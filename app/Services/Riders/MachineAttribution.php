<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * ⭐⭐ THE ONE ATTRIBUTION ENGINE (Aug-2026) — every kilometre this month, walked
 *     MACHINE-FIRST and labelled with who owns it.
 *
 * WHY IT EXISTS
 * The Bikes page had two lenses that could not agree. The Riders view chained a
 * rider's own attendance readings and never asked which bike produced them; the
 * Vehicles view chained the machine but had no idea a day had been shared. On a
 * handover both were wrong in opposite directions — Waseem carried 563 km of
 * DCR-799's August as "unattributed" (i.e. as suspicion) while 40 of those km were
 * Danish's overnight and 494 were days the two of them split. One walk, two lenses,
 * and the blame lands where the evidence actually points.
 *
 * ⭐ THE FIVE LEGS. Every stretch of odometer between two readings is exactly one:
 *
 *   on_duty      check-in → day close, one rider, his kilometres
 *   off_duty     between readings with nobody on shift — SAME rider both ends,
 *                so it is genuinely his (the commute, the evening errand)
 *   shared       a handover DAY: the morning reading is one rider's and the
 *                evening reading is the other's. The run is real, the split is
 *                unknowable, so it is charged to NEITHER man and named on both.
 *   transfer     a stretch whose two ends belong to different riders across a
 *                handover — the bike travelling between them. Nobody's fault.
 *   unaccounted  a stretch spanning a day someone WORKED with no usable reading:
 *                part work, part commute, unsplittable, credited to no one.
 *
 * ⭐⭐ THE INVARIANT, PER MACHINE:
 *        on_duty + off_duty + shared + transfer + unaccounted == the month's span
 *     `reconciles` reports it honestly rather than printing a total that disagrees
 *     with the header. Real August: DCR-799 = 674 + 40 + 494 + 29 + 0 = 1,237. ✓
 *
 * ⚠⚠ THREE RULES THAT LOOK LIKE DETAILS AND ARE NOT
 *
 * R-A  ONE MACHINE PER RIDER-DAY, decided by `VehicleResolver`. The older code
 *      pulled each keeper's attendance per assignment WINDOW, so a rider holding
 *      two machines across one day (Farooq: van in the morning, EDN-198 from the
 *      8th) had the SAME readings ingested into BOTH chains. Worse, a same-day
 *      assign/release slip put DCR-799's 25,223 into Danish's own bike's ~24,500
 *      chain as ~700 phantom km. The resolver already answers this question for the
 *      live gates; the analytics must ask it too, or the two disagree.
 *
 * R-B  PLAUSIBILITY IS RELATIVE TO THE MACHINE'S OWN CHAIN — never an absolute
 *      floor. The old rule refused any reading under 1,000 km as a dropped digit,
 *      which is right for a 47,000 km bike and catastrophic for a NEW one: every
 *      reading EDN-198 has ever produced (0–429) was discarded, which is precisely
 *      why the live page says "Rs 3,800 could not be tied to any kilometres —
 *      Farooq". Typos are caught by the chain instead (backwards, or a jump beyond
 *      MAX_GAP_KM), which is strictly stronger: it also catches a typo of 45,000 on
 *      a bike that reads 400, which no floor ever would.
 *
 * R-C  A SAME-DAY ASSIGN+RELEASE BLIP IS INVISIBLE. It never wins the resolver for
 *      a day that has readings, so it contributes nothing here — no special case is
 *      needed, which is the point. (The UI hides such rows from month views and
 *      keeps them in the audit history.)
 *
 * ⚠ SCOPE. This is a READ. It writes nothing, and no gate, fuel rule or alert
 *   consults it. Riders the registry has never tracked are simply absent from the
 *   output, and their callers keep the rider-keyed answer they have always had.
 */
class MachineAttribution
{
    /** A day's on-duty distance beyond this is a typo, not a ride. */
    public const MAX_DAY_KM = 500;

    /** A single unwitnessed stretch beyond this is a typo'd meter, not a distance. */
    public const MAX_GAP_KM = 2000;

    /** The ride home cannot plausibly exceed this. */
    public const MAX_HOME_KM = 700;

    private const TTL = 120;

    /**
     * ⭐ Bumped with the Sep-30 off-duty rules: the cached array gained keys (jitter_km,
     *   company_offduty_km, from_meter …). A new key means a page loaded in the two minutes
     *   after an upload can never read an old-shape array that lacks them.
     */
    private const CACHE_KEY = 'machine_attr:v2:';

    /** How far back to look for the rider who made a machine's month-opening reading. */
    private const OPENING_LOOKBACK_DAYS = 60;

    /** [vehicle_id][user_id] => list of [from, to|null] assignment windows, loaded once. */
    private static ?array $windows = null;

    /** Memo of `jitterKm()` / `custodyOn()` per process. */
    private static ?float $jitterMemo = null;
    private static ?bool $custodyMemo = null;

    /**
     * Everything, for one month, cached. Shape:
     *
     *   vehicles[vid] = ['legs'=>[], 'days'=>[], 'totals'=>[], 'span'=>?int,
     *                    'opens_at'=>?int, 'closes_at'=>?int, 'reconciles'=>bool]
     *   riders[uid]   = ['work_km','offduty_km','shared_km','transfer_km',
     *                    'unattributed_km','machines'=>[vid=>[...]], 'days'=>[date=>[...]]]
     */
    public function month(string $month, bool $fresh = false): array
    {
        $key = self::CACHE_KEY . $month;
        if ($fresh) Cache::forget($key);

        return Cache::remember($key, self::TTL, function () use ($month) {
            try {
                return $this->build($month);
            } catch (\Throwable $e) {
                Log::warning('MachineAttribution build failed', [
                    'month' => $month, 'error' => $e->getMessage(),
                ]);
                return ['vehicles' => [], 'riders' => [], 'available' => false];
            }
        });
    }

    /** One machine's month, or null when the engine has nothing for it. */
    public function forVehicle(int $vehicleId, string $month, bool $fresh = false): ?array
    {
        return $this->month($month, $fresh)['vehicles'][$vehicleId] ?? null;
    }

    /** One rider's month across every machine he touched, or null. */
    public function forRider(int $userId, string $month, bool $fresh = false): ?array
    {
        return $this->month($month, $fresh)['riders'][$userId] ?? null;
    }

    /**
     * Every leg this rider is named on, grouped by date — his own stretches AND
     * the shared/transfer ones he is merely a party to. The drill-down reads this
     * to say, on the day itself, "187 km shared with Danish" instead of quietly
     * adding it to a number with his name on top.
     */
    public function legsForRider(int $userId, string $month, bool $fresh = false): array
    {
        $out = [];
        foreach ($this->month($month, $fresh)['vehicles'] ?? [] as $v) {
            foreach ($v['legs'] as $l) {
                $mine = $l['user_id'] === $userId
                     || $l['from_user'] === $userId || $l['to_user'] === $userId;
                if (!$mine) continue;
                $out[$l['date']][] = $l + ['vehicle_label' => $v['label'],
                                           'vehicle_is_company' => !empty($v['is_company'])];
            }
        }
        ksort($out);
        return $out;
    }

    public function flush(string $month): void
    {
        Cache::forget(self::CACHE_KEY . $month);
        Cache::forget('machine_attr:' . $month);   // the pre-v2 key, in case it is still warm
        self::$windows = null;
    }

    // =================================================================
    // ⭐⭐ OFF-DUTY — THE ONE ANSWER EVERY SCREEN PRINTS (Sep-30 2026)
    // =================================================================
    //
    // Owner rulings of 30-Sep:
    //   1. Only a COMPANY machine (bike or van) produces off-duty km. An own bike never does.
    //   2. A stretch is his only if he made both readings AND the machine was his on every
    //      day in between (see `heldThroughout`). A handover day counts as his for BOTH men,
    //      so a bike received mid-day, closed that night and opened next morning is his.
    //   3. A stretch of METER_CONTINUITY_KM or less (1 km) is odometer rounding, not riding.
    //
    // The Bikes table, its night list, Attendance (web + phone) and the rider's own phone
    // block all read `offDutyNights()` / the rider roll-up below, so they cannot disagree.

    /**
     * Every off-duty night this rider is charged with this month, newest first.
     * The sum of `km` here IS his `company_offduty_km`.
     *
     * @return array<int, array{date:string, since:?string, km:int, from:?int, to:?int,
     *   vehicle_id:int, vehicle_label:string, vtype:string, source:?string, home:bool, tail:bool}>
     */
    public function offDutyNights(int $userId, string $month, bool $fresh = false): array
    {
        $out = [];
        foreach ($this->month($month, $fresh)['vehicles'] ?? [] as $vid => $v) {
            if (empty($v['is_company'])) continue;
            foreach ($v['legs'] as $l) {
                if (!self::isChargedOffDuty($l, $userId)) continue;
                // `since` only when the stretch spans MORE than one night (a day off between),
                // so an ordinary night never reads "(since yesterday)". `from_date` is exact.
                $since = $l['since'] ?? null;
                $multi = $since !== null
                    && Carbon::parse($since)->diffInDays(Carbon::parse($l['date'])) > 1;
                $out[] = [
                    'date'          => $l['date'],
                    'since'         => $multi ? $since : null,
                    'from_date'     => $since,
                    'km'            => (int) $l['km'],
                    'from'          => $l['from_meter'] ?? null,
                    'to'            => $l['to_meter'] ?? null,
                    'vehicle_id'    => (int) $vid,
                    'vehicle_label' => $v['label'],
                    'vtype'         => $v['vtype'] ?? 'bike',
                    // where the reading that ENDED the stretch was taken:
                    // home | checkin | manager | log | null
                    'source'        => $l['to_source'] ?? null,
                    'home'          => !empty($l['home']),
                    'tail'          => !empty($l['tail']),
                ];
            }
        }
        usort($out, fn ($a, $b) => [$b['date'], $b['vehicle_id']] <=> [$a['date'], $a['vehicle_id']]);
        return $out;
    }

    /**
     * His month on COMPANY machines only — what the rider's own "Company bike this month"
     * block and the Attendance column show. Null when the engine has nothing on him.
     */
    public function companyMonth(int $userId, string $month, bool $fresh = false): ?array
    {
        $r = $this->forRider($userId, $month, $fresh);
        if (!$r) return null;
        $work = 0; $days = 0;
        foreach ($r['machines'] as $m) {
            if (empty($m['is_company'])) continue;
            $work += (int) $m['work_km'];
            $days += (int) $m['days'];
        }
        $nights = $this->offDutyNights($userId, $month);
        $off = array_sum(array_column($nights, 'km'));
        return [
            'had_company' => !empty($r['had_company']),
            'work_km'     => $work,
            'offduty_km'  => $off,
            'days'        => $days,
            'nights'      => $nights,
        ];
    }

    /** One leg: is it off-duty charged to this rider, above the rounding threshold? */
    private static function isChargedOffDuty(array $l, int $userId): bool
    {
        return $l['kind'] === 'off_duty' && $l['user_id'] === $userId
            && $l['km'] > self::jitterKm();
    }

    /** Stretches this short are meter rounding. Same config as the morning check. */
    public static function jitterKm(): float
    {
        if (self::$jitterMemo !== null) return self::$jitterMemo;
        try {
            $v = (new WorkJourneyService())->continuityKm();
        } catch (\Throwable $e) {
            $v = 1.0;
        }
        return self::$jitterMemo = max(0.0, (float) $v);
    }

    /**
     * Rollback valve for the custody rule: `OFFDUTY_CUSTODY = 'N'` in t_fin_config puts
     * every stretch back to "same rider both ends = his", without an upload.
     */
    private static function custodyOn(): bool
    {
        if (self::$custodyMemo !== null) return self::$custodyMemo;
        try {
            $v = DB::table('t_fin_config')->where('config_key', 'OFFDUTY_CUSTODY')->value('config_value');
            return self::$custodyMemo = strtoupper(trim((string) ($v ?? 'Y'))) !== 'N';
        } catch (\Throwable $e) {
            return self::$custodyMemo = true;
        }
    }

    /**
     * ⭐⭐ WAS THE MACHINE HIS ON EVERY DAY STRICTLY BETWEEN TWO OF HIS READINGS?
     *
     * The two end days need no check: he took a reading on this machine on each, which is
     * evidence of custody in itself (that is also why a handover day is "his" for both men).
     * Only the reading-less days in between are asked, and a day is his when an assignment
     * window of THIS machine covers it (release day and assign day inclusive) or the
     * resolver — day override, open assignment, usual machine — says he was on it.
     *
     * Real case this exists for: the van, 4 → 9 Sep. Rajab held it 3–5 Sep and again from
     * the 9th; on 6–8 Sep it was assigned to nobody, so those 41 km are not his.
     *
     * ⚠ Fails OPEN (true) on any error: an unanswerable question must never move kilometres.
     */
    public function heldThroughout(int $userId, int $vehicleId, ?string $fromDate, string $toDate): bool
    {
        if ($fromDate === null || !self::custodyOn()) return true;
        try {
            $c = Carbon::parse($fromDate)->addDay();
            $end = Carbon::parse($toDate);
            if ($c->gte($end)) return true;

            $wins = self::windowsFor($vehicleId, $userId);
            $overrides = null;
            while ($c->lt($end)) {
                $d = $c->format('Y-m-d');
                $held = false;
                foreach ($wins as [$wf, $wt]) {
                    if ($wf <= $d && ($wt === null || $wt >= $d)) { $held = true; break; }
                }
                if (!$held) {
                    // The manager's explicit day override also puts him on it.
                    // ⚠ NOT the profile's "usual machine": it is a convenience mirror that can
                    //   go stale, and it would hand him a van for a day nobody gave it to him.
                    $overrides = $overrides ?? self::overrideDays($userId, $vehicleId);
                    $held = isset($overrides[$d]);
                }
                if (!$held) return false;
                $c->addDay();
            }
            return true;
        } catch (\Throwable $e) {
            Log::warning('heldThroughout failed', ['user' => $userId, 'vehicle' => $vehicleId, 'error' => $e->getMessage()]);
            return true;
        }
    }

    /** Days a manager put this rider on this machine by day-override, as a date set. */
    private static function overrideDays(int $userId, int $vehicleId): array
    {
        static $memo = [];
        $k = $userId . '|' . $vehicleId;
        if (isset($memo[$k])) return $memo[$k];
        try {
            $dates = DB::table('t_ops_attendance')
                ->where('user_id', $userId)->where('vehicle_id', $vehicleId)
                ->pluck('attendance_date')->all();
        } catch (\Throwable $e) {
            $dates = [];
        }
        $set = [];
        foreach ($dates as $d) $set[substr((string) $d, 0, 10)] = true;
        return $memo[$k] = $set;
    }

    /** Assignment windows of one machine for one rider (all loaded in ONE query). */
    private static function windowsFor(int $vehicleId, int $userId): array
    {
        if (self::$windows === null) {
            self::$windows = [];
            try {
                foreach (DB::table(VehicleService::T_ASSIGN)
                            ->get(['vehicle_id', 'user_id', 'assigned_on', 'released_on']) as $a) {
                    self::$windows[(int) $a->vehicle_id][(int) $a->user_id][] = [
                        substr((string) $a->assigned_on, 0, 10),
                        $a->released_on ? substr((string) $a->released_on, 0, 10) : null,
                    ];
                }
            } catch (\Throwable $e) {
                self::$windows = [];
            }
        }
        return self::$windows[$vehicleId][$userId] ?? [];
    }

    /**
     * WHO WROTE DOWN THIS MACHINE'S READING `$value`, the last time before `$beforeDate`?
     *
     * Used for the stretch that opens a month (the walk starts from a bare number) and for
     * the rider's morning prompt (is "last night's meter" his own, or the previous keeper's?).
     * Looks at attendance readings — a stamped reading must name this machine, an unstamped
     * one must fall on a day the resolver puts him on it — then at manager meter logs.
     *
     * @return null|array{user_id:int, date:string}  null = cannot tell (callers keep the old answer)
     */
    public function readingOwner(int $vehicleId, int $value, string $beforeDate): ?array
    {
        try {
            $before = Carbon::parse($beforeDate)->subDay()->format('Y-m-d');
            $lo = Carbon::parse($beforeDate)->subDays(self::OPENING_LOOKBACK_DAYS)->format('Y-m-d');
            $stamped = VehicleService::stampsAvailable();
            $cols = ['meter_start', 'meter_end', 'meter_home'];

            $rows = DB::table('t_ops_attendance')
                ->whereBetween('attendance_date', [$lo, $before])
                ->where(function ($q) use ($value) {
                    $q->where('meter_start', $value)->orWhere('meter_end', $value)->orWhere('meter_home', $value);
                })
                ->orderByDesc('attendance_date')
                ->get(array_merge(['user_id', 'attendance_date'], $cols,
                    $stamped ? ['meter_start_vehicle_id', 'meter_end_vehicle_id', 'meter_home_vehicle_id'] : []));

            $resolver = new VehicleResolver();
            foreach ($rows as $r) {
                $d = substr((string) $r->attendance_date, 0, 10);
                foreach ($cols as $col) {
                    if ((int) $r->$col !== $value) continue;
                    $stamp = $stamped ? ($r->{$col . '_vehicle_id'} ?? null) : null;
                    $mine = $stamp !== null
                        ? (int) $stamp === $vehicleId
                        : $resolver->vehicleForDay((int) $r->user_id, $d) === $vehicleId;
                    if ($mine) return ['user_id' => (int) $r->user_id, 'date' => $d];
                }
            }

            if (\Illuminate\Support\Facades\Schema::hasTable('t_ops_vehicle_meter_log')) {
                $log = DB::table('t_ops_vehicle_meter_log')
                    ->where('vehicle_id', $vehicleId)
                    ->whereBetween('log_date', [$lo, $before])
                    ->whereNotNull('driver_user_id')
                    ->where(function ($q) use ($value) {
                        $q->where('meter_end', $value)->orWhere('meter_start', $value);
                    })
                    ->orderByDesc('log_date')
                    ->first(['driver_user_id', 'log_date']);
                if ($log) {
                    return ['user_id' => (int) $log->driver_user_id, 'date' => substr((string) $log->log_date, 0, 10)];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('readingOwner failed', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
        }
        return null;
    }

    // =================================================================
    // THE BUILD
    // =================================================================

    private function build(string $month): array
    {
        $out = ['vehicles' => [], 'riders' => [], 'available' => false];

        $vs = new VehicleService();
        if (!$vs->available()) return $out;
        $out['available'] = true;

        $start = Carbon::parse($month . '-01')->startOfMonth();
        $end   = $start->copy()->endOfMonth();
        $from  = $start->format('Y-m-d');
        $to    = $end->format('Y-m-d');

        $vehicles = DB::table(VehicleService::T_VEHICLE)->get(['id', 'reg_no', 'nickname', 'vtype', 'is_company']);
        if ($vehicles->isEmpty()) return $out;

        // --- who the registry can speak for: anyone with an assignment, ever ---
        $riderIds = DB::table(VehicleService::T_ASSIGN)->distinct()->pluck('user_id')
            ->map(fn ($v) => (int) $v)->all();
        if (!$riderIds) return $out;

        $resolver = new VehicleResolver();
        $dayMap   = $resolver->vehiclesForDays($riderIds, $from, $to);

        $names = DB::table('t_sys_user')->whereIn('id', $riderIds)
            ->pluck('fullname', 'id')->toArray();

        // --- one query for the month's attendance, then split it by machine ---
        $att = DB::table('t_ops_attendance')
            ->whereIn('user_id', $riderIds)
            ->whereBetween('attendance_date', [$from, $to])
            ->orderBy('attendance_date')
            ->get(['user_id', 'attendance_date', 'meter_start', 'meter_end', 'meter_home',
                   'meter_start_source', 'login_time', 'logout_time', 'leave_type',
                   // ⭐ WHEN the reading was written down. The day card orders its
                   //   lines by real time, so a handover recorded at 22:49 sits
                   //   after a 21:42 close instead of being guessed into place.
                   'meter_start_recorded_at', 'home_meter_recorded_at',
                   ...(VehicleService::stampsAvailable()
                       ? ['meter_start_vehicle_id', 'meter_end_vehicle_id', 'meter_home_vehicle_id']
                       : [])]);

        // ⭐⭐ STEP C — a rider-day may now describe TWO machines, so it may produce two rows.
        //
        //    R-A ("one machine per rider-day") is still the answer when nothing says otherwise —
        //    but it was never true of a mid-day handover, and that is how the Van ended up
        //    showing a 6,434 km reading against a ~73,800 km odometer. Where a reading carries a
        //    stamp (recorded at the moment it was taken) the stamp WINS over the day map, and the
        //    row is split so each machine receives only the numbers that are actually its own.
        //
        // ⚠ `pointsForRow()` already copes with a partial row — that is its `max($known)` branch —
        //   so a half-row (close only, or start only) needs no special handling downstream.
        // ⚠ Unstamped rows take `$vid` exactly as before, so all history is untouched.
        $stamped = VehicleService::stampsAvailable();
        $rowsByVehicle = [];
        foreach ($att as $a) {
            $uid = (int) $a->user_id;
            $d   = substr((string) $a->attendance_date, 0, 10);
            $vid = $dayMap[$uid . '|' . $d] ?? null;

            $reads = [
                'meter_start' => $this->reading($a->meter_start),
                'meter_end'   => $this->reading($a->meter_end),
                'meter_home'  => $this->reading($a->meter_home),
            ];

            // Bucket each reading under the machine it belongs to.
            $buckets = [];
            foreach ($reads as $col => $val) {
                $target = $stamped ? ($a->{$col . '_vehicle_id'} ?? null) : null;
                $target = $target ? (int) $target : $vid;
                if (!$target) continue;                // held nothing, and nothing said otherwise
                $buckets[$target][$col] = $val;
            }
            if (!$buckets) continue;

            foreach ($buckets as $target => $vals) {
                $rowsByVehicle[$target][] = [
                    'date'         => $d,
                    'user_id'      => $uid,
                    'keeper'       => $names[$uid] ?? null,
                    'meter_start'  => $vals['meter_start'] ?? null,
                    'meter_end'    => $vals['meter_end'] ?? null,
                    'meter_home'   => $vals['meter_home'] ?? null,
                    'start_source' => $a->meter_start_source ?: null,
                    'leave'        => $a->leave_type ?: null,
                    'worked'       => $a->login_time !== null,
                    'closed'       => $a->logout_time !== null,
                    'start_at'     => $this->clock($a->meter_start_recorded_at ?? null),
                    'end_at'       => $this->clock($a->home_meter_recorded_at ?? null),
                ];
            }
        }

        $handovers = $this->handoverMeters($from, $to);

        // ⭐⭐ Aug-2026 — THE THIRD EVIDENCE SOURCE: readings entered from the
        //   Vehicles page for a machine nobody's attendance row covers (the
        //   mid-day van stint). Vehicle-keyed by construction, so R-A never applies
        //   to them; the named driver gets the kilometres on BOTH lenses.
        $logs = $this->meterLogs($from, $to);

        foreach ($vehicles as $v) {
            $vid = (int) $v->id;
            $out['vehicles'][$vid] = $this->walk(
                $vid,
                $this->labelFor($v),
                (int) $v->is_company === 1,
                $rowsByVehicle[$vid] ?? [],
                $vs,
                $month, $from, $to,
                $handovers[$vid] ?? [],
                $logs[$vid] ?? []
            );
            // ⭐ WHAT THE MACHINE IS — carried alongside the walk rather than threaded
            //   through its signature. `walk()` reasons about odometers, not body shapes;
            //   the registry already knows, and the rider lens picks it up from here.
            $out['vehicles'][$vid]['vtype'] = ((string) ($v->vtype ?? '')) === 'van' ? 'van' : 'bike';
        }

        $out['riders'] = $this->rollUpRiders($out['vehicles'], $names);
        return $out;
    }

    /**
     * ONE MACHINE, START TO FINISH.
     *
     * The chain is a list of reading POINTS in odometer order, each owned by the
     * rider whose attendance row (or claim) carried it. Between every consecutive
     * pair sits exactly one leg, and which leg it is depends only on who owns the
     * two ends and what happened in between.
     */
    private function walk(int $vehicleId, string $label, bool $isCompany, array $rows,
                          VehicleService $vs, string $month, string $from, string $to,
                          array $handoverByDate, array $logByDate = []): array
    {
        $totals = ['on_duty' => 0, 'off_duty' => 0, 'shared' => 0,
                   'transfer' => 0, 'unaccounted' => 0, 'total' => 0,
                   'days_counted' => 0, 'no_meter_days' => 0];
        $out = [
            'vehicle_id' => $vehicleId, 'label' => $label, 'is_company' => $isCompany,
            'month' => $month, 'legs' => [], 'days' => [], 'totals' => $totals,
            'span' => null, 'opens_at' => null, 'closes_at' => null, 'reconciles' => true,
            'boundary_dates' => [], 'keepers' => [], 'spend' => [], 'spend_total' => null,
            'events' => [], 'day_cards' => [],
        ];

        $boundary = $this->boundaryDates($vehicleId);
        $out['boundary_dates'] = array_keys($boundary);

        /**
         * ⭐⭐ METER REPLACED (5-Oct-2026). This walk orders readings by odometer and reads
         *    every stretch as a difference, so a new meter starting at 0 looked like "the meter
         *    went backwards" and the month stopped adding up. For a machine with a replacement
         *    on record every reading is lifted onto its CONTINUOUS scale before the walk (see
         *    `MeterReplacement`) and each displayed figure is put back as it was typed at the
         *    end (`showAsTyped`). `$replaced` is false — and nothing here runs — otherwise.
         */
        $replaced = MeterReplacement::has($vehicleId);
        if ($replaced) {
            $lift = fn ($v, $d) => MeterReplacement::toContinuous($vehicleId, $v, $d);
            foreach ($rows as $i => $r) {
                foreach (['meter_start', 'meter_end', 'meter_home'] as $k) {
                    $rows[$i][$k] = $lift($r[$k] ?? null, $r['date']);
                }
            }
            foreach ($handoverByDate as $hd => $hm) {
                $handoverByDate[$hd] = $lift($hm, $hd);
            }
            foreach ($logByDate as $ld => $lr) {
                foreach (['meter_start', 'meter_end'] as $k) {
                    $logByDate[$ld][$k] = $lift($lr[$k] ?? null, $ld);
                }
            }
        }

        $claims = $vs->claimsForVehicle($vehicleId, $from, $to);

        // ⭐ WHAT THIS MACHINE COST, AND WHO FILED IT. Kept here rather than left to
        //   the callers because both lenses need the same split: the rider strip
        //   ("his fuel on this bike") and the vehicle profile ("who spent what on
        //   it") must never be two different sums of the same claims.
        $out['spend'] = $this->spendByUser($claims);
        $out['spend_total'] = $this->spendTotals($claims);

        // --- group the month's evidence by date ------------------------------
        $byDate = [];
        foreach ($rows as $r) {
            $byDate[$r['date']]['rows'][] = $r;
        }
        foreach ($claims as $c) {
            $byDate[$c['date']]['claims'][] = $c;
        }
        // ⚠ A manager's reading may be the ONLY evidence a date has — the van on a
        //   day nobody's attendance row covers. Seed those dates too, or the walk
        //   never visits them and the reading is silently ignored.
        foreach (array_keys($logByDate) as $ld) {
            if (!isset($byDate[$ld])) $byDate[$ld] = [];
        }
        if (!$byDate) {
            $out['days'] = [];
            return $out;
        }
        ksort($byDate);

        // --- where the month opens: the last reading before it, else the first
        //     reading inside it (a machine's FIRST month has nothing behind it) ---
        $win  = $vs->meterWindowContinuousFor($vehicleId, $from);
        $prev = $win['floor'] ?? null;
        $prevOwner = null;
        $prevDate  = null;
        // ⭐ Sep-30: the month opens on a bare number, so the first stretch used to belong to
        //   whoever read the machine first this month — even when last month's closing reading
        //   was somebody else's. Name who wrote it (and when) so the first stretch gets the same
        //   same-rider / handover / custody questions as every other stretch.
        //   Unknown ⇒ both stay null ⇒ exactly the old behaviour.
        if ($prev !== null && $isCompany && self::custodyOn()) {
            // (looked up by the figure as it was typed)
            $opener = $this->readingOwner($vehicleId, (int) MeterReplacement::toRawByValue($vehicleId, (int) $prev), $from);
            if ($opener) { $prevOwner = $opener['user_id']; $prevDate = $opener['date']; }
        }
        $dirty = false;
        $days  = [];
        $legs  = [];
        // ⭐ The first reading this month ACTUALLY produced, whatever its source.
        //   A claim-only or manager-logged day carries its reading in the POINT, not
        //   in the row's meter columns, so a machine measured purely from fills had
        //   no opening anchor and could never reconcile.
        $firstSeen = null;

        foreach ($byDate as $d => $bundle) {
            $dayRows   = $bundle['rows']   ?? [];
            $dayClaims = $bundle['claims'] ?? [];
            $isBoundary = isset($boundary[$d]);

            // Each rider-day becomes a point (or a pair of points on a full day).
            $points = [];
            $dayOut = [];

            foreach ($dayRows as $r) {
                $p = $this->pointsForRow($r);
                $dayOut[] = [
                    'date' => $d, 'user_id' => $r['user_id'], 'keeper' => $r['keeper'],
                    'meter_start' => $r['meter_start'], 'meter_end' => $r['meter_end'],
                    'meter_home' => $r['meter_home'], 'start_source' => $r['start_source'],
                    'start_at' => $r['start_at'] ?? null, 'end_at' => $r['end_at'] ?? null,
                    'work_km' => $p['work'], 'home_km' => $p['home'],
                    // ⚠⚠ 'half' — ONE reading on a day the machine CHANGED HANDS — is
                    //   the correct shape and must not be scolded for. Off a handover
                    //   day, one reading is a genuine miss and still counts as one:
                    //   excusing every single-reading day would quietly forgive real
                    //   lapses (Kanan's June) while fixing the false accusations.
                    'status' => $p['broken'] ? 'unusable'
                        : ($p['work'] !== null ? 'ok'
                        : ($r['leave'] ? 'leave'
                        : ($r['worked']
                            ? (($p['opens'] !== null && $isBoundary) ? 'half'
                                : ($this->readingWasDue($r) ? 'no_meter' : 'in_progress'))
                        : 'no_attendance'))),
                    'partial' => $p['opens'] !== null && $p['work'] === null,
                    'handover_day' => $isBoundary,
                    'gap_km' => null, 'gap_kind' => null, 'gap_since' => null,
                    'gap_from_user' => null, 'gap_to_user' => null, 'gap_user_id' => null,
                    'claims' => [],
                ];
                if ($p['opens'] !== null) {
                    $points[] = ['v' => $p['opens'], 'user_id' => $r['user_id'],
                                 'keeper' => $r['keeper'], 'kind' => 'open',
                                 'work' => $p['work'], 'closes' => $p['closes'],
                                 'home' => $p['home'], 'idx' => count($dayOut) - 1];
                }
            }

            // A claim on a day with no attendance row still proves the machine was
            // read that day — the only reading such a day leaves behind.
            // ⭐⭐ ONE POINT PER CLAIM, not one per day (fixed Aug-14). Collapsing the
            //   day to min→max threw away the distance BETWEEN two fills: the van has
            //   two claims on 17 Aug (73,342 and 73,410) and those 68 km sat inside
            //   the month's span while appearing in no leg, so the machine could not
            //   reconcile. Emitting each meter as its own point lets the ordinary gap
            //   machinery measure and OWN the stretch, filer by filer.
            if (!$points && $dayClaims) {
                $withMeter = [];
                foreach ($dayClaims as $c) {
                    $m = $this->reading($c['meter_cont'] ?? ($c['meter'] ?? null));
                    if ($m !== null) $withMeter[] = ['m' => $m, 'c' => $c];
                }
                if ($withMeter) {
                    usort($withMeter, fn ($a, $b) => $a['m'] <=> $b['m']);
                    $dayOut[] = [
                        'date' => $d, 'user_id' => $withMeter[0]['c']['by_user_id'] ?? null,
                        'keeper' => $withMeter[0]['c']['by_name'] ?? null,
                        'meter_start' => null, 'meter_end' => null, 'meter_home' => null,
                        'start_source' => null, 'work_km' => null, 'home_km' => null,
                        'status' => 'claim_only', 'partial' => false, 'handover_day' => $isBoundary,
                        'gap_km' => null, 'gap_kind' => null, 'gap_since' => null,
                        'gap_from_user' => null, 'gap_to_user' => null, 'gap_user_id' => null,
                        'claims' => [],
                    ];
                    $idx = count($dayOut) - 1;
                    foreach ($withMeter as $w) {
                        $points[] = ['v' => $w['m'], 'user_id' => $w['c']['by_user_id'] ?? null,
                                     'keeper' => $w['c']['by_name'] ?? null, 'kind' => 'claim',
                                     'work' => null, 'closes' => $w['m'], 'home' => null,
                                     'idx' => $idx];
                    }
                }
            }

            // ⭐⭐ THE MANAGER'S OWN READING for this machine on this date (Aug-2026).
            //   The mid-day van stint: the driver already opened his day on another
            //   machine, so his ONE set of attendance meters is spent and the van's
            //   kilometres have nowhere else to live. Added as ordinary chain points,
            //   so every downstream rule (sanity, legs, the invariant, reconciles)
            //   treats them exactly like any other reading.
            // ⚠ NO handover is implied: the machine's holder does not change, nobody's
            //   meter demand moves. Only the named DRIVER gets the kilometres.
            $logRow = $logByDate[$d] ?? null;
            // ⚠ DUPLICATE GUARD: if a manager typed the SAME readings the rider also
            //   recorded (log entered in the morning, the holder checks in on the
            //   machine later), ingesting both would double-count the day and the
            //   card would carry two identical lines. An exact duplicate is dropped;
            //   a DIFFERENT log stint on the same day (holder morning + borrower
            //   afternoon) is genuine and stays — the chain orders it by odometer.
            if ($logRow) {
                foreach ($dayRows as $dr) {
                    if ($dr['meter_start'] === $logRow['meter_start']
                        && $dr['meter_end'] === $logRow['meter_end']) {
                        $logRow = null;
                        break;
                    }
                }
            }
            if ($logRow && ($logRow['meter_start'] !== null || $logRow['meter_end'] !== null)) {
                $ls = $logRow['meter_start'];
                $le = $logRow['meter_end'];
                $lWork = ($ls !== null && $le !== null && $le >= $ls
                          && ($le - $ls) <= self::MAX_DAY_KM) ? $le - $ls : null;

                $dayOut[] = [
                    'date' => $d, 'user_id' => $logRow['driver_id'], 'keeper' => $logRow['driver'],
                    'meter_start' => $ls, 'meter_end' => $le, 'meter_home' => null,
                    'start_source' => 'log', 'start_at' => null, 'end_at' => null,
                    'work_km' => $lWork, 'home_km' => null,
                    'status' => $lWork !== null ? 'ok' : 'half',
                    'partial' => $lWork === null,
                    'handover_day' => $isBoundary,
                    'from_log' => true,
                    'log_id' => $logRow['id'],
                    'log_note' => $logRow['note'],
                    'log_by' => $logRow['by_name'],
                    'gap_km' => null, 'gap_kind' => null, 'gap_since' => null,
                    'gap_from_user' => null, 'gap_to_user' => null, 'gap_user_id' => null,
                    'claims' => [],
                ];
                $opensAt = $ls ?? $le;
                $points[] = ['v' => $opensAt, 'user_id' => $logRow['driver_id'],
                             'keeper' => $logRow['driver'], 'kind' => 'log',
                             'work' => $lWork, 'closes' => $le ?? $ls, 'home' => null,
                             'idx' => count($dayOut) - 1];
            }


            // Attach the day's claims to the first row of that rider, else the first row.
            foreach ($dayClaims as $c) {
                $slot = null;
                foreach ($dayOut as $i => $row) {
                    if ($row['user_id'] === ($c['by_user_id'] ?? null)) { $slot = $i; break; }
                }
                if ($slot === null && $dayOut) $slot = 0;
                if ($slot === null) {
                    $dayOut[] = [
                        'date' => $d, 'user_id' => $c['by_user_id'] ?? null,
                        'keeper' => $c['by_name'] ?? null,
                        'meter_start' => null, 'meter_end' => null, 'meter_home' => null,
                        'start_source' => null, 'work_km' => null, 'home_km' => null,
                        'status' => 'claim_only', 'partial' => false, 'handover_day' => $isBoundary,
                        'gap_km' => null, 'gap_kind' => null, 'gap_since' => null,
                        'gap_from_user' => null, 'gap_to_user' => null, 'gap_user_id' => null,
                        'claims' => [],
                    ];
                    $slot = count($dayOut) - 1;
                }
                $dayOut[$slot]['claims'][] = $c;
            }

            // Handover halves order by odometer: the morning reading is lower.
            usort($points, fn ($a, $b) => $a['v'] <=> $b['v']);

            foreach ($points as $pt) {
                if ($firstSeen === null) $firstSeen = $pt['v'];
                // ---- the stretch BEFORE this point -------------------------
                if ($prev !== null) {
                    $gap = $pt['v'] - $prev;
                    if ($gap < 0) {
                        $dayOut[$pt['idx']]['anomaly'] = 'meter_back';
                    } elseif ($gap > self::MAX_GAP_KM) {
                        $dayOut[$pt['idx']]['anomaly'] = 'implausible';
                    } elseif ($gap > 0) {
                        $made = $this->classifyGap(
                            $gap, $prev, $pt, $prevOwner, $prevDate, $d,
                            $dirty, $isBoundary, $handoverByDate[$d] ?? null, $vehicleId, $isCompany);
                        // Where the reading that ENDED the stretch was taken — the night list
                        // says "meter at home" / "typed at the office" from this.
                        foreach ($made as $mi => $mLeg) {
                            $made[$mi]['to_source'] = $dayOut[$pt['idx']]['start_source'] ?? null;
                        }
                        $legs = array_merge($legs, $made);

                        // Stamp the stretch onto the row it arrived at, so the
                        // machine's day list can render it without re-deriving
                        // anything — one computation, both lenses.
                        $lead = $made[0];
                        $dayOut[$pt['idx']]['gap_km']   = $gap;
                        $dayOut[$pt['idx']]['gap_kind'] = count($made) > 1 ? 'split' : $lead['kind'];
                        $dayOut[$pt['idx']]['gap_since'] = $lead['since'] ?? null;
                        $dayOut[$pt['idx']]['gap_from_user'] = $lead['from_user'] ?? null;
                        $dayOut[$pt['idx']]['gap_to_user']   = $lead['to_user'] ?? null;
                        $dayOut[$pt['idx']]['gap_user_id']   = $lead['user_id'] ?? null;
                        // A stretch across days the machine was not his: charged to nobody.
                        $dayOut[$pt['idx']]['gap_unheld']    = !empty($lead['unheld']);
                    }
                }

                // ---- the day's own riding ---------------------------------
                if ($pt['work'] !== null) {
                    $legs[] = $this->leg('on_duty', $pt['work'], $d, $vehicleId, $pt['user_id']);
                    $totals['days_counted']++;
                }
                if ($pt['home']) {
                    $hTo = $pt['closes'] ?? null;
                    $legs[] = $this->leg('off_duty', $pt['home'], $d, $vehicleId, $pt['user_id'], [
                        'home' => true,
                        'from_meter' => $hTo !== null ? $hTo - $pt['home'] : null,
                        'to_meter' => $hTo, 'to_source' => 'home',
                    ]);
                }

                $close = $pt['closes'] ?? $pt['v'];
                if ($prev === null || $close >= $prev) {
                    $prev = $close; $prevOwner = $pt['user_id']; $prevDate = $d;
                } elseif ($pt['work'] !== null) {
                    // A self-consistent day re-anchors a chain a typo knocked over,
                    // so one bad row poisons only itself (the kmSinceLastFill rule).
                    $prev = $close; $prevOwner = $pt['user_id']; $prevDate = $d;
                }
            }

            // A day worked with no usable reading dirties the NEXT stretch — unless
            // the machine changed hands, where a single reading is the normal shape.
            foreach ($dayOut as $row) {
                if ($row['status'] === 'no_meter' && !$isBoundary) {
                    $dirty = true;
                    $totals['no_meter_days']++;
                } elseif ($row['status'] === 'ok') {
                    $dirty = false;
                }
            }

            foreach ($dayOut as $row) $days[] = $row;
        }

        // --- the tail: last reading of the month → where the next month opens ---
        $closeWin = $vs->meterWindowContinuousFor($vehicleId, Carbon::parse($to)->addDay()->format('Y-m-d'));
        $closesOn = $closeWin['floor'] ?? null;
        if ($prev !== null && $closesOn !== null && $closesOn > $prev
            && ($closesOn - $prev) <= self::MAX_GAP_KM) {
            $legs[] = $this->leg($dirty ? 'unaccounted' : 'off_duty', $closesOn - $prev,
                $to, $vehicleId, $dirty ? null : $prevOwner,
                ['tail' => true, 'from_meter' => $prev, 'to_meter' => $closesOn]);
            $prev = $closesOn;
        }

        foreach ($legs as $l) {
            $totals[$l['kind']] = ($totals[$l['kind']] ?? 0) + $l['km'];
        }
        $totals['total'] = $totals['on_duty'] + $totals['off_duty'] + $totals['shared']
                         + $totals['transfer'] + $totals['unaccounted'];

        $out['legs']   = $legs;
        $out['days']   = $days;
        $out['totals'] = $totals;
        $out['opens_at'] = $win['floor'] ?? ($this->firstReading($days) ?? $firstSeen);
        $out['closes_at'] = $prev;
        $out['span'] = ($out['opens_at'] !== null && $prev !== null && $prev >= $out['opens_at'])
            ? $prev - $out['opens_at'] : null;

        // The honesty check: the legs must equal the odometer's own movement.
        $out['reconciles'] = $out['span'] === null
            ? $totals['total'] === 0
            : $out['span'] === $totals['total'];

        $out['keepers'] = $this->keepersOf($days);
        $out['events']  = $this->handoverEvents($vehicleId, $from, $to, $days);
        $out['day_cards'] = $this->dayCards($days, $legs, $out['events']);
        return $replaced ? $this->showAsTyped($vehicleId, $out) : $out;
    }

    /**
     * Put every DISPLAYED odometer figure of a walked month back as the meter showed it. The
     * kilometres (legs, gaps, totals, span) are differences and are already right; only the
     * readings themselves were lifted onto the continuous scale for the walk.
     */
    private function showAsTyped(int $vehicleId, array $out): array
    {
        $typed = fn ($v) => $v === null ? null : MeterReplacement::toRawByValue($vehicleId, (int) $v);
        foreach (['opens_at', 'closes_at'] as $k) $out[$k] = $typed($out[$k] ?? null);
        foreach ($out['legs'] as $i => $l) {
            foreach (['from_meter', 'to_meter'] as $k) {
                if (array_key_exists($k, $l)) $out['legs'][$i][$k] = $typed($l[$k]);
            }
        }
        foreach ($out['days'] as $i => $d) {
            foreach (['meter_start', 'meter_end', 'meter_home'] as $k) {
                if (array_key_exists($k, $d)) $out['days'][$i][$k] = $typed($d[$k]);
            }
        }
        foreach ($out['day_cards'] as $ci => $card) {
            foreach ($card['lines'] as $li => $line) {
                if (in_array($line['type'] ?? null, ['meter_start', 'meter_end'], true) && isset($line['value'])) {
                    $out['day_cards'][$ci]['lines'][$li]['value'] = $typed($line['value']);
                }
            }
        }
        return $out;
    }

    /**
     * ⭐ THE MANAGER CHANGING THE RIDER, as a visible event.
     *
     * Assignments have always been invisible in the day list — a bike simply had a
     * different name against it the next morning with nothing saying why. These are
     * the "🔁 handover → Waseem · Shabib ne record ki · 22:49" lines.
     *
     * ⚠ `created_at` is when it was RECORDED, which is not always the day it covers
     *   (a backdated correction). The time is offered only when the two agree —
     *   printing 22:49 against a day recorded a week later would be a lie.
     * ⚠ Blips (same-day assign+release that never won a reading) stay hidden here
     *   exactly as they are hidden everywhere else — R-C, owner ruling Q5.
     */
    private function handoverEvents(int $vehicleId, string $from, string $to, array $days): array
    {
        $out = [];
        try {
            $cols = ['a.id', 'a.user_id', 'a.assigned_on', 'a.released_on', 'a.created_at',
                     'a.note', 'u.fullname as rider', 'b.fullname as by_name'];
            if ($this->hasHandoverMeter()) $cols[] = 'a.handover_meter';

            $rows = DB::table(VehicleService::T_ASSIGN . ' as a')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'a.user_id')
                ->leftJoin('t_sys_user as b', 'b.id', '=', 'a.assigned_by')
                ->where('a.vehicle_id', $vehicleId)
                ->whereBetween('a.assigned_on', [$from, $to])
                ->orderBy('a.assigned_on')->orderBy('a.id')
                ->get($cols);

            // Which (rider, date) pairs actually produced something on this machine —
            // a paper shuffle that never carried a reading is not an event.
            $seen = [];
            foreach ($days as $d) {
                if ($d['user_id'] !== null) $seen[$d['user_id'] . '|' . $d['date']] = true;
            }

            foreach ($rows as $r) {
                $date = substr((string) $r->assigned_on, 0, 10);
                $uid  = (int) $r->user_id;
                $blip = $r->released_on
                    && substr((string) $r->released_on, 0, 10) === $date
                    && !isset($seen[$uid . '|' . $date]);
                if ($blip) continue;

                $recordedOn = substr((string) $r->created_at, 0, 10);
                $out[] = [
                    'date'    => $date,
                    'to'      => $r->rider,
                    'to_user' => $uid,
                    'by_name' => $r->by_name,
                    // Only when the record was made on the day it covers.
                    'time'    => $recordedOn === $date ? $this->clock($r->created_at) : null,
                    'recorded_on' => $recordedOn === $date ? null : $recordedOn,
                    'meter'   => isset($r->handover_meter) && $r->handover_meter
                        ? (int) $r->handover_meter : null,
                    'note'    => $r->note ?: null,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('handoverEvents failed', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
            return [];
        }
        return $out;
    }

    /**
     * ⭐⭐ ONE CARD PER DATE — the day read top to bottom, the way it happened
     *     (owner review, Aug-13).
     *
     * The old list printed a separate block per RIDER, so a handover date arrived as
     * two fragments and the manager assembled the story himself. A date is one thing
     * that happened to one machine: it opens on a meter, things occur, it closes on a
     * meter. That is the card.
     *
     * ⭐ LINE ORDER IS DERIVED, NOT ASSUMED. Meters and handovers carry a clock;
     *   claims usually do not — but they carry an ODOMETER, which places them
     *   physically between the open and the close. So a fill at 25,479 lands between
     *   a 25,420 start and a 25,595 close without anyone recording a time for it.
     *   Verified against the real August cards (see test_day_cards.php).
     */
    private function dayCards(array $days, array $legs, array $events): array
    {
        $byDate = [];
        foreach ($days as $d) $byDate[$d['date']][] = $d;
        foreach ($events as $e) {
            if (!isset($byDate[$e['date']])) $byDate[$e['date']] = [];
        }
        ksort($byDate);

        $legsByDate = [];
        foreach ($legs as $l) $legsByDate[$l['date']][] = $l;

        $cards = [];
        foreach ($byDate as $date => $rows) {
            // The day's two anchors, whoever recorded them.
            $open = null; $close = null;
            foreach ($rows as $r) {
                if ($r['meter_start'] !== null && $open === null) {
                    $open = ['value' => $r['meter_start'], 'who' => $r['keeper'],
                             'user_id' => $r['user_id'], 'at' => $r['start_at'] ?? null,
                             'source' => $r['start_source'] ?? null];
                }
                $endVal = $r['meter_end'] ?? null;
                if ($endVal === null && $r['meter_home'] !== null) $endVal = $r['meter_home'];
                if ($endVal !== null) {
                    $close = ['value' => $endVal, 'who' => $r['keeper'],
                              'user_id' => $r['user_id'], 'at' => $r['end_at'] ?? null,
                              // provenance travels with the CLOSE too, so a manager-
                              // logged evening reading is marked as one on the card.
                              'source' => $r['start_source'] ?? null];
                }
            }

            $openPos  = $this->seconds($open['at'] ?? null, 0);
            $closePos = $this->seconds($close['at'] ?? null, 86400);

            $lines = [];

            // 1. the stretch that ARRIVED at this day, before anything else.
            // ⚠⚠ NEVER the shared/split run: `classifyGap` only calls a stretch
            //   'shared' when both its ends fall on THIS date, so that distance is
            //   the day's own run — already the card's headline. Printing it here
            //   too would show the same kilometres twice, which is exactly what the
            //   owner said must not happen.
            foreach ($rows as $r) {
                if (!$r['gap_km']) continue;
                if (in_array($r['gap_kind'], ['shared', 'split'], true)) continue;
                $lines[] = ['pos' => -1, 'rank' => 0, 'type' => 'gap',
                            'kind' => $r['gap_kind'], 'km' => $r['gap_km'],
                            'since' => $r['gap_since'] ?? null,
                            // ⚠ An `unheld` stretch crossed days the machine was not his, so it
                            //   is nobody's — naming him on it would be the accusation removed.
                            'who' => ($r['gap_kind'] === 'off_duty' || $r['gap_kind'] === 'unaccounted')
                                     && empty($r['gap_unheld'])
                                ? $r['keeper'] : null,
                            'unheld' => !empty($r['gap_unheld']),
                            'from' => $this->nameFor($r['gap_from_user'] ?? null, $rows, $events),
                            'to' => $this->nameFor($r['gap_to_user'] ?? null, $rows, $events)];
            }

            if ($open) {
                $lines[] = ['pos' => $openPos, 'rank' => 1, 'type' => 'meter_start'] + $open;
            }

            // 2. claims, placed by where the odometer says they happened
            foreach ($rows as $r) {
                foreach ($r['claims'] as $c) {
                    $lines[] = [
                        'pos'  => $this->claimPos($c['meter_cont'] ?? ($c['meter'] ?? null), $open, $close, $openPos, $closePos),
                        'rank' => 2, 'type' => 'claim',
                        'kind' => $c['kind'], 'amount' => $c['amount'], 'meter' => $c['meter'] ?? null,
                        'who'  => $c['by_name'] ?? null, 'pending' => !empty($c['is_pending']),
                        'id'   => $c['id'] ?? null,
                        // ⭐ the filing clock — see the note on 'at' in claimsForVehicle().
                        'at'   => $c['at'] ?? null,
                        // ⭐ stamped = the machine was RECORDED on the claim; unstamped = it was
                        //   INFERRED from who held what that day, which is exactly how an
                        //   own-bike claim can surface on a company machine's card.
                        'stamped' => !empty($c['stamped']),
                    ];
                }
            }

            // 3. the manager handing the machine over
            foreach ($events as $e) {
                if ($e['date'] !== $date) continue;
                $lines[] = ['pos' => $this->seconds($e['time'], 86400 - 1), 'rank' => 3,
                            'type' => 'handover'] + $e;
            }

            if ($close) {
                $lines[] = ['pos' => $closePos, 'rank' => 4, 'type' => 'meter_end'] + $close;
            }

            usort($lines, fn ($a, $b) => [$a['pos'], $a['rank']] <=> [$b['pos'], $b['rank']]);

            $cards[] = [
                'date'    => $date,
                'summary' => $this->cardSummary($date, $rows, $open, $close, $legsByDate[$date] ?? []),
                'lines'   => array_values($lines),
            ];
        }

        usort($cards, fn ($a, $b) => strcmp($b['date'], $a['date']));   // newest first
        return $cards;
    }

    /**
     * The day's verdict, exactly as a manager reads it: the machine opened on one
     * meter and closed on another, and WHO took those two readings decides whether
     * the distance belongs to one man or to neither.
     */
    private function cardSummary(string $date, array $rows, ?array $open, ?array $close, array $legs): array
    {
        $km = ($open && $close && $close['value'] >= $open['value'])
            ? $close['value'] - $open['value'] : null;

        $split = array_values(array_filter($legs, fn ($l) => !empty($l['from_handover'])));
        if ($split) {
            return ['km' => $km, 'kind' => 'split', 'riders' => array_values(array_unique(
                array_filter(array_map(fn ($l) => $this->nameFor($l['user_id'], $rows, []), $split)))),
                'parts' => array_map(fn ($l) => ['km' => $l['km'],
                    'who' => $this->nameFor($l['user_id'], $rows, [])], $split)];
        }

        if ($open && $close) {
            $differs = $open['user_id'] !== null && $close['user_id'] !== null
                    && $open['user_id'] !== $close['user_id'];

            // ⚠ 'shared' is a CLAIM — that a stretch of road belongs to nobody in
            //   particular. Only say it when the engine actually produced a shared
            //   leg for this date. Two names can also bracket a day whose every
            //   stretch IS attributed (the holder's own pair plus a manager log
            //   stint with a named driver): that day is measured, not shared, and
            //   labelling it shared would un-credit kilometres the log just
            //   credited. Present those as parts, like a handover-metered day.
            if ($differs && !array_filter($legs, fn ($l) => $l['kind'] === 'shared')) {
                $parts = array_values(array_filter($legs,
                    fn ($l) => $l['kind'] === 'on_duty' && $l['user_id'] !== null));
                if ($parts) {
                    return ['km' => $km, 'kind' => 'split', 'riders' => array_values(array_unique(
                        array_filter(array_map(fn ($l) => $this->nameFor($l['user_id'], $rows, []), $parts)))),
                        'parts' => array_map(fn ($l) => ['km' => $l['km'],
                            'who' => $this->nameFor($l['user_id'], $rows, [])], $parts)];
                }
            }

            return ['km' => $km, 'kind' => $differs ? 'shared' : 'on_duty',
                    'riders' => $differs ? [$open['who'], $close['who']] : [$close['who']]];
        }

        // No pair to measure. Say which of the honest reasons it is.
        $status = 'no_meter';
        foreach ($rows as $r) {
            if (in_array($r['status'], ['leave', 'claim_only', 'half', 'in_progress'], true)) {
                $status = $r['status'];
                break;
            }
        }
        return ['km' => null, 'kind' => $status,
                'riders' => array_values(array_unique(array_filter(array_column($rows, 'keeper'))))];
    }

    /**
     * Where in the day a claim sits — interpolated from its odometer between the
     * day's open and close. A fill at 25,250 on a day running 25,248 → 25,380
     * happened almost immediately after the start, and the card shows it there.
     */
    private function claimPos(?int $meter, ?array $open, ?array $close, int $openPos, int $closePos): float
    {
        if ($meter === null || !$open || !$close) return $openPos + 0.5;
        $span = $close['value'] - $open['value'];
        if ($span <= 0) return $openPos + 0.5;
        $frac = ($meter - $open['value']) / $span;
        $frac = max(0, min(1, $frac));
        return $openPos + $frac * ($closePos - $openPos);
    }

    /** 'HH:MM' from a datetime, or null. */
    private function clock($ts): ?string
    {
        if (!$ts) return null;
        $s = (string) $ts;
        return strlen($s) >= 16 ? substr($s, 11, 5) : null;
    }

    private function seconds(?string $hhmm, int $fallback): int
    {
        if (!$hhmm || !str_contains($hhmm, ':')) return $fallback;
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        return $h * 3600 + $m * 60;
    }

    private function nameFor(?int $uid, array $rows, array $events): ?string
    {
        if ($uid === null) return null;
        foreach ($rows as $r) {
            if ($r['user_id'] === $uid) return $r['keeper'];
        }
        foreach ($events as $e) {
            if (($e['to_user'] ?? null) === $uid) return $e['to'];
        }
        return null;
    }

    /** Has SQL-BIKES-HANDOVER-METER-AUG2026 been run? */
    private function hasHandoverMeter(): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        try {
            $ok = \Illuminate\Support\Facades\Schema::hasColumn(VehicleService::T_ASSIGN, 'handover_meter');
        } catch (\Throwable $e) {
            $ok = false;
        }
        return $ok;
    }

    /**
     * WHOSE STRETCH IS THIS? The whole blame question, in one place.
     *
     * Same rider at both ends  → his (off duty, or unaccounted when a day he worked
     *                            went unread and we cannot tell work from commute)
     * Different riders, same day → SHARED — the handover day itself, split unknown
     * Different riders, across   → TRANSFER — the bike moving between them
     *
     * ⭐ A recorded handover odometer turns the unknowable split into two exact
     *    stretches, which is the whole reason the field exists.
     */
    private function classifyGap(int $gap, int $prev, array $pt, ?int $prevOwner,
                                 ?string $prevDate, string $date, bool $dirty,
                                 bool $isBoundary, ?int $handoverMeter, int $vehicleId,
                                 bool $isCompany = true): array
    {
        $owner = $pt['user_id'];
        $meta  = ['since' => ($prevDate !== null && $prevDate !== $date) ? $prevDate : null,
                  // the two readings the stretch runs between — the night list prints them
                  'from_meter' => $prev, 'to_meter' => (int) $pt['v']];

        // Same man both ends — his, PROVIDED the machine was his on the days in between
        // (Sep-30 owner ruling). Across days it was not assigned to him the kilometres are
        // real but belong to nobody: 'unaccounted' with no owner, marked `unheld`.
        if ($prevOwner !== null && $owner !== null && $prevOwner === $owner) {
            // ⚠ Company machines only: an own bike is its owner's whatever the registry says,
            //   and it never carries an off-duty figure anyway.
            if (!$dirty && $isCompany && !$this->heldThroughout($owner, $vehicleId, $prevDate, $date)) {
                return [$this->leg('unaccounted', $gap, $date, $vehicleId, null,
                    $meta + ['unheld' => true, 'unheld_user' => $owner])];
            }
            return [$this->leg($dirty ? 'unaccounted' : 'off_duty', $gap, $date,
                $vehicleId, $dirty ? null : $owner, $meta)];
        }

        // Two riders. Did anyone write down the odometer at the moment of handover?
        if ($handoverMeter !== null && $handoverMeter > $prev && $handoverMeter < $pt['v']
            && $prevOwner !== null && $owner !== null) {
            return [
                $this->leg('on_duty', $handoverMeter - $prev, $date, $vehicleId, $prevOwner,
                    array_merge($meta, ['from_handover' => true, 'to_meter' => $handoverMeter])),
                $this->leg('on_duty', $pt['v'] - $handoverMeter, $date, $vehicleId, $owner,
                    array_merge($meta, ['from_handover' => true, 'from_meter' => $handoverMeter])),
            ];
        }

        // The handover DAY itself: one man's morning, the other's evening.
        if ($prevDate === $date && $prevOwner !== null && $owner !== null) {
            return [$this->leg('shared', $gap, $date, $vehicleId, null,
                $meta + ['from_user' => $prevOwner, 'to_user' => $owner])];
        }

        // The bike travelling between two people — nobody's personal kilometres.
        if ($prevOwner !== null && $owner !== null) {
            return [$this->leg('transfer', $gap, $date, $vehicleId, null,
                $meta + ['from_user' => $prevOwner, 'to_user' => $owner])];
        }

        // ⭐ Sep-30 owner ruling: a stretch is his only when BOTH readings are his. On a company
        //   machine, a stretch that STARTS on a reading nobody is named on (a manager log with
        //   no driver, a claim filed by nobody) is therefore nobody's. The van, 17 → 19 Aug:
        //   148 km from a driverless log reading, charged to Rajab until now.
        // ⚠ Only when that earlier reading is known to exist this month ($prevDate). The
        //   month-opening anchor whose writer could not be found keeps the old answer, so a
        //   failed lookup can never empty someone's month.
        if ($isCompany && $prevOwner === null && $prevDate !== null && $owner !== null
            && self::custodyOn()) {
            return [$this->leg('unaccounted', $gap, $date, $vehicleId, null,
                $meta + ['unheld' => true, 'unheld_user' => $owner, 'unknown_start' => true])];
        }

        // One end unknown (the opening anchor): treat as before.
        return [$this->leg($dirty ? 'unaccounted' : 'off_duty', $gap, $date,
            $vehicleId, $dirty ? null : $owner, $meta)];
    }

    private function leg(string $kind, int $km, string $date, int $vehicleId,
                         ?int $userId, array $meta = []): array
    {
        return array_merge([
            'kind' => $kind, 'km' => $km, 'date' => $date,
            'vehicle_id' => $vehicleId, 'user_id' => $userId,
            'from_user' => null, 'to_user' => null, 'since' => null,
            'from_meter' => null, 'to_meter' => null, 'to_source' => null,
        ], $meta);
    }

    /**
     * A rider-day's reading points.
     *
     * ⭐ A HALF-DAY IS EVIDENCE, NOT A FAILURE. On a handover the outgoing rider
     *   leaves only a morning reading and the incoming one only an evening reading;
     *   the older code required BOTH and therefore threw away exactly the readings
     *   that bracket the shared run.
     *
     * ⚠ A row carrying both readings that disagree with each other (backwards, or
     *   a day beyond MAX_DAY_KM) is a typo and contributes NOTHING — this is what
     *   keeps Arslan's 26,261 → 56,403 row out of the chain.
     */
    private function pointsForRow(array $r): array
    {
        $s = $r['meter_start'];
        $e = $r['meter_end'];
        $h = $r['meter_home'];

        if ($s !== null && $e !== null) {
            if ($e < $s || ($e - $s) > self::MAX_DAY_KM) {
                return ['opens' => null, 'closes' => null, 'work' => null, 'home' => null, 'broken' => true];
            }
            $home = ($h !== null && $h > $e && ($h - $e) <= self::MAX_HOME_KM) ? $h - $e : null;
            return ['opens' => $s, 'closes' => max($e, $home ? $h : $e),
                    'work' => $e - $s, 'home' => $home, 'broken' => false];
        }

        $known = array_values(array_filter([$s, $e, $h], fn ($x) => $x !== null));
        if (!$known) {
            return ['opens' => null, 'closes' => null, 'work' => null, 'home' => null, 'broken' => false];
        }
        return ['opens' => min($known), 'closes' => max($known),
                'work' => null, 'home' => null, 'broken' => false];
    }

    /** Every rider's month, summed from the legs that name him. */
    private function rollUpRiders(array $vehicles, array $names): array
    {
        $riders = [];
        // $vtype ('bike' | 'van') defaults to the bike so an older caller behaves as before.
        $touch = function (&$riders, $uid, $vid, $label, $isCompany, $vtype = 'bike') use ($names) {
            if (!isset($riders[$uid])) {
                $riders[$uid] = [
                    'user_id' => $uid, 'name' => $names[$uid] ?? null,
                    'work_km' => 0, 'offduty_km' => 0, 'shared_km' => 0,
                    'transfer_km' => 0, 'unattributed_km' => 0, 'no_meter_days' => 0,
                    // ⭐ Sep-30: meter rounding (≤ jitterKm) kept apart from off-duty, and the
                    //   off-duty that counts — company machines only.
                    'jitter_km' => 0, 'company_offduty_km' => 0, 'had_company' => false,
                    'machines' => [], 'days' => [],
                ];
            }
            if ($vid !== null && !isset($riders[$uid]['machines'][$vid])) {
                $riders[$uid]['machines'][$vid] = [
                    'vehicle_id' => $vid, 'label' => $label, 'is_company' => $isCompany,
                    // What the machine IS. Every screen drawing this row had only
                    // is_company to go on and used it as a stand-in for "van".
                    'vtype' => $vtype === 'van' ? 'van' : 'bike',
                    'work_km' => 0, 'offduty_km' => 0, 'shared_km' => 0,
                    'transfer_km' => 0, 'unattributed_km' => 0, 'days' => 0,
                    'jitter_km' => 0,
                    'fuel_rs' => 0.0, 'maint_rs' => 0.0,
                    'fuel_pending_rs' => 0.0, 'maint_pending_rs' => 0.0,
                    // ⚠ false = this machine's odometer chain has a hole this month
                    //   (readings from a bike nobody recorded him on). His DUTY km are
                    //   still sound — they come from within-day pairs — but the
                    //   between-days stretches cannot be trusted, and the UI says so
                    //   rather than printing a confident wrong number.
                    'reconciles' => true,
                ];
            }
        };

        foreach ($vehicles as $vid => $v) {
            foreach ($v['legs'] as $l) {
                $map = [
                    'on_duty'     => 'work_km',
                    'off_duty'    => 'offduty_km',
                    'unaccounted' => 'unattributed_km',
                ];
                if (isset($map[$l['kind']]) && $l['user_id'] !== null) {
                    $uid = $l['user_id'];
                    $touch($riders, $uid, $vid, $v['label'], $v['is_company'], $v['vtype'] ?? 'bike');
                    $field = $map[$l['kind']];
                    // ⭐ ≤ 1 km between two of his readings is odometer rounding (owner, 30-Sep).
                    //   Kept in its own bucket so the machine still adds up to its odometer.
                    if ($field === 'offduty_km' && $l['km'] <= self::jitterKm()) $field = 'jitter_km';
                    $riders[$uid][$field] += $l['km'];
                    $riders[$uid]['machines'][$vid][$field] += $l['km'];
                    if ($field === 'offduty_km' && !empty($v['is_company'])) {
                        $riders[$uid]['company_offduty_km'] += $l['km'];
                    }
                    continue;
                }
                // Shared and transfer name BOTH men and are charged to neither.
                if ($l['kind'] === 'shared' || $l['kind'] === 'transfer') {
                    $field = $l['kind'] === 'shared' ? 'shared_km' : 'transfer_km';
                    foreach ([$l['from_user'], $l['to_user']] as $uid) {
                        if ($uid === null) continue;
                        $touch($riders, $uid, $vid, $v['label'], $v['is_company'], $v['vtype'] ?? 'bike');
                        $riders[$uid][$field] += $l['km'];
                        $riders[$uid]['machines'][$vid][$field] += $l['km'];
                    }
                }
            }

            foreach ($v['days'] as $row) {
                $uid = $row['user_id'];
                if ($uid === null) continue;
                $touch($riders, $uid, $vid, $v['label'], $v['is_company'], $v['vtype'] ?? 'bike');
                $riders[$uid]['days'][$row['date']][] = array_merge($row, [
                    'vehicle_id' => $vid, 'vehicle_label' => $v['label'],
                    'is_company' => $v['is_company'],
                ]);
                if ($row['work_km'] !== null) $riders[$uid]['machines'][$vid]['days']++;
                $riders[$uid]['machines'][$vid]['reconciles'] = $v['reconciles'];
                // ⭐ A HANDOVER DAY IS NOT A MISSED READING. `status` is already
                //   'half' on those days (he recorded his end of it; the other man
                //   recorded the other), so counting only 'no_meter' here stops the
                //   drill-down scolding a rider for the three days he did nothing
                //   wrong — the last place that accusation survived.
                if ($row['status'] === 'no_meter') $riders[$uid]['no_meter_days']++;
            }

            // His money on this machine — even on a bike he only borrowed, and even
            // when he never produced a usable reading on it (that is exactly the
            // Farooq case the red banner was complaining about).
            foreach ($v['spend'] as $uid => $s) {
                $touch($riders, $uid, $vid, $v['label'], $v['is_company'], $v['vtype'] ?? 'bike');
                $riders[$uid]['machines'][$vid]['reconciles'] = $v['reconciles'];
                foreach (['fuel_rs', 'maint_rs', 'fuel_pending_rs', 'maint_pending_rs'] as $k) {
                    $riders[$uid]['machines'][$vid][$k] += $s[$k];
                }
            }
        }

        foreach ($riders as &$r) {
            ksort($r['days']);
            $r['reconciles'] = true;
            foreach ($r['machines'] as $m) {
                if (!$m['reconciles']) $r['reconciles'] = false;
                // He had a company machine this month — so he HAS an off-duty figure (maybe 0).
                if (!empty($m['is_company'])) $r['had_company'] = true;
            }
            $r['machines'] = array_values($r['machines']);
            // The bike he did most of his riding on reads as his main one.
            usort($r['machines'], fn ($a, $b) =>
                ($b['work_km'] + $b['shared_km']) <=> ($a['work_km'] + $a['shared_km']));
        }
        unset($r);

        return $riders;
    }

    // =================================================================
    // SMALL HELPERS
    // =================================================================

    /**
     * ⭐ R-B: a reading is anything above zero. Zero is how this system spells
     *   "not recorded" (a `meter_end = 0` row is the known poisoned shape), and
     *   there is no upper or lower bound here on purpose — the CHAIN judges
     *   plausibility, so a genuinely new bike reading 342 km is data, not a typo.
     */
    /** Fuel and maintenance on this machine, per person who filed it. */
    private function spendByUser(array $claims): array
    {
        $out = [];
        foreach ($claims as $c) {
            $uid = $c['by_user_id'] ?? null;
            if ($uid === null) continue;
            if (!isset($out[$uid])) {
                $out[$uid] = ['fuel_rs' => 0.0, 'maint_rs' => 0.0,
                              'fuel_pending_rs' => 0.0, 'maint_pending_rs' => 0.0, 'claims' => 0];
            }
            $isFuel  = $c['category'] === 'Petrol';
            $pending = !empty($c['is_pending']);
            $key = ($isFuel ? 'fuel' : 'maint') . ($pending ? '_pending_rs' : '_rs');
            $out[$uid][$key] += (float) $c['amount'];
            $out[$uid]['claims']++;
        }
        return $out;
    }

    private function spendTotals(array $claims): array
    {
        $t = ['fuel_rs' => 0.0, 'maint_rs' => 0.0, 'fuel_pending_rs' => 0.0,
              'maint_pending_rs' => 0.0, 'claims' => count($claims)];
        foreach ($claims as $c) {
            $key = ($c['category'] === 'Petrol' ? 'fuel' : 'maint')
                 . (!empty($c['is_pending']) ? '_pending_rs' : '_rs');
            $t[$key] += (float) $c['amount'];
        }
        return $t;
    }

    /**
     * ⭐ WAS A READING ACTUALLY DUE ON THIS DAY?
     *
     * The start becomes due once he CHECKS IN, the end once he CHECKS OUT. A rider
     * still on shift has no end meter yet and must never be counted as having missed
     * one — the attendance screen applies exactly this rule, and the two screens have
     * to agree about the same date. (Days left open are surfaced separately as
     * `open_days`, deliberately, so they are visible without being an accusation.)
     */
    private function readingWasDue(array $r): bool
    {
        if ($r['worked'] && $r['meter_start'] === null) return true;
        if (!empty($r['closed']) && $r['meter_end'] === null) return true;
        return false;
    }

    private function reading($v): ?int
    {
        if ($v === null || $v === '') return null;
        $n = (int) $v;
        return $n > 0 ? $n : null;
    }

    /** Dates on which this machine started or ended an assignment. */
    private function boundaryDates(int $vehicleId): array
    {
        $out = [];
        try {
            foreach (DB::table(VehicleService::T_ASSIGN)
                        ->where('vehicle_id', $vehicleId)
                        ->get(['assigned_on', 'released_on']) as $a) {
                $out[substr((string) $a->assigned_on, 0, 10)] = true;
                if ($a->released_on) $out[substr((string) $a->released_on, 0, 10)] = true;
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $out;
    }

    /**
     * The odometer written down at the moment a machine changed hands, as
     * [vehicle_id][date] => meter. Absent column (SQL not yet run) = absent
     * readings = every handover day stays "shared", which is the old behaviour.
     */
    private function handoverMeters(string $from, string $to): array
    {
        $out = [];
        try {
            if (!\Illuminate\Support\Facades\Schema::hasColumn(VehicleService::T_ASSIGN, 'handover_meter')) {
                return [];
            }
            foreach (DB::table(VehicleService::T_ASSIGN)
                        ->whereNotNull('handover_meter')
                        ->where('handover_meter', '>', 0)
                        ->whereBetween('assigned_on', [$from, $to])
                        ->get(['vehicle_id', 'assigned_on', 'handover_meter']) as $a) {
                $out[(int) $a->vehicle_id][substr((string) $a->assigned_on, 0, 10)]
                    = (int) $a->handover_meter;
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $out;
    }

/**
     * ⭐ READINGS ENTERED FROM THE VEHICLES PAGE, as [vehicle_id][date] => row.
     *
     * The machine's own supplementary odometer record: the mid-day van stint, a
     * reading nobody's attendance row could carry, a correction on a machine with no
     * keeper. Vehicle-keyed by construction, so R-A (one machine per rider-day) has
     * no question to answer about them.
     *
     * Absent table (SQL not yet run) = no rows = the engine behaves exactly as it did
     * before this feature existed.
     */
    private function meterLogs(string $from, string $to): array
    {
        $out = [];
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('t_ops_vehicle_meter_log')) {
                return [];
            }
            $rows = DB::table('t_ops_vehicle_meter_log as l')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'l.driver_user_id')
                ->whereBetween('l.log_date', [$from, $to])
                ->get(['l.id', 'l.vehicle_id', 'l.log_date', 'l.meter_start', 'l.meter_end',
                       'l.driver_user_id', 'l.note', 'l.entered_by', 'u.fullname as driver_name']);

            $byIds = [];
            foreach ($rows as $r) {
                if ($r->entered_by) $byIds[(int) $r->entered_by] = true;
            }
            $byNames = $byIds
                ? DB::table('t_sys_user')->whereIn('id', array_keys($byIds))->pluck('fullname', 'id')->toArray()
                : [];

            foreach ($rows as $r) {
                $out[(int) $r->vehicle_id][substr((string) $r->log_date, 0, 10)] = [
                    'id'          => (int) $r->id,
                    'meter_start' => $this->reading($r->meter_start),
                    'meter_end'   => $this->reading($r->meter_end),
                    'driver_id'   => $r->driver_user_id !== null ? (int) $r->driver_user_id : null,
                    'driver'      => $r->driver_name,
                    'note'        => $r->note,
                    'by_name'     => $r->entered_by ? ($byNames[(int) $r->entered_by] ?? null) : null,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('meterLogs failed', ['error' => $e->getMessage()]);
            return [];
        }
        return $out;
    }


    private function firstReading(array $days): ?int
    {
        foreach ($days as $d) {
            foreach (['meter_start', 'meter_end', 'meter_home'] as $k) {
                if ($d[$k] !== null) return $d[$k];
            }
        }
        return null;
    }

    /** Who appeared on this machine this month, in first-seen order. */
    private function keepersOf(array $days): array
    {
        $out = [];
        foreach ($days as $d) {
            if ($d['user_id'] === null || isset($out[$d['user_id']])) continue;
            $out[$d['user_id']] = ['user_id' => $d['user_id'], 'name' => $d['keeper']];
        }
        return array_values($out);
    }

    private function labelFor($v): string
    {
        $reg = trim((string) ($v->reg_no ?? ''));
        if ($reg !== '') return $reg;
        $nick = trim((string) ($v->nickname ?? ''));
        if ($nick !== '') return $nick;
        return ($v->vtype === 'van' ? 'Van #' : 'Vehicle #') . $v->id;
    }
}
