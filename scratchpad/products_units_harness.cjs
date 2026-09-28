// Sep-26-2026 Round B — the web Manage Products page's REAL script, driven in a vm.
//   1. kg against Chicken Cubes (pieces) → the mismatch box says so at once, "Use pieces" fixes it
//   2. "Change … everywhere" → asks the server's impact, renders every recipe line, posts the typed
//      amounts to change-unit, then re-submits the product form
//   3. the server's own unit_mismatch answer renders the same panel (edit form)
//   4. editing a legacy "1" unit shows it for re-picking; a product with purchases locks its unit
// Usage: node scratchpad/products_units_harness.cjs scratchpad/products_page_script.js
const fs = require('fs'), vm = require('vm');
const code = fs.readFileSync(process.argv[2], 'utf8');
let pass = 0, fail = 0;
const ok = (n, c, x) => { if (c) { pass++; console.log('  PASS ' + n); } else { fail++; console.log('  FAIL ' + n + (x !== undefined ? '  -> ' + JSON.stringify(x) : '')); } };

const typed = {};   // data-id → value the "person" types into the change panel
function mkEl(id) {
  const el = {
    id, value: '', textContent: '', innerHTML: '', disabled: false, checked: false, dataset: {}, style: {},
    _children: [],
    classList: { _s: new Set(['hidden']), add(c) { this._s.add(c); }, remove(c) { this._s.delete(c); },
      contains(c) { return this._s.has(c); }, toggle(c, on) { if (on === undefined) { on = !this._s.has(c); } on ? this._s.add(c) : this._s.delete(c); return on; } },
    closest() { return null; }, focus() {}, addEventListener() {}, dispatchEvent() {}, reset() {},
    requestSubmit() { el._submitted = (el._submitted || 0) + 1; },
    appendChild(o) { el._children.push(o); },
    querySelectorAll(sel) {
      if (sel === 'option[data-legacy]') { return el._children.filter(o => o.dataset.legacy).map(o => ({remove() { el._children.splice(el._children.indexOf(o), 1); }})); }
      if (sel === '.pq-base') { return []; }
      const cls = sel.replace('.', '');
      const out = [];
      const re = new RegExp('class="' + cls + '[^"]*" data-id="(\\d+)"', 'g');
      let m;
      while ((m = re.exec(el.innerHTML))) { out.push({dataset: {id: m[1]}, value: typed[m[1]] ?? ''}); }
      return out;
    },
  };
  return el;
}
const els = {};
const document = {
  getElementById(id) { if (!els[id]) { els[id] = mkEl(id); } return els[id]; },
  createElement() { return {value: '', textContent: '', dataset: {}}; },
};
const fetches = [];
let nextFetch = null;
const fetch = (url, opts) => { fetches.push({url, opts}); const r = nextFetch ? nextFetch(url, opts) : {success: true}; return Promise.resolve({json: () => Promise.resolve(r)}); };
const messages = [];
const ctx = {document, fetch, console, window: {location: {reload() {}}}, setTimeout: () => 0, confirm: () => true, Event: function (t) { this.type = t; }, Number, String, JSON, encodeURIComponent};
vm.createContext(ctx);
vm.runInContext(code + '\n;globalThis.__h = {syncPackQty, useSuggested, openChangeEverywhere, applyChangeEverywhere, handleRefusal, editProduct, ingredientById, INGREDIENTS, showMessage: (t, m) => {}};', ctx);
vm.runInContext('showMessage = function (t, m) { globalThis.__msgs = (globalThis.__msgs || []).concat([[t, m]]); };', ctx);
const H = ctx.__h;
const flush = () => new Promise(r => setImmediate(r));

const IMPACT = {success: true, can_manage: true, ingredient: {id: 26, name: 'Chicken Cubes'}, from: 'pcs', to: 'g',
  from_word: 'pieces', to_word: 'weight', to_base_word: 'grams', can_change: true, locked_reasons: [],
  recipe_lines: [{id: 501, product_name: 'NF - Chicken Cheese Samosa', version: 1, is_current: false, qty: 2},
                 {id: 502, product_name: 'NF - Chicken Cheese Samosa', version: 2, is_current: true, qty: 2}],
  products: [{id: 82, vendor_name: 'Imtiaz Store frozen', product_name: 'Chicken Cubes', unit: 'piece', needs_size: true, new_pack_qty_base: null}]};

(async () => {
  console.log('=== 1 · kg against a pieces ingredient ===');
  const cubes = H.ingredientById(26);
  ok('Chicken Cubes is on the page, counted in pieces, used in recipes', cubes && cubes.base_unit === 'pcs' && cubes.recipe_count > 0, cubes);
  els.ingredient_id = mkEl('ingredient_id'); els.ingredient_id.value = '26';
  els.unit = mkEl('unit'); els.unit.value = 'kg';
  H.syncPackQty('ingredient_id', 'unit', 'pack_qty_wrap', 'pack_qty_unit');
  ok('the mismatch box shows', !els.unit_mismatch.classList.contains('hidden'));
  ok('saying so plainly', /Chicken Cubes is counted in PIECES in your recipes, but this product is set to kg/.test(els.unit_mismatch.innerHTML), els.unit_mismatch.innerHTML);
  ok('with "Use pieces"', els.unit_mismatch.innerHTML.includes('Use pieces'));
  ok('and "Change … to weight everywhere"', els.unit_mismatch.innerHTML.includes('Change Chicken Cubes to weight everywhere'));
  H.useSuggested('', 'piece');
  H.syncPackQty('ingredient_id', 'unit', 'pack_qty_wrap', 'pack_qty_unit');
  ok('"Use pieces" sets the unit', els.unit.value === 'piece');
  ok('and the box goes away', els.unit_mismatch.classList.contains('hidden'));
  els.unit.value = 'box';
  H.syncPackQty('ingredient_id', 'unit', 'pack_qty_wrap', 'pack_qty_unit');
  ok('a box asks its size', !els.pack_qty_wrap.classList.contains('hidden'));

  console.log('=== 2 · change everywhere ===');
  els.unit.value = 'kg';
  nextFetch = url => (url.includes('unit-impact') ? IMPACT : {success: true, message: 'Chicken Cubes is now counted in weight everywhere'});
  H.openChangeEverywhere('');
  await flush(); await flush();
  ok('asked the server for the impact', /\/khaas\/ingredients\/26\/unit-impact\?to=g$/.test(fetches[0].url), fetches[0].url);
  const panel = els.change_panel;
  ok('the panel lists both Samosa versions', panel.innerHTML.includes('NF - Chicken Cheese Samosa v2 (current) — was 2 pieces'));
  ok('and asks #82\'s size in grams', panel.innerHTML.includes('class="cu-size') && panel.innerHTML.includes('data-id="82"'));
  H.applyChangeEverywhere('');
  await flush();
  ok('nothing posted while an amount is missing', fetches.length === 1);
  typed[501] = '40'; typed[502] = '44'; typed[82] = '20';
  H.applyChangeEverywhere('');
  await flush(); await flush();
  const post = fetches[1];
  ok('posts to change-unit', post && /\/khaas\/ingredients\/26\/change-unit$/.test(post.url), post && post.url);
  ok('with every typed amount', post && JSON.stringify(JSON.parse(post.opts.body)) === JSON.stringify({to: 'g', recipe_qty: {501: 40, 502: 44}, product_sizes: {82: 20}}), post && post.opts.body);
  ok('then re-submits the product form', els.addProductForm._submitted === 1);
  ok('the page now knows Chicken Cubes is grams', H.ingredientById(26).base_unit === 'g');

  console.log('=== 3 · the server\'s own refusal on the edit form ===');
  H.handleRefusal('edit_', {code: 'unit_mismatch', message: 'x', change: {...IMPACT, can_change: false, locked_reasons: ['3 recorded purchase lines already count it in pieces']}});
  ok('the edit panel renders', !els.edit_change_panel.classList.contains('hidden'));
  ok('locked, with the reason and no apply button', els.edit_change_panel.innerHTML.includes('It cannot change: 3 recorded purchase lines') && !els.edit_change_panel.innerHTML.includes('applyChangeEverywhere'));

  console.log('=== 4 · editing legacy and locked products ===');
  els.editModal = mkEl('editModal');
  H.editProduct(78, 'Maida', '1', 165, false, '', 0, 0);
  ok('a legacy "1" unit is shown for re-picking', els.edit_unit.value === '1' && els.edit_unit._children.some(o => /not a unit, pick one/.test(o.textContent)));
  ok('and it is not locked (never bought)', els.edit_unit.disabled === false);
  H.editProduct(76, 'Cooking Oil', 'kg', 580, true, '', 0, 0);
  ok('a product with a purchase has its unit locked', els.edit_unit.disabled === true);
  ok('saying why', /1 purchase recorded in kg/.test(els.edit_unit_lock.textContent), els.edit_unit_lock.textContent);
  ok('the old legacy option was cleared', !els.edit_unit._children.some(o => o.dataset && o.dataset.legacy));
  H.editProduct(82, 'Chicken Cubes', 'pcs', 75, false, '', 26, 1);
  ok('"pcs" opens as piece', els.edit_unit.value === 'piece');

  console.log(`\n${fail === 0 ? '✅' : '❌'}  ${pass} passed, ${fail} failed`);
  process.exit(fail === 0 ? 0 : 1);
})();
