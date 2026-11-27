<?php
declare(strict_types=1);
/**
 * 7.2 Clubs 2.0 – baut auf clubs.php auf:
 *   Moderation 2.0: Wortfilter je Club (+ Webseiten-Liste) → Beiträge landen in der Warteschlange; Sperren mit Ablauf und Grund; Einspruch der Gesperrten
 *   Tickets: Mitglieder (und Interessierte) schreiben der Club-Leitung, Verlauf mit Antworten, offen/erledigt
 *   Partnerschaften zwischen Clubs (Anfrage → Bestätigung durch die andere Leitung), Partner-Liste öffentlich
 *   Einladungslinks: Code mit Ablauf/Höchstzahl – damit kommt man auch in Clubs „nur mit Einladung“ / „mit Bewerbung“
 *   Statistik für die Leitung: Mitglieder-Verlauf, Beiträge, Events, Anmeldungen, aktivste Mitglieder
 *   Discord-Abgleich: öffentliche JSON-Liste kommender Events je Club (für den GhostNode-Bot)
 */

function cl2_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (setting('clubs_schema2', '0') === '1') return;
    $db = nc_db();
    cl_schema();
    $db->exec("
        CREATE TABLE IF NOT EXISTS club_bans (club_id INTEGER NOT NULL, vrc_id TEXT NOT NULL, until INTEGER NOT NULL DEFAULT 0, reason TEXT NOT NULL DEFAULT '', by_id TEXT NOT NULL DEFAULT '',
            appeal TEXT NOT NULL DEFAULT '', appeal_status TEXT NOT NULL DEFAULT '', appeal_at INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL, PRIMARY KEY (club_id, vrc_id));
        CREATE TABLE IF NOT EXISTS club_tickets (id INTEGER PRIMARY KEY AUTOINCREMENT, club_id INTEGER NOT NULL, author TEXT NOT NULL, subject TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'open', created INTEGER NOT NULL, updated INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS club_ticket_msgs (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER NOT NULL, author TEXT NOT NULL, staff INTEGER NOT NULL DEFAULT 0, text TEXT NOT NULL, created INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS club_partners (club_id INTEGER NOT NULL, partner_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'pending', note TEXT NOT NULL DEFAULT '', created INTEGER NOT NULL, PRIMARY KEY (club_id, partner_id));
        CREATE TABLE IF NOT EXISTS club_invites (code TEXT PRIMARY KEY, club_id INTEGER NOT NULL, created_by TEXT NOT NULL, uses INTEGER NOT NULL DEFAULT 0, max_uses INTEGER NOT NULL DEFAULT 0, expires INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL);
        CREATE INDEX IF NOT EXISTS idx_club_tickets ON club_tickets(club_id, status);
    ");
    foreach (["ALTER TABLE club_posts ADD COLUMN status TEXT NOT NULL DEFAULT 'ok'", "ALTER TABLE clubs ADD COLUMN wordfilter TEXT NOT NULL DEFAULT ''"] as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) { /* Spalte gibt es schon */ }
    }
    set_setting('clubs_schema2', '1');
}

// ---------- Moderation 2.0 ----------
/** Wörter aus dem Club-Filter und der Webseiten-Liste (Admin-Einstellung „community_wordfilter“) */
function cl2_words(array $c): array {
    $raw = (string)($c['wordfilter'] ?? '') . ',' . (string)setting('community_wordfilter', '');
    return array_values(array_unique(array_filter(array_map(fn($w) => mb_strtolower(trim($w)), explode(',', $raw)), fn($w) => mb_strlen($w) >= 2)));
}
/** true = Text enthält ein gefiltertes Wort (auch mit Leerzeichen/Punkten dazwischen, z. B. „b.l.a“) */
function cl2_filtered(array $c, string $text): bool {
    $t = mb_strtolower($text); $squash = preg_replace('/[\s._*\-]+/u', '', $t);
    foreach (cl2_words($c) as $w) { if (str_contains($t, $w) || str_contains($squash, preg_replace('/\s+/u', '', $w))) return true; }
    return false;
}
/** aktive Sperre (0 = unbegrenzt) oder null */
function cl2_ban(int $cid, string $vid): ?array {
    cl2_schema();
    $st = nc_db()->prepare('SELECT * FROM club_bans WHERE club_id = ? AND vrc_id = ?'); $st->execute([$cid, $vid]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) return null;
    if ((int)$b['until'] > 0 && (int)$b['until'] <= time()) { nc_db()->prepare('DELETE FROM club_bans WHERE club_id = ? AND vrc_id = ?')->execute([$cid, $vid]); return null; }   // abgelaufen
    return $b;
}
function cl2_ban_set(array $who, array $c, string $target, int $hours, string $reason): array {
    cl_need_staff($c, $who);
    if (!nx_uid_ok($target)) throw new NcError('Unbekanntes Konto.');
    if ($target === $c['owner']) throw new NcError('Die Besitzer:in kann man nicht sperren.');
    $m = cl_member((int)$c['id'], $target);
    if ($m && $m['role'] === 'mod' && $c['owner'] !== $who['vrc_id'] && empty($who['_mod'])) throw new NcError('Nur die Besitzer:in kann Moderator:innen sperren.');
    $until = $hours > 0 ? time() + min(24 * 365, $hours) * 3600 : 0;
    $db = nc_db();
    $db->prepare('DELETE FROM club_members WHERE club_id = ? AND vrc_id = ?')->execute([$c['id'], $target]);
    $db->prepare('DELETE FROM club_bans WHERE club_id = ? AND vrc_id = ?')->execute([$c['id'], $target]);
    $db->prepare('INSERT INTO club_bans (club_id, vrc_id, until, reason, by_id, created) VALUES (?, ?, ?, ?, ?, ?)')->execute([$c['id'], $target, $until, nc_cut($reason, 300), $who['vrc_id'], time()]);
    return ['msg' => $until ? '⏳ Gesperrt bis ' . date('d.m.Y H:i', $until) . '.' : '🚫 Dauerhaft gesperrt.'];
}
function cl2_unban(array $who, array $c, string $target): array {
    cl_need_staff($c, $who);
    nc_db()->prepare('DELETE FROM club_bans WHERE club_id = ? AND vrc_id = ?')->execute([$c['id'], $target]);
    return ['msg' => 'Sperre aufgehoben.'];
}
/** Gesperrte schreiben einmal einen Einspruch; die Leitung nimmt an (Sperre weg) oder lehnt ab */
function cl2_appeal(array $who, array $c, string $text): array {
    $b = cl2_ban((int)$c['id'], $who['vrc_id']);
    if (!$b) throw new NcError('Du bist in diesem Club nicht gesperrt.');
    if ($b['appeal_status'] !== '') throw new NcError($b['appeal_status'] === 'open' ? 'Dein Einspruch wird schon geprüft.' : 'Über deinen Einspruch wurde schon entschieden.');
    $t = nc_cut($text, 1000); if (mb_strlen($t) < 10) throw new NcError('Bitte begründe den Einspruch (mindestens 10 Zeichen).');
    nc_db()->prepare("UPDATE club_bans SET appeal = ?, appeal_status = 'open', appeal_at = ? WHERE club_id = ? AND vrc_id = ?")->execute([$t, time(), $c['id'], $who['vrc_id']]);
    return ['msg' => '📨 Einspruch gesendet.'];
}
function cl2_appeal_decide(array $who, array $c, string $target, bool $accept): array {
    cl_need_staff($c, $who);
    $b = cl2_ban((int)$c['id'], $target);
    if (!$b || $b['appeal_status'] !== 'open') throw new NcError('Kein offener Einspruch.');
    if ($accept) { nc_db()->prepare('DELETE FROM club_bans WHERE club_id = ? AND vrc_id = ?')->execute([$c['id'], $target]); return ['msg' => '✅ Einspruch angenommen – Sperre aufgehoben.']; }
    nc_db()->prepare("UPDATE club_bans SET appeal_status = 'declined' WHERE club_id = ? AND vrc_id = ?")->execute([$c['id'], $target]);
    return ['msg' => 'Einspruch abgelehnt.'];
}
function cl2_queue(array $who, array $c): array {
    cl_need_staff($c, $who);
    $db = nc_db();
    $p = $db->prepare("SELECT * FROM club_posts WHERE club_id = ? AND status = 'pending' ORDER BY id"); $p->execute([$c['id']]); $posts = $p->fetchAll(PDO::FETCH_ASSOC);
    $b = $db->prepare('SELECT * FROM club_bans WHERE club_id = ? ORDER BY created DESC LIMIT 200'); $b->execute([$c['id']]); $bans = array_values(array_filter($b->fetchAll(PDO::FETCH_ASSOC), fn($x) => (int)$x['until'] === 0 || (int)$x['until'] > time()));
    $names = nc_names(array_merge(array_column($posts, 'author'), array_column($bans, 'vrc_id')));
    return ['posts' => array_map(fn($x) => ['id' => (int)$x['id'], 'author' => $x['author'], 'name' => (string)($names[$x['author']] ?? ''), 'text' => $x['text'], 'created' => (int)$x['created']], $posts),
        'bans' => array_map(fn($x) => ['vrc_id' => $x['vrc_id'], 'name' => (string)($names[$x['vrc_id']] ?? ''), 'until' => (int)$x['until'], 'reason' => $x['reason'], 'appeal' => $x['appeal'], 'appeal_status' => $x['appeal_status']], $bans),
        'wordfilter' => (string)($c['wordfilter'] ?? '')];
}
function cl2_queue_decide(array $who, array $c, int $id, bool $ok): array {
    cl_need_staff($c, $who);
    $db = nc_db();
    if ($ok) $db->prepare("UPDATE club_posts SET status = 'ok' WHERE id = ? AND club_id = ?")->execute([$id, $c['id']]);
    else $db->prepare("DELETE FROM club_posts WHERE id = ? AND club_id = ? AND status = 'pending'")->execute([$id, $c['id']]);
    return ['msg' => $ok ? 'Freigegeben.' : 'Gelöscht.'];
}
function cl2_wordfilter_save(array $who, array $c, string $words): array {
    cl_need_staff($c, $who);
    $w = implode(', ', array_slice(array_values(array_unique(array_filter(array_map(fn($x) => nc_cut($x, 40), explode(',', str_replace("\n", ',', $words))), fn($x) => mb_strlen($x) >= 2))), 0, 200));
    nc_db()->prepare('UPDATE clubs SET wordfilter = ? WHERE id = ?')->execute([$w, $c['id']]);
    return ['msg' => 'Wortfilter gespeichert.'];
}

// ---------- Tickets ----------
function cl2_ticket_new(array $who, array $c, string $subject, string $text): array {
    cl_need_ok($who);
    if (nw_rate_over('clt|' . $who['vrc_id'], 10)) throw new NcError('Zu viele Tickets – kurz warten.');
    $s = nc_cut($subject, 100); $t = nc_cut($text, 2000);
    if (mb_strlen($s) < 3 || mb_strlen($t) < 3) throw new NcError('Betreff und Nachricht ausfüllen.');
    $db = nc_db();
    $open = $db->prepare("SELECT COUNT(*) FROM club_tickets WHERE club_id = ? AND author = ? AND status = 'open'"); $open->execute([$c['id'], $who['vrc_id']]);
    if ((int)$open->fetchColumn() >= 3) throw new NcError('Du hast schon 3 offene Tickets bei diesem Club.');
    $db->prepare('INSERT INTO club_tickets (club_id, author, subject, status, created, updated) VALUES (?, ?, ?, \'open\', ?, ?)')->execute([$c['id'], $who['vrc_id'], $s, time(), time()]);
    $id = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO club_ticket_msgs (ticket_id, author, staff, text, created) VALUES (?, ?, 0, ?, ?)')->execute([$id, $who['vrc_id'], $t, time()]);
    return ['msg' => '🎫 Ticket gesendet – die Club-Leitung antwortet hier.', 'id' => $id];
}
function cl2_ticket_row(array $c, int $id): array {
    $st = nc_db()->prepare('SELECT * FROM club_tickets WHERE id = ? AND club_id = ?'); $st->execute([$id, $c['id']]);
    $t = $st->fetch(PDO::FETCH_ASSOC); if (!$t) throw new NcError('Ticket nicht gefunden.'); return $t;
}
function cl2_tickets(array $who, array $c): array {
    $staff = cl_is_staff($c, $who);
    $sql = 'SELECT * FROM club_tickets WHERE club_id = ?' . ($staff ? '' : ' AND author = ?') . " ORDER BY CASE status WHEN 'open' THEN 0 ELSE 1 END, updated DESC LIMIT 100";
    $st = nc_db()->prepare($sql); $st->execute($staff ? [$c['id']] : [$c['id'], $who['vrc_id']]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC); $names = nc_names(array_column($rows, 'author'));
    return ['staff' => $staff, 'items' => array_map(fn($r) => ['id' => (int)$r['id'], 'subject' => $r['subject'], 'status' => $r['status'], 'author' => $r['author'], 'name' => (string)($names[$r['author']] ?? ''), 'updated' => (int)$r['updated']], $rows)];
}
function cl2_ticket_view(array $who, array $c, int $id): array {
    $t = cl2_ticket_row($c, $id);
    if ($t['author'] !== $who['vrc_id'] && !cl_is_staff($c, $who)) throw new NcError('Nicht dein Ticket.');
    $m = nc_db()->prepare('SELECT * FROM club_ticket_msgs WHERE ticket_id = ? ORDER BY id'); $m->execute([$id]); $msgs = $m->fetchAll(PDO::FETCH_ASSOC);
    $names = nc_names(array_column($msgs, 'author'));
    return ['ticket' => ['id' => (int)$t['id'], 'subject' => $t['subject'], 'status' => $t['status'], 'created' => (int)$t['created']],
        'msgs' => array_map(fn($x) => ['name' => (string)($names[$x['author']] ?? ''), 'staff' => (bool)$x['staff'], 'text' => $x['text'], 'created' => (int)$x['created'], 'own' => $x['author'] === $who['vrc_id']], $msgs)];
}
function cl2_ticket_reply(array $who, array $c, int $id, string $text, string $status): array {
    $t = cl2_ticket_row($c, $id); $staff = cl_is_staff($c, $who);
    if ($t['author'] !== $who['vrc_id'] && !$staff) throw new NcError('Nicht dein Ticket.');
    $db = nc_db(); $tx = nc_cut($text, 2000);
    if ($tx !== '') $db->prepare('INSERT INTO club_ticket_msgs (ticket_id, author, staff, text, created) VALUES (?, ?, ?, ?, ?)')->execute([$id, $who['vrc_id'], $staff && $t['author'] !== $who['vrc_id'] ? 1 : 0, $tx, time()]);
    $st = in_array($status, ['open', 'closed'], true) ? $status : ($tx !== '' && !$staff ? 'open' : $t['status']);
    $db->prepare('UPDATE club_tickets SET status = ?, updated = ? WHERE id = ?')->execute([$st, time(), $id]);
    return ['msg' => $st === 'closed' ? '✅ Ticket erledigt.' : 'Gesendet.'];
}

// ---------- Partnerschaften ----------
function cl2_partners(array $c): array {
    $st = nc_db()->prepare("SELECT p.club_id, p.partner_id, p.status, c1.slug AS s1, c1.name AS n1, c2.slug AS s2, c2.name AS n2 FROM club_partners p JOIN clubs c1 ON c1.id = p.club_id JOIN clubs c2 ON c2.id = p.partner_id
        WHERE (p.club_id = ? OR p.partner_id = ?) AND c1.status = 'active' AND c2.status = 'active'");
    $st->execute([$c['id'], $c['id']]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $mine = (int)$r['club_id'] === (int)$c['id'];
        $out[] = ['slug' => $mine ? $r['s2'] : $r['s1'], 'name' => $mine ? $r['n2'] : $r['n1'], 'status' => $r['status'], 'incoming' => !$mine && $r['status'] === 'pending', 'outgoing' => $mine && $r['status'] === 'pending'];
    }
    return $out;
}
function cl2_partner_request(array $who, array $c, string $other): array {
    cl_need_staff($c, $who);
    $o = cl_get($other);
    if ((int)$o['id'] === (int)$c['id']) throw new NcError('Das ist dein eigener Club.');
    $db = nc_db();
    $ex = $db->prepare('SELECT * FROM club_partners WHERE (club_id = ? AND partner_id = ?) OR (club_id = ? AND partner_id = ?)'); $ex->execute([$c['id'], $o['id'], $o['id'], $c['id']]);
    $r = $ex->fetch(PDO::FETCH_ASSOC);
    if ($r && $r['status'] === 'active') return ['msg' => 'Ihr seid schon Partner.'];
    if ($r && (int)$r['club_id'] === (int)$o['id']) {   // die andere Seite hat schon gefragt → annehmen
        $db->prepare("UPDATE club_partners SET status = 'active' WHERE club_id = ? AND partner_id = ?")->execute([$o['id'], $c['id']]);
        return ['msg' => '🤝 Partnerschaft mit „' . $o['name'] . '“ besteht jetzt.'];
    }
    if (!$r) $db->prepare("INSERT INTO club_partners (club_id, partner_id, status, created) VALUES (?, ?, 'pending', ?)")->execute([$c['id'], $o['id'], time()]);
    return ['msg' => '📨 Anfrage an „' . $o['name'] . '“ gesendet.'];
}
function cl2_partner_answer(array $who, array $c, string $other, bool $accept): array {
    cl_need_staff($c, $who);
    $o = cl_get($other); $db = nc_db();
    if ($accept) $db->prepare("UPDATE club_partners SET status = 'active' WHERE club_id = ? AND partner_id = ? AND status = 'pending'")->execute([$o['id'], $c['id']]);
    else $db->prepare('DELETE FROM club_partners WHERE (club_id = ? AND partner_id = ?) OR (club_id = ? AND partner_id = ?)')->execute([$o['id'], $c['id'], $c['id'], $o['id']]);
    return ['msg' => $accept ? '🤝 Partnerschaft angenommen.' : 'Partnerschaft beendet.'];
}

// ---------- Einladungslinks ----------
function cl2_invite_new(array $who, array $c, int $hours, int $max): array {
    cl_need_staff($c, $who);
    $code = substr(strtr(base64_encode(random_bytes(9)), '+/', 'ab'), 0, 12);
    nc_db()->prepare('INSERT INTO club_invites (code, club_id, created_by, uses, max_uses, expires, created) VALUES (?, ?, ?, 0, ?, ?, ?)')
        ->execute([$code, $c['id'], $who['vrc_id'], max(0, min(1000, $max)), $hours > 0 ? time() + min(24 * 90, $hours) * 3600 : 0, time()]);
    return ['msg' => '🔗 Einladungslink erstellt.', 'code' => $code];
}
function cl2_invites(array $who, array $c): array {
    cl_need_staff($c, $who);
    $st = nc_db()->prepare('SELECT * FROM club_invites WHERE club_id = ? ORDER BY created DESC LIMIT 50'); $st->execute([$c['id']]);
    return array_map(fn($r) => ['code' => $r['code'], 'uses' => (int)$r['uses'], 'max' => (int)$r['max_uses'], 'expires' => (int)$r['expires'],
        'valid' => ((int)$r['expires'] === 0 || (int)$r['expires'] > time()) && ((int)$r['max_uses'] === 0 || (int)$r['uses'] < (int)$r['max_uses'])], $st->fetchAll(PDO::FETCH_ASSOC));
}
function cl2_invite_delete(array $who, array $c, string $code): array { cl_need_staff($c, $who); nc_db()->prepare('DELETE FROM club_invites WHERE club_id = ? AND code = ?')->execute([$c['id'], $code]); return ['msg' => 'Link gelöscht.']; }
/** gültigen Code einlösen (zählt mit) – true, wenn der Code passt */
function cl2_invite_use(array $c, string $code): bool {
    if (!preg_match('/^[A-Za-z0-9]{12}$/', $code)) return false;
    cl2_schema();
    $db = nc_db();
    $st = $db->prepare('SELECT * FROM club_invites WHERE code = ? AND club_id = ?'); $st->execute([$code, $c['id']]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || ((int)$r['expires'] > 0 && (int)$r['expires'] < time()) || ((int)$r['max_uses'] > 0 && (int)$r['uses'] >= (int)$r['max_uses'])) return false;
    $db->prepare('UPDATE club_invites SET uses = uses + 1 WHERE code = ?')->execute([$code]);
    return true;
}

// ---------- Statistik ----------
function cl2_stats(array $who, array $c): array {
    cl_need_staff($c, $who);
    $db = nc_db(); $id = (int)$c['id']; $now = time();
    $j = $db->prepare("SELECT joined FROM club_members WHERE club_id = ? AND status = 'active'"); $j->execute([$id]); $joined = array_map('intval', $j->fetchAll(PDO::FETCH_COLUMN));
    $weeks = [];
    for ($w = 11; $w >= 0; $w--) { $end = $now - $w * 7 * 86400; $weeks[] = ['end' => $end, 'members' => count(array_filter($joined, fn($t) => $t <= $end))]; }
    $q = function (string $sql, array $a) use ($db) { $s = $db->prepare($sql); $s->execute($a); return $s->fetchColumn(); };
    $posts30 = (int)$q("SELECT COUNT(*) FROM club_posts WHERE club_id = ? AND created > ? AND status = 'ok'", [$id, $now - 30 * 86400]);
    $ev = $db->prepare("SELECT id FROM club_events WHERE club_id = ? AND ends < ? AND status = 'active' ORDER BY starts DESC LIMIT 20"); $ev->execute([$id, $now]); $past = $ev->fetchAll(PDO::FETCH_COLUMN);
    $att = []; foreach ($past as $e) $att[] = cl_counts((int)$e)['going'];
    $top = $db->prepare("SELECT author, COUNT(*) AS n FROM club_posts WHERE club_id = ? AND created > ? GROUP BY author ORDER BY n DESC LIMIT 5"); $top->execute([$id, $now - 30 * 86400]);
    $tops = $top->fetchAll(PDO::FETCH_ASSOC); $names = nc_names(array_column($tops, 'author'));
    return ['members' => count($joined), 'new_30' => count(array_filter($joined, fn($t) => $t > $now - 30 * 86400)), 'weeks' => $weeks, 'posts_30' => $posts30,
        'events_past' => count($past), 'events_next' => (int)$q("SELECT COUNT(*) FROM club_events WHERE club_id = ? AND ends >= ? AND status = 'active'", [$id, $now]),
        'avg_going' => $att ? round(array_sum($att) / count($att), 1) : 0, 'tickets_open' => (int)$q("SELECT COUNT(*) FROM club_tickets WHERE club_id = ? AND status = 'open'", [$id]),
        'pending' => (int)$q("SELECT COUNT(*) FROM club_members WHERE club_id = ? AND status = 'pending'", [$id]),
        'top' => array_map(fn($r) => ['name' => (string)($names[$r['author']] ?? $r['author']), 'n' => (int)$r['n']], $tops)];
}

// ---------- Discord-Abgleich (öffentlich, nur gelistete Clubs, keine „nur Mitglieder“-Events) ----------
function cl2_discord_feed(string $slug): array {
    $c = cl_get($slug);
    if (!$c['listed']) throw new NcError('Club nicht gefunden.');
    $st = nc_db()->prepare("SELECT * FROM club_events WHERE club_id = ? AND members_only = 0 AND ends >= ? ORDER BY starts LIMIT 50"); $st->execute([$c['id'], time() - 3600]);
    $base = 'https://' . preg_replace('/[^a-z0-9.\-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'ghostnode-labs.dev'));
    return ['club' => ['slug' => $c['slug'], 'name' => $c['name'], 'url' => $base . '/nexus.php?club=' . rawurlencode($c['slug'])],
        'events' => array_map(fn($e) => ['id' => (int)$e['id'], 'title' => $e['title'], 'descr' => $e['descr'], 'starts' => (int)$e['starts'], 'ends' => (int)$e['ends'], 'place' => $e['place'],
            'status' => $e['status'], 'capacity' => (int)$e['capacity'], 'going' => cl_counts((int)$e['id'])['going']], $st->fetchAll(PDO::FETCH_ASSOC))];
}

/** Aktionen 7.2 (von cl_action weitergereicht) */
function cl2_action(array $who, string $a, array $in): array {
    cl2_schema();
    $k = (string)($in['club'] ?? '');
    $c = fn() => cl_get($k);
    switch ($a) {
        case 'ban': return cl2_ban_set($who, $c(), (string)($in['target'] ?? ''), (int)($in['hours'] ?? 0), (string)($in['reason'] ?? ''));
        case 'unban': return cl2_unban($who, $c(), (string)($in['target'] ?? ''));
        case 'appeal': return cl2_appeal($who, $c(), (string)($in['text'] ?? ''));
        case 'appeal_decide': return cl2_appeal_decide($who, $c(), (string)($in['target'] ?? ''), !empty($in['accept']));
        case 'queue': return cl2_queue($who, $c());
        case 'queue_decide': return cl2_queue_decide($who, $c(), (int)($in['id'] ?? 0), !empty($in['ok']));
        case 'wordfilter': return cl2_wordfilter_save($who, $c(), (string)($in['words'] ?? ''));
        case 'ticket_new': return cl2_ticket_new($who, $c(), (string)($in['subject'] ?? ''), (string)($in['text'] ?? ''));
        case 'tickets': return cl2_tickets($who, $c());
        case 'ticket_view': return cl2_ticket_view($who, $c(), (int)($in['id'] ?? 0));
        case 'ticket_reply': return cl2_ticket_reply($who, $c(), (int)($in['id'] ?? 0), (string)($in['text'] ?? ''), (string)($in['status'] ?? ''));
        case 'partners': return ['items' => cl2_partners($c())];
        case 'partner_request': return cl2_partner_request($who, $c(), (string)($in['other'] ?? ''));
        case 'partner_answer': return cl2_partner_answer($who, $c(), (string)($in['other'] ?? ''), !empty($in['accept']));
        case 'invite_new': return cl2_invite_new($who, $c(), (int)($in['hours'] ?? 0), (int)($in['max'] ?? 0));
        case 'invites': return ['items' => cl2_invites($who, $c())];
        case 'invite_delete': return cl2_invite_delete($who, $c(), (string)($in['code'] ?? ''));
        case 'stats': return cl2_stats($who, $c());
    }
    throw new NcError('Unbekannte Aktion.');
}
