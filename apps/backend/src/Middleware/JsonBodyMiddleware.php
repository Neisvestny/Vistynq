<?php

declare(strict_types=1);

namespace Vistynq\Middleware;

use JsonException;
use Vistynq\Http\Request;
use Vistynq\Http\Response;
use Vistynq\Support\ApiException;

/**
 * Parses application/json request bodies into a request attribute.
 * Malformed JSON -> 400 BAD_REQUEST (docs/api.md).
 */
final class JsonBodyMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $contentType = $request->header('Content-Type', '');
        $hasBody = $request->rawBody() !== '';

        if ($hasBody && stripos($contentType, 'application/json') === 0) {
            // Bodies must be JSON objects: cheap check before decoding assoc arrays.
            $trimmed = ltrim($request->rawBody());
            if ($trimmed === '' || $trimmed[0] !== '{') {
                throw ApiException::badRequest('Тело запроса должно быть JSON-объектом');
            }

            try {
                $decoded = json_decode($request->rawBody(), true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw ApiException::badRequest('Тело запроса не является корректным JSON');
            }

            if (!is_array($decoded)) {
                throw ApiException::badRequest('Тело запроса должно быть JSON-объектом');
            }

            /** @var array<string, mixed> $decoded */
            $request = $request->withAttributes(['jsonBody' => $decoded]);
        }

        return $next($request);
    }
}
