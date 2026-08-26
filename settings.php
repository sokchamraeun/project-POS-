<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lang.php';
if (!can('settings')) { header("Location: dashboard.php?denied=1"); exit; }

$isKm = (current_lang() === 'km');
$message = '';
$message_type = '';

// ── SAVE SETTINGS ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_exchange') {
        $rate = (string)max(100, min(99999, (int)($_POST['khr_exchange_rate'] ?? 4000)));
        $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('khr_exchange_rate', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->bind_param('s', $rate);
        if ($stmt->execute()) {
            header("Location: settings.php?saved=exchange");
            exit;
        }
        $message      = $isKm ? ('កំហុសក្នុងការរក្សាទុកអត្រាប្តូរប្រាក់៖ ' . $conn->error) : ('Error saving exchange rate: ' . $conn->error);
        $message_type = 'error';
    } elseif ($action === 'save_receipt') {
        $shop_name  = trim((string)($_POST['receipt_shop_name'] ?? 'The Bird Nest Cafe'));
        $location   = trim((string)($_POST['receipt_location'] ?? 'Phnom Penh'));
        $phone      = trim((string)($_POST['receipt_phone'] ?? '+855 12 345 678'));
        $footer_msg = trim((string)($_POST['receipt_footer_msg'] ?? 'Thank You!'));
        
        $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        
        $k1 = 'receipt_shop_name';  $stmt->bind_param('ss', $k1, $shop_name);  $stmt->execute();
        $k2 = 'receipt_location';   $stmt->bind_param('ss', $k2, $location);   $stmt->execute();
        $k3 = 'receipt_phone';      $stmt->bind_param('ss', $k3, $phone);      $stmt->execute();
        $k4 = 'receipt_footer_msg'; $stmt->bind_param('ss', $k4, $footer_msg); $stmt->execute();
        
        header("Location: settings.php?saved=receipt");
        exit;
    } elseif ($action === 'save_smtp') {
        $smtp_enabled    = isset($_POST['smtp_enabled']) ? '1' : '0';
        $smtp_host       = trim((string)($_POST['smtp_host'] ?? 'smtp.gmail.com'));
        $smtp_port       = trim((string)($_POST['smtp_port'] ?? '587'));
        $smtp_secure     = trim((string)($_POST['smtp_secure'] ?? 'tls'));
        $smtp_user       = trim((string)($_POST['smtp_user'] ?? ''));
        $smtp_pass       = trim((string)($_POST['smtp_pass'] ?? ''));
        $mail_from_email = trim((string)($_POST['mail_from_email'] ?? ''));
        $mail_from_name  = trim((string)($_POST['mail_from_name'] ?? "Bird's Nest Coffee POS"));

        $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        
        $params = [
            'smtp_enabled'    => $smtp_enabled,
            'smtp_host'       => $smtp_host,
            'smtp_port'       => $smtp_port,
            'smtp_secure'     => $smtp_secure,
            'smtp_user'       => $smtp_user,
            'smtp_pass'       => $smtp_pass,
            'mail_from_email' => $mail_from_email,
            'mail_from_name'  => $mail_from_name,
        ];

        foreach ($params as $sk => $sv) {
            $stmt->bind_param('ss', $sk, $sv);
            $stmt->execute();
        }

        header("Location: settings.php?saved=smtp");
        exit;
    } elseif ($action === 'test_smtp') {
        require_once __DIR__ . '/mail_helper.php';
        $test_email = trim((string)($_POST['test_email'] ?? ''));
        if (empty($test_email) || !filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
            $message      = $isKm ? 'សូមបញ្ចូលអាសយដ្ឋានអ៊ីមែលត្រឹមត្រូវដើម្បីធ្វើតេស្ត។' : 'Please enter a valid email address for testing.';
            $message_type = 'error';
        } else {
            $test_res = send_app_email(
                $test_email,
                'Tester',
                "Bird's Nest POS — Test Email",
                "<div style='font-family:sans-serif;padding:24px;background:#090d16;color:#ffffff;border-radius:14px;border:1px solid rgba(16,185,129,0.3);box-shadow:0 8px 24px rgba(0,0,0,0.5);'><div style='display:flex;align-items:center;gap:10px;'><h2 style='color:#10b981;margin:0;'>☕ Bird's Nest Coffee POS</h2></div><p style='color:#cbd5e1;font-size:15px;margin-top:14px;line-height:1.6;'>This is a test email confirming that your email and SMTP configuration are working properly!</p><p style='color:#64748b;font-size:12px;margin-top:20px;border-top:1px solid #1e293b;padding-top:12px;'>Sent automatically from Bird's Nest POS System Settings.</p></div>"
            );
            if ($test_res['success']) {
                $message      = $isKm ? ('បានផ្ញើអ៊ីមែលតេស្តទៅកាន់ ' . htmlspecialchars($test_email) . ' ដោយជោគជ័យ!') : ('Test email sent successfully to ' . htmlspecialchars($test_email) . '!');
                $message_type = 'success';
            } else {
                $message      = ($isKm ? 'បរាជ័យក្នុងការផ្ញើអ៊ីមែល៖ ' : 'Failed to send email: ') . htmlspecialchars($test_res['error'] ?? 'Unknown error');
                $message_type = 'error';
            }
        }
    }
}

// ── FLASH MESSAGE ──
if (isset($_GET['saved'])) {
    if ($_GET['saved'] === 'exchange') {
        $message = $isKm ? 'បានរក្សាទុកអត្រាប្តូរប្រាក់ដោយជោគជ័យ។' : 'Exchange rate saved successfully.';
    } elseif ($_GET['saved'] === 'smtp') {
        $message = $isKm ? 'បានរក្សាទុកការកំណត់អ៊ីមែល SMTP ដោយជោគជ័យ។' : 'SMTP Email settings saved successfully.';
    } else {
        $message = $isKm ? 'បានរក្សាទុកព័ត៌មានវិក្កយបត្រ (ឈ្មោះហាង, ទីតាំង, ទូរស័ព្ទ, សារ) ដោយជោគជ័យ។' : 'Receipt details (Shop Name, Location, Phone, Footer) saved successfully.';
    }
    $message_type = 'success';
}

// ── LOAD CURRENT SETTINGS ──
$settings_map = [];
$res = $conn->query("SELECT setting_key, setting_value FROM settings");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $settings_map[$r['setting_key']] = $r['setting_value'];
    }
}

$khr_rate           = (int)($settings_map['khr_exchange_rate'] ?? 4000);
$receipt_shop_name  = $settings_map['receipt_shop_name']  ?? 'The Bird Nest Cafe';
$receipt_location   = $settings_map['receipt_location']   ?? 'Phnom Penh';
$receipt_phone      = $settings_map['receipt_phone']      ?? '+855 12 345 678';
$receipt_footer_msg = $settings_map['receipt_footer_msg'] ?? 'Thank You!';

$smtp_enabled       = ($settings_map['smtp_enabled'] ?? '0') === '1';
$smtp_host          = $settings_map['smtp_host']          ?? 'smtp.gmail.com';
$smtp_port          = $settings_map['smtp_port']          ?? '587';
$smtp_secure        = $settings_map['smtp_secure']        ?? 'tls';
$smtp_user          = $settings_map['smtp_user']          ?? '';
$smtp_pass          = $settings_map['smtp_pass']          ?? '';
$mail_from_email    = $settings_map['mail_from_email']    ?? '';
$mail_from_name     = $settings_map['mail_from_name']     ?? "Bird's Nest Coffee POS";
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>" data-lang="<?= current_lang() ?>">
<head>
<meta charset="UTF-8">
<script>(function(){if(localStorage.getItem('theme')==='light')document.documentElement.setAttribute('data-theme','light');})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $isKm ? 'ការកំណត់ប្រព័ន្ធ & វិក្កយបត្រ — Bird\'s Nest Coffee' : 'Bird\'s Nest Coffee — System & Receipt Settings' ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Noto+Sans+Khmer:wght@300;400;500;600;700;800&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap" rel="stylesheet">
<style>
@import url('https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Noto+Sans+Khmer:wght@300;400;500;600;700;800&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap');

/* ══ MASTER SETTINGS THEME SYSTEM (Midnight Slate & Emerald Accent) ══ */
:root {
  --bg:            #090d16;
  --panel:         rgba(18, 24, 38, 0.75);
  --panel-solid:   #0f172a;
  --panel-border:  rgba(255, 255, 255, 0.08);
  --panel-shadow:  0 8px 32px 0 rgba(0, 0, 0, 0.4), inset 0 1px 0 0 rgba(255, 255, 255, 0.06);
  
  --accent:        #10b981;
  --accent-hover:  #059669;
  --accent-glow:   rgba(16, 185, 129, 0.25);
  --accent-light:  rgba(16, 185, 129, 0.12);
  --accent-cyan:   #06b6d4;
  --accent-amber:  #f59e0b;
  --accent-violet: #8b5cf6;
  
  --text:          #f8fafc;
  --text-muted:    #94a3b8;
  --text-sub:      #64748b;
  
  --input-bg:      rgba(8, 14, 28, 0.85);
  --input-border:  rgba(255, 255, 255, 0.1);
  --input-focus:   #10b981;
  
  --success:       #10b981;
  --danger:        #ef4444;
  --radius:        20px;
  --radius-sm:     12px;
  --transition:    all 0.22s cubic-bezier(0.4, 0, 0.2, 1);
}

/* ══ LIGHT THEME OVERRIDES ══ */
[data-theme="light"], html[data-theme="light"] {
  --bg:            #f8fafc !important;
  --panel:         #ffffff !important;
  --panel-solid:   #ffffff !important;
  --panel-border:  rgba(226, 232, 240, 0.9) !important;
  --panel-shadow:  0 4px 20px rgba(0, 0, 0, 0.04), 0 1px 3px rgba(0, 0, 0, 0.02) !important;
  
  --text:          #0f172a !important;
  --text-muted:    #64748b !important;
  --text-sub:      #94a3b8 !important;
  
  --input-bg:      #f1f5f9 !important;
  --input-border:  #cbd5e1 !important;
  --input-focus:   #10b981 !important;
}

* { box-sizing: border-box; margin: 0; padding: 0; }
body, input, select, textarea, button, table {
  background: var(--bg);
  color: var(--text);
  font-family: 'Poppins', 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Khmer OS Battambang', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  font-size: 14px;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}

:lang(km), [data-lang="km"], html[lang="km"], html[lang="km"] * {
  font-family: 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Khmer OS Battambang', 'Khmer OS Siemreap', 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
}

i, .fa, .fa-solid, .fa-regular, .fa-brands, [class*="fa-"] {
  font-family: 'Font Awesome 6 Free', 'FontAwesome' !important;
}
.fa-brands {
  font-family: 'Font Awesome 6 Brands', 'FontAwesome' !important;
}

.wrapper {
  max-width: 900px;
  margin: 0 auto;
}

/* ── HERO BANNER ── */
.hero {
  background: radial-gradient(circle at 90% 15%, rgba(16, 185, 129, 0.16) 0%, transparent 60%),
              radial-gradient(circle at 10% 85%, rgba(6, 182, 212, 0.12) 0%, transparent 60%),
              var(--panel);
  border: 1px solid var(--panel-border);
  border-radius: var(--radius);
  padding: 28px 30px;
  margin-bottom: 24px;
  position: relative;
  overflow: hidden;
  box-shadow: var(--panel-shadow);
  -webkit-backdrop-filter: blur(16px);
  backdrop-filter: blur(16px);
  display: flex;
  align-items: center;
  gap: 22px;
}
.hero-icon {
  width: 54px;
  height: 54px;
  border-radius: 16px;
  background: linear-gradient(135deg, rgba(16, 185, 129, 0.25) 0%, rgba(6, 182, 212, 0.15) 100%);
  border: 1px solid rgba(16, 185, 129, 0.35);
  color: var(--accent);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 24px;
  flex-shrink: 0;
  box-shadow: 0 4px 16px rgba(16, 185, 129, 0.2);
}
.hero-content {
  flex: 1;
  min-width: 0;
}
.hero h1 {
  font-size: 22px;
  font-weight: 700;
  color: var(--text);
  margin-bottom: 4px;
  letter-spacing: -0.02em;
}
.hero p {
  font-size: 13px;
  color: var(--text-muted);
  line-height: 1.5;
}

/* ── SETTINGS CARD ── */
.card {
  background: var(--panel);
  border: 1px solid var(--panel-border);
  border-radius: var(--radius);
  margin-bottom: 24px;
  overflow: hidden;
  box-shadow: var(--panel-shadow);
  -webkit-backdrop-filter: blur(16px);
  backdrop-filter: blur(16px);
  transition: transform 0.22s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.22s cubic-bezier(0.4, 0, 0.2, 1), border-color 0.22s ease;
}
.card:hover {
  border-color: rgba(16, 185, 129, 0.28);
}
.card-header {
  padding: 18px 24px;
  border-bottom: 1px solid var(--panel-border);
  display: flex;
  align-items: center;
  gap: 14px;
  background: rgba(255, 255, 255, 0.02);
}
[data-theme="light"] .card-header {
  background: #f8fafc !important;
}

.card-icon {
  width: 40px;
  height: 40px;
  border-radius: 12px;
  background: var(--accent-light);
  border: 1px solid rgba(16, 185, 129, 0.25);
  color: var(--accent);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 17px;
  flex-shrink: 0;
}
.card-icon.icon-amber {
  background: rgba(245, 158, 11, 0.12);
  border-color: rgba(245, 158, 11, 0.3);
  color: #f59e0b;
}
.card-icon.icon-violet {
  background: rgba(139, 92, 246, 0.12);
  border-color: rgba(139, 92, 246, 0.3);
  color: #a78bfa;
}
.card-icon.icon-cyan {
  background: rgba(6, 182, 212, 0.12);
  border-color: rgba(6, 182, 212, 0.3);
  color: #06b6d4;
}

.card-title {
  font-size: 15.5px;
  font-weight: 700;
  color: var(--text);
  letter-spacing: -0.01em;
}
.card-sub {
  font-size: 12px;
  color: var(--text-muted);
  margin-top: 2px;
}
.card-inner {
  padding: 24px;
}

/* ── FORM FIELDS ── */
.form-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 18px;
}
@media (max-width: 680px) {
  .form-grid { grid-template-columns: 1fr; }
}

.field {
  display: flex;
  flex-direction: column;
}
.field label {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 700;
  color: var(--text);
  margin-bottom: 7px;
  letter-spacing: 0.2px;
}
.field label i {
  color: var(--accent);
  font-size: 13px;
}
.field input[type="text"],
.field input[type="number"],
.field input[type="email"],
.field input[type="password"],
.field select {
  width: 100%;
  padding: 11px 15px;
  background: var(--input-bg);
  border: 1px solid var(--input-border);
  border-radius: var(--radius-sm);
  color: var(--text);
  font-family: inherit;
  font-size: 13.5px;
  font-weight: 500;
  outline: none;
  transition: var(--transition);
  box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.2);
}
[data-theme="light"] .field input[type="text"],
[data-theme="light"] .field input[type="number"],
[data-theme="light"] .field input[type="email"],
[data-theme="light"] .field input[type="password"],
[data-theme="light"] .field select {
  box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.05) !important;
}

.field input:focus,
.field select:focus {
  border-color: var(--accent) !important;
  box-shadow: 0 0 0 3px var(--accent-glow), inset 0 1px 2px rgba(0, 0, 0, 0.2) !important;
}
.field-hint {
  display: block;
  font-size: 11.5px;
  color: var(--text-muted);
  margin-top: 5px;
  line-height: 1.4;
}

/* ── PREVIEW ROWS & BADGES ── */
.preview-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  background: rgba(245, 158, 11, 0.08);
  border: 1px dashed rgba(245, 158, 11, 0.35);
  border-radius: var(--radius-sm);
  padding: 14px 18px;
  color: #f59e0b;
  font-size: 14px;
  font-weight: 700;
}
[data-theme="light"] .preview-row {
  background: rgba(245, 158, 11, 0.06) !important;
  border-color: rgba(245, 158, 11, 0.4) !important;
  color: #b45309 !important;
}

/* ── THERMAL RECEIPT PREVIEW BOX ── */
.receipt-preview-wrapper {
  margin-top: 22px;
  padding-top: 18px;
  border-top: 1px solid var(--panel-border);
}
.receipt-preview-box {
  background: #ffffff;
  color: #0f172a;
  border-radius: 12px;
  padding: 18px 20px;
  width: 100%;
  max-width: 320px;
  margin: 12px auto 0;
  box-shadow: 0 12px 30px rgba(0, 0, 0, 0.4), 0 2px 6px rgba(0, 0, 0, 0.2);
  border: 1px solid rgba(226, 232, 240, 0.8);
  font-family: 'Kantumruy Pro', 'Poppins', -apple-system, sans-serif;
  font-size: 11.5px;
  position: relative;
}
.receipt-preview-box::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 4px;
  background: repeating-linear-gradient(90deg, #10b981 0, #10b981 8px, #06b6d4 8px, #06b6d4 16px);
  border-top-left-radius: 12px;
  border-top-right-radius: 12px;
}

/* ── FORM ACTIONS & BUTTONS ── */
.form-actions {
  padding: 16px 24px;
  background: rgba(0, 0, 0, 0.25);
  border-top: 1px solid var(--panel-border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
[data-theme="light"] .form-actions {
  background: #f8fafc !important;
}
.form-actions-info {
  font-size: 12px;
  color: var(--text-muted);
  display: flex;
  align-items: center;
  gap: 7px;
}

.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 10px 22px;
  border-radius: 12px;
  font-family: inherit;
  font-size: 13.5px;
  font-weight: 700;
  cursor: pointer;
  border: none;
  transition: var(--transition);
  text-decoration: none;
  user-select: none;
  -webkit-user-select: none;
}
.btn-save {
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  color: #ffffff !important;
  box-shadow: 0 4px 14px rgba(16, 185, 129, 0.35);
}
.btn-save:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(16, 185, 129, 0.45);
  background: linear-gradient(135deg, #34d399 0%, #059669 100%);
}
.btn-save:active {
  transform: scale(0.98);
}

.btn-cyan {
  background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
  color: #ffffff !important;
  box-shadow: 0 4px 14px rgba(6, 182, 212, 0.35);
}
.btn-cyan:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(6, 182, 212, 0.45);
}

/* ── FLASH NOTIFICATION ── */
.msg {
  padding: 14px 20px;
  border-radius: 14px;
  font-size: 13.5px;
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 22px;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
}
.msg.success {
  background: rgba(16, 185, 129, 0.14);
  border: 1px solid rgba(16, 185, 129, 0.4);
  color: #34d399;
}
[data-theme="light"] .msg.success {
  background: #ecfdf5 !important;
  color: #047857 !important;
  border-color: #a7f3d0 !important;
}
.msg.error {
  background: rgba(239, 68, 68, 0.14);
  border: 1px solid rgba(239, 68, 68, 0.4);
  color: #f87171;
}
[data-theme="light"] .msg.error {
  background: #fef2f2 !important;
  color: #b91c1c !important;
  border-color: #fecaca !important;
}

/* ── SIDEBAR TOGGLE & BREADCRUMB ── */
.st-sidebar-toggle {
  display: none;
  width: 38px;
  height: 38px;
  min-width: 38px;
  border-radius: 10px;
  background: var(--panel);
  border: 1px solid var(--panel-border);
  color: var(--text);
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: var(--transition);
  padding: 0;
}
.st-sidebar-toggle:hover {
  background: var(--accent-light);
  border-color: var(--accent);
  color: var(--accent);
}

@media (max-width: 768px) {
  .st-sidebar-toggle {
    display: inline-flex !important;
  }
  .app-main {
    padding: 14px 16px !important;
  }
  .hero {
    padding: 20px 18px !important;
    gap: 14px !important;
    border-radius: 16px !important;
  }
  .hero-icon {
    width: 44px !important;
    height: 44px !important;
    font-size: 20px !important;
    border-radius: 12px !important;
  }
  .hero h1 {
    font-size: 18px !important;
  }
  .card {
    border-radius: 16px !important;
    margin-bottom: 16px !important;
  }
  .card-header {
    padding: 14px 16px !important;
  }
  .card-inner {
    padding: 16px !important;
  }
  .form-actions {
    padding: 14px 16px !important;
    flex-direction: column !important;
    align-items: stretch !important;
    gap: 10px !important;
  }
  .form-actions-info {
    justify-content: center;
    text-align: center;
  }
  .btn-save, .btn {
    width: 100% !important;
    height: 42px !important;
  }
}
</style>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body style="margin:0; padding:0; background:var(--bg); color:var(--text); height:100vh; overflow:hidden;">
<div class="flex h-screen w-screen overflow-hidden app-layout" style="display:flex; width:100vw; height:100vh; overflow:hidden;">
<?php require_once __DIR__ . '/sidebar.php'; ?>
<main class="app-main flex-1 h-full overflow-y-auto p-6" style="flex:1; height:100%; overflow-y:auto; -webkit-overflow-scrolling:touch;">

<div class="wrapper">

    <!-- Top Breadcrumb & Mobile Sidebar Toggle -->
    <div class="st-breadcrumb flex items-center justify-between gap-3 mb-4">
        <div class="flex items-center gap-2.5 min-w-0">
            <button type="button" onclick="toggleSidebar()" class="st-sidebar-toggle" title="Toggle Navigation Sidebar">
                <i class="fa-solid fa-bars"></i>
            </button>
            <div class="flex items-center gap-1.5 text-xs font-semibold overflow-hidden whitespace-nowrap text-ellipsis">
                <a href="dashboard.php" style="color:var(--text-muted);text-decoration:none;"><?= __('nav_settings', 'Settings') ?></a>
                <span style="color:var(--text-muted);font-size:10px;">&rsaquo;</span>
                <span style="color:var(--accent);"><?= $isKm ? 'ប្រព័ន្ធ & វិក្កយបត្រ' : 'System & Receipt' ?></span>
            </div>
        </div>
    </div>

    <!-- Hero Header Banner -->
    <div class="hero">
        <div class="hero-icon"><i class="fa-solid fa-sliders"></i></div>
        <div class="hero-content">
            <h1><?= $isKm ? 'ការកំណត់ប្រព័ន្ធ & វិក្កយបត្រ' : 'System & Receipt Settings' ?></h1>
            <p><?= $isKm ? 'គ្រប់គ្រងព័ត៌មានវិក្កយបត្រ (ឈ្មោះហាង, ទីតាំង, លេខទូរស័ព្ទ, ចំណាំខាងក្រោម) អត្រាប្តូរប្រាក់ និងប្រព័ន្ធអ៊ីមែល SMTP' : 'Control thermal receipt details (Shop Name, Location, Phone, Footer), USD to KHR exchange rates, and SMTP email services.' ?></p>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="msg <?= htmlspecialchars($message_type) ?>">
        <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-emerald-400' : 'fa-circle-exclamation text-rose-400' ?> text-lg"></i>
        <div>
            <strong><?= $isKm ? 'ការកំណត់៖' : 'Settings:' ?></strong>&nbsp;<?= htmlspecialchars($message) ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── 1. RECEIPT CONTROL FORM ── -->
    <form method="POST">
        <input type="hidden" name="action" value="save_receipt">
        <div class="card">
            <div class="card-header">
                <div class="card-icon icon-cyan"><i class="fa-solid fa-receipt"></i></div>
                <div>
                    <div class="card-title"><?= $isKm ? 'ការកំណត់វិក្កយបត្រ & ក្បាល/បាតទំព័រ' : 'Receipt Customization & Header/Footer' ?></div>
                    <div class="card-sub"><?= $isKm ? 'គ្រប់គ្រងឈ្មោះហាង ទីតាំង លេខទូរស័ព្ទ និងសារថ្លែងអំណរគុណនៅលើវិក្កយបត្រកម្ដៅ' : 'Control shop title, address/location, contact phone, and thank-you footer printed on thermal receipts' ?></div>
                </div>
            </div>

            <div class="card-inner">
                <div class="form-grid">
                    <div class="field">
                        <label><i class="fa-solid fa-store"></i> <?= $isKm ? 'ឈ្មោះហាង / យីហោ' : 'Shop Name / Brand Title' ?></label>
                        <input type="text" name="receipt_shop_name" id="rcptShopName"
                               value="<?= htmlspecialchars($receipt_shop_name) ?>" placeholder="e.g. The Bird Nest Cafe" required>
                    </div>

                    <div class="field">
                        <label><i class="fa-solid fa-location-dot"></i> <?= $isKm ? 'ទីតាំងហាង / អាសយដ្ឋាន' : 'Shop Location / Address' ?></label>
                        <input type="text" name="receipt_location" id="rcptLocation"
                               value="<?= htmlspecialchars($receipt_location) ?>" placeholder="e.g. Phnom Penh" required>
                    </div>

                    <div class="field">
                        <label><i class="fa-solid fa-phone"></i> <?= $isKm ? 'លេខទូរស័ព្ទ / ទំនាក់ទំនង' : 'Phone Number / Contact' ?></label>
                        <input type="text" name="receipt_phone" id="rcptPhone"
                               value="<?= htmlspecialchars($receipt_phone) ?>" placeholder="e.g. +855 12 345 678">
                    </div>

                    <div class="field">
                        <label><i class="fa-solid fa-heart"></i> <?= $isKm ? 'សារខាងក្រោមវិក្កយបត្រ' : 'Receipt Footer Message' ?></label>
                        <input type="text" name="receipt_footer_msg" id="rcptFooterMsg"
                               value="<?= htmlspecialchars($receipt_footer_msg) ?>" placeholder="e.g. Thank You!" required>
                    </div>
                </div>

                <!-- Live Thermal Receipt Preview Box -->
                <div class="receipt-preview-wrapper">
                    <div class="text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider text-center mb-2 flex items-center justify-center gap-2">
                        <i class="fa-solid fa-eye text-emerald-400"></i> <?= $isKm ? 'ទិដ្ឋភាពជាក់ស្តែងនៃក្បាល & បាតវិក្កយបត្រ' : 'Live Receipt Header & Footer Preview' ?>
                    </div>
                    <div class="receipt-preview-box">
                        <div style="text-align: center; margin-bottom: 8px;">
                            <div id="prevShopName" style="font-size: 15px; font-weight: 800; line-height: 1.2; color:#0f172a;">
                                <?= htmlspecialchars($receipt_shop_name) ?>
                            </div>
                            <div id="prevLocation" style="font-size: 11px; margin-top: 3px; color: #475569;">
                                <?= htmlspecialchars($receipt_location) ?>
                            </div>
                            <div id="prevPhone" style="font-size: 11px; margin-top: 1px; color: #475569;">
                                <?= htmlspecialchars($receipt_phone) ?>
                            </div>
                            <div style="font-size: 13.5px; font-weight: 800; margin-top: 8px; color:#0f172a; letter-spacing:0.5px;">វិក្កយបត្រ / RECEIPT</div>
                        </div>

                        <div style="border-top: 1px dashed #94a3b8; border-bottom: 1px dashed #94a3b8; padding: 6px 0; margin: 8px 0; text-align: center; font-size: 10px; color: #64748b; font-weight:600;">
                            <?= $isKm ? '[ បញ្ជីទំនិញគំរូ / SAMPLE ITEMS ]' : '[ SAMPLE ORDER ITEMS LIST ]' ?>
                        </div>

                        <div style="border-top: 1px dotted #0f172a; margin-top: 10px; padding-top: 8px; text-align: center;">
                            <div id="prevFooterMsg" style="font-weight: 800; font-size: 12px; text-transform: uppercase; color:#0f172a;">
                                <?= htmlspecialchars($receipt_footer_msg) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <div class="form-actions-info">
                    <i class="fa-solid fa-circle-info text-emerald-400"></i>
                    <?= $isKm ? 'រក្សាទុកការកំណត់ក្បាល និងបាតវិក្កយបត្រ' : 'Saves thermal receipt header and footer settings' ?>
                </div>
                <button type="submit" class="btn btn-save">
                    <i class="fa-solid fa-floppy-disk"></i> <?= $isKm ? 'រក្សាទុកការកំណត់វិក្កយបត្រ' : 'Save Receipt Settings' ?>
                </button>
            </div>
        </div>
    </form>

    <!-- ── 2. CURRENCY / EXCHANGE RATE FORM ── -->
    <form method="POST">
        <input type="hidden" name="action" value="save_exchange">
        <div class="card">
            <div class="card-header">
                <div class="card-icon icon-amber"><i class="fa-solid fa-coins"></i></div>
                <div>
                    <div class="card-title"><?= $isKm ? 'អត្រាប្តូរប្រាក់ USD → KHR' : 'USD → KHR Exchange Rate' ?></div>
                    <div class="card-sub"><?= $isKm ? 'កំណត់អត្រាប្តូរប្រាក់ដែលត្រូវប្រើប្រាស់ក្នុងការគណនាតម្លៃ និងការទូទាត់ប្រាក់រៀល' : 'Set the official exchange rate used across orders, payments, and cashier change tender' ?></div>
                </div>
            </div>

            <div class="card-inner">
                <div class="field">
                    <label><i class="fa-solid fa-dollar-sign"></i> <?= $isKm ? 'អត្រាប្តូរប្រាក់ (រៀល ក្នុង ១ ដុល្លារ)' : 'Exchange Rate (KHR per 1 USD)' ?></label>
                    <input type="number" name="khr_exchange_rate" id="khrRate"
                           value="<?= $khr_rate ?>" min="100" max="99999" required>
                </div>

                <div class="preview-row" id="khrPreview" style="margin-top:16px;">
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-arrow-right-arrow-left text-amber-500"></i>
                        <span><?= $isKm ? 'ការគណនាបច្ចុប្បន្ន' : 'Live Currency Calculation' ?>:</span>
                    </div>
                    <span id="khrPreviewText" class="text-base tracking-wide">$1.00 USD = ៛<?= number_format($khr_rate) ?> <?= $isKm ? 'រៀល' : 'KHR' ?></span>
                </div>
            </div>

            <div class="form-actions">
                <div class="form-actions-info">
                    <i class="fa-solid fa-circle-info text-amber-400"></i>
                    <?= $isKm ? 'រក្សាទុកអត្រាប្តូរប្រាក់ ដុល្លារ/រៀល' : 'Saves USD to KHR Exchange Rate' ?>
                </div>
                <button type="submit" class="btn btn-save">
                    <i class="fa-solid fa-floppy-disk"></i> <?= $isKm ? 'រក្សាទុកអត្រាប្តូរប្រាក់' : 'Save Exchange Rate' ?>
                </button>
            </div>
        </div>
    </form>

    <!-- ── 3. EMAIL & SMTP CONFIGURATION FORM ── -->
    <form method="POST">
        <input type="hidden" name="action" value="save_smtp">
        <div class="card">
            <div class="card-header">
                <div class="card-icon icon-violet"><i class="fa-solid fa-envelope"></i></div>
                <div>
                    <div class="card-title"><?= $isKm ? 'ការកំណត់ប្រព័ន្ធអ៊ីមែល (Email / SMTP Settings)' : 'Email / SMTP System Settings' ?></div>
                    <div class="card-sub"><?= $isKm ? 'កំណត់រចនាសម្ព័ន្ធផ្ញើអ៊ីមែលសម្រាប់លេខសម្ងាត់បណ្តោះអាសន្ន និងការជូនដំណឹង' : 'Configure SMTP credentials for temporary passwords and system email alerts' ?></div>
                </div>
            </div>

            <div class="card-inner">
                <div class="field" style="margin-bottom: 20px;">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; text-transform: none; font-size: 14px; font-weight:700;">
                        <input type="checkbox" name="smtp_enabled" value="1" <?= $smtp_enabled ? 'checked' : '' ?> style="width: 19px; height: 19px; accent-color: var(--accent); cursor:pointer;">
                        <span style="color: var(--text);"><?= $isKm ? 'បើកដំណើរការ SMTP Email (Enable Custom SMTP Delivery)' : 'Enable Custom SMTP Delivery' ?></span>
                    </label>
                    <div class="field-hint"><?= $isKm ? 'ប្រសិនបើបិទ ប្រព័ន្ធនឹងប្រើប្រាស់ PHP Native mail() ដោយស្វ័យប្រវត្តិ' : 'If disabled, system defaults to standard PHP native mail()' ?></div>
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label><i class="fa-solid fa-server"></i> SMTP Host</label>
                        <input type="text" name="smtp_host" value="<?= htmlspecialchars($smtp_host) ?>" placeholder="e.g. smtp.gmail.com">
                    </div>

                    <div class="field">
                        <label><i class="fa-solid fa-hashtag"></i> SMTP Port</label>
                        <input type="number" name="smtp_port" value="<?= htmlspecialchars($smtp_port) ?>" placeholder="587 or 465">
                    </div>

                    <div class="field">
                        <label><i class="fa-solid fa-shield-halved"></i> Encryption</label>
                        <select name="smtp_secure">
                            <option value="tls" <?= $smtp_secure === 'tls' ? 'selected' : '' ?>>TLS (Port 587)</option>
                            <option value="ssl" <?= $smtp_secure === 'ssl' ? 'selected' : '' ?>>SSL (Port 465)</option>
                            <option value="none" <?= $smtp_secure === 'none' ? 'selected' : '' ?>>None</option>
                        </select>
                    </div>

                    <div class="field">
                        <label><i class="fa-solid fa-user"></i> SMTP Username / Email</label>
                        <input type="text" name="smtp_user" value="<?= htmlspecialchars($smtp_user) ?>" placeholder="your-email@gmail.com">
                    </div>

                    <div class="field">
                        <label><i class="fa-solid fa-key"></i> SMTP Password / App Password</label>
                        <input type="password" name="smtp_pass" value="<?= htmlspecialchars($smtp_pass) ?>" placeholder="Gmail 16-char App Password">
                    </div>

                    <div class="field">
                        <label><i class="fa-solid fa-signature"></i> Sender Name</label>
                        <input type="text" name="mail_from_name" value="<?= htmlspecialchars($mail_from_name) ?>" placeholder="Bird's Nest Coffee POS">
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <div class="form-actions-info">
                    <i class="fa-solid fa-circle-info text-violet-400"></i>
                    <?= $isKm ? 'រក្សាទុកព័ត៌មាន SMTP Email' : 'Saves SMTP Email Configuration' ?>
                </div>
                <button type="submit" class="btn btn-save">
                    <i class="fa-solid fa-floppy-disk"></i> <?= $isKm ? 'រក្សាទុកការកំណត់ Email' : 'Save Email Settings' ?>
                </button>
            </div>
        </div>
    </form>

    <!-- ── 4. TEST EMAIL FORM ── -->
    <form method="POST">
        <input type="hidden" name="action" value="test_smtp">
        <div class="card">
            <div class="card-header">
                <div class="card-icon"><i class="fa-solid fa-paper-plane"></i></div>
                <div>
                    <div class="card-title"><?= $isKm ? 'សាកល្បងផ្ញើអ៊ីមែល (Send Test Email)' : 'Send Test Email' ?></div>
                    <div class="card-sub"><?= $isKm ? 'ផ្ញើអ៊ីមែលគំរូដើម្បីពិនិត្យមើលថាតើប្រព័ន្ធផ្ញើអ៊ីមែលដំណើរការត្រឹមត្រូវឬទេ' : 'Send a sample test email to verify your email delivery configuration' ?></div>
                </div>
            </div>
            <div class="card-inner">
                <div style="display: flex; gap: 14px; align-items: flex-end; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 260px;">
                        <label style="display: block; font-size: 12px; font-weight: 700; color: var(--text); text-transform: uppercase; margin-bottom: 7px;">
                            <i class="fa-solid fa-at text-emerald-400 mr-1"></i> Test Email Address
                        </label>
                        <input type="email" name="test_email" placeholder="name@example.com" value="<?= htmlspecialchars($smtp_user ?: 'sokchamraeunid@gmail.com') ?>" required style="width:100%; padding:11px 15px; border-radius:12px; border:1px solid var(--input-border); background:var(--input-bg); color:var(--text); font-size:13.5px; outline:none;">
                    </div>
                    <button type="submit" class="btn btn-save" style="height: 44px; display: inline-flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-paper-plane"></i> <?= $isKm ? 'ផ្ញើអ៊ីមែលតេស្ត (Send Test)' : 'Send Test Email' ?>
                    </button>
                </div>
            </div>
        </div>
    </form>

</div>

<script>
// Live Receipt Preview Script
const inputShopName  = document.getElementById('rcptShopName');
const inputLocation  = document.getElementById('rcptLocation');
const inputPhone     = document.getElementById('rcptPhone');
const inputFooterMsg = document.getElementById('rcptFooterMsg');

const prevShopName  = document.getElementById('prevShopName');
const prevLocation  = document.getElementById('prevLocation');
const prevPhone     = document.getElementById('prevPhone');
const prevFooterMsg = document.getElementById('prevFooterMsg');

function updateReceiptPreview() {
    if (!prevShopName) return;
    const shopName = inputShopName.value.trim() || 'The Bird Nest Cafe';
    const loc      = inputLocation.value.trim() || 'Phnom Penh';
    const phone    = inputPhone.value.trim();
    const footer   = inputFooterMsg.value.trim() || 'Thank You!';

    prevShopName.textContent = shopName;
    prevLocation.textContent = loc;
    prevPhone.textContent    = phone;
    prevFooterMsg.textContent = footer;
}

[inputShopName, inputLocation, inputPhone, inputFooterMsg].forEach(input => {
    if (input) input.addEventListener('input', updateReceiptPreview);
});

// Live Exchange Rate Script
function updateKHRPreview() {
    const khrInput = document.getElementById('khrRate');
    if (!khrInput) return;
    const rate = parseInt(khrInput.value) || 4000;
    const suffix = <?= json_encode($isKm ? 'រៀល' : 'KHR') ?>;
    const prevEl = document.getElementById('khrPreviewText');
    if (prevEl) {
        prevEl.textContent = '$1.00 USD = ៛' + rate.toLocaleString() + ' ' + suffix;
    }
}
const khrRateEl = document.getElementById('khrRate');
if (khrRateEl) {
    khrRateEl.addEventListener('input', updateKHRPreview);
    updateKHRPreview();
}
</script>
</main>
</div>
</body>
</html>
