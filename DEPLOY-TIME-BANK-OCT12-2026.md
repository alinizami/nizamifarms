# ⏱ DEPLOY — Time bank: late + extra as ONE balance (Oct-12 2026)

Plan: `../TIME-BANK-LATE-OT-PLAN-OCT11-2026.md`. Built + proven on the local replica. **Not on prod yet.**

## Order (do not change)
1. **SQL first** — run `database/migrations/time_bank_oct2026.sql` on PROD (2 × `CREATE TABLE IF NOT EXISTS`,
   no existing table touched, safe to run twice). Nothing else to seed: the cut-over is fixed in code
   (**1 September 2026 for everyone**; a paid salary is never a leave decision).
2. **Upload web files** (below) → **`/api/public/xclean`** (routes + views changed — required).
3. **Build + install the APK** (mobile files below). Web first: old APKs keep working (they get the same numbers;
   an old manager phone can only "Confirm suggested" on a late & extra item — every other choice is on the web or the new APK).

## Web files (nizamifarms/)
NEW
- `app/Services/HR/TimeBankService.php` — the engine (one read `month()`, one write `decide()`)
- `app/Http/Controllers/Concerns/TimeBankEndpoints.php` — shared web + phone endpoints
- `database/migrations/time_bank_oct2026.sql`
CHANGED
- `app/Services/HR/PayrollService.php` — payroll row reads the bank; Pay writes no old leave rows for bank months; cut paid / returned; salary-paid push
- `app/Services/HR/OvertimeService.php` — `bonusLeavesFor` answers from the bank (every board)
- `app/Services/HR/DayReviewService.php` — "month already decided → counts next month" warning
- `app/Services/HR/SalaryCalculationService.php` — NEW slips take the decided bank cut (old slips untouched)
- `app/Services/FirebaseService.php` — 2 pushes (decided / salary paid)
- `app/Http/Controllers/HR/PayrollController.php`, `app/Http/Controllers/API/PayrollController.php`
- `app/Http/Controllers/CRM/AttendanceController.php` — month report chip + settings field
- `app/Http/Controllers/API/RiderController.php` ⚠ **shared with the Expense-types round** (also uncommitted) — upload together
- `routes/web.php`, `routes/api.php` ⚠ **shared with the Expense-types round** — upload together
- `resources/views/partials/leave-actions-panel.blade.php`, `resources/views/pages/payroll/index.blade.php`,
  `resources/views/pages/attendance/index.blade.php`

## Mobile files (NizamiFarmsMobile/)
NEW `src/components/TimeBankCard.js`, `src/components/MyTimeBank.js`
CHANGED `src/screens/PayrollScreen.js`, `SalaryScreen.js`, `RequestsAndSalaryScreen.js`, `AttendanceScreen.js`,
`AttendanceReportsScreen.js`, `EmployeeAttendanceDetailScreen.js`, `src/services/notificationService.js`

## After upload — check on prod
- Attendance → Settings → Overtime: "Extra covers late at" shows **0.5**.
- Payroll → September: EVERY monthly employee has a time-bank card. Arslan / Asim / Waseem (September overtime already
  given by the old panel) show "Extra already settled the old way: +N leaves given (…)" on the card and only their
  late is in the bank; the attendance boards keep showing their old "+N bonus days".
- August and earlier are before the cut-over: nothing from them is counted except overtime the old carry engine still
  owed (e.g. Haider 7h 45m). Kanan's un-decided August (28h 28m extra, 10h 31m late) and Danish's (15h 23m extra) are
  NOT pulled in — add them by hand with "Adjust time bank" (web Employee tab / card, or the phone) if wanted.
- Open one card, untick "Use extra to cover late" → the numbers change (server recalculates). Do NOT confirm unless deciding for real.

## Proof (local replica)
- `php test_time_bank_oct12.php` — engine + pay path + the independent review's 7 findings + the 1-Sep cut-over +
  "approved/paid numbers untouched" + "overtime verdicts (adjusted/waived) are what the bank counts", rolled back: **69/69**
  (⚠ run only with dev pushes disabled — the rollback cannot un-send a push)
- `php test_time_bank_parity_oct12.php 2026-09` / `2026-10` — same late minutes + same chip on payroll, web attendance,
  phone report, rider attendance, rider pay screen: **77/77** and **110/110**
- Regression: overtime carry 30/30, day review 70/70, shortfall 70/70 (pinned to Aug — Sep is a bank month now),
  slip split 11/11, late engine drift PASSED. Known pre-existing: `test_absence_decisions` 1 fail;
  `PROOF-LATE-WAIVE-*` 3 fails = replica data drift (more Oct waives than when written).
- jest: 975/975 tests (new `timeBankOct12.test.js` 3/3); `App.test.tsx` suite fails to load (gesture-handler native module) — pre-existing.
- Browser walk on local as Taimur: payroll grid chips, row → card, cover toggle, decide, decided chip, undo; attendance month chips.
- ⚠ NOT device-walked on a phone.
