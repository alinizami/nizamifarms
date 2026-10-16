# ⏱ DEPLOY — Time bank TILES round (Oct-15 2026) — web, then APK

Plan: `../TIME-BANK-TILES-PLAN-OCT14-2026.md` (rulings T1–T5). Mockup: https://claude.ai/artifact/LpeJyuTBDKGRye2XXfkhNQ
**This ONE checklist also ships the clarity round** (`DEPLOY-TIME-BANK-CLARITY-OCT13-2026.md`) — its files are still
uncommitted, so they go up together. **No SQL.**

> ⚠ The working tree also holds OTHER sessions' work (bundled products, invoice-edit guards, customers/orders/products
> files, `CustomersScreen.js` on mobile). They are NOT part of this round — upload only the files listed here.

## 1. Web (nizamifarms/) — upload these 9 files
1. `app/Services/HR/TimeBankService.php` — the engine: half rate always, tiles, cells, Confirm sentences, check ticks
2. `app/Services/HR/LeavePolicyService.php` — leave-history label ("Time bank Sep 2026 · taken for late")
3. `app/Services/HR/PayrollService.php` — cut receipt line on the paid salary; Leave-actions summary counts
4. `app/Http/Controllers/CRM/AttendanceController.php` — `cells` in the month report; leave-history labels
5. `app/Http/Controllers/API/RiderController.php` — `cells` for the phone month report + `time_bank_cells` in employee details (2 small hunks)
6. `resources/views/partials/leave-actions-panel.blade.php` — the tile card, table cells, bank boxes (ONE renderer)
7. `resources/views/pages/payroll/index.blade.php` — grid cells, Employee tab card
8. `resources/views/pages/attendance/index.blade.php` — Month table cells
9. `resources/views/pages/attendance/reports.blade.php` — Reports table: Late cell + new "Extra (overtime)" column

Then **`/api/public/xclean`** (views changed — required).
Not uploaded (local proof scripts): `test_time_bank_oct12.php`, `test_time_bank_parity_oct12.php`.

The installed APK keeps working after step 1: the "use extra to cover late" tick disappears by itself (the server no
longer offers it) and it still gets its sentences.

## 2. APK (NizamiFarmsMobile/) — build AFTER the web files are live
- `src/components/TimeBankCard.js` — tile card (LATE · EXTRA · RESULT), "Confirm will" = server sentence, unchecked-days
  warning, disabled-choice reasons, every state says what to do next; `TimeBankCells` (shared Late / Extra cells);
  bank boxes in the history.
- `src/components/MyTimeBank.js` — rider: LATE / OVERTIME tiles with ✓ / ⚠ checked line, boxes for the balance.
- `src/screens/PayrollScreen.js` — row shows the Late / Extra cells (tap → card); leave history uses the server label.
- `src/screens/AttendanceReportsScreen.js` — same cells per employee (tap → card); leave labels.
- `src/screens/EmployeeAttendanceDetailScreen.js` — same cells under the summary (tap → card); leave labels.
- `src/screens/AttendanceScreen.js` — rider leave-history labels.
- jest: `__tests__/timeBankOct12.test.js` 10/10; full suite 1001 pass — the one failing suite is the old `App.test.tsx`
  native-module error. **Not device-walked.**

## 3. What the manager sees
- **Word: "overtime", never "extra"** (owner, Oct-15) — tiles LATE · OVERTIME · RESULT, "Overtime +2 days", "wiped by overtime", "From overtime", "Late & overtime bank", Settings "Overtime covers late at". Internal keys (`cells.extra`, `tiles.extra`) are unchanged so the installed APK keeps working.
- Every card: three tiles — **LATE** (minutes, where they came from, ✓ checked by whom) · **OVERTIME** (same) · **RESULT**
  ("+2 leaves" / "−1 leave" / "0 leaves", how extra wiped late at ½ rate, what moves to next month).
- Only whole days ask a question: "Late −1 day: Take leave · Cut Rs … · Move to Oct" / "Extra +2 days: Give +2 leaves ·
  Move to Oct · Skip". The suggestion is pre-selected. "Confirm will: Give 2 leaves · move 2h 28m extra to Oct".
- Never stuck: days not checked → "N days not checked yet — they count in full. Confirm anyway?"; no leave left →
  "Take leave" greyed with the reason; under a day → "nothing to decide, moves to Oct by itself"; running month →
  "opens 1 Nov"; locked → names the reason and points to "Adjust time bank".
- Tables (Attendance › Month, Attendance › Reports, Payroll grid, phone Payroll / Reports / Employee detail): the
  same Late / Extra cells — minutes, ✓ checked, what happened ("5h 31m wiped by extra · 8h 34m → Oct", "2 leaves
  given"), who decided; "Decide September ›" when it is waiting. Tapping opens the card.
- Leave history everywhere: "+2 · Time bank Sep 2026 · given for extra · by Taimur".
- Undo now records the manager (not the employee) on the zeroed leave row.

### Independent review pass (Oct-15) — fixed
- Folded month (counted inside a later decision): kept its table cells ("in Oct decision"), names the month in words and
  has **Open October ›** (web + phone) — before: raw "2026-10", blank cells, no button.
- Lock reason now says the real way out: "October is already decided — **undo** Oct first" ("change" never unlocked it);
  paid / returned locks say "use Adjust time bank"; undo is refused for a month whose cut went back to the bank (no double count).
- RESULT tile follows the buttons (server `result_map`: "Rs 2,400 cut", "+2 leaves · Rs 2,400 cut", Skip → "0 leaves · nothing left over").
- Skip's Confirm sentence no longer says "move … extra to Oct" (Skip drops it all). One verb everywhere: "Move".
- A waive bigger than the late is clamped by the server and the card takes the server's number (no Confirm that then fails).
- Empty month: "Nothing to decide — nothing left over." (not "the leftover moves"). Hint: "only 0.5 leave left — cut salary or move to Oct"
  (or just "move to Oct" when no salary is set). "Take 1 leave + move 1 day to Oct".
- Phone: "see the days ›" / amber ⚠ open the person's day list (Payroll, Reports); a refused stale card re-picks from the
  fresh suggestion (before it could keep a now-disabled "Take leave").
- Payroll grid: unsaved grid edits are asked about before any card save / undo / Adjust (the old chip did this; the new cells lost it).
- Old-rule month tiles say where the worked minutes went ("9h 04m in Sep — settled the old way").
- Owner (Oct-15): the rate stays EDITABLE in Attendance › Settings and every number follows it — proven locally with 0.75 (wiped late, labels, cells, sentences all changed; decided months keep the rate stored on their row). Settings help text updated.
- Table chip now sits in the cell with the whole days and says what they are: "+2 leaves — Decide September ›"; the old-rule note is its own muted line.

## 4. Proof (local replica)
- `php test_time_bank_oct12.php` **109/109** (§7: undo names the manager; rider check state; phone employee-detail cells;
  review fixes: Skip sentence, result_map, waive clamp, clear note, folded cells, undo refused after a returned cut).
- `php test_time_bank_parity_oct12.php 2026-09` and `2026-10` **180/180** each — now also: payroll row cells = web
  month cells = phone month cells = engine.
- Regressions green: `test_overtime_carry` 30, `test_day_review` 70, `test_payroll_shortfall` 70,
  `test_slip_late_split` 11, `test_late_engine_drift` no-op.
- Browser walk as Taimur (local): Attendance › Month Sep cells, Attendance › Reports cells + card from "Decide
  September ›", Payroll Monthly grid, Payroll Employee tab card (side column now 2 + 1 tiles).

## 5. After upload — expect this on prod
With half rate as the rule, most September cards become **"under a day — nothing to decide"** (e.g. Haider, Farooq,
Kanan, Rajab, Arslan on the replica); only people with whole days left (Danish +2 on the replica) need a decision.
