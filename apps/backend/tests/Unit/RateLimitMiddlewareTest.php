<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Vistynq\Config\Config;
use Vistynq\Http\Request;
use Vistynq\Middleware\RateLimitMiddleware;

final class RateLimitMiddlewareTest extends TestCase
{
    private function makeRequest(string $ip, bool $trusted = false): Request
    {
        $req = new Request('POST', '/api/v1/auth/login', [], [], '', [], [], [], $ip);

        if ($trusted) {
            $req = $req->withAttributes([]);
        }

        return $req;
    }

    public function testUntrustedProxyUsesRemoteAddr(): void
    {
        putenv('TRUSTED_PROXIES=');
        $_ENV['TRUSTED_PROXIES'] = '';

        $config = new Config();
        $middleware = new RateLimitMiddleware($config, 2, 60);

        $req = $this->makeRequest('1.2.3.4');

        $next = fn (Request $r) => new \Vistynq\Http\Response(200, ['ok' => true]);
        $middleware->process($req, $next);
        $middleware->process($req, $next);

        $this->expectException(\Vistynq\Support\ApiException::class);
        $this->expectExceptionCode(429);
        $middleware->process($req, $next);
    }

    public function testTrustedProxyUsesXForwardedFor(): void
    {
        putenv('TRUSTED_PROXIES=10.0.0.1');
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.1';

        $config = new Config();
        $middleware = new RateLimitMiddleware($config, 2, 60);

        $req = new Request('POST', '/api/v1/auth/login', [], ['x-forwarded-for' => '203.0.113.5'], '', [], [], [], '10.0.0.1');

        $next = fn (Request $r) => new \Vistynq\Http\Response(200, ['ok' => true]);
        $middleware->process($req, $next);
        $middleware->process($req, $next);

        $this->expectException(\Vistynq\Support\ApiException::class);
        $this->expectExceptionCode(429);
        $middleware->process($req, $next);
    }

    public function testNonAuthPathNotRateLimited(): void
    {
        putenv('TRUSTED_PROXIES=');
        $_ENV['TRUSTED_PROXIES'] = '';

        $config = new Config();
        $middleware = new RateLimitMiddleware($config, 1, 60);

        $req = new Request('GET', '/api/v1/canvases', [], [], '', [], [], [], '1.2.3.4');

        $next = fn (Request $r) => new \Vistynq\Http\Response(200, ['ok' => true]);
        $result = $middleware->process($req, $next);
        $this->assertSame(200, $result->status());
    }

    public function testUnknownIpFallback(): void
    {
        putenv('TRUSTED_PROXIES=');
        $_ENV['TRUSTED_PROXIES'] = '';

        $config = new Config();
        $middleware = new RateLimitMiddleware($config, 1, 60);

        $req = new Request('POST', '/api/v1/auth/login', [], [], '', [], [], [], null);

        $next = fn (Request $r) => new \Vistynq\Http\Response(200, ['ok' => true]);
        $middleware->process($req, $next);

        $this->expectException(\Vistynq\Support\ApiException::class);
        $this->expectExceptionCode(429);
        $middleware->process($req, $next);
    }
}
