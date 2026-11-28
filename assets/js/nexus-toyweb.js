/* VRC Nexus Web 8.2 – 🔞 Spielzeug im Browser (nur Verified 18+ mit NSFW an; der Server prüft JEDE Anfrage).
 *   • Meine Spielzeuge: Regler, Muster, Überraschung, Verbindungstest – über VRC Nexus (Desktop) mit „Fernsteuerung empfangen“
 *   • Partner-Links: Ablaufzeit, Intensitäts-Obergrenze, erlaubte Muster, Grenzen + Safeword, Pausieren/Beenden
 *   • An mich freigegeben: erst Grenzen bestätigen, dann steuern (höchstens bis zur Obergrenze)
 *   • 🛑 Not-Stopp unten immer sichtbar (auch Esc / Umschalt+P), Stopp beim Verlassen der Seite, Protokoll nur für die Beteiligten
 * Alle Nutzertexte werden maskiert. */
(function () {
    const K = window.NexusWebKit;
    if (!K) return;
    const { api, toast, esc } = K;
    const $ = (s, r) => (r || document).querySelector(s), $$ = (s, r) => [...(r || document).querySelectorAll(s)];
    const lv = new Map();            // Ziel ('self' oder Link-ID) → Regler 0..1
    let st = null, root = null, beat = null, tick = null, stopBar = null;
    const left = s => { s = Math.max(0, s | 0); const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), x = s % 60; return h ? h + ' h ' + m + ' min' : m ? m + ' min ' + x + ' s' : x + ' s'; };
    const when = t => new Date(t * 1000).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const STATUS = { active: '🟢 aktiv', paused: '⏸ pausiert', expired: '⌛ abgelaufen', ended: '🛑 beendet' };
    const send = (target, c, extra) => api('nxp_toy_send', Object.assign({ target, c }, extra || {}));

    // ---------- Not-Stopp: immer sichtbar, sobald der Bereich einmal offen war ----------
    function stopAll(why) {
        const targets = new Set(['self', ...lv.keys(), ...((st && st.shared) || []).filter(l => l.status === 'active').map(l => l.id)]);
        lv.clear();
        $$('[data-lv]').forEach(r => { r.value = 0; const o = r.parentNode.querySelector('output'); if (o) o.textContent = '0 %'; });
        return Promise.allSettled([...targets].map(t => send(t, 'stop'))).then(r => {
            const ok = r.some(x => x.status === 'fulfilled');
            toast(ok ? '🛑 Not-Stopp: alles gestoppt.' + (why ? ' (' + why + ')' : '') : '⚠ Stopp nicht zugestellt – in VRC Nexus „Panik“ drücken.', !ok);
            if (root) load();
        });
    }
    function ensureStopBar() {
        if (stopBar) return;
        stopBar = document.createElement('button');
        stopBar.type = 'button'; stopBar.className = 'nxp-stopbar'; stopBar.innerHTML = '🛑 NOT-STOPP <small>Esc · Umschalt+P</small>';
        stopBar.onclick = () => stopAll();
        document.body.appendChild(stopBar);
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' || (e.shiftKey && (e.key === 'P' || e.key === 'p') && !/^(INPUT|TEXTAREA|SELECT)$/.test((e.target || {}).tagName))) { if (lv.size || (root && document.body.contains(root))) stopAll(); }
        });
        // Seite verlassen / Tab im Hintergrund: Regler aus (die App fällt ohne Lebenszeichen nach 15 s ohnehin auf 0)
        const bye = () => { for (const t of lv.keys()) { const b = new URLSearchParams({ act: 'nxp_toy_send', target: t, c: 'stop' }); try { navigator.sendBeacon('nexus.php', b); } catch (e) { /* egal */ } } lv.clear(); };
        window.addEventListener('pagehide', bye);
        document.addEventListener('visibilitychange', () => { if (document.hidden && lv.size) bye(); });
        // Lebenszeichen alle 5 s für jeden Regler > 0
        beat = setInterval(() => { for (const [t, v] of lv) if (v > 0) send(t, 'set', { lv: v }).catch(e => { lv.delete(t); toast('⚠ ' + e.message, true); load(); }); }, 5000);
    }

    // ---------- Bausteine ----------
    function patternForm(target, allowed, cap, surprise) {
        const ids = Object.keys(st.patterns).filter(k => !allowed || !allowed.length || allowed.includes(k));
        return '<div class="nxp-ctl" data-target="' + esc(target) + '">'
            + '<label class="nxp-slider">Stärke <input type="range" min="0" max="' + cap + '" value="' + Math.round((lv.get(target) || 0) * 100) + '" data-lv><output>' + Math.round((lv.get(target) || 0) * 100) + ' %</output></label>'
            + '<div class="nw-row"><select data-pat>' + ids.map(k => '<option value="' + esc(k) + '">' + esc(st.patterns[k]) + '</option>').join('') + '</select>'
            + '<label>Stärke <input type="number" data-pstr min="5" max="' + cap + '" value="' + Math.min(cap, 60) + '" style="width:64px"> %</label>'
            + '<label>Dauer <select data-psec><option value="30">30 s</option><option value="60">1 min</option><option value="120" selected>2 min</option><option value="300">5 min</option><option value="600">10 min</option></select></label>'
            + '<label>Tempo <select data-pspd><option value="0.6">langsam</option><option value="1" selected>normal</option><option value="1.6">schnell</option></select></label>'
            + '<button type="button" class="btn btn-sm btn-gold" data-pgo>🌊 Muster starten</button>'
            + (surprise ? '<button type="button" class="btn btn-sm" data-surp>🎁 Überraschung</button>' : '')
            + '<button type="button" class="btn btn-sm" data-ping>📡 Test</button><button type="button" class="btn btn-sm btn-danger" data-stop>⏹ Stopp</button></div></div>';
    }
    function bindCtl(el) {
        const target = el.dataset.target, r = $('[data-lv]', el), out = $('output', el);
        let t = null;
        r.oninput = () => {
            out.textContent = r.value + ' %';
            const v = Number(r.value) / 100;
            if (v > 0) lv.set(target, v); else lv.delete(target);
            clearTimeout(t); t = setTimeout(() => send(target, v > 0 ? 'set' : 'stop', { lv: v }).then(d => { if (d.lv !== null && d.lv !== undefined && d.lv !== Number(r.value)) { r.value = d.lv; out.textContent = d.lv + ' %'; } }).catch(e => { lv.delete(target); r.value = 0; out.textContent = '0 %'; toast('⚠ ' + e.message, true); }), 250);
        };
        $('[data-pgo]', el).onclick = () => send(target, 'pattern', { id: $('[data-pat]', el).value, strength: $('[data-pstr]', el).value, seconds: $('[data-psec]', el).value, speed: $('[data-pspd]', el).value }).then(d => toast('🌊 ' + d.msg)).catch(e => toast('⚠ ' + e.message, true));
        const s = $('[data-surp]', el); if (s) s.onclick = () => send(target, 'surprise').then(d => toast('🎁 ' + d.msg)).catch(e => toast('⚠ ' + e.message, true));
        $('[data-ping]', el).onclick = () => send(target, 'ping').then(d => toast('📡 ' + d.msg + ' Die Antwort siehst du in VRC Nexus.')).catch(e => toast('⚠ ' + e.message, true));
        $('[data-stop]', el).onclick = () => { lv.delete(target); r.value = 0; out.textContent = '0 %'; send(target, 'stop').then(d => toast(d.msg)).catch(e => toast('⚠ ' + e.message, true)); };
    }
    function showLog(id, title) {
        const dlg = document.createElement('div'); dlg.className = 'nxp-modal';
        dlg.innerHTML = '<div class="nw-card"><div class="nw-row"><h3 style="margin:0">📜 ' + esc(title) + '</h3><span class="sp"></span><button type="button" class="btn btn-sm" data-x>Schließen</button></div><div data-body class="nw-muted">Lade …</div></div>';
        document.body.appendChild(dlg);
        const close = () => dlg.remove();
        $('[data-x]', dlg).onclick = close; dlg.onclick = e => { if (e.target === dlg) close(); };
        const draw = d => {
            $('[data-body]', dlg).innerHTML = '<p class="nw-muted">Nur ihr beide seht dieses Protokoll. Einträge werden nach 30 Tagen gelöscht.</p>'
                + (d.items.length ? '<table class="nxp-log"><tbody>' + d.items.map(x => '<tr><td>' + esc(when(x.ts)) + '</td><td><b>' + esc(x.who) + '</b></td><td>' + esc(x.detail || x.cmd) + '</td></tr>').join('') + '</tbody></table>' : '<p>Noch keine Einträge.</p>')
                + (d.can_clear && d.items.length ? '<button type="button" class="btn btn-sm btn-danger" data-clr>🗑 Protokoll löschen</button>' : '');
            const c = $('[data-clr]', dlg); if (c) c.onclick = () => confirm('Protokoll wirklich löschen?') && api('nxp_toy_log_clear', { id }).then(r => { toast(r.msg); draw(r); }).catch(e => toast(e.message, true));
        };
        api('nxp_toy_log', { id }).then(draw).catch(e => { $('[data-body]', dlg).innerHTML = '<div class="vx-error">' + esc(e.message) + '</div>'; });
    }

    // ---------- Ansicht ----------
    function render() {
        const d = st, focus = window.NexusToyLink || '';
        const linkUrl = id => location.origin + location.pathname + '?toylink=' + id;
        const mine = d.mine.map(l => '<div class="nw-card nxp-link' + (focus === l.id ? ' nxp-hit' : '') + '" data-lid="' + esc(l.id) + '"><div class="nw-row"><b>🔗 für ' + esc(l.partner_name || l.partner) + '</b><span class="nw-muted">' + (STATUS[l.status] || esc(l.status)) + '</span><span class="sp"></span>'
            + (['active', 'paused'].includes(l.status) ? '<span class="nw-muted" data-left="' + l.expires + '">noch ' + left(l.left) + '</span>' : '') + '</div>'
            + '<div class="nw-muted">max. <b>' + l.cap + ' %</b> · ' + (l.patterns.length ? 'Muster: ' + l.patterns.map(p => esc(d.patterns[p] || p)).join(', ') : 'alle Muster') + (l.surprise ? ' · 🎁 Überraschung erlaubt' : '')
            + ' · ' + (l.accepted ? '✅ Grenzen bestätigt' : '⏳ wartet auf Bestätigung') + (l.last_used ? ' · zuletzt ' + esc(when(l.last_used)) : '') + '</div>'
            + (l.limits ? '<div class="nw-text">🚫 ' + esc(l.limits) + '</div>' : '') + (l.safeword ? '<div class="nw-muted">🛑 Safeword: <b>' + esc(l.safeword) + '</b></div>' : '')
            + '<div class="nw-row" style="margin-top:6px">' + (['active', 'paused'].includes(l.status) ? '<button type="button" class="btn btn-sm" data-copy="' + esc(linkUrl(l.id)) + '">📋 Link kopieren</button>'
                + (l.status === 'active' ? '<button type="button" class="btn btn-sm" data-do="pause">⏸ Pausieren</button>' : '<button type="button" class="btn btn-sm" data-do="resume">▶ Fortsetzen</button>')
                + '<button type="button" class="btn btn-sm btn-danger" data-do="end">🛑 Beenden</button>' : '') + '<button type="button" class="btn btn-sm" data-log>📜 Protokoll</button></div></div>').join('');
        const shared = d.shared.map(l => {
            const open = l.status === 'active';
            return '<div class="nw-card nxp-link' + (focus === l.id ? ' nxp-hit' : '') + '" data-lid="' + esc(l.id) + '"><div class="nw-row"><b>💞 ' + esc(l.owner_name || l.owner) + '</b><span class="nw-muted">' + (STATUS[l.status] || esc(l.status)) + '</span>'
                + (l.reachable ? '' : '<span class="nw-muted">· 📴 App gerade nicht erreichbar</span>') + '<span class="sp"></span>' + (['active', 'paused'].includes(l.status) ? '<span class="nw-muted" data-left="' + l.expires + '">noch ' + left(l.left) + '</span>' : '') + '</div>'
                + '<div class="nw-muted">Obergrenze <b>' + l.cap + ' %</b>' + (l.patterns.length ? ' · Muster: ' + l.patterns.map(p => esc(d.patterns[p] || p)).join(', ') : '') + '</div>'
                + '<div class="nxp-terms">' + (l.limits ? '<div>🚫 <b>Grenzen:</b> ' + esc(l.limits) + '</div>' : '<div class="nw-muted">Keine Grenzen angegeben – fragt im Zweifel nach.</div>') + (l.safeword ? '<div>🛑 <b>Safeword:</b> ' + esc(l.safeword) + ' – sofort Not-Stopp.</div>' : '') + '</div>'
                + (open && !l.accepted ? '<label class="cl-chk"><input type="checkbox" data-agree> Ich habe die Grenzen und das Safeword gelesen und halte mich daran.</label><button type="button" class="btn btn-sm btn-gold" data-do="accept" disabled>✅ Bestätigen</button>' : '')
                + (open && l.accepted ? patternForm(l.id, l.patterns, l.cap, l.surprise) : '')
                + '<div class="nw-row" style="margin-top:6px"><span class="sp"></span>' + (['active', 'paused'].includes(l.status) ? '<button type="button" class="btn btn-sm btn-danger" data-do="end">🛑 Beenden</button>' : '') + '<button type="button" class="btn btn-sm" data-log>📜 Protokoll</button></div></div>';
        }).join('');
        root.innerHTML = '<div class="nw-card"><div class="nw-row"><h3 style="margin:0">🎮 Meine Spielzeuge</h3><span class="sp"></span><span class="nw-muted">' + (d.reachable ? '🟢 VRC Nexus empfängt über das Internet' : '📴 VRC Nexus empfängt gerade nicht') + '</span><button type="button" class="btn btn-sm" data-log-self>📜 Protokoll</button></div>'
            + (d.reachable ? patternForm('self', null, 100, true) : '<p class="nw-muted">In VRC Nexus (Desktop): 🔞 → Spielzeug → „Fernsteuerung empfangen“ und „über das Internet“ einschalten. Die Höchststärke stellst du dort ein – sie gilt immer zusätzlich.</p>') + '</div>'
            + '<div class="nw-card"><h3>🔗 Partner-Link erstellen</h3><p class="nw-muted">Nur für Verbindungen (beide haben einer 18+-Anfrage zugestimmt). Dein Gegenüber muss in VRC Nexus auf deiner Freigabeliste stehen – die App prüft das selbst. Die Höchststärke in VRC Nexus gilt immer zusätzlich (der Regler ist ein Anteil davon).</p>'
            + (d.connections.length ? '<div class="cl-form"><label>Gegenüber<select data-l="partner">' + d.connections.map(c => '<option value="' + esc(c.vrc_id) + '">' + esc(c.name || c.vrc_id) + '</option>').join('') + '</select></label>'
                + '<label>Gültig für<select data-l="minutes">' + [[15, '15 min'], [30, '30 min'], [60, '1 Stunde'], [120, '2 Stunden'], [240, '4 Stunden'], [480, '8 Stunden'], [720, '12 Stunden']].map(([v, t]) => '<option value="' + v + '"' + (v === 60 ? ' selected' : '') + '>' + t + '</option>').join('') + '</select></label>'
                + '<label class="nxp-slider">Obergrenze <input type="range" min="5" max="100" step="5" value="50" data-l="cap"><output>50 %</output></label>'
                + '<label class="cl-chk"><input type="checkbox" data-l="surprise" disabled> 🎁 Überraschungen erlauben (erst ab 90 %)</label>'
                + '<div class="cl-wide nxp-pats"><span class="nw-muted">Erlaubte Muster (keins gewählt = alle):</span>' + Object.entries(d.patterns).map(([k, n]) => '<label class="cl-chk"><input type="checkbox" data-pat-ok value="' + esc(k) + '"> ' + esc(n) + '</label>').join('') + '</div>'
                + '<label class="cl-wide">Grenzen (dein Gegenüber bestätigt sie vor dem ersten Befehl)<textarea data-l="limits" rows="2" maxlength="500" placeholder="z. B. nur sanft, Pausen wenn ich es sage"></textarea></label>'
                + '<label>Safeword<input data-l="safeword" maxlength="40" placeholder="z. B. Rot"></label></div><div class="nw-row"><span class="sp"></span><button type="button" class="btn btn-sm btn-gold" data-create>🔗 Link erstellen</button></div>'
                : '<p class="nw-muted">Noch keine Verbindung – unter „💌 Anfragen &amp; Consent“ eine 18+-Anfrage schicken.</p>') + '</div>'
            + (mine ? '<h3 class="nw-muted" style="margin:6px 0 0">Meine Links</h3>' + mine : '')
            + '<h3 class="nw-muted" style="margin:6px 0 0">💞 An mich freigegeben</h3>' + (shared || '<div class="nw-card nw-muted">Niemand hat dir gerade ein Spielzeug freigegeben.</div>');
        // binden
        $$('.nxp-ctl', root).forEach(bindCtl);
        $('[data-log-self]', root).onclick = () => showLog('self', 'Meine Spielzeuge');
        const cap = $('[data-l="cap"]', root);
        if (cap) cap.oninput = () => { cap.nextElementSibling.textContent = cap.value + ' %'; const s = $('[data-l="surprise"]', root); s.disabled = Number(cap.value) < 90; if (s.disabled) s.checked = false; };
        const cr = $('[data-create]', root);
        if (cr) cr.onclick = () => {
            const v = k => $('[data-l="' + k + '"]', root);
            api('nxp_toy_link_create', { partner: v('partner').value, minutes: v('minutes').value, cap: v('cap').value, surprise: v('surprise').checked ? 1 : '', limits: v('limits').value, safeword: v('safeword').value,
                patterns: JSON.stringify($$('[data-pat-ok]:checked', root).map(x => x.value)) }).then(r => { toast(r.msg); st = r; window.NexusToyLink = r.created; render(); }).catch(e => toast('⚠ ' + e.message, true));
        };
        $$('[data-lid]', root).forEach(card => {
            const id = card.dataset.lid, l = d.mine.concat(d.shared).find(x => x.id === id);
            $$('[data-do]', card).forEach(b => b.onclick = () => {
                if (b.dataset.do === 'end' && !confirm('Link beenden? Das Spielzeug wird sofort gestoppt.')) return;
                if (b.dataset.do === 'end') lv.delete(id);
                api('nxp_toy_link', { id, do: b.dataset.do }).then(r => { toast(r.msg); st = r; render(); }).catch(e => toast('⚠ ' + e.message, true));
            });
            const ag = $('[data-agree]', card); if (ag) ag.onchange = () => { $('[data-do="accept"]', card).disabled = !ag.checked; };
            const cp = $('[data-copy]', card); if (cp) cp.onclick = () => (navigator.clipboard ? navigator.clipboard.writeText(cp.dataset.copy) : Promise.reject(new Error('Kopieren geht hier nicht'))).then(() => toast('📋 Link kopiert – nur ' + (l.partner_name || 'dein Gegenüber') + ' kann ihn nutzen.')).catch(() => prompt('Link:', cp.dataset.copy));
            $('[data-log]', card).onclick = () => showLog(id, l.role === 'owner' ? 'Link für ' + (l.partner_name || l.partner) : 'Spielzeug von ' + (l.owner_name || l.owner));
        });
        const hit = $('.nxp-hit', root); if (hit) { hit.scrollIntoView({ block: 'center' }); window.NexusToyLink = ''; }
    }
    function load() {
        if (!root) return;
        return api('nxp_toy_status').then(d => { st = d; if (root && document.body.contains(root)) render(); }).catch(e => { root.innerHTML = '<div class="vx-error">' + esc(e.message) + '</div>'; });
    }

    window.NexusToyWeb = {
        render(box) {
            root = box; ensureStopBar();
            box.innerHTML = '<div class="vx-loading">Lade …</div>';
            load();
            clearInterval(tick);
            // Restzeit herunterzählen, alle 30 s neu laden (Ablauf, Pause durch die andere Seite)
            let n = 0;
            tick = setInterval(() => {
                if (!root || !document.body.contains(root)) { clearInterval(tick); return; }
                $$('[data-left]', root).forEach(el => { const s = Number(el.dataset.left) - Math.floor(Date.now() / 1000); el.textContent = s > 0 ? 'noch ' + left(s) : '⌛ abgelaufen'; });
                if (++n % 30 === 0 && !lv.size) load();
            }, 1000);
        },
        stopAll,
    };
})();
