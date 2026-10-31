<?php

declare(strict_types=1);

namespace Vistynq\Services;

use Vistynq\Models\User;
use Vistynq\Repositories\AuthTokenRepository;
use Vistynq\Repositories\UserRepository;
use Vistynq\Support\ApiException;

/**
 * Auth slice 1 (roadmap R09): register, email verification, resend, login.
 * Refresh cookies, password reset and rate limiting land in R10.
 */
final class AuthService
{
    /** Email verification token lifetime (docs/data-model.md: 1 ч). */
    private const VERIFY_TTL_SECONDS = 3600;

    private const PURPOSE_EMAIL_VERIFY = 'email_verify';

    public function __construct(
        private readonly UserRepository $users,
        private readonly AuthTokenRepository $authTokens,
        private readonly JwtService $jwt,
        private readonly MailService $mail,
    ) {
    }

    public function register(string $email, string $password, string $displayName): void
    {
        if ($this->users->findByEmail($email) !== null) {
            throw ApiException::conflict('Email уже зарегистрирован', ['email' => ['email занят']]);
        }

        $user = $this->users->insert($email, password_hash($password, PASSWORD_DEFAULT), $displayName);

        $this->sendVerification($user->id, $user->email);
    }

    public function verifyEmail(string $token): void
    {
        $tokenRow = $this->authTokens->findValid(hash('sha256', $token), self::PURPOSE_EMAIL_VERIFY);
        if ($tokenRow === null) {
            throw ApiException::gone('Токен недействителен или истёк');
        }

        $this->authTokens->markUsed($tokenRow['id']);
        $this->users->markEmailVerified($tokenRow['user_id']);
    }

    /**
     * Always succeeds from the client's perspective: no email-existence leak (docs/api.md).
     */
    public function resendVerification(string $email): void
    {
        $user = $this->users->findByEmail($email);
        if ($user === null || $user->isEmailVerified()) {
            return;
        }

        $this->sendVerification($user->id, $user->email);
    }

    /**
     * @return array{user: User, accessToken: string}
     */
    public function login(string $email, string $password): array
    {
        $user = $this->users->findByEmail($email);
        if ($user === null || $user->passwordHash === null || !password_verify($password, $user->passwordHash)) {
            throw ApiException::unauthorized('UNAUTHORIZED', 'Неверный email или пароль');
        }

        if (!$user->isEmailVerified()) {
            throw ApiException::forbidden('EMAIL_NOT_VERIFIED', 'Email не подтверждён');
        }

        return [
            'user' => $user,
            'accessToken' => $this->jwt->sign($user->id, $user->email),
        ];
    }

    private function sendVerification(string $userId, string $email): void
    {
        $this->authTokens->invalidatePending($userId, self::PURPOSE_EMAIL_VERIFY);

        $token = bin2hex(random_bytes(32));
        $this->authTokens->create($userId, self::PURPOSE_EMAIL_VERIFY, hash('sha256', $token), self::VERIFY_TTL_SECONDS);

        $this->mail->sendVerificationEmail($email, $token);
    }
}
