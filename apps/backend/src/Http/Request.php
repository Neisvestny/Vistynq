<?php

declare(strict_types=1);

namespace Vistynq\Http;

/**
 * Immutable HTTP request wrapper over PHP superglobals.
 * Deliberately not PSR-7: vanilla PHP, minimal surface.
 */
final class Request
{
    /**
     * @param array<string, string>       $query
     * @param array<string, string>       $headers lowercase-header => value
     * @param array<string, string>       $cookies
     * @param array<string, mixed>        $routeParams
     * @param array<string, mixed>        $attributes
     * @param string|null                 $remoteAddr
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $headers,
        private readonly string $rawBody,
        private readonly array $cookies,
        private array $routeParams = [],
        private array $attributes = [],
        private readonly ?string $remoteAddr = null,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = rtrim(is_string($path) ? $path : '/', '/') ?: '/';

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        $rawBody = file_get_contents('php://input');
        $cookies = [];
        foreach ($_COOKIE as $name => $value) {
            if (is_string($value)) {
                $cookies[(string) $name] = $value;
            }
        }

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;

        return new self($method, $path, $_GET, $headers, $rawBody === false ? '' : $rawBody, $cookies, [], [], $remoteAddr);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(string $name, ?string $default = null): ?string
    {
        $value = $this->query[$name] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /**
     * @return array<string, string>
     */
    public function queryParams(): array
    {
        return $this->query;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * Parsed JSON body (already validated by JsonBodyMiddleware).
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $body = $this->attributes['jsonBody'] ?? null;

        return is_array($body) ? $body : [];
    }

    public function jsonScalar(string $name): mixed
    {
        return $this->json()[$name] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /**
     * Real client IP as seen by the web server (REMOTE_ADDR).
     * Never spoofable by the client: use this as the rate-limit key unless
     * a trusted reverse proxy is configured.
     */
    public function remoteAddr(): ?string
    {
        return $this->remoteAddr;
    }

    /**
     * @return array<string, mixed>
     */
    public function routeParams(): array
    {
        return $this->routeParams;
    }

    public function routeParam(string $name): ?string
    {
        $value = $this->routeParams[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function withRouteParams(array $params): self
    {
        $clone = clone $this;
        $clone->routeParams = $params;

        return $clone;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function attribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function withAttributes(array $attributes): self
    {
        $clone = clone $this;
        $clone->attributes = array_merge($this->attributes, $attributes);

        return $clone;
    }
}
