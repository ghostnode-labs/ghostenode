<?php
/**
 * Nexus Steckbriefe & Creator – Schnittstelle für die VRC-Nexus-App (JSON, gleiches Konto-Token wie Community/Clubs).
 * <title>Nexus Steckbriefe</title>
 *   POST ?a=list|get|creator|save|review|tag|creator_save|queue|queue_decide  {token, …}
 *   GET  ?a=list|get|creator&…  → öffentlich lesen (ohne Konto)
 */
require_once __DIR__ . '/inc/core/pages.php';
require_once __DIR__ . '/inc/modules/wiki.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$out = function (array $o, int $code = 200) { header('Content-Type: application/json; charset=utf-8'); http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
if (nw_rate_over('sb|' . client_ip(), 150)) $out(['error' => 'Zu viele Anfragen – kurz warten.'], 429);
$a = (string)($_GET['a'] ?? '');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        if (!in_array($a, ['list', 'get', 'creator'], true)) $out(['error' => 'Nur lesen.'], 405);
        $out(sb_action(null, $a, $_GET));
    }
    $in = json_decode((string)file_get_contents('php://input', false, null, 0, 262144), true);
    if (!is_array($in)) $out(['error' => 'JSON erwartet.'], 400);
    $out(sb_action(nc_who($in), $a, $in));
} catch (NxError | NcError $e) {
    $out(['error' => $e->getMessage()], $e->getMessage() === 'Nicht angemeldet.' ? 401 : 400);
} catch (Throwable $e) {
    error_log('nexuswiki: ' . $e->getMessage());
    $out(['error' => 'Serverfehler.'], 500);
}
