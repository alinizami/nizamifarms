# ⏱ DEPLOY — Time bank clarity round (Oct-13 2026) — WEB ONLY

> ⚠ **SUPERSEDED (Oct-15):** use `DEPLOY-TIME-BANK-TILES-OCT15-2026.md` — it ships these files together with the tiles
> round (same files, newer content). Kept for the history of what changed.

Follows the time-bank round already live (web `f097708`, APK `d4e6d0f`). **No SQL. No APK.**

## Upload (nizamifarms/)
1. `app/Services/HR/TimeBankService.php`
2. `app/Http/Controllers/CRM/AttendanceController.php` (leave-history labels only)
3. `resources/views/partials/leave-actions-panel.blade.php`
4. `resources/views/pages/payroll/index.blade.php`
5. `resources/views/pages/attendance/index.blade.php`

Then **`/api/public/xclean`** (views changed — required).
(`test_time_bank_parity_oct12.php` also changed — a local proof script, do not upload.)

## What changes
- **Card (web; the installed phone gets the text automatically):**
  - Facts in one format: "September: late 14h 05m · extra 3h 17m" / "Carried in: extra 7h 45m (from Aug)".
  - A card with no whole day says so: "… = 8h 34m late → Oct · nothing to take from leave this month".
  - The rate is named where it is used: "rate 0.5 (Attendance › Settings)".
- **Card (web only for now):** "Confirm will: Late: take 1 leave · Extra: give +2 leaves · Leftover: 2h 35m late + 4h 33m extra → Oct" above the button, updated on every click.
- **Time balance in one place (web):** top of the "Late & extra bank" box on the payroll Employee tab and in the card
  modal (payroll row chip / attendance chip):
  "In the bank: late 14h 05m · extra 11h 02m — Sep waiting for a decision" / "October so far: …" / "Since Sep: +2 extra
  leaves given · 1 leave taken for late · Rs 2,400 cut · 30m late waived".
- **Same words everywhere for leave:** "From extra (overtime)" and "Taken for late" (payroll Employee tab, attendance
  leave popup + chips, leave history). Settings label: "Extra covers late at (0.5 = half)".

## APK part (Oct-14) — build AFTER the 5 web files above are live
The phone pieces read `history.summary`, `time_bank.summary` and `statement.remainder`, which only the web files above
send. Upload web + /xclean first, then build/install the APK. (Older APKs keep working; a new APK against the old
server simply shows no summary strip and a "Confirm will" line without the leftover part.)

Mobile files (NizamiFarmsMobile/):
- `src/components/TimeBankCard.js` — "Confirm will: Late: … · Extra: … · Leftover: …" above Confirm; summary strip at the
  top of the bank history (card modal from a payroll row, employee-detail leave sheet).
- `src/components/MyTimeBank.js` — rider summary in Roman Urdu at the top of "Mera time hisaab" on the Attendance screen
  ("Bank mein abhi… / Is mahine abhi tak… / Sep se ab tak…"); the one-month pay screen stays month-only.
- `src/screens/AttendanceScreen.js`, `AttendanceReportsScreen.js`, `EmployeeAttendanceDetailScreen.js`, `PayrollScreen.js`
  — leave wording: "from extra (overtime)" / "taken for late" (same as web).
- jest: `__tests__/timeBankOct12.test.js` 6/6 (new: Confirm line from server words + follows taps; summary strip for
  manager and rider). Full suite 990 pass; the 3 that fail in the full run pass alone (119/119, timeouts under load);
  `App.test.tsx` native-module error is old. Not device-walked.

## Proof (local replica)
- `php test_time_bank_oct12.php` 69/69 · `php test_time_bank_parity_oct12.php 2026-10` **150/150** (adds: manager summary =
  engine, rider summary on both rider screens = engine, summary bank/own = the running card). Sep parity 110/110.
- Browser walk as Taimur: Haider card (nothing whole → note + "Confirm will: Leftover …"), Farooq card (cover off →
  "Late: take 1 leave · Extra: give +2 leaves · Leftover …", live on every click), Employee tab bank box + leave words.
