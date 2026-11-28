<?php
// 9.0: Letzte Aktivitäten aus dem Protokoll – optional nur Warnungen/Sicherheit
return [
    'name' => 'Letzte Aktivitäten',
    'icon' => '📋',
    'description' => 'Die neuesten Einträge im Protokoll (Anmeldungen, Änderungen, Sicherheit) – live, optional nur Warnungen.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 'm',
    'permission' => 'audit.view',
    'refresh' => 120,
    'settings' => [
        'level' => ['label' => 'Welche Einträge?', 'type' => 'select', 'default' => 'all', 'options' => ['all' => 'Alle', 'warn' => 'Warnungen & Sicherheit', 'security' => 'Nur Sicherheit']],
        'limit' => ['label' => 'Anzahl', 'type' => 'number', 'default' => 8, 'min' => 3, 'max' => 30],
    ],
    'render' => function (WidgetCtx $w): string {
        $lv = ['all' => ['info', 'warn', 'security'], 'warn' => ['warn', 'security'], 'security' => ['security']][$w->cfg['level']] ?? ['info', 'warn', 'security'];
        $st = site_db()->prepare('SELECT ts, username, action, detail, level, ip FROM audit_log WHERE level IN (' . implode(',', array_fill(0, count($lv), '?')) . ') ORDER BY id DESC LIMIT ' . (int)$w->cfg['limit']);
        $st->execute($lv);
        $ic = ['info' => 'ℹ️', 'warn' => '⚠️', 'security' => '🚨'];
        $h = '<ul class="gx-list al">';
        foreach ($st->fetchAll() as $r) {
            $h .= '<li class="gx-row lv-' . e($r['level']) . '"><span class="gx-ic">' . ($ic[$r['level']] ?? '•') . '</span><span class="gx-main"><b>' . e($r['action']) . '</b>'
                . ($r['detail'] !== '' ? ' <span class="al-d">' . e(mb_strimwidth((string)$r['detail'], 0, 90, '…')) . '</span>' : '')
                . '<small>' . e(date('d.m. H:i', (int)strtotime((string)$r['ts']))) . ' · ' . e($r['username'] !== '' ? $r['username'] : 'System') . '</small></span></li>';
        }
        return $h . '</ul><a class="wg-more" href="admin.php?tab=audit">Ganzes Protokoll →</a>';
    },
];
