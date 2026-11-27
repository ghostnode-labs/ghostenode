<?php
declare(strict_types=1);
/**
 * 7.2 Twitch-Panel-Erweiterung „VRC Nexus“ – Backend (EBS) auf der GhostNode-Webseite.
 *
 *  Panel (Twitch, Zuschauer)  ──JWT──►  twitchext.php?a=state / vote      (nur mit gültigem Twitch-Token der Erweiterung)
 *  Twitch-Dashboard (Streamer) ──JWT──►  twitchext.php?a=link             (Verbindungscode gnx_… erzeugen, nur Rolle „broadcaster“)
 *  VRC-Nexus-App (PC)          ──Code──►  twitchext.php?a=push            (Stand alle 15 s, Antwort = Panel-Stimmen der laufenden Umfrage)
 *
 * Secrets nur als Umgebungsvariablen (Plesk): TWITCH_EXT_CLIENT_ID, TWITCH_EXT_SECRET (Base64, wie im Twitch-Dev-Console angezeigt).
 * Gespeichert wird nur der SHA-256 des Verbindungscodes, nie der Code selbst.
 */

function tx_db(): PDO {
    $db = db_get('vrchat');
    static $done = false;
    if (!$done) {
        $done = true;
        if (setting('twitchext_schema', '0') !== '1') {
            $db->exec("
                CREATE TABLE IF NOT EXISTS twitch_ext (channel_id TEXT PRIMARY KEY, key_hash TEXT NOT NULL DEFAULT '', state TEXT NOT NULL DEFAULT '', updated INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL DEFAULT 0);
                CREATE TABLE IF NOT EXISTS twitch_ext_votes (channel_id TEXT NOT NULL, poll INTEGER NOT NULL, uid TEXT NOT NULL, choice INTEGER NOT NULL, ts INTEGER NOT NULL, PRIMARY KEY (channel_id, poll, uid));
                CREATE INDEX IF NOT EXISTS idx_twitch_ext_key ON twitch_ext(key_hash);
            ");
            set_setting('twitchext_schema', '1');
        }
    }
    return $db;
}

final class TxError extends RuntimeException {}

function tx_client_id(): string { return preg_replace('/[^a-z0-9]/', '', strtolower((string)getenv('TWITCH_EXT_CLIENT_ID'))); }
function tx_secret(): string { $s = trim((string)getenv('TWITCH_EXT_SECRET')); $b = $s === '' ? false : base64_decode($s, true); return $b === false ? '' : $b; }
function tx_ready(): bool { return tx_secret() !== ''; }
function tx_b64url(string $s): string { $r = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); return $r === false ? '' : $r; }

/** Twitch-JWT prüfen (HS256 mit dem Erweiterungs-Secret) → channel_id, opaque_user_id, role */
function tx_jwt(string $header): array {
    $sec = tx_secret();
    if ($sec === '') throw new TxError('Die Twitch-Erweiterung ist auf dem Server noch nicht eingerichtet.');
    if (!preg_match('/^Bearer\s+([\w-]+)\.([\w-]+)\.([\w-]+)$/', trim($header), $m)) throw new TxError('Kein Twitch-Token.');
    $head = json_decode(tx_b64url($m[1]), true);
    if (!is_array($head) || ($head['alg'] ?? '') !== 'HS256') throw new TxError('Token ungültig.');
    if (!hash_equals(hash_hmac('sha256', $m[1] . '.' . $m[2], $sec, true), tx_b64url($m[3]))) throw new TxError('Token ungültig.');
    $p = json_decode(tx_b64url($m[2]), true);
    if (!is_array($p) || (int)($p['exp'] ?? 0) < time()) throw new TxError('Token abgelaufen.');
    $ch = (string)($p['channel_id'] ?? '');
    if (!preg_match('/^\d{1,20}$/', $ch)) throw new TxError('Kanal fehlt.');
    return ['channel_id' => $ch, 'uid' => (string)($p['opaque_user_id'] ?? ''), 'role' => (string)($p['role'] ?? 'viewer')];
}

/** CORS nur für die eigene Erweiterung (https://<client-id>.ext-twitch.tv) */
function tx_cors(): void {
    $o = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $cid = tx_client_id();
    $ok = $cid !== '' ? $o === 'https://' . $cid . '.ext-twitch.tv' : (bool)preg_match('#^https://[a-z0-9]+\.ext-twitch\.tv$#', $o);
    if ($ok) {
        header('Access-Control-Allow-Origin: ' . $o);
        header('Vary: Origin');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Max-Age: 600');
    }
}

/** Streamer: neuen Verbindungscode erzeugen (der alte wird ungültig) */
function tx_link(array $jwt): array {
    if ($jwt['role'] !== 'broadcaster') throw new TxError('Nur der Kanal selbst kann die Erweiterung verbinden.');
    $code = 'gnx_' . bin2hex(random_bytes(20));
    $db = tx_db();
    $ex = $db->prepare('SELECT 1 FROM twitch_ext WHERE channel_id = ?'); $ex->execute([$jwt['channel_id']]);
    if ($ex->fetch()) $db->prepare('UPDATE twitch_ext SET key_hash = ? WHERE channel_id = ?')->execute([hash('sha256', $code), $jwt['channel_id']]);
    else $db->prepare('INSERT INTO twitch_ext (channel_id, key_hash, state, updated, created) VALUES (?, ?, \'\', 0, ?)')->execute([$jwt['channel_id'], hash('sha256', $code), time()]);
    return ['code' => $code, 'msg' => 'Code in VRC Nexus eintragen: Stream-Bot → 📺 Leiste & Twitch-Panel.'];
}
function tx_link_status(array $jwt): array {
    if ($jwt['role'] !== 'broadcaster') throw new TxError('Nur für den Kanal selbst.');
    $st = tx_db()->prepare('SELECT key_hash, updated FROM twitch_ext WHERE channel_id = ?'); $st->execute([$jwt['channel_id']]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return ['linked' => (bool)($r && $r['key_hash'] !== ''), 'updated' => (int)($r['updated'] ?? 0), 'live' => $r && time() - (int)$r['updated'] < 120];
}

/** nur bekannte Felder mit Längen-Grenzen übernehmen (das Panel zeigt alles nur als Text an) */
function tx_clean_state(array $s): array {
    $t = fn($v, int $n) => mb_substr(trim(str_replace(["\r", "\n"], ' ', (string)$v)), 0, $n);
    $o = ['v' => 1, 't' => time(), 'name' => $t($s['name'] ?? 'Punkte', 20), 'prefix' => $t($s['prefix'] ?? '!', 2), 'live' => !empty($s['live']), 'running' => !empty($s['running'])];
    if (isset($s['world'])) { $o['world'] = $t($s['world'], 80); $o['players'] = max(0, min(200, (int)($s['players'] ?? 0))); $o['since'] = max(0, (int)($s['since'] ?? 0)); }
    if (isset($s['heart'])) $o['heart'] = max(0, min(250, (int)$s['heart']));
    if (isset($s['music'])) $o['music'] = $t($s['music'], 120);
    foreach (['top' => 5, 'commands' => 25, 'rewards' => 20, 'recent' => 8] as $k => $n) {
        if (!isset($s[$k]) || !is_array($s[$k])) continue;
        $o[$k] = [];
        foreach (array_slice($s[$k], 0, $n) as $x) {
            if (!is_array($x)) continue;
            if ($k === 'top') $o[$k][] = ['name' => $t($x['name'] ?? '', 30), 'p' => (int)($x['p'] ?? 0)];
            if ($k === 'commands') $o[$k][] = ['name' => $t($x['name'] ?? '', 24), 'usage' => $t($x['usage'] ?? '', 40), 'cost' => max(0, (int)($x['cost'] ?? 0)), 'text' => $t($x['text'] ?? '', 90)];
            if ($k === 'rewards') $o[$k][] = ['name' => $t($x['name'] ?? '', 24), 'cost' => max(0, (int)($x['cost'] ?? 0))];
            if ($k === 'recent') $o[$k][] = ['kind' => preg_replace('/[^a-z]/', '', (string)($x['kind'] ?? '')), 'label' => $t($x['label'] ?? '', 30), 'user' => $t($x['user'] ?? '', 40), 'menge' => $t($x['menge'] ?? '', 20)];
        }
    }
    if (isset($s['poll']) && is_array($s['poll'])) {
        $p = $s['poll'];
        $o['poll'] = ['id' => (int)($p['id'] ?? 0), 'q' => $t($p['q'] ?? '', 120), 'total' => max(0, (int)($p['total'] ?? 0)), 'options' => []];
        foreach (array_slice((array)($p['options'] ?? []), 0, 10) as $x) $o['poll']['options'][] = ['text' => $t(is_array($x) ? ($x['text'] ?? '') : '', 60), 'votes' => max(0, (int)(is_array($x) ? ($x['votes'] ?? 0) : 0))];
    }
    if (isset($s['goal']) && is_array($s['goal'])) $o['goal'] = ['text' => $t($s['goal']['text'] ?? '', 40), 'cur' => max(0, (int)($s['goal']['cur'] ?? 0)), 'max' => max(1, (int)($s['goal']['max'] ?? 1))];
    if (isset($s['discord']) && preg_match('#^https://(discord\.gg|discord\.com/invite)/[\w-]{2,40}$#', (string)$s['discord'])) $o['discord'] = (string)$s['discord'];
    return $o;
}

/** App → Webseite: Stand speichern, Stimmen der laufenden Umfrage zurückgeben */
function tx_push(array $in): array {
    $key = (string)($in['key'] ?? '');
    if (!preg_match('/^gnx_[a-f0-9]{40}$/', $key)) throw new TxError('Verbindungscode ungültig.');
    $db = tx_db();
    $st = $db->prepare('SELECT channel_id FROM twitch_ext WHERE key_hash = ?'); $st->execute([hash('sha256', $key)]);
    $ch = $st->fetchColumn();
    if ($ch === false) throw new TxError('Verbindungscode unbekannt – im Twitch-Dashboard einen neuen erzeugen.');
    $state = tx_clean_state(is_array($in['state'] ?? null) ? $in['state'] : []);
    $db->prepare('UPDATE twitch_ext SET state = ?, updated = ? WHERE channel_id = ?')->execute([json_encode($state, JSON_UNESCAPED_UNICODE), time(), $ch]);
    $votes = [];
    if (!empty($state['poll']['id'])) {
        $db->prepare('DELETE FROM twitch_ext_votes WHERE channel_id = ? AND poll <> ?')->execute([$ch, $state['poll']['id']]);   // alte Umfragen aufräumen
        $v = $db->prepare('SELECT poll, uid, choice FROM twitch_ext_votes WHERE channel_id = ? AND poll = ? LIMIT 5000'); $v->execute([$ch, $state['poll']['id']]);
        foreach ($v->fetchAll(PDO::FETCH_ASSOC) as $r) $votes[] = ['poll' => (int)$r['poll'], 'uid' => $r['uid'], 'option' => (int)$r['choice']];
    } else $db->prepare('DELETE FROM twitch_ext_votes WHERE channel_id = ?')->execute([$ch]);
    return ['ok' => true, 'votes' => $votes];
}

/** Panel: aktueller Stand des Kanals (älter als 10 Minuten = offline) */
function tx_state(array $jwt): array {
    $st = tx_db()->prepare('SELECT state, updated FROM twitch_ext WHERE channel_id = ?'); $st->execute([$jwt['channel_id']]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || $r['state'] === '') return ['offline' => true, 'linked' => (bool)$r];
    $s = json_decode($r['state'], true) ?: [];
    $s['offline'] = time() - (int)$r['updated'] > 600;
    $s['age'] = time() - (int)$r['updated'];
    if (!empty($s['poll']['id']) && $jwt['uid'] !== '') {
        $v = tx_db()->prepare('SELECT choice FROM twitch_ext_votes WHERE channel_id = ? AND poll = ? AND uid = ?'); $v->execute([$jwt['channel_id'], $s['poll']['id'], $jwt['uid']]);
        $c = $v->fetchColumn();
        $s['poll']['mine'] = $c === false ? -1 : (int)$c;
    }
    return $s;
}

/** Panel: Stimme abgeben (nur angemeldete Zuschauer, eine Stimme je Umfrage, umentscheiden erlaubt) */
function tx_vote(array $jwt, array $in): array {
    if (!preg_match('/^U[\w-]{2,62}$/', $jwt['uid'])) throw new TxError('Zum Abstimmen bitte bei Twitch anmelden.');
    $s = tx_state($jwt);
    if (!empty($s['offline']) || empty($s['poll']['id'])) throw new TxError('Gerade läuft keine Umfrage.');
    if ((int)($in['poll'] ?? 0) !== (int)$s['poll']['id']) throw new TxError('Diese Umfrage ist schon vorbei.');
    $opt = (int)($in['option'] ?? -1);
    if ($opt < 0 || $opt >= count($s['poll']['options'])) throw new TxError('Unbekannte Antwort.');
    $db = tx_db();
    $db->prepare('DELETE FROM twitch_ext_votes WHERE channel_id = ? AND poll = ? AND uid = ?')->execute([$jwt['channel_id'], $s['poll']['id'], $jwt['uid']]);
    $db->prepare('INSERT INTO twitch_ext_votes (channel_id, poll, uid, choice, ts) VALUES (?, ?, ?, ?, ?)')->execute([$jwt['channel_id'], $s['poll']['id'], $jwt['uid'], $opt, time()]);
    return ['ok' => true, 'mine' => $opt, 'msg' => 'Stimme gezählt – erscheint mit der nächsten Aktualisierung.'];
}
