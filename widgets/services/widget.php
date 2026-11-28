<?php
// 9.0: Dienst-Status (Website, Datenbank, Nexus-Schnittstelle, HypeRate, Bot) – Daten der Status-Seite
require_once APP_ROOT . '/inc/modules/status.php';
return [
    'name' => 'Dienst-Status',
    'icon' => '🩺',
    'description' => 'Ampel für alle GhostNode-Dienste mit Antwortzeit und Verfügbarkeit der letzten 24 Stunden (Daten der Status-Seite).',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'refresh' => 300,
    'settings' => [
        'bars' => ['label' => 'Verlauf als Balken zeigen', 'type' => 'checkbox', 'default' => true],
    ],
    'render' => function (WidgetCtx $w): string {
        $s = st_summary();
        if (!$s['services']) {
            $live = st_check(fn($u) => [0, '']);   // ohne gespeicherte Prüfung: nur lokale Dienste (keine externen Abrufe beim Seitenaufbau)
            $s['services'] = [];
            foreach ($live as $id => [$ic, $name, $ok, $ms, $msg]) if (in_array($id, ['db', 'site', 'api'], true)) $s['services'][] = ['icon' => $ic, 'name' => $name, 'ok' => $ok, 'ms' => $ms, 'msg' => $msg, 'up24' => null, 'bars' => []];
            $s['ok'] = !array_filter($s['services'], fn($x) => !$x['ok']);
            $note = '<p class="wg-muted">Externe Dienste werden von der Aufgabe „status“ geprüft (Admin → System → Aufgaben).</p>';
        }
        $h = '<div class="gx-big ' . ($s['ok'] ? 'ok' : 'bad') . '">' . ($s['ok'] ? '✅ Alles läuft' : '⚠ ' . (int)($s['down'] ?? 0) . ' gestört') . '</div><ul class="gx-list">';
        foreach ($s['services'] as $x) {
            $h .= '<li class="gx-row"><span class="gx-dot ' . ($x['ok'] ? 'ok' : 'bad') . '"></span><span class="gx-main"><b>' . e($x['icon'] . ' ' . $x['name']) . '</b><small>'
                . e($x['ok'] ? ($x['ms'] ? $x['ms'] . ' ms' : 'ok') : $x['msg']) . ($x['up24'] !== null ? ' · ' . e(str_replace('.', ',', (string)$x['up24'])) . ' % (24 h)' : '') . '</small>';
            if ($w->cfg['bars'] && $x['bars']) $h .= '<span class="gx-bars">' . implode('', array_map(fn($b) => '<i class="' . ($b ? 'ok' : 'bad') . '"></i>', array_slice($x['bars'], -48))) . '</span>';
            $h .= '</span></li>';
        }
        $h .= '</ul>' . ($note ?? '');
        if (!empty($s['checked'])) $h .= '<div class="wg-muted">Geprüft ' . e(date('d.m. H:i', (int)$s['checked'])) . (!empty($s['stale']) ? ' · ⚠ veraltet' : '') . '</div>';
        return $h . '<a class="wg-more" href="status.php">Status-Seite →</a>';
    },
];
