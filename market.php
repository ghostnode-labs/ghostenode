<?php
/**
 * 🧩 Plugin-Marktplatz für VRC Nexus – Katalog (öffentlich), Einreichen (market.submit), Prüfen (market.manage).
 * Die App lädt den signierten Katalog über market.php?api=index und Pakete über market.php?dl=<id>&v=<version>.
 */
require_once __DIR__ . '/inc/modules/market.php';
require_once __DIR__ . '/inc/core/format.php';
auth_require_setup();

if (isset($_GET['dl'])) mk_download((string)$_GET['dl'], (string)($_GET['v'] ?? ''));

if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $out = function ($d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
    try {
        $act = (string)$_GET['api'];
        if ($act === 'index') { header('Access-Control-Allow-Origin: *'); $out(mk_index()); }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new InvalidArgumentException('Nur per POST.');
        if (!is_logged_in()) throw new InvalidArgumentException('Bitte anmelden.');
        if (!same_origin_request()) throw new InvalidArgumentException('Anfrage abgelehnt (fremde Herkunft).');
        switch ($act) {
            case 'submit': $r = mk_submit($_FILES['file'] ?? [], (string)($_POST['category'] ?? ''), (string)($_POST['changelog'] ?? ''));
                $out(['msg' => $r['plugin']['name'] . ' ' . $r['plugin']['version'] . ($r['status'] === 'approved' ? ' ist veröffentlicht.' : ' ist eingereicht und wartet auf Prüfung.')]);
            case 'review': mk_review((string)($_POST['id'] ?? ''), (string)($_POST['version'] ?? ''), (string)($_POST['decision'] ?? ''), (string)($_POST['note'] ?? '')); $out(['msg' => 'Gespeichert.']);
            case 'delete': mk_delete((string)($_POST['id'] ?? ''), (string)($_POST['version'] ?? '')); $out(['msg' => 'Gelöscht.']);
        }
        throw new InvalidArgumentException('Unbekannte Aktion.');
    } catch (Throwable $e) {
        if (!($e instanceof InvalidArgumentException)) error_log('Marktplatz: ' . $e->getMessage());
        $out(['error' => $e instanceof InvalidArgumentException ? $e->getMessage() : 'Interner Fehler.'], $e instanceof InvalidArgumentException ? 400 : 500);
    }
}

$me = current_user();
$all = mk_load();
$canSubmit = $me && (can('market.submit') || can('market.manage'));
$canManage = $me && can('market.manage');
$public = [];
foreach ($all as $p) { $v = mk_latest($p); if ($v) $public[] = [$p, $v]; }
usort($public, fn($a, $b) => strcasecmp($a[1]['name'], $b[1]['name']));
$mine = $me ? array_filter($all, fn($p) => (int)$p['owner'] === (int)$me['id']) : [];
$pending = [];
if ($canManage) foreach ($all as $p) foreach ($p['versions'] as $v) if ($v['status'] === 'pending') $pending[] = [$p, $v];
$permChips = fn(array $perms) => implode('', array_map(fn($p) => '<span class="mk-perm" title="' . e(MK_PERMS[$p][1] ?? $p) . '">' . (MK_PERMS[$p][0] ?? '?') . ' ' . e(MK_PERMS[$p][1] ?? $p) . '</span>', $perms)) ?: '<span class="mk-perm ok">✔ keine besonderen Rechte</span>';
$fmtSize = fn(int $b) => $b > 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, round($b / 1024)) . ' KB';
$ST = ['pending' => '⏳ wartet auf Prüfung', 'approved' => '✅ freigegeben', 'rejected' => '⛔ abgelehnt'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PLUGIN-MARKTPLATZ · GHOST NODE</title>
<?php echo emoji_favicon('🧩'); ?>
<link rel="stylesheet" href="assets/css/hud.css">
<style>
.mk-wrap{max-width:1100px}
.mk-head h1{margin:6px 0}
.mk-eyebrow{color:var(--accent-cyan);font-size:.7rem;letter-spacing:3px;font-weight:700}
.mk-bar{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0}.mk-bar input{flex:1 1 240px}.mk-bar select{flex:0 1 200px}
.mk-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:12px}
.mk-card{background:var(--panel-bg);border:1px solid var(--border-color);padding:16px;display:flex;flex-direction:column;gap:8px}
.mk-card:hover{border-color:var(--accent-cyan)}
.mk-card header{display:flex;gap:12px;align-items:center}.mk-card .ic{font-size:2rem}
.mk-card h3{margin:0;font-size:1.05rem}.mk-card small{color:var(--text-muted)}
.mk-card p{margin:0;color:var(--text-muted);font-size:.86rem;line-height:1.5}
.mk-perms{display:flex;flex-wrap:wrap;gap:5px}
.mk-perm{font-size:.7rem;padding:2px 7px;border:1px solid var(--accent-gold);color:var(--accent-gold)}.mk-perm.ok{border-color:#4ade80;color:#4ade80}
.mk-meta{display:flex;gap:10px;flex-wrap:wrap;font-size:.74rem;color:var(--text-muted)}
.mk-sec{margin:34px 0 10px}.mk-sec h2{font-size:1rem;letter-spacing:2px;color:var(--accent-cyan);border-bottom:1px solid var(--border-color);padding-bottom:6px}
.mk-form{background:var(--panel-bg);border:1px solid var(--border-color);padding:16px;display:grid;gap:10px;max-width:640px}
.mk-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.mk-tbl{width:100%;border-collapse:collapse;font-size:.85rem}.mk-tbl td,.mk-tbl th{border-bottom:1px solid var(--border-color);padding:8px;text-align:left;vertical-align:top}
.mk-note{color:var(--text-muted);font-size:.82rem}
.mk-toast{position:fixed;bottom:18px;left:50%;transform:translateX(-50%);background:var(--panel-bg);border:1px solid var(--accent-cyan);padding:9px 14px;z-index:9}
.mk-card[hidden]{display:none}
</style>
</head>
<body>
<?php auth_corner(); ?>
<div class="wrapper mk-wrap">
    <div class="nav-bar"><a href="dashboard.php" class="nav-back">&larr; ZUM DASHBOARD</a><div class="nav-tabs"><a href="nexus.php" class="nav-tab">🥽 VRC Nexus</a><a href="themes.php" class="nav-tab">🎨 Themes</a><a href="dev.php" class="nav-tab">🛠 Entwickler</a></div></div>
    <header class="mk-head">
        <div class="mk-eyebrow">VRC NEXUS // PLUGINS</div>
        <h1>🧩 Plugin-Marktplatz</h1>
        <p class="muted">Erweiterungen für die Windows-App von VRC Nexus. Jedes Plugin wird vor der Veröffentlichung geprüft, läuft in der App abgeschottet und bekommt nur die Rechte, die du bei der Installation bestätigst. Installieren: in der App unter <b>Plugins → Marktplatz</b>.</p>
    </header>

    <div class="mk-bar"><input type="search" id="mkQ" placeholder="Plugins suchen …" aria-label="Suchen"><select id="mkCat" aria-label="Kategorie"><option value="">Alle Kategorien</option><?php foreach (MK_CATS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="mk-grid" id="mkGrid">
    <?php if (!$public): ?><p class="muted">Noch keine Plugins veröffentlicht.<?= $canSubmit ? ' Reich unten das erste ein!' : '' ?></p><?php endif; ?>
    <?php foreach ($public as [$p, $v]): ?>
        <article class="mk-card" data-cat="<?= e($p['category'] ?? 'other') ?>" data-q="<?= e(mb_strtolower($v['name'] . ' ' . $v['description'] . ' ' . $v['author'] . ' ' . $p['id'])) ?>">
            <header><span class="ic"><?= e($v['icon']) ?></span><div><h3><?= e($v['name']) ?></h3><small><?= e($v['version']) ?><?= $v['author'] !== '' ? ' · von ' . e($v['author']) : '' ?> · <?= e(MK_CATS[$p['category'] ?? 'other'] ?? '') ?></small></div></header>
            <p><?= e($v['description']) ?></p>
            <div class="mk-perms"><?= $permChips($v['permissions']) ?></div>
            <div class="mk-meta"><span>⬇ <?= (int)($p['downloads'] ?? 0) ?></span><span>📦 <?= $fmtSize((int)$v['size']) ?></span><span>🗓 <?= date('d.m.Y', (int)$v['uploaded']) ?></span><?php if ($v['nexus']): ?><span>Nexus <?= e($v['nexus']) ?></span><?php endif; ?><?php if ($v['homepage']): ?><a href="<?= e($v['homepage']) ?>" target="_blank" rel="noopener nofollow">Website ↗</a><?php endif; ?></div>
            <?php if (($v['changelog'] ?? '') !== ''): ?><details class="mk-note"><summary>Neu in <?= e($v['version']) ?></summary><?= nl2br(e($v['changelog'])) ?></details><?php endif; ?>
        </article>
    <?php endforeach; ?>
    </div>

    <?php if ($canSubmit): ?>
    <section class="mk-sec"><h2>⬆ Plugin einreichen</h2>
        <form class="mk-form" id="mkSubmit">
            <p class="mk-note">Eine <code>.nxp</code>-Datei mit <code>plugin.json</code> (Aufbau: siehe <a href="https://github.com/ghostnode-labs/vrc_nexus/blob/main/docs/PLUGINS.md" target="_blank" rel="noopener">Plugin-Doku</a>). Gleiche ID + höhere Version = Update. <?= $canManage ? 'Als Prüfer wird deine Einreichung sofort veröffentlicht.' : 'Nach der Prüfung erscheint es im Katalog.' ?></p>
            <input type="file" name="file" accept=".nxp,.zip" required>
            <div class="mk-row"><label class="small">Kategorie</label><select name="category"><?php foreach (MK_CATS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
            <textarea name="changelog" rows="3" maxlength="1000" placeholder="Was ist neu? (optional)"></textarea>
            <button class="btn btn-gold">Einreichen</button>
        </form>
    </section>
    <?php endif; ?>

    <?php if ($mine): ?>
    <section class="mk-sec"><h2>👤 Meine Plugins</h2>
        <table class="mk-tbl"><tr><th>Plugin</th><th>Version</th><th>Status</th><th></th></tr>
        <?php foreach ($mine as $p) foreach (array_reverse($p['versions']) as $v): ?>
            <tr><td><?= e($v['icon'] . ' ' . $v['name']) ?></td><td><?= e($v['version']) ?></td><td><?= e($ST[$v['status']] ?? $v['status']) ?><?= ($v['note'] ?? '') !== '' ? '<br><small class="muted">' . e($v['note']) . '</small>' : '' ?></td>
                <td><button class="btn btn-sm btn-danger" data-del="<?= e($p['id']) ?>" data-v="<?= e($v['version']) ?>">Löschen</button></td></tr>
        <?php endforeach; ?>
        </table>
    </section>
    <?php endif; ?>

    <?php if ($canManage): ?>
    <section class="mk-sec"><h2>🛡 Prüfung (<?= count($pending) ?>)</h2>
        <p class="mk-note">Vor der Freigabe: Paket herunterladen, Code lesen (besonders bei „Internet-Zugriff“ und „VRChat-Gruppen“), Rechte mit der Beschreibung vergleichen. Öffentlicher Signaturschlüssel des Katalogs: <code><?= e(mk_public_key()) ?></code></p>
        <?php if (!$pending): ?><p class="muted">Nichts zu prüfen.</p><?php endif; ?>
        <table class="mk-tbl">
        <?php foreach ($pending as [$p, $v]): ?>
            <tr><td><b><?= e($v['icon'] . ' ' . $v['name']) ?></b> <?= e($v['version']) ?><br><small class="muted"><?= e($p['id']) ?> · <?= e($v['author']) ?> · SHA-256 <?= e(substr($v['sha256'], 0, 16)) ?>…</small><br><?= e($v['description']) ?></td>
                <td><div class="mk-perms"><?= $permChips($v['permissions']) ?></div><?= $v['groups'] ? '<small class="muted">Gruppen: ' . e(implode(', ', $v['groups'])) . '</small>' : '' ?></td>
                <td><a class="btn btn-sm" href="market.php?dl=<?= e(rawurlencode($p['id'])) ?>&amp;v=<?= e(rawurlencode($v['version'])) ?>">⬇ Paket</a>
                    <button class="btn btn-sm btn-gold" data-rev="approved" data-id="<?= e($p['id']) ?>" data-v="<?= e($v['version']) ?>">✅ Freigeben</button>
                    <button class="btn btn-sm btn-danger" data-rev="rejected" data-id="<?= e($p['id']) ?>" data-v="<?= e($v['version']) ?>">⛔ Ablehnen</button></td></tr>
        <?php endforeach; ?>
        </table>
    </section>
    <?php endif; ?>
    <?php if (!$me): ?><p class="mk-note" style="margin-top:30px">Eigene Plugins einreichen? <a href="admin.php">Anmelden</a> – das Recht „Plugin-Marktplatz: einreichen“ vergibt ein Admin.</p><?php endif; ?>
</div>
<script>
(() => {
    const toast = m => { const t = document.createElement('div'); t.className = 'mk-toast'; t.textContent = m; document.body.appendChild(t); setTimeout(() => t.remove(), 3500); };
    const post = (act, fd) => fetch('market.php?api=' + act, { method: 'POST', body: fd, credentials: 'same-origin' }).then(r => r.json()).then(d => { if (d.error) throw new Error(d.error); return d; });
    const q = document.getElementById('mkQ'), c = document.getElementById('mkCat');
    const filter = () => { const s = q.value.trim().toLowerCase(), k = c.value; document.querySelectorAll('.mk-card').forEach(el => { el.hidden = !((!k || el.dataset.cat === k) && (!s || el.dataset.q.includes(s))); }); };
    q.addEventListener('input', filter); c.addEventListener('change', filter);
    const f = document.getElementById('mkSubmit');
    if (f) f.addEventListener('submit', e => { e.preventDefault(); const b = f.querySelector('button'); b.disabled = true; post('submit', new FormData(f)).then(d => { toast(d.msg); setTimeout(() => location.reload(), 1200); }).catch(er => toast('⚠ ' + er.message)).finally(() => { b.disabled = false; }); });
    document.addEventListener('click', e => {
        const r = e.target.closest('[data-rev]'), d = e.target.closest('[data-del]');
        if (r) { const fd = new FormData(); fd.append('id', r.dataset.id); fd.append('version', r.dataset.v); fd.append('decision', r.dataset.rev);
            if (r.dataset.rev === 'rejected') { const n = prompt('Grund für die Ablehnung (sieht der Autor):', ''); if (n === null) return; fd.append('note', n); }
            post('review', fd).then(x => { toast(x.msg); setTimeout(() => location.reload(), 800); }).catch(er => toast('⚠ ' + er.message)); }
        if (d) { if (!confirm('Version ' + d.dataset.v + ' von ' + d.dataset.del + ' löschen?')) return; const fd = new FormData(); fd.append('id', d.dataset.del); fd.append('version', d.dataset.v);
            post('delete', fd).then(x => { toast(x.msg); setTimeout(() => location.reload(), 800); }).catch(er => toast('⚠ ' + er.message)); }
    });
})();
</script>
</body>
</html>
