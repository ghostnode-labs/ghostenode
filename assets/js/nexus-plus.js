/* VRC Nexus Web 8.2 – 💠 Mein Nexus: 🔋 Geräte & Uhr, 💓 Puls-Verlauf, 📆 Wochenrückblick, 🗺 Welt-Wunschliste
 * plus 🏅 Abzeichen & Profil-Vollständigkeit im Community-Profil. Daten von nexus.php (act = nxp_*); alle Texte werden maskiert. */
(function () {
    const K = window.NexusWebKit;
    if (!K || !document.getElementById('nxpPane')) return;
    const { api, toast, esc, ago } = K;
    const $ = (s, r) => (r || document).querySelector(s), $$ = (s, r) => [...(r || document).querySelectorAll(s)];
    const box = () => $('#nxpPane');
    const hm = s => { s = Math.max(0, Math.round(s || 0)); const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60); return h ? h + ' h ' + m + ' min' : m + ' min'; };
    const day = t => new Date(t * 1000).toLocaleDateString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit' });
    const fail = el => e => { (el || box()).innerHTML = '<div class="vx-error">' + esc(e.message || e) + '</div>'; };
    let timer = null, heartRange = 'day', weekBack = 0, wishTag = '', wishOpen = true;
    const stop = () => { if (timer) clearInterval(timer); timer = null; };

    // ---------------- 🔋 Geräte & Uhr ----------------
    function batt(d) {
        if (d.battery === null || d.battery === undefined) return '<span class="nw-muted">kein Akku-Wert</span>';
        const c = d.low ? 'low' : d.battery < 50 ? 'mid' : 'ok';
        return '<span class="nxp-batt ' + c + '" title="' + d.battery + ' %"><i style="width:' + d.battery + '%"></i></span> <b>' + d.battery + ' %</b>' + (d.charging ? ' ⚡' : '');
    }
    function devices() {
        const b = box();
        api('nxp_devices').then(d => {
            const s = d.state;
            const live = s ? (s.fresh ? '🟢 Desktop-Client verbunden' + (s.running ? ' · 🎮 VRChat läuft' + (s.world ? ' – ' + esc(s.world) : '') : '') : '⚪ Client zuletzt ' + esc(ago(s.updated))) : '⚪ Noch kein Desktop-Client gemeldet';
            const low = d.devices.filter(x => x.low);
            b.innerHTML = '<div class="nw-card"><div class="nw-row"><h3 style="margin:0">🔋 Geräte &amp; Uhr</h3><span class="sp"></span><span class="nw-muted">' + live + '</span></div>'
                + (s && s.heart ? '<p>💓 Puls gerade <b>' + s.heart + ' bpm</b>' + (s.heart_src ? ' <span class="nw-muted">(' + esc(s.heart_src) + ')</span>' : '') + '</p>' : '')
                + (low.length ? '<div class="nxp-warn">🪫 Bald laden: ' + low.map(x => esc(x.name) + ' (' + x.battery + ' %)').join(', ') + '</div>' : '')
                + (d.devices.length ? '<div class="nxp-devs">' + d.devices.map(x => '<div class="nxp-dev' + (x.connected ? '' : ' off') + '"><span class="nxp-ico">' + esc(x.label.split(' ')[0]) + '</span><div><b>' + esc(x.name) + '</b>'
                    + '<div class="nw-muted">' + esc(x.label.replace(/^\S+\s/, '')) + (x.via ? ' · ' + esc(x.via) : '') + (x.connected ? '' : ' · getrennt') + '</div><div>' + batt(x) + '</div></div></div>').join('') + '</div>'
                    : '<p class="nw-muted">Keine Geräte gemeldet. VRC Nexus (Desktop) zeigt hier Uhr, Tracker und Controller mit Akkustand, sobald sie verbunden sind.</p>')
                + '<p class="nw-muted">Unter 🔔 Push-Benachrichtigungen (Konto &amp; Einstellungen) kannst du dich bei schwachem Akku (unter ' + d.low + ' %) erinnern lassen.</p></div>'
                + '<div class="nw-card"><h3>🖥️ PCs mit Desktop-Client</h3>' + (d.clients.length ? d.clients.map(c => '<div class="nw-row nxp-line"><b>' + (c.online ? '🟢 ' : '⚪ ') + esc(c.name) + '</b><span class="nw-muted">' + esc([c.platform, c.version && 'v' + c.version].filter(Boolean).join(' · ')) + '</span><span class="sp"></span><span class="nw-muted">' + (c.last_seen ? esc(ago(c.last_seen)) : 'noch nie') + '</span></div>').join('')
                    : '<p class="nw-muted">Noch kein PC verbunden – unter „🎮 Spiel &amp; OSC“ ein Geräte-Token erstellen.</p>') + '</div>'
                + '<div class="nw-card"><h3>📲 Mit deinem Nexus-Konto angemeldet</h3>' + (d.nexus.length ? d.nexus.map(n => '<div class="nw-row nxp-line"><b>' + esc(n.name) + '</b><span class="nw-muted">seit ' + esc(day(n.created)) + '</span><span class="sp"></span><span class="nw-muted">' + esc(ago(n.last_seen)) + '</span><button type="button" class="btn btn-sm" data-devrm="' + esc(n.id) + '">Abmelden</button></div>').join('')
                    : '<p class="nw-muted">Keine App-Geräte angemeldet.</p>') + '<p class="nw-muted">Ein abgemeldetes Gerät muss sich in der App neu mit dem Nexus-Konto verbinden.</p></div>';
            $$('[data-devrm]', b).forEach(x => x.onclick = () => confirm('Dieses Gerät vom Nexus-Konto abmelden?') && api('nxp_device_remove', { id: x.dataset.devrm }).then(r => { toast(r.msg); devices(); }).catch(e => toast(e.message, true)));
        }).catch(fail(b));
    }

    // ---------------- 💓 Puls-Verlauf ----------------
    function chart(d) {
        const W = 760, H = 220, P = 28, pts = d.points;
        if (!pts.length) return '<div class="nw-muted nxp-empty">Noch keine Pulswerte in diesem Zeitraum. VRC Nexus (Desktop) schickt den Puls mit, sobald HypeRate, Pulsoid oder eine Uhr verbunden ist.</div>';
        const lo = Math.max(30, Math.min(...pts.map(p => p.lo)) - 5), hi = Math.min(230, Math.max(...pts.map(p => p.hi)) + 5);
        const t0 = d.from, t1 = Math.floor(Date.now() / 1000);
        const x = t => P + (t - t0) / Math.max(1, t1 - t0) * (W - P - 6), y = v => H - P - (v - lo) / Math.max(1, hi - lo) * (H - P - 8);
        const band = pts.map(p => x(p.t) + ',' + y(p.hi)).join(' ') + ' ' + pts.slice().reverse().map(p => x(p.t) + ',' + y(p.lo)).join(' ');
        const line = pts.map(p => x(p.t).toFixed(1) + ',' + y(p.avg).toFixed(1)).join(' ');
        const grid = [];
        for (let v = Math.ceil(lo / 20) * 20; v <= hi; v += 20) grid.push('<line x1="' + P + '" x2="' + W + '" y1="' + y(v) + '" y2="' + y(v) + '"/><text x="2" y="' + (y(v) + 4) + '">' + v + '</text>');
        return '<svg class="nxp-chart" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" role="img" aria-label="Pulsverlauf"><g class="g">' + grid.join('') + '</g>'
            + '<polygon class="band" points="' + band + '"/><polyline class="avg" points="' + line + '"/>'
            + '<text x="' + P + '" y="' + (H - 6) + '">' + esc(new Date(t0 * 1000).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })) + '</text><text x="' + (W - 4) + '" y="' + (H - 6) + '" text-anchor="end">jetzt</text></svg>';
    }
    function heart() {
        const b = box();
        const draw = () => api('nxp_heart', { range: heartRange }).then(d => {
            const s = d.stats;
            b.innerHTML = '<div class="nw-card"><div class="nw-row"><h3 style="margin:0">💓 Puls-Verlauf</h3><span class="sp"></span>'
                + [['day', '24 Stunden'], ['week', '7 Tage'], ['month', '30 Tage']].map(([k, l]) => '<button type="button" class="chip' + (heartRange === k ? ' on' : '') + '" data-hr="' + k + '">' + l + '</button>').join('')
                + '<a class="btn btn-sm" href="nexus.php?heart_csv=1">⬇ CSV</a></div>'
                + '<div class="nxp-kpis">' + [['jetzt', d.current ? d.current + ' bpm' : '–'], ['Ruhepuls (ca.)', s ? s.rest + ' bpm' : '–'], ['Durchschnitt', s ? s.avg + ' bpm' : '–'], ['Höchstwert', s ? s.max + ' bpm' : '–'], ['Tiefstwert', s ? s.min + ' bpm' : '–'], ['Messwerte', s ? s.samples.toLocaleString('de-DE') : '0']]
                    .map(([l, v]) => '<div><b>' + v + '</b><span>' + l + '</span></div>').join('') + '</div>'
                + chart(d)
                + (s ? '<div class="nxp-zones">' + d.zones.map(z => '<div class="z-' + z.name + '" style="flex:' + Math.max(z.share, 1) + '" title="' + esc(z.name + ': ' + z.share + ' %') + '"><span>' + esc(z.name) + ' ' + z.share + ' %</span></div>').join('') + '</div>'
                    + '<p class="nw-muted">Zonen: ruhig &lt; 90 · leicht 90–109 · aktiv 110–134 · hoch ab 135 bpm. Kein Medizinprodukt – nur zur eigenen Übersicht.</p>' : '')
                + '<p class="nw-muted">Pulswerte werden 30 Tage aufbewahrt (höchstens einer je 20 Sekunden) und sind nur für dich sichtbar.</p></div>';
            $$('[data-hr]', b).forEach(x => x.onclick = () => { heartRange = x.dataset.hr; draw(); });
        }).catch(fail(b));
        draw();
        stop(); timer = setInterval(() => { if (!document.hidden && !b.closest('[hidden]')) draw(); }, 30000);
    }

    // ---------------- 📆 Wochenrückblick ----------------
    function delta(v, fmt) {
        if (!v) return '<em class="nxp-d">±0</em>';
        return '<em class="nxp-d ' + (v > 0 ? 'up' : 'down') + '">' + (v > 0 ? '▲ ' : '▼ ') + (fmt ? fmt(Math.abs(v)) : Math.abs(v)) + '</em>';
    }
    function week() {
        const b = box();
        api('nxp_week', { back: weekBack }).then(d => {
            const c = d.cur, f = new Date(d.from * 1000), t = new Date((d.to - 1) * 1000);
            const range = f.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' }) + ' – ' + t.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
            b.innerHTML = '<div class="nw-card"><div class="nw-row"><button type="button" class="btn btn-sm" data-wk="1">‹ Vorwoche</button><h3 style="margin:0;flex:1;text-align:center">📆 KW ' + d.week + ' · ' + esc(range) + '</h3>'
                + '<button type="button" class="btn btn-sm" data-wk="-1"' + (d.back ? '' : ' disabled') + '>Nächste ›</button></div>'
                + (d.has_client ? '' : '<p class="nxp-warn">Für Spielzeit, Welten und Leute braucht es den Desktop-Client (VRC Nexus) – unter „🎮 Spiel &amp; OSC“ verbinden.</p>')
                + '<div class="nxp-kpis">'
                + '<div><b>' + hm(c.playtime) + '</b><span>Spielzeit ' + delta(d.diff.playtime, hm) + '</span></div>'
                + '<div><b>' + c.worlds + '</b><span>Welten ' + delta(d.diff.worlds) + '</span></div>'
                + '<div><b>' + c.people + '</b><span>Leute getroffen ' + delta(d.diff.people) + '</span></div>'
                + '<div><b>' + c.new_people + '</b><span>davon zum ersten Mal</span></div>'
                + '<div><b>' + c.screenshots + '</b><span>Screenshots ' + delta(d.diff.screenshots) + '</span></div>'
                + '<div><b>' + c.avatars + '</b><span>Avatar-Wechsel</span></div>'
                + '<div><b>' + c.club_events + '</b><span>Club-Events zugesagt</span></div>'
                + '<div><b>' + c.posts + '</b><span>Community-Beiträge</span></div>'
                + (c.heart ? '<div><b>' + c.heart.avg + ' bpm</b><span>Puls ⌀ (max. ' + c.heart.max + ')</span></div>' : '') + '</div>'
                + (c.best_day ? '<p>🏆 Aktivster Tag: <b>' + esc(day(new Date(c.best_day.day + 'T12:00:00').getTime() / 1000)) + '</b> mit ' + hm(c.best_day.seconds) + '</p>' : '')
                + (c.top.length ? '<h4>🌐 Meiste Zeit verbracht in</h4><ol class="nxp-top">' + c.top.map(w => '<li><b>' + esc(w.name) + '</b> <span class="nw-muted">' + hm(w.seconds) + ' · ' + w.visits + '× betreten</span>' + (/^wrld_/.test(w.id) ? ' <a href="https://vrchat.com/home/world/' + encodeURIComponent(w.id) + '" target="_blank" rel="noopener noreferrer">↗</a>' : '') + '</li>').join('') + '</ol>' : '<p class="nw-muted">Keine Weltbesuche in dieser Woche.</p>')
                + '</div>';
            $$('[data-wk]', b).forEach(x => x.onclick = () => { weekBack = Math.max(0, Math.min(52, weekBack + Number(x.dataset.wk))); week(); });
        }).catch(fail(b));
    }

    // ---------------- 🗺 Welt-Wunschliste ----------------
    const PRIO = ['💤 irgendwann', '🙂 gern', '🔥 unbedingt'];
    function wish(data) {
        const b = box();
        const draw = d => {
            const list = d.items.filter(w => (wishOpen ? !w.visited : !!w.visited) && (!wishTag || w.tag === wishTag));
            b.innerHTML = '<div class="nw-card"><div class="nw-row"><h3 style="margin:0">🗺 Welt-Wunschliste</h3><span class="sp"></span><span class="nw-muted">' + d.open + ' offen</span>'
                + '<button type="button" class="btn btn-sm btn-gold" data-rand' + (d.open ? '' : ' disabled') + '>🎲 Zufällige Welt</button></div>'
                + '<div class="cl-form nxp-wadd"><label class="cl-wide">Welt-ID oder Link<input data-w="world" placeholder="wrld_… oder https://vrchat.com/home/world/wrld_…"></label>'
                + '<label>Priorität<select data-w="prio">' + PRIO.map((p, i) => '<option value="' + i + '"' + (i === 1 ? ' selected' : '') + '>' + p + '</option>').join('') + '</select></label>'
                + '<label>Schlagwort<input data-w="tag" maxlength="24" placeholder="z. B. Horror" list="nxpTags"></label>'
                + '<label class="cl-wide">Notiz<input data-w="note" maxlength="300" placeholder="Warum? Mit wem?"></label></div>'
                + '<datalist id="nxpTags">' + d.tags.map(t => '<option value="' + esc(t) + '">').join('') + '</datalist>'
                + '<div class="nw-row"><span class="nw-muted">Tipp: in jeder Welt-Ansicht gibt es „🗺 Wunschliste“. Besuchte Welten hakt der Desktop-Client automatisch ab.</span><span class="sp"></span><button type="button" class="btn btn-sm btn-gold" data-wadd>➕ Hinzufügen</button></div></div>'
                + '<div class="nw-row"><button type="button" class="chip' + (wishOpen ? ' on' : '') + '" data-wo="1">Offen</button><button type="button" class="chip' + (wishOpen ? '' : ' on') + '" data-wo="0">✔ Besucht</button>'
                + '<span class="sp"></span>' + (d.tags.length ? '<button type="button" class="chip' + (wishTag ? '' : ' on') + '" data-wt="">alle</button>' + d.tags.map(t => '<button type="button" class="chip' + (wishTag === t ? ' on' : '') + '" data-wt="' + esc(t) + '">#' + esc(t) + '</button>').join('') : '') + '</div>'
                + (list.length ? '<div class="nxp-wishes">' + list.map(w => '<div class="nw-card nxp-wish" data-wid="' + w.id + '">'
                    + (w.image ? '<img src="' + esc(w.image) + '" alt="" loading="lazy" referrerpolicy="no-referrer">' : '<div class="nxp-noimg">🌐</div>')
                    + '<div><b>' + esc(w.name) + '</b>' + (w.author ? ' <span class="nw-muted">von ' + esc(w.author) + '</span>' : '')
                    + '<div class="nw-muted">' + PRIO[w.prio] + (w.tag ? ' · #' + esc(w.tag) : '') + ' · seit ' + esc(day(w.created)) + (w.visited ? ' · ✔ besucht ' + esc(day(w.visited)) : '') + '</div>'
                    + (w.note ? '<div class="nw-text">' + esc(w.note) + '</div>' : '')
                    + '<div class="nw-row" style="margin-top:6px"><a class="btn btn-sm" href="https://vrchat.com/home/world/' + encodeURIComponent(w.world_id) + '" target="_blank" rel="noopener noreferrer">↗ VRChat</a>'
                    + '<button type="button" class="btn btn-sm" data-wv="' + (w.visited ? 0 : 1) + '">' + (w.visited ? '↺ wieder offen' : '✔ besucht') + '</button>'
                    + '<button type="button" class="btn btn-sm" data-wp>⇅ Priorität</button><button type="button" class="btn btn-sm" data-wn>✏ Notiz</button><button type="button" class="btn btn-sm btn-danger" data-wdel>🗑</button></div></div></div>').join('') + '</div>'
                    : '<div class="nw-muted nxp-empty">' + (wishOpen ? 'Noch nichts geplant – füge oben eine Welt hinzu.' : 'Noch keine besuchten Welten.') + '</div>');
            const v = k => $('[data-w="' + k + '"]', b).value;
            $('[data-wadd]', b).onclick = () => {
                const m = /wrld_[0-9a-f-]{36}/i.exec(v('world'));
                if (!m) return toast('Bitte eine Welt-ID (wrld_…) oder einen Welt-Link angeben.', true);
                api('nxp_wish_add', { world_id: m[0], prio: v('prio'), tag: v('tag'), note: v('note') }).then(r => { toast(r.msg); draw(r); }).catch(e => toast(e.message, true));
            };
            $('[data-rand]', b).onclick = () => {
                const open = d.items.filter(w => !w.visited && (!wishTag || w.tag === wishTag));
                if (!open.length) return;
                const pool = open.flatMap(w => Array(w.prio + 1).fill(w));   // höhere Priorität = öfter gezogen
                const w = pool[Math.floor(Math.random() * pool.length)];
                toast('🎲 Heute: ' + w.name);
                const el = $('[data-wid="' + w.id + '"]', b); if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); el.classList.add('nxp-hit'); setTimeout(() => el.classList.remove('nxp-hit'), 2500); }
            };
            $$('[data-wo]', b).forEach(x => x.onclick = () => { wishOpen = x.dataset.wo === '1'; draw(d); });
            $$('[data-wt]', b).forEach(x => x.onclick = () => { wishTag = x.dataset.wt; draw(d); });
            $$('[data-wid]', b).forEach(card => {
                const id = card.dataset.wid, w = d.items.find(x => String(x.id) === id);
                const upd = o => api('nxp_wish_update', Object.assign({ id }, o)).then(r => { toast(r.msg); draw(r); }).catch(e => toast(e.message, true));
                $('[data-wv]', card).onclick = e => upd({ visited: e.target.dataset.wv });
                $('[data-wp]', card).onclick = () => upd({ prio: (w.prio + 1) % 3 });
                $('[data-wn]', card).onclick = () => { const n = prompt('Notiz zu „' + w.name + '“', w.note || ''); if (n !== null) upd({ note: n }); };
                $('[data-wdel]', card).onclick = () => confirm('„' + w.name + '“ von der Wunschliste nehmen?') && api('nxp_wish_delete', { id }).then(r => { toast(r.msg); draw(r); }).catch(e => toast(e.message, true));
            });
        };
        if (data) return draw(data);
        api('nxp_wish').then(draw).catch(fail(b));
    }

    // ---------------- 🏅 Abzeichen & Vollständigkeit im Community-Profil ----------------
    // nexus-web.js legt im Profilkopf einen Platzhalter <div class="nxp-badges" data-badges="<VRChat-ID>"> an
    function badges(holder) {
        if (!holder) return;
        api('nxp_profile_extra', { id: holder.dataset.badges }).then(d => {
            holder.innerHTML = d.badges.map(x => '<span class="nxp-badge' + (x.tag ? ' nxp-tag' : '') + '" title="' + esc(x.desc) + '"' + (/^#[0-9a-f]{6}$/i.test(x.color || '') ? ' style="--tc:' + x.color + '"' : '') + '>' + esc(x.icon) + ' ' + esc(x.title) + '</span>').join('')
                + (d.score ? '<div class="nxp-score"><span>Profil zu <b>' + d.score.percent + ' %</b> vollständig</span><span class="nxp-meter"><i style="width:' + d.score.percent + '%"></i></span>'
                    + (d.score.missing.length ? '<span class="nw-muted">Fehlt noch: ' + d.score.missing.map(esc).join(', ') + '</span>' : '<span>🎉 Alles ausgefüllt!</span>') + '</div>' : '');
        }).catch(() => { holder.remove(); });
    }

    window.NexusPlus = {
        badges,
        open(name, sub) {
            if (name !== 'mynx') { stop(); return; }
            stop();
            const b = box(); b.innerHTML = '<div class="vx-loading">Lade …</div>';
            if (sub === 'heart') heart(); else if (sub === 'week') week(); else if (sub === 'wish') wish(); else devices();
        },
    };
    const cur = $('.vx-tab:not([hidden])');
    if (cur && cur.dataset.pane === 'mynx') { const bt = $('#tabs [data-tab="mynx"].active'); window.NexusPlus.open('mynx', bt ? bt.dataset.msub : ''); }
})();
