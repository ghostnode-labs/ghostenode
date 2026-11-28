// End-to-End-Test VRChat-Poster gegen eine Testkopie (php -S 127.0.0.1:8910, PS_ALLOW_LOCAL=1 PS_FTP_NOW=1). Nur Testdaten.
const { chromium } = require('playwright');
const fs = require('fs');
const assert = require('assert');
const B = 'http://127.0.0.1:8910/';
const PW = 'Test-Passwort-123!';
const os = require('os'), path = require('path');
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'poster-test-'));
// Testbilder selbst erzeugen (PHP GD)
require('child_process').execFileSync('php', ['-r', 'foreach ([["t1",255,80,80,640,320],["t2",80,200,255,512,512],["t3",80,255,120,300,200]] as [$n,$r,$g,$b,$w,$h]) { $im = imagecreatetruecolor($w, $h); imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b)); imagestring($im, 5, 10, 10, $n, imagecolorallocate($im, 255, 255, 255)); imagepng($im, $argv[1] . "/$n.png"); }', TMP]);
const IMG = n => fs.readFileSync(path.join(TMP, n + '.png'));
const ROOT = process.argv[2] || path.join(__dirname, '..', '..');
fs.copyFileSync(path.join(TMP, 't3.png'), path.join(ROOT, 'assets', 'poster-test-t3.png'));
let fails = 0, oks = 0;
const step = async (name, fn) => { try { await fn(); oks++; console.log('✔', name); } catch (e) { fails++; console.log('✖', name, '\n   ', e.message); } };

(async () => {
    const b = await chromium.launch();
    const ctx = {};
    async function user(name) {
        if (ctx[name]) return ctx[name];
        const c = await b.newContext({ viewport: { width: 1280, height: 900 } });
        const p = await c.newPage();
        if (name !== 'guest') {
            await p.goto(B + 'admin.php');
            await p.fill('input[name=username]', name); await p.fill('input[name=password]', PW);
            await Promise.all([p.waitForNavigation(), p.click('button[name=login], button[type=submit]')]);
        }
        await p.goto(B + 'poster.php');
        const api = async (act, data, files) => {
            let multipart = Object.assign({}, data || {});
            if (files) { const fd = new FormData(); Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v)); files.forEach(f => fd.append('files[]', new Blob([IMG(f)], { type: 'image/png' }), f + '.png')); multipart = fd; }
            const r = (act === 'list' || act === 'slot') && !files ? await p.request.get(B + 'poster.php?api=' + act + (data && data.id ? '&id=' + data.id : ''))
                : await p.request.post(B + 'poster.php?api=' + act, { multipart, headers: { Origin: 'http://127.0.0.1:8910' } });
            const d = await r.json(); if (d.error) { const e = new Error(d.error); e.status = r.status(); throw e; } return d;
        };
        return (ctx[name] = { c, p, api });
    }
    const rejects = async (pr, re) => { let err = null; try { await pr; } catch (e) { err = e; } assert.ok(err, 'sollte abgelehnt werden'); if (re) assert.match(err.message, re); };
    let slot, slug;

    await step('Gast sieht anfangs nichts, darf nichts anlegen', async () => {
        const g = await user('guest');
        assert.strictEqual((await g.api('list')).slots.length, 0);
        await rejects(g.api('create', { title: 'x' }), /anmelden/i);
    });
    await step('Ohne Poster-Rechte: kein Slot anlegen', async () => { await rejects((await user('nobody')).api('create', { title: 'x' }), /Recht/); });
    await step('Uploader legt Slot an und lädt 2 Bilder hoch (Duplikat erkannt)', async () => {
        const u = await user('posteruploader');
        slot = (await u.api('create', { title: 'Club Eingang', folder: 'Testwelt', visibility: 'users' })).slot;
        slug = slot.slug;
        assert.ok(/^[a-z0-9]/.test(slug) && slot.url.includes('/p.php/' + slug + '.png'));
        const ph = await (await user('guest')).p.request.get(B + 'p.php/' + slug + '.png');
        assert.strictEqual(ph.status(), 200, 'leerer Slot: Platzhalter statt 404'); assert.strictEqual(ph.headers()['x-poster-state'], 'empty');
        const r = await u.api('upload', { id: slot.id, activate: '1' }, ['t1', 't2', 't1']);
        assert.strictEqual(r.added, 2); assert.strictEqual(r.dupes, 1);
        const d = await u.api('slot', { id: slot.id });
        assert.strictEqual(d.images.length, 2);
        assert.strictEqual(d.slot.level, 'upload');
    });
    await step('Öffentliche Poster-URL liefert das erste (aktive) Bild', async () => {
        const r = await (await user('guest')).p.request.get(B + 'p.php/' + slug + '.png');
        assert.strictEqual(r.status(), 200); assert.strictEqual(r.headers()['content-type'], 'image/png');
        assert.strictEqual(r.headers()['access-control-allow-origin'], '*');
        const img = await r.body(); assert.ok(img.length > 100);
        const r2 = await (await user('guest')).p.request.get(B + 'p.php?s=' + slug + '&i=1'); assert.strictEqual(r2.status(), 200);
        assert.strictEqual((await (await user('guest')).p.request.get(B + 'p.php/gibtsnicht.png')).status(), 404);
    });
    await step('Uploader ohne Bearbeiten-Recht darf Einstellungen nicht ändern', async () => {
        await rejects((await user('posteruploader')).api('save', { id: slot.id, title: 'neu' }), /Recht/);
    });
    await step('Viewer sieht den Slot (für Angemeldete), darf aber nicht hochladen', async () => {
        const v = await user('viewer');
        const l = await v.api('list');
        assert.strictEqual(l.slots.length, 1); assert.strictEqual(l.slots[0].level, 'view');
        await rejects(v.api('upload', { id: slot.id }, ['t1']), /Recht/);
    });
    await step('Ohne Poster-Recht sieht man den Slot nicht; Gast auch nicht', async () => {
        assert.strictEqual((await (await user('nobody')).api('list')).slots.length, 0);
        assert.strictEqual((await (await user('guest')).api('list')).slots.length, 0);
    });
    await step('Editor ohne Freigabe: kein Zugriff zum Bearbeiten; mit Freigabe (vom Admin) schon', async () => {
        const e = await user('postereditor');
        await rejects(e.api('save', { id: slot.id, title: 'x' }), /Recht/);
        const a = await user('posteradmin');
        const users = (await a.api('list')).users;
        const ed = users.find(x => /PosterEditor/.test(x.name));
        await a.api('acl_set', { id: slot.id, user: ed.id, level: 'edit' });
        const s = await e.api('save', { id: slot.id, title: 'Club Eingang links', mode: 'fixed', fit: 'contain', tw: '1024', th: '512', bg: '#112233' });
        assert.strictEqual(s.slot.title, 'Club Eingang links');
    });
    await step('Aktives Bild wechseln → Poster-URL liefert anderes Bild in Zielgröße 1024×512', async () => {
        const e = await user('postereditor');
        const d = await e.api('slot', { id: slot.id });
        const second = d.images.find(i => i.id !== d.slot.active_image);
        await e.api('activate', { id: slot.id, img: second.id });
        const r = await (await user('guest')).p.request.get(B + 'p.php/' + slug + '.png');
        const buf = await r.body();
        assert.strictEqual(buf.readUInt32BE(16), 1024); assert.strictEqual(buf.readUInt32BE(20), 512);
    });
    await step('Poster-URL ändern (Slug) – alte Adresse 404, neue 200', async () => {
        const e = await user('postereditor');
        const r = await e.api('save', { id: slot.id, slug: 'club-eingang' });
        assert.strictEqual(r.slot.slug, 'club-eingang');
        const g = (await user('guest')).p.request;
        assert.strictEqual((await g.get(B + 'p.php/' + slug + '.png')).status(), 404);
        assert.strictEqual((await g.get(B + 'p.php/club-eingang.png')).status(), 200);
        slug = 'club-eingang';
        await rejects(e.api('save', { id: slot.id, slug: '../x' }), /Adresse/);
    });
    await step('Bild privat → Viewer kann es nicht sehen; mit Bild-Freigabe schon', async () => {
        const a = await user('posteradmin');
        const d = await a.api('slot', { id: slot.id });
        const img = d.images[0];
        await a.api('image_save', { img: img.id, visibility: 'private', enabled: '1' });
        const v = await user('viewer');
        assert.ok(!(await v.api('slot', { id: slot.id })).images.some(i => i.id === img.id));
        assert.strictEqual((await v.p.request.get(B + 'poster.php?thumb=' + img.id)).status(), 404);
        const vid = (await a.api('list')).users.find(x => /Viewer/.test(x.name)).id;
        await a.api('image_acl_set', { img: img.id, user: vid, level: 'view' });
        assert.ok((await v.api('slot', { id: slot.id })).images.some(i => i.id === img.id));
        assert.strictEqual((await v.p.request.get(B + 'poster.php?thumb=' + img.id)).status(), 200);
    });
    await step('Bildlink übernehmen (+ interne Adresse ohne Testschalter abgelehnt)', async () => {
        const u = await user('posteruploader');
        const r = await u.api('url', { id: slot.id, url: B + 'assets/poster-test-t3.png', live: '1' });
        assert.match(r.msg, /übernommen/);
        const d = await u.api('slot', { id: slot.id });
        assert.ok(d.images.some(i => i.source === 'url' && i.live === 1));
        await rejects(u.api('url', { id: slot.id, url: 'file:///etc/passwd' }), /nicht erlaubt/);
    });
    await step('FTP-Eingang: Datei im Ordner wird übernommen und aktiv', async () => {
        const u = await user('posteruploader');
        const d = await u.api('slot', { id: slot.id });
        assert.ok(d.slot.ftp && d.slot.ftp.endsWith('/club-eingang'));
        fs.mkdirSync(ROOT + '/' + d.slot.ftp, { recursive: true });
        const im = IMG('t2'); const im2 = Buffer.from(im); im2[im2.length - 20] ^= 1;   // andere Datei (Inhalt minimal anders) – wird neu kodiert
        fs.writeFileSync(ROOT + '/' + d.slot.ftp + '/ftp-bild.png', IMG('t3').length ? IMG('t1') : im);
        const r = await u.api('ftp', { id: slot.id });
        assert.match(r.msg, /^[01] Bild/);   // t1 existiert schon im Slot → Duplikat zählt als übernommen ohne neues Bild
        assert.ok(!fs.existsSync(ROOT + '/' + d.slot.ftp + '/ftp-bild.png'), 'Datei aus dem Eingang entfernt');
    });
    await step('Schnittstelle mit Token (wie ShareX/curl): falscher Token 403, richtiger lädt hoch', async () => {
        const a = await user('posteradmin');
        const t = await a.api('token', { id: slot.id });
        const g = (await user('guest')).p.request;
        assert.strictEqual((await g.post(B + 'p.php/' + slug, { headers: { Authorization: 'Bearer ps_falsch' }, multipart: { file: { name: 'x.png', mimeType: 'image/png', buffer: IMG('t3') } } })).status(), 403);
        const r = await g.post(B + 'p.php/' + slug, { headers: { Authorization: 'Bearer ' + t.token }, multipart: { file: { name: 'x.png', mimeType: 'image/png', buffer: IMG('t3') } } });
        const d = await r.json();
        assert.ok(d.ok, JSON.stringify(d)); assert.ok(d.url.endsWith('/p.php/' + slug + '.png'));
    });
    await step('Rotation & Zeitplan: abgelaufenes Bild wird nicht geliefert', async () => {
        const a = await user('posteradmin');
        await a.api('save', { id: slot.id, mode: 'rotate', interval_min: '1' });
        const d = await a.api('slot', { id: slot.id });
        for (const i of d.images.slice(1)) await a.api('image_save', { img: i.id, end_at: '2000-01-01T00:00:00Z', enabled: '1' });
        const live = (await a.api('slot', { id: slot.id })).images.filter(i => i.live_now);
        assert.strictEqual(live.length, 1);
        const r404 = await (await user('guest')).p.request.get(B + 'p.php/' + slug + '.png?i=1');
        assert.strictEqual(r404.headers()['x-poster-state'], 'empty', 'nur ein gültiges Bild – sonst Platzhalter');
    });
    await step('Ausgeschaltet → Platzhalter „Image unavailable“ (200, PNG), wieder an → Bild', async () => {
        const a = await user('posteradmin');
        await a.api('save', { id: slot.id, enabled: '0' });
        const g = await user('guest');
        const r = await g.p.request.get(B + 'p.php/' + slug + '.png');
        assert.strictEqual(r.status(), 200); assert.strictEqual(r.headers()['x-poster-state'], 'off'); assert.match(r.headers()['content-type'], /image\//);
        await a.api('save', { id: slot.id, enabled: '1' });
        assert.strictEqual((await g.p.request.get(B + 'p.php/' + slug + '.png')).headers()['x-poster-state'], undefined);
    });
    await step('Öffentlich schalten → Gast sieht Slot (nur ansehen), kann nicht hochladen', async () => {
        const a = await user('posteradmin');
        await a.api('save', { id: slot.id, visibility: 'public' });
        const g = await user('guest');
        const l = await g.api('list');
        assert.strictEqual(l.slots.length, 1); assert.strictEqual(l.slots[0].level, 'view'); assert.ok(!l.slots[0].ftp);
        const d = await g.api('slot', { id: slot.id });
        assert.ok(d.images.every(i => i.source === undefined), 'Gäste sehen keine Herkunft/Besitzer');
        assert.ok(!d.log && !d.acl);
    });
    await step('Oberfläche: Übersicht und Slot-Fenster (Screenshots)', async () => {
        const a = await user('posteradmin');
        await a.p.goto(B + 'poster.php'); await a.p.waitForTimeout(800);
        await a.p.screenshot({ path: (process.env.SHOT_DIR || TMP) + '/poster1.png' });
        await a.p.click('.ps-card'); await a.p.waitForTimeout(800);
        await a.p.screenshot({ path: (process.env.SHOT_DIR || TMP) + '/poster2.png' });
        await a.p.click('[data-tab=settings]'); await a.p.waitForTimeout(400);
        await a.p.screenshot({ path: (process.env.SHOT_DIR || TMP) + '/poster3.png' });
        await a.p.goto(B + 'admin.php?tab=posters'); await a.p.waitForTimeout(500);
        await a.p.screenshot({ path: (process.env.SHOT_DIR || TMP) + '/poster4.png', fullPage: true });
        const g = await user('guest'); await g.p.goto(B + 'poster.php'); await g.p.waitForTimeout(600);
        await g.p.screenshot({ path: (process.env.SHOT_DIR || TMP) + '/poster5.png' });
    });
    await step('Nur der Besitzer/Admin darf den Slot löschen', async () => {
        await rejects((await user('postereditor')).api('delete', { id: slot.id }), /Recht/);
        await (await user('posteradmin')).api('delete', { id: slot.id });
        assert.strictEqual((await (await user('guest')).p.request.get(B + 'p.php/' + slug + '.png')).status(), 404);
    });
    await step('Fremde Herkunft wird abgelehnt', async () => {
        const u = await user('posteruploader');
        const r = await u.p.request.post(B + 'poster.php?api=create', { multipart: { title: 'x' }, headers: { Origin: 'https://evil.example' } });
        assert.strictEqual(r.status(), 400); assert.match((await r.json()).error, /Herkunft/);
    });
    await b.close();
    console.log(oks + '/' + (oks + fails) + ' Schritte bestanden');
    process.exit(fails ? 1 : 0);
})();
