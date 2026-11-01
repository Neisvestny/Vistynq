<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Vistynq\Http\Request;
use Vistynq\Http\Router;
use Vistynq\Support\ApiException;

final class RouterUuidTest extends TestCase
{
    private function makeRouter(): Router
    {
        $router = new Router();
        $router->add('GET', '/api/v1/canvases/{id}', fn () => null);
        $router->add('PATCH', '/api/v1/canvases/{id}/nodes/{noteId}', fn () => null);
        $router->add('GET', '/api/v1/notes/{id}', fn () => null);

        return $router;
    }

    private function request(string $method, string $path): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['SERVER_NAME'] = '127.0.0.1';
        $_SERVER['SERVER_PORT'] = '8000';

        return Request::fromGlobals();
    }

    public function testValidUuidMatches(): void
    {
        $router = $this->makeRouter();
        $req = $this->request('GET', '/api/v1/canvases/550e8400-e29b-41d4-a716-446655440000');

        $match = $router->match($req);
        $this->assertNotNull($match);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $match[1]['id']);
    }

    public function testInvalidUuidReturns400(): void
    {
        $router = $this->makeRouter();
        $req = $this->request('GET', '/api/v1/canvases/not-a-uuid');

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $router->match($req);
    }

    public function testInvalidNoteIdReturns400(): void
    {
        $router = $this->makeRouter();
        $req = $this->request('PATCH', '/api/v1/canvases/550e8400-e29b-41d4-a716-446655440000/nodes/also-not-uuid');

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $router->match($req);
    }

    public function testEmptyUuidParamReturns400(): void
    {
        $router = $this->makeRouter();
        $req = $this->request('GET', '/api/v1/notes/');

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(404);
        $router->match($req);
    }

    public function testRouteNotFoundReturns404(): void
    {
        $router = $this->makeRouter();
        $req = $this->request('GET', '/api/v1/unknown');

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(404);
        $router->match($req);
    }
}
