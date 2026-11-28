/* 9.0 Admin → Server: htop-artige Prozessanzeige (nur Anzeige). Holt alle 3 s admin.php?sv_procs=1. */
(function () {
    const box = document.getElementById('svProcs');
    if (!box) return;
    const $ = id => document.getElementById(id);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const bytes = b => { const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0; b = +b || 0; while (b >= 1024 && i < 4) { b /= 1024; i++; } return (i ? b.toFixed(1).replace('.', ',') : b) + ' ' + u[i]; };
    const dur = s => { s = +s || 0; const d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60); return d ? d + 'T ' + h + 'h' : h ? h + 'h ' + String(m).padStart(2, '0') + 'm' : m + 'm ' + String(s % 60).padStart(2, '0') + 's'; };
    const col = p => p >= 90 ? '#ff5c5c' : p >= 70 ? '#f3c262' : '#38d5e6';
    const num = n => String((+n || 0).toFixed(1)).replace('.', ',');
    let sort = 'cpu', timer = null, busy = false, q = '';

    function paint(d) {
        if (d.error) { $('svpState').textContent = '⚠ ' + d.error; $('svpRows').innerHTML = '<tr><td colspan="7" class="muted">' + esc(d.error) + '</td></tr>'; return; }
        $('svpState').textContent = d.live ? '● live · ' + new Date().toLocaleTimeString('de-DE') : 'Stand: ' + new Date(d.at * 1000).toLocaleString('de-DE') + ' (Schnappschuss des Server-Agents)';
        $('svpCores').innerHTML = (d.cores || []).map((p, i) => '<div class="sv-p-core"><span>' + (i + 1) + '</span><div class="sv-bar"><i style="width:' + p + '%;background:' + col(p) + '"></i></div><b>' + num(p) + '%</b></div>').join('');
        $('svpLoad').textContent = (d.load || []).map(x => (+x).toFixed(2).replace('.', ',')).join(' · ');
        const m = d.mem || {}, mp = m.total ? m.used / m.total * 100 : 0, sp = m.swap_total ? m.swap_used / m.swap_total * 100 : 0;
        $('svpMemT').textContent = bytes(m.used) + ' / ' + bytes(m.total); $('svpMem').style.width = mp + '%'; $('svpMem').style.background = col(mp);
        $('svpSwapT').textContent = m.swap_total ? bytes(m.swap_used) + ' / ' + bytes(m.swap_total) : 'keiner'; $('svpSwap').style.width = sp + '%'; $('svpSwap').style.background = col(sp);
        $('svpTasks').textContent = d.tasks + ' / ' + d.threads + (d.running ? ' · ' + d.running + ' aktiv' : '');
        $('svpUp').textContent = dur(d.uptime);
        $('svpRows').innerHTML = (d.rows || []).map(r => '<tr class="' + (r.cpu >= 50 ? 'hot' : '') + (r.state === 'R' ? ' r' : '') + '"><td class="n">' + r.pid + '</td><td>' + esc(r.user) + '</td><td class="n">' + num(r.cpu) + '</td><td class="n">' + num(r.mem)
            + '</td><td class="n">' + bytes(r.rss) + '</td><td class="n">' + dur(r.time) + '</td><td class="cmd" title="' + esc(r.cmd) + '">' + esc(r.cmd) + '</td></tr>').join('')
            || '<tr><td colspan="7" class="muted">Keine Treffer.</td></tr>';
    }
    function load() {
        if (busy || document.hidden || $('svpPause').checked) return;
        busy = true;
        fetch('admin.php?sv_procs=1&sort=' + encodeURIComponent(sort) + '&q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(r => r.json()).then(paint).catch(e => { $('svpState').textContent = '⚠ ' + e.message; }).finally(() => { busy = false; });
    }
    box.querySelector('thead').addEventListener('click', e => {
        const th = e.target.closest('th[data-s]');
        if (!th) return;
        sort = th.dataset.s;
        box.querySelectorAll('th[data-s]').forEach(x => x.classList.toggle('on', x === th));
        load();
    });
    let qt = null;
    $('svpQ').addEventListener('input', () => { clearTimeout(qt); qt = setTimeout(() => { q = $('svpQ').value.trim(); load(); }, 250); });
    load();
    timer = setInterval(load, 3000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
})();
