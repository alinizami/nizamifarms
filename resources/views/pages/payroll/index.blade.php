@extends('layouts.app')

@section('title', 'Payroll')

@section('content')
<style>
  .pr-wrap { max-width: 1280px; margin: 0 auto; padding: 18px 16px 120px; font-size: 13px; color: #1f2937; }
  .pr-head { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end; justify-content: space-between; margin-bottom: 14px; }
  .pr-title { font-size: 22px; font-weight: 700; color: #111827; margin: 0; }
  .pr-sub { color: #6b7280; font-size: 12.5px; margin-top: 3px; }
  .pr-monthbar { display: flex; align-items: center; gap: 8px; }
  .pr-navbtn { width: 32px; height: 34px; border: 1px solid #d1d5db; background: #fff; border-radius: 8px; cursor: pointer; font-size: 17px; line-height: 1; color: #374151; }
  .pr-navbtn:hover { background: #f3f4f6; }
  .pr-month-input { height: 34px; border: 1px solid #d1d5db; border-radius: 8px; padding: 0 10px; font-size: 13px; color: #111827; background: #fff; }
  .pr-btn-primary { height: 34px; padding: 0 16px; border: none; border-radius: 8px; background: #16a34a; color: #fff; font-weight: 600; font-size: 13px; cursor: pointer; }
  .pr-btn-primary:hover { background: #15803d; }
  .pr-btn-primary:disabled { background: #9ca3af; cursor: default; }

  .pr-strip { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; }
  .pr-card { flex: 1 1 150px; min-width: 140px; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 12px; background: #fff; }
  .pr-card .k { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: .03em; }
  .pr-card .v { font-size: 18px; font-weight: 700; color: #111827; margin-top: 2px; }

  .pr-tablewrap { border: 1px solid #e5e7eb; border-radius: 12px; overflow: auto; background: #fff; }
  table.pr-table { width: 100%; border-collapse: collapse; min-width: 1040px; }
  .pr-table thead th { position: sticky; top: 0; background: #f9fafb; z-index: 1; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .03em; padding: 10px 12px; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
  .pr-table td { padding: 11px 12px; border-bottom: 1px solid #f1f3f5; vertical-align: middle; }
  .pr-table tbody tr:hover { background: #fcfcfd; }
  .pr-table td.num, .pr-table th.num { text-align: right; font-variant-numeric: tabular-nums; }

  .pr-emp-name { font-weight: 600; color: #111827; }
  .pr-emp-sub { font-size: 11px; color: #9ca3af; margin-top: 1px; }

  .pr-base { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; padding: 3px 6px; border-radius: 6px; }
  .pr-base:hover { background: #f3f4f6; }
  .pr-base .amt { font-weight: 600; }
  .pr-base .pen { font-size: 11px; color: #9ca3af; }
  .pr-base-input { width: 96px; height: 30px; border: 1px solid #16a34a; border-radius: 6px; padding: 0 8px; font-size: 13px; text-align: right; }
  .pr-setsal { display: inline-flex; align-items: center; gap: 4px; padding: 4px 9px; border: 1px dashed #f59e0b; color: #b45309; background: #fffbeb; border-radius: 7px; font-size: 12px; font-weight: 600; cursor: pointer; }

  .pr-chip { display: inline-block; padding: 2px 7px; border-radius: 999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
  .pr-chip.present { background: #ecfdf5; color: #047857; }
  .pr-chip.absent  { background: #fef2f2; color: #b91c1c; cursor: pointer; }
  .pr-chip.leave   { background: #eff6ff; color: #1d4ed8; cursor: pointer; }
  .pr-chip.late    { background: #fff7ed; color: #c2410c; cursor: pointer; }
  .pr-chip.ot      { background: #f5f3ff; color: #6d28d9; cursor: pointer; }
  .pr-chip.muted   { background: #f3f4f6; color: #9ca3af; }
  .pr-chip.paid    { background: #ecfdf5; color: #047857; }
  .pr-chip.unpaid  { background: #f3f4f6; color: #6b7280; }
  .pr-flag { display: inline-block; margin-left: 4px; color: #b45309; cursor: help; }

  .pr-late-cell { min-width: 130px; }
  .pr-formula { font-size: 10.5px; color: #9ca3af; margin-top: 3px; }
  .pr-ded-input { width: 88px; height: 28px; border: 1px solid #d1d5db; border-radius: 6px; padding: 0 7px; font-size: 12.5px; text-align: right; margin-top: 4px; }
  .pr-ded-input.override { border-color: #c2410c; background: #fff7ed; }
  .pr-chip.dim { opacity: .4; }
  /* Manager bypass toggle for overtime bonus / late-leave deduction (applied on Pay). */
  .pr-lvtog { display: inline-flex; align-items: center; gap: 4px; margin-top: 4px; font-size: 10.5px; font-weight: 700; cursor: pointer; user-select: none; border-radius: 6px; padding: 2px 7px; line-height: 1.4; }
  .pr-lvtog.on  { color: #047857; background: #ecfdf5; }
  .pr-lvtog.off { color: #9ca3af; background: #f3f4f6; }

  .pr-adv { color: #b45309; cursor: pointer; font-weight: 600; }
  .pr-adv.zero { color: #cbd5e1; cursor: default; font-weight: 400; }
  .pr-give { display: inline-block; margin-top: 3px; font-size: 11px; color: #2563eb; cursor: pointer; }
  .pr-give:hover { text-decoration: underline; }
  .pr-dblpay { margin-top: 4px; font-size: 11px; color: #b45309; background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 2px 6px; display: inline-block; }
  .pr-net { font-weight: 700; font-size: 14px; color: #111827; }
  .pr-net.neg { color: #b91c1c; }
  .pr-ded-link { cursor: pointer; border-bottom: 1px dotted #cbd5e1; }
  .pr-ded-link:hover { color: #111827; border-bottom-color: #9ca3af; }

  .pr-empty { padding: 40px; text-align: center; color: #9ca3af; }

  /* sticky pay bar */
  .pr-paybar { position: fixed; left: 0; right: 0; bottom: 0; background: #111827; color: #fff; display: none; align-items: center; gap: 16px; padding: 12px 22px; z-index: 40; box-shadow: 0 -4px 16px rgba(0,0,0,.15); }
  .pr-paybar.show { display: flex; }
  .pr-paybar .sel { font-size: 13px; color: #d1d5db; }
  .pr-paybar .tot { font-size: 17px; font-weight: 700; margin-left: auto; }
  .pr-paybar .pay { height: 38px; padding: 0 20px; border: none; border-radius: 9px; background: #22c55e; color: #06210f; font-weight: 700; font-size: 14px; cursor: pointer; }
  .pr-paybar .pay:hover { background: #16a34a; color: #fff; }

  /* modal */
  .pr-modal-back { position: fixed; inset: 0; background: rgba(15,23,42,.45); display: none; align-items: center; justify-content: center; z-index: 60; padding: 16px; }
  .pr-modal-back.show { display: flex; }
  .pr-modal { background: #fff; border-radius: 14px; width: 100%; max-width: 460px; max-height: 88vh; overflow: auto; box-shadow: 0 20px 50px rgba(0,0,0,.3); }
  .pr-modal-h { padding: 16px 18px; border-bottom: 1px solid #eef0f2; font-size: 16px; font-weight: 700; }
  .pr-modal-b { padding: 16px 18px; }
  .pr-modal-f { padding: 14px 18px; border-top: 1px solid #eef0f2; display: flex; gap: 10px; justify-content: flex-end; }
  .pr-fund { display: block; border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px 14px; margin-bottom: 10px; cursor: pointer; }
  .pr-fund.active { border-color: #16a34a; background: #f0fdf4; }
  .pr-fund .lbl { font-weight: 600; }
  .pr-bank-sel { width: 100%; height: 36px; border: 1px solid #d1d5db; border-radius: 8px; padding: 0 8px; margin-top: 8px; font-size: 13px; }
  .pr-btn-ghost { height: 36px; padding: 0 14px; border: 1px solid #d1d5db; background: #fff; border-radius: 8px; cursor: pointer; font-size: 13px; }
  .pr-paylist { max-height: 180px; overflow: auto; border: 1px solid #f1f3f5; border-radius: 8px; margin-bottom: 14px; }
  .pr-paylist .r { display: flex; justify-content: space-between; padding: 7px 12px; border-bottom: 1px solid #f6f7f8; font-size: 12.5px; }
  .pr-paylist .r:last-child { border-bottom: none; }

  /* generic side sheet for date lists */
  .pr-sheet-back { position: fixed; inset: 0; background: rgba(15,23,42,.4); display: none; z-index: 70; }
  .pr-sheet-back.show { display: block; }
  .pr-sheet { position: fixed; top: 0; right: 0; bottom: 0; width: 340px; max-width: 92vw; background: #fff; box-shadow: -8px 0 24px rgba(0,0,0,.18); transform: translateX(100%); transition: transform .18s ease; overflow: auto; }
  .pr-sheet-back.show .pr-sheet { transform: translateX(0); }
  .pr-sheet-h { padding: 15px 16px; border-bottom: 1px solid #eef0f2; font-weight: 700; display: flex; justify-content: space-between; align-items: center; }
  .pr-sheet-x { cursor: pointer; color: #9ca3af; font-size: 20px; line-height: 1; }
  .pr-sheet-b { padding: 8px 4px; }
  /* Leave-actions review panel (wider than a plain date list — every row carries buttons) */
  .pr-sheet.wide { width: 470px; }
  .pr-la-note { padding: 10px 14px; font-size: 11.5px; color: #6b7280; background: #fafbfc; border-bottom: 1px solid #eef0f2; line-height: 1.5; }
  .pr-la-row { padding: 11px 14px; border-bottom: 1px solid #f6f7f8; }
  .pr-la-name { font-size: 13px; font-weight: 700; color: #111827; }
  .pr-la-head { font-size: 12.5px; font-weight: 700; margin-top: 2px; }
  .pr-la-why { font-size: 11px; color: #6b7280; line-height: 1.5; margin-top: 2px; }
  .pr-la-link { color: #4f46e5; cursor: pointer; text-decoration: underline dotted; font-weight: 600; white-space: nowrap; }
  .pr-la-acts { display: flex; gap: 6px; margin-top: 7px; flex-wrap: wrap; align-items: center; }
  .pr-la-btn { font-size: 11px; font-weight: 700; border-radius: 6px; padding: 4px 11px; cursor: pointer; border: 1px solid transparent; white-space: nowrap; }
  .pr-la-btn:disabled { opacity: .5; cursor: not-allowed; }
  .pr-la-give { color: #047857; background: #ecfdf5; border-color: #a7f3d0; }
  .pr-la-cut  { color: #b45309; background: #fffbeb; border-color: #fcd34d; }
  .pr-la-skip { color: #6b7280; background: #f3f4f6; border-color: #e5e7eb; }
  .pr-la-done { font-size: 11px; font-weight: 700; border-radius: 6px; padding: 3px 9px; display: inline-block; }
  .pr-la-done.applied { color: #047857; background: #ecfdf5; }
  .pr-la-done.waived  { color: #6b7280; background: #f3f4f6; }
  .pr-la-back { font-size: 12px; color: #4f46e5; cursor: pointer; font-weight: 600; padding: 9px 14px; border-bottom: 1px solid #eef0f2; }
  /* Day review — the verdict and the controls under a day in the drill. */
  .pr-dr-acts { display: flex; gap: 6px; margin-top: 6px; flex-wrap: wrap; align-items: center; }
  .pr-dr-btn  { font-size: 11px; font-weight: 700; border-radius: 6px; padding: 3px 10px; cursor: pointer; border: 1px solid #e5e7eb; background: #f3f4f6; color: #6b7280; white-space: nowrap; }
  .pr-dr-btn.ok { color: #047857; background: #ecfdf5; border-color: #a7f3d0; }
  .pr-dr-btn:disabled { opacity: .5; cursor: not-allowed; }
  .pr-dr-done { font-size: 10.5px; font-weight: 700; color: #047857; margin-top: 3px; }
  .pr-dr-flag { font-size: 10.5px; font-weight: 700; color: #b45309; margin-top: 3px; }
  .pr-dr-wait { font-size: 10.5px; color: #9ca3af; font-style: italic; margin-top: 4px; }
  .pr-dr-link { font-size: 11px; color: #4f46e5; cursor: pointer; text-decoration: underline dotted; font-weight: 600; }
  .pr-daterow { display: flex; justify-content: space-between; padding: 9px 14px; border-bottom: 1px solid #f6f7f8; font-size: 13px; }
  .pr-daterow .dt { font-weight: 600; color: #111827; }
  .pr-daterow .lb { color: #6b7280; font-size: 12px; }

  /* tabs */
  .pr-tabs { display: flex; gap: 4px; border-bottom: 1px solid #e5e7eb; margin-bottom: 14px; }
  .pr-tab { padding: 9px 16px; font-size: 13.5px; font-weight: 600; color: #6b7280; cursor: pointer; border: none; background: none; border-bottom: 2px solid transparent; margin-bottom: -1px; }
  .pr-tab:hover { color: #374151; }
  .pr-tab.active { color: #16a34a; border-bottom-color: #16a34a; }
  .pr-view { display: none; }
  .pr-view.active { display: block; }

  /* business-unit chip + settings gear on rows */
  .pr-emp-tags { display: flex; align-items: center; gap: 6px; margin-top: 3px; }
  .pr-bu { display: inline-block; padding: 1px 7px; border-radius: 999px; font-size: 10.5px; font-weight: 700; letter-spacing: .02em; }
  .pr-bu.bu-NF { background: #eef2ff; color: #4338ca; }
  .pr-bu.bu-KHAAS { background: #ecfeff; color: #0e7490; }
  .pr-gear { cursor: pointer; color: #9ca3af; font-size: 13px; line-height: 1; }
  .pr-gear:hover { color: #374151; }
  .pr-ratepill { display: inline-block; padding: 1px 7px; border-radius: 999px; font-size: 10.5px; font-weight: 700; background: #f3f4f6; color: #6b7280; }

  /* custom tab: employee cards */
  .pr-cust-list { display: flex; flex-direction: column; gap: 12px; }
  .pr-cust-card { border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; padding: 14px 16px; }
  .pr-cust-top { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; justify-content: space-between; }
  .pr-cust-name { font-weight: 700; color: #111827; font-size: 14px; }
  .pr-cust-meta { display: flex; align-items: center; gap: 8px; margin-top: 3px; }
  .pr-rate { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; padding: 2px 7px; border-radius: 6px; }
  .pr-rate:hover { background: #f3f4f6; }
  .pr-rate .amt { font-weight: 700; color: #111827; }
  .pr-rate .unit { font-size: 11px; color: #9ca3af; }
  .pr-cover { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px; align-items: center; }
  .pr-cover-chip { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; }
  .pr-cover-chip.paid { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
  .pr-cover-chip.none { background: #f9fafb; color: #9ca3af; border: 1px dashed #e5e7eb; cursor: default; }
  .pr-cover-add { display: inline-flex; align-items: center; gap: 5px; padding: 4px 12px; border-radius: 8px; font-size: 12.5px; font-weight: 700; background: #16a34a; color: #fff; border: none; cursor: pointer; }
  .pr-cover-add:hover { background: #15803d; }
  .pr-cust-adv { margin-top: 8px; font-size: 11.5px; color: #b45309; }
  .pr-cust-adv .give { color: #2563eb; cursor: pointer; margin-left: 8px; }
  .pr-cust-adv .give:hover { text-decoration: underline; }
  .pr-cust-adv .view { font-weight: 700; cursor: pointer; text-decoration: underline dotted; }

  /* "waiting for you" banner (advance requests still undecided) */
  .pr-banner { display: flex; align-items: center; gap: 13px; flex-wrap: wrap; border: 1px solid #fcd34d; background: #fffbeb; border-radius: 11px; padding: 12px 15px; margin-bottom: 14px; }
  .pr-banner .ic { font-size: 19px; line-height: 1; }
  .pr-banner .tx { font-size: 13.5px; font-weight: 700; color: #92400e; }
  .pr-banner .sub { font-size: 11.5px; color: #b45309; font-weight: 400; margin-top: 3px; line-height: 1.5; }
  .pr-banner .act { margin-left: auto; height: 34px; padding: 0 16px; border: none; border-radius: 9px; background: #f59e0b; color: #fff; font-weight: 700; font-size: 12.5px; cursor: pointer; white-space: nowrap; }
  .pr-banner .act:hover { background: #d97706; }

  /* ── custom running balance ("khata"): headline, calendar, payments ────────
     Wording over signs everywhere: managers read "To pay" / "Paid ahead", and
     colour carries the same meaning (red = we owe, green = paid ahead). */
  .pr-bal-hero { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; border: 1px solid; border-radius: 11px; padding: 12px 14px; margin-top: 12px; }
  .pr-bal-hero.to_pay { background: #fff7f7; border-color: #fecaca; }
  .pr-bal-hero.paid_ahead { background: #f0fdf4; border-color: #bbf7d0; }
  .pr-bal-hero.settled { background: #f8fafc; border-color: #e5e7eb; }
  .pr-bal-lbl { font-size: 10.5px; font-weight: 800; letter-spacing: .07em; text-transform: uppercase; }
  .pr-bal-hero.to_pay .pr-bal-lbl { color: #b91c1c; }
  .pr-bal-hero.paid_ahead .pr-bal-lbl { color: #047857; }
  .pr-bal-hero.settled .pr-bal-lbl { color: #6b7280; }
  .pr-bal-amt { font-size: 25px; font-weight: 800; letter-spacing: -.02em; color: #111827; line-height: 1.15; }
  .pr-bal-note { font-size: 11.5px; color: #6b7280; margin-top: 2px; }
  .pr-bal-acts { margin-left: auto; display: flex; gap: 8px; flex-wrap: wrap; }
  .pr-bal-btn { height: 34px; padding: 0 14px; border: 1px solid #d1d5db; background: #fff; color: #374151; border-radius: 9px; font-size: 12.5px; font-weight: 700; cursor: pointer; }
  .pr-bal-btn:hover { background: #f9fafb; }
  .pr-bal-btn.primary { background: #16a34a; border-color: #16a34a; color: #fff; }
  .pr-bal-btn.primary:hover { background: #15803d; }
  .pr-bal-stats { display: flex; flex-wrap: wrap; gap: 7px; margin-top: 10px; }
  .pr-bal-stat { background: #f9fafb; border: 1px solid #eef0f2; border-radius: 9px; padding: 6px 11px; font-size: 11.5px; color: #6b7280; }
  .pr-bal-stat b { color: #111827; font-size: 12.5px; font-weight: 700; }
  .pr-bal-start { display: inline-flex; align-items: center; gap: 6px; margin-top: 10px; font-size: 12px; color: #4f46e5; cursor: pointer; font-weight: 700; }
  .pr-bal-start:hover { text-decoration: underline; }
  .pr-bal-warn { margin-top: 9px; font-size: 11.5px; color: #b45309; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 7px 10px; line-height: 1.5; }
  .pr-bal-legacy { margin-top: 11px; padding-top: 10px; border-top: 1px dashed #e5e7eb; }
  .pr-bal-legacy .lbl { font-size: 11px; color: #9ca3af; line-height: 1.5; }
  .pr-bal-mini { border: 1px solid #eef0f2; background: #f9fafb; border-radius: 9px; padding: 9px 12px; margin-bottom: 12px; font-size: 12.5px; color: #374151; }
  .pr-bal-mini b { color: #111827; }

  /* interactive month calendar */
  .pr-modal.wide { max-width: 640px; }
  .pr-cal-nav { display: flex; align-items: center; justify-content: center; gap: 12px; margin: 14px 0 10px; }
  .pr-cal-month { font-size: 14px; font-weight: 700; min-width: 148px; text-align: center; color: #111827; }
  .pr-cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 5px; }
  .pr-cal-dow { text-align: center; font-size: 10px; font-weight: 800; color: #9ca3af; letter-spacing: .05em; }
  .pr-cal-day { position: relative; min-height: 56px; border: 1px solid #e5e7eb; border-radius: 9px; background: #fff; padding: 4px 3px 3px; display: flex; flex-direction: column; align-items: center; gap: 2px; cursor: pointer; transition: background .12s, border-color .12s, transform .07s; }
  .pr-cal-day:active { transform: scale(.95); }
  .pr-cal-day .n { font-size: 12.5px; font-weight: 700; color: #374151; line-height: 1.2; }
  .pr-cal-day.counted:hover { border-color: #16a34a; background: #f0fdf4; }
  .pr-cal-day.crossed { background: #fef2f2; border-color: #fecaca; }
  .pr-cal-day.crossed .n { color: #b91c1c; text-decoration: line-through; }
  .pr-cal-day.future { border-style: dashed; background: #fcfcfd; }
  .pr-cal-day.future .n { color: #9ca3af; }
  .pr-cal-day.crossed.future { background: #fef2f2; }
  .pr-cal-day.pre { background: #fafafa; border-style: dashed; cursor: default; }
  .pr-cal-day.pre .n { color: #d1d5db; }
  .pr-cal-day.today { box-shadow: 0 0 0 2px #16a34a; }
  .pr-cal-day.empty { border: none; background: none; cursor: default; }
  .pr-cal-x { position: absolute; top: 2px; right: 5px; font-size: 10px; font-weight: 900; color: #dc2626; }
  .pr-cal-pay { font-size: 9.5px; font-weight: 800; color: #065f46; background: #d1fae5; border: 1px solid #a7f3d0; border-radius: 5px; padding: 0 4px; line-height: 1.5; cursor: pointer; }
  .pr-cal-pay:hover { background: #a7f3d0; }
  .pr-cal-run { font-size: 9px; font-weight: 700; font-variant-numeric: tabular-nums; margin-top: auto; }
  .pr-cal-run.to_pay { color: #dc2626; }
  .pr-cal-run.paid_ahead { color: #059669; }
  .pr-cal-run.settled { color: #cbd5e1; }
  .pr-cal-foot { margin-top: 12px; display: flex; flex-wrap: wrap; gap: 7px; }
  .pr-cal-hint { font-size: 11.5px; color: #6b7280; text-align: center; margin-top: 9px; }
  .pr-cal-legend { margin-top: 9px; font-size: 10.5px; color: #9ca3af; display: flex; flex-wrap: wrap; gap: 11px; justify-content: center; }
  .pr-cal-key { display: inline-flex; align-items: center; gap: 4px; }
  .pr-cal-sw { width: 11px; height: 11px; border-radius: 4px; border: 1px solid #e5e7eb; display: inline-block; }
  @media (max-width: 520px) {
    .pr-cal-grid { gap: 3px; }
    .pr-cal-day { min-height: 48px; }
    .pr-cal-run { display: none; }
  }

  /* custom pay modal computed line */
  .pr-calc { background: #f9fafb; border: 1px solid #eef0f2; border-radius: 10px; padding: 12px 14px; margin: 4px 0 12px; }
  .pr-calc .row { display: flex; justify-content: space-between; font-size: 12.5px; padding: 3px 0; color: #374151; }
  .pr-calc .row.tot { font-weight: 700; color: #111827; border-top: 1px solid #eef0f2; margin-top: 4px; padding-top: 7px; }
  .pr-calc .ref { font-size: 11px; color: #9ca3af; margin-top: 6px; }
  .pr-field { margin-bottom: 12px; }
  .pr-field label { display: block; font-size: 12px; color: #6b7280; margin-bottom: 4px; }
  .pr-input { width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 8px; padding: 0 10px; font-size: 13px; }
  .pr-input:focus { border-color: #16a34a; outline: none; }
  .pr-radio-row { display: flex; gap: 8px; }
  .pr-radio { flex: 1; border: 1px solid #e5e7eb; border-radius: 9px; padding: 9px 12px; cursor: pointer; text-align: center; font-size: 12.5px; font-weight: 600; color: #6b7280; }
  .pr-radio.active { border-color: #16a34a; background: #f0fdf4; color: #047857; }
/* ── Employee tab (the privacy view) ─────────────────────────────────────── */
.pr-emp-pick{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px;}
.pr-emp-pick .pr-input{height:38px;min-width:240px;}
.pr-emp-hint{font-size:11.5px;color:#9ca3af;}
.pr-emp-head{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px;}
.pr-emp-avatar{width:42px;height:42px;border-radius:10px;background:#1e4d7b;color:#fff;display:flex;
  align-items:center;justify-content:center;font-weight:700;font-size:16px;flex:none;}
.pr-emp-name{font-size:19px;font-weight:700;color:#111827;letter-spacing:-.01em;}
.pr-emp-sub{font-size:11.5px;color:#6b7280;}
.pr-emp-grid{display:grid;grid-template-columns:1.25fr 1fr;gap:14px;align-items:start;}
@media (max-width:900px){.pr-emp-grid{grid-template-columns:1fr;}}
.pr-emp-card{border:1px solid #e5e7eb;border-radius:10px;background:#fff;padding:14px 16px;margin-bottom:14px;}
.pr-emp-card h4{font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;color:#6b7280;
  font-weight:700;margin:0 0 10px;}
.pr-emp-line{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;
  font-size:13px;color:#374151;padding:5px 0;}
.pr-emp-line .lv{font-variant-numeric:tabular-nums;font-weight:600;color:#111827;white-space:nowrap;}
.pr-emp-line.minus .lv{color:#b91c1c;}
.pr-emp-line.tot{border-top:1px solid #e5e7eb;margin-top:6px;padding-top:9px;}
.pr-emp-line.tot .ll{font-weight:700;color:#111827;font-size:13.5px;}
.pr-emp-line.tot .lv{font-size:20px;font-weight:800;}
.pr-emp-why{font-size:11px;color:#9ca3af;line-height:1.45;margin-top:2px;}
.pr-emp-drill{cursor:pointer;text-decoration:underline dotted;text-underline-offset:2px;}
.pr-emp-drill:hover{color:#1e4d7b;}
.pr-emp-tbl{width:100%;border-collapse:collapse;font-size:12px;}
.pr-emp-tbl th{text-align:left;font-size:9.5px;letter-spacing:.07em;text-transform:uppercase;
  color:#6b7280;font-weight:700;padding:6px 7px;border-bottom:1px solid #e5e7eb;white-space:nowrap;}
.pr-emp-tbl td{padding:7px;border-bottom:1px solid #f3f4f6;color:#374151;}
.pr-emp-tbl td.n{text-align:right;font-variant-numeric:tabular-nums;font-weight:600;color:#111827;white-space:nowrap;}
.pr-emp-open{border:1px solid #fcd34d;background:#fffbeb;border-radius:8px;padding:9px 11px;
  font-size:12px;color:#92400e;margin-bottom:12px;}
.pr-emp-acts{display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;}
</style>

<div class="pr-wrap">
  <div class="pr-head">
    <div>
      <h1 class="pr-title">Payroll</h1>
      <div class="pr-sub">Review the month, fix any salary, then pay your team — cash or online.</div>
    </div>
    <div class="pr-monthbar">
      <button class="pr-navbtn" id="prPrev" title="Previous month">‹</button>
      <input type="month" class="pr-month-input" id="prMonth">
      <button class="pr-navbtn" id="prNext" title="Next month">›</button>
      <button class="pr-btn-primary" id="prGen">Generate</button>
    </div>
  </div>

  {{-- "something is waiting for you" banner. Above the tabs on purpose: a pending request
       must not hide behind a tab choice, and it counts EVERY pending request, including
       staff who never appear on the monthly grid. --}}
  <div id="prAlertBanner"></div>

  <div class="pr-tabs">
    <button class="pr-tab active" id="prTabEmployee" data-tab="employee">Employee</button>
    <button class="pr-tab" id="prTabMonthly" data-tab="monthly">Monthly</button>
    <button class="pr-tab" id="prTabCustom" data-tab="custom">Custom schedule</button>
  </div>

  {{-- Monthly view (the original grid) --}}
  {{-- ── EMPLOYEE TAB — the privacy view ──────────────────────────────────────────────
       One person, and nothing about anyone else. It REPLACES the grid rather than floating
       over it, so a manager can turn the laptop round. It is the landing tab on purpose:
       opening Payroll should not put eleven salaries on screen. Nothing but names appears
       until someone is picked. --}}
  <div class="pr-view active" id="prEmployeeView">
    <div class="pr-emp-pick">
      <input type="text" id="prEmpSearch" class="pr-input" placeholder="Search an employee…" autocomplete="off">
      <select class="pr-bank-sel" id="prEmpSelect" style="min-width:220px;"></select>
      <span class="pr-emp-hint" id="prEmpHint"></span>
    </div>
    <div id="prEmpEmpty" class="pr-empty" style="padding:38px 12px;">
      Pick an employee to see their pay for this month, and their history.
    </div>
    <div id="prEmpBody" style="display:none;"></div>
  </div>
  <div class="pr-view" id="prMonthlyView">
    <div class="pr-strip" id="prStrip"></div>

    <div class="pr-tablewrap">
      <table class="pr-table">
        <thead>
          <tr>
            <th style="width:34px;"><input type="checkbox" id="prSelAll"></th>
            <th>Employee</th>
            <th class="num">Base salary</th>
            <th>Attendance</th>
            <th class="pr-late-cell">Late</th>
            <th>Overtime</th>
            <th class="num">Advances</th>
            <th class="num">Deductions</th>
            <th class="num">Net pay</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody id="prBody">
          <tr><td colspan="10" class="pr-empty">Pick a month and press <b>Generate</b>.</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  {{-- Custom-schedule view (date-range / weekly employees) --}}
  <div class="pr-view" id="prCustomView">
    <div class="pr-sub" style="margin-bottom:12px;">
      Employees paid by date range. Enter each period; overlapping days are blocked. A period that
      ends next month is filed under that month.
    </div>
    <div class="pr-cust-list" id="prCustList">
      <div class="pr-empty">Loading…</div>
    </div>
  </div>
</div>

{{-- sticky pay bar --}}
<div class="pr-paybar" id="prPaybar">
  <span class="sel" id="prPaybarSel">0 selected</span>
  <span class="tot" id="prPaybarTot">Rs 0</span>
  <button class="pay" id="prPayBtn">Pay selected →</button>
</div>

{{-- pay modal --}}
<div class="pr-modal-back" id="prPayModal">
  <div class="pr-modal">
    <div class="pr-modal-h">Pay salaries</div>
    <div class="pr-modal-b">
      <div class="pr-paylist" id="prPayList"></div>
      {{-- Leave actions for the selected employees: settle them with this payment, or pay the
           money now and leave them on the Leave-actions panel to decide later. Hidden entirely
           when the selected people have no bonus/penalty leave this month. --}}
      {{-- Deductions exceed salary. Money used to vanish here silently: net clamped to 0 and
           every advance was still marked fully settled, so whatever the salary could not cover
           was written off with nobody deciding. Now the manager is told and chooses. --}}
      {{-- ⭐ Sep-7 2026 — rewritten after Taimur read the old wording as "which deductions am I
           turning off?" and could not tell the two options apart. The confusion was fair: both
           options usually pay the employee exactly the SAME Rs 0, and the old copy never said so.
           What actually differs is how much of the ADVANCE the company gets back. So each option
           now states its two outcomes — take-home, and money never recovered — in that order. --}}
      <div id="prShortBox" style="display:none;border:1px solid #fca5a5;background:#fef2f2;border-radius:9px;padding:10px 12px;margin-bottom:12px;">
        <div style="font-size:12px;font-weight:700;color:#b91c1c;margin-bottom:6px;" id="prShortHead"></div>
        <div id="prShortList" style="font-size:11.5px;color:#7f1d1d;margin-bottom:9px;line-height:1.5;"></div>
        {{-- ⭐ Sep-7 (later): owner ruling — the employee never pays an advance back, it is only ever
             deducted, so the uncovered part MOVES TO NEXT MONTH. That is the default; write-off
             is the alternative the payer can still choose. The "don't deduct absences" option
             is gone from here (two questions, not three) — the server still accepts it. --}}
        <label id="prShortOptCarry" style="display:block;font-size:12px;color:#374151;margin-bottom:7px;cursor:pointer;">
          <input type="radio" name="prShortMode" value="carry" checked style="margin-right:7px;">
          <b id="prShortOptCarryLbl">Move the rest to next month</b>
          <div id="prShortOutCarry" style="margin:2px 0 0 21px;font-size:11.5px;color:#047857;"></div>
        </label>
        <label style="display:block;font-size:12px;color:#374151;cursor:pointer;">
          <input type="radio" name="prShortMode" value="writeoff" style="margin-right:7px;">
          <b>Write it off</b>
          <div id="prShortOutA" style="margin:2px 0 0 21px;font-size:11.5px;color:#7f1d1d;"></div>
        </label>
        <div id="prShortFoot" style="font-size:10.5px;color:#7f1d1d;margin-top:8px;line-height:1.45;"></div>
      </div>
      <div id="prPayAbsWarn" style="display:none;margin-top:10px;font-size:12px;color:#7f1d1d;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:8px 10px;line-height:1.5;"></div>
        <div id="prPayLeaveBox" style="display:none;border:1px solid #e5e7eb;border-radius:9px;padding:10px 12px;margin-bottom:12px;">
        <div style="font-size:12px;color:#374151;font-weight:600;margin-bottom:7px;" id="prPayLeaveSum"></div>
        <label style="display:flex;gap:8px;align-items:flex-start;font-size:12.5px;color:#374151;cursor:pointer;margin-bottom:5px;">
          <input type="radio" name="prLeaveMode" value="apply" checked style="margin-top:2px;">
          <span>Apply them with this payment</span>
        </label>
        <label style="display:flex;gap:8px;align-items:flex-start;font-size:12.5px;color:#374151;cursor:pointer;">
          <input type="radio" name="prLeaveMode" value="defer" style="margin-top:2px;">
          <span>Not now — keep them pending in Leave actions</span>
        </label>
      </div>
      <div style="font-size:12px;color:#6b7280;margin-bottom:8px;">Pay from</div>
      <label class="pr-fund active" data-fund="cash">
        <input type="radio" name="prFund" value="cash" checked style="margin-right:8px;">
        <span class="lbl" id="prCashLabel">NF Cash</span>
      </label>
      <label class="pr-fund" data-fund="online">
        <input type="radio" name="prFund" value="online" style="margin-right:8px;">
        <span class="lbl">Online / bank transfer</span>
        <select class="pr-bank-sel" id="prBankSel" disabled></select>
      </label>
    </div>
    <div class="pr-modal-f">
      <button class="pr-btn-ghost" id="prPayCancel">Cancel</button>
      <button class="pr-btn-primary" id="prPayConfirm">Confirm payment</button>
    </div>
  </div>
</div>

{{-- give-advance modal --}}
<div class="pr-modal-back" id="prAdvModal">
  <div class="pr-modal">
    <div class="pr-modal-h" id="prAdvModalTitle">Give advance</div>
    <div class="pr-modal-b">
      <div style="font-size:13px;color:#374151;margin-bottom:10px;" id="prAdvWho"></div>
      <div style="font-size:12px;color:#6b7280;margin-bottom:4px;">Amount</div>
      <input type="number" id="prAdvAmount" class="pr-bank-sel" style="height:38px;" placeholder="0" min="1">
      {{-- Which month recovers this advance, and (for a past month) when the money really
           moved. Hidden entirely when giving for the current month, which is the common case. --}}
      <div id="prAdvMonthBox" style="display:none;margin-top:12px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;">
        <div style="font-size:12px;font-weight:600;color:#374151;" id="prAdvMonthNote"></div>
        <div id="prAdvDateWrap" style="display:none;margin-top:8px;">
          <div style="font-size:12px;color:#6b7280;margin-bottom:4px;">When did the money actually leave?</div>
          <input type="date" id="prAdvDate" class="pr-bank-sel" style="height:36px;">
          <div style="font-size:11px;color:#9ca3af;margin-top:4px;" id="prAdvDateHint"></div>
        </div>
      </div>
      <div style="font-size:12px;color:#6b7280;margin:12px 0 4px;">Pay from</div>
      <label class="pr-fund active" data-advfund="cash">
        <input type="radio" name="prAdvFund" value="cash" checked style="margin-right:8px;">
        <span class="lbl" id="prAdvCashLabel">NF Cash</span>
      </label>
      <label class="pr-fund" data-advfund="online">
        <input type="radio" name="prAdvFund" value="online" style="margin-right:8px;">
        <span class="lbl">Online / bank transfer</span>
        <select class="pr-bank-sel" id="prAdvBankSel" disabled></select>
      </label>
      <input type="text" id="prAdvNote" class="pr-bank-sel" style="height:36px;margin-top:10px;" placeholder="Note (optional)">
    </div>
    <div class="pr-modal-f">
      <button class="pr-btn-ghost" id="prAdvCancel">Cancel</button>
      <button class="pr-btn-primary" id="prAdvConfirm">Give advance</button>
    </div>
  </div>
</div>

{{-- employee settings modal (pay schedule + business unit) --}}
<div class="pr-modal-back" id="prSetModal">
  <div class="pr-modal">
    <div class="pr-modal-h" id="prSetTitle">Pay settings</div>
    <div class="pr-modal-b">
      <div id="prSetLockNote" style="display:none;"></div>
      <div class="pr-field">
        <label>Pay schedule</label>
        <div class="pr-radio-row" id="prSetSched">
          <div class="pr-radio active" data-sched="monthly">Monthly</div>
          <div class="pr-radio" data-sched="custom">Custom (by date range)</div>
        </div>
      </div>
      <div class="pr-field" id="prSetRateWrap" style="display:none;">
        <label>Rate is per</label>
        <div class="pr-radio-row" id="prSetRate">
          <div class="pr-radio active" data-rate="monthly">Month (base salary)</div>
          <div class="pr-radio" data-rate="daily">Day</div>
        </div>
      </div>
      <div class="pr-field" id="prSetBuWrap" style="display:none;">
        <label>Business unit (salary expense)</label>
        <div class="pr-radio-row" id="prSetBu">
          <div class="pr-radio active" data-bu="NF">Nizami Farms</div>
          <div class="pr-radio" data-bu="KHAAS">Khaas / Frozen</div>
        </div>
      </div>
      <div style="font-size:11.5px;color:#9ca3af;">Changes apply to future payments; already-paid records are unchanged.
        The base salary number keeps its value — if you switch between per-day and per-month, update the rate after saving.</div>
    </div>
    <div class="pr-modal-f">
      <button class="pr-btn-ghost" id="prSetCancel">Cancel</button>
      <button class="pr-btn-primary" id="prSetSave">Save</button>
    </div>
  </div>
</div>

{{-- custom period pay modal --}}
<div class="pr-modal-back" id="prCustModal">
  <div class="pr-modal">
    <div class="pr-modal-h" id="prCustTitle">Add period</div>
    <div class="pr-modal-b">
      <div style="display:flex;gap:10px;">
        <div class="pr-field" style="flex:1;">
          <label>From</label>
          <input type="date" class="pr-input" id="prCustStart">
        </div>
        <div class="pr-field" style="flex:1;">
          <label>To</label>
          <input type="date" class="pr-input" id="prCustEnd">
        </div>
      </div>
      <div class="pr-calc" id="prCustCalc">
        <div class="pr-calc-inner" id="prCustCalcInner"><div class="row"><span>Pick the dates above</span><span></span></div></div>
      </div>
      <div class="pr-field">
        <label>Amount to pay (edit if needed)</label>
        <input type="number" class="pr-input" id="prCustAmount" min="0" placeholder="0">
      </div>
      <div style="font-size:12px;color:#6b7280;margin-bottom:6px;">Pay from</div>
      <label class="pr-fund active" data-custfund="cash">
        <input type="radio" name="prCustFund" value="cash" checked style="margin-right:8px;">
        <span class="lbl" id="prCustCashLabel">NF Cash</span>
      </label>
      <label class="pr-fund" data-custfund="online">
        <input type="radio" name="prCustFund" value="online" style="margin-right:8px;">
        <span class="lbl">Online / bank transfer</span>
        <select class="pr-bank-sel" id="prCustBankSel" disabled></select>
      </label>
      <input type="text" id="prCustNote" class="pr-input" style="margin-top:10px;" placeholder="Note (optional)">
    </div>
    <div class="pr-modal-f">
      <button class="pr-btn-ghost" id="prCustCancel">Cancel</button>
      <button class="pr-btn-primary" id="prCustConfirm">Pay period</button>
    </div>
  </div>
</div>

{{-- ── custom running balance ("khata") ─────────────────────────────────────
     Calendar: one month at a time, every day clickable. Crossing a day means it
     earns nothing; payments show on the day the money was handed over; the small
     number under each day is the balance AFTER that day. --}}
<div class="pr-modal-back" id="prBalCal">
  <div class="pr-modal wide">
    <div class="pr-modal-h" style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
      <span id="prBalCalTitle">Calendar</span>
      <span class="pr-sheet-x" id="prBalCalX">×</span>
    </div>
    <div class="pr-modal-b">
      <div id="prBalCalHero"></div>
      <div class="pr-cal-nav">
        <button class="pr-navbtn" id="prBalCalPrev" title="Previous month">‹</button>
        <div class="pr-cal-month" id="prBalCalMonth">—</div>
        <button class="pr-navbtn" id="prBalCalNext" title="Next month">›</button>
      </div>
      <div class="pr-cal-grid" id="prBalCalDows"></div>
      <div class="pr-cal-grid" id="prBalCalGrid" style="margin-top:5px;"></div>
      <div class="pr-cal-hint" id="prBalCalHint">Tap a day to mark it as not counted. Tap again to undo.</div>
      <div class="pr-cal-foot" id="prBalCalFoot"></div>
      <div class="pr-cal-legend">
        <span class="pr-cal-key"><span class="pr-cal-sw" style="background:#fff;"></span> counted</span>
        <span class="pr-cal-key"><span class="pr-cal-sw" style="background:#fef2f2;border-color:#fecaca;"></span> not counted</span>
        <span class="pr-cal-key"><span class="pr-cal-sw" style="background:#d1fae5;border-color:#a7f3d0;"></span> payment</span>
        <span class="pr-cal-key"><span class="pr-cal-sw" style="background:#fcfcfd;border-style:dashed;"></span> still to come</span>
      </div>
    </div>
    <div class="pr-modal-f">
      <button class="pr-btn-ghost" id="prBalCalClose">Done</button>
      <button class="pr-btn-primary" id="prBalCalPay">＋ Record payment</button>
    </div>
  </div>
</div>

{{-- record a payment against the balance --}}
<div class="pr-modal-back" id="prBalPayModal">
  <div class="pr-modal">
    <div class="pr-modal-h" id="prBalPayTitle">Record payment</div>
    <div class="pr-modal-b">
      <div class="pr-bal-mini" id="prBalPayNow"></div>
      <div style="display:flex;gap:10px;">
        <div class="pr-field" style="flex:1;">
          <label>Amount paid</label>
          <input type="number" class="pr-input" id="prBalPayAmt" min="1" placeholder="0">
        </div>
        <div class="pr-field" style="flex:1;">
          <label>Date paid</label>
          <input type="date" class="pr-input" id="prBalPayDate">
        </div>
      </div>
      <div class="pr-calc"><div class="pr-calc-inner" id="prBalPayAfter"><div class="row"><span>Enter the amount to see the new balance</span><span></span></div></div></div>
      <div style="font-size:12px;color:#6b7280;margin-bottom:6px;">Paid from</div>
      <label class="pr-fund active" data-balfund="cash">
        <input type="radio" name="prBalFund" value="cash" checked style="margin-right:8px;">
        <span class="lbl" id="prBalCashLabel">NF Cash</span>
      </label>
      <label class="pr-fund" data-balfund="online">
        <input type="radio" name="prBalFund" value="online" style="margin-right:8px;">
        <span class="lbl">Online / bank transfer</span>
        <select class="pr-bank-sel" id="prBalBankSel" disabled></select>
      </label>
      <input type="text" id="prBalPayNote" class="pr-input" style="margin-top:10px;" placeholder="Note (optional)">
    </div>
    <div class="pr-modal-f">
      <button class="pr-btn-ghost" id="prBalPayCancel">Cancel</button>
      <button class="pr-btn-primary" id="prBalPayConfirm">Record payment</button>
    </div>
  </div>
</div>

{{-- start a running balance (the anchor + what is owed on day one) --}}
<div class="pr-modal-back" id="prBalEnableModal">
  <div class="pr-modal">
    <div class="pr-modal-h" id="prBalEnTitle">Start a running balance</div>
    <div class="pr-modal-b">
      <div style="font-size:12.5px;color:#4b5563;line-height:1.6;margin-bottom:14px;">
        From the start date, every day earns the daily rate unless you cross it out on the calendar.
        Payments you record are subtracted, and the difference carries forward month after month.
        <b>Nothing before the start date is ever counted</b>, so the past stays closed.
      </div>
      <div class="pr-field">
        <label>Start counting from</label>
        <input type="date" class="pr-input" id="prBalEnStart">
        <div style="font-size:11px;color:#9ca3af;margin-top:4px;" id="prBalEnStartHint"></div>
      </div>
      <div class="pr-field">
        <label>On that date, the account stands at</label>
        <div class="pr-radio-row" id="prBalEnDir" style="flex-wrap:wrap;">
          <div class="pr-radio active" data-baldir="zero">Nothing owed<br><span style="font-weight:400;font-size:11px;">start clean</span></div>
          <div class="pr-radio" data-baldir="ahead">Paid ahead<br><span style="font-weight:400;font-size:11px;">he holds our money</span></div>
          <div class="pr-radio" data-baldir="owe">We owe him<br><span style="font-weight:400;font-size:11px;">unpaid from before</span></div>
        </div>
      </div>
      <div class="pr-field" id="prBalEnAmtWrap" style="display:none;">
        <label id="prBalEnAmtLbl">Amount</label>
        <input type="number" class="pr-input" id="prBalEnAmt" min="0" placeholder="0">
      </div>
      <div id="prBalEnAdvNote"></div>
    </div>
    <div class="pr-modal-f">
      <button class="pr-btn-ghost" id="prBalEnCancel">Cancel</button>
      <button class="pr-btn-primary" id="prBalEnSave">Start balance</button>
    </div>
  </div>
</div>

{{-- change the rate from a chosen date --}}
<div class="pr-modal-back" id="prBalRateModal">
  <div class="pr-modal">
    <div class="pr-modal-h" id="prBalRateTitle">Change rate</div>
    <div class="pr-modal-b">
      <div class="pr-bal-mini" id="prBalRateNow"></div>
      <div style="display:flex;gap:10px;">
        <div class="pr-field" style="flex:1;">
          <label id="prBalRateLbl">New rate per day</label>
          <input type="number" class="pr-input" id="prBalRateAmt" min="1" placeholder="0">
        </div>
        <div class="pr-field" style="flex:1;">
          <label>Applies from</label>
          <input type="date" class="pr-input" id="prBalRateDate">
        </div>
      </div>
      <div style="font-size:11.5px;color:#6b7280;line-height:1.6;">
        Days before that date keep the rate they were worked at — the balance already earned
        is not re-priced. Pick the date the new rate actually started, even if that was
        a few days ago.
      </div>
    </div>
    <div class="pr-modal-f">
      <button class="pr-btn-ghost" id="prBalRateCancel">Cancel</button>
      <button class="pr-btn-primary" id="prBalRateSave">Save rate</button>
    </div>
  </div>
</div>

{{-- date-list side sheet --}}
<div class="pr-sheet-back" id="prSheet">
  <div class="pr-sheet">
    <div class="pr-sheet-h"><span id="prSheetTitle">Details</span><span class="pr-sheet-x" id="prSheetX">×</span></div>
    <div class="pr-sheet-b" id="prSheetBody"></div>
  </div>
</div>

{{-- THE leave-actions row renderer. Loaded before this page's script so NFLeaveActions
     exists by the time anything calls it. The attendance page includes the same file. --}}
@include('partials.leave-actions-panel')

<script>
(function () {
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const fmt = (n) => 'Rs ' + Math.round(Number(n) || 0).toLocaleString('en-PK');
  const el = (id) => document.getElementById(id);
  let ROWS = [];        // computed rows from server (monthly)
  let FUND = { cash: null, banks: [] };
  let CURMONTH = '';
  // ⚠ A slow page + two month clicks used to leave the header on one month and the ROWS on
  // another: whichever fetch answered LAST won, even if it was the older request. On a
  // money screen that means paying August while the header says September. Every loader
  // takes a ticket and a stale answer is dropped.
  let LOAD_SEQ = 0;
  // Minutes in one bonus-leave day, from the server (SHIFT_TARGET_HOURS is configurable).
  let OT_PER_DAY = 540;
  let OT_CARRY_ON = false;
  // "6h 20m" / "45m" — the one place minutes become words on this screen.
  function hm(m) {
    m = Math.max(0, Number(m) || 0);
    const h = Math.floor(m / 60), mm = m % 60;
    if (h > 0) return mm > 0 ? (h + 'h ' + mm + 'm') : (h + 'h');  // "9h", not "9h 0m"
    return mm + 'm';
  }
  let SCHEDULE_AVAILABLE = false; // schema applied → schedule gear + Custom tab usable
  let KHAAS_AVAILABLE = false;    // manager may tag/see Khaas
  let KHAAS_BU_ID = null;         // the Khaas business-unit id (to post)
  let CAN_VOID = false;           // owner-only: may void a wrongly-given advance
  let ADV_SHEET = { row: null, mode: null }; // the advance list currently open in the sheet
  let LEAVE_ACT = null;           // this month's leave actions (card + review panel)
  let SHEET_BACK = null;          // when set, the sheet shows a "‹ back" to this renderer
  let TAB = 'employee';   // the Employee tab is the landing view (owner ruling)
  let CUST_ROWS = [];             // custom-schedule rows
  let CUST_LOADED_MONTH = '';     // month the custom list was last loaded for
  let ADV_SUMMARY = null;
  let ABS_SUMMARY = null;   // closed months whose absences nobody has decided         // page-wide pending-advance totals (banner + strip card)
  let BAL_AVAILABLE = false;      // khata schema applied → running balances usable
  let CAN_VOID_PAY = false;       // Taimur / Shabib: may void a custom-salary payment
  let TODAY = '';                 // the server's today (never trust the browser clock for money)

  // ---- month init ----
  const now = new Date();
  const dflt = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
  el('prMonth').value = dflt;

  function shiftMonth(delta) {
    const [y, m] = el('prMonth').value.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    el('prMonth').value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
  }
  el('prPrev').onclick = () => { shiftMonth(-1); reloadActive(); };
  el('prNext').onclick = () => { shiftMonth(1); reloadActive(); };
  el('prGen').onclick = reloadActive;
  el('prMonth').onchange = reloadActive;

  // ---- load month ----
  // ⭐⭐ Sep-6 2026 — ONE reader for every JSON call on this page.
  //
  // When the server answers with something that is NOT json — a session that expired and
  // redirected to the login page, a 500 rendering Laravel's HTML error page, a stale
  // config/view cache mid-deploy — `res.json()` throws the raw parser message, and the
  // manager is shown "Unexpected non-whitespace character after JSON at position 4". That
  // says nothing about what to do. This turns each of those into a sentence he can act on,
  // and keeps the real parser message only as a last resort for a genuinely malformed body.
  async function getJson(url, opts) {
    const res = await fetch(url, opts || { headers: { 'Accept': 'application/json' } });
    const body = await res.text();
    if (res.status === 401 || res.status === 419) {
      throw new Error('Your session has expired — sign in again and reload this page.');
    }
    const looksLikeHtml = /^\s*[<]/.test(body);
    if (looksLikeHtml) {
      throw new Error(res.ok
        ? 'The server sent a web page instead of data — you may have been signed out. Reload the page.'
        : 'The server hit an error (' + res.status + '). Reload the page; if it keeps happening the server caches need clearing.');
    }
    if (!res.ok) {
      throw new Error('The server refused that (' + res.status + '). Reload the page and try again.');
    }
    try {
      return JSON.parse(body);
    } catch (e) {
      throw new Error('The server sent a broken reply. Reload the page; if it keeps happening the server caches need clearing.');
    }
  }

  async function load() {
    const month = el('prMonth').value;
    const seq = ++LOAD_SEQ;
    el('prGen').disabled = true; el('prGen').textContent = 'Loading…';
    el('prBody').innerHTML = '<tr><td colspan="10" class="pr-empty">Loading…</td></tr>';
    try {
      const j = await getJson('/hr/payroll/data?month=' + encodeURIComponent(month));
      if (seq !== LOAD_SEQ) return;                       // a newer month was asked for — drop this
      if (month !== el('prMonth').value) return;          // …and never paint a month nobody is on
      if (!j.success) throw new Error(j.message || 'Failed');
      CURMONTH = j.month;
      if (j.ot_per_day) OT_PER_DAY = Number(j.ot_per_day) || 540;
      OT_CARRY_ON = !!j.ot_carry_on;
      FUND = j.funding || { cash: null, banks: [] };
      SCHEDULE_AVAILABLE = !!j.schedule_available;
      KHAAS_AVAILABLE = !!j.khaas_available;
      KHAAS_BU_ID = j.khaas_bu_id || null;
      CAN_VOID = !!j.can_void_advance;
      LEAVE_ACT = j.leave_actions || null;
      ADV_SUMMARY = j.advance_summary || null;
      ABS_SUMMARY = j.absence_summary || null;
      ROWS = (j.rows || []).map(r => ({ ...r, _selected: false, _lateOverride: null, _netOverride: null, _skipOvertime: false, _skipLateLeave: false }));
      renderBanner();
      renderStrip();
      renderRows();
      buildFundModal();
      el('prTabCustom').style.display = SCHEDULE_AVAILABLE ? '' : 'none';
      // Actions started from the Employee tab (salary, advance, pay) refresh the grid; the
      // tab must move with it or it shows stale figures next to a fresh grid.
      if (TAB === 'employee' && EMP_ID) empLoad();
    } catch (e) {
      if (seq !== LOAD_SEQ) return;
      el('prBody').innerHTML = '<tr><td colspan="10" class="pr-empty">Could not load payroll: ' + (e.message || e) + '</td></tr>';
    } finally {
      if (seq === LOAD_SEQ) { el('prGen').disabled = false; el('prGen').textContent = 'Generate'; }
    }
  }

  // effective late deduction for a row (override wins)
  function lateDed(r) {
    return (r._lateOverride !== null && r._lateOverride !== '') ? Number(r._lateOverride) : Number(r.late_deduction || 0);
  }
  function net(r) {
    // ⚠ `held_absence_deduction` — a charge for an EARLIER month's parked days, landing on this
    // month's pay — is a deduction like any other and computeRow() has always taken it out of
    // `net_salary`. This copy of the sum left it out, so the grid, the pay modal and the total
    // all showed MORE than the server would actually pay (and the red "negative" styling could
    // disagree with the shortfall dialog, which reads the server's own net_raw).
    const ded = Number(r.absent_deduction || 0) + lateDed(r) + Number(r.advance_total || 0)
              + Number(r.held_absence_deduction || 0);
    return Number(r.base_salary || 0) + Number(r.bonuses || 0) + Number(r.allowances || 0) + Number(r.other || 0) - ded;
  }
  // Whether the manager typed a manual take-home amount, and the effective net to pay.
  function hasNetOverride(r) { return r._netOverride !== null && r._netOverride !== undefined && r._netOverride !== ''; }
  function effNet(r) { return hasNetOverride(r) ? Math.max(0, Number(r._netOverride)) : Math.max(0, net(r)); }

  // The one thing on this page that says "you have something to do". Shown on both tabs,
  // and it counts every pending request — including the ones from people who never appear
  // on a payroll grid, which are exactly the ones that get forgotten.
  function renderBanner() {
    const s = ADV_SUMMARY || {};
    const n = Number(s.count || 0);
    const b = el('prAlertBanner');
    if (!b) return;
    if (!n) { b.innerHTML = ''; return; }

    const bits = [];
    if (s.oldest_days > 0) {
      bits.push('oldest is ' + s.oldest_days + ' day' + (s.oldest_days === 1 ? '' : 's') +
                ' old' + (s.oldest_name ? ' (' + esc(s.oldest_name) + ')' : ''));
    }
    bits.push('no money has been paid out yet');
    if (s.blocked > 0) {
      bits.push('<b>' + s.blocked + '</b> can only be rejected — they have left or are on a running balance');
    }

    b.innerHTML = '<div class="pr-banner">' +
      '<span class="ic">⏳</span>' +
      '<div><div class="tx">' + n + ' advance request' + (n === 1 ? '' : 's') +
        ' waiting for you · ' + fmt(s.total) + '</div>' +
        '<div class="sub">' + bits.join(' · ') + '</div></div>' +
      '<button type="button" class="act" id="prBannerBtn">Review ' + (n === 1 ? 'it' : 'them') + '</button>' +
    '</div>';
    el('prBannerBtn').onclick = () => openRequestSheet();
  }

  function renderStrip() {
    const configured = ROWS.filter(r => r.configured);
    const totalNet = ROWS.reduce((s, r) => s + effNet(r), 0);
    const missing = ROWS.filter(r => !r.configured).length;
    // Requests waiting on a decision — the PAGE total from the server, which is what the
    // review sheet lists. Deriving it from ROWS (as this once did) counted only employees
    // on this grid, so it read "2" while the sheet showed 6: custom-schedule staff, people
    // who have left, and anyone hidden from Payroll raise requests too. The per-row chips
    // still show that row's own requests.
    const reqCount = Number((ADV_SUMMARY || {}).count || 0);
    const reqTotal = Number((ADV_SUMMARY || {}).total || 0);
    el('prStrip').innerHTML =
      card('Employees', ROWS.length) +
      card('With salary set', configured.length) +
      card('Total net (month)', fmt(totalNet)) +
      (missing > 0 ? card('Need salary', missing) : '') +
      (reqCount > 0 ? requestCard(reqCount, reqTotal) : '') +
      leaveCard();
    // The absence nag. It also goes out as a Firebase push to everyone holding manage_payroll
    // (FirebaseService::notifyAbsenceDecisionsDue, once per month), but it lives HERE too
    // because this is where the person who can act on it already is. It keeps showing after
    // the month ends and into the next one: an undecided month silently becomes a cut at Pay.
    const abs = ABS_SUMMARY;
    if (abs && Number(abs.undecided_count || 0) > 0 && !abs.dismissed) {
      const people = abs.employees === 1 ? '1 employee' : abs.employees + ' employees';
      el('prAlertBanner').insertAdjacentHTML('beforeend',
        '<div class="pr-alert" style="border-color:#fca5a5;background:#fef2f2;margin-top:8px;">'
        + '<span style="font-size:20px;">🗓</span>'
        + '<div><div class="t" style="color:#b91c1c;">Absences waiting on a decision — ' + people
        + ', ' + abs.days + ' day' + (abs.days === 1 ? '' : 's') + '</div>'
        + '<div class="s" style="color:#7f1d1d;">'
        + (abs.decide_month ? 'from ' + monthName(abs.decide_month) + ' · ' : '')
        + 'unless you park or excuse them, paying will deduct them</div></div>'
        + '<button type="button" class="act" id="prAbsBannerBtn" style="background:#dc2626;">Decide them</button>'
        + '<span id="prAbsBannerX" title="Hide until next month" style="cursor:pointer;font-size:18px;color:#b91c1c;margin-left:10px;">×</span></div>');
      const ab = document.getElementById('prAbsBannerBtn');
      // Switch to the month that needs deciding first — opening the panel on the month the
      // manager happens to be looking at would show none of the absences the banner counted.
      if (ab) ab.onclick = async () => {
        if (abs.decide_month && abs.decide_month !== el('prMonth').value) {
          el('prMonth').value = abs.decide_month;
          if (TAB !== 'monthly') switchTab('monthly');
          await load();
        }
        openLeavePanel();
      };
      // Dismissal is per USER (server side), so one manager hiding it does not hide it
      // from the other, and it returns next month with a new key.
      const ax = document.getElementById('prAbsBannerX');
      if (ax) ax.onclick = async () => {
        ax.closest('.pr-alert').remove();
        try {
          await fetch('/hr/payroll/dismiss-absence-alert', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ alert_key: abs.alert_key })
          });
        } catch (e) { /* hidden locally either way */ }
      };
    }

    const rc = document.getElementById('prReqCard');
    if (rc) rc.onclick = () => openRequestSheet();
    const lc = document.getElementById('prLeaveCard');
    if (lc) lc.onclick = () => openLeavePanel();
  }

  // ---- leave actions: the month's bonus/penalty leaves ----
  // Amber while anything is undecided, green once the month is settled. Shows the leaves
  // being ADDED and TAKEN AWAY this month — the thing the grid could only say row by row.
  function leaveCard() {
    if (!LEAVE_ACT || !LEAVE_ACT.rows || !LEAVE_ACT.rows.length) return '';
    const s = LEAVE_ACT.summary || {};
    const pending = Number(s.pending_count || 0);
    const open = !LEAVE_ACT.closed;
    const bits = [];
    if (pending > 0) {
      if (s.give_pending) bits.push('+' + s.give_pending + ' bonus');
      if (s.deduct_pending) bits.push('−' + s.deduct_pending + ' late');
    } else {
      if (s.given) bits.push('+' + s.given + ' given');
      if (s.deducted) bits.push('−' + s.deducted + ' deducted');
      if (!s.given && !s.deducted) bits.push('all waived');
    }
    // In-progress month = a preview, not a to-do list: nothing can be decided until the
    // month can no longer change (see monthIsClosed on the server).
    const sub = open
      ? 'still counting · view'
      : (pending > 0 ? pending + ' pending · click to review' : 'settled · view history');
    const amber = pending > 0 && !open;
    const border = amber ? '#fcd34d' : (open ? '#e5e7eb' : '#a7f3d0');
    const bg = amber ? '#fffbeb' : (open ? '#fff' : '#f0fdf4');
    const fg = amber ? '#b45309' : (open ? '#6b7280' : '#047857');
    return '<div class="pr-card" id="prLeaveCard" style="cursor:pointer;border-color:' + border + ';background:' + bg + ';">' +
      '<div class="k" style="color:' + fg + ';">Leave actions</div>' +
      '<div class="v" style="color:' + fg + ';font-size:16px;">' + (bits.join(' · ') || '—') + '</div>' +
      '<div style="font-size:10.5px;color:' + fg + ';opacity:.85;margin-top:2px;">' + sub + '</div></div>';
  }
  function card(k, v) { return '<div class="pr-card"><div class="k">' + k + '</div><div class="v">' + v + '</div></div>'; }

  // Amber, clickable — money NOT yet given, waiting on the manager.
  function requestCard(n, total) {
    return '<div class="pr-card" id="prReqCard" style="cursor:pointer;border-color:#fcd34d;background:#fffbeb;">' +
      '<div class="k" style="color:#92400e;">Advance requests</div>' +
      '<div class="v" style="color:#b45309;">' + n + ' <span style="font-size:12px;font-weight:600;">· ' + fmt(total) + '</span></div>' +
      '<div style="font-size:10.5px;color:#a16207;margin-top:2px;">not given yet · click to review</div></div>';
  }

  function renderRows() {
    if (!ROWS.length) {
      el('prBody').innerHTML = '<tr><td colspan="10" class="pr-empty">No employees for this month.</td></tr>';
      updatePaybar();
      return;
    }
    el('prBody').innerHTML = ROWS.map((r, i) => rowHtml(r, i)).join('');
    // wire per-row interactions
    ROWS.forEach((r, i) => wireRow(r, i));
    updatePaybar();
  }

  // This month's decision for one kind of leave action on a row (null = still pending).
  function laFor(r, kind) { return (r.leave_actions || []).find(a => a.kind === kind) || null; }

  // The settled marker that replaces the bypass toggle once a decision exists. It reads as a
  // fact, not a control, and opens the panel for this employee if it needs changing.
  // ── Day-review standing, as one line under the Overtime / Late cell ──────────────────
  // "✔ 14/14 days verified" or "⚠ 12 of 14 verified · 2 to check ›". This is the whole point
  // of the early review: by month end the manager should be confirming, not investigating.
  // ⭐ Silent when the feature is not installed, when the month is before the start date, and
  // when there was nothing to review — a clean month must not grow a badge saying "0 of 0".
  function reviewChip(r, kind) {
    const dr = r.day_review;
    if (!dr || !dr.enabled) return '';
    const s = dr[kind];
    if (!s || !s.days) return '';
    const drill = kind === 'late' ? 'month_late' : 'month_overtime';
    const title = (kind === 'late' ? 'Late days — ' : 'Overtime days — ') + (r.fullname || '');
    const open = '<span data-drill="' + drill + '" data-uid="' + r.user_id + '"'
      + ' data-drilltitle="' + esc(title) + '" style="cursor:pointer;text-decoration:underline dotted;">';
    if (s.stale) {
      return '<div class="pr-formula" style="color:#b45309;font-weight:700;">' + open + '🔁 '
        + s.stale + ' changed after being checked ›</span></div>';
    }
    if (s.pending > 0) {
      return '<div class="pr-formula" style="color:#b45309;font-weight:700;">' + open + '⚠ '
        + s.reviewed + ' of ' + s.days + ' checked · ' + s.pending + ' to go ›</span></div>';
    }
    const extra = kind === 'late'
      ? (s.waived_minutes > 0 ? ' · ' + hm(s.waived_minutes) + ' waived' : '')
      : (s.changed > 0 ? ' · ' + s.changed + ' adjusted' : '');
    return '<div class="pr-formula" style="color:#047857;font-weight:700;">' + open + '✔ all '
      + s.days + ' checked' + extra + ' ›</span></div>';
  }

  function laSettledTag(a, i) {
    // ⭐ Sep-6 2026 — the same words the panel uses (server-supplied), and the author is on
    //   the SCREEN rather than in a title attribute. A tooltip is invisible on a touch
    //   device and unreadable at a glance, so "who decided this" was effectively missing.
    const txt = a.decided_label || (a.status === 'waived' ? '✕ skipped' : '✓ decided');
    const who = [a.decided_by ? 'by ' + esc(a.decided_by) : '', a.decided_at || ''].filter(Boolean).join(' · ');
    return '<div class="pr-lvtog ' + (a.status === 'waived' ? 'off' : 'on') + '" data-lareview="' + i + '"' +
      ' title="Click to review or change.">' + esc(txt) + ' ›</div>' +
      (who ? '<div class="pr-formula">' + who + '</div>' : '');
  }

  function rowHtml(r, i) {
    // base cell
    let baseCell;
    if (r.configured) {
      baseCell = '<span class="pr-base" data-base="' + i + '"><span class="amt">' + fmt(r.base_salary) + '</span><span class="pen">✎</span></span>';
    } else {
      baseCell = '<span class="pr-setsal" data-base="' + i + '">＋ Set salary</span>';
    }

    // attendance
    // Absences: what is going to HAPPEN to them, not just how many there were. Undecided
    // means the pay will be cut, so say so rather than leaving it to be discovered at Pay.
    const absDec = r.absence_decision;
    // ⭐ Sep-6 2026 — this was the one decision on the grid you could READ but not REACH:
    //   the late and overtime tags open the panel, the absence line just sat there in red.
    //   It is now the same affordance, and it names whoever decided it.
    const absWho = [r.absence_decided_by ? 'by ' + esc(r.absence_decided_by) : '', r.absence_decided_at || '']
      .filter(Boolean).join(' · ');
    const absTag = r.absent_days > 0
      ? '<div class="pr-formula" data-lareview="' + i + '" style="cursor:pointer;text-decoration:underline dotted;color:'
          + (absDec === 'park' ? '#b45309' : absDec === 'excuse' ? '#047857' : '#b91c1c') + ';"'
          + ' title="Click to review or change.">'
          + (absDec === 'park'
              ? 'parked — no cut; overtime days will settle them'
              : absDec === 'excuse'
                ? 'excused — no cut'
                : (absDec === 'cut' ? '✓ deducting ' : 'will deduct ') + fmt(r.absence_raw_deduction))
          + ' ›</div>'
        + (absWho ? '<div class="pr-formula">' + absWho + '</div>' : '')
      : '';
    // Days still owed from EARLIER parked months — the debt follows the employee, so it is
    // shown on every month until it is settled.
    // Absent AFTER the decision — outside it, so it is cut until the manager decides again.
    const absMore = Number(r.absence_undecided_days || 0) > 0
      ? '<div class="pr-formula" style="color:#b91c1c;">' + r.absence_undecided_days + ' absent since the decision — deducting ' + fmt(r.absent_deduction) + '</div>'
      : '';
    const absOwed = Number(r.absence_outstanding || 0) > 0
      ? '<div class="pr-formula" style="color:#b45309;">' + r.absence_owed_label + ' still owed</div>'
      : '';
    const heldAbs = Number(r.held_absence_deduction || 0) > 0
      ? '<div class="pr-formula" style="color:#b91c1c;">+ ' + fmt(r.held_absence_deduction) + ' charged for earlier absences</div>'
      : '';
    const att = '<span class="pr-chip present">' + r.present_days + ' present</span> ' +
      (r.absent_days > 0
        ? '<span class="pr-chip absent" data-drill="month_absent" data-uid="' + r.user_id + '">' + r.absent_days + ' absent</span>'
        : '<span class="pr-chip muted">0 absent</span>') +
      (r.leave_days > 0
        ? ' <span class="pr-chip leave" data-drill="month_leave" data-uid="' + r.user_id + '">' + r.leave_days + ' leave</span>'
        : '') +
      absTag + absMore + absOwed + heldAbs +
      '<div class="pr-formula"><span data-drill="leave_grants" data-uid="' + r.user_id + '" style="cursor:pointer;text-decoration:underline dotted;">leave history ›</span></div>';

    // late cell (with formula + free-form input when a cut applies)
    let lateCell;
    const lm = r.late_minutes || 0;
    const lateTxt = lm > 0 ? (Math.floor(lm / 60) + 'h ' + (lm % 60) + 'm') : '0m';
    const laLate = laFor(r, 'late_penalty');
    if (laLate && laLate.status !== 'pending') {
      // Already decided (here, at pay, or from Attendance) — show the outcome, not a toggle
      // that would silently do nothing. Clicking opens the panel scoped to this employee.
      lateCell = '<span class="pr-chip late" data-drill="month_late" data-uid="' + r.user_id + '" title="Click to see the late days">' + lateTxt + '</span>' +
        laSettledTag(laLate, i);
    } else if (r.late_flag) {
      const flagTitle = r.late_flag === 'over_step'
        ? 'Late over ' + Math.round(r.late_step_min / 60) + 'h this month → salary cut for all late hours (no leave used).'
        : 'No leaves left to absorb lateness → salary cut for all late hours.';
      lateCell = '<span class="pr-chip late" data-drill="month_late" data-uid="' + r.user_id + '" title="Click to see the late days">' + lateTxt + '</span><span class="pr-flag" title="' + flagTitle + '">⚠</span>' +
        '<div class="pr-formula">' + fmt(r.per_hour) + '/hr × ' + (lm / 60).toFixed(1) + 'h</div>' +
        '<input type="number" class="pr-ded-input" data-lateded="' + i + '" value="' + Math.round(lateDed(r)) + '" min="0">';
    } else if (r.late_leave_deduct > 0) {
      // Recommendation: remove 1 leave for lateness. Manager can bypass (keep the leave); applied on Pay.
      lateCell = '<span class="pr-chip late' + (r._skipLateLeave ? ' dim' : '') + '" data-drill="month_late" data-uid="' + r.user_id + '" title="Click to see the late days">' + lateTxt + '</span>' +
        '<div class="pr-formula">−' + r.late_leave_deduct + ' leave (no pay cut)</div>' +
        '<div class="pr-lvtog ' + (r._skipLateLeave ? 'off' : 'on') + '" data-skiplate="' + i + '" title="Deduct 1 leave for lateness? Click to toggle. Applied when you Pay.">' + (r._skipLateLeave ? '✕ kept (not deducted)' : '✓ will deduct') + '</div>';
    } else {
      lateCell = '<span class="pr-chip muted">' + lateTxt + '</span>' + (lm > 0 ? '<div class="pr-formula">within free buffer</div>' : '');
    }
    // ⭐ What a manager forgave, said out loud. Without this the month simply shows a smaller
    // number than the days add up to, and it reads as the engine changing its mind.
    const lateWaived = Number(r.late_waived_minutes || 0);
    if (lateWaived > 0) {
      lateCell += '<div class="pr-formula">' + hm(Number(r.late_raw_minutes || 0)) + ' late · '
        + hm(lateWaived) + ' waived · ' + hm(lm) + ' counts</div>';
    }
    lateCell += reviewChip(r, 'late');

    // overtime → bonus leave (manager can bypass; applied on Pay, or decided in the panel)
    // Overtime now reads like the Late cell: the MINUTES first, then the formula that turns
    // them into whole leave days, then the decision. Showing only "+1 bonus leave" hid both
    // how much was worked and how much was left over.
    const laOt = laFor(r, 'overtime');
    const otMin = Number(r.overtime_minutes || 0);
    const carryIn = Number(r.ot_carry_in || 0);
    const carryOut = Number(r.ot_carry_out || 0);
    const leavesTxt = (n) => '+' + n + ' bonus leave' + (n > 1 ? 's' : '');
    // One month reads "7h 45m carried from Aug"; two or more need the split spelled out,
    // because the whole point is that the employee can see which month is still owed.
    const carryTxt = (mins, label, verb) => {
      if (!label) return hm(mins) + ' ' + verb;
      if (label.indexOf(' + ') === -1) {
        const at = label.indexOf(' from ');
        if (at > -1) return hm(mins) + ' ' + verb + esc(label.slice(at));
      }
      return hm(mins) + ' ' + verb + ' (' + esc(label) + ')';
    };
    // "9h 0m × 1 = 1 leave · 6h 20m carried" — the same shape as the late formula line.
    const otFormula = () => {
      if (!otMin && !carryIn) return '';
      const bits = [];
      if (carryIn > 0) bits.push(carryTxt(carryIn, r.ot_carry_in_label, 'carried in'));
      bits.push('÷ ' + hm(OT_PER_DAY) + ' = ' + (r.bonus_leaves > 0 ? leavesTxt(r.bonus_leaves) : 'no leave yet'));
      if (carryOut > 0) bits.push(carryTxt(carryOut, r.ot_carry_out_label, 'carried'));
      return '<div class="pr-formula">' + bits.join(' · ') + '</div>';
    };
    // An ended month left undecided: its leftover is NOT carried yet, but hiding the figure
    // would make it invisible. Shown as a to-do naming the month that has to be settled.
    const pend = r.ot_carry_pending;
    const pendLine = pend
      ? '<div class="pr-formula" style="color:#b45309;">' + hm(pend.minutes) + ' waiting from '
        + monthName(pend.month) + ' — carries once that month is paid or its overtime is decided</div>'
      : '';
    let ot;
    if (otMin > 0 || carryIn > 0 || r.bonus_leaves > 0) {
      const dim = (!laOt || laOt.status === 'pending') && r._skipOvertime ? ' dim' : '';
      ot = '<span class="pr-chip ot' + dim + '" data-drill="month_overtime" data-uid="' + r.user_id + '"'
         + ' title="Click to see the overtime days">' + (otMin > 0 ? hm(otMin) : 'carried only') + '</span>'
         + otFormula();
      if (laOt && laOt.status !== 'pending') {
        ot += laSettledTag(laOt, i);
      } else if (r.bonus_leaves > 0) {
        // ⚠ Skipping forfeits the carried minutes too (owner ruling) — the toggle says so
        // BEFORE it is clicked rather than after the leaves are gone.
        const warn = carryIn > 0
          ? ' Skipping also forfeits the ' + hm(carryIn) + ' carried from earlier months.'
          : '';
        ot += '<div class="pr-lvtog ' + (r._skipOvertime ? 'off' : 'on') + '" data-skipot="' + i + '"'
            + ' title="Give this overtime bonus to the employee? Click to toggle. Applied when you Pay.' + warn + '">'
            + (r._skipOvertime ? '✕ bypassed' + (carryIn > 0 ? ' (carry lost)' : '') : '✓ will add') + '</div>';
      }
    } else {
      ot = '<span class="pr-chip muted">—</span>';
    }
    ot += pendLine + reviewChip(r, 'overtime');

    // advances (+ always offer "give advance")
    // The amber "requested" chip is money NOT given: it is deliberately rendered BELOW the real
    // total, in a different colour, with its own wording — it never joins advance_total, the
    // deductions breakdown or net pay.
    const pendReq = (r.pending_requests || []).length;
    const reqChip = pendReq > 0
      ? '<div><span data-reqrow="' + i + '" title="Employee asked for an advance — not given yet. Click to decide."' +
        ' style="display:inline-block;margin-top:3px;font-size:10.5px;font-weight:700;color:#b45309;background:#fef3c7;' +
        'border:1px solid #fcd34d;border-radius:5px;padding:1px 6px;cursor:pointer;white-space:nowrap;">⏳ ' +
        pendReq + ' requested · ' + fmt(r.pending_request_total) + '</span>' +
        '<div style="font-size:9.5px;color:#a16207;line-height:1.2;">not given yet</div></div>'
      : '';
    // Advances tagged to OTHER months. They are not in this month's deductions or net pay
    // (an advance is recovered only from the month it was given for), but the money HAS
    // left, so it is shown here — month-scoping must never make cash invisible.
    const otherOpen = Number(r.other_open_advance_total || 0);
    const otherChip = otherOpen > 0
      ? '<div style="font-size:9.5px;color:#6b7280;line-height:1.25;margin-top:2px;"'
        + ' title="Given for a different month — recovered from that month’s pay, not this one.">'
        + fmt(otherOpen) + ' open in other months</div>'
      : '';
    const adv = (r.advance_total > 0
        ? '<span class="pr-adv" data-adv="' + i + '">' + fmt(r.advance_total) + '</span>'
        : '<span class="pr-adv zero">—</span>') +
      otherChip +
      reqChip +
      '<div><span class="pr-give" data-give="' + i + '">＋ advance</span></div>';

    // The held charge for an earlier month's parked days is part of what is deducted;
    // leaving it out would make the Deductions column disagree with Net pay.
    const totalDed = Number(r.absent_deduction || 0) + lateDed(r) + Number(r.advance_total || 0)
                   + Number(r.held_absence_deduction || 0);
    const n = net(r);
    const ov = hasNetOverride(r);
    const shownNet = effNet(r);
    // Net pay cell: click to type a manual take-home amount (bypasses attendance deductions).
    const netCell = '<span class="pr-net' + (!ov && n < 0 ? ' neg' : '') + '" data-net="' + i + '" data-netedit="' + i + '" style="cursor:pointer;" title="Click to set a manual amount to pay">' + fmt(shownNet) + ' <span class="pen" style="font-size:11px;color:#9ca3af;">✎</span></span>' +
      (ov
        ? '<div class="pr-formula" style="color:#7c3aed;">manual · <span data-netclear="' + i + '" style="cursor:pointer;text-decoration:underline dotted;">use calc ' + fmt(Math.max(0, n)) + '</span></div>'
        : (n < 0 ? '<div class="pr-formula" style="color:#b91c1c;">deductions exceed salary</div>' : ''));

    const selectable = r.configured && !r.paid;
    const statusCell = r.paid
      ? '<span class="pr-chip paid" data-paydetail="' + i + '" style="cursor:pointer;" title="View payment details">✓ Paid ' + fmt(r.paid_net != null ? r.paid_net : n) + '</span>' +
        '<div class="pr-formula"><span data-paydetail="' + i + '" style="cursor:pointer;text-decoration:underline dotted;">details</span>' + (r.paid_at ? ' · ' + String(r.paid_at).slice(0, 10) : '') + '</div>' +
        (paidFromLabel(r.paid_detail) ? '<div class="pr-formula">' + paidFromLabel(r.paid_detail) + '</div>' : '')
      : '<span class="pr-chip unpaid">Unpaid</span>';

    return '<tr data-row="' + i + '"' + (r.paid ? ' style="opacity:.72;"' : '') + '>' +
      '<td>' + (selectable ? '<input type="checkbox" class="pr-rowchk" data-chk="' + i + '"' + (r._selected ? ' checked' : '') + '>' : '') + '</td>' +
      '<td><div class="pr-emp-name">' + esc(r.fullname) + '</div>' +
        (r.designation || r.employee_code ? '<div class="pr-emp-sub">' + esc([r.designation, r.employee_code].filter(Boolean).join(' · ')) + '</div>' : '') +
        empTags(r, i) +
        (r.staff_expense_count > 0 ? '<div class="pr-dblpay" data-dblpay="' + i + '" style="cursor:pointer;" title="Click to see the Staff Salaries expense records. Paying here too would pay them twice.">⚠ ' + fmt(r.staff_expense_total) + ' via expense · view ›</div>' : '') + '</td>' +
      '<td class="num">' + baseCell + '</td>' +
      '<td>' + att + '</td>' +
      '<td class="pr-late-cell">' + lateCell + '</td>' +
      '<td>' + ot + '</td>' +
      '<td class="num">' + adv + '</td>' +
      '<td class="num"><span class="pr-ded-link" data-ded="' + i + '" data-dedrow="' + i + '">' + fmt(totalDed) + '</span></td>' +
      '<td class="num">' + netCell + '</td>' +
      '<td>' + statusCell + '</td>' +
      '</tr>';
  }

  // Business-unit chip + settings gear shown under an employee's name.
  function empTags(r, i) {
    if (!SCHEDULE_AVAILABLE) return '';
    let chip = '';
    if (KHAAS_AVAILABLE) {
      const code = r.bu_code === 'KHAAS' ? 'KHAAS' : 'NF';
      chip = '<span class="pr-bu bu-' + code + '">' + (code === 'KHAAS' ? 'Khaas' : 'NF') + '</span>';
    }
    return '<div class="pr-emp-tags">' + chip +
      '<span class="pr-gear" data-settings="' + i + '" title="Pay schedule & business unit">⚙ settings</span></div>';
  }

  function wireRow(r, i) {
    // base edit
    const baseSpan = document.querySelector('[data-base="' + i + '"]');
    if (baseSpan) baseSpan.onclick = () => editBase(r, i);
    // settings gear (schedule + business unit)
    const gear = document.querySelector('[data-settings="' + i + '"]');
    if (gear) gear.onclick = () => openSettings(r);
    // checkbox
    const chk = document.querySelector('[data-chk="' + i + '"]');
    if (chk) chk.onchange = () => { r._selected = chk.checked; updatePaybar(); syncSelAll(); };
    // late override
    const ld = document.querySelector('[data-lateded="' + i + '"]');
    if (ld) ld.oninput = () => {
      r._lateOverride = ld.value;
      ld.classList.toggle('override', Number(ld.value) !== Math.round(Number(r.late_deduction || 0)));
      refreshMoney(r, i);
    };
    // advances drill
    const adv = document.querySelector('[data-adv="' + i + '"]');
    if (adv) adv.onclick = () => showAdvances(r);
    // give advance
    const give = document.querySelector('[data-give="' + i + '"]');
    if (give) give.onclick = () => openAdvance(r);
    // pending-request chip → the same review sheet, filtered to this employee
    const rq = document.querySelector('[data-reqrow="' + i + '"]');
    if (rq) rq.onclick = () => openRequestSheet(r.user_id);
    // deductions breakdown
    const ded = document.querySelector('[data-dedrow="' + i + '"]');
    if (ded) ded.onclick = () => showDeductions(r);
    // manual net override: click the net to type an amount, or clear back to computed
    const ne = document.querySelector('[data-netedit="' + i + '"]');
    if (ne) ne.onclick = () => editNet(r, i);
    const nc = document.querySelector('[data-netclear="' + i + '"]');
    if (nc) nc.onclick = (ev) => { ev.stopPropagation(); r._netOverride = null; renderRows(); };
    // double-pay chip → the underlying Staff Salaries expense records
    const dp = document.querySelector('[data-dblpay="' + i + '"]');
    if (dp) dp.onclick = () => showStaffExpense(r);
    // paid detail (the frozen receipt)
    document.querySelectorAll('[data-paydetail="' + i + '"]').forEach(pd => {
      pd.onclick = () => showPaidDetail(r);
    });
    // a settled leave action → the review panel, scoped to this employee
    document.querySelectorAll('[data-lareview="' + i + '"]').forEach(t => {
      t.onclick = () => openLeavePanel(r.user_id);
    });
    // bypass toggles: overtime bonus + late-leave deduction (in-place, no full re-render)
    const sot = document.querySelector('[data-skipot="' + i + '"]');
    if (sot) sot.onclick = () => {
      r._skipOvertime = !r._skipOvertime;
      sot.classList.toggle('off', r._skipOvertime); sot.classList.toggle('on', !r._skipOvertime);
      sot.textContent = r._skipOvertime ? '✕ bypassed' : '✓ will add';
      const chip = sot.parentElement.querySelector('.pr-chip.ot'); if (chip) chip.classList.toggle('dim', r._skipOvertime);
    };
    const slate = document.querySelector('[data-skiplate="' + i + '"]');
    if (slate) slate.onclick = () => {
      r._skipLateLeave = !r._skipLateLeave;
      slate.classList.toggle('off', r._skipLateLeave); slate.classList.toggle('on', !r._skipLateLeave);
      slate.textContent = r._skipLateLeave ? '✕ kept (not deducted)' : '✓ will deduct';
      const chip = slate.parentElement.querySelector('.pr-chip.late'); if (chip) chip.classList.toggle('dim', r._skipLateLeave);
    };
  }

  function refreshMoney(r, i) {
    // The held charge for an earlier month's parked days is part of what is deducted;
    // leaving it out would make the Deductions column disagree with Net pay.
    const totalDed = Number(r.absent_deduction || 0) + lateDed(r) + Number(r.advance_total || 0)
                   + Number(r.held_absence_deduction || 0);
    const dedCell = document.querySelector('[data-ded="' + i + '"]');
    const netCell = document.querySelector('[data-net="' + i + '"]');
    if (dedCell) dedCell.textContent = fmt(totalDed);
    if (netCell) {
      netCell.innerHTML = fmt(effNet(r)) + ' <span class="pen" style="font-size:11px;color:#9ca3af;">✎</span>';
      netCell.classList.toggle('neg', !hasNetOverride(r) && net(r) < 0);
    }
    updatePaybar();
  }

  // Inline edit of the manual take-home amount (empty clears it back to the computed net).
  function editNet(r, i) {
    const span = document.querySelector('[data-netedit="' + i + '"]');
    if (!span) return;
    const td = span.closest('td');
    const cur = hasNetOverride(r) ? Math.round(Number(r._netOverride)) : Math.round(Math.max(0, net(r)));
    td.innerHTML = '<input type="number" class="pr-base-input" value="' + cur + '" min="0" style="width:112px;">';
    const inp = td.querySelector('input'); inp.focus(); inp.select();
    const commit = () => {
      const v = inp.value.trim();
      r._netOverride = (v === '') ? null : String(Math.max(0, Number(v)));
      renderRows();
    };
    inp.onkeydown = (ev) => { if (ev.key === 'Enter') commit(); if (ev.key === 'Escape') renderRows(); };
    inp.onblur = commit;
  }

  // The Staff Salaries expense records behind a row's double-pay warning.
  async function showStaffExpense(r) {
    openSheet('Staff-salary expenses — ' + r.fullname, 'Loading…');
    try {
      const res = await fetch('/hr/payroll/staff-expense-detail?user_id=' + r.user_id + '&month=' + CURMONTH, { headers: { 'Accept': 'application/json' } });
      const j = await res.json();
      if (!j.success) throw new Error(j.message || 'Failed');
      const recs = j.records || [];
      if (!recs.length) { el('prSheetBody').innerHTML = '<div class="pr-empty">No records found.</div>'; return; }
      const total = recs.reduce((s, x) => s + Number(x.amount || 0), 0);
      el('prSheetBody').innerHTML = recs.map(x =>
        '<div class="pr-daterow"><div><div class="dt">' + fmt(x.amount) + '</div><div class="lb">' + esc(x.request_number || '') + (x.category ? ' · ' + esc(x.category) : '') + '</div></div><span class="lb">' + esc(x.date || '') + '</span></div>'
      ).join('') +
      '<div class="pr-daterow" style="font-weight:700;border-top:2px solid #eef0f2;"><span class="dt">Total</span><span class="dt">' + fmt(total) + '</span></div>' +
      '<div style="padding:12px 14px;font-size:11.5px;color:#9ca3af;">Approved “Staff Salaries” expense entries for this person in ' + esc(monthLabel()) + '. Paying salary here as well would pay them twice — only pay if these were for something else.</div>';
    } catch (e) {
      el('prSheetBody').innerHTML = '<div class="pr-empty">Could not load: ' + (e.message || e) + '</div>';
    }
  }

  // ---- inline base edit ----
  function editBase(r, i) {
    const td = document.querySelector('[data-base="' + i + '"]').closest('td');
    const cur = r.configured ? Math.round(r.base_salary) : '';
    td.innerHTML = '<input type="number" class="pr-base-input" value="' + cur + '" placeholder="0" min="0">';
    const inp = td.querySelector('input');
    inp.focus(); inp.select();
    const commit = async () => {
      const val = inp.value.trim();
      if (val === '' || Number(val) < 0) { renderRows(); return; }
      inp.disabled = true;
      try {
        const res = await fetch('/hr/payroll/set-salary', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
          body: JSON.stringify({ user_id: r.user_id, base_salary: Number(val) })
        });
        const j = await res.json();
        if (!j.success) throw new Error(j.message || 'Failed');
        await load(); // recompute the month with the new base
      } catch (e) {
        alert('Could not save salary: ' + (e.message || e));
        renderRows();
      }
    };
    inp.onkeydown = (ev) => { if (ev.key === 'Enter') commit(); if (ev.key === 'Escape') renderRows(); };
    inp.onblur = commit;
  }

  // ---- select all ----
  const isPayable = (r) => r.configured && !r.paid;
  el('prSelAll').onchange = () => {
    const on = el('prSelAll').checked;
    ROWS.forEach((r, i) => { if (isPayable(r)) { r._selected = on; const c = document.querySelector('[data-chk="' + i + '"]'); if (c) c.checked = on; } });
    updatePaybar();
  };
  function syncSelAll() {
    const payable = ROWS.filter(isPayable);
    el('prSelAll').checked = payable.length > 0 && payable.every(r => r._selected);
  }

  function selectedRows() { return ROWS.filter(r => r._selected && isPayable(r)); }
  function updatePaybar() {
    const sel = selectedRows();
    const tot = sel.reduce((s, r) => s + effNet(r), 0);
    el('prPaybarSel').textContent = sel.length + ' selected';
    el('prPaybarTot').textContent = fmt(tot);
    el('prPaybar').classList.toggle('show', sel.length > 0);
  }

  // ---- drilldown (absent/leave/overtime dates) ----
  // Renders the day-by-day evidence behind a number. Shared by the grid chips and by the
  // leave-actions panel; when the panel opened it, a "‹ back" returns there instead of
  // dead-ending on a date list.
  function wireSheetBack() {
    const b = el('prSheetBack');
    if (b) b.onclick = () => { const f = SHEET_BACK; SHEET_BACK = null; f && f(); };
  }
  function fmtMinsShort(n) { n = Number(n) || 0; const h = Math.floor(n / 60), m = n % 60; return h > 0 ? (h + 'h ' + m + 'm') : (m + 'm'); }

  // The evidence under an overtime day — check-in, the checkout AS RECORDED, and, when that
  // checkout rode a manager bypass, the end the overtime was actually counted to (the last
  // delivered order). Deliberately a COPY of bdDayMeta() on the attendance page, wording and
  // all: a manager comparing the two screens must read the same sentence about the same day.
  // The server sends this in `meta` on month_overtime rows (OvertimeService builds it in the
  // same loop as the minutes, so the times can never contradict the figure beside them).
  function dayMetaHtml(m) {
    if (!m) return '';
    const strong = (v) => '<b style="color:#111827;font-weight:600;">' + esc(v) + '</b>';
    // Late day: what the check-in was measured against, then the check-in itself.
    if (m.shift_start || (m.login && !m.logout)) {
      const lb = [];
      if (m.shift_start) lb.push('shift ' + strong(m.shift_start));
      if (m.login) lb.push('in ' + strong(m.login));
      return lb.length
        ? '<div style="font-size:11px;color:#6b7280;margin-top:2px;" title="' +
          esc('Checked in at ' + (m.login || '?') + ' against a ' + (m.shift_start || '?') + ' shift start.') + '">'
          + lb.join(' <span style="color:#d1d5db;">→</span> ') + '</div>'
        : '';
    }
    const bits = [];
    if (m.login)  bits.push('in ' + strong(m.login));
    if (m.logout) bits.push('out ' + strong(m.logout));
    const sums = 'Counted ' + fmtMinsShort(m.worked_minutes) + ' worked against a ' + fmtMinsShort(m.target_minutes) + ' target.';
    let html = bits.length
      ? '<div style="font-size:11px;color:#6b7280;margin-top:2px;" title="' + esc(sums) + '">'
        + bits.join(' <span style="color:#d1d5db;">→</span> ') + '</div>'
      : '';
    const cc = m.counted;
    if (cc && cc.time) {
      html += '<div style="font-size:11px;font-weight:700;color:#b45309;margin-top:2px;" title="'
        + esc('This checkout used a manager bypass, so its time is when the valve was used — not when the work ended. The day is counted up to the last delivered order at ' + cc.time + '. ' + sums)
        + '">🔓 bypassed · counted ' + esc(cc.time) + ' · last delivery</div>';
    }
    // How busy the day actually was — the case for the overtime, beside the hours themselves.
    if (m.orders) {
      const span = (m.first_delivery && m.last_delivery)
        ? ' · ' + esc(m.first_delivery) + ' → ' + esc(m.last_delivery) : '';
      html += '<div style="font-size:11px;color:#6b7280;margin-top:2px;" title="'
        + esc('Orders this rider delivered on this day, first to last.')
        + '">📦 ' + m.orders + ' order' + (m.orders === 1 ? '' : 's') + ' delivered' + span + '</div>';
    }
    return html;
  }

  // ── Day review (Sep-2026) ────────────────────────────────────────────────
  // The drill is also where a manager JUDGES a day: verify the figure, adjust it, or waive
  // late minutes. ⭐ An unreviewed day still counts in full — these controls only record
  // what he has actually looked at, they never decide anything by themselves.
  let DR_KIND = null;      // 'overtime' | 'late' while a reviewable drill is open
  let DR_UID  = null;
  let DR_ROWS = [];        // the drill rows currently on screen

  function drStatusHtml(rv, kind) {
    if (!rv) return '';
    if (rv.stale) {
      return '<div class="pr-dr-flag">🔁 changed since ' + esc(rv.by || 'it was reviewed')
        + ' looked at it — needs a fresh check</div>';
    }
    if (rv.status === 'pending') return '';
    const who = [rv.by ? 'by ' + esc(rv.by) : '', rv.at || ''].filter(Boolean).join(' · ');
    let txt;
    if (rv.status === 'verified') txt = '✔ verified';
    else if (kind === 'late') txt = '⏳ ' + hm(rv.waived) + ' waived';
    else txt = rv.status === 'waived' ? '✕ not overtime' : '✎ set to ' + hm(rv.effective);
    return '<div class="pr-dr-done">' + txt + (who ? ' · ' + who : '')
      + (rv.reason ? ' · “' + esc(rv.reason) + '”' : '') + '</div>';
  }

  function drActionsHtml(rv, kind, i) {
    if (!rv) return '';
    if (rv.not_ready) {
      return '<div class="pr-dr-wait">' + esc(rv.not_ready) + ' — review this once the day is closed</div>';
    }
    const decided = rv.status !== 'pending' && !rv.stale;
    if (decided) {
      return '<span class="pr-dr-link" data-drchange="' + i + '">change ›</span>';
    }
    return kind === 'late'
      ? '<button type="button" class="pr-dr-btn ok" data-drdo="' + i + '" data-drv="verified">Verify</button>'
        + '<button type="button" class="pr-dr-btn" data-drdo="' + i + '" data-drv="waived">Waive minutes…</button>'
      : '<button type="button" class="pr-dr-btn ok" data-drdo="' + i + '" data-drv="verified">Verify</button>'
        + '<button type="button" class="pr-dr-btn" data-drdo="' + i + '" data-drv="adjusted">Adjust…</button>'
        + '<button type="button" class="pr-dr-btn" data-drdo="' + i + '" data-drv="waived">Not overtime…</button>';
  }

  function renderDateList(dates) {
    DR_ROWS = dates || [];
    const back = SHEET_BACK ? '<div class="pr-la-back" id="prSheetBack">‹ back to leave actions</div>' : '';
    const head = DR_KIND ? drHeadHtml() : '';
    const body = (dates && dates.length)
      ? dates.map((d, i) => {
          const dt = new Date(d.date + 'T00:00:00');
          const lbl = dt.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
          const meta = dayMetaHtml(d.meta);
          const rv = d.review || null;
          const extra = DR_KIND ? (drStatusHtml(rv, DR_KIND) + '<div class="pr-dr-acts">' + drActionsHtml(rv, DR_KIND, i) + '</div>') : '';
          return '<div class="pr-daterow" style="align-items:' + (meta || extra ? 'flex-start' : 'center') + ';">' +
            '<div style="min-width:0;"><span class="dt">' + lbl + '</span>' + meta + extra + '</div>' +
            (d.label ? '<span class="lb" style="white-space:nowrap;">' + esc(d.label) + '</span>' : '') + '</div>';
        }).join('')
      : '<div class="pr-empty">Nothing to show.</div>';
    el('prSheetBody').innerHTML = back + head + body;
    wireSheetBack();
    wireDayReview();
  }

  function drHeadHtml() {
    const n = DR_ROWS.filter(d => d.review && d.review.status === 'pending' && !d.review.not_ready).length;
    const stale = DR_ROWS.filter(d => d.review && d.review.stale).length;
    if (!DR_ROWS.length) return '';
    // Oct-2026 (owner ruling): a processed month can still be reviewed — but said up front.
    const paid = (DR_ROWS.find(d => d.review && d.review.pay_processed) || {}).review;
    return (paid ? '<div class="pr-la-note" style="color:#b91c1c;border-color:#fecaca;background:#fef2f2;">💰 '
        + esc(paid.pay_processed) + '</div>' : '')
      + '<div class="pr-la-note">'
      + (n ? '<b>' + n + '</b> ' + (n === 1 ? 'day still needs' : 'days still need') + ' a look. '
           : 'Every day here has been looked at. ')
      + (stale ? '<span style="color:#b45309;">' + stale + ' changed after being reviewed.</span> ' : '')
      + 'Verifying changes nothing — it records that the figure is right. Only an adjustment or a waive moves a number.'
      + '</div>';
  }

  function wireDayReview() {
    document.querySelectorAll('[data-drchange]').forEach(c => {
      c.onclick = () => {
        const d = DR_ROWS[Number(c.getAttribute('data-drchange'))];
        if (d && d.review) { d.review = { ...d.review, status: 'pending' }; renderDateList(DR_ROWS); }
      };
    });
    document.querySelectorAll('[data-drdo]').forEach(b => {
      b.onclick = () => {
        const d = DR_ROWS[Number(b.getAttribute('data-drdo'))];
        if (d) recordDayReview(d, b.getAttribute('data-drv'));
      };
    });
  }

  async function recordDayReview(row, verdict) {
    const rv = row.review || {};
    const body = { user_id: DR_UID, date: row.date, kind: DR_KIND, verdict: verdict };
    if (verdict !== 'verified' && rv.pay_processed
        && !confirm('⚠ PAY ALREADY PROCESSED\n\n' + rv.pay_processed + '\n\nGo ahead anyway?')) return;
    if (verdict === 'adjusted') {
      const v = prompt('How many minutes of overtime did he really do on ' + row.date + '?\n\n'
        + 'The system counted ' + hm(rv.minutes || 0) + '.', String(rv.minutes || 0));
      if (v === null) return;
      const n = Number(v);
      if (!isFinite(n) || n < 0) { alert('Give the minutes as a number.'); return; }
      body.minutes = Math.round(n);
    }
    if (verdict === 'waived' && DR_KIND === 'late') {
      const v = prompt('How many of the ' + (rv.minutes || 0) + ' late minutes on ' + row.date
        + ' are being waived?', String(rv.minutes || 0));
      if (v === null) return;
      const n = Number(v);
      if (!isFinite(n) || n <= 0) { alert('Give the minutes as a number.'); return; }
      body.waived = Math.round(n);
    }
    if (verdict !== 'verified') {
      const why = prompt('Why? This is kept with the decision.', '');
      if (why === null) return;
      if (!String(why).trim()) { alert('A reason is needed.'); return; }
      body.reason = String(why).trim();
    }
    document.querySelectorAll('.pr-dr-btn').forEach(x => x.disabled = true);
    try {
      const res = await fetch('/hr/day-reviews/record', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify(body)
      });
      const j = await res.json();
      if (!j.success) throw new Error(j.message || 'Failed');
      if (j.warning) alert('Saved.\n\n⚠ ' + j.warning);
      // The month's figures can have moved (an adjustment or a waive), so reload the grid
      // behind the sheet, then redraw the drill from the server.
      await load();
      await loadDateList(DR_UID, DR_KIND === 'late' ? 'month_late' : 'month_overtime', el('prSheetTitle').textContent);
    } catch (e) {
      alert('Could not save that: ' + (e.message || e));
      renderDateList(DR_ROWS);
    }
  }

  async function loadDateList(uid, type, title) {
    openSheet(title, 'Loading…');
    // Only the overtime and late drills are reviewable; absent/leave drills are read-only.
    DR_KIND = type === 'month_overtime' ? 'overtime' : (type === 'month_late' ? 'late' : null);
    DR_UID = uid;
    try {
      const j = await getJson('/attendance/date-breakdown?user_id=' + uid + '&type=' + type + '&month=' + CURMONTH);
      if (!j.success) throw new Error(j.message || 'Failed');
      let dates = j.dates || [];
      if (DR_KIND) {
        // The verdicts come from the review service, keyed by date, and are merged onto the
        // rows the breakdown already returned — one list, not two.
        try {
          const rr = await fetch('/hr/day-reviews/month?user_id=' + uid + '&month=' + CURMONTH, { headers: { 'Accept': 'application/json' } });
          const rj = await rr.json();
          if (rj.success && rj.enabled) {
            const byDate = {};
            (rj.items || []).forEach(it => { if (it.kind === DR_KIND) byDate[it.date] = it; });
            dates = dates.map(d => ({ ...d, review: byDate[d.date] || null }));
          } else {
            DR_KIND = null;   // not installed on this server — the drill stays read-only
          }
        } catch (e) { DR_KIND = null; }
      }
      renderDateList(dates);
    } catch (e) {
      el('prSheetBody').innerHTML = '<div class="pr-empty">Could not load: ' + (e.message || e) + '</div>';
    }
  }
  document.addEventListener('click', async (ev) => {
    const t = ev.target.closest('[data-drill]');
    if (!t) return;
    SHEET_BACK = null;   // opened from the grid — nothing to go back to
    // A chip may carry its own title: the review chip's own text ("⚠ 0 of 1 checked · 1 to
    // go ›") makes a poor sheet heading.
    loadDateList(t.getAttribute('data-uid'), t.getAttribute('data-drill'),
                 t.getAttribute('data-drilltitle') || t.textContent.trim());
  });

  // ============================================================
  //  LEAVE ACTIONS PANEL
  // ============================================================
  // Every leave the month adds or takes away, in one list, with the derivation behind each
  // number and a decision per item. Money is NOT settled here — absent/late salary cuts and
  // advances still move only when the salary is paid.
  let LA_ITEMS = [];          // flattened rows currently rendered (index = button id)
  let LA_FILTER = null;       // when set, the panel is scoped to one employee

  function sheetWide(on) {
    const s = document.querySelector('#prSheet .pr-sheet');
    if (s) s.classList.toggle('wide', !!on);
  }

  function openLeavePanel(userId) {
    LA_FILTER = userId || null;
    SHEET_BACK = null;
    sheetWide(true);
    el('prSheetTitle').textContent = 'Leave actions — ' + monthLabel();
    el('prSheet').classList.add('show');
    renderLeavePanel();
  }

  function renderLeavePanel() {
    const data = LEAVE_ACT;
    if (!data || !(data.rows || []).length) {
      el('prSheetBody').innerHTML = '<div class="pr-empty">No overtime or lateness to settle for ' + esc(monthLabel()) + '.</div>';
      return;
    }
    const s = data.summary || {};
    const open = !data.closed;
    const rows = LA_FILTER ? data.rows.filter(r => r.user_id === LA_FILTER) : data.rows;

    // Header: what the month adds and takes away, then the money that is NOT settled here.
    const totals = [];
    if (s.give_pending) totals.push('<b style="color:#047857;">+' + s.give_pending + '</b> bonus to give');
    if (s.deduct_pending) totals.push('<b style="color:#b45309;">−' + s.deduct_pending + '</b> to deduct');
    if (s.given) totals.push('<b style="color:#047857;">+' + s.given + '</b> given');
    if (s.deducted) totals.push('<b style="color:#b45309;">−' + s.deducted + '</b> deducted');
    if (s.waived) totals.push(s.waived + ' waived');

    let note = '<div class="pr-la-note">' +
      (totals.length ? '<div style="font-size:12.5px;color:#374151;margin-bottom:5px;">' + totals.join(' · ') + '</div>' : '') +
      (open
        ? '⏳ <b>' + esc(monthLabel()) + ' is still running.</b> These are previews — overtime and lateness can still change, so they can be decided once the month ends. Paying a salary now works exactly as before.'
        : 'Leaves only. Absent deductions, late salary cuts and advances are money — they still move when you pay the salary.') +
      (s.late_cut_count
        ? '<div style="margin-top:6px;color:#b45309;">⚠ ' + s.late_cut_count + ' employee' + (s.late_cut_count > 1 ? 's have' : ' has') +
          ' a late <b>salary cut</b> of ' + fmt(s.late_cut_total) + ' this month (too late for a leave penalty) — that is deducted at pay, not here.</div>'
        : '') +
      '</div>';

    if (!open && s.pending_count > 0 && !LA_FILTER) {
      note += '<div style="padding:10px 14px;border-bottom:1px solid #eef0f2;">' +
        '<button type="button" class="pr-la-btn pr-la-give" id="prLaAll">Apply all ' + s.pending_count + ' recommended</button>' +
        '<span style="font-size:11px;color:#9ca3af;margin-left:8px;">gives the bonuses and takes the penalties</span></div>';
    }
    if (LA_FILTER) {
      note = '<div class="pr-la-back" id="prLaAllEmp">‹ all employees</div>' + note;
    }

    // ⭐⭐ ONE renderer (Sep-6 2026) — partials/leave-actions-panel. The row markup, the
    //   button labels and the decided-state pattern are no longer this page's business;
    //   the attendance page draws the identical rows from the same file, and the server
    //   supplies every word through `choices` / `decided_label`.
    LA_ITEMS = [];
    rows.forEach(r => (r.actions || []).forEach(a => {
      LA_ITEMS.push({ user_id: r.user_id, fullname: r.fullname, paid: r.paid, ...a });
    }));

    NFLeaveActions.render(el('prSheetBody'), LA_ITEMS, {
      header: note,
      monthOpen: open,
      monthLabel: monthLabel(),
      onDecide: (item, choice) => decideLeaveAction(item, choice),
      onDrill: (it) => {
        const keep = LA_FILTER;
        SHEET_BACK = () => { LA_FILTER = keep; sheetWide(true); el('prSheetTitle').textContent = 'Leave actions — ' + monthLabel(); renderLeavePanel(); };
        const what = it.kind === 'overtime' ? 'Overtime days — '
          : (it.kind === 'absence' ? 'Absent days — ' : 'Late days — ');
        loadDateList(it.user_id, it.drill, what + it.fullname);
      },
      // The header's own controls live outside the shared rows, so they are wired here.
      afterPaint: () => {
        const all = el('prLaAll');
        if (all) all.onclick = () => applyAllLeaveActions();
        const backAll = el('prLaAllEmp');
        if (backAll) backAll.onclick = () => { LA_FILTER = null; renderLeavePanel(); };
      }
    });
  }

  // One decision. The whole month is reloaded afterwards so the grid cells, the card and the
  // panel all move together — they are three views of the same server state.
  // ⚠⚠ Sep-6 2026 — deciding anything reloads the whole month (see below), and the reload
  //   reseeds every per-row UI override to its default (line ~773). A manager who had typed
  //   a late deduction or flipped a bypass toggle in the grid lost it with no message. These
  //   edits live only in the browser until Pay, so the warning is the only thing that can
  //   save them. Returns a human list of what is about to be discarded.
  function unsavedGridEdits() {
    const out = [];
    (ROWS || []).forEach(r => {
      const bits = [];
      if (r._lateOverride !== null && r._lateOverride !== undefined && r._lateOverride !== '') bits.push('a typed late deduction');
      if (r._netOverride !== null && r._netOverride !== undefined && r._netOverride !== '') bits.push('a typed net salary');
      if (r._skipOvertime) bits.push('overtime bypassed');
      if (r._skipLateLeave) bits.push('late leave kept');
      if (bits.length) out.push(r.fullname + ' — ' + bits.join(', '));
    });
    return out;
  }

  function confirmLosingGridEdits(whatFor) {
    const lost = unsavedGridEdits();
    if (!lost.length) return true;
    return confirm('You have changes on the grid that have not been paid yet:\n\n  • '
      + lost.join('\n  • ')
      + '\n\n' + whatFor + ' reloads the month and those go back to the calculated values.\n\nContinue?');
  }

  async function decideLeaveAction(item, choice) {
    if (!confirmLosingGridEdits('Saving this decision')) {
      // Nothing was sent, so put the buttons back the way they were.
      if (TAB === 'employee') { renderEmployee(); } else { renderLeavePanel(); }
      return;
    }
    document.querySelectorAll('.pr-la-btn, .nfla-btn').forEach(b => b.disabled = true);
    try {
      const res = await fetch('/hr/payroll/leave-actions/decide', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ user_id: item.user_id, month: CURMONTH, kind: item.kind, decision: choice })
      });
      const j = await res.json();
      if (!j.success) throw new Error(j.message || 'Failed');
      // ONE decide endpoint, refreshed into whichever view asked. The grid, the panel and
      // the Employee tab are three views of the same server state and must move together.
      if (TAB === 'employee') { await empLoad(); }
      else { await load(); renderLeavePanel(); }
    } catch (e) {
      alert('Could not save that: ' + (e.message || e));
      if (TAB === 'employee') { empLoad(); } else { renderLeavePanel(); }
    }
  }

  async function applyAllLeaveActions() {
    const s = (LEAVE_ACT && LEAVE_ACT.summary) || {};
    // ⚠ Absences are NOT in this count and cannot be "applied" — say so, because the red
    //   absence rows sit in the same list directly under this button.
    if (!confirm('Apply all ' + s.pending_count + ' recommended leave actions for ' + monthLabel() + '?'
      + '\n\nBonus leaves are given and late penalties are deducted. Anything you already decided is left alone.'
      + (Number(s.absence_pending || 0) > 0
          ? '\n\nThis does NOT touch the ' + s.absence_pending + ' absence decision'
            + (s.absence_pending > 1 ? 's' : '') + ' — each one has to be deducted, parked or excused itself.'
          : ''))) return;
    if (!confirmLosingGridEdits('Applying these')) return;
    const btn = el('prLaAll');
    if (btn) { btn.disabled = true; btn.textContent = 'Applying…'; }
    try {
      const res = await fetch('/hr/payroll/leave-actions/apply-all', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ month: CURMONTH })
      });
      const j = await res.json();
      if (!j.success) throw new Error(j.message || 'Failed');
      await load();
      renderLeavePanel();
      alert(j.message || 'Done.');
    } catch (e) {
      alert('Could not apply: ' + (e.message || e));
      renderLeavePanel();
    }
  }

  // mode 'custom' = the recovery rule differs: a custom period only recovers what
  // that period's pay can absorb, so the note must not promise full settlement.
  function showAdvances(r, mode) {
    ADV_SHEET = { row: r, mode: mode };
    openSheet('Advances — ' + r.fullname, '');
    renderAdvanceSheet();
  }

  // "Paid from" in ONE shape everywhere it appears (the grid row's sub-line and both frozen
  // receipts): an online salary names the BANK, because "Online" alone can't be reconciled
  // against a bank statement. Takes a paid_detail / paid_period object.
  function paidFromLabel(d) {
    if (!d || !d.funding) return '';
    return d.funding === 'online' ? '🏦 ' + esc(d.bank_label || 'Online') : '💵 NF Cash';
  }

  // Where the money actually came from. "Online Bank" is one chart account shared by every bank,
  // so for online advances the BANK is the useful fact — that's the balance a void moves back.
  // An online advance with no bank tag is legacy (pre per-bank tracking); say so rather than
  // implying it came from nowhere.
  function fundLabel(a) {
    if (!a.is_online) return a.source ? '💵 ' + esc(a.source) : '';
    return a.bank ? '🏦 ' + esc(a.bank) : '🏦 Online · bank not recorded';
  }

  // Each open advance shows WHERE the money came from, WHO gave it and the note, so two
  // same-amount advances can be told apart before acting. 🗑 Void appears only for the owner
  // (server flag CAN_VOID, re-checked server-side on every call).
  function renderAdvanceSheet() {
    const r = ADV_SHEET.row;
    if (!r) return;
    if (!r.advances || !r.advances.length) { el('prSheetBody').innerHTML = '<div class="pr-empty">No open advances.</div>'; return; }
    const note = ADV_SHEET.mode === 'custom'
      ? 'Open advances are recovered from the next period you pay — oldest first, and only as much as that pay can cover. An advance bigger than the pay stays open for a later period.'
      : 'Open advances are deducted from this pay and marked settled when you pay.';
    el('prSheetBody').innerHTML = r.advances.map((a, ai) => {
      const meta = [a.date || '', a.request_number ? esc(a.request_number) : '', fundLabel(a)]
        .filter(Boolean).join(' · ');
      const by = a.given_by ? '<div class="lb" style="color:#9ca3af;">given by ' + esc(a.given_by) + '</div>' : '';
      const nt = a.note ? '<div class="lb" style="color:#9ca3af;font-style:italic;">“' + esc(a.note) + '”</div>' : '';
      const voidBtn = (CAN_VOID && a.voidable)
        ? '<button type="button" data-voidadv="' + ai + '" title="Void this advance and return the money"' +
          ' style="margin-left:10px;font-size:11px;font-weight:700;color:#b91c1c;background:#fee2e2;border:1px solid #fecaca;border-radius:6px;padding:4px 9px;cursor:pointer;white-space:nowrap;">🗑 Void</button>'
        : '';
      // A remainder moved here from an earlier month says so, with the original figure —
      // "Rs 30,000" alone would read as a new advance nobody remembers giving.
      const carried = a.carried_from
        ? '<div class="lb" style="color:#b45309;">carried from ' + esc(monthNameOf(a.carried_from))
          + ' · ' + fmt(a.original_amount) + ' given, ' + fmt(a.settled_amount) + ' already deducted</div>'
        : '';
      return '<div class="pr-daterow" style="align-items:flex-start;">' +
        '<div><div class="dt">' + fmt(a.amount) + '</div><div class="lb">' + meta + '</div>' + carried + by + nt + '</div>' +
        '<div style="display:flex;align-items:center;">' + voidBtn + '</div></div>';
    }).join('')
      + '<div class="pr-daterow" style="font-weight:700;border-top:2px solid #eef0f2;"><span class="dt">Total open</span><span class="dt">' + fmt(r.advance_total) + '</span></div>'
      + '<div style="padding:12px 14px;font-size:11.5px;color:#9ca3af;">' + note + '</div>';

    (r.advances || []).forEach((a, ai) => {
      const b = document.querySelector('[data-voidadv="' + ai + '"]');
      if (b) b.onclick = () => voidAdvance(r, a);
    });
  }

  // ── Employee advance REQUESTS (asked, not given) ────────────────────────────
  // One review sheet, opened either from the amber top card (everyone) or from a row's
  // ⏳ chip (that employee only). Approving PAYS immediately — the funding account is
  // chosen in the same modal "+ advance" uses, so cash/online/bank behave identically.
  let REQ_LIST = [];

  async function openRequestSheet(userId) {
    openSheet('Advance requests', 'Loading…');
    try {
      const res = await fetch('/hr/payroll/pending-requests', { headers: { 'Accept': 'application/json' } });
      const j = await res.json();
      if (!j.success) throw new Error(j.message || 'Failed');
      REQ_LIST = j.requests || [];
      if (j.funding) FUND = j.funding;
      renderRequestSheet(userId ? Number(userId) : null);
    } catch (e) {
      el('prSheetBody').innerHTML = '<div class="pr-empty">Could not load requests: ' + (e.message || e) + '</div>';
    }
  }

  function renderRequestSheet(userId) {
    const list = userId ? REQ_LIST.filter(x => Number(x.user_id) === userId) : REQ_LIST;
    el('prSheetTitle').textContent = userId && list.length
      ? 'Advance requests — ' + list[0].fullname
      : 'Advance requests';
    if (!list.length) {
      el('prSheetBody').innerHTML = '<div class="pr-empty">No requests waiting.</div>';
      return;
    }
    const total = list.reduce((s, x) => s + Number(x.amount || 0), 0);
    let html = '<div style="padding:10px 14px;background:#fffbeb;border-bottom:1px solid #fde68a;font-size:11.5px;color:#92400e;">' +
      '<b>Nothing here has been paid yet.</b> Approving pays the money immediately from the account you pick, ' +
      'and it is then deducted from that person\'s next salary.</div>';
    html += list.map((q) => {
      const who = q.self_requested
        ? '<span style="color:#2563eb;">asked by ' + esc(q.fullname) + '</span>'
        : 'entered by ' + esc(q.raised_by || 'a manager');
      const age = q.age_days > 60
        ? '<span style="color:#b91c1c;font-weight:700;"> · ' + q.age_days + ' days old</span>'
        : (q.age_days > 0 ? '<span style="color:#9ca3af;"> · ' + q.age_days + 'd ago</span>' : '');
      // Some requests can no longer be paid: the employee has left, or they are now on a
      // running balance (where money is recorded as a payment, not an advance). The server
      // refuses both, so offer Reject alone and say why rather than showing a button that fails.
      const blockedWhy = (q.employee_active === false)
        ? 'This employee is no longer active — it can only be rejected.'
        : (q.balance_tracked
            ? 'On a running balance — record a payment on their card instead, then reject this.'
            : null);
      const actions = blockedWhy
        ? '<div style="display:flex;gap:6px;flex-shrink:0;">' +
            '<button type="button" data-reqno="' + q.request_id + '" style="font-size:11px;font-weight:700;color:#b91c1c;background:#fee2e2;border:1px solid #fecaca;border-radius:6px;padding:5px 10px;cursor:pointer;white-space:nowrap;">✕ Reject</button>' +
          '</div>'
        : '<div style="display:flex;gap:6px;flex-shrink:0;">' +
            '<button type="button" data-reqok="' + q.request_id + '" style="font-size:11px;font-weight:700;color:#166534;background:#dcfce7;border:1px solid #bbf7d0;border-radius:6px;padding:5px 10px;cursor:pointer;white-space:nowrap;">✓ Approve &amp; pay</button>' +
            '<button type="button" data-reqno="' + q.request_id + '" style="font-size:11px;font-weight:700;color:#b91c1c;background:#fee2e2;border:1px solid #fecaca;border-radius:6px;padding:5px 10px;cursor:pointer;white-space:nowrap;">✕ Reject</button>' +
          '</div>';
      return '<div class="pr-daterow" style="align-items:flex-start;">' +
        '<div style="min-width:0;">' +
          '<div class="dt">' + fmt(q.amount) + ' <span style="font-weight:600;color:#374151;font-size:12px;">· ' + esc(q.fullname) + '</span></div>' +
          '<div class="lb">' + (q.date || '') + ' · ' + esc(q.request_number) + age + '</div>' +
          '<div class="lb" style="color:#9ca3af;">' + who + '</div>' +
          (q.note ? '<div class="lb" style="color:#9ca3af;font-style:italic;">“' + esc(q.note) + '”</div>' : '') +
          (blockedWhy ? '<div class="lb" style="color:#b45309;">⚠ ' + blockedWhy + '</div>' : '') +
        '</div>' + actions + '</div>';
    }).join('');
    html += '<div class="pr-daterow" style="font-weight:700;border-top:2px solid #eef0f2;"><span class="dt">Total requested</span><span class="dt">' + fmt(total) + '</span></div>';
    el('prSheetBody').innerHTML = html;

    list.forEach((q) => {
      const okBtn = document.querySelector('[data-reqok="' + q.request_id + '"]');
      if (okBtn) okBtn.onclick = () => openApproveRequest(q);
      const noBtn = document.querySelector('[data-reqno="' + q.request_id + '"]');
      if (noBtn) noBtn.onclick = () => rejectRequest(q, userId);
    });
  }

  // Reuse the give-advance modal in APPROVE mode: amount is fixed by the request, the
  // manager only picks where the money comes from.
  function openApproveRequest(q) {
    ADV_MODE = 'approve';
    ADV_REQ = q;
    ADV_ROW = null;
    el('prAdvModalTitle').textContent = 'Approve & pay advance';
    el('prAdvWho').innerHTML = 'To <b>' + esc(q.fullname) + '</b> · ' + esc(q.request_number) +
      '<div style="font-size:11.5px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:6px 8px;margin-top:6px;">' +
      'This pays <b>' + fmt(q.amount) + '</b> now and deducts it from the next salary.</div>';
    el('prAdvAmount').value = q.amount;
    el('prAdvAmount').readOnly = true;
    el('prAdvAmount').style.background = '#f3f4f6';
    el('prAdvNote').style.display = 'none';
    el('prAdvConfirm').textContent = 'Approve & pay';
    resetAdvFunding();
    el('prAdvModal').classList.add('show');
  }

  async function rejectRequest(q, filterUid) {
    const reason = prompt('Reject this request?\n\n' + fmt(q.amount) + ' for ' + q.fullname +
      (q.date ? ' (asked ' + q.date + ')' : '') +
      '\nNo money has been paid, so nothing is reversed.\n\nType the reason (required):', '');
    if (reason === null) return;
    if (!reason.trim() || reason.trim().length < 3) { alert('Please type a reason.'); return; }
    try {
      const res = await fetch('/hr/payroll/reject-request', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ request_id: q.request_id, reason: reason.trim() })
      });
      const j = await res.json();
      alert(j.message || (j.success ? 'Rejected.' : 'Could not reject.'));
      if (j.success) {
        REQ_LIST = REQ_LIST.filter(x => x.request_id !== q.request_id);
        renderRequestSheet(filterUid || null);
        reloadActive();
      }
    } catch (e) { alert('Could not reject the request.'); }
  }

  // Void = un-do a wrongly-given advance: the money goes back to the account it came from
  // (same engine that posted it) and the advance disappears from payroll everywhere.
  async function voidAdvance(r, a) {
    // Name the BANK, not the shared 'Online Bank' chart account — that's the balance the
    // reviewer will see move (plain text here, not HTML: no esc()).
    const back = (a.is_online ? (a.bank || 'Online Bank') : a.source) || 'the funding account';
    const reason = prompt('Void this advance?\n\n' + fmt(a.amount) + ' given to ' + r.fullname +
      (a.date ? ' on ' + a.date : '') + (a.request_number ? ' (' + a.request_number + ')' : '') +
      '\n' + fmt(a.amount) + ' goes back to ' + back + '.' +
      '\n\nType the reason (required):', '');
    if (reason === null) return;
    if (!reason.trim() || reason.trim().length < 3) { alert('Please type why this advance is being voided.'); return; }
    try {
      const res = await fetch('/hr/payroll/void-advance', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ request_id: a.request_id, reason: reason.trim() })
      });
      const j = await res.json();
      alert(j.message || (j.success ? 'Advance voided.' : 'Could not void this advance.'));
      if (j.success) {
        // Drop it locally so the open sheet is instantly correct, then reload the grid
        // (net pay + deductions all move with it).
        r.advances = (r.advances || []).filter(x => x.request_id !== a.request_id);
        r.advance_total = Math.round((r.advances.reduce((s, x) => s + Number(x.amount || 0), 0)) * 100) / 100;
        renderAdvanceSheet();
        if (ADV_SHEET.mode === 'custom') { customLoad(); } else { load(); }
      }
    } catch (e) {
      alert('Could not void this advance.');
    }
  }

  // ---- deductions breakdown ----
  function showDeductions(r) {
    const absent = Number(r.absent_deduction || 0);
    const late = lateDed(r);
    const adv = Number(r.advance_total || 0);
    const total = absent + late + adv;
    const dedLine = (label, amount, formula, muted) =>
      '<div class="pr-daterow"><div><div class="dt"' + (muted ? ' style="color:#9ca3af;"' : '') + '>' + label + '</div>' +
      (formula ? '<div class="lb">' + esc(formula) + '</div>' : '') + '</div>' +
      '<span class="dt"' + (muted ? ' style="color:#cbd5e1;"' : '') + '>' + (amount > 0 ? '− ' + fmt(amount) : '—') + '</span></div>';

    let html = '';
    html += dedLine('Absent days', absent,
      r.absent_days > 0 ? (r.absent_days + ' × ' + fmt(r.per_day) + '/day') : 'no unapproved absences', absent === 0);
    html += dedLine('Late', late,
      late > 0 ? ((r.late_minutes / 60).toFixed(1) + 'h × ' + fmt(r.per_hour) + '/hr')
        : (r.late_leave_deduct > 0 ? 'covered by 1 leave (no pay cut)' : 'within free buffer'), late === 0);
    html += dedLine('Advances', adv,
      adv > 0 ? 'open advance — settled when you pay' : 'none open', adv === 0);
    html += '<div class="pr-daterow" style="font-weight:700;border-top:2px solid #eef0f2;margin-top:4px;"><span class="dt">Total deductions</span><span class="dt">' + fmt(total) + '</span></div>';
    html += '<div style="padding:12px 14px;font-size:11.5px;color:#9ca3af;">Net pay = base ' + fmt(r.base_salary) + ' − deductions ' + fmt(total) + ' = <b>' + fmt(Math.max(0, net(r))) + '</b></div>';

    sheetWide(false);
    el('prSheetTitle').textContent = 'Deductions — ' + r.fullname;
    el('prSheetBody').innerHTML = html;
    el('prSheet').classList.add('show');
  }

  // ---- paid detail (the frozen receipt of what was actually paid) ----
  function showPaidDetail(r) {
    const d = r.paid_detail;
    if (!d) { openSheet('Payment — ' + r.fullname, 'No payment record found.'); return; }
    const line = (label, value, neg) =>
      '<div class="pr-daterow"><span class="dt" style="font-weight:400;color:#6b7280;">' + label + '</span>' +
      '<span class="dt"' + (neg ? ' style="color:#b91c1c;"' : '') + '>' + value + '</span></div>';

    let html = '';
    html += line('Paid on', d.paid_at ? String(d.paid_at).slice(0, 16) : '—');
    if (d.paid_by_name) html += line('Paid by', esc(d.paid_by_name));
    html += line('Paid from', paidFromLabel(d));
    html += '<div style="height:8px;"></div>';
    html += line('Base salary', fmt(d.base));
    html += line('Attendance', d.present_days + ' present · ' + d.absent_days + ' absent' + (d.leave_days > 0 ? ' · ' + d.leave_days + ' leave' : ''));
    if (d.absent_deduction > 0) html += line('Absent deduction', '− ' + fmt(d.absent_deduction), true);
    if (d.late_minutes > 0) {
      const lt = Math.floor(d.late_minutes / 60) + 'h ' + (d.late_minutes % 60) + 'm';
      if (d.late_deduction > 0) html += line('Late (' + lt + ')', '− ' + fmt(d.late_deduction), true);
      else if (d.late_leave_deduct > 0) html += line('Late (' + lt + ')', '−' + d.late_leave_deduct + ' leave');
      else html += line('Late (' + lt + ')', 'free buffer');
    }
    // Oct-2026 — the late figure above is NET of a manager's waive; the receipt says so.
    if (d.late_waived_minutes > 0) {
      const raw = (d.late_minutes || 0) + d.late_waived_minutes;
      html += line('Late waived', hm(d.late_waived_minutes) + ' of ' + hm(raw));
    }
    if (d.advance_total > 0) html += line('Advances settled', '− ' + fmt(d.advance_total), true);
    if (d.bonus_leaves > 0) html += line('Overtime bonus', '+' + d.bonus_leaves + ' leave' + (d.bonus_leaves > 1 ? 's' : ''));
    if (d.notes) html += line('Note', esc(d.notes));
    html += '<div class="pr-daterow" style="font-weight:700;border-top:2px solid #eef0f2;margin-top:4px;"><span class="dt">Net paid</span><span class="dt" style="color:#047857;">' + fmt(d.net) + '</span></div>';
    if (d.ledger_id) html += '<div style="padding:12px 14px;font-size:11.5px;color:#9ca3af;">Ledger entry #' + d.ledger_id + '</div>';

    sheetWide(false);
    el('prSheetTitle').textContent = 'Payment — ' + r.fullname;
    el('prSheetBody').innerHTML = html;
    el('prSheet').classList.add('show');
  }

  function openSheet(title, body) {
    // A drill opened FROM the leave panel keeps the panel's width, so going back doesn't jump.
    sheetWide(!!SHEET_BACK);
    el('prSheetTitle').textContent = title;
    el('prSheetBody').innerHTML = body ? '<div class="pr-empty">' + body + '</div>' : '';
    el('prSheet').classList.add('show');
  }
  el('prSheetX').onclick = () => el('prSheet').classList.remove('show');
  el('prSheet').onclick = (ev) => { if (ev.target === el('prSheet')) el('prSheet').classList.remove('show'); };

  // ---- pay modal ----
  function buildFundModal() {
    const cashLbl = FUND.cash ? ('NF Cash (' + FUND.cash.name + ')') : 'NF Cash';
    const bankOpts = '<option value="">Select bank…</option>' +
      (FUND.banks || []).map(b => '<option value="' + b.id + '">' + esc(b.label) + '</option>').join('');
    el('prCashLabel').textContent = cashLbl;
    el('prBankSel').innerHTML = bankOpts;
    el('prAdvCashLabel').textContent = cashLbl;
    el('prAdvBankSel').innerHTML = bankOpts;
    if (el('prCustCashLabel')) el('prCustCashLabel').textContent = cashLbl;
    if (el('prCustBankSel')) el('prCustBankSel').innerHTML = bankOpts;
    if (el('prBalCashLabel')) el('prBalCashLabel').textContent = cashLbl;
    if (el('prBalBankSel')) el('prBalBankSel').innerHTML = bankOpts;
  }

  // ---- give advance ----
  let ADV_ROW = null;
  let ADV_MODE = 'give';   // 'give' = new advance, 'approve' = pay an employee's request
  let ADV_REQ = null;      // the request being approved (approve mode only)
  // Funding picker reset — shared by "give" and "approve & pay" so both post money the
  // same way (NF Cash, or the single ONLINE account tagged with the chosen bank).
  function resetAdvFunding() {
    document.querySelectorAll('[data-advfund]').forEach(x => x.classList.remove('active'));
    document.querySelector('[data-advfund="cash"]').classList.add('active');
    document.querySelector('input[name=prAdvFund][value=cash]').checked = true;
    el('prAdvBankSel').disabled = true;
    el('prAdvConfirm').disabled = false;
  }

  // The month an advance is being given FOR = the month the grid is showing. Everything
  // else (which pay recovers it, which month is charged, where Expenses lists it) follows
  // from this one value, so it is always sent explicitly rather than assumed to be today.
  function advMonth() { return CURMONTH || el('prMonth').value; }
  function thisMonthStr() {
    const n = new Date();
    return n.getFullYear() + '-' + String(n.getMonth() + 1).padStart(2, '0');
  }
  function monthNameOf(ym) {
    const [y, m] = ym.split('-').map(Number);
    return new Date(y, m - 1, 1).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
  }
  // Show the month/date controls only when the viewed month is NOT the current one:
  //  · past month  → ask which day the money really left (constrained to that month)
  //  · next month  → given forward; money moves today, so no date is asked
  function syncAdvMonthUi() {
    const m = advMonth(), cur = thisMonthStr();
    const box = el('prAdvMonthBox'), dateWrap = el('prAdvDateWrap');
    if (!m || m === cur) { box.style.display = 'none'; dateWrap.style.display = 'none'; return; }
    box.style.display = '';
    if (m < cur) {
      const last = new Date(Number(m.split('-')[0]), Number(m.split('-')[1]), 0);
      const lastStr = m + '-' + String(last.getDate()).padStart(2, '0');
      const today = new Date();
      const todayStr = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0')
        + '-' + String(today.getDate()).padStart(2, '0');
      el('prAdvMonthNote').textContent = 'This advance is for ' + monthNameOf(m)
        + ' — it will be deducted from ' + monthNameOf(m) + ' pay.';
      dateWrap.style.display = '';
      el('prAdvDate').min = m + '-01';
      el('prAdvDate').max = (lastStr > todayStr ? todayStr : lastStr);
      el('prAdvDate').value = (lastStr > todayStr ? todayStr : lastStr);
      el('prAdvDateHint').textContent = 'Must be a date in ' + monthNameOf(m)
        + '. The ledger uses this so it matches the bank statement.';
    } else {
      // Future month — the server allows the NEXT month only and re-checks it.
      el('prAdvMonthNote').textContent = 'This advance is for ' + monthNameOf(m)
        + ' — the money goes out today and is deducted from ' + monthNameOf(m) + ' pay.';
      dateWrap.style.display = 'none';
    }
  }

  function openAdvance(r) {
    ADV_MODE = 'give';
    ADV_REQ = null;
    ADV_ROW = r;
    el('prAdvModalTitle').textContent = 'Give advance';
    // ⭐ The cap, said up front: the server refuses more than what is left of the month's
    // salary, so the modal names that figure before the manager types one.
    const room = Number(r.advance_room);
    el('prAdvWho').innerHTML = 'To <b>' + esc(r.fullname) + '</b>'
      + (r.advance_total > 0 ? ' · open advances ' + fmt(r.advance_total) : '')
      + (Number.isFinite(room)
          ? '<div style="font-size:11.5px;color:' + (room > 0 ? '#047857' : '#b91c1c') + ';margin-top:3px;">'
            + (room > 0 ? 'Up to ' + fmt(room) + ' for ' + monthNameOf(CURMONTH) + ' — anything more goes against a later month.'
                        : monthNameOf(CURMONTH) + '’s salary is already fully advanced — record this against a later month.')
            + '</div>'
          : '');
    el('prAdvAmount').value = '';
    el('prAdvAmount').readOnly = false;
    el('prAdvAmount').style.background = '';
    el('prAdvNote').value = '';
    el('prAdvNote').style.display = '';
    el('prAdvConfirm').textContent = 'Give advance';
    resetAdvFunding();
    syncAdvMonthUi();
    el('prAdvModal').classList.add('show');
    setTimeout(() => el('prAdvAmount').focus(), 50);
  }
  document.querySelectorAll('[data-advfund]').forEach(f => {
    f.onclick = (ev) => {
      if (ev.target.closest('select')) return; // same select-click guard as the pay modal
      document.querySelectorAll('[data-advfund]').forEach(x => x.classList.remove('active'));
      f.classList.add('active');
      f.querySelector('input[type=radio]').checked = true;
      const dis = (f.getAttribute('data-advfund') !== 'online');
      if (el('prAdvBankSel').disabled !== dis) el('prAdvBankSel').disabled = dis;
    };
  });
  el('prAdvCancel').onclick = () => el('prAdvModal').classList.remove('show');
  el('prAdvModal').onclick = (ev) => { if (ev.target === el('prAdvModal')) el('prAdvModal').classList.remove('show'); };
  el('prAdvConfirm').onclick = async () => {
    const isApprove = ADV_MODE === 'approve';
    if (!isApprove && !ADV_ROW) return;
    if (isApprove && !ADV_REQ) return;
    const amount = Number(el('prAdvAmount').value);
    if (!amount || amount < 1) { alert('Enter an amount.'); return; }
    const fundType = document.querySelector('input[name=prAdvFund]:checked').value;
    const bankId = el('prAdvBankSel').value;
    if (fundType === 'online' && !bankId) { alert('Choose the bank.'); return; }
    el('prAdvConfirm').disabled = true;
    el('prAdvConfirm').textContent = isApprove ? 'Paying…' : 'Giving…';
    try {
      const url = isApprove ? '/hr/payroll/approve-request' : '/hr/payroll/give-advance';
      const body = isApprove
        ? { request_id: ADV_REQ.request_id, funding: fundType, bank_id: fundType === 'online' ? Number(bankId) : null }
        : {
            user_id: ADV_ROW.user_id, amount, funding: fundType,
            bank_id: fundType === 'online' ? Number(bankId) : null,
            note: el('prAdvNote').value || null,
            // The month the grid is showing decides which pay recovers this and which
            // month is charged; money_date only differs when that month is in the past.
            payroll_month: advMonth(),
            money_date: (el('prAdvDateWrap').style.display !== 'none' && el('prAdvDate').value)
              ? el('prAdvDate').value : null
          };
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify(body)
      });
      const j = await res.json();
      if (!j.success) throw new Error(j.message || 'Failed');
      el('prAdvModal').classList.remove('show');
      if (isApprove) {
        REQ_LIST = REQ_LIST.filter(x => x.request_id !== ADV_REQ.request_id);
        el('prSheet').classList.remove('show');
        alert(j.message || 'Approved.');
      }
      await reloadActive();
    } catch (e) {
      alert('Could not give advance: ' + (e.message || e));
    } finally {
      el('prAdvConfirm').disabled = false; el('prAdvConfirm').textContent = 'Give advance';
    }
  };
  document.querySelectorAll('[data-fund]').forEach(f => {
    f.onclick = (ev) => {
      // Clicks on the bank <select> must not re-run the fund toggle — re-setting
      // `disabled` mid-click cancels the native dropdown from ever opening.
      if (ev.target.closest('select')) return;
      document.querySelectorAll('[data-fund]').forEach(x => x.classList.remove('active'));
      f.classList.add('active');
      f.querySelector('input[type=radio]').checked = true;
      const dis = (f.getAttribute('data-fund') !== 'online');
      if (el('prBankSel').disabled !== dis) el('prBankSel').disabled = dis;
    };
  });
  el('prPayBtn').onclick = () => {
    const sel = selectedRows();
    if (!sel.length) return;
    el('prPayList').innerHTML = sel.map(r =>
      '<div class="r"><span>' + esc(r.fullname) + (hasNetOverride(r) ? ' <span style="color:#7c3aed;font-size:11px;">manual</span>' : '') + '</span><span>' + fmt(effNet(r)) + '</span></div>'
    ).join('') + '<div class="r" style="font-weight:700;background:#fafbfc;"><span>Total</span><span>' +
      fmt(sel.reduce((s, r) => s + effNet(r), 0)) + '</span></div>';

    // Rows whose deductions exceed the salary. `net_raw` is the un-clamped figure the row
    // already carries, so this costs nothing to detect.
    const short = sel.filter(r => Number(r.net_raw || 0) < 0 && !hasNetOverride(r));
    if (short.length) {
      const gapOf = (r) => Math.abs(Number(r.net_raw || 0));
      // What can MOVE to next month: the part of the gap that is advance. A gap bigger than
      // the open advance (a held charge larger than the salary) has nothing to move and is
      // written off either way — the copy says so rather than hiding it.
      const movable = (r) => Math.min(gapOf(r), Number(r.advance_total || 0));
      const totalGap = short.reduce((s, r) => s + gapOf(r), 0);
      const carryable = short.reduce((s, r) => s + movable(r), 0);
      const stuck = totalGap - carryable;   // written off under BOTH options
      // The server says whether carrying is possible yet (needs the carry SQL on prod).
      const carryOk = carryable > 0 && short.every(r => r.carry_available);
      const nextM = (() => { const [y, m] = CURMONTH.split('-').map(Number); return new Date(y, m, 1); })();
      const nextLabel = nextM.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
      const who = short.length === 1 ? esc(short[0].fullname) : short.length + ' employees';
      const they = short.length === 1 ? 'He takes home' : 'They take home';

      el('prShortHead').textContent = short.length === 1
        ? short[0].fullname + ' owes ' + fmt(totalGap) + ' more than this month’s salary covers'
        : who + ' owe ' + fmt(totalGap) + ' more than this month’s salary covers';
      el('prShortList').innerHTML = short.map(r =>
        '<div>' + esc(r.fullname) + ' — short by <b>' + fmt(gapOf(r)) + '</b></div>').join('');

      // Each option's outcomes, in the order the manager cares about them: what he takes
      // home (the same Rs 0 under both), then where the money goes.
      const optCarry = document.querySelector('input[name=prShortMode][value=carry]');
      el('prShortOptCarryLbl').textContent = carryOk
        ? 'Move ' + fmt(carryable) + ' to ' + nextLabel
        : 'Move the rest to next month';
      if (carryOk) {
        el('prShortOutCarry').textContent = they + ' ' + fmt(0) + ' · deducted from ' + nextLabel + '’s pay'
          + (stuck > 0 ? ' · ' + fmt(stuck) + ' still written off' : '');
        el('prShortOutCarry').style.color = '#047857';
        el('prShortOptCarry').style.opacity = '';
        if (optCarry) optCarry.disabled = false;
      } else {
        el('prShortOutCarry').textContent = carryable > 0
          ? 'Not available until the database update is applied.'
          : 'Nothing to move — the shortfall is not an advance.';
        el('prShortOutCarry').style.color = '#9ca3af';
        el('prShortOptCarry').style.opacity = '0.55';
        if (optCarry) optCarry.disabled = true;
      }
      el('prShortOutA').textContent = they + ' ' + fmt(0) + ' · ' + fmt(totalGap) + ' never recovered';
      el('prShortFoot').innerHTML = 'The advance stays open until salaries have taken all of it. '
        + 'Your choice is recorded on the payment note.'
        + (short.some(r => Number(r.late_leave_deduct || 0) > 0)
            ? ' The −1 leave for lateness is separate — it is decided below.' : '');
      const dflt = document.querySelector('input[name=prShortMode][value=' + (carryOk ? 'carry' : 'writeoff') + ']');
      if (dflt) dflt.checked = true;
      el('prShortBox').style.display = '';
    } else {
      el('prShortBox').style.display = 'none';
    }

    // Only the STILL-UNDECIDED leaves are in play here; anything already settled (from the
    // panel or an earlier payment) is untouched by paying and isn't mentioned.
    let give = 0, cut = 0;
    const undecided = (r, kind) => { const a = laFor(r, kind); return !a || a.status === 'pending'; };
    sel.forEach(r => {
      if (undecided(r, 'overtime') && !r._skipOvertime) give += Number(r.bonus_leaves || 0);
      if (undecided(r, 'late_penalty') && !r._skipLateLeave) cut += Number(r.late_leave_deduct || 0);
    });
    // ⚠ Paying is the LAST moment an absence can still be parked or excused — afterwards the
    // month is settled and the service refuses to re-decide it. Name the people, not a count.
    const absUndecided = sel.filter(r => Number(r.absent_days || 0) > 0 && !r.absence_decision);
    if (absUndecided.length) {
      const money = absUndecided.reduce((s, r) => s + Number(r.absent_deduction || 0), 0);
      el('prPayAbsWarn').innerHTML = '🗓 <b>' + absUndecided.length + ' of these have absences nobody has decided</b> — '
        + esc(absUndecided.map(r => r.fullname + ' (' + r.absent_days + ')').join(', '))
        + '. Paying deducts ' + fmt(money) + ' and the month can no longer be parked or excused.';
      el('prPayAbsWarn').style.display = '';
    } else {
      el('prPayAbsWarn').style.display = 'none';
    }

    const parts = [];
    if (give) parts.push('+' + give + ' bonus leave' + (give > 1 ? 's' : ''));
    if (cut) parts.push('−' + cut + ' leave' + (cut > 1 ? 's' : '') + ' for lateness');
    if (parts.length) {
      el('prPayLeaveSum').textContent = 'Leave actions for these employees: ' + parts.join(', ');
      el('prPayLeaveBox').style.display = '';
      const apply = document.querySelector('input[name=prLeaveMode][value=apply]');
      if (apply) apply.checked = true;
    } else {
      el('prPayLeaveBox').style.display = 'none';
    }
    el('prPayModal').classList.add('show');
  };
  el('prPayCancel').onclick = () => el('prPayModal').classList.remove('show');
  el('prPayModal').onclick = (ev) => { if (ev.target === el('prPayModal')) el('prPayModal').classList.remove('show'); };

  el('prPayConfirm').onclick = async () => {
    // Deductions-exceed-salary answer. ⚠ The fallback is the SERVER's own default, not a
    // third name: this line used to send 'carry', a mode that was designed and never built,
    // and the server rejected it outright.
    const shortEl = document.querySelector('input[name=prShortMode]:checked');
    const shortMode = shortEl ? shortEl.value : 'writeoff';
    const sel = selectedRows();
    if (!sel.length) return;
    const fundType = document.querySelector('input[name=prFund]:checked').value;
    const bankId = el('prBankSel').value;
    if (fundType === 'online' && !bankId) { alert('Please choose the bank you are paying from.'); return; }
    const leaveModeEl = document.querySelector('input[name=prLeaveMode]:checked');
    const deferLeave = !!leaveModeEl && leaveModeEl.value === 'defer'
      && el('prPayLeaveBox').style.display !== 'none';
    const payload = {
      month: CURMONTH,
      funding: fundType,
      bank_id: fundType === 'online' ? Number(bankId) : null,
      items: sel.map(r => ({ user_id: r.user_id, net: effNet(r), late_deduction: lateDed(r), net_override: hasNetOverride(r) ? Math.max(0, Number(r._netOverride)) : null, skip_overtime: !!r._skipOvertime, skip_late_leave: !!r._skipLateLeave, defer_leave_actions: deferLeave, shortfall: shortMode }))
    };
    el('prPayConfirm').disabled = true; el('prPayConfirm').textContent = 'Paying…';
    try {
      const res = await fetch('/hr/payroll/pay', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify(payload)
      });
      const j = await res.json();
      if (!j.success) throw new Error(j.message || 'Failed');
      el('prPayModal').classList.remove('show');
      alert(j.message || 'Salaries paid.');
      await load();
    } catch (e) {
      alert('Payment failed: ' + (e.message || e));
    } finally {
      el('prPayConfirm').disabled = false; el('prPayConfirm').textContent = 'Confirm payment';
    }
  };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

  // ============================================================
  //  TABS + CUSTOM-SCHEDULE VIEW
  // ============================================================
  function reloadActive() {
    if (TAB === 'custom') return customLoad();
    if (TAB === 'employee') return empLoad();
    return load();
  }
  function switchTab(tab) {
    TAB = tab;
    ['Employee', 'Monthly', 'Custom'].forEach(k => {
      const t = el('prTab' + k), v = el('pr' + k + 'View');
      if (t) t.classList.toggle('active', tab === k.toLowerCase());
      if (v) v.classList.toggle('active', tab === k.toLowerCase());
    });
    // The Employee tab is the privacy view, so nothing that names ANOTHER employee may
    // survive the switch: the pay bar is a grid concept, and the advance banner sitting
    // above the tabs names whoever is waiting for money.
    if (tab !== 'monthly') el('prPaybar').classList.remove('show');
    const banner = el('prAlertBanner');
    if (banner) banner.style.display = tab === 'employee' ? 'none' : '';
    if (tab === 'custom') { customLoad(); }
    else if (tab === 'employee') { empLoad(); }
    else { load(); }
  }
  el('prTabEmployee').onclick = () => switchTab('employee');
  el('prTabMonthly').onclick = () => switchTab('monthly');
  el('prTabCustom').onclick = () => switchTab('custom');

  // ============================================================
  //  EMPLOYEE TAB — one person, nothing about anyone else
  // ============================================================
  let EMP_ID = null;      // who is on screen
  let EMP = null;         // the detail payload
  // Which settled leave actions have had their buttons re-opened by "change ›" on this tab.
  // Cleared on every load, so a refresh always returns to the read-only chip.
  let EMP_CHANGE = {};
  let EMP_LIST = [];      // names only

  function empName(id) { const e = EMP_LIST.find(x => Number(x.user_id) === Number(id)); return e ? e.name : ''; }

  function renderEmpPicker() {
    const q = (el('prEmpSearch').value || '').trim().toLowerCase();
    const shown = EMP_LIST.filter(e => !q || e.name.toLowerCase().includes(q));
    el('prEmpSelect').innerHTML = '<option value="">— choose —</option>' + shown.map(e =>
      '<option value="' + e.user_id + '"' + (Number(e.user_id) === Number(EMP_ID) ? ' selected' : '') + '>'
      + esc(e.name) + (e.schedule === 'custom' ? ' (custom)' : '') + '</option>').join('');
    el('prEmpHint').textContent = shown.length + ' of ' + EMP_LIST.length + ' shown';
  }

  async function empLoad(userId) {
    if (userId !== undefined) EMP_ID = userId;
    EMP_CHANGE = {};
    const month = el('prMonth').value;
    const seq = ++LOAD_SEQ;
    if (!EMP_ID) {
      el('prEmpBody').style.display = 'none';
      el('prEmpEmpty').style.display = '';
    }
    try {
      const url = '/hr/payroll/employee?month=' + encodeURIComponent(month)
                + (EMP_ID ? '&user_id=' + encodeURIComponent(EMP_ID) : '');
      const j = await getJson(url);
      if (seq !== LOAD_SEQ || month !== el('prMonth').value) return;   // stale month — drop it
      if (!j.success) throw new Error(j.message || 'Failed');
      CURMONTH = j.month;
      EMP_LIST = j.employees || [];
      if (j.funding) FUND = j.funding;
      if (j.ot_per_day) OT_PER_DAY = Number(j.ot_per_day) || OT_PER_DAY;
      if (j.can_void_advance !== undefined) CAN_VOID = !!j.can_void_advance;
      // The custom card reads these globals; landing on this tab must not leave them at
      // their boot defaults (which would hide the khata controls and mis-date the calendar).
      if (j.balance_available !== undefined) BAL_AVAILABLE = !!j.balance_available;
      if (j.can_void_payment !== undefined) CAN_VOID_PAY = !!j.can_void_payment;
      if (j.today) TODAY = j.today;
      if (j.khaas_available !== undefined) KHAAS_AVAILABLE = !!j.khaas_available;
      if (j.khaas_bu_id !== undefined) KHAAS_BU_ID = j.khaas_bu_id || null;
      if (j.schedule_available !== undefined) SCHEDULE_AVAILABLE = !!j.schedule_available;
      renderEmpPicker();
      if (!EMP_ID) { EMP = null; return; }
      // Seed the same per-row UI state the grid seeds. Without it `_lateOverride` is
      // undefined, `Number(undefined)` is NaN, and the net silently renders as Rs 0 —
      // these helpers are shared with the grid and expect the fields to exist.
      EMP = j;
      if (EMP.row) {
        EMP.row = { ...EMP.row, _selected: false, _lateOverride: null, _netOverride: null,
                    _skipOvertime: false, _skipLateLeave: false };
      }
      renderEmployee();
    } catch (e) {
      el('prEmpBody').style.display = '';
      el('prEmpEmpty').style.display = 'none';
      el('prEmpBody').innerHTML = '<div class="pr-empty">Could not load: ' + esc(e.message || e) + '</div>';
    }
  }

  el('prEmpSearch').oninput = renderEmpPicker;
  el('prEmpSelect').onchange = (ev) => { empLoad(ev.target.value ? Number(ev.target.value) : null); };

  // One money line, optionally opening the days behind it.
  function empLine(label, value, opts) {
    opts = opts || {};
    const cls = 'pr-emp-line' + (opts.minus ? ' minus' : '') + (opts.total ? ' tot' : '');
    const lbl = opts.drill
      ? '<span class="pr-emp-drill" data-drill="' + opts.drill + '" data-uid="' + EMP_ID + '">' + label + ' ›</span>'
      : label;
    return '<div class="' + cls + '"><span class="ll">' + lbl
      + (opts.why ? '<div class="pr-emp-why">' + opts.why + '</div>' : '')
      + (opts.review || '')
      + '</span><span class="lv">' + value + '</span></div>';
  }

  // Header shared by both modes.
  function empHeader(u, subtitle) {
    const initials = (u.fullname || '?').split(/\s+/).map(w => w[0]).slice(0, 2).join('').toUpperCase();
    return '<div class="pr-emp-head"><div class="pr-emp-avatar">' + esc(initials) + '</div>'
      + '<div><div class="pr-emp-name">' + esc(u.fullname) + '</div>'
      + '<div class="pr-emp-sub">' + esc(subtitle) + (u.is_active ? '' : ' · <b>inactive</b>') + '</div></div></div>';
  }

  // ⭐⭐ CUSTOM-SCHEDULE employee: the very card the Custom tab shows, built by the same
  // renderer and wired to the same actions (Add period / Record payment / calendar / rate).
  // No monthly statement, no leave, no overtime — none of those rules apply to them, and
  // showing a monthly "Pay" here was exactly the bug the owner spotted (an absent-day cut and
  // a Rs 2,880 pay button on a khata employee).
  function renderEmployeeCustom() {
    const u = EMP.user, r = EMP.custom_row;
    let body = empHeader(u, 'Custom schedule · ' + EMP.month_label);
    if (r) {
      // 'emp' as the index keeps this card's data-* hooks distinct from the (hidden) Custom
      // tab's cards, which use numeric indexes — wireCust() queries the whole document.
      body += '<div class="pr-cust-list">' + custCard(r, 'emp') + '</div>';
    } else {
      body += '<div class="pr-empty">No custom-schedule card for ' + esc(EMP.month_label)
            + ' — the employee may be hidden from Payroll or not yet set up.</div>';
    }
    let notes = '';
    (EMP.pending_requests || []).forEach(q => {
      notes += '<div class="pr-emp-open">Asked for ' + fmt(q.amount) + ' — not given yet.</div>';
    });
    el('prEmpEmpty').style.display = 'none';
    el('prEmpBody').style.display = '';
    el('prEmpBody').innerHTML = body + notes + empHistoryCard();
    if (r) wireCust(r, 'emp');
  }

  // What was actually paid, month by month — the frozen receipts. Shared by both modes.
  function empHistoryCard() {
    let hist = '<div class="pr-emp-card"><h4>Paid history</h4>';
    if ((EMP.history || []).length) {
      hist += '<div style="overflow-x:auto;"><table class="pr-emp-tbl"><thead><tr>'
        + '<th>Month</th><th>Base</th><th>Days</th><th>Late</th><th>Advances</th><th>Net paid</th><th>Paid</th>'
        + '</tr></thead><tbody>'
        + EMP.history.map(h => '<tr>'
            + '<td><b>' + esc(h.period || monthName(h.pay_month)) + '</b></td>'
            + '<td class="n">' + fmt(h.base_salary) + '</td>'
            + '<td class="n">' + h.present_days + ' / ' + h.absent_days + '</td>'
            + '<td class="n">' + (h.late_deduction > 0 ? fmt(h.late_deduction) : '—') + '</td>'
            + '<td class="n">' + (h.advance_total > 0 ? fmt(h.advance_total) : '—') + '</td>'
            + '<td class="n">' + fmt(h.net_salary) + '</td>'
            + '<td>' + esc(h.funding || '') + ' · ' + esc(String(h.paid_at || '').slice(0, 10)) + '</td>'
          + '</tr>').join('')
        + '</tbody></table></div>'
        + '<div class="pr-emp-why" style="margin-top:8px;">These are the receipts as paid — never recalculated.</div>';
    } else {
      hist += '<div class="pr-emp-why">No salary has been paid through this screen yet.</div>';
    }
    return hist + '</div>';
  }

  function renderEmployee() {
    if (EMP.mode === 'custom') { renderEmployeeCustom(); return; }
    const r = EMP.row, u = EMP.user, bal = EMP.leave_balance || {};
    const paid = !!r.paid;
    const lateCut = lateDed(r);
    const otherDed = Number(r.absent_deduction || 0) + lateCut;
    const netRaw = Number(r.net_raw || 0);

    // ── This month, as a statement rather than a row ───────────────────────
    let month = '<div class="pr-emp-card"><h4>' + esc(EMP.month_label)
      + (paid ? ' — paid' : ' — not paid yet') + '</h4>';
    month += empLine('Base salary', fmt(r.base_salary), {
      why: u.previous_salary && u.last_salary_change_date
        ? 'was ' + fmt(u.previous_salary) + ' until ' + esc(u.last_salary_change_date) : ''
    });
    month += empLine('Attendance', r.present_days + ' present · ' + r.absent_days + ' absent · ' + (r.leave_days || 0) + ' leave',
      { drill: 'month_absent', why: 'Tap to see the days' });
    month += empLine('Absent deduction', r.absent_deduction > 0 ? '− ' + fmt(r.absent_deduction) : 'Rs 0',
      { minus: r.absent_deduction > 0 });
    // ⭐ The SAME review standing the grid shows. This tab is the explanation of the grid,
    // so a figure that has been checked (or is still waiting to be) must say so here too —
    // otherwise the two screens disagree about the same number, which is the whole thing
    // the one-engine rule exists to prevent.
    const lateBits = [];
    if (r.late_minutes > 0) {
      lateBits.push(lateCut > 0 ? fmt(r.per_hour) + '/hr × ' + ((r.late_minutes || 0) / 60).toFixed(1) + 'h'
                                : 'within the free buffer — no pay cut');
    }
    if (Number(r.late_waived_minutes || 0) > 0) {
      lateBits.push(hm(Number(r.late_raw_minutes || 0)) + ' late · ' + hm(r.late_waived_minutes)
        + ' waived · ' + hm(r.late_minutes || 0) + ' counts');
    }
    month += empLine('Late', hm(r.late_minutes || 0), {
      drill: 'month_late',
      why: lateBits.join('<br>'),
      review: reviewChip(r, 'late'),
    });
    if (lateCut > 0) month += empLine('Late deduction', '− ' + fmt(lateCut), { minus: true });
    const otBits = [];
    if (Number(r.ot_carry_in || 0) > 0) otBits.push(hm(r.ot_carry_in) + ' carried in');
    otBits.push('÷ ' + hm(OT_PER_DAY) + ' = ' + (r.bonus_leaves > 0 ? '+' + r.bonus_leaves + ' leave' + (r.bonus_leaves > 1 ? 's' : '') : 'no leave yet'));
    if (Number(r.ot_carry_out || 0) > 0) otBits.push(hm(r.ot_carry_out) + ' carried on');
    month += empLine('Overtime', hm(r.overtime_minutes || 0), {
      drill: 'month_overtime', why: otBits.join(' · '), review: reviewChip(r, 'overtime'),
    });
    month += empLine('Advances taken', r.advance_total > 0 ? '− ' + fmt(r.advance_total) : 'Rs 0',
      { minus: r.advance_total > 0, why: Number(r.other_open_advance_total || 0) > 0
          ? fmt(r.other_open_advance_total) + ' more is open against other months' : '' });
    month += empLine(paid ? 'Net paid' : 'Net to pay', fmt(paid ? r.paid_net : effNet(r)), { total: true });
    if (!paid && netRaw < 0) {
      month += '<div class="pr-emp-open" style="margin-top:10px;">Deductions are '
        + fmt(Math.abs(netRaw)) + ' more than this month\'s salary. He is paid Rs 0; when you '
        + 'press Pay you choose whether that ' + fmt(Math.abs(netRaw)) + ' is written off.</div>';
    }
    if (paid) {
      month += '<div class="pr-emp-why" style="margin-top:8px;">Paid ' + esc(String(r.paid_at || '').slice(0, 10))
        + ' from ' + esc(r.paid_funding || '—') + '. This is the frozen receipt, not a fresh calculation.</div>';
    } else {
      month += '<div class="pr-emp-why" style="margin-top:8px;">Still counting — these figures can change until the month is paid.</div>';
    }
    month += '<div class="pr-emp-acts">'
      + '<button class="pr-btn-ghost" id="prEmpSetSal">Change salary</button>'
      + '<button class="pr-btn-ghost" id="prEmpAdv">＋ advance</button>'
      + (paid ? '' : '<button class="pr-btn-primary" id="prEmpPay">Pay ' + fmt(effNet(r)) + '</button>')
      + '</div></div>';

    // ── Leave position ─────────────────────────────────────────────────────
    let leave = '<div class="pr-emp-card"><h4>Leave</h4>';
    leave += empLine('Yearly quota', (bal.quota_total ?? '—'));
    leave += empLine('Earned from overtime', '+' + (bal.earned_overtime ?? 0));
    leave += empLine('Taken off for lateness', (bal.late_penalties ?? 0));
    if (Number(bal.absence_cover ?? 0) !== 0) leave += empLine('Used to cover parked absences', bal.absence_cover);
    leave += empLine('Taken', (bal.taken_total ?? 0), { drill: 'month_leave' });
    leave += empLine('Remaining', (bal.remaining ?? '—'), { total: true });
    const carryOpen = (EMP.ot_carry || []).reduce((s, c) => s + (c.forfeited_at ? 0 : (c.carried_out || 0)), 0);
    if (EMP.ot_carry_on) {
      leave += '<div class="pr-emp-why" style="margin-top:8px;">Carried overtime: '
        + (carryOpen > 0 ? '<b>' + hm(carryOpen) + '</b> waiting to become a leave day' : 'none')
        + '.</div>';
      if (carryOpen > 0) {
        leave += '<div class="pr-emp-acts"><button class="pr-btn-ghost" id="prEmpForfeit">Forfeit carried overtime</button></div>';
      }
    }
    leave += '</div>';

    // ── Leave actions — decided HERE, through the SAME endpoint the panel uses ─────
    // Owner: these must be clickable and editable like everywhere else, and must not
    // become a second set of numbers. So they render from the row's own
    // `leave_actions` (leaveActionsForRow) and post to /leave-actions/decide.
    let la = '';
    const acts = r.leave_actions || [];
    if (acts.length) {
      la = '<div class="pr-emp-card"><h4>Leave actions — ' + esc(EMP.month_label) + '</h4>';
      acts.forEach(a => {
        const pending = a.status === 'pending';
        // ⭐⭐ Sep-6 2026 — SAME vocabulary and SAME decided-state pattern as the panel.
        //   This tab used to keep its buttons live after a decision (while the panel
        //   removed them), and it invented its own words — "Give" / "Skip" where the panel
        //   said "Give +2 bonus leaves" / "Skip". Both now come from the server's
        //   `choices` / `decided_label`, and a settled row is a chip until you ask to
        //   change it. The layout stays this tab's own; only the control column is shared.
        const settled = a.status && a.status !== 'pending';
        const canDecide = a.kind === 'absence' || EMP.month_closed;
        const btns = () => (a.choices || []).map(c =>
          '<button class="nfla-btn nfla-t-' + esc(c.tone || 'muted')
            + (a.current_choice && c.value === a.current_choice ? ' nfla-cur' : '')
            + '" data-empdec="' + esc(a.kind) + '" data-empchoice="' + esc(c.value) + '"'
            + (c.hint ? ' title="' + esc(c.hint) + '"' : '') + '>' + esc(c.label) + '</button>'
        ).join(' ');
        let ctl;
        if (!settled) {
          ctl = canDecide
            ? btns()
            : '<span class="pr-emp-why">Can be decided once ' + esc(EMP.month_label) + ' ends</span>';
        } else if (EMP_CHANGE[a.kind]) {
          ctl = btns() + ' <span class="nfla-link" data-empcancel="' + esc(a.kind) + '">cancel</span>';
        } else {
          const who = [a.decided_by ? 'by ' + esc(a.decided_by) : '', a.decided_at || ''].filter(Boolean).join(' · ');
          ctl = '<span class="nfla-chip nfla-t-' + esc(a.decided_tone || 'muted') + '">'
              + esc(a.decided_label || '') + '</span>'
              + (who ? ' <span class="nfla-who">' + who + '</span>' : '')
              + (canDecide ? ' <span class="nfla-link" data-empchange="' + esc(a.kind) + '">change ›</span>' : '');
        }
        la += '<div class="pr-emp-line"><span class="ll">'
            + '<span class="pr-emp-drill" data-drill="' + esc(a.drill || '') + '" data-uid="' + EMP_ID + '">'
            + esc(a.headline || a.kind) + ' ›</span>'
            + '<div class="pr-emp-why">' + esc(a.basis || '')
            + (a.formula ? ' · ' + esc(a.formula) : '') + '</div>'
            + (pending && a.kind === 'absence'
                ? '<div class="pr-emp-why" style="color:#b91c1c;">undecided — Pay will deduct it</div>'
                : '')
            + '</span><span class="lv" style="font-weight:400;">' + ctl + '</span></div>';
      });
      la += '</div>';
    }

    // ── Absences ───────────────────────────────────────────────────────────
    // What happened to this month's absences, and anything still owed from earlier ones.
    let absc = '';
    const owed = Number(r.absence_outstanding || 0);
    if (Number(r.absent_days || 0) > 0 || owed > 0 || Number(r.held_absence_deduction || 0) > 0) {
      absc = '<div class="pr-emp-card"><h4>Absences</h4>';
      if (Number(r.absent_days || 0) > 0) {
        const dec = r.absence_decision;
        const what = dec === 'park' ? 'Parked — no cut; overtime days will settle them'
          : dec === 'excuse' ? 'Excused — no cut, nothing owed'
          : (dec === 'cut' ? 'Being deducted' : 'Not decided — Pay will deduct this');
        absc += empLine(esc(EMP.month_label) + ': ' + r.absent_days + ' day' + (r.absent_days === 1 ? '' : 's'),
          dec === 'park' || dec === 'excuse' ? 'Rs 0' : fmt(r.absence_raw_deduction),
          { drill: 'month_absent', why: what + ' · ' + fmt(r.absence_day_rate) + ' per day' });
      }
      if (Number(r.held_absence_deduction || 0) > 0) {
        absc += empLine('Charged this month for earlier absences',
          '− ' + fmt(r.held_absence_deduction), { minus: true,
            why: r.held_absence_days + ' day(s) from ' + (r.held_absence_months || []).map(monthName).join(', ') });
      }
      if (owed > 0) {
        absc += '<div class="pr-emp-open" style="margin-top:10px;">Still owed: <b>' + esc(r.absence_owed_label)
             + '</b>. Overtime days settle these automatically.</div>'
             + '<div class="pr-emp-acts">'
             + '<button class="pr-btn-ghost" data-abssettle="charge">Charge it</button>'
             + '<button class="pr-btn-ghost" data-abssettle="use_leave">Use his leave</button>'
             + '<button class="pr-btn-ghost" data-abssettle="excuse">Excuse it</button>'
             + '</div>';
      }
      absc += '</div>';
    }

    // ── Advances ───────────────────────────────────────────────────────────
    let adv = '<div class="pr-emp-card"><h4>Advances</h4>';
    const list = r.advances || [];
    adv += list.length
      ? list.map(a => '<div class="pr-emp-line"><span class="ll">' + esc(a.date || '—')
          + '<div class="pr-emp-why">' + esc(a.request_number || '') + (a.bank ? ' · ' + esc(a.bank) : ' · cash')
          + (a.given_by ? ' · by ' + esc(a.given_by) : '')
          + (a.carried_from ? ' · carried from ' + esc(monthNameOf(a.carried_from)) + ' (' + fmt(a.original_amount) + ' given)' : '')
          + '</div></span>'
          + '<span class="lv">' + fmt(a.amount) + '</span></div>').join('')
      : '<div class="pr-emp-why">Nothing open for this month.</div>';
    (EMP.pending_requests || []).forEach(q => {
      adv += '<div class="pr-emp-open" style="margin-top:8px;">Asked for ' + fmt(q.amount)
           + ' — not given yet.</div>';
    });
    adv += '</div>';

    const hist = empHistoryCard();

    el('prEmpEmpty').style.display = 'none';
    el('prEmpBody').style.display = '';
    el('prEmpBody').innerHTML = empHeader(u, 'Monthly salary · ' + EMP.month_label)
      + '<div class="pr-emp-grid"><div>' + month + hist + '</div><div>' + leave + la + absc + adv + '</div></div>';

    wireEmployee();
  }

  function monthName(ym) {
    if (!ym) return '';
    const [y, m] = String(ym).split('-').map(Number);
    return new Date(y, m - 1, 1).toLocaleDateString('en-GB', { month: 'short', year: 'numeric' });
  }

  function wireEmployee() {
    const r = EMP.row;
    // Drills need no wiring here: a document-level [data-drill] delegate already opens the
    // SAME sheet the grid uses, so the days shown in both places cannot disagree — and
    // adding an onclick as well would fire it twice.
    // Settle days parked in an EARLIER month. The oldest owed month is settled first, the
    // same order the server uses, so the button and the result always agree.
    el('prEmpBody').querySelectorAll('[data-abssettle]').forEach(b => {
      b.onclick = async () => {
        const action = b.getAttribute('data-abssettle');
        const oldest = (EMP.absence_open || [])[0];
        if (!oldest) { alert('Nothing is owed.'); return; }
        const from = monthName(oldest.month);
        let body = { user_id: EMP_ID, month: oldest.month, action };
        if (action === 'charge') {
          const suggested = Math.round(oldest.outstanding * Number(r.absence_day_rate || 0));
          const amt = prompt('Charge ' + EMP.user.fullname + ' for ' + oldest.outstanding
            + ' day(s) of ' + from + ' absence.\n\nThis goes on ' + EMP.month_label
            + "'s pay. Amount (today's rate is suggested — change it if you want):", suggested);
          if (amt === null) return;
          body.amount = Number(amt);
          body.in_month = EMP.month;
        } else if (action === 'use_leave') {
          if (!confirm('Use ' + oldest.outstanding + ' of ' + EMP.user.fullname
            + "'s own leave to cover the " + from + ' absence?')) return;
        } else {
          if (!confirm('Excuse ' + oldest.outstanding + ' day(s) of ' + from
            + ' absence? Nothing will be owed or deducted.')) return;
        }
        try {
          const res = await fetch('/hr/payroll/settle-absence', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(body)
          });
          const j = await res.json();
          alert(j.message || (j.success ? 'Done.' : 'Could not do that.'));
          if (j.success) empLoad();
        } catch (e) { alert('Could not do that: ' + (e.message || e)); }
      };
    });

    // "change ›" re-opens the very buttons that were used, with the current one marked —
    // never a blind flip to the other state. "cancel" puts the chip back.
    el('prEmpBody').querySelectorAll('[data-empchange]').forEach(c => {
      c.onclick = () => { EMP_CHANGE[c.getAttribute('data-empchange')] = true; renderEmployee(); };
    });
    el('prEmpBody').querySelectorAll('[data-empcancel]').forEach(c => {
      c.onclick = () => { delete EMP_CHANGE[c.getAttribute('data-empcancel')]; renderEmployee(); };
    });

    // Same decide function the leave panel uses — not a copy.
    el('prEmpBody').querySelectorAll('[data-empdec]').forEach(b => {
      b.onclick = () => {
        const kind = b.getAttribute('data-empdec');
        const choice = b.getAttribute('data-empchoice');
        const act = ((r.leave_actions || []).filter(x => x.kind === kind))[0] || {};
        // Re-picking what is already in force closes the row instead of posting.
        if (act.current_choice && act.current_choice === choice) {
          delete EMP_CHANGE[kind]; renderEmployee(); return;
        }
        if (act.status && act.status !== 'pending') {
          const was = String(act.decided_label || 'the current decision').replace(/^[✓✕🅿]\s*/, '');
          const to = ((act.choices || []).filter(c => c.value === choice))[0];
          if (!confirm('Change ' + (r.fullname || 'this employee') + '’s ' + (act.headline || kind) + '?\n\n'
                     + 'Now: ' + was + '\nChange to: ' + ((to && to.label) || choice))) return;
        }
        delete EMP_CHANGE[kind];
        decideLeaveAction({ user_id: EMP_ID, kind: kind }, choice);
      };
    });
    const sal = el('prEmpSetSal'); if (sal) sal.onclick = () => editBase(r);
    const adv = el('prEmpAdv');    if (adv) adv.onclick = () => openAdvance(r);
    const pay = el('prEmpPay');
    if (pay) pay.onclick = async () => {
      // Reuse the real pay modal with a selection of exactly one. The tab is the landing
      // view, so the grid may never have been fetched — load it (hidden) first.
      if (!ROWS.length || CURMONTH !== el('prMonth').value) await load();
      ROWS.forEach(x => { x._sel = false; });
      const target = ROWS.find(x => Number(x.user_id) === Number(EMP_ID));
      if (!target) { alert('This employee is not on the Monthly grid for ' + EMP.month_label + '.'); return; }
      target._sel = true;
      el('prPayBtn').click();
    };
    const ff = el('prEmpForfeit');
    if (ff) ff.onclick = async () => {
      const why = prompt('Forfeit the carried overtime for ' + EMP.user.fullname
        + '?\n\nThis removes overtime he already worked, so say why:');
      if (why === null) return;
      if (!why.trim()) { alert('A reason is required.'); return; }
      try {
        const res = await fetch('/hr/payroll/forfeit-carry', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
          body: JSON.stringify({ user_id: EMP_ID, reason: why.trim() })
        });
        const j = await res.json();
        alert(j.message || (j.success ? 'Done.' : 'Could not do that.'));
        if (j.success) empLoad();
      } catch (e) { alert('Could not do that: ' + (e.message || e)); }
    };
  }

  async function customLoad() {
    const month = el('prMonth').value;
    el('prCustList').innerHTML = '<div class="pr-empty">Loading…</div>';
    try {
      const res = await fetch('/hr/payroll/custom-data?month=' + encodeURIComponent(month), { headers: { 'Accept': 'application/json' } });
      const j = await res.json();
      if (!j.success) throw new Error(j.message || 'Failed');
      CURMONTH = j.month;
      FUND = j.funding || { cash: null, banks: [] };
      SCHEDULE_AVAILABLE = !!j.schedule_available;
      KHAAS_AVAILABLE = !!j.khaas_available;
      KHAAS_BU_ID = j.khaas_bu_id || null;
      CAN_VOID = !!j.can_void_advance;
      BAL_AVAILABLE = !!j.balance_available;
      CAN_VOID_PAY = !!j.can_void_payment;
      TODAY = j.today || TODAY;
      ADV_SUMMARY = j.advance_summary || null;
      CUST_ROWS = j.rows || [];
      CUST_LOADED_MONTH = month;
      buildFundModal();
      renderBanner();
      renderCustom();
      if (TAB === 'employee' && EMP_ID) empLoad();   // same reason as in load()
    } catch (e) {
      el('prCustList').innerHTML = '<div class="pr-empty">Could not load: ' + (e.message || e) + '</div>';
    }
  }

  function renderCustom() {
    if (!SCHEDULE_AVAILABLE) {
      el('prCustList').innerHTML = '<div class="pr-empty">Custom schedules aren\'t enabled yet.</div>';
      return;
    }
    if (!CUST_ROWS.length) {
      el('prCustList').innerHTML = '<div class="pr-empty">No custom-schedule employees yet. Use ⚙ settings on the Monthly tab to move someone here.</div>';
      return;
    }
    el('prCustList').innerHTML = CUST_ROWS.map((r, i) => custCard(r, i)).join('');
    CUST_ROWS.forEach((r, i) => wireCust(r, i));
  }

  function custCard(r, i) {
    const unit = r.rate_type === 'daily' ? '/ day' : '/ month';
    const rate = r.configured
      ? '<span class="pr-rate" data-crate="' + i + '"><span class="amt">' + fmt(r.base_rate) + '</span><span class="unit">' + unit + '</span><span style="color:#9ca3af;font-size:11px;">✎</span></span>'
      : '<span class="pr-setsal" data-crate="' + i + '">＋ Set rate</span>';
    const buChip = KHAAS_AVAILABLE
      ? '<span class="pr-bu bu-' + (r.bu_code === 'KHAAS' ? 'KHAAS">Khaas' : 'NF">NF') + '</span>'
      : '';

    // A running-balance employee is a different KIND of card: one balance and a
    // calendar, no periods and no advances. Everyone else keeps the period card
    // exactly as it was.
    //
    // "Paid in <month>" must count BOTH channels for a tracked employee: the khata
    // payments AND any date-range periods filed under that month from before the
    // balance started. They are different entry kinds, so they can never overlap —
    // and counting only the khata read "Paid in Aug: Rs 0" for men who really had
    // been paid tens of thousands by period earlier that same month.
    const paidThisMonth = r.balance_tracked
      ? (((r.month_summary || {}).paid || 0) + (r.paid_total || 0))
      : (r.paid_total || 0);
    const body = r.balance_tracked ? balBody(r, i) : periodBody(r, i);

    return '<div class="pr-cust-card">' +
      '<div class="pr-cust-top">' +
        '<div><div class="pr-cust-name">' + esc(r.fullname) +
          (r.designation ? ' <span style="font-weight:400;color:#9ca3af;font-size:12px;">· ' + esc(r.designation) + '</span>' : '') + '</div>' +
          '<div class="pr-cust-meta">' + rate + buChip +
            (r.balance_tracked ? '<span class="pr-ratepill" title="Paid against a running balance">📒 running balance</span>' : '') +
            '<span class="pr-gear" data-csettings="' + i + '" title="Pay schedule & business unit">⚙ settings</span></div></div>' +
        '<div style="text-align:right;"><div style="font-size:11px;color:#9ca3af;">Paid in ' + esc(monthLabel()) + '</div>' +
          '<div style="font-weight:700;">' + fmt(paidThisMonth) + '</div></div>' +
      '</div>' +
      body +
    '</div>';
  }

  // ---- period card (unchanged behaviour) ----
  function periodBody(r, i) {
    let cover = (r.paid_periods || []).map((p, pi) =>
      '<span class="pr-cover-chip paid" data-period="' + i + '_' + pi + '" title="Paid ' + esc(String(p.paid_at).slice(0, 10)) +
        (paidFromLabel(p) ? ' from ' + paidFromLabel(p) : '') + '">✓ ' + esc(p.label) + ' · ' + fmt(p.net) + '</span>'
    ).join('');
    if (!r.paid_periods || !r.paid_periods.length) {
      cover += '<span class="pr-cover-chip none">Nothing paid in this month yet</span>';
    }
    cover += '<button class="pr-cover-add" data-addperiod="' + i + '">＋ Add period</button>';

    // Open advances: the amount opens the same list the Monthly tab shows (each
    // advance's amount, date and request number) — the manager can see WHAT is open,
    // not just the total. `data-cadv` (not `data-adv`) because both views live in the
    // DOM at once and a shared attribute would wire the monthly row's element.
    const adv = r.advance_total > 0
      ? '<div class="pr-cust-adv">Open advances: <span class="view" data-cadv="' + i + '">' + fmt(r.advance_total) + '</span> (deducted next pay) <span class="give" data-cgive="' + i + '">＋ advance</span></div>'
      : '<div class="pr-cust-adv" style="color:#9ca3af;">No open advances <span class="give" data-cgive="' + i + '">＋ advance</span></div>';

    // Offered only once a rate exists — the khata prices days off it from day one.
    const start = (BAL_AVAILABLE && r.configured)
      ? '<div class="pr-bal-start" data-balstart="' + i + '">📒 Start a running balance — record payments &amp; mark absent days instead</div>'
      : '';

    return '<div class="pr-cover">' + cover + '</div>' + adv + start;
  }

  // ---- running-balance card ----
  function balBody(r, i) {
    const b = r.balance || {};
    const s = r.month_summary || {};
    const dir = b.direction || 'settled';
    const asOf = (s.is_current === false) ? ('to ' + esc(niceDate(s.closing_date))) : 'as of today';

    const hero =
      '<div class="pr-bal-hero ' + dir + '">' +
        '<div>' +
          '<div class="pr-bal-lbl">' + esc(b.label || 'All settled') + '</div>' +
          '<div class="pr-bal-amt">' + fmt(b.amount || 0) + '</div>' +
          '<div class="pr-bal-note">as of today · balance running since ' + esc(b.start_label || '') + '</div>' +
        '</div>' +
        '<div class="pr-bal-acts">' +
          '<button class="pr-bal-btn primary" data-balpay="' + i + '">＋ Payment</button>' +
          '<button class="pr-bal-btn" data-balcal="' + i + '">📅 Calendar</button>' +
        '</div>' +
      '</div>';

    const stat = (label, value, extra) =>
      '<span class="pr-bal-stat">' + label + ' <b>' + value + '</b>' + (extra ? ' · ' + extra : '') + '</span>';

    // A month entirely BEFORE the anchor has no days in this account at all. Saying
    // "earned 0 · Balance All settled 0" there reads as though the month was worked and
    // squared up — it wasn't, the khata did not exist yet. Same wording as the calendar.
    let stats = s.before_start
      ? '<div class="pr-bal-stats"><span class="pr-bal-stat">' + esc(monthLabel()) +
        ' is before this balance started on <b>' + esc(b.start_label || '') + '</b></span></div>'
      : '<div class="pr-bal-stats">' +
        stat(esc(monthLabel()) + ' earned', fmt(s.earned || 0), (s.counted || 0) + ' day' + ((s.counted === 1) ? '' : 's') + ' counted') +
        stat(esc(monthLabel()) + ' paid', fmt(s.paid || 0), (r.month_payments || []).length + ' payment' + (((r.month_payments || []).length === 1) ? '' : 's')) +
        ((s.crossed || 0) > 0 ? stat('Not counted', s.crossed + ' day' + (s.crossed === 1 ? '' : 's'), '') : '') +
        ((s.is_current === false) ? stat('Balance ' + asOf, esc(s.label || '') + ' ' + fmt(s.amount || 0), '') : '') +
        '</div>';

    // Date-range payments filed under the month being viewed, from BEFORE the balance
    // started. That money really was paid and the records are intact, so the card shows
    // them rather than pretending the month was empty. Read-only — periods are blocked
    // for a tracked employee, so there is no "＋ Add period" here. The chips reuse the
    // same data-period hooks, so clicking one opens the same frozen receipt as always.
    const legacy = (r.paid_periods || []).length
      ? '<div class="pr-bal-legacy">' +
          '<div class="lbl">Also paid in ' + esc(monthLabel()) + ' by date range, before the running balance started ' +
          '— already settled, and not part of the balance above</div>' +
          '<div class="pr-cover" style="margin-top:7px;">' +
            (r.paid_periods || []).map((p, pi) =>
              '<span class="pr-cover-chip paid" data-period="' + i + '_' + pi + '" title="Paid ' + esc(String(p.paid_at).slice(0, 10)) +
                (paidFromLabel(p) ? ' from ' + paidFromLabel(p) : '') + '">✓ ' + esc(p.label) + ' · ' + fmt(p.net) + '</span>'
            ).join('') +
          '</div>' +
        '</div>'
      : '';

    // A leftover advance from before the khata (shouldn't happen — enabling converts
    // them — but if one is created another way it must not sit there invisibly).
    const warn = (r.advance_total > 0)
      ? '<div class="pr-bal-warn">⚠ This employee still has ' + fmt(r.advance_total) +
        ' in open advances from before the running balance. They are <b>not</b> part of this balance — ' +
        '<span class="view" style="text-decoration:underline;cursor:pointer;" data-cadv="' + i + '">see them</span>.</div>'
      : '';

    return hero + stats + legacy + warn;
  }

  function niceDate(d) {
    if (!d) return '';
    const t = new Date(d + 'T00:00:00');
    return t.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
  }
  /** '2026-08' → 'August 2026', for text that names a month other than the one on screen. */
  function monthName(m) {
    const [y, mm] = String(m || '').split('-').map(Number);
    if (!y || !mm) return '';
    return new Date(y, mm - 1, 1).toLocaleString('en-US', { month: 'long', year: 'numeric' });
  }

  function monthLabel() {
    const [y, m] = (CURMONTH || el('prMonth').value).split('-').map(Number);
    return new Date(y, m - 1, 1).toLocaleDateString('en-GB', { month: 'short', year: 'numeric' });
  }

  function wireCust(r, i) {
    const rate = document.querySelector('[data-crate="' + i + '"]');
    if (rate) rate.onclick = () => editRate(r);
    const gear = document.querySelector('[data-csettings="' + i + '"]');
    if (gear) gear.onclick = () => openSettings(r);
    const add = document.querySelector('[data-addperiod="' + i + '"]');
    if (add) add.onclick = () => openCustomPay(r);
    const give = document.querySelector('[data-cgive="' + i + '"]');
    if (give) give.onclick = () => openAdvance(r);
    const cadv = document.querySelector('[data-cadv="' + i + '"]');
    if (cadv) cadv.onclick = () => showAdvances(r, 'custom');
    (r.paid_periods || []).forEach((p, pi) => {
      const chip = document.querySelector('[data-period="' + i + '_' + pi + '"]');
      if (chip) chip.onclick = () => showCustomPeriod(r, p);
    });
    // running-balance controls
    const bpay = document.querySelector('[data-balpay="' + i + '"]');
    if (bpay) bpay.onclick = () => openBalPay(r);
    const bcal = document.querySelector('[data-balcal="' + i + '"]');
    if (bcal) bcal.onclick = () => openBalCal(r);
    const bstart = document.querySelector('[data-balstart="' + i + '"]');
    if (bstart) bstart.onclick = () => openBalEnable(r);
  }

  // Rate edit reuses the base-salary endpoint (base_salary IS the rate for custom).
  // A tracked employee goes to the dated rate change instead: their past days must
  // keep the price they were worked at.
  function editRate(r) {
    if (r.balance_tracked) { openBalRate(r); return; }
    const cur = r.configured ? Math.round(r.base_rate) : '';
    const val = prompt('Rate for ' + r.fullname + ' (' + (r.rate_type === 'daily' ? 'per DAY' : 'per MONTH') + '):', cur);
    if (val === null) return;
    const num = Number(val);
    if (String(val).trim() === '' || isNaN(num) || num < 0) { alert('Enter a valid amount.'); return; }
    fetch('/hr/payroll/set-salary', {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify({ user_id: r.user_id, base_salary: num })
    }).then(res => res.json()).then(j => {
      if (!j.success) throw new Error(j.message || 'Failed');
      customLoad();
    }).catch(e => alert('Could not save rate: ' + (e.message || e)));
  }

  // The frozen receipt for a paid period — the same story the Monthly tab tells:
  // what the period earned, what advance was recovered from it, what was handed
  // over, from which account and by whom. Every figure comes from the payment row.
  function showCustomPeriod(r, p) {
    const line = (label, value, neg, muted) =>
      '<div class="pr-daterow"><span class="dt" style="font-weight:400;color:#6b7280;">' + label + '</span>' +
      '<span class="dt"' + (neg ? ' style="color:#b91c1c;"' : (muted ? ' style="color:#cbd5e1;"' : '')) + '>' + value + '</span></div>';

    const adv = Number(p.advance_total || 0);
    const gross = (p.gross !== undefined && p.gross !== null) ? Number(p.gross) : (Number(p.net || 0) + adv);
    let html = '';
    html += line('Period', esc(p.label));
    html += line('Paid on', p.paid_at ? esc(String(p.paid_at).slice(0, 16)) : '—');
    if (p.paid_by_name) html += line('Paid by', esc(p.paid_by_name));
    html += line('Paid from', paidFromLabel(p));
    html += '<div style="height:8px;"></div>';
    if (p.days_paid) html += line('Days paid', p.days_paid + ' day' + (p.days_paid === 1 ? '' : 's'));
    html += line('Amount for the period', fmt(gross));
    html += line('Advances recovered', adv > 0 ? '− ' + fmt(adv) : 'none', adv > 0, adv === 0);
    if (p.present_days !== undefined && p.present_days !== null) {
      html += line('Attendance (reference)', p.present_days + ' present · ' + p.absent_days + ' absent');
    }
    if (p.notes) html += line('Note', esc(p.notes));
    html += '<div class="pr-daterow" style="font-weight:700;border-top:2px solid #eef0f2;margin-top:4px;"><span class="dt">Net paid</span><span class="dt" style="color:#047857;">' + fmt(p.net) + '</span></div>';
    html += '<div style="padding:12px 14px;font-size:11.5px;color:#9ca3af;">' +
      (p.ledger_id ? ('Ledger entry #' + p.ledger_id + '. ')
                   : (adv > 0 ? 'No cash moved — the whole amount went to recovering advances. ' : '')) +
      'This range is settled; overlapping days are blocked for new periods.</div>';

    sheetWide(false);
    el('prSheetTitle').textContent = 'Period — ' + r.fullname;
    el('prSheetBody').innerHTML = html;
    el('prSheet').classList.add('show');
  }

  // ---- settings modal (schedule + business unit) ----
  let SET_ROW = null, SET_SCHED = 'monthly', SET_RATE = 'monthly', SET_BU = 'NF';
  function setRadioGroup(containerId, attr, value) {
    document.querySelectorAll('#' + containerId + ' [data-' + attr + ']').forEach(x => {
      x.classList.toggle('active', x.getAttribute('data-' + attr) === value);
    });
  }
  function openSettings(r) {
    SET_ROW = r;
    SET_SCHED = r.pay_schedule === 'custom' ? 'custom' : 'monthly';
    SET_RATE = r.rate_type === 'daily' ? 'daily' : 'monthly';
    SET_BU = r.bu_code === 'KHAAS' ? 'KHAAS' : 'NF';
    el('prSetTitle').textContent = 'Pay settings — ' + r.fullname;
    setRadioGroup('prSetSched', 'sched', SET_SCHED);
    setRadioGroup('prSetRate', 'rate', SET_RATE);
    setRadioGroup('prSetBu', 'bu', SET_BU);
    el('prSetRateWrap').style.display = SET_SCHED === 'custom' ? 'block' : 'none';
    el('prSetBuWrap').style.display = KHAAS_AVAILABLE ? 'block' : 'none';
    // While a running balance is on, the schedule and rate unit are frozen (the server
    // refuses them too): switching would strand the balance or reprice the whole account.
    // The business unit stays editable, so the save skips the schedule call instead of
    // failing the whole modal.
    const locked = !!r.balance_tracked;
    el('prSetLockNote').style.display = locked ? 'block' : 'none';
    el('prSetLockNote').innerHTML = locked
      ? '<div class="pr-bal-warn" style="margin-top:0;margin-bottom:12px;">📒 This employee is on a <b>running balance</b>, so the pay schedule and rate unit stay fixed. The rate itself is changed from their card, with the date it applies from.</div>'
      : '';
    ['prSetSched', 'prSetRate'].forEach(id => {
      el(id).style.opacity = locked ? '.45' : '';
      el(id).style.pointerEvents = locked ? 'none' : '';
    });
    el('prSetModal').classList.add('show');
  }
  document.querySelectorAll('#prSetSched [data-sched]').forEach(x => x.onclick = () => {
    SET_SCHED = x.getAttribute('data-sched'); setRadioGroup('prSetSched', 'sched', SET_SCHED);
    el('prSetRateWrap').style.display = SET_SCHED === 'custom' ? 'block' : 'none';
  });
  document.querySelectorAll('#prSetRate [data-rate]').forEach(x => x.onclick = () => {
    SET_RATE = x.getAttribute('data-rate'); setRadioGroup('prSetRate', 'rate', SET_RATE);
  });
  document.querySelectorAll('#prSetBu [data-bu]').forEach(x => x.onclick = () => {
    SET_BU = x.getAttribute('data-bu'); setRadioGroup('prSetBu', 'bu', SET_BU);
  });
  el('prSetCancel').onclick = () => el('prSetModal').classList.remove('show');
  el('prSetModal').onclick = (ev) => { if (ev.target === el('prSetModal')) el('prSetModal').classList.remove('show'); };
  el('prSetSave').onclick = async () => {
    if (!SET_ROW) return;
    el('prSetSave').disabled = true; el('prSetSave').textContent = 'Saving…';
    try {
      // A balance-tracked employee's schedule is frozen — skip that call rather than
      // let its (correct) refusal block a business-unit change.
      if (!SET_ROW.balance_tracked) {
        const r1 = await fetch('/hr/payroll/set-schedule', {
          method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
          body: JSON.stringify({ user_id: SET_ROW.user_id, pay_schedule: SET_SCHED, rate_type: SET_RATE })
        }).then(x => x.json());
        if (!r1.success) throw new Error(r1.message || 'Failed');
      }
      if (KHAAS_AVAILABLE) {
        const r2 = await fetch('/hr/payroll/set-business-unit', {
          method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
          body: JSON.stringify({ user_id: SET_ROW.user_id, business_unit_id: SET_BU === 'KHAAS' ? KHAAS_BU_ID : null })
        }).then(x => x.json());
        if (!r2.success) throw new Error(r2.message || 'Failed');
      }
      el('prSetModal').classList.remove('show');
      reloadActive();
    } catch (e) {
      alert('Could not save: ' + (e.message || e));
    } finally {
      el('prSetSave').disabled = false; el('prSetSave').textContent = 'Save';
    }
  };

  // ---- custom period pay modal ----
  let CUST_ROW = null;
  function openCustomPay(r) {
    if (!r.configured) { alert('Set this employee\'s rate first (click the rate).'); return; }
    CUST_ROW = r;
    el('prCustTitle').textContent = 'Add period — ' + r.fullname;
    el('prCustStart').value = r.suggested_start || '';
    el('prCustEnd').value = '';
    el('prCustAmount').value = '';
    el('prCustNote').value = '';
    el('prCustCalcInner').innerHTML = '<div class="row"><span>Pick the end date to see the amount</span><span></span></div>';
    document.querySelectorAll('[data-custfund]').forEach(x => x.classList.remove('active'));
    document.querySelector('[data-custfund="cash"]').classList.add('active');
    document.querySelector('input[name=prCustFund][value=cash]').checked = true;
    el('prCustBankSel').disabled = true;
    el('prCustModal').classList.add('show');
  }
  async function custPreview() {
    if (!CUST_ROW) return;
    const start = el('prCustStart').value, end = el('prCustEnd').value;
    if (!start || !end) return;
    el('prCustCalcInner').innerHTML = '<div class="row"><span>Calculating…</span><span></span></div>';
    try {
      const res = await fetch('/hr/payroll/custom-preview', {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ user_id: CUST_ROW.user_id, start, end })
      });
      const j = await res.json();
      if (!j.success) throw new Error(j.message || 'Failed');
      const row = j.row;
      const unit = row.rate_type === 'daily'
        ? (fmt(row.base_rate) + '/day × ' + row.days + ' day' + (row.days === 1 ? '' : 's'))
        : (fmt(row.base_rate) + '/mo ÷ 30 × ' + row.days + ' day' + (row.days === 1 ? '' : 's'));
      let html = '<div class="row"><span>' + unit + '</span><span>' + fmt(row.computed_amount) + '</span></div>';
      if (row.advance_total > 0) html += '<div class="row"><span>Advances recovered this pay</span><span>− ' + fmt(row.advance_total) + '</span></div>';
      html += '<div class="row tot"><span>Net at computed amount</span><span>' + fmt(row.net_amount) + '</span></div>';
      if (row.advance_open_after > 0) html += '<div class="ref" style="color:#b45309;">' + fmt(row.advance_open_after) + ' of advances is bigger than this pay — it stays open for a later payment.</div>';
      html += '<div class="ref">In range: ' + row.present_days + ' present · ' + row.absent_days + ' absent (reference only — does not change pay)</div>';
      el('prCustCalcInner').innerHTML = html;
      if (el('prCustAmount').value.trim() === '') el('prCustAmount').value = Math.round(row.computed_amount);
    } catch (e) {
      el('prCustCalcInner').innerHTML = '<div class="row"><span style="color:#b91c1c;">' + esc(e.message || String(e)) + '</span><span></span></div>';
    }
  }
  el('prCustStart').onchange = () => { el('prCustAmount').value = ''; custPreview(); };
  el('prCustEnd').onchange = () => { el('prCustAmount').value = ''; custPreview(); };
  document.querySelectorAll('[data-custfund]').forEach(f => {
    f.onclick = (ev) => {
      if (ev.target.closest('select')) return;
      document.querySelectorAll('[data-custfund]').forEach(x => x.classList.remove('active'));
      f.classList.add('active');
      f.querySelector('input[type=radio]').checked = true;
      const dis = (f.getAttribute('data-custfund') !== 'online');
      if (el('prCustBankSel').disabled !== dis) el('prCustBankSel').disabled = dis;
    };
  });
  el('prCustCancel').onclick = () => el('prCustModal').classList.remove('show');
  el('prCustModal').onclick = (ev) => { if (ev.target === el('prCustModal')) el('prCustModal').classList.remove('show'); };
  el('prCustConfirm').onclick = async () => {
    if (!CUST_ROW) return;
    const start = el('prCustStart').value, end = el('prCustEnd').value;
    if (!start || !end) { alert('Pick the start and end dates.'); return; }
    const fundType = document.querySelector('input[name=prCustFund]:checked').value;
    const bankId = el('prCustBankSel').value;
    if (fundType === 'online' && !bankId) { alert('Choose the bank.'); return; }
    const amount = el('prCustAmount').value.trim();
    el('prCustConfirm').disabled = true; el('prCustConfirm').textContent = 'Paying…';
    try {
      const res = await fetch('/hr/payroll/custom-pay', {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({
          user_id: CUST_ROW.user_id, start, end,
          funding: fundType, bank_id: fundType === 'online' ? Number(bankId) : null,
          amount: amount === '' ? null : Number(amount), note: el('prCustNote').value || null
        })
      });
      const j = await res.json();
      if (!j.success) throw new Error(j.message || 'Failed');
      el('prCustModal').classList.remove('show');
      alert('Paid ' + fmt(j.net) + '.');
      customLoad();
    } catch (e) {
      alert('Could not pay: ' + (e.message || e));
    } finally {
      el('prCustConfirm').disabled = false; el('prCustConfirm').textContent = 'Pay period';
    }
  };

  // ============================================================
  //  CUSTOM RUNNING BALANCE ("khata")
  // ============================================================
  // One employee's account: days earn, payments subtract, the difference carries
  // forward. Everything here is scoped to the Custom tab — the monthly grid never
  // reaches any of it. Element ids are all prefixed prBal… because both views are
  // in the DOM at once and a shared id would wire the wrong element.

  let BAL_ROW = null;        // employee whose calendar is open
  let BAL_CAL = null;        // the loaded month payload
  let BAL_CAL_MONTH = '';
  let BAL_DIRTY = false;     // something changed → refresh the list when the calendar closes

  // Compact money for a calendar cell: 10,000 → "10k", 4,500 → "4.5k".
  function balShort(n) {
    n = Math.round(Number(n) || 0);
    if (n === 0) return '0';
    if (n < 1000) return String(n);
    const k = n / 1000;
    return (k >= 100 ? Math.round(k) : Math.round(k * 10) / 10) + 'k';
  }

  // The balance in words + colour, never a bare minus sign.
  function balWords(signed) {
    const v = Number(signed) || 0;
    if (Math.abs(v) < 0.005) return { dir: 'settled', label: 'All settled', amount: 0 };
    return v > 0
      ? { dir: 'paid_ahead', label: 'Paid ahead', amount: v }
      : { dir: 'to_pay', label: 'To pay', amount: -v };
  }

  // ---- calendar ----
  async function openBalCal(r) {
    BAL_ROW = r;
    BAL_DIRTY = false;
    const startMonth = (r.balance && r.balance.track_start) ? r.balance.track_start.slice(0, 7) : '';
    let month = CURMONTH || el('prMonth').value;
    if (startMonth && month < startMonth) { month = startMonth; }
    el('prBalCalTitle').textContent = 'Calendar — ' + r.fullname;
    el('prBalCalHero').innerHTML = '';
    el('prBalCalFoot').innerHTML = '';
    el('prBalCalGrid').innerHTML = '<div class="pr-empty" style="grid-column:1/-1;">Loading…</div>';
    el('prBalCal').classList.add('show');
    await balCalLoad(month);
  }

  async function balCalLoad(month, quiet) {
    if (!BAL_ROW) return;
    if (!quiet) el('prBalCalGrid').innerHTML = '<div class="pr-empty" style="grid-column:1/-1;">Loading…</div>';
    try {
      const res = await fetch('/hr/payroll/balance-calendar?user_id=' + BAL_ROW.user_id +
        '&month=' + encodeURIComponent(month), { headers: { 'Accept': 'application/json' } });
      const j = await res.json();
      if (!j.success) throw new Error(j.message || 'Failed');
      BAL_CAL = j;
      BAL_CAL_MONTH = j.month;
      if (j.can_void_payment !== undefined) CAN_VOID_PAY = !!j.can_void_payment;
      if (j.today) TODAY = j.today;
      balCalRender();
    } catch (e) {
      el('prBalCalGrid').innerHTML = '<div class="pr-empty" style="grid-column:1/-1;">Could not load: ' + esc(e.message || e) + '</div>';
    }
  }

  function balCalRender() {
    const j = BAL_CAL;
    if (!j) return;
    const b = j.balance || {}, s = j.summary || {};

    el('prBalCalMonth').textContent = j.month_label;
    el('prBalCalPrev').style.opacity = j.has_prev ? '1' : '.35';
    el('prBalCalPrev').style.pointerEvents = j.has_prev ? '' : 'none';

    el('prBalCalHero').innerHTML =
      '<div class="pr-bal-hero ' + (b.direction || 'settled') + '" style="margin-top:0;">' +
        '<div>' +
          '<div class="pr-bal-lbl">' + esc(b.label || 'All settled') + ' — today</div>' +
          '<div class="pr-bal-amt">' + fmt(b.amount || 0) + '</div>' +
          '<div class="pr-bal-note">' + fmt(b.day_rate) + ' per day · running since ' + esc(b.start_label || '') + '</div>' +
        '</div>' +
      '</div>';

    el('prBalCalDows').innerHTML = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']
      .map(d => '<div class="pr-cal-dow">' + d + '</div>').join('');

    const days = j.days || [];
    let cells = '';
    if (days.length) {
      const lead = (days[0].dow || 1) - 1;   // dow: 1 = Monday
      for (let k = 0; k < lead; k++) cells += '<div class="pr-cal-day empty"></div>';
    }
    cells += days.map((d, di) => balDayCell(d, di)).join('');
    el('prBalCalGrid').innerHTML = cells;

    const chip = (label, value, extra) =>
      '<span class="pr-bal-stat">' + label + ' <b>' + value + '</b>' + (extra ? ' · ' + extra : '') + '</span>';
    // A month before the account existed says so, rather than dating today's balance to it.
    el('prBalCalFoot').innerHTML = s.before_start
      ? '<span class="pr-bal-stat">This month is before the balance started on <b>' + esc(b.start_label || '') + '</b></span>'
      : (chip('Earned', fmt(s.earned || 0), (s.counted || 0) + ' day' + ((s.counted === 1) ? '' : 's')) +
         chip('Paid', fmt(s.paid || 0)) +
         ((s.crossed || 0) > 0 ? chip('Not counted', s.crossed + ' day' + (s.crossed === 1 ? '' : 's')) : '') +
         chip('Balance ' + (s.is_current ? 'today' : 'at ' + esc(niceDate(s.closing_date))),
              esc(s.label || '') + ' ' + fmt(s.amount || 0)));
    el('prBalCalHint').style.display = s.before_start ? 'none' : '';

    // wire the cells
    days.forEach((d, di) => {
      const cell = document.querySelector('[data-balday="' + di + '"]');
      if (cell && d.in_account) cell.onclick = () => balToggleDay(di);
      const pay = document.querySelector('[data-balpayday="' + di + '"]');
      if (pay) pay.onclick = (ev) => { ev.stopPropagation(); showBalPayments(d); };
    });
  }

  function balDayCell(d, di) {
    let cls = 'pr-cal-day';
    if (!d.in_account) { cls += ' pre'; }
    else {
      if (d.crossed) cls += ' crossed';
      if (d.future) cls += ' future';
      if (!d.crossed && !d.future) cls += ' counted';
    }
    if (d.is_today) cls += ' today';

    const x = d.crossed ? '<span class="pr-cal-x">✕</span>' : '';
    const pay = (d.paid_total > 0)
      ? '<span class="pr-cal-pay" data-balpayday="' + di + '">' + balShort(d.paid_total) + '</span>'
      : '';
    let run = '';
    if (d.in_account && d.running !== null && d.running !== undefined && !d.future) {
      const w = balWords(d.running);
      run = '<span class="pr-cal-run ' + w.dir + '">' + balShort(w.amount) + '</span>';
    }
    return '<div class="' + cls + '" data-balday="' + di + '" title="' + esc(balDayTitle(d)) + '">' +
      x + '<span class="n">' + d.day + '</span>' + pay + run + '</div>';
  }

  // The whole story of one day, for the hover tooltip: what it earned, what was
  // paid on it, and the balance after it — the running account, day by day.
  function balDayTitle(d) {
    const dt = niceDate(d.date);
    if (!d.in_account) return dt + ' — before this balance started';
    const bits = [dt];
    if (d.crossed) bits.push(d.future ? 'marked not counted (still to come)' : 'not counted — earns nothing');
    else if (d.future) bits.push('still to come');
    else bits.push('counted · earns ' + fmt(d.rate));
    if (d.paid_total > 0) bits.push('paid ' + fmt(d.paid_total));
    if (!d.future && d.running !== null && d.running !== undefined) {
      const w = balWords(d.running);
      bits.push('balance after: ' + (w.dir === 'settled' ? 'all settled' : w.label.toLowerCase() + ' ' + fmt(w.amount)));
    }
    return bits.join(' · ');
  }

  // Optimistic flip for instant feedback, then a silent reload so the running
  // balances and totals are the server's answer, never the browser's guess.
  async function balToggleDay(di) {
    const d = ((BAL_CAL || {}).days || [])[di];
    if (!d || !d.in_account || !BAL_ROW) return;
    const cell = document.querySelector('[data-balday="' + di + '"]');
    if (cell) { cell.classList.toggle('crossed'); cell.classList.toggle('counted'); }
    try {
      const res = await fetch('/hr/payroll/balance-absence', {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ user_id: BAL_ROW.user_id, date: d.date })
      });
      const j = await res.json();
      if (!j.success) throw new Error(j.message || 'Failed');
      BAL_DIRTY = true;
      await balCalLoad(BAL_CAL_MONTH, true);
    } catch (e) {
      alert('Could not update that day: ' + (e.message || e));
      balCalLoad(BAL_CAL_MONTH, true);
    }
  }

  el('prBalCalPrev').onclick = () => { if (BAL_CAL) balCalLoad(BAL_CAL.prev_month); };
  el('prBalCalNext').onclick = () => { if (BAL_CAL) balCalLoad(BAL_CAL.next_month); };
  function closeBalCal() {
    el('prBalCal').classList.remove('show');
    if (BAL_DIRTY) { BAL_DIRTY = false; customLoad(); }
  }
  el('prBalCalX').onclick = closeBalCal;
  el('prBalCalClose').onclick = closeBalCal;
  el('prBalCal').onclick = (ev) => { if (ev.target === el('prBalCal')) closeBalCal(); };
  el('prBalCalPay').onclick = () => {
    if (BAL_ROW) openBalPay(BAL_ROW, (BAL_CAL || {}).balance);
  };

  // ---- payment receipts for one day ----
  function showBalPayments(d) {
    const list = d.payments || [];
    if (!list.length) return;
    const line = (label, value, muted) =>
      '<div class="pr-daterow"><span class="dt" style="font-weight:400;color:#6b7280;">' + label + '</span>' +
      '<span class="dt"' + (muted ? ' style="color:#cbd5e1;"' : '') + '>' + value + '</span></div>';

    let html = '';
    list.forEach((p, pi) => {
      if (pi > 0) html += '<div style="height:10px;background:#f9fafb;border-top:1px solid #eef0f2;border-bottom:1px solid #eef0f2;"></div>';
      html += line('Amount', '<b>' + fmt(p.amount) + '</b>');
      html += line('Paid on', esc(niceDate(p.date)));
      html += line('Paid from', paidFromLabel(p) || '—');
      if (p.paid_by_name) html += line('Recorded by', esc(p.paid_by_name));
      if (p.notes) html += line('Note', esc(p.notes));
      if (p.ledger_id) html += line('Ledger entry', '#' + p.ledger_id, true);
      if (CAN_VOID_PAY) {
        html += '<div style="padding:10px 14px;">' +
          '<button type="button" class="pr-la-btn pr-la-cut" data-balvoid="' + pi + '">🗑 Void this payment</button>' +
          '<div style="font-size:11px;color:#9ca3af;margin-top:5px;line-height:1.5;">Returns the money to where it came from and removes it from the balance.</div>' +
          '</div>';
      }
    });

    sheetWide(false);
    el('prSheetTitle').textContent = 'Payment — ' + niceDate(d.date);
    el('prSheetBody').innerHTML = html;
    el('prSheet').classList.add('show');
    list.forEach((p, pi) => {
      const btn = document.querySelector('[data-balvoid="' + pi + '"]');
      if (btn) btn.onclick = () => voidBalPayment(p);
    });
  }

  async function voidBalPayment(p) {
    const reason = prompt('Why is this ' + fmt(p.amount) + ' payment being voided?\n\nThe money goes back to where it came from and the balance is recalculated.');
    if (reason === null) return;
    if (String(reason).trim().length < 3) { alert('Please type a short reason.'); return; }
    try {
      const res = await fetch('/hr/payroll/balance-void-payment', {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ payment_id: p.id, reason: reason })
      });
      const j = await res.json();
      if (!j.success) throw new Error(j.message || 'Failed');
      el('prSheet').classList.remove('show');
      alert(j.message || 'Payment voided.');
      BAL_DIRTY = true;
      if (el('prBalCal').classList.contains('show')) { balCalLoad(BAL_CAL_MONTH, true); }
      else { customLoad(); BAL_DIRTY = false; }
    } catch (e) {
      alert('Could not void: ' + (e.message || e));
    }
  }

  // ---- record a payment ----
  let BAL_PAY_ROW = null, BAL_PAY_BAL = null;
  function openBalPay(r, balOverride) {
    BAL_PAY_ROW = r;
    BAL_PAY_BAL = balOverride || r.balance || null;
    const b = BAL_PAY_BAL || {};
    el('prBalPayTitle').textContent = 'Record payment — ' + r.fullname;
    el('prBalPayNow').innerHTML = 'Balance now: <b>' + esc(b.label || '—') + ' ' + fmt(b.amount || 0) + '</b>' +
      (b.day_rate ? ' · ' + fmt(b.day_rate) + ' per day' : '');
    el('prBalPayAmt').value = '';
    el('prBalPayDate').value = TODAY;
    el('prBalPayDate').max = TODAY;
    if (b.track_start) el('prBalPayDate').min = b.track_start;
    el('prBalPayNote').value = '';
    el('prBalPayAfter').innerHTML = '<div class="row"><span>Enter the amount to see the new balance</span><span></span></div>';
    document.querySelectorAll('[data-balfund]').forEach(x => x.classList.remove('active'));
    document.querySelector('[data-balfund="cash"]').classList.add('active');
    document.querySelector('input[name=prBalFund][value=cash]').checked = true;
    el('prBalBankSel').disabled = true;
    el('prBalPayModal').classList.add('show');
    setTimeout(() => el('prBalPayAmt').focus(), 50);
  }

  function balPayPreview() {
    const amt = Number(el('prBalPayAmt').value) || 0;
    if (!amt || !BAL_PAY_BAL) {
      el('prBalPayAfter').innerHTML = '<div class="row"><span>Enter the amount to see the new balance</span><span></span></div>';
      return;
    }
    const now = balWords(Number(BAL_PAY_BAL.balance) || 0);
    const after = balWords((Number(BAL_PAY_BAL.balance) || 0) + amt);
    el('prBalPayAfter').innerHTML =
      '<div class="row"><span>Balance now</span><span>' + esc(now.label) + ' ' + fmt(now.amount) + '</span></div>' +
      '<div class="row"><span>This payment</span><span>+ ' + fmt(amt) + '</span></div>' +
      '<div class="row tot"><span>Balance after</span><span style="color:' +
        (after.dir === 'to_pay' ? '#b91c1c' : (after.dir === 'paid_ahead' ? '#047857' : '#6b7280')) + ';">' +
        esc(after.label) + ' ' + fmt(after.amount) + '</span></div>' +
      '<div class="ref">Days keep earning after this, so the balance moves again tomorrow.</div>';
  }
  el('prBalPayAmt').oninput = balPayPreview;

  document.querySelectorAll('[data-balfund]').forEach(f => {
    f.onclick = (ev) => {
      if (ev.target.closest('select')) return;
      document.querySelectorAll('[data-balfund]').forEach(x => x.classList.remove('active'));
      f.classList.add('active');
      f.querySelector('input[type=radio]').checked = true;
      const dis = (f.getAttribute('data-balfund') !== 'online');
      if (el('prBalBankSel').disabled !== dis) el('prBalBankSel').disabled = dis;
    };
  });
  el('prBalPayCancel').onclick = () => el('prBalPayModal').classList.remove('show');
  el('prBalPayModal').onclick = (ev) => { if (ev.target === el('prBalPayModal')) el('prBalPayModal').classList.remove('show'); };
  el('prBalPayConfirm').onclick = async () => {
    if (!BAL_PAY_ROW) return;
    const amount = Number(el('prBalPayAmt').value);
    if (!amount || amount < 1) { alert('Enter the amount paid.'); return; }
    const date = el('prBalPayDate').value;
    if (!date) { alert('Pick the date the money was paid.'); return; }
    const fundType = document.querySelector('input[name=prBalFund]:checked').value;
    const bankId = el('prBalBankSel').value;
    if (fundType === 'online' && !bankId) { alert('Choose the bank.'); return; }
    el('prBalPayConfirm').disabled = true; el('prBalPayConfirm').textContent = 'Recording…';
    try {
      const res = await fetch('/hr/payroll/balance-payment', {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({
          user_id: BAL_PAY_ROW.user_id, amount: amount, date: date,
          funding: fundType, bank_id: fundType === 'online' ? Number(bankId) : null,
          note: el('prBalPayNote').value || null
        })
      });
      const j = await res.json();
      if (!j.success) throw new Error(j.message || 'Failed');
      el('prBalPayModal').classList.remove('show');
      if (el('prBalCal').classList.contains('show')) {
        BAL_DIRTY = true;
        balCalLoad(BAL_CAL_MONTH, true);
      } else {
        customLoad();
      }
    } catch (e) {
      alert('Could not record the payment: ' + (e.message || e));
    } finally {
      el('prBalPayConfirm').disabled = false; el('prBalPayConfirm').textContent = 'Record payment';
    }
  };

  // ---- start a running balance ----
  let BAL_EN_ROW = null, BAL_EN_DIR = 'zero';
  function openBalEnable(r) {
    if (!r.configured) { alert('Set this employee\'s rate first (click the rate).'); return; }
    BAL_EN_ROW = r;
    el('prBalEnTitle').textContent = 'Start a running balance — ' + r.fullname;
    // Default to the first day that isn't already paid as a period.
    el('prBalEnStart').value = r.suggested_start || TODAY;
    el('prBalEnStart').max = TODAY;
    // The first free day, not the last paid one — otherwise the picker offers a date
    // the server must reject (a day can't be both period-paid and balance-accrued).
    if (r.last_period_end && r.suggested_start) el('prBalEnStart').min = r.suggested_start;
    el('prBalEnStartHint').textContent = r.last_period_end
      ? ('Everything up to ' + niceDate(r.last_period_end) + ' is already paid as periods, so the balance must start after that.')
      : 'Days before this date are never counted.';

    BAL_EN_DIR = r.advance_total > 0 ? 'ahead' : 'zero';
    setRadioGroup('prBalEnDir', 'baldir', BAL_EN_DIR);
    el('prBalEnAmt').value = r.advance_total > 0 ? Math.round(r.advance_total) : '';
    balEnDirUI();
    el('prBalEnAdvNote').innerHTML = r.advance_total > 0
      ? '<div class="pr-bal-warn">He is holding <b>' + fmt(r.advance_total) + '</b> in open advances. ' +
        'Starting the balance closes those advances off. Keep <b>Paid ahead</b> with that amount if the money should count ' +
        'against his coming days — or choose <b>Nothing owed</b> to write it off and start clean.</div>'
      : '';
    el('prBalEnableModal').classList.add('show');
  }
  function balEnDirUI() {
    const show = BAL_EN_DIR !== 'zero';
    el('prBalEnAmtWrap').style.display = show ? 'block' : 'none';
    el('prBalEnAmtLbl').textContent = BAL_EN_DIR === 'ahead'
      ? 'How much is he holding?' : 'How much do we still owe him?';
  }
  document.querySelectorAll('#prBalEnDir [data-baldir]').forEach(x => x.onclick = () => {
    BAL_EN_DIR = x.getAttribute('data-baldir');
    setRadioGroup('prBalEnDir', 'baldir', BAL_EN_DIR);
    balEnDirUI();
  });
  el('prBalEnCancel').onclick = () => el('prBalEnableModal').classList.remove('show');
  el('prBalEnableModal').onclick = (ev) => { if (ev.target === el('prBalEnableModal')) el('prBalEnableModal').classList.remove('show'); };
  el('prBalEnSave').onclick = async () => {
    if (!BAL_EN_ROW) return;
    const start = el('prBalEnStart').value;
    if (!start) { alert('Pick the date to start from.'); return; }
    let opening = 0;
    if (BAL_EN_DIR !== 'zero') {
      const amt = Number(el('prBalEnAmt').value);
      if (!amt || amt <= 0) { alert('Enter the amount, or choose "Nothing owed".'); return; }
      opening = BAL_EN_DIR === 'ahead' ? amt : -amt;
    }
    el('prBalEnSave').disabled = true; el('prBalEnSave').textContent = 'Starting…';
    try {
      const res = await fetch('/hr/payroll/balance-enable', {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ user_id: BAL_EN_ROW.user_id, start_date: start, opening: opening })
      });
      const j = await res.json();
      if (!j.success) throw new Error(j.message || 'Failed');
      el('prBalEnableModal').classList.remove('show');
      alert(j.message + (j.advances_converted ? ('\n' + j.advances_converted + ' open advance(s) closed off.') : ''));
      customLoad();
    } catch (e) {
      alert('Could not start: ' + (e.message || e));
    } finally {
      el('prBalEnSave').disabled = false; el('prBalEnSave').textContent = 'Start balance';
    }
  };

  // ---- change the rate from a date ----
  let BAL_RATE_ROW = null;
  function openBalRate(r) {
    BAL_RATE_ROW = r;
    const b = r.balance || {};
    const per = r.rate_type === 'daily' ? 'day' : 'month';
    el('prBalRateTitle').textContent = 'Change rate — ' + r.fullname;
    el('prBalRateLbl').textContent = 'New rate per ' + per;
    el('prBalRateNow').innerHTML = 'Now <b>' + fmt(r.base_rate) + '</b> per ' + per +
      (b.start_label ? ' · balance running since ' + esc(b.start_label) : '');
    el('prBalRateAmt').value = '';
    el('prBalRateDate').value = TODAY;
    el('prBalRateDate').max = TODAY;
    if (b.track_start) el('prBalRateDate').min = b.track_start;
    el('prBalRateModal').classList.add('show');
    setTimeout(() => el('prBalRateAmt').focus(), 50);
  }
  el('prBalRateCancel').onclick = () => el('prBalRateModal').classList.remove('show');
  el('prBalRateModal').onclick = (ev) => { if (ev.target === el('prBalRateModal')) el('prBalRateModal').classList.remove('show'); };
  el('prBalRateSave').onclick = async () => {
    if (!BAL_RATE_ROW) return;
    const rate = Number(el('prBalRateAmt').value);
    if (!rate || rate <= 0) { alert('Enter the new rate.'); return; }
    const date = el('prBalRateDate').value;
    if (!date) { alert('Pick the date the new rate starts.'); return; }
    el('prBalRateSave').disabled = true; el('prBalRateSave').textContent = 'Saving…';
    try {
      const res = await fetch('/hr/payroll/balance-rate', {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ user_id: BAL_RATE_ROW.user_id, rate: rate, effective_date: date })
      });
      const j = await res.json();
      if (!j.success) throw new Error(j.message || 'Failed');
      el('prBalRateModal').classList.remove('show');
      if (el('prBalCal').classList.contains('show')) {
        BAL_DIRTY = true;
        balCalLoad(BAL_CAL_MONTH, true);
      } else {
        customLoad();
      }
    } catch (e) {
      alert('Could not change the rate: ' + (e.message || e));
    } finally {
      el('prBalRateSave').disabled = false; el('prBalRateSave').textContent = 'Save rate';
    }
  };

  // auto-load current month
  // Land on the Employee tab (owner ruling): opening Payroll should not put every
  // salary on screen. The grid is one click away on Monthly.
  empLoad();
})();
</script>
@endsection
