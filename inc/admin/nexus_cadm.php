<?php
/**
 * Admin Core – 🗂️ Community-Verwaltung (Recht „nexus.community“), ergänzt die Moderation (Meldungen/Warteschlange):
 *  - Statistik (Beiträge, aktive Personen, Profile, Weltbeschreibungen, Top-Autor:innen)
 *  - Beiträge durchsuchen (Text, Autor:in, SFW/NSFW, Status) und ausblenden/zeigen/umstufen/löschen
 *  - Profile ansehen und Teile zurücksetzen (Text, Bilder, Steckbrief, 18+-Teil)
 *  - NSFW-Weltbeschreibungen durchsehen und entfernen
 * Alles wird im Warnlisten-/Community-Protokoll und im Admin-Protokoll festgehalten.
 */

function admin_nexus_cadm(string $actor, array $in): string {
    $who = ['vrc_id' => $actor, '_mod' => true, '_nsfw' => true];
    $db = nc_db();
    $do = (string)($in['do'] ?? '');
    $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 300);
    switch ($do) {
        case 'hide': case 'unhide': case 'delete': case 'nsfw':
            $r = nc_mod($who, $do, $in);
            audit('Nexus-Community', $do . ' #' . (int)($in['id'] ?? 0), 'info');
            return (string)($r['msg'] ?? 'Erledigt.');
        case 'sfw':   // falsch als NSFW eingestuft → zurück in den SFW-Feed (nur der Beitrag selbst)
            $p = nc_post_row((int)($in['id'] ?? 0));
            if (!$p) throw new InvalidArgumentException('Beitrag nicht gefunden.');
            $db->prepare("UPDATE comm_posts SET rating = 'sfw' WHERE id = ?")->execute([(int)$p['id']]);
            nw_log($actor, 'Admin: als SFW eingestuft', $p['author'], '#' . (int)$p['id']);
            audit('Nexus-Community', 'sfw #' . (int)$p['id']);
            return 'Beitrag ist wieder SFW.';
        case 'profile_reset':
            $id = strtolower((string)($in['vrc_id'] ?? ''));
            if (!nx_uid_ok($id)) throw new InvalidArgumentException('Ungültige VRChat-User-ID.');
            $parts = array_values(array_intersect(['text', 'images', 'facts', 'nsfw'], (array)($in['parts'] ?? [])));
            if (!$parts) throw new InvalidArgumentException('Bitte auswählen, was zurückgesetzt werden soll.');
            $set = [];
            if (in_array('text', $parts, true)) $set[] = "bio = '', pronouns = '', languages = '', interests = '', links = '[]'";
            if (in_array('images', $parts, true)) $set[] = "avatar = '', banner = ''";
            if (in_array('facts', $parts, true)) $set[] = "facts = '{}'";
            if (in_array('nsfw', $parts, true)) $set[] = "nsfw_bio = '', nsfw_facts = '{}'";
            $db->prepare('UPDATE comm_profiles SET ' . implode(', ', $set) . ', updated = ? WHERE vrc_id = ?')->execute([time(), $id]);
            nw_log($actor, 'Admin: Profil zurückgesetzt', $id, implode(', ', $parts) . ($note !== '' ? ' – ' . $note : ''));
            audit('Nexus-Community: Profil zurückgesetzt', $id . ' · ' . implode(', ', $parts), 'warn');
            return 'Profil zurückgesetzt (' . implode(', ', $parts) . ').';
        case 'wnote_del':
            nc_mod($who, 'wnote_del', $in);
            audit('Nexus-Community', 'Weltbeschreibung entfernt');
            return 'Weltbeschreibung entfernt.';
    }
    throw new InvalidArgumentException('Unbekannte Aktion.');
}

function admin_nexus_cadm_view(): void {
    $db = nc_db();
    $now = time();
    $q = trim((string)($_GET['q'] ?? '')); $author = strtolower(trim((string)($_GET['author'] ?? '')));
    $rating = in_array($_GET['rating'] ?? '', ['sfw', 'nsfw'], true) ? $_GET['rating'] : '';
    $status = in_array($_GET['status'] ?? '', ['visible', 'hidden', 'pending', 'deleted'], true) ? $_GET['status'] : '';
    $one = fn(string $sql, array $p = []) => (function () use ($db, $sql, $p) { $st = $db->prepare($sql); $st->execute($p); return (int)$st->fetchColumn(); })();
    $k = [
        'Beiträge' => $one("SELECT COUNT(*) FROM comm_posts WHERE status = 'visible'"),
        '7 Tage' => $one("SELECT COUNT(*) FROM comm_posts WHERE status = 'visible' AND created > ?", [$now - 7 * 86400]),
        'Aktiv (30 T.)' => $one("SELECT COUNT(DISTINCT author) FROM comm_posts WHERE created > ?", [$now - 30 * 86400]),
        'Profile' => $one('SELECT COUNT(*) FROM comm_profiles'),
        '18+-Profile' => $one("SELECT COUNT(*) FROM comm_profiles WHERE nsfw_facts <> '{}' OR nsfw_bio <> ''"),
        'Weltbeschr.' => $one('SELECT COUNT(*) FROM comm_world_notes'),
    ];
    echo '<div class="kpis">';
    foreach ($k as $l => $v) echo '<div class="kpi"><span class="k">' . e($l) . '</span><span class="v">' . $v . '</span></div>';
    echo '</div>';
    $top = $db->prepare("SELECT p.author, COALESCE(a.display_name, p.author) AS name, COUNT(*) AS n FROM comm_posts p LEFT JOIN nexus_accounts a ON a.vrc_id = p.author WHERE p.status = 'visible' AND p.created > ? GROUP BY p.author, a.display_name ORDER BY n DESC LIMIT 10");
    $top->execute([$now - 30 * 86400]);
    echo '<div class="grid2"><div class="panel"><div class="panel-title"><span>// Aktivste (30 Tage)</span></div>';
    foreach ($top->fetchAll(PDO::FETCH_ASSOC) as $t) echo '<div class="nx-item"><a href="admin.php?tab=nx_cadm&amp;author=' . e($t['author']) . '">' . e($t['name']) . '</a> <span class="badge">' . (int)$t['n'] . '</span></div>';
    echo '</div><div class="panel"><div class="panel-title"><span>// Profil verwalten</span></div>'
        . '<form method="GET" class="inline"><input type="hidden" name="tab" value="nx_cadm"><input type="text" name="profile" value="' . e((string)($_GET['profile'] ?? '')) . '" placeholder="usr_… oder Name"> <button class="btn btn-sm">Suchen</button></form>';
    $pq = trim((string)($_GET['profile'] ?? ''));
    if ($pq !== '') {
        $st = $db->prepare("SELECT p.*, a.display_name FROM comm_profiles p LEFT JOIN nexus_accounts a ON a.vrc_id = p.vrc_id WHERE p.vrc_id = ? OR a.display_name LIKE ? LIMIT 5");
        $st->execute([strtolower($pq), '%' . $pq . '%']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) echo '<p class="muted">Kein Profil gefunden.</p>';
        foreach ($rows as $p) {
            $nf = json_decode($p['nsfw_facts'] ?: '{}', true) ?: [];
            echo '<div class="nx-item"><b>' . e($p['display_name'] ?: $p['vrc_id']) . '</b> <code>' . e($p['vrc_id']) . '</code>'
                . '<p class="small">' . nl2br(e(mb_substr((string)$p['bio'], 0, 400))) . '</p>'
                . ($p['avatar'] || $p['banner'] ? '<p class="muted small">🖼 Profilbild/Banner gesetzt</p>' : '')
                . ($p['nsfw_bio'] !== '' || $nf ? '<p class="muted small">🔞 18+-Teil: ' . count($nf['kinks'] ?? []) . ' Kinks, ' . count($nf['avatars'] ?? []) . ' Avatare, ' . count($nf['worlds'] ?? []) . ' Welten</p>' : '')
                . nx_adm_form('nx_cadm', 'nx_cadm', '<input type="hidden" name="do" value="profile_reset"><input type="hidden" name="vrc_id" value="' . e($p['vrc_id']) . '">'
                    . '<label class="check small"><input type="checkbox" name="parts[]" value="text"> Text &amp; Links</label> <label class="check small"><input type="checkbox" name="parts[]" value="images"> Bilder</label> '
                    . '<label class="check small"><input type="checkbox" name="parts[]" value="facts"> Steckbrief</label> <label class="check small"><input type="checkbox" name="parts[]" value="nsfw"> 18+-Teil</label> '
                    . '<input type="text" name="note" placeholder="Grund"> <button class="btn btn-sm btn-danger" onclick="return confirm(\'Ausgewählte Teile zurücksetzen?\')">Zurücksetzen</button>', '')
                . '</div>';
        }
    }
    echo '</div></div>';

    // Beiträge durchsuchen
    $where = ['parent >= 0']; $p = [];
    if ($q !== '') { $where[] = 'text LIKE ?'; $p[] = '%' . $q . '%'; }
    if ($author !== '') { $where[] = 'author = ?'; $p[] = $author; }
    if ($rating !== '') { $where[] = 'rating = ?'; $p[] = $rating; }
    $where[] = $status !== '' ? 'status = ?' : "status <> 'deleted'"; if ($status !== '') $p[] = $status;
    $st = $db->prepare('SELECT * FROM comm_posts WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 50');
    $st->execute($p);
    $posts = $st->fetchAll(PDO::FETCH_ASSOC);
    $sel = fn(string $n, array $o, string $cur) => '<select name="' . $n . '">' . implode('', array_map(fn($k, $l) => '<option value="' . e($k) . '"' . ($k === $cur ? ' selected' : '') . '>' . e($l) . '</option>', array_keys($o), $o)) . '</select>';
    echo '<div class="panel"><div class="panel-title"><span>// Beiträge</span><span class="muted small">' . count($posts) . ' (max. 50)</span></div>'
        . '<form method="GET" class="inline"><input type="hidden" name="tab" value="nx_cadm"><input type="search" name="q" value="' . e($q) . '" placeholder="Text"> <input type="text" name="author" value="' . e($author) . '" placeholder="usr_…"> '
        . $sel('rating', ['' => 'SFW + NSFW', 'sfw' => 'SFW', 'nsfw' => '🔞 NSFW'], $rating) . ' ' . $sel('status', ['' => 'sichtbar + versteckt + wartend', 'visible' => 'sichtbar', 'hidden' => 'ausgeblendet', 'pending' => 'wartend', 'deleted' => 'gelöscht'], $status)
        . ' <button class="btn btn-sm">Filtern</button></form>';
    $act = function (string $do, string $label, int $id, string $cls = '', string $confirm = '') {
        return nx_adm_form('nx_cadm', 'nx_cadm', '<input type="hidden" name="do" value="' . $do . '"><input type="hidden" name="id" value="' . $id . '"><button class="btn btn-sm ' . $cls . '"' . ($confirm ? ' onclick="return confirm(\'' . e($confirm) . '\')"' : '') . '>' . $label . '</button>');
    };
    foreach ($posts as $r) {
        $id = (int)$r['id'];
        echo '<div class="nx-item"><div class="muted small">#' . $id . ($r['parent'] ? ' (Antwort auf #' . (int)$r['parent'] . ')' : '') . ' · ' . e(nx_adm_when($r['created'])) . ' · <a href="admin.php?tab=nx_cadm&amp;author=' . e($r['author']) . '"><code>' . e($r['author']) . '</code></a> · '
            . ($r['rating'] === 'nsfw' ? '<span class="badge red">NSFW</span>' : '<span class="badge">SFW</span>') . ' <span class="badge">' . e($r['status']) . '</span>' . ($r['mod_note'] !== '' ? ' · ' . e($r['mod_note']) : '') . '</div>'
            . '<p>' . nl2br(e(mb_substr((string)$r['text'], 0, 600))) . '</p><div class="tools">'
            . ($r['status'] === 'visible' ? $act('hide', '🙈 Ausblenden', $id) : ($r['status'] !== 'deleted' ? $act('unhide', '👁 Zeigen', $id) : ''))
            . ($r['rating'] === 'nsfw' ? $act('sfw', '↩ SFW', $id) : $act('nsfw', '🔞 NSFW', $id))
            . ($r['status'] !== 'deleted' ? $act('delete', '🗑', $id, 'btn-danger', 'Beitrag löschen?') : '') . '</div></div>';
    }
    if (!$posts) echo '<p class="muted">Keine Beiträge.</p>';
    echo '</div>';

    // Weltbeschreibungen
    $wn = $db->query('SELECT n.*, a.display_name FROM comm_world_notes n LEFT JOIN nexus_accounts a ON a.vrc_id = n.author ORDER BY COALESCE(NULLIF(n.updated, 0), n.created) DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    echo '<div class="panel"><div class="panel-title"><span>// 🔞 Weltbeschreibungen</span></div>';
    foreach ($wn as $w) echo '<div class="nx-item"><div class="muted small"><code>' . e($w['world_id']) . '</code> · ' . e($w['display_name'] ?: $w['author']) . ' · ' . e(nx_adm_when($w['updated'] ?: $w['created'])) . '</div><p>' . nl2br(e(mb_substr((string)$w['text'], 0, 500))) . '</p>'
        . nx_adm_form('nx_cadm', 'nx_cadm', '<input type="hidden" name="do" value="wnote_del"><input type="hidden" name="world" value="' . e($w['world_id']) . '"><input type="hidden" name="vrc_id" value="' . e($w['author']) . '"><input type="text" name="note" placeholder="Grund"> <button class="btn btn-sm btn-danger" onclick="return confirm(\'Beschreibung entfernen?\')">Entfernen</button>', '') . '</div>';
    if (!$wn) echo '<p class="muted">Noch keine.</p>';
    echo '</div>';
}
