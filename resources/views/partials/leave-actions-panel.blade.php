{{--
  THE leave-actions row renderer — shared by the payroll page and the attendance page.

  Why this file exists (Sep-6 2026): the attendance page carried a HAND-COPIED clone of
  payroll's panel. It had drifted — it had no absence branch at all, so it offered
  "Deduct / Keep leave" for an absence, posted a decision the service always refuses, and
  labelled a parked month "leaves deducted". Two copies of one screen is the bug; this is
  the single copy.

  It also fixes the thing that made the panel hard to read: a decided row used to keep all
  of its buttons (absence) or replace them with a blind two-state flip (overtime / late).
  Now ONE pattern everywhere — decided rows show a single chip with who and when, and
  "change" re-opens the very same buttons with the current one marked.

  All wording and every button label comes from the SERVER (`choices`, `decided_label`),
  so no surface can invent its own vocabulary. See PayrollService::leaveActionShape().

  Usage:
      NFLeaveActions.render(bodyEl, items, {
        monthOpen, monthLabel, onDecide(item, choice), onDrill(item), header
      });
  `items` is a flat list of actions, each carrying user_id / fullname / paid.
--}}
@once
@push('custom_css')
<style>
  /* Every class is prefixed — a bare name here would collide with the global utility sheet. */
  .nfla-row   { padding: 11px 14px; border-bottom: 1px solid #f6f7f8; }
  .nfla-name  { font-size: 13px; font-weight: 700; color: #111827; }
  .nfla-paid  { font-size: 10px; font-weight: 700; color: #6b7280; background: #f3f4f6; border-radius: 5px; padding: 1px 6px; margin-left: 6px; }
  .nfla-head  { font-size: 12.5px; font-weight: 700; margin-top: 2px; }
  .nfla-why   { font-size: 11px; color: #6b7280; line-height: 1.5; margin-top: 2px; }
  .nfla-link  { color: #4f46e5; cursor: pointer; text-decoration: underline dotted; font-weight: 600; white-space: nowrap; }
  .nfla-acts  { display: flex; gap: 6px; margin-top: 7px; flex-wrap: wrap; align-items: center; }
  .nfla-btn   { font-size: 11px; font-weight: 700; border-radius: 6px; padding: 4px 11px; cursor: pointer; border: 1px solid transparent; white-space: nowrap; background: #f3f4f6; color: #6b7280; border-color: #e5e7eb; }
  .nfla-btn:disabled { opacity: .5; cursor: not-allowed; }
  .nfla-btn.nfla-t-good   { color: #047857; background: #ecfdf5; border-color: #a7f3d0; }
  .nfla-btn.nfla-t-danger { color: #b91c1c; background: #fef2f2; border-color: #fecaca; }
  .nfla-btn.nfla-t-hold   { color: #b45309; background: #fffbeb; border-color: #fcd34d; }
  .nfla-btn.nfla-t-muted  { color: #6b7280; background: #f3f4f6; border-color: #e5e7eb; }
  /* The one in force, while the buttons are open for a change. */
  .nfla-btn.nfla-cur { outline: 2px solid currentColor; outline-offset: 1px; font-weight: 800; }
  .nfla-chip  { font-size: 11px; font-weight: 700; border-radius: 6px; padding: 3px 9px; display: inline-block; }
  .nfla-chip.nfla-t-good   { color: #047857; background: #ecfdf5; }
  .nfla-chip.nfla-t-danger { color: #b91c1c; background: #fef2f2; }
  .nfla-chip.nfla-t-hold   { color: #b45309; background: #fffbeb; }
  .nfla-chip.nfla-t-muted  { color: #6b7280; background: #f3f4f6; }
  .nfla-who   { font-size: 10.5px; color: #9ca3af; }
  .nfla-wait  { font-size: 11px; color: #6b7280; font-style: italic; }
  .nfla-warn  { font-size: 10.5px; color: #b45309; margin-top: 3px; }
  .nfla-hint  { font-size: 10.5px; color: #9ca3af; margin-top: 4px; width: 100%; }
  .nfla-empty { padding: 40px; text-align: center; color: #9ca3af; }

  /* ⏱ Time-bank card (Oct-2026) — one calm card per person: plain words, no formulas. */
  .nftb        { padding: 12px 14px; border-bottom: 1px solid #f1f2f4; }
  .nftb-top    { display: flex; align-items: baseline; gap: 8px; }
  .nftb-name   { font-size: 13.5px; font-weight: 700; color: #111827; }
  .nftb-bal    { margin-left: auto; font-size: 11.5px; color: #6b7280; white-space: nowrap; }
  .nftb-line   { font-size: 12px; color: #4b5563; margin-top: 3px; line-height: 1.45; }
  .nftb-cover  { display: flex; align-items: center; gap: 7px; margin-top: 8px; font-size: 12px; color: #374151; cursor: pointer; }
  .nftb-cover input { width: 15px; height: 15px; cursor: pointer; }
  .nftb-cover small { color: #9ca3af; }
  .nftb-result { font-size: 13px; font-weight: 700; color: #111827; margin-top: 7px; }
  .nftb-grp    { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 8px; }
  .nftb-glabel { font-size: 11px; font-weight: 700; color: #6b7280; min-width: 40px; }
  .nftb-opt    { font-size: 11.5px; font-weight: 600; border-radius: 999px; padding: 4px 12px; cursor: pointer; border: 1px solid #e5e7eb; background: #fff; color: #374151; }
  .nftb-opt:disabled { opacity: .45; cursor: not-allowed; }
  .nftb-opt.on { border-color: #4f46e5; background: #eef2ff; color: #3730a3; font-weight: 800; }
  .nftb-opt.on.nftb-red { border-color: #dc2626; background: #fef2f2; color: #b91c1c; }
  .nftb-foot   { display: flex; align-items: center; gap: 10px; margin-top: 10px; flex-wrap: wrap; }
  .nftb-link   { color: #4f46e5; cursor: pointer; font-size: 11.5px; font-weight: 600; text-decoration: underline dotted; }
  .nftb-go     { margin-left: auto; font-size: 12px; font-weight: 700; border-radius: 8px; padding: 6px 16px; border: 0; background: #4f46e5; color: #fff; cursor: pointer; }
  .nftb-go:disabled { opacity: .5; cursor: wait; }
  .nftb-chip   { display: inline-block; font-size: 11.5px; font-weight: 700; border-radius: 6px; padding: 3px 9px; }
  .nftb-chip.t-late  { color: #b45309; background: #fffbeb; }
  .nftb-chip.t-extra { color: #047857; background: #ecfdf5; }
  .nftb-chip.t-done  { color: #047857; background: #ecfdf5; }
  .nftb-chip.t-muted { color: #6b7280; background: #f3f4f6; }
  .nftb-who    { font-size: 10.5px; color: #9ca3af; margin-left: 6px; }
  .nftb-note   { font-size: 11px; color: #92400e; margin-top: 5px; }
  .nftb-waive  { display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap; }
  .nftb-waive input { border: 1px solid #d1d5db; border-radius: 6px; padding: 4px 8px; font-size: 12px; }
  .nftb-modal  { position: fixed; inset: 0; background: rgba(17,24,39,.45); display: flex; align-items: center; justify-content: center; z-index: 99990; padding: 16px; }
  .nftb-box    { background: #fff; border-radius: 12px; width: 100%; max-width: 520px; max-height: 88vh; overflow: auto; box-shadow: 0 20px 50px rgba(0,0,0,.25); }
  .nftb-boxhd  { display: flex; align-items: center; padding: 12px 14px; border-bottom: 1px solid #eef0f2; font-weight: 700; font-size: 14px; }
  .nftb-x      { margin-left: auto; cursor: pointer; color: #9ca3af; font-size: 18px; line-height: 1; }
</style>
@endpush

<script>
(function () {
  'use strict';
  if (window.NFLeaveActions) { return; }

  var esc = function (s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  };

  var ITEMS = [];
  var OPTS = {};
  var OPEN_CHANGE = {};   // idx -> true while a decided row has its buttons re-opened

  var tone = function (t) { return 'nfla-t-' + (t || 'muted'); };

  // An absence can always be decided (the days already happened); overtime and lateness
  // only once the month can no longer change.
  function decidable(item) {
    return item.kind === 'absence' || !OPTS.monthOpen;
  }

  function buttonsHtml(item, idx) {
    var cur = item.current_choice || null;
    var list = item.choices || [];
    var html = list.map(function (c) {
      var isCur = cur !== null && c.value === cur;
      return '<button type="button" class="nfla-btn ' + tone(c.tone) + (isCur ? ' nfla-cur' : '') +
        '" data-nfla-dec="' + idx + '" data-nfla-choice="' + esc(c.value) + '"' +
        (c.hint ? ' title="' + esc(c.hint) + '"' : '') + '>' + esc(c.label) + '</button>';
    }).join('');
    // Hints are what the tooltips used to hide. The one that costs money is always shown.
    var hint = '';
    list.forEach(function (c) {
      if (c.value === 'waive' && c.hint && /forfeit/i.test(c.hint)) {
        hint = '<div class="nfla-hint">⚠ ' + esc(c.hint) + '</div>';
      }
    });
    if (!hint && item.kind === 'overtime' && Number(item.will_cover || 0) > 0) {
      hint = '<div class="nfla-hint">⚠ ' + item.will_cover +
        ' of these will settle parked absences instead of becoming leave</div>';
    }
    return html + hint;
  }

  function actsHtml(item, idx) {
    var settled = item.status && item.status !== 'pending';

    if (!settled) {
      if (!decidable(item)) {
        // No dead greyed pills. A disabled button with a tooltip reads as "broken";
        // a sentence reads as "not yet".
        return '<span class="nfla-wait">Can be decided once ' + esc(OPTS.monthLabel || 'the month') + ' ends</span>';
      }
      return buttonsHtml(item, idx) +
        (item.kind === 'absence'
          ? '<span style="font-size:10.5px;color:#b91c1c;">undecided — Pay will deduct it</span>'
          : '');
    }

    // Decided. One chip, always with who and when — then an explicit way back in.
    if (OPEN_CHANGE[idx]) {
      return buttonsHtml(item, idx) +
        '<span class="nfla-link" data-nfla-cancel="' + idx + '">cancel</span>';
    }
    var who = [item.decided_by ? 'by ' + esc(item.decided_by) : '', item.decided_at || '']
      .filter(Boolean).join(' · ');
    return '<span class="nfla-chip ' + tone(item.decided_tone) + '">' + esc(item.decided_label) + '</span>' +
      (who ? '<span class="nfla-who">' + who + '</span>' : '') +
      (decidable(item)
        ? '<span class="nfla-link" data-nfla-change="' + idx + '" style="margin-left:auto;">change ›</span>'
        : '');
  }

  function rowHtml(item, idx) {
    // ⏱ A time-bank item is its own card (NFTimeBank below) — mounted after paint.
    if (item.kind === 'time_bank') {
      return '<div data-nftb-slot="' + idx + '"></div>';
    }
    var col = item.kind === 'overtime' ? '#047857' : (item.kind === 'absence' ? '#b91c1c' : '#b45309');
    var paid = item.paid ? '<span class="nfla-paid">salary paid</span>' : '';
    var changed = item.changed
      ? '<div class="nfla-warn">⚠ recommended now: ' +
          (item.recommended_days > 0 ? '+' : '') + item.recommended_days +
          ' — this month was settled on a different figure.</div>'
      : '';
    return '<div class="nfla-row">' +
      '<div class="nfla-name">' + esc(item.fullname) + paid + '</div>' +
      '<div class="nfla-head" style="color:' + col + ';">' + esc(item.headline) + '</div>' +
      '<div class="nfla-why">' + esc(item.basis) + '<br>' + esc(item.formula) +
        (OPTS.onDrill ? ' · <span class="nfla-link" data-nfla-why="' + idx + '">see the days ›</span>' : '') +
      '</div>' +
      changed +
      '<div class="nfla-acts">' + actsHtml(item, idx) + '</div>' +
    '</div>';
  }

  function wire(root) {
    root.querySelectorAll('[data-nfla-why]').forEach(function (w) {
      w.onclick = function () {
        var it = ITEMS[Number(w.getAttribute('data-nfla-why'))];
        if (it && OPTS.onDrill) { OPTS.onDrill(it); }
      };
    });
    root.querySelectorAll('[data-nfla-change]').forEach(function (c) {
      c.onclick = function () {
        OPEN_CHANGE[Number(c.getAttribute('data-nfla-change'))] = true;
        paint(root);
      };
    });
    root.querySelectorAll('[data-nfla-cancel]').forEach(function (c) {
      c.onclick = function () {
        delete OPEN_CHANGE[Number(c.getAttribute('data-nfla-cancel'))];
        paint(root);
      };
    });
    root.querySelectorAll('[data-nfla-dec]').forEach(function (b) {
      b.onclick = function () {
        var idx = Number(b.getAttribute('data-nfla-dec'));
        var it = ITEMS[idx];
        var choice = b.getAttribute('data-nfla-choice');
        if (!it || !OPTS.onDecide) { return; }
        // Re-picking what is already in force is a no-op, not a round trip.
        if (it.current_choice && it.current_choice === choice) {
          delete OPEN_CHANGE[idx];
          paint(root);
          return;
        }
        if (it.status && it.status !== 'pending') {
          var was = (it.decided_label || 'the current decision').replace(/^[✓✕🅿]\s*/, '');
          var to = (it.choices || []).filter(function (c) { return c.value === choice; })[0];
          if (!confirm('Change ' + it.fullname + '’s ' + it.headline + '?\n\n' +
                       'Now: ' + was + '\nChange to: ' + ((to && to.label) || choice))) { return; }
        }
        root.querySelectorAll('.nfla-btn').forEach(function (x) { x.disabled = true; });
        delete OPEN_CHANGE[idx];
        OPTS.onDecide(it, choice);
      };
    });
  }

  function paint(root) {
    root.innerHTML = (OPTS.header || '') +
      (ITEMS.length
        ? ITEMS.map(rowHtml).join('')
        : '<div class="nfla-empty">Nothing to decide here.</div>');
    if (OPTS.afterPaint) { OPTS.afterPaint(root); }
    wire(root);
    root.querySelectorAll('[data-nftb-slot]').forEach(function (slot) {
      var it = ITEMS[Number(slot.getAttribute('data-nftb-slot'))];
      if (it && window.NFTimeBank) {
        window.NFTimeBank.mount(slot, it.statement, {
          fullname: it.fullname, paid: it.paid,
          onDone: OPTS.onTimeBankDone, onDrill: OPTS.onDrill ? function () { OPTS.onDrill(it); } : null
        });
      }
    });
  }

  window.NFLeaveActions = {
    /** items = flat action list, each carrying user_id / fullname / paid. */
    render: function (root, items, opts) {
      ITEMS = items || [];
      OPTS = opts || {};
      OPEN_CHANGE = {};
      paint(root);
    },
    /** The items currently on screen (same indexes the data attributes use). */
    items: function () { return ITEMS; },
    esc: esc
  };
})();

/* =========================================================================================
 * ⏱ NFTimeBank — the ONE time-bank card (Oct-2026). Late + extra as one balance.
 * Every number and every word comes from TimeBankService (via /hr/payroll/time-bank/*):
 * ticking "use extra to cover late" or waiving minutes asks the SERVER again — this file
 * never divides by 540 or multiplies by the rate.
 *   NFTimeBank.mount(el, statement, {fullname, paid, onDone, onDrill})
 *   NFTimeBank.open(userId, month, fullname, onDone)   — modal (payroll row chip)
 * ========================================================================================= */
(function () {
  'use strict';
  if (window.NFTimeBank) { return; }
  var BASE = '/hr/payroll/time-bank';
  var esc = function (s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  };
  var csrf = function () {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
  };
  async function getJson(url) {
    var r = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
    return r.json();
  }
  async function postJson(url, body) {
    var r = await fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
      body: JSON.stringify(body)
    });
    return r.json();
  }

  function Card(el, st, o) {
    this.el = el; this.o = o || {}; this.busy = false;
    this.reset(st);
  }
  Card.prototype.reset = function (st) {
    this.st = st || {};
    var dec = (st && st.decision) || {};
    // A "change" starts from what is in force: its cover, its waive and its reason.
    this.changing = !!(st && st.redecide && st.status !== 'decided');
    this.cover = st && st.cover ? !!st.cover.on : (st && st.decision ? !!dec.cover : true);
    this.waive = (st && st.late && st.late.waive) || dec.waive || 0;
    this.waiveReason = dec.waive_reason || this.waiveReason || '';
    this.showWaive = this.waive > 0;
    var sg = (st && st.suggested) || {};
    this.late = sg.late || null;
    this.ot = sg.ot || null;
    this.paint();
  };
  Card.prototype.preview = async function (redecide) {
    var st = this.st;
    var q = '?user_id=' + st.user_id + '&month=' + st.month + '&cover=' + (this.cover ? 1 : 0) +
      '&waive=' + (this.waive || 0) + (redecide || this.changing ? '&redecide=1' : '');
    this.busy = true; this.paint();
    try {
      var j = await getJson(BASE + '/preview' + q);
      if (!j.success) throw new Error(j.message || 'Failed');
      var keepLate = this.late, keepOt = this.ot;
      this.st = j.statement; this.busy = false;
      var vals = function (list) { return (list || []).filter(function (c) { return !c.disabled; }).map(function (c) { return c.value; }); };
      var sg = j.statement.suggested || {};
      this.late = vals(j.statement.choices && j.statement.choices.late).indexOf(keepLate) >= 0 ? keepLate : (sg.late || null);
      this.ot = vals(j.statement.choices && j.statement.choices.ot).indexOf(keepOt) >= 0 ? keepOt : (sg.ot || null);
    } catch (e) { this.busy = false; alert('Could not refresh the card: ' + (e.message || e)); }
    this.paint();
  };
  Card.prototype.done = function (j) {
    if (this.o.onDone) { this.o.onDone(j); } else if (j && j.statement) { this.reset(j.statement); }
  };
  Card.prototype.decide = async function () {
    var st = this.st;
    var lateList = (st.choices && st.choices.late) || [];
    var pick = lateList.filter(function (c) { return c.value === this.late; }, this)[0];
    if (lateList.length && !this.late) { alert('Choose what happens to the late days.'); return; }
    if ((st.choices && st.choices.ot || []).length && !this.ot) { alert('Choose what happens to the extra days.'); return; }
    if (this.waive > 0 && !this.waiveReason.trim()) { alert('Give a reason for the waived minutes.'); return; }
    if (pick && /cut/i.test(pick.label) && !confirm((this.o.fullname || 'This employee') + ': ' + pick.label + '?\n\nThe money comes off the salary.')) { return; }
    this.busy = true; this.paint();
    try {
      var j = await postJson(BASE + '/decide', {
        user_id: st.user_id, month: st.month, cover: this.cover, late_choice: this.late, ot_choice: this.ot,
        waive: this.waive || 0, waive_reason: this.waiveReason, expect: st.expect
      });
      this.busy = false;
      if (!j.success) {
        alert(j.message || 'Could not save.');
        if (j.stale && j.statement) { this.reset(j.statement); }
        else { this.paint(); }
        return;
      }
      this.done(j);
    } catch (e) { this.busy = false; this.paint(); alert('Could not save: ' + (e.message || e)); }
  };
  Card.prototype.undo = async function () {
    var st = this.st;
    if (!confirm('Open ' + (this.o.fullname || '') + '’s ' + st.month_label + ' again?\n\nThe leaves taken or given by it are put back.')) { return; }
    this.busy = true; this.paint();
    var j = await postJson(BASE + '/undo', { user_id: st.user_id, month: st.month });
    this.busy = false;
    if (!j.success) { alert(j.message || 'Could not undo.'); this.paint(); return; }
    this.done(j);
  };

  Card.prototype.paint = function () {
    var st = this.st || {}, self = this, h = '';
    var bal = st.balance ? '<span class="nftb-bal">' + esc(st.balance.remaining) + ' leaves left</span>' : '';
    h += '<div class="nftb-top"><span class="nftb-name">' + esc(this.o.fullname || '') + '</span>' +
      (this.o.paid ? '<span class="nfla-paid">salary paid</span>' : '') +
      '<span class="nftb-who">' + esc(st.month_label || '') + '</span>' + bal + '</div>';
    (st.lines || []).forEach(function (l) { h += '<div class="nftb-line">' + esc(l) + '</div>'; });

    if (st.status === 'decided' && !this.changing) {
      var d = st.decision || {};
      h += '<div style="margin-top:7px;"><span class="nftb-chip t-' + esc(st.chip_tone || 'done') + '">' + esc(st.chip) + '</span>' +
        '<span class="nftb-who">' + (d.by ? 'by ' + esc(d.by) : '') + (d.at ? ' · ' + esc(d.at) : '') + '</span></div>';
      if (d.waive > 0) { h += '<div class="nftb-line">' + esc(d.waive) + ' min waived — ' + esc(d.waive_reason || '') + '</div>'; }
      h += '<div class="nftb-foot">' + (this.o.onDrill ? '<span class="nftb-link" data-k="drill">see the days ›</span>' : '') +
        (d.can_change
          ? '<span class="nftb-link" data-k="change">change ›</span><span class="nftb-link" data-k="undo">undo</span>'
          : (d.change_blocked ? '<span class="nftb-who">locked: ' + esc(d.change_blocked) + '</span>' : '')) + '</div>';
    } else if (st.status === 'running') {
      h += '<div class="nftb-line" style="color:#9ca3af;">' + esc(st.chip || 'Nothing yet this month') + ' — decided after the month ends</div>';
    } else {
      if (st.cover && st.cover.offered) {
        h += '<label class="nftb-cover"><input type="checkbox" data-k="cover"' + (this.cover ? ' checked' : '') + (this.busy ? ' disabled' : '') + '> ' +
          esc(st.cover.label) + ' <small>' + esc(st.cover.hint) + '</small></label>';
      }
      h += '<div class="nftb-result">' + esc(st.result || '') + '</div>';
      var grp = function (title, list, cur, key) {
        if (!list || !list.length) { return ''; }
        return '<div class="nftb-grp"><span class="nftb-glabel">' + title + '</span>' + list.map(function (c) {
          return '<button type="button" class="nftb-opt' + (c.value === cur ? ' on' : '') + (c.tone === 'danger' ? ' nftb-red' : '') + '"' +
            ' data-k="' + key + '" data-v="' + esc(c.value) + '"' + (c.disabled || self.busy ? ' disabled' : '') +
            (c.hint ? ' title="' + esc(c.hint) + '"' : '') + '>' + esc(c.label) + '</button>';
        }).join('') + '</div>';
      };
      h += grp('Late', st.choices && st.choices.late, this.late, 'late');
      h += grp('Extra', st.choices && st.choices.ot, this.ot, 'ot');
      if (this.ot === 'skip') {
        var sk = (st.choices.ot || []).filter(function (c) { return c.value === 'skip'; })[0];
        if (sk && sk.hint) { h += '<div class="nftb-note">⚠ ' + esc(sk.hint) + '</div>'; }
      }
      if (Number(st.will_cover_absence || 0) > 0 && this.ot === 'give') {
        h += '<div class="nftb-note">' + st.will_cover_absence + ' of the extra leaves settle a parked absence first.</div>';
      }
      (st.notes || []).forEach(function (n) {
        h += '<div class="nftb-line" style="font-size:11px;color:#6b7280;">' + (n.minutes > 0 ? '+' : '') + esc(n.minutes) + ' min ' +
          (n.kind === 'late' ? 'late' : 'extra') + ' · ' + esc(n.reason) + (n.by ? ' (' + esc(n.by) + ')' : '') + '</div>';
      });
      if (this.showWaive) {
        h += '<div class="nftb-waive"><input type="number" min="0" step="5" data-k="wmin" value="' + esc(this.waive || '') + '" placeholder="minutes" style="width:90px;">' +
          '<input type="text" data-k="wwhy" value="' + esc(this.waiveReason) + '" placeholder="reason (required)" style="flex:1;min-width:140px;">' +
          '<button type="button" class="nftb-opt" data-k="wapply">apply</button></div>';
      }
      h += '<div class="nftb-foot">' +
        (this.showWaive ? '' : '<span class="nftb-link" data-k="waive">waive minutes</span>') +
        (this.o.onDrill ? '<span class="nftb-link" data-k="drill">see the days ›</span>' : '') +
        (this.changing ? '<span class="nftb-link" data-k="cancel">cancel</span>' : '') +
        '<button type="button" class="nftb-go" data-k="go"' + (this.busy ? ' disabled' : '') + '>' + (this.busy ? '…' : 'Confirm') + '</button></div>';
    }
    this.el.innerHTML = '<div class="nftb">' + h + '</div>';
    this.wire();
  };
  Card.prototype.wire = function () {
    var self = this;
    this.el.querySelectorAll('[data-k]').forEach(function (n) {
      var k = n.getAttribute('data-k');
      if (k === 'cover') { n.onchange = function () { self.cover = n.checked; self.preview(); }; return; }
      if (k === 'wmin' || k === 'wwhy') {
        n.oninput = function () { if (k === 'wmin') { self.pendingWaive = Number(n.value || 0); } else { self.waiveReason = n.value; } };
        return;
      }
      n.onclick = function () {
        if (k === 'late') { self.late = n.getAttribute('data-v'); self.paint(); }
        else if (k === 'ot') { self.ot = n.getAttribute('data-v'); self.paint(); }
        else if (k === 'go') { self.decide(); }
        else if (k === 'undo') { self.undo(); }
        else if (k === 'change') {
          var dd = (self.st && self.st.decision) || {};
          self.changing = true; self.cover = !!dd.cover; self.waive = dd.waive || 0;
          self.waiveReason = dd.waive_reason || ''; self.showWaive = self.waive > 0;
          self.preview(true);
        }
        else if (k === 'cancel') { self.changing = false; self.o.onDone ? self.o.onDone(null) : self.paint(); }
        else if (k === 'waive') { self.showWaive = true; self.paint(); }
        else if (k === 'wapply') {
          self.waive = Math.max(0, Math.round(self.pendingWaive != null ? self.pendingWaive : self.waive));
          self.preview();
        }
        else if (k === 'drill' && self.o.onDrill) { self.o.onDrill(); }
      };
    });
  };

  /**
   * The person's bank history (decided months + every adjustment) and the "Adjust time bank"
   * tool. ONE renderer for the payroll Employee tab and the card modal (and the phone has the
   * same thing — TimeBankHistory), so the information and the action are identical everywhere.
   *   NFTimeBank.history(el, userId, {title, onChange})
   */
  async function history(el, userId, opts) {
    opts = opts || {};
    var j;
    try { j = await getJson(BASE + '/history?user_id=' + userId); } catch (e) { j = null; }
    if (!j || !j.success || !j.enabled) { el.style.display = 'none'; return; }
    el.style.display = '';
    var title = opts.title ? '<h4>' + opts.title + ' <span class="pr-emp-why" style="font-weight:400;font-size:11px;color:#9ca3af;">since Sep 2026</span></h4>' : '';
    if (j.excluded) { el.innerHTML = title + '<div class="nftb-line">Custom pay schedule — the time bank is not used.</div>'; return; }
    var h = title;
    (j.months || []).forEach(function (m) {
      h += '<div class="nftb-line" style="margin-top:6px;"><b style="color:#111827;">' + esc(m.month_label) + '</b> · <span style="color:#047857;font-weight:600;">' + esc(m.chip) + '</span>'
        + ((m.lines || []).length ? '<div style="color:#6b7280;font-size:11px;">' + esc(m.lines.join(' · ')) + '</div>' : '')
        + '<div class="nftb-who" style="margin-left:0;">' + esc([m.by, m.at].filter(Boolean).join(' · ')) + '</div></div>';
    });
    if (!(j.months || []).length) { h += '<div class="nftb-line" style="color:#9ca3af;">Nothing decided yet.</div>'; }
    (j.adjustments || []).forEach(function (a) {
      h += '<div class="nftb-line" style="font-size:11px;margin-top:4px;"><b>' + esc(a.label) + '</b> · ' + esc(a.reason || a.source) + ' · '
        + esc([a.by, a.at].filter(Boolean).join(' · ')) + (a.used_in ? ' · used in ' + esc(a.used_in) : ' · waiting')
        + (a.removable ? ' · <span class="nftb-link" data-tbrm="' + a.id + '">remove</span>' : '') + '</div>';
    });
    h += '<div style="margin-top:8px;"><span class="nftb-link" data-k="adjopen">＋ Adjust time bank (add missed minutes)</span></div>'
      + '<div data-k="adjform" style="display:none;margin-top:8px;padding:10px;border:1px solid #e5e7eb;border-radius:8px;background:#fafafa;">'
      + '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">'
      + '<select data-k="kind" style="border:1px solid #d1d5db;border-radius:6px;padding:5px 8px;font-size:12px;"><option value="ot">Extra (overtime)</option><option value="late">Late</option></select>'
      + '<select data-k="sign" style="border:1px solid #d1d5db;border-radius:6px;padding:5px 8px;font-size:12px;"><option value="1">add</option><option value="-1">take away</option></select>'
      + '<input data-k="mins" type="number" min="1" step="1" placeholder="minutes" style="width:90px;border:1px solid #d1d5db;border-radius:6px;padding:5px 8px;font-size:12px;">'
      + '<input data-k="why" type="text" placeholder="reason — shown on the card and to the employee" style="flex:1;min-width:180px;border:1px solid #d1d5db;border-radius:6px;padding:5px 8px;font-size:12px;">'
      + '<button type="button" data-k="save" class="nfla-btn nfla-t-good">Save</button></div>'
      + '<div class="nftb-line" style="font-size:11px;color:#6b7280;margin-top:5px;">It counts in the next decision (it never changes a month already decided).</div></div>';
    el.innerHTML = h;
    var q = function (k) { return el.querySelector('[data-k="' + k + '"]'); };
    q('adjopen').onclick = function () { var f = q('adjform'); f.style.display = f.style.display === 'none' ? '' : 'none'; };
    q('save').onclick = async function () {
      var mins = Math.round(Number(q('mins').value || 0));
      var why = String(q('why').value || '').trim();
      if (!(mins > 0)) { alert('Enter the minutes.'); return; }
      if (!why) { alert('A reason is required — it is shown on the card and to the employee.'); return; }
      q('save').disabled = true;
      var res = await postJson(BASE + '/adjust', { user_id: userId, kind: q('kind').value, minutes: mins * Number(q('sign').value), reason: why });
      alert(res.message || (res.success ? 'Saved.' : 'Could not save.'));
      history(el, userId, opts);
      if (opts.onChange) { opts.onChange(); }
    };
    el.querySelectorAll('[data-tbrm]').forEach(function (b) {
      b.onclick = async function () {
        if (!confirm('Remove this adjustment?')) { return; }
        var res = await postJson(BASE + '/adjust/remove', { user_id: userId, adjust_id: Number(b.getAttribute('data-tbrm')) });
        if (!res.success) { alert(res.message || 'Could not remove.'); }
        history(el, userId, opts);
        if (opts.onChange) { opts.onChange(); }
      };
    });
  }

  window.NFTimeBank = {
    mount: function (el, statement, opts) { return new Card(el, statement, opts); },
    history: history,
    open: async function (userId, month, fullname, onDone) {
      var wrap = document.createElement('div');
      wrap.className = 'nftb-modal';
      wrap.innerHTML = '<div class="nftb-box"><div class="nftb-boxhd">⏱ Late &amp; extra — ' + esc(fullname || '') +
        '<span class="nftb-x" title="Close">✕</span></div><div class="nftb-body" style="padding:2px 0;"><div class="nfla-empty">Loading…</div></div></div>';
      document.body.appendChild(wrap);
      var close = function () { if (wrap.parentNode) { wrap.parentNode.removeChild(wrap); } };
      wrap.querySelector('.nftb-x').onclick = close;
      wrap.onclick = function (e) { if (e.target === wrap) { close(); } };
      try {
        var j = await getJson(BASE + '/preview?user_id=' + userId + '&month=' + month);
        if (!j.success) throw new Error(j.message || 'Failed');
        var body = wrap.querySelector('.nftb-body');
        body.innerHTML = '<div class="nftb-cardslot"></div><div class="nftb-histslot" style="padding:10px 14px;border-top:1px solid #eef0f2;"></div>';
        var card = new Card(body.querySelector('.nftb-cardslot'), j.statement, {
          fullname: fullname,
          onDone: function (res) { close(); if (onDone) { onDone(res); } }
        });
        // The same history + Adjust the Employee tab (and the phone) show.
        history(body.querySelector('.nftb-histslot'), userId, {
          onChange: function () { card.preview(); if (onDone) { onDone(null); } }
        });
      } catch (e) {
        wrap.querySelector('.nftb-body').innerHTML = '<div class="nfla-empty">' + esc(e.message || e) + '</div>';
      }
    },
    esc: esc
  };
})();
</script>
@endonce
