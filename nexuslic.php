<?php
/**
 * Lizenzen 2.0 – Schnittstelle für VRC Nexus (JSON, ohne Sitzung/Cookies).
 * <title>Nexus-Lizenzen</title>
 *   POST nexuslic.php?a=revoked                     → {"ids":[…]}                 (öffentlich: gesperrte Schlüssel-IDs)
 *   POST nexuslic.php?a=check   {"token","version"} → {"valid":bool,"reason":"…"}
 *   POST nexuslic.php?a=sync    {"key","entries"}   → {"count":n,"revoked":[…]}   (nur Besitzerin, mit eigenem kurzlebigen Schlüssel)
 */
require_once __DIR__ . '/inc/core/jsonguard.php'; json_guard();
require_once __DIR__ . '/inc/core/pages.php';
require_once __DIR__ . '/inc/modules/nexuslic.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$out = function (array $o, int $code = 200) { json_guard_clean(); http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $out(['error' => 'Nur POST.'], 405);
$a = (string)($_GET['a'] ?? '');
if (nw_rate_over('lic|' . client_ip(), $a === 'sync' ? 20 : 120)) $out(['error' => 'Zu viele Anfragen – kurz warten.'], 429);
$in = json_decode((string)file_get_contents('php://input', false, null, 0, 2 * 1048576), true);
if (!is_array($in)) $in = [];
try {
    if ($a === 'revoked') $out(['ids' => nl_revoked(), 'at' => time()]);
    if ($a === 'check') $out(nl_check((string)($in['token'] ?? ''), (string)($in['version'] ?? '')));
    if ($a === 'sync') $out(nl_sync((string)($in['key'] ?? ''), is_array($in['entries'] ?? null) ? $in['entries'] : []));
    $out(['error' => 'Unbekannte Aktion.'], 400);
} catch (NwError $e) {
    $out(['error' => $e->getMessage()], 403);
} catch (Throwable $e) {
    error_log('nexuslic: ' . $e->getMessage());
    $out(['error' => 'Serverfehler.'], 500);
}
