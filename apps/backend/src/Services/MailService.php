<?php

declare(strict_types=1);

namespace Vistynq\Services;

use PHPMailer\PHPMailer\PHPMailer;
use Vistynq\Config\Config;

/**
 * SMTP outbound mail (dev: Mailpit on SMTP_HOST:SMTP_PORT).
 */
final class MailService implements MailServiceInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function sendVerificationEmail(string $to, string $token): void
    {
        $link = $this->config->frontendUrl() . '/verify-email?token=' . rawurlencode($token);

        $this->send(
            $to,
            'Подтвердите email — Vistynq',
            sprintf(
                "Здравствуйте!\n\nПодтвердите email, перейдя по ссылке:\n%s\n\nСсылка действует 1 час. Если вы не регистрировались — просто проигнорируйте письмо.\n",
                $link,
            ),
            sprintf(
                '<p>Здравствуйте!</p><p>Подтвердите email: <a href="%s">Подтвердить</a></p><p>Ссылка действует 1 час. Если вы не регистрировались — проигнорируйте письмо.</p>',
                htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ),
        );
    }

    /**
     * Письмо на **новый** адрес с токеном purpose=email_change
     * (docs/architecture.md §2: email меняется после POST /auth/verify-email).
     */
    public function sendEmailChangeEmail(string $to, string $token): void
    {
        $link = $this->config->frontendUrl() . '/verify-email?token=' . rawurlencode($token);

        $this->send(
            $to,
            'Подтвердите смену email — Vistynq',
            sprintf(
                "Здравствуйте!\n\nПодтвердите смену email, перейдя по ссылке:\n%s\n\nСсылка действует 1 час. Если вы не запрашивали смену — проигнорируйте письмо.\n",
                $link,
            ),
            sprintf(
                '<p>Здравствуйте!</p><p>Подтвердите смену email: <a href="%s">Подтвердить</a></p><p>Ссылка действует 1 час. Если вы не запрашивали смену — проигнорируйте письмо.</p>',
                htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ),
        );
    }

    public function sendPasswordResetEmail(string $to, string $token): void
    {
        $link = $this->config->frontendUrl() . '/reset-password?token=' . rawurlencode($token);

        $this->send(
            $to,
            'Сброс пароля — Vistynq',
            sprintf(
                "Здравствуйте!\n\nДля сброса пароля перейдите по ссылке:\n%s\n\nСсылка действует 1 час. Если вы не запрашивали сброс — проигнорируйте письмо.\n",
                $link,
            ),
            sprintf(
                '<p>Здравствуйте!</p><p>Для сброса пароля перейдите по ссылке: <a href="%s">Сбросить пароль</a></p><p>Ссылка действует 1 час. Если вы не запрашивали сброс — проигнорируйте письмо.</p>',
                htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ),
        );
    }

    public function sendAccessGrantedEmail(
        string $to,
        string $resourceType,
        string $resourceTitle,
        string $role,
        string $link,
    ): void {
        $target = self::resourceTarget($resourceType);
        $roleLabel = self::roleLabel($role);
        $safeTitle = htmlspecialchars($resourceTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeLink = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $this->send(
            $to,
            'Вам открыли доступ — Vistynq',
            sprintf(
                "Здравствуйте!\n\nВам открыли доступ (%s) к %s «%s».\n\nОткрыть: %s\n",
                $roleLabel,
                $target,
                $resourceTitle,
                $link,
            ),
            sprintf(
                '<p>Здравствуйте!</p><p>Вам открыли доступ (%s) к %s «%s».</p><p><a href="%s">Открыть</a></p>',
                $roleLabel,
                $target,
                $safeTitle,
                $safeLink,
            ),
        );
    }

    public function sendAccessInviteEmail(
        string $to,
        string $resourceType,
        string $resourceTitle,
        string $role,
        string $link,
    ): void {
        $target = self::resourceTarget($resourceType);
        $roleLabel = self::roleLabel($role);
        $safeTitle = htmlspecialchars($resourceTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeLink = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $this->send(
            $to,
            'Приглашение в Vistynq',
            sprintf(
                "Здравствуйте!\n\nВас пригласили (%s) к %s «%s».\n\nЗарегистрируйтесь и подтвердите email, чтобы получить доступ: %s\n",
                $roleLabel,
                $target,
                $resourceTitle,
                $link,
            ),
            sprintf(
                '<p>Здравствуйте!</p><p>Вас пригласили (%s) к %s «%s».</p><p><a href="%s">Зарегистрироваться</a></p>',
                $roleLabel,
                $target,
                $safeTitle,
                $safeLink,
            ),
        );
    }

    private static function resourceTarget(string $resourceType): string
    {
        return $resourceType === 'note' ? 'заметке' : 'канвасу';
    }

    private static function roleLabel(string $role): string
    {
        return $role === 'editor' ? 'редактирование' : 'просмотр';
    }

    private function send(string $to, string $subject, string $text, string $html): void
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->config->smtpHost();
        $mail->Port = $this->config->smtpPort();
        $mail->CharSet = 'UTF-8';

        if ($this->config->smtpUser() !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = $this->config->smtpUser();
            $mail->Password = $this->config->smtpPass();
        }

        $mail->setFrom($this->config->mailFrom(), 'Vistynq');
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $text;
        $mail->isHTML(true);

        try {
            $mail->send();
        } catch (\Throwable $e) {
            if (!$this->config->isProd()) {
                error_log('[mail] SMTP send failed (non-prod, ignoring): ' . $e->getMessage());
                return;
            }
            throw $e;
        }
    }
}
