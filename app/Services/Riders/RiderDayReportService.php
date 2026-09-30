<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Services\Riders\EtaPromiseService;
use Carbon\Carbon;

/**
 * RiderDayReportService — Phase 1 (Jul-2026)
 *
 * Rule engine behind the Rider Report Card + Manager Daily Issues reports.
 * Reads only existing tables (orders, status history, GPS trail, customers,
 * company locations, attendance) and returns / persists computed facts.
 * No AI. Every threshold comes from config/rider_reports.php.
 *
 * Timezone: production stores every timestamp in PKT, so nothing needs
 * shifting there ($tzOffsetMin = 0). On a replica loaded in another
 * timezone the TIMESTAMP columns (delivered/OFD changed_at) can read
 * shifted vs the literal DATETIME columns (GPS captured_at, ETAs); pass
 * $tzOffsetMin (e.g. 120) to subtract that shift for local verification only.
 */
class RiderDayReportService
{
    private array $cfg;

    public function __construct()
    {
        $this->cfg = config('rider_reports');
    }

    // ---- public API ---------------------------------------------------

    /** Compute (do not persist) every active rider's facts for a date. */
    public function computeForDate(string $date, ?int $tzOffsetMin = null): array
    {
        $tz = $tzOffsetMin ?? $this->detectTzOffset();
        $out = [];
        foreach ($this->activeRiders($date) as $r) {
            try {
                $rep = $this->computeForRider((int) $r->id, (string) $r->fullname, $date, $tz);
                if ($rep) {
                    $out[(int) $r->id] = $rep;
                }
            } catch (\Throwable $e) {
                Log::warning('RiderDayReport: rider compute failed', [
                    'user_id' => $r->id ?? null, 'date' => $date, 'error' => $e->getMessage(),
                ]);
            }
        }
        return $out;
    }

    /**
     * Auto-detect the TIMESTAMP skew (minutes) so times are correct on both
     * production (0) and a replica loaded in another timezone (+120, etc.),
     * with no config or env changes. captured_at (DATETIME, literal) and
     * created_at (TIMESTAMP, session-tz) are written from the same instant, so
     * their difference IS the shift. Rounded to the nearest hour, cached 1h.
     * Falls back to the config value if detection fails.
     */
    public function detectTzOffset(): int
    {
        return Cache::remember('rider_reports_tz_offset', 3600, function () {
            try {
                $r = DB::selectOne(
                    "SELECT ROUND(AVG(TIMESTAMPDIFF(MINUTE, captured_at, created_at)) / 60) * 60 AS off
                       FROM (SELECT captured_at, created_at FROM t_ops_rider_location
                              ORDER BY id DESC LIMIT 2000) t"
                );
                $off = ($r && $r->off !== null) ? (int) $r->off : (int) ($this->cfg['ts_offset_minutes'] ?? 0);
                return abs($off) > 1440 ? 0 : $off;   // sanity clamp
            } catch (\Throwable $e) {
                return (int) ($this->cfg['ts_offset_minutes'] ?? 0);
            }
        });
    }

    // ---- per-rider computation ---------------------------------------

    /**
     * @param array|null $timelineBounds  [from_ts, to_ts] epoch pair. When given
     *   (the Timeline view), stops/gaps span the whole shift instead of just the
     *   delivery window — so the full day (pre-dispatch HQ wait, etc.) is shown.
     *   The Issues report passes null → unchanged delivery-window behaviour.
     */
    public function computeForRider(int $userId, string $riderName, string $date, int $tz, ?array $timelineBounds = null): ?array
    {
        $dayStart = Carbon::parse($date)->startOfDay()->format('Y-m-d H:i:s');
        // deliveries can spill a couple hours past midnight; widen the window
        $dayEnd   = Carbon::parse($date)->addDay()->addHours(3)->format('Y-m-d H:i:s');
        // trail starts at midnight — never look into the previous day, or its
        // late-night activity gets mis-attributed as a stop on this report.
        $trailFrom = $dayStart;

        $pts = $this->loadTrail($userId, $trailFrom, $dayEnd);
        $orders = $this->loadOrders($userId, $dayStart, $dayEnd, $tz);
        if (empty($orders)) {
            return null;
        }

        // sort delivered orders chronologically, then number each WITHIN its
        // dispatch wave (matches the existing Dispatch Tracker's P3→#4 logic —
        // delivery_priority is a per-wave priority, not a whole-day rank).
        usort($orders, fn ($a, $b) => $a['_del_ts'] <=> $b['_del_ts']);
        $this->assignWaveSeq($orders);

        // bound the trail to the actual working window (first dispatch/drop − 30m
        // to last drop + 45m) so pre-shift and going-home wandering never flag.
        // The Timeline passes explicit shift bounds to show the whole day instead.
        $pts = $this->boundToWorkWindow($pts, $orders, $timelineBounds);

        // per-order location + timing facts
        foreach ($orders as &$o) {
            $this->fillOrderFacts($o, $pts);
        }
        unset($o);

        $stops = $this->detectStops($pts, $orders, $date);
        $gaps  = $this->detectGaps($pts, $orders);
        $oddRoutes = $this->detectOddRoutes($pts, $orders, $stops);
        $this->fillLateReasons($orders, $stops);

        // ⭐ Route changes he made himself AFTER he had already started dropping
        //    stops — the re-dispatches that move the goalposts. Derived from the
        //    dispatch log (no cron, no detection gap); empty before the log exists.
        $midRunChanges = EtaPromiseService::midRunChanges($userId, $date);

        // ⭐ Who pressed what (Sep-2026): the store re-timing or re-routing a route
        //    the rider HAD dispatched is not "the rider didn't press". Each order
        //    carries its wave's kind; the whole log feeds the Timeline.
        $dispatchLog = EtaPromiseService::dayDispatchLog($userId, $date, $tz);
        $kindByBatch = [];
        foreach ($dispatchLog as $a) {
            if ($a['type'] === 'dispatch') $kindByBatch[$a['batch_ts']] = $a['kind'];
        }
        foreach ($orders as &$o) {
            $o['store_kind'] = ($o['dispatched_by_other'] && $o['_wave'] !== null)
                ? ($kindByBatch[(string) $o['_wave']] ?? null) : null;
        }
        unset($o);
        $storeRedispatches = array_values(array_filter($dispatchLog,
            fn ($a) => $a['type'] === 'dispatch' && in_array($a['kind'], ['retime', 'reroute', 'next'], true)));

        $day = $this->aggregateDay($userId, $riderName, $date, $orders, $stops, $gaps, $oddRoutes, $midRunChanges);

        // Checkout audit: did he check out AT his last delivery point, when that point
        // wasn't the address's verified pin? (Reuses the same at_verified rule.)
        $checkout = $this->checkoutAudit($userId, $date, $orders);

        // shape order rows for storage
        $orderRows = array_map(fn ($o) => $this->orderRow($o), $orders);

        return ['day' => $day, 'orders' => $orderRows, 'stops' => $stops, 'gaps' => $gaps, 'odd_routes' => $oddRoutes, 'checkout' => $checkout, 'mid_run_changes' => $midRunChanges,
                'dispatch_log' => $dispatchLog, 'store_redispatches' => $storeRedispatches];
    }

    /**
     * ADDITIVE (Day Review, Jul-2026) — the same quality-gated trail the report
     * itself uses, exposed for the map polyline and proximity maths. Nothing in
     * the existing report path calls this; it only re-runs the private loader so
     * Day Review never has to duplicate the accuracy/speed/source gating rules.
     *
     * @return array<int,array{lat:float,lng:float,ts:int}> chronological
     */
    public function trailForDay(int $userId, string $date, bool $wholeDay = true): array
    {
        $dayStart = Carbon::parse($date)->startOfDay()->format('Y-m-d H:i:s');
        $dayEnd   = Carbon::parse($date)->addDay()->addHours(3)->format('Y-m-d H:i:s');
        $pts = $this->loadTrail($userId, $dayStart, $dayEnd);
        if ($wholeDay) {
            return $pts;
        }
        // caller wants it bounded the same way the report bounds it
        $orders = $this->loadOrders($userId, $dayStart, $dayEnd, $this->detectTzOffset());
        return $orders ? $this->boundToWorkWindow($pts, $orders, null) : $pts;
    }

    /** ADDITIVE (Day Review) — great-circle metres, exposed for reuse. */
    public function distanceM(float $la1, float $lo1, float $la2, float $lo2): float
    {
        return $this->haversine($la1, $lo1, $la2, $lo2);
    }

    // ---- data loaders -------------------------------------------------

    private function activeRiders(string $date): array
    {
        // any user with GPS trail on the day; real-rider filtering (role) is
        // applied in Phase 2's UI — Phase 1 stores facts for whoever moved.
        return DB::select(
            "SELECT DISTINCT u.id, u.fullname
               FROM t_sys_user u
               JOIN t_ops_rider_location l ON l.user_id = u.id
              WHERE l.captured_at BETWEEN ? AND ?",
            [Carbon::parse($date)->format('Y-m-d 00:00:00'),
             Carbon::parse($date)->addDay()->addHours(3)->format('Y-m-d H:i:s')]
        );
    }

    /** Quality-gated, de-duped, speed-filtered trail points. */
    private function loadTrail(int $userId, string $from, string $to): array
    {
        $excl = $this->cfg['exclude_sources'] ?? [];
        $rows = DB::table('t_ops_rider_location')
            ->select('id', 'latitude', 'longitude', 'accuracy', 'captured_at')
            ->where('user_id', $userId)
            ->whereBetween('captured_at', [$from, $to])
            ->where(function ($q) {
                $q->whereNull('accuracy')->orWhere('accuracy', '<=', $this->cfg['accuracy_max_m']);
            })
            ->when(!empty($excl), function ($q) use ($excl) {
                $q->where(function ($qq) use ($excl) {
                    $qq->whereNull('source')->orWhereNotIn('source', $excl);
                });
            })
            // Stable, deterministic order: per second keep the BEST-accuracy fix
            // (COALESCE so null accuracy sorts last), id as final tiebreaker —
            // otherwise same-second duplicates de-dup differently each run and
            // stop clustering flip-flops near boundaries.
            ->orderByRaw('captured_at ASC, COALESCE(accuracy, 999999) ASC, id ASC')
            ->get();

        $pts = [];
        $seen = [];
        foreach ($rows as $p) {
            if (isset($seen[$p->captured_at])) continue;   // one (best) fix per second
            $seen[$p->captured_at] = true;
            $pts[] = ['lat' => (float) $p->latitude, 'lng' => (float) $p->longitude,
                      'ts' => strtotime($p->captured_at)];
        }
        // the query ordered by accuracy within a second; re-sort chronologically
        usort($pts, fn ($a, $b) => $a['ts'] <=> $b['ts']);
        // speed gate vs last kept point
        $gated = [];
        foreach ($pts as $p) {
            if ($gated) {
                $pr = $gated[count($gated) - 1];
                $dt = max(1, $p['ts'] - $pr['ts']);
                if ($this->haversine($pr['lat'], $pr['lng'], $p['lat'], $p['lng']) / $dt * 3.6 > $this->cfg['speed_max_kmh']) {
                    continue;
                }
            }
            $gated[] = $p;
        }
        return $gated;
    }

    /** Delivered orders for the rider that day, with pin/verified/eta/OFD. */
    private function loadOrders(int $userId, string $from, string $to, int $tz): array
    {
        // delivery_accuracy_m only exists after the GPS-accuracy hardening SQL; select it
        // conditionally so this report keeps working on a DB where that hasn't run yet.
        $accCol = \Illuminate\Support\Facades\Schema::hasColumn('t_crm_order_status_history', 'delivery_accuracy_m')
            ? 'h.delivery_accuracy_m'
            : 'NULL';
        $rows = DB::select(
            "SELECT o.id, o.order_number, o.name AS customer_name, o.customer_id,
                    o.total_price, o.payment_method, o.delivery_priority, o.estimated_delivery_at AS eta,
                    o.eta_calculated_at AS wave, o.eta_calculated_by AS dispatched_by_id, du.fullname AS dispatched_by_name,
                    h.changed_at AS delivered_raw, h.delivery_latitude AS pin_lat, h.delivery_longitude AS pin_lng,
                    {$accCol} AS delivery_accuracy_m,
                    c.latitude AS verified_lat, c.longitude AS verified_lng,
                    ofd.ofd_raw
               FROM t_crm_prod_order o
               JOIN t_crm_order_status_history h
                    ON h.order_id = o.id AND h.status_code = 'delivered'
               LEFT JOIN t_crm_prod_customer c ON c.id = o.customer_id
               LEFT JOIN t_sys_user du ON du.id = o.eta_calculated_by
               LEFT JOIN (
                    SELECT order_id, MIN(changed_at) AS ofd_raw
                      FROM t_crm_order_status_history
                     WHERE status_code = 'out_for_delivery'
                     GROUP BY order_id
               ) ofd ON ofd.order_id = o.id
              WHERE o.assigned_rider_user_id = ?
                AND h.changed_at BETWEEN ? AND ?
              ORDER BY h.changed_at",
            [$userId, $from, $to]
        );

        $secs = $tz * 60;
        $dayFloor = strtotime(Carbon::parse($from)->format('Y-m-d 00:00:00'));

        // ⭐ The PROMISE: the ETA the rider is actually held to. o.estimated_delivery_at
        //    is overwritten by every dispatch, so measuring lateness against it let a
        //    late rider press Re-dispatch and wash the lateness away. EtaPromiseService
        //    replays the dispatch log: a store-initiated re-time moves the yardstick
        //    (management re-planned him), a rider-initiated one does not.
        //    Absent for orders dispatched before the log existed → we keep today's
        //    behaviour for them (fall back to the order's current ETA).
        $promises = EtaPromiseService::promisesFor(array_map(fn ($r) => (int) $r->id, $rows));

        $out = [];
        foreach ($rows as $r) {
            $delTs = strtotime($r->delivered_raw) - $secs;
            // keep only orders whose (corrected) delivery lands on the report day window
            if ($delTs < $dayFloor) continue;
            $promise = $promises[(int) $r->id] ?? null;
            $out[] = [
                'order_id'      => (int) $r->id,
                'order_number'  => $r->order_number,
                'customer_id'   => $r->customer_id ? (int) $r->customer_id : null,
                'customer_name' => trim((string) $r->customer_name),
                'amount'        => (float) $r->total_price,
                'payment_type'  => $this->isCash($r->payment_method) ? 'cash' : 'online',
                'planned_seq'   => $r->delivery_priority !== null ? (int) $r->delivery_priority : null,
                // eta_at = what he is JUDGED against (the promise; the live ETA only
                // when this order predates the log). eta_live = what the customer was
                // last told, shown alongside when a re-time moved them apart.
                'eta_at'        => $promise['promised_at'] ?? $r->eta,   // DATETIME, literal
                'eta_live'      => $r->eta,
                'eta_retimed'      => $promise['retimed'] ?? false,
                'eta_retimed_by_rider' => $promise['retimed_by_rider'] ?? false,
                'eta_promise_is_store' => $promise['promise_is_store'] ?? null,
                // What the rider's OWN dispatch had promised before the store
                // re-dispatched (null when the store never stepped in).
                'eta_pre_store' => $promise['pre_store_promised_at'] ?? null,
                '_wave'         => $r->wave,                      // dispatch wave key (eta_calculated_at)
                // who pressed dispatch: self, or someone else did it for them
                'dispatched_by_other' => ($r->wave !== null && (int) $r->dispatched_by_id !== $userId) ? 1 : 0,
                'dispatched_by_name'  => ($r->wave !== null && (int) $r->dispatched_by_id !== $userId) ? $r->dispatched_by_name : null,
                'pin_lat'       => $r->pin_lat !== null ? (float) $r->pin_lat : null,
                'pin_lng'       => $r->pin_lng !== null ? (float) $r->pin_lng : null,
                'delivery_accuracy_m' => $r->delivery_accuracy_m !== null ? (float) $r->delivery_accuracy_m : null,
                'verified_lat'  => $r->verified_lat !== null ? (float) $r->verified_lat : null,
                'verified_lng'  => $r->verified_lng !== null ? (float) $r->verified_lng : null,
                '_del_ts'       => $delTs,
                '_ofd_ts'       => $r->ofd_raw ? strtotime($r->ofd_raw) - $secs : null,
            ];
        }
        return $out;
    }

    /**
     * Checkout-location audit (Phase H, report-first — independent of the enforcement
     * switch). Flags when the rider checked out AT his MOST RECENT delivery point, but
     * that delivery point was NOT the address's verified pin (using the SAME unified
     * at_verified_m rule as the delivered-order screen). Catches "marked delivered from
     * the wrong place, then checked out there". Returns null when nothing to flag.
     */
    private function checkoutAudit(int $userId, string $date, array $orders): ?array
    {
        $att = DB::table('t_ops_attendance')
            ->where('user_id', $userId)->whereDate('attendance_date', $date)
            ->select('logout_time', 'checkout_latitude', 'checkout_longitude')->first();
        if (!$att || empty($att->logout_time) || $att->checkout_latitude === null || $att->checkout_longitude === null) {
            return null;
        }
        // most recent delivered order (orders are sorted ascending by _del_ts)
        $last = !empty($orders) ? end($orders) : null;
        if (!$last || $last['pin_lat'] === null) {
            return null;
        }
        // was the checkout AT that delivery point (within the same radius the gate uses)?
        $dToPin = $this->haversine((float) $att->checkout_latitude, (float) $att->checkout_longitude, $last['pin_lat'], $last['pin_lng']);
        if ($dToPin > $this->checkoutRadiusM()) {
            return null; // checked out elsewhere (office / another spot) — not this audit
        }
        // only flag when the address HAS a verified pin AND that delivery point is NOT
        // "at verified" (i.e. the drop was recorded > at_verified_m from the saved pin).
        if (empty($last['has_verified']) || $last['at_verified'] === null || (int) $last['at_verified'] === 1) {
            return null;
        }
        return [
            'flagged'            => 1,
            'logout_time'        => substr((string) $att->logout_time, 0, 5),
            'order_number'       => $last['order_number'],
            'customer_name'      => $last['customer_name'],
            'pin_distance_m'     => $last['pin_distance_m'],   // delivery point → verified pin
            'checkout_to_pin_m'  => (int) round($dToPin),      // checkout → delivery point
        ];
    }

    /** Checkout "at that delivery point" radius — same t_fin_config value the gate uses. */
    private function checkoutRadiusM(): float
    {
        try {
            $v = DB::table('t_fin_config')->where('config_key', 'CHECKOUT_DELIVERY_RADIUS_M')->value('config_value');
            return ($v !== null && $v !== '') ? (float) $v : 150.0;
        } catch (\Throwable $e) {
            return 150.0;
        }
    }

    // ---- per-order facts ----------------------------------------------

    private function fillOrderFacts(array &$o, array $pts): void
    {
        // on-time
        if (!empty($o['eta_at'])) {
            $late = (int) round(($o['_del_ts'] - strtotime($o['eta_at'])) / 60);
            $o['late_minutes'] = $late;
            $o['on_time'] = $late <= $this->cfg['late_card_minutes'] ? 1 : 0;
        } else {
            $o['late_minutes'] = null;
            $o['on_time'] = null;
        }
        // Against the rider's own times, when the store re-timed him — shown
        // alongside, never used for on_time/late_minutes.
        $o['own_late_minutes'] = !empty($o['eta_pre_store'])
            ? (int) round(($o['_del_ts'] - strtotime($o['eta_pre_store'])) / 60)
            : null;

        // pin vs verified — VerifiedPinRule is the ONE implementation of this verdict; the
        // store's Delivered Orders screen and the dispatch tracker now ask the same class, so
        // two screens can no longer disagree about one delivery. (The forgiveness-for-a-vague-
        // fix rule that used to be written out here lives in that class unchanged.)
        $o['has_verified'] = ($o['verified_lat'] !== null && $o['verified_lng'] !== null) ? 1 : 0;
        $o['pin_distance_m'] = null;
        $o['at_verified'] = null;
        $verdict = VerifiedPinRule::judge(
            $o['pin_lat'], $o['pin_lng'], $o['verified_lat'], $o['verified_lng'], $o['delivery_accuracy_m']
        );
        if ($verdict) {
            $o['pin_distance_m'] = $verdict['distance_m'];
            $o['at_verified'] = $verdict['at_verified'] ? 1 : 0;
            // Flagged separately so the UI can say "fix was coarse" instead of implying he moved.
            $o['fix_coarse'] = $verdict['fix_coarse'] ? 1 : 0;
        }

        // press proof: nearest gated trail point within ±5 min of the press
        $o['gps_ok'] = 0;
        $o['press_trail_m'] = null;
        if ($o['pin_lat'] !== null) {
            $best = null;
            foreach ($pts as $p) {
                if (abs($p['ts'] - $o['_del_ts']) <= 300) {
                    $d = $this->haversine($p['lat'], $p['lng'], $o['pin_lat'], $o['pin_lng']);
                    if ($best === null || $d < $best) $best = $d;
                }
            }
            if ($best !== null) {
                $o['gps_ok'] = 1;
                $o['press_trail_m'] = (int) round($best);
            }
        }

        // door wait: minutes with a gated fix within 120 m of the pin around the press
        $o['door_wait_min'] = null;
        if ($o['pin_lat'] !== null) {
            $t0 = null; $t1 = null;
            foreach ($pts as $p) {
                if (abs($p['ts'] - $o['_del_ts']) <= 1800 &&
                    $this->haversine($p['lat'], $p['lng'], $o['pin_lat'], $o['pin_lng']) <= 120) {
                    $t0 = $t0 === null ? $p['ts'] : min($t0, $p['ts']);
                    $t1 = $t1 === null ? $p['ts'] : max($t1, $p['ts']);
                }
            }
            if ($t0 !== null && ($t1 - $t0) >= $this->cfg['door_wait_minutes'] * 60) {
                $o['door_wait_min'] = (int) round(($t1 - $t0) / 60);
            }
        }
    }

    // ---- stops --------------------------------------------------------

    private function detectStops(array $pts, array $orders, string $date): array
    {
        $known = DB::table('t_ops_company_locations')->where('is_active', 1)
            ->select('location_name', 'latitude', 'longitude')->get();

        $stops = [];
        $i = 0; $n = count($pts);
        while ($i < $n) {
            $j = $i;
            while ($j + 1 < $n &&
                   $this->haversine($pts[$i]['lat'], $pts[$i]['lng'], $pts[$j + 1]['lat'], $pts[$j + 1]['lng']) < $this->cfg['stop_cluster_m']) {
                $j++;
            }
            $dur = $pts[$j]['ts'] - $pts[$i]['ts'];
            if ($dur >= $this->cfg['stop_min_minutes'] * 60) {
                $cnt = $j - $i + 1;
                $la = 0; $lo = 0;
                for ($k = $i; $k <= $j; $k++) { $la += $pts[$k]['lat']; $lo += $pts[$k]['lng']; }
                $la /= $cnt; $lo /= $cnt;

                $label = 'unknown'; $known_place = false;
                foreach ($known as $kl) {
                    if ($this->haversine($la, $lo, (float) $kl->latitude, (float) $kl->longitude) < $this->cfg['stop_match_m']) {
                        $label = $kl->location_name; $known_place = true; break;
                    }
                }
                if ($label === 'unknown') {
                    foreach ($orders as $o) {
                        if ($o['pin_lat'] !== null &&
                            $this->haversine($la, $lo, $o['pin_lat'], $o['pin_lng']) < $this->cfg['stop_match_m']) {
                            $label = 'customer ' . $o['order_number']; break;
                        }
                    }
                }

                $isUnknown = ($label === 'unknown');
                $onBoard = 0;
                if ($isUnknown) {
                    foreach ($orders as $o) {
                        $load = $o['_ofd_ts'] ?? ($o['_del_ts'] - 3600);
                        if ($load <= $pts[$j]['ts'] && $o['_del_ts'] >= $pts[$i]['ts']) $onBoard++;
                    }
                }
                $context = !$isUnknown ? 'accounted'
                    : ($onBoard > 0 ? 'on_route'
                        : ($pts[$i]['ts'] > $this->lastDeliveryTs($orders) ? 'returning' : 'between'));

                $stops[] = [
                    'from'     => date('H:i', $pts[$i]['ts']),
                    'to'       => date('H:i', $pts[$j]['ts']),
                    'min'      => (int) round($dur / 60),
                    'lat'      => round($la, 6),
                    'lng'      => round($lo, 6),
                    'label'    => $label,
                    'is_unknown' => $isUnknown ? 1 : 0,
                    'on_board' => $onBoard,
                    'context'  => $context,
                    'area'     => null,   // Phase 2: reverse-geocode lazily
                    'map_url'  => 'https://www.google.com/maps?q=' . round($la, 6) . ',' . round($lo, 6),
                    '_from_ts' => $pts[$i]['ts'],
                    '_to_ts'   => $pts[$j]['ts'],
                ];
            }
            $i = $j + 1;
        }
        return $stops;
    }

    /** Legs between consecutive drops where he drove far more than the direct line. */
    private function detectOddRoutes(array $pts, array $orders, array $stops): array
    {
        $flagged = [];
        for ($x = 1; $x < count($orders); $x++) {
            $a = $orders[$x - 1]; $b = $orders[$x];
            if ($a['pin_lat'] === null || $b['pin_lat'] === null) continue;
            // route resets at a known place (e.g. HQ) — skip legs that pass through one
            $through = false;
            foreach ($stops as $s) {
                if (!$s['is_unknown'] && $s['_from_ts'] >= $a['_del_ts'] && $s['_to_ts'] <= $b['_del_ts'] + 300) {
                    $through = true; break;
                }
            }
            if ($through) continue;

            $straight = $this->haversine($a['pin_lat'], $a['pin_lng'], $b['pin_lat'], $b['pin_lng']);
            $trav = 0; $prev = null;
            foreach ($pts as $p) {
                if ($p['ts'] >= $a['_del_ts'] && $p['ts'] <= $b['_del_ts']) {
                    if ($prev) {
                        $d = $this->haversine($prev['lat'], $prev['lng'], $p['lat'], $p['lng']);
                        if ($d < 5000) $trav += $d;
                    }
                    $prev = $p;
                }
            }
            if ($straight > 500 && $trav > 0) {
                $ratio = $trav / $straight;
                if ($ratio > $this->cfg['odd_route_ratio'] && ($trav - $straight) > $this->cfg['odd_route_extra_m']) {
                    $flagged[] = ['from' => $a['order_number'], 'to' => $b['order_number'],
                                  'straight_km' => round($straight / 1000, 1),
                                  'trav_km' => round($trav / 1000, 1), 'ratio' => round($ratio, 1)];
                }
            }
        }
        return $flagged;
    }

    private function detectGaps(array $pts, array $orders): array
    {
        if (empty($pts) || empty($orders)) return [];
        $t0 = $orders[0]['_del_ts'] - 5400;
        $t1 = $this->lastDeliveryTs($orders);
        $gaps = [];
        for ($x = 1; $x < count($pts); $x++) {
            $dt = $pts[$x]['ts'] - $pts[$x - 1]['ts'];
            if ($dt > $this->cfg['gap_min_minutes'] * 60 && $pts[$x]['ts'] > $t0 && $pts[$x - 1]['ts'] < $t1) {
                $gaps[] = ['from' => date('H:i', $pts[$x - 1]['ts']),
                           'to' => date('H:i', $pts[$x]['ts']),
                           'min' => (int) round($dt / 60)];
            }
        }
        return $gaps;
    }

    // ---- late reasons (only when certain) -----------------------------

    /**
     * Late-reason — Phase 1 keeps only the ONE cause we can prove: a genuine
     * unknown stop (≥ held_up_minutes) between the previous drop and this one.
     * Sequence changes are intentionally NOT derived here — delivery_priority
     * has ties/gaps, so exact-position matching over-flags; Phase 2 renders the
     * sequence badge by mirroring the existing Dispatch Tracker instead.
     */
    private function fillLateReasons(array &$orders, array $stops): void
    {
        foreach ($orders as $idx => &$o) {
            $o['late_reason'] = null;
            if ($o['late_minutes'] === null || $o['late_minutes'] <= $this->cfg['late_card_minutes']) {
                continue;
            }
            $prevTs = $idx > 0 ? $orders[$idx - 1]['_del_ts'] : null;
            if ($prevTs === null) continue;
            foreach ($stops as $s) {
                if ($s['is_unknown'] && $s['_from_ts'] >= $prevTs && $s['_to_ts'] <= $o['_del_ts']
                    && $s['min'] >= $this->cfg['held_up_minutes']) {
                    $o['late_reason'] = 'held_up';
                    break;
                }
            }
        }
        unset($o);
    }

    // ---- day aggregate ------------------------------------------------

    private function aggregateDay(int $userId, string $riderName, string $date, array $orders, array $stops, array $gaps, array $oddRoutes = [], array $midRunChanges = []): array
    {
        $att = DB::table('t_ops_attendance')->where('user_id', $userId)
            ->where('attendance_date', $date)->first();

        $etaTotal = 0; $ontime = 0; $hasVer = 0; $atVer = 0; $farPin = 0; $noPin = 0; $gpsOff = 0;
        $cashC = 0; $cashT = 0; $onlC = 0; $onlT = 0;
        foreach ($orders as $o) {
            if ($o['late_minutes'] !== null) { $etaTotal++; if ($o['on_time']) $ontime++; }
            if ($o['has_verified']) {
                $hasVer++;
                if ($o['at_verified'] === 1) $atVer++;
                elseif ($o['at_verified'] === 0) $farPin++;
                // at_verified === null → no pin captured at press; counted via gps_off, not "far"
            } else { $noPin++; }
            if (!$o['gps_ok']) $gpsOff++;
            if ($o['payment_type'] === 'cash') { $cashC++; $cashT += $o['amount']; }
            else { $onlC++; $onlT += $o['amount']; }
        }

        $unknownStops = array_values(array_filter($stops, fn ($s) => $s['is_unknown']));
        $onRoute = array_values(array_filter($unknownStops, fn ($s) => $s['on_board'] > 0));

        $checkin = $att && $att->login_time ? $date . ' ' . $att->login_time : null;
        $checkout = $att && $att->logout_time ? $date . ' ' . $att->logout_time : null;

        return [
            'rider_name'        => $riderName,
            'delivered_count'   => count($orders),
            'eta_total'         => $etaTotal,
            'ontime_count'      => $ontime,
            'has_verified_count'=> $hasVer,
            'at_verified_count' => $atVer,
            'far_pin_count'     => $farPin,
            'no_pin_count'      => $noPin,
            'gps_off_count'     => $gpsOff,
            'cash_count'        => $cashC,
            'cash_total'        => round($cashT, 2),
            'online_count'      => $onlC,
            'online_total'      => round($onlT, 2),
            'checkin_at'        => $checkin,
            'checkout_at'       => $checkout,
            'distance_km'       => $att && $att->road_distance_km !== null ? (float) $att->road_distance_km : null,
            'unknown_stop_count'=> count($unknownStops),
            'onroute_stop_count'=> count($onRoute),
            'gap_count'         => count($gaps),
            'odd_route_count'   => count($oddRoutes),
            // Re-dispatches he made himself once already part-way through the day.
            'mid_run_change_count' => count($midRunChanges),
            'stops_json'        => json_encode(array_map(fn ($s) => $this->publicStop($s), $unknownStops)),
            'gaps_json'         => json_encode($gaps),
            'flags_json'        => json_encode(['odd_routes' => $oddRoutes, 'mid_run_changes' => $midRunChanges]),
        ];
    }

    private function orderRow(array $o): array
    {
        return [
            'order_number'  => $o['order_number'],
            'customer_id'   => $o['customer_id'],
            'customer_name' => mb_substr($o['customer_name'] ?: 'Unknown', 0, 150),
            'delivered_at'  => date('Y-m-d H:i:s', $o['_del_ts']),
            'eta_at'        => $o['eta_at'],
            'late_minutes'  => $o['late_minutes'],
            'on_time'       => $o['on_time'],
            // The live ETA drifted from the promise (a re-dispatch after it was
            // committed). Lets a reader see "promised 5:10 → re-timed to 5:40 by
            // the rider" instead of silently judging against either one alone.
            'eta_live'              => $o['eta_live'] ?? null,
            'eta_retimed'           => !empty($o['eta_retimed']) ? 1 : 0,
            'eta_retimed_by_rider'  => !empty($o['eta_retimed_by_rider']) ? 1 : 0,
            'pin_lat'       => $o['pin_lat'],
            'pin_lng'       => $o['pin_lng'],
            'verified_lat'  => $o['verified_lat'],
            'verified_lng'  => $o['verified_lng'],
            'pin_distance_m'=> $o['pin_distance_m'],
            'at_verified'   => $o['at_verified'],
            'has_verified'  => $o['has_verified'],
            // Fix quality behind pin_distance_m — lets the UI say "coarse fix" instead of
            // implying the rider was somewhere else. NULL on every pre-hardening row.
            'fix_accuracy_m'=> $o['delivery_accuracy_m'] ?? null,
            'fix_coarse'    => $o['fix_coarse'] ?? 0,
            'gps_ok'        => $o['gps_ok'],
            'press_trail_m' => $o['press_trail_m'],
            'door_wait_min' => $o['door_wait_min'],
            'planned_seq'   => $o['planned_seq'],
            'actual_seq'    => $o['actual_seq'],
            'amount'        => $o['amount'],
            'payment_type'  => $o['payment_type'],
            'late_reason'   => $o['late_reason'],
            // dispatch button ("Get Times") was never pressed for this order —
            // eta_calculated_at is null. Catches "rider left without dispatching".
            'was_dispatched'=> $o['_wave'] !== null ? 1 : 0,
            // ADDITIVE (Day Review, Jul-2026): the dispatch WAVE this order rode in
            // (eta_calculated_at — the moment the store pressed dispatch for that
            // batch). Day Review groups by it so "which dispatch group was this in"
            // survives the Dispatch Tracker's removal. Existing consumers ignore it.
            'wave'          => $o['_wave'],
            // dispatch was pressed by someone OTHER than the rider (store manager
            // did it for them because the rider forgot).
            'dispatched_by_other' => $o['dispatched_by_other'],
            'dispatched_by_name'  => $o['dispatched_by_name'],
            // Sep-2026: WHAT the store's press was — 'first' (rider never pressed),
            // 'retime' (same route, new times), 'reroute' (route changed); null when
            // the rider pressed himself or the order predates the dispatch log.
            'store_kind'       => $o['store_kind'] ?? null,
            'eta_pre_store'    => $o['eta_pre_store'] ?? null,
            'own_late_minutes' => $o['own_late_minutes'] ?? null,
            'flags_json'    => json_encode([]),
            'order_id'      => $o['order_id'],
        ];
    }

    private function publicStop(array $s): array
    {
        return [
            'from' => $s['from'], 'to' => $s['to'], 'min' => $s['min'],
            'lat' => $s['lat'], 'lng' => $s['lng'], 'label' => $s['label'],
            'on_board' => $s['on_board'], 'context' => $s['context'],
            'area' => $s['area'], 'map_url' => $s['map_url'],
        ];
    }

    // ---- helpers ------------------------------------------------------

    /** Keep only trail points inside the day's working window (or explicit bounds). */
    private function boundToWorkWindow(array $pts, array $orders, ?array $bounds = null): array
    {
        if ($bounds !== null) {
            [$start, $end] = $bounds;
        } else {
            $start = PHP_INT_MAX; $end = 0;
            foreach ($orders as $o) {
                $load = $o['_ofd_ts'] ?? $o['_del_ts'];
                $start = min($start, $load, $o['_del_ts']);
                $end   = max($end, $o['_del_ts']);
            }
            $start -= 1800;   // 30 min before first dispatch/drop
            $end   += 2700;   // 45 min after last drop (covers returning to office)
        }
        return array_values(array_filter($pts, fn ($p) => $p['ts'] >= $start && $p['ts'] <= $end));
    }

    /** Number each order within its dispatch wave in delivery order (orders already sorted by time). */
    private function assignWaveSeq(array &$orders): void
    {
        $seqByWave = [];
        foreach ($orders as &$o) {
            $w = $o['_wave'] ?? 'none';
            $seqByWave[$w] = ($seqByWave[$w] ?? 0) + 1;
            $o['actual_seq'] = $seqByWave[$w];
        }
        unset($o);
    }

    private function lastDeliveryTs(array $orders): int
    {
        $m = 0;
        foreach ($orders as $o) { if ($o['_del_ts'] > $m) $m = $o['_del_ts']; }
        return $m;
    }

    private function isCash(?string $method): bool
    {
        return in_array(strtolower((string) $method), ['cash', 'cash_on_delivery', 'cod'], true);
    }

    private function haversine(float $la1, float $lo1, float $la2, float $lo2): float
    {
        $r = 6371000;
        $p1 = deg2rad($la1); $p2 = deg2rad($la2);
        $dp = deg2rad($la2 - $la1); $dl = deg2rad($lo2 - $lo1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return 2 * $r * asin(sqrt($a));
    }
}
