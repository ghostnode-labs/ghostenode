<?php
/**
 * Sitzungs-Wächter der Webseite: fragt die Restzeit der Anmeldung ab, ohne sie zu verlängern (a=check),
 * oder verlängert sie bewusst (a=extend, nur per POST von derselben Seite). Antwort: {in: bool, left: Sekunden}
 */
require_once __DIR__ . '/inc/core/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$a = (string)($_GET['a'] ?? 'check');
if ($a === 'extend') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !same_origin_request()) { http_response_code(403); echo '{"in":false}'; exit; }
    current_user();   // zählt als Aktivität
}
$left = auth_remaining();
session_write_close();
echo json_encode(['in' => $left !== null, 'left' => (int)$left]);
