/* Background motion preference. CSS owns every animation; no frame loop is needed. */
(function () {
    'use strict';
    const root = document.documentElement;
    const key = 'pokeanya-motion';
    const validPreference = value => ['running', 'paused'].includes(value) ? value : 'auto';
    let preference = 'auto';
    let reducedMotion = null;

    try { preference = validPreference(window.localStorage.getItem(key)); } catch (_) { /* Keep the choice for this visit if storage is unavailable. */ }
    try {
        if (typeof window.matchMedia === 'function') reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    } catch (_) { /* Older browsers can still use the explicit pause control. */ }

    function getState() {
        if (preference !== 'auto') return preference;
        return reducedMotion && reducedMotion.matches ? 'reduced' : 'running';
    }

    function apply() {
        const state = getState();
        const paused = state !== 'running';
        const label = state === 'reduced'
            ? 'Ativar animações do fundo'
            : paused ? 'Retomar animações do fundo' : 'Pausar animações do fundo';

        root.dataset.motion = state;
        document.querySelectorAll('[data-motion-toggle]').forEach(button => {
            button.disabled = false;
            button.setAttribute('aria-pressed', String(paused));
            button.setAttribute('aria-label', label);
            button.title = label;
            const icon = button.querySelector('[data-motion-icon]');
            if (icon) icon.textContent = paused ? '▶' : 'Ⅱ';
        });
    }

    function updateVisibility() {
        root.dataset.motionVisibility = document.hidden ? 'hidden' : 'visible';
    }

    function bindControls() {
        document.querySelectorAll('[data-motion-toggle]').forEach(button => button.addEventListener('click', () => {
            preference = getState() === 'running' ? 'paused' : 'running';
            try { window.localStorage.setItem(key, preference); } catch (_) { /* Pausing remains available without persistence. */ }
            apply();
        }));
        apply();
    }

    apply();
    updateVisibility();
    document.addEventListener('visibilitychange', updateVisibility);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bindControls, { once: true });
    else bindControls();

    if (reducedMotion) {
        if (typeof reducedMotion.addEventListener === 'function') reducedMotion.addEventListener('change', apply);
        else if (typeof reducedMotion.addListener === 'function') reducedMotion.addListener(apply);
    }
    window.addEventListener('storage', event => {
        if (event.key !== key && event.key !== null) return;
        preference = validPreference(event.newValue);
        apply();
    });
}());
