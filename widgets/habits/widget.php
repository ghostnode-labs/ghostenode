<?php
// 9.0: Gewohnheiten – tägliche Ziele abhaken, 7-Tage-Raster und Serie (je Konto privat)
if (!function_exists('hb_streak')) {
    /** Serie: aufeinanderfolgende erledigte Tage bis heute (heute darf noch offen sein) */
    function hb_streak(array $days, string $today): int {
        $set = array_flip($days);
        $d = new DateTimeImmutable($today);
        if (!isset($set[$today])) $d = $d->modify('-1 day');
        $n = 0;
        while (isset($set[$d->format('Y-m-d')]) && $n < 3660) { $n++; $d = $d->modify('-1 day'); }
        return $n;
    }
}
return [
    'name' => 'Gewohnheiten',
    'icon' => '🔥',
    'description' => 'Tägliche Ziele (z. B. Sport, Lernen, Wasser) abhaken – mit Wochenraster, Serie und Bestwert. Privat je Konto.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'login' => true,
    'render' => function (WidgetCtx $w): string {
        $hs = (array)($w->user_state()['habits'] ?? []);
        $today = date('Y-m-d');
        $wd = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
        $h = '<table class="hb"><thead><tr><th></th>';
        for ($i = 6; $i >= 0; $i--) { $ts = strtotime("-$i day"); $h .= '<th class="' . ($i === 0 ? 'today' : '') . '">' . $wd[(int)date('w', $ts)] . '</th>'; }
        $h .= '<th title="Serie">🔥</th></tr></thead><tbody>';
        foreach ($hs as $x) {
            $set = array_flip((array)$x['days']);
            $h .= '<tr><td class="hb-n">' . e($x['icon'] . ' ' . $x['name']) . '<button type="button" class="hb-x" data-wg-action="delete" data-id="' . e($x['id']) . '" title="Entfernen" aria-label="Entfernen">×</button></td>';
            for ($i = 6; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-$i day"));
                $h .= '<td><button type="button" class="hb-c' . (isset($set[$d]) ? ' on' : '') . '" data-wg-action="toggle" data-id="' . e($x['id']) . '" data-day="' . $d . '" aria-label="' . e($x['name'] . ' am ' . date('d.m.', strtotime($d))) . '">' . (isset($set[$d]) ? '✓' : '') . '</button></td>';
            }
            $st = hb_streak((array)$x['days'], $today);
            $h .= '<td class="hb-s" title="Bestwert ' . (int)($x['best'] ?? 0) . '">' . $st . '</td></tr>';
        }
        $h .= '</tbody></table>';
        if (!$hs) $h = '<div class="wg-empty">Lege ein tägliches Ziel an – z. B. „💧 2 l Wasser“.</div>';
        else {
            $done = count(array_filter($hs, fn($x) => in_array($today, (array)$x['days'], true)));
            $h .= '<div class="wg-muted">Heute ' . $done . ' / ' . count($hs) . ' erledigt' . ($done === count($hs) ? ' 🎉' : '') . '</div>';
        }
        if (count($hs) < 12) $h .= '<form class="hb-add" data-wg-action="add"><input type="text" name="name" maxlength="40" placeholder="Neues Ziel … (Emoji vorne möglich)" required><button class="btn btn-sm">＋</button></form>';
        return $h;
    },
    'action' => function (WidgetCtx $w, string $op, array $in): ?string {
        if (!is_logged_in()) throw new RuntimeException('Bitte anmelden.');
        $s = $w->user_state();
        $hs = (array)($s['habits'] ?? []);
        $id = (string)($in['id'] ?? '');
        if ($op === 'add' && count($hs) < 12) {
            $n = trim(mb_substr((string)($in['name'] ?? ''), 0, 40));
            $icon = '✅';
            if (preg_match('/^(\X)\s+(.+)$/u', $n, $m) && !preg_match('/^[\p{L}\p{N}]/u', $m[1])) { $icon = $m[1]; $n = $m[2]; }
            if ($n !== '') $hs[] = ['id' => bin2hex(random_bytes(4)), 'name' => $n, 'icon' => $icon, 'days' => [], 'best' => 0];
        }
        foreach ($hs as &$x) {
            if ($x['id'] !== $id || $op !== 'toggle') continue;
            $day = (string)($in['day'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || $day > date('Y-m-d') || $day < date('Y-m-d', strtotime('-6 day'))) throw new RuntimeException('Nur die letzten 7 Tage.');
            $x['days'] = in_array($day, $x['days'], true) ? array_values(array_diff($x['days'], [$day])) : array_merge($x['days'], [$day]);
            sort($x['days']);
            $x['days'] = array_slice($x['days'], -400);
            $x['best'] = max((int)$x['best'], hb_streak($x['days'], date('Y-m-d')));
        }
        unset($x);
        if ($op === 'delete') $hs = array_values(array_filter($hs, fn($x) => $x['id'] !== $id));
        $s['habits'] = $hs;
        $w->save_user_state($s);
        return null;
    },
];
