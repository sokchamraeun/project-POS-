# Configurable loyalty earn rate

**Date:** 2026-08-03
**Branch:** `feat/product-addons`
**Status:** design approved, not implemented

## Problem

The earn rate is hardcoded at one point per drink. An admin who wants *one point
per two drinks*, or *two points per drink*, cannot express it — the number lives
in four different PHP files rather than in `settings.php` beside the tax rate and
the exchange rate.

Those four sites do not agree with each other today:

| Site | Counts | Merch and gifts excluded? |
|---|---|---|
| `confirm_order.php:575` — paid up front | `$point_qty` | **yes** |
| `confirm_order.php:177` — add-to-order | delta | yes |
| `admin_pay_cash.php:168` — pay-later, counter | `SUM(quantity)` | **no** |
| `check_payment.php:139` — pay-later, Bakong | `SUM(quantity)` | **no** |

So a pay-later order containing a T-shirt earns points for the T-shirt, and a
free loyalty gift drink (`product_id = 0`) earns a point, while the same basket
paid up front earns neither. Putting a multiplier on top of that would multiply
the discrepancy instead of exposing it, which is why the two changes ship
together.

## What we are building

Two settings, one shared writer, and a carry-forward remainder so a fractional
rate still works for the customer who buys one drink at a time.

## Scope decisions

Three decisions were taken during brainstorming, each with the rejected
alternative recorded.

1. **A ratio, not a multiplier.** X points per Y drinks, so `1 per 2`, `1 per 3`
   and `2 per 1` are all expressible. A single multiplier was the first proposal
   and could not say "one point per two drinks" at all.
2. **The remainder carries on the card.** Flooring per order was rejected because
   it silently breaks the most common sale: at *1 point per 2 drinks*, a customer
   who buys one drink per visit would floor to zero every time and never earn
   anything. Rounding up was rejected because it makes every ratio more generous
   than the number the admin typed, which will confuse whoever sets it.
3. **Refunds restore progress exactly.** The cheaper option — claw back the
   points and leave the progress — was rejected because the customer silently
   loses progress they had *before* the refunded order, and the drift only ever
   runs against them. Four orders were refunded during testing on 2026-08-02
   alone, so this path is real, not theoretical.

**Not in scope, deliberately:** points per dollar spent. It is a different model
that would silently revalue every card already issued and every reward threshold.
The ratio can gain a mode switch later without reworking any of this.

## The earning model

Two rows in the existing key/value `settings` table, read into constants the way
every other setting already is (`config.php:52-64`):

```php
if (!defined('LOYALTY_POINTS_PER'))    define('LOYALTY_POINTS_PER',    max(1, (int)($_cafe_settings['loyalty_points_per']    ?? 1)));
if (!defined('LOYALTY_POINTS_DRINKS')) define('LOYALTY_POINTS_DRINKS', max(1, (int)($_cafe_settings['loyalty_points_drinks'] ?? 1)));
```

Both clamp to a minimum of 1. Zero on the left awards nothing forever; zero on
the right divides by zero. Neither is a rate anyone means to type, and the
clamp is in `config.php` as well as in the form so a direct database edit cannot
break the till.

**The defaults reproduce today's behaviour exactly** — 1 point per 1 drink.
Nothing changes for any existing card until an admin edits the setting.

### The arithmetic

One carry-forward accumulator per card:

```
numerator = progress + (qty × X)
points    = intdiv(numerator, Y)
progress  = numerator % Y
```

At **1 point per 2 drinks**: one drink gives 0 points and progress 1; the next
visit's single drink makes numerator 2, awards the point, and returns progress to
0. The single-drink regular still earns.

At **2 points per drink**: one drink gives numerator 2, awards 2 points, progress 0.

`qty` is earning drinks only — categories with `earns_points = 1`, and never a
gift line (`product_id = 0`). That is what `confirm_order.php` already does
correctly and what the two pay-later paths currently do not.

### Reversal

Closed-form, so a refund needs no history replay:

```
progress = progress_now + (points_earned × Y) − (qty × X)
```

This is exact because `points_earned = intdiv(progress_before + qty×X, Y)` and
`progress_now = (progress_before + qty×X) % Y`, so the two substitute back to
`progress_before`.

## Components

One shared writer in `config.php`, beside `_deduct_stock()` and the tender
helpers:

```php
loyalty_award(mysqli $conn, int $card_id, int $order_id, int $qty, string $note): int
loyalty_reverse(mysqli $conn, int $card_id, int $order_id, int $points_earned, int $qty): void
```

`loyalty_award()` returns the points awarded, updates `points`, `points_progress`,
`total_orders`, `total_drinks`, writes the `loyalty_history` row, and stores
`orders.points_earned` and `orders.points_qty`. All four award sites call it.
`cancel_order.php` calls `loyalty_reverse()`.

One writer rather than a formula in four files is the same choice already made
for `_deduct_stock()` and `order_board_state()`. The buy-X-get-1-free rule in
this codebase is duplicated in ten places and memory records it as a standing
hazard; this is that hazard caught early.

**Retained from the current code**, because they were deliberate: the award is
split into separate statements so a missing counter column cannot block the
points update, and the `loyalty_history` insert falls back from `'earned'` to
`'adjusted_add'` when a schema uses a different ENUM set.

## Schema

Two columns, added through the house `_migrate()` mechanism (`config.php:69`):

```php
_migrate($conn, 'loyalty_progress_v1', function($db) {
    $db->query("ALTER TABLE loyalty_cards ADD COLUMN IF NOT EXISTS points_progress INT NOT NULL DEFAULT 0");
    $db->query("ALTER TABLE orders        ADD COLUMN IF NOT EXISTS points_qty      INT NOT NULL DEFAULT 0");
});
```

`points_progress` is the carry, always `0..Y-1`. `points_qty` records how many
earning drinks an order counted; it is stored rather than recomputed because
`order_items` can change afterwards through add-to-order, so the quantity that
actually earned is not recoverable at refund time.

**No backfill.** Both default to 0, which is correct for all 18 existing cards
and every past order at the default 1:1 rate: nobody holds partial progress, and
reversing an old order computes `0 + points_earned×1 − qty×1 = 0`.

## Settings UI

A new `loyalty` group in `settings.php`, matching the existing group shape
(`settings.php:29-40`):

```php
'loyalty' => [
    'loyalty_points_per'    => (string)max(1, min(100, (int)($_POST['loyalty_points_per']    ?? 1))),
    'loyalty_points_drinks' => (string)max(1, min(100, (int)($_POST['loyalty_points_drinks'] ?? 1))),
],
```

Rendered as a sentence rather than two bare boxes, because `1` and `2` in
adjacent inputs is ambiguous until it is spelled out:

```
Loyalty points
Earn  [ 1 ]  point(s)  per  [ 2 ]  drink(s)

Currently: 1 point per 2 drinks.
Merch and free gift drinks don't earn points.
```

The live summary line updates as the numbers change and is the part that makes
the ratio unmistakable.

## Hide Add Discount

Independent of everything above and shipping as its own commit. The `Add
Discount` button in `menu.php`'s cart panel is wrapped in `if (false):` with a
restore comment — the pattern already used for the Riel tile and the bulk
enable-sizes tool (`products.php:1508`).

The POST handler, the `manual_discount` column and every reader stay untouched,
so orders that already carry a discount still render it and the control can be
restored by flipping one word. The reason is that discounting needs more shape
than a single blanket control before it goes in front of customers.

## Testing

`tests/loyalty_test.php`, following the `tests/purchase_order_test.php` harness —
`begin_transaction()` with `rollback()` in a `finally`, because these tests write
to real cards.

The arithmetic is pure and needs no database:

| Rate | Buys | Starting progress | Expect |
|---|---|---|---|
| 1 per 1 | 3 drinks | 0 | 3 points, progress 0 — today's behaviour, unchanged |
| 1 per 2 | 1 drink | 0 | 0 points, progress 1 |
| 1 per 2 | 1 drink | 1 | 1 point, progress 0 |
| 1 per 2 | 3 drinks | 1 | 2 points, progress 0 |
| 2 per 1 | 1 drink | 0 | 2 points, progress 0 |
| 1 per 3 | 7 drinks | 0 | 2 points, progress 1 |
| any | 0 drinks | any | 0 points, progress unchanged |
| 0 per 0 | 1 drink | 0 | clamps to 1 per 1, no division by zero |

Against the database, inside the rollback:

- **Round-trip**: award then reverse returns both `points` and `points_progress`
  to exactly their starting values, swept across several rates and several
  starting progresses. This is the property worth asserting rather than
  hand-checking, and it is what makes refunds safe.
- A merch-only order earns 0 (`categories.earns_points = 0`).
- A loyalty gift line (`product_id = 0`) earns 0. **This fails today** on both
  pay-later paths.
- The same basket earns identically whether paid up front, settled at the counter
  or settled by Bakong. **This fails today.**
- `points_progress` never leaves `0..Y-1`.

## Related

- Memory: `paylater-loyalty-points-seams` (points are awarded at settlement,
  three award sites), `merch-non-drink-items` (`categories.earns_points`),
  `loyalty-standalone-redemption`, `buy-x-get-1-free-semantics` (the
  ten-copy hazard this design avoids)
- `docs/superpowers/specs/2026-08-02-riel-into-cash-tender-design.md` — same
  one-shared-writer approach for the tender helpers
