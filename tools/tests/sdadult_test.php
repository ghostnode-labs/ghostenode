<?php
/**
 * Tests für den Download des zweiten Stream-Deck-Plugins (nexusdeck.php, inc/modules/sdadult.php) – nur Testdaten, kein echtes VRChat-Konto.
 *   php tools/tests/sdadult_test.php                 (SQLite in einem Wegwerf-Ordner)
 *   php my.php tools/tests/sdadult_test.php          (gegen ein lokales Test-MySQL/MariaDB: $GLOBALS['__db_cfg'] vorher setzen)
 * Startet kurz einen eingebauten PHP-Server (127.0.0.1, freier Port). Die Anmeldung im Test läuft über einen Haken im
 * Test-Router (nur in der Wegwerf-Kopie, nie in der Webseite). NIE gegen die echte Datenbank laufen lassen.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gnsda-' . bin2hex(random_bytes(4));
mkdir($tmp . '/data', 0700, true);
define('DATA_DIR', $tmp . '/data');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$cfg = $GLOBALS['__db_cfg'] ?? null;
require __DIR__ . '/../../inc/core/pages.php';
require __DIR__ . '/../../inc/modules/sdadult.php';

$fails = 0; $n = 0;
function t(bool $ok, string $name): void { global $fails, $n; $n++; echo ($ok ? "ok   " : "FAIL ") . $name . "\n"; if (!$ok) $fails++; }
$engine = db_is_mysql() ? 'MySQL' : 'SQLite';

/** ZIP-Einträge lesen (eigener Leser – unabhängig von ZipArchive): Name → Inhalt */
function unzip(string $b): array {
    $e = strrpos($b, "PK\x05\x06"); if ($e === false) return [];
    $x = unpack('vdisk/vcd_disk/vn1/vn/Vsize/Voff', substr($b, $e + 4, 16)); $p = $x['off']; $out = [];
    for ($i = 0; $i < $x['n']; $i++) {
        $c = unpack('Vsig/vver/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/viattr/Veattr/Vlho', substr($b, $p, 46));
        if ($c['sig'] !== 0x02014b50) return [];
        $name = substr($b, $p + 46, $c['nlen']); $p += 46 + $c['nlen'] + $c['elen'] + $c['clen'];
        $l = unpack('Vsig/vver/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen', substr($b, $c['lho'], 30));
        $raw = substr($b, $c['lho'] + 30 + $l['nlen'] + $l['elen'], $c['csize']);
        $data = $c['method'] === 8 ? gzinflate($raw) : $raw;
        if ($data === false || crc32($data) !== $c['crc'] || strlen($data) !== $c['usize']) return [];
        if (substr($name, -1) !== '/') $out[$name] = $data;
    }
    return $out;
}
/** gültiges .streamDeckPlugin? (ein *.sdPlugin-Ordner, Manifest mit CodePath/PI/Aktionen, alle Bilder in beiden Größen) */
function valid_plugin(string $bin, string &$why = ''): bool {
    $z = unzip($bin);
    if (!$z) { $why = 'kein ZIP'; return false; }
    $dirs = array_unique(array_map(fn($k) => explode('/', $k)[0], array_keys($z)));
    if (count($dirs) !== 1 || !str_ends_with($dirs[0], '.sdPlugin')) { $why = 'Ordner: ' . implode(',', $dirs); return false; }
    $d = $dirs[0] . '/';
    $m = json_decode($z[$d . 'manifest.json'] ?? '', true);
    if (!is_array($m) || ($m['UUID'] ?? '') . '.sdPlugin' !== $dirs[0]) { $why = 'Manifest/UUID'; return false; }
    foreach (['CodePath', 'PropertyInspectorPath'] as $k) if (!isset($z[$d . $m[$k]])) { $why = $k; return false; }
    $png = function (string $rel, int $w) use ($z, $d, &$why) { $b = $z[$d . $rel . '.png'] ?? ''; $ok = strlen($b) > 24 && unpack('N', substr($b, 16, 4))[1] === $w && unpack('N', substr($b, 20, 4))[1] === $w; if (!$ok) $why = 'Bild ' . $rel; return $ok; };
    if (!$png($m['Icon'], 256) || !$png($m['Icon'] . '@2x', 512) || !$png($m['CategoryIcon'], 28) || !$png($m['CategoryIcon'] . '@2x', 56)) return false;
    foreach ($m['Actions'] as $a) {
        if (strpos($a['UUID'], $m['UUID'] . '.') !== 0 || !$png($a['Icon'], 20) || !$png($a['Icon'] . '@2x', 40)) return false;
        foreach ($a['States'] as $s) if (!$png($s['Image'], 72) || !$png($s['Image'] . '@2x', 144)) return false;
        if (in_array('Neo', $a['Controllers'], true) && ($m['Software']['MinimumVersion'] ?? '') !== '7.6') { $why = 'Neo braucht 7.6'; return false; }
    }
    if (!isset($z[$d . 'layouts/infobar.json'])) { $why = 'Neo-Layout'; return false; }
    return true;
}

// ---------- Paket bauen: alle drei Wege ----------
t(sda_version() !== '' && count(sda_files()) > 20, 'Quelle liegt in clients/streamdeck-adult (Version ' . sda_version() . ', ' . count(sda_files()) . ' Dateien)');
foreach (['' => 'ziparchive', 'php' => 'php', 'prebuilt' => 'prebuilt'] as $force => $kind) {
    [$bin, $how] = sda_build($force);
    $why = '';
    t($how === (class_exists('ZipArchive') || $force !== '' ? $kind : 'php') && valid_plugin($bin, $why), 'Paket (' . $how . ') ist ein gültiges .streamDeckPlugin ' . $why);
}
$htc = (string)@file_get_contents(APP_ROOT . '/clients/streamdeck-adult/.htaccess');
t(str_contains($htc, 'Require all denied') && preg_match('#RedirectMatch 404 .*clients#', (string)file_get_contents(APP_ROOT . '/.htaccess')), 'Quelle gegen Direktzugriff gesperrt (.htaccess im Ordner + clients/ in der Haupt-.htaccess)');
t(!str_contains((string)file_get_contents(APP_ROOT . '/downloads.php'), 'nexusdeck') && !str_contains((string)file_get_contents(APP_ROOT . '/index.php'), 'nexusdeck'), 'öffentliche Seiten verlinken den Download nicht');
t(in_array('nexusdeck.php', PAGE_IGNORE, true), 'Endpunkt erscheint nicht im Seiten-Register/Menü');

// ---------- Testkonten ----------
$db = site_db();
$db->prepare('INSERT INTO roles (name) VALUES (?)')->execute(['SdaWeb' . bin2hex(random_bytes(2))]);
$rid = (int)$db->lastInsertId();
$db->prepare('INSERT INTO role_permissions (role_id, permission) VALUES (?, ?)')->execute([$rid, 'vrcx.access']);
$db->prepare('INSERT INTO roles (name) VALUES (?)')->execute(['SdaNone' . bin2hex(random_bytes(2))]);
$rid0 = (int)$db->lastInsertId();
nx_db();
$U = [];
foreach (['ok' => [$rid, true, true, true], 'off' => [$rid, true, true, false], 'age' => [$rid, true, false, false], 'novrc' => [$rid, false, false, false], 'noperm' => [$rid0, true, true, true]] as $k => [$role, $vrc, $v18, $on]) {
    $uid = auth_create('sda' . $k . bin2hex(random_bytes(3)), 'Test-Passwort-123!', $role, 'SDA ' . $k);
    $vid = 'usr_' . bin2hex(random_bytes(4)) . '-1111-2222-3333-444444444444';
    if ($vrc) {
        vrc_save_account($uid, ['id' => $vid, 'displayName' => 'SDA ' . $k], ['auth' => 'test']);
        nx_db()->prepare('INSERT INTO nexus_accounts (vrc_id, display_name, created, last_seen, age_status, age_checked, age_until, nsfw_on, banned) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)')
            ->execute([$vid, 'SDA ' . $k, time(), time(), $v18 ? '18+' : '', time(), $v18 ? time() + 86400 : 0, $on ? 1 : 0]);
    }
    $U[$k] = $uid;
}

// ---------- eingebauter PHP-Server mit derselben Datenbank ----------
file_put_contents($tmp . '/cfg.json', json_encode($cfg));
$router = $tmp . '/router.php';
file_put_contents($router, '<?php define("DATA_DIR", ' . var_export(DATA_DIR, true) . '); $c = json_decode(file_get_contents(' . var_export($tmp . '/cfg.json', true) . '), true); if ($c) $GLOBALS["__db_cfg"] = $c; chdir(' . var_export(realpath(__DIR__ . '/../..'), true) . ');'
    . ' if (strpos($_SERVER["REQUEST_URI"], "/__testlogin/") === 0) { require "inc/core/pages.php"; auth_start_session(user_load((int)substr($_SERVER["REQUEST_URI"], 13))); echo "ok"; exit; }'
    . ' require "nexusdeck.php";');
$sock = stream_socket_server('tcp://127.0.0.1:0'); $port = (int)explode(':', stream_socket_get_name($sock, false))[1]; fclose($sock);
$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $router], [1 => ['file', $tmp . '/srv.log', 'a'], 2 => ['file', $tmp . '/srv.log', 'a']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
function get(string $path, string $jar = ''): array {
    global $port;
    $ch = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HEADER => true]);
    if ($jar !== '') curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    $r = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    return ['code' => $code, 'head' => substr($r, 0, $hs), 'body' => substr($r, $hs)];
}
$login = function (string $k) use ($U, $tmp) { $jar = $tmp . '/jar_' . $k; get('/__testlogin/' . $U[$k], $jar); return $jar; };

try {
    $r = get('/nexusdeck.php');
    t($r['code'] === 403 && !str_contains($r['body'], 'PK'), 'ohne Anmeldung: 403 (keine Weiterleitung, kein Paket)');
    t(get('/nexusdeck.php?info=1')['code'] === 403, 'ohne Anmeldung: auch die Info 403');
    $r = get('/nexusdeck.php', $login('noperm'));
    t($r['code'] === 403, 'ohne Nexus-Web-Recht: 403');
    $r = get('/nexusdeck.php', $login('novrc'));
    t($r['code'] === 403 && str_contains($r['body'], 'VRChat'), 'ohne verbundenes VRChat-Konto: 403 (' . trim($r['body']) . ')');
    $r = get('/nexusdeck.php', $login('age'));
    t($r['code'] === 403 && str_contains($r['body'], '18+'), 'ohne Verified 18+: 403');
    $r = get('/nexusdeck.php', $login('off'));
    t($r['code'] === 403, 'Verified 18+, aber NSFW aus: 403');
    $cnt = function () use ($db) { $q = $db->prepare('SELECT value FROM settings WHERE key = ?'); $q->execute(['sdadult_downloads']); return json_decode((string)($q->fetchColumn() ?: '{}'), true) ?: []; };
    $before = $cnt();
    $jar = $login('ok');
    $i = get('/nexusdeck.php?info=1', $jar);
    $info = json_decode($i['body'], true);
    t($i['code'] === 200 && ($info['version'] ?? '') === sda_version() && !empty($info['available']), 'mit 18+ und NSFW: Info ' . $i['body']);
    $r = get('/nexusdeck.php', $jar);
    $why = '';
    t($r['code'] === 200 && stripos($r['head'], 'filename="VRC-Nexus-18.streamDeckPlugin"') !== false && valid_plugin($r['body'], $why), 'mit 18+ und NSFW: 200 + gültiges .streamDeckPlugin ' . $why);
    t(stripos($r['head'], 'Cache-Control: no-store') !== false, 'Download nicht zwischengespeichert');
    $after = $cnt();
    $k = date('Y-m-d') . '|' . sda_version();
    t((int)($after[$k] ?? 0) === (int)($before[$k] ?? 0) + 1 && !preg_match('/usr_|127\.0\.0\.1|sdaok/', json_encode($after)), 'Protokoll: nur Zähler je Tag + Version, ohne Benutzer/IP');
    // NSFW aus → sofort gesperrt (Prüfung bei jeder Anfrage)
    $vid = (string)(vrc_account($U['ok'])['vrc_id'] ?? '');
    nx_db()->prepare('UPDATE nexus_accounts SET nsfw_on = 0 WHERE vrc_id = ?')->execute([$vid]);
    t(get('/nexusdeck.php', $jar)['code'] === 403, 'NSFW ausgeschaltet → sofort 403');
    nx_db()->prepare('UPDATE nexus_accounts SET nsfw_on = 1 WHERE vrc_id = ?')->execute([$vid]);
    $got429 = false;
    for ($j = 0; $j < 15 && !$got429; $j++) $got429 = get('/nexusdeck.php?info=1', $jar)['code'] === 429;
    t($got429, 'Rate-Limit greift (429)');
} finally {
    proc_terminate($proc);
    if ($fails) echo "--- Server-Log ---\n" . @file_get_contents($tmp . '/srv.log');
}
echo ($fails ? "\n✖ $fails von $n fehlgeschlagen ($engine)\n" : "\n✔ $n Tests bestanden ($engine)\n");
exit($fails ? 1 : 0);
