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

echo "deduction arithmetic\n";
// Real stock moves here, so everything runs in a transaction rolled back in the
// finally. Reflection alone would not catch a body mangled by the move.
$conn->begin_transaction();
try {
    $r1 = $conn->query("SELECT product_id, ingredient_id, amount_used
                        FROM product_ingredients LIMIT 1")->fetch_assoc();
    check('a product with a recipe exists', $r1 !== null, true);

    $pid = (int)$r1['product_id'];
    $ing = (int)$r1['ingredient_id'];
    $per = (float)$r1['amount_used'];

    // Plenty on hand, so this measures the formula and not the oversell clamp.
    $conn->query("UPDATE ingredients SET stock_quantity = 100000 WHERE ingredient_id = $ing");
    $before = (float)$conn->query("SELECT stock_quantity FROM ingredients
                                   WHERE ingredient_id = $ing")->fetch_row()[0];

    _deduct_stock($conn, $pid, 1, '', 0, 1.0, 'Remake of order #999');
    $after1 = (float)$conn->query("SELECT stock_quantity FROM ingredients
                                   WHERE ingredient_id = $ing")->fetch_row()[0];
    check('one drink deducts one recipe amount', round($before - $after1, 4), round($per, 4));

    // qty and size_factor both multiply — a Large remake of 2 takes 2 x factor.
    _deduct_stock($conn, $pid, 2, '', 0, 1.5, 'Remake of order #999');
    $after2 = (float)$conn->query("SELECT stock_quantity FROM ingredients
                                   WHERE ingredient_id = $ing")->fetch_row()[0];
    check('qty and size_factor both multiply', round($after1 - $after2, 4), round($per * 2 * 1.5, 4));

    // The override is what makes a remake identifiable without a new change_type.
    $row = $conn->query("SELECT change_type, reference FROM ingredient_history
                         WHERE ingredient_id = $ing ORDER BY id DESC LIMIT 1")->fetch_assoc();
    check('the ledger carries the override reference', $row['reference'],   'Remake of order #999');
    check('the ledger keeps order_deduct',             $row['change_type'], 'order_deduct');

    // Omitting the override must still produce the ordering flow's wording.
    // A real order row is required: ingredient_history.order_id is a foreign key,
    // so an invented id fatals rather than logging. Dated 2020 so it can never
    // touch today's daily_order_no sequence, which is MAX+1 over today's orders.
    $conn->query("INSERT INTO orders (daily_order_no, customer_name, total, status,
                                      is_open, payment_method, business_date, order_date, order_type)
                  VALUES (1, 'DEDUCT-TEST', 0.00, 'Completed', 0, 'cash',
                          '2020-01-01', '2020-01-01 10:00:00', 'drink_in')");
    $oid = (int)$conn->insert_id;

    _deduct_stock($conn, $pid, 1, '', $oid, 1.0);
    $dflt = $conn->query("SELECT order_id, reference FROM ingredient_history
                          WHERE ingredient_id = $ing ORDER BY id DESC LIMIT 1")->fetch_assoc();
    check('without an override it still says Order #N', $dflt['reference'], "Order #$oid");
    check('the ledger links back to the order',        (int)$dflt['order_id'], $oid);
} finally {
    $conn->rollback();
}

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
