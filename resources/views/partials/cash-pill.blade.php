{{--
  💵 The cash pill (September 2026).

  Owner's brief, after a Rs 150,000 vendor payment was posted against NF Cash from a phone
  by mistake and only surfaced days later: "a floating icon like the invoices one but with a
  cash symbol, alerting Shabib of cash transactions done by people other than him — last 10
  records — and once he views it, it's read, while the icon shows unread."
  Sep-29: "last 10 records" became the last 7 DAYS, headed by the day each entry was typed,
  with "Show earlier days" for the next block (see LedgerWatchService::recent).

  This blocks nothing. Taimur is allowed to make that payment and it was correctly
  auto-approved; the fix is that the same event now ARRIVES instead of having to be excavated.

  Modelled deliberately on partials/day-review-pill.blade.php — same right-edge tab, same
  drag-to-move, same count-only poll, same "hide for this session". Two bulbs that behave
  differently would be two things to learn.

  ⚠ Placement: bottom-LEFT is the checkout-stuck stack, bottom-RIGHT is `nfCornerStack`
  (bike meter, service, tickets, workshop) and the right edge's centre is the day-review
  bulb. This one defaults BELOW that bulb and remembers its own position separately, so the
  two never land on top of each other and neither has to know about the other.

  Included from layouts/app.blade.php, so it is on every page. It renders nothing for anyone
  who is not the KEEPER of a till ("Holds the cash" on the account page): the endpoint answers
  0 and the pill stays hidden — the same "no access → no bulb" contract, which is why this
  needs no permission. Today that is Shabib, for NF Cash.
--}}
@auth
@once
{{-- ⚠⚠ A PLAIN <style>, deliberately NOT @push('custom_css'). This partial is included from
     layouts/app.blade.php *inside the body*, and the head's @stack('custom_css') has already
     been rendered by then — a push from here is silently dropped and the drawer renders as
     unstyled text in the page flow. Every class carries the `nfcp` prefix. --}}
<style>
  #nfCpPill {
    position: fixed; right: 0; top: 50%;
    z-index: 10984; display: none; align-items: center; gap: 7px;
    background: #ecfdf5; color: #047857; border: 1px solid #6ee7b7; border-right: none;
    border-radius: 10px 0 0 10px; padding: 9px 11px 9px 10px; cursor: grab;
    font-size: 12.5px; font-weight: 800; box-shadow: 0 2px 10px rgba(15,23,42,.10);
    font-family: inherit; touch-action: none; user-select: none;
  }
  #nfCpPill:hover { background: #d1fae5; }
  #nfCpPill.nfcp-dragging { cursor: grabbing; opacity: .9; box-shadow: 0 6px 18px rgba(15,23,42,.22); }
  #nfCpPill .nfcp-grip { color: #6ee7b7; font-size: 11px; letter-spacing: -1px; margin-right: -2px; }
  #nfCpPill .nfcp-n {
    background: #047857; color: #fff; border-radius: 999px;
    min-width: 19px; height: 19px; line-height: 19px; text-align: center;
    font-size: 11px; font-weight: 800; padding: 0 5px;
  }
  /* One pulse when the number GOES UP. Never a loop — a thing that blinks forever is a
     thing a manager learns to ignore. */
  @keyframes nfCpNudge { 0% { transform: scale(1); } 45% { transform: scale(1.13); } 100% { transform: scale(1); } }
  #nfCpPill.nfcp-nudge { animation: nfCpNudge .5s ease-in-out 2; }

  #nfCpWrap { position: fixed; inset: 0; z-index: 10994; display: none; }
  #nfCpWrap.open { display: block; }
  #nfCpScrim { position: absolute; inset: 0; background: rgba(15,23,42,.35); }
  #nfCpPanel {
    position: absolute; top: 0; right: 0; bottom: 0; width: 420px; max-width: 94vw;
    background: #fff; box-shadow: -8px 0 28px rgba(15,23,42,.18);
    display: flex; flex-direction: column;
  }
  #nfCpHead { display: flex; align-items: center; gap: 8px; padding: 12px 14px; border-bottom: 1px solid #eef0f2; }
  #nfCpHead h3 { margin: 0; font-size: 14.5px; font-weight: 800; color: #0f172a; flex: 1; }
  .nfcp-x { cursor: pointer; color: #94a3b8; font-size: 20px; line-height: 1; padding: 2px 6px; }
  .nfcp-link { cursor: pointer; color: #6b7280; font-size: 11.5px; font-weight: 600; text-decoration: underline dotted; white-space: nowrap; }
  .nfcp-link:hover { color: #047857; }
  #nfCpNote { font-size: 11.5px; color: #6b7280; line-height: 1.5; padding: 9px 12px; background: #fafbfc; border-bottom: 1px solid #eef0f2; }
  #nfCpBody { overflow-y: auto; padding: 10px 12px 26px; flex: 1; }
  .nfcp-card { border: 1px solid #eef0f2; border-radius: 10px; padding: 10px 12px; margin-bottom: 8px; text-decoration: none; display: block; color: inherit; }
  .nfcp-card:hover { border-color: #6ee7b7; }
  .nfcp-card.unread { border-left: 3px solid #047857; background: #f6fffb; }
  .nfcp-top { display: flex; align-items: baseline; gap: 8px; }
  .nfcp-amt { font-size: 14px; font-weight: 800; white-space: nowrap; }
  .nfcp-amt.out { color: #b91c1c; }
  .nfcp-amt.in { color: #047857; }
  .nfcp-type { font-size: 12.5px; font-weight: 700; color: #0f172a; flex: 1; min-width: 0;
               overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .nfcp-dot { width: 7px; height: 7px; border-radius: 50%; background: #047857; flex: none; }
  .nfcp-desc { font-size: 11.5px; color: #475569; margin-top: 2px; line-height: 1.45;
               overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .nfcp-meta { font-size: 11px; color: #94a3b8; font-weight: 600; margin-top: 3px; }
  .nfcp-meta b { color: #475569; font-weight: 700; }
  .nfcp-back { font-size: 10.5px; font-weight: 700; color: #b45309; margin-top: 3px; }
  .nfcp-empty { padding: 34px 12px; text-align: center; color: #9ca3af; font-size: 12.5px; }
  /* One heading per TYPED day. Sticky, so while he scrolls a long day he still knows which
     day he is in. top:-10px cancels #nfCpBody's top padding so it sits flush when stuck. */
  .nfcp-day {
    position: sticky; top: -10px; z-index: 1; background: #fff;
    display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap;
    padding: 9px 2px 6px; margin: 0 0 6px; border-bottom: 1px solid #eef0f2;
  }
  .nfcp-day-name { font-size: 12.5px; font-weight: 800; color: #0f172a; flex: 1; }
  .nfcp-day-sum { font-size: 11px; font-weight: 700; color: #64748b; white-space: nowrap; }
  .nfcp-day-sum .out { color: #b91c1c; }
  .nfcp-day-sum .in { color: #047857; }
  .nfcp-gap { font-size: 11px; color: #9ca3af; text-align: center; padding: 4px 0 10px; }
  .nfcp-more {
    display: block; width: 100%; margin: 6px 0 4px; padding: 9px 12px; cursor: pointer;
    background: #f0fdf4; color: #047857; border: 1px solid #bbf7d0; border-radius: 9px;
    font-size: 12.5px; font-weight: 700; font-family: inherit;
  }
  .nfcp-more:hover { background: #dcfce7; }
  .nfcp-more[disabled] { opacity: .6; cursor: default; }
  .nfcp-end { font-size: 11px; color: #9ca3af; text-align: center; padding: 8px 0 2px; }
  #nfCpFoot { padding: 10px 14px; border-top: 1px solid #eef0f2; font-size: 12px; }
  #nfCpFoot a { color: #047857; font-weight: 700; text-decoration: none; }
  #nfCpFoot a:hover { text-decoration: underline; }
</style>

<button type="button" id="nfCpPill" title="Money moved on your accounts by someone else — drag me up or down">
  <span class="nfcp-grip" aria-hidden="true">⋮⋮</span>
  💵 <span class="nfcp-n" id="nfCpCount">0</span>
</button>

<div id="nfCpWrap">
  <div id="nfCpScrim"></div>
  <div id="nfCpPanel">
    <div id="nfCpHead">
      <h3>Cash moved by others</h3>
      <span class="nfcp-link" id="nfCpHide" title="Put it away until you next sign in">hide for now</span>
      <span class="nfcp-x" id="nfCpMin" title="Close — the count stays on the side">–</span>
    </div>
    <div id="nfCpNote"></div>
    <div id="nfCpBody"></div>
    <div id="nfCpFoot"></div>
  </div>
</div>

<script>
(function () {
  'use strict';
  var pill = document.getElementById('nfCpPill');
  if (!pill) { return; }
  var wrap = document.getElementById('nfCpWrap');
  var body = document.getElementById('nfCpBody');
  var note = document.getElementById('nfCpNote');
  var foot = document.getElementById('nfCpFoot');
  var countEl = document.getElementById('nfCpCount');
  var csrfEl = document.querySelector('meta[name="csrf-token"]');
  var CSRF = csrfEl ? csrfEl.content : '';
  var URL_COUNT = @js(route('fin.hub.watch.count'));
  var URL_LIST  = @js(route('fin.hub.watch.list'));
  var URL_SEEN  = @js(route('fin.hub.watch.seen'));
  var URL_HUB   = @js(route('fin.hub.accounts'));
  var LAST = null;   // last count seen, so the nudge fires only on a RISE

  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }
  function money(n) {
    var v = Math.abs(Number(n) || 0);
    return 'Rs. ' + v.toLocaleString('en-PK', {minimumFractionDigits: 0, maximumFractionDigits: 0});
  }

  function setCount(n) {
    countEl.textContent = n;
    var wasHidden = pill.style.display !== 'flex';
    pill.style.display = (n > 0 && !hiddenThisSession()) ? 'flex' : 'none';
    // ⚠ Position it only once it is actually VISIBLE. A hidden element measures 0 high, so
    // placing it before this point puts it against a height of zero.
    if (n > 0 && wasHidden && !hiddenThisSession()) { placePill(); }
    if (LAST !== null && n > LAST) {
      pill.classList.remove('nfcp-nudge');
      void pill.offsetWidth;                 // restart the animation
      pill.classList.add('nfcp-nudge');
    }
    LAST = n;
  }

  function card(i) {
    var out = (Number(i.effect) || 0) < 0;
    var sign = out ? '−' : '+';
    return '<a class="nfcp-card' + (i.unread ? ' unread' : '') + '" href="' + esc(i.url) + '">' +
      '<div class="nfcp-top">' +
        (i.unread ? '<span class="nfcp-dot"></span>' : '') +
        '<span class="nfcp-type">' + esc(i.type) + '</span>' +
        '<span class="nfcp-amt ' + (out ? 'out' : 'in') + '">' + sign + ' ' + money(i.effect) + '</span>' +
      '</div>' +
      (i.description ? '<div class="nfcp-desc">' + esc(i.description) + '</div>' : '') +
      // Time only — the day is in the heading above the card.
      '<div class="nfcp-meta"><b>' + esc(i.who) + '</b>' +
        (i.source ? ' · ' + esc(i.source) : '') +
        ' · ' + esc(i.time || i.typed_at || '') +
        ' · ' + esc(i.account) + '</div>' +
      // Informational only. Backdating is routine here — it is worth SEEING next to
      // "somebody else's hand", not worth shouting about on its own.
      (i.days_backdated > 0
        ? '<div class="nfcp-back">dated ' + esc(i.shows_as) + ' · typed ' + i.days_backdated +
          ' day' + (i.days_backdated === 1 ? '' : 's') + ' later</div>'
        : '') +
    '</a>';
  }

  // ── Grouped by the day it was TYPED (Sep-29, owner: "segregate it by date") ────────────
  // The server sends whole days, newest first, 7 at a time, and never splits a day across
  // two pages — so a page can simply be appended, and each heading's total is the whole day.
  var NEXT = null;       // `before` for the next "Show earlier days"; null = nothing earlier
  var list = null;       // where day blocks go
  var tail = null;       // the button / "that's everything" line under them
  // ⭐ C11: the watermark the first page's dots were drawn against (before the open marked
  // everything read) — sent as `since` so earlier pages keep their dots. null = not known.
  var SEEN_SINCE = null;
  // ⚠ C12: bumped on every open. A "Show earlier days" (or a first page) still loading when
  // the drawer was closed and opened again belongs to the OLD drawer — its answer is dropped
  // instead of overwriting NEXT and appending under the new page.
  var GEN = 0;

  function daySum(items) {
    var out = 0, inn = 0;
    items.forEach(function (i) { var e = Number(i.effect) || 0; if (e < 0) { out += e; } else { inn += e; } });
    var parts = [];
    if (out) { parts.push('<span class="out">− ' + money(out) + '</span>'); }
    if (inn) { parts.push('<span class="in">+ ' + money(inn) + '</span>'); }
    return items.length + (items.length === 1 ? ' entry' : ' entries') + (parts.length ? ' · ' + parts.join(' · ') : '');
  }

  function daysHtml(items) {
    var html = '', i = 0;
    while (i < items.length) {
      var day = items[i].day, group = [];
      while (i < items.length && items[i].day === day) { group.push(items[i]); i++; }
      // ⚠ Each day in its OWN section: a sticky heading is held inside its parent, so the next
      // day pushes the old heading off. In one shared container they all stay stuck, stacked.
      html += '<section><div class="nfcp-day"><span class="nfcp-day-name">' + esc(group[0].day_label || day || '') + '</span>' +
              '<span class="nfcp-day-sum">' + daySum(group) + '</span></div>' +
              group.map(card).join('') + '</section>';
    }
    return html;
  }

  function renderTail() {
    tail.innerHTML = NEXT
      ? '<button type="button" class="nfcp-more">Show earlier days</button>'
      : '<div class="nfcp-end">That’s everything — older entries are in the Ledger Hub.</div>';
    var btn = tail.querySelector('.nfcp-more');
    if (btn) { btn.onclick = loadEarlier; }
  }

  function render(d) {
    var items = (d && d.items) || [];
    NEXT = (d && d.next_before) || null;
    SEEN_SINCE = (d && typeof d.seen_since === 'number') ? d.seen_since : null;
    if (!items.length && !NEXT) {
      note.textContent = '';
      body.innerHTML = '<div class="nfcp-empty">Nothing on your accounts but your own entries.</div>';
      foot.innerHTML = '';
      return;
    }
    note.innerHTML = 'Money moved on ' + (d.watching === 1 ? 'the till you hold' : 'the ' + d.watching + ' tills you hold')
      + ' by someone other than you — newest first, by the day it was typed. Your own entries, anything you approved, and order deliveries are not listed.';
    body.innerHTML = '<div></div><div></div>';
    list = body.firstChild;
    tail = body.lastChild;
    list.innerHTML = items.length ? daysHtml(items) : '<div class="nfcp-gap">Nothing in the last 7 days.</div>';
    renderTail();
    foot.innerHTML = '<a href="' + esc(URL_HUB) + '">Open the Ledger Hub →</a>';
  }

  async function loadEarlier() {
    var btn = tail.querySelector('.nfcp-more');
    if (!btn || !NEXT) { return; }
    var gen = GEN;
    btn.disabled = true;
    btn.textContent = 'Loading…';
    try {
      var res = await fetch(URL_LIST + '?before=' + encodeURIComponent(NEXT)
        + (SEEN_SINCE !== null ? '&since=' + encodeURIComponent(SEEN_SINCE) : ''),
        { headers: { 'Accept': 'application/json' } });
      if (gen !== GEN) { return; }            // the drawer was reopened meanwhile (C12)
      if (!res.ok) { throw new Error('HTTP ' + res.status); }
      var j = await res.json();
      if (gen !== GEN) { return; }
      list.insertAdjacentHTML('beforeend', daysHtml(j.items || []));
      NEXT = j.next_before || null;
      renderTail();
    } catch (e) {
      if (gen !== GEN) { return; }
      btn.disabled = false;
      btn.textContent = 'Could not load — tap to try again';
    }
  }

  async function open() {
    var gen = ++GEN;
    wrap.classList.add('open');
    body.innerHTML = '<div class="nfcp-empty">Loading…</div>';
    foot.innerHTML = '';
    NEXT = null;
    try {
      var res = await fetch(URL_LIST, { headers: { 'Accept': 'application/json' } });
      if (gen !== GEN) { return; }            // a newer open owns the drawer now (C12)
      if (!res.ok) { body.innerHTML = '<div class="nfcp-empty">Could not load.</div>'; return; }
      var j = await res.json();
      if (gen !== GEN) { return; }
      render(j);
      // ⭐ "Once he views it, it can be read." Marked at the SERVER's newest id, not the
      // newest in this list, so a row that landed while the drawer was opening is not
      // silently skipped past.
      await fetch(URL_SEEN, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF},
        body: JSON.stringify({last_seen_ledger_id: j.latest_id || null})
      });
      setCount(0);
    } catch (e) {
      if (gen !== GEN) { return; }
      body.innerHTML = '<div class="nfcp-empty">Could not load.</div>';
    }
  }
  function close() { wrap.classList.remove('open'); }

  async function poll() {
    try {
      var res = await fetch(URL_COUNT, { headers: { 'Accept': 'application/json' } });
      if (!res.ok) { return; }
      var j = await res.json();
      if (j.success) { setCount(Number(j.count) || 0); }
    } catch (e) { /* offline — leave the pill as it is */ }
  }

  // ── Drag it out of the way ───────────────────────────────────────────────────────────
  // Vertical only: it is an edge tab, and letting it wander horizontally would put it over
  // the page content it exists to stay clear of. Its own key, so moving this one never
  // moves the day-review bulb.
  var PILL_POS_KEY = 'nfCpPillTop';
  var DRAG = null;
  var PILL_USER_SET = false;

  function clampTop(t) {
    var h = pill.offsetHeight || 40;
    return Math.max(8, Math.min(window.innerHeight - h - 8, t));
  }
  // Default seat: just BELOW the day-review bulb's centred position, so the two do not
  // land on top of each other on a screen where both are showing.
  function defaultTop() { return (window.innerHeight - (pill.offsetHeight || 40)) / 2 + 46; }

  function placePill() {
    var saved = null;
    try { saved = localStorage.getItem(PILL_POS_KEY); } catch (e) { saved = null; }
    var top = (saved !== null && saved !== '') ? Number(saved) : NaN;
    PILL_USER_SET = isFinite(top);
    pill.style.top = clampTop(PILL_USER_SET ? top : defaultTop()) + 'px';
  }

  function dragStart(e) {
    var y = e.touches ? e.touches[0].clientY : e.clientY;
    DRAG = {startY: y, startTop: parseFloat(pill.style.top) || 0, moved: false};
    pill.classList.add('nfcp-dragging');
  }
  function dragMove(e) {
    if (!DRAG) { return; }
    var y = e.touches ? e.touches[0].clientY : e.clientY;
    var dy = y - DRAG.startY;
    if (Math.abs(dy) > 4) { DRAG.moved = true; }   // slack, so a shaky click still opens it
    pill.style.top = clampTop(DRAG.startTop + dy) + 'px';
    if (DRAG.moved && e.cancelable) { e.preventDefault(); }
  }
  // ⚠ A drag always ends in a click. This is what stops "move it out of the way" from
  // also opening the drawer.
  var JUST_DRAGGED = false;
  function dragEnd() {
    if (!DRAG) { return; }
    JUST_DRAGGED = DRAG.moved;
    var moved = DRAG.moved;
    DRAG = null;
    pill.classList.remove('nfcp-dragging');
    if (moved) {
      PILL_USER_SET = true;
      try { localStorage.setItem(PILL_POS_KEY, String(parseFloat(pill.style.top) || 0)); }
      catch (e) { /* private window — it just won't be remembered */ }
    }
  }

  pill.addEventListener('mousedown', dragStart);
  pill.addEventListener('touchstart', dragStart, {passive: true});
  document.addEventListener('mousemove', dragMove);
  document.addEventListener('touchmove', dragMove, {passive: false});
  document.addEventListener('mouseup', dragEnd);
  document.addEventListener('touchend', dragEnd);
  window.addEventListener('resize', function () {
    pill.style.top = clampTop(PILL_USER_SET ? (parseFloat(pill.style.top) || 0) : defaultTop()) + 'px';
  });

  pill.onclick = function () {
    if (JUST_DRAGGED) { JUST_DRAGGED = false; return; }
    open();
  };

  // ⚠ sessionStorage, NOT localStorage. "Put it away for now" must not mean "forever" —
  // a new browser session brings it straight back.
  var HIDE_KEY = 'nfCpHiddenSession';
  function hiddenThisSession() {
    try { return sessionStorage.getItem(HIDE_KEY) === '1'; } catch (e) { return false; }
  }
  var hideBtn = document.getElementById('nfCpHide');
  if (hideBtn) {
    hideBtn.onclick = function () {
      try { sessionStorage.setItem(HIDE_KEY, '1'); } catch (e) { /* private window */ }
      close();
      pill.style.display = 'none';
    };
  }
  document.getElementById('nfCpMin').onclick = close;
  document.getElementById('nfCpScrim').onclick = close;
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });

  poll();
  setInterval(poll, 60000);
})();
</script>
@endonce
@endauth
