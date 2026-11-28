<?php
// 9.0: Download-Statistik für VRC Nexus (öffentliche Zähler der GitHub-Releases)
require_once APP_ROOT . '/inc/modules/changelog.php';
if (!function_exists('dls_kind')) {
    function dls_kind(string $n): string {
        if (str_ends_with($n, '-Setup.exe')) return '🪟 Windows';
        if (str_ends_with($n, '-portable.exe')) return '🧳 Portable';
        if (str_contains($n, 'Watch') && str_ends_with($n, '.apk')) return '⌚ Wear OS';
        if (str_starts_with($n, 'Nexus-Pet') && str_ends_with($n, '.apk')) return '🐾 Nexus Pet';
        if (str_ends_with($n, '.apk')) return '📱 Android';
        if (str_ends_with($n, '.streamDeckPlugin')) return '🎛 Stream Deck';
        return '';
    }
    /** Zusammenfassung: [total, latest[v, sum, kinds[]], per_version[[v, sum]]] */
    function dls_summary(array $releases, int $versions = 8): array {
        $rel = array_values(array_filter($releases, fn($r) => empty($r['pre'])));
        $per = []; $total = 0;
        foreach ($rel as $r) {
            $sum = 0;
            foreach ((array)$r['assets'] as $a) if (dls_kind((string)$a['name']) !== '') $sum += (int)($a['downloads'] ?? 0);
            $total += $sum;
            $per[] = [$r['v'], $sum];
        }
        $kinds = [];
        if ($rel) foreach ((array)$rel[0]['assets'] as $a) { $k = dls_kind((string)$a['name']); if ($k !== '') $kinds[$k] = ($kinds[$k] ?? 0) + (int)($a['downloads'] ?? 0); }
        arsort($kinds);
        return ['total' => $total, 'latest' => $rel ? ['v' => $rel[0]['v'], 'sum' => $per[0][1], 'kinds' => $kinds] : null, 'per' => array_slice($per, 0, $versions)];
    }
}
return [
    'name' => 'Download-Statistik',
    'icon' => '📊',
    'description' => 'Wie oft VRC Nexus heruntergeladen wurde: gesamt, neueste Version nach Gerät und die letzten Versionen im Vergleich.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'refresh' => 1800,
    'settings' => [
        'versions' => ['label' => 'Versionen im Vergleich', 'type' => 'number', 'default' => 6, 'min' => 3, 'max' => 15],
    ],
    'render' => function (WidgetCtx $w): string {
        $s = dls_summary(cl_fetch(), (int)$w->cfg['versions']);
        if (!$s['latest']) return '<div class="wg-empty">Keine Release-Daten (GitHub nicht erreichbar?).</div>';
        $fmt = fn(int $n) => number_format($n, 0, ',', '.');
        $h = '<div class="dls-top"><div><b>' . $fmt($s['total']) . '</b><small>gesamt</small></div><div><b>' . $fmt($s['latest']['sum']) . '</b><small>v' . e($s['latest']['v']) . '</small></div></div>';
        if ($s['latest']['kinds']) {
            $max = max(1, max($s['latest']['kinds']));
            $h .= '<ul class="dls-bars">';
            foreach ($s['latest']['kinds'] as $k => $n) $h .= '<li><span>' . e($k) . '</span><i style="--w:' . round($n / $max * 100) . '%"></i><b>' . $fmt($n) . '</b></li>';
            $h .= '</ul>';
        }
        $max = max(1, max(array_column($s['per'], 1)));
        $h .= '<div class="dls-cols" role="img" aria-label="Downloads je Version">';
        foreach (array_reverse($s['per']) as [$v, $n]) $h .= '<span title="v' . e($v) . ': ' . $fmt($n) . '"><i style="--h:' . max(3, round($n / $max * 100)) . '%"></i><small>' . e($v) . '</small></span>';
        return $h . '</div><a class="wg-more" href="downloads.php">Downloads →</a>';
    },
];
