<?php
/**
 * 🔗 „Mit GhostNode anmelden“ (8.1) – OAuth 2.0 (Autorisierungscode + PKCE) für Fremd-Apps.
 *   oauth.php?r=authorize   Zustimmungs-Seite (angemeldet)
 *   oauth.php?r=token       POST: code → access_token (ein API-Schlüssel „gno_…“, nur als SHA-256 gespeichert)
 *   oauth.php?r=userinfo    GET mit Bearer: id, Name, Anzeigename
 *   oauth.php?r=revoke      POST: token widerrufen
 * Apps legt man im Entwickler-Portal an (höchstens 10 je Konto). Geheimnis nur als Hash; öffentliche Apps (ohne Geheimnis) müssen PKCE (S256) nutzen.
 * Zugriffs-Token liegen in api_keys (Präfix „gno_“) und erscheinen damit auch unter „API-Schlüssel“ – dort jederzeit widerrufbar.
 */
require_once __DIR__ . '/webapi.php';

const OA_CODE_TTL = 300;

function oa_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    wa_schema();
    if (setting('oauth_schema', '0') === '1') return;
    $db = site_db();
    $db->exec('CREATE TABLE IF NOT EXISTS oauth_apps (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, name TEXT NOT NULL, client_id TEXT NOT NULL,
        secret_hash TEXT NOT NULL DEFAULT \'\', redirects TEXT NOT NULL, homepage TEXT NOT NULL DEFAULT \'\', created INTEGER NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS oauth_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, code_hash TEXT NOT NULL, app_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
        scopes TEXT NOT NULL, redirect TEXT NOT NULL, challenge TEXT NOT NULL DEFAULT \'\', expires INTEGER NOT NULL, used INTEGER NOT NULL DEFAULT 0)');
    $db->exec('CREATE TABLE IF NOT EXISTS oauth_grants (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, app_id INTEGER NOT NULL, key_id INTEGER NOT NULL, scopes TEXT NOT NULL, created INTEGER NOT NULL)');
    set_setting('oauth_schema', '1');
}

/** Weiterleitungs-Adressen prüfen: https (oder http://localhost / 127.0.0.1 zum Entwickeln), ohne Fragment, höchstens 5. */
function oa_clean_redirects(string $raw): array {
    $out = [];
    foreach (preg_split('/[\s,]+/', trim($raw)) as $r) {
        if ($r === '') continue;
        $p = parse_url($r);
        if (!$p || empty($p['scheme']) || empty($p['host']) || isset($p['fragment']) || strlen($r) > 300) throw new InvalidArgumentException('Ungültige Weiterleitungs-Adresse: ' . $r);
        $local = in_array(strtolower($p['host']), ['localhost', '127.0.0.1', '[::1]'], true);
        if ($p['scheme'] !== 'https' && !($p['scheme'] === 'http' && $local)) throw new InvalidArgumentException('Nur https:// (oder http://localhost) erlaubt: ' . $r);
        $out[] = $r;
    }
    $out = array_values(array_unique($out));
    if (!$out) throw new InvalidArgumentException('Mindestens eine Weiterleitungs-Adresse angeben.');
    if (count($out) > 5) throw new InvalidArgumentException('Höchstens 5 Weiterleitungs-Adressen.');
    return $out;
}

function oa_apps(int $uid): array {
    oa_schema();
    $st = site_db()->prepare('SELECT * FROM oauth_apps WHERE user_id = ? ORDER BY id DESC');
    $st->execute([$uid]);
    return array_map('oa_row', $st->fetchAll(PDO::FETCH_ASSOC));
}
function oa_row(array $a): array { $a['redirects'] = json_decode((string)$a['redirects'], true) ?: []; $a['public'] = $a['secret_hash'] === ''; return $a; }
function oa_app_by_client(string $cid): ?array {
    oa_schema();
    if (!preg_match('/^gnc_[0-9a-f]{24}$/', $cid)) return null;
    $st = site_db()->prepare('SELECT * FROM oauth_apps WHERE client_id = ?');
    $st->execute([$cid]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    return $a ? oa_row($a) : null;
}

/** Neue App → [App, Geheimnis oder ''] (das Geheimnis gibt es nur dieses eine Mal zu sehen). */
function oa_app_create(int $uid, string $name, string $redirects, bool $confidential, string $homepage = ''): array {
    oa_schema();
    $name = mb_substr(trim(strip_tags($name)), 0, 40);
    if ($name === '') throw new InvalidArgumentException('Bitte einen Namen angeben.');
    if (count(oa_apps($uid)) >= 10) throw new InvalidArgumentException('Höchstens 10 Apps – bitte erst eine löschen.');
    $red = oa_clean_redirects($redirects);
    $homepage = trim($homepage);
    if ($homepage !== '' && !preg_match('#^https://[^\s<>"]{3,200}$#', $homepage)) throw new InvalidArgumentException('Webseite muss mit https:// beginnen.');
    $cid = 'gnc_' . bin2hex(random_bytes(12));
    $secret = $confidential ? 'gns_' . bin2hex(random_bytes(24)) : '';
    site_db()->prepare('INSERT INTO oauth_apps (user_id, name, client_id, secret_hash, redirects, homepage, created) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$uid, $name, $cid, $secret === '' ? '' : hash('sha256', $secret), json_encode($red, JSON_UNESCAPED_SLASHES), $homepage, time()]);
    audit('OAuth-App angelegt', $name . ' (' . $cid . ')');
    return [oa_app_by_client($cid), $secret];
}
/** App löschen – widerruft auch alle ausgegebenen Token. */
function oa_app_delete(int $uid, int $id): void {
    oa_schema();
    $db = site_db();
    $st = $db->prepare('SELECT id FROM oauth_apps WHERE id = ? AND user_id = ?'); $st->execute([$id, $uid]);
    if (!$st->fetchColumn()) return;
    $g = $db->prepare('SELECT key_id FROM oauth_grants WHERE app_id = ?'); $g->execute([$id]);
    foreach ($g->fetchAll(PDO::FETCH_COLUMN) as $kid) $db->prepare('DELETE FROM api_keys WHERE id = ?')->execute([(int)$kid]);
    $db->prepare('DELETE FROM oauth_grants WHERE app_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM oauth_codes WHERE app_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM oauth_apps WHERE id = ?')->execute([$id]);
    audit('OAuth-App gelöscht', '#' . $id);
}

/** Prüft eine authorize-Anfrage → [App, Scopes, Weiterleitung, state, challenge]; wirft bei Fehlern (dann NICHT weiterleiten). */
function oa_check_request(array $q): array {
    $app = oa_app_by_client((string)($q['client_id'] ?? ''));
    if (!$app) throw new InvalidArgumentException('Unbekannte App (client_id).');
    $red = (string)($q['redirect_uri'] ?? '');
    if ($red === '' && count($app['redirects']) === 1) $red = $app['redirects'][0];
    if (!in_array($red, $app['redirects'], true)) throw new InvalidArgumentException('Diese Weiterleitungs-Adresse ist für die App nicht eingetragen.');
    if ((string)($q['response_type'] ?? 'code') !== 'code') throw new InvalidArgumentException('Nur response_type=code wird unterstützt.');
    $scopes = array_values(array_intersect(array_keys(WA_SCOPES), preg_split('/[\s,+]+/', (string)($q['scope'] ?? 'read')))) ?: ['read'];
    $ch = (string)($q['code_challenge'] ?? '');
    if ($ch !== '') {
        if ((string)($q['code_challenge_method'] ?? 'S256') !== 'S256') throw new InvalidArgumentException('Nur code_challenge_method=S256.');
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $ch)) throw new InvalidArgumentException('code_challenge ungültig.');
    } elseif ($app['public']) throw new InvalidArgumentException('Öffentliche Apps müssen PKCE (code_challenge) nutzen.');
    return [$app, $scopes, $red, mb_substr((string)($q['state'] ?? ''), 0, 200), $ch];
}
function oa_redirect_url(string $red, array $params): string { return $red . (strpos($red, '?') === false ? '?' : '&') . http_build_query($params); }

/** Zustimmung erteilt → Code (5 min gültig, einmalig). */
function oa_issue_code(array $app, int $uid, array $scopes, string $red, string $challenge): string {
    oa_schema();
    $code = bin2hex(random_bytes(24));
    site_db()->prepare('DELETE FROM oauth_codes WHERE expires < ?')->execute([time() - 3600]);
    site_db()->prepare('INSERT INTO oauth_codes (code_hash, app_id, user_id, scopes, redirect, challenge, expires) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $code), (int)$app['id'], $uid, implode(',', $scopes), $red, $challenge, time() + OA_CODE_TTL]);
    return $code;
}

class OaError extends RuntimeException { public string $err; public int $status; public function __construct(string $err, string $m, int $s = 400) { parent::__construct($m); $this->err = $err; $this->status = $s; } }

/** Token-Endpunkt: code (+ secret oder verifier) → access_token. */
function oa_token(array $p, string $basicUser = '', string $basicPass = ''): array {
    oa_schema();
    if ((string)($p['grant_type'] ?? '') !== 'authorization_code') throw new OaError('unsupported_grant_type', 'Nur grant_type=authorization_code.');
    $app = oa_app_by_client($basicUser !== '' ? $basicUser : (string)($p['client_id'] ?? ''));
    if (!$app) throw new OaError('invalid_client', 'Unbekannte App.', 401);
    $secret = $basicUser !== '' ? $basicPass : (string)($p['client_secret'] ?? '');
    if (!$app['public'] && !hash_equals($app['secret_hash'], hash('sha256', $secret))) throw new OaError('invalid_client', 'Falsches Geheimnis.', 401);
    $db = site_db();
    $st = $db->prepare('SELECT * FROM oauth_codes WHERE code_hash = ? AND app_id = ?');
    $st->execute([hash('sha256', (string)($p['code'] ?? '')), (int)$app['id']]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c || (int)$c['expires'] < time()) throw new OaError('invalid_grant', 'Code ungültig oder abgelaufen.');
    if ((int)$c['used']) {   // Wiederverwendung = möglicher Diebstahl → alles aus diesem Code widerrufen
        oa_revoke_grants((int)$c['user_id'], (int)$app['id']);
        throw new OaError('invalid_grant', 'Code wurde schon benutzt.');
    }
    if ((string)($p['redirect_uri'] ?? $c['redirect']) !== $c['redirect']) throw new OaError('invalid_grant', 'redirect_uri passt nicht.');
    if ($c['challenge'] !== '') {
        $v = (string)($p['code_verifier'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $v) || !hash_equals($c['challenge'], rtrim(strtr(base64_encode(hash('sha256', $v, true)), '+/', '-_'), '='))) throw new OaError('invalid_grant', 'code_verifier passt nicht.');
    }
    $upd = $db->prepare('UPDATE oauth_codes SET used = 1 WHERE id = ? AND used = 0');
    $upd->execute([(int)$c['id']]);
    if ($upd->rowCount() !== 1) throw new OaError('invalid_grant', 'Code wurde schon benutzt.');
    $u = user_load((int)$c['user_id']);
    if (!$u || (isset($u['active']) && !(int)$u['active'])) throw new OaError('invalid_grant', 'Konto nicht verfügbar.');
    $tok = 'gno_' . bin2hex(random_bytes(20));
    $db->prepare('INSERT INTO api_keys (user_id, name, prefix, key_hash, scopes, created) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([(int)$c['user_id'], mb_substr('🔗 ' . $app['name'], 0, 40), substr($tok, 0, 10), hash('sha256', $tok), $c['scopes'], time()]);
    $kid = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO oauth_grants (user_id, app_id, key_id, scopes, created) VALUES (?, ?, ?, ?, ?)')->execute([(int)$c['user_id'], (int)$app['id'], $kid, $c['scopes'], time()]);
    return ['access_token' => $tok, 'token_type' => 'Bearer', 'scope' => str_replace(',', ' ', $c['scopes'])];
}

/** Verbundene Apps eines Kontos (für „Meine Daten“). */
function oa_grants(int $uid): array {
    oa_schema();
    $st = site_db()->prepare('SELECT g.id, g.app_id, g.scopes, g.created, a.name, a.homepage, k.last_used FROM oauth_grants g JOIN oauth_apps a ON a.id = g.app_id LEFT JOIN api_keys k ON k.id = g.key_id WHERE g.user_id = ? ORDER BY g.id DESC');
    $st->execute([$uid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
/** Alle Token einer App für ein Konto widerrufen (oder aller Apps, wenn $appId = 0). */
function oa_revoke_grants(int $uid, int $appId = 0): int {
    oa_schema();
    $db = site_db();
    $st = $db->prepare('SELECT id, key_id FROM oauth_grants WHERE user_id = ?' . ($appId ? ' AND app_id = ?' : ''));
    $st->execute($appId ? [$uid, $appId] : [$uid]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) {
        $db->prepare('DELETE FROM api_keys WHERE id = ? AND user_id = ?')->execute([(int)$g['key_id'], $uid]);
        $db->prepare('DELETE FROM oauth_grants WHERE id = ?')->execute([(int)$g['id']]);
        $n++;
    }
    return $n;
}
/** Einzelnes Token widerrufen (RFC 7009: immer „ok“, auch wenn unbekannt). */
function oa_revoke_token(string $tok): void {
    if (!preg_match('/^gno_[0-9a-f]{40}$/', $tok)) return;
    oa_schema();
    $db = site_db();
    $st = $db->prepare('SELECT id FROM api_keys WHERE key_hash = ?'); $st->execute([hash('sha256', $tok)]);
    $kid = (int)$st->fetchColumn();
    if (!$kid) return;
    $db->prepare('DELETE FROM api_keys WHERE id = ?')->execute([$kid]);
    $db->prepare('DELETE FROM oauth_grants WHERE key_id = ?')->execute([$kid]);
}
