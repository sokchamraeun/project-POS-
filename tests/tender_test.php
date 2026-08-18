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
check('riel only',      round(tender_usd_total('0.00|' . KHR_RATE), 2), 1.00);
check('both',           round(tender_usd_total('1.00|' . KHR_RATE), 2), 2.00);
check('not a tender is zero', tender_usd_total('KHQR9F2A1B'),    0.0);

echo "tender_is_riel_only\n";
// The single definition of "the customer paid entirely in riel". Every caller
// asks this question through this function; the last time an expression like it
// was inlined at more than one site the two drifted and the screen and the
// receipt disagreed by a cent.
check('riel only',                      tender_is_riel_only(tender_parts('0.00|20000')), true);
check('dollars only is not',            tender_is_riel_only(tender_parts('5.00')),       false);
check('mixed is not',                   tender_is_riel_only(tender_parts('1.00|8000')),  false);
// A reference that is not a tender at all (Bakong) parses to null.
check('null is not riel-only',          tender_is_riel_only(null),                       false);
// Nothing was handed over, so nothing was handed over in riel. Guards the
// change path from treating an empty tender as a riel tender.
check('a zero tender is not riel-only', tender_is_riel_only(tender_parts('0')),          false);

echo "tender_change\n";
// $5.00 handed over on a $1.34 bill: $3.66 back (< $10.00), so change is entirely riel.
$c = tender_change(5.00, 1.34);
check('change under $10 hands back no dollars', $c['usd'], 0);
check('change under $10 is in riel',            $c['khr'], (int)(round((3.66 * KHR_RATE) / 100) * 100));
check('a covered bill is not short',            $c['short'], false);

// Change >= $10.00: $20.00 handed over on a $1.34 bill -> $18.66 change: $10 bill + $8.66 in riel.
$c = tender_change(20.00, 1.34);
check('change >= $10 splits tens of dollars',   $c['usd'], 10);
check('remainder is in riel',                   $c['khr'], (int)(round((8.66 * KHR_RATE) / 100) * 100));

// Exact money.
$c = tender_change(1.34, 1.34);
check('exact tender gives no dollars', $c['usd'], 0);
check('exact tender gives no riel',    $c['khr'], 0);
// Short tender: the order still settles in full, but nothing is handed back.
$c = tender_change(1.00, 1.34);
check('a short tender is flagged', $c['short'], true);
check('a short tender hands back no dollars', $c['usd'], 0);
check('a short tender hands back no riel',    $c['khr'], 0);

echo "tender_change follow-the-currency\n";
// A shop gives back what it was given. Handing dollars to someone who paid in
// riel converts currency on them without being asked, at the shop's rate, on a
// transaction they did not agree to. Pay in riel, get riel back.
$riel_ref = '0.00|20000';
$c = tender_change(tender_usd_total($riel_ref), 1.34,
                   tender_is_riel_only(tender_parts($riel_ref)));
$expected_khr = (int)(round((tender_usd_total($riel_ref) - 1.34) * KHR_RATE / 100) * 100);
check('a riel-only tender hands back NO dollars',      $c['usd'], 0);
check('a riel-only tender hands back all of it in riel', $c['khr'], $expected_khr);
check('a covered riel-only tender is not short',       $c['short'], false);
check('and the label reads as riel only',              tender_change_text($c), 'KHR ' . number_format($expected_khr));

// Under-$10 dollar tender: $5.00 on $1.34 bill gives KHR only
$usd_ref = '5.00';
$c = tender_change(tender_usd_total($usd_ref), 1.34,
                   tender_is_riel_only(tender_parts($usd_ref)));
check('a dollars-only tender under $10 gives no dollars', $c['usd'], 0);
check('a dollars-only tender under $10 gives riel',       $c['khr'], (int)(round((3.66 * KHR_RATE) / 100) * 100));

// Change >= $10: $25.00 on $1.34 bill gives $20 + remainder in riel
$big_usd_ref = '25.00';
$c = tender_change(tender_usd_total($big_usd_ref), 1.34,
                   tender_is_riel_only(tender_parts($big_usd_ref)));
check('a dollars-only tender >= $10 splits tens of dollars', $c['usd'], 20);
check('a dollars-only tender >= $10 gives riel remainder',   $c['khr'], (int)(round((3.66 * KHR_RATE) / 100) * 100));

// Short is short in either currency: the order settles in full and nothing is
// handed back, so the flag must not conjure riel out of a negative difference.
$short_ref = '0.00|4000';
$c = tender_change(tender_usd_total($short_ref), 1.34,
                   tender_is_riel_only(tender_parts($short_ref)));
check('a short riel-only tender is still flagged short', $c['short'], true);
check('a short riel-only tender hands back no dollars',  $c['usd'], 0);
check('a short riel-only tender hands back no riel',     $c['khr'], 0);

// THE PARITY GUARD.
$carried = [];
$hollow  = [];
foreach ([0.75, 1.34, 2.50, 3.00, 4.10, 7.25, 12.80] as $owed) {
    foreach ([5000, 10000, 20000, 50000, 100000] as $khr) {
        $ref   = tender_ref(0, $khr);
        $total = tender_usd_total($ref);
        $g     = tender_change($total, $owed, tender_is_riel_only(tender_parts($ref)));
        if ($g['usd'] !== 0) { $carried[] = "$ref on $owed gave \${$g['usd']}"; }
        // Covers the bill by more than the ៛100 rounding step, so change is owed.
        if ($total - $owed > 0.05) {
            if ($g['short'])     { $hollow[] = "$ref on $owed said short"; }
            if ($g['khr'] <= 0)  { $hollow[] = "$ref on $owed handed back no riel"; }
        }
    }
}
check('no riel-only tender anywhere hands back a single dollar', $carried, []);
check('every covered riel-only tender hands real riel back',     $hollow,  []);

echo "tender_change_text\n";
check('under $10 change is riel only', tender_change_text(tender_change(5.00, 1.34)), 'KHR ' . number_format((int)(round((3.66 * KHR_RATE) / 100) * 100)));
check('>= $10 mixed change', tender_change_text(tender_change(20.00, 1.34)), '$10 + KHR ' . number_format((int)(round((8.66 * KHR_RATE) / 100) * 100)));
check('exact money gives $0.00', tender_change_text(tender_change(1.34, 1.34)), '$0.00');
check('a short tender gives $0.00', tender_change_text(tender_change(1.00, 1.34)), '$0.00');
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
        $st_o = $conn->prepare("INSERT INTO orders (total, payment_method, order_date) VALUES (?, ?, NOW())");
        $st_o->bind_param('ds', $total, $method);
        $st_o->execute();
        $oid = (int)$conn->insert_id;
        $st_p = $conn->prepare("INSERT INTO order_payments (order_id, payment_method, amount, reference)
                                VALUES (?,?,?,?)");
        $st_p->bind_param('isds', $oid, $method, $total, $ref);
        $st_p->execute();
        return $oid;
    };

    $exactKhr = (int)(round(1.34 * KHR_RATE / 100) * 100);
    $rielOnly = $mk(1.34, 'cash', tender_ref(0, $exactKhr));
    check('a riel-only cash sale is entirely riel',
          tender_riel_share($conn, [$rielOnly]), round($exactKhr / KHR_RATE, 2));

    // Net of change under follow-the-currency
    $bigRiel = $mk(1.34, 'cash', tender_ref(0, 20000));
    $bigRielCh = tender_change(20000 / KHR_RATE, 1.34, true);
    $bigRielKept = 20000 - $bigRielCh['khr'];
    check('riel handed back as change is netted off',
          tender_riel_share($conn, [$bigRiel]), round($bigRielKept / KHR_RATE, 2));

    $bigRielShare = tender_riel_share($conn, [$bigRiel]);
    check('a riel-only sale nets to the order total, so expected dollar cash is unchanged',
          round(1.34 - $bigRielShare, 2) <= 0.02, true);

    // Mixed tender: $1.00 + 20,000 KHR
    $mixedBig = $mk(1.34, 'cash', tender_ref(1.00, 20000));
    $mixedCh = tender_change(1.00 + (20000 / KHR_RATE), 1.34, false);
    $mixedKept = 20000 - $mixedCh['khr'];
    $mixedShare = tender_riel_share($conn, [$mixedBig]);
    check('a mixed big-riel tender nets riel correctly',
          round($mixedShare, 2), round($mixedKept / KHR_RATE, 2));

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
          tender_riel_share($conn, [$rielOnly, $bigRiel, $mixedBig, $dollars, $legacy, $bakong]),
          round(($exactKhr + $bigRielKept + $mixedKept) / KHR_RATE, 2));

    // An id that does not exist must not throw or count.
    check('an unknown order contributes nothing', tender_riel_share($conn, [-1]), 0.0);
} finally {
    // Undoes the scratch orders and their payment rows in one shot, whether the
    // block finished, failed an assertion, or threw.
    $conn->rollback();
}

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
