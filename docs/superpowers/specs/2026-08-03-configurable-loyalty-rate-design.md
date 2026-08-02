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

## Bug fixes included, not just a refactor

Two of the four award sites are **patched**, not merely moved behind a helper.
Anyone reading this later should not mistake the change for a tidy-up:

1. **`admin_pay_cash.php:168` and `check_payment.php:139` count merch and gift
   lines.** They use `SUM(quantity)` with no filter, so a pay-later order
   containing a T-shirt awards a point for the T-shirt, and a free loyalty gift
   drink (`product_id = 0`) awards a point for itself. The same basket paid up
   front awards neither. After this change all four sites count the same thing.
2. **`merge_loyalty_cards.php` does not follow points across a merge for
   reversal.** See "Card merges" below — verified against live data, 3 cards are
   already merged.

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

**The invariant `0 ≤ progress < Y` is enforced, not assumed.** Both helpers clamp
the value they write and log through `error_log()` when a clamp actually fires.
A drift into negative or `≥ Y` would otherwise be invisible until a customer
noticed their points were wrong, which is the worst way to find out. The clamp is
a symptom guard, not a fix: if it ever fires, the arithmetic above is wrong and
the log is the only thing that will say so.

The helpers read `LOYALTY_POINTS_PER` / `LOYALTY_POINTS_DRINKS` directly rather
than taking them as arguments, so no caller can pass `(0, 0)` and divide by zero.
They still clamp on read with `max(1, ...)`, because a constant defined by another
include or a direct database edit is outside this file's control.

## Card merges

`merge_loyalty_cards.php` folds one card into another: it moves `points`,
`total_orders` and `total_drinks` to the target, zeroes the source's points and
sets `is_active = 0` and `merged_into`. **The source row is deactivated, never
deleted** — `orders.loyalty_card_id` still references it, deliberately, so the
audit trail survives (`merge_loyalty_cards.php:7`).

Two consequences for this design, both verified against live data where 3 cards
are already merged:

1. **The merge must carry `points_progress`** — add it to the target and zero the
   source, exactly as it already does for `points`. Without this, a customer who
   is one drink into their next point loses that drink the moment their card is
   merged. Silent, and only ever against them.
2. **Reversal must follow `merged_into`.** A refund of an order placed before the
   merge would otherwise reverse against the source card, whose points are now
   zero and whose progress belongs to the target. `loyalty_reverse()` resolves the
   card through `merged_into` before writing, following the chain to its end.

Note that reversing points against a merged-away card is **already** wrong today,
before this change — the points moved to the target and `GREATEST(0, points - n)`
on a zeroed source silently does nothing. Fixing it here is cheap because
`loyalty_reverse()` is the one place that has to know.

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

`ADD COLUMN IF NOT EXISTS` is safe here: this server is **MariaDB 10.4.32** (10.0+
supports it) and `config.php` already relies on the same syntax in **47** places.
Not a new risk.

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

Settings clamping is tested separately, at the level where it happens — the
constant definitions, not the arithmetic. A stored `0` for either key must load
as `1`. The helpers cannot receive `(0, 0)` because they read the constants
rather than taking them as arguments, but they clamp on read anyway and that
clamp is asserted too.

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
- **Add-to-order accumulates without double-counting.** Order 2 drinks, award;
  then add 1 more drink through the delta path (`confirm_order.php:177`) and award
  again. At 1 per 2 the first award gives 1 point with progress 0, the second
  gives 0 points with progress 1 — not 2 points. `orders.points_qty` must end at
  3, not 1 or 2, or the later refund reverses the wrong amount. This is the path
  `points_qty` exists for, and it is the one most likely to be got wrong.
- **A merge carries progress.** Merge a card holding progress 1 into another
  holding progress 1 at rate 1 per 2: the target ends with the source's points
  added and progress carried, and the source ends at zero on both.
- **Reversal after a merge lands on the target card**, not the deactivated source.
- `points_progress` never leaves `0..Y-1`, asserted after every award, reversal
  and merge in the sweeps above rather than only in a dedicated test.

## Related housekeeping: hide Add Discount

**Shares nothing with the loyalty work** — different file, different concern, its
own commit. Recorded here only because it was asked for in the same breath; an
implementer working on loyalty can skip this section entirely.

The `Add Discount` button in `menu.php`'s cart panel is wrapped in `if (false):`
with a restore comment — the pattern already used for the Riel tile and the bulk
enable-sizes tool (`products.php:1508`). Six lines.

The POST handler, the `manual_discount` column and every reader stay untouched,
so orders that already carry a discount still render it and the control comes
back by flipping one word. The reason for hiding it is that discounting needs
more shape than a single blanket control before it goes in front of customers.

## Related

- Memory: `paylater-loyalty-points-seams` (points are awarded at settlement,
  three award sites), `merch-non-drink-items` (`categories.earns_points`),
  `loyalty-standalone-redemption`, `buy-x-get-1-free-semantics` (the
  ten-copy hazard this design avoids)
- `docs/superpowers/specs/2026-08-02-riel-into-cash-tender-design.md` — same
  one-shared-writer approach for the tender helpers
