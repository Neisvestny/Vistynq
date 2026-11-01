#!/usr/bin/env php
<?php

declare(strict_types=1);

// Отдельный процесс для проверки конкурентной ротации refresh-токена.
// Получает исходный токен и путь к файлу для записи результата.
// Выполняет ротацию через отдельное подключение к БД и сохраняет
// результат выполнения в формате JSON.

use Vistynq\Config\Config;
use Vistynq\Config\EnvLoader;
use Vistynq\Repositories\RefreshTokenRepository;
use Vistynq\Repositories\UserRepository;
use Vistynq\Services\JwtService;
use Vistynq\Services\RefreshTokenService;
use Vistynq\Support\ApiException;
use Vistynq\Support\Database;

$baseDir = __DIR__ . '/../..';
require $baseDir . '/vendor/autoload.php';

EnvLoader::load($baseDir . '/.env');

$rawToken = $argv[1] ?? '';
$resultFile = $argv[2] ?? '';

if ($rawToken === '' || $resultFile === '') {
    fwrite(STDERR, "Usage: php RefreshTokenRotationWorker.php <raw_token> <result_file>\n");
    exit(2);
}

try {
    $config = new Config();
    $db = new Database($config);
    $refreshTokens = new RefreshTokenRepository($db);
    $users = new UserRepository($db);
    $jwt = new JwtService($config);
    $service = new RefreshTokenService($config, $refreshTokens, $users, $jwt, $db);

    $service->rotate($rawToken);
    file_put_contents($resultFile, json_encode(['success' => true]));
} catch (ApiException $e) {
    file_put_contents($resultFile, json_encode([
        'success' => false,
        'code' => $e->httpStatus(),
        'code_name' => $e->errorCode(),
    ]));
} catch (Throwable $e) {
    file_put_contents($resultFile, json_encode([
        'success' => false,
        'code' => 500,
        'message' => $e->getMessage(),
    ]));
}
