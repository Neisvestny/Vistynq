<?php

declare(strict_types=1);

namespace Vistynq\Middleware;

use Vistynq\Http\Request;
use Vistynq\Http\Response;

/**
 * Correlates every response with X-Request-Id (docs/architecture.md §middleware).
 *
 * A well-formed client-supplied id is echoed back; anything else (or nothing)
 * is replaced with a fresh uuid. The id is also attached to the request
 * attributes as `requestId` so handlers/loggers can reference it.
 *
 * Outermost layer: error responses from ErrorHandlerMiddleware get the header
 * too. CORS exposes X-Request-Id (CorsMiddleware::EXPOSED_HEADERS).
 */
final class RequestIdMiddleware implements MiddlewareInterface
{
    private const MAX_LENGTH = 128;

    public function process(Request $request, callable $next): Response
    {
        $incoming = $request->header('X-Request-Id');
        $requestId = $this->isAcceptable($incoming) ? (string) $incoming : self::generate();

        return $next($request->withAttributes(['requestId' => $requestId]))
            ->withHeaders(['X-Request-Id' => $requestId]);
    }

    private function isAcceptable(?string $value): bool
    {
        if ($value === null || $value === '' || strlen($value) > self::MAX_LENGTH) {
            return false;
        }

        // Printable ASCII without spaces/commas — enough for uuid/ULID/correlation ids.
        return preg_match('/^[A-Za-z0-9._:-]+$/', $value) === 1;
    }

    private static function generate(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
