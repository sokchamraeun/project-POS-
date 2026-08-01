# Partial Receiving for Purchase Orders — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the inventory clerk record the quantity that physically arrived on a purchase order, per line, so stock reflects the delivery instead of the order.

**Architecture:** One new column (`purchase_order_items.qty_received`) and one new status (`Partially Received`) carry the whole feature. Stock moves by the delta on each receive, never by the total. `purchase_orders.total_cost` is never rewritten — the received value is derived from `SUM(qty_received * unit_cost)`. Because a PO can now legitimately be received more than once, the double-receive guard moves from a PO-level status claim to a per-line optimistic claim.

**Tech Stack:** PHP 8.2 (XAMPP), MariaDB via mysqli, no framework, no build step. Tests are CLI assertion scripts in `tests/` — there is no test framework in this project.

**Spec:** `docs/superpowers/specs/2026-07-28-partial-receiving-design.md`

## Global Constraints

- **Every DB write uses a prepared statement.** No string interpolation into SQL.
  One pre-existing exception stays: `purchase_orders.php:43` interpolates
  `$filter` into a `WHERE` clause. It is safe only because line 19 rejects
  anything not in the `$allowed` whitelist. Task 6 adds `'Partially Received'`
  to that whitelist — a value with no quote characters, so the guarantee holds.
  Do not add a user-supplied value to `$allowed`.
- **Every state-mutating POST checks CSRF** with `hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')`, redirecting through `po_redirect($from_list, $po_id, 'badtoken')` on failure. This is already in place at `purchase_order_view.php:27`; do not remove it.
- **Schema changes go through `_migrate($conn, '<id>_v1', function($db) { ... })`** in `config.php`. Migrations are idempotent and self-apply on next page load.
- **`purchase_orders.total_cost` is read-only.** No task may write to it.
- **Manager gate is the role list `['admin','manager']`** — the exact pattern used at `stock_count.php:87`. Never gate on a permission slug for this. Task 5 wraps it as `po_may_close_short()` so the handler, the button and the test all read one definition; call that function rather than re-inlining `in_array(...)`.
- **HTML output is escaped with `he()`** (defined in `purchase_order_view.php`).
- **Money is `number_format($n, 2)`; quantities are `number_format($n, 3)`** — matching the existing PO pages.
- **Tests run with `php tests/<file>.php` and print `PASS`/`FAIL` lines**, ending with `ALL PASS` or a non-zero failure count. Copy the harness shape from `tests/daily_report_test.php`.
- **New DB columns use `ADD COLUMN IF NOT EXISTS`** so re-running is safe.

## File Structure

| File | Responsibility | Change |
|---|---|---|
| `config.php` | Schema migration + three shared PO helpers | Modify |
| `purchase_order_view.php` | Receive form, receive transaction, close-short action | Modify |
| `purchase_orders.php` | List: status tab, colour, Receive link, Total Received stat | Modify |
| `tests/purchase_order_test.php` | CLI assertions for schema, helpers, and receive arithmetic | Create |

The helpers live in `config.php` rather than in the PO pages for two reasons. `po_line_values()` and `po_status_from_lines()` are needed by both pages — the detail page renders the received value, the list page sums it — which is the same reason `order_cogs()` and `cogs_cups()` live there. `po_receive_line()` is there so the test can reach it: the POST handler needs a session and ends every path in `header()` + `exit`, so a test can only drive the arithmetic if it is lifted out of the handler.

---

### Task 1: Schema migration and backfill

**Files:**
- Modify: `config.php` (append after the last `_migrate(...)` block)
- Test: `tests/purchase_order_test.php`

**Interfaces:**
- Consumes: nothing
- Produces: `purchase_order_items.qty_received` (DECIMAL(10,3) NOT NULL DEFAULT 0); `purchase_orders.status` enum gains `'Partially Received'`; `purchase_orders.closed_short` (TINYINT(1) NOT NULL DEFAULT 0), `closed_short_at` (DATETIME NULL), `closed_short_by` (VARCHAR(100) NULL)

- [ ] **Step 1: Write the failing test**

Create `tests/purchase_order_test.php`:

```php
<?php
/**
 * CLI assertions for purchase-order partial receiving.
 * Run:  php tests/purchase_order_test.php
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

echo "schema\n";
$col = $conn->query("SHOW COLUMNS FROM purchase_order_items LIKE 'qty_received'")->fetch_assoc();
check('qty_received exists',       $col !== null,                       true);
check('qty_received defaults to 0', (float)$col['Default'],             0.0);
check('qty_received is NOT NULL',   $col['Null'],                       'NO');

$status = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'status'")->fetch_assoc();
check('status allows Partially Received',
      strpos($status['Type'], "'Partially Received'") !== false,        true);

foreach (['closed_short', 'closed_short_at', 'closed_short_by'] as $c) {
    check("$c exists",
          $conn->query("SHOW COLUMNS FROM purchase_orders LIKE '$c'")->num_rows === 1, true);
}

echo "backfill\n";
// Every PO that was already Received predates this feature and was, by
// definition, received in full. Left at 0 they would all read as shortfalls.
$bad = (int)$conn->query("
    SELECT COUNT(*) FROM purchase_order_items poi
    JOIN purchase_orders p ON p.po_id = poi.po_id
    WHERE p.status = 'Received' AND poi.qty_received <> poi.qty_ordered
")->fetch_row()[0];
check('historical Received POs are backfilled in full', $bad, 0);

// Draft and Ordered POs have not been delivered, so they must stay at zero.
$early = (int)$conn->query("
    SELECT COUNT(*) FROM purchase_order_items poi
    JOIN purchase_orders p ON p.po_id = poi.po_id
    WHERE p.status IN ('Draft','Ordered') AND poi.qty_received <> 0
")->fetch_row()[0];
check('undelivered POs stay at zero', $early, 0);

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/purchase_order_test.php`
Expected: FAIL on `qty_received exists` (got `false`, want `true`), and a PHP warning about `$col['Default']` on null.

- [ ] **Step 3: Write the migration**

Append to `config.php`, after the last existing `_migrate(...)` call:

```php
// ── Partial receiving: record what actually arrived, not what was ordered ──
// mark_received used to add qty_ordered to stock regardless of the delivery,
// so a short delivery silently inflated inventory. qty_received is the number
// the clerk counted off the truck.
_migrate($conn, 'po_partial_receive_v1', function($db) {
    $db->query("ALTER TABLE purchase_order_items
                ADD COLUMN IF NOT EXISTS qty_received DECIMAL(10,3) NOT NULL DEFAULT 0");

    $db->query("ALTER TABLE purchase_orders
                MODIFY COLUMN status
                ENUM('Draft','Ordered','Partially Received','Received','Cancelled')
                NULL DEFAULT 'Draft'");

    $db->query("ALTER TABLE purchase_orders
                ADD COLUMN IF NOT EXISTS closed_short TINYINT(1) NOT NULL DEFAULT 0");
    $db->query("ALTER TABLE purchase_orders
                ADD COLUMN IF NOT EXISTS closed_short_at DATETIME NULL DEFAULT NULL");
    $db->query("ALTER TABLE purchase_orders
                ADD COLUMN IF NOT EXISTS closed_short_by VARCHAR(100) NULL DEFAULT NULL");

    // Anything already Received was received in full — that was the only
    // behaviour the old code had. Without this backfill all twelve historical
    // POs would render as shortfalls the moment the new columns appear.
    $db->query("UPDATE purchase_order_items poi
                JOIN purchase_orders p ON p.po_id = poi.po_id
                SET poi.qty_received = poi.qty_ordered
                WHERE p.status = 'Received'");
});
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/purchase_order_test.php`
Expected: PASS on all eight assertions, ending `ALL PASS`.

- [ ] **Step 5: Confirm total_cost was not disturbed**

Run:
```bash
php -r "require 'config.php';
\$r=\$conn->query('SELECT po_id,total_cost FROM purchase_orders ORDER BY po_id');
while(\$x=\$r->fetch_row()) echo implode(' ',\$x),\"\n\";"
```
Expected: 18 rows. Record this output — Task 6 compares against it.

- [ ] **Step 6: Commit**

```bash
git add config.php tests/purchase_order_test.php
git commit -m "feat(procurement): add qty_received and Partially Received status"
```

---

### Task 2: Shared helpers for received value and status

**Files:**
- Modify: `config.php` (near `cogs_cups()`, in the shared-helper block)
- Test: `tests/purchase_order_test.php`

**Interfaces:**
- Consumes: `purchase_order_items.qty_received` from Task 1
- Produces:
  - `po_line_values(mysqli $conn, int $po_id): array` returning `['ordered' => float, 'received' => float, 'outstanding' => float]` — money, not quantities
  - `po_status_from_lines(mysqli $conn, int $po_id): string` returning `'Received'` or `'Partially Received'`

- [ ] **Step 1: Write the failing test**

Append to `tests/purchase_order_test.php`, immediately before the final `echo $failures === 0 ...` line:

```php
echo "po_line_values\n";
// PO 1 is historical and fully received, so ordered and received must agree
// and nothing is outstanding.
$anyReceived = (int)$conn->query(
    "SELECT po_id FROM purchase_orders WHERE status='Received' ORDER BY po_id LIMIT 1"
)->fetch_row()[0];
$v = po_line_values($conn, $anyReceived);
check('returns three keys',        array_keys($v), ['ordered','received','outstanding']);
check('a full PO has no shortfall', round($v['outstanding'], 2),          0.0);
check('received equals ordered',    round($v['received'] - $v['ordered'], 2), 0.0);

// An Ordered PO has been placed but not delivered: nothing received, all outstanding.
$notYet = (int)$conn->query(
    "SELECT po_id FROM purchase_orders WHERE status='Ordered' ORDER BY po_id LIMIT 1"
)->fetch_row()[0];
$v2 = po_line_values($conn, $notYet);
check('an undelivered PO has received 0',        round($v2['received'], 2),    0.0);
check('an undelivered PO is fully outstanding',
      round($v2['outstanding'] - $v2['ordered'], 2),                           0.0);

check('an unknown PO is all zeroes', po_line_values($conn, 0),
      ['ordered'=>0.0, 'received'=>0.0, 'outstanding'=>0.0]);

echo "po_status_from_lines\n";
check('a fully received PO reads Received', po_status_from_lines($conn, $anyReceived), 'Received');
check('an untouched PO reads Partially Received',
      po_status_from_lines($conn, $notYet), 'Partially Received');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/purchase_order_test.php`
Expected: PHP fatal, `Call to undefined function po_line_values()`.

- [ ] **Step 3: Write the helpers**

Add to `config.php`, directly after the `cogs_cups()` function:

```php
/**
 * What a purchase order is worth, ordered against actually delivered.
 *
 * purchase_orders.total_cost records the order that was placed and is never
 * rewritten — changing it would falsify a document already issued to a
 * supplier, and would make a short delivery indistinguishable from a small
 * order. The delivered value is derived here instead.
 *
 * Over-delivery is not clamped: if twelve cartons arrive against ten ordered,
 * the received value exceeds the ordered value and 'outstanding' is zero
 * rather than negative, because you cannot be owed a negative quantity.
 */
if (!function_exists('po_line_values')) {
    function po_line_values(mysqli $conn, int $po_id): array {
        $out = ['ordered' => 0.0, 'received' => 0.0, 'outstanding' => 0.0];
        if ($po_id <= 0) { return $out; }

        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(qty_ordered  * unit_cost), 0),
                   COALESCE(SUM(qty_received * unit_cost), 0),
                   COALESCE(SUM(GREATEST(qty_ordered - qty_received, 0) * unit_cost), 0)
            FROM purchase_order_items WHERE po_id = ?
        ");
        $stmt->bind_param('i', $po_id);
        $stmt->execute();
        [$ordered, $received, $outstanding] = $stmt->get_result()->fetch_row();

        return [
            'ordered'     => (float)$ordered,
            'received'    => (float)$received,
            'outstanding' => (float)$outstanding,
        ];
    }
}

/**
 * The status a purchase order's lines say it should be in.
 *
 * Derived from the lines rather than assigned, so the badge can never drift
 * from the quantities beneath it. Only ever returns one of the two delivery
 * states — Draft, Ordered and Cancelled are decisions a person makes, not
 * facts the lines can tell you.
 */
if (!function_exists('po_status_from_lines')) {
    function po_status_from_lines(mysqli $conn, int $po_id): string {
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM purchase_order_items
            WHERE po_id = ? AND qty_received < qty_ordered
        ");
        $stmt->bind_param('i', $po_id);
        $stmt->execute();
        $short = (int)$stmt->get_result()->fetch_row()[0];
        return $short === 0 ? 'Received' : 'Partially Received';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/purchase_order_test.php`
Expected: `ALL PASS`, now with 16 assertions.

- [ ] **Step 5: Commit**

```bash
git add config.php tests/purchase_order_test.php
git commit -m "feat(procurement): derive PO received value and status from lines"
```

---

### Task 3: The receive transaction

**Files:**
- Modify: `purchase_order_view.php:39-84` (replace the `mark_received` block)
- Test: `tests/purchase_order_test.php`

**Interfaces:**
- Consumes: `po_status_from_lines()` from Task 2
- Produces:
  - `po_receive_line(mysqli $conn, int $po_id, int $poi_id, float $seen, float $qty, ?string $by, string $po_num): bool` in `config.php` — claims one line, moves stock by `$qty`, writes the ledger rows. Returns `false` when the line moved since the form was rendered.
  - POST action `receive_items` in `purchase_order_view.php`, reading `$_POST['recv'][<poi_id>]` (quantity being received now) and `$_POST['seen'][<poi_id>]` (the `qty_received` the form was rendered against). Redirect messages: `received` (all lines complete), `partial` (some outstanding), `stale` (a line moved since load), `nothing` (every box zero), `badqty` (a negative was submitted), `error` (transaction rolled back).

> **Why the arithmetic lives in `config.php` and not inline in the POST handler:**
> a test cannot drive the handler — it requires a session, and every path ends
> in `header()` + `exit`. If the test reimplemented the claim-and-move logic it
> would be asserting a copy, and could pass green while the real handler was
> wrong. One function, called by both.

- [ ] **Step 1: Write the failing test**

Append to `tests/purchase_order_test.php`, before the final `echo` line:

```php
echo "receive arithmetic\n";
// Build a throwaway PO so the assertions never depend on demo data, and so a
// failed run cannot corrupt a real order. Ingredient 48 is Milk base.
$conn->query("INSERT INTO purchase_orders (po_number, supplier_id, status, total_cost, created_by)
              SELECT 'PO-TEST', MIN(supplier_id), 'Ordered', 20.00, 'test' FROM suppliers");
$testPo = (int)$conn->insert_id;
$conn->query("INSERT INTO purchase_order_items (po_id, ingredient_id, qty_ordered, unit_cost)
              VALUES ($testPo, 48, 10.000, 2.00)");
$testPoi = (int)$conn->insert_id;
$before  = (float)$conn->query("SELECT stock_quantity FROM ingredients WHERE ingredient_id=48")
                        ->fetch_row()[0];
$histBefore = (int)$conn->query("SELECT COUNT(*) FROM ingredient_history
                                 WHERE change_type='po_received'")->fetch_row()[0];

check('first receive of 6 is claimed',
      po_receive_line($conn, $testPo, $testPoi, 0.0, 6.0, 'test', 'PO-TEST'), true);
$after1 = (float)$conn->query("SELECT stock_quantity FROM ingredients WHERE ingredient_id=48")->fetch_row()[0];
check('stock moved by exactly 6',       round($after1 - $before, 3),  6.0);
check('PO is short after a part delivery',
      po_status_from_lines($conn, $testPo), 'Partially Received');

// The regression this whole design is most likely to reintroduce: the same
// form submitted twice must move stock once. The replay still claims
// qty_received = 0, which no longer matches, so it is refused.
check('a replayed submit is refused',
      po_receive_line($conn, $testPo, $testPoi, 0.0, 6.0, 'test', 'PO-TEST'), false);
$after2 = (float)$conn->query("SELECT stock_quantity FROM ingredients WHERE ingredient_id=48")->fetch_row()[0];
check('stock did not move twice',       round($after2 - $before, 3),  6.0);

check('topping up the last 4 is claimed',
      po_receive_line($conn, $testPo, $testPoi, 6.0, 4.0, 'test', 'PO-TEST'), true);
$after3 = (float)$conn->query("SELECT stock_quantity FROM ingredients WHERE ingredient_id=48")->fetch_row()[0];
check('stock moved by exactly 4 more',  round($after3 - $after1, 3),  4.0);
check('PO is complete once filled',     po_status_from_lines($conn, $testPo), 'Received');

// The ledger records the delta, not the ordered amount — two receives, two rows.
$histAfter = (int)$conn->query("SELECT COUNT(*) FROM ingredient_history
                                WHERE change_type='po_received'")->fetch_row()[0];
check('one ledger row per successful receive', $histAfter - $histBefore, 2);
$amounts = [];
$hr = $conn->query("SELECT amount FROM ingredient_history WHERE change_type='po_received'
                    ORDER BY id DESC LIMIT 2");
while ($x = $hr->fetch_row()) { $amounts[] = round((float)$x[0], 3); }
check('the ledger carries the deltas', $amounts, [4.0, 6.0]);

// A line belonging to a different PO must never be claimable through this one.
check('a poi_id from another PO is refused',
      po_receive_line($conn, $testPo + 99999, $testPoi, 10.0, 1.0, 'test', 'PO-TEST'), false);

$v = po_line_values($conn, $testPo);
check('received value equals ordered value once complete',
      round($v['received'], 2), 20.00);
check('nothing outstanding once complete', round($v['outstanding'], 2), 0.0);

// Clean up: put the stock back and drop the scratch PO and its ledger rows.
$conn->query("DELETE FROM ingredient_history WHERE reference = 'Received via PO-TEST'");
$conn->query("DELETE FROM stock_refills WHERE notes = 'Received via PO-TEST'");
$conn->query("UPDATE ingredients SET stock_quantity = $before WHERE ingredient_id = 48");
$conn->query("DELETE FROM purchase_order_items WHERE po_id = $testPo");
$conn->query("DELETE FROM purchase_orders WHERE po_id = $testPo");
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/purchase_order_test.php`
Expected: PHP fatal, `Call to undefined function po_receive_line()`.

- [ ] **Step 3: Write the shared receive helper**

Add to `config.php`, directly after `po_status_from_lines()`:

```php
/**
 * Record one line of a delivery: claim it, move the stock, write the ledger.
 *
 * $seen is the qty_received the form was rendered against. The UPDATE only
 * matches while the line still holds that value, so a double-click, a
 * back-button re-POST, or a second clerk on another till claims nothing and
 * gets false back.
 *
 * This replaces the old guard, which claimed the whole PO by flipping
 * status='Ordered' and checking affected_rows. That worked only because
 * receiving happened exactly once; partial receiving means receiving the same
 * PO repeatedly and legitimately, so the guard had to move down to the line.
 *
 * Stock moves by $qty — what arrived this time — never by qty_ordered. Adding
 * the ordered amount regardless of the delivery is what put stock in the
 * system that was never in the building.
 *
 * The caller owns the transaction: a false return must roll it back.
 */
if (!function_exists('po_receive_line')) {
    function po_receive_line(mysqli $conn, int $po_id, int $poi_id, float $seen,
                             float $qty, ?string $by, string $po_num): bool {
        if ($qty <= 0) { return false; }

        // po_id is in the WHERE so a poi_id belonging to another order cannot
        // be claimed through this one.
        $claim = $conn->prepare("UPDATE purchase_order_items
                                 SET qty_received = qty_received + ?
                                 WHERE poi_id = ? AND po_id = ? AND qty_received = ?");
        $claim->bind_param('diid', $qty, $poi_id, $po_id, $seen);
        $claim->execute();
        if ($claim->affected_rows !== 1) { return false; }

        $line = $conn->prepare("SELECT ingredient_id FROM purchase_order_items
                                WHERE poi_id = ? AND po_id = ?");
        $line->bind_param('ii', $poi_id, $po_id);
        $line->execute();
        $row = $line->get_result()->fetch_row();
        if (!$row) { return false; }
        $iid = (int)$row[0];

        $upd = $conn->prepare("UPDATE ingredients SET stock_quantity = stock_quantity + ?
                               WHERE ingredient_id = ?");
        $upd->bind_param('di', $qty, $iid);
        $upd->execute();

        $note = "Received via $po_num";
        $log  = $conn->prepare("INSERT INTO stock_refills (ingredient_id, purchase_qty, notes)
                                VALUES (?,?,?)");
        $log->bind_param('ids', $iid, $qty, $note);
        $log->execute();

        $hist = $conn->prepare("INSERT INTO ingredient_history
                                (ingredient_id, change_type, amount, reference, created_by)
                                VALUES (?, 'po_received', ?, ?, ?)");
        $hist->bind_param('idss', $iid, $qty, $note, $by);
        $hist->execute();

        return true;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/purchase_order_test.php`
Expected: `ALL PASS`, now with 29 assertions.

- [ ] **Step 5: Replace the mark_received handler**

In `purchase_order_view.php`, replace the entire `if ($action === 'mark_received') { ... }` block (lines 39-84) with:

```php
    if ($action === 'receive_items') {
        // Receiving is now per line and repeatable, so the old guard — claiming
        // the PO by flipping status='Ordered' and checking affected_rows — no
        // longer guards anything: the second receive of a partially delivered
        // order is legitimate. Each line is claimed individually instead,
        // against the qty_received the form was rendered with.
        $po_now = $conn->prepare("SELECT po_number, status FROM purchase_orders WHERE po_id=?");
        $po_now->bind_param('i', $po_id);
        $po_now->execute();
        $po_row = $po_now->get_result()->fetch_assoc();
        if (!$po_row || !in_array($po_row['status'], ['Ordered', 'Partially Received'], true)) {
            po_redirect($from_list, $po_id, 'nochange');
        }

        $recv = (array)($_POST['recv'] ?? []);
        $seen = (array)($_POST['seen'] ?? []);

        // A negative delivery is not a thing. Reject before opening the
        // transaction so nothing is half-applied.
        foreach ($recv as $qty) {
            if ($qty !== '' && (!is_numeric($qty) || (float)$qty < 0)) {
                po_redirect($from_list, $po_id, 'badqty');
            }
        }
        // A blank or non-numeric box means "none of this arrived", never "all
        // of it did" — the old code's assumption is what caused phantom stock.
        $moves = [];
        foreach ($recv as $poi_id => $qty) {
            $q = is_numeric($qty) ? (float)$qty : 0.0;
            if ($q > 0) { $moves[(int)$poi_id] = $q; }
        }
        if (!$moves) { po_redirect($from_list, $po_id, 'nothing'); }

        $conn->begin_transaction();
        try {
            $by     = $_SESSION['username'] ?? null;
            $po_num = $po_row['po_number'];

            foreach ($moves as $poi_id => $qty) {
                // A missing 'seen' field must never look like a valid claim.
                // -1 can never equal a stored qty_received, so the line is
                // refused rather than blindly topped up.
                $was = isset($seen[$poi_id]) && is_numeric($seen[$poi_id])
                     ? (float)$seen[$poi_id] : -1.0;

                // Someone received against this line between the page render
                // and this POST — a double-click, a back-button re-POST, or a
                // second clerk. Abandon the whole delivery rather than apply
                // part of it.
                if (!po_receive_line($conn, $po_id, (int)$poi_id, $was, $qty, $by, $po_num)) {
                    $conn->rollback();
                    po_redirect($from_list, $po_id, 'stale');
                }
            }

            // Status is derived from the lines, never assigned, so the badge
            // cannot drift from the quantities under it.
            $new = po_status_from_lines($conn, $po_id);
            $st  = $conn->prepare("UPDATE purchase_orders
                                   SET status = ?,
                                       received_at = CASE WHEN ? = 'Received' THEN NOW() ELSE received_at END
                                   WHERE po_id = ?");
            $st->bind_param('ssi', $new, $new, $po_id);
            $st->execute();

            $conn->commit();
            po_redirect($from_list, $po_id, $new === 'Received' ? 'received' : 'partial');
        } catch (Throwable $e) {
            $conn->rollback();
            po_redirect($from_list, $po_id, 'error');
        }
    }
```

- [ ] **Step 6: Verify the file parses and the tests still pass**

Run: `php -l purchase_order_view.php && php tests/purchase_order_test.php`
Expected: `No syntax errors detected`, then `ALL PASS`.

- [ ] **Step 7: Confirm the old handler is gone**

Run: `grep -n "mark_received\|qty_ordered" purchase_order_view.php`
Expected: no `mark_received` anywhere, and `qty_ordered` appears only in the
render path — never in a statement that writes `stock_quantity`. Adding
`qty_ordered` to stock is the bug this task exists to remove.

- [ ] **Step 8: Commit**

```bash
git add config.php purchase_order_view.php tests/purchase_order_test.php
git commit -m "feat(procurement): receive purchase orders line by line"
```

---

### Task 4: The receive form

**Files:**
- Modify: `purchase_order_view.php` — items table (lines ~367-400), header cost summary (~357-364), action bar (~294-300), message map

**Interfaces:**
- Consumes: `receive_items` action and its message codes from Task 3; `po_line_values()` from Task 2
- Produces: a `<form method="POST">` wrapping the items table, emitting `recv[<poi_id>]` and `seen[<poi_id>]` for every incomplete line

- [ ] **Step 1: Replace the Mark Received button with a status gate**

In the action bar, replace the `if ($po['status'] === 'Ordered')` block containing `mark_received` with nothing — the receive control now lives in the items table, so the delivery is recorded against the lines the clerk is reading. Leave the `Draft` → `Mark Ordered` and the `Cancel PO` blocks untouched.

- [ ] **Step 2: Add the received figures to the cost summary**

Replace the Cost Summary `info-block` body with:

```php
                <div class="info-val">
                    <strong style="font-size:20px;color:var(--accent)">$<?= number_format($po['total_cost'],2) ?></strong>
                    <span style="color:var(--text-muted);font-size:12px">ordered</span><br>
                    <?php $vals = po_line_values($conn, $po_id);
                          /* total_cost is the order that was placed and is never
                             rewritten. The delivered value is derived, so a short
                             delivery stays distinguishable from a small order. */
                          if ($po['status'] === 'Partially Received' || $po['closed_short']): ?>
                    <span style="font-size:13px">Received <strong>$<?= number_format($vals['received'],2) ?></strong></span>
                    <span style="color:var(--warning,#e0a955);font-size:12px">
                        · $<?= number_format($vals['outstanding'],2) ?> outstanding
                    </span><br>
                    <?php endif; ?>
                    <span style="color:var(--text-muted);font-size:12px"><?= count($items) ?> ingredient<?= count($items)!=1?'s':'' ?></span>
                </div>
```

- [ ] **Step 3: Rebuild the items table as a form**

Replace the whole `<div class="items-card">` block with:

```php
    <?php
      // The form renders for exactly two statuses. Draft has not been placed;
      // Received and Cancelled are finished. Task 3's handler repeats this
      // check rather than trusting the markup.
      $canReceive = in_array($po['status'], ['Ordered', 'Partially Received'], true);
    ?>
    <div class="items-card">
        <div class="items-card-header"><i class="fa-solid fa-boxes-stacked"></i> Order Items</div>
        <?php if ($canReceive): ?>
        <form method="POST" id="receiveForm">
        <input type="hidden" name="action" value="receive_items">
        <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
        <?php endif; ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Ingredient</th>
                    <th>Unit</th>
                    <th style="text-align:right">Ordered</th>
                    <th style="text-align:right">Received</th>
                    <?php if ($canReceive): ?><th style="text-align:right">Receiving now</th><?php endif; ?>
                    <th style="text-align:right">Unit Cost</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $i => $item):
                $ordered     = (float)$item['qty_ordered'];
                $received    = (float)$item['qty_received'];
                $outstanding = max(0, $ordered - $received);
                $over        = $received > $ordered;
            ?>
            <tr>
                <td style="color:var(--text-muted);font-size:12px"><?= $i+1 ?></td>
                <td><span class="ing-name"><?= he($item['ingredient_name']) ?></span></td>
                <td><span class="unit-pill"><?= he($item['unit']) ?></span></td>
                <td style="text-align:right;font-weight:600"><?= number_format($ordered,3) ?></td>
                <td style="text-align:right;font-weight:600<?= $over ? ';color:var(--warning,#e0a955)' : '' ?>">
                    <?= number_format($received,3) ?>
                    <?php if ($over): ?><br><span style="font-size:11px">+<?= number_format($received-$ordered,3) ?> over</span><?php endif; ?>
                </td>
                <?php if ($canReceive): ?>
                <td style="text-align:right">
                    <?php if ($outstanding > 0): ?>
                        <?php /* Prefilled with the outstanding quantity: a full
                                 delivery is the normal case and stays one click,
                                 so typing is reserved for the exception. */ ?>
                        <input type="number" step="0.001" min="0"
                               name="recv[<?= (int)$item['poi_id'] ?>]"
                               value="<?= number_format($outstanding, 3, '.', '') ?>"
                               style="width:96px;text-align:right;padding:6px 8px;border-radius:6px;
                                      border:1px solid var(--border,#333);background:var(--card,#1a1a1a);
                                      color:inherit;">
                        <?php /* The value the form was rendered against. Task 3
                                 claims the line on this, so a replayed POST is
                                 refused instead of adding stock twice. */ ?>
                        <input type="hidden" name="seen[<?= (int)$item['poi_id'] ?>]"
                               value="<?= number_format($received, 3, '.', '') ?>">
                        <div style="font-size:11px;color:var(--text-muted);margin-top:3px">
                            <?= number_format($outstanding,3) ?> outstanding
                        </div>
                    <?php else: ?>
                        <span style="color:var(--success);font-size:12px">
                            <i class="fa-solid fa-check"></i> complete
                        </span>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
                <td style="text-align:right;color:var(--text-muted)">$<?= number_format($item['unit_cost'],2) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($canReceive): ?>
        <div class="total-bar">
            <div class="total-bar-inner" style="gap:12px">
                <div class="total-bar-label">Record what actually arrived</div>
                <button type="submit" class="btn btn-success">
                    <i class="fa-solid fa-truck-ramp-box"></i> Confirm delivery
                </button>
            </div>
        </div>
        </form>
        <?php endif; ?>
        <div class="total-bar">
            <div class="total-bar-inner">
                <div class="total-bar-label">Total Cost (ordered)</div>
                <div class="total-bar-value">$<?= number_format($po['total_cost'],2) ?></div>
            </div>
        </div>
    </div>
```

- [ ] **Step 4: Add the new redirect messages**

In `purchase_orders.php`, extend the `$msgs` map (line ~9) with:

```php
    'partial'   => ['text'=>'Delivery recorded. Some items are still outstanding.', 'type'=>'info'],
    'stale'     => ['text'=>'This purchase order changed while you were looking at it. Reload and try again.', 'type'=>'danger'],
    'nothing'   => ['text'=>'Nothing was recorded — every quantity was zero.',      'type'=>'info'],
    'badqty'    => ['text'=>'A received quantity cannot be negative.',              'type'=>'danger'],
    'error'     => ['text'=>'The delivery could not be saved. Nothing was changed.','type'=>'danger'],
    'shortclosed' => ['text'=>'Purchase order closed short. The outstanding items were written off.', 'type'=>'info'],
```

Find the equivalent message map in `purchase_order_view.php` and add the same six entries so the detail page shows them too.

- [ ] **Step 5: Verify in the browser**

Run: `php -l purchase_order_view.php && php -l purchase_orders.php`

Then log in as `Clerk_Sokun` / `@Clerksokun9811`, open an `Ordered` PO, change one line's box to less than the outstanding amount, and Confirm delivery.
Expected: PO badge reads `Partially Received`; the changed line shows the smaller received quantity with the remainder outstanding; the untouched lines read `complete`; `ingredient_history` has one `po_received` row per line carrying the delta, not the ordered amount.

Verify the ledger:
```bash
php -r "require 'config.php';
\$r=\$conn->query(\"SELECT ingredient_id, amount, reference, created_at FROM ingredient_history WHERE change_type='po_received' ORDER BY id DESC LIMIT 5\");
while(\$x=\$r->fetch_assoc()) echo implode(' | ', \$x), \"\n\";"
```

- [ ] **Step 6: Commit**

```bash
git add purchase_order_view.php purchase_orders.php
git commit -m "feat(procurement): per-line receive form on the PO page"
```

---

### Task 5: Close short

**Files:**
- Modify: `purchase_order_view.php` — POST handler (after the `receive_items` block) and action bar

**Interfaces:**
- Consumes: `closed_short` columns from Task 1
- Produces:
  - `po_may_close_short(?string $role): bool` in `config.php` — the role gate, callable by both the handler and the test
  - POST action `close_short`; redirect messages `shortclosed` and `denied`; `$is_manager` boolean available to the action bar

- [ ] **Step 1: Write the failing test**

Append to `tests/purchase_order_test.php`, before the final `echo`:

```php
echo "close short\n";
// Closing short writes off goods that were paid for, so it is a commercial
// decision, not a counting one. Only admin and manager may do it — the same
// split as stock_count.php, where the clerk counts and a manager applies.
//
// This asserts the production function, not a copy of the role list. A test
// that redeclares the rule it is checking passes whatever the real code does.
check('admin may close short',            po_may_close_short('admin'),           true);
check('manager may close short',          po_may_close_short('manager'),         true);
check('inventory clerk may not',          po_may_close_short('inventory_clerk'), false);
check('cashier may not',                  po_may_close_short('staff'),           false);
check('barista may not',                  po_may_close_short('barista'),         false);
check('a missing role may not',           po_may_close_short(null),              false);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/purchase_order_test.php`
Expected: PHP fatal, `Call to undefined function po_may_close_short()`.

- [ ] **Step 3: Add the role gate**

Add to `config.php`, after `po_receive_line()`:

```php
/**
 * Who may write off the undelivered part of a purchase order.
 *
 * Closing short abandons goods that were ordered and, on most terms, will still
 * be invoiced — a commercial decision rather than a counting one. It is gated on
 * the role and not on the purchase_orders permission, which the clerk who
 * receives deliveries already holds. Mirrors stock_count.php: the clerk counts
 * what is there, a manager commits the consequence.
 */
if (!function_exists('po_may_close_short')) {
    function po_may_close_short(?string $role): bool {
        return in_array($role, ['admin', 'manager'], true);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/purchase_order_test.php`
Expected: `ALL PASS`.

- [ ] **Step 5: Add the handler**

In `purchase_order_view.php`, immediately after the `receive_items` block:

```php
    if ($action === 'close_short') {
        // Writing off undelivered goods is a commercial decision, so it is
        // gated on the role and not on the purchase_orders permission the
        // clerk already holds. Checked here rather than only in the markup —
        // hiding a button is not access control.
        if (!po_may_close_short($_SESSION['role'] ?? null)) {
            po_redirect($from_list, $po_id, 'denied');
        }
        // No stock is added. This records that the remainder is never coming,
        // which is the opposite of a delivery.
        $by   = $_SESSION['username'] ?? null;
        $stmt = $conn->prepare("UPDATE purchase_orders
                                SET status='Received', closed_short=1,
                                    closed_short_at=NOW(), closed_short_by=?,
                                    received_at=COALESCE(received_at, NOW())
                                WHERE po_id=? AND status='Partially Received'");
        $stmt->bind_param('si', $by, $po_id);
        $stmt->execute();
        if ($stmt->affected_rows === 0) { po_redirect($from_list, $po_id, 'nochange'); }
        po_redirect($from_list, $po_id, 'shortclosed');
    }
```

Add the `denied` message to both message maps:

```php
    'denied' => ['text'=>'You do not have permission to close a purchase order short.', 'type'=>'danger'],
```

- [ ] **Step 6: Add the button**

Near the top of the render section of `purchase_order_view.php` (after `$po` is fetched), add:

```php
$is_manager = po_may_close_short($_SESSION['role'] ?? null);
```

Then in the action bar, after the Cancel PO block:

```php
        <?php if ($po['status'] === 'Partially Received' && $is_manager): ?>
        <form method="POST" style="display:inline"
              onsubmit="return confirm('Close this order short? The outstanding items will be written off and no stock will be added.')">
            <input type="hidden" name="action" value="close_short">
            <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
            <button type="submit" class="btn btn-danger"><i class="fa-solid fa-file-circle-xmark"></i> Close short</button>
        </form>
        <?php endif; ?>
```

- [ ] **Step 7: Show the write-off after the fact**

In the PO header meta block, after the `received_at` span:

```php
                    <?php if ($po['closed_short']): ?>
                    <span style="color:var(--warning,#e0a955)">
                        <i class="fa-solid fa-file-circle-xmark"></i>
                        Closed short <?= fmtDate($po['closed_short_at']) ?> by <?= he($po['closed_short_by']) ?>
                    </span>
                    <?php endif; ?>
```

- [ ] **Step 8: Verify the gate holds against a direct POST**

Run: `php -l purchase_order_view.php && php tests/purchase_order_test.php`

Log in as `Clerk_Sokun`, open a `Partially Received` PO.
Expected: no `Close short` button.

Then confirm the server refuses it even when the button is bypassed. Hiding a
button is not access control, and this is the one action in the feature that
writes off money.

```bash
# Find a Partially Received PO to aim at.
PO=$(php -r "require 'config.php';
  \$r=\$conn->query(\"SELECT po_id FROM purchase_orders WHERE status='Partially Received' LIMIT 1\");
  \$x=\$r->fetch_row(); echo \$x ? \$x[0] : '';")
echo "targeting PO \$PO"

curl -sk -c c.txt -b c.txt -X POST \
     -d "username=Clerk_Sokun&password=@Clerksokun9811" \
     https://localhost/Cafe/login.php -o /dev/null
curl -sk -b c.txt -c c.txt "https://localhost/Cafe/purchase_order_view.php?po_id=$PO" -o p.html
TOKEN=$(grep -o 'name="csrf_token" value="[a-f0-9]\{64\}"' p.html | head -1 | grep -o '[a-f0-9]\{64\}')
curl -sk -b c.txt -X POST -d "action=close_short&csrf_token=$TOKEN" \
     "https://localhost/Cafe/purchase_order_view.php?po_id=$PO" -o /dev/null -w "%{redirect_url}\n"
```

Expected: the redirect URL ends `msg=denied`. Then confirm nothing moved:

```bash
php -r "require 'config.php';
  \$r=\$conn->query('SELECT status, closed_short FROM purchase_orders WHERE po_id=$PO');
  print_r(\$r->fetch_assoc());"
```
Expected: still `Partially Received`, `closed_short` still `0`.

If there is no `Partially Received` PO yet, create one first by running Task 4's
browser step, or the `$PO` variable will be empty and the curl will 404.

- [ ] **Step 9: Commit**

```bash
git add purchase_order_view.php tests/purchase_order_test.php
git commit -m "feat(procurement): manager can close a short purchase order"
```

---

### Task 6: The purchase orders list

**Files:**
- Modify: `purchase_orders.php` — `$allowed` filter list (line ~18), `$statusColors` (~61), status tabs (~235), action buttons (~300-312), `Total Received` stat (~24-30)

**Interfaces:**
- Consumes: `po_line_values()` from Task 2; `Partially Received` status from Task 1
- Produces: nothing consumed downstream

- [ ] **Step 1: Add the status to the filter, colours and tabs**

Line ~18:
```php
$allowed = ['all','Draft','Ordered','Partially Received','Received','Cancelled'];
```

In `$statusColors`, after the `Ordered` entry:
```php
    // Amber, not red: a part delivery needs attention, it is not a failure.
    'Partially Received' => ['bg'=>'rgba(224,169,85,.14)', 'color'=>'#e0a955', 'icon'=>'fa-truck-ramp-box'],
```

In the status tabs array, after `Ordered`:
```php
        'Partially Received' => ['label'=>'Part delivered', 'icon'=>'fa-truck-ramp-box'],
```

**`purchase_order_view.php` has its own `$statusColors` map** at line ~162, and it
needs the identical entry. It is a separate array from the one above — not a
shared constant — so adding it in only one file leaves the other showing a
`Partially Received` PO in Draft's grey with a pen icon, because line ~168 falls
back with `?? $statusColors['Draft']`. Add the same line after its `Ordered`
entry:

```php
    // Amber, not red: a part delivery needs attention, it is not a failure.
    'Partially Received' => ['bg'=>'rgba(224,169,85,.14)', 'color'=>'#e0a955', 'icon'=>'fa-truck-ramp-box'],
```

After this step, load a `Partially Received` PO on both screens and confirm the
badge is amber with a truck icon in each, not grey with a pen.

- [ ] **Step 2: Turn the one-click receive into a link**

Replace the `elseif ($po['status'] === 'Ordered')` form containing `mark_received` with:

```php
                        <?php elseif (in_array($po['status'], ['Ordered','Partially Received'], true)): ?>
                        <?php /* Receiving needs a quantity per line, so it happens on
                                 the PO page where the lines are rendered. Keeping one
                                 caller for the stock arithmetic is the point. */ ?>
                        <a class="btn btn-success btn-sm" href="purchase_order_view.php?po_id=<?= (int)$po['po_id'] ?>">
                            <i class="fa-solid fa-truck-ramp-box"></i> Receive
                        </a>
```

Delete the now-unreferenced `mark_received` entry from the confirm-dialog JavaScript at the bottom of the file (the block around line 440 whose `message` reads `'Mark ' + po + ' as received.'`).

- [ ] **Step 3: Make Total Received mean money actually spent**

Replace the stats query block (~line 23-30) with:

```php
$sres = $conn->query("SELECT status, COUNT(*) AS cnt, IFNULL(SUM(total_cost),0) AS tot
                      FROM purchase_orders GROUP BY status");
$allTotal = 0; $allCount = 0; $pendingCount = 0;
while ($sr = $sres->fetch_assoc()) {
    $stats[$sr['status']] = $sr;
    $allCount += $sr['cnt'];
    $allTotal += $sr['tot'];
    if (in_array($sr['status'], ['Draft','Ordered','Partially Received'])) $pendingCount += $sr['cnt'];
}

// Money actually spent, not money ordered. Summing total_cost here counted the
// full value of any order that arrived short.
$receivedTotal = (float)$conn->query("
    SELECT IFNULL(SUM(poi.qty_received * poi.unit_cost), 0)
    FROM purchase_order_items poi
    JOIN purchase_orders p ON p.po_id = poi.po_id
    WHERE p.status IN ('Received','Partially Received')
")->fetch_row()[0];
```

Keep the `$stats` initialisation that precedes this block exactly as it is.

- [ ] **Step 4: Verify against the recorded baseline**

Run: `php -l purchase_orders.php`

Then confirm `total_cost` is untouched since Task 1 Step 5:
```bash
php -r "require 'config.php';
\$r=\$conn->query('SELECT po_id,total_cost FROM purchase_orders ORDER BY po_id');
while(\$x=\$r->fetch_row()) echo implode(' ',\$x),\"\n\";"
```
Expected: identical to the output recorded in Task 1 Step 5, row for row.

Load `purchase_orders.php` as a manager.
Expected: a `Part delivered` tab appears with the correct count; `Total Received` is lower than the sum of ordered values whenever any PO is short; the `Receive` button on an `Ordered` row opens the PO instead of receiving in one click.

- [ ] **Step 5: Run the whole suite**

Run: `php tests/purchase_order_test.php && php tests/daily_report_test.php`
Expected: `ALL PASS` from both. The daily-report suite is included because Task 2 added functions to `config.php`, which it also loads.

- [ ] **Step 6: Commit**

```bash
git add purchase_orders.php
git commit -m "feat(procurement): show part-delivered POs and real received value"
```

---

## Manual verification before calling this done

Walk the whole flow once, in this order, as the spec's verification section requires:

1. As `Clerk_Sokun`, create nothing — open an existing `Ordered` PO and receive one line short. Confirm the badge flips to `Partially Received` and stock rose by the delta only.
2. Reload that PO and receive the remainder. Confirm the badge flips to `Received` and stock rose by the second delta only.
3. On a fresh `Ordered` PO, submit the receive form, then press the browser Back button and submit again. Confirm the second attempt lands on `msg=stale` and stock moved exactly once.
4. As a manager, on a `Partially Received` PO, use `Close short`. Confirm status becomes `Received`, the header shows who closed it and when, and **stock did not change**.
5. Confirm `ingredient_history` shows one `po_received` row per received line carrying the real delta, and that `Total Received` on the list equals the sum of received values.
