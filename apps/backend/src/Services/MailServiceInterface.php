<?php

declare(strict_types=1);

namespace Vistynq\Services;

/**
 * Outbound mail contract (roadmap R16/R17): AuthService and InvitationService
 * depend on the interface, unit tests substitute a fake.
 */
interface MailServiceInterface
{
    public function sendVerificationEmail(string $to, string $token): void;

    public function sendEmailChangeEmail(string $to, string $token): void;

    public function sendPasswordResetEmail(string $to, string $token): void;

    /**
     * Уведомление о мгновенно выданном доступе.
     *
     * @param string $resourceType canvas|note
     * @param string $link         абсолютная ссылка на ресурс во фронтенде
     */
    public function sendAccessGrantedEmail(
        string $to,
        string $resourceType,
        string $resourceTitle,
        string $role,
        string $link,
    ): void;

    /**
     * Приглашение адреса без аккаунта (ссылка на регистрацию).
     *
     * @param string $resourceType canvas|note
     * @param string $link         абсолютная ссылка на регистрацию
     */
    public function sendAccessInviteEmail(
        string $to,
        string $resourceType,
        string $resourceTitle,
        string $role,
        string $link,
    ): void;
}
