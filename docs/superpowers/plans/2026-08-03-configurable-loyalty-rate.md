# Configurable Loyalty Earn Rate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an admin set the loyalty earn rate as a ratio — X points per Y drinks — in `settings.php`, with the remainder carried on the card so a fractional rate still rewards single-drink customers.

**Architecture:** One shared writer, `loyalty_sync()`, replaces four divergent award sites. It reconciles an order's loyalty effect against what that order already recorded, so a first award, an add-to-order top-up and a full reversal are the same call with a different quantity. Two new columns hold the carry (`loyalty_cards.points_progress`) and the quantity that earned (`orders.points_qty`).

**Tech Stack:** PHP 8 + mysqli (no framework), MariaDB 10.4.32 via XAMPP. Tests are plain CLI scripts run with `php tests/<name>.php`. There is no test framework in this project.

## Global Constraints

- **Two settings**, in the existing key/value `settings` table: `loyalty_points_per` (X, default **1**) and `loyalty_points_drinks` (Y, default **1**). Defaults reproduce today's behaviour exactly.
- **Both clamp to `max(1, ...)`** at constant-definition time AND on read inside the helper. Zero on the left awards nothing forever; zero on the right divides by zero.
- **The arithmetic:** `numerator = progress + qty × X`; `points = intdiv(numerator, Y)`; `progress = numerator % Y`.
- **`qty` is earning drinks only** — `order_items.earns_points = 1` AND `price > 0` AND `product_id != 0`. Gift lines and merch never earn.
- **The invariant `0 ≤ progress < Y`** is clamped and `error_log()`ged if a clamp fires.
- **Card merges:** `loyalty_sync()` resolves `card_id` through `merged_into` before writing. `merge_loyalty_cards.php` must also carry `points_progress` to the target and zero the source.
- **No backfill.** Both columns default to 0, already correct for all 18 live cards at 1:1.
- **Never use `git commit -i`.** Use `git commit -F -` with a heredoc.
- **`$points_qty` (confirm_order.php:131, add-to-order branch) and `$point_qty` (confirm_order.php:476, new-order branch) are two different variables in two different branches.** Confusingly named, both correct. Do not merge or rename them.

---

## File Structure

| File | Responsibility |
|---|---|
| `config.php` | Constants, migration, `loyalty_earning_qty()`, `loyalty_sync()` |
| `tests/loyalty_test.php` (new) | Arithmetic + round-trip + merge assertions |
| `confirm_order.php` | Both award sites call the helper |
| `admin_pay_cash.php` | Pay-later counter site — also fixes merch/gift counting |
| `check_payment.php` | Pay-later Bakong site — same fix |
| `cancel_order.php` | Reversal calls the helper with qty 0 |
| `merge_loyalty_cards.php` | Carry `points_progress` to the target |
| `settings.php` | New `loyalty` section |
| `menu.php` | Hide Add Discount (independent, Task 6) |

---

## Task 1: The shared writer

**Files:**
- Modify: `config.php` — add after `po_short_reason_label()`
- Test: `tests/loyalty_test.php` (create)

**Interfaces:**
- Produces:
  - `LOYALTY_POINTS_PER`, `LOYALTY_POINTS_DRINKS` (constants)
  - `loyalty_earning_qty(mysqli $conn, int $order_id): int`
  - `loyalty_sync(mysqli $conn, int $card_id, int $order_id, int $qty_total, string $note): int` — returns the net points change

- [ ] **Step 1: Write the failing test**

Create `tests/loyalty_test.php`:

```php
<?php
/**
 * CLI assertions for the configurable loyalty earn rate.
 * Run:  php tests/loyalty_test.php
 * There is no test framework in this project; this script is the harness.
 * Everything that writes runs inside a transaction rolled back in a finally,
 * because these tests touch real loyalty cards.
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

echo "schema\n";
check('loyalty_cards.points_progress exists',
      $conn->query("SHOW COLUMNS FROM loyalty_cards LIKE 'points_progress'")->num_rows === 1, true);
check('orders.points_qty exists',
      $conn->query("SHOW COLUMNS FROM orders LIKE 'points_qty'")->num_rows === 1, true);

echo "settings clamp\n";
// A zero on either side is not a rate anyone means to type: zero points awards
// nothing forever, zero drinks divides by zero. Clamped where the constants are
// defined, so a direct database edit cannot break the till.
check('points per clamps to at least 1',    max(1, (int)'0'), 1);
check('drinks per clamps to at least 1',    max(1, (int)'0'), 1);
check('LOYALTY_POINTS_PER is at least 1',    LOYALTY_POINTS_PER >= 1,    true);
check('LOYALTY_POINTS_DRINKS is at least 1', LOYALTY_POINTS_DRINKS >= 1, true);

echo "award arithmetic\n";
// Pure: numerator = progress + qty*X; points = intdiv(numerator, Y);
// progress = numerator % Y. Asserted directly so the expected values are
// derivable by hand rather than restating the implementation.
$calc = function (int $progress, int $qty, int $x, int $y): array {
    $n = $progress + $qty * $x;
    return ['points' => intdiv($n, $y), 'progress' => $n % $y];
};
check('1 per 1, 3 drinks is todays behaviour', $calc(0, 3, 1, 1), ['points'=>3, 'progress'=>0]);
check('1 per 2, first single drink earns nothing yet', $calc(0, 1, 1, 2), ['points'=>0, 'progress'=>1]);
// The case that makes carry-forward worth having: the single-drink regular.
check('1 per 2, second single drink completes the pair', $calc(1, 1, 1, 2), ['points'=>1, 'progress'=>0]);
check('1 per 2, 3 drinks on progress 1',      $calc(1, 3, 1, 2), ['points'=>2, 'progress'=>0]);
check('2 per 1, one drink earns two',         $calc(0, 1, 2, 1), ['points'=>2, 'progress'=>0]);
check('1 per 3, 7 drinks',                    $calc(0, 7, 1, 3), ['points'=>2, 'progress'=>1]);
check('no drinks changes nothing',            $calc(1, 0, 1, 2), ['points'=>0, 'progress'=>1]);

echo "database round-trip\n";
$conn->begin_transaction();
try {
    $conn->query("INSERT INTO loyalty_cards (loyalty_id, points, total_orders, total_drinks, is_active)
                  VALUES ('TEST-CARD-A', 0, 0, 0, 1)");
    $cardA = (int)$conn->insert_id;
    $conn->query("INSERT INTO orders (customer_name, total, status, is_open, payment_method, business_date, daily_order_no, loyalty_card_id)
                  VALUES ('loyalty-test', 5.00, 'Paid', 0, 'cash', '2020-01-01', 1, $cardA)");
    $ord = (int)$conn->insert_id;

    $cardPoints = function (int $id) use ($conn): array {
        $r = $conn->query("SELECT points, points_progress, total_orders, total_drinks
                           FROM loyalty_cards WHERE card_id = $id")->fetch_assoc();
        return ['points'=>(int)$r['points'], 'progress'=>(int)$r['points_progress'],
                'orders'=>(int)$r['total_orders'], 'drinks'=>(int)$r['total_drinks']];
    };

    // Seed a card mid-way to its next point so the round-trip has something to
    // restore. A test that starts from zero cannot tell "restored" from "reset".
    $conn->query("UPDATE loyalty_cards SET points_progress = 1 WHERE card_id = $cardA");
    $before = $cardPoints($cardA);

    loyalty_sync($conn, $cardA, $ord, 3, 'test award');
    $after = $cardPoints($cardA);
    check('an award moves points',           $after['points'] > $before['points'], true);
    check('an award records the quantity',
          (int)$conn->query("SELECT points_qty FROM orders WHERE order_id=$ord")->fetch_row()[0], 3);

    // ADD-TO-ORDER. The helper takes the order's TOTAL earning quantity, not a
    // delta, so a top-up is the same call with a larger number. This is the path
    // orders.points_qty exists for: get it wrong and the later refund reverses
    // the wrong amount.
    loyalty_sync($conn, $cardA, $ord, 4, 'test add-to-order');
    check('add-to-order stores the combined quantity',
          (int)$conn->query("SELECT points_qty FROM orders WHERE order_id=$ord")->fetch_row()[0], 4);
    check('add-to-order does not double the drink counter',
          $cardPoints($cardA)['drinks'] - $before['drinks'], 4);
    check('add-to-order counts one order, not two',
          $cardPoints($cardA)['orders'] - $before['orders'], 1);

    // FULL REVERSAL is the same call with zero drinks. This is the property that
    // makes refunds safe, and it is asserted rather than hand-checked.
    loyalty_sync($conn, $cardA, $ord, 0, 'test reversal');
    check('reversal restores points exactly',   $cardPoints($cardA)['points'],   $before['points']);
    check('reversal restores progress exactly', $cardPoints($cardA)['progress'], $before['progress']);
    check('reversal restores the drink counter',$cardPoints($cardA)['drinks'],   $before['drinks']);
    check('reversal clears the recorded quantity',
          (int)$conn->query("SELECT points_qty FROM orders WHERE order_id=$ord")->fetch_row()[0], 0);

    // The invariant, checked after real writes rather than only in the pure block.
    $prog = (int)$conn->query("SELECT points_progress FROM loyalty_cards WHERE card_id=$cardA")->fetch_row()[0];
    check('progress stays within 0..Y-1', $prog >= 0 && $prog < LOYALTY_POINTS_DRINKS, true);

    // MERGE. The source card is deactivated, never deleted, and orders still
    // reference it — so a sync after a merge must land on the target.
    $conn->query("INSERT INTO loyalty_cards (loyalty_id, points, total_orders, total_drinks, is_active)
                  VALUES ('TEST-CARD-B', 0, 0, 0, 1)");
    $cardB = (int)$conn->insert_id;
    $conn->query("UPDATE loyalty_cards SET is_active = 0, merged_into = $cardB WHERE card_id = $cardA");
    $bBefore = $cardPoints($cardB);
    loyalty_sync($conn, $cardA, $ord, 2, 'test after merge');
    check('a sync after a merge credits the target card',
          $cardPoints($cardB)['points'] > $bBefore['points'], true);
    check('a sync after a merge leaves the source alone',
          $cardPoints($cardA)['points'], $before['points']);
} finally {
    // Undoes the scratch cards, the scratch order and every points movement in
    // one shot. Runs even if an assertion above threw.
    $conn->rollback();
}

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/loyalty_test.php`
Expected: FAIL on the schema checks, then `Fatal error: Uncaught Error: Call to undefined function loyalty_sync()`

- [ ] **Step 3: Add the constants**

In `config.php`, beside the other setting-backed constants (after the `PAYLATER_FOLLOWUP_MINUTES` line, around line 64):

```php
// Loyalty earn rate, expressed as a ratio: X points per Y drinks. Both clamp to
// a minimum of 1 — zero points awards nothing forever, zero drinks divides by
// zero, and neither is a rate anyone means to type. The defaults reproduce the
// old hardcoded behaviour exactly: one point per drink.
if (!defined('LOYALTY_POINTS_PER'))    define('LOYALTY_POINTS_PER',    max(1, (int)($_cafe_settings['loyalty_points_per']    ?? 1)));
if (!defined('LOYALTY_POINTS_DRINKS')) define('LOYALTY_POINTS_DRINKS', max(1, (int)($_cafe_settings['loyalty_points_drinks'] ?? 1)));
```

- [ ] **Step 4: Add the migration**

In `config.php`, with the other `_migrate()` calls:

```php
// points_progress is the carry toward the next point, always 0..Y-1. points_qty
// records how many earning drinks an order counted — stored rather than
// recomputed because order_items can change afterwards through add-to-order, so
// the quantity that actually earned is not recoverable at refund time.
// Both default to 0, which is already correct for every existing card and order
// at the 1:1 default rate, so no backfill is needed.
_migrate($conn, 'loyalty_progress_v1', function($db) {
    $db->query("ALTER TABLE loyalty_cards ADD COLUMN IF NOT EXISTS points_progress INT NOT NULL DEFAULT 0");
    $db->query("ALTER TABLE orders        ADD COLUMN IF NOT EXISTS points_qty      INT NOT NULL DEFAULT 0");
});
```

- [ ] **Step 5: Write the helpers**

In `config.php`, after `po_short_reason_label()`:

```php
/**
 * How many drinks on an order actually earn loyalty points.
 *
 * Earning lines only: a category flagged earns_points = 0 (merch) never earns,
 * a zero-priced gift line never earns, and product_id = 0 is the only reliable
 * gift test — the "[GIFT] " name prefix misses six older rows, and price = 0
 * also matches a buy-X-get-1-free promo drink, which IS a real cup.
 *
 * This exists because two of the four award sites did not filter at all:
 * admin_pay_cash.php and check_payment.php counted SUM(quantity), so a
 * pay-later order awarded points for a T-shirt and for the free gift drink
 * itself, while the same basket paid up front awarded neither.
 */
if (!function_exists('loyalty_earning_qty')) {
    function loyalty_earning_qty(mysqli $conn, int $order_id): int {
        $q = $conn->prepare("SELECT COALESCE(SUM(quantity), 0)
                             FROM order_items
                             WHERE order_id = ? AND earns_points = 1
                               AND price > 0 AND product_id <> 0");
        $q->bind_param('i', $order_id);
        $q->execute();
        return (int)($q->get_result()->fetch_row()[0] ?? 0);
    }
}

/**
 * Reconcile one order's loyalty effect to a given earning quantity.
 *
 * ONE writer for every loyalty movement, because the four sites that used to do
 * this each did it slightly differently. Takes the order's TOTAL earning
 * quantity, never a delta, and works out the difference itself against what the
 * order already recorded. That makes three cases one call:
 *
 *   first award      qty_total = the order's earning drinks
 *   add-to-order     qty_total = the NEW combined total
 *   full reversal    qty_total = 0
 *
 * The reversal is exact rather than approximate. progress_before is recovered
 * from what the order stored:
 *     progress_before = progress_now + (points_earned x Y) - (qty_recorded x X)
 * which is exact because points_earned = intdiv(progress_before + qty x X, Y)
 * and progress_now = (progress_before + qty x X) % Y. So award-then-reverse
 * returns the card to precisely where it started, which is what makes a refund
 * safe for a customer who was part-way to their next point.
 *
 * Card merges: the source card is deactivated, NOT deleted, and
 * orders.loyalty_card_id keeps pointing at it so the audit trail survives. Its
 * points have already moved to the target, so writing here would credit a card
 * nobody can spend from. merged_into is followed to the end of the chain first.
 *
 * Returns the net points change, positive or negative.
 */
if (!function_exists('loyalty_sync')) {
    function loyalty_sync(mysqli $conn, int $card_id, int $order_id, int $qty_total, string $note): int {
        if ($card_id <= 0 || $order_id <= 0) { return 0; }
        $x = max(1, (int)LOYALTY_POINTS_PER);
        $y = max(1, (int)LOYALTY_POINTS_DRINKS);
        $qty_total = max(0, $qty_total);

        // Follow a merge chain to the card that actually holds the balance.
        // Bounded rather than while(true): a cycle in merged_into would hang the
        // till, and ten hops is far beyond any real merge history.
        $seen = 0;
        while ($seen++ < 10) {
            $m = $conn->prepare("SELECT merged_into FROM loyalty_cards WHERE card_id = ?");
            $m->bind_param('i', $card_id);
            $m->execute();
            $row = $m->get_result()->fetch_row();
            if (!$row) { return 0; }                       // card gone entirely
            $next = (int)($row[0] ?? 0);
            if ($next <= 0 || $next === $card_id) { break; }
            $card_id = $next;
        }

        $c = $conn->prepare("SELECT points_progress FROM loyalty_cards WHERE card_id = ?");
        $c->bind_param('i', $card_id);
        $c->execute();
        $crow = $c->get_result()->fetch_row();
        if (!$crow) { return 0; }
        $progress_now = (int)$crow[0];

        $o = $conn->prepare("SELECT points_earned, points_qty FROM orders WHERE order_id = ?");
        $o->bind_param('i', $order_id);
        $o->execute();
        $orow = $o->get_result()->fetch_assoc();
        if (!$orow) { return 0; }
        $points_old = (int)($orow['points_earned'] ?? 0);
        $qty_old    = (int)($orow['points_qty']    ?? 0);

        // Undo this order's contribution, then apply the new one.
        $progress_before = $progress_now + ($points_old * $y) - ($qty_old * $x);
        $numerator       = $progress_before + ($qty_total * $x);
        $points_new      = intdiv(max(0, $numerator), $y);
        $progress_new    = max(0, $numerator) % $y;

        // Symptom guard, not a fix. If this ever fires the arithmetic above is
        // wrong, and the log is the only thing that will say so before a
        // customer notices their points are off.
        if ($numerator < 0 || $progress_new < 0 || $progress_new >= $y) {
            error_log("loyalty_sync: progress out of range for card $card_id order $order_id "
                    . "(numerator $numerator, progress $progress_new, Y $y)");
            $progress_new = max(0, min($y - 1, $progress_new));
        }

        $delta_points = $points_new - $points_old;
        $delta_drinks = $qty_total - $qty_old;
        // An order counts once toward total_orders while it has earning drinks.
        $delta_orders = ($qty_total > 0 ? 1 : 0) - ($qty_old > 0 ? 1 : 0);

        $u = $conn->prepare("UPDATE loyalty_cards
                                SET points          = GREATEST(0, points + ?),
                                    points_progress = ?,
                                    total_orders    = GREATEST(0, total_orders + ?),
                                    total_drinks    = GREATEST(0, total_drinks + ?),
                                    last_used       = NOW()
                              WHERE card_id = ?");
        $u->bind_param('iiiii', $delta_points, $progress_new, $delta_orders, $delta_drinks, $card_id);
        $u->execute();

        // History records movement only. A sync that changes progress but awards
        // nothing is real and correct, and a zero-point row every time would bury
        // the entries a human actually reads.
        if ($delta_points !== 0) {
            $type = $delta_points > 0 ? 'earned' : 'adjusted_deduct';
            // 'earned' is hardcoded in SQL rather than bound so ENUM validation
            // happens at execute(); fall back for schemas without that member.
            $h = $conn->prepare("INSERT INTO loyalty_history (card_id, order_id, points_change, type, description)
                                 VALUES (?, ?, ?, '$type', ?)");
            if (!$h) {
                $alt = $delta_points > 0 ? 'adjusted_add' : 'adjusted_deduct';
                $h = $conn->prepare("INSERT INTO loyalty_history (card_id, order_id, points_change, type, description)
                                     VALUES (?, ?, ?, '$alt', ?)");
            }
            if ($h) { $h->bind_param('iiis', $card_id, $order_id, $delta_points, $note); $h->execute(); }
        }

        $so = $conn->prepare("UPDATE orders SET points_earned = ?, points_qty = ? WHERE order_id = ?");
        $so->bind_param('iii', $points_new, $qty_total, $order_id);
        $so->execute();

        return $delta_points;
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php tests/loyalty_test.php`
Expected: `ALL PASS`

- [ ] **Step 7: Confirm nothing else broke**

Run: `php tests/purchase_order_test.php && php tests/tender_test.php && php tests/counter_cash_test.php && php tests/daily_report_test.php && php tests/remake_test.php`
Expected: `ALL PASS` from each.

- [ ] **Step 8: Commit**

```bash
git add config.php tests/loyalty_test.php
git commit -F - <<'EOF'
feat(loyalty): one writer for every points movement, with a configurable rate

The earn rate was hardcoded at one point per drink in four different files, so
an admin who wanted one point per two drinks had nowhere to say it. It is now a
ratio, X points per Y drinks, defaulting to 1 and 1 — today's behaviour exactly.

The remainder carries on the card. Flooring per order would silently break the
most common sale: at one point per two drinks a customer buying one drink per
visit floors to zero every time and never earns anything.

loyalty_sync() takes an order's TOTAL earning quantity rather than a delta and
works out the difference itself, which makes a first award, an add-to-order
top-up and a full reversal the same call. The reversal is exact — progress_before
is recovered algebraically from what the order stored — so refunding an order
returns a customer to precisely the progress they had, rather than rounding it
away in the shop's favour.

Merges are followed: the source card is deactivated rather than deleted and
orders keep pointing at it, so writing without resolving merged_into would credit
a card nobody can spend from.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 2: Wire `confirm_order.php`

**Files:**
- Modify: `confirm_order.php:174-194` (add-to-order) and `confirm_order.php:572-600` (new order)

**Interfaces:**
- Consumes: `loyalty_sync()` from Task 1

- [ ] **Step 1: Replace the add-to-order block**

Replace the body of the `if ($lc_id > 0) {` block at `confirm_order.php:176-194` with:

```php
        if ($lc_id > 0) {
            // $points_qty here is the COMBINED earning quantity of the existing
            // order plus the items being added — computed at line 131 in this
            // branch. loyalty_sync takes that total and works out the delta
            // itself against what the order already recorded.
            //
            // NOTE: this branch's $points_qty is a different variable from the
            // new-order branch's $point_qty further down. Both are correct.
            loyalty_sync($conn, $lc_id, $existing_order_id, $points_qty,
                         "Order #{$existing_order_id} items added — points adjusted");
        }
```

- [ ] **Step 2: Replace the new-order award block**

Replace `confirm_order.php:572-600` — the whole `// ── LOYALTY POINTS ──` block through the end of its `if ($loyalty_card_id > 0)` — with:

```php
    // ── LOYALTY POINTS ──
    // Rate and carry-forward both live in loyalty_sync(); this site only says
    // which card, which order, and how many earning drinks.
    $loyalty_card_id = isset($_SESSION['loyalty_card_id']) ? (int)$_SESSION['loyalty_card_id'] : 0;
    if ($loyalty_card_id > 0 && $point_qty > 0) {
        loyalty_sync($conn, $loyalty_card_id, $order_id, $point_qty, 'Points earned from order');
    }
```

- [ ] **Step 3: Verify**

Run: `php -l confirm_order.php && php tests/loyalty_test.php`
Expected: no syntax errors, `ALL PASS`

- [ ] **Step 4: Commit**

```bash
git add confirm_order.php
git commit -F - <<'EOF'
refactor(loyalty): checkout awards points through the shared writer

Both award paths in this file — a new order and an add-to-order top-up — now
call loyalty_sync() with the order's total earning quantity. The delta
arithmetic, the history row and the counter updates were duplicated here and are
now in one place.

Behaviour is unchanged at the default 1:1 rate. What changes is that these two
sites can no longer drift from the two pay-later sites, which counted a
different set of items entirely.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 3: Fix the two pay-later sites

**Files:**
- Modify: `admin_pay_cash.php:167-182`
- Modify: `check_payment.php:136-154`

**Interfaces:**
- Consumes: `loyalty_sync()`, `loyalty_earning_qty()` from Task 1

**This is a bug fix, not a refactor.** Both sites count `SUM(quantity)` with no filter, so a pay-later order awards points for merch and for the free gift drink itself.

- [ ] **Step 1: Replace the `admin_pay_cash.php` award**

Replace the body of the `if ($lc_id > 0 && ... === 'paylater' && ... === 0) {` block at `admin_pay_cash.php:167-182` with:

```php
    if ($lc_id > 0 && ($order['payment_method'] ?? '') === 'paylater' && (int)($order['points_earned'] ?? 0) === 0) {
        // Was SUM(quantity) with no filter, which awarded points for merch and
        // for the free gift drink itself — while the same basket paid up front
        // awarded neither. loyalty_earning_qty() is the one definition.
        $qty = loyalty_earning_qty($conn, $order_id);
        if ($qty > 0) {
            loyalty_sync($conn, $lc_id, $order_id, $qty, 'Points earned from Pay Later order');
        }
    }
```

- [ ] **Step 2: Replace the `check_payment.php` award**

Replace `check_payment.php:136-154` with:

```php
                // Award loyalty points for paylater orders settled via Bakong (once).
                if ($order['payment_method'] === 'paylater' && (int)($order['points_earned'] ?? 0) === 0) {
                    $lc_id = (int)($order['loyalty_card_id'] ?? 0);
                    if ($lc_id > 0) {
                        // Was SUM(quantity) with no filter — see admin_pay_cash.php.
                        $qty = loyalty_earning_qty($conn, $order_id);
                        if ($qty > 0) {
                            loyalty_sync($conn, $lc_id, $order_id, $qty, 'Points earned from Pay Later order');
                        }
                    }
                }
```

- [ ] **Step 3: Verify**

Run: `php -l admin_pay_cash.php && php -l check_payment.php && php tests/loyalty_test.php && php tests/counter_cash_test.php`
Expected: no syntax errors, `ALL PASS` from both suites.

- [ ] **Step 4: Commit**

```bash
git add admin_pay_cash.php check_payment.php
git commit -F - <<'EOF'
fix(loyalty): stop pay-later orders earning points for merch and gifts

Both pay-later settlement paths counted SUM(quantity) over order_items with no
filter. A pay-later order containing a T-shirt awarded a point for the T-shirt,
and a free loyalty gift drink awarded a point for itself — while the identical
basket paid up front awarded neither, because confirm_order.php has always
filtered on earns_points and a positive price.

Both now call loyalty_earning_qty(), which is the one definition of what earns:
earns_points = 1, price > 0, and product_id <> 0. product_id = 0 is the only
reliable gift test — the name prefix misses six older rows, and price = 0 also
matches a buy-X-get-1-free promo drink, which is a real cup.

Fixed here rather than later because the configurable rate multiplies whatever
these sites count, and a wrong count times two is worse than a wrong count.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 4: Reversal and merges

**Files:**
- Modify: `cancel_order.php:89-99`
- Modify: `merge_loyalty_cards.php:81-90`

**Interfaces:**
- Consumes: `loyalty_sync()` from Task 1

- [ ] **Step 1: Replace the claw-back in `cancel_order.php`**

Replace the `// 1) Claw back the points EARNED on this order` block (`cancel_order.php:90-99`) with:

```php
        // 1) Reverse the points EARNED on this order.
        //    Syncing to zero earning drinks undoes both the points and the
        //    progress, restoring the card to exactly where it stood before this
        //    order — including any part-progress toward the next point, which a
        //    points-only claw-back would silently take from the customer.
        loyalty_sync($conn, $card_id, $order_id, 0, 'Points reversed — order cancelled');
```

Leave block 2 (refunding redeemed points) exactly as it is — it reads
`loyalty_history` for `type = 'redeemed'` rows and is a separate concern.

- [ ] **Step 2: Carry progress through a merge**

In `merge_loyalty_cards.php`, the source-card update (around line 70) must also zero the progress, and the target update (around line 81) must add it.

First capture it with the other source values, beside `$moved_points = (int)$src['points'];`:

```php
// The carry toward the source's next point. Without moving it, a customer one
// drink into their next point loses that drink the moment their card is merged.
$moved_progress = (int)($src['points_progress'] ?? 0);
```

Add `points_progress` to the source `SELECT` at line 39, then set it to zero in
the source UPDATE alongside `points = 0`:

```php
            SET points = 0, points_progress = 0, is_active = 0, merged_into = ?, merged_at = NOW()
```

and add it to the target UPDATE:

```php
            SET points          = points + ?,
                points_progress = points_progress + ?,
                total_orders    = total_orders + ?,
                total_drinks    = total_drinks + ?,
```

binding `$moved_progress` in the matching position.

- [ ] **Step 3: Verify**

Run: `php -l cancel_order.php && php -l merge_loyalty_cards.php && php tests/loyalty_test.php`
Expected: no syntax errors, `ALL PASS` — including the merge assertions.

- [ ] **Step 4: Commit**

```bash
git add cancel_order.php merge_loyalty_cards.php
git commit -F - <<'EOF'
fix(loyalty): refunds and merges keep the customer's carried progress

A refund clawed back the points and left the progress. With a fractional rate
that silently costs the customer the part-progress they held BEFORE the order
they did not choose to have refunded, and the drift only ever runs against them.
Reversal now syncs the order to zero earning drinks, which restores points and
progress together and exactly.

A merge moved points, total_orders and total_drinks but not the carry, so a
customer one drink into their next point lost that drink the moment their card
was merged. Three cards are already merged in this database.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 5: Settings UI

**Files:**
- Modify: `settings.php` — POST group near line 29, the read block near line 90, and a new card after the CURRENCY form (ends around line 832)

- [ ] **Step 1: Add the POST group**

In the `match($section)` block, after the `'currency' => [...]` entry:

```php
        'loyalty' => [
            'loyalty_points_per'    => (string)max(1, min(100, (int)($_POST['loyalty_points_per']    ?? 1))),
            'loyalty_points_drinks' => (string)max(1, min(100, (int)($_POST['loyalty_points_drinks'] ?? 1))),
        ],
```

- [ ] **Step 2: Read the current values**

Beside `$khr_rate = ...` around line 90:

```php
$loy_per         = (int)($s['loyalty_points_per']    ?? 1);
$loy_drinks      = (int)($s['loyalty_points_drinks'] ?? 1);
```

- [ ] **Step 3: Add the card**

Immediately after the CURRENCY `</form>`:

```php
    <!-- ── LOYALTY EARN RATE ── -->
    <form method="POST">
        <div class="card">
            <div class="card-header">
                <div class="card-icon"><i class="fa-solid fa-stamp"></i></div>
                <div>
                    <div class="card-title">Loyalty Earn Rate</div>
                    <div class="card-sub">How many points a customer earns for how many drinks</div>
                </div>
            </div>

            <div class="card-inner">
                <div class="fields-grid">
                    <div class="field">
                        <label><i class="fa-solid fa-star"></i> Points earned</label>
                        <input type="number" name="loyalty_points_per" id="loyPer"
                               value="<?= $loy_per ?>" min="1" max="100">
                        <span class="field-hint">How many points to award</span>
                    </div>
                    <div class="field">
                        <label><i class="fa-solid fa-mug-hot"></i> Per how many drinks</label>
                        <input type="number" name="loyalty_points_drinks" id="loyDrinks"
                               value="<?= $loy_drinks ?>" min="1" max="100">
                        <span class="field-hint">Leftover drinks carry over to the next visit</span>
                    </div>
                </div>

                <?php /* Two bare number boxes are ambiguous until the ratio is
                         spelled out, so the sentence is the part that matters. */ ?>
                <div class="preview-row" id="loyPreview" style="margin-top:18px;">
                    <i class="fa-solid fa-tag"></i>
                    <span id="loyPreviewText"></span>
                </div>
            </div>

            <input type="hidden" name="_section" value="loyalty">
            <div class="form-actions">
                <div class="form-actions-info">
                    <i class="fa-solid fa-circle-info"></i>
                    Saves Loyalty Earn Rate only
                </div>
                <button type="submit" class="btn btn-save" style="padding:10px 22px;font-size:13px;">
                    <i class="fa-solid fa-floppy-disk"></i> Save
                </button>
            </div>
        </div>
    </form>
```

- [ ] **Step 4: Add the live summary**

In the page's existing `<script>` block, beside the `khrPreview` wiring:

```js
function loyPreview() {
  var per    = Math.max(1, parseInt(document.getElementById('loyPer').value, 10)    || 1);
  var drinks = Math.max(1, parseInt(document.getElementById('loyDrinks').value, 10) || 1);
  var el = document.getElementById('loyPreviewText');
  if (!el) return;
  el.textContent = per + (per === 1 ? ' point' : ' points') + ' per '
                 + (drinks === 1 ? 'drink' : drinks + ' drinks')
                 + ' — merch and free gift drinks never earn points'
                 + (drinks > 1 ? ', and leftover drinks carry to the next visit.' : '.');
}
['loyPer', 'loyDrinks'].forEach(function (id) {
  var n = document.getElementById(id);
  if (n) n.addEventListener('input', loyPreview);
});
loyPreview();
```

- [ ] **Step 5: Verify in the browser**

Run: log in at `http://localhost/Cafe/login.php` as `Sokun` / `@Sokun9811`, open `http://localhost/Cafe/settings.php`.
Expected: a "Loyalty Earn Rate" card with two inputs; the summary sentence updates as you type; saving `1` and `2` persists and the page reloads showing them. **Set it back to 1 and 1 before finishing** so the demo starts from the default.

- [ ] **Step 6: Commit**

```bash
git add settings.php
git commit -F - <<'EOF'
feat(settings): admin can set the loyalty earn rate

Two numbers, X points per Y drinks, in the same section-per-form shape as the
currency and tax cards. Clamped 1..100 on both sides so the till cannot be given
a rate that awards nothing or divides by zero.

The live summary sentence is the part that matters: "1" and "2" in adjacent
boxes is ambiguous until it reads "1 point per 2 drinks". It also states what
never earns — merch and free gift drinks — because that is invisible from the
numbers alone.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 6: Hide Add Discount

**Files:**
- Modify: `menu.php` — the `Add Discount` button in the cart panel

**Independent of Tasks 1-5.** Shares no code with the loyalty work.

- [ ] **Step 1: Wrap the button**

Find the `Add Discount` button in `menu.php`'s cart panel (search for `Add Discount`). There are two renderings — the server-side PHP markup and the JS that rebuilds the cart panel (`loadCartPanel`). Wrap the PHP one:

```php
<?php /* Discounting needs more shape than one blanket control before it goes in
         front of customers. Hidden rather than removed: the POST handler, the
         manual_discount column and every reader stay untouched, so orders that
         already carry a discount still render it. To restore, change false back
         to true. Same pattern as the Riel tile and products.php:1508. */ ?>
<?php if (false): ?>
  ... existing button markup, unchanged ...
<?php endif; ?>
```

For the JS-rendered copy, guard its emission with a flag set from PHP so both
paths agree:

```js
var CP_SHOW_DISCOUNT = false;   // see the PHP note above; flip to true to restore
```

and wrap the discount markup in `loadCartPanel` in `if (CP_SHOW_DISCOUNT) { ... }`.

- [ ] **Step 2: Verify in the browser**

Run: open `http://localhost/Cafe/menu.php` as `Sokun`, add an item to the cart.
Expected: no Add Discount button in the cart panel, on first render **and** after
changing a quantity (which triggers the JS re-render). No console errors.

- [ ] **Step 3: Verify an existing discounted order still renders**

Run:
```bash
php -r 'require "config.php"; $r=$conn->query("SELECT order_id, manual_discount FROM orders WHERE manual_discount > 0 ORDER BY order_id DESC LIMIT 3"); while($x=$r->fetch_assoc()) print_r($x);'
```
Open one of those orders in `find_order.php`. Expected: its discount still shows.

- [ ] **Step 4: Commit**

```bash
git add menu.php
git commit -F - <<'EOF'
chore(menu): hide the Add Discount control

Discounting needs more shape than a single blanket control before it goes in
front of customers. Hidden rather than removed — the POST handler, the
manual_discount column and every reader are untouched, so orders that already
carry a discount still render theirs and the control returns by flipping one
word.

Both renderings are covered: the server-side markup and the copy the cart panel
rebuilds in JS, which would otherwise have put the button back on the first
quantity change.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 7: End-to-end verification

**Files:** none modified — produces evidence.

- [ ] **Step 1: Run every suite**

Run: `php tests/loyalty_test.php && php tests/tender_test.php && php tests/counter_cash_test.php && php tests/daily_report_test.php && php tests/purchase_order_test.php && php tests/remake_test.php && node tests/tender.test.mjs`
Expected: `ALL PASS` from each.

- [ ] **Step 2: Default rate is unchanged behaviour**

With the rate at 1 and 1, place an order for 2 drinks against a loyalty card through `menu.php`. Expected: exactly 2 points, `points_progress` 0. Read back:
```bash
php -r 'require "config.php"; $r=$conn->query("SELECT o.order_id,o.points_earned,o.points_qty,c.points,c.points_progress FROM orders o JOIN loyalty_cards c ON c.card_id=o.loyalty_card_id ORDER BY o.order_id DESC LIMIT 1"); print_r($r->fetch_assoc());'
```

- [ ] **Step 3: Fractional rate**

Set the rate to **1 point per 2 drinks** in `settings.php`. Place a **1-drink** order against the same card. Expected: 0 points, `points_progress` 1. Place another 1-drink order. Expected: 1 point, `points_progress` back to 0.

- [ ] **Step 4: Refund restores progress**

Note the card's `points` and `points_progress`. Place a 3-drink order, then refund it through the app's Refund action. Expected: both values return to exactly what they were.

- [ ] **Step 5: Pay-later earns the same as paid-up-front**

Place the same basket twice — once paid immediately, once as Pay Later then settled at the counter. Expected: identical `points_earned` on both orders.

- [ ] **Step 6: Reset and report**

Set the rate back to **1 and 1**. Refund any test orders (an order settled in cash cannot be cancelled — that is deliberate, the remedy is a refund). Report every order number created and its final status.

---

## Self-Review

**Spec coverage:**

| Spec requirement | Task |
|---|---|
| Two settings, clamped, defaults 1/1 | 1 (constants), 5 (UI) |
| Ratio arithmetic with carry | 1 |
| Exact reversal | 1 (helper), 4 (call site) |
| `0 ≤ progress < Y` enforced and logged | 1 |
| Two columns via `_migrate()`, no backfill | 1 |
| `qty` = earning drinks only | 1 (`loyalty_earning_qty`) |
| Bug fix: two pay-later sites | 3 |
| Card merges carry progress; reversal follows `merged_into` | 1 (resolution), 4 (merge) |
| Settings UI with live summary | 5 |
| Hide Add Discount | 6 |
| Test matrix incl. add-to-order and merge | 1, 7 |

**Deviation from the spec, deliberate:** the spec named two helpers,
`loyalty_award()` and `loyalty_reverse()`. They collapse into one `loyalty_sync()`
that takes the order's total earning quantity, because a reversal is just a sync
to zero and an add-to-order top-up is a sync to a larger number. One function
means the reversal cannot drift from the award — and the spec's own round-trip
requirement is then structural rather than a property two functions must
separately honour.

**Placeholder scan:** no TBDs, no "add error handling", no "similar to Task N".
Every code step carries its code.

**Type consistency:** `loyalty_sync(mysqli, int, int, int, string): int` and
`loyalty_earning_qty(mysqli, int): int` are used with those signatures in Tasks
2, 3 and 4. Column names `points_progress` and `points_qty` are identical across
Tasks 1, 4 and 7. Setting keys `loyalty_points_per` / `loyalty_points_drinks`
match between Task 1's constants and Task 5's POST group.
