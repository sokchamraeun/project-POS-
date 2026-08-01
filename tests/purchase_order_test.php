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

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
