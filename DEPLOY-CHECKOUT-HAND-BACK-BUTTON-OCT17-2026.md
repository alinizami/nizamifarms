# Deploy — 🔁 Hand back a company machine from the checkout button (17-Oct-2026)

**Why.** Mashood drives the van and has no bike of his own. The "hand the van back first?" question at
OUT only exists for riders who came in on their own bike (Rajab), so OUT simply closed Mashood's day on
the van. His only way to return it was the "🔁 Bike badlein" sheet on the Vehicle profile screen. On
8-Oct his request (#29) was never answered, and the van stayed on his name until Shabib released it by
hand on 14-Oct.

**What changes (owner ruling 17-Oct).** Checking out ON the company machine stays the default. Every
rider holding a company machine (van or company bike) now gets ONE extra button on the checkout dialog
he already sees. Nothing new pops up.
- Meter riders: the "🏁 Meter aur Check Out" dialog gets **"🔁 Van wapas karein"** (a company bike is
  named by its plate, e.g. "🔁 EDN-198 wapas karein").
- Home-flow riders (company bike, meter taken at home) and meter-exempt riders: the plain "Check Out
  confirm karein" dialog gets the same button.
- Tapping it opens the existing hand-back sheet with the closing meter compulsory. The request goes to
  the managers' banner and push, exactly like today.
- Pressing OUT again while it waits: "⏳ Manzoori ka intezar hai", with "Van par hi OUT" still available.
- After approval he holds nothing, so OUT asks for no meter. (Proved on the replica, rolled back:
  Mashood holds van 4 → meter required; after `release()` → holds nothing → meter not required.)
- Rajab's own-bike prompt is unchanged.

**1 web file + APK. NO SQL, no /xclean** (no routes, views or config). The two halves don't depend on each
other, so either can go first. Older APKs keep today's behaviour.

## File (web, 1, WinSCP)

| # | File | What |
|---|------|------|
| W1 | `app/Services/Riders/VehicleHandoverRequestService.php` | new `closeDayOnReturnedMachine()`: when an approved return leaves the rider holding NOTHING, the reading he typed also becomes his attendance `meter_end` (+ `meter_end_vehicle_id`). This keeps the fuel summary (web Fleet fuel + phone My Vehicle) and day checks from flagging the day as "no_end". |

It is written only when all of these hold: he holds nothing after the return; an open attendance row (checked in,
not out) has no end meter yet (never overwritten); and its start reading is of the SAME machine (stamped
to it, or unstamped with a sane 0–500 km day). The approver's message adds "His day's end meter was
recorded as N." Never fatal.

Proof: `test_handback_closes_day_oct17.php` (replica, everything rolled back), run on a finished day:

| Scenario | attendance end | Fuel day | Day checks |
|---|---|---|---|
| A start stamped to van | 76,070 (van) | ok, 40 km | clean |
| B start unstamped | 76,070 (van) | ok, 40 km | clean |
| C control: no write (= without W1) | — | missing / no_end | no_end |
| D start on another machine (two-machine day) | not written | missing / no_end | no_end |
| E control for D: same day closed on the van at OUT, today's behaviour | — | missing / unusable | — |

D is a two-machine day (a company bike swapped for the van, then the van returned). It is flagged today
whichever way it closes (E), so it is unchanged. It would need its own split-day rule.

## Files (mobile, 4)

| # | File | What |
|---|------|------|
| 1 | `src/utils/checkoutMachine.js` | new pure `handBackAtCheckout()`: offer / waiting / nothing, and the words |
| 2 | `src/screens/AttendanceScreen.js` | OUT reads his open request fresh, then adds the button to the meter dialog or the plain confirm; "waiting" dialog |
| 3 | `src/components/VehicleHandoverRequest.js` | `refreshPending()` on the sheet's handle; "sent" message no longer promises "apni bike ka meter" when nothing comes back |
| 4 | `__tests__/checkoutMachinePrompt.test.js` | 7 new tests (70/70 pass; with `vehicleRequestNoOwnBikeOct07` 74/74) |

Build: `build-production-apk-auto.bat`. This APK tree had no other uncommitted changes when this was built.

## Desk check (17-Oct, no phone): every way out
No new endpoint. OUT additionally reads `/rider/vehicle-request/mine`, which the Attendance screen already
polls every 30 s today.

| Situation | What the rider sees | Way out |
|---|---|---|
| Not holding a company machine (own bike, nothing) | exactly today's OUT | — (no network call is added) |
| Rajab-shaped day (came on own bike, holds van) | his existing question, unchanged | unchanged |
| Holds a company machine, no open request | today's dialog + one extra button (first position) | "Meter likh kar check out" / "Check Out karein" are where they were |
| Bad network at OUT | at most a 4 s pause, then the dialog built from the last poll | same buttons |
| Double tap on OUT | one dialog | — |
| Return already sent, not decided | "⏳ Manzoori ka intezar hai" | "🚚 Van par hi OUT" → today's checkout on the van |
| Some other request open (a "take") | today's dialog, no extra button | unchanged |
| Request decided / cancelled / expired since the screen loaded | "🔄 Gaari ki request band ho gayi — dobara OUT dabayein" (screen reloads) | press OUT again |
| Office already took the machine back (stale screen) and he taps "wapas karein" | "Aap ke naam koi gaari nahi — dobara OUT dabayein" (screen reloads) | press OUT again |
| Sheet can't load (network) | "Load nahi hua" | press OUT again, check out on it |
| Approved, now holds nothing | plain confirm; the server asks no meter (replica probe) | — |

Lint is unchanged from before (same 3 old `fetchAttendanceData` hook errors, no new ones); jest 74/74.

✅ The "no end meter" flag on a day handed back before OUT is fixed by W1 above (owner ruling 17-Oct).

## Check after installing the APK (not device-walked yet)
1. Mashood, holding the van, presses OUT → the meter dialog shows three buttons:
   "🔁 Van wapas karein" · "Rehne dein" · "Meter likh kar check out".
2. Tap "Van wapas karein" → the sheet opens on the return, meter compulsory → send → "Bhej diya …
   Manzoori ke baad dobara OUT dabayein." (no "apni bike" line).
3. Press OUT again before approval → "⏳ Manzoori ka intezar hai" with "Intezar karein" / "🚚 Van par hi OUT".
4. Shabib or Taimur approves. The banner message ends "His day's end meter was recorded as …". Mashood
   presses OUT → plain confirm, no meter asked. Next day: Fleet fuel → Mashood → that day shows "ok"
   with its km, not "no end".
5. A company-bike home-flow rider presses OUT → the plain confirm shows "🔁 <plate> wapas karein".
   "Check Out karein" still works as before.
6. Rajab (own bike, holding the van) → his existing question appears first, unchanged.
