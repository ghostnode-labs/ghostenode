<?php
/**
 * 🛰 Föderation (8.1) – öffentliche Clubs und Profile von Partner-Servern; Schnittstelle für andere GhostNode-Server.
 * <title>Föderation</title>
 *   fed.php?r=info     Server-Info + öffentlicher Schlüssel
 *   fed.php?r=export   signierte Liste (Kopfzeile X-GhostNode-Fed-Sig = Ed25519 über den Body)
 */
require_once __DIR__ . '/inc/core/common.php';
require_once __DIR__ . '/inc/core/pages.php';
require_once __DIR__ . '/inc/modules/federation.php';

$r = (string)($_GET['r'] ?? '');
if ($r === 'peer18') {   // 8.2: signierte 18+-Abfrage eines Partner-Servers (nur ja/nein)
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo '{"error":"POST erwartet."}'; exit; }
    require_once __DIR__ . '/inc/modules/nexuswarn.php';
    if (nw_rate_over('fed18|' . client_ip(), 60)) { http_response_code(429); echo '{"error":"Zu viele Anfragen."}'; exit; }
    try {
        require_once __DIR__ . '/inc/modules/nexusacct.php';
        [$out, $sig] = fed_peer18_answer((string)file_get_contents('php://input', false, null, 0, 4096), (string)($_SERVER['HTTP_X_GHOSTNODE_FED_SIG'] ?? ''),
            function (string $uid): bool { $p = nx_account($uid); return $p && empty($p['banned']) && nx_nsfw_allowed($p); });
        header('X-GhostNode-Fed-Sig: ' . $sig); echo $out;
    } catch (RuntimeException $e) { http_response_code(403); echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE); }
    catch (Throwable $e) { error_log('fed18: ' . $e->getMessage()); http_response_code(500); echo '{"error":"Serverfehler."}'; }
    exit;
}
if ($r === 'info' || $r === 'export') {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Access-Control-Allow-Origin: *');
    if (!fed_on()) { http_response_code(404); echo '{"error":"Föderation ist auf diesem Server aus."}'; exit; }
    require_once __DIR__ . '/inc/modules/nexuswarn.php';
    if (nw_rate_over('fed|' . client_ip(), 30)) { http_response_code(429); echo '{"error":"Zu viele Anfragen."}'; exit; }
    try {
        if ($r === 'info') { header('Cache-Control: public, max-age=3600'); echo json_encode(fed_info(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
        [$body, $sig] = fed_export();
        header('Cache-Control: public, max-age=300');
        header('X-GhostNode-Fed-Sig: ' . $sig);
        echo $body;
    } catch (Throwable $e) { error_log('fed: ' . $e->getMessage()); http_response_code(500); echo '{"error":"Serverfehler."}'; }
    exit;
}
auth_require_setup();
$kind = in_array($_GET['k'] ?? '', ['club', 'profile'], true) ? $_GET['k'] : '';
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$items = fed_on() ? fed_items($kind, $q) : [];
$peers = []; foreach (fed_peers() as $p) $peers[$p['url']] = $p;
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FÖDERATION · GHOST NODE</title>
<?php echo emoji_favicon('🛰'); ?>
<link rel="stylesheet" href="assets/css/hud.css">
<style>
.fd-wrap{max-width:1100px}.fd-eyebrow{color:var(--accent-cyan);font-size:.7rem;letter-spacing:3px;font-weight:700}
.fd-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px;margin-top:12px}
.fd-card{background:var(--panel-bg);border:1px solid var(--border-color);padding:12px 14px;display:flex;flex-direction:column;gap:6px;color:inherit;text-decoration:none}
.fd-card:hover{border-color:var(--accent-cyan)}.fd-card h3{margin:0;font-size:.98rem}.fd-card p{margin:0;color:var(--text-muted);font-size:.84rem;flex:1}
.fd-meta{font-size:.74rem;color:var(--text-muted)}.fd-filter{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}
</style>
</head>
<body>
<?php auth_corner(); ?>
<div class="wrapper fd-wrap">
    <div class="nav-bar"><a href="nexus.php" class="nav-back">&larr; VRC NEXUS</a><div class="nav-tabs"><a href="dev.php" class="nav-tab">🛠 Entwickler</a></div></div>
    <header><div class="fd-eyebrow">GHOST NODE // FEDERATION</div><h1>🛰 Föderation</h1>
        <p class="muted">Öffentliche Clubs und Profile von befreundeten Servern. Übertragen wird nur, was dort ohnehin öffentlich ist – keine Konten, keine Mitgliederlisten, nie 18+. Jeder Datensatz ist vom Partner signiert.</p></header>
    <?php if (!fed_on()): ?><p class="muted">Die Föderation ist auf diesem Server ausgeschaltet.</p><?php else: ?>
    <form class="fd-filter" method="get"><select name="k" aria-label="Art"><option value="">Alles</option><option value="club"<?= $kind === 'club' ? ' selected' : '' ?>>🏠 Clubs</option><option value="profile"<?= $kind === 'profile' ? ' selected' : '' ?>>👤 Profile</option></select>
        <input name="q" value="<?= e($q) ?>" placeholder="Suchen…" aria-label="Suchen"><button class="btn btn-sm">Filtern</button>
        <span class="muted"><?= count($items) ?> Einträge von <?= count($peers) ?> Partner-Server(n)</span></form>
    <?php if (!$items): ?><p class="muted">Noch nichts von Partnern da.</p><?php endif; ?>
    <div class="fd-grid"><?php foreach (array_slice($items, 0, 300) as $it): $pn = $peers[$it['peer']]['name'] ?? parse_url($it['peer'], PHP_URL_HOST); ?>
        <a class="fd-card" href="<?= e($it['url']) ?>" target="_blank" rel="noopener nofollow" data-no-i18n>
            <h3><?= $it['kind'] === 'club' ? '🏠' : '👤' ?> <?= e($it['name']) ?></h3>
            <p><?= e($it['text']) ?></p>
            <span class="fd-meta">🛰 <?= e($pn) ?><?= $it['kind'] === 'club' ? ' · ' . (int)$it['members'] . ' 👥' . ($it['lang'] ? ' · ' . e(strtoupper($it['lang'])) : '') : '' ?></span>
        </a>
    <?php endforeach; ?></div>
    <?php endif; ?>
</div>
</body>
</html>
