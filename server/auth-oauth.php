<?php

declare(strict_types=1);

// OAuth credentials and tokens remain on the server. This file has no HTTP test mode.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Firebase\\JWT\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $name = substr($class, strlen($prefix));
    if (preg_match('/^[A-Za-z]+$/D', $name)) {
        $path = __DIR__ . '/vendor/php-jwt/src/' . $name . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

const PA_AUTH_OAUTH_TTL = 600;

function pa_auth_provider_status(): array
{
    $config = pa_auth_config();
    $transport = extension_loaded('curl') && extension_loaded('openssl');
    return [
        'google' => $transport && $config['google_client_id'] !== '' && $config['google_client_secret'] !== '',
    ];
}

function pa_auth_oauth_provider(string $provider): void
{
    if ($provider !== 'google') {
        pa_auth_fail(404, 'provider_not_found', 'Esta opção de acesso não existe.');
    }
    if (!pa_auth_provider_status()[$provider]) {
        pa_auth_fail(503, 'provider_unavailable', 'O acesso com ' . ucfirst($provider) . ' ainda não foi configurado. Entre ou crie sua conta com e-mail.');
    }
}

function pa_auth_oauth_b64(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function pa_auth_oauth_callback_url(string $provider): string
{
    // base_url is validated, trusted configuration; never build a redirect from Host.
    return rtrim(pa_auth_config()['base_url'], '/') . '/api/auth/oauth/' . $provider . '/callback';
}

function pa_auth_oauth_start(string $provider): array
{
    pa_auth_require_guest();
    pa_auth_oauth_provider($provider);
    $config = pa_auth_config();
    $state = pa_auth_oauth_b64(random_bytes(32));
    $flow = ['provider' => $provider, 'state' => $state, 'expiresAt' => time() + PA_AUTH_OAUTH_TTL];
    $params = ['response_type' => 'code', 'redirect_uri' => pa_auth_oauth_callback_url($provider), 'state' => $state];
    $flow['verifier'] = pa_auth_oauth_b64(random_bytes(64));
    $flow['nonce'] = pa_auth_oauth_b64(random_bytes(32));
    $params += [
        'client_id' => $config['google_client_id'],
        'scope' => 'openid email profile',
        'nonce' => $flow['nonce'],
        'code_challenge' => pa_auth_oauth_b64(hash('sha256', $flow['verifier'], true)),
        'code_challenge_method' => 'S256',
        'prompt' => 'select_account',
    ];
    $url = 'https://accounts.google.com/o/oauth2/v2/auth';
    unset($_SESSION['auth_oauth_pending']);
    $_SESSION['auth_oauth_flow'] = $flow;
    return ['authorizationUrl' => $url . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986)];
}

/** Consume before any exchange: a callback, including a denied callback, is single use. */
function pa_auth_oauth_consume_state(string $provider, mixed $state): array
{
    $flow = $_SESSION['auth_oauth_flow'] ?? null;
    unset($_SESSION['auth_oauth_flow']);
    if (
        !is_array($flow) || !is_string($state) || strlen($state) > 128
        || !isset($flow['state'], $flow['provider'], $flow['expiresAt'])
        || !is_string($flow['state']) || !hash_equals($flow['state'], $state)
        || $flow['provider'] !== $provider || $flow['expiresAt'] <= time()
    ) {
        pa_auth_fail(400, 'oauth_expired', 'Este acesso expirou. Escolha novamente uma opção para entrar.');
    }
    return $flow;
}

/**
 * Internal transport seam for fixture tests. Production passes no callable.
 * Only fixed Google endpoints may receive server-side credentials.
 */
function pa_auth_oauth_http(string $url, array $options = [], ?callable $transport = null): array
{
    $parts = parse_url($url);
    $host = $parts['host'] ?? '';
    $path = $parts['path'] ?? '';
    $valid = ($parts['scheme'] ?? '') === 'https' && !isset($parts['user']) && !isset($parts['pass'])
        && !isset($parts['port']) && !isset($parts['fragment']);
    $valid = $valid && (
        ($host === 'oauth2.googleapis.com' && $path === '/token')
        || ($host === 'www.googleapis.com' && $path === '/oauth2/v3/certs')
    );
    if (!$valid) {
        throw new LogicException('OAuth endpoint is not allowlisted.');
    }
    if ($transport !== null) {
        return $transport($url, $options);
    }
    $body = '';
    $handle = curl_init($url);
    $headers = array_merge(['Accept: application/json'], $options['headers'] ?? []);
    $curlOptions = [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 1048576) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ];
    if (defined('CURLOPT_PROTOCOLS_STR')) {
        $curlOptions[CURLOPT_PROTOCOLS_STR] = 'https';
    }
    if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
        // PHP's Windows build may have no CA bundle configured. Still verify TLS
        // against Windows' trusted roots, in addition to any configured CA file.
        $curlOptions[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
    }
    if (isset($options['form'])) {
        $curlOptions[CURLOPT_POST] = true;
        $curlOptions[CURLOPT_POSTFIELDS] = http_build_query($options['form'], '', '&', PHP_QUERY_RFC3986);
    }
    curl_setopt_array($handle, $curlOptions);
    $success = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    // Do not emit cURL's URL, response body, or remote error text; they can contain tokens.
    if ($success === false || $status === 429 || $status >= 500) {
        pa_auth_fail(503, 'provider_unavailable', 'O serviço de acesso está indisponível. Tente novamente em alguns instantes.');
    }
    try {
        $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $data = null;
    }
    if ($status !== 200 || !is_array($data) || isset($data['error'])) {
        pa_auth_fail(400, 'oauth_failed', 'Não foi possível confirmar seu acesso. Tente novamente.');
    }
    return $data;
}

/** Verify Google's signature with Google's public RSA keys, then OIDC claims. */
function pa_auth_oauth_google_claims(string $token, string $nonce, string $clientId, array $jwks): array
{
    try {
        if ($token === '' || strlen($token) > 16384) {
            throw new UnexpectedValueException('Invalid token size.');
        }
        $jwks['keys'] = array_values(array_filter(
            $jwks['keys'] ?? [],
            static fn($key): bool =>
            is_array($key) && ($key['kty'] ?? '') === 'RSA' && ($key['alg'] ?? 'RS256') === 'RS256'
                && (!isset($key['use']) || $key['use'] === 'sig')
        ));
        $keys = \Firebase\JWT\JWK::parseKeySet($jwks, 'RS256');
        $claims = (array) \Firebase\JWT\JWT::decode($token, $keys);
        $audiences = is_string($claims['aud'] ?? null) ? [$claims['aud']] : ($claims['aud'] ?? []);
        if (
            !is_array($audiences) || !in_array($clientId, $audiences, true)
            || (count($audiences) > 1 && ($claims['azp'] ?? null) !== $clientId)
            || (isset($claims['azp']) && $claims['azp'] !== $clientId)
            || !in_array($claims['iss'] ?? null, ['https://accounts.google.com', 'accounts.google.com'], true)
            || !is_int($claims['exp'] ?? null) || $claims['exp'] <= time()
            || !is_int($claims['iat'] ?? null) || $claims['iat'] > time() + 30
            || !is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce'])
            || !is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 255
        ) {
            throw new UnexpectedValueException('Invalid identity claims.');
        }
    } catch (Throwable) {
        pa_auth_fail(400, 'oauth_failed', 'Não foi possível validar sua conta Google. Tente novamente.');
    }
    if (($claims['email_verified'] ?? false) !== true) {
        pa_auth_fail(400, 'email_not_verified', 'Confirme seu e-mail na conta Google antes de continuar.');
    }
    return $claims;
}

function pa_auth_oauth_google(string $code, array $flow, ?callable $transport = null): array
{
    $config = pa_auth_config();
    $tokens = pa_auth_oauth_http('https://oauth2.googleapis.com/token', ['form' => [
        'code' => $code,
        'client_id' => $config['google_client_id'],
        'client_secret' => $config['google_client_secret'],
        'redirect_uri' => pa_auth_oauth_callback_url('google'),
        'grant_type' => 'authorization_code',
        'code_verifier' => $flow['verifier'],
    ]], $transport);
    if (!is_string($tokens['id_token'] ?? null)) {
        pa_auth_fail(400, 'oauth_failed', 'Não foi possível confirmar sua conta Google.');
    }
    $jwks = pa_auth_oauth_http('https://www.googleapis.com/oauth2/v3/certs', [], $transport);
    $claims = pa_auth_oauth_google_claims($tokens['id_token'], $flow['nonce'], $config['google_client_id'], $jwks);
    return ['subject' => $claims['sub'], 'email' => $claims['email'] ?? null, 'name' => $claims['name'] ?? ''];
}

function pa_auth_oauth_suggested_username(mixed $name, string $email): string
{
    $name = is_string($name) ? $name : '';
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $name = $converted === false ? '' : $converted;
    }
    $name = preg_replace('/[^A-Za-z0-9_]/', '', $name) ?? '';
    if (strlen($name) < 3) {
        $name = preg_replace('/[^A-Za-z0-9_]/', '', explode('@', $email)[0]) ?? '';
    }
    $name = substr($name, 0, 30);
    return strlen($name) >= 3 && !in_array(strtolower($name), ['admin', 'root', 'administrador', 'administrator', 'suporte', 'support'], true) ? $name : '';
}

/** Existing provider IDs remain stable even when the provider's email changes. */
function pa_auth_oauth_accept_identity(string $provider, array $identity): void
{
    pa_auth_oauth_provider($provider);
    $existing = pa_auth_identity_user($provider, $identity['subject']);
    if ($existing !== null) {
        unset($_SESSION['auth_oauth_pending']);
        pa_auth_login_user($existing['id']);
        return;
    }
    if (!is_string($identity['email'] ?? null) || $identity['email'] === '') {
        pa_auth_fail(400, 'email_required', 'Essa conta não compartilhou um e-mail. Crie sua conta usando a opção de e-mail.');
    }
    try {
        $email = pa_auth_email($identity['email']);
    } catch (PaAuthError) {
        pa_auth_fail(400, 'email_required', 'Essa conta não compartilhou um e-mail válido. Crie sua conta usando e-mail.');
    }
    if (pa_auth_user_by_email($email) !== null) {
        pa_auth_fail(409, 'account_exists', 'Já existe uma conta com esse e-mail. Entre usando o método do seu cadastro original.');
    }
    $_SESSION['auth_oauth_pending'] = [
        'provider' => $provider,
        'subject' => $identity['subject'],
        'email' => $email,
        'suggestedUsername' => pa_auth_oauth_suggested_username($identity['name'] ?? '', $email),
        'expiresAt' => time() + PA_AUTH_OAUTH_TTL,
    ];
}

function pa_auth_oauth_finish(string $provider, array $query, ?callable $transport = null): void
{
    pa_auth_require_guest();
    $flow = pa_auth_oauth_consume_state($provider, $query['state'] ?? null);
    pa_auth_oauth_provider($provider);
    if (isset($query['error'])) {
        pa_auth_fail(400, $query['error'] === 'access_denied' ? 'oauth_cancelled' : 'oauth_failed', 'O acesso não foi concluído. Você pode tentar novamente.');
    }
    $code = $query['code'] ?? null;
    if (!is_string($code) || $code === '' || strlen($code) > 8192) {
        pa_auth_fail(400, 'oauth_failed', 'O serviço não retornou um código de acesso válido.');
    }
    $identity = pa_auth_oauth_google($code, $flow, $transport);
    pa_auth_oauth_accept_identity($provider, $identity);
}

function pa_auth_oauth_callback(string $provider): never
{
    $error = null;
    try {
        pa_auth_oauth_finish($provider, $_GET);
    } catch (PaAuthError $exception) {
        $allowed = ['oauth_expired', 'oauth_cancelled', 'oauth_failed', 'provider_unavailable', 'email_not_verified', 'email_required', 'account_exists', 'already_authenticated'];
        $error = in_array($exception->errorCode, $allowed, true) ? $exception->errorCode : 'oauth_failed';
    } catch (Throwable) {
        $error = 'oauth_failed';
    }
    $url = rtrim(pa_auth_config()['base_url'], '/') . '/conta.html';
    if ($error !== null) {
        $url .= '?auth_error=' . rawurlencode($error);
    }
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('Location: ' . $url, true, 303);
    exit;
}

function pa_auth_oauth_pending_public(): ?array
{
    $pending = $_SESSION['auth_oauth_pending'] ?? null;
    if (!is_array($pending) || ($pending['provider'] ?? null) !== 'google' || !isset($pending['expiresAt']) || $pending['expiresAt'] <= time()) {
        unset($_SESSION['auth_oauth_pending']);
        return null;
    }
    return ['provider' => $pending['provider'], 'email' => $pending['email'], 'suggestedUsername' => $pending['suggestedUsername']];
}

function pa_auth_oauth_complete(string $username): array
{
    pa_auth_require_guest();
    if (pa_auth_oauth_pending_public() === null) {
        pa_auth_fail(400, 'oauth_expired', 'Seu cadastro expirou. Escolha novamente uma opção para entrar.');
    }
    $pending = $_SESSION['auth_oauth_pending'];
    $username = pa_auth_username($username);
    try {
        $user = pa_auth_create_user($username, $pending['email'], null, ['provider' => $pending['provider'], 'subject' => $pending['subject']]);
    } catch (PaAuthError $exception) {
        if (in_array($exception->errorCode, ['email_taken', 'identity_exists', 'account_exists'], true)) {
            unset($_SESSION['auth_oauth_pending']);
            pa_auth_fail(409, 'account_exists', 'Já existe uma conta com esses dados. Entre usando o método do seu cadastro original.');
        }
        throw $exception;
    }
    unset($_SESSION['auth_oauth_pending']);
    return pa_auth_login_user($user['id']);
}

function pa_auth_oauth_cancel(): void
{
    unset($_SESSION['auth_oauth_flow'], $_SESSION['auth_oauth_pending']);
}
