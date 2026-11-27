<?php
/**
 * Briefkasten für die Spielzeug-Fernsteuerung der VRC-Nexus-App (Nexus ↔ Nexus über die User-ID).
 * Seit 3.1.1 (NSFW-Overhaul, Spez. 7 „jede NSFW-Anfrage serverseitig prüfen“): Senden und Abholen nur mit Nexus-Konto-Token,
 * das Konto muss Verified 18+ mit eingeschaltetem NSFW sein; abholen darf man nur den eigenen Briefkasten.
 * Zusätzlich prüft die App wie bisher (Freigabeliste, Gegenseite Verified 18+).
 *
 *   POST toyrelay.php?a=send  {"token", "to": "<64 hex>", "msg": "<Text, max. 6000 Zeichen>"}   → {"ok":true}
 *   POST toyrelay.php?a=poll  {"token", "to": "<64 hex>", "key": "<Briefkasten-Schlüssel>"}      → {"msgs":[…]}
 *
 * „to“ ist ein Hash der VRChat-User-ID (die ID selbst steht nirgends). Lesen darf nur, wer beim ersten Abholen
 * den Briefkasten-Schlüssel gesetzt hat. Nachrichten verfallen nach 90 s. Keine Sitzung, keine Cookies, keine Datenbank.
 * <title>Nexus Toy-Relay</title>
 */
require_once __DIR__ . '/inc/core/jsonguard.php'; json_guard();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const TR_TTL = 90;
const TR_MAX_MSGS = 50;
const TR_MAX_LEN = 6000;
/** Dateien enden auf .php und beginnen hiermit – auch ohne .htaccess (z. B. nginx) direkt aufgerufen nur leer */
const TR_GUARD = "<?php http_response_code(404); exit; ?>\n";
const TR_RATE = 300;          // Anfragen pro IP und Minute (Abholen 1×/s + Regler)

function tr_out(array $o, int $code = 200): void { json_guard_clean(); http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE); exit; }
function tr_dir(): string {
    $d = __DIR__ . '/data/toyrelay';
    if (!is_dir($d)) { @mkdir($d, 0700, true); @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n"); }
    return $d;
}
/** Datei gesperrt öffnen, $fn darf den Inhalt ändern */
function tr_with(string $file, callable $fn) {
    $h = fopen($file, 'c+');
    if (!$h) tr_out(['error' => 'Speicher nicht beschreibbar (Ordner data/).'], 500);
    flock($h, LOCK_EX);
    $raw = (string)stream_get_contents($h);
    if (strpos($raw, TR_GUARD) === 0) $raw = substr($raw, strlen(TR_GUARD));
    $d = $raw ? (json_decode($raw, true) ?: []) : [];
    $res = $fn($d);
    ftruncate($h, 0); rewind($h); fwrite($h, TR_GUARD . json_encode($d)); fflush($h);
    flock($h, LOCK_UN); fclose($h);
    return $res;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') tr_out(['error' => 'Nur POST.'], 405);
$dir = tr_dir();

// Bremse pro IP
$ip = hash('sha256', 'tr|' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
$over = tr_with($dir . '/_rate_' . substr($ip, 0, 16) . '.php', function (array &$d) {
    $m = (int)floor(time() / 60);
    if (($d['m'] ?? 0) !== $m) $d = ['m' => $m, 'n' => 0];
    return ++$d['n'] > TR_RATE;
});
if ($over) tr_out(['error' => 'Zu viele Anfragen – kurz warten.'], 429);

$in = json_decode((string)file_get_contents('php://input', false, null, 0, 16000), true);
if (!is_array($in)) tr_out(['error' => 'JSON erwartet.'], 400);
$to = strtolower((string)($in['to'] ?? ''));
if (!preg_match('/^[0-9a-f]{64}$/', $to)) tr_out(['error' => 'Empfänger ungültig.'], 400);
$box = $dir . '/' . $to . '.php';
$a = (string)($_GET['a'] ?? '');

// Nexus-Konto: Verified 18+ und NSFW an – sonst keine Fernsteuerung über diese Webseite
require_once __DIR__ . '/inc/core/pages.php';
require_once __DIR__ . '/inc/modules/nexusacct.php';
try { $acc = nx_auth((string)($in['token'] ?? '')); }
catch (Throwable $e) { tr_out(['error' => 'Fernsteuerung nur mit Nexus-Konto (Verified 18+, NSFW an) – bitte VRC Nexus aktualisieren und das Konto verbinden.'], 401); }
if (!nx_nsfw_allowed($acc)) tr_out(['error' => 'Fernsteuerung nur mit „Verified 18+“ und eingeschaltetem NSFW.'], 403);
if ($a === 'poll' && !hash_equals(hash('sha256', 'vrcnx-toy|' . strtolower((string)$acc['vrc_id'])), $to)) tr_out(['error' => 'Nur der eigene Briefkasten.'], 403);

// ab und zu aufräumen (verwaiste Briefkästen nach 30 Tagen, Bremsen nach 1 Tag)
if (mt_rand(1, 200) === 1) foreach (glob($dir . '/*.php') ?: [] as $f) {
    $age = time() - (int)@filemtime($f);
    if ((strpos(basename($f), '_rate_') === 0 && $age > 86400) || $age > 30 * 86400) @unlink($f);
}

if ($a === 'send') {
    $msg = (string)($in['msg'] ?? '');
    if ($msg === '' || strlen($msg) > TR_MAX_LEN) tr_out(['error' => 'Nachricht leer oder zu lang.'], 400);
    if (!is_file($box)) tr_out(['error' => 'Empfänger hat die Fernsteuerung über diese Webseite nicht an.'], 404);
    tr_with($box, function (array &$d) use ($msg) {
        $d['msgs'] = array_values(array_filter($d['msgs'] ?? [], fn($x) => ($x['t'] ?? 0) > time() - TR_TTL));
        $d['msgs'][] = ['t' => time(), 'm' => $msg];
        $d['msgs'] = array_slice($d['msgs'], -TR_MAX_MSGS);
    });
    tr_out(['ok' => true]);
}

if ($a === 'poll') {
    $key = (string)($in['key'] ?? '');
    if (strlen($key) < 32 || strlen($key) > 200) tr_out(['error' => 'Briefkasten-Schlüssel ungültig.'], 400);
    $res = tr_with($box, function (array &$d) use ($key) {
        $kh = hash('sha256', $key);
        if (empty($d['key'])) $d['key'] = $kh;                    // erster Abruf legt den Briefkasten an
        if (!hash_equals($d['key'], $kh)) return null;
        $out = array_values(array_map(fn($x) => $x['m'], array_filter($d['msgs'] ?? [], fn($x) => ($x['t'] ?? 0) > time() - TR_TTL)));
        $d['msgs'] = [];
        $d['seen'] = time();
        return $out;
    });
    if ($res === null) tr_out(['error' => 'Dieser Briefkasten gehört einer anderen App-Installation.'], 403);
    tr_out(['msgs' => $res]);
}

tr_out(['error' => 'Unbekannte Aktion.'], 400);
