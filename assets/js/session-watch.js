/* Sitzungs-Wächter: prüft die Restzeit der Anmeldung (ohne sie zu verlängern). 2 Minuten vorher kommt ein Hinweis mit
 * „Angemeldet bleiben“; ist die Sitzung abgelaufen (Zeit um, anderswo abgemeldet, Passwort geändert, Konto gesperrt),
 * geht es automatisch zur Anmeldung – danach zurück auf dieselbe Seite. */
(function () {
    if (window.__gnWatch) return;
    window.__gnWatch = true;
    let seen = false, timer = null, bar = null;
    const WARN = 120;

    function show(left) {
        if (!bar) {
            bar = document.createElement('div');
            bar.style.cssText = 'position:fixed;left:50%;bottom:18px;transform:translateX(-50%);z-index:99999;display:flex;gap:12px;align-items:center;flex-wrap:wrap;'
                + 'max-width:calc(100vw - 32px);padding:10px 16px;border-radius:8px;background:#141a22;color:#ffc35c;border:1px solid rgba(255,195,92,.6);'
                + 'box-shadow:0 8px 24px rgba(0,0,0,.5);font:14px/1.4 system-ui,sans-serif';
            bar.innerHTML = '<span></span><button type="button" style="cursor:pointer;padding:6px 12px;border-radius:6px;border:1px solid #ffc35c;background:transparent;color:#ffc35c;font:inherit">Angemeldet bleiben</button>';
            bar.querySelector('button').addEventListener('click', () => {
                fetch('session.php?a=extend', { method: 'POST', credentials: 'same-origin' }).then(r => r.json()).then(d => { hide(); plan(d); }).catch(() => {});
            });
            document.body.appendChild(bar);
        }
        bar.querySelector('span').textContent = '⏱ Du wirst in ' + (left > 60 ? Math.ceil(left / 60) + ' Minuten' : left + ' Sekunden') + ' wegen Inaktivität automatisch abgemeldet.';
    }
    function hide() { if (bar) { bar.remove(); bar = null; } }
    function out() {
        hide();
        const here = location.pathname.replace(/^.*\//, '') + location.search;
        location.href = 'admin.php?next=' + encodeURIComponent(here || 'index.php') + '&error=' + encodeURIComponent('Deine Sitzung ist abgelaufen – du wurdest automatisch abgemeldet. Bitte melde dich neu an.');
    }
    function plan(d) {
        clearTimeout(timer);
        if (!d.in) { if (seen) out(); return; }   // nie angemeldet gewesen → nichts tun (öffentliche Seite)
        seen = true;
        if (d.left <= WARN) show(d.left); else hide();
        // kurz vor dem Ablauf genauer prüfen, sonst höchstens alle 5 Minuten (fängt auch Abmelden auf anderen Geräten ab)
        const next = d.left <= WARN ? Math.min(15, d.left + 1) : Math.min(300, d.left - WARN);
        timer = setTimeout(check, Math.max(1, next) * 1000);
    }
    function check() {
        fetch('session.php?a=check', { credentials: 'same-origin', cache: 'no-store' }).then(r => r.json()).then(plan).catch(() => { timer = setTimeout(check, 60000); });
    }
    document.addEventListener('visibilitychange', () => { if (!document.hidden && seen) check(); });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', check); else check();
})();
