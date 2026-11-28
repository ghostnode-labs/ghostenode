<?php
// 9.0: Notizbuch – mehrere private Notizen je Konto (jede Person sieht nur ihre eigenen), anheften, durchsuchen, Formatierung
return [
    'name' => 'Notizbuch (privat)',
    'icon' => '📓',
    'description' => 'Mehrere private Notizen mit Titel, Formatierung, Anheften und Suche – jedes Konto sieht nur seine eigenen, auch im gemeinsamen Dashboard.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 'm',
    'login' => true,
    'settings' => [
        'limit' => ['label' => 'Notizen in der Liste', 'type' => 'number', 'default' => 8, 'min' => 3, 'max' => 40],
    ],
    'render' => function (WidgetCtx $w): string {
        $s = $w->user_state();
        $notes = (array)($s['notes'] ?? []);
        usort($notes, fn($a, $b) => [empty($b['pin']), $b['at'] ?? 0] <=> [empty($a['pin']), $a['at'] ?? 0]);
        $open = (string)($s['open'] ?? ($notes[0]['id'] ?? ''));
        $cur = null;
        foreach ($notes as $n) if ($n['id'] === $open) $cur = $n;
        $h = '<div class="nb"><div class="nb-side"><form class="nb-add" data-wg-action="new"><input type="text" name="title" maxlength="80" placeholder="Neue Notiz …" required><button class="btn btn-sm">＋</button></form>';
        if (count($notes) > 4) $h .= '<input type="search" class="nb-find" placeholder="🔍 Suchen …" aria-label="Notizen durchsuchen">';
        $h .= '<ul class="nb-list">';
        foreach (array_slice($notes, 0, max(3, (int)$w->cfg['limit'])) as $n)
            $h .= '<li class="' . ($n['id'] === $open ? 'on' : '') . '" data-find="' . e(mb_strtolower($n['title'] . ' ' . mb_substr($n['text'], 0, 400))) . '"><button type="button" data-wg-action="open" data-id="' . e($n['id']) . '">' . (!empty($n['pin']) ? '📌 ' : '') . e($n['title']) . '</button></li>';
        $h .= '</ul>' . (count($notes) > (int)$w->cfg['limit'] ? '<div class="wg-muted">+' . (count($notes) - (int)$w->cfg['limit']) . ' weitere (Suche nutzen)</div>' : '') . '</div><div class="nb-main">';
        if (!$cur) $h .= '<div class="wg-empty">Noch keine Notiz – links eine anlegen. Nur du siehst sie.</div>';
        else {
            $h .= '<div class="nb-head"><b>' . e($cur['title']) . '</b><span><button type="button" class="ic" data-wg-action="pin" data-id="' . e($cur['id']) . '" title="' . (!empty($cur['pin']) ? 'Lösen' : 'Anheften') . '">📌</button>'
                . '<button type="button" class="ic nb-edit" title="Bearbeiten">✎</button><button type="button" class="ic danger" data-wg-action="delete" data-id="' . e($cur['id']) . '" data-confirm="Notiz löschen?" title="Löschen">✕</button></span></div>'
                . '<div class="fmt nb-text">' . ($cur['text'] !== '' ? format_text($cur['text']) : '<span class="wg-muted">Leer – ✎ zum Schreiben.</span>') . '</div>'
                . '<form class="nb-form" data-wg-action="save" hidden><input type="hidden" name="id" value="' . e($cur['id']) . '"><input type="text" name="title" maxlength="80" value="' . e($cur['title']) . '">'
                . '<textarea name="text" rows="8" data-format="text">' . e($cur['text']) . '</textarea><button class="btn btn-sm btn-gold">Speichern</button></form>'
                . '<div class="wg-muted">geändert ' . e(date('d.m.Y H:i', (int)$cur['at'])) . '</div>';
        }
        return $h . '</div></div>';
    },
    'action' => function (WidgetCtx $w, string $op, array $in): ?string {
        if (!is_logged_in()) throw new RuntimeException('Bitte anmelden.');
        $s = $w->user_state();
        $notes = (array)($s['notes'] ?? []);
        $id = (string)($in['id'] ?? '');
        $msg = null;
        if ($op === 'new') {
            if (count($notes) >= 100) throw new RuntimeException('Maximal 100 Notizen.');
            $t = trim(mb_substr((string)($in['title'] ?? ''), 0, 80));
            if ($t === '') throw new RuntimeException('Titel fehlt.');
            $id = bin2hex(random_bytes(5));
            $notes[] = ['id' => $id, 'title' => $t, 'text' => '', 'pin' => false, 'at' => time()];
            $s['open'] = $id;
        }
        foreach ($notes as $i => &$n) {
            if ($n['id'] !== $id) continue;
            if ($op === 'open') $s['open'] = $id;
            if ($op === 'pin') $n['pin'] = empty($n['pin']);
            if ($op === 'save') { $n['title'] = trim(mb_substr((string)($in['title'] ?? $n['title']), 0, 80)) ?: $n['title']; $n['text'] = mb_substr((string)($in['text'] ?? ''), 0, 20000); $n['at'] = time(); $msg = 'Gespeichert.'; }
            if ($op === 'delete') { unset($notes[$i]); $s['open'] = ''; $msg = 'Gelöscht.'; }
        }
        unset($n);
        $s['notes'] = array_values($notes);
        $w->save_user_state($s);
        return $msg;
    },
];
