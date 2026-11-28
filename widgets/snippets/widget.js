/* Textbausteine: Klick kopiert (mit Rückfall für ältere Browser / http) */
Widgets.register('snippets', (el) => {
    el.querySelectorAll('.sn-copy').forEach(b => b.addEventListener('click', () => {
        const t = b.dataset.text;
        const ok = () => { b.classList.add('done'); Widgets.toast('Kopiert ✓'); setTimeout(() => b.classList.remove('done'), 1200); };
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(t).then(ok, () => fallback());
        else fallback();
        function fallback() { const ta = document.createElement('textarea'); ta.value = t; ta.style.position = 'fixed'; ta.style.opacity = '0'; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); ok(); } catch (e) { Widgets.toast('Kopieren nicht möglich.', true); } ta.parentNode.removeChild(ta); }
    }));
});
