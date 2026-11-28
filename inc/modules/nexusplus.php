<?php
declare(strict_types=1);
/**
 * 8.2 Web-Nexus – neue Bereiche im Browser (Aktionen nxp_* aus nexus.php):
 *   • 🔋 Geräte-Übersicht: Uhr, Tracker, Controller … mit Akku (vom Desktop-Client gemeldet), Client-PCs, angemeldete Nexus-Geräte
 *   • 💓 Puls-Verlauf: Pulswerte vom Desktop-Client (client.php state.heart bzw. a=heart), Tag/Woche/Monat, Zonen, CSV
 *   • 📆 Wochenrückblick: Spielzeit, Welten, Leute, Screenshots, Puls, Club-Events – mit Vergleich zur Vorwoche
 *   • 🗺 Welt-Wunschliste: „will ich besuchen“ mit Notiz, Priorität, Schlagwort; wird automatisch abgehakt, sobald der Client den Besuch meldet
 *   • 🏅 Profil-Abzeichen & Vollständigkeit (Community-Profil), 🔔 Push für zugesagte Club-Events und schwachen Akku (nexus_push.php)
 *   • 🔞 Spielzeug im Browser: eigene Spielzeuge und Partner-Links (Ablaufzeit, Intensitäts-Obergrenze, erlaubte Muster, Consent vor dem
 *     ersten Befehl, Not-Stopp, Protokoll nur für die Beteiligten). Befehle gehen über denselben Briefkasten wie toyrelay.php (toybox.php)
 *     im Format der App (v1). Die App prüft zusätzlich wie immer selbst (Freigabeliste, Höchststärke, Verified 18+ des Absenders).
 * NSFW wird bei JEDER nxp_toy_*-Anfrage serverseitig geprüft (nc_need_nsfw → Verified 18+ und NSFW-Schalter), beim Partner-Link
 * zusätzlich das Konto der Besitzerin/des Besitzers. Alles läuft über nc_db() und damit auch unter MySQL.
 */
require_once __DIR__ . '/nexusweb.php';
require_once __DIR__ . '/nexus_client.php';
require_once __DIR__ . '/toybox.php';

const NXP_HEART_GAP = 20;                 // höchstens ein Pulswert je 20 s
const NXP_HEART_KEEP = 30 * 86400;        // Puls 30 Tage aufbewahren
const NXP_HEART_RANGES = ['day' => [86400, 300], 'week' => [7 * 86400, 3600], 'month' => [30 * 86400, 6 * 3600]];   // Zeitraum, Balkenbreite
const NXP_HEART_ZONES = [['ruhig', 0, 90], ['leicht', 90, 110], ['aktiv', 110, 135], ['hoch', 135, 999]];
const NXP_DEV_KINDS = ['watch' => '⌚ Uhr', 'phone' => '📱 Handy', 'tracker' => '📍 Tracker', 'controller' => '🎮 Controller', 'headset' => '🥽 Headset',
                       'heart' => '💓 Pulsmesser', 'toy' => '🔞 Spielzeug', 'other' => '🔌 Gerät'];
const NXP_BATT_LOW = 20;
const NXP_WISH_MAX = 300;
// Muster der App (backend/toys.js) – nur die Kennungen; die App kennt die Kurven selbst
const NXP_TOY_PATTERNS = ['welle' => '🌊 Welle', 'puls' => '💥 Puls', 'herz' => '💓 Herzschlag', 'treppe' => '📶 Treppe', 'zufall' => '🎲 Zufall', 'gewitter' => '⛈ Gewitter',
    'necken' => '😈 Necken', 'achterbahn' => '🎢 Achterbahn', 'atem' => '🫁 Atem', 'saege' => '🪚 Sägezahn', 'klopfen' => '🚪 Klopfen', 'crescendo' => '📈 Crescendo',
    'sos' => '🆘 Morse', 'kante' => '🎯 Kantenritt', 'hoehepunkt' => '💥 Höhepunkt'];
const NXP_TOY_CMDS = ['ping', 'set', 'pattern', 'surprise', 'stop'];
const NXP_TOY_RATE = 90;                  // Befehle je Konto und Minute (Regler-Lebenszeichen alle 5 s + Bedienung)
const NXP_LINK_MAX = 10;                  // gleichzeitig offene Partner-Links je Besitzer:in
const NXP_LOG_KEEP = 30 * 86400;

// =====================================================================
// 💓 Puls
// =====================================================================
/** Pulswert speichern (vom Desktop-Client) → true, wenn gespeichert */
function nxp_heart_store(int $uid, int $bpm, ?int $ts = null): bool {
    $ts = $ts ?? time();
    if ($bpm < 25 || $bpm > 250 || $ts > time() + 60 || $ts < time() - NXP_HEART_KEEP) return false;
    $db = vrc_db();
    $st = $db->prepare('SELECT COUNT(*) FROM web_heart WHERE user_id = ? AND ts > ? AND ts < ?');
    $st->execute([$uid, $ts - NXP_HEART_GAP, $ts + NXP_HEART_GAP]);
    if ((int)$st->fetchColumn() > 0) return false;
    $db->prepare('INSERT OR IGNORE INTO web_heart (user_id, ts, bpm) VALUES (?, ?, ?)')->execute([$uid, $ts, $bpm]);
    if (mt_rand(1, 200) === 1) $db->prepare('DELETE FROM web_heart WHERE ts < ?')->execute([time() - NXP_HEART_KEEP]);
    return true;
}
/** mehrere Werte auf einmal (gepuffert) → Anzahl gespeicherter */
function nxp_heart_batch(int $uid, array $samples): int {
    $n = 0;
    usort($samples, fn($a, $b) => (int)($a['ts'] ?? 0) <=> (int)($b['ts'] ?? 0));
    foreach (array_slice($samples, -2000) as $s) if (is_array($s) && nxp_heart_store($uid, (int)($s['bpm'] ?? 0), (int)($s['ts'] ?? 0) ?: null)) $n++;
    return $n;
}
function nxp_heart(int $uid, string $range): array {
    [$span, $bucket] = NXP_HEART_RANGES[$range] ?? NXP_HEART_RANGES['day'];
    $from = time() - $span;
    $db = vrc_db();
    $st = $db->prepare('SELECT ts - (ts % ' . $bucket . ') AS b, MIN(bpm) AS lo, MAX(bpm) AS hi, AVG(bpm) AS av, COUNT(*) AS n FROM web_heart WHERE user_id = ? AND ts >= ? GROUP BY ts - (ts % ' . $bucket . ') ORDER BY b');
    $st->execute([$uid, $from]);
    $points = array_map(fn($r) => ['t' => (int)$r['b'], 'lo' => (int)$r['lo'], 'hi' => (int)$r['hi'], 'avg' => (int)round((float)$r['av']), 'n' => (int)$r['n']], $st->fetchAll(PDO::FETCH_ASSOC));
    $all = $db->prepare('SELECT bpm FROM web_heart WHERE user_id = ? AND ts >= ? ORDER BY bpm');
    $all->execute([$uid, $from]);
    $v = array_map('intval', $all->fetchAll(PDO::FETCH_COLUMN));
    $cnt = count($v);
    $zones = [];
    foreach (NXP_HEART_ZONES as [$name, $lo, $hi]) $zones[] = ['name' => $name, 'from' => $lo, 'to' => $hi, 'share' => $cnt ? (int)round(count(array_filter($v, fn($x) => $x >= $lo && $x < $hi)) / $cnt * 100) : 0];
    $state = nc_state($uid);
    $cur = $state && !empty($state['fresh']) && !empty($state['heart']) ? (int)$state['heart'] : 0;
    return ['range' => isset(NXP_HEART_RANGES[$range]) ? $range : 'day', 'bucket' => $bucket, 'from' => $from, 'points' => $points, 'current' => $cur,
        'stats' => $cnt ? ['min' => $v[0], 'max' => $v[$cnt - 1], 'avg' => (int)round(array_sum($v) / $cnt), 'rest' => $v[(int)floor(($cnt - 1) * 0.1)], 'samples' => $cnt] : null,
        'zones' => $zones];
}
function nxp_heart_csv(int $uid): void {
    $st = vrc_db()->prepare('SELECT ts, bpm FROM web_heart WHERE user_id = ? ORDER BY ts');
    $st->execute([$uid]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="puls-verlauf.csv"');
    echo "zeit;bpm\n";
    foreach ($st as $r) echo date('Y-m-d H:i:s', (int)$r['ts']) . ';' . (int)$r['bpm'] . "\n";
}

// =====================================================================
// 🔋 Geräte
// =====================================================================
/** Geräteliste aus dem Client-Zustand säubern (für nc_state_save) */
function nxp_devices_clean($list): array {
    $out = [];
    foreach (array_slice(is_array($list) ? $list : [], 0, 24) as $d) {
        if (!is_array($d)) continue;
        $kind = isset(NXP_DEV_KINDS[$d['kind'] ?? '']) ? (string)$d['kind'] : 'other';
        $b = $d['battery'] ?? null;
        $out[] = ['kind' => $kind, 'name' => mb_substr(trim((string)($d['name'] ?? '')), 0, 48) ?: NXP_DEV_KINDS[$kind], 'battery' => is_numeric($b) ? max(0, min(100, (int)$b)) : null,
                  'charging' => !empty($d['charging']), 'connected' => !array_key_exists('connected', $d) || !empty($d['connected']), 'via' => mb_substr((string)($d['via'] ?? ''), 0, 24)];
    }
    return $out;
}
function nxp_devices(int $uid, array $who): array {
    $s = nc_state($uid);
    $list = array_values(array_filter((array)($s['devices'] ?? []), fn($d) => ($d['kind'] ?? '') !== 'toy' || !empty($who['_nsfw'])));   // Spielzeuge nur mit 18+-Freigabe
    foreach ($list as &$d) { $d['label'] = NXP_DEV_KINDS[$d['kind']] ?? NXP_DEV_KINDS['other']; $d['low'] = $d['battery'] !== null && $d['battery'] < NXP_BATT_LOW && empty($d['charging']); }
    unset($d);
    return ['state' => $s ? ['fresh' => !empty($s['fresh']), 'updated' => (int)$s['updated'], 'running' => !empty($s['running']), 'world' => (string)($s['world_name'] ?? ''),
                             'heart' => !empty($s['fresh']) ? (int)($s['heart'] ?? 0) : 0, 'heart_src' => (string)($s['heart_src'] ?? '')] : null,
            'devices' => $list, 'clients' => nc_tokens($uid), 'nexus' => nx_devices($who), 'low' => NXP_BATT_LOW];
}

// =====================================================================
// 📆 Wochenrückblick
// =====================================================================
function nxp_week_range(int $back): array {
    $mon = strtotime('monday this week', time());
    if ((int)date('N') === 1) $mon = strtotime('today');
    $from = strtotime('-' . max(0, min(52, $back)) . ' week', $mon);
    return [$from, strtotime('+1 week', $from)];
}
/** Kennzahlen eines Zeitraums aus dem Spiel-Log (Client) */
function nxp_period(int $uid, int $from, int $to): array {
    $db = vrc_db();
    $st = $db->prepare("SELECT ts, kind, world_id, world_name, player_id, player_name FROM game_log WHERE user_id = ? AND ts >= ? AND ts < ? ORDER BY ts, id");
    $st->execute([$uid, $from - 43200, $to]);
    $worlds = []; $people = []; $days = []; $shots = 0; $avatars = 0; $play = 0; $cur = null;
    $close = function (int $t) use (&$cur, &$worlds, &$days, &$play, $from, $to) {
        if (!$cur) return;
        $a = max($cur['start'], $from); $b = min($t, $to, $cur['start'] + 43200);
        if ($b > $a) {
            $sec = $b - $a; $play += $sec;
            $k = $cur['id'] ?: 'n:' . $cur['name'];
            $worlds[$k] = ['id' => $cur['id'], 'name' => $cur['name'] ?: $cur['id'], 'seconds' => ($worlds[$k]['seconds'] ?? 0) + $sec, 'visits' => ($worlds[$k]['visits'] ?? 0) + 1];
            $d = date('Y-m-d', $a); $days[$d] = ($days[$d] ?? 0) + $sec;
        }
        $cur = null;
    };
    foreach ($st as $e) {
        $ts = (int)$e['ts'];
        if (in_array($e['kind'], ['world_join', 'world_leave', 'vrc_quit'], true)) $close($ts);
        if ($e['kind'] === 'world_join') $cur = ['id' => (string)$e['world_id'], 'name' => (string)$e['world_name'], 'start' => $ts];
        if ($ts < $from) continue;
        if ($e['kind'] === 'player_join' && $e['player_name'] !== '') $people[$e['player_id'] !== '' ? $e['player_id'] : 'n:' . $e['player_name']] = $e['player_name'];
        if ($e['kind'] === 'screenshot') $shots++;
        if ($e['kind'] === 'avatar') $avatars++;
    }
    $close(min(time(), $to));
    // neue Leute: erstes Treffen überhaupt in diesem Zeitraum
    $new = 0;
    if ($people) {
        $ids = array_keys($people);
        foreach (array_chunk($ids, 200) as $chunk) {
            $realIds = array_values(array_filter($chunk, fn($k) => strpos((string)$k, 'n:') !== 0));
            if (!$realIds) continue;
            $q = $db->prepare("SELECT COUNT(*) FROM (SELECT player_id FROM game_log WHERE user_id = ? AND kind = 'player_join' AND player_id IN (" . implode(',', array_fill(0, count($realIds), '?')) . ") GROUP BY player_id HAVING MIN(ts) >= " . (int)$from . ") x");   // Zahl direkt: SQLite vergleicht MIN() sonst mit Text
            $q->execute(array_merge([$uid], $realIds));
            $new += (int)$q->fetchColumn();
        }
    }
    uasort($worlds, fn($a, $b) => $b['seconds'] <=> $a['seconds']);
    arsort($days);
    $h = $db->prepare('SELECT AVG(bpm), MAX(bpm), COUNT(*) FROM web_heart WHERE user_id = ? AND ts >= ? AND ts < ?');
    $h->execute([$uid, $from, $to]);
    [$hav, $hmax, $hn] = $h->fetch(PDO::FETCH_NUM);
    return ['playtime' => $play, 'worlds' => count($worlds), 'top' => array_slice(array_values($worlds), 0, 5), 'people' => count($people), 'new_people' => $new,
            'screenshots' => $shots, 'avatars' => $avatars, 'best_day' => $days ? ['day' => (string)array_key_first($days), 'seconds' => (int)reset($days)] : null,
            'heart' => (int)$hn ? ['avg' => (int)round((float)$hav), 'max' => (int)$hmax] : null];
}
function nxp_week(int $uid, array $who, int $back): array {
    [$from, $to] = nxp_week_range($back);
    $cur = nxp_period($uid, $from, $to);
    $prev = nxp_period($uid, $from - ($to - $from), $from);
    $vid = (string)$who['vrc_id'];
    $events = 0; $posts = 0;
    try {
        require_once __DIR__ . '/clubs.php';
        $q = cl_db()->prepare("SELECT COUNT(*) FROM club_rsvp r JOIN club_events e ON e.id = r.event_id WHERE r.vrc_id = ? AND r.status = 'going' AND e.starts >= ? AND e.starts < ?");
        $q->execute([$vid, $from, $to]); $events = (int)$q->fetchColumn();
    } catch (Throwable $e) { $events = 0; }
    $p = nc_db()->prepare("SELECT COUNT(*) FROM comm_posts WHERE author = ? AND rating = 'sfw' AND status = 'visible' AND created >= ? AND created < ?");
    $p->execute([$vid, $from, $to]); $posts = (int)$p->fetchColumn();
    $diff = fn($k) => $cur[$k] - $prev[$k];
    return ['from' => $from, 'to' => $to, 'back' => max(0, min(52, $back)), 'week' => (int)date('W', $from), 'cur' => $cur + ['club_events' => $events, 'posts' => $posts],
            'diff' => ['playtime' => $diff('playtime'), 'worlds' => $diff('worlds'), 'people' => $diff('people'), 'screenshots' => $diff('screenshots')],
            'has_client' => (bool)nc_tokens($uid)];
}

// =====================================================================
// 🗺 Welt-Wunschliste
// =====================================================================
function nxp_wish_row(int $uid, int $id): array {
    $st = vrc_db()->prepare('SELECT * FROM web_wish WHERE id = ? AND user_id = ?');
    $st->execute([$id, $uid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new NcError('Eintrag nicht gefunden.');
    return $r;
}
function nxp_wish_list(int $uid): array {
    $db = vrc_db();
    // automatisch abhaken: der Client hat einen Besuch nach dem Eintragen gemeldet
    $open = $db->prepare('SELECT id, world_id, created FROM web_wish WHERE user_id = ? AND visited = 0');
    $open->execute([$uid]);
    $seen = $db->prepare("SELECT MIN(ts) FROM game_log WHERE user_id = ? AND kind = 'world_join' AND world_id = ? AND ts >= ?");
    $upd = $db->prepare('UPDATE web_wish SET visited = ? WHERE id = ?');
    foreach ($open->fetchAll(PDO::FETCH_ASSOC) as $w) {
        $seen->execute([$uid, $w['world_id'], (int)$w['created']]);
        $t = (int)$seen->fetchColumn();
        if ($t > 0) $upd->execute([$t, $w['id']]);
    }
    $st = $db->prepare('SELECT id, world_id, name, image, author, note, prio, tag, created, visited FROM web_wish WHERE user_id = ? ORDER BY visited > 0, prio DESC, created DESC');
    $st->execute([$uid]);
    $items = array_map(fn($r) => ['id' => (int)$r['id'], 'world_id' => $r['world_id'], 'name' => $r['name'], 'image' => $r['image'], 'author' => $r['author'], 'note' => $r['note'],
        'prio' => (int)$r['prio'], 'tag' => $r['tag'], 'created' => (int)$r['created'], 'visited' => (int)$r['visited']], $st->fetchAll(PDO::FETCH_ASSOC));
    return ['items' => $items, 'tags' => array_values(array_unique(array_filter(array_column($items, 'tag')))), 'open' => count(array_filter($items, fn($x) => !$x['visited']))];
}
function nxp_wish_add(int $uid, array $in): array {
    $wid = nc_world_id((string)($in['world_id'] ?? ''));
    $n = vrc_db()->prepare('SELECT COUNT(*) FROM web_wish WHERE user_id = ?'); $n->execute([$uid]);
    if ((int)$n->fetchColumn() >= NXP_WISH_MAX) throw new NcError('Höchstens ' . NXP_WISH_MAX . ' Welten auf der Wunschliste.');
    $name = nc_cut((string)($in['name'] ?? ''), 120); $image = (string)($in['image'] ?? ''); $author = nc_cut((string)($in['author'] ?? ''), 64);
    if ($name === '' && function_exists('vrc_worlds')) { try { $w = vrc_worlds([$wid])[$wid] ?? []; $name = nc_cut((string)($w['name'] ?? ''), 120); $image = $image ?: (string)($w['image'] ?? $w['thumb'] ?? ''); } catch (Throwable $e) { /* ohne Namen */ } }
    if (!preg_match('~^https://[\w.\-/%?=&]+$~', $image)) $image = '';
    vrc_db()->prepare('INSERT OR IGNORE INTO web_wish (user_id, world_id, name, image, author, note, prio, tag, created, visited) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)')
        ->execute([$uid, $wid, $name ?: $wid, mb_substr($image, 0, 400), $author, nc_cut((string)($in['note'] ?? ''), 300), max(0, min(2, (int)($in['prio'] ?? 1))), nc_cut(ltrim((string)($in['tag'] ?? ''), '#'), 24), time()]);
    return nxp_wish_list($uid) + ['msg' => '🗺 Auf der Wunschliste.'];
}
function nxp_wish_update(int $uid, array $in): array {
    $r = nxp_wish_row($uid, (int)($in['id'] ?? 0));
    $visited = array_key_exists('visited', $in) ? ((string)$in['visited'] === '1' ? ((int)$r['visited'] ?: time()) : 0) : (int)$r['visited'];
    vrc_db()->prepare('UPDATE web_wish SET note = ?, prio = ?, tag = ?, visited = ? WHERE id = ? AND user_id = ?')
        ->execute([array_key_exists('note', $in) ? nc_cut((string)$in['note'], 300) : $r['note'], array_key_exists('prio', $in) ? max(0, min(2, (int)$in['prio'])) : (int)$r['prio'],
                   array_key_exists('tag', $in) ? nc_cut(ltrim((string)$in['tag'], '#'), 24) : $r['tag'], $visited, (int)$r['id'], $uid]);
    // „noch nicht besucht“ von Hand: Eintragsdatum neu setzen, sonst hakt der alte Besuch sofort wieder ab
    if (array_key_exists('visited', $in) && !$visited) vrc_db()->prepare('UPDATE web_wish SET created = ? WHERE id = ?')->execute([time(), (int)$r['id']]);
    return nxp_wish_list($uid) + ['msg' => 'Gespeichert.'];
}
function nxp_wish_delete(int $uid, int $id): array {
    nxp_wish_row($uid, $id);
    vrc_db()->prepare('DELETE FROM web_wish WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
    return nxp_wish_list($uid) + ['msg' => 'Entfernt.'];
}

// =====================================================================
// 🏅 Profil: Abzeichen & Vollständigkeit
// =====================================================================
function nxp_badges(string $id): array {
    $acc = nx_account($id);
    if (!$acc) return [];
    try { require_once __DIR__ . '/clubs.php'; cl_db(); } catch (Throwable $e) { /* ohne Clubs */ }
    $db = nc_db();
    $one = function (string $sql, array $a) use ($db) { try { $s = $db->prepare($sql); $s->execute($a); return (int)$s->fetchColumn(); } catch (Throwable $e) { return 0; } };
    $age = time() - (int)$acc['created'];
    $posts = $one("SELECT COUNT(*) FROM comm_posts WHERE author = ? AND status = 'visible' AND rating = 'sfw' AND parent = 0", [$id]);
    $replies = $one("SELECT COUNT(*) FROM comm_posts WHERE author = ? AND status = 'visible' AND rating = 'sfw' AND parent > 0", [$id]);
    $followers = $one('SELECT COUNT(*) FROM comm_follows WHERE target = ?', [$id]);
    $founder = $one("SELECT COUNT(*) FROM clubs WHERE owner = ? AND status = 'active' AND adult = 0", [$id]);
    $events = $one("SELECT COUNT(*) FROM club_rsvp r JOIN club_events e ON e.id = r.event_id JOIN clubs c ON c.id = e.club_id WHERE r.vrc_id = ? AND r.status = 'going' AND e.starts < ? AND c.adult = 0", [$id, time()]);
    $b = [];
    if ($age < 30 * 86400) $b[] = ['🌱', 'Neu dabei', 'Seit weniger als 30 Tagen in der Nexus Community'];
    elseif ($age >= 365 * 86400) $b[] = ['🎂', floor($age / (365 * 86400)) . ' Jahr' . ($age >= 730 * 86400 ? 'e' : '') . ' dabei', 'Treues Mitglied der Nexus Community'];
    if ($posts >= 100) $b[] = ['📣', 'Vielschreiber:in', 'Mindestens 100 Beiträge'];
    elseif ($posts >= 10) $b[] = ['✍', 'Erzähler:in', 'Mindestens 10 Beiträge'];
    if ($replies >= 25) $b[] = ['💬', 'Hilfsbereit', 'Mindestens 25 Antworten in Unterhaltungen'];
    if ($followers >= 50) $b[] = ['🌟', 'Bekannt', 'Mindestens 50 Folgende'];
    elseif ($followers >= 10) $b[] = ['⭐', 'Beliebt', 'Mindestens 10 Folgende'];
    if ($founder) $b[] = ['🏠', 'Club-Gründer:in', 'Hat einen Club gegründet'];
    if ($events >= 5) $b[] = ['🎉', 'Event-Gänger:in', 'Bei mindestens 5 Club-Events zugesagt'];
    $out = array_map(fn($x) => ['icon' => $x[0], 'title' => $x[1], 'desc' => $x[2]], $b);
    // 8.4: Tags (Supporter, Moderator, VIP …) vorne – nur Anzeige
    try { require_once __DIR__ . '/nexuslic.php'; $out = array_merge(nl_tag_badges($id), $out); } catch (Throwable $e) { /* ohne Tags */ }
    return $out;
}
/** Wie vollständig ist mein Profil? (nur für mich) */
function nxp_profile_score(array $p): array {
    $checks = [['bio', 'Über mich', mb_strlen((string)($p['bio'] ?? '')) >= 20], ['pronouns', 'Pronomen', ($p['pronouns'] ?? '') !== ''], ['languages', 'Sprachen', ($p['languages'] ?? '') !== ''],
               ['interests', 'Interessen', ($p['interests'] ?? '') !== ''], ['avatar', 'Profilbild', ($p['avatar'] ?? '') !== ''], ['banner', 'Banner', ($p['banner'] ?? '') !== ''],
               ['links', 'Links', !empty($p['links'])], ['facts', 'Steckbrief', !empty((array)($p['facts'] ?? []))]];
    $done = count(array_filter($checks, fn($c) => $c[2]));
    return ['percent' => (int)round($done / count($checks) * 100), 'missing' => array_values(array_map(fn($c) => $c[1], array_filter($checks, fn($c) => !$c[2])))];
}

// =====================================================================
// 🔔 Push-Zusätze (aus nx_push_check)
// =====================================================================
function nxp_push_extra(int $uid, string $vrc_id, array $p, int $now): array {
    $msgs = [];
    $vid = strtolower($vrc_id);
    if (!empty($p['clubs']) && nx_uid_ok($vid)) {
        require_once __DIR__ . '/clubs.php';
        $acc = nx_account($vid);
        $adult = $acc && nx_nsfw_allowed($acc);   // 18+-Clubs nur mit Freigabe – auch in der Meldung
        $done = json_decode(setting('push_cle_' . $uid, '[]'), true) ?: [];
        $st = cl_db()->prepare("SELECT e.id, e.title, e.starts, e.place, c.name AS club, c.adult FROM club_rsvp r JOIN club_events e ON e.id = r.event_id JOIN clubs c ON c.id = e.club_id
                                WHERE r.vrc_id = ? AND r.status = 'going' AND e.status = 'active' AND e.starts > ? AND e.starts <= ? ORDER BY e.starts LIMIT 10");
        $st->execute([$vid, $now, $now + (int)$p['events_min'] * 60]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
            if (in_array((int)$e['id'], $done, true) || ((int)$e['adult'] && !$adult)) continue;
            $done[] = (int)$e['id'];
            $msgs[] = ['title' => '🏠 ' . $e['title'], 'body' => 'beginnt um ' . date('H:i', (int)$e['starts']) . ' · ' . $e['club'] . ($e['place'] !== '' ? ' · ' . mb_substr((string)$e['place'], 0, 60) : ''), 'tag' => 'club-ev-' . $e['id'], 'url' => 'nexus.php'];
        }
        set_setting('push_cle_' . $uid, json_encode(array_slice($done, -200)));
    }
    if (!empty($p['battery'])) {
        $s = nc_state($uid);
        $warned = json_decode(setting('push_batt_' . $uid, '{}'), true) ?: [];
        $now_low = [];
        foreach ((array)($s['devices'] ?? []) as $d) {
            if (empty($s['fresh']) || ($d['kind'] ?? '') === 'toy' || $d['battery'] === null || !empty($d['charging']) || $d['battery'] >= NXP_BATT_LOW) continue;
            $k = md5($d['kind'] . '|' . $d['name']);
            $now_low[$k] = 1;
            if (!empty($warned[$k])) continue;   // je Gerät nur einmal, bis es wieder geladen wurde
            $msgs[] = ['title' => '🔋 Akku schwach: ' . $d['name'], 'body' => 'Noch ' . (int)$d['battery'] . ' % – bald laden.', 'tag' => 'batt-' . $k, 'url' => 'nexus.php'];
        }
        set_setting('push_batt_' . $uid, json_encode($now_low));
    }
    return $msgs;
}

// =====================================================================
// 🔞 Spielzeug im Browser
// =====================================================================
function nxp_toy_rate(string $vid): void {
    $db = nc_db(); $m = (int)floor(time() / 60);
    $db->prepare('INSERT INTO toy_rate (vrc_id, minute, n) VALUES (?, ?, 1) ON CONFLICT(vrc_id) DO UPDATE SET n = CASE WHEN minute = excluded.minute THEN n + 1 ELSE 1 END, minute = excluded.minute')->execute([$vid, $m]);
    $st = $db->prepare('SELECT n FROM toy_rate WHERE vrc_id = ?'); $st->execute([$vid]);
    if ((int)$st->fetchColumn() > NXP_TOY_RATE) throw new NcError('Zu viele Befehle – kurz warten.');
}
/** Befehl im Format der App (toyremote.js, v1) */
function nxp_toy_msg(string $from, string $to, string $cmd, array $a): string {
    return json_encode(['v' => 1, 'from' => $from, 'to' => $to, 'tok' => '', 'ts' => (int)round(microtime(true) * 1000), 'n' => bin2hex(random_bytes(10)), 'c' => $cmd, 'a' => $a ?: new stdClass()], JSON_UNESCAPED_UNICODE);
}
function nxp_toy_deliver(string $from, string $to, string $cmd, array $a): void {
    if (!tb_push(tb_hash($to), nxp_toy_msg($from, $to, $cmd, $a)))
        throw new NcError('Das Spielzeug ist gerade nicht erreichbar – in VRC Nexus „Fernsteuerung empfangen“ und „über das Internet“ einschalten.');
}
function nxp_toy_log(string $link, string $owner, string $actor, string $cmd, string $detail = ''): void {
    $db = nc_db();
    $db->prepare('INSERT INTO toy_log (link_id, owner, actor, ts, cmd, detail) VALUES (?, ?, ?, ?, ?, ?)')->execute([$link, $owner, $actor, time(), $cmd, mb_substr($detail, 0, 200)]);
    if (mt_rand(1, 100) === 1) {
        $db->prepare('DELETE FROM toy_log WHERE ts < ?')->execute([time() - NXP_LOG_KEEP]);
        $db->prepare("DELETE FROM toy_links WHERE status IN ('ended', 'expired') AND (ended < ? AND expires < ?)")->execute([time() - NXP_LOG_KEEP, time() - NXP_LOG_KEEP]);
    }
}
function nxp_link_row(string $id): ?array {
    if (!preg_match('/^[0-9a-f]{20}$/', $id)) return null;
    $st = nc_db()->prepare('SELECT * FROM toy_links WHERE id = ?'); $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
/** abgelaufene Links als solche markieren (einmal, mit Protokoll-Eintrag) */
function nxp_link_state(array &$l): string {
    if (in_array($l['status'], ['active', 'paused'], true) && (int)$l['expires'] <= time()) {
        nc_db()->prepare("UPDATE toy_links SET status = 'expired', ended = ? WHERE id = ? AND status IN ('active', 'paused')")->execute([(int)$l['expires'], $l['id']]);
        nxp_toy_log($l['id'], $l['owner'], 'system', 'expired', 'Zeit abgelaufen');
        $l['status'] = 'expired'; $l['ended'] = (int)$l['expires'];
    }
    return $l['status'];
}
function nxp_link_pub(array $l, string $me, array $names): array {
    nxp_link_state($l);
    $pats = json_decode((string)$l['patterns'], true) ?: [];
    return ['id' => $l['id'], 'role' => $l['owner'] === $me ? 'owner' : 'partner', 'owner' => $l['owner'], 'owner_name' => (string)($names[$l['owner']] ?? ''),
            'partner' => $l['partner'], 'partner_name' => (string)($names[$l['partner']] ?? ''), 'cap' => (int)$l['cap'], 'patterns' => $pats, 'surprise' => (bool)$l['surprise'],
            'limits' => (string)$l['limits'], 'safeword' => (string)$l['safeword'], 'status' => $l['status'], 'accepted' => (int)$l['accepted'], 'created' => (int)$l['created'],
            'expires' => (int)$l['expires'], 'left' => max(0, (int)$l['expires'] - time()), 'last_used' => (int)$l['last_used'], 'reachable' => tb_exists(tb_hash($l['owner']))];
}
function nxp_toy_status(array $who): array {
    nc_need_nsfw($who);
    $me = (string)$who['vrc_id'];
    $st = nc_db()->prepare("SELECT * FROM toy_links WHERE (owner = ? OR partner = ?) AND (status IN ('active', 'paused') OR ended > ?) ORDER BY created DESC LIMIT 60");
    $st->execute([$me, $me, time() - 7 * 86400]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $con = nc_db()->prepare("SELECT from_id, to_id FROM comm_requests WHERE status = 'accepted' AND (from_id = ? OR to_id = ?)");
    $con->execute([$me, $me]);
    $partners = array_values(array_unique(array_map(fn($r) => $r['from_id'] === $me ? $r['to_id'] : $r['from_id'], $con->fetchAll(PDO::FETCH_ASSOC))));
    $names = nc_names(array_merge($partners, array_column($rows, 'owner'), array_column($rows, 'partner')));
    $links = array_map(fn($l) => nxp_link_pub($l, $me, $names), $rows);
    return ['me' => $me, 'reachable' => tb_exists(tb_hash($me)), 'patterns' => NXP_TOY_PATTERNS,
            'mine' => array_values(array_filter($links, fn($l) => $l['role'] === 'owner')), 'shared' => array_values(array_filter($links, fn($l) => $l['role'] === 'partner')),
            'connections' => array_map(fn($id) => ['vrc_id' => $id, 'name' => (string)($names[$id] ?? '')], $partners)];
}
function nxp_link_create(array $who, array $in): array {
    nc_need_nsfw($who);
    $me = (string)$who['vrc_id'];
    $partner = strtolower(trim((string)($in['partner'] ?? '')));
    if (!nx_uid_ok($partner) || $partner === $me) throw new NcError('Bitte eine gültige VRChat-ID deines Gegenübers angeben.');
    if (!nc_connected($me, $partner)) throw new NcError('Partner-Links gibt es nur für Verbindungen (ihr habt beide einer 18+-Anfrage zugestimmt).');
    $pa = nx_account($partner);
    if (!$pa || !empty($pa['banned']) || !nx_nsfw_allowed($pa)) throw new NcError('Dein Gegenüber hat gerade keine 18+-Freigabe (Verified 18+ und NSFW an).');
    $db = nc_db();
    $open = $db->prepare("SELECT * FROM toy_links WHERE owner = ? AND status IN ('active', 'paused')"); $open->execute([$me]);
    $rows = $open->fetchAll(PDO::FETCH_ASSOC);
    $live = array_filter($rows, function ($l) { return nxp_link_state($l) !== 'expired'; });
    if (array_filter($live, fn($l) => $l['partner'] === $partner)) throw new NcError('Für diese Person gibt es schon einen offenen Link – erst beenden.');
    if (count($live) >= NXP_LINK_MAX) throw new NcError('Höchstens ' . NXP_LINK_MAX . ' offene Partner-Links.');
    $min = max(5, min(720, (int)($in['minutes'] ?? 60)));
    $cap = max(5, min(100, (int)($in['cap'] ?? 50)));
    $pats = array_values(array_intersect(array_keys(NXP_TOY_PATTERNS), array_map('strval', (array)($in['patterns'] ?? []))));
    $id = bin2hex(random_bytes(10));
    $db->prepare("INSERT INTO toy_links (id, owner, partner, cap, patterns, surprise, limits, safeword, status, accepted, created, expires) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', 0, ?, ?)")
        ->execute([$id, $me, $partner, $cap, json_encode($pats), $cap >= 90 && !empty($in['surprise']) && $in['surprise'] !== '0' ? 1 : 0, nc_cut((string)($in['limits'] ?? ''), 500), nc_cut((string)($in['safeword'] ?? ''), 40), time(), time() + $min * 60]);
    nxp_toy_log($id, $me, $me, 'create', 'max. ' . $cap . ' %, ' . $min . ' min' . ($pats ? ', Muster: ' . implode(', ', $pats) : ', alle Muster'));
    return nxp_toy_status($who) + ['msg' => '🔗 Partner-Link erstellt – dein Gegenüber bestätigt zuerst deine Grenzen.', 'created' => $id];
}
/** Link holen und prüfen, ob ich dabei bin */
function nxp_link_mine(array $who, string $id): array {
    $l = nxp_link_row($id);
    if (!$l || ($l['owner'] !== $who['vrc_id'] && $l['partner'] !== $who['vrc_id'])) throw new NcError('Link nicht gefunden.');   // gleiche Antwort für fremde Links
    nxp_link_state($l);
    return $l;
}
function nxp_link_do(array $who, string $id, string $do): array {
    nc_need_nsfw($who);
    $l = nxp_link_mine($who, $id);
    $me = (string)$who['vrc_id']; $own = $l['owner'] === $me; $db = nc_db();
    $open = in_array($l['status'], ['active', 'paused'], true);
    switch ($do) {
        case 'accept':
            if ($own || $l['status'] !== 'active') throw new NcError('Nichts zu bestätigen.');
            $db->prepare('UPDATE toy_links SET accepted = ? WHERE id = ?')->execute([time(), $id]);
            nxp_toy_log($id, $l['owner'], $me, 'accept', 'Grenzen & Safeword gelesen und bestätigt');
            $msg = '✅ Bestätigt – du kannst jetzt steuern.';
            break;
        case 'pause': case 'resume':
            if (!$own || !$open) throw new NcError('Nur die Besitzerin/der Besitzer eines offenen Links.');
            $db->prepare('UPDATE toy_links SET status = ? WHERE id = ?')->execute([$do === 'pause' ? 'paused' : 'active', $id]);
            if ($do === 'pause') { try { nxp_toy_deliver($me, $me, 'stop', []); } catch (Throwable $e) { /* nicht erreichbar – pausiert trotzdem */ } }
            nxp_toy_log($id, $l['owner'], $me, $do, $do === 'pause' ? 'pausiert (Spielzeug gestoppt)' : 'fortgesetzt');
            $msg = $do === 'pause' ? '⏸ Pausiert – Spielzeug gestoppt.' : '▶ Fortgesetzt.';
            break;
        case 'end':
            if (!$open) throw new NcError('Der Link ist schon beendet.');
            $db->prepare("UPDATE toy_links SET status = 'ended', ended = ? WHERE id = ?")->execute([time(), $id]);
            try { nxp_toy_deliver($me, $l['owner'], 'stop', []); } catch (Throwable $e) { /* nicht erreichbar */ }
            nxp_toy_log($id, $l['owner'], $me, 'end', $own ? 'von der Besitzerin/dem Besitzer beendet' : 'vom Gegenüber beendet');
            $msg = '🛑 Link beendet – Spielzeug gestoppt.';
            break;
        default: throw new NcError('Unbekannte Aktion.');
    }
    return nxp_toy_status($who) + ['msg' => $msg];
}
/**
 * Befehl schicken: $target = 'self' (eigene Spielzeuge) oder Link-ID (als Gegenüber).
 * Not-Stopp geht IMMER – auch bei pausiertem, abgelaufenem oder beendetem Link, für beide Seiten.
 */
function nxp_toy_send(array $who, string $target, string $cmd, array $in): array {
    nc_need_nsfw($who);
    $me = (string)$who['vrc_id'];
    if (!in_array($cmd, NXP_TOY_CMDS, true)) throw new NcError('Unbekannter Befehl.');
    if ($cmd !== 'stop') nxp_toy_rate($me);   // der Not-Stopp wird nie gebremst
    $lv = max(0.0, min(1.0, (float)($in['lv'] ?? 0)));
    if ($target === 'self') {
        $a = [];
        if ($cmd === 'set') $a = ['lv' => round($lv, 3)];
        if ($cmd === 'pattern') { $a = nxp_pattern_args($in, 100, 600); }
        nxp_toy_deliver($me, $me, $cmd, $a);
        if ($cmd !== 'set') nxp_toy_log('self', $me, $me, $cmd, nxp_cmd_text($cmd, $a));
        return ['ok' => true, 'msg' => $cmd === 'stop' ? '🛑 Gestoppt.' : 'Gesendet.', 'lv' => $cmd === 'set' ? (int)round($lv * 100) : null];
    }
    $l = nxp_link_mine($who, $target);
    $own = $l['owner'] === $me;
    if ($cmd === 'stop') {
        try { nxp_toy_deliver($me, $l['owner'], 'stop', []); }
        finally { nxp_toy_log($l['id'], $l['owner'], $me, 'stop', '🛑 Not-Stopp'); nc_db()->prepare('UPDATE toy_links SET last_lv = 0 WHERE id = ?')->execute([$l['id']]); }
        return ['ok' => true, 'msg' => '🛑 Gestoppt.', 'lv' => 0];
    }
    if ($own) throw new NcError('Über deinen eigenen Link steuert nur dein Gegenüber – nutze „Meine Spielzeuge“.');
    if ($l['status'] === 'paused') throw new NcError('⏸ Der Link ist gerade pausiert.');
    if ($l['status'] !== 'active') throw new NcError('Der Link ist ' . ($l['status'] === 'expired' ? 'abgelaufen' : 'beendet') . '.');
    if (!(int)$l['accepted']) throw new NcError('Bitte zuerst die Grenzen bestätigen.');
    $oa = nx_account($l['owner']);
    if (!$oa || !empty($oa['banned']) || !nx_nsfw_allowed($oa)) throw new NcError('Die Besitzerin/der Besitzer hat gerade keine 18+-Freigabe – Steuerung gesperrt.');
    $cap = (int)$l['cap'];
    $left = (int)$l['expires'] - time();
    $a = [];
    if ($cmd === 'set') { $lv = min($lv, $cap / 100); $a = ['lv' => round($lv, 3)]; }
    if ($cmd === 'pattern') {
        $pats = json_decode((string)$l['patterns'], true) ?: [];
        if ($pats && !in_array((string)($in['id'] ?? ''), $pats, true)) throw new NcError('Dieses Muster ist im Link nicht freigegeben.');
        $a = nxp_pattern_args($in, $cap, $left);
    }
    if ($cmd === 'surprise' && !(int)$l['surprise']) throw new NcError('Überraschungen sind in diesem Link nicht freigegeben.');
    nxp_toy_deliver($me, $l['owner'], $cmd, $a);
    $pct = $cmd === 'set' ? (int)round($lv * 100) : (int)$l['last_lv'];
    // Protokoll: alles außer Regler-Lebenszeichen ohne nennenswerte Änderung
    if ($cmd !== 'set' || abs($pct - (int)$l['last_lv']) >= 10 || ($pct === 0 && (int)$l['last_lv'] > 0)) nxp_toy_log($l['id'], $l['owner'], $me, $cmd, nxp_cmd_text($cmd, $a));
    nc_db()->prepare('UPDATE toy_links SET last_used = ?, last_lv = ? WHERE id = ?')->execute([time(), $pct, $l['id']]);
    return ['ok' => true, 'msg' => $cmd === 'ping' ? 'Verbindungstest gesendet.' : 'Gesendet.', 'lv' => $cmd === 'set' ? $pct : null, 'cap' => $cap];
}
function nxp_pattern_args(array $in, int $cap, int $left): array {
    $id = (string)($in['id'] ?? '');
    if (!isset(NXP_TOY_PATTERNS[$id])) throw new NcError('Unbekanntes Muster.');
    $sec = max(10, min(600, (int)($in['seconds'] ?? 120), max(10, $left)));
    return ['id' => $id, 'speed' => round(max(0.3, min(3.0, (float)($in['speed'] ?? 1))), 2), 'strength' => max(5, min($cap, (int)($in['strength'] ?? $cap))), 'seconds' => $sec];
}
function nxp_cmd_text(string $cmd, array $a): string {
    if ($cmd === 'set') return 'Stärke ' . (int)round(($a['lv'] ?? 0) * 100) . ' %';
    if ($cmd === 'pattern') return (NXP_TOY_PATTERNS[$a['id']] ?? $a['id']) . ' · ' . $a['strength'] . ' % · ' . $a['seconds'] . ' s';
    return ['ping' => 'Verbindungstest', 'surprise' => '🎁 Überraschung', 'stop' => '🛑 Stopp'][$cmd] ?? $cmd;
}
/** Protokoll – nur für die Beteiligten (Link: Besitzer:in + Gegenüber; „self“: nur ich) */
function nxp_toy_log_get(array $who, string $id): array {
    nc_need_nsfw($who);
    $me = (string)$who['vrc_id'];
    $owner = $me;
    if ($id !== 'self') { $l = nxp_link_mine($who, $id); $owner = $l['owner']; }
    $st = nc_db()->prepare('SELECT actor, ts, cmd, detail FROM toy_log WHERE link_id = ? AND owner = ? ORDER BY id DESC LIMIT 300');
    $st->execute([$id, $owner]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $names = nc_names(array_column($rows, 'actor'));
    return ['items' => array_map(fn($r) => ['ts' => (int)$r['ts'], 'cmd' => $r['cmd'], 'detail' => $r['detail'], 'me' => $r['actor'] === $me,
        'who' => $r['actor'] === 'system' ? 'System' : ($r['actor'] === $me ? 'Du' : (string)($names[$r['actor']] ?? 'Gegenüber'))], $rows), 'can_clear' => $owner === $me];
}
function nxp_toy_log_clear(array $who, string $id): array {
    nc_need_nsfw($who);
    $me = (string)$who['vrc_id'];
    if ($id !== 'self') { $l = nxp_link_mine($who, $id); if ($l['owner'] !== $me) throw new NcError('Nur die Besitzerin/der Besitzer kann das Protokoll löschen.'); }
    nc_db()->prepare('DELETE FROM toy_log WHERE link_id = ? AND owner = ?')->execute([$id, $me]);
    return ['items' => [], 'can_clear' => true, 'msg' => 'Protokoll gelöscht.'];
}

// =====================================================================
// Verteiler (nexus.php, act = nxp_*)
// =====================================================================
function nxp_action(int $uid, string $act, array $in): array {
    $J = function (string $k) use ($in) { $v = json_decode((string)($in[$k] ?? ''), true); return is_array($v) ? $v : []; };
    $who = nxweb_who($uid);
    switch ($act) {
        case 'nxp_heart': return nxp_heart($uid, (string)($in['range'] ?? 'day'));
        case 'nxp_devices': return nxp_devices($uid, $who);
        case 'nxp_device_remove': nx_logout($who, preg_match('/^[0-9a-f]{12}$/', (string)($in['id'] ?? '')) ? (string)$in['id'] : 'x'); return nxp_devices($uid, $who) + ['msg' => 'Gerät abgemeldet.'];
        case 'nxp_week': return nxp_week($uid, $who, (int)($in['back'] ?? 0));
        case 'nxp_wish': return nxp_wish_list($uid);
        case 'nxp_wish_add': return nxp_wish_add($uid, $in);
        case 'nxp_wish_update': return nxp_wish_update($uid, $in);
        case 'nxp_wish_delete': return nxp_wish_delete($uid, (int)($in['id'] ?? 0));
        case 'nxp_profile_extra': {
            $id = strtolower(trim((string)($in['id'] ?? ''))) ?: (string)$who['vrc_id'];
            if (!nx_uid_ok($id)) throw new NcError('Ungültige VRChat-User-ID.');
            $out = ['badges' => nxp_badges($id)];
            if ($id === $who['vrc_id']) $out['score'] = nxp_profile_score(nc_profile($who, $id));
            return $out;
        }
        // 🔞 – jede Aktion prüft Verified 18+ und NSFW selbst
        case 'nxp_toy_status': return nxp_toy_status($who);
        case 'nxp_toy_send': return nxp_toy_send($who, (string)($in['target'] ?? ''), (string)($in['c'] ?? ''), $in);
        case 'nxp_toy_link_create': return nxp_link_create($who, $in + ['patterns' => $J('patterns')]);
        case 'nxp_toy_link': return nxp_link_do($who, (string)($in['id'] ?? ''), (string)($in['do'] ?? ''));
        case 'nxp_toy_log': return nxp_toy_log_get($who, (string)($in['id'] ?? ''));
        case 'nxp_toy_log_clear': return nxp_toy_log_clear($who, (string)($in['id'] ?? ''));
    }
    throw new NcError('Unbekannte Aktion.');
}
