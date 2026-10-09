# DEPLOY — 🔁🚚 Vehicle request for riders WITHOUT their own bike (Oct-07-2026)

**1 SQL · 3 web · /xclean · APK (4 mobile files).** Web first, then the APK.

Owner (Shabib), 7-Oct:
- **Gap 1:** a rider holding nothing had no "Bike badlein" button. Every rider holding nothing now
  sees it, no list (example given: Taimur with no bike assigned can ask for the van).
- **Gap 2:** a rider on a company bike can now ask for the van (or another company machine) in
  **one** request, with both meter readings.

## Why
- The request button was only on **My Vehicle**. The only daily way into My Vehicle was the
  Attendance **MY VEHICLE** row, and that row is hidden when nothing is assigned. My Vehicle's own
  "no bike" page already had the button, but no screen led to it.
  Example on the replica: Mashood (van, no own bike) handed the van back by request on 2-Oct. On
  3-Oct and 4-Oct a manager had to assign him the van by hand.
- A company bike → van request used to be refused: "pehle wapas karein…" (hand the bike back
  first). After that first approval the rider holds nothing, so (because of Gap 1) he had no way
  to ask for the van.

## 1 — SQL (local replica already has it)
`database/migrations/vehicle_handover_swap_oct2026.sql` adds two nullable columns to
`t_ops_vehicle_handover_request`: `swap_from_vehicle_id`, `swap_from_meter`. Run it **once** (plain
ADD COLUMN). The web code checks that the columns exist. If the code is uploaded before the SQL
runs, the swap is still refused exactly as before, so the order is safe either way.

## 2 — Web (3 files)
| File | Change |
|------|--------|
| `app/Services/Riders/VehicleHandoverRequestService.php` | `swapReady()`. `options()` adds `swap_ready`. `raise(…, $closeMeter)`: a company → company take now needs the opening reading **and** the closing reading (`meter_missing: close_meter`) instead of being refused. A user with **no rider profile** is told when he asks (assign() would refuse him at approval anyway). `shape()` adds `swap_from_vehicle_id/_name/_meter` + `swap_meter_hint`. `decide()` passes the closing reading to `assign()` as the vacated-machine reading, but **only while he still holds that machine**. The approver's `close_meter` overrides it. A refusal returns `meter_missing` + `meter_vehicle_id`. The outcome message and the rider's push name the machine he **actually** handed back. |
| `app/Http/Controllers/CRM/VehicleHandoverController.php` | Accepts `close_meter` on raise and on approve. Passes `meter_missing` (+ `meter_vehicle_id`) out on a 422. |
| `resources/views/partials/vehicle-request-banner.blade.php` | The swap card shows "He hands back: X · meter N". When the server says a reading is missing, the banner asks for it (prompt) and sends the same approval again. Before this, the server's "type it in the meter box" message pointed at a box the web banner did not have. |

Then run **`/api/public/xclean`** (the Blade partial changed). No route or config changes.

## 3 — APK (4 mobile files; this tree also holds the Oct-07 recipe-link round)
| File | Change |
|------|--------|
| `src/screens/AttendanceScreen.js` | New **MY VEHICLE — "Koi gaari aap ke naam nahi · 🚚 Van ya company bike maangein"** row, shown only when the server says `reason: none_assigned`. It is not shown when the call fails or the fleet tables are missing. Tapping it opens My Vehicle. |
| `src/screens/VehicleProfileScreen.js` | On the "no bike" page the button reads **"🚚 Company gaari maangein"**. |
| `src/components/VehicleHandoverRequest.js` | Holding nothing: the sheet title is "Gaari maangein" and the picker label is "Kaunsi gaari?". Swap (company → company, and only when the server says `swap_ready`): a **second meter box** for the machine he hands back. The phone stops him if it is empty, and sends `close_meter`. Every other request posts exactly what it posted before. |
| `src/screens/StoreOpenOrdersScreen.js` | The approval banner shows "Hands back: X · meter N" and the second plausibility hint. Android has no prompt box, so when a reading is missing the error says to approve from the web banner. |

## Mixed versions (safe)
- **New web + old APK:** a swap is refused with the new message. That message also tells an old
  app, which has no second box, to use the two-step route. Holding-nothing riders still have no
  Attendance row until the APK is installed.
- **Old web + new APK:** `swap_ready` is missing, so there is no second box. The old refusal still
  applies, and the Attendance row still works because it only reads `/rider/my-vehicle`.

## Unchanged
Return, R4 (van → own bike = a RETURN of the van), the checkout "van wapas" prompt, the TTL, one
open request per rider, the approver permission (`assign_vehicles`), and the displaced-rider flow.

## Proof
- `test_handover_swap_oct07.php` — **59/59**: holding nothing → ask → approve; no rider profile is
  refused before anything is written; a swap demands and stores both readings; approving a swap
  moves both machines and writes A's closing reading; the bike taken back before approval does
  **not** get the stale reading written; a different company machine assigned after the request
  → approval refused with `close_meter`/that machine's id → the approver's reading → approved and
  that machine is named; R4 is unchanged; the controller passes `close_meter` in and
  `meter_missing` out.
- Older suites `test_handover_{requests,hardening,mobile,fullday}.php`: same counts before and
  after (31/3, 4/5, 10/3, 37/4). The ✗ are **pre-existing** fixture rot (fixtures assign company
  machines without the R3 meter) and are not touched by this round.
- Browser, local, as Shabib, Bikes → Vehicles: the swap card read "He hands back: TEST bike A ·
  meter 520". The missing-reading card prompted with the **right** machine. Cancel left it
  pending. Typing "4,010" re-sent `{"close_meter":4010}`. That second POST was intercepted so no
  real push went out.
- Jest: `__tests__/vehicleRequestNoOwnBikeOct07.test.js` 4/4. Full suite **962/962 tests**.
  44/45 suites pass. The one failing suite is `App.test.tsx`, which fails to load (gesture-handler
  native module not mocked). That was already failing before this round, as recorded in earlier
  rounds' notes.
- Not device-walked.
