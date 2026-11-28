<?php
// 9.0: Marktplatz-Neuheiten – zuletzt freigegebene Plugins und Themes
require_once APP_ROOT . '/inc/modules/market.php';
require_once APP_ROOT . '/inc/modules/themes_market.php';
return [
    'name' => 'Marktplatz-Neuheiten',
    'icon' => '🧩',
    'description' => 'Die zuletzt freigegebenen Plugins (VRC Nexus & D2 Nexus) und Themes aus dem Marktplatz.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'refresh' => 1800,
    'settings' => [
        'limit' => ['label' => 'Einträge', 'type' => 'number', 'default' => 6, 'min' => 2, 'max' => 20],
        'themes' => ['label' => 'Themes einbeziehen', 'type' => 'checkbox', 'default' => true],
    ],
    'render' => function (WidgetCtx $w): string {
        $list = [];
        foreach (mk_load() as $id => $p) {
            $ok = array_values(array_filter((array)($p['versions'] ?? []), fn($v) => ($v['status'] ?? '') === 'approved'));
            if (!$ok) continue;
            $v = end($ok);
            $list[] = ['t' => (int)($v['reviewed'] ?? $v['uploaded'] ?? 0), 'icon' => (string)($v['icon'] ?? '') ?: '🧩', 'name' => (string)($v['name'] ?? $id), 'sub' => 'Plugin v' . ($v['version'] ?? '?') . (($v['author'] ?? '') !== '' ? ' · ' . $v['author'] : ''), 'href' => 'market.php#' . rawurlencode((string)($p['id'] ?? $id))];
        }
        if ($w->cfg['themes']) foreach (tm_load() as $id => $t) {
            if (($t['status'] ?? '') !== 'approved') continue;
            $list[] = ['t' => (int)($t['reviewed'] ?? $t['uploaded'] ?? 0), 'icon' => '🎨', 'name' => (string)($t['name'] ?? $id), 'sub' => 'Theme' . (($t['author'] ?? '') !== '' ? ' · ' . $t['author'] : ''), 'href' => 'themes.php#' . rawurlencode((string)$id),
                       'sw' => array_slice(array_values((array)($t['colors'] ?? [])), 0, 4)];
        }
        usort($list, fn($a, $b) => $b['t'] <=> $a['t']);
        if (!$list) return '<div class="wg-empty">Noch nichts freigegeben. <a href="market.php">Zum Marktplatz →</a></div>';
        $h = '<ul class="gx-list">';
        foreach (array_slice($list, 0, (int)$w->cfg['limit']) as $x) {
            $sw = '';
            foreach ($x['sw'] ?? [] as $c) if (preg_match('/^#[0-9a-f]{6}$/', (string)$c)) $sw .= '<i style="background:' . $c . '"></i>';
            $h .= '<li class="gx-row"><span class="gx-ic">' . e(mb_substr($x['icon'], 0, 4)) . '</span><span class="gx-main"><a href="' . e($x['href']) . '"><b>' . e($x['name']) . '</b></a><small>' . e($x['sub']) . ($x['t'] ? ' · ' . e(date('d.m.Y', $x['t'])) : '') . '</small>' . ($sw ? '<span class="mk-sw">' . $sw . '</span>' : '') . '</span></li>';
        }
        return $h . '</ul><a class="wg-more" href="market.php">Marktplatz →</a>';
    },
];
