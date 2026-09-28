// Sep-27: the Manage Products page's REAL script, opened as /finance/vendors/50/products?link_ingredient=28
// (from the Planning page's "Link at…"): Maida is pre-chosen, the name filled, the note shown.
const fs = require('fs'), vm = require('vm'), path = require('path');
const code = fs.readFileSync(path.join(__dirname, 'products_page_script_link.js'), 'utf8');
let pass = 0, fail = 0;
const ok = (n, c, x) => { if (c) { pass++; console.log('  PASS ' + n); } else { fail++; console.log('  FAIL ' + n + (x !== undefined ? '  -> ' + JSON.stringify(x) : '')); } };
function mkEl(id) {
  return { id, value: '', textContent: '', innerHTML: '', style: {}, dataset: {}, options: [], disabled: false,
    classList: { _s: new Set(['hidden']), add(c) { this._s.add(c); }, remove(c) { this._s.delete(c); }, contains(c) { return this._s.has(c); }, toggle() {} },
    parentNode: { inserted: [], insertBefore(n) { this.inserted.push(n); } },
    addEventListener() {}, querySelectorAll() { return []; }, appendChild() {}, scrollIntoView() { this.scrolled = true; }, focus() {} };
}
const els = {};
const document = {
  getElementById(id) { if (!els[id]) { els[id] = mkEl(id); } return els[id]; },
  createElement() { return mkEl('new'); }, querySelector() { return null; }, querySelectorAll() { return []; }, addEventListener() {},
};
const INGS = JSON.parse(code.match(/const INGREDIENTS = (\[.*?\]);/s)[1]);
const maida = INGS.find(i => i.name === 'Maida');
document.getElementById('ingredient_id').options = INGS.map(i => ({value: String(i.id)}));
const ctx = {document, console, fetch: () => Promise.resolve({json: () => Promise.resolve({})}), setTimeout: () => 0,
  window: {location: {search: '?link_ingredient=' + maida.id, reload() {}}}, URLSearchParams, confirm: () => true, alert() {}};
vm.createContext(ctx);
vm.runInContext(code, ctx);
ok('Maida is on this vendor page', !!maida);
ok('the add form has Maida pre-chosen', els.ingredient_id.value === String(maida.id), els.ingredient_id.value);
ok('the name is filled from the ingredient', els.product_name.value === 'Maida', els.product_name.value);
const note = els.addProductForm.parentNode.inserted[0];
ok('a note says how to link an existing product instead', !!note && /Linking Maida/.test(note.innerHTML) && /Edit/.test(note.innerHTML), note && note.innerHTML);
// without the parameter nothing changes
const els2 = {}; const doc2 = {...document, getElementById(id) { if (!els2[id]) { els2[id] = mkEl(id); } return els2[id]; }};
const ctx2 = {...ctx, document: doc2, window: {location: {search: '', reload() {}}}};
vm.createContext(ctx2); vm.runInContext(code, ctx2);
ok('opened normally, nothing is pre-chosen', (els2.ingredient_id ? els2.ingredient_id.value : '') === '');
console.log(`\n${fail === 0 ? 'OK' : 'FAILED'}  ${pass} passed, ${fail} failed`);
process.exit(fail === 0 ? 0 : 1);
