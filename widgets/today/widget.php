<?php
// 🗓️ Heute in VRChat (7.2): Club-Events heute, neue Clubs, beste neue Steckbriefe und die neueste VRC-Nexus-Version
return [
    'name' => 'Heute in VRChat',
    'icon' => '🗓️',
    'description' => 'Was heute los ist: Club-Events des Tages, neue Clubs, neue Steckbriefe und die neueste VRC-Nexus-Version.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 'm',
    'refresh' => 900,
    'settings' => [
        'events' => ['label' => 'Anzahl Events', 'type' => 'number', 'default' => 5, 'min' => 1, 'max' => 12],
    ],
    'render' => function (WidgetCtx $w): string {
        $h = '';
        try {
            require_once APP_ROOT . '/inc/modules/clubs.php';
            $end = strtotime('tomorrow') ?: time() + 86400;
            $ev = array_values(array_filter(cl_calendar(null, ['days' => 1])['items'], fn($e) => $e['starts'] < $end));
            $h .= '<div class="tdy-h">📅 Heute</div>';
            $h .= $ev ? '<ul class="tdy-l">' . implode('', array_map(fn($e) => '<li><b>' . e(date('H:i', $e['starts'])) . '</b> ' . e($e['title']) . ' <span class="muted">· ' . e($e['club']['name']) . '</span></li>', array_slice($ev, 0, max(1, (int)$w->cfg['events'])))) . '</ul>'
                : '<p class="muted">Heute keine Club-Events – <a href="events.php">alle Events</a></p>';
            $nc = cl_db()->prepare("SELECT slug, name FROM clubs WHERE status = 'active' AND listed = 1 AND adult = 0 AND created > ? ORDER BY created DESC LIMIT 3"); $nc->execute([time() - 7 * 86400]);
            if ($rows = $nc->fetchAll(PDO::FETCH_ASSOC)) $h .= '<div class="tdy-h">🏠 Neue Clubs</div><p>' . implode(' · ', array_map(fn($c) => '<a href="nexus.php?club=' . e(rawurlencode($c['slug'])) . '">' . e($c['name']) . '</a>', $rows)) . '</p>';
        } catch (Throwable $e) { $h .= '<p class="muted">Club-Events gerade nicht verfügbar.</p>'; }
        try {
            require_once APP_ROOT . '/inc/modules/wiki.php';
            $sb = sb_db()->prepare('SELECT kind, id, name FROM sb_items WHERE created > ? ORDER BY created DESC LIMIT 3'); $sb->execute([time() - 7 * 86400]);
            if ($rows = $sb->fetchAll(PDO::FETCH_ASSOC)) $h .= '<div class="tdy-h">📚 Neue Steckbriefe</div><p>' . implode(' · ', array_map(fn($x) => '<a href="wiki.php?w=' . e($x['id']) . '">' . ($x['kind'] === 'world' ? '🌐 ' : '🎭 ') . e($x['name']) . '</a>', $rows)) . '</p>';
        } catch (Throwable $e) { /* Steckbriefe noch nicht eingerichtet */ }
        try {
            require_once APP_ROOT . '/inc/modules/changelog.php';
            $r = cl_fetch()[0] ?? null;
            if ($r) $h .= '<div class="tdy-h">📦 VRC Nexus</div><p><a href="changelog.php#v' . e($r['v']) . '">Version ' . e($r['v']) . '</a> <span class="muted">vom ' . e(date('d.m.', strtotime($r['date']) ?: time())) . '</span> · <a href="downloads.php">Downloads</a></p>';
        } catch (Throwable $e) { /* GitHub nicht erreichbar */ }
        return '<style>.tdy-h{font-weight:700;color:var(--accent-cyan);margin:8px 0 4px}.tdy-l{margin:0;padding-left:18px}.tdy-l li{margin:2px 0}</style>' . $h;
    },
];
