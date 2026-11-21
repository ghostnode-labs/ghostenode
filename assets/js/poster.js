/* VRChat-Poster – Oberfläche (poster.php). Alle Rechte prüft der Server; hier wird nur angezeigt, was die Stufe erlaubt. */
(function () {
    const $ = (s, r) => (r || document).querySelector(s), $$ = (s, r) => [...(r || document).querySelectorAll(s)];
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const L = { none: 0, view: 1, upload: 2, edit: 3, manage: 4 };
    const can = (lvl, need) => L[lvl] >= L[need];
    const kb = n => n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
    const when = ts => ts ? new Date(ts * 1000).toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' }) : '–';
    const dtl = ts => { if (!ts) return ''; const d = new Date(ts * 1000); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0, 16); };
    let D = null, cur = null, tab = 'images', toastT = null;

    function toast(m, bad) { const t = $('#psToast'); t.textContent = m; t.className = 'ps-toast' + (bad ? ' bad' : ''); t.hidden = false; clearTimeout(toastT); toastT = setTimeout(() => { t.hidden = true; }, 3200); }
    function api(act, data, files, onProgress) {
        const get = act === 'list' || act === 'slot';
        if (get && !files) return fetch('poster.php?api=' + act + (data && data.id ? '&id=' + data.id : ''), { credentials: 'same-origin' }).then(j);
        const fd = new FormData();
        Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v == null ? '' : v));
        (files || []).forEach(f => fd.append('files[]', f, f.name || 'bild.png'));
        if (!onProgress) return fetch('poster.php?api=' + act, { method: 'POST', body: fd, credentials: 'same-origin' }).then(j);
        return new Promise((res, rej) => {   // mit Fortschritt (große Uploads)
            const x = new XMLHttpRequest();
            x.open('POST', 'poster.php?api=' + act);
            x.upload.onprogress = e => { if (e.lengthComputable) onProgress(e.loaded / e.total); };
            x.onload = () => { let d; try { d = JSON.parse(x.responseText); } catch (e) { d = { error: 'HTTP ' + x.status + (x.status === 413 ? ' – Datei zu groß für den Server' : '') }; } d.error ? rej(new Error(d.error)) : res(d); };
            x.onerror = () => rej(new Error('Netzwerkfehler'));
            x.send(fd);
        });
    }
    const j = r => r.json().catch(() => ({ error: 'HTTP ' + r.status })).then(d => { if (d.error) throw new Error(d.error); return d; });
    const perm = p => !!(D && D.me && D.me.perms.includes(p));
    const thumb = id => 'poster.php?thumb=' + id;
    const copy = t => (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(() => toast('📋 Kopiert')).catch(() => { prompt('Kopieren:', t); });
    const opts = (map, cur) => Object.entries(map).map(([k, v]) => '<option value="' + esc(k) + '"' + (k === cur ? ' selected' : '') + '>' + esc(v) + '</option>').join('');

    // ---------- Übersicht ----------
    function load() {
        return api('list').then(d => {
            D = d;
            $('#psNew').style.display = perm('posters.upload') ? '' : 'none';
            const me = d.me;
            $('#psMe').innerHTML = me ? '👤 <b>' + esc(me.name) + '</b> · ' + (me.perms.length ? me.perms.map(p => p.replace('posters.', '')).join(', ') : 'nur öffentliche Poster')
                + (d.cfg.quota_mb ? '<div>' + kb(me.used) + ' / ' + d.cfg.quota_mb + ' MB</div><div class="ps-meter"><i style="width:' + Math.min(100, me.used / (d.cfg.quota_mb * 1048576) * 100).toFixed(1) + '%"></i></div>' : '<div>' + kb(me.used) + ' belegt</div>')
                : '👀 Gast – du siehst nur öffentliche Poster. <a href="admin.php?next=poster.php">Anmelden</a>';
            const folders = [...new Set(d.slots.map(s => s.folder).filter(Boolean))].sort();
            const fs = $('#psFolder'), fv = fs.value;
            fs.innerHTML = '<option value="">Alle Ordner</option>' + folders.map(f => '<option' + (f === fv ? ' selected' : '') + '>' + esc(f) + '</option>').join('');
            grid();
        }).catch(e => { $('#psGrid').innerHTML = '<div class="ps-empty">⚠ ' + esc(e.message) + '</div>'; });
    }
    function grid() {
        const q = $('#psSearch').value.trim().toLowerCase(), f = $('#psFolder').value, so = $('#psSort').value;
        let l = D.slots.filter(s => (!f || s.folder === f) && (!q || (s.title + ' ' + s.slug + ' ' + s.folder + ' ' + (s.note || '')).toLowerCase().includes(q)));
        const cmp = { folder: (a, b) => a.folder.localeCompare(b.folder) || a.title.localeCompare(b.title), updated: (a, b) => b.updated - a.updated, hits: (a, b) => b.hits - a.hits, title: (a, b) => a.title.localeCompare(b.title) }[so];
        l.sort(cmp);
        if (!l.length) { $('#psGrid').innerHTML = '<div class="ps-empty">' + (D.slots.length ? 'Nichts gefunden.' : perm('posters.upload') ? 'Noch keine Slots – oben „＋ Neuer Slot“ drücken.' : 'Keine Poster sichtbar.') + '</div>'; return; }
        let last = null, h = '';
        l.forEach(s => {
            if (so === 'folder' && s.folder !== last) { h += '<div class="ps-folder">📁 ' + esc(s.folder || 'Ohne Ordner') + '</div>'; last = s.folder; }
            h += '<div class="ps-card' + (s.enabled ? '' : ' off') + '" tabindex="0" data-slot="' + s.id + '">'
                + '<div class="ps-prev"' + (s.current ? ' style="background-image:url(' + thumb(s.current) + ')"' : '') + '>' + (s.current ? '' : '🖼') + '</div>'
                + '<button type="button" class="btn btn-sm ps-copy" data-copy="' + esc(s.url) + '" title="Poster-URL kopieren">⧉ URL</button>'
                + '<div class="ps-cbody"><b>' + esc(s.title) + '</b><code>' + esc(s.url) + '</code><div class="ps-cmeta">'
                + '<span class="ps-pill">' + esc((D.modes[s.mode] || s.mode)) + '</span>' + (s.enabled ? '' : '<span class="ps-pill red">aus</span>')
                + '<span class="ps-pill' + (s.visibility === 'public' ? ' green' : s.visibility === 'private' ? ' red' : '') + '">' + esc((D.vis[s.visibility] || '').split(' ')[0]) + '</span>'
                + (D.me ? '<span>📈 ' + s.hits + '</span>' : '') + (s.owner_name ? '<span>👤 ' + esc(s.owner_name) + '</span>' : '') + (can(s.level, 'upload') ? '<span class="ps-pill gold">' + esc((D.levels[s.level] || s.level).split(' ')[0]) + '</span>' : '') + '</div></div></div>';
        });
        $('#psGrid').innerHTML = h;
    }

    // ---------- Slot-Fenster ----------
    function open(id, keepTab) {
        if (!keepTab) tab = 'images';
        return api('slot', { id }).then(d => { cur = d; $('#psModal').hidden = false; document.body.style.overflow = 'hidden'; draw(); }).catch(e => toast(e.message, true));
    }
    const reload = () => cur ? Promise.all([open(cur.slot.id, true), load()]) : load();
    function close() { $('#psModal').hidden = true; document.body.style.overflow = ''; cur = null; }
    function draw() {
        const s = cur.slot, lv = s.level;
        const tabs = [['images', '🖼 Bilder (' + cur.images.length + ')']].concat(can(lv, 'edit') ? [['settings', '⚙ Einstellungen']] : []).concat(lv === 'manage' ? [['acl', '👥 Rechte']] : [])
            .concat(can(lv, 'upload') ? [['ways', '🔌 Upload-Wege']] : []).concat(can(lv, 'edit') ? [['stats', '📈 Statistik & Verlauf']] : []);
        if (!tabs.some(t => t[0] === tab)) tab = 'images';
        const ci = cur.images.find(i => i.id === s.current);
        $('#psBody').innerHTML = '<div class="ps-dhead"><div class="ps-prev"' + (ci ? ' style="background-image:url(' + thumb(ci.id) + ')"' : '') + '>' + (ci ? '' : '🖼') + '</div>'
            + '<div class="ps-dinfo"><h2 id="psTitle">' + esc(s.title) + (s.enabled ? '' : ' <span class="ps-pill red">aus</span>') + '</h2>'
            + '<div class="ps-url"><input type="text" readonly value="' + esc(s.url) + '" aria-label="Poster-URL"><button type="button" class="btn btn-sm btn-gold" data-copy="' + esc(s.url) + '">⧉ Kopieren</button><a class="btn btn-sm" href="' + esc(s.url) + '" target="_blank" rel="noopener">↗ Öffnen</a></div>'
            + '<div class="ps-cmeta"><span class="ps-pill">' + esc(D.modes[s.mode]) + (s.mode === 'rotate' ? ' · alle ' + s.interval_min + ' min' : '') + '</span><span class="ps-pill">' + esc(D.vis[s.visibility]) + '</span>'
            + (s.tw || s.th ? '<span class="ps-pill">' + (s.tw || 'auto') + '×' + (s.th || 'auto') + ' · ' + esc(D.fits[s.fit]) + '</span>' : '') + '<span class="ps-pill">' + s.format.toUpperCase() + '</span>'
            + (D.me ? '<span>📈 ' + s.hits + ' Abrufe · zuletzt ' + when(s.last_hit) + '</span>' : '') + '</div>'
            + (s.note ? '<p class="muted small">' + esc(s.note) + '</p>' : '') + '<canvas class="ps-qr" width="96" height="96" title="QR-Code der Poster-URL"></canvas></div></div>'
            + '<div class="ps-tabs" role="tablist">' + tabs.map(([k, l]) => '<button type="button" role="tab" data-tab="' + k + '" class="' + (k === tab ? 'on' : '') + '">' + esc(l) + '</button>').join('') + '</div>'
            + '<div id="psPane">' + (P[tab] ? P[tab](s, lv) : '') + '</div>';
        const qr = $('.ps-qr'); if (qr && window.QR) try { window.QR.draw(qr, s.url); } catch (e) { qr.remove(); } else if (qr) qr.remove();
        if (tab === 'images') bindImages();
    }
    const P = {
        images: (s, lv) => {
            let h = '';
            if (can(lv, 'upload')) {
                h += '<div class="ps-drop" id="psDrop" tabindex="0"><b>Bilder hierher ziehen</b>, klicken zum Auswählen oder <b>Strg+V</b> zum Einfügen<br><small>PNG, JPG, WebP, GIF · max. ' + D.cfg.max_mb + ' MB je Datei · größer als ' + D.cfg.max_dim + ' px wird verkleinert · mehrere Dateien auf einmal</small>'
                    + '<input type="file" id="psFile" accept="image/png,image/jpeg,image/webp,image/gif" multiple hidden><div class="ps-prog" hidden><i></i></div></div>'
                    + '<div class="ps-row">' + (can(lv, 'edit') ? '<label class="small"><input type="checkbox" id="psAct" checked> neues Bild sofort aktiv</label>' : '<span class="muted small">Neue Bilder schaltet jemand mit Bearbeiten-Recht aktiv.</span>') + '</div>'
                    + (D.cfg.links ? '<form class="ps-row" id="psUrlForm"><input type="url" name="url" placeholder="Bildlink https://… übernehmen" required><label class="small" title="Lädt das Bild alle ' + D.cfg.live_min + ' min neu – ändert sich das Bild an der Quelle, ändert sich das Poster"><input type="checkbox" name="live" value="1"> live halten</label><button class="btn btn-sm">⬇ Übernehmen</button></form>' : '');
            }
            if (!cur.images.length) return h + '<div class="ps-empty">Noch keine Bilder.</div>';
            const users = D.users || [];
            return h + '<div class="ps-imgs" id="psImgs">' + cur.images.map((i, n) => {
                const act = i.id === s.active_image, ed = i.level === 'edit';
                return '<div class="ps-img' + (act ? ' active' : '') + (i.live_now ? '' : ' dim') + '" data-img="' + i.id + '"' + (can(lv, 'edit') ? ' draggable="true"' : '') + '>'
                    + '<div class="ps-prev" data-zoom="' + i.id + '" style="background-image:url(' + thumb(i.id) + ')"></div><div class="ps-ib">'
                    + '<b title="' + esc(i.title) + '">' + (can(lv, 'edit') ? '<span class="ps-grip" title="Ziehen zum Sortieren">⠿</span> ' : '') + '#' + (n) + ' ' + esc(i.title || 'Bild ' + i.id) + '</b>'
                    + '<span class="muted">' + i.w + '×' + i.h + ' · ' + kb(i.size) + (i.source ? ' · ' + esc({ upload: '⬆', url: '🔗', ftp: '📂', api: '🔌' }[i.source] || i.source) : '') + (i.live ? ' · 🔄 live' : '') + '</span>'
                    + (i.owner_name ? '<span class="muted">👤 ' + esc(i.owner_name) + ' · ' + when(i.created) + '</span>' : '')
                    + '<div class="ps-cmeta">' + (act ? '<span class="ps-pill gold">★ aktiv</span>' : '') + (s.fallback_image === i.id ? '<span class="ps-pill">Ersatz</span>' : '') + (i.enabled ? '' : '<span class="ps-pill red">aus</span>')
                    + (i.start_at || i.end_at ? '<span class="ps-pill">🗓 ' + (i.start_at ? when(i.start_at) : '…') + ' – ' + (i.end_at ? when(i.end_at) : '…') + '</span>' : '') + (i.visibility !== 'inherit' ? '<span class="ps-pill">' + esc((D.vis[i.visibility] || '').split(' ')[0]) + '</span>' : '') + '</div>'
                    + '<div class="ps-ia">' + (can(lv, 'edit') && !act ? '<button type="button" class="btn btn-sm btn-gold" data-iact="activate">★ Aktiv</button>' : '')
                    + (ed ? '<button type="button" class="btn btn-sm" data-iact="edit">✎</button>' : '') + '<a class="btn btn-sm" href="' + esc(s.url.replace(/\?.*$/, '') + '?i=' + n) + '" target="_blank" rel="noopener" title="Feste Adresse nur für dieses Bild (#' + n + ' der gerade gültigen)">#</a>'
                    + (ed ? '<button type="button" class="btn btn-sm btn-danger" data-iact="del" title="Löschen">🗑</button>' : '') + '</div>'
                    + (ed ? '<details class="ps-ied small"><summary>Bearbeiten</summary><form data-iform="' + i.id + '" class="ps-form" style="margin-top:8px">'
                        + '<label class="wide">Titel<input type="text" name="title" value="' + esc(i.title) + '" maxlength="120"></label>'
                        + '<label>Zeigen ab<input type="datetime-local" name="start_at" value="' + dtl(i.start_at) + '"></label><label>Zeigen bis<input type="datetime-local" name="end_at" value="' + dtl(i.end_at) + '"></label>'
                        + '<label>Sichtbarkeit im Dashboard<small>Die Poster-URL zeigt das Bild trotzdem, wenn es dran ist.</small><select name="visibility"><option value="inherit"' + (i.visibility === 'inherit' ? ' selected' : '') + '>wie der Slot</option>' + opts(D.vis, i.visibility) + '</select></label>'
                        + '<label class="chk"><input type="checkbox" name="enabled" value="1"' + (i.enabled ? ' checked' : '') + '> verwenden</label>'
                        + (i.source_url ? '<label class="chk"><input type="checkbox" name="live" value="1"' + (i.live ? ' checked' : '') + '> live halten</label><span class="muted wide" style="word-break:break-all">🔗 ' + esc(i.source_url) + '</span>' : '')
                        + '<div class="wide ps-ia"><button class="btn btn-sm btn-gold">Speichern</button>' + (can(lv, 'edit') ? '<button type="button" class="btn btn-sm" data-iact="fallback">Als Ersatzbild</button>' : '')
                        + (D.slots.filter(x => x.id !== s.id && can(x.level, 'upload')).length ? '<select data-move><option value="">→ in Slot verschieben …</option>' + D.slots.filter(x => x.id !== s.id && can(x.level, 'upload')).map(x => '<option value="' + x.id + '">' + esc(x.title) + '</option>').join('') + '</select>' : '') + '</div>'
                        + (can(lv, 'edit') && users.length ? '<div class="wide"><b>Rechte nur für dieses Bild</b><div data-iacl="' + i.id + '" class="muted">…</div><div class="ps-ia"><select data-iacluser>' + users.map(u => '<option value="' + u.id + '">' + esc(u.name) + '</option>').join('') + '</select><select data-iacllvl><option value="view">👁 darf sehen</option><option value="edit">✎ darf bearbeiten</option><option value="none">✕ entfernen</option></select><button type="button" class="btn btn-sm" data-iacladd>Setzen</button></div></div>' : '')
                        + '</form></details>' : '')
                    + '</div></div>';
            }).join('') + '</div>' + (can(lv, 'edit') && cur.images.length > 1 ? '<p class="muted small">Reihenfolge per Ziehen ändern – gilt für Rotation und für die festen Adressen …?i=0, ?i=1 … Ausgegraute Bilder sind gerade nicht gültig (aus oder außerhalb des Zeitfensters).</p>' : '');
        },
        settings: s => '<form class="ps-form" id="psSet">'
            + '<label>Titel<input type="text" name="title" value="' + esc(s.title) + '" maxlength="80"></label>'
            + '<label>Ordner<input type="text" name="folder" value="' + esc(s.folder) + '" maxlength="40" list="psFolders"><datalist id="psFolders">' + [...new Set(D.slots.map(x => x.folder).filter(Boolean))].map(f => '<option value="' + esc(f) + '">').join('') + '</datalist></label>'
            + '<label>Poster-URL (Adresse)<input type="text" name="slug" value="' + esc(s.slug) + '" pattern="[a-z0-9][a-z0-9_\\-]{2,47}" title="3–48 Zeichen: a–z, 0–9, - und _"><small>⚠ Ändern macht die alte URL ungültig – Welten müssen die neue nutzen.</small></label>'
            + '<label>Sichtbarkeit im Dashboard<select name="visibility">' + opts(D.vis, s.visibility) + '</select><small>Die Poster-URL selbst ist immer abrufbar (sonst kann VRChat sie nicht laden), solange der Slot an ist.</small></label>'
            + '<label>Modus<select name="mode">' + opts(D.modes, s.mode) + '</select><small>Zeitplan: das Bild mit dem jüngsten „Zeigen ab“ im gültigen Zeitfenster gewinnt.</small></label>'
            + '<label>Rotation alle … Minuten<input type="number" name="interval_min" min="1" max="10080" value="' + s.interval_min + '"></label>'
            + '<label>Ausgabeformat<select name="format"><option value="png"' + (s.format === 'png' ? ' selected' : '') + '>PNG (verlustfrei, Transparenz)</option><option value="jpg"' + (s.format === 'jpg' ? ' selected' : '') + '>JPG (kleiner)</option></select></label>'
            + '<label>Größe anpassen<select name="fit">' + opts(D.fits, s.fit) + '</select></label>'
            + '<label>Breite × Höhe (px, 0 = automatisch)<span class="ps-presets"><input type="number" name="tw" min="0" max="' + D.cfg.max_dim + '" value="' + s.tw + '" style="width:90px"> × <input type="number" name="th" min="0" max="' + D.cfg.max_dim + '" value="' + s.th + '" style="width:90px"></span>'
            + '<span class="ps-presets">' + [[2048, 1024], [1024, 1024], [2048, 2048], [1920, 1080], [1024, 512], [1024, 2048], [0, 0]].map(([w, h]) => '<button type="button" class="btn btn-sm" data-preset="' + w + 'x' + h + '">' + (w ? w + '×' + h : 'Original') + '</button>').join('') + '</span></label>'
            + '<label>Hintergrundfarbe (Einpassen)<input type="color" name="bg" value="' + esc(s.bg) + '"></label>'
            + '<label>Zwischenspeichern (Sekunden)<input type="number" name="cache_s" min="0" max="86400" value="' + s.cache_s + '"><small>Wie lange Browser/Proxys das Bild behalten dürfen. 0 = immer frisch.</small></label>'
            + '<label class="wide">Notiz (z. B. welche Welt)<textarea name="note" rows="2" maxlength="500">' + esc(s.note) + '</textarea></label>'
            + '<label class="chk"><input type="checkbox" name="enabled" value="1"' + (s.enabled ? ' checked' : '') + '> Slot an (Poster-URL liefert ein Bild)</label>'
            + '<div class="wide ps-ia"><button class="btn btn-gold">Speichern</button>' + (s.level === 'manage' ? '<button type="button" class="btn btn-danger" id="psDel">🗑 Slot löschen</button>' : '') + '</div></form>',
        acl: s => {
            const users = D.users || [];
            return '<p class="muted small">Zusätzlich zu den Rollenrechten: wer darf in <b>diesem</b> Slot was? Die Rolle begrenzt nach oben (ohne „Poster hochladen“ bleibt es beim Ansehen). Gäste sehen nur öffentliche Slots.</p>'
                + '<table class="ps-tbl"><tr><th>Benutzer</th><th>Stufe</th><th></th></tr><tr><td>👑 ' + esc(s.owner_name) + '</td><td>Besitzer – alle Rechte</td><td></td></tr>'
                + (cur.acl || []).map(a => '<tr><td>' + esc(a.name) + '</td><td><select data-acl="' + a.user_id + '">' + opts(D.levels, a.level) + '<option value="none">✕ entfernen</option></select></td><td></td></tr>').join('') + '</table>'
                + (users.length ? '<div class="ps-row"><select id="psAclUser">' + users.map(u => '<option value="' + u.id + '">' + esc(u.name) + '</option>').join('') + '</select><select id="psAclLvl">' + opts(D.levels, 'view') + '</select><button type="button" class="btn btn-sm btn-gold" id="psAclAdd">＋ Freigeben</button>'
                    + '<span class="ps-sp"></span><button type="button" class="btn btn-sm" id="psOwner">👑 Gewählten Benutzer zum Besitzer machen</button></div>' : '');
        },
        ways: s => '<div class="ps-form"><div class="wide"><h4>📂 FTP</h4>' + (s.ftp ? '<p class="small">Bilder per FTP in diesen Ordner legen (Plesk-FTP-Zugang zur Webseite). Sie werden automatisch übernommen (Aufgabenplaner, alle 15 min) und sofort aktiv – oder jetzt abholen:</p><div class="ps-url"><input type="text" readonly value="' + esc(s.ftp) + '/"><button type="button" class="btn btn-sm" data-copy="' + esc(s.ftp) + '/">⧉</button><button type="button" class="btn btn-sm btn-gold" id="psFtp">📥 Jetzt abholen</button></div>' : '<p class="muted small">FTP-Eingang ist in den Admin-Einstellungen aus.</p>') + '</div>'
            + '<div class="wide"><h4>🔌 Schnittstelle (ShareX, curl, Skripte)</h4><p class="small">Mit einem geheimen Token lädt jedes Programm direkt in diesen Slot. Der Token wird nur einmal angezeigt.' + (s.has_token ? ' <span class="ps-pill green">Token vorhanden</span>' : '') + '</p>'
            + (s.level === 'manage' || can(s.level, 'edit') ? '<button type="button" class="btn btn-sm btn-gold" id="psTok">' + (s.has_token ? '🔄 Neuen Token erzeugen (alter wird ungültig)' : '🔑 Token erzeugen') + '</button>' : '') + '<div id="psTokOut"></div></div>'
            + '<div class="wide"><h4>🔗 Bildlink</h4><p class="small">Im Reiter „Bilder“: Link einfügen. Mit „live halten“ wird das Bild alle ' + D.cfg.live_min + ' min neu geladen – so lässt sich das Poster auch über eine fremde Adresse steuern.</p></div>'
            + '<div class="wide"><h4>🥽 In VRChat</h4><p class="small">Poster-URL im Bildlader (VRCImageDownloader), im Poster-Prefab oder als Videoplayer-Vorschaubild eintragen. Feste Bilder einer Liste: <code>' + esc(s.url) + '?i=0</code>, <code>?i=1</code> … VRChat lädt Bilder bis 2048 × 2048 px; fremde Domains nur mit „Allow Untrusted URLs“.</p></div></div>',
        stats: () => {
            const h = cur.hits || [], mx = Math.max(1, ...h.map(x => x.n));
            return '<h4>📈 Abrufe der letzten 30 Tage</h4>' + (h.length ? '<div class="ps-bars">' + h.map(x => '<span title="' + esc(x.day) + ': ' + x.n + '" style="height:' + Math.round(x.n / mx * 100) + '%"></span>').join('') + '</div>' : '<p class="muted">Noch keine Abrufe.</p>')
                + '<h4>📜 Verlauf</h4><div class="ps-log">' + (cur.log || []).map(l => '<div><span class="muted">' + when(l.ts) + '</span> · <b>' + esc(l.user) + '</b> · ' + esc(l.action) + (l.detail ? ' <span class="muted">– ' + esc(l.detail) + '</span>' : '') + '</div>').join('') + '</div>';
        },
    };

    // ---------- Hochladen ----------
    function upload(files, slotId) {
        files = [...files].filter(f => /^image\//.test(f.type));
        if (!files.length) return toast('Keine Bilddateien.', true);
        const big = files.filter(f => f.size > D.cfg.max_mb * 1048576);
        if (big.length) toast(big.length + ' Datei(en) über ' + D.cfg.max_mb + ' MB werden abgelehnt.', true);
        const pr = $('#psDrop .ps-prog'); if (pr) { pr.hidden = false; pr.firstChild.style.width = '0'; }
        const act = $('#psAct') ? ($('#psAct').checked ? '1' : '') : '';
        return api('upload', { id: slotId, activate: act }, files, p => { if (pr) pr.firstChild.style.width = Math.round(p * 100) + '%'; })
            .then(r => { toast('⬆ ' + r.added + ' hochgeladen' + (r.dupes ? ' · ' + r.dupes + ' schon vorhanden' : '') + (r.errors.length ? ' · ⚠ ' + r.errors.join(' · ') : ''), !!r.errors.length && !r.added); return reload(); })
            .catch(e => toast(e.message, true)).finally(() => { if (pr) pr.hidden = true; });
    }
    function bindImages() {
        const dz = $('#psDrop');
        if (dz) {
            dz.addEventListener('click', () => $('#psFile').click());
            dz.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); $('#psFile').click(); } });
            $('#psFile').addEventListener('change', e => upload(e.target.files, cur.slot.id));
            ['dragenter', 'dragover'].forEach(ev => dz.addEventListener(ev, e => { if ([...e.dataTransfer.types].includes('Files')) { e.preventDefault(); dz.classList.add('over'); } }));
            ['dragleave', 'drop'].forEach(ev => dz.addEventListener(ev, () => dz.classList.remove('over')));
            dz.addEventListener('drop', e => { if (e.dataTransfer.files.length) { e.preventDefault(); upload(e.dataTransfer.files, cur.slot.id); } });
        }
        const uf = $('#psUrlForm');
        if (uf) uf.addEventListener('submit', e => {
            e.preventDefault();
            const b = uf.querySelector('button'); b.disabled = true;
            api('url', { id: cur.slot.id, url: uf.url.value, live: uf.live.checked ? '1' : '', activate: $('#psAct') && $('#psAct').checked ? '1' : '' }).then(r => { toast(r.msg); return reload(); }).catch(x => toast(x.message, true)).finally(() => { b.disabled = false; });
        });
        // Sortieren per Ziehen
        const box = $('#psImgs'); if (!box) return;
        let drag = null;
        box.addEventListener('dragstart', e => { const c = e.target.closest('.ps-img'); if (!c) return; drag = c; c.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; });
        box.addEventListener('dragend', () => { if (drag) drag.classList.remove('dragging'); drag = null; $$('.drop-before, .drop-after', box).forEach(x => x.classList.remove('drop-before', 'drop-after')); });
        box.addEventListener('dragover', e => { if (!drag) return; e.preventDefault(); const t = e.target.closest('.ps-img'); $$('.drop-before, .drop-after', box).forEach(x => x.classList.remove('drop-before', 'drop-after')); if (!t || t === drag) return; const r = t.getBoundingClientRect(); t.classList.add(e.clientX < r.left + r.width / 2 ? 'drop-before' : 'drop-after'); });
        box.addEventListener('drop', e => {
            if (!drag) return; e.preventDefault();
            const t = $('.drop-before, .drop-after', box);
            if (t) { t.classList.contains('drop-before') ? t.before(drag) : t.after(drag); }
            const ids = $$('.ps-img', box).map(x => +x.dataset.img);
            api('reorder', { id: cur.slot.id, ids: JSON.stringify(ids) }).then(r => { toast(r.msg); return reload(); }).catch(x => toast(x.message, true));
        });
        // Bild-Rechte nachladen, wenn „Bearbeiten“ aufgeklappt wird
        $$('details.ps-ied', box).forEach(d => d.addEventListener('toggle', () => {
            const el = $('[data-iacl]', d); if (!d.open || !el) return;
            api('image_acl', { img: el.dataset.iacl }).then(r => { el.innerHTML = r.acl.length ? r.acl.map(a => esc(a.name) + ': ' + (a.level === 'edit' ? '✎ bearbeiten' : '👁 sehen')).join(' · ') : 'keine (es gelten die Slot-Rechte)'; }).catch(x => { el.textContent = x.message; });
        }));
    }

    // ---------- Ereignisse ----------
    document.addEventListener('click', e => {
        const cp = e.target.closest('[data-copy]'); if (cp) { e.preventDefault(); e.stopPropagation(); return copy(cp.dataset.copy); }
        const card = e.target.closest('.ps-card[data-slot]'); if (card) return open(+card.dataset.slot);
        if (e.target.closest('[data-close]') || e.target.id === 'psModal') return close();
        const tb = e.target.closest('.ps-tabs [data-tab]'); if (tb) { tab = tb.dataset.tab; return draw(); }
        const z = e.target.closest('[data-zoom]'); if (z) { const lb = document.createElement('div'); lb.className = 'ps-lb'; lb.innerHTML = '<img src="' + thumb(z.dataset.zoom) + '" alt="">'; lb.onclick = () => lb.remove(); document.body.appendChild(lb); return; }
        const pre = e.target.closest('[data-preset]'); if (pre) { const [w, h] = pre.dataset.preset.split('x'); const f = $('#psSet'); f.tw.value = w; f.th.value = h; if (+w && f.fit.value === 'none') f.fit.value = 'contain'; return; }
        const ia = e.target.closest('[data-iact]');
        if (ia && cur) {
            const id = +ia.closest('.ps-img').dataset.img, a = ia.dataset.iact;
            if (a === 'activate') return api('activate', { id: cur.slot.id, img: id }).then(r => { toast(r.msg); return reload(); }).catch(x => toast(x.message, true));
            if (a === 'fallback') return api('save', { id: cur.slot.id, fallback_image: id }).then(() => { toast('Ersatzbild gesetzt.'); return reload(); }).catch(x => toast(x.message, true));
            if (a === 'edit') { const d = ia.closest('.ps-img').querySelector('details'); if (d) d.open = !d.open; return; }
            if (a === 'del') { if (!confirm('Bild endgültig löschen?')) return; return api('image_delete', { img: id }).then(r => { toast(r.msg); return reload(); }).catch(x => toast(x.message, true)); }
        }
        const iadd = e.target.closest('[data-iacladd]');
        if (iadd) { const f = iadd.closest('form'), img = +f.dataset.iform; return api('image_acl_set', { img, user: $('[data-iacluser]', f).value, level: $('[data-iacllvl]', f).value }).then(r => { $('[data-iacl]', f).innerHTML = r.acl.length ? r.acl.map(a => esc(a.name) + ': ' + (a.level === 'edit' ? '✎ bearbeiten' : '👁 sehen')).join(' · ') : 'keine'; toast('Gespeichert.'); }).catch(x => toast(x.message, true)); }
        if (e.target.id === 'psNew') return newSlot();
        if (e.target.id === 'psDel') { if (!confirm('Slot „' + cur.slot.title + '“ mit allen Bildern löschen? Die Poster-URL funktioniert danach nicht mehr.')) return; return api('delete', { id: cur.slot.id }).then(r => { toast(r.msg); close(); load(); }).catch(x => toast(x.message, true)); }
        if (e.target.id === 'psAclAdd') return api('acl_set', { id: cur.slot.id, user: $('#psAclUser').value, level: $('#psAclLvl').value }).then(r => { cur.acl = r.acl; draw(); toast('Freigegeben.'); }).catch(x => toast(x.message, true));
        if (e.target.id === 'psOwner') { if (!confirm('Besitz übertragen? Du verlierst dann die Besitzer-Rechte (außer mit „alle Slots verwalten“).')) return; return api('owner_set', { id: cur.slot.id, user: $('#psAclUser').value }).then(r => { toast(r.msg); return reload(); }).catch(x => toast(x.message, true)); }
        if (e.target.id === 'psFtp') return api('ftp', { id: cur.slot.id }).then(r => { toast(r.msg + (r.errors.length ? ' ⚠ ' + r.errors.join(' · ') : ''), !!r.errors.length); return reload(); }).catch(x => toast(x.message, true));
        if (e.target.id === 'psTok') {
            if (cur.slot.has_token && !confirm('Neuen Token erzeugen? Programme mit dem alten Token können dann nicht mehr hochladen.')) return;
            return api('token', { id: cur.slot.id }).then(r => {
                const sx = { Version: '15.0.0', Name: 'GhostNode Poster – ' + cur.slot.title, DestinationType: 'ImageUploader', RequestMethod: 'POST', RequestURL: r.endpoint, Headers: { Authorization: 'Bearer ' + r.token }, Body: 'MultipartFormData', FileFormName: 'file', URL: '{json:url}', ErrorMessage: '{json:error}' };
                const blob = URL.createObjectURL(new Blob([JSON.stringify(sx, null, 2)], { type: 'application/json' }));
                $('#psTokOut').innerHTML = '<p class="small">⚠ Nur jetzt sichtbar – sicher aufbewahren:</p><div class="ps-url"><input type="text" readonly value="' + esc(r.token) + '"><button type="button" class="btn btn-sm" data-copy="' + esc(r.token) + '">⧉</button><a class="btn btn-sm btn-gold" href="' + blob + '" download="ghostnode-poster-' + esc(cur.slot.slug) + '.sxcu">⬇ ShareX-Datei</a></div>'
                    + '<div class="ps-code">curl -H "Authorization: Bearer ' + esc(r.token) + '" -F "file=@bild.png" ' + esc(r.endpoint) + '\n\ncurl -H "Authorization: Bearer ' + esc(r.token) + '" -F "url=https://…/bild.png" -F "live=1" ' + esc(r.endpoint) + '</div>';
                cur.slot.has_token = true;
            }).catch(x => toast(x.message, true));
        }
    });
    document.addEventListener('change', e => {
        const a = e.target.closest('[data-acl]'); if (a) return api('acl_set', { id: cur.slot.id, user: a.dataset.acl, level: a.value }).then(r => { cur.acl = r.acl; draw(); toast('Gespeichert.'); }).catch(x => toast(x.message, true));
        const mv = e.target.closest('[data-move]'); if (mv && mv.value) { const img = +mv.closest('form').dataset.iform; return api('image_move', { img, to: mv.value }).then(r => { toast(r.msg); return reload(); }).catch(x => toast(x.message, true)); }
    });
    document.addEventListener('submit', e => {
        const f = e.target;
        if (f.id === 'psSet') {
            e.preventDefault();
            const d = Object.fromEntries(new FormData(f)); d.enabled = f.enabled.checked ? '1' : '0'; d.id = cur.slot.id;
            return api('save', d).then(r => { toast(r.msg); return reload(); }).catch(x => toast(x.message, true));
        }
        if (f.dataset.iform) {
            e.preventDefault();
            const d = Object.fromEntries(new FormData(f)); d.img = f.dataset.iform; d.enabled = f.enabled.checked ? '1' : '0'; if (f.live) d.live = f.live.checked ? '1' : '0';
            ['start_at', 'end_at'].forEach(k => { if (d[k]) d[k] = new Date(d[k]).toISOString(); });
            return api('image_save', d).then(r => { toast(r.msg); return reload(); }).catch(x => toast(x.message, true));
        }
    });
    // Dateien direkt auf eine Slot-Kachel ziehen
    const g = $('#psGrid');
    g.addEventListener('dragover', e => { const c = e.target.closest('.ps-card'); if (!c || ![...e.dataTransfer.types].includes('Files')) return; const s = D.slots.find(x => x.id === +c.dataset.slot); if (!s || !can(s.level, 'upload')) return; e.preventDefault(); $$('.ps-card.drop').forEach(x => x !== c && x.classList.remove('drop')); c.classList.add('drop'); });
    g.addEventListener('dragleave', e => { const c = e.target.closest('.ps-card'); if (c && !c.contains(e.relatedTarget)) c.classList.remove('drop'); });
    g.addEventListener('drop', e => { const c = e.target.closest('.ps-card'); if (!c) return; c.classList.remove('drop'); if (!e.dataTransfer.files.length) return; e.preventDefault(); upload(e.dataTransfer.files, +c.dataset.slot); });
    // Einfügen aus der Zwischenablage ins offene Slot-Fenster
    document.addEventListener('paste', e => {
        if (!cur || !can(cur.slot.level, 'upload') || tab !== 'images' || (document.activeElement && document.activeElement.matches('input, textarea'))) return;
        const files = [...(e.clipboardData || {}).items || []].filter(i => i.kind === 'file' && /^image\//.test(i.type)).map(i => i.getAsFile());
        if (files.length) { e.preventDefault(); upload(files, cur.slot.id); }
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') { const lb = $('.ps-lb'); if (lb) return lb.remove(); if (cur) close(); } if (e.key === 'Enter' && e.target.matches('.ps-card')) open(+e.target.dataset.slot); });
    ['input', 'change'].forEach(ev => { $('#psSearch').addEventListener(ev, grid); }); $('#psFolder').addEventListener('change', grid); $('#psSort').addEventListener('change', grid);

    function newSlot() {
        const title = prompt('Name des Posters (z. B. „Club Eingang links“):'); if (title === null) return;
        const folder = prompt('Ordner (optional, z. B. Name der Welt):', $('#psFolder').value || '') || '';
        api('create', { title, folder, visibility: 'users' }).then(r => { toast('Slot angelegt.'); return load().then(() => open(r.slot.id)); }).catch(x => toast(x.message, true));
    }
    load();
})();
