<?php

declare(strict_types=1);

namespace Vistynq\Support;

use DateTimeImmutable;
use Throwable;
use Vistynq\Config\Config;

/**
 * Структурный логгер (R20): одна JSON-строка на запись в stderr/stdout
 * контейнера, с requestId и контекстом. Токены/пароли/секреты редактируются.
 */
final class Logger
{
    private const LEVELS = [
        'debug' => 0,
        'info' => 1,
        'warning' => 2,
        'error' => 3,
    ];

    private const SENSITIVE_KEY = '/password|passwd|secret|token|authorization|cookie|refresh|access[_-]?token|jwt|api[_-]?key|credential/i';
    private const BEARER = '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i';

    private int $minLevel;

    public function __construct(
        private readonly Config $config,
        private readonly RequestContext $context,
        private readonly string $stream = 'php://stderr',
    ) {
        $level = strtolower($this->config->logLevel());
        $this->minLevel = self::LEVELS[$level] ?? self::LEVELS['info'];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * Удобный хелпер для исключений.
     *
     * @param array<string, mixed> $context
     */
    public function exception(Throwable $e, string $message = 'Unhandled exception', array $context = []): void
    {
        $context += [
            'exception' => $e::class,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];

        $this->error($message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context): void
    {
        if ((self::LEVELS[$level] ?? self::LEVELS['info']) < $this->minLevel) {
            return;
        }

        $payload = [
            'time' => (new DateTimeImmutable())->format(DATE_ATOM),
            'level' => $level,
            'message' => $this->redactString($message),
        ];

        $requestId = $this->context->requestId();
        if ($requestId !== null && $requestId !== '') {
            $payload['requestId'] = $requestId;
        }

        if ($context !== []) {
            $payload['context'] = $this->redactValue($context);
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{"level":"error","message":"logger json_encode failed"}';
        }

        // Одна строка в stderr/stdout контейнера (или файл в тестах).
        @file_put_contents($this->stream, $json . PHP_EOL, FILE_APPEND);
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private function redactValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match(self::SENSITIVE_KEY, $key) === 1) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->redactValue($v, is_string($k) ? $k : null);
            }

            return $out;
        }

        if (is_string($value)) {
            return $this->redactString($value);
        }

        return $value;
    }

    private function redactString(string $value): string
    {
        return preg_replace(self::BEARER, 'Bearer [REDACTED]', $value) ?? $value;
    }
}
