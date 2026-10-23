# Deploy — Absence decisions visible (Oct-20 2026)

**No SQL.** Read-only display of data that already exists (`t_hr_absence_decision`).
Web FIRST, then the APK (the phone reads a new key from the web endpoint).

## 1. Web upload (4 files)
1. `app/Http/Controllers/CRM/AttendanceController.php`
2. `app/Http/Controllers/API/PayrollController.php` — `leaveHistory` also returns `absence_history` + `absence_owed` (additive; installed APKs ignore them)
3. `resources/views/pages/attendance/index.blade.php`
4. `resources/views/pages/payroll/index.blade.php`

Then run **/api/public/xclean** (Blade views changed).

At upload time these four files had no other uncommitted changes — this round is the whole diff.

## 2. APK (next build — 4 files + 2 tests)
### Rider's OWN view (Attendance + Salary) — reads `/rider/payroll/leave-history` (self is allowed)
- `NizamiFarmsMobile/src/utils/absenceHistory.js` — NEW. Roman-Urdu words shared by both screens.
- `NizamiFarmsMobile/src/screens/AttendanceScreen.js` — under "This year absent": "13 din baqi (parked) —
  overtime se poore honge ›" → sheet "Meri ghair-hazri ka hisaab" (month · days · parked/maaf/kati · by ·
  date · overtime se poore · baqi). Absent-dates list labels each date with its month's decision.
  Fire-and-forget fetch: an older server just hides the line.
- `NizamiFarmsMobile/src/screens/SalaryScreen.js` — parked month: "12 din parked — is mahine tankhwah se
  nahi kate, overtime ki chhutti se poore honge"; excused: "N din maaf"; "Kul baqi ghair-hazri: 13 din ›".
- Test: `__tests__/riderAbsenceHistory.test.js` (9 tests; Salary screen rendered; Attendance screen is
  too heavy to render — its lines all come from the tested util).
- ⚠ `API/RiderController.php` deliberately NOT touched (it carries another round's unshipped diff).

### Manager Payroll screen
- `NizamiFarmsMobile/src/screens/PayrollScreen.js` — Payroll card: absent chip gets "›", new amber
  "N owed ›" chip; both open "Absences — <name>" (month, decision, by/when, paid off by overtime /
  leave / charged / excused, still owed). An un-updated server → "server is not updated yet".
- Test: `__tests__/payrollAbsenceHistory.test.js` (4 tests, real screen render). Not device-walked.
- ⚠ The mobile working tree carries other unbuilt rounds (recipe families, box count, split invoice,
  tag alerts…) — this file rides whichever APK is built next.

## What changes
- **Attendance → Month → Absent (yr):** under the count, "N parked · owed ›" (or "decisions ›" when
  nothing is owed). Opens the month-by-month decisions: parked / excused / deducted, by whom, when,
  how many days overtime / own leave / a charge / an excuse have settled, and what is still owed.
  Link at the bottom opens the leave-adjustment history (overtime earned, covers).
- **Absent (yr) date list:** each date says its month's decision ("parked · by Shabib · 1 Oct").
  A date after a mid-month decision says "after the decision · not decided". Undecided months stay blank.
- **Payroll → Employee tab → Absences card:** new "History" section, one line per decided month.

## Check after upload
Attendance → Month → Asim Tahir: Absent (yr) shows "13 parked · owed ›" (prod numbers may differ
from dev). Click it → Sep 12 by Shabib 1 Oct, Aug 2 by Taimur 5 Sep with 1 paid off by overtime.
