<?php
/**
 * Nexus-Warnliste – Schnittstelle für die VRC-Nexus-App und Verwaltung für Admins.
 * <title>Nexus-Warnliste</title>
 *
 * App (JSON, ohne Sitzung/Cookies), immer mit dem NXK1-Freischalt-Schlüssel der App:
 *   POST nexuswarn.php?a=list      {"token"}                                         → {"items":[…], "mine":[…], "me":{…}, "min":2, "admin":bool}
 *   POST nexuswarn.php?a=report    {"token","target","name","reason","note","evidence","days","reporter_name"}
 *   POST nexuswarn.php?a=withdraw  {"token","target"}
 *   POST nexuswarn.php?a=appeal    {"token","text"}                                  (nur für die eigene ID)
 *   POST nexuswarn.php?a=admin_*   {"token", …}                                      (Besitzerin oder Moderator)
 * Webseite: GET nexuswarn.php → Verwaltung für angemeldete Webseiten-Admins (Recht „admin.access“).
 */
require_once __DIR__ . '/inc/core/jsonguard.php'; if (isset($_GET['a'])) json_guard();
require_once __DIR__ . '/inc/core/pages.php';
require_once __DIR__ . '/inc/modules/vrchat.php';
require_once __DIR__ . '/inc/modules/nexuswarn.php';

// =============================== App-Schnittstelle ===============================
if (isset($_GET['a'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $out = function (array $o, int $code = 200) { json_guard_clean(); http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE); exit; };
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $out(['error' => 'Nur POST.'], 405);
    // Bremse pro IP (60 Anfragen/Minute)
    if (nw_rate_over(client_ip(), 60)) $out(['error' => 'Zu viele Anfragen – kurz warten.'], 429);
    $in = json_decode((string)file_get_contents('php://input', false, null, 0, 20000), true);
    if (!is_array($in)) $out(['error' => 'JSON erwartet.'], 400);
    $a = (string)$_GET['a'];
    try {
        $who = nw_auth((string)($in['token'] ?? ''));
        if (mt_rand(1, 50) === 1) nw_cleanup();
        if (strpos($a, 'admin_') === 0) {
            if (!$who['admin']) $out(['error' => 'Nur für Moderatoren.'], 403);
            $out(nw_admin($who['uid'], $a, $in));
        }
        switch ($a) {
            case 'list': $out(['items' => nw_list($who['uid']), 'mine' => nw_mine($who['uid']), 'me' => nw_me($who), 'min' => nw_min(), 'admin' => $who['admin'], 'reasons' => NW_REASONS]);
            case 'report': $out(nw_report($who, $in));
            case 'withdraw': $out(nw_withdraw($who, (string)($in['target'] ?? '')));
            case 'appeal': $out(nw_appeal($who, (string)($in['text'] ?? '')));
        }
        $out(['error' => 'Unbekannte Aktion.'], 400);
    } catch (NwError $e) {
        $out(['error' => $e->getMessage()], 400);
    } catch (Throwable $e) {
        error_log('nexuswarn: ' . $e->getMessage());
        $out(['error' => 'Serverfehler.'], 500);
    }
}

// =============================== Verwaltung (Webseite) ===============================
require_permission('admin.access');
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    guard_post('admin.access');
    try {
        $r = nw_admin('web:' . user_label(), (string)($_POST['do'] ?? ''), $_POST);
        audit('Nexus-Warnliste', (string)($_POST['do'] ?? ''), 'info');
        $msg = (string)($r['msg'] ?? 'Gespeichert.');
    } catch (NwError $e) { $msg = '⚠ ' . $e->getMessage(); }
}
$d = nw_admin_data();
$by = [];
foreach ($d['reports'] as $r) $by[$r['target']][] = $r;
$when = fn($t) => $t ? date('d.m.Y H:i', (int)$t) : '–';
$reasonSel = function (string $cur) { $h = '<select name="reason">'; foreach (NW_REASONS as $k => $v) $h .= '<option value="' . e($k) . '"' . ($k === $cur ? ' selected' : '') . '>' . e($v[0] . ' ' . $v[1]) . '</option>'; return $h . '</select>'; };
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nexus-Warnliste · GhostNode</title>
    <?php echo emoji_favicon('⚠'); ?>
    <link rel="stylesheet" href="assets/css/hud.css">
    <style>
        .nw-wrap { max-width: 1100px; margin: 0 auto; padding: 20px 16px 60px; position: relative; z-index: 2; }
        .nw-sec { margin: 26px 0; }
        .nw-sec h2 { color: var(--accent-cyan); font-size: .9rem; letter-spacing: 2px; text-transform: uppercase; border-bottom: 1px solid var(--border-color); padding-bottom: 8px; }
        .nw-card { background: var(--panel-bg); border: 1px solid var(--border-color); clip-path: var(--cut-sm); padding: 14px; margin: 10px 0; }
        .nw-card h3 { margin: 0 0 6px; color: var(--accent-gold); font-size: 1rem; }
        .nw-card small, .nw-muted { color: var(--text-muted); }
        .nw-rep { border-top: 1px solid rgba(56,213,230,.14); padding: 10px 0; display: grid; gap: 6px; }
        .nw-rep form { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .nw-rep input[type=text], .nw-rep textarea { flex: 1 1 220px; }
        .nw-rep textarea { min-height: 50px; width: 100%; }
        .nw-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .nw-msg { padding: 10px 14px; border-left: 3px solid var(--accent-gold); background: rgba(243,194,98,.08); }
        .nw-log { font-size: .78rem; max-height: 420px; overflow: auto; }
        .nw-log div { padding: 3px 0; border-bottom: 1px solid rgba(56,213,230,.08); }
        .nw-pill { display: inline-block; padding: 2px 8px; border: 1px solid var(--accent-red, #ff5c5c); color: var(--accent-red, #ff5c5c); font-size: .72rem; letter-spacing: 1px; }
        code { font-size: .78rem; }
        @media (max-width: 640px) { .nw-rep input[type=text] { flex-basis: 100%; } }
    </style>
</head>
<body>
<div class="nw-wrap">
    <h1>⚠ NEXUS-WARNLISTE</h1>
    <p class="nw-muted">Gemeinsame Warnliste der VRC-Nexus-App (Crasher, Ripper, Troller). Sichtbar für freigeschaltete Nutzer erst ab <b><?= (int)$d['min'] ?></b> unabhängigen Meldungen. Moderatoren ernennt die Besitzerin in der App (👑 Admin → Freischalt-Schlüssel mit Recht „🛡 Moderator“).</p>
    <?php if ($msg !== ''): ?><p class="nw-msg"><?= e($msg) ?></p><?php endif; ?>

    <section class="nw-sec"><h2>Einstellungen</h2>
        <form method="post" class="nw-card nw-row">
            <input type="hidden" name="do" value="admin_settings">
            <label>Sichtbar ab <input type="number" name="min" min="1" max="10" value="<?= (int)$d['min'] ?>" style="width:70px"> Meldungen</label>
            <label style="flex:1">Gesperrte Schlüssel-IDs <input type="text" name="revoked" value="<?= e(implode(', ', $d['revoked'])) ?>" style="width:100%"></label>
            <button class="btn">Speichern</button>
        </form>
    </section>

    <section class="nw-sec"><h2>Einsprüche (<?= count(array_filter($d['appeals'], fn($a) => $a['status'] === 'open')) ?> offen)</h2>
        <?php if (!$d['appeals']): ?><p class="nw-muted">Keine Einsprüche.</p><?php endif; ?>
        <?php foreach ($d['appeals'] as $ap): ?>
            <div class="nw-card"><div class="nw-row"><code><?= e($ap['target']) ?></code><small><?= e($when($ap['created'])) ?></small><span class="nw-pill"><?= e($ap['status']) ?></span></div>
                <p><?= nl2br(e($ap['text'])) ?></p>
                <?php if ($ap['status'] === 'open'): ?>
                    <form method="post" class="nw-row"><input type="hidden" name="do" value="admin_appeal"><input type="hidden" name="id" value="<?= (int)$ap['id'] ?>">
                        <input type="text" name="answer" placeholder="Antwort (optional)" style="flex:1">
                        <button class="btn" name="accept" value="1" onclick="return confirm('Einspruch annehmen und ALLE Meldungen zu dieser Person löschen?')">✔ Annehmen</button>
                        <button class="btn">✖ Ablehnen</button></form>
                <?php elseif ($ap['answer'] !== ''): ?><small>Antwort: <?= e($ap['answer']) ?></small><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="nw-sec"><h2>Gemeldete Personen (<?= count($by) ?>)</h2>
        <?php if (!$by): ?><p class="nw-muted">Noch keine Meldungen.</p><?php endif; ?>
        <?php foreach ($by as $target => $list): ?>
            <div class="nw-card">
                <div class="nw-row"><h3><?= e($list[0]['target_name'] ?: $target) ?></h3><code><?= e($target) ?></code>
                    <span class="nw-pill"><?= count($list) ?> Meldung<?= count($list) === 1 ? '' : 'en' ?><?= count($list) >= $d['min'] ? ' · sichtbar' : '' ?></span>
                    <a href="https://vrchat.com/home/user/<?= e($target) ?>" target="_blank" rel="noopener noreferrer">↗ VRChat</a>
                    <form method="post" style="margin-left:auto"><input type="hidden" name="do" value="admin_clear"><input type="hidden" name="target" value="<?= e($target) ?>"><button class="btn" onclick="return confirm('Alle Meldungen zu dieser Person löschen?')">🗑 Alle löschen</button></form></div>
                <?php foreach ($list as $r): ?>
                    <div class="nw-rep"><small>#<?= (int)$r['id'] ?> · von <?= e($r['reporter_name'] ?: $r['reporter']) ?> <code><?= e($r['reporter']) ?></code> · <?= e($when($r['created'])) ?> · läuft ab <?= e($when($r['expires'])) ?></small>
                        <form method="post"><input type="hidden" name="do" value="admin_update"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <?= $reasonSel($r['reason']) ?>
                            <input type="text" name="name" value="<?= e($r['target_name']) ?>" placeholder="Anzeigename">
                            <input type="text" name="note" value="<?= e($r['note']) ?>" placeholder="Notiz">
                            <label>Neu: <input type="number" name="days" min="0" max="365" value="0" style="width:70px"> Tage</label>
                            <textarea name="evidence" placeholder="Beweis"><?= e($r['evidence']) ?></textarea>
                            <button class="btn">Speichern</button></form>
                        <form method="post"><input type="hidden" name="do" value="admin_delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn" onclick="return confirm('Meldung löschen?')">🗑 Meldung löschen</button></form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="nw-sec"><h2>Protokoll</h2>
        <div class="nw-card nw-log"><?php foreach ($d['log'] as $l): ?><div><?= e($when($l['ts'])) ?> · <b><?= e($l['action']) ?></b> <?= $l['target'] ? '<code>' . e($l['target']) . '</code>' : '' ?> <small><?= e($l['actor']) ?> <?= e($l['detail']) ?></small></div><?php endforeach; ?></div>
    </section>
    <p><a href="admin.php">← Admin-Zentrale</a></p>
</div>
</body>
</html>
