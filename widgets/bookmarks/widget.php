<?php
// 9.0: Private Lesezeichen mit Ordnern – je Konto, Import aus dem Browser (Lesezeichen-Export als HTML), Filter
if (!function_exists('bm_import_html')) {
    /** Browser-Lesezeichen-Export (Netscape-Format, Chrome/Firefox/Edge) → [[folder, title, url]] – nur http(s) */
    function bm_import_html(string $html, int $max = 500): array {
        $out = []; $stack = []; $pending = '';
        if (!preg_match_all('#<H3[^>]*>(.*?)</H3>|<A\s[^>]*HREF="([^"]+)"[^>]*>(.*?)</A>|</DL>#is', $html, $m, PREG_SET_ORDER)) return [];
        foreach ($m as $x) {
            if (($x[1] ?? '') !== '') { $stack[] = trim(html_entity_decode(strip_tags($x[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')); continue; }
            if (stripos($x[0], '</DL>') === 0) { array_pop($stack); continue; }
            $url = html_entity_decode((string)$x[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!preg_match('#^https?://[^\s<>"]+$#i', $url) || strlen($url) > 500) continue;
            $title = trim(html_entity_decode(strip_tags((string)$x[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: (parse_url($url, PHP_URL_HOST) ?: $url);
            $folder = (string)(end($stack) ?: 'Importiert');
            if (in_array(mb_strtolower($folder), ['lesezeichenleiste', 'bookmarks bar', 'bookmarks toolbar', 'lesezeichen-symbolleiste', 'favoritenleiste'], true)) $folder = 'Leiste';
            $out[] = [mb_substr($folder, 0, 40), mb_substr($title, 0, 100), $url];
            if (count($out) >= $max) break;
        }
        return $out;
    }
}
return [
    'name' => 'Lesezeichen (privat)',
    'icon' => '🔖',
    'description' => 'Eigene Lesezeichen in Ordnern – nur für dich sichtbar. Mit Filter und Import des Lesezeichen-Exports aus Chrome, Firefox oder Edge.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'login' => true,
    'settings' => [
        'icons' => ['label' => 'Website-Symbole laden (über DuckDuckGo)', 'type' => 'checkbox', 'default' => false],
    ],
    'render' => function (WidgetCtx $w): string {
        $items = (array)($w->user_state()['items'] ?? []);
        $folders = [];
        foreach ($items as $b) $folders[$b['folder']][] = $b;
        ksort($folders, SORT_NATURAL | SORT_FLAG_CASE);
        $h = $items ? '<input type="search" class="bm-find" placeholder="🔍 ' . count($items) . ' Lesezeichen filtern …" aria-label="Lesezeichen filtern">' : '<div class="wg-empty">Noch keine Lesezeichen. Nur du siehst sie.</div>';
        foreach ($folders as $f => $list) {
            $h .= '<details class="bm-f" ' . (count($folders) <= 3 ? 'open' : '') . '><summary>📁 ' . e($f) . ' <small>' . count($list) . '</small></summary><ul class="bm-l">';
            foreach ($list as $b) {
                $host = (string)parse_url($b['url'], PHP_URL_HOST);
                $h .= '<li data-find="' . e(mb_strtolower($b['title'] . ' ' . $b['url'] . ' ' . $f)) . '">'
                    . ($w->cfg['icons'] && $host !== '' ? '<img src="https://icons.duckduckgo.com/ip3/' . e(rawurlencode($host)) . '.ico" alt="" width="14" height="14" loading="lazy" referrerpolicy="no-referrer">' : '<span>🔗</span>')
                    . '<a href="' . e($b['url']) . '" target="_blank" rel="noopener noreferrer" title="' . e($b['url']) . '">' . e($b['title']) . '</a>'
                    . '<button type="button" class="bm-x" data-wg-action="delete" data-id="' . e($b['id']) . '" aria-label="Entfernen">×</button></li>';
            }
            $h .= '</ul></details>';
        }
        $h .= '<details class="bm-new"><summary>＋ Lesezeichen / Import</summary><form data-wg-action="add" class="bm-form"><input type="url" name="url" placeholder="https://…" required maxlength="500">'
            . '<input type="text" name="title" placeholder="Titel (optional)" maxlength="100"><input type="text" name="folder" placeholder="Ordner (optional)" maxlength="40" list="bmf-' . e($w->uid) . '">'
            . '<datalist id="bmf-' . e($w->uid) . '">' . implode('', array_map(fn($f) => '<option value="' . e($f) . '">', array_keys($folders))) . '</datalist><button class="btn btn-sm btn-gold">Speichern</button></form>'
            . '<label class="btn btn-sm bm-imp">⬆ Browser-Export (.html) importieren<input type="file" accept=".html,.htm,text/html" hidden></label></details>';
        return $h;
    },
    'action' => function (WidgetCtx $w, string $op, array $in): ?string {
        if (!is_logged_in()) throw new RuntimeException('Bitte anmelden.');
        $s = $w->user_state();
        $items = (array)($s['items'] ?? []);
        $known = array_flip(array_column($items, 'url'));
        $msg = null;
        $add = function (string $folder, string $title, string $url) use (&$items, &$known): bool {
            if (isset($known[$url]) || count($items) >= 1000) return false;
            $items[] = ['id' => bin2hex(random_bytes(5)), 'folder' => $folder, 'title' => $title, 'url' => $url];
            $known[$url] = 1;
            return true;
        };
        if ($op === 'add') {
            $url = trim((string)($in['url'] ?? ''));
            if (!preg_match('#^https?://[^\s<>"]+$#i', $url) || strlen($url) > 500) throw new RuntimeException('Bitte eine gültige http(s)-Adresse.');
            $title = trim(mb_substr((string)($in['title'] ?? ''), 0, 100)) ?: ((string)parse_url($url, PHP_URL_HOST) ?: $url);
            $folder = trim(mb_substr((string)($in['folder'] ?? ''), 0, 40)) ?: 'Allgemein';
            $msg = $add($folder, $title, $url) ? 'Gespeichert.' : 'Gibt es schon.';
        }
        if ($op === 'import') {
            $html = (string)($in['html'] ?? '');
            if (strlen($html) > 3000000) throw new RuntimeException('Datei zu groß (max. 3 MB).');
            $n = 0;
            foreach (bm_import_html($html) as [$f, $t, $u]) if ($add($f, $t, $u)) $n++;
            $msg = $n . ' Lesezeichen importiert.';
        }
        if ($op === 'delete') $items = array_values(array_filter($items, fn($b) => $b['id'] !== (string)($in['id'] ?? '')));
        $s['items'] = array_values($items);
        $w->save_user_state($s);
        return $msg;
    },
];
