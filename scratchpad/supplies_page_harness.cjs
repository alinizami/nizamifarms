// Drives the REAL Storage page IIFE (extracted from pages/supplies/index.blade.php)
// against a stub DOM. The three things proven here are the ones that move money:
//
//   1. the misread guard — the web intake had NO median check at all until this round,
//      so a keyboard-wedge scan of a 0.505 kg label that read as 9.205 kg went straight
//      in and mis-priced every packet in the batch;
//   2. the client_uuid lifecycle — minted ONCE per form open, kept across a failed Save
//      (that is exactly when it matters), cleared on success and on Cancel;
//   3. the batch-total cross-check — the only thing that catches a missed packet or a
//      label scanned twice.
//
// Usage: node scratchpad/supplies_page_harness.cjs <extracted.js>
const fs = require('fs'), vm = require('vm');
const code = fs.readFileSync(process.argv[2], 'utf8');

let pass = 0, fail = 0;
const ok = (name, cond, extra) => {
  if (cond) { pass++; console.log('  PASS ' + name); }
  else { fail++; console.log('  FAIL ' + name + (extra !== undefined ? '  -> ' + JSON.stringify(extra) : '')); }
};

// ── stub DOM ───────────────────────────────────────────────────────────────────
function mkEl(id) {
  return {
    id, value: '', textContent: '', innerHTML: '', disabled: false, checked: false, step: '',
    dataset: {}, options: [], selectedIndex: -1, style: {},
    classList: {
      _s: new Set(),
      add(c) { this._s.add(c); }, remove(c) { this._s.delete(c); },
      contains(c) { return this._s.has(c); },
      toggle(c, on) { if (on === undefined) { on = !this._s.has(c); } on ? this._s.add(c) : this._s.delete(c); return on; },
    },
    closest() { return null; }, focus() {}, addEventListener() {},
  };
}

const els = {};
const ID_LIST = [
  'supRoot', 'supBookInModal', 'supBookInMsg', 'supBiProduct', 'supBiScanBlock', 'supBiScanLabel',
  'supBiScanInput', 'supBiScanHint', 'supBiStaged', 'supBiCountLabel', 'supBiQtyLabel',
  'supBiManualBtn', 'supBiExpected', 'supBiExpectedLabel', 'supBiExpectedHint', 'supBiPiecesBlock',
  'supBiPieces', 'supBiPiecesLabel', 'supBiCost', 'supBiDate', 'supBiSource', 'supBiBankField', 'supBiBank',
  'supBiStockOnly', 'supBiNote', 'supBiSave', 'supProductModal', 'supPrMsg', 'supPrTitle',
  'supPrName', 'supPrMode', 'supPrPlu', 'supPrBarcode', 'supPrPiecesPer', 'supPrLow',
  'supPrActive', 'supPrCategory', 'supPrSave', 'supPrLockedHint', 'supPrPacketBarcode', 'supPrPacketKg',
  'supPrPacketField', 'supPaneTakeouts', 'supTakeoutList', 'supTabTakeouts', 'supEditModal', 'supEdMsg',
  'supEdTitle', 'supEdQty', 'supEdReason', 'supEdPreview', 'supEdApply', 'supToScan', 'supToWarn', 'supToQuote', 'supTakeOutModal', 'supToMsg',
  'supToTitle', 'supToBody', 'supToConfirm', 'supToPacket', 'supToQty', 'supStockPane',
  'supBatchPane', 'supHistoryPane', 'supApprovalSwitch', 'supCountModal', 'supCnSave',
  'supCorrectModal', 'supCoApply',
];
ID_LIST.concat(["supPool1"]).forEach((id) => { els[id] = mkEl(id); });
els.supPool1.classList.add("sup-none");
els.supRoot.dataset.canManage = '1';

// the calls the page makes, and what we hand back
const CATALOGUE = {
  success: true,
  can_manage: true,
  products: [
    {id: 1, name: 'NF Bags Large', mode: 'weight', plu: 190, barcode: null, is_active: 1,
     expense_config_id: 7, low_stock_qty: null, pieces_per_packet: null, has_stock: true, stock_value: 50340},
    {id: 2, name: 'Fresh Sleeve', mode: 'scan', plu: null, barcode: '8901234567894', is_active: 1,
     expense_config_id: 7, low_stock_qty: null, pieces_per_packet: null, has_stock: false, stock_value: 0},
  ],
  expense_categories: [{id: 7, name: 'Packaging - Bags', business_unit_id: 1}],
  payment_sources: [{id: 2, account_name: 'Online Bank', display_name: 'Online Bank',
                     is_default: true, is_online: true, preferred_bank_id: 3}],
  banks: [{id: 3, short_code: 'HBL'}],
};

let decodeReply = {success: true, plu: 190, weight_kg: 1.25, barcode: '2000190012506'};
const posted = [];          // every POST body the page sent
let batchReply = null;      // null => reject (network failure)

// ── round 3 stubs — the pool ────────────────────────────────────────────────────
let scanReply = {
  success: true, pooled: true, product: {id: 1, name: 'NF Bags Large', mode: 'weight'},
  qty: 1.5, qty_label: '1.5 kg', cost: 1376.53, free: false, capped: false,
  pool_remaining: 54.86, pool_label: '54.86 kg', scanned_barcode: '2000190015008', warnings: [],
};
let quoteReply = {...scanReply};
let takeOutReply = {success: true, message: 'Taken out — Rs 1,377 booked to Packaging - Bags.'};
let editPreviewReply = {
  success: true, was_qty_label: '1.5 kg', qty_label: '1.2 kg', was_cost: 1376.53, cost: 1101.22,
  legs: [{purchase_date: '14-Sep', qty: 1.2, cost: 1101.22}],
  restates_earlier_month: false, expense_month: '2026-09',
};
let takeoutsReply = {
  success: true, shows_everyone: true,
  takeouts: [
    {id: 77, product_name: 'NF Bags Large', qty: 1.5, unit: 'kg', qty_label: '1.5 kg', cost: 1376.53,
     status: 'approved', request_number: 'REQ-1', typed: false, taken_by_name: 'Taimur', mine: true,
     can_undo: false, can_delete: true, can_edit: true, at: '17-Sep 04:47 PM'},
    {id: 78, product_name: 'NF Bags Large', qty: 2.0, unit: 'kg', qty_label: '2 kg', cost: 1835.37,
     status: 'undone', request_number: 'REQ-2', typed: true, taken_by_name: 'Waseem', mine: false,
     can_undo: false, can_delete: false, can_edit: false, at: '17-Sep 05:10 PM'},
  ],
};
let poolReply = {
  success: true, product: {id: 1, name: 'NF Bags Large', mode: 'weight'},
  batches: [
    {id: 2, purchase_date: '2026-09-14', qty_total: 27.89, qty_remaining: 27.89, qty_label: '27.89 kg',
     total_cost: 25590, cost_remaining: 25590, paid_from: 'Online Bank', is_active: true,
     takeouts_from_it: 0, can_void: true, can_correct: true},
    {id: 1, purchase_date: '2026-09-14', qty_total: 26.97, qty_remaining: 25.47, qty_label: '25.47 kg',
     total_cost: 24750, cost_remaining: 23373.47, paid_from: 'Online Bank', is_active: true,
     takeouts_from_it: 1, can_void: false, can_correct: true},
  ],
};

const sandbox = {
  console,
  // ⚠ `window` must BE the global object, exactly as in a browser. The page writes its
  // entry points as `window.supBookIn = ...` and then calls some of them bare
  // (`supBiProductChanged()`), which only resolves if window === globalThis.
  confirm: () => true,
  prompt: () => null,
  alert: () => {},
  location: {reload() { sandbox.__reloaded = (sandbox.__reloaded || 0) + 1; }},
  __reloaded: 0,
  document: {
    getElementById: (id) => els[id] || null,
    querySelector: (s) => (s.indexOf('csrf') >= 0 ? {content: 'tok'} : null),
    querySelectorAll: () => [],
    addEventListener: () => {},
  },
  setTimeout: (fn) => { fn(); return 0; },
  fetch: (url, opts) => {
    const body = opts && opts.body ? JSON.parse(opts.body) : null;
    posted.push({url, body});
    if (url.indexOf('/supplies/decode') >= 0) {
      return Promise.resolve({ok: true, json: () => Promise.resolve(decodeReply)});
    }
    if (url.indexOf('/supplies/products') >= 0 && (!opts || opts.method !== 'POST')) {
      return Promise.resolve({ok: true, json: () => Promise.resolve(CATALOGUE)});
    }
    if (url.indexOf('/supplies/batches') >= 0) {
      if (batchReply === null) { return Promise.reject(new Error('network down')); }
      return Promise.resolve({ok: true, json: () => Promise.resolve(batchReply)});
    }
    // ── round 3 ─────────────────────────────────────────────────────────────────
    if (url.indexOf('/supplies/resolve-scan') >= 0) {
      return Promise.resolve({ok: true, json: () => Promise.resolve(scanReply)});
    }
    if (url.indexOf('/supplies/take-out/quote') >= 0) {
      return Promise.resolve({ok: true, json: () => Promise.resolve(quoteReply)});
    }
    if (/\/supplies\/take-out\/\d+\/preview-edit/.test(url)) {
      return Promise.resolve({ok: true, json: () => Promise.resolve(editPreviewReply)});
    }
    if (url.indexOf('/supplies/take-out') >= 0) {
      return Promise.resolve({ok: takeOutReply.success !== false,
                              json: () => Promise.resolve(takeOutReply)});
    }
    if (url.indexOf('/my-takeouts') >= 0) {
      return Promise.resolve({ok: true, json: () => Promise.resolve(takeoutsReply)});
    }
    if (/\/supplies\/\d+\/batches/.test(url)) {
      return Promise.resolve({ok: true, json: () => Promise.resolve(poolReply)});
    }
    return Promise.resolve({ok: true, json: () => Promise.resolve({success: true})});
  },
};
sandbox.window = sandbox;
sandbox.globalThis = sandbox;
vm.createContext(sandbox);
vm.runInContext(code, sandbox);

const tick = () => new Promise((r) => setImmediate(r));
const act = async (fn) => { await fn(); await tick(); await tick(); await tick(); };
const w = sandbox;

(async () => {
  console.log('\n=== 1. the misread guard the web page never had ===');
  await w.supOpenBookIn(); await tick(); await tick();
  els.supBiProduct.value = '1';
  w.supBiProductChanged();

  const key = (raw) => {
    els.supBiScanInput.value = raw;
    return w.supBiScanKey({key: 'Enter', preventDefault() {}, target: els.supBiScanInput});
  };

  // three ordinary 1.25 kg bags — the gate must stay invisible
  let confirms = 0;

  sandbox.confirm = () => { confirms++; return true; };
  for (let i = 0; i < 3; i++) { key('2000190012506'); await tick(); await tick(); }
  ok('three normal packets staged with no interruption',
     els.supBiCountLabel.textContent === '3 packet(s)' && confirms === 0,
     {label: els.supBiCountLabel.textContent, confirms});
  ok('  ...and the running total is right', els.supBiQtyLabel.textContent === '3.75 kg',
     els.supBiQtyLabel.textContent);

  // the real Aug-28 misread: a 0.505 kg label that decoded as 9.205 kg
  decodeReply = {success: true, plu: 190, weight_kg: 9.205, barcode: '2000190092057'};
  sandbox.confirm = () => { confirms++; return false; };   // "scan it again"
  key('2000190092057'); await tick(); await tick();
  ok('⭐ the misread IS questioned', confirms === 1, confirms);
  ok('  ...and rejecting it keeps it OUT of the batch',
     els.supBiCountLabel.textContent === '3 packet(s)', els.supBiCountLabel.textContent);

  sandbox.confirm = () => { confirms++; return true; };    // "it really is correct"
  key('2000190092057'); await tick(); await tick();
  ok('  ...and confirming it lets a genuinely odd packet through',
     els.supBiCountLabel.textContent === '4 packet(s)', els.supBiCountLabel.textContent);

  console.log('\n=== 2. the first packet has no baseline, by design ===');
  await w.supOpenBookIn(); await tick(); await tick();
  els.supBiProduct.value = '1'; w.supBiProductChanged();
  confirms = 0;
  key('2000190092057'); await tick(); await tick();
  ok('a lone first packet is never questioned — nothing to compare with',
     confirms === 0 && els.supBiCountLabel.textContent === '1 packet(s)',
     {confirms, label: els.supBiCountLabel.textContent});

  console.log('\n=== 3. client_uuid — one per ATTEMPT, kept across a failure ===');
  decodeReply = {success: true, plu: 190, weight_kg: 1.25, barcode: '2000190012506'};
  await w.supOpenBookIn(); await tick(); await tick();
  els.supBiProduct.value = '1'; w.supBiProductChanged();
  sandbox.confirm = () => true;
  key('2000190012506'); await tick(); await tick();
  els.supBiCost.value = '5000';
  els.supBiSource.options = [{value: '2', dataset: {online: '1', bank: '3'}}];
  els.supBiSource.selectedIndex = 0;
  els.supBiSource.value = '2';
  els.supBiBankField.classList.add('sup-none');   // treat as cash-like for this leg
  els.supBiExpected.value = '';

  posted.length = 0;
  batchReply = null;                              // the network drops
  w.supBookIn(); await tick(); await tick();
  const firstTry = posted.filter((p) => p.url.indexOf('/supplies/batches') >= 0);
  ok('the failed Save did send a uuid', firstTry.length === 1 && !!firstTry[0].body.client_uuid);
  ok('⚠ the failure copy no longer claims nothing was recorded',
     els.supBookInMsg.textContent.indexOf('Press Save again') >= 0, els.supBookInMsg.textContent);

  batchReply = {success: true, message: 'Booked'};
  w.supBookIn(); await tick(); await tick();
  const tries = posted.filter((p) => p.url.indexOf('/supplies/batches') >= 0);
  ok('⭐⭐ the retry carries the SAME uuid — the purchase cannot be booked twice',
     tries.length === 2 && tries[0].body.client_uuid === tries[1].body.client_uuid,
     tries.map((t) => t.body.client_uuid));

  const usedUuid = tries[1].body.client_uuid;
  await w.supOpenBookIn(); await tick(); await tick();
  els.supBiProduct.value = '1'; w.supBiProductChanged();
  key('2000190012506'); await tick(); await tick();
  els.supBiCost.value = '5000'; els.supBiExpected.value = '';
  posted.length = 0;
  w.supBookIn(); await tick(); await tick();
  const fresh = posted.filter((p) => p.url.indexOf('/supplies/batches') >= 0)[0];
  ok('a NEW intake gets a NEW uuid — a real second purchase is not collapsed',
     fresh && fresh.body.client_uuid !== usedUuid, fresh && fresh.body.client_uuid);

  console.log('\n=== 4. the batch-total cross-check ===');
  await w.supOpenBookIn(); await tick(); await tick();
  els.supBiProduct.value = '1'; w.supBiProductChanged();
  for (let i = 0; i < 3; i++) { key('2000190012506'); await tick(); await tick(); }  // 3 x 1.25 = 3.75
  els.supBiCost.value = '5000';
  els.supBiExpected.value = '5';                 // the bill says 5 kg — a packet is missing
  let asked = null;
  sandbox.confirm = (m) => { asked = m; return false; };
  posted.length = 0;
  w.supBookIn(); await tick(); await tick();
  ok('⭐ a short batch is questioned before it is saved', asked !== null);
  ok('  ...and it says which way it is out',
     !!asked && asked.indexOf('packet may be missing') >= 0);
  ok('  ...and Cancel really does not save',
     posted.filter((p) => p.url.indexOf('/supplies/batches') >= 0).length === 0);

  els.supBiExpected.value = '3.75';
  asked = null;
  batchReply = {success: true, message: 'Booked'};
  posted.length = 0;
  w.supBookIn(); await tick(); await tick();
  ok('a batch that matches the bill is saved with no interruption',
     asked === null && posted.filter((p) => p.url.indexOf('/supplies/batches') >= 0).length === 1);

  console.log('\n=== 5. the product form locks what the server refuses ===');
  await w.supOpenProduct(1); await tick(); await tick();       // has_stock: true
  ok('mode is locked for a product with stock', els.supPrMode.disabled === true);
  ok('  ...PLU too', els.supPrPlu.disabled === true);
  ok('  ...and the barcode', els.supPrBarcode.disabled === true);
  ok('  ...and the reason is on screen',
     els.supPrLockedHint.textContent.indexOf('already has stock') >= 0, els.supPrLockedHint.textContent);
  ok('  ...while the name stays editable', els.supPrName.disabled === false);

  await w.supOpenProduct(2); await tick(); await tick();       // has_stock: false
  ok('a product with no stock is fully editable',
     els.supPrMode.disabled === false && els.supPrPlu.disabled === false && els.supPrBarcode.disabled === false);

  await w.supOpenProduct(); await tick(); await tick();        // brand new
  ok('a brand-new product is fully editable', els.supPrMode.disabled === false);

  console.log('\n=== 6. ⭐⭐ a POOLED take-out asks HOW MUCH, not WHICH PACKET ===');
  await w.supOpenTakeOut(1); await tick(); await tick();
  ok('no "which packet?" dropdown for a weighed product',
     els.supToBody.innerHTML.indexOf('Which packet') < 0);
  ok('  ...it asks for a weight instead', els.supToBody.innerHTML.indexOf('Weight (kg)') >= 0);
  ok('  ...and offers a scan box', els.supToBody.innerHTML.indexOf('supToScan') >= 0);

  els.supToScan.value = '2000190015008';
  await act(() => w.supToScanKey({key: 'Enter', preventDefault() {}, target: els.supToScan}));
  ok('a scan fills in the weight it read', els.supToQty.value === 1.5, els.supToQty.value);
  ok('  ...and prices it before anything is pressed',
     els.supToQuote.innerHTML.indexOf('1.5 kg') >= 0 && els.supToQuote.innerHTML.indexOf('1,377') >= 0,
     els.supToQuote.innerHTML);
  ok('  ...naming what is left on the shelf', els.supToQuote.innerHTML.indexOf('54.86 kg') >= 0);

  posted.length = 0;
  await act(() => w.supTakeOut());
  const sent = posted.filter((p) => p.url.indexOf('/supplies/take-out') >= 0 && p.url.indexOf('quote') < 0);
  ok('⭐ it posts a QUANTITY, never a packet id',
     sent.length === 1 && sent[0].body.qty === 1.5 && sent[0].body.packet_id === undefined,
     sent[0] && sent[0].body);
  ok('  ...and carries the code it actually read',
     sent[0].body.scanned_barcode === '2000190015008' && sent[0].body.source === 'scan');

  console.log('\n=== 7. the guards must be SEEN before they are passed ===');
  quoteReply = {...scanReply, qty: 9, qty_label: '9 kg', cost: 8259,
    warnings: [{code: 'most_of_shelf', title: 'That is most of the shelf',
                message: '9 kg out of 14 kg. Is this one packet?'}]};
  await w.supOpenTakeOut(1); await tick(); await tick();
  els.supToQty.value = '9';
  await act(() => w.supToQuoteSoon());
  ok('⭐ the warning is on screen before the button is pressed',
     !els.supToWarn.classList.contains('sup-none') &&
     els.supToWarn.innerHTML.indexOf('most of the shelf') >= 0, els.supToWarn.innerHTML);

  posted.length = 0;
  await act(() => w.supTakeOut());
  const guarded = posted.filter((p) => p.url.indexOf('/supplies/take-out') >= 0 && p.url.indexOf('quote') < 0);
  ok('  ...and pressing it sends the confirmation BY CODE',
     guarded.length === 1 && JSON.stringify(guarded[0].body.confirmed_warnings) === '["most_of_shelf"]',
     guarded[0] && guarded[0].body.confirmed_warnings);

  // a guard the page had not shown (stale quote) must be surfaced, not written through
  takeOutReply = {success: false, code: 'needs_confirmation',
    warnings: [{code: 'same_label', title: 'This label went out already', message: '3 minutes ago.'}]};
  els.supToQty.value = '1.5';
  posted.length = 0;
  await act(() => w.supTakeOut());
  ok('⚠ a guard the server raises is SHOWN, not clicked through',
     els.supToWarn.innerHTML.indexOf('went out already') >= 0, els.supToWarn.innerHTML);
  takeOutReply = {success: true, message: 'Taken out.'};

  console.log('\n=== 8. take-outs list, delete and weight edit ===');
  await act(() => w.supShowTab('takeouts'));
  const list = els.supTakeoutList.innerHTML;
  ok('the list shows everyone when the server says so', list.indexOf("everyone's take-outs") >= 0);
  ok('  ...marks a typed take-out', list.indexOf('(typed)') >= 0);
  ok('  ...offers Edit + Delete on a live row', list.indexOf('Edit weight') >= 0 && list.indexOf('Delete') >= 0);
  ok('  ...and offers neither on one already reversed',
     (list.match(/Edit weight/g) || []).length === 1 && (list.match(/>Delete</g) || []).length === 1);

  await act(() => w.supOpenEditTakeout(77, 1.5, 'NF Bags Large'));
  ok('the edit dialog opens on the right take-out', els.supEdQty.value === 1.5);
  ok('  ...with Apply hidden until the preview is seen',
     els.supEdApply.classList.contains('sup-none'));

  els.supEdQty.value = '1.2';
  await act(() => w.supPreviewEditTakeout());
  ok('⭐ the preview shows old → new, weight AND money',
     els.supEdPreview.innerHTML.indexOf('1.5 kg') >= 0 &&
     els.supEdPreview.innerHTML.indexOf('1.2 kg') >= 0 &&
     els.supEdPreview.innerHTML.indexOf('1,101') >= 0, els.supEdPreview.innerHTML);
  ok('  ...and only then offers Apply', !els.supEdApply.classList.contains('sup-none'));

  editPreviewReply = {...editPreviewReply, restates_earlier_month: true, expense_month: '2026-08'};
  await act(() => w.supPreviewEditTakeout());
  ok('⚠ a month already reported is named before it is restated',
     els.supEdPreview.innerHTML.indexOf('already been reported') >= 0 &&
     els.supEdPreview.innerHTML.indexOf('2026-08') >= 0);

  console.log('\n=== 9. the card shows what the pool is made of ===');
  const btn = {textContent: '2 purchases in this pool ▾'};
  await act(() => w.supTogglePool(1, btn));
  const detail = els.supPool1 ? els.supPool1.innerHTML : '';
  ok('both open purchases are listed', detail.indexOf('25.47 kg') >= 0 && detail.indexOf('27.89 kg') >= 0, detail);
  ok('⭐ the OLDEST is marked as the one the next take-out comes from',
     detail.indexOf('sup-pool-next') >= 0 && detail.indexOf('→ 25.47 kg') >= 0);
  ok('  ...each with its own rate', detail.indexOf('/unit') >= 0);

  console.log('\n' + (fail ? `  ${fail} FAILED, ${pass} passed` : `  all ${pass} checks passed`) + '\n');
  process.exit(fail ? 1 : 0);
})();
