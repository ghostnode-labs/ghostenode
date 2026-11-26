<?php
/**
 * Nexus Pulse – Reddit über die offizielle API (Reddit sperrt anonyme RSS/JSON-Abrufe mit 403/404).
 * <title>Nexus Pulse</title>
 *
 * App (JSON):
 *   POST nexuspulse.php?a=feed     {"sub":"VRChat"|"VRchatERP","sort":"new|hot|top","token"?}  → {"items":[…]}
 *   POST nexuspulse.php?a=comments {"sub":…,"id":"abc123","token"?}                           → {"items":[…]}
 * r/VRchatERP nur mit Nexus-Konto-Token, Verified 18+ und NSFW an.
 * Zugang: Umgebungsvariablen REDDIT_CLIENT_ID und REDDIT_CLIENT_SECRET (Reddit → prefs/apps, Typ „script“ oder „web app“),
 * optional REDDIT_USER_AGENT. Antworten werden 10 Minuten zwischengespeichert.
 */
require_once __DIR__ . '/inc/core/pages.php';
require_once __DIR__ . '/inc/modules/nexusacct.php';
require_once __DIR__ . '/inc/modules/nexuspulse.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$out = function (array $o, int $code = 200) { http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $out(['error' => 'Nur POST.'], 405);
if (nw_rate_over('pulse|' . client_ip(), 60)) $out(['error' => 'Zu viele Anfragen – kurz warten.'], 429);

try {
    $a = (string)($_GET['a'] ?? '');
    $in = json_decode((string)file_get_contents('php://input', false, null, 0, 4000), true) ?: [];
    $subKey = strtolower((string)($in['sub'] ?? 'VRChat'));
    if (!isset(NP_SUBS[$subKey])) $out(['error' => 'Unbekannter Subreddit.'], 400);
    $src = $subKey === 'vrchaterp' ? 'erp' : 'reddit';
    if ($src === 'erp') {   // 18+ nur mit Nexus-Konto (Verified 18+ und NSFW an)
        try { $acc = nx_auth((string)($in['token'] ?? '')); } catch (Throwable $e) { $out(['error' => 'Nur mit Nexus-Konto.'], 401); }
        if (!nx_nsfw_allowed($acc)) $out(['error' => 'Nicht freigeschaltet.'], 403);
    }
    if ($a === 'feed') $out(['items' => np_feed($subKey, (string)($in['sort'] ?? 'new')), 'via' => 'ghostnode']);
    if ($a === 'comments') $out(['items' => np_comments($subKey, (string)($in['id'] ?? ''))]);
    $out(['error' => 'Unbekannte Aktion.'], 400);
} catch (RuntimeException $e) {
    $out(['error' => $e->getMessage() === 'reddit_not_configured' ? 'Reddit ist auf der GhostNode-Webseite noch nicht eingerichtet.' : $e->getMessage()], $e->getMessage() === 'reddit_not_configured' ? 503 : 502);
}
