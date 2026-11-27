<?php
/**
 * Nexus-Konto – Schnittstelle für die VRC-Nexus-App und Verwaltung für Admins.
 * <title>Nexus-Konten</title>
 *
 * App (JSON, ohne Sitzung/Cookies):
 *   POST nexusacct.php?a=register {"vrc_id","name","device"}    → {"token","account"}  (seit 3.0.2: direkt mit der VRChat-ID, ohne Code)
 *   POST nexusacct.php?a=code     {"vrc_id"}                    → {"code":"NX-…","expires":…}   (Code in die VRChat-Bio)
 *   POST nexusacct.php?a=verify   {"vrc_id","device"}           → {"token":"…","account":{…}}  (Bio wird über das Dienst-Konto geprüft)
 *   POST nexusacct.php?a=me       {"token"}                     → {"account":{…},"devices":[…]}
 *   POST nexusacct.php?a=nsfw     {"token","on":true|false}     → {"account":{…}}              (nur mit Verified 18+)
 *   POST nexusacct.php?a=age      {"token","status"}            → {"account":{…}}  (Ergebnis von VRChat GET /ageVerification/status aus der App)
 *   POST nexusacct.php?a=recheck  {"token"}                     → {"account":{…}}
 *   POST nexusacct.php?a=peer     {"token","uid"}              → {"nsfw":bool}  (nur wenn man selbst NSFW-freigegeben ist)
 *   POST nexusacct.php?a=logout   {"token","device"?}
 * Webseite: GET nexusacct.php → Verwaltung (Recht „admin.access“): Dienst-Konto, Konten, Sperren.
 */
require_once __DIR__ . '/inc/core/jsonguard.php'; if (isset($_GET['a'])) json_guard();
require_once __DIR__ . '/inc/core/pages.php';
require_once __DIR__ . '/inc/modules/nexusacct.php';

if (isset($_GET['a'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $out = function (array $o, int $code = 200) { json_guard_clean(); http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE); exit; };
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $out(['error' => 'Nur POST.'], 405);
    $a = (string)$_GET['a'];
    // Bremse pro IP; Code/Prüfen strenger (fragt VRChat ab)
    if (nw_rate_over(client_ip(), 60) || (in_array($a, ['code', 'verify', 'register'], true) && nw_rate_over('v|' . client_ip(), 10))) $out(['error' => 'Zu viele Anfragen – kurz warten.'], 429);
    $in = json_decode((string)file_get_contents('php://input', false, null, 0, 8000), true);
    if (!is_array($in)) $out(['error' => 'JSON erwartet.'], 400);
    try {
        if ($a === 'register') $out(nx_register(strtolower(trim((string)($in['vrc_id'] ?? ''))), (string)($in['name'] ?? ''), (string)($in['device'] ?? '')));   // seit 3.0.2: direkt, ohne Code
        if ($a === 'code') $out(nx_code(strtolower(trim((string)($in['vrc_id'] ?? '')))));
        if ($a === 'verify') $out(nx_verify(strtolower(trim((string)($in['vrc_id'] ?? ''))), (string)($in['device'] ?? '')));
        $acc = nx_auth((string)($in['token'] ?? ''));
        switch ($a) {
            case 'me': nx_verified18($acc); $out(['account' => nx_public($acc), 'devices' => nx_devices($acc)]);
            case 'nsfw': $out(['account' => nx_set_nsfw($acc, !empty($in['on']))]);
            case 'recheck': $out(['account' => nx_recheck($acc)]);
            case 'age': $out(['account' => nx_report_age($acc, (string)($in['status'] ?? ''))]);   // VRChat /ageVerification/status, abgefragt von der App
            case 'peer': {
                // Darf die andere Person NSFW (z. B. Spielzeug-Fernsteuerung)? Nur für selbst freigegebene Konten abfragbar.
                nx_require_nsfw($acc);
                $pid = strtolower(trim((string)($in['uid'] ?? '')));
                if (!nx_uid_ok($pid)) throw new NxError('Ungültige VRChat-User-ID.');
                $p = nx_account($pid);
                $ok = $p && empty($p['banned']) && nx_nsfw_allowed($p);
                if (!$p) { require_once __DIR__ . '/inc/modules/federation.php'; $ok = fed_peer18($pid); }   // 8.2: unbekannt hier → signiert bei Partner-Servern nachfragen
                $out(['uid' => $pid, 'nsfw' => (bool)$ok]);
            }
            case 'logout': nx_logout($acc, preg_replace('/[^0-9a-f]/', '', (string)($in['device'] ?? ''))); $out(['ok' => true]);
        }
        $out(['error' => 'Unbekannte Aktion.'], 400);
    } catch (NxError $e) {
        $out(['error' => $e->getMessage()], $e->getMessage() === 'Nicht angemeldet.' ? 401 : 400);
    } catch (Throwable $e) {
        error_log('nexusacct: ' . $e->getMessage());
        $out(['error' => 'Serverfehler.'], 500);
    }
}

// =============================== Verwaltung (Webseite) ===============================
require_permission('admin.access');
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    guard_post('admin.access');
    $do = (string)($_POST['do'] ?? '');
    $id = strtolower((string)($_POST['vrc_id'] ?? ''));
    try {
        if ($do === 'service') { set_setting('nexus_service_uid', (string)(int)($_POST['uid'] ?? 0)); $msg = 'Dienst-Konto gespeichert.'; }
        elseif ($do === 'ban' && nx_uid_ok($id)) { nx_db()->prepare('UPDATE nexus_accounts SET banned = ?, nsfw_on = 0 WHERE vrc_id = ?')->execute([empty($_POST['off']) ? 1 : 0, $id]); if (empty($_POST['off'])) nx_db()->prepare('DELETE FROM nexus_devices WHERE vrc_id = ?')->execute([$id]); nw_log('web:' . user_label(), empty($_POST['off']) ? 'Admin: Konto gesperrt' : 'Admin: Konto entsperrt', $id); $msg = 'Gespeichert.'; }
        elseif ($do === 'recheck' && nx_uid_ok($id)) { $ok = nx_apply_age($id, nx_vrc_user($id)); $msg = $ok ? '✔ Verified 18+' : '✖ Kein sichtbarer 18+-Status – Freigabe entzogen.'; }
        elseif ($do === 'cron') { $msg = nx_cron(20) . ' Konten neu geprüft.'; }
        audit('Nexus-Konten', $do . ' ' . $id, 'info');
    } catch (NxError $e) { $msg = '⚠ ' . $e->getMessage(); }
}
$svc = nx_service_uid();
$users = site_db()->query('SELECT id, username FROM users ORDER BY username')->fetchAll(PDO::FETCH_ASSOC);
$vacc = []; foreach ($users as $u) { $x = vrc_account((int)$u['id']); if ($x) $vacc[(int)$u['id']] = $x['display_name'] ?? ''; }
$list = nx_db()->query('SELECT a.*, (SELECT COUNT(*) FROM nexus_devices d WHERE d.vrc_id = a.vrc_id) AS devs FROM nexus_accounts a ORDER BY last_seen DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
$when = fn($t) => $t ? date('d.m.Y H:i', (int)$t) : '–';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nexus-Konten · GhostNode</title>
    <?php echo emoji_favicon('🪪'); ?>
    <link rel="stylesheet" href="assets/css/hud.css">
    <style>
        .nx-wrap { max-width: 1100px; margin: 0 auto; padding: 20px 16px 60px; position: relative; z-index: 2; }
        .nx-sec h2 { color: var(--accent-cyan); font-size: .9rem; letter-spacing: 2px; text-transform: uppercase; border-bottom: 1px solid var(--border-color); padding-bottom: 8px; }
        .nx-card { background: var(--panel-bg); border: 1px solid var(--border-color); clip-path: var(--cut-sm); padding: 14px; margin: 10px 0; }
        .nx-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .nx-msg { padding: 10px 14px; border-left: 3px solid var(--accent-gold); background: rgba(243,194,98,.08); }
        table { width: 100%; border-collapse: collapse; font-size: .82rem; } th, td { padding: 6px 8px; border-bottom: 1px solid rgba(56,213,230,.12); text-align: left; }
        .ok { color: var(--accent-green, #51e57e); } .bad { color: #ff5c5c; } .muted { color: var(--text-muted); }
        @media (max-width: 700px) { table, tbody, tr, td { display: block; } thead { display: none; } td { border: 0; padding: 2px 0; } tr { border-bottom: 1px solid var(--border-color); padding: 8px 0; } }
    </style>
</head>
<body>
<div class="nx-wrap">
    <h1>🪪 NEXUS-KONTEN</h1>
    <p class="muted">Zentrales Konto der VRC-Nexus-App (an der VRChat-User-ID, Nachweis per Bio-Code). Altersfreigabe „Verified 18+“ nur über den sichtbaren VRChat-Status <code>ageVerificationStatus = 18+</code>, alle 7 Tage neu geprüft.</p>
    <?php if ($msg !== ''): ?><p class="nx-msg"><?= e($msg) ?></p><?php endif; ?>
    <section class="nx-sec"><h2>Dienst-Konto für die VRChat-Prüfung</h2>
        <form method="post" class="nx-card nx-row"><input type="hidden" name="do" value="service">
            <select name="uid"><option value="0">– keins –</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= $svc === (int)$u['id'] ? ' selected' : '' ?><?= isset($vacc[(int)$u['id']]) ? '' : ' disabled' ?>><?= e($u['username']) ?><?= isset($vacc[(int)$u['id']]) ? ' → VRChat: ' . e($vacc[(int)$u['id']]) : ' (kein VRChat verbunden)' ?></option><?php endforeach; ?></select>
            <button class="btn">Speichern</button>
            <span class="muted">Empfehlung: ein eigenes VRChat-Zweitkonto im Dashboard (VRC Nexus) verbinden und hier wählen.</span></form>
        <form method="post" class="nx-row"><input type="hidden" name="do" value="cron"><button class="btn">⟳ Ablaufende Freigaben jetzt prüfen</button></form>
    </section>
    <section class="nx-sec"><h2>Konten (<?= count($list) ?>)</h2>
        <div class="nx-card"><table><thead><tr><th>Name</th><th>VRChat-ID</th><th>Verified 18+</th><th>NSFW</th><th>Geräte</th><th>Zuletzt</th><th></th></tr></thead><tbody>
        <?php foreach ($list as $a): $v = (int)$a['age_until'] > time(); ?>
            <tr><td><?= e($a['display_name']) ?><?= $a['banned'] ? ' <span class="bad">gesperrt</span>' : '' ?></td><td><code><?= e($a['vrc_id']) ?></code></td>
                <td class="<?= $v ? 'ok' : 'bad' ?>"><?= $v ? '✔ bis ' . e($when($a['age_until'])) : '✖ ' . e($a['age_status'] ?: '–') ?></td><td><?= $v && $a['nsfw_on'] ? 'an' : 'aus' ?></td>
                <td><?= (int)$a['devs'] ?></td><td><?= e($when($a['last_seen'])) ?></td>
                <td class="nx-row"><form method="post"><input type="hidden" name="do" value="recheck"><input type="hidden" name="vrc_id" value="<?= e($a['vrc_id']) ?>"><button class="btn">⟳ 18+</button></form>
                    <form method="post"><input type="hidden" name="do" value="ban"><input type="hidden" name="vrc_id" value="<?= e($a['vrc_id']) ?>"><?php if ($a['banned']): ?><input type="hidden" name="off" value="1"><button class="btn">Entsperren</button><?php else: ?><button class="btn" onclick="return confirm('Konto sperren und alle Geräte abmelden?')">Sperren</button><?php endif; ?></form></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </section>
    <p><a href="admin.php">← Admin-Zentrale</a> · <a href="nexuswarn.php">⚠ Nexus-Warnliste</a></p>
</div>
</body>
</html>
