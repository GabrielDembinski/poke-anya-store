<?php

declare(strict_types=1);
/* Owner-only maintenance. No HTTP setup, credentials or database downloads. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', '0');
if (!getenv('POKEANYA_AUTH_CONFIG') && getenv('LOCALAPPDATA')) {
    $defaultConfig = getenv('LOCALAPPDATA') . '/PokeAnyaStore/auth.config.php';
    if (is_file($defaultConfig)) putenv('POKEANYA_AUTH_CONFIG=' . $defaultConfig);
}
require_once __DIR__ . '/store-core.php';
require_once __DIR__ . '/admin-auth.php';
try {
    $command = $argv[1] ?? 'status';
    if (!in_array($command, ['init', 'status', 'backup'], true)) throw new RuntimeException('Use init, status ou backup.');
    $directory = pa_auth_config()['data_dir'];
    $files = ['customers.sqlite', 'admins.sqlite', 'commerce.sqlite'];
    if ($command === 'init') {
        pa_auth_private_directory($directory);
        pa_auth_db();
        pa_admin_db();
        pa_store_db();
        fwrite(STDOUT, "Bancos inicializados. Contas e registros existentes foram preservados.\n");
    } elseif ($command === 'status') {
        $status = [];
        foreach ($files as $file) {
            $path = $directory . '/' . $file;
            $status[$file] = is_file($path) ? 'presente' : 'ainda não criado';
        }
        fwrite(STDOUT, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
    } else {
        $backup = $directory . '/backups/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        pa_auth_private_directory($backup);
        $manifest = ['createdAt' => gmdate('c'), 'files' => []];
        foreach ($files as $file) {
            $source = $directory . '/' . $file;
            if (!is_file($source)) continue;
            $pdo = new PDO('sqlite:' . $source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA busy_timeout=10000');
            $target = $backup . '/' . $file;
            $pdo->exec('VACUUM INTO ' . $pdo->quote($target));
            $check = new PDO('sqlite:' . $target, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if ($check->query('PRAGMA quick_check')->fetchColumn() !== 'ok') throw new RuntimeException('Falha na conferência do backup.');
            $check = null;
            $pdo = null;
            @chmod($target, 0600);
            $manifest['files'][$file] = hash_file('sha256', $target);
        }
        if (!$manifest['files']) throw new RuntimeException('Ainda não existem bancos para exportar.');
        file_put_contents($backup . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
        @chmod($backup . '/manifest.json', 0600);
        fwrite(STDOUT, "Backup privado concluído e conferido: " . $backup . PHP_EOL);
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Não foi possível concluir a manutenção. Confira o comando, as extensões PHP e a pasta privada configurada.\n");
    exit(1);
}
