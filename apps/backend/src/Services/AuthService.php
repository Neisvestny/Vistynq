<?php

declare(strict_types=1);

namespace Vistynq\Services;

use RuntimeException;
use Vistynq\Models\User;
use Vistynq\Repositories\AuthTokenRepositoryInterface;
use Vistynq\Repositories\UserRepositoryInterface;
use Vistynq\Support\ApiException;

final class AuthService
{
    private const VERIFY_TTL_SECONDS = 3600;
    private const RESET_TTL_SECONDS = 3600;

    private const PURPOSE_EMAIL_VERIFY = 'email_verify';
    private const PURPOSE_EMAIL_CHANGE = 'email_change';
    private const PURPOSE_PASSWORD_RESET = 'password_reset';

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly AuthTokenRepositoryInterface $authTokens,
        private readonly JwtService $jwt,
        private readonly MailServiceInterface $mail,
        private readonly RefreshTokenService $refreshTokens,
        private readonly InvitationService $invitations,
    ) {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('ext-sodium is required for argon2id password hashing');
        }
    }

    /**
     * Хеш пароля argon2id. Требует ext-sodium (проверяется в конструкторе).
     */
    private static function hashPassword(string $password): string
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        if ($hash === false) {
            throw new RuntimeException('Не удалось вычислить хеш пароля');
        }

        return $hash;
    }

    public function register(string $email, string $password, string $displayName): void
    {
        if ($this->users->findByEmail($email) !== null) {
            throw ApiException::conflict('Email уже зарегистрирован', ['email' => ['email занят']]);
        }

        $user = $this->users->insert($email, self::hashPassword($password), $displayName);

        // docs/ROADMAP.md: «войти без подтверждённого email нельзя» — скипа
        // для dev нет, письмо уходит всегда (dev/test читают его из Mailpit).
        $this->sendVerification($user->id, $user->email);
    }

    public function verifyEmail(string $token): void
    {
        $tokenHash = hash('sha256', $token);

        $tokenRow = $this->authTokens->findValid($tokenHash, self::PURPOSE_EMAIL_VERIFY);
        if ($tokenRow !== null) {
            $this->authTokens->markUsed($tokenRow['id']);
            $this->users->markEmailVerified($tokenRow['user_id']);

            // pending-инвайты на подтверждённый email превращаются в доступы
            // (R16). Best-effort: подтверждение email не должно откатываться
            // из-за сбоя выдачи доступа.
            $user = $this->users->findById($tokenRow['user_id']);
            if ($user !== null) {
                $this->resolveInvitations($user->email, $user->id);
            }

            return;
        }

        $tokenRow = $this->authTokens->findValid($tokenHash, self::PURPOSE_EMAIL_CHANGE);
        if ($tokenRow !== null) {
            $this->applyEmailChange($tokenRow['id'], $tokenRow['user_id']);

            return;
        }

        throw ApiException::gone('Токен недействителен или истёк');
    }

    /**
     * Переносит pending_email в email. Пользователь остаётся верифицированным
     * всё время (docs/data-model.md §31): адрес подтверждён по старому.
     */
    private function applyEmailChange(string $tokenId, string $userId): void
    {
        $user = $this->users->findById($userId);
        if ($user === null || $user->pendingEmail === null) {
            throw ApiException::gone('Токен недействителен или истёк');
        }

        $newEmail = $user->pendingEmail;

        // Гонка: между PATCH /profile и подтверждением кто-то мог занять адрес
        // (в т.ч. зарегистрировавшись). pending_email не чистим — юзер сможет
        // выбрать другой адрес повторным PATCH.
        if ($this->users->emailTaken($user->pendingEmail, $userId)) {
            throw ApiException::conflict('Email уже занят', ['email' => ['email занят']]);
        }

        $this->authTokens->markUsed($tokenId);
        $this->users->applyPendingEmail($userId);

        // Инвайты, отправленные на новый адрес, резолвятся после его
        // подтверждения (R16).
        $this->resolveInvitations($newEmail, $userId);
    }

    private function resolveInvitations(string $email, string $userId): void
    {
        try {
            $this->invitations->acceptPendingForEmail($email, $userId);
        } catch (\Throwable $e) {
            error_log('[auth] invitation resolve failed: ' . $e->getMessage());
        }
    }

    /**
     * PATCH /profile: имя меняется сразу, email — через pending_email
     * (docs/api.md §Auth и профиль, architecture.md §2).
     *
     * @return User текущее состояние пользователя (с pendingEmail)
     */
    public function updateProfile(string $userId, ?string $displayName, ?string $email): User
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw ApiException::unauthorized();
        }

        if ($displayName !== null && $displayName !== $user->displayName) {
            $this->users->updateDisplayName($userId, $displayName);
        }

        if ($email !== null && strcasecmp($email, $user->email) !== 0) {
            if ($this->users->emailTaken($email, $userId)) {
                throw ApiException::conflict('Email уже занят', ['email' => ['email занят']]);
            }

            $this->users->setPendingEmail($userId, $email);

            // Старый токен смены недействителен: подтверждается последний адрес.
            $this->authTokens->invalidatePending($userId, self::PURPOSE_EMAIL_CHANGE);
            $token = bin2hex(random_bytes(32));
            $this->authTokens->create($userId, self::PURPOSE_EMAIL_CHANGE, hash('sha256', $token), self::VERIFY_TTL_SECONDS);

            $this->mail->sendEmailChangeEmail($email, $token);
        }

        $updated = $this->users->findById($userId);
        if ($updated === null) {
            throw ApiException::unauthorized();
        }

        return $updated;
    }

    public function resendVerification(string $email): void
    {
        $user = $this->users->findByEmail($email);
        if ($user === null || $user->isEmailVerified()) {
            return;
        }

        $this->sendVerification($user->id, $user->email);
    }

    /**
     * @return array{user: User, accessToken: string, cookie: string}
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

        return $this->refreshTokens->issue($user->id);
    }

    public function forgotPassword(string $email): void
    {
        $user = $this->users->findByEmail($email);
        if ($user === null) {
            return;
        }

        $this->authTokens->invalidatePending($user->id, self::PURPOSE_PASSWORD_RESET);

        $token = bin2hex(random_bytes(32));
        $this->authTokens->create($user->id, self::PURPOSE_PASSWORD_RESET, hash('sha256', $token), self::RESET_TTL_SECONDS);

        $this->mail->sendPasswordResetEmail($user->email, $token);
    }

    public function resetPassword(string $token, string $password): void
    {
        $tokenRow = $this->authTokens->findValid(hash('sha256', $token), self::PURPOSE_PASSWORD_RESET);
        if ($tokenRow === null) {
            throw ApiException::gone('Токен недействителен или истёк');
        }

        $this->authTokens->markUsed($tokenRow['id']);
        $this->users->updatePassword($tokenRow['user_id'], self::hashPassword($password));
        $this->refreshTokens->revokeAllForUser($tokenRow['user_id']);
    }

    public function refresh(string $rawRefreshToken): array
    {
        return $this->refreshTokens->rotate($rawRefreshToken);
    }

    public function logout(string $rawRefreshToken): string
    {
        if ($rawRefreshToken !== '') {
            $this->refreshTokens->revokeByRaw($rawRefreshToken);
        }

        return $this->refreshTokens->clearCookie();
    }

    private function sendVerification(string $userId, string $email): void
    {
        $this->authTokens->invalidatePending($userId, self::PURPOSE_EMAIL_VERIFY);

        $token = bin2hex(random_bytes(32));
        $this->authTokens->create($userId, self::PURPOSE_EMAIL_VERIFY, hash('sha256', $token), self::VERIFY_TTL_SECONDS);

        $this->mail->sendVerificationEmail($email, $token);
    }
}
