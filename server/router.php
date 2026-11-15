<?php
declare(strict_types=1);
/* Run with php -S 127.0.0.1:8000 server/router.php. Never serve private data here. */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($path) || str_contains($path, "\0") || str_contains(rawurldecode($path), "\0")) { http_response_code(400); exit('Bad request'); }
$decoded = preg_replace('~/+~', '/', rawurldecode($path));
if (str_contains($decoded, '\\') || preg_match('~(?:^|/)\.~', $decoded) || preg_match('~\A/(?:server|tests)(?:/|\z)~i', $decoded)) { http_response_code(404); exit('Not found'); }
if (strcasecmp($decoded, '/conta.html') === 0) { header('Cache-Control: no-store'); header('Location: /index.html', true, 303); exit; }
if (in_array(strtolower($decoded), ['/auth-client.js', '/auth-ui.js'], true)) { http_response_code(404); exit('Not found'); }
if (strcasecmp($decoded, '/password.html') === 0 && isset($_GET['role']) && $_GET['role'] !== 'admin') { header('Location: /index.html', true, 303); exit; }
if (str_starts_with($decoded, '/api/')) {
    if ($decoded === '/api/auth' || str_starts_with($decoded, '/api/auth/')) { http_response_code(404); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode(['ok'=>false,'error'=>['code'=>'not_found','message'=>'A loja tem entrada livre. Não há cadastro ou login de clientes.']], JSON_UNESCAPED_UNICODE); exit; }
    if (str_starts_with($decoded, '/api/admin/auth/password/')) { $paPasswordRoute = $decoded; require __DIR__ . '/password-api.php'; return true; }
    if (str_starts_with($decoded, '/api/store/') || $decoded === '/api/admin/store' || str_starts_with($decoded, '/api/admin/store/')) { $paStoreRoute = $decoded; require __DIR__ . '/store-api.php'; return true; }
    if (str_starts_with($decoded, '/api/admin/')) { $paAdminRoute = $decoded; require __DIR__ . '/admin-api.php'; return true; }
    http_response_code(404); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>['code'=>'not_found','message'=>'Endereço não encontrado.']], JSON_UNESCAPED_UNICODE); return true;
}
if (preg_match('~\A/media/([a-f0-9]{64})\z~', $decoded, $matches)) { require_once __DIR__ . '/store-core.php'; pa_store_media($matches[1]); return true; }
$root = realpath(__DIR__ . '/..');
$requested = $decoded === '/' ? '/index.html' : $decoded;
$file = realpath($root . $requested);
$rootPrefix = rtrim(str_replace('\\', '/', (string) $root), '/') . '/';
$normalized = str_replace('\\', '/', (string) $file);
if (PHP_OS_FAMILY === 'Windows') { $rootPrefix = strtolower($rootPrefix); $normalized = strtolower($normalized); }
$types = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'js' => 'application/javascript; charset=utf-8', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon'];
$extension = strtolower(pathinfo((string) $file, PATHINFO_EXTENSION));
if (!$file || !is_file($file) || !str_starts_with($normalized, $rootPrefix) || !isset($types[$extension])) { http_response_code(404); exit('Not found'); }
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) { http_response_code(405); header('Allow: GET, HEAD'); exit; }
$privateAdmin = in_array($normalized, [$rootPrefix . 'admin.html', $rootPrefix . 'admin.js', $rootPrefix . 'password.html', $rootPrefix . 'password-ui.js'], true);
if ($privateAdmin) {
    ini_set('display_errors', '0');
    require_once __DIR__ . '/admin-auth.php';
    try {
        pa_admin_bootstrap();
        if (pa_admin_current() === null) {
            header('Cache-Control: no-store, private');
            if ($extension === 'html') { header('Location: /admin-login.html?next=' . rawurlencode(basename($normalized)), true, 303); exit; }
            http_response_code(403); exit('Administrative authentication required');
        }
        session_write_close();
    } catch (Throwable) { header('Cache-Control: no-store, private'); http_response_code(503); exit('Administrative authentication unavailable'); }
}
header('Content-Type: ' . $types[$extension]); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: ' . (strtolower(basename((string) $file)) === 'password.html' ? 'no-referrer' : 'strict-origin-when-cross-origin'));
header('Cache-Control: ' . ($privateAdmin ? 'no-store, private' : 'no-cache')); if ($privateAdmin) header('Vary: Cookie'); header('Content-Length: ' . filesize($file));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($file);
return true;
