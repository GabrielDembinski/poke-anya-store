<?php
declare(strict_types=1);
require_once __DIR__ . '/admin-auth.php';

/* Password management is administrative only. There is no external mail service. */
function pa_admin_replace_password(array $record, string $password): void {
    $hash = password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
    $db = pa_admin_db();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $update = $db->prepare('UPDATE administrator SET password_hash=?,password_version=password_version+1,updated_at=? WHERE id=1 AND password_version=? AND password_hash=?');
        $update->execute([$hash, gmdate('Y-m-d\TH:i:s\Z'), (int) $record['password_version'], $record['password_hash']]);
        if ($update->rowCount() !== 1) pa_auth_fail(409, 'password_changed', 'A senha foi atualizada em outra sessão. Entre novamente para continuar.');
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        try { $db->exec('ROLLBACK'); } catch (Throwable) {}
        throw $error;
    }
}
function pa_admin_change_password(array $input): array {
    pa_admin_require();
    pa_admin_limit('password-change', $_SERVER['REMOTE_ADDR'] ?? 'unknown', 10);
    $record = pa_admin_record();
    $current = $input['currentPassword'] ?? null;
    if (!is_string($current) || strlen($current) > 1024 || !password_verify($current, $record['password_hash'])) pa_auth_fail(400, 'invalid_current_password', 'A senha atual não está correta.');
    $password = pa_admin_password($input['password'] ?? null);
    if (!is_string($input['passwordConfirmation'] ?? null) || !hash_equals($password, $input['passwordConfirmation'])) pa_auth_fail(400, 'password_mismatch', 'As senhas precisam ser iguais.');
    if (hash_equals($current, $password)) pa_auth_fail(400, 'password_unchanged', 'Escolha uma senha diferente da senha atual.');
    pa_admin_replace_password($record, $password);
    pa_admin_login();
    return pa_admin_envelope() + ['message' => 'Senha atualizada. As outras sessões administrativas foram encerradas.'];
}
