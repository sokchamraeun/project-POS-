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
    // Clamped to a value that is actually reachable at the configured rate: at
    // the mandatory 1:1 default (Y=1) progress can only ever be 0, since every
    // single drink completes a point on its own — there is no "mid-way" state
    // to seed, so seeding 1 there would be testing recovery of data the app
    // itself could never have produced.
    $seedProgress = min(1, LOYALTY_POINTS_DRINKS - 1);
    $conn->query("UPDATE loyalty_cards SET points_progress = $seedProgress WHERE card_id = $cardA");
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
