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
