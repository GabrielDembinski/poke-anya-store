'use strict';
const PHONE = '5593991494273';
const categories = [
    { id: 'cartas', name: 'Cartas Pokémon', short: 'Cartas', description: 'O próximo destaque do seu deck', color: '#eee2f5', icon: '<rect x="14" y="9" width="36" height="48" rx="5" transform="rotate(-10 32 32)"/><circle cx="32" cy="32" r="10"/><path d="M22 32h20"/><circle cx="32" cy="32" r="3"/>' },
    { id: 'figures', name: 'Figures', short: 'Figures', description: 'Seus personagens, bem pertinho', color: '#fbe3e9', icon: '<circle cx="32" cy="19" r="10"/><path d="M23 28l-7 15 8 3v10h16V46l8-3-7-15M26 15l3 2m6 0 3-2M29 23h6M25 56h-6m20 0h6"/>' },
    { id: 'chaveiros', name: 'Chaveiros', short: 'Chaveiros', description: 'Um toque geek em todo lugar', color: '#fff0be', icon: '<circle cx="32" cy="14" r="9"/><path d="M32 23v9"/><path d="M32 31l6 10 12 2-9 8 2 12-11-6-11 6 2-12-9-8 12-2z"/>' },
    { id: 'pelucias', name: 'Pelúcias', short: 'Pelúcias', description: 'Impossível não abraçar', color: '#e2eee6', icon: '<circle cx="19" cy="17" r="7"/><circle cx="45" cy="17" r="7"/><ellipse cx="32" cy="30" rx="20" ry="18"/><circle cx="25" cy="28" r="1"/><circle cx="39" cy="28" r="1"/><ellipse cx="32" cy="36" rx="7" ry="5"/><path d="M20 45l-5 12h34l-5-12M30 35h4"/>' },
    { id: 'albuns', name: 'Álbuns', short: 'Álbuns', description: 'Cada carta no seu lugar', color: '#e3eafa', icon: '<rect x="10" y="10" width="44" height="48" rx="4"/><path d="M19 10v48M25 20h9v12h-9zm14 0h9v12h-9zM25 38h9v12h-9zm14 0h9v12h-9z"/>' },
    { id: 'roupas', name: 'Roupas', short: 'Roupas', description: 'Seu estilo em uma nova fase', color: '#f2e9dd', icon: '<path d="M22 12l-15 9 7 13 8-4v28h24V30l8 4 7-13-15-9c-5 10-19 10-24 0z"/>' }
];
const categoryImages = {
    cartas: 'assets/categories/cartas.webp',
    figures: 'assets/categories/figures.webp',
    chaveiros: 'assets/categories/chaveiros.webp',
    pelucias: 'assets/categories/pelucias.webp',
    albuns: 'assets/categories/albuns.webp',
    roupas: 'assets/categories/roupas.webp'
};
const $ = id => document.getElementById(id);
const escapeHTML = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const money = n => Number.isFinite(n) ? n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }) : 'Valor a consultar';
const wa = message => `https://wa.me/${PHONE}?text=${encodeURIComponent(message)}`;
const normalize = value => String(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('pt-BR');
const initialCategory = new URLSearchParams(location.search).get('categoria');
let selected = categories.some(c => c.id === initialCategory) ? initialCategory : 'todos';
let list = [];
let preparing = false;
let detailId = null;
try {
    const saved = JSON.parse(localStorage.getItem('pokeanya-list') || '[]');
    if (Array.isArray(saved)) list = saved.filter(i => i && typeof i.key === 'string' && i.key.length < 200 && typeof i.name === 'string' && Number.isInteger(i.qty) && i.qty > 0 && i.qty <= 99).slice(0, 99);
} catch (_) { /* A damaged wishlist should not prevent browsing. */ }

function notify(message) {
    $('toast').textContent = message;
    $('toast').classList.add('show');
    clearTimeout(window.toastTimeout);
    window.toastTimeout = setTimeout(() => $('toast').classList.remove('show'), 3200);
}
function getProduct(item) { return window.PokeStore.getProducts().find(p => 'produto:' + p.id === item.key); }
function rememberPrepared(id) {
    try { if (id) sessionStorage.setItem('pokeanya-prepared-order', id); else sessionStorage.removeItem('pokeanya-prepared-order'); } catch (_) { /* A blocked preference never invalidates a saved order. */ }
}
function showPrepared(order) {
    $('send-order').href = wa(orderMessage(order));
    $('order-reference').textContent = 'Referência ' + order.codigo + ' · aguardando confirmação';
    $('bag-compose').hidden = true;
    $('order-success').hidden = false;
}
function productImage(p, detail = false) {
    const category = categories.find(c => c.id === p.categoria) || categories[0];
    return `<div class="product-image ${detail ? 'detail-image' : ''}" style="--tile:${category.color}">${p.imagem ? `<img src="${escapeHTML(p.imagem)}" alt="${escapeHTML(p.nome)}" loading="${detail ? 'eager' : 'lazy'}" referrerpolicy="no-referrer">` : `<svg viewBox="0 0 72 72" aria-label="Produto sem foto">${category.icon}</svg>`}</div>`;
}
function stockBadge(p) { return `<span class="stock-badge ${p.estoque > 0 ? 'available' : 'on-order'}"><span aria-hidden="true">${p.estoque > 0 ? '●' : '✦'}</span> ${p.estoque > 0 ? `${p.estoque} em estoque` : 'Sob encomenda'}</span>`; }

$('category-grid').innerHTML = categories.map(c => `<button type="button" class="category" data-category="${c.id}" aria-pressed="false" aria-controls="catalog-content"><div class="category-art category-photo category-photo--${c.id}" style="--tile:${c.color}"><img class="category-image" src="${categoryImages[c.id]}" alt="" width="400" height="400" loading="lazy" decoding="async"></div><h3>${c.name}<span class="arrow" aria-hidden="true">↗</span></h3><p>${c.description}</p><span class="category-product-count">Em breve</span></button>`).join('');
$('tabs').innerHTML = [{ id: 'todos', short: 'Tudo' }, ...categories].map(c => `<button type="button" data-filter="${c.id}" aria-pressed="${c.id === selected}" aria-controls="catalog-content" class="${c.id === selected ? 'active' : ''}">${c.short}</button>`).join('');
document.querySelectorAll('[data-whatsapp]').forEach(a => {
    a.href = wa('Olá, PokeAnya Store! Vim pelo site e gostaria de consultar produtos e encomendas.');
    a.target = '_blank'; a.rel = 'noopener';
});

function syncCategoryURL() {
    try {
        const next = new URL(location.href);
        if (selected === 'todos') next.searchParams.delete('categoria');
        else next.searchParams.set('categoria', selected);
        if (next.href !== location.href) history.replaceState(null, '', next.href);
    } catch (_) { /* Filtering remains available when a local file disallows history updates. */ }
}
function renderCatalog() {
    const products = window.PokeStore.getProducts();
    const connection = window.PokeStore.getStatus();
    const status = $('catalog-status');
    if (status) {
        status.hidden = connection.status === 'ready';
        status.textContent = connection.status === 'loading' ? 'Carregando os produtos da loja…' : connection.error || '';
    }
    document.querySelectorAll('[data-category]').forEach(button => {
        const count = products.filter(product => product.categoria === button.dataset.category).length;
        button.setAttribute('aria-pressed', String(button.dataset.category === selected));
        button.querySelector('.category-product-count').textContent = count ? `${count} ${count === 1 ? 'produto' : 'produtos'}` : 'Em breve';
    });
    document.querySelectorAll('[data-filter]').forEach(b => { b.classList.toggle('active', b.dataset.filter === selected); b.setAttribute('aria-pressed', String(b.dataset.filter === selected)); });
    const query = normalize($('search').value.trim());
    const items = products.filter(p => (selected === 'todos' || p.categoria === selected) && normalize(p.nome + ' ' + p.descricao).includes(query));
    const category = categories.find(c => c.id === selected);
    $('catalog-title').textContent = category ? category.name : 'Explore o catálogo';
    $('catalog-description').textContent = category ? 'Explore os itens desta categoria e escolha seus favoritos. Produtos sem estoque também podem ser encomendados.' : 'Os produtos da loja estão aqui. Escolha uma categoria acima ou use os filtros para encontrar seu próximo favorito.';
    $('catalog-current').textContent = 'Catálogo / ' + (category ? category.name : 'Todos os produtos');
    $('reset-catalog').hidden = selected === 'todos' && !query;
    $('reset-catalog').textContent = selected === 'todos' ? 'Limpar busca ×' : 'Ver todos os produtos ↗';
    $('catalog-count').textContent = `${items.length} ${items.length === 1 ? 'produto' : 'produtos'}${query ? ' na busca' : category ? ' nesta categoria' : ' no catálogo'}`;
    if (items.length) {
        $('catalog-content').innerHTML = '<div class="products">' + items.map(p => `<article class="product" data-product-id="${escapeHTML(p.id)}" data-product-category="${escapeHTML(p.categoria)}"><button class="product-preview" data-details="${escapeHTML(p.id)}" aria-label="Ver detalhes de ${escapeHTML(p.nome)}">${productImage(p)}<span class="detail-hint">Ver detalhes ↗</span></button><div class="product-copy"><div class="product-meta"><span class="product-category-label">${escapeHTML(categories.find(c => c.id === p.categoria)?.name || 'Colecionáveis')}</span>${stockBadge(p)}</div><button class="product-title" data-details="${escapeHTML(p.id)}"><h3>${escapeHTML(p.nome)}</h3></button><p class="product-description">${escapeHTML(p.descricao || 'Um novo favorito para a sua coleção.')}</p>${window.PokeReviewsUI ? window.PokeReviewsUI.productRatingHTML(p.id) : ''}<div class="product-bottom"><strong class="product-price">${money(p.preco)}</strong><button class="button purple" data-product="${escapeHTML(p.id)}">${p.estoque > 0 ? 'Quero esse' : 'Encomendar'} <span aria-hidden="true">+</span></button></div></div></article>`).join('') + '</div>';
        return;
    }
    if (connection.status !== 'ready') {
        $('catalog-content').innerHTML = `<div class="empty"><h3>${connection.status === 'loading' ? 'Carregando a vitrine…' : 'Não foi possível carregar a vitrine.'}</h3><p>${connection.status === 'loading' ? 'Só um instante enquanto buscamos os produtos da loja.' : escapeHTML(connection.error || 'Confira sua conexão e tente novamente.')}</p>${connection.status === 'error' ? '<button type="button" class="button purple" data-retry-catalog>Tentar novamente</button>' : ''}</div>`;
        $('catalog-content').querySelector('[data-retry-catalog]')?.addEventListener('click', () => window.PokeStore.refresh().catch(() => {}));
        return;
    }
    const title = query ? 'Seu favorito pode estar a uma conversa.' : category ? `${category.name}: novidades a caminho` : 'Uma nova coleção está chegando.';
    const description = query ? 'Tente outro nome ou consulte uma encomenda personalizada com a loja.' : category ? 'Esta categoria ainda está sem produtos na vitrine. Você pode consultar uma encomenda ou explorar as outras categorias.' : 'Os produtos aparecerão aqui assim que a vitrine estiver pronta. Enquanto isso, conte para a gente o que falta na sua coleção.';
    const queryMessage = query ? 'Olá! Estou procurando: ' + $('search').value.trim() : 'Olá, PokeAnya Store! Gostaria de conhecer os produtos e valores disponíveis.';
    $('catalog-content').innerHTML = `<div class="empty"><div class="empty-icon" aria-hidden="true">✦</div><h3>${title}</h3><p>${description}</p>${category && !query ? `<button class="button purple" id="add-interest">Tenho interesse em ${category.short.toLowerCase()} +</button>` : `<a class="button purple" href="${escapeHTML(wa(queryMessage))}" target="_blank" rel="noopener">${query ? 'Consultar uma encomenda' : 'Conversar com a loja'} ↗</a>`}</div>`;
    $('add-interest')?.addEventListener('click', () => add({ key: 'categoria:' + category.id, name: category.name, kind: 'Interesse na categoria' }));
}

function persistList() {
    try { localStorage.setItem('pokeanya-list', JSON.stringify(list)); }
    catch (_) { notify('Sua lista está disponível nesta visita. O navegador não permitiu salvá-la.'); }
    renderList();
}
function add(item) {
    const previous = list.find(i => i.key === item.key);
    if (previous) {
        if (item.kind !== 'Interesse na categoria') {
            if (previous.qty >= 99) { notify('Para mais de 99 unidades, fale com a loja.'); return; }
            previous.qty += 1;
        }
    } else {
        if (list.length >= 99) { notify('Sua lista está cheia. Envie este pedido antes de adicionar mais itens.'); return; }
        list.push({ ...item, qty: 1 });
    }
    $('order-success').hidden = true;
    $('bag-compose').hidden = false;
    rememberPrepared(null);
    persistList();
    notify('Adicionado à sua lista ♡');
}
function splitLabel(p, qty) {
    if (!p) return 'Este produto foi removido. Retire-o da lista para continuar.';
    const stock = Math.min(p.estoque, qty);
    const preorder = qty - stock;
    return preorder ? `${stock ? stock + ' em estoque · ' : ''}${preorder} por encomenda · prazo a combinar` : `${qty} ${qty === 1 ? 'unidade disponível' : 'unidades disponíveis'}`;
}
function renderList() {
    let subtotal = 0;
    let consultPrice = false;
    const hasProducts = list.some(i => i.key.startsWith('produto:'));
    let hasRemoved = false;
    $('bag-count').textContent = list.reduce((n, i) => n + i.qty, 0);
    $('bag-items').innerHTML = list.length ? list.map((item, index) => {
        const isProduct = item.key.startsWith('produto:');
        const p = isProduct ? getProduct(item) : null;
        if (isProduct && !p) hasRemoved = true;
        if (p) { if (p.preco === null) consultPrice = true; else subtotal += p.preco * item.qty; }
        const name = p?.nome || item.name;
        return `<div class="list-row ${isProduct && !p ? 'removed-product' : ''}"><div class="list-item-copy"><strong>${escapeHTML(name)}</strong><small>${isProduct ? escapeHTML(splitLabel(p, item.qty)) : 'Interesse na categoria'}${p ? ' · ' + money(p.preco) + '/un.' : ''}</small></div>${isProduct ? `<div class="quantity"><button type="button" data-qty="${index}" data-delta="-1" ${item.qty === 1 ? 'disabled' : ''} aria-label="Diminuir quantidade de ${escapeHTML(name)}">−</button><span>${item.qty}</span><button type="button" data-qty="${index}" data-delta="1" ${item.qty === 99 ? 'disabled' : ''} aria-label="Aumentar quantidade de ${escapeHTML(name)}">+</button></div>` : ''}<button type="button" class="remove" data-remove="${index}" aria-label="Remover ${escapeHTML(name)} da lista">Remover</button></div>`;
    }).join('') : '<div class="bag-empty"><span aria-hidden="true">♡</span><p>Sua lista está esperando seus favoritos.</p><small>Explore o catálogo ou conte abaixo o que você procura.</small></div>';
    $('bag-total').hidden = !hasProducts;
    $('bag-total').innerHTML = `<div><span>Subtotal estimado</span><strong>${money(subtotal)}${consultPrice ? ' + itens a consultar' : ''}</strong></div><small>Frete e prazo combinados com a loja. Estoque sujeito à confirmação.</small>`;
    $('customer-fields').hidden = !hasProducts;
    $('customer-name').required = hasProducts;
    $('customer-contact').required = hasProducts;
    $('prepare-order').hidden = !hasProducts;
    $('prepare-order').disabled = hasRemoved || preparing || !window.PokeStore.isPersistent();
    $('checkout').hidden = hasProducts;
    updateMessage();
}
function updateMessage() {
    const lines = ['Olá, PokeAnya Store! Vim pelo site e gostaria de fazer uma consulta.'];
    if (list.length) lines.push('', ...list.map(i => i.key.startsWith('categoria:') ? `• Interesse em ${i.name}` : `• ${i.qty}x ${getProduct(i)?.nome || i.name}`));
    const note = $('order-note').value.trim();
    if (note) lines.push('', note);
    lines.push('', 'Podem me informar os valores, os prazos e as opções de entrega?');
    $('checkout').href = wa(lines.join('\n'));
}
function orderMessage(order) {
    const lines = ['Olá, PokeAnya Store! Gostaria de confirmar este pedido:', `Referência: ${order.codigo}`, '', `Nome: ${order.cliente.nome}`, `Contato: ${order.cliente.contato}`, ''];
    let total = 0;
    let consult = false;
    order.items.forEach(item => {
        const p = window.PokeStore.getProducts().find(p => p.id === item.produtoId);
        if (item.preco === null) consult = true; else total += item.preco * item.quantidade;
        lines.push(`• ${item.quantidade}x ${item.nome} — ${money(item.preco)}/un.`, `  ${splitLabel(p, item.quantidade)}`);
    });
    lines.push('', `Subtotal estimado: ${money(total)}${consult ? ' + itens a consultar' : ''}`);
    if (order.observacao) lines.push('', 'Observações: ' + order.observacao);
    lines.push('', 'Aguardo confirmação da disponibilidade, dos prazos de encomenda, do pagamento e da entrega.');
    return lines.join('\n');
}
$('order-form').addEventListener('submit', async event => {
    event.preventDefault();
    if (preparing || !list.some(i => i.key.startsWith('produto:'))) return;
    $('order-error').hidden = true;
    const contact = $('customer-contact').value.trim();
    const digits = contact.replace(/\D/g, '');
    if (digits.length < 8 || digits.length > 15 || !/^[+\d\s().-]+$/.test(contact)) {
        $('order-error').textContent = 'Informe um WhatsApp válido com DDD para a loja falar com você.';
        $('order-error').hidden = false;
        $('customer-contact').focus();
        return;
    }
    preparing = true;
    $('order-form').setAttribute('aria-busy', 'true');
    $('prepare-order').disabled = true;
    try {
        const interests = list.filter(i => i.key.startsWith('categoria:')).map(i => 'Interesse em ' + i.name);
        const order = await window.PokeStore.createOrder({
            cliente: { nome: $('customer-name').value.trim(), contato: contact },
            observacao: [$('order-note').value.trim(), ...interests].filter(Boolean).join('\n').slice(0, 2000),
            items: list.filter(i => i.key.startsWith('produto:')).map(i => ({ produtoId: i.key.slice(8), quantidade: i.qty }))
        });
        rememberPrepared(order.id);
        showPrepared(order);
        list = [];
        persistList();
        $('order-form').reset();
        $('send-order').focus();
    } catch (error) {
        $('order-error').textContent = error.message || 'Não foi possível preparar o pedido. Tente novamente.';
        $('order-error').hidden = false;
    } finally {
        preparing = false;
        $('order-form').setAttribute('aria-busy', 'false');
        renderList();
    }
});

function renderDetails(id) {
    const p = window.PokeStore.getProducts().find(p => p.id === id);
    if (!p) { $('product-dialog').close(); detailId = null; return; }
    const c = categories.find(c => c.id === p.categoria);
    $('product-details').innerHTML = `${productImage(p, true)}<span class="kicker">${escapeHTML(c?.name || 'Colecionáveis')}</span><h2 id="product-detail-title">${escapeHTML(p.nome)}</h2>${stockBadge(p)}<p class="detail-description">${escapeHTML(p.descricao || 'Converse com a loja para saber mais sobre este favorito.')}</p><strong class="detail-price">${money(p.preco)}</strong><p class="muted">${p.estoque > 0 ? 'Disponibilidade confirmada pela loja ao receber seu pedido. Precisa de mais unidades? O restante pode ser encomendado.' : 'Este item está sem estoque, mas você pode pedir por encomenda. A loja confirma a possibilidade e o prazo com você.'}</p><button class="button purple checkout" data-product="${escapeHTML(p.id)}">${p.estoque > 0 ? 'Adicionar à minha lista' : 'Quero encomendar'} +</button><section id="product-reviews" class="product-reviews" tabindex="-1" aria-label="Avaliações deste produto"></section>`;
    if (window.PokeReviewsUI) window.PokeReviewsUI.mountProduct(p.id, $('product-reviews'));
}
function openProductReviews(id, write = false) {
    if (!window.PokeStore.getProducts().some(product => product.id === id)) {
        notify('Este produto já saiu da vitrine.');
        return;
    }
    detailId = id;
    renderDetails(id);
    if (!$('product-dialog').open) $('product-dialog').showModal();
    requestAnimationFrame(() => {
        const reviews = $('product-reviews');
        if (!reviews || !$('product-dialog').open) return;
        reviews.scrollIntoView({ block: 'start', behavior: 'instant' });
        if (write) reviews.querySelector('[data-review-write]')?.click();
        else reviews.focus({ preventScroll: true });
    });
}
document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    const category = target.closest('[data-category]');
    const filter = target.closest('[data-filter]');
    if (category || filter) {
        selected = (category || filter).dataset[category ? 'category' : 'filter'];
        $('search').value = '';
        syncCategoryURL();
        renderCatalog();
        if (category) $('catalogo').scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    }
    const details = target.closest('[data-details]');
    if (details) { detailId = details.dataset.details; renderDetails(detailId); $('product-dialog').showModal(); }
    const reviewProduct = target.closest('[data-review-product]');
    if (reviewProduct) openProductReviews(reviewProduct.dataset.reviewProduct);
    const product = target.closest('[data-product]');
    if (product) {
        const p = window.PokeStore.getProducts().find(p => p.id === product.dataset.product);
        if (p) { add({ key: 'produto:' + p.id, name: p.nome, kind: 'Produto' }); if ($('product-dialog').open) $('product-dialog').close(); }
    }
    const remove = target.closest('[data-remove]');
    if (remove) { list.splice(Number(remove.dataset.remove), 1); persistList(); }
    const qty = target.closest('[data-qty]');
    if (qty) {
        const item = list[Number(qty.dataset.qty)];
        if (item) { item.qty = Math.min(99, Math.max(1, item.qty + Number(qty.dataset.delta))); persistList(); }
    }
});
$('search').addEventListener('input', renderCatalog);
$('reset-catalog').addEventListener('click', () => { selected = 'todos'; $('search').value = ''; syncCategoryURL(); renderCatalog(); });
$('order-note').addEventListener('input', updateMessage);
$('open-bag').addEventListener('click', () => { renderList(); $('bag-dialog').showModal(); });
$('close-bag').addEventListener('click', () => $('bag-dialog').close());
$('close-product').addEventListener('click', () => { $('product-dialog').close(); detailId = null; });
$('new-list').addEventListener('click', () => { rememberPrepared(null); $('bag-dialog').close(); $('order-success').hidden = true; $('bag-compose').hidden = false; $('catalogo').scrollIntoView(); });
document.querySelectorAll('dialog').forEach(dialog => dialog.addEventListener('click', event => {
    if (event.target !== dialog) return;
    const rect = dialog.getBoundingClientRect();
    if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
}));
document.addEventListener('error', event => {
    if (event.target instanceof HTMLImageElement && event.target.closest('.product-image')) {
        const holder = event.target.parentElement;
        const fallback = document.createElement('span');
        fallback.className = 'image-fallback';
        fallback.textContent = '✦ Foto indisponível';
        holder.replaceChildren(fallback);
    }
}, true);
window.addEventListener('pokeanya:change', event => { renderCatalog(); renderList(); if (event.detail?.type !== 'reviews' && detailId && $('product-dialog').open) renderDetails(detailId); });
window.addEventListener('pokeanya:reviews', renderCatalog);
window.addEventListener('storage', event => {
    if (event.key !== 'pokeanya-list' && event.key !== null) return;
    try {
        const saved = JSON.parse(event.newValue || '[]');
        if (Array.isArray(saved)) list = saved.filter(i => i && typeof i.key === 'string' && typeof i.name === 'string' && Number.isInteger(i.qty) && i.qty > 0 && i.qty <= 99).slice(0, 99);
        renderList();
    } catch (_) { /* Ignore malformed changes from another tab. */ }
});
renderCatalog();
renderList();
window.PokeStore.ready.then(() => {
    const url = new URL(location.href);
    const productId = url.searchParams.get('avaliar');
    if (!productId) return;
    url.searchParams.delete('avaliar');
    history.replaceState(null, '', url.href);
    openProductReviews(productId, true);
});
window.PokeStore.ready.then(async () => {
    try {
        const preparedId = sessionStorage.getItem('pokeanya-prepared-order');
        if (!preparedId || list.length) return;
        const prepared = await window.PokeStore.getOrder(preparedId);
        if (prepared?.status === 'pendente') showPrepared(prepared);
        else rememberPrepared(null);
    } catch (error) {
        if (error.code === 'receipt_missing' || error.status === 404) rememberPrepared(null);
        else notify('Não foi possível recuperar o último pedido. Tente novamente antes de preparar outro.');
    }
});
