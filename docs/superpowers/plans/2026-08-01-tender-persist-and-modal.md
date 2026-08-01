# Tender Persistence + Settle-in-Modal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Record the amount the customer handed over so counter settlements print Received / Change like checkout sales do, and let a cashier settle without leaving the queue.

**Architecture:** The tender is already persisted in `order_payments.reference` — the shipped tender screen simply discards it. Give the input a `name`, store it on settle, and change the receipt/success-screen gate from "the method says cash" to "a numeric tender was recorded". Separately, `find_order.php` fetches the existing tender partial into a modal instead of navigating.

**Tech Stack:** PHP 8.2 + mysqli, vanilla JS. Tests are CLI scripts: `php tests/<name>.php`.

## Global Constraints

- **The tender never changes what is settled.** The order is paid in full regardless of what is typed. A tender below the total must not become a partial payment.
- **Write the tender only when the order has exactly ONE payment row.** On a split, "the change" is ambiguous and `reference` belongs to whichever leg. 96 orders here are genuine splits.
- **Do not add Riel.** It is being folded into cash; building it here builds the thing about to be removed.
- **Do not touch `admin_pay_bakong.php`.** Its GET-time transaction persists a KHQR md5, not a settlement.
- **One source of tender markup.** The modal fetches `_cash_tender.php`; it is never reimplemented in JS.
- Bakong references are always blank in this database (182/182), so a numeric-reference gate cannot misfire on them.

---

### Task 1: Send and store the tender

**Files:**
- Modify: `_cash_tender.php` (name the input, underpayment warning)
- Modify: `admin_pay_cash.php` (store into `order_payments.reference`)

- [ ] **Step 1: Give the amount a name**

In `_cash_tender.php`, add `name="cash_received"` to the `#cpCashReceived` input, and
replace the comment above it that says the value is never submitted.

- [ ] **Step 2: Warn when the tender does not cover the bill**

Add below the change row in `_cash_tender.php`:

```html
<div id="cpShortWarn" style="display:none;margin-top:6px;font-size:11px;color:#e0a955;">
    <i class="fa-solid fa-triangle-exclamation"></i>
    This is less than the total. The order will still be settled in full.
</div>
```

and in `cpCalcChange()`, after the existing branches:

```js
  var warn = document.getElementById('cpShortWarn');
  if (warn) warn.style.display = (received > 0 && change < 0) ? 'block' : 'none';
```

Non-blocking by design: a cashier who already counted the change must not be stopped
by a field they skipped.

- [ ] **Step 3: Store it on settle**

In `admin_pay_cash.php`, inside the POST branch after the existing
`order_payments` status update, before the loyalty block:

```php
    // The tender is what makes a receipt show Received / Change. It lives in
    // order_payments.reference — the same column menu.php writes at checkout
    // (confirm_order.php:446) and receipt_pdf.php reads back.
    //
    // Only for a single-row payment: on a split, "the change" belongs to one leg
    // and writing it across every row would assert something we never saw.
    // Storing it never alters the amount settled.
    $rows = $conn->prepare("SELECT COUNT(*) FROM order_payments WHERE order_id = ?");
    $rows->bind_param("i", $order_id);
    $rows->execute();
    $rowCount = (int)$rows->get_result()->fetch_row()[0];

    $tender = $_POST['cash_received'] ?? '';
    if ($rowCount === 1 && is_numeric($tender) && (float)$tender > 0) {
        $tenderStr = number_format((float)$tender, 2, '.', '');
        $rf = $conn->prepare("UPDATE order_payments SET reference = ? WHERE order_id = ?");
        $rf->bind_param("si", $tenderStr, $order_id);
        $rf->execute();
    }
```

- [ ] **Step 4: Verify against a scratch order**

Never test this against order 1908 — it is the only open pay-later tab and is demo
data. Build a throwaway:

```bash
/c/xampp/mysql/bin/mysql.exe -u root db_coffee -e "
INSERT INTO orders (daily_order_no, customer_name, total, status, is_open, payment_method, business_date, order_date, order_type, points_earned)
VALUES (9992,'SCRATCH-TENDER-2',3.15,'Preparing',1,'paylater',CURDATE(),NOW(),'drink_in',0);
SELECT LAST_INSERT_ID() AS id;"
```

Then, with `$ID` from above:

```bash
/c/xampp/mysql/bin/mysql.exe -u root db_coffee -e \
  "INSERT INTO order_payments (order_id,payment_method,amount,payment_status) VALUES ($ID,'paylater',3.15,'unpaid');"

rm -f /tmp/ck.txt
curl -sk -c /tmp/ck.txt -b /tmp/ck.txt -X POST "https://localhost/Cafe/login.php" \
  -d "username=Sokun&password=%40Sokun9811" -o /dev/null
TOKEN=$(curl -sk -b /tmp/ck.txt "https://localhost/Cafe/admin_pay_cash.php?order_id=$ID&return=paylater" \
  | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -sk -b /tmp/ck.txt -X POST "https://localhost/Cafe/admin_pay_cash.php?order_id=$ID" \
  -d "csrf_token=$TOKEN&return=paylater&cash_received=20.00" -o /dev/null

/c/xampp/mysql/bin/mysql.exe -u root db_coffee -e \
  "SELECT payment_method,amount,reference,payment_status FROM order_payments WHERE order_id=$ID;"
```

Expected: `reference` = `20.00`, `amount` still `3.15` — the tender is recorded, the
settled amount is not touched.

Leave the scratch order in place; Task 2 needs it. Delete it at the end of Task 2.

- [ ] **Step 5: Commit**

```bash
git add _cash_tender.php admin_pay_cash.php
git commit -m "feat(cash): record the amount tendered at the counter"
```

---

### Task 2: Show the change on the receipt and the success screen

**Files:**
- Modify: `payment_cash.php:589`
- Modify: `receipt_pdf.php:458-484`
- Test: `tests/counter_cash_test.php` (extend)

- [ ] **Step 1: Write the failing test**

Append to `tests/counter_cash_test.php` before the final `echo`:

```php
echo "change block gating\n";
// The gate must key on "a tender was recorded", not on the method reading cash.
// A settled pay-later tab deliberately keeps payment_method='paylater' so the
// reporting bucket survives, and would otherwise never show its change.
$pc = file_get_contents(__DIR__ . '/../payment_cash.php');
check('success screen does not require cash',
      strpos($pc, "\$pay['payment_method'] === 'cash' && is_numeric") === false, true);
check('success screen still requires a numeric tender',
      strpos($pc, "is_numeric(\$pay['reference'])") !== false, true);

$rp = file_get_contents(__DIR__ . '/../receipt_pdf.php');
check('receipt gates on a tender, not the method',
      strpos($rp, '$has_tender') !== false, true);
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/counter_cash_test.php`
Expected: FAIL on `success screen does not require cash` and
`receipt gates on a tender, not the method`.

- [ ] **Step 3: Fix the success screen**

`payment_cash.php:589` — drop the method requirement:

```php
            // Gated on a recorded tender rather than on the method: a settled
            // pay-later tab keeps payment_method='paylater' on purpose, and a
            // Bakong reference is never numeric (all 182 are blank), so this
            // cannot misfire.
            if (is_numeric($pay['reference']) && (float)$pay['reference'] > 0):
```

- [ ] **Step 4: Fix the receipt**

`receipt_pdf.php:458` — keep `$is_solo_cash` deciding whether the PAYMENT block is
suppressed, but compute the tender separately so the change block can render for a
single-row pay-later settlement too:

```php
    $is_solo_cash = count($payments) === 1 && $payments[0]['payment_method'] === 'cash';
    // A tender was recorded. Independent of the method, because a settled
    // pay-later tab keeps payment_method='paylater' by design.
    $ref_val      = count($payments) === 1 ? (string)($payments[0]['reference'] ?? '') : '';
    $tendered_usd = is_numeric($ref_val) ? (float)$ref_val : 0.0;
    $has_tender   = $tendered_usd > 0;
```

Then change the block condition at `:460` from `if ($is_solo_cash) {` to
`if ($is_solo_cash || $has_tender) {`, and inside it remove the now-duplicated
`$ref_val` / `$tendered_usd` assignments at `:462-463`, keeping the
`if ($tendered_usd > 0)` guard that already wraps the markup.

**Do not** widen `$is_solo_cash` itself — it also suppresses the PAYMENT block, and a
pay-later receipt must keep showing how it was settled.

- [ ] **Step 5: Verify end to end**

Using the scratch order from Task 1 Step 4:

```bash
curl -sk -b /tmp/ck.txt "https://localhost/Cafe/payment_cash.php?order_id=$ID&from=paylater" -o /tmp/ps.html
grep -c "Fatal error" /tmp/ps.html
grep -o "Received\|Change (KHR)" /tmp/ps.html | sort -u
```

Expected: zero fatals, and both `Received` and `Change (KHR)` present. Then open
`receipt_pdf.php?order_id=$ID` in the browser and confirm the printed receipt shows
`Received $20.00 / Change $16.85 / Change (KHR)`.

- [ ] **Step 6: Clean up the scratch order**

```bash
/c/xampp/mysql/bin/mysql.exe -u root db_coffee -e "DELETE FROM order_payments WHERE order_id=$ID;"
/c/xampp/mysql/bin/mysql.exe -u root db_coffee -e "DELETE FROM orders WHERE order_id=$ID AND customer_name='SCRATCH-TENDER-2';"
/c/xampp/mysql/bin/mysql.exe -u root db_coffee -e "SELECT COUNT(*) AS leftover FROM orders WHERE customer_name LIKE 'SCRATCH-%';"
```

Expected: `leftover` = 0.

- [ ] **Step 7: Commit**

```bash
git add payment_cash.php receipt_pdf.php tests/counter_cash_test.php
git commit -m "fix(receipt): show change whenever a tender was recorded"
```

---

### Task 3: Settle from a modal without leaving the queue

**Files:**
- Modify: `admin_pay_cash.php` (serve a fragment)
- Modify: `_cash_tender.php` (fragment mode)
- Modify: `find_order.php` (modal + fetch, hook the loyalty flow)

- [ ] **Step 1: Serve the fragment**

In `admin_pay_cash.php`, replace the GET branch:

```php
if (!$is_settle) {
    // partial=1 returns just the tender panel for the find_order modal, so the
    // markup has exactly one source. Without it, the full standalone page renders
    // and still works for direct links and with JavaScript off.
    $tender_fragment = (($_GET['partial'] ?? '') === '1');
    include '_cash_tender.php';
    exit;
}
```

- [ ] **Step 2: Fragment mode in the partial**

In `_cash_tender.php`, wrap the document shell. Everything from `<!DOCTYPE html>`
through `<body>` becomes conditional, as does the closing `</body></html>`:

```php
<?php if (empty($tender_fragment)): ?>
<!DOCTYPE html>
... existing head and <style> ...
<body>
<?php endif; ?>
```

The `<style>` block must render in **both** modes, so move it above that condition
or duplicate nothing — simplest is to keep `<style>` outside the `if`, since a
repeated `<style>` in the modal is harmless and the rules are scoped by class.

The `.card` wrapper, the form and the `<script>` render in both modes unchanged.

- [ ] **Step 3: Add the modal to find_order.php**

Near the loyalty modal markup, add:

```html
<div class="tender-modal" id="tenderModal" style="display:none;position:fixed;inset:0;
     background:rgba(0,0,0,.65);z-index:1200;align-items:center;justify-content:center;">
    <div id="tenderModalBody" style="max-height:92vh;overflow-y:auto;"></div>
</div>
```

and the script:

```js
/* One source of tender markup: the panel is fetched from admin_pay_cash.php rather
   than rebuilt here. The form inside POSTs normally, so settling still lands on the
   success screen and its receipt. */
async function openTenderModal(url) {
    const modal = document.getElementById('tenderModal');
    const body  = document.getElementById('tenderModalBody');
    body.innerHTML = '<div style="padding:40px;color:#aaa;text-align:center">' +
                     '<i class="fa-solid fa-spinner fa-spin"></i></div>';
    modal.style.display = 'flex';
    try {
        const sep  = url.includes('?') ? '&' : '?';
        const resp = await fetch(url + sep + 'partial=1', { credentials: 'same-origin' });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        body.innerHTML = await resp.text();
        // innerHTML does not execute <script>, so re-run them to wire up the
        // tender buttons and the change calculator.
        body.querySelectorAll('script').forEach(function (old) {
            const s = document.createElement('script');
            s.textContent = old.textContent;
            document.body.appendChild(s);
            s.remove();
        });
    } catch (e) {
        // Never strand the cashier: fall back to the page that always worked.
        window.location.href = url;
    }
}
function closeTenderModal() { document.getElementById('tenderModal').style.display = 'none'; }
document.getElementById('tenderModal').addEventListener('click', function (e) {
    if (e.target === this) closeTenderModal();
});
```

- [ ] **Step 4: Route both entry points into it**

The pay-later path already runs a loyalty prompt first. Replace **both**
`window.location.href = lpDestUrl;` at `find_order.php:713` and `:716` with:

```js
    closeLpModal();
    openTenderModal(lpDestUrl);
```

(at `:713` the `closeLpModal()` may already be present — do not call it twice).

For non-pay-later cards the Cash link has no handler, so add one in
`_order_card.php` — on the cash link only, and only when `!$isPL`:

```php
<?= $isPL ? '...existing intercept...' : 'onclick="openTenderModal(this.href); return false;"' ?>
```

Bakong keeps navigating; it has no tender.

- [ ] **Step 5: Verify**

In the browser as `Sokun`:

1. `find_order.php?tab=paylater` → click **Cash**. Loyalty prompt appears as before.
2. Skip it → the tender panel opens **in a modal**, the URL bar still reads `find_order.php`.
3. Tender buttons and the change figure work inside the modal.
4. Type an amount below the total → the amber warning appears; the button still submits.
5. Click the backdrop → modal closes, nothing settled.
6. Reopen, **Confirm Cash Payment** → lands on the success screen with Received / Change.
7. `find_order.php?tab=all` → a non-pay-later Cash button opens the modal directly, no loyalty prompt.
8. With JS disabled, the Cash link still loads the standalone page.

- [ ] **Step 6: Run both suites**

```bash
php tests/counter_cash_test.php
php tests/purchase_order_test.php
```

Expected: `ALL PASS` for counter cash. `purchase_order_test.php` still reports its
2 known failures — those are Task 1 of the close-short plan, not this work.

- [ ] **Step 7: Commit**

```bash
git add admin_pay_cash.php _cash_tender.php find_order.php _order_card.php
git commit -m "feat(cash): settle from a modal without leaving the queue"
```

---

## Self-review notes

- **Spec coverage.** Amendment "what was wrong" → Task 1. Gap 1 (receipt/success gate) → Task 2. Gap 2 (modal) → Task 3. Underpayment warning → Task 1 Step 2.
- **Not automated:** the modal itself and the rendered receipt, both of which need a browser. The gate change is covered by source assertions, which is weaker than behavioural testing but catches the specific regression of someone re-adding the method check.
- **Known risk in Task 3 Step 2:** `_cash_tender.php` currently emits a full document. Splitting it wrongly yields a modal containing `<html>`. Step 5.2 checks the URL bar precisely because a botched split still *looks* fine until inspected.
