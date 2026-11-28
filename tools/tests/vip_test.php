<?php
/**
 * Tests für die VIP-Listen (inc/modules/vip.php, vip.php, kofi.php, Admin-Reiter) – nur Testdaten und Mocks, kein echtes Ko-fi/GitHub.
 *   php tools/tests/vip_test.php                 (SQLite in einem Wegwerf-Ordner)
 *   php my.php tools/tests/vip_test.php          (gegen ein lokales Test-MySQL/MariaDB: $GLOBALS['__db_cfg'] vorher setzen)
 * Startet kurz einen eingebauten PHP-Server (127.0.0.1, freier Port) für vip.php und kofi.php. NIE gegen die echte Datenbank laufen lassen.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gnvip-' . bin2hex(random_bytes(4));
mkdir($tmp . '/data', 0700, true);
define('DATA_DIR', $tmp . '/data');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$cfg = $GLOBALS['__db_cfg'] ?? null;
putenv('GN_KOFI_TOKEN'); putenv('GN_GIST_TOKEN');
require __DIR__ . '/../../inc/core/pages.php';
require __DIR__ . '/../../inc/admin/vip_tab.php';
require_once __DIR__ . '/../../inc/core/tasks.php';

$fails = 0; $n = 0;
function t(bool $ok, string $name): void { global $fails, $n; $n++; echo ($ok ? "ok   " : "FAIL ") . $name . "\n"; if (!$ok) $fails++; }
$engine = db_is_mysql() ? 'MySQL' : 'SQLite';
$GLOBALS['__vip_no_autosync'] = true;   // Gist nur dort abgleichen, wo der Test es will
$db = vip_db();
$sfx = bin2hex(random_bytes(3));
// gemeinsame Test-Datenbank (MySQL) kann Einstellungen eines früheren Laufs enthalten → zurücksetzen
foreach (['vip_gist_id' => '', 'vip_gist_owner' => '', 'vip_gist_hash' => '', 'vip_gist_files' => '[]', 'vip_gist_on' => '0', 'vip_gist_last' => '0', 'vip_kofi_review' => '0', 'vip_kofi_types' => implode(',', VIP_KOFI_TYPES), 'vip_kofi_token_enc' => '', 'vip_gist_token_enc' => '', 'vip_kofi_sub_days' => '35', 'vip_kofi_don_days' => '0'] as $sk => $sv) set_setting($sk, $sv);

// ---------- Schema / Migration ----------
vip_schema();
t(count(array_intersect(['vip_lists', 'vip_entries', 'vip_kofi_seen'], db_tables($db))) === 3 && setting('vip_schema') === VIP_SCHEMA, 'Migration 1: Tabellen vip_lists, vip_entries, vip_kofi_seen (' . $engine . ')');
t(isset(PERMISSIONS['vip.manage']) && VIP_ADMIN_TABS['vip'][2] === 'vip.manage' && isset(TASKS['vipgist']), 'Recht vip.manage, Admin-Reiter und Aufgabe „vipgist“ registriert');
t(in_array('vip.php', PAGE_IGNORE, true) && in_array('kofi.php', PAGE_IGNORE, true), 'Endpunkte erscheinen nicht im Seiten-Register');

// ---------- Listen + Einträge ----------
$vip = vip_list_save(['title' => 'VIP Test', 'slug' => 'vip-' . $sfx, 'world' => 'Neon Club', 'grp' => 'vip', 'gist' => 1]);
$kofi = vip_list_save(['title' => 'Ko-fi Test', 'slug' => 'kofi-' . $sfx, 'grp' => 'KOFI', 'kofi' => 1, 'gist' => 1]);
t(vip_list($vip)['grp'] === 'VIP' && (int)setting('vip_kofi_list') === $kofi, 'Listen angelegt (Gruppe normalisiert, Ko-fi-Liste gemerkt)');
$dup = false; try { vip_list_save(['title' => 'x', 'slug' => 'vip-' . $sfx]); } catch (InvalidArgumentException $e) { $dup = true; }
t($dup, 'gleiche Kurzadresse wird abgelehnt');
$a = vip_entry_save($vip, ['name' => '  Neon Fox  ', 'note' => 'geheime Notiz', 'grp' => '']);
vip_entry_save($vip, ['name' => '#Alpha|Wolf', 'grp' => 'staff']);
vip_entry_save($vip, ['name' => 'Old Friend', 'expires' => time() - 10]);
vip_entry_save($vip, ['name' => 'Waiting One', 'status' => 'pending']);
$same = vip_entry_save($vip, ['name' => 'neon fox', 'note' => 'neu']);
t($same === $a && count(vip_entries($vip)) === 4, 'gleicher Name (Groß/Klein, Leerzeichen) = derselbe Eintrag');
$e = vip_entry($a);
t($e['name'] === 'neon fox' && $e['nkey'] === 'neon fox' && $e['grp'] === 'VIP', 'Eintrag aktualisiert, Standardgruppe der Liste');
$bad = false; try { vip_entry_save($vip, ['name' => " \n|# "]); } catch (InvalidArgumentException $e2) { $bad = true; }
t($bad, 'leerer/unsinniger Name wird abgelehnt');
t(vip_parse_date('2030-01-31') === strtotime('2030-01-31 23:59:59') && vip_parse_date('') === 0, 'Ablaufdatum JJJJ-MM-TT → Ende des Tages, leer = dauerhaft');

$txt = vip_text(vip_list($vip));
$lines = array_values(array_filter(explode("\n", $txt), fn($l) => $l !== '' && $l[0] !== '#'));
t($lines === ['STAFF|Alpha Wolf', 'VIP|neon fox'], 'Textformat GRUPPE|Name, sortiert, ohne Abgelaufene/Ungeprüfte: ' . json_encode($lines));
t(!str_contains($txt, 'geheime') && !str_contains($txt, 'neu') && !str_contains($txt, 'Old Friend') && !str_contains($txt, 'Waiting'), 'keine Notizen, keine abgelaufenen/wartenden Einträge in der Ausgabe');
t(vip_text(vip_list($vip), 'names') === "Alpha Wolf\nneon fox\n", 'fmt=names: nur Namen, eine Zeile je Name');
t(vip_text(vip_list($vip), '', 'staff') === "# GhostNode VIP-Liste: VIP Test\n# Format: GRUPPE|Anzeigename\nSTAFF|Alpha Wolf\n", 'Filter nach Gruppe');

// ---------- Ko-fi-Webhook (in-process) ----------
$tok = 'kofi-test-' . bin2hex(random_bytes(8));
$mk = function (array $over = []) use (&$tok) {
    return json_encode(array_merge(['verification_token' => $tok, 'message_id' => 'm-' . bin2hex(random_bytes(6)), 'timestamp' => '2026-11-28T12:00:00Z', 'type' => 'Donation',
        'is_public' => true, 'from_name' => 'Kofi Kim', 'message' => 'Danke!', 'amount' => '3.00', 'url' => 'https://ko-fi.com/Home/CoffeeShop?txid=00000000-1111-2222-3333-444444444444',
        'email' => 'kim-test@example.invalid', 'currency' => 'EUR', 'is_subscription_payment' => false, 'is_first_subscription_payment' => false,
        'kofi_transaction_id' => '00000000-1111-2222-3333-444444444444', 'shop_items' => null, 'tier_name' => null], $over));
};
t(vip_kofi_handle($mk())[0] === 503, 'ohne Token: 503 (nichts gespeichert)');
vip_secret_set('kofi', $tok);
t(vip_secret_source('kofi') === 'admin' && str_starts_with(setting('vip_kofi_token_enc'), 'gcm:') && !str_contains(setting('vip_kofi_token_enc'), $tok), 'Token im Admin nur verschlüsselt gespeichert');
putenv('GN_KOFI_TOKEN=env-token-xyz');
t(vip_secret('kofi') === 'env-token-xyz' && vip_secret_source('kofi') === 'env', 'Server-Secret GN_KOFI_TOKEN hat Vorrang');
putenv('GN_KOFI_TOKEN');
t(vip_kofi_handle($mk(['verification_token' => 'falsch']))[0] === 401, 'falsches Token: 401');
t(vip_kofi_handle('kein json')[0] === 400, 'kaputte Daten: 400');
$cnt = fn() => (int)$db->query('SELECT COUNT(*) FROM vip_entries WHERE list_id = ' . (int)$kofi)->fetchColumn();
$m1 = $mk(['from_name' => 'Kofi Kim', 'message' => 'Liebe Grüße! vrc: Neon Kim ✨']);
t(vip_kofi_handle($m1) === [200, 'OK'] && $cnt() === 1, 'richtiges Token: 200, Eintrag angelegt');
$k = $db->query('SELECT * FROM vip_entries WHERE list_id = ' . (int)$kofi)->fetch(PDO::FETCH_ASSOC);
t($k['name'] === 'Neon Kim ✨' && $k['grp'] === 'KOFI' && $k['source'] === 'kofi' && (int)$k['expires'] === 0 && $k['status'] === 'ok', 'Name aus „vrc: …“ in der Nachricht, Gruppe KOFI, dauerhaft');
t(vip_kofi_handle($m1) === [200, 'Schon verarbeitet.'] && $cnt() === 1, 'doppelte Zustellung (gleiche message_id) erkannt');
$all = json_encode($db->query('SELECT * FROM vip_entries')->fetchAll(PDO::FETCH_ASSOC)) . json_encode($db->query('SELECT * FROM vip_kofi_seen')->fetchAll(PDO::FETCH_ASSOC))
     . json_encode($db->query("SELECT * FROM settings WHERE key LIKE 'vip_%'")->fetchAll(PDO::FETCH_ASSOC)) . json_encode($db->query('SELECT * FROM audit_log')->fetchAll(PDO::FETCH_ASSOC));
t(!str_contains($all, 'example.invalid') && !str_contains($all, '3.00') && !str_contains($all, 'Liebe Gr') && !str_contains($all, '00000000-1111') && !str_contains($all, 'Kofi Kim') && !preg_match('/"m-[0-9a-f]{12}"/', $all),
  'keine E-Mail, kein Betrag, keine Nachricht, keine Transaktions-ID, kein Ko-fi-Name, message_id nur als Hash gespeichert');
// Abo: verlängern
$s1 = $mk(['type' => 'Subscription', 'from_name' => 'Abo Anna', 'message' => '', 'is_subscription_payment' => true, 'is_first_subscription_payment' => true, 'tier_name' => 'Neon']);
vip_kofi_handle($s1);
$q = $db->prepare('SELECT * FROM vip_entries WHERE list_id = ? AND nkey = ?');
$q->execute([$kofi, 'abo anna']); $an = $q->fetch(PDO::FETCH_ASSOC);
t($an && abs((int)$an['expires'] - (time() + 35 * 86400)) < 60 && str_contains($an['note'], 'Neon'), 'Abo: läuft nach 35 Tagen ab (Stufe in der Notiz)');
vip_kofi_handle($mk(['type' => 'Subscription', 'from_name' => 'Abo Anna', 'is_subscription_payment' => true, 'is_first_subscription_payment' => false]));
$q->execute([$kofi, 'abo anna']); $an2 = $q->fetch(PDO::FETCH_ASSOC);
t(abs((int)$an2['expires'] - ((int)$an['expires'] + 35 * 86400)) < 60 && (int)$an2['id'] === (int)$an['id'], 'Abo-Zahlung verlängert denselben Eintrag um 35 Tage');
// Prüfmodus + privat
set_setting('vip_kofi_review', '1');
vip_kofi_handle($mk(['from_name' => 'Review Rita']));
set_setting('vip_kofi_review', '0');
vip_kofi_handle($mk(['from_name' => 'Private Paul', 'is_public' => false]));
$q->execute([$kofi, 'review rita']); $rr = $q->fetch(PDO::FETCH_ASSOC);
$q->execute([$kofi, 'private paul']); $pp = $q->fetch(PDO::FETCH_ASSOC);
t($rr['status'] === 'pending' && $pp['status'] === 'pending' && !str_contains(vip_text(vip_list($kofi)), 'Rita') && !str_contains(vip_text(vip_list($kofi)), 'Paul'), '„erst prüfen“ und private Unterstützung: Eintrag wartet, nicht in der Welt');
vip_entry_approve((int)$rr['id']);
t(str_contains(vip_text(vip_list($kofi)), 'KOFI|Review Rita'), 'nach „Freigeben“ in der Liste');
set_setting('vip_kofi_types', 'Subscription');
t(vip_kofi_handle($mk(['from_name' => 'Nur Spende']))[1] === 'Art ignoriert.', 'nicht gewählte Art wird ignoriert (trotzdem 200)');
set_setting('vip_kofi_types', implode(',', VIP_KOFI_TYPES));

// ---------- Gist-Spiegel (gemocktes HTTP) ----------
$calls = [];
$GLOBALS['__vip_http'] = function ($method, $url, $headers, $body) use (&$calls) {
    $calls[] = ['m' => $method, 'u' => $url, 'h' => $headers, 'b' => json_decode((string)$body, true)];
    if (in_array('Authorization: Bearer bad-token', $headers, true)) return [401, '{"message":"Bad credentials"}'];
    return [$method === 'POST' ? 201 : 200, json_encode(['id' => 'abcdef0123456789abcdef0123456789', 'owner' => ['login' => 'ghost-test'], 'files' => []])];
};
t(vip_gist_sync() === 'Gist-Spiegel ist aus.' && !$calls, 'Gist aus: kein Aufruf');
set_setting('vip_gist_on', '1');
t(str_starts_with(vip_gist_sync(), 'Kein Gist-Token') && !$calls, 'ohne Token: Hinweis, kein Aufruf');
putenv('GN_GIST_TOKEN=bad-token');
$err = ''; try { vip_gist_sync(); } catch (RuntimeException $x) { $err = $x->getMessage(); }
t(str_contains($err, '401') && setting('vip_gist_id') === '', 'falsches Gist-Token: Fehler 401, nichts gemerkt');
putenv('GN_GIST_TOKEN=test-gist-token');
$calls = [];
$r = vip_gist_sync();
$c = $calls[0] ?? [];
t(($c['m'] ?? '') === 'POST' && ($c['u'] ?? '') === 'https://api.github.com/gists' && ($c['b']['public'] ?? null) === false && in_array('Authorization: Bearer test-gist-token', $c['h'] ?? [], true) && str_contains($r, 'angelegt'),
  'erster Abgleich legt ein geheimes Gist an (POST /gists, Bearer-Token)');
$f = $c['b']['files'] ?? [];
t(($f['vip-' . $sfx . '.txt']['content'] ?? '') === vip_text(vip_list($vip)) && ($f['vip-' . $sfx . '.names.txt']['content'] ?? '') === "Alpha Wolf\nneon fox\n" && isset($f['kofi-' . $sfx . '.txt']), 'Gist-Dateien: <slug>.txt und <slug>.names.txt je Liste');
t(vip_gist_raw_url('vip-' . $sfx . '.txt') === 'https://gist.githubusercontent.com/ghost-test/abcdef0123456789abcdef0123456789/raw/vip-' . $sfx . '.txt', 'Raw-Adresse auf gist.githubusercontent.com (ohne Commit-Kennung)');
$calls = [];
t(vip_gist_sync() === 'Gist ist aktuell.' && !$calls, 'unverändert: kein erneuter Aufruf (Hash)');
vip_entry_save($vip, ['name' => 'Gamma Ray']);
vip_list_delete($kofi);
t(setting('vip_gist_dirty') === '1', 'Änderung markiert das Gist als geändert');
vip_gist_sync();
$c = $calls[0] ?? [];
t(($c['m'] ?? '') === 'PATCH' && str_ends_with($c['u'] ?? '', '/gists/abcdef0123456789abcdef0123456789') && str_contains($c['b']['files']['vip-' . $sfx . '.txt']['content'] ?? '', 'VIP|Gamma Ray')
  && array_key_exists('kofi-' . $sfx . '.txt', $c['b']['files'] ?? []) && $c['b']['files']['kofi-' . $sfx . '.txt'] === null, 'Änderung → PATCH; Datei einer gelöschten Liste wird im Gist entfernt');
// Drosselung bei automatischem Abgleich
unset($GLOBALS['__vip_no_autosync']);
$calls = [];
set_setting('vip_gist_last', (string)time());
vip_entry_save($vip, ['name' => 'Delta']);
t(!$calls && setting('vip_gist_dirty') === '1', 'automatischer Abgleich gedrosselt (höchstens 1× pro Minute), bleibt „geändert“');
set_setting('vip_gist_last', (string)(time() - 120));
vip_entry_save($vip, ['name' => 'Epsilon']);
t(count($calls) === 1 && $calls[0]['m'] === 'PATCH' && setting('vip_gist_dirty') === '0', 'nach der Pause: Änderung wird sofort gespiegelt');
$calls = [];
t(task_run('vipgist') === 'Gist ist aktuell.' && !$calls, 'Aufgabe „vipgist“ läuft (nichts zu tun)');
$GLOBALS['__vip_no_autosync'] = true;
$db->prepare("UPDATE vip_entries SET expires = ? WHERE list_id = ? AND nkey = 'delta'")->execute([time() - 1, $vip]);
task_run('vipgist');
t(count($calls) === 1 && !str_contains($calls[0]['b']['files']['vip-' . $sfx . '.txt']['content'] ?? 'Delta', 'Delta'), 'abgelaufener Eintrag: Aufgabe aktualisiert das Gist');
unset($GLOBALS['__vip_http']);
putenv('GN_GIST_TOKEN');
set_setting('vip_gist_on', '0');

// ---------- Admin-Aktionen (Logik der Formulare) ----------
$_SESSION = [];
admin_vip_post('vip_entry_save', ['list' => $vip, 'name' => 'Admin Added', 'grp' => 'VIP', 'note' => 'x', 'expires' => '2099-12-31']);
$q->execute([$vip, 'admin added']); $ad = $q->fetch(PDO::FETCH_ASSOC);
t($ad && (int)$ad['expires'] === strtotime('2099-12-31 23:59:59') && ($_SESSION['vip_list'] ?? 0) === $vip, 'Admin: Eintrag mit Ablaufdatum hinzufügen');
$bad = false; try { admin_vip_post('vip_list_delete', ['id' => $vip, 'confirm' => 'falsch']); } catch (InvalidArgumentException $x) { $bad = true; }
t($bad && vip_list($vip), 'Admin: Liste löschen nur mit Bestätigung der Kurzadresse');
ob_start(); admin_vip_view(); $html = ob_get_clean();
t(str_contains($html, 'class="grid2"') && !preg_match('/max-width/i', $html) && str_contains($html, 'kofi.php') && str_contains($html, 'Text kopieren') && !str_contains($html, $tok), 'Admin-Ansicht: Masonry-Raster (.grid2), keine max-width, Webhook-URL, „Text kopieren“, kein Token im HTML');
$perm = (string)file_get_contents(__DIR__ . '/../../admin.php');
t(str_contains($perm, "array_fill_keys(VIP_ADMIN_ACTIONS, 'vip.manage')") && str_contains($perm, 'admin_vip_post'), 'Admin-Aktionen laufen über guard_post mit Recht vip.manage (Herkunftsprüfung wie alle Reiter)');

// ---------- Endpunkte über einen eingebauten PHP-Server ----------
file_put_contents($tmp . '/cfg.json', json_encode($cfg));
$router = $tmp . '/router.php';
file_put_contents($router, '<?php define("DATA_DIR", ' . var_export(DATA_DIR, true) . '); $c = json_decode(file_get_contents(' . var_export($tmp . '/cfg.json', true) . '), true); if ($c) $GLOBALS["__db_cfg"] = $c; chdir(' . var_export(realpath(__DIR__ . '/../..'), true) . ');'
    . ' $p = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH); if ($p === "/kofi.php") { require "kofi.php"; exit; } require "vip.php";');
$sock = stream_socket_server('tcp://127.0.0.1:0'); $port = (int)explode(':', stream_socket_get_name($sock, false))[1]; fclose($sock);
$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $router], [1 => ['file', $tmp . '/srv.log', 'a'], 2 => ['file', $tmp . '/srv.log', 'a']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
function req(string $path, ?array $post = null, array $hdr = []): array {
    global $port;
    $ch = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $hdr]);
    if ($post !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    $r = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    return ['code' => $code, 'head' => substr($r, 0, $hs), 'body' => substr($r, $hs)];
}
try {
    $r = req('/vip.php?l=vip-' . $sfx);
    t($r['code'] === 200 && stripos($r['head'], 'Content-Type: text/plain; charset=utf-8') !== false && $r['body'] === vip_text(vip_list($vip)), 'vip.php: 200, text/plain, gleicher Text wie im Admin');
    t(stripos($r['head'], 'Cache-Control: public, max-age=60') !== false && preg_match('/ETag: ("[0-9a-f]{32}")/i', $r['head'], $et), 'Cache-Header (max-age=60) und ETag');
    t(req('/vip.php?l=vip-' . $sfx, null, ['If-None-Match: ' . ($et[1] ?? '')])['code'] === 304, 'If-None-Match → 304');
    $r = req('/vip.php?l=vip-' . $sfx . '&fmt=names');
    t($r['code'] === 200 && !str_contains($r['body'], '|') && !str_contains($r['body'], '#') && str_contains($r['body'], "Alpha Wolf\n"), 'fmt=names über HTTP');
    t(req('/vip.php?l=gibtsnicht-' . $sfx)['code'] === 404, 'unbekannte Liste: 404');
    t(req('/vip.php?l=vip-' . $sfx, ['x' => 1])['code'] === 405, 'POST auf vip.php: 405');
    // Webhook über HTTP (Token aus der verschlüsselten Einstellung)
    $nk = vip_list_save(['title' => 'Kofi HTTP', 'slug' => 'kofih-' . $sfx, 'grp' => 'KOFI', 'kofi' => 1]);
    t(req('/kofi.php')['code'] === 405, 'kofi.php: GET → 405');
    t(req('/kofi.php', ['data' => $mk(['verification_token' => 'nope'])])['code'] === 401, 'kofi.php: falsches Token → 401');
    $body = $mk(['from_name' => 'Http Hanna']);
    $r1 = req('/kofi.php', ['data' => $body]);
    $r2 = req('/kofi.php', ['data' => $body]);
    $q->execute([$nk, 'http hanna']);
    t($r1['code'] === 200 && $r2['code'] === 200 && str_contains($r2['body'], 'Schon') && $q->fetch(PDO::FETCH_ASSOC), 'kofi.php: richtiges Token → 200 + Eintrag, Wiederholung → 200 „Schon verarbeitet“');
    t(req('/kofi.php', ['nodata' => 1])['code'] === 400, 'kofi.php: ohne Feld data → 400');
    t(str_contains(req('/vip.php?l=kofih-' . $sfx)['body'], 'KOFI|Http Hanna'), 'neuer Unterstützer sofort in der Liste');
    $got = false;
    for ($j = 0; $j < VIP_RATE + 5 && !$got; $j++) $got = req('/vip.php?l=vip-' . $sfx)['code'] === 429;
    t($got, 'Rate-Limit greift (429)');
} finally {
    proc_terminate($proc);
    if ($fails) echo "--- Server-Log ---\n" . @file_get_contents($tmp . '/srv.log');
}
echo ($fails ? "\n✖ $fails von $n fehlgeschlagen ($engine)\n" : "\n✔ $n Tests bestanden ($engine)\n");
exit($fails ? 1 : 0);
