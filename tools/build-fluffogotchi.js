#!/usr/bin/env node
'use strict';
/*
 * Baut „Fluffogotchi“ – das Tamagotchi aus dem Furry Hub – als eigenständiges Offline-Spiel (ohne Server, Spielstände im Browser).
 *   node tools/build-fluffogotchi.js            → games/fluffogotchi/  (index.html, Spiel-Dateien)
 * Die Spiel-Logik (assets/js/furhub-tama.js) und die Bausteine (Fursona-Grafik, Töne, Konfetti …) kommen 1:1 aus dem Furry Hub,
 * damit beide Versionen gleich bleiben. Nur die Server-Teile (Konto, gemeinsame Boops) werden durch lokale Ersatzteile ersetzt.
 */
const fs = require('fs');
const path = require('path');
const ROOT = path.join(__dirname, '..');
const OUT = path.join(ROOT, 'games', 'fluffogotchi');
const VERSION = require(path.join(OUT, 'version.json')).version;
// Auswahl-Listen der Fursona (Ohren, Frisuren …) – kommen auf der Webseite vom Server, hier fest eingebaut
const ENUMS = JSON.parse(require('child_process').execFileSync('php', ['-r', 'define("FH_BUILD_ONLY", true); $s = file_get_contents($argv[1]); if (!preg_match("/const FH_LOOK_ENUMS\\s*=\\s*(\\[.*?\\]);\\n/s", $s, $m)) exit(1); echo json_encode(eval("return " . $m[1] . ";"), JSON_UNESCAPED_UNICODE);', path.join(ROOT, 'inc/modules/furhub.php')]).toString());

const src = fs.readFileSync(path.join(ROOT, 'assets/js/furhub.js'), 'utf8').split('\n');
// Blöcke auf oberster Ebene (4 Leerzeichen eingerückt) nach Namen
const START = /^    (?:const|let|function|async function) +(?:\[[^\]]*\]|\{[^}]*\}|([A-Za-z_$][\w$]*))/;
const blocks = [];
for (let i = 0; i < src.length; i++) {
    const m = START.exec(src[i]);
    if (m) blocks.push({ name: m[1] || '', start: i });
}
for (let i = 0; i < blocks.length; i++) blocks[i].end = i + 1 < blocks.length ? blocks[i + 1].start : src.length;
const take = name => {
    const b = blocks.find(x => x.name === name);
    if (!b) throw new Error('Baustein „' + name + '“ nicht in furhub.js gefunden');
    let lines = src.slice(b.start, b.end);
    // nur bis zum Ende des Blocks (Leerzeilen/Kommentare des nächsten Abschnitts weglassen)
    while (lines.length && /^\s*(\/\/.*)?$/.test(lines[lines.length - 1])) lines.pop();
    return lines.join('\n');
};
const NEED = ['$', '$$', 'esc', 'rnd', 'clamp', 'hash', 'toastT', 'toast', 'hex2hsl', 'hsl2hex', 'shade', 'jitter', 'SPECIES', 'BGS', 'ACC_COLORS', 'LABELS', 'COLOR_LABELS', 'GENDERS', 'PRIDE',
    'defaultLook', 'svgId', 'EXMAP', 'sonaSVG', 'EMO', 'safeImg', 'imgFor', 'hero', 'avatar', 'face', 'blobToData', 'emoSlots', 'confetti', 'floatText',
    'NAME_A', 'NAME_B', 'NAME_WORDS', 'TITLES', 'genName', 'randomLook', 'download', 'AC', 'ac', 'env', 'osc', 'noise', 'sfx', 'SOUNDS'];
const missing = [];
const parts = NEED.map(n => { try { return take(n); } catch (e) { missing.push(n); return ''; } });
// Bausteine, die emoSlots u. a. brauchen, falls vorhanden
for (const opt of ['uploadImage', 'ensureServerImages', 'shrinkImage', 'resizeImage', 'toWebp']) { try { parts.push(take(opt)); } catch (e) { /* nicht nötig */ } }
if (missing.length) console.warn('⚠ nicht gefunden (Ersatz unten):', missing.join(', '));

const core = `/* Fluffogotchi ${VERSION} – Bausteine aus dem GhostNode Furry Hub (automatisch erzeugt von tools/build-fluffogotchi.js – nicht von Hand ändern) */
(function () {
    'use strict';
    const FH = window.FH = { me: null, offline: true, enums: ${JSON.stringify(ENUMS)} };
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

${parts.join('\n')}

    window.FHK = { FH, $, $$, esc, rnd, clamp, store, hash, api, toast, confetti, floatText, download, unlock, react, boopCount, sfx, SOUNDS,
        sonaSVG, face, hero, avatar, EMO, emoSlots, randomLook, defaultLook, SPECIES, genName, jitter, shade, safeImg, imgFor,
        uploadImage: typeof uploadImage === 'function' ? uploadImage : uploadImageLocal, ensureServerImages: typeof ensureServerImages === 'function' ? ensureServerImages : async x => x,
        currentLook: () => null, showTab: () => {}, INIT: {}, SHOW: {}, renderMine: () => {}, renderTop: () => {}, loadSonas: async () => [] };
})();
`;
fs.writeFileSync(path.join(OUT, 'fluffo-core.js'), core);
fs.copyFileSync(path.join(ROOT, 'assets/js/furhub-tama.js'), path.join(OUT, 'fluffo-game.js'));
fs.copyFileSync(path.join(ROOT, 'assets/css/hud.css'), path.join(OUT, 'hud.css'));
fs.copyFileSync(path.join(ROOT, 'assets/css/format.css'), path.join(OUT, 'format.css'));
fs.copyFileSync(path.join(ROOT, 'assets/css/furhub.css'), path.join(OUT, 'furhub.css'));
console.log('✔ Fluffogotchi ' + VERSION + ' gebaut → games/fluffogotchi/');
