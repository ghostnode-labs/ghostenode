<?php
declare(strict_types=1);
/**
 * 7.2 Steckbriefe & Creator – Welten und Avatare mit Community-Bewertungen und Tags (nur SFW), Creator-Profile mit Shop-Links.
 * Gleiches Nexus-Konto wie Community/Clubs (Identität = VRChat-ID). Lesen ist öffentlich, Schreiben braucht ein Konto.
 * Avatar-Daten kommen nur von der Community (keine fremden Avatar-Datenbanken). Bewertungstexte laufen durch den Wortfilter der Webseite.
 */
require_once __DIR__ . '/nexuscomm.php';

const SB_KINDS = ['world' => '🌐 Welt', 'avatar' => '🎭 Avatar'];
const SB_SHOPS = ['booth.pm' => 'Booth', 'gumroad.com' => 'Gumroad', 'jinxxy.com' => 'Jinxxy', 'ko-fi.com' => 'Ko-fi', 'patreon.com' => 'Patreon', 'payhip.com' => 'Payhip', 'itch.io' => 'itch.io', 'vrchat.com' => 'VRChat', 'x.com' => 'X', 'bsky.app' => 'Bluesky', 'youtube.com' => 'YouTube', 'twitch.tv' => 'Twitch'];

function sb_db(): PDO {
    $db = nc_db();
    static $done = false;
    if (!$done) {
        $done = true;
        if (setting('wiki_schema', '0') !== '1') {
            $db->exec("
                CREATE TABLE IF NOT EXISTS sb_items (kind TEXT NOT NULL, id TEXT NOT NULL, name TEXT NOT NULL, creator_id TEXT NOT NULL DEFAULT '', creator_name TEXT NOT NULL DEFAULT '',
                    descr TEXT NOT NULL DEFAULT '', image TEXT NOT NULL DEFAULT '', created_by TEXT NOT NULL, created INTEGER NOT NULL, updated INTEGER NOT NULL, PRIMARY KEY (kind, id));
                CREATE TABLE IF NOT EXISTS sb_reviews (kind TEXT NOT NULL, id TEXT NOT NULL, vrc_id TEXT NOT NULL, stars INTEGER NOT NULL, text TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'ok', created INTEGER NOT NULL, PRIMARY KEY (kind, id, vrc_id));
                CREATE TABLE IF NOT EXISTS sb_tags (kind TEXT NOT NULL, id TEXT NOT NULL, tag TEXT NOT NULL, vrc_id TEXT NOT NULL, PRIMARY KEY (kind, id, tag, vrc_id));
                CREATE TABLE IF NOT EXISTS sb_creators (vrc_id TEXT PRIMARY KEY, bio TEXT NOT NULL DEFAULT '', links TEXT NOT NULL DEFAULT '[]', skills TEXT NOT NULL DEFAULT '', updated INTEGER NOT NULL);
                CREATE INDEX IF NOT EXISTS idx_sb_creator ON sb_items(creator_id);
            ");
            set_setting('wiki_schema', '1');
        }
    }
    return $db;
}

function sb_id(string $kind, string $id): array {
    if (!isset(SB_KINDS[$kind])) throw new NcError('Unbekannte Art (Welt oder Avatar).');
    $id = strtolower(trim($id));
    $pre = $kind === 'world' ? 'wrld' : 'avtr';
    if (!preg_match('/^' . $pre . '_[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) throw new NcError($kind === 'world' ? 'Bitte eine Welt-ID (wrld_…) angeben.' : 'Bitte eine Avatar-ID (avtr_…) angeben.');
    return [$kind, $id];
}
function sb_filtered(string $text): bool {
    $t = mb_strtolower($text); $sq = preg_replace('/[\s._*\-]+/u', '', $t);
    foreach (explode(',', (string)setting('community_wordfilter', '')) as $w) { $w = mb_strtolower(trim($w)); if (mb_strlen($w) >= 2 && (str_contains($t, $w) || str_contains($sq, $w))) return true; }
    return false;
}
function sb_tag_norm(string $t): string { $t = mb_strtolower(trim(ltrim($t, '#'))); return preg_match('/^[\p{L}\p{N}][\p{L}\p{N}\- ]{1,23}$/u', $t) ? preg_replace('/\s+/', '-', $t) : ''; }

function sb_row(string $kind, string $id): ?array { $st = sb_db()->prepare('SELECT * FROM sb_items WHERE kind = ? AND id = ?'); $st->execute([$kind, $id]); return $st->fetch(PDO::FETCH_ASSOC) ?: null; }
function sb_rating(string $kind, string $id): array {
    $st = sb_db()->prepare("SELECT AVG(stars), COUNT(*) FROM sb_reviews WHERE kind = ? AND id = ? AND status = 'ok'"); $st->execute([$kind, $id]);
    [$avg, $n] = $st->fetch(PDO::FETCH_NUM);
    return ['avg' => $n ? round((float)$avg, 1) : 0, 'count' => (int)$n];
}
function sb_tags(string $kind, string $id, ?array $who, int $limit = 20): array {
    $st = sb_db()->prepare('SELECT tag, COUNT(*) AS n FROM sb_tags WHERE kind = ? AND id = ? GROUP BY tag ORDER BY n DESC, tag LIMIT ' . $limit); $st->execute([$kind, $id]);
    $mine = [];
    if ($who) { $m = sb_db()->prepare('SELECT tag FROM sb_tags WHERE kind = ? AND id = ? AND vrc_id = ?'); $m->execute([$kind, $id, $who['vrc_id']]); $mine = $m->fetchAll(PDO::FETCH_COLUMN); }
    return array_map(fn($r) => ['tag' => $r['tag'], 'n' => (int)$r['n'], 'mine' => in_array($r['tag'], $mine, true)], $st->fetchAll(PDO::FETCH_ASSOC));
}
function sb_pub(array $r, ?array $who = null, bool $full = false): array {
    $o = ['kind' => $r['kind'], 'id' => $r['id'], 'name' => $r['name'], 'creator_id' => $r['creator_id'], 'creator_name' => $r['creator_name'], 'descr' => $r['descr'], 'image' => $r['image'],
        'updated' => (int)$r['updated']] + sb_rating($r['kind'], $r['id']) + ['tags' => sb_tags($r['kind'], $r['id'], $full ? $who : null, $full ? 20 : 4)];
    if ($full && $who) $o['can_edit'] = $r['created_by'] === $who['vrc_id'] || $r['creator_id'] === $who['vrc_id'] || !empty($who['_mod']);
    return $o;
}

function sb_get(?array $who, string $kind, string $id): array {
    [$kind, $id] = sb_id($kind, $id);
    $r = sb_row($kind, $id);
    if (!$r) return ['exists' => false, 'kind' => $kind, 'id' => $id];
    $rv = sb_db()->prepare("SELECT * FROM sb_reviews WHERE kind = ? AND id = ? AND (status = 'ok' OR vrc_id = ?) ORDER BY created DESC LIMIT 100"); $rv->execute([$kind, $id, $who['vrc_id'] ?? '']);
    $rows = $rv->fetchAll(PDO::FETCH_ASSOC); $names = nc_names(array_column($rows, 'vrc_id'));
    return ['exists' => true, 'item' => sb_pub($r, $who, true), 'reviews' => array_map(fn($x) => ['name' => (string)($names[$x['vrc_id']] ?? ''), 'stars' => (int)$x['stars'], 'text' => $x['text'],
        'created' => (int)$x['created'], 'own' => $who && $x['vrc_id'] === $who['vrc_id'], 'held' => $x['status'] === 'pending'], $rows)];
}
function sb_save(array $who, array $in): array {
    nc_can_post($who);
    [$kind, $id] = sb_id((string)($in['kind'] ?? ''), (string)($in['id'] ?? ''));
    $r = sb_row($kind, $id);
    if ($r && $r['created_by'] !== $who['vrc_id'] && $r['creator_id'] !== $who['vrc_id'] && empty($who['_mod'])) throw new NcError('Nur wer den Steckbrief angelegt hat (oder der Creator) kann ihn ändern.');
    $name = nc_cut((string)($in['name'] ?? ''), 80); if (mb_strlen($name) < 2) throw new NcError('Name: mindestens 2 Zeichen.');
    $cid = strtolower(trim((string)($in['creator_id'] ?? '')));
    if ($cid !== '' && !nx_uid_ok($cid)) throw new NcError('Creator bitte als usr_… angeben (oder leer lassen).');
    $img = trim((string)($in['image'] ?? ''));
    if ($img !== '' && !preg_match('#^https://[^\s<>"\']{8,400}\.(png|jpe?g|webp)(\?[^\s<>"\']*)?$#i', $img)) throw new NcError('Bild: nur https-Adressen (png, jpg, webp).');
    $descr = nc_cut((string)($in['descr'] ?? ''), 2000);
    if (sb_filtered($name . ' ' . $descr)) throw new NcError('Der Text enthält ein gesperrtes Wort.');
    $db = sb_db(); $t = time();
    $vals = [$name, $cid, nc_cut((string)($in['creator_name'] ?? ''), 60), $descr, $img, $t];
    if ($r) $db->prepare('UPDATE sb_items SET name=?, creator_id=?, creator_name=?, descr=?, image=?, updated=? WHERE kind = ? AND id = ?')->execute(array_merge($vals, [$kind, $id]));
    else {
        if (nw_rate_over('sbn|' . $who['vrc_id'], 20)) throw new NcError('Zu viele neue Steckbriefe – kurz warten.');
        $db->prepare('INSERT INTO sb_items (name, creator_id, creator_name, descr, image, updated, kind, id, created_by, created) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute(array_merge($vals, [$kind, $id, $who['vrc_id'], $t]));
    }
    return ['msg' => '📝 Steckbrief gespeichert.'] + sb_get($who, $kind, $id);
}
function sb_review(array $who, array $in): array {
    nc_can_post($who);
    [$kind, $id] = sb_id((string)($in['kind'] ?? ''), (string)($in['id'] ?? ''));
    if (!sb_row($kind, $id)) throw new NcError('Steckbrief nicht gefunden.');
    $db = sb_db();
    if (!empty($in['delete'])) { $db->prepare('DELETE FROM sb_reviews WHERE kind = ? AND id = ? AND vrc_id = ?')->execute([$kind, $id, $who['vrc_id']]); return ['msg' => 'Bewertung gelöscht.'] + sb_get($who, $kind, $id); }
    $stars = (int)($in['stars'] ?? 0); if ($stars < 1 || $stars > 5) throw new NcError('Bitte 1 bis 5 Sterne wählen.');
    $text = nc_cut((string)($in['text'] ?? ''), 1000);
    $held = $text !== '' && sb_filtered($text);
    $db->prepare('DELETE FROM sb_reviews WHERE kind = ? AND id = ? AND vrc_id = ?')->execute([$kind, $id, $who['vrc_id']]);
    $db->prepare('INSERT INTO sb_reviews (kind, id, vrc_id, stars, text, status, created) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$kind, $id, $who['vrc_id'], $stars, $text, $held ? 'pending' : 'ok', time()]);
    return ['msg' => $held ? '⏳ Bewertung wird geprüft (Wortfilter).' : '⭐ Danke für deine Bewertung!'] + sb_get($who, $kind, $id);
}
function sb_tag(array $who, array $in): array {
    nc_can_post($who);
    [$kind, $id] = sb_id((string)($in['kind'] ?? ''), (string)($in['id'] ?? ''));
    if (!sb_row($kind, $id)) throw new NcError('Steckbrief nicht gefunden.');
    $tag = sb_tag_norm((string)($in['tag'] ?? '')); if ($tag === '') throw new NcError('Tag: 2–24 Zeichen, Buchstaben, Zahlen und Bindestrich.');
    if (sb_filtered($tag)) throw new NcError('Dieser Tag ist gesperrt.');
    $db = sb_db();
    if (empty($in['on']) || $in['on'] === '0') $db->prepare('DELETE FROM sb_tags WHERE kind = ? AND id = ? AND tag = ? AND vrc_id = ?')->execute([$kind, $id, $tag, $who['vrc_id']]);
    else {
        $n = $db->prepare('SELECT COUNT(*) FROM sb_tags WHERE kind = ? AND id = ? AND vrc_id = ?'); $n->execute([$kind, $id, $who['vrc_id']]);
        if ((int)$n->fetchColumn() >= 10) throw new NcError('Höchstens 10 Tags je Steckbrief von dir.');
        $db->prepare('DELETE FROM sb_tags WHERE kind = ? AND id = ? AND tag = ? AND vrc_id = ?')->execute([$kind, $id, $tag, $who['vrc_id']]);
        $db->prepare('INSERT INTO sb_tags (kind, id, tag, vrc_id) VALUES (?, ?, ?, ?)')->execute([$kind, $id, $tag, $who['vrc_id']]);
    }
    return ['tags' => sb_tags($kind, $id, $who)];
}
/** Suche & Bestenliste (öffentlich) */
function sb_list(?array $who, array $f): array {
    $sql = 'SELECT i.*, (SELECT AVG(stars) FROM sb_reviews r WHERE r.kind = i.kind AND r.id = i.id AND r.status = \'ok\') AS av, (SELECT COUNT(*) FROM sb_reviews r WHERE r.kind = i.kind AND r.id = i.id AND r.status = \'ok\') AS cnt FROM sb_items i WHERE 1=1';
    $a = [];
    if (isset(SB_KINDS[$f['kind'] ?? ''])) { $sql .= ' AND i.kind = ?'; $a[] = $f['kind']; }
    if (($q = trim((string)($f['q'] ?? ''))) !== '') { $sql .= ' AND (i.name LIKE ? OR i.creator_name LIKE ? OR i.id = ?)'; $a[] = '%' . $q . '%'; $a[] = '%' . $q . '%'; $a[] = strtolower($q); }
    if (($tg = sb_tag_norm((string)($f['tag'] ?? ''))) !== '') { $sql .= ' AND EXISTS (SELECT 1 FROM sb_tags t WHERE t.kind = i.kind AND t.id = i.id AND t.tag = ?)'; $a[] = $tg; }
    if (($c = strtolower((string)($f['creator'] ?? ''))) !== '' && nx_uid_ok($c)) { $sql .= ' AND i.creator_id = ?'; $a[] = $c; }
    $sort = ['new' => 'i.created DESC', 'rated' => 'cnt DESC, av DESC', 'top' => '(COALESCE(av,0) * cnt + 3 * 2) / (cnt + 2) DESC, cnt DESC'][$f['sort'] ?? 'top'] ?? 'i.created DESC';
    $st = sb_db()->prepare($sql . ' ORDER BY ' . $sort . ' LIMIT ' . max(1, min(100, (int)($f['limit'] ?? 50)))); $st->execute($a);
    $pop = sb_db()->query('SELECT tag, COUNT(*) AS n FROM sb_tags GROUP BY tag ORDER BY n DESC LIMIT 24')->fetchAll(PDO::FETCH_ASSOC);
    return ['items' => array_map(fn($r) => sb_pub($r, $who), $st->fetchAll(PDO::FETCH_ASSOC)), 'kinds' => SB_KINDS, 'popular_tags' => array_column($pop, 'tag')];
}

// ---------- Creator ----------
function sb_links(array $raw): array {
    $out = [];
    foreach (array_slice($raw, 0, 8) as $l) {
        $u = trim(is_array($l) ? (string)($l['url'] ?? '') : (string)$l);
        if ($u === '') continue;
        if (!preg_match('#^https://([a-z0-9.\-]+)(/[^\s<>"\']*)?$#i', $u, $m) || strlen($u) > 300) throw new NcError('Links nur als https-Adresse.');
        $host = strtolower(preg_replace('/^www\./', '', $m[1])); $label = 'Webseite';
        foreach (SB_SHOPS as $d => $n) if ($host === $d || str_ends_with($host, '.' . $d)) { $label = $n; break; }
        $out[] = ['url' => $u, 'label' => is_array($l) && trim((string)($l['label'] ?? '')) !== '' ? nc_cut((string)$l['label'], 30) : $label];
    }
    return $out;
}
function sb_creator(?array $who, string $id): array {
    $id = strtolower(trim($id)); if (!nx_uid_ok($id)) throw new NcError('Unbekannter Creator.');
    $st = sb_db()->prepare('SELECT * FROM sb_creators WHERE vrc_id = ?'); $st->execute([$id]); $c = $st->fetch(PDO::FETCH_ASSOC);
    $items = sb_list($who, ['creator' => $id, 'limit' => 100, 'sort' => 'top'])['items'];
    $names = nc_names([$id]);
    $rated = array_filter($items, fn($x) => $x['count'] > 0);
    return ['id' => $id, 'name' => (string)($names[$id] ?? ($items[0]['creator_name'] ?? '')), 'bio' => (string)($c['bio'] ?? ''), 'links' => json_decode((string)($c['links'] ?? '[]'), true) ?: [],
        'skills' => array_values(array_filter(array_map('trim', explode(',', (string)($c['skills'] ?? ''))))), 'items' => $items, 'own' => $who && $who['vrc_id'] === $id,
        'avg' => $rated ? round(array_sum(array_map(fn($x) => $x['avg'] * $x['count'], $rated)) / array_sum(array_column($rated, 'count')), 1) : 0, 'reviews' => array_sum(array_column($items, 'count'))];
}
function sb_creator_save(array $who, array $in): array {
    nc_can_post($who);
    $links = $in['links'] ?? []; if (is_string($links)) $links = json_decode($links, true) ?: preg_split('/\s+/', $links);
    $bio = nc_cut((string)($in['bio'] ?? ''), 1000); if (sb_filtered($bio)) throw new NcError('Der Text enthält ein gesperrtes Wort.');
    $skills = implode(', ', array_slice(array_filter(array_map(fn($s) => nc_cut($s, 24), explode(',', (string)($in['skills'] ?? '')))), 0, 8));
    $db = sb_db();
    $db->prepare('DELETE FROM sb_creators WHERE vrc_id = ?')->execute([$who['vrc_id']]);
    $db->prepare('INSERT INTO sb_creators (vrc_id, bio, links, skills, updated) VALUES (?, ?, ?, ?, ?)')->execute([$who['vrc_id'], $bio, json_encode(sb_links((array)$links), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $skills, time()]);
    return ['msg' => '🎨 Creator-Profil gespeichert.'] + sb_creator($who, $who['vrc_id']);
}
/** Seiten-Moderation: wartende Bewertungen (Wortfilter) */
function sb_queue(array $who): array {
    nc_require_mod($who);
    $st = sb_db()->query("SELECT * FROM sb_reviews WHERE status = 'pending' ORDER BY created LIMIT 100"); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $names = nc_names(array_column($rows, 'vrc_id'));
    return ['items' => array_map(fn($r) => ['kind' => $r['kind'], 'id' => $r['id'], 'vrc_id' => $r['vrc_id'], 'name' => (string)($names[$r['vrc_id']] ?? ''), 'stars' => (int)$r['stars'], 'text' => $r['text']], $rows)];
}
function sb_queue_decide(array $who, array $in): array {
    nc_require_mod($who);
    [$kind, $id] = sb_id((string)($in['kind'] ?? ''), (string)($in['id'] ?? ''));
    $v = (string)($in['vrc_id'] ?? '');
    if (!empty($in['ok'])) sb_db()->prepare("UPDATE sb_reviews SET status = 'ok' WHERE kind = ? AND id = ? AND vrc_id = ?")->execute([$kind, $id, $v]);
    else sb_db()->prepare('DELETE FROM sb_reviews WHERE kind = ? AND id = ? AND vrc_id = ?')->execute([$kind, $id, $v]);
    return ['msg' => !empty($in['ok']) ? 'Freigegeben.' : 'Gelöscht.'] + sb_queue($who);
}

function sb_action(?array $who, string $a, array $in): array {
    $need = function () use ($who) { if (!$who) throw new NcError('Bitte anmelden.'); return $who; };
    switch ($a) {
        case 'list': return sb_list($who, $in);
        case 'get': return sb_get($who, (string)($in['kind'] ?? ''), (string)($in['id'] ?? ''));
        case 'creator': return sb_creator($who, (string)($in['id'] ?? ($who['vrc_id'] ?? '')));
        case 'save': return sb_save($need(), $in);
        case 'review': return sb_review($need(), $in);
        case 'tag': return sb_tag($need(), $in);
        case 'creator_save': return sb_creator_save($need(), $in);
        case 'queue': return sb_queue($need());
        case 'queue_decide': return sb_queue_decide($need(), $in);
    }
    throw new NcError('Unbekannte Aktion.');
}
