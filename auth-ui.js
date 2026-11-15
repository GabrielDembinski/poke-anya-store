/* Customer account interface. Credentials are sent only through PokeAuth. */
(function () {
    'use strict';
    const $ = id => document.getElementById(id);
    const auth = window.PokeAuth;
    if (!$('auth-card')) return;
    const returnKey = 'pokeanya-auth-return';
    const query = new URLSearchParams(location.search);
    let view = query.get('mode') === 'register' ? 'register' : 'login';
    let busy = false;
    let didRedirect = false;

    function safeReturn(value) {
        if (typeof value !== 'string' || !value) return null;
        try {
            const destination = new URL(value, location.href);
            const store = new URL('index.html', location.href);
            if (destination.origin !== store.origin || destination.pathname !== store.pathname || destination.username || destination.password) return null;
            return destination.pathname + destination.search + destination.hash;
        } catch (_) { return null; }
    }
    function rememberReturn() {
        const destination = safeReturn(query.get('next'));
        if (!destination) return;
        try { sessionStorage.setItem(returnKey, destination); } catch (_) { /* Authentication remains usable when preferences are blocked. */ }
    }
    function returnToStore() {
        if (didRedirect) return false;
        let destination = safeReturn(query.get('next'));
        try {
            destination = destination || safeReturn(sessionStorage.getItem(returnKey));
            if (destination) sessionStorage.removeItem(returnKey);
        } catch (_) { /* A direct next parameter can still be used. */ }
        if (!destination) return false;
        didRedirect = true;
        location.assign(destination);
        return true;
    }
    function error(element, message) {
        element.textContent = message instanceof Error ? message.message : String(message || 'Não foi possível concluir. Tente novamente.');
        element.hidden = false;
    }
    function clearError(element) { element.hidden = true; element.textContent = ''; }
    function status(message) { $('auth-status').textContent = message || ''; }
    function passwordFields(form) {
        form.querySelectorAll('input[type="password"],input[data-password-visible]').forEach(input => {
            input.value = '';
            input.type = 'password';
            delete input.dataset.passwordVisible;
            const toggle = form.querySelector(`[data-password-toggle="${input.id}"]`);
            if (toggle) { toggle.textContent = 'Mostrar'; toggle.setAttribute('aria-pressed', 'false'); toggle.setAttribute('aria-label', input.id === 'register-confirmation' ? 'Mostrar confirmação de senha' : 'Mostrar senha'); }
        });
    }
    function setBusy(form, value, label) {
        form.setAttribute('aria-busy', String(value));
        const submit = form.querySelector('button[type="submit"]');
        if (!submit) return;
        if (value) { submit.dataset.originalLabel = submit.textContent; submit.textContent = label; }
        else if (submit.dataset.originalLabel) { submit.textContent = submit.dataset.originalLabel; delete submit.dataset.originalLabel; }
        submit.disabled = value;
    }
    function setView(next, moveFocus = false) {
        view = next === 'register' ? 'register' : 'login';
        $('auth-login-panel').hidden = view !== 'login';
        $('auth-register-panel').hidden = view !== 'register';
        document.querySelectorAll('[data-auth-view]').forEach(button => {
            const active = button.dataset.authView === view;
            button.setAttribute('aria-selected', String(active));
            button.tabIndex = active ? 0 : -1;
        });
        if (!$('auth-access').hidden) {
            $('auth-title').textContent = view === 'register' ? 'Seu próximo capítulo começa aqui.' : 'Bom ter você por aqui.';
            $('auth-subtitle').textContent = view === 'register' ? 'Crie seu perfil e faça parte da nossa comunidade.' : 'Entre para continuar no seu universo favorito.';
        }
        if (moveFocus) $(view === 'register' ? 'register-username' : 'login-email').focus({ preventScroll: true });
    }
    function profile(user) {
        $('auth-profile-username').textContent = user.username || '';
        $('auth-profile-email').textContent = user.email || '';
        const labels = { email: 'E-mail e senha', google: 'Google' };
        const methods = Array.isArray(user.providers) ? user.providers.map(provider => labels[provider]).filter(Boolean) : [];
        $('auth-profile-methods').textContent = methods.length ? methods.join(' · ') : 'Conta de cliente';
        $('auth-profile-avatar').textContent = auth.getInitials(user.username);
    }
    function render(state) {
        if (!state || typeof state !== 'object') state = auth?.getState?.() || { status: 'unavailable' };
        const user = state.status === 'authenticated' ? state.user : null;
        const pending = !user && state.pendingRegistration ? state.pendingRegistration : null;
        $('auth-access').hidden = Boolean(user || pending);
        $('auth-profile').hidden = !user;
        $('auth-oauth-panel').hidden = !pending;
        $('auth-service-warning').hidden = state.status !== 'unavailable';
        $('auth-provider-note').hidden = Boolean(state.providers?.google);
        $('auth-provider-note').textContent = 'O login com Google ainda não está disponível. Por enquanto, entre ou crie sua conta por e-mail.';
        document.querySelectorAll('[data-auth-provider]').forEach(button => {
            const available = Boolean(state.providers?.[button.dataset.authProvider]);
            button.classList.toggle('is-pending', !available);
            button.title = available ? button.textContent.trim() : 'Este método ainda não está disponível. Você pode usar seu e-mail.';
        });
        if (state.status === 'loading') status('Carregando sua conta…');
        else if (!busy) status('');
        if (user) {
            $('auth-title').textContent = 'Sua conta, seu universo.';
            $('auth-subtitle').textContent = 'Bom ter você com a gente.';
            profile(user);
        } else if (pending) {
            $('auth-title').textContent = 'Bem-vindo à comunidade.';
            $('auth-subtitle').textContent = 'Conclua seu perfil para começar.';
            $('oauth-email').value = pending.email || '';
            if (!$('oauth-username').value) $('oauth-username').value = pending.suggestedUsername || '';
        } else setView(view);
    }
    async function submit(form, errorHost, loading, action) {
        if (busy) return;
        clearError(errorHost);
        clearError($('auth-page-error'));
        form.querySelectorAll('[aria-invalid="true"]').forEach(field => field.removeAttribute('aria-invalid'));
        if (!form.checkValidity()) { form.reportValidity(); return; }
        busy = true;
        setBusy(form, true, loading);
        try {
            if (!auth) throw new Error('Não foi possível acessar o sistema de contas. Abra a loja pelo endereço mostrado ao iniciar o sistema.');
            await action();
            passwordFields(form);
            render(auth.getState());
            status('Sua conta está conectada.');
            if (!returnToStore()) $('auth-title').focus({ preventScroll: true });
        } catch (caught) {
            error(errorHost.parentElement.closest('[hidden]') ? $('auth-page-error') : errorHost, caught);
            const field = typeof caught?.field === 'string' ? form.elements.namedItem(caught.field) : null;
            if (field instanceof HTMLInputElement) {
                field.setAttribute('aria-invalid', 'true');
                const descriptions = new Set((field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
                descriptions.add(errorHost.id);
                field.setAttribute('aria-describedby', [...descriptions].join(' '));
                field.focus({ preventScroll: true });
            }
        }
        finally { busy = false; setBusy(form, false); }
    }
    rememberReturn();
    const oauthErrors = {
        provider_unavailable: 'Esse método de entrada ainda não está disponível. Use seu e-mail por enquanto.',
        account_exists: 'Já existe uma conta com esse e-mail. Entre usando o mesmo método do seu cadastro.',
        email_exists: 'Já existe uma conta com esse e-mail. Entre usando o mesmo método do seu cadastro.',
        access_denied: 'A entrada foi cancelada. Você pode tentar novamente ou usar seu e-mail.',
        oauth_cancelled: 'A entrada foi cancelada. Você pode tentar novamente ou usar seu e-mail.',
        invalid_state: 'Esta tentativa de entrada expirou. Comece novamente.',
        oauth_invalid_state: 'Esta tentativa de entrada expirou. Comece novamente.',
        oauth_expired: 'Esta tentativa de entrada expirou. Comece novamente.',
        email_unavailable: 'Não recebemos seu e-mail por esse método. Use a opção de e-mail para entrar ou criar sua conta.',
        email_required: 'Não recebemos seu e-mail por esse método. Use a opção de e-mail para entrar ou criar sua conta.',
        email_not_verified: 'O provedor não confirmou seu e-mail. Confira sua conta no provedor ou use a opção de e-mail.'
    };
    if (query.has('auth_error')) {
        error($('auth-page-error'), oauthErrors[query.get('auth_error')] || 'Não foi possível concluir essa entrada. Tente novamente ou use seu e-mail.');
        try { const clean = new URL(location.href); clean.searchParams.delete('auth_error'); history.replaceState(null, '', clean.href); } catch (_) { /* The error is already shown safely. */ }
    }
    $('auth-title').tabIndex = -1;
    setView(view);
    document.addEventListener('click', async event => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) return;
        const viewButton = target.closest('[data-auth-view]');
        const switchButton = target.closest('[data-auth-switch]');
        if (viewButton || switchButton) { setView((viewButton || switchButton).dataset[viewButton ? 'authView' : 'authSwitch'], Boolean(switchButton)); return; }
        const toggle = target.closest('[data-password-toggle]');
        if (toggle) {
            const field = $(toggle.dataset.passwordToggle);
            if (!field) return;
            const show = field.type === 'password';
            field.type = show ? 'text' : 'password';
            if (show) field.dataset.passwordVisible = 'true'; else delete field.dataset.passwordVisible;
            toggle.textContent = show ? 'Ocultar' : 'Mostrar';
            toggle.setAttribute('aria-pressed', String(show));
            toggle.setAttribute('aria-label', (show ? 'Ocultar' : 'Mostrar') + (field.id === 'register-confirmation' ? ' confirmação de senha' : ' senha'));
            return;
        }
        const provider = target.closest('[data-auth-provider]');
        if (provider) {
            if (busy) return;
            clearError($('auth-page-error'));
            busy = true;
            provider.setAttribute('aria-busy', 'true');
            status('Preparando sua entrada…');
            try {
                if (!auth) throw new Error('Não foi possível acessar o sistema de contas. Abra a loja pelo endereço mostrado ao iniciar o sistema.');
                await auth.startProvider(provider.dataset.authProvider);
            } catch (caught) { error($('auth-page-error'), caught); status(''); }
            finally { busy = false; provider.removeAttribute('aria-busy'); }
        }
        if (target.closest('[data-auth-refresh]')) {
            if (!auth || busy) return;
            status('Tentando conectar sua conta…');
            try { await auth.refresh(); render(auth.getState()); } catch (caught) { error($('auth-page-error'), caught); }
        }
    });
    document.querySelector('.auth-tabs').addEventListener('keydown', event => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        setView(event.key === 'Home' ? 'login' : event.key === 'End' ? 'register' : view === 'login' ? 'register' : 'login');
        $(view === 'login' ? 'auth-login-tab' : 'auth-register-tab').focus();
    });
    $('auth-login-form').addEventListener('submit', event => {
        event.preventDefault();
        submit(event.currentTarget, $('login-error'), 'Entrando…', () => auth.login({ email: $('login-email').value.trim(), password: $('login-password').value }));
    });
    $('auth-register-form').addEventListener('submit', event => {
        event.preventDefault();
        const form = event.currentTarget;
        clearError($('register-error'));
        if ($('register-password').value !== $('register-confirmation').value) { error($('register-error'), 'As senhas precisam ser iguais. Confira a confirmação.'); $('register-confirmation').focus(); return; }
        submit(form, $('register-error'), 'Criando sua conta…', () => auth.register({ username: $('register-username').value.trim(), email: $('register-email').value.trim(), password: $('register-password').value, passwordConfirmation: $('register-confirmation').value }));
    });
    $('auth-oauth-form').addEventListener('submit', event => {
        event.preventDefault();
        submit(event.currentTarget, $('oauth-error'), 'Concluindo…', () => auth.completeProfile({ username: $('oauth-username').value.trim() }));
    });
    $('oauth-cancel').addEventListener('click', async () => {
        if (busy || !auth) return;
        busy = true;
        clearError($('oauth-error'));
        try { await auth.cancelOAuth(); $('oauth-username').value = ''; render(auth.getState()); setView('login'); }
        catch (caught) { error($('oauth-error'), caught); }
        finally { busy = false; }
    });
    $('auth-logout').addEventListener('click', async () => {
        if (busy || !auth) return;
        busy = true;
        $('auth-logout').disabled = true;
        clearError($('auth-profile-error'));
        try { await auth.logout(); render(auth.getState()); status('Você saiu da sua conta.'); passwordFields($('auth-login-form')); passwordFields($('auth-register-form')); }
        catch (caught) { error($('auth-profile-error'), caught); }
        finally { busy = false; $('auth-logout').disabled = false; }
    });
    window.addEventListener('pokeanya:auth', () => { if (auth) render(auth.getState()); });
    if (auth) {
        render(auth.getState());
        Promise.resolve(auth.ready).then(() => {
            render(auth.getState());
            if (auth.getState().status === 'authenticated') returnToStore();
        }).catch(() => { render({ status: 'unavailable' }); });
    } else render({ status: 'unavailable' });
}());
