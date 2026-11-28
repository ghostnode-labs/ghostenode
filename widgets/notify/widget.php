<?php
// 9.0: Benachrichtigungen aus „Mein Bereich“ direkt auf dem Dashboard – mit Zähler „neu“ und „alles gelesen“
return [
    'name' => 'Benachrichtigungen',
    'icon' => '🔔',
    'description' => 'Neue Versionen (VRC Nexus, D2 Nexus, Bot), anstehende Termine, Antworten in der Community und Dienst-Störungen – mit „Alles gelesen“.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'login' => true,
    'refresh' => 900,
    'settings' => [
        'limit' => ['label' => 'Einträge', 'type' => 'number', 'default' => 6, 'min' => 3, 'max' => 20],
    ],
    'render' => function (WidgetCtx $w): string {
        $u = current_user();
        if (!$u) return '<div class="wg-empty">Bitte anmelden.</div>';
        require_once APP_ROOT . '/inc/modules/me.php';
        $list = me_notes($u);
        $new = count(array_filter($list, fn($n) => !empty($n['new'])));
        $h = '<div class="nf-head"><span>' . ($new ? '<b class="nf-new">' . $new . ' neu</b>' : 'Nichts Neues') . '</span>' . ($new ? '<button type="button" class="wg-link" data-wg-action="seen">✓ Alles gelesen</button>' : '') . '</div><ul class="gx-list">';
        foreach (array_slice($list, 0, (int)$w->cfg['limit']) as $n)
            $h .= '<li class="gx-row' . (!empty($n['new']) ? ' is-new' : '') . '"><span class="gx-ic">' . e($n['icon']) . '</span><span class="gx-main"><a href="' . e($n['href']) . '">' . e($n['title']) . '</a><small>'
                . e(($n['sub'] ?? '') !== '' ? $n['sub'] : ($n['t'] ? date('d.m. H:i', (int)$n['t']) : '')) . '</small></span></li>';
        if (!$list) $h .= '<li class="wg-empty">Keine Benachrichtigungen.</li>';
        return $h . '</ul><a class="wg-more" href="me.php?tab=notes">Alle & Einstellungen →</a>';
    },
    'action' => function (WidgetCtx $w, string $op, array $in): ?string {
        $u = current_user();
        if (!$u) throw new RuntimeException('Bitte anmelden.');
        require_once APP_ROOT . '/inc/modules/me.php';
        if ($op === 'seen') { me_notes_seen((int)$u['id']); return 'Als gelesen markiert.'; }
        return null;
    },
];
