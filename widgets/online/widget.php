<?php
// 9.0: Wer ist gerade angemeldet und aktiv? (Sitzungen der letzten Minuten)
return [
    'name' => 'Wer ist online',
    'icon' => '🟢',
    'description' => 'Konten, die in den letzten Minuten auf der Webseite aktiv waren – mit Rolle und letzter Aktivität.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'permission' => 'users.view',
    'refresh' => 120,
    'settings' => [
        'minutes' => ['label' => 'Aktiv in den letzten … Minuten', 'type' => 'number', 'default' => 15, 'min' => 2, 'max' => 240],
    ],
    'render' => function (WidgetCtx $w): string {
        $min = (int)$w->cfg['minutes'];
        $st = site_db()->prepare('SELECT u.id, u.username, u.display_name, r.name AS role_name, r.color AS role_color, MAX(s.last_seen) AS seen, COUNT(*) AS n
                                  FROM user_sessions s JOIN users u ON u.id = s.user_id JOIN roles r ON r.id = u.role_id
                                  WHERE s.last_seen >= ? GROUP BY u.id, u.username, u.display_name, r.name, r.color ORDER BY seen DESC LIMIT 30');
        $st->execute([date('Y-m-d H:i:s', time() - $min * 60)]);
        $rows = $st->fetchAll();
        $h = '<div class="gx-big ok">' . count($rows) . ' online</div><ul class="gx-list">';
        foreach ($rows as $r) {
            $ago = max(0, intdiv(time() - (int)strtotime((string)$r['seen']), 60));
            $h .= '<li class="gx-row"><span class="on-av" style="--rc:' . e($r['role_color']) . '">' . e(mb_strtoupper(mb_substr($r['display_name'] ?: $r['username'], 0, 1))) . '</span><span class="gx-main"><b>' . e($r['display_name'] ?: $r['username']) . '</b><small>'
                . e($r['role_name']) . ' · ' . ($ago ? 'vor ' . $ago . ' min' : 'gerade') . ((int)$r['n'] > 1 ? ' · ' . (int)$r['n'] . ' Geräte' : '') . '</small></span></li>';
        }
        if (!$rows) $h .= '<li class="wg-empty">Gerade niemand aktiv.</li>';
        return $h . '</ul>' . (can('sessions.manage') ? '<a class="wg-more" href="admin.php?tab=sessions">Sitzungen verwalten →</a>' : '');
    },
];
