<?php
declare(strict_types=1);
/**
 * 6.6 „Mein Bereich“ – Darstellung, Benachrichtigungen, Webhooks, Lizenzen, öffentliches Profil, Datenschutz.
 * <title>Mein Bereich</title>
 * Nur für angemeldete Konten; Passwort, Zwei-Faktor und Geräte bleiben unter „Mein Konto“ (admin.php?tab=account).
 */
require_once __DIR__ . '/inc/core/common.php';
require_once __DIR__ . '/inc/core/pages.php';
auth_require_setup();
require_once __DIR__ . '/inc/modules/me.php';

$me = current_user();
if (!$me) { header('Location: admin.php?next=me.php'); exit; }
guard_post();
$uid = (int)$me['id'];
$tab = preg_replace('/[^a-z]/', '', (string)($_GET['tab'] ?? $_POST['tab'] ?? 'notes')) ?: 'notes';
$msg = ''; $err = '';

if (($_GET['export'] ?? '') === '1') {   // Datenschutz: alle eigenen Daten
    audit('Datenexport', 'eigene Daten heruntergeladen', 'info', $me);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="ghostnode-meine-daten_' . date('Y-m-d') . '.json"');
    header('Cache-Control: no-store');
    echo json_encode(me_export($me), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch ((string)($_POST['action'] ?? '')) {
            case 'look': me_look_save($uid, $_POST); $msg = 'Darstellung gespeichert.'; break;
            case 'profile': me_profile_save($uid, $_POST); $msg = 'Profil gespeichert.'; break;
            case 'hooks': me_hooks_save($uid, $_POST); $msg = 'Webhooks gespeichert.'; break;
            case 'hooktest': {
                $h = array_values(array_filter((array)(me_get($uid)['d']['hooks'] ?? []), fn($x) => ($x['id'] ?? '') === (string)($_POST['id'] ?? '')))[0] ?? null;
                if (!$h) throw new RuntimeException('Webhook nicht gefunden.');
                $msg = me_hook_send($h['url'], '🔔 Test von GhostNode – dein Webhook funktioniert.', [], (string)($h['secret'] ?? '')) ? 'Test gesendet ✓' : '';
                if ($msg === '') throw new RuntimeException('Test fehlgeschlagen – Adresse prüfen.');
                break;
            }
            case 'seen': me_notes_seen($uid); $msg = 'Alles als gelesen markiert.'; break;
            case 'priv': $msg = me_privacy_act($uid, (string)($_POST['do'] ?? ''), $_POST); break;   // 8.1 Datenschutz-Center
            case 'delete':
                me_delete(user_load($uid) + $me, (string)($_POST['confirm'] ?? ''), (string)($_POST['password'] ?? ''));
                auth_logout();
                header('Location: index.php?bye=1');
                exit;
        }
    } catch (Throwable $e) { $err = $e->getMessage(); }
}
$p = me_get($uid); $d = $p['d'];
$TABS = ['notes' => ['🔔', 'Benachrichtigungen'], 'look' => ['🎨', 'Darstellung'], 'profile' => ['🌐', 'Öffentliches Profil'], 'hooks' => ['🪝', 'Webhooks'], 'lic' => ['🔑', 'Lizenzen'], 'privacy' => ['🛡', 'Datenschutz']];
if (!isset($TABS[$tab])) $tab = 'notes';
$notes = $tab === 'notes' ? me_notes($me) : [];
$unread = $tab === 'notes' ? count(array_filter($notes, fn($n) => $n['new'])) : 0;
$lic = $tab === 'lic' ? me_licences($uid) : null;
$base = acc_base_url();
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mein Bereich · GhostNode</title>
<link rel="stylesheet" href="assets/css/hud.css">
<style>
body.me{padding:0;display:block}
.me-wrap{box-sizing:border-box;width:100%;max-width:980px;margin:0 auto;padding:20px 20px 60px}
.me-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:14px 0 18px}
.me-tabs a{padding:8px 12px;border:1px solid var(--border-color);color:var(--text-muted);text-decoration:none;font-size:.85rem}
.me-tabs a.on{color:var(--accent-cyan);border-color:var(--accent-cyan);background:rgba(56,213,230,.08)}
.me-card{border:1px solid var(--border-color);background:var(--panel-bg);padding:16px 18px;margin-bottom:14px;clip-path:var(--cut-md)}
.me-card h3{margin:0 0 10px;font-size:1rem;color:var(--accent-gold);letter-spacing:1px}
.me-row{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:8px 0}
.me-row label{min-width:160px;color:var(--text-muted);font-size:.85rem}
.me-row select,.me-row input[type=text],.me-row input[type=url],.me-row input[type=password]{flex:1;min-width:200px;width:auto}
.me-note{display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.06);text-decoration:none;color:inherit}
.me-note b{display:block}.me-note small{color:var(--text-muted)}.me-note.new b::after{content:' • neu';color:var(--accent-cyan);font-size:.75rem}
.me-note span:first-child{font-size:1.3rem}
.me-themes{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px;width:100%}
.me-themes label{display:flex;gap:6px;align-items:center;padding:8px;border:1px solid var(--border-color);cursor:pointer;min-width:0}
.ok{color:var(--accent-green)}.bad{color:var(--accent-red)}
.lic{display:grid;grid-template-columns:1fr auto auto;gap:6px 14px;font-size:.85rem}.lic div{padding:4px 0;border-bottom:1px solid rgba(255,255,255,.05)}
.muted{color:var(--text-muted);font-size:.85rem}
code{background:rgba(255,255,255,.06);padding:1px 6px}
</style></head><body class="me">
<div class="me-wrap">
<h1 style="margin:0">👤 Mein Bereich</h1>
<p class="muted">Angemeldet als <b><?php echo e(user_label($me)); ?></b> · Passwort, Zwei-Faktor und Geräte findest du unter <a href="admin.php?tab=account">Mein Konto</a>.</p>
<nav class="me-tabs"><?php foreach ($TABS as $k => [$i, $l]) echo '<a href="me.php?tab=' . $k . '"' . ($k === $tab ? ' class="on"' : '') . '>' . $i . ' ' . e($l) . '</a>'; ?></nav>
<?php if ($msg): ?><div class="me-card ok">✓ <?php echo e($msg); ?></div><?php endif; ?>
<?php if ($err): ?><div class="me-card bad">⚠ <?php echo e($err); ?></div><?php endif; ?>

<?php if ($tab === 'notes'): ?>
<div class="me-card"><h3>🔔 Benachrichtigungen <?php if ($unread): ?><small class="muted">(<?php echo $unread; ?> neu)</small><?php endif; ?></h3>
<?php if (!$notes): ?><p class="muted">Gerade nichts Neues.</p><?php endif; ?>
<?php foreach ($notes as $n): ?><a class="me-note<?php echo $n['new'] ? ' new' : ''; ?>" href="<?php echo e($n['href']); ?>"><span><?php echo $n['icon']; ?></span><span><b><?php echo e($n['title']); ?></b><small><?php echo e(($n['sub'] ?? '') !== '' ? $n['sub'] : ($n['t'] ? date('d.m.Y H:i', $n['t']) : '')); ?></small></span></a><?php endforeach; ?>
<form method="POST" class="me-row"><input type="hidden" name="tab" value="notes"><button class="btn" name="action" value="seen">✓ Alles gelesen</button></form>
<p class="muted">Neue Versionen, Termine der nächsten 7 Tage und Antworten auf deine Beiträge in der Nexus-Community (wenn dein VRChat-Konto verbunden ist).</p></div>

<?php elseif ($tab === 'look'): ?>
<form method="POST" class="me-card"><input type="hidden" name="tab" value="look"><input type="hidden" name="action" value="look"><h3>🎨 Darstellung – gilt auf allen Seiten, wenn du angemeldet bist</h3>
<div class="me-row"><label>Thema</label><div class="me-themes"><?php foreach (ME_THEMES as $k => [$l]) echo '<label><input type="radio" name="theme" value="' . e($k) . '"' . (($d['theme'] ?? 'cyber') === $k ? ' checked' : '') . '> ' . e($l) . '</label>'; ?></div></div>
<div class="me-row"><label>Schriftart</label><select name="font"><?php foreach (ME_FONTS as $k => $l) echo '<option value="' . e($k) . '"' . (($d['font'] ?? '') === $k ? ' selected' : '') . '>' . e($l) . '</option>'; ?></select></div>
<div class="me-row"><label>Schriftgröße</label><input type="range" name="size" min="85" max="130" step="5" value="<?php echo (int)($d['size'] ?? 100); ?>" oninput="this.nextElementSibling.textContent=this.value+' %'"><b><?php echo (int)($d['size'] ?? 100); ?> %</b></div>
<div class="me-row"><label>Startseite nach dem Anmelden</label><select name="start"><?php foreach (ME_STARTS as $k => $l) echo '<option value="' . e($k) . '"' . (($d['start'] ?? '') === $k ? ' selected' : '') . '>' . e($l) . '</option>'; ?></select></div>
<button class="btn">💾 Speichern</button></form>

<?php elseif ($tab === 'profile'): ?>
<form method="POST" class="me-card"><input type="hidden" name="tab" value="profile"><input type="hidden" name="action" value="profile"><h3>🌐 Öffentliches Profil (freiwillig)</h3>
<div class="me-row"><label><input type="checkbox" name="public" value="1"<?php echo $p['public'] ? ' checked' : ''; ?>> Profil öffentlich zeigen</label></div>
<div class="me-row"><label>Adresse</label><code><?php echo e($base); ?>/u.php?n=</code><input type="text" name="slug" maxlength="24" value="<?php echo e($p['slug']); ?>" placeholder="dein-name"></div>
<div class="me-row"><label>Über mich</label><textarea name="bio" rows="4" maxlength="600" style="flex:1"><?php echo e($d['bio'] ?? ''); ?></textarea></div>
<div class="me-row"><label>Links (https, max. 4)</label><input type="text" name="links" value="<?php echo e(implode(' ', (array)($d['links'] ?? []))); ?>" placeholder="https://… https://…"></div>
<div class="me-row"><label><input type="checkbox" name="show_role" value="1"<?php echo !empty($d['show_role']) ? ' checked' : ''; ?>> Rolle anzeigen</label></div>
<button class="btn">💾 Speichern</button>
<?php if ($p['public'] && $p['slug']): ?> <a class="btn" href="u.php?n=<?php echo e(urlencode($p['slug'])); ?>" target="_blank">👁 Ansehen</a><?php endif; ?>
<p class="muted">Gezeigt werden nur Anzeigename, „Mitglied seit“, dein Text, Links und – wenn du willst – deine Rolle. Keine E-Mail, kein VRChat-Konto.</p></form>

<?php elseif ($tab === 'hooks'): $hooks = (array)($d['hooks'] ?? []); ?>
<div class="me-card"><h3>🪝 Webhooks</h3><p class="muted">Deine Adressen (z. B. ein Discord-Webhook) bekommen eine Nachricht bei neuen VRC-Nexus-Versionen und kurz vor Terminen. Nur https, keine internen Adressen; nach 10 Fehlern wird ein Webhook pausiert. 🔏 Jede Nachricht ist signiert: <code>X-GhostNode-Signature: sha256=HMAC(Schlüssel, Zeitstempel + "." + Inhalt)</code> und <code>X-GhostNode-Timestamp</code> – so kann dein Server prüfen, dass sie wirklich von GhostNode kommt (Discord ignoriert das einfach).</p>
<?php foreach ($hooks as $h): ?><div class="me-row"><code style="flex:1;overflow:hidden;text-overflow:ellipsis"><?php echo e(preg_replace('#(/[^/]{6})[^/]*$#', '$1…', $h['url'])); ?></code>
<span class="muted"><?php echo e(implode(', ', array_map(fn($x) => ME_HOOK_EVENTS[$x] ?? $x, $h['events']))); ?><?php echo (int)($h['fails'] ?? 0) >= 10 ? ' · ⏸ pausiert' : ''; ?></span>
<details class="me-sec"><summary title="Signatur-Schlüssel">🔏</summary><?php if (!empty($h['secret'])): ?><code style="user-select:all"><?php echo e($h['secret']); ?></code><?php else: ?><span class="muted">noch keiner</span><?php endif; ?>
<form method="POST" style="display:inline"><input type="hidden" name="tab" value="hooks"><input type="hidden" name="action" value="hooks"><input type="hidden" name="do" value="secret"><input type="hidden" name="id" value="<?php echo e($h['id']); ?>"><button class="btn btn-sm">↻ neu</button></form></details>
<form method="POST"><input type="hidden" name="tab" value="hooks"><input type="hidden" name="id" value="<?php echo e($h['id']); ?>"><button class="btn" name="action" value="hooktest">🔔 Test</button></form>
<form method="POST"><input type="hidden" name="tab" value="hooks"><input type="hidden" name="action" value="hooks"><input type="hidden" name="do" value="del"><input type="hidden" name="id" value="<?php echo e($h['id']); ?>"><button class="btn">✕</button></form></div><?php endforeach; ?>
<form method="POST"><input type="hidden" name="tab" value="hooks"><input type="hidden" name="action" value="hooks"><input type="hidden" name="do" value="add">
<div class="me-row"><label>Neue Adresse</label><input type="url" name="url" placeholder="https://discord.com/api/webhooks/…" required></div>
<div class="me-row"><label>Ereignisse</label><?php foreach (ME_HOOK_EVENTS as $k => $l) echo '<label style="min-width:0"><input type="checkbox" name="events[]" value="' . e($k) . '" checked> ' . e($l) . '</label>'; ?></div>
<button class="btn">＋ Hinzufügen</button></form></div>

<?php elseif ($tab === 'lic'): ?>
<div class="me-card"><h3>🔑 Meine Lizenzen</h3>
<?php if (!$lic['linked']): ?><p class="muted">Verbinde zuerst dein VRChat-Konto im <a href="nexus.php">VRC Nexus</a> – Schlüssel gehören immer zu einer VRChat-ID.</p>
<?php elseif (!$lic['keys']): ?><p class="muted">Zu <code><?php echo e($lic['vrc_id']); ?></code> sind keine Schlüssel bekannt. Eingegebene Schlüssel siehst du auch in der App unter Einstellungen → Erweitert.</p>
<?php else: ?><div class="lic"><div><b>Rollen</b></div><div><b>gültig bis</b></div><div><b>Status</b></div>
<?php foreach ($lic['keys'] as $k): $exp = (int)$k['exp']; $st = (int)$k['revoked'] ? '<span class="bad">⛔ gesperrt</span>' : ($exp && $exp < time() ? '<span class="bad">abgelaufen</span>' : ($exp && $exp < time() + 14 * 86400 ? '⏳ läuft bald ab' : '<span class="ok">✓ gültig</span>')); ?>
<div><?php echo e(str_replace(',', ', ', (string)$k['roles']) ?: '–'); ?></div><div><?php echo $exp ? date('d.m.Y', $exp) : 'unbegrenzt'; ?></div><div><?php echo $st; ?></div><?php endforeach; ?></div>
<p class="muted">Verlängern oder übertragen kann die Besitzerin – schreib ihr im Discord.</p><?php endif; ?></div>

<?php elseif ($tab === 'privacy'): $pv = me_privacy($uid); $pf = fn($do, $label, $cls = '', $extra = '') => '<form method="POST" style="display:inline"><input type="hidden" name="tab" value="privacy"><input type="hidden" name="action" value="priv"><input type="hidden" name="do" value="' . $do . '">' . $extra . '<button class="btn btn-sm ' . $cls . '">' . $label . '</button></form>'; ?>
<div class="me-card"><h3>🛡 Datenschutz-Center</h3><p class="muted">Alles, was mit deinen Daten passiert, an einem Ort – mit Sofort-Knöpfen. Keine Werbe- oder Tracking-Cookies; nur ein Sitzungs-Cookie für die Anmeldung.</p>
<table class="me-priv">
<tr><td>🌐 Öffentliches Profil</td><td><?php echo $pv['public'] ? '<b>sichtbar</b> unter <a href="u.php?n=' . e(rawurlencode($pv['slug'])) . '">u.php?n=' . e($pv['slug']) . '</a>' : 'aus – niemand sieht ein Profil'; ?></td><td><?php echo $pv['public'] ? $pf('public_off', 'Ausschalten') : '<a class="btn btn-sm" href="me.php?tab=profile">Einstellen</a>'; ?></td></tr>
<tr><td>🖥 Verbundene Geräte (VRC Nexus)</td><td><?php echo $pv['devices'] ? count($pv['devices']) . ' Gerät(e) mit Zugriff' : 'keine'; ?></td><td><?php echo $pv['devices'] ? $pf('devices_all', 'Alle entfernen', 'btn-danger') : ''; ?></td></tr>
<?php foreach ($pv['devices'] as $dv): ?><tr class="sub"><td>↳ <?php echo e($dv['name']); ?></td><td class="muted"><?php echo e(trim(($dv['platform'] ?? '') . ' ' . ($dv['version'] ?? ''))); ?> · <?php echo $dv['last_seen'] ? 'zuletzt ' . e(date('d.m.Y H:i', (int)$dv['last_seen'])) : 'nie benutzt'; ?></td><td><?php echo $pf('device_revoke', 'Entfernen', '', '<input type="hidden" name="id" value="' . (int)$dv['id'] . '">'); ?></td></tr><?php endforeach; ?>
<tr><td>🔐 Angemeldete Sitzungen</td><td><?php echo (int)$pv['sessions']; ?> Sitzung(en) auf deinen Geräten</td><td><?php echo $pv['sessions'] > 1 ? $pf('sessions_others', 'Andere abmelden') : ''; ?></td></tr>
<tr><td>🪝 Webhooks</td><td><?php echo $pv['hooks'] ? $pv['hooks'] . ' Adresse(n) bekommen Nachrichten' : 'keine'; ?></td><td><?php echo $pv['hooks'] ? $pf('hooks_off', 'Alle entfernen') : ''; ?></td></tr>
<tr><td>🟦 VRChat-Verbindung der Website</td><td><?php echo $pv['vrchat'] ? 'verbunden (Sitzungs-Cookie verschlüsselt gespeichert, kein Passwort)' : 'nicht verbunden'; ?></td><td><?php echo $pv['vrchat'] ? '<a class="btn btn-sm" href="vrcx.php">Verwalten</a>' : ''; ?></td></tr>
<tr><td>🍪 Cookies</td><td>nur das Sitzungs-Cookie (und „angemeldet bleiben“, wenn gewählt) – kein Tracking, keine Werbung, keine Dritt-Cookies</td><td></td></tr>
</table></div>
<style>.me-priv{width:100%;border-collapse:collapse;font-size:.9rem}.me-priv td{border-bottom:1px solid var(--border-color);padding:8px;vertical-align:top}.me-priv td:first-child{white-space:nowrap;font-weight:600}.me-priv tr.sub td{font-size:.82rem;padding-left:22px}.me-priv td:last-child{text-align:right;white-space:nowrap}</style>
<div class="me-card"><h3>📦 Meine Daten</h3><p class="muted">Alles, was die Webseite zu deinem Konto speichert, als JSON-Datei – ohne Passwort-Prüfsumme und ohne Zwei-Faktor-Geheimnis.</p>
<a class="btn" href="me.php?export=1">⬇ Daten herunterladen</a></div>
<form method="POST" class="me-card" onsubmit="return confirm('Konto wirklich endgültig löschen?')"><input type="hidden" name="tab" value="privacy"><input type="hidden" name="action" value="delete"><h3 style="color:var(--accent-red)">🗑 Konto löschen</h3>
<p class="muted">Löscht dein Konto, deine Sitzungen, Einstellungen und die VRChat-Verbindung der Webseite. Einträge im Protokoll bleiben ohne IP-Adresse erhalten. Das lässt sich nicht rückgängig machen.</p>
<?php if (!empty($me['role_is_system'])): ?><p class="muted">Das Besitzer-Konto kann nicht gelöscht werden.</p><?php else: ?>
<div class="me-row"><label>Benutzername zur Bestätigung</label><input type="text" name="confirm" autocomplete="off" required></div>
<?php if ((string)(user_load($uid)['password_hash'] ?? '') !== ''): ?><div class="me-row"><label>Passwort</label><input type="password" name="password" autocomplete="current-password" required></div><?php endif; ?>
<button class="btn" style="border-color:var(--accent-red);color:var(--accent-red)">Endgültig löschen</button><?php endif; ?></form>
<?php endif; ?>
</div></body></html>
