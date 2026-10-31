<?php

declare(strict_types=1);

namespace Vistynq;

use LogicException;
use Throwable;
use Vistynq\Config\Config;
use Vistynq\Config\EnvLoader;
use Vistynq\Http\Request;
use Vistynq\Http\Response;
use Vistynq\Http\Router;
use Vistynq\Middleware\CorsMiddleware;
use Vistynq\Middleware\ErrorHandlerMiddleware;
use Vistynq\Middleware\JsonBodyMiddleware;
use Vistynq\Middleware\MiddlewareInterface;
use Vistynq\Support\ApiException;
use Vistynq\Support\Container;

/**
 * Application bootstrap: env -> config -> DI -> routes -> middleware pipeline.
 *
 * Pipeline order (outermost first):
 *   ErrorHandler -> CORS -> JSON body -> router dispatch.
 */
final class Kernel
{
    private function __construct(
        private readonly Config $config,
        private readonly Container $container,
        private readonly Router $router,
    ) {
    }

    public static function boot(string $baseDir): self
    {
        EnvLoader::load($baseDir . '/.env');

        $config = new Config();

        error_reporting($config->isProd() ? E_ALL & ~E_DEPRECATED & ~E_NOTICE : E_ALL);
        ini_set('display_errors', $config->isProd() ? '0' : '1');

        $container = new Container();
        $container->singleton(Config::class, static fn (): Config => $config);
        $container->singleton(Router::class, static function (): Router {
            $router = new Router();
            require __DIR__ . '/routes.php';

            return $router;
        });

        $kernel = new self($config, $container, $container->get(Router::class));

        return $kernel;
    }

    public function run(): int
    {
        $request = Request::fromGlobals();

        $pipeline = $this->buildPipeline();

        try {
            $response = $pipeline($request);
        } catch (Throwable $e) {
            // Last-resort guard: never let a broken pipeline leak a PHP error page.
            error_log('[kernel] pipeline failure: ' . $e::class . ': ' . $e->getMessage());
            $status = $e instanceof ApiException ? $e->httpStatus() : 500;
            $response = Response::json(['error' => ['code' => 'INTERNAL', 'message' => 'Внутренняя ошибка сервера']], $status);
        }

        $response->send();

        return $response->status() >= 500 ? 1 : 0;
    }

    /**
     * @return callable(Request): Response
     */
    private function buildPipeline(): callable
    {
        /** @var list<MiddlewareInterface> $middleware */
        $middleware = [
            new ErrorHandlerMiddleware($this->config),
            new CorsMiddleware($this->config),
            new JsonBodyMiddleware(),
        ];

        $core = fn (Request $request): Response => $this->dispatch($request);

        $pipeline = $core;
        foreach (array_reverse($middleware) as $layer) {
            $next = $pipeline;
            $pipeline = static fn (Request $request): Response => $layer->process($request, $next);
        }

        return $pipeline;
    }

    private function dispatch(Request $request): Response
    {
        $match = $this->router->match($request);
        if ($match === null) {
            throw ApiException::notFound('Маршрут не найден');
        }

        [$handler, $routeParams] = $match;
        $request = $request->withRouteParams($routeParams);

        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            $handler = [$class, $method];
        }

        if (is_array($handler) && is_string($handler[0]) && is_string($handler[1])) {
            $controller = $this->container->get($handler[0]);
            $callable = [$controller, $handler[1]];
        } else {
            $callable = $handler;
        }

        $result = $callable($request);

        if (!$result instanceof Response) {
            throw new LogicException('Обработчик маршрута должен возвращать Response');
        }

        return $result;
    }
}
