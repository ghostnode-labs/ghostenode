<?php
/**
 * 🔀 Kurz-Weiterleitungen (8.1) und 404-Seite. Wird über .htaccess (ErrorDocument 404) für alle unbekannten Pfade aufgerufen.
 * Weiterleitungen verwaltet man unter Admin → System → Domains & Weiterleitungen.
 * <title>Nicht gefunden</title>
 */
require_once __DIR__ . '/inc/core/common.php';
require_once __DIR__ . '/inc/modules/domains.php';
$uri = (string)($_SERVER['REDIRECT_URL'] ?? $_SERVER['REQUEST_URI'] ?? '/');
$qs = (string)($_SERVER['REDIRECT_QUERY_STRING'] ?? '');
$to = null;
try { $to = dom_resolve(dom_cfg(), (string)($_SERVER['HTTP_HOST'] ?? ''), $uri); } catch (Throwable $e) { $to = null; }
if ($to && $to['from'] !== null) {
    dom_hit($to['from']);
    header('Cache-Control: ' . ($to['code'] === 301 ? 'public, max-age=3600' : 'no-store'));
    header('Location: ' . $to['url'] . ($qs !== '' && strpos($to['url'], '?') === false && strpos($to['url'], 'https://') !== 0 ? '?' . $qs : ''), true, $to['code']);
    exit;
}
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NICHT GEFUNDEN · GHOST NODE</title>
<?php echo emoji_favicon('🧭'); ?>
<link rel="stylesheet" href="/assets/css/hud.css">
</head>
<body>
<div class="wrapper" style="max-width:640px;text-align:center;padding-top:60px">
    <h1>🧭 404</h1>
    <p>Diese Seite gibt es hier nicht (mehr).</p>
    <p><a class="btn btn-gold" href="/index.php">Zur Startseite</a> <a class="btn" href="/search.php">Suchen</a></p>
</div>
</body>
</html>
