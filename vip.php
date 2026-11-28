<?php
/**
 * VIP-Listen für VRChat-Welten – öffentlicher Text-Endpunkt (für VRCStringDownloader / NexusVipBoard).
 * <title>VIP-Liste</title>
 *   GET vip.php?l=<slug>                 → „GRUPPE|Anzeigename“ je Zeile, # = Kommentar
 *   GET vip.php?l=<slug>&fmt=names       → nur Anzeigenamen, ein Name je Zeile (z. B. für die Url einer Keypad-Rolle)
 *   GET vip.php?l=<slug>&g=VIP           → nur eine Gruppe
 * Ausgeliefert werden nur Anzeigename + Gruppe aktiver Einträge (freigegeben, nicht abgelaufen). Verwaltung: Admin → VIP-Listen.
 */
require_once __DIR__ . '/inc/core/pages.php';
require_once __DIR__ . '/inc/modules/vip.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
$stop = function (int $code, string $msg) { http_response_code($code); header('Cache-Control: no-store'); exit('# ' . $msg . "\n"); };
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) $stop(405, 'Nur GET.');
if (nw_rate_over('vip|' . client_ip(), VIP_RATE)) { header('Retry-After: 60'); $stop(429, 'Zu viele Anfragen - kurz warten.'); }
try {
    $list = vip_list_by_slug((string)($_GET['l'] ?? ''));
    if (!$list) $stop(404, 'Liste nicht gefunden.');
    $fmt = ($_GET['fmt'] ?? '') === 'names' ? 'names' : '';
    $body = vip_text($list, $fmt, (string)($_GET['g'] ?? ''));
} catch (Throwable $e) {
    error_log('vip: ' . $e->getMessage());
    $stop(500, 'Serverfehler.');
}
$etag = '"' . substr(hash('sha256', $body), 0, 32) . '"';
header('Cache-Control: public, max-age=60');
header('ETag: ' . $etag);
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
echo $body;
