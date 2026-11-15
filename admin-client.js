/* Administrative identity uses its own server session and cookie. */
(function () {
    'use strict';
    const endpoint = new URL('api/admin/auth/', location.href);
    const protectedPage = document.documentElement.hasAttribute('data-admin-pending');
    const serviceMessage = 'Não foi possível acessar o sistema administrativo. Abra a loja pelo INICIAR-LOJA.bat e tente novamente.';
    const clone = value => JSON.parse(JSON.stringify(value));
    let csrf = '';
    let state = { status: 'loading', admin: null, configured: false };
    let lastActivityCheck = Date.now();
    let checkingActivity = false;
    const channel = typeof BroadcastChannel === 'function' ? new BroadcastChannel('pokeanya-admin-auth') : null;
    function failure(message, code = 'service_unavailable', status = 0) { const error = new Error(message); error.code = code; error.status = status; return error; }
    function loginURL() {
        const login = new URL('admin-login.html', location.href);
        if (protectedPage) login.searchParams.set('next', 'admin.html' + location.hash);
        return login.pathname + login.search;
    }
    function notify() {
        if (protectedPage) {
            const loading = document.getElementById('admin-access-status');
            if (state.status === 'authenticated') {
                document.documentElement.removeAttribute('data-admin-pending');
                if (loading) loading.hidden = true;
                document.querySelectorAll('[data-admin-name]').forEach(label => { label.textContent = state.admin.name; });
            } else {
                document.documentElement.setAttribute('data-admin-pending', '');
                document.querySelectorAll('dialog[open]').forEach(dialog => dialog.close());
                if (loading) { loading.hidden = false; loading.textContent = state.status === 'unavailable' ? serviceMessage : 'Verificando seu acesso administrativo…'; }
                if (state.status === 'anonymous') location.replace(loginURL());
            }
        }
        window.dispatchEvent(new CustomEvent('pokeanya:admin-auth', { detail: clone(state) }));
    }
    function apply(payload) {
        if (typeof payload.csrf !== 'string' || !Object.prototype.hasOwnProperty.call(payload, 'admin')) throw failure(serviceMessage);
        const admin = payload.admin;
        if (admin !== null && (!admin || admin.role !== 'admin' || typeof admin.id !== 'string' || typeof admin.name !== 'string' || typeof admin.email !== 'string')) throw failure(serviceMessage);
        csrf = payload.csrf;
        state = { status: admin ? 'authenticated' : 'anonymous', admin: admin ? clone(admin) : null, configured: payload.configured === true };
        notify();
    }
    async function request(action, body, method = 'POST') {
        if (!['http:', 'https:'].includes(location.protocol)) throw failure(serviceMessage);
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 12000);
        let response, payload;
        try {
            response = await fetch(new URL(action, endpoint), {
                method, credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: { Accept: 'application/json', ...(method === 'POST' ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf } : {}) },
                ...(method === 'POST' ? { body: JSON.stringify(body || {}) } : {})
            });
            if (!response.headers.get('content-type')?.includes('application/json')) throw failure(serviceMessage);
            payload = await response.json();
        } catch (_) { throw failure(serviceMessage); }
        finally { clearTimeout(timer); }
        if (!response.ok || payload?.ok !== true) throw failure(payload?.error?.message || 'Não foi possível concluir. Tente novamente.', payload?.error?.code || 'request_failed', response.status);
        apply(payload);
        return payload;
    }
    async function refresh() {
        try { await request('session', undefined, 'GET'); }
        catch (_) { csrf = ''; state = { status: 'unavailable', admin: null, configured: false }; notify(); }
        return clone(state);
    }
    function recordActivity(event) {
        if (!protectedPage || !event.isTrusted || document.hidden || state.status !== 'authenticated' || checkingActivity) return;
        const now = Date.now();
        if (now - lastActivityCheck < 60000) return;
        lastActivityCheck = now;
        checkingActivity = true;
        request('session', undefined, 'GET')
            .catch(() => { /* A temporary connection failure must not discard the open product form. */ })
            .finally(() => { checkingActivity = false; });
    }
    async function mutate(action, body) {
        await ready;
        if (!csrf || state.status === 'unavailable') await refresh();
        if (!csrf) throw failure(serviceMessage);
        try { const result = await request(action, body); channel?.postMessage({ type: 'session-changed' }); return result; }
        catch (error) { if (error.status === 401 || error.code === 'invalid_csrf' || error.code === 'admin_not_configured') await refresh(); throw error; }
    }
    const ready = refresh();
    window.PokeAdminAuth = Object.freeze({
        ready, refresh,
        getState() { return clone(state); },
        getAdmin() { return state.admin ? clone(state.admin) : null; },
        login(input) { return mutate('login', input); },
        logout() { return mutate('logout', {}); },
        async requireSession() { await refresh(); if (!state.admin) throw failure(state.status === 'unavailable' ? serviceMessage : 'Sua sessão terminou. Entre novamente para continuar.', 'authentication_required', 401); },
        loginURL
    });
    if (channel) channel.addEventListener('message', event => { if (event.data?.type === 'session-changed') refresh(); });
    window.addEventListener('pageshow', event => { if (event.persisted) refresh(); });
    document.addEventListener('visibilitychange', () => { if (!document.hidden && state.status !== 'loading') refresh(); });
    // Only user input renews an open panel; automatic catalogue polling does not.
    ['pointerdown', 'keydown', 'input', 'wheel'].forEach(type => document.addEventListener(type, recordActivity, { passive: true, capture: true }));
    document.addEventListener('DOMContentLoaded', notify, { once: true });
    document.addEventListener('click', async event => {
        const button = event.target instanceof Element ? event.target.closest('#admin-logout') : null;
        if (!button || button.disabled) return;
        button.disabled = true;
        try { await window.PokeAdminAuth.logout(); location.replace(new URL('admin-login.html', location.href).href); }
        catch (error) { const message = document.getElementById('storage-warning'); if (message) { message.textContent = error.message; message.hidden = false; } }
        finally { button.disabled = false; }
    });
}());
