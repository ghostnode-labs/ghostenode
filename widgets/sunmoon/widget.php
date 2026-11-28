<?php
// 9.0: Sonne & Mond – Sonnenauf-/untergang, Tageslänge, Mondphase (rein rechnerisch, ohne fremden Dienst)
if (!function_exists('sm_moon')) {
    /** Mondphase 0…1 (0 = Neumond, 0.5 = Vollmond) nach mittlerem synodischem Monat ab bekanntem Neumond 06.01.2000 18:14 UTC */
    function sm_moon(int $ts): float {
        $syn = 29.530588853;
        $days = ($ts - 947182440) / 86400;
        $p = fmod($days / $syn, 1.0);
        return $p < 0 ? $p + 1 : $p;
    }
    function sm_moon_name(float $p): array {
        $names = [[0.0333, '🌑', 'Neumond'], [0.2167, '🌒', 'zunehmende Sichel'], [0.2833, '🌓', 'erstes Viertel'], [0.4667, '🌔', 'zunehmender Mond'], [0.5333, '🌕', 'Vollmond'],
                  [0.7167, '🌖', 'abnehmender Mond'], [0.7833, '🌗', 'letztes Viertel'], [0.9667, '🌘', 'abnehmende Sichel'], [1.01, '🌑', 'Neumond']];
        foreach ($names as [$lim, $ic, $n]) if ($p < $lim) return [$ic, $n];
        return ['🌑', 'Neumond'];
    }
    /** Tage bis zur nächsten Phase $target (0 Neumond, 0.5 Vollmond) */
    function sm_days_to(float $p, float $target): float { $d = $target - $p; if ($d <= 0.001) $d += 1; return $d * 29.530588853; }
}
return [
    'name' => 'Sonne & Mond',
    'icon' => '🌗',
    'description' => 'Sonnenaufgang, Sonnenuntergang, Tageslänge und Mondphase mit nächstem Voll-/Neumond – für einen frei wählbaren Ort, ohne fremden Dienst.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'refresh' => 1800,
    'settings' => [
        'place' => ['label' => 'Ortsname (nur Anzeige)', 'type' => 'text', 'default' => 'Berlin'],
        'lat' => ['label' => 'Breitengrad', 'type' => 'number', 'default' => 52.52, 'min' => -66, 'max' => 66],
        'lon' => ['label' => 'Längengrad', 'type' => 'number', 'default' => 13.405, 'min' => -180, 'max' => 180],
    ],
    'render' => function (WidgetCtx $w): string {
        $now = time();
        $sun = date_sun_info(strtotime('today 12:00'), (float)$w->cfg['lat'], (float)$w->cfg['lon']);
        $rise = is_int($sun['sunrise'] ?? null) ? $sun['sunrise'] : null; $set = is_int($sun['sunset'] ?? null) ? $sun['sunset'] : null;
        $len = $rise && $set ? $set - $rise : 0;
        $prog = $rise && $set ? max(0, min(100, ($now - $rise) / max(1, $len) * 100)) : 0;
        $p = sm_moon($now);
        [$mi, $mn] = sm_moon_name($p);
        $illum = (int)round((1 - cos($p * 2 * M_PI)) / 2 * 100);
        $h = '<div class="sm-sun"><div class="sm-arc" style="--p:' . round($prog, 1) . '"><i></i></div><div class="sm-times"><span>🌅 ' . ($rise ? date('H:i', $rise) : '–') . '</span><span>' . ($len ? intdiv($len, 3600) . ' h ' . sprintf('%02d', intdiv($len % 3600, 60)) . ' min Tag' : '') . '</span><span>🌇 ' . ($set ? date('H:i', $set) : '–') . '</span></div></div>';
        $h .= '<div class="sm-moon"><span class="sm-mi">' . $mi . '</span><div><b>' . e($mn) . '</b><small>' . $illum . ' % beleuchtet</small><small>🌕 in ' . round(sm_days_to($p, 0.5)) . ' T · 🌑 in ' . round(sm_days_to($p, 0.0)) . ' T</small></div></div>';
        return $h . '<div class="wg-muted">📍 ' . e($w->cfg['place']) . '</div>';
    },
];
