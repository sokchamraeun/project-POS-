// CLI assertions for the browser-side tender helpers.
// Run:  node tests/tender.test.mjs
// tender.js is a plain script (no modules) so the browser can load it with a
// bare <script src>; this harness evaluates it the same way.
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const src  = readFileSync(join(here, '..', 'tender.js'), 'utf8');
// Only the pure functions are exposed here. The DOM helpers need a document and
// are verified in the browser (Task 8) rather than against a shim that would
// prove nothing about the real page.
const ctx  = {};
new Function('globalThis', src + '\nglobalThis.tenderRef=tenderRef;'
  + 'globalThis.tenderParts=tenderParts;globalThis.tenderUsdTotal=tenderUsdTotal;'
  + 'globalThis.tenderChange=tenderChange;globalThis.tenderChangeText=tenderChangeText;')(ctx);

let failures = 0;
function check(what, got, want) {
  const ok = JSON.stringify(got) === JSON.stringify(want);
  if (ok) { console.log(`  PASS  ${what}`); return; }
  failures++;
  console.log(`  FAIL  ${what}\n        got:  ${JSON.stringify(got)}\n        want: ${JSON.stringify(want)}`);
}

const RATE = 4100;

console.log('tenderRef');
check('dollars only stays a bare number', ctx.tenderRef(1.34, 0),    '1.34');
check('riel only',                        ctx.tenderRef(0, 5500),    '0.00|5500');
check('both currencies',                  ctx.tenderRef(1.00, 8000), '1.00|8000');
check('nothing tendered is empty',        ctx.tenderRef(0, 0),       '');

console.log('tenderParts');
check('legacy bare number reads as USD', ctx.tenderParts('5.00'),      {usd:5,   khr:0});
check('two-part reads both',             ctx.tenderParts('0.00|5500'), {usd:0,   khr:5500});
check('bakong txn id is not a tender',   ctx.tenderParts('KHQR9F2A1B'), null);
check('empty is not a tender',           ctx.tenderParts(''),          null);

console.log('tenderUsdTotal');
check('riel only', Math.round(ctx.tenderUsdTotal('0.00|4100', RATE) * 100) / 100, 1);
check('both',      Math.round(ctx.tenderUsdTotal('1.00|4100', RATE) * 100) / 100, 2);

console.log('tenderChange');
// Must agree with the PHP twin exactly — the two are kept in step by hand.
check('change splits into whole dollars', ctx.tenderChange(5.00, 1.34, RATE), {usd:3, khr:2700, short:false});
check('rounding carry promotes a dollar', ctx.tenderChange(5.33, 1.34, RATE), {usd:4, khr:0,    short:false});
check('exact tender',                     ctx.tenderChange(1.34, 1.34, RATE), {usd:0, khr:0,    short:false});
check('short tender',                     ctx.tenderChange(1.00, 1.34, RATE), {usd:0, khr:0,    short:true});
// The riel-only case the whole feature exists for.
check('riel-only exact payment', ctx.tenderChange(ctx.tenderUsdTotal('0.00|5500', RATE), 1.34, RATE),
      {usd:0, khr:0, short:false});

console.log('tenderChangeText');
check('dollars and riel',  ctx.tenderChangeText({usd:3, khr:2700, short:false}, 5.00, 1.34), '$3 + ៛2,700');
check('riel only',         ctx.tenderChangeText({usd:0, khr:2700, short:false}, 2.00, 1.34), '៛2,700');
check('dollars only',      ctx.tenderChangeText({usd:4, khr:0,    short:false}, 5.34, 1.34), '$4.00');
check('nothing to give back', ctx.tenderChangeText({usd:0, khr:0, short:false}, 1.34, 1.34), '$0.00');
check('short says what is missing', ctx.tenderChangeText({usd:0, khr:0, short:true}, 1.00, 1.34), 'Need $0.34 more');

console.log(failures === 0 ? '\nALL PASS' : `\n${failures} FAILURE(S)`);
process.exit(failures === 0 ? 0 : 1);
