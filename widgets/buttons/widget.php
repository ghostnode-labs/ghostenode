<?php
// 🎛 Baukasten: Knopfleiste – große Knöpfe für Seiten und Links (eine Zeile je Knopf)
return [
    'name' => 'Knopfleiste',
    'icon' => '🎛',
    'description' => 'Große Knöpfe wie ein Stream Deck: eine Zeile je Knopf „Emoji | Name | Ziel“ – Ziel ist eine Seite (calendar.php) oder https-Adresse.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 'm',
    'settings' => [
        'buttons' => ['label' => 'Knöpfe (eine Zeile je Knopf: Emoji | Name | Ziel)', 'type' => 'textarea',
            'default' => "🥽 | VRC Nexus | nexus.php\n📅 | Kalender | calendar.php\n📜 | Changelog | changelog.php\n🗺 | Roadmap | Roadmap.php\n💬 | Discord | https://discord.com/app"],
        'cols' => ['label' => 'Knöpfe pro Zeile', 'type' => 'select', 'default' => '4', 'options' => ['2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6']],
        'newtab' => ['label' => 'https-Ziele in neuem Tab', 'type' => 'checkbox', 'default' => true],
    ],
    'render' => function (WidgetCtx $w): string {
        $h = '<div class="kb" style="grid-template-columns:repeat(' . (int)$w->cfg['cols'] . ',minmax(0,1fr))">';
        $n = 0;
        foreach (preg_split('/\r?\n/', (string)$w->cfg['buttons']) as $line) {
            $p = array_map('trim', explode('|', $line));
            if (count($p) < 3 || $p[1] === '') continue;
            $url = $p[2];
            $ext = (bool)preg_match('#^https://#i', $url);
            if (!$ext && !preg_match('#^[A-Za-z0-9_\-]+\.php(\?[\w=&%\-]*)?$#', $url)) continue;   // nur Seiten dieser Webseite oder https
            $h .= '<a class="kb-b" href="' . e($url) . '"' . ($ext && $w->cfg['newtab'] ? ' target="_blank" rel="noopener noreferrer"' : '') . '><i>' . e(mb_substr($p[0] ?: '🔗', 0, 4)) . '</i><span>' . e(mb_substr($p[1], 0, 30)) . '</span></a>';
            if (++$n >= 30) break;
        }
        return $n ? $h . '</div>' : '<p class="muted">Noch keine Knöpfe – im Bearbeiten-Modus über ⚙ eintragen.</p>';
    },
];
