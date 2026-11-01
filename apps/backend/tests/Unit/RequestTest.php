<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Vistynq\Http\Request;

final class RequestTest extends TestCase
{
    public function testConstructorGetters(): void
    {
        $req = new Request(
            'GET',
            '/api/v1/canvases',
            ['page' => '1'],
            ['content-type' => 'application/json'],
            '{"key":"value"}',
            ['session' => 'abc'],
            ['id' => '550e8400-e29b-41d4-a716-446655440000'],
            ['auth' => true],
            '127.0.0.1',
        );

        $this->assertSame('GET', $req->method());
        $this->assertSame('/api/v1/canvases', $req->path());
        $this->assertSame('1', $req->query('page'));
        $this->assertSame('application/json', $req->header('content-type'));
        $this->assertSame('{"key":"value"}', $req->rawBody());
        $this->assertSame('abc', $req->cookie('session'));
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $req->routeParam('id'));
        $this->assertSame('127.0.0.1', $req->remoteAddr());
    }

    public function testRemoteAddrNullDefault(): void
    {
        $req = new Request('GET', '/', [], [], '', [], [], [], null);
        $this->assertNull($req->remoteAddr());
    }

    public function testJsonAttrs(): void
    {
        $req = new Request('GET', '/', [], [], '', [], [], ['jsonBody' => ['foo' => 'bar']], null);
        $this->assertSame(['foo' => 'bar'], $req->json());
        $this->assertSame('bar', $req->jsonScalar('foo'));
        $this->assertNull($req->jsonScalar('missing'));
    }

    public function testWithRouteParams(): void
    {
        $req = new Request('GET', '/api/v1/notes/{id}', [], [], '', []);
        $withParams = $req->withRouteParams(['id' => 'abc-123']);

        $this->assertNull($req->routeParam('id'));
        $this->assertSame('abc-123', $withParams->routeParam('id'));
    }

    public function testFromGlobalsSetsRemoteAddr(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/v1/healthz';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['SERVER_NAME'] = '127.0.0.1';
        $_SERVER['SERVER_PORT'] = '8000';
        $_GET = [];
        $_COOKIE = [];

        $req = Request::fromGlobals();
        $this->assertSame('10.0.0.5', $req->remoteAddr());
        $this->assertSame('GET', $req->method());
        $this->assertSame('/api/v1/healthz', $req->path());
    }
}
