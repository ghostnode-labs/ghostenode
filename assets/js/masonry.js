'use strict';
// GhostNode-Webseite: Mauerwerk-Raster für alle Karten-Raster (wird zentral über inc/core/sitenav.php auf jeder Seite eingebunden).
(function () {
    if (!('ResizeObserver' in window) || !('MutationObserver' in window)) return;
    /** Raster, die immer als Mauerwerk laufen (Karten unterschiedlicher Höhe) */
    var CONTAINERS = '.grid, .grid2, .grid3, .roles, .tpl-info, .sv-grid, .cards, .bd-grid, .bd-goals, .bd-2col, .cn-grid2, .cn-calcs, .cn-packs, .dc-cols, .dc-members, '
        + '.fh-grid, .com-grid, .gloss, .paws, .toys, .ld-feat, .ld-proj, .ld-news, .ld-links, .nw-grid, .nxp-wishes, .nxp-devs, '
        + '.vx-inst, .vx-cols, .vx-u-cols, .vx-groups, .vx-fgrid, .nc-grid, .sh-grid, .ns-cols, .cnt-grid, .ps-grid, .dl-grid, .rm-items';
    /** Karten: ist ihr Elternelement ein mehrspaltiges Grid, wird es automatisch zum Mauerwerk */
    var CARDS = '.panel, .card, .nc-card, .nw-card, .fh-card';
    /** nie anfassen: Dashboard-Widgets (eigenes Mauerwerk mit gespeicherter Reihenfolge/Drag & Drop), Kalender, Seitengerüste
     *  (Inhalt + Seitenleiste), Kennzahlen-/Knopf-Kacheln und Formular-Raster */
    var SKIP = '.wg-grid, .wg-cat, .mc-grid, .shell, .kpis, .quick, .quick-btns, .f2, .f3, .perm-grid, .stor, .codes, .bd-kpis, .cn-kpis, .dc-kpis, '
        + '.ld-stats, .rm-stats, .ld-hero, .vx-gstack-b, .cal-layout, .fd-app, .nw-cols, .ns-lb, .jr-layout, .sona-layout, .boop-layout, .pet-layout, '
        + '.tama-layout, .cards-layout, .wheel-wrap, .owo-layout, .bd-donutwrap, .dc-setup, .vx-favwrap, .nc-world, .ps-form, .cl-form, .bd-addgrid, '
        + '.f-grid, .year, .month-row, .week-grid, .row-fields, .nb, .ip-body';
    /*
     * Mauerwerk-Raster (Masonry) – eigene kleine Umsetzung ohne Abhängigkeit.
     * Karten-Raster mit mehreren Spalten strecken bei CSS-Grid jede Zeile auf die höchste Karte → große Lücken
     * unter kürzeren Karten. Hier bekommt das Raster feine Zeilen (4 px, Klasse „nxm-on“), und jede Karte
     * belegt per grid-row-end: span N genau so viele Zeilen, wie sie hoch ist. Karten rutschen dadurch in jeder
     * Spalte nach oben; die Reihenfolge bleibt – nur wenn eine Karte über mehrere Spalten geht, dürfen
     * spätere Karten die Lücke darüber füllen („dense“), sonst bliebe dort Leerraum.
     * Einspaltig (Handy) wird nichts verändert. Elemente mit grid-column: 1 / -1 bleiben volle Breite.
     */
    var ROW = 4, ON = 'nxm-on';
    var BOXES = [CONTAINERS, '.nx-masonry'].filter(Boolean).join(',');
    var known = new Set(), gaps = new WeakMap(), touched = new WeakSet(), seen = new WeakSet();
    var dirty = new Set(), full = false, raf = 0;
    var tracks = function (cs) { return cs.gridTemplateColumns.replace(/\[[^\]]*\]/g, ' ').trim().split(/\s+/).filter(function (t) { return parseFloat(t) > 0; }).length; };
    var skip = function (el) { return SKIP && el.matches(SKIP); };
    function off(c) {
        if (!c.classList.contains(ON)) return;
        c.classList.remove(ON, 'nxm-dense');
        for (var i = 0; i < c.children.length; i++) { var k = c.children[i]; if (touched.has(k)) { k.style.gridRowEnd = ''; touched.delete(k); } }
    }
    function layout(c) {
        if (!c.isConnected) { known.delete(c); return; }
        if (!c.getClientRects().length) return;                       // versteckt – später, wenn sichtbar (ResizeObserver)
        var cs = getComputedStyle(c);
        if (!/grid/.test(cs.display) || /column/.test(cs.gridAutoFlow)) { off(c); return; }
        if (tracks(cs) < 2 || c.children.length < 2) { off(c); return; }
        if (!c.classList.contains(ON)) { gaps.set(c, parseFloat(cs.rowGap) || 0); c.classList.add(ON); }
        var gap = gaps.get(c) || 0, list = [], wide = false, i, k, kc, tw = parseFloat(cs.gridTemplateColumns.replace(/\[[^\]]*\]/g, ' ').trim()) || 1e9;
        for (i = 0; i < c.children.length; i++) {
            k = c.children[i];
            if (!seen.has(k)) { seen.add(k); ro.observe(k); }
            if (!k.getClientRects().length) continue;                 // ausgeblendet
            kc = getComputedStyle(k);
            if (kc.position === 'absolute' || kc.position === 'fixed') continue;
            if (!touched.has(k) && (k.style.gridRow || kc.gridRowStart !== 'auto' || kc.gridRowEnd !== 'auto')) continue;   // feste Zeile vom Layout – nicht anfassen
            var kr = k.getBoundingClientRect();
            if (kr.width > tw * 1.5) wide = true;                       // Karte über mehrere Spalten
            list.push([k, kr.height + (parseFloat(kc.marginTop) || 0) + (parseFloat(kc.marginBottom) || 0)]);
        }
        // Karten über mehrere Spalten (z. B. grid-column: 1 / -1) würden über sich Lücken lassen → spätere Karten dürfen sie füllen
        if (c.classList.contains('nxm-dense') !== wide) c.classList.toggle('nxm-dense', wide);
        for (i = 0; i < list.length; i++) {                           // erst alles messen, dann schreiben (kein Layout-Flattern)
            var cur = parseInt(String(list[i][0].style.gridRowEnd).replace('span ', ''), 10) || 0;
            if (cur && Math.abs(list[i][1] - cur * ROW) < 0.5) continue;  // füllt genau ihre Fläche (height: 100 %) → nicht hochschaukeln
            var v = 'span ' + Math.max(1, Math.ceil((list[i][1] + gap) / ROW));
            if (list[i][0].style.gridRowEnd !== v) list[i][0].style.gridRowEnd = v;
            touched.add(list[i][0]);
        }
    }
    function scan() {
        var found = new Set(), i, l;
        if (BOXES) { l = document.querySelectorAll(BOXES); for (i = 0; i < l.length; i++) found.add(l[i]); }
        if (CARDS) { l = document.querySelectorAll(CARDS); for (i = 0; i < l.length; i++) if (l[i].parentElement) found.add(l[i].parentElement); }
        found.forEach(function (c) {
            if (known.has(c) || skip(c)) return;
            if (CARDS && !(BOXES && c.matches(BOXES)) && !/grid/.test(getComputedStyle(c).display)) return;
            known.add(c); ro.observe(c);
        });
        known.forEach(function (c) { dirty.add(c); });
    }
    function run() {
        raf = 0;
        if (full) { full = false; scan(); }
        var l = Array.from(dirty); dirty.clear();
        for (var i = 0; i < l.length; i++) layout(l[i]);
    }
    function queue(c) { if (c) dirty.add(c); else full = true; if (!raf) raf = requestAnimationFrame(run); }
    var ro = new ResizeObserver(function (es) {
        for (var i = 0; i < es.length; i++) { var t = es[i].target; queue(known.has(t) ? t : (t.parentElement && known.has(t.parentElement) ? t.parentElement : null)); }
    });
    new MutationObserver(function (ms) {
        for (var i = 0; i < ms.length; i++) { var m = ms[i]; if (m.type === 'childList' || !(m.target.classList && m.target.classList.contains(ON) && m.attributeName === 'class')) { queue(); return; } }
    }).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'hidden', 'open'] });
    window.addEventListener('load', function () { queue(); });
    /** von außen: Raster sofort neu berechnen (z. B. nach eigenem Umsortieren) */
    window.nxMasonry = { refresh: function () { queue(); }, ROW: ROW };
    queue();
})();
