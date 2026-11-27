/* GhostNode – 8.1 Mehrsprachig: Deutsch (Original), English, Français, 日本語.
 * Übersetzt bekannte Texte (assets/js/site-dict.js) direkt im DOM – auch später nachgeladene. Inhalte von Leuten (Namen, Posts, Notizen) bleiben, wie sie sind.
 * Sprache: Flaggen-Menü unten links; Standard = Browsersprache (wenn vorhanden), sonst Deutsch. Gemerkt im Browser (localStorage „gnLang“). */
(function () {
    const LANGS = { de: ['Deutsch', 'de-DE', '🇩🇪'], en: ['English', 'en-GB', '🇬🇧'], fr: ['Français', 'fr-FR', '🇫🇷'], ja: ['日本語', 'ja-JP', '🇯🇵'] };
    const sys = () => { for (const l of [].concat(navigator.languages || [], navigator.language || [])) { const k = String(l).slice(0, 2).toLowerCase(); if (LANGS[k]) return k; } return 'de'; };
    let lang = 'de';
    try { const l = localStorage.getItem('gnLang'); lang = LANGS[l] ? l : sys(); } catch (e) { lang = sys(); }
    const D = () => ((window.SiteDict || {})[lang]) || null;
    const HEAD = /^[^\p{L}\p{N}„"(]+/u, TAIL = /[\s:…→←▾▸▶◀·.!?)"“]+$/u;
    const SKIP = 'script, style, textarea, code, pre, [contenteditable], [data-no-i18n], .user-content, .cm-post, .md, input[type=text], input[type=search]';
    function tr(text, depth = 0) {
        const d = D(); if (!d || !text) return null;
        const t = text.trim().replace(/\s+/g, ' '); if (!t) return null;
        if (d[t] !== undefined) return d[t];
        const h = (t.match(HEAD) || [''])[0], rest = t.slice(h.length);
        const tl = (rest.match(TAIL) || [''])[0], core = rest.slice(0, rest.length - tl.length);
        if (core && d[core] !== undefined) return h + d[core] + tl;
        const nums = []; const k = (core || t).replace(/\d+(?:[.,:]\d+)*/g, m => { nums.push(m); return '#'; });
        if (nums.length && d[k] !== undefined) { let i = 0; return (core ? h : '') + d[k].replace(/#/g, () => nums[i++] ?? '') + (core ? tl : ''); }
        if (depth < 2 && core.includes(' · ')) { let hit = false; const parts = core.split(' · ').map(p => { const x = tr(p, depth + 1); if (x !== null) hit = true; return x === null ? p : x; }); if (hit) return h + parts.join(' · ') + tl; }
        return null;
    }
    window.gnT = s => (lang === 'de' ? s : tr(s) || s);
    const ORIG = new WeakMap(), ATTR = ['placeholder', 'title', 'aria-label', 'alt'];
    function doText(n) {
        if (n.parentElement && n.parentElement.closest(SKIP)) return;
        let o = ORIG.get(n);
        if (o === undefined || (n.nodeValue !== o && n.nodeValue !== n.__tr)) { o = n.nodeValue; ORIG.set(n, o); }
        if (lang === 'de') { if (n.nodeValue !== o) n.nodeValue = o; return; }
        const x = tr(o);
        if (x === null) { if (n.nodeValue !== o) n.nodeValue = o; return; }
        const v = o.replace(o.trim(), x); n.__tr = v; if (n.nodeValue !== v) n.nodeValue = v;
    }
    function doAttrs(el) {
        if (el.closest(SKIP)) return;
        for (const a of ATTR) {
            if (!el.hasAttribute(a)) continue;
            const key = 'gn' + a.replace(/-/g, ''); let o = el.dataset[key]; const cur = el.getAttribute(a);
            if (o === undefined || (cur !== o && cur !== el['__tr' + key])) { o = cur; el.dataset[key] = o; }
            const x = lang === 'de' ? o : tr(o) || o; el['__tr' + key] = x; if (cur !== x) el.setAttribute(a, x);
        }
    }
    let busy = false;
    function walk(root) {
        if (!root) return; busy = true;
        try {
            if (root.nodeType === 3) { doText(root); return; }
            if (root.nodeType !== 1 || (root.closest && root.closest(SKIP))) return;
            doAttrs(root);
            const w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT, { acceptNode: n => n.nodeType === 1 ? (n.matches(SKIP) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT) : (n.nodeValue.trim() ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP) });
            let n; while ((n = w.nextNode())) { if (n.nodeType === 3) doText(n); else doAttrs(n); }
        } finally { busy = false; }
    }
    const obs = new MutationObserver(list => {
        if (busy || lang === 'de') return; busy = true;
        try { for (const m of list) { if (m.type === 'characterData') doText(m.target); else if (m.type === 'attributes') doAttrs(m.target); else m.addedNodes.forEach(n => { busy = false; walk(n); busy = true; }); } }
        finally { busy = false; }
        obs.takeRecords();
    });
    let box = null;
    function corner() {
        if (document.body.hasAttribute('data-no-langswitch')) return;
        if (!box) {
            box = document.createElement('div'); box.className = 'gn-lang'; box.setAttribute('data-no-i18n', '');
            document.body.append(box);
            box.addEventListener('click', e => { const b = e.target.closest('button'); if (!b) return; if (b.dataset.l) { set(b.dataset.l); return; } box.classList.toggle('open'); });
            document.addEventListener('click', e => { if (box && !box.contains(e.target)) box.classList.remove('open'); });
        }
        box.innerHTML = '<button type="button" class="gn-lang-btn" aria-haspopup="true" title="Sprache / Language">' + LANGS[lang][2] + ' <span>' + lang.toUpperCase() + '</span></button><div class="gn-lang-menu" role="menu">'
            + Object.entries(LANGS).map(([k, v]) => '<button type="button" role="menuitem" data-l="' + k + '"' + (k === lang ? ' class="on"' : '') + '>' + v[2] + ' ' + v[0] + '</button>').join('') + '</div>';
    }
    function set(l) { if (!LANGS[l]) return; lang = l; try { localStorage.setItem('gnLang', l); } catch (e) { /* egal */ } document.documentElement.lang = l; walk(document.body); corner(); }
    function start() {
        const css = document.createElement('style');
        css.textContent = '.gn-lang{position:fixed;left:12px;bottom:12px;z-index:9000;font-size:.82rem}.gn-lang-btn{background:var(--panel-bg,#0b121b);color:var(--text-main,#e2e8f0);border:1px solid var(--border-color,#2a3a4a);padding:5px 9px;border-radius:16px;cursor:pointer}'
            + '.gn-lang-menu{display:none;position:absolute;bottom:34px;left:0;background:var(--panel-bg,#0b121b);border:1px solid var(--border-color,#2a3a4a);min-width:140px}.gn-lang.open .gn-lang-menu{display:block}'
            + '.gn-lang-menu button{display:block;width:100%;text-align:left;background:none;border:0;color:var(--text-main,#e2e8f0);padding:7px 10px;cursor:pointer}.gn-lang-menu button.on,.gn-lang-menu button:hover{background:rgba(56,213,230,.12)}';
        document.head.append(css);
        document.documentElement.lang = lang;
        walk(document.body);
        obs.observe(document.body, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ATTR });
        corner();
    }
    window.GnI18n = { get lang() { return lang; }, set, t: window.gnT, missing() { const out = new Set(); const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT); let n; while ((n = w.nextNode())) { const o = (ORIG.get(n) || n.nodeValue).trim(); if (o && n.parentElement && !n.parentElement.closest(SKIP) && /\p{L}/u.test(o) && tr(o) === null) out.add(o); } return [...out]; } };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
