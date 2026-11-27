<?php
/**
 * 🌍 Übersetzen helfen (8.1) – Community-Werkzeug für die Sprachen der Webseite.
 * <title>Übersetzen</title>
 *   uebersetzen.php               Übersicht, Fortschritt, Vorschläge (angemeldet)
 *   uebersetzen.php?js=1          freigegebene Texte als JavaScript (window.SiteDictExtra)
 *   uebersetzen.php?export=1      TSV für assets/i18n/site.tsv (nur Team)
 */
require_once __DIR__ . '/inc/core/common.php';
require_once __DIR__ . '/inc/core/pages.php';
require_once __DIR__ . '/inc/modules/i18ncomm.php';

if (isset($_GET['js'])) {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    header('X-Content-Type-Options: nosniff');
    try { echo tc_js(); } catch (Throwable $e) { echo 'window.SiteDictExtra = {};'; }
    exit;
}
auth_require_setup();
$u = current_user();
$team = $u && can('admin.access');
if (isset($_GET['export'])) {
    if (!$team) { http_response_code(403); exit('Nur für das Team.'); }
    header('Content-Type: text/tab-separated-values; charset=utf-8');
    header('Content-Disposition: attachment; filename="site.tsv"');
    echo tc_export_tsv(tc_sources(), tc_overlay());
    exit;
}
$msg = ''; $err = '';
if ($u && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    guard_post();
    require_once __DIR__ . '/inc/modules/nexuswarn.php';
    try {
        $a = (string)($_POST['action'] ?? '');
        if ($a === 'suggest') {
            if (nw_rate_over('i18n|' . $u['id'], 20)) throw new InvalidArgumentException('Zu viele Vorschläge auf einmal – kurz warten.');
            tc_suggest((int)$u['id'], (string)($_POST['lang'] ?? ''), (string)($_POST['src'] ?? ''), (string)($_POST['text'] ?? ''));
            $msg = 'Danke! Dein Vorschlag wartet auf Prüfung.';
        }
        if (($a === 'approve' || $a === 'reject') && $team) { tc_decide((int)($_POST['id'] ?? 0), $a === 'approve', (int)$u['id'], (string)($_POST['note'] ?? '')); $msg = $a === 'approve' ? 'Freigegeben – gilt sofort.' : 'Abgelehnt.'; }
    } catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
}
$src = tc_sources(); $ov = tc_overlay(); $prog = tc_progress($src, $ov);
$lang = isset(TC_LANGS[$_GET['l'] ?? '']) ? $_GET['l'] : 'en';
$only = (string)($_GET['f'] ?? '');   // „missing“ = nur fehlende/unübersetzte
$q = tc_clean((string)($_GET['q'] ?? ''), 80);
$keys = array_values(array_unique(array_merge(array_keys($src), array_keys($ov[$lang]))));
$rows = [];
foreach ($keys as $k) {
    $cur = $ov[$lang][$k] ?? ($src[$k][$lang] ?? '');
    if ($only === 'missing' && $cur !== '' && $cur !== $k) continue;
    if ($q !== '' && mb_stripos($k . ' ' . $cur, $q) === false) continue;
    $rows[] = [$k, $cur, isset($ov[$lang][$k])];
}
$pending = $team ? tc_list('pending') : [];
$mine = $u ? tc_list('pending', 50, (int)$u['id']) : [];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ÜBERSETZEN · GHOST NODE</title>
<?php echo emoji_favicon('🌍'); ?>
<link rel="stylesheet" href="assets/css/hud.css">
<style>
.tc-wrap{max-width:1100px}.tc-eyebrow{color:var(--accent-cyan);font-size:.7rem;letter-spacing:3px;font-weight:700}
.tc-prog{display:flex;gap:10px;flex-wrap:wrap;margin:12px 0}.tc-prog a{flex:1;min-width:180px;border:1px solid var(--border-color);padding:10px;color:inherit;text-decoration:none;background:var(--panel-bg)}
.tc-prog a.on{border-color:var(--accent-cyan)}.tc-bar{height:6px;background:var(--border-color);margin-top:6px}.tc-bar i{display:block;height:100%;background:var(--accent-cyan)}
.tc-tbl{width:100%;border-collapse:collapse;font-size:.86rem}.tc-tbl td,.tc-tbl th{border-bottom:1px solid var(--border-color);padding:7px;text-align:left;vertical-align:top}
.tc-tbl td:first-child{width:38%}.tc-miss{color:#f3c262}.tc-comm{font-size:.7rem;color:var(--accent-cyan)}
.tc-form{display:flex;gap:6px}.tc-form input[name=text]{flex:1;min-width:0}
.tc-card{background:var(--panel-bg);border:1px solid var(--border-color);padding:14px;margin:14px 0}
.tc-filter{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:10px 0}
</style>
</head>
<body>
<?php auth_corner(); ?>
<div class="wrapper tc-wrap">
    <div class="nav-bar"><a href="dev.php" class="nav-back">&larr; ENTWICKLER</a><div class="nav-tabs"><a href="Roadmap.php" class="nav-tab">🗺 Roadmap</a><a href="status.php" class="nav-tab">🩺 Status</a></div></div>
    <header><div class="tc-eyebrow">GHOST NODE // TRANSLATE</div><h1>🌍 Übersetzen helfen</h1>
        <p class="muted">Die Webseite gibt es auf Deutsch, Englisch, Französisch und Japanisch. Hilf mit: Übersetzungen vorschlagen oder verbessern. Das Team prüft jeden Vorschlag; freigegebene Texte gelten sofort.</p></header>
    <?php if ($msg): ?><p>✅ <?= e($msg) ?></p><?php endif; ?><?php if ($err): ?><p style="color:#ff5d73">⚠ <?= e($err) ?></p><?php endif; ?>

    <div class="tc-prog"><?php foreach (TC_LANGS as $l => [$flag, $name]): $p = $prog[$l]; $pc = $p['total'] ? (int)floor($p['done'] * 100 / $p['total']) : 0; ?>
        <a href="?l=<?= $l ?>" class="<?= $l === $lang ? 'on' : '' ?>"><b><?= $flag ?> <span data-no-i18n><?= e($name) ?></span></b> · <?= $pc ?> %<br><small class="muted"><?= $p['done'] ?> / <?= $p['total'] ?> Texte</small><div class="tc-bar"><i style="width:<?= $pc ?>%"></i></div></a>
    <?php endforeach; ?></div>

    <?php if ($team): ?>
    <section class="tc-card"><h3>🛡 Zu prüfen (<?= count($pending) ?>)</h3>
        <?php if (!$pending): ?><p class="muted">Nichts offen.</p><?php else: ?><table class="tc-tbl"><tr><th>Original</th><th>Vorschlag</th><th>Von</th><th></th></tr>
        <?php foreach ($pending as $s): $old = $ov[$s['lang']][$s['src']] ?? ($src[$s['src']][$s['lang']] ?? ''); ?>
            <tr><td data-no-i18n><?= e($s['src']) ?><?= isset($src[$s['src']]) ? '' : ' <span class="tc-comm">neu</span>' ?></td>
                <td data-no-i18n><?= TC_LANGS[$s['lang']][0] ?> <b><?= e($s['text']) ?></b><?= $old !== '' ? '<br><small class="muted">bisher: ' . e($old) . '</small>' : '' ?></td>
                <td data-no-i18n><?= e($s['username'] ?? '?') ?></td>
                <td><form method="post" style="display:flex;gap:4px"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn-sm btn-gold" name="action" value="approve">✔</button><button class="btn btn-sm btn-danger" name="action" value="reject">✖</button></form></td></tr>
        <?php endforeach; ?></table><?php endif; ?>
        <p><a class="btn btn-sm" href="?export=1">⬇ site.tsv exportieren</a> <small class="muted">Wörterbuch + Freigaben, zum dauerhaften Übernehmen ins Repository (danach <code>php tools/i18n-build.php</code>).</small></p>
    </section>
    <?php endif; ?>

    <?php if ($u): ?>
    <section class="tc-card"><h3>➕ Neuen Text vorschlagen</h3>
        <p class="muted">Ein deutscher Text auf der Webseite ist noch nicht übersetzt? Original genau abschreiben und Übersetzung eintragen. Zahlen im Text als <code>#</code> schreiben.</p>
        <form method="post" class="tc-form"><input type="hidden" name="action" value="suggest"><input type="hidden" name="lang" value="<?= $lang ?>">
            <input name="src" maxlength="300" placeholder="Original (Deutsch)" required style="flex:1;min-width:0"><input name="text" maxlength="500" placeholder="<?= e(TC_LANGS[$lang][1]) ?>" required data-no-i18n><button class="btn btn-gold">Vorschlagen</button></form>
        <?php if ($mine): ?><p class="muted" style="margin-top:8px">Deine offenen Vorschläge: <?= count($mine) ?></p><?php endif; ?>
    </section>
    <?php else: ?><p class="muted"><a href="admin.php?next=uebersetzen.php">Anmelden</a>, um Übersetzungen vorzuschlagen.</p><?php endif; ?>

    <form class="tc-filter" method="get"><input type="hidden" name="l" value="<?= $lang ?>">
        <input name="q" value="<?= e($q) ?>" placeholder="Suchen…"><label><input type="checkbox" name="f" value="missing"<?= $only === 'missing' ? ' checked' : '' ?>> nur fehlende</label><button class="btn btn-sm">Filtern</button>
        <span class="muted"><?= count($rows) ?> Texte</span></form>
    <table class="tc-tbl"><tr><th>Deutsch</th><th><?= TC_LANGS[$lang][0] ?> <span data-no-i18n><?= e(TC_LANGS[$lang][1]) ?></span></th></tr>
    <?php foreach (array_slice($rows, 0, 400) as [$k, $cur, $comm]): ?>
        <tr><td data-no-i18n><?= e($k) ?></td><td>
            <?php if ($cur === '' || $cur === $k): ?><span class="tc-miss">fehlt</span><?php else: ?><span data-no-i18n><?= e($cur) ?></span><?= $comm ? ' <span class="tc-comm">Community</span>' : '' ?><?php endif; ?>
            <?php if ($u): ?><form method="post" class="tc-form" style="margin-top:4px"><input type="hidden" name="action" value="suggest"><input type="hidden" name="lang" value="<?= $lang ?>"><input type="hidden" name="src" value="<?= e($k) ?>">
                <input name="text" maxlength="500" placeholder="Besser übersetzen…" data-no-i18n><button class="btn btn-sm">↑</button></form><?php endif; ?>
        </td></tr>
    <?php endforeach; ?></table>
</div>
<?php echo site_i18n_tags(); ?>
</body>
</html>
