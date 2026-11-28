<?php
// 9.0: Release-Status aller GhostNode-Projekte (VRC Nexus, D2 Nexus, Discord-Bot) – neueste Version, Alter, Downloads
require_once APP_ROOT . '/inc/modules/releases.php';
return [
    'name' => 'Release-Status',
    'icon' => '🚀',
    'description' => 'Neueste Version von VRC Nexus, D2 Nexus und dem Discord-Bot mit Datum, Alter und Download-Link (GitHub-Releases).',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'refresh' => 1800,
    'settings' => [
        'vrc' => ['label' => 'VRC Nexus anzeigen', 'type' => 'checkbox', 'default' => true],
        'd2' => ['label' => 'D2 Nexus anzeigen', 'type' => 'checkbox', 'default' => true],
        'bot' => ['label' => 'Discord-Bot anzeigen', 'type' => 'checkbox', 'default' => true],
        'fresh' => ['label' => '„Neu“-Markierung bis (Tage)', 'type' => 'number', 'default' => 7, 'min' => 0, 'max' => 60],
    ],
    'render' => function (WidgetCtx $w): string {
        $ids = array_values(array_filter(array_keys(REL_PROJECTS), fn($id) => !empty($w->cfg[$id])));
        if (!$ids) return '<div class="wg-empty">Kein Projekt ausgewählt (⚙).</div>';
        $h = '<ul class="gx-list">';
        foreach (rel_status($ids) as $id => $r) {
            $new = $r['age'] !== null && $r['age'] < (int)$w->cfg['fresh'];
            $h .= '<li class="gx-row"><span class="gx-ic">' . e($r['icon']) . '</span><span class="gx-main"><a href="' . e($r['page']) . '"><b>' . e($r['name']) . '</b></a>'
                . '<small>' . ($r['v'] !== '' ? e(rel_ago($r['age'])) . ($r['downloads'] ? ' · ' . number_format($r['downloads'], 0, ',', '.') . ' Downloads' : '') : 'noch keine Version gefunden') . '</small></span>'
                . ($r['v'] !== '' ? '<a class="gx-badge' . ($new ? ' new' : '') . '" href="' . e($r['url']) . '" target="_blank" rel="noopener" title="Release auf GitHub">v' . e($r['v']) . ($new ? ' · neu' : '') . '</a>' : '<span class="gx-badge off">–</span>')
                . '</li>';
        }
        return $h . '</ul><a class="wg-more" href="downloads.php">⬇ Alle Downloads →</a>';
    },
];
