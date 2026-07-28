<?php
/**
 * Printable daily report — the same day daily_report.php shows on screen,
 * laid out for paper and for handing to someone.
 *
 * Every figure comes from the shared helpers the screen uses, so the paper and
 * the page can never disagree: paid_orders_where() for what counts as
 * collected, order_cogs() for what the drinks cost, weekday_baseline() for
 * whether the day beat a normal one.
 */
require 'auth.php';
require 'config.php';
if (!can('report')) { header("Location: dashboard.php?denied=1"); exit; }
require 'dompdf/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

date_default_timezone_set('Asia/Phnom_Penh');

function he($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n) { return '$' . number_format((float)$n, 2); }

// Same validation as the screen: regex, real calendar date, never the future.
$today = business_date_today();
$date  = is_string($_GET['date'] ?? null) ? trim($_GET['date']) : '';
if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
    $date = $today;
}
if ($date > $today) { $date = $today; }

// ── The day's money ──
$stmt = $conn->prepare("SELECT COALESCE(SUM(total),0), COUNT(*) FROM orders WHERE business_date = ? AND " . paid_orders_where());
$stmt->bind_param("s", $date);
$stmt->execute();
[$gotToday, $orderCount] = $stmt->get_result()->fetch_row();
$gotToday   = (float)$gotToday;
$orderCount = (int)$orderCount;

$ids = [];
$stmt = $conn->prepare("SELECT order_id FROM orders WHERE business_date = ? AND " . paid_orders_where());
$stmt->bind_param("s", $date);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_row()) { $ids[] = (int)$row[0]; }

$costMap  = ingredient_cost_map($conn);
$cogs     = order_cogs($conn, $ids, $costMap);
$itemsSold = cogs_cups($cogs);   // cups poured, loyalty redemptions excluded
$kept      = $gotToday - $cogs['total'];
$avgOrder  = $orderCount > 0 ? $gotToday / $orderCount : 0.0;

// ── Hour by hour. The trading day runs 06:00 to 05:00, so the table runs that
//    way too rather than starting at midnight with a dead morning. ──
$hourRev = array_fill(0, 24, 0.0);
$hourOrd = array_fill(0, 24, 0);
$hourIds = array_fill(0, 24, []);
$stmt = $conn->prepare("
    SELECT HOUR(order_date) h, order_id, total
    FROM orders WHERE business_date = ? AND " . paid_orders_where()
);
$stmt->bind_param("s", $date);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $h = (int)$r['h'];
    $hourRev[$h] += (float)$r['total'];
    $hourOrd[$h]++;
    $hourIds[$h][] = (int)$r['order_id'];
}
$hourRows = [];
for ($i = 6; $i < 30; $i++) {
    $h = $i % 24;
    if ($hourOrd[$h] === 0) { continue; }
    $hourRows[] = [
        'label' => sprintf('%02d:00 to %02d:59', $h, $h),
        'rev'   => $hourRev[$h],
        'ord'   => $hourOrd[$h],
        'items' => cogs_cups(order_cogs($conn, $hourIds[$h], $costMap)),
    ];
}

// ── Top sellers ──
// Loyalty redemptions ride in order_items under the product_id=0 sentinel. A
// shirt handed over for points is not a drink sold, so it has no place in a
// table headed "top selling drinks". Free promo drinks keep a real product id
// and stay — they were made and poured like any other.
//
// The test is the is_gift flag order_cogs() sets from product_id, not the
// "[GIFT] " name prefix this used to match: six rows predate that prefix and
// leaked through as top sellers.
$byProduct = array_filter($cogs['by_product'], fn($p) => empty($p['is_gift']));
$giftCount = (int)$cogs['gift_items'];
uasort($byProduct, fn($a, $b) => $b['qty'] <=> $a['qty']);
$topProducts = array_slice($byProduct, 0, 10, true);

// ── How it was paid. Anything not cash/bakong/paylater is a legacy row, so the
//    remainder is derived rather than enumerated — the cards must always sum. ──
$stmt = $conn->prepare("
    SELECT payment_method, COALESCE(SUM(total),0) amount, COUNT(*) txns
    FROM orders WHERE business_date = ? AND " . paid_orders_where() . " GROUP BY payment_method
");
$stmt->bind_param("s", $date);
$stmt->execute();
$byMethod = ['cash' => [0,0], 'bakong' => [0,0], 'paylater' => [0,0], 'other' => [0,0]];
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $k = strtolower((string)$r['payment_method']);
    if (!isset($byMethod[$k])) { $k = 'other'; }
    $byMethod[$k][0] += (float)$r['amount'];
    $byMethod[$k][1] += (int)$r['txns'];
}
$methodNames = ['cash' => 'Cash', 'bakong' => 'Bakong (KHQR)', 'paylater' => 'Pay later (settled)', 'other' => 'Other / not recorded'];

// ── Still owed ──
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(total),0), COUNT(*) FROM orders
    WHERE business_date = ? AND status NOT IN ('Cancelled','Void') AND NOT (" . paid_orders_where() . ")
");
$stmt->bind_param("s", $date);
$stmt->execute();
[$notPaid, $notPaidCount] = $stmt->get_result()->fetch_row();
$notPaid = (float)$notPaid;

// ── Against a normal weekday ──
$base = weekday_baseline($conn, $date);
$verdict = 'No comparison available yet.';
if ($orderCount === 0) {
    $verdict = 'No sales recorded for this day.';
} elseif ($base['value'] !== null) {
    $diff = $gotToday - $base['value'];
    $verdict = abs($diff) < 0.005
        ? 'The same as ' . $base['label'] . '.'
        : money(abs($diff)) . ($diff > 0 ? ' more' : ' less') . ' than ' . $base['label']
          . ' (' . money($base['value']) . ' on average over ' . $base['days'] . ' day' . ($base['days'] === 1 ? '' : 's') . ').';
}

$generated = date('j/n/Y, g:i:s A');
$periodLbl = date('l, j F Y', strtotime($date));

ob_start(); ?>
<style>
  @page { margin: 26px 30px 46px; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #1a1a1a; margin: 0; }
  .band { background: #16130f; color: #fff; text-align: center; padding: 18px 10px 16px; margin: -26px -30px 18px; }
  .band h1 { margin: 0; font-size: 17px; letter-spacing: 1px; }
  .band .shop { margin: 5px 0 0; font-size: 11px; color: #d1904b; }
  .band .meta { margin: 4px 0 0; font-size: 8.5px; color: #b9b4ae; }

  table { width: 100%; border-collapse: collapse; }
  .kpis td { width: 25%; border: 1px solid #ddd; padding: 10px 12px; text-align: center; }
  .kpis .k { font-size: 7.5px; letter-spacing: 1px; color: #6b6259; text-transform: uppercase; }
  .kpis .v { font-size: 15px; font-weight: bold; padding-top: 4px; }

  h2 { font-size: 10px; letter-spacing: .8px; text-transform: uppercase; margin: 18px 0 7px; }
  .data th { background: #16130f; color: #fff; font-size: 8px; letter-spacing: .6px; text-transform: uppercase;
             padding: 7px 8px; text-align: left; }
  .data td { padding: 6px 8px; border-bottom: 1px solid #eee; }
  .data tr.alt td { background: #faf9f7; }
  .data .num { text-align: right; }
  .data tr.total td { background: #d1904b; color: #1a1207; font-weight: bold; border: none; }

  .note { font-size: 8.5px; color: #6b6259; margin-top: 6px; line-height: 1.5; }
  .verdict { border-left: 3px solid #d1904b; background: #faf7f2; padding: 9px 12px; margin: 14px 0 0; font-size: 9.5px; }
</style>

<div class="band">
  <h1>DAILY SALES REPORT</h1>
  <p class="shop">The Bird's Nest Coffee</p>
  <?php // Plain ASCII only in the PDF: DOMPDF renders &nbsp; and &middot; as
        // replacement boxes with this font, so separators are written out. ?>
  <p class="meta">Trading day: <?= he($periodLbl) ?> (06:00 to 05:00 next morning)</p>
  <p class="meta">Generated: <?= he($generated) ?></p>
</div>

<table class="kpis">
  <tr>
    <td><div class="k">Total Sales</div><div class="v"><?= money($gotToday) ?></div></td>
    <td><div class="k">Total Orders</div><div class="v"><?= $orderCount ?></div></td>
    <td><div class="k">Cups Sold</div><div class="v"><?= $itemsSold ?></div></td>
    <td><div class="k">Avg Order</div><div class="v"><?= money($avgOrder) ?></div></td>
  </tr>
</table>

<div class="verdict">
  <b>Against a normal day:</b> <?= he($verdict) ?><br>
  <b>Money kept after ingredients:</b> <?= money($kept) ?>
  <?= $gotToday > 0 ? '(' . round(($kept / $gotToday) * 100) . ' cents of each $1)' : '' ?>
  <?php if ($notPaid > 0): ?><br><b>Not paid yet:</b> <?= money($notPaid) ?> across <?= (int)$notPaidCount ?> order<?= $notPaidCount == 1 ? '' : 's' ?> - counted only once the customer pays.<?php endif; ?>
</div>

<h2>Sales by hour</h2>
<table class="data">
  <tr><th>Hour</th><th class="num">Revenue</th><th class="num">Orders</th><th class="num">Cups</th><th class="num">Avg order</th></tr>
  <?php if (!$hourRows): ?>
    <tr><td colspan="5" style="text-align:center;color:#6b6259;padding:14px">No sales recorded for this day.</td></tr>
  <?php else: $i = 0; foreach ($hourRows as $r): $i++; ?>
    <tr class="<?= $i % 2 === 0 ? 'alt' : '' ?>">
      <td><?= he($r['label']) ?></td>
      <td class="num"><?= money($r['rev']) ?></td>
      <td class="num"><?= $r['ord'] ?></td>
      <td class="num"><?= $r['items'] ?></td>
      <td class="num"><?= money($r['ord'] > 0 ? $r['rev'] / $r['ord'] : 0) ?></td>
    </tr>
  <?php endforeach; ?>
    <tr class="total">
      <td>TOTAL</td><td class="num"><?= money($gotToday) ?></td>
      <td class="num"><?= $orderCount ?></td><td class="num"><?= $itemsSold ?></td>
      <td class="num"><?= money($avgOrder) ?></td>
    </tr>
  <?php endif; ?>
</table>

<h2>Top selling drinks</h2>
<table class="data">
  <tr><th style="width:28px">#</th><th>Drink</th><th class="num">Cups</th><th class="num">Revenue</th><th class="num">Avg price</th></tr>
  <?php if (!$topProducts): ?>
    <tr><td colspan="5" style="text-align:center;color:#6b6259;padding:14px">Nothing sold on this day.</td></tr>
  <?php else: $i = 0; foreach ($topProducts as $name => $p): $i++; ?>
    <tr class="<?= $i % 2 === 0 ? 'alt' : '' ?>">
      <td><?= $i ?></td>
      <td><?= he($name) ?></td>
      <td class="num"><?= (int)$p['qty'] ?></td>
      <td class="num"><?= money($p['revenue']) ?></td>
      <td class="num"><?= money($p['qty'] > 0 ? $p['revenue'] / $p['qty'] : 0) ?></td>
    </tr>
  <?php endforeach; endif; ?>
</table>
<?php if ($giftCount > 0): ?>
  <p class="note">Excludes <?= (int)$giftCount ?> item<?= $giftCount === 1 ? '' : 's' ?> given away against loyalty points, which earn no revenue.</p>
<?php endif; ?>

<h2>How it was paid</h2>
<table class="data">
  <tr><th>Payment method</th><th class="num">Orders</th><th class="num">Amount</th></tr>
  <?php $i = 0; foreach ($byMethod as $k => [$amt, $txns]):
      if ($amt <= 0 && $txns === 0) { continue; } $i++; ?>
    <tr class="<?= $i % 2 === 0 ? 'alt' : '' ?>">
      <td><?= he($methodNames[$k]) ?></td>
      <td class="num"><?= (int)$txns ?></td>
      <td class="num"><?= money($amt) ?></td>
    </tr>
  <?php endforeach; ?>
  <tr class="total"><td>TOTAL</td><td class="num"><?= $orderCount ?></td><td class="num"><?= money($gotToday) ?></td></tr>
</table>

<p class="note">
  Revenue counts money actually collected - an order is included once it is paid, so an unsettled
  pay-later tab appears under “not paid yet” rather than in sales. Ingredient costs behind
  “money kept” are documented estimates at market wholesale rates, not supplier invoices.
</p>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('isRemoteEnabled', false);   // nothing on this page loads off the network
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Page numbers, drawn after render so the total is known.
$canvas = $dompdf->getCanvas();
$canvas->page_text(40, $canvas->get_height() - 28, "The Bird's Nest Coffee", null, 8, [0.42, 0.38, 0.35]);
$canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 28, 'Page {PAGE_NUM} of {PAGE_COUNT}', null, 8, [0.42, 0.38, 0.35]);

// Opened inline, not downloaded: this is what the Print button loads, and a
// manager who wanted paper needs to see the pages before sending them. The
// browser's PDF viewer carries both a print and a save control, so nothing is
// lost by not forcing the file to disk.
$dompdf->stream('daily-report-' . $date . '.pdf', ['Attachment' => false]);
