<?php
session_start();
require 'config.php';

$error = '';
if (isset($_GET['timeout']))       $error = 'Session expired due to inactivity. Please sign in again.';
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
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT u.*, u.username AS emp_name FROM users u WHERE u.username = ? LIMIT 1");
    $stmt->bind_param("s", $username); $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']           = $user['user_id'];
            $_SESSION['username']          = $user['username'];
            $_SESSION['emp_name']          = $user['emp_name'] ?: $user['username'];
            $_SESSION['role']              = $user['role'];
            $_SESSION['last_activity']     = time();
            $_SESSION['login_time']        = time();
            $_SESSION['flash_welcome']     = true;
            $_SESSION['flash_stock_alert'] = true;
            if (!empty($user['must_change_password']) || (!empty($user['must_set_security']) && empty($user['security_question']))) { header("Location: profile.php"); exit; }
            header("Location: loading.php"); exit;
        }
    }
    header("Location: login.php?error=1"); exit;
}
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>" data-lang="<?= current_lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In — Bird's Nest Coffee</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Noto+Sans+Khmer:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600&family=Syne:wght@700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Noto+Sans+Khmer:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600&family=Syne:wght@700;800&display=swap');

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:     #0d0a07;
    --card:   #161109;
    --border: rgba(255,255,255,0.07);
    --gold:   #e0a050;
    --gold2:  #c07830;
    --text:   #f0ebe4;
    --muted:  #6b5e4e;
    --dim:    #2a1f14;
    --err:    #e05050;
}

html, body {
    min-height: 100vh;
    background: var(--bg);
    font-family: 'Outfit', 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', sans-serif;
    color: var(--text);
    overflow-x: hidden;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

:lang(km), [data-lang="km"], html[lang="km"], html[lang="km"] * {
    font-family: 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Outfit', sans-serif !important;
}

/* ── BACKGROUND SCENE ── */
.bg {
    position: fixed; inset: 0;
    z-index: 0;
    overflow: hidden;
}

/* Deep warm radial */
.bg::before {
    content: '';
    position: absolute; inset: 0;
    background:
        radial-gradient(ellipse 80% 60% at 50% 0%, rgba(180,90,20,0.22) 0%, transparent 65%),
        radial-gradient(ellipse 50% 40% at 80% 80%, rgba(120,50,10,0.15) 0%, transparent 60%),
        radial-gradient(ellipse 40% 30% at 10% 70%, rgba(100,40,5,0.1) 0%, transparent 55%);
}

/* Geometric rings */
.ring {
    position: absolute;
    border-radius: 50%;
    border: 1px solid rgba(200,120,40,0.06);
}
.ring-1 { width: 700px; height: 700px; top: 50%; left: 50%; transform: translate(-50%,-50%); }
.ring-2 { width: 900px; height: 900px; top: 50%; left: 50%; transform: translate(-50%,-50%); border-color: rgba(200,120,40,0.04); }
.ring-3 { width: 1100px; height: 1100px; top: 50%; left: 50%; transform: translate(-50%,-50%); border-color: rgba(200,120,40,0.025); }

/* Floating orbs */
.orb {
    position: absolute;
    border-radius: 50%;
    filter: blur(80px);
    animation: drift 12s ease-in-out infinite;
}
.orb-1 {
    width: 380px; height: 380px;
    background: radial-gradient(circle, rgba(180,90,15,0.35) 0%, transparent 70%);
    top: -80px; right: -60px;
    animation-delay: 0s;
}
.orb-2 {
    width: 280px; height: 280px;
    background: radial-gradient(circle, rgba(120,50,5,0.25) 0%, transparent 70%);
    bottom: -40px; left: -40px;
    animation-delay: -5s;
}
.orb-3 {
    width: 200px; height: 200px;
    background: radial-gradient(circle, rgba(200,130,30,0.15) 0%, transparent 70%);
    top: 40%; left: 15%;
    animation-delay: -9s;
}
@keyframes drift {
    0%, 100% { transform: translate(0, 0) scale(1); }
    33%       { transform: translate(20px, -30px) scale(1.05); }
    66%       { transform: translate(-15px, 20px) scale(0.97); }
}

/* Subtle grid */
.bg::after {
    content: '';
    position: absolute; inset: 0;
    background-image:
        linear-gradient(rgba(255,255,255,0.012) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,0.012) 1px, transparent 1px);
    background-size: 60px 60px;
    mask-image: radial-gradient(ellipse 70% 70% at 50% 50%, black 0%, transparent 100%);
}

/* ── PAGE ── */
.page {
    position: relative; z-index: 1;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
}

/* ── TOP WORDMARK ── */
.wordmark {
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 36px;
}
.wordmark-icon {
    width: 38px; height: 38px;
    background: linear-gradient(135deg, var(--gold), var(--gold2));
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    color: #0d0a07; font-size: 16px;
    box-shadow: 0 4px 20px rgba(200,120,30,0.4);
}
.wordmark-name {
    font-family: 'Syne', sans-serif;
    font-size: 15px; font-weight: 700;
    color: var(--text);
    letter-spacing: .01em;
    line-height: 1.2;
}
.wordmark-name small {
    display: block;
    font-family: 'Outfit', sans-serif;
    font-size: 10px; font-weight: 400;
    color: var(--muted);
    letter-spacing: .12em;
    text-transform: uppercase;
}

/* ── CARD ── */
.card {
    width: 100%; max-width: 440px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 40px 40px 36px;
    position: relative;
    overflow: hidden;
    box-shadow:
        0 0 0 1px rgba(255,255,255,0.03),
        0 32px 64px rgba(0,0,0,0.6),
        0 8px 24px rgba(0,0,0,0.4);
    animation: cardIn .5s cubic-bezier(.16,1,.3,1) both;
}
@keyframes cardIn {
    from { opacity: 0; transform: translateY(24px) scale(.98); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}

/* Top glow bar */
.card::before {
    content: '';
    position: absolute; top: 0; left: 10%; right: 10%; height: 1px;
    background: linear-gradient(90deg, transparent, var(--gold), transparent);
    opacity: .6;
}

/* Corner accent */
.card::after {
    content: '';
    position: absolute; top: -60px; right: -60px;
    width: 180px; height: 180px;
    background: radial-gradient(circle, rgba(200,120,30,0.1) 0%, transparent 70%);
    pointer-events: none;
}

/* ── CARD HEADER ── */
.card-greeting {
    font-size: 10.5px; font-weight: 600;
    letter-spacing: .15em;
    text-transform: uppercase;
    color: var(--gold);
    margin-bottom: 8px;
    animation: fadeUp .4s .15s ease both; opacity: 0;
}
.card-title {
    font-family: 'Syne', sans-serif;
    font-size: 30px; font-weight: 800;
    color: var(--text);
    line-height: 1.1;
    margin-bottom: 4px;
    animation: fadeUp .4s .2s ease both; opacity: 0;
}
.card-sub {
    font-size: 13.5px; font-weight: 300;
    color: var(--muted);
    margin-bottom: 30px;
    animation: fadeUp .4s .25s ease both; opacity: 0;
}

/* ── ERROR ── */
.error-box {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 12px 14px;
    background: rgba(200,60,60,0.08);
    border: 1px solid rgba(200,60,60,0.2);
    border-radius: 10px;
    color: #e07070;
    font-size: 13px; line-height: 1.5;
    margin-bottom: 22px;
    animation: shake .4s ease;
}
.error-box i { color: var(--err); flex-shrink: 0; margin-top: 2px; }
@keyframes shake {
    0%,100% { transform: translateX(0); }
    20%,60%  { transform: translateX(-6px); }
    40%,80%  { transform: translateX(6px); }
}

/* ── FIELDS ── */
.field {
    margin-bottom: 14px;
    animation: fadeUp .4s ease both; opacity: 0;
}
.field:nth-of-type(1) { animation-delay: .3s; }
.field:nth-of-type(2) { animation-delay: .36s; }

.field-label {
    display: block;
    font-size: 11px; font-weight: 600;
    letter-spacing: .1em; text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 8px;
}

.input-wrap {
    position: relative;
}
.input-wrap i.ico {
    position: absolute; left: 15px; top: 50%;
    transform: translateY(-50%);
    color: var(--muted); font-size: 13px;
    pointer-events: none;
    transition: color .2s ease;
}
.input-wrap input {
    width: 100%;
    height: 50px;
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 10px;
    padding: 0 44px 0 42px;
    color: var(--text);
    font-family: 'Outfit', sans-serif;
    font-size: 14.5px; font-weight: 400;
    outline: none;
    transition: border-color .2s, background .2s, box-shadow .2s;
}
.input-wrap input::placeholder { color: rgba(240,235,228,0.18); }
.input-wrap input:focus {
    border-color: rgba(200,120,30,0.55);
    background: rgba(200,120,30,0.05);
    box-shadow: 0 0 0 3px rgba(200,120,30,0.1);
}
.input-wrap:focus-within i.ico { color: var(--gold); }

.eye-btn {
    position: absolute; right: 12px; top: 50%;
    transform: translateY(-50%);
    background: none; border: none;
    color: var(--muted); font-size: 13px;
    cursor: pointer; padding: 4px;
    transition: color .2s;
}
.eye-btn:hover { color: var(--text); }

/* ── REMEMBER ── */
.remember {
    display: flex; align-items: center; gap: 9px;
    margin-bottom: 26px; cursor: pointer;
    user-select: none;
    animation: fadeUp .4s .42s ease both; opacity: 0;
}
.remember input { display: none; }
.cb {
    width: 20px; height: 20px; flex-shrink: 0;
    border: 1.5px solid rgba(255,255,255,0.12);
    border-radius: 5px;
    display: flex; align-items: center; justify-content: center;
    font-size: 10px; color: transparent;
    transition: all .2s ease;
    background: rgba(255,255,255,0.03);
}
.remember input:checked + .cb {
    background: var(--gold);
    border-color: var(--gold);
    color: #0d0a07;
}
.remember span { font-size: 13px; color: var(--muted); font-weight: 400; }

/* ── SUBMIT ── */
.btn {
    width: 100%; height: 52px;
    border: none; border-radius: 12px;
    background: linear-gradient(135deg, #e0a050 0%, #b06020 100%);
    color: #0d0a07;
    font-family: 'Outfit', sans-serif;
    font-size: 15px; font-weight: 600;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 10px;
    position: relative; overflow: hidden;
    box-shadow: 0 4px 20px rgba(180,90,20,0.4), 0 1px 0 rgba(255,255,255,0.1) inset;
    transition: transform .15s ease, box-shadow .2s ease, filter .2s ease;
    animation: fadeUp .4s .48s ease both; opacity: 0;
    letter-spacing: .01em;
}
.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 32px rgba(180,90,20,0.55), 0 1px 0 rgba(255,255,255,0.1) inset;
    filter: brightness(1.08);
}
.btn:active { transform: translateY(0); }

/* Shimmer sweep */
.btn::after {
    content: '';
    position: absolute; inset: 0;
    background: linear-gradient(105deg, transparent 35%, rgba(255,255,255,0.18) 50%, transparent 65%);
    background-size: 250% 100%; background-position: 200% 0;
    transition: background-position .6s ease;
}
.btn:hover::after { background-position: -50% 0; }

.ripple {
    position: absolute; border-radius: 50%;
    background: rgba(0,0,0,0.15);
    transform: scale(0);
    animation: rpl .5s linear;
    pointer-events: none;
}
@keyframes rpl { to { transform: scale(5); opacity: 0; } }

.spinner {
    display: none; width: 18px; height: 18px;
    border: 2px solid rgba(0,0,0,0.2);
    border-top-color: #0d0a07;
    border-radius: 50%;
    animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
.btn.loading .btn-label { display: none; }
.btn.loading .spinner   { display: block; }

/* ── FOOTER ── */
.card-foot {
    display: flex; align-items: center; justify-content: space-between;
    margin-top: 20px;
    animation: fadeUp .4s .54s ease both; opacity: 0;
}
.foot-a {
    font-size: 12.5px; color: var(--muted);
    text-decoration: none;
    display: flex; align-items: center; gap: 5px;
    transition: color .2s;
}
.foot-a:hover { color: var(--text); }
.foot-a.accent { color: var(--gold); }
.foot-a.accent:hover { color: #f0b860; }

/* ── BELOW CARD ── */
.secure {
    margin-top: 22px;
    font-size: 11px; color: rgba(240,235,228,0.2);
    letter-spacing: .1em;
    display: flex; align-items: center; gap: 6px;
    animation: fadeUp .4s .6s ease both; opacity: 0;
}
.secure i { color: rgba(100,200,100,0.4); }

/* ── KEYFRAME ── */
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ── MOBILE ── */
@media (max-width: 500px) {
    .card { padding: 32px 24px 28px; border-radius: 16px; }
    .card-title { font-size: 26px; }
}
</style>
</head>
<body>

<div class="bg">
    <div class="ring ring-1"></div>
    <div class="ring ring-2"></div>
    <div class="ring ring-3"></div>
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
</div>

<div class="page">

    <div class="wordmark">
        <div class="wordmark-icon"><i class="fa-solid fa-mug-hot"></i></div>
        <div class="wordmark-name">
            Bird's Nest Coffee
            <small>Staff Portal</small>
        </div>
    </div>

    <div class="card">
        <p class="card-greeting" id="greeting">Good evening</p>
        <h1 class="card-title">Sign in</h1>
        <p class="card-sub">Enter your credentials to continue.</p>

        <?php if (!empty($error)): ?>
        <div class="error-box">
            <i class="fa-solid fa-circle-exclamation"></i>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" id="form" autocomplete="off">

            <div class="field">
                <label class="field-label" for="u">Username</label>
                <div class="input-wrap">
                    <i class="fa-solid fa-user ico"></i>
                    <input type="text" name="username" id="u" placeholder="Your username" required autofocus autocomplete="username">
                </div>
            </div>

            <div class="field">
                <label class="field-label" for="p">Password</label>
                <div class="input-wrap">
                    <i class="fa-solid fa-lock ico"></i>
                    <input type="password" name="password" id="p" placeholder="Your password" required autocomplete="current-password">
                    <button type="button" class="eye-btn" id="eye">
                        <i class="fa-solid fa-eye" id="eyeIco"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn" id="btn">
                <span class="btn-label">Sign In &nbsp;<i class="fa-solid fa-arrow-right-to-bracket"></i></span>
                <span class="spinner"></span>
            </button>
        </form>

        <div class="card-foot">
            <a href="forgot_password.php" class="foot-a accent"><i class="fa-solid fa-key"></i> Forgot password?</a>
        </div>
    </div>

    <div class="secure">
        <i class="fa-solid fa-shield-halved"></i>
        STAFF ONLY &nbsp;·&nbsp; SECURED &nbsp;·&nbsp; <?= date('Y') ?>
    </div>

</div>

<script>
// Greeting
(function(){
    const h = new Date().getHours();
    document.getElementById('greeting').textContent =
        h < 5 ? 'Working late?' : h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
})();

// Eye toggle
document.getElementById('eye').addEventListener('click', function(){
    const inp = document.getElementById('p');
    const ico = document.getElementById('eyeIco');
    const show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    ico.className = show ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
});

// Ripple
document.getElementById('btn').addEventListener('click', function(e){
    const b = this, r = b.getBoundingClientRect();
    const sz = Math.max(r.width, r.height);
    const rpl = document.createElement('span');
    rpl.className = 'ripple';
    rpl.style.cssText = `width:${sz}px;height:${sz}px;left:${e.clientX-r.left-sz/2}px;top:${e.clientY-r.top-sz/2}px`;
    b.appendChild(rpl);
    rpl.addEventListener('animationend', () => rpl.remove());
});

// Loading state
document.getElementById('form').addEventListener('submit', function(){
    document.getElementById('btn').classList.add('loading');
});
</script>
</body>
</html>
