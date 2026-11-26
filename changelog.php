<?php
declare(strict_types=1);
/**
 * VRC Nexus – Changelog als Unterseite der Webseite.
 * <title>Changelog</title>
 * Quelle: die öffentlichen GitHub-Releases (Texte schreibt die Release-Pipeline aus der README), 30 Minuten zwischengespeichert.
 * 🔞-Details stehen nie hier (dort steht „🔞 ***“) – die gibt es nur freigeschaltet in der App.
 */
require_once __DIR__ . '/inc/core/common.php';
require_once __DIR__ . '/inc/core/pages.php';
auth_require_setup();

require_once __DIR__ . '/inc/modules/changelog.php';

/** Release-Text → HTML (kleines, sicheres Markdown: Überschriften, Listen, fett, kursiv, Code, Links) – ohne Download-Tabelle und Build-Zeile */
function cl_md(string $md): array {
    $md = str_replace("\r", '', $md);
    $md = preg_replace('/^## VRC Nexus [^\n]*\n/m', '', $md);
    $md = preg_replace('/\n\| File \|.*$/s', '', $md);   // Download-Tabelle und alles danach (Hinweise, Build)
    $inline = function (string $t): string {
        $t = e($t);
        $t = preg_replace('/`([^`]+)`/', '<code>$1</code>', $t);
        $t = preg_replace('/\*\*([^*]+)\*\*/', '<b>$1</b>', $t);
        $t = preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/', '<i>$1</i>', $t);
        $t = preg_replace('/\[([^\]]+)\]\((https:\/\/[^)\s"]+)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $t);
        return $t;
    };
    $html = ''; $title = ''; $inList = 0;
    foreach (explode("\n", $md) as $l) {
        if (preg_match('/^###\s+(.*)$/', $l, $m)) { if ($inList) { $html .= str_repeat('</ul>', $inList); $inList = 0; } $t = preg_replace('/^New in [\d.]+\s*[–-]\s*/u', '', $m[1]); if ($title === '') $title = $t; else $html .= '<h4>' . $inline($t) . '</h4>'; continue; }
        if (preg_match('/^(\s*)[-*]\s+(.*)$/', $l, $m)) {
            $lvl = strlen($m[1]) >= 2 ? 2 : 1;
            while ($inList < $lvl) { $html .= '<ul>'; $inList++; }
            while ($inList > $lvl) { $html .= '</ul>'; $inList--; }
            $html .= '<li>' . $inline($m[2]) . '</li>'; continue;
        }
        if ($inList) { $html .= str_repeat('</ul>', $inList); $inList = 0; }
        if (trim($l) !== '') $html .= '<p>' . $inline(trim($l)) . '</p>';
    }
    if ($inList) $html .= str_repeat('</ul>', $inList);
    return [$title, $html];
}
$rel = cl_fetch();
$majors = [];
foreach ($rel as $r) { $mj = (int)explode('.', $r['v'])[0]; $majors[$mj] = ($majors[$mj] ?? 0) + 1; }
$fmt = fn(string $d) => $d ? date('d.m.Y', strtotime($d)) : '';
$kind = function (string $name): string {
    if (str_contains($name, 'Watch')) return '⌚ Wear OS';
    if (str_ends_with($name, '.apk')) return '📱 Android';
    if (str_contains($name, 'Setup')) return '🪟 Windows-Installer';
    if (str_contains($name, 'portable')) return '🪟 Windows portable';
    if (str_contains($name, 'streamDeck')) return '🎛 Stream Deck';
    return '📦 ' . $name;
};
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Changelog · VRC Nexus</title>
<meta name="description" content="Alle Versionen von VRC Nexus mit Neuerungen und Downloads.">
<link rel="stylesheet" href="assets/css/hud.css">
<style>
body.cl{padding:0;display:block}
.cl-wrap{box-sizing:border-box;width:100%;max-width:980px;margin:0 auto;padding:20px 20px 60px}
.cl-hero{padding:30px 0 18px;border-bottom:1px solid var(--border-color)}
.cl-hero h1{font-size:clamp(2rem,5vw,3rem);letter-spacing:6px;margin:8px 0;color:var(--accent-gold)}
.cl-hero p{color:var(--text-muted);margin:0;line-height:1.6}
.cl-tools{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0;position:sticky;top:52px;z-index:5;background:rgba(4,6,9,.92);padding:10px 0}
.cl-tools input{flex:1;min-width:200px}
.cl-chip{border:1px solid var(--border-color);background:transparent;color:var(--text-main);padding:6px 12px;border-radius:16px;cursor:pointer;font:inherit;font-size:.82rem}
.cl-chip.on{border-color:var(--accent-cyan);background:rgba(56,213,230,.14);color:#fff}
.cl-rel{border-left:2px solid var(--border-color);padding:0 0 22px 20px;position:relative}
.cl-rel::before{content:"";position:absolute;left:-7px;top:6px;width:12px;height:12px;border-radius:50%;background:var(--accent-cyan);box-shadow:0 0 10px var(--accent-cyan)}
.cl-rel.minor::before{width:8px;height:8px;left:-5px;background:var(--text-muted);box-shadow:none}
.cl-rel header{display:flex;gap:10px;align-items:baseline;flex-wrap:wrap}
.cl-rel h2{margin:0;font-size:1.15rem;color:#fff;border:0;padding:0;box-shadow:none}.cl-rel header{border:0}.cl-rel h2 a{color:inherit;text-decoration:none}
.cl-v{font-family:monospace;color:var(--accent-gold);font-size:.95rem}
.cl-date{color:var(--text-muted);font-size:.8rem}
.cl-body{background:var(--panel-bg);border:1px solid var(--border-color);padding:12px 16px;margin-top:8px;line-height:1.55;font-size:.92rem}
.cl-body ul{margin:6px 0;padding-left:20px}.cl-body li{margin:3px 0}.cl-body h4{margin:12px 0 4px;color:var(--accent-cyan)}
.cl-body code{background:rgba(56,213,230,.1);padding:0 4px}.cl-body a{color:var(--accent-cyan)}
.cl-dl{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}
.cl-dl a{font-size:.78rem;border:1px solid var(--border-color);padding:4px 10px;color:var(--text-main);text-decoration:none}
.cl-dl a:hover{border-color:var(--accent-cyan);color:var(--accent-cyan)}
.cl-latest{font-size:.66rem;letter-spacing:1px;color:#000;background:var(--accent-gold);padding:1px 6px;font-weight:700}
.cl-empty{color:var(--text-muted);text-align:center;padding:40px 0}
</style></head><body class="cl"><div class="cl-wrap">
<div class="cl-hero"><div class="hud-tag">GhostNode · VRC Nexus · Changelog</div><h1>CHANGELOG</h1>
<p><?= count($rel) ?> Versionen – neueste zuerst. Jede Version mit Neuerungen und Downloads für Windows, Android, Wear OS und Stream Deck. Was als Nächstes kommt, steht in der <a href="Roadmap.php" style="color:var(--accent-cyan)">Roadmap</a>.</p></div>
<?php if (!$rel): ?><p class="cl-empty">Die Versionsliste ist gerade nicht erreichbar (GitHub). Bitte später noch einmal – oder direkt auf <a href="https://github.com/<?= CL_REPO ?>/releases" style="color:var(--accent-cyan)">GitHub</a>.</p>
<?php else: ?>
<div class="cl-tools"><input type="search" id="clQ" placeholder="🔍 Suchen, z. B. „Uhr“, „OSC“, „5.2“ …" aria-label="Changelog durchsuchen">
<button type="button" class="cl-chip on" data-mj="">Alle</button><?php foreach ($majors as $mj => $n): ?><button type="button" class="cl-chip" data-mj="<?= $mj ?>"><?= $mj ?>.x <small>(<?= $n ?>)</small></button><?php endforeach; ?></div>
<?php foreach ($rel as $i => $r): [$t, $h] = cl_md($r['body']); $minor = !preg_match('/\.0$/', $r['v']); ?>
<article class="cl-rel<?= $minor ? ' minor' : '' ?>" id="v<?= e($r['v']) ?>" data-mj="<?= (int)explode('.', $r['v'])[0] ?>">
<header><span class="cl-v"><?= e($r['v']) ?></span><h2><a href="#v<?= e($r['v']) ?>"><?= e($t ?: 'VRC Nexus ' . $r['v']) ?></a></h2><span class="cl-date"><?= e($fmt($r['date'])) ?></span><?php if ($i === 0): ?><span class="cl-latest">AKTUELL</span><?php endif; ?></header>
<div class="cl-body"><?= $h ?: '<p class="cl-date">Keine Beschreibung.</p>' ?>
<?php if ($r['assets']): ?><div class="cl-dl"><?php foreach ($r['assets'] as $a): if (!preg_match('#^https://github\.com/#', $a['url'])) continue; ?><a href="<?= e($a['url']) ?>" rel="noopener"><?= e($kind($a['name'])) ?> · <?= round($a['size'] / 1048576, 1) ?> MB</a><?php endforeach; ?><a href="<?= e($r['url']) ?>" target="_blank" rel="noopener">GitHub ↗</a></div><?php endif; ?>
</div></article>
<?php endforeach; endif; ?>
</div>
<script>
(function () {
    const q = document.getElementById('clQ'); if (!q) return;
    let mj = '';
    const run = () => { const s = q.value.trim().toLowerCase(); document.querySelectorAll('.cl-rel').forEach(a => { a.hidden = (mj && a.dataset.mj !== mj) || (s && !a.textContent.toLowerCase().includes(s)); }); };
    q.addEventListener('input', run);
    document.querySelectorAll('.cl-chip').forEach(b => b.addEventListener('click', () => { mj = b.dataset.mj; document.querySelectorAll('.cl-chip').forEach(x => x.classList.toggle('on', x === b)); run(); }));
})();
</script>
</body></html>
