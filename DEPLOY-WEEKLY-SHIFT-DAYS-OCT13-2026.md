# 🔁 DEPLOY — Weekly days on a shift ("different on some days") (Oct-13 2026)

Plan: `../WEEKLY-SHIFT-PATTERN-PLAN-OCT12-2026.md` (owner rulings 12-Oct). Built + proven on the local replica. **Not on prod yet.**

Taimur can now set, once: *Haider — Manager Shift 11:00 at the office, except Mon / Wed / Thu LaCarne 09:30 at Orchard
Lacarne, Sat off — until changed.* It sits inside the existing popup (web) and sheet (phone): pick **New regular shift**
or **A date range**, then tap **🔁 Different on some days?**. One-day covers work exactly as before and still win their day.

## Order (do not change)
1. **SQL first** — `database/migrations/shift_weekly_days_oct2026.sql`, PART 1, 1b and 2 (three `ALTER TABLE … ADD COLUMN`:
   `day_pattern` on assignments, `is_temporary` on assignments + its backfill, `day_pattern` on approval requests).
   A second run of an ALTER errors 1060 "Duplicate column" = already applied, harmless; the backfill UPDATEs are re-runnable.
   PART 3 is the agreed clean-up of junk shift rows (run any time; it changes no answer — proven). Read its two PREVIEW
   selects first; on the replica they list 10 rows to delete (end before start) and 3 of Kanan's rows to close.
2. **Upload web files** (12, below) → **`/api/public/xclean`** (Blade views changed). No route changes.
3. **Build + install the APK** (mobile files below).

⭐ Web first is safe. Until the SQL runs, the PHP hides the weekly section and refuses weekly days. An old APK keeps
working: the server works out every day itself, and a regular change from an old phone **keeps** the person's weekly days
(it cannot show them, so it never removes them). The old phone just does not show the 🔁 marks.

## Web files (nizamifarms/)
NEW
- `app/Services/Ops/WeekdayPattern.php` — the one reader / checker / writer / wording of weekly days
- `database/migrations/shift_weekly_days_oct2026.sql`
CHANGED
- `app/Services/ShiftResolutionService.php` — applies the winning row's weekday (`shapeFromRow`), `locationFor()`
- `app/Models/Ops/UserShiftAssignmentModel.php` — `day_pattern` fillable
- `app/Http/Controllers/Ops/ShiftController.php` — assign / bulk / approval queue / carry-over / gate per shift type /
  template-delete guard / log note / push wording / Attendance popup summary
- `app/Http/Controllers/Ops/ShiftPlannerController.php` — grid cells `pattern_day`, person `regular` + `weekly`
- `app/Services/Ops/ShiftChangeRequestService.php` — request stores + shows + replays weekly days
- `app/Services/WhatsApp/Automation/Handlers/ShiftAssignedHandler.php` — WhatsApp wording (one line)
- `app/Http/Controllers/API/RiderController.php` ⚠ **also carries the Time-bank and Expense-types rounds (uncommitted)** —
  uploading it uploads their code too, which needs THEIR SQL first. Ship those rounds first or together.
- `resources/views/pages/shifts/planner.blade.php` — the 🔁 section, chips, row summary
- `resources/views/partials/shift-change-modal.blade.php` — Attendance popup says weekly days stay / pause
- `app/Services/Riders/WorkshopVisitService.php` — a workshop pin on a weekly day keeps that day off / working (review fix)

## Mobile files (NizamiFarmsMobile/)
NEW `src/utils/weekdayPattern.js`, `src/components/WeekdayRows.js`
CHANGED `src/screens/StoreShiftsScreen.js` (sheet + row), `src/screens/RiderShiftMonthScreen.js` (🔁 + place),
`src/screens/MyShiftScreen.js` ("Is hafte" day-by-day), `src/components/ShiftAckBanner.js` (weekly line, place in reminder)
Test: `__tests__/weeklyShiftDaysOct13.test.js`

## Rules as built (owner rulings)
- Weekly days ride on the shift row: cancel / end / replace / history / approvals / confirm all carry them.
- A weekday is **Same as above**, **Off**, or **another shift at another place** (a working day even if that shift
  type has the day off).
- A **one-day cover** still wins its day.
- Regular change **without** the section open (or from an older screen) → the person **keeps** their weekly days.
  Section open → exactly what is shown is saved; **Remove weekly days** removes them.
- Date range: weekly days apply only if the section is open; otherwise every day of the range is that shift.
- Approvals: same as today. The gate is asked for every shift type involved; the request carries the weekly days and
  the approver sees them; approving replays them.
- Rider is told once (push + WhatsApp + confirm banner, with the weekly days), and the evening "kal" reminder fires
  whenever tomorrow's shift or place differs on a weekly week.
- A shift type used on any weekly day cannot be deleted (deactivate instead).

## Fixed in passing (same files, no answer changes)
- The "already on this shift" check compared against ANY open row, so a stale old row could swallow a real change.
  It now compares against the row that actually applies.
- Saving a regular shift on a day that already had a row left older regular rows open (how Kanan got four). It now
  closes them, like every other regular save.
- The phone's place list no longer offers workshops (the web already filtered them; the server dropped them anyway).

## Independent review (13-Oct) — 6 findings, all fixed
1. A workshop visit pinned on a weekly day flipped it (weekly OFF day → working = false absence; weekly shift on the
   type's off day → off). The pin now carries that day's on/off status; only the place moves.
2. The help line promised "a one-day change wins its day" also inside a date range, where (existing rule) a one-day
   change replaces the whole range. The wording now differs by mode. ⚠ That existing rule is unchanged — flag for owner.
3. If the person's regular shift type is not offered to this manager, the form no longer opens pre-filled (a weekday
   tweak would have moved his regular shift); it stays closed and a save keeps his weekly days.
4. A refused approval request (request column missing) no longer silently withdraws his earlier waiting request; the
   screens offer weekly days only when BOTH columns exist.
5. A weekday with no place of its own now shows "Same place as above" instead of the first office.
6. A shift type named only in a WAITING request can no longer be deleted.
+ The rider push now says "Hafte ke alag din khatam" when weekly days are removed.

## Two more rules (owner + phone walk, 13-Oct afternoon)
- **A temporary change replaces only the dates it covers** (owner ruling). A one-day cover inside a two-week range
  changes that day only; the range keeps every other day and cancelling the cover gives the day back to the range.
  Engine: `applyTemporaryAssignment` leaves an overlapping row that starts on/before the new one alone (the resolver's
  own ordering gives the new row its dates), trims one that starts inside the new dates, deletes one fully inside.
- **A closed regular shift is not a temporary change** (found on the phone). Every regular change gives the previous
  regular row an end date, and every screen read that as a temporary change: the month view said "12 changed days"
  after one regular change, and an upcoming regular change listed the old row as a cancellable temporary change
  (cancelling it dropped the person to the default shift). `ShiftResolutionService::closedRegularIds()` now decides
  from the audit log (start + end date) with a chain-rule fallback; used by the web grid, the phone list / month /
  My Shift, the Attendance popup summary and the cancel door.

## Second independent review (13-Oct) — 5 findings, all fixed
1. Two rows can now start on the same day (a range + a one-day cover on its first day); a regular change from that day
   could pick the range as the row to turn into the regular while the newer cover kept winning the day. The regular
   change now takes the open row (or the newest) and deletes every other row starting that day.
2. The "already told about a change" question fired over a CLOSED regular row (every one-day cover in the week before
   an upcoming regular change). It now ignores closed regulars, and says "Sirf <dates> badlega" when only part of an
   announced range changes.
3. A range whose start was trimmed had no audit entry at its new start and could read as a closed regular. The trim
   now writes its own audit row — and, more robustly, **every row the engine writes is stamped `is_temporary`**
   (new column, PART 1b) so no screen guesses the kind from dates any more; the audit-log rule only serves rows older
   than the column.
4. An older range that ENDS inside a new one is now trimmed to end the day before (answers identical, printed dates
   true). Cancelling a cover inside a range tells the rider what applies NOW ("ab aap ki shift: …"), not "normal shift".
5. A weekly day on a shift type that was deactivated since no longer blocks every save for that person: an untouched
   day keeps it; putting that type on a NEW day is still refused.

## Proof (local replica)
- `test_shift_weekly_days_oct13.php` — 144/144, in one rolled-back transaction, pushes + WhatsApp stubbed.
- `test_shift_weekly_parity_oct13.php` — old vs new resolver on 26 people × 457 days (11,882 person-days): 0 differ.
  PART 3 clean-up: 0 differ, leaves 0 duplicate open rows and 0 inverted rows.
- Existing suites (pushes stubbed): roster 60/60, authority 69/69, workshop seam 58/58, time bank 69/69,
  time-bank parity 110/110.
- Phone: new suite 15/15; full jest 991/991 tests (the App.test.tsx file fails to load a native gesture module —
  pre-existing, unrelated).
- Browser walk on the local planner as Taimur: grid, popup prefill, Friday edit, Save payload captured (not sent),
  date-range wording, Attendance popup wording.
- ✅ Walked on the dev phone as Taimur (13-Oct): list, sheet pre-fill, Friday picker, a real Save against the local
  replica (row + log via mobile), Month view. Rider side (banner / My Shift) proven by the harness only. `test_shift_authority_http.php` not run (it would send real pushes from this machine).

## After upload
Taimur opens each person once (Haider, Kanan, Arslan, Waseem…), picks **New regular shift**, opens
**🔁 Different on some days?**, fills the week, saves. Their old one-day rows stay as history.
