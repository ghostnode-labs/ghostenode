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

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$out = function (array $o, int $code = 200) { http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $out(['error' => 'Nur POST.'], 405);
if (nw_rate_over('pulse|' . client_ip(), 60)) $out(['error' => 'Zu viele Anfragen – kurz warten.'], 429);

const NP_SUBS = ['vrchat' => 'VRChat', 'vrchaterp' => 'VRchatERP'];
const NP_TTL = 600;

function np_cfg(): array {
    return ['id' => (string)getenv('REDDIT_CLIENT_ID'), 'secret' => (string)getenv('REDDIT_CLIENT_SECRET'),
            'ua' => (string)(getenv('REDDIT_USER_AGENT') ?: 'web:dev.ghostnode-labs.nexus-pulse:1.0 (by GhostNode)')];
}
function np_http(string $url, array $opt = []): array {
    $c = np_cfg();
    $ch = curl_init($url);
    $h = ['User-Agent: ' . $c['ua'], 'Accept: application/json'];
    if (!empty($opt['bearer'])) $h[] = 'Authorization: Bearer ' . $opt['bearer'];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $h, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
    if (isset($opt['post'])) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['post'])); curl_setopt($ch, CURLOPT_USERPWD, $c['id'] . ':' . $c['secret']); }
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, json_decode((string)$raw, true)];
}
/** App-Token (client_credentials), zwischengespeichert bis kurz vor Ablauf */
function np_token(): string {
    $c = np_cfg();
    if ($c['id'] === '' || $c['secret'] === '') throw new RuntimeException('reddit_not_configured');
    $t = json_decode(setting('np_reddit_tok', '{}'), true) ?: [];
    if (!empty($t['tok']) && (int)($t['exp'] ?? 0) > time() + 60) return (string)$t['tok'];
    [$code, $j] = np_http('https://www.reddit.com/api/v1/access_token', ['post' => ['grant_type' => 'client_credentials']]);
    if ($code !== 200 || empty($j['access_token'])) throw new RuntimeException('Reddit-Anmeldung fehlgeschlagen (' . $code . ').');
    set_setting('np_reddit_tok', json_encode(['tok' => $j['access_token'], 'exp' => time() + (int)($j['expires_in'] ?? 3600)]));
    return (string)$j['access_token'];
}
function np_cached(string $key, callable $fn): array {
    $dir = DATA_DIR . '/cache'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $f = $dir . '/pulse_' . preg_replace('/[^a-z0-9_]/', '', strtolower($key)) . '.json';
    if (is_file($f) && time() - filemtime($f) < NP_TTL) { $j = json_decode((string)file_get_contents($f), true); if (is_array($j)) return $j; }
    $v = $fn();
    @file_put_contents($f, json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $v;
}
function np_https(?string $u): string { $u = html_entity_decode((string)$u, ENT_QUOTES); return preg_match('#^https://[^\s"<>]{4,600}$#', $u) ? $u : ''; }
function np_post(array $d, string $src): array {
    $img = '';
    if (!empty($d['preview']['images'][0]['resolutions'])) { $r = $d['preview']['images'][0]['resolutions']; $img = np_https(($r[min(2, count($r) - 1)] ?? [])['url'] ?? ''); }
    if ($img === '' && preg_match('#^https://#', (string)($d['thumbnail'] ?? ''))) $img = np_https($d['thumbnail']);
    return ['id' => 'reddit:t3_' . preg_replace('/[^a-z0-9]/', '', (string)$d['id']), 'src' => $src, 'kind' => $src, 'title' => mb_substr((string)($d['title'] ?? ''), 0, 300),
        'url' => 'https://www.reddit.com' . (string)($d['permalink'] ?? ''), 'author' => mb_substr((string)($d['author'] ?? ''), 0, 60), 'ts' => (int)($d['created_utc'] ?? 0),
        'text' => mb_substr(trim((string)($d['selftext'] ?? '')), 0, 400), 'img' => $img, 'comments' => 1, 'nsfw' => !empty($d['over_18'])];
}

try {
    $a = (string)($_GET['a'] ?? '');
    $in = json_decode((string)file_get_contents('php://input', false, null, 0, 4000), true) ?: [];
    $subKey = strtolower((string)($in['sub'] ?? 'VRChat'));
    if (!isset(NP_SUBS[$subKey])) $out(['error' => 'Unbekannter Subreddit.'], 400);
    $sub = NP_SUBS[$subKey];
    $src = $subKey === 'vrchaterp' ? 'erp' : 'reddit';
    if ($src === 'erp') {   // 18+ nur mit Nexus-Konto (Verified 18+ und NSFW an)
        try { $acc = nx_auth((string)($in['token'] ?? '')); } catch (Throwable $e) { $out(['error' => 'Nur mit Nexus-Konto.'], 401); }
        if (!nx_nsfw_allowed($acc)) $out(['error' => 'Nicht freigeschaltet.'], 403);
    }
    if ($a === 'feed') {
        $sort = in_array($in['sort'] ?? '', ['hot', 'top'], true) ? (string)$in['sort'] : 'new';
        $items = np_cached($sub . '_' . $sort, function () use ($sub, $sort, $src) {
            [$code, $j] = np_http('https://oauth.reddit.com/r/' . $sub . '/' . $sort . '?limit=30&raw_json=1' . ($sort === 'top' ? '&t=week' : ''), ['bearer' => np_token()]);
            if ($code !== 200 || !isset($j['data']['children'])) throw new RuntimeException('Reddit antwortet mit HTTP ' . $code . '.');
            $o = [];
            foreach ($j['data']['children'] as $c) if (($c['kind'] ?? '') === 't3') $o[] = np_post($c['data'], $src);
            return $o;
        });
        $out(['items' => $items, 'via' => 'ghostnode']);
    }
    if ($a === 'comments') {
        $id = preg_replace('/[^a-z0-9]/', '', strtolower((string)($in['id'] ?? '')));
        if ($id === '' || strlen($id) > 12) $out(['error' => 'Ungültiger Beitrag.'], 400);
        $items = np_cached($sub . '_c_' . $id, function () use ($sub, $id) {
            [$code, $j] = np_http('https://oauth.reddit.com/r/' . $sub . '/comments/' . $id . '?limit=40&depth=1&raw_json=1', ['bearer' => np_token()]);
            if ($code !== 200 || !isset($j[1]['data']['children'])) throw new RuntimeException('Reddit antwortet mit HTTP ' . $code . '.');
            $o = [];
            foreach ($j[1]['data']['children'] as $c) {
                if (($c['kind'] ?? '') !== 't1') continue;
                $d = $c['data'];
                $o[] = ['author' => mb_substr((string)($d['author'] ?? ''), 0, 60), 'ts' => (int)($d['created_utc'] ?? 0), 'text' => mb_substr((string)($d['body'] ?? ''), 0, 1500), 'url' => 'https://www.reddit.com' . (string)($d['permalink'] ?? '')];
                if (count($o) >= 40) break;
            }
            return $o;
        });
        $out(['items' => $items]);
    }
    $out(['error' => 'Unbekannte Aktion.'], 400);
} catch (RuntimeException $e) {
    $out(['error' => $e->getMessage() === 'reddit_not_configured' ? 'Reddit ist auf der GhostNode-Webseite noch nicht eingerichtet.' : $e->getMessage()], $e->getMessage() === 'reddit_not_configured' ? 503 : 502);
}
