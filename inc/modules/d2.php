<?php
/**
 * D2 Nexus auf der Webseite: öffentliche Destiny-2-Daten von der Bungie-API (Woche, Xûr, News) und die neueste D2-Nexus-App von GitHub.
 * Der Bungie-API-Key kommt aus der Umgebung (GN_BUNGIE_KEY) oder aus der Einstellung „d2_bungie_key“ (Admin) – nie aus dem Code.
 * Alles wird zwischengespeichert: Seitendaten 15 min, einzelne Definitionen 7 Tage, Releases 30 min.
 */
const D2_REPO = 'ghostnode-labs/d2_nexus';
const D2_XUR = 2190858386;

function d2_key(): string {
    $k = (string)getenv('GN_BUNGIE_KEY');
    if ($k === '' && function_exists('setting')) $k = setting('d2_bungie_key', '');
    return preg_match('/^[a-f0-9]{32}$/i', $k) ? $k : '';
}

/** OAuth der eingebauten D2-Nexus-App: Umgebung (GN_BUNGIE_*) vor Einstellung; null = nicht eingerichtet */
function d2_oauth(): ?array {
    $id = (string)getenv('GN_BUNGIE_CLIENT_ID'); $sec = (string)getenv('GN_BUNGIE_CLIENT_SECRET');
    if ($id === '' && function_exists('setting')) $id = setting('d2_client_id', '');
    if ($sec === '' && function_exists('setting')) $sec = setting('d2_client_secret', '');
    $key = d2_key();
    return preg_match('/^\d{1,10}$/', $id) && strlen($sec) >= 16 && $key !== '' ? ['id' => $id, 'secret' => $sec, 'key' => $key] : null;
}

/** Was fehlt für die Anmeldung? (nur Namen, keine Werte) */
function d2_oauth_missing(): array {
    $m = [];
    if (d2_key() === '') $m[] = 'Bungie-API-Key (GN_BUNGIE_KEY)';
    $id = (string)getenv('GN_BUNGIE_CLIENT_ID'); if ($id === '' && function_exists('setting')) $id = setting('d2_client_id', '');
    if (!preg_match('/^\d{1,10}$/', $id)) $m[] = 'OAuth-Client-ID (GN_BUNGIE_CLIENT_ID)';
    $sec = (string)getenv('GN_BUNGIE_CLIENT_SECRET'); if ($sec === '' && function_exists('setting')) $sec = setting('d2_client_secret', '');
    if (strlen($sec) < 16) $m[] = 'OAuth-Client-Secret (GN_BUNGIE_CLIENT_SECRET)';
    return $m;
}

function d2_cache_dir(string $sub = ''): string {
    $d = DATA_DIR . '/cache/d2' . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}

/** GET auf die Bungie-API (nur öffentliche Endpunkte, mit API-Key) */
function d2_api(string $path) {
    $key = d2_key();
    if ($key === '') throw new RuntimeException('Kein Bungie-API-Key eingetragen.');
    if (!function_exists('curl_init')) throw new RuntimeException('Auf dem Server fehlt die PHP-Erweiterung cURL.');
    $ch = curl_init('https://www.bungie.net/Platform' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['X-API-Key: ' . $key, 'User-Agent: GhostNode-D2']]);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$raw, true);
    if (!is_array($j)) throw new RuntimeException('Bungie antwortet nicht (HTTP ' . $code . ').');
    if ((int)($j['ErrorCode'] ?? 0) === 5) throw new RuntimeException('Bungie ist gerade in Wartung.');
    if ((int)($j['ErrorCode'] ?? 0) !== 1) throw new RuntimeException('Bungie-Fehler: ' . (string)($j['Message'] ?? $j['ErrorStatus'] ?? 'unbekannt'));
    return $j['Response'] ?? null;
}

/** einzelne Manifest-Definition (deutsch), 7 Tage zwischengespeichert */
function d2_def(string $type, $hash): ?array {
    if (!preg_match('/^Destiny\w+Definition$/', $type)) return null;
    $hash = (string)((int)$hash < 0 ? (int)$hash + 4294967296 : (int)$hash);
    $f = d2_cache_dir('def') . '/' . $type . '_' . $hash . '.json';
    if (is_file($f) && time() - filemtime($f) < 7 * 86400) { $d = json_decode((string)file_get_contents($f), true); return is_array($d) ? $d : null; }
    try { $d = d2_api('/Destiny2/Manifest/' . $type . '/' . $hash . '/?lc=de'); }
    catch (Throwable $e) { if (is_file($f)) { $d = json_decode((string)file_get_contents($f), true); return is_array($d) ? $d : null; } return null; }
    if (!is_array($d)) return null;
    $dp = $d['displayProperties'] ?? [];
    $slim = ['n' => (string)($dp['name'] ?? ''), 'd' => (string)($dp['description'] ?? ''), 'i' => (string)($dp['icon'] ?? ''),
        'img' => (string)($d['pgcrImage'] ?? $d['image'] ?? ''), 't' => (string)($d['itemTypeDisplayName'] ?? ''), 'tier' => (int)($d['inventory']['tierType'] ?? 0),
        'sub' => (string)($dp['subtitle'] ?? '')];
    @file_put_contents($f, json_encode($slim, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $slim;
}

/** nächster Wochen-Reset: Dienstag 17:00 UTC */
function d2_next_reset(?int $now = null): int {
    $now = $now ?? time();
    $d = gmmktime(17, 0, 0, (int)gmdate('n', $now), (int)gmdate('j', $now), (int)gmdate('Y', $now));
    $add = (2 - (int)gmdate('w', $d) + 7) % 7;
    if ($add === 0 && $now >= $d) $add = 7;
    return $d + $add * 86400;
}

/** Woche + Xûr + News, 15 Minuten zwischengespeichert */
function d2_overview(bool $force = false): array {
    $f = d2_cache_dir() . '/overview.json';
    $old = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    if (!$force && is_array($old) && time() - filemtime($f) < 900) return $old;
    if (d2_key() === '') return ['error' => 'nokey'];
    $out = ['at' => time(), 'milestones' => [], 'xur' => null, 'news' => []];
    try {
        foreach ((array)d2_api('/Destiny2/Milestones/') as $h => $m) {
            $d = d2_def('DestinyMilestoneDefinition', $h);
            if (!$d || $d['n'] === '') continue;
            $acts = [];
            foreach (array_slice((array)($m['activities'] ?? []), 0, 6) as $a) {
                $ad = d2_def('DestinyActivityDefinition', $a['activityHash'] ?? 0);
                if ($ad && $ad['n'] !== '' && !in_array($ad['n'], array_column($acts, 'n'), true)) $acts[] = $ad;
            }
            $out['milestones'][] = ['name' => $d['n'], 'desc' => $d['d'], 'icon' => $d['i'], 'image' => $d['img'] ?: ($acts[0]['img'] ?? ''), 'end' => (string)($m['endDate'] ?? ''),
                'activities' => array_map(fn($a) => $a['n'], $acts)];
            if (count($out['milestones']) >= 16) break;
        }
    } catch (Throwable $e) { $out['milestones_error'] = $e->getMessage(); }
    try {
        $v = d2_api('/Destiny2/Vendors/?components=400,402');
        $sales = $v['sales']['data'][D2_XUR]['saleItems'] ?? [];
        $vend = $v['vendors']['data'][D2_XUR] ?? null;
        if ($vend && $sales) {
            $items = [];
            foreach ($sales as $s) {
                $it = d2_def('DestinyInventoryItemDefinition', $s['itemHash'] ?? 0);
                if (!$it || $it['n'] === '') continue;
                $costs = [];
                foreach ((array)($s['costs'] ?? []) as $c) { $cd = d2_def('DestinyInventoryItemDefinition', $c['itemHash'] ?? 0); $costs[] = (int)($c['quantity'] ?? 0) . ' ' . ($cd['n'] ?? ''); }
                $items[] = ['name' => $it['n'], 'icon' => $it['i'], 'type' => $it['t'], 'exotic' => $it['tier'] === 6, 'costs' => $costs];
                if (count($items) >= 24) break;
            }
            $out['xur'] = ['refresh' => (string)($vend['nextRefreshDate'] ?? ''), 'items' => $items];
        }
    } catch (Throwable $e) { $out['xur_error'] = $e->getMessage(); }
    try {
        $n = d2_api('/Content/Rss/NewsArticles/0/?includebody=false');
        foreach (array_slice((array)($n['NewsArticles'] ?? []), 0, 8) as $a) {
            $link = (string)($a['Link'] ?? '');
            $out['news'][] = ['title' => (string)($a['Title'] ?? ''), 'date' => (string)($a['PubDate'] ?? ''), 'image' => (string)($a['ImagePath'] ?? ''), 'desc' => (string)($a['Description'] ?? ''),
                'link' => preg_match('#^https://#', $link) ? $link : 'https://www.bungie.net' . $link];
        }
    } catch (Throwable $e) { $out['news_error'] = $e->getMessage(); }
    // alles fehlgeschlagen → alten Stand behalten
    if (!$out['milestones'] && !$out['xur'] && !$out['news'] && is_array($old) && empty($old['error'])) { @touch($f); return $old; }
    @file_put_contents($f, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $out;
}

/** neueste D2-Nexus-Version von GitHub (öffentliche Releases), 30 min zwischengespeichert */
function d2_release(): ?array {
    $f = d2_cache_dir() . '/release.json';
    $old = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    if (is_array($old) && time() - filemtime($f) < 1800) return $old ?: null;
    if (!function_exists('curl_init')) return is_array($old) ? ($old ?: null) : null;
    $ch = curl_init('https://api.github.com/repos/' . D2_REPO . '/releases?per_page=10');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'User-Agent: GhostNode-D2']]);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$raw, true);
    if ($code !== 200 || !is_array($j)) { if (is_array($old)) { @touch($f); return $old ?: null; } return null; }
    $rel = [];
    foreach ($j as $r) {
        if (!empty($r['draft']) || !empty($r['prerelease']) || !preg_match('/^v(\d+\.\d+\.\d+)$/', (string)($r['tag_name'] ?? ''), $m)) continue;
        $assets = [];
        foreach ((array)($r['assets'] ?? []) as $a) $assets[] = ['name' => (string)$a['name'], 'url' => (string)$a['browser_download_url'], 'size' => (int)$a['size']];
        $rel = ['v' => $m[1], 'date' => (string)($r['published_at'] ?? ''), 'url' => (string)($r['html_url'] ?? ''), 'assets' => $assets];
        break;
    }
    @file_put_contents($f, json_encode($rel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $rel ?: null;
}
