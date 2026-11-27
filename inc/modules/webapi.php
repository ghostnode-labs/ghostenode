<?php
/**
 * GhostNode Web-API (v1) – Schlüssel, Rechte, Limits und Endpunkte. Ausgeliefert über api.php.
 * Schlüssel: „gnk_“ + 40 Zeichen, gespeichert nur als SHA-256; je Benutzer höchstens 10, jederzeit widerrufbar.
 */
require_once __DIR__ . '/nexuswarn.php';   // nw_rate_over

const WA_SCOPES = [
    'read'      => ['Lesen', 'Profil, Seiten, Kalender, Changelog, News'],
    'community' => ['Community', 'Nexus-Community lesen und posten (braucht ein verbundenes VRChat-Konto in VRC Nexus Web)'],
];

function wa_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (setting('webapi_schema', '0') === '1') return;
    site_db()->exec('CREATE TABLE IF NOT EXISTS api_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, name TEXT NOT NULL DEFAULT \'\', prefix TEXT NOT NULL,
        key_hash TEXT NOT NULL UNIQUE, scopes TEXT NOT NULL DEFAULT \'read\', created INTEGER NOT NULL, last_used INTEGER NOT NULL DEFAULT 0, uses INTEGER NOT NULL DEFAULT 0)');
    set_setting('webapi_schema', '1');
}
function wa_keys(int $uid): array {
    wa_schema();
    $st = site_db()->prepare('SELECT id, name, prefix, scopes, created, last_used, uses FROM api_keys WHERE user_id = ? ORDER BY id DESC');
    $st->execute([$uid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function wa_create(int $uid, string $name, array $scopes): string {
    wa_schema();
    if (count(array_filter(wa_keys($uid), fn($k) => strpos((string)$k['prefix'], 'gnk_') === 0)) >= 10) throw new InvalidArgumentException('Höchstens 10 Schlüssel – bitte erst einen widerrufen.');
    $scopes = array_values(array_intersect(array_keys(WA_SCOPES), $scopes)) ?: ['read'];
    $key = 'gnk_' . bin2hex(random_bytes(20));
    site_db()->prepare('INSERT INTO api_keys (user_id, name, prefix, key_hash, scopes, created) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$uid, mb_substr(trim($name) ?: 'Schlüssel', 0, 40), substr($key, 0, 10), hash('sha256', $key), implode(',', $scopes), time()]);
    audit('API-Schlüssel erstellt', substr($key, 0, 10) . '… (' . implode(', ', $scopes) . ')');
    return $key;
}
function wa_revoke(int $uid, int $id): void {
    wa_schema();
    site_db()->prepare('DELETE FROM api_keys WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
    audit('API-Schlüssel widerrufen', '#' . $id);
}
/** Schlüssel aus Authorization: Bearer … oder X-Api-Key → [Schlüssel-Zeile, Benutzer] oder null */
function wa_auth(): ?array {
    $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    $key = preg_match('/^Bearer\s+(gn[ko]_[0-9a-f]{40})$/', $h, $m) ? $m[1] : (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
    if ($key === '') return null;
    if (!preg_match('/^gn[ko]_[0-9a-f]{40}$/', $key)) throw new WaError('Ungültiger API-Schlüssel.', 401);
    wa_schema();
    $st = site_db()->prepare('SELECT * FROM api_keys WHERE key_hash = ?');
    $st->execute([hash('sha256', $key)]);
    $k = $st->fetch(PDO::FETCH_ASSOC);
    if (!$k) throw new WaError('Ungültiger API-Schlüssel.', 401);
    $u = site_db()->prepare('SELECT * FROM users WHERE id = ?'); $u->execute([(int)$k['user_id']]);
    $user = $u->fetch(PDO::FETCH_ASSOC);
    if (!$user || (isset($user['active']) && !(int)$user['active'])) throw new WaError('Konto gesperrt.', 403);
    site_db()->prepare('UPDATE api_keys SET last_used = ?, uses = uses + 1 WHERE id = ?')->execute([time(), (int)$k['id']]);
    $k['scopes'] = explode(',', (string)$k['scopes']);
    return [$k, $user];
}
class WaError extends RuntimeException { public int $status; public function __construct(string $m, int $s = 400) { parent::__construct($m); $this->status = $s; } }

/** Endpunkte – $key = [Schlüssel, Benutzer] oder null (öffentlich) */
function wa_route(string $method, string $path, ?array $key): array {
    $need = function (string $scope) use ($key): array {
        if (!$key) throw new WaError('Für diesen Endpunkt wird ein API-Schlüssel gebraucht.', 401);
        if (!in_array($scope, $key[0]['scopes'], true)) throw new WaError('Dem Schlüssel fehlt das Recht „' . $scope . '“.', 403);
        return $key[1];
    };
    $q = fn(string $k, $d = '') => $_GET[$k] ?? $d;
    switch ("$method $path") {
        case 'GET status':
            return ['ok' => true, 'service' => 'ghostnode-web-api', 'version' => 1, 'time' => time(), 'authed' => (bool)$key, 'docs' => 'api.php'];
        case 'GET versions': {
            require_once __DIR__ . '/changelog.php';
            $n = max(1, min(100, (int)$q('limit', 20)));
            return ['items' => array_map(fn($r) => ['version' => $r['v'], 'date' => $r['date'], 'url' => $r['url'], 'downloads' => array_map(fn($a) => ['name' => $a['name'], 'url' => $a['url'], 'size' => $a['size']], $r['assets'])], array_slice(cl_fetch(), 0, $n))];
        }
        case 'GET versions/latest': {
            require_once __DIR__ . '/changelog.php';
            $r = cl_fetch()[0] ?? null;
            if (!$r) throw new WaError('Gerade keine Versionsliste verfügbar.', 503);
            return ['version' => $r['v'], 'date' => $r['date'], 'url' => $r['url'], 'downloads' => $r['assets']];
        }
        case 'GET news': {
            require_once __DIR__ . '/nexuspulse.php';
            $items = np_steam();
            $kind = (string)$q('kind');
            if ($kind !== '') $items = array_values(array_filter($items, fn($x) => $x['kind'] === $kind));
            return ['items' => array_slice($items, 0, max(1, min(30, (int)$q('limit', 10))))];
        }
        case 'GET market': {
            require_once __DIR__ . '/market.php';
            return mk_index();
        }
        case 'GET me': {
            $u = $need('read');
            return ['id' => (int)$u['id'], 'name' => user_label($u), 'username' => $u['username'], 'role_id' => (int)$u['role_id'], 'scopes' => $key[0]['scopes']];
        }
        case 'GET pages': {
            $u = $need('read');
            $GLOBALS['__wa_user'] = $u;
            $rows = site_db()->query('SELECT p.*, c.name AS cat FROM pages p LEFT JOIN page_categories c ON c.id = p.category_id WHERE p.hidden = 0 ORDER BY p.sort, p.title')->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $p) if (wa_page_ok($p, $u)) $out[] = ['title' => $p['title'], 'icon' => $p['icon'], 'description' => $p['description'], 'category' => (string)$p['cat'], 'url' => page_href($p)];
            return ['items' => $out];
        }
        case 'GET calendar': {
            $u = $need('read');
            require_once __DIR__ . '/calendar.php';
            $days = max(1, min(90, (int)$q('days', 30)));
            $now = new DateTimeImmutable();
            $items = cal_items($now, $now->modify('+' . $days . ' days'), wa_can($u, 'calendar.private') || wa_can($u, 'calendar.edit'), ['events', 'countdowns']);
            return ['items' => array_map(fn($it) => ['title' => (string)($it['title'] ?? ''), 'start' => $it['start'] instanceof DateTimeInterface ? $it['start']->format(DATE_ATOM) : '', 'end' => isset($it['end']) && $it['end'] instanceof DateTimeInterface ? $it['end']->format(DATE_ATOM) : '',
                'all_day' => !empty($it['all_day']), 'kind' => (string)($it['layer'] ?? $it['kind'] ?? ''), 'location' => (string)($it['location'] ?? '')], array_slice($items, 0, 200))];
        }
        case 'GET bot/feed': {   // 8.1: offene Schnittstelle für Bots anderer Communities – ohne Schlüssel, ein Aufruf für alles
            require_once __DIR__ . '/changelog.php'; require_once __DIR__ . '/status.php'; require_once __DIR__ . '/nexuspulse.php';
            $rel = []; $inc = []; $news = [];
            try { $rel = cl_fetch(); } catch (Throwable $e) { /* Quelle fällt aus → Rest liefern */ }
            try { $inc = st_summary()['incidents'] ?? []; } catch (Throwable $e) { }
            try { $news = np_steam(); } catch (Throwable $e) { }
            $types = array_filter(explode(',', (string)$q('types', '')));
            $items = wa_bot_items($rel, $inc, $news, (int)$q('since', 0), $types, max(1, min(50, (int)$q('limit', 20))));
            return (string)$q('format') === 'discord' ? wa_bot_discord($items) : ['items' => $items, 'next_since' => $items ? max(array_column($items, 'time')) : (int)$q('since', 0)];
        }
        case 'GET community/feed':
        case 'POST community/post':
        case 'GET community/trends': {
            $u = $need('community');
            require_once __DIR__ . '/vrchat.php';
            require_once __DIR__ . '/nexusweb.php';
            $who = nxweb_who((int)$u['id']);
            $who['_nsfw'] = false;   // über die API nie NSFW
            if ($path === 'community/trends') return nc_trends($who, 'sfw');
            if ($method === 'GET') return ['items' => nc_feed($who, 'sfw', (int)$q('before', 0), (string)$q('author'), ['q' => (string)$q('q'), 'tag' => (string)$q('tag')])];
            $in = json_decode((string)file_get_contents('php://input', false, null, 0, 20000), true) ?: [];
            return nc_create($who, ['text' => (string)($in['text'] ?? ''), 'rating' => 'sfw', 'parent' => (int)($in['parent'] ?? 0), 'media' => []]);
        }
    }
    throw new WaError('Unbekannter Endpunkt: ' . $method . ' /' . $path, 404);
}
/** Recht/Seite für den Schlüssel-Besitzer prüfen (nicht für die Sitzung des Aufrufers) */
function wa_can(array $u, string $perm): bool {
    $r = site_db()->prepare('SELECT is_system FROM roles WHERE id = ?'); $r->execute([(int)$u['role_id']]);
    if ((int)$r->fetchColumn() === 1) return true;
    $p = site_db()->prepare('SELECT COUNT(*) FROM role_permissions WHERE role_id = ? AND permission = ?'); $p->execute([(int)$u['role_id'], $perm]);
    return (int)$p->fetchColumn() > 0;
}
function wa_page_ok(array $p, array $u): bool {
    if (in_array($p['access'], ['public', 'users', 'members'], true)) return true;
    if (wa_can($u, '__system__')) return true;   // Systemrolle hat alles
    return in_array((string)$u['role_id'], array_filter(explode(',', (string)$p['allowed_roles'])), true);
}

/**
 * 8.1: API v2 – gleiche Endpunkte wie v1, aber einheitliche Hülle und Seiten:
 *   Liste:   {"data": [...], "page": {"limit": 25, "cursor": "…", "next": "…"|null, "total": 123}, "meta": {"version": 2}}
 *   Objekt:  {"data": {...}, "meta": {"version": 2}}
 *   Fehler:  {"error": {"status": 403, "message": "…"}, "meta": {"version": 2}}
 * Seiten: ?limit=1–100 (Standard 25) & ?cursor=<next aus der vorigen Antwort>. Der Cursor ist undurchsichtig (Base64), nicht selbst bauen.
 */
function wa_v2_cursor(int $off): string { return rtrim(strtr(base64_encode('o:' . $off), '+/', '-_'), '='); }
function wa_v2_offset(string $cursor): int {
    if ($cursor === '') return 0;
    $raw = base64_decode(strtr($cursor, '-_', '+/') . str_repeat('=', (4 - strlen($cursor) % 4) % 4), true);
    if ($raw === false || !preg_match('/^o:(\d{1,7})$/', $raw, $m)) throw new WaError('Ungültiger Cursor.', 400);
    return (int)$m[1];
}
function wa_v2(array $res, int $limit, string $cursor): array {
    $meta = ['version' => 2];
    if (isset($res['items']) && is_array($res['items']) && ($res['items'] === [] || array_keys($res['items']) === range(0, count($res['items']) - 1))) {
        $limit = max(1, min(100, $limit ?: 25));
        $off = wa_v2_offset($cursor);
        $all = $res['items']; $total = count($all);
        $page = array_slice($all, $off, $limit);
        $rest = $res; unset($rest['items']);
        if ($rest) $meta['extra'] = $rest;
        return ['data' => $page, 'page' => ['limit' => $limit, 'cursor' => $cursor !== '' ? $cursor : null, 'next' => $off + $limit < $total ? wa_v2_cursor($off + $limit) : null, 'total' => $total], 'meta' => $meta];
    }
    if (isset($res['version']) && ($res['service'] ?? '') === 'ghostnode-web-api') $res['version'] = 2;
    return ['data' => $res, 'meta' => $meta];
}
function wa_v2_error(string $msg, int $status): array { return ['error' => ['status' => $status, 'message' => $msg], 'meta' => ['version' => 2]]; }

/** 8.1 Bot-Feed: Versionen, Störungen und VRChat-News in einer einheitlichen Form, neueste zuerst. $since = nur Neueres (Unix-Zeit). */
function wa_bot_items(array $releases, array $incidents, array $news, int $since = 0, array $types = [], int $limit = 20): array {
    $base = function_exists('mk_base_url') ? mk_base_url() : '';
    $out = [];
    foreach ($releases as $r) {
        if (!empty($r['pre'])) continue;
        $t = strtotime((string)($r['date'] ?? '')) ?: 0;
        $out[] = ['type' => 'release', 'id' => 'release:' . $r['v'], 'time' => $t, 'title' => 'VRC Nexus ' . $r['v'], 'text' => 'Neue Version von VRC Nexus.', 'url' => (string)($r['url'] ?? '')];
    }
    foreach ($incidents as $i) {
        $out[] = ['type' => 'incident', 'id' => 'incident:' . $i['id'] . ':' . (int)$i['start'], 'time' => (int)$i['start'], 'title' => '⚠ Störung: ' . $i['name'], 'text' => (string)($i['msg'] ?? ''), 'url' => $base . '/status.php'];
        if (!empty($i['end'])) $out[] = ['type' => 'resolved', 'id' => 'resolved:' . $i['id'] . ':' . (int)$i['start'], 'time' => (int)$i['end'], 'title' => '✅ Wieder in Ordnung: ' . $i['name'], 'text' => '', 'url' => $base . '/status.php'];
    }
    foreach ($news as $n) $out[] = ['type' => 'news', 'id' => (string)$n['id'], 'time' => (int)($n['ts'] ?? 0), 'title' => (string)$n['title'], 'text' => (string)($n['text'] ?? ''), 'url' => (string)($n['url'] ?? ''), 'kind' => (string)($n['kind'] ?? '')];
    $out = array_values(array_filter($out, fn($x) => $x['time'] > $since && (!$types || in_array($x['type'], $types, true))));
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return array_slice($out, 0, $limit);
}
/** Dieselben Einträge als fertige Discord-Webhook-Nachricht (höchstens 10 Embeds) – Bots müssen nur weiterreichen. */
function wa_bot_discord(array $items): array {
    $col = ['release' => 0x38d5e6, 'incident' => 0xff5d73, 'resolved' => 0x4ade80, 'news' => 0xf3c262];
    return ['username' => 'GhostNode', 'embeds' => array_map(fn($x) => array_filter(['title' => mb_substr($x['title'], 0, 250), 'description' => mb_substr($x['text'], 0, 400),
        'url' => preg_match('#^https?://#', $x['url']) ? $x['url'] : null, 'color' => $col[$x['type']] ?? 0x888888, 'timestamp' => $x['time'] ? gmdate('c', $x['time']) : null], fn($v) => $v !== null && $v !== ''), array_slice(array_reverse($items), -10))];
}
