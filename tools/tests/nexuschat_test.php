<?php
/**
 * Tests für nexuschat.php 8.3 (Protokoll-Aushandlung nexus-v1 / signal-v1, Kyber-Pre-Keys, Registrierungs-ID) – nur Testdaten.
 *   php tools/tests/nexuschat_test.php                 (SQLite in einem Wegwerf-Ordner)
 *   php my.php tools/tests/nexuschat_test.php          (gegen ein lokales Test-MySQL/MariaDB: $GLOBALS['__db_cfg'] vorher setzen)
 * Startet dafür kurz einen eingebauten PHP-Server (127.0.0.1, freier Port). NIE gegen die echte Datenbank laufen lassen.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gnchat-' . bin2hex(random_bytes(4));
mkdir($tmp . '/data', 0700, true);
define('DATA_DIR', $tmp . '/data');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$cfg = $GLOBALS['__db_cfg'] ?? null;
require __DIR__ . '/../../inc/core/pages.php';
require __DIR__ . '/../../inc/modules/nexusacct.php';

$fails = 0; $n = 0;
function t(bool $ok, string $name): void { global $fails, $n; $n++; echo ($ok ? "ok   " : "FAIL ") . $name . "\n"; if (!$ok) $fails++; }

// ---------- MySQL: Testtabellen dieses Laufs vorher leeren ----------
$db = nx_db();
if (db_is_mysql()) foreach (['chat_devices', 'chat_opks', 'chat_sig_opks', 'chat_kyber_keys', 'chat_inbox', 'nexus_accounts', 'nexus_devices'] as $tb) $db->exec('DELETE FROM ' . $tb);
t((int)$db->query('PRAGMA user_version')->fetchColumn() >= 21, 'Migration: Schema-Version ≥ 21 (' . (db_is_mysql() ? 'MySQL' : 'SQLite') . ')');
$cols = db_columns($db, 'chat_devices');
t(count(array_intersect(['protos', 'reg_id', 'sig_ik', 'sig_bind', 'sig_spk', 'sig_kyber'], $cols)) === 6, 'Migration: neue Spalten in chat_devices');
t(in_array('chat_kyber_keys', db_tables($db), true) && in_array('chat_sig_opks', db_tables($db), true), 'Migration: Tabellen chat_kyber_keys + chat_sig_opks');

// ---------- Testkonten ----------
$users = ['a' => 'usr_aaaaaaaa-1111-2222-3333-444444444444', 'b' => 'usr_bbbbbbbb-1111-2222-3333-444444444444', 'c' => 'usr_cccccccc-1111-2222-3333-444444444444'];
$tok = [];
foreach ($users as $k => $uid) {
    $db->prepare("INSERT INTO nexus_accounts (vrc_id, display_name, created, last_seen, age_status, age_checked, age_until, nsfw_on, banned) VALUES (?, ?, ?, ?, '', 0, 0, 0, 0)")->execute([$uid, 'Test ' . $k, time(), time()]);
    $tok[$k] = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO nexus_devices (token_hash, vrc_id, name, created, last_seen) VALUES (?, ?, ?, ?, ?)')->execute([hash('sha256', $tok[$k]), $uid, 'Test', time(), time()]);
}

// ---------- eingebauter PHP-Server mit derselben Datenbank ----------
file_put_contents($tmp . '/cfg.json', json_encode($cfg));
$router = $tmp . '/router.php';
file_put_contents($router, '<?php define("DATA_DIR", ' . var_export(DATA_DIR, true) . '); $c = json_decode(file_get_contents(' . var_export($tmp . '/cfg.json', true) . '), true); if ($c) $GLOBALS["__db_cfg"] = $c; chdir(' . var_export(realpath(__DIR__ . '/../..'), true) . '); require "nexuschat.php";');
$sock = stream_socket_server('tcp://127.0.0.1:0'); $port = (int)explode(':', stream_socket_get_name($sock, false))[1]; fclose($sock);
$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $router], [1 => ['file', $tmp . '/srv.log', 'a'], 2 => ['file', $tmp . '/srv.log', 'a']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
function call(string $a, array $body): array {
    global $port;
    $ch = curl_init('http://127.0.0.1:' . $port . '/nexuschat.php?a=' . $a);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 20]);
    $r = (string)curl_exec($ch); curl_close($ch);
    $d = json_decode($r, true);
    return is_array($d) ? $d : ['error' => 'kein JSON: ' . substr($r, 0, 200)];
}

// ---------- Schlüssel (Testwerte; libsignal-Signaturen prüft der Server nicht, nur die Bindung an ik_sig) ----------
function nexus_keys(): array {
    $sig = sodium_crypto_sign_keypair(); $ikd = random_bytes(32); $spk = random_bytes(32);
    return ['sk' => sodium_crypto_sign_secretkey($sig), 'iks' => sodium_crypto_sign_publickey($sig), 'ikd' => $ikd, 'spk' => $spk];
}
function nexus_publish(array $k, array $extra = []): array {
    return ['ik_dh' => base64_encode($k['ikd']), 'ik_sig' => base64_encode($k['iks']),
        'spk' => ['id' => 1, 'pub' => base64_encode($k['spk']), 'sig' => base64_encode(sodium_crypto_sign_detached('spk' . $k['spk'] . $k['ikd'], $k['sk']))],
        'opks' => [['id' => 1, 'pub' => base64_encode(random_bytes(32))], ['id' => 2, 'pub' => base64_encode(random_bytes(32))]]] + $extra;
}
function sig_part(array $k, bool $badBind = false): array {
    $ik = "\x05" . random_bytes(32);
    $kp = fn(int $id) => ['id' => $id, 'pub' => base64_encode("\x08" . random_bytes(1568)), 'sig' => base64_encode(random_bytes(64))];
    return ['reg' => 4242, 'ik' => base64_encode($ik), 'bind' => base64_encode($badBind ? random_bytes(64) : sodium_crypto_sign_detached('nexus-signal-v1' . $ik, $k['sk'])),
        'spk' => ['id' => 1, 'pub' => base64_encode("\x05" . random_bytes(32)), 'sig' => base64_encode(random_bytes(64))], 'kyber' => $kp(1),
        'opks' => [['id' => 1, 'pub' => base64_encode("\x05" . random_bytes(32))], ['id' => 2, 'pub' => base64_encode("\x05" . random_bytes(32))]], 'kybers' => [$kp(2), $kp(3)]];
}

try {
    // 1) alter Client (ohne Protokoll-Liste) – unverändert nexus-v1
    $ka = nexus_keys();
    $r = call('publish', ['token' => $tok['a']] + nexus_publish($ka));
    t(isset($r['device']) && $r['protos'] === ['nexus-v1'] && $r['opks'] === 2, 'alter Client: Anmeldung, nur nexus-v1 ' . json_encode($r));
    $devA = $r['device'];
    // 2) neuer Desktop-Client mit signal-v1
    $kb = nexus_keys(); $sb = sig_part($kb);
    $r = call('publish', ['token' => $tok['b'], 'protos' => ['nexus-v1', 'signal-v1', 'quatsch-v9'], 'sig' => $sb] + nexus_publish($kb));
    t(($r['protos'] ?? null) === ['nexus-v1', 'signal-v1'] && $r['sig_opks'] === 2 && $r['sig_kybers'] === 2, 'neuer Client: signal-v1 angenommen, Einmal-Schlüssel gezählt ' . json_encode($r));
    $devB = $r['device'];
    // 3) falsche Bindung → Gerät trotzdem angemeldet, aber ohne signal-v1
    $kc = nexus_keys();
    $r = call('publish', ['token' => $tok['c'], 'protos' => ['nexus-v1', 'signal-v1'], 'sig' => sig_part($kc, true)] + nexus_publish($kc));
    t(isset($r['device']) && $r['protos'] === ['nexus-v1'] && strpos((string)($r['sig_error'] ?? ''), 'gebunden') !== false, 'falsche Bindung: nur nexus-v1, Fehler gemeldet');
    $devC = $r['device'];
    // 4) devices: Protokoll-Liste + Signal-Identität nur bei gültigem signal-v1
    $r = call('devices', ['token' => $tok['a'], 'uid' => $users['b']]);
    $d = $r['devices'][0] ?? [];
    t($d['protos'] === ['nexus-v1', 'signal-v1'] && $d['sig_ik'] === $sb['ik'] && $d['sig_bind'] === $sb['bind'] && $d['reg'] === 4242, 'devices: protos, sig_ik, sig_bind, reg');
    $r = call('devices', ['token' => $tok['b'], 'uid' => $users['a']]);
    t($r['devices'][0]['protos'] === ['nexus-v1'] && !isset($r['devices'][0]['sig_ik']), 'devices: altes Gerät nur nexus-v1');
    $r = call('devices', ['token' => $tok['b'], 'uid' => $users['c']]);
    t($r['devices'][0]['protos'] === ['nexus-v1'], 'devices: Gerät mit falscher Bindung nur nexus-v1');
    // 5) bundles ohne sig (alter Client) → wie bisher, nexus-Einmal-Schlüssel verbraucht, kein sig-Teil
    $r = call('bundles', ['token' => $tok['a'], 'uid' => $users['b']]);
    $b = $r['bundles'][0] ?? [];
    t(($b['opk']['id'] ?? 0) === 1 && !isset($b['sig']) && $b['protos'] === ['nexus-v1', 'signal-v1'], 'bundles (alt): nexus-Paket mit Einmal-Pre-Key');
    // 6) bundles mit sig=1 → libsignal-Paket, Einmal-Kyber verbraucht, nexus-Einmal-Schlüssel bleibt
    $r = call('bundles', ['token' => $tok['a'], 'uid' => $users['b'], 'sig' => 1]);
    $b = $r['bundles'][0] ?? [];
    t($b['opk'] === null && ($b['sig']['kyber']['id'] ?? 0) === 2 && ($b['sig']['opk']['id'] ?? 0) === 1 && $b['sig']['reg'] === 4242 && $b['sig']['spk']['id'] === 1 && $b['sig']['ik'] === $sb['ik'], 'bundles (signal): Einmal-Kyber + Einmal-EC');
    call('bundles', ['token' => $tok['a'], 'uid' => $users['b'], 'sig' => 1]);
    $r = call('bundles', ['token' => $tok['a'], 'uid' => $users['b'], 'sig' => 1]);
    $b = $r['bundles'][0] ?? [];
    t(($b['sig']['kyber']['id'] ?? 0) === 1 && $b['sig']['opk'] === null, 'bundles (signal): Einmal-Schlüssel leer → Kyber-Last-Resort, ohne EC-Einmal-Schlüssel');
    $r = call('bundles', ['token' => $tok['b'], 'uid' => $users['a'], 'sig' => 1]);
    t(!isset($r['bundles'][0]['sig']) && ($r['bundles'][0]['opk']['id'] ?? 0) === 1, 'bundles (signal-Abfrager → altes Gerät): nexus-v1-Paket');
    // 7) fetch zählt, Nachrichten unverändert
    $r = call('send', ['token' => $tok['a'], 'device' => $devA, 'msgs' => [['to' => $users['b'], 'dev' => $devB, 'body' => '{"p":"signal-v1","t":3,"m":"AAAA"}']]]);
    t(($r['ok'] ?? 0) === 1, 'send: signal-v1-Umschlag angenommen');
    $r = call('fetch', ['token' => $tok['b'], 'device' => $devB]);
    t(count($r['msgs']) === 1 && $r['msgs'][0]['body'] === '{"p":"signal-v1","t":3,"m":"AAAA"}' && $r['sig_opks'] === 0 && $r['sig_kybers'] === 0 && $r['opks'] === 1, 'fetch: Zähler für Nachlegen');
    // 8) Nachlegen + erneute Anmeldung ohne sig lässt signal-v1 nicht stillschweigend weg, wenn protos fehlt
    $more = sig_part($kb); $more['ik'] = $sb['ik']; $more['bind'] = $sb['bind']; $more['kybers'] = [['id' => 7, 'pub' => base64_encode("\x08" . random_bytes(1568)), 'sig' => base64_encode(random_bytes(64))]]; $more['opks'] = [['id' => 9, 'pub' => base64_encode("\x05" . random_bytes(32))]];
    $r = call('publish', ['token' => $tok['b'], 'protos' => ['nexus-v1', 'signal-v1'], 'sig' => $more] + nexus_publish($kb));
    t($r['sig_kybers'] === 1 && $r['sig_opks'] === 1, 'publish: Einmal-Schlüssel nachgelegt');
    $r = call('publish', ['token' => $tok['b']] + nexus_publish($kb));
    t($r['protos'] === ['nexus-v1', 'signal-v1'], 'publish ohne protos (z. B. älteres Backend): bestehende Angaben bleiben');
    // 9) fremdes Gerät kann nichts überschreiben; zu große Schlüssel abgelehnt
    $r = call('publish', ['token' => $tok['a'], 'protos' => ['nexus-v1', 'signal-v1'], 'sig' => sig_part($kb)] + nexus_publish($kb));
    t(strpos((string)($r['error'] ?? ''), 'anderen Konto') !== false, 'publish: Gerät eines anderen Kontos abgelehnt');
    $bad = sig_part($kb); $bad['kyber']['pub'] = base64_encode(random_bytes(5000));
    $r = call('publish', ['token' => $tok['b'], 'protos' => ['nexus-v1', 'signal-v1'], 'sig' => $bad] + nexus_publish($kb));
    t(isset($r['sig_error']) && $r['protos'] === ['nexus-v1'], 'publish: ungültiger Kyber-Schlüssel → sig_error, Rückfall');
    // 10) entfernen löscht auch die neuen Tabellen
    call('publish', ['token' => $tok['b'], 'protos' => ['nexus-v1', 'signal-v1'], 'sig' => sig_part($kb) + []] + nexus_publish($kb));
    $q = fn(string $t) => (function () use ($t, $db, $devB) { $s = $db->prepare('SELECT COUNT(*) FROM ' . $t . ' WHERE device_id = ?'); $s->execute([$devB]); return (int)$s->fetchColumn(); })();
    t($q('chat_kyber_keys') > 0, 'vor dem Entfernen: Kyber-Schlüssel gespeichert');
    $r = call('remove', ['token' => $tok['b'], 'device' => $devB]);
    t(($r['ok'] ?? false) === true && $q('chat_kyber_keys') === 0 && $q('chat_sig_opks') === 0 && $q('chat_devices') === 0, 'remove: Gerät inkl. signal-v1-Schlüssel gelöscht');
    unset($devC);
} finally {
    proc_terminate($proc); proc_close($proc);
    if ($fails) echo "--- Server-Log ---\n" . @file_get_contents($tmp . '/srv.log') . "\n";
    if (db_is_mysql()) foreach (['chat_devices', 'chat_opks', 'chat_sig_opks', 'chat_kyber_keys', 'chat_inbox', 'nexus_accounts', 'nexus_devices'] as $tb) $db->exec('DELETE FROM ' . $tb);
}
echo ($fails ? "FEHLER: $fails von $n\n" : "Alle $n Tests bestanden\n");
exit($fails ? 1 : 0);
