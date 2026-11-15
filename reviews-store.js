/* Shared feedback is owned by the anonymous visitor session, without customer accounts. */
(function () {
    'use strict';
    const LEGACY_KEY = 'pokeanya-reviews-v1';
    const clone = value => value == null ? value : JSON.parse(JSON.stringify(value));
    let reviews = [];
    function profile() {
        return window.PokeStore?.getVisitor() || null;
    }
    function notify(type) { window.dispatchEvent(new CustomEvent('pokeanya:reviews', { detail: { type } })); }
    function sync() { reviews = window.PokeStore?.getReviews() || []; notify('sync'); }
    function legacy() {
        try { const raw = localStorage.getItem(LEGACY_KEY); return raw ? JSON.parse(raw) : null; }
        catch (_) { throw new Error('Não foi possível ler as avaliações antigas deste navegador. Nenhum dado foi removido.'); }
    }
    const api = {
        ready: Promise.resolve(window.PokeStore?.ready).then(sync),
        refresh() { return window.PokeStore.refresh().then(sync); },
        listReviews(produtoId) { return clone(reviews.filter(review => produtoId === undefined || review.produtoId === produtoId).sort((a, b) => b.createdAt.localeCompare(a.createdAt) || b.id.localeCompare(a.id))); },
        getProfile() { return clone(profile()); },
        getOwnReview(produtoId) { const author = profile(); return clone(author ? reviews.find(review => !review.legacy && review.produtoId === produtoId && review.autorId === author.id) || null : null); },
        getSummary(produtoId) {
            const values = api.listReviews(produtoId);
            const distribution = { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 };
            values.forEach(review => { distribution[review.nota] += 1; });
            return { count: values.length, average: values.length ? values.reduce((sum, review) => sum + review.nota, 0) / values.length : 0, distribution };
        },
        async saveReview(input) {
            const saved = await window.PokeStore.saveReview({ produtoId: input.produtoId, nome: input.nome, nota: input.nota, comentario: input.comentario });
            sync();
            return saved;
        },
        async removeReview(id) {
            const author = profile();
            if (!author || !reviews.some(review => review.id === id && !review.legacy && review.autorId === author.id)) throw new Error('Você só pode excluir avaliações feitas neste navegador.');
            const removed = await window.PokeStore.removeReview(id);
            sync();
            return removed;
        },
        isPersistent() { return window.PokeStore?.isPersistent() || false; },
        getStorageError() { return window.PokeStore?.getStorageError() || ''; },
        exportData() { return { version: 1, profile: clone(profile()), reviews: clone(reviews) }; },
        exportLegacyData: legacy,
        getLegacyData: legacy,
        hasLegacyData() { try { return Boolean(legacy()?.reviews?.length); } catch (_) { return true; } },
        importData(input) { return window.PokeStore.importLegacyReviews(input); }
    };
    window.addEventListener('pokeanya:change', sync);
    window.addEventListener('pokeanya:visitor', () => notify('identity'));
    window.PokeReviews = Object.freeze(api);
}());
