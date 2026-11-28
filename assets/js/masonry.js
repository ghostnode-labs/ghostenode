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
    /*
     * 8.6 GRUNDREGEL – 4er-Raster: jedes Mauerwerk hat 4 gleich breite Spalten (schmal: 2, Handy: 1), jede Kachel hat
     * eine von vier Größen: S = 1 Spalte, M = 2 (Standard), L = 3, XL = 4 (ganze Inhaltsbreite).
     * Größe setzen: Klasse sz-s / sz-m / sz-l / sz-xl (oder data-sz="s|m|l|xl"); Kacheln mit grid-column: 1 / -1
     * (z. B. .ns-wide) gelten als XL. Freie Plätze rechts werden immer aufgefüllt („dense“).
     */
    var SIZES = { s: 1, m: 2, l: 3, xl: 4 }, sizeOf = new WeakMap();
    function sizeFor(k) {
        var z = 0, m = /(?:^|\s)sz-(s|m|l|xl)(?:\s|$)/.exec(typeof k.className === 'string' ? k.className : '');
        if (m) return SIZES[m[1]];                                    // Klasse/Attribut immer frisch lesen (kann später gesetzt werden)
        if (k.dataset && SIZES[k.dataset.sz]) return SIZES[k.dataset.sz];
        if (sizeOf.has(k)) return sizeOf.get(k);
        { var kc = getComputedStyle(k); z = (kc.gridColumnEnd === '-1' || /^span (?:[4-9]|\d\d)/.test(kc.gridColumnEnd)) ? 4 : 2; }
        sizeOf.set(k, z);
        return z;
    }
    function colsFor(c) { var w = c.getBoundingClientRect().width; return w >= 980 ? 4 : w >= 520 ? 2 : 1; }
    var BOXES = [CONTAINERS, '.nx-masonry'].filter(Boolean).join(',');
    var known = new Set(), gaps = new WeakMap(), touched = new WeakSet(), seen = new WeakSet();
    var dirty = new Set(), full = false, raf = 0;
    var tracks = function (cs) { return cs.gridTemplateColumns.replace(/\[[^\]]*\]/g, ' ').trim().split(/\s+/).filter(function (t) { return parseFloat(t) > 0; }).length; };
    var skip = function (el) { return SKIP && el.matches(SKIP); };
    function off(c) {
        if (!c.classList.contains(ON)) return;
        c.classList.remove(ON, 'nxm-dense', 'nxm-c1', 'nxm-c2', 'nxm-c4');
        for (var i = 0; i < c.children.length; i++) { var k = c.children[i]; if (touched.has(k)) { k.style.gridRowEnd = ''; k.style.gridColumn = ''; touched.delete(k); } }
    }
    function layout(c) {
        var i;
        if (!c.isConnected) { known.delete(c); return; }
        if (!c.getClientRects().length) return;                       // versteckt – später, wenn sichtbar (ResizeObserver)
        var cs = getComputedStyle(c);
        if (!/grid/.test(cs.display) || /column/.test(cs.gridAutoFlow)) { off(c); return; }
        if ((!c.classList.contains(ON) && tracks(cs) < 2) || c.children.length < 2) { off(c); return; }
        for (i = 0; i < c.children.length; i++) sizeFor(c.children[i]);   // Größe merken, bevor eigene Spalten gesetzt werden
        if (!c.classList.contains(ON)) { gaps.set(c, parseFloat(cs.rowGap) || 0); c.classList.add(ON); }
        var cols = colsFor(c);
        ['nxm-c1', 'nxm-c2', 'nxm-c4'].forEach(function (x) { c.classList.toggle(x, x === 'nxm-c' + cols); });
        for (i = 0; i < c.children.length; i++) { var ch = c.children[i], sp = 'span ' + Math.min(cols, sizeFor(ch)); if (ch.style.gridColumn !== sp) ch.style.gridColumn = sp; touched.add(ch); }
        cs = getComputedStyle(c);
        var gap = gaps.get(c) || 0, list = [], wide = true, k, kc, tw = parseFloat(cs.gridTemplateColumns.replace(/\[[^\]]*\]/g, ' ').trim()) || 1e9;
        for (i = 0; i < c.children.length; i++) {
            k = c.children[i];
            if (!seen.has(k)) { seen.add(k); ro.observe(k); }
            if (!k.getClientRects().length) continue;                 // ausgeblendet
            kc = getComputedStyle(k);
            if (kc.position === 'absolute' || kc.position === 'fixed') continue;
            if (!touched.has(k) && (k.style.gridRow || kc.gridRowStart !== 'auto' || kc.gridRowEnd !== 'auto')) continue;   // feste Zeile vom Layout – nicht anfassen
            var kr = k.getBoundingClientRect();
            void tw;                                                    // 8.6: immer „dense“ – freie Plätze werden aufgefüllt
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
    /** 8.6: Nachprüfung – liegt eine Karte über ihre Fläche hinaus (z. B. Inhalt nach dem Messen geändert, Aufklappen,
     *  Schriften/Bilder später geladen), wird das Raster sofort neu berechnet → keine Überlappungen und keine Lücken */
    function verify(c) {
        if (!c.isConnected || !c.classList.contains(ON)) return;
        for (var i = 0; i < c.children.length; i++) {
            var k = c.children[i];
            if (!touched.has(k) || !k.getClientRects().length) continue;
            var span = parseInt(String(k.style.gridRowEnd).replace('span ', ''), 10) || 0, kc = getComputedStyle(k);
            var h = k.getBoundingClientRect().height + (parseFloat(kc.marginTop) || 0) + (parseFloat(kc.marginBottom) || 0);
            if (span && Math.abs(h - span * ROW) < 0.5) continue;      // füllt genau ihre Fläche (height: 100 %) → nicht hochschaukeln
            var need = Math.ceil((h + (gaps.get(c) || 0)) / ROW);
            if (need !== span) { k.style.gridRowEnd = 'span ' + Math.max(1, need); }
        }
    }
    var checkT = 0;
    function run() {
        raf = 0;
        if (full) { full = false; scan(); }
        var l = Array.from(dirty); dirty.clear();
        for (var i = 0; i < l.length; i++) layout(l[i]);
        clearTimeout(checkT);
        checkT = setTimeout(function () { known.forEach(verify); }, 250);
    }
    function queue(c) { if (c) dirty.add(c); else full = true; if (!raf) raf = requestAnimationFrame(run); }
    var ro = new ResizeObserver(function (es) {
        for (var i = 0; i < es.length; i++) { var t = es[i].target; queue(known.has(t) ? t : (t.parentElement && known.has(t.parentElement) ? t.parentElement : null)); }
    });
    new MutationObserver(function (ms) {
        for (var i = 0; i < ms.length; i++) { var m = ms[i]; if (m.type === 'childList' || !(m.target.classList && m.target.classList.contains(ON) && m.attributeName === 'class')) { queue(); return; } }
    }).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'hidden', 'open'] });
    window.addEventListener('load', function () { queue(); });
    // 8.6: Auf-/Zuklappen (<details>), nachgeladene Bilder und Schriften → neu berechnen
    document.addEventListener('toggle', function () { queue(); }, true);
    document.addEventListener('load', function (e) { if (e.target && e.target.tagName === 'IMG') queue(); }, true);
    if (document.fonts && document.fonts.addEventListener) document.fonts.addEventListener('loadingdone', function () { queue(); });
    /** von außen: Raster sofort neu berechnen (z. B. nach eigenem Umsortieren) */
    window.nxMasonry = { refresh: function () { queue(); }, ROW: ROW };
    queue();
})();
