<?php

declare(strict_types=1);

namespace Vistynq\Middleware;

use Vistynq\Http\Request;
use Vistynq\Http\Response;
use Vistynq\Services\JwtService;
use Vistynq\Support\ApiException;

/**
 * Extracts the Bearer access token into a request attribute (authUserId).
 * Requests without the header pass through (public routes); requests with
 * an invalid or expired token get 401 before hitting a controller.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly JwtService $jwt)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $header = $request->header('Authorization', '');
        if ($header === '' || !preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            return $next($request);
        }

        $claims = $this->jwt->verify($matches[1]);

        return $next($request->withAttributes(['authUserId' => $claims['sub']]));
    }

    /**
     * @throws ApiException 401 when the route requires auth and the token was absent
     */
    public static function userId(Request $request): string
    {
        $userId = $request->attribute('authUserId');
        if (!is_string($userId)) {
            throw ApiException::unauthorized();
        }

        return $userId;
    }
}
