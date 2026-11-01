<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Vistynq\Support\ApiException;

final class ApiExceptionTest extends TestCase
{
    public function testBadRequest(): void
    {
        $e = ApiException::badRequest('bad', ['email' => ['invalid']]);
        $this->assertSame(400, $e->httpStatus());
        $this->assertSame('BAD_REQUEST', $e->errorCode());
        $this->assertSame('bad', $e->getMessage());
        $this->assertSame(['email' => ['invalid']], $e->errorDetails());
        $this->assertSame([
            'error' => ['code' => 'BAD_REQUEST', 'message' => 'bad', 'details' => ['email' => ['invalid']]],
        ], $e->toArray());
    }

    public function testUnauthorized(): void
    {
        $e = ApiException::unauthorized('TOKEN_EXPIRED', 'Токен истёк');
        $this->assertSame(401, $e->httpStatus());
        $this->assertSame('TOKEN_EXPIRED', $e->errorCode());
        $this->assertSame('Токен истёк', $e->getMessage());
    }

    public function testUnauthorizedDefaults(): void
    {
        $e = ApiException::unauthorized();
        $this->assertSame(401, $e->httpStatus());
        $this->assertSame('UNAUTHORIZED', $e->errorCode());
        $this->assertSame('Требуется авторизация', $e->getMessage());
    }

    public function testForbidden(): void
    {
        $e = ApiException::forbidden('EMAIL_NOT_VERIFIED', 'Email не подтверждён');
        $this->assertSame(403, $e->httpStatus());
        $this->assertSame('EMAIL_NOT_VERIFIED', $e->errorCode());
    }

    public function testNotFound(): void
    {
        $e = ApiException::notFound();
        $this->assertSame(404, $e->httpStatus());
        $this->assertSame('NOT_FOUND', $e->errorCode());
        $this->assertSame('Не найдено', $e->getMessage());
    }

    public function testConflict(): void
    {
        $e = ApiException::conflict('Email занят', ['email' => ['занят']]);
        $this->assertSame(409, $e->httpStatus());
        $this->assertSame('CONFLICT', $e->errorCode());
        $this->assertSame(['email' => ['занят']], $e->errorDetails());
    }

    public function testGone(): void
    {
        $e = ApiException::gone();
        $this->assertSame(410, $e->httpStatus());
        $this->assertSame('GONE', $e->errorCode());
    }

    public function testTooManyRequests(): void
    {
        $e = ApiException::tooManyRequests();
        $this->assertSame(429, $e->httpStatus());
        $this->assertSame('TOO_MANY_REQUESTS', $e->errorCode());
    }

    public function testValidation(): void
    {
        $e = ApiException::validation(['email' => ['required']]);
        $this->assertSame(422, $e->httpStatus());
        $this->assertSame('VALIDATION_FAILED', $e->errorCode());
        $this->assertSame(['email' => ['required']], $e->errorDetails());
    }

    public function testWithHeaders(): void
    {
        $e = ApiException::notFound()->withHeaders(['Allow' => 'GET, HEAD']);
        $this->assertSame(['Allow' => 'GET, HEAD'], $e->extraHeaders());
    }
}
