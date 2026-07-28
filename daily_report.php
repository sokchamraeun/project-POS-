<?php
require 'auth.php';
require 'config.php';
if (!can('report')) { header("Location: dashboard.php?denied=1"); exit; }

// Which business day are we reading? Defaults to the one in progress.
$today = business_date_today();
$date  = is_string($_GET['date'] ?? null) ? trim($_GET['date']) : '';
if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
    $date = $today;
}
if ($date > $today) { $date = $today; }
$prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($date . ' +1 day'));
$isToday  = ($date === $today);

// ── Live refresh (Task 9): cheap signature, not a data refetch ──
// dashboard.php re-runs its full KPI queries every 5s for every open browser;
// this endpoint returns only a signature so the client can decide whether a
// reload is even worth doing. Must exit before any HTML is output.
if (isset($_GET['poll'])) {
    header('Content-Type: application/json');
    // COUNT/SUM(total)/MAX(order_date) alone are blind to a settlement or a
    // cancellation: admin_pay_cash.php and cancel_order.php both flip
    // is_open/status without touching total or order_date, but tab 1's
    // collected figure (paid_orders_where()) moves the instant either
    // happens. SUM(is_open) catches settlement; the cancelled/refunded/void
    // count catches a status flip that leaves is_open alone. The two
    // ingredients subqueries catch the case no order touches at all — a
    // restock, a PO receipt, or a stock count — which still moves box 3, the
    // stock lines, and the tab badge, and would otherwise sit stale on a
    // 30s-old "YES" long after an item ran low.
    $stmt = $conn->prepare("
        SELECT COUNT(*), COALESCE(SUM(total),0), COALESCE(SUM(is_open),0),
               COALESCE(SUM(CASE WHEN status IN ('Cancelled','Refunded','Void') THEN 1 ELSE 0 END),0),
               COALESCE(MAX(order_date),''),
               (SELECT COUNT(*) FROM ingredients WHERE stock_quantity <= minimum_stock),
               (SELECT COALESCE(SUM(stock_quantity),0) FROM ingredients)
        FROM orders WHERE business_date = ?
    ");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $sig = implode('|', $stmt->get_result()->fetch_row());
    echo json_encode(['sig' => md5($sig)]);
    exit;
}

// ── Tab 1: the three verdicts (Task 4) ──
// Money we got — the app-wide definition of collected, never hand-rolled.
$stmt = $conn->prepare("SELECT COALESCE(SUM(total),0), COUNT(*) FROM orders WHERE business_date = ? AND " . paid_orders_where());
$stmt->bind_param("s", $date);
$stmt->execute();
[$gotToday, $paidOrderCount] = $stmt->get_result()->fetch_row();
$gotToday = (float)$gotToday;

$ids = [];
$stmt = $conn->prepare("SELECT order_id FROM orders WHERE business_date = ? AND " . paid_orders_where());
$stmt->bind_param("s", $date);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_row()) { $ids[] = (int)$row[0]; }

$costMap  = ingredient_cost_map($conn);
$cogs     = order_cogs($conn, $ids, $costMap);
$keptToday = $gotToday - $cogs['total'];
$centsKept = $gotToday > 0 ? round(($keptToday / $gotToday) * 100) : 0;

$baseGot = weekday_baseline($conn, $date);

/**
 * Turn a difference into the sentence a manager reads. Money, never percent —
 * "9.1% less" is a maths sentence, "$30.50 less" is a money sentence.
 */
function dr_verdict(float $now, ?float $baseline, string $label): array {
    if ($baseline === null) {
        return ['tone' => 'flat', 'line' => 'first day — nothing to compare yet', 'sub' => ''];
    }
    $diff = $now - $baseline;
    if (abs($diff) < 0.005) {
        return ['tone' => 'flat', 'line' => 'the same as ' . $label, 'sub' => ''];
    }
    return [
        'tone' => $diff > 0 ? 'good' : 'bad',
        'line' => '$' . number_format(abs($diff), 2) . ($diff > 0 ? ' MORE' : ' LESS') . ' than',
        'sub'  => $label,
    ];
}

/**
 * The one line under a headline figure that says whether it is good.
 * A number on its own — "TOTAL SALES $14,450" — cannot tell a manager anything;
 * this is the whole reason the page exists, compressed into a single line so it
 * fits a stat card instead of needing a box of its own.
 *
 * $money formats the difference as dollars; otherwise it is a plain count.
 */
function dr_delta(?float $now, ?float $base, string $label, bool $money = true): array {
    if ($base === null || $now === null) {
        return ['tone' => 'flat', 'text' => 'nothing to compare yet'];
    }
    $diff = $now - $base;
    // Counts are whole things. The baseline is an average, so the difference can
    // land on 6.3 — but "6.3 cups less" is not how anyone reads a cup.
    $unit = $money ? '$' . number_format(abs($diff), 2) : (string)(int)round(abs($diff));
    if (($money && abs($diff) < 0.005) || (!$money && round(abs($diff)) < 1)) {
        return ['tone' => 'flat', 'text' => 'same as ' . $label];
    }
    return [
        'tone' => $diff > 0 ? 'good' : 'bad',
        'text' => ($diff > 0 ? '↑ ' : '↓ ') . $unit . ($diff > 0 ? ' more' : ' less') . ' than ' . $label,
    ];
}

$vGot  = dr_verdict($gotToday, $baseGot['value'], $baseGot['label']);

/**
 * What we kept on the baseline days, costed the same way today is.
 *
 * Scaling the takings baseline by today's keep-rate would be cheaper, but it
 * reduces to the takings difference times a constant — box 2 would always
 * agree with box 1 and never tell the manager anything new. A day can take
 * less and keep more (cheap drinks sold instead of dear ones), and that is
 * exactly the day this box exists to catch. So cost the baseline days for real.
 */
$keptBaseline = null;
if ($baseGot['basis'] !== 'none') {
    // Take the day's total from this same read — a second SUM(total) query per
    // day would only re-fetch rows already in hand.
    $stmt = $conn->prepare("
        SELECT business_date, order_id, total
        FROM orders
        WHERE business_date IN (" . implode(',', array_fill(0, count($baseGot['dates']), '?')) . ")
          AND " . paid_orders_where()
    );
    $stmt->bind_param(str_repeat('s', count($baseGot['dates'])), ...$baseGot['dates']);
    $stmt->execute();
    $res = $stmt->get_result();
    $byDay = [];
    $gotByDay = [];
    while ($r = $res->fetch_assoc()) {
        $byDay[$r['business_date']][] = (int)$r['order_id'];
        $gotByDay[$r['business_date']] = ($gotByDay[$r['business_date']] ?? 0.0) + (float)$r['total'];
    }

    $keptSum = 0.0;
    $orderSum = 0;
    $cupSum   = 0;
    foreach ($baseGot['dates'] as $d) {
        $dayIds = $byDay[$d] ?? [];
        $dayCogs = order_cogs($conn, $dayIds, $costMap);
        $keptSum  += ($gotByDay[$d] ?? 0.0) - $dayCogs['total'];
        $orderSum += count($dayIds);
        $cupSum   += cogs_cups($dayCogs);
    }
    $n = count($baseGot['dates']);
    $keptBaseline   = $keptSum / $n;
    // The same baseline days answer every headline stat, so all four cards
    // compare against one consistent idea of "a normal Monday".
    $ordersBaseline = $orderSum / $n;
    $cupsBaseline   = $cupSum / $n;
    $avgBaseline    = $orderSum > 0 ? ($baseGot['value'] * $n) / $orderSum : null;
}
$ordersBaseline = $ordersBaseline ?? null;
$cupsBaseline   = $cupsBaseline   ?? null;
$avgBaseline    = $avgBaseline    ?? null;
$avgToday       = $paidOrderCount > 0 ? $gotToday / $paidOrderCount : 0.0;

$vKept = dr_verdict($keptToday, $keptBaseline, $baseGot['label']);

// Zero sales (the common every-morning-before-the-first-order state) is not
// a "LESS than a normal Saturday" verdict — that reads as an alarm before
// the shop has even had a chance. Neutral and unmissable instead.
// The zero-sales override lives further down, after $notPaidCount is known —
// it needs to tell "nothing sold" apart from "sold, but all still on tabs".

// Plain-words tooltip for box 1's baseline line — only meaningful when the
// line actually states a weekday-average comparison (not the yesterday
// fallback, the no-baseline state, or the zero-sales override above, which
// replace the line with something that makes no baseline claim at all).
$baselineTooltip = '';
if ($paidOrderCount > 0 && $baseGot['basis'] === 'weekday') {
    $baselineTooltip = 'average of your last ' . $baseGot['days'] . ' ' . date('l', strtotime($date)) . 's';
}

// Yesterday's figure stays visible regardless of which baseline drove the
// colour above — a manager glancing at box 1 shouldn't have to open tab 2 to
// see what yesterday did.
$stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) FROM orders WHERE business_date = ? AND " . paid_orders_where());
$stmt->bind_param("s", $prevDate);
$stmt->execute();
$gotYesterday = (float)$stmt->get_result()->fetch_row()[0];

// Stock going DOWN is not bad — it means drinks were sold. Red fires only
// when something will actually stop service tomorrow.
$low = $conn->query("
    SELECT ingredient_name, stock_quantity, minimum_stock, unit
    FROM ingredients
    WHERE stock_quantity <= minimum_stock
    ORDER BY (stock_quantity - minimum_stock) ASC
")->fetch_all(MYSQLI_ASSOC);
$lowItems  = count($low);
$lowNames  = array_column(array_slice($low, 0, 3), 'ingredient_name');
$lowExtra  = max(0, $lowItems - 3);
// Already out is a harder fact than merely low — the alert strip names both.
$outItems  = count(array_filter($low, fn($r) => (float)$r['stock_quantity'] <= 0));

$stockValue = (float)$conn->query("SELECT COALESCE(SUM(stock_quantity * cost_per_unit),0) FROM ingredients")->fetch_row()[0];

// ingredient_history has no business_date column, so match the business day by
// its 06:00-to-06:00 window. Joining through orders would look tidier but drops
// the 33 order_deduct rows that carry a NULL order_id.
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(ABS(h.amount) * i.cost_per_unit),0)
    FROM ingredient_history h
    JOIN ingredients i ON i.ingredient_id = h.ingredient_id
    WHERE h.change_type = 'order_deduct'
      AND h.created_at >= CONCAT(?, ' 06:00:00')
      AND h.created_at <  CONCAT(DATE_ADD(?, INTERVAL 1 DAY), ' 06:00:00')
");
$stmt->bind_param("ss", $date, $date);
$stmt->execute();
$usedValue = (float)$stmt->get_result()->fetch_row()[0];

// ── Tab 1: the neutral row (Task 5) — how the money came in, facts only ──
// How the collected money arrived. Pay-later only counts once settled.
$stmt = $conn->prepare("
    SELECT payment_method, COALESCE(SUM(total),0) AS amount
    FROM orders WHERE business_date = ? AND " . paid_orders_where() . "
    GROUP BY payment_method
");
$stmt->bind_param("s", $date);
$stmt->execute();
$byMethod = [];
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) { $byMethod[strtolower((string)$r['payment_method'])] = (float)$r['amount']; }

$gotCash   = $byMethod['cash'] ?? 0.0;
$gotBakong = $byMethod['bakong'] ?? 0.0;
$gotLater  = $byMethod['paylater'] ?? 0.0;
// Remainder, not enumeration: legacy rows (payment_method='0', 'riel', ...)
// predate the current three-method model. Deriving this from the total
// keeps the cards honest even as old data carries values we don't name here.
$gotOther  = $gotToday - ($gotCash + $gotBakong + $gotLater);

// Money not (yet) collected — every order failing the same paid_orders_where()
// test tab 2's footer uses, minus Cancelled/Void (never owed, never "not paid
// yet"). This used to hand-roll payment_method='paylater' AND is_open=1,
// which silently missed a Refunded or PendingPayment cash/bakong order and
// made this card disagree with tab 2's footer on the same day.
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(total),0), COUNT(*)
    FROM orders
    WHERE business_date = ? AND status NOT IN ('Cancelled','Void') AND NOT (" . paid_orders_where() . ")
");
$stmt->bind_param("s", $date);
$stmt->execute();
[$notPaidYet, $notPaidCount] = $stmt->get_result()->fetch_row();
$notPaidYet   = (float)$notPaidYet;
$notPaidCount = (int)$notPaidCount;

// Two different zero days, and calling both "no sales" is a lie on the second.
// A day whose every order is still on a tab HAS sold drinks — it just has not
// been paid for them. "No sales yet today" printed beside a non-zero "not paid
// yet" card reads as a broken page.
$drAllOnTab = ($paidOrderCount === 0 && $notPaidCount > 0);
$drZeroLine = $drAllOnTab
    ? 'nothing paid yet — every order is still on a tab'
    : 'no sales yet today';
if ($paidOrderCount === 0) {
    $vGot  = ['tone' => 'flat', 'line' => $drZeroLine, 'sub' => ''];
    $vKept = ['tone' => 'flat', 'line' => $drZeroLine, 'sub' => ''];
}

// ── When the money came in, hour by hour ──
// Business days run 06:00 to 06:00, so the axis runs from 6am and wraps past
// midnight rather than starting at hour 0 with a dead morning.
$hourRev = array_fill(0, 24, 0.0);
$stmt = $conn->prepare("
    SELECT HOUR(order_date) h, COALESCE(SUM(total),0) rev
    FROM orders WHERE business_date = ? AND " . paid_orders_where() . "
    GROUP BY HOUR(order_date)
");
$stmt->bind_param("s", $date);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) { $hourRev[(int)$r['h']] = (float)$r['rev']; }

$hourOrder = [];
for ($i = 6; $i < 30; $i++) { $hourOrder[] = $i % 24; }          // 06:00 → 05:00
$hourPeakVal = max($hourRev) ?: 0.0;
$busiestHour = null;
if ($hourPeakVal > 0) {
    foreach ($hourOrder as $h) { if ($hourRev[$h] === $hourPeakVal) { $busiestHour = $h; break; } }
}

// Best seller: $cogs['by_product'] arrives unsorted (insertion order), so sort
// by cups moved. uasort preserves the key, and the key IS the product name.
//
// Loyalty redemptions are skipped. A shirt handed over for points is not a
// thing the shop sold, and on a quiet day it outranks every real drink —
// "[GIFT] Free Shirt (Loyalty)" sat under a heading reading BEST SELLER on
// 31 May, which reads as a broken report rather than a slow day. The same
// is_gift flag drives the PDF and the spreadsheet.
$byProductSorted = array_filter($cogs['by_product'], fn($p) => empty($p['is_gift']));
uasort($byProductSorted, fn($a, $b) => $b['qty'] <=> $a['qty']);
$bestSellerName = null;
$bestSellerQty  = 0;
foreach ($byProductSorted as $bpName => $bpRow) { $bestSellerName = $bpName; $bestSellerQty = (int)$bpRow['qty']; break; }
$cupsToday = cogs_cups($cogs);
$avgCups   = $paidOrderCount > 0 ? $cupsToday / $paidOrderCount : null;

/**
 * For pay-later, Completed means the drinks were made and the customer still
 * owes — is_open is the only trustworthy signal. Getting this wrong has caused
 * three money bugs in this codebase.
 *
 * Non-payment outranks method: an order that fails the same collected test
 * paid_orders_where() uses reads "not paid yet" regardless of what method it
 * carries — a Preparing/is_open=1 cash row has not been paid any more than an
 * open pay-later tab has. Only once collected do we name the method, and
 * anything outside cash/bakong/paylater (legacy payment_method='0'/'riel'
 * rows) reads "other way", matching tab 1's "other ways" card instead of
 * being folded silently into "cash".
 *
 * Returns [label, state, bucket]. bucket is the payment-method category
 * (cash/bakong/paylater/other) and drives the filter pills; state is
 * 'open'/'ok'/'refunded' and drives the amber tint ('open' only). The two
 * are independent — a paylater row keeps bucket=paylater whether or not it
 * is state=open.
 */
function dr_pay_label(array $o): array {
    $m = strtolower((string)$o['payment_method']);
    $bucket = in_array($m, ['cash', 'bakong', 'paylater'], true) ? $m : 'other';

    // Checked before the collected test: money that WAS collected and then
    // given back is not money that was never paid. Its own neutral state
    // (not 'open') keeps it out of the amber "needs attention" styling too.
    if ($o['status'] === 'Refunded') {
        return ['money given back', 'refunded', $bucket];
    }

    $collected = ((int)$o['is_open'] === 0)
        && !in_array($o['status'], ['PendingPayment', 'Cancelled', 'Refunded', 'Void'], true);
    if (!$collected) {
        return ['not paid yet', 'open', $bucket];
    }

    $label = match ($bucket) {
        'paylater' => 'pay later — paid',
        'other'    => 'other way',
        default    => $bucket, // cash, bakong
    };
    return [$label, 'ok', $bucket];
}

// ── Tab 2: Orders (Task 6) — the day's orders and how each was paid ──
function dr_fragment_orders(mysqli $conn, string $date): void {
    $stmt = $conn->prepare("
        SELECT order_id, daily_order_no, customer_name, total, payment_method, status, is_open,
               order_date
        FROM orders
        WHERE business_date = ? AND status NOT IN ('Cancelled','Void')
        ORDER BY order_date ASC
    ");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Neutral counts, never a red card — see banned-vocabulary note.
    $stmt = $conn->prepare("SELECT COUNT(*) FROM order_refunds r JOIN orders o ON o.order_id = r.order_id WHERE o.business_date = ?");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $givenBackCount = (int)$stmt->get_result()->fetch_row()[0];

    $stmt = $conn->prepare("SELECT COUNT(*) FROM order_remakes r JOIN orders o ON o.order_id = r.order_id WHERE o.business_date = ?");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $remadeCount = (int)$stmt->get_result()->fetch_row()[0];

    // Cups per order, for the per-row data attribute the footer's JS sums
    // over the currently-filtered rows.
    //
    // product_id <> 0 drops loyalty redemptions, matching cogs_cups() on tab 1.
    // This footer and that card have disagreed before — 70 against 92 — and the
    // two count cups by different routes, so the same rule has to be written
    // into both. Do not change one without the other.
    $cupsByOrder = [];
    $stmt = $conn->prepare("
        SELECT oi.order_id, COALESCE(SUM(oi.quantity),0) AS cups
        FROM order_items oi
        JOIN orders o ON o.order_id = oi.order_id
        WHERE o.business_date = ? AND oi.product_id <> 0 AND " . paid_orders_where('o') . "
        GROUP BY oi.order_id
    ");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) { $cupsByOrder[(int)$r['order_id']] = (int)$r['cups']; }
    $cupsToday = array_sum($cupsByOrder);

    // Money collected vs. still owed — the same collected test tab 1 uses
    // (paid_orders_where()), never a hand-rolled status check. This is the
    // figure that belongs in a "total" slot; folding in open pay-later tabs
    // here is exactly the confusion that has cost this codebase three money
    // bugs already.
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total),0), COUNT(*) FROM orders WHERE business_date = ? AND " . paid_orders_where());
    $stmt->bind_param("s", $date);
    $stmt->execute();
    [$collectedTotal, $collectedCount] = $stmt->get_result()->fetch_row();
    $collectedTotal = (float)$collectedTotal;

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(total),0), COUNT(*)
        FROM orders
        WHERE business_date = ? AND status NOT IN ('Cancelled','Void') AND NOT (" . paid_orders_where() . ")
    ");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    [$notPaidTotal, $notPaidCount] = $stmt->get_result()->fetch_row();
    $notPaidTotal = (float)$notPaidTotal;

    // Pre-derive each row's label/state/bucket once — the table and the
    // "Other" pill's visibility both need it, and deriving it twice is
    // exactly the desync risk the bucket used to have.
    $rowData = [];
    $hasOther = false;
    foreach ($rows as $o) {
        [$label, $state, $bucket] = dr_pay_label($o);
        if ($bucket === 'other') { $hasOther = true; }
        $rowData[] = [
            'label'  => $label,
            'state'  => $state,
            'bucket' => $bucket,
            'time'   => date('H:i', strtotime($o['order_date'])),
            'no'     => '#' . str_pad((string)(int)$o['daily_order_no'], 4, '0', STR_PAD_LEFT),
            'cust'   => (($name = trim((string)$o['customer_name'])) === '' || $name === 'Guest') ? '—' : $name,
            'total'  => (float)$o['total'],
            'cups'   => $cupsByOrder[(int)$o['order_id']] ?? 0,
        ];
    }
    ?>
    <div class="dr-card dr-wide" style="margin-top:0">
      <p class="dr-note" style="margin-bottom:14px">money given back: <?= (int)$givenBackCount ?> &middot; drinks made again: <?= (int)$remadeCount ?></p>

      <div class="dr-pills" role="group" aria-label="Filter by how the order was paid">
        <button type="button" class="dr-pill is-on" data-filter="all">All</button>
        <button type="button" class="dr-pill" data-filter="cash">Cash</button>
        <button type="button" class="dr-pill" data-filter="bakong">Bakong</button>
        <button type="button" class="dr-pill" data-filter="paylater">Pay later</button>
        <?php if ($hasOther): ?>
        <button type="button" class="dr-pill" data-filter="other">Other</button>
        <?php endif; ?>
      </div>

      <div class="dr-table-wrap">
        <table class="dr-table" id="ordersTable">
          <thead>
            <tr><th>Time</th><th>Order</th><th>Customer</th><th>Total</th><th>Paid</th></tr>
          </thead>
          <tbody>
            <?php if (!$rowData): ?>
            <tr><td colspan="5" class="dr-note" style="padding:20px 0;text-align:center">no orders this day</td></tr>
            <?php endif; ?>
            <?php foreach ($rowData as $rd): ?>
            <tr class="<?= $rd['state'] === 'open' ? 'is-open' : '' ?>"
                data-method="<?= htmlspecialchars($rd['bucket']) ?>"
                data-state="<?= htmlspecialchars($rd['state']) ?>"
                data-total="<?= htmlspecialchars((string)$rd['total']) ?>"
                data-cups="<?= (int)$rd['cups'] ?>">
              <td class="dr-mono-dim"><?= htmlspecialchars($rd['time']) ?></td>
              <td class="dr-mono"><?= htmlspecialchars($rd['no']) ?></td>
              <td><?= htmlspecialchars($rd['cust']) ?></td>
              <td class="dr-mono">$<?= htmlspecialchars(number_format($rd['total'], 2)) ?></td>
              <td><span class="dr-status is-<?= htmlspecialchars($rd['state']) ?>"><?= htmlspecialchars($rd['label']) ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="dr-table-foot" id="ordersFoot">
        <span><?= (int)count($rowData) ?> orders</span> &middot; <span><?= (int)$cupsToday ?> cups</span> &middot; <span>$<?= htmlspecialchars(number_format($collectedTotal, 2)) ?> collected</span><?php if ($notPaidCount > 0): ?> &middot; <span>$<?= htmlspecialchars(number_format($notPaidTotal, 2)) ?> not paid yet</span><?php endif; ?>
      </div>

      <div class="pg-wrap" id="ordersPagination" style="display:none"></div>
    </div>
    <?php
}

// ── Tab 3: Stock (Task 7) — levels, what today used, what needs buying ──
/**
 * Quantity formatting shared by the stock table: whole numbers print plain,
 * fractional ones (ingredient_history.amount is DECIMAL(10,4), so "used
 * today" can be fractional even though stock_quantity/minimum_stock are
 * always ints) get two decimal places.
 */
function dr_qty(float $n): string {
    return (abs($n - round($n)) < 0.005) ? number_format($n, 0) : number_format($n, 2);
}

function dr_fragment_stock(mysqli $conn, string $date): void {
    // Same 06:00-to-06:00 business-day window as tab 1's usedValue query —
    // ingredient_history has no business_date column, and joining through
    // orders would silently drop the order_deduct rows with a NULL order_id.
    $stmt = $conn->prepare("
        SELECT i.ingredient_id, i.ingredient_name, i.unit, i.stock_quantity,
               i.minimum_stock, i.cost_per_unit,
               COALESCE(u.used, 0) AS used_today
        FROM ingredients i
        LEFT JOIN (
            SELECT ingredient_id, SUM(ABS(amount)) AS used
            FROM ingredient_history
            WHERE change_type = 'order_deduct'
              AND created_at >= CONCAT(?, ' 06:00:00')
              AND created_at <  CONCAT(DATE_ADD(?, INTERVAL 1 DAY), ' 06:00:00')
            GROUP BY ingredient_id
        ) u ON u.ingredient_id = i.ingredient_id
        ORDER BY (i.stock_quantity - i.minimum_stock) ASC, i.ingredient_name ASC
    ");
    $stmt->bind_param("ss", $date, $date);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $trackedCount = count($rows);
    $buyCount = 0;
    $outCount = 0;
    $rowData  = [];
    foreach ($rows as $r) {
        $stock = (int)$r['stock_quantity'];
        $min   = (int)$r['minimum_stock'];
        $used  = (float)$r['used_today'];
        $cost  = (float)$r['cost_per_unit'];

        // Three logical states, but only two the manager needs to act on:
        // "buy now" is the only one that ever gets amber — it is also the
        // exact test tab 1's $lowItems uses, so the badge and this table
        // never disagree. "low" is shown as a heads-up label only, styled
        // the same neutral way as "OK" — nothing red or green on this tab.
        if ($stock <= $min) {
            $bucket = 'buy';   $statusLabel = 'buy now';
            $buyCount++;
        } elseif ($min > 0 && $stock <= $min * 1.25) {
            $bucket = 'ok';    $statusLabel = 'getting low';
        } else {
            $bucket = 'ok';    $statusLabel = 'have enough';
        }
        if ($stock <= 0) { $outCount++; }

        $barBase = max(1, $min * 2);
        $barPct  = min(100, ($stock / $barBase) * 100);

        $rowData[] = [
            'name'    => (string)$r['ingredient_name'],
            'unit'    => (string)$r['unit'],
            'stock'   => $stock,
            'min'     => $min,
            'used'    => $used,
            'cost'    => $cost,
            'bucket'  => $bucket,
            'label'   => $statusLabel,
            'needBuy' => $bucket === 'buy',
            'barPct'  => $barPct,
        ];
    }
    ?>
    <div class="dr-card dr-wide" style="margin-top:0">
      <div class="dr-facts-inline" style="margin-top:0">
        <div class="dr-fact"><div class="dr-v-sm"><?= (int)$trackedCount ?></div><div class="dr-note">items we track</div></div>
        <div class="dr-fact"><div class="dr-v-sm"><?= (int)$buyCount ?></div><div class="dr-note">items to buy</div></div>
        <div class="dr-fact"><div class="dr-v-sm"><?= (int)$outCount ?></div><div class="dr-note">items already out</div></div>
      </div>

      <div class="dr-toolbar">
        <div class="dr-pills" role="group" aria-label="Filter by whether we need to buy more">
          <button type="button" class="dr-pill is-on" data-filter="all">All</button>
          <button type="button" class="dr-pill" data-filter="buy">Need buying</button>
          <button type="button" class="dr-pill" data-filter="ok">OK</button>
        </div>
        <input type="text" class="dr-search" id="stockSearch" placeholder="Search ingredient…" aria-label="Search ingredients">
      </div>

      <div class="dr-table-wrap">
        <table class="dr-table" id="stockTable">
          <thead>
            <tr><th>Item</th><th>We have</th><th>Buy more below</th><th>Used today</th><th>What it costs us (per unit)</th><th>Level</th></tr>
          </thead>
          <tbody>
            <?php if (!$rowData): ?>
            <tr><td colspan="6" class="dr-note" style="padding:20px 0;text-align:center">no ingredients tracked</td></tr>
            <?php endif; ?>
            <?php foreach ($rowData as $rd): ?>
            <tr class="<?= $rd['needBuy'] ? 'needs-buy' : '' ?>"
                data-bucket="<?= htmlspecialchars($rd['bucket']) ?>"
                data-name="<?= htmlspecialchars(mb_strtolower($rd['name'])) ?>">
              <td><?= htmlspecialchars($rd['name']) ?></td>
              <td class="dr-mono"><?= htmlspecialchars(dr_qty($rd['stock'])) ?> <?= htmlspecialchars($rd['unit']) ?></td>
              <td class="dr-mono-dim"><?= htmlspecialchars(dr_qty($rd['min'])) ?> <?= htmlspecialchars($rd['unit']) ?></td>
              <td class="dr-mono-dim"><?= htmlspecialchars(dr_qty($rd['used'])) ?> <?= htmlspecialchars($rd['unit']) ?></td>
              <td class="dr-mono-dim">$<?= htmlspecialchars(number_format($rd['cost'], 4)) ?> / <?= htmlspecialchars($rd['unit']) ?></td>
              <td>
                <div class="dr-stock-bar"><span class="dr-stock-fill <?= $rd['needBuy'] ? 'tone-attn' : 'tone-normal' ?>" style="width:<?= round($rd['barPct'], 2) ?>%"></span></div>
                <div style="margin-top:5px"><span class="dr-status <?= $rd['needBuy'] ? 'is-open' : 'is-neutral' ?>"><?= htmlspecialchars($rd['label']) ?></span></div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="dr-table-foot" id="stockFoot"></div>
      <div class="pg-wrap" id="stockPagination" style="display:none"></div>
    </div>
    <?php
}

// ── Tab 4: Staff (Task 8) — who worked today and what they served ──
//
// LANDMINE: orders.employee_id is a FK to employees.employee_id, which is NOT
// users.user_id and the two never coincide. attendance keys on user_id. The
// only correct chain is attendance.user_id -> employees.user_id ->
// employees.employee_id -> orders.employee_id. Joining orders to attendance
// any more directly attributes work to the wrong person (or nobody) and has
// produced silently wrong staff figures in this codebase before.
// employees.user_id carries a UNIQUE key, so this chain cannot fan out one
// attendance row into more than one employee.

/**
 * "two shifts" / "three shifts" — spelled out for the common small counts a
 * split-shift day actually produces, numeric fallback beyond that.
 */
function dr_shift_note(int $n): string {
    static $words = [2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five'];
    return ($words[$n] ?? (string)$n) . ' shifts';
}

function dr_fragment_staff(mysqli $conn, string $date): void {
    // Order/money aggregates are computed per employee_id in their own
    // subquery, then joined in once. Aggregating orders directly against a
    // one-row-per-shift attendance join would fan out: a person with two
    // attendance rows that day would multiply their order rows by two before
    // GROUP BY ever runs, doubling both the order count and the money.
    $stmt = $conn->prepare("
        SELECT e.employee_id, e.name AS full_name,
               MIN(a.clock_in)                                    AS clock_in,
               MAX(a.clock_out)                                   AS clock_out,
               SUM(a.hours_worked)                                AS hours_worked,
               COUNT(a.id)                                        AS shift_count,
               SUM(a.clock_out IS NULL)                           AS open_shifts,
               COALESCE(MAX(o.orders_served), 0)                  AS orders_served,
               COALESCE(MAX(o.money_taken), 0)                    AS money_taken
        FROM attendance a
        JOIN employees e ON e.user_id = a.user_id
        LEFT JOIN (
            SELECT employee_id,
                   COUNT(order_id)          AS orders_served,
                   COALESCE(SUM(total), 0)  AS money_taken
            FROM orders
            WHERE business_date = ? AND " . paid_orders_where() . "
            GROUP BY employee_id
        ) o ON o.employee_id = e.employee_id
        WHERE a.date = ?
        GROUP BY e.employee_id
        ORDER BY MIN(a.clock_in) ASC
    ");
    $stmt->bind_param("ss", $date, $date);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Money a person "took" means money actually collected (paid_orders_where
    // above), never an open pay-later tab they merely rang up. One row per
    // person now (split shifts collapsed above), so a plain sum is correct —
    // no per-employee dedup needed.
    $peopleCount = count($rows);
    $ordersTotal = 0;
    $moneyTotal  = 0.0;
    foreach ($rows as $r) {
        $ordersTotal += (int)$r['orders_served'];
        $moneyTotal  += (float)$r['money_taken'];
    }

    // The day's collected money (tab 1's own figure, same paid_orders_where()
    // test) minus what landed on someone who was actually clocked in. Orders
    // with a NULL employee_id — or an employee_id that isn't in today's
    // attendance at all — never show up in $moneyTotal above, so without this
    // line the table would silently show a column of dashes next to a tab 1
    // total that says money came in, and read as broken rather than honest.
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total), 0) FROM orders WHERE business_date = ? AND " . paid_orders_where());
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $collectedTotal = (float)$stmt->get_result()->fetch_row()[0];
    $unlinkedMoney  = $collectedTotal - $moneyTotal;
    ?>
    <div class="dr-card dr-wide" style="margin-top:0">
      <div class="dr-table-wrap">
        <table class="dr-table" id="staffTable">
          <thead>
            <tr><th>Name</th><th>Clocked in</th><th>Clocked out</th><th>Hours</th><th>Orders served</th><th>Money taken</th></tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
            <tr><td colspan="6" class="dr-note" style="padding:20px 0;text-align:center">no one worked this day</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
                // Any open shift that day means the person reads as still
                // working — the normal case for a manager reading this
                // mid-shift, not an edge case. hours_worked is genuinely NULL
                // until clock-out sets it, so a shift in progress must never
                // be summed into fmt_hours() (non-nullable float param); we
                // simply don't use the hours figure while still working.
                $stillWorking = (int)$r['open_shifts'] > 0;
                $shiftCount   = (int)$r['shift_count'];
                $orders = (int)$r['orders_served'];
                $money  = (float)$r['money_taken'];
                $served = $orders > 0;

                $hoursCell = $stillWorking
                    ? 'still working'
                    : ($r['hours_worked'] !== null ? fmt_hours((float)$r['hours_worked']) : '—');
                if ($shiftCount > 1) { $hoursCell .= ' · ' . dr_shift_note($shiftCount); }
            ?>
            <?php
                // Initials for the avatar: first letter of the first two words,
                // so "Sok Dara" reads SD and a single name reads one letter.
                $parts = preg_split('/\s+/', trim((string)$r['full_name']));
                $initials = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''));
            ?>
            <tr>
              <td>
                <span class="dr-who">
                  <span class="dr-av" aria-hidden="true"><?= htmlspecialchars($initials) ?></span>
                  <?= htmlspecialchars((string)$r['full_name']) ?>
                </span>
              </td>
              <td class="dr-mono-dim"><?= htmlspecialchars(date('H:i', strtotime($r['clock_in']))) ?></td>
              <td class="dr-mono-dim"><?= $stillWorking ? '<span class="dr-status is-ok">still working</span>' : htmlspecialchars(date('H:i', strtotime($r['clock_out']))) ?></td>
              <td class="dr-mono"><?= htmlspecialchars($hoursCell) ?></td>
              <td class="dr-mono"><?= $served ? (int)$orders : '—' ?></td>
              <td class="dr-mono"><?= $served ? '$' . htmlspecialchars(number_format($money, 2)) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($rows): ?>
      <div class="dr-table-foot" id="staffFoot">
        <span><?= (int)$peopleCount ?> people worked</span> &middot; <span><?= (int)$ordersTotal ?> orders served</span> &middot; <span>$<?= htmlspecialchars(number_format($moneyTotal, 2)) ?> taken</span>
      </div>
      <?php endif; ?>

      <?php if ($unlinkedMoney > 0.005): ?>
      <p class="dr-note" style="margin-top:12px">$<?= htmlspecialchars(number_format($unlinkedMoney, 2)) ?> collected today is not linked to anyone who was clocked in.</p>
      <?php endif; ?>
    </div>
    <?php
}

// Tabs 2-4 ask for their own HTML. Each branch echoes a fragment and exits.
// This MUST run before any HTML output — a fragment response is inlined into
// a tab panel by JS, so it must never carry the page chrome.
$fragment = $_GET['fragment'] ?? '';
if ($fragment !== '') {
    header('Content-Type: text/html; charset=utf-8');
    switch ($fragment) {
        case 'orders': dr_fragment_orders($conn, $date); break;
        case 'stock':  dr_fragment_stock($conn, $date); break;
        case 'staff':  dr_fragment_staff($conn, $date); break;
        default: http_response_code(404); echo 'Unknown tab.';
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Daily Report | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script>(function(){try{if(localStorage.getItem("theme")==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0a0a0a;--surface:#111;--surface2:#161616;--border:rgba(255,255,255,.07);
  --amber:#d1904b;--amber-dim:rgba(209,144,75,.12);--amber-border:rgba(209,144,75,.2);
  --text:#f0f0f0;--muted:#555;--muted2:#888;
  --bg-card:var(--surface);--text-muted:var(--muted2);
  --radius:10px;
  --bar:#16130f;              /* brand bar — near-black in both themes */
  --bar-text:#f3f1ee;
  --ok:#2f9e5f;--ok-bg:rgba(47,158,95,.14);
  --warn:#c98a2e;--warn-bg:rgba(201,138,46,.14);
  --stop:#d1544a;--stop-bg:rgba(209,84,74,.14);
  /* Data — counts, ids, times, money in tables — is set in mono. Numbers line
     up column to column and stop reading as prose. System stack: no web font
     to load, so this costs nothing. */
  --mono:ui-monospace,SFMono-Regular,"SF Mono",Menlo,Consolas,"Liberation Mono",monospace;
}
[data-theme="light"]{
  --bg:#f6f5f3;--surface:#FFFFFF;--surface2:#faf9f7;--border:#e6e3de;
  --text:#141210;--muted:#9a8f84;--muted2:#6b6259;
  --bg-card:var(--surface);--text-muted:var(--muted2);
  --ok:#1f7a45;--ok-bg:#e8f4ec;
  --warn:#9a6410;--warn-bg:#fbf1de;
  --stop:#b0342a;--stop-bg:#fbeae8;
}
body{
  font-family:'Poppins',sans-serif;
  background:var(--bg);
  color:var(--text);min-height:100vh;
}

/* ── Brand bar ── the app's name and where you are, before anything else. */
.dr-topbar{
  background:var(--bar);color:var(--bar-text);
  padding:0 20px;
}
.dr-topbar-in{
  max-width:1180px;margin:0 auto;height:52px;
  display:flex;align-items:center;gap:12px;
}
.dr-topbar-mark{
  width:26px;height:26px;border-radius:7px;background:var(--amber);
  display:inline-flex;align-items:center;justify-content:center;color:#1a1207;font-size:13px;
}
.dr-topbar-name{font-size:14px;font-weight:700;letter-spacing:.2px}
.dr-topbar-sep{color:rgba(243,241,238,.28)}
.dr-topbar-where{font-family:var(--mono);font-size:12px;color:rgba(243,241,238,.62)}
.dr-topbar-right{margin-left:auto;font-family:var(--mono);font-size:12px;color:rgba(243,241,238,.62)}
.dr-topbar-right a{color:inherit;text-decoration:none}
.dr-topbar-right a:hover{color:var(--amber)}

.wrap{max-width:1180px;margin:0 auto;padding:22px 20px 60px}

.back-btn{
  display:inline-flex;align-items:center;gap:8px;text-decoration:none;color:var(--amber);
  font-size:13.5px;font-weight:600;padding:9px 16px;border-radius:10px;
  border:1px solid var(--amber-border);background:var(--amber-dim);transition:all .2s;
  margin-bottom:18px;
}
.back-btn:hover{background:rgba(209,144,75,.22)}
.dr-live{
  display:inline-block;width:7px;height:7px;border-radius:50%;background:#4ade80;
  margin-right:7px;vertical-align:middle;
}

/* ── Header ── */
.dr-head{
  display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:14px;
  margin-bottom:18px;
}
.dr-eyebrow{font-family:var(--mono);font-size:11px;font-weight:500;color:var(--muted2);text-transform:uppercase;letter-spacing:1.4px;margin-bottom:5px}
.dr-head h1{font-size:26px;font-weight:700;color:var(--text);letter-spacing:-.4px}
.dr-head-sub{font-family:var(--mono);font-size:12px;color:var(--muted2);margin-top:5px}
.dr-head-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.dr-nav{
  display:inline-flex;align-items:center;gap:7px;padding:9px 14px;border-radius:9px;
  background:var(--surface);border:1px solid var(--border);color:var(--text);
  font-size:13px;font-weight:600;text-decoration:none;cursor:pointer;font-family:'Poppins',sans-serif;
  transition:all .2s;
}
.dr-nav:hover{border-color:var(--amber);color:var(--amber)}
.dr-nav.is-disabled{pointer-events:none;opacity:.35}

/* ── Tabs ── */
.dr-tabs{
  display:flex;gap:2px;border-bottom:1px solid var(--border);margin-bottom:20px;
  overflow-x:auto;
  /* Scrolls on a narrow screen without ever drawing a bar across the tabs. */
  scrollbar-width:none;-ms-overflow-style:none;
}
.dr-tabs::-webkit-scrollbar{display:none}
.dr-tab{
  appearance:none;background:none;border:none;border-bottom:2px solid transparent;
  padding:12px 16px;font-size:13.5px;font-weight:600;color:var(--muted2);
  font-family:'Poppins',sans-serif;cursor:pointer;white-space:nowrap;
  display:inline-flex;align-items:center;gap:8px;margin-bottom:-1px;
}
.dr-tab i{font-size:12px;opacity:.85}
.dr-tab:hover{color:var(--text)}
.dr-tab.is-on{color:var(--text);border-bottom-color:var(--amber)}
.dr-tab.is-on i{color:var(--amber);opacity:1}
.dr-badge{
  display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;
  padding:0 6px;border-radius:20px;background:var(--amber);
  color:#1a1207;font-family:var(--mono);font-size:10.5px;font-weight:700;
}
.dr-badge:empty{display:none}

/* ── Panels ── */
.dr-panel{animation:fadeInUp .3s ease both}
@keyframes fadeInUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.dr-card{
  background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  padding:18px 20px;
}
.dr-loading,.dr-error{
  padding:48px 20px;text-align:center;color:var(--muted2);font-size:13.5px;
}
.dr-error button{
  margin-left:8px;padding:6px 12px;border-radius:8px;border:1px solid var(--amber-border);
  background:var(--amber-dim);color:var(--amber);font-size:12.5px;font-weight:600;
  cursor:pointer;font-family:'Poppins',sans-serif;
}

/* ── Tab 1: the three verdicts ── */

.tone-good .dr-line { color: var(--ok); }
.tone-bad  .dr-line { color: var(--stop); }
.tone-flat .dr-line { color: var(--text-muted); }

.dr-q     { font-family: var(--mono); font-size: 11px; font-weight: 500; letter-spacing: .1em; text-transform: uppercase; color: var(--text-muted); }
/* Khmer needs more leading than Latin or the diacritics clip. */
.dr-sub   { font-size: 13px;   color: var(--text-muted); }
.dr-line  { font-weight: 700; margin-top: 10px; }
.dr-foot  { font-size: 12px; color: var(--text-muted); margin-top: 8px; line-height: 1.7; }

/* ── Headline stats ── the reference's card row, with a comparison line where
   it had a static caption. Colour lives on that line only: it is the one part
   that carries a judgement, so it is the one part allowed to be red or green. */
.dr-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(195px, 1fr)); gap: 14px; }
.dr-stat  { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px 18px; }
.dr-stat.is-lead { border-left: 3px solid var(--amber); }
.dr-v-lg  { font-size: 28px; font-weight: 800; font-variant-numeric: tabular-nums; margin: 6px 0 8px; letter-spacing: -.5px; }
.dr-delta { font-size: 12px; font-weight: 600; line-height: 1.5; }
.dr-delta.tone-good { color: var(--ok); }
.dr-delta.tone-bad  { color: var(--stop); }
.dr-delta.tone-flat { color: var(--text-muted); font-weight: 500; }

/* Stock warning, placed where the reference puts it: a strip under the stats,
   not a card competing with them. */
.dr-alert {
  display: flex; align-items: flex-start; gap: 10px; margin-top: 14px;
  padding: 12px 16px; border-radius: var(--radius); font-size: 13px; line-height: 1.55;
  background: var(--warn-bg); border: 1px solid var(--amber-border); color: var(--text);
}
.dr-alert i { color: var(--warn); margin-top: 2px; }
.dr-alert.is-clear { background: var(--ok-bg); border-color: rgba(47,158,95,.25); }
.dr-alert.is-clear i { color: var(--ok); }

/* ── Tab 1: the neutral row — facts, no colour ── */
.dr-facts { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-top: 16px; }
@media (max-width: 900px) { .dr-facts { grid-template-columns: repeat(2, 1fr); } }
.dr-k     { font-family: var(--mono); font-size: 11px; font-weight: 500; letter-spacing: .1em; text-transform: uppercase; color: var(--text-muted); }
.dr-v     { font-size: 22px; font-weight: 800; font-variant-numeric: tabular-nums; margin-top: 2px; }
/* Facts sit in cards like the reference's stat row: hairline box, quiet label
   above, figure below. The lead card takes a rule so the eye starts there. */
.dr-facts > .dr-card, .dr-facts-inline > .dr-fact {
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 16px;
}
.dr-facts > .dr-card:first-child { border-left: 3px solid var(--amber); }
.dr-note  { font-size: 12px; color: var(--text-muted); margin-top: 4px; line-height: 1.6; }
.dr-wide  { margin-top: 16px; }

.dr-bar   { display: flex; width: 100%; height: 14px; border-radius: 8px; overflow: hidden; background: var(--surface2); margin-top: 10px; }
.seg      { display: block; height: 100%; }
/* One hue, descending strength — segments are labelled underneath, so they
   don't need to be independently identifiable by colour. Never red/green:
   colour on this page means "act on this", reserved for the verdict boxes. */
.seg-cash   { background: rgba(209,144,75,1);    }
.seg-bakong { background: rgba(209,144,75,.65);  }
.seg-later  { background: rgba(209,144,75,.4);   }
.seg-other  { background: rgba(209,144,75,.22);  }

/* Auto-fit, not a fixed four: a row of three stats should fill the width
   rather than leave a dead fourth column. */
.dr-facts-inline { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; margin-top: 12px; }

/* ── Charts ── drawn by hand in CSS and SVG. No chart library: a CDN that
   fails on venue wifi is a blank chart in front of a judge. */
.dr-charts { display: grid; grid-template-columns: 1.6fr 1fr; gap: 14px; margin-top: 16px; }
@media (max-width: 900px) { .dr-charts { grid-template-columns: 1fr; } }

.dr-hours { display: flex; align-items: flex-end; gap: 3px; height: 150px; margin-top: 14px; }
.dr-hour  { flex: 1; height: 100%; display: flex; align-items: flex-end; border-radius: 3px; }
.dr-hour-fill { display: block; width: 100%; background: var(--amber); opacity: .45; border-radius: 3px; transition: opacity .15s; }
.dr-hour:hover .dr-hour-fill { opacity: .8; }
.dr-hour.is-peak .dr-hour-fill { opacity: 1; }
/* Midnight. Marked because the axis crosses a calendar day mid-chart and a
   reader who doesn't know the 06:00 business day would otherwise read the
   post-midnight bars as an error. */
.dr-hour.is-daybreak { border-left: 1px dashed var(--border); margin-left: 2px; padding-left: 2px; }
.dr-hours-axis { display: flex; justify-content: space-between; font-family: var(--mono); font-size: 10px; color: var(--muted2); margin-top: 6px; }

.dr-donut-row { display: flex; align-items: center; gap: 16px; margin-top: 12px; flex-wrap: wrap; }
.dr-donut { width: 140px; height: 140px; flex: none; transform: rotate(-90deg); }
.dr-donut-track { fill: none; stroke: var(--surface2); stroke-width: 16; }
.dr-donut-seg   { fill: none; stroke-width: 16; }
.dr-donut-mid, .dr-donut-sub { transform: rotate(90deg); transform-origin: 70px 70px; text-anchor: middle; }
.dr-donut-mid { font-family: var(--mono); font-size: 15px; font-weight: 700; fill: var(--text); }
.dr-donut-sub { font-family: var(--mono); font-size: 9px; fill: var(--muted2); letter-spacing: .1em; }
.dr-legend { list-style: none; display: flex; flex-direction: column; gap: 7px; font-size: 12.5px; color: var(--text-muted); }
.dr-legend b { color: var(--text); font-family: var(--mono); font-size: 12px; margin-left: 4px; }
.dr-dot { display: inline-block; width: 9px; height: 9px; border-radius: 3px; margin-right: 7px; }
.dr-dot.seg-cash{background:rgba(209,144,75,1)} .dr-dot.seg-bakong{background:rgba(209,144,75,.65)}
.dr-dot.seg-later{background:rgba(209,144,75,.4)} .dr-dot.seg-other{background:rgba(209,144,75,.22)}
.dr-donut-seg.seg-cash{stroke:rgba(209,144,75,1)} .dr-donut-seg.seg-bakong{stroke:rgba(209,144,75,.65)}
.dr-donut-seg.seg-later{stroke:rgba(209,144,75,.4)} .dr-donut-seg.seg-other{stroke:rgba(209,144,75,.22)}
.dr-fact  { display: flex; flex-direction: column; }
.dr-v-sm  { font-size: 20px; font-weight: 800; font-variant-numeric: tabular-nums; }
.dr-v-text{ font-size: 20px; font-weight: 700; font-variant-numeric: initial; letter-spacing: 0; }

/* ── Tab 2: Orders ── */
.dr-pills   { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
.dr-pill    {
  appearance: none; font-family: 'Poppins',sans-serif; cursor: pointer;
  padding: 7px 14px; border-radius: 20px; font-size: 12.5px; font-weight: 600;
  background: var(--surface2); border: 1px solid var(--border); color: var(--text-muted);
  transition: all .15s;
}
.dr-pill:hover  { color: var(--text); }
.dr-pill.is-on  { background: var(--amber-dim); border-color: var(--amber-border); color: var(--amber); }

.dr-table-wrap  { overflow-x: auto; }
.dr-table       { width: 100%; border-collapse: collapse; font-size: 13.5px; }
.dr-table th    {
  text-align: left; font-family: var(--mono); font-size: 10.5px; font-weight: 500; letter-spacing: .1em; text-transform: uppercase;
  color: var(--text-muted); padding: 10px; border-bottom: 1px solid var(--border);
  background: var(--surface2);
}
.dr-table td    { padding: 11px 10px; border-bottom: 1px solid var(--border); font-variant-numeric: tabular-nums; }
.dr-table tbody tr:hover td { background: var(--surface2); }
.dr-table tbody tr:last-child td { border-bottom: none; }
/* Data columns — ids, clock times, money — set in mono so they align down the
   column and stop reading as prose. */
.dr-mono { font-family: var(--mono); font-size: 12.5px; }
.dr-mono-dim { font-family: var(--mono); font-size: 12.5px; color: var(--text-muted); }

/* Status pills. These are row-level state, not verdicts: small, inside dense
   text, describing one line. The big cards stay uncoloured. */
.dr-status {
  display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 20px;
  font-family: var(--mono); font-size: 10.5px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase;
}
.dr-status.is-ok       { background: var(--ok-bg);   color: var(--ok); }
.dr-status.is-open     { background: var(--warn-bg); color: var(--warn); }
.dr-status.is-refunded { background: var(--stop-bg); color: var(--stop); }
.dr-status.is-neutral  { background: var(--surface2); color: var(--text-muted); }

/* Initial avatars on the staff table, as in the reference. */
.dr-who { display: inline-flex; align-items: center; gap: 9px; }
.dr-av  {
  width: 26px; height: 26px; border-radius: 50%; flex: none;
  display: inline-flex; align-items: center; justify-content: center;
  font-family: var(--mono); font-size: 10.5px; font-weight: 700;
  background: var(--amber-dim); color: var(--amber); border: 1px solid var(--amber-border);
}
/* Amber = needs attention, not a verdict colour — reserved for tab 1. */
.dr-table tr.is-open td { background: var(--amber-dim); }
.dr-table tr.is-open td:first-child { border-left: 3px solid var(--amber); }

.dr-table-foot  { display: flex; gap: 10px; margin-top: 12px; font-size: 12.5px; color: var(--text-muted); }
/* Pagination — same component as ingredients.php so paging feels identical
   wherever a table appears in this app. Kept selector-for-selector. */
.pg-wrap { padding:14px 0 0; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
.pg-nav { display:flex; gap:4px; flex-wrap:wrap; }
.pg-btn { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text-muted); font-size:13px; font-weight:600; text-decoration:none; cursor:pointer; transition:.15s ease; }
.pg-btn:hover { border-color:var(--amber); color:var(--amber); }
.pg-active { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; background:var(--amber); border:1px solid var(--amber); color:#000; font-size:13px; font-weight:700; }
.pg-disabled { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; border:1px solid var(--border); color:var(--text-muted); font-size:13px; opacity:.35; cursor:default; }
.pg-ellipsis { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; color:var(--text-muted); font-size:13px; }
.pg-info { font-size:12px; color:var(--text-muted); }

/* ── Tab 3: Stock ── */
.dr-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin: 16px 0 14px; }
.dr-search  {
  font-family: 'Poppins',sans-serif; font-size: 12.5px; color: var(--text);
  background: var(--surface2); border: 1px solid var(--border); border-radius: 20px;
  padding: 8px 14px; min-width: 200px;
}
.dr-search:focus { outline: none; border-color: var(--amber); }
/* Amber = needs attention, same rule as the orders tab's open rows — never a
   verdict colour. Rows that don't need buying get a neutral, colourless bar. */
.dr-table tr.needs-buy td { background: var(--amber-dim); }
.dr-table tr.needs-buy td:first-child { border-left: 3px solid var(--amber); }
.dr-stock-bar  { width: 100px; height: 8px; border-radius: 6px; overflow: hidden; background: var(--surface2); }
.dr-stock-fill { display: block; height: 100%; }
.dr-stock-fill.tone-attn   { background: var(--amber); }
.dr-stock-fill.tone-normal { background: var(--muted2); }

.dr-foot-bar{
  display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;
  margin-top:28px;padding-top:14px;border-top:1px solid var(--border);
  font-family:var(--mono);font-size:11px;color:var(--muted2);
}

@media print {
    .dr-tabs, .dr-head-actions, .dr-nav, .back-btn, .dr-topbar, .dr-foot-bar { display: none !important; }
    .dr-panel[hidden] { display: none !important; }
    body { background: #fff !important; color: #000 !important; }
    .dr-card { break-inside: avoid; border: 1px solid #ccc !important; }
}
</style>
</head>
<body>

<div class="dr-topbar">
  <div class="dr-topbar-in">
    <span class="dr-topbar-mark"><i class="fa-solid fa-mug-hot"></i></span>
    <span class="dr-topbar-name">The Bird's Nest Coffee</span>
    <span class="dr-topbar-sep">·</span>
    <span class="dr-topbar-where">Daily Report</span>
    <span class="dr-topbar-right"><?= $isToday ? '<span class="dr-live"></span>live · ' : '' ?><?= htmlspecialchars(date('F Y', strtotime($date))) ?></span>
  </div>
</div>

<div class="wrap">

    <a href="dashboard.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>

    <div class="dr-head">
        <div>
            <div class="dr-eyebrow">DAILY REPORT</div>
            <h1><?= date('l, F j, Y', strtotime($date)) ?></h1>
            <div class="dr-head-sub"><?= htmlspecialchars($date) ?><?= $isToday ? ' · today' : '' ?></div>
        </div>
        <div class="dr-head-actions">
            <a class="dr-nav" href="?date=<?= htmlspecialchars($prevDate) ?>"><i class="fa-solid fa-chevron-left"></i> Yesterday</a>
            <?php if (!$isToday): /* On today there is no next day to show — drop the
                    button rather than render a dead one. On a past date it stays, because
                    it is how a manager walks back forward toward the present. */ ?>
            <a class="dr-nav" href="?date=<?= htmlspecialchars($nextDate) ?>">Tomorrow <i class="fa-solid fa-chevron-right"></i></a>
            <?php endif; ?>
            <?php /* Both exports are built on the server from the same shared helpers this
                    page uses, so neither can drift from what is on screen. Print opens the
                    PDF in a new tab rather than printing the page: the browser's own print
                    of a tabbed, lazily-loaded report only ever captured the visible tab. */ ?>
            <a class="dr-nav" href="daily_report_xlsx.php?date=<?= htmlspecialchars($date) ?>"><i class="fa-solid fa-file-excel"></i> Excel</a>
            <a class="dr-nav" href="daily_report_pdf.php?date=<?= htmlspecialchars($date) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-print"></i> Print</a>
            <a class="dr-nav" href="report.php?mode=daily&date=<?= htmlspecialchars($date) ?>">Full analytics <i class="fa-solid fa-arrow-right"></i></a>
        </div>
    </div>

    <div class="dr-tabs" role="tablist">
        <button class="dr-tab is-on" data-tab="today"  role="tab"><i class="fa-solid fa-chart-simple"></i> Today</button>
        <button class="dr-tab"       data-tab="orders" role="tab"><i class="fa-solid fa-receipt"></i> Orders</button>
        <button class="dr-tab"       data-tab="stock"  role="tab"><i class="fa-solid fa-boxes-stacked"></i> Stock <span class="dr-badge" id="stockBadge"><?= $lowItems ? (int)$lowItems : '' ?></span></button>
        <button class="dr-tab"       data-tab="staff"  role="tab"><i class="fa-solid fa-user-group"></i> Staff</button>
    </div>
    <div class="dr-panel" id="panel-today">
        <?php
          // Four headline stats in the reference's card format, each carrying its
          // own comparison on the third line. A figure with no baseline cannot
          // tell a manager whether the day went well, which is the whole reason
          // this page replaced report.php.
          // Before the first sale, every card would otherwise read red — "$14.65
          // less than a normal Tuesday" at 8am is an alarm about a day that has
          // not happened yet. The shop opening quietly is not bad news.
          if ($paidOrderCount === 0) {
              $zero  = ['tone' => 'flat', 'text' => $drZeroLine];
              $dGot = $dKept = $dOrd = $dCups = $dAvg = $zero;
          } else {
              $dGot   = dr_delta($gotToday,  $baseGot['value'],  $baseGot['label']);
              $dKept  = dr_delta($keptToday, $keptBaseline,      $baseGot['label']);
              $dOrd   = dr_delta((float)$paidOrderCount, $ordersBaseline, $baseGot['label'], false);
              $dCups  = dr_delta((float)$cupsToday,      $cupsBaseline,   $baseGot['label'], false);
              $dAvg   = dr_delta($avgToday,  $avgBaseline,       $baseGot['label']);
          }
          $tip    = $baselineTooltip !== '' ? ' title="' . htmlspecialchars($baselineTooltip) . '"' : '';
        ?>
        <div class="dr-stats">
          <div class="dr-stat is-lead">
            <div class="dr-k">total sales</div>
            <div class="dr-v-lg">$<?= number_format($gotToday, 2) ?></div>
            <div class="dr-delta tone-<?= $dGot['tone'] ?>"<?= $tip ?>><?= htmlspecialchars($dGot['text']) ?></div>
          </div>
          <div class="dr-stat">
            <div class="dr-k">money we keep</div>
            <div class="dr-v-lg">$<?= number_format($keptToday, 2) ?></div>
            <div class="dr-delta tone-<?= $dKept['tone'] ?>"><?= htmlspecialchars($dKept['text']) ?></div>
          </div>
          <div class="dr-stat">
            <div class="dr-k">total orders</div>
            <div class="dr-v-lg"><?= (int)$paidOrderCount ?></div>
            <div class="dr-delta tone-<?= $dOrd['tone'] ?>"><?= htmlspecialchars($dOrd['text']) ?></div>
          </div>
          <div class="dr-stat">
            <div class="dr-k">cups sold</div>
            <div class="dr-v-lg"><?= (int)$cupsToday ?></div>
            <div class="dr-delta tone-<?= $dCups['tone'] ?>"><?= htmlspecialchars($dCups['text']) ?></div>
          </div>
          <div class="dr-stat">
            <div class="dr-k">avg per order</div>
            <div class="dr-v-lg">$<?= number_format($avgToday, 2) ?></div>
            <div class="dr-delta tone-<?= $dAvg['tone'] ?>"><?= htmlspecialchars($dAvg['text']) ?></div>
          </div>
        </div>

        <?php if ($isToday && $lowItems): ?>
          <div class="dr-alert">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span><b><?= (int)$lowItems ?> item<?= $lowItems === 1 ? '' : 's' ?></b> below the buy-more level<?= $outItems ? ', ' . (int)$outItems . ' already out' : '' ?>.
            Buy <?= htmlspecialchars(implode(', ', $lowNames)) ?><?= $lowExtra ? " and $lowExtra more" : '' ?> before tomorrow.</span>
          </div>
        <?php elseif ($isToday): ?>
          <div class="dr-alert is-clear">
            <i class="fa-solid fa-circle-check"></i>
            <span>Every item is above its buy-more level. You can open tomorrow.</span>
          </div>
        <?php endif; ?>

        <div class="dr-facts">
          <div class="dr-card"><div class="dr-k">cash</div><div class="dr-v">$<?= number_format($gotCash, 2) ?></div><div class="dr-note">in the register</div></div>
          <div class="dr-card"><div class="dr-k">bakong</div><div class="dr-v">$<?= number_format($gotBakong, 2) ?></div><div class="dr-note">by phone</div></div>
          <div class="dr-card"><div class="dr-k">pay later — paid</div><div class="dr-v">$<?= number_format($gotLater, 2) ?></div><div class="dr-note">settled today</div></div>
          <div class="dr-card"><div class="dr-k">not paid yet</div><div class="dr-v">$<?= number_format($notPaidYet, 2) ?></div><div class="dr-note"><?= $notPaidCount === 0 ? 'everyone has paid' : ('from ' . $notPaidCount . ' order' . ($notPaidCount === 1 ? '' : 's')) ?></div></div>
          <?php if ($gotOther > 0.01): ?>
          <div class="dr-card"><div class="dr-k">other ways</div><div class="dr-v">$<?= number_format($gotOther, 2) ?></div><div class="dr-note">older orders that did not record how they were paid</div></div>
          <?php endif; ?>
        </div>

        <div class="dr-charts">
          <div class="dr-card">
            <div class="dr-k">when the money came in</div>
            <?php // The axis runs 06:00 to 05:00 because that is the business day
                  // (orders.business_date): trade before 06:00 belongs to the night
                  // before. Said out loud here so nobody reads midnight-after-18:00
                  // as a broken axis. ?>
            <div class="dr-note">the trading day, 06:00 to 05:00 next morning</div>
            <?php if ($hourPeakVal > 0): ?>
              <div class="dr-hours" role="img" aria-label="Money taken each hour of the trading day">
                <?php foreach ($hourOrder as $h):
                    $v = $hourRev[$h];
                    $pct = $hourPeakVal > 0 ? ($v / $hourPeakVal) * 100 : 0;
                    $isPeak = ($busiestHour !== null && $h === $busiestHour); ?>
                  <span class="dr-hour<?= $isPeak ? ' is-peak' : '' ?><?= $h === 0 ? ' is-daybreak' : '' ?>"
                        title="<?= sprintf('%02d:00', $h) ?> · $<?= number_format($v, 2) ?>">
                    <span class="dr-hour-fill" style="height:<?= $v > 0 ? max(2, round($pct, 1)) : 0 ?>%"></span>
                  </span>
                <?php endforeach; ?>
              </div>
              <div class="dr-hours-axis"><span>06:00</span><span>12:00</span><span>18:00</span><span>00:00</span><span>05:00</span></div>
              <?php if ($busiestHour !== null): ?>
                <p class="dr-note">busiest at <?= sprintf('%02d:00', $busiestHour) ?> · $<?= number_format($hourPeakVal, 2) ?></p>
              <?php endif; ?>
            <?php else: ?>
              <p class="dr-note"><?= htmlspecialchars($drZeroLine) ?></p>
            <?php endif; ?>
          </div>

          <div class="dr-card">
            <div class="dr-k">how the money came in</div>
            <?php if ($gotToday > 0):
                // Donut drawn with stroke-dasharray: each slice is a circle whose
                // dash covers its share, offset by everything before it. No chart
                // library — a CDN that fails on venue wifi is a blank chart.
                $C = 2 * M_PI * 54;
                $slices = array_values(array_filter([
                    ['cash', 'cash', $gotCash], ['bakong', 'bakong', $gotBakong],
                    ['later', 'pay later', $gotLater], ['other', 'other ways', $gotOther],
                ], fn($s) => $s[2] > 0.005));
                $acc = 0.0; ?>
              <div class="dr-donut-row">
                <svg class="dr-donut" viewBox="0 0 140 140" role="img" aria-label="Share of money by how it was paid">
                  <circle cx="70" cy="70" r="54" class="dr-donut-track"></circle>
                  <?php foreach ($slices as [$cls, $lbl, $amt]):
                      $frac = $amt / $gotToday;
                      $len  = $frac * $C; ?>
                    <circle cx="70" cy="70" r="54" class="dr-donut-seg seg-<?= $cls ?>"
                            stroke-dasharray="<?= round($len, 2) ?> <?= round($C - $len, 2) ?>"
                            stroke-dashoffset="<?= round(-$acc * $C, 2) ?>"></circle>
                  <?php $acc += $frac; endforeach; ?>
                  <text x="70" y="66" class="dr-donut-mid">$<?= number_format($gotToday, 2) ?></text>
                  <text x="70" y="82" class="dr-donut-sub">collected</text>
                </svg>
                <ul class="dr-legend">
                  <?php foreach ($slices as [$cls, $lbl, $amt]): ?>
                    <li><span class="dr-dot seg-<?= $cls ?>"></span><?= htmlspecialchars($lbl) ?>
                        <b>$<?= number_format($amt, 2) ?></b></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php else: ?>
              <p class="dr-note"><?= htmlspecialchars($drZeroLine) ?></p>
            <?php endif; ?>
            <?php if ($notPaidYet > 0): ?>
              <p class="dr-note">$<?= number_format($notPaidYet, 2) ?> not paid yet. We count it only when the customer pays.</p>
            <?php endif; ?>
          </div>
        </div>

        <div class="dr-card dr-wide">
          <div class="dr-k">best seller</div>
          <?php // Orders, cups and the per-order average are headline stats now —
                // repeating them here would be four more numbers to keep in step. ?>
          <?php if ($bestSellerName !== null): ?>
            <div class="dr-v-lg dr-v-text"><?= htmlspecialchars($bestSellerName) ?></div>
            <div class="dr-note"><?= (int)$bestSellerQty ?> sold<?= $avgCups !== null ? ' · ' . number_format($avgCups, 1) . ' cups per order' : '' ?></div>
          <?php else: ?>
            <p class="dr-note"><?= htmlspecialchars($drZeroLine) ?></p>
          <?php endif; ?>
        </div>
    </div>
    <div class="dr-panel" id="panel-orders" hidden></div>
    <div class="dr-panel" id="panel-stock"  hidden></div>
    <div class="dr-panel" id="panel-staff"  hidden></div>

    <footer class="dr-foot-bar">
      <span>The Bird's Nest Coffee · POS</span>
      <span id="drSync">read at <?= date('H:i') ?></span>
    </footer>

</div>

<script>
const drLoaded = {};           // tab -> true once its HTML has arrived
const DR_DATE  = <?= json_encode($date) ?>;

function drShowTab(tab) {
    const btn = document.querySelector('.dr-tab[data-tab="' + tab + '"]');
    if (!btn) return;
    document.querySelectorAll('.dr-tab').forEach(b => b.classList.toggle('is-on', b === btn));
    document.querySelectorAll('.dr-panel').forEach(p => p.hidden = (p.id !== 'panel-' + tab));
    if (tab !== 'today' && !drLoaded[tab]) { loadFragment(tab); }
    try { sessionStorage.setItem('drActiveTab:' + DR_DATE, tab); } catch (e) {}
}

document.querySelectorAll('.dr-tab').forEach(btn => {
    btn.addEventListener('click', () => drShowTab(btn.dataset.tab));
});

// A poll-triggered reload must not throw away where the manager was reading —
// restore the tab they had open, and their scroll position, right after load.
// Gated on a one-shot flag set only by the poll handler immediately before
// its reload: an ordinary visit (nav click, typed URL, "Full analytics" back
// button) must always land on Today, never on whatever tab was left open the
// last time this date happened to be viewed.
(function drRestoreAfterReload() {
    try {
        const pendingKey = 'drPendingRestore:' + DR_DATE;
        if (sessionStorage.getItem(pendingKey) !== '1') { return; }
        sessionStorage.removeItem(pendingKey);
        const savedTab = sessionStorage.getItem('drActiveTab:' + DR_DATE);
        if (savedTab && savedTab !== 'today') { drShowTab(savedTab); }
        const savedY = sessionStorage.getItem('drScrollY:' + DR_DATE);
        if (savedY !== null) {
            sessionStorage.removeItem('drScrollY:' + DR_DATE);
            window.scrollTo(0, parseInt(savedY, 10) || 0);
        }
    } catch (e) {}
})();

async function loadFragment(tab) {
    const panel = document.getElementById('panel-' + tab);
    panel.innerHTML = '<div class="dr-loading">Loading…</div>';
    try {
        const res  = await fetch('daily_report.php?fragment=' + tab + '&date=' + encodeURIComponent(DR_DATE));
        if (!res.ok) throw new Error(res.status);
        panel.innerHTML = await res.text();
        drLoaded[tab] = true;
        // Fragment HTML is set via innerHTML, so any <script> inside it is
        // inert. Wire up per-tab behaviour here instead, keyed by tab name.
        const initFn = window['drInit_' + tab];
        if (typeof initFn === 'function') initFn();
    } catch (e) {
        // Never leave a blank panel — say what happened and offer the retry.
        panel.innerHTML = '<div class="dr-error">Could not load this tab. '
                        + '<button onclick="loadFragment(\'' + tab + '\')">Try again</button></div>';
    }
}

/* The Excel export used to be built here, in the browser, by scraping the
 * rendered tables into a CSV. It has moved to daily_report_xlsx.php, which
 * queries the same shared helpers this page does. Scraping the DOM meant the
 * file could only ever contain what had already been rendered — tabs the
 * manager never opened had to be fetched first just to be read back out — and
 * CSV could carry no headings, no currency and no charts. */

/**
 * Shared pager for every table on this page — « ‹ 1 2 3 › », windowed two
 * either side of the current page with ellipses beyond. One renderer rather
 * than one per tab, so the two tables cannot drift apart.
 *   wrap  — the .pg-wrap element (hidden when there is only one page)
 *   info  — text for the left-hand side, e.g. "showing 1–10 of 49"
 *   onGo  — called with the requested page number
 */
function drRenderPager(wrap, page, totalPages, info, onGo) {
    if (!wrap) return;
    if (totalPages <= 1) { wrap.style.display = 'none'; wrap.innerHTML = ''; return; }
    wrap.style.display = 'flex';

    const btn = (label, target, title) =>
        `<a href="#" class="pg-btn" data-go="${target}" aria-label="${title}" title="${title}">${label}</a>`;
    const off = label => `<span class="pg-disabled">${label}</span>`;

    let nav = page > 1
        ? btn('&laquo;', 1, 'First page') + btn('&lsaquo;', page - 1, 'Previous page')
        : off('&laquo;') + off('&lsaquo;');

    const from = Math.max(1, page - 2);
    const to   = Math.min(totalPages, page + 2);
    if (from > 1) nav += '<span class="pg-ellipsis">…</span>';
    for (let i = from; i <= to; i++) {
        nav += i === page ? `<span class="pg-active">${i}</span>` : btn(i, i, 'Page ' + i);
    }
    if (to < totalPages) nav += '<span class="pg-ellipsis">…</span>';

    nav += page < totalPages
        ? btn('&rsaquo;', page + 1, 'Next page') + btn('&raquo;', totalPages, 'Last page')
        : off('&rsaquo;') + off('&raquo;');

    wrap.innerHTML = `<span class="pg-info">${info}</span><nav class="pg-nav">${nav}</nav>`;
    wrap.querySelectorAll('a[data-go]').forEach(a => {
        a.addEventListener('click', e => { e.preventDefault(); onGo(parseInt(a.dataset.go, 10)); });
    });
}

// ── Tab 2: Orders — filter pills + client-side pagination ──
// Run once per fragment load (loadFragment calls this after innerHTML is set,
// since <script> tags inside injected HTML never execute).
function drInit_orders() {
    const panel = document.getElementById('panel-orders');
    const table = panel && panel.querySelector('#ordersTable');
    if (!table) return;
    const pageSize = 10;
    const allRows  = Array.from(table.querySelectorAll('tbody tr[data-method]'));
    const pager    = panel.querySelector('#ordersPagination');
    const foot     = panel.querySelector('#ordersFoot');
    let filtered = allRows.slice();
    let page = 1;

    function render() {
        allRows.forEach(r => { r.style.display = 'none'; });
        const start = (page - 1) * pageSize;
        filtered.slice(start, start + pageSize).forEach(r => { r.style.display = ''; });
        renderPager();
        renderFoot();
    }

    // Describes the currently-filtered set (all matching rows, not just the
    // page on screen) — clicking a pill must change what the footer says, or
    // it silently contradicts the table exactly like the "$X total" bug did.
    function renderFoot() {
        if (!foot) return;
        let cups = 0, collected = 0, notPaid = 0, notPaidCount = 0;
        filtered.forEach(r => {
            cups += parseInt(r.dataset.cups || '0', 10);
            const total = parseFloat(r.dataset.total || '0');
            // 'refunded' rows read "money given back" in the Paid column, but
            // this total must still agree with tab 1's card and the PHP-
            // rendered footer below, both of which bucket Refunded under
            // NOT (paid_orders_where()) — i.e. everything that isn't 'ok'.
            if (r.dataset.state !== 'ok') { notPaid += total; notPaidCount++; }
            else { collected += total; }
        });
        let html = '<span>' + filtered.length + ' orders</span> · <span>' + cups + ' cups</span> · '
                 + '<span>$' + collected.toFixed(2) + ' collected</span>';
        if (notPaidCount > 0) {
            html += ' · <span>$' + notPaid.toFixed(2) + ' not paid yet</span>';
        }
        foot.innerHTML = html;
    }

    function renderPager() {
        const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
        const start = (page - 1) * pageSize;
        const end   = Math.min(start + pageSize, filtered.length);
        drRenderPager(pager, page, totalPages,
            'showing ' + (start + 1) + '–' + end + ' of ' + filtered.length,
            p => { page = p; render(); });
    }

    panel.querySelectorAll('.dr-pill').forEach(btn => {
        btn.addEventListener('click', () => {
            panel.querySelectorAll('.dr-pill').forEach(b => b.classList.toggle('is-on', b === btn));
            const f = btn.dataset.filter;
            filtered = (f === 'all') ? allRows.slice() : allRows.filter(r => r.dataset.method === f);
            page = 1;
            render();
        });
    });

    render();
}

// ── Tab 3: Stock — filter pills + live search over ingredient name ──
function drInit_stock() {
    const panel = document.getElementById('panel-stock');
    const table = panel && panel.querySelector('#stockTable');
    if (!table) return;
    const pageSize = 10;
    const allRows = Array.from(table.querySelectorAll('tbody tr[data-bucket]'));
    const search  = panel.querySelector('#stockSearch');
    const pager   = panel.querySelector('#stockPagination');
    const foot    = panel.querySelector('#stockFoot');
    let activeFilter = 'all';
    let filtered = allRows.slice();
    let page = 1;

    // Filter and search decide WHICH rows; the pager decides how many of them
    // are on screen. Any change to the first two resets to page 1 — leaving a
    // filtered set showing "page 4 of 1" is how paginated tables go blank.
    function apply(resetPage) {
        const q = (search && search.value || '').trim().toLowerCase();
        filtered = allRows.filter(r =>
            (activeFilter === 'all' || r.dataset.bucket === activeFilter) &&
            (!q || r.dataset.name.includes(q))
        );
        if (resetPage !== false) page = 1;
        const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
        if (page > totalPages) page = totalPages;
        render();
    }

    function render() {
        allRows.forEach(r => { r.style.display = 'none'; });
        const start = (page - 1) * pageSize;
        filtered.slice(start, start + pageSize).forEach(r => { r.style.display = ''; });
        renderFoot();
        renderPager();
    }

    // Only speaks when the pager is silent. Once there is more than one page the
    // pager's own info line carries the count, and two of them would be two
    // numbers to keep in agreement.
    function renderFoot() {
        if (!foot) return;
        if (filtered.length === 0)      foot.innerHTML = '<span>nothing matches</span>';
        else if (filtered.length <= pageSize) foot.innerHTML = '<span>' + filtered.length + ' item' + (filtered.length === 1 ? '' : 's') + '</span>';
        else                            foot.innerHTML = '';
    }

    function renderPager() {
        const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
        const start = (page - 1) * pageSize;
        const end   = Math.min(start + pageSize, filtered.length);
        drRenderPager(pager, page, totalPages,
            'showing ' + (start + 1) + '–' + end + ' of ' + filtered.length,
            p => { page = p; render(); });
    }

    panel.querySelectorAll('.dr-pill').forEach(btn => {
        btn.addEventListener('click', () => {
            panel.querySelectorAll('.dr-pill').forEach(b => b.classList.toggle('is-on', b === btn));
            activeFilter = btn.dataset.filter;
            apply();
        });
    });

    if (search) search.addEventListener('input', () => apply());

    apply();
}

// ── Live refresh (Task 9) — poll a cheap signature, today only. A past date
// is settled history and must never repeat a network request for it.
const DR_IS_TODAY = <?= $isToday ? 'true' : 'false' ?>;
let drSig = null;
if (DR_IS_TODAY) {
    setInterval(async () => {
        try {
            const res = await fetch('daily_report.php?poll=1&date=' + encodeURIComponent(DR_DATE));
            const { sig } = await res.json();
            if (drSig !== null && sig !== drSig) {
                // Reloading mid-read is hostile to a manager part-way down a
                // table — stash where they were so drRestoreAfterReload can
                // put them back once the new page settles. The pending-restore
                // flag is what tells that IIFE this reload was poll-triggered,
                // as opposed to an ordinary nav click that should land on Today.
                try {
                    sessionStorage.setItem('drScrollY:' + DR_DATE, String(window.scrollY));
                    sessionStorage.setItem('drPendingRestore:' + DR_DATE, '1');
                } catch (e) {}
                window.location.reload();
            }
            drSig = sig;
        } catch (e) { /* a dropped poll is not worth interrupting the manager */ }
    }, 30000);
}

// follows shared theme key (toggled elsewhere)
window.addEventListener('storage', function (e) {
    if (e.key === 'theme') {
        if (e.newValue === 'light') document.documentElement.setAttribute('data-theme', 'light');
        else document.documentElement.removeAttribute('data-theme');
    }
});
</script>
</body>
</html>
