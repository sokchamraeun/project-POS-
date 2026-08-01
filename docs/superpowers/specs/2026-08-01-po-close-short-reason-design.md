# Purchase order write-offs: recording why

**Date:** 2026-08-01
**Branch:** `feat/product-addons`
**Follows:** `2026-07-28-partial-receiving-design.md`

## Problem

Partial receiving shipped on 2026-08-01. A manager can now **Close short** a
part-delivered purchase order: the outstanding goods are written off, no stock is
added, and the PO is stamped with `closed_short`, `closed_short_at` and
`closed_short_by`.

What it does not record is **why**.

Three months later an accountant sees a $7.00 gap between what PO-2026-020 ordered
and what it received, and has no way to tell a supplier shortage from a damaged
pallet from a theft. The person who knew is the manager who clicked the button, and
the system asked them nothing. The write-off is the one moment where that knowledge
exists and is cheap to capture; every later attempt to recover it is expensive or
impossible.

Two smaller comprehension gaps are fixed in the same pass:

- The Order Items footer reads `TOTAL COST (ORDERED) $55.93` on a PO that only
  delivered $48.93 of goods. A clerk cannot tell from the footer whether the shop
  owes $55.93 or owns $48.93.
- The double-receive guard reports a replayed delivery as *"This purchase order
  changed while you were looking at it."* That is alarming and wrong: nothing
  changed underneath the user, their own submission already went through. On
  2026-08-01 this message was read as *"the system will not let me enter a smaller
  quantity"* — a misunderstanding of the whole feature caused by one sentence.

## Non-goals

- **Reversing a close-short.** Writing off is an admission, not a state to toggle.
  A mistaken write-off is corrected by a stock adjustment, which the Inventory
  Count page already provides and which leaves its own signed ledger entry.
- **Editing the reason after the fact.** The value of the field is that it was
  recorded at the moment of the decision. An editable field records the most
  recent story, not the true one.
- **A reasons admin screen.** Five codes in a PHP array is the whole requirement.
  A CRUD screen for a list that changes once a year is not.
- **Reporting on write-offs.** The codes are stored so that grouping is possible
  later. Nothing in this spec queries them in aggregate.

## Design

### 1. Schema

Migration `po_close_short_reason_v1` in `config.php`, alongside the existing
`po_partial_receive_v1`:

```sql
ALTER TABLE purchase_orders
  ADD COLUMN closed_short_reason VARCHAR(40)  NOT NULL DEFAULT '',
  ADD COLUMN closed_short_note   VARCHAR(255) NULL;
```

Two columns rather than one. `closed_short_reason` holds a **stable code**
(`supplier_oos`), never the display text, so that relabelling an option later does
not orphan history and so the column can be grouped. `closed_short_note` holds the
manager's free text.

**No backfill.** There are zero closed-short POs in the database today, so there is
nothing to guess at. Any row that does predate the column reads `''` and renders
"No reason recorded" — which is true, and is a better answer than an invented one.

### 2. The reason list

A new helper in `config.php`, beside `po_may_close_short()`:

```php
function po_short_reasons(): array {
    return [
        'supplier_oos'    => 'Supplier out of stock',
        'damaged'         => 'Damaged in transit',
        'never_arrived'   => 'Delivery never arrived',
        'supplier_cancel' => 'Cancelled by supplier',
        'other'           => 'Other',
    ];
}
```

One array, read by both the dropdown that offers the options and the server code
that validates them, so the two cannot drift apart. Every render goes through
`po_short_reasons()[$code] ?? $code`: an unrecognised code displays as itself
rather than collapsing to blank.

### 3. Modal and handler

The native `confirm()` on the Close short form
(`purchase_order_view.php:373`) is replaced by a modal following the pattern
already used by `suppliers.php` — `.modal-overlay > .modal` with
`.modal-header` / `.modal-footer`, opened and closed by small local functions.
`suppliers.php` is the sibling inventory page, so this introduces no new
convention.

The form stays a **plain POST**, not AJAX. The existing handler already redirects
with a message code and the page already renders those messages; AJAX would add a
second response path for no gain.

Modal contents:

- a sentence stating the amount to be written off and that no stock will be added
- `<select name="short_reason" required>` populated from `po_short_reasons()`, with
  an empty first option so no reason is pre-selected by accident
- `<textarea name="short_note" maxlength="255">`, optional
- Cancel / Confirm write-off

Handler changes at `purchase_order_view.php:123-143`:

- reject when `short_reason` is not a key of `po_short_reasons()` →
  `po_redirect($from_list, $po_id, 'badreason')`
- `trim()` the note and cut it to 255 characters
- write both values in the **same** `UPDATE` that already carries
  `WHERE po_id=? AND status='Partially Received'` and its `affected_rows` check

Writing them in the existing statement rather than a follow-up one means the
concurrency guard keeps covering the whole change: a PO that stopped being
`Partially Received` between the page render and the POST is still refused, and
cannot end up with a reason attached to a state change that never happened.

The role gate (`po_may_close_short()`), the CSRF token and the `affected_rows`
stale check are otherwise untouched. `required` on the `<select>` is a convenience
for the user; the server-side key check is the actual rule.

### 4. Where the reason surfaces

**On the PO page** — a permanent amber strip below the header whenever
`closed_short = 1`:

> ⚠️ Closed short by **Sokun** on Aug 1, 2026 — **Supplier out of stock**.
> $7.00 written off.
> *"Only had 699g in the cold room."*

Amber, matching the `Partially Received` badge, for the reason already recorded in
those files: a write-off needs attention but is not an error. The note renders only
when non-empty. The amount is `$vals['outstanding']` from `po_line_values()`, the
same derived figure the Cost Summary already displays.

**On the Purchase Orders list** — a closed-short PO currently carries a plain green
`Received` pill, making it indistinguishable from a clean delivery. A small amber
`closed short` chip is added beside it, with `title=` carrying the reason and note,
so a manager scanning the list finds every write-off without opening a single PO.

Both screens keep separate `$statusColors` arrays and separate label maps — a known
trap from the partial receiving work. This change touches presentation on both, so
both must be edited.

### 5. Footer: ordered and received

The Order Items footer becomes:

```
ORDERED $55.93 · RECEIVED $48.93
```

(figures shown for a PO that ordered $55.93 of goods and took delivery of $48.93)

**ORDERED remains `purchase_orders.total_cost`**, the stored document, and is not
re-derived from the line items. Deriving it would introduce a third money figure
that disagrees with the `$55.93 ordered` already shown in the Cost Summary
directly above, and `total_cost` records the order as placed to the supplier —
which is what will be invoiced. RECEIVED is `$vals['received']`, the same derived
figure the Cost Summary uses.

Consequence, stated plainly: on **legacy** POs where the stored `total_cost`
disagrees with `SUM(qty_ordered * unit_cost)` — PO-2026-009 reads $153.00 ordered
against $148.00 received with $12.00 outstanding — the footer inherits that
mismatch. It is neither introduced nor worsened here; the same two numbers are
already adjacent in the Cost Summary. Any PO created since
`purchase_order_create.php:48` derives `total_cost` from its own lines and is
self-consistent.

### 6. Splitting the two receive failures

`po_receive_line()` in `config.php` returns a bare `false` for two different
situations, and the caller reports both as `stale`:

- another clerk received against this line, or the PO moved underneath the user —
  genuinely *"changed while you were looking at it"*
- this exact delivery already went through, and the POST is a double-click, a
  back-button re-submit or a bfcache replay

The second is the common one and the message is wrong for it. On the **failure path
only**, the caller re-reads the line's stored `qty_received`; if it already equals
or exceeds `seen + qty`, the submission was a replay and the user is told:

> This delivery was already recorded.

Anything else keeps the existing wording. The extra `SELECT` runs only after a
claim has already failed, so the successful path is unchanged.

`po_receive_line()` itself keeps returning `bool`. The distinction is a message
concern, and widening its return type would force every caller — including the
test — to care about a difference only the UI uses.

## Testing

Extends `tests/purchase_order_test.php`, which already covers partial receiving,
inside its existing `begin_transaction()` / `rollback()`-in-`finally` harness. That
harness is required: these tests mutate real stock, and straight-line cleanup is
skipped by a fatal.

| Case | Expected |
|---|---|
| Close short with a valid reason | reason + note stored, status `Received`, `closed_short=1`, **`ingredients.stock_quantity` unchanged** |
| Close short with a code not in `po_short_reasons()` | refused, PO still `Partially Received`, no columns written |
| Close short on an already-`Received` PO | `affected_rows` 0, nothing written |
| Note longer than 255 characters | stored truncated, no SQL error |
| Replay a receive (same `seen`, line already advanced) | classified as already-recorded, not stale; `qty_received` unchanged |
| `po_short_reasons()` keys | every key ≤ 40 characters, so no code can be silently truncated by the column |

The stock-unchanged assertion is the important one. Close short exists precisely
because no goods arrived, and a future edit that routes it through
`po_receive_line()` would be a phantom-stock bug of exactly the kind the partial
receiving work was built to remove.

## Files touched

| File | Change |
|---|---|
| `config.php` | migration `po_close_short_reason_v1`; `po_short_reasons()`; nothing in `po_receive_line()` |
| `purchase_order_view.php` | close-short modal; handler validation; amber strip; footer; replay message |
| `purchase_orders.php` | `closed short` chip beside the `Received` pill |
| `tests/purchase_order_test.php` | the cases above |
