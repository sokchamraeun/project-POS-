<?php
session_start();
require 'config.php';

$error = '';
$success = '';
if (isset($_GET['reset']) && $_GET['reset'] === 'success') {
    $success = 'លេខសម្ងាត់ត្រូវបានផ្លាស់ប្តូរដោយជោគជ័យ! សូមចូលប្រព័ន្ធឥឡូវនេះ (Password changed successfully! Please sign in).';
}
elseif (isset($_GET['timeout']))       $error = 'Session expired due to inactivity. Please sign in again.';
elseif (isset($_GET['error'])) {
    if ($_GET['error'] === 'locked') {
        $mins  = isset($_GET['mins']) ? max(1, (int)$_GET['mins']) : 15;
        $error = "Too many failed attempts. Try again in {$mins} minute" . ($mins === 1 ? '' : 's') . '.';
    }
    else {
        $left  = isset($_GET['left']) ? (int)$_GET['left'] : null;
        $error = 'Invalid username or password.' . ($left !== null && $left > 0 ? " {$left} attempt(s) left." : '');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username !== '' && $password !== '') {
        try {
            // Attempt login with username or email
            $stmt = @$conn->prepare("SELECT u.*, u.username AS emp_name FROM users u WHERE u.username = ? OR u.email = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("ss", $username, $username);
                $stmt->execute();
                $result = $stmt->get_result();
            } else {
                // Fallback if email column not present in older hosting schema
                $stmt = $conn->prepare("SELECT u.*, u.username AS emp_name FROM users u WHERE u.username = ? LIMIT 1");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();
            }

            if ($result && $result->num_rows === 1) {
                $user = $result->fetch_assoc();
                if (password_verify($password, (string)$user['password'])) {
                    session_regenerate_id(true);
                    $_SESSION['user_id']           = $user['user_id'];
                    $_SESSION['username']          = $user['username'];
                    $_SESSION['emp_name']          = $user['emp_name'] ?: $user['username'];
                    $_SESSION['role']              = $user['role'];
                    $_SESSION['last_activity']     = time();
                    $_SESSION['login_time']        = time();
                    $_SESSION['flash_welcome']     = true;
                    $_SESSION['flash_stock_alert'] = true;

                    header("Location: loading.php");
                    exit;
                }
            }
        } catch (Throwable $e) {
            error_log("[Login Exception] " . $e->getMessage());
        }
    }
    header("Location: login.php?error=1");
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>" data-lang="<?= current_lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In — Bird's Nest POS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Noto+Sans+Khmer:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:          #050d0a;
    --card-bg:     #0b1713;
    --input-bg:    #091410;
    --border-dim:  rgba(255, 255, 255, 0.08);
    --border-glow: rgba(0, 255, 170, 0.22);
    --mint:        #00f5a0;
    --mint-dim:    #00d486;
    --mint-glow:   rgba(0, 245, 160, 0.3);
    --text-main:   #ffffff;
    --text-muted:  #708b82;
    --text-dim:    #4a665c;
    --btn-dark:    #041f15;
    --err:         #f87171;
    --err-bg:      rgba(239, 68, 68, 0.12);
    --err-border:  rgba(239, 68, 68, 0.3);
}

html, body {
    min-height: 100vh;
    background-color: var(--bg);
    font-family: 'Plus Jakarta Sans', 'Outfit', 'Kantumruy Pro', 'Noto Sans Khmer', sans-serif;
    color: var(--text-main);
    overflow-x: hidden;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

:lang(km), [data-lang="km"], html[lang="km"] body {
    font-family: 'Kantumruy Pro', 'Noto Sans Khmer', 'Plus Jakarta Sans', sans-serif;
}

/* ── PREVENT ICON FONT OVERRIDES ── */
i.fa-solid, i.fas, .fa-solid, .fas {
    font-family: "Font Awesome 6 Free" !important;
    font-weight: 900 !important;
    font-style: normal !important;
}

i.fa-regular, i.far, .fa-regular, .far {
    font-family: "Font Awesome 6 Free" !important;
    font-weight: 400 !important;
    font-style: normal !important;
}

i.fa-brands, i.fab, .fa-brands, .fab {
    font-family: "Font Awesome 6 Brands" !important;
    font-weight: 400 !important;
    font-style: normal !important;
}

/* ── BACKGROUND GRID & RADIAL GLOW ── */
.bg-scene {
    position: fixed;
    inset: 0;
    z-index: 0;
    background-color: #040a08;
    background-image: 
        linear-gradient(rgba(0, 255, 170, 0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0, 255, 170, 0.04) 1px, transparent 1px);
    background-size: 36px 36px;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Ambient Radial Emerald Glows */
.bg-scene::before {
    content: '';
    position: absolute;
    width: 650px;
    height: 650px;
    background: radial-gradient(circle, rgba(0, 245, 160, 0.14) 0%, rgba(0, 180, 120, 0.05) 45%, transparent 70%);
    filter: blur(40px);
    pointer-events: none;
}

.bg-scene::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at center, transparent 40%, rgba(3, 7, 5, 0.85) 100%);
    pointer-events: none;
}

/* ── PAGE LAYOUT ── */
.page {
    position: relative;
    z-index: 1;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px 16px;
}

/* ── WRAPPER ── */
.login-wrapper {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: clamp(24px, 4vw, 56px);
    width: 100%;
    max-width: 1400px;
    position: relative;
}

/* ── SIDE CHARACTER COLUMNS ── */
.side-character-col {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 22px;
    width: 320px;
    flex-shrink: 0;
    animation: sideFadeIn 0.8s cubic-bezier(0.16, 1, 0.3, 1) both;
    position: relative;
    z-index: 2;
}

.side-left {
    animation-delay: 0.1s;
}

.side-right {
    animation-delay: 0.2s;
}

@keyframes sideFadeIn {
    from { opacity: 0; transform: translateY(30px) scale(0.95); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
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

/* ── 3D CHARACTER BOX ── */
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

/* ── LOGIN CARD ── */
.card {
    width: 100%;
    max-width: 440px;
    background: var(--card-bg);
    border: 1px solid var(--border-glow);
    border-radius: 28px;
    padding: 34px 34px 28px;
    position: relative;
    box-shadow:
        0 0 50px -10px rgba(0, 245, 160, 0.18),
        0 30px 60px -15px rgba(0, 0, 0, 0.9),
        inset 0 1px 1px rgba(255, 255, 255, 0.08);
    backdrop-filter: blur(20px);
    animation: cardEntrance .5s cubic-bezier(.16, 1, .3, 1) both;
    flex-shrink: 0;
}

@keyframes cardEntrance {
    from { opacity: 0; transform: translateY(20px) scale(0.98); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}

/* ── CARD HEADER ── */
.card-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 28px;
}

.brand-wrapper {
    display: flex;
    align-items: center;
    gap: 13px;
}

.brand-icon-box {
    position: relative;
    width: 48px;
    height: 48px;
    border-radius: 16px;
    background: rgba(0, 245, 160, 0.05);
    border: 1.5px solid rgba(0, 245, 160, 0.35);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--mint);
    box-shadow: 0 0 20px rgba(0, 245, 160, 0.15);
    flex-shrink: 0;
}

.brand-icon-box svg {
    color: var(--mint);
    filter: drop-shadow(0 0 6px rgba(0, 245, 160, 0.4));
}

/* Online Indicator Dot */
.brand-icon-box .status-dot {
    position: absolute;
    top: -3px;
    right: -3px;
    width: 8px;
    height: 8px;
    background-color: var(--mint);
    border-radius: 50%;
    box-shadow: 0 0 8px var(--mint);
    border: 2px solid var(--card-bg);
}

.brand-meta {
    display: flex;
    flex-direction: column;
}

.brand-title {
    font-size: 14.5px;
    font-weight: 800;
    color: var(--text-main);
    letter-spacing: 0.04em;
    line-height: 1.2;
}

.brand-sub {
    font-size: 10px;
    font-weight: 700;
    color: var(--mint-dim);
    letter-spacing: 0.14em;
    text-transform: uppercase;
    margin-top: 2px;
}

.terminal-badge {
    display: inline-flex;
    align-items: center;
    padding: 5px 13px;
    border-radius: 999px;
    background: rgba(0, 245, 160, 0.06);
    border: 1px solid rgba(0, 245, 160, 0.22);
    color: var(--mint);
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    font-family: 'Plus Jakarta Sans', monospace, sans-serif;
}

/* ── TITLE SECTION ── */
.heading-section {
    margin-bottom: 24px;
}

.main-title {
    font-size: 25px;
    font-weight: 800;
    color: var(--text-main);
    display: flex;
    align-items: center;
    gap: 8px;
    line-height: 1.25;
}

.sparkle-icon {
    color: var(--mint);
    display: inline-flex;
    align-items: center;
    filter: drop-shadow(0 0 6px var(--mint));
}

.sub-title {
    font-size: 13.5px;
    font-weight: 400;
    color: var(--text-muted);
    margin-top: 6px;
    line-height: 1.4;
}

/* ── ERROR MESSAGE ── */
.error-box {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px 14px;
    background: var(--err-bg);
    border: 1px solid var(--err-border);
    border-radius: 12px;
    color: var(--err);
    font-size: 13px;
    line-height: 1.45;
    margin-bottom: 20px;
    animation: shake .4s ease;
}
.error-box svg { flex-shrink: 0; margin-top: 2px; }

@keyframes shake {
    0%, 100% { transform: translateX(0); }
    20%, 60%  { transform: translateX(-5px); }
    40%, 80%  { transform: translateX(5px); }
}

/* ── FORM ELEMENTS ── */
.field-group {
    margin-bottom: 18px;
}

.field-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
}

.field-label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-muted);
}

.forgot-link {
    font-size: 12px;
    font-weight: 600;
    color: var(--mint-dim);
    text-decoration: none;
    transition: color .2s, text-shadow .2s;
}
.forgot-link:hover {
    color: var(--mint);
    text-shadow: 0 0 8px rgba(0, 245, 160, 0.4);
}

.input-box {
    position: relative;
    width: 100%;
    display: flex;
    align-items: center;
}

.input-box input {
    width: 100%;
    height: 52px;
    background: var(--input-bg);
    border: 1px solid rgba(255, 255, 255, 0.09);
    border-radius: 14px;
    padding: 0 46px 0 44px;
    color: var(--text-main);
    font-size: 14.5px;
    font-weight: 500;
    outline: none;
    transition: border-color .2s, box-shadow .2s, background-color .2s;
}

/* Active Highlight Style for inputs */
.input-box.highlight-border input,
.input-box input:focus {
    border-color: var(--mint-dim);
    box-shadow: 0 0 16px rgba(0, 212, 134, 0.22), inset 0 0 8px rgba(0, 212, 134, 0.05);
}

.input-box input::placeholder {
    color: var(--text-dim);
    font-weight: 400;
}

.input-box .input-icon {
    position: absolute;
    left: 16px;
    color: var(--text-dim);
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
    transition: color .2s;
}

.input-box.highlight-border .input-icon,
.input-box:focus-within .input-icon {
    color: var(--mint);
}

.eye-btn {
    position: absolute;
    right: 14px;
    background: none;
    border: none;
    color: var(--text-dim);
    cursor: pointer;
    padding: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: color .2s;
}
.eye-btn:hover {
    color: var(--mint);
}

/* ── SUBMIT BUTTON ── */
.submit-btn {
    width: 100%;
    height: 52px;
    border: none;
    border-radius: 14px;
    background: linear-gradient(135deg, #00f5a0 0%, #00d486 100%);
    color: var(--btn-dark);
    font-size: 15.5px;
    font-weight: 800;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin-top: 24px;
    position: relative;
    overflow: hidden;
    box-shadow: 0 4px 25px rgba(0, 245, 160, 0.35), 0 0 10px rgba(0, 212, 134, 0.2);
    transition: transform .15s ease, box-shadow .2s ease, filter .2s ease;
    letter-spacing: 0.02em;
}

.submit-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 32px rgba(0, 245, 160, 0.5), 0 0 15px rgba(0, 212, 134, 0.3);
    filter: brightness(1.05);
}

.submit-btn:active {
    transform: translateY(0);
}

.submit-btn .btn-content {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.submit-btn svg.arrow {
    transition: transform .2s ease;
}

.submit-btn:hover svg.arrow {
    transform: translateX(4px);
}

/* Spinner for submit */
.spinner {
    display: none;
    width: 18px;
    height: 18px;
    border: 2.5px solid rgba(4, 31, 21, 0.25);
    border-top-color: var(--btn-dark);
    border-radius: 50%;
    animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
.submit-btn.loading .btn-content { display: none; }
.submit-btn.loading .spinner     { display: block; }

/* ── FOOTER META ── */
.card-footer-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 24px;
    padding-top: 4px;
    font-size: 11.5px;
    font-weight: 500;
    color: var(--text-dim);
}

.card-footer-meta .encrypted {
    display: flex;
    align-items: center;
    gap: 6px;
}

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

/* ── RESPONSIVE ── */
@media (max-width: 1350px) {
    .login-wrapper {
        gap: 28px;
    }
    .side-character-col {
        width: 260px;
    }
    .character-img {
        width: 250px;
    }
    .character-glow {
        width: 170px;
    }
}

@media (max-width: 1100px) {
    .side-character-col {
        display: none;
    }
    .cyber-line-container {
        display: none;
    }
    .login-wrapper {
        justify-content: center;
        gap: 0;
    }
}

@media (max-width: 480px) {
    .card {
        padding: 26px 20px 22px;
        border-radius: 22px;
    }
    .main-title {
        font-size: 22px;
    }
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
        
        <!-- Left Side Character & Badge -->
        <div class="side-character-col side-left">
            <div class="chat-bubble">
                <span class="chat-text" id="typewriterLeft">សូមស្វាគមន៍មកកាន់ POS!</span><span class="typewriter-cursor"></span>
            </div>
            <div class="character-box">
                <img src="image/3d-cartoon-left.webp" alt="Bird's Nest 3D Barista" class="character-img" loading="eager">
                <div class="character-glow"></div>
            </div>
        </div>

        <!-- Center Login Card -->
        <div class="card">
            
            <!-- Top Brand Header -->
            <div class="card-top">
                <div class="brand-wrapper">
                    <div class="brand-icon-box">
                        <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 8h1a4 4 0 0 1 0 8h-1"></path>
                            <path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"></path>
                            <line x1="6" y1="1" x2="6" y2="4"></line>
                            <line x1="10" y1="1" x2="10" y2="4"></line>
                            <line x1="14" y1="1" x2="14" y2="4"></line>
                        </svg>
                        <span class="status-dot"></span>
                    </div>
                    <div class="brand-meta">
                        <span class="brand-title">BIRD'S NEST POS</span>
                        <span class="brand-sub">STAFF LOGIN</span>
                    </div>
                </div>
                <div class="terminal-badge">
                    TERMINAL 01
                </div>
            </div>

            <!-- Main Title -->
            <div class="heading-section">
                <h1 class="main-title">
                    ចូលប្រព័ន្ធ (Sign In)
                    <span class="sparkle-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 0L14.6 9.4L24 12L14.6 14.6L12 24L9.4 14.6L0 12L9.4 9.4L12 0Z"/>
                        </svg>
                    </span>
                </h1>
                <p class="sub-title">សូមបញ្ចូលគណនីរបស់អ្នកដើម្បីចាប់ផ្តើម</p>
            </div>

            <!-- Success Box -->
            <?php if (!empty($success)): ?>
            <div class="success-box" style="display: flex; align-items: center; gap: 10px; padding: 12px 14px; background: rgba(0, 245, 160, 0.1); border: 1px solid rgba(0, 245, 160, 0.3); border-radius: 12px; color: var(--mint); font-size: 13px; line-height: 1.45; margin-bottom: 20px;">
                <i class="fa-solid fa-circle-check" style="font-size: 16px; flex-shrink: 0;"></i>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
            <?php endif; ?>

            <!-- Error Box -->
            <?php if (!empty($error)): ?>
            <div class="error-box">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
            <?php endif; ?>

            <!-- Form -->
            <form method="POST" id="form" autocomplete="off">

                <!-- Username or Email Field -->
                <div class="field-group">
                    <div class="field-header">
                        <label class="field-label" for="u">USERNAME OR EMAIL</label>
                    </div>
                    <div class="input-box highlight-border">
                        <span class="input-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                        </span>
                        <input type="text" name="username" id="u" placeholder="ឈ្មោះអ្នកប្រើប្រាស់ ឬ អ៊ីមែល" required autofocus autocomplete="username">
                    </div>
                </div>

                <!-- Password Field -->
                <div class="field-group">
                    <div class="field-header">
                        <label class="field-label" for="p">PASSWORD</label>
                        <a href="forgot_password.php?reset=1" class="forgot-link">ភ្លេចលេខសម្ងាត់?</a>
                    </div>
                    <div class="input-box">
                        <span class="input-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                            </svg>
                        </span>
                        <input type="password" name="password" id="p" placeholder="ពាក្យសម្ងាត់" required autocomplete="current-password">
                        <button type="button" class="eye-btn" id="eye" aria-label="Toggle password visibility">
                            <svg id="eyeSvg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="submit-btn" id="btn">
                    <span class="btn-content">
                        ចូលប្រើប្រាស់
                        <svg class="arrow" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                            <polyline points="12 5 19 12 12 19"></polyline>
                        </svg>
                    </span>
                    <span class="spinner"></span>
                </button>
            </form>

        </div>

        <!-- Right Side Character & Badge -->
        <div class="side-character-col side-right">
            <div class="chat-bubble">
                <span class="chat-text" id="typewriterRight">ត្រៀមរួចរាល់សម្រាប់បម្រើ!</span><span class="typewriter-cursor"></span>
            </div>
            <div class="character-box">
                <img src="image/3d-cartoon-right.webp" alt="Bird's Nest 3D Barista" class="character-img" loading="eager" onerror="this.src='image/3d-cartoon-rgiht.webp'">
                <div class="character-glow"></div>
            </div>
        </div>

    </div>
</div>

<script>
// Eye password toggle
const eyeBtn = document.getElementById('eye');
const passInput = document.getElementById('p');
const eyeSvg = document.getElementById('eyeSvg');

eyeBtn.addEventListener('click', function(){
    const show = passInput.type === 'password';
    passInput.type = show ? 'text' : 'password';
    
    if (show) {
        // Eye-off icon
        eyeSvg.innerHTML = `
            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
            <line x1="1" y1="1" x2="23" y2="23"></line>
        `;
    } else {
        // Eye icon
        eyeSvg.innerHTML = `
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
            <circle cx="12" cy="12" r="3"></circle>
        `;
    }
});

// Submit loading state
document.getElementById('form').addEventListener('submit', function(){
    document.getElementById('btn').classList.add('loading');
});

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
    initTypewriter('typewriterLeft', [
        "សូមស្វាគមន៍មកកាន់ POS!",
        "កាហ្វេឆ្ងាញ់ជារៀងរាល់ថ្ងៃ!",
        "Bird's Nest POS"
    ], 80, 40, 3200);

    setTimeout(function() {
        initTypewriter('typewriterRight', [
            "ត្រៀមរួចរាល់សម្រាប់បម្រើ!",
            "សុវត្ថិភាព និងរហ័សទាន់ចិត្ត!",
            "ប្រព័ន្ធ POS ទំនើបទាន់សម័យ"
        ], 80, 40, 3200);
    }, 1200);
});
</script>
</body>
</html>
