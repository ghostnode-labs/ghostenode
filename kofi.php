<?php
/**
 * Ko-fi-Webhook für die VIP-Listen (Admin → VIP-Listen). In Ko-fi unter „Webhooks“ als Webhook-URL eintragen.
 * <title>Ko-fi-Webhook</title>
 *   POST kofi.php   Formularfeld „data“ = JSON von Ko-fi (verification_token, message_id, type, from_name, message, …)
 * Das Token kommt aus GN_KOFI_TOKEN (Server-Secret) oder verschlüsselt aus dem Admin – nie aus dem Code.
 * Gespeichert wird nur der Anzeigename (+ Art/Stufe/Datum als Notiz), keine E-Mail, kein Betrag, keine Nachricht.
 */
require_once __DIR__ . '/inc/core/pages.php';
require_once __DIR__ . '/inc/modules/vip.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$out = function (int $code, string $msg, string $data = '') {
    try { if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') vip_kofi_log($code, $msg, $data); } catch (Throwable $e) { /* Protokoll ist unwichtig */ }
    http_response_code($code); exit($msg . "\n");
};
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $out(405, 'Nur POST.');
if (nw_rate_over('kofi|' . client_ip(), VIP_KOFI_RATE)) $out(429, 'Zu viele Anfragen.');
$data = vip_kofi_body($_POST, (string)file_get_contents('php://input', false, null, 0, 70000));
if ($data === '' || strlen($data) > 65536) $out(400, 'Feld data fehlt.');
try {
    [$code, $msg] = vip_kofi_handle($data);
    if ($code === 401) { audit('VIP-Liste: Ko-fi-Token falsch', '', 'security', ['id' => null, 'username' => 'ko-fi']); usleep(300000); }
    $out($code, $msg, $data);
} catch (Throwable $e) {
    error_log('kofi: ' . $e->getMessage());
    try { vip_kofi_log(500, 'Serverfehler: ' . mb_substr($e->getMessage(), 0, 60), $data); } catch (Throwable $e2) { /* egal */ }
    http_response_code(500); exit("Serverfehler.\n");
}
