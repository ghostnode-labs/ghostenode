<?php
/**
 * Tests für nexuslic.php 8.4 (Tags: Zustellung signierter NXK1-Schlüssel über a=mine) – nur Testdaten und Test-Schlüssel.
 *   php tools/tests/nexuslic_test.php                 (SQLite in einem Wegwerf-Ordner)
 *   php my.php tools/tests/nexuslic_test.php          (gegen ein lokales Test-MySQL/MariaDB: $GLOBALS['__db_cfg'] vorher setzen)
 * Startet dafür kurz einen eingebauten PHP-Server (127.0.0.1, freier Port) mit einem zusätzlichen Test-Schlüssel (NEXUS_WARN_TEST_PUBKEY).
 * NIE gegen die echte Datenbank laufen lassen.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gnlic-' . bin2hex(random_bytes(4));
mkdir($tmp . '/data', 0700, true);
define('DATA_DIR', $tmp . '/data');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$cfg = $GLOBALS['__db_cfg'] ?? null;

// Test-Schlüsselpaar (Ed25519) – öffentlicher Teil als SPKI wie in der App
$kp = sodium_crypto_sign_keypair();
$SK = sodium_crypto_sign_secretkey($kp);
$PUB = base64_encode(hex2bin('302a300506032b6570032100') . sodium_crypto_sign_publickey($kp));
putenv('NEXUS_WARN_TEST_PUBKEY=' . $PUB);

require __DIR__ . '/../../inc/core/pages.php';
require __DIR__ . '/../../inc/modules/nexuslic.php';

$fails = 0; $n = 0;
function t(bool $ok, string $name): void { global $fails, $n; $n++; echo ($ok ? "ok   " : "FAIL ") . $name . "\n"; if (!$ok) $fails++; }
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function nxk(array $p, ?string $sk = null): string { global $SK; $body = b64u(json_encode($p)); return 'NXK1.' . $body . '.' . b64u(sodium_crypto_sign_detached($body, $sk ?? $SK)); }

$db = nl_db();
$engine = db_is_mysql() ? 'MySQL' : 'SQLite';
// ---------- Migration: alte Tabelle (ohne token) → Schema 2 ----------
nx_db();   // Nexus-Tabellen (Migration im Modul „vrchat“)
if (db_is_mysql()) foreach (['nexus_accounts', 'nexus_devices'] as $tb) $db->exec('DELETE FROM ' . $tb);
$db->exec('DROP TABLE IF EXISTS lic_keys');
$db->exec("CREATE TABLE lic_keys (id TEXT PRIMARY KEY, uid TEXT NOT NULL, name TEXT NOT NULL DEFAULT '', roles TEXT NOT NULL DEFAULT '', iat INTEGER NOT NULL DEFAULT 0,
    exp INTEGER NOT NULL DEFAULT 0, note TEXT NOT NULL DEFAULT '', revoked INTEGER NOT NULL DEFAULT 0, revoked_src TEXT NOT NULL DEFAULT '', updated INTEGER NOT NULL DEFAULT 0,
    last_check INTEGER NOT NULL DEFAULT 0, checks INTEGER NOT NULL DEFAULT 0, last_version TEXT NOT NULL DEFAULT '')");
$db->prepare("INSERT INTO lic_keys (id, uid, roles, iat) VALUES ('alt000000001', 'usr_99999999-1111-2222-3333-444444444444', 'supporter', 1)")->execute();
set_setting('nexuslic_schema', '1');
set_setting('nexuswarn_revoked', '');
nl_schema();
t(in_array('token', db_columns($db, 'lic_keys'), true), 'Migration 2: Spalte token in lic_keys (' . $engine . ')');
t(setting('nexuslic_schema', '') === NL_SCHEMA, 'Migration 2: Schema-Stand gemerkt');
t((string)$db->query("SELECT token FROM lic_keys WHERE id = 'alt000000001'")->fetchColumn() === '', 'Migration 2: alte Einträge bleiben, ohne Schlüssel');

// ---------- Testkonten ----------
$u = fn(string $c) => 'usr_' . str_repeat($c, 8) . '-1111-2222-3333-444444444444';
$users = ['a' => $u('a'), 'b' => $u('b'), 'c' => $u('c'), 'm' => $u('d')];
$tok = [];
foreach ($users as $k => $uid) {
    $db->prepare("INSERT INTO nexus_accounts (vrc_id, display_name, created, last_seen, age_status, age_checked, age_until, nsfw_on, banned) VALUES (?, ?, ?, ?, '', 0, 0, 0, 0)")->execute([$uid, 'Test ' . $k, time(), time()]);
    $tok[$k] = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO nexus_devices (token_hash, vrc_id, name, created, last_seen) VALUES (?, ?, ?, ?, ?)')->execute([hash('sha256', $tok[$k]), $uid, 'Test', time(), time()]);
}
// Register-Einträge wie nach einem Abgleich der Besitzer-App
$row = function (string $id, string $uid, array $f, int $exp = 0) use ($db) {
    $db->prepare('INSERT INTO lic_keys (id, uid, name, roles, iat, exp, note, revoked, revoked_src, updated) VALUES (?, ?, ?, ?, ?, ?, ?, 0, \'\', ?)')
        ->execute([$id, $uid, 'Test', implode(',', $f), time(), $exp, '', time()]);
    return nxk(['v' => 1, 'id' => $id, 'uid' => $uid, 'f' => $f, 'iat' => time(), 'exp' => $exp, 'n' => '']);
};
$kA = $row('a00000000001', $users['a'], ['creator', 'vip', 'partner']);
$kB = $row('b00000000001', $users['b'], ['supporter', 'event']);
$kM = $row('d00000000001', $users['m'], ['mod', 'artist']);
$kR = $row('a00000000002', $users['a'], ['beta']);
$kX = $row('a00000000003', $users['a'], ['vip'], time() - 10);   // abgelaufen
t(nl_store_token('a00000000001', $users['a'], $kA), 'Abgleich: gültiger Schlüssel wird zur Zustellung gespeichert');
t(nl_store_token('b00000000001', $users['b'], $kB), 'Abgleich: zweite Person');
t(!nl_store_token('d00000000001', $users['m'], $kM), 'Abgleich: Moderator-Schlüssel wird NIE gespeichert');
t(!nl_store_token('a00000000002', $users['a'], $kB), 'Abgleich: Schlüssel einer anderen Person/ID wird abgelehnt');
$fake = nxk(['v' => 1, 'id' => 'a00000000002', 'uid' => $users['a'], 'f' => ['beta'], 'iat' => time(), 'exp' => 0], sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
t(!nl_store_token('a00000000002', $users['a'], $fake), 'Abgleich: fremd unterschriebener Schlüssel wird abgelehnt');
t(nl_store_token('a00000000002', $users['a'], $kR), 'Abgleich: weiterer Schlüssel derselben Person');
t(!nl_store_token('a00000000003', $users['a'], $kX), 'Abgleich: abgelaufener Schlüssel wird nicht gespeichert');
nl_set_revoked('a00000000002', true, 'app');   // entzogen
// Schutz in der Tiefe: auch wenn in der Datenbank ein fremder/Moderator-Schlüssel stünde, gibt „mine“ ihn nicht heraus
$db->prepare('UPDATE lic_keys SET token = ? WHERE id = ?')->execute([$kM, 'd00000000001']);
$db->prepare("INSERT INTO lic_keys (id, uid, roles, iat, token) VALUES ('c00000000001', ?, 'vip', ?, ?)")->execute([$users['c'], time(), $kA]);
t(!in_array('token', array_keys(nl_list()[0] ?? []), true) && isset(nl_list()[0]['delivered']), 'Admin-Liste zeigt den Schlüssel selbst nie');

// ---------- eingebauter PHP-Server mit derselben Datenbank ----------
file_put_contents($tmp . '/cfg.json', json_encode($cfg));
$router = $tmp . '/router.php';
file_put_contents($router, '<?php define("DATA_DIR", ' . var_export(DATA_DIR, true) . '); $c = json_decode(file_get_contents(' . var_export($tmp . '/cfg.json', true) . '), true); if ($c) $GLOBALS["__db_cfg"] = $c; chdir(' . var_export(realpath(__DIR__ . '/../..'), true) . '); require "nexuslic.php";');
$sock = stream_socket_server('tcp://127.0.0.1:0'); $port = (int)explode(':', stream_socket_get_name($sock, false))[1]; fclose($sock);
$env = array_merge(getenv(), ['NEXUS_WARN_TEST_PUBKEY' => $PUB]);
$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $router], [1 => ['file', $tmp . '/srv.log', 'a'], 2 => ['file', $tmp . '/srv.log', 'a']], $pipes, null, $env);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
function call(string $a, array $body): array {
    global $port;
    $ch = curl_init('http://127.0.0.1:' . $port . '/nexuslic.php?a=' . $a);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 20]);
    $r = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $d = json_decode($r, true);
    return (is_array($d) ? $d : ['error' => 'kein JSON: ' . substr($r, 0, 200)]) + ['_code' => $code];
}
$ids = fn(array $r) => array_map(fn($k) => $k['id'], $r['keys'] ?? []);

try {
    $r = call('mine', ['token' => $tok['a']]);
    t($r['_code'] === 200 && $ids($r) === ['a00000000001'], 'mine: Person A bekommt nur ihren gültigen Schlüssel (nicht entzogen/abgelaufen) ' . json_encode($ids($r)));
    t(($r['keys'][0]['token'] ?? '') === $kA && ($r['keys'][0]['tags'] ?? []) === ['creator', 'vip', 'partner'], 'mine: Schlüssel und Tags stimmen');
    $r = call('mine', ['token' => $tok['b']]);
    t($ids($r) === ['b00000000001'] && !str_contains(json_encode($r), $kA), 'mine: fremde ID bekommt nichts von A');
    $r = call('mine', ['token' => $tok['c']]);
    t($r['_code'] === 200 && $ids($r) === [], 'mine: falsch abgelegter fremder Schlüssel wird nicht herausgegeben ' . json_encode($r));
    $r = call('mine', ['token' => $tok['m']]);
    t($ids($r) === [], 'mine: Moderator-Schlüssel nie über mine');
    $r = call('mine', ['token' => 'quatsch']);
    t($r['_code'] === 401 && !isset($r['keys']), 'mine: ungültiges Token → 401');
    $r = call('mine', ['token' => bin2hex(random_bytes(32))]);
    t($r['_code'] === 401, 'mine: unbekanntes Token → 401');
    $r = call('mine', []);
    t($r['_code'] === 401, 'mine: ohne Token → 401');
    $db->prepare('UPDATE nexus_accounts SET banned = 1 WHERE vrc_id = ?')->execute([$users['b']]);
    $r = call('mine', ['token' => $tok['b']]);
    t($r['_code'] === 401, 'mine: gesperrtes Konto → 401');
    // Entziehen wirkt sofort
    nl_set_revoked('a00000000001', true, 'web');
    $r = call('mine', ['token' => $tok['a']]);
    t($ids($r) === [], 'mine: nach dem Entziehen nichts mehr');
    t(in_array('a00000000001', call('revoked', [])['ids'] ?? [], true), 'revoked: entzogener Schlüssel steht in der Sperrliste');
    nl_set_revoked('a00000000001', false, 'web');
    // Abgleich bleibt der Besitzerin vorbehalten (ein Test-Schlüssel ist nie Besitzer)
    $r = call('sync', ['key' => $kA, 'entries' => [['id' => 'a00000000009', 'uid' => $users['a'], 'f' => ['vip'], 'token' => $kA]]]);
    t($r['_code'] === 403 && str_contains((string)($r['error'] ?? ''), 'Besitzerin'), 'sync: nur Besitzerin (403) ' . json_encode($r));
    t((int)$db->query("SELECT COUNT(*) FROM lic_keys WHERE id = 'a00000000009'")->fetchColumn() === 0, 'sync: kein Eintrag ohne Besitzerin');
    // Tags fürs Profil (nur aktive, keine Rechte – Besitzer-Abzeichen nur über den Owner-Lock)
    t(nl_tags_for($users['a']) === ['creator', 'vip', 'partner'], 'Profil: aktive Tags von A ' . json_encode(nl_tags_for($users['a'])));
    t(nl_tags_for($users['m']) === ['mod', 'artist'], 'Profil: Moderator als Abzeichen sichtbar');
    t(!in_array('owner', nl_tags_for($users['a']), true) && array_column(nl_tag_badges($users['a']), 'tag') === ['creator', 'vip', 'partner'], 'Profil: kein Besitzer-Abzeichen aus Schlüsseln');
    $db->prepare("UPDATE lic_keys SET roles = 'owner,vip' WHERE id = 'b00000000001'")->execute();
    t(nl_tags_for($users['b']) === ['vip'], 'Profil: „owner“ im Register wird ignoriert');
    // Bremse
    $got429 = false;
    for ($i = 0; $i < 35 && !$got429; $i++) $got429 = call('mine', ['token' => $tok['a']])['_code'] === 429;
    t($got429, 'mine: Rate-Limit greift (429)');
} finally {
    proc_terminate($proc);
    if ($fails) echo "--- Server-Log ---\n" . @file_get_contents($tmp . '/srv.log');
}
echo ($fails ? "\n✖ $fails von $n fehlgeschlagen ($engine)\n" : "\n✔ $n Tests bestanden ($engine)\n");
exit($fails ? 1 : 0);
