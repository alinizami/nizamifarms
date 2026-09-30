<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Day Review (Jul-2026) — the manager-facing "what happened on this day" builder.
 *
 * Deliberately a THIN layer on top of RiderDayReportService: every delivery fact
 * (promise vs delivered, delivered-point vs customer pin, stops, GPS gaps) is
 * already computed there and is what the Issues tab / mobile Timeline show. This
 * service only adds what Day Review needs on top and NOTHING that would change
 * those numbers:
 *
 *   1. pin-crossing  — did the trail ever come near the customer's saved pin,
 *                      and when (answers "did he ever actually get there?")
 *   2. glitch check  — the delivered marker vs where the trail says he was at
 *                      that minute (answers "was that far-away delivery real?")
 *   3. route         — a simplified polyline for the map (never raw GPS dots)
 *   4. in-flight     — today's dispatched-but-not-delivered orders, so removing
 *                      the old Dispatch Tracker tab loses nothing
 *
 * Thresholds come from config/rider_reports.php — the SAME numbers the Issues
 * tab uses, so the two can never disagree.
 */
class RiderDayReviewService
{
    /** Trail fix within this of the customer pin counts as "he got there". */
    const PIN_CROSS_M = 100;

    /** Delivered marker this far from the trail at that minute = suspect. */
    const GLITCH_M = 300;

    /** Max points in a route polyline (payload guard). */
    const ROUTE_MAX_POINTS = 700;

    public function __construct(private RiderDayReportService $report)
    {
    }

    private function cfg(string $k, $default = null)
    {
        return config("rider_reports.$k", $default);
    }

    // =================================================================
    // DAY LEVEL
    // =================================================================

    /**
     * Riders who worked the day + their headline counts.
     * A rider appears if he delivered anything OR is carrying something now.
     */
    public function daySummary(string $date): array
    {
        $isToday = $date === Carbon::today()->format('Y-m-d');

        $delivered = $this->deliveredCounts($date);
        $inFlight  = $isToday ? $this->inFlightCounts() : [];
        $kms       = $this->dayKm($date);

        // 🚚 The van day, if there was one. Read-only and defensive — it is an
        //    extra story ABOVE the rider list, never a reason the day fails to
        //    open. Fetched before the early return so a van-only day (everything
        //    still aboard, nothing delivered yet) still renders its trip card.
        $vanTrips = [];
        try {
            $vanTrips = (new VanService())->dayTrips($date);
        } catch (\Throwable $e) {
            $vanTrips = [];
        }

        $ids = array_unique(array_merge(array_keys($delivered), array_keys($inFlight)));
        if (!$ids) {
            return ['riders' => [], 'totals' => $this->emptyTotals(), 'van_trips' => $vanTrips];
        }

        $names = DB::table('t_sys_user')->whereIn('id', $ids)
            ->pluck('fullname', 'id')->toArray();

        $riders = [];
        $tDelivered = 0; $tOnTime = 0; $tRated = 0; $tLateSum = 0; $tNeeds = 0; $tFlight = 0;

        foreach ($ids as $uid) {
            $d = $delivered[$uid] ?? ['delivered' => 0, 'on_time' => 0, 'rated' => 0, 'late_sum' => 0, 'needs_look' => 0];
            $f = $inFlight[$uid] ?? ['in_flight' => 0, 'overdue' => 0];

            $riders[] = [
                'user_id'    => (int) $uid,
                'name'       => $names[$uid] ?? 'Rider',
                'delivered'  => $d['delivered'],
                'on_time'    => $d['on_time'],
                'late'       => max(0, $d['rated'] - $d['on_time']),
                'needs_look' => $d['needs_look'],
                'in_flight'  => $f['in_flight'],
                'overdue'    => $f['overdue'],
                'km'         => $kms[$uid] ?? null,
            ];

            $tDelivered += $d['delivered'];
            $tOnTime    += $d['on_time'];
            $tRated     += $d['rated'];
            $tLateSum   += $d['late_sum'];
            $tNeeds     += $d['needs_look'];
            $tFlight    += $f['in_flight'];
        }

        // flagged riders first, then busiest
        usort($riders, function ($a, $b) {
            if ($a['needs_look'] !== $b['needs_look']) return $b['needs_look'] <=> $a['needs_look'];
            return $b['delivered'] <=> $a['delivered'];
        });

        return [
            'riders' => $riders,
            'totals' => [
                'delivered'    => $tDelivered,
                'on_time'      => $tOnTime,
                'rated'        => $tRated,
                'on_time_pct'  => $tRated > 0 ? (int) round($tOnTime * 100 / $tRated) : null,
                'avg_late_min' => $tRated > 0 ? (int) round($tLateSum / $tRated) : null,
                'needs_look'   => $tNeeds,
                'in_flight'    => $tFlight,
            ],
            // 🚚 The handover choreography the rider rows cannot show.
            'van_trips' => $vanTrips,
        ];
    }

    private function emptyTotals(): array
    {
        return ['delivered' => 0, 'on_time' => 0, 'rated' => 0, 'on_time_pct' => null,
                'avg_late_min' => null, 'needs_look' => 0, 'in_flight' => 0];
    }

    /**
     * Per-rider delivered counts for the day.
     *
     * Cheap SQL pass (no GPS): counts + lateness + the two flags that do not need
     * a trail. The trail-dependent flags (no GPS proof, never reached the pin) are
     * added when a rider is opened — keeping the day list fast.
     */
    private function deliveredCounts(string $date): array
    {
        $tz     = $this->report->detectTzOffset();
        $from   = Carbon::parse($date)->startOfDay()->format('Y-m-d H:i:s');
        $to     = Carbon::parse($date)->addDay()->addHours(3)->format('Y-m-d H:i:s');
        $lateMg = (int) $this->cfg('late_manager_minutes', 15);

        // delivery_accuracy_m only exists after the GPS-accuracy hardening SQL; select it
        // conditionally so the day list keeps working on a DB where that hasn't run yet.
        $accCol = \Illuminate\Support\Facades\Schema::hasColumn('t_crm_order_status_history', 'delivery_accuracy_m')
            ? 'h.delivery_accuracy_m'
            : 'NULL';

        $rows = DB::select(
            "SELECT o.assigned_rider_user_id AS uid, o.id AS order_id,
                    o.estimated_delivery_at AS eta,
                    h.changed_at AS delivered_raw,
                    h.delivery_latitude AS pin_lat, h.delivery_longitude AS pin_lng,
                    {$accCol} AS delivery_accuracy_m,
                    c.latitude AS ver_lat, c.longitude AS ver_lng
               FROM t_crm_prod_order o
               JOIN t_crm_order_status_history h
                    ON h.order_id = o.id AND h.status_code = 'delivered'
               LEFT JOIN t_crm_prod_customer c ON c.id = o.customer_id
              WHERE o.assigned_rider_user_id IS NOT NULL
                AND h.changed_at BETWEEN ? AND ?",
            [$from, $to]
        );

        $secs     = $tz * 60;
        $dayFloor = strtotime(Carbon::parse($date)->format('Y-m-d 00:00:00'));
        $out = [];

        // C10 (Sep-29): rate against the PROMISE, exactly as the opened rider and Issues
        // do (RiderDayReportService::loadOrders) — the live ETA is overwritten by every
        // re-dispatch. ONE batched read for the whole list; pre-log orders fall back.
        $promises = EtaPromiseService::promisesFor(array_map(fn ($r) => (int) $r->order_id, $rows));

        foreach ($rows as $r) {
            $delTs = strtotime($r->delivered_raw) - $secs;
            if ($delTs < $dayFloor) continue;           // same guard the report uses
            $eta = $promises[(int) $r->order_id]['promised_at'] ?? $r->eta;

            $uid = (int) $r->uid;
            if (!isset($out[$uid])) {
                $out[$uid] = ['delivered' => 0, 'on_time' => 0, 'rated' => 0, 'late_sum' => 0, 'needs_look' => 0];
            }
            $out[$uid]['delivered']++;

            $needs = false;

            if (!empty($eta)) {
                $late = (int) round(($delTs - strtotime($eta)) / 60);
                $out[$uid]['rated']++;
                $out[$uid]['late_sum'] += max(0, $late);
                if ($late <= (int) $this->cfg('late_card_minutes', 10)) {
                    $out[$uid]['on_time']++;
                }
                if ($late > $lateMg) $needs = true;
            }

            // Same verdict class the rider's opened day uses (RiderDayReportService), so the
            // list can't say "needs a look" about a delivery the detail view calls clean.
            $pin = VerifiedPinRule::judge(
                $r->pin_lat, $r->pin_lng, $r->ver_lat, $r->ver_lng, $r->delivery_accuracy_m ?? null
            );
            if ($pin && !$pin['at_verified']) $needs = true;

            if ($needs) $out[$uid]['needs_look']++;
        }

        return $out;
    }

    /** Today only: dispatched but still out. Replaces the old Dispatch Tracker's live half. */
    private function inFlightCounts(): array
    {
        $rows = DB::select(
            "SELECT o.assigned_rider_user_id AS uid, o.id AS order_id, o.estimated_delivery_at AS eta
               FROM t_crm_prod_order o
              WHERE o.assigned_rider_user_id IS NOT NULL
                AND o.order_status = 'out_for_delivery'"
        );

        // C10 (Sep-29): "overdue" against the PROMISE, like riderInFlight() — one batched read.
        $promises = EtaPromiseService::promisesFor(array_map(fn ($r) => (int) $r->order_id, $rows));

        $now = time();
        $out = [];
        foreach ($rows as $r) {
            $uid = (int) $r->uid;
            if (!isset($out[$uid])) $out[$uid] = ['in_flight' => 0, 'overdue' => 0];
            $out[$uid]['in_flight']++;
            $eta = $promises[(int) $r->order_id]['promised_at'] ?? $r->eta;
            if (!empty($eta) && strtotime($eta) < $now) $out[$uid]['overdue']++;
        }
        return $out;
    }

    /** Meter km per rider for the day (same bounds-checking the Fleet view uses). */
    private function dayKm(string $date): array
    {
        $rows = DB::table('t_ops_attendance')
            ->select('user_id', 'meter_start', 'meter_end')
            // ⭐ The machine stamps, so a two-machine day is REFUSED rather than measured.
            ->when(VehicleService::stampsAvailable(),
                fn ($q) => $q->addSelect(['meter_start_vehicle_id', 'meter_end_vehicle_id']))
            ->where('attendance_date', $date)
            ->whereNotNull('meter_start')->whereNotNull('meter_end')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            /**
             * ⚠⚠ THE 500 km BOUND IS NOT A SPLIT-DAY GUARD (13-Sep-2026). It happens to catch
             *    Rajab's 7,610 → 75,484, but only because those two odometers are far apart.
             *    Two company bikes at 18,040 and 18,310 would produce a plausible-looking
             *    "270 km day" that belongs to neither machine, and nothing would flag it.
             *    `MeterPairHelper::distance()` answers null whenever the two ends are stamped to
             *    different machines — the same ONE rule the attendance page, the ⛽ tick, the
             *    phone and the month total now use.
             */
            $km = MeterPairHelper::distance($r);
            // reject impossible readings (dropped digits / test rows) rather than
            // showing a nonsense number — see the Jun/Jul meter typos.
            if ($km !== null && $km <= 500 && (int) $r->meter_start > 1000) {
                $out[(int) $r->user_id] = $km;
            }
        }
        return $out;
    }

    // =================================================================
    // RIDER LEVEL
    // =================================================================

    /**
     * One rider's day: order rows with verdicts, stops, gaps and a simplified route.
     * Returns null when the rider has nothing that day.
     */
    public function riderDay(int $userId, string $date): ?array
    {
        $name = DB::table('t_sys_user')->where('id', $userId)->value('fullname') ?: 'Rider';
        $tz   = $this->report->detectTzOffset();

        $rep = $this->report->computeForRider($userId, $name, $date, $tz);
        $trail = $this->report->trailForDay($userId, $date, true);

        $orders = [];
        if ($rep && !empty($rep['orders'])) {
            $ofd = $this->ofdTimes(array_column($rep['orders'], 'order_id'), $tz);
            $plannedRank = $this->plannedRankByOrder($rep['orders']);
            foreach ($rep['orders'] as $o) {
                $o['planned_rank'] = $plannedRank[$o['order_id']] ?? null;
                $dispatchedAt = $this->pickDispatch(
                    $ofd[$o['order_id']] ?? null,
                    strtotime($o['delivered_at'])
                );
                $orders[] = $this->decorateOrder($o, $trail, $dispatchedAt);
            }
        }

        // today: append what he is still carrying
        $inFlight = ($date === Carbon::today()->format('Y-m-d'))
            ? $this->riderInFlight($userId) : [];

        if (!$orders && !$inFlight) {
            return null;
        }

        return [
            'user_id'    => $userId,
            'name'       => $name,
            'orders'     => $orders,
            'in_flight'  => $inFlight,
            'stops'      => $rep['stops'] ?? [],
            'gaps'       => $rep['gaps'] ?? [],
            'route'      => $this->simplifyRoute($trail),
            'has_trail'  => count($trail) > 0,
            // Dispatch waves: what the old Dispatch Tracker grouped by. Without
            // this, Day Review could say WHEN an order was dispatched but not
            // which batch it belonged to, nor whether the run was re-timed
            // mid-way — both of which the Tracker showed.
            'waves'      => $this->attachWaveStory($this->waveSummary($orders), $rep['dispatch_log'] ?? []),
            'mid_run_changes' => $rep['mid_run_changes'] ?? [],
        ];
    }

    /**
     * When each order went out for delivery — the "dispatched" half of the
     * dispatched → promised → delivered line that replaces the Dispatch Tracker.
     * Timezone-corrected the same way the report corrects delivered_at, so the
     * three times on one row are always on the same clock.
     */
    private function ofdTimes(array $orderIds, int $tz): array
    {
        if (!$orderIds) return [];
        // An order can go out-for-delivery more than once (re-dispatch, or a
        // correction logged after the fact). Taking MIN() blindly can therefore
        // hand back a "dispatched" time LATER than the delivery. Pull every row
        // and let the caller pick the run that actually led to this delivery.
        $rows = DB::table('t_crm_order_status_history')
            ->select('order_id', 'changed_at')
            ->whereIn('order_id', $orderIds)
            ->where('status_code', 'out_for_delivery')
            ->orderBy('changed_at')
            ->get();

        $secs = $tz * 60;
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->order_id][] = strtotime($r->changed_at) - $secs;
        }
        return $out;
    }

    /**
     * The dispatch that led to THIS delivery: the latest out-for-delivery stamp
     * at or before the drop. Returns null when every stamp post-dates the
     * delivery (bad data) rather than showing an impossible "dispatched after
     * delivered" time.
     */
    private function pickDispatch(?array $stamps, int $delTs): ?string
    {
        if (!$stamps) return null;
        $best = null;
        foreach ($stamps as $ts) {
            if ($ts <= $delTs && ($best === null || $ts > $best)) $best = $ts;
        }
        return $best !== null ? date('Y-m-d H:i:s', $best) : null;
    }

    /** Adds the two verdicts + the map slice to one report order row. */
    private function decorateOrder(array $o, array $trail, ?string $ofdAt = null): array
    {
        $delTs = strtotime($o['delivered_at']);

        // --- 1. did he ever reach the customer's saved pin? ---
        $cross = ['state' => 'no_pin', 'closest_m' => null, 'at' => null];
        if (!empty($o['has_verified']) && $o['verified_lat'] !== null) {
            if (!$trail) {
                $cross['state'] = 'no_gps';
            } else {
                $bestM = null; $bestTs = null;
                foreach ($trail as $p) {
                    $d = $this->report->distanceM($p['lat'], $p['lng'], (float) $o['verified_lat'], (float) $o['verified_lng']);
                    if ($bestM === null || $d < $bestM) { $bestM = $d; $bestTs = $p['ts']; }
                }
                // WHEN he was closest matters as much as whether: reaching the pin
                // 15 min AFTER pressing delivered is a different story from being
                // there at the time. Negative = before the press, positive = after.
                $cross = [
                    'state'      => $bestM !== null && $bestM <= self::PIN_CROSS_M ? 'crossed' : 'not_crossed',
                    'closest_m'  => $bestM !== null ? (int) round($bestM) : null,
                    'at'         => $bestTs ? date('H:i', $bestTs) : null,
                    'delta_min'  => $bestTs ? (int) round(($bestTs - $delTs) / 60) : null,
                ];
            }
        }

        // --- 2. does the delivered marker agree with the trail at that minute? ---
        // press_trail_m (from the report) is the nearest gated fix to the delivered
        // marker within ±5 min. Large = the marker and the rider disagree.
        $press = ['state' => 'no_gps', 'trail_m' => $o['press_trail_m'] ?? null];
        if (!empty($o['gps_ok'])) {
            $press['state'] = ($o['press_trail_m'] !== null && $o['press_trail_m'] > self::GLITCH_M)
                ? 'disagrees' : 'agrees';
        }

        // Only meaningful to call it a glitch when the delivered marker is BOTH far
        // from the customer pin AND far from where he actually was.
        $farFromPin = ($o['at_verified'] === 0);
        $verdict = 'ok';
        if ($farFromPin) {
            if ($press['state'] === 'disagrees' && $cross['state'] === 'crossed') {
                $verdict = 'likely_glitch';        // he got there; the marker is wrong
            } elseif ($press['state'] === 'agrees') {
                $verdict = 'really_away';          // trail agrees — genuinely delivered away
            } elseif ($cross['state'] === 'not_crossed') {
                $verdict = 'never_reached';        // never got near the pin at all
            } else {
                $verdict = 'unverifiable';         // no usable GPS
            }
        }

        $lateMg  = (int) $this->cfg('late_manager_minutes', 15);
        $needs   = ($o['late_minutes'] !== null && $o['late_minutes'] > $lateMg)
                   || $farFromPin
                   || ($o['gps_ok'] === 0 && $o['pin_lat'] !== null);

        return array_merge($o, [
            'dispatched_at' => $ofdAt,
            'pin_cross'     => $cross,
            'press_check'   => $press,
            'away_verdict'  => $verdict,
            'needs_look'    => $needs ? 1 : 0,
            'slice'         => $this->sliceAround($trail, $delTs, 20 * 60),
        ]);
    }

    /**
     * The order the store PLANNED each wave to be delivered in — order_id => 1..N.
     *
     * ⚠️ Do NOT use delivery_priority for this. It is a live counter that gets
     * renumbered from 1 over the REMAINING stops every time the rider re-orders
     * mid-route, so a finished wave can hold two "P1"s — meaning a comparison
     * against it silently misses the very re-ordering a manager wants to catch.
     *
     * The ETA is assigned once, when dispatch is pressed, and is never renumbered.
     * Ranking a wave by its ETAs therefore recovers the plan as it stood at
     * dispatch. This mirrors the old Dispatch Tracker's `plannedRankById` exactly
     * (RiderController::getDateDeliveryReport) so both screens agree; the tie-break
     * chain is copied verbatim — no-ETA last, then ETA, then priority, then id.
     */
    private function plannedRankByOrder(array $orders): array
    {
        $byWave = [];
        foreach ($orders as $o) {
            $byWave[$o['wave'] ?? 'none'][] = $o;
        }

        $rank = [];
        foreach ($byWave as $waveOrders) {
            usort($waveOrders, function ($a, $b) {
                $ka = $this->plannedSortKey($a);
                $kb = $this->plannedSortKey($b);
                return strcmp($ka, $kb);
            });
            foreach ($waveOrders as $i => $o) {
                $rank[$o['order_id']] = $i + 1;
            }
        }
        return $rank;
    }

    /** Sort key mirroring the Dispatch Tracker's: hasEta | eta | priority | id. */
    private function plannedSortKey(array $o): string
    {
        // eta_live is the order's own estimated_delivery_at — the value that was
        // written when THIS wave was dispatched. (eta_at can be an older promise
        // deliberately held from before a re-time, so it must not drive the plan.)
        $eta    = $o['eta_live'] ?? $o['eta_at'] ?? null;
        $hasEta = empty($eta) ? '1' : '0';
        $eta    = $eta ?: '9999-12-31 23:59:59';
        $prio   = str_pad((string) ($o['planned_seq'] ?? 999999), 7, '0', STR_PAD_LEFT);
        $id     = str_pad((string) ($o['order_id'] ?? 0), 12, '0', STR_PAD_LEFT);
        return "{$hasEta}|{$eta}|{$prio}|{$id}";
    }

    /**
     * One entry per dispatch wave, in the order the waves went out.
     *
     * A "wave" is one press of dispatch: every order timed in that batch shares
     * `eta_calculated_at`. Orders never dispatched at all collect under a null
     * key — the Dispatch Tracker's "not dispatched yet" group, which is exactly
     * the case a manager most needs to see.
     */
    private function waveSummary(array $orders): array
    {
        $waves = [];
        foreach ($orders as $o) {
            $key = $o['wave'] ?? null;
            $k = $key === null ? 'none' : (string) $key;
            if (!isset($waves[$k])) {
                $waves[$k] = [
                    'key'          => $k,
                    'dispatched_at'=> $key,          // null = never dispatched
                    'orders'       => 0,
                    'late'         => 0,
                    'out_of_order' => 0,
                    'seq_checked'  => 0,
                    'seq_followed' => 0,
                    'first_drop'   => null,
                    'last_drop'    => null,
                ];
            }
            $waves[$k]['orders']++;
            if (($o['late_minutes'] ?? null) !== null && $o['late_minutes'] > (int) $this->cfg('late_manager_minutes', 15)) {
                $waves[$k]['late']++;
            }
            // Delivered in a different position than the store PLANNED at dispatch.
            // Measured against planned_rank (the stable ETA-derived plan), never
            // delivery_priority — see plannedRankByOrder() for why.
            if (($o['planned_rank'] ?? null) !== null && ($o['actual_seq'] ?? null) !== null) {
                $waves[$k]['seq_checked']++;
                if ((int) $o['planned_rank'] === (int) $o['actual_seq']) {
                    $waves[$k]['seq_followed']++;
                } else {
                    $waves[$k]['out_of_order']++;
                }
            }
            $d = $o['delivered_at'] ?? null;
            if ($d) {
                if ($waves[$k]['first_drop'] === null || $d < $waves[$k]['first_drop']) $waves[$k]['first_drop'] = $d;
                if ($waves[$k]['last_drop'] === null || $d > $waves[$k]['last_drop']) $waves[$k]['last_drop'] = $d;
            }
        }

        // Chronological, with the never-dispatched group last (it has no time).
        uasort($waves, function ($a, $b) {
            if ($a['dispatched_at'] === null) return 1;
            if ($b['dispatched_at'] === null) return -1;
            return strcmp((string) $a['dispatched_at'], (string) $b['dispatched_at']);
        });

        return array_values($waves);
    }

    /**
     * Who pressed each wave and what it was (Sep-2026) — so the card can say
     * "Dispatched 6:52 PM by Taimur · re-time of Asim's 5:59 PM" instead of
     * leaving a store press to read like the rider's own. Additive keys only.
     */
    private function attachWaveStory(array $waves, array $dispatchLog): array
    {
        $byBatch = [];
        foreach ($dispatchLog as $a) {
            if ($a['type'] === 'dispatch') $byBatch[$a['batch_ts']] = $a;
        }
        foreach ($waves as &$w) {
            $a = $w['dispatched_at'] !== null ? ($byBatch[(string) $w['dispatched_at']] ?? null) : null;
            if (!$a) continue;
            $w['by_name']       = $a['by_name'];
            $w['by_self']       = $a['by_self'];
            $w['kind']          = $a['kind'];
            $w['after_cancel']  = $a['after_cancel'] ?? false;
            $w['shift_min']     = $a['shift_min'] ?? null;
            $w['prior_at']      = $a['prior_at'] ?? null;
            $w['prior_by_self'] = $a['prior_by_self'] ?? null;
            $w['origin_source'] = $a['origin_source'] ?? null;
            $w['rider_distance_m'] = $a['rider_distance_m'] ?? null;
        }
        unset($w);
        return $waves;
    }

    /** Orders this rider is carrying right now. */
    private function riderInFlight(int $userId): array
    {
        $rows = DB::select(
            "SELECT o.id, o.order_number, o.name AS customer_name, o.estimated_delivery_at AS eta,
                    o.eta_calculated_at AS wave, o.total_price, o.payment_method,
                    o.delivery_priority, ofd.ofd_at
               FROM t_crm_prod_order o
               LEFT JOIN (
                    SELECT order_id, MIN(changed_at) AS ofd_at
                      FROM t_crm_order_status_history
                     WHERE status_code = 'out_for_delivery'
                     GROUP BY order_id
               ) ofd ON ofd.order_id = o.id
              WHERE o.assigned_rider_user_id = ?
                AND o.order_status = 'out_for_delivery'
              ORDER BY COALESCE(o.estimated_delivery_at, ofd.ofd_at)",
            [$userId]
        );

        // C10 (Sep-29): judged against the PROMISE, like his delivered rows (eta_at) and
        // the day list; eta_live = what the customer was last told. Pre-log → the live ETA.
        $promises = EtaPromiseService::promisesFor(array_map(fn ($r) => (int) $r->id, $rows));

        $now = time();
        $out = [];
        foreach ($rows as $r) {
            $eta = $promises[(int) $r->id]['promised_at'] ?? $r->eta;
            $etaTs = !empty($eta) ? strtotime($eta) : null;
            $out[] = [
                'order_id'      => (int) $r->id,
                'order_number'  => $r->order_number,
                'customer_name' => trim((string) $r->customer_name),
                'amount'        => (float) $r->total_price,
                'eta_at'        => $eta,
                'eta_live'      => $r->eta,
                'dispatched_at' => $r->ofd_at,
                'was_dispatched'=> $r->wave !== null ? 1 : 0,
                'planned_seq'   => $r->delivery_priority !== null ? (int) $r->delivery_priority : null,
                'mins_left'     => $etaTs ? (int) round(($etaTs - $now) / 60) : null,
                'overdue'       => $etaTs !== null && $etaTs < $now ? 1 : 0,
            ];
        }
        return $out;
    }

    // =================================================================
    // TRAIL SHAPING
    // =================================================================

    /**
     * Thin the trail for drawing: ~1 point/minute, always keeping the ends and
     * any point where the direction changes sharply (so corners survive).
     */
    private function simplifyRoute(array $pts): array
    {
        $n = count($pts);
        if ($n === 0) return [];
        if ($n <= 3) return array_map(fn ($p) => $this->pt($p), $pts);

        $keep = [$pts[0]];
        $lastKept = $pts[0];

        for ($i = 1; $i < $n - 1; $i++) {
            $p = $pts[$i];
            $dt = $p['ts'] - $lastKept['ts'];
            $dm = $this->report->distanceM($lastKept['lat'], $lastKept['lng'], $p['lat'], $p['lng']);

            $corner = false;
            if ($dm > 30) {
                $b1 = $this->bearing($lastKept, $p);
                $b2 = $this->bearing($p, $pts[$i + 1]);
                $diff = abs($b1 - $b2);
                if ($diff > 180) $diff = 360 - $diff;
                $corner = $diff > 40;
            }

            if ($dt >= 60 || $corner) {
                $keep[] = $p;
                $lastKept = $p;
            }
        }
        $keep[] = $pts[$n - 1];

        // hard cap — drop evenly rather than truncating the tail
        $c = count($keep);
        if ($c > self::ROUTE_MAX_POINTS) {
            $step = $c / self::ROUTE_MAX_POINTS;
            $thin = [];
            for ($i = 0; $i < self::ROUTE_MAX_POINTS; $i++) {
                $thin[] = $keep[(int) floor($i * $step)];
            }
            $thin[] = $keep[$c - 1];
            $keep = $thin;
        }

        return array_map(fn ($p) => $this->pt($p), $keep);
    }

    /** A short window of trail around a moment — the drawer's mini-map. */
    private function sliceAround(array $pts, int $ts, int $halfWindowSecs): array
    {
        $slice = [];
        foreach ($pts as $p) {
            if (abs($p['ts'] - $ts) <= $halfWindowSecs) $slice[] = $p;
        }
        return $this->simplifyRoute($slice);
    }

    private function pt(array $p): array
    {
        return ['lat' => round($p['lat'], 6), 'lng' => round($p['lng'], 6), 't' => date('H:i', $p['ts'])];
    }

    private function bearing(array $a, array $b): float
    {
        $dLon = deg2rad($b['lng'] - $a['lng']);
        $la1 = deg2rad($a['lat']); $la2 = deg2rad($b['lat']);
        $y = sin($dLon) * cos($la2);
        $x = cos($la1) * sin($la2) - sin($la1) * cos($la2) * cos($dLon);
        return fmod(rad2deg(atan2($y, $x)) + 360, 360);
    }
}
