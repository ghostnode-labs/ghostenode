<?php
/**
 * Nexus Community – Profile, Feed (SFW) und getrennter NSFW-Feed, Antworten, Reaktionen, Bilder, Melden, Moderation.
 *
 * Zugriff (alles serverseitig, jede Anfrage):
 *  - Konto-Token (nexusacct) ist Pflicht; gesperrte Konten kommen nicht rein, stummgeschaltete dürfen nicht posten.
 *  - NSFW (Beiträge, Antworten, Bilder, 18+-Profilteil) nur mit gültigem „Verified 18+“ UND eingeschaltetem NSFW (nx_nsfw_allowed).
 *    NSFW erscheint ausschließlich im eigenen NSFW-Feed (Entscheidung der Besitzerin) – nie in der normalen Timeline.
 *  - Moderatoren: NXK1-Schlüssel mit Recht „mod“ (oder Besitzerin), Schlüssel-ID = Konto-ID.
 *  - NSFW-Bilder werden erst nach Freigabe durch einen Moderator sichtbar (Vorab-Prüfung).
 * Tabellen im Modul „vrchat“ (Migration 11).
 */
require_once __DIR__ . '/nexusacct.php';

const NC_MAX_TEXT = 2000;
const NC_MAX_IMG = 5 * 1048576;
const NC_REACTIONS = ['❤️', '😂', '😮', '😢', '🔥', '👍', '🦊', '🐾'];
const NC_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

class NcError extends RuntimeException {}

function nc_db(): PDO { return db_get('vrchat'); }
function nc_dir(): string {
    $d = DATA_DIR . '/community';
    if (!is_dir($d)) { @mkdir($d, 0700, true); @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n"); }
    return $d;
}

// ---------- Wer fragt? ----------
/** Konto + optional Moderator-Schlüssel (NXK1 „mod“, gleiche ID) */
function nc_who(array $in): array {
    $a = nx_auth((string)($in['token'] ?? ''));
    $mod = false;
    if (!empty($in['key'])) { try { $k = nw_auth((string)$in['key']); $mod = $k['admin'] && in_array(strtolower((string)$a['vrc_id']), $k['uids'] ?? [$k['uid']], true); /* 3.4: auch Zweitkonten eines Multiaccount-Schlüssels */ } catch (Throwable $e) { $mod = false; } }
    $a['_mod'] = $mod;
    $a['_nsfw'] = nx_nsfw_allowed($a);
    return $a;
}
function nc_sanction(string $id): array {
    $st = nc_db()->prepare('SELECT * FROM comm_sanctions WHERE vrc_id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: ['vrc_id' => $id, 'mute_until' => 0, 'banned' => 0, 'note' => ''];
}
function nc_can_post(array $who): void {
    $s = nc_sanction($who['vrc_id']);
    if (!empty($s['banned'])) throw new NcError('Du bist in der Community gesperrt.');
    if ((int)$s['mute_until'] > time()) throw new NcError('Du bist stummgeschaltet bis ' . date('d.m.Y H:i', (int)$s['mute_until']) . '.');
}
function nc_require_mod(array $who): void { if (empty($who['_mod'])) throw new NcError('Nur für Moderatoren.'); }
function nc_need_nsfw(array $who): void { if (empty($who['_nsfw'])) throw new NcError('NSFW ist für dieses Konto nicht freigegeben (Verified 18+ und NSFW-Schalter nötig).'); }
function nc_cut(string $s, int $n): string { return mb_substr(trim(str_replace("\r", '', $s)), 0, $n); }

// ---------- Profile ----------
/** Steckbrief: nur feste Werte (Liste in der App), alles andere wird verworfen */
const NC_FACT_SETS = [
    'orientation' => ['', 'ace', 'hetero', 'gay', 'bi', 'lesbian', 'pan', 'questioning', 'other'],
    'relationship' => ['', 'single', 'taken', 'open', 'complicated', 'married'],
    'looking' => ['', 'yes', 'no'],
];
const NC_GENDERS = ['female', 'male', 'trans_female', 'trans_male', 'nonbinary'];
const NC_NSFW_SETS = [
    'position' => ['', 'top', 'vers_top', 'versatile', 'vers_bottom', 'bottom', 'side'],
    'behavior' => ['', 'dominant', 'submissive', 'switch', 'versatile'],
    'open_contact' => ['', 'yes', 'no', 'ask'],
    'safer' => ['', 'always', 'sometimes', 'never', 'talk'],
    'visibility' => ['', 'connections', 'hidden'],        // Sichtbarkeit: '' = alle Verified 18+ mit NSFW an, 'connections' = nur angenommene Anfragen (6.0), 'hidden' = nur ich
];
function nc_facts(array $in): array {
    $out = [];
    foreach (NC_FACT_SETS as $k => $set) { $v = (string)($in[$k] ?? ''); if ($v !== '' && in_array($v, $set, true)) $out[$k] = $v; }
    $g = array_values(array_intersect(NC_GENDERS, array_map('strval', (array)($in['gender'] ?? []))));
    if ($g) $out['gender'] = $g;
    $o = nc_cut((string)($in['gender_other'] ?? ''), 40);
    if ($o !== '') $out['gender_other'] = $o;
    return $out;
}
function nc_nsfw_facts(array $in): array {
    $out = [];
    foreach (NC_NSFW_SETS as $k => $set) { $v = (string)($in[$k] ?? ''); if ($v !== '' && in_array($v, $set, true)) $out[$k] = $v; }
    if (!empty($in['can_host'])) $out['can_host'] = true;
    $kinks = [];
    foreach ((array)($in['kinks'] ?? []) as $k => $v) {
        if (count($kinks) >= 200 || !preg_match('/^[a-z0-9_]{2,32}$/', (string)$k) || !is_array($v)) continue;
        $r = isset($v[0]) && is_numeric($v[0]) ? max(-1, min(2, (int)$v[0])) : null;
        $g = isset($v[1]) && is_numeric($v[1]) ? max(-1, min(2, (int)$v[1])) : null;
        if ($r !== null || $g !== null) $kinks[(string)$k] = [$r, $g];
    }
    if ($kinks) $out['kinks'] = $kinks;
    // 5.1: NSFW-Avatare und -Welten im 18+-Profil (nur IDs und Namen, Bilder holt die App selbst bei VRChat)
    foreach (['avatars' => 'avtr', 'worlds' => 'wrld'] as $k => $pre) {
        $l = [];
        foreach ((array)($in[$k] ?? []) as $x) {
            if (count($l) >= 12 || !is_array($x)) continue;
            $id = strtolower((string)($x['id'] ?? ''));
            if (!preg_match('/^' . $pre . '_[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) || isset($l[$id])) continue;
            $l[$id] = ['id' => $id, 'name' => nc_cut((string)($x['name'] ?? ''), 60), 'note' => nc_cut((string)($x['note'] ?? ''), 120)];
        }
        if ($l) $out[$k] = array_values($l);
    }
    return $out;
}
/** 5.1: nur Avatare/Welten des 18+-Profils setzen (der Rest bleibt, wie er ist) */
function nc_profile_items(array $who, array $in): array {
    if (!$who['_nsfw']) throw new NcError('NSFW ist nicht freigegeben.');
    $db = nc_db();
    $ex = $db->prepare('SELECT nsfw_facts FROM comm_profiles WHERE vrc_id = ?'); $ex->execute([$who['vrc_id']]);
    $row = $ex->fetch(PDO::FETCH_ASSOC);
    $nf = $row ? (json_decode((string)$row['nsfw_facts'], true) ?: []) : [];
    foreach (['avatars', 'worlds'] as $k) if (array_key_exists($k, $in)) $nf[$k] = (array)$in[$k];
    $nf = nc_nsfw_facts($nf);
    $j = json_encode($nf ?: (object)[]);
    if ($row) $db->prepare('UPDATE comm_profiles SET nsfw_facts = ?, updated = ? WHERE vrc_id = ?')->execute([$j, time(), $who['vrc_id']]);
    else $db->prepare("INSERT INTO comm_profiles (bio, pronouns, languages, interests, links, theme, avatar, banner, nsfw_bio, updated, facts, nsfw_facts, vrc_id) VALUES ('', '', '', '', '[]', '', '', '', '', ?, '{}', ?, ?)")->execute([time(), $j, $who['vrc_id']]);
    return ['avatars' => $nf['avatars'] ?? [], 'worlds' => $nf['worlds'] ?? []];
}
function nc_profile(array $who, string $id): array {
    $id = strtolower($id);
    if (!nx_uid_ok($id)) throw new NcError('Ungültige VRChat-User-ID.');
    $acc = nx_account($id);
    if (!$acc) throw new NcError('Diese Person hat (noch) kein Nexus-Konto.');
    $st = nc_db()->prepare('SELECT * FROM comm_profiles WHERE vrc_id = ?');
    $st->execute([$id]);
    $p = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $own = $id === $who['vrc_id'];
    $out = ['vrc_id' => $id, 'name' => $acc['display_name'], 'bio' => $p['bio'] ?? '', 'pronouns' => $p['pronouns'] ?? '', 'languages' => $p['languages'] ?? '',
        'interests' => $p['interests'] ?? '', 'links' => json_decode($p['links'] ?? '[]', true) ?: [], 'theme' => $p['theme'] ?? '', 'avatar' => $p['avatar'] ?? '', 'banner' => $p['banner'] ?? '',
        'verified18' => (int)$acc['age_until'] > time(), 'joined' => (int)$acc['created'], 'own' => $own,
        'facts' => json_decode($p['facts'] ?? '{}', true) ?: (object)[]];
    // 18+-Profilteil nur für freigegebene Betrachter (und nur, wenn die Person selbst NSFW an hat)
    $nf = json_decode($p['nsfw_facts'] ?? '{}', true) ?: [];
    $vis = (string)($nf['visibility'] ?? '');
    if ($own || ($who['_nsfw'] && !empty($acc['nsfw_on']) && (int)$acc['age_until'] > time() && $vis !== 'hidden' && ($vis !== 'connections' || nc_connected($who['vrc_id'], $id)))) {
        $out['nsfw_bio'] = $p['nsfw_bio'] ?? '';
        $out['nsfw_facts'] = $nf ?: (object)[];
    }
    $c = nc_db()->prepare("SELECT COUNT(*) FROM comm_posts WHERE author = ? AND status = 'visible' AND parent = 0 AND rating = 'sfw'");
    $c->execute([$id]);
    $out['posts'] = (int)$c->fetchColumn();
    if ($who['_nsfw'] && !$own) $out['link18'] = nc_link_state($who['vrc_id'], $id);   // 6.0: Stand der 18+-Anfrage zwischen uns
    $f = nc_db()->prepare('SELECT (SELECT COUNT(*) FROM comm_follows WHERE target = ?), (SELECT COUNT(*) FROM comm_follows WHERE vrc_id = ?), (SELECT COUNT(*) FROM comm_follows WHERE vrc_id = ? AND target = ?)');
    $f->execute([$id, $id, $who['vrc_id'], $id]);
    [$out['followers'], $out['following'], $out['i_follow']] = array_map('intval', $f->fetch(PDO::FETCH_NUM));
    $out['i_follow'] = (bool)$out['i_follow'];
    return $out;
}
function nc_profile_save(array $who, array $in): array {
    $links = array_values(array_filter(array_map(fn($l) => nc_cut((string)$l, 200), (array)($in['links'] ?? [])), fn($l) => preg_match('#^https://[\w.\-]+(/\S*)?$#', $l)));
    foreach (['avatar', 'banner'] as $k) if (!empty($in[$k]) && !nc_media_owned($who, (string)$in[$k], 'sfw')) throw new NcError('Profilbild/Banner muss ein eigenes SFW-Bild sein.');
    $vals = [nc_cut((string)($in['bio'] ?? ''), 1000), nc_cut((string)($in['pronouns'] ?? ''), 40), nc_cut((string)($in['languages'] ?? ''), 100), nc_cut((string)($in['interests'] ?? ''), 300),
        json_encode(array_slice($links, 0, 6)), preg_replace('/[^a-z0-9\-]/', '', (string)($in['theme'] ?? '')), (string)($in['avatar'] ?? ''), (string)($in['banner'] ?? ''),
        $who['_nsfw'] ? nc_cut((string)($in['nsfw_bio'] ?? ''), 1000) : null, time()];
    $db = nc_db();
    $ex = $db->prepare('SELECT nsfw_bio, facts, nsfw_facts FROM comm_profiles WHERE vrc_id = ?'); $ex->execute([$who['vrc_id']]);
    $old = $ex->fetch(PDO::FETCH_ASSOC);
    if ($vals[8] === null) $vals[8] = $old ? (string)$old['nsfw_bio'] : '';   // ohne Freigabe bleibt der 18+-Teil unverändert
    // Steckbrief: nur wenn mitgeschickt (ältere Apps kennen ihn nicht); 18+-Teil nur mit Freigabe
    $vals[] = array_key_exists('facts', $in) ? json_encode(nc_facts((array)$in['facts']) ?: (object)[]) : ($old ? (string)$old['facts'] : '{}');
    $vals[] = array_key_exists('nsfw_facts', $in) && $who['_nsfw'] ? json_encode(nc_nsfw_facts((array)$in['nsfw_facts']) ?: (object)[]) : ($old ? (string)$old['nsfw_facts'] : '{}');
    if ($old) $db->prepare('UPDATE comm_profiles SET bio = ?, pronouns = ?, languages = ?, interests = ?, links = ?, theme = ?, avatar = ?, banner = ?, nsfw_bio = ?, updated = ?, facts = ?, nsfw_facts = ? WHERE vrc_id = ?')->execute(array_merge($vals, [$who['vrc_id']]));
    else $db->prepare('INSERT INTO comm_profiles (bio, pronouns, languages, interests, links, theme, avatar, banner, nsfw_bio, updated, facts, nsfw_facts, vrc_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(array_merge($vals, [$who['vrc_id']]));
    return nc_profile($who, $who['vrc_id']);
}

// ---------- Welten (3.2): NSFW-Beschreibungen ----------
function nc_world_id(string $w): string { $w = strtolower(trim($w)); if (!preg_match('/^wrld_[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $w)) throw new NcError('Ungültige Welt-ID.'); return $w; }
/** Beschreibungen nur für Verified 18+ mit NSFW an – sonst nur der Hinweis, dass es welche gibt (keine Inhalte) */
function nc_world(array $who, string $world): array {
    $world = nc_world_id($world);
    $c = nc_db()->prepare('SELECT COUNT(*) FROM comm_world_notes WHERE world_id = ?'); $c->execute([$world]);
    $out = ['world' => $world, 'nsfw' => !empty($who['_nsfw']), 'count' => (int)$c->fetchColumn()];
    if (empty($who['_nsfw'])) return $out;
    $st = nc_db()->prepare('SELECT n.author, n.text, n.updated, a.display_name FROM comm_world_notes n LEFT JOIN nexus_accounts a ON a.vrc_id = n.author WHERE n.world_id = ? ORDER BY n.updated DESC LIMIT 50');
    $st->execute([$world]);
    $out['notes'] = array_map(fn($r) => ['author' => $r['author'], 'name' => (string)($r['display_name'] ?? ''), 'text' => $r['text'], 'updated' => (int)$r['updated'], 'own' => $r['author'] === $who['vrc_id']], $st->fetchAll(PDO::FETCH_ASSOC));
    return $out;
}
function nc_world_note(array $who, string $world, string $text): array {
    nc_need_nsfw($who);
    nc_can_post($who);
    $world = nc_world_id($world);
    $text = nc_cut($text, 2000);
    $db = nc_db();
    $db->prepare('DELETE FROM comm_world_notes WHERE world_id = ? AND author = ?')->execute([$world, $who['vrc_id']]);
    if ($text !== '') $db->prepare('INSERT INTO comm_world_notes (world_id, author, text, created, updated) VALUES (?, ?, ?, ?, ?)')->execute([$world, $who['vrc_id'], $text, time(), time()]);
    return nc_world($who, $world);
}

// ---------- Bilder ----------
function nc_upload(array $who, array $in): array {
    nc_can_post($who);
    $rating = ($in['rating'] ?? 'sfw') === 'nsfw' ? 'nsfw' : 'sfw';
    if ($rating === 'nsfw') nc_need_nsfw($who);
    $bin = base64_decode((string)($in['data'] ?? ''), true);
    if ($bin === false || $bin === '') throw new NcError('Kein Bild.');
    if (strlen($bin) > NC_MAX_IMG) throw new NcError('Bild zu groß (max. 5 MB).');
    $info = @getimagesizefromstring($bin);
    $mime = $info['mime'] ?? '';
    if (!isset(NC_MIMES[$mime])) throw new NcError('Nur JPG, PNG, WebP oder GIF.');
    $n = nc_db()->prepare('SELECT COUNT(*) FROM comm_media WHERE owner = ? AND created > ?');
    $n->execute([$who['vrc_id'], time() - 86400]);
    if ((int)$n->fetchColumn() >= 50 && !$who['_mod']) throw new NcError('Zu viele Bilder heute.');
    $id = bin2hex(random_bytes(12));
    file_put_contents(nc_dir() . '/' . $id . '.bin', $bin);
    // NSFW-Bilder: erst nach Moderator-Freigabe sichtbar
    $status = $rating === 'nsfw' && !$who['_mod'] ? 'pending' : 'ok';
    nc_db()->prepare('INSERT INTO comm_media (id, owner, mime, size, rating, status, created) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$id, $who['vrc_id'], $mime, strlen($bin), $rating, $status, time()]);
    return ['id' => $id, 'status' => $status, 'rating' => $rating];
}
function nc_media_row(string $id): ?array {
    if (!preg_match('/^[0-9a-f]{24}$/', $id)) return null;
    $st = nc_db()->prepare('SELECT * FROM comm_media WHERE id = ?'); $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function nc_media_owned(array $who, string $id, string $rating): bool { $m = nc_media_row($id); return $m && $m['owner'] === $who['vrc_id'] && ($rating === 'nsfw' || $m['rating'] === 'sfw'); }
/** Bild ausliefern – mit Zugriffsprüfung (NSFW nur freigegeben, „pending“ nur für Besitzer und Moderatoren) */
function nc_media_get(array $who, string $id): array {
    $m = nc_media_row($id);
    if (!$m || $m['status'] === 'rejected') throw new NcError('Bild nicht gefunden.');
    if ($m['rating'] === 'nsfw') nc_need_nsfw($who);
    if ($m['status'] === 'pending' && $m['owner'] !== $who['vrc_id'] && !$who['_mod']) throw new NcError('Bild wartet auf Freigabe.');
    $f = nc_dir() . '/' . $m['id'] . '.bin';
    if (!is_file($f)) throw new NcError('Bild nicht gefunden.');
    return [$m['mime'], (string)file_get_contents($f)];
}

// ---------- Beiträge ----------
function nc_row(array $p, array $who, array $names): array {
    $media = json_decode($p['media'] ?: '[]', true) ?: [];
    return ['id' => (int)$p['id'], 'author' => $p['author'], 'name' => $names[$p['author']] ?? '', 'rating' => $p['rating'], 'text' => $p['status'] === 'deleted' ? '' : $p['text'],
        'media' => $p['status'] === 'deleted' ? [] : $media, 'parent' => (int)$p['parent'], 'created' => (int)$p['created'], 'edited' => (int)$p['edited'], 'status' => $p['status'],
        'replies' => (int)($p['replies'] ?? 0), 'reactions' => $p['_reactions'] ?? [], 'mine' => $p['_mine'] ?? [], 'own' => $p['author'] === $who['vrc_id'], 'saved' => !empty($p['_saved']), 'tags' => $p['status'] === 'deleted' ? [] : nc_tags($p['text'])];
}
function nc_decorate(array $rows, array $who): array {
    if (!$rows) return [];
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $in = implode(',', $ids);
    $re = nc_db()->query("SELECT post_id, emoji, COUNT(*) AS n FROM comm_reactions WHERE post_id IN ($in) GROUP BY post_id, emoji")->fetchAll(PDO::FETCH_ASSOC);
    $mine = nc_db()->prepare("SELECT post_id, emoji FROM comm_reactions WHERE vrc_id = ? AND post_id IN ($in)"); $mine->execute([$who['vrc_id']]);
    $rc = nc_db()->query("SELECT parent, COUNT(*) AS n FROM comm_posts WHERE parent IN ($in) AND status = 'visible' GROUP BY parent")->fetchAll(PDO::FETCH_KEY_PAIR);
    $authors = array_values(array_unique(array_map(fn($r) => $r['author'], $rows)));
    $q = nc_db()->prepare('SELECT vrc_id, display_name FROM nexus_accounts WHERE vrc_id IN (' . implode(',', array_fill(0, count($authors), '?')) . ')'); $q->execute($authors);
    $names = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    $sv = nc_db()->prepare("SELECT post_id FROM comm_saves WHERE vrc_id = ? AND post_id IN ($in)"); $sv->execute([$who['vrc_id']]);
    $saved = array_flip(array_map('intval', $sv->fetchAll(PDO::FETCH_COLUMN)));
    $by = []; foreach ($re as $r) $by[$r['post_id']][$r['emoji']] = (int)$r['n'];
    $my = []; foreach ($mine->fetchAll(PDO::FETCH_ASSOC) as $r) $my[$r['post_id']][] = $r['emoji'];
    return array_map(function ($r) use ($by, $my, $rc, $who, $names, $saved) { $r['_reactions'] = $by[$r['id']] ?? []; $r['_mine'] = $my[$r['id']] ?? []; $r['replies'] = $rc[$r['id']] ?? 0; $r['_saved'] = isset($saved[(int)$r['id']]); return nc_row($r, $who, $names); }, $rows);
}
/**
 * Feed: rating = sfw (Timeline) oder nsfw (eigener Feed, nur freigegeben).
 * 5.3: q = Volltextsuche, tag = #Hashtag, mode = following (nur Leute, denen ich folge) | saved (meine Merkliste)
 */
function nc_feed(array $who, string $rating, int $before = 0, string $author = '', array $opt = []): array {
    if ($rating === 'nsfw') nc_need_nsfw($who); else $rating = 'sfw';
    $sql = "SELECT p.* FROM comm_posts p WHERE p.rating = ? AND p.parent = 0 AND (p.status = 'visible' OR (p.status = 'pending' AND p.author = ?))";
    $args = [$rating, $who['vrc_id']];
    if ($before > 0) { $sql .= ' AND p.id < ?'; $args[] = $before; }
    if ($author !== '' && nx_uid_ok($author)) { $sql .= ' AND p.author = ?'; $args[] = strtolower($author); }
    $q = nc_cut((string)($opt['q'] ?? ''), 80);
    if ($q !== '') { $sql .= " AND p.text LIKE ? ESCAPE '\\'"; $args[] = '%' . addcslashes($q, '%_\\') . '%'; }
    $tag = nc_tag((string)($opt['tag'] ?? ''));
    if ($tag !== '') { $sql .= " AND (' ' || lower(p.text) || ' ') LIKE ? ESCAPE '\\'"; $args[] = '%#' . addcslashes($tag, '%_\\') . '%'; }
    $mode = (string)($opt['mode'] ?? '');
    if ($mode === 'following') { $sql .= ' AND p.author IN (SELECT target FROM comm_follows WHERE vrc_id = ?)'; $args[] = $who['vrc_id']; }
    if ($mode === 'saved') { $sql .= ' AND p.id IN (SELECT post_id FROM comm_saves WHERE vrc_id = ?)'; $args[] = $who['vrc_id']; }
    $st = nc_db()->prepare($sql . ' ORDER BY p.id DESC LIMIT 30');
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($tag !== '') $rows = array_values(array_filter($rows, fn($r) => in_array($tag, nc_tags($r['text']), true)));   // genau dieser Hashtag, nicht nur der Anfang
    return nc_decorate($rows, $who);
}
/** Hashtag normalisieren (ohne #, klein, Buchstaben/Ziffern/_) */
function nc_tag(string $t): string { $t = mb_strtolower(ltrim(trim($t), '#')); return preg_match('/^[\p{L}\p{N}_]{2,32}$/u', $t) ? $t : ''; }
function nc_tags(string $text): array {
    preg_match_all('/(?:^|[^\p{L}\p{N}_&#])#([\p{L}\p{N}_]{2,32})/u', $text, $m);
    return array_values(array_unique(array_map('mb_strtolower', $m[1])));
}
function nc_post_row(int $id): ?array { $st = nc_db()->prepare('SELECT * FROM comm_posts WHERE id = ?'); $st->execute([$id]); return $st->fetch(PDO::FETCH_ASSOC) ?: null; }
function nc_visible(array $who, array $p): void {
    if ($p['rating'] === 'nsfw') nc_need_nsfw($who);
    if ($p['status'] !== 'visible' && $p['author'] !== $who['vrc_id'] && !$who['_mod']) throw new NcError('Beitrag nicht verfügbar.');
}
function nc_thread(array $who, int $id): array {
    $p = nc_post_row($id);
    if (!$p) throw new NcError('Beitrag nicht gefunden.');
    nc_visible($who, $p);
    $st = nc_db()->prepare("SELECT * FROM comm_posts WHERE parent = ? AND (status = 'visible' OR author = ?) ORDER BY id LIMIT 200");
    $st->execute([$id, $who['vrc_id']]);
    return ['post' => nc_decorate([$p], $who)[0], 'replies' => nc_decorate($st->fetchAll(PDO::FETCH_ASSOC), $who)];
}
function nc_create(array $who, array $in): array {
    nc_can_post($who);
    $text = nc_cut((string)($in['text'] ?? ''), NC_MAX_TEXT);
    $media = array_values(array_unique(array_filter(array_map('strval', (array)($in['media'] ?? [])))));
    if ($text === '' && !$media) throw new NcError('Leerer Beitrag.');
    if (count($media) > 4) throw new NcError('Höchstens 4 Bilder.');
    $parent = (int)($in['parent'] ?? 0);
    $rating = ($in['rating'] ?? 'sfw') === 'nsfw' ? 'nsfw' : 'sfw';
    if ($parent) { $pp = nc_post_row($parent); if (!$pp || $pp['parent']) throw new NcError('Antwort nicht möglich.'); nc_visible($who, $pp); $rating = $pp['rating']; }   // Antworten erben die Einstufung
    if ($rating === 'nsfw') nc_need_nsfw($who);
    $pending = false;
    foreach ($media as $m) {
        $row = nc_media_row($m);
        if (!$row || $row['owner'] !== $who['vrc_id']) throw new NcError('Unbekanntes Bild.');
        if ($row['rating'] === 'nsfw' && $rating !== 'nsfw') throw new NcError('NSFW-Bilder gehören in den NSFW-Feed.');
        if ($row['status'] === 'pending') $pending = true;
    }
    $n = nc_db()->prepare('SELECT COUNT(*) FROM comm_posts WHERE author = ? AND created > ?');
    $n->execute([$who['vrc_id'], time() - 3600]);
    if ((int)$n->fetchColumn() >= 60 && !$who['_mod']) throw new NcError('Zu viele Beiträge – kurz Pause.');
    nc_db()->prepare('INSERT INTO comm_posts (author, rating, text, media, parent, created, edited, status, mod_note) VALUES (?, ?, ?, ?, ?, ?, 0, ?, \'\')')
        ->execute([$who['vrc_id'], $rating, $text, json_encode($media), $parent, time(), $pending ? 'pending' : 'visible']);
    $id = (int)nc_db()->lastInsertId();
    return ['post' => nc_decorate([nc_post_row($id)], $who)[0], 'msg' => $pending ? 'Gepostet – das NSFW-Bild wird vor dem Erscheinen von einem Moderator geprüft.' : 'Gepostet.'];
}
function nc_edit(array $who, int $id, string $text): array {
    $p = nc_post_row($id);
    if (!$p || $p['author'] !== $who['vrc_id'] || $p['status'] === 'deleted') throw new NcError('Nur eigene Beiträge.');
    nc_can_post($who);
    nc_db()->prepare('UPDATE comm_posts SET text = ?, edited = ? WHERE id = ?')->execute([nc_cut($text, NC_MAX_TEXT), time(), $id]);
    return ['post' => nc_decorate([nc_post_row($id)], $who)[0]];
}
function nc_delete(array $who, int $id): array {
    $p = nc_post_row($id);
    if (!$p || ($p['author'] !== $who['vrc_id'] && !$who['_mod'])) throw new NcError('Nur eigene Beiträge.');
    nc_db()->prepare("UPDATE comm_posts SET status = 'deleted', text = '', media = '[]' WHERE id = ?")->execute([$id]);
    if ($p['author'] !== $who['vrc_id']) nw_log($who['vrc_id'], 'Mod: Beitrag gelöscht', $p['author'], '#' . $id);
    return ['ok' => true, 'msg' => 'Gelöscht.'];
}
function nc_react(array $who, int $id, string $emoji): array {
    if (!in_array($emoji, NC_REACTIONS, true)) throw new NcError('Unbekannte Reaktion.');
    $p = nc_post_row($id);
    if (!$p) throw new NcError('Beitrag nicht gefunden.');
    nc_visible($who, $p);
    $db = nc_db();
    $st = $db->prepare('DELETE FROM comm_reactions WHERE post_id = ? AND vrc_id = ? AND emoji = ?'); $st->execute([$id, $who['vrc_id'], $emoji]);
    if (!$st->rowCount()) $db->prepare('INSERT INTO comm_reactions (post_id, vrc_id, emoji, created) VALUES (?, ?, ?, ?)')->execute([$id, $who['vrc_id'], $emoji, time()]);
    return ['post' => nc_decorate([nc_post_row($id)], $who)[0]];
}
function nc_report(array $who, int $id, string $reason): array {
    $p = nc_post_row($id);
    if (!$p) throw new NcError('Beitrag nicht gefunden.');
    nc_visible($who, $p);
    $reason = nc_cut($reason, 300);
    if ($reason === '') throw new NcError('Bitte kurz den Grund angeben.');
    $ex = nc_db()->prepare("SELECT COUNT(*) FROM comm_reports WHERE post_id = ? AND reporter = ? AND status = 'open'"); $ex->execute([$id, $who['vrc_id']]);
    if ((int)$ex->fetchColumn()) return ['ok' => true, 'msg' => 'Schon gemeldet.'];
    nc_db()->prepare("INSERT INTO comm_reports (post_id, reporter, reason, created, status, resolver, result) VALUES (?, ?, ?, ?, 'open', '', '')")->execute([$id, $who['vrc_id'], $reason, time()]);
    return ['ok' => true, 'msg' => 'Gemeldet – danke! Ein Moderator sieht es sich an.'];
}

// ---------- Moderation ----------
function nc_mod_queue(array $who): array {
    nc_require_mod($who);
    $db = nc_db();
    $reports = $db->query("SELECT r.*, p.author, p.rating, p.text, p.status AS post_status, p.media FROM comm_reports r JOIN comm_posts p ON p.id = r.post_id WHERE r.status = 'open' ORDER BY r.id LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    $pending = $db->query("SELECT * FROM comm_posts WHERE status = 'pending' ORDER BY id LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    $media = $db->query("SELECT id, owner, mime, size, rating, created FROM comm_media WHERE status = 'pending' ORDER BY created LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    $sanctions = $db->query('SELECT * FROM comm_sanctions WHERE banned = 1 OR mute_until > ' . time())->fetchAll(PDO::FETCH_ASSOC);
    $log = $db->query("SELECT * FROM warn_log WHERE action LIKE 'Mod:%' ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    return ['reports' => $reports, 'pending' => nc_decorate($pending, $who), 'media' => $media, 'sanctions' => $sanctions, 'log' => $log];
}
function nc_mod(array $who, string $do, array $in): array {
    nc_require_mod($who);
    $db = nc_db();
    $id = (int)($in['id'] ?? 0);
    $note = nc_cut((string)($in['note'] ?? ''), 300);
    $p = $id ? nc_post_row($id) : null;
    switch ($do) {
        case 'hide': case 'unhide': case 'approve':
            if (!$p) throw new NcError('Beitrag nicht gefunden.');
            $db->prepare('UPDATE comm_posts SET status = ?, mod_note = ? WHERE id = ?')->execute([$do === 'hide' ? 'hidden' : 'visible', $note, $id]);
            if ($do === 'approve') foreach (json_decode($p['media'] ?: '[]', true) ?: [] as $m) $db->prepare("UPDATE comm_media SET status = 'ok' WHERE id = ?")->execute([$m]);
            nw_log($who['vrc_id'], 'Mod: Beitrag ' . ['hide' => 'ausgeblendet', 'unhide' => 'wieder sichtbar', 'approve' => 'freigegeben'][$do], $p['author'], '#' . $id . ($note ? ' – ' . $note : ''));
            break;
        case 'nsfw':   // falsch als SFW gepostet → in den NSFW-Feed (inkl. Antworten und Bilder)
            if (!$p) throw new NcError('Beitrag nicht gefunden.');
            $db->prepare("UPDATE comm_posts SET rating = 'nsfw' WHERE id = ? OR parent = ?")->execute([$id, $id]);
            foreach (json_decode($p['media'] ?: '[]', true) ?: [] as $m) $db->prepare("UPDATE comm_media SET rating = 'nsfw' WHERE id = ?")->execute([$m]);
            nw_log($who['vrc_id'], 'Mod: als NSFW umgestuft', $p['author'], '#' . $id);
            break;
        case 'delete': return nc_delete($who, $id);
        case 'media_ok': case 'media_reject':
            $m = nc_media_row((string)($in['media'] ?? ''));
            if (!$m) throw new NcError('Bild nicht gefunden.');
            $db->prepare('UPDATE comm_media SET status = ? WHERE id = ?')->execute([$do === 'media_ok' ? 'ok' : 'rejected', $m['id']]);
            if ($do === 'media_reject') @unlink(nc_dir() . '/' . $m['id'] . '.bin');
            // Beiträge, deren Bilder jetzt alle geprüft sind, erscheinen
            foreach ($db->query("SELECT id, media FROM comm_posts WHERE status = 'pending'")->fetchAll(PDO::FETCH_ASSOC) as $pp) {
                $ms = json_decode($pp['media'] ?: '[]', true) ?: [];
                if (!in_array($m['id'], $ms, true)) continue;
                $left = 0; foreach ($ms as $x) { $r = nc_media_row($x); if ($r && $r['status'] === 'pending') $left++; }
                if (!$left) $db->prepare("UPDATE comm_posts SET status = 'visible', media = ? WHERE id = ?")->execute([json_encode(array_values(array_filter($ms, function ($x) { $r = nc_media_row($x); return $r && $r['status'] === 'ok'; }))), $pp['id']]);
            }
            nw_log($who['vrc_id'], $do === 'media_ok' ? 'Mod: Bild freigegeben' : 'Mod: Bild abgelehnt', $m['owner'], $m['id']);
            break;
        case 'mute': case 'ban': case 'unban':
            $target = strtolower((string)($in['vrc_id'] ?? ($p['author'] ?? '')));
            if (!nx_uid_ok($target)) throw new NcError('Ungültige VRChat-User-ID.');
            if (nw_is_owner($target)) throw new NcError('Die Besitzerin kann nicht gesperrt werden.');
            if ($target === $who['vrc_id']) throw new NcError('Dich selbst kannst du nicht sperren.');
            $s = nc_sanction($target);
            $mute = $do === 'mute' ? time() + max(1, min(24 * 90, (int)($in['hours'] ?? 24))) * 3600 : ($do === 'unban' ? 0 : (int)$s['mute_until']);
            $ban = $do === 'ban' ? 1 : ($do === 'unban' ? 0 : (int)$s['banned']);
            $db->prepare('DELETE FROM comm_sanctions WHERE vrc_id = ?')->execute([$target]);
            $db->prepare('INSERT INTO comm_sanctions (vrc_id, mute_until, banned, note, by_id, at) VALUES (?, ?, ?, ?, ?, ?)')->execute([$target, $mute, $ban, $note, $who['vrc_id'], time()]);
            nw_log($who['vrc_id'], 'Mod: ' . ['mute' => 'stummgeschaltet', 'ban' => 'gesperrt', 'unban' => 'Sperre aufgehoben'][$do], $target, $note . ($do === 'mute' ? ' bis ' . date('d.m.Y H:i', $mute) : ''));
            break;
        case 'wnote_del':   // 3.2: NSFW-Weltbeschreibung entfernen
            $w = nc_world_id((string)($in['world'] ?? '')); $a = strtolower((string)($in['vrc_id'] ?? ''));
            $db->prepare('DELETE FROM comm_world_notes WHERE world_id = ? AND author = ?')->execute([$w, $a]);
            nw_log($who['vrc_id'], 'Mod: Weltbeschreibung entfernt', $a, $w . ($note ? ' – ' . $note : ''));
            break;
        case 'resolve':
            $db->prepare("UPDATE comm_reports SET status = 'done', resolver = ?, result = ? WHERE id = ?")->execute([$who['vrc_id'], $note ?: 'erledigt', (int)($in['report'] ?? 0)]);
            nw_log($who['vrc_id'], 'Mod: Meldung erledigt', '', '#' . (int)($in['report'] ?? 0));
            break;
        default: throw new NcError('Unbekannte Aktion.');
    }
    if (in_array($do, ['hide', 'delete', 'nsfw'], true) && $id) $db->prepare("UPDATE comm_reports SET status = 'done', resolver = ?, result = ? WHERE post_id = ? AND status = 'open'")->execute([$who['vrc_id'], $do, $id]);
    return ['ok' => true, 'msg' => 'Erledigt.'];
}

// ---------- 5.3: Merken, Folgen, Trends, Antworten an mich ----------
function nc_save(array $who, int $id, bool $on): array {
    $p = nc_post_row($id);
    if (!$p || $p['parent']) throw new NcError('Beitrag nicht gefunden.');
    nc_visible($who, $p);
    if ($on) {
        $n = nc_db()->prepare('SELECT COUNT(*) FROM comm_saves WHERE vrc_id = ?'); $n->execute([$who['vrc_id']]);
        if ((int)$n->fetchColumn() >= 500) throw new NcError('Merkliste ist voll (500).');
        nc_db()->prepare('INSERT OR IGNORE INTO comm_saves (vrc_id, post_id, created) VALUES (?, ?, ?)')->execute([$who['vrc_id'], $id, time()]);
    } else nc_db()->prepare('DELETE FROM comm_saves WHERE vrc_id = ? AND post_id = ?')->execute([$who['vrc_id'], $id]);
    return ['saved' => $on, 'msg' => $on ? '🔖 Gemerkt.' : 'Nicht mehr gemerkt.'];
}
function nc_follow(array $who, string $target, bool $on): array {
    $target = strtolower(trim($target));
    if (!nx_uid_ok($target) || $target === $who['vrc_id']) throw new NcError('Ungültige Person.');
    if ($on) {
        if (!nx_account($target)) throw new NcError('Diese Person hat (noch) kein Nexus-Konto.');
        $n = nc_db()->prepare('SELECT COUNT(*) FROM comm_follows WHERE vrc_id = ?'); $n->execute([$who['vrc_id']]);
        if ((int)$n->fetchColumn() >= 1000) throw new NcError('Du folgst schon 1000 Leuten.');
        nc_db()->prepare('INSERT OR IGNORE INTO comm_follows (vrc_id, target, created) VALUES (?, ?, ?)')->execute([$who['vrc_id'], $target, time()]);
    } else nc_db()->prepare('DELETE FROM comm_follows WHERE vrc_id = ? AND target = ?')->execute([$who['vrc_id'], $target]);
    return ['following' => $on, 'msg' => $on ? '⭐ Du folgst jetzt.' : 'Nicht mehr gefolgt.'];
}
function nc_follows(array $who): array {
    $st = nc_db()->prepare('SELECT f.target AS vrc_id, a.display_name AS name, f.created FROM comm_follows f LEFT JOIN nexus_accounts a ON a.vrc_id = f.target WHERE f.vrc_id = ? ORDER BY f.created DESC LIMIT 1000');
    $st->execute([$who['vrc_id']]);
    return ['items' => $st->fetchAll(PDO::FETCH_ASSOC)];
}
/** Trends der letzten 7 Tage: Hashtags, meistbeachtete Beiträge, aktive Leute (nur SFW, außer der Feed ist nsfw und freigegeben) */
function nc_trends(array $who, string $rating = 'sfw'): array {
    if ($rating === 'nsfw') nc_need_nsfw($who); else $rating = 'sfw';
    $since = time() - 7 * 86400;
    $st = nc_db()->prepare("SELECT text FROM comm_posts WHERE rating = ? AND status = 'visible' AND created > ? ORDER BY id DESC LIMIT 2000");
    $st->execute([$rating, $since]);
    $tags = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) foreach (nc_tags((string)$t) as $g) $tags[$g] = ($tags[$g] ?? 0) + 1;
    arsort($tags);
    $tp = nc_db()->prepare("SELECT p.*, (SELECT COUNT(*) FROM comm_reactions r WHERE r.post_id = p.id) + 2 * (SELECT COUNT(*) FROM comm_posts c WHERE c.parent = p.id AND c.status = 'visible') AS score
        FROM comm_posts p WHERE p.rating = ? AND p.parent = 0 AND p.status = 'visible' AND p.created > ? ORDER BY score DESC, p.id DESC LIMIT 5");
    $tp->execute([$rating, $since]);
    $top = array_values(array_filter($tp->fetchAll(PDO::FETCH_ASSOC), fn($r) => (int)$r['score'] > 0));
    $pp = nc_db()->prepare("SELECT p.author AS vrc_id, a.display_name AS name, COUNT(*) AS n FROM comm_posts p LEFT JOIN nexus_accounts a ON a.vrc_id = p.author
        WHERE p.rating = ? AND p.status = 'visible' AND p.created > ? GROUP BY p.author ORDER BY n DESC LIMIT 5");
    $pp->execute([$rating, $since]);
    $out = [];
    foreach (array_slice($tags, 0, 10, true) as $g => $n) $out[] = ['tag' => (string)$g, 'n' => $n];
    return ['tags' => $out, 'top' => nc_decorate($top, $who), 'people' => array_map(fn($r) => ['vrc_id' => $r['vrc_id'], 'name' => (string)$r['name'], 'n' => (int)$r['n']], $pp->fetchAll(PDO::FETCH_ASSOC))];
}
/** Antworten auf meine Beiträge (neueste zuerst) + Zahl der ungelesenen; seen=1 setzt den Lesestand */
function nc_inbox(array $who, bool $markSeen = false): array {
    $st = nc_db()->prepare("SELECT r.* FROM comm_posts r JOIN comm_posts p ON p.id = r.parent
        WHERE p.author = ? AND r.author != ? AND r.status = 'visible' AND (r.rating = 'sfw' OR ?) ORDER BY r.id DESC LIMIT 40");
    $st->execute([$who['vrc_id'], $who['vrc_id'], $who['_nsfw'] ? 1 : 0]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $s = nc_db()->prepare('SELECT replies_seen FROM comm_seen WHERE vrc_id = ?'); $s->execute([$who['vrc_id']]);
    $seen = (int)$s->fetchColumn();
    $unread = count(array_filter($rows, fn($r) => (int)$r['id'] > $seen));
    if ($markSeen && $rows) nc_db()->prepare('INSERT INTO comm_seen (vrc_id, replies_seen) VALUES (?, ?) ON CONFLICT(vrc_id) DO UPDATE SET replies_seen = excluded.replies_seen')->execute([$who['vrc_id'], (int)$rows[0]['id']]);
    return ['items' => nc_decorate($rows, $who), 'unread' => $unread, 'seen' => $seen];
}

// ---------- 6.0 (18+): Profil-Anfragen, Verbindungen, Kompatibilität, Consent-Check ----------
const NC_REQ_DAYS = 14;
function nc_pair(string $a, string $b): array { return strcmp($a, $b) < 0 ? [$a, $b] : [$b, $a]; }
function nc_connected(string $a, string $b): bool {
    if ($a === $b) return true;
    $st = nc_db()->prepare("SELECT 1 FROM comm_requests WHERE status = 'accepted' AND ((from_id = ? AND to_id = ?) OR (from_id = ? AND to_id = ?)) LIMIT 1");
    $st->execute([$a, $b, $b, $a]);
    return (bool)$st->fetchColumn();
}
/** Stand zwischen zwei Personen: none | sent | received | connected (+ Anfrage-ID) */
function nc_link_state(string $me, string $other): array {
    $st = nc_db()->prepare("SELECT * FROM comm_requests WHERE ((from_id = ? AND to_id = ?) OR (from_id = ? AND to_id = ?)) AND (status = 'accepted' OR (status = 'pending' AND expires > ?)) ORDER BY id DESC LIMIT 1");
    $st->execute([$me, $other, $other, $me, time()]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return ['state' => 'none'];
    if ($r['status'] === 'accepted') return ['state' => 'connected', 'id' => (int)$r['id']];
    return ['state' => $r['from_id'] === $me ? 'sent' : 'received', 'id' => (int)$r['id']];
}
/** Darf diese Person 18+-Anfragen bekommen? (Verified 18+, NSFW an, nicht gesperrt, offen für Kontakt) */
function nc_req_target(string $id): array {
    $acc = nx_account($id);
    if (!$acc || !empty($acc['banned']) || !nx_nsfw_allowed($acc)) throw new NcError('Diese Person kann gerade keine 18+-Anfragen bekommen.');
    $st = nc_db()->prepare('SELECT nsfw_facts FROM comm_profiles WHERE vrc_id = ?'); $st->execute([$id]);
    $nf = json_decode((string)$st->fetchColumn(), true) ?: [];
    if (($nf['open_contact'] ?? '') === 'no' || ($nf['visibility'] ?? '') === 'hidden') throw new NcError('Diese Person nimmt gerade keine Anfragen an.');
    return $acc;
}
function nc_req_send(array $who, string $to, string $message): array {
    nc_need_nsfw($who); nc_can_post($who);
    $to = strtolower(trim($to));
    if (!nx_uid_ok($to) || $to === $who['vrc_id']) throw new NcError('Ungültige Person.');
    nc_req_target($to);
    $db = nc_db();
    $ls = nc_link_state($who['vrc_id'], $to);
    if ($ls['state'] === 'connected') throw new NcError('Ihr seid schon verbunden.');
    if ($ls['state'] !== 'none') throw new NcError($ls['state'] === 'sent' ? 'Deine Anfrage wartet noch auf Antwort.' : 'Diese Person hat dir schon eine Anfrage geschickt – schau in deine Anfragen.');
    $n = $db->prepare("SELECT COUNT(*) FROM comm_requests WHERE from_id = ? AND created > ?"); $n->execute([$who['vrc_id'], time() - 86400]);
    if ((int)$n->fetchColumn() >= 20) throw new NcError('Höchstens 20 Anfragen pro Tag.');
    $d = $db->prepare("SELECT COUNT(*) FROM comm_requests WHERE from_id = ? AND to_id = ? AND status = 'declined' AND answered > ?"); $d->execute([$who['vrc_id'], $to, time() - 30 * 86400]);
    if ((int)$d->fetchColumn() > 0) throw new NcError('Diese Person hat vor Kurzem abgelehnt – erneut geht erst nach 30 Tagen.');
    $db->prepare('INSERT INTO comm_requests (from_id, to_id, message, status, created, expires) VALUES (?, ?, ?, \'pending\', ?, ?)')
        ->execute([$who['vrc_id'], $to, nc_cut($message, 500), time(), time() + NC_REQ_DAYS * 86400]);
    return ['link18' => nc_link_state($who['vrc_id'], $to), 'msg' => '💌 Anfrage geschickt.'];
}
function nc_req_row(int $id): ?array { $st = nc_db()->prepare('SELECT * FROM comm_requests WHERE id = ?'); $st->execute([$id]); return $st->fetch(PDO::FETCH_ASSOC) ?: null; }
function nc_req_answer(array $who, int $id, bool $accept): array {
    nc_need_nsfw($who);
    $r = nc_req_row($id);
    if (!$r || $r['to_id'] !== $who['vrc_id'] || $r['status'] !== 'pending' || (int)$r['expires'] < time()) throw new NcError('Anfrage nicht (mehr) offen.');
    if ($accept) nc_req_target($r['from_id']);
    nc_db()->prepare('UPDATE comm_requests SET status = ?, answered = ? WHERE id = ?')->execute([$accept ? 'accepted' : 'declined', time(), $id]);
    return ['msg' => $accept ? '💞 Angenommen – ihr seid jetzt verbunden.' : 'Abgelehnt. Die andere Person sieht nur „nicht angenommen“.'];
}
/** Zurückziehen (eigene offene Anfrage) oder Verbindung lösen (beide Seiten) */
function nc_req_withdraw(array $who, int $id): array {
    $r = nc_req_row($id);
    if (!$r || ($r['from_id'] !== $who['vrc_id'] && $r['to_id'] !== $who['vrc_id'])) throw new NcError('Nicht gefunden.');
    if ($r['status'] === 'pending' && $r['from_id'] === $who['vrc_id']) { nc_db()->prepare("UPDATE comm_requests SET status = 'withdrawn', answered = ? WHERE id = ?")->execute([time(), $id]); return ['msg' => 'Anfrage zurückgezogen.']; }
    if ($r['status'] === 'accepted') {
        nc_db()->prepare("UPDATE comm_requests SET status = 'ended', answered = ? WHERE id = ?")->execute([time(), $id]);
        $other = $r['from_id'] === $who['vrc_id'] ? $r['to_id'] : $r['from_id'];
        nc_db()->prepare("UPDATE comm_sessions SET status = 'ended', ended = ?, ended_by = ?, end_reason = 'unlinked' WHERE status IN ('proposed', 'ready') AND ((a_id = ? AND b_id = ?) OR (a_id = ? AND b_id = ?))")->execute([time(), $who['vrc_id'], $who['vrc_id'], $other, $other, $who['vrc_id']]);
        return ['msg' => 'Verbindung gelöst.'];
    }
    throw new NcError('Nichts zu tun.');
}
function nc_names(array $ids): array {
    $ids = array_values(array_unique(array_filter($ids)));
    if (!$ids) return [];
    $q = nc_db()->prepare('SELECT vrc_id, display_name FROM nexus_accounts WHERE vrc_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'); $q->execute($ids);
    return $q->fetchAll(PDO::FETCH_KEY_PAIR);
}
function nc_req_list(array $who): array {
    nc_need_nsfw($who);
    $me = $who['vrc_id'];
    $st = nc_db()->prepare("SELECT * FROM comm_requests WHERE (to_id = ? OR from_id = ?) AND (status IN ('pending', 'accepted') OR answered > ?) ORDER BY id DESC LIMIT 300");
    $st->execute([$me, $me, time() - 30 * 86400]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $names = nc_names(array_merge(array_column($rows, 'from_id'), array_column($rows, 'to_id')));
    $in = $out = $con = [];
    foreach ($rows as $r) {
        $other = $r['from_id'] === $me ? $r['to_id'] : $r['from_id'];
        $e = ['id' => (int)$r['id'], 'vrc_id' => $other, 'name' => (string)($names[$other] ?? ''), 'created' => (int)$r['created'], 'answered' => (int)$r['answered'], 'expires' => (int)$r['expires']];
        if ($r['status'] === 'accepted') { $con[] = $e + ['since' => (int)$r['answered']]; continue; }
        if ($r['status'] === 'pending' && (int)$r['expires'] < time()) $r['status'] = 'expired';
        if ($r['to_id'] === $me) { if ($r['status'] === 'pending') $in[] = $e + ['message' => $r['message']]; }
        else $out[] = $e + ['message' => $r['message'], 'status' => $r['status'] === 'declined' ? 'not_accepted' : $r['status']];   // Ablehnung ohne Details
    }
    // 6.7: Erinnerung – offene Anfragen an mich, die schon über 3 Tage warten (laufen nach 14 Tagen ab)
    $remind = count(array_filter($in, fn($r) => $r['created'] < time() - 3 * 86400));
    return ['incoming' => $in, 'outgoing' => $out, 'connections' => $con, 'sessions' => nc_sess_list($who), 'remind' => $remind];
}
/** Kompatibilität aus den Vorlieben (kinks: [empfangen, geben], −1 nein … 2 sehr gern) – nur für Verbundene */
function nc_compat(array $who, string $other): array {
    nc_need_nsfw($who);
    $other = strtolower(trim($other));
    if (!nc_connected($who['vrc_id'], $other) || $other === $who['vrc_id']) throw new NcError('Kompatibilität gibt es nur für Verbindungen (beide haben zugestimmt).');
    $get = function ($id) { $st = nc_db()->prepare('SELECT nsfw_facts FROM comm_profiles WHERE vrc_id = ?'); $st->execute([$id]); $nf = json_decode((string)$st->fetchColumn(), true) ?: []; return (array)($nf['kinks'] ?? []); };
    $a = $get($who['vrc_id']); $b = $get($other);
    $match = $maybe = $conflict = [];
    foreach (array_intersect(array_keys($a), array_keys($b)) as $k) {
        // ich empfange ↔ du gibst, ich gebe ↔ du empfängst
        foreach ([[0, 1], [1, 0]] as [$i, $j]) {
            $x = $a[$k][$i] ?? null; $y = $b[$k][$j] ?? null;
            if ($x === null || $y === null) continue;
            $e = ['k' => $k, 'dir' => $i === 0 ? 'me' : 'you', 'a' => $x, 'b' => $y];
            if ($x >= 1 && $y >= 1) { if ($x + $y >= 3) $match[] = $e; else $maybe[] = $e; }
            elseif (($x === -1 && $y >= 1) || ($y === -1 && $x >= 1)) $conflict[] = ['k' => $k, 'dir' => $i === 0 ? 'me' : 'you'];
        }
    }
    $tot = count($match) + count($maybe) + count($conflict);
    return ['score' => $tot ? (int)round((count($match) + 0.5 * count($maybe)) / $tot * 100) : null, 'match' => $match, 'maybe' => $maybe, 'conflict' => $conflict,
            'note' => 'Nur ein Gesprächsanfang – redet immer direkt über Grenzen.'];
}
// ---------- Consent-Check vor einer gemeinsamen Session ----------
function nc_terms(array $in): array {
    $light = in_array($in['light'] ?? '', ['green', 'yellow', 'red'], true) ? $in['light'] : 'yellow';
    return ['light' => $light, 'limits' => nc_cut((string)($in['limits'] ?? ''), 500), 'safeword' => nc_cut((string)($in['safeword'] ?? ''), 40) ?: 'Rot',
            'minutes' => max(5, min(240, (int)($in['minutes'] ?? 30))), 'toys' => !empty($in['toys']), 'at' => time()];
}
function nc_sess_row(int $id): ?array { $st = nc_db()->prepare('SELECT * FROM comm_sessions WHERE id = ?'); $st->execute([$id]); return $st->fetch(PDO::FETCH_ASSOC) ?: null; }
function nc_sess_pub(array $s, string $me): array {
    $mineA = $s['a_id'] === $me;
    $st = $s['status'] === 'ready' && (int)$s['ends'] < time() ? 'ended' : $s['status'];
    return ['id' => (int)$s['id'], 'with' => $mineA ? $s['b_id'] : $s['a_id'], 'mine' => json_decode($mineA ? $s['a_terms'] : $s['b_terms'], true) ?: null,
            'theirs' => json_decode($mineA ? $s['b_terms'] : $s['a_terms'], true) ?: null, 'proposed_by_me' => $mineA, 'status' => $st, 'created' => (int)$s['created'],
            'ready' => (int)$s['ready'], 'ends' => (int)$s['ends'], 'ended' => (int)$s['ended'], 'ended_by_me' => $s['ended_by'] === $me, 'end_reason' => $s['end_reason']];
}
function nc_sess_list(array $who): array {
    $st = nc_db()->prepare("SELECT * FROM comm_sessions WHERE (a_id = ? OR b_id = ?) AND (status IN ('proposed', 'ready') OR ended > ?) ORDER BY id DESC LIMIT 50");
    $st->execute([$who['vrc_id'], $who['vrc_id'], time() - 7 * 86400]);
    return array_map(fn($s) => nc_sess_pub($s, $who['vrc_id']), $st->fetchAll(PDO::FETCH_ASSOC));
}
function nc_sess_start(array $who, string $other, array $terms): array {
    nc_need_nsfw($who);
    $other = strtolower(trim($other));
    if (!nc_connected($who['vrc_id'], $other) || $other === $who['vrc_id']) throw new NcError('Consent-Check nur mit Verbindungen.');
    nc_req_target($other);
    $t = nc_terms($terms);
    if ($t['light'] === 'red') throw new NcError('Bei Rot startet keine Session.');
    $db = nc_db();
    $db->prepare("UPDATE comm_sessions SET status = 'ended', ended = ?, ended_by = ?, end_reason = 'replaced' WHERE status = 'proposed' AND a_id = ? AND b_id = ?")->execute([time(), $who['vrc_id'], $who['vrc_id'], $other]);
    $db->prepare('INSERT INTO comm_sessions (a_id, b_id, a_terms, status, created) VALUES (?, ?, ?, \'proposed\', ?)')->execute([$who['vrc_id'], $other, json_encode($t, JSON_UNESCAPED_UNICODE), time()]);
    return ['session' => nc_sess_pub(nc_sess_row((int)$db->lastInsertId()), $who['vrc_id']), 'msg' => '🤝 Consent-Check geschickt – startet erst, wenn beide zugestimmt haben.'];
}
function nc_sess_answer(array $who, int $id, bool $agree, array $terms): array {
    nc_need_nsfw($who);
    $s = nc_sess_row($id);
    if (!$s || $s['b_id'] !== $who['vrc_id'] || $s['status'] !== 'proposed') throw new NcError('Kein offener Consent-Check.');
    if (!$agree) { nc_db()->prepare("UPDATE comm_sessions SET status = 'ended', ended = ?, ended_by = ?, end_reason = 'declined' WHERE id = ?")->execute([time(), $who['vrc_id'], $id]); return ['msg' => 'Nein ist okay – nichts startet.']; }
    $t = nc_terms($terms);
    if ($t['light'] === 'red') throw new NcError('Bei Rot startet keine Session.');
    $a = json_decode($s['a_terms'], true) ?: [];
    $min = min((int)($a['minutes'] ?? 30), $t['minutes']);   // die kürzere Zeit gilt
    nc_db()->prepare("UPDATE comm_sessions SET b_terms = ?, status = 'ready', ready = ?, ends = ? WHERE id = ?")->execute([json_encode($t, JSON_UNESCAPED_UNICODE), time(), time() + $min * 60, $id]);
    return ['session' => nc_sess_pub(nc_sess_row($id), $who['vrc_id']), 'msg' => '✅ Beide haben zugestimmt – ' . $min . ' min, Safewords gelten sofort.'];
}
/** Beenden – jederzeit, von beiden Seiten, ohne Begründung (Safeword) */
function nc_sess_end(array $who, int $id, string $reason): array {
    $s = nc_sess_row($id);
    if (!$s || ($s['a_id'] !== $who['vrc_id'] && $s['b_id'] !== $who['vrc_id'])) throw new NcError('Nicht gefunden.');
    if (!in_array($s['status'], ['proposed', 'ready'], true)) return ['msg' => 'Schon beendet.'];
    $reason = in_array($reason, ['safeword', 'done', 'cancel'], true) ? $reason : 'done';
    nc_db()->prepare("UPDATE comm_sessions SET status = 'ended', ended = ?, ended_by = ?, end_reason = ? WHERE id = ?")->execute([time(), $who['vrc_id'], $reason, $id]);
    return ['msg' => $reason === 'safeword' ? '🛑 Safeword – beendet.' : 'Beendet.'];
}
