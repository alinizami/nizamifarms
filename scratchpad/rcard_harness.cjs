// A2 / C14 harness: drives the vendor page's REAL scan-card script (rcard.js, cut from the page
// rendered through the Laravel kernel) against a small fake DOM. Extract/products are faked —
// no model call of any kind.
const fs = require('fs');
const vm = require('vm');
const code = fs.readFileSync(__dirname + '/rcard.js', 'utf8');
let pass = 0, fail = 0;
const ok = (w, c, x) => { if (c) { pass++; console.log('  ✓ ' + w); } else { fail++; console.log('  ✗ ' + w + (x !== undefined ? '  → ' + JSON.stringify(x) : '')); } };

// ── a fake element whose children are found by parsing its innerHTML ────────────────────
function closeOf(html, openIdx) {           // index just after the </div> matching the <div at openIdx
  let depth = 0, i = openIdx;
  const re = /<div\b|<\/div>/g; re.lastIndex = openIdx;
  let m;
  while ((m = re.exec(html))) { depth += m[0] === '</div>' ? -1 : 1; if (depth === 0) { return re.lastIndex; } }
  return html.length;
}
class Box {
  constructor(id) { this.id = id; this._html = ''; this.gen = 0; this.memo = {}; this.style = {}; this.value = ''; this.textContent = ''; this.files = null; this.disabled = false; }
  set innerHTML(h) { this._html = h; this.gen++; this.memo = {}; }
  get innerHTML() { return this._html; }
  tags(cls) {
    const out = [];
    const re = /<(\w+)\s([^>]*)>/g; let m;
    while ((m = re.exec(this._html))) {
      const attrs = {}; m[2].replace(/([\w-]+)="([^"]*)"/g, (_, k, v) => { attrs[k] = v; return ''; });
      if ((' ' + (attrs.class || '') + ' ').includes(' ' + cls + ' ')) { out.push({tag: m[1], attrs, at: m.index}); }
    }
    return out;
  }
  el(cls, t) {
    const key = cls + '|' + t.attrs['data-i'] + '|' + t.at;
    if (!this.memo[key]) {
      const box = this;
      this.memo[key] = {
        value: t.attrs.value ?? '', checked: false, disabled: false, textContent: '', style: {},
        getAttribute: n => t.attrs[n] ?? null,
        get innerHTML() { const s = box._html.indexOf('>', t.at) + 1; return box._html.slice(s, closeOf(box._html, t.at) - 6); },
        set innerHTML(h) { const s = box._html.indexOf('>', t.at) + 1; const e = closeOf(box._html, t.at) - 6; box._html = box._html.slice(0, s) + h + box._html.slice(e); box.memo = {}; },
        querySelector: () => null,
      };
    }
    return this.memo[key];
  }
  querySelectorAll(sel) { const cls = sel.replace(/^\./, ''); return this.tags(cls).map(t => this.el(cls, t)); }
  querySelector(sel) {
    const m = sel.match(/^\.([\w-]+)\[data-i="(\d+)"\]$/);
    if (!m) { return this.querySelectorAll(sel)[0] || null; }
    const t = this.tags(m[1]).find(x => x.attrs['data-i'] === m[2]);
    return t ? this.el(m[1], t) : null;
  }
}
const els = {};
const byId = id => (els[id] = els[id] || new Box(id));

const SALT = {id: 77, product_name: 'Salt', unit: 'kg', pack_qty_base: '1000.000', ingredient_id: 33, ingredient_name: 'Salt', ingredient_base_unit: 'g'};
const MAIDA = {id: 78, product_name: 'Maida', unit: 'kg', pack_qty_base: '1000.000', ingredient_id: 34, ingredient_name: 'Maida', ingredient_base_unit: 'g'};
const CARD = {
  lines: [{raw_name: 'NATIONAL NAMAK 800G', qty: 3, unit_price: 100, line_total: 270, discount: 30, discount_applied: true,
           sold_by: 'pack', pack_size_value: 800, pack_size_unit: 'g', product_id: null, matched: false,
           ai_suggestion: {id: 77, name: 'Salt', unit: 'kg', rate: 100}, ai_ingredient: null, suggestions: []}],
  grand_total: 270, discount_adjustment: -30, warnings: [], store_name: 'TEST',
};
const pending = [];
const fetchFn = url => new Promise(resolve => pending.push({url, resolve: b => resolve({json: async () => b})}));
const ctx = {
  document: {getElementById: byId, querySelector: () => ({getAttribute: () => 'csrf'})},
  window: {}, fetch: fetchFn, FormData: class { append() {} }, alert: m => { throw new Error('alert: ' + m); },
  confirm: () => true, console, Math, Number, String, JSON, isFinite, parseFloat,
};
ctx.window = ctx;
vm.createContext(ctx);
vm.runInContext(code, ctx);
const tick = () => new Promise(r => setImmediate(r));
const take = re => { const i = pending.findIndex(p => re.test(p.url)); if (i < 0) { throw new Error('no pending ' + re + ' in ' + pending.map(p => p.url)); } return pending.splice(i, 1)[0]; };
const L = () => byId('rcLines');
const q = sel => L().querySelector(sel);
const hint = () => (q('.rc-hintbox[data-i="0"]') || {}).innerHTML || '';
const ing = () => (q('.rc-ingline[data-i="0"]') || {}).innerHTML || '';
const type = (cls, v) => { const el = q('.' + cls + '[data-i="0"]'); el.value = v; el.oninput(); };

(async () => {
  ctx.rcOpen();
  take(/products/).resolve({success: true, products: [SALT, MAIDA], supports_ingredients: false});
  await tick();
  byId('rcFile').files = [{name: 'bill.jpg'}];
  byId('rcFile').onchange.call(byId('rcFile'));
  take(/extract/).resolve({success: true, draft_id: 1, card: JSON.parse(JSON.stringify(CARD))});
  await tick(); await tick();
  ok('the card shows the reader\'s suggestion, not applied', hint().includes('Looks like <b>Salt</b>'));
  ok('the discount starts in the adjustment', byId('rcAdjust').value === '-30');

  q('.rc-ai-yes[data-i="0"]').onclick();
  ok('after "Yes, use it": ⚖ 3 × 800 g = 2.4 kg is offered', hint().includes('The bill has 3 × 800 g = 2.4 kg of Salt.'));
  ok('A2: the offer keeps qty × PRINTED price (Rs 125/kg), not the discounted total (Rs 112.5)', hint().includes('data-rate="125"'), hint());
  ok('"· 3 kg" beside the ingredient', ing() === 'Salt · 3 kg', ing());

  type('rc-qty', '2');
  ok('C14: typing a quantity hides the offer (no "2 × 800 g")', !hint().includes('rc-fit-use') && !hint().includes('2 × 800'), hint());
  ok('C14: "· 2 kg" follows the typed quantity', ing() === 'Salt · 2 kg', ing());
  type('rc-qty', '3');
  ok('typing the read quantity back brings the offer back', hint().includes('rc-fit-use'));

  type('rc-rate', '110');                       // a misread price corrected by hand
  q('.rc-fit-use[data-i="0"]').onclick();       // the button was drawn before the rate changed
  const conv = byId('rcCard') && JSON.parse(JSON.stringify({t: byId('rcLinesTotal').textContent, g: byId('rcGrand').textContent}));
  ok('the ⚖ button re-works the numbers from the line as it is now (3 × 110 = Rs 330 kept)', conv.t === 'Rs 330', conv);
  type('rc-rate', '100');

  // fresh card: the A2 money on record
  ctx.rcOpen();
  byId('rcFile').onchange.call(byId('rcFile'));
  take(/extract/).resolve({success: true, draft_id: 2, card: JSON.parse(JSON.stringify(CARD))});
  await tick(); await tick();
  q('.rc-ai-yes[data-i="0"]').onclick();
  q('.rc-fit-use[data-i="0"]').onclick();
  ok('A2: converted, the lines add up to Rs 300 and the bill to Rs 270 (= the paper)',
    byId('rcLinesTotal').textContent === 'Rs 300' && byId('rcGrand').textContent === 'Rs 270', [byId('rcLinesTotal').textContent, byId('rcGrand').textContent]);
  ok('…"· 2.4 kg" after the conversion', ing() === 'Salt · 2.4 kg', ing());
  type('rc-qty', '2.5');                        // he weighed it himself
  const sel = q('.rc-prod[data-i="0"]'); sel.value = '78'; sel.onchange();
  const qtyEl = q('.rc-qty[data-i="0"]'), rateEl = q('.rc-rate[data-i="0"]');
  ok('C14: another product keeps the quantity typed after the conversion (2.5, not 3)', qtyEl.value === '2.5' && rateEl.value === '125', [qtyEl.value, rateEl.value]);
  ok('…with "· 2.5 kg" for the new product', ing() === 'Maida · 2.5 kg', ing());
  ok('…and no fresh offer computed from the typed 2.5', !hint().includes('rc-fit-use'));

  // untouched conversion + another product → the printed numbers come back
  ctx.rcOpen();
  byId('rcFile').onchange.call(byId('rcFile'));
  take(/extract/).resolve({success: true, draft_id: 3, card: JSON.parse(JSON.stringify(CARD))});
  await tick(); await tick();
  q('.rc-ai-yes[data-i="0"]').onclick();
  q('.rc-fit-use[data-i="0"]').onclick();
  const sel2 = q('.rc-prod[data-i="0"]'); sel2.value = '78'; sel2.onchange();
  ok('an untouched conversion is put back to the printed 3 × 100 on another product',
    q('.rc-qty[data-i="0"]').value === '3' && q('.rc-rate[data-i="0"]').value === '100', [q('.rc-qty[data-i="0"]').value, q('.rc-rate[data-i="0"]').value]);

  console.log(`\n${fail === 0 ? '✅' : '❌'}  ${pass} passed, ${fail} failed`);
  process.exit(fail === 0 ? 0 : 1);
})().catch(e => { console.log('💥', e.stack); process.exit(1); });
