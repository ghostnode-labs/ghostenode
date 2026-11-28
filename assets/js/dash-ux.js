/* 9.0 Dashboard: persönliche Anordnung (Reihenfolge, ausblenden, einklappen – im Konto gespeichert), Gruppen-Filter, Befehlspalette (Strg+K). */
(function () {
    const IDX = window.IDX || {};
    const $ = (s, r) => (r || document).querySelector(s), $$ = (s, r) => [...(r || document).querySelectorAll(s)];
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const W = window.Widgets || {};
    const toast = (m, bad) => (W.toast ? W.toast(m, bad) : alert(m));
    const grid = $('#wgGrid'), area = $('#wgArea');

    // ---------- Persönliche Ansicht ----------
    const prefs = Object.assign({ order: [], hidden: [], min: [], group: '' }, IDX.prefs || {});
    const cards = () => grid ? $$('.wg', grid) : [];
    let saveTimer = null;
    function collect() {
        prefs.order = cards().map(c => c.dataset.uid);
        prefs.hidden = cards().filter(c => c.hidden).map(c => c.dataset.uid);
        prefs.min = cards().filter(c => c.classList.contains('min')).map(c => c.dataset.uid);
    }
    function save() {
        if (!IDX.user || IDX.wgEdit) return;
        collect();
        clearTimeout(saveTimer);
        saveTimer = setTimeout(() => {
            W.call('prefs', { order: JSON.stringify(prefs.order), hidden: JSON.stringify(prefs.hidden), min: JSON.stringify(prefs.min), group: prefs.group })
                .catch(e => toast(e.message, true));
        }, 350);
    }
    window.DashPrefs = { save };

    function show(el, on) {
        el.hidden = !on;
        el.classList.toggle('is-off', !on);
        const body = $('.wg-body', el);
        if (on && body && body.dataset.lazy) { delete body.dataset.lazy; W.reload && W.reload(el); }
        W.relayout && W.relayout();
    }
    function paintTray() {
        const list = $('#wgTrayList');
        if (!list) return;
        const off = cards().filter(c => c.hidden);
        list.innerHTML = off.length
            ? '<span class="wg-tray-label">Ausgeblendet:</span>' + off.map(c => '<button type="button" class="wg-chip" data-wg-show="' + esc(c.dataset.uid) + '" title="Wieder einblenden">' + esc(c.dataset.icon) + ' ' + esc(c.dataset.name) + ' <b>＋</b></button>').join('')
            : '<span class="wg-tray-label">Keine ausgeblendeten Widgets.</span>';
    }

    // Gruppen-Filter
    function applyGroup(g) {
        prefs.group = g || '';
        cards().forEach(c => c.classList.toggle('g-out', !!prefs.group && c.dataset.group !== prefs.group));
        $$('#wgGroups [data-g]').forEach(b => b.classList.toggle('on', b.dataset.g === prefs.group));
        cards().forEach(c => { const b = $('.wg-body', c); if (!c.hidden && !c.classList.contains('g-out') && b && b.dataset.lazy) { delete b.dataset.lazy; W.reload && W.reload(c); } });
        W.relayout && W.relayout();
    }
    const groups = $('#wgGroups');
    if (groups) {
        if (!$('[data-g="' + CSS.escape(prefs.group) + '"]', groups)) prefs.group = '';
        applyGroup(prefs.group);
        groups.addEventListener('click', e => { const b = e.target.closest('[data-g]'); if (!b) return; applyGroup(b.dataset.g); save(); });
    }

    // Anpassen-Modus
    const tray = $('#wgTray'), btn = $('#wgCustomize');
    let drag = null;
    function setCustomize(on) {
        if (!tray || !grid) return;
        document.body.classList.toggle('customizing', on);
        tray.hidden = !on;
        if (btn) btn.classList.toggle('on', on);
        cards().forEach(c => { c.draggable = on; });
        if (on && area && area.classList.contains('collapsed')) $('#wgToggle') && $('#wgToggle').click();
        paintTray();
        W.relayout && W.relayout();
    }
    if (btn) btn.addEventListener('click', () => {
        if (!grid) return toast('Hier gibt es noch keine Widgets.', true);
        setCustomize(!document.body.classList.contains('customizing'));
        if (document.body.classList.contains('customizing')) area.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    if (tray) {
        $('#wgDone').addEventListener('click', () => setCustomize(false));
        $('#wgReset').addEventListener('click', () => {
            if (!confirm('Persönliche Anordnung zurücksetzen? Reihenfolge, Ausblendungen und Gruppe gelten dann wieder wie für alle.')) return;
            W.call('prefs_reset', {}).then(() => location.reload()).catch(e => toast(e.message, true));
        });
        $('#wgImport').addEventListener('change', e => {
            const f = e.target.files[0];
            if (!f) return;
            if (f.size > 300000) return toast('Datei zu groß.', true);
            f.text().then(txt => W.call('prefs_import', { data: txt })).then(r => {
                toast(r.widgets ? r.widgets + ' Widgets übernommen (Mein Dashboard).' : 'Ansicht übernommen.');
                setTimeout(() => location.reload(), 700);
            }).catch(x => toast(x.message, true));
        });
        tray.addEventListener('click', e => {
            const b = e.target.closest('[data-wg-show]');
            if (!b) return;
            const el = cards().find(c => c.dataset.uid === b.dataset.wgShow);
            if (el) { show(el, true); paintTray(); save(); el.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        });
    }
    if (grid && !IDX.wgEdit) {
        grid.addEventListener('click', e => {
            const h = e.target.closest('[data-wg-hide]');
            if (!h) return;
            const el = h.closest('.wg');
            show(el, false); paintTray(); save();
            toast('„' + el.dataset.name + '“ ausgeblendet – unter 🎛 Anpassen wieder einblenden.');
        });
        // Ziehen nur im Anpassen-Modus
        grid.addEventListener('dragstart', e => { const el = e.target.closest && e.target.closest('.wg'); if (!el || !document.body.classList.contains('customizing')) return; drag = el; el.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; });
        grid.addEventListener('dragend', () => { if (!drag) return; drag.classList.remove('dragging'); drag = null; W.relayout && W.relayout(); save(); });
        grid.addEventListener('dragover', e => {
            if (!drag) return;
            const el = e.target.closest && e.target.closest('.wg');
            e.preventDefault();
            if (!el || el === drag) return;
            const r = el.getBoundingClientRect();
            const after = (e.clientY - r.top) > r.height / 2 || (e.clientX - r.left) > r.width * 0.66;
            grid.insertBefore(drag, after ? el.nextSibling : el);
            W.relayout && W.relayout();
        });
        // Tastatur (auch Handy ohne Drag & Drop): im Anpassen-Modus mit ←/→ am Griff verschieben
        grid.addEventListener('keydown', e => {
            const h = e.target.closest('.wg-own .wg-drag');
            if (!h || !document.body.classList.contains('customizing')) return;
            const el = h.closest('.wg');
            if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { e.preventDefault(); const p = el.previousElementSibling; if (p) { grid.insertBefore(el, p); save(); W.relayout && W.relayout(); h.focus(); } }
            if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { e.preventDefault(); const n = el.nextElementSibling; if (n) { grid.insertBefore(n, el); save(); W.relayout && W.relayout(); h.focus(); } }
        });
        $$('.wg-own .wg-drag', grid).forEach(h => { h.tabIndex = 0; h.setAttribute('role', 'button'); h.setAttribute('aria-label', 'Verschieben (Pfeiltasten)'); });
    }

    // ---------- Befehlspalette (Strg+K) ----------
    const cmds = (IDX.cmd || []).slice();
    if (IDX.user && !IDX.wgEdit && grid) cmds.push({ i: '🎛', t: 'Widgets anpassen', s: 'Aktion', a: 'customize' });
    cmds.push({ i: '🔎', t: 'Websuche / lokale Suche fokussieren', s: 'Aktion', a: 'search' });
    cards().forEach(c => cmds.push({ i: c.dataset.icon, t: 'Widget: ' + c.dataset.name, s: 'Zum Widget springen', a: 'wg:' + c.dataset.uid }));
    let pal = null, sel = 0, hits = [];
    function norm(s) { return String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
    function score(c, q) {
        if (!q) return 1;
        const title = norm(c.t), t = title + ' ' + norm(c.s);
        if (title.startsWith(q)) return 4;
        if (title.includes(q)) return 3;
        if (t.includes(q)) return 2;
        // Buchstaben in Reihenfolge („dwnl“ → Downloads): je enger beieinander, desto besser
        const sub = s => { let i = 0, first = -1, last = -1; for (let k = 0; k < s.length && i < q.length; k++) if (s[k] === q[i]) { if (first < 0) first = k; last = k; i++; } return i === q.length ? 1 + 0.9 / (last - first + 1 - q.length + 1) : 0; };
        return Math.max(sub(title) * 1.05, sub(t));
    }
    function render(q) {
        q = norm(q.trim());
        hits = cmds.map(c => [score(c, q), c]).filter(x => x[0] > 0).sort((a, b) => b[0] - a[0]).slice(0, 12).map(x => x[1]);
        if (q.length >= 2) hits.push({ i: '🌐', t: 'Überall suchen: „' + q + '“', s: 'Globale Suche', h: 'search.php?q=' + encodeURIComponent(q) });
        sel = Math.min(sel, Math.max(0, hits.length - 1));
        $('.cmd-list', pal).innerHTML = hits.map((c, i) => '<li role="option" data-i="' + i + '" class="' + (i === sel ? 'on' : '') + '" aria-selected="' + (i === sel) + '"><span class="cmd-ic">' + esc(c.i) + '</span><span class="cmd-t">' + esc(c.t) + '</span><small>' + esc(c.s) + '</small></li>').join('')
            || '<li class="cmd-none">Nichts gefunden.</li>';
    }
    function run(c) {
        if (!c) return;
        close();
        if (c.h) { location.href = c.h; return; }
        if (c.a === 'customize') { btn && btn.click(); return; }
        if (c.a === 'search') { const s = $('#searchInput'); if (s) s.focus(); return; }
        if (c.a && c.a.startsWith('wg:')) {
            const el = cards().find(x => x.dataset.uid === c.a.slice(3));
            if (!el) return;
            if (el.hidden) { show(el, true); paintTray(); save(); }
            if (el.classList.contains('g-out')) { applyGroup(''); save(); }
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            el.classList.add('flash'); setTimeout(() => el.classList.remove('flash'), 1400);
        }
    }
    function open() {
        if (!pal) {
            pal = document.createElement('div');
            pal.className = 'cmd-overlay';
            pal.innerHTML = '<div class="cmd-box" role="dialog" aria-modal="true" aria-label="Befehlspalette"><input type="text" class="cmd-in" placeholder="Seite, Widget oder Aktion … (Enter öffnet)" autocomplete="off" aria-label="Befehl suchen"><ul class="cmd-list" role="listbox"></ul><div class="cmd-foot"><kbd>↑</kbd><kbd>↓</kbd> wählen · <kbd>Enter</kbd> öffnen · <kbd>Esc</kbd> schließen</div></div>';
            document.body.appendChild(pal);
            const inp = $('.cmd-in', pal);
            inp.addEventListener('input', () => { sel = 0; render(inp.value); });
            inp.addEventListener('keydown', e => {
                if (e.key === 'ArrowDown') { e.preventDefault(); sel = Math.min(hits.length - 1, sel + 1); render(inp.value); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); sel = Math.max(0, sel - 1); render(inp.value); }
                else if (e.key === 'Enter') { e.preventDefault(); run(hits[sel]); }
                else if (e.key === 'Escape') close();
            });
            pal.addEventListener('click', e => { if (e.target === pal) return close(); const li = e.target.closest('li[data-i]'); if (li) run(hits[+li.dataset.i]); });
        }
        pal.classList.add('open');
        const inp = $('.cmd-in', pal);
        inp.value = ''; sel = 0; render('');
        setTimeout(() => inp.focus(), 10);
    }
    function close() { if (pal) pal.classList.remove('open'); }
    $$('#cmdOpen').forEach(b => b.addEventListener('click', open));
    document.addEventListener('keydown', e => {
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); pal && pal.classList.contains('open') ? close() : open(); return; }
        const typing = /^(INPUT|TEXTAREA|SELECT)$/.test((e.target.tagName || '')) || e.target.isContentEditable;
        if (!typing && !e.ctrlKey && !e.metaKey && !e.altKey && (e.key === 'a' || e.key === 'A') && btn) { e.preventDefault(); btn.click(); }
    });
})();
