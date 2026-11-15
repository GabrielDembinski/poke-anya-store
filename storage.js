/* Shared catalogue and orders. The server is authoritative; local keys are kept only for explicit migration. */
(function () {
    'use strict';
    const LEGACY_KEY = 'pokeanya-store-v1';
    const RECEIPTS_KEY = 'pokeanya-order-receipts';
    const ATTEMPT_KEY = 'pokeanya-order-attempt';
    const adminPage = /\/admin\.html$/i.test(location.pathname);
    const clone = value => value == null ? value : JSON.parse(JSON.stringify(value));
    const channel = typeof BroadcastChannel === 'function' ? new BroadcastChannel('pokeanya-store-updates') : null;
    const documentReady = document.readyState === 'complete' ? Promise.resolve() : new Promise(resolve => document.addEventListener('DOMContentLoaded', resolve, { once: true }));
    let state = { version: 1, products: [], orders: [], movements: [], reviews: [] };
    let status = 'loading';
    let loadError = '';
    let visitorCsrf = '';
    let adminCsrf = '';
    let refreshing = null;
    let visitor = null;

    function failure(message, code = 'service_unavailable', responseStatus = 0) {
        const error = new Error(message);
        error.code = code;
        error.status = responseStatus;
        return error;
    }
    function notify(type) {
        window.dispatchEvent(new CustomEvent('pokeanya:change', { detail: { type, status, error: loadError } }));
    }
    function applyVisitor(value) {
        if (!value || typeof value.id !== 'string' || typeof value.nome !== 'string') return;
        const changed = !visitor || visitor.id !== value.id || visitor.nome !== value.nome;
        visitor = { id: value.id, nome: value.nome };
        if (changed) window.dispatchEvent(new CustomEvent('pokeanya:visitor', { detail: clone(visitor) }));
    }
    async function request(path, body, options = {}) {
        if (!['http:', 'https:'].includes(location.protocol)) throw failure('Abra a loja pelo servidor PHP para acessar os dados compartilhados.');
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 20000);
        let response, payload;
        try {
            response = await fetch(new URL(path, location.href), {
                method: body === undefined ? 'GET' : 'POST',
                credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: { Accept: 'application/json', ...(body === undefined ? {} : { 'Content-Type': 'application/json', 'X-CSRF-Token': options.csrf || '' }), ...(options.token ? { Authorization: 'Bearer ' + options.token } : {}) },
                ...(body === undefined ? {} : { body: JSON.stringify(body) })
            });
            if (!response.headers.get('content-type')?.includes('application/json')) throw failure('Não foi possível acessar os dados da loja. Confira o servidor e tente novamente.');
            payload = await response.json();
        } catch (error) {
            if (typeof error.code === 'string') throw error;
            throw failure('Não foi possível conectar à loja. Confira sua conexão e tente novamente.');
        } finally { clearTimeout(timer); }
        if (!response.ok || payload?.ok !== true) {
            const error = failure(payload?.error?.message || 'Não foi possível concluir esta operação.', payload?.error?.code || 'request_failed', response.status);
            error.field = payload?.error?.field || '';
            throw error;
        }
        return payload;
    }
    function apply(payload, type = 'sync') {
        if (!Array.isArray(payload.products)) throw failure('O servidor não retornou um catálogo válido.');
        state.products = clone(payload.products);
        if (adminPage) {
            if (!Array.isArray(payload.orders) || !Array.isArray(payload.movements)) throw failure('Não foi possível carregar os pedidos administrativos.');
            state.orders = clone(payload.orders);
            state.movements = clone(payload.movements);
            if (typeof payload.csrf === 'string') adminCsrf = payload.csrf;
        } else if (typeof payload.csrf === 'string') visitorCsrf = payload.csrf;
        if (Array.isArray(payload.reviews)) state.reviews = clone(payload.reviews);
        status = 'ready';
        loadError = '';
        applyVisitor(payload.visitor);
        notify(type);
    }
    async function refresh() {
        if (refreshing) return refreshing;
        refreshing = (async () => {
            try {
                await documentReady;
                if (adminPage) {
                    if (!window.PokeAdminAuth) throw failure('Entre na conta administrativa para acessar o painel.');
                    await window.PokeAdminAuth.ready;
                    if (!window.PokeAdminAuth.getAdmin()) throw failure('Entre na conta administrativa para acessar o painel.', 'authentication_required', 401);
                }
                apply(await request(adminPage ? 'api/admin/store' : 'api/store/catalog'));
                return clone(state);
            } catch (error) {
                if (adminPage && error.status === 401) {
                    state = { version: 1, products: [], orders: [], movements: [], reviews: [] };
                    adminCsrf = '';
                    await window.PokeAdminAuth.refresh();
                }
                status = 'error';
                loadError = error.message;
                notify('connection-error');
                throw error;
            } finally { refreshing = null; }
        })();
        return refreshing;
    }
    async function visitorMutation(action, input) {
        await ready;
        const session = await request('api/store/session');
        visitorCsrf = session.csrf || '';
        applyVisitor(session.visitor);
        const payload = await request('api/store/' + action, input, { csrf: visitorCsrf });
        if (Array.isArray(payload.reviews)) {
            state.reviews = clone(payload.reviews);
            notify('reviews');
        }
        applyVisitor(payload.visitor);
        channel?.postMessage({ type: 'changed' });
        return payload;
    }
    async function adminMutation(action, input) {
        if (!adminPage || !window.PokeAdminAuth) throw failure('Entre no painel administrativo para alterar a loja.', 'authentication_required', 401);
        await window.PokeAdminAuth.requireSession();
        const current = await request('api/admin/store');
        adminCsrf = current.csrf || '';
        const payload = await request('api/admin/store/' + action, input, { csrf: adminCsrf });
        apply(payload, action);
        channel?.postMessage({ type: 'changed' });
        return clone(payload.result);
    }
    function receipts() {
        try {
            const values = JSON.parse(sessionStorage.getItem(RECEIPTS_KEY) || '[]');
            return Array.isArray(values) ? values.filter(value => value && typeof value.id === 'string' && typeof value.token === 'string').slice(-20) : [];
        } catch (_) { return []; }
    }
    function rememberReceipt(id, token) {
        try { sessionStorage.setItem(RECEIPTS_KEY, JSON.stringify([...receipts().filter(item => item.id !== id), { id, token }].slice(-20))); }
        catch (_) { /* The confirmed order remains on the server even without receipt persistence. */ }
    }
    function legacyData() {
        let raw;
        try { raw = localStorage.getItem(LEGACY_KEY); }
        catch (_) { throw failure('Não foi possível ler os dados antigos deste navegador.', 'legacy_unavailable'); }
        if (!raw) return null;
        try {
            const parsed = JSON.parse(raw);
            if (parsed?.version !== 1 || !Array.isArray(parsed.products) || !Array.isArray(parsed.orders) || !Array.isArray(parsed.movements)) throw new Error();
            return clone(parsed);
        } catch (_) { throw failure('Os dados antigos deste navegador estão danificados. Nenhum dado foi removido.', 'legacy_invalid'); }
    }
    function legacyReviewsData() {
        try {
            const raw = localStorage.getItem('pokeanya-reviews-v1');
            if (!raw) return null;
            const parsed = JSON.parse(raw);
            if (parsed?.version !== 1 || !Array.isArray(parsed.reviews)) throw new Error();
            return clone(parsed);
        } catch (_) { throw failure('Não foi possível ler as avaliações antigas deste navegador. Nenhum dado foi removido.', 'legacy_invalid'); }
    }
    function signature(value) {
        const text = JSON.stringify(value);
        let hash = 2166136261;
        for (let index = 0; index < text.length; index += 1) hash = Math.imul(hash ^ text.charCodeAt(index), 16777619);
        return text.length + ':' + (hash >>> 0).toString(16);
    }
    function alreadyMigrated(key, value) {
        try { return localStorage.getItem(key + '-server-migrated') === signature(value); }
        catch (_) { return false; }
    }
    function markMigrated(key, value) {
        try { localStorage.setItem(key + '-server-migrated', signature(value)); }
        catch (_) { /* Only this migration marker is optional; original local data stays intact. */ }
        notify('migration');
    }
    const ready = refresh().catch(() => clone(state));
    const api = {
        ready, refresh,
        getStatus() { return { status, error: loadError }; },
        getProducts() { return clone(state.products); },
        getOrders() { return clone(state.orders); },
        getReviews() { return clone(state.reviews); },
        getVisitor() { return clone(visitor); },
        isPersistent() { return status === 'ready'; },
        getStorageError() { return loadError; },
        saveProduct(product) { return adminMutation('products/save', { product }); },
        removeProduct(id) { return adminMutation('products/remove', { id }); },
        adjustStock(id, quantity, reason) { return adminMutation('stock', { id, quantity, reason }); },
        approveOrder(id) { return adminMutation('orders/approve', { id }); },
        cancelOrder(id) { return adminMutation('orders/cancel', { id }); },
        async createOrder(input) {
            const fingerprint = window.crypto?.subtle ? Array.from(new Uint8Array(await window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(JSON.stringify(input))))).map(byte => byte.toString(16).padStart(2, '0')).join('') : '';
            let attempt;
            try { attempt = JSON.parse(sessionStorage.getItem(ATTEMPT_KEY) || 'null'); } catch (_) { /* A new attempt will be generated. */ }
            if (!fingerprint || !attempt || attempt.fingerprint !== fingerprint || typeof attempt.key !== 'string') {
                attempt = { fingerprint, key: window.crypto?.randomUUID?.() || Date.now().toString(36) + '-' + Math.random().toString(36).slice(2) };
                try { sessionStorage.setItem(ATTEMPT_KEY, JSON.stringify(attempt)); } catch (_) { /* In-memory duplicate prevention still applies in the form. */ }
            }
            const payload = await visitorMutation('orders', { ...input, idempotencyKey: attempt.key });
            if (!payload.order || typeof payload.orderToken !== 'string') throw failure('O pedido foi enviado, mas não foi possível recuperar seu comprovante. Consulte a loja antes de tentar novamente.', 'invalid_order_response');
            rememberReceipt(payload.order.id, payload.orderToken);
            state.orders = [payload.order, ...state.orders.filter(order => order.id !== payload.order.id)].slice(0, 20);
            try { sessionStorage.removeItem(ATTEMPT_KEY); } catch (_) { /* No private token is written to localStorage. */ }
            notify('order-created');
            return clone(payload.order);
        },
        async getOrder(id) {
            if (adminPage) return clone(state.orders.find(order => order.id === id) || null);
            const receipt = receipts().find(item => item.id === id);
            if (!receipt) throw failure('Este navegador não tem o comprovante privado desse pedido.', 'receipt_missing', 403);
            const payload = await request('api/store/orders/' + encodeURIComponent(id), undefined, { token: receipt.token });
            state.orders = [payload.order, ...state.orders.filter(order => order.id !== id)].slice(0, 20);
            return clone(payload.order);
        },
        async saveReview(input) { const payload = await visitorMutation('reviews/save', input); return clone(payload.review); },
        async removeReview(id) { const payload = await visitorMutation('reviews/remove', { id }); return clone(payload.review || null); },
        async exportData() {
            if (!adminPage || !window.PokeAdminAuth) throw failure('Entre no painel administrativo para exportar os dados.', 'authentication_required', 401);
            await window.PokeAdminAuth.requireSession();
            const payload = await request('api/admin/store/export');
            return clone(payload.data);
        },
        importData(data) { return adminMutation('import', { data }); },
        async migrateLegacyData() {
            const data = legacyData();
            if (!data) throw failure('Não há dados antigos neste navegador.', 'legacy_missing');
            const result = await adminMutation('import', { data });
            markMigrated(LEGACY_KEY, data);
            return result;
        },
        getLegacyReviewsData: legacyReviewsData,
        hasLegacyReviewsData() {
            try { const data = legacyReviewsData(); return Boolean(data?.reviews.length && !alreadyMigrated('pokeanya-reviews-v1', data)); }
            catch (_) { return true; }
        },
        async importLegacyReviews(data) {
            const result = await adminMutation('reviews/import', { data });
            markMigrated('pokeanya-reviews-v1', data);
            return result;
        },
        getLegacyData: legacyData,
        exportLegacyData: legacyData,
        hasLegacyData() {
            try { const old = legacyData(); return Boolean(old && (old.products.length || old.orders.length || old.movements.length) && !alreadyMigrated(LEGACY_KEY, old)); }
            catch (_) { return true; }
        }
    };
    window.PokeStore = Object.freeze(api);
    function scheduleRefresh() { if (!document.hidden) refresh().catch(() => {}); }
    if (channel) channel.addEventListener('message', event => { if (event.data?.type === 'changed') scheduleRefresh(); });
    window.addEventListener('focus', scheduleRefresh);
    window.addEventListener('pageshow', event => { if (event.persisted) scheduleRefresh(); });
    document.addEventListener('visibilitychange', scheduleRefresh);
    setInterval(scheduleRefresh, 30000);
}());
