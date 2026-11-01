<?php

declare(strict_types=1);

namespace Vistynq\Http;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Vistynq\Support\ApiException;
use Vistynq\Support\Validator;

use function FastRoute\simpleDispatcher;

/**
 * fast-route wrapper: route registration + dispatch to handler.
 *
 * Handlers are callables: fn (Request $req): Response or [class, method]
 * resolved through the DI container by the Kernel.
 */
final class Router
{
    /** @var list<array{string, string, callable|array{0: class-string, 1: string}|string}> */
    private array $routes = [];

    private ?Dispatcher $dispatcher = null;

    /**
     * Handler forms: callable (Request): Response, [class-string, method] or "Class::method".
     *
     * @param callable|array{0: class-string, 1: string}|string $handler
     */
    public function add(string $method, string $path, callable|array|string $handler): void
    {
        $this->routes[] = [strtoupper($method), $path, $handler];
        $this->dispatcher = null;
    }

    /**
     * @return array{0: callable|array{0: class-string, 1: string}|string, 1: array<string, mixed>}|null
     *         [handler, routeParams]
     *
     * @throws ApiException on 404 / 405 / 400 (invalid UUID route param)
     */
    public function match(Request $request): ?array
    {
        $dispatcher = $this->dispatcher ??= simpleDispatcher(function (RouteCollector $collector): void {
            foreach ($this->routes as [$method, $path, $handler]) {
                $collector->addRoute($method, $path, $handler);
            }
        });

        $routeInfo = $dispatcher->dispatch($request->method(), $request->path());

        switch ($routeInfo[0]) {
            case Dispatcher::FOUND:
                /** @var array<string, mixed> $vars */
                $vars = $routeInfo[2];

                $this->validateUuidRouteParams($vars);

                return [$routeInfo[1], $vars];

            case Dispatcher::METHOD_NOT_ALLOWED:
                // OPTIONS never reach here: CorsMiddleware answers preflight earlier.
                throw ApiException::make(
                    405,
                    'METHOD_NOT_ALLOWED',
                    'Метод не поддерживается',
                )->withHeaders(['Allow' => implode(', ', array_unique($routeInfo[1]))]);

            default:
                throw ApiException::notFound('Маршрут не найден');
        }
    }

    /**
     * Все route-параметры бэкенда — UUID. Некорректный UUID → 400 вместо 500
     * от PostgreSQL downstream.
     *
     * @param array<string, mixed> $vars
     */
    private function validateUuidRouteParams(array $vars): void
    {
        foreach ($vars as $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            if (preg_match(Validator::UUID_PATTERN, $value) !== 1) {
                throw ApiException::badRequest('Некорректный идентификатор ресурса');
            }
        }
    }
}
