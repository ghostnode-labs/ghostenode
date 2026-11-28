<?php
/**
 * Nexus-Privat-Chat – Briefkasten für Ende-zu-Ende-verschlüsselte Nachrichten (X3DH + Double Ratchet in der App).
 * <title>Nexus-Chat</title>
 *
 * Der Server sieht NIE Klartext oder private Schlüssel: nur öffentliche Geräte-Schlüssel und Chiffretexte.
 * Jede Anfrage braucht das Konto-Token (nexusacct). Geräte gehören fest zu einem Konto.
 *   publish  {token, ik_dh, ik_sig, spk:{id,pub,sig}, opks:[{id,pub}], protos?, sig?}   → {device, opks, protos}
 *   devices  {token, uid}                     → Geräte (öffentliche Identitäten, protos, sig_ik, sig_bind) – für Sicherheitsnummern
 *   bundles  {token, uid, skip:[device…], sig?} → je Gerät ein Paket inkl. EINEM Einmal-Pre-Key (wird verbraucht)
 *   send     {token, device, msgs:[{to, dev, body}]}
 *   fetch    {token, device}                  → {msgs:[{id, from, from_dev, body, at}], opks, sig_opks, sig_kybers}
 * 8.3 Protokoll-Aushandlung: jedes Gerät nennt in „protos“, was es kann – „nexus-v1“ (eigene X3DH/Double-Ratchet-Umsetzung, alle Clients)
 *   und „signal-v1“ (offizielle Signal-Bibliothek libsignal mit PQXDH/Kyber, nur Desktop). publish.sig = {reg, ik, bind, spk:{id,pub,sig},
 *   kyber:{id,pub,sig} (Last-Resort), opks:[{id,pub}], kybers:[{id,pub,sig}]}; bind = Ed25519-Signatur von ik_sig über „nexus-signal-v1“‖ik.
 *   bundles mit sig=1 (Abfragender kann signal-v1): für signal-fähige Geräte nur die libsignal-Einmal-Schlüssel verbrauchen.
 *   Alte Clients schicken/lesen diese Felder nicht und arbeiten unverändert mit nexus-v1.
 *   ack      {token, device, ids:[…]}
 *   remove   {token, device}
 *   blob_put {token, data(b64, Chiffretext ≤ 7 MB), allow:[uid…]}  → {id}     (3.4: verschlüsselte Bilder; Schlüssel steckt nur in der E2E-Nachricht)
 *   blob_get {token, id}                       → Chiffretext (nur Absender und erlaubte Empfänger)
 *   turn     {token}                          → {ice:[…]} (3.7 Sprachanrufe: eigener STUN/TURN-Server, kurzlebige Zugangsdaten nach coturn „use-auth-secret“)
 * Nachrichten liegen höchstens 30 Tage und werden nach dem Abholen gelöscht; Bilder höchstens 30 Tage.
 */
require_once __DIR__ . '/inc/core/jsonguard.php'; json_guard();
require_once __DIR__ . '/inc/core/pages.php';
require_once __DIR__ . '/inc/modules/nexusacct.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=utf-8');
$out = function (array $o, int $code = 200) { json_guard_clean(); http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE); exit; };
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $out(['error' => 'Nur POST.'], 405);
$a = (string)($_GET['a'] ?? '');
if (nw_rate_over('ch|' . client_ip(), 240)) $out(['error' => 'Zu viele Anfragen – kurz warten.'], 429);
$in = json_decode((string)file_get_contents('php://input', false, null, 0, $a === 'blob_put' ? 10 * 1048576 : 1048576), true);
if (!is_array($in)) $out(['error' => 'JSON erwartet.'], 400);

function ch_db(): PDO { return db_get('vrchat'); }
function ch_key(string $b64): string { $r = base64_decode($b64, true); if ($r === false || strlen($r) !== 32) throw new NxError('Schlüssel ungültig.'); return $r; }
function ch_dev(array $acc, string $dev): array {
    $st = ch_db()->prepare('SELECT * FROM chat_devices WHERE device_id = ? AND vrc_id = ?');
    $st->execute([$dev, $acc['vrc_id']]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d) throw new NxError('Gerät nicht registriert.');
    ch_db()->prepare('UPDATE chat_devices SET last_seen = ? WHERE device_id = ?')->execute([time(), $dev]);
    return $d;
}
function ch_blob_dir(): string {
    $d = DATA_DIR . '/chatblobs';
    if (!is_dir($d)) { @mkdir($d, 0700, true); @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n"); }
    return $d;
}
function ch_blob_cleanup(PDO $db): void {
    $old = $db->prepare('SELECT id FROM chat_blobs WHERE created < ?'); $old->execute([time() - 30 * 86400]);
    foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $id) { @unlink(ch_blob_dir() . '/' . $id . '.bin'); $db->prepare('DELETE FROM chat_blobs WHERE id = ?')->execute([$id]); }
}
function ch_opks(string $dev): int { $st = ch_db()->prepare('SELECT COUNT(*) FROM chat_opks WHERE device_id = ?'); $st->execute([$dev]); return (int)$st->fetchColumn(); }
const CH_PROTOS = ['nexus-v1', 'signal-v1'];
function ch_bin(string $b64, int $min, int $max, string $what): string {
    $r = base64_decode($b64, true);
    if ($r === false || strlen($r) < $min || strlen($r) > $max) throw new NxError($what . ' ungültig.');
    return $r;
}
function ch_protos(array $d): array { $p = array_values(array_intersect(CH_PROTOS, explode(',', (string)($d['protos'] ?? '')))); return $p ?: ['nexus-v1']; }
function ch_sig_ok(array $d): bool { return in_array('signal-v1', ch_protos($d), true) && (string)($d['sig_ik'] ?? '') !== '' && (string)($d['sig_spk'] ?? '') !== '' && (string)($d['sig_kyber'] ?? '') !== ''; }
function ch_sig_counts(string $dev): array {
    $a = ch_db()->prepare('SELECT COUNT(*) FROM chat_sig_opks WHERE device_id = ?'); $a->execute([$dev]);
    $b = ch_db()->prepare('SELECT COUNT(*) FROM chat_kyber_keys WHERE device_id = ?'); $b->execute([$dev]);
    return ['sig_opks' => (int)$a->fetchColumn(), 'sig_kybers' => (int)$b->fetchColumn()];
}
/** signal-v1-Teil von publish prüfen und speichern (Bindung an ik_sig wird hier geprüft, libsignal-Signaturen prüft der Client) */
function ch_sig_publish(PDO $db, string $dev, string $iks, array $sig, array $protos): void {
    $ik = ch_bin((string)($sig['ik'] ?? ''), 33, 33, 'Signal-Identität');
    $bind = ch_bin((string)($sig['bind'] ?? ''), 64, 64, 'Signal-Bindung');
    if (!sodium_crypto_sign_verify_detached($bind, 'nexus-signal-v1' . $ik, $iks)) throw new NxError('Signal-Identität ist nicht an dieses Gerät gebunden.');
    $reg = (int)($sig['reg'] ?? 0);
    if ($reg < 1 || $reg > 16380) throw new NxError('Registrierungs-ID ungültig.');
    $spk = (array)($sig['spk'] ?? []); $ky = (array)($sig['kyber'] ?? []);
    $spkJ = json_encode(['id' => (int)($spk['id'] ?? 0), 'pub' => base64_encode(ch_bin((string)($spk['pub'] ?? ''), 33, 33, 'Signierter Pre-Key')), 'sig' => base64_encode(ch_bin((string)($spk['sig'] ?? ''), 64, 64, 'Pre-Key-Signatur'))]);
    $kyJ = json_encode(['id' => (int)($ky['id'] ?? 0), 'pub' => base64_encode(ch_bin((string)($ky['pub'] ?? ''), 1000, 2000, 'Kyber-Pre-Key')), 'sig' => base64_encode(ch_bin((string)($ky['sig'] ?? ''), 64, 64, 'Kyber-Signatur'))]);
    $db->prepare('UPDATE chat_devices SET protos = ?, reg_id = ?, sig_ik = ?, sig_bind = ?, sig_spk = ?, sig_kyber = ? WHERE device_id = ?')
        ->execute([implode(',', $protos), $reg, base64_encode($ik), base64_encode($bind), $spkJ, $kyJ, $dev]);
    // Einmal-Schlüssel mit Obergrenze je Gerät (Speicher): darüber hinaus wird still nichts mehr angenommen
    $n = ch_sig_counts($dev);
    $io = $db->prepare('INSERT OR IGNORE INTO chat_sig_opks (device_id, opk_id, pub) VALUES (?, ?, ?)');
    foreach (array_slice((array)($sig['opks'] ?? []), 0, max(0, min(100, 300 - $n['sig_opks']))) as $o) $io->execute([$dev, (int)($o['id'] ?? 0), base64_encode(ch_bin((string)($o['pub'] ?? ''), 33, 33, 'Pre-Key'))]);
    $ik2 = $db->prepare('INSERT OR IGNORE INTO chat_kyber_keys (device_id, key_id, pub, sig) VALUES (?, ?, ?, ?)');
    foreach (array_slice((array)($sig['kybers'] ?? []), 0, max(0, min(40, 60 - $n['sig_kybers']))) as $o)
        $ik2->execute([$dev, (int)($o['id'] ?? 0), base64_encode(ch_bin((string)($o['pub'] ?? ''), 1000, 2000, 'Kyber-Pre-Key')), base64_encode(ch_bin((string)($o['sig'] ?? ''), 64, 64, 'Kyber-Signatur'))]);
}
/** libsignal-Paket für ein Gerät; verbraucht je einen Einmal-EC- und Einmal-Kyber-Schlüssel (sonst Last-Resort-Kyber) */
function ch_sig_bundle(PDO $db, array $d): array {
    $dev = $d['device_id'];
    $o = $db->prepare('SELECT opk_id, pub FROM chat_sig_opks WHERE device_id = ? ORDER BY opk_id LIMIT 1'); $o->execute([$dev]);
    $opk = $o->fetch(PDO::FETCH_ASSOC);
    if ($opk) $db->prepare('DELETE FROM chat_sig_opks WHERE device_id = ? AND opk_id = ?')->execute([$dev, $opk['opk_id']]);
    $k = $db->prepare('SELECT key_id, pub, sig FROM chat_kyber_keys WHERE device_id = ? ORDER BY key_id LIMIT 1'); $k->execute([$dev]);
    $ky = $k->fetch(PDO::FETCH_ASSOC);
    if ($ky) { $db->prepare('DELETE FROM chat_kyber_keys WHERE device_id = ? AND key_id = ?')->execute([$dev, $ky['key_id']]); $ky = ['id' => (int)$ky['key_id'], 'pub' => $ky['pub'], 'sig' => $ky['sig']]; }
    else $ky = json_decode((string)$d['sig_kyber'], true);
    return ['reg' => (int)$d['reg_id'], 'ik' => $d['sig_ik'], 'bind' => $d['sig_bind'], 'spk' => json_decode((string)$d['sig_spk'], true), 'kyber' => $ky,
        'opk' => $opk ? ['id' => (int)$opk['opk_id'], 'pub' => $opk['pub']] : null];
}
function ch_devices(string $uid): array {
    $st = ch_db()->prepare('SELECT * FROM chat_devices WHERE vrc_id = ? AND last_seen > ? ORDER BY created');
    $st->execute([$uid, time() - 60 * 86400]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

try {
    $acc = nx_auth((string)($in['token'] ?? ''));
    $db = ch_db();
    if (mt_rand(1, 100) === 1) { $db->prepare('DELETE FROM chat_inbox WHERE created < ?')->execute([time() - 30 * 86400]); ch_blob_cleanup($db); }
    switch ($a) {
        case 'publish': {
            $ikd = ch_key((string)($in['ik_dh'] ?? '')); $iks = ch_key((string)($in['ik_sig'] ?? ''));
            $spk = (array)($in['spk'] ?? []);
            $sp = ch_key((string)($spk['pub'] ?? '')); $sig = base64_decode((string)($spk['sig'] ?? ''), true);
            if ($sig === false || strlen($sig) !== 64 || !sodium_crypto_sign_verify_detached($sig, 'spk' . $sp . $ikd, $iks)) throw new NxError('Signatur des Pre-Keys ungültig.');
            $dev = substr(hash('sha256', $iks), 0, 16);
            $ex = $db->prepare('SELECT vrc_id FROM chat_devices WHERE device_id = ?'); $ex->execute([$dev]);
            $owner = (string)$ex->fetchColumn();
            if ($owner !== '' && $owner !== $acc['vrc_id']) throw new NxError('Gerät gehört zu einem anderen Konto.');
            if ($owner === '') {
                if (count(ch_devices($acc['vrc_id'])) >= 10) throw new NxError('Höchstens 10 Geräte – erst ein altes entfernen.');
                $db->prepare('INSERT INTO chat_devices (device_id, vrc_id, ik_dh, ik_sig, spk_id, spk_pub, spk_sig, created, last_seen) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$dev, $acc['vrc_id'], base64_encode($ikd), base64_encode($iks), (int)($spk['id'] ?? 0), base64_encode($sp), base64_encode($sig), time(), time()]);
                nw_log($acc['vrc_id'], 'Chat-Gerät angemeldet', $acc['vrc_id'], $dev);
            } else $db->prepare('UPDATE chat_devices SET spk_id = ?, spk_pub = ?, spk_sig = ?, last_seen = ? WHERE device_id = ?')->execute([(int)($spk['id'] ?? 0), base64_encode($sp), base64_encode($sig), time(), $dev]);
            $ins = $db->prepare('INSERT OR IGNORE INTO chat_opks (device_id, opk_id, pub) VALUES (?, ?, ?)');
            foreach (array_slice((array)($in['opks'] ?? []), 0, 100) as $o) $ins->execute([$dev, (int)($o['id'] ?? 0), base64_encode(ch_key((string)($o['pub'] ?? '')))]);
            // 8.3: Protokoll-Liste + signal-v1-Schlüssel (fehlen bei alten Clients und auf Android → bleibt wie es war)
            if (isset($in['protos']) || isset($in['sig'])) {
                $protos = array_values(array_intersect(CH_PROTOS, array_map('strval', (array)($in['protos'] ?? []))));
                if (!in_array('nexus-v1', $protos, true)) array_unshift($protos, 'nexus-v1');
                $sigErr = '';
                if (is_array($in['sig'] ?? null) && in_array('signal-v1', $protos, true)) {
                    // fehlerhafter signal-v1-Teil darf die Anmeldung (nexus-v1) nicht verhindern
                    try { ch_sig_publish($db, $dev, $iks, $in['sig'], $protos); } catch (NxError $e) { $sigErr = $e->getMessage(); }
                }
                if ($sigErr !== '' || !is_array($in['sig'] ?? null)) $db->prepare('UPDATE chat_devices SET protos = ? WHERE device_id = ?')->execute([implode(',', array_diff($protos, ['signal-v1'])), $dev]);
            }
            $st = $db->prepare('SELECT * FROM chat_devices WHERE device_id = ?'); $st->execute([$dev]);
            $row = (array)$st->fetch(PDO::FETCH_ASSOC);
            $out(['device' => $dev, 'opks' => ch_opks($dev), 'protos' => ch_sig_ok($row) ? ch_protos($row) : ['nexus-v1']] + ch_sig_counts($dev) + (!empty($sigErr) ? ['sig_error' => $sigErr] : []));
        }
        case 'turn': {
            // Konfiguration NICHT im Repo: Umgebungsvariablen NEXUS_TURN_URLS / NEXUS_TURN_SECRET oder data/keys/turn.json {"urls":[…],"secret":"…"}
            $cfg = []; $f = DATA_DIR . '/keys/turn.json';
            if (is_file($f)) $cfg = (array)json_decode((string)file_get_contents($f), true);
            $urls = getenv('NEXUS_TURN_URLS') ? explode(',', (string)getenv('NEXUS_TURN_URLS')) : (array)($cfg['urls'] ?? []);
            $secret = (string)(getenv('NEXUS_TURN_SECRET') ?: ($cfg['secret'] ?? ''));
            $urls = array_values(array_filter(array_map('trim', $urls), fn($u) => preg_match('#^(stun|turns?):[\w.\-\[\]:]+(\?transport=(udp|tcp))?$#', $u)));
            $stun = array_values(array_filter($urls, fn($u) => strpos($u, 'stun:') === 0));
            $turn = array_values(array_filter($urls, fn($u) => strpos($u, 'turn') === 0));
            $ice = [];
            if ($stun) $ice[] = ['urls' => $stun];
            if ($turn && $secret !== '') {
                $user = (time() + 6 * 3600) . ':' . $acc['vrc_id'];           // 6 h gültig, an das Konto gebunden
                $ice[] = ['urls' => $turn, 'username' => $user, 'credential' => base64_encode(hash_hmac('sha1', $user, $secret, true))];
            }
            $out(['ice' => $ice]);
        }
        case 'devices': {
            $uid = strtolower((string)($in['uid'] ?? ''));
            if (!nx_uid_ok($uid)) throw new NxError('Ungültige VRChat-User-ID.');
            $own = $uid === strtolower((string)$acc['vrc_id']);   // „zuletzt aktiv“ nur für die eigenen Geräte (3.4 Geräte-Übersicht)
            $out(['devices' => array_map(fn($d) => ['device' => $d['device_id'], 'ik_dh' => $d['ik_dh'], 'ik_sig' => $d['ik_sig'], 'created' => (int)$d['created']] + ($own ? ['last_seen' => (int)$d['last_seen']] : [])
                + (ch_sig_ok($d) ? ['protos' => ch_protos($d), 'sig_ik' => $d['sig_ik'], 'sig_bind' => $d['sig_bind'], 'reg' => (int)$d['reg_id']] : ['protos' => ['nexus-v1']]), ch_devices($uid))]);
        }
        case 'bundles': {
            $uid = strtolower((string)($in['uid'] ?? ''));
            if (!nx_uid_ok($uid)) throw new NxError('Ungültige VRChat-User-ID.');
            if (!nx_account($uid)) throw new NxError('Diese Person hat (noch) kein Nexus-Konto.');
            $skip = array_map('strval', (array)($in['skip'] ?? []));
            $wantSig = !empty($in['sig']);   // Abfragender kann signal-v1
            $res = [];
            foreach (ch_devices($uid) as $d) {
                if (in_array($d['device_id'], $skip, true)) continue;
                $useSig = $wantSig && ch_sig_ok($d);
                $opk = false;
                if (!$useSig) {   // nexus-v1-Einmal-Schlüssel nur verbrauchen, wenn er gebraucht wird
                    $o = $db->prepare('SELECT opk_id, pub FROM chat_opks WHERE device_id = ? ORDER BY opk_id LIMIT 1'); $o->execute([$d['device_id']]);
                    $opk = $o->fetch(PDO::FETCH_ASSOC);
                    if ($opk) $db->prepare('DELETE FROM chat_opks WHERE device_id = ? AND opk_id = ?')->execute([$d['device_id'], $opk['opk_id']]);
                }
                $res[] = ['device' => $d['device_id'], 'ik_dh' => $d['ik_dh'], 'ik_sig' => $d['ik_sig'], 'spk' => ['id' => (int)$d['spk_id'], 'pub' => $d['spk_pub'], 'sig' => $d['spk_sig']],
                    'opk' => $opk ? ['id' => (int)$opk['opk_id'], 'pub' => $opk['pub']] : null, 'protos' => ch_protos($d)] + ($useSig ? ['sig' => ch_sig_bundle($db, $d)] : []);
            }
            $out(['bundles' => $res]);
        }
        case 'send': {
            $from = ch_dev($acc, (string)($in['device'] ?? ''));
            $msgs = (array)($in['msgs'] ?? []);
            if (!$msgs || count($msgs) > 50) throw new NxError('1 bis 50 Nachrichten je Aufruf.');
            $n = $db->prepare('SELECT COUNT(*) FROM chat_inbox WHERE from_uid = ? AND created > ?'); $n->execute([$acc['vrc_id'], time() - 3600]);
            if ((int)$n->fetchColumn() > 3000) throw new NxError('Zu viele Nachrichten – kurz Pause.');
            $chk = $db->prepare('SELECT 1 FROM chat_devices WHERE device_id = ? AND vrc_id = ?');
            $ins = $db->prepare('INSERT INTO chat_inbox (to_device, to_uid, from_uid, from_device, body, created) VALUES (?, ?, ?, ?, ?, ?)');
            $ok = 0;
            foreach ($msgs as $m) {
                $to = strtolower((string)($m['to'] ?? '')); $dev = (string)($m['dev'] ?? ''); $body = (string)($m['body'] ?? '');
                if (!nx_uid_ok($to) || $body === '' || strlen($body) > 30000) continue;
                $chk->execute([$dev, $to]);
                if (!$chk->fetchColumn()) continue;
                $ins->execute([$dev, $to, $acc['vrc_id'], $from['device_id'], $body, time()]);
                $ok++;
            }
            $out(['ok' => $ok]);
        }
        case 'fetch': {
            $d = ch_dev($acc, (string)($in['device'] ?? ''));
            $st = $db->prepare('SELECT id, from_uid, from_device, body, created FROM chat_inbox WHERE to_device = ? ORDER BY id LIMIT 200');
            $st->execute([$d['device_id']]);
            $out(['msgs' => array_map(fn($m) => ['id' => (int)$m['id'], 'from' => $m['from_uid'], 'from_dev' => $m['from_device'], 'body' => $m['body'], 'at' => (int)$m['created']], $st->fetchAll(PDO::FETCH_ASSOC)), 'opks' => ch_opks($d['device_id'])] + ch_sig_counts($d['device_id']));
        }
        case 'ack': {
            $d = ch_dev($acc, (string)($in['device'] ?? ''));
            $del = $db->prepare('DELETE FROM chat_inbox WHERE id = ? AND to_device = ?');
            foreach (array_slice((array)($in['ids'] ?? []), 0, 500) as $id) $del->execute([(int)$id, $d['device_id']]);
            $out(['ok' => true]);
        }
        case 'blob_put': {
            $bin = base64_decode((string)($in['data'] ?? ''), true);
            if ($bin === false || strlen($bin) < 32 || strlen($bin) > 7 * 1048576) throw new NxError('Bild fehlt oder ist zu groß (max. 5 MB).');
            $allow = [];
            foreach (array_slice((array)($in['allow'] ?? []), 0, 60) as $u) { $u = strtolower((string)$u); if (nx_uid_ok($u)) $allow[$u] = true; }
            $allow[strtolower($acc['vrc_id'])] = true;
            $n = $db->prepare('SELECT COALESCE(SUM(size), 0) FROM chat_blobs WHERE owner = ? AND created > ?'); $n->execute([$acc['vrc_id'], time() - 86400]);
            if ((int)$n->fetchColumn() + strlen($bin) > 200 * 1048576) throw new NxError('Tageslimit für Bilder erreicht (200 MB).');
            $id = bin2hex(random_bytes(16));
            if (@file_put_contents(ch_blob_dir() . '/' . $id . '.bin', $bin) === false) throw new NxError('Speichern fehlgeschlagen.');
            $db->prepare('INSERT INTO chat_blobs (id, owner, allow, size, created) VALUES (?, ?, ?, ?, ?)')->execute([$id, $acc['vrc_id'], implode(',', array_keys($allow)), strlen($bin), time()]);
            $out(['id' => $id]);
        }
        case 'blob_get': {
            $id = (string)($in['id'] ?? '');
            if (!preg_match('/^[0-9a-f]{32}$/', $id)) throw new NxError('Unbekanntes Bild.');
            $st = $db->prepare('SELECT allow FROM chat_blobs WHERE id = ?'); $st->execute([$id]);
            $allow = $st->fetchColumn();
            if ($allow === false || !in_array(strtolower($acc['vrc_id']), explode(',', (string)$allow), true)) throw new NxError('Bild nicht gefunden oder abgelaufen.');
            $bin = @file_get_contents(ch_blob_dir() . '/' . $id . '.bin');
            if ($bin === false) throw new NxError('Bild nicht gefunden oder abgelaufen.');
            header('Content-Type: application/octet-stream'); header('Content-Length: ' . strlen($bin)); echo $bin; exit;
        }
        case 'remove': {
            $dev = (string)($in['device'] ?? '');
            $own = $db->prepare('SELECT 1 FROM chat_devices WHERE device_id = ? AND vrc_id = ?'); $own->execute([$dev, $acc['vrc_id']]);
            if (!$own->fetchColumn()) throw new NxError('Gerät nicht gefunden.');
            foreach (['chat_opks WHERE device_id', 'chat_sig_opks WHERE device_id', 'chat_kyber_keys WHERE device_id', 'chat_inbox WHERE to_device', 'chat_devices WHERE device_id'] as $t) $db->prepare('DELETE FROM ' . $t . ' = ?')->execute([$dev]);
            nw_log($acc['vrc_id'], 'Chat-Gerät entfernt', $acc['vrc_id'], $dev);
            $out(['ok' => true]);
        }
    }
    $out(['error' => 'Unbekannte Aktion.'], 400);
} catch (NxError $e) {
    $out(['error' => $e->getMessage()], $e->getMessage() === 'Nicht angemeldet.' ? 401 : 400);
} catch (Throwable $e) {
    error_log('nexuschat: ' . $e->getMessage());
    $out(['error' => 'Serverfehler.'], 500);
}
