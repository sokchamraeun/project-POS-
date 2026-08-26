<?php
// mail_helper.php — Centralized Email Delivery Helper for Bird's Nest POS

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
    } elseif (file_exists(__DIR__ . '/lib/phpmailer/PHPMailer.php')) {
        require_once __DIR__ . '/lib/phpmailer/Exception.php';
        require_once __DIR__ . '/lib/phpmailer/PHPMailer.php';
        require_once __DIR__ . '/lib/phpmailer/SMTP.php';
    }
}
require_once __DIR__ . '/config.php';

/**
 * Get SMTP settings from database or mail_config.local.php
 */
function get_mail_settings() {
    global $conn;
    $settings = [
        'smtp_enabled' => true,
        'smtp_host'    => 'smtp.gmail.com',
        'smtp_port'    => 587,
        'smtp_secure'  => 'tls',
        'smtp_user'    => 'sokchamraeunid@gmail.com',
        'smtp_pass'    => 'axnojdgzlxgwvksm',
        'from_email'   => 'sokchamraeunid@gmail.com',
        'from_name'    => "Bird's Nest Coffee POS"
    ];

    // 1. Check local config file override first (git-ignored)
    if (is_file(__DIR__ . '/mail_config.local.php')) {
        require __DIR__ . '/mail_config.local.php';
        if (isset($smtp_enabled)) $settings['smtp_enabled'] = (bool)$smtp_enabled;
        if (!empty($smtp_host))    $settings['smtp_host']    = (string)$smtp_host;
        if (!empty($smtp_port))    $settings['smtp_port']    = (int)$smtp_port;
        if (!empty($smtp_secure))  $settings['smtp_secure']  = (string)$smtp_secure;
        if (!empty($smtp_user))    $settings['smtp_user']    = (string)$smtp_user;
        if (!empty($smtp_pass))    $settings['smtp_pass']    = (string)$smtp_pass;
        if (!empty($from_email))   $settings['from_email']   = (string)$from_email;
        if (!empty($from_name))    $settings['from_name']    = (string)$from_name;
    }

    // 2. Fallback to database settings
    if ($conn) {
        $res = $conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%' OR setting_key LIKE 'mail_%'");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $k = $r['setting_key'];
                $v = $r['setting_value'];
                if ($k === 'smtp_enabled' && !isset($smtp_enabled)) $settings['smtp_enabled'] = ($v === '1' || $v === 'true' || $v === 'yes');
                if ($k === 'smtp_host' && !empty($v))       $settings['smtp_host'] = $v;
                if ($k === 'smtp_port' && !empty($v))       $settings['smtp_port'] = (int)$v;
                if ($k === 'smtp_secure' && !empty($v))     $settings['smtp_secure'] = $v;
                if ($k === 'smtp_user' && !empty($v))       $settings['smtp_user'] = $v;
                if ($k === 'smtp_pass' && !empty($v))       $settings['smtp_pass'] = $v;
                if ($k === 'mail_from_email' && !empty($v)) $settings['from_email'] = $v;
                if ($k === 'mail_from_name' && !empty($v))  $settings['from_name'] = $v;
            }
        }
    }

    // Auto-enable SMTP if credentials are present
    if (!empty($settings['smtp_user']) && !empty($settings['smtp_pass'])) {
        $settings['smtp_enabled'] = true;
    }

    return $settings;
}

/**
 * Send an email via PHPMailer (SMTP with dual-port fallback or PHP mail fallback)
 */
function send_app_email($to_email, $to_name, $subject, $html_content, $plain_content = '') {
    $mail_cfg = get_mail_settings();
    $last_error = '';

    // Check if PHPMailer is available
    if (class_exists(PHPMailer::class)) {
        // Try configured port first, fallback to SSL 465 if 587 blocked on hosting
        $ports_to_try = [
            ['port' => $mail_cfg['smtp_port'], 'secure' => $mail_cfg['smtp_secure']],
            ['port' => 465, 'secure' => 'ssl'],
            ['port' => 587, 'secure' => 'tls']
        ];

        $tried = [];
        foreach ($ports_to_try as $p_cfg) {
            $key = $p_cfg['port'] . '-' . $p_cfg['secure'];
            if (isset($tried[$key])) continue;
            $tried[$key] = true;

            $mail = new PHPMailer(true);
            try {
                if ($mail_cfg['smtp_enabled'] && !empty($mail_cfg['smtp_user']) && !empty($mail_cfg['smtp_pass'])) {
                    $mail->isSMTP();
                    $mail->Host       = $mail_cfg['smtp_host'];
                    $mail->SMTPAuth   = true;
                    $mail->Username   = $mail_cfg['smtp_user'];
                    $mail->Password   = $mail_cfg['smtp_pass'];
                    $mail->SMTPSecure = ($p_cfg['secure'] === 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = (int)$p_cfg['port'];
                    $mail->Timeout    = 10;
                    $mail->CharSet    = 'UTF-8';

                    // Critical for Windows XAMPP SSL handshake
                    $mail->SMTPOptions = [
                        'ssl' => [
                            'verify_peer'       => false,
                            'verify_peer_name'  => false,
                            'allow_self_signed' => true
                        ]
                    ];

                    $from_email = !empty($mail_cfg['from_email']) ? $mail_cfg['from_email'] : $mail_cfg['smtp_user'];
                } else {
                    $mail->isMail();
                    $mail->CharSet = 'UTF-8';
                    $from_email = !empty($mail_cfg['from_email']) ? $mail_cfg['from_email'] : 'noreply@birdsnest.com';
                }

                // Recipients
                $mail->setFrom($from_email, $mail_cfg['from_name']);
                $mail->addAddress($to_email, $to_name);

                // Content
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body    = $html_content;
                $mail->AltBody = !empty($plain_content) ? $plain_content : strip_tags($html_content);

                $mail->send();
                return ['success' => true, 'message' => 'Email sent successfully'];
            } catch (Throwable $e) {
                $last_error = ($mail->ErrorInfo ?? '') . ' | ' . $e->getMessage();
                error_log("[PHPMailer Error Port {$p_cfg['port']}] " . $last_error);
            }
        }
    }

    // Fallback to PHP native mail()
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . $mail_cfg['from_name'] . " <" . (!empty($mail_cfg['from_email']) ? $mail_cfg['from_email'] : 'noreply@birdsnest.com') . ">\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    $native_sent = @mail($to_email, $subject, $html_content, $headers);
    if ($native_sent) {
        return ['success' => true, 'message' => 'Email sent via native mailer'];
    }
    return ['success' => false, 'error' => $last_error ?: 'Could not send email via SMTP or native mailer'];
}

/**
 * Send Temporary Password Email with Modern Dark Glassmorphic Template
 */
function send_temporary_password_email($to_email, $username, $temp_password) {
    $subject = "Bird's Nest Coffee — Your Temporary Password";
    
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
    <meta charset="UTF-8">
    <title>Temporary Password</title>
    <style>
        body { margin: 0; padding: 24px; background-color: #040a08; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #ffffff; }
        .container { max-width: 540px; margin: 0 auto; background: #081410; border: 1px solid rgba(0, 245, 160, 0.25); border-radius: 20px; overflow: hidden; box-shadow: 0 20px 40px rgba(0,0,0,0.6); }
        .header { background: linear-gradient(135deg, rgba(0,245,160,0.15) 0%, rgba(8,20,16,0.9) 100%); padding: 28px 24px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.06); }
        .brand { font-size: 18px; font-weight: 800; color: #00f5a0; letter-spacing: 1px; margin: 0; }
        .sub { font-size: 11px; color: #708b82; letter-spacing: 2px; text-transform: uppercase; font-weight: 700; margin-top: 4px; }
        .content { padding: 32px 28px; }
        .greeting { font-size: 16px; font-weight: 700; color: #ffffff; margin-bottom: 12px; }
        .text { font-size: 13.5px; color: #9bb0a8; line-height: 1.6; margin-bottom: 24px; }
        .code-box { background: rgba(0, 245, 160, 0.06); border: 1.5px dashed #00f5a0; border-radius: 14px; padding: 18px; text-align: center; margin: 24px 0; }
        .code-label { font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px; color: #708b82; font-weight: 700; margin-bottom: 6px; }
        .code-val { font-size: 26px; font-weight: 800; color: #00f5a0; font-family: monospace; letter-spacing: 4px; }
        .notice { font-size: 12px; color: #eab308; background: rgba(234, 179, 8, 0.08); border-left: 3px solid #eab308; padding: 10px 14px; border-radius: 0 8px 8px 0; margin-top: 20px; line-height: 1.5; }
        .footer { padding: 20px; text-align: center; border-top: 1px solid rgba(255,255,255,0.06); font-size: 11px; color: #708b82; }
    </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <div class="brand">BIRD\'S NEST COFFEE POS</div>
                <div class="sub">PASSWORD RECOVERY</div>
            </div>
            <div class="content">
                <div class="greeting">ជំរាបសួរ ' . htmlspecialchars($username) . ' (Hello ' . htmlspecialchars($username) . '),</div>
                <div class="text">
                    យើងបានទទួលការស្នើសុំកំណត់ពាក្យសម្ងាត់ឡើងវិញសម្រាប់គណនីរបស់អ្នក។ នេះជាលេខសម្ងាត់បណ្តោះអាសន្នរបស់អ្នក៖<br>
                    (Here is your temporary password to log in and create a new password:)
                </div>
                
                <div class="code-box">
                    <div class="code-label">TEMPORARY PASSWORD / លេខសម្ងាត់បណ្តោះអាសន្ន</div>
                    <div class="code-val">' . htmlspecialchars($temp_password) . '</div>
                </div>

                <div class="notice">
                    ⚠️ <strong>ចំណាំ (Note):</strong> លេខសម្ងាត់នេះអាចប្រើបានតែម្តងគត់។ សូមបញ្ចូលលេខនេះទៅក្នុងទម្រង់ដើម្បីផ្លាស់ប្តូរលេខសម្ងាត់ថ្មីរបស់អ្នកភ្លាមៗ។
                </div>
            </div>
            <div class="footer">
                © ' . date('Y') . ' Bird\'s Nest Coffee POS. All rights reserved.
            </div>
        </div>
    </body>
    </html>';

    return send_app_email($to_email, $username, $subject, $html, "Your temporary password is: " . $temp_password);
}
