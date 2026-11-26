<?php
/**
 * GhostNode-Konten für alle: offene Registrierung (optional), Anmeldung mit Discord, Discord-Verknüpfung.
 *  - Registrierungsmodus (Admin → System): aus | nur Einladung | offen (mit Standardrolle)
 *  - Discord-Anmeldung über OAuth2 (Scope „identify“, optional „email“), Zustand per Einmal-„state“ in der Sitzung
 *  - Client-ID/Secret: Umgebungsvariablen GN_DISCORD_CLIENT_ID / GN_DISCORD_CLIENT_SECRET (Plesk), sonst im Admin
 *    eingetragen (Secret verschlüsselt mit crypto_encrypt, wird nie wieder angezeigt)
 *  - Konten, die über Discord entstanden sind, haben zunächst kein Passwort (zufälliger Hash) und können eins setzen.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/crypto.php';

const ACC_DISCORD_API = 'https://discord.com/api/v10';
const ACC_REG_MODES = ['off' => 'Aus (nur Admins legen Konten an)', 'invite' => 'Nur mit Einladungscode', 'open' => 'Offen für alle'];

/** Spalten für E-Mail & Discord (einmalig, SQLite und MySQL) */
function acc_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (setting('acc_schema', '0') === '1') return;
    $db = site_db();
    try { $have = db_columns($db, 'users'); } catch (Throwable $e) { $have = []; }
    $cols = ['email' => "TEXT NOT NULL DEFAULT ''", 'discord_id' => "TEXT NOT NULL DEFAULT ''", 'discord_name' => "TEXT NOT NULL DEFAULT ''",
             'discord_avatar' => "TEXT NOT NULL DEFAULT ''", 'pw_set' => 'INTEGER NOT NULL DEFAULT 1', 'registered_via' => "TEXT NOT NULL DEFAULT ''"];
    foreach ($cols as $k => $def) if (!in_array($k, $have, true)) { try { $db->exec("ALTER TABLE users ADD COLUMN $k $def"); } catch (Throwable $e) { /* schon da */ } }
    set_setting('acc_schema', '1');
}

function acc_reg_mode(): string { $m = setting('reg_mode', 'invite'); return isset(ACC_REG_MODES[$m]) ? $m : 'invite'; }
function acc_reg_role(): ?array {
    $id = (int)setting('reg_role', '0');
    if ($id <= 0) return null;
    $st = site_db()->prepare('SELECT * FROM roles WHERE id = ?'); $st->execute([$id]);
    $r = $st->fetch();
    return $r && !(int)$r['is_system'] ? $r : null;   // nie automatisch Administrator
}
function acc_open(): bool { return acc_reg_mode() === 'open' && acc_reg_role() !== null; }

// ---------- Discord-OAuth ----------
function acc_discord_client_id(): string { $v = (string)getenv('GN_DISCORD_CLIENT_ID'); return preg_match('/^\d{15,22}$/', $v) ? $v : (preg_match('/^\d{15,22}$/', setting('dlogin_client_id', '')) ? setting('dlogin_client_id', '') : ''); }
function acc_discord_secret(): string {
    $v = (string)getenv('GN_DISCORD_CLIENT_SECRET');
    if ($v !== '') return $v;
    $enc = setting('dlogin_secret', '');
    $s = $enc !== '' ? crypto_decrypt('dlogin', $enc) : null;
    return is_string($s) ? $s : '';
}
function acc_discord_secret_source(): string { return getenv('GN_DISCORD_CLIENT_SECRET') ? 'env' : (setting('dlogin_secret', '') !== '' ? 'admin' : ''); }
function acc_discord_ready(): bool { return setting('dlogin_on', '0') === '1' && acc_discord_client_id() !== '' && acc_discord_secret() !== ''; }
function acc_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}
function acc_redirect_uri(): string { $f = setting('dlogin_redirect', ''); return preg_match('#^https://[\w.\-]+(:\d+)?/\S*$#', $f) ? $f : acc_base_url() . '/auth-discord.php'; }

/** Startet die Discord-Anmeldung: intent login | link | register */
function acc_discord_start(string $intent, string $next = ''): string {
    auth_session();
    $state = bin2hex(random_bytes(16));
    $_SESSION['dlogin'] = ['state' => $state, 'intent' => in_array($intent, ['login', 'link', 'register'], true) ? $intent : 'login', 'next' => $next, 't' => time()];
    return 'https://discord.com/oauth2/authorize?' . http_build_query(['client_id' => acc_discord_client_id(), 'response_type' => 'code', 'redirect_uri' => acc_redirect_uri(),
        'scope' => 'identify' . (setting('dlogin_email', '0') === '1' ? ' email' : ''), 'state' => $state, 'prompt' => 'none']);
}

/** HTTP-Anfrage an Discord (für Tests über GN_DISCORD_API umlenkbar) */
function acc_discord_http(string $method, string $path, array $form = null, string $bearer = ''): array {
    $base = rtrim((string)(getenv('GN_DISCORD_API') ?: ACC_DISCORD_API), '/');
    $ch = curl_init($base . $path);
    $h = ['Accept: application/json', 'User-Agent: GhostNode-Login (ghostnode-labs.dev, 1.0)'];
    if ($bearer !== '') $h[] = 'Authorization: Bearer ' . $bearer;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $h, CURLOPT_CUSTOMREQUEST => $method]);
    if ($form !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$raw, true);
    if ($code < 200 || $code >= 300 || !is_array($j)) throw new InvalidArgumentException('Discord hat die Anmeldung abgelehnt (' . $code . ').');
    return $j;
}

/** Rückkehr von Discord: liefert [discord_user, intent, next] oder wirft */
function acc_discord_finish(string $code, string $state): array {
    auth_session();
    $s = $_SESSION['dlogin'] ?? null;
    unset($_SESSION['dlogin']);
    if (!$s || !hash_equals((string)$s['state'], $state) || time() - (int)$s['t'] > 600) throw new InvalidArgumentException('Die Anmeldung ist abgelaufen – bitte noch einmal versuchen.');
    if (!preg_match('/^[A-Za-z0-9]{10,64}$/', $code)) throw new InvalidArgumentException('Ungültige Antwort von Discord.');
    $tok = acc_discord_http('POST', '/oauth2/token', ['client_id' => acc_discord_client_id(), 'client_secret' => acc_discord_secret(), 'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => acc_redirect_uri()]);
    $me = acc_discord_http('GET', '/users/@me', null, (string)($tok['access_token'] ?? ''));
    if (!preg_match('/^\d{15,22}$/', (string)($me['id'] ?? ''))) throw new InvalidArgumentException('Discord-Konto nicht lesbar.');
    return [$me, $s['intent'], (string)$s['next']];
}

function acc_discord_label(array $me): string { $g = trim((string)($me['global_name'] ?? '')); return mb_substr($g !== '' ? $g : (string)($me['username'] ?? 'Discord'), 0, 60); }
function acc_discord_avatar(array $me): string {
    return !empty($me['avatar']) && preg_match('/^[a-f0-9_]{6,40}$/', (string)$me['avatar']) ? 'https://cdn.discordapp.com/avatars/' . $me['id'] . '/' . $me['avatar'] . '.png?size=128' : '';
}

function acc_by_discord(string $id): ?array {
    acc_schema();
    $st = site_db()->prepare("SELECT id FROM users WHERE discord_id = ? AND discord_id <> ''"); $st->execute([$id]);
    $uid = $st->fetchColumn();
    return $uid ? user_load((int)$uid) : null;
}
function acc_link_discord(int $uid, array $me): void {
    acc_schema();
    $other = acc_by_discord((string)$me['id']);
    if ($other && (int)$other['id'] !== $uid) throw new InvalidArgumentException('Dieses Discord-Konto ist schon mit einem anderen GhostNode-Konto verknüpft.');
    site_db()->prepare('UPDATE users SET discord_id = ?, discord_name = ?, discord_avatar = ? WHERE id = ?')->execute([(string)$me['id'], acc_discord_label($me), acc_discord_avatar($me), $uid]);
}
function acc_unlink_discord(array $u): void {
    acc_schema();
    if (!(int)($u['pw_set'] ?? 1)) throw new InvalidArgumentException('Setze erst ein Passwort – sonst kannst du dich nicht mehr anmelden.');
    site_db()->prepare("UPDATE users SET discord_id = '', discord_name = '', discord_avatar = '' WHERE id = ?")->execute([(int)$u['id']]);
}

/** freien Benutzernamen aus einem Wunsch ableiten */
function acc_free_username(string $want): string {
    $base = preg_replace('/[^\p{L}\p{N}_.\-]/u', '', str_replace(' ', '_', $want));
    $base = mb_substr($base !== '' && mb_strlen($base) >= 3 ? $base : 'user_' . $base, 0, 26);
    $st = site_db()->prepare('SELECT 1 FROM users WHERE username = ? COLLATE NOCASE');
    for ($i = 0; $i < 50; $i++) {
        $name = $i === 0 ? $base : $base . '_' . ($i < 10 ? $i : random_int(100, 9999));
        $st->execute([$name]);
        if (!$st->fetchColumn()) return $name;
    }
    return 'user_' . bin2hex(random_bytes(4));
}

/** Konto über Discord anlegen (nur bei offener Registrierung) */
function acc_create_from_discord(array $me): array {
    acc_schema();
    $role = acc_reg_role();
    if (acc_reg_mode() !== 'open' || !$role) throw new InvalidArgumentException('Neue Konten sind gerade nur mit Einladung möglich.');
    $user = acc_free_username((string)($me['username'] ?? 'discord'));
    $id = auth_create($user, bin2hex(random_bytes(24)), (int)$role['id'], acc_discord_label($me));
    $email = !empty($me['verified']) && filter_var((string)($me['email'] ?? ''), FILTER_VALIDATE_EMAIL) ? mb_substr((string)$me['email'], 0, 190) : '';
    site_db()->prepare("UPDATE users SET pw_set = 0, registered_via = 'discord', email = ?, note = ? WHERE id = ?")->execute([$email, 'Registriert mit Discord', $id]);
    acc_link_discord($id, $me);
    $u = user_load($id);
    audit('Registriert mit Discord', $user . ' · Rolle ' . $role['name'], 'security', $u);
    return $u;
}

/** offene Registrierung mit Benutzername + Passwort; liefert Fehlertext oder das Konto */
function acc_register(array $in) {
    acc_schema();
    $role = acc_reg_role();
    if (!acc_open() || !$role) return 'Die Registrierung ist gerade geschlossen.';
    if (trim((string)($in['website'] ?? '')) !== '') return 'Anfrage abgelehnt.';                // Honigtopf-Feld für Bots
    if (time() - (int)($in['t'] ?? 0) < 3) return 'Bitte noch einmal absenden.';                   // zu schnell = Bot
    if (empty($in['accept'])) return 'Bitte die Regeln bestätigen.';
    $u = trim((string)($in['username'] ?? ''));
    $err = auth_validate($u, (string)($in['password'] ?? ''), (string)($in['password2'] ?? ''));
    if ($err) return $err;
    $email = trim((string)($in['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Die E-Mail-Adresse sieht ungültig aus.';
    if (setting('reg_email', '0') === '1' && $email === '') return 'Bitte eine E-Mail-Adresse angeben.';
    $d = site_db()->prepare('SELECT 1 FROM users WHERE username = ? COLLATE NOCASE'); $d->execute([$u]);
    if ($d->fetchColumn()) return 'Den Benutzernamen gibt es schon.';
    // höchstens 3 Registrierungen pro IP und Tag
    $k = 'reg_ip_' . hash('sha256', client_ip() . date('Y-m-d'));
    $n = (int)setting($k, '0');
    if ($n >= 3) return 'Heute wurden von hier schon genug Konten erstellt.';
    set_setting($k, (string)($n + 1));
    $id = auth_create($u, (string)$in['password'], (int)$role['id'], mb_substr(trim((string)($in['display_name'] ?? '')), 0, 60));
    site_db()->prepare("UPDATE users SET email = ?, registered_via = 'form', note = 'Selbst registriert' WHERE id = ?")->execute([mb_substr($email, 0, 190), $id]);
    $nu = user_load($id);
    audit('Selbst registriert', $u . ' · Rolle ' . $role['name'], 'security', $nu);
    return $nu;
}

/** Passwort für Discord-Konten setzen (ohne altes Passwort) */
function acc_set_first_password(array $u, string $p, string $p2): string {
    if ((int)($u['pw_set'] ?? 1)) return 'Du hast schon ein Passwort – ändere es unter „Passwort ändern“.';
    $err = auth_validate_password($p, $p2, (string)$u['username']);
    if ($err) return $err;
    site_db()->prepare("UPDATE users SET password_hash = ?, pw_set = 1, password_changed = datetime('now','localtime') WHERE id = ?")->execute([password_hash($p, PASSWORD_DEFAULT), (int)$u['id']]);
    audit('Passwort gesetzt (Discord-Konto)', '', 'security', $u);
    return '';
}
