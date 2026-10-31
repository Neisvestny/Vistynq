<?php

declare(strict_types=1);

namespace Vistynq\Support;

use Exception;

/**
 * Application-level exception mapped to a JSON error response.
 *
 * Shape follows docs/api.md:
 * {"error": {"code": "...", "message": "...", "details": {...}}}
 */
class ApiException extends Exception
{
    /** @var array<string, string> extra response headers (e.g. Allow on 405) */
    private array $extraHeaders = [];

    /**
     * @param array<string, list<string>>|null $details
     */
    public function __construct(
        private readonly int $httpStatus,
        private readonly string $errorCode,
        string $message,
        private readonly ?array $details = null,
    ) {
        parent::__construct($message, $httpStatus);
    }

    /**
     * @param array<string, list<string>> $details
     */
    public static function make(int $status, string $code, string $message, ?array $details = null): self
    {
        return new self($status, $code, $message, $details);
    }

    /**
     * Adds extra response headers, e.g. Allow on 405. Returns $this for chaining
     * (Exception objects cannot be cloned in PHP, so this mutates in place).
     *
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        $this->extraHeaders = array_merge($this->extraHeaders, $headers);

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function extraHeaders(): array
    {
        return $this->extraHeaders;
    }

    public static function badRequest(string $message = 'Некорректный запрос', ?array $details = null): self
    {
        return new self(400, 'BAD_REQUEST', $message, $details);
    }

    public static function unauthorized(string $code = 'UNAUTHORIZED', string $message = 'Требуется авторизация'): self
    {
        return new self(401, $code, $message);
    }

    public static function forbidden(string $code = 'FORBIDDEN', string $message = 'Недостаточно прав'): self
    {
        return new self(403, $code, $message);
    }

    public static function notFound(string $message = 'Не найдено'): self
    {
        return new self(404, 'NOT_FOUND', $message);
    }

    public static function conflict(string $message = 'Конфликт уникальности', ?array $details = null): self
    {
        return new self(409, 'CONFLICT', $message, $details);
    }

    public static function gone(string $message = 'Ресурс удалён'): self
    {
        return new self(410, 'GONE', $message);
    }

    public static function tooManyRequests(string $message = 'Слишком много запросов'): self
    {
        return new self(429, 'TOO_MANY_REQUESTS', $message);
    }

    /**
     * @param array<string, list<string>> $details field => [messages]
     */
    public static function validation(array $details, string $message = 'Проверьте поля формы'): self
    {
        return new self(422, 'VALIDATION_FAILED', $message, $details);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, list<string>>|null
     */
    public function errorDetails(): ?array
    {
        return $this->details;
    }

    /**
     * @return array{error: array{code: string, message: string, details?: array<string, list<string>>}}
     */
    public function toArray(): array
    {
        $error = [
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ];

        if ($this->details !== null) {
            $error['details'] = $this->details;
        }

        return ['error' => $error];
    }
}
