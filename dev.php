<?php
/**
 * 🛠 Entwickler-Portal (8.1) – alles für Entwickler*innen an einem Ort:
 * API-Schlüssel, Web-API v1/v2, Client-API (WebSocket), Webhook-Signatur, Plugin-SDK, Themes, Einreichungen, Status-JSON.
 * Öffentlich lesbar; eigene Schlüssel, Plugins und Themes nur angemeldet.
 * <title>Entwickler</title>
 */
require_once __DIR__ . '/inc/core/common.php';
require_once __DIR__ . '/inc/core/pages.php';
require_once __DIR__ . '/inc/modules/webapi.php';
require_once __DIR__ . '/inc/modules/market.php';
require_once __DIR__ . '/inc/modules/themes_market.php';
require_once __DIR__ . '/inc/modules/oauth.php';
auth_require_setup();
$u = current_user();
$keys = []; $plugins = []; $themes = [];
if ($u) {
    try { $keys = wa_keys((int)$u['id']); } catch (Throwable $e) { $keys = []; }
    foreach (mk_load() as $p) if ((int)($p['owner'] ?? 0) === (int)$u['id']) $plugins[] = $p;
    foreach (tm_load() as $t) if ((int)($t['owner'] ?? 0) === (int)$u['id']) $themes[] = $t;
}
$apps = []; $newApp = null; $oaMsg = ''; $oaErr = '';
if ($u && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    guard_post();
    try {
        if (($_POST['action'] ?? '') === 'oa_create') $newApp = oa_app_create((int)$u['id'], (string)($_POST['name'] ?? ''), (string)($_POST['redirects'] ?? ''), !empty($_POST['confidential']), (string)($_POST['homepage'] ?? ''));
        if (($_POST['action'] ?? '') === 'oa_delete') { oa_app_delete((int)$u['id'], (int)($_POST['id'] ?? 0)); $oaMsg = 'App gelöscht – alle ihre Zugriffe sind widerrufen.'; }
    } catch (InvalidArgumentException $e) { $oaErr = $e->getMessage(); }
}
if ($u) { try { $apps = oa_apps((int)$u['id']); } catch (Throwable $e) { $apps = []; } }
$pub = count(array_filter(mk_load(), fn($p) => mk_latest($p) !== null));
$pubT = count(array_filter(tm_load(), fn($t) => ($t['status'] ?? '') === 'approved'));
$ST = ['pending' => '⏳ wartet', 'approved' => '✅ frei', 'rejected' => '⛔ abgelehnt'];
$REPO = 'https://github.com/ghostnode-labs/vrc_nexus/blob/main';
$card = fn(string $ic, string $title, string $text, array $links) => '<article class="dv-card"><h3>' . $ic . ' ' . e($title) . '</h3><p>' . $text . '</p><div class="dv-links">'
    . implode('', array_map(fn($l) => '<a class="btn btn-sm' . (!empty($l[2]) ? ' btn-gold' : '') . '" href="' . e($l[1]) . '"' . (strpos($l[1], 'http') === 0 ? ' target="_blank" rel="noopener"' : '') . '>' . e($l[0]) . '</a>', $links)) . '</div></article>';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ENTWICKLER · GHOST NODE</title>
<?php echo emoji_favicon('🛠'); ?>
<link rel="stylesheet" href="assets/css/hud.css">
<style>
.dv-wrap{max-width:1100px}
.dv-eyebrow{color:var(--accent-cyan);font-size:.7rem;letter-spacing:3px;font-weight:700}
.dv-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px;margin-top:14px}
.dv-card{background:var(--panel-bg);border:1px solid var(--border-color);padding:14px 16px;display:flex;flex-direction:column;gap:8px}
.dv-card h3{margin:0;font-size:1rem}.dv-card p{margin:0;color:var(--text-muted);font-size:.86rem;line-height:1.5;flex:1}
.dv-links{display:flex;gap:6px;flex-wrap:wrap}
.dv-sec{margin:30px 0 10px}.dv-sec h2{font-size:1rem;letter-spacing:2px;color:var(--accent-cyan);border-bottom:1px solid var(--border-color);padding-bottom:6px}
.dv-tbl{width:100%;border-collapse:collapse;font-size:.85rem}.dv-tbl td,.dv-tbl th{border-bottom:1px solid var(--border-color);padding:7px;text-align:left}
.dv-stats{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}.dv-stats span{border:1px solid var(--border-color);padding:6px 10px;font-size:.82rem}
pre.dv{background:rgba(0,0,0,.35);border:1px solid var(--border-color);padding:10px;overflow-x:auto;font-size:.8rem}
</style>
</head>
<body>
<?php auth_corner(); ?>
<div class="wrapper dv-wrap">
    <div class="nav-bar"><a href="dashboard.php" class="nav-back">&larr; ZUM DASHBOARD</a><div class="nav-tabs"><a href="api.php" class="nav-tab">📡 API</a><a href="market.php" class="nav-tab">🧩 Plugins</a><a href="themes.php" class="nav-tab">🎨 Themes</a><a href="status.php" class="nav-tab">🩺 Status</a></div></div>
    <header><div class="dv-eyebrow">GHOST NODE // DEVELOPERS</div><h1>🛠 Entwickler-Portal</h1>
        <p class="muted">Alles, um mit GhostNode und VRC Nexus zu bauen: Schnittstellen, Plugins, Themes und Webhooks. Regeln für alle: keine 🔞-Inhalte über die API, keine Geheimnisse in Plugins, kein Scraping fremder Konten.</p>
        <div class="dv-stats"><span>🧩 <?= $pub ?> Plugins im Marktplatz</span><span>🎨 <?= $pubT ?> Themes</span><span>📡 Web-API v1 + v2</span><span>🔏 signierte Webhooks</span></div>
    </header>

    <section class="dv-sec"><h2>📚 Bausteine</h2>
    <div class="dv-grid">
        <?= $card('📡', 'Web-API (PHP)', 'Versionen, News, Marktplatz, eigene Seiten, Kalender, Community – JSON, CORS offen. <b>v2</b> mit Seiten (<code>limit</code>/<code>cursor</code>) und einheitlicher Hülle.', [['Doku', 'api.php#web', true], ['v2', 'api.php#v2'], ['Fehler & Limits', 'api.php#errors']]) ?>
        <?= $card('🖥', 'Client-API (WebSocket)', 'Live-Daten aus der VRC-Nexus-App am PC: Ereignisse abonnieren, Aktionen auslösen – nur lokal, mit App-Schlüssel und Rechten je Verbindung.', [['Doku', 'api.php#client', true], ['Beispiele', 'api.php#client-ex']]) ?>
        <?= $card('🧩', 'Plugin-SDK', 'Plugins als <code>.nxp</code>: eigene Oberfläche aus Bausteinen, Rechte-Modell, abgeschotteter Prozess, verschlüsselter Speicher. Beispiel-Plugin liegt der App bei.', [['PLUGINS.md', $REPO . '/docs/PLUGINS.md', true], ['Marktplatz', 'market.php'], ['Einreichen', 'market.php#mkSubmit']]) ?>
        <?= $card('🎨', 'Themes', 'Farbschemata für App und Handy – nur 13 Hex-Farben, Kontrast-Prüfung. In der App bauen, als JSON teilen, hier einreichen.', [['Marktplatz', 'themes.php', true], ['Einreichen', 'themes.php#tmSubmit']]) ?>
        <?= $card('🔏', 'Webhooks', 'Neue Versionen, Termine und Störungen an deine Adresse – jede Nachricht mit <code>X-GhostNode-Signature</code> (HMAC-SHA256) und Zeitstempel.', [['Signatur prüfen', 'api.php#hooksig', true], ['Meine Webhooks', 'me.php?tab=hooks']]) ?>
        <?= $card('🔗', 'Mit GhostNode anmelden', 'OAuth 2.0 (Autorisierungscode + PKCE) für eigene Apps, Bots und Webseiten: Leute melden sich mit ihrem GhostNode-Konto an und geben nur die Rechte frei, die du brauchst.', [['Doku', 'api.php#oauth', true], ['Meine Apps', '#oauth']]) ?>
        <?= $card('🌍', 'Übersetzen helfen', 'Die Webseite auf Englisch, Französisch und Japanisch: fehlende Texte übersetzen, bestehende verbessern. Das Team prüft, freigegebene Texte gelten sofort.', [['Mitmachen', 'uebersetzen.php', true], ['Nur fehlende', 'uebersetzen.php?f=missing']]) ?>
        <?= $card('🩺', 'Status-JSON', 'Zustand aller Dienste maschinenlesbar – für eigene Monitore, Bots oder Statusleisten.', [['status.php?api=1', 'status.php?api=1', true], ['Status-Seite', 'status.php']]) ?>
    </div></section>

    <section class="dv-sec"><h2>⚡ Schnellstart</h2>
<pre class="dv"># Neueste VRC-Nexus-Version (ohne Schlüssel)
curl "<?= e(mk_base_url()) ?>/api.php/v2/versions/latest"

# Eigene Seiten (mit Schlüssel „read“)
curl -H "Authorization: Bearer gnk_…" "<?= e(mk_base_url()) ?>/api.php/v2/pages?limit=10"

# Status aller Dienste
curl "<?= e(mk_base_url()) ?>/status.php?api=1"</pre>
    </section>

    <?php if ($u): ?>
    <section class="dv-sec"><h2>👤 Meins</h2>
        <div class="dv-grid">
            <article class="dv-card"><h3>🔑 API-Schlüssel (<?= count($keys) ?>)</h3>
                <?php if ($keys): ?><table class="dv-tbl"><?php foreach (array_slice($keys, 0, 6) as $k): ?><tr><td><?= e($k['name']) ?></td><td><code><?= e($k['prefix']) ?>…</code></td><td class="muted"><?= (int)($k['uses'] ?? 0) ?> Aufrufe</td></tr><?php endforeach; ?></table><?php else: ?><p>Noch keine Schlüssel.</p><?php endif; ?>
                <div class="dv-links"><a class="btn btn-sm btn-gold" href="api.php#keys">Verwalten</a></div></article>
            <article class="dv-card"><h3>🧩 Meine Plugins (<?= count($plugins) ?>)</h3>
                <?php if ($plugins): ?><table class="dv-tbl"><?php foreach ($plugins as $p): $v = end($p['versions']); ?><tr><td><?= e(($v['icon'] ?? '🧩') . ' ' . ($v['name'] ?? $p['id'])) ?></td><td><?= e($v['version'] ?? '') ?></td><td><?= e($ST[$v['status'] ?? ''] ?? '') ?></td><td class="muted">⬇ <?= (int)($p['downloads'] ?? 0) ?></td></tr><?php endforeach; ?></table><?php else: ?><p>Noch keine eingereicht.</p><?php endif; ?>
                <div class="dv-links"><a class="btn btn-sm" href="market.php">Marktplatz</a></div></article>
            <article class="dv-card"><h3>🎨 Meine Themes (<?= count($themes) ?>)</h3>
                <?php if ($themes): ?><table class="dv-tbl"><?php foreach ($themes as $t): ?><tr><td><?= e($t['name']) ?></td><td><?= e($ST[$t['status']] ?? $t['status']) ?></td><td class="muted">⬇ <?= (int)($t['installs'] ?? 0) ?></td></tr><?php endforeach; ?></table><?php else: ?><p>Noch keine eingereicht.</p><?php endif; ?>
                <div class="dv-links"><a class="btn btn-sm" href="themes.php">Themes</a></div></article>
        </div>
        <article class="dv-card" id="oauth" style="margin-top:12px"><h3>🔗 Meine OAuth-Apps (<?= count($apps) ?>)</h3>
            <?php if ($oaErr): ?><p style="color:#ff5d73">⚠ <?= e($oaErr) ?></p><?php endif; ?><?php if ($oaMsg): ?><p>✅ <?= e($oaMsg) ?></p><?php endif; ?>
            <?php if ($newApp): ?><div style="border:1px solid var(--accent-gold,#f3c262);padding:10px"><b>✅ App angelegt.</b> Client-ID: <code><?= e($newApp[0]['client_id']) ?></code>
                <?php if ($newApp[1] !== ''): ?><br>Geheimnis (nur jetzt sichtbar – sicher aufbewahren, nie in Apps auf Geräten einbauen): <code><?= e($newApp[1]) ?></code><?php else: ?><br>Öffentliche App: kein Geheimnis, dafür PKCE (S256) Pflicht.<?php endif; ?></div><?php endif; ?>
            <?php if ($apps): ?><table class="dv-tbl"><tr><th>Name</th><th>Client-ID</th><th>Art</th><th>Weiterleitungen</th><th></th></tr><?php foreach ($apps as $a): ?>
                <tr><td data-no-i18n><?= e($a['name']) ?></td><td><code><?= e($a['client_id']) ?></code></td><td><?= $a['public'] ? 'öffentlich (PKCE)' : 'vertraulich' ?></td><td class="muted" data-no-i18n><?= e(implode(' ', $a['redirects'])) ?></td>
                <td><form method="post" onsubmit="return confirm('App löschen? Alle Anmeldungen damit werden widerrufen.')"><input type="hidden" name="action" value="oa_delete"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-sm btn-danger">Löschen</button></form></td></tr>
            <?php endforeach; ?></table><?php endif; ?>
            <form method="post" style="display:grid;gap:6px;margin-top:8px"><input type="hidden" name="action" value="oa_create">
                <input name="name" maxlength="40" placeholder="Name der App" required>
                <textarea name="redirects" rows="2" placeholder="Weiterleitungs-Adressen (https://…, eine pro Zeile, höchstens 5)" required></textarea>
                <input name="homepage" placeholder="Webseite der App (optional, https://…)">
                <label><input type="checkbox" name="confidential" value="1" checked> Vertraulich (Server mit Geheimnis) – aus für Handy-/Desktop-Apps und Single-Page-Apps</label>
                <button class="btn btn-gold">➕ App anlegen</button></form>
        </article>
    </section>
    <?php else: ?><p class="muted" style="margin-top:24px"><a href="admin.php?next=dev.php">Anmelden</a>, um Schlüssel, eigene Plugins und Themes hier zu sehen.</p><?php endif; ?>
</div>
</body>
</html>
