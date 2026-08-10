/* Two-currency cash tender — the browser twin of the PHP helpers in config.php.
 *
 * NOTHING BINDS ON LOAD. This file defines functions and does nothing else. The
 * DOM helpers below attach listeners only when a host page CALLS them, after
 * that page's markup exists. find_order.php injects _cash_tender.php with
 * innerHTML and re-executes its <script> tags by hand, so anything that bound
 * at load time would bind against markup that is not there yet and silently do
 * nothing. Each page owns the calls; this file owns the logic.
 *
 * The exchange rate is a PARAMETER, not a constant. This file is static and is
 * never parsed by PHP, so it cannot read KHR_RATE. Each host page inlines the
 * rate at render (menu.php emits const CP_KHR_RATE) and passes it in.
 *
 * Keep in step with config.php's tender_ref / tender_parts / tender_usd_total /
 * tender_is_riel_only / tender_change. Same names, same rules, same rounding.
 */

function tenderRef(usd, khr) {
  usd = Math.max(0, Number(usd) || 0);
  khr = Math.max(0, Math.round(Number(khr) || 0));
  if (usd <= 0 && khr <= 0) { return ''; }
  if (khr <= 0) { return usd.toFixed(2); }
  return usd.toFixed(2) + '|' + khr;
}

function tenderParts(ref) {
  ref = String(ref == null ? '' : ref).trim();
  if (ref === '') { return null; }
  var one = /^(\d+(?:\.\d+)?)$/.exec(ref);
  if (one) { return { usd: parseFloat(one[1]), khr: 0 }; }
  var two = /^(\d+(?:\.\d+)?)\|(\d+)$/.exec(ref);
  if (two) { return { usd: parseFloat(two[1]), khr: parseInt(two[2], 10) }; }
  return null;
}

function tenderUsdTotal(ref, rate) {
  var p = tenderParts(ref);
  if (p === null) { return 0; }
  return p.usd + (p.khr / rate);
}

/* Did the customer pay entirely in riel? The twin of config.php's
   tender_is_riel_only(). One definition, because tenderChange() branches on it
   and the screen and the receipt must answer it the same way — the last time a
   rule like this was spelled out twice they drifted and one sale read $4.00 on
   screen and $3.99 on paper.

   Zero dollars AND positive riel. A zero tender is not riel-only: nothing was
   handed over, so nothing was handed over in riel. */
function tenderIsRielOnly(parts) {
  if (!parts) { return false; }
  return (Number(parts.usd) || 0) <= 0 && (Number(parts.khr) || 0) > 0;
}

/* FOLLOW THE CURRENCY (allRiel). A customer who paid entirely in riel gets
   change entirely in riel. Handing back dollars converts currency on them at
   the shop's rate without being asked; a shop gives back what it was given.
   Every other tender — dollars only, or dollars and riel mixed — keeps the
   dollars-first split, because dollars were in the exchange.

   NO CARRY on the riel-only path, deliberately: the carry below promotes a
   full-dollar riel remainder to a dollar note, which is precisely the thing
   this rule forbids. Keep in step with config.php tender_change(). */
function tenderChange(receivedUsdTotal, owed, rate, allRiel) {
  var change = Math.round((receivedUsdTotal - owed) * 10000) / 10000;
  if (change <= 0) { return { usd: 0, khr: 0, short: change < 0 }; }
  if (allRiel) {
    return { usd: 0, khr: Math.round((change * rate) / 100) * 100, short: false };
  }
  var dollars = Math.floor(change);
  var riel    = Math.round(((change - dollars) * rate) / 100) * 100;
  if (riel >= rate) { dollars += 1; riel = 0; }
  return { usd: dollars, khr: riel, short: false };
}

/* What the change line reads. Shared so the checkout modal and the counter
   screen cannot drift apart — they must agree to the cent. */
function tenderChangeText(ch, received, owed) {
  if (ch.short) { return 'Need $' + (owed - received).toFixed(2) + ' more'; }
  var r = (typeof window !== 'undefined' && window.CP_KHR_RATE) ? window.CP_KHR_RATE : 4100;
  var changeUsd = Math.max(0, received - owed);
  var changeKhr = Math.round((changeUsd * r) / 100) * 100;

  if (changeUsd <= 0) return '$0.00';

  if (ch.usd > 0 && ch.khr > 0) {
    return '$' + ch.usd + ' + ៛' + ch.khr.toLocaleString();
  }

  return '$' + changeUsd.toFixed(2) + ' or ៛' + changeKhr.toLocaleString();
}

/* ── DOM helpers ──────────────────────────────────────────────────────────
   Shared so menu.php and _cash_tender.php do not each carry a copy. Called by
   the host page after its markup exists; never bound on load. */

function tenderCashReceivedUsd(usdId, khrId, rate) {
  var usd = parseFloat((document.getElementById(usdId) || {}).value) || 0;
  var khr = parseFloat((document.getElementById(khrId) || {}).value) || 0;
  return Math.max(0, usd) + Math.max(0, khr) / rate;
}

function tenderFieldsRielOnly(usdId, khrId) {
  var usd = parseFloat((document.getElementById(usdId) || {}).value) || 0;
  var khr = parseFloat((document.getElementById(khrId) || {}).value) || 0;
  usd = Math.round(Math.max(0, usd) * 100) / 100;
  return tenderIsRielOnly({ usd: usd, khr: Math.max(0, khr) });
}

function tenderOnRielInput(usdId, khrId, eqId, rate) {
  var ri  = document.getElementById(khrId);
  var cr  = document.getElementById(usdId);
  var khr = Math.max(0, parseFloat(ri ? ri.value : 0) || 0);
  if (cr && khr > 0) { cr.value = ''; delete cr.dataset.touched; }
  var eq = document.getElementById(eqId);
  if (eq) { eq.textContent = '≈ $' + (khr / rate).toFixed(2); }
}

/* Riel notes that could plausibly cover the bill, capped at four — the same
   rule the dollar buttons already use. onPick runs after the value is set so
   the host can recalculate its own change line. */
function tenderRenderRielQuick(wrapId, khrId, owed, rate, onPick) {
  var wrap = document.getElementById(wrapId);
  if (!wrap) { return; }
  wrap.innerHTML = '';
  if (owed <= 0) { return; }
  var owedKhr = Math.round(owed * rate / 100) * 100;

  var mk = function (label, val) {
    var b = document.createElement('button');
    b.type = 'button';          // a bare <button> in a form submits it
    b.className = 'cp-tender-btn';
    b.textContent = label;
    b.addEventListener('click', function () {
      var ri = document.getElementById(khrId);
      if (!ri) { return; }
      ri.value = val;
      if (typeof onPick === 'function') { onPick(); }
    });
    return b;
  };

  wrap.appendChild(mk('Exact ៛', owedKhr));
  [5000, 10000, 20000, 50000, 100000]
    .filter(function (n) { return n > owedKhr; })
    .slice(0, 4)
    .forEach(function (n) { wrap.appendChild(mk('៛' + n.toLocaleString(), n)); });
}
