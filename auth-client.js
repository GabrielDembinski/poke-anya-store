/* Customer identity comes from the server session, never from browser storage. */
(function () {
    'use strict';
    let csrf = '';
    let state = { status: 'loading', user: null, providers: { google: false }, pendingRegistration: null };
    const clone = value => JSON.parse(JSON.stringify(value));
    const endpoint = new URL('api/auth/', location.href);
    const serviceMessage = 'Não foi possível acessar o sistema de contas. Abra a loja pelo endereço mostrado ao iniciar o sistema.';
    const channel = typeof BroadcastChannel === 'function' ? new BroadcastChannel('pokeanya-customer-auth') : null;

    function failure(message, code = 'service_unavailable', field = '', status = 0) {
        const error = new Error(message);
        error.code = code;
        error.field = field;
        error.status = status;
        return error;
    }
    function getInitials(username) {
        const parts = String(username || '')
            .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
            .replace(/([A-Z])([A-Z][a-z])/g, '$1 $2')
            .split(/[^A-Za-z0-9]+/).filter(Boolean);
        const words = parts.map(part => part.replace(/[0-9]/g, '')).filter(Boolean);
        const initials = words.length > 1 ? words[0][0] + words[words.length - 1][0] : (words[0] || parts.join('')).slice(0, 2);
        return initials.toUpperCase() || '?';
    }
    function notify() {
        document.querySelectorAll('[data-customer-account-label]').forEach(label => {
            label.textContent = state.user ? getInitials(state.user.username) : 'Entrar';
            if (state.user) label.setAttribute('aria-hidden', 'true');
            else label.removeAttribute('aria-hidden');
        });
        const link = document.getElementById('customer-account-link');
        if (link) {
            link.classList.toggle('is-authenticated', Boolean(state.user));
            const label = state.user ? `Minha conta: ${state.user.username}` : 'Entrar na conta de cliente';
            link.setAttribute('aria-label', label);
            link.title = label;
        }
        window.dispatchEvent(new CustomEvent('pokeanya:auth', { detail: clone(state) }));
    }
    function apply(payload) {
        if (typeof payload.csrf === 'string') csrf = payload.csrf;
        if (Object.prototype.hasOwnProperty.call(payload, 'user')) {
            const user = payload.user;
            if (user !== null && (!user || typeof user.id !== 'string' || typeof user.username !== 'string' || typeof user.email !== 'string' || user.role !== 'customer')) throw failure(serviceMessage);
            state.user = user ? clone(user) : null;
            state.status = user ? 'authenticated' : 'anonymous';
        }
        if (payload.providers && typeof payload.providers === 'object') state.providers = { google: payload.providers.google === true };
        if (Object.prototype.hasOwnProperty.call(payload, 'pendingRegistration')) state.pendingRegistration = payload.pendingRegistration?.provider === 'google' ? clone(payload.pendingRegistration) : null;
        notify();
    }
    async function request(action, body, method = 'POST') {
        if (!['http:', 'https:'].includes(location.protocol)) throw failure(serviceMessage);
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 12000);
        let response;
        let payload;
        try {
            response = await fetch(new URL(action, endpoint), {
                method,
                credentials: 'same-origin',
                headers: { Accept: 'application/json', ...(method === 'POST' ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf } : {}) },
                ...(method === 'POST' ? { body: JSON.stringify(body || {}) } : {}),
                cache: 'no-store',
                signal: controller.signal
            });
            if (!response.headers.get('content-type')?.includes('application/json')) throw failure(serviceMessage);
            payload = await response.json();
        } catch (error) {
            if (typeof error.code === 'string') throw error;
            throw failure(serviceMessage);
        } finally { clearTimeout(timer); }
        if (!response.ok || payload?.ok !== true) {
            const info = payload && typeof payload.error === 'object' ? payload.error : {};
            throw failure(typeof info.message === 'string' ? info.message : 'Não foi possível concluir esta ação. Tente novamente.', typeof info.code === 'string' ? info.code : 'request_failed', typeof info.field === 'string' ? info.field : '', response.status);
        }
        apply(payload);
        return payload;
    }
    async function refresh() {
        try { await request('session', undefined, 'GET'); }
        catch (_) {
            csrf = '';
            state = { status: 'unavailable', user: null, providers: { google: false }, pendingRegistration: null };
            notify();
        }
        return clone(state);
    }
    async function mutate(action, body) {
        await ready;
        if (!csrf || state.status === 'unavailable') await refresh();
        if (!csrf || state.status === 'unavailable') throw failure(serviceMessage);
        try {
            const result = await request(action, body);
            channel?.postMessage({ type: 'session-changed' });
            return result;
        }
        catch (error) {
            if (error.status === 401 || error.code === 'csrf_invalid' || error.code === 'invalid_csrf' ||
                (action === 'complete-profile' && ['oauth_expired', 'account_exists', 'email_exists'].includes(error.code))) await refresh();
            throw error;
        }
    }
    function loginURL(returnTo) {
        const login = new URL('conta.html', location.href);
        if (returnTo) {
            try {
                const next = new URL(returnTo, location.href);
                const home = new URL('index.html', location.href);
                if (next.origin === home.origin && next.pathname === home.pathname) login.searchParams.set('next', next.pathname + next.search + next.hash);
            } catch (_) { /* Ignore invalid or external return locations. */ }
        }
        return login.pathname + login.search;
    }
    const ready = refresh();
    window.PokeAuth = Object.freeze({
        ready,
        getUser() { return state.user ? clone(state.user) : null; },
        getState() { return clone(state); },
        getInitials,
        refresh,
        register(input) { return mutate('register', input); },
        login(input) { return mutate('login', input); },
        logout() { return mutate('logout', {}); },
        completeProfile(input) { return mutate('complete-profile', input); },
        cancelOAuth() { return mutate('cancel-oauth', {}); },
        async startProvider(provider) {
            if (provider !== 'google') throw failure('Escolha um método de entrada válido.', 'invalid_provider');
            const result = await mutate(`oauth/${provider}/start`, {});
            let destination;
            try { destination = new URL(result.authorizationUrl); } catch (_) { throw failure('Não foi possível abrir este método de entrada.', 'invalid_provider_response'); }
            if (destination.protocol !== 'https:' || destination.hostname !== 'accounts.google.com' || destination.username || destination.password) throw failure('Não foi possível abrir este método de entrada.', 'invalid_provider_response');
            location.assign(destination.href);
        },
        loginURL,
        openLogin(returnTo) { location.assign(loginURL(returnTo)); }
    });
    window.addEventListener('pageshow', event => { if (event.persisted) refresh(); });
    if (channel) channel.addEventListener('message', event => { if (event.data?.type === 'session-changed') refresh(); });
    document.addEventListener('visibilitychange', () => { if (!document.hidden && state.status !== 'loading') refresh(); });
    document.addEventListener('DOMContentLoaded', notify, { once: true });
}());
