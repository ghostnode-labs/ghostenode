<?php
/**
 * Nexus Pulse – gemeinsame Funktionen (App-Schnittstelle nexuspulse.php und Nexus Web):
 * Reddit über die offizielle API (Umgebungsvariablen REDDIT_CLIENT_ID / REDDIT_CLIENT_SECRET, optional REDDIT_USER_AGENT)
 * und VRChat-News von Steam. Antworten werden 10 Minuten zwischengespeichert.
 */
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

/** Reddit-Beiträge eines erlaubten Subreddits ($sub = Schlüssel aus NP_SUBS) */
function np_feed(string $subKey, string $sort): array {
    $sub = NP_SUBS[$subKey] ?? 'VRChat'; $src = $subKey === 'vrchaterp' ? 'erp' : 'reddit';
    $sort = in_array($sort, ['hot', 'top'], true) ? $sort : 'new';
    return np_cached($sub . '_' . $sort, function () use ($sub, $sort, $src) {
        [$code, $j] = np_http('https://oauth.reddit.com/r/' . $sub . '/' . $sort . '?limit=30&raw_json=1' . ($sort === 'top' ? '&t=week' : ''), ['bearer' => np_token()]);
        if ($code !== 200 || !isset($j['data']['children'])) throw new RuntimeException('Reddit antwortet mit HTTP ' . $code . '.');
        $o = [];
        foreach ($j['data']['children'] as $c) if (($c['kind'] ?? '') === 't3') $o[] = np_post($c['data'], $src);
        return $o;
    });
}
function np_comments(string $subKey, string $id): array {
    $sub = NP_SUBS[$subKey] ?? 'VRChat';
    $id = preg_replace('/[^a-z0-9]/', '', strtolower($id));
    if ($id === '' || strlen($id) > 12) throw new RuntimeException('Ungültiger Beitrag.');
    return np_cached($sub . '_c_' . $id, function () use ($sub, $id) {
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
}
/** Offizielle VRChat-News (Steam, App 438100) mit Kategorie */
function np_kind(string $t): string {
    if (preg_match('/security|sicherheit|scam|phishing|exploit|malware|vulnerab|account (theft|safety)/i', $t)) return 'security';
    if (preg_match('/dev(eloper)? update|dev blog/i', $t)) return 'dev';
    if (preg_match('/creator|economy|marketplace|vrchat\+|credits|store/i', $t)) return 'creator';
    if (preg_match('/patch|release|hotfix|build \d|^\d{4}\.\d|open beta|update \d/i', $t)) return 'patch';
    return 'news';
}
function np_steam(): array {
    return np_cached('steam_vrchat', function () {
        $ch = curl_init('https://api.steampowered.com/ISteamNews/GetNewsForApp/v2/?appid=438100&count=30&maxlength=1200&format=json');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => ['User-Agent: ' . np_cfg()['ua']]]);
        $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $j = json_decode((string)$raw, true);
        if ($code !== 200 || !isset($j['appnews']['newsitems'])) throw new RuntimeException('Steam antwortet mit HTTP ' . $code . '.');
        $o = [];
        foreach ($j['appnews']['newsitems'] as $n) {
            $raw = (string)($n['contents'] ?? '');
            preg_match('/\[img\](.*?)\[\/img\]/i', $raw, $m);
            $img = str_replace('{STEAM_CLAN_IMAGE}', 'https://clan.akamai.steamstatic.com/images', (string)($m[1] ?? ''));
            $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(preg_replace(['/\[img\].*?\[\/img\]/is', '/\[\/?[a-z0-9*]+(=[^\]]*)?\]/i'], ' ', $raw)), ENT_QUOTES)));
            $url = np_https((string)($n['url'] ?? ''));
            if ($url === '') continue;
            $o[] = ['id' => 'steam:' . preg_replace('/[^0-9]/', '', (string)($n['gid'] ?? '')), 'src' => 'vrchat', 'kind' => np_kind((string)($n['title'] ?? '')), 'title' => mb_substr(trim(strip_tags((string)($n['title'] ?? ''))), 0, 200),
                'url' => $url, 'author' => mb_substr((string)($n['author'] ?? 'VRChat') ?: 'VRChat', 0, 60), 'ts' => (int)($n['date'] ?? 0), 'text' => mb_substr($text, 0, 400), 'img' => np_https($img), 'comments' => 0];
        }
        return $o;
    });
}
