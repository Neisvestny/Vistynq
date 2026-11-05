<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Vistynq\Config\Config;
use Vistynq\Services\JwtService;

final class JwtServiceTest extends TestCase
{
    private function makeJwt(string $secret = 'test-secret-key-that-is-long-enough-for-hs256'): JwtService
    {
        putenv('JWT_SECRET=' . $secret);
        $_ENV['JWT_SECRET'] = $secret;

        return new JwtService(new Config());
    }

    public function testSignAndVerify(): void
    {
        $jwt = $this->makeJwt();
        $token = $jwt->sign('user-123', 'user@example.com');

        $claims = $jwt->verify($token);
        $this->assertSame('user-123', $claims['sub']);
        $this->assertSame('user@example.com', $claims['email']);
    }

    public function testWrongSecretRejected(): void
    {
        $jwtA = $this->makeJwt('secret-key-a-that-is-long-enough-for-hs256');
        $token = $jwtA->sign('user-123', 'user@example.com');

        $jwtB = $this->makeJwt('secret-key-b-that-is-long-enough-for-hs256');

        $this->expectException(\Vistynq\Support\ApiException::class);
        $jwtB->verify($token);
    }

    public function testMalformedTokenRejected(): void
    {
        $jwt = $this->makeJwt();

        $this->expectException(\Vistynq\Support\ApiException::class);
        $jwt->verify('not-a-jwt-at-all');
    }

    public function testTamperedTokenRejected(): void
    {
        $jwt = $this->makeJwt();
        $token = $jwt->sign('user-123', 'user@example.com');

        $parts = explode('.', $token);
        $parts[1] = base64_encode(json_encode(['sub' => 'hacker', 'email' => 'hacker@evil.com', 'iat' => time(), 'exp' => time() + 900]));
        $tampered = implode('.', $parts);

        $this->expectException(\Vistynq\Support\ApiException::class);
        $jwt->verify($tampered);
    }

    public function testMissingSubRejected(): void
    {
        $jwt = $this->makeJwt();

        $token = $this->craftToken(['email' => 'user@example.com', 'iat' => time(), 'exp' => time() + 900]);

        $e = $this->captureApi(fn (): mixed => $jwt->verify($token));
        $this->assertSame('UNAUTHORIZED', $e->errorCode());
    }

    public function testMissingEmailRejected(): void
    {
        // Раньше это падало с warning "Undefined property: stdClass::$sub"
        // и отдавало 500 вместо 401 — фиксируем контракт 401.
        $jwt = $this->makeJwt();

        $token = $this->craftToken(['sub' => 'user-123', 'iat' => time(), 'exp' => time() + 900]);

        $e = $this->captureApi(fn (): mixed => $jwt->verify($token));
        $this->assertSame('UNAUTHORIZED', $e->errorCode());
    }

    public function testExpiredTokenRejectedWithTokenExpiredCode(): void
    {
        $jwt = $this->makeJwt();

        $token = $this->craftToken([
            'sub' => 'user-123',
            'email' => 'user@example.com',
            'iat' => time() - 1800,
            'exp' => time() - 900,
        ]);

        $e = $this->captureApi(fn (): mixed => $jwt->verify($token));
        $this->assertSame(401, $e->httpStatus());
        $this->assertSame('TOKEN_EXPIRED', $e->errorCode());
    }

    public function testTokenSignedForAnotherSecretRejected(): void
    {
        // Подпись тем же секретом, но другой длины/значения — уже другая подпись.
        $jwt = $this->makeJwt('test-secret-key-that-is-long-enough-for-hs256');

        $token = $this->craftToken(
            ['sub' => 'user-123', 'email' => 'user@example.com', 'iat' => time(), 'exp' => time() + 900],
            'another-secret-key-that-is-long-enough-for-hs256',
        );

        $e = $this->captureApi(fn (): mixed => $jwt->verify($token));
        $this->assertSame('UNAUTHORIZED', $e->errorCode());
    }

    /**
     * Подписывает произвольный payloadHS256-секретом (для негативных кейсов).
     *
     * @param array<string, mixed> $payload
     */
    private function craftToken(array $payload, ?string $secret = null): string
    {
        $secret ??= 'test-secret-key-that-is-long-enough-for-hs256';
        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
        $body = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$body", $secret, true)), '+/', '-_'), '=');

        return "$header.$body.$sig";
    }

    private function captureApi(callable $fn): \Vistynq\Support\ApiException
    {
        try {
            $fn();
        } catch (\Vistynq\Support\ApiException $e) {
            return $e;
        }

        $this->fail('ожидалась ApiException');
    }
}
