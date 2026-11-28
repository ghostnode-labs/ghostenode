<?php
/**
 * Widget-System (Plug & Play).
 *
 * Jedes Widget ist ein eigener Ordner unter widgets/<name>/ mit einer Datei widget.php, die ein Array zurückgibt
 * (Name, Icon, Einstellungen, render-Funktion …). Optional: widget.css und widget.js im selben Ordner – sie werden
 * automatisch geladen. Ordner, die mit „_“ beginnen (z. B. widgets/_vorlage), werden ignoriert.
 * Neues Widget: widgets/_vorlage kopieren, umbenennen, anpassen – es erscheint sofort unter „＋ Widget“.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/format.php';

const WIDGETS_DIR = APP_ROOT . '/widgets';
const WIDGET_SIZES = ['s' => 'klein', 'm' => 'mittel', 'l' => 'breit', 'xl' => 'ganze Zeile'];
/** 9.0: Gruppen fürs Dashboard (Filter-Leiste). Ein Widget kann im Manifest 'group' => '…' setzen, sonst gilt diese Zuordnung. */
const WIDGET_GROUPS = ['overview' => ['🧭', 'Übersicht'], 'plan' => ['📅', 'Planung'], 'personal' => ['📝', 'Persönlich'], 'projects' => ['🚀', 'Projekte'],
                       'community' => ['🫂', 'Community'], 'system' => ['🛠', 'System'], 'fun' => ['🎲', 'Freizeit']];
const WIDGET_GROUP_DEFAULT = [
    'clock' => 'overview', 'weather' => 'overview', 'quote' => 'overview', 'progress' => 'overview', 'block' => 'overview', 'buttons' => 'overview', 'quicklinks' => 'overview', 'sunmoon' => 'overview', 'notify' => 'overview',
    'calendar' => 'plan', 'countdowns' => 'plan', 'todo' => 'plan', 'con' => 'plan', 'budget' => 'plan', 'currency' => 'plan', 'pomodoro' => 'plan', 'habits' => 'plan',
    'notes' => 'personal', 'notebook' => 'personal', 'bookmarks' => 'personal', 'snippets' => 'personal', 'accountcheck' => 'personal', 'photo' => 'personal',
    'nexus' => 'projects', 'releases' => 'projects', 'dlstats' => 'projects', 'market' => 'projects', 'services' => 'projects',
    'vrchat' => 'community', 'vrclive' => 'community', 'today' => 'community', 'discord' => 'community', 'feed' => 'community', 'rss' => 'community',
    'system' => 'system', 'adminq' => 'system', 'online' => 'system', 'auditlive' => 'system',
    'boop' => 'fun', 'random' => 'fun',
];

/** Kontext, den jedes Widget beim Rendern bekommt */
final class WidgetCtx {
    public string $uid;
    public string $type;
    public array $cfg;
    public array $manifest;
    public string $url;   // Web-Pfad des Widget-Ordners (für Bilder o. Ä.)

    public function __construct(string $uid, string $type, array $cfg, array $manifest) {
        $this->uid = $uid;
        $this->type = $type;
        $this->manifest = $manifest;
        $this->url = 'widgets/' . rawurlencode($type) . '/';
        $defaults = [];
        foreach ((array)($manifest['settings'] ?? []) as $k => $s) $defaults[$k] = $s['default'] ?? '';
        $this->cfg = array_intersect_key($cfg, $defaults) + $defaults;
    }

    /** Gespeicherter Zustand dieser Widget-Instanz (z. B. Aufgaben, Notiztext, Cache) */
    public function state(): array {
        $v = json_decode(setting('widget_state_' . $this->uid, '{}'), true);
        return is_array($v) ? $v : [];
    }

    public function save_state(array $state): void {
        set_setting('widget_state_' . $this->uid, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** 9.0: privater Inhalt dieser Instanz für das angemeldete Konto (Notizbuch, Lesezeichen …) – jedes Konto sieht nur seinen eigenen */
    public function user_state(): array {
        $u = current_user();
        if (!$u) return [];
        $v = json_decode(user_pref((int)$u['id'], 'ws:' . $this->uid, '{}'), true);
        return is_array($v) ? $v : [];
    }

    public function save_user_state(array $state): void {
        $u = current_user();
        if (!$u) throw new RuntimeException('Bitte anmelden.');
        user_pref_set((int)$u['id'], 'ws:' . $this->uid, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Darf der aktuelle Benutzer Inhalte dieses Widgets ändern? (Standard: Recht „pages.edit“) */
    public function can_edit(): bool {
        return widgets_mine() || can($this->manifest['edit_permission'] ?? 'pages.edit');   // eigenes Dashboard: eigene Inhalte
    }

    /** Daten von einer Adresse holen und für $ttl Sekunden zwischenspeichern */
    public function cached_json(string $url, int $ttl = 1800, array $headers = []) {
        $key = 'widget_cache_' . md5($url);
        $c = json_decode(setting($key, ''), true);
        if (is_array($c) && ($c['t'] ?? 0) > time() - $ttl) return $c['d'];
        if (!function_exists('curl_init')) throw new RuntimeException('cURL fehlt auf dem Server.');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 12, CURLOPT_FOLLOWLOCATION => true,
                                CURLOPT_USERAGENT => 'GhostNodeDashboard/1.0 (widget)', CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers)]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $d = $raw !== false && $code < 400 ? json_decode((string)$raw, true) : null;
        if ($d === null) {
            if (is_array($c)) return $c['d'];   // alte Daten sind besser als keine
            throw new RuntimeException('Dienst nicht erreichbar (HTTP ' . $code . ').');
        }
        set_setting($key, json_encode(['t' => time(), 'd' => $d], JSON_UNESCAPED_UNICODE));
        return $d;
    }
}

/** Alle installierten Widgets: Typ => Manifest (mit 'error', falls die Datei kaputt ist) */
function widgets_available(): array {
    static $list = null;
    if ($list !== null) return $list;
    $list = [];
    foreach (glob(WIDGETS_DIR . '/*/widget.php') ?: [] as $file) {
        $type = basename(dirname($file));
        if ($type[0] === '_' || $type[0] === '.' || !preg_match('/^[a-z0-9_-]{1,40}$/', $type)) continue;
        try {
            $m = (function () use ($file) { return include $file; })();
            if (!is_array($m) || !is_callable($m['render'] ?? null)) throw new RuntimeException('widget.php muss ein Array mit einer render-Funktion zurückgeben.');
        } catch (Throwable $e) {
            $m = ['name' => $type, 'icon' => '⚠️', 'description' => 'Fehler: ' . $e->getMessage(), 'error' => $e->getMessage(), 'render' => null];
        }
        $dir = dirname($file);
        $m += ['name' => $type, 'icon' => '🧩', 'description' => '', 'version' => '1.0', 'author' => '', 'size' => 's', 'permission' => null, 'login' => false,
               'refresh' => 0, 'settings' => [], 'action' => null];
        $m['has_css'] = is_file($dir . '/widget.css');
        $m['has_js'] = is_file($dir . '/widget.js');
        $m['mtime'] = max(filemtime($file), $m['has_css'] ? filemtime($dir . '/widget.css') : 0, $m['has_js'] ? filemtime($dir . '/widget.js') : 0);
        $list[$type] = $m;
    }
    uasort($list, function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
    return $list;
}

/** Darf der aktuelle Besucher dieses Widget sehen? */
function widget_allowed(array $m): bool {
    if (!empty($m['error'])) return can('widgets.manage');
    if (!empty($m['login']) && !is_logged_in()) return false;
    if (!empty($m['permission']) && !can($m['permission'])) return false;
    return true;
}

/** Baukasten: eigenes Dashboard dieses Kontos an? (sonst gilt die gemeinsame Anordnung) */
function widgets_mine(): bool {
    $u = current_user();
    return $u && setting('widgets_mine_u' . (int)$u['id'], '0') === '1';
}
function widgets_mine_set(bool $on): void {
    $u = current_user();
    if (!$u) throw new RuntimeException('Bitte anmelden.');
    $id = (int)$u['id'];
    if ($on && setting('widgets_layout_u' . $id, '') === '') {   // Start: Kopie der gemeinsamen Anordnung (gleiche Widgets, gleicher Inhalt)
        set_setting('widgets_mine_u' . $id, '0');
        // eigene IDs: Notizen, Aufgaben usw. im eigenen Dashboard sind getrennt vom gemeinsamen Inhalt
        $copy = array_map(function ($w) { $w['uid'] = substr(md5($w['uid'] . random_bytes(6)), 0, 10); return $w; }, widgets_layout());
        set_setting('widgets_layout_u' . $id, json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    set_setting('widgets_mine_u' . $id, $on ? '1' : '0');
}
/** Darf der Besucher die gerade gültige Anordnung ändern? */
function widgets_can_arrange(): bool { return widgets_mine() || can('widgets.manage'); }
/** Sichtbarkeit einer Instanz (Einstellung „Wer sieht das?“) */
function widget_visible(array $inst): bool {
    $v = (string)($inst['cfg']['_vis'] ?? 'all');
    if ($v === 'login') return is_logged_in();
    if ($v === 'guests') return !is_logged_in();
    return true;
}

/** Anordnung auf der Startseite (gemeinsam, bearbeitbar mit „widgets.manage“ – oder das eigene Dashboard des Kontos) */
function widgets_layout(): array {
    if (widgets_mine()) {
        $l = json_decode(setting('widgets_layout_u' . (int)current_user()['id'], '[]'), true);
        return is_array($l) ? array_values(array_filter($l, function ($w) { return isset($w['uid'], $w['type']); })) : [];
    }
    $raw = setting('widgets_layout', '');
    if ($raw === '') {
        // Erster Start: alle mitgelieferten Widgets in sinnvoller Reihenfolge
        $order = ['clock', 'weather', 'countdowns', 'calendar', 'todo', 'notes', 'budget', 'vrchat', 'feed', 'quicklinks'];
        $avail = widgets_available();
        $layout = [];
        foreach ($order as $t) {   // weitere Widgets nur über „＋ Widget“
            if (!isset($avail[$t]) || !empty($avail[$t]['error']) || in_array($t, array_column($layout, 'type'), true)) continue;
            $layout[] = ['uid' => substr(md5($t . random_bytes(4)), 0, 10), 'type' => $t, 'size' => $avail[$t]['size'], 'cfg' => []];
        }
        widgets_layout_save($layout);
        return $layout;
    }
    $l = json_decode($raw, true);
    return is_array($l) ? array_values(array_filter($l, function ($w) { return isset($w['uid'], $w['type']); })) : [];
}

function widgets_layout_save(array $layout): void {
    if (widgets_mine()) { set_setting('widgets_layout_u' . (int)current_user()['id'], json_encode(array_values($layout), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); return; }
    set_setting('widgets_layout', json_encode(array_values($layout), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function widget_find(string $uid): ?array {
    foreach (widgets_layout() as $w) if ($w['uid'] === $uid) return $w;
    return null;
}

function widget_ctx(array $inst): ?WidgetCtx {
    $m = widgets_available()[$inst['type']] ?? null;
    return $m ? new WidgetCtx($inst['uid'], $inst['type'], (array)($inst['cfg'] ?? []), $m) : null;
}

/** Gruppe eines Widget-Typs (für die Filter-Leiste) */
function widget_group(string $type, ?array $m = null): string {
    $g = (string)($m['group'] ?? '');
    if (isset(WIDGET_GROUPS[$g])) return $g;
    return WIDGET_GROUP_DEFAULT[$type] ?? 'overview';
}

// ---------- 9.0: persönliche Dashboard-Ansicht (Reihenfolge, ausgeblendet, eingeklappt – je Konto) ----------

/** Persönliche Ansicht: ['order' => [uid…], 'hidden' => [uid…], 'min' => [uid…], 'group' => ''] */
function widgets_prefs(): array {
    $u = current_user();
    $d = ['order' => [], 'hidden' => [], 'min' => [], 'group' => ''];
    if (!$u) return $d;
    $j = json_decode(user_pref((int)$u['id'], 'dash', ''), true);
    return is_array($j) ? widgets_prefs_clean($j) : $d;
}

/** Eingaben prüfen (nur IDs, begrenzte Länge) */
function widgets_prefs_clean(array $in): array {
    $ids = function ($v) { return array_values(array_unique(array_slice(array_filter(array_map(function ($x) { return preg_replace('/\W/', '', (string)$x); }, (array)$v), 'strlen'), 0, 80))); };
    $g = (string)($in['group'] ?? '');
    return ['order' => $ids($in['order'] ?? []), 'hidden' => $ids($in['hidden'] ?? []), 'min' => $ids($in['min'] ?? []), 'group' => isset(WIDGET_GROUPS[$g]) ? $g : ''];
}

function widgets_prefs_save(array $p): array {
    $u = current_user();
    if (!$u) throw new RuntimeException('Bitte anmelden – die persönliche Anordnung wird im Konto gespeichert.');
    $p = widgets_prefs_clean($p);
    user_pref_set((int)$u['id'], 'dash', json_encode($p, JSON_UNESCAPED_SLASHES));
    return $p;
}

/** Anordnung in der persönlichen Reihenfolge; neue Widgets (noch nicht in „order“) behalten ihre Stelle relativ zum Original */
function widgets_apply_prefs(array $layout, array $prefs): array {
    if (!$prefs['order']) return $layout;
    $pos = array_flip($prefs['order']);
    $keyed = [];
    foreach (array_values($layout) as $i => $w) $keyed[] = [$pos[$w['uid']] ?? null, $i, $w];
    // bekannte nach persönlicher Reihenfolge, unbekannte hinter ihren Vorgänger im Original
    $known = array_values(array_filter($keyed, function ($k) { return $k[0] !== null; }));
    usort($known, function ($a, $b) { return $a[0] <=> $b[0]; });
    $out = array_map(function ($k) { return $k[2]; }, $known);
    foreach ($keyed as $k) {
        if ($k[0] !== null) continue;
        $at = count($out);
        if ($k[1] > 0) { $prev = $layout[$k[1] - 1]['uid'] ?? ''; foreach ($out as $j => $w) if ($w['uid'] === $prev) { $at = $j + 1; break; } }
        array_splice($out, $at, 0, [$k[2]]);
    }
    return $out;
}

/** Inhalt eines Widgets (Fehler werden im Widget angezeigt, nie die ganze Seite) */
function widget_body(array $inst): string {
    $m = widgets_available()[$inst['type']] ?? null;
    if (!$m) return '<div class="wg-error">Widget „' . e($inst['type']) . '“ ist nicht (mehr) installiert. Ordner <code>widgets/' . e($inst['type']) . '</code> fehlt.</div>';
    if (!empty($m['error'])) return '<div class="wg-error">⚠ ' . e($m['error']) . '</div>';
    try {
        ob_start();
        $html = ($m['render'])(widget_ctx($inst));
        $echoed = ob_get_clean();
        return $echoed . (string)$html;
    } catch (Throwable $e) {
        if (ob_get_level()) ob_end_clean();
        return '<div class="wg-error">⚠ ' . e($e->getMessage()) . '</div>';
    }
}

/** Komplette Widget-Karte */
function widget_card(array $inst, bool $edit, array $flags = []): string {
    $m = widgets_available()[$inst['type']] ?? ['name' => $inst['type'], 'icon' => '❔', 'refresh' => 0, 'settings' => []];
    $title = trim((string)($inst['cfg']['_title'] ?? '')) ?: $m['name'];
    $size = isset(WIDGET_SIZES[$inst['size'] ?? '']) ? $inst['size'] : ($m['size'] ?? 's');
    $group = widget_group($inst['type'], $m);
    $h = '<section class="wg wg-' . e($size) . ' wg-type-' . e($inst['type']) . (!empty($flags['hidden']) ? ' is-off' : '') . (!empty($flags['min']) ? ' min' : '') . '" data-uid="' . e($inst['uid']) . '" data-type="' . e($inst['type']) . '" data-group="' . e($group) . '" data-name="' . e($title) . '" data-icon="' . e($m['icon']) . '" data-refresh="' . (int)($m['refresh'] ?? 0) . '"'
        . ($edit ? ' draggable="true"' : '') . (!empty($flags['hidden']) ? ' hidden' : '') . '>';
    $h .= '<header class="wg-head"><span class="wg-icon">' . e($m['icon']) . '</span><h3>' . e($title) . '</h3>';
    if ($edit) {
        $h .= '<span class="wg-tools"><span class="wg-drag" title="Ziehen zum Sortieren">⠿</span>'
            . '<button type="button" class="ic" data-wg-size title="Größe ändern (' . e(WIDGET_SIZES[$size]) . ')">⇔</button>'
            . '<button type="button" class="ic" data-wg-settings title="Einstellungen">⚙</button>'
            . '<button type="button" class="ic danger" data-wg-remove title="Entfernen">✕</button></span>';
    } else {
        $h .= '<span class="wg-tools wg-own"><span class="wg-drag" title="Ziehen zum Sortieren (nur für dich)">⠿</span>'
            . '<button type="button" class="ic" data-wg-hide title="Für mich ausblenden" aria-label="Ausblenden">👁</button></span>'
            . '<button type="button" class="wg-min" title="Einklappen" aria-label="Einklappen">–</button>';
    }
    // ausgeblendete Widgets erst beim Einblenden laden (spart Abrufe fremder Dienste)
    $h .= '</header><div class="wg-body"' . (!empty($flags['hidden']) ? ' data-lazy="1">' : '>' . widget_body($inst)) . '</div></section>';
    return $h;
}

/** Einstellungs-Schema für den Dialog im Bearbeiten-Modus */
function widget_settings_schema(array $m): array {
    $s = ['_title' => ['label' => 'Eigener Titel (leer = Standard)', 'type' => 'text', 'default' => ''],
          '_vis' => ['label' => 'Wer sieht das?', 'type' => 'select', 'default' => 'all', 'options' => ['all' => '🌍 alle', 'login' => '🔒 nur Angemeldete', 'guests' => '👋 nur Gäste (nicht angemeldet)']]];
    foreach ((array)($m['settings'] ?? []) as $k => $d) $s[$k] = $d + ['type' => 'text', 'label' => $k, 'default' => ''];
    return $s;
}

/** Eingaben eines Einstellungs-Dialogs prüfen */
function widget_clean_cfg(array $m, array $in): array {
    $out = [];
    foreach (widget_settings_schema($m) as $k => $d) {
        $v = $in[$k] ?? null;
        switch ($d['type']) {
            case 'checkbox': $out[$k] = !empty($v); break;
            case 'number': $out[$k] = max($d['min'] ?? -1e9, min($d['max'] ?? 1e9, (float)($v ?? $d['default']))); break;
            case 'select': $out[$k] = array_key_exists((string)$v, (array)$d['options']) ? (string)$v : $d['default']; break;
            case 'textarea': $out[$k] = mb_substr((string)$v, 0, 5000); break;
            default: $out[$k] = mb_substr(trim((string)$v), 0, 300);
        }
    }
    return $out;
}
