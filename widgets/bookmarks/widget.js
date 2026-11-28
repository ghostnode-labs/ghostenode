/* Lesezeichen: Filter und Import der Browser-Datei */
Widgets.register('bookmarks', (el, api) => {
    const find = el.querySelector('.bm-find');
    if (find) find.addEventListener('input', () => {
        const v = find.value.trim().toLowerCase();
        el.querySelectorAll('.bm-l li').forEach(li => { li.hidden = v !== '' && !li.dataset.find.includes(v); });
        el.querySelectorAll('.bm-f').forEach(d => { if (v) d.open = !!d.querySelector('li:not([hidden])'); d.hidden = v !== '' && !d.querySelector('li:not([hidden])'); });
        Widgets.relayout();
    });
    const file = el.querySelector('.bm-imp input');
    if (file) file.addEventListener('change', () => {
        const f = file.files[0];
        if (!f) return;
        if (f.size > 3000000) return Widgets.toast('Datei zu groß (max. 3 MB).', true);
        f.text().then(html => api.action('import', { html }));
    });
});
