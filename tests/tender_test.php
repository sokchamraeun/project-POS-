<?php
/**
 * CLI assertions for the two-currency cash tender helpers.
 * Run:  php tests/tender_test.php
 * There is no test framework in this project; this script is the harness.
 *
 * Everything down to tender_riel_share is pure — no database is touched. The
 * tender_riel_share block at the bottom needs rows, and builds them inside a
 * transaction that is always rolled back (same harness as
 * tests/purchase_order_test.php), so nothing survives the run.
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

echo "tender_ref\n";
// A dollars-only sale must write exactly what it writes today, or all 49
// existing bare-number rows would start printing differently.
check('dollars only stays a bare number', tender_ref(1.34, 0),    '1.34');
check('riel only',                        tender_ref(0, 5500),    '0.00|5500');
check('both currencies',                  tender_ref(1.00, 8000), '1.00|8000');
check('nothing tendered is empty',        tender_ref(0, 0),       '');

echo "tender_parts\n";
check('legacy bare number reads as USD',  tender_parts('5.00'),   ['usd'=>5.0,  'khr'=>0]);
check('two-part reads both',              tender_parts('0.00|5500'), ['usd'=>0.0,'khr'=>5500]);
check('integer legacy value',             tender_parts('20000'),  ['usd'=>20000.0,'khr'=>0]);
// A zero tender is still a tender. is_numeric() accepted '0' too, so this is
// not a behaviour change.
check('zero is a tender',                 tender_parts('0'),      ['usd'=>0.0,  'khr'=>0]);
// A Bakong transaction id must never be mistaken for money.
check('bakong txn id is not a tender',    tender_parts('KHQR9F2A1B'), null);
check('empty is not a tender',            tender_parts(''),       null);
check('null is not a tender',             tender_parts(null),     null);
check('malformed pipe is not a tender',   tender_parts('1.00|'),  null);
check('negative is not a tender',         tender_parts('-5.00'),  null);

echo "tender_usd_total\n";
check('dollars only',   round(tender_usd_total('5.00'), 2),      5.00);
check('riel only',      round(tender_usd_total('0.00|4100'), 2), 1.00);
check('both',           round(tender_usd_total('1.00|4100'), 2), 2.00);
check('not a tender is zero', tender_usd_total('KHQR9F2A1B'),    0.0);

echo "tender_change\n";
// $5.00 handed over on a $1.34 bill: $3.66 back. No US coins circulate, so the
// remainder is riel.
$c = tender_change(5.00, 1.34);
check('change splits into whole dollars', $c['usd'], 3);
check('remainder becomes riel',           $c['khr'], 2700);
check('a covered bill is not short',      $c['short'], false);
// The carry: a remainder that rounds up to a whole dollar must be handed back
// as a dollar bill, not as 4,100 riel in small notes.
$c = tender_change(5.33, 1.34);
check('rounding carry promotes to a dollar', $c['usd'], 4);
check('rounding carry leaves no riel',       $c['khr'], 0);
// Exact money.
$c = tender_change(1.34, 1.34);
check('exact tender gives no dollars', $c['usd'], 0);
check('exact tender gives no riel',    $c['khr'], 0);
// Short tender: the order still settles in full, but nothing is handed back.
$c = tender_change(1.00, 1.34);
check('a short tender is flagged', $c['short'], true);
check('a short tender hands back no dollars', $c['usd'], 0);
check('a short tender hands back no riel',    $c['khr'], 0);

echo "tender_change_text\n";
// THE REGRESSION LOCK. The receipt used to build this label by hand and, when
// the change had no riel part, printed the raw received-minus-owed difference
// instead of the carried whole dollar: $3.99 on paper against $4.00 on screen,
// for the same sale. Both now read the same function.
check('the rounding carry prints as a whole dollar, not the raw difference',
      tender_change_text(tender_change(5.33, 1.34)), '$4.00');
check('mixed change', tender_change_text(tender_change(5.00, 1.34)), '$3 + KHR 2,700');
// Under a dollar back: riel only, no "$0 +" prefix.
check('riel-only change', tender_change_text(tender_change(1.90, 1.34)), 'KHR 2,300');
check('exact money gives $0.00', tender_change_text(tender_change(1.34, 1.34)), '$0.00');
// A receipt is printed after the money changed hands; it has no owed/received
// pair to subtract, so a short tender reads $0.00 rather than the screen's
// "Need $0.34 more".
check('a short tender gives $0.00', tender_change_text(tender_change(1.00, 1.34)), '$0.00');
// Receipts are ASCII-safe PDF output: the dompdf fonts carry no Khmer glyph, so
// this side deliberately spells the currency KHR where tender.js prints ៛.
check('the receipt spelling stays ASCII',
      strpos(tender_change_text(tender_change(1.90, 1.34)), '៛'), false);

echo "tender_received_text\n";
check('dollars only', tender_received_text(tender_parts('5.00'), tender_usd_total('5.00')), '$5.00');
check('riel only',
      tender_received_text(tender_parts('0.00|5500'), tender_usd_total('0.00|5500')), 'KHR 5,500');
check('both currencies',
      tender_received_text(tender_parts('1.00|8000'), tender_usd_total('1.00|8000')), '$1.00 + KHR 8,000');
// The legacy path: a reference that is not a recognised tender still has a
// dollar figure the caller can show.
check('unparsed parts fall back to the dollar total', tender_received_text(null, 5.0), '$5.00');

echo "tender_riel_share\n";
// An empty shift must not build an IN () with no placeholders.
check('no orders is a clean zero', tender_riel_share($conn, []), 0.0);

// Rows are needed from here down. Everything is created inside one transaction
// and rolled back in the finally, so nothing is committed even if an assertion
// throws. business_date is deliberately far in the past and daily_order_no is 0:
// a scratch order dated today would be visible to the live daily order counter
// for as long as the transaction is open.
$conn->begin_transaction();
try {
    $mk = function (float $total, string $method, string $ref) use ($conn): int {
        $conn->query("INSERT INTO orders (user_id, customer_name, total, status, business_date, daily_order_no)
                      VALUES (NULL, 'TENDER TEST', $total, 'Paid', '2000-01-01', 0)");
        $oid = (int)$conn->insert_id;
        $st = $conn->prepare("INSERT INTO order_payments (order_id, payment_method, amount, reference)
                              VALUES (?,?,?,?)");
        $st->bind_param('isds', $oid, $method, $total, $ref);
        $st->execute();
        return $oid;
    };

    // The case the whole helper exists for: the bill was paid entirely in riel,
    // booked to the cash bucket, and not one dollar reached the drawer.
    $rielOnly = $mk(1.34, 'cash', tender_ref(0, 5500));
    check('a riel-only cash sale is entirely riel',
          tender_riel_share($conn, [$rielOnly]), 1.34);

    // Net of change: ៛20,000 in, $3 + ៛2,200 back, so ៛17,800 stayed.
    $bigRiel = $mk(1.34, 'cash', tender_ref(0, 20000));
    check('riel handed back as change is netted off',
          tender_riel_share($conn, [$bigRiel]), round(17800 / KHR_RATE, 2));

    // R2 lock: this same $1.34 order's riel share (~$4.34) is bigger than the
    // order total itself. That is correct, not a bug — the $20,000 tender's
    // change paid out $3 in whole dollar notes before the sub-dollar riel
    // remainder, so three real dollar notes left the drawer on top of the riel
    // that never entered it. shift_report.php subtracts this from a shift's
    // cash total, so a riel-heavy shift like this one can legitimately drive
    // expected_cash negative — clamping it to $0.00 (the old behaviour) would
    // hide that the dollar drawer is really $3 lighter than it started.
    $bigRielShare = tender_riel_share($conn, [$bigRiel]);
    // Expressed via KHR_RATE, not hardcoded, so this stays correct if the
    // configured exchange rate ever changes; at today's rate (4100) it is $4.34.
    check('a big riel tender exceeds the order total, about $4.34 vs $1.34',
          round($bigRielShare, 2), round(17800 / KHR_RATE, 2));
    check('and is therefore greater than the order total (drives expected-cash negative)',
          $bigRielShare > 1.34, true);

    // A dollars-only tender put dollars in the dollar drawer. Nothing to subtract.
    $dollars = $mk(1.34, 'cash', tender_ref(5.00, 0));
    check('a dollars-only cash sale contributes nothing',
          tender_riel_share($conn, [$dollars]), 0.0);

    // The 4 historical payment_method='riel' rows carry a raw KHR integer, which
    // tender_parts() reads as dollars. They are not cash rows, they were never in
    // the cash bucket this figure is subtracted from, and pulling them in would
    // subtract $20,000 from one shift's expected drawer.
    $legacy = $mk(4.88, 'riel', '20000');
    check('a legacy riel-method row is not a cash row',
          tender_riel_share($conn, [$legacy]), 0.0);

    // Bakong references are not tenders at all.
    $bakong = $mk(1.34, 'bakong', 'KHQR9F2A1B');
    check('a bakong row contributes nothing', tender_riel_share($conn, [$bakong]), 0.0);

    // And the shift-level sum, which is how shift_report.php calls it.
    check('a whole shift sums its riel rows only',
          tender_riel_share($conn, [$rielOnly, $bigRiel, $dollars, $legacy, $bakong]),
          round((5500 + 17800) / KHR_RATE, 2));

    // An id that does not exist must not throw or count.
    check('an unknown order contributes nothing', tender_riel_share($conn, [-1]), 0.0);
} finally {
    // Undoes the scratch orders and their payment rows in one shot, whether the
    // block finished, failed an assertion, or threw.
    $conn->rollback();
}

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
