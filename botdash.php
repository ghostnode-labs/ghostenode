<?php
/**
 * 🎛️ Bot-Dashboard über das GhostNode-Konto (8.2.1)
 * <title>Bot-Dashboard</title>
 * Angemeldet + Discord verknüpft → „Öffnen“ schickt ein signiertes Ticket an den Bot (node_discord /sso).
 * Kein OAuth2-Redirect bei Discord nötig. Admins stellen hier die Bot-Adresse und den gemeinsamen Schlüssel ein.
 */
require_once __DIR__ . '/inc/core/common.php';
require_once __DIR__ . '/inc/core/pages.php';
require_once __DIR__ . '/inc/core/accounts.php';
require_once __DIR__ . '/inc/modules/botsso.php';

auth_require_setup();
if (!is_logged_in()) { header('Location: admin.php?next=botdash.php'); exit; }
acc_schema();
$u = current_user();
$st = site_db()->prepare('SELECT discord_id, discord_name, discord_avatar FROM users WHERE id = ?'); $st->execute([(int)$u['id']]);
$dc = $st->fetch(PDO::FETCH_ASSOC) ?: ['discord_id' => '', 'discord_name' => '', 'discord_avatar' => ''];
$linked = preg_match('/^\d{15,22}$/', (string)$dc['discord_id']) === 1;
$admin = can('admin.access');
$msg = ''; $newKey = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    guard_post('admin.access');
    $do = (string)($_POST['do'] ?? '');
    if ($do === 'url') {
        $url = rtrim(trim((string)($_POST['url'] ?? '')), '/');
        if ($url !== '' && !botsso_url_ok($url)) $msg = '⚠ Bitte eine Adresse wie https://discord.ghostnode-labs.dev oder http://IP:Port eingeben.';
        else { set_setting('botsso_url', $url); audit('Bot-Dashboard', 'Adresse ' . ($url ?: '–'), 'info'); $msg = '✔ Adresse gespeichert.'; }
    } elseif ($do === 'key') {
        if (botsso_secret_source() === 'env') $msg = '⚠ Der Schlüssel kommt aus der Umgebungsvariable GN_BOT_SSO_SECRET – dort ändern.';
        else { $newKey = botsso_new_secret(); audit('Bot-Dashboard', 'neuer Schlüssel', 'security'); }
    }
}

if (isset($_GET['go'])) {
    if (!botsso_ready()) $msg = '⚠ Das Bot-Dashboard ist noch nicht eingerichtet' . ($admin ? ' – unten Adresse und Schlüssel eintragen.' : ' – bitte die Admins fragen.');
    elseif (!$linked) $msg = '⚠ Verknüpfe zuerst dein Discord-Konto.';
    else {
        $name = (string)($dc['discord_name'] ?: ($u['display_name'] ?? '') ?: $u['username']);
        $av = (string)$dc['discord_avatar']; if (preg_match('#/avatars/\d+/((?:a_)?[0-9a-f]{32})#', $av, $m)) $av = $m[1];
        header('Cache-Control: no-store');
        header('Location: ' . botsso_url() . '/sso?t=' . botsso_ticket((string)$dc['discord_id'], $name, $av));
        exit;
    }
}
$ready = botsso_ready();
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BOT-DASHBOARD · GHOST NODE</title>
<?php echo emoji_favicon('🎛️'); ?>
<link rel="stylesheet" href="assets/css/hud.css">
<style>
.bd-wrap{max-width:820px}.bd-eyebrow{color:var(--accent-cyan);font-size:.7rem;letter-spacing:3px;font-weight:700}
.bd-card{background:var(--panel-bg);border:1px solid var(--border-color);padding:16px;margin:12px 0}
.bd-card h2{margin:0 0 8px;font-size:1rem}.bd-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.bd-msg{padding:10px 14px;border-left:3px solid var(--accent-gold);background:rgba(243,194,98,.08)}
.bd-key{font-family:monospace;word-break:break-all;background:rgba(0,0,0,.3);padding:8px;border:1px dashed var(--accent-gold);user-select:all}
.bd-big{font-size:1.05rem;padding:12px 22px}.ok{color:var(--accent-green,#51e57e)}.bad{color:#ff5c5c}
</style>
</head>
<body>
<?php auth_corner(); ?>
<div class="wrapper bd-wrap">
    <header><div class="bd-eyebrow">GHOST NODE // DISCORD-BOT</div><h1>🎛️ Bot-Dashboard</h1>
        <p class="muted">Begrüßung, Auto-Rollen, Level, AutoMod und eigene Befehle des GhostNode-Bots einstellen. Du meldest dich mit deinem GhostNode-Konto an – sichtbar sind nur Discord-Server, auf denen der Bot ist und du „Server verwalten“ hast.</p></header>
    <?php if ($msg !== ''): ?><p class="bd-msg"><?= e($msg) ?></p><?php endif; ?>

    <section class="bd-card">
        <?php if (!$linked): ?>
            <h2>1 · Discord verknüpfen</h2>
            <p class="muted">Damit der Bot weiß, wer du bist, braucht dein GhostNode-Konto ein verknüpftes Discord-Konto.</p>
            <?php if (acc_discord_ready()): ?><a class="btn" href="auth-discord.php?go=link&amp;next=botdash.php">🔗 Discord verknüpfen</a>
            <?php else: ?><p class="bad">Die Anmeldung mit Discord ist auf dieser Webseite noch nicht eingerichtet (Admin → Konten → Discord).</p><?php endif; ?>
        <?php else: ?>
            <h2>Verbunden als <?= e($dc['discord_name'] ?: $dc['discord_id']) ?></h2>
            <?php if ($ready): ?><p><a class="btn btn-primary bd-big" href="botdash.php?go=1">🎛️ Bot-Dashboard öffnen</a></p>
                <p class="muted">Öffnet <code><?= e(botsso_url()) ?></code> und meldet dich dort automatisch an (Ticket 60 s gültig, nur einmal nutzbar).</p>
            <?php else: ?><p class="muted">Das Bot-Dashboard ist noch nicht eingerichtet.</p><?php endif; ?>
        <?php endif; ?>
    </section>

    <?php if ($admin): ?>
    <section class="bd-card">
        <h2>⚙️ Einrichtung (Admin)</h2>
        <form method="post" class="bd-row"><input type="hidden" name="do" value="url">
            <label for="bd-url">Adresse des Bot-Dashboards</label>
            <input id="bd-url" name="url" value="<?= e(setting('botsso_url', '')) ?>" placeholder="https://discord.ghostnode-labs.dev" style="flex:1;min-width:240px">
            <button class="btn">Speichern</button></form>
        <p class="muted">Die Adresse, unter der der Bot sein Dashboard zeigt – mit Domain (empfohlen) oder vorerst direkt <code>http://IP:Port</code> aus Pterodactyl.</p>
        <p>Gemeinsamer Schlüssel: <?= botsso_secret_source() === 'env' ? '<span class="ok">✔ aus GN_BOT_SSO_SECRET</span>' : (botsso_secret_source() === 'admin' ? '<span class="ok">✔ gesetzt</span>' : '<span class="bad">✖ fehlt</span>') ?></p>
        <?php if ($newKey !== ''): ?>
            <p class="bd-msg">Neuer Schlüssel – <strong>wird nur jetzt angezeigt</strong>. Im Bot als Variable <code>BOT_SSO_SECRET</code> eintragen (Pterodactyl → Server → Startup) und den Bot neu starten:</p>
            <p class="bd-key"><?= e($newKey) ?></p>
        <?php endif; ?>
        <?php if (botsso_secret_source() !== 'env'): ?>
        <form method="post" onsubmit="return <?= botsso_secret_source() ? "confirm('Neuen Schlüssel erzeugen? Der alte gilt dann nicht mehr – der Bot braucht den neuen.')" : 'true' ?>"><input type="hidden" name="do" value="key">
            <button class="btn"><?= botsso_secret_source() ? '🔄 Neuen Schlüssel erzeugen' : '🔑 Schlüssel erzeugen' ?></button></form>
        <?php endif; ?>
        <p class="muted">Der Bot prüft jedes Ticket mit diesem Schlüssel und fragt selbst bei Discord nach, wer welchen Server verwalten darf. Ein OAuth2-Redirect bei Discord ist dafür nicht nötig.</p>
    </section>
    <?php endif; ?>
</div>
</body>
</html>
