<?php
/**
 * 8.2.1: Anmeldung am Bot-Dashboard (node_discord) über das GhostNode-Dashboard – ohne OAuth2-Redirect bei Discord.
 * Die Webseite gibt ein signiertes Ticket aus (HMAC-SHA256 mit gemeinsamem Schlüssel, 60 s gültig, nur einmal nutzbar)
 * mit der verknüpften Discord-ID; welche Server die Person verwalten darf, prüft der Bot selbst mit seinem Token.
 * Schlüssel: Umgebungsvariable GN_BOT_SSO_SECRET (Plesk) oder verschlüsselt in den Einstellungen (Admin, einmal angezeigt).
 * Auf dem Bot steht derselbe Wert in BOT_SSO_SECRET.
 */
require_once __DIR__ . '/../core/crypto.php';

function botsso_secret(): string {
    $v = trim((string)getenv('GN_BOT_SSO_SECRET'));
    if ($v !== '') return $v;
    $enc = setting('botsso_secret', '');
    $s = $enc !== '' ? crypto_decrypt('botsso', $enc) : null;
    return is_string($s) ? $s : '';
}
function botsso_secret_source(): string { return getenv('GN_BOT_SSO_SECRET') ? 'env' : (setting('botsso_secret', '') !== '' ? 'admin' : ''); }
/** neuen Schlüssel erzeugen und verschlüsselt speichern – Rückgabe nur dieses eine Mal zum Kopieren */
function botsso_new_secret(): string {
    $k = rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
    set_setting('botsso_secret', crypto_encrypt('botsso', $k));
    return $k;
}
/** Adresse des Bot-Dashboards (https://… oder http://IP:Port) */
function botsso_url(): string {
    $u = rtrim(trim(setting('botsso_url', '')), '/');
    return botsso_url_ok($u) ? $u : '';
}
function botsso_url_ok(string $u): bool {
    $p = parse_url($u);
    return $u !== '' && strlen($u) <= 200 && $p && in_array($p['scheme'] ?? '', ['http', 'https'], true) && !empty($p['host']) && empty($p['query']) && empty($p['fragment']) && empty($p['user']) && (($p['path'] ?? '') === '' || ($p['path'] ?? '') === '/');
}
function botsso_ready(): bool { return botsso_url() !== '' && strlen(botsso_secret()) >= 32; }
function botsso_b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
/** Ticket für eine Discord-ID */
function botsso_ticket(string $discord_id, string $name, string $avatar = '', ?int $now = null, ?string $secret = null): string {
    if (!preg_match('/^\d{15,22}$/', $discord_id)) throw new InvalidArgumentException('Keine gültige Discord-ID.');
    $now = $now ?? time();
    $p = botsso_b64(json_encode(['v' => 1, 'd' => $discord_id, 'n' => mb_substr($name, 0, 64), 'a' => preg_match('/^(a_)?[0-9a-f]{32}$/', $avatar) ? $avatar : null,
        'e' => $now + 60, 'j' => bin2hex(random_bytes(16))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $p . '.' . botsso_b64(hash_hmac('sha256', $p, $secret ?? botsso_secret(), true));
}
