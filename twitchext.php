<?php
/**
 * Twitch-Panel-Erweiterung „VRC Nexus“ – Backend (EBS). Siehe inc/modules/twitchext.php.
 * <title>Twitch-Erweiterung</title>
 *   GET  ?a=state  (Twitch-JWT)        · POST ?a=vote {poll, option} (Twitch-JWT)
 *   POST ?a=link   (JWT, broadcaster)  · GET  ?a=linked (JWT, broadcaster)
 *   POST ?a=push   {key, state}        (VRC-Nexus-App)
 */
require_once __DIR__ . '/inc/core/pages.php';
require_once __DIR__ . '/inc/modules/nexuswarn.php';
require_once __DIR__ . '/inc/modules/twitchext.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
tx_cors();
$out = function (array $o, int $code = 200) { header('Content-Type: application/json; charset=utf-8'); http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE); exit; };
$m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($m === 'OPTIONS') { http_response_code(204); exit; }
$a = (string)($_GET['a'] ?? '');
try {
    if ($a === 'push') {
        if ($m !== 'POST') $out(['error' => 'Nur POST.'], 405);
        if (nw_rate_over('txp|' . client_ip(), 30)) $out(['error' => 'Zu viele Anfragen.'], 429);
        $in = json_decode((string)file_get_contents('php://input', false, null, 0, 65536), true);
        $out(tx_push(is_array($in) ? $in : []));
    }
    if (nw_rate_over('txv|' . client_ip(), 240)) $out(['error' => 'Zu viele Anfragen.'], 429);
    $jwt = tx_jwt((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if ($a === 'state') $out(tx_state($jwt));
    if ($a === 'linked') $out(tx_link_status($jwt));
    if ($m !== 'POST') $out(['error' => 'Nur POST.'], 405);
    $in = json_decode((string)file_get_contents('php://input', false, null, 0, 4096), true);
    if ($a === 'link') $out(tx_link($jwt));
    if ($a === 'vote') $out(tx_vote($jwt, is_array($in) ? $in : []));
    $out(['error' => 'Unbekannte Aktion.'], 400);
} catch (TxError $e) {
    $out(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('twitchext: ' . $e->getMessage());
    $out(['error' => 'Serverfehler.'], 500);
}
