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
    }
}

// ── FLASH MESSAGE ──
if (isset($_GET['saved'])) {
    if ($_GET['saved'] === 'exchange') {
        $message = $isKm ? 'បានរក្សាទុកអត្រាប្តូរប្រាក់ដោយជោគជ័យ។' : 'Exchange rate saved successfully.';
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
?>
<!DOCTYPE html>
<html lang="<?= $isKm ? 'km' : 'en' ?>">
<head>
<meta charset="UTF-8">
<script>(function(){if(localStorage.getItem('theme')==='light')document.documentElement.setAttribute('data-theme','light');})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $isKm ? 'ការកំណត់ប្រព័ន្ធ & វិក្កយបត្រ — Bird\'s Nest Coffee' : 'Bird\'s Nest Coffee — System & Receipt Settings' ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:wght@400;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --bg:          #0f0f12;
  --panel:       #18181c;
  --panel-border:#24242b;
  --accent:      #d1904b;
  --accent-dark: #b87b38;
  --text:        #e4e4e7;
  --text-muted:  #a0a0ab;
  --success:     #22c55e;
  --danger:      #ef4444;
  --radius:      16px;
  --transition:  all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

/* ══ LIGHT THEME OVERRIDES FOR SETTINGS ══ */
[data-theme="light"], html[data-theme="light"] {
  --bg:           #f4efe9 !important;
  --panel:        #ffffff !important;
  --panel-border: #e0d4c4 !important;
  --text:         #1a1410 !important;
  --text-muted:   #5a4a3a !important;
}

[data-theme="light"] .app-main {
  background-color: #f4efe9 !important;
  color: #1a1410 !important;
}

[data-theme="light"] .hero {
  background: linear-gradient(135deg, rgba(209,144,75,0.12) 0%, rgba(255,255,255,0.9) 100%) !important;
  border-color: #e0d4c4 !important;
}

[data-theme="light"] .hero h1,
[data-theme="light"] .card-title {
  color: #1a1410 !important;
}

[data-theme="light"] .hero p,
[data-theme="light"] .card-sub,
[data-theme="light"] .field-hint,
[data-theme="light"] .form-actions-info {
  color: #5a4a3a !important;
}

[data-theme="light"] .card {
  background: #ffffff !important;
  border-color: #e0d4c4 !important;
  box-shadow: 0 4px 20px rgba(90,60,20,0.06) !important;
}

[data-theme="light"] .card-header,
[data-theme="light"] .form-actions {
  background: #fdfaf6 !important;
  border-color: #e0d4c4 !important;
}

[data-theme="light"] .field label {
  color: #1a1410 !important;
}

[data-theme="light"] .field input[type="text"],
[data-theme="light"] .field input[type="number"] {
  background: #ede8e0 !important;
  border-color: #e0d4c4 !important;
  color: #1a1410 !important;
}

[data-theme="light"] .receipt-preview-box {
  background: #ffffff !important;
  color: #000000 !important;
  border: 1px solid #e0d4c4 !important;
}

* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  background: var(--bg);
  color: var(--text);
  font-family: 'Kantumruy Pro', 'Poppins', sans-serif;
  font-size: 14px;
}

.wrapper {
  max-width: 860px;
  margin: 0 auto;
}

/* ── HERO ── */
.hero {
  background: linear-gradient(135deg, rgba(209,144,75,0.12) 0%, rgba(24,24,28,0.8) 100%);
  border: 1px solid var(--panel-border);
  border-radius: var(--radius);
  padding: 32px 28px;
  margin-bottom: 24px;
  position: relative;
  overflow: hidden;
}
.hero::before {
  content: '';
  position: absolute;
  top: -40px; right: -40px;
  width: 180px; height: 180px;
  background: radial-gradient(circle, rgba(209,144,75,0.2) 0%, transparent 70%);
  pointer-events: none;
}
.hero-icon {
  width: 48px; height: 48px;
  border-radius: 12px;
  background: rgba(209,144,75,0.15);
  border: 1px solid rgba(209,144,75,0.3);
  color: var(--accent);
  display: flex; align-items: center; justify-content: center;
  font-size: 22px;
  margin-bottom: 14px;
}
.hero h1 { font-size: 24px; font-weight: 700; color: #fff; margin-bottom: 6px; }
.hero p  { font-size: 13px; color: var(--text-muted); }

/* ── CARD ── */
.card {
  background: var(--panel);
  border: 1px solid var(--panel-border);
  border-radius: var(--radius);
  margin-bottom: 24px;
  overflow: hidden;
  box-shadow: 0 4px 20px rgba(0,0,0,0.3);
}
.card-header {
  padding: 20px 24px;
  border-bottom: 1px solid var(--panel-border);
  display: flex; align-items: center; gap: 14px;
}
.card-icon {
  width: 38px; height: 38px;
  border-radius: 10px;
  background: rgba(209,144,75,0.1);
  border: 1px solid rgba(209,144,75,0.2);
  color: var(--accent);
  display: flex; align-items: center; justify-content: center;
  font-size: 17px; flex-shrink: 0;
}
.card-title { font-size: 16px; font-weight: 600; color: #fff; }
.card-sub   { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.card-inner { padding: 24px; }

/* ── FORM FIELDS ── */
.form-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 20px;
}
@media (max-width: 640px) {
  .form-grid { grid-template-columns: 1fr; }
}

.field label {
  display: block; font-size: 12px; font-weight: 600;
  color: var(--text); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;
}
.field label i { color: var(--accent); margin-right: 4px; }
.field input[type="text"],
.field input[type="number"] {
  width: 100%; padding: 12px 16px;
  background: rgba(255,255,255,0.04);
  border: 1px solid var(--panel-border);
  border-radius: 10px; color: #fff;
  font-family: inherit; font-size: 14px; font-weight: 500;
  outline: none; transition: var(--transition);
}
.field input[type="text"]:focus,
.field input[type="number"]:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(209,144,75,0.15);
  background: rgba(255,255,255,0.07);
}
.field-hint { display: block; font-size: 12px; color: var(--text-muted); margin-top: 6px; }

.preview-row {
  display: flex; align-items: center; gap: 10px;
  background: rgba(209,144,75,0.08);
  border: 1px dashed rgba(209,144,75,0.3);
  border-radius: 10px; padding: 14px 18px;
  color: var(--accent); font-size: 14px; font-weight: 600;
}

/* Thermal receipt preview box */
.receipt-preview-box {
  background: #ffffff;
  color: #000000;
  border-radius: 8px;
  padding: 16px;
  width: 100%;
  max-width: 320px;
  margin: 20px auto 0;
  box-shadow: 0 10px 25px rgba(0,0,0,0.5);
  font-family: 'Kantumruy Pro', 'Poppins', sans-serif;
  font-size: 11px;
}

/* ── FORM ACTIONS ── */
.form-actions {
  padding: 16px 24px;
  background: rgba(0,0,0,0.2);
  border-top: 1px solid var(--panel-border);
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
}
.form-actions-info { font-size: 12px; color: var(--text-muted); display: flex; align-items: center; gap: 6px; }
.btn {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 10px 24px; border-radius: 10px;
  font-family: inherit; font-size: 13.5px; font-weight: 600;
  cursor: pointer; border: none; transition: var(--transition); text-decoration: none;
}
.btn-save {
  background: linear-gradient(135deg, var(--accent) 0%, var(--accent-dark) 100%);
  color: #000; box-shadow: 0 4px 12px rgba(209,144,75,0.3);
}
.btn-save:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(209,144,75,0.4); }

.msg {
  padding: 14px 18px; border-radius: 12px; font-size: 13.5px;
  display: flex; align-items: center; gap: 10px; margin-bottom: 20px;
}
.msg.success { background: rgba(34,197,94,0.12); border: 1px solid rgba(34,197,94,0.3); color: #4ade80; }
.msg.error   { background: rgba(239,68,68,0.12);  border: 1px solid rgba(239,68,68,0.3);  color: #f87171; }

/* ── Sidebar Toggle & Breadcrumb ── */
.st-sidebar-toggle {
    display: none;
    width: 36px;
    height: 36px;
    min-width: 36px;
    border-radius: 8px;
    background: var(--panel, #18181c);
    border: 1px solid var(--panel-border, #24242b);
    color: var(--text, #fff);
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: var(--transition);
    padding: 0;
}
.st-sidebar-toggle:hover {
    background: rgba(209, 144, 75, 0.15);
    border-color: var(--accent, #d1904b);
    color: var(--accent, #d1904b);
}
[data-theme="light"] .st-sidebar-toggle {
    background: #ede8e0 !important;
    border-color: #e0d4c4 !important;
    color: #1a1410 !important;
}

@media (max-width: 768px) {
    .st-sidebar-toggle {
        display: inline-flex !important;
    }
    .app-main {
        padding: 12px 14px !important;
    }
    .hero {
        padding: 18px 16px !important;
        margin-bottom: 14px !important;
        border-radius: 12px !important;
    }
    .hero-icon {
        width: 38px !important;
        height: 38px !important;
        font-size: 18px !important;
        margin-bottom: 10px !important;
        border-radius: 10px !important;
    }
    .hero h1 {
        font-size: 18px !important;
        margin-bottom: 4px !important;
    }
    .hero p {
        font-size: 12px !important;
        line-height: 1.4 !important;
    }
    .card {
        margin-bottom: 14px !important;
        border-radius: 12px !important;
    }
    .card-header {
        padding: 12px 14px !important;
        gap: 10px !important;
    }
    .card-icon {
        width: 32px !important;
        height: 32px !important;
        font-size: 14px !important;
        border-radius: 8px !important;
    }
    .card-title {
        font-size: 13.5px !important;
    }
    .card-sub {
        font-size: 11px !important;
    }
    .card-inner {
        padding: 14px 14px !important;
    }
    .form-grid {
        grid-template-columns: 1fr !important;
        gap: 12px !important;
    }
    .field label {
        font-size: 11px !important;
        margin-bottom: 4px !important;
    }
    .field input[type="text"],
    .field input[type="number"] {
        padding: 9px 12px !important;
        font-size: 13px !important;
        border-radius: 8px !important;
    }
    .field-hint {
        font-size: 10.5px !important;
        margin-top: 4px !important;
    }
    .receipt-preview-box {
        max-width: 100% !important;
        padding: 14px 12px !important;
        margin-top: 14px !important;
    }
    .form-actions {
        padding: 12px 14px !important;
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 8px !important;
    }
    .form-actions-info {
        font-size: 11px !important;
        justify-content: center !important;
        text-align: center !important;
    }
    .btn-save {
        width: 100% !important;
        justify-content: center !important;
        height: 38px !important;
        font-size: 13px !important;
        border-radius: 8px !important;
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

    <div class="hero">
        <div class="hero-icon"><i class="fa-solid fa-sliders"></i></div>
        <h1><?= $isKm ? 'ការកំណត់ប្រព័ន្ធ & វិក្កយបត្រ' : 'System & Receipt Settings' ?></h1>
        <p><?= $isKm ? 'គ្រប់គ្រងព័ត៌មានវិក្កយបត្រ (ឈ្មោះហាង, ទីតាំង, លេខទូរស័ព្ទ, ចំណាំខាងក្រោម) និងអត្រាប្តូរប្រាក់ ដុល្លារ/រៀល' : 'Control thermal receipt details (Shop Name, Location, Phone, Footer) and USD to KHR Exchange Rate.' ?></p>
    </div>

    <?php if ($message): ?>
    <div class="msg <?= htmlspecialchars($message_type) ?>">
        <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
        <strong><?= $isKm ? 'ការកំណត់៖' : 'Settings:' ?></strong>&nbsp;<?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>

    <!-- ── 1. RECEIPT CONTROL FORM ── -->
    <form method="POST">
        <input type="hidden" name="action" value="save_receipt">
        <div class="card">
            <div class="card-header">
                <div class="card-icon"><i class="fa-solid fa-receipt"></i></div>
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
                <div class="mt-6 pt-4 border-t border-[#24242b]">
                    <div class="text-xs font-semibold text-[#a0a0ab] uppercase tracking-wider text-center mb-3">
                        <i class="fa-solid fa-eye text-[#d1904b]"></i> <?= $isKm ? 'ទិដ្ឋភាពជាក់ស្តែងនៃក្បាល & បាតវិក្កយបត្រ' : 'Live Receipt Header & Footer Preview' ?>
                    </div>
                    <div class="receipt-preview-box">
                        <div style="text-align: center; margin-bottom: 8px;">
                            <div id="prevShopName" style="font-size: 16px; font-weight: 700; line-height: 1.2;">
                                <?= htmlspecialchars($receipt_shop_name) ?>
                            </div>
                            <div id="prevLocation" style="font-size: 11px; margin-top: 2px; color: #333;">
                                <?= htmlspecialchars($receipt_location) ?>
                            </div>
                            <div id="prevPhone" style="font-size: 11px; margin-top: 1px; color: #333;">
                                <?= htmlspecialchars($receipt_phone) ?>
                            </div>
                            <div style="font-size: 14px; font-weight: 700; margin-top: 8px;">វិក្កយបត្រ / RECEIPT</div>
                        </div>

                        <div style="border-top: 1px dashed #666; border-bottom: 1px dashed #666; padding: 6px 0; margin: 8px 0; text-align: center; font-size: 10px; color: #555;">
                            <?= $isKm ? '[ បញ្ជីទំនិញគំរូ ]' : '[ SAMPLE ORDER ITEMS LIST ]' ?>
                        </div>

                        <div style="border-top: 1px dotted #000; margin-top: 10px; padding-top: 6px; text-align: center;">
                            <div id="prevFooterMsg" style="font-weight: 700; font-size: 12px; text-transform: uppercase;">
                                <?= htmlspecialchars($receipt_footer_msg) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <div class="form-actions-info">
                    <i class="fa-solid fa-circle-info"></i>
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
                <div class="card-icon"><i class="fa-solid fa-coins"></i></div>
                <div>
                    <div class="card-title"><?= $isKm ? 'អត្រាប្តូរប្រាក់ USD → KHR' : 'USD → KHR Exchange Rate' ?></div>
                </div>
            </div>

            <div class="card-inner">
                <div class="field">
                    <label><i class="fa-solid fa-dollar-sign"></i> <?= $isKm ? 'អត្រាប្តូរប្រាក់ (រៀល ក្នុង ១ ដុល្លារ)' : 'Exchange Rate (KHR per 1 USD)' ?></label>
                    <input type="number" name="khr_exchange_rate" id="khrRate"
                           value="<?= $khr_rate ?>" min="100" max="99999" required>
                </div>

                <div class="preview-row" id="khrPreview" style="margin-top:20px;">
                    <i class="fa-solid fa-tag"></i>
                    <span id="khrPreviewText">$1.00 USD = ៛<?= number_format($khr_rate) ?> <?= $isKm ? 'រៀល' : 'KHR' ?></span>
                </div>
            </div>

            <div class="form-actions">
                <div class="form-actions-info">
                    <i class="fa-solid fa-circle-info"></i>
                    <?= $isKm ? 'រក្សាទុកអត្រាប្តូរប្រាក់ ដុល្លារ/រៀល' : 'Saves USD to KHR Exchange Rate' ?>
                </div>
                <button type="submit" class="btn btn-save">
                    <i class="fa-solid fa-floppy-disk"></i> <?= $isKm ? 'រក្សាទុកអត្រាប្តូរប្រាក់' : 'Save Exchange Rate' ?>
                </button>
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
    const rate = parseInt(document.getElementById('khrRate').value) || 4000;
    const suffix = <?= json_encode($isKm ? 'រៀល' : 'KHR') ?>;
    document.getElementById('khrPreviewText').textContent =
        '$1.00 USD = ៛' + rate.toLocaleString() + ' ' + suffix;
}
document.getElementById('khrRate').addEventListener('input', updateKHRPreview);
updateKHRPreview();
</script>
</main>
</div>
</body>
</html>
