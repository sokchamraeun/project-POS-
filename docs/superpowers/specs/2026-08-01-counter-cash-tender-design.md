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

The button appears on every `find_order.php` tab — **All Active**, **Pending
Payment** and **Pay Later** — via the shared `_order_card.php`. All of them route
through `admin_pay_cash.php`, so one file carries both defects and one file fixes
them. `dashboard.php` has no cash button; it contains no reference to
`admin_pay_cash`.

**A third defect, found while specifying this.** `admin_pay_cash.php:10` reads:

```php
$return_page = ($_GET['return'] ?? '') === 'dashboard' ? 'dashboard.php' : 'find_order.php?tab=pending';
```

Nothing in the codebase ever passes `return=dashboard` — the string appears
nowhere else — so that branch is unreachable and **every settlement returns to
`?tab=pending`**. A cashier who settles a pay-later tab is dropped on the Pending
Payment tab, having started on Pay Later. The same binary choice is repeated at
`:125` for the `payment_cash.php` success screen. This is current behaviour, not
something introduced here, but the return path is being touched anyway and leaving
it wrong would be a choice.

## Non-goals

- ~~**Persisting the tender.** Nothing in the schema stores an amount received or
  change given — `order_payments` has no such column and `menu.php` keeps its
  calculator entirely client-side.~~

  **WRONG — corrected 2026-08-01, see the amendment below.** The tender *is*
  persisted, in `order_payments.reference`. `menu.php:2300-2305` posts the received
  amount as `payment_references[]`, `confirm_order.php:446` writes it, and both
  `receipt_pdf.php:460` and `payment_cash.php:589` read it back to print
  Received / Change / Change (KHR). The first implementation was built on this
  false premise and therefore deliberately discarded the amount, which is why
  pay-later and pending-payment receipts have no change lines.
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

The binary `dashboard` / `tab=pending` choice is replaced by the **originating
tab**, carried end to end.

`_order_card.php` appends the tab it is being rendered under to both the Cash and
the Bakong links. `admin_pay_cash.php` validates that value against
`['all', 'pending', 'paylater', 'dashboard']` and falls back to `pending` on
anything else — an unvalidated value in a `Location:` header is a redirect the
caller controls. The chosen destination is carried through the POST as a hidden
field and passed on to `payment_cash.php` so the success screen's back button
agrees with it.

`dashboard` stays in the allow-list. It has no caller today, but it is one word and
it keeps the door open for a dashboard cash button without a second round of this.

Result: settling from Pay Later returns to Pay Later, from Pending Payment to
Pending Payment, from All Active to All Active.

This means `_order_card.php` **is** modified after all — one appended query
parameter on two links. Its `interceptPayLater` handler is a loyalty prompt that
runs before navigation and is unaffected.

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
| Settle from Pay Later | order reaches `Paid`; returns to the **Pay Later** tab |
| Settle from Pending Payment | order reaches `Preparing` — it still has to be made — and returns to the **Pending Payment** tab |
| Settle from All Active | returns to **All Active** |
| `?return=` set to junk, or omitted | falls back to `pending`; no open redirect |

The replay case is the one to watch. Requiring a POST removes the accidental
settlement, but a deliberate resubmit is still possible, and the loyalty award is
the part that must not run twice.

## Files touched

| File | Change |
|---|---|
| `config.php` | `pay_return_tab()` / `pay_return_url()` — one validated destination helper |
| `admin_pay_cash.php` | GET renders a tender screen; existing settlement moves into a CSRF-checked POST branch; return destination becomes a validated tab |
| `_order_card.php` | Cash and Bakong links carry the tab they are rendered under |
| `find_order.php` | exposes the validated current tab to the card |
| `payment_cash.php` | back-button map gains `all` |
| `tests/counter_cash_test.php` | new |

**Pay Later needs no new back-button entry.** `payment_cash.php:635-638`
short-circuits on `$is_paylater` and hardcodes a Pay Later button before
`$back_targets` is read. A settled pay-later order keeps
`payment_method = 'paylater'` — that is the deliberate exclusion from this
morning's method-recording fix — so the branch still fires and the button is
already right. An entry in the map would be unreachable.

The settlement logic itself — the status branch, the `order_payments` update, the
single-method guard, the loyalty award and its `points_earned === 0` check — is
moved verbatim and not edited. The status branch in particular is easy to
misremember: **pay-later settles to `Paid`, but a `PendingPayment` order settles to
`Preparing`**, because paying for it is not the same as making it.

---

# Amendment — 2026-08-01: persist the tender, and settle without leaving the queue

The first implementation shipped (`8979c9b`..`ebea2fd`). Using it surfaced one
wrong premise in this spec and two gaps.

## What was wrong

**The tender is persisted, in `order_payments.reference`.** The non-goal above
asserted the opposite. Evidence:

| Site | What it does |
|---|---|
| `menu.php:2300-2305` | posts the received amount as `payment_references[]` |
| `confirm_order.php:446` | writes it into `order_payments.reference` |
| `payment_cash.php:589` | prints Received / Change / Change (KHR) from it |
| `receipt_pdf.php:460-483` | same, on the printed receipt |

Because this spec said otherwise, `_cash_tender.php` deliberately gave the amount
no `name` attribute and threw it away. A counter settlement therefore produces a
receipt with no change lines, while a checkout sale produces one with them.

## Gap 1 — the change block never renders for a counter settlement

Two different causes:

- **Pending Payment (bakong → cash).** The settle converts
  `order_payments.payment_method` to `cash`, so `receipt_pdf.php:458`'s
  `$is_solo_cash` gate passes. The block is skipped only because `reference` is
  empty. Writing the tender is the whole fix.
- **Pay Later.** The settle deliberately keeps the method as `paylater` — the
  reporting bucket depends on it — so `$is_solo_cash` is **false** and the block is
  skipped even with a tender present. The gate must change too.

**The gate becomes "a single payment row carrying a numeric reference greater than
zero"**, rather than "the method says cash". That is the honest condition: it means
*a tender was recorded*. It cannot misfire on Bakong — all 182 bakong rows in this
database have a blank reference, and a Bakong reference is never numeric.

## Gap 2 — settling loses the queue

The Cash button navigates away from `find_order.php`. A cashier working a list of
tabs loses their place on every settlement.

The tender screen becomes a **modal on `find_order.php` that fetches the existing
partial** from `admin_pay_cash.php`. One source of truth: the standalone page keeps
working for direct links and without JavaScript, and the markup is not duplicated.
Rebuilding the modal client-side from the card's data was rejected — that is how the
buy-X-get-1-free formula ended up in ten places.

## Also folded in

**A tender below the total is silently accepted.** Nothing validates that the
amount covers the bill; the receipt renders `max(0, change)` so a $2 tender on a
$3.15 tab prints change `$0.00` with no sign the customer underpaid. The tender
screen gains a client-side warning. It does **not** block submission — a cashier who
has already counted the change must not be stopped by a field they skipped, and the
order settles in full either way.

## Deliberately still not done

- **Riel at the counter.** `admin_pay_cash.php` and `_cash_tender.php` have zero
  Riel support. This is not being added: the decision (2026-08-01) is to hide Riel
  as a separate method and record it as cash. Adding it here would build the thing
  that is about to be removed.
- **`admin_pay_bakong.php`'s GET write.** It opens a transaction on load
  (`:73`) but only persists the KHQR md5; settlement happens in `check_payment.php`
  once Bakong confirms. It is not a double-charge path and does not need the
  GET/POST split. Recorded so it is not "fixed" later.
- **Backfilling old receipts.** 328 of 362 historical cash rows have a blank
  reference — the tender was never captured. Inventing one would fabricate a record.

## Amended files

| File | Change |
|---|---|
| `_cash_tender.php` | amount received gains a `name`; underpayment warning; renders standalone or as a modal fragment |
| `admin_pay_cash.php` | stores the tender in `order_payments.reference`; serves the fragment |
| `find_order.php` | modal that fetches the partial |
| `receipt_pdf.php` | change block gated on a numeric reference, not on the method |
| `payment_cash.php` | same gate |
| `tests/counter_cash_test.php` | gate assertions |
