{{--
  The day-review bulb (September 2026).

  Owner's brief: "a notification like a bulb icon showing him that he needs to perform an
  action… this bubble keeps floating somewhere on the side of the screen where it doesn't
  impact his work". So: no popup, nothing that steals focus, and nothing that opens by
  itself. A small tab on the RIGHT EDGE with a count. Click it and a drawer slides in;
  minimise and the count is still there.

  ⚠ Placement is deliberate. Both bottom corners are already spoken for — bottom-LEFT is the
  checkout-stuck stack, bottom-RIGHT is `nfCornerStack` (bike meter, service, tickets,
  workshop). The right EDGE, vertically centred, is the only lane nothing else uses.

  Included from layouts/app.blade.php, so it is on every page rather than opted into
  page by page like the alert partials. It renders nothing at all for anyone without
  `manage_payroll`: the endpoint answers 0 and the pill stays hidden.
--}}
@once
{{-- ⚠⚠ A PLAIN <style>, deliberately NOT @push('custom_css').
     This partial is included from layouts/app.blade.php *inside the body*, and the head's
     @stack('custom_css') has already been rendered by then — a push from here is silently
     dropped and the drawer renders as unstyled text in the page flow. (The alert partials
     get away with pushing because they are included from a page's @section('content'),
     which Blade evaluates before the layout.) Every class below carries the `nfdr` prefix,
     so nothing here can collide with the global utility sheet. --}}
<style>
  /* ⚠ `top` is set from JS (the remembered position) and the element is NOT translated —
     an earlier version centred it with translateY(-50%), which fought the drag maths and
     the pulse animation, since both want the transform. Vertical centring is done once in
     JS instead, as a starting `top`. */
  #nfDrPill {
    position: fixed; right: 0; top: 50%;
    z-index: 10985; display: none; align-items: center; gap: 7px;
    background: #fffbeb; color: #b45309; border: 1px solid #fcd34d; border-right: none;
    border-radius: 10px 0 0 10px; padding: 9px 11px 9px 10px; cursor: grab;
    font-size: 12.5px; font-weight: 800; box-shadow: 0 2px 10px rgba(15,23,42,.10);
    font-family: inherit; touch-action: none; user-select: none;
  }
  #nfDrPill:hover { background: #fef3c7; }
  #nfDrPill.nfdr-dragging { cursor: grabbing; opacity: .9; box-shadow: 0 6px 18px rgba(15,23,42,.22); }
  #nfDrPill .nfdr-grip { color: #d19a3e; font-size: 11px; letter-spacing: -1px; margin-right: -2px; }
  #nfDrPill .nfdr-n {
    background: #b45309; color: #fff; border-radius: 999px;
    min-width: 19px; height: 19px; line-height: 19px; text-align: center;
    font-size: 11px; font-weight: 800; padding: 0 5px;
  }
  /* One pulse when the number GOES UP. Never a loop — a thing that blinks forever is a
     thing a manager learns to ignore. */
  @keyframes nfDrNudge { 0% { transform: scale(1); } 45% { transform: scale(1.13); } 100% { transform: scale(1); } }
  #nfDrPill.nfdr-nudge { animation: nfDrNudge .5s ease-in-out 2; }

  #nfDrWrap { position: fixed; inset: 0; z-index: 10995; display: none; }
  #nfDrWrap.open { display: block; }
  #nfDrScrim { position: absolute; inset: 0; background: rgba(15,23,42,.35); }
  #nfDrPanel {
    position: absolute; top: 0; right: 0; bottom: 0; width: 420px; max-width: 94vw;
    background: #fff; box-shadow: -8px 0 28px rgba(15,23,42,.18);
    display: flex; flex-direction: column;
  }
  #nfDrHead { display: flex; align-items: center; gap: 8px; padding: 12px 14px; border-bottom: 1px solid #eef0f2; }
  #nfDrHead h3 { margin: 0; font-size: 14.5px; font-weight: 800; color: #0f172a; flex: 1; }
  .nfdr-x { cursor: pointer; color: #94a3b8; font-size: 20px; line-height: 1; padding: 2px 6px; }
  .nfdr-link { cursor: pointer; color: #6b7280; font-size: 11.5px; font-weight: 600; text-decoration: underline dotted; white-space: nowrap; }
  .nfdr-link:hover { color: #b45309; }
  #nfDrBody { overflow-y: auto; padding: 10px 12px 26px; flex: 1; }
  #nfDrNote { font-size: 11.5px; color: #6b7280; line-height: 1.5; padding: 9px 12px; background: #fafbfc; border-bottom: 1px solid #eef0f2; }
  .nfdr-card { border: 1px solid #eef0f2; border-radius: 10px; padding: 11px 12px; margin-bottom: 9px; }
  .nfdr-card.stale { border-color: #fcd34d; background: #fffdf5; }
  .nfdr-who { font-size: 13px; font-weight: 800; color: #0f172a; }
  .nfdr-when { font-size: 11px; color: #9ca3af; font-weight: 600; }
  .nfdr-head { font-size: 12.5px; font-weight: 800; margin-top: 2px; }
  .nfdr-ev { font-size: 11px; color: #6b7280; line-height: 1.6; margin-top: 4px; }
  .nfdr-ev b { color: #111827; font-weight: 600; }
  .nfdr-warn { font-size: 11px; font-weight: 700; color: #b45309; margin-top: 3px; }
  .nfdr-acts { display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap; }
  .nfdr-btn { font-size: 11.5px; font-weight: 700; border-radius: 7px; padding: 5px 11px; cursor: pointer;
              border: 1px solid #e5e7eb; background: #f3f4f6; color: #6b7280; white-space: nowrap; }
  .nfdr-btn.ok { color: #047857; background: #ecfdf5; border-color: #a7f3d0; }
  .nfdr-btn:disabled { opacity: .5; cursor: not-allowed; }
  .nfdr-snooze { font-size: 11px; color: #9ca3af; cursor: pointer; text-decoration: underline dotted; margin-left: auto; align-self: center; }
  .nfdr-empty { padding: 34px 12px; text-align: center; color: #9ca3af; font-size: 12.5px; }
</style>

<button type="button" id="nfDrPill" title="Days waiting for you to check — drag me up or down">
  <span class="nfdr-grip" aria-hidden="true">⋮⋮</span>
  💡 <span class="nfdr-n" id="nfDrCount">0</span>
</button>

<div id="nfDrWrap">
  <div id="nfDrScrim"></div>
  <div id="nfDrPanel">
    <div id="nfDrHead">
      <h3>Days to check</h3>
      {{-- Two different "not now"s, deliberately distinct: minimise keeps the bulb on the
           side with its count, hide puts it away entirely until the next sign-in. Neither
           does anything to the days themselves — an unchecked day still counts in full. --}}
      <span class="nfdr-link" id="nfDrHide" title="Put the bulb away until you next sign in">hide for now</span>
      <span class="nfdr-x" id="nfDrMin" title="Minimise — the count stays on the side">–</span>
    </div>
    <div id="nfDrNote"></div>
    <div id="nfDrBody"></div>
  </div>
</div>

<script>
(function () {
  'use strict';
  var pill = document.getElementById('nfDrPill');
  if (!pill) { return; }
  var wrap = document.getElementById('nfDrWrap');
  var body = document.getElementById('nfDrBody');
  var note = document.getElementById('nfDrNote');
  var countEl = document.getElementById('nfDrCount');
  var csrfEl = document.querySelector('meta[name="csrf-token"]');
  var CSRF = csrfEl ? csrfEl.content : '';
  var LAST = null;      // last count seen, so the nudge fires only on a RISE
  var ITEMS = [];
  var SNOOZED = {};     // per-browser, per-day: "user|date|kind" -> true

  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }
  function hm(m) {
    m = Math.max(0, Number(m) || 0);
    var h = Math.floor(m / 60);
    return h > 0 ? (h + 'h ' + (m % 60) + 'm') : (m + 'm');
  }
  function key(i) { return i.user_id + '|' + i.date + '|' + i.kind; }

  // Snoozes live in this browser only and last until tomorrow. Deliberately NOT a server
  // dismissal: "not now" is a personal, same-day thing, and the work still has to be done.
  try {
    var raw = localStorage.getItem('nfDrSnooze');
    var parsed = raw ? JSON.parse(raw) : {};
    var today = new Date().toISOString().slice(0, 10);
    Object.keys(parsed).forEach(function (k) { if (parsed[k] === today) { SNOOZED[k] = true; } });
    localStorage.setItem('nfDrSnooze', JSON.stringify(
      Object.keys(SNOOZED).reduce(function (a, k) { a[k] = today; return a; }, {})));
  } catch (e) { SNOOZED = {}; }

  function snooze(i) {
    SNOOZED[key(i)] = true;
    try {
      var raw = localStorage.getItem('nfDrSnooze');
      var parsed = raw ? JSON.parse(raw) : {};
      parsed[key(i)] = new Date().toISOString().slice(0, 10);
      localStorage.setItem('nfDrSnooze', JSON.stringify(parsed));
    } catch (e) { /* private window — the snooze just won't survive a reload */ }
    render();
    setCount(visible().length);
  }

  function visible() { return ITEMS.filter(function (i) { return !SNOOZED[key(i)]; }); }

  function setCount(n) {
    countEl.textContent = n;
    var wasHidden = pill.style.display !== 'flex';
    // Put away for this session → stay away, however the count moves. The poll keeps running
    // so the number is right the moment the next session brings it back.
    pill.style.display = (n > 0 && !hiddenThisSession()) ? 'flex' : 'none';
    // ⚠ Position it only once it is actually VISIBLE. A hidden element measures 0 high, so
    // placing it before this point centres it against a height of zero.
    if (n > 0 && wasHidden && !hiddenThisSession()) { placePill(); }
    if (LAST !== null && n > LAST) {
      pill.classList.remove('nfdr-nudge');
      void pill.offsetWidth;               // restart the animation
      pill.classList.add('nfdr-nudge');
    }
    LAST = n;
  }

  function evidence(i) {
    var m = i.meta || {};
    var out = [];
    if (i.kind === 'late') {
      if (m.shift_start || m.login) {
        out.push('shift <b>' + esc(m.shift_start || '?') + '</b> → in <b>' + esc(m.login || '?') + '</b>');
      }
    } else {
      if (m.login || m.logout) {
        out.push('in <b>' + esc(m.login || '?') + '</b> → out <b>' + esc(m.logout || '?') + '</b>');
      }
      if (m.worked_minutes) {
        out.push('worked <b>' + hm(m.worked_minutes) + '</b> against a ' + hm(m.target_minutes) + ' target');
      }
      if (m.orders) {
        out.push('📦 ' + m.orders + ' order' + (m.orders === 1 ? '' : 's')
          + ((m.first_delivery && m.last_delivery) ? ' · ' + esc(m.first_delivery) + ' → ' + esc(m.last_delivery) : ''));
      }
    }
    var html = out.length ? '<div class="nfdr-ev">' + out.join('<br>') + '</div>' : '';
    // The two things that make a figure worth doubting, said plainly rather than hidden.
    if (m.counted && m.counted.time) {
      html += '<div class="nfdr-warn">🔓 checkout used a bypass · counted to the last delivery at '
        + esc(m.counted.time) + '</div>';
    }
    if (i.stale) {
      html += '<div class="nfdr-warn">🔁 this day changed after it was checked</div>';
    }
    // Oct-2026 (owner ruling): allowed, but said BEFORE he decides — pay is already processed.
    if (i.pay_processed) {
      html += '<div class="nfdr-warn" style="color:#b91c1c;">💰 ' + esc(i.pay_processed) + '</div>';
    }
    return html;
  }

  function card(i, idx) {
    var d = new Date(i.date + 'T00:00:00');
    var when = d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
    var head = i.kind === 'late'
      ? '<span style="color:#b45309;">' + hm(i.minutes) + ' late</span>'
      : '<span style="color:#047857;">' + hm(i.minutes) + ' overtime</span>';
    var acts = i.not_ready
      ? '<div class="nfdr-ev" style="font-style:italic;">' + esc(i.not_ready) + ' — check this once the day is closed</div>'
      : '<div class="nfdr-acts">'
        + (i.kind === 'late'
            ? '<button type="button" class="nfdr-btn ok" data-nfdr="' + idx + '" data-v="verified">Verify</button>'
              + '<button type="button" class="nfdr-btn" data-nfdr="' + idx + '" data-v="waived">Waive minutes…</button>'
            : '<button type="button" class="nfdr-btn ok" data-nfdr="' + idx + '" data-v="verified">Verify</button>'
              + '<button type="button" class="nfdr-btn" data-nfdr="' + idx + '" data-v="adjusted">Adjust…</button>'
              + '<button type="button" class="nfdr-btn" data-nfdr="' + idx + '" data-v="waived">Not overtime…</button>')
        + '<span class="nfdr-snooze" data-nfdrsnooze="' + idx + '">not now</span>'
        + '</div>';
    return '<div class="nfdr-card' + (i.stale ? ' stale' : '') + '">'
      + '<div style="display:flex;align-items:baseline;gap:8px;">'
      + '<span class="nfdr-who">' + esc(i.fullname || '') + '</span>'
      + '<span class="nfdr-when">' + esc(when) + '</span></div>'
      + '<div class="nfdr-head">' + head + '</div>'
      + evidence(i) + acts + '</div>';
  }

  function render() {
    var list = visible();
    note.innerHTML = list.length
      ? 'Verifying changes nothing — it records that the figure is right. Only an adjustment '
        + 'or a waive moves a number, and a day you never get to still counts in full.'
      : '';
    body.innerHTML = list.length
      ? list.map(card).join('')
      : '<div class="nfdr-empty">Nothing waiting. Anything you skip still counts in full.</div>';
    body.querySelectorAll('[data-nfdr]').forEach(function (b) {
      b.onclick = function () { act(list[Number(b.getAttribute('data-nfdr'))], b.getAttribute('data-v')); };
    });
    body.querySelectorAll('[data-nfdrsnooze]').forEach(function (s) {
      s.onclick = function () { snooze(list[Number(s.getAttribute('data-nfdrsnooze'))]); };
    });
  }

  async function act(item, verdict) {
    if (!item) { return; }
    var payload = { user_id: item.user_id, date: item.date, kind: item.kind, verdict: verdict };
    // Oct-2026 (owner ruling): a change in a month whose pay is already processed goes
    // ahead only once he has read, in plain words, that the money will NOT move.
    if (verdict !== 'verified' && item.pay_processed
        && !confirm('⚠ PAY ALREADY PROCESSED\n\n' + item.pay_processed + '\n\nGo ahead anyway?')) { return; }
    if (verdict === 'adjusted') {
      var v = prompt('How many minutes of overtime did ' + item.fullname + ' really do on '
        + item.date + '?\n\nThe system counted ' + hm(item.minutes) + '.', String(item.minutes));
      if (v === null) { return; }
      if (!isFinite(Number(v)) || Number(v) < 0) { alert('Give the minutes as a number.'); return; }
      payload.minutes = Math.round(Number(v));
    }
    if (verdict === 'waived' && item.kind === 'late') {
      var w = prompt('How many of the ' + item.minutes + ' late minutes are being waived?', String(item.minutes));
      if (w === null) { return; }
      if (!isFinite(Number(w)) || Number(w) <= 0) { alert('Give the minutes as a number.'); return; }
      payload.waived = Math.round(Number(w));
    }
    if (verdict !== 'verified') {
      var why = prompt('Why? This is kept with the decision.', '');
      if (why === null) { return; }
      if (!String(why).trim()) { alert('A reason is needed.'); return; }
      payload.reason = String(why).trim();
    }
    body.querySelectorAll('.nfdr-btn').forEach(function (b) { b.disabled = true; });
    try {
      var res = await fetch('/hr/day-reviews/record', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
        body: JSON.stringify(payload)
      });
      var j = await res.json();
      if (!j.success) { throw new Error(j.message || 'Could not save that.'); }
      // Saved — and if this day's pay was already processed, the server says so again.
      if (j.warning) { alert('Saved.\n\n⚠ ' + j.warning); }
      await load();
    } catch (e) {
      alert(e.message || e);
      render();
    }
  }

  async function load() {
    try {
      var res = await fetch('/hr/day-reviews/pending?limit=60', { headers: { 'Accept': 'application/json' } });
      if (!res.ok) { setCount(0); return; }
      var j = await res.json();
      if (!j.success || !j.enabled) { setCount(0); return; }
      ITEMS = j.items || [];
      setCount(visible().length);
      if (wrap.classList.contains('open')) { render(); }
    } catch (e) { /* offline / no access — leave the pill as it is */ }
  }

  async function poll() {
    // The count only. The list itself is fetched when the drawer is opened, so a manager
    // who never opens it costs one small query a minute.
    try {
      var res = await fetch('/hr/day-reviews/pending-count', { headers: { 'Accept': 'application/json' } });
      if (!res.ok) { return; }
      var j = await res.json();
      if (j.success && j.enabled) { setCount(Number(j.count) || 0); }
    } catch (e) { /* ignore */ }
  }

  function open() { wrap.classList.add('open'); load().then(render); }
  function close() { wrap.classList.remove('open'); }

  // ── Drag it out of the way ───────────────────────────────────────────────────────────
  // Owner: "move it if it's blocking something". Vertical only — it is an edge tab, and
  // letting it wander horizontally would just put it over the page content it is meant to
  // stay clear of. The position is remembered per browser.
  var PILL_POS_KEY = 'nfDrPillTop';
  var DRAG = null;              // {startY, startTop, moved}

  function clampTop(t) {
    var h = pill.offsetHeight || 40;
    return Math.max(8, Math.min(window.innerHeight - h - 8, t));
  }

  // Has the manager actually chosen a position? Until he has, the pill stays centred and
  // FOLLOWS the window — placing it once against whatever height the viewport happened to
  // have (mid-load, or a pane still resizing) left it stuck near the top for no reason.
  var PILL_USER_SET = false;

  function centreTop() { return (window.innerHeight - (pill.offsetHeight || 40)) / 2; }

  function placePill() {
    var saved = null;
    try { saved = localStorage.getItem(PILL_POS_KEY); } catch (e) { saved = null; }
    var top = (saved !== null && saved !== '') ? Number(saved) : NaN;
    PILL_USER_SET = isFinite(top);
    pill.style.top = clampTop(PILL_USER_SET ? top : centreTop()) + 'px';
  }

  function dragStart(e) {
    var y = e.touches ? e.touches[0].clientY : e.clientY;
    DRAG = {startY: y, startTop: parseFloat(pill.style.top) || 0, moved: false};
    pill.classList.add('nfdr-dragging');
  }

  function dragMove(e) {
    if (!DRAG) { return; }
    var y = e.touches ? e.touches[0].clientY : e.clientY;
    var dy = y - DRAG.startY;
    // A few pixels of slack so a slightly shaky click still opens the drawer.
    if (Math.abs(dy) > 4) { DRAG.moved = true; }
    pill.style.top = clampTop(DRAG.startTop + dy) + 'px';
    if (DRAG.moved && e.cancelable) { e.preventDefault(); }
  }

  // ⚠ A drag always ends in a click. This flag is what stops "move the pill out of the way"
  // from also opening the drawer. Set on release, read (and cleared) by the click that follows.
  var JUST_DRAGGED = false;

  function dragEnd() {
    if (!DRAG) { return; }
    JUST_DRAGGED = DRAG.moved;
    var moved = DRAG.moved;
    DRAG = null;
    pill.classList.remove('nfdr-dragging');
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
  // Keep it on screen when the window is resized smaller.
  window.addEventListener('resize', function () {
    pill.style.top = clampTop(PILL_USER_SET ? (parseFloat(pill.style.top) || 0) : centreTop()) + 'px';
  });

  pill.onclick = function () {
    if (JUST_DRAGGED) { JUST_DRAGGED = false; return; }
    open();
  };

  // ── Hide until the next sign-in ──────────────────────────────────────────────────────
  // ⚠ sessionStorage, NOT localStorage. "Put it away for now" must not mean "put it away
  // forever" — the days are still waiting, and a bulb that never comes back is a bulb that
  // stops being a reminder. A new browser session brings it straight back.
  var HIDE_KEY = 'nfDrHiddenSession';
  function hiddenThisSession() {
    try { return sessionStorage.getItem(HIDE_KEY) === '1'; } catch (e) { return false; }
  }
  var hideBtn = document.getElementById('nfDrHide');
  if (hideBtn) {
    hideBtn.onclick = function () {
      try { sessionStorage.setItem(HIDE_KEY, '1'); } catch (e) { /* private window */ }
      close();
      pill.style.display = 'none';
    };
  }
  document.getElementById('nfDrMin').onclick = close;
  document.getElementById('nfDrScrim').onclick = close;
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });

  poll();
  setInterval(poll, 60000);
  // Other screens can nudge it after they change a day.
  document.addEventListener('nf:day-reviews-changed', function () { poll(); });
})();
</script>
@endonce
