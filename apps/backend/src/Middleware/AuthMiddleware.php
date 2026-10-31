<?php

declare(strict_types=1);

namespace Vistynq\Middleware;

use Vistynq\Http\Request;
use Vistynq\Http\Response;
use Vistynq\Services\JwtService;
use Vistynq\Support\ApiException;

final class AuthMiddleware implements MiddlewareInterface
{
    private const PUBLIC_PREFIXES = [
        '/healthz',
        '/api/v1/healthz',
        '/api/v1/auth/register',
        '/api/v1/auth/verify-email',
        '/api/v1/auth/resend-verification',
        '/api/v1/auth/login',
        '/api/v1/auth/refresh',
        '/api/v1/auth/logout',
        '/api/v1/auth/forgot-password',
        '/api/v1/auth/reset-password',
    ];

    public function __construct(private readonly JwtService $jwt)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $path = $request->path();

        if ($request->method() === 'OPTIONS') {
            return $next($request);
        }

        foreach (self::PUBLIC_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return $next($request);
            }
        }

        $header = $request->header('Authorization', '');
        if ($header === '' || !preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            throw ApiException::unauthorized();
        }

        try {
            $claims = $this->jwt->verify($matches[1]);
        } catch (\Throwable) {
            throw ApiException::unauthorized('TOKEN_EXPIRED', 'Токен истёк или недействителен');
        }

        $sub = $claims['sub'] ?? null;
        if (!is_string($sub) || $sub === '') {
            throw ApiException::unauthorized();
        }

        return $next($request->withAttributes(['authUserId' => $sub]));
    }

    public static function userId(Request $request): string
    {
        $userId = $request->attribute('authUserId');
        if (!is_string($userId)) {
            throw ApiException::unauthorized();
        }

        return $userId;
    }
}
