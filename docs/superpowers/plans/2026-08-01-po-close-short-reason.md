# PO Close-Short Reason Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Record *why* a manager wrote off the undelivered part of a purchase order, and surface that reason wherever the write-off is visible.

**Architecture:** Two additive columns on `purchase_orders` (a stable reason code plus a free-text note), one shared helper listing the valid codes so the dropdown and the validator cannot drift, a modal replacing the native `confirm()`, and the reason written inside the `UPDATE` that already guards the status transition. Display is a permanent amber strip on the PO page and a small chip on the list.

**Tech Stack:** PHP 8.2 + mysqli (procedural page scripts, no framework), vanilla JS, Poppins/FontAwesome. No test framework — `tests/*.php` are CLI scripts run with `php tests/<name>.php`.

## Global Constraints

- **The reason column stores a code, never the display text.** Relabelling an option later must not orphan history, and the column must be groupable.
- **The reason is written in the existing `UPDATE`**, which already carries `WHERE po_id=? AND status='Partially Received'` and an `affected_rows` check. Do not add a second statement — a PO that stopped being `Partially Received` between render and POST must not end up with a reason attached to a state change that never happened.
- **Close short adds no stock.** It is the opposite of a delivery. Nothing in this plan may call `po_receive_line()`.
- **`purchase_orders.php` and `purchase_order_view.php` keep SEPARATE `$statusColors` arrays and separate label maps.** This change touches presentation on both. Editing one and not the other is the known trap from the partial-receiving work.
- **Amber, not red**, for anything close-short. A write-off needs attention; it is not an error state. Existing comment in both files records this.
- `he()` is defined per-page in this codebase, not in `config.php` — `purchase_order_view.php:208` has its own. Never assume it is in scope.
- Escaping: every rendered reason and note goes through `he()`. The note is free text typed by staff.

---

### Task 1: Repair the two failing purchase-order assertions

`tests/purchase_order_test.php` currently reports 2 failures. Both are the test being wrong, not the code — and one of them has been passing *vacuously*, which is worse. Fixing this first means the rest of the plan lands against a green suite.

**Findings that drive this task:**
- `historical Received POs are backfilled in full` asserts `qty_received = qty_ordered` for every `Received` PO. Three rows now violate it: PO-2026-009 (2100 of 2000) and PO-2026-021 (900 of 800) are **over-deliveries**, which the feature explicitly supports (`purchase_order_view.php:486` renders a `+N over` branch), and PO-2026-022 is **`closed_short = 1`**, where a shortfall is the entire point.
- `an untouched PO reads Partially Received` selects `WHERE status='Ordered' ORDER BY po_id LIMIT 1`. **There are zero `Ordered` POs in the database.** `fetch_row()` returns null, `(int)` of the offset yields `0`, and the test then asserts against PO id 0. `po_line_values()` returns all zeroes for an unknown PO, so the two checks above it pass for the wrong reason, and `po_status_from_lines()` returns `Received` for a PO with no lines — the visible failure.

**Files:**
- Modify: `tests/purchase_order_test.php:37-42`, `:63-70`, `:77-78`

**Interfaces:**
- Consumes: `po_line_values()`, `po_status_from_lines()` — unchanged.
- Produces: nothing.

- [ ] **Step 1: Confirm the failures and their causes**

Run: `php tests/purchase_order_test.php`
Expected: `2 FAILURE(S)` — `historical Received POs are backfilled in full` (got 3) and `an untouched PO reads Partially Received` (got `'Received'`).

Confirm there are no `Ordered` POs, which is what makes the second one vacuous:

```bash
/c/xampp/mysql/bin/mysql.exe -u root db_coffee -e \
  "SELECT COUNT(*) AS ordered_pos FROM purchase_orders WHERE status='Ordered';"
```

Expected: `0`.

- [ ] **Step 2: Fix the backfill assertion**

Replace `tests/purchase_order_test.php:35-42`:

```php
echo "backfill\n";
// Every PO that was already Received predates this feature and was, by
// definition, received in full. Left at 0 they would all read as shortfalls.
//
// Two legitimate exceptions, both added after this assertion was written:
//   - an over-delivery (qty_received > qty_ordered) is supported and rendered
//     as "+N over"; only a SHORTFALL indicates a missed backfill
//   - a closed-short PO is Received *with* a shortfall on purpose
$bad = (int)$conn->query("
    SELECT COUNT(*) FROM purchase_order_items poi
    JOIN purchase_orders p ON p.po_id = poi.po_id
    WHERE p.status = 'Received'
      AND p.closed_short = 0
      AND poi.qty_received < poi.qty_ordered
")->fetch_row()[0];
check('historical Received POs are backfilled in full', $bad, 0);
```

- [ ] **Step 3: Run to confirm the first failure is gone**

Run: `php tests/purchase_order_test.php`
Expected: `1 FAILURE(S)` — only `an untouched PO reads Partially Received` remains.

- [ ] **Step 4: Delete the ambient `$notYet` lookup**

Delete `tests/purchase_order_test.php:63-70` and `:77-78` entirely — the block starting
`// An Ordered PO has been placed but not delivered` through the
`an undelivered PO is fully outstanding` check, and the
`an untouched PO reads Partially Received` check at the bottom of the
`po_status_from_lines` section.

Leave `check('an unknown PO is all zeroes', ...)` at `:72-73` — that one deliberately
passes `0` and is testing exactly what it claims.

After deleting, `po_status_from_lines` section reads:

```php
echo "po_status_from_lines\n";
check('a fully received PO reads Received', po_status_from_lines($conn, $anyReceived), 'Received');
```

- [ ] **Step 5: Re-assert those three properties against the fixture PO instead**

The transaction block at `:91` already inserts a throwaway PO as `'Ordered'` with one
line and `qty_received` defaulting to 0 — that *is* an untouched Ordered PO, and it
exists regardless of what the demo data looks like.

Insert immediately after the `$histBefore` assignment (currently `:103-104`), before
the `first receive of 6 is claimed` check:

```php
    // An Ordered PO has been placed but not delivered: nothing received, all
    // outstanding. Asserted against the fixture rather than whatever demo PO
    // happens to exist — the previous ambient lookup silently resolved to po_id 0
    // once every real PO had been received, and passed vacuously.
    $v2 = po_line_values($conn, $testPo);
    check('an undelivered PO has received 0',     round($v2['received'], 2), 0.0);
    check('an undelivered PO is fully outstanding',
          round($v2['outstanding'] - $v2['ordered'], 2),                     0.0);
    check('an untouched PO reads Partially Received',
          po_status_from_lines($conn, $testPo), 'Partially Received');
```

- [ ] **Step 6: Run to confirm the suite is green**

Run: `php tests/purchase_order_test.php`
Expected: `ALL PASS`, and the three re-homed checks appear under `receive arithmetic`.

- [ ] **Step 7: Commit**

```bash
git add tests/purchase_order_test.php
git commit -m "test(po): assert against a fixture instead of ambient demo data"
```

---

### Task 2: Schema and the reason list

**Files:**
- Modify: `config.php` (migration beside `po_partial_receive_v1`; helper beside `po_may_close_short()`)
- Test: `tests/purchase_order_test.php` (extend)

**Interfaces:**
- Produces: `po_short_reasons(): array` — ordered map of code => label. Keys: `supplier_oos`, `damaged`, `never_arrived`, `supplier_cancel`, `other`.
- Produces: columns `purchase_orders.closed_short_reason` VARCHAR(40) NOT NULL DEFAULT `''`, `purchase_orders.closed_short_note` VARCHAR(255) NULL.
- Consumed by Tasks 3 and 4.

- [ ] **Step 1: Write the failing test**

Append to `tests/purchase_order_test.php`, immediately after the `backfill` section:

```php
echo "close-short reason\n";
foreach (['closed_short_reason', 'closed_short_note'] as $c) {
    check("$c exists",
          $conn->query("SHOW COLUMNS FROM purchase_orders LIKE '$c'")->num_rows === 1, true);
}
$rc = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'closed_short_reason'")->fetch_assoc();
check('reason is NOT NULL',        $rc['Null'],    'NO');
check('reason defaults to empty',  $rc['Default'], '');

$reasons = po_short_reasons();
check('reason list is not empty',  count($reasons) > 0,            true);
check('other is offered',          isset($reasons['other']),       true);
check('supplier_oos is offered',   isset($reasons['supplier_oos']), true);
// A code longer than the column would be silently truncated on write, and the
// truncated value would then fail every lookup.
$tooLong = array_filter(array_keys($reasons), fn($k) => strlen($k) > 40);
check('every code fits the column', $tooLong, []);
// Labels are for humans; codes are the stored value and must never contain
// anything that would need escaping in a value= attribute.
$badCode = array_filter(array_keys($reasons), fn($k) => !preg_match('/^[a-z_]+$/', $k));
check('codes are plain identifiers', $badCode, []);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/purchase_order_test.php`
Expected: FAIL — `Call to undefined function po_short_reasons()`

- [ ] **Step 3: Add the migration**

In `config.php`, immediately after the `po_partial_receive_v1` migration block:

```php
_migrate($conn, 'po_close_short_reason_v1', function($db) {
    // Why the remainder was written off. Stored as a stable code so relabelling
    // an option later cannot orphan history and so write-offs can be grouped.
    // No backfill: there is nothing to guess at, and '' renders honestly as
    // "No reason recorded".
    $db->query("ALTER TABLE purchase_orders
                ADD COLUMN IF NOT EXISTS closed_short_reason VARCHAR(40) NOT NULL DEFAULT ''");
    $db->query("ALTER TABLE purchase_orders
                ADD COLUMN IF NOT EXISTS closed_short_note VARCHAR(255) NULL");
});
```

- [ ] **Step 4: Add the reason list**

In `config.php`, immediately after the `po_may_close_short()` block:

```php
/**
 * Why the undelivered part of a purchase order was written off.
 *
 * One array, read by both the dropdown that offers these and the handler that
 * validates the submission, so the two cannot drift. Keys are stored in
 * purchase_orders.closed_short_reason and must stay stable — the labels are
 * free to change, the codes are not.
 */
if (!function_exists('po_short_reasons')) {
    function po_short_reasons(): array {
        return [
            'supplier_oos'    => 'Supplier out of stock',
            'damaged'         => 'Damaged in transit',
            'never_arrived'   => 'Delivery never arrived',
            'supplier_cancel' => 'Cancelled by supplier',
            'other'           => 'Other',
        ];
    }
}

/**
 * The human label for a stored reason code.
 *
 * Falls back to the raw code rather than blanking, so a row written by an older
 * or newer version of the list stays readable instead of looking like no reason
 * was given at all.
 */
if (!function_exists('po_short_reason_label')) {
    function po_short_reason_label(?string $code): string {
        $code = trim((string)$code);
        if ($code === '') return 'No reason recorded';
        return po_short_reasons()[$code] ?? $code;
    }
}
```

- [ ] **Step 5: Run the migration and the test**

The migration runs on the next page load that includes `config.php`; the test
script includes it directly, so simply running the test applies it.

Run: `php tests/purchase_order_test.php`
Expected: `ALL PASS`

Confirm the columns landed:

```bash
/c/xampp/mysql/bin/mysql.exe -u root db_coffee -e \
  "SHOW COLUMNS FROM purchase_orders LIKE 'closed_short%';"
```

Expected: four rows — `closed_short`, `closed_short_at`, `closed_short_by`,
`closed_short_reason`, `closed_short_note`.

- [ ] **Step 6: Commit**

```bash
git add config.php tests/purchase_order_test.php
git commit -m "feat(po): store why a purchase order was closed short"
```

---

### Task 3: Capture the reason — modal and handler

**Files:**
- Modify: `purchase_order_view.php:123-143` (handler), `:181-195` (message map), `:371-378` (the button and its `confirm()`)
- Test: manual, commands below

**Interfaces:**
- Consumes: `po_short_reasons()`, `po_may_close_short()`.
- Produces: `closed_short_reason` / `closed_short_note` populated on close short.

- [ ] **Step 1: Validate and store in the handler**

Replace the body of the `close_short` branch at `purchase_order_view.php:123-143`:

```php
    if ($action === 'close_short') {
        // Writing off undelivered goods is a commercial decision, so it is
        // gated on the role and not on the purchase_orders permission the
        // clerk already holds. Checked here rather than only in the markup —
        // hiding a button is not access control.
        if (!po_may_close_short($_SESSION['role'] ?? null)) {
            po_redirect($from_list, $po_id, 'denied');
        }

        // The reason is what makes a write-off auditable months later, so an
        // unrecognised code is refused rather than stored. Validated against the
        // same array the dropdown was built from.
        $reason = trim($_POST['short_reason'] ?? '');
        if (!isset(po_short_reasons()[$reason])) {
            po_redirect($from_list, $po_id, 'badreason');
        }
        // Free text, so it is bounded here as well as in the column — a silent
        // truncation on write is worse than a short note.
        $note = mb_substr(trim($_POST['short_note'] ?? ''), 0, 255);

        // No stock is added. This records that the remainder is never coming,
        // which is the opposite of a delivery. The reason rides along in the
        // same UPDATE as the status change: if the PO stopped being Partially
        // Received in the meantime, neither is written.
        $by   = $_SESSION['username'] ?? null;
        $stmt = $conn->prepare("UPDATE purchase_orders
                                SET status='Received', closed_short=1,
                                    closed_short_at=NOW(), closed_short_by=?,
                                    closed_short_reason=?, closed_short_note=?,
                                    received_at=COALESCE(received_at, NOW())
                                WHERE po_id=? AND status='Partially Received'");
        $stmt->bind_param('sssi', $by, $reason, $note, $po_id);
        $stmt->execute();
        if ($stmt->affected_rows === 0) { po_redirect($from_list, $po_id, 'nochange'); }
        po_redirect($from_list, $po_id, 'shortclosed');
    }
```

- [ ] **Step 2: Add the rejection message**

In the `$msg_text` match at `purchase_order_view.php:181-195`, beside `'denied'`:

```php
    'badreason' => ['text'=>'Pick a reason for the write-off before closing this order short.', 'type'=>'danger'],
```

- [ ] **Step 3: Replace the native confirm with a modal**

Replace `purchase_order_view.php:371-378`:

```php
        <?php if ($po['status'] === 'Partially Received' && $is_manager): ?>
        <button type="button" class="btn btn-danger" onclick="openCloseShort()">
            <i class="fa-solid fa-file-circle-xmark"></i> Close short
        </button>
        <?php endif; ?>
```

Then add the modal markup near the end of the page body, before the closing
`</body>`. Follows the `.modal-overlay > .modal` pattern from `suppliers.php:416`:

```php
<?php if ($po['status'] === 'Partially Received' && $is_manager): ?>
<div class="modal-overlay" id="closeShortModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-solid fa-file-circle-xmark"></i> Close short</div>
            <button class="modal-close" type="button" onclick="closeCloseShort()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="close_short">
            <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">

            <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px">
                <strong>$<?= number_format($vals['outstanding'], 2) ?></strong> of goods will be
                written off. No stock will be added, and this cannot be undone.
            </p>

            <div class="field">
                <label>Why is the rest not coming? *</label>
                <select name="short_reason" required
                        style="width:100%;padding:9px;border-radius:8px;
                               border:1px solid var(--border,#333);
                               background:var(--card,#1a1a1a);color:inherit;">
                    <option value="">Select a reason…</option>
                    <?php foreach (po_short_reasons() as $code => $label): ?>
                    <option value="<?= he($code) ?>"><?= he($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Note (optional)</label>
                <textarea name="short_note" rows="2" maxlength="255"
                          placeholder="e.g. Only had 699g in the cold room."
                          style="width:100%;padding:9px;border-radius:8px;
                                 border:1px solid var(--border,#333);
                                 background:var(--card,#1a1a1a);color:inherit;
                                 font-family:inherit;"></textarea>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeCloseShort()">Cancel</button>
                <button type="submit" class="btn btn-danger">
                    <i class="fa-solid fa-file-circle-xmark"></i> Confirm write-off
                </button>
            </div>
        </form>
    </div>
</div>
<script>
function openCloseShort()  { document.getElementById('closeShortModal').classList.add('open'); }
function closeCloseShort() { document.getElementById('closeShortModal').classList.remove('open'); }
document.getElementById('closeShortModal').addEventListener('click', function (e) {
    // Click the backdrop to dismiss, but not a click that started inside the panel.
    if (e.target === this) closeCloseShort();
});
</script>
<?php endif; ?>
```

`purchase_order_view.php` has **no** `.modal-overlay` styles — verified, zero
occurrences — so add these to its `<style>` block. Lifted from `suppliers.php:254-280`
unchanged, including its `.open` show/hide mechanism, so the two modals behave
identically:

```css
.modal-overlay{
    position:fixed;inset:0;background:rgba(0,0,0,.65);
    display:flex;align-items:center;justify-content:center;
    z-index:1000;opacity:0;pointer-events:none;transition:opacity .2s;
}
.modal-overlay.open{opacity:1;pointer-events:all;}
.modal{
    background:var(--bg-card);border:1px solid var(--border-hover);
    border-radius:18px;padding:32px;width:min(520px,calc(100vw - 32px));
    box-shadow:var(--shadow-lg);
    transform:translateY(16px);transition:transform .25s ease;
}
.modal-overlay.open .modal{transform:translateY(0);}
.modal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;}
.modal-title{font-size:17px;font-weight:700;color:var(--text-light);}
.modal-close{background:none;border:none;color:var(--text-muted);font-size:18px;cursor:pointer;padding:4px;transition:var(--transition);}
.modal-close:hover{color:var(--danger);}
.field{margin-bottom:14px;}
.field label{display:block;font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.field select,.field textarea{
    width:100%;background:var(--bg-input);border:1px solid var(--border);
    border-radius:8px;padding:10px 14px;color:var(--text);font-family:'Poppins',sans-serif;font-size:13px;
    transition:var(--transition);resize:vertical;
}
.field select:focus,.field textarea:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(209,144,75,.12);}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;margin-top:8px;}
```

`suppliers.php` styles `.field input`; this modal has a `<select>` and a
`<textarea>` instead, so the selectors are adjusted accordingly. With these rules
present, the inline `style=` attributes on the `<select>` and `<textarea>` in the
markup above are redundant — drop them.

If any of `--bg-input`, `--border-hover`, `--text-light` or `--shadow-lg` are not
defined in `purchase_order_view.php`, substitute the variables that page already
uses rather than adding new ones.

- [ ] **Step 4: Verify a junk reason is refused**

```bash
rm -f /tmp/ck.txt
curl -sk -c /tmp/ck.txt -b /tmp/ck.txt -X POST "https://localhost/Cafe/login.php" \
  -d "username=Sokun&password=%40Sokun9811" -o /dev/null

PO=$(/c/xampp/mysql/bin/mysql.exe -u root db_coffee -N -e \
  "SELECT po_id FROM purchase_orders WHERE status='Partially Received' ORDER BY po_id LIMIT 1")
echo "testing PO $PO"
TOKEN=$(curl -sk -b /tmp/ck.txt "https://localhost/Cafe/purchase_order_view.php?po_id=$PO" \
  | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')

curl -sk -b /tmp/ck.txt -X POST "https://localhost/Cafe/purchase_order_view.php?po_id=$PO" \
  -d "action=close_short&csrf_token=$TOKEN&short_reason=NOT_A_REASON&short_note=x" \
  -o /dev/null -D - | grep -i "^location:"
/c/xampp/mysql/bin/mysql.exe -u root db_coffee -e \
  "SELECT status, closed_short, closed_short_reason FROM purchase_orders WHERE po_id=$PO;"
```

Expected: the redirect carries `msg=badreason`, and the PO is still
`Partially Received` with `closed_short = 0` and an empty reason.

- [ ] **Step 5: Verify a valid reason is stored**

Do this one **in the browser**, not curl — it is also the check that the modal
works. There are only two `Partially Received` POs and one of them, PO-2026-009,
is deliberately preserved demo data. Use the other.

1. Open Inventory → Purchase Orders → a part-delivered PO.
2. Click **Close short**. The modal opens; the amount matches the outstanding figure.
3. Submit with the reason left blank — the browser blocks it (`required`).
4. Pick **Supplier out of stock**, type a note, click **Confirm write-off**.
5. Confirm the success message, and that stock did not change:

```bash
/c/xampp/mysql/bin/mysql.exe -u root db_coffee -e \
  "SELECT po_number, status, closed_short, closed_short_by, closed_short_reason, closed_short_note
   FROM purchase_orders WHERE closed_short=1 ORDER BY closed_short_at DESC LIMIT 3;"
```

Expected: the PO reads `Received`, `closed_short=1`, reason `supplier_oos`, and the note as typed.

- [ ] **Step 6: Commit**

```bash
git add purchase_order_view.php
git commit -m "feat(po): require a reason when closing a purchase order short"
```

---

### Task 4: Show the reason wherever the write-off is

**Files:**
- Modify: `purchase_order_view.php` (amber strip under the header)
- Modify: `purchase_orders.php:298-302` (chip beside the status badge)
- Test: manual, commands below

**Interfaces:**
- Consumes: `po_short_reason_label()` from Task 2; `$vals` from `po_line_values()`.
- Produces: nothing.

- [ ] **Step 1: Add the strip to the PO page**

In `purchase_order_view.php`, immediately after the message banner block (the
`<?php if ($msg_text): ?>` section inside `.content`):

```php
    <?php if ((int)$po['closed_short'] === 1): ?>
    <?php /* Amber, not red: a write-off needs attention and an explanation, but the
             order itself is closed and correct. Permanent, unlike the msg banner —
             this is the record, not a notification. */ ?>
    <div style="background:rgba(224,169,85,.10);border:1px solid rgba(224,169,85,.28);
                border-left:3px solid #e0a955;border-radius:10px;padding:12px 14px;
                margin-bottom:16px;font-size:13px;">
        <div style="color:#e0a955;font-weight:600;margin-bottom:3px;">
            <i class="fa-solid fa-file-circle-xmark"></i>
            Closed short by <?= he($po['closed_short_by'] ?: 'unknown') ?>
            on <?= fmtDate($po['closed_short_at']) ?>
            — <?= he(po_short_reason_label($po['closed_short_reason'])) ?>
        </div>
        <div style="color:var(--text-muted);">
            $<?= number_format($vals['outstanding'], 2) ?> written off. No stock was added.
        </div>
        <?php if (trim((string)$po['closed_short_note']) !== ''): ?>
        <div style="color:var(--text-muted);font-style:italic;margin-top:5px;">
            &ldquo;<?= he($po['closed_short_note']) ?>&rdquo;
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
```

`$vals` is assigned at `purchase_order_view.php:439`, inside the Cost Summary block.
If that runs *after* this strip in page order, hoist the
`$vals = po_line_values($conn, $po_id);` assignment above both and delete the later
one — do not call it twice.

- [ ] **Step 2: Add the chip to the list**

In `purchase_orders.php`, replace the status cell at `:299-302`:

```php
                <td>
                    <span class="status-badge" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>">
                        <i class="fa-solid <?= $sc['icon'] ?>"></i> <?= $po['status'] ?>
                    </span>
                    <?php /* A closed-short PO is Received, so without this it is
                             indistinguishable from a clean delivery in an 18-row list.
                             The reason rides in the tooltip rather than the cell —
                             the chip answers "is there a write-off here", the tooltip
                             answers "why", and only one of those belongs in a table. */ ?>
                    <?php if ((int)($po['closed_short'] ?? 0) === 1):
                        $tip = po_short_reason_label($po['closed_short_reason']);
                        if (trim((string)$po['closed_short_note']) !== '') {
                            $tip .= ' — ' . $po['closed_short_note'];
                        }
                    ?>
                    <span class="status-badge"
                          style="background:rgba(224,169,85,.14);color:#e0a955;margin-left:4px;"
                          title="<?= he($tip) ?>">
                        <i class="fa-solid fa-file-circle-xmark"></i> closed short
                    </span>
                    <?php endif; ?>
                </td>
```

The list query is `SELECT p.*` at `purchase_orders.php:61`, so the new columns are
already available — no query change.

- [ ] **Step 3: Verify both screens**

```bash
rm -f /tmp/ck.txt
curl -sk -c /tmp/ck.txt -b /tmp/ck.txt -X POST "https://localhost/Cafe/login.php" \
  -d "username=Sokun&password=%40Sokun9811" -o /dev/null

PO=$(/c/xampp/mysql/bin/mysql.exe -u root db_coffee -N -e \
  "SELECT po_id FROM purchase_orders WHERE closed_short=1 ORDER BY closed_short_at DESC LIMIT 1")

curl -sk -b /tmp/ck.txt "https://localhost/Cafe/purchase_order_view.php?po_id=$PO" -o /tmp/pv.html
grep -c "Fatal error" /tmp/pv.html
grep -o "Closed short by [^<]*" /tmp/pv.html

curl -sk -b /tmp/ck.txt "https://localhost/Cafe/purchase_orders.php" -o /tmp/pl.html
grep -c "Fatal error" /tmp/pl.html
grep -o 'title="[^"]*"[^>]*>\s*<i class="fa-solid fa-file-circle-xmark"></i> closed short' /tmp/pl.html | head -2
```

Expected: zero fatals on both, the strip line renders with the manager's name and
the reason label, and the list carries at least one `closed short` chip with a
populated `title`.

- [ ] **Step 4: Verify a PO with no reason still renders**

Legacy rows carry `closed_short_reason = ''`.

```bash
/c/xampp/mysql/bin/mysql.exe -u root db_coffee -e \
  "SELECT po_id, po_number, closed_short_reason FROM purchase_orders WHERE closed_short=1;"
```

For any row with an empty reason, open its PO page and confirm the strip reads
**"No reason recorded"** rather than trailing off after the em dash.

- [ ] **Step 5: Run the full suite**

Run: `php tests/purchase_order_test.php`
Expected: `ALL PASS`

Run: `php tests/counter_cash_test.php`
Expected: `ALL PASS`

- [ ] **Step 6: Commit**

```bash
git add purchase_order_view.php purchase_orders.php
git commit -m "feat(po): surface the write-off reason on both PO screens"
```

---

## Self-review notes

- **Spec coverage.** §1 schema → Task 2. §2 reason list → Task 2. §3 modal and handler → Task 3. §4 display on both screens → Task 4. §5 footer `ORDERED · RECEIVED` → **deliberately deferred**, see below. §6 testing → Tasks 1, 2 and the manual steps in 3 and 4.
- **The spec's §5 footer change is not in this plan.** It is unrelated to write-off reasons, it is the item the spec itself flags as making the legacy `total_cost` mismatch more visible, and bundling it would mean a reviewer cannot reject one without rejecting the other. It should be its own small change.
- **Not covered by an automated test:** the modal itself, and the "no stock was added" property of close short. The first needs a browser. The second is asserted only by inspection in Task 3 Step 5 — a real regression test would need a fixture PO closed short inside the existing transaction harness, which is worth adding if this area is touched again.
- **`$is_manager` is assumed to already exist** in `purchase_order_view.php` — it gates the current button at `:371`. If Task 3 finds it is computed further down the file than the modal markup, hoist it rather than recomputing.
