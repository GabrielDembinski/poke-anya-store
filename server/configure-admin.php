<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', '0');
require_once __DIR__ . '/admin-auth.php';
$bufferLevel = ob_get_level();
ob_start();
$status = 0;
set_error_handler(static function (): never {
    throw new RuntimeException('Não foi possível configurar o administrador. Nenhuma conta existente foi substituída.');
});
try {
    if ($argc !== 2 || $argv[1] !== '--stdin-json') throw new RuntimeException('Use CONFIGURAR-ADMIN.bat para criar o acesso administrativo.');
    $raw = stream_get_contents(STDIN, 8193);
    if ($raw === false || strlen($raw) > 8192) throw new RuntimeException('Os dados de configuração excedem o tamanho permitido.');
    // Windows PowerShell may prefix redirected UTF-8 input with a byte-order mark.
    if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
    try {
        $input = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('Não foi possível ler a configuração administrativa.');
    }
    if (!$input instanceof stdClass) throw new RuntimeException('Não foi possível ler a configuração administrativa.');
    pa_admin_setup(get_object_vars($input));
    fwrite(STDOUT, 'Administrador único configurado. Abra INICIAR-LOJA.bat e acesse admin.html.' . PHP_EOL);
} catch (PaAuthError $error) {
    $safeErrors = [
        'invalid_name' => 'O nome do administrador deve ter de 2 a 80 caracteres.',
        'invalid_email' => 'Informe um e-mail válido.',
        'invalid_password' => 'Escolha uma senha administrativa de 12 a 128 caracteres.',
        'password_mismatch' => 'As senhas precisam ser iguais.',
        'admin_already_configured' => 'Já existe um administrador configurado. A conta existente foi preservada.',
    ];
    fwrite(STDERR, ($safeErrors[$error->errorCode] ?? 'Não foi possível configurar o administrador. Nenhuma conta existente foi substituída.') . PHP_EOL);
    $status = 1;
} catch (Throwable $error) {
    $safeMessages = ['Use CONFIGURAR-ADMIN.bat para criar o acesso administrativo.', 'Os dados de configuração excedem o tamanho permitido.', 'Não foi possível ler a configuração administrativa.'];
    $message = get_class($error) === RuntimeException::class && in_array($error->getMessage(), $safeMessages, true) ? $error->getMessage() : 'Não foi possível configurar o administrador. Nenhuma conta existente foi substituída.';
    fwrite(STDERR, $message . PHP_EOL);
    $status = 1;
} finally {
    restore_error_handler();
    while (ob_get_level() > $bufferLevel) ob_end_clean();
}
exit($status);
