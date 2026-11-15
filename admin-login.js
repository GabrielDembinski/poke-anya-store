(function () {
    'use strict';
    const auth = window.PokeAdminAuth;
    const $ = id => document.getElementById(id);
    let busy = false;
    function destination() {
        const fallback = new URL('admin.html', location.href);
        try {
            const next = new URL(new URLSearchParams(location.search).get('next') || 'admin.html', location.href);
            const allowed = [fallback.pathname, new URL('password.html', location.href).pathname];
            if (next.origin === fallback.origin && allowed.includes(next.pathname) && !next.username && !next.password) return next.pathname + next.hash;
        } catch (_) { /* Invalid return locations use the panel home. */ }
        return fallback.pathname;
    }
    function render() {
        const state = auth?.getState() || { status: 'unavailable', configured: false };
        if (state.status === 'authenticated') { location.replace(destination()); return; }
        $('admin-login-service').hidden = state.status !== 'unavailable';
        $('admin-setup-note').hidden = state.status !== 'anonymous' || state.configured;
        $('admin-login-status').textContent = state.status === 'loading' ? 'Verificando o acesso…' : busy ? 'Entrando no painel…' : '';
        $('admin-login-submit').disabled = busy || state.status !== 'anonymous' || !state.configured;
    }
    $('admin-password-toggle').addEventListener('click', () => {
        const show = $('admin-password').type === 'password';
        $('admin-password').type = show ? 'text' : 'password';
        $('admin-password-toggle').textContent = show ? 'Ocultar' : 'Mostrar';
        $('admin-password-toggle').setAttribute('aria-pressed', String(show));
    });
    document.querySelectorAll('[data-admin-refresh]').forEach(button => button.addEventListener('click', async () => { if (auth && !busy) { await auth.refresh(); render(); } }));
    $('admin-login-form').addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || !event.currentTarget.reportValidity()) return;
        busy = true;
        $('admin-login-error').hidden = true;
        event.currentTarget.setAttribute('aria-busy', 'true');
        render();
        try {
            if (!auth) throw new Error('Não foi possível acessar o sistema administrativo. Abra a loja pelo INICIAR-LOJA.bat.');
            await auth.login({ email: $('admin-email').value.trim(), password: $('admin-password').value });
            $('admin-password').value = '';
            location.replace(destination());
        } catch (error) {
            $('admin-login-error').textContent = error.message;
            $('admin-login-error').hidden = false;
            $('admin-password').value = '';
            $('admin-password').focus();
        } finally { busy = false; event.target.setAttribute('aria-busy', 'false'); render(); }
    });
    window.addEventListener('pokeanya:admin-auth', render);
    render();
    auth?.ready.then(render);
}());
