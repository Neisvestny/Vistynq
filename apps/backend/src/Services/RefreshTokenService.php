<?php

declare(strict_types=1);

namespace Vistynq\Services;

use DateTimeImmutable;
use Throwable;
use Vistynq\Config\Config;
use Vistynq\Repositories\RefreshTokenRepository;
use Vistynq\Repositories\UserRepository;
use Vistynq\Support\ApiException;
use Vistynq\Support\Database;

final class RefreshTokenService
{
    private const REFRESH_COOKIE_NAME = 'vistynq_refresh';

    public function __construct(
        private readonly Config $config,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly UserRepository $users,
        private readonly JwtService $jwt,
        private readonly Database $db,
    ) {
    }

    /**
     * @return array{user: \Vistynq\Models\User, accessToken: string, cookie: string}
     */
    public function issue(string $userId): array
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw ApiException::unauthorized();
        }

        $familyId = $this->uuid();
        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $this->refreshTokens->create($userId, $familyId, $hash, $this->config->refreshTtl());

        return [
            'user' => $user,
            'accessToken' => $this->jwt->sign($user->id, $user->email),
            'cookie' => $this->buildCookie($raw, $this->config->refreshTtl()),
        ];
    }

    /**
     * @return array{user: \Vistynq\Models\User, accessToken: string, cookie: string}
     */
    public function rotate(string $rawToken): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $hash = hash('sha256', $rawToken);
            // Блокируем строку для сериализации конкурентных ротаций одного токена.
            $tokenRow = $this->refreshTokens->findByHashForUpdate($hash);
            if ($tokenRow === null) {
                $pdo->rollBack();
                throw ApiException::unauthorized();
            }

            if ($tokenRow['revoked_at'] !== null || $tokenRow['replaced_by'] !== null) {
                // reuse detected - revoke entire family and persist it
                $this->refreshTokens->revokeFamily($tokenRow['family_id']);
                $pdo->commit();
                throw ApiException::unauthorized();
            }

            $expiresAt = new DateTimeImmutable($tokenRow['expires_at']);
            if ($expiresAt < new DateTimeImmutable()) {
                $this->refreshTokens->revokeFamily($tokenRow['family_id']);
                $pdo->commit();
                throw ApiException::unauthorized();
            }

            $user = $this->users->findById($tokenRow['user_id']);
            if ($user === null) {
                $this->refreshTokens->revokeFamily($tokenRow['family_id']);
                $pdo->commit();
                throw ApiException::unauthorized();
            }

            $familyId = $tokenRow['family_id'];
            $rawNew = bin2hex(random_bytes(32));
            $hashNew = hash('sha256', $rawNew);
            $newId = $this->refreshTokens->create($user->id, $familyId, $hashNew, $this->config->refreshTtl());
            $this->refreshTokens->markReplaced($tokenRow['id'], $newId);

            $pdo->commit();

            return [
                'user' => $user,
                'accessToken' => $this->jwt->sign($user->id, $user->email),
                'cookie' => $this->buildCookie($rawNew, $this->config->refreshTtl()),
            ];
        } catch (ApiException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function revokeByRaw(string $rawToken): void
    {
        $hash = hash('sha256', $rawToken);
        $tokenRow = $this->refreshTokens->findByHash($hash);
        if ($tokenRow === null) {
            return;
        }
        $this->refreshTokens->revokeFamily($tokenRow['family_id']);
    }

    public function revokeAllForUser(string $userId): void
    {
        $this->refreshTokens->revokeAllForUser($userId);
    }

    public function clearCookie(): string
    {
        $parts = [
            self::REFRESH_COOKIE_NAME . '=',
            'Path=/api/v1/auth/refresh',
            'HttpOnly',
            'SameSite=Lax',
            'Max-Age=0',
            'Expires=Thu, 01 Jan 1970 00:00:00 GMT',
        ];
        if ($this->config->cookieSecure()) {
            $parts[] = 'Secure';
        }
        $domain = $this->config->cookieDomain();
        if ($domain !== '') {
            $parts[] = 'Domain=' . $domain;
        }

        return implode('; ', $parts);
    }

    private function buildCookie(string $raw, int $ttl): string
    {
        $parts = [
            self::REFRESH_COOKIE_NAME . '=' . $raw,
            'Path=/api/v1/auth/refresh',
            'HttpOnly',
            'SameSite=Lax',
            'Max-Age=' . $ttl,
        ];
        if ($this->config->cookieSecure()) {
            $parts[] = 'Secure';
        }
        $domain = $this->config->cookieDomain();
        if ($domain !== '') {
            $parts[] = 'Domain=' . $domain;
        }

        return implode('; ', $parts);
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
