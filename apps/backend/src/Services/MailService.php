<?php

declare(strict_types=1);

namespace Vistynq\Services;

use PHPMailer\PHPMailer\PHPMailer;
use Vistynq\Config\Config;

/**
 * SMTP outbound mail (dev: Mailpit on SMTP_HOST:SMTP_PORT).
 */
final class MailService
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
