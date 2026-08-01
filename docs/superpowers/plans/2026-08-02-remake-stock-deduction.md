# Remake Stock Deduction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make a remake consume the ingredients it actually uses, for the drinks actually re-poured, and reset their made-state so the barista sees real work to do.

**Architecture:** `_deduct_stock()` moves from `confirm_order.php` into `config.php` so the remake handler shares one writer rather than a copy. `remake_order.php` gains the transaction it never had, validates per-item quantities against the order, deducts through that shared helper, and decrements `made_qty`. The modal gains a checkbox and a quantity per drink.

**Tech Stack:** PHP 8.2 + mysqli, vanilla JS. Tests are CLI scripts: `php tests/<name>.php`.

## Global Constraints

- **Deduct only the drinks actually remade.** Whole-order deduction on a four-drink order where one was wrong invents three drinks of consumption — a worse lie than today's zero.
- **`_deduct_stock()` is MOVED, not copied.** A duplicated stock formula is how the buy-X-get-1-free calculation reached ten places.
- **No new `change_type`.** The deduct classifier is duplicated across five places in `ingredient_history.php` (the poll-stats branch is the one that gets missed). Remakes reuse `order_deduct` and are identified by their `reference` string plus the accompanying `order_remakes` row.
- **Never re-bill the customer.** `remake_order.php` deliberately leaves `order_items.price` alone; a remake is service recovery. Stock is physical and moves; money does not.
- **Never block a remake for want of stock.** `_deduct_stock()` already deducts only what is on hand and returns shortfalls. The drink has already been poured by the time this is recorded; refusing would record less than what happened.
- **Adjust only what was remade.** Rewriting the milk on a drink nobody re-poured falsifies history.
- Deduction, made-state and the `order_remakes` row all live in one transaction. A partially applied remake is worse than a refused one.

---

### Task 1: Move `_deduct_stock()` into `config.php`

**Files:**
- Modify: `config.php` (add the function beside `po_receive_line()`)
- Modify: `confirm_order.php:637-697` (remove the body; calls at `:244` and `:480` unchanged)
- Test: `tests/remake_test.php` (create)

**Interfaces:**
- Produces: `_deduct_stock(mysqli $conn, int $product_id, int $qty, string $milk_choice, int $order_id = 0, float $size_factor = 1.0, ?string $reference = null): array` — returns a list of shortfalls, each `['name' => string, 'need' => float, 'had' => float]`.
- Consumed by Task 2 and by `confirm_order.php`.

- [ ] **Step 1: Write the failing test**

Create `tests/remake_test.php`:

```php
<?php
/**
 * CLI assertions for remake stock deduction.
 * Run:  php tests/remake_test.php
 * There is no test framework in this project; this script is the harness.
 *
 * Everything that touches stock runs inside one transaction, rolled back in the
 * finally no matter how the block exits. These tests move REAL ingredient stock;
 * straight-line cleanup is skipped by a fatal and would leave it inflated.
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

echo "shared deduct helper\n";
check('_deduct_stock is available from config.php',
      function_exists('_deduct_stock'), true);
$ref = new ReflectionFunction('_deduct_stock');
check('it accepts a reference override', $ref->getNumberOfParameters(), 7);
check('the reference parameter is last and optional',
      $ref->getParameters()[6]->getName() . ':' . ($ref->getParameters()[6]->isOptional() ? 'opt' : 'req'),
      'reference:opt');

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/remake_test.php`
Expected: FAIL — `_deduct_stock is available from config.php` is `false`. `config.php` alone does not define it today; it lives inside `confirm_order.php`, which this script never loads.

- [ ] **Step 3: Move the function**

Cut the entire `_deduct_stock` definition from `confirm_order.php:637-697` and paste it into `config.php` immediately after the `po_short_reason_label()` block, wrapped in a `function_exists` guard to match every other helper there. Add the docblock and the new parameter:

```php
/**
 * Deduct ingredient stock for one drink.
 *
 * Deducts and logs only what is actually on hand (never goes negative, never logs
 * a phantom full deduction when short). Returns a list of shortfalls so the caller
 * can warn staff: each ['name' => ingredient, 'need' => required, 'had' => available].
 *
 * Lives here rather than in confirm_order.php because remake_order.php needs the
 * same arithmetic, including the milk substitution — a remake can change the milk.
 * One writer, never a copy.
 *
 * $reference overrides the ledger reference. Remakes pass their own so a second
 * deduction against one order is identifiable, without adding a change_type that
 * the five deduct classifiers in ingredient_history.php would all have to learn.
 */
if (!function_exists('_deduct_stock')) {
    function _deduct_stock(mysqli $conn, int $product_id, int $qty, string $milk_choice,
                           int $order_id = 0, float $size_factor = 1.0,
                           ?string $reference = null): array {
        // ... body exactly as it was in confirm_order.php ...
    }
}
```

Inside the body, change only the reference line:

```php
            $oid = $order_id > 0 ? $order_id : null;
            $ref = $reference !== null ? $reference : ($oid ? "Order #$order_id" : null);
```

Everything else — the milk substitution, the on-hand read, the `GREATEST(0, …)` update, the shortfall collection — is moved verbatim.

Then delete the now-empty trailing `?>` region issue: `confirm_order.php` ends with the function followed by `?>`. Leave the `?>` in place and remove only the function.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/remake_test.php`
Expected: PASS — all three checks.

- [ ] **Step 5: Verify ordering still works**

The move must not change checkout. `confirm_order.php:244` and `:480` still call it and `config.php` is already required there.

```bash
php -l confirm_order.php && php -l config.php
```

Then place a real order through the UI (menu → add a drink → Cash → confirm) and confirm stock moved:

```bash
/c/xampp/mysql/bin/mysql.exe -u root db_coffee -e \
  "SELECT ingredient_id, change_type, amount, reference, created_at
   FROM ingredient_history WHERE change_type='order_deduct'
   ORDER BY id DESC LIMIT 5;"
```

Expected: fresh `order_deduct` rows with a `Order #N` reference, exactly as before.

- [ ] **Step 6: Commit**

```bash
git add config.php confirm_order.php tests/remake_test.php
git commit -m "refactor(stock): move _deduct_stock into config.php as one shared writer"
```

---

### Task 2: Deduct on remake, per drink and per quantity

**Files:**
- Modify: `remake_order.php` (transaction, validation, deduction, made-state)
- Test: `tests/remake_test.php` (extend)

**Interfaces:**
- Consumes: `_deduct_stock()` from Task 1.
- Produces: `remake_order.php` accepts `items` — a JSON array of `{item_id, qty}` — alongside the existing `reason` and `adjustments`.

- [ ] **Step 1: Write the failing test**

Append to `tests/remake_test.php`, before the final `echo`. Ingredient 48 is Milk base, the same fixture `purchase_order_test.php` uses.

```php
echo "remake deduction\n";
$conn->begin_transaction();
try {
    // A throwaway order with one line of 3, so the per-quantity case is real.
    $conn->query("INSERT INTO orders (daily_order_no, customer_name, total, status,
                                      is_open, payment_method, business_date, order_date, order_type)
                  VALUES (1, 'REMAKE-TEST', 9.00, 'Completed', 0, 'cash', '2020-01-01', '2020-01-01 10:00:00', 'drink_in')");
    $oid = (int)$conn->insert_id;

    // A product that actually has a recipe, else there is nothing to deduct.
    $pid = (int)$conn->query("SELECT product_id FROM product_ingredients
                              GROUP BY product_id HAVING COUNT(*) > 0 LIMIT 1")->fetch_row()[0];
    check('a product with a recipe exists', $pid > 0, true);

    $conn->query("INSERT INTO order_items (order_id, product_id, product_name, quantity,
                                           price, made_qty, made_at)
                  VALUES ($oid, $pid, 'Test Drink', 3, 3.00, 3, NOW())");
    $iid = (int)$conn->insert_id;

    // What one drink of this product costs in its first ingredient.
    $r1  = $conn->query("SELECT ingredient_id, amount_used FROM product_ingredients
                         WHERE product_id = $pid LIMIT 1")->fetch_assoc();
    $ing = (int)$r1['ingredient_id'];
    $per = (float)$r1['amount_used'];

    $before = (float)$conn->query("SELECT stock_quantity FROM ingredients
                                   WHERE ingredient_id = $ing")->fetch_row()[0];

    // Remake ONE of the three.
    $short = _deduct_stock($conn, $pid, 1, '', $oid, 1.0, "Remake of order #1");
    $after = (float)$conn->query("SELECT stock_quantity FROM ingredients
                                  WHERE ingredient_id = $ing")->fetch_row()[0];
    check('one remade drink deducts one drink of stock', round($before - $after, 4), round($per, 4));

    // The ledger row must be identifiable as a remake without a new change_type.
    $lref = (string)$conn->query("SELECT reference FROM ingredient_history
                                  WHERE ingredient_id = $ing ORDER BY id DESC LIMIT 1")->fetch_row()[0];
    check('the ledger carries the remake reference', $lref, 'Remake of order #1');
    $ltype = (string)$conn->query("SELECT change_type FROM ingredient_history
                                   WHERE ingredient_id = $ing ORDER BY id DESC LIMIT 1")->fetch_row()[0];
    check('the ledger keeps order_deduct', $ltype, 'order_deduct');

    // made_qty decrements rather than zeroing: the two drinks nobody complained
    // about are still made.
    $conn->query("UPDATE order_items SET made_qty = GREATEST(0, made_qty - 1) WHERE item_id = $iid");
    $mq = (int)$conn->query("SELECT made_qty FROM order_items WHERE item_id = $iid")->fetch_row()[0];
    check('made_qty drops by the remade quantity only', $mq, 2);
} finally {
    $conn->rollback();
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/remake_test.php`
Expected: FAIL on `one remade drink deducts one drink of stock` if Task 1 was skipped; otherwise these pass and prove the helper works, which is what Task 2's handler will rely on.

If they already pass, that is correct — this block tests the helper contract the handler depends on. Proceed to Step 3 for the handler itself.

- [ ] **Step 3: Accept the ticked items**

In `remake_order.php`, after the existing `$reason` validation and before the `order_remakes` insert:

```php
/* Which drinks were actually re-poured, and how many of each. Deducting the whole
   order would invent consumption for drinks nobody remade; deducting nothing —
   the old behaviour — silently lost the ingredients that really were used. */
$items_raw = trim($_POST['items'] ?? '');
$items     = json_decode($items_raw, true);
if (!is_array($items) || !$items) {
    echo json_encode(["ok" => 0, "error" => "Select at least one drink to remake"]);
    exit;
}

// item_id => quantity, validated against this order's own lines.
$lines = [];
$lq = $conn->prepare("SELECT item_id, product_id, quantity, size_label, milk
                      FROM order_items WHERE order_id = ?");
$lq->bind_param("i", $order_id);
$lq->execute();
$lr = $lq->get_result();
while ($row = $lr->fetch_assoc()) { $lines[(int)$row['item_id']] = $row; }

$remake = [];
foreach ($items as $it) {
    $iid = (int)($it['item_id'] ?? 0);
    $q   = (int)($it['qty'] ?? 0);
    // An item_id from another order, or a quantity above what was sold, is refused
    // outright rather than clamped — a silent clamp hides a broken client.
    if (!isset($lines[$iid]) || $q < 1 || $q > (int)$lines[$iid]['quantity']) {
        echo json_encode(["ok" => 0, "error" => "That drink is not on this order, or the quantity is wrong"]);
        exit;
    }
    $remake[$iid] = $q;
}
```

- [ ] **Step 4: Wrap everything in a transaction and deduct**

`remake_order.php` currently runs three unguarded statements. Wrap from the
`order_remakes` insert to the status flip:

```php
$conn->begin_transaction();
try {
    // ... existing order_remakes INSERT ...
    // ... existing adjustments block, but see Step 5 ...

    $shortfalls = [];
    foreach ($remake as $iid => $q) {
        $line = $lines[$iid];
        $pid  = (int)$line['product_id'];

        /* The Large multiplier, so a Large remake consumes Large quantities. Same
           factor confirm_order.php applies at ordering. No row — an unsized
           product — means 1.0. */
        $sf = 1.0;
        if (!empty($line['size_label'])) {
            $sq = $conn->prepare("SELECT size_factor FROM product_sizes
                                  WHERE product_id = ? AND label = ? LIMIT 1");
            $sq->bind_param("is", $pid, $line['size_label']);
            $sq->execute();
            $srow = $sq->get_result()->fetch_assoc();
            if ($srow) $sf = (float)$srow['size_factor'];
        }

        /* The ADJUSTED milk, not the milk originally ordered — the drink that gets
           poured is the adjusted one. adjustments may have just rewritten it. */
        $milk = (string)($adjusted_milk[$iid] ?? $line['milk'] ?? '');

        $shortfalls = array_merge(
            $shortfalls,
            _deduct_stock($conn, $pid, $q, $milk, $order_id, $sf,
                          "Remake of order #" . $order['daily_order_no'])
        );

        /* Decrement rather than zero: the drinks on this line that nobody
           complained about are still made. made_at clears only when none remain. */
        $mu = $conn->prepare("UPDATE order_items
                              SET made_qty = GREATEST(0, made_qty - ?),
                                  made_at  = CASE WHEN GREATEST(0, made_qty - ?) = 0
                                                  THEN NULL ELSE made_at END
                              WHERE item_id = ? AND order_id = ?");
        $mu->bind_param("iiii", $q, $q, $iid, $order_id);
        $mu->execute();
    }

    // ... existing UPDATE orders SET status='Preparing', is_open=1 ...

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    echo json_encode(["ok" => 0, "error" => "The remake could not be saved. Nothing was changed."]);
    exit;
}
```

Return the shortfalls so the cashier gets the same warning the ordering flow gives:

```php
echo json_encode([
    "ok" => 1,
    "message" => "Order #" . $order['daily_order_no'] . " sent back to Preparing",
    "shortfalls" => $shortfalls,
]);
```

- [ ] **Step 5: Adjust only the remade drinks**

In the existing adjustments loop, skip anything not being remade, and record the
milk chosen so Step 4 can deduct against it. Immediately after
`$adj_item_id = (int)($adj['item_id'] ?? 0);`:

```php
            if ($adj_item_id <= 0) continue;
            // Rewriting the options of a drink nobody re-poured would falsify
            // history — only remade drinks are adjustable.
            if (!isset($remake[$adj_item_id])) continue;
```

and declare `$adjusted_milk = [];` above the loop, setting `$adjusted_milk[$adj_item_id] = $adj_ml;` after `$adj_ml` is assigned.

- [ ] **Step 6: Verify the whole handler**

Run: `php tests/remake_test.php`
Expected: `ALL PASS`

Then a live check against a scratch order. **Use a past `business_date`** — a test order dated today poisons the customer-facing `daily_order_no` sequence, and deleting the row afterwards does not undo it:

```bash
/c/xampp/mysql/bin/mysql.exe -u root db_coffee -e "
INSERT INTO orders (daily_order_no,customer_name,total,status,is_open,payment_method,business_date,order_date,order_type)
VALUES (1,'SCRATCH-REMAKE',9.00,'Completed',0,'cash','2020-01-01','2020-01-01 10:00:00','drink_in');
SELECT LAST_INSERT_ID();"
```

Add a line for a product with a recipe, then POST with `items=[{"item_id":N,"qty":1}]`
and confirm stock fell by exactly one drink and `made_qty` by exactly 1. Delete the
scratch order afterwards and re-check `SELECT stock_quantity` to be sure the test
did not leave stock changed.

- [ ] **Step 7: Commit**

```bash
git add remake_order.php tests/remake_test.php
git commit -m "feat(remake): deduct ingredients for the drinks actually re-poured"
```

---

### Task 3: Pick the drinks in the modal

**Files:**
- Modify: `view_order.php:2516-2541` (`showRemakeModal`), `:2548-2586` (`confirmRemake`), `:693-705` (styles)

**Interfaces:**
- Consumes: the `items` payload contract from Task 2.

- [ ] **Step 1: Add a checkbox and quantity per drink**

In `showRemakeModal`, replace the `block.innerHTML` assignment:

```js
            const maxQty = parseInt(item.quantity, 10) || 1;
            block.innerHTML =
                `<div class="remake-item-name">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" class="remake-pick" onchange="toggleRemakePick(this)">
                        <i class="fa-solid fa-mug-hot"></i>
                        ${escapeHtml(item.product_name)}
                        ${maxQty > 1 ? `<span style="opacity:.6;font-weight:400">×${maxQty} ordered</span>` : ''}
                    </label>
                 </div>` +
                `<div class="remake-qty-row" style="display:none;margin-bottom:10px">
                    <label class="remake-adj-label">How many to remake</label>
                    <input type="number" class="remake-qty" value="1" min="1" max="${maxQty}"
                           style="width:70px;padding:5px 8px;border-radius:6px;
                                  border:1px solid var(--border,#333);
                                  background:var(--card,#1a1a1a);color:inherit">
                 </div>` +
                `<div class="remake-opts" style="display:none">` +
                buildPillGroup('sweetness', SWEETNESS_OPTS, item.sweetness) +
                buildPillGroup('ice',       ICE_OPTS,       item.ice) +
                buildPillGroup('milk',      MILK_OPTS,      item.milk) +
                buildAddonGroup(item.product_id, item.addons) +
                `</div>`;
```

and add beside `toggleAddonPill`:

```js
/* Options and quantity only apply to a drink being remade, so they stay hidden
   until it is ticked. A visible option pill on an unticked drink implies it will
   be applied, and it will not. */
function toggleRemakePick(cb) {
    const block = cb.closest('.remake-item-block');
    const on = cb.checked;
    block.querySelector('.remake-qty-row').style.display = on ? '' : 'none';
    block.querySelector('.remake-opts').style.display    = on ? '' : 'none';
    block.style.opacity = on ? '1' : '.55';
}
```

Set the initial dimming when each block is appended:

```js
            block.style.opacity = '.55';
```

- [ ] **Step 2: Send only the ticked drinks**

In `confirmRemake`, replace the `adjustments` collection:

```js
    const adjustments = [];
    const items = [];
    document.querySelectorAll('#remakeAdjustments .remake-item-block').forEach(block => {
        if (!block.querySelector('.remake-pick').checked) return;
        const itemId = block.dataset.itemId;
        const qty    = parseInt(block.querySelector('.remake-qty').value, 10) || 1;
        items.push({ item_id: itemId, qty: qty });
        adjustments.push({
            item_id:   itemId,
            sweetness: block.querySelector('[data-type="sweetness"].selected')?.textContent || '',
            ice:       block.querySelector('[data-type="ice"].selected')?.textContent || '',
            milk:      block.querySelector('[data-type="milk"].selected')?.textContent || '',
            // Names only — the server re-looks-up prices so the client can't set them.
            addons:    Array.from(block.querySelectorAll('[data-type="addon"].selected'))
                            .map(b => b.dataset.name)
        });
    });

    if (!items.length) {
        showToast('Pick at least one drink to remake.', 'error');
        btn.disabled = false;
        return;
    }
```

and add it to the request:

```js
        formData.append('items', JSON.stringify(items));
```

- [ ] **Step 3: Surface any shortfall**

In the `if (data.ok)` branch, after the existing toast:

```js
            if (data.shortfalls && data.shortfalls.length) {
                // Same warning the ordering flow gives: the remake is recorded
                // either way, but the shop is short.
                const names = data.shortfalls.map(s => s.name).join(', ');
                showToast('⚠️ Not enough stock: ' + names, 'error');
            }
```

- [ ] **Step 4: Verify in the browser**

As `Sokun` on `view_order.php`:

1. Find a **Completed** order and click **Remake**. Every drink starts unticked and dimmed, with options hidden.
2. Submit with nothing ticked → *"Pick at least one drink to remake."*, nothing sent.
3. Tick one drink → its quantity box and options appear. On a `×3` line the quantity caps at 3.
4. Enter a reason, confirm. Toast reports the order back to Preparing.
5. Check stock fell by exactly the remade quantity:

```bash
/c/xampp/mysql/bin/mysql.exe -u root db_coffee -e \
  "SELECT ingredient_id, amount, reference, created_at FROM ingredient_history
   WHERE reference LIKE 'Remake of order%' ORDER BY id DESC LIMIT 5;"
```

6. Confirm the order is back in the barista queue with the remade drink showing as
   unmade, and the untouched drinks on the same line still made.

- [ ] **Step 5: Run both suites**

```bash
php tests/remake_test.php
php tests/purchase_order_test.php
php tests/counter_cash_test.php
```

Expected: `ALL PASS` for all three.

- [ ] **Step 6: Commit**

```bash
git add view_order.php
git commit -m "feat(remake): pick which drinks and how many are being re-poured"
```

---

## Self-review notes

- **Spec coverage.** §1 move the helper → Task 1. §2 modal → Task 3. §3 handler (transaction, validation, size factor, deduction, made-state) → Task 2. §4 access unchanged — `canRemake` and the server role check are untouched.
- **Not automated:** the modal, and the end-to-end handler behaviour over HTTP. The test covers the deduction contract, the ledger reference and the made-state arithmetic directly; the handler wiring is verified manually in Task 2 Step 6 and Task 3 Step 4.
- **The riskiest step is Task 1.** `_deduct_stock` is the ingredient arithmetic for every drink sold. Step 5 exists specifically to prove checkout still deducts after the move — do not skip it because the tests pass, since the tests do not exercise `confirm_order.php`.
- **Scratch orders must use a past `business_date`.** A test order dated today poisons the live `daily_order_no` sequence via `confirm_order.php:370`, and deleting the row does not undo it.
