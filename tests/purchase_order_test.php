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
//
// Two legitimate exceptions, both created after this assertion was written:
//   - an over-delivery (qty_received > qty_ordered) is supported and rendered as
//     "+N over", so only a SHORTFALL indicates a missed backfill
//   - a closed-short PO is Received *with* a shortfall, on purpose
$bad = (int)$conn->query("
    SELECT COUNT(*) FROM purchase_order_items poi
    JOIN purchase_orders p ON p.po_id = poi.po_id
    WHERE p.status = 'Received'
      AND p.closed_short = 0
      AND poi.qty_received < poi.qty_ordered
")->fetch_row()[0];
check('historical Received POs are backfilled in full', $bad, 0);

echo "close-short reason\n";
foreach (['closed_short_reason', 'closed_short_note'] as $c) {
    check("$c exists",
          $conn->query("SHOW COLUMNS FROM purchase_orders LIKE '$c'")->num_rows === 1, true);
}
$rc = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'closed_short_reason'")->fetch_assoc();
check('reason is NOT NULL',       $rc['Null'],    'NO');
check('reason defaults to empty', $rc['Default'], '');

$reasons = po_short_reasons();
check('reason list is not empty', count($reasons) > 0,             true);
check('other is offered',         isset($reasons['other']),        true);
check('supplier_oos is offered',  isset($reasons['supplier_oos']), true);
// A code longer than the column would be silently truncated on write, and the
// truncated value would then fail every lookup.
check('every code fits the column',
      array_values(array_filter(array_keys($reasons), fn($k) => strlen($k) > 40)), []);
// Codes are the stored value and end up in a value= attribute; labels are for humans.
check('codes are plain identifiers',
      array_values(array_filter(array_keys($reasons), fn($k) => !preg_match('/^[a-z_]+$/', $k))), []);
// An unrecognised code must stay readable rather than collapsing to blank.
check('a known code renders its label', po_short_reason_label('supplier_oos'), 'Supplier out of stock');
check('an unknown code renders itself', po_short_reason_label('zzz_gone'),     'zzz_gone');
check('an empty code says so',          po_short_reason_label(''),             'No reason recorded');

// Draft and Ordered POs have not been delivered, so they must stay at zero.
$early = (int)$conn->query("
    SELECT COUNT(*) FROM purchase_order_items poi
    JOIN purchase_orders p ON p.po_id = poi.po_id
    WHERE p.status IN ('Draft','Ordered') AND poi.qty_received <> 0
")->fetch_row()[0];
check('undelivered POs stay at zero', $early, 0);

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

// The "undelivered PO" assertions used to look up the first Ordered PO here. Once
// every real PO had been received that query returned no row, the (int) cast turned
// it into po_id 0, and po_line_values() answers all-zeroes for an unknown PO — so
// two of the three checks passed vacuously and the third failed. They now run
// against the fixture PO built inside the transaction below, which always exists.

check('an unknown PO is all zeroes', po_line_values($conn, 0),
      ['ordered'=>0.0, 'received'=>0.0, 'outstanding'=>0.0]);

echo "po_status_from_lines\n";
check('a fully received PO reads Received', po_status_from_lines($conn, $anyReceived), 'Received');

echo "receive arithmetic\n";
// Everything below runs inside one outer transaction on this connection, rolled
// back in the finally no matter how the block exits — assertion failure, thrown
// exception, or a clean run. That makes explicit cleanup (DELETEs, a stock
// restore) unnecessary and, more importantly, safe: the old straight-line
// cleanup skipped entirely on a fatal between the scratch-PO insert and the
// bottom of the script, which is exactly what happened mid-development and
// left ingredient 48's stock permanently inflated. A restore statement like
// `SET stock_quantity = $before` is also a lost-update hazard on a live DB —
// it overwrites whatever a concurrent sale did to the same row while this ran.
// A rollback has neither problem: nothing here is ever committed.
$conn->begin_transaction();
try {
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

    // An Ordered PO has been placed but not delivered: nothing received, all
    // outstanding. Asserted against this fixture rather than whatever demo PO
    // happens to exist — the previous ambient lookup silently resolved to po_id 0
    // once every real PO had been received, and passed vacuously.
    $v2 = po_line_values($conn, $testPo);
    check('an undelivered PO has received 0',     round($v2['received'], 2), 0.0);
    check('an undelivered PO is fully outstanding',
          round($v2['outstanding'] - $v2['ordered'], 2),                     0.0);
    check('an untouched PO reads Partially Received',
          po_status_from_lines($conn, $testPo), 'Partially Received');

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

    // --- Atomicity: the caller must roll back a delivery that fails part-way ---
    // po_receive_line() itself only ever claims or refuses one line; the "whole
    // delivery rolls back together" property lives in the caller
    // (purchase_order_view.php's receive_items handler), which wraps every line
    // of a submission in one transaction and rolls it all back the moment any
    // line refuses. Simulate exactly that here: a second line on the same PO,
    // one call that claims and one that is deliberately submitted with the
    // wrong 'seen' so it is refused, then roll back and confirm nothing from
    // either call survived — not just the refused one.
    //
    // A SAVEPOINT is used for the rollback rather than $conn->rollback() bare,
    // so only this inner simulated delivery unwinds — the outer transaction
    // that the whole "receive arithmetic" block runs in stays open and still
    // gets rolled back exactly once, in the finally below.
    $conn->query("INSERT INTO purchase_order_items (po_id, ingredient_id, qty_ordered, unit_cost)
                  VALUES ($testPo, 48, 5.000, 2.00)");
    $testPoi2 = (int)$conn->insert_id;
    $stockBeforeAtomic = (float)$conn->query(
        "SELECT stock_quantity FROM ingredients WHERE ingredient_id=48")->fetch_row()[0];
    $recvBeforeAtomic = (float)$conn->query(
        "SELECT qty_received FROM purchase_order_items WHERE poi_id=$testPoi2")->fetch_row()[0];

    // Raw SQL, not mysqli::savepoint()/rollback($flags,$name): on this driver
    // the OOP rollback() with a name performs a full ROLLBACK regardless of
    // the savepoint argument, not a ROLLBACK TO SAVEPOINT — confirmed by
    // comparing both side by side before writing this. The SQL form behaves
    // as documented.
    $conn->query("SAVEPOINT atomic_delivery");
    $claim1 = po_receive_line($conn, $testPo, $testPoi2, 0.0,   2.0, 'test', 'PO-TEST');
    $claim2 = po_receive_line($conn, $testPo, $testPoi,  999.0, 1.0, 'test', 'PO-TEST');
    check('first line of the doomed delivery claims', $claim1, true);
    check('second line of the doomed delivery is refused on a stale seen', $claim2, false);
    $conn->query("ROLLBACK TO SAVEPOINT atomic_delivery");

    $stockAfterAtomic = (float)$conn->query(
        "SELECT stock_quantity FROM ingredients WHERE ingredient_id=48")->fetch_row()[0];
    $recvAfterAtomic = (float)$conn->query(
        "SELECT qty_received FROM purchase_order_items WHERE poi_id=$testPoi2")->fetch_row()[0];
    check('rolling back a doomed delivery leaves stock untouched',
          round($stockAfterAtomic - $stockBeforeAtomic, 3), 0.0);
    check('rolling back a doomed delivery leaves qty_received untouched',
          round($recvAfterAtomic - $recvBeforeAtomic, 3), 0.0);
} finally {
    // Undoes everything in this block in one shot: the scratch PO, its items,
    // the stock move, the stock_refills and ingredient_history rows. Runs even
    // if a check() assertion never ran because something above it threw.
    $conn->rollback();
}

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

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
