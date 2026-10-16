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
  .nftb-will   { font-size: 11.5px; color: #4b5563; margin-top: 9px; padding: 6px 9px; background: #f8fafc; border-left: 3px solid #4f46e5; border-radius: 4px; }
  .nftb-will b { color: #111827; font-weight: 700; }
  .nftb-sum    { background: #f8fafc; border: 1px solid #eef0f2; border-radius: 8px; padding: 8px 10px; margin: 4px 0 8px; }
  .nftb-sum div { font-size: 12px; color: #4b5563; line-height: 1.55; }
  .nftb-sum div:first-child { color: #111827; font-weight: 700; }
  .nftb-waive input { border: 1px solid #d1d5db; border-radius: 6px; padding: 4px 8px; font-size: 12px; }
  /* Tiles round (Oct-14): LATE · EXTRA · RESULT, the tick, the table cell, the bank boxes. */
  .nftb-tiles  { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin-top: 10px; }
  @media (max-width: 560px) { .nftb-tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); } .nftb-tile.result, .nftb-tile.decided { grid-column: 1 / -1; } }
  /* The card also sits in narrow side columns (payroll Employee tab): size by the CARD, not the screen. */
  .nftb { container-type: inline-size; }
  @container (max-width: 470px) { .nftb-tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); } .nftb-tile.result, .nftb-tile.decided { grid-column: 1 / -1; } .nftb-boxes { grid-template-columns: minmax(0, 1fr); } }
  @container (max-width: 250px) { .nftb-tiles { grid-template-columns: minmax(0, 1fr); } }
  .nftb-tile   { box-sizing: border-box; padding: 10px 12px; border-radius: 10px; border: 1px solid; display: flex; flex-direction: column; gap: 3px; min-width: 0; }
  .nftb-tile.late    { background: #fffbeb; border-color: #fde68a; }
  .nftb-tile.extra   { background: #ecfdf5; border-color: #a7f3d0; }
  .nftb-tile.result  { background: #eef2ff; border-color: #c7d2fe; }
  .nftb-tile.decided { background: #ecfdf5; border-color: #6ee7b7; }
  .nftb-tl     { display: flex; align-items: center; gap: 4px; font-size: 10.5px; font-weight: 800; letter-spacing: .05em; color: #6b7280; }
  .nftb-tile.late .nftb-tl, .nftb-tile.late .nftb-tb { color: #92400e; }
  .nftb-tile.extra .nftb-tl, .nftb-tile.extra .nftb-tb, .nftb-tile.decided .nftb-tl, .nftb-tile.decided .nftb-tb { color: #065f46; }
  .nftb-tile.result .nftb-tl, .nftb-tile.result .nftb-tb { color: #3730a3; }
  .nftb-tb     { font-size: 21px; font-weight: 800; line-height: 1.15; }
  .nftb-ts     { font-size: 11.5px; color: #6b7280; line-height: 1.35; }
  .nftb-ts.strong { color: #374151; font-weight: 600; }
  .nftb-tc     { display: block; font-size: 11.5px; font-weight: 600; line-height: 1.35; background: none; border: 0; padding: 0; text-align: left; font-family: inherit; }
  .nftb-tc.ok    { color: #047857; }
  .nftb-tc.warn  { color: #b45309; cursor: pointer; text-decoration: underline dotted; }
  .nftb-tc.muted { color: #9ca3af; }
  .nftb-tby    { display: block; color: #6b7280; font-weight: 400; }
  .nftb-tc svg { display: inline-block; vertical-align: -1px; margin-right: 3px; }
  .nftb-to     { font-size: 11.5px; font-weight: 700; color: #111827; line-height: 1.35; }
  .nftb-tleg   { font-size: 11px; color: #92400e; line-height: 1.35; }
  .nftb-choices { margin-top: 10px; padding: 8px 10px; border: 1px solid #e5e7eb; border-radius: 10px; }
  .nftb-choices .nftb-grp { margin-top: 4px; }
  .nftb-choices .nftb-glabel { min-width: 92px; }
  .nftb-hint   { font-size: 11px; color: #9ca3af; }
  .nftb-info   { margin-top: 10px; font-size: 12px; color: #374151; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px 10px; }
  .nftb-info.inline { margin-top: 0; padding: 4px 8px; font-size: 11.5px; }
  .nftb-will   { display: flex; align-items: center; gap: 12px; margin-top: 10px; padding: 10px 12px; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 10px; }
  .nftb-will > div { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
  .nftb-will span { font-size: 11px; color: #6b7280; }
  .nftb-will b { font-size: 13px; color: #111827; }
  .nftb-will .nftb-go { margin-left: auto; flex-shrink: 0; }
  .nftb-boxes  { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin: 4px 0 8px; }
  @media (max-width: 560px) { .nftb-boxes { grid-template-columns: minmax(0, 1fr); } }
  .nftb-bx     { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px 10px; display: flex; flex-direction: column; gap: 2px; }
  .nftb-bx span { font-size: 11px; color: #6b7280; }
  .nftb-bx b   { font-size: 12.5px; color: #111827; }
  .nftb-hrow   { display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px; font-size: 12px; margin-top: 6px; }
  .nftb-hchip  { color: #047857; font-weight: 600; }
  .nftb-cell   { display: flex; flex-direction: column; gap: 2px; align-items: flex-start; margin-top: 3px; }
  .nftb-cell.center { align-items: center; text-align: center; }
  .nftb-cell.center .nftb-cout { text-align: center; }
  .nftb-cbig   { font-size: 13.5px; font-weight: 800; }
  .nftb-cbig.late { color: #92400e; } .nftb-cbig.extra { color: #065f46; } .nftb-cbig.zero { color: #9ca3af; font-weight: 600; }
  .nftb-csub   { font-size: 10.5px; font-weight: 500; color: #6b7280; }
  .nftb-ccheck { display: inline-flex; align-items: center; gap: 3px; font-size: 10.5px; font-weight: 600; }
  .nftb-ccheck.ok { color: #047857; } .nftb-ccheck.warn { color: #b45309; }
  .nftb-cout   { background: none; border: 0; padding: 0; text-align: left; font: inherit; font-size: 11px; font-weight: 700; color: #111827; cursor: pointer; line-height: 1.3; }
  .nftb-cout:hover { text-decoration: underline dotted; }
  .nftb-cby    { font-size: 10.5px; color: #6b7280; }
  .nftb-cleg   { font-size: 10px; color: #9ca3af; font-style: italic; margin-top: 2px; line-height: 1.3; }
  .nftb-cchip  { font: inherit; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 6px; background: #fffbeb; color: #92400e; border: 1px solid #fde68a; cursor: pointer; }
  .nftb-modal  { position: fixed; inset: 0; background: rgba(17,24,39,.45); display: flex; align-items: center; justify-content: center; z-index: 99990; padding: 16px; }
  .nftb-box    { background: #fff; border-radius: 12px; width: 100%; max-width: 640px; max-height: 88vh; overflow: auto; box-shadow: 0 20px 50px rgba(0,0,0,.25); }
  .nftb-boxhd  { display: flex; align-items: center; padding: 12px 14px; border-bottom: 1px solid #eef0f2; font-weight: 700; font-size: 14px; }
  .nftb-x      { margin-left: auto; cursor: pointer; color: #6b7280; font-size: 18px; line-height: 1; background: none; border: 0; padding: 4px 8px; }
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
          onDone: OPTS.onTimeBankDone,
          // The tile's amber "not checked" opens THAT kind's days (late or overtime).
          onDrill: OPTS.onDrill ? function (kind) {
            OPTS.onDrill(Object.assign({}, it, { drill: kind === 'overtime' ? 'month_overtime' : 'month_late' }));
          } : null
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
 * ⏱ NFTimeBank — the ONE time-bank card (tiles round, Oct-14 2026).
 * Three tiles: LATE · EXTRA · RESULT, each with the day-review tick. Extra ALWAYS pays for
 * late first at the half rate (owner) — there is no tick. Every number and every word comes
 * from TimeBankService (via /hr/payroll/time-bank/*): `tiles`, `choices`, `confirm_map`,
 * `cells`. This file never divides by 540, multiplies by the rate or composes a sentence.
 *   NFTimeBank.mount(el, statement, {fullname, paid, onDone, onDrill})
 *   NFTimeBank.open(userId, month, fullname, onDone)   — modal (payroll row / attendance cell)
 *   NFTimeBank.cellHtml(cells, 'late'|'extra', {uid, name})  — the table cell (every table)
 *   NFTimeBank.history(el, userId, {title, onChange})  — the bank boxes + history + Adjust
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
  var ICON_OK = '<svg aria-hidden="true" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg>';
  var ICON_WARN = '<svg aria-hidden="true" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"></path><path d="M12 17h.01"></path><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"></path></svg>';

  /** The day-review tick: green when every day is checked, amber (opens the days) when not. */
  function checkHtml(c, withBy, kind) {
    // Nothing to check (no late / no extra days): the tile's sub-line already says so.
    if (!c || c.state === 'off' || c.state === 'nothing' || !c.label) { return ''; }
    var cls = c.state === 'all' ? 'ok' : 'warn';
    var icon = cls === 'ok' ? ICON_OK : (cls === 'warn' ? ICON_WARN : '');
    var by = withBy && c.by_label ? ' <span class="nftb-tby">' + esc(c.by_label) + '</span>' : '';
    if (cls === 'warn') {
      return '<button type="button" class="nftb-tc warn" data-k="drill" data-kind="' + esc(kind || '') + '" title="Open the days that still need checking">'
        + icon + esc(c.label) + '</button>';
    }
    return '<div class="nftb-tc ' + cls + '">' + icon + esc(c.label) + by + '</div>';
  }

  function tilesHtml(st, key) {
    var t = st.tiles || {};
    if (!t.late) { return ''; }
    var tile = function (cls, label, x, kind) {
      return '<div class="nftb-tile ' + cls + '"><div class="nftb-tl">' + label + '</div>'
        + '<div class="nftb-tb">' + esc(x.big) + '</div>'
        + '<div class="nftb-ts">' + esc(x.sub) + '</div>'
        + checkHtml(x.check, true, kind)
        + (x.outcome ? '<div class="nftb-to">' + esc(x.outcome) + '</div>' : '')
        + (x.legacy ? '<div class="nftb-tleg">' + esc(x.legacy) + '</div>' : '')
        + '</div>';
    };
    var r = t.result || {};
    var res = '<div class="nftb-tile ' + (r.decided ? 'decided' : 'result') + '">'
      + '<div class="nftb-tl">' + esc(r.title || 'RESULT') + '</div>'
      + '<div class="nftb-tb">' + esc((!r.decided && key && st.result_map && st.result_map[key]) || r.big) + '</div>'
      + (r.how ? '<div class="nftb-ts strong">' + esc(r.how) + '</div>' : '')
      + '<div class="nftb-ts">' + esc((!r.decided && key && /\|skip$/.test(key) && st.leftover_skip) || r.leftover) + '</div>'
      + (r.by ? '<div class="nftb-ts">' + esc(r.by) + '</div>' : '')
      + (r.note ? '<div class="nftb-to">' + esc(r.note) + '</div>' : '')
      + '</div>';
    return '<div class="nftb-tiles">' + tile('late', 'LATE', t.late, 'late') + tile('extra', 'OVERTIME', t.extra, 'overtime') + res + '</div>';
  }

  function Card(el, st, o) {
    this.el = el; this.o = o || {}; this.busy = false;
    this.reset(st);
  }
  Card.prototype.reset = function (st) {
    this.st = st || {};
    var dec = (st && st.decision) || {};
    // A "change" starts from what is in force: its waive and its reason.
    this.changing = !!(st && st.redecide && st.status !== 'decided');
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
    var q = '?user_id=' + st.user_id + '&month=' + st.month + '&waive=' + (this.waive || 0) + (redecide || this.changing ? '&redecide=1' : '');
    this.busy = true; this.paint();
    try {
      var j = await getJson(BASE + '/preview' + q);
      if (!j.success) throw new Error(j.message || 'Failed');
      var keepLate = this.late, keepOt = this.ot;
      this.st = j.statement; this.busy = false;
      this.waive = (j.statement.late && j.statement.late.waive) || 0;
      var vals = function (list) { return (list || []).filter(function (c) { return !c.disabled; }).map(function (c) { return c.value; }); };
      var sg = j.statement.suggested || {};
      this.late = vals(j.statement.choices && j.statement.choices.late).indexOf(keepLate) >= 0 ? keepLate : (sg.late || null);
      this.ot = vals(j.statement.choices && j.statement.choices.ot).indexOf(keepOt) >= 0 ? keepOt : (sg.ot || null);
    } catch (e) { this.busy = false; alert('Could not refresh the card: ' + (e.message || e)); }
    this.paint();
  };
  // A folded month's card opens the month that holds it, in place.
  Card.prototype.openMonth = async function (month) {
    if (!month) { return; }
    try {
      var j = await getJson(BASE + '/preview?user_id=' + this.st.user_id + '&month=' + month);
      if (!j.success) throw new Error(j.message || 'Failed');
      this.reset(j.statement);
    } catch (e) { alert('Could not open ' + month + ': ' + (e.message || e)); }
  };
  // Pages with unsaved work (the payroll grid) may stop a save: window.NFTimeBankBeforeSave(what).
  var mayProceed = function (what) {
    return typeof window.NFTimeBankBeforeSave !== 'function' || window.NFTimeBankBeforeSave(what) !== false;
  };
  Card.prototype.done = function (j) {
    if (this.o.onDone) { this.o.onDone(j); } else if (j && j.statement) { this.reset(j.statement); }
  };
  Card.prototype.willText = function () {
    var st = this.st || {}, m = st.confirm_map || {};
    var key = (this.late || '') + '|' + (this.ot || '');
    return m[key] || st.confirm_will || '';
  };
  Card.prototype.decide = async function () {
    var st = this.st;
    var lateList = ((st.choices && st.choices.late) || []).filter(function (c) { return !c.disabled; });
    var otList = (st.choices && st.choices.ot) || [];
    if (lateList.length && !this.late) { alert('Choose what happens to the late days.'); return; }
    if (otList.length && !this.ot) { alert('Choose what happens to the overtime days.'); return; }
    if (this.waive > 0 && !this.waiveReason.trim()) { alert('Give a reason for the waived minutes.'); return; }
    if (st.unchecked_warning && !confirm(st.unchecked_warning)) { return; }
    var pick = lateList.filter(function (c) { return c.value === this.late; }, this)[0];
    if (pick && /cut/i.test(pick.label) && !confirm((this.o.fullname || 'This employee') + ': ' + this.willText() + '\n\nThe money comes off the salary.')) { return; }
    if (!mayProceed('Saving this decision')) { return; }
    this.busy = true; this.paint();
    try {
      var j = await postJson(BASE + '/decide', {
        user_id: st.user_id, month: st.month, late_choice: this.late, ot_choice: this.ot,
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
    if (!mayProceed('Undoing this decision')) { return; }
    this.busy = true; this.paint();
    var j;
    try { j = await postJson(BASE + '/undo', { user_id: st.user_id, month: st.month }); }
    catch (e) { j = { success: false, message: 'Could not undo: ' + (e.message || e) }; }
    this.busy = false;
    if (!j.success) { alert(j.message || 'Could not undo.'); this.paint(); return; }
    this.done(j);
  };

  Card.prototype.paint = function () {
    var st = this.st || {}, self = this, h = '';
    var decided = st.status === 'decided' && !this.changing;
    var bal = st.balance ? '<span class="nftb-bal">' + esc(st.balance.remaining) + ' leaves left</span>' : '';
    h += '<div class="nftb-top"><span class="nftb-name">' + esc(this.o.fullname || '') + '</span>'
      + (this.o.paid ? '<span class="nfla-paid">salary paid</span>' : '')
      + '<span class="nftb-who">' + esc(st.month_label || '') + '</span>' + bal + '</div>';

    if (st.status === 'folded') {
      var fl = st.folded_label || st.folded_into || 'a later month';
      h += '<div class="nftb-info">This month is part of ' + esc(fl) + '’s decision.</div>'
        + (st.folded_into ? '<div class="nftb-foot"><span class="nftb-link" data-k="openfold">Open ' + esc(fl) + ' ›</span></div>' : '');
      this.el.innerHTML = '<div class="nftb">' + h + '</div>';
      this.wire();
      return;
    }
    h += tilesHtml(st, decided || st.status === 'running' ? null : (this.late || '') + '|' + (this.ot || ''));

    // Whole days only: the two choice rows (server labels; the suggestion is pre-selected).
    var net = st.net || {};
    var grp = function (title, list, cur, key) {
      if (!list || !list.length) { return ''; }
      return '<div class="nftb-grp"><span class="nftb-glabel">' + esc(title) + '</span>' + list.map(function (c) {
        return '<button type="button" class="nftb-opt' + (c.value === cur && !c.disabled ? ' on' : '') + (c.tone === 'danger' ? ' nftb-red' : '') + '"'
          + ' data-k="' + key + '" data-v="' + esc(c.value) + '"' + (c.disabled || self.busy ? ' disabled' : '')
          + (c.hint ? ' title="' + esc(c.hint) + '"' : '') + '>' + esc(c.label) + '</button>'
          + (c.disabled && c.hint ? '<span class="nftb-hint">' + esc(c.hint) + '</span>' : '');
      }).join('') + '</div>';
    };
    if (!decided && st.status !== 'running') {
      var ld = Number(net.late_days || 0), od = Number(net.ot_days || 0);
      var rows = grp('Late −' + ld + ' day' + (ld === 1 ? '' : 's'), st.choices && st.choices.late, this.late, 'late')
               + grp('Overtime +' + od + ' day' + (od === 1 ? '' : 's'), st.choices && st.choices.ot, this.ot, 'ot');
      if (rows) { h += '<div class="nftb-choices">' + rows + '</div>'; }
    }
    (st.notes || []).forEach(function (n) {
      h += '<div class="nftb-line" style="font-size:11px;color:#6b7280;">' + (n.minutes > 0 ? '+' : '') + esc(n.minutes) + ' min '
        + (n.kind === 'late' ? 'late' : 'overtime') + ' · ' + esc(n.reason) + (n.by ? ' (' + esc(n.by) + ')' : '') + '</div>';
    });
    if (this.showWaive && !decided && st.status !== 'running') {
      h += '<div class="nftb-waive"><input type="number" min="0" step="5" data-k="wmin" value="' + esc(this.waive || '') + '" placeholder="minutes" aria-label="Minutes to waive" style="width:90px;">'
        + '<input type="text" data-k="wwhy" value="' + esc(this.waiveReason) + '" placeholder="reason (required)" aria-label="Reason" style="flex:1;min-width:140px;">'
        + '<button type="button" class="nftb-opt" data-k="wapply">apply</button></div>';
    }

    // Links + the one way forward for every state (never a dead end).
    var drill = this.o.onDrill ? '<span class="nftb-link" data-k="drill" data-kind="late">see the days ›</span>' : '';
    if (decided) {
      var d = st.decision || {};
      h += '<div class="nftb-foot">' + drill
        + (d.can_change
          ? '<span class="nftb-link" data-k="change">change ›</span><span class="nftb-link" data-k="undo">undo</span>'
          : (d.change_blocked ? '<span class="nftb-info inline">Locked: ' + esc(d.change_blocked) + '.</span>' : ''))
        + '</div>';
    } else if (st.status === 'running') {
      h += '<div class="nftb-foot">' + drill + '<span class="nftb-info inline">Decided after the month ends' + (st.opens_on ? ' — opens ' + esc(st.opens_on) : '') + '.</span></div>';
    } else {
      // A change of a decided month always offers Confirm (it may now be under a day).
      var isClear = st.status === 'clear' && !(this.waive > 0) && !this.changing;
      h += '<div class="nftb-foot">' + drill
        + (this.showWaive ? '' : '<span class="nftb-link" data-k="waive">waive minutes</span>')
        + (this.changing ? '<span class="nftb-link" data-k="cancel">cancel</span>' : '') + '</div>';
      if (isClear) {
        h += '<div class="nftb-info">' + esc(st.clear_note || ('Under a day — nothing to decide. The leftover moves to ' + (st.next_label || 'next month') + ' by itself.')) + '</div>';
      } else {
        h += '<div class="nftb-will"><div><span>Confirm will</span><b>' + esc(this.willText()) + '</b></div>'
          + '<button type="button" class="nftb-go" data-k="go"' + (this.busy ? ' disabled' : '') + '>' + (this.busy ? '…' : 'Confirm') + '</button></div>';
      }
    }
    this.el.innerHTML = '<div class="nftb">' + h + '</div>';
    this.wire();
  };
  Card.prototype.wire = function () {
    var self = this;
    this.el.querySelectorAll('[data-k]').forEach(function (n) {
      var k = n.getAttribute('data-k');
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
          self.changing = true; self.waive = dd.waive || 0;
          self.waiveReason = dd.waive_reason || ''; self.showWaive = self.waive > 0;
          self.preview(true);
        }
        else if (k === 'cancel') { self.changing = false; self.o.onDone ? self.o.onDone(null) : self.paint(); }
        else if (k === 'waive') { self.showWaive = true; self.paint(); }
        else if (k === 'wapply') {
          self.waive = Math.max(0, Math.round(self.pendingWaive != null ? self.pendingWaive : self.waive));
          self.preview();
        }
        else if (k === 'drill' && self.o.onDrill) { self.o.onDrill(n.getAttribute('data-kind') || 'late'); }
        else if (k === 'openfold') { self.openMonth(self.st.folded_into); }
      };
    });
  };

  /**
   * The LATE / OVERTIME table cell — ONE renderer for Attendance › Month, the payroll grid and
   * the popups, from the server's `cells`. A pending month carries "Decide <Month> ›"; a decided
   * or covered month says what happened and who decided. Both open the card.
   */
  function cellHtml(cells, kind, o) {
    if (!cells || !cells[kind]) { return ''; }
    o = o || {};
    var x = cells[kind];
    var openAttr = o.uid ? ' data-tbopen-uid="' + esc(o.uid) + '" data-tbopen-month="' + esc(cells.month) + '" data-tbopen-name="' + esc(o.name || '') + '"' : '';
    // `bare`: the page already draws the minutes (clickable to the days) and its own clickable
    // review chip (the same day-review counts) — the cell adds only what HAPPENED.
    var h = '<div class="nftb-cell' + (o.center ? ' center' : '') + '">';
    if (o.bare) {
      if (x.sub && x.sub !== 'so far') { h += '<div class="nftb-csub">' + esc(x.sub) + '</div>'; }
    } else {
      h += '<div class="nftb-cbig ' + (kind === 'late' ? 'late' : 'extra') + (x.minutes ? '' : ' zero') + '">' + esc(x.big)
        + (x.sub ? ' <span class="nftb-csub">' + esc(x.sub) + '</span>' : '') + '</div>';
    }
    if (!o.bare && x.check && x.check.state !== 'off' && x.check.state !== 'nothing' && x.check.label) {
      var ok = x.check.state === 'all';
      h += '<div class="nftb-ccheck ' + (ok ? 'ok' : 'warn') + '">' + (ok ? ICON_OK : ICON_WARN)
        + esc(ok ? (x.check.done + ' checked') : (x.check.total - x.check.done) + ' not checked') + '</div>';
    }
    if (x.outcome) {
      h += '<button type="button" class="nftb-cout"' + openAttr + ' title="Open the late &amp; overtime card">' + esc(x.outcome) + '</button>';
    }
    if (x.legacy) { h += '<div class="nftb-cleg">' + esc(x.legacy) + '</div>'; }
    // Who decided (or "nothing to decide") — once per row, in the Late cell.
    if (cells.by && kind === 'late') { h += '<div class="nftb-cby">' + esc(cells.by) + '</div>'; }
    // The pending chip sits in the cell that has the whole days and says what they are.
    if (cells.chip && kind === (cells.chip_in || 'late')) {
      h += '<button type="button" class="nftb-cchip"' + openAttr + '>' + (cells.chip_sub ? esc(cells.chip_sub) + ' — ' : '') + esc(cells.chip) + '</button>';
    }
    return h + '</div>';
  }
  // One delegate for every table: a cell's outcome / "Decide …" opens the card for that month.
  document.addEventListener('click', function (e) {
    var b = e.target && e.target.closest ? e.target.closest('[data-tbopen-uid]') : null;
    if (!b) { return; }
    e.preventDefault(); e.stopPropagation();
    window.NFTimeBank.open(Number(b.getAttribute('data-tbopen-uid')), b.getAttribute('data-tbopen-month'),
      b.getAttribute('data-tbopen-name'), window.NFTimeBankOnDone || null);
  }, true);

  /**
   * The person's bank: three boxes (in the bank now · this month so far · since Sep), decided
   * months, adjustments, and "Adjust time bank". ONE renderer for the payroll Employee tab and
   * the card modal (the phone has the same thing — TimeBankHistory).
   */
  async function history(el, userId, opts) {
    opts = opts || {};
    var j;
    try { j = await getJson(BASE + '/history?user_id=' + userId); } catch (e) { j = null; }
    if (!j || !j.success || !j.enabled) { el.style.display = 'none'; return; }
    el.style.display = '';
    var title = opts.title ? '<h4>' + opts.title + ' <span class="pr-emp-why" style="font-weight:400;font-size:11px;color:#9ca3af;">since Sep 2026</span></h4>' : '';
    if (j.excluded) { el.innerHTML = title + '<div class="nftb-info">Custom pay schedule — the time bank is not used.</div>'; return; }
    var h = title;
    if (j.summary && j.summary.enabled && (j.summary.lines || []).length) {
      h += '<div class="nftb-boxes">' + j.summary.lines.map(function (l) {
        var i = l.indexOf(': ');
        var lab = i > 0 ? l.slice(0, i) : '', val = i > 0 ? l.slice(i + 2) : l;
        return '<div class="nftb-bx"><span>' + esc(lab) + '</span><b>' + esc(val) + '</b></div>';
      }).join('') + '</div>';
    }
    (j.months || []).forEach(function (m) {
      h += '<div class="nftb-hrow"><b>' + esc(m.month_label) + '</b><span class="nftb-hchip">' + esc(m.chip) + '</span>'
        + '<span class="nftb-who">' + esc([m.by, m.at].filter(Boolean).join(' · ')) + '</span></div>';
    });
    (j.adjustments || []).forEach(function (a) {
      h += '<div class="nftb-line" style="font-size:11px;margin-top:4px;"><b>' + esc(a.label) + '</b> · ' + esc(a.reason || a.source) + ' · '
        + esc([a.by, a.at].filter(Boolean).join(' · ')) + (a.used_in ? ' · used in ' + esc(a.used_in) : ' · waiting')
        + (a.removable ? ' · <span class="nftb-link" data-tbrm="' + a.id + '">remove</span>' : '') + '</div>';
    });
    h += '<div style="margin-top:8px;"><span class="nftb-link" data-k="adjopen">＋ Adjust time bank (add missed minutes)</span></div>'
      + '<div data-k="adjform" style="display:none;margin-top:8px;padding:10px;border:1px solid #e5e7eb;border-radius:8px;background:#fafafa;">'
      + '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">'
      + '<select data-k="kind" aria-label="Late or overtime" style="border:1px solid #d1d5db;border-radius:6px;padding:5px 8px;font-size:12px;"><option value="ot">Overtime</option><option value="late">Late</option></select>'
      + '<select data-k="sign" aria-label="Add or take away" style="border:1px solid #d1d5db;border-radius:6px;padding:5px 8px;font-size:12px;"><option value="1">add</option><option value="-1">take away</option></select>'
      + '<input data-k="mins" type="number" min="1" step="1" placeholder="minutes" aria-label="Minutes" style="width:90px;border:1px solid #d1d5db;border-radius:6px;padding:5px 8px;font-size:12px;">'
      + '<input data-k="why" type="text" placeholder="reason — shown on the card and to the employee" aria-label="Reason" style="flex:1;min-width:180px;border:1px solid #d1d5db;border-radius:6px;padding:5px 8px;font-size:12px;">'
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
      if (!mayProceed('Saving this adjustment')) { return; }
      q('save').disabled = true;
      var res = await postJson(BASE + '/adjust', { user_id: userId, kind: q('kind').value, minutes: mins * Number(q('sign').value), reason: why });
      alert(res.message || (res.success ? 'Saved.' : 'Could not save.'));
      history(el, userId, opts);
      if (opts.onChange) { opts.onChange(); }
    };
    el.querySelectorAll('[data-tbrm]').forEach(function (b) {
      b.onclick = async function () {
        if (!confirm('Remove this adjustment?')) { return; }
        if (!mayProceed('Removing this adjustment')) { return; }
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
    cellHtml: cellHtml,
    open: async function (userId, month, fullname, onDone) {
      var wrap = document.createElement('div');
      wrap.className = 'nftb-modal';
      wrap.innerHTML = '<div class="nftb-box" role="dialog" aria-label="Late and overtime"><div class="nftb-boxhd">⏱ Late &amp; overtime — ' + esc(fullname || '') +
        '<button type="button" class="nftb-x" aria-label="Close">✕</button></div><div class="nftb-body" style="padding:2px 0;"><div class="nfla-empty">Loading…</div></div></div>';
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
          onDone: function (res) { close(); if (onDone) { onDone(res); } },
          // Each page says how it shows a person's days (window.NFTimeBankDrill); the modal
          // closes first so the list is not hidden behind it.
          onDrill: window.NFTimeBankDrill ? function (kind) { close(); window.NFTimeBankDrill(userId, kind, fullname); } : null
        });
        // The same bank boxes + history + Adjust the Employee tab (and the phone) show.
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
