<?php

declare(strict_types=1);

namespace Vistynq\Config;

/**
 * Typed access to environment-backed application configuration.
 *
 * Reads come from getenv()/$_ENV (seeded by EnvLoader in dev).
 * No magic: plain getters with sane defaults, no container wiring required.
 */
final class Config
{
    public function appEnv(): string
    {
        return self::str('APP_ENV', 'dev');
    }

    public function isDev(): bool
    {
        return $this->appEnv() !== 'prod';
    }

    public function isProd(): bool
    {
        return !$this->isDev();
    }

    public function appUrl(): string
    {
        return rtrim(self::str('APP_URL', 'http://localhost:8000'), '/');
    }

    public function frontendUrl(): string
    {
        return rtrim(self::str('FRONTEND_URL', 'http://localhost:5173'), '/');
    }

    /**
     * CORS origins whitelist: APP_URL, FRONTEND_URL and optional CORS_ORIGINS (comma-separated).
     *
     * @return list<string>
     */
    public function corsOrigins(): array
    {
        $origins = [$this->appUrl(), $this->frontendUrl()];

        foreach (explode(',', self::str('CORS_ORIGINS', '')) as $extra) {
            $extra = rtrim(trim($extra), '/');
            if ($extra !== '') {
                $origins[] = $extra;
            }
        }

        return array_values(array_unique($origins));
    }

    public function dbHost(): string
    {
        return self::str('DB_HOST', '127.0.0.1');
    }

    public function dbPort(): string
    {
        return self::str('DB_PORT', '5432');
    }

    public function dbName(): string
    {
        return self::str('DB_NAME', 'vistynq');
    }

    public function dbUser(): string
    {
        return self::str('DB_USER', 'vistynq');
    }

    public function dbPass(): string
    {
        return self::str('DB_PASS', '');
    }

    public function dbDsn(): string
    {
        return sprintf('pgsql:host=%s;port=%s;dbname=%s', $this->dbHost(), $this->dbPort(), $this->dbName());
    }

    public function jwtSecret(): string
    {
        return self::str('JWT_SECRET', 'dev_only_change_me');
    }

    public function accessTtl(): int
    {
        return self::int('ACCESS_TTL', 900);
    }

    public function refreshTtl(): int
    {
        return self::int('REFRESH_TTL', 2592000);
    }

    public function s3Endpoint(): string
    {
        return self::str('S3_ENDPOINT', 'http://minio:9000');
    }

    public function s3Region(): string
    {
        return self::str('S3_REGION', 'us-east-1');
    }

    public function s3Bucket(): string
    {
        return self::str('S3_BUCKET', 'vistynq-media');
    }

    public function s3AccessKey(): string
    {
        return self::str('S3_ACCESS_KEY', '');
    }

    public function s3SecretKey(): string
    {
        return self::str('S3_SECRET_KEY', '');
    }

    public function s3UsePathStyle(): bool
    {
        return self::bool('S3_USE_PATH_STYLE', true);
    }

    public function smtpHost(): string
    {
        return self::str('SMTP_HOST', 'mailpit');
    }

    public function smtpPort(): int
    {
        return self::int('SMTP_PORT', 1025);
    }

    public function smtpUser(): string
    {
        return self::str('SMTP_USER', '');
    }

    public function smtpPass(): string
    {
        return self::str('SMTP_PASS', '');
    }

    public function mailFrom(): string
    {
        return self::str('MAIL_FROM', 'no-reply@vistynq.local');
    }

    public function cookieDomain(): string
    {
        return self::str('COOKIE_DOMAIN', '');
    }

    public function cookieSecure(): bool
    {
        return self::bool('COOKIE_SECURE', false);
    }

    private static function str(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }

    private static function int(string $name, int $default): int
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : (int) $value;
    }

    private static function bool(string $name, bool $default): bool
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
