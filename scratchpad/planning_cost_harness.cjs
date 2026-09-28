// Sep-27-2026 — the web Planning page's REAL recipe-editor script, driven in a vm with the
// REAL server replies (saved by verify_costing_http.php):
//   1. opening Chicken Cheese Samosa prices it at today's prices: Rs 1,395 a batch, Rs 139.50 a pack
//   2. typing a new amount re-prices live (2 kg chicken → Rs 2,145 · Rs 214.50)
//   3. unpriced lines are NAMED with their reason, never counted as zero; old price flagged
//   4. Qasim (no view_khaas_costing) sees "✓ priced" and the names — no rupee figure at all
//   5. "Change how it is measured…" asks the impact, refuses half-typed / fractional pieces,
//      then posts the typed amounts to change-unit
// Usage: node scratchpad/planning_cost_harness.cjs
const fs = require('fs'), vm = require('vm'), path = require('path');
const dir = __dirname;
const code = fs.readFileSync(path.join(dir, 'planning_page_script.js'), 'utf8');
const TAIMUR = JSON.parse(fs.readFileSync(path.join(dir, 'recipe_1313_taimur.json'), 'utf8'));
const QASIM = JSON.parse(fs.readFileSync(path.join(dir, 'recipe_1313_qasim.json'), 'utf8'));
const INGS = JSON.parse(fs.readFileSync(path.join(dir, 'ingredients_bu2.json'), 'utf8'));
let pass = 0, fail = 0;
const ok = (n, c, x) => { if (c) { pass++; console.log('  PASS ' + n); } else { fail++; console.log('  FAIL ' + n + (x !== undefined ? '  -> ' + JSON.stringify(x).slice(0, 400) : '')); } };

function run(recipeReply) {
  const typed = {};
  const kids = {};   // "<parentId>|<class>" → persistent child stubs
  function child(parent, cls, i, attrs) {
    const key = parent.id + '|' + cls + '|' + i;
    if (!kids[key]) {
      kids[key] = {cls, value: attrs.value ?? '', textContent: '', innerHTML: '', title: '', style: {}, disabled: false,
        _attrs: attrs, getAttribute(a) { return this._attrs[a]; }, focus() {}};
    } else {
      Object.assign(kids[key]._attrs, attrs);
    }
    return kids[key];
  }
  function mkEl(id) {
    const el = {
      id, value: '', textContent: '', innerHTML: '', disabled: false, style: {}, dataset: {},
      classList: {_s: new Set(['hidden']), add(c) { this._s.add(c); }, remove(c) { this._s.delete(c); },
        contains(c) { return this._s.has(c); }, toggle(c, on) { if (on === undefined) { on = !this._s.has(c); } on ? this._s.add(c) : this._s.delete(c); return on; }},
      appendChild() {}, focus() {}, dispatchEvent(ev) { if (ev && ev.type === 'change' && el.onchange) { el.onchange.call(el); } },
      getAttribute() { return null; }, setAttribute() {},
      querySelectorAll(sel) {
        const cls = sel.replace(/^\./, '').split(',')[0].trim().replace(/^\./, '');
        const out = [];
        // every element in innerHTML whose class list starts with cls, in order
        const re = new RegExp('<(\\w+)[^>]*class="' + cls + '(?:\\s[^"]*)?"([^>]*)>', 'g');
        let m, i = 0;
        const full = el.innerHTML;
        const re2 = new RegExp('class="(?:[^"]*\\s)?' + cls + '(?:\\s[^"]*)?"', 'g');
        while ((m = re2.exec(full))) {
          // attributes after the class attribute (data-i / data-id / data-to / value)
          const tail = full.slice(m.index, full.indexOf('>', m.index));
          const attrs = {};
          tail.replace(/(data-[\w-]+|value)="([^"]*)"/g, (_, k, v) => { attrs[k] = v; });
          const c = child(el, cls, i, attrs);
          if (attrs['data-id'] && typed[attrs['data-id']] !== undefined) { c.value = typed[attrs['data-id']]; }
          out.push(c);
          i++;
        }
        return out;
      },
    };
    return el;
  }
  const els = {};
  const document = {
    getElementById(id) { if (!els[id]) { els[id] = mkEl(id); } return els[id]; },
    querySelector() { return {getAttribute: () => 'csrf'}; },
    querySelectorAll() { return []; },
    createElement() { return {value: '', textContent: ''}; },
    addEventListener() {},
  };
  const fetches = [];
  let impactReply = null, changeReply = {success: true, message: 'Chicken Cubes is now counted in weight everywhere.'};
  const fetch = (url, opts) => {
    fetches.push({url, opts});
    let r = {success: true};
    if (/\/khaas\/ingredients\?/.test(url)) { r = INGS; }
    else if (/recipe\/coverage/.test(url)) { r = {success: true, products: [{product_id: 1313, product_name: 'NF – Chicken Cheese Samosa (8 pcs)', has_recipe: true}]}; }
    else if (/\/khaas\/recipe\?/.test(url)) { r = recipeReply; }
    else if (/unit-impact/.test(url)) { r = impactReply; }
    else if (/change-unit/.test(url)) { r = changeReply; }
    return Promise.resolve({json: () => Promise.resolve(r)});
  };
  const alerts = [];
  const ctx = {document, fetch, console, window: {location: {href: ''}}, setTimeout: () => 0, alert: m => alerts.push(m),
    confirm: () => true, prompt: () => null, Event: function (t) { this.type = t; }, Number, String, JSON, Math, Object, Array,
    encodeURIComponent, parseInt, parseFloat, isFinite};
  vm.createContext(ctx);
  vm.runInContext(code, ctx);
  return {els, fetches, alerts, typed, ctx, setImpact: r => { impactReply = r; }, setChange: r => { changeReply = r; }};
}
const flush = () => new Promise(r => setImmediate(r));
const costSpans = h => h.els.recLines.querySelectorAll('.rec-cost');

(async () => {
  console.log('=== 1 · Taimur opens Chicken Cheese Samosa ===');
  const T = run(TAIMUR);
  await flush(); await flush();
  T.els.recProduct.value = '1313';
  T.els.recProduct.onchange.call(T.els.recProduct);
  await flush(); await flush();
  const box = T.els.recCost.innerHTML;
  ok('cost box shown', !T.els.recCost.classList.contains('hidden'));
  ok('Rs 1,395 a batch · Rs 139.50 a pack', box.includes('Rs 1,395 a batch') && box.includes('Rs 139.50 a pack'), box);
  ok('26.8% of the Rs 520 price', box.includes('26.8% of the Rs 520 price'), box);
  const spans = costSpans(T);
  ok('one cost cell per line (11)', spans.length === 11, spans.length);
  const chicken = spans[TAIMUR.recipe.lines.findIndex(l => l.ingredient_id === 1)];
  ok('chicken line: Rs 750 · Rs 75.00/pack', chicken && chicken.textContent === 'Rs 750 · Rs 75.00/pack', chicken && chicken.textContent);
  ok('chicken line says where the price came from', chicken && /meat order of 2026-09-20/.test(chicken.title), chicken && chicken.title);
  console.log('=== 3 · unpriced lines are named, not zero ===');
  ok('names Chicken Cubes as "no bill yet"', box.includes('Chicken Cubes (no bill yet)'), box);
  ok('names Maida as "not a vendor product yet"', box.includes('Maida (not a vendor product yet)'), box);
  ok('"so the real cost is higher"', box.includes('so the real cost is higher'));
  ok('cheese flagged as an old price', box.includes('Old price (over 60 days): Cheese'), box);
  const cubes = spans[TAIMUR.recipe.lines.findIndex(l => l.ingredient_id === 26)];
  ok('cubes cell says "no bill yet"', cubes && cubes.innerHTML.includes('no bill yet'), cubes && cubes.innerHTML);

  console.log('=== 2 · typing re-prices live ===');
  const qtys = T.els.recLines.querySelectorAll('.rec-qty');
  const ci = TAIMUR.recipe.lines.findIndex(l => l.ingredient_id === 1);
  qtys[ci].value = '2';
  qtys[ci].oninput.call(qtys[ci]);
  const box2 = T.els.recCost.innerHTML;
  ok('2 kg chicken → Rs 2,145 a batch · Rs 214.50 a pack', box2.includes('Rs 2,145 a batch') && box2.includes('Rs 214.50 a pack'), box2);
  T.els.recBasis.value = '20';
  T.els.recBasis.oninput();
  ok('20 packs a batch → Rs 107.25 a pack', T.els.recCost.innerHTML.includes('Rs 107.25 a pack'), T.els.recCost.innerHTML);

  console.log('=== 4 · Qasim sees which lines are priced, no rupees ===');
  const Q = run(QASIM);
  await flush(); await flush();
  Q.els.recProduct.value = '1313';
  Q.els.recProduct.onchange.call(Q.els.recProduct);
  await flush(); await flush();
  const qb = Q.els.recCost.innerHTML;
  ok('no "Rs <digit>" anywhere in the box', !/Rs\s?\d/.test(qb), qb);
  ok('the unpriced names are still there', qb.includes('Chicken Cubes (no bill yet)'), qb);
  const qch = costSpans(Q)[QASIM.recipe.lines.findIndex(l => l.ingredient_id === 1)];
  ok('chicken cell: "✓ priced"', qch && qch.textContent === '✓ priced', qch && qch.textContent);
  ok('no rupee figure in any cell', costSpans(Q).every(s => !/Rs\s?\d/.test(s.textContent + s.innerHTML)));

  console.log('=== 5 · change how Chicken Cubes is measured ===');
  const edits = T.els.ingList.querySelectorAll('.ing-edit');
  const cubeEdit = edits.find(e => e.getAttribute('data-id') === '26');
  ok('Chicken Cubes has an Edit button', !!cubeEdit);
  cubeEdit.onclick();
  ok('the form explains the change-everywhere door', !T.els.ingFormLock.classList.contains('hidden'));
  T.els.ingUnitChangeBtn.onclick();
  const tos = T.els.ingUnitChange.querySelectorAll('.ing-to');
  ok('offers weight and volume (not pieces, which it already is)', tos.map(t => t.getAttribute('data-to')).join(',') === 'g,ml', tos.map(t => t._attrs));
  T.setImpact({success: true, can_manage: true, ingredient: {id: 26, name: 'Chicken Cubes'}, from: 'pcs', to: 'g',
    from_word: 'pieces', to_word: 'weight', to_base_word: 'grams', can_change: true, locked_reasons: [],
    recipe_lines: [{id: 8, product_name: 'NF – Chicken Cheese Samosa (8 pcs)', version: 1, is_current: false, qty: 2},
                   {id: 19, product_name: 'NF – Chicken Cheese Samosa (8 pcs)', version: 2, is_current: true, qty: 2}],
    products: [{id: 82, vendor_name: 'Imtiaz Store frozen', product_name: 'Chicken Cubes', unit: 'piece', needs_size: true, new_pack_qty_base: null}]});
  tos[0].onclick();
  await flush(); await flush();
  const panel = T.els.ingUnitChange.innerHTML;
  ok('the impact url carries the id and the target', T.fetches.some(f => /ingredients\/26\/unit-impact\?to=g/.test(f.url)), T.fetches.map(f => f.url).slice(-2));
  ok('panel lists both recipe versions and the product', panel.includes('v1') && panel.includes('v2 (current)') && panel.includes('Imtiaz Store frozen · Chicken Cubes (piece)'), panel);
  const apply = T.els.iuApply;
  T.alerts.length = 0;
  apply.onclick();
  ok('refuses with amounts missing, posts nothing', T.alerts[0] && /Type every new amount/.test(T.alerts[0]) && !T.fetches.some(f => /change-unit/.test(f.url)), T.alerts);
  T.typed['8'] = '20'; T.typed['19'] = '22'; T.typed['82'] = '10';
  apply.onclick();
  await flush(); await flush();
  const post = T.fetches.find(f => /change-unit/.test(f.url));
  ok('posts to /ingredients/26/change-unit', !!post && /ingredients\/26\/change-unit/.test(post.url), post && post.url);
  const body = post ? JSON.parse(post.opts.body) : {};
  ok('with to=g, both recipe amounts and the product size', body.to === 'g' && body.recipe_qty['8'] === 20 && body.recipe_qty['19'] === 22 && body.product_sizes['82'] === 10, body);
  ok('then reloads the ingredients and the open recipe', T.fetches.filter(f => /\/khaas\/recipe\?/.test(f.url)).length >= 2);

  console.log('=== 5b · towards pieces, amounts must be whole ===');
  T.setImpact({success: true, can_manage: true, ingredient: {id: 23, name: 'Cheese'}, from: 'g', to: 'pcs',
    from_word: 'weight', to_word: 'pieces', to_base_word: 'pieces', can_change: true, locked_reasons: [],
    recipe_lines: [{id: 2, product_name: 'NF – Chicken Cheese Samosa (8 pcs)', version: 1, is_current: false, qty: 300}], products: []});
  const cheeseEdit = T.els.ingList.querySelectorAll('.ing-edit').find(e => e.getAttribute('data-id') === '23');
  cheeseEdit.onclick();
  T.els.ingUnitChangeBtn.onclick();
  T.els.ingUnitChange.querySelectorAll('.ing-to').find(t => t.getAttribute('data-to') === 'pcs').onclick();
  await flush(); await flush();
  T.typed['2'] = '1.5';
  const before = T.fetches.filter(f => /change-unit/.test(f.url)).length;
  T.alerts.length = 0;
  T.els.iuApply.onclick();
  ok('1.5 pieces refused, nothing posted', /whole numbers/.test(T.alerts[0] || '') && T.fetches.filter(f => /change-unit/.test(f.url)).length === before, T.alerts);

  console.log('=== 5c · a locked ingredient says why and offers no apply ===');
  T.setImpact({success: true, can_manage: true, ingredient: {id: 23, name: 'Cheese'}, from: 'g', to: 'ml',
    from_word: 'weight', to_word: 'volume', to_base_word: 'millilitres', can_change: false,
    locked_reasons: ['5 purchase lines were recorded in weight'], recipe_lines: [], products: []});
  T.els.iuApply = undefined;
  T.els.ingUnitChange.querySelectorAll('.ing-to').find(t => t.getAttribute('data-to') === 'ml') ||
    T.els.ingUnitChangeBtn.onclick();
  T.els.ingUnitChange.querySelectorAll('.ing-to').find(t => t.getAttribute('data-to') === 'ml').onclick();
  await flush(); await flush();
  ok('locked panel names the reason', T.els.ingUnitChange.innerHTML.includes('It cannot change: 5 purchase lines were recorded in weight'), T.els.ingUnitChange.innerHTML);
  ok('and has no "Change everywhere" button', !T.els.ingUnitChange.innerHTML.includes('id="iuApply"'));

  console.log('=== 6 · 🔗 Link at… (Sep-27) ===');
  const gapsHtml = T.els.ingGaps.innerHTML;
  ok('the gaps box offers "Link at…" with the 3 by-weight vendors',
    gapsHtml.includes('class="ing-link') && gapsHtml.includes('Imtiaz Store frozen') && gapsHtml.includes('B.B.Q') && gapsHtml.includes('Tazo cheese'), gapsHtml.slice(0, 300));
  ok('Capsicum says its vendor enters bills as one total', gapsHtml.includes('Vegetable Supplies enters bills as one total'), gapsHtml.slice(0, 600));
  const picks = T.els.ingGaps.querySelectorAll('.ing-link');
  const maidaPick = picks.find(p => p.getAttribute('data-id') === '28');
  ok('Maida has a picker', !!maidaPick);
  if (maidaPick) {
    maidaPick.value = '50';
    maidaPick.onchange();
  }
  ok('choosing Imtiaz opens its Manage Products with Maida pre-chosen',
    /\/finance\/vendors\/50\/products\?link_ingredient=28$/.test(T.ctx.window.location.href), T.ctx.window.location.href);

  console.log(`\n${fail === 0 ? 'OK' : 'FAILED'}  ${pass} passed, ${fail} failed`);
  process.exit(fail === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
