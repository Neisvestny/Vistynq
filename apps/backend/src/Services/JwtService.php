<?php

declare(strict_types=1);

namespace Vistynq\Services;

use DomainException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use UnexpectedValueException;
use Vistynq\Config\Config;
use Vistynq\Support\ApiException;

/**
 * Access-token JWT (HS256, TTL from ACCESS_TTL). Refresh tokens live in
 * httpOnly cookies and are handled by the auth service (roadmap R10).
 */
final class JwtService
{
    public function __construct(private readonly Config $config)
    {
    }

    public function sign(string $userId, string $email): string
    {
        $now = time();

        return JWT::encode(
            [
                'sub' => $userId,
                'email' => $email,
                'iat' => $now,
                'exp' => $now + $this->config->accessTtl(),
            ],
            $this->config->jwtSecret(),
            'HS256',
        );
    }

    /**
     * @return array{sub: string, email: string}
     *
     * @throws ApiException 401 UNAUTHORIZED / TOKEN_EXPIRED
     */
    public function verify(string $token): array
    {
        try {
            $claims = JWT::decode($token, new Key($this->config->jwtSecret(), 'HS256'));
        } catch (ExpiredException) {
            throw ApiException::unauthorized('TOKEN_EXPIRED', 'Токен истёк');
        } catch (SignatureInvalidException | DomainException | UnexpectedValueException) {
            throw ApiException::unauthorized('UNAUTHORIZED', 'Некорректный токен');
        }

        if (!is_string($claims->sub) || !is_string($claims->email ?? null)) {
            throw ApiException::unauthorized('UNAUTHORIZED', 'Некорректный токен');
        }

        return ['sub' => $claims->sub, 'email' => $claims->email];
    }
}
