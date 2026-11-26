<?php
/**
 * Anmeldung mit Discord (OAuth2): ?go=login|link|register startet, Discord schickt mit ?code&state hierher zurück.
 * Verknüpfte Konten werden angemeldet (Zwei-Faktor gilt weiter), sonst – bei offener Registrierung – neu angelegt.
 */
require_once __DIR__ . '/inc/core/accounts.php';

ip_block_check();
acc_schema();
$back = function (string $to, string $msg = '', bool $err = true) {
    header('Location: ' . $to . ($msg !== '' ? (strpos($to, '?') !== false ? '&' : '?') . ($err ? 'error=' : 'msg=') . rawurlencode($msg) : ''));
    exit;
};
$next = (string)($_GET['next'] ?? '');
if (!preg_match('/^[A-Za-z0-9_\-]+\.php(\?[A-Za-z0-9_\-=&%.+#]*)?$/', $next)) $next = '';

if (!acc_discord_ready()) $back('admin.php', 'Die Anmeldung mit Discord ist nicht eingerichtet.');
if (isset($_GET['go'])) {
    $go = (string)$_GET['go'];
    if ($go === 'link' && !is_logged_in()) $back('admin.php?next=admin.php%3Ftab%3Daccount', 'Bitte zuerst anmelden.');
    if (auth_locked_seconds()) $back('admin.php', 'Zu viele Fehlversuche – bitte warten.');
    header('Location: ' . acc_discord_start($go, $next));
    exit;
}
if (isset($_GET['error'])) $back(is_logged_in() ? 'admin.php?tab=account' : 'admin.php', 'Discord-Anmeldung abgebrochen.');

try {
    [$me, $intent, $next] = acc_discord_finish((string)($_GET['code'] ?? ''), (string)($_GET['state'] ?? ''));
} catch (Throwable $e) {
    auth_register_fail();
    $back(is_logged_in() ? 'admin.php?tab=account' : 'admin.php', $e->getMessage());
}

$cur = current_user();
try {
    if ($intent === 'link' || $cur) {
        if (!$cur) $back('admin.php', 'Bitte zuerst anmelden.');
        acc_link_discord((int)$cur['id'], $me);
        audit('Discord verknüpft', acc_discord_label($me), 'security', $cur);
        $back('admin.php?tab=account', '🔗 Discord „' . acc_discord_label($me) . '“ verknüpft.', false);
    }
    $u = acc_by_discord((string)$me['id']);
    if (!$u) {
        if (!acc_open()) $back('admin.php', 'Mit diesem Discord-Konto ist noch kein GhostNode-Konto verknüpft. Melde dich an und verknüpfe Discord unter „Mein Konto“' . (acc_reg_mode() === 'invite' ? ' – oder registriere dich mit einem Einladungscode.' : '.'));
        $u = acc_create_from_discord($me);
        $new = true;
    } else {
        acc_link_discord((int)$u['id'], $me);   // Name/Bild auffrischen
    }
    if (!$u['active'] || user_expired($u)) { audit('Discord-Login abgelehnt (Konto gesperrt/abgelaufen)', acc_discord_label($me), 'security', $u); $back('admin.php', 'Dieses Konto ist gesperrt oder abgelaufen.'); }
    if ((string)$u['totp_secret'] !== '') {
        auth_session();
        session_regenerate_id(true);
        $_SESSION['auth_pending'] = ['uid' => (int)$u['id'], 'remember' => false, 't' => time(), 'tries' => 0];
        $back('admin.php?next=' . rawurlencode($next ?: 'admin.php'));
    }
    auth_finish_login($u, false, 'Discord');
    if (!empty($new)) $back('admin.php?tab=account&welcome=1', '👋 Willkommen! Dein Konto ist angelegt – du kannst hier ein Passwort setzen.', false);
    header('Location: ' . ($next !== '' ? $next : (can('admin.access') ? 'admin.php' : 'dashboard.php')));
    exit;
} catch (Throwable $e) {
    $back($cur ? 'admin.php?tab=account' : 'admin.php', $e->getMessage());
}
