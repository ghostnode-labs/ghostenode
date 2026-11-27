<?php
declare(strict_types=1);
/**
 * 7.2 Öffentliche Steckbriefe & Creator (nur lesen): wiki.php · wiki.php?w=wrld_…|avtr_… · wiki.php?c=usr_… · ?q=&tag=&kind=
 * Bewerten, Taggen und Anlegen im GhostNode Nexus (Webseite) oder in der VRC-Nexus-App.
 * <title>Steckbriefe</title>
 */
require_once __DIR__ . '/inc/core/common.php';
require_once __DIR__ . '/inc/core/pages.php';
auth_require_setup();
require_once __DIR__ . '/inc/modules/wiki.php';

$st = fn(float $n) => '<span class="st">' . str_repeat('★', (int)round($n)) . str_repeat('☆', 5 - (int)round($n)) . '</span>';
$kind = fn(string $k) => $k === 'world' ? '🌐 Welt' : '🎭 Avatar';
$cardHtml = function (array $x) use ($st, $kind) {
    return '<a class="wk-card" href="wiki.php?w=' . e($x['id']) . '">' . ($x['image'] ? '<span class="wk-img" style="background-image:url(\'' . e($x['image']) . '\')"></span>' : '<span class="wk-img wk-no">' . ($x['kind'] === 'world' ? '🌐' : '🎭') . '</span>')
        . '<b>' . e($x['name']) . '</b><span class="muted">' . $kind($x['kind']) . ($x['creator_name'] ? ' · ' . e($x['creator_name']) : '') . '</span><span>' . $st($x['avg']) . ' <span class="muted">' . ($x['count'] ? $x['avg'] . ' (' . $x['count'] . ')' : '–') . '</span></span></a>';
};
$err = ''; $mode = 'list'; $data = [];
try {
    if (isset($_GET['w'])) { $id = strtolower((string)$_GET['w']); $data = sb_get(null, str_starts_with($id, 'avtr_') ? 'avatar' : 'world', $id); $mode = $data['exists'] ? 'item' : 'missing'; }
    elseif (isset($_GET['c'])) { $data = sb_creator(null, (string)$_GET['c']); $mode = 'creator'; }
    else $data = sb_list(null, ['q' => (string)($_GET['q'] ?? ''), 'tag' => (string)($_GET['tag'] ?? ''), 'kind' => (string)($_GET['kind'] ?? ''), 'sort' => (string)($_GET['sort'] ?? 'top')]);
} catch (Throwable $e) { $err = $e instanceof NcError ? $e->getMessage() : 'Gerade nicht verfügbar.'; }
$title = $mode === 'item' ? $data['item']['name'] : ($mode === 'creator' ? ($data['name'] ?: 'Creator') : 'Steckbriefe');
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo e($title); ?> · GhostNode</title>
<meta name="description" content="Welten und Avatare für VRChat mit Bewertungen und Tags der Community.">
<link rel="stylesheet" href="assets/css/hud.css">
<style>
body.wk{padding:0;display:block}.wk-wrap{box-sizing:border-box;max-width:1000px;margin:0 auto;padding:20px 16px 60px}
.wk-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}.wk-card{display:flex;flex-direction:column;gap:4px;border:1px solid var(--border-color);background:var(--panel-bg);padding:10px;color:inherit;text-decoration:none}
.wk-card:hover{border-color:var(--accent-cyan)}.wk-img{display:block;height:120px;background:#0c1420 center/cover no-repeat;margin:-10px -10px 6px}.wk-no{display:flex;align-items:center;justify-content:center;font-size:2.4rem;opacity:.5}
.st{color:var(--accent-gold)}.muted{color:var(--text-muted);font-size:.85rem}.tags a{display:inline-block;margin:2px;padding:2px 8px;border:1px solid var(--border-color);border-radius:10px;font-size:.8rem}
.box{border:1px solid var(--border-color);background:var(--panel-bg);padding:14px;margin:12px 0}.big{height:240px;background:#0c1420 center/cover no-repeat;margin-bottom:12px}
form.f{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}form.f input,form.f select{flex:1;min-width:140px}.rv{padding:8px 0;border-bottom:1px solid var(--border-color)}
</style></head><body class="wk"><div class="wk-wrap">
<p class="muted"><a href="wiki.php">📚 Steckbriefe</a> · Bewerten &amp; anlegen im <a href="nexus.php">GhostNode Nexus</a> oder in der VRC-Nexus-App</p>
<?php if ($err): ?><div class="box"><?php echo e($err); ?></div>
<?php elseif ($mode === 'missing'): ?><div class="box">Zu dieser ID gibt es noch keinen Steckbrief. <a href="nexus.php?sb=<?php echo e($data['id']); ?>">Jetzt anlegen</a></div>
<?php elseif ($mode === 'item'): $x = $data['item']; ?>
<?php if ($x['image']): ?><div class="big" style="background-image:url('<?php echo e($x['image']); ?>')"></div><?php endif; ?>
<h1 style="margin:0"><?php echo e($x['name']); ?></h1>
<p class="muted"><?php echo $kind($x['kind']); ?> · <code><?php echo e($x['id']); ?></code><?php if ($x['creator_name'] || $x['creator_id']): ?> · 🎨 <?php echo $x['creator_id'] ? '<a href="wiki.php?c=' . e($x['creator_id']) . '">' . e($x['creator_name'] ?: 'Creator') . '</a>' : e($x['creator_name']); ?><?php endif; ?></p>
<p><?php echo $st($x['avg']); ?> <b><?php echo $x['count'] ? $x['avg'] : '–'; ?></b> <span class="muted">(<?php echo $x['count']; ?> Bewertungen)</span></p>
<?php if ($x['descr']): ?><div class="box" style="white-space:pre-wrap"><?php echo e($x['descr']); ?></div><?php endif; ?>
<?php if ($x['tags']): ?><p class="tags"><?php foreach ($x['tags'] as $t) echo '<a href="wiki.php?tag=' . e(rawurlencode($t['tag'])) . '">#' . e($t['tag']) . ' <span class="muted">' . $t['n'] . '</span></a>'; ?></p><?php endif; ?>
<?php if ($x['kind'] === 'world'): ?><p><a class="btn" href="https://vrchat.com/home/world/<?php echo e($x['id']); ?>" target="_blank" rel="noopener">In VRChat ansehen</a></p><?php endif; ?>
<h2>💬 Bewertungen</h2><?php foreach ($data['reviews'] as $r) echo '<div class="rv">' . $st($r['stars']) . ' <b>' . e($r['name']) . '</b> <span class="muted">' . e(date('d.m.Y', $r['created'])) . '</span>' . ($r['text'] !== '' ? '<div style="white-space:pre-wrap">' . e($r['text']) . '</div>' : '') . '</div>'; if (!$data['reviews']) echo '<p class="muted">Noch keine Bewertungen.</p>'; ?>
<?php elseif ($mode === 'creator'): ?>
<h1 style="margin:0">🎨 <?php echo e($data['name'] ?: $data['id']); ?></h1>
<?php if ($data['reviews']): ?><p><?php echo $st($data['avg']); ?> <span class="muted"><?php echo $data['avg']; ?> aus <?php echo $data['reviews']; ?> Bewertungen</span></p><?php endif; ?>
<?php if ($data['skills']): ?><p class="tags"><?php foreach ($data['skills'] as $s) echo '<a>' . e($s) . '</a>'; ?></p><?php endif; ?>
<?php if ($data['bio'] !== ''): ?><div class="box" style="white-space:pre-wrap"><?php echo e($data['bio']); ?></div><?php endif; ?>
<?php if ($data['links']): ?><p><?php foreach ($data['links'] as $l) echo '<a class="btn" href="' . e($l['url']) . '" target="_blank" rel="noopener noreferrer">🛍 ' . e($l['label']) . '</a> '; ?></p><?php endif; ?>
<div class="wk-grid"><?php foreach ($data['items'] as $x) echo $cardHtml($x); ?></div>
<?php else: ?>
<h1 style="margin:0">📚 Steckbriefe</h1>
<form class="f" method="get"><input name="q" value="<?php echo e((string)($_GET['q'] ?? '')); ?>" placeholder="Name, Creator oder ID"><select name="kind"><option value="">Welten &amp; Avatare</option><option value="world"<?php echo ($_GET['kind'] ?? '') === 'world' ? ' selected' : ''; ?>>🌐 Welten</option><option value="avatar"<?php echo ($_GET['kind'] ?? '') === 'avatar' ? ' selected' : ''; ?>>🎭 Avatare</option></select>
<select name="sort"><option value="top">⭐ Beste</option><option value="rated"<?php echo ($_GET['sort'] ?? '') === 'rated' ? ' selected' : ''; ?>>💬 Meiste Bewertungen</option><option value="new"<?php echo ($_GET['sort'] ?? '') === 'new' ? ' selected' : ''; ?>>🆕 Neueste</option></select><button class="btn">Suchen</button></form>
<?php if (!empty($data['popular_tags'])): ?><p class="tags"><?php foreach ($data['popular_tags'] as $t) echo '<a href="wiki.php?tag=' . e(rawurlencode($t)) . '">#' . e($t) . '</a>'; ?></p><?php endif; ?>
<div class="wk-grid"><?php foreach ($data['items'] as $x) echo $cardHtml($x); if (!$data['items']) echo '<p class="muted">Nichts gefunden.</p>'; ?></div>
<?php endif; ?>
</div></body></html>
