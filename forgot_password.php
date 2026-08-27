<?php
declare(strict_types=1);

/**
 * forgot_password.php — Interactive 2-Step Password Recovery
 * Step 1: Input Email -> Generates & sends temporary code to email -> Transitions immediately to Step 2
 * Step 2: Input Current/Temp Password from email + New Password + Confirm -> Saves and returns to Login
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail_helper.php';
require_once __DIR__ . '/lang.php';

global $conn;

// ── ENSURE USERS TABLE SCHEMA IS COMPATIBLE ACROSS ALL MYSQL VERSIONS ──
try {
    if (isset($conn) && $conn instanceof mysqli) {
        $existing_cols = [];
        $col_res = $conn->query("SHOW COLUMNS FROM `users`");
        if ($col_res) {
            while ($c = $col_res->fetch_assoc()) {
                $existing_cols[strtolower($c['Field'])] = true;
            }
        }
        if (!isset($existing_cols['email']))                @$conn->query("ALTER TABLE `users` ADD `email` VARCHAR(255) NULL DEFAULT NULL");
        if (!isset($existing_cols['is_active']))            @$conn->query("ALTER TABLE `users` ADD `is_active` TINYINT(1) NOT NULL DEFAULT 1");
        if (!isset($existing_cols['must_change_password'])) @$conn->query("ALTER TABLE `users` ADD `must_change_password` TINYINT(1) NOT NULL DEFAULT 0");
    }
} catch (Throwable $e) {}

// Generate CSRF token if missing
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$step    = 1;
$error   = '';
$success = '';

// If arriving via fresh GET (or reset), clear any old session and start at Step 1
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['reset']) || empty($_SESSION['fp_user_id']) || empty($_SESSION['fp_step']) || $_SESSION['fp_step'] != 2) {
        unset($_SESSION['fp_step'], $_SESSION['fp_email'], $_SESSION['fp_username'], $_SESSION['fp_user_id']);
        $step = 1;
    } else {
        $step = (int)$_SESSION['fp_step'];
    }
} else {
    $step = (int)($_SESSION['fp_step'] ?? 1);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $submittedCsrf)) {
        $error = "សុពលភាពទម្រង់មិនត្រឹមត្រូវ សូមព្យាយាមម្តងទៀត (Invalid CSRF token).";
    } else {
        $action = $_POST['action'] ?? '';

        // ── STEP 1: Find User by Email, Send Code, & Load Step 2 ──
        if ($action === 'find_user') {
            $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);

            if (!$email) {
                $error = "សូមបញ្ចូលអាសយដ្ឋានអ៊ីមែលត្រឹមត្រូវ (Please enter a valid email address).";
                $step  = 1;
            } else {
                try {
                    $user = null;
                    if (isset($conn) && $conn instanceof mysqli) {
                        $stmt = $conn->prepare("SELECT user_id, username, name, email FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
                        if ($stmt) {
                            $stmt->bind_param("s", $email);
                            $stmt->execute();
                            $res = $stmt->get_result();
                            $user = $res ? $res->fetch_assoc() : null;
                            $stmt->close();
                        }
                    }

                    if (!$user) {
                        $error = "រកមិនឃើញគណនីបុគ្គលិកជាមួយអ៊ីមែល " . htmlspecialchars($email) . " នេះទេ (No account found).";
                        $step  = 1;
                    } else {
                        // 1. Generate clean 8-character temporary password (e.g. BN-489201)
                        $tempPass = 'BN-' . random_int(100000, 999999);
                        $hashedTemp = password_hash($tempPass, PASSWORD_BCRYPT, ['cost' => 12]);
                        $userId = (int)$user['user_id'];
                        $userName = (string)(!empty($user['name']) ? $user['name'] : $user['username']);
                        $userEmail = (string)$user['email'];

                        // 2. Update user's temporary password in DB
                        $updStmt = $conn->prepare("UPDATE users SET password = ?, must_change_password = 1 WHERE user_id = ?");
                        if ($updStmt) {
                            $updStmt->bind_param("si", $hashedTemp, $userId);
                            $updStmt->execute();
                            $updStmt->close();
                        }

                        // 3. Dispatch Email with Temporary Password
                        $mail_result = send_temporary_password_email($userEmail, $userName, $tempPass);

                        if (!$mail_result['success']) {
                            $error = "បរាជ័យក្នុងការផ្ញើអ៊ីមែល (Failed to send email): " . htmlspecialchars($mail_result['error'] ?? 'SMTP error. Please verify SMTP settings in settings.php');
                            $step = 1;
                        } else {
                            // 4. Save session state & transition IMMEDIATELY to Step 2
                            $_SESSION['fp_user_id']   = $userId;
                            $_SESSION['fp_username']  = $userName;
                            $_SESSION['fp_email']     = $userEmail;
                            $_SESSION['fp_step']      = 2;
                            $step = 2;
                            $success = "យើងបានផ្ញើលេខសម្ងាត់បណ្តោះអាសន្នទៅកាន់អ៊ីមែល " . htmlspecialchars($userEmail) . " រួចរាល់ហើយ! សូមពិនិត្យមើល Email របស់អ្នក។";
                        }
                    }
                } catch (Throwable $e) {
                    error_log("[Password Reset Error] " . $e->getMessage());
                    $error = "មានបញ្ហាបច្ចេកទេស: " . htmlspecialchars($e->getMessage());
                    $step  = 1;
                }
            }
        }

        // ── STEP 2: Verify Current/Temp Password and Update to New Password ──
        elseif ($action === 'update_password') {
            $userId      = (int)($_SESSION['fp_user_id'] ?? 0);
            $currentPass = trim((string)($_POST['current_password'] ?? ''));
            $newPass     = (string)($_POST['new_password'] ?? '');
            $confirmPass = (string)($_POST['confirm_password'] ?? '');

            if (!$userId) {
                $error = "វគ្គរបស់អ្នកបានផុតកំណត់ហើយ សូមបញ្ចូលអ៊ីមែលឡើងវិញ (Session expired).";
                $step = 1;
            } elseif (empty($currentPass)) {
                $error = "សូមបញ្ចូលលេខសម្ងាត់បណ្តោះអាសន្នដែលទទួលបានពីអ៊ីមែល (Please enter temporary password from email).";
                $step = 2;
            } elseif (strlen($newPass) < 4) {
                $error = "ពាក្យសម្ងាត់ថ្មីត្រូវមានយ៉ាងហោចណាស់ 4 តួអក្សរឡើងទៅ (Min 4 chars).";
                $step = 2;
            } elseif ($newPass !== $confirmPass) {
                $error = "ការបញ្ជាក់ពាក្យសម្ងាត់មិនត្រូវគ្នាទេ (Passwords do not match).";
                $step = 2;
            } else {
                try {
                    $dbUser = null;
                    if (isset($conn) && $conn instanceof mysqli) {
                        $chkStmt = $conn->prepare("SELECT password FROM users WHERE user_id = ? LIMIT 1");
                        if ($chkStmt) {
                            $chkStmt->bind_param("i", $userId);
                            $chkStmt->execute();
                            $res = $chkStmt->get_result();
                            $dbUser = $res ? $res->fetch_assoc() : null;
                            $chkStmt->close();
                        }
                    }

                    if (!$dbUser || !password_verify($currentPass, (string)$dbUser['password'])) {
                        $error = "លេខសម្ងាត់ចាស់/បណ្តោះអាសន្នមិនត្រឹមត្រូវទេ (Invalid password from email).";
                        $step = 2;
                    } else {
                        // Hash new password using BCRYPT cost 12
                        $newHash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
                        $upd = $conn->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE user_id = ?");
                        if ($upd) {
                            $upd->bind_param("si", $newHash, $userId);
                            $upd->execute();
                            $upd->close();
                        }

                        // Clean reset session
                        unset($_SESSION['fp_user_id'], $_SESSION['fp_username'], $_SESSION['fp_email'], $_SESSION['fp_step']);

                        header("Location: login.php?reset=success");
                        exit;
                    }
                } catch (Throwable $e) {
                    error_log("[Password Update Error] " . $e->getMessage());
                    $error = "បរាជ័យក្នុងការផ្លាស់ប្តូរពាក្យសម្ងាត់: " . htmlspecialchars($e->getMessage());
                    $step = 2;
                }
            }
        }
    }
}

$fpEmail = htmlspecialchars((string)($_SESSION['fp_email'] ?? ''));
$fpUser  = htmlspecialchars((string)($_SESSION['fp_username'] ?? ''));
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>" data-lang="<?= current_lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ផ្លាស់ប្តូរលេខសម្ងាត់ — Bird's Nest Coffee POS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,100..900;1,100..900&family=Noto+Sans+Khmer:wght@100..900&family=Siemreap&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,100..900;1,100..900&family=Noto+Sans+Khmer:wght@100..900&family=Siemreap&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:          #040a08;
    --card-bg:     #081410;
    --input-bg:    #060f0c;
    --border-glow: rgba(0, 245, 160, 0.3);
    --mint:        #00f5a0;
    --mint-dim:    #00d486;
    --text-main:   #ffffff;
    --text-muted:  #708b82;
    --err:         #f87171;
    --err-bg:      rgba(239, 68, 68, 0.12);
    --err-border:  rgba(239, 68, 68, 0.3);
}

body, input, select, textarea, button, table, a, span, p, h1, h2, h3, h4, h5, h6, label, div {
    font-family: 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Khmer OS Battambang', 'Khmer OS Siemreap', 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    text-rendering: optimizeLegibility;
}
:lang(km), [data-lang="km"], html[lang="km"], html[lang="km"] * {
    font-family: 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Khmer OS Battambang', 'Khmer OS Siemreap', 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
}
html[lang="km"] .fa, html[lang="km"] [class*="fa-"], html[lang="km"] i, .fa, .fa-solid, [class*="fa-"] {
    font-family: 'Font Awesome 6 Free', 'FontAwesome' !important;
}
html[lang="km"] .fa-brands, html[lang="km"] [class*="fa-brands"], .fa-brands {
    font-family: 'Font Awesome 6 Brands', 'FontAwesome' !important;
}

html, body {
    min-height: 100vh;
    background-color: var(--bg);
    color: var(--text-main);
    overflow-x: hidden;
}

.bg-scene {
    position: fixed;
    inset: 0;
    z-index: 0;
    background-color: #030806;
    background-image: 
        linear-gradient(rgba(0, 255, 170, 0.035) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0, 255, 170, 0.035) 1px, transparent 1px);
    background-size: 36px 36px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.bg-scene::before {
    content: '';
    position: absolute;
    width: 750px;
    height: 750px;
    background: radial-gradient(circle, rgba(0, 245, 160, 0.12) 0%, rgba(0, 180, 120, 0.04) 45%, transparent 70%);
    filter: blur(50px);
    pointer-events: none;
}

.page {
    position: relative;
    z-index: 1;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px 16px;
}

.login-wrapper {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: clamp(24px, 4.5vw, 64px);
    width: 100%;
    max-width: 1440px;
    position: relative;
}

.side-character-col {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 20px;
    width: 320px;
    flex-shrink: 0;
    position: relative;
    z-index: 2;
}

/* ── CHAT BOX SPEECH BUBBLE ── */
.chat-bubble {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 11px 22px;
    background: rgba(8, 20, 16, 0.92);
    border: 2px solid var(--mint);
    border-radius: 18px;
    box-shadow: 
        0 0 25px rgba(0, 245, 160, 0.35),
        0 10px 30px rgba(0, 0, 0, 0.6),
        inset 0 0 15px rgba(0, 245, 160, 0.08);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    font-size: 14px;
    font-weight: 700;
    color: #ffffff;
    white-space: nowrap;
    user-select: none;
    z-index: 3;
    animation: chatFloat 4.5s ease-in-out infinite alternate;
    transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.25s ease;
}

.chat-bubble:hover {
    transform: translateY(-3px) scale(1.03);
    box-shadow: 
        0 0 35px rgba(0, 245, 160, 0.55),
        0 14px 32px rgba(0, 0, 0, 0.7),
        inset 0 0 20px rgba(0, 245, 160, 0.15);
}

/* Chat bubble speech tail pointer */
.chat-bubble::before,
.chat-bubble::after {
    content: '';
    position: absolute;
    width: 0;
    height: 0;
    border-style: solid;
}

/* Left Bubble: Tail on bottom-left pointing to barista */
.side-left .chat-bubble {
    border-bottom-left-radius: 6px;
}
.side-left .chat-bubble::before {
    bottom: -13px;
    left: 26px;
    border-width: 13px 12px 0 0;
    border-color: var(--mint) transparent transparent transparent;
}
.side-left .chat-bubble::after {
    bottom: -9px;
    left: 28px;
    border-width: 10px 9px 0 0;
    border-color: #081410 transparent transparent transparent;
}

/* Right Bubble: Tail on bottom-right pointing to barista */
.side-right .chat-bubble {
    border-bottom-right-radius: 6px;
    animation-delay: -2.25s;
}
.side-right .chat-bubble::before {
    bottom: -13px;
    right: 26px;
    border-width: 13px 0 0 12px;
    border-color: var(--mint) transparent transparent transparent;
}
.side-right .chat-bubble::after {
    bottom: -9px;
    right: 28px;
    border-width: 10px 0 0 9px;
    border-color: #081410 transparent transparent transparent;
}

@keyframes chatFloat {
    0%   { transform: translateY(0px); }
    50%  { transform: translateY(-7px); }
    100% { transform: translateY(0px); }
}

.chat-bubble .chat-text {
    color: #ffffff;
    font-weight: 700;
    letter-spacing: 0.02em;
    text-shadow: 0 0 12px rgba(0, 245, 160, 0.35);
}

.typewriter-cursor {
    display: inline-block;
    width: 2.5px;
    height: 1.15em;
    background-color: var(--mint);
    margin-left: 3px;
    vertical-align: -2px;
    border-radius: 1px;
    box-shadow: 0 0 8px var(--mint);
    animation: cursorBlink 0.75s infinite ease-in-out;
}

@keyframes cursorBlink {
    0%, 100% { opacity: 1; }
    50%      { opacity: 0; }
}

.character-box {
    position: relative;
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.character-img {
    width: 310px;
    max-width: 100%;
    max-height: 480px;
    height: auto;
    object-fit: contain;
    filter: drop-shadow(0 25px 35px rgba(0, 0, 0, 0.85)) drop-shadow(0 0 45px rgba(0, 245, 160, 0.25));
    animation: characterFloat 5s ease-in-out infinite alternate;
    user-select: none;
    -webkit-user-drag: none;
    transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), filter 0.4s ease;
}

.character-box:hover .character-img {
    filter: drop-shadow(0 30px 42px rgba(0, 0, 0, 0.95)) drop-shadow(0 0 60px rgba(0, 245, 160, 0.45));
    transform: scale(1.05) translateY(-6px);
}

.side-right .character-img {
    animation-delay: -2.5s;
}

@keyframes characterFloat {
    0%   { transform: translateY(0px); }
    50%  { transform: translateY(-12px); }
    100% { transform: translateY(0px); }
}

.character-glow {
    position: absolute;
    bottom: -16px;
    width: 210px;
    height: 40px;
    background: radial-gradient(ellipse, rgba(0, 245, 160, 0.32) 0%, rgba(0, 245, 160, 0.08) 50%, transparent 70%);
    filter: blur(14px);
    pointer-events: none;
    animation: shadowPulse 5s ease-in-out infinite alternate;
}

.side-right .character-glow {
    animation-delay: -2.5s;
}

@keyframes shadowPulse {
    0%   { transform: scale(1); opacity: 0.9; }
    50%  { transform: scale(0.82); opacity: 0.45; }
    100% { transform: scale(1); opacity: 0.9; }
}

.card {
    width: 100%;
    max-width: 480px;
    background: var(--card-bg);
    border: 1.5px solid var(--border-glow);
    border-radius: 28px;
    padding: 34px 34px 28px;
    position: relative;
    box-shadow: 0 0 50px -10px rgba(0, 245, 160, 0.2), 0 30px 60px -15px rgba(0, 0, 0, 0.9);
    backdrop-filter: blur(20px);
}

.card-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
}

.brand-wrapper {
    display: flex;
    align-items: center;
    gap: 12px;
}

.brand-icon-box {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: rgba(0, 245, 160, 0.06);
    border: 1.2px solid rgba(0, 245, 160, 0.35);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--mint);
    font-size: 18px;
}

.brand-title {
    font-size: 14px;
    font-weight: 800;
    color: #ffffff;
}

.brand-sub {
    font-size: 9.5px;
    font-weight: 800;
    color: var(--mint);
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.step-badge {
    padding: 4px 14px;
    border-radius: 999px;
    background: rgba(0, 245, 160, 0.05);
    border: 1.2px solid rgba(0, 245, 160, 0.4);
    color: var(--mint);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.08em;
}

.heading-section {
    margin-bottom: 22px;
}

.main-title {
    font-size: 24px;
    font-weight: 800;
    color: #ffffff;
    display: flex;
    align-items: center;
    gap: 8px;
}

.sub-title {
    font-size: 12.5px;
    color: var(--text-muted);
    margin-top: 6px;
    line-height: 1.5;
}

.error-box {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 14px;
    background: var(--err-bg);
    border: 1px solid var(--err-border);
    border-radius: 12px;
    color: var(--err);
    font-size: 12.5px;
    margin-bottom: 18px;
}

.success-box {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 14px 16px;
    background: rgba(0, 245, 160, 0.08);
    border: 1px solid rgba(0, 245, 160, 0.35);
    border-radius: 14px;
    color: var(--mint);
    font-size: 12.5px;
    line-height: 1.5;
    margin-bottom: 20px;
}

.field-group {
    margin-bottom: 16px;
}

.field-label {
    display: block;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-muted);
    margin-bottom: 6px;
}

.input-box {
    position: relative;
    width: 100%;
    display: flex;
    align-items: center;
}

.input-box input {
    width: 100%;
    height: 48px;
    background: var(--input-bg);
    border: 1px solid rgba(255, 255, 255, 0.09);
    border-radius: 12px;
    padding: 0 42px 0 42px;
    color: #ffffff;
    font-size: 13.5px;
    outline: none;
    transition: all 0.2s ease;
}

.input-box input:focus {
    border-color: var(--mint);
    box-shadow: 0 0 16px rgba(0, 245, 160, 0.22);
}

.input-box input::placeholder {
    color: #3b5249;
    font-size: 13px;
}

.input-box .input-icon {
    position: absolute;
    left: 15px;
    color: #4a665c;
    font-size: 14px;
    pointer-events: none;
}

.input-box:focus-within .input-icon {
    color: var(--mint);
}

.eye-btn {
    position: absolute;
    right: 12px;
    background: none;
    border: none;
    color: #4a665c;
    cursor: pointer;
    padding: 6px;
    font-size: 14px;
    transition: color 0.2s;
}

.eye-btn:hover { color: var(--mint); }

.req-box {
    background: rgba(0, 0, 0, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.05);
    border-radius: 14px;
    padding: 12px 16px;
    margin: 18px 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.req-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: #708b82;
    transition: color 0.2s;
}

.req-item.met { color: var(--mint); }
.req-item i { font-size: 11px; }

.submit-btn {
    width: 100%;
    height: 48px;
    background: var(--mint);
    border: none;
    border-radius: 12px;
    color: #03140c;
    font-size: 14.5px;
    font-weight: 800;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-top: 8px;
    box-shadow: 0 8px 24px rgba(0, 245, 160, 0.35);
    transition: all 0.2s ease;
    text-decoration: none;
}

.submit-btn:hover {
    background: #1affb0;
    transform: translateY(-2px);
    box-shadow: 0 12px 30px rgba(0, 245, 160, 0.45);
}

.bottom-links {
    margin-top: 22px;
    padding-top: 16px;
    border-top: 1px solid rgba(255, 255, 255, 0.06);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.back-link {
    color: var(--text-muted);
    font-size: 12px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.back-link:hover { color: var(--mint); }
.year-text { color: #3b5249; font-size: 11px; font-weight: 700; font-family: monospace; }

/* ── CYBER FLOOR NEON LINE UNDER 3D CARTOONS ── */
.cyber-line-container {
    position: absolute;
    bottom: -55px;
    left: 50%;
    transform: translateX(-50%);
    width: 100vw;
    max-width: 1750px;
    height: 180px;
    pointer-events: none;
    z-index: 1;
    overflow: visible;
}

.cyber-line-svg {
    width: 100%;
    height: 100%;
    display: block;
    overflow: visible;
    animation: linePulse 4s ease-in-out infinite alternate;
}

@keyframes linePulse {
    0%   { opacity: 0.85; filter: drop-shadow(0 0 8px rgba(0, 245, 160, 0.4)); }
    100% { opacity: 1;    filter: drop-shadow(0 0 20px rgba(0, 245, 160, 0.75)); }
}

@media (max-width: 1100px) {
    .side-character-col { display: none; }
    .cyber-line-container { display: none; }
    .login-wrapper { justify-content: center; }
}
</style>
</head>
<body>

<div class="bg-scene"></div>

<div class="page">
    <div class="login-wrapper">
        
        <!-- Cyber Neon Baseline Curve Directly Below 3D Cartoons (Split Left & Right) -->
        <div class="cyber-line-container">
            <svg class="cyber-line-svg" viewBox="0 0 1600 180" fill="none" preserveAspectRatio="none">
                <defs>
                    <linearGradient id="neonCyberGrad" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" stop-color="#00f5a0" stop-opacity="0" />
                        <stop offset="6%" stop-color="#00f5a0" stop-opacity="0.35" />
                        <stop offset="20%" stop-color="#00f5a0" stop-opacity="0.95" />
                        <stop offset="33%" stop-color="#00f5a0" stop-opacity="0.2" />
                        <stop offset="36%" stop-color="#00f5a0" stop-opacity="0" />
                        <stop offset="64%" stop-color="#00f5a0" stop-opacity="0" />
                        <stop offset="67%" stop-color="#00f5a0" stop-opacity="0.2" />
                        <stop offset="80%" stop-color="#00f5a0" stop-opacity="0.95" />
                        <stop offset="94%" stop-color="#00f5a0" stop-opacity="0.35" />
                        <stop offset="100%" stop-color="#00f5a0" stop-opacity="0" />
                    </linearGradient>
                    <filter id="neonGlowWide" x="-20%" y="-100%" width="140%" height="300%">
                        <feGaussianBlur stdDeviation="6" result="blur1" />
                        <feGaussianBlur stdDeviation="14" result="blur2" />
                        <feMerge>
                            <feMergeNode in="blur2" />
                            <feMergeNode in="blur1" />
                            <feMergeNode in="SourceGraphic" />
                        </feMerge>
                    </filter>
                </defs>
                <!-- Wide ambient glow path (Ultra-smooth Left & Right wings) -->
                <path d="M 0,20 C 120,20 200,110 330,110 L 560,110 M 1040,110 L 1260,110 C 1370,105 1450,115 1600,175"
                      stroke="url(#neonCyberGrad)" stroke-width="7" opacity="0.32" filter="url(#neonGlowWide)" />
                <!-- Core solid smooth neon path -->
                <path d="M 0,20 C 120,20 200,110 330,110 L 560,110 M 1040,110 L 1260,110 C 1370,105 1450,115 1600,175"
                      stroke="url(#neonCyberGrad)" stroke-width="2.5" stroke-linecap="round" />
            </svg>
        </div>
        
        <!-- Left Side Character -->
        <div class="side-character-col side-left">
            <div class="chat-bubble">
                <span class="chat-text" id="typewriterLeft"><?= $step === 2 ? 'បង្កើតលេខសម្ងាត់ថ្មី! 🔒' : 'ភ្លេចលេខសម្ងាត់មែនទេ? 🔑' ?></span><span class="typewriter-cursor"></span>
            </div>
            <div class="character-box">
                <img src="image/forgot-password-cartoon.webp" alt="Bird's Nest Forgot Password" class="character-img">
                <div class="character-glow"></div>
            </div>
        </div>

        <!-- Center Card -->
        <div class="card">
            
            <div class="card-top">
                <div class="brand-wrapper">
                    <div class="brand-icon-box">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <div class="brand-meta">
                        <div class="brand-title">BIRD'S NEST POS</div>
                        <div class="brand-sub"><?= $step === 2 ? 'SECURITY UPDATE' : 'STAFF RECOVERY' ?></div>
                    </div>
                </div>
                <div class="step-badge">STEP <?= $step === 2 ? '02' : '01' ?></div>
            </div>

            <?php if (!empty($error)): ?>
            <div class="error-box">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
            <div class="success-box">
                <i class="fa-solid fa-circle-check"></i>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- ── STEP 1: Find User By Email ── -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php if ($step === 1): ?>
            <div class="heading-section">
                <h1 class="main-title">ភ្លេចលេខសម្ងាត់ 🔐</h1>
                <p class="sub-title">សូមបញ្ចូលអាសយដ្ឋានអ៊ីមែលរបស់អ្នកដើម្បីទទួលបានលេខសម្ងាត់បណ្តោះអាសន្នតាមរយៈ Email។</p>
            </div>

            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="find_user">
                
                <div class="field-group">
                    <label class="field-label" for="email">EMAIL ADDRESS / អ៊ីមែលបុគ្គលិក</label>
                    <div class="input-box" style="padding: 0;">
                        <span class="input-icon"><i class="fa-solid fa-envelope"></i></span>
                        <input type="email" name="email" id="email" placeholder="name@example.com" value="<?= $fpEmail ?>" required autofocus autocomplete="email" style="padding-left: 42px; padding-right: 16px;">
                    </div>
                </div>

                <button type="submit" class="submit-btn">
                    <span>ផ្ញើលេខសម្ងាត់ទៅអ៊ីមែល (Send Code to Email)</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </button>
            </form>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- ── STEP 2: Change Password (MATCHING EXACT SCREENSHOT) ── -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($step === 2): ?>
            <div class="heading-section">
                <h1 class="main-title">ផ្លាស់ប្តូរលេខសម្ងាត់ 🗝️</h1>
                <p class="sub-title">សូមបញ្ចូលលេខសម្ងាត់ចាស់ និងលេខសម្ងាត់ថ្មីរបស់អ្នកដើម្បីបន្តរៀបចំគណនី។</p>
            </div>

            <form method="POST" autocomplete="off" id="changePassForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="update_password">

                <!-- 1. Current Password / Temp Password from Email -->
                <div class="field-group">
                    <label class="field-label" for="currPass">CURRENT PASSWORD / លេខសម្ងាត់ចាស់</label>
                    <div class="input-box">
                        <span class="input-icon"><i class="fa-solid fa-lock"></i></span>
                        <input type="password" name="current_password" id="currPass" placeholder="បញ្ចូលលេខសម្ងាត់បច្ចុប្បន្ន..." required autofocus>
                        <button type="button" class="eye-btn" onclick="togglePass('currPass', 'eyeCurr')"><i class="fa-solid fa-eye" id="eyeCurr"></i></button>
                    </div>
                </div>

                <!-- 2. New Password -->
                <div class="field-group">
                    <label class="field-label" for="newPass">NEW PASSWORD / លេខសម្ងាត់ថ្មី</label>
                    <div class="input-box">
                        <span class="input-icon"><i class="fa-solid fa-shield-halved"></i></span>
                        <input type="password" name="new_password" id="newPass" placeholder="យ៉ាងហោចណាស់ 8 តួអក្សរ..." required>
                        <button type="button" class="eye-btn" onclick="togglePass('newPass', 'eyeNew')"><i class="fa-solid fa-eye" id="eyeNew"></i></button>
                    </div>
                </div>

                <!-- 3. Confirm New Password -->
                <div class="field-group">
                    <label class="field-label" for="confirmPass">CONFIRM NEW PASSWORD / បញ្ជាក់លេខសម្ងាត់ថ្មី</label>
                    <div class="input-box">
                        <span class="input-icon"><i class="fa-solid fa-circle-check"></i></span>
                        <input type="password" name="confirm_password" id="confirmPass" placeholder="វាយបញ្ចូលលេខសម្ងាត់ថ្មីម្តងទៀត..." required>
                        <button type="button" class="eye-btn" onclick="togglePass('confirmPass', 'eyeConf')"><i class="fa-solid fa-eye" id="eyeConf"></i></button>
                    </div>
                </div>

                <!-- Validation Checklist Box -->
                <div class="req-box">
                    <div class="req-item" id="reqLen">
                        <i class="fa-solid fa-check"></i>
                        <span>យ៉ាងហោចណាស់ 8 តួអក្សរ</span>
                    </div>
                    <div class="req-item" id="reqMix">
                        <i class="fa-solid fa-check"></i>
                        <span>រួមមានអក្សរធំ អក្សរតូច និងលេខ</span>
                    </div>
                </div>

                <button type="submit" class="submit-btn">
                    <span>រក្សាទុកលេខសម្ងាត់ថ្មី (Save Changes)</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </button>
            </form>
            <?php endif; ?>

            <div class="bottom-links">
                <a href="login.php" class="back-link">
                    <i class="fa-solid fa-arrow-left"></i> ត្រឡប់ទៅចូលគណនី (Back to Sign In)
                </a>
                <span class="year-text">2026</span>
            </div>

        </div>

        <!-- Right Side Character -->
        <div class="side-character-col side-right">
            <div class="chat-bubble">
                <span class="chat-text" id="typewriterRight">សុវត្ថិភាពខ្ពស់ 100%! 🛡️</span><span class="typewriter-cursor"></span>
            </div>
            <div class="character-box">
                <img src="image/3d-cartoon-right.webp" alt="Bird's Nest Barista" class="character-img">
                <div class="character-glow"></div>
            </div>
        </div>

    </div>
</div>

<script>
function togglePass(inputId, iconId) {
    const el = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    if (!el || !icon) return;
    if (el.type === 'password') {
        el.type = 'text';
        icon.className = 'fa-solid fa-eye-slash';
    } else {
        el.type = 'password';
        icon.className = 'fa-solid fa-eye';
    }
}

const newPassInput = document.getElementById('newPass');
const reqLen = document.getElementById('reqLen');
const reqMix = document.getElementById('reqMix');

if (newPassInput) {
    newPassInput.addEventListener('input', function() {
        const val = this.value;
        const hasLen = val.length >= 8;
        const hasUpper = /[A-Z]/.test(val);
        const hasLower = /[a-z]/.test(val);
        const hasNum = /[0-9]/.test(val);

        if (reqLen) reqLen.classList.toggle('met', hasLen);
        if (reqMix) reqMix.classList.toggle('met', (hasUpper || hasLower) && hasNum);
    });
}

// ── TYPEWRITER TEXT ANIMATION ──
function initTypewriter(elementId, texts, typeSpeed = 75, eraseSpeed = 35, delayBetween = 3000) {
    const el = document.getElementById(elementId);
    if (!el) return;
    
    let textIdx = 0;
    let charIdx = 0;
    let isDeleting = false;
    
    function getGraphemes(str) {
        if (typeof Intl !== 'undefined' && Intl.Segmenter) {
            try {
                const seg = new Intl.Segmenter('km', { granularity: 'grapheme' });
                return Array.from(seg.segment(str), s => s.segment);
            } catch(e) {}
        }
        return Array.from(str);
    }
    
    function run() {
        const currentString = texts[textIdx % texts.length];
        const graphemes = getGraphemes(currentString);
        
        if (isDeleting) {
            charIdx--;
            el.textContent = graphemes.slice(0, charIdx).join('');
        } else {
            charIdx++;
            el.textContent = graphemes.slice(0, charIdx).join('');
        }
        
        let wait = isDeleting ? eraseSpeed : typeSpeed;
        
        if (!isDeleting && charIdx === graphemes.length) {
            wait = delayBetween;
            isDeleting = true;
        } else if (isDeleting && charIdx === 0) {
            isDeleting = false;
            textIdx++;
            wait = 500;
        }
        
        setTimeout(run, wait);
    }
    
    setTimeout(run, 600);
}

document.addEventListener('DOMContentLoaded', function() {
    const step = <?= (int)$step ?>;
    if (step === 2) {
        initTypewriter('typewriterLeft', [
            "បង្កើតលេខសម្ងាត់ថ្មី!",
            "បញ្ចូលលេខសម្ងាត់ពីអ៊ីមែល",
            "កំណត់ពាក្យសម្ងាត់សុវត្ថិភាព"
        ], 80, 40, 3200);
    } else {
        initTypewriter('typewriterLeft', [
            "ភ្លេចលេខសម្ងាត់មែនទេ?",
            "កុំបារម្ភ យើងជួយអ្នកបាន!",
            "បញ្ចូលអ៊ីមែលបុគ្គលិក"
        ], 80, 40, 3200);
    }

    setTimeout(function() {
        initTypewriter('typewriterRight', [
            "សុវត្ថិភាពខ្ពស់ 100%!",
            "លេខកូដផ្ញើតាម Email",
            "រហ័ស និងទុកចិត្តបាន!"
        ], 80, 40, 3200);
    }, 1200);
});
</script>
</body>
</html>
