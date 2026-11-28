<?php
/**
 * Admin-Zentrale – Bereich „⭐ VIP-Listen“ (Recht vip.manage, standardmäßig nur Administratoren):
 * Listen je Welt, Einträge (Gruppe, Notiz, Ablauf), Ko-fi-Webhook, Gist-Spiegel. Logik: inc/modules/vip.php.
 */
require_once __DIR__ . '/../modules/vip.php';

const VIP_ADMIN_TABS = ['vip' => ['⭐', 'VIP-Listen', 'vip.manage']];
const VIP_ADMIN_ACTIONS = ['vip_list_save', 'vip_list_delete', 'vip_entry_save', 'vip_entry_delete', 'vip_entry_approve', 'vip_kofi_settings', 'vip_gist_settings', 'vip_gist_sync', 'vip_list_move', 'vip_list_sort', 'vip_entry_move', 'vip_kofi_test', 'vip_kofi_import', 'vip_kofi_log_clear'];

function admin_vip_post(string $act, array $in): string {
    vip_schema();
    switch ($act) {
        case 'vip_list_save':
            $id = vip_list_save($in);
            $_SESSION['vip_list'] = $id;
            audit('VIP-Liste gespeichert', (string)(vip_list($id)['slug'] ?? $id));
            return 'Liste gespeichert.';
        case 'vip_list_delete':
            $l = vip_list((int)($in['id'] ?? 0));
            if (!$l) throw new InvalidArgumentException('Liste nicht gefunden.');
            if (($in['confirm'] ?? '') !== $l['slug']) throw new InvalidArgumentException('Zum Löschen bitte die Kurzadresse „' . $l['slug'] . '“ eintippen.');
            vip_list_delete((int)$l['id']);
            unset($_SESSION['vip_list']);
            audit('VIP-Liste gelöscht', $l['slug'], 'warn');
            return 'Liste gelöscht.';
        case 'vip_entry_save':
            $lid = (int)($in['list'] ?? 0);
            $_SESSION['vip_list'] = $lid;
            $old = (int)($in['id'] ?? 0) > 0 ? vip_entry((int)$in['id']) : null;
            if ($old && (int)$old['list_id'] !== $lid) throw new InvalidArgumentException('Eintrag gehört zu einer anderen Liste.');
            vip_entry_save($lid, ['id' => (int)($in['id'] ?? 0), 'name' => (string)($in['name'] ?? ''), 'grp' => (string)($in['grp'] ?? ''), 'note' => (string)($in['note'] ?? ''),
                'expires' => vip_parse_date((string)($in['expires'] ?? '')), 'status' => $old ? (string)$old['status'] : 'ok', 'source' => $old ? (string)$old['source'] : 'admin']);
            audit('VIP-Eintrag gespeichert', vip_clean_name((string)($in['name'] ?? '')));
            return $old ? 'Eintrag gespeichert.' : 'Eintrag hinzugefügt.';
        case 'vip_list_move':
            vip_list_move((int)($in['id'] ?? 0), (int)($in['dir'] ?? 1));
            $_SESSION['vip_list'] = (int)($in['id'] ?? 0);
            return 'Reihenfolge der Reiter geändert.';
        case 'vip_list_sort':
            $l = vip_list((int)($in['id'] ?? 0));
            if (!$l) throw new InvalidArgumentException('Liste nicht gefunden.');
            $sort = (string)($in['sort'] ?? '');
            if (!isset(VIP_SORTS[$sort])) throw new InvalidArgumentException('Unbekannte Sortierung.');
            vip_list_save(['id' => (int)$l['id'], 'slug' => $l['slug'], 'title' => $l['title'], 'world' => $l['world'], 'grp' => $l['grp'], 'kofi' => (int)$l['kofi'], 'gist' => (int)$l['gist'], 'sort' => $sort]);
            $_SESSION['vip_list'] = (int)$l['id'];
            return 'Sortierung: ' . VIP_SORTS[$sort] . '.';
        case 'vip_entry_move':
            $en = vip_entry((int)($in['id'] ?? 0));
            if (!$en) throw new InvalidArgumentException('Eintrag nicht gefunden.');
            vip_entry_move((int)$en['id'], (int)($in['dir'] ?? 1));
            $_SESSION['vip_list'] = (int)$en['list_id'];
            return 'Reihenfolge geändert.';
        case 'vip_kofi_test':
            $_SESSION['vip_list'] = 'settings';
            if ($p = vip_kofi_problems()) throw new InvalidArgumentException(implode(' ', $p));
            [$ok, $txt] = vip_kofi_selftest();
            if (!$ok) throw new InvalidArgumentException($txt);
            return $txt;
        case 'vip_kofi_log_clear':
            $_SESSION['vip_list'] = 'settings';
            set_setting('vip_kofi_log', '[]');
            return 'Protokoll geleert.';
        case 'vip_kofi_import':
            $_SESSION['vip_list'] = 'settings';
            $csv = (string)($in['csv'] ?? '');
            $f = $_FILES['csvfile'] ?? null;
            if (is_array($f) && ($f['error'] ?? 4) === UPLOAD_ERR_OK && is_uploaded_file((string)$f['tmp_name'])) {
                if ((int)$f['size'] > 2 * 1024 * 1024) throw new InvalidArgumentException('Die CSV ist zu groß (max. 2 MB).');
                $csv = (string)file_get_contents((string)$f['tmp_name']);
            }
            if (trim($csv) === '') throw new InvalidArgumentException('Bitte eine CSV-Datei wählen oder den Inhalt einfügen.');
            $r = vip_kofi_import_csv($csv, (int)($in['list'] ?? 0));
            audit('VIP-Listen: Ko-fi-Import', $r['added'] . ' neu, ' . $r['updated'] . ' verlängert');
            return 'Ko-fi-Import: ' . $r['added'] . ' neu, ' . $r['updated'] . ' verlängert, ' . $r['skipped'] . ' übersprungen (abgelaufen oder schon aktuell).';
        case 'vip_entry_delete':
        case 'vip_entry_approve':
            $en = vip_entry((int)($in['id'] ?? 0));
            if (!$en) throw new InvalidArgumentException('Eintrag nicht gefunden.');
            $_SESSION['vip_list'] = (int)$en['list_id'];
            if ($act === 'vip_entry_delete') { vip_entry_delete((int)$en['id']); audit('VIP-Eintrag entfernt', $en['name']); return 'Eintrag entfernt.'; }
            vip_entry_approve((int)$en['id']); audit('VIP-Eintrag freigegeben', $en['name']);
            return 'Eintrag freigegeben.';
        case 'vip_kofi_settings':
            set_setting('vip_kofi_review', !empty($in['review']) ? '1' : '0');
            set_setting('vip_kofi_group', vip_clean_grp((string)($in['group'] ?? ''), 'KOFI'));
            set_setting('vip_kofi_sub_days', (string)max(1, min(400, (int)($in['sub_days'] ?? 35))));
            set_setting('vip_kofi_don_days', (string)max(0, min(3650, (int)($in['don_days'] ?? 0))));
            set_setting('vip_kofi_types', implode(',', array_values(array_intersect(VIP_KOFI_TYPES, (array)($in['types'] ?? [])))));
            $lid = (int)($in['list'] ?? 0);
            if ($lid > 0 && vip_list($lid)) { set_setting('vip_kofi_list', (string)$lid); vip_db()->prepare('UPDATE vip_lists SET kofi = CASE WHEN id = ? THEN 1 ELSE 0 END')->execute([$lid]); }
            elseif ($lid === 0) { set_setting('vip_kofi_list', ''); vip_db()->exec('UPDATE vip_lists SET kofi = 0'); }
            if (!empty($in['token_clear'])) vip_secret_set('kofi', '');
            elseif (trim((string)($in['token'] ?? '')) !== '') vip_secret_set('kofi', (string)$in['token']);
            $_SESSION['vip_list'] = 'settings';
            audit('VIP-Listen: Ko-fi-Einstellungen gespeichert', '', 'security');
            return 'Ko-fi-Einstellungen gespeichert.';
        case 'vip_gist_settings':
            set_setting('vip_gist_on', !empty($in['on']) ? '1' : '0');
            $gid = trim((string)($in['gist_id'] ?? ''));
            if ($gid !== setting('vip_gist_id', '')) {
                if ($gid !== '' && !preg_match('/^[0-9a-f]{20,40}$/i', $gid)) throw new InvalidArgumentException('Gist-ID: die lange Kennung aus der Gist-Adresse (0–9, a–f).');
                set_setting('vip_gist_id', $gid); set_setting('vip_gist_hash', ''); set_setting('vip_gist_files', '[]');
                if ($gid === '') set_setting('vip_gist_owner', '');
            }
            $own = trim((string)($in['gist_owner'] ?? ''));
            if ($own !== '' && preg_match('/^[A-Za-z0-9-]{1,39}$/', $own)) set_setting('vip_gist_owner', $own);
            if (!empty($in['token_clear'])) vip_secret_set('gist', '');
            elseif (trim((string)($in['token'] ?? '')) !== '') vip_secret_set('gist', (string)$in['token']);
            $u = rtrim(trim((string)($in['public_url'] ?? '')), '/');
            if ($u !== '' && !preg_match('#^https://[^\s/]+(/[^\s]*)?$#i', $u)) throw new InvalidArgumentException('Öffentliche Adresse: z. B. https://ghostnode-labs.dev');
            set_setting('vip_public_url', $u);
            $_SESSION['vip_list'] = 'settings';
            audit('VIP-Listen: Gist-Einstellungen gespeichert', '', 'security');
            return 'Gist-Einstellungen gespeichert.';
        case 'vip_gist_sync':
            $_SESSION['vip_list'] = 'settings';
            try { return vip_gist_sync(true); }
            catch (RuntimeException $e) { throw new InvalidArgumentException($e->getMessage()); }
    }
    throw new InvalidArgumentException('Unbekannte Aktion.');
}

/** kleines Formular mit Aktion (+ versteckte Felder) */
function vip_form(string $act, array $hidden = [], string $attrs = ''): string {
    $h = '<form method="POST"' . $attrs . '><input type="hidden" name="tab" value="vip"><input type="hidden" name="action" value="' . e($act) . '">';
    foreach ($hidden as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e((string)$v) . '">';
    return $h;
}
/** Adresse mit Kopier-Knopf */
function vip_copy_field(string $id, string $value, string $label, string $hint = ''): string {
    return '<div class="vip-link"><div class="vip-link-h"><b>' . $label . '</b>' . ($hint !== '' ? '<small>' . $hint . '</small>' : '') . '</div>'
        . '<div class="vip-link-row"><input type="text" readonly id="' . e($id) . '" value="' . e($value) . '">'
        . '<button type="button" class="btn btn-sm" data-copy="#' . e($id) . '">⧉ Kopieren</button></div></div>';
}
/** kleines Abzeichen */
function vip_chip(string $txt, string $cls = ''): string { return '<span class="vip-chip' . ($cls !== '' ? ' ' . $cls : '') . '">' . $txt . '</span>'; }


function admin_vip_view(): void {
    vip_schema();
    $lists = vip_lists();
    // gewählter Reiter: Listen-ID oder „settings“ (über ?list=…, sonst zuletzt benutzt)
    $want = isset($_GET['list']) ? (string)$_GET['list'] : (string)($_SESSION['vip_list'] ?? '');
    if (isset($_GET['list'])) $_SESSION['vip_list'] = $want === 'settings' ? 'settings' : (int)$want;
    $settings = $want === 'settings';
    $cur = null;
    if (!$settings) {
        foreach ($lists as $l) if ((int)$l['id'] === (int)$want) $cur = $l;
        if (!$cur && $lists) $cur = $lists[0];
    }
    $base = vip_base_url();
    $kc = vip_kofi_cfg();
    $gist_on = setting('vip_gist_on', '0') === '1';
    $gist_tok = vip_secret_source('gist');
    $kofi_tok = vip_secret_source('kofi');
    $pending = array_sum(array_map(fn($l) => (int)$l['pending'], $lists));
    $src = fn($s) => $s === 'env' ? vip_chip('✔ Server-Secret', 'ok') : ($s === 'admin' ? vip_chip('✔ verschlüsselt gespeichert', 'ok') : vip_chip('✖ fehlt', 'bad'));

    echo '<div class="kpis vip-kpis"><div class="kpi"><span class="k">Listen</span><span class="v">' . count($lists) . '</span></div>'
        . '<div class="kpi"><span class="k">Aktive Einträge</span><span class="v">' . array_sum(array_map(fn($l) => (int)$l['n'], $lists)) . '</span></div>'
        . '<div class="kpi"><span class="k">Warten auf Prüfung</span><span class="v"' . ($pending ? ' style="color:var(--accent-gold)"' : '') . '>' . $pending . '</span></div>'
        . '<div class="kpi"><span class="k">Ko-fi · Gist</span><span class="v small">' . ($kc['list'] && $kofi_tok ? '💖 an' : '💖 aus') . ' · ' . ($gist_on ? ($gist_tok ? '🐙 an' : '🐙 ohne Token') : '🐙 aus') . '</span></div></div>';

    // ---------- Reiter: je Liste einer, dann Einstellungen, dann „Neue Liste“ (Popup) ----------
    echo '<nav class="vip-tabs" aria-label="VIP-Listen">';
    foreach ($lists as $l) {
        $on = $cur && (int)$cur['id'] === (int)$l['id'];
        echo '<a class="vip-tab' . ($on ? ' active' : '') . '" href="admin.php?tab=vip&amp;list=' . (int)$l['id'] . '"' . ($on ? ' aria-current="page"' : '') . '>'
            . '<span class="vt-t">' . e($l['title']) . '</span>' . ($l['world'] !== '' ? '<span class="vt-w">' . e($l['world']) . '</span>' : '')
            . '<span class="vt-n" title="aktive Einträge">' . (int)$l['n'] . '</span>' . ((int)$l['pending'] ? '<span class="vt-p" title="warten auf Prüfung">' . (int)$l['pending'] . '</span>' : '')
            . ((int)$l['kofi'] ? '<span title="empfängt Ko-fi">💖</span>' : '') . '</a>';
    }
    echo '<span class="vip-tabs-gap"></span>'
        . '<a class="vip-tab vip-tab-set' . ($settings ? ' active' : '') . '" href="admin.php?tab=vip&amp;list=settings"' . ($settings ? ' aria-current="page"' : '') . '>⚙ Einstellungen <small>Ko-fi · Gist</small></a>'
        . '<button type="button" class="vip-tab vip-tab-new" data-open="#vipNewModal">＋ Neue Liste</button></nav>';

    // ---------- Popup „Neue Liste“ ----------
    echo '<div class="modal-overlay" id="vipNewModal" role="dialog" aria-modal="true" aria-labelledby="vipNewTitle">'
        . vip_form('vip_list_save', [], ' class="modal-box vip-modal"') . '<button type="button" class="modal-close" data-close aria-label="Schließen">&times;</button>'
        . '<div class="panel-title"><span id="vipNewTitle">// ⭐ Neue Liste</span></div>'
        . '<p class="muted small">Jede Liste bekommt eine eigene Adresse für die Welt. Einträge fügst du danach im Reiter der Liste hinzu.</p>'
        . '<label class="field">Name *</label><input type="text" name="title" maxlength="80" required placeholder="z. B. VIP oder Ko-fi-Unterstützer">'
        . '<div class="f2"><div><label class="field">Kurzadresse</label><input type="text" name="slug" maxlength="40" pattern="[a-z0-9-]*" placeholder="leer = aus dem Namen"></div>'
        . '<div><label class="field">Standardgruppe</label><input type="text" name="grp" value="VIP" maxlength="20"></div></div>'
        . '<label class="field">Welt (nur zur Übersicht)</label><input type="text" name="world" maxlength="80" placeholder="z. B. Sylvan Club">'
        . '<label class="check"><input type="checkbox" name="gist" value="1" checked> ins Gist spiegeln</label>'
        . '<label class="check"><input type="checkbox" name="kofi" value="1"> neue Ko-fi-Unterstützer landen in dieser Liste</label>'
        . '<div class="vip-actions"><button type="button" class="btn" data-close>Abbrechen</button><button class="btn btn-gold">＋ Liste anlegen</button></div></form></div>';

    if ($settings) { vip_settings_view($lists, $kc, $base, $gist_on, $gist_tok, $kofi_tok, $src); return; }

    if (!$cur) {
        echo '<div class="panel vip-empty"><div class="vip-empty-i">⭐</div><h3>Noch keine VIP-Liste</h3>'
            . '<p class="muted">Lege eine Liste an, trage Namen ein und kopiere die Adresse in die VIP-Tafel oder eine Keypad-Rolle in Unity.</p>'
            . '<button type="button" class="btn btn-gold" data-open="#vipNewModal">＋ Erste Liste anlegen</button></div>';
        return;
    }

    // ---------- Kopf der gewählten Liste ----------
    $id = (int)$cur['id'];
    $rows = vip_entries($id);
    $direct = $base . '/vip.php?l=' . rawurlencode($cur['slug']);
    $raw = (int)$cur['gist'] ? vip_gist_raw_url($cur['slug'] . '.txt') : '';
    $expired = count(array_filter($rows, fn($r) => (int)$r['expires'] > 0 && (int)$r['expires'] <= time()));
    echo '<div class="panel vip-hero"><div class="vip-hero-i">⭐</div><div class="vip-hero-b"><h2>' . e($cur['title']) . '</h2>'
        . '<div class="vip-chips">' . vip_chip('/' . e($cur['slug'])) . ($cur['world'] !== '' ? vip_chip('🌍 ' . e($cur['world'])) : '') . vip_chip('Gruppe ' . e($cur['grp']))
        . ((int)$cur['kofi'] ? vip_chip('💖 empfängt Ko-fi', 'pink') : '') . ((int)$cur['gist'] ? vip_chip('🐙 im Gist', 'ok') : vip_chip('nicht im Gist')) . '</div></div>'
        . '<div class="vip-stats"><div><b>' . (int)$cur['n'] . '</b><small>aktiv</small></div><div' . ((int)$cur['pending'] ? ' class="warn"' : '') . '><b>' . (int)$cur['pending'] . '</b><small>warten</small></div>'
        . '<div' . ($expired ? ' class="bad"' : '') . '><b>' . $expired . '</b><small>abgelaufen</small></div></div>'
        . (count($lists) > 1 ? '<div class="vip-move" title="Reiter verschieben">' . vip_form('vip_list_move', ['id' => $id, 'dir' => -1]) . '<button class="btn btn-sm" aria-label="Reiter nach links">◀</button></form>'
            . vip_form('vip_list_move', ['id' => $id, 'dir' => 1]) . '<button class="btn btn-sm" aria-label="Reiter nach rechts">▶</button></form></div>' : '') . '</div>';

    // ---------- Einträge (volle Breite) ----------
    $sort = isset(VIP_SORTS[$cur['sort'] ?? '']) ? (string)$cur['sort'] : 'group';
    $manual = $sort === 'manual';
    $sel = vip_form('vip_list_sort', ['id' => $id], ' class="vip-sort"') . '<label for="vipSort">↕ Sortierung</label><select id="vipSort" name="sort" onchange="this.form.submit()">';
    foreach (VIP_SORTS as $k => $lbl) $sel .= '<option value="' . e($k) . '"' . ($k === $sort ? ' selected' : '') . '>' . e($lbl) . '</option>';
    $sel .= '</select><noscript><button class="btn btn-sm">OK</button></noscript></form>';
    echo '<div class="panel vip-entries"><div class="panel-title"><span>// 👥 Einträge (' . count($rows) . ')</span>' . $sel
        . ($rows ? '<input type="search" class="vip-search" data-vip-filter="#vipTbl" placeholder="🔍 Suchen …" aria-label="Einträge durchsuchen">' : '') . '</div>'
        . '<p class="muted small">Anzeigename genau wie in VRChat (Groß/Klein und Leerzeichen am Rand sind egal). Ablauf leer = dauerhaft. Abgelaufene und ungeprüfte Einträge erscheinen nicht in der Welt. Notizen bleiben intern. '
        . 'Die Sortierung gilt auch für die Tafel in der Welt' . ($manual ? ' – Reihenfolge mit ▲ ▼ ändern (⤒ ⤓ = ganz nach oben/unten)' : '; ▲ ▼ schaltet auf „Eigene Reihenfolge“ um') . '.</p>'
        . '<div class="tbl-wrap"><table class="tbl vip-tbl" id="vipTbl"><thead><tr><th>Anzeigename</th><th>Gruppe</th><th>Notiz (intern)</th><th>läuft ab</th><th>Status</th><th></th></tr></thead><tbody>'
        . '<tr class="vip-addrow"><td><input form="vipnew" type="text" name="name" maxlength="64" placeholder="＋ VRChat-Anzeigename" required></td>'
        . '<td><input form="vipnew" type="text" name="grp" value="' . e($cur['grp']) . '" maxlength="20" class="vip-grp"></td>'
        . '<td><input form="vipnew" type="text" name="note" maxlength="200" placeholder="z. B. Event-Helferin"></td>'
        . '<td><input form="vipnew" type="date" name="expires"></td><td></td><td>' . vip_form('vip_entry_save', ['list' => $id], ' id="vipnew"') . '<button class="btn btn-sm btn-gold">＋ Hinzufügen</button></form></td></tr>';
    foreach ($rows as $r) {
        $fid = 'vipe' . (int)$r['id'];
        $exp = (int)$r['expires'] > 0 && (int)$r['expires'] <= time();
        $state = $r['status'] === 'pending' ? vip_chip('⏳ wartet', 'warn') : ($exp ? vip_chip('abgelaufen', 'bad') : vip_chip('● aktiv', 'ok'));
        echo '<tr data-q="' . e(mb_strtolower($r['name'] . ' ' . $r['grp'] . ' ' . $r['note'])) . '"><td><input form="' . $fid . '" type="text" name="name" value="' . e($r['name']) . '" maxlength="64" required></td>'
            . '<td><input form="' . $fid . '" type="text" name="grp" value="' . e($r['grp']) . '" maxlength="20" class="vip-grp"></td>'
            . '<td><input form="' . $fid . '" type="text" name="note" value="' . e($r['note']) . '" maxlength="200"></td>'
            . '<td><input form="' . $fid . '" type="date" name="expires" value="' . ((int)$r['expires'] ? e(date('Y-m-d', (int)$r['expires'])) : '') . '"></td>'
            . '<td>' . $state . ($r['source'] === 'kofi' ? ' ' . vip_chip('💖', 'pink') : '') . '</td><td class="vip-act">'
            . '<span class="vip-ord">' . ($manual ? vip_form('vip_entry_move', ['id' => (int)$r['id'], 'dir' => -2]) . '<button class="btn btn-sm" title="ganz nach oben">⤒</button></form>' : '')
            . vip_form('vip_entry_move', ['id' => (int)$r['id'], 'dir' => -1]) . '<button class="btn btn-sm" title="nach oben">▲</button></form>'
            . vip_form('vip_entry_move', ['id' => (int)$r['id'], 'dir' => 1]) . '<button class="btn btn-sm" title="nach unten">▼</button></form>'
            . ($manual ? vip_form('vip_entry_move', ['id' => (int)$r['id'], 'dir' => 2]) . '<button class="btn btn-sm" title="ganz nach unten">⤓</button></form>' : '') . '</span>'
            . vip_form('vip_entry_save', ['list' => $id, 'id' => (int)$r['id']], ' id="' . $fid . '"') . '<button class="btn btn-sm" title="Speichern">💾</button></form>'
            . ($r['status'] === 'pending' ? vip_form('vip_entry_approve', ['id' => (int)$r['id']]) . '<button class="btn btn-sm btn-gold">✔ Freigeben</button></form>' : '')
            . vip_form('vip_entry_delete', ['id' => (int)$r['id']], ' onsubmit="return confirm(\'Eintrag entfernen?\')"') . '<button class="btn btn-sm" title="Entfernen">🗑</button></form></td></tr>';
    }
    echo '</tbody></table></div>' . (!$rows ? '<p class="muted small vip-none">Noch keine Einträge – oben den ersten Namen eintragen.</p>' : '') . '</div>';

    // ---------- Links + Bearbeiten (Masonry) ----------
    echo '<div class="grid2"><div class="panel"><div class="panel-title"><span>// 🔗 Links für Unity</span></div>'
        . '<p class="muted small">In der VIP-Tafel (NexusVipBoard) gehören die Adressen ins Feld „Urls“. Für eine Keypad-Rolle reicht die Namensliste.</p>';
    if ($raw !== '') echo vip_copy_field('vipr' . $id, $raw, 'URL 1 · Gist', 'vertrauenswürdig, klappt ohne „Untrusted URLs“');
    echo vip_copy_field('vipd' . $id, $direct, ($raw !== '' ? 'URL 2' : 'URL') . ' · direkt', 'nur mit „Allow Untrusted URLs“')
        . vip_copy_field('vipn' . $id, $raw !== '' ? vip_gist_raw_url($cur['slug'] . '.names.txt') : $direct . '&fmt=names', 'Nur Namen', 'z. B. „Url“ einer Keypad-Rolle')
        . '<textarea id="vipt' . $id . '" readonly hidden>' . e(vip_text($cur)) . '</textarea>'
        . '<div class="vip-actions"><button type="button" class="btn btn-sm" data-copy="#vipt' . $id . '">⧉ Text kopieren (für ein Gist von Hand)</button>'
        . '<a class="btn btn-sm" href="' . e($direct) . '" target="_blank" rel="noopener">↗ Vorschau</a></div></div>';

    echo vip_form('vip_list_save', ['id' => $id], ' class="panel"') . '<div class="panel-title"><span>// ✎ Liste bearbeiten</span></div>'
        . '<label class="field">Name</label><input type="text" name="title" value="' . e($cur['title']) . '" maxlength="80" required>'
        . '<div class="f2"><div><label class="field">Kurzadresse ⚠</label><input type="text" name="slug" value="' . e($cur['slug']) . '" maxlength="40" pattern="[a-z0-9-]+" required title="Ändert die Adresse in der Welt"></div>'
        . '<div><label class="field">Standardgruppe</label><input type="text" name="grp" value="' . e($cur['grp']) . '" maxlength="20"></div></div>'
        . '<label class="field">Welt</label><input type="text" name="world" value="' . e($cur['world']) . '" maxlength="80">'
        . '<label class="check"><input type="checkbox" name="gist" value="1"' . ((int)$cur['gist'] ? ' checked' : '') . '> ins Gist spiegeln</label>'
        . '<label class="check"><input type="checkbox" name="kofi" value="1"' . ((int)$cur['kofi'] ? ' checked' : '') . '> empfängt Ko-fi-Unterstützer</label>'
        . '<div class="vip-actions"><button class="btn btn-gold">Speichern</button></div></form>';

    echo vip_form('vip_list_delete', ['id' => $id], ' class="panel vip-danger"') . '<div class="panel-title"><span>// 🗑 Liste löschen</span></div>'
        . '<p class="muted small">Löscht die Liste mit allen Einträgen. Die Adresse in der Welt liefert danach „nicht gefunden“.</p>'
        . '<label class="field">Zur Bestätigung „' . e($cur['slug']) . '“ eintippen</label><input type="text" name="confirm" autocomplete="off">'
        . '<div class="vip-actions"><button class="btn btn-danger">🗑 Endgültig löschen</button></div></form></div>';
}

/** Reiter „Einstellungen“: Ko-fi-Webhook und Gist-Spiegel */
function vip_settings_view(array $lists, array $kc, string $base, bool $gist_on, string $gist_tok, string $kofi_tok, callable $src): void {
    $kofi_url = $base . '/kofi.php';
    echo '<div class="grid2">' . vip_form('vip_kofi_settings', [], ' class="panel" autocomplete="off"') . '<div class="panel-title"><span>// 💖 Ko-fi-Webhook</span>' . $src($kofi_tok) . '</div>'
        . vip_copy_field('vipkofi', $kofi_url, 'Webhook-Adresse', 'Ko-fi → Einstellungen → API/Webhooks → „Webhook URL“, danach „Send Test“')
        . '<p class="muted small">Verification Token am besten als Server-Secret <code>GN_KOFI_TOKEN</code> (<code>ghostnode secrets</code>); sonst hier eintragen – wird verschlüsselt gespeichert und nie angezeigt.</p>'
        . '<label class="field">Verification Token (leer = behalten)</label><input type="password" name="token" autocomplete="new-password"' . ($kofi_tok === 'env' ? ' disabled placeholder="kommt vom Server"' : '') . '>'
        . ($kofi_tok === 'admin' ? '<label class="check"><input type="checkbox" name="token_clear" value="1"> gespeicherten Token löschen</label>' : '')
        . '<div class="f2"><div><label class="field">Neue Unterstützer landen in</label><select name="list"><option value="0">– keine Liste (Ko-fi aus) –</option>';
    foreach ($lists as $l) echo '<option value="' . (int)$l['id'] . '"' . ($kc['list'] === (int)$l['id'] ? ' selected' : '') . '>' . e($l['title']) . ' (' . e($l['slug']) . ')</option>';
    echo '</select></div><div><label class="field">Gruppe für neue Einträge</label><input type="text" name="group" value="' . e($kc['grp']) . '" maxlength="20"></div>'
        . '<div><label class="field">Abo: jede Zahlung verlängert um … Tage</label><input type="number" name="sub_days" min="1" max="400" value="' . $kc['sub_days'] . '"></div>'
        . '<div><label class="field">Einmalig: sichtbar für … Tage (0 = dauerhaft)</label><input type="number" name="don_days" min="0" max="3650" value="' . $kc['don_days'] . '"></div></div>'
        . '<label class="field">Diese Arten übernehmen</label><div class="vip-checks">';
    foreach (VIP_KOFI_TYPES as $t) echo '<label class="check"><input type="checkbox" name="types[]" value="' . e($t) . '"' . (in_array($t, $kc['types'], true) ? ' checked' : '') . '> ' . e($t) . '</label>';
    $probs = vip_kofi_problems();
    echo '</div><label class="check"><input type="checkbox" name="review" value="1"' . ($kc['review'] ? ' checked' : '') . '> erst prüfen (neue Einträge warten auf „Freigeben“)</label>'
        . ($probs ? '<div class="vip-warnbox"><b>⚠ Ko-fi kommt so noch nicht an:</b><ul><li>' . implode('</li><li>', array_map('e', $probs)) . '</li></ul></div>' : '')
        . '<p class="muted small">Name: steht in der Ko-fi-Nachricht z. B. <code>vrc: DeinName</code>, wird dieser genommen, sonst der Ko-fi-Name. Gespeichert werden nur Name, Art/Stufe und Datum – keine E-Mail, kein Betrag, keine Nachricht. '
        . 'Private Unterstützungen warten immer auf Prüfung. Doppelte Zustellungen werden erkannt.</p>'
        . '<div class="vip-actions"><button class="btn" form="vipKofiTest" title="schickt eine Test-Zustellung übers Internet an kofi.php">🧪 Verbindung testen</button><button class="btn btn-gold">Speichern</button></div></form>';

    $gid = setting('vip_gist_id', '');
    echo vip_form('vip_gist_settings', [], ' class="panel" autocomplete="off"') . '<div class="panel-title"><span>// 🐙 Gist-Spiegel</span>' . $src($gist_tok) . '</div>'
        . '<p class="muted small">VRChat lädt Texte aus <code>gist.githubusercontent.com</code> ohne „Untrusted URLs“ – darum spiegelt die Webseite die Listen in ein geheimes Gist. '
        . 'Token: GitHub → Settings → Developer settings → Personal access token, nur Recht „Gists: Read and write“; am besten als Server-Secret <code>GN_GIST_TOKEN</code>.</p>'
        . '<label class="check"><input type="checkbox" name="on" value="1"' . ($gist_on ? ' checked' : '') . '> Gist-Spiegel an (bei Änderungen höchstens 1× pro Minute, sonst alle 15 min per Aufgabenplaner)</label>'
        . '<label class="field">Gist-Token (leer = behalten)</label><input type="password" name="token" autocomplete="new-password"' . ($gist_tok === 'env' ? ' disabled placeholder="kommt vom Server"' : '') . '>'
        . ($gist_tok === 'admin' ? '<label class="check"><input type="checkbox" name="token_clear" value="1"> gespeicherten Token löschen</label>' : '')
        . '<div class="f2"><div><label class="field">Vorhandenes Gist (ID)</label><input type="text" name="gist_id" value="' . e($gid) . '" maxlength="40" placeholder="leer = automatisch anlegen"></div>'
        . '<div><label class="field">GitHub-Benutzer des Gists</label><input type="text" name="gist_owner" value="' . e(setting('vip_gist_owner', '')) . '" maxlength="39" placeholder="wird erkannt"></div></div>'
        . '<label class="field">Öffentliche Adresse der Webseite (leer = automatisch)</label><input type="url" name="public_url" value="' . e(setting('vip_public_url', '')) . '" placeholder="https://ghostnode-labs.dev">'
        . '<div class="vip-sync"><span>Letzter Abgleich: <b>' . e(setting('vip_gist_result', '–') ?: '–') . '</b></span>' . (setting('vip_gist_dirty', '0') === '1' ? vip_chip('Änderungen warten', 'warn') : '') . '</div>'
        . ($gist_tok === '' ? '<p class="muted small">Ohne Token: Gist von Hand auf gist.github.com anlegen, je Liste den Text mit „⧉ Text kopieren“ (im Reiter der Liste) einfügen und die „Raw“-Adresse (ohne Commit-Kennung) in Unity eintragen.</p>' : '')
        . '<div class="vip-actions"><button class="btn btn-gold">Speichern</button>'
        . ($gist_on && $gist_tok !== '' ? '<button class="btn" form="vipSync">🔄 Jetzt abgleichen</button>' : '') . '</div></form></div>';
    if ($gist_on && $gist_tok !== '') echo vip_form('vip_gist_sync', [], ' id="vipSync" hidden') . '</form>';
    echo vip_form('vip_kofi_test', [], ' id="vipKofiTest" hidden') . '</form>';

    // ---------- Protokoll + Import ----------
    $log = vip_kofi_log_list();
    echo '<div class="grid2"><div class="panel"><div class="panel-title"><span>// 📜 Letzte Ko-fi-Zustellungen</span>'
        . ($log ? vip_form('vip_kofi_log_clear', [], ' style="margin-left:auto"') . '<button class="btn btn-sm">Leeren</button></form>' : '') . '</div>';
    if (!$log) echo '<p class="muted small">Noch keine Zustellung angekommen. Dann erreicht Ko-fi die Webhook-Adresse nicht: Adresse in Ko-fi prüfen (genau wie oben, mit https), '
        . 'dort „Send Test“ drücken und hier neu laden. „🧪 Verbindung testen“ prüft, ob die Adresse von außen erreichbar ist.</p>';
    else {
        echo '<div class="tbl-wrap"><table class="tbl vip-log"><thead><tr><th>Zeit</th><th>Ergebnis</th><th>Art</th><th>Name</th></tr></thead><tbody>';
        foreach ($log as $x) {
            $c = (int)($x['c'] ?? 0);
            echo '<tr><td>' . e(date('d.m. H:i:s', (int)($x['t'] ?? 0))) . '</td><td>' . vip_chip($c . ' · ' . e((string)($x['m'] ?? '')), $c === 200 ? 'ok' : ($c === 401 || $c >= 500 ? 'bad' : 'warn')) . '</td>'
                . '<td>' . e((string)($x['y'] ?? '')) . '</td><td>' . e((string)($x['n'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></div><p class="muted small">401 = Token in Ko-fi und hier verschieden · 503 = kein Token eingerichtet · „Art ignoriert“ = Art oben nicht angehakt · „Schon verarbeitet“ = Ko-fi hat doppelt zugestellt.</p>';
    }
    echo '</div>' . vip_form('vip_kofi_import', [], ' class="panel" enctype="multipart/form-data"') . '<div class="panel-title"><span>// 📥 Frühere Unterstützer importieren</span></div>'
        . '<p class="muted small">Der Webhook meldet nur neue Zahlungen. Ältere holst du so: Ko-fi → Payments/Transactions → „Export CSV“ und hier hochladen. '
        . 'Erkannt werden die Spalten Name (From), Art (Type), Datum und Nachricht (<code>vrc: Name</code>). Ablauf wie oben eingestellt, ab dem Zahlungsdatum; Abgelaufenes wird übersprungen. Gespeichert werden nur Name, Art und Datum.</p>'
        . '<label class="field">Ko-fi-CSV</label><input type="file" name="csvfile" accept=".csv,text/csv">'
        . '<label class="field">… oder Inhalt einfügen</label><textarea name="csv" rows="4" placeholder="DateTime (UTC),From,Message,…"></textarea>'
        . '<label class="field">In diese Liste</label><select name="list">';
    foreach ($lists as $l) echo '<option value="' . (int)$l['id'] . '"' . ($kc['list'] === (int)$l['id'] ? ' selected' : '') . '>' . e($l['title']) . ' (' . e($l['slug']) . ')</option>';
    echo '</select><div class="vip-actions"><button class="btn btn-gold">📥 Importieren</button></div></form></div>';
}
