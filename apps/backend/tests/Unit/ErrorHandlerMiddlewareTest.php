<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Vistynq\Config\Config;
use Vistynq\Http\Request;
use Vistynq\Http\Response;
use Vistynq\Middleware\ErrorHandlerMiddleware;
use Vistynq\Support\ApiException;
use Vistynq\Support\Logger;
use Vistynq\Support\RequestContext;

/**
 * Unit-тесты внешнего обработчика ошибок (R15): ApiException → её JSON
 * со статусом; прочие Throwable → 500 INTERNAL; стек уходит только в dev
 * (ROADMAP R03 — утечка стека в prod запрещена).
 */
final class ErrorHandlerMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        // prod по умолчанию: «no stack leak» — основной контракт (R03).
        $this->setAppEnv('prod');
    }

    protected function tearDown(): void
    {
        $this->setAppEnv('test');
    }

    // --- ApiException проходит со своим статусом и телом ---

    public function testApiExceptionMapsToItsStatusAndBody(): void
    {
        $response = $this->process(fn (): Response => throw ApiException::forbidden('EMAIL_NOT_VERIFIED', 'Email не подтверждён'));

        $this->assertSame(403, $response->status());
        $body = $this->json($response);
        $this->assertSame('EMAIL_NOT_VERIFIED', $body['error']['code']);
        $this->assertSame('Email не подтверждён', $body['error']['message']);
    }

    public function testApiExceptionKeepsValidationDetails(): void
    {
        $e = ApiException::validation(['email' => ['некорректный email']], 'Проверьте поля формы');
        $response = $this->process(static fn (): Response => throw $e);

        $this->assertSame(422, $response->status());
        $this->assertSame(['email' => ['некорректный email']], $this->json($response)['error']['details']);
    }

    public function testApiExceptionCarriesExtraHeaders(): void
    {
        $e = ApiException::make(405, 'METHOD_NOT_ALLOWED', 'Метод не поддерживается')
            ->withHeaders(['Allow' => 'GET']);

        $response = $this->process(static fn (): Response => throw $e);

        $this->assertSame(405, $response->status());
        $this->assertSame('GET', $response->headers()['Allow'] ?? null);
    }

    public function testSuccessfulResponsePassesThrough(): void
    {
        $response = $this->process(static fn (): Response => Response::json(['ok' => true]));

        $this->assertSame(200, $response->status());
        $this->assertSame(['ok' => true], $this->json($response));
    }

    // --- Прочие Throwable → 500 INTERNAL ---

    public function testUnexpectedThrowableBecomes500Internal(): void
    {
        $response = $this->process(static fn (): Response => throw new RuntimeException('secret db password'));

        $this->assertSame(500, $response->status());
        $body = $this->json($response);
        $this->assertSame('INTERNAL', $body['error']['code']);
        $this->assertArrayNotHasKey('exception', $body['error'], 'класс исключения не должен утекать');
        $this->assertArrayNotHasKey('trace', $body['error'], 'стек не должен утекать');
    }

    public function testUnexpectedThrowableIsLogged(): void
    {
        $requestId = 'req-log-1';
        $lines = $this->processWithLog(
            static fn (): Response => throw new RuntimeException('boom-log-message Bearer supersecrettoken'),
            $requestId,
        );

        $this->assertNotEmpty($lines, 'исключение должно быть залогировано');
        $entry = json_decode($lines[0], true);
        $this->assertIsArray($entry);
        $this->assertSame('error', $entry['level']);
        $this->assertSame('Unhandled exception', $entry['message']);
        $this->assertSame($requestId, $entry['requestId']);
        $this->assertSame('GET', $entry['context']['method']);
        $this->assertSame('/api/v1/test', $entry['context']['path']);
        $this->assertStringContainsString('boom-log-message', (string) $entry['context']['error']);
        $this->assertStringNotContainsString('supersecrettoken', $lines[0], 'секреты не должны утекать в лог');
    }

    // --- dev: стек для отладки; prod/test: без стека ---

    public function testDevExposesExceptionClassAndTrace(): void
    {
        $this->setAppEnv('dev');

        $response = $this->process(static fn (): Response => throw new LogicException('dev-boom'));

        $error = $this->json($response)['error'];
        $this->assertSame(LogicException::class, $error['exception']);
        $this->assertSame('dev-boom', $error['message']);
        $this->assertIsArray($error['trace']);
        $this->assertNotEmpty($error['trace']);
    }

    public function testProdHidesExceptionClassMessageAndTrace(): void
    {
        $this->setAppEnv('prod');

        $response = $this->process(static fn (): Response => throw new LogicException('prod-secret'));

        $error = $this->json($response)['error'];
        $this->assertSame('INTERNAL', $error['code']);
        $this->assertSame('Внутренняя ошибка сервера', $error['message']);
        $this->assertArrayNotHasKey('exception', $error);
        $this->assertArrayNotHasKey('trace', $error);
        $this->assertStringNotContainsString('prod-secret', $response->body());
    }

    public function testTestEnvIsTreatedAsDevForTrace(): void
    {
        // Фактический контракт Config::isDev(): всё, кроме prod, — dev.
        // APP_ENV=test ⇒ trace показывается (удобно для отладки падений в CI).
        $this->setAppEnv('test');

        $response = $this->process(static fn (): Response => throw new RuntimeException('x'));

        $this->assertSame(500, $response->status());
        $error = $this->json($response)['error'];
        $this->assertSame('INTERNAL', $error['code']);
        $this->assertArrayHasKey('trace', $error);
    }

    // --- хелперы ---

    private function process(callable $next): Response
    {
        $config = new Config();
        $context = new RequestContext();
        $logFile = tempnam(sys_get_temp_dir(), 'ehm-log-');
        $middleware = new ErrorHandlerMiddleware($config, new Logger($config, $context, $logFile));
        $request = new Request('GET', '/api/v1/test', [], [], '', []);

        try {
            return $middleware->process($request, $next);
        } finally {
            @unlink($logFile);
        }
    }

    /**
     * Выполняет middleware и перехватывает JSON-строки лога.
     *
     * @return list<string>
     */
    private function processWithLog(callable $next, string $requestId = 'req-log'): array
    {
        $logFile = tempnam(sys_get_temp_dir(), 'ehm-log-');
        $config = new Config();
        $context = new RequestContext();
        $context->setRequestId($requestId);
        $middleware = new ErrorHandlerMiddleware($config, new Logger($config, $context, $logFile));
        $request = new Request('GET', '/api/v1/test', [], [], '', []);

        try {
            $middleware->process($request, $next);
            $contents = (string) file_get_contents($logFile);

            return array_values(array_filter(explode(PHP_EOL, trim($contents))));
        } finally {
            @unlink($logFile);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $decoded = json_decode($response->body(), true);
        $this->assertIsArray($decoded, 'тело должно быть JSON: ' . $response->body());

        return $decoded;
    }

    private function setAppEnv(string $value): void
    {
        putenv('APP_ENV=' . $value);
        $_ENV['APP_ENV'] = $value;
    }
}
