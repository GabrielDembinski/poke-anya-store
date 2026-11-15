<?php
declare(strict_types=1);
require_once __DIR__ . '/auth-core.php';

/* Anonymous browser ownership, not a customer account or a login session. */
const PA_VISITOR_LIFETIME = 2592000;

function pa_visitor_bootstrap(): void {
    $config = pa_auth_config();
    umask(0077);
    pa_auth_private_directory($config['data_dir']);
    pa_auth_private_directory($config['data_dir'] . '/visitor-sessions');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', (string) PA_VISITOR_LIFETIME);
    session_name('pokeanya_visit');
    session_save_path($config['data_dir'] . '/visitor-sessions');
    session_set_cookie_params([
        'lifetime' => PA_VISITOR_LIFETIME, 'path' => '/',
        'secure' => str_starts_with($config['base_url'], 'https://'),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    if (!session_start()) pa_auth_fail(503, 'session_unavailable', 'Não foi possível abrir a sessão de navegação. Tente novamente.');
    $created = $_SESSION['visitor_created_at'] ?? 0;
    $id = $_SESSION['visitor_id'] ?? null;
    if (!is_int($created) || $created < time() - PA_VISITOR_LIFETIME || !is_string($id) || !preg_match('/\A[a-f0-9]{48}\z/', $id)) {
        $_SESSION = [
            'visitor_id' => bin2hex(random_bytes(24)),
            'visitor_created_at' => time(),
            'visitor_name' => '',
            'csrf' => bin2hex(random_bytes(32)),
        ];
        session_regenerate_id(true);
    }
    if (!is_string($_SESSION['csrf'] ?? null) || strlen($_SESSION['csrf']) !== 64) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function pa_visitor_profile(): array {
    return [
        'id' => 'visitante-' . $_SESSION['visitor_id'],
        'nome' => is_string($_SESSION['visitor_name'] ?? null) ? $_SESSION['visitor_name'] : '',
    ];
}

function pa_visitor_csrf(): string { return (string) $_SESSION['csrf']; }

function pa_visitor_require_csrf(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && rtrim($origin, '/') !== pa_auth_config()['base_url']) pa_auth_fail(403, 'invalid_origin', 'Não foi possível confirmar a origem desta solicitação.');
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($provided) || !hash_equals(pa_visitor_csrf(), $provided)) pa_auth_fail(403, 'invalid_csrf', 'Sua sessão de navegação foi atualizada. Recarregue a página e tente novamente.');
}
