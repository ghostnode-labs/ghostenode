<?php
/**
 * D2 Nexus – Chat zwischen D2-Nexus-Nutzern (Clan-Kanal + Direktnachrichten).
 * Kein eigenes Konto: Jede Anfrage trägt das Bungie-Zugangstoken des Nutzers; der Server fragt Bungie, wer das ist
 * (10 min zwischengespeichert, nur als Hash) und ob er im Clan ist (30 min). Nachrichten liegen 30 Tage, dann werden sie gelöscht.
 */
require_once __DIR__ . '/d2.php';

const D2C_KEEP_DAYS = 30;
const D2C_MAX_LEN = 1000;

class D2cError extends RuntimeException { public int $status; public function __construct(string $m, int $s = 400) { parent::__construct($m); $this->status = $s; } }

function d2c_db(): Db { return db_get('d2'); }

function d2c_migrate(PDO $db): void {
    if ((int)$db->query('PRAGMA user_version')->fetchColumn() < 1) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS users (bungie_id TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL DEFAULT '', code INTEGER NOT NULL DEFAULT 0, clan TEXT NOT NULL DEFAULT '', last_seen INTEGER NOT NULL DEFAULT 0, blocked INTEGER NOT NULL DEFAULT 0);
            CREATE TABLE IF NOT EXISTS msgs (id INTEGER PRIMARY KEY AUTOINCREMENT, chan TEXT NOT NULL, from_id TEXT NOT NULL, from_name TEXT NOT NULL, body TEXT NOT NULL, ts INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS idx_d2_msgs_chan ON msgs(chan, id);
            CREATE TABLE IF NOT EXISTS reads (bungie_id TEXT NOT NULL, chan TEXT NOT NULL, last_id INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (bungie_id, chan));
            PRAGMA user_version = 1;
        ");
    }
}

/** GET auf die Bungie-API mit dem Token des Nutzers */
function d2c_bungie(string $path, string $token = '') {
    $key = d2_key();
    if ($key === '') throw new D2cError('Chat ist auf dem Server noch nicht eingerichtet.', 503);
    $ch = curl_init('https://www.bungie.net/Platform' . $path);
    $h = ['X-API-Key: ' . $key, 'User-Agent: GhostNode-D2'];
    if ($token !== '') $h[] = 'Authorization: Bearer ' . $token;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => $h]);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$raw, true);
    if ($code === 401) throw new D2cError('Bungie-Anmeldung abgelaufen.', 401);
    if (!is_array($j) || (int)($j['ErrorCode'] ?? 0) !== 1) throw new D2cError('Bungie antwortet nicht.', 502);
    return $j['Response'] ?? null;
}

/** Wer ist das? Token → Bungie-Konto (+ Clan), zwischengespeichert nur unter dem Hash des Tokens */
function d2c_who(string $token): array {
    if (!preg_match('#^[A-Za-z0-9+/=._-]{20,4000}$#', $token)) throw new D2cError('Nicht angemeldet.', 401);
    $f = d2_cache_dir('who') . '/' . hash('sha256', $token) . '.json';
    if (is_file($f) && time() - filemtime($f) < 600 && ($c = json_decode((string)file_get_contents($f), true))) return $c;
    $r = d2c_bungie('/User/GetMembershipsForCurrentUser/', $token);
    $u = $r['bungieNetUser'] ?? [];
    $bid = (string)($u['membershipId'] ?? '');
    if (!preg_match('/^\d{1,20}$/', $bid)) throw new D2cError('Bungie-Konto nicht erkannt.', 401);
    $who = ['id' => $bid, 'name' => mb_substr((string)($u['cachedBungieGlobalDisplayName'] ?? $u['displayName'] ?? 'Hüter'), 0, 40), 'code' => (int)($u['cachedBungieGlobalDisplayNameCode'] ?? 0), 'clan' => '', 'clanName' => ''];
    // Clan über die erste Destiny-Mitgliedschaft (Cross-Save beachten)
    foreach ((array)($r['destinyMemberships'] ?? []) as $m) {
        if (!empty($m['crossSaveOverride']) && $m['crossSaveOverride'] !== $m['membershipType']) continue;
        try {
            $g = d2c_bungie('/GroupV2/User/' . (int)$m['membershipType'] . '/' . preg_replace('/\D/', '', (string)$m['membershipId']) . '/0/1/');
            $grp = $g['results'][0]['group'] ?? null;
            if ($grp) { $who['clan'] = (string)$grp['groupId']; $who['clanName'] = mb_substr((string)$grp['name'], 0, 60); }
        } catch (Throwable $e) { /* ohne Clan */ }
        break;
    }
    @file_put_contents($f, json_encode($who, JSON_UNESCAPED_UNICODE), LOCK_EX);
    if (mt_rand(1, 50) === 1) foreach (glob(dirname($f) . '/*.json') ?: [] as $x) if (time() - (int)@filemtime($x) > 3600) @unlink($x);
    $db = d2c_db();
    $up = $db->prepare('UPDATE users SET name = ?, code = ?, clan = ?, last_seen = ? WHERE bungie_id = ?');
    $up->execute([$who['name'], $who['code'], $who['clan'], time(), $bid]);
    if (!$up->rowCount()) { try { $db->prepare('INSERT INTO users (bungie_id, name, code, clan, last_seen) VALUES (?, ?, ?, ?, ?)')->execute([$bid, $who['name'], $who['code'], $who['clan'], time()]); } catch (Throwable $e) { /* gleichzeitig angelegt */ } }
    return $who;
}

/** Admins des D2-Nexus-Chats: Bungie-IDs aus GN_D2_ADMINS oder Einstellung „d2_admins“ (Komma-Liste) */
function d2c_is_admin(array $who): bool {
    $list = (string)getenv('GN_D2_ADMINS');
    if ($list === '' && function_exists('setting')) $list = setting('d2_admins', '');
    return in_array($who['id'], array_filter(array_map('trim', explode(',', $list))), true);
}

/** Darf $who diesen Kanal lesen/schreiben? clan:<groupId> nur Mitglieder, dm:<a>:<b> nur die beiden */
function d2c_chan_ok(array $who, string $chan, bool $write = false): bool {
    if ($chan === 'news') return !$write || d2c_is_admin($who);   // 📢 Ankündigungen: alle lesen, nur Admins schreiben
    if (preg_match('/^clan:(\d{1,20})$/', $chan, $m)) return $who['clan'] !== '' && $who['clan'] === $m[1];
    if (preg_match('/^dm:(\d{1,20}):(\d{1,20})$/', $chan, $m)) return $m[1] < $m[2] ? in_array($who['id'], [$m[1], $m[2]], true) : false;
    return false;
}
function d2c_dm(string $a, string $b): string { return strcmp($a, $b) < 0 ? "dm:$a:$b" : "dm:$b:$a"; }

/** Kanäle des Nutzers mit Ungelesen-Zahl */
function d2c_chans(array $who): array {
    $db = d2c_db();
    $out = [];
    $out[] = ['chan' => 'news', 'name' => 'Ankündigungen', 'kind' => 'news'];
    if ($who['clan'] !== '') $out[] = ['chan' => 'clan:' . $who['clan'], 'name' => $who['clanName'] ?: 'Clan', 'kind' => 'clan'];
    $st = $db->prepare("SELECT DISTINCT chan FROM msgs WHERE chan LIKE ? OR chan LIKE ?");
    $st->execute(['dm:' . $who['id'] . ':%', 'dm:%:' . $who['id']]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
        [, $a, $b] = explode(':', $c);
        $other = $a === $who['id'] ? $b : $a;
        $n = $db->prepare('SELECT name, code FROM users WHERE bungie_id = ?'); $n->execute([$other]); $u = $n->fetch(PDO::FETCH_ASSOC) ?: ['name' => 'Hüter', 'code' => 0];
        $out[] = ['chan' => $c, 'name' => $u['name'], 'code' => (int)$u['code'], 'kind' => 'dm', 'with' => $other];
    }
    foreach ($out as &$c) {
        $r = $db->prepare('SELECT last_id FROM reads WHERE bungie_id = ? AND chan = ?'); $r->execute([$who['id'], $c['chan']]);
        $last = (int)$r->fetchColumn();
        $q = $db->prepare('SELECT COUNT(*) AS n, MAX(id) AS mx, MAX(ts) AS ts FROM msgs WHERE chan = ? AND id > ? AND from_id <> ?'); $q->execute([$c['chan'], $last, $who['id']]);
        $x = $q->fetch(PDO::FETCH_ASSOC);
        $t = $db->prepare('SELECT MAX(ts) FROM msgs WHERE chan = ?'); $t->execute([$c['chan']]);
        $c['unread'] = (int)$x['n']; $c['lastTs'] = (int)$t->fetchColumn();
    }
    unset($c);
    $rank = ['news' => 0, 'clan' => 1, 'dm' => 2];
    usort($out, fn($a, $b) => ($rank[$a['kind']] <=> $rank[$b['kind']]) ?: $b['lastTs'] <=> $a['lastTs']);
    return $out;
}

function d2c_messages(array $who, string $chan, int $after = 0): array {
    if (!d2c_chan_ok($who, $chan)) throw new D2cError('Kein Zugriff auf diesen Kanal.', 403);
    $db = d2c_db();
    if ($after > 0) { $st = $db->prepare('SELECT id, from_id, from_name, body, ts FROM msgs WHERE chan = ? AND id > ? ORDER BY id LIMIT 200'); $st->execute([$chan, $after]); $rows = $st->fetchAll(PDO::FETCH_ASSOC); }
    else { $st = $db->prepare('SELECT id, from_id, from_name, body, ts FROM msgs WHERE chan = ? ORDER BY id DESC LIMIT 80'); $st->execute([$chan]); $rows = array_reverse($st->fetchAll(PDO::FETCH_ASSOC)); }
    $max = $rows ? (int)end($rows)['id'] : 0;
    if ($max) {
        $up = $db->prepare('UPDATE reads SET last_id = ? WHERE bungie_id = ? AND chan = ? AND last_id < ?'); $up->execute([$max, $who['id'], $chan, $max]);
        $ex = $db->prepare('SELECT 1 FROM reads WHERE bungie_id = ? AND chan = ?'); $ex->execute([$who['id'], $chan]);
        if (!$ex->fetchColumn()) { try { $db->prepare('INSERT INTO reads (bungie_id, chan, last_id) VALUES (?, ?, ?)')->execute([$who['id'], $chan, $max]); } catch (Throwable $e) { /* egal */ } }
    }
    return array_map(fn($r) => ['id' => (int)$r['id'], 'from' => (string)$r['from_id'], 'name' => (string)$r['from_name'], 'text' => (string)$r['body'], 'ts' => (int)$r['ts'], 'mine' => $r['from_id'] === $who['id']], $rows);
}

function d2c_send(array $who, string $chan, string $text): array {
    if (!d2c_chan_ok($who, $chan, true)) throw new D2cError($chan === 'news' ? 'Ankündigungen schreiben nur Admins.' : 'Kein Zugriff auf diesen Kanal.', 403);
    $db = d2c_db();
    $b = $db->prepare('SELECT blocked FROM users WHERE bungie_id = ?'); $b->execute([$who['id']]);
    if ((int)$b->fetchColumn()) throw new D2cError('Du bist für den Chat gesperrt.', 403);
    $text = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text));
    if ($text === '') throw new D2cError('Leere Nachricht.');
    if (mb_strlen($text) > D2C_MAX_LEN) throw new D2cError('Höchstens ' . D2C_MAX_LEN . ' Zeichen.');
    $db->prepare('INSERT INTO msgs (chan, from_id, from_name, body, ts) VALUES (?, ?, ?, ?, ?)')->execute([$chan, $who['id'], $who['name'], $text, time()]);
    if (mt_rand(1, 100) === 1) $db->prepare('DELETE FROM msgs WHERE ts < ?')->execute([time() - D2C_KEEP_DAYS * 86400]);
    return ['ok' => true, 'id' => (int)$db->lastInsertId()];
}

/* ---------- Admin (D2-Nexus-Plugin „Admin-Panel“) ---------- */
/** $trusted = Aufruf über die Web-API mit geprüftem GhostNode-Admin-Konto (Admin-Plugin) */
function d2c_admin(array $who, string $op, array $in, bool $trusted = false): array {
    if (!$trusted && !d2c_is_admin($who)) throw new D2cError('Nur für Admins.', 403);
    $db = d2c_db();
    switch ($op) {
        case 'stats':
            $n = fn(string $sql, array $p = []) => (function () use ($db, $sql, $p) { $st = $db->prepare($sql); $st->execute($p); return (int)$st->fetchColumn(); })();
            return ['users' => $n('SELECT COUNT(*) FROM users'), 'active24h' => $n('SELECT COUNT(*) FROM users WHERE last_seen > ?', [time() - 86400]), 'active7d' => $n('SELECT COUNT(*) FROM users WHERE last_seen > ?', [time() - 7 * 86400]),
                'blocked' => $n('SELECT COUNT(*) FROM users WHERE blocked = 1'), 'msgs' => $n('SELECT COUNT(*) FROM msgs'), 'msgs24h' => $n('SELECT COUNT(*) FROM msgs WHERE ts > ?', [time() - 86400]),
                'clans' => $n("SELECT COUNT(DISTINCT clan) FROM users WHERE clan <> ''"), 'login' => d2_oauth() ? (d2_oauth()['secret'] === '' ? 'Public' : 'Confidential') : 'fehlt', 'keepDays' => D2C_KEEP_DAYS];
        case 'users':
            $q = '%' . str_replace(['%', '_'], '', mb_substr((string)($in['q'] ?? ''), 0, 40)) . '%';
            $st = $db->prepare('SELECT bungie_id, name, code, clan, last_seen, blocked FROM users WHERE name LIKE ? OR bungie_id LIKE ? ORDER BY last_seen DESC LIMIT 200'); $st->execute([$q, $q]);
            return ['users' => array_map(fn($u) => ['id' => $u['bungie_id'], 'name' => $u['name'], 'code' => (int)$u['code'], 'clan' => $u['clan'], 'lastSeen' => (int)$u['last_seen'], 'blocked' => (bool)$u['blocked']], $st->fetchAll(PDO::FETCH_ASSOC))];
        case 'msgs':
            $chan = (string)($in['chan'] ?? '');
            if ($chan !== '') { $st = $db->prepare('SELECT id, chan, from_id, from_name, body, ts FROM msgs WHERE chan = ? ORDER BY id DESC LIMIT 150'); $st->execute([$chan]); }
            else $st = $db->query('SELECT id, chan, from_id, from_name, body, ts FROM msgs ORDER BY id DESC LIMIT 150');
            // Direktnachrichten nur mit Kanalnamen, Inhalt bleibt privat
            return ['msgs' => array_map(fn($m) => ['id' => (int)$m['id'], 'chan' => $m['chan'], 'from' => $m['from_id'], 'name' => $m['from_name'], 'text' => strpos($m['chan'], 'dm:') === 0 ? '(Direktnachricht – privat)' : $m['body'], 'ts' => (int)$m['ts']], $st->fetchAll(PDO::FETCH_ASSOC))];
        case 'delete':
            $db->prepare('DELETE FROM msgs WHERE id = ?')->execute([(int)($in['id'] ?? 0)]);
            return ['ok' => true];
        case 'block':
            $id = (string)($in['id'] ?? '');
            if (!preg_match('/^\d{1,20}$/', $id)) throw new D2cError('Ungültige ID.');
            if ($id === $who['id']) throw new D2cError('Dich selbst sperrst du besser nicht.');
            $db->prepare('UPDATE users SET blocked = ? WHERE bungie_id = ?')->execute([!empty($in['blocked']) ? 1 : 0, $id]);
            if (!empty($in['purge'])) $db->prepare("DELETE FROM msgs WHERE from_id = ? AND chan NOT LIKE 'dm:%'")->execute([$id]);
            return ['ok' => true];
        case 'announce':
            $text = trim((string)($in['text'] ?? ''));
            if ($text === '' || mb_strlen($text) > D2C_MAX_LEN) throw new D2cError('Text fehlt oder ist zu lang.');
            $db->prepare('INSERT INTO msgs (chan, from_id, from_name, body, ts) VALUES (?, ?, ?, ?, ?)')->execute(['news', (string)$who['id'], '📢 ' . mb_substr((string)$who['name'], 0, 40), $text, time()]);
            return ['ok' => true, 'id' => (int)$db->lastInsertId()];
    }
    throw new D2cError('Unbekannte Admin-Aktion.', 404);
}
