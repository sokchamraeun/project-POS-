# The add-to-order cart must not be the new-order cart

**Date:** 2026-08-02
**Branch:** `feat/product-addons`

## Problem

There is exactly one cart, `$_SESSION['cart']`, and two jobs share it: building a
new order at the menu, and adding drinks to an existing open tab.

`menu.php?add_to_order=12` is only a **display flag** — `menu.php:85` reads
`$add_to_order_mode` from the query string, and nothing anywhere clears or separates
the cart when that mode is entered. So a drink queued for a walk-in customer is
still in the cart when the cashier switches to a tab, and **Add to Order #12** would
put that stranger's drink on the tab.

Reported from live use: an Iced Matcha Latte added at `menu.php` appeared unchanged
at `menu.php?add_to_order=12`.

### A second hole with the same root

`$_SESSION['add_to_order_id']` is set by `add_to_existing_order.php:102` and cleared
only on a successful confirm (`confirm_order.php:251`) or when the parent order is
found closed (`:115`). Abandoning the flow leaves it set.

`confirm_order.php:95-97` anticipates exactly this:

> *"A stale `$_SESSION['add_to_order_id']` left over from a previous, abandoned
> add-to-order flow would otherwise hijack every subsequent normal checkout."*

Its guard only honours the session value when the **form** declares add-to-order
mode. That holds for `menu.php`, which sets the field from the URL (`:1108`). It does
**not** hold for `cart.php:1426`, which sets the same field *from the session* — so
the guard is circular there and the stale value re-asserts itself. `cart.php` is
reachable as a full page through the `Go back` links `confirm_order.php` prints on
payment errors.

Both defects are the same root cause: **one cart and one mode flag doing two jobs.**

## Decisions taken

- **The in-progress cart is held, not discarded.** A half-built order the cashier
  cannot recover is worse than a moment of surprise.
- **Leaving add-to-order mode restores it immediately**, by any route.
- **The cashier is told.** A one-line notice on the add-to-order banner when a stash
  exists, so an empty cart is never unexplained.

## Non-goals

- **Fully separate cart keys.** `$_SESSION['cart']` appears **46 times across 9
  files** (`cart.php` alone has 17). Threading a second key through all of them is a
  session-plumbing refactor across code that handles money, for the same practical
  guarantee this spec achieves in four touchpoints. The tidier end state is not worth
  that risk today.
- **The 40 orders with `payment_method = '0'`.** Unexamined, unrelated, separate.
- **Changing what add-to-order does once confirmed.** `confirm_order.php`'s
  existing-order branch is correct and is not touched.

## Design

### 1. One active cart, plus a stash

`$_SESSION['cart']` remains the only cart any code reads or writes. All 46 existing
references keep working unchanged, because there is still exactly one active cart at
any moment — it simply is not the *same* cart across modes.

A new `$_SESSION['cart_stash']` holds the new-order cart while add-to-order mode is
active. It is never rendered and never checked out; it exists only to be swapped
back.

### 2. Entering add-to-order mode

`add_to_existing_order.php`, immediately before its redirect (`:102-107`):

```
if cart is non-empty and cart_stash is NOT already set:
    cart_stash := cart
cart := []
```

**A stash is created only when the cart is non-empty.** The presence of
`$_SESSION['cart_stash']` is therefore the single signal for both the notice in §4
and the restore in §3 — there is no separate flag to keep in step with it. An empty
cart produces no stash, no notice, and nothing to return.

**An existing stash is never overwritten.** `find_order.php` offers *Add Items*, so
`add_to_existing_order.php` is reachable while add-to-order mode is already active —
without passing through `menu.php`, which means the restore in §3 never fires. Without
this guard:

```
cart_stash = [Matcha]     held from the new order
cart       = [Latte]      queued for tab 12
→ Add Items on tab 15
→ cart_stash := [Latte]   overwrites [Matcha] — silently lost
```

That is exactly the failure this spec exists to prevent, on the one path where the
restore cannot intervene. With the guard, `[Matcha]` survives and returns when the
cashier next reaches a plain menu.

The consequence is deliberate: switching from tab 12 to tab 15 mid-add **discards the
drinks queued for tab 12**. That is the correct reading — the cashier abandoned that
tab's additions by navigating to another one — and it is the in-flight add-cart being
dropped, never the held new-order cart.

### 3. Leaving it

**The exit condition is `menu.php` loading without `?add_to_order`.** That is the
only way to begin a new order, so it is the moment the cashier has demonstrably left
the flow — whether by a Back button, the nav, or typing the URL.

At `menu.php:85`, where `$add_to_order_mode` is already computed:

```
if add_to_order_mode is 0 and cart_stash is set:
    cart := cart_stash
    clear cart_stash, add_to_order_id, add_to_daily_no, paylater_reopen
```

**The condition keys on `cart_stash`, not on `add_to_order_id`.** A successful add
already clears `add_to_order_id` at `confirm_order.php:251`, so keying on it would
leave the stash orphaned after the one path that is meant to work — the cashier
would confirm an add and never get their drinks back. `cart_stash` is the thing that
needs returning, so its presence is what drives the return.

This closes the second hole as a side effect: the stale `add_to_order_id` that
`confirm_order.php:95` warns about is now cleared the moment the cashier returns to
a normal menu, so `cart.php` can no longer resurrect an abandoned flow.

**Residual risk, stated rather than hidden:** a cashier who reaches `cart.php`
*without* passing through `menu.php` — only possible via a `Go back` link after a
payment error — still carries the stale flag. Reaching that state requires having
been in add-to-order mode and hitting a payment error, and the cart shown will be the
add-cart, which is the correct one for that flow. It is not fixed here.

### 4. Telling the cashier

The add-to-order banner (`menu.php:1070`) gains one line when a stash exists:

> *N drink(s) held from your previous order — they'll come back when you leave.*

Wording matters: it says the drinks are **held**, not removed. The failure this
prevents is a cashier seeing an empty cart, assuming the system lost their work, and
rebuilding it.

### 5. `cart.php`'s mode flag

`cart.php:1426` derives `is_add_to_order` from `$_SESSION['add_to_order_id']`. With
§3 clearing that key on exit, the value is trustworthy whenever `cart.php` is
reached through the normal flow. **No behavioural change to `cart.php` is required.**

This is deliberate: rewiring the flag to an explicit source means giving `cart.php`
a way to know the mode that the session does not already provide, which is the
separate-keys refactor this spec declines.

**A comment is added at that line** recording why the session read is correct and
what remains accepted, so the next reader does not "repair" it by adding a second
session read and rebuild the circular guard `confirm_order.php:95` warns about:

```php
/* Mode comes from the session on purpose: menu.php clears add_to_order_id the
   moment the cashier returns to a plain menu, so a stale value cannot survive to
   hijack a normal checkout. Do NOT add a second session read here to "fix" this —
   that is what made confirm_order.php:95's guard circular in the first place.
   Accepted gap: reaching this page via a payment-error "Go back" link while still
   in add-to-order mode keeps the flag. See
   docs/superpowers/specs/2026-08-02-add-to-order-cart-stash-design.md §5. */
```

## Testing

No test framework covers session state; these are manual, in a browser.

| Case | Expected |
|---|---|
| Cart has 1 drink → Add Items on a tab | add-cart is empty; banner says 1 drink held |
| Add a different drink → Add to Order | only the new drink joins the tab; the tab total rises by that drink alone |
| After that confirm | the original drink is back in the cart |
| Cart has 1 drink → Add Items → navigate to `menu.php` without confirming | the original drink is back; the add-to-order banner is gone |
| Cart empty → Add Items | no stash, no notice, add-cart empty |
| Abandon add-to-order, then check out a new order normally | the new order is its own order and is NOT appended to the old tab |
| Cart has 1 drink → Add Items tab 12 → queue a drink → **without returning to the menu**, Add Items tab 15 from `find_order.php` | the held drink still returns on exit; tab 12's queued drink is dropped |
| Back out to the menu, then Add Items on a different tab | the restored cart is stashed again; nothing accumulates or is lost |
| Confirm an add, then Add Items on another tab before visiting the menu | the held drink survives and returns when the menu is next reached |

The abandon case is the second defect and the one most worth re-testing: before this
change, an abandoned flow could silently append a fresh sale to a previous tab. The
nested-entry case is the one the restore cannot cover, which is why §2 guards the
stash rather than relying on §3.

## Files touched

| File | Change |
|---|---|
| `add_to_existing_order.php` | stash the cart before redirecting, without overwriting an existing stash |
| `menu.php` | restore on exit; one-line notice on the banner |
| `cart.php` | comment only, at `:1426` — no behavioural change |

`confirm_order.php`, `add_to_cart.php` and the other five readers of
`$_SESSION['cart']` are untouched.
