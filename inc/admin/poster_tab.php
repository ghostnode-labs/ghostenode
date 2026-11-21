<?php
/**
 * Admin-Zentrale – Bereich „🖼 VRChat-Poster“ (Recht posters.manage): Grenzen & Upload-Wege einstellen, alle Slots im Überblick.
 * Die Rechte selbst (posters.view/upload/edit/manage) vergibt man wie alle anderen unter „Rollen & Rechte“.
 */
require_once __DIR__ . '/../modules/posters.php';

const PS_ADMIN_TABS = ['posters' => ['🖼️', 'VRChat-Poster', 'posters.manage']];

function admin_poster_post(string $act, array $in): string {
    switch ($act) {
        case 'ps_settings':
            set_setting('ps_max_mb', (string)max(1, min(100, (int)($in['max_mb'] ?? 15))));
            set_setting('ps_max_dim', (string)max(256, min(8192, (int)($in['max_dim'] ?? 2048))));
            set_setting('ps_quota_mb', (string)max(0, (int)($in['quota_mb'] ?? 500)));
            set_setting('ps_ftp', !empty($in['ftp']) ? '1' : '0');
            set_setting('ps_links', !empty($in['links']) ? '1' : '0');
            set_setting('ps_live_min', (string)max(5, (int)($in['live_min'] ?? 30)));
            set_setting('ps_keep', (string)max(0, (int)($in['keep'] ?? 0)));
            $u = trim((string)($in['public_url'] ?? ''));
            if ($u !== '' && !preg_match('#^https?://[^\s/]+(/[^\s]*)?$#i', $u)) throw new InvalidArgumentException('Öffentliche Adresse: z. B. https://example.org');
            set_setting('ps_public_url', rtrim($u, '/'));
            audit('VRChat-Poster: Einstellungen gespeichert');
            return 'Gespeichert.';
        case 'ps_run':
            return 'Poster: ' . ps_task();
        case 'ps_slot_toggle':
            $s = ps_slot((int)($in['id'] ?? 0));
            if (!$s) throw new InvalidArgumentException('Slot nicht gefunden.');
            ps_db()->prepare('UPDATE slots SET enabled = ? WHERE id = ?')->execute([(int)$s['enabled'] ? 0 : 1, (int)$s['id']]);
            ps_log((int)$s['id'], (int)$s['enabled'] ? 'Admin: Slot ausgeschaltet' : 'Admin: Slot eingeschaltet');
            audit('VRChat-Poster: Slot ' . ((int)$s['enabled'] ? 'aus' : 'an'), $s['slug']);
            return (int)$s['enabled'] ? 'Slot ausgeschaltet.' : 'Slot eingeschaltet.';
    }
    throw new InvalidArgumentException('Unbekannte Aktion.');
}

function admin_poster_view(): void {
    $c = ps_cfg();
    $st = ps_stats();
    $mb = fn($b) => number_format($b / 1048576, 1, ',', '.') . ' MB';
    $slots = ps_db()->query('SELECT s.*, (SELECT COUNT(*) FROM images i WHERE i.slot_id = s.id) AS n, (SELECT COALESCE(SUM(size), 0) FROM images i WHERE i.slot_id = s.id) AS bytes FROM slots s ORDER BY s.folder, s.title')->fetchAll();
    $perm_roles = site_db()->query("SELECT r.name, r.is_system, GROUP_CONCAT(p.permission) AS perms FROM roles r LEFT JOIN role_permissions p ON p.role_id = r.id AND p.permission LIKE 'posters.%' GROUP BY r.id ORDER BY r.name")->fetchAll();
    $max_up = ini_get('upload_max_filesize') . ' / post_max_size ' . ini_get('post_max_size');
    echo '<div class="kpis"><div class="kpi"><span class="k">Slots</span><span class="v">' . $st['slots'] . '</span></div><div class="kpi"><span class="k">Bilder</span><span class="v">' . $st['images'] . '</span></div>'
        . '<div class="kpi"><span class="k">Speicher</span><span class="v">' . $mb($st['bytes']) . '</span></div><div class="kpi"><span class="k">Abrufe</span><span class="v">' . number_format($st['hits'], 0, ',', '.') . '</span></div>'
        . '<div class="kpi"><span class="k">Live-Bildlinks</span><span class="v">' . $st['live'] . '</span></div></div>';
    echo '<div class="grid2"><form method="POST" class="panel"><input type="hidden" name="tab" value="posters"><input type="hidden" name="action" value="ps_settings"><div class="panel-title"><span>// Grenzen &amp; Upload-Wege</span></div>'
        . '<label class="field">Max. Dateigröße je Bild (MB) – PHP erlaubt gerade ' . e($max_up) . '</label><input type="number" name="max_mb" min="1" max="100" value="' . $c['max_mb'] . '">'
        . '<label class="field">Max. Kantenlänge (px) – VRChat lädt bis 2048</label><input type="number" name="max_dim" min="256" max="8192" value="' . $c['max_dim'] . '">'
        . '<label class="field">Speicher je Benutzer (MB, 0 = unbegrenzt)</label><input type="number" name="quota_mb" min="0" value="' . $c['quota_mb'] . '">'
        . '<label class="field">Alte Bilder je Slot behalten (0 = alle; aktive, Ersatz- und geplante Bilder bleiben immer)</label><input type="number" name="keep" min="0" value="' . $c['keep'] . '">'
        . '<label class="field">„Live“-Bildlinks neu laden alle … Minuten</label><input type="number" name="live_min" min="5" value="' . $c['live_min'] . '">'
        . '<label class="field">Öffentliche Adresse der Webseite (leer = automatisch, z. B. https://example.org)</label><input type="url" name="public_url" value="' . e($c['public_url']) . '" placeholder="automatisch">'
        . '<label class="check"><input type="checkbox" name="ftp" value="1"' . ($c['ftp'] ? ' checked' : '') . '> FTP-Eingangsordner (' . e(ltrim(str_replace(APP_ROOT, '', ps_dir('inbox')), '/')) . '/&lt;adresse&gt;/)</label>'
        . '<label class="check"><input type="checkbox" name="links" value="1"' . ($c['links'] ? ' checked' : '') . '> Bilder per Bildlink übernehmen</label>'
        . '<button class="btn btn-gold" style="margin-top:10px">Speichern</button></form>';
    echo '<div class="panel"><div class="panel-title"><span>// Rechte nach Rolle</span></div><p class="muted small">Vergeben unter <a href="admin.php?tab=roles">Rollen &amp; Rechte</a> (Gruppe „Poster“). Zusätzlich kann jeder Besitzer seinen Slot und einzelne Bilder für bestimmte Benutzer freigeben. Gäste sehen nur Slots mit „Öffentlich“.</p><table class="tbl"><tr><th>Rolle</th><th>Poster-Rechte</th></tr>';
    foreach ($perm_roles as $r) echo '<tr><td>' . e($r['name']) . '</td><td>' . ((int)$r['is_system'] ? 'alle (Administrator)' : e(str_replace('posters.', '', (string)$r['perms']) ?: '–')) . '</td></tr>';
    echo '</table><form method="POST" style="margin-top:12px"><input type="hidden" name="tab" value="posters"><input type="hidden" name="action" value="ps_run"><button class="btn btn-sm">📥 FTP-Eingang &amp; Live-Links jetzt abarbeiten</button></form>'
        . '<p class="muted small">Läuft sonst automatisch über den Aufgabenplaner (Aufgabe „VRChat-Poster“, alle 15 min).</p><p><a class="btn btn-sm btn-gold" href="poster.php">🖼 Zum Poster-Dashboard</a></p></div></div>';
    echo '<div class="panel"><div class="panel-title"><span>// Alle Slots (' . count($slots) . ')</span></div><div class="tbl-wrap"><table class="tbl"><tr><th>Slot</th><th>Ordner</th><th>Besitzer</th><th>Bilder</th><th>Speicher</th><th>Abrufe</th><th>Zuletzt</th><th>Sichtbar</th><th></th></tr>';
    foreach ($slots as $s) {
        echo '<tr><td><b>' . e($s['title']) . '</b><br><a href="' . e(ps_public_url($s)) . '" target="_blank" rel="noopener"><code>' . e($s['slug']) . '</code></a></td><td>' . e($s['folder']) . '</td><td>' . e(ps_user_name((int)$s['owner'])) . '</td><td>' . (int)$s['n'] . '</td><td>' . $mb((int)$s['bytes']) . '</td>'
            . '<td>' . (int)$s['hits'] . '</td><td>' . ($s['last_hit'] ? e(date('d.m. H:i', (int)$s['last_hit'])) : '–') . '</td><td>' . e(explode(' ', PS_VIS[$s['visibility']] ?? $s['visibility'])[0]) . '</td>'
            . '<td><form method="POST"><input type="hidden" name="tab" value="posters"><input type="hidden" name="action" value="ps_slot_toggle"><input type="hidden" name="id" value="' . (int)$s['id'] . '"><button class="btn btn-sm' . ((int)$s['enabled'] ? '' : ' btn-gold') . '">' . ((int)$s['enabled'] ? '⏸ Aus' : '▶ An') . '</button></form></td></tr>';
    }
    echo '</table></div></div>';
}
