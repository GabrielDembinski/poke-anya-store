<?php
declare(strict_types=1);

final class PaAuthError extends RuntimeException {
    public function __construct(public int $status, public string $errorCode, string $message, public ?string $field = null) { parent::__construct($message); }
}
function pa_auth_fail(int $status, string $code, string $message, ?string $field = null): never { throw new PaAuthError($status, $code, $message, $field); }
function pa_auth_inside(string $path, string $root): bool {
    $path = str_replace('\\', '/', $path); $root = rtrim(str_replace('\\', '/', $root), '/');
    if (PHP_OS_FAMILY === 'Windows') { $path = strtolower($path); $root = strtolower($root); }
    return $path === $root || str_starts_with($path, $root . '/');
}
function pa_auth_resolved_destination(string $path): string {
    $suffix = [];
    $ancestor = $path;
    while (($resolved = realpath($ancestor)) === false) {
        $parent = dirname($ancestor);
        if ($parent === $ancestor) return $path;
        array_unshift($suffix, basename($ancestor));
        $ancestor = $parent;
    }
    return $resolved . ($suffix ? DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $suffix) : '');
}
function pa_auth_config(): array {
    static $config;
    if ($config !== null) return $config;
    $project = (string) realpath(__DIR__ . '/..');
    $file = getenv('POKEANYA_AUTH_CONFIG') ?: '';
    $private = [];
    if ($file !== '') {
        $resolved = realpath($file);
        if (!$resolved || pa_auth_inside($resolved, $project) || !is_file($resolved)) pa_auth_fail(503, 'configuration_error', 'A configuração privada de contas não está disponível.');
        $private = require $resolved;
        if (!is_array($private)) pa_auth_fail(503, 'configuration_error', 'A configuração de contas é inválida.');
    }
    $read = static function(string $env, string $key, string $default = '') use ($private): string {
        $value = getenv($env);
        return $value !== false ? trim($value) : (isset($private[$key]) && is_string($private[$key]) && trim($private[$key]) !== '' ? trim($private[$key]) : $default);
    };
    $environment = $read('POKEANYA_APP_ENV', 'app_env', 'development');
    $base = rtrim($read('POKEANYA_BASE_URL', 'base_url', 'http://127.0.0.1:8000'), '/');
    $url = parse_url($base);
    $host = strtolower($url['host'] ?? '');
    if (!in_array($url['scheme'] ?? '', ['http', 'https'], true) || $host === '' || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || !in_array($url['path'] ?? '', ['', '/'], true)) pa_auth_fail(503, 'configuration_error', 'O endereço do serviço de contas é inválido.');
    if (($url['scheme'] ?? '') !== 'https' && ($environment === 'production' || !in_array($host, ['localhost', '127.0.0.1', '::1'], true))) pa_auth_fail(503, 'https_required', 'O serviço de contas exige uma conexão HTTPS.');
    $defaultData = PHP_OS_FAMILY === 'Windows' && getenv('LOCALAPPDATA') ? getenv('LOCALAPPDATA') . '/PokeAnyaStore/auth' : '';
    $data = $read('POKEANYA_AUTH_DATA_DIR', 'data_dir', $defaultData);
    if ($data === '' || (!str_starts_with($data, '/') && !preg_match('~^[A-Za-z]:[\\\\/]~', $data)) || preg_match('~(?:^|[\\\\/])\.\.(?:[\\\\/]|$)~', $data) || pa_auth_inside(pa_auth_resolved_destination($data), $project)) pa_auth_fail(503, 'configuration_error', 'Defina uma pasta privada, fora dos arquivos públicos, para as contas.');
    $config = [
        'app_env' => $environment, 'base_url' => $base, 'data_dir' => rtrim($data, '/\\')
    ];
    return $config;
}
function pa_auth_private_directory(string $path): void {
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) pa_auth_fail(503, 'storage_unavailable', 'Não foi possível acessar o armazenamento de contas.');
    if (!is_writable($path)) pa_auth_fail(503, 'storage_unavailable', 'O armazenamento de contas não está disponível.');
}
function pa_auth_bootstrap(): void {
    $config = pa_auth_config();
    if (!extension_loaded('pdo_sqlite')) pa_auth_fail(503, 'storage_unavailable', 'O servidor de contas não está disponível.');
    umask(0077);
    pa_auth_private_directory($config['data_dir']);
    pa_auth_private_directory($config['data_dir'] . '/sessions');
    ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1'); ini_set('session.gc_maxlifetime', '28800');
    session_name('pokeanya_customer'); session_save_path($config['data_dir'] . '/sessions');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => str_starts_with($config['base_url'], 'https://'), 'httponly' => true, 'samesite' => 'Lax']);
    if (!session_start()) pa_auth_fail(503, 'session_unavailable', 'Não foi possível abrir uma sessão de cliente.');
    $now = time();
    if (isset($_SESSION['created_at']) && (($now - (int) $_SESSION['created_at']) > 28800 || ($now - (int) ($_SESSION['last_seen'] ?? 0)) > 1800)) { $_SESSION = []; session_regenerate_id(true); }
    $_SESSION['created_at'] ??= $now; $_SESSION['last_seen'] = $now;
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
function pa_auth_db(): PDO {
    static $db;
    if ($db instanceof PDO) return $db;
    $db = new PDO('sqlite:' . pa_auth_config()['data_dir'] . '/customers.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000; PRAGMA journal_mode = WAL;');
    // Preserve the legacy provider value in the schema so historical rows remain intact.
    // New identities, public methods and authentication accept only Google or email.
    $db->exec("CREATE TABLE IF NOT EXISTS users (id TEXT PRIMARY KEY, username TEXT NOT NULL COLLATE NOCASE UNIQUE, email TEXT NOT NULL COLLATE NOCASE UNIQUE, password_hash TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS auth_identities (provider TEXT NOT NULL CHECK(provider IN ('google','facebook')), subject TEXT NOT NULL, user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE, PRIMARY KEY(provider,subject), UNIQUE(provider,user_id));
        CREATE TABLE IF NOT EXISTS rate_limits (key TEXT PRIMARY KEY, window_start INTEGER NOT NULL, attempts INTEGER NOT NULL);");
    pa_auth_password_version_column($db, 'users');
    return $db;
}
function pa_auth_password_version_column(PDO $db, string $table): void {
    if (!in_array($table, ['users', 'administrator'], true)) throw new LogicException('Invalid identity table.');
    $columns = $db->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (in_array('password_version', $columns, true)) return;
    try { $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN password_version INTEGER NOT NULL DEFAULT 0'); }
    catch (PDOException $error) {
        // Concurrent initial requests may both discover a legacy database.
        $columns = $db->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('password_version', $columns, true)) throw $error;
    }
}
function pa_auth_username(mixed $value): string {
    if (!is_string($value) || !preg_match('/\A[A-Za-z0-9_]{3,30}\z/', $value) || in_array(strtolower($value), ['admin', 'administrador', 'administrator', 'root', 'suporte', 'support'], true)) pa_auth_fail(400, 'invalid_username', 'Escolha um nome de usuário com 3 a 30 letras sem acentos, números ou _.', 'username');
    return $value;
}
function pa_auth_email(mixed $value): string {
    $email = is_string($value) ? strtolower(trim($value)) : '';
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) pa_auth_fail(400, 'invalid_email', 'Informe um e-mail válido.', 'email');
    return $email;
}
function pa_auth_password(mixed $value): string {
    if (!is_string($value) || !preg_match('//u', $value)) pa_auth_fail(400, 'invalid_password', 'Escolha uma senha de 10 a 128 caracteres.', 'password');
    $length = preg_match_all('/./us', $value);
    if ($length < 10 || $length > 128) pa_auth_fail(400, 'invalid_password', 'Escolha uma senha de 10 a 128 caracteres.', 'password');
    if (!defined('PASSWORD_ARGON2ID') && strlen($value) > 72) pa_auth_fail(400, 'invalid_password', 'Esta senha excede o tamanho aceito pelo servidor. Escolha uma senha menor.', 'password');
    if (!defined('PASSWORD_ARGON2ID') && str_contains($value, "\0")) pa_auth_fail(400, 'invalid_password', 'Esta senha contém um caractere que o servidor não aceita.', 'password');
    return $value;
}
function pa_auth_user_by_id(string $id): ?array { $statement = pa_auth_db()->prepare('SELECT * FROM users WHERE id = ?'); $statement->execute([$id]); return $statement->fetch() ?: null; }
function pa_auth_user_by_email(string $email): ?array { $statement = pa_auth_db()->prepare('SELECT * FROM users WHERE email = ? COLLATE NOCASE'); $statement->execute([$email]); return $statement->fetch() ?: null; }
function pa_auth_identity_user(string $provider, string $subject): ?array { $statement = pa_auth_db()->prepare('SELECT users.* FROM users JOIN auth_identities ON users.id = auth_identities.user_id WHERE provider = ? AND subject = ?'); $statement->execute([$provider, $subject]); return $statement->fetch() ?: null; }
function pa_auth_public_user(array $user): array {
    $providers = $user['password_hash'] !== null ? ['email'] : [];
    $statement = pa_auth_db()->prepare("SELECT provider FROM auth_identities WHERE user_id = ? AND provider = 'google'"); $statement->execute([$user['id']]);
    foreach ($statement->fetchAll() as $identity) $providers[] = $identity['provider'];
    return ['id' => $user['id'], 'username' => $user['username'], 'email' => $user['email'], 'role' => 'customer', 'providers' => $providers];
}
function pa_auth_session_user(): ?array {
    if (!isset($_SESSION['user_id']) || !is_string($_SESSION['user_id'])) return null;
    $user = pa_auth_user_by_id($_SESSION['user_id']);
    if (!$user) { unset($_SESSION['user_id']); return null; }
    if ((int) ($_SESSION['password_version'] ?? 0) !== (int) $user['password_version']) { pa_auth_logout(); return null; }
    $public = pa_auth_public_user($user);
    if ($public['providers'] === []) { pa_auth_logout(); return null; }
    return $public;
}
function pa_auth_require_guest(): void { if (pa_auth_session_user() !== null) pa_auth_fail(409, 'already_authenticated', 'Você já está conectado. Saia da conta antes de entrar em outra.'); }
function pa_auth_login_user(string $id): array {
    $user = pa_auth_user_by_id($id);
    if (!$user) pa_auth_fail(401, 'invalid_credentials', 'Não foi possível entrar. Confira o e-mail e a senha.');
    $public = pa_auth_public_user($user);
    if ($public['providers'] === []) pa_auth_fail(401, 'unsupported_login', 'Entre usando Google ou e-mail e senha.');
    $_SESSION = ['user_id' => $id, 'password_version' => (int) $user['password_version'], 'csrf' => bin2hex(random_bytes(32)), 'created_at' => time(), 'last_seen' => time()];
    session_regenerate_id(true);
    return $public;
}
function pa_auth_logout(): void { $_SESSION = ['csrf' => bin2hex(random_bytes(32)), 'created_at' => time(), 'last_seen' => time()]; session_regenerate_id(true); }
function pa_auth_csrf(): string { return (string) $_SESSION['csrf']; }
function pa_auth_require_csrf(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && rtrim($origin, '/') !== pa_auth_config()['base_url']) pa_auth_fail(403, 'invalid_origin', 'Não foi possível confirmar a origem desta solicitação.');
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($provided) || !hash_equals(pa_auth_csrf(), $provided)) pa_auth_fail(403, 'invalid_csrf', 'Sua sessão foi atualizada. Recarregue a página e tente novamente.');
}
function pa_auth_create_user(string $username, string $email, ?string $passwordHash, ?array $identity = null): array {
    $username = pa_auth_username($username); $email = pa_auth_email($email); $db = pa_auth_db();
    if ($identity !== null && (($identity['provider'] ?? '') !== 'google' || !is_string($identity['subject'] ?? null) || strlen($identity['subject']) > 255 || $identity['subject'] === '')) pa_auth_fail(400, 'invalid_identity', 'Não foi possível confirmar o perfil do provedor.');
    $db->exec('BEGIN IMMEDIATE');
    try {
        $query = $db->prepare('SELECT 1 FROM users WHERE username = ? COLLATE NOCASE'); $query->execute([$username]);
        if ($query->fetchColumn()) pa_auth_fail(409, 'username_taken', 'Este nome de usuário já está em uso. Escolha outro.', 'username');
        if (pa_auth_user_by_email($email)) pa_auth_fail(409, 'email_taken', 'Já existe uma conta com este e-mail. Entre na conta existente.', 'email');
        if ($identity && pa_auth_identity_user($identity['provider'], $identity['subject'])) pa_auth_fail(409, 'identity_exists', 'Este perfil já possui uma conta. Use a opção de entrar.');
        $id = bin2hex(random_bytes(16)); $now = gmdate('Y-m-d\TH:i:s\Z');
        $query = $db->prepare('INSERT INTO users(id,username,email,password_hash,created_at,updated_at) VALUES(?,?,?,?,?,?)'); $query->execute([$id, $username, $email, $passwordHash, $now, $now]);
        if ($identity) { $query = $db->prepare('INSERT INTO auth_identities(provider,subject,user_id) VALUES(?,?,?)'); $query->execute([$identity['provider'], $identity['subject'], $id]); }
        $db->exec('COMMIT');
        return pa_auth_user_by_id($id);
    } catch (Throwable $error) {
        try { $db->exec('ROLLBACK'); } catch (Throwable) {}
        if ($error instanceof PDOException && (string) $error->getCode() === '23000') pa_auth_fail(409, 'account_exists', 'Já existe uma conta com estes dados. Use a opção de entrar.');
        throw $error;
    }
}
function pa_auth_rate_limit(string $scope, string $identifier, int $limit, int $seconds): void {
    $db = pa_auth_db(); $key = hash('sha256', $scope . ':' . $identifier); $now = time();
    $statement = $db->prepare('INSERT INTO rate_limits(key,window_start,attempts) VALUES(?,?,1) ON CONFLICT(key) DO UPDATE SET attempts = CASE WHEN window_start <= ? THEN 1 ELSE attempts + 1 END, window_start = CASE WHEN window_start <= ? THEN excluded.window_start ELSE window_start END RETURNING attempts');
    $statement->execute([$key, $now, $now - $seconds, $now - $seconds]);
    $attempts = (int) $statement->fetchColumn(); $statement->closeCursor();
    if ($attempts > $limit) { header('Retry-After: ' . $seconds); pa_auth_fail(429, 'too_many_attempts', 'Muitas tentativas. Aguarde alguns minutos antes de tentar novamente.'); }
    if (random_int(1, 100) === 1) { $delete = $db->prepare('DELETE FROM rate_limits WHERE window_start < ?'); $delete->execute([$now - 86400]); }
}
function pa_auth_envelope(): array {
    return ['ok' => true, 'user' => pa_auth_session_user(), 'csrf' => pa_auth_csrf(), 'providers' => function_exists('pa_auth_provider_status') ? pa_auth_provider_status() : ['google' => false], 'pendingRegistration' => function_exists('pa_auth_oauth_pending_public') ? pa_auth_oauth_pending_public() : null];
}
