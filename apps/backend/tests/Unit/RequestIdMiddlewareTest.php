<?php

declare(strict_types=1);

namespace Vistynq\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vistynq\Http\Request;
use Vistynq\Http\Response;
use Vistynq\Middleware\RequestIdMiddleware;

/**
 * X-Request-Id: эхо валидного id клиента, генерация uuid при отсутствии/мусоре,
 * заголовок на любом ответе (docs/architecture.md §middleware).
 */
final class RequestIdMiddlewareTest extends TestCase
{
    public function testEchoesValidIncomingRequestId(): void
    {
        $response = $this->process('req-abc.123:xyz');

        $this->assertSame('req-abc.123:xyz', $response->headers()['X-Request-Id']);
    }

    public function testGeneratesUuidWhenHeaderMissing(): void
    {
        $response = $this->process(null);

        $id = $response->headers()['X-Request-Id'] ?? null;
        $this->assertIsString($id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
            'без заголовка генерируется uuid v4',
        );
    }

    public function testReplacesGarbageIncomingValue(): void
    {
        // Пробелы/запятые/кириллица/слишком длинные значения неприемлемы.
        foreach (['bad id', 'a,b', 'юникод', str_repeat('x', 129), ''] as $garbage) {
            $response = $this->process($garbage);
            $id = $response->headers()['X-Request-Id'] ?? '';
            $this->assertNotSame($garbage, $id, 'мусорный id заменяется');
            $this->assertNotSame('', $id);
        }
    }

    public function testAttachesRequestIdToRequestAttributes(): void
    {
        $captured = null;
        $middleware = new RequestIdMiddleware();
        $middleware->process(
            $this->request('client-id-1'),
            function (Request $request) use (&$captured): Response {
                $captured = $request->attribute('requestId');

                return Response::json(['ok' => true]);
            },
        );

        $this->assertSame('client-id-1', $captured);
    }

    public function testHeaderIsSetOnErrorResponseToo(): void
    {
        $middleware = new RequestIdMiddleware();
        $response = $middleware->process(
            $this->request(null),
            static fn (): Response => Response::json(['error' => ['code' => 'INTERNAL']], 500),
        );

        $this->assertSame(500, $response->status());
        $this->assertArrayHasKey('X-Request-Id', $response->headers());
    }

    private function process(?string $incoming): Response
    {
        $middleware = new RequestIdMiddleware();

        return $middleware->process(
            $this->request($incoming),
            static fn (): Response => Response::json(['ok' => true]),
        );
    }

    private function request(?string $incomingId): Request
    {
        $headers = $incomingId === null ? [] : ['x-request-id' => $incomingId];

        return new Request('GET', '/api/v1/healthz', [], $headers, '', []);
    }
}
