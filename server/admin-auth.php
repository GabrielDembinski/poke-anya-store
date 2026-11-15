<?php

declare(strict_types=1);
require_once __DIR__ . '/auth-core.php';

/* Administrative identities and sessions never use customer cookies or tables. */
function pa_admin_db(): PDO
{
    static $db;
    if ($db instanceof PDO) return $db;
    if (!extension_loaded('pdo_sqlite')) pa_auth_fail(503, 'storage_unavailable', 'O serviço administrativo não está disponível.');
    $directory = pa_auth_config()['data_dir'];
    umask(0077);
    pa_auth_private_directory($directory);
    $db = new PDO('sqlite:' . $directory . '/admins.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA busy_timeout = 5000; PRAGMA journal_mode = WAL;');
    $db->exec('CREATE TABLE IF NOT EXISTS administrator (id INTEGER PRIMARY KEY CHECK(id = 1), name TEXT NOT NULL, email TEXT NOT NULL COLLATE NOCASE UNIQUE, password_hash TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS admin_rate_limits (key TEXT PRIMARY KEY, window_start INTEGER NOT NULL, attempts INTEGER NOT NULL);');
    pa_auth_password_version_column($db, 'administrator');
    return $db;
}
function pa_admin_bootstrap(bool $recordActivity = true): void
{
    $config = pa_auth_config();
    if (!extension_loaded('pdo_sqlite')) pa_auth_fail(503, 'storage_unavailable', 'O serviço administrativo não está disponível.');
    umask(0077);
    pa_auth_private_directory($config['data_dir']);
    pa_auth_private_directory($config['data_dir'] . '/admin-sessions');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', '28800');
    session_name('pokeanya_admin');
    session_save_path($config['data_dir'] . '/admin-sessions');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => str_starts_with($config['base_url'], 'https://'), 'httponly' => true, 'samesite' => 'Lax']);
    if (!session_start()) pa_auth_fail(503, 'session_unavailable', 'Não foi possível abrir a sessão administrativa.');
    $now = time();
    if (isset($_SESSION['created_at']) && (($now - (int) $_SESSION['created_at']) > 28800 || ($now - (int) ($_SESSION['last_seen'] ?? 0)) > 1800)) pa_admin_logout();
    $_SESSION['created_at'] ??= $now;
    if ($recordActivity || !isset($_SESSION['last_seen'])) $_SESSION['last_seen'] = $now;
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
function pa_admin_record(): ?array
{
    return pa_admin_db()->query('SELECT * FROM administrator WHERE id = 1')->fetch() ?: null;
}
function pa_admin_public(array $admin): array
{
    return ['id' => '1', 'name' => $admin['name'], 'email' => $admin['email'], 'role' => 'admin'];
}
function pa_admin_current(): ?array
{
    if (($_SESSION['admin_id'] ?? null) !== '1') return null;
    $admin = pa_admin_record();
    if (!$admin) {
        pa_admin_logout();
        return null;
    }
    if ((int) ($_SESSION['password_version'] ?? 0) !== (int) $admin['password_version']) {
        pa_admin_logout();
        return null;
    }
    return pa_admin_public($admin);
}
function pa_admin_require(): array
{
    $admin = pa_admin_current();
    if (!$admin) pa_auth_fail(401, 'admin_required', 'Entre na conta do administrador para continuar.');
    return $admin;
}
function pa_admin_login(): array
{
    $admin = pa_admin_record();
    if (!$admin) pa_auth_fail(503, 'admin_not_configured', 'A conta administrativa ainda não foi configurada.');
    $_SESSION = ['admin_id' => '1', 'password_version' => (int) $admin['password_version'], 'csrf' => bin2hex(random_bytes(32)), 'created_at' => time(), 'last_seen' => time()];
    session_regenerate_id(true);
    return pa_admin_public($admin);
}
function pa_admin_logout(): void
{
    $_SESSION = ['csrf' => bin2hex(random_bytes(32)), 'created_at' => time(), 'last_seen' => time()];
    session_regenerate_id(true);
}
function pa_admin_csrf(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && rtrim($origin, '/') !== pa_auth_config()['base_url']) pa_auth_fail(403, 'invalid_origin', 'Não foi possível confirmar a origem desta solicitação.');
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals((string) $_SESSION['csrf'], $token)) pa_auth_fail(403, 'invalid_csrf', 'Sua sessão foi atualizada. Recarregue a página e tente novamente.');
}
function pa_admin_password(mixed $value): string
{
    try {
        $password = pa_auth_password($value);
    } catch (PaAuthError) {
        pa_auth_fail(400, 'invalid_password', 'Escolha uma senha administrativa de 12 a 128 caracteres.', 'password');
    }
    if (preg_match_all('/./us', $password) < 12) pa_auth_fail(400, 'invalid_password', 'Escolha uma senha administrativa de 12 a 128 caracteres.', 'password');
    return $password;
}
function pa_admin_setup(array $input): void
{
    $name = $input['name'] ?? null;
    if (!is_string($name) || !preg_match('//u', $name) || preg_match('/[\x00-\x1f\x7f]/', $name)) pa_auth_fail(400, 'invalid_name', 'Informe um nome válido para o administrador.');
    $name = trim($name);
    $length = preg_match_all('/./us', $name);
    if ($length < 2 || $length > 80) pa_auth_fail(400, 'invalid_name', 'O nome do administrador deve ter de 2 a 80 caracteres.');
    $email = pa_auth_email($input['email'] ?? null);
    $password = pa_admin_password($input['password'] ?? null);
    if (!is_string($input['passwordConfirmation'] ?? null) || !hash_equals($password, $input['passwordConfirmation'])) pa_auth_fail(400, 'password_mismatch', 'As senhas precisam ser iguais.');
    $db = pa_admin_db();
    $db->exec('BEGIN IMMEDIATE');
    try {
        if (pa_admin_record()) pa_auth_fail(409, 'admin_already_configured', 'Já existe um administrador configurado. A conta existente foi preservada.');
        $hash = password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $query = $db->prepare('INSERT INTO administrator(id,name,email,password_hash,created_at,updated_at) VALUES(1,?,?,?,?,?)');
        $query->execute([$name, $email, $hash, $now, $now]);
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable) {
        }
        throw $error;
    }
}
function pa_admin_limit(string $scope, string $identifier, int $limit): void
{
    $db = pa_admin_db();
    $key = hash('sha256', $scope . ':' . $identifier);
    $now = time();
    $seconds = 900;
    $query = $db->prepare('INSERT INTO admin_rate_limits(key,window_start,attempts) VALUES(?,?,1) ON CONFLICT(key) DO UPDATE SET attempts=CASE WHEN window_start <= ? THEN 1 ELSE attempts+1 END, window_start=CASE WHEN window_start <= ? THEN excluded.window_start ELSE window_start END RETURNING attempts');
    $query->execute([$key, $now, $now - $seconds, $now - $seconds]);
    $attempts = (int) $query->fetchColumn();
    $query->closeCursor();
    if ($attempts > $limit) {
        header('Retry-After: 900');
        pa_auth_fail(429, 'too_many_attempts', 'Muitas tentativas. Aguarde alguns minutos antes de tentar novamente.');
    }
    if (random_int(1, 100) === 1) {
        $delete = $db->prepare('DELETE FROM admin_rate_limits WHERE window_start < ?');
        $delete->execute([$now - 86400]);
    }
}
function pa_admin_envelope(): array
{
    return ['ok' => true, 'admin' => pa_admin_current(), 'csrf' => (string) $_SESSION['csrf'], 'configured' => pa_admin_record() !== null];
}
