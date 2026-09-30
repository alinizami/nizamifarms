<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The delivery-time PROMISE — the yardstick lateness is measured against.
 *
 * The problem this solves: `RiderController::calculateDeliveryEtas` overwrites
 * `t_crm_prod_order.estimated_delivery_at` on every dispatch, and every lateness
 * surface compares `delivered_at` against that CURRENT value. So a rider running
 * late could press Re-dispatch, get fresh (later) times, and never be flagged —
 * the original commitment survived nowhere queryable.
 *
 * The rule (owner, Jul-2026): **the yardstick only resets when management
 * resets it.**
 *  - The FIRST dispatch of the day is the promise.
 *  - A STORE-initiated dispatch — or a rider dispatch that FOLLOWS a
 *    store-initiated cancel — sets a NEW promise: management sanctioned the
 *    re-plan (they inserted an urgent order, or re-routed him), so holding him
 *    to the old times would be unfair.
 *  - A RIDER-initiated re-dispatch still refreshes the live customer-facing
 *    ETAs (we WANT honest times going out) but does NOT move the promise.
 * A rider cancelling his OWN dispatch is therefore not a reset either — that
 * loophole is why `event='cancel'` rows are logged with `is_rider_self`.
 *
 * Everything here is FAIL-SAFE: with no table (or no rows for an order) the
 * writers no-op and `promisesFor()` returns nothing, so callers fall back to
 * today's behaviour. That is what lets the PHP deploy before the SQL runs, and
 * what makes pre-log historical orders keep rendering sensibly forever.
 */
class EtaPromiseService
{
    private const TABLE = 't_ops_eta_log';

    /** Cached per request — Schema::hasTable hits the information_schema. */
    private static ?bool $tableOk = null;
    /** Memo for the B4 evidence table (one information_schema hit per process, not per rider). */
    private static ?bool $missedTableOk = null;

    private static function available(): bool
    {
        if (self::$tableOk === null) {
            try {
                self::$tableOk = Schema::hasTable(self::TABLE);
            } catch (\Throwable $e) {
                self::$tableOk = false;
            }
        }
        return self::$tableOk;
    }

    /** Sep-2026 origin columns (eta_log_origin_sep2026.sql) — cached per request. */
    private static ?bool $originCols = null;

    public static function originColumnsAvailable(): bool
    {
        if (self::$originCols === null) {
            try {
                self::$originCols = self::available() && Schema::hasColumn(self::TABLE, 'rider_distance_m');
            } catch (\Throwable $e) {
                self::$originCols = false;
            }
        }
        return self::$originCols;
    }

    /** Only the origin keys the table can hold (nothing before the SQL runs). */
    private static function originFields(array $origin): array
    {
        if (!self::originColumnsAvailable()) {
            return [];
        }
        return [
            'origin_source'    => isset($origin['origin_source']) ? substr((string) $origin['origin_source'], 0, 40) : null,
            'rider_distance_m' => isset($origin['rider_distance_m']) ? (int) $origin['rider_distance_m'] : null,
            'rider_gps_age_s'  => isset($origin['rider_gps_age_s']) ? (int) $origin['rider_gps_age_s'] : null,
        ];
    }

    // ---- writers ---------------------------------------------------------

    /**
     * Record one dispatch wave. Called from inside calculateDeliveryEtas after
     * the ETAs are written. Never throws — a logging failure must never cost a
     * rider his dispatch.
     *
     * @param array $stops list of ['order_id' => int, 'estimated_delivery_at' => string, 'position' => int]
     */
    public static function logDispatch(
        int $riderId,
        string $batchTs,
        array $stops,
        ?int $byUserId,
        bool $isMidRun,
        int $deliveredBefore,
        string $scope,
        array $origin = []
    ): void {
        if (!self::available() || empty($stops)) {
            return;
        }
        try {
            $now = now()->format('Y-m-d H:i:s');
            $extra = self::originFields($origin);
            $rows = [];
            foreach ($stops as $s) {
                $rows[] = $extra + [
                    'order_id'              => (int) $s['order_id'],
                    'rider_id'              => $riderId,
                    'event'                 => 'dispatch',
                    'batch_ts'              => $batchTs,
                    'estimated_delivery_at' => $s['estimated_delivery_at'],
                    'position'              => (int) $s['position'],
                    'calculated_by'         => $byUserId,
                    'is_rider_self'         => ($byUserId !== null && (int) $byUserId === $riderId) ? 1 : 0,
                    'is_mid_run'            => $isMidRun ? 1 : 0,
                    'delivered_before'      => $deliveredBefore,
                    'scope'                 => $scope,
                    'created_at'            => $now,
                ];
            }
            DB::table(self::TABLE)->insert($rows);
        } catch (\Throwable $e) {
            Log::warning('EtaPromiseService: dispatch log failed (non-fatal)', [
                'rider_id' => $riderId, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record a cancel-dispatch. Only a STORE-initiated cancel opens the door to
     * a new promise, but we log both so the rider-cancel case is provably not a
     * reset rather than merely unrecorded.
     */
    public static function logCancel(int $riderId, array $orderIds, ?int $byUserId, array $origin = []): void
    {
        if (!self::available() || empty($orderIds)) {
            return;
        }
        try {
            $now = now()->format('Y-m-d H:i:s');
            $isSelf = ($byUserId !== null && (int) $byUserId === $riderId) ? 1 : 0;
            // Where the rider WAS when his times were cleared — a store cancel while
            // he is out on the road is the store's re-plan, not his missed dispatch.
            $extra = self::originFields($origin);
            $rows = [];
            foreach ($orderIds as $oid) {
                $rows[] = $extra + [
                    'order_id'              => (int) $oid,
                    'rider_id'              => $riderId,
                    'event'                 => 'cancel',
                    'batch_ts'              => null,
                    'estimated_delivery_at' => null,
                    'position'              => null,
                    'calculated_by'         => $byUserId,
                    'is_rider_self'         => $isSelf,
                    'is_mid_run'            => 0,
                    'delivered_before'      => 0,
                    'scope'                 => null,
                    'created_at'            => $now,
                ];
            }
            DB::table(self::TABLE)->insert($rows);
        } catch (\Throwable $e) {
            Log::warning('EtaPromiseService: cancel log failed (non-fatal)', [
                'rider_id' => $riderId, 'error' => $e->getMessage(),
            ]);
        }
    }

    // ---- reader ----------------------------------------------------------

    /**
     * Promise per order id. Orders with no log rows are simply absent from the
     * result — callers must fall back to the order's current ETA.
     *
     * @return array<int, array> order_id => [
     *     promised_at, promise_batch_ts, promise_by, promise_is_store,
     *     final_at, retimed, retimed_by, retimed_by_rider, retimed_mid_run
     *   ]
     */
    public static function promisesFor(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if (!self::available() || empty($orderIds)) {
            return [];
        }
        try {
            $rows = DB::table(self::TABLE)
                ->whereIn('order_id', $orderIds)
                ->orderBy('order_id')
                ->orderBy('created_at')
                ->orderBy('id')          // stable within the same second
                ->get([
                    'order_id', 'event', 'batch_ts', 'estimated_delivery_at',
                    'calculated_by', 'is_rider_self', 'is_mid_run', 'created_at',
                ]);

            // ⭐ Owner ruling (28-Sep-2026): when Taimur or Farooq re-time a route,
            //    lateness everywhere is judged against THEIR new times. Every store
            //    dispatch or cancel is a reset point — no exception for a same-route
            //    re-time. The rider's own earlier times are only carried alongside
            //    (pre_store_promised_at) so a reader can still see them.
            $byOrder = [];
            foreach ($rows as $r) {
                $byOrder[(int) $r->order_id][] = $r;
            }

            $out = [];
            foreach ($byOrder as $oid => $events) {
                $p = self::derive($events);
                if ($p !== null) {
                    $out[$oid] = $p;
                }
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('EtaPromiseService: promise read failed (non-fatal)', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /** "Out on the road" = his own GPS was farther than this from the office. */
    public const OUT_OF_OFFICE_M = 300;

    /**
     * Do this rider's CURRENT out-for-delivery orders carry a dispatch press from
     * today in the log — even though the order rows now hold no ETA (a store
     * cancel wiped them)? (Sep-2026)
     *
     * The third mid-run signal in RiderController::riderIsMidRun: a route that WAS
     * dispatched, and a rider now away from the office, is a rider on the road —
     * whatever a cancel did to the order rows since. Deliberately tied to the
     * orders he holds now, so a rider who delivered earlier and is at his pickup
     * point with NEVER-dispatched orders is not caught (that stays a first dispatch,
     * with all its phantom-GPS anchoring).
     */
    public static function currentOrdersWereDispatchedToday(int $riderId): bool
    {
        if (!self::available()) {
            return false;
        }
        try {
            return DB::table(self::TABLE . ' as l')
                ->join('t_crm_prod_order as o', 'o.id', '=', 'l.order_id')
                ->where('l.rider_id', $riderId)
                ->where('l.event', 'dispatch')
                // C8: a range, not whereDate() — lets idx_eta_log_rider_day seek (same rows)
                ->where('l.created_at', '>=', now()->format('Y-m-d') . ' 00:00:00')
                ->where('l.created_at', '<', now()->addDay()->format('Y-m-d') . ' 00:00:00')
                ->where('o.assigned_rider_user_id', $riderId)
                ->where('o.order_status', 'out_for_delivery')
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Riders whose CURRENT untimed out-for-delivery orders were cleared by the
     * STORE while the rider was out on the road (Sep-2026). Those orders have no
     * times because the store is re-planning — not because he forgot to press
     * dispatch — so "left without dispatch" must not be pinned on him.
     *
     * A store cancel made while he was AT the office (the "back to add orders"
     * case) does not count: leaving after that without dispatching is still his.
     * Before the origin SQL there is no position on the cancel row, so a store
     * cancel from the last 15 minutes stands in for it.
     *
     * @return array<int,string> rider_id => created_at of that cancel (the caller
     *         also checks he has not been back to the office since — if he has,
     *         a new departure without dispatch is his own again)
     */
    public static function storeClearedWhileAway(array $riderIds): array
    {
        $riderIds = array_values(array_filter(array_map('intval', $riderIds)));
        if (!self::available() || !$riderIds) {
            return [];
        }
        try {
            $q = DB::table(self::TABLE . ' as l')
                ->join('t_crm_prod_order as o', 'o.id', '=', 'l.order_id')
                ->whereIn('l.rider_id', $riderIds)
                ->where('l.event', 'cancel')
                ->where('l.is_rider_self', 0)
                // C8: a range, not whereDate() — lets idx_eta_log_rider_day seek (same rows)
                ->where('l.created_at', '>=', now()->format('Y-m-d') . ' 00:00:00')
                ->where('l.created_at', '<', now()->addDay()->format('Y-m-d') . ' 00:00:00')
                ->where('o.order_status', 'out_for_delivery')
                ->whereNull('o.eta_calculated_at')
                ->whereColumn('o.assigned_rider_user_id', 'l.rider_id')
                // the cancel is still the order's LATEST log event
                ->whereRaw('NOT EXISTS (SELECT 1 FROM ' . self::TABLE . ' x WHERE x.order_id = l.order_id AND x.id > l.id)');
            if (self::originColumnsAvailable()) {
                $q->where(function ($w) {
                    $w->where('l.rider_distance_m', '>', self::OUT_OF_OFFICE_M)
                      ->orWhere(function ($w2) {
                          $w2->whereNull('l.rider_distance_m')
                             ->where('l.created_at', '>=', now()->subMinutes(15)->format('Y-m-d H:i:s'));
                      });
                });
            } else {
                $q->where('l.created_at', '>=', now()->subMinutes(15)->format('Y-m-d H:i:s'));
            }
            $out = [];
            foreach ($q->groupBy('l.rider_id')->selectRaw('l.rider_id, MAX(l.created_at) AS at')->get() as $r) {
                $out[(int) $r->rider_id] = (string) $r->at;
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('EtaPromiseService: storeClearedWhileAway failed (non-fatal)', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /** A store event resets the yardstick; a rider's own never does. */
    private static function isReset($e): bool
    {
        return !$e->is_rider_self;
    }

    /**
     * Apply the rule to one order's chronological event list.
     *
     * Scoped to the LAST calendar day that carries a dispatch: an order
     * dispatched yesterday, left undelivered and re-dispatched today must be
     * judged against today's commitment, not yesterday's.
     */
    private static function derive(array $events): ?array
    {
        // Keep only the events of the most recent day that actually dispatched.
        $lastDispatchDay = null;
        foreach ($events as $e) {
            if ($e->event === 'dispatch') {
                $lastDispatchDay = substr((string) $e->created_at, 0, 10);
            }
        }
        if ($lastDispatchDay === null) {
            return null; // cancels only — nothing was ever promised
        }
        $events = array_values(array_filter(
            $events,
            fn ($e) => substr((string) $e->created_at, 0, 10) === $lastDispatchDay
        ));

        // The last STORE-initiated event (dispatch OR cancel) is the sanctioned
        // reset point; everything before it is superseded by management.
        $resetIdx = 0;
        $storeReset = false;
        foreach ($events as $i => $e) {
            if (self::isReset($e)) {
                $resetIdx = $i;
                $storeReset = true;
            }
        }

        // What he was held to BEFORE the store stepped in (Sep-2026). The store's
        // reset stays the yardstick; this only lets a reader also see "against his
        // own times he was N min late", so a store re-time is never mistaken for
        // the rider being on time — nor for the rider failing to press dispatch.
        // A store cancel + re-dispatch is ONE store action, so step back over the
        // whole trailing run of store events before looking at what came earlier.
        $preStore = null;
        if ($storeReset) {
            $firstStore = $resetIdx;
            while ($firstStore > 0 && self::isReset($events[$firstStore - 1])) {
                $firstStore--;
            }
            if ($firstStore > 0) {
                $before = self::derive(array_slice($events, 0, $firstStore));
                $preStore = $before['promised_at'] ?? null;
            }
        }

        // The promise = the first dispatch AT or AFTER that reset point.
        $promise = null;
        for ($i = $resetIdx; $i < count($events); $i++) {
            if ($events[$i]->event === 'dispatch') {
                $promise = $events[$i];
                break;
            }
        }
        // Reset was a store cancel never followed by a dispatch: the times were
        // deliberately withdrawn, so there is no promise to hold him to.
        if ($promise === null) {
            return null;
        }

        // The last dispatch = what the customer/app currently sees.
        $final = null;
        foreach ($events as $e) {
            if ($e->event === 'dispatch') {
                $final = $e;
            }
        }

        $retimed = $final && $final->estimated_delivery_at !== $promise->estimated_delivery_at;

        return [
            'promised_at'       => $promise->estimated_delivery_at,
            'promise_batch_ts'  => $promise->batch_ts,
            'promise_by'        => $promise->calculated_by !== null ? (int) $promise->calculated_by : null,
            'promise_is_store'  => !$promise->is_rider_self,
            'final_at'          => $final ? $final->estimated_delivery_at : null,
            'retimed'           => (bool) $retimed,
            'retimed_by'        => $retimed && $final->calculated_by !== null ? (int) $final->calculated_by : null,
            'retimed_by_rider'  => $retimed ? (bool) $final->is_rider_self : false,
            'retimed_mid_run'   => $retimed ? (bool) $final->is_mid_run : false,
            'pre_store_promised_at' => $preStore,
        ];
    }

    /**
     * The day's dispatch story for one rider — every dispatch press and every
     * cancel, in order, with each STORE dispatch classified (Sep-2026):
     *
     *   kind = 'self'    the rider pressed it himself
     *          'first'   the store timed orders the rider had never dispatched
     *                    ("dispatch by X" in the old sense — he didn't press). Also a
     *                    store wave after his own press when a missed-dispatch row
     *                    shows he had already left with those orders (Sep-29, D4)
     *          'next'    the store sent a further wave of never-dispatched orders
     *                    after the rider had pressed his own wave(s)
     *          'retime' the store re-dispatched a route the rider HAD already
     *                    dispatched: same stops, same order — only the times moved
     *          'reroute' the store re-dispatched and the route itself changed:
     *                    stops added, removed (not delivered), re-ordered, or
     *                    waves merged
     *
     * A store cancel that the SAME user follows with a dispatch of those orders
     * within 10 minutes is marked `merged` — it is one action ("cleared and
     * re-dispatched"), not two, and screens show it once.
     *
     * Read-only, fail-safe (empty on any error / before the log existed).
     *
     * @param int $tzOffsetMin the report's TIMESTAMP skew (0 on prod) — only the
     *                         delivered stamps need it; the log is literal PKT.
     */
    public static function dayDispatchLog(int $riderId, string $date, int $tzOffsetMin = 0): array
    {
        if (!self::available()) {
            return [];
        }
        try {
            $rows = DB::table(self::TABLE)
                ->where('rider_id', $riderId)
                // C8: a range, not whereDate() — same rows, index-friendly
                ->where('created_at', '>=', date('Y-m-d', strtotime($date)) . ' 00:00:00')
                ->where('created_at', '<', date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(array_merge(
                    ['order_id', 'event', 'batch_ts', 'estimated_delivery_at', 'position',
                     'calculated_by', 'is_rider_self', 'is_mid_run', 'delivered_before', 'created_at'],
                    self::originColumnsAvailable() ? ['origin_source', 'rider_distance_m', 'rider_gps_age_s'] : []
                ));
            if ($rows->isEmpty()) {
                return [];
            }

            // Group rows into actions: one per dispatch batch, one per cancel moment.
            $actions = [];
            foreach ($rows as $r) {
                $key = $r->event === 'dispatch'
                    ? 'd|' . $r->batch_ts
                    : 'c|' . $r->created_at . '|' . (int) $r->calculated_by;
                if (!isset($actions[$key])) {
                    $actions[$key] = [
                        'type'      => $r->event === 'dispatch' ? 'dispatch' : 'cancel',
                        'at'        => (string) $r->created_at,
                        'batch_ts'  => $r->event === 'dispatch' ? (string) $r->batch_ts : null,
                        'by'        => $r->calculated_by !== null ? (int) $r->calculated_by : null,
                        'by_self'   => (bool) $r->is_rider_self,
                        'is_mid_run'       => (bool) $r->is_mid_run,
                        'delivered_before' => (int) $r->delivered_before,
                        // Where he was / what the times were measured from (NULL
                        // before eta_log_origin_sep2026.sql).
                        'origin_source'    => $r->origin_source ?? null,
                        'rider_distance_m' => isset($r->rider_distance_m) ? (int) $r->rider_distance_m : null,
                        'rider_gps_age_s'  => isset($r->rider_gps_age_s) ? (int) $r->rider_gps_age_s : null,
                        'stops'     => [],   // order_id => ['eta' => , 'pos' => ]
                    ];
                }
                $actions[$key]['stops'][(int) $r->order_id] = [
                    'eta' => $r->estimated_delivery_at,
                    'pos' => $r->position !== null ? (int) $r->position : null,
                ];
            }
            $actions = array_values($actions);

            // When each order touched today was delivered (to tell "removed from
            // the route" apart from "already delivered").
            $allIds = [];
            foreach ($actions as $a) {
                $allIds += array_fill_keys(array_keys($a['stops']), true);
            }
            $deliveredTs = [];
            $secs = $tzOffsetMin * 60;
            foreach (DB::table('t_crm_order_status_history')
                         ->whereIn('order_id', array_keys($allIds))
                         ->where('status_code', 'delivered')
                         ->groupBy('order_id')
                         ->selectRaw('order_id, MIN(changed_at) AS at')
                         ->get() as $d) {
                $deliveredTs[(int) $d->order_id] = strtotime($d->at) - $secs;
            }

            $userIds = array_filter(array_unique(array_column($actions, 'by')));
            $names = $userIds
                ? DB::table('t_sys_user')->whereIn('id', $userIds)->pluck('fullname', 'id')->toArray()
                : [];

            // B4 (owner D4, Sep-29): the day's "left without dispatch" rows — the EVIDENCE
            // that a store press of never-dispatched orders after his own wave was really
            // him riding off with them unpressed. left_at is DATETIME, literal PKT, the same
            // clock as this log's created_at, so the two compare as-is. Fail-safe: [].
            $missed = [];
            try {
                if (self::$missedTableOk ??= Schema::hasTable('t_ops_dispatch_missed')) {
                    foreach (DB::table('t_ops_dispatch_missed')
                                 ->where('rider_id', $riderId)
                                 ->where('issue_date', date('Y-m-d', strtotime($date)))
                                 ->whereNotNull('left_at')
                                 ->get(['left_at', 'undispatched_order_ids']) as $m) {
                        $ids = json_decode((string) $m->undispatched_order_ids, true);
                        if (is_array($ids) && $ids) {
                            $missed[] = ['left_ts' => strtotime((string) $m->left_at), 'left_at' => (string) $m->left_at,
                                         'ids' => array_map('intval', $ids)];
                        }
                    }
                }
            } catch (\Throwable $e) {
                $missed = [];
            }

            // Walk forward: per order, its latest prior dispatch (batch + eta + pos)
            // and whether a cancel came after it.
            $lastDispatch = [];   // order_id => ['batch' => idx, 'eta' => , 'pos' => ]
            $cancelledAfter = []; // order_id => true when its last event is a cancel
            $riderPressedBefore = false; // the rider dispatched something himself earlier today
            $out = [];
            foreach ($actions as $i => $a) {
                $entry = [
                    'type'        => $a['type'],
                    'at'          => $a['at'],
                    'batch_ts'    => $a['batch_ts'],
                    'by'          => $a['by'],
                    'by_name'     => $a['by'] !== null ? ($names[$a['by']] ?? null) : null,
                    'by_self'     => $a['by_self'],
                    'order_count' => count($a['stops']),
                    'order_ids'   => array_keys($a['stops']),
                    'delivered_before' => $a['delivered_before'],
                    'origin_source'    => $a['origin_source'],
                    'rider_distance_m' => $a['rider_distance_m'],
                    'rider_gps_age_s'  => $a['rider_gps_age_s'],
                ];

                if ($a['type'] === 'cancel') {
                    foreach (array_keys($a['stops']) as $oid) {
                        $cancelledAfter[$oid] = true;
                    }
                    $entry['merged'] = false;   // resolved below
                    $out[$i] = $entry;
                    continue;
                }

                $priorBatches = []; $added = 0; $shifts = [];
                $afterCancel = false; $priorPairs = [];
                foreach ($a['stops'] as $oid => $s) {
                    $p = $lastDispatch[$oid] ?? null;
                    if ($p === null) { $added++; continue; }
                    $priorBatches[$p['batch']] = true;
                    if (!empty($cancelledAfter[$oid])) $afterCancel = true;
                    $priorPairs[] = [$p['pos'] ?? 0, $s['pos'] ?? 0];
                    if ($p['eta'] && $s['eta']) {
                        $shifts[] = (int) round((strtotime($s['eta']) - strtotime($p['eta'])) / 60);
                    }
                }

                $kind = 'self';
                $priorIdx = count($priorBatches) === 1 ? array_key_first($priorBatches) : null;
                if (!$a['by_self']) {
                    if (!$priorBatches) {
                        // Never-dispatched orders. 'first' = the rider pressed nothing
                        // today (he forgot); 'next' = he had pressed his own wave(s)
                        // and the store sent a further wave for him — not a missed
                        // press, so it must not read as one.
                        $kind = $riderPressedBefore ? 'next' : 'first';
                        // B4: …unless he had already LEFT with some of these very orders
                        // without pressing (a missed-dispatch row, left at or before this
                        // press) — then the store sending them is "rider didn't press".
                        // Evidence only: never inferred from distance.
                        if ($kind === 'next' && $missed) {
                            $atTs = strtotime($a['at']);
                            $oids = array_keys($a['stops']);
                            foreach ($missed as $m) {
                                if ($m['left_ts'] <= $atTs && array_intersect($oids, $m['ids'])) {
                                    $kind = 'first';
                                    $entry['missed_left_at'] = $m['left_at'];
                                    break;
                                }
                            }
                        }
                    } else {
                        // Re-ordered: the relative order of the carried-over stops changed.
                        usort($priorPairs, fn ($x, $y) => $x[0] <=> $y[0]);
                        $reordered = false;
                        for ($k = 1; $k < count($priorPairs); $k++) {
                            if ($priorPairs[$k][1] < $priorPairs[$k - 1][1]) { $reordered = true; break; }
                        }
                        // Removed: a stop of the earlier batch that is not in this
                        // one and had not been delivered by the time of this press.
                        $removed = 0;
                        if ($priorIdx !== null) {
                            $atTs = strtotime($a['at']);
                            foreach (array_keys($actions[$priorIdx]['stops']) as $oid) {
                                if (isset($a['stops'][$oid])) continue;
                                $del = $deliveredTs[$oid] ?? null;
                                if ($del === null || $del > $atTs) $removed++;
                            }
                        }
                        $kind = ($added === 0 && $priorIdx !== null && !$reordered && $removed === 0)
                            ? 'retime' : 'reroute';
                        $entry['added'] = $added;
                        $entry['removed'] = $removed;
                        $entry['reordered'] = $reordered;
                    }
                }
                if ($a['by_self']) {
                    $riderPressedBefore = true;
                }
                $entry['kind'] = $kind;
                $entry['after_cancel'] = $afterCancel;
                $entry['shift_min'] = $shifts ? (int) round(array_sum($shifts) / count($shifts)) : null;
                if ($priorIdx !== null) {
                    $pa = $actions[$priorIdx];
                    $entry['prior_at'] = $pa['at'];
                    $entry['prior_by_self'] = $pa['by_self'];
                    $entry['prior_by_name'] = $pa['by'] !== null ? ($names[$pa['by']] ?? null) : null;
                }

                foreach ($a['stops'] as $oid => $s) {
                    $lastDispatch[$oid] = ['batch' => $i, 'eta' => $s['eta'], 'pos' => $s['pos']];
                    unset($cancelledAfter[$oid]);
                }
                $out[$i] = $entry;
            }

            // A cancel immediately re-dispatched by the same person is one action.
            foreach ($out as $i => &$e) {
                if ($e['type'] !== 'cancel') continue;
                $cTs = strtotime($e['at']);
                for ($j = $i + 1; $j < count($out); $j++) {
                    $n = $out[$j];
                    if (strtotime($n['at']) - $cTs > 600) break;
                    if ($n['type'] === 'dispatch' && $n['by'] === $e['by']
                        && array_intersect($e['order_ids'], $n['order_ids'])) {
                        $e['merged'] = true;
                        $e['merged_into'] = $n['batch_ts'];
                        break;
                    }
                }
            }
            unset($e);

            return array_values($out);
        } catch (\Throwable $e) {
            Log::warning('EtaPromiseService: dayDispatchLog failed (non-fatal)', [
                'rider_id' => $riderId, 'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Rider-initiated re-dispatches that actually MOVED times already promised to
     * customers, made while he was already part-way through the day's deliveries.
     * The daily-issues "Mid-run route changes" section. One entry per wave.
     *
     * The load-bearing rule (owner, Jul-2026): a wave is a "route change" ONLY if
     * it RE-TIMES a stop whose latest prior event was itself a `dispatch` — i.e. a
     * live promise was overwritten. This is what keeps a GENUINE next wave honest:
     *  - A returning rider who finished wave 1 and dispatches fresh wave-2 orders
     *    has delivered_before > 0, but those orders were never dispatched before
     *    (no prior event, or a prior `cancel`), so nothing is re-timed → NOT
     *    flagged. Gating on delivered_before alone (the old bug) false-flagged
     *    exactly this case.
     *  - A `cancel` (e.g. cancel-dispatch at the office to merge in a new order)
     *    breaks the chain: the next dispatch is fresh, not a re-time.
     * This makes the report agree with the A3 push alert by construction (that
     * alert already gates on isRedispatch = "re-timed an already-timed stop").
     *
     * Also requires is_rider_self (store re-times are sanctioned) and
     * delivered_before > 0 (an at-office re-time before leaving moves nobody's
     * expectations — the per-order info chip still shows it, this row does not).
     *
     * Shift per re-timed order is measured against ITS OWN prior promise (the
     * immediately preceding dispatch), so it reports what THIS action moved.
     */
    public static function midRunChanges(int $riderId, string $date): array
    {
        if (!self::available()) {
            return [];
        }
        try {
            // ALL events (dispatch AND cancel), chronological — cancels are needed
            // to tell a genuine re-time from a fresh wave.
            $rows = DB::table(self::TABLE)
                ->where('rider_id', $riderId)
                ->whereDate('created_at', $date)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['order_id', 'event', 'batch_ts', 'estimated_delivery_at',
                       'is_rider_self', 'delivered_before', 'created_at']);
            if ($rows->isEmpty()) {
                return [];
            }

            // Walk forward tracking, per order, its most recent PRIOR event and the
            // ETA that dispatch promised — so each dispatch can be classified as a
            // re-time (prior event was a dispatch) and its shift measured.
            $priorEvent = [];   // order_id => 'dispatch' | 'cancel'
            $priorEta   = [];   // order_id => last dispatch ETA seen so far
            $waves      = [];   // batch_ts => ['head' => row, 'shifts' => [], 'count' => n]

            foreach ($rows as $r) {
                $oid = (int) $r->order_id;

                if ($r->event === 'cancel') {
                    $priorEvent[$oid] = 'cancel'; // breaks the re-time chain
                    continue;
                }

                // A dispatch RE-TIMES this order only if its previous event was a
                // dispatch (a live promise existed and was overwritten).
                if (($priorEvent[$oid] ?? null) === 'dispatch') {
                    $key = (string) $r->batch_ts;
                    if (!isset($waves[$key])) {
                        $waves[$key] = ['head' => $r, 'shifts' => [], 'count' => 0];
                    }
                    $waves[$key]['count']++;
                    $base = $priorEta[$oid] ?? null;
                    if ($base && $r->estimated_delivery_at) {
                        $waves[$key]['shifts'][] = (int) round(
                            (strtotime($r->estimated_delivery_at) - strtotime($base)) / 60
                        );
                    }
                }

                // Advance this order's state for the next event.
                $priorEvent[$oid] = 'dispatch';
                $priorEta[$oid]   = $r->estimated_delivery_at;
            }

            $out = [];
            foreach ($waves as $w) {
                $head = $w['head'];
                // Rider-initiated, and he'd already started delivering. (A re-time
                // can't be the day's first wave, so no explicit first-wave guard is
                // needed — the prior-dispatch requirement already excludes it.)
                if (!$head->is_rider_self || (int) $head->delivered_before < 1) {
                    continue;
                }
                $shifts = $w['shifts'];
                $out[] = [
                    'at'               => $head->created_at,
                    'batch_ts'         => $head->batch_ts,
                    'order_count'      => $w['count'],          // orders whose promise moved
                    'delivered_before' => (int) $head->delivered_before,
                    'avg_shift_min'    => $shifts ? (int) round(array_sum($shifts) / count($shifts)) : null,
                    'max_shift_min'    => $shifts ? max($shifts) : null,
                ];
            }
            // Chronological for display.
            usort($out, fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']));
            return $out;
        } catch (\Throwable $e) {
            Log::warning('EtaPromiseService: midRunChanges failed (non-fatal)', [
                'rider_id' => $riderId, 'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
