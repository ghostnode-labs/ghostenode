<?php
// 🥽 VRC Nexus: neueste Version mit Downloads + offizielle VRChat-News
return [
    'name' => 'VRC Nexus',
    'icon' => '🥽',
    'description' => 'Neueste VRC-Nexus-Version mit Downloads (Windows, Android, Wear OS, Stream Deck, Nexus Pet) und die letzten offiziellen VRChat-News.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 'm',
    'refresh' => 1800,
    'settings' => [
        'news' => ['label' => 'Anzahl VRChat-News', 'type' => 'number', 'default' => 3, 'min' => 0, 'max' => 8],
    ],
    'render' => function (WidgetCtx $w): string {
        require_once APP_ROOT . '/inc/modules/changelog.php';
        $h = '';
        $r = cl_fetch()[0] ?? null;
        if ($r) {
            $kind = fn($n) => str_contains($n, 'Watch') ? '⌚' : (str_starts_with($n, 'Nexus-Pet') ? '🐾' : (str_ends_with($n, '.apk') ? '📱' : (str_contains($n, 'Setup') ? '🪟' : (str_contains($n, 'streamDeck') ? '🎛' : ''))));
            $h .= '<div class="nxw-v"><b>VRC Nexus ' . e(cl_ver($r['v'])) . '</b> <span class="muted">' . e(date('d.m.Y', strtotime($r['date']) ?: time())) . '</span> <a href="changelog.php#v' . e($r['v']) . '">Was ist neu?</a></div><div class="nxw-dl">';
            foreach ($r['assets'] as $a) if ($kind($a['name']) !== '' && !str_contains($a['name'], 'portable') && preg_match('#^https://github\.com/#', $a['url'])) $h .= '<a class="btn btn-sm" href="' . e($a['url']) . '" rel="noopener">' . $kind($a['name']) . ' ' . ['⌚' => 'Wear OS', '📱' => 'Android', '🪟' => 'Windows', '🎛' => 'Stream Deck', '🐾' => 'Nexus Pet'][$kind($a['name'])] . '</a>';
            $h .= '</div>';
        } else $h .= '<p class="muted">Versionsliste gerade nicht erreichbar.</p>';
        $n = (int)$w->cfg['news'];
        if ($n > 0) {
            require_once APP_ROOT . '/inc/modules/nexuspulse.php';
            try { $news = array_slice(np_steam(), 0, $n); } catch (Throwable $e) { $news = []; }
            if ($news) $h .= '<ul class="nxw-news">' . implode('', array_map(fn($x) => '<li><a href="' . e($x['url']) . '" target="_blank" rel="noopener noreferrer">' . e($x['title']) . '</a> <span class="muted">' . e(date('d.m.', (int)$x['ts'])) . '</span></li>', $news)) . '</ul>';
        }
        return $h;
    },
];
