<?php
declare(strict_types=1);
/**
 * D2 Nexus: Anmelde-Vermittlung für die eingebaute Bungie-App.
 * Die App schickt den Anmelde-Code (oder ihr Erneuerungs-Token); der Server tauscht ihn bei Bungie mit dem Client-Secret ein,
 * das nur hier liegt (Umgebung GN_BUNGIE_CLIENT_SECRET oder Einstellung). Es wird nichts gespeichert oder protokolliert.
 *   POST d2auth.php  JSON { "grant_type": "authorization_code", "code": "…" } | { "grant_type": "refresh_token", "refresh_token": "…" }
 *   Kopf X-D2-Client: <Client-ID der App>
 */
require_once __DIR__ . '/inc/core/common.php';
require_once __DIR__ . '/inc/core/auth.php';   // client_ip
require_once __DIR__ . '/inc/modules/nexuswarn.php';   // nw_rate_over
require_once __DIR__ . '/inc/modules/d2.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$out = function (array $o, int $code = 200) { http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $out(['error' => 'Nur POST.'], 405);
if (nw_rate_over('d2auth|' . client_ip(), 20)) $out(['error' => 'Zu viele Anfragen – bitte kurz warten.'], 429);
$cfg = d2_oauth();
if (!$cfg) $out(['error' => 'Die Anmeldung ist auf dem Server noch nicht eingerichtet – es fehlt: ' . implode(', ', d2_oauth_missing()) . '.'], 503);
if (!hash_equals($cfg['id'], (string)($_SERVER['HTTP_X_D2_CLIENT'] ?? ''))) $out(['error' => 'Falsche App.'], 403);

$in = json_decode((string)file_get_contents('php://input', false, null, 0, 8192), true);
if (!is_array($in)) $out(['error' => 'Kein gültiges JSON.'], 400);
$grant = (string)($in['grant_type'] ?? '');
if ($grant === 'authorization_code') {
    $code = (string)($in['code'] ?? '');
    if (!preg_match('/^[A-Za-z0-9]{8,200}$/', $code)) $out(['error' => 'Ungültiger Code.'], 400);
    $form = ['grant_type' => 'authorization_code', 'code' => $code];
} elseif ($grant === 'refresh_token' && $cfg['secret'] === '') {
    $out(['error' => 'Bitte neu anmelden (die Bungie-App ist vom Typ „Public“ – für längere Anmeldung auf „Confidential“ umstellen).'], 400);
} elseif ($grant === 'refresh_token') {
    $rt = (string)($in['refresh_token'] ?? '');
    if (!preg_match('#^[A-Za-z0-9+/=._-]{8,2000}$#', $rt)) $out(['error' => 'Ungültiges Token.'], 400);
    $form = ['grant_type' => 'refresh_token', 'refresh_token' => $rt];
} else $out(['error' => 'Unbekannter grant_type.'], 400);

if (!function_exists('curl_init')) $out(['error' => 'Auf dem Server fehlt cURL.'], 500);
$ch = curl_init('https://www.bungie.net/Platform/App/OAuth/Token/');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($cfg['secret'] === '' ? $form + ['client_id' => $cfg['id']] : $form),
    CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/x-www-form-urlencoded', 'X-API-Key: ' . $cfg['key'], 'User-Agent: GhostNode-D2'],
        $cfg['secret'] === '' ? [] : ['Authorization: Basic ' . base64_encode($cfg['id'] . ':' . $cfg['secret'])])]);
$raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
$j = json_decode((string)$raw, true);
if (!is_array($j) || empty($j['access_token'])) {
    $why = is_array($j) ? (string)($j['error_description'] ?? $j['error'] ?? '') : '';
    $out(['error' => 'Bungie hat die Anmeldung abgelehnt' . ($why !== '' ? ': ' . rtrim(mb_substr($why, 0, 200), '. ') : '') . '.'], $code >= 400 && $code < 500 ? 400 : 502);
}
// nur die Felder weitergeben, die die App braucht
$out(array_intersect_key($j, array_flip(['access_token', 'token_type', 'expires_in', 'refresh_token', 'refresh_expires_in', 'membership_id'])));
