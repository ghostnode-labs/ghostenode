<?php
declare(strict_types=1);
/**
 * 7.0 Clubs & Events (gleiches Nexus-Konto wie Community/App; Identität = VRChat-ID).
 *   Clubs: Name, Adresse, Beschreibung, Regeln, Sprache, Thema, Beitritt offen | Bewerbung (mit Einlass-Fragen) | nur Einladung,
 *          Rollen owner / mod / member, Feed (Beiträge, anheften), Galerie (Bild-Links), Mitglieder, Bewerbungen.
 *   Events: Termin mit Ort (Welt/Instanz-Text), Plätzen, Anmeldung, Warteliste (rückt automatisch nach), iCal je Club,
 *           öffentlicher Kalender aller öffentlichen Clubs mit Filter (Sprache, Thema, Uhrzeit), Archiv vergangener Events.
 * 7.3.2: 18+-Clubs (clubs.adult) sehen nur freigegebene Konten – Gate in cl_get/cl_list/cl_calendar über ad_ok() (inc/modules/adult.php).
 */
require_once __DIR__ . '/nexuscomm.php';
require_once __DIR__ . '/clubs2.php';   // 7.2: Moderation 2.0, Tickets, Partner, Einladungslinks, Statistik
require_once __DIR__ . '/adult.php';    // 7.3.2: 18+-Clubs, Paar-Profile, Vorlagen, Einlass-Liste

const CL_JOIN = ['open' => 'offen', 'apply' => 'mit Bewerbung', 'invite' => 'nur mit Einladung'];
const CL_TOPICS = ['allgemein' => '💬 Allgemein', 'furry' => '🦊 Furry', 'musik' => '🎵 Musik & DJ', 'rp' => '🎭 Rollenspiel', 'tanz' => '💃 Tanzen', 'kunst' => '🎨 Kunst & Avatare', 'chill' => '😌 Chillen', 'games' => '🎮 Spiele', 'lernen' => '📚 Lernen & Sprache', 'events' => '🎉 Partys'];
const CL_LANGS = ['de' => '🇩🇪 Deutsch', 'en' => '🇬🇧 English', 'fr' => '🇫🇷 Français', 'ja' => '🇯🇵 日本語', 'multi' => '🌐 gemischt'];

function cl_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (setting('clubs_schema', '0') === '1') return;
    nc_db()->exec("
        CREATE TABLE IF NOT EXISTS clubs (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT NOT NULL UNIQUE, name TEXT NOT NULL, descr TEXT NOT NULL DEFAULT '', rules TEXT NOT NULL DEFAULT '',
            lang TEXT NOT NULL DEFAULT 'de', topic TEXT NOT NULL DEFAULT 'allgemein', join_mode TEXT NOT NULL DEFAULT 'open', questions TEXT NOT NULL DEFAULT '[]', banner TEXT NOT NULL DEFAULT '',
            color TEXT NOT NULL DEFAULT '', listed INTEGER NOT NULL DEFAULT 1, owner TEXT NOT NULL, created INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'active');
        CREATE TABLE IF NOT EXISTS club_members (club_id INTEGER NOT NULL, vrc_id TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'member', status TEXT NOT NULL DEFAULT 'active',
            answers TEXT NOT NULL DEFAULT '[]', joined INTEGER NOT NULL, PRIMARY KEY (club_id, vrc_id));
        CREATE TABLE IF NOT EXISTS club_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, club_id INTEGER NOT NULL, author TEXT NOT NULL, text TEXT NOT NULL, pinned INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS club_gallery (id INTEGER PRIMARY KEY AUTOINCREMENT, club_id INTEGER NOT NULL, url TEXT NOT NULL, caption TEXT NOT NULL DEFAULT '', author TEXT NOT NULL, created INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS club_events (id INTEGER PRIMARY KEY AUTOINCREMENT, club_id INTEGER NOT NULL, title TEXT NOT NULL, descr TEXT NOT NULL DEFAULT '', starts INTEGER NOT NULL, ends INTEGER NOT NULL,
            place TEXT NOT NULL DEFAULT '', capacity INTEGER NOT NULL DEFAULT 0, members_only INTEGER NOT NULL DEFAULT 0, created_by TEXT NOT NULL, created INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'active');
        CREATE TABLE IF NOT EXISTS club_rsvp (event_id INTEGER NOT NULL, vrc_id TEXT NOT NULL, status TEXT NOT NULL, created INTEGER NOT NULL, PRIMARY KEY (event_id, vrc_id));
        CREATE INDEX IF NOT EXISTS idx_club_ev ON club_events(club_id, starts);
        CREATE INDEX IF NOT EXISTS idx_club_posts ON club_posts(club_id, id);
    ");
    set_setting('clubs_schema', '1');
}
function cl_db(): PDO { cl_schema(); cl2_schema(); ad_schema(); return nc_db(); }
function cl_need_ok(array $who): void { $s = nc_sanction($who['vrc_id']); if (!empty($s['banned']) || (int)($s['mute_until'] ?? 0) > time()) throw new NcError('Dein Konto ist für die Community gerade gesperrt.'); }
function cl_get(string $key): array {
    $st = cl_db()->prepare(ctype_digit($key) ? 'SELECT * FROM clubs WHERE id = ? AND status = \'active\'' : 'SELECT * FROM clubs WHERE slug = ? AND status = \'active\'');
    $st->execute([ctype_digit($key) ? (int)$key : strtolower($key)]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c || (!empty($c['adult']) && !ad_ok())) throw new NcError('Club nicht gefunden.');   // 7.3.2: 18+ nur mit Freigabe
    return $c;
}
function cl_member(int $cid, string $vid): ?array { $st = cl_db()->prepare('SELECT * FROM club_members WHERE club_id = ? AND vrc_id = ?'); $st->execute([$cid, $vid]); return $st->fetch(PDO::FETCH_ASSOC) ?: null; }
function cl_role(array $c, array $who): string { if (!empty($who['_mod'])) return 'site_mod'; $m = cl_member((int)$c['id'], $who['vrc_id']); return $m && $m['status'] === 'active' ? $m['role'] : ($m['status'] ?? ''); }
function cl_is_staff(array $c, array $who): bool { return in_array(cl_role($c, $who), ['owner', 'mod', 'site_mod'], true); }
function cl_need_staff(array $c, array $who): void { if (!cl_is_staff($c, $who)) throw new NcError('Nur für Club-Leitung und Moderation.'); }
function cl_need_member(array $c, array $who): void { if (!in_array(cl_role($c, $who), ['owner', 'mod', 'member', 'site_mod'], true)) throw new NcError('Nur für Mitglieder.'); }

function cl_pub(array $c, ?array $who = null): array {
    $db = cl_db();
    $n = $db->prepare("SELECT COUNT(*) FROM club_members WHERE club_id = ? AND status = 'active'"); $n->execute([$c['id']]);
    $next = $db->prepare("SELECT COUNT(*) FROM club_events WHERE club_id = ? AND status = 'active' AND ends > ?"); $next->execute([$c['id'], time()]);
    return ['id' => (int)$c['id'], 'slug' => $c['slug'], 'name' => $c['name'], 'descr' => $c['descr'], 'rules' => $c['rules'], 'lang' => $c['lang'], 'topic' => $c['topic'], 'join_mode' => $c['join_mode'],
        'questions' => json_decode($c['questions'], true) ?: [], 'banner' => $c['banner'], 'color' => $c['color'], 'listed' => (bool)$c['listed'], 'members' => (int)$n->fetchColumn(),
        'upcoming' => (int)$next->fetchColumn(), 'created' => (int)$c['created'], 'my_role' => $who ? cl_role($c, $who) : '', 'adult' => !empty($c['adult'])];
}
function cl_clean(array $in, bool $new): array {
    $name = nc_cut((string)($in['name'] ?? ''), 60);
    if (mb_strlen($name) < 3) throw new NcError('Name: mindestens 3 Zeichen.');
    $q = $in['questions'] ?? []; if (is_string($q)) $q = json_decode($q, true) ?: [];
    $q = array_values(array_filter(array_map(fn($x) => nc_cut((string)$x, 160), array_slice((array)$q, 0, 5)), fn($x) => $x !== ''));
    $banner = trim((string)($in['banner'] ?? ''));
    if ($banner !== '' && !preg_match('#^https://[^\s<>"\']{8,400}$#', $banner)) throw new NcError('Banner: nur https-Bildadressen.');
    $color = (string)($in['color'] ?? '');
    $d = ['name' => $name, 'descr' => nc_cut((string)($in['descr'] ?? ''), 2000), 'rules' => nc_cut((string)($in['rules'] ?? ''), 3000),
        'lang' => isset(CL_LANGS[$in['lang'] ?? '']) ? $in['lang'] : 'de', 'topic' => isset(CL_TOPICS[$in['topic'] ?? '']) ? $in['topic'] : 'allgemein',
        'join_mode' => isset(CL_JOIN[$in['join_mode'] ?? '']) ? $in['join_mode'] : 'open', 'questions' => json_encode($q, JSON_UNESCAPED_UNICODE), 'banner' => $banner,
        'color' => preg_match('/^#[0-9a-f]{6}$/i', $color) ? strtolower($color) : '', 'listed' => !isset($in['listed']) || !empty($in['listed']) ? 1 : 0];
    if ($new) {
        $slug = strtolower(trim((string)($in['slug'] ?? '')));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{2,31}$/', $slug)) throw new NcError('Adresse: 3–32 Zeichen, nur a–z, 0–9 und -.');
        $d['slug'] = $slug;
    }
    return $d;
}
function cl_create(array $who, array $in): array {
    cl_need_ok($who);
    $db = cl_db();
    $own = $db->prepare("SELECT COUNT(*) FROM clubs WHERE owner = ? AND status = 'active'"); $own->execute([$who['vrc_id']]);
    if ((int)$own->fetchColumn() >= 3 && empty($who['_mod'])) throw new NcError('Höchstens 3 eigene Clubs.');
    $d = cl_clean($in, true);
    $adult = ad_club_flag($who, $in, $d, null);
    $ex = $db->prepare('SELECT 1 FROM clubs WHERE slug = ?'); $ex->execute([$d['slug']]);
    if ($ex->fetch()) throw new NcError('Diese Adresse ist schon vergeben.');
    $db->prepare('INSERT INTO clubs (slug, name, descr, rules, lang, topic, join_mode, questions, banner, color, listed, owner, created) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$d['slug'], $d['name'], $d['descr'], $d['rules'], $d['lang'], $d['topic'], $d['join_mode'], $d['questions'], $d['banner'], $d['color'], $d['listed'], $who['vrc_id'], time()]);
    $id = (int)$db->lastInsertId();
    if ($adult) $db->prepare('UPDATE clubs SET adult = 1 WHERE id = ?')->execute([$id]);
    $db->prepare("INSERT INTO club_members (club_id, vrc_id, role, status, joined) VALUES (?, ?, 'owner', 'active', ?)")->execute([$id, $who['vrc_id'], time()]);
    return ['club' => cl_pub(cl_get((string)$id), $who), 'msg' => '🏠 Club erstellt.'];
}
function cl_update(array $who, string $key, array $in): array {
    $c = cl_get($key); cl_need_staff($c, $who);
    $old = $c; $old['questions'] = json_decode($c['questions'], true) ?: []; unset($old['id'], $old['slug'], $old['owner'], $old['status'], $old['created']);
    $d = cl_clean($in + $old, false); // fehlende Felder behalten ihren alten Wert
    $adult = ad_club_flag($who, $in, $d, $c);
    cl_db()->prepare('UPDATE clubs SET name=?, descr=?, rules=?, lang=?, topic=?, join_mode=?, questions=?, banner=?, color=?, listed=? WHERE id = ?')
        ->execute([$d['name'], $d['descr'], $d['rules'], $d['lang'], $d['topic'], $d['join_mode'], $d['questions'], $d['banner'], $d['color'], $d['listed'], $c['id']]);
    cl_db()->prepare('UPDATE clubs SET adult = ? WHERE id = ?')->execute([$adult, $c['id']]);
    return ['club' => cl_pub(cl_get((string)$c['id']), $who), 'msg' => 'Gespeichert.'];
}
function cl_list(array $who, array $opt): array {
    $sql = "SELECT * FROM clubs WHERE status = 'active'" . ad_sql(); $args = [];
    if (($opt['mine'] ?? '') !== '') { $sql .= " AND id IN (SELECT club_id FROM club_members WHERE vrc_id = ? AND status IN ('active','pending'))"; $args[] = $who['vrc_id']; }
    else $sql .= ' AND listed = 1';
    if (isset(CL_TOPICS[$opt['topic'] ?? ''])) { $sql .= ' AND topic = ?'; $args[] = $opt['topic']; }
    if (isset(CL_LANGS[$opt['lang'] ?? ''])) { $sql .= ' AND lang = ?'; $args[] = $opt['lang']; }
    if (($q = trim((string)($opt['q'] ?? ''))) !== '') { $sql .= ' AND (name LIKE ? OR descr LIKE ?)'; $args[] = '%' . $q . '%'; $args[] = '%' . $q . '%'; }
    $st = cl_db()->prepare($sql . ' ORDER BY id DESC LIMIT 100'); $st->execute($args);
    return ['items' => array_map(fn($c) => cl_pub($c, $who), $st->fetchAll(PDO::FETCH_ASSOC)), 'topics' => CL_TOPICS, 'langs' => CL_LANGS, 'join' => CL_JOIN, 'adult_ok' => ad_ok()];
}
function cl_view(array $who, string $key): array {
    $c = cl_get($key); $role = cl_role($c, $who); $mem = in_array($role, ['owner', 'mod', 'member', 'site_mod'], true);
    $db = cl_db();
    $out = ['club' => cl_pub($c, $who), 'events' => cl_events_of($who, $c, false), 'is_member' => $mem, 'is_staff' => cl_is_staff($c, $who),
        'partners' => array_values(array_filter(cl2_partners($c), fn($x) => $x['status'] === 'active' || cl_is_staff($c, $who)))];
    if ($b = cl2_ban((int)$c['id'], $who['vrc_id'])) $out['ban'] = ['until' => (int)$b['until'], 'reason' => $b['reason'], 'appeal_status' => $b['appeal_status']];
    if ($out['is_staff']) { $qn = $db->prepare("SELECT COUNT(*) FROM club_posts WHERE club_id = ? AND status = 'pending'"); $qn->execute([$c['id']]); $out['queue'] = (int)$qn->fetchColumn();
        $tn = $db->prepare("SELECT COUNT(*) FROM club_tickets WHERE club_id = ? AND status = 'open'"); $tn->execute([$c['id']]); $out['tickets_open'] = (int)$tn->fetchColumn(); }
    if ($mem || $c['join_mode'] === 'open') {
        $p = $db->prepare("SELECT * FROM club_posts WHERE club_id = ? AND (status = 'ok' OR author = ?) ORDER BY pinned DESC, id DESC LIMIT 50"); $p->execute([$c['id'], $who['vrc_id']]); $posts = $p->fetchAll(PDO::FETCH_ASSOC);
        $g = $db->prepare('SELECT * FROM club_gallery WHERE club_id = ? ORDER BY id DESC LIMIT 60'); $g->execute([$c['id']]); $gal = $g->fetchAll(PDO::FETCH_ASSOC);
        $names = nc_names(array_merge(array_column($posts, 'author'), array_column($gal, 'author')));
        $out['posts'] = array_map(fn($x) => ['id' => (int)$x['id'], 'author' => $x['author'], 'name' => (string)($names[$x['author']] ?? ''), 'text' => $x['text'], 'own' => $x['author'] === $who['vrc_id'], 'held' => ($x['status'] ?? 'ok') === 'pending', 'pinned' => (bool)$x['pinned'], 'created' => (int)$x['created']], $posts);
        $out['gallery'] = array_map(fn($x) => ['id' => (int)$x['id'], 'url' => $x['url'], 'caption' => $x['caption'], 'name' => (string)($names[$x['author']] ?? ''), 'created' => (int)$x['created']], $gal);
    }
    if ($mem) {
        $m = $db->prepare("SELECT vrc_id, role, joined FROM club_members WHERE club_id = ? AND status = 'active' ORDER BY CASE role WHEN 'owner' THEN 0 WHEN 'mod' THEN 1 ELSE 2 END, joined LIMIT 300"); $m->execute([$c['id']]);
        $rows = $m->fetchAll(PDO::FETCH_ASSOC); $names = nc_names(array_column($rows, 'vrc_id'));
        $out['members'] = array_map(fn($r) => ['vrc_id' => $r['vrc_id'], 'name' => (string)($names[$r['vrc_id']] ?? ''), 'role' => $r['role'], 'joined' => (int)$r['joined']], $rows);
        $out['archive'] = cl_events_of($who, $c, true);
    }
    if ($out['is_staff']) {
        $a = $db->prepare("SELECT vrc_id, answers, joined FROM club_members WHERE club_id = ? AND status = 'pending' ORDER BY joined"); $a->execute([$c['id']]);
        $rows = $a->fetchAll(PDO::FETCH_ASSOC); $names = nc_names(array_column($rows, 'vrc_id'));
        $out['applications'] = array_map(fn($r) => ['vrc_id' => $r['vrc_id'], 'name' => (string)($names[$r['vrc_id']] ?? ''), 'answers' => json_decode($r['answers'], true) ?: [], 'at' => (int)$r['joined']], $rows);
    }
    return $out;
}
function cl_join(array $who, string $key, array $answers, string $code = ''): array {
    cl_need_ok($who);
    $c = cl_get($key); $m = cl_member((int)$c['id'], $who['vrc_id']);
    if ($b = cl2_ban((int)$c['id'], $who['vrc_id'])) throw new NcError('Du bist in diesem Club gesperrt' . ((int)$b['until'] ? ' bis ' . date('d.m.Y H:i', (int)$b['until']) : '') . ($b['reason'] !== '' ? ' (' . $b['reason'] . ')' : '') . '. Einspruch ist im Club möglich.');
    $invited = $code !== '' && $c['join_mode'] !== 'open' && cl2_invite_use($c, $code);
    if ($code !== '' && $c['join_mode'] !== 'open' && !$invited) throw new NcError('Der Einladungslink ist ungültig oder abgelaufen.');
    if ($m && $m['status'] === 'active') return ['msg' => 'Du bist schon Mitglied.'];
    if ($m && $m['status'] === 'banned') throw new NcError('Du bist aus diesem Club ausgeschlossen.');
    if ($m && $m['status'] === 'pending') return ['msg' => 'Deine Bewerbung wird geprüft.'];
    if ($c['join_mode'] === 'invite' && !$invited) throw new NcError('Diesem Club tritt man nur mit Einladung bei.');
    $rulesAt = ad_join_check($who, $c, $GLOBALS['cl_join_in'] ?? []);   // 7.3.2
    $q = json_decode($c['questions'], true) ?: [];
    $ans = [];
    if ($c['join_mode'] === 'apply' && !$invited) foreach ($q as $i => $qq) { $a = nc_cut((string)($answers[$i] ?? ''), 500); if ($a === '') throw new NcError('Bitte alle Einlass-Fragen beantworten.'); $ans[] = ['q' => $qq, 'a' => $a]; }
    $status = $c['join_mode'] === 'open' || $invited ? 'active' : 'pending';
    cl_db()->prepare('INSERT OR REPLACE INTO club_members (club_id, vrc_id, role, status, answers, joined) VALUES (?, ?, \'member\', ?, ?, ?)')->execute([$c['id'], $who['vrc_id'], $status, json_encode($ans, JSON_UNESCAPED_UNICODE), time()]);
    if ($rulesAt) cl_db()->prepare('UPDATE club_members SET rules_at = ? WHERE club_id = ? AND vrc_id = ?')->execute([$rulesAt, $c['id'], $who['vrc_id']]);
    return ['msg' => $status === 'active' ? '🎉 Willkommen im Club!' : '📨 Bewerbung gesendet – die Club-Leitung meldet sich.', 'status' => $status];
}
function cl_leave(array $who, string $key): array {
    $c = cl_get($key);
    if ($c['owner'] === $who['vrc_id']) throw new NcError('Als Besitzer:in erst die Leitung übergeben.');
    cl_db()->prepare("DELETE FROM club_members WHERE club_id = ? AND vrc_id = ? AND status <> 'banned'")->execute([$c['id'], $who['vrc_id']]);
    cl_db()->prepare('DELETE FROM club_rsvp WHERE vrc_id = ? AND event_id IN (SELECT id FROM club_events WHERE club_id = ? AND starts > ?)')->execute([$who['vrc_id'], $c['id'], time()]);
    return ['msg' => 'Club verlassen.'];
}
/** Leitung: Bewerbung annehmen/ablehnen, Rolle setzen, entfernen, ausschließen, Leitung übergeben, einladen */
function cl_manage(array $who, string $key, string $do, string $target): array {
    $c = cl_get($key); cl_need_staff($c, $who);
    if (!nx_uid_ok($target)) throw new NcError('Unbekanntes Konto.');
    $db = cl_db(); $m = cl_member((int)$c['id'], $target); $isOwner = $c['owner'] === $who['vrc_id'] || !empty($who['_mod']);
    if ($target === $c['owner'] && $do !== 'invite') throw new NcError('Die Besitzer:in kann man nicht ändern.');
    if ($m && $m['role'] === 'mod' && !$isOwner && in_array($do, ['kick', 'ban', 'demote'], true)) throw new NcError('Nur die Besitzer:in kann Moderator:innen ändern.');
    switch ($do) {
        case 'accept': if (!$m || $m['status'] !== 'pending') throw new NcError('Keine offene Bewerbung.'); $db->prepare("UPDATE club_members SET status = 'active', joined = ? WHERE club_id = ? AND vrc_id = ?")->execute([time(), $c['id'], $target]); return ['msg' => 'Aufgenommen.'];
        case 'decline': $db->prepare("DELETE FROM club_members WHERE club_id = ? AND vrc_id = ? AND status = 'pending'")->execute([$c['id'], $target]); return ['msg' => 'Bewerbung abgelehnt.'];
        case 'invite': if ($m && $m['status'] === 'active') return ['msg' => 'Ist schon Mitglied.']; if ($m && $m['status'] === 'banned') throw new NcError('Ausgeschlossen.'); $db->prepare("INSERT OR REPLACE INTO club_members (club_id, vrc_id, role, status, joined) VALUES (?, ?, 'member', 'active', ?)")->execute([$c['id'], $target, time()]); return ['msg' => 'Eingeladen und aufgenommen.'];
        case 'promote': if (!$isOwner) throw new NcError('Nur die Besitzer:in vergibt Rollen.'); $db->prepare("UPDATE club_members SET role = 'mod' WHERE club_id = ? AND vrc_id = ? AND status = 'active'")->execute([$c['id'], $target]); return ['msg' => 'Jetzt Moderator:in.'];
        case 'demote': $db->prepare("UPDATE club_members SET role = 'member' WHERE club_id = ? AND vrc_id = ?")->execute([$c['id'], $target]); return ['msg' => 'Jetzt Mitglied.'];
        case 'kick': $db->prepare('DELETE FROM club_members WHERE club_id = ? AND vrc_id = ?')->execute([$c['id'], $target]); return ['msg' => 'Entfernt.'];
        case 'ban': return cl2_ban_set($who, $c, $target, 0, '');   // 7.2: Sperren mit Ablauf über cl2_action('ban')
        case 'transfer': if ($c['owner'] !== $who['vrc_id']) throw new NcError('Nur die Besitzer:in.'); if (!$m || $m['status'] !== 'active') throw new NcError('Nur an Mitglieder.');
            $db->prepare('UPDATE clubs SET owner = ? WHERE id = ?')->execute([$target, $c['id']]); $db->prepare("UPDATE club_members SET role = 'owner' WHERE club_id = ? AND vrc_id = ?")->execute([$c['id'], $target]);
            $db->prepare("UPDATE club_members SET role = 'mod' WHERE club_id = ? AND vrc_id = ?")->execute([$c['id'], $who['vrc_id']]); return ['msg' => 'Leitung übergeben.'];
    }
    throw new NcError('Unbekannte Aktion.');
}
function cl_post(array $who, string $key, string $text): array {
    cl_need_ok($who); $c = cl_get($key); cl_need_member($c, $who);
    $t = nc_cut($text, 2000); if ($t === '') throw new NcError('Leerer Beitrag.');
    if (nw_rate_over('clp|' . $who['vrc_id'], 20)) throw new NcError('Zu viele Beiträge – kurz warten.');
    $held = !cl_is_staff($c, $who) && cl2_filtered($c, $t);
    cl_db()->prepare('INSERT INTO club_posts (club_id, author, text, created, status) VALUES (?, ?, ?, ?, ?)')->execute([$c['id'], $who['vrc_id'], $t, time(), $held ? 'pending' : 'ok']);
    return ['msg' => $held ? '⏳ Dein Beitrag wird von der Club-Leitung geprüft (Wortfilter).' : 'Gepostet.'];
}
function cl_post_mod(array $who, string $key, int $id, string $do): array {
    $c = cl_get($key); $st = cl_db()->prepare('SELECT * FROM club_posts WHERE id = ? AND club_id = ?'); $st->execute([$id, $c['id']]); $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) throw new NcError('Beitrag nicht gefunden.');
    if ($do === 'delete') { if ($p['author'] !== $who['vrc_id']) cl_need_staff($c, $who); cl_db()->prepare('DELETE FROM club_posts WHERE id = ?')->execute([$id]); return ['msg' => 'Gelöscht.']; }
    if ($do === 'pin') { cl_need_staff($c, $who); cl_db()->prepare('UPDATE club_posts SET pinned = 1 - pinned WHERE id = ?')->execute([$id]); return ['msg' => 'Angeheftet / gelöst.']; }
    throw new NcError('Unbekannt.');
}
function cl_gallery_add(array $who, string $key, string $url, string $caption): array {
    cl_need_ok($who); $c = cl_get($key); cl_need_member($c, $who);
    if (!preg_match('#^https://[^\s<>"\']{8,400}\.(png|jpe?g|webp|gif)(\?[^\s<>"\']*)?$#i', $url)) throw new NcError('Nur https-Bildadressen (png, jpg, webp, gif).');
    cl_db()->prepare('INSERT INTO club_gallery (club_id, url, caption, author, created) VALUES (?, ?, ?, ?, ?)')->execute([$c['id'], $url, nc_cut($caption, 140), $who['vrc_id'], time()]);
    return ['msg' => '🖼 Bild hinzugefügt.'];
}
function cl_gallery_del(array $who, string $key, int $id): array {
    $c = cl_get($key); $st = cl_db()->prepare('SELECT author FROM club_gallery WHERE id = ? AND club_id = ?'); $st->execute([$id, $c['id']]); $a = $st->fetchColumn();
    if ($a === false) throw new NcError('Nicht gefunden.');
    if ($a !== $who['vrc_id']) cl_need_staff($c, $who);
    cl_db()->prepare('DELETE FROM club_gallery WHERE id = ?')->execute([$id]);
    return ['msg' => 'Entfernt.'];
}

// ---------- Events ----------
function cl_event_save(array $who, string $key, array $in): array {
    cl_need_ok($who); $c = cl_get($key); cl_need_staff($c, $who);
    $title = nc_cut((string)($in['title'] ?? ''), 80); if (mb_strlen($title) < 3) throw new NcError('Titel: mindestens 3 Zeichen.');
    $s = is_numeric($in['starts'] ?? null) ? (int)$in['starts'] : (strtotime((string)($in['starts'] ?? '')) ?: 0);
    $e = is_numeric($in['ends'] ?? null) ? (int)$in['ends'] : (strtotime((string)($in['ends'] ?? '')) ?: 0);
    if ($s < time() - 3600) throw new NcError('Beginn liegt in der Vergangenheit.');
    if ($e <= $s) $e = $s + 2 * 3600;
    if ($e - $s > 3 * 86400) throw new NcError('Höchstens 3 Tage lang.');
    $d = [$title, nc_cut((string)($in['descr'] ?? ''), 2000), $s, $e, nc_cut((string)($in['place'] ?? ''), 160), max(0, min(500, (int)($in['capacity'] ?? 0))), !empty($in['members_only']) ? 1 : 0];
    $id = (int)($in['id'] ?? 0); $db = cl_db();
    if ($id) { $chk = $db->prepare('SELECT 1 FROM club_events WHERE id = ? AND club_id = ?'); $chk->execute([$id, $c['id']]); if (!$chk->fetch()) throw new NcError('Event nicht gefunden.');
        $db->prepare('UPDATE club_events SET title=?, descr=?, starts=?, ends=?, place=?, capacity=?, members_only=? WHERE id = ?')->execute(array_merge($d, [$id])); cl_promote($id); }
    else { $db->prepare('INSERT INTO club_events (title, descr, starts, ends, place, capacity, members_only, club_id, created_by, created) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute(array_merge($d, [$c['id'], $who['vrc_id'], time()])); $id = (int)$db->lastInsertId(); }
    return ['msg' => '📅 Event gespeichert.', 'id' => $id];
}
function cl_event_cancel(array $who, int $id): array {
    $ev = cl_event_row($id); $c = cl_get((string)$ev['club_id']); cl_need_staff($c, $who);
    cl_db()->prepare("UPDATE club_events SET status = 'cancelled' WHERE id = ?")->execute([$id]);
    return ['msg' => 'Event abgesagt.'];
}
function cl_event_row(int $id): array { $st = cl_db()->prepare('SELECT * FROM club_events WHERE id = ?'); $st->execute([$id]); $e = $st->fetch(PDO::FETCH_ASSOC); if (!$e) throw new NcError('Event nicht gefunden.'); return $e; }
function cl_counts(int $id): array {
    $st = cl_db()->prepare('SELECT status, COUNT(*) FROM club_rsvp WHERE event_id = ? GROUP BY status'); $st->execute([$id]);
    return array_merge(['going' => 0, 'wait' => 0, 'maybe' => 0], array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR)));
}
/** freie Plätze an die Warteliste vergeben (wer zuerst kam) */
function cl_promote(int $id): int {
    $ev = cl_event_row($id); if ((int)$ev['capacity'] <= 0) { cl_db()->prepare("UPDATE club_rsvp SET status = 'going' WHERE event_id = ? AND status = 'wait'")->execute([$id]); return 0; }
    $free = (int)$ev['capacity'] - cl_counts($id)['going']; $n = 0;
    if ($free > 0) { $st = cl_db()->prepare("SELECT vrc_id FROM club_rsvp WHERE event_id = ? AND status = 'wait' ORDER BY created LIMIT " . $free); $st->execute([$id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) { cl_db()->prepare("UPDATE club_rsvp SET status = 'going' WHERE event_id = ? AND vrc_id = ?")->execute([$id, $v]); $n++; } }
    return $n;
}
function cl_rsvp(array $who, int $id, string $want): array {
    cl_need_ok($who); $ev = cl_event_row($id); $c = cl_get((string)$ev['club_id']);
    if ($ev['status'] !== 'active') throw new NcError('Dieses Event ist abgesagt.');
    if ((int)$ev['ends'] < time()) throw new NcError('Das Event ist vorbei.');
    if ($ev['members_only']) cl_need_member($c, $who);
    $db = cl_db();
    if ($want === 'no') { $db->prepare('DELETE FROM club_rsvp WHERE event_id = ? AND vrc_id = ?')->execute([$id, $who['vrc_id']]); $p = cl_promote($id); return ['msg' => 'Abgemeldet.' . ($p ? ' (Warteliste rückt nach)' : ''), 'event' => cl_event_pub($ev, $who)]; }
    if (!in_array($want, ['going', 'maybe'], true)) throw new NcError('Unbekannt.');
    $cur = $db->prepare('SELECT status FROM club_rsvp WHERE event_id = ? AND vrc_id = ?'); $cur->execute([$id, $who['vrc_id']]); $cur = (string)$cur->fetchColumn();
    $status = $want;
    if ($want === 'going' && $cur !== 'going' && (int)$ev['capacity'] > 0 && cl_counts($id)['going'] >= (int)$ev['capacity']) $status = 'wait';
    $db->prepare('INSERT OR REPLACE INTO club_rsvp (event_id, vrc_id, status, created) VALUES (?, ?, ?, ?)')->execute([$id, $who['vrc_id'], $status, $cur !== '' && $cur === $status ? time() : time()]);
    return ['msg' => $status === 'wait' ? '⏳ Ausgebucht – du stehst auf der Warteliste.' : ($status === 'going' ? '✅ Angemeldet.' : '🤔 Vielleicht.'), 'status' => $status, 'event' => cl_event_pub(cl_event_row($id), $who)];
}
function cl_event_pub(array $e, ?array $who, ?array $club = null): array {
    $n = cl_counts((int)$e['id']); $mine = '';
    if ($who) { $st = cl_db()->prepare('SELECT status FROM club_rsvp WHERE event_id = ? AND vrc_id = ?'); $st->execute([$e['id'], $who['vrc_id']]); $mine = (string)$st->fetchColumn(); }
    $o = ['id' => (int)$e['id'], 'club_id' => (int)$e['club_id'], 'title' => $e['title'], 'descr' => $e['descr'], 'starts' => (int)$e['starts'], 'ends' => (int)$e['ends'], 'place' => $e['place'],
        'capacity' => (int)$e['capacity'], 'members_only' => (bool)$e['members_only'], 'status' => $e['status'], 'going' => $n['going'], 'wait' => $n['wait'], 'maybe' => $n['maybe'], 'mine' => $mine];
    if ($club) $o += ['club' => ['slug' => $club['slug'], 'name' => $club['name'], 'lang' => $club['lang'], 'topic' => $club['topic'], 'color' => $club['color']]];
    return $o;
}
function cl_events_of(?array $who, array $c, bool $past): array {
    $st = cl_db()->prepare('SELECT * FROM club_events WHERE club_id = ? AND ' . ($past ? 'ends < ? ORDER BY starts DESC LIMIT 50' : "ends >= ? AND status <> 'cancelled' ORDER BY starts LIMIT 50")); $st->execute([$c['id'], time()]);
    return array_map(fn($e) => cl_event_pub($e, $who), $st->fetchAll(PDO::FETCH_ASSOC));
}
/** öffentlicher Kalender: kommende Events aller gelisteten Clubs (ohne „nur Mitglieder“) mit Filter */
function cl_calendar(?array $who, array $f): array {
    $sql = "SELECT e.*, c.slug, c.name AS club_name, c.lang, c.topic, c.color FROM club_events e JOIN clubs c ON c.id = e.club_id WHERE c.status = 'active' AND c.listed = 1 AND e.status = 'active' AND e.ends >= ? AND e.starts <= ?" . ($who ? ad_sql('c') : ' AND c.adult = 0');
    $args = [time(), time() + max(1, min(90, (int)($f['days'] ?? 30))) * 86400];
    if (!$who) $sql .= ' AND e.members_only = 0'; else { $sql .= " AND (e.members_only = 0 OR e.club_id IN (SELECT club_id FROM club_members WHERE vrc_id = ? AND status = 'active'))"; $args[] = $who['vrc_id']; }
    if (isset(CL_LANGS[$f['lang'] ?? ''])) { $sql .= ' AND c.lang = ?'; $args[] = $f['lang']; }
    if (isset(CL_TOPICS[$f['topic'] ?? ''])) { $sql .= ' AND c.topic = ?'; $args[] = $f['topic']; }
    $st = cl_db()->prepare($sql . ' ORDER BY e.starts LIMIT 200'); $st->execute($args);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (($f['tod'] ?? '') !== '') { $h = (int)date('G', (int)$r['starts']); $tod = $h < 12 ? 'morning' : ($h < 18 ? 'afternoon' : 'evening'); if ($tod !== $f['tod']) continue; }
        $out[] = cl_event_pub($r, $who, ['slug' => $r['slug'], 'name' => $r['club_name'], 'lang' => $r['lang'], 'topic' => $r['topic'], 'color' => $r['color']]);
    }
    return ['items' => $out, 'topics' => CL_TOPICS, 'langs' => CL_LANGS];
}
/** iCal (RFC 5545) aller kommenden Events eines gelisteten Clubs – ohne „nur Mitglieder“-Events */
function cl_ics(string $slug): string {
    $c = cl_get($slug);
    if (!$c['listed']) throw new NcError('Club nicht gefunden.');
    $esc = fn(string $s) => str_replace(["\\", ";", ",", "\n"], ["\\\\", "\\;", "\\,", "\\n"], $s);
    $fold = function (string $l): string { $o = ''; while (strlen($l) > 74) { $cut = 74; while ($cut > 0 && (ord($l[$cut]) & 0xC0) === 0x80) $cut--; $o .= substr($l, 0, $cut) . "\r\n "; $l = substr($l, $cut); } return $o . $l; };
    $st = cl_db()->prepare("SELECT * FROM club_events WHERE club_id = ? AND status = 'active' AND members_only = 0 AND ends >= ? ORDER BY starts LIMIT 200"); $st->execute([$c['id'], time() - 7 * 86400]);
    $L = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//GhostNode//Clubs//DE', 'CALSCALE:GREGORIAN', 'X-WR-CALNAME:' . $esc($c['name'])];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $L[] = 'BEGIN:VEVENT'; $L[] = 'UID:club-event-' . $e['id'] . '@ghostnode'; $L[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        $L[] = 'DTSTART:' . gmdate('Ymd\THis\Z', (int)$e['starts']); $L[] = 'DTEND:' . gmdate('Ymd\THis\Z', (int)$e['ends']);
        $L[] = 'SUMMARY:' . $esc($e['title']); if ($e['place'] !== '') $L[] = 'LOCATION:' . $esc($e['place']); if ($e['descr'] !== '') $L[] = 'DESCRIPTION:' . $esc($e['descr']);
        $L[] = 'END:VEVENT';
    }
    $L[] = 'END:VCALENDAR';
    return implode("\r\n", array_map($fold, $L)) . "\r\n";
}

/** gemeinsame Aktionen für App (nexusclubs.php) und Web (nexus.php nw_club_*) */
function cl_action(array $who, string $a, array $in): array {
    $GLOBALS['cl_adult_ok'] = !empty($who['_nsfw']);   // 7.3.2: 18+-Clubs nur für freigegebene Konten
    $GLOBALS['cl_join_in'] = $in;
    $k = (string)($in['club'] ?? '');
    switch ($a) {
        case 'list': return cl_list($who, $in);
        case 'view': return cl_view($who, $k);
        case 'create': return cl_create($who, $in);
        case 'update': return cl_update($who, $k, $in);
        case 'join': return cl_join($who, $k, (array)($in['answers'] ?? []), (string)($in['code'] ?? ''));
        case 'leave': return cl_leave($who, $k);
        case 'manage': return cl_manage($who, $k, (string)($in['do'] ?? ''), (string)($in['target'] ?? ''));
        case 'post': return cl_post($who, $k, (string)($in['text'] ?? ''));
        case 'post_mod': return cl_post_mod($who, $k, (int)($in['id'] ?? 0), (string)($in['do'] ?? ''));
        case 'gallery_add': return cl_gallery_add($who, $k, (string)($in['url'] ?? ''), (string)($in['caption'] ?? ''));
        case 'gallery_del': return cl_gallery_del($who, $k, (int)($in['id'] ?? 0));
        case 'event_save': return cl_event_save($who, $k, $in);
        case 'event_cancel': return cl_event_cancel($who, (int)($in['id'] ?? 0));
        case 'rsvp': return cl_rsvp($who, (int)($in['id'] ?? 0), (string)($in['status'] ?? ''));
        case 'calendar': return cl_calendar($who, $in);
    }
    return cl2_action($who, $a, $in);   // 7.2
}
