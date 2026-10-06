<?php

declare(strict_types=1);

/**
 * Заполнение незаданных переменных из apps/backend/.env (нужно только для
 * запуска на хосте: `php -S ... -t public`). Реальное окружение всегда
 * важнее файла — docker-compose передаёт переменные явно.
 * R03 заменит это на полноценный Config.
 */
loadEnvFile(dirname(__DIR__) . '/.env');

header('Content-Type: application/json; charset=utf-8');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = rtrim(is_string($path) ? $path : '/', '/') ?: '/';

if ($path === '/healthz' || $path === '/api/v1/healthz') {
    $reason = databaseFailureReason();
    if ($reason === null) {
        http_response_code(200);
        echo json_encode(['status' => 'ok'], JSON_UNESCAPED_UNICODE);

        exit;
    }

    http_response_code(503);
    echo json_encode(['status' => 'unavailable', 'error' => 'database', 'reason' => $reason], JSON_UNESCAPED_UNICODE);

    exit;
}

http_response_code(404);
echo json_encode(
    ['error' => ['code' => 'NOT_FOUND', 'message' => 'Not Found']],
    JSON_UNESCAPED_UNICODE
);

/**
 * Cheap dependency probe: one round-trip query against PostgreSQL.
 * Returns null when healthy, otherwise a stable machine-readable reason
 * (exception details go to the log, never to the response body).
 */
function databaseFailureReason(): ?string
{
    if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
        error_log('[healthz] pdo_pgsql extension is not loaded');

        return 'pdo_pgsql_missing';
    }

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '5432';
    $name = getenv('DB_NAME') ?: 'vistynq';
    $user = getenv('DB_USER') ?: 'vistynq';
    $pass = getenv('DB_PASS');

    try {
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name),
            $user,
            $pass === false ? '' : $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 2,
            ]
        );
        $pdo->query('SELECT 1');
    } catch (Throwable $e) {
        error_log('[healthz] database check failed: ' . $e->getMessage());

        return 'database_unreachable';
    }

    return null;
}

/**
 * Мини-парсер .env (KEY=VALUE, #, кавычки) для запуска на хосте.
 * Уже заданные переменные окружения не перезаписываются — docker-compose
 * передаёт их явно, и файл в контейнере роли не играет.
 */
function loadEnvFile(string $file): void
{
    if (!is_readable($file)) {
        return;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $name = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));

        if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            continue;
        }

        if (getenv($name) !== false) {
            continue;
        }

        $len = strlen($value);
        if ($len >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[$len - 1] === $value[0]) {
            $value = substr($value, 1, -1);
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}
