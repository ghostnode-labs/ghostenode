<?php
declare(strict_types=1);
/**
 * 6.6 „Mein Bereich“: Einstellungen und Daten je GhostNode-Konto.
 *   • Darstellung: Thema, Schrift, Schriftgröße, Startseite (wirkt auf allen Seiten über das gemeinsame Menü)
 *   • Benachrichtigungs-Center: neue Versionen, kommende Termine, Antworten in der Community
 *   • Webhooks: eigene Adressen bekommen neue Versionen und Termine (nur https, keine internen Adressen)
 *   • Lizenzen: die NXK1-Schlüssel zum eigenen VRChat-Konto (nur lesen)
 *   • Öffentliches Profil (freiwillig) unter u.php?n=<name>
 *   • Datenschutz: alle eigenen Daten als JSON, Konto löschen
 */
require_once APP_ROOT . '/inc/core/common.php';
require_once APP_ROOT . '/inc/core/accounts.php';

const ME_THEMES = [
    'cyber'  => ['🌃 Cyber (Standard)', []],
    'neon'   => ['💜 Neon', ['--accent-cyan' => '#ff4fd8', '--accent-gold' => '#9b8cff', '--border-color' => 'rgba(255,79,216,.35)', '--accent-glow' => 'rgba(255,79,216,.3)']],
    'ocean'  => ['🌊 Ozean', ['--accent-cyan' => '#3ba7ff', '--accent-gold' => '#5ef2d6', '--border-color' => 'rgba(59,167,255,.35)', '--bg-color' => '#030a14', '--accent-glow' => 'rgba(59,167,255,.3)']],
    'forest' => ['🌲 Wald', ['--accent-cyan' => '#4ade80', '--accent-gold' => '#d9c26a', '--border-color' => 'rgba(74,222,128,.32)', '--bg-color' => '#04100a', '--accent-glow' => 'rgba(74,222,128,.3)']],
    'sunset' => ['🌅 Sonnenuntergang', ['--accent-cyan' => '#ff8a4c', '--accent-gold' => '#ff4f7b', '--border-color' => 'rgba(255,138,76,.35)', '--bg-color' => '#12060a', '--accent-glow' => 'rgba(255,138,76,.3)']],
    'amoled' => ['⚫ AMOLED', ['--bg-color' => '#000000', '--panel-bg' => 'rgba(0,0,0,.92)', '--panel-hover' => 'rgba(16,16,16,.95)']],
];
const ME_FONTS = [
    ''          => 'Standard',
    'system'    => 'System (Segoe UI / Roboto)',
    'rounded'   => 'Rund (Nunito / Varela)',
    'serif'     => 'Serif (Georgia)',
    'mono'      => 'Monospace',
    'readable'  => 'Gut lesbar (Verdana / Atkinson)',
];
const ME_FONT_CSS = [
    'system' => '"Segoe UI", Roboto, -apple-system, sans-serif', 'rounded' => '"Nunito", "Varela Round", "Segoe UI", sans-serif',
    'serif' => 'Georgia, "Times New Roman", serif', 'mono' => '"Cascadia Mono", Consolas, "DejaVu Sans Mono", monospace', 'readable' => '"Atkinson Hyperlegible", Verdana, Tahoma, sans-serif',
];
const ME_STARTS = ['' => 'Wie bisher', 'dashboard.php' => '🧭 Dashboard', 'nexus.php' => '🥽 VRC Nexus', 'me.php' => '👤 Mein Bereich', 'changelog.php' => '📜 Changelog', 'market.php' => '🧩 Marktplatz', 'calendar.php' => '📅 Kalender'];
const ME_HOOK_EVENTS = ['release' => '📦 Neue VRC-Nexus-Version', 'event' => '📅 Termine (Start in der nächsten Stunde)'];

function me_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (setting('me_schema', '0') === '1') return;
    site_db()->exec("CREATE TABLE IF NOT EXISTS user_prefs (user_id INTEGER PRIMARY KEY, data TEXT NOT NULL DEFAULT '{}', slug TEXT NOT NULL DEFAULT '', public INTEGER NOT NULL DEFAULT 0,
        notif_seen INTEGER NOT NULL DEFAULT 0, updated INTEGER NOT NULL DEFAULT 0)");
    set_setting('me_schema', '1');
}
function me_get(int $uid): array {
    me_schema();
    $st = site_db()->prepare('SELECT * FROM user_prefs WHERE user_id = ?');
    $st->execute([$uid]);
    $r = $st->fetch() ?: ['user_id' => $uid, 'data' => '{}', 'slug' => '', 'public' => 0, 'notif_seen' => 0];
    $r['d'] = json_decode((string)$r['data'], true) ?: [];
    return $r;
}
function me_put(int $uid, array $d, ?string $slug = null, ?int $public = null, ?int $seen = null): void {
    $cur = me_get($uid);
    $slug = $slug ?? (string)$cur['slug']; $public = $public ?? (int)$cur['public']; $seen = $seen ?? (int)$cur['notif_seen'];
    $db = site_db();
    $db->prepare('DELETE FROM user_prefs WHERE user_id = ?')->execute([$uid]);
    $db->prepare('INSERT INTO user_prefs (user_id, data, slug, public, notif_seen, updated) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$uid, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $slug, $public, $seen, time()]);
}

// ---------- Darstellung ----------
function me_look_save(int $uid, array $in): array {
    $p = me_get($uid); $d = $p['d'];
    $d['theme'] = isset(ME_THEMES[(string)($in['theme'] ?? '')]) ? (string)$in['theme'] : 'cyber';
    $d['font'] = isset(ME_FONTS[(string)($in['font'] ?? '')]) ? (string)$in['font'] : '';
    $d['size'] = max(85, min(130, (int)($in['size'] ?? 100)));
    $d['start'] = isset(ME_STARTS[(string)($in['start'] ?? '')]) ? (string)$in['start'] : '';
    me_put($uid, $d);
    return $d;
}
/** <style> für das gemeinsame Menü (alle Seiten) – leer, wenn nichts eingestellt ist */
function me_style(int $uid): string {
    $d = me_get($uid)['d'];
    $vars = ME_THEMES[$d['theme'] ?? 'cyber'][1] ?? [];
    $css = '';
    if ($vars) { $css .= ':root{'; foreach ($vars as $k => $v) $css .= $k . ':' . $v . ';'; $css .= '}'; }
    if (!empty($d['font']) && isset(ME_FONT_CSS[$d['font']])) $css .= 'body,input,select,textarea,button{font-family:' . ME_FONT_CSS[$d['font']] . ' !important}';
    if (!empty($d['size']) && (int)$d['size'] !== 100) $css .= 'html{font-size:' . (int)$d['size'] . '%}';
    return $css === '' ? '' : '<style id="me-look">' . $css . '</style>';
}
/** Ziel nach der Anmeldung: eigene Startseite (nur wenn kein bestimmtes Ziel verlangt war), sonst wie bisher */
function me_after_login(string $next): string {
    $u = current_user();
    $def = $next === 'admin.php' && !can('admin.access') ? 'dashboard.php' : $next;
    if (!$u || !in_array($next, ['admin.php', '', 'dashboard.php'], true)) return $def;
    try { $s = me_start((int)$u['id']); } catch (Throwable $e) { $s = ''; }
    return $s !== '' ? $s : $def;
}
/** öffentliche Adresse der Webseite (im Aufgabenplaner gibt es keinen Host) */
function me_site(): string { $s = setting('site_url', ''); if (preg_match('#^https://[\w.\-]+(/[\w./\-]*)?$#', $s)) return rtrim($s, '/'); return PHP_SAPI === 'cli' ? 'https://ghostnode-labs.dev' : acc_base_url(); }
/** Startseite nach der Anmeldung (leer = wie bisher) */
function me_start(int $uid): string { $s = (string)(me_get($uid)['d']['start'] ?? ''); return isset(ME_STARTS[$s]) ? $s : ''; }

// ---------- Öffentliches Profil ----------
function me_profile_save(int $uid, array $in): array {
    $p = me_get($uid); $d = $p['d'];
    $slug = strtolower(trim((string)($in['slug'] ?? '')));
    $public = !empty($in['public']) ? 1 : 0;
    if ($public) {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{2,23}$/', $slug)) throw new RuntimeException('Adresse: 3–24 Zeichen, nur a–z, 0–9, - und _.');
        if (in_array($slug, ['admin', 'root', 'ghostnode', 'api', 'nexus', 'support', 'mod', 'moderator', 'owner'], true)) throw new RuntimeException('Diese Adresse ist reserviert.');
        $st = site_db()->prepare('SELECT user_id FROM user_prefs WHERE slug = ? AND user_id <> ?');
        $st->execute([$slug, $uid]);
        if ($st->fetch()) throw new RuntimeException('Diese Adresse ist schon vergeben.');
    }
    $d['bio'] = mb_substr(trim(str_replace("\r", '', (string)($in['bio'] ?? ''))), 0, 600);
    $links = [];
    foreach (preg_split('/\s+/', (string)($in['links'] ?? '')) as $l) if (preg_match('#^https://[^\s<>"]{4,200}$#', $l) && count($links) < 4) $links[] = $l;
    $d['links'] = $links;
    $d['show_role'] = !empty($in['show_role']);
    $d['federate'] = $public && !empty($in['federate']);   // 8.1: auch auf Partner-Servern zeigen (Föderation)
    me_put($uid, $d, $public ? $slug : ($slug !== '' && preg_match('/^[a-z0-9][a-z0-9_-]{2,23}$/', $slug) ? $slug : ''), $public);
    return me_get($uid);
}
function me_public(string $slug): ?array {
    me_schema();
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{2,23}$/', $slug)) return null;
    $st = site_db()->prepare('SELECT p.*, u.display_name, u.username, u.created_at, r.name AS role_name, r.color AS role_color FROM user_prefs p JOIN users u ON u.id = p.user_id JOIN roles r ON r.id = u.role_id WHERE p.slug = ? AND p.public = 1 AND u.active = 1');
    $st->execute([$slug]);
    $r = $st->fetch();
    if (!$r) return null;
    $d = json_decode((string)$r['data'], true) ?: [];
    return ['name' => (string)($r['display_name'] ?: $r['username']), 'since' => (string)$r['created_at'], 'bio' => (string)($d['bio'] ?? ''), 'links' => (array)($d['links'] ?? []),
        'role' => !empty($d['show_role']) ? (string)$r['role_name'] : '', 'role_color' => (string)$r['role_color']];
}

// ---------- Benachrichtigungs-Center ----------
function me_notes(array $u): array {
    $out = []; $p = me_get((int)$u['id']); $seen = (int)$p['notif_seen'];
    try {
        require_once APP_ROOT . '/inc/modules/changelog.php';
        foreach (array_slice(cl_fetch(), 0, 6) as $r) if (!$r['pre']) $out[] = ['t' => strtotime($r['date']) ?: 0, 'icon' => '📦', 'title' => 'VRC Nexus ' . cl_ver($r['v']) . ' ist erschienen', 'href' => 'changelog.php#v' . $r['v']];
    } catch (Throwable $e) { /* GitHub nicht erreichbar */ }
    try {   // 9.0: auch D2 Nexus und der Discord-Bot
        require_once APP_ROOT . '/inc/modules/releases.php';
        foreach (['d2', 'bot'] as $pid) foreach (array_slice(array_values(array_filter(rel_fetch($pid), fn($r) => !$r['pre'])), 0, 3) as $r)
            $out[] = ['t' => strtotime($r['date']) ?: 0, 'icon' => REL_PROJECTS[$pid][0], 'title' => REL_PROJECTS[$pid][1] . ' v' . $r['v'] . ' ist erschienen', 'href' => $pid === 'd2' ? 'downloads.php#more' : REL_PROJECTS[$pid][4]];
    } catch (Throwable $e) { /* GitHub nicht erreichbar */ }
    try {   // 9.0: Störungen der GhostNode-Dienste (letzte 7 Tage)
        require_once APP_ROOT . '/inc/modules/status.php';
        foreach (array_slice(st_summary()['incidents'], 0, 5) as $inc) {
            if ((int)$inc['start'] < time() - 7 * 86400) continue;
            $out[] = ['t' => (int)($inc['end'] ?: $inc['start']), 'icon' => $inc['end'] ? '✅' : '⚠️', 'title' => $inc['name'] . ($inc['end'] ? ' läuft wieder' : ' ist gestört'),
                      'sub' => $inc['end'] ? 'Störung von ' . date('d.m. H:i', (int)$inc['start']) . ' bis ' . date('H:i', (int)$inc['end']) : (string)$inc['msg'], 'href' => 'status.php'];
        }
    } catch (Throwable $e) { /* Status optional */ }
    try {
        require_once APP_ROOT . '/inc/modules/calendar.php';
        $now = new DateTimeImmutable('now');
        foreach (array_slice(cal_items($now, $now->modify('+7 days'), false, ['events']), 0, 8) as $it) {
            $ts = strtotime((string)($it['start'] ?? $it['start_at'] ?? '')) ?: 0;
            if ($ts && $ts >= time() - 3600) $out[] = ['t' => $ts, 'icon' => '📅', 'title' => (string)($it['title'] ?? 'Termin'), 'sub' => date('d.m. H:i', $ts), 'href' => 'calendar.php', 'future' => true];
        }
    } catch (Throwable $e) { /* Kalender nicht verfügbar */ }
    try {
        require_once APP_ROOT . '/inc/modules/nexusweb.php';
        $va = function_exists('vrc_account') ? vrc_account((int)$u['id']) : null;
        if ($va && nx_account(strtolower((string)($va['vrc_id'] ?? '')))) {   // nur mit bestehendem Nexus-Konto (hier nichts anlegen)
            $who = nxweb_who((int)$u['id']);
            $in = nc_inbox($who, false);
            foreach (array_slice((array)($in['items'] ?? []), 0, 10) as $x) $out[] = ['t' => (int)($x['created'] ?? 0), 'icon' => '💬', 'title' => (string)($x['name'] ?? 'Jemand') . ' hat geantwortet', 'sub' => mb_substr((string)($x['text'] ?? ''), 0, 90), 'href' => 'nexus.php#community'];
        }
    } catch (Throwable $e) { /* ohne VRChat-Verbindung keine Community */ }
    foreach ($out as &$o) $o['new'] = empty($o['future']) && $o['t'] > $seen;
    unset($o);
    usort($out, fn($a, $b) => $b['t'] <=> $a['t']);
    return $out;
}
function me_notes_seen(int $uid): void { me_put($uid, me_get($uid)['d'], null, null, time()); }

// ---------- Webhooks ----------
/** https-Adresse prüfen: keine internen/reservierten Ziele (auch nicht über DNS) → [url, host, ip] */
function me_hook_target(string $url): array {
    $url = trim($url);
    $p = parse_url($url);
    if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])) throw new RuntimeException('Nur https-Adressen (ohne Zugangsdaten).');
    if (strlen($url) > 400) throw new RuntimeException('Adresse zu lang.');
    $host = strtolower($p['host']);
    if (in_array($host, ['localhost', 'localhost.localdomain'], true) || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) throw new RuntimeException('Interne Adressen sind nicht erlaubt.');
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (array)@gethostbynamel($host);
    if (!$ips) throw new RuntimeException('Adresse nicht auflösbar.');
    foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new RuntimeException('Interne Adressen sind nicht erlaubt.');
    return [$url, $host, $ips[0], (int)($p['port'] ?? 443)];
}
function me_hooks_save(int $uid, array $in): array {
    $p = me_get($uid); $d = $p['d']; $list = (array)($d['hooks'] ?? []);
    if (($in['do'] ?? '') === 'add') {
        if (count($list) >= 5) throw new RuntimeException('Höchstens 5 Webhooks.');
        [$url] = me_hook_target((string)($in['url'] ?? ''));
        $ev = array_values(array_intersect(array_keys(ME_HOOK_EVENTS), (array)($in['events'] ?? [])));
        if (!$ev) throw new RuntimeException('Mindestens ein Ereignis wählen.');
        $list[] = ['id' => bin2hex(random_bytes(4)), 'url' => $url, 'events' => $ev, 'fails' => 0, 'last' => 0, 'secret' => 'whsec_' . bin2hex(random_bytes(24))];   // 8.1: Signatur-Schlüssel je Webhook
    } elseif (($in['do'] ?? '') === 'secret') {   // 8.1: Signatur-Schlüssel (neu) erzeugen
        foreach ($list as &$h) if (($h['id'] ?? '') === (string)($in['id'] ?? '')) $h['secret'] = 'whsec_' . bin2hex(random_bytes(24));
        unset($h);
    } elseif (($in['do'] ?? '') === 'del') {
        $list = array_values(array_filter($list, fn($h) => ($h['id'] ?? '') !== (string)($in['id'] ?? '')));
    }
    $d['hooks'] = $list; me_put($uid, $d);
    return $list;
}
/** eine Nachricht senden (Discord-kompatibel: „content“) – fest auf die geprüfte IP, keine Weiterleitungen */
/**
 * 8.1: Webhook-Signatur – Kopfzeilen „X-GhostNode-Timestamp“ (Unix-Sekunden) und
 * „X-GhostNode-Signature: sha256=<HMAC-SHA256(secret, timestamp + "." + body)>“. Empfänger prüfen: gleiche Rechnung, Zeit ±5 min.
 */
function me_hook_sign(string $secret, string $body, int $ts): array {
    return ['X-GhostNode-Timestamp: ' . $ts, 'X-GhostNode-Signature: sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret)];
}
function me_hook_verify(string $secret, string $body, string $tsHeader, string $sigHeader, int $now = 0): bool {
    $now = $now ?: time(); $ts = (int)$tsHeader;
    if (abs($now - $ts) > 300 || strpos($sigHeader, 'sha256=') !== 0) return false;
    return hash_equals(hash_hmac('sha256', $ts . '.' . $body, $secret), substr($sigHeader, 7));
}
function me_hook_send(string $url, string $text, array $extra = [], string $secret = ''): bool {
    try { [$u, $host, $ip, $port] = me_hook_target($url); } catch (Throwable $e) { return false; }
    $body = json_encode(['content' => mb_substr($text, 0, 1900), 'username' => 'GhostNode'] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $hdr = ['Content-Type: application/json', 'User-Agent: GhostNode-Webhooks'];
    if ($secret !== '') $hdr = array_merge($hdr, me_hook_sign($secret, $body, time()));
    $ch = curl_init($u);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ip], CURLOPT_HTTPHEADER => $hdr, CURLOPT_POSTFIELDS => $body]);
    curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code >= 200 && $code < 300;
}
/** Aufgabe „webhooks“: neue Version / bald beginnende Termine an alle abonnierten Adressen */
function me_hooks_run(): string {
    me_schema();
    $msgs = ['release' => [], 'event' => []];
    try {
        require_once APP_ROOT . '/inc/modules/changelog.php';
        $rel = array_values(array_filter(cl_fetch(), fn($r) => !$r['pre']));
        $last = setting('me_hook_release', '');
        if ($rel && $last === '') set_setting('me_hook_release', $rel[0]['v']);   // erster Lauf: nichts Altes schicken
        elseif ($rel && version_compare($rel[0]['v'], $last, '>')) { $msgs['release'][] = '📦 **VRC Nexus ' . cl_ver($rel[0]['v']) . '** ist erschienen: ' . me_site() . '/changelog.php'; set_setting('me_hook_release', $rel[0]['v']); }
    } catch (Throwable $e) { /* egal */ }
    try {
        require_once APP_ROOT . '/inc/modules/calendar.php';
        $sent = json_decode(setting('me_hook_events', '[]'), true) ?: [];
        $now = new DateTimeImmutable('now');
        foreach (cal_items($now, $now->modify('+1 day'), false, ['events']) as $it) {
            $ts = strtotime((string)($it['start'] ?? $it['start_at'] ?? '')) ?: 0; $key = md5(($it['title'] ?? '') . '|' . $ts);
            if ($ts > time() && $ts - time() <= 3600 && !in_array($key, $sent, true)) { $msgs['event'][] = '📅 **' . ($it['title'] ?? 'Termin') . '** beginnt um ' . date('H:i', $ts) . ' Uhr'; $sent[] = $key; }
        }
        set_setting('me_hook_events', json_encode(array_slice($sent, -200)));
    } catch (Throwable $e) { /* egal */ }
    if (!$msgs['release'] && !$msgs['event']) return 'nichts Neues';
    $n = 0;
    foreach (site_db()->query('SELECT user_id, data FROM user_prefs')->fetchAll() as $r) {
        $d = json_decode((string)$r['data'], true) ?: []; $ch = false;
        foreach ((array)($d['hooks'] ?? []) as $i => $h) {
            if ((int)($h['fails'] ?? 0) >= 10) continue;   // nach 10 Fehlern pausiert
            foreach ($h['events'] as $ev) foreach ($msgs[$ev] ?? [] as $m) { $ok = me_hook_send($h['url'], $m, [], (string)($h['secret'] ?? '')); $d['hooks'][$i]['fails'] = $ok ? 0 : (int)($h['fails'] ?? 0) + 1; $d['hooks'][$i]['last'] = time(); $ch = true; if ($ok) $n++; }
        }
        if ($ch) site_db()->prepare('UPDATE user_prefs SET data = ? WHERE user_id = ?')->execute([json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $r['user_id']]);
    }
    return $n . ' Webhook-Nachrichten gesendet';
}

// ---------- Lizenzen ----------
function me_licences(int $uid): array {
    try {
        require_once APP_ROOT . '/inc/modules/vrchat.php';
        $acc = vrc_account($uid);
        $vid = strtolower((string)($acc['vrc_id'] ?? ''));
        if ($vid === '') return ['linked' => false, 'keys' => []];
        require_once APP_ROOT . '/inc/modules/nexuslic.php';
        nl_schema();
        $st = nl_db()->prepare('SELECT id, name, roles, iat, exp, revoked FROM lic_keys WHERE lower(uid) = ? ORDER BY iat DESC');
        $st->execute([$vid]);
        return ['linked' => true, 'vrc_id' => $vid, 'keys' => $st->fetchAll()];
    } catch (Throwable $e) { return ['linked' => false, 'keys' => [], 'error' => $e->getMessage()]; }
}

// ---------- Datenschutz ----------
function me_export(array $u): array {
    $uid = (int)$u['id'];
    $db = site_db();
    $user = $db->prepare('SELECT * FROM users WHERE id = ?'); $user->execute([$uid]); $row = $user->fetch() ?: [];
    foreach (['password_hash', 'totp_secret', 'totp_recovery', 'session_version'] as $k) unset($row[$k]);   // Geheimnisse nie ausgeben
    $sess = $db->prepare('SELECT ip, agent, created, last_seen FROM user_sessions WHERE user_id = ?'); $sess->execute([$uid]);
    $log = $db->prepare('SELECT ts, action, detail, ip FROM audit_log WHERE user_id = ? ORDER BY id DESC LIMIT 500'); $log->execute([$uid]);
    $p = me_get($uid);
    $out = ['exported' => date('c'), 'site' => acc_base_url(), 'account' => $row, 'preferences' => $p['d'], 'public_profile' => ['address' => $p['slug'], 'public' => (bool)$p['public']],
        'sessions' => $sess->fetchAll(), 'activity' => $log->fetchAll()];
    try { $out['dashboard'] = user_kv_all($uid); } catch (Throwable $e) { /* 9.0: Dashboard-Anordnung, Notizbuch, Lesezeichen … */ }
    try {
        require_once APP_ROOT . '/inc/modules/vrchat.php';
        $acc = vrc_account($uid);
        if ($acc) $out['vrchat'] = ['vrc_id' => $acc['vrc_id'] ?? '', 'display_name' => $acc['display_name'] ?? '', 'note' => 'Sitzungs-Cookies werden verschlüsselt gespeichert und nicht exportiert.'];
    } catch (Throwable $e) { /* ohne VRChat */ }
    return $out;
}
/** Konto löschen – nur mit Bestätigung; Besitzer-/Systemkonten nicht */
function me_delete(array $u, string $confirm, string $password): void {
    if (!empty($u['role_is_system'])) throw new RuntimeException('Das Besitzer-Konto kann nicht gelöscht werden.');
    if (mb_strtolower(trim($confirm)) !== mb_strtolower((string)$u['username'])) throw new RuntimeException('Zur Bestätigung deinen Benutzernamen eintippen.');
    if ((string)($u['password_hash'] ?? '') !== '' && !password_verify($password, (string)$u['password_hash'])) throw new RuntimeException('Passwort falsch.');
    $uid = (int)$u['id'];
    audit('Konto gelöscht', 'auf eigenen Wunsch', 'warn', $u);
    try { require_once APP_ROOT . '/inc/modules/vrchat.php'; vrc_db()->prepare('DELETE FROM accounts WHERE user_id = ?')->execute([$uid]); } catch (Throwable $e) { /* ohne VRChat */ }
    $db = site_db();
    foreach (['DELETE FROM user_sessions WHERE user_id = ?', 'DELETE FROM user_prefs WHERE user_id = ?', 'UPDATE audit_log SET ip = \'\' WHERE user_id = ?', 'DELETE FROM users WHERE id = ?'] as $q)
        try { $db->prepare($q)->execute([$uid]); } catch (Throwable $e) { /* Tabelle fehlt */ }
}

// ---------- 8.1: Datenschutz-Center – alles auf einen Blick, mit Sofort-Knöpfen ----------
function me_privacy(int $uid): array {
    $p = me_get($uid); $d = $p['d'];
    $out = ['public' => (int)$p['public'] === 1, 'slug' => (string)$p['slug'], 'hooks' => count((array)($d['hooks'] ?? [])), 'devices' => [], 'sessions' => 0, 'vrchat' => false, 'current' => (string)($_SESSION['auth']['sid'] ?? '')];
    try { require_once APP_ROOT . '/inc/modules/nexus_client.php'; $out['devices'] = nc_tokens($uid); } catch (Throwable $e) { $out['devices'] = []; }
    try { $out['sessions'] = count(sessions_list($uid)); } catch (Throwable $e) { $out['sessions'] = 0; }
    try { require_once APP_ROOT . '/inc/modules/oauth.php'; $out['apps'] = oa_grants($uid); } catch (Throwable $e) { $out['apps'] = []; }   // 8.1 „Mit GhostNode anmelden“
    try { require_once APP_ROOT . '/inc/modules/vrchat.php'; $acc = vrc_account($uid); $out['vrchat'] = !empty($acc['vrc_id']); } catch (Throwable $e) { /* egal */ }
    return $out;
}
function me_privacy_act(int $uid, string $act, array $in): string {
    switch ($act) {
        case 'public_off':
            me_schema();
            site_db()->prepare('UPDATE user_prefs SET public = 0, updated = ? WHERE user_id = ?')->execute([time(), $uid]);
            audit('Datenschutz', 'öffentliches Profil ausgeschaltet', 'info');
            return 'Öffentliches Profil ist aus.';
        case 'device_revoke':
            require_once APP_ROOT . '/inc/modules/nexus_client.php';
            nc_token_revoke($uid, (int)($in['id'] ?? 0));
            audit('Datenschutz', 'Gerät entfernt', 'info');
            return 'Gerät entfernt – es kommt ohne neues Token nicht mehr rein.';
        case 'devices_all':
            require_once APP_ROOT . '/inc/modules/nexus_client.php';
            foreach (nc_tokens($uid) as $t) nc_token_revoke($uid, (int)$t['id']);
            audit('Datenschutz', 'alle Geräte entfernt', 'info');
            return 'Alle Geräte entfernt.';
        case 'sessions_others':
            $sid = (string)($_SESSION['auth']['sid'] ?? '');
            site_db()->prepare('DELETE FROM user_sessions WHERE user_id = ? AND id <> ?')->execute([$uid, $sid]);
            audit('Datenschutz', 'andere Sitzungen abgemeldet', 'info');
            return 'Alle anderen Sitzungen sind abgemeldet.';
        case 'apps_all':
        case 'app_revoke':
            require_once APP_ROOT . '/inc/modules/oauth.php';
            $n = oa_revoke_grants($uid, $act === 'apps_all' ? 0 : max(1, (int)($in['app'] ?? 0)));
            audit('Datenschutz', 'App-Zugriff widerrufen (' . $n . ')', 'info');
            return $n ? 'Zugriff widerrufen.' : 'Nichts zu widerrufen.';
        case 'hooks_off':
            $p = me_get($uid); $d = $p['d']; $d['hooks'] = []; me_put($uid, $d);
            return 'Alle Webhooks entfernt.';
    }
    throw new RuntimeException('Unbekannte Aktion.');
}
