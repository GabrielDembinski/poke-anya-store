<?php

declare(strict_types=1);

/* Local CLI onboarding only. Credentials are read from Google's downloaded JSON,
   never from command-line arguments, HTTP input, or console output. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/auth-core.php';

const PA_SETUP_GOOGLE_ORIGIN = 'http://127.0.0.1:8000';
const PA_SETUP_GOOGLE_CALLBACK = PA_SETUP_GOOGLE_ORIGIN . '/api/auth/oauth/google/callback';

function pa_setup_google_stop(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function pa_setup_google_absolute(string $path): bool
{
    return str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
}

function pa_setup_google_values(mixed $value, int $depth = 0): bool
{
    if ($depth > 8) return false;
    if (is_array($value)) {
        if (count($value) > 128) return false;
        foreach ($value as $item) if (!pa_setup_google_values($item, $depth + 1)) return false;
        return true;
    }
    return $value === null || (is_scalar($value) && (!is_float($value) || is_finite($value)));
}

function pa_setup_google_private_config(string $path, string $project): array
{
    if (!pa_setup_google_absolute($path) || str_contains($path, "\0") || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
        throw new RuntimeException('Defina POKEANYA_AUTH_CONFIG como um caminho absoluto para um arquivo PHP privado.');
    }
    $resolved = pa_auth_resolved_destination($path);
    if (pa_auth_inside($resolved, $project)) {
        throw new RuntimeException('A configuração de contas deve ficar fora da pasta pública do projeto.');
    }
    if (file_exists($resolved) && !is_file($resolved)) {
        throw new RuntimeException('O destino da configuração privada não é um arquivo.');
    }
    $config = [];
    $hash = null;
    if (is_file($resolved)) {
        if (!is_readable($resolved) || filesize($resolved) > 65536) {
            throw new RuntimeException('Não foi possível ler a configuração privada existente.');
        }
        $hash = @hash_file('sha256', $resolved);
        if ($hash === false) throw new RuntimeException('Não foi possível conferir a configuração privada existente.');
        $level = ob_get_level();
        ob_start();
        set_error_handler(static function (): never {
            throw new RuntimeException('A configuração privada existente precisa ser corrigida antes da importação.');
        });
        try {
            $config = require $resolved;
        } catch (Throwable) {
            throw new RuntimeException('A configuração privada existente precisa ser corrigida antes da importação.');
        } finally {
            restore_error_handler();
            while (ob_get_level() > $level) ob_end_clean();
        }
        if (!is_array($config) || !pa_setup_google_values($config)) {
            throw new RuntimeException('A configuração privada existente deve retornar um array de valores simples.');
        }
        foreach (['app_env', 'base_url', 'data_dir', 'google_client_id', 'google_client_secret'] as $key) {
            if (array_key_exists($key, $config) && !is_string($config[$key])) {
                throw new RuntimeException('A configuração privada existente contém um campo de contas inválido.');
            }
        }
    }
    $base = $config['base_url'] ?? PA_SETUP_GOOGLE_ORIGIN;
    $environment = $config['app_env'] ?? 'development';
    $baseEnvironment = getenv('POKEANYA_BASE_URL');
    $appEnvironment = getenv('POKEANYA_APP_ENV');
    if (
        !is_string($base) || !is_string($environment)
        || ($base !== '' && rtrim(trim($base), '/') !== PA_SETUP_GOOGLE_ORIGIN)
        || strtolower(trim($environment)) === 'production'
        || ($baseEnvironment !== false && rtrim(trim($baseEnvironment), '/') !== PA_SETUP_GOOGLE_ORIGIN)
        || ($appEnvironment !== false && strtolower(trim($appEnvironment)) === 'production')
    ) {
        throw new RuntimeException('Este importador é para testes em http://127.0.0.1:8000. A configuração de outro ambiente foi preservada; use uma configuração privada separada para os testes locais.');
    }
    return [$resolved, $config, $hash];
}

function pa_setup_google_save(string $path, array $config, ?string $originalHash, string $project): void
{
    $parent = dirname($path);
    if (!is_dir($parent) && !@mkdir($parent, 0700, true) && !is_dir($parent)) {
        throw new RuntimeException('Não foi possível criar a pasta privada de configuração.');
    }
    // Re-resolve after creating the parent, including Windows junctions/symlinks.
    $resolvedParent = realpath($parent);
    if ($resolvedParent === false || pa_auth_inside($resolvedParent, $project) || !is_writable($resolvedParent)) {
        throw new RuntimeException('A pasta privada de configuração não está disponível.');
    }
    $path = $resolvedParent . DIRECTORY_SEPARATOR . basename($path);
    if (is_link($path) || pa_auth_inside(pa_auth_resolved_destination($path), $project)) {
        throw new RuntimeException('O destino privado foi alterado. A importação foi cancelada.');
    }
    $temporary = @tempnam($resolvedParent, '.pokeanya-google-');
    if ($temporary === false) throw new RuntimeException('Não foi possível preparar a configuração privada.');
    try {
        @chmod($temporary, 0600);
        $content = "<?php\n/* Private PokeAnya Store configuration. Keep outside the public site. */\nreturn " . var_export($config, true) . ";\n";
        if (@file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)) {
            throw new RuntimeException('Não foi possível salvar a configuração privada.');
        }
        clearstatcache(true, $path);
        $currentHash = is_file($path) ? @hash_file('sha256', $path) : null;
        if ($currentHash !== $originalHash) {
            throw new RuntimeException('A configuração mudou durante a importação. Nenhuma alteração foi aplicada; tente novamente.');
        }
        if (!@rename($temporary, $path)) {
            throw new RuntimeException('Não foi possível substituir a configuração privada. O arquivo anterior foi preservado.');
        }
        @chmod($path, 0600);
    } finally {
        if (is_file($temporary)) @unlink($temporary);
    }
}

try {
    umask(0077);
    $project = (string) realpath(__DIR__ . '/..');
    $destination = getenv('POKEANYA_AUTH_CONFIG');
    if ($destination === false || trim($destination) === '') {
        $local = getenv('LOCALAPPDATA');
        if ($local === false || trim($local) === '') {
            throw new RuntimeException('Defina POKEANYA_AUTH_CONFIG como um arquivo PHP privado fora da pasta do projeto.');
        }
        $destination = rtrim($local, '/\\') . '/PokeAnyaStore/auth.config.php';
    }
    [$target, $config, $originalHash] = pa_setup_google_private_config(trim($destination), $project);
    fwrite(STDOUT, 'Configuração Google para testes locais em ' . PA_SETUP_GOOGLE_ORIGIN . PHP_EOL);
    fwrite(STDOUT, 'Informe o caminho do JSON de credenciais Web baixado do Google Cloud (Enter cancela): ');
    $input = fgets(STDIN, 4098);
    if ($input === false || trim($input) === '') {
        fwrite(STDOUT, PHP_EOL . 'Importação cancelada. Nenhuma credencial foi alterada.' . PHP_EOL);
        exit(0);
    }
    $input = trim($input);
    if (strlen($input) > 4096) throw new RuntimeException('O caminho informado é muito longo.');
    if (strlen($input) >= 2 && (($input[0] === '"' && str_ends_with($input, '"')) || ($input[0] === "'" && str_ends_with($input, "'")))) {
        $input = substr($input, 1, -1);
    }
    $source = realpath($input);
    if ($source === false || !is_file($source) || !is_readable($source)) {
        throw new RuntimeException('Não foi possível ler o arquivo JSON informado. Confira o caminho.');
    }
    if (pa_auth_inside($source, $project)) {
        throw new RuntimeException('Guarde o JSON de credenciais fora da pasta pública do projeto e tente novamente.');
    }
    if (filesize($source) > 65536) throw new RuntimeException('O arquivo excede o tamanho esperado para credenciais Google.');
    $json = @file_get_contents($source, false, null, 0, 65537);
    if ($json === false || strlen($json) > 65536) throw new RuntimeException('Não foi possível ler um arquivo de credenciais válido.');
    try {
        $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('O arquivo informado não contém um JSON de credenciais válido.');
    }
    $web = is_array($decoded) ? ($decoded['web'] ?? null) : null;
    if (!is_array($web)) throw new RuntimeException('Crie no Google Cloud um cliente OAuth do tipo Aplicativo da Web e baixe seu JSON.');
    $clientId = $web['client_id'] ?? null;
    $secret = $web['client_secret'] ?? null;
    if (
        !is_string($clientId) || !preg_match('/\A[A-Za-z0-9_-]{1,256}\.apps\.googleusercontent\.com\z/', $clientId)
        || !is_string($secret) || !preg_match('/\A[\x21-\x7e]{8,2048}\z/', $secret)
    ) {
        throw new RuntimeException('O JSON não contém ID e segredo válidos de um cliente OAuth Web. Baixe novamente as credenciais.');
    }
    if (!is_array($web['redirect_uris'] ?? null) || !in_array(PA_SETUP_GOOGLE_CALLBACK, $web['redirect_uris'], true)) {
        throw new RuntimeException('Cadastre no cliente Google a URI de redirecionamento exata ' . PA_SETUP_GOOGLE_CALLBACK . ' e baixe o JSON atualizado.');
    }
    $config['google_client_id'] = $clientId;
    $config['google_client_secret'] = $secret;
    pa_setup_google_save($target, $config, $originalHash, $project);
    fwrite(STDOUT, PHP_EOL . 'Credenciais Google importadas para a configuração privada.' . PHP_EOL);
    fwrite(STDOUT, 'Reinicie INICIAR-LOJA.bat e teste Entrar com Google na página de conta.' . PHP_EOL);
    fwrite(STDOUT, 'O teste real depende do cadastro e das permissões configurados no Google Cloud.' . PHP_EOL);
} catch (Throwable $error) {
    // Only our fixed validation messages may reach the terminal; never print PHP,
    // JSON parse details, provider credentials, or an untrusted exception message.
    if ($error instanceof RuntimeException && get_class($error) === RuntimeException::class) {
        pa_setup_google_stop($error->getMessage());
    }
    pa_setup_google_stop('Não foi possível concluir a importação. A configuração anterior foi preservada.');
}
