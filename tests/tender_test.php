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

echo "tender_change follow-the-currency\n";
// A shop gives back what it was given. Handing dollars to someone who paid in
// riel converts currency on them without being asked, at the shop's rate, on a
// transaction they did not agree to. Pay in riel, get riel back.
$riel_ref = '0.00|20000';
$c = tender_change(tender_usd_total($riel_ref), 1.34,
                   tender_is_riel_only(tender_parts($riel_ref)));
check('a riel-only tender hands back NO dollars',      $c['usd'], 0);
check('a riel-only tender hands back all of it in riel', $c['khr'], 14500);
check('a covered riel-only tender is not short',       $c['short'], false);
check('and the label reads as riel only',              tender_change_text($c), 'KHR 14,500');

// Everything that is not riel-only keeps today's behaviour exactly. These two
// are the same sums as the block above, now routed through the flag the call
// sites actually pass, so a wrong flag would show up here.
$usd_ref = '5.00';
$c = tender_change(tender_usd_total($usd_ref), 1.34,
                   tender_is_riel_only(tender_parts($usd_ref)));
check('a dollars-only tender still splits dollars first', $c['usd'], 3);
check('a dollars-only tender still gives riel remainder',  $c['khr'], 2700);

$mix_ref = '1.00|8000';
$c = tender_change(tender_usd_total($mix_ref), 1.34,
                   tender_is_riel_only(tender_parts($mix_ref)));
check('a mixed tender still splits dollars first', $c['usd'], 1);
check('a mixed tender still gives riel remainder',  $c['khr'], 2500);

// Short is short in either currency: the order settles in full and nothing is
// handed back, so the flag must not conjure riel out of a negative difference.
$short_ref = '0.00|4000';
$c = tender_change(tender_usd_total($short_ref), 1.34,
                   tender_is_riel_only(tender_parts($short_ref)));
check('a short riel-only tender is still flagged short', $c['short'], true);
check('a short riel-only tender hands back no dollars',  $c['usd'], 0);
check('a short riel-only tender hands back no riel',     $c['khr'], 0);

// THE PARITY GUARD. The dollars-first path carries a riel remainder that fills
// a whole dollar up into a dollar note. That carry must never fire on the
// riel-only path or it would hand a dollar back to someone who paid in riel —
// the exact thing this rule exists to stop. Swept across bills and notes so a
// future edit cannot reintroduce it for one unlucky amount.
// Asserting usd === 0 ALONE is too weak: a branch that regressed to returning
// short, or to handing back nothing at all, would satisfy it while being just as
// broken. So a tender that genuinely covers the bill must also come back with
// real riel and not be flagged short.
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

    // Net of change under follow-the-currency: ៛20,000 in, ៛14,500 back, so
    // ៛5,500 stayed — worth exactly the $1.34 bill.
    $bigRiel = $mk(1.34, 'cash', tender_ref(0, 20000));
    check('riel handed back as change is netted off',
          tender_riel_share($conn, [$bigRiel]), round(5500 / KHR_RATE, 2));

    // THE DRAWER CONSEQUENCE. Under the old dollars-first rule this same sale's
    // riel share was ~$4.34 against a $1.34 bill, because $3 in real dollar
    // notes left the drawer as change on top of the riel that never entered it.
    // Follow-the-currency hands back only riel, so NO dollar note moves: the
    // share now nets to the order total, and shift_report.php's
    // (cash total − riel share) leaves the expected dollar figure untouched by
    // riel-only sales. That is the whole point — a riel sale should be
    // invisible to a drawer counted in dollars.
    $bigRielShare = tender_riel_share($conn, [$bigRiel]);
    check('a riel-only sale nets to the order total, so expected dollar cash is unchanged',
          round(1.34 - $bigRielShare, 2), 0.0);

    // ...but the negative-capable behaviour still has to exist, because a MIXED
    // tender still pays dollars back. ៛20,000 with $1.00 on a $1.34 bill is not
    // riel-only, so change is $4 + ៛2,200 and ៛17,800 stays: a $4.34 share
    // against a $1.34 sale, four dollar notes genuinely gone from the drawer.
    // shift_report.php must keep letting expected_cash go negative — clamping it
    // to $0.00 was the phantom-shortage bug, and it is still reachable here.
    $mixedBig = $mk(1.34, 'cash', tender_ref(1.00, 20000));
    $mixedShare = tender_riel_share($conn, [$mixedBig]);
    check('a mixed big-riel tender still exceeds the order total',
          round($mixedShare, 2), round(17800 / KHR_RATE, 2));
    check('and still drives expected-cash negative', $mixedShare > 1.34, true);

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
          round((5500 + 5500 + 17800) / KHR_RATE, 2));

    // An id that does not exist must not throw or count.
    check('an unknown order contributes nothing', tender_riel_share($conn, [-1]), 0.0);
} finally {
    // Undoes the scratch orders and their payment rows in one shot, whether the
    // block finished, failed an assertion, or threw.
    $conn->rollback();
}

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
