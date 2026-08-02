# Riel Into Cash Tender Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a cashier take dollars and riel in one cash payment, with the change split into whole dollars plus riel, and stop offering Riel as its own payment method.

**Architecture:** Two numbers — dollars received and riel received — are entered on the two existing cash tender screens, summed to one USD figure, and stored together in `order_payments.reference` as a two-part string parsed by one helper set. A dollars-only sale writes the bare number it writes today, so all 191 existing rows are untouched. Riel remains a valid `payment_method` for its 4 historical rows; only its UI is hidden.

**Tech Stack:** PHP 8 + mysqli (no framework), vanilla JS, MySQL/MariaDB via XAMPP. Tests are plain CLI scripts — `php tests/*.php` and `node tests/*.mjs`. There is no test framework in this project.

## Global Constraints

- **Reference shapes are exactly two:** `^\d+(\.\d+)?$` (legacy, USD-only) and `^\d+(\.\d+)?\|\d+$` (dollars`|`riel). Anything else is not a tender.
- **`tender_ref()` emits the bare number when riel is 0** so dollars-only sales stay byte-identical to today. `tender_ref(0, 0)` returns `''`.
- **Riel rounds to ៛100:** `round($usd * KHR_RATE / 100) * 100`.
- **Change split:** `dollars = floor(change_usd)`, `riel = round((change_usd - dollars) * RATE / 100) * 100`, then **if `riel >= RATE` → `dollars += 1; riel = 0`**.
- **`KHR_RATE`** is defined per request at `config.php:58` from the `khr_exchange_rate` setting. Currently **4100**. Never hardcode 4100 in PHP; JS receives it as a parameter.
- **JS function names mirror PHP exactly:** `tenderRef`, `tenderParts`, `tenderUsdTotal`, `tenderChange`.
- **`tender.js` lives at the repo root**, not `assets/js/`. The live precedent is `animations.js`, loaded as `<script src="animations.js?v=<?= @filemtime('animations.js') ?>"></script>` (see `find_order.php:796`). `assets/js/menu.js` exists but no PHP file references it.
- **`tender.js` must never bind anything on load.** It defines functions and does nothing else — no top-level DOM access, no listeners attached at load time. The DOM helpers it exports attach listeners *only when the host page calls them*, after that page's markup exists. This is the rule that matters: `find_order.php` injects `_cash_tender.php` via `innerHTML` and re-executes its `<script>` tags by hand (`find_order.php:673-676`), so anything that auto-bound at load would bind against markup that isn't there yet and silently do nothing. Each page's inline script owns the *calls*; the shared file owns the *logic*.
- **Hiding the Riel tile uses the `if (false)` + restore-comment pattern**, precedent `products.php:1508`. Do not delete riel markup, do not touch `order_payment_methods()`.
- **Never run `git commit` with `-i`** and never use PowerShell here-strings for commit messages; use `git commit -F -` with a heredoc.

---

## File Structure

| File | Responsibility |
|---|---|
| `config.php` | PHP tender helpers, beside `order_payment_methods()` |
| `tender.js` (new, root) | JS mirror of the same four functions, pure |
| `tests/tender_test.php` (new) | PHP helper assertions, no DB |
| `tests/tender.test.mjs` (new) | JS helper assertions, run with node |
| `menu.php` | Checkout modal: two-field cash box, hide Riel tile, guard fix |
| `cart.php` | Hide Riel tile only |
| `confirm_order.php` | Re-emit the posted reference canonically before insert |
| `_cash_tender.php` | Counter tender: two-field box, `CP_KHR_RATE`, guard fix |
| `admin_pay_cash.php` | Accept `cash_received_khr`, replace the `is_numeric` gate |
| `find_order.php` | Load `tender.js` as a host page |
| `receipt_pdf.php` | Print the real handover and the split change |
| `payment_cash.php` | Same reader swap |

---

## Task 1: PHP tender helpers

**Files:**
- Modify: `config.php` (insert after `order_payment_method_or()`, which ends at line 711)
- Test: `tests/tender_test.php` (create)

**Interfaces:**
- Consumes: `KHR_RATE` (constant, `config.php:58`)
- Produces:
  - `tender_ref(float $usd, int $khr): string`
  - `tender_parts(?string $ref): ?array` → `['usd'=>float,'khr'=>int]` or `null`
  - `tender_usd_total(?string $ref): float`
  - `tender_change(float $received_usd_total, float $owed): array` → `['usd'=>int,'khr'=>int,'short'=>bool]`

- [ ] **Step 1: Write the failing test**

Create `tests/tender_test.php`:

```php
<?php
/**
 * CLI assertions for the two-currency cash tender helpers.
 * Run:  php tests/tender_test.php
 * There is no test framework in this project; this script is the harness.
 * These helpers are pure — no database is touched and no order numbering is at risk.
 */
require __DIR__ . '/../config.php';

$failures = 0;
function check(string $what, $got, $want): void {
    global $failures;
    $ok = is_float($want) ? abs($got - $want) < 0.0001 : $got === $want;
    if ($ok) { echo "  PASS  $what\n"; return; }
    $failures++;
    echo "  FAIL  $what\n        got:  " . var_export($got, true)
       . "\n        want: " . var_export($want, true) . "\n";
}

echo "tender_ref\n";
// A dollars-only sale must write exactly what it writes today, or all 191
// existing rows would start printing differently.
check('dollars only stays a bare number', tender_ref(1.34, 0),    '1.34');
check('riel only',                        tender_ref(0, 5500),    '0.00|5500');
check('both currencies',                  tender_ref(1.00, 8000), '1.00|8000');
check('nothing tendered is empty',        tender_ref(0, 0),       '');

echo "tender_parts\n";
check('legacy bare number reads as USD',  tender_parts('5.00'),   ['usd'=>5.0,  'khr'=>0]);
check('two-part reads both',              tender_parts('0.00|5500'), ['usd'=>0.0,'khr'=>5500]);
check('integer legacy value',             tender_parts('20000'),  ['usd'=>20000.0,'khr'=>0]);
// A zero tender is still a tender. is_numeric() accepted '0' too, so this is
// not a behaviour change.
check('zero is a tender',                 tender_parts('0'),      ['usd'=>0.0,  'khr'=>0]);
// A Bakong transaction id must never be mistaken for money.
check('bakong txn id is not a tender',    tender_parts('KHQR9F2A1B'), null);
check('empty is not a tender',            tender_parts(''),       null);
check('null is not a tender',             tender_parts(null),     null);
check('malformed pipe is not a tender',   tender_parts('1.00|'),  null);
check('negative is not a tender',         tender_parts('-5.00'),  null);

echo "tender_usd_total\n";
check('dollars only',   round(tender_usd_total('5.00'), 2),      5.00);
check('riel only',      round(tender_usd_total('0.00|4100'), 2), 1.00);
check('both',           round(tender_usd_total('1.00|4100'), 2), 2.00);
check('not a tender is zero', tender_usd_total('KHQR9F2A1B'),    0.0);

echo "tender_change\n";
// $5.00 handed over on a $1.34 bill: $3.66 back. No US coins circulate, so the
// remainder is riel.
$c = tender_change(5.00, 1.34);
check('change splits into whole dollars', $c['usd'], 3);
check('remainder becomes riel',           $c['khr'], 2700);
check('a covered bill is not short',      $c['short'], false);
// The carry: a remainder that rounds up to a whole dollar must be handed back
// as a dollar bill, not as 4,100 riel in small notes.
$c = tender_change(5.33, 1.34);
check('rounding carry promotes to a dollar', $c['usd'], 4);
check('rounding carry leaves no riel',       $c['khr'], 0);
// Exact money.
$c = tender_change(1.34, 1.34);
check('exact tender gives no dollars', $c['usd'], 0);
check('exact tender gives no riel',    $c['khr'], 0);
// Short tender: the order still settles in full, but nothing is handed back.
$c = tender_change(1.00, 1.34);
check('a short tender is flagged', $c['short'], true);
check('a short tender hands back no dollars', $c['usd'], 0);
check('a short tender hands back no riel',    $c['khr'], 0);

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/tender_test.php`
Expected: `Fatal error: Uncaught Error: Call to undefined function tender_ref()`

- [ ] **Step 3: Write the implementation**

In `config.php`, immediately after the closing `}` of `order_payment_method_or()` (line 711):

```php
/**
 * A cash tender, recorded in two currencies.
 *
 * order_payments.reference has always held one bare number for a cash tender —
 * the dollars handed over — and receipts read it back to print Received and
 * Change. Taking riel as well needs two numbers in one column.
 *
 * The bare number is kept for a dollars-only tender so the 191 existing rows,
 * and every future dollars-only sale, write and read byte-identically. The
 * two-part form appears only when riel is actually involved.
 *
 * Riel is not a separate payment method here. The shop has one drawer; splitting
 * it across two tender types made the counter and the checkout disagree.
 */
if (!function_exists('tender_ref')) {
    function tender_ref(float $usd, int $khr): string {
        $usd = max(0, $usd);
        $khr = max(0, $khr);
        if ($usd <= 0 && $khr <= 0) { return ''; }
        if ($khr <= 0) { return number_format($usd, 2, '.', ''); }
        return number_format($usd, 2, '.', '') . '|' . $khr;
    }
}

/**
 * Read a stored tender back, or null if the value is not one.
 *
 * Exactly two shapes are recognised. Everything else — a Bakong transaction id,
 * an empty reference, a malformed string — returns null, so no reader can
 * mistake a non-tender for money. This replaces is_numeric(), which would have
 * accepted a bare '22000' written by anything at all.
 */
if (!function_exists('tender_parts')) {
    function tender_parts(?string $ref): ?array {
        $ref = trim((string)$ref);
        if ($ref === '') { return null; }
        if (preg_match('/^(\d+(?:\.\d+)?)$/', $ref, $m)) {
            return ['usd' => (float)$m[1], 'khr' => 0];
        }
        if (preg_match('/^(\d+(?:\.\d+)?)\|(\d+)$/', $ref, $m)) {
            return ['usd' => (float)$m[1], 'khr' => (int)$m[2]];
        }
        return null;
    }
}

/**
 * What a stored tender is worth in dollars. Zero for anything that is not one.
 */
if (!function_exists('tender_usd_total')) {
    function tender_usd_total(?string $ref): float {
        $p = tender_parts($ref);
        if ($p === null) { return 0.0; }
        return $p['usd'] + ($p['khr'] / KHR_RATE);
    }
}

/**
 * What the cashier physically hands back.
 *
 * Split the way it actually happens in Cambodia: whole dollars as notes, the
 * remainder under a dollar in riel, because no US coins circulate. Showing
 * "$3.66 or ៛15,000" and letting the cashier decide would leave the mental
 * arithmetic this screen exists to remove.
 *
 * The riel rounds to ៛100, the smallest note in practice, so a handover can be
 * a few cents either side of exact. That is already true of the ៛ total shown on
 * every screen. When the rounding fills a whole dollar it is promoted to a
 * dollar bill rather than handed over as 4,100 riel in small notes.
 *
 * Short tenders report short and hand back nothing. The order still settles in
 * full — a cashier who has already counted the change must not be blocked by a
 * field they skipped.
 */
if (!function_exists('tender_change')) {
    function tender_change(float $received_usd_total, float $owed): array {
        $change = round($received_usd_total - $owed, 4);
        if ($change <= 0) {
            return ['usd' => 0, 'khr' => 0, 'short' => $change < 0];
        }
        $dollars = (int)floor($change);
        $riel    = (int)(round((($change - $dollars) * KHR_RATE) / 100) * 100);
        if ($riel >= KHR_RATE) { $dollars += 1; $riel = 0; }
        return ['usd' => $dollars, 'khr' => $riel, 'short' => false];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/tender_test.php`
Expected: `ALL PASS`

- [ ] **Step 5: Confirm nothing else broke**

Run: `php tests/purchase_order_test.php`
Expected: `ALL PASS`

- [ ] **Step 6: Commit**

```bash
git add config.php tests/tender_test.php
git commit -F - <<'EOF'
feat(tender): record a cash tender in dollars and riel

order_payments.reference held one number, the dollars handed over. Taking riel
as well needs two, and the shop has one drawer — splitting it across two tender
types is what made the counter and the checkout disagree.

The bare number is kept for a dollars-only tender, so the 191 existing rows and
every future dollars-only sale write and read byte-identically. The two-part
form appears only when riel is involved.

tender_parts() recognises exactly two shapes and returns null for everything
else, so a Bakong transaction id can never be read as money. is_numeric(), which
it replaces, would have accepted a bare number written by anything.

Change splits into whole dollars plus riel under a dollar, because no US coins
circulate here. Riel rounds to the 100 note; when that rounding fills a whole
dollar it is promoted to a dollar bill rather than handed over in small notes.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 2: JS mirror of the helpers

**Files:**
- Create: `tender.js` (repo root, beside `animations.js`)
- Test: `tests/tender.test.mjs` (create)

**Interfaces:**
- Consumes: nothing — rate passed in
- Produces, pure:
  - `tenderRef(usd, khr)` → `'1.34'` | `'0.00|5500'` | `''`
  - `tenderParts(ref)` → `{usd, khr}` | `null`
  - `tenderUsdTotal(ref, rate)` → number
  - `tenderChange(receivedUsdTotal, owed, rate)` → `{usd, khr, short}`
  - `tenderChangeText(ch, received, owed)` → the string the change line displays
- Produces, DOM — **called by the host page, never bound on load**:
  - `tenderCashReceivedUsd(usdId, khrId, rate)` → number
  - `tenderOnRielInput(usdId, khrId, eqId, rate)` → void
  - `tenderRenderRielQuick(wrapId, khrId, owed, rate, onPick)` → void

These four exist so `menu.php` and `_cash_tender.php` do not each carry their
own copy — the two screens must agree to the cent, and a formula duplicated
across files is how this codebase's buy-X-get-1-free rule ended up in ten
places. The DOM ones are verified in the browser (Task 8), not in node; only the
pure ones are unit-tested here.

- [ ] **Step 1: Write the failing test**

Create `tests/tender.test.mjs`:

```js
// CLI assertions for the browser-side tender helpers.
// Run:  node tests/tender.test.mjs
// tender.js is a plain script (no modules) so the browser can load it with a
// bare <script src>; this harness evaluates it the same way.
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const src  = readFileSync(join(here, '..', 'tender.js'), 'utf8');
// Only the pure functions are exposed here. The DOM helpers need a document and
// are verified in the browser (Task 8) rather than against a shim that would
// prove nothing about the real page.
const ctx  = {};
new Function('globalThis', src + '\nglobalThis.tenderRef=tenderRef;'
  + 'globalThis.tenderParts=tenderParts;globalThis.tenderUsdTotal=tenderUsdTotal;'
  + 'globalThis.tenderChange=tenderChange;globalThis.tenderChangeText=tenderChangeText;')(ctx);

let failures = 0;
function check(what, got, want) {
  const ok = JSON.stringify(got) === JSON.stringify(want);
  if (ok) { console.log(`  PASS  ${what}`); return; }
  failures++;
  console.log(`  FAIL  ${what}\n        got:  ${JSON.stringify(got)}\n        want: ${JSON.stringify(want)}`);
}

const RATE = 4100;

console.log('tenderRef');
check('dollars only stays a bare number', ctx.tenderRef(1.34, 0),    '1.34');
check('riel only',                        ctx.tenderRef(0, 5500),    '0.00|5500');
check('both currencies',                  ctx.tenderRef(1.00, 8000), '1.00|8000');
check('nothing tendered is empty',        ctx.tenderRef(0, 0),       '');

console.log('tenderParts');
check('legacy bare number reads as USD', ctx.tenderParts('5.00'),      {usd:5,   khr:0});
check('two-part reads both',             ctx.tenderParts('0.00|5500'), {usd:0,   khr:5500});
check('bakong txn id is not a tender',   ctx.tenderParts('KHQR9F2A1B'), null);
check('empty is not a tender',           ctx.tenderParts(''),          null);

console.log('tenderUsdTotal');
check('riel only', Math.round(ctx.tenderUsdTotal('0.00|4100', RATE) * 100) / 100, 1);
check('both',      Math.round(ctx.tenderUsdTotal('1.00|4100', RATE) * 100) / 100, 2);

console.log('tenderChange');
// Must agree with the PHP twin exactly — the two are kept in step by hand.
check('change splits into whole dollars', ctx.tenderChange(5.00, 1.34, RATE), {usd:3, khr:2700, short:false});
check('rounding carry promotes a dollar', ctx.tenderChange(5.33, 1.34, RATE), {usd:4, khr:0,    short:false});
check('exact tender',                     ctx.tenderChange(1.34, 1.34, RATE), {usd:0, khr:0,    short:false});
check('short tender',                     ctx.tenderChange(1.00, 1.34, RATE), {usd:0, khr:0,    short:true});
// The riel-only case the whole feature exists for.
check('riel-only exact payment', ctx.tenderChange(ctx.tenderUsdTotal('0.00|5500', RATE), 1.34, RATE),
      {usd:0, khr:0, short:false});

console.log('tenderChangeText');
check('dollars and riel',  ctx.tenderChangeText({usd:3, khr:2700, short:false}, 5.00, 1.34), '$3 + ៛2,700');
check('riel only',         ctx.tenderChangeText({usd:0, khr:2700, short:false}, 2.00, 1.34), '៛2,700');
check('dollars only',      ctx.tenderChangeText({usd:4, khr:0,    short:false}, 5.34, 1.34), '$4.00');
check('nothing to give back', ctx.tenderChangeText({usd:0, khr:0, short:false}, 1.34, 1.34), '$0.00');
check('short says what is missing', ctx.tenderChangeText({usd:0, khr:0, short:true}, 1.00, 1.34), 'Need $0.34 more');

console.log(failures === 0 ? '\nALL PASS' : `\n${failures} FAILURE(S)`);
process.exit(failures === 0 ? 0 : 1);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node tests/tender.test.mjs`
Expected: `Error: ENOENT: no such file or directory, open '...tender.js'`

- [ ] **Step 3: Write the implementation**

Create `tender.js` at the repo root:

```js
/* Two-currency cash tender — the browser twin of the PHP helpers in config.php.
 *
 * NOTHING BINDS ON LOAD. This file defines functions and does nothing else. The
 * DOM helpers below attach listeners only when a host page CALLS them, after
 * that page's markup exists. find_order.php injects _cash_tender.php with
 * innerHTML and re-executes its <script> tags by hand, so anything that bound
 * at load time would bind against markup that is not there yet and silently do
 * nothing. Each page owns the calls; this file owns the logic.
 *
 * The exchange rate is a PARAMETER, not a constant. This file is static and is
 * never parsed by PHP, so it cannot read KHR_RATE. Each host page inlines the
 * rate at render (menu.php emits const CP_KHR_RATE) and passes it in.
 *
 * Keep in step with config.php's tender_ref / tender_parts / tender_usd_total /
 * tender_change. Same names, same rules, same rounding.
 */

function tenderRef(usd, khr) {
  usd = Math.max(0, Number(usd) || 0);
  khr = Math.max(0, Math.round(Number(khr) || 0));
  if (usd <= 0 && khr <= 0) { return ''; }
  if (khr <= 0) { return usd.toFixed(2); }
  return usd.toFixed(2) + '|' + khr;
}

function tenderParts(ref) {
  ref = String(ref == null ? '' : ref).trim();
  if (ref === '') { return null; }
  var one = /^(\d+(?:\.\d+)?)$/.exec(ref);
  if (one) { return { usd: parseFloat(one[1]), khr: 0 }; }
  var two = /^(\d+(?:\.\d+)?)\|(\d+)$/.exec(ref);
  if (two) { return { usd: parseFloat(two[1]), khr: parseInt(two[2], 10) }; }
  return null;
}

function tenderUsdTotal(ref, rate) {
  var p = tenderParts(ref);
  if (p === null) { return 0; }
  return p.usd + (p.khr / rate);
}

function tenderChange(receivedUsdTotal, owed, rate) {
  var change = Math.round((receivedUsdTotal - owed) * 10000) / 10000;
  if (change <= 0) { return { usd: 0, khr: 0, short: change < 0 }; }
  var dollars = Math.floor(change);
  var riel    = Math.round(((change - dollars) * rate) / 100) * 100;
  if (riel >= rate) { dollars += 1; riel = 0; }
  return { usd: dollars, khr: riel, short: false };
}

/* What the change line reads. Shared so the checkout modal and the counter
   screen cannot drift apart — they must agree to the cent. */
function tenderChangeText(ch, received, owed) {
  if (ch.short) { return 'Need $' + (owed - received).toFixed(2) + ' more'; }
  if (ch.khr > 0) {
    return ch.usd > 0
      ? '$' + ch.usd + ' + ៛' + ch.khr.toLocaleString()
      : '៛' + ch.khr.toLocaleString();
  }
  return '$' + ch.usd.toFixed(2);
}

/* ── DOM helpers ──────────────────────────────────────────────────────────
   Shared so menu.php and _cash_tender.php do not each carry a copy. Called by
   the host page after its markup exists; never bound on load. */

/* The combined tender in dollars. Keyed on BOTH fields: with dollars and riel
   separate, zero dollars is the normal riel-only case, and a dollars-only test
   would leave the change line at $0.00 while the cashier holds 5,500 riel. */
function tenderCashReceivedUsd(usdId, khrId, rate) {
  var usd = parseFloat((document.getElementById(usdId) || {}).value) || 0;
  var khr = parseFloat((document.getElementById(khrId) || {}).value) || 0;
  return Math.max(0, usd) + Math.max(0, khr) / rate;
}

/* The prefill trap. The dollar field is pre-seeded with the exact total so
   one-tap exact cash stays one tap. With a second field that seed is dangerous:
   a prefilled $1.34 plus a typed ៛5,500 reads as $2.68 received on a $1.34
   order, and the screen would confidently show change that was never owed.
   The first real keystroke in the riel field clears an UNTOUCHED dollar
   prefill. A dollar amount the cashier typed themselves carries
   dataset.touched and is never cleared. */
function tenderOnRielInput(usdId, khrId, eqId, rate) {
  var ri  = document.getElementById(khrId);
  var cr  = document.getElementById(usdId);
  var khr = Math.max(0, parseFloat(ri ? ri.value : 0) || 0);
  if (cr && cr.dataset.touched !== '1' && khr > 0) { cr.value = ''; }
  var eq = document.getElementById(eqId);
  if (eq) { eq.textContent = '≈ $' + (khr / rate).toFixed(2); }
}

/* Riel notes that could plausibly cover the bill, capped at four — the same
   rule the dollar buttons already use. onPick runs after the value is set so
   the host can recalculate its own change line. */
function tenderRenderRielQuick(wrapId, khrId, owed, rate, onPick) {
  var wrap = document.getElementById(wrapId);
  if (!wrap) { return; }
  wrap.innerHTML = '';
  if (owed <= 0) { return; }
  var owedKhr = Math.round(owed * rate / 100) * 100;

  var mk = function (label, val) {
    var b = document.createElement('button');
    b.type = 'button';          // a bare <button> in a form submits it
    b.className = 'cp-tender-btn';
    b.textContent = label;
    b.addEventListener('click', function () {
      var ri = document.getElementById(khrId);
      if (!ri) { return; }
      ri.value = val;
      if (typeof onPick === 'function') { onPick(); }
    });
    return b;
  };

  wrap.appendChild(mk('Exact ៛', owedKhr));
  [5000, 10000, 20000, 50000, 100000]
    .filter(function (n) { return n > owedKhr; })
    .slice(0, 4)
    .forEach(function (n) { wrap.appendChild(mk('៛' + n.toLocaleString(), n)); });
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `node tests/tender.test.mjs`
Expected: `ALL PASS`

- [ ] **Step 5: Commit**

```bash
git add tender.js tests/tender.test.mjs
git commit -F - <<'EOF'
feat(tender): browser twin of the tender helpers

Same four functions as config.php, same names, same rounding. The two are kept
in step by hand, as order_board_state() and boardState() already are.

Pure functions only — no listeners, no DOM. find_order.php injects
_cash_tender.php with innerHTML and re-executes its script tags by hand, so a
shared file that attached listeners would have to re-bind after injection and
would silently fail when it didn't. Wiring stays inline in the page that owns
the markup.

The rate is a parameter. This file is static and never parsed by PHP, so it
cannot read KHR_RATE; each host page inlines the rate and passes it in.

Placed at the repo root beside animations.js, which is the pattern actually
loaded by the app. assets/js/ exists but no PHP file references anything in it.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 3: Checkout modal — two-field cash box

**Files:**
- Modify: `menu.php` — tile at `1290-1294`, cash box at `1311-1322`, `cpUpdateConfirmBtn()` at `1960-2010`, `cpCalcChange()` at `2239-2248`, `cpClosePayModal()` at `2143-2157`, submit handler at `2359-2362`
- Modify: `menu.php` `<head>` — load `tender.js`

**Interfaces:**
- Consumes: `tenderRef`, `tenderChange`, `tenderUsdTotal` from Task 2; `CP_KHR_RATE` (already emitted at `menu.php:1378`)
- Produces: a `payment_references[]` value in `tender_ref()` format for the cash leg

- [ ] **Step 1: Load the shared helpers**

In `menu.php`, beside the other root scripts, add:

```php
<script src="tender.js?v=<?= @filemtime('tender.js') ?>"></script>
```

- [ ] **Step 2: Hide the Riel tile**

Replace `menu.php:1290-1294` with:

```php
        <?php /* Riel is no longer offered as its own method: the shop has one
                 drawer, and riel is now taken inside the Cash tender below.
                 Hidden rather than deleted — 4 historical orders still carry
                 payment_method='riel' and their receipts read this method.
                 To restore, change false back to true. */ ?>
        <?php if (false): ?>
        <div class="cp-pay-method" data-method="riel" onclick="cpTogglePayment(this)">
          <input type="checkbox" value="riel">
          <i class="cp-pm-ico fa-solid fa-coins"></i><span class="cp-pm-lbl">Riel &#x17DB;</span>
          <i class="cp-pm-check fa-solid fa-circle-check"></i>
        </div>
        <?php endif; ?>
```

Also remove the `R` shortcut hint at `menu.php:1183` (`<span><kbd>R</kbd> Riel</span>`), since the tile it names is gone.

- [ ] **Step 3: Replace the cash box markup**

Replace `menu.php:1311-1322` with:

```html
      <div class="cp-change-calc" id="cpChangeCalc">
        <label><i class="fa-solid fa-money-bill-wave" style="color:#55e087;margin-right:4px;"></i> Amount Received</label>

        <div style="display:flex;align-items:center;gap:6px;">
          <span style="font-size:12px;font-weight:700;color:var(--text-2,#aaa);width:16px;">$</span>
          <!-- oninput only fires for real typing, never for a programmatic .value
               set, so dataset.touched reliably marks "the cashier entered this". -->
          <input type="number" id="cpCashReceived" step="0.01" min="0" placeholder="0.00"
                 oninput="this.dataset.touched='1'; cpCalcChange(); cpMarkActiveTender(this.value)"
                 onfocus="this.select()">
        </div>
        <div class="cp-tender-quick" id="cpTenderQuick"></div>

        <div style="display:flex;align-items:center;gap:6px;margin-top:9px;">
          <span style="font-size:12px;font-weight:700;color:var(--text-2,#aaa);width:16px;">&#x17DB;</span>
          <input type="number" id="cpRielCash" step="100" min="0" placeholder="0"
                 oninput="cpOnRielInput(); cpCalcChange()" onfocus="this.select()">
          <span id="cpRielCashUsd" style="font-size:11px;color:#888;white-space:nowrap;">&asymp; $0.00</span>
        </div>
        <div class="cp-tender-quick" id="cpRielQuick"></div>

        <div class="cp-change-row">
          <span class="change-label">Change to give back</span>
          <span class="change-amount" id="cpChangeAmount">$0.00</span>
        </div>
        <div id="cpShortWarn" style="display:none;margin-top:6px;font-size:11px;color:#e0a955;">
          <i class="fa-solid fa-triangle-exclamation"></i>
          This is less than the total. The order will still be settled in full.
        </div>
      </div>
```

- [ ] **Step 4: Replace `cpCalcChange()` — the guard fix**

Replace `menu.php:2239-2248` with:

```js
/* The received === 0 guard used to mean "nothing entered yet". With dollars and
   riel in separate fields, ZERO DOLLARS IS THE NORMAL RIEL-ONLY CASE — a cashier
   who types ៛5,500 on a $1.34 order would otherwise see the change line sit at
   $0.00 and hand back nothing: wrong on screen, right in the database. The guard
   keys on the combined total from tender.js instead.
   The arithmetic and the wording both live in tender.js so this screen and the
   counter screen cannot drift apart. */
function cpCashReceivedUsd() {
  return tenderCashReceivedUsd('cpCashReceived', 'cpRielCash', CP_KHR_RATE);
}

function cpCalcChange() {
  var el = document.getElementById('cpChangeAmount');
  if (!el) return;
  var received = cpCashReceivedUsd();
  var owed     = cpOwedInCash();
  var ch       = tenderChange(received, owed, CP_KHR_RATE);

  var warn = document.getElementById('cpShortWarn');
  if (warn) warn.style.display = (received > 0 && ch.short) ? 'block' : 'none';

  if (received === 0) { el.textContent = '$0.00'; el.className = 'change-amount'; return; }
  el.className   = ch.short ? 'change-amount not-enough' : 'change-amount';
  el.textContent = tenderChangeText(ch, received, owed);
}

function cpOnRielInput() {
  tenderOnRielInput('cpCashReceived', 'cpRielCash', 'cpRielCashUsd', CP_KHR_RATE);
}

function cpRenderRielQuick() {
  tenderRenderRielQuick('cpRielQuick', 'cpRielCash', cpOwedInCash(), CP_KHR_RATE,
    function () { cpOnRielInput(); cpCalcChange(); });
}
```

- [ ] **Step 5: Render the riel quick buttons when the cash box opens**

In `cpUpdateConfirmBtn()`, in the `selected.includes('cash')` branch (around `menu.php:1999-2005`) and in the split branch that shows the cash box (around `1982-1986`), add `cpRenderRielQuick();` immediately after each existing `cpPrefillCashReceived();` call.

- [ ] **Step 6: Clear the riel field when the modal closes**

In `cpClosePayModal()`, replace the line `var ri = document.getElementById('cpRielReceived'); if (ri) ri.value = '';` with:

```js
  var ri = document.getElementById('cpRielCash');
  if (ri) ri.value = '';
  var eq = document.getElementById('cpRielCashUsd');
  if (eq) eq.textContent = '≈ $0.00';
```

- [ ] **Step 7: Submit the two-part reference**

Replace `menu.php:2359-2362` with:

```js
        if (method === 'cash') {
          var usd = Math.max(0, parseFloat((document.getElementById('cpCashReceived') || {}).value) || 0);
          var khr = Math.max(0, parseFloat((document.getElementById('cpRielCash')     || {}).value) || 0);
          reference = tenderRef(usd, Math.round(khr));
        }
```

- [ ] **Step 8: Verify in the browser**

Run: log in at `http://localhost/Cafe/login.php` as `Sokun` / `@Sokun9811`, open `menu.php`, add one item, click Confirm Order, choose Cash.
Expected: three method tiles (Bakong, Cash, Later) with no Riel; the cash box shows a `$` field prefilled with the total and a `៛` field at 0. Typing `5500` in the riel field clears the dollar prefill and the change line updates. **Do not submit the order** — the submit path is verified in Task 8.

- [ ] **Step 9: Commit**

```bash
git add menu.php
git commit -F - <<'EOF'
feat(checkout): take riel inside the cash tender

The cash box takes dollars and riel side by side and shows the change the way it
is physically handed back — whole dollars plus riel under a dollar. Riel stops
being its own method tile; the shop has one drawer.

The tile is hidden, not deleted. Four historical orders carry
payment_method='riel' and their receipts still read that method, and keeping
riel a known method is also what stops a stray riel POST from being coerced to
cash while its raw KHR reference is read as dollars.

Fixes a guard that was about to become wrong: received === 0 meant "nothing
entered yet", but with separate fields zero dollars is the normal riel-only
case. A cashier typing 5,500 riel on a $1.34 order would have seen the change
line sit at $0.00 and handed back nothing — wrong on screen, right in the
database.

The dollar field's exact-total prefill is cleared by the first real keystroke in
the riel field. Prefilled $1.34 plus a typed 5,500 riel reads as $2.68 received
on a $1.34 order, and the screen would have shown change that was never owed. A
dollar amount the cashier typed themselves is never cleared; dataset.touched
already carried that distinction.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 4: Canonical reference on insert

**Files:**
- Modify: `confirm_order.php:449`

**Interfaces:**
- Consumes: `tender_ref`, `tender_parts` from Task 1

- [ ] **Step 1: Re-emit the posted reference**

Replace `confirm_order.php:449` (`$reference = $payment_references[$i] ?? '';`) with:

```php
        $reference = (string)($payment_references[$i] ?? '');
        // A cash tender is re-emitted through tender_ref() so the stored string
        // is always canonical, whatever the POST contained. Same guard pattern
        // as f5aea86, which stopped orders.payment_method being written verbatim
        // from a POST and leaving 195 rows reading '0'.
        //
        // Only the cash leg: a Bakong reference is a transaction id and must
        // pass through untouched, and tender_parts() would return null for it
        // anyway.
        if ($method === 'cash') {
            $parts     = tender_parts($reference);
            $reference = $parts === null ? '' : tender_ref($parts['usd'], $parts['khr']);
        }
```

- [ ] **Step 2: Verify the helpers still pass**

Run: `php tests/tender_test.php`
Expected: `ALL PASS`

- [ ] **Step 3: Commit**

```bash
git add confirm_order.php
git commit -F - <<'EOF'
fix(checkout): store a canonical cash tender reference

The posted reference went into order_payments verbatim. A cash tender is now
re-parsed and re-emitted through tender_ref(), so a hand-crafted or malformed
POST cannot store a value the receipt readers would misread.

Same guard pattern as f5aea86, which stopped orders.payment_method being written
straight from a POST — the reason 195 rows read '0' and match no method.

Only the cash leg. A Bakong reference is a transaction id and passes through
untouched.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 5: Counter tender screen

**Files:**
- Modify: `_cash_tender.php` — form at `188-208`, script at `226-298`
- Modify: `admin_pay_cash.php:133`, `:146-151`
- Modify: `find_order.php` — add the `tender.js` loader beside `animations.js` at `:796`

**Interfaces:**
- Consumes: `tenderRef`/`tenderChange` (Task 2), `tender_parts`/`tender_ref`/`tender_usd_total` (Task 1)
- Produces: `cash_received` + `cash_received_khr` POST fields

- [ ] **Step 1: Load the helpers on both hosts**

In `find_order.php`, immediately after the `animations.js` tag at line 796:

```php
<script src="tender.js?v=<?= @filemtime('tender.js') ?>"></script>
```

In `_cash_tender.php`, inside the `<?php if (!$tender_fragment): ?>` head block (after the Font Awesome link at line 43):

```php
<script src="tender.js?v=<?= @filemtime('tender.js') ?>"></script>
```

- [ ] **Step 2: Add the riel field to the form**

Replace the `<div class="cp-change-calc">` block at `_cash_tender.php:188-208` with:

```html
            <div class="cp-change-calc">
                <label><i class="fa-solid fa-money-bill-wave" style="color:#55e087;margin-right:4px;"></i> Amount Received</label>

                <div style="display:flex;align-items:center;gap:6px;">
                    <span style="font-size:12px;font-weight:700;color:var(--text-2);width:16px;">$</span>
                    <!-- Submitted as cash_received and stored, with the riel below, in
                         order_payments.reference — the same column checkout writes — so
                         the receipt can print Received / Change. Never changes the
                         amount settled: the order is paid in full whatever is typed. -->
                    <input type="number" id="cpCashReceived" name="cash_received"
                           step="0.01" min="0" placeholder="0.00"
                           oninput="this.dataset.touched='1'; cpCalcChange(); cpMarkActiveTender(this.value)"
                           onfocus="this.select()">
                </div>
                <div class="cp-tender-quick" id="cpTenderQuick"></div>

                <div style="display:flex;align-items:center;gap:6px;margin-top:9px;">
                    <span style="font-size:12px;font-weight:700;color:var(--text-2);width:16px;">&#6107;</span>
                    <input type="number" id="cpRielCash" name="cash_received_khr"
                           step="100" min="0" placeholder="0"
                           oninput="cpOnRielInput(); cpCalcChange()" onfocus="this.select()">
                    <span id="cpRielCashUsd" style="font-size:11px;color:var(--text-3);white-space:nowrap;">&asymp; $0.00</span>
                </div>
                <div class="cp-tender-quick" id="cpRielQuick"></div>

                <div class="cp-change-row">
                    <span class="change-label">Change to give back</span>
                    <span class="change-amount" id="cpChangeAmount">$0.00</span>
                </div>
                <div id="cpShortWarn" style="display:none;margin-top:6px;font-size:11px;color:#e0a955;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    This is less than the total. The order will still be settled in full.
                </div>
            </div>
```

- [ ] **Step 3: Replace the inline script**

Replace `_cash_tender.php:226-298` (everything between `<script>` and `</script>`) with:

```js
const CP_OWED     = <?= json_encode(round($owed, 2)) ?>;
// tender.js is static and never parsed by PHP, so the rate is passed in.
const CP_KHR_RATE = <?= defined('KHR_RATE') ? (int)KHR_RATE : 4100 ?>;

function cpOwedInCash() { return CP_OWED; }

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
  var ch       = tenderChange(received, owed, CP_KHR_RATE);

  var warn = document.getElementById('cpShortWarn');
  // Non-blocking on purpose: a cashier who has already counted the change must
  // not be stopped by a field they skipped, and the order settles in full.
  if (warn) warn.style.display = (received > 0 && ch.short) ? 'block' : 'none';

  if (received === 0) { el.textContent = '$0.00'; el.className = 'change-amount'; return; }
  el.className   = ch.short ? 'change-amount not-enough' : 'change-amount';
  el.textContent = tenderChangeText(ch, received, owed);
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
  cpCalcChange();
  cpRenderTenderQuick();
  cpRenderRielQuick();
})();
```

- [ ] **Step 4: Accept the riel field server-side**

Replace `admin_pay_cash.php:133` with:

```php
    // Re-emitted through tender_ref() so the stored string is canonical whatever
    // the POST contained, exactly as confirm_order.php does for the checkout leg.
    $tender = tender_ref(
        (float)($_POST['cash_received']     ?? 0),
        (int)  ($_POST['cash_received_khr'] ?? 0)
    );
```

Replace `admin_pay_cash.php:146` (`if (is_numeric($tender) && (float)$tender > 0) {`) and its body with:

```php
        // Gated on tender_parts(), not is_numeric(): "0.00|5500" is a valid
        // riel-only tender and is not numeric, so the old gate would have
        // written NO reference at all — silently — and the receipt would print
        // with no Received/Change lines, which is the gap this block closes.
        if (tender_parts($tender) !== null && tender_usd_total($tender) > 0) {
            $rf = $conn->prepare("UPDATE order_payments SET reference = ? WHERE order_id = ?");
            $rf->bind_param("si", $tender, $order_id);
            $rf->execute();
        }
```

Delete the now-unused `$tenderStr` line.

- [ ] **Step 5: Verify in the browser**

Run: as `Sokun`, open `find_order.php`, find an unpaid or pay-later order, and click its cash settle button to open the tender modal.
Expected: the modal shows the `$` field prefilled with the amount due, a `៛` field at 0, both quick-button rows, and the change line responds to either field. **Do not submit** — Task 8 covers settlement.

- [ ] **Step 6: Commit**

```bash
git add _cash_tender.php admin_pay_cash.php find_order.php
git commit -F - <<'EOF'
feat(counter): take riel when settling at the counter

The counter tender screen had no riel option at all — zero references — so a
pay-later tab settled in riel had to be booked as dollars. It now takes both
currencies, with the same fields, rounding and change split as the checkout
modal.

Fixes a gate that would have dropped the reference silently: is_numeric() is
false for "0.00|5500", so a riel-only settlement would have written no reference
at all and printed a receipt with no Received or Change lines — the exact gap
that block was added to close. Gated on tender_parts() instead.

The rowCount === 1 restriction and the amount sync are untouched. Change is
still measured against orders.total, never against a pay-later row that records
the tab's opening total and is never updated as items are added.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 6: Receipt readers

**Files:**
- Modify: `receipt_pdf.php:534` and the change rows at `:541-544`
- Modify: `payment_cash.php:593-604`

**Interfaces:**
- Consumes: `tender_parts`, `tender_usd_total`, `tender_change` from Task 1

- [ ] **Step 1: Update `receipt_pdf.php`**

Replace `receipt_pdf.php:534` (`$tendered_usd   = is_numeric($ref) ? (float)$ref : 0;`) with:

```php
                // tender_parts() accepts exactly two shapes where is_numeric()
                // would have taken a bare number written by anything. The riel
                // branch above still handles the 4 historical payment_method='riel'
                // rows; this branch now handles riel taken as cash.
                $tender_p     = tender_parts($ref);
                $tendered_usd = tender_usd_total($ref);
```

Then, inside the `if ($tendered_usd > 0) {` block, replace the `$ck` line with:

```php
                    $ch = tender_change($tendered_usd, $owed_for_change);
                    $received_label = ($tender_p && $tender_p['khr'] > 0)
                        ? ($tender_p['usd'] > 0
                            ? '$' . number_format($tender_p['usd'], 2) . ' + KHR ' . number_format($tender_p['khr'])
                            : 'KHR ' . number_format($tender_p['khr']))
                        : '$' . number_format($tendered_usd, 2);
                    $change_label = $ch['khr'] > 0
                        ? ($ch['usd'] > 0
                            ? '$' . $ch['usd'] . ' + KHR ' . number_format($ch['khr'])
                            : 'KHR ' . number_format($ch['khr']))
                        : '$' . number_format(max(0, $change_usd), 2);
```

Use `$received_label` where the Received row prints the amount, and `$change_label` where the change row does. Leave `$owed_for_change` exactly as it is — that line is the `143fa32` fix.

- [ ] **Step 2: Update `payment_cash.php`**

Replace `payment_cash.php:593-604` with:

```php
            // Gated on a recorded tender rather than on the method. A pay-later
            // tab settled in cash keeps payment_method='paylater' so its
            // reporting bucket survives. tender_parts() replaces is_numeric():
            // a riel-only tender reads "0.00|5500", which is not numeric, and a
            // Bakong reference is neither shape so it still cannot misfire.
            $tender_p = tender_parts($pay['reference']);
            if ($tender_p !== null && tender_usd_total($pay['reference']) > 0):
                $received = tender_usd_total($pay['reference']);
                // Change is measured against what the customer owed. On a
                // single-row payment that is the order total: a pay-later row is
                // written when the tab opens and is NOT updated as items are
                // added, so 18 orders carry a stale amount (order 1908: row says
                // $1.34, order totals $19.78). A split's legs are per-method and
                // genuinely correct.
                $owed_for_change = count($payments) === 1 ? $total : (float)$pay['amount'];
                $ch         = tender_change($received, $owed_for_change);
                $change_usd = round(max(0, $received - $owed_for_change), 2);
                $change_khr = $ch['khr'];
            ?>
```

Then update the two display rows to print `$tender_p['khr'] > 0 ? ... : ...` in the same shape as Task 6 Step 1.

- [ ] **Step 3: Verify no reader regressed on existing data**

Run:
```bash
php -r 'require "config.php";
$r=$conn->query("SELECT reference FROM order_payments WHERE reference<>\"\" LIMIT 50");
$n=0; while($x=$r->fetch_row()){ if (tender_parts($x[0])!==null) $n++; }
echo "parsed as tenders: $n of 50\n";'
```
Expected: a non-zero count, and no PHP errors. Bakong references correctly return null.

- [ ] **Step 4: Commit**

```bash
git add receipt_pdf.php payment_cash.php
git commit -F - <<'EOF'
fix(receipts): print what actually crossed the counter

Readers swapped is_numeric() for tender_parts(), which accepts exactly two
shapes where the old test would have taken a bare number written by anything.

Received now prints the real handover — "$1.00 + KHR 8,000", or "KHR 5,500" when
no dollars were involved — instead of a single converted figure that
misdescribes what the customer handed over. Change prints the split the cashier
actually gives back. A dollars-only sale prints exactly as it does today.

The riel branch and label stay: 4 historical orders carry payment_method='riel'
and still read them.

owed_for_change is untouched. That line is the 143fa32 fix — change is measured
against the order total, not a pay-later row that records the tab's opening
total and is never updated as items are added.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 7: Hide the Riel tile on `cart.php`

**Files:**
- Modify: `cart.php:1358-1361` (tile), `:1384-1396` (calculator), `:1448` (shortcut hint)

- [ ] **Step 1: Hide the tile and its calculator**

Wrap the method tile at `cart.php:1358-1361` and the `rielCalc` block at `:1384-1396` each in:

```php
<?php /* Riel is taken inside the Cash tender on menu.php's payment modal now.
         cart.php is a duplicate checkout surface whose entry link was dropped in
         fec47c6, so it gets no riel field of its own. Hidden rather than deleted
         because riel is still a valid payment_method for 4 historical orders.
         To restore, change false back to true. */ ?>
<?php if (false): ?>
  ... existing markup unchanged ...
<?php endif; ?>
```

Remove the `<span class="sc"><kbd>R</kbd> Riel</span>` hint at `cart.php:1448`.

- [ ] **Step 2: Verify the page still renders**

Run: open `http://localhost/Cafe/cart.php` as `Sokun` with at least one item in the cart.
Expected: the page renders with three payment methods and no JavaScript errors in the console.

- [ ] **Step 3: Commit**

```bash
git add cart.php
git commit -F - <<'EOF'
chore(cart): hide the Riel method on the duplicate checkout page

cart.php is a second checkout surface whose entry link was dropped in fec47c6.
It gets no riel field of its own — building one there invests in a page being
retired. Named consequence: a cashier who still checks out from cart.php cannot
take riel there and should use the menu modal or the counter.

Hidden rather than deleted: riel is still a valid payment_method for 4
historical orders, and keeping it known is what stops a stray riel POST being
coerced to cash while its raw KHR reference is read as dollars.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 8: End-to-end verification

**Files:** none modified — this task produces evidence.

- [ ] **Step 1: Run both helper suites**

Run:
```bash
php tests/tender_test.php
node tests/tender.test.mjs
php tests/purchase_order_test.php
```
Expected: `ALL PASS` from each.

- [ ] **Step 2: Riel-only checkout**

Log in as `Sokun`, add one item to the cart, open the payment modal, choose Cash. Leave the dollar field untouched, type the exact riel equivalent in the `៛` field, and confirm.

Expected: the dollar prefill clears on the first riel keystroke; the change line reads the exact split; the order is created. Then check the stored value:

```bash
php -r 'require "config.php";
$r=$conn->query("SELECT order_id,payment_method,amount,reference FROM order_payments ORDER BY payment_id DESC LIMIT 1");
print_r($r->fetch_assoc());'
```
Expected: `payment_method` = `cash`, `reference` matching `0.00|<khr>`.

- [ ] **Step 3: Mixed tender**

Repeat with, for example, `$1` and `៛8,000` on a bill above $1. Expected `reference` = `1.00|8000`, and the change line shows dollars plus riel.

- [ ] **Step 4: Dollars-only regression**

Repeat with dollars only. Expected `reference` = a bare number such as `5.00`, byte-identical in shape to rows written before this work.

- [ ] **Step 5: Receipt reprint**

Open the receipt PDF for each of the three orders above and for one pre-existing order.
Expected: riel-only prints `Received KHR 5,500`; mixed prints `$1.00 + KHR 8,000`; dollars-only and the pre-existing order print exactly as before.

- [ ] **Step 6: Counter riel settlement**

Create a pay-later order, then settle it from `find_order.php` with riel only.
Expected: settles; `order_payments.reference` reads `0.00|<khr>`; the receipt shows Received and Change lines.

- [ ] **Step 7: Clean up the test orders**

The test orders above are real orders and take real `daily_order_no` values — that is correct and expected for UI-created orders. **Do not delete them by hand and do not INSERT replacements**, which would poison the customer-facing sequence. A test order settled in cash cannot be cancelled (`3a0a794` made `Paid` non-cancellable on purpose); refund it instead.

Run, to confirm the sequence is intact:
```bash
php -r 'require "config.php";
echo (int)$conn->query("SELECT COALESCE(MAX(daily_order_no),0)+1 FROM orders WHERE order_date >= CONCAT(CURDATE(),\" 00:00:00\")")->fetch_row()[0];'
```
Expected: a small number consistent with the day's real order count, not a jump into the thousands.

- [ ] **Step 8: Commit any fixes found**

If steps 2-6 surface defects, fix them with a failing test first and commit each separately. If everything passes, there is nothing to commit for this task.

---

## Self-Review

**Spec coverage:**

| Spec section | Task |
|---|---|
| Tender model + four helpers | 1 |
| Change split, carry, rounding | 1 (PHP), 2 (JS) |
| JS mirror, rate as parameter, pure functions | 2 |
| Checkout modal two-field box | 3 |
| Riel tile hidden (`menu.php`) | 3 |
| Prefill-clear rule | 3, 5 |
| Canonical reference on insert | 4 |
| Counter tender two-field box | 5 |
| Defect 1 — `received === 0` guard, both files | 3 (menu.php), 5 (_cash_tender.php) |
| Defect 2 — `is_numeric` gate in `admin_pay_cash.php` | 5 |
| Readers — `receipt_pdf.php`, `payment_cash.php` | 6 |
| Riel tile hidden (`cart.php`) | 7 |
| Test matrix, browser passes, hazards | 1, 2, 8 |
| Riel stays in `order_payment_methods()`; no migration | Not a change — verified absent from every task |

**Deviation from the spec, deliberate:** the spec placed the shared JS at `assets/js/tender.js`. It goes at the repo root as `tender.js` instead, because `assets/js/menu.js` exists but no PHP file references anything in that directory, while `animations.js` at the root is loaded by six pages including `find_order.php`. Following the pattern that is actually wired up.

**Placeholder scan:** no TBDs, no "add error handling", no "similar to Task N". Every code step carries the code.

**Type consistency:** `tenderRef`/`tender_ref`, `tenderParts`/`tender_parts`, `tenderUsdTotal`/`tender_usd_total`, `tenderChange`/`tender_change` are used consistently. `tender_change()` returns `['usd'=>int,'khr'=>int,'short'=>bool]` in Task 1 and is consumed with those keys in Tasks 3, 5 and 6. The DOM ids `cpCashReceived`, `cpRielCash`, `cpRielCashUsd`, `cpRielQuick`, `cpTenderQuick`, `cpChangeAmount`, `cpShortWarn` are identical across Tasks 3 and 5.

**Note on `tender_ref(0, 5500)`:** returns `'0.00|5500'`, not `'0|5500'`. The spec's prose used `0|5500` informally; the implementation formats the dollar part with `number_format(..., 2)` unconditionally so the shape is uniform. `tender_parts()` reads both, and the regex accepts `0|5500` too, so nothing breaks either way. Tests assert `'0.00|5500'`.
