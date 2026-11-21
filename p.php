<?php
/**
 * VRChat-Poster – öffentliche Bild-Adressen und Upload-Schnittstelle.
 *
 *   GET  p.php/<slug>.png        aktuelles Bild des Slots (auch .jpg, oder p.php?s=<slug>)
 *   GET  p.php/<slug>.png?i=2    festes Bild Nr. 2 (0-basiert) aus den gerade gültigen Bildern
 *   POST p.php/<slug>            Bild hochladen per Token (Header „Authorization: Bearer ps_…“ oder Feld „token“),
 *                                Datei im Feld „file“ (z. B. ShareX/curl) oder „url“ für einen Bildlink; „activate=0“ = nicht sofort zeigen.
 */
require_once __DIR__ . '/inc/modules/posters.php';

$path = trim((string)($_SERVER['PATH_INFO'] ?? ''), '/');
$slug = strtolower($path !== '' ? (string)preg_replace('/\.(png|jpe?g)$/i', '', $path) : (string)($_GET['s'] ?? ''));
if (!ps_slug_ok($slug)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Poster nicht gefunden.'); }

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $tok = (string)($_POST['token'] ?? '');
    if (preg_match('/^Bearer\s+(\S+)$/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''), $m)) $tok = $m[1];
    $slot = $tok !== '' ? ps_token_slot($slug, $tok) : null;
    if (!$slot) { http_response_code(403); usleep(300000); exit(json_encode(['error' => 'Token ungültig.'])); }
    try {
        $meta = ['owner' => (int)$slot['owner'], 'activate' => ($_POST['activate'] ?? '1') !== '0', 'source' => 'api'];
        if (!empty($_POST['url'])) { $img = ps_add_url($slot, (string)$_POST['url'], ($_POST['live'] ?? '') === '1', $meta['activate']); $r = ['images' => [$img], 'errors' => []]; }
        elseif (!empty($_FILES)) $r = ps_upload_files($slot, reset($_FILES), $meta);
        else throw new InvalidArgumentException('Keine Datei („file“) und kein Bildlink („url“).');
        if (!$r['images']) throw new InvalidArgumentException(implode(' · ', $r['errors']) ?: 'Nichts hochgeladen.');
        ps_log((int)$slot['id'], 'Upload über Schnittstelle', count($r['images']) . ' Bild(er)');
        echo json_encode(['ok' => true, 'url' => ps_public_url(ps_slot((int)$slot['id'])), 'images' => array_map(fn($i) => (int)$i['id'], $r['images']), 'errors' => $r['errors']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

ps_serve($slug, isset($_GET['i']) && $_GET['i'] !== '' ? max(0, (int)$_GET['i']) : null);
