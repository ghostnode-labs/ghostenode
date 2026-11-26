<?php
/**
 * Lizenzen 2.0 (VRC Nexus 5.6) – Register der Freischalt-Schlüssel (NXK1) und gemeinsame Sperrliste.
 *  - Die Besitzerin meldet aus der App, welche Schlüssel sie ausgestellt hat (nur Metadaten, nie private Schlüssel).
 *  - Sperren (aus App ODER Admin-Bereich der Webseite) landen in einer Liste, die jede App alle 6 h holt.
 *  - Apps können ihren Schlüssel online prüfen lassen (Unterschrift, Ablauf, Sperre).
 */
require_once __DIR__ . '/nexuswarn.php';

function nl_db(): PDO { return nw_db(); }
function nl_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (setting('nexuslic_schema', '0') === '1') return;
    nl_db()->exec("CREATE TABLE IF NOT EXISTS lic_keys (id TEXT PRIMARY KEY, uid TEXT NOT NULL, name TEXT NOT NULL DEFAULT '', roles TEXT NOT NULL DEFAULT '', iat INTEGER NOT NULL DEFAULT 0,
        exp INTEGER NOT NULL DEFAULT 0, note TEXT NOT NULL DEFAULT '', revoked INTEGER NOT NULL DEFAULT 0, revoked_src TEXT NOT NULL DEFAULT '', updated INTEGER NOT NULL DEFAULT 0,
        last_check INTEGER NOT NULL DEFAULT 0, checks INTEGER NOT NULL DEFAULT 0, last_version TEXT NOT NULL DEFAULT '')");
    set_setting('nexuslic_schema', '1');
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
        $n++;
    }
    nw_log('app:' . $a['uid'], 'Lizenzen abgeglichen', '', $n . ' Schlüssel');
    return ['ok' => true, 'count' => $n, 'revoked' => nl_revoked()];
}
function nl_list(): array {
    nl_schema();
    return nl_db()->query('SELECT * FROM lic_keys ORDER BY iat DESC LIMIT 1000')->fetchAll(PDO::FETCH_ASSOC);
}
