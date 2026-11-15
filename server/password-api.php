<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require_once __DIR__ . '/password-core.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
try {
    $route = $paPasswordRoute ?? '';
    if (!in_array($route, ['/api/admin/auth/password/status', '/api/admin/auth/password/change'], true)) pa_auth_fail(404, 'not_found', 'Endereço não encontrado.');
    pa_admin_bootstrap();
    pa_admin_require();
    if ($route === '/api/admin/auth/password/status') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') pa_auth_fail(405, 'method_not_allowed', 'Método não permitido.');
        echo json_encode(['ok'=>true, 'authenticated'=>true, 'canChangePassword'=>true, 'role'=>'admin', 'csrf'=>(string) $_SESSION['csrf']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') pa_auth_fail(405, 'method_not_allowed', 'Método não permitido.');
    pa_admin_csrf();
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) pa_auth_fail(415, 'invalid_content_type', 'Envie os dados no formato esperado.');
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) pa_auth_fail(413, 'request_too_large', 'Os dados excedem o limite permitido.');
    $raw = file_get_contents('php://input', false, null, 0, 8193);
    if ($raw === false || strlen($raw) > 8192) pa_auth_fail(413, 'request_too_large', 'Os dados excedem o limite permitido.');
    try { $input = json_decode($raw, false, 32, JSON_THROW_ON_ERROR); } catch (JsonException) { pa_auth_fail(400, 'invalid_json', 'Os dados enviados não são válidos.'); }
    if (!$input instanceof stdClass) pa_auth_fail(400, 'invalid_json', 'Os dados enviados não são válidos.');
    echo json_encode(pa_admin_change_password(get_object_vars($input)), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (PaAuthError $error) {
    http_response_code($error->status);
    echo json_encode(['ok'=>false,'error'=>['code'=>$error->errorCode,'message'=>$error->getMessage()]], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('PokeAnya admin password: ' . get_class($error));
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Não foi possível acessar o serviço administrativo. Tente novamente.']], JSON_UNESCAPED_UNICODE);
}
