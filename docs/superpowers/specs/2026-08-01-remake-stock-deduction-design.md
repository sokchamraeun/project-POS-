# Remakes must consume ingredients

**Date:** 2026-08-01
**Branch:** `feat/product-addons`

## Problem

`remake_order.php` does three things: inserts a row into `order_remakes`, optionally
rewrites item options, and sets the order back to `Preparing`. It never touches
ingredient stock.

A barista physically pours a second drink. Milk, syrup, tea and cups leave the
building. The system records none of it.

This is the phantom-stock failure the partial receiving work was built to remove,
arriving through a different door: recipes deduct against ingredients that are not
there, the low-stock alert never fires, and the gap surfaces weeks later in
Inventory Count as an untraceable shortage. It is quieter than an oversell because
nothing ever errors.

Two further defects in the same handler:

- **`made_qty` / `made_at` are never reset.** Whole-order Complete sets
  `made_qty = quantity` on every row (`view_order.php:3223`), and `is_made` is
  derived from that count (`:3087`). A remade order returns to `Preparing` with
  every drink still flagged made. The barista card does not go empty — `:1999`
  falls back to the full list when nothing is unmade — but the made state is a lie,
  and the second Complete's `WHERE made_qty < quantity` stamps nothing.
- **The handler has no transaction.** The log insert, the option adjustments and
  the status flip are three independent statements. Adding a stock deduction to an
  unguarded sequence would allow stock to move while the remake is not recorded.

## Decisions taken

**Remakes are per drink and per quantity.** The modal gets a checkbox per item and,
on a ticked item, a quantity capped at that line's quantity and defaulting to 1.

Deducting the whole order would be a worse lie than deducting nothing: on a
four-drink order where one was too sweet it invents three drinks of consumption.
The same argument applies inside a line — remaking all of `3× Iced Lemon Tea`
because one was wrong over-deducts by two. `made_qty` is already a count rather
than a flag, so partial state has somewhere to live.

## Non-goals

- **A new `change_type`.** `_deduct_stock()` writes `order_deduct`, and the deduct
  classifier is duplicated across five places in `ingredient_history.php` — the
  poll-stats branch being the one that gets missed. A `remake_deduct` type would
  have to be added to all five or the ledger would silently misclassify. Remakes
  are distinguished by the `reference` string instead, which is already a
  parameter, and by the `order_remakes` row that always accompanies them.
- **Re-billing the customer.** `remake_order.php:109-110` deliberately leaves
  `order_items.price` alone; a remake is service recovery. Stock is a physical
  fact and moves; money does not.
- **Blocking a remake that would take stock negative.** `_deduct_stock()` already
  deducts only what is on hand and returns shortfalls rather than going negative.
  A remake is recorded after the drink has been poured, so refusing it would record
  less than what happened.

## Design

### 1. Move `_deduct_stock()` to `config.php`

It currently lives at `confirm_order.php:637`, private to that file. `remake_order.php`
needs the same logic, including its milk-substitution branch, because a remake can
change the milk.

It moves to `config.php` behind `if (!function_exists(...))`, joining
`po_receive_line()` as a single writer that every caller shares. It is **not**
copied — a duplicated stock formula is how the buy-X-get-1-free calculation ended
up in ten places.

Signature gains one optional parameter, defaulted so existing calls are unchanged:

```php
_deduct_stock(mysqli $conn, int $product_id, int $qty, string $milk_choice,
              int $order_id = 0, float $size_factor = 1.0,
              ?string $reference = null): array
```

`$reference` overrides the internally built ledger reference. Remakes pass
`"Remake of order #<daily_order_no>"`.

### 2. Modal

The existing per-item blocks in the remake modal (`#remakeAdjustments`) gain a
checkbox and, when ticked, a quantity input `min=1 max=<line quantity> value=1`.
Unticked items are not remade: no deduction, no made-state change, and — a change
from today — **no option adjustment either**, since adjusting a drink nobody
remade would rewrite history.

`confirmRemake()` sends the ticked items with their quantities. The reason textarea
is unchanged and still required.

### 3. Handler

`remake_order.php` wraps everything from the `order_remakes` insert to the status
flip in `begin_transaction()` / `commit()`, with `rollback()` in a `catch`.

Per ticked item, in order:

1. **Validate.** The `item_id` belongs to this `order_id` (the handler already
   builds `$item_product` for exactly this reason) and `1 <= qty <= quantity`.
   Anything else is refused and rolls the whole remake back — a partially applied
   remake is worse than a refused one.
2. **Resolve the size factor.** `order_items.size_label` joined to
   `product_sizes.label` for that `product_id` gives `size_factor`. No matching row
   — an unsized product — means `1.0`. This is the same multiplier
   `confirm_order.php` applies, so a Large remake consumes Large quantities.
3. **Deduct** via `_deduct_stock()`, passing the **adjusted** milk where the
   modal changed it, not the milk originally ordered. The drink that gets poured is
   the adjusted one.
4. **Reset made state:** `made_qty = GREATEST(0, made_qty - <remade qty>)`, and
   `made_at = NULL` when it reaches 0. Decrementing rather than zeroing preserves
   the made state of the drinks on that line nobody complained about.

Shortfalls returned by `_deduct_stock()` are collected and returned in the JSON so
the cashier sees the same warning the ordering flow gives.

If no item is ticked, the request is refused with "Select at least one drink to
remake" — the alternative is a logged remake that consumed nothing, which is the
bug being fixed.

### 4. Access

Unchanged. `canRemake` (`view_order.php:1788`) is `admin || manager || staff`;
baristas do not press this button. The cashier logs the remake, the order returns
to the barista's queue. `remake_order.php` keeps its own server-side role check
rather than trusting the markup.

## Testing

A new `tests/remake_test.php`, using `begin_transaction()` / `rollback()` in a
`finally` — these tests move real stock, and straight-line cleanup is skipped by a
fatal.

| Case | Expected |
|---|---|
| Remake 1 of a `3×` line | stock falls by exactly one drink's recipe; `made_qty` 3 → 2; `made_at` unchanged |
| Remake all 3 of a `3×` line | stock falls by three; `made_qty` → 0; `made_at` → NULL |
| Remake with a changed milk | the substitute ingredient is deducted, the original is not |
| Remake a Large | deducted amount equals base × `size_factor`, not base |
| `item_id` from another order | whole remake refused, no stock moved, no `order_remakes` row |
| qty 0, negative, or above the line quantity | refused, nothing written |
| No items ticked | refused with a message; no `order_remakes` row |
| Ledger | one `order_deduct` row per ingredient carrying the remake reference |
| Unsized product | factor 1.0, no error |

The refusal cases matter most: each asserts that **nothing** was written, which is
what the missing transaction currently cannot guarantee.

## Files touched

| File | Change |
|---|---|
| `config.php` | receives `_deduct_stock()` + optional `$reference` |
| `confirm_order.php` | loses the function body; calls unchanged |
| `remake_order.php` | transaction, per-item validation, deduction, made-state reset |
| `view_order.php` | checkbox + quantity per item in the remake modal; `confirmRemake()` payload |
| `tests/remake_test.php` | new |
