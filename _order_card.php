<?php
/**
 * Renders one order card. Expects $order (associative row from the orders query) in scope.
 * Included by find_order.php (initial render) and the action=list AJAX endpoint.
 */
$isPaidOpen = ($order['status'] === 'Paid' && $order['is_open'] == 1);
$isPayLater = ($order['payment_method'] === 'paylater');
$canAdd     = ($order['is_open'] == 1 && (in_array($order['status'], ['Preparing', 'Paid']) || ($isPayLater && $order['status'] === 'Completed')));
$cardClass  = $isPaidOpen ? 'is-paid-open' : ($canAdd ? 'can-add' : '');
$statusClass = strtolower($order['status']);
$tz   = new DateTimeZone('Asia/Phnom_Penh');
$now  = new DateTime('now', $tz);
$then = new DateTime($order['order_date'], $tz);
$diff = $now->getTimestamp() - $then->getTimestamp();
if ($diff < 0) {
    $absDiff = abs($diff);
    if ($absDiff < 3600)       $timeAgo = 'in ' . floor($absDiff/60) . 'm';
    elseif ($absDiff < 86400)  $timeAgo = 'in ' . floor($absDiff/3600) . 'h';
    else                       $timeAgo = 'in ' . floor($absDiff/86400) . 'd';
} elseif ($diff < 60)          $timeAgo = $diff . 's ago';
elseif ($diff < 3600)          $timeAgo = floor($diff/60) . 'm ago';
elseif ($diff < 86400)         $timeAgo = floor($diff/3600) . 'h ' . floor(($diff%3600)/60) . 'm ago';
else                           $timeAgo = floor($diff/86400) . 'd ago';

$isOverdue = ($order['payment_method'] === 'paylater' && $diff > PAYLATER_FOLLOWUP_MINUTES * 60); // unpaid past follow-up window (Settings)

// Human-readable overdue span: minutes under an hour, then hours, then days.
$overdueMins = floor($diff / 60);
if ($overdueMins < 60) {
    $overdueLabel = $overdueMins . '+ min';
} elseif ($overdueMins < 1440) {
    $h = floor($overdueMins / 60);
    $overdueLabel = $h . '+ ' . ($h === 1 ? 'hour' : 'hours');
} else {
    $d = floor($overdueMins / 1440);
    $overdueLabel = $d . '+ ' . ($d === 1 ? 'day' : 'days');
}
?>
<div class="order-card <?= $cardClass ?> <?= $isOverdue ? 'overdue' : '' ?>"
     data-name="<?= strtolower(htmlspecialchars($order['customer_name'])) ?>"
     data-token="<?= $order['token_number'] ?>"
     data-amount="<?= $order['total'] ?>"
     data-order="<?= $order['daily_order_no'] ?>">

    <div class="card-top">
        <!-- Left: main info -->
        <div class="card-main-info">

            <div class="info-group">
                <span class="info-label">Order</span>
                <span class="info-value">#<?= (int)$order['daily_order_no'] ?></span>
            </div>

            <div class="info-group">
                <span class="info-label">Customer</span>
                <span class="info-value small"><?= htmlspecialchars($order['customer_name']) ?></span>
            </div>

            <div class="info-group">
                <span class="info-label">Total</span>
                <span class="info-value total">$<?= number_format($order['total'], 2) ?></span>
            </div>

            <div class="info-group">
                <span class="info-label">Status</span>
                <span>
                    <span class="status-badge <?= $statusClass ?>">
                        <?php
                        $icons = ['Preparing'=>'fa-fire','PendingPayment'=>'fa-clock','Paid'=>'fa-check-circle','Refunded'=>'fa-rotate-left'];
                        $icon = $icons[$order['status']] ?? 'fa-circle';
                        $labels = ['PendingPayment'=>'Pending Payment','Preparing'=>'Preparing','Paid'=>'Paid','Refunded'=>'Refunded'];
                        $label = $labels[$order['status']] ?? $order['status'];
                        ?>
                        <i class="fa-solid <?= $icon ?>"></i>
                        <?= htmlspecialchars($label) ?>
                    </span>
                    <?php if ($canAdd): ?>
                    <span class="open-badge"><i class="fa-solid fa-circle-plus"></i> Can Add Items</span>
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <!-- Right: actions -->
        <div class="actions">
            <?php if ($canAdd): ?>
            <a href="add_to_existing_order.php?order_id=<?= $order['order_id'] ?>" class="btn btn-add">
                <i class="fa-solid fa-plus"></i> Add Items
            </a>
            <?php endif; ?>
            <?php $isPL = ($order['payment_method'] === 'paylater' && $order['status'] === 'Preparing'); ?>
            <?php if ($isPL): ?>
            <a href="edit_order_items.php?order_id=<?= $order['order_id'] ?>" class="btn btn-edit" title="Edit items on this order">
                <i class="fa-solid fa-pen-to-square"></i> Edit
            </a>
            <?php endif; ?>
            <?php /* Carry the tab the cashier is on so settling returns them to it.
                      Defaulted, because this partial may be included by a page that
                      does not define $card_tab — that gets today's behaviour rather
                      than a notice. data-lp-dest must match href exactly or the
                      loyalty intercept navigates somewhere the link did not point. */
                   $ret = '&return=' . urlencode($card_tab ?? 'pending'); ?>
            <a href="admin_pay_cash.php?order_id=<?= $order['order_id'] ?><?= $ret ?>"
               class="btn btn-pay-cash"
               <?= $isPL ? 'data-lp-order="'.$order['order_id'].'" data-lp-dest="admin_pay_cash.php?order_id='.$order['order_id'].$ret.'" onclick="return interceptPayLater(event,this)"' : '' ?>>
                <i class="fa-solid fa-money-bill-wave"></i> Cash
            </a>
            <a href="admin_pay_bakong.php?order_id=<?= $order['order_id'] ?><?= $ret ?>"
               class="btn btn-pay-bakong"
               <?= $isPL ? 'data-lp-order="'.$order['order_id'].'" data-lp-dest="admin_pay_bakong.php?order_id='.$order['order_id'].$ret.'" onclick="return interceptPayLater(event,this)"' : '' ?>>
                <i class="fa-solid fa-qrcode"></i> Bakong
            </a>
            <?php /* receipt_paylater.php stamps PAY LATER across the page and closes
                     with "Payment pending" — both hardcoded, neither conditional. Sending
                     every order there printed a cash or Bakong sale as an unpaid tab.
                     receipt_pdf.php is the method-aware one: it reads order_payments and
                     names what was actually tendered. */ ?>
            <a href="<?= $isPayLater ? 'receipt_paylater.php' : 'receipt_pdf.php' ?>?order_id=<?= $order['order_id'] ?>"
               target="_blank" class="btn btn-receipt" title="Open receipt">
                <i class="fa-solid fa-file-pdf"></i>
            </a>
            <?php if (!$is_cashier): ?>
            <button class="btn btn-cancel-order" onclick="cancelOrderFromFind(<?= $order['order_id'] ?>, this)" title="Cancel this order">
                <i class="fa-solid fa-ban"></i>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="card-bottom">
        <div class="card-meta">
            <span class="<?= $isOverdue ? 'age-overdue' : '' ?>"><i class="fa-solid fa-clock"></i> <?= $timeAgo ?> &nbsp;·&nbsp; <?= date("d M, g:i A", strtotime($order['order_date'])) ?></span>
            <?php
            /* payment_method on an unpaid order is the method the customer PICKED at
               checkout, not one they have paid by — nobody has paid. Printing it bare
               put "Bakong" on a card whose own buttons offer Cash or Bakong, which
               reads as a contradiction and, worse, as a settled fact. The customer is
               still free to hand over cash, and until they do this is an intention.

               Collected is the same test the rest of the app uses (paid_orders_where):
               closed, and not in a status that means unpaid or reversed. */
            $collected = ((int)$order['is_open'] === 0)
                && !in_array($order['status'], ['PendingPayment', 'Cancelled', 'Refunded', 'Void'], true);

            // ucfirst('paylater') gives "Paylater", and a settled tab would have
            // read "Paid by Paylater". Pay later is also not a method the
            // customer picked at the till — it is a tab they opened — so it
            // gets its own wording in both states.
            $methodLabels = ['cash' => 'Cash', 'bakong' => 'Bakong', 'paylater' => 'Pay later'];
            $methodName   = $methodLabels[$order['payment_method']] ?? ucfirst($order['payment_method']);

            // Refunded is tested before the collected check, the same order
            // dr_pay_label() uses on the daily report: money that WAS collected
            // and then handed back is not money that was never paid. Without
            // this a refund read "Cash selected — not paid yet", which is the
            // opposite of what happened.
            if ($order['status'] === 'Refunded') {
                $methodText = 'Refunded — ' . $methodName . ' given back';
            } elseif ($isPayLater) {
                $methodText = $collected ? 'Pay later — settled' : 'Pay later — not paid yet';
            } else {
                $methodText = $collected
                    ? 'Paid by ' . $methodName
                    : $methodName . ' selected — not paid yet';
            }
            ?>
            <span<?= $collected ? '' : ' style="color:var(--text-muted);"' ?>>
                <i class="fa-solid <?= $collected ? 'fa-credit-card' : 'fa-hourglass-half' ?>"></i>
                <?= htmlspecialchars($methodText) ?>
            </span>
            <span class="table-edit-wrap" data-order="<?= $order['order_id'] ?>">
                <i class="fa-solid fa-ticket" style="color:var(--accent);"></i>
                <span class="table-label" style="color:var(--accent);"><?= !empty($order['table_number']) ? 'Stand ' . htmlspecialchars($order['table_number']) : 'No stand' ?></span>
            </span>
            <?php if ($order['is_open'] == 1): ?>
            <span style="color:var(--accent);"><i class="fa-solid fa-door-open"></i> Order is open</span>
            <?php else: ?>
            <span style="color:var(--text-muted);"><i class="fa-solid fa-door-closed"></i> Order closed</span>
            <?php endif; ?>
        </div>
        <?php if ($isOverdue): ?>
        <div class="overdue-warning">
            <i class="fa-solid fa-triangle-exclamation"></i> Unpaid for <?= $overdueLabel ?> — follow up with customer
        </div>
        <?php endif; ?>
    </div>
</div>
