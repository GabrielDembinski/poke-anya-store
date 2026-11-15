(function () {
    'use strict';
    const key = 'pokeanya-theme';
    const themes = ['marca', 'claro', 'escuro'];
    let current = 'marca';
    try { const saved = localStorage.getItem(key); if (themes.includes(saved)) current = saved; } catch (_) { /* Theme still works for this visit. */ }
    function apply(value) {
        current = themes.includes(value) ? value : 'marca';
        document.documentElement.dataset.theme = current;
        document.querySelectorAll('[data-theme-select]').forEach(select => { select.value = current; });
    }
    apply(current);
    document.addEventListener('DOMContentLoaded', () => {
        apply(current);
        document.querySelectorAll('[data-theme-select]').forEach(select => select.addEventListener('change', () => {
            apply(select.value);
            try { localStorage.setItem(key, current); } catch (_) { /* Noncritical preference. */ }
        }));
    });
    window.addEventListener('storage', event => { if (event.key === key || event.key === null) apply(event.newValue); });
}());
