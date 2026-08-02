# Riel folded into the cash tender

**Date:** 2026-08-02
**Branch:** `feat/product-addons`
**Status:** design approved, not implemented

## Problem

Riel is a separate payment method. A customer who pays in riel is recorded as a
different kind of sale from one who pays in dollars, even though the same drawer
takes both. Meanwhile the cash tender screen is dollars-only, so a customer who
hands over riel — or, more commonly, a mix of dollars and riel — gets no change
calculation at all. The cashier does the arithmetic in their head, which is the
one thing the tender screen exists to prevent.

The counter settle path (`admin_pay_cash.php` / `_cash_tender.php`) has no riel
option whatsoever: zero references. A pay-later tab settled in riel has always
had to be booked as dollars.

## What we are building

Cash becomes a **two-currency tender**. The cash box on both tender screens takes
dollars received and riel received, sums them, and tells the cashier what to hand
back — split the way it physically happens, whole dollars plus riel under $1.

Riel stops being offered as its own method. It is **hidden, not deleted**: the
existing method, its four historical rows and their receipt handling all keep
working, and nothing new can create one.

## Scope decisions

Six decisions were taken during brainstorming, each with the alternative recorded
so a later reader knows what was weighed.

1. **Mixed tender, not one-currency-at-a-time.** A customer handing $5 and ៛2,000
   is the common Cambodian counter case; a currency toggle would leave the mental
   arithmetic in place. Riel-only is covered by leaving the dollar field at 0.
2. **Change splits into dollars + riel** — whole dollars in notes, the remainder
   under $1 in riel, because no US coins circulate here. The rejected alternative
   was showing "$3.66 or ៛15,000" and letting the cashier decide, which is the
   mental-arithmetic problem moved rather than solved.
3. **Both amounts stored in `order_payments.reference`** as a two-part string.
   The rejected alternative was storing one combined USD figure, under which a
   receipt reprints "$5.49 received" for a customer who handed $1 + ៛18,400 — the
   reprint would misdescribe what crossed the counter. A schema change was also
   rejected: `reference` already exists to hold exactly this.
4. **Checkout modal and counter settle get the field; split tenders do not.** The
   counter is the one place riel was outright impossible, so it is the point of
   the change. A split leg is already a fraction of a total; a second currency on
   top of that is where the arithmetic stops being checkable at a glance.
5. **`cart.php` loses its Riel tile and gets no riel field.** It is a duplicate
   checkout surface whose entry link was already dropped in `fec47c6`. Building a
   second riel UI there is investing in a page being retired. Named consequence:
   anyone still checking out from `cart.php` cannot take riel there.
6. **Riel is hidden, not retired.** Full retirement — deleting 84 references
   across 8 files and migrating the 4 historical rows — was designed, costed, and
   deferred as a standalone follow-up. See "Deferred" below.

## The tender model

One helper set in `config.php`, beside `order_payment_methods()`:

```php
tender_ref(float $usd, int $khr): string      // '' | '5.00' | '1.00|8000'
tender_parts(?string $ref): ?array            // ['usd'=>1.00,'khr'=>8000] | null
tender_usd_total(?string $ref): float         // usd + khr / KHR_RATE
tender_change(float $received_usd_total, float $owed): array
                                              // ['usd'=>3,'khr'=>2700,'short'=>false]
```

`tender_parts()` recognises exactly two shapes — `^\d+(\.\d+)?$` and
`^\d+(\.\d+)?\|\d+$` — and returns `null` for anything else. A Bakong transaction
id and an empty reference both fall through to `null`, so no reader can mistake
one for a tender.

`tender_ref()` emits the **bare number when riel is 0**. A dollars-only sale
therefore writes a value byte-identical to what it writes today, and all 191
existing rows keep printing exactly as they do now. The `|` form appears only
when riel is genuinely involved.

### Change split

```
change_usd = (usd + khr/RATE) - owed
if change_usd <= 0  → short; no split; existing non-blocking warning stands
dollars    = floor(change_usd)
riel       = round((change_usd - dollars) * RATE / 100) * 100
if riel >= RATE     → dollars += 1; riel = 0     // rounding filled a whole dollar
```

At the live rate of 4100, change of $3.66 gives **$3 + ៛2,700**. The ៛100
rounding means the cashier may hand back a few cents either side of exact; that
is already how the ៛ total behaves on every screen, and how the shop works.

`KHR_RATE` comes from the `khr_exchange_rate` setting (currently 4100, default
4100). The `settings.php` control for it **stays** — the rate is now load-bearing
for every cash sale, not just riel ones.

### Why a helper and not inline arithmetic

Without it this formula lands in five places: the checkout modal's JS, the counter
tender's JS, the counter POST handler, `receipt_pdf.php` and `payment_cash.php`.
The buy-X-get-1-free formula is duplicated in ten spots in this codebase and is a
standing hazard. One PHP implementation, one JS implementation, kept in step
deliberately — the same arrangement as `order_board_state()` / `boardState()`.

The JS lives in `assets/js/tender.js`, loaded by `<script src>` from `menu.php`,
`find_order.php` and `_cash_tender.php`'s standalone `<head>`, following the
`animations.js?v=<?= @filemtime(...) ?>` pattern `find_order.php:796` already
uses. The cache-buster matters: a stale cached money calculation is a bad failure.

**`tender.js` contains pure functions only — it binds no event listeners and
touches no DOM.** All wiring stays inline in the page or partial that owns the
markup. This matters because `find_order.php` injects `_cash_tender.php` via
`innerHTML` and re-executes its `<script>` tags by hand
(`find_order.php:673-676`). Inline wiring rides that mechanism, which already
works today; the shared arithmetic comes from a host-page `src` tag instead of
betting the change calculation on it. A shared file that attached listeners would
have to re-bind after injection and would silently fail to when it didn't — the
split keeps that failure mode out of reach.

The JS names mirror the PHP API exactly, so neither side invents its own
vocabulary for the same operation:

```js
tenderRef(usd, khr)                        // → '1.34' | '0|5500' | ''
tenderParts(ref)                           // → {usd: 1.34, khr: 0} | null
tenderUsdTotal(ref, rate)                  // → 1.34
tenderChange(receivedUsdTotal, owed, rate) // → {usd: 3, khr: 2700, short: false}
```

**The rate is a parameter on the JS side, not a constant.** `tender.js` is a
static file and is never parsed by PHP, so it cannot read `KHR_RATE`. Each host
page inlines the rate at render and passes it in: `menu.php:1378` already emits
`const CP_KHR_RATE = <?= (int)KHR_RATE ?>`, and `_cash_tender.php` — which today
inlines only `CP_OWED` — gains the same line.

On the PHP side the helper reads `KHR_RATE` directly. That constant is
`define()`d per request from the `khr_exchange_rate` setting
(`config.php:58`), and PHP holds no state across requests, so it cannot go stale
against a rate the admin changes mid-day: the next request re-reads it.

## Screens

### Checkout modal (`menu.php`)

Methods become Bakong / Cash / Later. The Riel tile is hidden behind the
`if (false)` + restore-comment pattern used for the bulk enable-sizes tool
(`products.php:1508`), at `menu.php:1290` and `cart.php:1358`.

The cash box gains a second field:

```
┌ Amount Received ─────────────────────────┐
│  Dollars  $ [        1.34 ]              │
│  [Exact] [$5] [$10] [$20]                │
│                                          │
│  Riel     ៛ [           0 ]  ≈ $0.00     │
│  [Exact ៛] [៛5,000] [៛10,000] [៛20,000]  │
│  ───────────────────────────────────────  │
│  Change to give back      $3 + ៛2,700    │
└──────────────────────────────────────────┘
```

Riel quick-buttons follow the rule the dollar buttons already use — only notes
that could plausibly cover the bill, capped at four. The `≈ $x.xx` readout under
the riel field lets the cashier sanity-check the conversion.

**The prefill trap.** The dollar field is pre-seeded with the exact total so
one-tap exact cash stays one tap. With a second field that seed becomes
dangerous: a prefilled **$1.34** plus a typed **៛5,500** reads as $2.68 received
on a $1.34 order, and the screen would confidently display change that was never
owed. Rule: **the first real keystroke in the riel field clears an untouched
dollar prefill.** The existing `dataset.touched` flag already distinguishes a
prefill from a real entry — it exists for precisely this — and just needs reading
from the other field.

Short tender stays **non-blocking**, with the existing amber warning; the order
settles in full either way. That is deliberate (`_cash_tender.php:236`) and does
not change.

On submit the cash leg posts `payment_amounts[]` = the order total as it does
today, and `payment_references[]` = `tender_ref(usd, khr)`. `confirm_order.php`
re-parses and re-emits that through `tender_ref()` before insert, so a
hand-crafted POST cannot store a malformed reference — the same guard pattern as
`f5aea86`.

### Counter settle (`_cash_tender.php` + `admin_pay_cash.php`)

Same two-field box, same helper, same split. The form gains `cash_received_khr`
alongside `cash_received`; both are re-emitted through `tender_ref()` server-side
before the UPDATE so the stored string is always canonical.

The `$rowCount === 1` restriction and the `amount` sync are **untouched**. That
sync is the `143fa32` fix — change is measured against `orders.total`, never
against a pay-later payment row that records the tab's opening total and is never
updated as items are added.

## Two defects this exposes in existing code

Both are latent today and become live the moment dollars and riel are separate
fields. **Both ship in the same commit as the two-field UI.** Shipping the UI
without them is a regression, not a follow-up.

**1. `received === 0` no longer means "nothing tendered."**

```js
// menu.php:2245 and _cash_tender.php:240 — the same line in both.
// JavaScript, inside a <script> block, four lines under
//   var received = parseFloat(document.getElementById('cpCashReceived')?.value) || 0;
if (received === 0) { el.textContent = '$0.00'; el.className = 'change-amount'; return; }
```

That guard means "no money entered yet". Once the fields are separate, zero
dollars is the *normal riel-only case*. Left as-is, a cashier who types ៛5,500 on
a $1.34 order sees the change line sit at **$0.00** and hands back nothing —
wrong on screen, right in the database, which is the worst combination.

The fix is arithmetic on two floats, not string parsing — `received` is a float
straight from the dollar field, and no reference string exists until submit:

```js
var totalUsd = usd + khr / CP_KHR_RATE;
if (totalUsd === 0) { el.textContent = '$0.00'; el.className = 'change-amount'; return; }
```

`tender_usd_total()` is the PHP-side reader for a stored reference and is *not*
callable here. The two sites are JS.

**2. `admin_pay_cash.php:146` would silently drop a riel-only reference.**

```php
if (is_numeric($tender) && (float)$tender > 0) { /* write reference */ }
```

`"0|5500"` is not `is_numeric`, so a riel-only settlement would write **no
reference at all** — no error — and the receipt would reprint with no
Received/Change lines, which is exactly the gap this block was added to close.
This one *is* PHP:

```php
$parts = tender_parts($tender);
if ($parts !== null && tender_usd_total($tender) > 0) { /* write reference */ }
```

Writes `0|5500` for a riel-only tender; skips for an empty reference or a Bakong
transaction id.

## Readers

Every reader swaps `is_numeric($ref)` for `tender_parts($ref)`. The surrounding
logic is unchanged.

- **`receipt_pdf.php:534`** — `$tendered_usd` comes from `tender_usd_total()`.
  Received prints what actually crossed the counter: `$1.00 + ៛8,000` when both,
  `៛5,500` when riel-only, `$5.00` when dollars-only (identical to today).
  Change prints the split. The riel branch and the `'riel' => 'Riel (KHR)'` label
  **stay** — four rows still use them.
  `$owed_for_change = count($payments) === 1 ? $stored_total : $pay_amount` is
  untouched.
- **`payment_cash.php:593`** — same one-line swap. Its comment ("Bakong cannot
  misfire: its reference is never numeric") stays true and gets stronger:
  `tender_parts()` accepts two exact shapes where `is_numeric` would have taken a
  bare `"22000"` from anywhere.
- **Reports need nothing.** `daily_report.php` already treats riel as an
  unenumerated remainder alongside `payment_method='0'` (`:240`, `:321`), and
  `shift_report.php:137` keeps its label. Riel remains a live method, so both
  stay accurate.

## Testing

**Unit — `tests/tender_test.php`**, following `tests/purchase_order_test.php`.
The helper is pure, so these need no database and cannot touch order numbering.

| Case | Expect |
|---|---|
| `tender_ref(1.34, 0)` | `'1.34'` — legacy shape preserved |
| `tender_ref(0, 5500)` | `'0\|5500'` |
| `tender_ref(1.00, 8000)` | `'1.00\|8000'` |
| `tender_ref(0, 0)` | `''` |
| `tender_parts('5.00')` | `['usd'=>5.0,'khr'=>0]` — the 191 existing rows |
| `tender_parts('0\|5500')` | `['usd'=>0.0,'khr'=>5500]` |
| `tender_parts('KHQR9F2A…')` | `null` — a Bakong txn id is not a tender |
| `tender_parts('')` | `null` |
| `tender_parts('0')` | `['usd'=>0.0,'khr'=>0]` — a zero tender is still a tender; `is_numeric` took it too, so no behaviour change |
| `tender_change(5.00, 1.34)` | `$3 + ៛2,700` |
| `tender_change(5.33, 1.34)` | `$4 + ៛0` — the rounding carry |
| `tender_change(5.34, 1.34)` | `$4 + ៛0` — exact dollar, remainder 0 |
| `tender_change(1.00, 1.34)` | short, no split |

The carry cases are worth stating plainly. A remainder of $0.9998 converts to
៛4,099, which rounds to ៛4,100 — exactly one dollar at the live rate — so the
`riel >= RATE` check promotes it to a dollar bill rather than handing back 4,100
riel in small notes. A remainder of exactly zero produces no riel line at all.
Note that `tender_ref(0, 0)` returns `''` while `tender_parts('0')` returns
zeros: the writer never emits `'0'`, but the reader accepts it, because legacy
rows may hold it.

**Browser — Playwright MCP**, entering at `http://localhost/Cafe/`.

1. **Riel-only checkout.** Order $1.34. Dollar field untouched, ៛5,500 entered.
   Screen reads **Change $0 + ៛0**; submit succeeds; `reference` stores `0|5500`;
   receipt reprints "Received ៛5,500", not "$5,500". *Raised in review as the case
   most likely to be wrongly rejected. Verified against the code that nothing
   rejects a zero-dollar cash leg: `payment_amounts[]` carries the order total,
   never the tender, so the only amount check (`confirm_order.php:336`) compares
   1.34 to 1.34 and passes; `menu.php:2361` writes no reference at zero rather
   than blocking; `cpCashReceived` has no `required`; `admin_pay_cash.php:133`
   takes the value raw. The risk was never rejection — it was defect 1 above.*
2. **Mixed tender.** $1 + ៛8,000 on a $1.34 order; change splits correctly.
3. **Dollars-only regression.** Reference written is byte-identical to today's.
4. **Prefill clear.** Typing in the riel field clears an untouched $1.34.
5. **Counter riel-only settlement** of a pay-later tab; reference and receipt
   lines both present.
6. **Legacy receipt reprint** — an untouched existing row renders unchanged.

**Two hazards to respect while testing:**

- **Order numbering.** Drive test orders through the UI, which takes a legitimate
  next `daily_order_no`. Never `INSERT` a scratch order dated today: that poisons
  the customer-facing sequence permanently, and deleting the row afterwards does
  not undo it.
- **Cleanup is a refund, not a cancel.** `3a0a794` made `Paid` non-cancellable on
  purpose. A test order settled in cash must be refunded. That is correct
  behaviour, not an obstacle to work around.

## Deferred: full Riel retirement

Designed and costed, held back as a standalone follow-up commit. Nothing here
blocks it.

The work would be: delete 84 riel references across 8 files (`menu.php` 36,
`cart.php` 48 markup/JS/CSS, `confirm_order.php`'s can't-combine `die()` at `:89`
and amount override at `:327`, `receipt_pdf.php`'s branch and label,
`shift_report.php:137`); drop `'riel'` from `order_payment_methods()`; and
migrate four rows via `_migrate($conn, 'riel_folded_into_cash_v1', ...)`,
rewriting `reference` and `payment_method` **in one statement** so no row is ever
`cash` with a raw KHR reference.

The four rows, verified live 2026-08-02:

| payment_id | order_id | amount | reference (KHR) | order total | business_date |
|---|---|---|---|---|---|
| 284 | 1498 | $1.65 | ៛6,765 | $1.65 | 2026-06-02 |
| 291 | 1504 | $3.85 | ៛20,000 | $3.85 | 2026-06-02 |
| 320 | 1529 | $4.40 | ៛20,000 | $4.40 | 2026-06-03 |
| 470 | 1669 | $3.01 | ៛15,000 | $3.01 | 2026-06-15 |

Three are genuine tenders with change owed. Row 284's ៛6,765 is exactly
`1.65 × 4100`, an exact-conversion seed rather than a handover; it converts the
same way regardless.

**Why it is deferred, and why hiding is the safer half:** dropping `riel` from
`order_payment_methods()` makes `order_payment_method_or()` coerce a stray riel
POST to `cash` — while its raw KHR reference tags along and is then read as
dollars. A ៛22,000 tender would print as **$22,000 received**. `cart.php` can
still produce such a POST. Keeping `riel` a known method makes that failure
impossible. The migration is also the only irreversible step in the whole design,
and it must be preceded by a `mysqldump` of those 8 rows to
`riel_rows_before_fold.sql` — the precedent being
`orders_backup_before_datefix.sql`. Rollback is the four `payment_id`s and four
`order_id`s recorded above.

## Related

- `docs/superpowers/specs/2026-08-01-counter-cash-tender-design.md` — the tender
  screen this extends
- Memory: `riel-into-cash-decision`, `scratch-order-numbering-hazard`,
  `revenue-paid-recognition`, `legacy-order-data-caveats`
