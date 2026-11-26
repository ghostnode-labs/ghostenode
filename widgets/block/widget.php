<?php
// 🧱 Baukasten: eigener Block mit Text (Markdown/BBCode), Bild und Knopf – für Hinweise, Regeln, Begrüßung …
return [
    'name' => 'Eigener Block',
    'icon' => '🧱',
    'description' => 'Baukasten-Block: Überschrift, Text mit Formatierung, optional Bild und Knopf – z. B. Begrüßung, Regeln, Hinweise.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 'm',
    'settings' => [
        'emoji' => ['label' => 'Großes Emoji (optional)', 'type' => 'text', 'default' => '👋'],
        'text' => ['label' => 'Text (Markdown/BBCode: **fett**, *kursiv*, Links)', 'type' => 'textarea', 'default' => "**Willkommen!**\nHier kannst du eigenen Text hinterlegen."],
        'image' => ['label' => 'Bild-Adresse (https://…, optional)', 'type' => 'text', 'default' => ''],
        'button' => ['label' => 'Knopf-Beschriftung (optional)', 'type' => 'text', 'default' => ''],
        'url' => ['label' => 'Knopf-Ziel (https://… oder Seite wie calendar.php)', 'type' => 'text', 'default' => ''],
        'accent' => ['label' => 'Farbe', 'type' => 'select', 'default' => 'cyan', 'options' => ['cyan' => 'Türkis', 'gold' => 'Gold', 'pink' => 'Pink', 'green' => 'Grün', 'red' => 'Rot']],
        'center' => ['label' => 'Zentriert', 'type' => 'checkbox', 'default' => false],
    ],
    'render' => function (WidgetCtx $w): string {
        $c = $w->cfg;
        $col = ['cyan' => '#38d5e6', 'gold' => '#f3c262', 'pink' => '#ff6fae', 'green' => '#50fa7b', 'red' => '#ff5555'][$c['accent']] ?? '#38d5e6';
        $url = trim((string)$c['url']);
        $safe = preg_match('#^https://#i', $url) || preg_match('#^[a-z0-9_\-]+\.php(\?[\w=&%\-]*)?$#i', $url);
        $img = preg_match('#^https://[^\s"<>]+$#i', (string)$c['image']) ? (string)$c['image'] : '';
        $h = '<div class="blk" style="--blk:' . $col . ';' . ($c['center'] ? 'text-align:center' : '') . '">';
        if (trim((string)$c['emoji']) !== '') $h .= '<div class="blk-emo">' . e(mb_substr((string)$c['emoji'], 0, 4)) . '</div>';
        if ($img) $h .= '<img class="blk-img" src="' . e($img) . '" alt="" loading="lazy" referrerpolicy="no-referrer">';
        $h .= '<div class="blk-text">' . format_text((string)$c['text']) . '</div>';
        if ($safe && trim((string)$c['button']) !== '') $h .= '<a class="btn btn-sm blk-btn" href="' . e($url) . '"' . (preg_match('#^https://#i', $url) ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . e($c['button']) . '</a>';
        return $h . '</div>';
    },
];
