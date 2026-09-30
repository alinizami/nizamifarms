// C11/C12 harness: drives the cash pill's REAL rendered script (cashpill.js, cut from the page
// rendered through the Laravel kernel) against a tiny fake DOM and a fetch we control.
const fs = require('fs');
const vm = require('vm');
const code = fs.readFileSync(__dirname + '/cashpill.js', 'utf8');

let pass = 0, fail = 0;
const ok = (w, c) => { if (c) { pass++; console.log('  ✓ ' + w); } else { fail++; console.log('  ✗ ' + w); } };

class El {
  constructor(id) { this.id = id; this._html = ''; this.style = {}; this.children = []; this.textContent = ''; this.classList = new Set(); this.classList.add = this.classList.add.bind(this.classList); this.classList.remove = this.classList.delete.bind(this.classList); this._btn = null; }
  set innerHTML(h) {
    this._html = h;
    this.children = h === '<div></div><div></div>' ? [new El('list'), new El('tail')] : [];
    this._btn = h.includes('nfcp-more') ? {disabled: false, textContent: 'Show earlier days', onclick: null} : null;
  }
  get innerHTML() { return this._html; }
  get firstChild() { return this.children[0]; }
  get lastChild() { return this.children[this.children.length - 1]; }
  insertAdjacentHTML(pos, h) { this._html += h; }
  querySelector(sel) { return sel === '.nfcp-more' ? this._btn : null; }
  addEventListener() {}
  get offsetHeight() { return 40; }
  get offsetWidth() { return 40; }
}
const els = {};
['nfCpPill', 'nfCpWrap', 'nfCpBody', 'nfCpNote', 'nfCpFoot', 'nfCpCount', 'nfCpHide', 'nfCpMin', 'nfCpScrim'].forEach(id => { els[id] = new El(id); });

const pending = [];
const calls = [];
const fetchFn = (url, opts) => new Promise(resolve => {
  calls.push({url, opts});
  pending.push({url, resolve: body => resolve({ok: true, status: 200, json: async () => body})});
});
const ctx = {
  document: {
    getElementById: id => els[id] || null,
    querySelector: () => ({content: 'csrf'}),
    addEventListener() {},
  },
  window: {innerHeight: 800, addEventListener() {}},
  localStorage: {getItem: () => null, setItem() {}},
  sessionStorage: {getItem: () => null, setItem() {}},
  setInterval() {}, fetch: fetchFn, console, Number, String, Math, JSON, Error, encodeURIComponent,
};
vm.createContext(ctx);
vm.runInContext(code, ctx);
const tick = () => new Promise(r => setImmediate(r));
const take = re => { const i = pending.findIndex(p => re.test(p.url)); if (i < 0) { throw new Error('no pending ' + re); } return pending.splice(i, 1)[0]; };
const item = (id, day, unread) => ({id, day, day_label: day, time: '10:00', effect: -100, type: 'Vendor payment', who: 'Taimur', account: 'NF Cash', url: '#', unread});
const tail = () => els.nfCpBody.lastChild;

(async () => {
  take(/watch\/count|watch.count|count/).resolve({success: true, count: 3});
  await tick();

  // ── open #1: first page (C11: seen_since comes back) ──
  els.nfCpPill.onclick();
  take(/list(?!.*before)/).resolve({success: true, items: [item(1, '2026-09-19', true)], watching: 1, latest_id: 900, next_before: '2026-09-18', seen_since: 500});
  await tick();
  take(/seen/).resolve({success: true}); await tick();
  ok('first page rendered with a "Show earlier days" button', !!tail().querySelector('.nfcp-more'));

  // ── "Show earlier days": passes since= ──
  tail().querySelector('.nfcp-more').onclick();
  const slow = take(/before=2026-09-18/);
  ok('C11: "Show earlier days" sends since= the first page\'s seen_since', /since=500/.test(slow.url));

  // ── close + reopen while it is still loading (C12) ──
  els.nfCpMin.onclick();
  els.nfCpPill.onclick();
  take(/list(?!.*before)/).resolve({success: true, items: [item(2, '2026-09-29', false)], watching: 1, latest_id: 901, next_before: '2026-09-25', seen_since: 900});
  await tick();
  take(/seen/).resolve({success: true}); await tick();
  const listNow = els.nfCpBody.firstChild;
  slow.resolve({success: true, items: [item(77, '2026-09-12', true)], next_before: '2026-09-01'});
  await tick(); await tick();
  ok('C12: the stale page is NOT appended under the new drawer', !listNow.innerHTML.includes('2026-09-12'));
  tail().querySelector('.nfcp-more').onclick();
  const next = take(/before=/);
  ok('C12: NEXT is the new page\'s (2026-09-25), not the stale one\'s (2026-09-01)', /before=2026-09-25/.test(next.url));
  ok('C11: …and it carries the NEW first page\'s seen_since (900)', /since=900/.test(next.url));
  next.resolve({success: true, items: [item(3, '2026-09-20', false)], next_before: null});
  await tick(); await tick();
  ok('a current "Show earlier days" still appends', listNow.innerHTML.includes('2026-09-20'));
  ok('…and ends with "That’s everything"', tail().innerHTML.includes('That’s everything'));

  // ── a first page from an older open must not overwrite a newer open (C12) ──
  els.nfCpPill.onclick();                       // open A
  const a = take(/list(?!.*before)/);
  els.nfCpPill.onclick();                       // open B
  const b = take(/list(?!.*before)/);
  b.resolve({success: true, items: [item(5, '2026-09-29', true)], watching: 1, latest_id: 950, next_before: null, seen_since: 901});
  await tick(); take(/seen/).resolve({success: true}); await tick();
  a.resolve({success: true, items: [item(4, '2026-08-01', true)], watching: 1, latest_id: 940, next_before: '2026-07-01', seen_since: 800});
  await tick(); await tick();
  ok('C12: a slower first page from an earlier open is dropped', !els.nfCpBody.firstChild.innerHTML.includes('2026-08-01') && tail().innerHTML.includes('That’s everything'));
  ok('…and it does not post a second "seen"', !pending.some(p => /seen/.test(p.url)));

  console.log(`\n${fail === 0 ? '✅' : '❌'}  ${pass} passed, ${fail} failed`);
  process.exit(fail === 0 ? 0 : 1);
})().catch(e => { console.log('💥', e.message); process.exit(1); });
