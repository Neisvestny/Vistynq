#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Кросс-платформенный dev-сервер backend (Windows/Linux/macOS).
 *
 * Замена POSIX-скрипта bin/serve: вызывается как `php bin/serve.php [port]`
 * и работает из package.json ("dev": "php bin/serve.php") на любой ОС.
 *
 * Если pdo_pgsql не загружен, скрипт один раз перезапускает сам себя с нужными
 * -d-флагами (на Windows дополнительно подставляет extension_dir рядом с php.exe,
 * т.к. winget-билд PHP часто запускается вообще без php.ini).
 */

$baseDir = dirname(__DIR__);

$port = '8000';
foreach (array_slice($argv, 1) as $arg) {
    if (ctype_digit($arg)) {
        $port = $arg;
        break;
    }
}

$pgsqlLoaded = in_array('pgsql', PDO::getAvailableDrivers(), true);
$alreadyRetried = in_array('--load-pgsql', $argv, true);

if (!$pgsqlLoaded && $alreadyRetried) {
    fwrite(STDERR, "Ошибка: расширение pdo_pgsql не загрузилось даже с флагами.\n");
    fwrite(STDERR, "Проверьте PHP-окружение: `php -m` должно содержать pdo_pgsql (см. docs/architecture.md, раздел «Настройка PHP»).\n");

    exit(1);
}

if (!$pgsqlLoaded) {
    /** @var list<string> $flags */
    $flags = [];

    if (PHP_OS_FAMILY === 'Windows') {
        $extDir = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'ext';
        if (is_dir($extDir)) {
            $flags[] = '-d';
            $flags[] = 'extension_dir=' . $extDir;
        }
    }

    $flags[] = '-d';
    $flags[] = 'extension=pdo_pgsql';

    $cmd = escapeshellarg(PHP_BINARY);
    foreach ($flags as $flag) {
        $cmd .= ' ' . escapeshellarg($flag);
    }
    $cmd .= ' ' . escapeshellarg(__FILE__) . ' --load-pgsql ' . escapeshellarg($port);

    chdir($baseDir);
    passthru($cmd, $exitCode);

    exit($exitCode);
}

// public/index.php doubling as router script: every path hits the front controller.
$cmd = escapeshellarg(PHP_BINARY)
    . ' -S ' . escapeshellarg('127.0.0.1:' . $port)
    . ' -t ' . escapeshellarg($baseDir . '/public')
    . ' ' . escapeshellarg($baseDir . '/public/index.php');

chdir($baseDir);
passthru($cmd, $exitCode);

exit($exitCode);
