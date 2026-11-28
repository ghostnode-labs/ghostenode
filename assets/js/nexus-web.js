/* VRC Nexus Web – 🫂 Community, 📡 Nexus Pulse und ❤️ 18+ / ERP im Browser (gleiches Nexus-Konto wie die App).
 * Alle Texte von Nutzern werden maskiert; NSFW prüft der Server bei jeder Anfrage. */
(function () {
    const K = window.NexusWebKit;
    if (!K || !document.getElementById('nwComm')) return;
    const { api, toast, esc } = K;
    const $ = (s, r) => (r || document).querySelector(s);
    const $$ = (s, r) => [...(r || document).querySelectorAll(s)];
    const when = ts => { const d = Math.floor(Date.now() / 1000) - ts; if (d < 60) return 'gerade eben'; if (d < 3600) return 'vor ' + Math.floor(d / 60) + ' min'; if (d < 86400) return 'vor ' + Math.floor(d / 3600) + ' h';
        return new Date(ts * 1000).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: d > 300 * 86400 ? '2-digit' : undefined }); };
    const media = id => 'nexus.php?cmedia=' + encodeURIComponent(id);
    let me = null, meP = null;
    const ME = () => meP || (meP = api('nw_me').then(d => { me = d; applyMe(); return d; }).catch(e => { meP = null; throw e; }));

    function applyMe() {
        $$('.cm-mod-only').forEach(b => { b.hidden = !me.mod; });
        const erp = $('#tabs [data-tab=erp]'); if (erp) erp.hidden = !(me.account && me.account.verified18);
        if (window.NexusSubNav) window.NexusSubNav();
        inboxBadge();
    }
    function inboxBadge() {
        api('nw_inbox').then(d => {
            const top = $$('#tabs .vx-mtop').find(b => /Community/.test(b.textContent));
            if (!top) return;
            let bd = $('.vx-badge', top);
            if (!d.unread) { if (bd) bd.remove(); return; }
            if (!bd) { bd = document.createElement('b'); bd.className = 'vx-badge'; top.appendChild(bd); }
            bd.textContent = d.unread;
        }).catch(() => {});
    }
    const gate = (box, title, text) => { box.innerHTML = '<div class="nw-card nw-gate"><b>' + esc(title) + '</b><span class="nw-muted">' + esc(text) + '</span></div>'; };

    // ---------- Text mit Links & Hashtags ----------
    function rich(t) {
        return esc(t).replace(/https:\/\/[^\s<]+/g, u => '<a href="' + u + '" target="_blank" rel="noopener noreferrer">' + u + '</a>')
            .replace(/(^|[^\p{L}\p{N}_&#])#([\p{L}\p{N}_]{2,32})/gu, (m, a, g) => a + '<span class="tag" data-tag="' + g.toLowerCase() + '">#' + g + '</span>');
    }

    // =====================================================================
    // 🫂 Community
    // =====================================================================
    const cs = { sub: 'feed', rating: 'sfw', q: '', tag: '', items: [], end: false, author: '', box: null };
    function postHtml(p, reply) {
        const name = p.name || p.author.slice(0, 12);
        const reacts = (me.reactions || []).map(e => { const n = (p.reactions || {})[e] || 0, on = (p.mine || []).includes(e); return n || on ? '<button type="button" data-react="' + e + '" class="' + (on ? 'on' : '') + '">' + e + ' ' + n + '</button>' : ''; }).join('');
        return '<article class="nw-post' + (p.rating === 'nsfw' ? ' nsfw' : '') + (p.status === 'pending' ? ' pending' : '') + '" data-id="' + p.id + '" data-author="' + esc(p.author) + '">'
            + '<div class="nw-ava">' + esc(name.slice(0, 1).toUpperCase()) + '</div><div>'
            + '<div class="nw-head"><b data-prof="' + esc(p.author) + '">' + esc(name) + '</b><time>' + when(p.created) + (p.edited ? ' · bearbeitet' : '') + '</time>' + (p.status === 'pending' ? '<span class="nw-muted">⏳ wartet auf Freigabe</span>' : '') + '</div>'
            + (p.status === 'deleted' ? '<div class="nw-text nw-muted">🗑 gelöscht</div>' : '<div class="nw-text">' + rich(p.text) + '</div>')
            + (p.media && p.media.length ? '<div class="nw-media">' + p.media.map(m => '<img src="' + media(m) + '" alt="" loading="lazy" data-big="' + esc(m) + '">').join('') + '</div>' : '')
            + '<div class="nw-acts">' + reacts + '<button type="button" data-addreact>😊＋</button>'
            + (reply ? '' : '<button type="button" data-thread>💬 ' + (p.replies || 0) + '</button><button type="button" data-save class="' + (p.saved ? 'on' : '') + '">🔖' + (p.saved ? ' gemerkt' : '') + '</button>')
            + (p.own ? '<button type="button" data-edit>✏</button><button type="button" data-del>🗑</button>' : '<button type="button" data-report title="Melden">⚠</button>')
            + (me.mod && !p.own ? '<button type="button" data-modhide title="Ausblenden (Moderation)">🛡</button>' : '')
            + '</div><div class="nw-thread" hidden></div></div></article>';
    }
    function composer(rating, parent) {
        return '<div class="nw-card nw-compose" data-rating="' + rating + '"' + (parent ? ' data-parent="' + parent + '"' : '') + '>'
            + '<textarea maxlength="2000" placeholder="' + (parent ? 'Antworten …' : rating === 'nsfw' ? '🔞 Etwas im NSFW-Feed teilen … (#Hashtags gehen auch)' : 'Was gibt\'s Neues? #Hashtags, Links und VRChat-IDs gehen auch') + '"></textarea>'
            + '<div class="nw-row" style="margin-top:8px"><label class="btn btn-sm" style="cursor:pointer">🖼 Bild<input type="file" accept="image/png,image/jpeg,image/webp,image/gif" hidden data-file></label>'
            + '<span class="nw-muted" data-files></span><span class="sp"></span><button type="button" class="btn btn-sm btn-gold" data-send>' + (parent ? 'Antworten' : 'Posten') + '</button></div></div>';
    }
    function bindComposer(el, after) {
        const media = [];
        $('[data-file]', el).onchange = e => {
            const f = e.target.files[0]; e.target.value = '';
            if (!f) return;
            if (f.size > 5 * 1048576) return toast('Bild zu groß (max. 5 MB).', true);
            if (media.length >= 4) return toast('Höchstens 4 Bilder.', true);
            const r = new FileReader();
            r.onload = () => api('nw_upload', { data: String(r.result).split(',')[1], rating: el.dataset.rating }).then(d => { media.push(d.id); $('[data-files]', el).textContent = '🖼 ' + media.length + ' Bild(er)'; if (d.msg) toast(d.msg); }).catch(x => toast('⚠ ' + x.message, true));
            r.readAsDataURL(f);
        };
        $('[data-send]', el).onclick = () => {
            const t = $('textarea', el);
            api('nw_post', { text: t.value, rating: el.dataset.rating, parent: el.dataset.parent || 0, media: JSON.stringify(media) })
                .then(d => { t.value = ''; media.length = 0; $('[data-files]', el).textContent = ''; toast(d.msg || 'Gepostet.'); after && after(d.post); })
                .catch(x => toast('⚠ ' + x.message, true));
        };
    }
    function bindPosts(root, reload) {
        root.onclick = e => {
            const art = e.target.closest('.nw-post'); const id = art && art.dataset.id;
            const tg = e.target.closest('[data-tag]'); if (tg) { cs.tag = tg.dataset.tag; cs.q = ''; return openComm(cs.rating === 'nsfw' ? 'erp-feed' : 'feed'); }
            const pf = e.target.closest('[data-prof]'); if (pf) return showProfile(pf.dataset.prof);
            const big = e.target.closest('[data-big]'); if (big) return window.open(media(big.dataset.big), '_blank', 'noopener');
            if (!id) return;
            const b = e.target.closest('button'); if (!b) return;
            if (b.dataset.react) return api('nw_react', { id, emoji: b.dataset.react }).then(reload).catch(x => toast('⚠ ' + x.message, true));
            if (b.hasAttribute('data-addreact')) {
                const pick = document.createElement('span'); pick.className = 'nw-row';
                pick.innerHTML = (me.reactions || []).map(x => '<button type="button" data-react="' + x + '">' + x + '</button>').join('');
                b.replaceWith(pick); return;
            }
            if (b.hasAttribute('data-save')) return api('nw_save', { id, on: b.classList.contains('on') ? '' : '1' }).then(d => { toast(d.msg); b.classList.toggle('on', d.saved); b.textContent = '🔖' + (d.saved ? ' gemerkt' : ''); }).catch(x => toast('⚠ ' + x.message, true));
            if (b.hasAttribute('data-thread')) return toggleThread(art);
            if (b.hasAttribute('data-del')) { if (confirm('Beitrag löschen?')) api('nw_delete', { id }).then(reload).catch(x => toast('⚠ ' + x.message, true)); return; }
            if (b.hasAttribute('data-edit')) { const old = $('.nw-text', art).textContent; const t = prompt('Beitrag bearbeiten:', old); if (t != null && t !== old) api('nw_edit', { id, text: t }).then(reload).catch(x => toast('⚠ ' + x.message, true)); return; }
            if (b.hasAttribute('data-report')) { const r = prompt('Warum meldest du diesen Beitrag? (geht an die Moderation)'); if (r) api('nw_report', { id, reason: r }).then(() => toast('Danke – gemeldet.')).catch(x => toast('⚠ ' + x.message, true)); return; }
            if (b.hasAttribute('data-modhide')) { const n = prompt('Ausblenden – Notiz für das Protokoll:'); if (n != null) api('nw_mod', { do: 'hide', data: JSON.stringify({ id: +id, note: n }) }).then(reload).catch(x => toast('⚠ ' + x.message, true)); }
        };
    }
    function toggleThread(art) {
        const box = $('.nw-thread', art);
        if (!box.hidden) { box.hidden = true; return; }
        box.hidden = false; box.innerHTML = '<div class="vx-loading">Lade …</div>';
        const load = () => api('nw_thread', { id: art.dataset.id }).then(d => {
            box.innerHTML = '<div class="nw-replies">' + d.replies.map(r => postHtml(r, true)).join('') + '</div>' + composer(d.post.rating, d.post.id);
            bindComposer($('.nw-compose', box), load);
            const c = $('[data-thread]', art); if (c) c.textContent = '💬 ' + d.replies.length;
        }).catch(x => { box.innerHTML = '<div class="vx-error">' + esc(x.message) + '</div>'; });
        load();
    }

    function openComm(sub) {
        const erp = sub.startsWith('erp-');
        const box = erp ? $('#nwErpBody') : $('#nwComm');
        if (!box) return;
        cs.sub = erp ? sub.slice(4) : sub; cs.rating = erp ? 'nsfw' : 'sfw'; cs.box = box; cs.author = '';
        if (cs.sub === 'profile') return showProfile(me.account.vrc_id, box);
        if (cs.sub === 'mod') return showMod(box);
        const mode = cs.sub === 'following' ? 'following' : cs.sub === 'saved' ? 'saved' : '';
        box.innerHTML = '<div class="nw-cols"><div class="nw">'
            + (mode ? '' : composer(cs.rating))
            + '<div class="nw-row"><input type="search" id="nwQ" placeholder="🔍 Beiträge durchsuchen …" value="' + esc(cs.q) + '" style="flex:1;min-width:180px">'
            + (cs.tag ? '<button type="button" class="chip on" id="nwTagX">#' + esc(cs.tag) + ' ✕</button>' : '') + '</div>'
            + '<div class="nw" id="nwList"><div class="vx-loading">Lade …</div></div><button type="button" class="btn btn-sm" id="nwMore" hidden>Ältere laden</button></div>'
            + '<aside class="nw"><div class="nw-card"><h3>💬 Antworten an mich</h3><div id="nwInbox" class="nw-trend nw-muted">Lade …</div></div>'
            + '<div class="nw-card"><h3>🔥 Trends (7 Tage)</h3><div id="nwTrends" class="nw-trend nw-muted">Lade …</div></div></aside></div>';
        if (!mode) bindComposer($('.nw-compose', box), () => loadFeed(true));
        let qt; $('#nwQ', box).oninput = e => { clearTimeout(qt); qt = setTimeout(() => { cs.q = e.target.value.trim(); loadFeed(true); }, 350); };
        if ($('#nwTagX', box)) $('#nwTagX', box).onclick = () => { cs.tag = ''; openComm(sub); };
        $('#nwMore', box).onclick = () => loadFeed(false);
        bindPosts($('#nwList', box), () => loadFeed(true));
        cs.mode = mode;
        loadFeed(true);
        api('nw_trends', { rating: cs.rating }).then(d => {
            const t = $('#nwTrends', box); if (!t) return;
            t.innerHTML = (d.tags.length ? '<div class="nw-row">' + d.tags.map(x => '<button type="button" class="chip" data-tag="' + esc(x.tag) + '">#' + esc(x.tag) + ' <small>' + x.n + '</small></button>').join('') + '</div>' : '<span>Noch keine Hashtags diese Woche.</span>')
                + (d.top.length ? '<b style="margin-top:8px">Meistbeachtet</b>' + d.top.map(p => '<button type="button" data-open="' + p.id + '">' + esc((p.name || '') + ': ' + (p.text || '🖼').slice(0, 60)) + '</button>').join('') : '')
                + (d.people.length ? '<b style="margin-top:8px">Aktiv</b>' + d.people.map(p => '<button type="button" data-prof="' + esc(p.vrc_id) + '">👤 ' + esc(p.name || p.vrc_id.slice(0, 12)) + ' · ' + p.n + '</button>').join('') : '');
            t.onclick = e => { const g = e.target.closest('[data-tag]'); if (g) { cs.tag = g.dataset.tag; cs.q = ''; return openComm(sub); } const p = e.target.closest('[data-prof]'); if (p) return showProfile(p.dataset.prof); const o = e.target.closest('[data-open]'); if (o) openSingle(+o.dataset.open); };
        }).catch(x => { const t = $('#nwTrends', box); if (t) t.textContent = x.message; });
        api('nw_inbox', { seen: '1' }).then(d => {
            const t = $('#nwInbox', box); if (!t) return;
            t.innerHTML = d.items.length ? d.items.slice(0, 8).map(r => '<button type="button" data-open="' + r.parent + '">' + (r.id > d.seen ? '🟡 ' : '') + esc((r.name || '') + ': ' + (r.text || '🖼').slice(0, 70)) + '</button>').join('') : '<span>Noch keine Antworten.</span>';
            t.onclick = e => { const o = e.target.closest('[data-open]'); if (o) openSingle(+o.dataset.open); };
            inboxBadge();
        }).catch(() => {});
    }
    function loadFeed(reset) {
        const list = $('#nwList', cs.box); if (!list) return;
        if (reset) { cs.items = []; cs.end = false; }
        const before = cs.items.length ? cs.items[cs.items.length - 1].id : 0;
        api('nw_feed', { rating: cs.rating, before, q: cs.q, tag: cs.tag, mode: cs.mode || '', author: cs.author || '' }).then(d => {
            cs.items = cs.items.concat(d.items); cs.end = d.items.length < 30;
            list.innerHTML = cs.items.length ? cs.items.map(p => postHtml(p)).join('') : '<div class="vx-empty">' + (cs.mode === 'following' ? 'Du folgst noch niemandem – im Profil einer Person auf ⭐ Folgen tippen.' : cs.mode === 'saved' ? 'Noch nichts gemerkt – bei einem Beitrag auf 🔖 tippen.' : 'Keine Beiträge gefunden.') + '</div>';
            $('#nwMore', cs.box).hidden = cs.end;
        }).catch(x => { list.innerHTML = '<div class="vx-error">' + esc(x.message) + '</div>'; });
    }
    function openSingle(id) {
        api('nw_thread', { id }).then(d => {
            const list = $('#nwList', cs.box); if (!list) return;
            list.innerHTML = '<button type="button" class="btn btn-sm" id="nwBack">← zurück zum Feed</button>' + postHtml(d.post);
            $('#nwBack', list).onclick = () => loadFeed(true);
            toggleThread($('.nw-post', list));
        }).catch(x => toast('⚠ ' + x.message, true));
    }

    // ---------- Profil ----------
    function showProfile(id, box) {
        box = box || cs.box || $('#nwComm');
        if (box === $('#nwComm') && $('[data-pane=community]').hidden && window.NexusTab) { window.NexusTab('community', 'feed'); box = $('#nwComm'); }
        box.innerHTML = '<div class="vx-loading">Lade Profil …</div>';
        api('nw_profile', { id }).then(d => {
            const p = d.profile, own = p.own;
            const facts = [p.pronouns && '🏷 ' + p.pronouns, p.languages && '🗣 ' + p.languages, p.verified18 && '✅ Verified 18+', '📅 seit ' + new Date(p.joined * 1000).toLocaleDateString('de-DE'),
                p.posts + ' Beiträge', p.followers + ' Folgende', p.following + ' folgt'].filter(Boolean);
            box.innerHTML = '<div class="nw-card"><div class="nw-prof-banner"' + (p.banner ? ' style="background-image:url(' + media(p.banner) + ')"' : '') + '></div>'
                + '<div class="nw-prof-head"><div class="nw-ava">' + (p.avatar ? '<img src="' + media(p.avatar) + '" alt="">' : esc((p.name || '?').slice(0, 1))) + '</div>'
                + '<div style="flex:1"><h2 style="margin:0">' + esc(p.name || p.vrc_id) + '</h2></div>'
                + (own ? '' : '<button type="button" class="btn btn-sm' + (p.i_follow ? '' : ' btn-gold') + '" id="nwFollow">' + (p.i_follow ? '✓ Folge ich' : '⭐ Folgen') + '</button>')
                + '<button type="button" class="btn btn-sm" id="nwVrc">VRChat-Profil</button></div>'
                + '<div class="nw-facts">' + facts.map(f => '<span class="nw-fact">' + esc(f) + '</span>').join('') + '</div>'
                + '<div class="nxp-badges" data-badges="' + esc(p.vrc_id) + '"></div>'
                + (p.bio ? '<div class="nw-text">' + rich(p.bio) + '</div>' : '')
                + (p.interests ? '<div class="nw-muted">💜 ' + esc(p.interests) + '</div>' : '')
                + (p.links && p.links.length ? '<div class="nw-row">' + p.links.map(l => '<a class="chip" href="' + esc(l) + '" target="_blank" rel="noopener noreferrer">🔗 ' + esc(l.replace(/^https:\/\//, '').slice(0, 40)) + '</a>').join('') + '</div>' : '')
                + (me && me.nsfw && !own && p.link18 ? linkBar(p) : '')
                + (p.nsfw_bio ? '<div class="nw-card" style="margin-top:10px;border-color:rgba(255,85,140,.45)"><h3>🔞 18+-Profil</h3><div class="nw-text">' + rich(p.nsfw_bio) + '</div></div>' : '')
                + '</div>' + (own ? profileForm(p) : '') + '<h3 class="nw-muted" style="margin:6px 0 0">Beiträge</h3><div class="nw" id="nwList"></div><button type="button" class="btn btn-sm" id="nwMore" hidden>Ältere laden</button>';
            if ($('#nwFollow', box)) $('#nwFollow', box).onclick = () => api('nw_follow', { id: p.vrc_id, on: p.i_follow ? '' : '1' }).then(x => { toast(x.msg); showProfile(id, box); }).catch(x => toast('⚠ ' + x.message, true));
            $('#nwVrc', box).onclick = () => K.openUser(p.vrc_id);
            if (window.NexusPlus) window.NexusPlus.badges($('[data-badges]', box));   // 8.2: Abzeichen & Vollständigkeit
            $$('[data-l18]', box).forEach(b => { b.onclick = () => l18Do(b, () => showProfile(id, box)); });
            if (own) bindProfileForm(box, p);
            cs.box = box; cs.author = p.vrc_id; cs.mode = ''; cs.q = ''; cs.tag = ''; cs.rating = box.id === 'nwErpBody' ? 'nsfw' : 'sfw';
            $('#nwMore', box).onclick = () => loadFeed(false);
            bindPosts($('#nwList', box), () => loadFeed(true));
            loadFeed(true);
        }).catch(x => { box.innerHTML = '<div class="vx-error">' + esc(x.message) + '</div>'; });
    }
    function profileForm(p) {
        const f = (k, l, t) => '<label>' + l + '</label>' + (t ? '<textarea data-k="' + k + '" maxlength="1000">' + esc(p[k] || '') + '</textarea>' : '<input type="text" data-k="' + k + '" value="' + esc(p[k] || '') + '">');
        return '<details class="nw-card nw-form"><summary class="panel-title" style="cursor:pointer;margin:0">✏ Profil bearbeiten</summary>'
            + f('bio', 'Über mich', 1) + f('pronouns', 'Pronomen') + f('languages', 'Sprachen') + f('interests', 'Interessen')
            + '<label>Links (eine pro Zeile, nur https)</label><textarea data-k="links">' + esc((p.links || []).join('\n')) + '</textarea>'
            + (me.nsfw ? f('nsfw_bio', '🔞 18+-Profiltext (nur für freigegebene Konten sichtbar)', 1) : '')
            + '<div class="nw-row" style="margin-top:10px"><span class="sp"></span><button type="button" class="btn btn-gold btn-sm" id="nwProfSave">Speichern</button></div></details>';
    }
    function bindProfileForm(box, p) {
        $('#nwProfSave', box).onclick = () => {
            const o = { theme: p.theme || '', avatar: p.avatar || '', banner: p.banner || '' };
            $$('[data-k]', box).forEach(el => { o[el.dataset.k] = el.dataset.k === 'links' ? el.value.split('\n').map(s => s.trim()).filter(Boolean) : el.value; });
            if (!me.nsfw) delete o.nsfw_bio;
            api('nw_profile_save', { profile: JSON.stringify(o) }).then(d => { toast(d.msg); showProfile(p.vrc_id, box); }).catch(x => toast('⚠ ' + x.message, true));
        };
    }

    // ---------- Moderation ----------
    function showMod(box) {
        box.innerHTML = '<div class="vx-loading">Lade …</div>';
        api('nw_mod_queue').then(d => {
            const act = (doo, data, label, bad) => '<button type="button" class="btn btn-sm' + (bad ? '' : ' btn-gold') + '" data-do="' + doo + '" data-x="' + esc(JSON.stringify(data)) + '">' + label + '</button>';
            box.innerHTML = '<div class="nw-card"><h3>⚠ Offene Meldungen (' + d.reports.length + ')</h3>' + (d.reports.map(r => '<div class="nw-card"><div class="nw-muted">#' + r.post_id + ' · ' + esc(r.reason) + '</div><div class="nw-text">' + rich(r.text || '') + '</div><div class="nw-row">'
                + act('hide', { id: +r.post_id, report: +r.id }, '🙈 Ausblenden') + act('unhide', { id: +r.post_id, report: +r.id }, '✓ Ist ok', true) + act('mute', { vrc_id: r.author, hours: 24, report: +r.id }, '🔇 24 h stumm', true) + '</div></div>').join('') || '<span class="nw-muted">Nichts offen. 🎉</span>') + '</div>'
                + '<div class="nw-card"><h3>⏳ Wartende Beiträge (' + d.pending.length + ')</h3>' + (d.pending.map(p => '<div class="nw-card">' + postHtml(p, true) + '<div class="nw-row">' + act('approve', { id: p.id }, '✓ Freigeben') + act('hide', { id: p.id }, '🙈 Ablehnen', true) + '</div></div>').join('') || '<span class="nw-muted">Keine.</span>') + '</div>'
                + '<div class="nw-card"><h3>🖼 Wartende Bilder (' + d.media.length + ')</h3><div class="nw-row">' + (d.media.map(m => '<div class="nw-card"><img src="' + media(m.id) + '" alt="" style="max-width:180px;max-height:180px"><div class="nw-row">' + act('media_ok', { media: m.id }, '✓') + act('media_reject', { media: m.id }, '✕', true) + '</div></div>').join('') || '<span class="nw-muted">Keine.</span>') + '</div></div>'
                + '<p class="nw-muted">Mehr (Sperren, Statistik, Suche) im Admin-Bereich der Webseite unter Nexus → Community.</p>';
            box.onclick = e => { const b = e.target.closest('[data-do]'); if (!b) return; const data = JSON.parse(b.dataset.x); if (['hide', 'mute'].includes(b.dataset.do)) { const n = prompt('Notiz für das Protokoll:'); if (n == null) return; data.note = n; }
                api('nw_mod', { do: b.dataset.do, data: JSON.stringify(data) }).then(x => { toast(x.msg || 'Erledigt.'); showMod(box); }).catch(x => toast('⚠ ' + x.message, true)); };
        }).catch(x => { box.innerHTML = '<div class="vx-error">' + esc(x.message) + '</div>'; });
    }

    // =====================================================================
    // 📡 Nexus Pulse
    // =====================================================================
    const ps = { src: 'vrchat', sort: 'new', kind: 'all' };
    const KINDS = { all: 'Alle', patch: 'Patchnotes', dev: 'Developer-Updates', security: 'Sicherheit', creator: 'Creator', news: 'News' };
    function openPulse(box, erp) {
        box = box || $('#nwPulse');
        if (erp) ps.src = 'erp'; else if (ps.src === 'erp') ps.src = 'vrchat';
        const srcs = erp ? '' : '<button type="button" class="chip' + (ps.src === 'vrchat' ? ' on' : '') + '" data-src="vrchat">📣 VRChat offiziell</button><button type="button" class="chip' + (ps.src === 'reddit' ? ' on' : '') + '" data-src="reddit">👽 r/VRChat</button>';
        const sorts = ps.src === 'vrchat' ? Object.entries(KINDS).map(([k, l]) => '<button type="button" class="chip' + (ps.kind === k ? ' on' : '') + '" data-kind="' + k + '">' + l + '</button>').join('')
            : ['new', 'hot', 'top'].map(k => '<button type="button" class="chip' + (ps.sort === k ? ' on' : '') + '" data-sort="' + k + '">' + { new: '🆕 Neu', hot: '🔥 Beliebt', top: '🏆 Top der Woche' }[k] + '</button>').join('');
        box.innerHTML = '<div class="nw-card"><div class="nw-row">' + srcs + '<span class="sp"></span>' + sorts + '</div>'
            + (ps.src !== 'vrchat' && me && !me.pulse ? '<p class="nw-muted" style="margin:8px 0 0">Reddit ist auf dieser Webseite noch nicht eingerichtet (Reddit-App-Schlüssel fehlt auf dem Server).</p>' : '') + '</div>'
            + '<div class="nw-grid" id="nwPGrid"><div class="vx-loading">Lade …</div></div>';
        box.onclick = e => {
            const b = e.target.closest('button'); if (!b) return;
            if (b.dataset.src) { ps.src = b.dataset.src; return openPulse(box, erp); }
            if (b.dataset.sort) { ps.sort = b.dataset.sort; return openPulse(box, erp); }
            if (b.dataset.kind) { ps.kind = b.dataset.kind; return openPulse(box, erp); }
            if (b.hasAttribute('data-com')) {
                const c = b.nextElementSibling;
                if (!c.hidden) { c.hidden = true; return; }
                c.hidden = false; c.innerHTML = '<div class="vx-loading">Lade …</div>';
                api('nw_pulse_comments', { url: b.dataset.url }).then(d => { c.innerHTML = d.items.length ? d.items.map(x => '<div class="nw-card" style="padding:8px 10px"><b>' + esc(x.author) + '</b> <span class="nw-muted">' + when(x.ts) + '</span><div class="nw-text">' + rich(x.text) + '</div></div>').join('') : '<span class="nw-muted">Keine Kommentare.</span>'; })
                    .catch(x => { c.innerHTML = '<div class="vx-error">' + esc(x.message) + '</div>'; });
            }
        };
        api('nw_pulse', { src: ps.src, sort: ps.sort }).then(d => {
            const items = ps.src === 'vrchat' && ps.kind !== 'all' ? d.items.filter(x => x.kind === ps.kind) : d.items;
            $('#nwPGrid', box).innerHTML = items.length ? items.map(x => '<div class="nw-pitem">' + (x.img ? '<img src="' + esc(x.img) + '" alt="" loading="lazy" referrerpolicy="no-referrer">' : '')
                + '<div class="in"><span class="nw-kind ' + esc(x.kind) + '">' + esc(KINDS[x.kind] || (x.src === 'erp' ? '🔞 r/VRchatERP' : 'r/VRChat')) + '</span>'
                + '<a class="t" href="' + esc(x.url) + '" target="_blank" rel="noopener noreferrer">' + esc(x.title) + '</a>'
                + (x.text ? '<span class="nw-muted">' + esc(x.text.slice(0, 220)) + '</span>' : '')
                + '<span class="nw-muted">' + esc(x.author) + ' · ' + when(x.ts) + '</span>'
                + (x.comments ? '<button type="button" class="btn btn-sm" data-com data-url="' + esc(x.url) + '">💬 Kommentare</button><div class="nw" hidden></div>' : '') + '</div></div>').join('')
                : '<div class="vx-empty">Nichts gefunden.</div>';
        }).catch(x => { $('#nwPGrid', box).innerHTML = '<div class="vx-error">' + esc(x.message) + '</div>'; });
    }

    // =====================================================================
    // ❤️ 18+ / ERP
    // =====================================================================
    let erpSub = 'feed';
    // ---------- 6.0: 18+-Anfragen, Verbindungen, Kompatibilität, Consent-Check ----------
    const LIGHT = { green: '🟢', yellow: '🟡', red: '🔴' };
    let compatOf = null, consentFor = '';
    function linkBar(p) {
        const l = p.link18, b = (a, t, g) => '<button type="button" class="btn btn-sm' + (g ? ' btn-gold' : '') + '" data-l18="' + a + '" data-vrc="' + esc(p.vrc_id) + '"' + (l.id ? ' data-rid="' + l.id + '"' : '') + '>' + t + '</button>';
        return '<div class="nw-row" style="margin-top:8px">' + (l.state === 'none' ? b('send', '💌 18+-Anfrage senden', true)
            : l.state === 'sent' ? '<span class="chip on">⏳ Anfrage gesendet</span>' + b('withdraw', 'Zurückziehen')
            : l.state === 'received' ? b('open', '💌 Anfrage beantworten', true)
            : '<span class="chip on">💞 Verbunden</span>' + b('open', '🤝 Consent-Check & Kompatibilität', true)) + '</div>';
    }
    function l18Do(b, again) {
        const a = b.dataset.l18, rid = b.dataset.rid, vrc = b.dataset.vrc, sid = b.dataset.sid;
        const ok = d => { if (d && d.msg) toast(d.msg); again(); }, bad = x => toast('⚠ ' + x.message, true);
        if (a === 'send') { const m = prompt('Kurze Nachricht zur Anfrage (optional):', ''); if (m === null) return; const lim = prompt('Grenzen-Vorschau mitschicken? (optional, z. B. „🟢 Kuscheln · 🔴 Blut“ – leer lassen für keine)', ''); if (lim === null) return; return api('nw_req_send', { id: vrc, message: m, limits: lim }).then(ok).catch(bad); }
        if (a === 'open') { erpSub = 'links'; if (window.NexusTab) window.NexusTab('erp'); return openErp(); }
        if (a === 'accept' || a === 'decline') return api('nw_req_answer', { id: rid, accept: a === 'accept' ? '1' : '' }).then(ok).catch(bad);
        if (a === 'withdraw') return api('nw_req_withdraw', { id: rid }).then(ok).catch(bad);
        if (a === 'unlink') { if (!confirm('Verbindung lösen? Laufende Consent-Checks enden dann.')) return; return api('nw_req_withdraw', { id: rid }).then(ok).catch(bad); }
        if (a === 'compat') return api('nw_compat', { id: vrc }).then(d => { compatOf = { id: vrc, d }; again(); }).catch(bad);
        if (a === 'consent') { consentFor = vrc; return again(); }
        if (a === 'sdecline') return api('nw_sess_answer', { id: sid, agree: '' }).then(ok).catch(bad);
        if (a === 'end') return api('nw_sess_end', { id: sid, reason: b.dataset.r }).then(ok).catch(bad);
    }
    function openLinks(body) {
        body.innerHTML = '<div class="vx-loading">Lade …</div>';
        api('nw_req_list').then(d => {
            const nm = x => '<a href="#" data-prof="' + esc(x.vrc_id) + '"><b>' + esc(x.name || x.vrc_id.slice(0, 12)) + '</b></a>';
            const dt = ts => new Date(ts * 1000).toLocaleDateString('de-DE');
            const terms = t => t ? LIGHT[t.light] + ' · ⏱ ' + t.minutes + ' min · 🛑 „' + esc(t.safeword) + '“' + (t.toys ? ' · 🎮 Spielzeug' : '') + (t.limits ? '<div class="nw-muted">🚫 ' + esc(t.limits) + '</div>' : '') : '–';
            const form = (id, sid) => '<form class="nw-card nw-consent" data-cf="' + esc(id) + '"' + (sid ? ' data-sid="' + sid + '"' : '') + '><div class="nw-row">'
                + ['green', 'yellow', 'red'].map(l => '<label class="vx-auto"><input type="radio" name="light" value="' + l + '"' + (l === 'green' ? ' checked' : '') + '> ' + LIGHT[l] + ' ' + { green: 'Bereit', yellow: 'Vorsichtig', red: 'Heute nicht' }[l] + '</label>').join('') + '</div>'
                + '<input type="text" name="limits" maxlength="500" placeholder="Grenzen für heute (optional)"><div class="nw-row"><input type="text" name="safeword" maxlength="40" value="Rot" style="width:140px" placeholder="Safeword">'
                + '<select name="minutes">' + [15, 30, 45, 60, 90, 120].map(m => '<option value="' + m + '"' + (m === 30 ? ' selected' : '') + '>' + m + ' min</option>').join('') + '</select><label class="vx-auto"><input type="checkbox" name="toys"> 🎮 Spielzeug erlaubt</label></div>'
                + '<div class="nw-row"><button class="btn btn-sm btn-gold">' + (sid ? 'Zustimmen' : 'Consent-Check schicken') + '</button>' + (sid ? '<button type="button" class="btn btn-sm" data-l18="sdecline" data-sid="' + sid + '">Nein danke</button>' : '') + '</div></form>';
            const compat = c => '<div class="nw-card"><b>💞 ' + (c.score === null ? 'Noch zu wenig ausgefüllt' : c.score + ' %') + '</b>'
                + [['match', '💚'], ['maybe', '💛'], ['conflict', '🚫']].map(([k, i]) => c[k].length ? '<div class="nw-row">' + c[k].map(x => '<span class="chip">' + i + ' ' + esc(x.k.replace(/_/g, ' ')) + ' ' + (x.dir === 'me' ? '↙' : '↗') + '</span>').join('') + '</div>' : '').join('')
                + '<span class="nw-muted">↙ du empfängst · ↗ du gibst</span></div>';
            const card = (h, inner) => '<div class="nw-card"><h3>' + h + '</h3>' + inner + '</div>';
            body.innerHTML = (d.remind ? '<div class="nw-card" style="border-color:#f3c262">⏳ Erinnerung: ' + d.remind + ' Anfrage(n) warten schon über 3 Tage auf deine Antwort – nach 14 Tagen laufen sie ab.</div>' : '')
                + '<div class="nw-muted" style="margin-bottom:8px">💌 Anfragen gibt es nur zwischen Konten mit Verified 18+ und NSFW an. Erst wenn beide zustimmen, seid ihr verbunden. Ablehnen bleibt anonym („nicht angenommen“).</div>'
                + card('📥 Eingang (' + d.incoming.length + ')', d.incoming.length ? d.incoming.map(r => '<div class="nw-l18">' + nm(r) + ' <span class="nw-muted">' + dt(r.created) + '</span>' + (r.message ? '<div class="nw-text">' + esc(r.message) + '</div>' : '') + (r.limits ? '<details class="ad-lim"><summary>📋 Grenzen-Vorschau</summary><pre class="ad-body">' + esc(r.limits) + '</pre></details>' : '')
                    + '<div class="nw-row"><button type="button" class="btn btn-sm btn-gold" data-l18="accept" data-rid="' + r.id + '">💞 Annehmen</button><button type="button" class="btn btn-sm" data-l18="decline" data-rid="' + r.id + '">Ablehnen</button></div></div>').join('') : '<span class="nw-muted">Keine offenen Anfragen.</span>')
                + card('💞 Verbindungen (' + d.connections.length + ')', d.connections.length ? d.connections.map(c => {
                    const ss = d.sessions.filter(s => s.with === c.vrc_id && ['proposed', 'ready'].includes(s.status));
                    const open = ss.find(s => s.status === 'proposed' && !s.proposed_by_me), mine = ss.find(s => s.status === 'proposed' && s.proposed_by_me), ready = ss.find(s => s.status === 'ready');
                    return '<div class="nw-l18">' + nm(c) + ' <span class="nw-muted">seit ' + dt(c.since) + '</span><div class="nw-row">'
                        + '<button type="button" class="btn btn-sm" data-l18="compat" data-vrc="' + esc(c.vrc_id) + '">💞 Kompatibilität</button>'
                        + (!ss.length ? '<button type="button" class="btn btn-sm btn-gold" data-l18="consent" data-vrc="' + esc(c.vrc_id) + '">🤝 Consent-Check</button>' : '')
                        + '<button type="button" class="btn btn-sm" data-l18="unlink" data-rid="' + c.id + '">Lösen</button></div>'
                        + (compatOf && compatOf.id === c.vrc_id ? compat(compatOf.d) : '')
                        + (consentFor === c.vrc_id && !ss.length ? form(c.vrc_id, 0) : '')
                        + (open ? '<div class="nw-card"><b>🤝 Consent-Check angefragt</b><div>' + terms(open.theirs) + '</div>' + form(c.vrc_id, open.id) + '</div>' : '')
                        + (mine ? '<div class="nw-muted">⏳ Wartet auf Zustimmung – ' + terms(mine.mine) + ' <button type="button" class="btn btn-sm" data-l18="end" data-sid="' + mine.id + '" data-r="cancel">Zurückziehen</button></div>' : '')
                        + (ready ? '<div class="nw-card" style="border-color:rgba(80,200,120,.5)"><b>✅ Session läuft</b> · bis ' + new Date(ready.ends * 1000).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })
                            + '<div>Du: ' + terms(ready.mine) + '</div><div>Gegenüber: ' + terms(ready.theirs) + '</div><div class="nw-row"><button type="button" class="btn btn-sm btn-danger" data-l18="end" data-sid="' + ready.id + '" data-r="safeword">🛑 Safeword – sofort beenden</button>'
                            + '<button type="button" class="btn btn-sm" data-l18="end" data-sid="' + ready.id + '" data-r="done">Fertig</button></div></div>' : '') + '</div>';
                }).join('') : '<span class="nw-muted">Noch keine Verbindungen – schick jemandem im Profil eine 18+-Anfrage.</span>')
                + card('📤 Gesendet', d.outgoing.length ? d.outgoing.map(r => '<div class="nw-l18">' + nm(r) + ' <span class="nw-muted">' + dt(r.created) + ' · ' + ({ pending: 'wartet', not_accepted: 'nicht angenommen', withdrawn: 'zurückgezogen', expired: 'abgelaufen', ended: 'gelöst' }[r.status] || esc(r.status)) + '</span>'
                    + (r.status === 'pending' ? ' <button type="button" class="btn btn-sm" data-l18="withdraw" data-rid="' + r.id + '">Zurückziehen</button>' : '') + '</div>').join('') : '<span class="nw-muted">–</span>');
            const again = () => openLinks(body);
            $$('[data-l18]', body).forEach(b => { b.onclick = () => l18Do(b, again); });
            $$('[data-prof]', body).forEach(a => { a.onclick = e => { e.preventDefault(); erpSub = 'profile'; cs.box = body; showProfile(a.dataset.prof, body); }; });
            $$('form.nw-consent', body).forEach(f => { f.onsubmit = e => {
                e.preventDefault();
                const t = { light: (f.querySelector('[name=light]:checked') || {}).value || 'yellow', limits: f.limits.value, safeword: f.safeword.value, minutes: +f.minutes.value, toys: f.toys.checked };
                (f.dataset.sid ? api('nw_sess_answer', { id: f.dataset.sid, agree: '1', terms: JSON.stringify(t) }) : api('nw_sess_start', { id: f.dataset.cf, terms: JSON.stringify(t) }))
                    .then(x => { consentFor = ''; toast(x.msg); again(); }).catch(x => toast('⚠ ' + x.message, true));
            }; });
        }).catch(x => { body.innerHTML = '<div class="vx-error">' + esc(x.message) + '</div>'; });
    }
    function openErp() {
        const box = $('#nwErp');
        const a = me.account;
        if (!a.verified18) return gate(box, '🔞 Nur mit Verified 18+', 'Schließe in VRChat die Altersverifizierung (18+) ab und mach sie im Profil sichtbar – danach hier neu laden.');
        box.innerHTML = '<div class="nw-erp-hero"><div class="nw-row"><div style="flex:1"><h2>❤️ 18+ / ERP</h2><span class="nw-muted">Nur für dich sichtbar, weil dein Konto Verified 18+ ist. Inhalte bleiben im 18+-Bereich – nichts davon erscheint im normalen Feed.</span></div>'
            + '<label class="vx-auto"><input type="checkbox" id="nwNsfwOn"' + (me.nsfw ? ' checked' : '') + '> NSFW-Inhalte anzeigen</label></div></div>'
            + (me.nsfw ? '<div class="nw-row">' + [['feed', '🔞 NSFW-Feed'], ['links', '💌 Anfragen & Consent'], ['reddit', '👽 r/VRchatERP'], ['profile', '👤 Mein 18+-Profil'], ['couple', '💞 Paar-Profil'], ['tpl', '📚 Vorlagen'], ['toys', '🎮 Spielzeug'], ['tips', '🛡 Sicherheit & Regeln']].map(([k, l]) => '<button type="button" class="chip' + (erpSub === k ? ' on' : '') + '" data-esub="' + k + '">' + l + '</button>').join('') + '</div><div id="nwErpBody" class="nw"></div>'
                : '<div class="nw-card nw-gate"><b>NSFW ist aus</b><span class="nw-muted">Schalte oben „NSFW-Inhalte anzeigen“ ein. Das gilt auch in der App (gleiches Nexus-Konto) und lässt sich jederzeit wieder ausschalten.</span></div>');
        $('#nwNsfwOn', box).onchange = e => api('nw_nsfw', { on: e.target.checked ? '1' : '0' }).then(d => { toast(d.msg); meP = null; return ME(); }).then(openErp).catch(x => { e.target.checked = !e.target.checked; toast('⚠ ' + x.message, true); });
        $$('[data-esub]', box).forEach(b => { b.onclick = () => { erpSub = b.dataset.esub; openErp(); }; });
        if (!me.nsfw) return;
        const body = $('#nwErpBody', box);
        if (erpSub === 'feed') return openComm('erp-feed');
        if (erpSub === 'reddit') return openPulse(body, true);
        if (erpSub === 'links') return openLinks(body);
        if (erpSub === 'profile') { cs.box = body; return showProfile(a.vrc_id, body); }
        if ((erpSub === 'couple' || erpSub === 'tpl') && window.NexusAdult) return window.NexusAdult.render(body, erpSub);   // 7.3.2
        if (erpSub === 'toys' && window.NexusToyWeb) return window.NexusToyWeb.render(body);   // 8.2: Spielzeug im Browser, Partner-Links
        body.innerHTML = '<div class="nw-tiles">' + [
            ['🤝', 'Einverständnis zuerst', 'Klärt Grenzen vorher. „Nein“ und Safewords gelten sofort – auch mitten in einer Szene.'],
            ['🔒', 'Privatsphäre', 'Keine echten Namen, Adressen oder Gesichtsbilder posten. Screenshots anderer nur mit deren Erlaubnis.'],
            ['🚫', 'Klare Grenzen', 'Nichts mit Minderjährigen, nichts ohne Einverständnis, keine echte Gewalt – sofort melden (⚠).'],
            ['🛡', 'Melden & Blockieren', 'Jeder Beitrag hat ⚠ Melden. Die Moderation sieht Meldungen sofort.'],
            ['🆘', 'Panik in der App', 'In der Desktop-App beendet der Panik-Knopf (auch von der Uhr) sofort alles NSFW.'],
        ].map(([i, t, s]) => '<div class="nw-tile"><span>' + i + '</span><b>' + t + '</b><span class="nw-muted" style="font-size:.8rem">' + s + '</span></div>').join('') + '</div>';
    }

    // =====================================================================
    window.NexusWeb = {
        open(name, sub) {
            if (!['community', 'pulse', 'erp'].includes(name)) return;
            ME().then(() => {
                if (name === 'community') openComm(sub || 'feed');
                if (name === 'pulse') openPulse();
                if (name === 'erp') openErp();
            }).catch(x => { const box = $({ community: '#nwComm', pulse: '#nwPulse', erp: '#nwErp' }[name]); if (box) box.innerHTML = '<div class="vx-error">' + esc(x.message) + '</div>'; });
        },
    };
    // Erster Aufruf: nexus.js hat den gespeicherten Reiter schon geöffnet, bevor diese Datei geladen war
    ME().catch(() => {});
    // 8.2: Partner-Link (nexus.php?toylink=<id>) → 18+ / ERP → 🎮 Spielzeug; ob man ihn nutzen darf, entscheidet der Server
    const tl = new URLSearchParams(location.search).get('toylink');
    const toyLink = !!tl && /^[0-9a-f]{20}$/.test(tl);
    if (toyLink) {
        window.NexusToyLink = tl; erpSub = 'toys';
        try { history.replaceState(null, '', location.pathname); } catch (e) { /* egal */ }
        ME().then(() => { if (window.NexusTab) window.NexusTab('erp'); }).catch(() => {});
    }
    const cur = $('.vx-tab:not([hidden])');
    if (!toyLink && cur && ['community', 'pulse', 'erp'].includes(cur.dataset.pane)) {
        const b = $('#tabs [data-tab="' + cur.dataset.pane + '"].active');
        window.NexusWeb.open(cur.dataset.pane, b ? b.dataset.msub : '');
    }
})();
