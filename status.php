<?php
/**
 * 🩺 Status aller GhostNode-Dienste (8.1) – öffentlich, ohne Anmeldung.
 *   status.php          Seite
 *   status.php?api=1    JSON (für App, Bot, eigene Monitore; CORS offen)
 * Geprüft wird von der Aufgabe „status“ (Admin → System → Aufgaben, alle 15 min).
 * <title>Status</title>
 */
require_once __DIR__ . '/inc/core/common.php';
require_once __DIR__ . '/inc/modules/status.php';

$sum = st_summary();
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($sum, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$fmtUp = fn($v) => $v === null ? '–' : rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',') . ' %';
$dur = function (int $s): string { if ($s < 3600) return max(1, (int)round($s / 60)) . ' min'; if ($s < 86400) return floor($s / 3600) . ' h ' . floor($s % 3600 / 60) . ' min'; return floor($s / 86400) . ' Tage'; };
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>STATUS · GHOST NODE</title>
<?php echo emoji_favicon($sum['ok'] ? '🟢' : '🟠'); ?>
<meta http-equiv="refresh" content="120">
<link rel="stylesheet" href="assets/css/hud.css">
<style>
.st-wrap{max-width:900px}
.st-eyebrow{color:var(--accent-cyan);font-size:.7rem;letter-spacing:3px;font-weight:700}
.st-banner{padding:16px 18px;margin:16px 0;border:1px solid;font-weight:700;font-size:1.05rem}
.st-banner.ok{border-color:#4ade80;color:#4ade80;background:rgba(74,222,128,.08)}.st-banner.bad{border-color:#f3c262;color:#f3c262;background:rgba(243,194,98,.08)}
.st-svc{background:var(--panel-bg);border:1px solid var(--border-color);padding:12px 14px;margin:8px 0}
.st-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.st-row b{flex:1}.st-row small{color:var(--text-muted)}
.st-dot{width:10px;height:10px;border-radius:50%;display:inline-block}.st-dot.ok{background:#4ade80}.st-dot.bad{background:#ff5d73}
.st-bars{display:flex;gap:2px;height:22px;margin-top:8px;align-items:stretch}.st-bars i{flex:1;background:#4ade80;opacity:.75;border-radius:1px}.st-bars i.b{background:#ff5d73;opacity:1}.st-bars i.e{background:var(--border-color);opacity:.4}
.st-legend{display:flex;justify-content:space-between;font-size:.7rem;color:var(--text-muted);margin-top:3px}
.st-inc{border-left:3px solid #ff5d73;padding:6px 10px;margin:6px 0;background:var(--panel-bg)}.st-inc.done{border-color:#4ade80}
.st-note{color:var(--text-muted);font-size:.8rem}
</style>
</head>
<body>
<div class="wrapper st-wrap">
    <div class="nav-bar"><a href="index.php" class="nav-back">&larr; GHOST NODE</a><div class="nav-tabs"><a href="changelog.php" class="nav-tab">📦 Changelog</a><a href="Roadmap.php" class="nav-tab">🗺 Roadmap</a></div></div>
    <header><div class="st-eyebrow">GHOST NODE // STATUS</div><h1>🩺 Status</h1></header>
    <?php if (!$sum['services']): ?>
        <div class="st-banner bad">Noch keine Messung – die Aufgabe „Status-Seite“ läuft alle 15 Minuten (Admin → System → Aufgaben).</div>
    <?php else: ?>
        <div class="st-banner <?= $sum['ok'] ? 'ok' : 'bad' ?>"><?= $sum['ok'] ? '✅ Alle Dienste laufen.' : '⚠ ' . $sum['down'] . ' Dienst' . ($sum['down'] === 1 ? '' : 'e') . ' gestört.' ?><?= $sum['stale'] ? ' <small>(letzte Messung älter als 1 h)</small>' : '' ?></div>
        <?php foreach ($sum['services'] as $s): $bars = array_pad($s['bars'], -96, -1); ?>
        <section class="st-svc">
            <div class="st-row"><span class="st-dot <?= $s['ok'] ? 'ok' : 'bad' ?>"></span><b><?= e($s['icon'] . ' ' . $s['name']) ?></b>
                <small><?= $s['ok'] ? 'läuft' : 'gestört: ' . e($s['msg']) ?><?= $s['ms'] ? ' · ' . (int)$s['ms'] . ' ms' : '' ?></small>
                <small>24 h: <b><?= $fmtUp($s['up24']) ?></b> · 7 Tage: <b><?= $fmtUp($s['up7']) ?></b></small></div>
            <div class="st-bars" aria-label="Verlauf der letzten 24 Stunden"><?php foreach ($bars as $v): ?><i class="<?= $v < 0 ? 'e' : ($v ? '' : 'b') ?>"></i><?php endforeach; ?></div>
            <div class="st-legend"><span>vor 24 h</span><span>jetzt</span></div>
        </section>
        <?php endforeach; ?>
        <h2 style="margin-top:28px;font-size:1rem;letter-spacing:2px;color:var(--accent-cyan)">📋 Störungen</h2>
        <?php if (!$sum['incidents']): ?><p class="st-note">Keine Störungen aufgezeichnet.</p><?php endif; ?>
        <?php foreach ($sum['incidents'] as $i): ?>
            <div class="st-inc<?= $i['end'] ? ' done' : '' ?>"><b><?= e($i['name']) ?></b> · <?= date('d.m.Y H:i', (int)$i['start']) ?> – <?= $i['end'] ? date('H:i', (int)$i['end']) . ' (' . $dur((int)$i['end'] - (int)$i['start']) . ')' : '<b>läuft noch</b>' ?><br><small class="st-note"><?= e($i['msg']) ?></small></div>
        <?php endforeach; ?>
        <p class="st-note" style="margin-top:20px">Letzte Messung: <?= $sum['checked'] ? date('d.m.Y H:i', $sum['checked']) : '–' ?> · Seite lädt alle 2 Minuten neu · JSON: <a href="status.php?api=1">status.php?api=1</a></p>
    <?php endif; ?>
</div>
</body>
</html>
