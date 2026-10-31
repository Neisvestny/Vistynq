<?php

declare(strict_types=1);

namespace Vistynq\Middleware;

use Vistynq\Http\Request;
use Vistynq\Http\Response;
use Vistynq\Support\ApiException;

final class RateLimitMiddleware implements MiddlewareInterface
{
    /** @var array<string, array{count:int,reset:int}> */
    private array $store = [];

    public function __construct(private readonly int $maxAttempts = 60, private readonly int $windowSeconds = 60)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $path = $request->path();
        if (!str_starts_with($path, '/api/v1/auth/')) {
            return $next($request);
        }

        $ip = $this->ip($request);
        $key = $ip . '|' . $path;
        $now = time();

        if (!isset($this->store[$key]) || $this->store[$key]['reset'] <= $now) {
            $this->store[$key] = ['count' => 1, 'reset' => $now + $this->windowSeconds];
            return $next($request);
        }

        $this->store[$key]['count']++;

        if ($this->store[$key]['count'] > $this->maxAttempts) {
            throw ApiException::tooManyRequests('Слишком много запросов, попробуйте позже');
        }

        return $next($request);
    }

    private function ip(Request $request): string
    {
        $forwarded = $request->header('x-forwarded-for');
        if (is_string($forwarded) && $forwarded !== '') {
            $parts = explode(',', $forwarded);
            $ip = trim($parts[0]);

            return $ip !== '' ? $ip : 'unknown';
        }

        $real = $request->header('x-real-ip');
        if (is_string($real) && $real !== '') {
            return $real;
        }

        return 'unknown';
    }
}
