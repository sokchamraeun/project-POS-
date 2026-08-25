<?php
declare(strict_types=1);

/**
 * mailer.php — PHPMailer SMTP Email Service for Password Resets
 * Delivers responsive, branded HTML emails via Gmail / custom SMTP
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/db.php';

final class PasswordResetMailer
{
    private array $smtpConfig;

    public function __construct()
    {
        $this->smtpConfig = $this->loadSmtpConfig();
    }

    /**
     * Load SMTP configurations from database or environment
     */
    private function loadSmtpConfig(): array
    {
        $defaults = [
            'enabled'    => true,
            'host'       => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
            'port'       => (int)(getenv('SMTP_PORT') ?: 587),
            'secure'     => getenv('SMTP_SECURE') ?: 'tls',
            'username'   => getenv('SMTP_USER') ?: '',
            'password'   => getenv('SMTP_PASS') ?: '',
            'from_email' => getenv('MAIL_FROM_EMAIL') ?: 'noreply@birdsnest.com',
            'from_name'  => getenv('MAIL_FROM_NAME') ?: "Bird's Nest Coffee POS",
        ];

        if (is_file(__DIR__ . '/mail_config.local.php')) {
            require __DIR__ . '/mail_config.local.php';
            if (isset($smtp_enabled)) $defaults['enabled']    = (bool)$smtp_enabled;
            if (!empty($smtp_host))    $defaults['host']       = (string)$smtp_host;
            if (!empty($smtp_port))    $defaults['port']       = (int)$smtp_port;
            if (!empty($smtp_secure))  $defaults['secure']     = (string)$smtp_secure;
            if (!empty($smtp_user))    $defaults['username']   = (string)$smtp_user;
            if (!empty($smtp_pass))    $defaults['password']   = (string)$smtp_pass;
            if (!empty($from_email))   $defaults['from_email'] = (string)$from_email;
            if (!empty($from_name))    $defaults['from_name']  = (string)$from_name;
        }

        try {
            $pdo = Database::getPdo();
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%' OR setting_key LIKE 'mail_%'");
            while ($row = $stmt->fetch()) {
                $k = $row['setting_key'];
                $v = $row['setting_value'];
                if ($k === 'smtp_enabled' && !isset($smtp_enabled)) $defaults['enabled']    = ($v === '1' || $v === 'true');
                if ($k === 'smtp_host' && !empty($v) && empty($defaults['username'])) $defaults['host']       = $v;
                if ($k === 'smtp_port' && !empty($v) && empty($defaults['username'])) $defaults['port']       = (int)$v;
                if ($k === 'smtp_secure' && !empty($v) && empty($defaults['username'])) $defaults['secure']   = $v;
                if ($k === 'smtp_user' && !empty($v)) $defaults['username']   = $v;
                if ($k === 'smtp_pass' && !empty($v)) $defaults['password']   = $v;
                if ($k === 'mail_from_email' && !empty($v)) $defaults['from_email'] = $v;
                if ($k === 'mail_from_name' && !empty($v))  $defaults['from_name']  = $v;
            }
        } catch (Throwable) {
            // Use defaults if settings table not yet ready
        }

        return $defaults;
    }

    /**
     * Send Password Reset Link Email
     */
    public function sendResetLink(string $toEmail, string $toName, string $resetUrl, int $expiresMinutes = 15): bool
    {
        $mail = new PHPMailer(true);

        try {
            if ($this->smtpConfig['enabled'] && !empty($this->smtpConfig['username']) && !empty($this->smtpConfig['password'])) {
                $mail->isSMTP();
                $mail->Host       = $this->smtpConfig['host'];
                $mail->SMTPAuth   = true;
                $mail->Username   = $this->smtpConfig['username'];
                $mail->Password   = $this->smtpConfig['password'];
                $mail->SMTPSecure = ($this->smtpConfig['secure'] === 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = $this->smtpConfig['port'];
                $mail->Timeout    = 15;
                $mail->CharSet    = 'UTF-8';
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer'       => false,
                        'verify_peer_name'  => false,
                        'allow_self_signed' => true
                    ]
                ];
                $fromEmail = !empty($this->smtpConfig['from_email']) ? $this->smtpConfig['from_email'] : $this->smtpConfig['username'];
            } else {
                $mail->isMail();
                $mail->CharSet = 'UTF-8';
                $fromEmail = !empty($this->smtpConfig['from_email']) ? $this->smtpConfig['from_email'] : 'noreply@birdsnest.com';
            }

            $mail->setFrom($fromEmail, $this->smtpConfig['from_name']);
            $mail->addAddress($toEmail, $toName);
            $mail->Subject = "Bird's Nest Coffee — Password Reset Request";

            $htmlTemplate = $this->buildHtmlTemplate($toName, $resetUrl, $expiresMinutes);
            $mail->isHTML(true);
            $mail->Body    = $htmlTemplate;
            $mail->AltBody = "Hello {$toName},\n\nYou recently requested to reset your password for Bird's Nest Coffee POS.\n\nClick the link below to reset your password (valid for {$expiresMinutes} minutes):\n{$resetUrl}\n\nIf you did not request this, please ignore this email.";

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("[Mailer Error] PHPMailer failed: " . $mail->ErrorInfo . " | " . $e->getMessage());
            
            // Native mail fallback
            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Content-type: text/html; charset=UTF-8\r\n";
            $headers .= "From: " . $this->smtpConfig['from_name'] . " <" . $this->smtpConfig['from_email'] . ">\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion();

            return @mail($toEmail, "Bird's Nest Coffee — Password Reset Request", $this->buildHtmlTemplate($toName, $resetUrl, $expiresMinutes), $headers);
        }
    }

    /**
     * Render high-converting responsive HTML email template
     */
    private function buildHtmlTemplate(string $name, string $resetUrl, int $expiresMinutes): string
    {
        $safeName     = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeUrl      = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
        $currentYear  = date('Y');

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Reset Your Password</title>
        <style>
            body { margin: 0; padding: 24px 12px; background-color: #040a08; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #ffffff; }
            .container { max-width: 560px; margin: 0 auto; background: #081410; border: 1px solid rgba(0, 245, 160, 0.25); border-radius: 20px; overflow: hidden; box-shadow: 0 20px 50px rgba(0,0,0,0.8); }
            .header { background: linear-gradient(135deg, rgba(0,245,160,0.15) 0%, rgba(8,20,16,0.95) 100%); padding: 32px 24px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.06); }
            .brand-title { font-size: 20px; font-weight: 800; color: #00f5a0; letter-spacing: 1px; margin: 0 0 4px; }
            .brand-sub { font-size: 11px; color: #708b82; letter-spacing: 2px; text-transform: uppercase; font-weight: 700; margin: 0; }
            .content { padding: 36px 30px; }
            .greeting { font-size: 17px; font-weight: 700; color: #ffffff; margin-bottom: 14px; }
            .text { font-size: 14px; color: #a0aec0; line-height: 1.6; margin-bottom: 24px; }
            .btn-wrap { text-align: center; margin: 32px 0; }
            .btn-reset { display: inline-block; background: #00f5a0; color: #03140c !important; text-decoration: none; font-weight: 800; font-size: 15px; padding: 14px 36px; border-radius: 12px; letter-spacing: 0.5px; box-shadow: 0 8px 24px rgba(0,245,160,0.35); }
            .timer-badge { display: flex; align-items: center; justify-content: center; gap: 8px; background: rgba(234, 179, 8, 0.08); border: 1px solid rgba(234, 179, 8, 0.25); border-radius: 12px; padding: 12px 16px; color: #facc15; font-size: 12.5px; line-height: 1.4; margin-bottom: 24px; }
            .url-box { background: rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.06); border-radius: 10px; padding: 12px; font-size: 11.5px; color: #708b82; word-break: break-all; font-family: monospace; }
            .footer { padding: 22px 24px; text-align: center; border-top: 1px solid rgba(255,255,255,0.06); background: rgba(0,0,0,0.3); font-size: 11px; color: #708b82; line-height: 1.5; }
        </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1 class="brand-title">BIRD'S NEST COFFEE POS</h1>
                    <p class="brand-sub">ACCOUNT SECURITY</p>
                </div>
                <div class="content">
                    <div class="greeting">ជំរាបសួរ {$safeName} (Hello {$safeName}),</div>
                    <p class="text">
                        យើងបានទទួលការស្នើសុំកំណត់ពាក្យសម្ងាត់ឡើងវិញសម្រាប់គណនីរបស់អ្នក។ សូមចុចប៊ូតុងខាងក្រោមដើម្បីកំណត់ពាក្យសម្ងាត់ថ្មី៖<br>
                        (We received a password reset request for your account. Please click the button below to set a new password:)
                    </p>

                    <div class="btn-wrap">
                        <a href="{$safeUrl}" class="btn-reset" target="_blank">កំណត់ពាក្យសម្ងាត់ថ្មី (Reset Password) &rarr;</a>
                    </div>

                    <div class="timer-badge">
                        <span>⏰ តំណភ្ជាប់នេះមានសុពលភាពរយៈពេល <strong>{$expiresMinutes} នាទី</strong> ប៉ុណ្ណោះ (Valid for {$expiresMinutes} minutes only).</span>
                    </div>

                    <p class="text" style="font-size: 12px; margin-bottom: 8px;">ឬចម្លងតំណភ្ជាប់ខាងក្រោមទៅកាន់ Browser របស់អ្នក (Or copy the link below):</p>
                    <div class="url-box">{$safeUrl}</div>

                    <p class="text" style="font-size: 12px; color: #718096; margin-top: 24px; margin-bottom: 0;">
                        ⚠️ ប្រសិនបើអ្នកមិនបានស្នើសុំទេ សូមកុំបារម្ភ គណនីរបស់អ្នកនៅតែមានសុវត្ថិភាព ហើយគ្មានការផ្លាស់ប្តូរណាមួយកើតឡើងឡើយ។
                    </p>
                </div>
                <div class="footer">
                    &copy; {$currentYear} Bird's Nest Coffee POS. All rights reserved.<br>
                    Automated security message. Please do not reply.
                </div>
            </div>
        </body>
        </html>
        HTML;
    }
}
