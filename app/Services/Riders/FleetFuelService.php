<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

/**
 * Fleet & Fuel (Jul-2026) — what each rider's bike costs per month.
 *
 * Two different money models sit side by side and must NOT be averaged
 * together, which is the whole point of this screen:
 *
 *   • OWN bike  — paid a per-km rate for SHIFT kilometres only. The claim is
 *                 built from the attendance meter, so it audits itself.
 *   • COMPANY bike — the firm buys the actual fuel, so EVERY kilometre costs
 *                 money, including the ride home and personal use. Claims are
 *                 flat cash amounts with no km attached.
 *
 * So cost-per-km uses a different denominator per bike type (`basis_km`), and
 * the UI always says which. Averaging them would flatter the company bikes.
 *
 * Meter data contains real typos (dropped digits, test rows, one rider once
 * entered ANOTHER rider's odometer). Every reading passes plausibility bounds
 * before it can move a number — see isSaneDay()/isSaneGap().
 */
class FleetFuelService
{
    /** A single day's shift distance above this is a typo, not a ride. */
    const MAX_DAY_KM = 500;

    /** Odometer readings below this are dropped-digit typos (bikes are 5-figure). */
    const MIN_METER = 1000;

    /** Overnight gap above this is a meter change / typo, not a ride home. */
    const MAX_GAP_KM = 500;

    /** Two flat claims closer together than this with the same amount = double-tap. */
    const DOUBLETAP_SECS = 600;

    /**
     * A gap between two consecutive fills beyond this is a typo or a swapped bike,
     * never a real tank. Named because BOTH chains below (machine-keyed and the
     * rider-keyed fallback) have to call the same number plausible — two literals
     * would eventually drift and the same claim would read "142 km" on one path
     * and "doesn't add up" on the other.
     */
    const MAX_FILL_GAP_KM = 2000;

    const CAT_FUEL = 'Petrol';
    const CAT_MAINT = 'Maintenance';

    /** One machine's fill list is walked once per request, not once per claim. */
    private array $machineFillMemo = [];

    /** Same, for the new-claim form's "since his last fill" hint. */
    private array $lastFillMemo = [];

    // =================================================================
    // MONTH SUMMARY
    // =================================================================

    public function monthSummary(string $month): array
    {
        [$from, $to] = $this->bounds($month);

        $profiles = $this->profiles();
        $meters   = $this->meterAggregates($from, $to);
        $claims   = $this->claimAggregates($from, $to);

        // ⭐⭐ MACHINE-KEYED KILOMETRES (Aug-2026). Where the registry can say which
        //    bike produced a reading, its answer replaces the rider-keyed chain —
        //    that is the whole point of the engine. Riders it has never tracked are
        //    simply absent from the overlay and keep the older answer untouched.
        $engine = $this->machineOverlay($month);

        // ⭐ The ACTIVE ROSTER is always in the list, activity or not. The ids used
        //    to come only from the month's meters + claims, so a fresh month (or
        //    one where a rider hadn't ridden yet) returned an empty table — and
        //    the "new petrol / maintenance" modal builds its rider list from
        //    these rows, so on the 1st of a month a manager had nobody to file
        //    for, on web and mobile alike. A delivery rider exists regardless of
        //    whether this month has data on him yet.
        $rosterIds = array_keys(array_filter($profiles, fn ($p) => (int) $p->active === 1));
        $ids = array_unique(array_merge($rosterIds, array_keys($meters), array_keys($claims)));
        if (!$ids) {
            return ['month' => $month, 'riders' => [], 'totals' => $this->emptyTotals()];
        }

        $names = DB::table('t_sys_user')->whereIn('id', $ids)->pluck('fullname', 'id')->toArray();
        $service = $this->serviceState(array_keys($profiles));

        /**
         * 🏠 HOME LOCATION for the whole roster (Sep-2026, owner ask: the pin belongs
         * "with the riders in the fleet"). This table IS the riders list on the Bikes
         * tab, so the pin has to arrive with the row rather than be fetched per rider.
         *
         * ⚠ ONE query for the month × roster — this loop already runs over every rider
         *   and must never issue a query inside it (the N+1 that cost the payroll page
         *   14 seconds). Two queries total: the pins, then the names of whoever set them.
         * ⚠ Guarded: a database without the home-journey columns returns no pins and
         *   every row simply reads as "not set", exactly as it does today.
         */
        $homePins = [];
        try {
            if (\Illuminate\Support\Facades\Schema::hasColumn('t_ops_rider_profile', 'home_latitude')) {
                $pinSvc = new \App\Services\Riders\RiderHomePinService();
                $pinRows = DB::table('t_ops_rider_profile')->whereIn('user_id', $ids)
                    ->whereNotNull('home_latitude')->whereNotNull('home_longitude')
                    ->get(['user_id', 'home_latitude', 'home_longitude', 'home_radius_m',
                           'home_set_by', 'home_set_at']);
                $setterIds = $pinRows->pluck('home_set_by')->filter()->unique()->all();
                $setters = $setterIds
                    ? DB::table('t_sys_user')->whereIn('id', $setterIds)->pluck('fullname', 'id')->toArray()
                    : [];
                foreach ($pinRows as $pr) {
                    $homePins[(int) $pr->user_id] = [
                        'lat' => (float) $pr->home_latitude,
                        'lng' => (float) $pr->home_longitude,
                        'radius_m' => $pr->home_radius_m !== null ? (int) $pr->home_radius_m : null,
                        'set_at' => $pr->home_set_at,
                        'set_by_name' => $pr->home_set_by !== null
                            ? ($setters[(int) $pr->home_set_by] ?? null) : null,
                        'maps_url' => $pinSvc->mapsUrl((float) $pr->home_latitude, (float) $pr->home_longitude),
                    ];
                }
            }
        } catch (\Throwable $e) {
            $homePins = [];   // never let a missing pin break the month table
        }

        $riders = [];
        $offRoster = [];        // spend by people who are not delivery riders
        foreach ($ids as $uid) {
            $p = $profiles[$uid] ?? null;
            $m = $meters[$uid] ?? ['days' => 0, 'work_km' => 0, 'offduty_km' => 0, 'no_meter_days' => 0];
            $c = $claims[$uid] ?? $this->emptyClaims();

            // A rider with fuel claims but no bike flag at all can't be costed
            // honestly — surface it rather than guessing a denominator.
            // NOTE: $isCompany is a bool (or null when the rider has no profile).
            // Compare it as a bool — `=== 1` is always false and silently blanked
            // the off-duty column when this was first written.
            $e = $engine[$uid] ?? null;

            // ⭐ WHICH BIKE, not just which KIND of bike. The active machine is the
            //   OPEN assignment (what he is on right now), while `machines` is every
            //   one the month touched — the two answer different questions and the
            //   table shows both.
            $active = $e['active'] ?? null;
            $isCompany = $active !== null
                ? (bool) $active['is_company']
                : ($p ? ((int) $p->company_bike === 1) : null);
            $classified = $p !== null && ($isCompany === true || (int) $p->meter_required === 1);

            // Machine-keyed kilometres replace the rider-keyed chain wherever the
            // registry can speak. `shared` and `transfer` belong to NO rider, so
            // they never enter work or off-duty — the blame fix, in one line.
            if ($e) {
                $m = [
                    'days'            => $e['days_counted'],
                    'work_km'         => $e['work_km'],
                    'offduty_km'      => $e['offduty_km'],
                    'unattributed_km' => $e['unattributed_km'],
                    // Handover days are excluded — see MachineAttribution's rollup.
                    'no_meter_days'   => $e['no_meter_days'],
                    'open_days'       => $m['open_days'] ?? 0,
                    'incl_ride_home_days' => $m['incl_ride_home_days'] ?? 0,
                ];
            }

            // Every kilometre the bike actually covered. Unattributed km ARE part of
            // it — we could not tell whether they were work or commute, but the bike
            // moved them and we bought the petrol. Leaving them out would make the
            // strip fail to add up (work + off-duty + unattributed ≠ total) and would
            // overstate the running cost by dividing real fuel by fewer kilometres.
            // Kilometres that belong to nobody: the handover day whose split cannot
            // be known, and the bike travelling between two riders.
            $sharedKm   = $e['shared_km'] ?? 0;
            $transferKm = $e['transfer_km'] ?? 0;

            $totalKm = $m['work_km'] + $m['offduty_km'] + ($m['unattributed_km'] ?? 0)
                     + $sharedKm + $transferKm;

            // ⭐ COST PER PRODUCTIVE KILOMETRE — always SHIFT km, both bike types.
            //
            // It is tempting to divide a company bike's cost by every kilometre it
            // moved (work + off-duty). That is wrong and flatters company bikes:
            // off-duty kilometres are a cost you carry, not output you received.
            // Dividing by them credits the bike for the rider's commute.
            //
            // An own-bike rider is paid per shift kilometre, so shift km is his
            // denominator by definition. Using the same denominator for company
            // bikes is the only way the two numbers answer the same question:
            // "what does one delivered kilometre cost me under each model?"
            $basisKm = $m['work_km'];

            // Nothing at all this month (no money, no distance). An OFF-roster
            // user with an empty month is noise and is dropped — but an ACTIVE
            // delivery rider keeps his row with zeros: the roster is who the
            // screen is ABOUT, and the new-claim modal needs him listed even on
            // the 1st of the month before anything has happened.
            $emptyMonth = $basisKm <= 0 && $c['fuel_rs'] <= 0 && $c['maint_rs'] <= 0
                && $c['fuel_pending_rs'] <= 0 && $c['maint_pending_rs'] <= 0;
            if ($emptyMonth && (!$p || (int) $p->active !== 1)) {
                continue;
            }

            // ⭐ ROSTER: this screen lists DELIVERY RIDERS, and the switch for that
            //    already exists — the "Delivery Rider" checkbox in the People &
            //    Rider List, which writes t_ops_rider_profile.active. The same flag
            //    drives the Rider Management page and the mobile assign-rider list,
            //    so one control now governs all three.
            //
            //    Anyone else who bought fuel (a manager buying in bulk, an
            //    ex-rider) keeps their money in the "unattributed" line rather than
            //    getting a bike row — the spend stays visible, it just stops
            //    pretending to be a rider's running cost.
            if (!$p || (int) $p->active !== 1) {
                $offRoster[] = [
                    'name'  => $names[$uid] ?? 'Unknown',
                    'fuel'  => $c['fuel_rs'],
                    'maint' => $c['maint_rs'],
                ];
                continue;
            }

            $riders[] = [
                'user_id'       => (int) $uid,
                'name'          => $names[$uid] ?? 'Unknown',
                'bike'          => $isCompany === null ? 'unknown' : ($isCompany ? 'company' : 'own'),
                'classified'    => $classified,
                // ⭐ THE MACHINE ITSELF — the plate, not just the kind. Null for a
                //   rider the registry has never tracked, whose row is unchanged.
                'vehicle_id'    => $active['vehicle_id'] ?? null,
                'vehicle_label' => $active['label'] ?? null,
                'machines'      => $e['machines'] ?? [],
                'machine_count' => $e ? count($e['machines']) : 0,
                // false = the plate above is a bike he rode this month but has since
                // handed back, so the chip reads as history rather than as "he is on
                // it now". Riders outside the registry send null (no opinion).
                'holds_now'     => $e['holds_now'] ?? null,
                // ⚠ false = a machine he rode has a hole in its odometer chain this
                //   month. His duty km still stand (they come from within-day pairs);
                //   the stretches between days do not, and the screen says so.
                'chain_ok'      => $e['reconciles'] ?? true,
                'days'          => $m['days'],
                'work_km'       => $m['work_km'],
                'offduty_km'    => $isCompany === true ? $m['offduty_km'] : null,
                'total_km'      => $isCompany === true ? $totalKm : null,
                // ⚠⚠ COMPATIBILITY CONTRACT — `unattributed_km` keeps its ORIGINAL
                //   meaning, "kilometres this rider is not credited with", so an old
                //   APK that renders it as one number stays correct. The engine's
                //   three separate reasons live in their own keys beside it; only
                //   surfaces that understand them should use those.
                'unattributed_km' => $isCompany === true
                    ? ($m['unattributed_km'] ?? 0) + $sharedKm + $transferKm : null,
                // Km on a day this machine changed hands: real, measured, and
                // unsplittable — so it is named on BOTH riders and charged to neither.
                'shared_km'     => $sharedKm ?: null,
                // The bike travelling between two people.
                'transfer_km'   => $transferKm ?: null,
                // Stretches spanning a day he worked and left unread. The only one of
                // the three that is really "we cannot tell".
                'unaccounted_km' => $isCompany === true ? ($m['unattributed_km'] ?? 0) : null,
                // Past days he checked in and never checked out. Left as
                // "in progress" by owner ruling — the team must go and close them —
                // but surfaced here so they are visible instead of invisible.
                'open_days'     => $m['open_days'] ?? 0,
                'basis_km'      => $basisKm,
                'basis_label'   => 'shift km',
                // ⭐ FUELLED KM (owner ruling Jul-28): every kilometre whose petrol
                //    THIS COMPANY paid for. On a company bike that is work + the
                //    commute; on an own bike we only fund shift km. This is what
                //    "costed" should always have meant — the old column showed work
                //    km alone while the firm was demonstrably paying for more.
                //    It is a MONEY figure, deliberately NOT the comparison
                //    denominator — see rs_per_fuelled_km below.
                //    Includes unattributed km: not knowing whether a kilometre was
                //    work or commute does not make the petrol free.
                'fuelled_km'    => $isCompany === true ? $totalKm : $m['work_km'],
                // Context only, never a denominator for the comparison: what the
                // fuel worked out to across every kilometre the bike actually
                // moved. Near real pump economics (~7 Rs/km) means the claims are
                // roughly honest; far above it means over-claiming.
                'fuel_per_all_km' => ($isCompany === true && $totalKm > 0)
                    ? round($c['fuel_rs'] / $totalKm, 2) : null,
                'no_meter_days' => $m['no_meter_days'],
                /**
                 * 🏠 Where he takes the company vehicle at night — null when none is set.
                 *
                 * ⚠ Deliberately independent of `bike` above. The pin is now kept whatever
                 *   he is riding today (a rider on his own bike this week is back on a
                 *   company one next week), so this must NOT be hidden for an 'own' row —
                 *   hiding it is how the old code lost pins in the first place.
                 */
                'home_pin'      => $homePins[$uid] ?? null,
                // The last odometer we have from a FILL, so the new-claim form can
                // say "that's N km since his last fill" while the manager types —
                // the same figure the approver later sees on the claim itself.
                // ⭐ THE BIKE HE HOLDS NOW, not the man: the claim being typed is for
                //   that machine, so the tank it follows is that machine's. `_by` is
                //   set when the previous tank was somebody else's, so the hint can
                //   say so instead of calling it "his".
                'last_fill_meter' => $this->lastFillMeter($uid)['meter'],
                'last_fill_by'    => $this->lastFillMeter($uid)['by'],
                'incl_ride_home_days' => $m['incl_ride_home_days'] ?? 0,
                'fuel_rs'       => $c['fuel_rs'],
                'fuel_pending_rs' => $c['fuel_pending_rs'],
                'pending_count' => $c['pending_count'],
                'fuel_claims'   => $c['fuel_claims'],
                'metered_rs'    => $c['metered_rs'],
                'flat_rs'       => $c['flat_rs'],
                'flat_claims'   => $c['flat_claims'],
                'litres'        => $c['litres'] > 0 ? round($c['litres'], 1) : null,
                'maint_rs'      => $c['maint_rs'],
                'maint_regular_rs' => $c['maint_regular_rs'],
                'maint_repair_rs'  => $c['maint_repair_rs'],
                'maint_other_rs'   => $c['maint_other_rs'],
                'maint_pending_rs' => $c['maint_pending_rs'],
                'rs_per_km'     => $basisKm > 0 ? round($c['fuel_rs'] / $basisKm, 2) : null,
                'rs_per_km_all' => $basisKm > 0 ? round(($c['fuel_rs'] + $c['maint_rs']) / $basisKm, 2) : null,
                // What a kilometre costs on THIS BIKE — fuel ÷ every km we fuelled.
                // Answers "what is this machine costing me to run", which is a
                // different question from "what does a DELIVERED km cost me"
                // (rs_per_km). Both are shown; only rs_per_km may be compared
                // across bike types, because an own-bike rider's commute is his
                // own expense and never enters his denominator.
                'rs_per_fuelled_km' => ($isCompany === true && $totalKm > 0)
                    ? round($c['fuel_rs'] / $totalKm, 2)
                    : ($basisKm > 0 ? round($c['fuel_rs'] / $basisKm, 2) : null),
                // All-in on the SAME denominator as the fuel rate beside it. Showing
                // fuel ÷ ridden km next to all-in ÷ productive km put two different
                // denominators side by side in one row, which reads as an error.
                'rs_per_fuelled_km_all' => ($isCompany === true && $totalKm > 0)
                    ? round(($c['fuel_rs'] + $c['maint_rs']) / $totalKm, 2)
                    : ($basisKm > 0 ? round(($c['fuel_rs'] + $c['maint_rs']) / $basisKm, 2) : null),
                'km_per_litre'  => $c['litres'] > 0 && $basisKm > 0 ? round($basisKm / $c['litres'], 1) : null,
                'dupe_flags'    => $c['dupe_flags'],
                'early_service_count' => $c['early_service_count'] ?? 0,
                'service'       => $service[$uid] ?? null,
            ];
        }

        usort($riders, fn ($a, $b) => $b['basis_km'] <=> $a['basis_km']);

        $totals = $this->totals($riders, $offRoster);

        // ⚠⚠ COUNT A SHARED LEG ONCE. Every shared/transit kilometre is deliberately
        //   named on BOTH riders — that is the point, neither is charged and both can
        //   see it. Summing the rider rows to get a fleet figure therefore DOUBLES it
        //   (the live page read "1,046 km" for 523 real kilometres). The fleet-wide
        //   number has to come from the machines, where each leg exists exactly once.
        $totals['shared_km'] = 0;
        $totals['transfer_km'] = 0;
        try {
            if (strtoupper((string) $this->cfg('MACHINE_ATTRIBUTION', 'Y')) === 'Y') {
                foreach ((new MachineAttribution())->month($month)['vehicles'] ?? [] as $v) {
                    $totals['shared_km']   += $v['totals']['shared'];
                    $totals['transfer_km'] += $v['totals']['transfer'];
                }
            }
        } catch (\Throwable $e) {
            $totals['shared_km'] = 0;
            $totals['transfer_km'] = 0;
        }

        return ['month' => $month, 'riders' => $riders, 'totals' => $totals];
    }

    /**
     * ⭐⭐ THE ENGINE'S ANSWER, SHAPED FOR A RIDER ROW.
     *
     * Returns [user_id => [...]] for every rider the registry can speak for, and
     * NOTHING for anyone else — an absent entry is the signal to keep the older
     * rider-keyed answer, which is what makes this safe to ship: a rider with no
     * assignment history cannot notice that this code exists.
     *
     * `active` is the machine he holds RIGHT NOW (the open assignment), not the one
     * he rode most — a manager looking at the table wants to know where the bike is
     * today. `machines` is every machine the month touched, busiest first.
     */
    private function machineOverlay(string $month): array
    {
        try {
            // ⭐ ROLLBACK LEVER, NOT A FEATURE FLAG. Defaults to ON and needs no SQL
            //   row to work; inserting `MACHINE_ATTRIBUTION = 'N'` puts every rider
            //   back on the rider-keyed chain instantly, without a code change or an
            //   upload. It is also how the regression suite proves the before/after.
            if (strtoupper((string) $this->cfg('MACHINE_ATTRIBUTION', 'Y')) !== 'Y') return [];

            $engine = (new MachineAttribution())->month($month);
            if (empty($engine['available']) || empty($engine['riders'])) return [];

            $resolver = new VehicleResolver();
            $out = [];

            foreach ($engine['riders'] as $uid => $r) {
                $active = null;
                $activeId = $resolver->currentVehicleFor((int) $uid);
                foreach ($r['machines'] as $mm) {
                    if ($activeId !== null && $mm['vehicle_id'] === $activeId) $active = $mm;
                }
                // He holds something the month never saw him ride (a fresh handover):
                // still name it, with no kilometres behind it.
                if ($active === null && $activeId !== null) {
                    $v = $engine['vehicles'][$activeId] ?? null;
                    if ($v) {
                        $active = ['vehicle_id' => $activeId, 'label' => $v['label'],
                                   'is_company' => $v['is_company'], 'work_km' => 0,
                                   'offduty_km' => 0, 'shared_km' => 0, 'transfer_km' => 0,
                                   'unattributed_km' => 0, 'days' => 0, 'fuel_rs' => 0.0,
                                   'maint_rs' => 0.0, 'fuel_pending_rs' => 0.0,
                                   'maint_pending_rs' => 0.0, 'reconciles' => true];
                    }
                }

                // ⭐ He rode something this month but holds nothing now (a bike handed
                //   back, a rider between machines). Naming the bike he actually rode
                //   beats printing a bare "company" — the table's job is to say WHICH
                //   machine — so the busiest one stands in, flagged as no longer his.
                $holdsNow = $activeId !== null;
                if ($active === null && !empty($r['machines'])) $active = $r['machines'][0];

                $daysCounted = 0;
                foreach ($r['machines'] as $mm) $daysCounted += $mm['days'];

                $out[(int) $uid] = [
                    'holds_now'       => $holdsNow,
                    'work_km'         => $r['work_km'],
                    'offduty_km'      => $r['offduty_km'],
                    'shared_km'       => $r['shared_km'],
                    'transfer_km'     => $r['transfer_km'],
                    'unattributed_km' => $r['unattributed_km'],
                    'no_meter_days'   => $r['no_meter_days'],
                    'days_counted'    => $daysCounted,
                    'machines'        => $this->shapeMachines($r['machines']),
                    'active'          => $active,
                    'reconciles'      => $r['reconciles'],
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            // The engine is an improvement, never a dependency: if it cannot answer,
            // every rider falls back to the chain this screen has always used.
            Log::warning('machineOverlay unavailable', ['month' => $month, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * ⭐ THE DRILL-DOWN'S DAY ROWS, RE-CUT BY MACHINE.
     *
     * Everything the engine knows about this rider's month, laid over the day rows
     * the rider-keyed walk produced. Days the engine has no opinion on are left
     * exactly as they were, which is what keeps an untracked rider's drill-down
     * byte-identical to the one he has always had.
     */
    private function overlayRiderDays(int $userId, string $month, array $days, $isCompany): array
    {
        $blank = ['days' => $days, 'machines' => []];
        try {
            if (strtoupper((string) $this->cfg('MACHINE_ATTRIBUTION', 'Y')) !== 'Y') return $blank;

            $eng   = new MachineAttribution();
            $rider = $eng->forRider($userId, $month);
            if (!$rider) return $blank;

            $legs = $eng->legsForRider($userId, $month);
            $names = [];

            // 1. the machine each day was ridden on, and who wrote the reading down
            foreach ($rider['days'] as $date => $rows) {
                foreach ($rows as $row) {
                    if (!isset($days[$date])) {
                        $days[$date] = [
                            'date' => $date, 'meter_start' => null, 'meter_end' => null,
                            'work_km' => null, 'offduty_km' => null, 'offduty_since' => null,
                            'meter_ok' => false, 'status' => 'no_attendance',
                            'detail' => 'no_attendance', 'incl_ride_home' => false, 'claims' => [],
                        ];
                    }
                    // ⭐⭐ EVERY MACHINE HE RODE THAT DAY, not just the last one.
                    //
                    // ⚠⚠ THE ENGINE ALREADY SPLITS A RIDER-DAY PER MACHINE — `$rows` is a
                    //    LIST, one entry per bike, each carrying its own readings and
                    //    times. This loop then assigned the scalars with `=`, so the last
                    //    row silently won and the first bike vanished. That is what put
                    //    DCR-799's 27,751 reading under a CEN-455 chip on a day Danish
                    //    rode both: the label and the number came from different machines,
                    //    and nothing on screen said so.
                    //
                    // ⚠⚠ EVIDENCE ONLY. The engine also emits a day row for a machine it
                    //    attributed a claim to by ASSIGNMENT WINDOW — an unstamped claim
                    //    can conjure a bike the man never touched (live example: Waseem
                    //    showing CEN-455 on 1-3 Aug with no reading on it at all).
                    //    Announcing that as "a bike he had today" would be inventing
                    //    custody. A STAMPED claim does put its machine back, in the claims
                    //    loop, because then something recorded says so.
                    //
                    // ⭐ The scalars still hold the LAST machine, so every existing reader
                    //   (and every installed APK) behaves exactly as before; the full list
                    //   is additive beside them.
                    $mtHasEvidence = ($row['meter_start'] ?? null) !== null
                        || ($row['meter_end'] ?? null) !== null
                        || ($row['work_km'] ?? null) !== null;
                    $mtKey = (int) $row['vehicle_id'];
                    $mtRow = [
                        'vehicle_id'   => $row['vehicle_id'],
                        'label'        => $row['vehicle_label'],
                        'is_company'   => !empty($row['is_company']),
                        'meter_start'  => $row['meter_start'] ?? null,
                        'meter_end'    => $row['meter_end'] ?? null,
                        'work_km'      => $row['work_km'] ?? null,
                        'start_at'     => $row['start_at'] ?? null,
                        'end_at'       => $row['end_at'] ?? null,
                        'start_source' => $row['start_source'] ?? null,
                        'partial'      => !empty($row['partial']),
                    ];
                    // ⚠ A machine can appear TWICE in one day (his attendance pair plus a
                    //   manager-entered stint on the same bike). Merged, or the heading
                    //   would print the same plate twice as if he had swapped onto it.
                    if (isset($days[$date]['machines_today'][$mtKey])) {
                        $prev = $days[$date]['machines_today'][$mtKey];
                        if ($mtRow['meter_start'] === null
                            || ($prev['meter_start'] !== null && $prev['meter_start'] < $mtRow['meter_start'])) {
                            $mtRow['meter_start']  = $prev['meter_start'];
                            $mtRow['start_at']     = $prev['start_at'];
                            $mtRow['start_source'] = $prev['start_source'];
                        }
                        if ($mtRow['meter_end'] === null
                            || ($prev['meter_end'] !== null && $prev['meter_end'] > $mtRow['meter_end'])) {
                            $mtRow['meter_end'] = $prev['meter_end'];
                            $mtRow['end_at']    = $prev['end_at'];
                        }
                        $mtRow['work_km'] = ($prev['work_km'] === null && $mtRow['work_km'] === null)
                            ? null : (float) ($prev['work_km'] ?? 0) + (float) ($mtRow['work_km'] ?? 0);
                        $mtRow['partial'] = $prev['partial'] && $mtRow['partial'];
                    }
                    if ($mtHasEvidence || isset($days[$date]['machines_today'][$mtKey])) {
                        $days[$date]['machines_today'][$mtKey] = $mtRow;
                    }
                    $days[$date]['vehicle_id']    = $row['vehicle_id'];
                    $days[$date]['vehicle_label'] = $row['vehicle_label'];
                    // 'manager' / 'home' / 'checkin' — shown when the reading was not
                    // his own doing, which is the whole point on a handover day.
                    $days[$date]['start_source']  = $row['start_source'] ?? null;
                    // He has a reading at one end of the day only: the normal shape
                    // when a bike changes hands, NOT a missed meter.
                    $days[$date]['partial_day']   = !empty($row['partial']);
                }
            }

            // 2. the legs — his own, and the ones he merely shares
            foreach ($days as $date => $_) {
                $days[$date]['shared_km']       = null;
                $days[$date]['shared_with']     = null;
                $days[$date]['transfer_km']     = null;
                $days[$date]['handover']        = false;
                if ($isCompany === true) {
                    $days[$date]['offduty_km']      = null;
                    $days[$date]['unattributed_km'] = null;
                }
            }

            foreach ($legs as $date => $list) {
                if (!isset($days[$date])) continue;
                foreach ($list as $l) {
                    if ($l['kind'] === 'on_duty') continue;      // already in work_km
                    if ($l['kind'] === 'off_duty' && $l['user_id'] === $userId) {
                        $days[$date]['offduty_km'] = ($days[$date]['offduty_km'] ?? 0) + $l['km'];
                        $days[$date]['offduty_since'] = $l['since'] ?? ($days[$date]['offduty_since'] ?? null);
                    } elseif ($l['kind'] === 'unaccounted' && $l['user_id'] === $userId) {
                        $days[$date]['unattributed_km'] = ($days[$date]['unattributed_km'] ?? 0) + $l['km'];
                        $days[$date]['offduty_since'] = $l['since'] ?? ($days[$date]['offduty_since'] ?? null);
                    } elseif ($l['kind'] === 'shared') {
                        $days[$date]['shared_km'] = ($days[$date]['shared_km'] ?? 0) + $l['km'];
                        $days[$date]['handover']  = true;
                        $other = $l['from_user'] === $userId ? $l['to_user'] : $l['from_user'];
                        $days[$date]['shared_with'] = $this->riderName($other, $names);
                        $days[$date]['shared_direction'] = $l['from_user'] === $userId ? 'to' : 'from';
                    } elseif ($l['kind'] === 'transfer') {
                        $days[$date]['transfer_km'] = ($days[$date]['transfer_km'] ?? 0) + $l['km'];
                        $other = $l['from_user'] === $userId ? $l['to_user'] : $l['from_user'];
                        $days[$date]['transfer_with'] = $this->riderName($other, $names);
                    }
                }
            }

            // Keyed by vehicle while merging; handed out as a plain ordered LIST so the
            // screens can just iterate it. Ordered by when the machine was first read
            // that day, which is the order he actually rode them.
            foreach ($days as $dt => $row) {
                if (empty($row['machines_today'])) continue;
                $list = array_values($row['machines_today']);
                usort($list, function ($a, $b) {
                    $at = $a['start_at'] ?? $a['end_at'] ?? '';
                    $bt = $b['start_at'] ?? $b['end_at'] ?? '';
                    if ($at !== $bt) return strcmp((string) $at, (string) $bt);
                    return (int) $a['vehicle_id'] <=> (int) $b['vehicle_id'];
                });
                $days[$dt]['machines_today'] = $list;
            }
            ksort($days);
            return ['days' => $days, 'machines' => $this->shapeMachines($rider['machines'])];
        } catch (\Throwable $e) {
            Log::warning('overlayRiderDays failed', ['user' => $userId, 'error' => $e->getMessage()]);
            return $blank;
        }
    }

    /** Names for the "shared with X" lines, resolved once per request. */
    private function riderName(?int $uid, array &$cache): ?string
    {
        if ($uid === null) return null;
        if (!array_key_exists($uid, $cache)) {
            $cache[$uid] = DB::table('t_sys_user')->where('id', $uid)->value('fullname') ?: null;
        }
        return $cache[$uid];
    }

    /** Per-machine rows for the rider's "his machines this month" strip. */
    /**
     * Is this machine already in the day's list?
     *
     * ⚠ `machines_today` leaves overlayRiderDays RE-INDEXED as a plain list, so an
     *   `isset($list[$vehicleId])` test would silently check position 4, not vehicle 4.
     */
    private function dayListsMachine(array $list, int $vehicleId): bool
    {
        foreach ($list as $m) {
            if ((int) ($m['vehicle_id'] ?? 0) === $vehicleId) return true;
        }
        return false;
    }

    private function shapeMachines(array $machines): array
    {
        $out = [];
        foreach ($machines as $m) {
            $withHim = $m['work_km'] + $m['offduty_km'] + $m['shared_km']
                     + $m['transfer_km'] + $m['unattributed_km'];
            $out[] = array_merge($m, [
                'km_with_him' => $withHim,
                // His own running cost ON THIS BIKE. Fuel he filed ÷ every kilometre
                // it moved under him — the same shape as `rs_per_fuelled_km` on the
                // row above, so the two can be read together.
                'rs_per_km'   => $withHim > 0 && $m['fuel_rs'] > 0
                    ? round($m['fuel_rs'] / $withHim, 2) : null,
            ]);
        }
        return $out;
    }

    private function emptyClaims(): array
    {
        return ['fuel_rs' => 0.0, 'fuel_pending_rs' => 0.0, 'fuel_claims' => 0, 'metered_rs' => 0.0,
                'flat_rs' => 0.0, 'flat_claims' => 0, 'litres' => 0.0, 'maint_rs' => 0.0,
                'maint_pending_rs' => 0.0, 'dupe_flags' => 0, 'pending_count' => 0,
                'maint_regular_rs' => 0.0, 'maint_repair_rs' => 0.0, 'maint_other_rs' => 0.0,
                'early_service_count' => 0];
    }

    private function emptyTotals(): array
    {
        return ['fuel_rs' => 0, 'maint_rs' => 0, 'company' => null, 'own' => null, 'dupe_flags' => 0];
    }

    /**
     * Fleet totals split by bike type — the comparison the whole screen exists
     * to answer. Never merged into one number.
     */
    private function totals(array $riders, array $offRoster = []): array
    {
        $acc = ['company' => ['km' => 0, 'fuel' => 0.0, 'maint' => 0.0, 'riders' => 0, 'offduty' => 0, 'fuelled' => 0, 'unattrib_km' => 0],
                'own'     => ['km' => 0, 'fuel' => 0.0, 'maint' => 0.0, 'riders' => 0, 'offduty' => 0, 'fuelled' => 0, 'unattrib_km' => 0]];
        $fuel = 0.0; $maint = 0.0; $dupes = 0; $unattributed = 0.0; $unattributedWho = [];

        // People who spent on fuel/maintenance but are not delivery riders.
        // Their money still counts in the HEADLINE totals — it left the business
        // either way, and quietly dropping it would make the month look cheaper
        // than it was. It is only kept out of the per-km buckets, and named in
        // the unattributed line so the gap between the two is explained.
        foreach ($offRoster as $x) {
            $spend = ($x['fuel'] ?? 0) + ($x['maint'] ?? 0);
            if ($spend <= 0) continue;
            $fuel  += $x['fuel'] ?? 0;
            $maint += $x['maint'] ?? 0;
            $unattributed += $spend;
            $unattributedWho[] = $x['name'];
        }

        foreach ($riders as $r) {
            $fuel += $r['fuel_rs'];
            $maint += $r['maint_rs'];
            $dupes += $r['dupe_flags'];

            // ⭐⭐ POOL BY THE MACHINE THAT TURNED THE WHEELS (owner ruling Aug-13),
            //    not by a flag on the man. The same rider can spend half a month on
            //    a company bike and half on his own — pooling him whole puts real
            //    company kilometres in the "own" column and back again on the next
            //    handover. Where the registry knows the machine, each stretch and
            //    each claim goes to the pool its own bike belongs to; where it does
            //    not, the profile flag decides exactly as before.
            if (!empty($r['machines'])) {
                $placed = false;
                foreach ($r['machines'] as $mm) {
                    $mk = $mm['is_company'] ? 'company' : 'own';
                    $spend = $mm['fuel_rs'] + $mm['maint_rs'];

                    // Same rule as ever: money with no kilometres behind it cannot
                    // enter a per-km figure. It stays in the headline and is named.
                    if ($mm['work_km'] <= 0) {
                        if ($spend > 0) {
                            $unattributed += $spend;
                            $unattributedWho[] = $r['name'];
                        }
                        continue;
                    }
                    $acc[$mk]['km']          += $mm['work_km'];
                    $acc[$mk]['fuel']        += $mm['fuel_rs'];
                    $acc[$mk]['maint']       += $mm['maint_rs'];
                    $acc[$mk]['offduty']     += (int) $mm['offduty_km'];
                    // ⚠⚠ FUELLED KM IS A MONEY FIGURE, AND THE TWO BIKE TYPES ARE NOT
                    //   SYMMETRIC (Jul-28 owner ruling, nearly lost here). On a COMPANY
                    //   machine we buy the petrol for every kilometre it turns, commute
                    //   included. On an OWN bike we fund SHIFT km only — the rider pays
                    //   for his own commute. Feeding his off-duty km in here inflates
                    //   the own denominator and makes own bikes look artificially cheap,
                    //   which is the exact distortion the whole comparison exists to
                    //   avoid. Caught on the live page: own read 5.53 vs company 10.05.
                    $acc[$mk]['fuelled']     += $mm['is_company']
                        ? (int) $mm['km_with_him']
                        : (int) $mm['work_km'];
                    $acc[$mk]['unattrib_km'] += (int) ($mm['unattributed_km']
                                                + $mm['shared_km'] + $mm['transfer_km']);
                    $placed = true;
                }
                if ($placed) $acc[$r['bike'] === 'own' ? 'own' : 'company']['riders']++;

                // Anything he filed that no machine could be found for.
                $onMachines = 0.0;
                foreach ($r['machines'] as $mm) $onMachines += $mm['fuel_rs'] + $mm['maint_rs'];
                $loose = round(($r['fuel_rs'] + $r['maint_rs']) - $onMachines, 2);
                if ($loose > 0.01) {
                    $unattributed += $loose;
                    $unattributedWho[] = $r['name'];
                }
                continue;
            }

            $k = $r['bike'] === 'company' ? 'company' : ($r['bike'] === 'own' ? 'own' : null);

            // Spend with NO kilometres behind it (no meter readings, or someone
            // who isn't really a rider buying fuel in bulk) must not enter the
            // per-km maths — it would inflate the numerator against a smaller
            // denominator and make the honest riders look expensive. Report it
            // separately instead of hiding it.
            if ($k === null || $r['basis_km'] <= 0) {
                $unattributed += $r['fuel_rs'] + $r['maint_rs'];
                if (($r['fuel_rs'] + $r['maint_rs']) > 0) $unattributedWho[] = $r['name'];
                continue;
            }

            $acc[$k]['km']    += $r['basis_km'];
            $acc[$k]['fuel']  += $r['fuel_rs'];
            $acc[$k]['maint'] += $r['maint_rs'];
            $acc[$k]['offduty'] += (int) ($r['offduty_km'] ?? 0);
            $acc[$k]['fuelled'] += (int) ($r['fuelled_km'] ?? 0);
            $acc[$k]['unattrib_km'] += (int) ($r['unattributed_km'] ?? 0);
            $acc[$k]['riders']++;
        }

        $shape = function (array $a): array {
            return [
                'riders'        => $a['riders'],
                'km'            => $a['km'],
                'offduty_km'    => $a['offduty'],
                // Every km this company bought fuel for (work + commute on a
                // company bike; shift km only on an own bike).
                'fuelled_km'    => $a['fuelled'],
                // Km inside stretches containing a worked-but-unmetered day.
                'unattributed_km' => $a['unattrib_km'],
                'fuel_rs'       => round($a['fuel'], 2),
                'maint_rs'      => round($a['maint'], 2),
                // ⚠ COMPARISON RATE — productive km for BOTH bike types. This is the
                // only pair that may be set against each other: an own-bike rider is
                // paid per shift km and funds his own commute, so crediting a company
                // bike for the commute we pay for would flatter it (Jul-2026: −3% on
                // this basis, −23% on fuelled km — the second is not a saving, it is
                // the commute being counted as output).
                'rs_per_km'     => $a['km'] > 0 ? round($a['fuel'] / $a['km'], 2) : null,
                'rs_per_km_all' => $a['km'] > 0 ? round(($a['fuel'] + $a['maint']) / $a['km'], 2) : null,
                // RUNNING COST of the machines — fuel ÷ every km we fuelled. Real and
                // useful ("what does a km on these bikes cost"), but never a
                // like-for-like against own bikes.
                'rs_per_fuelled_km' => $a['fuelled'] > 0 ? round($a['fuel'] / $a['fuelled'], 2) : null,
                // Everything in — fuel + maintenance over the same kilometres.
                'rs_per_fuelled_km_all' => $a['fuelled'] > 0
                    ? round(($a['fuel'] + $a['maint']) / $a['fuelled'], 2) : null,
            ];
        };

        return [
            'fuel_rs'      => round($fuel, 2),
            'maint_rs'     => round($maint, 2),
            'dupe_flags'   => $dupes,
            'unattributed_rs'  => round($unattributed, 2),
            'unattributed_who' => array_values(array_unique($unattributedWho)),
            'company'      => $shape($acc['company']),
            'own'          => $shape($acc['own']),
        ];
    }

    // =================================================================
    // METERS
    // =================================================================

    /**
     * Why a day has no usable distance. Deliberately mirrors how the attendance
     * screen classifies a day, so the two screens never disagree about the same
     * date:
     *   leave   — an approved leave day. Nothing to measure.
     *   absent  — never checked in. Nothing to measure.
     *   missing — he WORKED but the meter is absent or unusable. This is the only
     *             one worth an alert.
     *   ok      — readings are usable.
     */
    private function dayStatus($r): string
    {
        if (!empty($r->leave_type)) return 'leave';

        // Same first test the attendance screen makes ("didn't work today"):
        // neither a check-in nor a check-out means there was no ride to measure.
        $in  = !empty($r->login_time);
        $out = !empty($r->logout_time);
        if (!$in && !$out) return 'absent';

        if ($this->isSaneRow($r)) return 'ok';

        // ⭐ A reading is only "missing" once it was DUE — start once he checked
        // in, end once he checked out. A rider still on shift has no end meter
        // yet and must not be alerted for it; the attendance screen applies
        // exactly this rule, and the two must agree about the same date.
        if ($in && $r->meter_start === null) return 'missing';
        if ($out && $r->meter_end === null) return 'missing';

        // Readings present but implausible (typo / another bike) — always wrong.
        if ($r->meter_start !== null && $r->meter_end !== null) return 'missing';

        return 'in_progress';        // checked in, no check-out yet — nothing due
    }

    /** Finer-grained reason for the day list — what exactly is absent. */
    private function dayDetail($r): string
    {
        $s = $this->dayStatus($r);
        if ($s !== 'missing') return $s;
        $in  = !empty($r->login_time);
        $out = !empty($r->logout_time);
        if ($in && $out && $r->meter_start === null && $r->meter_end === null) return 'no_reading';
        if ($in && $r->meter_start === null) return 'no_start';
        if ($out && $r->meter_end === null) return 'no_end';
        return 'unusable';           // present but implausible (typo / wrong bike)
    }

    private function isSaneDay($start, $end): bool
    {
        if ($start === null || $end === null) return false;
        $km = (int) $end - (int) $start;
        return (int) $start > self::MIN_METER && $km >= 0 && $km <= self::MAX_DAY_KM;
    }

    /**
     * ⭐⭐ THE SAME TEST, BUT IT CAN SEE THE MACHINE (13-Sep-2026).
     *
     * ⚠⚠ `isSaneDay()` only ever compared two NUMBERS, so a day that began on one bike and
     *    ended on another was "sane" whenever the two odometers happened to be close.
     *    `dayStatus()` has always meant to catch that — its own comment reads
     *    *"Readings present but implausible (typo / another bike)"* — but it could only detect
     *    it by implausibility, which is luck, not a rule. Measured on the replica before this:
     *
     *        start 2,300 on EDN-198  →  end 2,570 on DCR-799
     *        work_km = 270   meter_ok = true      ← a distance belonging to NEITHER machine
     *        machines_today: EDN-198 2300 → NULL,  DCR-799 NULL → 2570   (both correct)
     *
     *    Rajab's 7,610 → 75,484 was caught only because a bike and a van are 68,000 km apart.
     *    Two company bikes 270 km apart sail straight through.
     *
     * ⭐ So the stamps decide, via the ONE helper every other surface now uses. A split day
     *   becomes `status = missing` / `detail = unusable` — exactly the label `dayStatus` was
     *   already written to give it — which in turn means it contributes no `work_km`, does not
     *   seed `prevEnd` for the next day's gap (its end reading is on a machine the day did not
     *   start on), and correctly dirties that gap so the km across it are reported as
     *   UNATTRIBUTED rather than quietly credited as off-duty.
     *
     * ⚠ Schema-guarded by `MeterPairHelper::isSplit()`, which is false unless BOTH ends are
     *   stamped and differ — so on a DB without the stamp columns nothing changes at all.
     */
    private function isSaneRow($r): bool
    {
        return $this->isSaneDay($r->meter_start ?? null, $r->meter_end ?? null)
            && !MeterPairHelper::isSplit($r);
    }

    private function isSaneGap(int $prevEnd, int $nextStart): bool
    {
        $gap = $nextStart - $prevEnd;
        return $gap >= 0 && $gap <= self::MAX_GAP_KM;
    }

    /** Work km + off-duty km per rider for the month, typos excluded. */
    private function meterAggregates(string $from, string $to): array
    {
        $rows = DB::table('t_ops_attendance')
            ->select('user_id', 'attendance_date', 'meter_start', 'meter_end',
                     'meter_home', 'login_time', 'logout_time', 'leave_type')
            // ⭐ The machine stamps — `isSaneRow()` cannot see a two-machine day without them.
            ->when(VehicleService::stampsAvailable(),
                fn ($q) => $q->addSelect(['meter_start_vehicle_id', 'meter_end_vehicle_id']))
            ->whereBetween('attendance_date', [$from, $to])
            ->orderBy('user_id')->orderBy('attendance_date')
            ->get();

        $out = [];
        $prevEnd = [];
        // Set per rider while a gap is "dirty": a day he WORKED but left no usable
        // meter sits inside the stretch, so the km across it are part work and part
        // commute and cannot be told apart. See the unattributed note below.
        $dirtyGap = [];
        $today = Carbon::today()->format('Y-m-d');
        foreach ($rows as $r) {
            $uid = (int) $r->user_id;
            if (!isset($out[$uid])) {
                $out[$uid] = ['days' => 0, 'work_km' => 0, 'offduty_km' => 0,
                              'no_meter_days' => 0, 'incl_ride_home_days' => 0,
                              'unattributed_km' => 0, 'open_days' => 0];
            }

            if (!$this->isSaneRow($r)) {
                // ⚠ Only a day he ACTUALLY WORKED can be "missing a meter reading".
                // On leave or absent there is no ride to measure, so counting those
                // produced alerts for days the rider was never on the bike — and it
                // disagreed with the attendance screen, which treats leave/absent as
                // their own states rather than as failures.
                $st = $this->dayStatus($r);
                if ($st === 'missing') {
                    $out[$uid]['no_meter_days']++;
                    // He rode that day, so the next usable gap contains work km.
                    $dirtyGap[$uid] = true;
                }
                // A PAST day still "in progress" = checked in, never checked out.
                // Kept as in_progress on purpose (owner ruling: the team must go and
                // close it, not have it silently reclassified) — but it is counted
                // here so it is visible, and it dirties the gap for the same reason:
                // he worked, and no end reading was ever recorded.
                if ($st === 'in_progress' && $r->attendance_date < $today) {
                    $out[$uid]['open_days']++;
                    $dirtyGap[$uid] = true;
                }
                continue;
            }

            // ⭐ OWNER RULING (Jul-27): for a company bike the ride until he reaches
            //    home and enters the meter IS part of the shift. The meter-out is
            //    taken at home and written to meter_end, so start→end already spans
            //    exactly that — nothing to separate out. The ONLY off-duty stretch
            //    is meter_end → next day's meter_start. Kept as a plain count for
            //    the day list; it is not an exception to flag.
            if ($r->meter_home !== null && (int) $r->meter_home === (int) $r->meter_end) {
                $out[$uid]['incl_ride_home_days']++;
            }

            $s = (int) $r->meter_start; $e = (int) $r->meter_end;
            $out[$uid]['days']++;
            $out[$uid]['work_km'] += $e - $s;

            if (isset($prevEnd[$uid]) && $this->isSaneGap($prevEnd[$uid], $s)) {
                // ⭐ A gap that SPANS a day he worked without a usable meter is NOT
                //    off-duty. Kanan's Jul-1 → Jul-3 stretch is 198 km, but Jul-2 is
                //    inside it and he delivered 12 orders that day — most of those km
                //    are work. Calling the whole stretch "off-duty" both inflates the
                //    commute figure the company-bike argument rests on AND removes
                //    real work km from the Rs/km denominator, making the rider look
                //    dearer than he is. It cannot be split, so it is reported on its
                //    own as unattributed — the same honesty rule already applied to
                //    fuel that can't be tied to any kilometres.
                $gapKm = $s - $prevEnd[$uid];
                if (!empty($dirtyGap[$uid])) {
                    $out[$uid]['unattributed_km'] += $gapKm;
                } else {
                    $out[$uid]['offduty_km'] += $gapKm;
                }
            }
            $dirtyGap[$uid] = false;
            $prevEnd[$uid] = $e;
        }
        return $out;
    }

    // =================================================================
    // CLAIMS
    // =================================================================

    /** Fuel + maintenance money per rider, with duplicate detection. */
    private function claimAggregates(string $from, string $to): array
    {
        $rows = $this->claimRows($from, $to);

        $out = [];
        $byRiderDay = [];      // [uid][date] => list of rows, for duplicate rules
        foreach ($rows as $r) {
            $uid = (int) $r->requester_user_id;
            if (!isset($out[$uid])) $out[$uid] = $this->emptyClaims();

            $approved = $r->status === 'approved';
            $pending  = $r->status === 'pending';
            $amount   = (float) $r->amount;

            if ($r->expense_category === self::CAT_FUEL) {
                if ($approved) {
                    $out[$uid]['fuel_rs'] += $amount;
                    $out[$uid]['fuel_claims']++;
                    if ($r->meter_distance !== null) $out[$uid]['metered_rs'] += $amount;
                    else { $out[$uid]['flat_rs'] += $amount; $out[$uid]['flat_claims']++; }
                    if ($r->litres !== null) $out[$uid]['litres'] += (float) $r->litres;
                } elseif ($pending) {
                    $out[$uid]['fuel_pending_rs'] += $amount;
                    $out[$uid]['pending_count']++;
                }
                if ($approved || $pending) {
                    $byRiderDay[$uid][$r->d][] = $r;
                }
            } else {
                if ($approved) {
                    $out[$uid]['maint_rs'] += $amount;
                    // Split by what was done. Regular service is the scheduled
                    // work that resets the due clock; repair is everything ad-hoc.
                    // Rows filed before service_type existed have none — they are
                    // kept in their own bucket rather than guessed into either.
                    $out[$uid][$this->maintBucket($r->service_type)] += $amount;
                } elseif ($pending) {
                    $out[$uid]['maint_pending_rs'] += $amount;
                    $out[$uid]['pending_count']++;
                }
            }
        }

        foreach ($byRiderDay as $uid => $days) {
            foreach ($days as $date => $list) {
                $out[$uid]['dupe_flags'] += count($this->dupeFlags($list));
            }
        }

        // Regular services done before the bike's schedule was up. Computed per
        // rider from that rider's own rows (kmSinceLastService assumes a single
        // rider's claims, so the grouping has to happen here).
        $byRider = collect($rows)->groupBy('requester_user_id');
        foreach ($byRider as $uid => $riderRows) {
            $uid = (int) $uid;
            if (!isset($out[$uid])) continue;
            $gaps = $this->kmSinceLastService($uid, $riderRows, null);
            $out[$uid]['early_service_count'] = count(array_filter(
                $gaps, fn ($g) => ($g['early_by'] ?? null) !== null
            ));
        }

        return $out;
    }

    /** One month of fuel/maintenance requests, newest last. */
    private function claimRows(string $from, string $to)
    {
        // ⭐ WHICH MACHINE the claim was filed against. Without it the rider lens
        //   renders every claim anonymously, so on a day he rode two bikes his two
        //   fuel claims looked identical and neither said which tank it filled.
        // ⚠⚠ SCHEMA-GUARDED. `vehicle_id` was hand-applied in a SQL batch; naming it
        //   unconditionally in a raw select would take the whole Bikes screen down
        //   with "Unknown column" on any server that has not run it. Every other
        //   reader of this column guards it the same way.
        $vehicleCol = Schema::hasColumn('t_req_master', 'vehicle_id')
            ? 'vehicle_id,' : 'NULL AS vehicle_id,';

        return DB::table('t_req_master')
            ->selectRaw("id, requester_user_id, amount, expense_category, status,
                         meter_distance, petrol_rate, meter_at_fill, litres, service_type,
                         service_due_km,
                         attendance_id, attachments, created_at, title, description,
                         requires_level_1, level_1_status, requires_level_2, level_2_status,
                         -- What the claim was FILED against. The approve strip
                         -- pre-selects this instead of its own first option, which
                         -- used to overwrite the filer's choice with NF Cash unless
                         -- the approver happened to touch the dropdown.
                         payment_source_account_id, receiving_account_id,
                         maintenance_type_id,
                         {$vehicleCol}
                         COALESCE(expense_date, DATE(created_at)) AS d")
            ->whereIn('expense_category', [self::CAT_FUEL, self::CAT_MAINT])
            ->whereRaw("COALESCE(expense_date, DATE(created_at)) BETWEEN ? AND ?", [$from, $to])
            ->orderBy('requester_user_id')->orderBy('created_at')
            ->get();
    }

    /**
     * Duplicate/over-claim detection for one rider on one day.
     *
     * Derived from what actually happened in June: three 500-rupee claims filed
     * within two minutes; a flat cash claim on a day already covered by a
     * metered claim. Returns a flag per SUSPECT row (never the first one).
     */
    private function dupeFlags(array $list): array
    {
        $flags = [];
        $hasMetered = false;
        foreach ($list as $r) {
            if ($r->meter_distance !== null && $r->status === 'approved') $hasMetered = true;
        }

        $seenFlat = 0;
        foreach ($list as $i => $r) {
            if ($r->meter_distance !== null) continue;      // metered rows are self-auditing
            $seenFlat++;

            // (a) identical amount, minutes apart = a double-tap on the button
            foreach ($list as $j => $o) {
                if ($j >= $i || $o->meter_distance !== null) continue;
                if ((float) $o->amount === (float) $r->amount &&
                    abs(strtotime($r->created_at) - strtotime($o->created_at)) <= self::DOUBLETAP_SECS) {
                    $flags[$r->id] = 'double_tap';
                    continue 2;
                }
            }
            // (b) cash claim on a day the meter already paid for
            if ($hasMetered) { $flags[$r->id] = 'flat_on_metered_day'; continue; }
            // (c) simply the 2nd+ cash claim of the day
            if ($seenFlat > 1) $flags[$r->id] = 'second_same_day';
        }
        return $flags;
    }

    // =================================================================
    // SERVICE (oil change) STATE
    // =================================================================

    /** Current odometer per rider — the max plausible reading we know of. */
    private function currentMeters(array $userIds): array
    {
        if (!$userIds) return [];

        // ⚠ PLAUSIBLE rows only. A single typo'd row (one rider has 26,261 → 56,403
        // in a day) would otherwise become "current odometer" forever and make every
        // service chip read wildly overdue. Same bounds as isSaneDay().
        $att = DB::table('t_ops_attendance')
            ->selectRaw('user_id, MAX(GREATEST(COALESCE(meter_end,0), COALESCE(meter_home,0), COALESCE(meter_start,0))) AS m')
            ->whereIn('user_id', $userIds)
            ->whereRaw('meter_start > ' . self::MIN_METER)
            ->whereRaw('(meter_end IS NULL OR (meter_end >= meter_start AND meter_end - meter_start <= ' . self::MAX_DAY_KM . '))')
            ->whereRaw('(meter_home IS NULL OR (meter_home >= meter_start AND meter_home - meter_start <= 700))')
            ->groupBy('user_id')->pluck('m', 'user_id')->toArray();

        $fill = DB::table('t_req_master')
            ->selectRaw('requester_user_id, MAX(meter_at_fill) AS m')
            ->whereIn('requester_user_id', $userIds)
            ->whereNotNull('meter_at_fill')
            ->groupBy('requester_user_id')->pluck('m', 'requester_user_id')->toArray();

        $out = [];
        foreach ($userIds as $uid) {
            $v = max((int) ($att[$uid] ?? 0), (int) ($fill[$uid] ?? 0));
            if ($v > self::MIN_METER) $out[$uid] = $v;
        }
        return $out;
    }

    /** Due / due-soon / overdue per bike. Null when we cannot tell. */
    /**
     * Per rider: the interval of the type of their most recent APPROVED
     * clock-resetting service. Empty for anyone whose last service was untyped
     * (every rider before Aug-2026) — the caller then falls back as before.
     *
     * Only clock-resetting types count: a brake-shoe job is real maintenance but
     * it is not what "next service due" measures, so it must not set the schedule
     * any more than it resets the clock.
     *
     * @return array<int, int>  userId => interval_km
     */
    private function lastServiceTypeIntervals(array $userIds): array
    {
        if (!$userIds) {
            return [];
        }
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('t_fleet_maintenance_types')) {
                return [];
            }
            // The newest qualifying claim per rider, then that type's interval.
            $rows = DB::table('t_req_master as r')
                ->join('t_fleet_maintenance_types as t', 't.id', '=', 'r.maintenance_type_id')
                ->whereIn('r.requester_user_id', $userIds)
                ->where('r.expense_category', 'Maintenance')
                ->where('r.status', 'approved')
                ->where('t.resets_service_clock', 1)
                ->where('t.interval_km', '>', 0)
                ->orderBy('r.requester_user_id')
                ->orderByDesc('r.meter_at_fill')
                ->get(['r.requester_user_id as uid', 't.interval_km']);

            $out = [];
            foreach ($rows as $row) {
                // Ordered newest-first per rider, so the first one wins.
                if (!isset($out[(int) $row->uid])) {
                    $out[(int) $row->uid] = (int) $row->interval_km;
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function serviceState(array $userIds): array
    {
        $profiles = $this->profiles();
        $current  = $this->currentMeters($userIds);
        // ⚠ 1200, matching VehicleService — this default now feeds the SAME engine as
        //   the vehicle card, and two literals (this was 3000) would make the chip
        //   and the card disagree by 1,800 km on the day the config row ever goes
        //   missing. The config row exists on prod, so today this changes nothing.
        $default  = (new ServiceIntervalResolver())->companyDefault();

        // ⭐ Aug-2026: the schedule follows THE WORK LAST DONE. If the bike had the
        // 2,500 km oil+tuning, it is due in 2,500 — not in whatever the bike's
        // generic override says. Without this the chip kept showing the old single
        // interval while the frozen `service_due_km` on the request used the type's,
        // and the two disagreed on the same screen.
        // Precedence: last clock-resetting service's type → per-bike override →
        // company default. The override still governs every bike with no typed
        // service yet, which today is all of them.
        $lastTypeInterval = $this->lastServiceTypeIntervals($userIds);

        // ⭐⭐ Aug-16: THE MACHINE ANSWERS FIRST. This chip used to be computed purely
        //    from the RIDER's profile stamp, which is one of the three stores of "last
        //    service" that drifted apart — see VehicleService::overallServiceStateFor.
        //    When the registry can name the machine this rider is holding, the chip is
        //    now the SAME derivation the vehicle card, the schedule panel and the
        //    service alerts read, so a manager and a rider can no longer be shown
        //    different figures for one bike.
        //
        // ⚠ The old profile math is kept verbatim below for anyone the registry
        //   cannot place — riders with no registered machine (Farooq files flat petrol
        //   claims and has never held one). Removing their chip would be a regression
        //   for exactly the people the registry says nothing about.
        $veh      = new VehicleService();
        $resolver = new VehicleResolver();
        $today    = date('Y-m-d');

        $out = [];
        foreach ($userIds as $uid) {
            $p = $profiles[$uid] ?? null;
            if (!$p) continue;

            $now = $current[$uid] ?? null;

            try {
                $vid = $veh->available() ? $resolver->currentVehicleFor($uid, $today) : null;
                if ($vid) {
                    // ⚠⚠ THE MACHINE'S ODOMETER, NOT THE RIDER'S. `$now` above is this
                    //   RIDER's highest reading across every bike he has ever held; the
                    //   machine's is window-scoped to the days it was actually his.
                    //   They agree until someone swaps bikes and then diverge — and
                    //   measuring a machine's service clock with a rider's meter would
                    //   reintroduce this exact bug on the exact day it matters most.
                    //   Falls back to the rider's figure only if the machine has none.
                    $vMeter = $veh->currentMeterFor((int) $vid) ?? $now;
                    $s = $veh->overallServiceStateFor($vid, $vMeter, null, $uid, $default);
                    // ⚠ The STORED override rides along beside the DERIVED interval —
                    //   the "⚙️ This bike's schedule" prompt must pre-fill what is
                    //   actually saved, or opening it would offer the due job's
                    //   schedule as this bike's override and save a value nobody set.
                    // ⚠⚠ THE MACHINE COLUMN ONLY — do NOT fall back to the rider's
                    //    legacy override here, however tempting.
                    //
                    //    This value pre-fills "⚙️ This bike's schedule", and that prompt
                    //    now WRITES the machine override. A rider-profile override is
                    //    inert for any bike with typed history (it is consulted only in
                    //    the legacy fallback), so surfacing it would show a number that
                    //    is not in force and — the moment the manager pressed OK —
                    //    materialise it as a real machine override he never chose. That
                    //    is the same trap as pre-filling the DERIVED interval, one level
                    //    down. Blank means "this bike has no schedule of its own", which
                    //    is the truth.
                    //
                    //    Riders with NO registered machine take the legacy branches
                    //    below, which DO report the profile override — there it is the
                    //    setting actually in force.
                    $ovr = DB::table(VehicleService::T_VEHICLE)->where('id', $vid)
                        ->value('service_interval_km');
                    $out[$uid] = [
                        'state'              => $s['state'],
                        'interval_km'        => $s['interval_km'],
                        'interval_override'  => $ovr !== null ? (int) $ovr : null,
                        'current_meter'      => $vMeter,
                        'last_service_meter' => $s['last_service_meter'],
                        'last_service_at'    => $s['last_service_at'],
                        'since_km'           => $s['since_km'],
                        'due_in_km'          => $s['due_in_km'],
                        // Which job is soonest due — the chip can name it.
                        'due_type_name'      => $s['due_type_name'] ?? null,
                        /**
                         * ⭐ …and in ITS OWN UNIT (29-Sep-2026). The chip is now the most urgent of
                         *   EVERY scheduled job, so it can be a time-based one — `due_in_km` is then
                         *   null and the pill read "overdue 0 km". The engine's own phrase and the
                         *   time fields ride along (additive; km rows read exactly as before).
                         */
                        'basis'              => $s['basis'] ?? 'km',
                        'is_time_based'      => !empty($s['is_time_based']),
                        'interval_days'      => $s['interval_days'] ?? null,
                        'interval_label'     => $s['interval_label'] ?? null,
                        'due_in_days'        => $s['due_in_days'] ?? null,
                        'due_at_date'        => $s['due_at_date'] ?? null,
                        'due_text'           => $s['due_text'] ?? null,
                        'vehicle_id'         => $vid,
                    ];
                    continue;
                }
            } catch (\Throwable $e) {
                // fall through to the profile math — a chip must never break the sheet
            }

            // ⭐ The shared chain (Aug-27 2026) — the rider-keyed fallback for someone the
            //   registry cannot place. Same order as every other surface: the job's own
            //   schedule, then the bike's, then his, then the company default.
            $interval = (new ServiceIntervalResolver())->intervalFor(
                null, (int) ($lastTypeInterval[$uid] ?? 0), $uid
            );
            $last     = $p->last_service_meter !== null ? (int) $p->last_service_meter : null;

            // ⚠ Both legacy branches carry `due_type_name`/`vehicle_id` as null so the
            //   row shape is identical whichever path produced it — a consumer must
            //   never have to know which branch it is reading.
            if ($interval <= 0 || $now === null || $last === null) {
                $out[$uid] = [
                    'state' => 'unknown', 'interval_km' => $interval,
                    'current_meter' => $now, 'last_service_meter' => $last,
                    'last_service_at' => $p->last_service_at,
                    'since_km' => null, 'due_in_km' => null,
                    'due_type_name' => null, 'vehicle_id' => null,
                    'interval_override' => $p->service_interval_km !== null
                        ? (int) $p->service_interval_km : null,
                ];
                continue;
            }

            $since = $now - $last;
            $dueIn = $interval - $since;
            $out[$uid] = [
                'state'         => ServiceIntervalResolver::stateFor($dueIn),
                'interval_km'   => $interval,
                'current_meter' => $now,
                'last_service_meter' => $last,
                'last_service_at'    => $p->last_service_at,
                'since_km'      => $since,
                'due_in_km'     => $dueIn,
                'due_type_name' => null,
                'vehicle_id'    => null,
                'interval_override' => $p->service_interval_km !== null
                    ? (int) $p->service_interval_km : null,
            ];
        }
        return $out;
    }

    // =================================================================
    // RIDER DETAIL (datewise)
    // =================================================================

    /** One rider's month: a row per day, plus the claims and photos on it. */
    public function riderMonth(int $userId, string $month): array
    {
        [$from, $to] = $this->bounds($month);

        $name = DB::table('t_sys_user')->where('id', $userId)->value('fullname') ?: 'Rider';
        $profiles = $this->profiles();
        $p = $profiles[$userId] ?? null;
        // bool, not int — see the note in monthSummary(); `=== 1` is always false.
        $isCompany = $p ? ((int) $p->company_bike === 1) : null;

        // --- claims, grouped by their expense day ---
        // Approved + pending ONLY. Rejected/cancelled rows are dead money — showing
        // them clutters the review, and worse, a rejected claim sitting on a day
        // would trigger a false "second cash claim" flag on the honest one. The
        // month totals were always approved-only; the datewise list now matches.
        $claimRows = $this->claimRows($from, $to)
            ->where('requester_user_id', $userId)
            ->filter(fn ($r) => in_array($r->status, ['approved', 'pending'], true));
        $byDay = [];
        foreach ($claimRows as $r) $byDay[$r->d][] = $r;

        $flagsById = [];
        foreach ($byDay as $date => $list) {
            $fuelOnly = array_values(array_filter($list, fn ($x) => $x->expense_category === self::CAT_FUEL));
            $flagsById += $this->dupeFlags($fuelOnly);
        }

        // ⭐ Km since the PREVIOUS fill — the number the approver actually wants:
        //    "he last filled at 32,484; this request is at 32,607, so this tank is
        //    for 123 km." Walked in filing order (day, then time). The chain seeds
        //    from the last fill BEFORE this month so the 1st of the month still
        //    gets a delta. Implausible jumps (≤0 or >2000 km — a typo or a swapped
        //    bike) are marked odd rather than shown as a real distance.
        $sinceById = $this->kmSinceLastFill($userId, $claimRows, $from);
        $trail = $this->approvalTrail($claimRows->pluck('id')->all());
        $approvalNotes   = $trail['notes'];
        $approvalActions = $trail['actions'];
        $serviceGap = $this->kmSinceLastService($userId, $claimRows, $isCompany);
        // Where the bike stands against its schedule RIGHT NOW. Used to explain a
        // still-pending service ("the bike is 317 km past due") — after approval
        // the clock resets, so this can only ever describe the unapproved case.
        $svcState = $this->serviceState([$userId])[$userId] ?? null;

        // --- attendance days ---
        $att = DB::table('t_ops_attendance')
            ->select('attendance_date', 'meter_start', 'meter_end', 'meter_home',
                     'login_time', 'logout_time', 'leave_type')
            // ⭐ Same reason as meterAggregates(): without the stamps a day that started on one
            //   bike and ended on another looks like an ordinary day to `isSaneRow()`.
            ->when(VehicleService::stampsAvailable(),
                fn ($q) => $q->addSelect(['meter_start_vehicle_id', 'meter_end_vehicle_id']))
            ->where('user_id', $userId)
            ->whereBetween('attendance_date', [$from, $to])
            ->orderBy('attendance_date')->get();

        $days = [];
        $prevEnd = null;
        $prevDate = null;
        // Mirrors meterAggregates(): a worked-but-unmetered day (or a past day never
        // closed) makes the NEXT gap part-work, so it can't be called off-duty.
        $dirtyGap = false;
        $todayYmd = Carbon::today()->format('Y-m-d');
        foreach ($att as $a) {
            $date = $a->attendance_date;
            $sane = $this->isSaneRow($a);
            $offduty = null;
            $offdutySince = null;
            $unattributed = null;
            if ($sane && $prevEnd !== null && $this->isSaneGap($prevEnd, (int) $a->meter_start)) {
                $gapKm = (int) $a->meter_start - $prevEnd;
                // The gap is measured from the last USABLE reading, which may be
                // several days back when readings were missed. Say which day it
                // runs from, so "+191 km off-duty" is never read as one evening.
                if ($prevDate !== null &&
                    Carbon::parse($prevDate)->diffInDays(Carbon::parse($date)) > 1) {
                    $offdutySince = $prevDate;
                }
                // Contains a day he rode without a usable meter → part work, part
                // commute, unsplittable. Reported as unattributed rather than
                // silently credited to either side.
                if ($dirtyGap) { $unattributed = $gapKm; }
                else           { $offduty = $gapKm; }
            }
            if (!$sane) {
                $st0 = $this->dayStatus($a);
                if ($st0 === 'missing' || ($st0 === 'in_progress' && $date < $todayYmd)) {
                    $dirtyGap = true;
                }
            } else {
                $dirtyGap = false;
            }
            $days[$date] = [
                'date'        => $date,
                'meter_start' => $a->meter_start !== null ? (int) $a->meter_start : null,
                'meter_end'   => $a->meter_end !== null ? (int) $a->meter_end : null,
                'work_km'     => $sane ? (int) $a->meter_end - (int) $a->meter_start : null,
                'offduty_km'  => $isCompany === true ? $offduty : null,
                'offduty_since' => $isCompany === true ? $offdutySince : null,
                // Km across a stretch that contains a worked-but-unmetered day.
                'unattributed_km' => $isCompany === true ? $unattributed : null,
                'meter_ok'    => $sane,
                // Why this day has no distance — so the list can say "on leave"
                // instead of implying the rider failed to record something.
                'status'      => $this->dayStatus($a),
                'detail'      => $this->dayDetail($a),
                // The meter-out was taken at home, so this day's km include the
                // ride home (the office-close reading is not stored separately).
                'incl_ride_home' => $a->meter_home !== null && (int) $a->meter_home === (int) $a->meter_end,
                'claims'      => [],
            ];
            if ($sane) { $prevEnd = (int) $a->meter_end; $prevDate = $date; }
        }

        // ⭐⭐ THE MACHINE'S VERSION OF THE SAME DAYS. Where the registry can say
        //    which bike a reading came from, its legs replace the rider-keyed ones
        //    computed above — so a handover day reads "187 km shared with Danish"
        //    instead of adding 187 km to a column with Waseem's name on it.
        $machineDays = $this->overlayRiderDays($userId, $month, $days, $isCompany);
        $days        = $machineDays['days'];
        $riderMachines = $machineDays['machines'];

        // --- attach claims (a claim can land on a day with no attendance row) ---
        // Resolved once for the whole month rather than per claim row.
        $maintTypes = app(\App\Services\Riders\MaintenanceTypeService::class);
        // ⭐ A bill that pays for several jobs of one visit is labelled with all of them
        //   (29-Sep-2026). Fetched for the whole month at once so labelFor() below reads a memo.
        try {
            $claimIds = [];
            foreach ($byDay as $list) { foreach ($list as $c) { if (!empty($c->id)) $claimIds[] = (int) $c->id; } }
            app(ServiceRecordService::class)->jobLabelsForClaims($claimIds);
        } catch (\Throwable $e) { /* labels fall back to the lead job */ }
        // Plate/name for a stamped claim. Prefers the machines already resolved for
        // his month (no extra query), falling back to the resolver's cached lookup for
        // a machine he did not otherwise ride this month.
        $machineLabels = [];
        foreach ($riderMachines as $m) { $machineLabels[(int) $m['vehicle_id']] = $m['label']; }
        $resolverForLabels = new \App\Services\Riders\VehicleResolver();
        $vehLabel = function (int $vid) use (&$machineLabels, $resolverForLabels) {
            if (!array_key_exists($vid, $machineLabels)) {
                $machineLabels[$vid] = $resolverForLabels->labelFor($vid);
            }
            return $machineLabels[$vid];
        };
        $vehIsCompany = function (int $vid) use ($resolverForLabels) {
            $v = $resolverForLabels->vehicle($vid);
            return $v ? ((int) $v->is_company === 1) : false;
        };
        // ⭐ The machine in WORDS — "Company bike · EDN-198" / "Personal bike · no plate
        //   on file" — composed ONCE in VehicleResolver so this popup, the Daily Closing
        //   Requests pane and the phone all read identically. `vehicle_label` above stays
        //   exactly as it was (older clients render from it); these keys are additive.
        $vehChip = function (?int $vid) use ($resolverForLabels) {
            $c = $vid !== null ? $resolverForLabels->machineChip($vid) : null;
            return [
                'vehicle_is_company' => $c ? $c['is_company'] : null,
                'vehicle_class'      => $c ? $c['vtype']      : null,
                'vehicle_icon'       => $c ? $c['icon']       : null,
                'vehicle_kind'       => $c ? $c['kind']       : null,
                'vehicle_plate'      => $c ? $c['plate']      : null,
                'vehicle_text'       => $c ? $c['text']       : null,
                'vehicle_plate_note' => $c ? $c['plate_note'] : null,
            ];
        };
        foreach ($byDay as $date => $list) {
            if (!isset($days[$date])) {
                // A claim dated on a day with no attendance row at all (e.g. filed
                // for a day off). Shown, but never counted as a missing meter.
                $days[$date] = ['date' => $date, 'meter_start' => null, 'meter_end' => null,
                                'work_km' => null, 'offduty_km' => null, 'offduty_since' => null,
                                'meter_ok' => false, 'status' => 'no_attendance',
                                'detail' => 'no_attendance', 'incl_ride_home' => false, 'claims' => []];
            }
            foreach ($list as $r) {
                $days[$date]['claims'][] = $vehChip($r->vehicle_id !== null ? (int) $r->vehicle_id : null) + [
                    'id'        => (int) $r->id,
                    'kind'      => $r->expense_category === self::CAT_FUEL ? 'fuel' : 'maintenance',
                    'amount'    => (float) $r->amount,
                    'status'    => $r->status,
                    'source'    => $r->meter_distance !== null ? 'meter' : 'manual',
                    'meter_distance' => $r->meter_distance !== null ? (float) $r->meter_distance : null,
                    'petrol_rate'    => $r->petrol_rate !== null ? (float) $r->petrol_rate : null,
                    'meter_at_fill'  => $r->meter_at_fill !== null ? (int) $r->meter_at_fill : null,
                    'km_since_fill'  => ($sinceById[$r->id]['km'] ?? null) > 0
                        ? $sinceById[$r->id]['km'] : null,
                    'km_since_fill_odd' => ($sinceById[$r->id]['km'] ?? null) === -1,
                    // ⭐ WHOSE tank this is measured from, when it was not his own.
                    //   "962 km since last fill" on a bike somebody else filled last
                    //   night is the exact confusion this chip caused; naming the
                    //   previous filler is what makes the number readable. Extra keys
                    //   only — an older APK simply ignores them and shows the number.
                    'km_since_fill_by'   => $sinceById[$r->id]['by'] ?? null,
                    'km_since_fill_on'   => $sinceById[$r->id]['on'] ?? null,
                    'km_since_fill_from' => $sinceById[$r->id]['from_meter'] ?? null,
                    'litres'         => $r->litres !== null ? (float) $r->litres : null,
                    'service_type'   => $r->service_type,
                    // The manager's own label for this job ("Brake Shoe"), falling back
                    // to the bucket name on rows filed before types existed.
                    'maintenance_type_id' => $r->maintenance_type_id !== null ? (int) $r->maintenance_type_id : null,
                    'maintenance_type'    => $maintTypes->labelFor($r->maintenance_type_id ?? null, $r->service_type, $r->id ?? null),
                    // ⭐⭐ WHICH BIKE THIS MONEY WAS FOR. On a day he rode two machines
                    //   his claims rendered identically — two fuel rows, two different
                    //   tanks, one anonymous list. The vehicle lens never had this
                    //   problem because it is machine-scoped by construction.
                    // ⚠ ONLY from the claim's OWN stamp. The day's machine is deliberately
                    //   NOT a fallback: on the very day this matters the day has TWO, so
                    //   inferring would print a confident wrong answer. Unstamped stays
                    //   null and the screen says "machine not recorded" — the same words
                    //   the vehicle card already uses.
                    'vehicle_id'      => $r->vehicle_id !== null ? (int) $r->vehicle_id : null,
                    'vehicle_label'   => $r->vehicle_id !== null ? $vehLabel((int) $r->vehicle_id) : null,
                    'vehicle_stamped' => $r->vehicle_id !== null,
                    // Regular services only: how far the bike ran since the last
                    // one, and by how much this beat the schedule.
                    'km_since_service' => $serviceGap[$r->id]['since'] ?? null,
                    'service_early_by' => $serviceGap[$r->id]['early_by'] ?? null,
                    'service_late_by'  => $serviceGap[$r->id]['late_by'] ?? null,
                    'service_interval' => $serviceGap[$r->id]['interval'] ?? null,
                    // A PENDING regular service can't have reset the clock yet, so
                    // the bike is still overdue while it waits. Showing that on the
                    // row being approved answers "why is this bike red?".
                    'overdue_now_km' => ($r->status === 'pending' && $r->expense_category === self::CAT_MAINT
                        && in_array($r->service_type, ['oil_change', 'general'], true)
                        && ($svcState['state'] ?? null) === 'overdue')
                        ? abs((int) $svcState['due_in_km']) : null,
                    // How far off schedule the bike was AT THE MOMENT this was
                    // approved, frozen then. Negative = overdue by that many km.
                    // Without it the evidence disappears the instant approval
                    // resets the clock, and the record can no longer say why.
                    'service_due_km_at_approval' => $r->service_due_km !== null ? (int) $r->service_due_km : null,
                    'filed_at'  => $r->created_at,
                    'note'      => $r->description,
                    // What the approver typed when approving/rejecting — real
                    // context like "Tyre Puncture" that only lives on the
                    // approval row, and was invisible everywhere until now.
                    'approval_notes' => $approvalNotes[$r->id] ?? [],
                    // Who signed this off and from which screen.
                    'approval_actions' => $approvalActions[$r->id] ?? [],
                    'flag'      => $flagsById[$r->id] ?? null,
                    'photo'     => $this->attachmentUrl($r->attachments),
                    // Which approval level this request is WAITING on, so an
                    // approve action from here posts the same level the Daily
                    // Closing screen would. null once nothing is pending.
                    'next_level' => $this->nextApprovalLevel($r),
                    // The account it was FILED against (null on older claims, and
                    // on anything a rider raised — he is never asked). The approver
                    // still decides, but he starts from what was filed rather than
                    // from whatever happens to be first in the list.
                    'filed_source_id' => $r->payment_source_account_id !== null ? (int) $r->payment_source_account_id : null,
                    'filed_bank_id'   => $r->receiving_account_id !== null ? (int) $r->receiving_account_id : null,
                ];

                // ⭐ A STAMPED claim names its machine outright, so that machine belongs
                //   in the day's list even when he recorded no reading on it — otherwise
                //   the heading omits a bike the claims directly beneath it are labelled
                //   with (Rajab, 22-Aug: two van claims under an own-bike-only heading).
                //   Unstamped claims add nothing: see the evidence rule in overlayRiderDays.
                if ($r->vehicle_id !== null) {
                    $cvid = (int) $r->vehicle_id;
                    if (!$this->dayListsMachine($days[$date]['machines_today'] ?? [], $cvid)) {
                        $days[$date]['machines_today'][] = [
                            'vehicle_id'  => $cvid,
                            'label'       => $vehLabel($cvid),
                            'is_company'  => $vehIsCompany($cvid),
                            'meter_start' => null, 'meter_end' => null, 'work_km' => null,
                            'start_at'    => null, 'end_at' => null, 'start_source' => null,
                            'partial'     => false,
                            // Nothing was read on it — it is here because money names it.
                            'from_claim'  => true,
                        ];
                    }
                }
            }
        }

        krsort($days);

        // Every off-duty stretch on its own line: meter-out at home → next
        // morning's meter-in. This is the ONLY distance outside the shift, so
        // it is the one a manager will want to open and read night by night.
        $offNights = [];
        if ($isCompany === true) {
            foreach ($days as $d) {
                if (($d['offduty_km'] ?? 0) > 0) {
                    $offNights[] = [
                        'date'  => $d['date'],
                        'since' => $d['offduty_since'],   // set when it spans >1 day
                        'km'    => $d['offduty_km'],
                        'from'  => null,                   // filled below
                        'to'    => $d['meter_start'],
                        // Which bike he was on that night. A rider who changed
                        // machines mid-month would otherwise read as one long chain.
                        'vehicle_label' => $d['vehicle_label'] ?? null,
                    ];
                }
            }
            // `from` is the previous usable close; recover it from the km delta.
            foreach ($offNights as &$n) {
                $n['from'] = $n['to'] !== null ? $n['to'] - $n['km'] : null;
            }
            unset($n);
            usort($offNights, fn ($a, $b) => strcmp($b['date'], $a['date']));
        }

        return [
            'user_id' => $userId,
            'name'    => $name,
            'bike'    => $isCompany === null ? 'unknown' : ($isCompany ? 'company' : 'own'),
            'month'   => $month,
            // ⭐ Every machine he rode this month, busiest first — the strip that
            //   makes "which bike was he on?" answerable without leaving the row.
            'machines' => $riderMachines,
            'days'    => array_values($days),
            'off_nights' => $offNights,
            'service' => ($this->serviceState([$userId])[$userId] ?? null),
            'service_history' => $this->serviceHistory($userId),
            // 🔧 Per-type schedule — the report the manager actually asked for:
            // each job on its own clock (oil 1,200 / brake shoe 10,000 / …) rather
            // than one number for the whole bike.
            'service_schedule' => $this->serviceSchedule($userId),
            // What the month's maintenance money went ON, by type.
            'maint_by_type' => $this->maintByType($userId, $from, $to),
        ];
    }

    /**
     * Every scheduled maintenance type, with when this rider's bike last had it
     * and how far it is from being due again.
     *
     * ⚠⚠ THE MACHINE'S ENGINE ANSWERS FIRST (Aug-16). This method is RIDER-keyed and
     *    predates the machine derivation: no covers rule, `MAX(meter_at_fill)` over
     *    the RIDER's claims, the RIDER's odometer (which spans every bike he has ever
     *    held), and it cannot see a per-bike override. The rider drawer renders the
     *    new headline directly above this list, so leaving it on the old engine
     *    reproduced the original bug INSIDE ONE PANEL — a headline saying "ok" over a
     *    row saying "Oil Change 426 km overdue", and a rider drawer disagreeing with
     *    the vehicle drawer about the same machine.
     *
     * ⚠ The rider-keyed body below is KEPT for anyone the registry cannot place — a
     *   rider with no registered machine still gets his schedule, exactly as before.
     *
     * @return array<int, array<string, mixed>>
     */
    private function serviceSchedule(int $userId): array
    {
        try {
            $veh = new VehicleService();
            if ($veh->available()) {
                $vid = (new VehicleResolver())->currentVehicleFor($userId);
                if ($vid) {
                    return $veh->serviceScheduleFor((int) $vid, $veh->currentMeterFor((int) $vid));
                }
            }
        } catch (\Throwable $e) {
            // fall through to the rider-keyed reconstruction below
        }
        return $this->serviceScheduleByRider($userId);
    }

    /** The pre-registry, rider-keyed schedule — see the note on serviceSchedule(). */
    private function serviceScheduleByRider(int $userId): array
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('t_fleet_maintenance_types')) {
                return [];
            }
            /**
             * ⚠ THE BIKE CLASS, DELIBERATELY. This branch exists only for a rider the
             *   registry cannot place on any machine — it does not know what he rides,
             *   and bike is both the old behaviour and the overwhelmingly likely truth.
             *   A rider ON the van resolves through the machine-keyed engine above and
             *   never reaches here.
             */
            $cols = ['id', 'type_name', 'interval_km', 'bucket', 'resets_service_clock'];
            if (app(MaintenanceTypeService::class)->classAware()) {
                $cols = array_merge($cols, ['applies_to', 'basis', 'interval_km_van',
                                            'interval_days', 'interval_days_van']);
            }
            $types = DB::table('t_fleet_maintenance_types')
                ->where('is_active', 1)
                ->orderBy('sort_order')->orderBy('type_name')
                // ⚠ `resets_service_clock` is selected because the rows below must have
                //   the SAME shape as VehicleService::serviceScheduleFor's — see the
                //   note on `resets_clock` there.
                ->get($cols);
            if ($types->isEmpty()) {
                return [];
            }

            // Last job per type for this rider — from APPROVED claims (the normal
            // path, which carries the bill and the photo) AND from manually
            // recorded services (work done outside the system, no bill). Both are
            // real evidence the job happened; whichever is further along the
            // odometer is the one that counts.
            $last = DB::table('t_req_master')
                ->where('requester_user_id', $userId)
                ->where('expense_category', 'Maintenance')
                ->where('status', 'approved')
                ->whereNotNull('maintenance_type_id')
                ->whereNotNull('meter_at_fill')
                ->groupBy('maintenance_type_id')
                ->selectRaw('maintenance_type_id AS tid, MAX(meter_at_fill) AS m,
                             MAX(COALESCE(expense_date, DATE(created_at))) AS d')
                ->get()->keyBy('tid');

            if (\Illuminate\Support\Facades\Schema::hasTable('t_fleet_service_log')) {
                $logged = DB::table('t_fleet_service_log')
                    ->where('user_id', $userId)
                    ->groupBy('maintenance_type_id')
                    ->selectRaw('maintenance_type_id AS tid, MAX(meter) AS m, MAX(service_date) AS d')
                    ->get();
                foreach ($logged as $row) {
                    $existing = $last->get($row->tid);
                    if (!$existing || (int) $row->m > (int) $existing->m) {
                        $last->put($row->tid, $row);
                    }
                }
            }

            $now = $this->currentMeters([$userId])[$userId] ?? null;

            // ⭐ The shared resolver (Aug-27 2026). This rider-keyed fallback read the raw
            //   type interval only, so it could not see ANY override and disagreed with
            //   the machine-keyed panel for the same rider on the same day.
            $resolver = new ServiceIntervalResolver();

            $klass = \App\Models\Riders\MaintenanceTypeModel::CLASS_BIKE;

            $out = [];
            foreach ($types as $t) {
                $r = $resolver->resolveFor(null, $klass, $t, $userId);
                // Same filter the machine-keyed engine applies: no schedule on this
                // class ⇒ no countdown, so the row is not on the panel at all.
                if (empty($r['has'])) continue;

                $l    = $last->get($t->id);
                $lastM = $l ? (int) $l->m : null;
                $explained = $r;
                $interval  = (int) ($r['km'] ?? 0);
                // due_in is only meaningful when we know BOTH where the bike is now
                // and when this job was last done. Anything else stays null rather
                // than inventing a countdown from a made-up reference point.
                $dueIn = ($lastM !== null && $now !== null)
                    ? $interval - ($now - $lastM) : null;

                $out[] = [
                    'id'          => (int) $t->id,
                    'name'        => $t->type_name,
                    'bucket'      => $t->bucket,
                    /**
                     * ⚠⚠ ONE SHAPE, BOTH BRANCHES (Sep-2026). This key was MISSING here
                     *    while the machine-keyed twin (VehicleService::serviceScheduleFor)
                     *    has always emitted it — so for a rider the registry cannot place,
                     *    every consumer read `undefined` and concluded the job does NOT
                     *    refresh the bike's overall service. The Record-service prompt
                     *    states that fact out loud ("also refreshes the bike's overall
                     *    service" / "unchanged"), so an oil job on an unregistered bike
                     *    would have been described as the exact opposite of what it does.
                     * ⭐ Additive: nothing read this key from this branch before, so no
                     *   existing surface changes except the one that was wrong.
                     */
                    'resets_clock' => !empty($t->resets_service_clock),
                    'interval_km' => $interval,
                    'type_interval_km'      => (int) ($r['standard_km'] ?? 0),
                    'interval_overridden'   => !$explained['from_type'],
                    'interval_source'       => $explained['source'],
                    'interval_source_label' => $explained['source_label'],
                    // ── shape parity with the machine-keyed engine (Sep-2026) ──
                    'vehicle_class'  => $klass,
                    'basis'          => $r['basis'],
                    'interval_days'  => $r['days'],
                    'standard_days'  => $r['standard_days'],
                    'has_schedule'   => (bool) $r['has'],
                    'interval_label' => $r['label'],
                    'due_text'       => VehicleService::dueText(
                                            ServiceIntervalResolver::stateFor($dueIn),
                                            false, $dueIn, null),
                    'due_in_days'    => null,
                    'due_at_date'    => null,
                    'last_meter'  => $lastM,
                    'last_at'     => $l->d ?? null,
                    'due_at_km'   => $lastM !== null ? $lastM + $interval : null,
                    'due_in_km'   => $dueIn,
                    'state'       => ServiceIntervalResolver::stateFor($dueIn),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * This month's maintenance spend split by type — "where did the Rs 1,760 go".
     * Approved and pending both count; pending is flagged so the manager can see
     * what is still waiting on him.
     *
     * @return array<int, array<string, mixed>>
     */
    private function maintByType(int $userId, string $from, string $to): array
    {
        try {
            $rows = DB::table('t_req_master as r')
                ->leftJoin('t_fleet_maintenance_types as t', 't.id', '=', 'r.maintenance_type_id')
                ->where('r.requester_user_id', $userId)
                ->where('r.expense_category', 'Maintenance')
                ->whereNotIn('r.status', ['cancelled', 'rejected'])
                ->whereRaw('COALESCE(r.expense_date, DATE(r.created_at)) BETWEEN ? AND ?', [$from, $to])
                ->selectRaw("r.id, r.amount, r.status, COALESCE(t.type_name,
                                CASE WHEN r.service_type IN ('oil_change','general') THEN 'Regular service'
                                     WHEN r.service_type = 'repair' THEN 'Repair'
                                     ELSE 'Maintenance' END) AS label")
                ->get();

            /**
             * ⭐⭐ ONE RECEIPT FOR SEVERAL JOBS IS ITS OWN LINE (29-Sep-2026). A bill that paid for
             *    Oil + Tuning, Brake Shoe and Chain Set in one visit cannot be split into parts
             *    nobody wrote down, and crediting the whole receipt to its lead job would inflate
             *    that job's spend. So it is grouped under its combined label — the same label the
             *    claim list and Daily Closing print — and reads "Oil + Tuning + Brake Shoe".
             */
            $combined = app(ServiceRecordService::class)->jobLabelsForClaims(
                $rows->pluck('id')->map(fn ($v) => (int) $v)->all());

            // Untyped rows collapse onto the same bucket label, so merge by label.
            $merged = [];
            foreach ($rows as $r) {
                $k = $combined[(int) $r->id] ?? $r->label;
                $merged[$k] ??= ['label' => $k, 'total' => 0.0, 'n' => 0, 'pending_n' => 0];
                $merged[$k]['total']     += (float) $r->amount;
                $merged[$k]['n']         += 1;
                $merged[$k]['pending_n'] += $r->status === 'pending' ? 1 : 0;
            }
            usort($merged, fn ($a, $b) => $b['total'] <=> $a['total']);
            return array_values($merged);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * claim_id => how far the BIKE ran on the tank before this one.
     *
     * ⭐⭐ MACHINE-KEYED (Aug-2026). A tank belongs to the machine, not to the man,
     *    so the anchor is the previous fill on THAT BIKE — whoever filed it. The old
     *    chain asked only "when did this rider last fill?", which is the same question
     *    on a bike nobody else touches and the wrong one the moment a bike changes
     *    hands. Danish's 20-Aug fill on DCR-799 (26,441) was measured against his own
     *    last fill eleven days earlier (25,479) and reported **962 km** for a tank that
     *    covered **143** — Waseem had filled the same bike at 26,298 the night before.
     *    It failed in both directions: the first fill after a handover had no anchor at
     *    all and printed nothing (Danish 8 Aug, Farooq 17 Aug, Rajab's van pair).
     *
     * ⭐ The house rule, same as `serviceState` and `FuelClaimRules::odometerWindow`:
     *    the machine answers where the registry can place the claim, and anything it
     *    cannot place keeps the rider-keyed answer it has always had. A rider the
     *    registry has never tracked cannot notice that this code exists.
     *
     * ⚠ A fill with NO reading still breaks the chain, now for the whole machine: an
     *   unmeasured tank means the next delta would span an unknown distance, and that
     *   is just as true when the unmeasured fill was someone else's. (Per-km metered
     *   claims carry no reading either, but they only ever attach to OWN bikes — a
     *   company machine never sees one — so they cannot break a company chain.)
     *
     * @return array<int, array{km:int,by:?string,on:?string,from_meter:?int}>
     *         `km` is -1 where the two readings don't add up; `by` is set only when
     *         the previous tank was somebody ELSE's.
     */
    private function kmSinceLastFill(int $userId, $claimRows, string $monthStart): array
    {
        $fills = collect($claimRows)
            ->filter(fn ($r) => $r->expense_category === self::CAT_FUEL)
            ->sortBy(fn ($r) => $r->d . '|' . $r->created_at)
            ->values();
        if ($fills->isEmpty()) return [];

        // ⚠ The rider walk runs over the WHOLE list rather than over the leftovers,
        //   so an untracked rider's chain is byte-identical to the one he has always
        //   had. Placed rows are then OVERWRITTEN by the machine's answer — never
        //   removed from the walk, whose anchors depend on seeing every fill.
        $out = $this->riderFillChain($userId, $fills, $monthStart);

        foreach ($this->machineFillChain($userId, $fills) as $id => $m) {
            $out[$id] = $m;
        }
        return $out;
    }

    /**
     * ⭐⭐ THE MACHINE'S OWN FILL CHAIN, cut down to one rider's claims.
     *
     * Returns an entry for EVERY one of his metered fills the registry can place on
     * a machine — including an explicit `km => null` when the machine declines to
     * measure (nothing honest on the near side: the bike's first-ever reading, or a
     * break behind someone's unmeasured tank). That silence is an ANSWER and must
     * override the rider-keyed number, which would otherwise bridge across another
     * man's unknown tank — or another bike entirely. Only fills the registry cannot
     * PLACE are absent, and absence is the signal to keep the rider-keyed answer.
     *
     * ⚠ WHICH CLAIMS BELONG TO A MACHINE IS ASKED ONCE, of
     *   `VehicleService::claimHistoryFor` — the same reconstruction the Vehicles tab
     *   spends money against (stamped rows, assignment windows, manager day-overrides,
     *   the pre-registry backfill). Re-deriving it here would let the fuel chip and the
     *   bike's own cost history disagree about which bike burned a tank.
     */
    private function machineFillChain(int $userId, $fills): array
    {
        $out = [];
        try {
            // ⭐ THE SAME ROLLBACK LEVER as the rest of the machine work: one config
            //   row (`MACHINE_ATTRIBUTION = 'N'`) puts every chip back on the rider
            //   chain with no upload and no code change, and it is how the regression
            //   suite proves the before/after.
            if (strtoupper((string) $this->cfg('MACHINE_ATTRIBUTION', 'Y')) !== 'Y') return [];

            $veh = new VehicleService();
            if (!$veh->available()) return [];
            $resolver = new VehicleResolver();

            // 1. which machine each of HIS fills belongs to. The STAMP wins — it is the
            //    fact frozen at filing (VehicleResolver::stampClaim), and a reassignment
            //    months later must not silently re-point old money. The day lookup only
            //    covers rows filed before the stamp existed.
            $stamped = $this->stampedVehicles($fills->pluck('id')->all());
            $vidByClaim = [];
            foreach ($fills as $f) {
                $vid = $stamped[(int) $f->id] ?? null;
                if (!$vid) $vid = $resolver->vehicleForDay($userId, $f->d);
                if ($vid) $vidByClaim[(int) $f->id] = (int) $vid;
            }
            if (!$vidByClaim) return [];

            // 2. walk each of those machines end to end — every rider's fills, in
            //    filing order. The walk covers the machine's whole history, so a month
            //    boundary and a handover are the same non-event to it.
            foreach (array_unique(array_values($vidByClaim)) as $vid) {
                $mine = array_flip(array_keys($vidByClaim, $vid, true));
                $prev = null; $prevBy = null; $prevById = null; $prevOn = null;

                foreach ($this->machineFills($veh, (int) $vid) as $c) {
                    if ($c['meter'] === null) {          // unmeasured tank — see the docblock
                        $prev = null; $prevBy = null; $prevById = null; $prevOn = null;
                        continue;
                    }
                    $cur  = (int) $c['meter'];
                    $his  = isset($mine[$c['id']]);

                    if ($prev === null) {
                        // ⭐ Nothing honest on the near side (first reading, or the fill
                        //   behind an unmeasured tank). For HIS claims this is an
                        //   explicit answer — see the docblock — never a fall-through.
                        if ($his) $out[$c['id']] = ['km' => null, 'by' => null, 'on' => null, 'from_meter' => null];
                        $prev = $cur; $prevBy = $c['by_name'];
                        $prevById = $c['by_user_id']; $prevOn = $c['date'];
                        continue;
                    }

                    $delta = $cur - $prev;
                    if ($delta >= 1 && $delta <= self::MAX_FILL_GAP_KM) {
                        if ($his) {
                            $out[$c['id']] = [
                                'km' => $delta,
                                // Named only when the previous tank was SOMEONE ELSE's —
                                // on his own run of fills there is nothing to explain,
                                // and "since Danish's fill" on Danish's row reads as a bug.
                                'by' => ($prevById !== null && $prevById !== $userId) ? $prevBy : null,
                                'on' => $prevOn,
                                'from_meter' => $prev,
                            ];
                        }
                        $prev = $cur; $prevBy = $c['by_name'];
                        $prevById = $c['by_user_id']; $prevOn = $c['date'];
                    } else {
                        if ($his) $out[$c['id']] = ['km' => -1, 'by' => null, 'on' => null, 'from_meter' => null];
                        // Anchor stays at the last SANE reading, so one bad entry poisons
                        // its own row only — exactly as the rider chain has always done.
                    }
                }
            }
        } catch (\Throwable $e) {
            // The machine is an improvement, never a dependency. Whatever was already
            // resolved stands; everything else keeps the rider-keyed answer.
            Log::warning('machineFillChain unavailable', ['user' => $userId, 'error' => $e->getMessage()]);
        }
        return $out;
    }

    /**
     * The rider-keyed chain this screen has always used, unchanged in substance.
     * Still the answer for anyone the registry cannot place on a machine.
     */
    private function riderFillChain(int $userId, $fills, string $monthStart): array
    {
        $prev = DB::table('t_req_master')
            ->where('requester_user_id', $userId)
            ->where('expense_category', self::CAT_FUEL)
            ->whereNotNull('meter_at_fill')
            ->whereIn('status', ['approved', 'pending'])
            ->whereRaw('COALESCE(expense_date, DATE(created_at)) < ?', [$monthStart])
            ->orderByRaw('COALESCE(expense_date, DATE(created_at)) DESC')
            ->orderByDesc('created_at')
            ->value('meter_at_fill');
        $prev = $prev !== null ? (int) $prev : null;

        $out = [];
        foreach ($fills as $f) {
            if ($f->meter_at_fill === null) {
                // A fill with no reading breaks the chain: the NEXT delta would
                // silently include this tank's unknown distance.
                $prev = null;
                continue;
            }
            $cur = (int) $f->meter_at_fill;
            if ($prev === null) {
                $prev = $cur;
                continue;
            }
            $delta = $cur - $prev;
            if ($delta >= 1 && $delta <= self::MAX_FILL_GAP_KM) {
                $out[(int) $f->id] = ['km' => $delta, 'by' => null, 'on' => null, 'from_meter' => $prev];
                $prev = $cur;
            } else {
                // Implausible reading (typo / swapped bike). Flag it, but keep the
                // anchor at the last SANE reading — otherwise one bad entry would
                // poison every delta after it instead of just its own.
                $out[(int) $f->id] = ['km' => -1, 'by' => null, 'on' => null, 'from_meter' => null];
            }
        }
        return $out;
    }

    /**
     * One machine's fuel claims, oldest first, memoised per request.
     *
     * Ordered by DATE then ID — the same order the Vehicles tab lists them in, so
     * "the previous fill" means the same physical row on both screens. (Two fills on
     * one day are ordered by id, which is filing order.)
     */
    private function machineFills(VehicleService $veh, int $vehicleId): array
    {
        if (array_key_exists($vehicleId, $this->machineFillMemo)) return $this->machineFillMemo[$vehicleId];

        $fuel = array_values(array_filter(
            $veh->claimHistoryFor($vehicleId),
            fn ($c) => ($c['category'] ?? null) === self::CAT_FUEL
        ));
        usort($fuel, fn ($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);

        return $this->machineFillMemo[$vehicleId] = $fuel;
    }

    /**
     * The machine each claim was STAMPED with at filing, for ids we already hold.
     *
     * ⚠ Guarded on the column: the web files can be uploaded to a server where batch
     *   13 has not been run, and that must degrade to the day lookup rather than 500
     *   the whole drill-down.
     *
     * @return array<int, int>
     */
    private function stampedVehicles(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return [];
        try {
            if (!Schema::hasColumn('t_req_master', 'vehicle_id')) return [];
            return DB::table('t_req_master')->whereIn('id', $ids)
                ->whereNotNull('vehicle_id')
                ->pluck('vehicle_id', 'id')
                ->map(fn ($v) => (int) $v)->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The approval level a pending request is waiting on.
     *
     * Mirrors RequestModel::canBeApprovedByLevel — L1 first when required and
     * still pending, otherwise L2. Returning the wrong level would make the
     * server reject the approval, so this must stay in step with that model.
     */
    private function nextApprovalLevel($r): ?int
    {
        if (($r->status ?? null) !== 'pending') return null;
        if ((int) ($r->requires_level_1 ?? 0) === 1 && ($r->level_1_status ?? null) === 'pending') return 1;
        if ((int) ($r->requires_level_2 ?? 0) === 1 && ($r->level_2_status ?? null) === 'pending') return 2;
        return null;
    }

    /**
     * For each REGULAR service in the month: how far the bike ran since the
     * previous regular service, and — if that is short of the bike's interval —
     * by how much it beat the schedule.
     *
     * "Early" is worth surfacing because a service at 300 km on a 500 km schedule
     * is either money spent sooner than needed or a bike with a problem; either
     * way the approver should see it before paying. Repairs are never measured
     * this way — they are ad-hoc by definition and never reset the clock.
     *
     * Needs the odometer at the service (`meter_at_fill`), so it stays null for
     * everything filed before that field existed rather than being guessed.
     *
     * ⭐⭐ THREE THINGS CHANGED IN AUG-2026, all so this can never again contradict
     *    the frozen `service_due_km` printed on the SAME ROW. The live screen showed
     *    Waseem's 16-Aug oil change as "⏱ serviced 297 km early" AND "🔴 done 43 km
     *    overdue" at once; on Kanan's 17-Aug row the two were 1,351 km apart.
     *
     *    1. WHICH ROWS COUNT is the TYPE's call, not `service_type`'s. That column
     *       says only "regular", so Chain Set and Brake Shoe — both flagged
     *       `resets_service_clock = 0` — were being chained as if they were services.
     *       Waseem's anchor was a Chain Set at 25,007 when the real oil anchor was
     *       24,667. Same predicate the approval hook uses: `resetsClock()`.
     *    2. THE ANCHOR IS THE MACHINE'S, not the man's — `lastServicePointBefore`,
     *       the identical call that produced the frozen figure, including its
     *       "covers" rule (a tuning is never measured from a mere oil change).
     *    3. THE INTERVAL IS THE JOB'S OWN. Measuring a 10,000 km brake-shoe job
     *       against the 1,200 km oil schedule reported it "151 km overdue", which is
     *       noise dressed as a finding.
     *
     * ⭐ Where the row already carries `service_due_km`, that number IS the answer —
     *    it was frozen at approval against evidence that no longer exists, so
     *    recomputing it could only ever disagree with it. Note that early/late then
     *    come out EXACTLY equal to the frozen figure whatever interval is in force,
     *    since both sides divide out. Only the "▲ N km since last service" line
     *    depends on the interval, which is why the precedence below has to mirror
     *    `BikeServiceClock::stampServiceDueKm` — change one, change both.
     */
    private function kmSinceLastService(int $userId, $claimRows, ?bool $isCompany): array
    {
        $types = app(\App\Services\Riders\MaintenanceTypeService::class);

        $regular = collect($claimRows)
            ->filter(fn ($r) => $r->expense_category === self::CAT_MAINT
                && $types->resetsClock($r->maintenance_type_id ?? null, $r->service_type)
                && $r->meter_at_fill !== null
                && in_array($r->status, ['approved', 'pending'], true))
            ->sortBy(fn ($r) => $r->d . '|' . $r->created_at)
            ->values();
        if ($regular->isEmpty()) return [];

        $profiles = $this->profiles();
        $p = $profiles[$userId] ?? null;
        // ⚠ 1200, matching serviceState() and VehicleService. This was 3000 — the
        //   same two-literals-for-one-rule trap already fixed in serviceState, left
        //   behind here. The config row exists on prod, so today this changes nothing.
        $default = (new ServiceIntervalResolver())->companyDefault();

        // The schedule this particular job is judged against.
        // ⭐ Aug-27 2026: the SHARED resolver, so the "serviced N km early" flag is
        //   measured against the very interval the screens show and `stampServiceDueKm`
        //   freezes. This block used to hand-roll `type → rider → config` and, like its
        //   twin in the clock, never saw the machine's own schedule at all.
        $resolver = new ServiceIntervalResolver();
        $vehSvc   = new VehicleService();
        /**
         * ⭐ CLASS-AWARE (Sep-2026): "serviced N km early" is measured against the
         *   schedule THAT MACHINE follows, so a van claim is judged on van numbers.
         * ⚠ A TIME-based job has no kilometre schedule to be early against, so it
         *   returns 0 and the flag simply does not fire — better than inventing an
         *   early/late verdict from a number in the wrong unit.
         */
        $intervalFor = function ($r) use ($types, $resolver, $userId, $vehSvc): int {
            $t   = $types->find($r->maintenance_type_id ?? null);
            $vid = isset($r->vehicle_id) && $r->vehicle_id ? (int) $r->vehicle_id : null;
            $res = $resolver->resolveFor($vid, $vehSvc->classOf($vid), $t, $userId);
            return ($res['basis'] === \App\Models\Riders\MaintenanceTypeModel::BASIS_KM)
                ? (int) ($res['km'] ?? 0) : 0;
        };

        // ⭐ The machine, where the registry can place the claim. Gated on the same
        //   rollback lever as every other machine read on this screen.
        $veh       = new VehicleService();
        $machineOn = strtoupper((string) $this->cfg('MACHINE_ATTRIBUTION', 'Y')) === 'Y';
        try {
            $machineOn = $machineOn && $veh->available();
        } catch (\Throwable $e) {
            $machineOn = false;
        }
        $resolver = new VehicleResolver();
        $stamped  = $machineOn ? $this->stampedVehicles($regular->pluck('id')->all()) : [];

        // Seed from the most recent regular service BEFORE this month, so the
        // first one in the month is still measured. APPROVED ONLY — the anchor
        // every claim is judged against must be settled fact. A pending claim
        // still gets measured (the approver wants the number next to Approve),
        // but until someone approves it, its meter is just a rider's typing and
        // must not shift how the next real claim reads: a mistyped pending meter
        // used to poison the following claim's early/late, and a claim that was
        // later REJECTED had already served as an anchor.
        //
        // ⚠ This is now the FALLBACK anchor only — used for riders the registry
        //   cannot place, whose chip must stay exactly as it has always been.
        $prev = DB::table('t_req_master')
            ->where('requester_user_id', $userId)
            ->where('expense_category', self::CAT_MAINT)
            ->whereIn('service_type', ['oil_change', 'general'])
            ->whereNotNull('meter_at_fill')
            ->where('status', 'approved')
            ->whereRaw('COALESCE(expense_date, DATE(created_at)) < ?', [$regular->first()->d])
            ->orderByRaw('COALESCE(expense_date, DATE(created_at)) DESC')
            ->value('meter_at_fill');
        $prev = $prev !== null ? (int) $prev : null;

        $out = [];
        foreach ($regular as $r) {
            $cur = (int) $r->meter_at_fill;
            $isApproved = $r->status === 'approved';
            $interval   = $intervalFor($r);
            $since      = null;
            $answered   = false;

            // 1. Frozen at approval — the record of what was actually true then.
            if ($r->service_due_km !== null && $interval > 0) {
                $since    = $interval - (int) $r->service_due_km;
                $answered = true;
            }

            // 2. Otherwise ask the machine. A pending service has no frozen figure
            //    by definition, and this is precisely the number the approver wants
            //    next to the Approve button.
            if (!$answered && $machineOn) {
                try {
                    $vid = $stamped[(int) $r->id] ?? $resolver->vehicleForDay($userId, $r->d);
                    if ($vid) {
                        $t = $types->find($r->maintenance_type_id ?? null);
                        $point = $veh->lastServicePointBefore((int) $vid, $cur,
                            $t && (int) $t->interval_km > 0 ? (int) $t->interval_km : null);
                        // ⚠ "Nothing on record that could have included this job" IS an
                        //   answer, and it is `null` — falling through to the rider
                        //   anchor here would count exactly the smaller jobs the covers
                        //   rule just rejected, through the back door. Same reasoning as
                        //   BikeServiceClock::stampServiceDueKm.
                        $answered = true;
                        $since    = $point ? $cur - (int) $point['meter'] : null;
                    }
                } catch (\Throwable $e) {
                    $answered = false;          // machine unavailable → rider chain
                }
            }

            // 3. The rider-keyed walk, unchanged, for anyone the registry cannot place.
            if (!$answered && $prev !== null) {
                $since = $cur - $prev;
            }

            // A schedule of zero cannot judge anything early or late — it would make
            // every service look overdue by its own distance. Say nothing instead.
            if ($since !== null && $interval > 0) {
                // Implausible gap = a typo or a swapped bike; don't call it early.
                if ($since >= 1 && $since <= 50000) {
                    $out[$r->id] = [
                        'since'    => $since,
                        'interval' => $interval,
                        // Both directions matter: early is money spent sooner than
                        // needed, late means the bike ran past its service — the
                        // reason the due chip was red. A small tolerance stops a
                        // service a few km either side reading as a problem.
                        'early_by' => $since < $interval - 25 ? $interval - $since : null,
                        'late_by'  => $since > $interval + 25 ? $since - $interval : null,
                    ];
                    // Anchor advances on APPROVED rows only (see the seed note).
                    if ($isApproved) { $prev = $cur; }
                    continue;
                }
            }
            $out[$r->id] = ['since' => null, 'interval' => $interval, 'early_by' => null, 'late_by' => null];
            if ($isApproved) { $prev = $cur; }
        }
        return $out;
    }

    /**
     * Which money bucket a maintenance row belongs to.
     * `general` rides with `oil_change` because both are scheduled work and both
     * reset the service clock (see BikeServiceClock::onRequestApproved).
     */
    private function maintBucket(?string $serviceType): string
    {
        if (in_array($serviceType, ['oil_change', 'general'], true)) return 'maint_regular_rs';
        if ($serviceType === 'repair') return 'maint_repair_rs';
        return 'maint_other_rs';        // 'other', or filed before the field existed
    }

    /**
     * The approval trail for a set of requests, split into two things that look
     * alike but answer different questions:
     *
     *  'notes'   — what the approver actually TYPED. Managers use this field for
     *              real detail ("Tyre Puncture", "Oil Change") and it lived only
     *              on the approval row, so it never reached any screen.
     *  'actions' — WHO acted, WHEN, and from WHICH screen. Every approve path in
     *              the system stamps its own name into `comments` ("Approved from
     *              daily closing" / "from Fleet" / "from month view"), so that
     *              boilerplate — noise as a note — is precisely the provenance:
     *              it tells you Shabib approved from Daily Closing while Qasim
     *              approved from Bikes.
     *
     * Both come out of one query. A row with a typed note contributes to both.
     *
     * @return array{notes: array<int,array>, actions: array<int,array>}
     */
    private function approvalTrail(array $requestIds): array
    {
        if (!$requestIds) return ['notes' => [], 'actions' => []];
        try {
            $rows = DB::table('t_req_approval as a')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'a.approver_user_id')
                ->whereIn('a.request_id', $requestIds)
                ->whereIn('a.status', ['approved', 'rejected'])
                ->orderBy('a.action_date')
                ->get(['a.request_id', 'a.approval_level', 'a.status', 'a.comments', 'a.action_date', 'u.fullname']);

            $notes = [];
            $actions = [];
            foreach ($rows as $r) {
                $id   = (int) $r->request_id;
                $text = trim((string) ($r->comments ?? ''));

                $actions[$id][] = [
                    'level'  => (int) $r->approval_level,
                    'status' => $r->status,
                    'by'     => $r->fullname,
                    'at'     => $r->action_date,
                    'source' => $this->approvalSource($text),
                ];

                if ($text !== '' && !$this->isBoilerplateComment($text)) {
                    $notes[$id][] = [
                        'level'  => (int) $r->approval_level,
                        'status' => $r->status,
                        'by'     => $r->fullname,
                        'at'     => $r->action_date,
                        'text'   => mb_substr($text, 0, 300),
                    ];
                }
            }
            return ['notes' => $notes, 'actions' => $actions];
        } catch (\Throwable $e) {
            return ['notes' => [], 'actions' => []];
        }
    }

    /** Auto-written "Approved from X" — provenance, not a human note. */
    private function isBoilerplateComment(string $text): bool
    {
        return (bool) preg_match('/^(approved|rejected) from (daily closing|fleet|month view)$/i', $text);
    }

    /**
     * Which screen the action came from, read back off the stamp that screen
     * writes. Null when nothing recognisable was written — that means the
     * Requests screen, which posts the approver's own words instead.
     */
    private function approvalSource(string $text): ?string
    {
        if (stripos($text, 'from daily closing') !== false) return 'Daily Closing';
        if (stripos($text, 'from month view') !== false)    return 'Daily Closing — month view';
        if (stripos($text, 'from Fleet') !== false)         return 'Bikes';
        return null;
    }

    /** Past maintenance claims — the bike's service record. */
    private function serviceHistory(int $userId): array
    {
        $rows = DB::table('t_req_master')
            ->selectRaw("id, amount, status, service_type, meter_at_fill, attachments,
                         COALESCE(expense_date, DATE(created_at)) AS d")
            ->where('requester_user_id', $userId)
            ->where('expense_category', self::CAT_MAINT)
            ->whereIn('status', ['approved', 'pending'])
            ->orderByDesc('d')->limit(12)->get();

        return $rows->map(fn ($r) => [
            'date'   => $r->d,
            'amount' => (float) $r->amount,
            'status' => $r->status,
            'type'   => $r->service_type,
            'meter'  => $r->meter_at_fill !== null ? (int) $r->meter_at_fill : null,
            'photo'  => $this->attachmentUrl($r->attachments),
        ])->toArray();
    }

    // =================================================================
    // helpers
    // =================================================================

    /** Rider profiles keyed by user id (bike type + service fields). */
    private function profiles(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $cache = DB::table('t_ops_rider_profile')
            ->select('user_id', 'company_bike', 'meter_required', 'vehicle_type', 'active',
                     'service_interval_km', 'last_service_meter', 'last_service_at')
            ->get()->keyBy('user_id')->toArray();
        return $cache;
    }

    /** First and last day of a YYYY-MM month. */
    private function bounds(string $month): array
    {
        $c = Carbon::parse($month . '-01');
        return [$c->copy()->startOfMonth()->format('Y-m-d'), $c->copy()->endOfMonth()->format('Y-m-d')];
    }

    /** Browser-usable URL for a request's photo, or null. */
    /**
     * Receipt photo for a claim, as an ABSOLUTE url.
     *
     * ⚠ It used to return a bare '/public-storage/…' path. A browser resolves that
     * against the page origin, so the web screens looked fine — but React Native's
     * <Image source={{uri}}> has no origin to resolve against, so every petrol and
     * maintenance photo silently failed to load on the phone, in BOTH store and
     * Khaas/Frozen mode. Same absolute form getMeterPictureUrl() already uses for
     * the attendance meter pictures, which is why those always worked on mobile.
     */
    /**
     * The odometer the NEXT fill will be measured from, for the live "that's N km
     * since his last fill" hint while a claim is being typed.
     *
     * ⭐ THE MACHINE HE HOLDS NOW, whoever filled it last — the claim being typed is
     *    for that bike, so the tank it follows is that bike's. Keyed to the man, the
     *    hint told a manager filing Danish's 20-Aug claim that the previous reading
     *    was 25,479 (Danish's own, eleven days and one handover ago) when DCR-799 had
     *    been filled at 26,298 the night before — the same error the chip on the
     *    saved claim made, one step earlier.
     *
     * ⚠ NO ABSOLUTE FLOOR on the machine branch, deliberately. A reading under
     *   1,000 km is a dropped digit on a 47,000 km bike and the plain truth on
     *   EDN-198, which has never read above 800; the floor is why that bike's hint
     *   never appeared at all. Readings on the machine's own list are already
     *   bounded by its own series — `FuelClaimRules::checkOdometer` refuses anything
     *   outside the machine's window before it can be saved.
     *
     * The stored figure on a saved claim still comes from kmSinceLastFill(), which
     * chains fills properly and refuses to guess across a fill that carried no
     * reading. This is only the hint.
     *
     * @return array{meter:?int,by:?string}  `by` names the previous filler only when
     *         it was somebody other than this rider.
     */
    private function lastFillMeter(int $userId): array
    {
        if (array_key_exists($userId, $this->lastFillMemo)) return $this->lastFillMemo[$userId];
        $out = ['meter' => null, 'by' => null];

        try {
            if (strtoupper((string) $this->cfg('MACHINE_ATTRIBUTION', 'Y')) === 'Y') {
                $veh = new VehicleService();
                $vid = $veh->available()
                    ? (new VehicleResolver())->currentVehicleFor($userId)
                    : null;
                if ($vid) {
                    // machineFills() is oldest-first, so the machine's latest fill
                    // with a reading is the last one carrying a meter.
                    foreach (array_reverse($this->machineFills($veh, (int) $vid)) as $c) {
                        if ($c['meter'] === null) continue;
                        $out = [
                            'meter' => (int) $c['meter'],
                            'by' => (($c['by_user_id'] ?? null) !== null && (int) $c['by_user_id'] !== $userId)
                                ? $c['by_name'] : null,
                        ];
                        return $this->lastFillMemo[$userId] = $out;
                    }
                    // He holds a machine that has never been filled with a reading —
                    // that IS the answer. Falling through to his own history would
                    // offer a different bike's odometer as this bike's last fill.
                    return $this->lastFillMemo[$userId] = $out;
                }
            }
        } catch (\Throwable $e) {
            // fall through to the rider-keyed figure — a hint must never break the page
        }

        try {
            $v = DB::table('t_req_master')
                ->where('requester_user_id', $userId)
                ->where('expense_category', self::CAT_FUEL)
                ->whereNotNull('meter_at_fill')
                ->where('meter_at_fill', '>', self::MIN_METER)
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->orderByRaw('COALESCE(expense_date, DATE(created_at)) DESC, id DESC')
                ->value('meter_at_fill');
            $out['meter'] = $v !== null ? (int) $v : null;
        } catch (\Throwable $e) {
            $out['meter'] = null;
        }
        return $this->lastFillMemo[$userId] = $out;
    }

    private function attachmentUrl($attachments): ?string
    {
        if (empty($attachments)) return null;
        $paths = is_array($attachments) ? $attachments : json_decode($attachments, true);
        if (!is_array($paths) || !isset($paths[0]) || !$paths[0]) return null;

        $path = '/public-storage/' . ltrim((string) $paths[0], '/');

        // Console/queue contexts have no request; fall back to the relative path
        // rather than building a url against a bogus host.
        try {
            $base = request() ? request()->getSchemeAndHttpHost() : null;
        } catch (\Throwable $e) {
            $base = null;
        }
        return $base ? rtrim($base, '/') . $path : $path;
    }

    private function cfg(string $key, $default)
    {
        try {
            $v = DB::table('t_fin_config')->where('config_key', $key)->value('config_value');
            return ($v === null || $v === '') ? $default : $v;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
