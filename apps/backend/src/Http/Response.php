<?php

declare(strict_types=1);

namespace Vistynq\Http;

/**
 * Simple HTTP response: status, headers, body. Sent by Kernel::send().
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly int $status,
        private readonly array $headers = [],
        private readonly string $body = '',
    ) {
    }

    /**
     * @param array<string, mixed>|list<mixed>|scalar|null $data
     * @param array<string, string>                        $headers
     */
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            $body = '{"error":{"code":"INTERNAL","message":"Не удалось закодировать ответ"}}';
            $status = 500;
        }

        return new self($status, array_merge(['Content-Type' => 'application/json; charset=utf-8'], $headers), $body);
    }

    /**
     * 204 No Content: без тела (docs/openapi.yaml: DELETE/PATCH → 204).
     *
     * @param array<string, string> $headers
     */
    public static function noContent(array $headers = []): self
    {
        return new self(204, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function redirect(string $location, int $status = 302, array $headers = []): self
    {
        return new self($status, array_merge(['Location' => $location], $headers));
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->status, array_merge($this->headers, $headers), $this->body);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        if ($this->body !== '') {
            echo $this->body;
        }
    }
}
