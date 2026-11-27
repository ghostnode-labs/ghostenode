<?php
/**
 * Notfall-Zugang über SSH (nur Kommandozeile, nicht über den Browser erreichbar).
 * Gedacht für den Server-Besitzer, wenn die Anmeldung auf der Webseite nicht mehr geht – wer root auf dem Server ist,
 * hat ohnehin vollen Zugriff; dieses Werkzeug macht es nur einfach und protokolliert jede Änderung.
 *
 *   php tools/login.php liste                     Konten mit Rolle, Status, Zwei-Faktor, letzter Anmeldung
 *   php tools/login.php entsperren                Sperre „Zu viele Fehlversuche“ für alle aufheben
 *   php tools/login.php passwort BENUTZER         neues Einmal-Passwort (muss beim nächsten Login geändert werden)
 *   php tools/login.php 2fa-aus BENUTZER          Zwei-Faktor-Anmeldung abschalten (z. B. Handy verloren)
 *   php tools/login.php aktivieren BENUTZER       gesperrtes/abgelaufenes Konto wieder freischalten
 * Auf dem Root-Server bequemer:  bash /root/ghostenode/server/ghostnode-root.sh login …
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once __DIR__ . '/../inc/core/common.php';
require_once __DIR__ . '/../inc/core/auth.php';

$args = array_slice($argv, 1);
$cmd = (string)(array_shift($args) ?? 'liste');
$user = (string)(array_shift($args) ?? '');
$say = function (string $s) { fwrite(STDOUT, $s . "\n"); };
$fail = function (string $s) { fwrite(STDERR, "✖ $s\n"); exit(1); };
$log = function (string $what, string $who) { try { if (function_exists('audit')) audit('Notfall-Zugang (SSH)', $what . ' ' . $who, 'security'); } catch (Throwable $e) { /* Protokoll optional */ } };

$db = site_db();
$find = function (string $name) use ($db, $fail): array {
    if ($name === '') $fail('Benutzername fehlt – „liste“ zeigt alle Konten.');
    $st = $db->prepare('SELECT * FROM users WHERE LOWER(username) = LOWER(?)');
    $st->execute([$name]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) $fail("Kein Konto „{$name}“ – „liste“ zeigt alle Konten.");
    return $u;
};

try {
    switch ($cmd) {
        case 'liste': case 'list':
            $rows = $db->query('SELECT u.*, r.name AS role_name, r.is_system FROM users u LEFT JOIN roles r ON r.id = u.role_id ORDER BY r.is_system DESC, u.username')->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) { $say('Noch kein Konto – auf der Webseite /admin.php das Besitzer-Konto anlegen.'); break; }
            $say(sprintf('%-24s %-22s %-8s %-4s %-6s %s', 'BENUTZER', 'ROLLE', 'STATUS', '2FA', 'PW-NEU', 'LETZTE ANMELDUNG'));
            foreach ($rows as $u) $say(sprintf('%-24s %-22s %-8s %-4s %-6s %s', mb_substr($u['username'], 0, 24), mb_substr(($u['is_system'] ? '★ ' : '') . ($u['role_name'] ?? '?'), 0, 22),
                $u['active'] ? 'aktiv' : 'GESPERRT', ($u['totp_secret'] ?? '') !== '' ? 'an' : '–', !empty($u['must_change_pw']) ? 'ja' : '–', $u['last_login'] ?: 'nie'));
            $n = (int)$db->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn();
            $say($n ? "\n$n Adresse(n) mit Fehlversuchen – aufheben mit: entsperren" : "\nKeine Sperren wegen Fehlversuchen.");
            break;
        case 'entsperren': case 'unlock':
            $n = $db->exec('DELETE FROM login_attempts');
            $log('Sperren aufgehoben', '');
            $say('✔ Sperre wegen Fehlversuchen aufgehoben (' . (int)$n . ' Einträge). Du kannst dich sofort wieder anmelden.');
            break;
        case 'passwort': case 'password':
            $u = $find($user);
            $alpha = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $pw = ''; for ($i = 0; $i < 16; $i++) $pw .= $alpha[random_int(0, strlen($alpha) - 1)];
            $db->prepare('UPDATE users SET password_hash = ?, must_change_pw = 1, session_version = session_version + 1, password_changed = ? WHERE id = ?')
               ->execute([password_hash($pw, PASSWORD_DEFAULT), date('Y-m-d H:i:s'), (int)$u['id']]);
            $db->exec('DELETE FROM login_attempts');
            $log('Passwort zurückgesetzt', $u['username']);
            $say("✔ Neues Einmal-Passwort für „{$u['username']}“ (wird nur jetzt angezeigt):\n\n    $pw\n");
            $say('Damit auf /admin.php anmelden – die Webseite verlangt danach sofort ein eigenes neues Passwort. Alle alten Sitzungen sind abgemeldet.');
            if (($u['totp_secret'] ?? '') !== '') $say('Hinweis: Zwei-Faktor ist an – Code aus der App nötig. Handy weg? Dann zusätzlich: 2fa-aus ' . $u['username']);
            if (!$u['active']) $say('Hinweis: Das Konto ist gesperrt – freischalten mit: aktivieren ' . $u['username']);
            break;
        case '2fa-aus': case '2fa-off':
            $u = $find($user);
            $db->prepare("UPDATE users SET totp_secret = '', totp_recovery = '[]', session_version = session_version + 1 WHERE id = ?")->execute([(int)$u['id']]);
            $log('Zwei-Faktor abgeschaltet', $u['username']);
            $say("✔ Zwei-Faktor-Anmeldung für „{$u['username']}“ ist aus. Nach dem Login unter „Mein Konto“ neu einrichten (Admins müssen das wieder tun).");
            break;
        case 'aktivieren': case 'activate':
            $u = $find($user);
            $db->prepare('UPDATE users SET active = 1, expires_at = NULL WHERE id = ?')->execute([(int)$u['id']]);
            $db->exec('DELETE FROM login_attempts');
            $log('Konto freigeschaltet', $u['username']);
            $say("✔ Konto „{$u['username']}“ ist wieder aktiv.");
            break;
        default:
            $say("Notfall-Zugang:\n  liste · entsperren · passwort BENUTZER · 2fa-aus BENUTZER · aktivieren BENUTZER");
            exit($cmd === 'hilfe' || $cmd === 'help' ? 0 : 1);
    }
} catch (Throwable $e) { $fail($e->getMessage()); }
