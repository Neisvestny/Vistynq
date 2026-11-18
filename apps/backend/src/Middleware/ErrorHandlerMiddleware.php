<?php

declare(strict_types=1);

namespace Vistynq\Middleware;

use Throwable;
use Vistynq\Config\Config;
use Vistynq\Http\Request;
use Vistynq\Http\Response;
use Vistynq\Support\ApiException;
use Vistynq\Support\Logger;

/**
 * Outermost middleware: ApiException -> its JSON payload with matching status;
 * any other Throwable -> 500 INTERNAL. Stack trace leaks only in dev
 * (security requirement, roadmap R03).
 */
final class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (ApiException $e) {
            return Response::json($e->toArray(), $e->httpStatus(), $e->extraHeaders());
        } catch (Throwable $e) {
            $this->logger->error('Unhandled exception', [
                'method' => $request->method(),
                'path' => $request->path(),
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $payload = [
                'error' => [
                    'code' => 'INTERNAL',
                    'message' => 'Внутренняя ошибка сервера',
                ],
            ];

            if ($this->config->isDev()) {
                $payload['error']['exception'] = $e::class;
                $payload['error']['message'] = $e->getMessage();
                $payload['error']['trace'] = array_map(
                    static fn (array $frame): string => sprintf(
                        '%s%s(%s:%d)',
                        $frame['class'] ?? '',
                        $frame['type'] ?? '',
                        $frame['file'] ?? '?',
                        $frame['line'] ?? 0,
                    ),
                    array_slice($e->getTrace(), 0, 20),
                );
            }

            return Response::json($payload, 500);
        }
    }
}
