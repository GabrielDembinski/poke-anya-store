/* Shared product feedback. The server owns authorship and permissions. */
(function () {
    'use strict';
    const escape = value => String(value ?? '').replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character]));
    const gradeNames = ['', 'Ruim', 'Regular', 'Bom', 'Muito bom', 'Excelente'];
    const number = value => Number(value).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
    let current = { id: null, host: null, limit: 5 };
    let communityLimit = 3;
    let pendingDelete = null;
    let deleteError = '';
    let saving = false;
    let activeAuthor = null;

    function store() { return window.PokeReviews; }
    function product(id) { return window.PokeStore?.getProducts().find(item => item.id === id); }
    function initials(name) { return String(name).trim().split(/\s+/).filter(Boolean).slice(0, 2).map(word => Array.from(word)[0]).join('').toLocaleUpperCase('pt-BR'); }
    function date(value) {
        const parsed = new Date(value);
        return Number.isFinite(parsed.getTime()) ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'medium', timeZone: 'America/Sao_Paulo' }).format(parsed) : '';
    }
    function ratingHTML(value, className = '') {
        const rounded = Math.round(Number(value) || 0);
        return `<span class="review-balls ${className}" aria-hidden="true">${[1, 2, 3, 4, 5].map(score => `<span class="review-ball${score <= rounded ? ' is-filled' : ''}"></span>`).join('')}</span>`;
    }
    function productRatingHTML(id) {
        const summary = store()?.getSummary(id) || { count: 0, average: 0 };
        const label = summary.count ? `${summary.count} ${summary.count === 1 ? 'avaliação' : 'avaliações'}; nota ${number(summary.average)} de 5 Pokébolas` : 'Avaliar este produto';
        return `<button type="button" class="product-review-link" data-review-product="${escape(id)}" aria-label="${escape(label)}"><span class="review-ball${summary.count ? ' is-filled' : ''}" aria-hidden="true"></span><span>${summary.count ? `${number(summary.average)} <span class="review-inline-count">(${summary.count})</span>` : 'Avaliar produto'}</span></button>`;
    }
    function reviewCard(review, options = {}) {
        const own = !review.legacy && !options.community && store().getProfile()?.id === review.autorId;
        const confirming = own && pendingDelete === review.id;
        const linkedProduct = options.community ? product(review.produtoId) : null;
        return `<article class="review-card" data-review-id="${escape(review.id)}"><div class="review-card-head"><span class="review-avatar" aria-hidden="true">${escape(initials(review.nome))}</span><div class="review-person"><strong>${escape(review.nome)}</strong><span><time datetime="${escape(review.createdAt)}">${escape(date(review.createdAt))}</time>${review.updatedAt !== review.createdAt ? ' · Editada' : ''}${review.legacy ? ' · Histórico importado' : String(review.autorId).startsWith('visitante-') ? ' · Visitante' : ''}</span></div></div><div class="review-card-rating" aria-label="${review.nota} de 5 Pokébolas">${ratingHTML(review.nota)}<span>${review.nota}/5</span></div><p class="review-comment">${escape(review.comentario)}</p>${linkedProduct ? `<button type="button" class="review-product-context" data-review-product="${escape(review.produtoId)}"><span>Sobre</span> ${escape(linkedProduct.nome)} <span aria-hidden="true">↗</span></button>` : ''}${own ? `<div class="review-own-actions" ${confirming ? 'hidden' : ''}><span>Sua avaliação</span><button type="button" data-review-edit="${escape(review.id)}">Editar</button><button type="button" data-review-delete="${escape(review.id)}">Excluir</button></div>${confirming ? `<div class="review-delete-confirm" data-review-confirm="${escape(review.id)}"><p>Excluir sua avaliação deste produto?</p><div><button type="button" data-review-delete-cancel>Manter avaliação</button><button type="button" class="review-delete-action" data-review-delete-confirm="${escape(review.id)}">Excluir avaliação</button></div>${deleteError ? `<p class="review-error" role="alert">${escape(deleteError)}</p>` : ''}</div>` : ''}` : ''}</article>`;
    }
    function captureDraft() {
        const form = current.host?.querySelector('#review-form');
        if (!form) return null;
        return {
            open: !form.hidden,
            nome: form.querySelector('#review-name').value,
            comentario: form.querySelector('#review-comment').value,
            nota: form.querySelector('[name="review-rating"]:checked')?.value || '',
            error: form.querySelector('#review-form-error').hidden ? '' : form.querySelector('#review-form-error').textContent
        };
    }
    function fillForm(values) {
        const form = current.host?.querySelector('#review-form');
        if (!form) return;
        form.querySelector('#review-name').value = values.nome || '';
        form.querySelector('#review-comment').value = values.comentario || '';
        form.querySelectorAll('[name="review-rating"]').forEach(input => { input.checked = input.value === String(values.nota || ''); });
        const error = form.querySelector('#review-form-error');
        error.textContent = values.error || '';
        error.hidden = !values.error;
        updateRating();
        updateCounter();
    }
    function updateRating() {
        const form = current.host?.querySelector('#review-form');
        if (!form) return;
        const chosen = Number(form.querySelector('[name="review-rating"]:checked')?.value || 0);
        form.querySelectorAll('[data-rating-choice]').forEach(label => label.querySelector('.review-ball').classList.toggle('is-filled', Number(label.dataset.ratingChoice) <= chosen));
        form.querySelector('#review-rating-description').textContent = chosen ? `${chosen} de 5 · ${gradeNames[chosen]}` : 'Escolha de 1 a 5 Pokébolas';
    }
    function updateCounter() {
        const field = current.host?.querySelector('#review-comment');
        if (field) current.host.querySelector('#review-character-count').textContent = `${field.value.length}/1200`;
    }
    function setStatus(message) {
        const status = current.host?.querySelector('#review-form-status');
        if (status) status.textContent = message;
    }
    function formHTML() {
        return `<form id="review-form" class="review-form" hidden><h4>Sua experiência ajuda outros fãs.</h4><label class="review-field" for="review-name">Seu nome<input id="review-name" name="nome" autocomplete="name" minlength="2" maxlength="80" required></label><fieldset class="review-rating-field"><legend>Sua nota</legend><div class="review-rating-options">${[1, 2, 3, 4, 5].map(score => `<label class="review-rating-option" data-rating-choice="${score}"><input type="radio" id="review-rating-${score}" name="review-rating" value="${score}" required aria-label="${score} de 5 Pokébolas: ${gradeNames[score]}"><span class="review-rating-target"><span class="review-ball" aria-hidden="true"></span><span aria-hidden="true">${score}</span></span></label>`).join('')}</div><p id="review-rating-description" class="review-rating-description" aria-live="polite">Escolha de 1 a 5 Pokébolas</p></fieldset><label class="review-field" for="review-comment">Conte o que achou<textarea id="review-comment" name="comentario" rows="4" minlength="10" maxlength="1200" required placeholder="Como foi sua experiência com este produto?"></textarea></label><div class="review-comment-hint"><span>Pelo menos 10 caracteres</span><span id="review-character-count">0/1200</span></div><p class="review-local-note">O nome é informado pelo visitante e a avaliação não confirma uma compra. Você pode gerenciar seu comentário neste navegador.</p><p id="review-form-error" class="review-error" role="alert" hidden></p><div class="review-form-actions"><button type="button" class="review-secondary" data-review-form-cancel>Cancelar</button><button type="submit" class="button purple" id="review-submit">Salvar avaliação</button></div></form>`;
    }
    function mountProduct(id, host) {
        if (!host || !store()) return;
        const authorId = store().getProfile()?.id || null;
        const draft = current.id === id && authorId === activeAuthor ? captureDraft() : null;
        activeAuthor = authorId;
        const previousLimit = current.id === id ? current.limit : 5;
        current = { id, host, limit: previousLimit };
        pendingDelete = null;
        deleteError = '';
        host.innerHTML = `<div class="reviews-product-heading"><div><span class="kicker">DE FÃ PARA FÃ</span><h3>Avaliações do produto</h3></div><button type="button" class="review-secondary" data-review-write>Escrever avaliação</button></div><div id="product-review-summary" class="review-product-summary"></div><p id="review-storage-warning" class="review-error" role="alert" hidden></p>${formHTML()}<p id="review-form-status" class="review-status" role="status" aria-live="polite"></p><div id="product-review-list" class="review-product-list"></div><button type="button" class="review-load-more" data-review-load-more hidden>Ver mais avaliações</button>`;
        if (draft) {
            fillForm(draft);
            host.querySelector('#review-form').hidden = !draft.open;
        } else fillForm({ nome: store().getProfile()?.nome || '' });
        refreshProduct();
    }
    function refreshProduct() {
        const host = current.host;
        if (!host?.isConnected || !store()) return;
        const summary = store().getSummary(current.id);
        host.querySelector('#product-review-summary').innerHTML = summary.count ? `<div class="review-average"><strong>${number(summary.average)}</strong><div><span class="review-average-balls" aria-label="Média ${number(summary.average)} de 5 Pokébolas">${ratingHTML(summary.average)}</span><span>${summary.count} ${summary.count === 1 ? 'avaliação' : 'avaliações'} deste produto</span></div></div>` : `<div class="review-no-ratings"><span class="review-ball" aria-hidden="true"></span><p>Este favorito ainda não tem avaliações.<br><strong>Seja a primeira pessoa a compartilhar uma experiência.</strong></p></div>`;
        const own = store().getOwnReview(current.id);
        host.querySelector('[data-review-write]').textContent = own ? 'Editar minha avaliação' : 'Escrever avaliação';
        host.querySelector('#review-submit').textContent = own ? 'Salvar alterações' : 'Salvar avaliação';
        const reviews = store().listReviews(current.id);
        host.querySelector('#product-review-list').innerHTML = reviews.slice(0, current.limit).map(review => reviewCard(review)).join('');
        host.querySelector('[data-review-load-more]').hidden = reviews.length <= current.limit;
        const warning = host.querySelector('#review-storage-warning');
        warning.hidden = store().isPersistent();
        warning.textContent = warning.hidden ? '' : store().getStorageError() || 'Não foi possível acessar as avaliações da loja. Confira sua conexão.';
        host.querySelector('#review-form').setAttribute('aria-busy', String(saving));
        host.querySelectorAll('#review-submit,[data-review-write],[data-review-edit],[data-review-delete],[data-review-delete-confirm],[data-review-form-cancel]').forEach(button => { button.disabled = saving; });
    }
    function openForm() {
        if (!current.host?.isConnected || !product(current.id)) return;
        const form = current.host.querySelector('#review-form');
        const own = store().getOwnReview(current.id);
        if (form.hidden) fillForm(own || { nome: store().getProfile()?.nome || '' });
        form.hidden = false;
        setStatus('');
        (form.querySelector('#review-name').value ? form.querySelector('[name="review-rating"]:checked') || form.querySelector('[name="review-rating"]') : form.querySelector('#review-name')).focus({ preventScroll: true });
        form.scrollIntoView({ behavior: 'auto', block: 'nearest' });
    }
    function renderCommunity() {
        const summaryHost = document.getElementById('community-review-summary');
        const listHost = document.getElementById('community-review-list');
        const more = document.getElementById('community-review-more');
        if (!summaryHost || !listHost || !more || !store() || !window.PokeStore) return;
        const liveProducts = new Set(window.PokeStore.getProducts().map(item => item.id));
        const reviews = store().listReviews().filter(review => liveProducts.has(review.produtoId));
        const average = reviews.length ? reviews.reduce((total, review) => total + review.nota, 0) / reviews.length : 0;
        summaryHost.innerHTML = reviews.length ? `<div class="review-community-score"><strong>${number(average)}<span>/5</span></strong><span aria-label="Média ${number(average)} de 5 Pokébolas">${ratingHTML(average)}</span><span>${reviews.length} ${reviews.length === 1 ? 'experiência compartilhada' : 'experiências compartilhadas'}</span></div>` : '<p class="review-community-intro">Experiências reais começam com quem compartilha.</p>';
        listHost.innerHTML = reviews.length ? reviews.slice(0, communityLimit).map(review => reviewCard(review, { community: true })).join('') : `<div class="review-community-empty"><span class="review-balls" aria-hidden="true">${[1, 2, 3, 4, 5].map(() => '<span class="review-ball"></span>').join('')}</span><h3>A próxima opinião pode ser a sua.</h3><p>Escolha um produto no catálogo e conte sua experiência.<br>As avaliações aparecerão aqui com nome, comentário e nota.</p></div>`;
        listHost.classList.toggle('is-empty', !reviews.length);
        more.hidden = reviews.length <= communityLimit;
    }
    async function handleClick(event) {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) return;
        if (saving && target.closest('[data-review-write],[data-review-form-cancel],[data-review-edit],[data-review-delete],[data-review-delete-confirm]')) return;
        if (target.closest('[data-review-write]')) { openForm(); return; }
        if (target.closest('[data-review-form-cancel]')) {
            current.host.querySelector('#review-form').hidden = true;
            current.host.querySelector('[data-review-write]').focus();
            return;
        }
        if (target.closest('[data-review-load-more]')) { current.limit += 5; refreshProduct(); return; }
        if (target.closest('#community-review-more')) { communityLimit += 3; renderCommunity(); return; }
        if (target.closest('[data-review-edit]')) { openForm(); return; }
        const remove = target.closest('[data-review-delete]');
        if (remove) { pendingDelete = remove.dataset.reviewDelete; deleteError = ''; refreshProduct(); current.host.querySelector('[data-review-delete-confirm]')?.focus(); return; }
        if (target.closest('[data-review-delete-cancel]')) { pendingDelete = null; deleteError = ''; refreshProduct(); current.host.querySelector('[data-review-delete]')?.focus(); return; }
        const confirm = target.closest('[data-review-delete-confirm]');
        if (confirm) {
            if (saving) return;
            saving = true;
            confirm.disabled = true;
            const productId = current.id;
            try {
                await store().removeReview(confirm.dataset.reviewDeleteConfirm);
                pendingDelete = null;
                deleteError = '';
                if (current.id !== productId) return;
                current.host.querySelector('#review-form').hidden = true;
                fillForm({ nome: store().getProfile()?.nome || '' });
                refreshProduct();
                setStatus('Sua avaliação foi excluída da loja.');
                current.host.querySelector('[data-review-write]').focus();
            } catch (error) { if (current.id === productId) { deleteError = error.message || 'Não foi possível excluir sua avaliação.'; refreshProduct(); } }
            finally { saving = false; confirm.disabled = false; refreshProduct(); }
        }
    }
    async function handleSubmit(event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.id !== 'review-form') return;
        event.preventDefault();
        if (saving) return;
        const errorHost = form.querySelector('#review-form-error');
        errorHost.hidden = true;
        if (!form.checkValidity()) { form.reportValidity(); return; }
        const button = form.querySelector('#review-submit');
        saving = true;
        button.disabled = true;
        form.setAttribute('aria-busy', 'true');
        const productId = current.id;
        const authorId = store().getProfile()?.id;
        refreshProduct();
        try {
            await store().saveReview({ produtoId: productId, nome: form.querySelector('#review-name').value.trim(), nota: Number(form.querySelector('[name="review-rating"]:checked')?.value), comentario: form.querySelector('#review-comment').value.trim() });
            if (current.id !== productId || store().getProfile()?.id !== authorId) return;
            current.host.querySelector('#review-form').hidden = true;
            fillForm({ nome: store().getProfile()?.nome || '' });
            refreshProduct();
            setStatus('Avaliação publicada na loja. Obrigado por compartilhar!');
            current.host.querySelector('[data-review-write]').focus();
        } catch (error) {
            if (current.id === productId && store().getProfile()?.id === authorId) {
                const liveError = current.host.querySelector('#review-form-error') || errorHost;
                liveError.textContent = error.message || 'Não foi possível salvar sua avaliação. Tente novamente.';
                liveError.hidden = false;
            }
        } finally {
            saving = false;
            button.disabled = false;
            form.setAttribute('aria-busy', 'false');
            const liveButton = current.host?.querySelector('#review-submit');
            if (liveButton) liveButton.disabled = false;
            refreshProduct();
        }
    }
    document.addEventListener('click', handleClick);
    document.addEventListener('submit', handleSubmit);
    document.addEventListener('change', event => { if (event.target instanceof HTMLInputElement && event.target.name === 'review-rating') updateRating(); });
    document.addEventListener('input', event => { if (event.target instanceof HTMLTextAreaElement && event.target.id === 'review-comment') updateCounter(); });
    window.addEventListener('pokeanya:reviews', () => { refreshProduct(); renderCommunity(); });
    window.addEventListener('pokeanya:change', () => { refreshProduct(); renderCommunity(); });
    window.addEventListener('pokeanya:visitor', () => {
        const profile = store()?.getProfile();
        const nextAuthor = profile?.id || null;
        if (nextAuthor !== activeAuthor && current.host) {
            const form = current.host.querySelector('#review-form');
            if (form) { form.hidden = true; fillForm({ nome: profile?.nome || '' }); }
            pendingDelete = null;
            setStatus('');
        }
        activeAuthor = nextAuthor;
        refreshProduct();
        renderCommunity();
    });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', renderCommunity, { once: true });
    else renderCommunity();
    window.PokeReviewsUI = Object.freeze({ mountProduct, productRatingHTML, renderCommunity });
}());
