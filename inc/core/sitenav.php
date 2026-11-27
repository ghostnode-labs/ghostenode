<?php
/**
 * Webseite 2.0 – einheitliches Menü für alle Seiten (Desktop + Handy) mit Suche.
 * Wird zentral über die Ausgabe eingehängt (direkt nach <body>), damit keine Seite einzeln angepasst werden muss.
 * Ausgenommen: Schnittstellen/Bilder/Downloads, die Landing-Page (eigenes Menü) und Seiten mit data-no-sitenav.
 */

const SITENAV_SKIP = ['index.php', 'p.php', 'cron.php', 'session.php', 'toyrelay.php', 'client.php', 'backup.php',
    'nexusacct.php', 'nexuschat.php', 'nexuscomm.php', 'nexuswarn.php', 'nexuspulse.php', 'nexuslic.php', 'auth-discord.php'];
const SITENAV_MAIN = [
    ['dashboard.php', '🧭', 'Dashboard'], ['nexus.php', '🥽', 'VRC Nexus'], ['poster.php', '🖼️', 'Poster'],
    ['market.php', '🧩', 'Marktplatz'], ['changelog.php', '📜', 'Changelog'], ['downloads.php', '⬇', 'Downloads'], ['Roadmap.php', '🗺', 'Roadmap'],
];

function sitenav_start(): void {
    if (PHP_SAPI === 'cli' || isset($GLOBALS['__sitenav'])) return;
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === '' || in_array($script, SITENAV_SKIP, true)) return;
    foreach (['api', 'dl', 'thumb', 'download', 'export', 'raw', 'json'] as $k) if (isset($_GET[$k])) return;
    $GLOBALS['__sitenav'] = true;
    ob_start('sitenav_inject');
}

function sitenav_inject(string $buf): string {
    try {
        foreach (headers_list() as $h) if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) return $buf;
        $pos = stripos($buf, '<body');
        if ($pos === false || strpos($buf, 'data-no-sitenav') !== false || strpos($buf, 'class="gn-nav"') !== false) return $buf;
        $end = strpos($buf, '>', $pos);
        if ($end === false) return $buf;
        return substr($buf, 0, $end + 1) . sitenav_html() . substr($buf, $end + 1);
    } catch (Throwable $e) {
        error_log('sitenav: ' . $e->getMessage());
        return $buf;
    }
}

function sitenav_html(): string {
    require_once __DIR__ . '/pages.php';
    $here = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    // im Ausgabe-Handler keine neue Sitzung starten (Header sind dann schon unterwegs)
    $u = function_exists('current_user') && session_status() === PHP_SESSION_ACTIVE ? current_user() : null;
    $main = array_column(SITENAV_MAIN, 0);
    // weitere Seiten aus der Seitenverwaltung (nur sichtbare)
    $more = [];
    try {
        foreach (site_db()->query('SELECT * FROM pages ORDER BY sort, title')->fetchAll() as $p) {
            if (!page_visible_in_index($p, false)) continue;
            $href = page_href($p);
            if (in_array($href, $main, true) || $href === 'index.php') continue;
            $more[] = ['href' => $href, 'icon' => (string)($p['icon'] ?? '📄'), 'title' => (string)$p['title'], 'desc' => (string)($p['description'] ?? ''),
                'lock' => !$u && ($p['access'] ?? '') !== 'public'];
        }
    } catch (Throwable $e) { /* Seitenverwaltung nicht verfügbar */ }
    $search = [];
    foreach (SITENAV_MAIN as [$h, $i, $t]) $search[] = ['h' => $h, 'i' => $i, 't' => $t, 'd' => ''];
    foreach ($more as $m) $search[] = ['h' => $m['href'], 'i' => $m['icon'], 't' => $m['title'], 'd' => $m['desc']];

    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $o = '<nav class="gn-nav" aria-label="Hauptmenü"><a class="gn-brand" href="index.php"><span>◈</span> GHOST<b>NODE</b></a>'
        . '<button type="button" class="gn-burger" aria-label="Menü" aria-expanded="false">☰</button><div class="gn-menu">';
    foreach (SITENAV_MAIN as [$href, $icon, $title]) $o .= '<a href="' . $h($href) . '"' . ($here === $href ? ' class="on" aria-current="page"' : '') . '>' . $icon . ' ' . $h($title) . '</a>';
    if ($more) {
        $o .= '<div class="gn-more"><button type="button" aria-expanded="false">Mehr ▾</button><div class="gn-drop">';
        foreach ($more as $m) $o .= '<a href="' . $h($m['href']) . '"' . ($here === $m['href'] ? ' class="on"' : '') . ' title="' . $h($m['desc']) . '">' . $h($m['icon']) . ' ' . $h($m['title']) . ($m['lock'] ? ' 🔒' : '') . '</a>';
        $o .= '</div></div>';
    }
    $o .= '<div class="gn-search"><input type="search" placeholder="Suchen … ( / )" aria-label="Seiten suchen" autocomplete="off"><div class="gn-res" hidden></div></div>';
    if ($u) { try { require_once APP_ROOT . '/inc/modules/me.php'; $o = me_style((int)$u['id']) . $o; } catch (Throwable $e) { /* ohne Darstellung */ } }   // 6.6: Darstellung je Konto
    $o .= $u ? '<a class="gn-user" href="me.php" title="Mein Bereich · ' . $h($u['role_name'] ?? '') . '">👤 ' . $h(function_exists('user_label') ? user_label($u) : ($u['username'] ?? '')) . '</a>'
             : '<a class="gn-user" href="admin.php?next=' . $h(urlencode($here)) . '">🔒 Anmelden</a>';
    $o .= '</div></nav><div class="gn-spacer"></div>';
    // 6.6: installierbare Web-App (PWA) – Manifest + Service Worker (nur https, nur Offline-Hinweis, nichts Persönliches im Zwischenspeicher)
    $o .= '<link rel="manifest" href="manifest.webmanifest"><meta name="theme-color" content="#040609"><script>if(location.protocol==="https:"&&"serviceWorker" in navigator)navigator.serviceWorker.register("sw.js").catch(function(){});</script>';
    $items = json_encode($search, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return $o . <<<'CSS'
<style>
.gn-nav{position:fixed;top:0;left:0;right:0;z-index:60;display:flex;align-items:center;gap:14px;padding:8px 18px;background:rgba(4,6,9,.88);backdrop-filter:blur(10px);border-bottom:1px solid rgba(56,213,230,.25);font-family:inherit;box-sizing:border-box}
.gn-spacer{height:52px;flex:none;width:100%}
.gn-brand{text-decoration:none;color:#e2e8f0;font-weight:800;letter-spacing:3px;font-size:.95rem;white-space:nowrap}.gn-brand span{color:#38d5e6}.gn-brand b{color:#f3c262}
.gn-menu{display:flex;align-items:center;gap:4px;flex:1;min-width:0}
.gn-menu>a,.gn-more>button{color:#94a3b8;text-decoration:none;font-size:.78rem;letter-spacing:1px;padding:7px 10px;border:1px solid transparent;background:none;cursor:pointer;font-family:inherit;white-space:nowrap}
.gn-menu>a:hover,.gn-more>button:hover,.gn-menu>a.on{color:#38d5e6;border-color:rgba(56,213,230,.35)}
.gn-more{position:relative}.gn-drop{display:none;position:absolute;top:100%;left:0;min-width:260px;max-height:70vh;overflow:auto;background:rgba(6,9,13,.97);border:1px solid rgba(56,213,230,.35);padding:6px;z-index:61}
.gn-more.open .gn-drop{display:block}.gn-drop a{display:block;padding:8px 10px;color:#e2e8f0;text-decoration:none;font-size:.85rem}.gn-drop a:hover,.gn-drop a.on{background:rgba(56,213,230,.1);color:#38d5e6}
.gn-search{position:relative;margin-left:auto}.gn-search input{width:200px;padding:6px 10px;font-size:.8rem;background:rgba(255,255,255,.04);border:1px solid rgba(56,213,230,.25);color:#e2e8f0}
.gn-res{position:absolute;right:0;top:110%;width:320px;max-height:60vh;overflow:auto;background:rgba(6,9,13,.97);border:1px solid rgba(56,213,230,.35);z-index:61}
.gn-res a{display:block;padding:8px 10px;color:#e2e8f0;text-decoration:none;font-size:.85rem}.gn-res a small{display:block;color:#94a3b8;font-size:.72rem}.gn-res a.sel,.gn-res a:hover{background:rgba(56,213,230,.12)}
.gn-user{color:#f3c262!important;border-color:rgba(243,194,98,.35)!important}
.gn-burger{display:none;margin-left:auto;background:none;border:1px solid rgba(56,213,230,.35);color:#e2e8f0;font-size:1.1rem;padding:4px 10px;cursor:pointer}
.auth-corner,.nav-back[href="dashboard.php"],.nav-back[href="index.php"]{display:none!important}
@media(max-width:900px){.gn-burger{display:block}.gn-menu{display:none;position:fixed;top:50px;left:0;right:0;flex-direction:column;align-items:stretch;background:rgba(4,6,9,.97);border-bottom:1px solid rgba(56,213,230,.25);padding:10px 16px;max-height:calc(100vh - 50px);overflow:auto}
.gn-nav.open .gn-menu{display:flex}.gn-menu>a,.gn-more>button{padding:11px 8px;text-align:left}.gn-drop{position:static;display:block;border:none;background:none;padding:0 0 0 12px;min-width:0;max-height:none}.gn-more>button{display:none}
.gn-search{margin:6px 0}.gn-search input{width:100%;box-sizing:border-box}.gn-res{position:static;width:auto}}
</style>
CSS
        . str_replace('__ITEMS__', (string)$items, <<<'JS'
<script>
(() => {
    const nav = document.querySelector('.gn-nav'); if (!nav) return;
    const ITEMS = __ITEMS__;
    const b = nav.querySelector('.gn-burger'), more = nav.querySelector('.gn-more'), inp = nav.querySelector('.gn-search input'), res = nav.querySelector('.gn-res');
    b.addEventListener('click', () => { const o = nav.classList.toggle('open'); b.setAttribute('aria-expanded', o); });
    if (more) { const mb = more.querySelector('button'); mb.addEventListener('click', e => { e.stopPropagation(); const o = more.classList.toggle('open'); mb.setAttribute('aria-expanded', o); }); document.addEventListener('click', () => more.classList.remove('open')); }
    const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    let sel = 0, hits = [];
    const draw = () => { const q = inp.value.trim().toLowerCase(); if (!q) { res.hidden = true; return; }
        hits = ITEMS.filter(i => (i.t + ' ' + i.d).toLowerCase().includes(q)).slice(0, 12); sel = 0;
        res.innerHTML = (hits.length ? hits.map((i, n) => '<a href="' + esc(i.h) + '"' + (n === sel ? ' class="sel"' : '') + '>' + esc(i.i) + ' ' + esc(i.t) + (i.d ? '<small>' + esc(i.d) + '</small>' : '') + '</a>').join('') : '')
            + '<a href="search.php?q=' + encodeURIComponent(inp.value.trim()) + '">🔍 Alles durchsuchen: „' + esc(inp.value.trim()) + '“<small>Changelog, Termine, Plugins, Seiten</small></a>';
        res.hidden = false; };
    inp.addEventListener('input', draw);
    inp.addEventListener('keydown', e => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); if (!hits.length) return; sel = (sel + (e.key === 'ArrowDown' ? 1 : hits.length - 1)) % hits.length; res.querySelectorAll('a').forEach((a, n) => a.classList.toggle('sel', n === sel)); }
        if (e.key === 'Enter') { e.preventDefault(); if (hits[sel]) location.href = hits[sel].h; else if (inp.value.trim()) location.href = 'search.php?q=' + encodeURIComponent(inp.value.trim()); }
        if (e.key === 'Escape') { inp.value = ''; res.hidden = true; inp.blur(); }
    });
    inp.addEventListener('blur', () => setTimeout(() => { res.hidden = true; }, 150));
    document.addEventListener('keydown', e => { if (e.key === '/' && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName) && !document.activeElement.isContentEditable) { e.preventDefault(); if (innerWidth <= 900) nav.classList.add('open'); inp.focus(); } });
})();
</script>
JS);
}
