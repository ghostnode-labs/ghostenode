<?php
declare(strict_types=1);
/**
 * 6.6 Download-Center: die neueste VRC-Nexus-Version je Gerät mit Größe, SHA-256-Prüfsumme (von GitHub) und Installationshilfe.
 * <title>Downloads</title>
 */
require_once __DIR__ . '/inc/core/common.php';
require_once __DIR__ . '/inc/core/pages.php';
auth_require_setup();
require_once __DIR__ . '/inc/modules/changelog.php';

$rel = array_values(array_filter(cl_fetch(), fn($r) => !$r['pre']));
$latest = $rel[0] ?? null;
$KINDS = [
    'setup'    => ['🪟', 'Windows – Installer', fn($n) => str_ends_with($n, '-Setup.exe'), 'Herunterladen, doppelklicken, fertig. Windows SmartScreen kann einmal fragen („Weitere Informationen → Trotzdem ausführen“) – die App ist nicht mit einem gekauften Zertifikat signiert. Updates kommen danach automatisch.'],
    'portable' => ['🧳', 'Windows – portable', fn($n) => str_ends_with($n, '-portable.exe'), 'Ohne Installation, z. B. vom USB-Stick. Daten liegen neben der Datei.'],
    'android'  => ['📱', 'Android', fn($n) => str_ends_with($n, '-android.apk'), 'APK auf dem Handy öffnen und „Aus dieser Quelle installieren“ erlauben. Danach aktualisiert sich die App selbst. Nicht die Wear-OS-Datei nehmen!'],
    'watch'    => ['⌚', 'Wear OS (Nexus Watch)', fn($n) => str_contains($n, 'Watch') && str_ends_with($n, '.apk'), 'Für die Uhr: per ADB installieren oder über die Handy-App (Werkzeuge → Nexus Watch). Braucht Wear OS 3 oder neuer.'],
    'pet'      => ['🐾', 'Nexus Pet (Android)', fn($n) => str_starts_with($n, 'Nexus-Pet') && str_ends_with($n, '.apk'), 'Eigene App: ein virtuelles Haustier – zieh deinen Geistling auf (füttern, spielen, putzen, Entwicklung in 13 Formen, Widget). Unabhängig von VRC Nexus; funktioniert ohne Konto und ohne Internet.'],
    'twitch'   => ['🟣', 'Twitch-Panel (Erweiterung)', fn($n) => str_contains($n, 'Twitch-Extension') && str_ends_with($n, '.zip'), 'Für Streamer: zeigt Zuschauern unter dem Stream Welt, Puls, Song, Ziel, Umfrage (mit Abstimmen), Befehle und Belohnungen. In der Twitch-Entwicklerkonsole hochladen – Anleitung im Repo (apps/twitch-extension).'],
    'deck'     => ['🎛', 'Stream-Deck-Plugin', fn($n) => str_ends_with($n, '.streamDeckPlugin'), 'Doppelklicken – die Stream-Deck-App installiert das Plugin. Liegt auch der Windows-App bei (Werkzeuge → Stream Deck).'],
];
$size = fn(int $b) => $b > 1048576 ? number_format($b / 1048576, 1, ',', '.') . ' MB' : max(1, (int)round($b / 1024)) . ' KB';
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Downloads · VRC Nexus</title>
<meta name="description" content="VRC Nexus herunterladen – Windows, Android, Wear OS, Stream Deck und Nexus Pet, mit Prüfsummen.">
<link rel="stylesheet" href="assets/css/hud.css">
<style>
body.dl{padding:0;display:block}.dl-wrap{box-sizing:border-box;max-width:980px;margin:0 auto;padding:20px 20px 60px}
.dl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
.dl-card{border:1px solid var(--border-color);background:var(--panel-bg);padding:16px;clip-path:var(--cut-md);display:flex;flex-direction:column;gap:8px}
.dl-card h3{margin:0;color:var(--accent-gold)}.dl-card p{margin:0;color:var(--text-muted);font-size:.85rem;line-height:1.45}
.sha{font-family:monospace;font-size:.72rem;word-break:break-all;background:rgba(255,255,255,.05);padding:6px;cursor:copy}
.muted{color:var(--text-muted);font-size:.85rem}
details{margin-top:18px}summary{cursor:pointer;color:var(--accent-cyan)}
pre{background:rgba(255,255,255,.05);padding:10px;overflow:auto;font-size:.8rem}
</style></head><body class="dl"><div class="dl-wrap">
<h1 style="margin:0">⬇ Downloads</h1>
<?php if (!$latest): ?><p class="muted">Gerade keine Versionen erreichbar (GitHub). Bitte später erneut versuchen.</p><?php else: ?>
<p class="muted">Neueste Version: <b>VRC Nexus <?php echo e($latest['v']); ?></b> vom <?php echo e(date('d.m.Y', strtotime($latest['date']) ?: time())); ?> · <a href="changelog.php">Was ist neu?</a> · <a href="<?php echo e($latest['url']); ?>" target="_blank" rel="noopener">auf GitHub</a></p>
<div class="dl-grid">
<?php foreach ($KINDS as $k => [$ic, $label, $match, $help]):
    $a = null; foreach ($latest['assets'] as $x) if ($match($x['name']) && ($k !== 'android' || (!str_contains($x['name'], 'Watch') && !str_starts_with($x['name'], 'Nexus-Pet')))) { $a = $x; break; }
    if (!$a) continue; ?>
<div class="dl-card"><h3><?php echo $ic . ' ' . e($label); ?></h3><p><?php echo e($help); ?></p>
<a class="btn" href="<?php echo e($a['url']); ?>" rel="noopener">⬇ <?php echo e($a['name']); ?> · <?php echo $size((int)$a['size']); ?></a>
<?php if (!empty($a['sha256'])): ?><div class="sha" title="Klicken zum Kopieren" onclick="navigator.clipboard&&navigator.clipboard.writeText(this.dataset.h)" data-h="<?php echo e($a['sha256']); ?>">SHA-256: <?php echo e($a['sha256']); ?></div>
<?php else: ?><p class="muted">Prüfsumme: auf der GitHub-Seite der Version.</p><?php endif; ?></div>
<?php endforeach; ?>
</div>
<details><summary>🔐 Prüfsumme selbst kontrollieren</summary>
<p class="muted">So siehst du, dass die Datei unverändert ist – das Ergebnis muss genau der Zeichenkette oben entsprechen.</p>
<pre>Windows (PowerShell):  Get-FileHash .\VRC-Nexus-<?php echo e($latest['v']); ?>-Setup.exe -Algorithm SHA256
macOS / Linux:         shasum -a 256 VRC-Nexus-<?php echo e($latest['v']); ?>-android.apk</pre></details>
<details><summary>🗂 Ältere Versionen</summary><p class="muted"><?php foreach (array_slice($rel, 1, 12) as $r) echo '<a href="' . e($r['url']) . '" target="_blank" rel="noopener">' . e($r['v']) . '</a> · '; ?> <a href="changelog.php">alle im Changelog</a></p></details>
<?php endif; ?>
</div></body></html>
