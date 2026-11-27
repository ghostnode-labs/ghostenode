<?php
declare(strict_types=1);
/**
 * D2 Nexus: Destiny-2-Zentrale – Wochen-Reset, Rotation, Xûr, Bungie-News und Download der D2-Nexus-App.
 * <title>D2 Nexus</title>
 */
require_once __DIR__ . '/inc/core/common.php';
require_once __DIR__ . '/inc/core/pages.php';
auth_require_setup();
require_once __DIR__ . '/inc/modules/d2.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    guard_post('system.manage');
    $k = trim((string)($_POST['d2_key'] ?? ''));
    if (($_POST['action'] ?? '') === 'd2_key') {
        if ($k !== '' && !preg_match('/^[a-f0-9]{32}$/i', $k)) $msg = '✖ Der API-Key hat 32 Zeichen (0-9, a-f).';
        else { set_setting('d2_bungie_key', $k); audit('D2 Nexus: Bungie-API-Key ' . ($k === '' ? 'entfernt' : 'gespeichert'), '', 'security'); @unlink(d2_cache_dir() . '/overview.json'); $msg = $k === '' ? '✔ Key entfernt.' : '✔ Key gespeichert.'; }
    }
    if (($_POST['action'] ?? '') === 'd2_oauth') {
        $id = trim((string)($_POST['d2_cid'] ?? '')); $sec = trim((string)($_POST['d2_csec'] ?? ''));
        if ($id !== '' && !preg_match('/^\d{1,10}$/', $id)) $msg = '✖ Die Client-ID ist eine Zahl.';
        elseif ($sec !== '' && strlen($sec) < 16) $msg = '✖ Das Client-Secret ist zu kurz.';
        else {
            set_setting('d2_client_id', $id);
            if ($sec !== '' || !empty($_POST['d2_csec_del'])) set_setting('d2_client_secret', $sec);
            audit('D2 Nexus: Bungie-OAuth geändert', 'Client-ID ' . ($id ?: '–'), 'security');
            $msg = '✔ Anmelde-Einstellungen gespeichert.';
        }
    }
    if (($_POST['action'] ?? '') === 'd2_refresh') { d2_overview(true); $msg = '✔ Neu geladen.'; }
}

$ov = d2_overview();
$rel = d2_release();
$reset = d2_next_reset();
$img = fn(string $p) => $p === '' ? '' : (str_starts_with($p, 'http') ? $p : 'https://www.bungie.net' . $p);
$until = function (int $ts): string { $s = max(0, $ts - time()); $d = intdiv($s, 86400); $h = intdiv($s % 86400, 3600); $m = intdiv($s % 3600, 60); return ($d ? $d . ' T ' : '') . $h . ' h ' . $m . ' min'; };
$asset = function (?array $rel, string $suffix) { foreach ($rel['assets'] ?? [] as $a) if (str_ends_with($a['name'], $suffix)) return $a; return null; };
$size = fn(int $b) => number_format($b / 1048576, 1, ',', '.') . ' MB';
// Xûr ist von Freitag 17:00 UTC bis zum Reset am Dienstag da
$xurNext = $reset - 4 * 86400;
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>D2 Nexus · GhostNode</title>
<meta name="description" content="D2 Nexus – Destiny 2: Wochen-Reset, Rotation, Xûr, Bungie-News und die D2-Nexus-App für Windows und Linux.">
<link rel="stylesheet" href="assets/css/hud.css">
<style>
body.d2{padding:0;display:block}.d2-wrap{box-sizing:border-box;max-width:1180px;margin:0 auto;padding:20px 20px 60px}
.d2-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px}
.d2-card{border:1px solid var(--border-color);background:var(--panel-bg);clip-path:var(--cut-md);overflow:hidden;display:flex;flex-direction:column}
.d2-card .im{height:120px;background:#111 center/cover;position:relative}.d2-card .im b{position:absolute;left:0;right:0;bottom:0;padding:8px 12px;background:linear-gradient(transparent,rgba(0,0,0,.85))}
.d2-card .bd{padding:10px 14px;font-size:.86rem;line-height:1.45}
.d2-hero{border:1px solid var(--border-color);background:linear-gradient(120deg,rgba(56,213,230,.12),rgba(206,174,51,.08));padding:18px;clip-path:var(--cut-md);display:flex;gap:18px;align-items:center;flex-wrap:wrap;margin-bottom:18px}
.d2-hero h1{margin:0;letter-spacing:.08em}.d2-hero .grow{flex:1;min-width:260px}
.muted{color:var(--text-muted);font-size:.85rem}.sec{margin:26px 0 10px;color:var(--accent-cyan);letter-spacing:.18em;font-size:.78rem;text-transform:uppercase}
.xi{display:flex;gap:10px;align-items:center;padding:6px 0;border-bottom:1px dashed rgba(255,255,255,.06)}.xi img{width:40px;height:40px;border:2px solid #7b4fa0}.xi img.ex{border-color:#ceae33}
.cd{font-size:1.6rem;font-weight:700;color:var(--accent-gold)}
.feat{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px;margin-top:10px}.feat div{background:rgba(255,255,255,.04);padding:8px 10px;font-size:.84rem}
</style></head><body class="d2"><div class="d2-wrap">

<div class="d2-hero">
  <img src="assets/img/d2nexus.png" alt="" width="84" height="84">
  <div class="grow">
    <h1>D2 NEXUS</h1>
    <div class="muted">Destiny-2-Begleiter von GhostNode: Inventar &amp; Tresor, Loadouts, Rüstungs-Optimierer, Woche, Triumphe, Sammlungen, Händler, Clan und Statistik – direkt über die offizielle Bungie-API, Schlüssel bleiben auf deinem PC.</div>
  </div>
  <div>
    <?php if ($rel): $setup = $asset($rel, '-Setup.exe'); $port = $asset($rel, '-portable.exe'); $app = $asset($rel, '.AppImage'); ?>
      <div class="muted">Version <b><?= e($rel['v']) ?></b> vom <?= e(date('d.m.Y', strtotime($rel['date']) ?: time())) ?></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
        <?php if ($setup): ?><a class="btn btn-gold" href="<?= e($setup['url']) ?>">🪟 Windows-Installer (<?= e($size($setup['size'])) ?>)</a><?php endif; ?>
        <?php if ($port): ?><a class="btn" href="<?= e($port['url']) ?>">🧳 portabel</a><?php endif; ?>
        <?php if ($app): ?><a class="btn" href="<?= e($app['url']) ?>">🐧 Linux / Steam Deck</a><?php endif; ?>
        <a class="btn" href="<?= e($rel['url']) ?>" target="_blank" rel="noopener">alle Dateien</a>
      </div>
    <?php else: ?><div class="muted">Der erste Download erscheint hier, sobald GitHub ihn gebaut hat.</div><?php endif; ?>
  </div>
</div>

<?php if ($msg): ?><p class="muted"><?= e($msg) ?></p><?php endif; ?>

<div class="d2-grid">
  <div class="d2-card"><div class="bd"><div class="sec" style="margin-top:0">// Wochen-Reset</div><div class="cd"><?= e($until($reset)) ?></div><div class="muted"><?= e(['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][(int)date('w', $reset)] . ', ' . date('d.m. H:i', $reset)) ?> Uhr (Dienstag 17:00 UTC)</div></div></div>
  <div class="d2-card"><div class="bd"><div class="sec" style="margin-top:0">// Xûr</div>
    <?php if (!empty($ov['xur']['items'])): ?><div class="cd" style="color:#38d5e6">ist da</div><div class="muted">bis zum Reset · <?= e($until($reset)) ?></div>
    <?php elseif (time() < $xurNext): ?><div class="cd"><?= e($until($xurNext)) ?></div><div class="muted">bis Xûr kommt (Freitag 17:00 UTC)</div>
    <?php else: ?><div class="muted">Gerade keine Angaben.</div><?php endif; ?></div></div>
  <div class="d2-card"><div class="bd"><div class="sec" style="margin-top:0">// Was die App kann</div>
    <div class="feat" style="grid-template-columns:1fr 1fr"><div>🎒 Inventar &amp; Tresor</div><div>🧩 Loadouts</div><div>📐 Optimierer</div><div>🏆 Triumphe &amp; Titel</div><div>🛒 Händler &amp; Xûr</div><div>🛡 Clan &amp; Freunde</div></div></div></div>
</div>

<?php if (($ov['error'] ?? '') === 'nokey'): ?>
  <p class="muted" style="margin-top:18px">Live-Daten (Rotation, Xûr, News) erscheinen, sobald ein Bungie-API-Key hinterlegt ist.</p>
<?php else: ?>
  <?php if (!empty($ov['xur']['items'])): ?>
  <div class="sec">// Xûrs Angebot</div>
  <div class="d2-card"><div class="bd" style="columns:2 300px">
    <?php foreach ($ov['xur']['items'] as $it): ?><div class="xi" style="break-inside:avoid"><img src="<?= e($img($it['icon'])) ?>" alt="" loading="lazy" class="<?= $it['exotic'] ? 'ex' : '' ?>"><div style="flex:1"><b><?= e($it['name']) ?></b><div class="muted"><?= e($it['type']) ?></div></div><div class="muted" style="text-align:right"><?= e(implode(' · ', $it['costs'])) ?></div></div><?php endforeach; ?>
  </div></div>
  <?php endif; ?>

  <div class="sec">// Diese Woche</div>
  <?php if (!empty($ov['milestones_error'])): ?><p class="muted"><?= e($ov['milestones_error']) ?></p><?php endif; ?>
  <div class="d2-grid">
    <?php foreach ($ov['milestones'] ?? [] as $m): ?>
      <div class="d2-card"><div class="im" style="background-image:url('<?= e($img($m['image'])) ?>')"><b><?= e($m['name']) ?></b></div>
        <div class="bd"><?php if ($m['activities']): ?><?= implode('<br>', array_map(fn($a) => '• ' . e($a), array_slice($m['activities'], 0, 5))) ?><?php else: ?><span class="muted"><?= e(mb_substr($m['desc'], 0, 140)) ?></span><?php endif; ?></div></div>
    <?php endforeach; ?>
  </div>

  <div class="sec">// Neuigkeiten von Bungie</div>
  <div class="d2-grid">
    <?php foreach ($ov['news'] ?? [] as $n): ?>
      <a class="d2-card" href="<?= e($n['link']) ?>" target="_blank" rel="noopener" style="text-decoration:none;color:inherit"><div class="im" style="background-image:url('<?= e($img($n['image'])) ?>')"></div>
        <div class="bd"><b><?= e($n['title']) ?></b><div class="muted"><?= e(date('d.m.Y', strtotime($n['date']) ?: time())) ?></div><div><?= e(mb_substr($n['desc'], 0, 160)) ?></div></div></a>
    <?php endforeach; ?>
  </div>
  <p class="muted" style="margin-top:12px">Stand: <?= e(date('d.m.Y H:i', (int)($ov['at'] ?? time()))) ?> · wird alle 15 Minuten aktualisiert.</p>
<?php endif; ?>

<?php if (can('system.manage')): ?>
  <div class="sec">// Admin: Bungie-API-Key für diese Seite</div>
  <div class="d2-card"><div class="bd">
    <p class="muted">Nur für die öffentlichen Daten hier (Woche, Xûr, News). Key anlegen auf bungie.net/en/Application (Typ „Not Applicable“ reicht). Alternativ als Umgebungsvariable <code>GN_BUNGIE_KEY</code>.
      Status: <?= d2_key() !== '' ? '<b style="color:#4ade80">eingetragen</b>' . (getenv('GN_BUNGIE_KEY') ? ' (aus der Umgebung)' : '') : '<b>fehlt</b>' ?></p>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap"><input type="hidden" name="action" value="d2_key"><input name="d2_key" type="password" placeholder="32 Zeichen (leer = entfernen)" autocomplete="off" style="flex:1;min-width:240px"><button class="btn">Speichern</button></form>
    <form method="post" style="margin-top:8px"><input type="hidden" name="action" value="d2_refresh"><button class="btn">⟳ Jetzt neu laden</button></form>
  </div></div>
  <div class="sec">// Admin: Anmeldung der D2-Nexus-App (eingebaute Bungie-App)</div>
  <div class="d2-card"><div class="bd">
    <p class="muted">Damit sich Nutzer in D2 Nexus nur noch anmelden müssen, tauscht dieser Server den Anmelde-Code mit dem Client-Secret ein (<code>d2auth.php</code>). Bungie-App: Typ <b>Confidential</b>, Redirect-URL <code>https://<?= e($_SERVER['HTTP_HOST'] ?? 'ghostnode-labs.dev') ?>/d2-callback</code>.
      Am besten per SSH: <code>ghostnode-root.sh secrets</code> → GN_BUNGIE_KEY, GN_BUNGIE_CLIENT_ID, GN_BUNGIE_CLIENT_SECRET. Sonst hier:</p>
    <p class="muted">Status: <?= d2_oauth() ? '<b style="color:#4ade80">eingerichtet</b>' . (getenv('GN_BUNGIE_CLIENT_SECRET') ? ' (aus der Umgebung)' : '') : '<b>fehlt:</b> ' . e(implode(', ', d2_oauth_missing())) ?></p>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap"><input type="hidden" name="action" value="d2_oauth">
      <input name="d2_cid" placeholder="OAuth client_id" value="<?= e(setting('d2_client_id', '')) ?>" autocomplete="off" style="width:180px">
      <input name="d2_csec" type="password" placeholder="<?= setting('d2_client_secret', '') !== '' ? '•••••• (leer = behalten)' : 'OAuth client_secret' ?>" autocomplete="off" style="flex:1;min-width:240px">
      <label class="muted"><input type="checkbox" name="d2_csec_del" value="1"> Secret löschen</label><button class="btn">Speichern</button></form>
  </div></div>
<?php endif; ?>
<p class="muted" style="margin-top:24px">D2 Nexus ist nicht mit Bungie verbunden oder von Bungie unterstützt. Destiny ist eine Marke von Bungie, Inc.</p>
</div></body></html>
