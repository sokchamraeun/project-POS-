<?php
/**
 * CLI assertions for the two-currency cash tender helpers.
 * Run:  php tests/tender_test.php
 * There is no test framework in this project; this script is the harness.
 * These helpers are pure — no database is touched and no order numbering is at risk.
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
// A dollars-only sale must write exactly what it writes today, or all 191
// existing rows would start printing differently.
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

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
