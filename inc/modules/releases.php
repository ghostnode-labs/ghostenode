<?php
/**
 * 9.0: Release-Status aller GhostNode-Projekte (VRC Nexus, D2 Nexus, Discord-Bot) aus den öffentlichen GitHub-Releases.
 * Benutzt von: Widget „Release-Status“, Downloads-Seite („Weitere Projekte“), Benachrichtigungen (Mein Bereich).
 * Zwischenspeicher 30 min in DATA_DIR/cache/releases_<repo>.json; ist GitHub weg, bleibt der alte Stand.
 */
require_once APP_ROOT . '/inc/core/common.php';

const REL_TTL = 1800;
/** id => [Icon, Name, Repo, Tag-Muster (Regex mit Gruppe für die Version), Seite] */
const REL_PROJECTS = [
    'vrc' => ['🥽', 'VRC Nexus', 'ghostnode-labs/vrc_nexus', '/^vrc-nexus-v(\d+\.\d+\.\d+)$/', 'changelog.php'],
    'd2'  => ['💠', 'D2 Nexus', 'ghostnode-labs/d2_nexus', '/^v(\d+\.\d+\.\d+)$/', 'd2nexus.php'],
    'bot' => ['🤖', 'Discord-Bot', 'ghostnode-labs/node_discord', '/^v(\d+\.\d+\.\d+)$/', 'botdash.php'],
];

/** Rohdaten von GitHub → Liste [v, date, url, body, assets[], pre] (neueste zuerst). $fetch(url) → [code, body] für Tests. */
function rel_parse(array $json, string $pattern): array {
    $out = [];
    foreach ($json as $r) {
        if (!is_array($r) || !empty($r['draft']) || !preg_match($pattern, (string)($r['tag_name'] ?? ''), $m)) continue;
        $assets = [];
        foreach ((array)($r['assets'] ?? []) as $a) {
            if (!preg_match('#^https://github\.com/#', (string)($a['browser_download_url'] ?? ''))) continue;
            $assets[] = ['name' => (string)$a['name'], 'url' => (string)$a['browser_download_url'], 'size' => (int)($a['size'] ?? 0), 'downloads' => (int)($a['download_count'] ?? 0),
                         'sha256' => preg_match('/^sha256:([0-9a-f]{64})$/', (string)($a['digest'] ?? ''), $dm) ? $dm[1] : ''];
        }
        $out[] = ['v' => $m[1], 'date' => (string)($r['published_at'] ?? ''), 'url' => (string)($r['html_url'] ?? ''), 'body' => (string)($r['body'] ?? ''), 'assets' => $assets, 'pre' => !empty($r['prerelease'])];
    }
    usort($out, fn($a, $b) => version_compare($b['v'], $a['v']));
    return $out;
}

function rel_fetch(string $id, ?callable $fetch = null): array {
    $p = REL_PROJECTS[$id] ?? null;
    if (!$p) return [];
    $f = data_path('cache/releases_' . $id . '.json');
    $old = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    if (!$fetch && is_array($old) && time() - filemtime($f) < REL_TTL) return $old;
    $url = 'https://api.github.com/repos/' . $p[2] . '/releases?per_page=30';
    if ($fetch) [$code, $raw] = $fetch($url);
    else {
        if (!function_exists('curl_init')) return is_array($old) ? $old : [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'User-Agent: GhostNode-Releases']]);
        $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    }
    $j = json_decode((string)$raw, true);
    if ((int)$code !== 200 || !is_array($j)) { if (is_array($old)) { @touch($f); return $old; } @file_put_contents($f, '[]'); return []; }   // nicht dauernd neu fragen
    $list = rel_parse($j, $p[3]);
    @file_put_contents($f, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $list;
}

/** neueste stabile Version je Projekt: id => [icon, name, repo, page, v, date, url, age_days, assets, downloads] (v = '' wenn unbekannt) */
function rel_status(?array $ids = null, ?callable $fetch = null, int $now = 0): array {
    $now = $now ?: time();
    $out = [];
    foreach ($ids ?? array_keys(REL_PROJECTS) as $id) {
        if (!isset(REL_PROJECTS[$id])) continue;
        [$ic, $name, $repo, , $page] = REL_PROJECTS[$id];
        $rel = array_values(array_filter(rel_fetch($id, $fetch), fn($r) => !$r['pre']));
        $r = $rel[0] ?? null;
        $ts = $r ? (strtotime($r['date']) ?: 0) : 0;
        $out[$id] = ['icon' => $ic, 'name' => $name, 'repo' => $repo, 'page' => $page, 'v' => $r['v'] ?? '', 'date' => $ts, 'url' => $r['url'] ?? ('https://github.com/' . $repo . '/releases'),
                     'age' => $ts ? intdiv(max(0, $now - $ts), 86400) : null, 'assets' => $r['assets'] ?? [], 'downloads' => array_sum(array_column($r['assets'] ?? [], 'downloads')), 'count' => count($rel)];
    }
    return $out;
}

/** „vor 3 Tagen“ */
function rel_ago(?int $days): string {
    if ($days === null) return '';
    if ($days === 0) return 'heute';
    if ($days === 1) return 'gestern';
    if ($days < 30) return 'vor ' . $days . ' Tagen';
    if ($days < 365) return 'vor ' . intdiv($days, 30) . ' Mon.';
    return 'vor ' . intdiv($days, 365) . ' J.';
}

/** Dateigröße lesbar */
function rel_size(int $b): string { return $b > 1048576 ? number_format($b / 1048576, 1, ',', '.') . ' MB' : max(1, (int)round($b / 1024)) . ' KB'; }
