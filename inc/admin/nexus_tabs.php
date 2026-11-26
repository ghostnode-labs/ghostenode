<?php
/**
 * Admin Core – Nexus-Bereiche (eingebunden in admin.php, Recht „admin.access“):
 *   nx_accounts  🪪 Nexus-Konten (Konten, Verified 18+, Geräte, Sperren, VRChat-Konto für den Bio-Code)
 *   nx_warn      ⚠ Warnliste (Meldungen bearbeiten/löschen, Einsprüche, Einstellungen, Protokoll)
 *   nx_comm      🛡 Community-Moderation (Meldungen, wartende Beiträge, NSFW-Bilder, Sanktionen)
 *   nx_chat      💬 Chat-Geräte (nur öffentliche Schlüssel/Zähler – Nachrichten sind Ende-zu-Ende-verschlüsselt)
 */
require_once __DIR__ . '/../modules/nexuscomm.php';   // lädt auch nexusacct + nexuswarn
require_once __DIR__ . '/nexus_cadm.php';   // 5.1: Community-Verwaltung
require_once __DIR__ . '/../modules/nexuslic.php';   // 5.6: Lizenzen 2.0

const NX_ADMIN_TABS = [
    'nx_accounts' => ['🪪', 'Nexus-Konten', 'admin.access'],
    'nx_warn'     => ['⚠️', 'Nexus-Warnliste', 'admin.access'],
    'nx_comm'     => ['🫂', 'Community-Moderation', 'admin.access'],
    'nx_cadm'     => ['🗂️', 'Community-Verwaltung', 'nexus.community'],
    'nx_chat'     => ['💬', 'Nexus-Chat', 'admin.access'],
    'nx_lic'      => ['🔑', 'Lizenzen', 'admin.access'],   // 5.6: Freischalt-Schlüssel & Sperrliste
];

/** POST-Aktionen „nx_…“ → Meldung (Fehler als InvalidArgumentException, wie in admin.php üblich) */
function admin_nexus_post(string $act, array $in): string {
    $actor = 'web:' . user_label();
    $id = strtolower((string)($in['vrc_id'] ?? ''));
    try {
        switch ($act) {
            // --- Konten ---
            case 'nx_service': set_setting('nexus_service_uid', (string)(int)($in['uid'] ?? 0)); audit('Nexus: VRChat-Konto für Bio-Code', (string)(int)($in['uid'] ?? 0)); return 'Gespeichert.';
            case 'nx_ban': case 'nx_unban':
                if (!nx_uid_ok($id)) throw new InvalidArgumentException('Ungültige VRChat-User-ID.');
                if ($act === 'nx_ban' && nw_is_owner($id)) throw new InvalidArgumentException('Die Besitzerin kann nicht gesperrt werden.');
                nx_db()->prepare('UPDATE nexus_accounts SET banned = ?, nsfw_on = 0 WHERE vrc_id = ?')->execute([$act === 'nx_ban' ? 1 : 0, $id]);
                if ($act === 'nx_ban') nx_db()->prepare('DELETE FROM nexus_devices WHERE vrc_id = ?')->execute([$id]);
                nw_log($actor, $act === 'nx_ban' ? 'Admin: Konto gesperrt' : 'Admin: Konto entsperrt', $id);
                audit('Nexus-Konto ' . ($act === 'nx_ban' ? 'gesperrt' : 'entsperrt'), $id, 'security');
                return $act === 'nx_ban' ? 'Konto gesperrt, alle Geräte abgemeldet.' : 'Konto entsperrt.';
            case 'nx_age_revoke':
                if (!nx_uid_ok($id)) throw new InvalidArgumentException('Ungültige VRChat-User-ID.');
                nx_db()->prepare("UPDATE nexus_accounts SET age_until = 0, nsfw_on = 0, age_status = 'entzogen (Admin)', age_manual_until = 0, age_manual_by = '', age_manual_note = '' WHERE vrc_id = ?")->execute([$id]);
                nw_log($actor, 'Admin: Verified 18+ entzogen', $id, mb_substr(trim((string)($in['note'] ?? '')), 0, 200));
                audit('Nexus: 18+ entzogen', $id, 'security');
                return 'Freigabe entzogen (auch eine manuelle) – die App prüft beim nächsten Start neu bei VRChat.';
            case 'nx_age_grant':   // 5.1: 18+ manuell – nur mit Grund (wie geprüft), Ablauf, protokolliert
                if (!nx_uid_ok($id)) throw new InvalidArgumentException('Ungültige VRChat-User-ID.');
                $acc = nx_account($id);
                if (!$acc) throw new InvalidArgumentException('Zu dieser VRChat-ID gibt es noch kein Nexus-Konto – die Person muss VRC Nexus einmal mit ihrem Konto verbinden.');
                if ((int)$acc['banned']) throw new InvalidArgumentException('Das Konto ist gesperrt.');
                $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 300);
                if (mb_strlen($note) < 10) throw new InvalidArgumentException('Bitte angeben, wie das Alter geprüft wurde (mindestens 10 Zeichen) – das wird protokolliert.');
                if (empty($in['confirm'])) throw new InvalidArgumentException('Bitte bestätigen, dass die Person nachweislich volljährig ist.');
                $days = max(1, min(365, (int)($in['days'] ?? 30)));
                $until = time() + $days * 86400;
                nx_db()->prepare("UPDATE nexus_accounts SET age_manual_until = ?, age_manual_by = ?, age_manual_note = ?, age_until = MAX(age_until, ?), age_status = CASE WHEN age_until > ? THEN age_status ELSE 'manuell' END WHERE vrc_id = ?")
                    ->execute([$until, user_label(), $note, $until, time(), $id]);
                nw_log($actor, 'Admin: Verified 18+ manuell vergeben', $id, $days . ' Tage – ' . $note);
                audit('Nexus: 18+ manuell vergeben', $id . ' · ' . $days . ' Tage · ' . $note, 'security');
                return '🔞 18+ für ' . $acc['display_name'] . ' manuell freigegeben bis ' . date('d.m.Y', $until) . '. NSFW muss die Person in der App selbst einschalten.';
            case 'nx_cadm': return admin_nexus_cadm($actor, $in);
            case 'nx_lic': {   // 5.6: Schlüssel sperren/entsperren – gilt in allen Apps nach dem nächsten Abgleich (spätestens 6 h)
                $kid = (string)($in['kid'] ?? '');
                $on = ($in['do'] ?? '') === 'revoke';
                nl_set_revoked($kid, $on, 'web');
                nw_log($actor, $on ? 'Admin: Schlüssel gesperrt' : 'Admin: Schlüssel entsperrt', $kid);
                return $on ? 'Schlüssel gesperrt – Apps übernehmen das beim nächsten Abgleich (spätestens 6 h).' : 'Schlüssel entsperrt.';
            }
            case 'nx_cron': return nx_cron(50) . ' abgelaufene Freigaben aufgeräumt.';
            // --- Warnliste ---
            case 'nx_warn_settings': case 'nx_warn_update': case 'nx_warn_delete': case 'nx_warn_clear': case 'nx_warn_appeal':
                $map = ['nx_warn_settings' => 'admin_settings', 'nx_warn_update' => 'admin_update', 'nx_warn_delete' => 'admin_delete', 'nx_warn_clear' => 'admin_clear', 'nx_warn_appeal' => 'admin_appeal'];
                $r = nw_admin($actor, $map[$act], $in);
                audit('Nexus-Warnliste', $map[$act]);
                return (string)($r['msg'] ?? 'Gespeichert.');
            // --- Community ---
            case 'nx_comm':
                $who = ['vrc_id' => $actor, '_mod' => true, '_nsfw' => true];   // Admins der Webseite moderieren alles
                $do = (string)($in['do'] ?? '');
                if (in_array($do, ['mute', 'ban', 'unban'], true) && !nx_uid_ok($id) && empty($in['id'])) throw new InvalidArgumentException('Ungültige VRChat-User-ID.');
                $r = nc_mod($who, $do, $in);
                audit('Nexus-Community', $do . ' ' . ($in['id'] ?? $id));
                return (string)($r['msg'] ?? 'Erledigt.');
            // --- Chat ---
            case 'nx_chat_remove':
                $dev = preg_replace('/[^0-9a-f]/', '', (string)($in['device'] ?? ''));
                foreach (['chat_opks WHERE device_id', 'chat_inbox WHERE to_device', 'chat_devices WHERE device_id'] as $t) nx_db()->prepare('DELETE FROM ' . $t . ' = ?')->execute([$dev]);
                nw_log($actor, 'Admin: Chat-Gerät entfernt', '', $dev);
                return 'Gerät entfernt (wartende verschlüsselte Nachrichten gelöscht).';
        }
    } catch (NxError | NwError | NcError $e) { throw new InvalidArgumentException($e->getMessage()); }
    throw new InvalidArgumentException('Unbekannte Aktion.');
}

function nx_adm_when($t): string { return $t ? date('d.m.Y H:i', (int)$t) : '–'; }
function nx_adm_form(string $tab, string $action, string $inner, string $cls = 'inline'): string {
    return '<form method="POST" class="' . $cls . '"><input type="hidden" name="tab" value="' . e($tab) . '"><input type="hidden" name="action" value="' . e($action) . '">' . $inner . '</form>';
}

/** Ansicht eines Nexus-Tabs (im Panel-Design von admin.php) */
function admin_nexus_view(string $tab): void {
    $db = nx_db();
    echo '<style>.nx-item{border-top:1px solid var(--border-color);padding:10px 0}.nx-item:first-of-type{border-top:0}.nx-item summary{cursor:pointer}.nx-sub{margin:8px 0 8px 14px;padding-left:10px;border-left:2px solid var(--border-color)}.nx-sub form{display:flex;flex-wrap:wrap;gap:6px;margin:4px 0}'
        . '.nx-log{max-height:420px;overflow:auto;font-size:.8rem}.nx-log div{padding:3px 0;border-bottom:1px solid rgba(56,213,230,.08)}.nx-media{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}.nx-media img{width:100%;height:150px;object-fit:cover;border:1px solid var(--border-color)}.nx-media figure{margin:0}.nx-media figcaption{display:flex;gap:6px;margin-top:4px}'
        . '.badge{display:inline-block;padding:1px 7px;border:1px solid var(--border-color);font-size:.7rem;letter-spacing:1px}.badge.red{border-color:var(--accent-red);color:var(--accent-red)}.badge.gold{border-color:var(--accent-gold);color:var(--accent-gold)}.tbl-wrap{overflow-x:auto}</style>';
    if ($tab === 'nx_accounts') {
        $svc = nx_service_uid();
        $users = site_db()->query('SELECT id, username FROM users ORDER BY username')->fetchAll(PDO::FETCH_ASSOC);
        $vacc = []; foreach ($users as $u) { $x = vrc_account((int)$u['id']); if ($x) $vacc[(int)$u['id']] = $x['display_name'] ?? ''; }
        $list = $db->query('SELECT a.*, (SELECT COUNT(*) FROM nexus_devices d WHERE d.vrc_id = a.vrc_id) AS devs FROM nexus_accounts a ORDER BY last_seen DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
        $v18 = count(array_filter($list, fn($a) => (int)$a['age_until'] > time()));
        $auto = ''; try { $auto = nx_service()['display_name'] ?? ''; } catch (Throwable $e) { $auto = ''; }
        echo '<div class="kpis"><div class="kpi"><span class="k">Konten</span><span class="v">' . count($list) . '</span></div><div class="kpi"><span class="k">Verified 18+</span><span class="v">' . $v18 . '</span></div>'
            . '<div class="kpi"><span class="k">NSFW an</span><span class="v">' . count(array_filter($list, fn($a) => $a['nsfw_on'] && (int)$a['age_until'] > time())) . '</span></div><div class="kpi"><span class="k">Gesperrt</span><span class="v">' . count(array_filter($list, fn($a) => $a['banned'])) . '</span></div></div>';
        echo '<div class="grid2"><div class="panel"><div class="panel-title"><span>// VRChat-Konto für den Bio-Code (nur ältere Apps)</span></div>'
            . '<p class="muted small">Ab App 3.0.2 verbindet sich Nexus direkt mit der VRChat-ID (ohne Code). Nur ältere Apps nutzen noch den Bio-Code über ein VRChat-Konto aus dem Dashboard. Ohne Auswahl wird automatisch das erste verbundene Konto eines Administrators genommen' . ($auto !== '' ? ' (jetzt: <b>' . e($auto) . '</b>)' : ' – <b class="red">keins verbunden</b>') . '. Die 18+-Prüfung macht die App selbst direkt bei VRChat.</p>'
            . nx_adm_form('nx_accounts', 'nx_service', '<select name="uid"><option value="0">automatisch</option>' . implode('', array_map(fn($u) => '<option value="' . (int)$u['id'] . '"' . ($svc === (int)$u['id'] ? ' selected' : '') . (isset($vacc[(int)$u['id']]) ? '' : ' disabled') . '>' . e($u['username']) . (isset($vacc[(int)$u['id']]) ? ' → VRChat: ' . e($vacc[(int)$u['id']]) : ' (kein VRChat)') . '</option>', $users)) . '</select> <button class="btn btn-gold">Speichern</button>', '')
            . '</div><div class="panel"><div class="panel-title"><span>// Aufräumen</span></div><p class="muted small">Abgelaufene Freigaben schalten NSFW automatisch aus (Aufgabe „🔞 Nexus: 18+-Freigaben“).</p>'
            . nx_adm_form('nx_accounts', 'nx_cron', '<button class="btn">⟳ Jetzt aufräumen</button>', '') . '</div></div>';
        echo '<div class="panel"><div class="panel-title"><span>// Konten</span></div><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Name</th><th>VRChat-ID</th><th>Verified 18+</th><th>NSFW</th><th>Geräte</th><th>Zuletzt</th><th></th></tr></thead><tbody>';
        foreach ($list as $a) {
            $v = (int)$a['age_until'] > time();
            echo '<tr><td>' . e($a['display_name']) . ($a['banned'] ? ' <span class="badge red">gesperrt</span>' : '') . (nw_is_owner($a['vrc_id']) ? ' <span class="badge gold">Besitzerin</span>' : '') . '</td><td><code>' . e($a['vrc_id']) . '</code></td>'
                . '<td>' . ($v ? '<span class="green">✔ bis ' . e(nx_adm_when($a['age_until'])) . '</span>' : '<span class="muted">✖ ' . e($a['age_status'] ?: '–') . '</span>')
                . ((int)($a['age_manual_until'] ?? 0) > time() ? ' <span class="badge gold" title="' . e(($a['age_manual_by'] ?? '') . ': ' . ($a['age_manual_note'] ?? '')) . '">manuell bis ' . e(date('d.m.Y', (int)$a['age_manual_until'])) . '</span>' : '') . '</td><td>' . ($v && $a['nsfw_on'] ? 'an' : 'aus') . '</td><td>' . (int)$a['devs'] . '</td><td>' . e(nx_adm_when($a['last_seen'])) . '</td><td class="tools">'
                . (can('nexus.age') && !$v && !$a['banned'] ? '<details class="nx-grant"><summary class="btn btn-sm">🔞 18+ manuell</summary>' . nx_adm_form('nx_accounts', 'nx_age_grant', '<input type="hidden" name="vrc_id" value="' . e($a['vrc_id']) . '"><input type="text" name="note" required minlength="10" maxlength="300" placeholder="Wie geprüft? (z. B. Ausweis per Video-Call am …)"> <label><input type="number" name="days" min="1" max="365" value="30" style="width:70px"> Tage</label> <label class="check small"><input type="checkbox" name="confirm" value="1" required> nachweislich volljährig</label> <button class="btn btn-sm btn-gold">Freigeben</button>', '') . '</details>' : '')
                . ($v && can('nexus.age') ? nx_adm_form('nx_accounts', 'nx_age_revoke', '<input type="hidden" name="vrc_id" value="' . e($a['vrc_id']) . '"><button class="btn btn-sm" onclick="return confirm(\'18+-Freigabe entziehen?\')">18+ entziehen</button>') : '')
                . nx_adm_form('nx_accounts', $a['banned'] ? 'nx_unban' : 'nx_ban', '<input type="hidden" name="vrc_id" value="' . e($a['vrc_id']) . '"><button class="btn btn-sm ' . ($a['banned'] ? '' : 'btn-danger') . '"' . ($a['banned'] ? '' : ' onclick="return confirm(\'Konto sperren und alle Geräte abmelden?\')"') . '>' . ($a['banned'] ? 'Entsperren' : 'Sperren') . '</button>') . '</td></tr>';
        }
        if (!$list) echo '<tr><td colspan="7" class="muted">Noch keine Nexus-Konten.</td></tr>';
        echo '</tbody></table></div></div>';
        return;
    }
    if ($tab === 'nx_warn') {
        $d = nw_admin_data();
        $by = []; foreach ($d['reports'] as $r) $by[$r['target']][] = $r;
        $open = array_filter($d['appeals'], fn($a) => $a['status'] === 'open');
        $reasons = function (string $cur) { $h = '<select name="reason">'; foreach (NW_REASONS as $k => $v) $h .= '<option value="' . e($k) . '"' . ($k === $cur ? ' selected' : '') . '>' . e($v[0] . ' ' . $v[1]) . '</option>'; return $h . '</select>'; };
        echo '<div class="kpis"><div class="kpi"><span class="k">Gemeldete Personen</span><span class="v">' . count($by) . '</span></div><div class="kpi"><span class="k">Meldungen</span><span class="v">' . count($d['reports']) . '</span></div>'
            . '<div class="kpi"><span class="k">Offene Einsprüche</span><span class="v">' . count($open) . '</span></div><div class="kpi"><span class="k">Sichtbar ab</span><span class="v">' . (int)$d['min'] . '</span></div></div>';
        echo '<div class="panel"><div class="panel-title"><span>// Einstellungen</span></div>' . nx_adm_form('nx_warn', 'nx_warn_settings', '<label>Sichtbar ab <input type="number" name="min" min="1" max="10" value="' . (int)$d['min'] . '" style="width:70px"> Meldungen</label> '
            . '<label>Gesperrte Schlüssel-IDs <input type="text" name="revoked" value="' . e(implode(', ', $d['revoked'])) . '" style="min-width:260px"></label> <button class="btn btn-gold">Speichern</button>', '')
            . '<p class="muted small">Moderatoren ernennst du in der App (👑 Admin → Freischalt-Schlüssel mit „🛡 Moderator“).</p></div>';
        echo '<div class="panel"><div class="panel-title"><span>// Einsprüche</span></div>';
        if (!$d['appeals']) echo '<p class="muted">Keine Einsprüche.</p>';
        foreach ($d['appeals'] as $ap) {
            echo '<div class="nx-item"><div><code>' . e($ap['target']) . '</code> · ' . e(nx_adm_when($ap['created'])) . ' · <span class="badge">' . e($ap['status']) . '</span></div><p>' . nl2br(e($ap['text'])) . '</p>';
            if ($ap['status'] === 'open') echo nx_adm_form('nx_warn', 'nx_warn_appeal', '<input type="hidden" name="id" value="' . (int)$ap['id'] . '"><input type="text" name="answer" placeholder="Antwort (optional)"> <button class="btn btn-gold" name="accept" value="1" onclick="return confirm(\'Einspruch annehmen und ALLE Meldungen löschen?\')">✔ Annehmen</button> <button class="btn">✖ Ablehnen</button>', '');
            elseif ($ap['answer'] !== '') echo '<p class="muted small">Antwort: ' . e($ap['answer']) . '</p>';
            echo '</div>';
        }
        echo '</div><div class="panel"><div class="panel-title"><span>// Gemeldete Personen</span></div>';
        if (!$by) echo '<p class="muted">Noch keine Meldungen.</p>';
        foreach ($by as $target => $list) {
            echo '<details class="nx-item"><summary><b>' . e($list[0]['target_name'] ?: $target) . '</b> <code>' . e($target) . '</code> <span class="badge' . (count($list) >= $d['min'] ? ' red' : '') . '">' . count($list) . (count($list) >= $d['min'] ? ' · sichtbar' : '') . '</span> <a href="https://vrchat.com/home/user/' . e($target) . '" target="_blank" rel="noopener noreferrer">↗ VRChat</a></summary>';
            foreach ($list as $r) echo '<div class="nx-sub"><div class="muted small">#' . (int)$r['id'] . ' · von ' . e($r['reporter_name'] ?: $r['reporter']) . ' · ' . e(nx_adm_when($r['created'])) . ' · läuft ab ' . e(nx_adm_when($r['expires'])) . '</div>'
                . nx_adm_form('nx_warn', 'nx_warn_update', '<input type="hidden" name="id" value="' . (int)$r['id'] . '">' . $reasons($r['reason']) . ' <input type="text" name="name" value="' . e($r['target_name']) . '" placeholder="Anzeigename"> <input type="text" name="note" value="' . e($r['note']) . '" placeholder="Notiz"> <label>neu gültig <input type="number" name="days" min="0" max="365" value="0" style="width:70px"> Tage</label><textarea name="evidence" rows="2" style="width:100%">' . e($r['evidence']) . '</textarea><button class="btn btn-gold">Speichern</button>', '')
                . nx_adm_form('nx_warn', 'nx_warn_delete', '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn btn-sm btn-danger" onclick="return confirm(\'Meldung löschen?\')">🗑 Meldung löschen</button>') . '</div>';
            echo nx_adm_form('nx_warn', 'nx_warn_clear', '<input type="hidden" name="target" value="' . e($target) . '"><button class="btn btn-sm btn-danger" onclick="return confirm(\'Alle Meldungen zu dieser Person löschen?\')">🗑 Alle Meldungen löschen</button>') . '</details>';
        }
        echo '</div><div class="panel"><div class="panel-title"><span>// Protokoll</span></div><div class="nx-log">';
        foreach ($d['log'] as $l) echo '<div>' . e(nx_adm_when($l['ts'])) . ' · <b>' . e($l['action']) . '</b> ' . ($l['target'] ? '<code>' . e($l['target']) . '</code> ' : '') . '<span class="muted">' . e($l['actor']) . ' ' . e($l['detail']) . '</span></div>';
        echo '</div></div>';
        return;
    }
    if ($tab === 'nx_comm') {
        $q = nc_mod_queue(['vrc_id' => 'web', '_mod' => true, '_nsfw' => true]);
        $posts = (int)$db->query("SELECT COUNT(*) FROM comm_posts WHERE status = 'visible'")->fetchColumn();
        echo '<div class="kpis"><div class="kpi"><span class="k">Beiträge</span><span class="v">' . $posts . '</span></div><div class="kpi"><span class="k">Offene Meldungen</span><span class="v">' . count($q['reports']) . '</span></div>'
            . '<div class="kpi"><span class="k">Bilder zur Freigabe</span><span class="v">' . count($q['media']) . '</span></div><div class="kpi"><span class="k">Sanktionen</span><span class="v">' . count($q['sanctions']) . '</span></div></div>';
        $mod = function (string $do, string $label, array $f, string $cls = '', string $confirm = '') {
            $h = '<input type="hidden" name="do" value="' . e($do) . '">'; foreach ($f as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e((string)$v) . '">';
            return nx_adm_form('nx_comm', 'nx_comm', $h . '<button class="btn btn-sm ' . $cls . '"' . ($confirm ? ' onclick="return confirm(\'' . e($confirm) . '\')"' : '') . '>' . $label . '</button>');
        };
        echo '<div class="panel"><div class="panel-title"><span>// Meldungen</span></div>';
        if (!$q['reports']) echo '<p class="muted">Keine offenen Meldungen.</p>';
        foreach ($q['reports'] as $r) {
            echo '<div class="nx-item"><div class="muted small">Beitrag #' . (int)$r['post_id'] . ' · ' . e(nx_adm_when($r['created'])) . ' · Grund: ' . e($r['reason']) . ($r['rating'] === 'nsfw' ? ' · 🔞' : '') . ' · von <code>' . e($r['author']) . '</code></div>'
                . '<p>' . nl2br(e(mb_substr((string)$r['text'], 0, 500))) . '</p><div class="tools">'
                . $mod('hide', '🙈 Ausblenden', ['id' => $r['post_id']]) . ($r['rating'] !== 'nsfw' ? $mod('nsfw', '🔞 Als NSFW umstufen', ['id' => $r['post_id']]) : '')
                . $mod('delete', '🗑 Löschen', ['id' => $r['post_id']], 'btn-danger', 'Beitrag löschen?') . $mod('mute', '🔇 24 h', ['vrc_id' => $r['author'], 'hours' => 24])
                . $mod('ban', '⛔ Sperren', ['vrc_id' => $r['author']], 'btn-danger', 'Konto in der Community sperren?') . $mod('resolve', '✔ Erledigt', ['report' => $r['id']]) . '</div></div>';
        }
        echo '</div><div class="panel"><div class="panel-title"><span>// NSFW-Bilder zur Freigabe</span></div>';
        if (!$q['media']) echo '<p class="muted">Nichts zu prüfen.</p>';
        echo '<div class="nx-media">';
        foreach ($q['media'] as $m) echo '<figure><img loading="lazy" src="admin.php?tab=nx_comm&nx_img=' . e($m['id']) . '" alt=""><figcaption>' . $mod('media_ok', '✔', ['media' => $m['id']], 'btn-gold') . $mod('media_reject', '✖', ['media' => $m['id']], 'btn-danger') . '</figcaption></figure>';
        echo '</div></div><div class="panel"><div class="panel-title"><span>// Wartende Beiträge</span></div>';
        if (!$q['pending']) echo '<p class="muted">–</p>';
        foreach ($q['pending'] as $p) echo '<div class="nx-item"><div class="muted small">#' . (int)$p['id'] . ' · ' . e($p['name'] ?: $p['author']) . ' · ' . e($p['rating']) . '</div><p>' . nl2br(e(mb_substr($p['text'], 0, 500))) . '</p><div class="tools">' . $mod('approve', '✔ Freigeben', ['id' => $p['id']], 'btn-gold') . $mod('delete', '🗑', ['id' => $p['id']], 'btn-danger', 'Beitrag löschen?') . '</div></div>';
        echo '</div><div class="panel"><div class="panel-title"><span>// Sanktionen</span></div>';
        foreach ($q['sanctions'] as $s) echo '<div class="nx-item"><code>' . e($s['vrc_id']) . '</code> ' . ($s['banned'] ? '⛔ gesperrt' : '🔇 bis ' . e(nx_adm_when($s['mute_until']))) . ($s['note'] ? ' · ' . e($s['note']) : '') . ' ' . $mod('unban', 'Aufheben', ['vrc_id' => $s['vrc_id']]) . '</div>';
        echo nx_adm_form('nx_comm', 'nx_comm', '<input type="hidden" name="do" value="mute"><input type="text" name="vrc_id" placeholder="usr_…"> <input type="number" name="hours" min="1" max="2160" value="24" style="width:80px"> Std. <input type="text" name="note" placeholder="Notiz"> <button class="btn">🔇 Stummschalten</button>', '');
        echo '</div><div class="panel"><div class="panel-title"><span>// Protokoll</span></div><div class="nx-log">';
        foreach ($q['log'] as $l) echo '<div>' . e(nx_adm_when($l['ts'])) . ' · <b>' . e($l['action']) . '</b> ' . ($l['target'] ? '<code>' . e($l['target']) . '</code> ' : '') . '<span class="muted">' . e($l['actor']) . ' ' . e($l['detail']) . '</span></div>';
        echo '</div></div>';
        return;
    }
    if ($tab === 'nx_cadm') { admin_nexus_cadm_view(); return; }
    if ($tab === 'nx_lic') {
        $keys = nl_list(); $now = time();
        $act = count(array_filter($keys, fn($k) => !$k['revoked'] && (!$k['exp'] || $k['exp'] > $now)));
        $soon = count(array_filter($keys, fn($k) => !$k['revoked'] && $k['exp'] && $k['exp'] > $now && $k['exp'] < $now + 14 * 86400));
        echo '<div class="kpis"><div class="kpi"><span class="k">Schlüssel</span><span class="v">' . count($keys) . '</span></div><div class="kpi"><span class="k">Aktiv</span><span class="v">' . $act . '</span></div>'
            . '<div class="kpi"><span class="k">Laufen in 14 Tagen ab</span><span class="v">' . $soon . '</span></div><div class="kpi"><span class="k">Gesperrt</span><span class="v">' . count(nl_revoked()) . '</span></div></div>';
        echo '<div class="panel"><div class="panel-title"><span>// Freischalt-Schlüssel (aus der App gemeldet)</span></div><p class="muted small">Ausstellen und Verlängern geht nur in der App (Admin → 🔑), weil nur dort der private Schlüssel liegt. Sperren hier wirkt in allen Apps beim nächsten Abgleich (alle 6 h) und sofort für Warnliste &amp; Community-Moderation.</p>'
            . '<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Name</th><th>VRChat-ID</th><th>Rollen</th><th>Ausgestellt</th><th>Gültig bis</th><th>Zuletzt geprüft</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($keys as $k) {
            $st = $k['revoked'] ? '⛔ gesperrt' . ($k['revoked_src'] ? ' (' . e($k['revoked_src'] === 'web' ? 'Webseite' : 'App') . ')' : '') : ($k['exp'] && $k['exp'] < $now ? '⌛ abgelaufen' : ($k['exp'] && $k['exp'] < $now + 14 * 86400 ? '⏳ läuft bald ab' : '✔ aktiv'));
            echo '<tr><td>' . e($k['name'] ?: '–') . ($k['note'] ? '<br><small class="muted">' . e($k['note']) . '</small>' : '') . '</td><td><code>' . e($k['uid']) . '</code></td><td>' . e(str_replace(',', ', ', $k['roles'])) . '</td>'
                . '<td>' . e(nx_adm_when($k['iat'])) . '</td><td>' . ($k['exp'] ? e(nx_adm_when($k['exp'])) : '∞') . '</td><td>' . e(nx_adm_when($k['last_check'])) . ($k['last_version'] ? '<br><small class="muted">v' . e($k['last_version']) . ' · ' . (int)$k['checks'] . '×</small>' : '') . '</td><td>' . $st . '</td><td>'
                . nx_adm_form('nx_lic', 'nx_lic', '<input type="hidden" name="kid" value="' . e($k['id']) . '"><input type="hidden" name="do" value="' . ($k['revoked'] ? 'unrevoke' : 'revoke') . '"><button class="btn btn-sm' . ($k['revoked'] ? '' : ' btn-danger') . '">' . ($k['revoked'] ? 'Entsperren' : 'Sperren') . '</button>') . '</td></tr>';
        }
        if (!$keys) echo '<tr><td colspan="8" class="muted">Noch keine Schlüssel gemeldet – in der App unter Admin → 🔑 „☁ Mit der Webseite abgleichen“.</td></tr>';
        echo '</tbody></table></div></div>';
        return;
    }
    if ($tab === 'nx_chat') {
        $devs = $db->query('SELECT d.*, a.display_name, (SELECT COUNT(*) FROM chat_opks o WHERE o.device_id = d.device_id) AS opks, (SELECT COUNT(*) FROM chat_inbox i WHERE i.to_device = d.device_id) AS waiting FROM chat_devices d LEFT JOIN nexus_accounts a ON a.vrc_id = d.vrc_id ORDER BY d.last_seen DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
        $wait = (int)$db->query('SELECT COUNT(*) FROM chat_inbox')->fetchColumn();
        echo '<div class="kpis"><div class="kpi"><span class="k">Chat-Geräte</span><span class="v">' . count($devs) . '</span></div><div class="kpi"><span class="k">Wartende Nachrichten</span><span class="v">' . $wait . '</span></div></div>';
        echo '<div class="panel"><div class="panel-title"><span>// Geräte</span></div><p class="muted small">🔒 Ende-zu-Ende-verschlüsselt: Hier stehen nur öffentliche Schlüssel und Zähler – Inhalte kann auch die Webseite nicht lesen. Nachrichten werden nach dem Abholen bzw. spätestens nach 30 Tagen gelöscht.</p>'
            . '<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Konto</th><th>Gerät</th><th>Einmal-Schlüssel</th><th>Wartend</th><th>Angemeldet</th><th>Zuletzt</th><th></th></tr></thead><tbody>';
        foreach ($devs as $d) echo '<tr><td>' . e($d['display_name'] ?: $d['vrc_id']) . '</td><td><code>' . e($d['device_id']) . '</code></td><td>' . (int)$d['opks'] . '</td><td>' . (int)$d['waiting'] . '</td><td>' . e(nx_adm_when($d['created'])) . '</td><td>' . e(nx_adm_when($d['last_seen'])) . '</td><td>'
            . nx_adm_form('nx_chat', 'nx_chat_remove', '<input type="hidden" name="device" value="' . e($d['device_id']) . '"><button class="btn btn-sm btn-danger" onclick="return confirm(\'Gerät entfernen? Wartende Nachrichten gehen verloren.\')">Entfernen</button>') . '</td></tr>';
        if (!$devs) echo '<tr><td colspan="7" class="muted">Noch keine Chat-Geräte.</td></tr>';
        echo '</tbody></table></div></div>';
    }
}
