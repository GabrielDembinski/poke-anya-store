<?php

declare(strict_types=1);
ini_set('display_errors', '0');
require_once __DIR__ . '/auth-core.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
try {
    pa_auth_bootstrap();
    if (is_file(__DIR__ . '/auth-oauth.php')) require_once __DIR__ . '/auth-oauth.php';
    $route = $paAuthRoute ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($route === '/api/auth/session') {
        if ($method !== 'GET') pa_auth_fail(405, 'method_not_allowed', 'Método não permitido.');
        echo json_encode(pa_auth_envelope(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
    if (preg_match('~\A/api/auth/oauth/(google)/callback\z~', $route, $matches)) {
        if ($method !== 'GET') pa_auth_fail(405, 'method_not_allowed', 'Método não permitido.');
        if (!function_exists('pa_auth_oauth_callback')) pa_auth_fail(503, 'provider_unavailable', 'Este método de entrada ainda não está disponível.');
        pa_auth_oauth_callback($matches[1]);
        exit;
    }
    $known = in_array($route, ['/api/auth/register', '/api/auth/login', '/api/auth/logout', '/api/auth/complete-profile', '/api/auth/cancel-oauth'], true) || preg_match('~\A/api/auth/oauth/(google)/start\z~', $route);
    if (!$known) pa_auth_fail(404, 'not_found', 'Endereço de autenticação não encontrado.');
    if ($method !== 'POST') pa_auth_fail(405, 'method_not_allowed', 'Método não permitido.');
    pa_auth_require_csrf();
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) pa_auth_fail(415, 'invalid_content_type', 'Envie os dados no formato esperado.');
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) pa_auth_fail(413, 'request_too_large', 'A solicitação excede o tamanho permitido.');
    $raw = file_get_contents('php://input', false, null, 0, 8193);
    if ($raw === false || strlen($raw) > 8192) pa_auth_fail(413, 'request_too_large', 'A solicitação excede o tamanho permitido.');
    try {
        $decoded = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        pa_auth_fail(400, 'invalid_json', 'Os dados enviados não são válidos.');
    }
    if (!$decoded instanceof stdClass) pa_auth_fail(400, 'invalid_json', 'Os dados enviados não são válidos.');
    $input = get_object_vars($decoded);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if ($route === '/api/auth/register') {
        pa_auth_require_guest();
        pa_auth_rate_limit('register', $ip, 10, 3600);
        if (isset($input['role']) && $input['role'] !== 'customer') pa_auth_fail(400, 'invalid_role', 'Este cadastro é exclusivo para clientes.');
        $username = pa_auth_username($input['username'] ?? null);
        $email = pa_auth_email($input['email'] ?? null);
        $password = pa_auth_password($input['password'] ?? null);
        if (!is_string($input['passwordConfirmation'] ?? null) || !hash_equals($password, $input['passwordConfirmation'])) pa_auth_fail(400, 'password_mismatch', 'As senhas precisam ser iguais.', 'passwordConfirmation');
        $hash = password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
        $user = pa_auth_create_user($username, $email, $hash);
        pa_auth_login_user($user['id']);
        http_response_code(201);
    } elseif ($route === '/api/auth/login') {
        pa_auth_require_guest();
        $email = pa_auth_email($input['email'] ?? null);
        pa_auth_rate_limit('login-ip', $ip, 30, 900);
        pa_auth_rate_limit('login-email', $email, 10, 900);
        try {
            $password = pa_auth_password($input['password'] ?? null);
        } catch (PaAuthError) {
            pa_auth_fail(401, 'invalid_credentials', 'Não foi possível entrar. Confira o e-mail e a senha.');
        }
        $user = pa_auth_user_by_email($email);
        $dummy = defined('PASSWORD_ARGON2ID') ? '$argon2id$v=19$m=65536,t=4,p=1$WGZtTUVKblE1N0JwVE9Mdg$Xz6/SpmGk72KgpLSN9JchDwx5x/8qY9vaoEAEurSN7E' : '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $valid = password_verify($password, $user['password_hash'] ?? $dummy);
        if (!$user || $user['password_hash'] === null || !$valid) pa_auth_fail(401, 'invalid_credentials', 'Não foi possível entrar. Confira o e-mail e a senha.');
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        if (password_needs_rehash($user['password_hash'], $algorithm)) {
            $statement = pa_auth_db()->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?');
            $statement->execute([password_hash($password, $algorithm), gmdate('Y-m-d\TH:i:s\Z'), $user['id']]);
        }
        $clear = pa_auth_db()->prepare('DELETE FROM rate_limits WHERE key = ?');
        $clear->execute([hash('sha256', 'login-email:' . $email)]);
        pa_auth_login_user($user['id']);
    } elseif ($route === '/api/auth/logout') pa_auth_logout();
    elseif ($route === '/api/auth/cancel-oauth') {
        if (function_exists('pa_auth_oauth_cancel')) pa_auth_oauth_cancel();
    } elseif ($route === '/api/auth/complete-profile') {
        pa_auth_require_guest();
        pa_auth_rate_limit('complete-profile', $ip, 20, 3600);
        if (!function_exists('pa_auth_oauth_complete')) pa_auth_fail(503, 'provider_unavailable', 'Este método de entrada ainda não está disponível.');
        pa_auth_oauth_complete(pa_auth_username($input['username'] ?? null));
    } else {
        pa_auth_require_guest();
        pa_auth_rate_limit('oauth-start', $ip, 30, 900);
        preg_match('~\A/api/auth/oauth/(google)/start\z~', $route, $matches);
        if (!function_exists('pa_auth_oauth_start')) pa_auth_fail(503, 'provider_unavailable', 'Este método de entrada ainda não está disponível. Use seu e-mail.');
        $started = pa_auth_oauth_start($matches[1]);
        echo json_encode(array_merge(pa_auth_envelope(), $started), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
    echo json_encode(pa_auth_envelope(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (PaAuthError $error) {
    http_response_code($error->status);
    $body = ['code' => $error->errorCode, 'message' => $error->getMessage()];
    if ($error->field !== null) $body['field'] = $error->field;
    echo json_encode(['ok' => false, 'error' => $body], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('PokeAnya auth: ' . get_class($error));
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => ['code' => 'service_unavailable', 'message' => 'O serviço de contas não está disponível. Tente novamente em instantes.']], JSON_UNESCAPED_UNICODE);
}
