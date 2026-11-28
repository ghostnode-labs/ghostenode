<?php
/**
 * Admin-Zentrale – Bereich „⭐ VIP-Listen“ (Recht vip.manage, standardmäßig nur Administratoren):
 * Listen je Welt, Einträge (Gruppe, Notiz, Ablauf), Ko-fi-Webhook, Gist-Spiegel. Logik: inc/modules/vip.php.
 */
require_once __DIR__ . '/../modules/vip.php';

const VIP_ADMIN_TABS = ['vip' => ['⭐', 'VIP-Listen', 'vip.manage']];
const VIP_ADMIN_ACTIONS = ['vip_list_save', 'vip_list_delete', 'vip_entry_save', 'vip_entry_delete', 'vip_entry_approve', 'vip_kofi_settings', 'vip_gist_settings', 'vip_gist_sync'];

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
            audit('VIP-Listen: Gist-Einstellungen gespeichert', '', 'security');
            return 'Gist-Einstellungen gespeichert.';
        case 'vip_gist_sync':
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
function vip_copy_field(string $id, string $value, string $label): string {
    return '<label class="field">' . $label . '</label><span class="inv-link" style="display:flex;gap:6px"><input type="text" readonly id="' . e($id) . '" value="' . e($value) . '" style="flex:1">'
        . '<button type="button" class="btn btn-sm" data-copy="#' . e($id) . '">⧉ Kopieren</button></span>';
}

function admin_vip_view(): void {
    vip_schema();
    $lists = vip_lists();
    $sel = (int)($_GET['list'] ?? ($_SESSION['vip_list'] ?? 0));
    $cur = $sel ? vip_list($sel) : null;
    if (!$cur && $lists) $cur = $lists[0];
    $base = vip_base_url();
    $kc = vip_kofi_cfg();
    $gist_on = setting('vip_gist_on', '0') === '1';
    $gist_tok = vip_secret_source('gist');
    $kofi_tok = vip_secret_source('kofi');
    $pending = array_sum(array_map(fn($l) => (int)$l['pending'], $lists));
    $src = fn($s) => $s === 'env' ? '<b style="color:#4ade80">gesetzt (Server-Secret)</b>' : ($s === 'admin' ? '<b style="color:#4ade80">gesetzt (verschlüsselt hier gespeichert)</b>' : '<b style="color:#f87171">fehlt</b>');

    echo '<div class="kpis"><div class="kpi"><span class="k">Listen</span><span class="v">' . count($lists) . '</span></div>'
        . '<div class="kpi"><span class="k">Einträge</span><span class="v">' . array_sum(array_map(fn($l) => (int)$l['n'], $lists)) . '</span></div>'
        . '<div class="kpi"><span class="k">Warten auf Prüfung</span><span class="v">' . $pending . '</span></div>'
        . '<div class="kpi"><span class="k">Gist</span><span class="v">' . ($gist_on ? ($gist_tok ? 'an' : 'ohne Token') : 'aus') . '</span></div></div>';

    // ---------- Listen + neue Liste ----------
    echo '<div class="grid2">';
    foreach ($lists as $l) {
        $direct = $base . '/vip.php?l=' . rawurlencode($l['slug']);
        $raw = vip_gist_raw_url($l['slug'] . '.txt');
        echo '<div class="panel"><div class="panel-title"><span>// ' . e($l['title']) . ($l['world'] !== '' ? ' · ' . e($l['world']) : '') . '</span>'
            . '<a class="btn btn-sm' . ($cur && (int)$cur['id'] === (int)$l['id'] ? ' btn-gold' : '') . '" href="admin.php?tab=vip&amp;list=' . (int)$l['id'] . '">✎ Einträge</a></div>'
            . '<p class="muted small"><code>' . e($l['slug']) . '</code> · Standardgruppe <b>' . e($l['grp']) . '</b> · ' . (int)$l['n'] . ' aktiv' . ((int)$l['pending'] ? ' · <b style="color:#f5c242">' . (int)$l['pending'] . ' warten</b>' : '')
            . ((int)$l['kofi'] ? ' · 💖 empfängt Ko-fi' : '') . ((int)$l['gist'] ? ' · im Gist' : '') . '</p>';
        if ($raw !== '' && (int)$l['gist']) echo vip_copy_field('vipr' . (int)$l['id'], $raw, 'Unity „URL 1“ (Gist, vertrauenswürdig)');
        echo vip_copy_field('vipd' . (int)$l['id'], $direct, 'Unity „URL 2“ (direkt – nur mit „Untrusted URLs“)')
            . vip_copy_field('vipn' . (int)$l['id'], $raw !== '' && (int)$l['gist'] ? vip_gist_raw_url($l['slug'] . '.names.txt') : $direct . '&fmt=names', 'Nur Namen (z. B. „Url“ einer Keypad-Rolle)')
            . '<textarea id="vipt' . (int)$l['id'] . '" readonly hidden>' . e(vip_text($l)) . '</textarea>'
            . '<p style="margin-top:8px"><button type="button" class="btn btn-sm" data-copy="#vipt' . (int)$l['id'] . '">⧉ Text kopieren (für ein Gist von Hand)</button></p></div>';
    }
    echo vip_form('vip_list_save', [], ' class="panel"') . '<div class="panel-title"><span>// Neue Liste</span></div>'
        . '<label class="field">Name (z. B. „VIP“ oder „Ko-fi-Unterstützer“)</label><input type="text" name="title" maxlength="80" required>'
        . '<label class="field">Kurzadresse (a–z, 0–9, -; leer = aus dem Namen)</label><input type="text" name="slug" maxlength="40" pattern="[a-z0-9-]*">'
        . '<label class="field">Welt (nur zur Übersicht)</label><input type="text" name="world" maxlength="80">'
        . '<label class="field">Standardgruppe (z. B. VIP, KOFI)</label><input type="text" name="grp" value="VIP" maxlength="20">'
        . '<label class="check"><input type="checkbox" name="gist" value="1" checked> ins Gist spiegeln</label>'
        . '<label class="check"><input type="checkbox" name="kofi" value="1"> neue Ko-fi-Unterstützer landen in dieser Liste</label>'
        . '<button class="btn btn-gold" style="margin-top:10px">＋ Liste anlegen</button></form>';
    echo '</div>';

    // ---------- Einträge der gewählten Liste (volle Breite) ----------
    if ($cur) {
        $rows = vip_entries((int)$cur['id']);
        echo '<div class="panel"><div class="panel-title"><span>// Einträge: ' . e($cur['title']) . ' (' . count($rows) . ')</span></div>'
            . '<p class="muted small">Anzeigename genau wie in VRChat (Groß/Klein und Leerzeichen am Rand sind egal). Ablauf leer = dauerhaft. Abgelaufene und ungeprüfte Einträge erscheinen nicht in der Welt. '
            . 'Notizen bleiben intern und werden nie ausgeliefert.</p>'
            . '<div class="tbl-wrap"><table class="tbl"><tr><th>Anzeigename</th><th>Gruppe</th><th>Notiz (intern)</th><th>läuft ab</th><th>Status</th><th></th></tr>';
        foreach ($rows as $r) {
            $fid = 'vipe' . (int)$r['id'];
            $expired = (int)$r['expires'] > 0 && (int)$r['expires'] <= time();
            $state = $r['status'] === 'pending' ? '<b style="color:#f5c242">wartet</b>' : ($expired ? '<span style="color:#f87171">abgelaufen</span>' : '<span style="color:#4ade80">aktiv</span>');
            echo '<tr><td><input form="' . $fid . '" type="text" name="name" value="' . e($r['name']) . '" maxlength="64" required></td>'
                . '<td><input form="' . $fid . '" type="text" name="grp" value="' . e($r['grp']) . '" maxlength="20" style="width:7em"></td>'
                . '<td><input form="' . $fid . '" type="text" name="note" value="' . e($r['note']) . '" maxlength="200"></td>'
                . '<td><input form="' . $fid . '" type="date" name="expires" value="' . ((int)$r['expires'] ? e(date('Y-m-d', (int)$r['expires'])) : '') . '"></td>'
                . '<td>' . $state . ($r['source'] === 'kofi' ? ' · 💖' : '') . '</td><td style="white-space:nowrap">'
                . vip_form('vip_entry_save', ['list' => (int)$cur['id'], 'id' => (int)$r['id']], ' id="' . $fid . '" style="display:inline"') . '<button class="btn btn-sm">💾</button></form> '
                . ($r['status'] === 'pending' ? vip_form('vip_entry_approve', ['id' => (int)$r['id']], ' style="display:inline"') . '<button class="btn btn-sm btn-gold">✔ Freigeben</button></form> ' : '')
                . vip_form('vip_entry_delete', ['id' => (int)$r['id']], ' style="display:inline" onsubmit="return confirm(\'Eintrag entfernen?\')"') . '<button class="btn btn-sm">🗑</button></form></td></tr>';
        }
        echo '<tr><td><input form="vipnew" type="text" name="name" maxlength="64" placeholder="VRChat-Anzeigename" required></td>'
            . '<td><input form="vipnew" type="text" name="grp" value="' . e($cur['grp']) . '" maxlength="20" style="width:7em"></td>'
            . '<td><input form="vipnew" type="text" name="note" maxlength="200" placeholder="z. B. Event-Helferin"></td>'
            . '<td><input form="vipnew" type="date" name="expires"></td><td></td><td>' . vip_form('vip_entry_save', ['list' => (int)$cur['id']], ' id="vipnew"') . '<button class="btn btn-sm btn-gold">＋ Hinzufügen</button></form></td></tr>';
        echo '</table></div></div>';

        echo '<div class="grid2">' . vip_form('vip_list_save', ['id' => (int)$cur['id']], ' class="panel"') . '<div class="panel-title"><span>// Liste bearbeiten</span></div>'
            . '<label class="field">Name</label><input type="text" name="title" value="' . e($cur['title']) . '" maxlength="80" required>'
            . '<label class="field">Kurzadresse (Achtung: ändert die Adresse in der Welt)</label><input type="text" name="slug" value="' . e($cur['slug']) . '" maxlength="40" pattern="[a-z0-9-]+" required>'
            . '<label class="field">Welt</label><input type="text" name="world" value="' . e($cur['world']) . '" maxlength="80">'
            . '<label class="field">Standardgruppe</label><input type="text" name="grp" value="' . e($cur['grp']) . '" maxlength="20">'
            . '<label class="check"><input type="checkbox" name="gist" value="1"' . ((int)$cur['gist'] ? ' checked' : '') . '> ins Gist spiegeln</label>'
            . '<label class="check"><input type="checkbox" name="kofi" value="1"' . ((int)$cur['kofi'] ? ' checked' : '') . '> empfängt Ko-fi-Unterstützer</label>'
            . '<button class="btn btn-gold" style="margin-top:10px">Speichern</button></form>'
            . vip_form('vip_list_delete', ['id' => (int)$cur['id']], ' class="panel"') . '<div class="panel-title"><span>// Liste löschen</span></div>'
            . '<p class="muted small">Löscht die Liste mit allen Einträgen. Die Adresse in der Welt liefert danach „nicht gefunden“.</p>'
            . '<label class="field">Zur Bestätigung „' . e($cur['slug']) . '“ eintippen</label><input type="text" name="confirm" autocomplete="off">'
            . '<button class="btn" style="margin-top:10px">🗑 Löschen</button></form></div>';
    }

    // ---------- Ko-fi + Gist ----------
    $kofi_url = $base . '/kofi.php';
    echo '<div class="grid2">' . vip_form('vip_kofi_settings', [], ' class="panel" autocomplete="off"') . '<div class="panel-title"><span>// 💖 Ko-fi-Webhook</span></div>'
        . vip_copy_field('vipkofi', $kofi_url, 'In Ko-fi → Einstellungen → API/Webhooks als „Webhook URL“ eintragen')
        . '<p class="muted small">Verification Token: ' . $src($kofi_tok) . '. Am besten als Server-Secret <code>GN_KOFI_TOKEN</code> (<code>ghostnode secrets</code>); sonst hier eintragen (wird verschlüsselt gespeichert, nie angezeigt).</p>'
        . '<label class="field">Verification Token (leer = behalten)</label><input type="password" name="token" autocomplete="new-password"' . ($kofi_tok === 'env' ? ' disabled placeholder="kommt vom Server"' : '') . '>'
        . ($kofi_tok === 'admin' ? '<label class="check"><input type="checkbox" name="token_clear" value="1"> gespeicherten Token löschen</label>' : '')
        . '<label class="field">Neue Unterstützer landen in</label><select name="list"><option value="0">– keine Liste (Ko-fi aus) –</option>';
    foreach ($lists as $l) echo '<option value="' . (int)$l['id'] . '"' . ($kc['list'] === (int)$l['id'] ? ' selected' : '') . '>' . e($l['title']) . ' (' . e($l['slug']) . ')</option>';
    echo '</select><label class="field">Gruppe für neue Ko-fi-Einträge</label><input type="text" name="group" value="' . e($kc['grp']) . '" maxlength="20">'
        . '<label class="field">Abo: jede Zahlung verlängert um … Tage</label><input type="number" name="sub_days" min="1" max="400" value="' . $kc['sub_days'] . '">'
        . '<label class="field">Einzel-Unterstützung: sichtbar für … Tage (0 = dauerhaft)</label><input type="number" name="don_days" min="0" max="3650" value="' . $kc['don_days'] . '">'
        . '<label class="field">Diese Arten übernehmen</label>';
    foreach (VIP_KOFI_TYPES as $t) echo '<label class="check"><input type="checkbox" name="types[]" value="' . e($t) . '"' . (in_array($t, $kc['types'], true) ? ' checked' : '') . '> ' . e($t) . '</label>';
    echo '<label class="check"><input type="checkbox" name="review" value="1"' . ($kc['review'] ? ' checked' : '') . '> erst prüfen (neue Einträge warten auf „Freigeben“)</label>'
        . '<p class="muted small">Name: steht in der Ko-fi-Nachricht z. B. <code>vrc: DeinName</code>, wird dieser genommen, sonst der Ko-fi-Name. Gespeichert werden nur Name, Art/Stufe und Datum – keine E-Mail, kein Betrag, keine Nachricht. '
        . 'Private Unterstützungen (nicht öffentlich) warten immer auf Prüfung. Doppelte Zustellungen werden erkannt.</p>'
        . '<button class="btn btn-gold" style="margin-top:10px">Speichern</button></form>';

    $gid = setting('vip_gist_id', '');
    echo vip_form('vip_gist_settings', [], ' class="panel" autocomplete="off"') . '<div class="panel-title"><span>// 🐙 Gist-Spiegel</span></div>'
        . '<p class="muted small">VRChat lädt Texte aus <code>gist.githubusercontent.com</code> ohne „Untrusted URLs“ – darum spiegelt die Webseite die Listen in ein geheimes Gist. '
        . 'Token: ' . $src($gist_tok) . ' (GitHub → Settings → Developer settings → Personal access token, nur Recht „Gists: Read and write“). Am besten als Server-Secret <code>GN_GIST_TOKEN</code>.</p>'
        . '<label class="check"><input type="checkbox" name="on" value="1"' . ($gist_on ? ' checked' : '') . '> Gist-Spiegel an (bei Änderungen, höchstens 1× pro Minute, und alle 15 min per Aufgabenplaner)</label>'
        . '<label class="field">Gist-Token (leer = behalten)</label><input type="password" name="token" autocomplete="new-password"' . ($gist_tok === 'env' ? ' disabled placeholder="kommt vom Server"' : '') . '>'
        . ($gist_tok === 'admin' ? '<label class="check"><input type="checkbox" name="token_clear" value="1"> gespeicherten Token löschen</label>' : '')
        . '<label class="field">Vorhandenes Gist benutzen (ID, leer = wird automatisch angelegt)</label><input type="text" name="gist_id" value="' . e($gid) . '" maxlength="40">'
        . '<label class="field">GitHub-Benutzername des Gists (wird beim Abgleich automatisch erkannt)</label><input type="text" name="gist_owner" value="' . e(setting('vip_gist_owner', '')) . '" maxlength="39">'
        . '<label class="field">Öffentliche Adresse der Webseite (leer = automatisch)</label><input type="url" name="public_url" value="' . e(setting('vip_public_url', '')) . '" placeholder="https://ghostnode-labs.dev">'
        . '<p class="muted small">Letzter Abgleich: ' . e(setting('vip_gist_result', '–') ?: '–') . (setting('vip_gist_dirty', '0') === '1' ? ' · <b>Änderungen warten</b>' : '') . '</p>'
        . ($gist_tok === '' ? '<p class="muted small">Ohne Token: Gist von Hand auf gist.github.com anlegen, je Liste den Text mit „⧉ Text kopieren“ einfügen und die „Raw“-Adresse (ohne Commit-Kennung) in Unity eintragen.</p>' : '')
        . '<button class="btn btn-gold" style="margin-top:10px">Speichern</button></form>';
    echo '</div>';
    if ($gist_on && $gist_tok !== '') echo vip_form('vip_gist_sync') . '<button class="btn btn-sm">🔄 Gist jetzt abgleichen</button></form>';
}
