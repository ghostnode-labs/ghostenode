<?php
/**
 * Nexus Clubs & Events – Schnittstelle für die VRC-Nexus-App (JSON, gleiches Konto-Token wie Community).
 * <title>Nexus Clubs</title>
 *   list {mine,topic,lang,q} · view {club} · create/update {…} · join {club, answers[]} · leave · manage {club, do, target}
 *   post {club,text} · post_mod {club,id,do} · gallery_add {club,url,caption} · gallery_del · event_save {club,…} · event_cancel {id}
 *   rsvp {id, status: going|maybe|no} · calendar {lang,topic,tod,days}
 * Öffentlich (GET, ohne Konto): ?ics=<club> → iCal · ?cal=1 → Kalender-JSON (ohne „nur Mitglieder“-Events)
 */
require_once __DIR__ . '/inc/core/pages.php';
require_once __DIR__ . '/inc/modules/clubs.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$out = function (array $o, int $code = 200) { header('Content-Type: application/json; charset=utf-8'); http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE); exit; };
if (nw_rate_over('cl|' . client_ip(), 120)) $out(['error' => 'Zu viele Anfragen – kurz warten.'], 429);
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        if (isset($_GET['ics'])) { $ics = cl_ics((string)$_GET['ics']); header('Content-Type: text/calendar; charset=utf-8'); header('Content-Disposition: inline; filename="club.ics"'); echo $ics; exit; }
        if (isset($_GET['cal'])) $out(cl_calendar(null, $_GET));
        $out(['error' => 'Nur POST.'], 405);
    }
    $in = json_decode((string)file_get_contents('php://input', false, null, 0, 1048576), true);
    if (!is_array($in)) $out(['error' => 'JSON erwartet.'], 400);
    $out(cl_action(nc_who($in), (string)($_GET['a'] ?? ''), $in));
} catch (NxError | NcError $e) {
    $out(['error' => $e->getMessage()], $e->getMessage() === 'Nicht angemeldet.' ? 401 : 400);
} catch (Throwable $e) {
    error_log('nexusclubs: ' . $e->getMessage());
    $out(['error' => 'Serverfehler.'], 500);
}
