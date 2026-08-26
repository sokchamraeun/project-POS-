<?php
/**
 * 
 * test
 * Tender screen for a cash settlement taken at the counter.
 *
 * Included by admin_pay_cash.php on a GET. Renders and writes nothing — the
 * order is only settled by the POST this form submits.
 *
 * Expects in scope: $order, $order_id, $return_tab, $return_page.
 * Optional: $tender_fragment — true to omit the document shell and scope the CSS,
 * for injection into the find_order.php modal.
 *
 * The amount received IS submitted, as cash_received, and stored in
 * order_payments.reference so the receipt can print Received / Change. It never
 * changes what is settled: the order is paid in full whatever is typed, so a
 * short tender can never look like a partial payment.
 */
if (!isset($order, $order_id, $return_tab, $return_page)) { http_response_code(500); exit('Tender screen loaded out of context.'); }
require_once __DIR__ . '/lang.php';

// he() is defined per-page across this codebase rather than in config.php, so
// this partial cannot assume its includer declared one.
if (!function_exists('he')) {
    function he($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$owed      = (float)$order['total'];
$khr_owed  = (int)(round($owed * KHR_RATE / 100) * 100);
$is_pl     = ($order['payment_method'] ?? '') === 'paylater';
$cust      = trim($order['customer_name'] ?? '') !== '' ? $order['customer_name'] : 'Guest';
// Fragment mode omits the document shell so the panel can be injected into the
// find_order.php modal. The <style> block is emitted either way — the modal needs
// it, and a repeated <style> is harmless since every rule is class-scoped.
$tender_fragment = !empty($tender_fragment);
?>
<?php if (!$tender_fragment): ?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>" data-lang="<?= current_lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script>(function(){if(localStorage.getItem('theme')==='dark')document.documentElement.setAttribute('data-theme','dark');})()</script>
<title><?= __('cpm_modal_title', 'Cash Payment') ?> | Bird's Nest Coffee</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Noto+Sans+Khmer:wght@300;400;500;600;700;800&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="tender.js?v=<?= @filemtime('tender.js') ?>"></script>
<?php endif; ?>
<?php
/* Every rule is scoped to .tender-card in fragment mode. These styles get injected
   straight into find_order.php, and :root / body / * are page-level selectors —
   unscoped they would reset that page's margins, recolour its variables and turn
   its body into a centred flex column. The panel must not be able to restyle its
   host. */
$S = $tender_fragment ? '.tender-card ' : '';
/* Component-level dark rules. Standalone they hang off [data-theme="dark"]; in the
   modal dark is the DEFAULT so they apply unconditionally and are undone by the
   light block below. */
$DARK = $tender_fragment ? '.tender-card' : '[data-theme="dark"]';
?>
<style>
<?php if ($tender_fragment): ?>
/* The host page (find_order.php) follows the app convention: dark is the default
   and [data-theme="light"] overrides it. This partial standalone does the opposite
   — light base, [data-theme="dark"] override — so the fragment MUST invert. On a
   light host, [data-theme="dark"] .tender-card won't match, so .tender-card's dark
   rules are dormant and the light styles apply. On a dark host, [data-theme="dark"]
   .tender-card matches and applies the dark block. */
.tender-card {
    --bg-body:   #f0ebe4;
    --bg-card:   #fffdf9;
    --border:    #e4d9cc;
    --border-h:  #c9b89f;
    --orange:    #d1904b;
    --orange-d:  #a0702a;
    --green:     #2ecc71;
    --text-1:    #1a1410;
    --text-2:    #5a4a3a;
    --text-3:    #9a8070;
    --shadow-md: 0 8px 32px rgba(90,60,20,0.13);
}
[data-theme="dark"] .tender-card {
    --bg-body: #0a0a0a;
    --bg-card: #141414;
    --border: #222;
    --text-1: #f0f0f0;
    --text-2: #aaa;
    --text-3: #666;
    --shadow-md: 0 8px 32px rgba(0,0,0,0.6);
}
<?php else: ?>
:root {
    --bg-body:   #f0ebe4;
    --bg-card:   #fffdf9;
    --border:    #e4d9cc;
    --border-h:  #c9b89f;
    --orange:    #d1904b;
    --orange-d:  #a0702a;
    --green:     #2ecc71;
    --text-1:    #1a1410;
    --text-2:    #5a4a3a;
    --text-3:    #9a8070;
    --shadow-md: 0 8px 32px rgba(90,60,20,0.13);
}
[data-theme="dark"] {
    --bg-body: #0a0a0a; --bg-card: #141414;
    --border: #222; --text-1: #f0f0f0; --text-2: #aaa; --text-3: #666;
    --shadow-md: 0 8px 32px rgba(0,0,0,0.6);
}
<?php endif; ?>
<?php if ($tender_fragment): ?>
.tender-card, .tender-card * { box-sizing: border-box; }
.tender-card { font-family: 'Poppins', 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Khmer OS Battambang', sans-serif; color: var(--text-1); display: flex; justify-content: center; }
:lang(km) .tender-card, [data-lang="km"] .tender-card, html[lang="km"] .tender-card * {
    font-family: 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Khmer OS Battambang', 'Khmer OS Siemreap', 'Poppins', sans-serif !important;
}
<?php else: ?>
* { box-sizing: border-box; margin: 0; padding: 0; }
body, input, select, textarea, button, table {
    font-family: 'Poppins', 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Khmer OS Battambang', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}
:lang(km), [data-lang="km"], html[lang="km"], html[lang="km"] * {
    font-family: 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Khmer OS Battambang', 'Khmer OS Siemreap', 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
}
html[lang="km"] .fa, html[lang="km"] [class*="fa-"], html[lang="km"] i {
    font-family: 'Font Awesome 6 Free', 'FontAwesome' !important;
}
html[lang="km"] .fa-brands, html[lang="km"] [class*="fa-brands"] {
    font-family: 'Font Awesome 6 Brands', 'FontAwesome' !important;
}
body {
    background: var(--bg-body);
    min-height: 100vh; display: flex; align-items: flex-start; justify-content: center;
    padding: 24px 16px 40px; color: var(--text-1);
}
<?php endif; ?>
<?= $S ?>.card {
    background: var(--bg-card); border-radius: 18px; width: 100%; max-width: 420px;
    border: 1px solid var(--border); box-shadow: var(--shadow-md); overflow: hidden;
}
<?= $S ?>.card-accent { height: 4px; background: linear-gradient(90deg, var(--green), var(--orange)); }
<?= $S ?>.card-body { padding: 24px 24px 20px; }

<?= $S ?>.head { text-align: center; padding-bottom: 16px; border-bottom: 1px dashed var(--border); margin-bottom: 18px; }
<?= $S ?>.head h1 { font-size: 18px; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 8px; }
<?= $S ?>.head .sub { font-size: 12px; color: var(--text-3); margin-top: 5px; }
<?= $S ?>.pl-pill {
    display: inline-block; margin-top: 8px; padding: 3px 10px; border-radius: 999px;
    background: rgba(52,152,219,.14); color: #3498db; font-size: 11px; font-weight: 700;
}

<?= $S ?>.amount-due { text-align: center; margin-bottom: 16px; }
<?= $S ?>.amount-due .lbl { font-size: 11px; letter-spacing: .07em; text-transform: uppercase; color: var(--text-3); font-weight: 600; }
<?= $S ?>.amount-due .amt { font-size: 38px; font-weight: 800; color: var(--orange); line-height: 1.15; }
<?= $S ?>.amount-due .khr { font-size: 13px; color: var(--text-3); font-weight: 600; }

/* Tender block — same classes and behaviour as the menu.php checkout, so a
   cashier who has used checkout already knows this screen. */
<?= $S ?>.cp-change-calc { margin-top: 6px; padding: 12px; background: rgba(85,224,135,.05); border-radius: 9px; border: 1px solid rgba(85,224,135,.2); }
<?= $S ?>.cp-change-calc label { font-size: 11px; color: var(--text-2); font-weight: 600; display: block; margin-bottom: 4px; }
<?= $S ?>.cp-change-calc input { width: 100%; padding: 8px 10px; border-radius: 6px; border: 1px solid rgba(85,224,135,.35); background: var(--bg-card); color: var(--text-1); font-size: 16px; font-weight: 700; font-family: 'Poppins',sans-serif; outline: none; text-align: right; }
<?= $S ?>.cp-change-calc input:focus { border-color: #55e087; }
<?= $DARK ?> .cp-change-calc { background: rgba(85,224,135,.03); }
<?= $DARK ?> .cp-change-calc input { background: #1a1a1a; color: #f0f0f0; border-color: #252525; color-scheme: dark; }

<?= $S ?>.cp-tender-quick { display: flex; gap: 5px; flex-wrap: wrap; margin-top: 7px; }
<?= $S ?>.cp-tender-btn { flex: 1 1 auto; min-width: 48px; padding: 8px; border-radius: 7px; border: 1.5px solid rgba(85,224,135,.3); background: var(--bg-body); color: var(--text-2); font-family: 'Poppins',sans-serif; font-size: 12px; font-weight: 700; cursor: pointer; transition: all .15s; }
<?= $S ?>.cp-tender-btn:hover { border-color: #55e087; color: #2e9c5a; }
<?= $S ?>.cp-tender-btn.active { background: rgba(85,224,135,.16); border-color: #55e087; color: #2e9c5a; box-shadow: 0 0 0 2px rgba(85,224,135,.15); }
<?= $DARK ?> .cp-tender-btn { background: #1a1a1a; border-color: #2d2d2d; color: #aaa; }
<?= $DARK ?> .cp-tender-btn.active { background: rgba(85,224,135,.14); border-color: #55e087; color: #55e087; }

/* 3D Return Change Mode Selector */
<?= $S ?>#cpTenderModeWrap {
    background: #ede4d8; border: 1px solid #d6c6b4; border-radius: 10px;
    box-shadow: inset 0 2px 4px rgba(0,0,0,0.08), 0 1px 0 rgba(255,255,255,0.8);
    padding: 3px; display: inline-flex; gap: 4px;
}
<?= $S ?>#cpTenderModeWrap .cp-tender-btn {
    border-radius: 7px; padding: 5px 9px; min-width: auto;
    background: linear-gradient(180deg, #ffffff 0%, #f4ede3 100%);
    border: 1px solid #dcd1c2; border-top: 1px solid #ffffff;
    color: #635343; font-weight: 700; box-shadow: 0 2px 0 #c8bba8;
    transition: all 0.18s cubic-bezier(0.34, 1.56, 0.64, 1);
}
<?= $S ?>#cpTenderModeWrap .cp-tender-btn:hover {
    transform: translateY(-1.5px); color: #1a1410; box-shadow: 0 3px 0 #c8bba8;
}
<?= $S ?>#cpTenderModeWrap .cp-tender-btn.active {
    background: linear-gradient(180deg, #f5b974 0%, #d1904b 50%, #ad6d28 100%) !important;
    color: #140c02 !important; border: 1px solid #f8cd99 !important; border-top: 1px solid #ffe3c0 !important;
    box-shadow: 0 3px 0 #8b521b, 0 4px 10px rgba(209,144,75,0.35) !important;
    transform: translateY(-1px);
}
<?= $DARK ?> #cpTenderModeWrap {
    background: #101015; border-color: #282838;
    box-shadow: inset 0 2px 5px rgba(0,0,0,0.6), 0 1px 0 rgba(255,255,255,0.05);
}
<?= $DARK ?> #cpTenderModeWrap .cp-tender-btn {
    background: linear-gradient(180deg, #22222d 0%, #15151c 100%);
    border: 1px solid #2f2f42; border-top: 1px solid #43435c;
    color: #9292a4; box-shadow: 0 2px 0 #09090d;
}
<?= $DARK ?> #cpTenderModeWrap .cp-tender-btn:hover {
    color: #fff; background: linear-gradient(180deg, #2c2c3b 0%, #1c1c26 100%);
    box-shadow: 0 3px 0 #09090d;
}
<?= $DARK ?> #cpTenderModeWrap .cp-tender-btn.active {
    background: linear-gradient(180deg, #f5b974 0%, #d1904b 52%, #aa6824 100%) !important;
    color: #120b02 !important; border-color: #f8cc96 !important;
    box-shadow: 0 3px 0 #68380d, 0 6px 14px rgba(209,144,75,0.4) !important;
}

<?= $S ?>.cp-change-row { display: flex; justify-content: space-between; align-items: center; margin-top: 9px; padding-top: 7px; border-top: 1px solid rgba(85,224,135,.15); }
<?= $S ?>.cp-change-row .change-label { font-size: 11px; font-weight: 600; color: var(--text-2); }
<?= $S ?>.cp-change-row .change-amount { font-size: 19px; font-weight: 800; color: #55e087; }
<?= $S ?>.cp-change-row .change-amount.not-enough { color: #e74c3c; font-size: 13px; }

<?= $S ?>.btn-confirm {
    width: 100%; margin-top: 18px; padding: 14px; border: none; border-radius: 12px;
    background: var(--green); color: #04210f; font-family: 'Poppins','Kantumruy Pro',sans-serif;
    font-size: 15px; font-weight: 700; cursor: pointer; transition: all .18s;
    display: flex; align-items: center; justify-content: center; gap: 8px;
}
<?= $S ?>.btn-confirm:hover { filter: brightness(1.07); transform: translateY(-1px); }
<?= $S ?>.btn-cancel {
    display: block; text-align: center; margin-top: 10px; padding: 10px;
    color: var(--text-3); text-decoration: none; font-size: 13px; font-weight: 600;
    border-radius: 10px; border: 1px solid var(--border);
}
<?= $S ?>.btn-cancel:hover { color: var(--text-1); }
<?php if ($tender_fragment): ?>
/* The component rules above hardcode dark values, which is correct by default in
   the modal. Restore the light versions when the host page is in light mode. */
[data-theme="light"] .tender-card .cp-change-calc { background: rgba(85,224,135,.05); }
[data-theme="light"] .tender-card .cp-change-calc input { background: var(--bg-card); color: var(--text-1); border-color: rgba(85,224,135,.35); color-scheme: light; }
[data-theme="light"] .tender-card .cp-tender-btn { background: var(--bg-body); border-color: rgba(85,224,135,.3); color: var(--text-2); }
[data-theme="light"] .tender-card .cp-tender-btn.active { background: rgba(85,224,135,.16); border-color: #55e087; color: #2e9c5a; }
<?php endif; ?>
</style>
<?php if (!$tender_fragment): ?>
</head>
<body>
<?php endif; ?>

<div class="tender-card">
<div class="card">
    <div class="card-accent"></div>
    <div class="card-body">

        <div class="head">
            <h1><i class="fa-solid fa-money-bill-wave" style="color:var(--green)"></i> <?= __('cpm_modal_title', 'Cash Payment') ?></h1>
            <div class="sub">Order #<?= (int)$order['daily_order_no'] ?> &middot; <?= he($cust) ?></div>
            <?php if ($is_pl): ?><div class="pl-pill"><i class="fa-solid fa-clock"></i> <?= __('settling_pay_later_tab', 'Settling a pay-later tab') ?></div><?php endif; ?>
        </div>

        <div class="amount-due">
            <div class="lbl"><?= __('cpm_total_due', 'Total due') ?></div>
            <div class="amt">$<?= number_format($owed, 2) ?></div>
            <div class="khr">&#6107;<?= number_format($khr_owed) ?></div>
        </div>

        <form method="POST" action="admin_pay_cash.php?order_id=<?= (int)$order_id ?>" id="tenderForm">
            <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="return" value="<?= he($return_tab) ?>">

            <div class="cp-change-calc">
                <label><i class="fa-solid fa-money-bill-wave" style="color:#55e087;margin-right:4px;"></i> <?= __('cpm_amount_received', 'Amount Received') ?></label>

                <div style="display:flex;align-items:center;gap:6px;">
                    <span style="font-size:12px;font-weight:700;color:var(--text-2);width:16px;">$</span>
                    <input type="number" id="cpCashReceived" name="cash_received"
                           step="0.01" min="0" placeholder="0.00"
                           oninput="this.dataset.touched='1'; cpCalcChange(); cpMarkActiveTender(this.value)"
                           onfocus="this.select()">
                </div>
                <div class="cp-tender-quick" id="cpTenderQuick" style="display:none;"></div>

                <div style="display:flex;align-items:center;gap:6px;margin-top:9px;">
                    <span style="font-size:12px;font-weight:700;color:var(--text-2);width:16px;">&#6107;</span>
                    <input type="number" id="cpRielCash" name="cash_received_khr"
                           step="100" min="0" placeholder="0"
                           oninput="cpOnRielInput(); cpCalcChange()" onfocus="this.select()">
                    <span id="cpRielCashUsd" style="font-size:11px;color:var(--text-3);white-space:nowrap;">&asymp; $0.00</span>
                </div>
                <div class="cp-tender-quick" id="cpRielQuick" style="display:none;"></div>

                <div class="cp-change-mode-row" style="display:flex;align-items:center;justify-content:space-between;margin-top:10px;padding-top:8px;border-top:1px solid rgba(85,224,135,.15);">
                    <span style="font-size:11px;font-weight:600;color:var(--text-2);"><?= __('cpm_return_change_in', 'Return change in:') ?></span>
                    <div style="display:inline-flex;gap:4px;background:rgba(0,0,0,0.15);padding:2px;border-radius:8px;border:1px solid rgba(85,224,135,.2);" id="cpTenderModeWrap">
                        <button type="button" class="cp-tender-btn active" id="cpTenderModeMixed" onclick="cpSetTenderChangeMode('mixed')" style="padding:4px 10px;font-size:11px;min-width:auto;"><?= __('cpm_mode_mixed', 'Dollar + Riel') ?></button>
                        <button type="button" class="cp-tender-btn" id="cpTenderModeKhr" onclick="cpSetTenderChangeMode('khr')" style="padding:4px 10px;font-size:11px;min-width:auto;"><?= __('cpm_mode_khr', 'Riel') ?></button>
                    </div>
                </div>

                <div class="cp-change-row">
                    <span class="change-label"><?= __('cpm_change_to_return', 'Change to give back') ?></span>
                    <span class="change-amount" id="cpChangeAmount">$0.00</span>
                </div>
                <div id="cpShortWarn" style="display:none;margin-top:6px;font-size:11px;color:#e0a955;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    This is less than the total. The order will still be settled in full.
                </div>
            </div>

            <button type="submit" class="btn-confirm">
                <i class="fa-solid fa-check"></i> <?= __('cpm_apply_payment', 'Confirm Cash Payment') ?>
            </button>
        </form>
        <?php if ($tender_fragment): ?>
        <a href="#" class="btn-cancel" onclick="closeTenderModal(); return false;"><?= __('cpm_cancel', 'Cancel') ?></a>
        <?php else: ?>
        <a href="<?= he($return_page) ?>" class="btn-cancel"><?= __('cpm_cancel', 'Cancel') ?></a>
        <?php endif; ?>

    </div>
</div>
</div>

<script>
const CP_OWED     = <?= json_encode(round($owed, 2)) ?>;
// tender.js is static and never parsed by PHP, so the rate is passed in.
var CP_KHR_RATE = window.CP_KHR_RATE || <?= defined('KHR_RATE') ? (int)KHR_RATE : 4100 ?>;
var cpTenderChangeMode = localStorage.getItem('cpm_change_mode') || 'mixed';

function cpOwedInCash() { return CP_OWED; }

function cpSetTenderChangeMode(mode) {
  if (mode !== 'khr') mode = 'mixed';
  cpTenderChangeMode = mode;
  try { localStorage.setItem('cpm_change_mode', mode); } catch(e) {}
  var bMixed = document.getElementById('cpTenderModeMixed');
  var bKhr   = document.getElementById('cpTenderModeKhr');
  if (bMixed) bMixed.classList.toggle('active', mode === 'mixed');
  if (bKhr)   bKhr.classList.toggle('active', mode === 'khr');
  cpCalcChange();
}

/* Arithmetic, the prefill-clear rule and the change wording all live in
   tender.js, so this screen and the checkout modal cannot drift apart. This
   file owns only the calls and this screen's own owed amount. */
function cpCashReceivedUsd() {
  return tenderCashReceivedUsd('cpCashReceived', 'cpRielCash', CP_KHR_RATE);
}

/* Keyed on the COMBINED total, not on dollars. With separate fields, zero
   dollars is the normal riel-only case, and a dollars-only test would leave the
   change line at $0.00 while the cashier holds 5,500 riel. */
function cpCalcChange() {
  var el = document.getElementById('cpChangeAmount');
  if (!el) return;
  var received = cpCashReceivedUsd();
  var owed     = cpOwedInCash();
  var changeUsd = received - owed;
  var isShort   = (changeUsd < -0.005);

  var warn = document.getElementById('cpShortWarn');
  if (warn) warn.style.display = (received > 0 && isShort) ? 'block' : 'none';

  if (received === 0) { el.textContent = '$0.00'; el.className = 'change-amount'; return; }
  if (isShort) {
    el.className = 'change-amount not-enough';
    el.textContent = 'Need $' + Math.abs(changeUsd).toFixed(2) + ' more';
    return;
  }

  el.className = 'change-amount';
  if (typeof tenderChangeFormatted === 'function') {
    var fmt = tenderChangeFormatted(changeUsd, CP_KHR_RATE, cpTenderChangeMode);
    el.textContent = fmt.main;
  } else {
    var ch = tenderChange(received, owed, CP_KHR_RATE,
                          tenderFieldsRielOnly('cpCashReceived', 'cpRielCash'));
    el.textContent = tenderChangeText(ch, received, owed);
  }
}

function cpOnRielInput() {
  tenderOnRielInput('cpCashReceived', 'cpRielCash', 'cpRielCashUsd', CP_KHR_RATE);
}

/* One tap for the note the customer actually handed over. The prefilled exact
   amount on its own was a trap: it LOOKS handled, so a rushed cashier can leave
   "Change $0.00" showing while holding a $20 note. */
function cpRenderTenderQuick() {
  var wrap = document.getElementById('cpTenderQuick');
  if (!wrap) return;
  wrap.innerHTML = '';
  var owed = cpOwedInCash();
  if (owed <= 0) return;

  var mk = function (label, val) {
    var b = document.createElement('button');
    b.type = 'button';            // a bare <button> in a form submits — that would settle the order
    b.className = 'cp-tender-btn';
    b.textContent = label;
    b.dataset.tender = val.toFixed(2);
    b.addEventListener('click', function () { cpSetTender(val); });
    return b;
  };

  wrap.appendChild(mk('Exact', owed));
  [1, 5, 10, 20, 50, 100].filter(function (d) { return d > owed; })
                         .slice(0, 4)
                         .forEach(function (d) { wrap.appendChild(mk('$' + d, d)); });

  cpMarkActiveTender(parseFloat(document.getElementById('cpCashReceived').value) || 0);
}

function cpRenderRielQuick() {
  tenderRenderRielQuick('cpRielQuick', 'cpRielCash', cpOwedInCash(), CP_KHR_RATE,
    function () { cpOnRielInput(); cpCalcChange(); });
}

function cpSetTender(val) {
  var cr = document.getElementById('cpCashReceived');
  if (!cr) return;
  cr.value = Number(val).toFixed(2);
  cr.dataset.touched = '1';   // an explicit choice — never re-seed over it
  cpCalcChange();
  cpMarkActiveTender(val);
}

function cpMarkActiveTender(val) {
  var v = Number(val).toFixed(2);
  document.querySelectorAll('#cpTenderQuick .cp-tender-btn').forEach(function (b) {
    b.classList.toggle('active', b.dataset.tender === v);
  });
}

/* Seed with what is owed so exact cash stays one tap; a customer handing over a
   note just overtypes it. Only ever prefills an untouched field. */
(function () {
  var cr = document.getElementById('cpCashReceived');
  if (cr && cr.dataset.touched !== '1' && CP_OWED > 0) { cr.value = CP_OWED.toFixed(2); }
  cpSetTenderChangeMode(cpTenderChangeMode);
  cpRenderTenderQuick();
  cpRenderRielQuick();
})();
</script>
<?php if (!$tender_fragment): ?>
</body>
</html>
<?php endif; ?>
