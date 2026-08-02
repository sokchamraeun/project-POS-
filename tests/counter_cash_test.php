<?php
/**
 * CLI assertions for counter cash settlement.
 * Run:  php tests/counter_cash_test.php
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

echo "pay_return_tab\n";
check('all is allowed',        pay_return_tab('all'),       'all');
check('pending is allowed',    pay_return_tab('pending'),   'pending');
check('paylater is allowed',   pay_return_tab('paylater'),  'paylater');
check('dashboard is allowed',  pay_return_tab('dashboard'), 'dashboard');
// An unvalidated value reaching a Location: header is an open redirect.
check('junk falls back',       pay_return_tab('evil.com'),  'pending');
check('empty falls back',      pay_return_tab(''),          'pending');
check('null falls back',       pay_return_tab(null),        'pending');
check('absolute URL falls back', pay_return_tab('https://evil.com'), 'pending');

echo "pay_return_url\n";
check('all URL',       pay_return_url('all'),       'find_order.php?tab=all');
check('pending URL',   pay_return_url('pending'),   'find_order.php?tab=pending');
check('paylater URL',  pay_return_url('paylater'),  'find_order.php?tab=paylater');
check('dashboard URL', pay_return_url('dashboard'), 'dashboard.php');
// Defence in depth: even if an unvalidated string reaches it.
check('junk URL falls back', pay_return_url('evil.com'), 'find_order.php?tab=pending');

echo "payment_cash back targets\n";
// The map is a literal in the page, so assert on the source rather than booting
// the page (it requires a session and a real order). Assert on strings that are
// genuinely absent today: "'paylater'" and "find_order.php?tab=paylater" already
// appear in this file for unrelated reasons and would pass before the change.
$pc = file_get_contents(__DIR__ . '/../payment_cash.php');
check('back target: pending kept',   strpos($pc, "'pending'   =>") !== false, true);
check('back target: dashboard kept', strpos($pc, "'dashboard' =>") !== false, true);
check('back target: all added',      strpos($pc, "'all'       =>") !== false, true);
check('all active back label',       strpos($pc, 'Back to Active Orders') !== false, true);
// Pay Later is served by the $is_paylater short-circuit at :635, not this map.
// An entry here would be unreachable.
check('no unreachable paylater entry', strpos($pc, "'paylater'  =>") === false, true);

echo "change block gating\n";
// The gate must key on "a tender was recorded", not on the method reading cash.
// A settled pay-later tab deliberately keeps payment_method='paylater' so the
// reporting bucket survives, and would otherwise never show its change.
// The gate itself is now tender_parts() !== null, not is_numeric(). Riel-into-cash
// stores both amounts as "1.00|8000" (dollars|riel), and a riel-only tender reads
// "0.00|5500" — not is_numeric() — so the old gate would have silently written or
// shown nothing for it. tender_parts() accepts exactly the two valid tender shapes
// (bare dollars, or "dollars|riel") and returns null for anything else, including
// a Bakong transaction id, so it is a strict superset of what is_numeric() gated.
$pc = file_get_contents(__DIR__ . '/../payment_cash.php');
check('success screen does not require cash',
      strpos($pc, "\$pay['payment_method'] === 'cash' && is_numeric") === false, true);
check('success screen still requires a recorded tender',
      strpos($pc, "tender_parts(\$pay['reference'])") !== false, true);
// Regression guard: the old gate must not quietly come back, since it would
// silently drop the change block for every riel-only cash tender.
check('success screen no longer gates on is_numeric',
      strpos($pc, "is_numeric(\$pay['reference'])") === false, true);

$rp = file_get_contents(__DIR__ . '/../receipt_pdf.php');
check('receipt gates on a tender, not the method',
      strpos($rp, "\$pay['payment_method'] === 'cash' && \$ref !== ''") === false, true);
check('receipt still requires a recorded tender',
      strpos($rp, 'tender_parts($ref)') !== false, true);
// Regression guard: same as above, for the receipt's split/non-solo branch.
check('receipt no longer gates on is_numeric',
      strpos($rp, 'is_numeric($ref)') === false, true);
// $is_solo_cash must survive: it suppresses the PAYMENT block for a plain cash
// sale, and a pay-later receipt has to keep showing how it was settled.
check('receipt keeps the solo-cash distinction',
      strpos($rp, '$is_solo_cash') !== false, true);

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
