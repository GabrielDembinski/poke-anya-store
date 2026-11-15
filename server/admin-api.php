<?php

declare(strict_types=1);
ini_set('display_errors', '0');
require_once __DIR__ . '/admin-auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
try {
    pa_admin_bootstrap();
    $route = $paAdminRoute ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($route === '/api/admin/auth/session') {
        if ($method !== 'GET') pa_auth_fail(405, 'method_not_allowed', 'Método não permitido.');
        echo json_encode(pa_admin_envelope(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
    if (!in_array($route, ['/api/admin/auth/login', '/api/admin/auth/logout'], true)) pa_auth_fail(404, 'not_found', 'Endereço administrativo não encontrado.');
    if ($method !== 'POST') pa_auth_fail(405, 'method_not_allowed', 'Método não permitido.');
    pa_admin_csrf();
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
    if ($route === '/api/admin/auth/logout') pa_admin_logout();
    else {
        if (!pa_admin_record()) pa_auth_fail(503, 'admin_not_configured', 'A conta administrativa ainda não foi configurada. Use o configurador local para criar o acesso único.');
        if (pa_admin_current()) pa_auth_fail(409, 'already_authenticated', 'A conta administrativa já está conectada.');
        $email = pa_auth_email($input['email'] ?? null);
        pa_admin_limit('login-ip', $_SERVER['REMOTE_ADDR'] ?? 'unknown', 30);
        pa_admin_limit('login-email', $email, 10);
        try {
            $password = pa_admin_password($input['password'] ?? null);
        } catch (PaAuthError) {
            pa_auth_fail(401, 'invalid_credentials', 'Não foi possível entrar. Confira o e-mail e a senha.');
        }
        $admin = pa_admin_record();
        $validPassword = password_verify($password, $admin['password_hash']);
        if (!hash_equals($admin['email'], $email) || !$validPassword) pa_auth_fail(401, 'invalid_credentials', 'Não foi possível entrar. Confira o e-mail e a senha.');
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        if (password_needs_rehash($admin['password_hash'], $algorithm)) {
            $update = pa_admin_db()->prepare('UPDATE administrator SET password_hash=?,updated_at=? WHERE id=1');
            $update->execute([password_hash($password, $algorithm), gmdate('Y-m-d\TH:i:s\Z')]);
        }
        $clear = pa_admin_db()->prepare('DELETE FROM admin_rate_limits WHERE key=?');
        $clear->execute([hash('sha256', 'login-email:' . $email)]);
        pa_admin_login();
    }
    echo json_encode(pa_admin_envelope(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (PaAuthError $error) {
    http_response_code($error->status);
    $body = ['code' => $error->errorCode, 'message' => $error->getMessage()];
    if ($error->field !== null) $body['field'] = $error->field;
    echo json_encode(['ok' => false, 'error' => $body], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('PokeAnya admin: ' . get_class($error));
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => ['code' => 'service_unavailable', 'message' => 'O serviço administrativo não está disponível. Tente novamente em instantes.']], JSON_UNESCAPED_UNICODE);
}
