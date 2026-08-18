<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// ── IP whitelist (optional hardening) ────────────────────────────────────────
// To enable: set $ip_whitelist_enabled = true and replace IPs with your actual
// POS terminal / office IPs.
$ip_whitelist_enabled = false;
$allowed_ips = ['192.168.1.54', '127.0.0.1', '::1'];
if ($ip_whitelist_enabled && !in_array($_SERVER['REMOTE_ADDR'], $allowed_ips)) {
    http_response_code(403);
    die(<<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Access Denied</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}

@keyframes fadeInUp  { from{opacity:0;transform:translateY(28px)} to{opacity:1;transform:translateY(0)} }
@keyframes floatCup  { 0%,100%{transform:translateY(0) rotate(-4deg)} 50%{transform:translateY(-10px) rotate(4deg)} }
@keyframes glowPulse { 0%,100%{opacity:.5} 50%{opacity:1} }
@keyframes scanline  { 0%{transform:translateY(-100%)} 100%{transform:translateY(400%)} }

body {
  font-family:'Poppins',sans-serif;
  background:#070707;
  color:#e8e8e8;
  min-height:100vh;
  display:flex;align-items:center;justify-content:center;
  padding:24px;
  overflow:hidden;
}

/* Ambient blobs */
.blob {
  position:fixed;border-radius:50%;filter:blur(80px);pointer-events:none;
}
.blob-1{width:400px;height:400px;background:rgba(209,144,75,.07);top:-100px;left:-100px;}
.blob-2{width:300px;height:300px;background:rgba(239,68,68,.05);bottom:-80px;right:-80px;}

.card {
  position:relative;
  text-align:center;
  max-width:400px;width:100%;
  background:rgba(255,255,255,.03);
  border:1px solid rgba(255,255,255,.08);
  border-radius:24px;
  padding:44px 36px 36px;
  backdrop-filter:blur(12px);
  box-shadow:0 0 0 1px rgba(255,255,255,.04), 0 32px 64px rgba(0,0,0,.6);
  animation:fadeInUp .6s cubic-bezier(.16,1,.3,1) both;
  overflow:hidden;
}

/* Subtle scanline sweep */
.card::before {
  content:'';
  position:absolute;inset:0;
  background:linear-gradient(180deg,transparent 0%,rgba(255,255,255,.015) 50%,transparent 100%);
  height:40%;top:0;left:0;right:0;
  animation:scanline 4s linear infinite;
  pointer-events:none;
}

/* Top accent bar */
.card::after {
  content:'';
  position:absolute;top:0;left:10%;right:10%;height:1px;
  background:linear-gradient(90deg,transparent,rgba(209,144,75,.6),transparent);
}

.cup-wrap {
  font-size:58px;line-height:1;
  margin-bottom:24px;
  display:inline-block;
  animation:floatCup 3.5s ease-in-out infinite;
  filter:drop-shadow(0 8px 24px rgba(209,144,75,.25));
}

.badge {
  display:inline-block;
  font-size:10px;font-weight:600;letter-spacing:1.2px;
  color:#ef4444;text-transform:uppercase;
  background:rgba(239,68,68,.1);
  border:1px solid rgba(239,68,68,.2);
  border-radius:20px;padding:3px 12px;
  margin-bottom:16px;
  animation:glowPulse 2.5s ease-in-out infinite;
}

h1 {
  font-size:20px;font-weight:700;color:#f0f0f0;
  line-height:1.3;margin-bottom:12px;
}

.sub {
  font-size:13px;color:#555;line-height:1.75;margin-bottom:0;
}
.sub em { font-style:normal;color:#888; }

.divider {
  height:1px;background:rgba(255,255,255,.05);
  margin:24px 0;
}

.hint {
  font-size:11px;color:#383838;letter-spacing:.2px;line-height:1.6;
}
</style>
</head>
<body>

<div class="blob blob-1"></div>
<div class="blob blob-2"></div>

<div class="card">
    <div class="cup-wrap">☕</div>
    <div class="badge">403 — Access Denied</div>
    <h1>Wrong machine,<br>wrong place.</h1>
    <p class="sub">
        This POS only runs from the <em>cafe terminal</em>.<br>
        You can't order coffee from here either, sorry.
    </p>
    <div class="divider"></div>
    <p class="hint">Think this is a mistake? Let your manager know.</p>
</div>

</body>
</html>
HTML);
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// ── Session timeout: 30 minutes idle ──
$timeout = 30 * 60;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    // Auto-clock-out on idle timeout (mirror logout.php) so an open attendance
    // record closes at the real time, not at 23:59:59 via the day-rollover sweep.
    require_once 'config.php';
    $uid = (int)$_SESSION['user_id'];
    $conn->query("UPDATE attendance
                  SET clock_out    = NOW(),
                      hours_worked = ROUND(TIMESTAMPDIFF(MINUTE, clock_in, NOW()) / 60, 2)
                  WHERE user_id = $uid AND date = CURDATE() AND clock_out IS NULL");
    session_unset();
    session_destroy();
    header("Location: login.php?timeout=1");
    exit;
}
$_SESSION['last_activity'] = time();

// ── Re-sync role from DB so admin role changes take effect on next page load ──
require_once 'config.php';
$_rs = $conn->prepare("SELECT u.role FROM users u WHERE u.user_id = ?");
$_rs->bind_param("i", $_SESSION['user_id']);
$_rs->execute();
$_rr = $_rs->get_result()->fetch_assoc();
if (!$_rr) {
    // Account deleted — force logout (close any open attendance record first)
    $uid = (int)$_SESSION['user_id'];
    $conn->query("UPDATE attendance
                  SET clock_out    = NOW(),
                      hours_worked = ROUND(TIMESTAMPDIFF(MINUTE, clock_in, NOW()) / 60, 2)
                  WHERE user_id = $uid AND date = CURDATE() AND clock_out IS NULL");
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}
$_SESSION['role']    = $_rr['role'] ?? 'staff';
unset($_rs, $_rr);
?>
