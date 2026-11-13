<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Vistynq\Config\Config;

final class ConfigTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (['APP_ENV', 'APP_URL', 'FRONTEND_URL', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'JWT_SECRET', 'ACCESS_TTL', 'REFRESH_TTL', 'TRUSTED_PROXIES', 'COOKIE_SECURE', 'CORS_ORIGINS', 'SMTP_HOST', 'SMTP_PORT'] as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("$key=$value");
            }
        }
    }

    public function testDefaults(): void
    {
        $config = new Config();
        $this->assertSame('dev', $config->appEnv());
        $this->assertTrue($config->isDev());
        $this->assertFalse($config->isProd());
        $this->assertSame('http://localhost:8000', $config->appUrl());
        $this->assertSame('http://localhost:5173', $config->frontendUrl());
        $this->assertSame('127.0.0.1', $config->dbHost());
        $this->assertSame('5432', $config->dbPort());
        $this->assertSame('vistynq', $config->dbName());
        $this->assertSame('vistynq', $config->dbUser());
        $this->assertSame('', $config->dbPass());
        $this->assertSame('pgsql:host=127.0.0.1;port=5432;dbname=vistynq', $config->dbDsn());
        $this->assertSame('dev_only_change_me', $config->jwtSecret());
        $this->assertSame(900, $config->accessTtl());
        $this->assertSame(2592000, $config->refreshTtl());
        $this->assertSame('http://minio:9000', $config->s3Endpoint());
        $this->assertSame('us-east-1', $config->s3Region());
        $this->assertSame('vistynq-media', $config->s3Bucket());
        $this->assertSame('', $config->s3AccessKey());
        $this->assertSame('', $config->s3SecretKey());
        $this->assertTrue($config->s3UsePathStyle());
        $this->assertSame('mailpit', $config->smtpHost());
        $this->assertSame(1025, $config->smtpPort());
        $this->assertSame('', $config->smtpUser());
        $this->assertSame('', $config->smtpPass());
        $this->assertSame('no-reply@vistynq.local', $config->mailFrom());
        $this->assertSame('', $config->cookieDomain());
        $this->assertFalse($config->cookieSecure());
        $this->assertSame([], $config->trustedProxies());
    }

    public function testEnvOverrides(): void
    {
        putenv('APP_ENV=prod');
        putenv('APP_URL=https://api.example.com');
        putenv('DB_HOST=db.internal');
        putenv('DB_PORT=5433');
        putenv('DB_NAME=testdb');
        putenv('JWT_SECRET=ci-secret-key-that-is-long-enough-for-hs256');
        putenv('ACCESS_TTL=300');
        putenv('TRUSTED_PROXIES=10.0.0.1,10.0.0.2');
        putenv('COOKIE_SECURE=true');

        $_ENV['APP_ENV'] = 'prod';
        $_ENV['APP_URL'] = 'https://api.example.com';
        $_ENV['DB_HOST'] = 'db.internal';
        $_ENV['DB_PORT'] = '5433';
        $_ENV['DB_NAME'] = 'testdb';
        $_ENV['JWT_SECRET'] = 'ci-secret-key-that-is-long-enough-for-hs256';
        $_ENV['ACCESS_TTL'] = '300';
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.1,10.0.0.2';
        $_ENV['COOKIE_SECURE'] = 'true';

        $config = new Config();
        $this->assertSame('prod', $config->appEnv());
        $this->assertFalse($config->isDev());
        $this->assertTrue($config->isProd());
        $this->assertSame('https://api.example.com', $config->appUrl());
        $this->assertSame('db.internal', $config->dbHost());
        $this->assertSame('5433', $config->dbPort());
        $this->assertSame('testdb', $config->dbName());
        $this->assertSame('pgsql:host=db.internal;port=5433;dbname=testdb', $config->dbDsn());
        $this->assertSame('ci-secret-key-that-is-long-enough-for-hs256', $config->jwtSecret());
        $this->assertSame(300, $config->accessTtl());
        $this->assertSame(['10.0.0.1', '10.0.0.2'], $config->trustedProxies());
        $this->assertTrue($config->cookieSecure());
    }

    public function testTrustedProxiesEmptyWhenNotSet(): void
    {
        $config = new Config();
        $this->assertSame([], $config->trustedProxies());
    }

    public function testCorsOriginsIncludesAppAndFrontend(): void
    {
        putenv('APP_URL=http://localhost:8000');
        putenv('FRONTEND_URL=http://localhost:5173');
        $_ENV['APP_URL'] = 'http://localhost:8000';
        $_ENV['FRONTEND_URL'] = 'http://localhost:5173';

        $config = new Config();
        $origins = $config->corsOrigins();
        $this->assertContains('http://localhost:8000', $origins);
        $this->assertContains('http://localhost:5173', $origins);
    }

    public function testCorsOriginsDeduplicates(): void
    {
        putenv('APP_URL=http://localhost:8000');
        putenv('FRONTEND_URL=http://localhost:8000');
        $_ENV['APP_URL'] = 'http://localhost:8000';
        $_ENV['FRONTEND_URL'] = 'http://localhost:8000';

        $config = new Config();
        $origins = $config->corsOrigins();
        $count = count(array_filter($origins, fn ($o) => $o === 'http://localhost:8000'));
        $this->assertSame(1, $count);
    }
}
