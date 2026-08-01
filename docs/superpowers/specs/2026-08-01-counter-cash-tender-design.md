# Counter cash settlement needs a tender step

**Date:** 2026-08-01
**Branch:** `feat/product-addons`

## Problem

The **Cash** button on an order card (`_order_card.php:97`) is a plain link to
`admin_pay_cash.php?order_id=N`. That page settles the order **while it loads** —
`begin_transaction()` at line 33, no form, no confirmation, no input.

The customer hands over $100 for a $4.73 tab and the cashier has nothing to compute
change from. They do it in their head, which is how a drawer ends up short. The
checkout screen in `menu.php` has had a quick-tender calculator for exactly this
reason; its own code comment records that leaving the field blank meant 93% of cash
sales carried no tender line.

**A second defect comes free with the first: this is a money-mutating GET.** A
refresh, a back-button, a link prefetch or a double-tap settles a tab. Nothing in
the handler distinguishes a deliberate click from a repeat.

The button appears on **Pay Later**, on **Pending Payment**, and on the dashboard.
All three route through `admin_pay_cash.php`, so all three have both defects and
all three are fixed by changing that one file.

## Non-goals

- **Persisting the tender.** Nothing in the schema stores an amount received or
  change given — `order_payments` has no such column and `menu.php` keeps its
  calculator entirely client-side. Mirroring that keeps this change schema-free.
  Whether the drawer should record tenders is a real question and a separate one.
- **Touching Bakong.** `admin_pay_bakong.php` has its own flow and no change to
  compute.
- **Revisiting what gets recorded as cash.** The method-recording fix landed
  earlier today, including its deliberate pay-later and split-tender exclusions.
  This spec adds a step in front of that logic and does not alter it.

## Design

### 1. GET renders, POST settles

`admin_pay_cash.php` splits in two.

**On GET** it renders a tender screen: the order, the amount due, and the quick
tender calculator. It writes nothing. A refresh re-renders it; a prefetch costs a
page render.

**On POST**, with a valid CSRF token, it runs the settlement logic that exists
today, unchanged. All of it — the `paylater` / `PendingPayment` / `Completed`
status branch, the `order_payments` update, the single-method guard, the loyalty
award with its `points_earned === 0` check — moves inside the POST branch verbatim.

This is the whole fix for the GET-mutation defect. It is not an extra feature: a
page that spends money needs a submit.

### 2. The tender screen

Reuses `menu.php`'s block rather than inventing one: `.cp-change-calc` with its
`Amount Received` input, `.cp-tender-quick` buttons, and the `Change to give back`
row. Same class names, same behaviour, so a cashier who has used checkout already
knows this screen.

- **Amount Received** prefills with the amount owed, so exact cash stays one tap.
  It only ever prefills an untouched field — `menu.php` marks typing with a
  `data-touched` flag set from `oninput`, which does not fire for a programmatic
  value, and that mechanism is kept.
- **Quick tender buttons** — Exact, $5, $10, $20, $50 — with the active one
  highlighted, so a cashier handed a $20 note taps once instead of typing.
- **Change to give back** updates live and is display-only.

The tender is a calculator. **The amount received is not sent to the server and
does not affect what is recorded**: the order is settled for its full amount
either way. This must stay true, because a tender below the total would otherwise
look like a partial payment the system never agreed to.

`Confirm Cash Payment` submits. Nothing gates the button on the amount entered —
a cashier who has already counted the change should not be blocked by a field they
skipped.

### 3. Where it returns to

`admin_pay_cash.php` already reads `?return=dashboard` and otherwise sends the user
to `find_order.php?tab=pending`. That is preserved and carried through the POST as
a hidden field, so a settlement started from Pay Later returns to Pay Later and one
started from the dashboard returns to the dashboard.

### 4. Cancel

The tender screen gets a Cancel that returns to the same page, having written
nothing. Today there is no way to back out — arriving at the URL is the settlement.

## Testing

Extends the manual E2E checklist; the settlement logic itself is unchanged and
already covered.

| Case | Expected |
|---|---|
| GET `admin_pay_cash.php?order_id=N` | tender screen renders; order still unpaid |
| Refresh that GET | still unpaid |
| POST with a valid token | settled exactly as before this change |
| POST replayed (back then resubmit) | second POST finds the order already settled and does not double-award loyalty points |
| POST without a CSRF token | refused |
| $100 tendered on a $4.73 tab | change reads $95.27; the order records $4.73 |
| Pay Later entry point | returns to Pay Later |
| Pending Payment entry point | settles and returns correctly |
| Dashboard entry point (`?return=dashboard`) | returns to the dashboard |

The replay case is the one to watch. Requiring a POST removes the accidental
settlement, but a deliberate resubmit is still possible, and the loyalty award is
the part that must not run twice.

## Files touched

| File | Change |
|---|---|
| `admin_pay_cash.php` | GET renders a tender screen; existing settlement moves into a CSRF-checked POST branch |

`_order_card.php` needs **no** change. Its Cash link already points at
`admin_pay_cash.php?order_id=N`, and that URL now renders instead of settling. The
`interceptPayLater` handler on the pay-later variant is a loyalty prompt that runs
before navigation and is unaffected.
