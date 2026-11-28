<?php
// 9.0: Textbausteine – oft gebrauchte Texte (Befehle, IDs, Antworten) mit einem Klick kopieren; privat je Konto
return [
    'name' => 'Textbausteine',
    'icon' => '📋',
    'description' => 'Oft gebrauchte Texte – Befehle, VRChat-IDs, Standard-Antworten – mit einem Klick in die Zwischenablage kopieren. Privat je Konto.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'login' => true,
    'render' => function (WidgetCtx $w): string {
        $items = (array)($w->user_state()['items'] ?? []);
        $h = '<ul class="sn">';
        foreach ($items as $x) $h .= '<li><button type="button" class="sn-copy" data-text="' . e($x['text']) . '" title="Kopieren"><b>' . e($x['label']) . '</b><code>' . e(mb_strimwidth(str_replace("\n", ' ⏎ ', $x['text']), 0, 70, '…')) . '</code></button>'
            . '<button type="button" class="sn-x" data-wg-action="delete" data-id="' . e($x['id']) . '" aria-label="Entfernen">×</button></li>';
        $h .= '</ul>';
        if (!$items) $h .= '<div class="wg-empty">Noch keine Bausteine.</div>';
        if (count($items) < 50) $h .= '<details class="sn-new"><summary>＋ Baustein</summary><form data-wg-action="add"><input type="text" name="label" maxlength="40" placeholder="Name, z. B. „Discord-Einladung“" required>'
            . '<textarea name="text" rows="3" maxlength="2000" placeholder="Text …" required></textarea><button class="btn btn-sm btn-gold">Speichern</button></form></details>';
        return $h;
    },
    'action' => function (WidgetCtx $w, string $op, array $in): ?string {
        if (!is_logged_in()) throw new RuntimeException('Bitte anmelden.');
        $s = $w->user_state();
        $items = (array)($s['items'] ?? []);
        if ($op === 'add' && count($items) < 50) {
            $l = trim(mb_substr((string)($in['label'] ?? ''), 0, 40)); $t = mb_substr((string)($in['text'] ?? ''), 0, 2000);
            if ($l === '' || trim($t) === '') throw new RuntimeException('Name und Text angeben.');
            $items[] = ['id' => bin2hex(random_bytes(4)), 'label' => $l, 'text' => $t];
        }
        if ($op === 'delete') $items = array_values(array_filter($items, fn($x) => $x['id'] !== (string)($in['id'] ?? '')));
        $s['items'] = $items;
        $w->save_user_state($s);
        return null;
    },
];
