/* Dashboard-Extras: ⭐ Angeheftet & 🕘 Zuletzt geöffnet (je Browser und Konto), Kategorien einklappen, Ansicht Kacheln/Kompakt, Begrüßung mit Namen.
 * Alles nur im Browser gespeichert (localStorage) – nichts geht an den Server. */
(function () {
    const IDX = window.IDX || {};
    if (IDX.edit) return;   // im Bearbeiten-Modus nichts umbauen
    const $ = (s, r) => (r || document).querySelector(s), $$ = (s, r) => [...(r || document).querySelectorAll(s)];
    const uid = IDX.user ? IDX.user.id : 0, K = k => 'gn_dash_' + k + '_' + uid;
    const load = (k, d) => { try { const v = JSON.parse(localStorage.getItem(K(k)) || 'null'); return v === null ? d : v; } catch (e) { return d; } };
    const save = (k, v) => { try { localStorage.setItem(K(k), JSON.stringify(v)); } catch (e) { /* privat/voll */ } };
    const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    // ---------- Begrüßung mit Namen ----------
    const greet = $('#greeting');
    if (greet && IDX.user && IDX.user.name) greet.textContent = greet.textContent.replace(/^([^/]+?)( \/\/)/, (m, a, b) => a + ', ' + IDX.user.name + b);

    // ---------- Seiten einsammeln ----------
    const tiles = $$('#sections .tile-wrap').map(w => {
        const a = $('a.tile', w);
        return a && { id: w.dataset.id, w, a, href: a.getAttribute('href'), title: ($('.tile-title', a) || a).childNodes[0].textContent.trim(), icon: ($('.tile-icon', a) || {}).textContent || '📄', locked: a.classList.contains('locked') };
    }).filter(Boolean);
    const byId = Object.fromEntries(tiles.map(t => [t.id, t]));
    let pins = load('pins', null), recent = load('recent', null);
    // einmalig: Favoriten/Verlauf der älteren Leiste (idxFavs/idxRecent, nach Adresse) übernehmen
    const idOf = href => (tiles.find(t => t.href === href) || {}).id;
    const old = k => { try { return JSON.parse(localStorage.getItem(k) || '[]') || []; } catch (e) { return []; } };
    if (pins === null) { pins = old('idxFavs').map(f => idOf(f.href)).filter(Boolean); save('pins', pins); }
    if (recent === null) { recent = old('idxRecent').map((r, i) => ({ id: idOf(r.href), at: Date.now() - i * 60000 })).filter(r => r.id); save('recent', recent); }
    pins = pins.filter(id => byId[id]); recent = recent.filter(r => byId[r.id]);

    // ⭐-Knopf an jeder Kachel
    for (const t of tiles) {
        const b = document.createElement('button');
        b.type = 'button'; b.className = 'tile-pin'; b.dataset.pin = t.id;
        t.w.appendChild(b);
        t.a.addEventListener('click', () => {   // zuletzt geöffnet
            if (t.locked) return;
            recent = [{ id: t.id, at: Date.now() }].concat(recent.filter(r => r.id !== t.id)).slice(0, 8);
            save('recent', recent);
        });
    }
    const paintPins = () => $$('.tile-pin').forEach(b => { const on = pins.includes(b.dataset.pin); b.classList.toggle('on', on); b.textContent = on ? '★' : '☆'; b.title = on ? 'Nicht mehr anheften' : 'Oben anheften'; });

    // ---------- Bereich „Meins“ ----------
    const mine = $('#idxMine');
    const chip = t => '<a class="mine-chip' + (t.locked ? ' locked' : '') + '" href="' + esc(t.href) + '" data-mine="' + esc(t.id) + '"><span>' + esc(t.icon) + '</span>' + esc(t.title) + '</a>';
    const ago = ms => { const m = Math.round((Date.now() - ms) / 60000); return m < 1 ? 'gerade' : m < 60 ? 'vor ' + m + ' min' : m < 1440 ? 'vor ' + Math.round(m / 60) + ' Std.' : 'vor ' + Math.round(m / 1440) + ' Tg.'; };
    function paintMine() {
        if (!mine) return;
        const p = pins.map(id => byId[id]).filter(Boolean), r = recent.filter(x => !pins.includes(x.id)).slice(0, 6);
        if (!p.length && !r.length) { mine.innerHTML = ''; return; }
        mine.innerHTML = '<section class="mine">'
            + (p.length ? '<div class="mine-row"><span class="mine-label">⭐ Angeheftet</span><div class="mine-chips" id="minePins">' + p.map(chip).join('') + '</div></div>' : '')
            + (r.length ? '<div class="mine-row"><span class="mine-label">🕘 Zuletzt</span><div class="mine-chips">' + r.map(x => chip(byId[x.id]).replace('</a>', '<small>' + ago(x.at) + '</small></a>')).join('') + '</div><button type="button" class="mine-clear" data-clear-recent title="Verlauf leeren">✕</button></div>' : '')
            + '</section>';
        // Angeheftete per Ziehen sortieren
        const box = $('#minePins'); let drag = null;
        if (box) for (const c of $$('.mine-chip', box)) {
            c.draggable = true;
            c.addEventListener('dragstart', () => { drag = c; c.classList.add('drag'); });
            c.addEventListener('dragend', () => { c.classList.remove('drag'); pins = $$('.mine-chip', box).map(x => x.dataset.mine); save('pins', pins); drag = null; });
            c.addEventListener('dragover', e => { e.preventDefault(); if (drag && drag !== c) { const r = c.getBoundingClientRect(); box.insertBefore(drag, e.clientX > r.left + r.width / 2 ? c.nextSibling : c); } });
        }
    }
    document.addEventListener('click', e => {
        const b = e.target.closest('.tile-pin');
        if (b) { e.preventDefault(); const id = b.dataset.pin; pins = pins.includes(id) ? pins.filter(x => x !== id) : pins.concat(id); save('pins', pins); paintPins(); paintMine(); return; }
        if (e.target.closest('[data-clear-recent]')) { recent = []; save('recent', recent); paintMine(); }
        const m = e.target.closest('.mine-chip');
        if (m && byId[m.dataset.mine]) { recent = [{ id: m.dataset.mine, at: Date.now() }].concat(recent.filter(r => r.id !== m.dataset.mine)).slice(0, 8); save('recent', recent); }
    });

    // ---------- Kategorien einklappen ----------
    let closed = load('closed', []);
    for (const s of $$('#sections .cat-section')) {
        const h = $('.cat-head h2', s), id = s.dataset.cat;
        if (!h) continue;
        h.classList.add('cat-toggle'); h.tabIndex = 0; h.setAttribute('role', 'button');
        const set = c => { s.classList.toggle('closed', c); h.setAttribute('aria-expanded', c ? 'false' : 'true'); };
        set(closed.includes(id));
        const flip = () => { const c = !s.classList.contains('closed'); set(c); closed = c ? closed.concat(id) : closed.filter(x => x !== id); save('closed', closed); };
        h.addEventListener('click', flip);
        h.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); flip(); } });
    }
    // Suche: eingeklappte Bereiche bei Treffern trotzdem zeigen
    const si = $('#searchInput');
    if (si) si.addEventListener('input', () => document.body.classList.toggle('searching', si.value.trim() !== ''));

    // ---------- Ansicht: Kacheln / Kompakt ----------
    const secs = $('#sections');
    if (secs) {
        const bar = document.createElement('div');
        bar.className = 'view-bar';
        bar.innerHTML = '<span>Ansicht</span><button type="button" data-view="tiles">▦ Kacheln</button><button type="button" data-view="compact">☰ Kompakt</button>'
            + '<button type="button" data-allcats title="Alle Bereiche auf-/zuklappen">⇕</button>';
        secs.parentNode.insertBefore(bar, secs);
        const setView = v => { document.body.classList.toggle('view-compact', v === 'compact'); $$('[data-view]', bar).forEach(b => b.classList.toggle('on', b.dataset.view === v)); save('view', v); };
        setView(load('view', 'tiles'));
        bar.addEventListener('click', e => {
            const b = e.target.closest('[data-view]'); if (b) return setView(b.dataset.view);
            if (e.target.closest('[data-allcats]')) {
                const all = $$('#sections .cat-section'), anyOpen = all.some(s => !s.classList.contains('closed'));
                all.forEach(s => s.classList.toggle('closed', anyOpen));
                closed = anyOpen ? all.map(s => s.dataset.cat) : []; save('closed', closed);
            }
        });
    }
    paintPins(); paintMine();
})();
