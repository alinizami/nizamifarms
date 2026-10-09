# Bikes area (Riders Map → Bikes) — UI redesign + fixes · PLAN · 7-Oct-2026

Owner rulings so far (29-Sep): **keep the three views** (Riders · Vehicles · Issues); approve the
clean-up, the side drawers, and the removal of repeated facts. Mockup (plain page, real Sep data):
https://claude.ai/artifact/5gaeuu4mkJrcZBRzCPtQu8 — all five screens, click-through.

Backend stays the single engine. Everything below is web front end (`fleet.blade.php`,
`fleet-issues.blade.php`, `index.blade.php`) plus a handful of small, listed server fixes. **No new
SQL, no new routes.** No APK.

---

## 1. Bugs found during the evaluation — ship with the redesign (or before it)

| # | What is wrong today | Where | Fix |
|---|---|---|---|
| B1 | Verdict says "Own bikes cost Rs 4.42/km less (48%)". It divides by the cheaper side. Own is 32% cheaper; company is 48% dearer. | `fleet.blade.php` `flRenderVerdict` | `pct = diff / max(ca, oa)`; wording "X% cheaper per km". |
| B2 | An own bike's "owner" is guessed from its **last keeper** → "Danish - own bike · Waseem is on EHP-676" (Waseem held it on 7-Aug by mistake). | `flvCard` `parkedBecause` | Use the **first** keeper in `history` (the seeded owner) for an own machine; fall back to last keeper only when there is no history. No SQL. A real `owner_user_id` is a later option if this ever misfires. |
| B3 | Job names "Oil + Tuning ??", "Oil Change ?", "Tyre Change ?" (types 2, 7, 8). 4-byte emoji lost on the 12-Sep save; "Chain Set ⚙️" (3-byte) survived. | data | Owner renames them in Service types (or SQL UPDATE). Build-time: find the save path that drops 4-byte characters (likely the connection the phone's types editor uses) and strip/accept emoji consistently. |
| B4 | Vehicle header + Riders "Service" column show the job that has **used up the most of its interval** (Brake Shoe, 7,865 km), not the one **due first** (Oil + Tuning, 1,946 km). | `VehicleService::overallServiceStateFor` (engine — same payload feeds the phone and the alerts) | Keep the state ranking (overdue → due soon → ok). Within the same state, pick the job with the **fewest km (or days) left**. One place, both surfaces follow. Re-run `test_service_intervals`, `test_vehicle_class_schedule`, alert tests. |
| B5 | BCN-5755 Fuel view: "Arslan: Rs 38.97/km since 1 Jan". Divides all fuel since the stint began (Rs 191,900) by only the km that have readings (4,924). | `VehicleService` `keeper_stint` in `show` | Compute fuel and km over the **same** window (first reading in the stint → now), or drop the line. |
| B6 | After **Record meter** or **Meter replaced** is saved, the page calls `flLoad()` with **no month** → server answers 422 "Invalid month" → Riders table reads "Could not load this month" and `flMonth` becomes undefined (rider panel breaks until the month picker is touched). | `flvSaveMeter`, `flvMeterReplacementPost` | `flLoad(flMonth, true)`. |
| B7 | The Riders tab and rider panel are served from a **120-second cache** (`fleet_fuel_month_*`, `fleet_fuel_rider_*`) that a meter save / replacement does **not** clear. For up to 2 minutes the rider row and panel still show the old odometer (9,133) while the vehicle card already shows 505. | `FleetFuelController` cache keys; `VehicleService::flushAfterMeterReplacement` / `flushAfterMeterLog` | Forget the month + rider keys for the affected months in the two flushes (or always request `fresh=1` on the client after a meter write — do both). |
| B8 | The open **rider** panel is not reloaded after a meter write (the vehicle panel is). | `flvMeterReplacementPost`, `flvSaveMeter` | `if (flSelected) flSelectRider(flSelected)`. |
| B9 | A **machine-log reading lower than the bike's history** is accepted with "✓ 146 km on this machine" and no warning; the card keeps the higher figure. By design a machine's km is its highest reading, so the log changes but the bike does not. This is what a manager sees as "I changed the meter and nothing moved". | `flvMeterHintCheck`, Meter editor | When start **or** end is below the machine's floor: amber hint "Lower than this bike's last reading (9,133 km). If the meter was replaced, record the replacement below — a lower reading on its own does not move the bike's km." and unfold the replacement section. Day hint must check the floor even when both readings are typed. |

Verified on the replica (7-Oct, rolled-back transactions): recording the replacement 2-Oct 9,133→0
turns Rajab's 2–4 Oct days from "missing" to ok (146 / 179 / 126 km), vehicle current km 9,133→505,
rider row and vehicle day totals agree (809 km), and the server is right **immediately** when asked
fresh. The stale figures the owner saw are B6/B7/B8 (client + cache) and, for a plain lower log
entry, B9 (by design, unexplained).

**Question for the owner:** on prod, did you record Rajab's change as a **replacement** (🔁 section)
or as a **meter log** entry? The local copy (to 5-Oct) has neither; the answer tells which of B6–B9
you hit, and whether the replacement still needs recording on prod.

---

## 2. Recent rounds that must be visible in the new screens

Work shipped between the mockup (29-Sep) and today, all affecting Bikes:

| Round | What the new UI must carry |
|---|---|
| 🔁 **Meter replaced** (Oct-5, committed) | Meter editor keeps the folded "Meter replaced on this machine?" section (list · record · remove). New: a **"Meter replaced 2 Oct · old 9,133 → new 0"** line on the bike's Overview and in the Activity/Day-by-day on that day; readings always shown **as typed**; on the swap day the start/end pair may read "9,133 → 147" without a warning. Checkout "end < start" block skipped only when the server says `meter_replaced_today`. |
| 🏍🌙 **Off-duty km = one number** (Sep-30) | Riders "Off duty" column = **company machines only**, custody-checked, ≤1 km ignored. Rider drawer "Off duty" tile opens the **nights list** (date · km · plate · from/to meter) from `offDutyNights()`. A past month shows off-duty only if he rode a company machine. Fuelled km = company km + own-bike work km. |
| 🏍📈 **Rule P spine + date ceiling** (Oct-5) | No UI of its own, but refusal messages ("21,459 does not fit its own readings…", the record named in the service guard) must be shown **verbatim** in the new Record service / Mark done / claim forms. Keep the escape hatch: a machine-log entry at the real reading. |
| 🧰 **Record a service: several jobs, ONE bill** (Sep-29) | Record-a-service sheet with job tick-boxes; Past services grouped **by visit** (Edit visit · + Add a job · Add the bill); "visit bill #N" nudge; "amount needed" nudge; combined labels ("Oil + Tuning + Brake Shoe"); workshop close with job tick-boxes + "which issues are fixed?". |
| 🔧 **Service record odometer guard** (Sep-20) | Refusals name the record that set the bound. Edit/Remove on service history stays. |
| 🛠 **Issues board rounds E–G** (Sep-15) | Chat (N) button, server `context_line`, history rows open threads, quiet-row count, 60-s poll that never redraws an open thread. |
| Sep-29 pre-deploy fixes | Own bikes read "no company schedule" (not "service unknown"); visit-level bill check. |

---

## 3. Functions that exist today and must exist in the new design (acceptance list)

Grouped by screen. "Form" = the existing modal, re-used unchanged unless noted.

**Riders (month)**
- Month stepper · New petrol / New maintenance (one "New claim ▾") · Service types editor (types, class/basis, exceptions, default interval) · Km-breakdown toggle.
- Needs-you cards: claims to approve · home missing (company riders) · service due · no-meter days · **days still open** · **unclassified rider** · unusual (thresholds TBD) · second fills same day (TBD).
- Summary: fuel · maintenance · company vs own all-in · correct verdict · **notes box** (unattributed Rs, shared/transit km, unaccounted km) shown only when non-zero.
- Row: name · machine(s) incl. "+ CAD-2958 van · 839 km" · km · fuel (+waiting) · maintenance (regular/repair/other split) · Rs/km all-in with bar (fuel-only behind toggle) · next service (B4 rule) · attention chips.
- Visible primary action on hover/row end: **Open**. Row opens the rider drawer.

**Rider drawer**
- Header: name · machine chip (link to bike drawer) · month stepper · close.
- Month: tiles (on duty · off duty → nights list · fuel · maintenance) · worth-a-look (early service, second fills, open days, no-meter) · **one card per machine this month** (km, fuel, Rs/km, "chain incomplete", Open bike) · maintenance by job.
- Day by day: date · start→end as typed · km · ride-home mark · "✎ manager" mark · multi-machine day lines · shared/transit/unaccounted lines · **machine-for-the-day override** (day chip → `flvEditDay`) · claims inline with: **Approve / Reject (pay source + bank, L1/L2)** · **Edit claim** · **Fix meter reading** · photo lightbox · dupe/odd-km flags · service early/late text.
- Claims: same rows, filterable (fuel / maintenance / waiting).
- Home location: on file / set / change (shared editor).
- Record service · This vehicle's schedule · Company standards (buttons on the machine card).

**Vehicles (now)**
- Add vehicle · Meet-up points · **vehicle-request banner** (asking / handing back → approve) with per-row "X is asking for this" chip · "rules not switched on" note when `rules_enabled=false`.
- Filter chips: all · needs attention · on the road · parked · company · own · van · **retired**.
- Row: vehicle (plate, model, company/own/van, photo thumb if any) · with (keeper, since; "Parked — <owner> is on …"; "Nobody · last with …") · odometer (as typed) · next service (job, km/days, bar; "no company schedule" for own) · issues (open · past) · workshop (booked / today / tomorrow / missed / proposed) · attention flags (**stranded · missed · overdue · today · unconfirmed**) · **No home location** tag → opens the home editor.
- **One visible primary action per row: Assign (parked) / Reassign (on road)**; ⋯ holds Take back · Edit vehicle (incl. van base, retire/restore) · Record meter · Schedule workshop.
- Riders without a bike: Give a bike · Register own bike.
- Deep link `#bikes?vehicle=ID` (and `?ticket=`) opens the drawer.

**Bike drawer**
- Header: plate · tags · keeper link (→ rider drawer) · odometer · **Change keeper** · **Add ▾** (Petrol — company only · Service done · Repair bill) · Schedule workshop · ⋯ (Reassign · Take back · Record meter · Edit · This bike's schedule · Retire/Restore) · close.
- Overview: next service (B4 rule, bars, never-recorded list) · **meter replaced line** · issues (open, past, Open an issue — company only) · **workshop block with every action** (approve · decline · later · withdraw · accept for him · depart · arrived · mark done with job + issue ticks · cancel) · month-so-far · keeper + home.
- Costs: 4 period tiles (selectable) · breakdown (regular / repairs / fuel / unclassified / waiting) · Rs/km month vs usual · B5-fixed stint line or none.
- Activity: month stepper · filters (everything / fuel / service & repairs / kilometres) · day cards (meter start/end, who, claims with time + "machine not recorded" marker, handover/split/shared/transit/unaccounted lines, reconciliation note) · **who rode it this month** · service history **grouped by visit** with Edit visit / + Add a job / Add the bill / Remove · "from history" marker on unstamped claims.
- Service schedule: per job · last done · due · bar · **Edit this bike's schedule**.
- Photos & handovers: view (lightbox) · add with date + note · **delete** · keeper history.
- Meter editor (from ⋯ Record meter): attendance pair · machine log pair · hint (B9) · **replacement section**; after save → B6/B7/B8 refresh.

**Issues**
- Filter chips incl. **not rideable · missed · awaiting approval**, shown when non-zero · cards collapsed by default, red auto-open · ticket rows (title, age, whose turn, unread) · inline thread (reply + photo, close with unread warning, reopen) · workshop line + Schedule · **Approve/Decline on a proposal line** (open item from Sep-15) · Open bike (drawer in place) · Chat (N) with closed history · quiet row · 60-s strip-only poll · badge on the tab · planners' standalone page renders the same partial.

**Page**
- Rename header "⚠️ Issues" → "Rider checks" (pending ruling) · per-tab title · service-alert toasts move to a bell/inbox (pending ruling) · 💡 widget must not cover the actions.

---

## 4. Build order

**Phase 0 — fixes (can ship alone, 1–2 days).** B1, B2, B6, B7, B8, B9 in `fleet.blade.php` +
`VehicleService` flushes + `FleetFuelController` keys. B4 and B5 in `VehicleService` (engine) with the
fleet suites re-run. B3 is data (owner) + a save-path check. Deploy: web files + `/xclean`, no SQL, no APK.

**Phase 1 — visual system in place (no layout change).** Tokens, one SVG icon set, status pills,
orange = brand only, prose → ⓘ, duplicates removed, per-tab title, header overflow fixed.

**Phase 2 — drawers.** `#flDetail` and `#flVehDetail` become right-side drawers with tabs; both
addressable (`#bikes?rider=ID`, `#bikes?vehicle=ID`); rider ↔ bike cross-links; every existing modal
keeps working from inside a drawer (z-index audit: assign, meter, schedule, types, home pin, workshop
form, service record, claim edit, lightbox).

**Phase 3 — the three list views.** Riders: Needs-you cards, KPI strip, 8 columns + toggle, notes box.
Vehicles: dense list, filters, visible Assign/Reassign, ⋯ menu. Issues: shared visual system, Open
bike → drawer, proposal Approve/Decline.

Keep: all `fl*` / `flv*` / `flIss*` state names and the traps recorded in the file comments
(`onclick` quoting via `flJsArg`, single `#flTicketThread`, DOM-only toggles while a thread is open,
`flvMode` vs `flvDetailMode`, poll must not redraw an open thread).

## 5. Proof

- JS harness per view (jsdom, like `issboard_harness.cjs`): every button in §3 exists and calls the
  same function it calls today; drawer open/close; deep links; refresh after meter save hits
  `flLoad(flMonth, true)` and `flSelectRider`.
- PHP: `test_meter_replacement` 73 · `test_rule_p_moving_spine` 30 · `test_claim_attribution_plausible`
  19 · `test_service_intervals` · `test_vehicle_class_schedule` · `test_fleet_personas` 85 · issue
  board suites — identical before/after except B4/B5 deltas, which are listed.
- Browser walk on the replica as Taimur and as Shabib: all §3 actions once; rolled back.
- Deploy doc `DEPLOY-BIKES-UI-OCT-2026.md`: web only + `/xclean`; no SQL; no APK (B4 changes a
  server payload the phone already renders).

## 6. Open rulings

1. Second fill same day: flag only when the meter did not move?
2. "Unusual" thresholds (all-in > 1.5× median; off-duty km > work km)?
3. Rename header "Issues" → "Rider checks"?
4. Phone's Bikes screen: same clean-up later, or not?
5. B2: first-keeper rule now, owner field later?
6. Service alerts: bell/inbox instead of toasts?
7. Prod: was Rajab's 2-Oct change recorded as a replacement or a meter-log entry?
