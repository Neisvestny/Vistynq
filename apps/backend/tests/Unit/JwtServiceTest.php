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
        $token = $jwt->sign('user-123', 'user@example.com');

        // Manually craft a token without sub
        $header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = base64_encode(json_encode(['email' => 'user@example.com', 'iat' => time(), 'exp' => time() + 900]));
        $sig = base64_encode(hash_hmac('sha256', "$header.$payload", 'test-secret-key-that-is-long-enough-for-hs256', true));
        $noSub = "$header.$payload.$sig";

        $this->expectException(\Vistynq\Support\ApiException::class);
        $jwt->verify($noSub);
    }
}
