<?php
/**
 * Lizenzen 2.0 (VRC Nexus 5.6) – Register der Freischalt-Schlüssel (NXK1) und gemeinsame Sperrliste.
 *  - Die Besitzerin meldet aus der App, welche Schlüssel sie ausgestellt hat (nur Metadaten, nie private Schlüssel).
 *  - Sperren (aus App ODER Admin-Bereich der Webseite) landen in einer Liste, die jede App alle 6 h holt.
 *  - Apps können ihren Schlüssel online prüfen lassen (Unterschrift, Ablauf, Sperre).
 *  - 8.4 Tags: die Besitzer-App schickt beim Abgleich die signierten Schlüssel mit (Spalte token, nie Moderator-Schlüssel);
 *    a=mine gibt sie NUR an das Nexus-Konto mit derselben VRChat-ID heraus. Die Signatur prüft die App wie beim Eintippen.
 *    „owner“ ist kein vergebbares Tag – das Besitzer-Abzeichen kommt allein vom Owner-Lock (NW_OWNER_HASH).
 */
require_once __DIR__ . '/nexuswarn.php';
require_once __DIR__ . '/nexusacct.php';

/** 8.4: bekannte Tags (Rollen + rein optische) – Schlüssel => [Icon, Name, Farbe]; nur Anzeige, keine Rechte */
const NL_TAGS = ['supporter' => ['💖', 'Supporter', '#ff6b9d'], 'mod' => ['🛡', 'Moderator', '#38d5e6'], 'creator' => ['🎨', 'Creator', '#a78bfa'], 'beta' => ['🧪', 'Beta-Tester', '#4ade80'],
    'vip' => ['⭐', 'VIP', '#f5c242'], 'partner' => ['🤝', 'Partner', '#60a5fa'], 'event' => ['🎉', 'Event-Team', '#fb923c'], 'artist' => ['🖌', 'Künstler:in', '#f472b6']];
const NL_OWNER_TAG = ['👑', 'Besitzerin', '#f3c262'];
const NL_SCHEMA = '2';

function nl_db(): PDO { return nw_db(); }
function nl_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (setting('nexuslic_schema', '0') === NL_SCHEMA && in_array('lic_keys', db_tables(nl_db()), true)) return;
    $db = nl_db();
    $db->exec("CREATE TABLE IF NOT EXISTS lic_keys (id TEXT PRIMARY KEY, uid TEXT NOT NULL, name TEXT NOT NULL DEFAULT '', roles TEXT NOT NULL DEFAULT '', iat INTEGER NOT NULL DEFAULT 0,
        exp INTEGER NOT NULL DEFAULT 0, note TEXT NOT NULL DEFAULT '', revoked INTEGER NOT NULL DEFAULT 0, revoked_src TEXT NOT NULL DEFAULT '', updated INTEGER NOT NULL DEFAULT 0,
        last_check INTEGER NOT NULL DEFAULT 0, checks INTEGER NOT NULL DEFAULT 0, last_version TEXT NOT NULL DEFAULT '')");
    // Migration 2 (8.4): signierter Schlüssel zur automatischen Zustellung (SQLite + MySQL über die Db-Übersetzung)
    if (!in_array('token', db_columns($db, 'lic_keys'), true)) $db->exec("ALTER TABLE lic_keys ADD COLUMN token TEXT NOT NULL DEFAULT ''");
    set_setting('nexuslic_schema', NL_SCHEMA);
}
/** Inhalt eines NXK1-Schlüssels (ohne Prüfung der Unterschrift – die macht nw_auth) */
function nl_payload(string $token): ?array {
    if (strlen($token) > 4000 || !preg_match('/^NXK1\.([\w-]+)\.([\w-]+)$/', trim($token), $m)) return null;
    $p = json_decode(nw_b64u($m[1]), true);
    return is_array($p) ? $p : null;
}
/** zugestellt wird nur ein Schlüssel, der echt unterschrieben ist, zu genau diesem Eintrag (ID + VRChat-ID) passt und KEINE Moderator-Rolle trägt */
function nl_token_ok(string $token, string $id, string $uid): bool {
    $p = nl_payload($token);
    if (!$p || (string)($p['id'] ?? '') !== $id || strtolower((string)($p['uid'] ?? '')) !== strtolower($uid)) return false;
    if (in_array('mod', (array)($p['f'] ?? []), true)) return false;
    try { $a = nw_auth($token); } catch (Throwable $e) { return false; }
    return $a['kid'] === $id && $a['uid'] === strtolower($uid) && empty($a['owner']);
}
/** Schlüssel zu einem Eintrag merken (leer = nichts zustellen) */
function nl_store_token(string $id, string $uid, string $token): bool {
    nl_schema();
    $ok = $token !== '' && nl_token_ok($token, $id, $uid);
    nl_db()->prepare('UPDATE lic_keys SET token = ? WHERE id = ?')->execute([$ok ? trim($token) : '', $id]);
    return $ok;
}
/** 8.4: eigene Schlüssel für das Nexus-Konto zum Token – nur die eigene VRChat-ID, nie gesperrt/abgelaufen/Moderator */
function nl_mine(string $acct_token): array {
    nl_schema();
    $acc = nx_auth($acct_token);   // NxError → 401
    $vid = strtolower((string)$acc['vrc_id']);
    if (!nx_uid_ok($vid)) return ['keys' => [], 'at' => time()];
    $st = nl_db()->prepare("SELECT id, uid, roles, exp, token FROM lic_keys WHERE lower(uid) = ? AND revoked = 0 AND token <> '' AND (exp = 0 OR exp > ?) ORDER BY iat DESC LIMIT 20");
    $st->execute([$vid, time()]);
    $rev = nl_revoked();
    $keys = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (in_array((string)$r['id'], $rev, true) || strtolower((string)$r['uid']) !== $vid) continue;
        if (!nl_token_ok((string)$r['token'], (string)$r['id'], $vid)) continue;   // nochmals: Unterschrift, gleiche ID/VRChat-ID, kein „mod“
        $keys[] = ['id' => (string)$r['id'], 'token' => (string)$r['token'], 'tags' => nl_tag_list((string)$r['roles']), 'exp' => (int)$r['exp']];
    }
    return ['keys' => $keys, 'at' => time()];
}
function nl_tag_list(string $csv): array { return array_values(array_filter(array_unique(explode(',', $csv)), fn($t) => isset(NL_TAGS[$t]))); }
/** 8.4: aktive Tags einer VRChat-ID (für Profil-Abzeichen) – Besitzer-Abzeichen nur über den Owner-Lock */
function nl_tags_for(string $uid): array {
    $uid = strtolower($uid);
    if (!nx_uid_ok($uid)) return [];
    $tags = nw_is_owner($uid) ? ['owner'] : [];
    try {
        nl_schema();
        $st = nl_db()->prepare('SELECT id, roles FROM lic_keys WHERE lower(uid) = ? AND revoked = 0 AND (exp = 0 OR exp > ?)');
        $st->execute([$uid, time()]);
        $rev = nl_revoked();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (!in_array((string)$r['id'], $rev, true)) foreach (nl_tag_list((string)$r['roles']) as $t) $tags[] = $t;
    } catch (Throwable $e) { /* ohne Register */ }
    $tags = array_values(array_unique($tags));
    $order = array_merge(['owner'], array_keys(NL_TAGS));
    usort($tags, fn($a, $b) => array_search($a, $order, true) <=> array_search($b, $order, true));
    return $tags;
}
/** Tags als Abzeichen (Icon, Name, Farbe) */
function nl_tag_badges(string $uid): array {
    return array_map(function ($t) { $m = $t === 'owner' ? NL_OWNER_TAG : NL_TAGS[$t]; return ['tag' => $t, 'icon' => $m[0], 'title' => $m[1], 'color' => $m[2], 'desc' => $t === 'owner' ? 'Besitzerin von VRC Nexus' : 'Von der Besitzerin vergebenes Tag']; }, nl_tags_for($uid));
}
function nl_id(string $s): string { return preg_match('/^[0-9a-z]{4,40}$/', $s) ? $s : ''; }
/** alle gesperrten IDs (Register + Liste der Warnliste) */
function nl_revoked(): array {
    nl_schema();
    $ids = nw_revoked();
    foreach (nl_db()->query('SELECT id FROM lic_keys WHERE revoked = 1')->fetchAll(PDO::FETCH_COLUMN) as $id) $ids[] = (string)$id;
    return array_values(array_unique($ids));
}
/** Sperre setzen/aufheben – hält die Liste für nw_auth (Warnliste, Community-Moderation) gleich */
function nl_set_revoked(string $id, bool $on, string $src): void {
    nl_schema();
    $id = nl_id($id);
    if ($id === '') throw new NwError('Ungültige Schlüssel-ID.');
    nl_db()->prepare('UPDATE lic_keys SET revoked = ?, revoked_src = ?, updated = ? WHERE id = ?')->execute([$on ? 1 : 0, $on ? $src : '', time(), $id]);
    $list = array_values(array_filter(nw_revoked(), fn($x) => $x !== $id));
    if ($on) $list[] = $id;
    set_setting('nexuswarn_revoked', implode(',', array_slice(array_values(array_unique($list)), 0, 2000)));
}
/** Schlüssel online prüfen */
function nl_check(string $token, string $version): array {
    nl_schema();
    try { $a = nw_auth($token); }
    catch (NwError $e) { return ['valid' => false, 'reason' => $e->getMessage()]; }
    if ($a['kid'] !== '') nl_db()->prepare('UPDATE lic_keys SET last_check = ?, checks = checks + 1, last_version = ? WHERE id = ?')->execute([time(), mb_substr(preg_replace('/[^0-9.]/', '', $version), 0, 20), $a['kid']]);
    return ['valid' => true, 'reason' => '', 'uid' => $a['uid'], 'id' => $a['kid']];
}
/** Besitzerin meldet ihre ausgestellten Schlüssel; Antwort: alle gesperrten IDs (auch die, die auf der Webseite gesperrt wurden) */
function nl_sync(string $key, array $entries): array {
    nl_schema();
    $a = nw_auth($key);
    if (empty($a['owner'])) throw new NwError('Nur für die Besitzerin.');
    $db = nl_db();
    $up = $db->prepare('INSERT INTO lic_keys (id, uid, name, roles, iat, exp, note, revoked, revoked_src, updated) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(id) DO UPDATE SET uid = excluded.uid, name = excluded.name, roles = excluded.roles, iat = excluded.iat, exp = excluded.exp, note = excluded.note, updated = excluded.updated');
    $n = 0;
    foreach (array_slice($entries, 0, 2000) as $e) {
        if (!is_array($e)) continue;
        $id = nl_id((string)($e['id'] ?? ''));
        $uid = strtolower((string)($e['uid'] ?? ''));
        if ($id === '' || !preg_match(NW_UID, $uid)) continue;
        $roles = implode(',', array_values(array_filter(array_map('strval', (array)($e['f'] ?? [])), fn($r) => preg_match('/^[a-z]{2,16}$/', $r))));
        $up->execute([$id, $uid, nw_cut((string)($e['name'] ?? ''), 60), $roles, (int)($e['iat'] ?? 0), (int)($e['exp'] ?? 0), nw_cut((string)($e['note'] ?? ''), 80), 0, '', time()]);
        // Sperre aus der App übernehmen; Entsperren aus der App hebt nur App-Sperren auf (Web-Sperren bleiben, bis sie auf der Webseite aufgehoben werden)
        $cur = $db->prepare('SELECT revoked, revoked_src FROM lic_keys WHERE id = ?'); $cur->execute([$id]); $c = $cur->fetch(PDO::FETCH_ASSOC);
        if (!empty($e['revoked']) && !(int)$c['revoked']) nl_set_revoked($id, true, 'app');
        if (empty($e['revoked']) && (int)$c['revoked'] && $c['revoked_src'] === 'app') nl_set_revoked($id, false, 'app');
        // 8.4: Schlüssel zur Zustellung (ältere Apps schicken keinen → bleibt unverändert; leer → nichts mehr zustellen)
        if (array_key_exists('token', $e)) nl_store_token($id, $uid, !empty($e['revoked']) ? '' : (string)$e['token']);
        $n++;
    }
    nw_log('app:' . $a['uid'], 'Lizenzen abgeglichen', '', $n . ' Schlüssel');
    return ['ok' => true, 'count' => $n, 'revoked' => nl_revoked()];
}
function nl_list(): array {
    nl_schema();
    // 8.4: den Schlüssel selbst nie ausgeben – nur, ob er automatisch zugestellt wird
    return array_map(function ($r) { $r['delivered'] = ($r['token'] ?? '') !== ''; unset($r['token']); return $r; }, nl_db()->query('SELECT * FROM lic_keys ORDER BY iat DESC LIMIT 1000')->fetchAll(PDO::FETCH_ASSOC));
}
