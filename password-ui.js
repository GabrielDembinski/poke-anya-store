(function () {
    'use strict';
    const $ = id => document.getElementById(id);
    const endpoint = new URL('api/admin/auth/password/', location.href);
    let csrf = '';
    let busy = false;
    history.replaceState(null, '', location.pathname);
    async function request(action, input) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch(new URL(action, endpoint), {
                method: input === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: { Accept: 'application/json', ...(input === undefined ? {} : { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }) },
                ...(input === undefined ? {} : { body: JSON.stringify(input) })
            });
            if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Abra o painel pelo servidor da loja.');
            const data = await response.json();
            if (response.status === 401) { location.replace('admin-login.html?next=password.html'); throw new Error('Entre no painel para alterar sua senha.'); }
            if (!response.ok || !data.ok) { const error = new Error(data.error?.message || 'Não foi possível concluir. Tente novamente.'); error.code = data.error?.code; throw error; }
            if (typeof data.csrf === 'string') csrf = data.csrf;
            return data;
        } catch (error) {
            if (error.name === 'AbortError' || error instanceof TypeError) throw new Error('Não foi possível conectar à loja. Tente novamente.');
            throw error;
        } finally { clearTimeout(timer); }
    }
    function showError(message) { $('password-error').textContent = message; $('password-error').hidden = false; }
    $('password-change-form').addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || !event.currentTarget.reportValidity()) return;
        $('password-error').hidden = true;
        if ($('password-new').value !== $('password-confirmation').value) { showError('As senhas precisam ser iguais. Confira a confirmação.'); return; }
        busy = true; $('password-change-submit').disabled = true;
        event.currentTarget.setAttribute('aria-busy', 'true');
        $('password-status').textContent = 'Atualizando sua senha…';
        try {
            const result = await request('change', { currentPassword: $('password-current').value, password: $('password-new').value, passwordConfirmation: $('password-confirmation').value });
            $('password-change-form').reset();
            $('password-change-form').hidden = true;
            $('password-success-message').textContent = result.message;
            $('password-success').hidden = false;
            if (typeof BroadcastChannel === 'function') { const channel = new BroadcastChannel('pokeanya-admin-auth'); channel.postMessage({ type: 'session-changed' }); channel.close(); }
        } catch (error) {
            showError(error.message);
            if (error.code === 'invalid_csrf') { try { await request('status'); } catch (_) {} }
        } finally { busy = false; $('password-change-submit').disabled = false; $('password-change-form').setAttribute('aria-busy', 'false'); $('password-status').textContent = ''; }
    });
    $('password-toggle').addEventListener('click', () => {
        const show = $('password-new').type === 'password';
        $('password-new').type = show ? 'text' : 'password';
        $('password-toggle').textContent = show ? 'Ocultar' : 'Mostrar';
        $('password-toggle').setAttribute('aria-pressed', String(show));
    });
    request('status').then(() => { $('password-status').textContent = ''; $('password-change-form').hidden = false; }).catch(error => { $('password-status').textContent = ''; showError(error.message); });
}());
