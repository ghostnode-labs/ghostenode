<?php
/** VRC-Nexus-Versionen aus den öffentlichen GitHub-Releases (für changelog.php und api.php), 30 Minuten zwischengespeichert. */
const CL_REPO = 'ghostnode-labs/vrc_nexus';
const CL_TTL = 1800;

function cl_fetch(): array {
    $dir = DATA_DIR . '/cache'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $f = $dir . '/changelog_releases.json';
    $old = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    if (is_array($old) && time() - filemtime($f) < CL_TTL) return $old;
    $all = [];
    for ($page = 1; $page <= 3; $page++) {
        $ch = curl_init('https://api.github.com/repos/' . CL_REPO . '/releases?per_page=100&page=' . $page);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'User-Agent: GhostNode-Changelog']]);
        $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $j = json_decode((string)$raw, true);
        if ($code !== 200 || !is_array($j)) { if (is_array($old)) { @touch($f); return $old; } return []; }   // GitHub weg → alter Stand
        foreach ($j as $r) {
            if (!empty($r['draft']) || !preg_match('/^vrc-nexus-v(\d+\.\d+\.\d+)$/', (string)($r['tag_name'] ?? ''), $m)) continue;
            $assets = [];
            foreach ((array)($r['assets'] ?? []) as $a) $assets[] = ['name' => (string)$a['name'], 'url' => (string)$a['browser_download_url'], 'size' => (int)$a['size'],
                'sha256' => preg_match('/^sha256:([0-9a-f]{64})$/', (string)($a['digest'] ?? ''), $dm) ? $dm[1] : '', 'downloads' => (int)($a['download_count'] ?? 0)];   // 6.6: Prüfsumme von GitHub
            $all[] = ['v' => $m[1], 'date' => (string)($r['published_at'] ?? ''), 'body' => (string)($r['body'] ?? ''), 'url' => (string)($r['html_url'] ?? ''), 'assets' => $assets, 'pre' => !empty($r['prerelease'])];
        }
        if (count($j) < 100) break;
    }
    usort($all, fn($a, $b) => version_compare($b['v'], $a['v']));
    @file_put_contents($f, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $all;
}
