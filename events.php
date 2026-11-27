<?php
declare(strict_types=1);
/**
 * 7.0 Öffentlicher Event-Kalender: kommende Events aller öffentlichen Clubs (ohne „nur Mitglieder“) mit Filter, iCal je Club.
 * <title>Events</title>
 */
require_once __DIR__ . '/inc/core/common.php';
require_once __DIR__ . '/inc/core/pages.php';
auth_require_setup();
require_once __DIR__ . '/inc/modules/clubs.php';

$f = ['lang' => (string)($_GET['lang'] ?? ''), 'topic' => (string)($_GET['topic'] ?? ''), 'tod' => in_array($_GET['tod'] ?? '', ['morning', 'afternoon', 'evening'], true) ? $_GET['tod'] : '', 'days' => (int)($_GET['days'] ?? 30) ?: 30];
try { $cal = cl_calendar(null, $f); } catch (Throwable $e) { $cal = ['items' => [], 'topics' => CL_TOPICS, 'langs' => CL_LANGS]; }
$sel = fn(array $o, string $cur, string $any) => '<option value="">' . e($any) . '</option>' . implode('', array_map(fn($k, $v) => '<option value="' . e($k) . '"' . ($k === $cur ? ' selected' : '') . '>' . e($v) . '</option>', array_keys($o), $o));
$WD = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Events · GhostNode</title>
<meta name="description" content="Kommende VRChat-Events der GhostNode-Clubs – nach Sprache, Thema und Uhrzeit filtern, per iCal abonnieren.">
<link rel="stylesheet" href="assets/css/hud.css">
<style>
body.ev{padding:0;display:block}.ev-wrap{box-sizing:border-box;max-width:980px;margin:0 auto;padding:20px 16px 60px}
.ev-f{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 18px}.ev-f select{flex:1;min-width:140px}
.ev-day{margin:18px 0 8px;color:var(--accent-cyan);font-size:1rem}
.ev-card{border:1px solid var(--border-color);background:var(--panel-bg);padding:12px 14px;margin-bottom:8px;clip-path:var(--cut-md)}
.ev-card b{color:var(--accent-gold)}.muted{color:var(--text-muted);font-size:.85rem}
.ev-card .row{display:flex;flex-wrap:wrap;gap:8px;align-items:baseline}.ev-card .sp{flex:1}
</style></head><body class="ev"><div class="ev-wrap">
<h1 style="margin:0">🗓️ Events</h1>
<p class="muted">Kommende Events aus den öffentlichen Clubs. Anmelden, Clubs gründen und beitreten: im <a href="nexus.php">GhostNode Nexus</a> (Community → Clubs) oder in der VRC-Nexus-App.</p>
<form class="ev-f" method="get">
<select name="topic" onchange="this.form.submit()"><?php echo $sel($cal['topics'], $f['topic'], 'Alle Themen'); ?></select>
<select name="lang" onchange="this.form.submit()"><?php echo $sel($cal['langs'], $f['lang'], 'Alle Sprachen'); ?></select>
<select name="tod" onchange="this.form.submit()"><?php echo $sel(['morning' => '🌅 vormittags', 'afternoon' => '☀ nachmittags', 'evening' => '🌙 abends'], $f['tod'], 'Jede Uhrzeit'); ?></select>
<select name="days" onchange="this.form.submit()"><?php foreach ([7, 30, 90] as $d) echo '<option value="' . $d . '"' . ($f['days'] === $d ? ' selected' : '') . '>' . $d . ' Tage</option>'; ?></select>
<noscript><button class="btn">Filtern</button></noscript></form>
<?php if (!$cal['items']): ?><p class="muted">Keine Events in diesem Zeitraum.</p><?php endif;
$day = '';
foreach ($cal['items'] as $ev):
    $k = $WD[(int)date('w', $ev['starts'])] . ', ' . date('d.m.Y', $ev['starts']);
    if ($k !== $day) { echo '<h2 class="ev-day">' . e($k) . '</h2>'; $day = $k; } ?>
<div class="ev-card"<?php if ($ev['club']['color']) echo ' style="border-left:4px solid ' . e($ev['club']['color']) . '"'; ?>><div class="row"><b><?php echo e($ev['title']); ?></b><span class="sp"></span>
<span class="muted">🕒 <?php echo date('H:i', $ev['starts']) . '–' . date('H:i', $ev['ends']); ?></span></div>
<div class="muted">🏠 <a href="nexus.php?club=<?php echo e(rawurlencode($ev['club']['slug'])); ?>"><?php echo e($ev['club']['name']); ?></a> · <?php echo e(CL_TOPICS[$ev['club']['topic']] ?? ''); ?> · <?php echo e(CL_LANGS[$ev['club']['lang']] ?? ''); ?>
 · <a href="nexusclubs.php?ics=<?php echo e(rawurlencode($ev['club']['slug'])); ?>">📆 iCal</a></div>
<?php if ($ev['place'] !== ''): ?><div class="muted">📍 <?php echo e($ev['place']); ?></div><?php endif; ?>
<?php if ($ev['descr'] !== ''): ?><p style="margin:6px 0 0;white-space:pre-wrap"><?php echo e($ev['descr']); ?></p><?php endif; ?>
<div class="muted" style="margin-top:4px">👥 <?php echo $ev['going'] . ($ev['capacity'] ? ' / ' . $ev['capacity'] . ($ev['going'] >= $ev['capacity'] ? ' · ausgebucht (Warteliste)' : '') : ''); ?></div></div>
<?php endforeach; ?>
</div></body></html>
