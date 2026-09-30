<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\Riders\RiderDayReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Rider Reports — Phase 2 read API (Jul-2026).
 *
 * Powers the web "Issues" tab (manager daily exceptions) and the per-rider
 * Report Card. Fully REAL-TIME: every open computes the requested day live from
 * the source tables (60s cache) — no nightly job, no stored snapshot, so there
 * is never any staleness or inconsistency. Only recent dates are offered
 * (config live_window_days), which also keeps it inside the ~20-day GPS window.
 *
 * Gated by the `view_rider_reports` permission.
 */
class RiderReportsController extends Controller
{
    const PERMISSION = 'view_rider_reports';
    const LIVE_CACHE_SECS = 60;    // avoid recompute storms on rapid reloads

    /** Web entry — gated by the role permission. */
    public function index(Request $request, RiderDayReportService $svc)
    {
        $user = auth()->user();
        if (!$user || !$user->hasPermission(self::PERMISSION)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        return $this->build($request, $svc);
    }

    /** Shared real-time builder (permission already checked by the caller). */
    private function build(Request $request, RiderDayReportService $svc)
    {
        try {
            $window = (int) config('rider_reports.live_window_days', 7);
            $date = $request->query('date') ?: Carbon::today()->format('Y-m-d');
            $date = Carbon::parse($date)->format('Y-m-d');   // guard bad input

            $daysAgo = Carbon::parse($date)->diffInDays(Carbon::today(), false);
            if ($daysAgo < 0 || $daysAgo > $window) {
                return response()->json([
                    'success' => true, 'date' => $date, 'realtime' => true,
                    'out_of_window' => true, 'window_days' => $window,
                    'config' => $this->cfgPublic(), 'riders' => [],
                ]);
            }

            $riders = Cache::remember(
                "rider_reports_live_{$date}",
                self::LIVE_CACHE_SECS,
                fn () => $this->buildLive($svc, $date)
            );

            $riderIds = $this->riderRoleUserIds();
            foreach ($riders as &$r) {
                $r['is_rider'] = in_array($r['user_id'], $riderIds, true);
            }
            unset($r);

            return response()->json([
                'success'     => true,
                'date'        => $date,
                'realtime'    => true,
                'window_days' => $window,
                'config'      => $this->cfgPublic(),
                'riders'      => array_values($riders),
            ]);
        } catch (\Throwable $e) {
            \Log::error('RiderReports index failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load reports'], 500);
        }
    }

    private function cfgPublic(): array
    {
        return [
            'at_verified_m'        => (int) config('rider_reports.at_verified_m'),
            'late_manager_minutes' => (int) config('rider_reports.late_manager_minutes'),
            'late_card_minutes'    => (int) config('rider_reports.late_card_minutes'),
        ];
    }

    // ---- per-rider Timeline (chronological day) -----------------------

    /** Web entry — one rider's day timeline. Gated by the role permission. */
    public function timeline(Request $request, RiderDayReportService $svc)
    {
        $user = auth()->user();
        if (!$user || !$user->hasPermission(self::PERMISSION)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        return $this->buildTimeline($request, $svc, true);
    }

    /** Mobile entry — gated by the mobile permission. */
    public function apiTimeline(Request $request, RiderDayReportService $svc)
    {
        $user = $request->user();
        if (!$user || !$user->hasMobilePermission(self::PERMISSION)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        // Dispatch rows only when the app asks: an APK that predates them draws an
        // unknown event kind as an empty row.
        return $this->buildTimeline($request, $svc, $request->boolean('with_dispatch'));
    }

    /** Merge a rider's day into one time-sorted event list. */
    private function buildTimeline(Request $request, RiderDayReportService $svc, bool $withDispatch = false)
    {
        try {
            $window = (int) config('rider_reports.live_window_days', 7);
            $date = Carbon::parse($request->query('date') ?: Carbon::today())->format('Y-m-d');
            $rid  = (int) $request->query('rider');
            if (!$rid) {
                return response()->json(['success' => false, 'message' => 'rider required'], 422);
            }
            $daysAgo = Carbon::parse($date)->diffInDays(Carbon::today(), false);
            if ($daysAgo < 0 || $daysAgo > $window) {
                return response()->json(['success' => true, 'out_of_window' => true, 'window_days' => $window, 'events' => []]);
            }

            $name = DB::table('t_sys_user')->where('id', $rid)->value('fullname') ?: 'Rider';
            $tz   = $svc->detectTzOffset();
            $att  = DB::table('t_ops_attendance')->where('user_id', $rid)
                    ->where('attendance_date', $date)->first();

            // For the timeline, span the whole shift (check-in − 15m … check-out + 15m)
            // so the full day shows — not just the delivery window.
            $bounds = null;
            if ($att && $att->login_time) {
                $from = strtotime("$date {$att->login_time}") - 900;
                $to = null;
                if ($att->logout_time) {
                    $to = strtotime("$date {$att->logout_time}");
                    if ($to < strtotime("$date {$att->login_time}")) $to += 86400;  // past midnight
                    $to += 900;
                } else {
                    $to = strtotime("$date {$att->login_time}") + 16 * 3600;         // still on duty → wide
                }
                $bounds = [$from, $to];
            }

            $rep  = $svc->computeForRider($rid, $name, $date, $tz, $bounds)
                    ?? ['orders' => [], 'stops' => [], 'gaps' => []];

            $events = [];

            // check-in
            if ($att && $att->login_time) {
                $events[] = [
                    'kind' => 'checkin', 'time' => substr($att->login_time, 0, 5),
                    'remote' => (int) ($att->is_remote_checkin ?? 0) === 1,
                    'dist_m' => $att->checkin_distance_from_base !== null ? (int) $att->checkin_distance_from_base : null,
                    '_sort' => strtotime("$date {$att->login_time}"),
                ];
            }
            // deliveries (carry the full order → frontend computes the same chips)
            foreach ($rep['orders'] as $o) {
                $events[] = [
                    'kind' => 'delivery', 'time' => date('H:i', strtotime($o['delivered_at'])),
                    'order' => $o, '_sort' => strtotime($o['delivered_at']),
                ];
            }
            // ALL stops (HQ/known/customer + unknown) so the day is complete
            foreach ($rep['stops'] as $s) {
                $events[] = [
                    'kind' => 'stop', 'time' => $s['from'], 'to' => $s['to'], 'min' => $s['min'],
                    'label' => $s['label'] ?? 'unknown', 'context' => $s['context'] ?? 'between',
                    'on_board' => $s['on_board'] ?? 0, 'is_unknown' => (int) ($s['is_unknown'] ?? 0),
                    'map_url' => $s['map_url'] ?? null, '_sort' => $s['_from_ts'] ?? strtotime("$date {$s['from']}"),
                ];
            }
            // dispatch presses + cancels (who, what kind) — the Sep-2026 dispatch story.
            // A cancel the same person immediately re-dispatched is folded into
            // that dispatch row ("cleared and re-dispatched"), not shown twice.
            $dispatchLog = !$withDispatch ? [] : ($rep['dispatch_log']
                ?? \App\Services\Riders\EtaPromiseService::dayDispatchLog($rid, $date, $tz));
            foreach ($dispatchLog as $a) {
                if ($a['type'] === 'cancel' && !empty($a['merged'])) continue;
                // the log's own 'kind' (self/first/retime/reroute) moves to
                // dispatch_kind — 'kind' is the timeline's event type.
                $events[] = array_merge($a, [
                    'dispatch_kind' => $a['kind'] ?? null,
                    'kind' => $a['type'] === 'dispatch' ? 'dispatch' : 'cancel',
                    'time' => date('H:i', strtotime($a['at'])),
                    '_sort' => strtotime($a['at']),
                ]);
            }
            // GPS gaps
            foreach ($rep['gaps'] as $g) {
                $events[] = [
                    'kind' => 'gap', 'time' => $g['from'], 'to' => $g['to'], 'min' => $g['min'],
                    '_sort' => strtotime("$date {$g['from']}"),
                ];
            }
            // check-out (may be past midnight → push a day so it sorts last)
            if ($att && $att->logout_time) {
                $co = strtotime("$date {$att->logout_time}");
                if ($att->login_time && $co < strtotime("$date {$att->login_time}")) $co += 86400;
                $events[] = ['kind' => 'checkout', 'time' => substr($att->logout_time, 0, 5), '_sort' => $co];
            }

            usort($events, fn ($a, $b) => $a['_sort'] <=> $b['_sort']);
            foreach ($events as &$e) { unset($e['_sort']); }
            unset($e);

            return response()->json([
                'success' => true, 'date' => $date, 'realtime' => true,
                'rider_name' => $name, 'config' => $this->cfgPublic(), 'events' => $events,
            ]);
        } catch (\Throwable $e) {
            \Log::error('RiderReports timeline failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load timeline'], 500);
        }
    }

    /**
     * Mobile API — same real-time report, gated by the MOBILE permission
     * `view_rider_reports` (Roles → Mobile Permissions). Sanctum-authenticated.
     */
    public function apiIndex(Request $request, RiderDayReportService $svc)
    {
        $user = $request->user();
        if (!$user || !$user->hasMobilePermission(self::PERMISSION)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        return $this->build($request, $svc);
    }

    /** Live compute via the service. */
    private function buildLive(RiderDayReportService $svc, string $date): array
    {
        $out = [];
        foreach ($svc->computeForDate($date) as $userId => $rep) {
            $out[$userId] = [
                'user_id'     => (int) $userId,
                'rider_name'  => $rep['day']['rider_name'] ?? 'Rider',
                'day'         => $rep['day'],   // already the public day shape from the service
                'orders'      => $rep['orders'],
                // only unknown stops matter to the report (HQ/known/customer stops are "accounted")
                'stops'       => array_values(array_map(fn ($s) => $this->stopPublic($s),
                                    array_filter($rep['stops'], fn ($s) => !empty($s['is_unknown'])))),
                'gaps'        => $rep['gaps'],
                'odd_routes'  => $rep['odd_routes'] ?? [],
                'checkout'    => $rep['checkout'] ?? null,  // Phase H checkout-location audit
                // Re-dispatches the rider made himself after he'd already started
                // delivering — i.e. the delivery times moved under customers who
                // had already been promised them. First-class (not just inside
                // day.flags_json) so clients don't have to parse that blob.
                'mid_run_changes' => $rep['mid_run_changes'] ?? [],
                // The store re-timed / re-routed a route he HAD dispatched (Sep-2026).
                // One rider-level line instead of "dispatch by X" on every order.
                'store_redispatches' => $rep['store_redispatches'] ?? [],
                'missed_dispatch' => null,
            ];
        }

        // Merge "left without dispatch" events from the canonical detector's log
        // (t_ops_dispatch_missed, see DISPATCH-TRACKING-MODEL.md §7). A flagged
        // rider with no deliveries yet still appears, with an empty day.
        foreach ($this->missedDispatchEvents($date) as $rid => $ev) {
            if (isset($out[$rid])) {
                $out[$rid]['missed_dispatch'] = $ev;
            } else {
                $out[$rid] = [
                    'user_id' => $rid, 'rider_name' => $ev['rider_name'] ?: 'Rider',
                    'day' => ['rider_name' => $ev['rider_name'], 'delivered_count' => 0, 'eta_total' => 0,
                              'ontime_count' => 0, 'has_verified_count' => 0, 'at_verified_count' => 0],
                    'orders' => [], 'stops' => [], 'gaps' => [], 'odd_routes' => [],
                    'mid_run_changes' => [],
                    'missed_dispatch' => $ev,
                ];
            }
        }

        // Merge attendance lateness (only when late today AND month total crosses
        // the threshold — quiet until it's a pattern).
        $lateness = $this->attendanceLateness(array_keys($out), $date);
        foreach ($out as $rid => &$r) {
            $r['lateness'] = $lateness[$rid] ?? null;
        }
        unset($r);

        // Merge company-bike meter issues (info-only): overnight grace exceeded and/or no meter
        // reading recorded. Fully guarded — non-bike riders, no meter, or a missing column never
        // surface here.
        foreach ($this->bikeMeterIssues($date) as $rid => $bm) {
            if (isset($out[$rid])) {
                $out[$rid]['bike_meter'] = $bm;
            } else {
                // A flagged rider with no delivery/trail data still deserves the flag.
                $out[$rid] = [
                    'user_id' => $rid, 'rider_name' => $bm['rider_name'] ?: 'Rider',
                    'day' => ['rider_name' => $bm['rider_name'], 'delivered_count' => 0, 'eta_total' => 0,
                              'ontime_count' => 0, 'has_verified_count' => 0, 'at_verified_count' => 0],
                    'orders' => [], 'stops' => [], 'gaps' => [], 'odd_routes' => [],
                    'mid_run_changes' => [],
                    'missed_dispatch' => null, 'lateness' => null, 'bike_meter' => $bm,
                ];
            }
        }
        foreach ($out as $rid => &$r) {
            if (!array_key_exists('bike_meter', $r)) { $r['bike_meter'] = null; }
        }
        unset($r);

        // Merge office-checkout exceptions (R5): a NON-company-bike rider who delivered ≥1 order
        // today but checked out at the OFFICE (he came back) — an exception worth surfacing, since
        // such riders normally check out at their last delivery.
        foreach ($this->officeCheckouts($date) as $rid => $oc) {
            if (isset($out[$rid])) {
                $out[$rid]['office_checkout'] = $oc;
            }
            // (No synthetic row: an office checkout only matters for a rider who actually delivered,
            // so he's always already in $out from computeForDate.)
        }
        foreach ($out as $rid => &$r) {
            if (!array_key_exists('office_checkout', $r)) { $r['office_checkout'] = null; }
        }
        unset($r);

        // Merge company-bike GOING-HOME issues (U4): late / locked / unlocked / completed-late.
        try {
            foreach ((new \App\Services\Riders\HomeJourneyService())->homeIssues($date) as $rid => $hi) {
                if (isset($out[$rid])) {
                    $out[$rid]['home_journey'] = $hi;
                } else {
                    $out[$rid] = [
                        'user_id' => $rid, 'rider_name' => $hi['rider_name'] ?: 'Rider',
                        'day' => ['rider_name' => $hi['rider_name'], 'delivered_count' => 0, 'eta_total' => 0,
                                  'ontime_count' => 0, 'has_verified_count' => 0, 'at_verified_count' => 0],
                        'orders' => [], 'stops' => [], 'gaps' => [], 'odd_routes' => [], 'mid_run_changes' => [],
                        'missed_dispatch' => null, 'lateness' => null, 'bike_meter' => null, 'office_checkout' => null,
                        'home_journey' => $hi,
                    ];
                }
            }
        } catch (\Throwable $e) { \Log::warning('home issues merge failed (non-fatal)', ['error' => $e->getMessage()]); }
        foreach ($out as $rid => &$r) {
            if (!array_key_exists('home_journey', $r)) { $r['home_journey'] = null; }
        }
        unset($r);

        return $out;
    }

    /**
     * Office-checkout exceptions on $date (R5). For NON-company-bike riders who delivered at least
     * one order today: if their checkout GPS classifies as "at office" (via CheckoutClassifierService,
     * which now checks delivery-first then a tight OFFICE_AT_RADIUS_M office), flag it with details —
     * checkout time, the last delivery (time + customer), and minutes between the two ("came back to
     * the office N min after his last delivery"). Fully guarded; company-bike riders and
     * zero-delivery days never surface. Returns [userId => {rider_name, checkout_time,
     * last_delivery_time, last_customer, last_order, minutes_after}].
     */
    private function officeCheckouts(string $date): array
    {
        $out = [];
        try {
            // Riders who checked out today WITH a checkout GPS fix.
            $atts = DB::table('t_ops_attendance')
                ->where('attendance_date', $date)
                ->whereNotNull('logout_time')->where('logout_time', '!=', '')
                ->whereNotNull('checkout_latitude')->whereNotNull('checkout_longitude')
                ->get(['user_id', 'logout_time', 'checkout_latitude', 'checkout_longitude']);
            if ($atts->isEmpty()) {
                return $out;
            }
            $ids = $atts->pluck('user_id')->all();

            // Company-bike riders are EXCLUDED (their office checkout is normal — they go home after).
            $bikeIds = [];
            if (\Illuminate\Support\Facades\Schema::hasColumn('t_ops_rider_profile', 'company_bike')) {
                // ⭐ Phase C: whoever was on a company machine on THIS date.
                $companySet = array_flip(
                    (new \App\Services\Riders\VehicleResolver())->companyRiderIdsFor($date)
                );
                $bikeIds = array_values(array_filter($ids, fn ($u) => isset($companySet[(int) $u])));
            }
            $bikeIds = array_flip($bikeIds);

            // Each rider's deliveries today: count + the LAST one (time + customer + order).
            $deliveries = DB::table('t_crm_order_status_history as h')
                ->join('t_crm_prod_order as o', 'o.id', '=', 'h.order_id')
                ->where('h.status_code', 'delivered')
                ->whereIn('o.assigned_rider_user_id', $ids)
                ->whereDate('h.changed_at', $date)
                ->orderBy('h.changed_at')
                ->get(['o.assigned_rider_user_id as uid', 'h.changed_at', 'o.order_number', 'o.name as customer']);
            $delByUser = [];
            foreach ($deliveries as $d) {
                $delByUser[$d->uid]['count'] = ($delByUser[$d->uid]['count'] ?? 0) + 1;
                $delByUser[$d->uid]['last'] = $d; // ordered asc → last assignment wins = latest
            }

            $names = DB::table('t_sys_user')->whereIn('id', $ids)->pluck('fullname', 'id');
            $classifier = new \App\Services\Riders\CheckoutClassifierService();

            foreach ($atts as $att) {
                $uid = $att->user_id;
                if (isset($bikeIds[$uid])) { continue; }             // company-bike → skip
                if (empty($delByUser[$uid]['count'])) { continue; }  // no delivery today → office is normal

                $info = $classifier->classify((int) $uid, $date, $att->checkout_latitude, $att->checkout_longitude);
                if (!$info || ($info['status'] ?? null) !== 'office') { continue; }

                $last = $delByUser[$uid]['last'];
                $coTime = strtotime($date . ' ' . $att->logout_time);
                $lastTime = strtotime((string) $last->changed_at);
                $minsAfter = ($coTime && $lastTime && $coTime >= $lastTime) ? (int) round(($coTime - $lastTime) / 60) : null;

                $out[$uid] = [
                    'rider_name'         => $names[$uid] ?? 'Rider',
                    'checkout_time'      => substr((string) $att->logout_time, 0, 5),
                    'last_delivery_time' => date('H:i', $lastTime ?: time()),
                    'last_customer'      => trim((string) $last->customer) ?: 'a customer',
                    'last_order'         => $last->order_number,
                    'minutes_after'      => $minsAfter,
                ];
            }
        } catch (\Throwable $e) {
            \Log::warning('officeCheckouts failed (non-fatal)', ['date' => $date, 'error' => $e->getMessage()]);
            return [];
        }
        return $out;
    }

    /**
     * Company-bike meter issues on $date. Per rider, either or both of:
     *   - grace   : start meter is more than (per-rider or default) grace km above the previous
     *               day's end meter (used the bike off-hours).
     *   - no_meter: checked in without recording the start meter, or checked out without an end
     *               meter — so their bike km can't be tracked. (missing = start|end|both)
     * Returns [userId => ['rider_name'=>, 'grace'=>?, 'no_meter'=>?]]. Fully guarded so a missing
     * column, a non-bike rider, or a rider who didn't come simply produces nothing (never errors).
     */
    private function bikeMeterIssues(string $date): array
    {
        $out = [];
        try {
            if (!\Illuminate\Support\Facades\Schema::hasColumn('t_ops_rider_profile', 'company_bike')) {
                return $out; // feature column not deployed yet
            }
            $hasGrace = \Illuminate\Support\Facades\Schema::hasColumn('t_ops_rider_profile', 'overnight_grace_km');

            // Company-bike riders + their per-rider grace override (if any).
            $cols = $hasGrace ? ['user_id', 'overnight_grace_km'] : ['user_id'];
            // ⭐ Phase C: the company-machine cohort for THIS date.
            $companyIds = (new \App\Services\Riders\VehicleResolver())->companyRiderIdsFor($date);
            $bikeRiders = empty($companyIds)
                ? collect()
                : DB::table('t_ops_rider_profile')->whereIn('user_id', $companyIds)->get($cols);
            if ($bikeRiders->isEmpty()) {
                return $out;
            }
            $ids = $bikeRiders->pluck('user_id')->all();
            $graceByUser = [];
            foreach ($bikeRiders as $b) {
                if ($hasGrace && $b->overnight_grace_km !== null && $b->overnight_grace_km !== '') {
                    $graceByUser[$b->user_id] = (float) $b->overnight_grace_km;
                }
            }
            $defaultGrace = (float) $this->attConfig('ATTENDANCE_OVERNIGHT_GRACE_KM', 30);

            // Today's attendance for those riders (only riders who actually came in today).
            $today = DB::table('t_ops_attendance')
                ->whereIn('user_id', $ids)->where('attendance_date', $date)
                ->get(['user_id', 'login_time', 'logout_time', 'meter_start', 'meter_end'])
                ->keyBy('user_id');
            if ($today->isEmpty()) {
                return $out;
            }

            // Previous end meter per rider (most recent attendance_date < $date with a meter_end).
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $prev = DB::select("
                SELECT a.user_id, a.meter_end, a.attendance_date
                FROM t_ops_attendance a
                INNER JOIN (
                    SELECT user_id, MAX(attendance_date) AS md FROM t_ops_attendance
                    WHERE user_id IN ($ph) AND attendance_date < ? AND meter_end IS NOT NULL AND meter_end != ''
                    GROUP BY user_id
                ) l ON a.user_id = l.user_id AND a.attendance_date = l.md
            ", array_merge($ids, [$date]));
            $prevEnd = [];
            foreach ($prev as $p) { $prevEnd[$p->user_id] = ['end' => $p->meter_end, 'date' => $p->attendance_date]; }

            $names = DB::table('t_sys_user')->whereIn('id', $ids)->pluck('fullname', 'id');
            $has = fn ($v) => $v !== null && $v !== '';
            $wjBase = new \App\Services\Riders\WorkJourneyService();

            foreach ($today as $uid => $att) {
                $checkedIn  = $has($att->login_time);
                $checkedOut = $has($att->logout_time);
                $hasStart   = $has($att->meter_start);
                $hasEnd     = $has($att->meter_end);
                if (!$checkedIn && !$checkedOut) { continue; } // never actually worked

                // No-meter: checked in without a start, or checked out without an end.
                $missStart = $checkedIn && !$hasStart;
                $missEnd   = $checkedOut && !$hasEnd;
                $noMeter = null;
                if ($missStart || $missEnd) {
                    $noMeter = ['missing' => $missStart && $missEnd ? 'both' : ($missStart ? 'start' : 'end')];
                }

                // Grace: needs a start meter today + a prior reading to compare against.
                // ⭐⭐ The baseline is THE MACHINE's last reading (`closingBaseline()` — the same
                //    brain the attendance sheet and the red meter-gap verdict use). Keyed to the
                //    rider, this accused a man who simply changed machines of the difference
                //    between two odometers. Falls back to the batch rider lookup if the registry
                //    is off or cannot answer, so a report is never emptied by this.
                $grace = null;
                $baseVal = isset($prevEnd[$uid]) ? (float) $prevEnd[$uid]['end'] : null;
                $baseDate = $prevEnd[$uid]['date'] ?? null;
                $baseLabel = null;
                $baseHandover = false;
                if ($hasStart) {
                    try {
                        $mb = $wjBase->closingBaseline((int) $uid, $date);
                        $baseHandover = (bool) $mb['transfer_day'];
                        if ($mb['holds_nothing']) {
                            $baseVal = null; $baseDate = null;
                        } elseif ($mb['value'] !== null) {
                            $baseVal = (float) $mb['value'];
                            $baseDate = $mb['date'];
                            $baseLabel = $mb['label'];
                        }
                    } catch (\Throwable $e) { /* keep the batch answer */ }
                }
                // A handover day is not judged: the baseline may belong to the other rider, so
                // a km allowance is the wrong shape (see WorkJourneyService::continuity).
                if ($hasStart && $baseVal !== null && !$baseHandover) {
                    $start = (float) $att->meter_start;
                    $g     = $graceByUser[$uid] ?? $defaultGrace;
                    $over  = $start - $baseVal;
                    if ($over > $g) {
                        $grace = [
                            'overnight_km'   => (int) round($over),
                            'grace_km'       => (int) round($g),
                            'meter_start'    => (int) round($start),
                            'prev_meter_end' => (int) round($baseVal),
                            'prev_date'      => $baseDate,
                            'prev_label'     => $baseLabel,
                        ];
                    }
                }

                if ($grace || $noMeter) {
                    $out[$uid] = ['rider_name' => $names[$uid] ?? 'Rider', 'grace' => $grace, 'no_meter' => $noMeter];
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('bikeMeterIssues failed (non-fatal)', ['date' => $date, 'error' => $e->getMessage()]);
            return [];
        }
        return $out;
    }

    /** t_fin_config read with a default — never throws (a config hiccup must not break reports). */
    private function attConfig(string $key, $default)
    {
        try {
            $v = DB::table('t_fin_config')->where('config_key', $key)->value('config_value');
            return ($v === null || $v === '') ? $default : $v;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * Month-to-date attendance lateness per rider. Uses the SAME snapshot-preferred
     * helper (ShiftResolutionService::sumLateOvertimeMinutes) as the salary
     * calculation and the monthly attendance report, so the numbers match the salary
     * slips even when a rider's shift changed mid-month (each day resolves against
     * its own frozen snapshot / per-date shift, never a single as-of-today shift).
     * Returns only riders who are late TODAY and whose month total ≥ the threshold.
     */
    private function attendanceLateness(array $userIds, string $date): array
    {
        $out = [];
        if (empty($userIds)) return $out;
        try {
            $threshold = (int) config('rider_reports.late_month_threshold_min', 200);
            $monthStart = date('Y-m-01', strtotime($date));
            $svc = new \App\Services\ShiftResolutionService();

            foreach ($userIds as $rid) {
                // Snapshot-preferred, per-date resolution — identical basis to the
                // salary slip and the attendance report.
                $todayOt = $svc->sumLateOvertimeMinutes($rid, $date, $date);
                $monthOt = $svc->sumLateOvertimeMinutes($rid, $monthStart, $date);
                $today = (int) $todayOt['late_minutes'];
                $month = (int) $monthOt['late_minutes'];
                // ⭐ Sep-2026 — both figures are NET of minutes a manager forgave, and that is
                // deliberate: a waived lateness has been accepted as genuine, so it should stop
                // raising the issue. The waived total travels with it so the card can say why
                // a rider dropped off the list rather than just going quiet.
                if ($today > 0 && $month >= $threshold) {
                    $out[$rid] = [
                        'today_min' => $today,
                        'month_min' => $month,
                        'month_waived_min' => (int) ($monthOt['late_waived_minutes'] ?? 0),
                    ];
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('RiderReports attendanceLateness failed (non-fatal)', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    /**
     * "Left without dispatch" events for a date, from the event log written by
     * the canonical detectLeftWithoutDispatch() rule. Resolution is DERIVED per
     * the model: each undispatched order id later carries eta_calculated_at/by
     * once someone presses dispatch. Fail-safe: table absent / bad data → [].
     */
    private function missedDispatchEvents(string $date): array
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('t_ops_dispatch_missed')) {
                return [];
            }
            $rows = DB::table('t_ops_dispatch_missed as m')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'm.rider_id')
                ->where('m.issue_date', $date)
                ->select('m.*', 'u.fullname')
                ->get();

            // C8: once per call, not per row (information_schema read).
            $etaLogOk = \Illuminate\Support\Facades\Schema::hasTable('t_ops_eta_log');
            $dayFrom = date('Y-m-d', strtotime($date)) . ' 00:00:00';
            $dayTo   = date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00';

            $out = [];
            foreach ($rows as $r) {
                $ids = json_decode($r->undispatched_order_ids ?: '[]', true) ?: [];
                $resolution = ['state' => 'unknown'];
                // ⭐ Sep-2026: the FIRST press after he left, from the dispatch log. The
                //    order rows only hold the LATEST dispatch — after a cancel and
                //    re-dispatch they read "dispatched at 12:00" for a route first
                //    pressed at 11:53 (device walk, 29-Sep). Falls back to the order
                //    rows below for days before the log existed.
                $firstPress = null;
                if (!empty($ids) && $etaLogOk) {
                    $firstPress = DB::table('t_ops_eta_log as l')
                        ->leftJoin('t_sys_user as du', 'du.id', '=', 'l.calculated_by')
                        ->whereIn('l.order_id', $ids)
                        ->where('l.event', 'dispatch')
                        ->where('l.created_at', '>=', $r->left_at ?: $r->created_at)
                        ->where('l.created_at', '>=', $dayFrom)   // C8: range, same rows
                        ->where('l.created_at', '<', $dayTo)
                        ->orderBy('l.created_at')->orderBy('l.id')
                        ->first(['l.created_at', 'l.calculated_by', 'du.fullname as by_name']);
                }
                if ($firstPress) {
                    $resolution = [
                        'state'   => 'dispatched',
                        'at'      => date('H:i', strtotime($firstPress->created_at)),
                        'by_name' => $firstPress->by_name,
                        'by_self' => (int) $firstPress->calculated_by === (int) $r->rider_id,
                    ];
                } elseif (!empty($ids)) {
                    $orders = DB::table('t_crm_prod_order as o')
                        ->leftJoin('t_sys_user as du', 'du.id', '=', 'o.eta_calculated_by')
                        ->whereIn('o.id', $ids)
                        ->select('o.id', 'o.eta_calculated_at', 'o.eta_calculated_by', 'du.fullname as by_name')
                        ->get();
                    $dispatched = $orders->filter(fn ($o) => $o->eta_calculated_at !== null)
                                         ->sortBy('eta_calculated_at');
                    if ($dispatched->isEmpty()) {
                        $resolution = ['state' => 'still_undispatched'];
                    } else {
                        $first = $dispatched->first();
                        $resolution = [
                            'state'   => 'dispatched',
                            'at'      => date('H:i', strtotime($first->eta_calculated_at)),
                            'by_name' => $first->by_name,
                            'by_self' => (int) $first->eta_calculated_by === (int) $r->rider_id,
                        ];
                    }
                }
                $out[(int) $r->rider_id] = [
                    'left_at'    => $r->left_at ? date('H:i', strtotime($r->left_at)) : null,
                    'context'    => $r->context,                     // first_run | returned_then_left
                    'count'      => (int) $r->undispatched_count,
                    'resolution' => $resolution,
                    'rider_name' => $r->fullname,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            \Log::warning('RiderReports missedDispatchEvents failed (non-fatal)', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function stopPublic(array $s): array
    {
        return [
            'from' => $s['from'], 'to' => $s['to'], 'min' => $s['min'],
            'lat' => $s['lat'], 'lng' => $s['lng'], 'label' => $s['label'] ?? 'unknown',
            'on_board' => $s['on_board'] ?? 0, 'context' => $s['context'] ?? 'between',
            'area' => $s['area'] ?? null, 'map_url' => $s['map_url'] ?? null,
        ];
    }

    /** user_ids that hold at least one role of type 'rider'. */
    private function riderRoleUserIds(): array
    {
        return DB::table('t_sys_user_role as ur')
            ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
            ->where('r.type', 'rider')->where('r.is_active', '1')
            ->pluck('ur.user_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }
}
