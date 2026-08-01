# Counter Cash Tender Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the counter Cash button render a tender screen with a change calculator on GET and settle the order only on POST, returning the cashier to the tab they started on.

**Architecture:** `admin_pay_cash.php` currently settles the order as a side effect of being loaded via a link. It splits into two branches in the same file: a GET that renders the order, the amount due and a change calculator copied from `menu.php`'s checkout, and a CSRF-checked POST that runs today's settlement logic verbatim. The return destination stops being a hardcoded binary and becomes the originating tab, carried `_order_card.php` → `admin_pay_cash.php` → `payment_cash.php` and validated at each hop by one shared helper.

**Tech Stack:** PHP 8.2 + mysqli (procedural page scripts, no framework), vanilla JS, Poppins/FontAwesome. No test framework — `tests/*.php` are CLI scripts run with `php tests/<name>.php`.

## Global Constraints

- **The settlement logic is moved verbatim, not edited.** The status branch, the `order_payments` update, the single-method guard and the loyalty award with its `points_earned === 0` check are copied into the POST branch unchanged.
- **Pay-later settles to `Paid`; a `PendingPayment` order settles to `Preparing`.** Paying for a drink is not making it. Do not "fix" this on the way past.
- **The tender amount is never sent to the server.** It is a client-side calculator. The order settles for its full amount regardless of what is typed, because a short tender must never look like a partial payment.
- **No schema changes.** Nothing stores an amount received; `order_payments` has no such column and `menu.php` does not persist one either.
- **Every redirect destination is validated against an allow-list** before it reaches a `Location:` header.
- CSRF pattern used across this codebase: `if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));`
- Escaping helper in scope after `config.php`: `he()`.

---

### Task 1: `pay_return_tab()` — one validated destination helper

The same "where did the cashier come from" decision is made in three files today, each differently. This creates the single answer the other tasks consume.

**Files:**
- Modify: `config.php` (append near `po_may_close_short()`, at the end of the helper block)
- Test: `tests/counter_cash_test.php` (create)

**Interfaces:**
- Produces: `pay_return_tab(?string $raw): string` — returns one of `all`, `pending`, `paylater`, `dashboard`; anything unrecognised, empty or null returns `pending`.
- Produces: `pay_return_url(string $tab): string` — maps a validated tab to a real URL.
- Consumed by Tasks 2, 3 and 4.

- [ ] **Step 1: Write the failing test**

Create `tests/counter_cash_test.php`:

```php
<?php
/**
 * CLI assertions for counter cash settlement.
 * Run:  php tests/counter_cash_test.php
 * There is no test framework in this project; this script is the harness.
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

echo "pay_return_tab\n";
check('all is allowed',        pay_return_tab('all'),       'all');
check('pending is allowed',    pay_return_tab('pending'),   'pending');
check('paylater is allowed',   pay_return_tab('paylater'),  'paylater');
check('dashboard is allowed',  pay_return_tab('dashboard'), 'dashboard');
// An unvalidated value reaching a Location: header is an open redirect.
check('junk falls back',       pay_return_tab('evil.com'),  'pending');
check('empty falls back',      pay_return_tab(''),          'pending');
check('null falls back',       pay_return_tab(null),        'pending');
check('absolute URL falls back', pay_return_tab('https://evil.com'), 'pending');

echo "pay_return_url\n";
check('all URL',       pay_return_url('all'),       'find_order.php?tab=all');
check('pending URL',   pay_return_url('pending'),   'find_order.php?tab=pending');
check('paylater URL',  pay_return_url('paylater'),  'find_order.php?tab=paylater');
check('dashboard URL', pay_return_url('dashboard'), 'dashboard.php');
// Defence in depth: even if an unvalidated string reaches it.
check('junk URL falls back', pay_return_url('evil.com'), 'find_order.php?tab=pending');

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/counter_cash_test.php`
Expected: FAIL — `Call to undefined function pay_return_tab()`

- [ ] **Step 3: Write minimal implementation**

Append to `config.php`, after the `po_may_close_short()` block:

```php
/**
 * Where a cashier returns to after settling an order at the counter.
 *
 * The destination arrives as a query parameter and ends up in a Location:
 * header, so it is validated against a fixed list rather than interpolated.
 * 'pending' is the fallback because it is where the majority of counter
 * settlements start; a wrong-but-safe tab beats an open redirect.
 *
 * 'dashboard' has no caller today. It is kept because dropping it would mean
 * revisiting all three files the first time a dashboard cash button is added.
 */
if (!function_exists('pay_return_tab')) {
    function pay_return_tab(?string $raw): string {
        $allowed = ['all', 'pending', 'paylater', 'dashboard'];
        return in_array($raw, $allowed, true) ? $raw : 'pending';
    }
}

if (!function_exists('pay_return_url')) {
    function pay_return_url(string $tab): string {
        // Re-validate rather than trust the caller: this is the last stop
        // before a Location: header.
        $tab = pay_return_tab($tab);
        return $tab === 'dashboard' ? 'dashboard.php' : 'find_order.php?tab=' . $tab;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/counter_cash_test.php`
Expected: PASS — all 12 checks, `ALL PASS`

- [ ] **Step 5: Commit**

```bash
git add config.php tests/counter_cash_test.php
git commit -m "feat(cash): add validated return-tab helper for counter settlement"
```

---

### Task 2: Teach the success screen about the All Active tab

`payment_cash.php` maps `from` to a back button and knows `pending` and `dashboard` only.

**Pay Later is already handled and must not be added here.** `payment_cash.php:635-638`
short-circuits on `$is_paylater` (derived from `orders.payment_method`) and hardcodes
a `find_order.php?tab=paylater` button *before* `$back_from` is consulted. Because
`admin_pay_cash.php` deliberately never rewrites a pay-later order's method when
settling it, that branch still fires after settlement and the button is already
correct. Adding a `'paylater'` entry to `$back_targets` would be unreachable code.

That leaves `all` as the only genuinely missing destination.

**Files:**
- Modify: `payment_cash.php:53-58`
- Test: `tests/counter_cash_test.php` (extend)

**Interfaces:**
- Consumes: nothing (`$back_targets` is a local array).
- Produces: `payment_cash.php?order_id=N&from=<tab>` renders a correct back button for all four tabs.

- [ ] **Step 1: Write the failing test**

Append to `tests/counter_cash_test.php`, before the final `echo`:

```php
echo "payment_cash back targets\n";
// The map is a literal in the page, so assert on the source rather than booting
// the page (it requires a session and a real order). Assert on strings that are
// genuinely absent today: "'paylater'" and "find_order.php?tab=paylater" already
// appear in this file for unrelated reasons and would pass before the change.
$pc = file_get_contents(__DIR__ . '/../payment_cash.php');
check('back target: pending kept',   strpos($pc, "'pending'   =>") !== false, true);
check('back target: dashboard kept', strpos($pc, "'dashboard' =>") !== false, true);
check('back target: all added',      strpos($pc, "'all'       =>") !== false, true);
check('all active back label',       strpos($pc, 'Back to Active Orders') !== false, true);
// Pay Later is served by the $is_paylater short-circuit at :635, not this map.
// An entry here would be unreachable.
check('no unreachable paylater entry', strpos($pc, "'paylater'  =>") === false, true);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/counter_cash_test.php`
Expected: FAIL on `back target: all added` and `all active back label`. The other
three pass already and are regression guards.

- [ ] **Step 3: Write minimal implementation**

Replace the `$back_targets` array at `payment_cash.php:54-57`. Note the alignment —
the test asserts on `'all'       =>` with that spacing:

```php
$back_targets = [
    'pending'   => ['find_order.php?tab=pending', 'Back to Pending Payment', 'fa-arrow-left'],
    'all'       => ['find_order.php?tab=all',     'Back to Active Orders',   'fa-arrow-left'],
    'dashboard' => ['dashboard.php',              'Back to Dashboard',       'fa-arrow-left'],
];
```

Update the comment block above it (`payment_cash.php:47-52`):

```php
/* Where the cashier came from decides where "back" goes. Settling a Pending Payment
   order from Find Orders and then being dropped at the menu loses the queue they were
   working; only a fresh menu checkout should offer New Order.
     from=pending    → collected from Find Orders → Pending Payment (cash or Bakong)
     from=all        → collected from Find Orders → All Active
     from=dashboard  → collected via the dashboard shortcut
     (absent)        → a fresh order taken at the menu
   No 'paylater' entry: a settled pay-later order keeps payment_method='paylater',
   so the $is_paylater branch below wins before this map is read. */
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/counter_cash_test.php`
Expected: PASS — `ALL PASS`

- [ ] **Step 5: Commit**

```bash
git add payment_cash.php tests/counter_cash_test.php
git commit -m "feat(cash): route the success screen back to Pay Later and All Active"
```

---

### Task 3: Split `admin_pay_cash.php` into GET renders / POST settles

The core change. Today the file settles the order at load time; a refresh, a back-button or a link prefetch spends money.

**Files:**
- Modify: `admin_pay_cash.php` (whole file)
- Test: manual curl E2E, commands given below

**Interfaces:**
- Consumes: `pay_return_tab()`, `pay_return_url()` from Task 1; `from=<tab>` support from Task 2.
- Produces: `admin_pay_cash.php?order_id=N&return=<tab>` renders a tender form. `POST` to the same URL with `csrf_token` and `return` settles.

- [ ] **Step 1: Guard the settlement behind POST**

Replace lines 9-15 of `admin_pay_cash.php` (the `$order_id` / `$return_page` block) with:

```php
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$order_id    = (int)($_GET['order_id'] ?? 0);
$return_tab  = pay_return_tab($_POST['return'] ?? $_GET['return'] ?? null);
$return_page = pay_return_url($return_tab);

if ($order_id <= 0) {
    header("Location: $return_page");
    exit;
}

// Settling spends money, so it needs a submit. As a GET this page charged the
// customer while it loaded: a refresh, a back-button or a browser link prefetch
// was indistinguishable from a deliberate click.
$is_settle = ($_SERVER['REQUEST_METHOD'] === 'POST');
if ($is_settle && !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    header("Location: $return_page");
    exit;
}
```

- [ ] **Step 2: Wrap the existing settlement block**

The order fetch at lines 17-30 stays where it is — both branches need it.

Wrap everything from `$conn->begin_transaction();` to the final
`header("Location: payment_cash.php...")` / `exit;` in:

```php
if ($is_settle) {
    // ... existing settlement code, entirely unchanged ...
}
```

Then replace the final redirect at the end of that block (today's lines 123-127) with:

```php
    // Show the same success screen as the regular checkout (identical UI). Carry
    // where the cashier came from so its back button returns to the queue.
    header("Location: payment_cash.php?order_id=" . $order_id . "&from=" . $return_tab);
    exit;
}
```

Do not change anything between `begin_transaction()` and the loyalty block. The
`catch` at today's line 117 keeps its `header("Location: $return_page"); exit;`.

- [ ] **Step 3: Verify the GET no longer settles**

Find a pay-later order and confirm it stays unpaid across two GETs:

```bash
rm -f /tmp/ck.txt
curl -sk -c /tmp/ck.txt -b /tmp/ck.txt -X POST "https://localhost/Cafe/login.php" \
  -d "username=Sokun&password=%40Sokun9811" -o /dev/null

ORDER=$(/c/xampp/mysql/bin/mysql.exe -u root db_coffee -N -e \
  "SELECT order_id FROM orders WHERE payment_method='paylater' AND is_open=1 LIMIT 1")
echo "testing order $ORDER"

curl -sk -b /tmp/ck.txt "https://localhost/Cafe/admin_pay_cash.php?order_id=$ORDER&return=paylater" -o /dev/null
curl -sk -b /tmp/ck.txt "https://localhost/Cafe/admin_pay_cash.php?order_id=$ORDER&return=paylater" -o /dev/null

/c/xampp/mysql/bin/mysql.exe -u root db_coffee -e \
  "SELECT order_id, status, is_open, payment_method FROM orders WHERE order_id=$ORDER;"
```

Expected: `is_open` still `1`, `status` unchanged, `payment_method` still `paylater`. Two GETs changed nothing.

- [ ] **Step 4: Build the tender screen**

Below the settlement block, render the GET view. Uses `menu.php`'s classes so a
cashier who has used checkout recognises this screen. Copy `.cp-change-calc`,
`.cp-tender-quick` and `.cp-tender-btn` rules from `menu.php:497-503` into this
page's `<style>`.

```php
$owed = (float)$order['total'];
?>
<form method="POST" action="admin_pay_cash.php?order_id=<?= $order_id ?>" id="tenderForm">
  <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
  <input type="hidden" name="return" value="<?= he($return_tab) ?>">

  <div class="amount-due">
    <span>Total due</span>
    <strong>$<?= number_format($owed, 2) ?></strong>
  </div>

  <div class="cp-change-calc">
    <label><i class="fa-solid fa-money-bill-wave" style="color:#55e087;margin-right:4px;"></i> Amount Received</label>
    <!-- oninput only fires for real typing, never for a programmatic .value set,
         so this flag reliably marks "the cashier has entered their own amount". -->
    <input type="number" id="cpCashReceived" step="0.01" min="0" placeholder="0.00"
           oninput="this.dataset.touched='1'; cpCalcChange(); cpMarkActiveTender(this.value)"
           onfocus="this.select()">
    <div class="cp-tender-quick" id="cpTenderQuick"></div>
    <div class="cp-change-row">
      <span class="change-label">Change to give back</span>
      <span class="change-amount" id="cpChangeAmount">$0.00</span>
    </div>
  </div>

  <button type="submit" class="btn-confirm">
    <i class="fa-solid fa-money-bill-wave"></i> Confirm Cash Payment
  </button>
  <a href="<?= he($return_page) ?>" class="btn-cancel">Cancel</a>
</form>
```

The amount received is deliberately **not** a form field with a `name` — it is
never submitted. The order settles for `$owed` either way.

- [ ] **Step 5: Add the tender JavaScript**

Ported from `menu.php:1828-1900` and `:2179-2188`, with `cpOwedInCash()` reduced to
a constant — this page settles one order and has no cart or split tender.

```html
<script>
const CP_OWED = <?= json_encode(round($owed, 2)) ?>;
function cpOwedInCash() { return CP_OWED; }

function cpCalcChange() {
  var received = parseFloat(document.getElementById('cpCashReceived')?.value) || 0;
  var change   = received - cpOwedInCash();
  var el       = document.getElementById('cpChangeAmount');
  if (!el) return;
  if (received === 0) { el.textContent = '$0.00'; el.className = 'change-amount'; return; }
  if (change < 0) { el.textContent = 'Need $' + Math.abs(change).toFixed(2) + ' more'; el.className = 'change-amount not-enough'; }
  else            { el.textContent = '$' + change.toFixed(2); el.className = 'change-amount'; }
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
    b.type = 'button';                       // never submits the form
    b.className = 'cp-tender-btn';
    b.textContent = label;
    b.dataset.tender = val.toFixed(2);
    b.addEventListener('click', function () { cpSetTender(val); });
    return b;
  };

  wrap.appendChild(mk('Exact', owed));
  // Only notes that actually cover the bill — a $5 button on a $23 order just
  // produces "Need $18 more".
  [1, 5, 10, 20, 50, 100].filter(function (d) { return d > owed; })
                         .slice(0, 4)
                         .forEach(function (d) { wrap.appendChild(mk('$' + d, d)); });

  cpMarkActiveTender(parseFloat(document.getElementById('cpCashReceived').value) || 0);
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

/* Seed with what is owed so exact cash is one tap; a customer handing over a note
   just overtypes it. Only ever prefills an untouched field. */
(function () {
  var cr = document.getElementById('cpCashReceived');
  if (cr && cr.dataset.touched !== '1' && CP_OWED > 0) {
    cr.value = CP_OWED.toFixed(2);
  }
  cpCalcChange();
  cpRenderTenderQuick();
})();
</script>
```

`type="button"` on the tender buttons is load-bearing: a bare `<button>` inside a
form defaults to `type="submit"` and would settle the order on the first tap.

- [ ] **Step 6: Verify POST settles, and that CSRF is enforced**

```bash
ORDER=$(/c/xampp/mysql/bin/mysql.exe -u root db_coffee -N -e \
  "SELECT order_id FROM orders WHERE payment_method='paylater' AND is_open=1 LIMIT 1")

# No token → refused
curl -sk -b /tmp/ck.txt -X POST "https://localhost/Cafe/admin_pay_cash.php?order_id=$ORDER" \
  -d "return=paylater" -o /dev/null -w "no-token:%{http_code}\n"
/c/xampp/mysql/bin/mysql.exe -u root db_coffee -e "SELECT is_open FROM orders WHERE order_id=$ORDER;"

# With the token from the rendered form → settles
TOKEN=$(curl -sk -b /tmp/ck.txt "https://localhost/Cafe/admin_pay_cash.php?order_id=$ORDER&return=paylater" \
  | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -sk -b /tmp/ck.txt -X POST "https://localhost/Cafe/admin_pay_cash.php?order_id=$ORDER" \
  -d "csrf_token=$TOKEN&return=paylater" -o /dev/null -D - | grep -i "^location:"
/c/xampp/mysql/bin/mysql.exe -u root db_coffee -e \
  "SELECT order_id, status, is_open, payment_method, points_earned FROM orders WHERE order_id=$ORDER;"
```

Expected: after the tokenless POST `is_open` is still `1`. After the valid POST the
`Location:` header reads `payment_cash.php?order_id=<N>&from=paylater`, `status` is
`Paid`, `is_open` is `0`, `payment_method` is still `paylater` (the pay-later
exclusion), and `points_earned` is set.

- [ ] **Step 7: Commit**

```bash
git add admin_pay_cash.php
git commit -m "feat(cash): tender screen on GET, settle only on POST"
```

---

### Task 4: Carry the originating tab from the order card

Without this, every settlement still reports itself as coming from `pending`.

**Files:**
- Modify: `_order_card.php:97-105`
- Modify: `find_order.php:148` and `find_order.php:592` (include sites)
- Test: manual, commands below

**Interfaces:**
- Consumes: `pay_return_tab()` from Task 1; the `return` parameter from Task 3.
- Produces: nothing downstream.

- [ ] **Step 1: Expose the current tab to the card**

`find_order.php:9` already computes `$poll_tab = $_GET['tab'] ?? 'all';`. Add a
validated copy near it so the card and the poller cannot disagree:

```php
// The tab the cashier is looking at, carried into the settle links so they come
// back to it instead of always landing on Pending Payment.
$card_tab = pay_return_tab($_GET['tab'] ?? 'all');
```

Both `include '_order_card.php'` sites (`:148`, `:592`) are in the same file scope,
so `$card_tab` is already visible to them. No change to the include lines
themselves.

- [ ] **Step 2: Append the tab to both settle links**

In `_order_card.php`, replace lines 97-105:

```php
            <?php $ret = '&return=' . urlencode($card_tab ?? 'pending'); ?>
            <a href="admin_pay_cash.php?order_id=<?= $order['order_id'] ?><?= $ret ?>"
               class="btn btn-pay-cash"
               <?= $isPL ? 'data-lp-order="'.$order['order_id'].'" data-lp-dest="admin_pay_cash.php?order_id='.$order['order_id'].$ret.'" onclick="return interceptPayLater(event,this)"' : '' ?>>
                <i class="fa-solid fa-money-bill-wave"></i> Cash
            </a>
            <a href="admin_pay_bakong.php?order_id=<?= $order['order_id'] ?><?= $ret ?>"
               class="btn btn-pay-bakong"
               <?= $isPL ? 'data-lp-order="'.$order['order_id'].'" data-lp-dest="admin_pay_bakong.php?order_id='.$order['order_id'].$ret.'" onclick="return interceptPayLater(event,this)"' : '' ?>>
                <i class="fa-solid fa-qrcode"></i> Bakong
```

`$card_tab ?? 'pending'` matters: `_order_card.php` is an include, and a future
caller that does not define the variable gets today's behaviour rather than a
notice. `data-lp-dest` must carry the same string as `href` or the loyalty
intercept sends the cashier somewhere the link did not point.

- [ ] **Step 3: Verify each tab returns to itself**

Log in as `Sokun` and, in the browser, for each of `?tab=all`, `?tab=pending`,
`?tab=paylater` on `find_order.php`:

1. Confirm the Cash button's URL ends with `&return=<that tab>` (hover / inspect).
2. Click it, confirm the tender screen renders and the order is still unpaid.
3. Click Cancel, confirm you land back on the same tab.
4. Repeat, click Confirm Cash Payment, confirm the success screen's back button
   reads "Back to Active Orders" / "Back to Pending Payment" respectively. From the
   Pay Later tab it reads "Back to Pay Later" — via the `$is_paylater`
   short-circuit, not via `from`, so it reads that way for a pay-later order
   settled from *any* tab. That is pre-existing and correct enough: the tab is
   where those orders live.

- [ ] **Step 4: Run the full test script**

Run: `php tests/counter_cash_test.php`
Expected: `ALL PASS`

- [ ] **Step 5: Commit**

```bash
git add _order_card.php find_order.php
git commit -m "feat(cash): return the cashier to the tab they settled from"
```

---

## Self-review notes

- **Spec coverage.** §1 GET/POST split → Task 3 steps 1-2. §2 tender screen → Task 3 steps 4-5. §3 return destination → Tasks 1, 2, 4. §4 Cancel → Task 3 step 4. The spec's "third defect" (unreachable `return=dashboard`, everything landing on `pending`) → Tasks 1 and 4.
- **Not covered by an automated test:** the POST-replay case from the spec's test table. `admin_pay_cash.php` re-reads the order before settling and the loyalty award is guarded by `points_earned === 0`, so a replay cannot double-award — but this plan does not add a regression test for it, because doing so means driving two sequential POSTs against a real order and asserting the loyalty row count. Verify it manually with the Task 3 step 6 commands run twice; expect the second POST to leave `points_earned` unchanged.
- **`admin_pay_bakong.php` gains a `return` parameter it does not read.** Harmless — it computes its own destination — and Task 4 passes it so the two links stay symmetrical for whoever wires Bakong up next.
