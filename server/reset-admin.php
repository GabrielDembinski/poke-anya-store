<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
$buffer = ob_get_level(); ob_start();
set_error_handler(static function (): never { throw new RuntimeException('Configuration unavailable'); });
$status = 1; $message = 'Não foi possível recuperar o acesso. Nenhuma conta foi substituída.';
try {
    if (($argv[1] ?? '') !== '--stdin-json') throw new RuntimeException('Use RECUPERAR-ADMIN.bat.');
    $raw = stream_get_contents(STDIN, 8193);
    if (!is_string($raw) || strlen($raw) > 8192) throw new RuntimeException('Invalid setup');
    // Accept the UTF-8 preamble emitted by older Windows launchers.
    if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
    $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input) || ($input['confirmation'] ?? '') !== 'REDEFINIR') throw new RuntimeException('Confirmation required');
    if (!getenv('POKEANYA_AUTH_CONFIG') && getenv('LOCALAPPDATA')) { $default = getenv('LOCALAPPDATA') . '/PokeAnyaStore/auth.config.php'; if (is_file($default)) putenv('POKEANYA_AUTH_CONFIG=' . $default); }
    require_once __DIR__ . '/password-core.php';
    $record = pa_admin_record();
    $email = pa_auth_email($input['email'] ?? null);
    if (!$record || !hash_equals($record['email'], $email)) pa_auth_fail(400, 'admin_mismatch', 'Confira o e-mail do administrador existente.');
    $password = pa_admin_password($input['password'] ?? null);
    if (!is_string($input['passwordConfirmation'] ?? null) || !hash_equals($password, $input['passwordConfirmation'])) pa_auth_fail(400, 'password_mismatch', 'As senhas precisam ser iguais.');
    pa_admin_replace_password($record, $password);
    $status = 0; $message = 'Senha administrativa redefinida. Entre novamente no painel com a nova senha.';
} catch (Throwable $error) {
    if ($error instanceof PaAuthError) $message = match ($error->errorCode) { 'admin_mismatch' => 'Confira o e-mail do administrador existente.', 'invalid_password' => 'Escolha uma senha de 12 a 128 caracteres.', 'password_mismatch' => 'As senhas precisam ser iguais.', default => $message };
} finally { restore_error_handler(); while (ob_get_level() > $buffer) ob_end_clean(); }
fwrite($status === 0 ? STDOUT : STDERR, $message . PHP_EOL);
exit($status);
