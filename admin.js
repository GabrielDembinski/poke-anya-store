'use strict';

(async () => {
    const adminAuth = window.PokeAdminAuth;
    if (!adminAuth) return;
    await adminAuth.ready;
    if (!adminAuth.getAdmin()) return;
    const store = window.PokeStore;
    if (!store) return;
    await store.ready;
    const $ = id => document.getElementById(id);
    const escape = value => String(value ?? '').replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character]));
    const money = value => Number.isFinite(value) ? value.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }) : 'A consultar';
    const date = value => { const parsed = new Date(value); return Number.isNaN(parsed.getTime()) ? '' : parsed.toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo', dateStyle: 'short', timeStyle: 'short' }); };
    const categoryNames = { cartas: 'Cartas Pokémon', figures: 'Figures', chaveiros: 'Chaveiros', pelucias: 'Pelúcias', albuns: 'Álbuns', roupas: 'Roupas' };
    let imageValue = '';
    let imageBusy = false;
    let imageRevision = 0;
    let editingVersion = '';
    let confirmAction = null;
    let toastTimer;

    function showError(element, error) {
        element.textContent = error instanceof Error ? error.message : String(error);
        element.hidden = false;
    }

    function toast(message) {
        $('admin-toast').textContent = message;
        $('admin-toast').classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => $('admin-toast').classList.remove('show'), 3500);
    }

    function changeTab(tab) {
        const orders = tab === 'orders';
        $('products-panel').hidden = orders;
        $('orders-panel').hidden = !orders;
        $('admin-breadcrumb').textContent = orders ? 'Pedidos' : 'Produtos';
        document.querySelectorAll('[data-admin-tab]').forEach(button => {
            const active = button.dataset.adminTab === tab;
            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', String(active));
        });
        history.replaceState(null, '', orders ? '#pedidos' : '#produtos');
    }

    function badge(stock) {
        return stock > 0 ? '<span class="admin-badge available">Em estoque</span>' : '<span class="admin-badge backorder">Sob encomenda</span>';
    }

    function renderProducts(products) {
        const query = $('admin-search').value.trim().toLocaleLowerCase('pt-BR');
        const filter = $('product-filter').value;
        const items = products.filter(product => `${product.nome} ${product.descricao}`.toLocaleLowerCase('pt-BR').includes(query) && (filter === 'all' || (filter === 'stock' ? product.estoque > 0 : product.estoque === 0)));
        if (!items.length) {
            $('admin-products').innerHTML = `<div class="admin-empty"><span aria-hidden="true">${products.length ? '⌕' : '✦'}</span><h3>${products.length ? 'Nenhum produto por aqui' : 'O próximo favorito começa com você.'}</h3><p>${products.length ? 'Tente outro nome ou filtro para encontrar o produto.' : 'Adicione sua primeira foto, conte os detalhes e coloque mais um universo na vitrine.'}</p>${products.length ? '<button class="admin-text-button" data-clear-products>Limpar busca e filtros</button>' : '<button class="admin-text-button" data-new-product>Adicionar meu primeiro produto →</button>'}</div>`;
            return;
        }
        $('admin-products').innerHTML = `<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th scope="col">Produto</th><th class="admin-category-cell" scope="col">Categoria</th><th scope="col">Preço</th><th scope="col">Estoque</th><th scope="col">Disponibilidade</th><th scope="col"><span class="admin-visually-hidden">Ações</span></th></tr></thead><tbody>${items.map(product => `<tr><td><div class="admin-product-ident">${product.imagem ? `<img src="${escape(product.imagem)}" alt="" loading="lazy">` : '<span class="admin-product-placeholder" aria-hidden="true">▧</span>'}<div><strong>${escape(product.nome)}</strong><small>${escape(product.descricao || categoryNames[product.categoria])}</small></div></div></td><td class="admin-category-cell">${escape(categoryNames[product.categoria] || product.categoria)}</td><td class="admin-price-cell"><span class="admin-price">${money(product.preco)}</span></td><td class="admin-stock-cell"><span class="admin-stock-number">${product.estoque}<small>${product.estoque === 1 ? 'unidade' : 'unidades'}</small></span></td><td class="admin-status-cell">${badge(product.estoque)}</td><td><details class="admin-dots"><summary aria-label="Opções de ${escape(product.nome)}">⋯</summary><div class="admin-dots-menu"><button type="button" data-edit-product="${escape(product.id)}">Editar produto</button><button type="button" class="admin-remove" data-delete-product="${escape(product.id)}">Remover produto</button></div></details></td></tr>`).join('')}</tbody></table></div>`;
    }

    function allocation(item, products) {
        const current = products.find(product => product.id === item.produtoId);
        const fromStock = Math.min(current?.estoque || 0, item.quantidade);
        return { stock: fromStock, backorder: item.quantidade - fromStock };
    }

    function renderOrders(orders, products) {
        const query = $('order-search').value.trim().toLocaleLowerCase('pt-BR');
        const filter = $('order-filter').value;
        const items = orders.filter(order => (filter === 'all' || order.status === filter) && `${order.codigo} ${order.cliente.nome} ${order.cliente.contato} ${order.items.map(item => item.nome).join(' ')}`.toLocaleLowerCase('pt-BR').includes(query)).sort((first, second) => new Date(second.createdAt) - new Date(first.createdAt));
        const statuses = { pendente: ['pending', 'Aguardando aprovação'], aprovado: ['approved', 'Aprovado'], cancelado: ['cancelled', 'Cancelado'] };
        if (!items.length) {
            $('admin-orders').innerHTML = `<div class="admin-empty"><span aria-hidden="true">☷</span><h3>${orders.length ? 'Nenhum pedido neste filtro' : 'Cada pedido é uma nova história.'}</h3><p>${orders.length ? 'Escolha outro status ou tente uma busca diferente.' : 'Os pedidos preparados neste navegador aparecerão aqui para você conferir e aprovar.'}</p></div>`;
            return;
        }
        $('admin-orders').innerHTML = items.map(order => {
            const status = statuses[order.status] || statuses.pendente;
            const total = order.items.reduce((sum, item) => sum + (Number.isFinite(item.preco) ? item.preco * item.quantidade : 0), 0);
            const hasUnknown = order.items.some(item => !Number.isFinite(item.preco));
            const contactDigits = order.cliente.contato.replace(/\D/g, '');
            const waPhone = contactDigits.length >= 10 && contactDigits.length <= 13 ? (contactDigits.length <= 11 ? '55' : '') + contactDigits : '';
            return `<article class="admin-order-card"><div class="admin-order-head"><div><strong>Pedido ${escape(order.codigo)}</strong><p>${date(order.createdAt)}</p></div><span class="admin-badge ${status[0]}">${status[1]}</span></div><div class="admin-order-customer"><div><strong>${escape(order.cliente.nome)}</strong><span>${escape(order.cliente.contato)}</span></div>${waPhone ? `<a href="https://wa.me/${waPhone}?text=${encodeURIComponent('Olá, ' + order.cliente.nome + '! Vamos conversar sobre seu pedido ' + order.codigo + ' na PokeAnya Store?')}" target="_blank" rel="noopener">Conversar com cliente ↗</a>` : ''}</div><ul class="admin-order-items">${order.items.map(item => {
                const available = order.status === 'aprovado' ? { stock: item.quantidadeEstoque || 0, backorder: item.quantidadeEncomenda || 0 } : allocation(item, products);
                const subtitle = order.status === 'cancelado' ? 'Sem alteração de estoque' : order.status === 'aprovado' ? `${available.stock} unidade(s) retirada(s) do estoque${available.backorder ? ' · ' + available.backorder + ' sob encomenda' : ''}` : `${available.stock} disponível(is) em estoque${available.backorder ? ' · ' + available.backorder + ' sob encomenda' : ''}`;
                return `<li><div><strong>${item.quantidade}× ${escape(item.nome)}</strong><span>${subtitle}</span></div><span class="admin-order-value">${Number.isFinite(item.preco) ? money(item.preco * item.quantidade) : 'A consultar'}</span></li>`;
            }).join('')}</ul>${order.observacao ? `<p class="admin-order-note">${escape(order.observacao)}</p>` : ''}<div class="admin-order-bottom"><span class="admin-order-total">${hasUnknown ? 'Subtotal informado' : 'Total do pedido'}<strong>${money(total)}</strong></span>${order.status === 'pendente' ? `<div class="admin-order-actions"><button class="admin-secondary" data-cancel-order="${escape(order.id)}">Cancelar pedido</button><button class="button purple" data-approve-order="${escape(order.id)}">Aprovar venda <span aria-hidden="true">✓</span></button></div>` : `<p class="admin-order-approved-note">${order.status === 'aprovado' ? 'Venda aprovada. As unidades sob encomenda devem ser providenciadas para o cliente.' : 'Pedido cancelado. Nenhuma unidade foi retirada do estoque.'}</p>`}</div></article>`;
        }).join('');
    }

    function render() {
        try {
            const products = store.getProducts();
            const orders = store.getOrders();
            const pending = orders.filter(order => order.status === 'pendente').length;
            $('stat-products').textContent = products.length;
            $('stat-stock').textContent = products.reduce((sum, product) => sum + product.estoque, 0);
            $('stat-pending').textContent = pending;
            $('stat-backorder').textContent = products.filter(product => product.estoque === 0).length;
            $('nav-product-count').textContent = products.length;
            $('nav-order-count').textContent = pending;
            $('product-count').textContent = products.length;
            $('order-count').textContent = orders.length;
            renderProducts(products);
            renderOrders(orders, products);
            $('add-product').disabled = !store.isPersistent();
            if (!store.isPersistent()) showError($('storage-warning'), store.getStorageError() || 'Não foi possível conectar aos dados da loja. Tente novamente.');
            else $('storage-warning').hidden = true;
            if ($('reload-store')) $('reload-store').hidden = store.isPersistent();
            if ($('legacy-data-notice')) $('legacy-data-notice').hidden = !store.hasLegacyData();
            if ($('legacy-reviews-notice')) $('legacy-reviews-notice').hidden = !store.hasLegacyReviewsData();
        } catch (error) {
            showError($('storage-warning'), error);
        }
    }

    function setImage(value) {
        imageValue = value;
        const present = Boolean(value);
        $('image-preview').hidden = !present;
        $('image-placeholder').hidden = present;
        $('clear-image').hidden = !present;
        if (present) $('image-preview').src = value;
        else $('image-preview').removeAttribute('src');
    }

    function updateCategoryPreview() {
        const selected = $('product-category').value;
        const category = Object.prototype.hasOwnProperty.call(categoryNames, selected) ? selected : 'cartas';
        $('product-category-preview-name').textContent = categoryNames[category];
        $('product-category-preview-link').href = `index.html?categoria=${encodeURIComponent(category)}#catalogo`;
    }

    function openProduct(id) {
        const product = id ? store.getProducts().find(item => item.id === id) : null;
        if (id && !product) { toast('Este produto já foi removido.'); return; }
        $('product-form').reset();
        $('product-error').hidden = true;
        $('product-id').value = product?.id || '';
        editingVersion = product?.updatedAt || '';
        $('product-dialog-title').textContent = product ? 'Editar produto' : 'Adicionar produto';
        $('product-name').value = product?.nome || '';
        $('product-category').value = product?.categoria || 'cartas';
        updateCategoryPreview();
        $('product-price').value = product?.preco ?? '';
        $('product-stock').value = product?.estoque ?? 0;
        $('product-description').value = product?.descricao || '';
        $('product-image-url').value = /^https?:\/\//i.test(product?.imagem || '') ? product.imagem : '';
        $('image-status').textContent = '';
        imageRevision += 1;
        imageBusy = false;
        $('save-product').disabled = false;
        setImage(product?.imagem || '');
        $('product-dialog').showModal();
        $('product-name').focus();
    }

    function readImage(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onerror = () => reject(new Error('Não foi possível ler a foto. Escolha o arquivo novamente.'));
            reader.onload = () => {
                const image = new Image();
                image.onerror = () => reject(new Error('Esta imagem não pôde ser aberta. Escolha uma foto JPG, PNG, WebP ou GIF válida.'));
                image.onload = () => {
                    try {
                        const scale = Math.min(1, 1000 / Math.max(image.naturalWidth, image.naturalHeight));
                        const canvas = document.createElement('canvas');
                        canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
                        canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
                        const context = canvas.getContext('2d');
                        if (!context) throw new Error('Não foi possível otimizar a imagem neste navegador.');
                        context.drawImage(image, 0, 0, canvas.width, canvas.height);
                        let data = canvas.toDataURL('image/webp', 0.82);
                        if (data.length > 650000) data = canvas.toDataURL('image/webp', 0.62);
                        if (data.length > 1000000) throw new Error('A foto ficou muito grande para o armazenamento local. Escolha uma imagem menor.');
                        resolve(data);
                    } catch (error) { reject(error); }
                };
                image.src = reader.result;
            };
            reader.readAsDataURL(file);
        });
    }

    async function uploadImage(file) {
        if (!file) return;
        $('product-error').hidden = true;
        if (!['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(file.type)) { showError($('product-error'), 'Escolha uma imagem JPG, PNG, WebP ou GIF.'); return; }
        if (file.size > 10 * 1024 * 1024) { showError($('product-error'), 'A imagem deve ter no máximo 10 MB.'); return; }
        const revision = ++imageRevision;
        imageBusy = true;
        $('save-product').disabled = true;
        $('image-status').textContent = 'Preparando sua imagem…';
        try {
            const result = await readImage(file);
            if (revision !== imageRevision) return;
            setImage(result);
            $('product-image-url').value = '';
            $('image-status').textContent = 'Imagem pronta para salvar.';
        } catch (error) {
            if (revision === imageRevision) { showError($('product-error'), error); $('image-status').textContent = ''; }
        } finally {
            if (revision === imageRevision) { imageBusy = false; $('save-product').disabled = false; }
        }
    }

    function confirm(title, content, label, action, danger = false) {
        confirmAction = action;
        $('confirm-title').textContent = title;
        $('confirm-content').innerHTML = content;
        $('confirm-action').textContent = label;
        $('confirm-action').classList.toggle('admin-danger', danger);
        $('confirm-error').hidden = true;
        $('confirm-dialog').showModal();
    }

    function deleteProduct(id) {
        const product = store.getProducts().find(item => item.id === id);
        if (!product) { toast('Este produto já foi removido.'); return; }
        confirm('Remover este produto?', `<p><strong>${escape(product.nome)}</strong> sairá da vitrine. Os pedidos anteriores continuarão no histórico.</p><p>Se você só ficou sem unidades, mantenha o produto e ajuste o estoque para zero. Assim, os clientes poderão pedir sob encomenda.</p>`, 'Remover produto', async () => { await store.removeProduct(id); toast('Produto removido da vitrine.'); }, true);
    }

    function approveOrder(id) {
        const order = store.getOrders().find(item => item.id === id);
        if (!order || order.status !== 'pendente') { toast('Este pedido já foi atualizado. Confira a lista.'); render(); return; }
        const products = store.getProducts();
        confirm('Aprovar esta venda?', `<p>Confirme que combinou a venda com <strong>${escape(order.cliente.nome)}</strong>. Ao aprovar, este será o destino das unidades do pedido <strong>${escape(order.codigo)}</strong>:</p><ul class="admin-confirm-list">${order.items.map(item => { const available = allocation(item, products); return `<li><strong>${item.quantidade}× ${escape(item.nome)}</strong><span>${available.stock} retirada(s) do estoque<br>${available.backorder} sob encomenda</span></li>`; }).join('')}</ul><p>O estoque será conferido novamente ao confirmar. Unidades sem estoque continuam como encomenda para você providenciar.</p>`, 'Confirmar venda', async () => { await store.approveOrder(id); toast('Venda aprovada. Estoque atualizado.'); });
    }

    function cancelOrder(id) {
        const order = store.getOrders().find(item => item.id === id);
        if (!order || order.status !== 'pendente') { toast('Este pedido já foi atualizado.'); render(); return; }
        confirm('Cancelar este pedido?', `<p>O pedido <strong>${escape(order.codigo)}</strong> de <strong>${escape(order.cliente.nome)}</strong> ficará como cancelado no histórico. Nenhuma unidade será retirada do estoque.</p>`, 'Cancelar pedido', async () => { await store.cancelOrder(id); toast('Pedido cancelado.'); }, true);
    }

    async function exportBackup() {
        try {
            await adminAuth.requireSession();
            const data = await store.exportData();
            const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `pokeanya-backup-${new Intl.DateTimeFormat('sv-SE', { timeZone: 'America/Sao_Paulo' }).format(new Date())}.json`;
            document.body.append(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            toast('Backup exportado. Guarde o arquivo em um local seguro.');
        } catch (error) { showError($('storage-warning'), error); }
    }

    async function importBackup(file) {
        if (!file) return;
        try {
            if (file.size > 15 * 1024 * 1024) throw new Error('O backup excede o limite de 15 MB.');
            const data = JSON.parse(await file.text());
            if (!data || typeof data !== 'object') throw new Error('Este arquivo não contém um backup válido da loja.');
            confirm('Restaurar um backup?', '<p>O catálogo, os pedidos e o histórico compartilhados da loja serão <strong>substituídos</strong> pelos dados do arquivo escolhido.</p><p>Exporte um backup dos dados atuais antes de continuar. O arquivo será validado antes de qualquer substituição.</p>', 'Restaurar backup', async () => { await store.importData(data); toast('Backup restaurado. Sua loja foi atualizada.'); }, true);
        } catch (error) {
            showError($('storage-warning'), error instanceof SyntaxError ? new Error('O arquivo não é um JSON válido. Escolha um backup exportado pela loja.') : error);
        } finally { $('backup-file').value = ''; }
    }

    $('product-form').addEventListener('submit', async event => {
        event.preventDefault();
        if (imageBusy || $('product-form').getAttribute('aria-busy') === 'true') return;
        $('product-form').setAttribute('aria-busy', 'true');
        $('save-product').disabled = true;
        $('product-error').hidden = true;
        try {
            await adminAuth.requireSession();
            const nome = $('product-name').value.trim();
            const preco = Number($('product-price').value);
            const estoque = Number($('product-stock').value);
            const imageURL = $('product-image-url').value.trim();
            if (!nome) throw new Error('Informe o nome do produto.');
            if ($('product-price').value === '' || !Number.isFinite(preco) || preco < 0) throw new Error('Informe um preço válido, maior ou igual a zero.');
            if (!Number.isInteger(estoque) || estoque < 0) throw new Error('O estoque precisa ser um número inteiro, maior ou igual a zero.');
            if (imageURL && !/^https?:\/\//i.test(imageURL)) throw new Error('O link da imagem deve começar com https:// ou http://.');
            const imagem = imageURL || imageValue;
            if (!imagem) throw new Error('Adicione uma foto ou o link de uma imagem para o produto.');
            const id = $('product-id').value;
            await store.saveProduct({ ...(id ? { id, updatedAt: editingVersion } : {}), nome, categoria: $('product-category').value, preco, estoque, descricao: $('product-description').value.trim(), imagem });
            $('product-dialog').close();
            toast(id ? 'Produto atualizado. Sua vitrine já está pronta.' : 'Produto adicionado à vitrine!');
        } catch (error) { showError($('product-error'), error); }
        finally { $('product-form').setAttribute('aria-busy', 'false'); $('save-product').disabled = imageBusy; }
    });

    $('confirm-action').addEventListener('click', async () => {
        if (!confirmAction || $('confirm-action').disabled) return;
        $('confirm-action').disabled = true;
        try { await adminAuth.requireSession(); await confirmAction(); $('confirm-dialog').close(); confirmAction = null; }
        catch (error) { showError($('confirm-error'), error); }
        finally { $('confirm-action').disabled = false; }
    });

    document.addEventListener('click', event => {
        const tab = event.target.closest('[data-admin-tab]');
        if (tab) changeTab(tab.dataset.adminTab);
        const close = event.target.closest('[data-close]');
        if (close) $(close.dataset.close).close();
        if (event.target.closest('[data-new-product]')) openProduct();
        const edit = event.target.closest('[data-edit-product]');
        if (edit) { edit.closest('details').open = false; openProduct(edit.dataset.editProduct); }
        const remove = event.target.closest('[data-delete-product]');
        if (remove) { remove.closest('details').open = false; deleteProduct(remove.dataset.deleteProduct); }
        const approve = event.target.closest('[data-approve-order]');
        if (approve) approveOrder(approve.dataset.approveOrder);
        const cancel = event.target.closest('[data-cancel-order]');
        if (cancel) cancelOrder(cancel.dataset.cancelOrder);
        if (event.target.closest('[data-clear-products]')) { $('admin-search').value = ''; $('product-filter').value = 'all'; render(); }
        if (!event.target.closest('.admin-dots')) document.querySelectorAll('.admin-dots[open]').forEach(details => { details.open = false; });
    });
    document.addEventListener('keydown', event => { if (event.key === 'Escape') document.querySelectorAll('.admin-dots[open]').forEach(details => { details.open = false; }); });
    document.addEventListener('toggle', event => { if (event.target.matches('.admin-dots[open]')) document.querySelectorAll('.admin-dots[open]').forEach(details => { if (details !== event.target) details.open = false; }); }, true);
    document.querySelectorAll('dialog').forEach(dialog => dialog.addEventListener('click', event => { if (event.target !== dialog) return; const box = dialog.getBoundingClientRect(); if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) dialog.close(); }));
    $('product-dialog').addEventListener('close', () => { imageRevision += 1; imageBusy = false; });
    $('add-product').addEventListener('click', () => openProduct());
    $('admin-search').addEventListener('input', render);
    $('product-filter').addEventListener('change', render);
    $('product-category').addEventListener('change', updateCategoryPreview);
    $('order-search').addEventListener('input', render);
    $('order-filter').addEventListener('change', render);
    $('product-file').addEventListener('change', event => uploadImage(event.target.files[0]));
    $('image-drop').addEventListener('dragover', event => { event.preventDefault(); event.currentTarget.classList.add('dragover'); });
    $('image-drop').addEventListener('dragleave', event => { if (!event.currentTarget.contains(event.relatedTarget)) event.currentTarget.classList.remove('dragover'); });
    $('image-drop').addEventListener('drop', event => { event.preventDefault(); event.currentTarget.classList.remove('dragover'); uploadImage(event.dataTransfer.files[0]); });
    $('product-image-url').addEventListener('input', event => { imageRevision += 1; imageBusy = false; $('save-product').disabled = false; const url = event.target.value.trim(); if (/^https?:\/\//i.test(url)) setImage(url); else if (!url && /^https?:\/\//i.test(imageValue)) setImage(''); $('image-status').textContent = ''; });
    $('image-preview').addEventListener('error', () => { $('image-status').textContent = 'Não foi possível exibir a prévia. Confira o link da imagem ou escolha um arquivo.'; });
    $('clear-image').addEventListener('click', () => { imageRevision += 1; imageBusy = false; $('save-product').disabled = false; setImage(''); $('product-image-url').value = ''; $('product-file').value = ''; $('image-status').textContent = ''; });
    $('export-backup').addEventListener('click', exportBackup);
    $('import-backup').addEventListener('click', () => $('backup-file').click());
    $('backup-file').addEventListener('change', event => importBackup(event.target.files[0]));
    $('reload-store')?.addEventListener('click', () => store.refresh().catch(error => showError($('storage-warning'), error)));
    $('migrate-local-data')?.addEventListener('click', () => {
        try {
            const data = store.getLegacyData();
            if (!data) throw new Error('Não há dados antigos neste navegador.');
            confirm('Migrar os dados deste navegador?', '<p>O catálogo, os pedidos e os movimentos de estoque antigos deste navegador passarão a ser compartilhados com todos os dispositivos.</p><p><strong>Os dados atuais do servidor serão substituídos.</strong> Exporte um backup antes de continuar. Os dados antigos do navegador serão preservados.</p>', 'Migrar dados para o servidor', async () => { await store.migrateLegacyData(); toast('Dados migrados para o servidor. O histórico local foi preservado.'); }, true);
        } catch (error) { showError($('storage-warning'), error); }
    });
    $('migrate-local-reviews')?.addEventListener('click', () => {
        try {
            const data = store.getLegacyReviewsData();
            if (!data) throw new Error('Não há avaliações antigas neste navegador.');
            confirm('Migrar o histórico de avaliações?', '<p>As avaliações antigas serão publicadas como <strong>histórico importado</strong>, sem atribuir esse conteúdo a uma conta atual. Os dados locais serão preservados.</p>', 'Migrar histórico de avaliações', async () => { await store.importLegacyReviews(data); toast('Histórico de avaliações migrado para a loja.'); });
        } catch (error) { showError($('storage-warning'), error); }
    });
    window.addEventListener('pokeanya:change', render);
    window.addEventListener('hashchange', () => changeTab(location.hash === '#pedidos' ? 'orders' : 'products'));
    if (!store) { showError($('storage-warning'), 'Não foi possível carregar os dados da loja. Confira se storage.js está junto dos arquivos do site.'); return; }
    changeTab(location.hash === '#pedidos' ? 'orders' : 'products');
    render();
    document.documentElement.dataset.adminReady = 'true';
})();
