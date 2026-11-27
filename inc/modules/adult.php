<?php
declare(strict_types=1);
/**
 * 7.3.2 – 🔞 Bereich für Konten mit „Verified 18+“ UND eingeschaltetem NSFW ($who['_nsfw']).
 *   • 18+-Clubs: Spalte clubs.adult – nur für freigegebene Konten sichtbar (Liste, Kalender, Ansicht, iCal, Discord-Feed, Widgets),
 *     Regeln Pflicht und vor dem Beitritt zu bestätigen (club_members.rules_at)
 *   • Einlass-Liste für Events (Club-Leitung hakt Zusagen beim Einlass ab) – für alle Clubs
 *   • Paar-Profile: zwei freigegebene Konten, beide bestätigen (auch jede Änderung)
 *   • Vorlagen-Bibliothek: Fantasien und Szenen teilen, bewerten (1–5), übernehmen; Wortfilter → Prüfung durch Mods
 *   • Grenzen-Vorschau bei 18+-Anfragen (Text aus dem Grenzen-Profil der App, freiwillig)
 * Alles läuft über nc_db() und damit auch unter MySQL (Übersetzung in inc/core/database.php).
 */

const AD_KINDS = ['fantasy' => '🎭 Fantasie', 'scene' => '🧱 Szene'];

function ad_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (setting('adult_schema', '0') === '1') return;
    $db = nc_db();
    $db->exec("
        CREATE TABLE IF NOT EXISTS adult_couples (id INTEGER PRIMARY KEY AUTOINCREMENT, a TEXT NOT NULL, b TEXT NOT NULL, name TEXT NOT NULL, bio TEXT NOT NULL DEFAULT '', looking TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'pending', draft TEXT NOT NULL DEFAULT '', draft_by TEXT NOT NULL DEFAULT '', created INTEGER NOT NULL, updated INTEGER NOT NULL);
        CREATE INDEX IF NOT EXISTS idx_ad_couple_a ON adult_couples(a);
        CREATE INDEX IF NOT EXISTS idx_ad_couple_b ON adult_couples(b);
        CREATE TABLE IF NOT EXISTS adult_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, title TEXT NOT NULL, descr TEXT NOT NULL DEFAULT '', body TEXT NOT NULL,
            author TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'ok', uses INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL, updated INTEGER NOT NULL);
        CREATE INDEX IF NOT EXISTS idx_ad_tpl_author ON adult_templates(author);
        CREATE TABLE IF NOT EXISTS adult_tpl_votes (tpl_id INTEGER NOT NULL, vrc_id TEXT NOT NULL, stars INTEGER NOT NULL, created INTEGER NOT NULL, PRIMARY KEY (tpl_id, vrc_id));
        CREATE TABLE IF NOT EXISTS adult_req_limits (req_id INTEGER PRIMARY KEY, text TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS club_checkins (event_id INTEGER NOT NULL, vrc_id TEXT NOT NULL, by_id TEXT NOT NULL, at INTEGER NOT NULL, PRIMARY KEY (event_id, vrc_id));
    ");
    foreach (['ALTER TABLE clubs ADD COLUMN adult INTEGER NOT NULL DEFAULT 0', "ALTER TABLE club_members ADD COLUMN rules_at INTEGER NOT NULL DEFAULT 0"] as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) { /* gibt es schon */ }
    }
    set_setting('adult_schema', '1');
}

/** Darf die aktuelle Anfrage 18+-Clubs sehen? (cl_action setzt das aus $who; öffentliche GETs nie) */
function ad_ok(): bool { return !empty($GLOBALS['cl_adult_ok']); }
function ad_sql(string $alias = ''): string { return ad_ok() ? '' : ' AND ' . ($alias !== '' ? $alias . '.' : '') . 'adult = 0'; }
function ad_need(array $who): void { nc_need_nsfw($who); cl_need_ok($who); }
function ad_filter(string $t): bool { return function_exists('cl2_filtered') && cl2_filtered([], $t); }   // Webseiten-Wortfilter (ohne Club-Liste)

// ---------------- 18+-Clubs ----------------
/** beim Anlegen/Ändern: 18+ nur mit Freigabe, dann Regeln Pflicht */
function ad_club_flag(array $who, array $in, array $d, ?array $old): int {
    $want = array_key_exists('adult', $in) ? (!empty($in['adult']) && $in['adult'] !== '0' ? 1 : 0) : (int)($old['adult'] ?? 0);
    if ($want && empty($who['_nsfw'])) throw new NcError('18+-Clubs gibt es nur mit Verified 18+ und eingeschaltetem NSFW.');
    if ($old && (int)($old['adult'] ?? 0) !== $want) {
        $n = nc_db()->prepare("SELECT COUNT(*) FROM club_members WHERE club_id = ? AND status = 'active'"); $n->execute([$old['id']]);
        if ((int)$n->fetchColumn() > 1) throw new NcError('18+ lässt sich nur ändern, solange der Club noch keine weiteren Mitglieder hat.');
    }
    if ($want && mb_strlen(trim($d['rules'])) < 20) throw new NcError('18+-Clubs brauchen Regeln (mindestens 20 Zeichen) – sie werden vor dem Beitritt bestätigt.');
    return $want;
}
/** Beitritt: 18+-Club nur mit Freigabe und bestätigten Regeln */
function ad_join_check(array $who, array $c, array $in): int {
    if (empty($c['adult'])) return 0;
    nc_need_nsfw($who);
    if (empty($in['accept_rules'])) throw new NcError('Bitte bestätige zuerst die Regeln dieses 18+-Clubs.');
    return time();
}

// ---------------- Einlass-Liste ----------------
function ad_door(array $who, int $id): array {
    $e = cl_event_row($id); $c = cl_get((string)$e['club_id']); cl_need_staff($c, $who);
    $db = nc_db();
    $st = $db->prepare("SELECT r.vrc_id, r.status, k.at FROM club_rsvp r LEFT JOIN club_checkins k ON k.event_id = r.event_id AND k.vrc_id = r.vrc_id WHERE r.event_id = ? AND r.status IN ('going','waitlist') ORDER BY r.status, r.created");
    $st->execute([$id]); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $names = nc_names(array_column($rows, 'vrc_id'));
    $list = array_map(fn($r) => ['vrc_id' => $r['vrc_id'], 'name' => (string)($names[$r['vrc_id']] ?? ''), 'status' => $r['status'], 'in' => (int)($r['at'] ?? 0)], $rows);
    return ['event' => ['id' => (int)$e['id'], 'title' => $e['title'], 'starts' => (int)$e['starts']], 'adult' => (bool)($c['adult'] ?? 0), 'list' => $list,
        'count' => ['going' => count(array_filter($list, fn($x) => $x['status'] === 'going')), 'in' => count(array_filter($list, fn($x) => $x['in'] > 0))]];
}
function ad_door_check(array $who, int $id, string $target, bool $on): array {
    $e = cl_event_row($id); $c = cl_get((string)$e['club_id']); cl_need_staff($c, $who);
    $db = nc_db();
    $r = $db->prepare("SELECT status FROM club_rsvp WHERE event_id = ? AND vrc_id = ?"); $r->execute([$id, $target]);
    if ($r->fetchColumn() !== 'going') throw new NcError('Nur Personen mit Zusage stehen auf der Einlass-Liste.');
    if ($on && !empty($c['adult'])) { $acc = nx_account($target); if (!$acc || !nx_nsfw_allowed($acc)) throw new NcError('Diese Person hat gerade keine 18+-Freigabe – kein Einlass.'); }
    if ($on) $db->prepare('INSERT OR REPLACE INTO club_checkins (event_id, vrc_id, by_id, at) VALUES (?, ?, ?, ?)')->execute([$id, $target, $who['vrc_id'], time()]);
    else $db->prepare('DELETE FROM club_checkins WHERE event_id = ? AND vrc_id = ?')->execute([$id, $target]);
    return ad_door($who, $id) + ['msg' => $on ? '✅ Eingelassen.' : 'Zurückgenommen.'];
}

// ---------------- Paar-Profile ----------------
function ad_couple_clean(array $in): array {
    $name = nc_cut((string)($in['name'] ?? ''), 60);
    if (mb_strlen($name) < 2) throw new NcError('Name des Paares: mindestens 2 Zeichen.');
    $d = ['name' => $name, 'bio' => nc_cut((string)($in['bio'] ?? ''), 1500), 'looking' => nc_cut((string)($in['looking'] ?? ''), 500)];
    if (ad_filter($d['name'] . ' ' . $d['bio'] . ' ' . $d['looking'])) throw new NcError('Der Text enthält gesperrte Wörter.');
    return $d;
}
function ad_couple_of(string $vid): ?array {
    $st = nc_db()->prepare("SELECT * FROM adult_couples WHERE (a = ? OR b = ?) AND status IN ('pending','active') ORDER BY id DESC LIMIT 1"); $st->execute([$vid, $vid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function ad_couple_pub(array $r, ?array $who): array {
    $names = nc_names([$r['a'], $r['b']]); $me = $who['vrc_id'] ?? '';
    $o = ['id' => (int)$r['id'], 'name' => $r['name'], 'bio' => $r['bio'], 'looking' => $r['looking'], 'status' => $r['status'],
        'members' => [['vrc_id' => $r['a'], 'name' => (string)($names[$r['a']] ?? '')], ['vrc_id' => $r['b'], 'name' => (string)($names[$r['b']] ?? '')]], 'updated' => (int)$r['updated']];
    if ($me === $r['a'] || $me === $r['b']) {
        $o['mine'] = true; $o['waiting_for_me'] = $r['status'] === 'pending' ? $me === $r['b'] : ($r['draft'] !== '' && $r['draft_by'] !== $me);
        if ($r['draft'] !== '') $o['draft'] = json_decode($r['draft'], true) + ['by' => $r['draft_by']];
    }
    return $o;
}
function ad_couple_invite(array $who, string $partner, array $in): array {
    ad_need($who);
    $partner = strtolower(trim($partner));
    if (!nx_uid_ok($partner) || $partner === $who['vrc_id']) throw new NcError('Bitte die VRChat-ID deines Gegenübers angeben.');
    $acc = nx_account($partner);
    if (!$acc || !empty($acc['banned']) || !nx_nsfw_allowed($acc)) throw new NcError('Diese Person hat gerade keine 18+-Freigabe.');
    if (ad_couple_of($who['vrc_id'])) throw new NcError('Du hast schon ein Paar-Profil (oder eine offene Einladung).');
    if (ad_couple_of($partner)) throw new NcError('Diese Person hat schon ein Paar-Profil.');
    if (function_exists('nc_connected') && !nc_connected($who['vrc_id'], $partner)) throw new NcError('Paar-Profile gehen nur mit Personen, mit denen du über eine 18+-Anfrage verbunden bist.');
    $d = ad_couple_clean($in);
    nc_db()->prepare("INSERT INTO adult_couples (a, b, name, bio, looking, status, created, updated) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?)")
        ->execute([$who['vrc_id'], $partner, $d['name'], $d['bio'], $d['looking'], time(), time()]);
    return ['couple' => ad_couple_pub(ad_couple_of($who['vrc_id']), $who), 'msg' => '💞 Einladung geschickt – das Profil erscheint, sobald ihr beide bestätigt habt.'];
}
function ad_couple_answer(array $who, bool $yes): array {
    ad_need($who);
    $r = ad_couple_of($who['vrc_id']);
    if (!$r) throw new NcError('Kein Paar-Profil.');
    $db = nc_db();
    if ($r['status'] === 'pending') {
        if ($r['b'] !== $who['vrc_id']) throw new NcError('Die Einladung muss dein Gegenüber bestätigen.');
        if ($yes) { $db->prepare("UPDATE adult_couples SET status = 'active', updated = ? WHERE id = ?")->execute([time(), $r['id']]); return ['couple' => ad_couple_pub(ad_couple_of($who['vrc_id']), $who), 'msg' => '💞 Euer Paar-Profil ist sichtbar.']; }
        $db->prepare('DELETE FROM adult_couples WHERE id = ?')->execute([$r['id']]); return ['couple' => null, 'msg' => 'Einladung abgelehnt.'];
    }
    if ($r['draft'] === '' || $r['draft_by'] === $who['vrc_id']) throw new NcError('Keine Änderung, die du bestätigen musst.');
    if ($yes) { $d = json_decode($r['draft'], true) ?: []; $db->prepare("UPDATE adult_couples SET name = ?, bio = ?, looking = ?, draft = '', draft_by = '', updated = ? WHERE id = ?")->execute([$d['name'], $d['bio'], $d['looking'], time(), $r['id']]); }
    else $db->prepare("UPDATE adult_couples SET draft = '', draft_by = '' WHERE id = ?")->execute([$r['id']]);
    return ['couple' => ad_couple_pub(ad_couple_of($who['vrc_id']), $who), 'msg' => $yes ? '✅ Änderung übernommen.' : 'Änderung verworfen.'];
}
function ad_couple_edit(array $who, array $in): array {
    ad_need($who);
    $r = ad_couple_of($who['vrc_id']);
    if (!$r || $r['status'] !== 'active') throw new NcError('Erst wenn beide bestätigt haben, lässt sich das Profil ändern.');
    $d = ad_couple_clean($in);
    nc_db()->prepare('UPDATE adult_couples SET draft = ?, draft_by = ? WHERE id = ?')->execute([json_encode($d, JSON_UNESCAPED_UNICODE), $who['vrc_id'], $r['id']]);
    return ['couple' => ad_couple_pub(ad_couple_of($who['vrc_id']), $who), 'msg' => '📝 Änderung vorgeschlagen – sichtbar, sobald dein Gegenüber bestätigt.'];
}
function ad_couple_end(array $who): array {
    $r = ad_couple_of($who['vrc_id']);
    if (!$r) throw new NcError('Kein Paar-Profil.');
    nc_db()->prepare('DELETE FROM adult_couples WHERE id = ?')->execute([$r['id']]);
    return ['couple' => null, 'msg' => $r['status'] === 'pending' ? 'Einladung zurückgezogen.' : 'Paar-Profil gelöscht.'];
}
function ad_couple_list(array $who, array $in): array {
    nc_need_nsfw($who);
    $sql = "SELECT * FROM adult_couples WHERE status = 'active'"; $args = [];
    if (($q = trim((string)($in['q'] ?? ''))) !== '') { $sql .= ' AND (name LIKE ? OR bio LIKE ? OR looking LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
    $st = nc_db()->prepare($sql . ' ORDER BY updated DESC LIMIT 60'); $st->execute($args);
    $rows = array_filter($st->fetchAll(PDO::FETCH_ASSOC), function ($r) { foreach ([$r['a'], $r['b']] as $id) { $a = nx_account($id); if (!$a || !nx_nsfw_allowed($a)) return false; } return true; });   // nur solange beide freigegeben sind
    $mine = ad_couple_of($who['vrc_id']);
    return ['items' => array_values(array_map(fn($r) => ad_couple_pub($r, $who), $rows)), 'mine' => $mine ? ad_couple_pub($mine, $who) : null];
}

// ---------------- Vorlagen-Bibliothek ----------------
function ad_tpl_body(string $kind, $body): array {
    if (is_string($body)) $body = json_decode($body, true);
    if (!is_array($body)) throw new NcError('Inhalt der Vorlage fehlt.');
    $s = fn($k, $n) => nc_cut((string)($body[$k] ?? ''), $n);
    if ($kind === 'fantasy') {
        $roles = array_values(array_filter(array_map(fn($r) => ['name' => nc_cut((string)($r['name'] ?? ''), 40), 'desc' => nc_cut((string)($r['desc'] ?? ''), 200)], array_slice((array)($body['roles'] ?? []), 0, 4)), fn($r) => $r['name'] !== ''));
        if (count($roles) < 2) throw new NcError('Eine Fantasie braucht mindestens zwei Rollen.');
        return ['place' => $s('place', 80), 'mood' => $s('mood', 80), 'roles' => $roles, 'limits' => $s('limits', 300), 'safeword' => $s('safeword', 40)];
    }
    $steps = array_slice((array)($body['steps'] ?? []), 0, 30); $out = [];
    foreach ($steps as $i => $st) {
        $sec = max(5, min(600, (int)($st['seconds'] ?? 30)));
        if (!empty($st['pause']) || ($st['pattern'] ?? '') === 'pause') { $out[] = ['pause' => true, 'seconds' => $sec]; continue; }
        $p = (string)($st['pattern'] ?? '');
        if (!preg_match('/^[a-z0-9_-]{1,40}$/', $p)) throw new NcError('Schritt ' . ($i + 1) . ': ungültiges Muster.');
        $out[] = ['pattern' => $p, 'strength' => max(5, min(100, (int)($st['strength'] ?? 50))), 'speed' => max(0.25, min(3, round((float)($st['speed'] ?? 1), 2))), 'seconds' => $sec];
    }
    if (!array_filter($out, fn($x) => empty($x['pause']))) throw new NcError('Die Szene braucht mindestens ein Muster.');
    return ['steps' => $out, 'rounds' => max(1, min(10, (int)($body['rounds'] ?? 1))), 'ramp' => max(0, min(30, (int)($body['ramp'] ?? 0)))];
}
function ad_tpl_row(int $id): array { $st = nc_db()->prepare('SELECT * FROM adult_templates WHERE id = ?'); $st->execute([$id]); $r = $st->fetch(PDO::FETCH_ASSOC); if (!$r) throw new NcError('Vorlage nicht gefunden.'); return $r; }
function ad_tpl_pub(array $r, array $who, bool $full = false): array {
    $v = nc_db()->prepare('SELECT COUNT(*) AS n, AVG(stars) AS a FROM adult_tpl_votes WHERE tpl_id = ?'); $v->execute([$r['id']]); $s = $v->fetch(PDO::FETCH_ASSOC);
    $m = nc_db()->prepare('SELECT stars FROM adult_tpl_votes WHERE tpl_id = ? AND vrc_id = ?'); $m->execute([$r['id'], $who['vrc_id']]);
    $names = nc_names([$r['author']]);
    $o = ['id' => (int)$r['id'], 'kind' => $r['kind'], 'title' => $r['title'], 'descr' => $r['descr'], 'author' => (string)($names[$r['author']] ?? ''), 'own' => $r['author'] === $who['vrc_id'],
        'status' => $r['status'], 'uses' => (int)$r['uses'], 'count' => (int)$s['n'], 'avg' => $s['n'] ? round((float)$s['a'], 1) : 0, 'my_stars' => (int)$m->fetchColumn(), 'created' => (int)$r['created']];
    if ($full) $o['body'] = json_decode($r['body'], true);
    return $o;
}
function ad_tpl_save(array $who, array $in): array {
    ad_need($who);
    $kind = (string)($in['kind'] ?? '');
    if (!isset(AD_KINDS[$kind])) throw new NcError('Art: Fantasie oder Szene.');
    $title = nc_cut((string)($in['title'] ?? ''), 60); if (mb_strlen($title) < 3) throw new NcError('Titel: mindestens 3 Zeichen.');
    $descr = nc_cut((string)($in['descr'] ?? ''), 600);
    $body = json_encode(ad_tpl_body($kind, $in['body'] ?? null), JSON_UNESCAPED_UNICODE);
    $held = ad_filter($title . ' ' . $descr . ' ' . $body);
    $db = nc_db(); $id = (int)($in['id'] ?? 0);
    if ($id) {
        $r = ad_tpl_row($id);
        if ($r['author'] !== $who['vrc_id']) throw new NcError('Nur die eigene Vorlage lässt sich ändern.');
        $db->prepare('UPDATE adult_templates SET title = ?, descr = ?, body = ?, status = ?, updated = ? WHERE id = ?')->execute([$title, $descr, $body, $held ? 'pending' : 'ok', time(), $id]);
    } else {
        $n = $db->prepare('SELECT COUNT(*) FROM adult_templates WHERE author = ? AND created > ?'); $n->execute([$who['vrc_id'], time() - 86400]);
        if ((int)$n->fetchColumn() >= 10) throw new NcError('Höchstens 10 neue Vorlagen pro Tag.');
        $db->prepare('INSERT INTO adult_templates (kind, title, descr, body, author, status, created, updated) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$kind, $title, $descr, $body, $who['vrc_id'], $held ? 'pending' : 'ok', time(), time()]);
        $id = (int)$db->lastInsertId();
    }
    return ['item' => ad_tpl_pub(ad_tpl_row($id), $who, true), 'msg' => $held ? '⏳ Gespeichert – wird wegen des Wortfilters erst geprüft.' : '📚 Vorlage geteilt.'];
}
function ad_tpl_list(array $who, array $in): array {
    nc_need_nsfw($who);
    $sql = "SELECT t.*, (SELECT AVG(stars) FROM adult_tpl_votes v WHERE v.tpl_id = t.id) AS av, (SELECT COUNT(*) FROM adult_tpl_votes v WHERE v.tpl_id = t.id) AS vn FROM adult_templates t WHERE (t.status = 'ok' OR t.author = ?)";
    $args = [$who['vrc_id']];
    if (isset(AD_KINDS[$in['kind'] ?? ''])) { $sql .= ' AND t.kind = ?'; $args[] = $in['kind']; }
    if (!empty($in['mine'])) { $sql .= ' AND t.author = ?'; $args[] = $who['vrc_id']; }
    if (($q = trim((string)($in['q'] ?? ''))) !== '') { $sql .= ' AND (t.title LIKE ? OR t.descr LIKE ?)'; array_push($args, "%$q%", "%$q%"); }
    $order = ['top' => 'av DESC, vn DESC', 'used' => 't.uses DESC', 'new' => 't.id DESC'][$in['sort'] ?? 'top'] ?? 'av DESC, vn DESC';
    $st = nc_db()->prepare($sql . ' ORDER BY ' . $order . ', t.id DESC LIMIT 100'); $st->execute($args);
    return ['items' => array_map(fn($r) => ad_tpl_pub($r, $who), $st->fetchAll(PDO::FETCH_ASSOC)), 'kinds' => AD_KINDS];
}
function ad_tpl_get(array $who, int $id, bool $use = false): array {
    nc_need_nsfw($who);
    $r = ad_tpl_row($id);
    if ($r['status'] !== 'ok' && $r['author'] !== $who['vrc_id'] && empty($who['_mod'])) throw new NcError('Vorlage nicht gefunden.');
    if ($use && $r['author'] !== $who['vrc_id']) nc_db()->prepare('UPDATE adult_templates SET uses = uses + 1 WHERE id = ?')->execute([$id]);
    return ['item' => ad_tpl_pub(ad_tpl_row($id), $who, true)];
}
function ad_tpl_rate(array $who, int $id, int $stars): array {
    ad_need($who);
    $r = ad_tpl_row($id);
    if ($r['status'] !== 'ok') throw new NcError('Vorlage nicht gefunden.');
    if ($r['author'] === $who['vrc_id']) throw new NcError('Die eigene Vorlage kannst du nicht bewerten.');
    if ($stars < 1 || $stars > 5) throw new NcError('1 bis 5 Sterne.');
    nc_db()->prepare('INSERT OR REPLACE INTO adult_tpl_votes (tpl_id, vrc_id, stars, created) VALUES (?, ?, ?, ?)')->execute([$id, $who['vrc_id'], $stars, time()]);
    return ['item' => ad_tpl_pub(ad_tpl_row($id), $who), 'msg' => '⭐ Danke!'];
}
function ad_tpl_del(array $who, int $id): array {
    $r = ad_tpl_row($id);
    if ($r['author'] !== $who['vrc_id'] && empty($who['_mod'])) throw new NcError('Nur die eigene Vorlage lässt sich löschen.');
    nc_db()->prepare('DELETE FROM adult_tpl_votes WHERE tpl_id = ?')->execute([$id]);
    nc_db()->prepare('DELETE FROM adult_templates WHERE id = ?')->execute([$id]);
    return ['msg' => 'Vorlage gelöscht.'];
}
function ad_tpl_queue(array $who, ?int $id = null, bool $ok = false): array {
    nc_require_mod($who);
    if ($id) nc_db()->prepare('UPDATE adult_templates SET status = ? WHERE id = ?')->execute([$ok ? 'ok' : 'hidden', $id]);
    $st = nc_db()->prepare("SELECT * FROM adult_templates WHERE status = 'pending' ORDER BY id LIMIT 100"); $st->execute();
    return ['items' => array_map(fn($r) => ad_tpl_pub($r, $who, true), $st->fetchAll(PDO::FETCH_ASSOC))];
}

// ---------------- Grenzen-Vorschau bei 18+-Anfragen ----------------
function ad_req_limits_set(int $req, string $text): void {
    $text = nc_cut(trim($text), 1500);
    if ($text === '') return;
    ad_schema();
    nc_db()->prepare('INSERT OR REPLACE INTO adult_req_limits (req_id, text) VALUES (?, ?)')->execute([$req, $text]);
}
function ad_req_limits_get(array $ids): array {
    if (!$ids) return [];
    ad_schema();
    $st = nc_db()->prepare('SELECT req_id, text FROM adult_req_limits WHERE req_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'); $st->execute(array_map('intval', $ids));
    return $st->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Aktionen (über cl_action → cl2_action → hier) */
function ad_action(array $who, string $a, array $in): ?array {
    ad_schema();
    switch ($a) {
        case 'door': return ad_door($who, (int)($in['id'] ?? 0));
        case 'door_check': return ad_door_check($who, (int)($in['id'] ?? 0), (string)($in['target'] ?? ''), !isset($in['on']) || !empty($in['on']));
        case 'couple_list': return ad_couple_list($who, $in);
        case 'couple_invite': return ad_couple_invite($who, (string)($in['partner'] ?? ''), $in);
        case 'couple_answer': return ad_couple_answer($who, !empty($in['yes']));
        case 'couple_edit': return ad_couple_edit($who, $in);
        case 'couple_end': return ad_couple_end($who);
        case 'tpl_list': return ad_tpl_list($who, $in);
        case 'tpl_get': return ad_tpl_get($who, (int)($in['id'] ?? 0), !empty($in['use']));
        case 'tpl_save': return ad_tpl_save($who, $in);
        case 'tpl_rate': return ad_tpl_rate($who, (int)($in['id'] ?? 0), (int)($in['stars'] ?? 0));
        case 'tpl_del': return ad_tpl_del($who, (int)($in['id'] ?? 0));
        case 'tpl_queue': return ad_tpl_queue($who, isset($in['id']) ? (int)$in['id'] : null, !empty($in['ok']));
    }
    return null;
}
