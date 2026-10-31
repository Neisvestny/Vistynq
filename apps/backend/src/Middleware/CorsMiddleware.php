<?php

declare(strict_types=1);

namespace Vistynq\Middleware;

use Vistynq\Config\Config;
use Vistynq\Http\Request;
use Vistynq\Http\Response;

/**
 * CORS for a strict origin whitelist (docs/architecture.md):
 * Allow-Origin echoes the exact request origin only when it is whitelisted;
 * credentials are allowed; preflight is answered with 204 and stops the pipeline.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private array $allowedOrigins;

    private const ALLOWED_HEADERS = 'Authorization, Content-Type';

    private const ALLOWED_METHODS = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';

    private const EXPOSED_HEADERS = 'X-Request-Id';

    public function __construct(Config $config)
    {
        $this->allowedOrigins = $config->corsOrigins();
    }

    public function process(Request $request, callable $next): Response
    {
        $origin = $request->header('Origin');
        $corsHeaders = [];

        if ($origin !== null && in_array($origin, $this->allowedOrigins, true)) {
            $corsHeaders = [
                'Access-Control-Allow-Origin' => $origin,
                'Access-Control-Allow-Credentials' => 'true',
                'Vary' => 'Origin',
            ];

            if ($request->method() === 'OPTIONS') {
                $corsHeaders['Access-Control-Allow-Methods'] = self::ALLOWED_METHODS;
                $corsHeaders['Access-Control-Allow-Headers'] = self::ALLOWED_HEADERS;
                $corsHeaders['Access-Control-Max-Age'] = '600';
            }
        }

        if ($request->method() === 'OPTIONS') {
            // Preflight never reaches handlers: 200 with (or without) CORS headers.
            return Response::json(['ok' => true], 200, $corsHeaders);
        }

        return $next($request)->withHeaders($corsHeaders);
    }
}
