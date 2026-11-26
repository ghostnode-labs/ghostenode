/* Fluffogotchi 1.0.0 – Bausteine aus dem GhostNode Furry Hub (automatisch erzeugt von tools/build-fluffogotchi.js – nicht von Hand ändern) */
(function () {
    'use strict';
    const FH = window.FH = { me: null, offline: true, enums: {"ears":["pointy","round","long","floppy","big","small","swept","none"],"muzzle":["long","medium","short","beak"],"marking":["none","mask","stripes","spots","blaze","cheeks","freckles","glow"],"extra":["none","horns","antlers","mane","fins","tuft","visor"],"acc":["none","glasses","bandana","bow","collar","headphones","flower","earring","scarf"],"eyeShape":["round","happy","sleepy","sharp"],"gender":["neutral","female","male","nonbinary"],"hair":["none","bangs","long","spiky","bun","curly","mohawk"],"pin":["none","rainbow","trans","nonbinary","bi","pan","lesbian","ace","gay"]} };
    // ---------- lokale Ersatzteile für Server-Funktionen ----------
    const store = {
        get(k, d) { try { const v = localStorage.getItem('fluffo-' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
        set(k, v) { try { localStorage.setItem('fluffo-' + k, JSON.stringify(v)); } catch (e) { /* privat oder voll */ } },
    };
    async function api(act) { if (act === 'pet') return { state: null }; return {}; }   // offline: alles bleibt im Browser
    const ACH = { boop1: ['👃', 'Erster Boop', 'Das erste Mal gebooped'], boop100: ['💯', 'Boop-Profi', '100 Boops'], boop1000: ['🏅', 'Boop-Legende', '1000 Boops'] };
    const achs = store.get('ach', {});
    function unlock(k) { if (achs[k]) return; achs[k] = Date.now(); store.set('ach', achs); if (ACH[k]) toast('🏆 Erfolg: ' + ACH[k][1] + ' – ' + ACH[k][2]); else toast('🏆 Erfolg freigeschaltet!'); }
    function react() { /* gemeinsame Zähler gibt es nur auf der Webseite */ }
    let myLocal = store.get('boops', 0);
    function boopCount() { myLocal++; store.set('boops', myLocal); if (myLocal >= 1) unlock('boop1'); if (myLocal >= 100) unlock('boop100'); if (myLocal >= 1000) unlock('boop1000'); return myLocal; }
    function uploadImageLocal(file) { return new Promise((res, rej) => { const r = new FileReader(); r.onload = () => res(r.result); r.onerror = rej; r.readAsDataURL(file); }); }

    const $ = (s, r = document) => r.querySelector(s);
    const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const rnd = a => a[Math.floor(Math.random() * a.length)];
    const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
    const hash = s => { let h = 2166136261; for (const c of String(s)) { h ^= c.codePointAt(0); h = Math.imul(h, 16777619); } return h >>> 0; };
    let toastT;
    function toast(msg, bad) {
        const t = $('#fhToast');
        t.textContent = msg; t.hidden = false; t.className = 'fh-toast' + (bad ? ' bad' : '');
        clearTimeout(toastT); toastT = setTimeout(() => { t.hidden = true; }, 3200);
    }
    const hex2hsl = hex => {
        let r = parseInt(hex.slice(1, 3), 16) / 255, g = parseInt(hex.slice(3, 5), 16) / 255, b = parseInt(hex.slice(5, 7), 16) / 255;
        const mx = Math.max(r, g, b), mn = Math.min(r, g, b); let h = 0, s = 0; const l = (mx + mn) / 2;
        if (mx !== mn) { const d = mx - mn; s = l > 0.5 ? d / (2 - mx - mn) : d / (mx + mn); h = mx === r ? (g - b) / d + (g < b ? 6 : 0) : mx === g ? (b - r) / d + 2 : (r - g) / d + 4; h *= 60; }
        return [h, s * 100, l * 100];
    };
    const hsl2hex = (h, s, l) => {
        s /= 100; l /= 100; const k = n => (n + h / 30) % 12, a = s * Math.min(l, 1 - l);
        const f = n => Math.round(255 * (l - a * Math.max(-1, Math.min(k(n) - 3, 9 - k(n), 1)))).toString(16).padStart(2, '0');
        return '#' + f(0) + f(8) + f(4);
    };
    const shade = (hex, dl) => { const [h, s, l] = hex2hsl(hex); return hsl2hex(h, s, clamp(l + dl, 0, 100)); };
    const jitter = (hex, dh = 14, ds = 10, dl = 8) => { const [h, s, l] = hex2hsl(hex); return hsl2hex((h + (Math.random() * 2 - 1) * dh + 360) % 360, clamp(s + (Math.random() * 2 - 1) * ds, 0, 100), clamp(l + (Math.random() * 2 - 1) * dl, 4, 96)); };
    const SPECIES = {
        fox:      ['🦊 Fuchs', { ears: 'pointy', muzzle: 'long', marking: 'none', extra: 'none', fluff: true, main: '#e8742a', second: '#3a2a22', muzzleC: '#fff4e6', inner: '#ffd2b0', eye: '#f5b014', nose: '#2a1a14', markC: '#ffffff' }],
        wolf:     ['🐺 Wolf', { ears: 'pointy', muzzle: 'long', marking: 'none', extra: 'none', fluff: true, main: '#8a8f99', second: '#5b606b', muzzleC: '#e6e6ea', inner: '#c9c3c8', eye: '#f2c14e', nose: '#1d1d22', markC: '#5b606b' }],
        husky:    ['🐕 Husky', { ears: 'pointy', muzzle: 'long', marking: 'blaze', extra: 'none', fluff: true, main: '#4b5563', second: '#2d3440', muzzleC: '#f3f4f6', inner: '#f3d2d8', eye: '#5ec8ff', nose: '#1d1d22', markC: '#f3f4f6' }],
        dog:      ['🐶 Hund', { ears: 'floppy', muzzle: 'medium', marking: 'none', extra: 'none', fluff: false, main: '#c58a4a', second: '#8a5a2b', muzzleC: '#f2dfc4', inner: '#e3a98a', eye: '#6b3f1d', nose: '#2a1a14', markC: '#8a5a2b' }],
        cat:      ['🐱 Katze', { ears: 'pointy', muzzle: 'short', marking: 'stripes', extra: 'none', fluff: false, main: '#f0a24f', second: '#c46d1e', muzzleC: '#fff1df', inner: '#ffc2c2', eye: '#7bd88f', nose: '#e8798f', markC: '#c46d1e' }],
        lion:     ['🦁 Löwe', { ears: 'round', muzzle: 'medium', marking: 'none', extra: 'mane', fluff: false, main: '#e0b265', second: '#9b5d1e', muzzleC: '#f7e3bd', inner: '#c98b4a', eye: '#d99a1e', nose: '#7a4a2a', markC: '#9b5d1e' }],
        tiger:    ['🐯 Tiger', { ears: 'round', muzzle: 'medium', marking: 'stripes', extra: 'none', fluff: true, main: '#f08a24', second: '#1f1f1f', muzzleC: '#ffffff', inner: '#ffffff', eye: '#e6c229', nose: '#e67a8a', markC: '#1f1f1f' }],
        rabbit:   ['🐰 Hase', { ears: 'long', muzzle: 'short', marking: 'none', extra: 'none', fluff: true, main: '#d9c3a5', second: '#b59b7b', muzzleC: '#fbf3e6', inner: '#ffb6c8', eye: '#6b4226', nose: '#ff8fa8', markC: '#b59b7b' }],
        novabeast: ['🌌 Novabeast', { ears: 'swept', muzzle: 'long', marking: 'glow', extra: 'tuft', fluff: true, main: '#2b2540', second: '#17122a', muzzleC: '#3f3760', inner: '#7cf3ff', eye: '#7cf3ff', nose: '#120e1e', markC: '#7cf3ff', eyeShape: 'sharp' }],
        redpanda: ['🐼 Roter Panda', { ears: 'round', muzzle: 'short', marking: 'cheeks', extra: 'none', fluff: true, main: '#c8502a', second: '#3a1d12', muzzleC: '#ffffff', inner: '#ffffff', eye: '#3b2314', nose: '#1d1414', markC: '#ffffff' }],
        raccoon:  ['🦝 Waschbär', { ears: 'round', muzzle: 'medium', marking: 'mask', extra: 'none', fluff: true, main: '#8d8d8d', second: '#4a4a4a', muzzleC: '#f0f0f0', inner: '#e8e8e8', eye: '#3b2a1a', nose: '#1d1d1d', markC: '#2b2b2b' }],
        otter:    ['🦦 Otter', { ears: 'small', muzzle: 'short', marking: 'none', extra: 'none', fluff: false, main: '#7a5034', second: '#5a3a24', muzzleC: '#d9b48f', inner: '#a77a58', eye: '#2b1b10', nose: '#2b1b10', markC: '#d9b48f' }],
        deer:     ['🦌 Hirsch', { ears: 'big', muzzle: 'medium', marking: 'spots', extra: 'antlers', fluff: false, main: '#a86b3c', second: '#6e4423', muzzleC: '#f3e1c9', inner: '#f3c9b0', eye: '#3b2314', nose: '#2b1b10', markC: '#f3e1c9' }],
        dragon:   ['🐉 Drache', { ears: 'none', muzzle: 'long', marking: 'freckles', extra: 'horns', fluff: false, main: '#4caf7a', second: '#2f6e4c', muzzleC: '#bfe8c8', inner: '#bfe8c8', eye: '#ffcf33', nose: '#2f6e4c', markC: '#2f6e4c', eyeShape: 'sharp' }],
        bat:      ['🦇 Fledermaus', { ears: 'big', muzzle: 'short', marking: 'none', extra: 'tuft', fluff: true, main: '#4a3b5c', second: '#2c2238', muzzleC: '#6d5a82', inner: '#c98bbd', eye: '#ff4d6d', nose: '#2c2238', markC: '#2c2238' }],
        hyena:    ['🐾 Hyäne', { ears: 'round', muzzle: 'long', marking: 'spots', extra: 'tuft', fluff: false, main: '#c9a46a', second: '#3b2f22', muzzleC: '#3b2f22', inner: '#8a6a44', eye: '#e0a32e', nose: '#1d1414', markC: '#5a4630' }],
        bird:     ['🐦 Vogel', { ears: 'none', muzzle: 'beak', marking: 'blaze', extra: 'tuft', fluff: true, main: '#4aa3e8', second: '#1f5f9a', muzzleC: '#ffb547', inner: '#ffffff', eye: '#1d1d22', nose: '#ffb547', markC: '#ffffff' }],
        protogen: ['🤖 Protogen', { ears: 'pointy', muzzle: 'long', marking: 'none', extra: 'visor', fluff: false, main: '#e9eef5', second: '#5aa9ff', muzzleC: '#d6dde8', inner: '#5aa9ff', eye: '#31e2ff', nose: '#22262e', markC: '#5aa9ff' }],
        mouse:    ['🐭 Maus', { ears: 'round', muzzle: 'short', marking: 'none', extra: 'none', fluff: false, main: '#b9b2ad', second: '#8e8681', muzzleC: '#eeeae7', inner: '#ffc2cf', eye: '#1d1d22', nose: '#ff8fa8', markC: '#8e8681' }],
        skunk:    ['🦨 Stinktier', { ears: 'small', muzzle: 'medium', marking: 'blaze', extra: 'tuft', fluff: true, main: '#26262b', second: '#ffffff', muzzleC: '#3a3a40', inner: '#5a5a62', eye: '#7c5cff', nose: '#111111', markC: '#ffffff' }],
    };
    const BGS = ['#1d3557', '#3a2e5c', '#264d3b', '#5c2e45', '#2e4a5c', '#4a3b1f', '#203040', '#40203a', '#1f3f3f', '#30254d'];
    const ACC_COLORS = ['#e63946', '#f3c262', '#4cc9f0', '#7bd88f', '#c77dff', '#ff8fab', '#ffffff', '#ff9f1c'];
    const LABELS = {
        ears: { pointy: 'Spitz', round: 'Rund', long: 'Lang (Hase)', floppy: 'Schlapp', big: 'Riesig', small: 'Klein', swept: 'Lang & zurückgelegt', none: 'Keine' },
        muzzle: { long: 'Lange Schnauze', medium: 'Mittel', short: 'Kurz (Katze)', beak: 'Schnabel' },
        marking: { none: 'Keine', mask: 'Maske', stripes: 'Streifen', spots: 'Flecken', blaze: 'Blesse', cheeks: 'Wangenflecken', freckles: 'Sommersprossen', glow: '✨ Leuchtlinien' },
        extra: { none: 'Nichts', horns: 'Hörner', antlers: 'Geweih', mane: 'Mähne', fins: 'Flossen/Kämme', tuft: 'Haarbüschel', visor: 'Visier' },
        acc: { none: 'Nichts', glasses: 'Brille', bandana: 'Halstuch', bow: 'Schleife', collar: 'Halsband', headphones: 'Kopfhörer', flower: 'Blume', earring: 'Ohrring', scarf: 'Schal' },
        eyeShape: { round: 'Rund', happy: 'Fröhlich', sleepy: 'Verschlafen', sharp: 'Scharf' },
        hair: { none: 'Keine Frisur', bangs: 'Pony', long: 'Lange Haare', spiky: 'Stachelig', bun: 'Dutt', curly: 'Locken', mohawk: 'Irokese' },
        pin: { none: 'Kein Anstecker', rainbow: '🏳️‍🌈 Regenbogen', trans: '🏳️‍⚧️ Trans', nonbinary: 'Nicht-binär', bi: 'Bi', pan: 'Pan', lesbian: 'Lesbisch', ace: 'Ace', gay: 'Gay (MLM)' },
    };
    const COLOR_LABELS = { bg: 'Hintergrund', main: 'Fell', second: 'Zweitfarbe', muzzleC: 'Schnauze', inner: 'Ohr innen', eye: 'Augen', nose: 'Nase', markC: 'Zeichnung', accC: 'Accessoire', hairC: 'Haare' };
    const GENDERS = { neutral: ['⚪', 'Keine Angabe', ''], female: ['♀', 'Weiblich', 'sie/ihr'], male: ['♂', 'Männlich', 'er/ihm'], nonbinary: ['⚧', 'Nicht-binär', 'they/them'] };
    const PRIDE = {
        rainbow: ['#e40303', '#ff8c00', '#ffed00', '#008026', '#004dff', '#750787'], trans: ['#5bcefa', '#f5a9b8', '#ffffff', '#f5a9b8', '#5bcefa'],
        nonbinary: ['#fcf434', '#ffffff', '#9c59d1', '#2c2c2c'], bi: ['#d60270', '#d60270', '#9b4f96', '#0038a8', '#0038a8'], pan: ['#ff218c', '#ffd800', '#21b1ff'],
        lesbian: ['#d52d00', '#ff9a56', '#ffffff', '#d362a4', '#a30262'], ace: ['#000000', '#a3a3a3', '#ffffff', '#800080'], gay: ['#078d70', '#26ceaa', '#98e8c1', '#ffffff', '#7bade2', '#5049cc', '#3d1a78'],
    };
    function defaultLook(sp = 'fox') {
        const p = SPECIES[sp][1];
        return Object.assign({ species: sp, eyeShape: 'round', acc: 'none', accC: '#4cc9f0', bg: '#1d3557', blush: true, gender: 'neutral', hair: 'none', hairC: p.second, pin: 'none' }, JSON.parse(JSON.stringify(p)));
    }
    let svgId = 0;
    /** Kopf einer Figur als SVG. expr: normal | happy | boop | sleep | sad */
    const EXMAP = { neutral: 'normal', surprised: 'boop', sleepy: 'sleep' };
    function sonaSVG(L, expr = 'normal') {
        expr = EXMAP[expr] || expr;
        L = Object.assign(defaultLook(), L || {});
        const id = 'fh' + (++svgId);
        const M = (d, fill, extra = '') => `<path d="${d}" fill="${fill}" ${extra}/>`;
        const mir = d => d.replace(/(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)/g, (m, x, y) => (200 - parseFloat(x)) + ' ' + y);   // spiegeln an x = 100
        const both = (d, fill, extra = '') => M(d, fill, extra) + M(mir(d), fill, extra);
        const ink = '#1a1420';
        const o = [];
        o.push(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" class="fh-sona">`);
        o.push(`<defs><clipPath id="${id}h"><ellipse cx="100" cy="112" rx="64" ry="58"/></clipPath><radialGradient id="${id}g" cx="50%" cy="35%" r="70%"><stop offset="0" stop-color="${shade(L.bg, 12)}"/><stop offset="1" stop-color="${L.bg}"/></radialGradient></defs>`);
        o.push(`<rect width="200" height="200" rx="28" fill="url(#${id}g)"/>`);
        // Körper
        o.push(M(L.gender === 'male' ? 'M 22 200 Q 34 154 100 152 Q 166 154 178 200 Z' : L.gender === 'female' ? 'M 46 200 Q 54 160 100 158 Q 146 160 154 200 Z' : 'M 34 200 Q 44 158 100 156 Q 156 158 166 200 Z', L.main));
        o.push(`<ellipse cx="100" cy="190" rx="28" ry="22" fill="${L.muzzleC}"/>`);
        // Mähne / Flossen hinter dem Kopf
        if (L.extra === 'mane') {
            let pts = []; for (let i = 0; i < 32; i++) { const a = i / 32 * Math.PI * 2, r = i % 2 ? 76 : 90; pts.push((100 + Math.cos(a) * r).toFixed(1) + ',' + (110 + Math.sin(a) * r * 0.92).toFixed(1)); }
            o.push(`<polygon points="${pts.join(' ')}" fill="${L.second}"/>`);
        }
        if (L.extra === 'fins') o.push(both('M 42 96 L 8 70 L 22 96 L 4 104 L 26 116 L 10 130 L 44 128 Z', L.second));
        // Ohren
        const ears = {
            pointy: [['M 50 92 L 46 14 L 94 60 Z', L.main], ['M 58 80 L 55 32 L 84 62 Z', L.inner]],
            big:    [['M 56 94 L 16 4 L 98 56 Z', L.main], ['M 60 84 L 30 22 L 86 58 Z', L.inner]],
            swept:  [['M 56 90 Q 34 58 4 18 Q 48 30 90 62 Z', L.main], ['M 60 80 Q 42 58 20 32 Q 50 42 80 64 Z', L.inner]],
            long:   [['M 66 70 C 50 40 50 0 68 -2 C 86 0 88 40 84 68 Z', L.main], ['M 70 62 C 60 40 60 10 69 8 C 78 10 80 40 79 62 Z', L.inner]],
        }[L.ears];
        if (ears) ears.forEach(([d, c]) => o.push(both(d, c)));
        const circ = (x, y, r, c) => `<circle cx="${x}" cy="${y}" r="${r}" fill="${c}"/><circle cx="${200 - x}" cy="${y}" r="${r}" fill="${c}"/>`;
        if (L.ears === 'round') o.push(circ(52, 58, 24, L.main) + circ(52, 58, 13, L.inner));
        if (L.ears === 'small') o.push(circ(50, 76, 14, L.main) + circ(50, 76, 7, L.inner));
        // Wangen-Fluff
        if (L.fluff) o.push(both('M 44 118 L 20 128 L 40 134 L 22 148 L 46 146 L 34 160 L 62 150 Z', L.main));
        const hc = L.hairC || L.second;
        if (L.hair === 'long') o.push(both('M 40 96 Q 22 150 38 199 L 74 199 Q 54 152 62 100 Z', hc));
        // Kopf
        o.push(`<ellipse cx="100" cy="112" rx="64" ry="58" fill="${L.main}"/>`);
        // Zeichnung (auf den Kopf beschnitten)
        const mk = [];
        const mc = L.markC;
        if (L.marking === 'mask') mk.push(M('M 30 104 Q 100 78 170 104 Q 156 128 122 118 Q 100 112 78 118 Q 44 128 30 104 Z', mc));
        if (L.marking === 'stripes') { mk.push(M('M 100 54 L 93 80 L 107 80 Z', mc)); mk.push(both('M 74 60 L 80 84 L 88 66 Z', mc)); mk.push(both('M 36 106 L 60 112 L 38 120 Z', mc)); mk.push(both('M 38 128 L 58 130 L 42 138 Z', mc)); }
        if (L.marking === 'spots') [[70, 70, 6], [130, 66, 5], [52, 96, 5], [150, 98, 6], [114, 58, 4], [86, 60, 3]].forEach(([x, y, r]) => mk.push(`<circle cx="${x}" cy="${y}" r="${r}" fill="${mc}"/>`));
        if (L.marking === 'blaze') mk.push(M('M 100 50 Q 86 92 100 132 Q 114 92 100 50 Z', mc));
        if (L.marking === 'cheeks') { mk.push(both('M 36 118 Q 58 96 82 118 Q 60 150 36 118 Z', mc)); mk.push(both('M 74 84 Q 82 76 90 84 Q 82 92 74 84 Z', mc)); }
        if (L.marking === 'glow') {
            const gl = `stroke="${mc}" fill="none" stroke-linecap="round" stroke-linejoin="round"`;
            mk.push(`<g style="filter:drop-shadow(0 0 3px ${mc})"><path d="M 82 62 L 100 78 L 118 62" stroke-width="4" ${gl}/><path d="M 92 54 L 100 62 L 108 54" stroke-width="3" ${gl}/>`
                + `<path d="M 38 116 L 60 112 L 52 124" stroke-width="3.5" ${gl}/><path d="M 162 116 L 140 112 L 148 124" stroke-width="3.5" ${gl}/>`
                + `<circle cx="74" cy="126" r="2.4" fill="${mc}"/><circle cx="126" cy="126" r="2.4" fill="${mc}"/></g>`);
        }
        if (L.marking === 'freckles') [[64, 128], [70, 134], [58, 134], [136, 128], [130, 134], [142, 134]].forEach(([x, y]) => mk.push(`<circle cx="${x}" cy="${y}" r="2.2" fill="${mc}"/>`));
        if (mk.length) o.push(`<g clip-path="url(#${id}h)">${mk.join('')}</g>`);
        const hairFront = {
            bangs: M('M 44 92 Q 48 50 100 48 Q 152 50 156 92 L 144 78 L 134 90 L 122 74 L 110 88 L 98 72 L 86 88 L 74 74 L 64 90 L 54 78 Z', hc),
            long: M('M 42 98 Q 46 50 104 48 Q 154 52 158 96 Q 130 64 96 70 Q 64 76 42 98 Z', hc),
            spiky: M('M 52 82 L 54 40 L 72 62 L 80 24 L 96 56 L 108 20 L 118 56 L 134 30 L 138 64 L 154 50 L 150 86 Q 100 60 52 82 Z', hc),
            bun: `<circle cx="100" cy="46" r="18" fill="${hc}"/><rect x="88" y="58" width="24" height="5" rx="2" fill="${shade(hc, -20)}"/>` + M('M 46 92 Q 52 54 100 54 Q 148 54 154 92 Q 128 70 100 70 Q 72 70 46 92 Z', hc),
            curly: [[56, 72, 13], [70, 60, 14], [86, 54, 14], [102, 52, 15], [118, 54, 14], [133, 60, 14], [146, 72, 13]].map(([x, y, r]) => `<circle cx="${x}" cy="${y}" r="${r}" fill="${hc}"/>`).join(''),
            mohawk: M('M 90 72 L 82 30 L 98 40 L 102 16 L 112 38 L 122 26 L 112 72 Z', hc),
        }[L.hair];
        if (hairFront) o.push(hairFront);
        // Schnauze & Nase
        const eyeY = 106;
        if (L.muzzle === 'long') o.push(`<ellipse cx="100" cy="140" rx="34" ry="26" fill="${L.muzzleC}"/>`);
        if (L.muzzle === 'medium') o.push(`<ellipse cx="100" cy="142" rx="29" ry="21" fill="${L.muzzleC}"/>`);
        if (L.muzzle === 'short') o.push(`<ellipse cx="89" cy="143" rx="15" ry="12" fill="${L.muzzleC}"/><ellipse cx="111" cy="143" rx="15" ry="12" fill="${L.muzzleC}"/>`);
        if (L.muzzle === 'beak') o.push(`<g class="fh-nose">${M('M 80 126 Q 100 118 120 126 L 100 158 Z', L.muzzleC)}${M('M 86 140 L 114 140 L 100 158 Z', shade(L.muzzleC, -12))}</g>`);
        else {
            const ny = L.muzzle === 'short' ? 132 : 128, s = L.muzzle === 'short' ? 0.75 : 1;
            o.push(`<g class="fh-nose" style="transform-origin:100px ${ny + 5}px"><path d="M ${100 - 11 * s} ${ny} Q 100 ${ny - 6 * s} ${100 + 11 * s} ${ny} Q ${100 + 5 * s} ${ny + 11 * s} 100 ${ny + 12 * s} Q ${100 - 5 * s} ${ny + 11 * s} ${100 - 11 * s} ${ny} Z" fill="${L.nose}"/><ellipse cx="${100 - 3 * s}" cy="${ny + 1}" rx="${3.2 * s}" ry="${1.8 * s}" fill="#fff" opacity=".45"/></g>`);
        }
        // Mund
        if (L.muzzle !== 'beak') {
            const my = L.muzzle === 'short' ? 147 : 148;
            if (expr === 'boop') o.push(`<ellipse cx="100" cy="${my + 3}" rx="6" ry="7" fill="${ink}"/><ellipse cx="100" cy="${my + 6}" rx="4" ry="3" fill="#ff7a9c"/>`);
            else if (expr === 'happy') o.push(`<path d="M 86 ${my - 2} Q 100 ${my + 16} 114 ${my - 2} Z" fill="${ink}"/><path d="M 93 ${my + 6} Q 100 ${my + 12} 107 ${my + 6} Q 100 ${my + 2} 93 ${my + 6} Z" fill="#ff7a9c"/>`);
            else if (expr === 'sad') o.push(`<path d="M 90 ${my + 6} Q 100 ${my - 3} 110 ${my + 6}" stroke="${ink}" stroke-width="2.6" fill="none" stroke-linecap="round"/>`);
            else if (expr === 'angry') o.push(`<path d="M 86 ${my + 7} Q 100 ${my - 4} 114 ${my + 7}" stroke="${ink}" stroke-width="3.4" fill="none" stroke-linecap="round"/><path d="M 93 ${my + 3} L 95 ${my + 7} L 97 ${my + 3}" fill="#fff"/>`);
            else o.push(`<path d="M 88 ${my} Q 94 ${my + 7} 100 ${my} Q 106 ${my + 7} 112 ${my}" stroke="${ink}" stroke-width="2.6" fill="none" stroke-linecap="round"/>`);
        }
        // Augen
        const eye = x => {
            const shape = expr === 'normal' || expr === 'sad' || expr === 'angry' ? L.eyeShape : expr;
            if (L.extra === 'visor') return '';
            if (shape === 'happy') return `<path d="M ${x - 12} ${eyeY + 4} Q ${x} ${eyeY - 10} ${x + 12} ${eyeY + 4}" stroke="${ink}" stroke-width="4" fill="none" stroke-linecap="round"/>`;
            if (shape === 'sleep') return `<path d="M ${x - 12} ${eyeY} Q ${x} ${eyeY + 8} ${x + 12} ${eyeY}" stroke="${ink}" stroke-width="4" fill="none" stroke-linecap="round"/>`;
            if (shape === 'boop') { const d = x < 100 ? 1 : -1; return `<path d="M ${x - 10 * d} ${eyeY - 8} L ${x + 8 * d} ${eyeY} L ${x - 10 * d} ${eyeY + 8}" stroke="${ink}" stroke-width="4" fill="none" stroke-linecap="round" stroke-linejoin="round"/>`; }
            let s = '';
            if (shape === 'sharp') {
                s += `<path d="M ${x - 15} ${eyeY + 2} Q ${x} ${eyeY - 14} ${x + 15} ${eyeY - 2} Q ${x} ${eyeY + 12} ${x - 15} ${eyeY + 2} Z" fill="#fff"/>`;
                s += `<ellipse cx="${x}" cy="${eyeY}" rx="8" ry="9" fill="${L.eye}"/><ellipse cx="${x}" cy="${eyeY}" rx="2.4" ry="8" fill="${ink}"/>`;
            } else {
                s += `<ellipse cx="${x}" cy="${eyeY}" rx="13" ry="15" fill="#fff"/><circle cx="${x}" cy="${eyeY + 1}" r="10" fill="${L.eye}"/><circle cx="${x}" cy="${eyeY + 1}" r="5.2" fill="${ink}"/>`;
            }
            s += `<circle cx="${x + 3.5}" cy="${eyeY - 4}" r="3.2" fill="#fff"/><circle cx="${x - 3}" cy="${eyeY + 5}" r="1.4" fill="#fff" opacity=".8"/>`;
            const d = x < 100 ? -1 : 1;   // außen
            if (L.gender === 'female' || L.gender === 'nonbinary') {
                const lashes = L.gender === 'female' ? [[11, -9, 18, -15], [13, -3, 21, -5], [6, -13, 9, -20]] : [[11, -9, 17, -14], [13, -3, 19, -5]];
                s += lashes.map(([x1, y1, x2, y2]) => `<path d="M ${x + d * x1} ${eyeY + y1} L ${x + d * x2} ${eyeY + y2}" stroke="${ink}" stroke-width="2.6" stroke-linecap="round"/>`).join('');
            }
            if (L.gender === 'male' && expr !== 'sad' && expr !== 'angry') s += `<path d="M ${x - 11} ${eyeY - 20} Q ${x} ${eyeY - 24} ${x + 11} ${eyeY - 20}" stroke="${shade(L.main, -40)}" stroke-width="4.5" fill="none" stroke-linecap="round"/>`;
            if (shape === 'sleepy') s += `<path d="M ${x - 15} ${eyeY - 16} L ${x + 15} ${eyeY - 16} L ${x + 15} ${eyeY + 1} Q ${x} ${eyeY - 4} ${x - 15} ${eyeY + 1} Z" fill="${L.main}"/>`;
            if (expr === 'sad') s += `<path d="M ${x - 12} ${eyeY - 18 + (x < 100 ? 4 : -2)} L ${x + 10} ${eyeY - 18 + (x < 100 ? -2 : 4)}" stroke="${ink}" stroke-width="3" stroke-linecap="round"/>`;
            if (expr === 'angry') s += x < 100 ? `<path d="M ${x - 14} ${eyeY - 22} L ${x + 11} ${eyeY - 12}" stroke="${ink}" stroke-width="4.5" stroke-linecap="round"/>` : `<path d="M ${x - 11} ${eyeY - 12} L ${x + 14} ${eyeY - 22}" stroke="${ink}" stroke-width="4.5" stroke-linecap="round"/>`;
            return s;
        };
        o.push(eye(74) + eye(126));
        // Visier (Protogen)
        if (L.extra === 'visor') {
            o.push(`<rect x="34" y="84" width="132" height="46" rx="23" fill="#0d1117"/>`);
            const g = L.eye, sw = 'stroke-width="5" stroke-linecap="round" fill="none"';
            const glyph = expr === 'happy' || expr === 'boop' ? (x => `<path d="M ${x - 12} 112 L ${x} 100 L ${x + 12} 112" stroke="${g}" ${sw}/>`)
                : expr === 'sleep' ? (x => `<path d="M ${x - 12} 106 L ${x + 12} 106" stroke="${g}" ${sw}/>`)
                : expr === 'sad' ? (x => `<path d="M ${x - 12} 100 L ${x} 112 L ${x + 12} 100" stroke="${g}" ${sw}/>`)
                : expr === 'angry' ? (x => `<path d="M ${x - 12} ${x < 100 ? 98 : 112} L ${x + 12} ${x < 100 ? 112 : 98}" stroke="${g}" ${sw}/>`)
                : (x => `<rect x="${x - 9}" y="96" width="18" height="18" rx="4" fill="${g}"/>`);
            o.push(`<g filter="drop-shadow(0 0 4px ${g})">${glyph(74)}${glyph(126)}${expr === 'boop' ? `<path d="M 92 122 L 100 117 L 108 122" stroke="${g}" stroke-width="3" fill="none"/>` : ''}</g>`);
        }
        // Wangen
        if (L.blush || expr === 'boop' || expr === 'happy') o.push(`<ellipse cx="58" cy="128" rx="11" ry="6" fill="#ff7aa2" opacity="${expr === 'boop' ? 0.7 : 0.4}"/><ellipse cx="142" cy="128" rx="11" ry="6" fill="#ff7aa2" opacity="${expr === 'boop' ? 0.7 : 0.4}"/>`);
        // Schlappohren vor dem Kopf
        if (L.ears === 'floppy') o.push(both('M 46 66 C 20 70 14 120 30 140 C 40 150 52 132 56 112 C 60 92 60 72 46 66 Z', L.second));
        // Hörner, Geweih, Büschel
        if (L.extra === 'horns') o.push(both('M 70 66 Q 56 34 38 20 Q 52 46 60 74 Z', shade(L.second, 18)));
        if (L.extra === 'antlers') o.push(both('M 74 62 L 62 30 L 48 14 M 62 30 L 74 14 M 66 42 L 44 38', 'none', `stroke="${shade(L.second, 25)}" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"`));
        if (L.extra === 'tuft') o.push(M('M 84 58 Q 90 36 100 30 Q 98 44 104 50 Q 112 34 122 32 Q 116 46 116 58 Z', L.second));
        // Accessoires
        const a = L.accC;
        if (L.acc === 'glasses') o.push(`<g fill="rgba(255,255,255,.12)" stroke="${a}" stroke-width="4"><circle cx="74" cy="${eyeY}" r="17"/><circle cx="126" cy="${eyeY}" r="17"/></g><path d="M 91 ${eyeY - 2} Q 100 ${eyeY - 8} 109 ${eyeY - 2}" stroke="${a}" stroke-width="4" fill="none"/>`);
        if (L.acc === 'bandana') o.push(M('M 46 162 Q 100 176 154 162 L 100 200 Z', a) + `<path d="M 60 170 L 140 170" stroke="${shade(a, -15)}" stroke-width="2" stroke-dasharray="4 5"/>`);
        if (L.acc === 'collar') o.push(`<rect x="54" y="160" width="92" height="12" rx="6" fill="${a}"/><circle cx="100" cy="180" r="8" fill="#f3c262" stroke="#b8862a" stroke-width="2"/>`);
        if (L.acc === 'scarf') o.push(M('M 44 160 Q 100 182 156 160 L 158 176 Q 100 196 42 176 Z', a) + M('M 120 176 L 136 200 L 150 200 L 136 172 Z', shade(a, -12)));
        if (L.acc === 'bow') o.push(`<g transform="translate(140 58) rotate(15)">${M('M 0 0 L -18 -12 L -18 12 Z', a)}${M('M 0 0 L 18 -12 L 18 12 Z', a)}<circle r="5" fill="${shade(a, -15)}"/></g>`);
        if (L.acc === 'headphones') o.push(`<path d="M 38 108 Q 40 34 100 32 Q 160 34 162 108" stroke="${a}" stroke-width="9" fill="none"/><rect x="26" y="96" width="20" height="34" rx="9" fill="${shade(a, -10)}"/><rect x="154" y="96" width="20" height="34" rx="9" fill="${shade(a, -10)}"/>`);
        if (L.acc === 'flower') o.push(`<g transform="translate(62 64)">${[0, 72, 144, 216, 288].map(d => `<ellipse rx="6" ry="10" cy="-9" fill="${a}" transform="rotate(${d})"/>`).join('')}<circle r="5" fill="#ffd166"/></g>`);
        if (L.acc === 'earring') o.push(`<circle cx="150" cy="78" r="6" fill="none" stroke="#f3c262" stroke-width="3"/><circle cx="150" cy="86" r="3" fill="${a}"/>`);
        if (L.pin && PRIDE[L.pin]) {
            const st = PRIDE[L.pin], h = 22 / st.length;
            o.push(`<clipPath id="${id}p"><circle cx="134" cy="184" r="11"/></clipPath><g clip-path="url(#${id}p)">${st.map((c, i) => `<rect x="123" y="${(173 + i * h).toFixed(2)}" width="22" height="${(h + 0.4).toFixed(2)}" fill="${c}"/>`).join('')}</g><circle cx="134" cy="184" r="11" fill="none" stroke="#f3c262" stroke-width="2"/>`);
        }
        o.push('</svg>');
        return o.join('');
    }
    const EMO = { neutral: ['😐', 'Normal'], happy: ['😊', 'Fröhlich'], sad: ['🥺', 'Traurig'], angry: ['😠', 'Wütend'], surprised: ['😳', 'Überrascht'], sleepy: ['😴', 'Müde'] };
    const safeImg = (u, allowData = true) => (typeof u === 'string' && (/^furhub\.php\?img=[0-9a-f]{24}$/.test(u) || (allowData && /^data:image\/(png|jpeg|webp|gif);base64,[A-Za-z0-9+/=]+$/.test(u)))) ? u : '';
    function imgFor(look, emo) {
        if (!look || look.mode !== 'custom') return '';
        const im = look.images && !Array.isArray(look.images) ? look.images : {};
        return safeImg(im[emo]) || safeImg(im.neutral) || safeImg(look.mainImg) || Object.values(im).map(u => safeImg(u)).find(Boolean) || '';
    }
    /** Großes Bild einer Sona: Hauptbild, sonst Emotionsbild, sonst Avatar */
    function hero(look, emo = 'neutral') {
        if (emo === 'neutral-emo') { const u = imgFor(look, 'neutral'); return u ? `<img class="fh-hero-img" src="${esc(u)}" alt="" draggable="false">` : sonaSVG(look, 'neutral'); }
        const u = look && look.mode === 'custom' ? (emo === 'neutral' ? safeImg(look.mainImg) || imgFor(look, emo) : imgFor(look, emo)) : '';
        return u ? `<img class="fh-hero-img" src="${esc(u)}" alt="" draggable="false">` : sonaSVG(look, emo);
    }
    /** Gespeichertes Profilbild (Avatar) als <img> */
    const avatar = (look, cls = '') => `<span class="fh-av ${cls}">${sonaSVG(look || defaultLook('fox'))}</span>`;
    /** Gesicht einer Figur: eigenes Bild der Emotion oder gezeichneter Avatar */
    function face(look, emo = 'neutral') {
        const u = imgFor(look, emo);
        return u ? `<img class="fh-face-img" src="${esc(u)}" alt="" draggable="false">` : sonaSVG(look, emo);
    }
    const blobToData = b => new Promise(res => { const r = new FileReader(); r.onload = () => res(r.result); r.readAsDataURL(b); });
    function emoSlots(box, getImages, kind, onChange) {
        const input = document.createElement('input');
        input.type = 'file'; input.accept = 'image/png,image/jpeg,image/webp,image/gif'; input.hidden = true;
        box.after(input);
        const render = () => {
            const im = getImages();
            box.innerHTML = Object.entries(EMO).map(([e, [ic, l]]) => `<div class="emo-slot ${safeImg(im[e]) ? 'has' : ''}" data-e="${e}"><button type="button" class="emo-pic" title="${l}: Bild wählen">${safeImg(im[e]) ? `<img src="${esc(im[e])}" alt="">` : `<span>${ic}</span>`}</button><small>${ic} ${l}</small>${safeImg(im[e]) ? `<button type="button" class="emo-x" data-x="${e}" title="Entfernen">×</button>` : ''}</div>`).join('')
                + `<div class="emo-slot all"><button type="button" class="emo-pic" data-all title="Ein Bild für alle Emotionen"><span>⬆</span></button><small>Eins für alle</small></div>`;
        };
        const take = async (file, targets) => {
            box.classList.add('busy');
            try {
                const url = await uploadImage(file, kind);
                const im = getImages(); targets.forEach(e => { im[e] = url; });
                onChange(); render(); unlock('custom');
            } catch (err) { toast(err.message, true); } finally { box.classList.remove('busy'); }
        };
        box.addEventListener('click', e => {
            const x = e.target.closest('[data-x]');
            if (x) { delete getImages()[x.dataset.x]; onChange(); render(); return; }
            const all = e.target.closest('[data-all]'), slot = e.target.closest('.emo-slot:not(.all)');
            if (all || slot) { input.dataset.slotTarget = all ? 'all' : slot.dataset.e; input.value = ''; input.click(); }
        });
        input.addEventListener('change', () => { if (input.files[0]) take(input.files[0], input.dataset.slotTarget === 'all' ? Object.keys(EMO) : [input.dataset.slotTarget]); });
        box.addEventListener('dragover', e => { e.preventDefault(); });
        box.addEventListener('drop', e => {
            e.preventDefault();
            const slot = e.target.closest('.emo-slot'), f = e.dataTransfer.files[0];
            if (slot && f) take(f, slot.classList.contains('all') ? Object.keys(EMO) : [slot.dataset.e]);
        });
        render();
        return { render };
    }
    function confetti(n = 40, chars = ['🐾', '✨', '💖', '⭐', '🦊', '🐺']) {
        for (let i = 0; i < n; i++) {
            const s = document.createElement('span');
            s.className = 'fh-confetti'; s.textContent = rnd(chars);
            s.style.left = Math.random() * 100 + 'vw';
            s.style.animationDuration = 2 + Math.random() * 2.5 + 's';
            s.style.animationDelay = Math.random() * 0.8 + 's';
            s.style.fontSize = 14 + Math.random() * 18 + 'px';
            document.body.append(s);
            setTimeout(() => s.remove(), 5500);
        }
    }
    function floatText(x, y, txt, cls = '') {
        const s = document.createElement('span');
        s.className = 'fh-float ' + cls; s.textContent = txt;
        s.style.left = x + 'px'; s.style.top = y + 'px';
        document.body.append(s);
        setTimeout(() => s.remove(), 1100);
    }
    const NAME_A = ['Ka', 'Ri', 'Zu', 'Fen', 'Mi', 'Lo', 'Sha', 'Ty', 'Ko', 'Ra', 'Ve', 'Nyx', 'Ash', 'Em', 'Ju', 'Ki', 'Lu', 'Mo', 'No', 'Pi', 'Sa', 'Ta', 'Wi', 'Yo', 'Ze', 'Ari', 'Bri', 'Cy', 'Dra', 'Eli'];
    const NAME_B = ['ro', 'ka', 'rin', 'li', 'ne', 'xa', 'zu', 'mi', 'ra', 'ko', 'vy', 'shi', 'ndo', 'lyn', 'ber', 'tas', 'nix', 'mo', 'ri', 'sk', 'ven', 'dra', 'lo', 'ki'];
    const NAME_WORDS = ['Ember', 'Frost', 'Luna', 'Nova', 'Shadow', 'Sky', 'Storm', 'Mocha', 'Pixel', 'Rune', 'Vex', 'Zephyr', 'Juniper', 'Toffee', 'Cinder', 'Maple', 'Orbit', 'Nebula', 'Kiwi', 'Biscuit', 'Echo', 'Sable', 'Clover', 'Glitch', 'Saffron'];
    const TITLES = ['aus dem Nebelwald', 'vom Mondsee', 'der Sternwarte', 'aus dem Kaffeeduft', 'vom Nordlicht', 'der Pixelwälder', 'aus dem Glitzerbau', 'vom Funkturm', 'aus dem Mitternachts-Café', 'der Wolkenberge'];
    const genName = () => Math.random() < 0.45 ? rnd(NAME_WORDS) + (Math.random() < 0.3 ? ' ' + rnd(TITLES) : '') : rnd(NAME_A) + rnd(NAME_B) + (Math.random() < 0.25 ? ' ' + rnd(TITLES) : '');
    function randomLook(sp) {
        sp = sp || rnd(Object.keys(SPECIES));
        const L = defaultLook(sp);
        ['main', 'second', 'muzzleC', 'eye', 'markC'].forEach(k => { L[k] = jitter(L[k], k === 'eye' ? 40 : 14); });
        if (Math.random() < 0.2) L.main = hsl2hex(Math.random() * 360, 40 + Math.random() * 45, 40 + Math.random() * 25);   // Fantasiefarbe
        L.bg = rnd(BGS);
        L.acc = Math.random() < 0.55 ? rnd(FH.enums.acc.slice(1)) : 'none';
        L.accC = rnd(ACC_COLORS);
        L.eyeShape = SPECIES[sp][1].eyeShape || (Math.random() < 0.75 ? 'round' : rnd(['sharp', 'sleepy']));
        L.blush = Math.random() < 0.6;
        L.gender = rnd(Object.keys(GENDERS));
        L.hair = Math.random() < 0.5 ? 'none' : rnd(FH.enums.hair.slice(1));
        L.hairC = Math.random() < 0.5 ? L.second : hsl2hex(Math.random() * 360, 50 + Math.random() * 40, 35 + Math.random() * 35);
        L.pin = Math.random() < 0.15 ? rnd(FH.enums.pin.slice(1)) : 'none';
        return L;
    }
    function download(blob, name) {
        const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name; document.body.append(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 2000);
    }
    let AC = null, master = null;
    const ac = () => {
        if (!AC) { AC = new (window.AudioContext || window.webkitAudioContext)(); master = AC.createGain(); master.connect(AC.destination); }
        if (AC.state === 'suspended') AC.resume();
        master.gain.value = (store.get('vol', 60) / 100) * 0.9;
        return AC;
    };
    const env = (g, t, a, peak, d) => { g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(peak, t + a); g.gain.exponentialRampToValueAtTime(0.0001, t + a + d); };
    function osc(type, f0, curve, dur, peak = 0.3, filter) {
        const c = ac(), t = c.currentTime, o = c.createOscillator(), g = c.createGain();
        o.type = type; o.frequency.setValueAtTime(f0, t);
        curve.forEach(([dt, f]) => o.frequency.exponentialRampToValueAtTime(f, t + dt));
        let node = o;
        if (filter) { const fl = c.createBiquadFilter(); fl.type = filter[0]; fl.frequency.value = filter[1]; fl.Q.value = filter[2] || 1; o.connect(fl); node = fl; }
        node.connect(g); g.connect(master); env(g, t, Math.min(0.08, dur / 4), peak, dur); o.start(t); o.stop(t + dur + 0.2);
        return o;
    }
    function noise(dur, filter, peak = 0.3, lfo) {
        const c = ac(), t = c.currentTime, buf = c.createBuffer(1, c.sampleRate * dur, c.sampleRate), d = buf.getChannelData(0);
        for (let i = 0; i < d.length; i++) d[i] = Math.random() * 2 - 1;
        const src = c.createBufferSource(), fl = c.createBiquadFilter(), g = c.createGain();
        src.buffer = buf; fl.type = filter[0]; fl.frequency.value = filter[1]; fl.Q.value = filter[2] || 0.7;
        src.connect(fl); fl.connect(g); g.connect(master); env(g, t, 0.05, peak, dur - 0.05);
        if (lfo) { const l = c.createOscillator(), lg = c.createGain(); l.frequency.value = lfo; lg.gain.value = peak * 0.9; l.connect(lg); lg.connect(g.gain); l.start(t); l.stop(t + dur); }
        src.start(t);
    }
    const sfx = {
        awoo() { const o = osc('sawtooth', 280, [[0.35, 520], [1.1, 470], [1.6, 330]], 1.6, 0.22, ['lowpass', 1400, 2]); const c = ac(), v = c.createOscillator(), vg = c.createGain(); v.frequency.value = 5.5; vg.gain.value = 9; v.connect(vg); vg.connect(o.frequency); v.start(); v.stop(c.currentTime + 1.7); },
        bark() { [0, 0.22].forEach(d => setTimeout(() => { osc('square', 260, [[0.12, 150]], 0.14, 0.25, ['lowpass', 1200]); noise(0.12, ['bandpass', 900, 1], 0.2); }, d * 1000)); },
        meow() { osc('sawtooth', 480, [[0.18, 820], [0.6, 420]], 0.65, 0.2, ['bandpass', 1100, 3]); },
        purr() { noise(1.4, ['lowpass', 220, 1], 0.5, 24); },
        chirp() { osc('sine', 700, [[0.05, 1300], [0.1, 900], [0.15, 1500]], 0.18, 0.2); },
        mrrp() { osc('triangle', 380, [[0.08, 620], [0.2, 560], [0.32, 760]], 0.35, 0.25, ['lowpass', 1800]); },
        hiss() { noise(0.9, ['highpass', 3000, 0.5], 0.25); },
        squeak() { [0, 0.15].forEach(d => setTimeout(() => osc('sine', 1300, [[0.08, 2100]], 0.1, 0.18), d * 1000)); },
        yip() { osc('square', 900, [[0.07, 1500], [0.12, 1000]], 0.13, 0.15, ['lowpass', 2600]); },
        roar() { osc('sawtooth', 110, [[0.4, 80], [1.2, 60]], 1.2, 0.3, ['lowpass', 600, 4]); noise(1.2, ['lowpass', 700, 1], 0.3); },
        boop() { osc('sine', 620, [[0.07, 980]], 0.09, 0.15); },
        hoot() { [0, 0.45].forEach(d => setTimeout(() => osc('sine', 420, [[0.15, 380]], 0.3, 0.25), d * 1000)); },
    };
    const SOUNDS = [['awoo', '🐺', 'Awoo'], ['bark', '🐶', 'Wuff'], ['meow', '🐱', 'Miau'], ['purr', '😺', 'Schnurren'], ['mrrp', '🐈', 'Mrrp'], ['chirp', '🐦', 'Zwitschern'],
                    ['yip', '🦊', 'Fuchs-Yip'], ['hiss', '🐍', 'Fauchen'], ['squeak', '🐭', 'Quietschen'], ['roar', '🐉', 'Brüllen'], ['hoot', '🦉', 'Huhu'], ['boop', '👃', 'Boop']];
    async function uploadImage(file, kind) {
        const blob = await prepImage(file, kind === 'gallery' ? (FH.me ? 1600 : 520) : (FH.me ? 768 : 320));
        return FH.me ? uploadBlob(blob, kind) : blobToData(blob);
    }
    /** Daten-URLs (aus der Zeit vor dem Anmelden) auf den Server bringen */
    async function ensureServerImages(images, kind) {
        if (!FH.me || !images) return images;
        for (const k of Object.keys(images)) if (String(images[k]).startsWith('data:')) images[k] = await uploadBlob(await (await fetch(images[k])).blob(), kind);
        return images;
    }
    /** Sechs Felder (je Emotion) zum Hochladen – für Fursona und Tamagotchi */

    window.FHK = { FH, $, $$, esc, rnd, clamp, store, hash, api, toast, confetti, floatText, download, unlock, react, boopCount, sfx, SOUNDS,
        sonaSVG, face, hero, avatar, EMO, emoSlots, randomLook, defaultLook, SPECIES, genName, jitter, shade, safeImg, imgFor,
        uploadImage: typeof uploadImage === 'function' ? uploadImage : uploadImageLocal, ensureServerImages: typeof ensureServerImages === 'function' ? ensureServerImages : async x => x,
        currentLook: () => null, showTab: () => {}, INIT: {}, SHOW: {}, renderMine: () => {}, renderTop: () => {}, loadSonas: async () => [] };
})();
