<?php
/**
 * 🎨 Themes-Marktplatz für VRC Nexus (8.0.5) – Katalog (öffentlich), Einreichen (angemeldet), Prüfen (market.manage).
 * Die App lädt den signierten Katalog über themes.php?api=index und meldet Übernahmen über themes.php?api=got&id=…
 */
require_once __DIR__ . '/inc/modules/themes_market.php';
require_once __DIR__ . '/inc/core/format.php';
auth_require_setup();

if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $out = function ($d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
    try {
        $act = (string)$_GET['api'];
        if ($act === 'index') { header('Access-Control-Allow-Origin: *'); $out(tm_index()); }
        if ($act === 'got') { tm_count(preg_replace('/[^a-z0-9-]/', '', (string)($_GET['id'] ?? ''))); $out(['ok' => true]); }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new InvalidArgumentException('Nur per POST.');
        if (!is_logged_in()) throw new InvalidArgumentException('Bitte anmelden.');
        if (!same_origin_request()) throw new InvalidArgumentException('Anfrage abgelehnt (fremde Herkunft).');
        $me = current_user();
        switch ($act) {
            case 'submit':
                $json = (string)($_POST['theme'] ?? '');
                if ($json === '' && !empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name']) && filesize($_FILES['file']['tmp_name']) < 20000) $json = (string)file_get_contents($_FILES['file']['tmp_name']);
                if (strlen($json) > 20000) throw new InvalidArgumentException('Theme zu groß.');
                $r = tm_submit($me, $json);
                $out(['msg' => '🎨 ' . $r['theme']['name'] . ($r['status'] === 'approved' ? ' ist veröffentlicht.' : ' ist eingereicht und wartet auf Prüfung.')]);
            case 'review': tm_review((string)($_POST['id'] ?? ''), (string)($_POST['decision'] ?? ''), (string)($_POST['note'] ?? '')); $out(['msg' => 'Gespeichert.']);
            case 'delete': tm_delete($me, (string)($_POST['id'] ?? '')); $out(['msg' => 'Gelöscht.']);
        }
        throw new InvalidArgumentException('Unbekannte Aktion.');
    } catch (Throwable $e) {
        if (!($e instanceof InvalidArgumentException)) error_log('Themes: ' . $e->getMessage());
        $out(['error' => $e instanceof InvalidArgumentException ? $e->getMessage() : 'Interner Fehler.'], $e instanceof InvalidArgumentException ? 400 : 500);
    }
}

$me = current_user();
$all = tm_load();
$canManage = $me && can('market.manage');
$public = array_values(array_filter($all, fn($t) => ($t['status'] ?? '') === 'approved'));
usort($public, fn($a, $b) => ($b['installs'] ?? 0) <=> ($a['installs'] ?? 0) ?: strcasecmp($a['name'], $b['name']));
$mine = $me ? array_filter($all, fn($t) => (int)$t['owner'] === (int)$me['id']) : [];
$pending = $canManage ? array_filter($all, fn($t) => ($t['status'] ?? '') === 'pending') : [];
$ST = ['pending' => '⏳ wartet auf Prüfung', 'approved' => '✅ freigegeben', 'rejected' => '⛔ abgelehnt'];
/** Vorschau-Karte nur aus geprüften Hex-Farben */
$preview = function (array $t): string {
    $c = $t['colors'] + ['panel2' => $t['colors']['panel'], 'gold' => $t['colors']['accent'], 'line' => $t['colors']['muted']];
    return '<div class="tm-prev" style="background:' . $c['bg'] . ';color:' . $c['text'] . '">'
        . '<div class="tm-side" style="background:' . $c['panel'] . ';border-color:' . $c['line'] . '"><i style="background:' . $c['accent'] . '"></i><i style="background:' . $c['panel2'] . '"></i><i style="background:' . $c['panel2'] . '"></i></div>'
        . '<div class="tm-main"><b style="color:' . $c['accent'] . '">VRC NEXUS</b><span style="color:' . $c['muted'] . '">Freunde · Welten</span>'
        . '<div class="tm-card" style="background:' . $c['panel'] . ';border-color:' . $c['line'] . '"><span style="color:' . $c['gold'] . '">★</span> Lofi Café</div></div></div>';
};
$example = json_encode(['format' => 1, 'id' => 'mein-theme', 'name' => 'Mein Theme', 'author' => '', 'scheme' => 'dark',
    'colors' => ['bg' => '#0a0710', 'panel' => '#161022', 'panel2' => '#241a38', 'accent' => '#ff7ad9', 'gold' => '#ffd166', 'green' => '#5eead4', 'purple' => '#c4b5fd', 'red' => '#ff5d73', 'text' => '#f3eaff', 'muted' => '#9a8fb3', 'strong' => '#ffffff', 'surface' => '#1a1228', 'line' => '#3b2d55']],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>THEMES · GHOST NODE</title>
<?php echo emoji_favicon('🎨'); ?>
<link rel="stylesheet" href="assets/css/hud.css">
<style>
.tm-wrap{max-width:1100px}
.tm-eyebrow{color:var(--accent-cyan);font-size:.7rem;letter-spacing:3px;font-weight:700}
.tm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin-top:14px}
.tm-item{background:var(--panel-bg);border:1px solid var(--border-color);padding:12px;display:flex;flex-direction:column;gap:8px}
.tm-item h3{margin:0;font-size:1rem}.tm-item small{color:var(--text-muted)}
.tm-prev{display:flex;height:110px;border-radius:6px;overflow:hidden;font-size:.75rem}
.tm-side{width:28%;border-right:1px solid;display:flex;flex-direction:column;gap:6px;padding:8px}.tm-side i{display:block;height:10px;border-radius:3px}
.tm-main{flex:1;padding:8px;display:flex;flex-direction:column;gap:4px}.tm-main b{letter-spacing:2px}
.tm-card{border:1px solid;padding:6px;border-radius:4px;margin-top:auto}
.tm-sec{margin:34px 0 10px}.tm-sec h2{font-size:1rem;letter-spacing:2px;color:var(--accent-cyan);border-bottom:1px solid var(--border-color);padding-bottom:6px}
.tm-form{background:var(--panel-bg);border:1px solid var(--border-color);padding:16px;display:grid;gap:10px;max-width:720px}
.tm-form textarea{font-family:monospace;font-size:.8rem}
.tm-tbl{width:100%;border-collapse:collapse;font-size:.85rem}.tm-tbl td,.tm-tbl th{border-bottom:1px solid var(--border-color);padding:8px;text-align:left;vertical-align:top}
.tm-note{color:var(--text-muted);font-size:.82rem}
.tm-toast{position:fixed;bottom:18px;left:50%;transform:translateX(-50%);background:var(--panel-bg);border:1px solid var(--accent-cyan);padding:9px 14px;z-index:9}
</style>
</head>
<body>
<?php auth_corner(); ?>
<div class="wrapper tm-wrap">
    <div class="nav-bar"><a href="dashboard.php" class="nav-back">&larr; ZUM DASHBOARD</a><div class="nav-tabs"><a href="nexus.php" class="nav-tab">🥽 VRC Nexus</a><a href="market.php" class="nav-tab">🧩 Plugins</a></div></div>
    <header>
        <div class="tm-eyebrow">VRC NEXUS // THEMES</div>
        <h1>🎨 Themes-Marktplatz</h1>
        <p class="muted">Farbschemata für VRC Nexus (PC und Handy). Ein Theme besteht nur aus Farben – kein Code, kein CSS – und wird vor der Veröffentlichung geprüft. Übernehmen: in der App unter ⚙️ Einstellungen → Aussehen → 🎨 Themes.</p>
    </header>
    <div class="tm-grid">
    <?php if (!$public): ?><p class="muted">Noch keine Themes veröffentlicht.<?= $me ? ' Reich unten das erste ein!' : '' ?></p><?php endif; ?>
    <?php foreach ($public as $t): ?>
        <article class="tm-item"><?= $preview($t) ?><h3><?= e($t['name']) ?></h3><small><?= $t['scheme'] === 'light' ? '☀️ hell' : '🌙 dunkel' ?><?= $t['author'] !== '' ? ' · von ' . e($t['author']) : '' ?> · ⬇ <?= (int)($t['installs'] ?? 0) ?></small></article>
    <?php endforeach; ?>
    </div>

    <?php if ($me): ?>
    <section class="tm-sec"><h2>⬆ Theme einreichen</h2>
        <form class="tm-form" id="tmSubmit">
            <p class="tm-note">Als JSON (Export aus der App: Einstellungen → Aussehen → 🎨 Themes → „Teilen“) oder als <code>.json</code>-Datei. Nur <code>#rrggbb</code>-Farben; Text und Hintergrund brauchen genug Kontrast (mind. 4,5 : 1). Gleiche ID = Update deines Themes.</p>
            <textarea name="theme" rows="12" spellcheck="false"><?= e($example) ?></textarea>
            <input type="file" name="file" accept=".json,application/json">
            <button class="btn btn-gold">Einreichen</button>
        </form>
    </section>
    <?php else: ?><p class="tm-note" style="margin-top:30px">Eigene Themes einreichen? <a href="admin.php">Anmelden</a>.</p><?php endif; ?>

    <?php if ($mine): ?>
    <section class="tm-sec"><h2>👤 Meine Themes</h2>
        <table class="tm-tbl"><tr><th>Theme</th><th>Status</th><th></th></tr>
        <?php foreach ($mine as $t): ?>
            <tr><td><?= e($t['name']) ?> <small class="muted"><?= e($t['id']) ?></small></td><td><?= e($ST[$t['status']] ?? $t['status']) ?><?= ($t['note'] ?? '') !== '' ? '<br><small class="muted">' . e($t['note']) . '</small>' : '' ?></td>
                <td><button class="btn btn-sm btn-danger" data-del="<?= e($t['id']) ?>">Löschen</button></td></tr>
        <?php endforeach; ?>
        </table>
    </section>
    <?php endif; ?>

    <?php if ($canManage): ?>
    <section class="tm-sec"><h2>🛡 Prüfung (<?= count($pending) ?>)</h2>
        <?php if (!$pending): ?><p class="muted">Nichts zu prüfen.</p><?php endif; ?>
        <div class="tm-grid">
        <?php foreach ($pending as $t): ?>
            <article class="tm-item"><?= $preview($t) ?><h3><?= e($t['name']) ?></h3><small><?= e($t['id']) ?> · <?= e($t['author']) ?></small>
                <div><button class="btn btn-sm btn-gold" data-rev="approved" data-id="<?= e($t['id']) ?>">✅ Freigeben</button> <button class="btn btn-sm btn-danger" data-rev="rejected" data-id="<?= e($t['id']) ?>">⛔ Ablehnen</button></div></article>
        <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</div>
<script>
(() => {
    const toast = m => { const t = document.createElement('div'); t.className = 'tm-toast'; t.textContent = m; document.body.appendChild(t); setTimeout(() => t.remove(), 3500); };
    const post = (act, fd) => fetch('themes.php?api=' + act, { method: 'POST', body: fd, credentials: 'same-origin' }).then(r => r.json()).then(d => { if (d.error) throw new Error(d.error); return d; });
    const f = document.getElementById('tmSubmit');
    if (f) f.addEventListener('submit', e => { e.preventDefault(); const fd = new FormData(f); if (f.file.files.length) fd.set('theme', ''); post('submit', fd).then(d => { toast(d.msg); setTimeout(() => location.reload(), 1200); }).catch(er => toast('⚠ ' + er.message)); });
    document.addEventListener('click', e => {
        const r = e.target.closest('[data-rev]'), d = e.target.closest('[data-del]');
        if (r) { const fd = new FormData(); fd.append('id', r.dataset.id); fd.append('decision', r.dataset.rev);
            if (r.dataset.rev === 'rejected') { const n = prompt('Grund (sieht der Autor):', ''); if (n === null) return; fd.append('note', n); }
            post('review', fd).then(x => { toast(x.msg); setTimeout(() => location.reload(), 800); }).catch(er => toast('⚠ ' + er.message)); }
        if (d) { if (!confirm('Theme ' + d.dataset.del + ' löschen?')) return; const fd = new FormData(); fd.append('id', d.dataset.del);
            post('delete', fd).then(x => { toast(x.msg); setTimeout(() => location.reload(), 800); }).catch(er => toast('⚠ ' + er.message)); }
    });
})();
</script>
</body>
</html>
