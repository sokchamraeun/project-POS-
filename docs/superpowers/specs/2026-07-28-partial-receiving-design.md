# Partial receiving for purchase orders

**Date:** 2026-07-28
**Files:** `purchase_order_view.php`, `purchase_orders.php`, `config.php` (migrations)
**Status:** design approved, not yet implemented

## The problem

A supplier delivers 6 of the 10 cartons of milk on the purchase order. The cafe
needs the coffee beans that came with them, and the missing milk may take days.

`purchase_order_items` records only `qty_ordered`. There is no column for what
actually turned up, so `mark_received` loops the ordered quantity and adds that
to stock:

```php
$qty = (float)$item['qty_ordered'];
$upd->bind_param('di', $qty, $iid);   // stock_quantity + qty_ordered
```

The clerk therefore has two buttons and neither is right:

- **Mark Received** adds 10. The cafe now owns four cartons of milk that do not
  exist.
- **Cancel PO** sends the coffee beans back too, and leaves a `total_cost`
  recording a delivery that never happened.

Phantom stock is the worse of the two because it is silent. Recipes deduct
against milk that is not there, the low-stock alert never fires because on paper
the shop is fine, and the discrepancy surfaces weeks later in the Inventory Count
page as a shortage nobody can trace back to a delivery.

**This is not the same failure an external review described.** That review
assumed the system cancels a PO when items are missing. It does not — it has no
concept of "missing" at all. The remedy is the same (partial receiving); the
diagnosis is not.

## What gets built

The clerk records the quantity that physically arrived, per line. Stock receives
that number. The PO stays open while anything is outstanding.

### Data model

One column, one enum value, no rewrites.

```
purchase_order_items
  + qty_received       DECIMAL(10,3) NOT NULL DEFAULT 0

purchase_orders
    status  enum('Draft','Ordered','Partially Received','Received','Cancelled')
  + closed_short       TINYINT(1) NOT NULL DEFAULT 0
  + closed_short_at    DATETIME NULL
  + closed_short_by    VARCHAR(100) NULL
```

Applied through the existing `_migrate($conn, 'po_partial_receive_v1', ...)`
helper in `config.php`, so they self-apply on the next page load like every other
schema change in this codebase.

The same migration backfills `qty_received = qty_ordered` on every existing
`Received` PO. Without it, twelve historical orders would read as shortfalls.

**`total_cost` is never written.** It records the order that was placed, and
rewriting it would falsify a document already issued to a supplier. The received
value is derived where needed as `SUM(qty_received * unit_cost)`. Keeping the
stored column stable also protects the pages that already reconcile against it.

This is what lets a short delivery stay distinguishable from a small order,
permanently.

### The receive transaction

Stock moves by the delta, never by the total:

```
Fresh Milk   ordered 10   already received 6   receiving now 4
  -> stock_quantity + 4
  -> ingredient_history: change_type 'po_received', amount 4
  -> qty_received becomes 10
```

**The double-receive guard must be rebuilt.** Today it claims the PO by flipping
`status='Ordered'` and checking `affected_rows`:

```php
$s1 = $conn->prepare("UPDATE purchase_orders SET status='Received', received_at=NOW()
                      WHERE po_id=? AND status='Ordered'");
if ($s1->affected_rows === 0) { $conn->rollback(); ... }
```

That works only because receiving happens exactly once. Partial receiving means
receiving the same PO repeatedly and legitimately, so this guard stops guarding.
Leaving it in place would reintroduce the PO double-stock bug it was written to
fix.

Replace it with an optimistic claim per line. Each row carries the
`qty_received` the form was rendered against, in a hidden field:

```sql
UPDATE purchase_order_items
   SET qty_received = qty_received + ?
 WHERE poi_id = ? AND qty_received = ?     -- the value the form saw
```

`affected_rows === 0` means that line moved since the page loaded. Roll the whole
transaction back and ask the clerk to reload. This catches a double-click, a
back-button re-POST, and two clerks working two tills.

The receive form renders for exactly two statuses — `Ordered` and `Partially
Received`. `Draft` has not been placed yet; `Received` and `Cancelled` are
finished. The POST handler repeats that check rather than trusting the form.

Status is recomputed from the lines after the loop, never assigned directly:

| Condition | Status |
|---|---|
| Every line `qty_received >= qty_ordered` | `Received` |
| Some received, some outstanding | `Partially Received` |
| Every box submitted as zero | rejected, PO untouched |

All inside the existing `begin_transaction` / `rollback`, so a failure part-way
leaves neither stock nor the PO half-changed.

### Over-delivery

Twelve cartons against ten ordered are accepted and recorded as twelve. Stock is
a physical fact; the cartons are in the fridge whether or not the paperwork
expected them. The line is flagged as over-delivered so a manager can query the
invoice. Capping at the ordered quantity would push the surplus into Inventory
Count as an unexplained overage.

### Screens

**`purchase_order_view.php`** — the items table gains `Received` and
`Receiving now` columns:

```
Ingredient      Ordered    Received    Receiving now
Coffee Beans    10 bag     10 bag      -  complete
Fresh Milk      10 ctn      6 ctn      [  4 ]    4 outstanding
Sugar            5 kg       0 kg       [  5 ]
                                        [ Confirm delivery ]
```

Inputs prefill with the outstanding quantity, so a full delivery — the normal
case — stays one glance and one click, and typing is reserved for the exception.
Completed lines render no input at all rather than a disabled one. Negative
values are rejected on the server, not only in the browser.

The header carries both figures, neither pretending to be the other:

```
PO-0007   Partially Received
Ordered $20.00  ·  Received $12.00  ·  $8.00 outstanding
```

**`purchase_orders.php`** — the one-click `Mark Received` becomes a link that
opens the PO, so the stock arithmetic keeps a single caller. `Partially Received`
joins the status tabs and the colour map in amber: it needs attention, it is not
a failure. The `Total Received` stat switches to summing received value, so it
means money actually spent.

### Close short

Manager and admin only, shown only on a `Partially Received` PO. It sets status
`Received`, `closed_short = 1`, and stamps who and when.

**It adds no stock.** It is an admission that the remainder is never arriving,
not a delivery. The PO goes on displaying ordered $20 against received $12 so the
write-off stays legible afterwards.

The permission split mirrors the stock-count flow already in the app: the clerk
counts what is there, a manager commits the commercial consequence.

## Failure modes

| Case | Behaviour |
|---|---|
| Double-click / back-button re-POST | Per-line claim fails, rollback, "this PO changed since you loaded it" |
| All boxes zero | Rejected, PO untouched |
| Negative quantity | Rejected server-side |
| Non-numeric or missing field | Treated as zero for that line, never as the ordered amount |
| Receive on a `Received` / `Cancelled` PO | Form not rendered, and the POST handler re-checks status |
| Clerk POSTs `close_short` directly | Rejected on role, not merely hidden in the UI |
| Ingredient deleted between order and delivery | Line skipped with a warning (`purchase_order_items` is RESTRICT on `ingredients`, so this should not arise) |
| Missing CSRF token | Existing `badtoken` path, unchanged |

## Verification

1. `php -l` on every touched file. Migration dry-run confirming `qty_received`
   backfills all twelve historical `Received` POs to `qty_ordered`.
2. CLI harness against a scratch PO asserting the arithmetic:
   - order 10, receive 6 → stock `+6` exactly
   - receive 4 more → stock `+4`, status becomes `Received`
   - **submit the same form twice → stock moves once**

   The third assertion is the regression test for the bug this design is most
   likely to reintroduce.
3. Browser pass as inventory clerk (receive short, then top up) and as manager
   (close short). `ingredient_history` must show the real deltas, not the ordered
   amounts.
4. `total_cost` byte-identical before and after on every PO touched. `Total
   Received` on the list equals the sum of received values.

## Deliberately not built

**Auto-generated backorder POs.** An external review proposed splitting a short
delivery into a second PO for the missing quantity. Rejected: `total_cost` is
stored per PO and reconciles to its line items, so splitting one order forces a
decision about what each half is worth, and it litters a list of eighteen rows
with orphans. Keeping the original PO open carries the same information —
outstanding quantity, per line, visible — with none of that.

**Reassign a shortfall to a different supplier.** `ingredients.supplier_id` holds
one preferred supplier and there is no table describing which suppliers *can*
supply a given ingredient, so the dropdown would list every supplier ever entered
with nothing to guide the choice. Most expensive item on the review's list, least
likely to be exercised.

Both are recorded here so the decision is not relitigated later.

## Related

`2026-07-11-stock-count-reconciliation-design.md` — the clerk-counts /
manager-applies split this permission model follows.
