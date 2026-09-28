// Sep-25-2026 — the PACKET product on the web Storage page (Vaccum Seal Bags, scale no. 176).
//
// Drives the REAL page IIFE twice — once as a store user, once as a manager — because the
// owner's ruling splits them: the store team takes out BY SCANNING, Taimur and Shabib may
// also take out without a scan.
//
//   1. book-in is a typed COUNT ("10 packets, Rs X"), never ten scans of the same label
//   2. store user: a scan resolves the packet, the take-out carries the scanned code;
//      no "without scanning" button; no typing a weight on a weighed product
//   3. manager: "Take one out without scanning" quotes the next packet and posts it manual
//
// Usage: node scratchpad/supplies_packet_harness.cjs <extracted.js>
const fs = require('fs'), vm = require('vm');
const code = fs.readFileSync(process.argv[2], 'utf8');

let pass = 0, fail = 0;
const ok = (name, cond, extra) => {
  if (cond) { pass++; console.log('  PASS ' + name); }
  else { fail++; console.log('  FAIL ' + name + (extra !== undefined ? '  -> ' + JSON.stringify(extra) : '')); }
};

function mkEl(id) {
  return {
    id, value: '', textContent: '', innerHTML: '', disabled: false, checked: false, step: '',
    placeholder: '', max: '', dataset: {}, options: [], selectedIndex: -1, style: {},
    classList: {
      _s: new Set(),
      add(c) { this._s.add(c); }, remove(c) { this._s.delete(c); },
      contains(c) { return this._s.has(c); },
      toggle(c, on) { if (on === undefined) { on = !this._s.has(c); } on ? this._s.add(c) : this._s.delete(c); return on; },
    },
    closest() { return null; }, focus() {}, addEventListener() {},
  };
}

const ID_LIST = [
  'supRoot', 'supBookInModal', 'supBookInMsg', 'supBiProduct', 'supBiScanBlock', 'supBiScanLabel',
  'supBiScanInput', 'supBiScanHint', 'supBiStaged', 'supBiCountLabel', 'supBiQtyLabel',
  'supBiManualBtn', 'supBiExpected', 'supBiExpectedLabel', 'supBiExpectedHint', 'supBiPiecesBlock',
  'supBiPieces', 'supBiPiecesLabel', 'supBiCost', 'supBiDate', 'supBiSource', 'supBiBankField', 'supBiBank',
  'supBiStockOnly', 'supBiNote', 'supBiSave', 'supTakeOutModal', 'supToMsg', 'supToTitle', 'supToBody',
  'supToConfirm', 'supToScan', 'supToWarn', 'supToQuote', 'supToQty', 'supStockPane',
  'supPaneStock', 'supPaneBatches', 'supPaneTakeouts', 'supPaneHistory', 'supTabStock', 'supTabTakeouts', 'supTakeoutList',
];

const CATALOGUE = {
  success: true,
  products: [
    {id: 1, name: 'NF Bags Large', mode: 'weight', plu: 178, barcode: null, is_active: 1,
     expense_config_id: 7, has_stock: true, stock_value: 50340},
    {id: 4, name: 'Vaccum Seal Bags 8x12', mode: 'scan', plu: null, barcode: '176', is_active: 1,
     expense_config_id: 7, has_stock: false, stock_value: 0},
  ],
  expense_categories: [{id: 7, name: 'Packaging - Bags', business_unit_id: 1}],
  payment_sources: [{id: 2, account_name: 'Online Bank', display_name: 'Online Bank',
                     is_default: true, is_online: true, preferred_bank_id: 3}],
  banks: [{id: 3, short_code: 'HBL'}],
};

const PACKET_REPLY = {
  success: true,
  product: {id: 4, name: 'Vaccum Seal Bags 8x12', mode: 'scan', expense_category: 'Packaging - Bags'},
  packet: {id: 901, qty: 1, qty_label: '1 packet', cost: 450, batch_date: '25-Sep', free: false},
  on_shelf: 10, on_shelf_label: '10 packets', scanned_barcode: '2000176000017',
};

function boot(canManage, hooks = {}) {
  const els = {};
  ID_LIST.forEach((id) => { els[id] = mkEl(id); });
  els.supRoot.dataset.canManage = canManage ? '1' : '0';
  const posted = [];
  const sandbox = {
    console, confirm: () => true, prompt: () => null, alert: () => {},
    location: {reload() { sandbox.__reloaded = (sandbox.__reloaded || 0) + 1; }},
    __reloaded: 0,
    document: {
      getElementById: (id) => els[id] || null,
      querySelector: (s) => (s.indexOf('csrf') >= 0 ? {content: 'tok'} : null),
      querySelectorAll: () => [], addEventListener: () => {},
    },
    setTimeout: (fn) => { fn(); return 0; },
    fetch: (url, opts) => {
      const body = opts && opts.body ? JSON.parse(opts.body) : null;
      posted.push({url, body});
      const reply = (d, okFlag = true) => Promise.resolve({ok: okFlag, json: () => Promise.resolve(d)});
      if (hooks.fetch) { const h = hooks.fetch(url, body, reply); if (h) { return h; } }
      if (url.indexOf('/supplies/products') >= 0 && (!opts || opts.method !== 'POST')) { return reply(CATALOGUE); }
      if (url.indexOf('/supplies/resolve-scan') >= 0) { return reply({...PACKET_REPLY, scanned_barcode: body.barcode}); }
      if (url.indexOf('/supplies/take-out/quote') >= 0) {
        return canManage ? reply({...PACKET_REPLY, scanned_barcode: null})
                         : reply({success: false, code: 'scan_required', message: 'Scan the label'}, false);
      }
      if (url.indexOf('/supplies/take-out') >= 0) { return reply({success: true, message: 'Taken out.'}); }
      if (url.indexOf('/supplies/batches') >= 0) { return reply({success: true, message: 'Added.'}); }
      return reply({success: true});
    },
  };
  sandbox.sessionStorage = hooks.sessionStorage || {getItem: () => null, setItem() {}, removeItem() {}};
  sandbox.window = sandbox;
  sandbox.globalThis = sandbox;
  vm.createContext(sandbox);
  vm.runInContext(code, sandbox);
  return {w: sandbox, els, posted};
}

const tick = () => new Promise((r) => setImmediate(r));
const act = async (fn) => { await fn(); await tick(); await tick(); await tick(); };
const enter = (value) => ({key: 'Enter', preventDefault() {}, target: {value}});

(async () => {
  // ───────────────────────────────────────────────────────────────────────────────
  console.log('\n=== 1. book-in: a packet product is a typed COUNT ===');
  {
    const {w, els, posted} = boot(true);
    await act(() => w.supOpenBookIn());
    els.supBiProduct.value = '4';
    w.supBiProductChanged();
    ok('the scan block is hidden for a packet product', els.supBiScanBlock.classList.contains('sup-none'));
    ok('  ...and the count box is shown', !els.supBiPiecesBlock.classList.contains('sup-none'));
    ok('  ...labelled in PACKETS', els.supBiPiecesLabel.textContent === 'How many packets?',
       els.supBiPiecesLabel.textContent);

    els.supBiPieces.value = '10';
    els.supBiCost.value = '4500';
    els.supBiSource.value = '2';
    els.supBiSource.options = [{dataset: {online: '0'}}];
    els.supBiSource.selectedIndex = 0;
    w.supBiSourceChanged();
    await act(() => w.supBookIn());
    const sent = posted.filter((p) => p.url.indexOf('/supplies/batches') >= 0);
    ok('⭐ Save sends packet_count = 10', sent.length === 1 && sent[0].body.packet_count === 10, sent[0] && sent[0].body);
    ok('  ...and no packet list (nothing was scanned)', sent[0] && sent[0].body.packets === undefined);
    ok('  ...with the money', sent[0] && sent[0].body.total_cost === 4500);

    els.supBiPieces.value = '2.5';
    await act(() => w.supBookIn());
    ok('half a packet is refused before it is sent',
       posted.filter((p) => p.url.indexOf('/supplies/batches') >= 0).length === 1 &&
       /whole number/.test(els.supBookInMsg.textContent), els.supBookInMsg.textContent);

    els.supBiProduct.value = '1';
    w.supBiProductChanged();
    ok('a WEIGHED product still scans its labels in', !els.supBiScanBlock.classList.contains('sup-none') &&
       els.supBiPiecesBlock.classList.contains('sup-none'));
  }

  // ───────────────────────────────────────────────────────────────────────────────
  console.log('\n=== 2. store user: scan only ===');
  {
    const {w, els, posted} = boot(false);
    await act(() => w.supOpenTakeOut(4));
    ok('no "without scanning" button for the store team',
       els.supToBody.innerHTML.indexOf('supToWithoutScan') < 0);
    ok('no packet list to pick from', els.supToBody.innerHTML.indexOf('id="supToPacket"') < 0 && els.supToBody.innerHTML.indexOf('<select') < 0);
    ok('Take out is disabled until something is scanned', els.supToConfirm.disabled === true);

    await act(() => w.supTakeOut());
    ok('pressing it anyway sends nothing',
       posted.filter((p) => /\/supplies\/take-out$/.test(p.url)).length === 0);

    await act(() => w.supToPacketScanKey(enter('2000176000017')));
    ok('⭐ the scan is resolved by the server', posted.some((p) => p.url.indexOf('resolve-scan') >= 0 &&
       p.body.barcode === '2000176000017'));
    ok('  ...and the card says what is going: 1 packet, the money, what is on the shelf',
       els.supToQuote.innerHTML.indexOf('1 packet') >= 0 && els.supToQuote.innerHTML.indexOf('450') >= 0 &&
       els.supToQuote.innerHTML.indexOf('10 packets') >= 0, els.supToQuote.innerHTML);
    ok('  ...and Take out is enabled', els.supToConfirm.disabled === false);

    await act(() => w.supTakeOut());
    const sent = posted.filter((p) => /\/supplies\/take-out$/.test(p.url));
    ok('⭐ the take-out carries the packet AND the code that was scanned',
       sent.length === 1 && sent[0].body.packet_id === 901 && sent[0].body.scanned_barcode === '2000176000017' &&
       sent[0].body.source === 'scan', sent[0] && sent[0].body);

    // a weighed product: EXACTLY as before (owner, Sep-26) — the store team may type a weight
    await act(() => w.supOpenTakeOut(1));
    ok('⭐ weighed product: the store team can TYPE a weight again (as before)',
       !/readonly/.test(els.supToBody.innerHTML), els.supToBody.innerHTML);
    ok('  ...with the same placeholder as before',
       els.supToBody.innerHTML.indexOf('Scan the label, or type the weight below') >= 0);
  }

  // ───────────────────────────────────────────────────────────────────────────────
  console.log('\n=== 3. manager: may take one out without scanning ===');
  {
    const {w, els, posted} = boot(true);
    await act(() => w.supOpenTakeOut(4));
    ok('the "Take one out without scanning" button is there',
       els.supToBody.innerHTML.indexOf('supToWithoutScan') >= 0);

    await act(() => w.supToWithoutScan());
    ok('it asks the server for the next packet (a quote, not a take-out)',
       posted.some((p) => p.url.indexOf('/take-out/quote') >= 0 && p.body.product_id === 4));
    ok('  ...shows it, marked as not scanned',
       els.supToQuote.innerHTML.indexOf('1 packet') >= 0 && els.supToQuote.innerHTML.indexOf('without scanning') >= 0,
       els.supToQuote.innerHTML);

    await act(() => w.supTakeOut());
    const sent = posted.filter((p) => /\/supplies\/take-out$/.test(p.url));
    ok('⭐ posted as MANUAL, with no scanned code',
       sent.length === 1 && sent[0].body.packet_id === 901 && sent[0].body.source === 'manual' &&
       sent[0].body.scanned_barcode === undefined, sent[0] && sent[0].body);

    await act(() => w.supOpenTakeOut(1));
    ok('weighed product: a manager may still type the weight',
       !/id="supToQty"[^>]*readonly/.test(els.supToBody.innerHTML));
  }

  // ───────────────────────────────────────────────────────────────────────────────
  console.log('\n=== 4. Sep-26 review fixes on the page ===');
  {
    // (a) the server asks for a confirmation the page had not shown: the PRICE LINE stays
    const QUOTE = {success: true, pooled: true, product: {id: 1, name: 'NF Bags Large'}, qty: 1.5,
      qty_label: '1.5 kg', cost: 1376.53, free: false, pool_label: '50.6 kg', scanned_barcode: '2000178015002', warnings: []};
    const {w, els, posted} = boot(false, {fetch: (url, body, reply) => {
      if (url.indexOf('resolve-scan') >= 0) { return reply(QUOTE); }
      if (/\/supplies\/take-out$/.test(url)) {
        return reply({success: false, code: 'needs_confirmation', message: 'Same label',
                      warnings: [{code: 'same_label', title: 'This label went out already', message: 'Same label'}]}, false);
      }
      return null;
    }});
    await act(() => w.supOpenTakeOut(1));
    await act(() => w.supToScanKey(enter('2000178015002')));
    await act(() => w.supTakeOut());
    ok('⭐ a late warning keeps the real price on screen (it showed "Rs 0")',
       els.supToQuote.innerHTML.indexOf('1,377') >= 0 && els.supToQuote.innerHTML.indexOf('Rs 0') < 0, els.supToQuote.innerHTML);
    ok('  ...and shows the warning', els.supToWarn.innerHTML.indexOf('This label went out already') >= 0);
    await act(() => w.supTakeOut());
    const sent = posted.filter((p) => /\/supplies\/take-out$/.test(p.url));
    ok('  ...the second press confirms it', sent.length === 2 && sent[1].body.confirmed_warnings.indexOf('same_label') >= 0);

    // (b) typing a new figure forgets the old figure's warnings at once
    els.supToQty.value = '2';
    w.supToQuoteSoon();
    ok('⭐ typing a new weight clears the old warnings immediately', els.supToWarn.innerHTML === '');
  }
  {
    // (c) a dropped connection is said out loud
    const {w, els} = boot(true, {fetch: (url) => (url.indexOf('resolve-scan') >= 0 ? Promise.reject(new Error('offline')) : null)});
    await act(() => w.supOpenTakeOut(4));
    await act(() => w.supToPacketScanKey(enter('2000176000017')));
    ok('⭐ a network failure on a scan says so (it showed nothing)', /Could not reach the server/.test(els.supToMsg.textContent),
       els.supToMsg.textContent);
  }
  {
    // (d) after a delete, the page comes back on the Take-outs tab
    const store = {};
    const ss = {getItem: (k) => (k in store ? store[k] : null), setItem: (k, v) => { store[k] = v; }, removeItem: (k) => { delete store[k]; }};
    const {w} = boot(true, {sessionStorage: ss, fetch: (url, body, reply) => (/\/delete$/.test(url) ? reply({success: true, message: 'Deleted.'}) : null)});
    await act(() => w.supDeleteTakeout(77, '1 packet'));
    ok('⭐ a delete reloads back onto the Take-outs tab', store.supTab === 'takeouts' && w.__reloaded === 1, store);
    const again = boot(true, {sessionStorage: ss});
    await act(() => Promise.resolve());
    ok('  ...which the next page load opens (and forgets)',
       !again.els.supPaneTakeouts.classList.contains('sup-none') &&
       again.els.supPaneStock.classList.contains('sup-none') && store.supTab === undefined);
  }
  console.log('\n' + (fail ? `  ${fail} FAILED, ${pass} passed` : `  all ${pass} checks passed`) + '\n');
  process.exit(fail ? 1 : 0);
})();
