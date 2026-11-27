<?php
/**
 * 🔗 „Mit GhostNode anmelden“ (8.1) – OAuth 2.0 mit Autorisierungscode + PKCE.
 * <title>Anmelden mit GhostNode</title>
 *   GET  oauth.php?r=authorize&client_id=…&redirect_uri=…&scope=read&state=…&code_challenge=…&code_challenge_method=S256
 *   POST oauth.php?r=token      grant_type=authorization_code, code, redirect_uri, client_id (+ client_secret oder code_verifier)
 *   GET  oauth.php?r=userinfo   Authorization: Bearer gno_…
 *   POST oauth.php?r=revoke     token=gno_…
 *   GET  oauth.php?r=meta       Server-Metadaten (RFC 8414)
 * Doku: api.php#oauth · Apps anlegen: dev.php#oauth
 */
require_once __DIR__ . '/inc/core/common.php';
require_once __DIR__ . '/inc/core/pages.php';
require_once __DIR__ . '/inc/modules/oauth.php';
require_once __DIR__ . '/inc/modules/market.php';   // mk_base_url

$r = (string)($_GET['r'] ?? 'authorize');
$json = function (array $o, int $code = 200): void {
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('Pragma: no-cache');
    header('Access-Control-Allow-Origin: *'); header('Access-Control-Allow-Headers: Authorization, Content-Type');
    http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS' && $r !== 'authorize') { header('Access-Control-Allow-Origin: *'); header('Access-Control-Allow-Headers: Authorization, Content-Type'); header('Access-Control-Allow-Methods: GET, POST'); http_response_code(204); exit; }

if ($r === 'meta') {
    $b = mk_base_url() . '/oauth.php';
    $json(['issuer' => mk_base_url(), 'authorization_endpoint' => $b . '?r=authorize', 'token_endpoint' => $b . '?r=token', 'userinfo_endpoint' => $b . '?r=userinfo',
        'revocation_endpoint' => $b . '?r=revoke', 'response_types_supported' => ['code'], 'grant_types_supported' => ['authorization_code'],
        'code_challenge_methods_supported' => ['S256'], 'scopes_supported' => array_keys(WA_SCOPES), 'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none']]);
}
if ($r === 'token') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $json(['error' => 'invalid_request', 'error_description' => 'POST erwartet.'], 405);
    require_once __DIR__ . '/inc/modules/nexuswarn.php';
    if (nw_rate_over('oauth|' . client_ip(), 30)) $json(['error' => 'slow_down', 'error_description' => 'Zu viele Anfragen.'], 429);
    try { $json(oa_token($_POST, (string)($_SERVER['PHP_AUTH_USER'] ?? ''), (string)($_SERVER['PHP_AUTH_PW'] ?? ''))); }
    catch (OaError $e) { $json(['error' => $e->err, 'error_description' => $e->getMessage()], $e->status); }
    catch (Throwable $e) { error_log('oauth: ' . $e->getMessage()); $json(['error' => 'server_error'], 500); }
}
if ($r === 'revoke') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') oa_revoke_token((string)($_POST['token'] ?? ''));
    $json(['ok' => true]);
}
if ($r === 'userinfo') {
    try {
        $k = wa_auth();
        if (!$k) $json(['error' => 'invalid_token'], 401);
        [$key, $user] = $k;
        $json(['sub' => (string)$user['id'], 'preferred_username' => $user['username'], 'name' => ($user['display_name'] ?? '') !== '' ? $user['display_name'] : $user['username'], 'scope' => implode(' ', $key['scopes'])]);
    } catch (WaError $e) { $json(['error' => 'invalid_token', 'error_description' => $e->getMessage()], $e->status); }
}

// ---------------------------------------------------------------- Zustimmungs-Seite
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");
auth_require_setup();
$err = ''; $req = null;
try { $req = oa_check_request($_GET); } catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
$u = current_user();
if ($req && !$u) { header('Location: admin.php?next=' . urlencode('oauth.php?' . http_build_query($_GET))); exit; }
if ($req && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    guard_post();
    [$app, $scopes, $red, $state, $ch] = $req;
    $nonce = (string)($_POST['nonce'] ?? '');
    $ok = $nonce !== '' && hash_equals((string)($_SESSION['oa_nonce'] ?? ''), $nonce);
    unset($_SESSION['oa_nonce']);
    if (!$ok) $err = 'Die Anfrage ist abgelaufen – bitte noch einmal versuchen.';
    else {
        $p = ($_POST['do'] ?? '') === 'allow' ? ['code' => oa_issue_code($app, (int)$u['id'], $scopes, $red, $ch)] : ['error' => 'access_denied'];
        if ($state !== '') $p['state'] = $state;
        if (isset($p['code'])) audit('App verbunden', $app['name'] . ' (' . implode(', ', $scopes) . ')');
        header('Location: ' . oa_redirect_url($red, $p));
        exit;
    }
}
$_SESSION['oa_nonce'] = bin2hex(random_bytes(16));
$owner = $req ? user_load((int)$req[0]['user_id']) : null;
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ANMELDEN MIT GHOST NODE</title>
<?php echo emoji_favicon('🔗'); ?>
<link rel="stylesheet" href="assets/css/hud.css">
<style>
.oa-box{max-width:460px;margin:40px auto;background:var(--panel-bg);border:1px solid var(--border-color);padding:22px}
.oa-box h1{font-size:1.2rem;margin:0 0 6px}.oa-app{font-size:1.1rem;color:var(--accent-gold,#f3c262)}
.oa-sc{list-style:none;padding:0;margin:14px 0}.oa-sc li{padding:8px 0;border-bottom:1px solid var(--border-color)}.oa-sc small{display:block;color:var(--text-muted)}
.oa-btns{display:flex;gap:10px;margin-top:16px}.oa-btns .btn{flex:1;text-align:center}
.oa-note{color:var(--text-muted);font-size:.8rem;margin-top:12px;line-height:1.5}.oa-note a{color:var(--accent-cyan)}.oa-err{color:#ff5d73}
</style>
</head>
<body>
<div class="wrapper">
<div class="oa-box">
<?php if ($err !== ''): ?>
    <h1>🔗 Anmelden mit GhostNode</h1>
    <p class="oa-err">⚠ <?= e($err) ?></p>
    <p class="oa-note">Wenn du von einer App hierher geschickt wurdest, melde den Fehler bitte dort. <a href="index.php">Zur Startseite</a></p>
<?php else: [$app, $scopes, $red] = $req; $host = parse_url($red, PHP_URL_HOST); ?>
    <h1>🔗 Anmelden mit GhostNode</h1>
    <p><span class="oa-app" data-no-i18n><?= e($app['name']) ?></span> möchte auf dein Konto <b data-no-i18n><?= e($u['display_name'] ?: $u['username']) ?></b> zugreifen.</p>
    <ul class="oa-sc">
        <li>👤 Deinen Namen und deine Konto-Nummer sehen<small>immer dabei</small></li>
        <?php foreach ($scopes as $s): ?><li><?= $s === 'community' ? '💬' : '📖' ?> <?= e(WA_SCOPES[$s][0]) ?><small><?= e(WA_SCOPES[$s][1]) ?></small></li><?php endforeach; ?>
    </ul>
    <p class="oa-note">Kein Zugriff auf Passwort, E-Mail, 🔞-Bereiche oder Einstellungen. Weiterleitung an <b data-no-i18n><?= e($host) ?></b>.
        App von <span data-no-i18n><?= e($owner ? ($owner['display_name'] ?: $owner['username']) : '?') ?></span><?php if ($app['homepage']): ?> · <a href="<?= e($app['homepage']) ?>" target="_blank" rel="noopener">Webseite</a><?php endif; ?>.
        Widerrufen jederzeit unter <a href="me.php?tab=privacy">Mein Profil → Datenschutz</a>.</p>
    <form method="post" class="oa-btns">
        <input type="hidden" name="nonce" value="<?= e($_SESSION['oa_nonce']) ?>">
        <button class="btn" name="do" value="deny">Ablehnen</button>
        <button class="btn btn-gold" name="do" value="allow">Erlauben</button>
    </form>
<?php endif; ?>
</div>
</div>
<?php echo site_i18n_tags(); ?>
</body>
</html>
