/* Notizbuch: Bearbeiten ein-/ausblenden, Suche in der Liste, Löschen bestätigen */
Widgets.register('notebook', (el) => {
    const q = s => el.querySelector(s);
    const ed = q('.nb-edit'), f = q('.nb-form'), find = q('.nb-find');
    if (ed && f) ed.addEventListener('click', () => { f.hidden = !f.hidden; q('.nb-text').hidden = !f.hidden; if (!f.hidden) f.querySelector('textarea').focus(); Widgets.relayout(); });
    if (find) find.addEventListener('input', () => { const v = find.value.trim().toLowerCase(); el.querySelectorAll('.nb-list li').forEach(li => { li.hidden = v !== '' && !li.dataset.find.includes(v); }); });
    el.querySelectorAll('[data-confirm]').forEach(b => b.addEventListener('click', e => { if (!confirm(b.dataset.confirm)) { e.stopImmediatePropagation(); e.preventDefault(); } }, true));
});
