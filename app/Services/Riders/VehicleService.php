<?php

namespace App\Services\Riders;

use App\Models\Riders\MaintenanceTypeModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * The vehicle registry (Aug-2026) — bikes and the van as real, assignable rows.
 *
 * WHY THIS EXISTS
 * A meter reading belongs to a MACHINE, but every rule in the system keys off the
 * RIDER. The moment a rider borrows another bike, the morning continuity check,
 * the odometer window, the service clock and the running-cost figures all misfire
 * at once (Danish on Arslan's bike, Jul-29). This class is the missing entity.
 *
 * ⭐ PHASE A IS DELIBERATELY INERT. Nothing in here is consulted by any meter,
 *    fuel or alert rule yet — those still read the rider, exactly as before, until
 *    `VEHICLE_RULES` is flipped to 'Y' in Phase C. What this class does today is
 *    let the fleet be recorded and assigned, so the data can be checked BEFORE
 *    anything depends on it.
 *
 * ⭐ EVERY PUBLIC METHOD IS SAFE BEFORE THE SQL IS RUN. `available()` gates the
 *    whole class on the table existing, so an upload-before-SQL degrades to "no
 *    Vehicles view" rather than throwing. Same discipline as MaintenanceTypeService.
 *
 * THE ONE BEHAVIOUR-AFFECTING WRITE is the `company_bike` mirror in assign() —
 * see the comment there. It is intended, it is what makes the flag stay true, and
 * previewAssign() exists so the manager is told in words before he commits.
 */
class VehicleService
{
    public const T_VEHICLE = 't_ops_vehicle';
    public const T_ASSIGN  = 't_ops_vehicle_assignment';
    public const T_PHOTO   = 't_ops_vehicle_photo';
    public const T_METER_LOG = 't_ops_vehicle_meter_log';

    /**
     * ⚠ Marker for the R3 closing-meter refusal raised INSIDE assign()'s transaction. A thrown
     *   exception is the only way to roll the handover back from in there; this prefix lets the
     *   catch turn it into the same actionable answer the pre-flight gate returns, instead of a
     *   generic "Could not assign that vehicle."
     */
    private const ERR_CLOSING_METER = '__R3_CLOSING_METER__:';

    /** Readings below this are dropped-digit typos — the bikes here are 5-figure. */
    public const MIN_METER = 1000;

    /**
     * How far a sub-floor reading may sit below what the machine had already read
     * for it to still be the same odometer rather than a dropped digit. Matches
     * MachineAttribution::MAX_GAP_KM — a real 3-figure reading on a genuinely new
     * bike is within a tank-span of its chain; a dropped digit on a 5-figure bike
     * is tens of thousands below it.
     */
    private const LOW_ERA_GAP_KM = 2000;

    /** Per-process memos for the service derivation — see serviceEvidenceByType. */
    private static array $evidenceMemo = [];
    private static array $fullClaimsMemo = [];
    private static array $scheduleMemo = [];
    private static array $meterMemo = [];
    /** [table.column => bool] — see hasCol(). Process-scoped on purpose. */
    private static array $schemaMemo = [];
    /** [vehicleId|date => window] — see meterWindowFor(). Evidence-derived, so it IS flushed. */
    private static array $windowMemo = [];
    private static array $elsewhereMemo = [];
    private static array $dayMapMemo = [];
    /** [vehicleId => bool] — is a three-figure reading the truth for this machine? */
    private static array $lowMileageMemo = [];
    /** [vehicleId => [['m'=>int,'d'=>?string],…]] raw, floor-free readings + dates. */
    private static array $rawReadingsMemo = [];
    /** [vehicleId => [[m,d],…]] machine-KEYED readings (meter log + handover meter). */
    private static array $machineReadMemo = [];
    /** [vehicleId => {hi, days}] attendance readings STAMPED to the machine — see stampedReadings(). */
    private static array $stampedReadMemo = [];
    /**
     * [class => Collection] — the scheduled jobs, PER VEHICLE CLASS (Sep-2026).
     * ⚠ Was a single static shared by the whole fleet; keyed by class now, because a
     *   render that touches a bike and the van must not serve one the other's list.
     */
    private static array $typesMemo = [];
    /** [vehicleId => row] — vtype + is_company, asked once per machine per render. */
    private static array $vehicleRowMemo = [];

    /**
     * How far back the FIRST keeper's history reaches (see attributionWindows).
     * A sentinel, not a real date — it simply means "since before we recorded this".
     */
    public const PRE_REGISTRY_FROM = '2000-01-01';

    /** A day's on-duty distance beyond this is a typo, not a ride. Mirrors meterWindowFor. */
    public const MAX_DAY_KM = 500;

    /** A single unwitnessed stretch beyond this is a typo'd meter, not a distance. */
    public const MAX_GAP_KM = 2000;

    /** The ride home cannot plausibly exceed this. Mirrors MachineAttribution::MAX_HOME_KM. */
    public const MAX_HOME_KM = 700;

    /**
     * ⭐ A MONTH's plausible ceiling — deliberately NOT MAX_GAP_KM (Aug-6 fix).
     *
     * The month figure was capped at 2,000 km, which sounded generous and was
     * simply wrong: Kanan's machine genuinely runs ~4,600 km/month (32,202 km
     * since 1 Jan), so EVERY real month before August failed the cap, the header
     * showed nothing, and the day-by-day view declared a mismatch against a
     * header that wasn't there — for correct data. The owner confirmed the July
     * readings are solid; this cap was the only thing rejecting them.
     *
     * 10,000 ≈ 330 km/day sustained — nothing here has ever approached it, while
     * a dropped or doubled digit still lands far outside. The per-day and
     * per-stretch guards (MAX_DAY_KM, MAX_GAP_KM, SANE_ROW_SQL) stay the typo net
     * INSIDE the month; this only stops rejecting honest totals.
     */
    public const MAX_MONTH_KM = 10000;

    /**
     * ⚠ PLAUSIBLE ROWS ONLY — never a raw MAX/MIN over a meter column.
     * One typo'd row (26,261 → 56,403 in a day) otherwise becomes "current
     * odometer" forever and every service chip reads wildly overdue.
     *
     * This is the canonical copy. Two older copies say the same thing and must
     * stay in step until Phase C repoints them here:
     *   • FuelClaimRules::odometerWindow()
     *   • FleetFuelService::currentMeters()
     */
    public const SANE_ROW_SQL = 'meter_start > 1000
        AND (meter_end  IS NULL OR (meter_end  >= meter_start AND meter_end  - meter_start <= 500))
        AND (meter_home IS NULL OR (meter_home >= meter_start AND meter_home - meter_start <= 700))';

    // =================================================================
    // AVAILABILITY
    // =================================================================

    /** Has batch 13 been run? Cached per process — Schema::hasTable hits information_schema. */
    public function available(): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        try {
            $ok = self::hasTbl(self::T_VEHICLE)
               && self::hasTbl(self::T_ASSIGN)
               && self::hasTbl(self::T_PHOTO);
        } catch (\Throwable $e) {
            $ok = false;
        }
        return $ok;
    }

    /** Is the Phase-C behaviour switch on? Nothing in Phase A reads this yet. */
    public function rulesEnabled(): bool
    {
        return strtoupper((string) $this->cfg('VEHICLE_RULES', 'N')) === 'Y';
    }

    /** Km allowed for the handover ride on a transfer day (owner ruling: ~20). */
    public function transferGraceKm(): float
    {
        return (float) $this->cfg('VEHICLE_TRANSFER_GRACE_KM', 20);
    }

    // =================================================================
    // READS
    // =================================================================

    /**
     * The fleet, each row carrying its current keeper, that keeper's odometer and
     * the service state. Retired vehicles are excluded unless asked for.
     */
    public function all(bool $includeRetired = false): array
    {
        if (!$this->available()) return [];

        try {
            $q = DB::table(self::T_VEHICLE . ' as v')
                ->leftJoin(self::T_ASSIGN . ' as a', function ($j) {
                    $j->on('a.vehicle_id', '=', 'v.id')->whereNull('a.released_on');
                })
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'a.user_id')
                // ⭐ The keeper's home pin, so the list can nag until it is set —
                //    see `needs_home_pin` in shape().
                ->leftJoin('t_ops_rider_profile as p', 'p.user_id', '=', 'a.user_id')
                ->select('v.*', 'a.id as assignment_id', 'a.user_id as keeper_user_id',
                         'a.assigned_on', 'u.fullname as keeper_name',
                         'p.home_latitude as keeper_home_lat', 'p.home_longitude as keeper_home_lng');
            if (!$includeRetired) $q->where('v.is_active', 1);

            $rows = $q->orderByDesc('v.is_active')
                      ->orderByDesc('v.vtype')      // van first, then bikes
                      ->orderByDesc('v.is_company')
                      ->orderByRaw('COALESCE(v.reg_no, v.nickname)')
                      ->get();

            $defaultInterval = (new ServiceIntervalResolver())->companyDefault();
            $photoCounts     = $this->photoCounts();
            $thumbs          = $this->latestPhotoPaths();
            // ⭐ Who last held each idle machine — so the fleet screen can say
            //   "parked, Danish is on DCR-799" about an own bike sitting still,
            //   instead of alarming the manager with "not assigned to anyone".
            $lastKeeper      = $this->lastKeepers();

            $out = [];
            foreach ($rows as $r) {
                $meter = $this->currentMeter((int) $r->id, $r->keeper_user_id ? (int) $r->keeper_user_id : null);
                $shaped = $this->shape($r, $meter, $defaultInterval, (int) ($photoCounts[$r->id] ?? 0));
                $shaped['first_photo_url'] = $this->publicUrl($thumbs[$r->id] ?? null);
                $lk = $lastKeeper[(int) $r->id] ?? null;
                $shaped['last_keeper_user_id'] = $lk['user_id'] ?? null;
                $shaped['last_keeper_name']    = $lk['name'] ?? null;
                $out[] = $shaped;
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('VehicleService::all failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /** One vehicle, with keeper, service state, assignment history and photos. */
    public function find(int $id): ?array
    {
        if (!$this->available()) return null;

        try {
            $r = DB::table(self::T_VEHICLE . ' as v')
                ->leftJoin(self::T_ASSIGN . ' as a', function ($j) {
                    $j->on('a.vehicle_id', '=', 'v.id')->whereNull('a.released_on');
                })
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'a.user_id')
                ->leftJoin('t_ops_rider_profile as p', 'p.user_id', '=', 'a.user_id')
                ->where('v.id', $id)
                ->select('v.*', 'a.id as assignment_id', 'a.user_id as keeper_user_id',
                         'a.assigned_on', 'u.fullname as keeper_name',
                         'p.home_latitude as keeper_home_lat', 'p.home_longitude as keeper_home_lng')
                ->first();
            if (!$r) return null;

            $meter = $this->currentMeter($id, $r->keeper_user_id ? (int) $r->keeper_user_id : null);
            $shaped = $this->shape($r, $meter, (new ServiceIntervalResolver())->companyDefault(),
                                   count($this->photosFor($id)));

            $shaped['history'] = $this->historyFor($id);
            $shaped['photos']  = $this->photosFor($id);
            return $shaped;
        } catch (\Throwable $e) {
            Log::warning('VehicleService::find failed', ['id' => $id, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** The open assignment row for a vehicle, or null when nobody holds it. */
    public function keeperOf(int $vehicleId)
    {
        if (!$this->available()) return null;
        try {
            return DB::table(self::T_ASSIGN)
                ->where('vehicle_id', $vehicleId)->whereNull('released_on')
                ->orderByDesc('id')->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** The vehicle a rider normally has (the mirror column), or null. */
    public function defaultVehicleFor(int $userId): ?int
    {
        if (!$this->available()) return null;
        try {
            $v = DB::table('t_ops_rider_profile')->where('user_id', $userId)->value('default_vehicle_id');
            return $v ? (int) $v : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Who has had this vehicle, newest first. */
    public function historyFor(int $vehicleId): array
    {
        if (!$this->available()) return [];
        try {
            return DB::table(self::T_ASSIGN . ' as a')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'a.user_id')
                ->leftJoin('t_sys_user as ab', 'ab.id', '=', 'a.assigned_by')
                ->where('a.vehicle_id', $vehicleId)
                ->orderByDesc('a.assigned_on')->orderByDesc('a.id')
                ->get(['a.id', 'a.user_id', 'a.assigned_on', 'a.released_on', 'a.note',
                       'u.fullname as rider_name', 'ab.fullname as assigned_by_name'])
                ->map(fn ($r) => [
                    'id'          => (int) $r->id,
                    'user_id'     => (int) $r->user_id,
                    'rider_name'  => $r->rider_name,
                    'assigned_on' => $r->assigned_on ? substr((string) $r->assigned_on, 0, 10) : null,
                    'released_on' => $r->released_on ? substr((string) $r->released_on, 0, 10) : null,
                    'is_current'  => $r->released_on === null,
                    'assigned_by' => $r->assigned_by_name,
                    'note'        => $r->note,
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * ⭐ THE MACHINE'S fuel + maintenance claims for a month — Phase C4 (owner
     *    ruling Aug-4: *"under Vehicles it's showing HIS fuel and maintenance,
     *    it should show the BIKE's — and who requested each one"*).
     *
     * ⚠ WHY THIS IS NOT SIMPLY `WHERE vehicle_id = ?`. The column has only been
     *   stamped since batch 13 (Aug-2026). A hard filter would show an empty
     *   screen for every earlier month and quietly lose the bike's real history.
     *   So two sources are unioned, in this order of trust:
     *     1. rows STAMPED with this vehicle — the fact, frozen at filing;
     *     2. unstamped rows filed by whoever HELD the bike on that claim's date
     *        — the same date-window reconstruction the odometer uses, and the
     *        reason a bike's cost history survives a change of keeper.
     *
     * ⭐ Every row names WHO filed it, so a rider looking at a machine he has just
     *    been given is never confused by a predecessor's claims (they read
     *    "by Waseem"), and never sees his OWN claims for a different bike here.
     *
     * Returns newest first. Read-only; never throws.
     */
    public function claimsForVehicle(int $vehicleId, string $from, string $to): array
    {
        if (!$this->available()) return [];

        try {
            $hasVehicleCol = self::hasCol('t_req_master', 'vehicle_id');
            $hasTypeCol    = self::hasCol('t_req_master', 'maintenance_type_id');

            $cols = ['r.id', 'r.expense_category', 'r.amount', 'r.meter_at_fill', 'r.service_type',
                     'r.status', 'r.expense_date', 'r.created_at', 'r.description',
                     'r.requester_user_id', 'u.fullname as requester_name'];
            if ($hasTypeCol)    $cols[] = 'r.maintenance_type_id';
            if ($hasVehicleCol) $cols[] = 'r.vehicle_id';

            $base = fn () => DB::table('t_req_master as r')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'r.requester_user_id')
                ->whereIn('r.expense_category', ['Petrol', 'Maintenance'])
                ->whereNotIn('r.status', ['cancelled', 'rejected'])
                ->whereRaw('COALESCE(r.expense_date, DATE(r.created_at)) BETWEEN ? AND ?', [$from, $to]);

            $rows = collect();

            // 1. stamped rows — unambiguous
            if ($hasVehicleCol) {
                $rows = $base()->where('r.vehicle_id', $vehicleId)->get($cols);
            }

            $seen = $rows->pluck('id')->flip();

            // ⭐ PHASE D — 1b. unstamped claims on a day the manager gave to THIS
            //   machine. Before the windows, so a borrowed day's fuel is credited to
            //   the bike that burned it even though the rider was outside every
            //   window this machine has.
            //   ⚠ STAMPED rows are never re-pointed: which bike a payment was for is
            //     frozen at filing time, exactly like service_due_km.
            // ...and the mirror of it: a day pointed at a DIFFERENT machine takes its
            // claims with it, or the same fuel would be counted against two bikes.
            $movedAway = [];
            if ($this->hasDayOverride()) {
                foreach (DB::table('t_ops_attendance')
                            ->whereNotNull('vehicle_id')
                            ->where('vehicle_id', '!=', $vehicleId)
                            ->whereBetween('attendance_date', [$from, $to])
                            ->get(['user_id', 'attendance_date']) as $o) {
                    $movedAway[(int) $o->user_id . '|' . substr((string) $o->attendance_date, 0, 10)] = true;
                }
            }

            $overrideDays = [];         // 'userId|date' handled here, skip below
            if ($this->hasDayOverride()) {
                $ov = DB::table('t_ops_attendance')
                    ->where('vehicle_id', $vehicleId)
                    ->whereBetween('attendance_date', [$from, $to])
                    ->get(['user_id', 'attendance_date']);
                foreach ($ov as $o) {
                    $d   = substr((string) $o->attendance_date, 0, 10);
                    $uid = (int) $o->user_id;
                    $overrideDays[$uid . '|' . $d] = true;

                    $q = $base()->where('r.requester_user_id', $uid)
                        ->whereRaw('COALESCE(r.expense_date, DATE(r.created_at)) = ?', [$d]);
                    if ($hasVehicleCol) $q->whereNull('r.vehicle_id');
                    foreach ($q->get($cols) as $r) {
                        if (isset($seen[$r->id])) continue;
                        $seen[$r->id] = true;
                        $r->_assumed = false;         // a manager said so
                        $rows->push($r);
                    }
                }
            }

            // 2. unstamped rows, attributed by who held it on the claim's date
            //    (windows extended backwards for the first keeper — see attributionWindows)
            foreach ($this->attributionWindows($vehicleId) as $w) {
                $q = $base()
                    ->where('r.requester_user_id', $w['user_id'])
                    ->whereRaw('COALESCE(r.expense_date, DATE(r.created_at)) >= ?', [$w['from']])
                    ->when($w['to'], fn ($q2) => $q2->whereRaw(
                        'COALESCE(r.expense_date, DATE(r.created_at)) <= ?', [$w['to']]));
                if ($hasVehicleCol) $q->whereNull('r.vehicle_id');

                foreach ($q->get($cols) as $r) {
                    if (isset($seen[$r->id])) continue;
                    $rDate = substr((string) ($r->expense_date ?: $r->created_at), 0, 10);
                    // That day was given to another machine — its money went with it.
                    if (isset($movedAway[$w['user_id'] . '|' . $rDate])) continue;

                    // ⭐⭐ RULE P ON THE WINDOW PASS — a claim whose odometer cannot belong
                    //    to this machine is NOT this machine's (Aug-28 2026).
                    //
                    // ⚠⚠ THE PROD CASE. CEN-455 was registered on 22-Aug and its first (only)
                    //    keeper is Waseem. `attributionWindows` extends a first keeper's window
                    //    back to PRE_REGISTRY_FROM so pre-registry history is not lost — which
                    //    swept EVERY unstamped claim Waseem had ever filed onto CEN-455,
                    //    including his DCR-799 fills at 24,588-24,822. CEN-455 runs at ~17,2xx.
                    //    The damage was not cosmetic: those readings became the machine's fuel
                    //    chain, so its last "sane" anchor sat at 24,822 and EVERY later fill
                    //    (29-Aug 17,209, 30-Aug 17,286) was judged against it and flagged
                    //    "meter vs last fill doesn't add up" — permanently, because the anchor
                    //    only advances on a plausible delta. It also made the machine's month
                    //    span 17,1xx to 24,9xx and report "a meter reading looks wrong".
                    //
                    // ⚠ ONLY THIS PASS. A STAMPED claim names its machine outright and a
                    //   day-override is a manager's explicit instruction — both are recorded
                    //   facts and are never second-guessed here. This pass is the only one
                    //   that GUESSES, so it is the only one that has to be plausible.
                    //
                    // ⚠ FAILS OPEN twice over: a claim with no odometer cannot be judged and
                    //   is kept, and `readingPlausibleFor` itself accepts everything for a
                    //   machine with no spine of its own — so a brand-new bike still inherits
                    //   its keeper's history exactly as before.
                    if ($r->meter_at_fill !== null && (int) $r->meter_at_fill > 0
                        && !$this->readingPlausibleFor($vehicleId, (int) $r->meter_at_fill, $rDate)) {
                        continue;
                    }

                    $seen[$r->id] = true;
                    // Filed before the registry knew who had this machine: shown, but
                    // labelled as an assumption rather than a recorded fact.
                    $r->_assumed = !empty($w['assumed']) && $rDate < $w['real_from'];
                    $rows->push($r);
                }
            }

            $types = [];
            try {
                if (app(\App\Services\Riders\MaintenanceTypeService::class)->available()) {
                    $types = DB::table('t_fleet_maintenance_types')->pluck('type_name', 'id')->toArray();
                }
            } catch (\Throwable $e) {
                $types = [];
            }

            // ⭐ A bill that pays for several jobs of one visit reads as all of them
            //   (29-Sep-2026) — the same label every claim list prints.
            $combined = [];
            try {
                $combined = app(ServiceRecordService::class)->jobLabelsForClaims(
                    $rows->filter(fn ($r) => $r->expense_category === 'Maintenance')
                         ->map(fn ($r) => (int) $r->id)->values()->all());
            } catch (\Throwable $e) {
                $combined = [];
            }

            $shaped = $rows
                ->sortByDesc(fn ($r) => [substr((string) ($r->expense_date ?: $r->created_at), 0, 10), $r->id])
                ->values()
                ->map(function ($r) use ($types, $hasVehicleCol, $combined) {
                    $kind = $r->expense_category === 'Petrol' ? '⛽ Fuel' : '🔧 Maintenance';
                    if ($r->expense_category === 'Maintenance') {
                        $named = $combined[(int) $r->id] ?? (isset($r->maintenance_type_id) && $r->maintenance_type_id
                            ? ($types[$r->maintenance_type_id] ?? null) : null);
                        if ($named) $kind = '🔧 ' . $named;
                    }
                    return [
                        'id'         => (int) $r->id,
                        'date'       => substr((string) ($r->expense_date ?: $r->created_at), 0, 10),
                        'category'   => $r->expense_category,
                        // For the per-type service schedule — which job this row was.
                        'maintenance_type_id' => isset($r->maintenance_type_id) && $r->maintenance_type_id
                            ? (int) $r->maintenance_type_id : null,
                        // ⭐ The raw machine flag as well as the type id. Every row filed
                        // before Aug-2026 is untyped, and `oil_change`/`general` is the
                        // ONLY thing that identifies those as clock-resetting work —
                        // without it the overall service clock would go blind on exactly
                        // the history it has to read (see overallServiceStateFor).
                        'service_type' => $r->service_type,
                        'kind'       => $kind,
                        'amount'     => (float) $r->amount,
                        'meter'      => $r->meter_at_fill !== null ? (int) $r->meter_at_fill : null,
                        'status'     => (string) $r->status,
                        'is_pending' => $r->status === 'pending',
                        // ⭐ WHEN it was filed (owner request, Aug-22). The day card already
                        //   ORDERS by this — a claim filed at 12:37 sits above a 12:57 handover —
                        //   but never showed it, so a manager could see the sequence and not the
                        //   clock. On a machine that changed hands mid-day the time is the whole
                        //   story: it says which side of the handover a claim belongs to.
                        'at'         => $r->created_at ? substr((string) $r->created_at, 11, 5) : null,
                        // ⭐ who filed it — the confusion-killer
                        'by_user_id' => (int) $r->requester_user_id,
                        'by_name'    => $r->requester_name,
                        // false = attributed by assignment window, not stamped
                        'stamped'    => $hasVehicleCol && !empty($r->vehicle_id),
                        // true = predates the registry; credited to the first keeper
                        'assumed'    => !empty($r->_assumed),
                    ];
                })->all();

            // ⭐ Meter replaced (Oct-2026): `meter` stays the figure as typed (it is shown);
            //   `meter_cont` is the same reading on the machine's continuous scale, for anything
            //   that CHAINS readings (fill-to-fill km, the day walk). Only added for a machine
            //   with a replacement — every other machine's rows are exactly as before.
            if (MeterReplacement::has($vehicleId)) {
                foreach ($shaped as $i => $c) {
                    $shaped[$i]['meter_cont'] = MeterReplacement::toContinuous($vehicleId, $c['meter'], $c['date']);
                }
            }
            return $shaped;
        } catch (\Throwable $e) {
            Log::warning('claimsForVehicle failed', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * ⭐⭐ THE PER-TYPE SERVICE SCHEDULE, KEYED TO THE MACHINE (owner ask, Aug-6).
     *
     * The rider drill-down has shown this for weeks — oil every 1,200, oil+tuning
     * every 2,500, brake shoe every 10,000, each with its own "last done / due in".
     * But it is keyed to the RIDER (`requester_user_id`), which stopped being the
     * right key the day services started travelling with the bike: hand DCR-799 to
     * Danish and his profile says "brake shoe never recorded" while the machine
     * had one 800 km ago under Waseem. The machine's profile is where the schedule
     * belongs, built from the machine's own attributed history.
     *
     * Evidence per type, whichever is furthest along the odometer wins:
     *   • approved Maintenance claims attributed to THIS machine — through
     *     `claimsForVehicle`, so stamped rows, window attribution, manager
     *     day-overrides and the pre-registry backfill all count exactly as the
     *     money view counts them (the two must never disagree about a service);
     *   • manual service-log entries (work recorded outside the claim flow),
     *     attributed by who held the machine on the service date.
     *
     * `due_*` need the machine's CURRENT meter — the caller passes the same one
     * the profile header shows, so "due in 957 km" here always matches up there.
     */
    public function serviceScheduleFor(int $vehicleId, ?int $currentMeter): array
    {
        if (!$this->available()) return [];
        // ⚠ Memoised per (machine, meter). The headline, the panel and the alert
        //   sweep all ask for the same machine at the same odometer within one
        //   render; without this the covers pass and the type lookup ran three
        //   times per bike and turned a 58 ms sheet into a 533 ms one.
        $memoKey = $vehicleId . '|' . ($currentMeter ?? '-');
        if (array_key_exists($memoKey, self::$scheduleMemo)) return self::$scheduleMemo[$memoKey];

        // …and cached ACROSS requests per (machine, meter, evidence-version) — see
        // evidenceVersion() for why each key part is what keeps it correct.
        $cacheKey = 'svc_sched_' . $memoKey . '|' . self::evidenceVersion($vehicleId);
        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) return self::$scheduleMemo[$memoKey] = $cached;
        } catch (\Throwable $e) {
            // cache down = compute fresh
        }

        try {
            /**
             * ⭐⭐ ONLY COMPANY MACHINES ARE ON A SCHEDULE (owner ruling, 10-Sep-2026):
             *    *"own bikes maintenance is not with the company so we aren't tracking
             *    them or setting them either. this is only for company bikes and van."*
             *
             * ⚠ Returning an EMPTY schedule (rather than an "unknown" state) is what
             *   makes every consumer do the right thing without being told: the panel
             *   draws no countdown, `overallServiceStateFor` finds no row to speak for
             *   the headline, and the alert sweep has nothing to raise. One rule, in
             *   one place, and the screens say WHY via `isTracked()`.
             */
            $vRow = $this->vehicleRowFor($vehicleId);
            if (!$this->isTracked($vRow)) return self::$scheduleMemo[$memoKey] = [];

            $class = MaintenanceTypeModel::normaliseClass($vRow->vtype ?? null);
            $types = $this->scheduledTypesFor($class);
            if ($types->isEmpty()) return self::$scheduleMemo[$memoKey] = [];

            $last = $this->coveredServiceEvidence($vehicleId, $types, $class);

            /**
             * ⭐⭐ ONE RESOLVER FOR "HOW OFTEN IS THIS JOB DUE?" (Aug-27 2026).
             *
             * ⚠⚠ WHAT USED TO BE HERE, AND WHY IT HAD TO GO. The per-bike scalar
             *    (`t_ops_vehicle.service_interval_km`) was applied to "whichever type is
             *    the SHORTEST `resets_service_clock = 1`" — a target it INFERRED rather
             *    than being told. That made it migrate: on 22-Aug a manager set Oil
             *    Change to 1,000 and unticked its clock flag, which silently promoted Oil
             *    + Tuning to the target, so AY-4771's 1,200 landed on a job whose own
             *    schedule says 2,000. He had not touched Oil + Tuning. The panel then
             *    read 1,200 while the Record-service prompt on the same page read 2,000,
             *    and the frozen `service_due_km` used a third chain again.
             *
             * ⭐ The comment this replaces argued the override "makes the control work".
             *   It worked in the sense of changing a number — but never the number the
             *   manager was looking at, and never predictably. A control whose effect
             *   moves between jobs when an unrelated checkbox changes is not a working
             *   control.
             *
             * ⭐ Now: the JOB's own schedule wins, and the bike/rider/company figures are
             *   the fallback for jobs that carry none (Misc / Overhauling). Genuine
             *   per-bike-per-job schedules need a row per pair — Phase 2 — and slot in
             *   ahead of everything else inside the resolver, not here.
             *
             * ⚠ Blast radius, measured: exactly ONE row on the whole fleet moves —
             *   AY-4771's Oil + Tuning, 1,200 → 2,000, i.e. back to what the manager
             *   configured. Every other vehicle's override is NULL.
             */
            $resolver = new ServiceIntervalResolver();
            $keeperId = null;
            try {
                $keeperId = (new VehicleResolver())->riderForVehicleDay($vehicleId, date('Y-m-d'));
            } catch (\Throwable $e) {
                $keeperId = null;
            }

            $today = Carbon::today();

            // ⭐ Meter replaced (Oct-2026): the caller's figure is what the meter shows NOW; the
            //   evidence (`m`) is continuous. Count on one scale, show on the bike's.
            $curC = $currentMeter !== null
                ? (int) MeterReplacement::toContinuous($vehicleId, $currentMeter, null) : null;

            $out = [];
            foreach ($types as $t) {
                $l     = $last[(int) $t->id] ?? null;
                $lastM = $l['m'] ?? null;
                $lastD = $l['d'] ?? null;

                // ⭐⭐ ONE call answers class, basis and every override step.
                $r        = $resolver->resolveFor($vehicleId, $class, $t, $keeperId);
                $isTime   = $r['basis'] === MaintenanceTypeModel::BASIS_TIME;
                $interval = (int) ($r['km'] ?? 0);
                // Kept for compatibility: true whenever the number did NOT come from the
                // job's own standard, which is the only case a screen must explain.
                $overridden = !$r['from_type'];

                // A countdown needs BOTH ends; anything less stays null rather
                // than inventing one from a made-up reference (same rule as the
                // rider panel). For a TIME job the two ends are dates, not meters.
                $dueIn = (!$isTime && $lastM !== null && $curC !== null)
                    ? $interval - ($curC - $lastM) : null;

                $dueInDays = null;
                $dueAtDate = null;
                if ($isTime && $r['days'] !== null && $lastD) {
                    try {
                        $due       = Carbon::parse($lastD)->addDays((int) $r['days']);
                        $dueAtDate = $due->format('Y-m-d');
                        // ⚠ Whole days between calendar dates, signed — NOT diffInDays,
                        //   which is absolute and would report an overdue job as early.
                        $dueInDays = $today->diffInDays($due, false);
                    } catch (\Throwable $e) {
                        $dueInDays = null;
                    }
                }

                $state = $isTime
                    ? ServiceIntervalResolver::stateForDays($dueInDays)
                    : ServiceIntervalResolver::stateFor($dueIn);

                $out[] = [
                    'id'          => (int) $t->id,
                    'name'        => $t->type_name,
                    'bucket'      => $t->bucket,
                    // Whether this row may speak for "the bike's service" overall —
                    // read by overallServiceStateFor when it picks the headline.
                    'resets_clock' => !empty($t->resets_service_clock),
                    'interval_km' => $interval,
                    // True when the number did NOT come from this job's own standard —
                    // so a screen can say WHERE it came from instead of just printing it.
                    'interval_overridden' => $overridden,
                    // ⚠ THE CLASS'S standard, not the bike column — this is what the
                    //   "(this vehicle's own schedule)" line is measured against, and
                    //   on a van it must be the van figure or the explanation lies.
                    'type_interval_km'    => (int) ($r['standard_km'] ?? 0),
                    // 'vehicle_job' | 'type' | 'vehicle' | 'rider' | 'company' | 'fallback',
                    // plus the short human label the screens print. Additive keys.
                    'interval_source'     => $r['source'],
                    'interval_source_label' => $r['source_label'],
                    // ── class + basis, Sep-2026 (additive; old clients ignore them) ──
                    'vehicle_class'  => $class,
                    'basis'          => $r['basis'],
                    'interval_days'  => $r['days'],
                    'standard_days'  => $r['standard_days'],
                    // ⭐ ONE flag for "does this job count down at all", whatever the unit.
                    'has_schedule'   => (bool) $r['has'],
                    // ⭐⭐ THE PHRASING IS THE SERVER'S TOO. Six screens compose "every
                    //   N km" and "due in N km" by hand; with two units that is twelve
                    //   places to get wrong, so the sentence ships with the number.
                    'interval_label' => $r['label'],
                    'due_text'       => self::dueText($state, $isTime, $dueIn, $dueInDays),
                    'due_in_days'    => $dueInDays,
                    'due_at_date'    => $dueAtDate,
                    // As it was typed (the old meter's figure if it was done before a replacement).
                    'last_meter'  => $l !== null ? ($l['raw'] ?? $lastM) : null,
                    // Km run since, straight through any replacement — for the headline's `since_km`.
                    'since_km'    => (!$isTime && $lastM !== null && $curC !== null) ? $curC - $lastM : null,
                    'last_at'     => $lastD,
                    'last_by'     => $l['by'] ?? null,
                    'assumed'     => !empty($l['assumed']),
                    // ⭐ Set when this countdown was refreshed by a BIGGER job rather
                    // than by its own (the covers rule). The screens say "covered by
                    // Oil + Tuning" so a manager is never left wondering why a type he
                    // has no record of reads as freshly done.
                    'covered_by'  => $l['covered_by'] ?? null,
                    // On the meter fitted NOW — the number the rider will see come up.
                    'due_at_km'   => (!$isTime && $lastM !== null)
                        ? MeterReplacement::toRaw($vehicleId, $lastM + $interval, null) : null,
                    'due_in_km'   => $dueIn,
                    // ⭐ ONE state rule — alerts fire off this, so no local ternary.
                    'state'       => $state,
                ];
            }
            try {
                Cache::put($cacheKey, $out, 300);
            } catch (\Throwable $e) {
                // uncached is merely slower
            }
            return self::$scheduleMemo[$memoKey] = $out;
        } catch (\Throwable $e) {
            Log::warning('serviceScheduleFor failed', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * ⭐⭐ RAW PER-TYPE EVIDENCE — the machine's furthest-along record of each job.
     *
     * ⚠⚠ THE ODOMETER IS THE TRUTH, NOT THE ORDER OF ENTRY (owner ruling Aug-16).
     *    A km-based schedule can only be measured against kilometres, so the winner
     *    per type is the HIGHEST METER, never the newest row. That single rule is
     *    what makes a late-arriving old claim safe: it is accepted, paid and shown
     *    like any other, but a lower meter cannot pull the due point backwards and
     *    resurrect a service that has already been done.
     *
     * Two sources, treated identically:
     *   • approved Maintenance claims attributed to THIS machine, via
     *     `claimsForVehicle` — so stamped rows, window attribution, manager
     *     day-overrides and the pre-registry backfill all count exactly as the money
     *     view counts them (the two must never disagree about a service);
     *   • manual service-log rows (work recorded with no bill), attributed by who
     *     held the machine on the day of the work.
     *
     * @return array<int, array{m:int,d:?string,by:?string,assumed:bool}> keyed by type id
     */
    private function serviceEvidenceByType(int $vehicleId): array
    {
        // ⚠ MEMOISED PER PROCESS, and it has to be. One fleet render now asks for
        //   this twice per machine (the card's own state, then the alert sweep's
        //   per-type pass), and each miss is a full claim reconstruction. Read-only
        //   derivation inside one request, so the memo cannot serve a stale answer;
        //   `flushServiceMemo()` exists for tests and long-running processes.
        if (array_key_exists($vehicleId, self::$evidenceMemo)) return self::$evidenceMemo[$vehicleId];

        $last = [];
        try {
            /**
             * ⚠⚠ ONE JOB MUST NOT COUNT TWICE (Sep-3). When a manager records a service AND
             *    files its bill in one go, the pair is linked by `t_fleet_service_log.request_id`.
             *    Both halves would otherwise arrive here as independent evidence, and since
             *    beatsEvidence() keeps the HIGHEST meter per type, a one-kilometre disagreement
             *    between them would silently pick a winner with nothing saying they were the
             *    same job. The LOG is the half we keep — it is the record of the work, and it
             *    counts from the moment it is written rather than waiting for approval.
             * ⚠ Schema-guarded: before the link column exists this is an empty set and the
             *   whole thing is a no-op.
             */
            /**
             * ⚠⚠ ONLY A **LIVE** LINK HIDES A CLAIM (review, 3-Sep). This used to hide any
             *    linked claim whatever its status, so a REJECTED bill stayed invisible while
             *    its log still read as billed — money that never cleared, presented as paid.
             *    `liveBillLinks()` counts a link only while the claim is pending or approved,
             *    and it is the ONE reader of that rule, shared with the history list below.
             */
            $billedByLog = ServiceRecordService::liveBillLinks();

            foreach ($this->allClaimsFor($vehicleId) as $c) {
                if (($c['category'] ?? '') !== 'Maintenance') continue;
                if (($c['status'] ?? '') !== 'approved')      continue;
                // ⚠ Its own service log already speaks for this job — see the note above.
                if (isset($c['id']) && isset($billedByLog[(int) $c['id']])) continue;
                if (empty($c['maintenance_type_id']) || $c['meter'] === null) continue;
                // ⚠ The reading's OWN date rides along — its verdict must never
                //   change as the bike ages (see plausibleServiceMeter).
                if (!$this->plausibleServiceMeter((int) $c['meter'], $vehicleId, $c['date'])) continue;
                $tid = (int) $c['maintenance_type_id'];
                // ⭐ `m` is the CONTINUOUS distance (what every comparison and countdown uses);
                //   `raw` is the figure as it was typed, for showing. Equal without a replacement.
                $mc = (int) MeterReplacement::toContinuous($vehicleId, (int) $c['meter'], $c['date']);
                if (self::beatsEvidence($mc, $c['date'], $last[$tid] ?? null)) {
                    $last[$tid] = ['m' => $mc, 'raw' => (int) $c['meter'], 'd' => $c['date'],
                                   'by' => $c['by_name'] ?? null, 'assumed' => !empty($c['assumed'])];
                }
            }

            if (self::hasTbl('t_fleet_service_log')) {
                $resolver = new VehicleResolver();
                foreach (DB::table('t_fleet_service_log as l')
                            ->leftJoin('t_sys_user as u', 'u.id', '=', 'l.user_id')
                            ->whereNotNull('l.maintenance_type_id')
                            ->whereNotNull('l.meter')
                            ->get(array_merge(['l.user_id', 'l.maintenance_type_id', 'l.meter',
                                               'l.service_date', 'u.fullname'],
                                              ServiceRecordService::logVehicleCols('l.'))) as $row) {
                    $d = substr((string) $row->service_date, 0, 10);
                    if (!$this->plausibleServiceMeter((int) $row->meter, $vehicleId, $d)) continue;
                    /**
                     * ⭐⭐ THE STAMP FIRST, THE REGISTRY ONLY FOR OLD ROWS (10-Sep-2026).
                     *    `vehicleForDay` answers "what was he on that day", which stops being
                     *    the same question as "which bike was serviced" the moment the two
                     *    diverge — and a bike going INTO the workshop is exactly when they do,
                     *    because the manager hands him a spare while it is in. See
                     *    `ServiceRecordService::logVehicleOf()`.
                     */
                    if (ServiceRecordService::logVehicleOf($row, $resolver) !== $vehicleId) continue;
                    $tid = (int) $row->maintenance_type_id;
                    $mc = (int) MeterReplacement::toContinuous($vehicleId, (int) $row->meter, $d);
                    if (self::beatsEvidence($mc, $d, $last[$tid] ?? null)) {
                        $last[$tid] = ['m' => $mc, 'raw' => (int) $row->meter, 'd' => $d,
                                       'by' => $row->fullname, 'assumed' => false];
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('serviceEvidenceByType failed', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
        }
        return self::$evidenceMemo[$vehicleId] = $last;
    }

    /**
     * The active types that actually have a countdown, fetched ONCE per process.
     * Every machine asks the same question, so this was one query per bike per
     * surface for a list that changes about twice a year.
     */
    /**
     * ⭐⭐ THE JOBS THAT COUNT DOWN **ON THIS KIND OF MACHINE** (Sep-2026).
     *
     * ⚠⚠ WHAT USED TO BE HERE: `where('interval_km', '>', 0)` against ONE list for
     *    the whole fleet. That single condition is why the van was judged against
     *    bike numbers — "Oil Change every 1,000 km" on a 75,000 km van — and why a
     *    van job could not exist at all. The filter now happens per class, in PHP,
     *    because "does this job apply, and does it have a number here" is a question
     *    about two columns chosen by a third, and SQL would have to repeat that rule.
     *
     * ⚠ Memoised PER CLASS. The old memo was a single static; sharing it across
     *   classes would hand the van the bike list on the second call of a render that
     *   touched both — which is every fleet screen this company has.
     */
    private function scheduledTypesFor(?string $class)
    {
        $class = MaintenanceTypeModel::normaliseClass($class);
        if (isset(self::$typesMemo[$class])) return self::$typesMemo[$class];
        try {
            if (!self::hasTbl('t_fleet_maintenance_types')) return self::$typesMemo[$class] = collect();

            $cols = ['id', 'type_name', 'interval_km', 'bucket', 'resets_service_clock'];
            // Pre-SQL: the extra columns do not exist yet, and every class reads the
            // bike numbers — exactly today's behaviour. See MaintenanceTypeService.
            $aware = app(MaintenanceTypeService::class)->classAware();
            if ($aware) {
                $cols = array_merge($cols, ['applies_to', 'basis', 'interval_km_van',
                                            'interval_days', 'interval_days_van']);
            }

            $rows = DB::table('t_fleet_maintenance_types')
                ->where('is_active', 1)
                ->orderBy('sort_order')->orderBy('type_name')
                ->get($cols);

            $resolver = new ServiceIntervalResolver();
            $rows = $rows->filter(function ($t) use ($class, $resolver, $aware) {
                if (!$aware) return (int) $t->interval_km > 0;
                // A job is on the panel when it applies to this class AND that class
                // has a figure for it. "Applies but no number yet" is the van's day-one
                // state: the job exists and can be billed, it simply has no countdown.
                $r = $resolver->resolveFor(null, $class, $t, null);
                return $r['has'];
            })->values();

            return self::$typesMemo[$class] = $rows;
        } catch (\Throwable $e) {
            return self::$typesMemo[$class] = collect();
        }
    }

    /**
     * ⭐ Is this machine on the COMPANY maintenance schedule at all?
     *
     * Owner ruling, 10-Sep-2026: *"own bikes maintenance is not with the company so
     * we aren't tracking them or setting them either. this is only for company bikes
     * and van."* A rider's personal bike is his own affair — the company neither
     * schedules its services nor nags him about them.
     *
     * ⚠⚠ `is_company` is the RIGHT column here and it is being used for its actual
     *    meaning — "do we own and pay for this machine" — not as a stand-in for
     *    "van", which is the mistake the Sep-4 round had to unpick. Class (`vtype`)
     *    decides WHICH schedule; `is_company` decides WHETHER there is one.
     */
    public function isTracked($vehicleRow): bool
    {
        if (!$vehicleRow) return false;
        return (int) ($vehicleRow->is_company ?? 0) === 1;
    }

    /** The row behind a vehicle id, memoised — class and tracking are asked constantly. */
    private function vehicleRowFor(int $vehicleId)
    {
        if (array_key_exists($vehicleId, self::$vehicleRowMemo)) return self::$vehicleRowMemo[$vehicleId];
        try {
            $r = DB::table(self::T_VEHICLE)->where('id', $vehicleId)
                ->first(['id', 'vtype', 'is_company', 'reg_no', 'nickname']);
        } catch (\Throwable $e) {
            $r = null;
        }
        return self::$vehicleRowMemo[$vehicleId] = $r;
    }

    /** 'bike' | 'van' for a machine the registry knows; 'bike' for anything else. */
    public function classOf(?int $vehicleId): string
    {
        $r = $vehicleId ? $this->vehicleRowFor($vehicleId) : null;
        return MaintenanceTypeModel::normaliseClass($r->vtype ?? null);
    }

    /** Is this machine on the company schedule? Takes an id rather than a row. */
    public function isTrackedId(?int $vehicleId): bool
    {
        return $vehicleId ? $this->isTracked($this->vehicleRowFor($vehicleId)) : false;
    }

    /**
     * ⭐⭐ "due in 341 km" / "12 days overdue" / "never recorded" — composed ONCE, on
     *    the server, and shipped with the row.
     *
     * Six screens (web list, web panel, mobile fleet, mobile vehicles, mobile profile,
     * the alert banner) each built this sentence from raw numbers. With one unit that
     * was six copies of a ternary; with two it would be twelve, and the day a phone
     * printed "due in 90 km" for a 90-DAY job nobody would know which half was wrong.
     * The numbers still ship beside it, so an older APK renders exactly as before.
     */
    public static function dueText(string $state, bool $isTime, ?int $dueInKm, ?int $dueInDays): string
    {
        if ($state === 'unknown') return 'never recorded';

        if ($isTime) {
            if ($dueInDays === null) return 'never recorded';
            if ($dueInDays < 0) {
                $n = abs($dueInDays);
                return number_format($n) . ' day' . ($n === 1 ? '' : 's') . ' overdue';
            }
            if ($dueInDays === 0) return 'due today';
            return 'due in ' . number_format($dueInDays) . ' day' . ($dueInDays === 1 ? '' : 's');
        }

        if ($dueInKm === null) return 'never recorded';
        return $dueInKm < 0
            ? number_format(abs($dueInKm)) . ' km overdue'
            : 'due in ' . number_format($dueInKm) . ' km';
    }

    /**
     * ⚠ THE ONE COMPARISON, so every evidence source is ranked identically.
     *   Higher odometer wins — that is the ruling. On an EQUAL meter the later
     *   date wins: the same physical job recorded twice (a claim on the 4th, the
     *   manual log entry on the 6th) should read as the more recent record rather
     *   than whichever source the loop happened to reach first.
     */
    /**
     * ⚠⚠ A DROPPED-DIGIT READING IS NOT EVIDENCE. Every bike here is 5-figure, so a
     *   service recorded at e.g. 800 km is a typo — and since the HEADLINE is now the
     *   worst scheduled row, one such row would drag a bike's banner (and its push
     *   alert, and the rider's phone) to "18,600 km overdue" forever. The other two
     *   consumers of this rule already guarded it; the per-type gatherer did not,
     *   which quietly widened the blast radius of an old typo. Same MIN_METER the
     *   odometer window and the approval hook use.
     *
     * ⭐⭐ …BUT PLAUSIBILITY IS RELATIVE TO THE MACHINE'S OWN CHAIN, ANCHORED IN TIME
     *    (Aug-2026, MachineAttribution's rule R-B, applied here at last). The bare
     *    floor is right for a 47,000 km bike and catastrophic for a NEW one: EDN-198
     *    has never read above 800, so its approved Rs 1,000 oil change at 659 km was
     *    thrown away by every consumer of this rule — the bike had no service clock,
     *    and the claim's `service_due_km` was never stamped.
     *
     * ⚠⚠ WHY THE DATE, NOT A PER-MACHINE FLAG (review, same day). A dropped digit
     *    means the machine had ALREADY read far above the number when it was written
     *    down — that is a fact about a moment, not about the machine. The first cut
     *    of this fix classified whole machines by their MAXIMUM reading, which put a
     *    cliff at the floor: the week EDN-198's odometer passed 1,000 km, its flag
     *    would flip and the 659 km service — a historical fact — would have been
     *    thrown away all over again, days before its oil change came due. Anchored to
     *    the reading's own date, the verdict on a record can never change afterwards:
     *    the 659 stays valid when the bike reads 15,000, and a three-figure typo
     *    typed on that same bike NEXT year is still refused, because by then the
     *    machine HAD read far above it.
     *
     *    A reading over the floor passes outright, as it always has — the relaxation
     *    only ever examines the sub-floor case, so every 5-figure reading on the
     *    fleet answers bit-for-bit as before. A machine with no raw readings at all
     *    keeps the strict rule: "unknown" must never buy laxity.
     *
     * @param string|null $onDate the reading's own date. Null = judge against the
     *        machine's whole history — the conservative answer, right for callers
     *        with no date, wrong for historical rows (their verdict would drift as
     *        the bike ages), so evidence gatherers must pass the date.
     */
    public function plausibleServiceMeter(?int $meter, ?int $vehicleId = null, ?string $onDate = null): bool
    {
        if ($meter === null || $meter <= 0) return false;
        // ⭐ Meter replaced (Oct-2026): judged on the continuous scale, so 300 km on a NEW meter
        //   is "the old meter's last + 300", not a dropped digit. Identity without a replacement.
        if ($vehicleId !== null) $meter = (int) MeterReplacement::toContinuous($vehicleId, $meter, $onDate);
        if ($meter > self::MIN_METER) return true;
        if ($vehicleId === null) return false;

        $all = $this->rawMachineReadings($vehicleId);
        if (!$all) return false;                    // nothing known → the strict rule

        $d = $onDate ? substr($onDate, 0, 10) : null;

        // Undated evidence (the registry seed) counts as "always known" — it can only
        // make the check STRICTER, never looser.
        $prior = [];
        foreach ($all as $r) {
            if ($d === null || $r['d'] === null || $r['d'] <= $d) $prior[] = $r['m'];
        }
        if ($prior) {
            // The machine had read `max($prior)` by then. A reading a whole tank-span
            // below that is a dropped digit; anything the chain can reach is real.
            return max($prior) - $meter <= self::LOW_ERA_GAP_KM;
        }

        // Nothing on record on or before that date: accept only what plugs onto the
        // FRONT of the chain (a back-dated first reading), never a free-floating one.
        return min(array_column($all, 'm')) - $meter <= self::LOW_ERA_GAP_KM;
    }

    /**
     * ⭐ Is this machine still genuinely below the dropped-digit floor?
     *
     * ⚠ ONE consumer: computeCurrentMeter's query floor, where the max-based answer
     *   is self-correcting — the moment the machine's readings pass the floor, the
     *   floored MAX query finds those same readings, so no cliff exists THERE.
     *   Evidence rules must use `plausibleServiceMeter` with the reading's date
     *   instead; a max-based flag would re-lose old low records as the bike ages.
     */
    public function isLowMileageMachine(int $vehicleId): bool
    {
        if (array_key_exists($vehicleId, self::$lowMileageMemo)) return self::$lowMileageMemo[$vehicleId];

        // No reading at all is "unknown", not "low" — an own bike whose rider files
        // per-km claims has no odometer here and must not be reclassified.
        $all = $this->rawMachineReadings($vehicleId);
        $answer = $all && max(array_column($all, 'm')) <= self::MIN_METER;
        return self::$lowMileageMemo[$vehicleId] = $answer;
    }

    /**
     * Every raw odometer reading the machine has ever produced, with its date —
     * deliberately UNFILTERED by any floor (filtering the evidence by the rule it
     * feeds would be circular). Sources: stamped claims, the manual meter log, and
     * the registry seed. Attendance rows are left out on purpose: they are
     * rider-keyed, and the two rules built on this list only need the machine-keyed
     * spine to say "had this machine read far above X by date D".
     */
    private function rawMachineReadings(int $vehicleId): array
    {
        if (array_key_exists($vehicleId, self::$rawReadingsMemo)) return self::$rawReadingsMemo[$vehicleId];

        $out = [];
        try {
            if (self::hasCol('t_req_master', 'vehicle_id')) {
                foreach (DB::table('t_req_master')
                            ->where('vehicle_id', $vehicleId)
                            ->whereNotNull('meter_at_fill')
                            ->where('meter_at_fill', '>', 0)
                            ->whereNotIn('status', ['cancelled', 'rejected'])
                            ->selectRaw('meter_at_fill AS m, COALESCE(expense_date, DATE(created_at)) AS d')
                            ->get() as $r) {
                    $out[] = ['m' => (int) $r->m, 'd' => $r->d ? substr((string) $r->d, 0, 10) : null];
                }
            }
            if (self::hasTbl(self::T_METER_LOG)) {
                foreach (DB::table(self::T_METER_LOG)->where('vehicle_id', $vehicleId)
                            ->get(['meter_start', 'meter_end', 'log_date']) as $r) {
                    $d = $r->log_date ? substr((string) $r->log_date, 0, 10) : null;
                    foreach ([$r->meter_start, $r->meter_end] as $m) {
                        if ($m !== null && (int) $m > 0) $out[] = ['m' => (int) $m, 'd' => $d];
                    }
                }
            }
            $v = DB::table(self::T_VEHICLE)->where('id', $vehicleId)
                ->first(['last_service_meter', 'last_service_at']);
            if ($v && $v->last_service_meter !== null && (int) $v->last_service_meter > 0) {
                $out[] = ['m' => (int) $v->last_service_meter,
                          'd' => $v->last_service_at ? substr((string) $v->last_service_at, 0, 10) : null];
            }
            // ⭐ Meter replaced (Oct-2026): the list is the machine's CONTINUOUS distance, so a new
            //   meter's first readings sit above the old one's last instead of looking like a
            //   brand-new bike. ⚠ An UNDATED seed predates any replacement — left as typed.
            if (MeterReplacement::has($vehicleId)) {
                foreach ($out as $i => $r) {
                    if ($r['d'] !== null) $out[$i]['m'] = (int) MeterReplacement::toContinuous($vehicleId, $r['m'], $r['d']);
                }
            }
        } catch (\Throwable $e) {
            // Partial evidence is still evidence; empty keeps the strict rule.
        }
        return self::$rawReadingsMemo[$vehicleId] = $out;
    }

    private static function beatsEvidence(int $meter, ?string $date, ?array $incumbent): bool
    {
        if ($incumbent === null) return true;
        if ($meter !== (int) $incumbent['m']) return $meter > (int) $incumbent['m'];
        return (string) $date > (string) ($incumbent['d'] ?? '');
    }

    /**
     * The machine's WHOLE claim history, memoised — the input every service
     * derivation shares. Without this the same reconstruction runs three or four
     * times per vehicle per render.
     */
    private function allClaimsFor(int $vehicleId): array
    {
        if (array_key_exists($vehicleId, self::$fullClaimsMemo)) return self::$fullClaimsMemo[$vehicleId];
        return self::$fullClaimsMemo[$vehicleId]
            = $this->claimsForVehicle($vehicleId, self::PRE_REGISTRY_FROM, date('Y-m-d'));
    }

    /**
     * ⭐ ONE MACHINE'S WHOLE CLAIM HISTORY, for consumers outside this class.
     *
     * Deliberately the SAME memoised list the per-type evidence and
     * `lastServicePointBefore` read. `FleetFuelService` chains a bike's fuel fills
     * from it (Aug-2026); giving it its own reconstruction would let the fuel chip
     * and this bike's cost history disagree about which machine burned a tank, which
     * is the whole class of bug the attribution work exists to end.
     */
    public function claimHistoryFor(int $vehicleId): array
    {
        return $this->allClaimsFor($vehicleId);
    }

    /** Drop the service memos — tests, queue workers, anything long-lived. */
    /**
     * ⭐⭐ `self::hasCol()` IS A DATABASE QUERY, AND IT IS NOT CHEAP (Sep-2026).
     *
     * Every call hits `information_schema` — ~40 ms each on this box. That is fine
     * once; it is not fine inside a loop. `meterWindowFor()` asks twice per
     * assignment window, so one rider's month on the app cost **202 metadata
     * queries ≈ 8 seconds** of a 16-second response, for an answer that cannot
     * change while the request is running.
     *
     * Measured on the replica, rider 95 / August: 2,561 queries → 610, 16.1 s → 2.6 s.
     *
     * ⚠ Deliberately NOT flushed by `flushServiceMemo()`. That method exists for
     *   EVIDENCE going stale mid-request (a reading saved and re-read). The SHAPE of
     *   the schema cannot change inside one request — and if a migration ran in the
     *   middle of one, a stale memo would be the least of it. Process-scoped by
     *   design, exactly like the framework's own schema caching.
     */
    public static function hasCol(string $table, string $column): bool
    {
        $k = $table . '.' . $column;
        if (isset(self::$schemaMemo[$k])) return self::$schemaMemo[$k];
        try {
            return self::$schemaMemo[$k] = Schema::hasColumn($table, $column);
        } catch (\Throwable $e) {
            return self::$schemaMemo[$k] = false;   // absent = "no such column", never a 500
        }
    }

    /** Same reasoning as hasCol(), for table existence. */
    public static function hasTbl(string $table): bool
    {
        if (isset(self::$schemaMemo['@' . $table])) return self::$schemaMemo['@' . $table];
        try {
            return self::$schemaMemo['@' . $table] = Schema::hasTable($table);
        } catch (\Throwable $e) {
            return self::$schemaMemo['@' . $table] = false;
        }
    }

    public static function flushServiceMemo(): void
    {
        self::$evidenceMemo = [];
        self::$lowMileageMemo = [];
        self::$rawReadingsMemo = [];
        self::$fullClaimsMemo = [];
        self::$scheduleMemo = [];
        self::$meterMemo = [];
        self::$windowMemo = [];
        self::$elsewhereMemo = [];
        self::$dayMapMemo = [];
        self::$typesMemo = [];
        self::$vehicleRowMemo = [];
        // ⚠ The machine-keyed reading spine feeds Rule P (`readingPlausibleFor`) and was
        //   missing from this list. It matters now that a reading can be SAVED and then
        //   re-read inside one request (the meter-log writer): judging a fresh reading
        //   against a spine captured before it was written is the stale-evidence bug.
        self::$machineReadMemo = [];
        self::$stampedReadMemo = [];
        MeterReplacement::flush();
    }

    /**
     * ⭐ THE CROSS-REQUEST CACHE VERSION for one machine's service evidence.
     *
     * The in-process memos keep ONE render cheap; this keeps the NEXT render cheap.
     * `MyVehicleController` brief mode exists because the attendance card refetches
     * from thirteen trigger points and was engineered to cost "a couple of cheap
     * reads" — a full claim reconstruction per poke would quietly break that
     * contract. So the derived schedule is cached per (machine, meter, version):
     *
     *   • a NEW METER READING changes the key by itself — the countdown is fresh the
     *     moment the odometer moves, no invalidation needed;
     *   • RECORDING A SERVICE bumps the version (both writers call
     *     `bumpServiceEvidence`), so the just-recorded job is visible immediately;
     *   • anything else that can shift attribution (a handover, a day override) is
     *     rare and bounded by the short TTL.
     */
    private static function evidenceVersion(int $vehicleId): int
    {
        try {
            // ⚠⚠ TWO counters, and the GLOBAL one is not optional.
            //   Per-vehicle covers service EVENTS (a claim approved, a service
            //   recorded). But the derivation also depends on fleet-wide SETTINGS —
            //   a maintenance type's interval, the company default, a per-bike
            //   override saved from the vehicle form. Those change the answer for
            //   machines the writer never names, and without a global counter a
            //   manager edited a schedule and watched the number sit unchanged for
            //   up to the full TTL. Verified: type interval 1,200→1,500 was invisible
            //   to a fresh request; per-bike override 2,500→600 likewise.
            //   One extra cache read (no DB) buys correctness for the whole class.
            $g = (int) (Cache::get('svc_cfg_ver') ?: 0);
            return $g * 1000000 + (int) (Cache::get('svc_ev_ver_' . $vehicleId) ?: 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Fleet-wide settings changed — every machine's cached derivation is suspect.
     * Cheap and blunt on purpose: these edits happen a few times a year, and being
     * wrong about them is exactly the drift this whole change set exists to end.
     */
    public static function bumpServiceConfig(): void
    {
        self::flushServiceMemo();
        try {
            Cache::put('svc_cfg_ver', (int) (Cache::get('svc_cfg_ver') ?: 0) + 1, 86400);
        } catch (\Throwable $e) {
            // losing the bump only means the short TTL does the job instead
        }
    }

    /**
     * Evidence changed for this machine: drop the in-process memos and move the
     * cross-request version so every cached derivation for it is dead. Null vehicle
     * (the writer could not resolve one) still flushes the memos — correctness in
     * THIS request never depends on knowing the machine.
     */
    public static function bumpServiceEvidence(?int $vehicleId): void
    {
        self::flushServiceMemo();
        if (!$vehicleId) return;
        try {
            $key = 'svc_ev_ver_' . $vehicleId;
            Cache::put($key, (int) (Cache::get($key) ?: 0) + 1, 86400);
        } catch (\Throwable $e) {
            // losing the bump only means the short TTL does the job instead
        }
    }

    /**
     * ⭐⭐ THE COVERS RULE (owner ruling, Aug-16).
     *
     * A clock-resetting service also refreshes every clock-resetting type with a
     * SMALLER-OR-EQUAL interval. An Oil + Tuning (2,500 km) necessarily includes the
     * oil change, so it refreshes Oil Change (1,200 km) too; an Oil Change does NOT
     * refresh Oil + Tuning, because no tuning happened.
     *
     * ⭐ WHY: without it the team has to record the same physical job twice to keep
     *    both countdowns honest — which is exactly what they were already doing by
     *    hand (three records for one Aug-6 service). The system now does it for them,
     *    and a job entered once stops producing a phantom "overdue" for the smaller
     *    service it plainly contained.
     *
     * ⚠ NON-RESETTING TYPES ARE NEVER TOUCHED, in either direction. Brake Shoe is
     *   real work on its own 10,000 km cycle; it neither covers nor is covered, so a
     *   big service can never make worn brake shoes look replaced. That is the same
     *   safety rule `resets_service_clock` was created for — see
     *   [[maintenance-types-and-meter-date-rule]].
     *
     * ⚠ The covering record must be FURTHER ALONG to win, so a genuinely more recent
     *   small service still beats an older big one.
     */
    /**
     * ⚠⚠ THE COVERS RULE IS CLASS-AWARE AND BASIS-AWARE (Sep-2026).
     *
     * "A bigger job vouches for a smaller one" is a comparison of two SIZES, so with
     * per-class standards it must compare the sizes **on the machine in front of it**
     * — judging a van's ordering by bike numbers would let the wrong job vouch. And a
     * kilometre job can never vouch for a time-based one (or the reverse): 10,000 km
     * and 90 days are not comparable quantities, and pretending otherwise would mark
     * a countdown fresh on the strength of unrelated work.
     */
    private function coveredServiceEvidence(int $vehicleId, $types, ?string $class = null): array
    {
        $direct = $this->serviceEvidenceByType($vehicleId);
        $out    = $direct;
        $class  = MaintenanceTypeModel::normaliseClass($class ?? $this->classOf($vehicleId));

        try {
            // ⚠⚠ COVERERS ARE LOOKED UP AMONG *ALL* TYPES, not just the active ones.
            //    A retired type's work still happened: if the coverer set were the
            //    (active-only) list being rendered, retiring "Oil + Tuning" would strip
            //    the coverage from every Oil Change whose only evidence was that job —
            //    and a whole fleet could flip to overdue from one admin click. What a
            //    type's retirement means is "stop OFFERING it", never "unremember it".
            $byId = [];
            foreach ($types as $t) $byId[(int) $t->id] = $t;
            try {
                if (self::hasTbl('t_fleet_maintenance_types')) {
                    $cols = ['id', 'type_name', 'interval_km', 'resets_service_clock'];
                    if (app(MaintenanceTypeService::class)->classAware()) {
                        $cols = array_merge($cols, ['applies_to', 'basis', 'interval_km_van',
                                                    'interval_days', 'interval_days_van']);
                    }
                    foreach (DB::table('t_fleet_maintenance_types')->get($cols) as $any) {
                        $byId[(int) $any->id] = $byId[(int) $any->id] ?? $any;
                    }
                }
            } catch (\Throwable $e) {
                // the active set alone is still a usable coverer list
            }

            /**
             * How big is this job ON THIS CLASS, and in what unit? Two jobs are only
             * comparable when the unit matches; `null` size means "no standard here",
             * which can neither cover nor be covered.
             */
            $resolver = new ServiceIntervalResolver();
            $sizeOf = function ($row) use ($resolver, $class): ?array {
                if (!$row) return null;
                $r = $resolver->resolveFor(null, $class, $row, null);
                if ($r['basis'] === MaintenanceTypeModel::BASIS_TIME) {
                    return $r['standard_days'] !== null
                        ? ['basis' => 'time', 'size' => (int) $r['standard_days']] : null;
                }
                return $r['standard_km'] !== null
                    ? ['basis' => 'km', 'size' => (int) $r['standard_km']] : null;
            };

            foreach ($types as $t) {
                $tid = (int) $t->id;
                if (empty($t->resets_service_clock)) continue;   // covered types only
                $tSize = $sizeOf($t);
                if ($tSize === null) continue;

                foreach ($direct as $sid => $ev) {
                    if ($sid === $tid) continue;
                    $s = $byId[$sid] ?? null;
                    if (!$s || empty($s->resets_service_clock)) continue;   // coverers only
                    $sSize = $sizeOf($s);
                    if ($sSize === null) continue;
                    // Same unit only — km never vouches for days, or the reverse.
                    if ($sSize['basis'] !== $tSize['basis']) continue;
                    // A bigger-or-equal scheduled job contains this one.
                    if ($sSize['size'] < $tSize['size']) continue;
                    if (isset($out[$tid]) && $ev['m'] <= $out[$tid]['m']) continue;

                    // Keeps the covering record's own meter/date/by, and names the
                    // job that actually did the work.
                    $out[$tid] = $ev;
                    $out[$tid]['covered_by'] = $s->type_name;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('coveredServiceEvidence failed', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
            return $direct;
        }
        return $out;
    }

    /**
     * ⭐⭐ THE ONE OVERALL SERVICE STATE — what every surface now renders.
     *
     * ⚠⚠ THIS REPLACES READING `t_ops_vehicle.last_service_meter` DIRECTLY, and that
     *    is the whole point. Those columns were seeded once when the registry was
     *    created (Aug-4) and NOTHING has ever updated them: both writers stamp the
     *    RIDER's profile. So the machine row silently froze, and the rider's phone —
     *    the only surface reading it — told Arslan his bike was 233 km overdue while
     *    the web panel and the alerts correctly said "142 km left". Three stores of
     *    one fact, and the stalest one was the one facing the rider.
     *
     *    The cure is to stop storing the answer. This derives it, at read time, from
     *    the machine's own evidence — the same evidence the per-type schedule and the
     *    service alerts already read — so the surfaces cannot drift apart again.
     *
     * ⭐⭐ THE HEADLINE **IS** THE MOST URGENT SCHEDULED JOB — not a separate clock.
     *
     *    This is what finally makes the surfaces agree. A first cut measured the
     *    overall state against the LAST job's interval, and AY-4771 immediately
     *    disagreed with itself: the banner read "ok, due in 874" (Oil + Tuning's
     *    2,500 km) while the panel underneath it — and the service alert — correctly
     *    read "Oil Change overdue by 426". Same bike, same second, two answers: the
     *    very complaint this work exists to end.
     *
     *    "Is this bike due for service?" means "is ANY scheduled job due?", so the
     *    headline now reports the worst row of the per-type panel and names it. The
     *    banner, the panel and the alert are then the same computation read at three
     *    levels of detail, and cannot drift apart by construction.
     *
     * LEGACY FALLBACK (no typed history — most machines until Aug-2026), best
     * (highest meter) wins:
     *   1. legacy UNTYPED approved claims flagged `oil_change`/`general`;
     *   2. the seeded `t_ops_vehicle` columns — demoted from truth to just another
     *      candidate, so a machine with no other history keeps the state it had;
     *   3. the keeper's profile stamp, but ONLY when he actually held THIS machine on
     *      that date (⚠ without that guard a rider's stamp from his previous bike
     *      would leak onto the new one — the exact bike-swap contamination that
     *      [[bike-swap-breaks-meter-rules]] is about).
     *
     * INTERVAL PRECEDENCE for that fallback — the schedule follows the work last
     * done: winning job's type interval → the MACHINE's override → the keeper's
     * legacy profile override → company default.
     *
     * @param  object|null $vehicleRow   the already-fetched t_ops_vehicle row, if any
     * @param  int|null    $keeperUserId the current keeper, for the legacy fallback
     */
    public function overallServiceStateFor(
        int $vehicleId,
        ?int $currentMeter,
        $vehicleRow = null,
        ?int $keeperUserId = null,
        ?int $defaultInterval = null
    ): array {
        $default = $defaultInterval ?? (new ServiceIntervalResolver())->companyDefault();

        // Cached across requests on the same terms as the schedule (same version key,
        // meter in the key) — this is what keeps MyVehicle brief mode a cheap read.
        // Keeper is in the key because the legacy fallback consults his profile.
        // ⚠ `svc_over2_` (29-Sep-2026): the chip's rule changed (every scheduled job, not only
        //   oil services), so a chip cached under the old rule must not outlive the upload.
        $overKey = 'svc_over2_' . $vehicleId . '|' . ($currentMeter ?? '-') . '|'
            . ($keeperUserId ?? '-') . '|' . self::evidenceVersion($vehicleId);
        try {
            $cached = Cache::get($overKey);
            if (is_array($cached)) return $cached;
        } catch (\Throwable $e) {
            // cache down = compute fresh
        }

        // ⭐ The panel's own rows decide the headline. Only clock-resetting types can
        //   speak for "the bike's service" — a brake-shoe job is real work on its own
        //   cycle, but it is not what "next service due" has ever meant, and letting
        //   it drive the headline would make a 10,000 km countdown mask an overdue
        //   oil change. Same rule `resets_service_clock` exists for.
        /**
         * ⭐⭐ THE MOST URGENT JOB, ACROSS TWO UNITS (Sep-2026).
         *
         * With km and time jobs on one machine "which is worse" cannot be a raw
         * comparison — 40 km left and 40 days left are not the same urgency. So:
         * the worse STATE always wins (an overdue job outranks a due-soon one), and
         * within a state the job that is furthest through its own cycle wins. That
         * ratio is unit-free, which is exactly why it is the right tie-break.
         */
        /**
         * ⭐⭐ A PERSONAL BIKE HAS NO COMPANY SERVICE STATE (owner ruling, 10-Sep-2026).
         *
         * ⚠⚠ Returning the legacy `unknown` here was not an option: "unknown" means
         *    *we track this and nobody has recorded a service*, which is a nag, and the
         *    legacy evidence path below would happily have invented a countdown from a
         *    seeded meter. `not_tracked` is a different answer and every screen prints
         *    it as a sentence rather than a warning.
         */
        $trackRow = $vehicleRow ?: $this->vehicleRowFor($vehicleId);
        if (!$this->isTracked($trackRow)) {
            $out = self::untrackedServiceState();
            try {
                Cache::put($overKey, $out, 300);
            } catch (\Throwable $e) {
                // uncached is merely slower
            }
            return $out;
        }

        $worst = null; $worstRank = null;
        $rank  = ['overdue' => 0, 'due_soon' => 1, 'ok' => 2];
        if ($currentMeter !== null) {
            foreach ($this->serviceScheduleFor($vehicleId, $currentMeter) as $t) {
                /**
                 * ⭐⭐ EVERY SCHEDULED JOB SPEAKS FOR THE CHIP (owner, 29-Sep-2026: "what's the
                 *    overall clock, since the bikes get alerts for individual services?").
                 *
                 * ⚠⚠ This used to consider only `resets_clock` jobs — a leftover from the
                 *    one-clock era. With per-job countdowns it made the summary chip and the
                 *    alerts disagree: bike "Oil Change" (12-Sep, un-ticked) alerted as overdue
                 *    while the chip beside it read "641 km left". The chip is now the most urgent
                 *    of exactly the jobs `BikeServiceAlerts` raises — same schedule rows, same
                 *    worst-state-first ranking — so the two can never disagree. A brake job can
                 *    never hide an overdue oil change: overdue always outranks due-soon.
                 * ⚠ `resets_service_clock` keeps ONE meaning, "oil service": a bigger oil service
                 *   covers a smaller one (the covers rule), and the old-era fallback below.
                 */
                if (empty($t['has_schedule'])) continue;
                $isTime = ($t['basis'] ?? 'km') === MaintenanceTypeModel::BASIS_TIME;
                $left   = $isTime ? ($t['due_in_days'] ?? null) : ($t['due_in_km'] ?? null);
                if ($left === null) continue;

                $span = $isTime ? (int) ($t['interval_days'] ?? 0) : (int) ($t['interval_km'] ?? 0);
                // Fraction of the cycle still to run; lower = more urgent. Guarded so a
                // zero span can never divide, and so an overdue job stays negative.
                $frac = $span > 0 ? ($left / $span) : ($left < 0 ? -1 : 0);
                $r    = [$rank[$t['state']] ?? 3, $frac];

                if ($worst === null || $r < $worstRank) { $worst = $t; $worstRank = $r; }
            }
        }
        if ($worst !== null) {
            $wIsTime = ($worst['basis'] ?? 'km') === MaintenanceTypeModel::BASIS_TIME;
            $out = [
                'interval_km'        => $worst['interval_km'],
                'last_service_meter' => $worst['last_meter'],
                'last_service_at'    => $worst['last_at'],
                'last_service_by'    => $worst['last_by'],
                // ⚠ Only meaningful against a meter — a time job's "since" is days, and
                //   it rides along beside rather than overwriting this.
                'since_km'           => array_key_exists('since_km', $worst)
                                          ? $worst['since_km']
                                          : ($worst['last_meter'] !== null ? $currentMeter - $worst['last_meter'] : null),
                'due_in_km'          => $worst['due_in_km'],
                'due_at_km'          => $worst['due_at_km'],
                'state'              => $worst['state'],
                // ⭐ WHICH job is the soonest due — so the banner can say "Oil Change
                //   due in 341 km" instead of an unattributed number the rider cannot
                //   act on. Older APKs simply ignore the extra key.
                'due_type_id'        => $worst['id'],
                'due_type_name'      => $worst['name'],
                'covered_by'         => $worst['covered_by'],
                'source'             => 'schedule',
                // ── class + basis, Sep-2026 ──────────────────────────────────
                'vehicle_class'      => $worst['vehicle_class'] ?? null,
                'basis'              => $worst['basis'] ?? MaintenanceTypeModel::BASIS_KM,
                'interval_days'      => $worst['interval_days'] ?? null,
                'due_in_days'        => $worst['due_in_days'] ?? null,
                'due_at_date'        => $worst['due_at_date'] ?? null,
                'interval_label'     => $worst['interval_label'] ?? null,
                'due_text'           => $worst['due_text'] ?? null,
                'is_time_based'      => $wIsTime,
                'tracked'            => true,
            ];
            try {
                Cache::put($overKey, $out, 300);
            } catch (\Throwable $e) {
                // uncached is merely slower
            }
            return $out;
        }

        $best = null;   // ['m' => meter, 'd' => date, 'by' => name, 'interval' => ?int, 'source' => string]
        $consider = function (?int $meter, ?string $date, ?string $by, ?int $interval, string $source) use (&$best) {
            if ($meter === null || $meter <= self::MIN_METER) return;
            // Highest meter wins; a tie goes to the newer record.
            if ($best !== null && ($meter < $best['m']
                || ($meter === $best['m'] && (string) $date <= (string) $best['d']))) return;
            $best = ['m' => $meter, 'd' => $date, 'by' => $by, 'interval' => $interval, 'source' => $source];
        };

        try {
            // 1. Typed clock-resetting work on this machine.
            $types = [];
            try {
                if (self::hasTbl('t_fleet_maintenance_types')) {
                    $types = DB::table('t_fleet_maintenance_types')
                        ->get(['id', 'type_name', 'interval_km', 'resets_service_clock'])
                        ->keyBy('id')->all();
                }
            } catch (\Throwable $e) {
                $types = [];
            }

            foreach ($this->serviceEvidenceByType($vehicleId) as $tid => $ev) {
                $t = $types[$tid] ?? null;
                if (!$t || empty($t->resets_service_clock)) continue;
                $consider($ev['m'], $ev['d'], $ev['by'],
                          (int) $t->interval_km > 0 ? (int) $t->interval_km : null,
                          'type:' . $t->type_name);
            }

            // 2. Legacy untyped oil changes — the whole history before Aug-2026.
            foreach ($this->allClaimsFor($vehicleId) as $c) {
                if (($c['category'] ?? '') !== 'Maintenance') continue;
                if (($c['status'] ?? '') !== 'approved')      continue;
                if (!empty($c['maintenance_type_id']))        continue;   // handled above
                if ($c['meter'] === null)                     continue;
                if (!in_array($c['service_type'] ?? null, ['oil_change', 'general'], true)) continue;
                $consider((int) MeterReplacement::toContinuous($vehicleId, (int) $c['meter'], $c['date']),
                          $c['date'], $c['by_name'] ?? null, null, 'legacy');
            }

            // 3. The seeded machine row.
            if ($vehicleRow === null) {
                try {
                    $vehicleRow = DB::table(self::T_VEHICLE)->where('id', $vehicleId)->first();
                } catch (\Throwable $e) {
                    $vehicleRow = null;
                }
            }
            if ($vehicleRow && $vehicleRow->last_service_meter !== null) {
                $seedD = $vehicleRow->last_service_at ? substr((string) $vehicleRow->last_service_at, 0, 10) : null;
                // An undated seed predates any replacement — it is already on the continuous scale.
                $consider($seedD !== null
                              ? (int) MeterReplacement::toContinuous($vehicleId, (int) $vehicleRow->last_service_meter, $seedD)
                              : (int) $vehicleRow->last_service_meter,
                          $seedD, null, null, 'vehicle_seed');
            }

            // 4. The keeper's profile stamp — only if he held THIS machine that day.
            if ($keeperUserId) {
                try {
                    $p = DB::table('t_ops_rider_profile')->where('user_id', $keeperUserId)
                        ->first(['last_service_meter', 'last_service_at', 'service_interval_km']);
                    if ($p && $p->last_service_meter !== null) {
                        $d = $p->last_service_at ? substr((string) $p->last_service_at, 0, 10) : null;
                        $heldIt = $d === null
                            || (new VehicleResolver())->vehicleForDay($keeperUserId, $d) === $vehicleId;
                        if ($heldIt) {
                            $consider($d !== null
                                          ? (int) MeterReplacement::toContinuous($vehicleId, (int) $p->last_service_meter, $d)
                                          : (int) $p->last_service_meter,
                                      $d, null, null, 'keeper_profile');
                        }
                    }
                } catch (\Throwable $e) {
                    // a missing profile is not an error — the machine simply has less evidence
                }
            }
        } catch (\Throwable $e) {
            Log::warning('overallServiceStateFor failed', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
        }

        // Interval: the winning job's schedule, then the MACHINE's own, then the
        // keeper's legacy one, then the company default.
        // ⭐ Aug-27 2026: this chain was already the RIGHT one — it is now the SHARED one,
        //   so it cannot drift from the schedule list, the alerts or the frozen record.
        $interval = (new ServiceIntervalResolver())
            ->intervalFor($vehicleId, (int) ($best['interval'] ?? 0), $keeperUserId);
        if ($interval <= 0) $interval = $default;

        // ⭐ Meter replaced (Oct-2026): `$last` is continuous; count on that scale and show each
        //   figure as the meter of its own day read. All identity without a replacement.
        $last  = $best['m'] ?? null;
        $curC  = $currentMeter !== null ? (int) MeterReplacement::toContinuous($vehicleId, $currentMeter, null) : null;
        $since = ($curC !== null && $last !== null) ? $curC - $last : null;
        $dueIn = ($since !== null && $interval > 0) ? $interval - $since : null;

        $out = [
            'interval_km'        => $interval,
            'last_service_meter' => ($last !== null && ($best['d'] ?? null) !== null)
                                        ? MeterReplacement::toRaw($vehicleId, $last, $best['d']) : $last,
            'last_service_at'    => $best['d'] ?? null,
            'last_service_by'    => $best['by'] ?? null,
            'since_km'           => $since,
            'due_in_km'          => $dueIn,
            'due_at_km'          => $last !== null ? MeterReplacement::toRaw($vehicleId, $last + $interval, null) : null,
            'state'              => ServiceIntervalResolver::stateFor($dueIn),
            // Same keys as the scheduled branch — a caller must never have to check
            // which path produced its payload. Null here means "no named job".
            'due_type_id'        => null,
            'due_type_name'      => null,
            'covered_by'         => null,
            // Which evidence won — for diagnosis, never rendered as a headline.
            'source'             => $best['source'] ?? null,
            'vehicle_class'      => $this->classOf($vehicleId),
            'basis'              => MaintenanceTypeModel::BASIS_KM,
            'due_in_days'        => null,
            'due_at_date'        => null,
            'is_time_based'      => false,
            'tracked'            => true,
            'due_text'           => self::dueText(ServiceIntervalResolver::stateFor($dueIn),
                                                  false, $dueIn, null),
        ];
        try {
            Cache::put($overKey, $out, 300);
        } catch (\Throwable $e) {
            // uncached is merely slower
        }
        return $out;
    }

    /**
     * The headline for a machine the company does not maintain. Same shape as every
     * other branch — a caller must never have to know which path produced its payload.
     */
    public static function untrackedServiceState(): array
    {
        return [
            'interval_km'        => 0,
            'last_service_meter' => null,
            'last_service_at'    => null,
            'last_service_by'    => null,
            'since_km'           => null,
            'due_in_km'          => null,
            'due_at_km'          => null,
            'state'              => 'not_tracked',
            'due_type_id'        => null,
            'due_type_name'      => null,
            'covered_by'         => null,
            'source'             => 'not_tracked',
            'vehicle_class'      => null,
            'basis'              => MaintenanceTypeModel::BASIS_KM,
            'interval_days'      => null,
            'due_in_days'        => null,
            'due_at_date'        => null,
            'interval_label'     => null,
            'is_time_based'      => false,
            'tracked'            => false,
            // 🗣 Manager-facing furniture, so English (the Sep-3 copy rule).
            'due_text'           => 'personal vehicle — not on the company schedule',
        ];
    }

    /**
     * The machine's current odometer — the public door to the memoised reconstruction.
     *
     * ⚠ EXISTS SO NOBODY MEASURES A MACHINE'S CLOCK WITH A RIDER'S METER. The two are
     *   not the same number: a rider's highest reading spans every bike he has ever
     *   held, while this is window-scoped to the days THIS machine was actually his.
     *   Feeding the rider's figure into a machine's service state is precisely the
     *   category of mistake this whole change set exists to remove.
     */
    public function currentMeterFor(int $vehicleId): ?int
    {
        return $this->currentMeter($vehicleId, null);
    }

    /**
     * ⭐ THE MACHINE'S PREVIOUS SERVICE POINT, STRICTLY BELOW A GIVEN ODOMETER.
     *
     * Used when a service is recorded, to freeze "how far off schedule was the bike
     * when this was done" (`service_due_km`) — a permanent figure on the claim that
     * can never be recomputed once the clock moves past it.
     *
     * ⚠ STRICTLY BELOW is what makes it correct AND what makes a late-filed old claim
     *   safe. The row being stamped is already approved by the time this runs, so it
     *   is its own evidence; measuring against everything under its meter excludes
     *   itself, excludes its same-meter twin (the manual log entry for the same
     *   physical job), and — crucially — excludes any NEWER service that has happened
     *   since. A claim filed late is therefore judged against the state of the bike
     *   as it was at ITS OWN meter, which is the only honest reading.
     *
     * ⚠⚠ THE COVERS RULE APPLIES HERE TOO (caught in review, Aug-16). "When was this
     *   job last done" may only count records that could have INCLUDED this job —
     *   the job itself, or a bigger one (interval ≥ the claim's own type). Without
     *   the filter, an Oil + Tuning being stamped would count a mere Oil Change as
     *   its previous point and record a tuning done 500 km LATE as done 1,500 km
     *   EARLY — permanently, since `service_due_km` is frozen. Untyped legacy rows
     *   and the seeded machine value are plain oil services from the one-clock era:
     *   they count only when the claim's own interval fits inside the company
     *   default those rows lived under. `$claimTypeInterval = null` (an untyped
     *   claim from an old APK) keeps the old behaviour exactly.
     *
     * @return array{meter:int,date:?string,interval:?int}|null
     */
    public function lastServicePointBefore(int $vehicleId, int $meter, ?int $claimTypeInterval = null,
                                           ?string $onDate = null): ?array
    {
        if (!$this->available()) return null;
        try {
            // ⭐ Meter replaced (Oct-2026): "strictly below" is asked on the machine's continuous
            //   scale (`$onDate` = the day `$meter` was read; null = the meter fitted now). The
            //   point is handed back in the CALLER's scale, so `$meter - point['meter']` is the
            //   true distance between the two services whichever side of a replacement each
            //   falls on. `$shift` is 0 — and all of this a no-op — without a replacement.
            $rawMeter = $meter;
            $meter    = (int) MeterReplacement::toContinuous($vehicleId, $meter, $onDate);
            $shift    = $meter - $rawMeter;
            $types = [];
            try {
                if (self::hasTbl('t_fleet_maintenance_types')) {
                    $types = DB::table('t_fleet_maintenance_types')
                        ->get(['id', 'interval_km', 'resets_service_clock'])->keyBy('id')->all();
                }
            } catch (\Throwable $e) {
                $types = [];
            }

            // A typed record counts only if its job could have included the one being
            // stamped (its interval covers the claim's own).
            $typedCovers = function ($t) use ($claimTypeInterval): bool {
                if (!$t || empty($t->resets_service_clock)) return false;
                return $claimTypeInterval === null || (int) $t->interval_km >= $claimTypeInterval;
            };
            // Untyped/seeded rows are plain oil services from the one-clock era; they
            // can only vouch for jobs on (or inside) that old default schedule.
            // ⚠ `$typedCovers` above deliberately keeps comparing the TYPES' own standard
            //   schedules: "could job A have included job B" is a question about the two
            //   jobs, not about this bike, and making it bike-dependent would let an
            //   override rewrite historical evidence. Only the company-default literal
            //   moves to the shared resolver, so there is one source for that number.
            $legacyCovers = $claimTypeInterval === null
                || $claimTypeInterval <= (new ServiceIntervalResolver())->companyDefault();

            $best = null;
            // ⚠ Same machine-relative, DATE-ANCHORED floor as the per-type evidence —
            //   a new bike's three-figure service is a real reference point, and it
            //   must STAY one however far the bike later runs.
            $consider = function (int $m, ?string $d, ?int $interval) use ($meter, $vehicleId, &$best) {
                if (!$this->plausibleServiceMeter($m, $vehicleId, $d)) return;
                if ($d !== null) $m = (int) MeterReplacement::toContinuous($vehicleId, $m, $d);
                if ($m >= $meter) return;
                if ($best !== null && ($m < $best['meter']
                    || ($m === $best['meter'] && (string) $d <= (string) $best['date']))) return;
                $best = ['meter' => $m, 'date' => $d, 'interval' => $interval];
            };

            foreach ($this->allClaimsFor($vehicleId) as $c) {
                if (($c['category'] ?? '') !== 'Maintenance') continue;
                if (($c['status'] ?? '') !== 'approved')      continue;
                if ($c['meter'] === null)                     continue;
                $tid = $c['maintenance_type_id'] ?? null;
                if ($tid) {
                    $t = $types[$tid] ?? null;
                    if (!$typedCovers($t)) continue;
                    $consider((int) $c['meter'], $c['date'], (int) $t->interval_km ?: null);
                } elseif ($legacyCovers
                    && in_array($c['service_type'] ?? null, ['oil_change', 'general'], true)) {
                    $consider((int) $c['meter'], $c['date'], null);
                }
            }

            if (self::hasTbl('t_fleet_service_log')) {
                $resolver = new VehicleResolver();
                foreach (DB::table('t_fleet_service_log')
                            ->whereNotNull('maintenance_type_id')->whereNotNull('meter')
                            ->get(array_merge(['user_id', 'maintenance_type_id', 'meter', 'service_date'],
                                              ServiceRecordService::logVehicleCols())) as $row) {
                    $t = $types[(int) $row->maintenance_type_id] ?? null;
                    if (!$typedCovers($t)) continue;
                    $d = substr((string) $row->service_date, 0, 10);
                    // ⭐ Stamp first — same rule as the per-type evidence above.
                    if (ServiceRecordService::logVehicleOf($row, $resolver) !== $vehicleId) continue;
                    $consider((int) $row->meter, $d, (int) $t->interval_km ?: null);
                }
            }

            // The seeded machine row is a legitimate previous point for the FIRST
            // typed service after the registry was created — same legacy scope.
            if ($legacyCovers) {
                try {
                    $v = DB::table(self::T_VEHICLE)->where('id', $vehicleId)
                        ->first(['last_service_meter', 'last_service_at']);
                    if ($v && $v->last_service_meter !== null) {
                        $consider((int) $v->last_service_meter,
                                  $v->last_service_at ? substr((string) $v->last_service_at, 0, 10) : null, null);
                    }
                } catch (\Throwable $e) {
                    // no row is not an error
                }
            }

            if ($best !== null && $shift !== 0) $best['meter'] -= $shift;
            return $best;
        } catch (\Throwable $e) {
            Log::warning('lastServicePointBefore failed', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * ⭐ WHAT IS ALREADY ON RECORD FOR THIS MACHINE (owner ask, Aug-16).
     *
     * "When they add a maintenance request, show the last entry as well, so they
     * know what they are entering and what is already there, when, and by whom."
     *
     * Returned to every filing form through ONE endpoint (`VehicleController::forUser`),
     * which all four surfaces — the web modal, the manager's mobile form, the rider's
     * own form and the store form — already call to name the bike. So the context
     * arrives with the label they are all showing anyway, and no screen needs its own
     * lookup that could disagree with the others.
     *
     * Read-only, self-limiting, and never throws: this is a hint on a form.
     */
    public function lastMaintenanceFor(int $vehicleId, int $limit = 3): array
    {
        // current_meter present on every path — a consumer must never have to
        // distinguish "failed" from "absent key".
        $out = ['recent' => [], 'per_type' => [], 'overall' => null, 'current_meter' => null];
        if (!$this->available()) return $out;

        try {
            $meter = $this->currentMeter($vehicleId, null);

            // The last few maintenance claims on this machine, whoever filed them —
            // pending ones included, because "somebody already filed this yesterday"
            // is exactly the duplicate this panel exists to prevent.
            $rows = array_values(array_filter(
                $this->allClaimsFor($vehicleId),
                fn ($c) => ($c['category'] ?? '') === 'Maintenance'
            ));
            $out['recent'] = array_slice(array_map(fn ($c) => [
                'id'         => $c['id'],
                'date'       => $c['date'],
                'kind'       => $c['kind'],
                'amount'     => $c['amount'],
                'meter'      => $c['meter'],
                'status'     => $c['status'],
                'is_pending' => $c['is_pending'],
                'by_name'    => $c['by_name'],
            ], $rows), 0, $limit);

            // Per-type "last done / due in", the same figures the schedule panel shows.
            foreach ($this->serviceScheduleFor($vehicleId, $meter) as $t) {
                $out['per_type'][] = [
                    'id'          => $t['id'],
                    'name'        => $t['name'],
                    'interval_km' => $t['interval_km'],
                    'last_meter'  => $t['last_meter'],
                    'last_at'     => $t['last_at'],
                    'last_by'     => $t['last_by'],
                    'covered_by'  => $t['covered_by'],
                    'due_in_km'   => $t['due_in_km'],
                    'state'       => $t['state'],
                    // ── class + basis, Sep-2026 (additive). This payload feeds the
                    //   claim-form panel on three phone screens, which composed "due in
                    //   N km" from `due_in_km` — a time-based job arrived as null and
                    //   read "never recorded". The server's sentence rides along now.
                    'basis'          => $t['basis'] ?? 'km',
                    'due_in_days'    => $t['due_in_days'] ?? null,
                    'interval_label' => $t['interval_label'] ?? null,
                    'due_text'       => $t['due_text'] ?? null,
                    'has_schedule'   => $t['has_schedule'] ?? ((int) $t['interval_km'] > 0),
                ];
            }

            // ⚠ SAME ARGUMENTS AS shape() — keeper included (review find #5). This
            //   payload sits in the very response whose `vehicle.service` came
            //   through shape(); computing one with the keeper and one without lets
            //   the two disagree in the legacy-fallback case, on a single screen.
            $keeper = $this->keeperOf($vehicleId);
            $out['overall'] = $this->overallServiceStateFor(
                $vehicleId, $meter, null,
                $keeper && $keeper->user_id ? (int) $keeper->user_id : null
            );
            $out['current_meter'] = $meter;
        } catch (\Throwable $e) {
            Log::warning('lastMaintenanceFor failed', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
        }
        return $out;
    }

    /** Dated condition photos, newest first. */
    public function photosFor(int $vehicleId): array
    {
        if (!$this->available()) return [];
        try {
            return DB::table(self::T_PHOTO . ' as p')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'p.uploaded_by')
                ->where('p.vehicle_id', $vehicleId)
                ->orderByDesc('p.taken_on')->orderByDesc('p.id')
                ->get(['p.id', 'p.photo_path', 'p.taken_on', 'p.context', 'p.note',
                       'p.assignment_id', 'u.fullname as by_name'])
                ->map(fn ($r) => [
                    'id'        => (int) $r->id,
                    'url'       => $this->publicUrl($r->photo_path),
                    'taken_on'  => $r->taken_on ? substr((string) $r->taken_on, 0, 10) : null,
                    'context'   => (string) $r->context,
                    'label'     => self::CONTEXT_LABELS[$r->context] ?? 'Condition',
                    'note'      => $r->note,
                    'by_name'   => $r->by_name,
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public const CONTEXT_LABELS = [
        'handover_in'  => '🔑 Given to rider',
        'handover_out' => '↩️ Returned',
        'condition'    => '📷 Condition',
        'service'      => '🔧 Service',
        'damage'       => '⚠️ Damage',
    ];

    // =================================================================
    // WRITES — vehicles
    // =================================================================

    /**
     * Create or update a machine. Returns ['ok'=>bool,'message'=>?string,'id'=>?int].
     * reg_no is normalised (upper, single hyphen) so "ay 4771" and "AY-4771" cannot
     * become two vehicles; it stays NULLable because the van has no plate yet.
     */
    public function saveVehicle(array $data, ?int $id, ?int $actorId = null): array
    {
        if (!$this->available()) return $this->fail('Vehicles are not set up yet (SQL batch 13).');

        $reg      = $this->normaliseReg($data['reg_no'] ?? null);
        $nickname = trim((string) ($data['nickname'] ?? '')) ?: null;

        if ($reg === null && $nickname === null) {
            return $this->fail('Give the vehicle a plate number or a name.');
        }

        try {
            if ($reg !== null) {
                // ⚠⚠ THE ASYMMETRY THAT WASTED A MANAGER'S AFTERNOON (Aug-22 2026).
                //    This check sees EVERY vehicle; the fleet list only shows `is_active = 1`
                //    (retired ones hide inside a collapsed "N retired" block). So a machine saved
                //    with "In service" unticked vanishes from the page, and trying to add it again
                //    only said "there is already a vehicle with plate X" — true, unhelpful, and
                //    with no hint of WHERE it went. The state has to be in the message.
                $clash = DB::table(self::T_VEHICLE)->where('reg_no', $reg)
                    ->when($id, fn ($q) => $q->where('id', '!=', $id))
                    ->first(['id', 'is_active']);
                if ($clash) {
                    return $this->fail((int) $clash->is_active === 1
                        ? 'There is already a vehicle with plate ' . $reg . '.'
                        : 'Plate ' . $reg . ' already exists but is RETIRED, so it is hidden from the '
                          . 'list — open the "retired" section at the bottom of Parked & spare and '
                          . 'reactivate it (Edit → tick "In service") instead of adding it again.');
                }
            }

            $row = [
                'vtype'      => in_array($data['vtype'] ?? 'bike', ['bike', 'van'], true) ? $data['vtype'] : 'bike',
                'reg_no'     => $reg,
                'nickname'   => $nickname,
                'is_company' => !empty($data['is_company']) ? 1 : 0,
                'make_model' => trim((string) ($data['make_model'] ?? '')) ?: null,
                'notes'      => trim((string) ($data['notes'] ?? '')) ?: null,
                'is_active'  => array_key_exists('is_active', $data) ? (!empty($data['is_active']) ? 1 : 0) : 1,
                'updated_by' => $actorId,
                'updated_at' => now(),
            ];

            // 0 / null means "follow the company default" — never wipe an existing
            // per-vehicle interval by omitting the field.
            if (array_key_exists('service_interval_km', $data)) {
                $iv = (int) ($data['service_interval_km'] ?? 0);
                $row['service_interval_km'] = $iv > 0 ? $iv : null;
            }

            if ($id) {
                $exists = DB::table(self::T_VEHICLE)->where('id', $id)->exists();
                if (!$exists) return $this->fail('That vehicle no longer exists.');
                DB::table(self::T_VEHICLE)->where('id', $id)->update($row);
            } else {
                $row['created_by'] = $actorId;
                $row['created_at'] = now();
                $id = (int) DB::table(self::T_VEHICLE)->insertGetId($row);
            }

            // ⭐ RECLASSIFYING THE MACHINE MOVES ITS KEEPER TOO. Ticking "company
            //   vehicle" on a bike somebody is already riding changes his arrangement
            //   just as surely as handing him a different bike — so his checkbox
            //   follows here as well, or it would be correct only for machines that
            //   never change category.
            try {
                $keeper = $this->keeperOf((int) $id);
                if ($keeper && $keeper->user_id) {
                    VehicleResolver::flush();
                    $this->syncCompanyBikeFlag((int) $keeper->user_id);
                }
            } catch (\Throwable $e) {
                // never fail a vehicle edit over the denormalised flag
            }

            return ['ok' => true, 'message' => null, 'id' => (int) $id];
        } catch (\Throwable $e) {
            Log::error('VehicleService::saveVehicle failed', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->fail('Could not save that vehicle.');
        }
    }

    /**
     * Set (or clear) the vehicle's FIXED overnight base.
     *
     * Only a machine that sleeps somewhere of its own needs this — the van parks
     * away from the office. A bike left NULL keeps today's behaviour exactly:
     * it sleeps wherever its keeper does, measured against that rider's own home
     * pin. Clearing the base restores that.
     */
    public function setBase(int $vehicleId, ?float $lat, ?float $lng, ?int $radiusM, ?int $actorId = null): array
    {
        if (!$this->available()) return $this->fail('Vehicles are not set up yet (SQL batch 13).');

        $clearing = ($lat === null || $lng === null);
        if (!$clearing && (abs($lat) > 90 || abs($lng) > 180 || ($lat == 0 && $lng == 0))) {
            return $this->fail('That does not look like a valid location.');
        }

        try {
            DB::table(self::T_VEHICLE)->where('id', $vehicleId)->update([
                'base_latitude'  => $clearing ? null : $lat,
                'base_longitude' => $clearing ? null : $lng,
                'base_radius_m'  => $clearing ? null : (($radiusM && $radiusM > 0) ? $radiusM : null),
                'base_set_by'    => $clearing ? null : $actorId,
                'base_set_at'    => $clearing ? null : now(),
                'updated_by'     => $actorId,
                'updated_at'     => now(),
            ]);
            return ['ok' => true, 'message' => $clearing
                ? 'Base cleared — this vehicle now follows its rider\'s home.'
                : 'Base location saved.', 'id' => $vehicleId];
        } catch (\Throwable $e) {
            Log::error('VehicleService::setBase failed', ['id' => $vehicleId, 'error' => $e->getMessage()]);
            return $this->fail('Could not save the base location.');
        }
    }

    // =================================================================
    // WRITES — assignment
    // =================================================================

    /**
     * What WILL happen if this vehicle is given to this rider — computed without
     * writing anything, so the modal can state the consequences in words before
     * the manager commits. Returns a list of plain sentences.
     */
    public function previewAssign(int $vehicleId, int $userId, ?string $onDate = null): array
    {
        if (!$this->available()) return ['ok' => false, 'lines' => [], 'warnings' => []];

        $date  = $this->safeDate($onDate);
        $lines = [];
        $warn  = [];

        try {
            $v = DB::table(self::T_VEHICLE)->where('id', $vehicleId)->first();
            if (!$v) return ['ok' => false, 'lines' => [], 'warnings' => ['That vehicle no longer exists.']];

            $name     = $this->displayName($v);
            $rider    = DB::table('t_sys_user')->where('id', $userId)->value('fullname') ?: ('user ' . $userId);
            $profile  = DB::table('t_ops_rider_profile')->where('user_id', $userId)->first();
            $current  = $this->keeperOf($vehicleId);

            if ($current && (int) $current->user_id === $userId) {
                return ['ok' => true, 'lines' => [$name . ' is already with ' . $rider . '.'], 'warnings' => [],
                        'no_change' => true];
            }

            $lines[] = 'From ' . $date . ', ' . $name . '\'s meter readings and service record follow ' . $rider . '.';

            if ($current) {
                $prev = DB::table('t_sys_user')->where('id', $current->user_id)->value('fullname') ?: 'the current rider';
                $lines[] = $prev . ' is released from ' . $name . ' on the same date.';
            }

            // The rider's existing machine, if any — he can only have one default.
            $his = $this->openAssignmentForUser($userId);
            if ($his && (int) $his->vehicle_id !== $vehicleId) {
                $hisV = DB::table(self::T_VEHICLE)->where('id', $his->vehicle_id)->first();
                if ($hisV) {
                    $lines[] = $rider . ' is released from ' . $this->displayName($hisV)
                             . ', which becomes unassigned.';
                }
            }

            /**
             * ⭐⭐ R3 — WHICH METERS THIS MOVE REQUIRES, decided by the SERVER (13-Sep-2026).
             *
             * Every surface (web fleet modal, store-mode sheet, the rider's Bike badlein) reads
             * THIS rather than working it out from `is_company` in its own JavaScript. One
             * answer, so a field can never be optional on one screen and compulsory on another —
             * which is precisely how the van left Rajab's hands twice with no reading.
             */
            $meters = [];
            if ($this->needsOpeningMeter($vehicleId)) {
                $meters[] = ['field' => 'handover_meter', 'vehicle_id' => $vehicleId,
                             'label' => $name, 'which' => 'opening',
                             'prompt' => $name . ' — meter reading as it is handed over'];
            }
            if ($his && (int) $his->vehicle_id !== $vehicleId
                && $this->needsClosingMeter((int) $his->vehicle_id)) {
                $vName = $this->displayName(DB::table(self::T_VEHICLE)->where('id', $his->vehicle_id)->first());
                $meters[] = ['field' => 'vacated_meter', 'vehicle_id' => (int) $his->vehicle_id,
                             'label' => $vName, 'which' => 'closing',
                             'prompt' => $vName . ' — meter reading as ' . $rider . ' hands it back'];
            }

            // Transfer-day allowance — only says anything when there is a previous
            // holder, because that is the only case with a handover ride.
            if ($current) {
                $lines[] = 'The handover ride on ' . $date . ' is allowed up to '
                         . rtrim(rtrim(number_format($this->transferGraceKm(), 1), '0'), '.')
                         . ' km — it is recorded as transfer travel, not personal use.';
            }

            // What changes, and — just as importantly — what does not change YET.
            // Saying "his fuel rules change now" while VEHICLE_RULES is still off
            // would be a plain lie to the manager pressing the button.
            if ($profile) {
                $was = (int) ($profile->company_bike ?? 0);
                $now = (int) $v->is_company;
                if ($was !== $now) {
                    $what = $now === 1
                        ? $rider . ' counts as riding a COMPANY vehicle: the company buys his fuel, and a '
                          . 'meter reading is required on his fuel and service claims'
                        : $rider . ' counts as riding his OWN vehicle, so the company-bike fuel rules '
                          . 'do not apply to him';

                    $warn[] = $this->rulesEnabled()
                        ? ucfirst($what) . '.'
                        : 'Once vehicle rules are switched on, ' . $what . '. Until then his fuel and meter '
                          . 'rules are unchanged — this assignment is recorded, not yet enforced.';
                }

                // Overnight checks need a pin somewhere. Silence here is the trap
                // the plan called out — a company bike with no pin quietly has no
                // overnight accountability at all.
                $hasBase = $v->base_latitude !== null && $v->base_longitude !== null;
                $hasPin  = $profile->home_latitude !== null && $profile->home_longitude !== null;
                if ((int) $v->is_company === 1 && !$hasBase && !$hasPin) {
                    $warn[] = $rider . ' has no home location set, and ' . $name . ' has no base of its own — '
                            . 'so the overnight and morning meter checks will have nowhere to measure against. '
                            . 'Set his home pin on the Riders page, or give the vehicle a base.';
                }
            } else {
                $warn[] = $rider . ' has no rider profile, so meter tracking will not apply to him.';
            }

            // ⭐ PHASE D — everything the "and what about him?" question needs.
            //   Silence here is what used to leave a displaced rider in limbo, so
            //   the modal always asks when somebody is losing this machine.
            $displaced = null;
            if ($current) {
                $dUser = (int) $current->user_id;
                $displaced = [
                    'user_id'    => $dUser,
                    'name'       => DB::table('t_sys_user')->where('id', $dUser)->value('fullname') ?: 'the current rider',
                    'own'        => $this->ownVehicleFor($dUser),
                    // ⚠ COMPANY machines only. His own bike is its own option above,
                    //   and another man's personal bike must never be one quick pick
                    //   away — that exact slip put Waseem on "Danish - own bike" for
                    //   85 minutes on 7 Aug (prod).
                    'spare'      => array_values(array_filter($this->spareVehicles(),
                                        fn ($s) => $s['id'] !== $vehicleId && $s['is_company'])),
                    'goes_quiet' => $this->rulesEnabled(),
                ];
            }

            return ['ok' => true, 'lines' => $lines, 'warnings' => $warn, 'no_change' => false,
                    'displaced' => $displaced,
                    // R3 — the boxes this move REQUIRES. Empty on an own-bike move.
                    'meters_required' => $meters];
        } catch (\Throwable $e) {
            Log::warning('VehicleService::previewAssign failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'lines' => [], 'warnings' => ['Could not work out the consequences.']];
        }
    }

    /**
     * Give a vehicle to a rider.
     *
     * Order matters and is all inside one transaction:
     *   1. same rider already holds it        → no-op (idempotent; a double-tap
     *                                            must not create a second row)
     *   2. release the vehicle's current keeper on this date
     *   3. release the rider from whatever he was holding (one default per rider —
     *      the mirror column can only carry one id)
     *   4. open the new assignment row
     *   5. point the rider's profile at it (`default_vehicle_id`)
     *
     * ⭐ WHAT THIS DELIBERATELY DOES **NOT** TOUCH: `t_ops_rider_profile.company_bike`.
     *
     *    That flag is read by a dozen LIVE call sites — FuelClaimRules::ridesCompanyBike,
     *    HomeJourneyService::riderHomePin (which arms the whole overnight meter flow),
     *    WorkJourneyService::workIssues, FleetFuelService, the attendance surfaces.
     *    Writing it from an assignment would mean pressing "Assign" silently changes
     *    whose fuel the company buys, who is asked for a meter, and whose overnight
     *    checks arm — while `VEHICLE_RULES` is still OFF and this phase is supposed to
     *    record the fleet, not govern it.
     *
     *    It is also unfixable in the release direction: when a rider hands a bike back
     *    he holds nothing, and BOTH answers are wrong. Leaving it at 1 keeps the per-km
     *    petrol block lifted for someone with no company bike (a money risk, since
     *    isPerKmRider() short-circuits on this flag); forcing it to 0 disarms the
     *    overnight flow for a rider who may simply have had his bike in the workshop
     *    for a day. There is no safe automatic answer, which is the tell that the
     *    write does not belong here.
     *
     *    Phase C settles it properly: the rules read the VEHICLE through
     *    VehicleResolver, `company_bike` stops being consulted, and no mirror is
     *    needed. Until then the flag stays exactly as the owner set it, and
     *    previewAssign() says plainly that fuel treatment changes only once vehicle
     *    rules are switched on.
     */
    /**
     * ⭐⭐ R3 — A COMPANY MACHINE NEVER CHANGES HANDS WITHOUT ITS ODOMETER.
     *
     * ⚠⚠ THE DAY THIS EXISTS FOR (Rajab, 13-Sep-2026, PRODUCTION). He took the van at 14:10 on a
     *    request carrying `meter: null`, and handed it back at 17:46 on another request carrying
     *    `meter: null`. Both were approved. The van therefore had NO rider-recorded reading at
     *    either end of its day, and Shabib hand-typed both into the Vehicles page at 17:47/17:48.
     *    Worse: because the app gave him no door for the van's opening reading, Rajab used the
     *    only meter box he could see — the attendance START tile — which overwrote his own bike's
     *    morning reading with the van's 75,484. One missing gate, three separate symptoms.
     *
     * ⭐ ONE DEFINITION, asked by every door (web fleet, store-mode fleet, the rider's Bike
     *   badlein, the handover approval, and the checkout prompt). A door may present the refusal
     *   however it likes; none of them can decide the RULE.
     *
     * ⚠ Own (non-company) machines are unaffected — a man's own odometer is his business and the
     *   firm buys no fuel for it.
     * ⚠ Only demanded when we can actually STORE it: the opening needs the `handover_meter`
     *   column, the closing needs the meter-log table. Demanding a number we would then drop is
     *   a block with no remedy.
     */
    public function isCompanyMachine(?int $vehicleId): bool
    {
        if (!$vehicleId) return false;
        try {
            $v = DB::table(self::T_VEHICLE)->where('id', $vehicleId)->first(['is_company']);
            return $v ? ((int) $v->is_company === 1) : false;
        } catch (\Throwable $e) {
            return false;      // cannot tell ⇒ never block a handover
        }
    }

    /** R3 for the machine being HANDED TO someone: is its opening reading compulsory? */
    public function needsOpeningMeter(?int $vehicleId): bool
    {
        return $this->isCompanyMachine($vehicleId) && $this->hasHandoverMeter();
    }

    /** R3 for the machine LEAVING someone's hands: is its closing reading compulsory? */
    public function needsClosingMeter(?int $vehicleId): bool
    {
        return $this->isCompanyMachine($vehicleId) && self::hasTbl(self::T_METER_LOG);
    }

    /** The one refusal sentence, so every surface says the same thing. */
    public function meterRequiredMessage(int $vehicleId, string $which): string
    {
        $name = $this->displayName(DB::table(self::T_VEHICLE)->where('id', $vehicleId)->first());
        return $which === 'closing'
            ? $name . ' is a company machine — record its meter reading as it is handed back.'
            : $name . ' is a company machine — record its meter reading as it is handed over.';
    }

    /**
     * ⭐⭐ THE OPENING READING OF A MACHINE ENTERING A RIDER'S HANDS, into ITS OWN meter log.
     *
     * ⚠⚠ THE GAP THIS CLOSES (owner ruling, 13-Sep-2026). The two ends of a machine's day were
     *    written into two different drawers: the OPENING reading onto the assignment row
     *    (`handover_meter`) and the CLOSING reading into this log. Nothing joined them, so a van
     *    picked up mid-day read `meter_start: NULL, meter_end: 75,484` — an end with no start,
     *    and its kilometres counted nowhere. Measured on the replica before this existed:
     *
     *        vehicle 4 (the van)   start=NULL   end=75484   km=NULL
     *
     *    `handover_meter` still carries the per-rider split (that is what makes each man's own
     *    lens exact, and `MachineAttribution` merges it); this makes the MACHINE's own day whole.
     *
     * ⚠ NEVER overwrites a start that is already there. The first reading of the day is the day's
     *   start — a second rider picking the van up at lunchtime is not the morning. He adds
     *   nothing here (his number already equals the first man's close); his stint is the
     *   assignment row, and the close is what widens this row. Same `uq_vehicle_day` row, same
     *   "this is the machine's day" reading as `recordClosingMeter` — see its block.
     *
     * ⚠ Non-fatal by construction, exactly like the closing write: a handover that physically
     *   happened must never fail because a log row would not save.
     */
    public function recordOpeningMeter(int $vehicleId, string $date, int $meter,
                                       int $driverId, ?int $actorId, ?string $note = null): array
    {
        try {
            if (!self::hasTbl(self::T_METER_LOG)) {
                return ['ok' => false, 'message' => 'Meter logging is not set up on this server yet.'];
            }
            $date = substr($date, 0, 10);

            $row = DB::table(self::T_METER_LOG)
                ->where('vehicle_id', $vehicleId)->where('log_date', $date)
                ->first(['meter_start', 'meter_end', 'driver_user_id', 'note']);

            // The day already has its first reading — this is a later stint, which the close
            // will record. Nothing to add, and nothing may be overwritten.
            if ($row && $row->meter_start !== null) {
                return ['ok' => true, 'message' => null, 'skipped' => 'start already recorded'];
            }

            $rowDriver = $row && $row->driver_user_id !== null ? (int) $row->driver_user_id : null;
            $shared    = $rowDriver !== null && $rowDriver !== $driverId;
            $end       = $row && $row->meter_end !== null ? (int) $row->meter_end : null;

            $saved = $this->saveMeterLog(
                $vehicleId, $date, $meter, $end,
                $shared ? null : $driverId,
                $shared ? 'Two or more riders on this machine today'
                        : ($row && $row->note ? $row->note : ($note ?: 'Handed over')),
                $actorId ?: $driverId
            );
            return ['ok' => (bool) ($saved['ok'] ?? false), 'message' => $saved['message'] ?? null];
        } catch (\Throwable $e) {
            Log::warning('opening meter not recorded', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not record the opening reading.'];
        }
    }

    /**
     * ⭐⭐ THE CLOSING READING OF A MACHINE LEAVING A RIDER'S HANDS, into ITS OWN meter log.
     *
     * ⚠ MOVED HERE from VehicleHandoverRequestService (13-Sep-2026) because it is needed by FOUR
     *   doors, not one: the rider's hand-back request, the web fleet "Take back", the store-mode
     *   release, and `assign()`'s step 3 (a rider stepping off machine A onto machine B — which
     *   is how Rajab gave the van back at 17:46, and the path that recorded nothing).
     *
     * ⚠ Preserves an existing start on that row: `saveMeterLog` writes the row wholesale, so
     *   passing null for a start somebody recorded this morning would erase it.
     *
     * ⭐⭐ TWO RIDERS, ONE VAN, ONE DAY (owner ruling, 13-Sep-2026).
     *    *"if two different riders rode the van, then it should be handled… it should be shown
     *    in the van logs as well that how much did it travel?"*
     *
     *    The row is ONE PER MACHINE PER DAY (`uq_vehicle_day`), so it is read as **the machine's
     *    day**, not one man's stint: `meter_start` is the first reading anybody gave it, and
     *    `meter_end` the last. Rajab 75,403 → hands back 75,484 → Farooq takes it → hands back
     *    75,600, and the van's day reads 75,403 → 75,600 = 197 km.
     *
     * ⚠⚠ THIS USED TO REFUSE OUTRIGHT when the row belonged to another driver, to avoid
     *    overwriting his stint. That protected his attribution but threw the second rider's
     *    reading on the floor — the van's day then ended on the FIRST man's number and the rest
     *    of its kilometres existed nowhere. Refusing to record a handover that physically
     *    happened is the failure mode this whole round exists to remove.
     *
     * ⭐ So the row WIDENS instead, and the moment a second driver's kilometres join it the row
     *   stops belonging to any one man: `driver_user_id` becomes NULL, which every reader
     *   already understands as "the machine's own record". That matters for money —
     *   `MachineAttribution` turns a log row into a chain point owned by `driver_id`, so leaving
     *   the first man named would hand him the second man's kilometres. NULL attributes them to
     *   nobody, which is the honest answer; each rider's own share is still exact on his lens,
     *   because it comes from the assignment rows' `handover_meter`, one per handover.
     *
     * ⚠ `max()`, not "last write wins": a day's closing odometer must never travel backwards,
     *   whatever order two handovers are recorded in. Corrections are the ✎ editor's job.
     */
    public function recordClosingMeter(int $vehicleId, string $date, int $meter,
                                       int $driverId, ?int $actorId, ?string $note = null): array
    {
        try {
            if (!self::hasTbl(self::T_METER_LOG)) {
                return ['ok' => false, 'message' => 'Meter logging is not set up on this server yet.'];
            }
            $date = substr($date, 0, 10);

            $row = DB::table(self::T_METER_LOG)
                ->where('vehicle_id', $vehicleId)->where('log_date', $date)
                ->first(['meter_start', 'meter_end', 'driver_user_id', 'note']);

            $rowDriver = $row && $row->driver_user_id !== null ? (int) $row->driver_user_id : null;
            $shared    = $rowDriver !== null && $rowDriver !== $driverId;

            $existingStart = $row && $row->meter_start !== null ? (int) $row->meter_start : null;
            $existingEnd   = $row && $row->meter_end   !== null ? (int) $row->meter_end   : null;
            // "The later reading wins" is asked on the continuous scale, so on the day a meter is
            // replaced the NEW meter's close is kept (identity for a machine never replaced).
            $end = ($existingEnd !== null
                    && MeterReplacement::toContinuous($vehicleId, $existingEnd, $date)
                       > MeterReplacement::toContinuous($vehicleId, $meter, $date))
                ? $existingEnd : $meter;

            if ($shared) {
                Log::info('meter log now covers more than one driver — the day belongs to the machine', [
                    'vehicle' => $vehicleId, 'date' => $date, 'was_driver' => $rowDriver,
                    'also_driver' => $driverId, 'meter' => $meter,
                ]);
            }

            $saved = $this->saveMeterLog(
                $vehicleId, $date, $existingStart, $end,
                $shared ? null : $driverId,
                $shared ? 'Two or more riders on this machine today' : ($note ?: 'Handed back'),
                $actorId ?: $driverId
            );
            return ['ok' => (bool) ($saved['ok'] ?? false), 'message' => $saved['message'] ?? null,
                    'shared' => $shared];
        } catch (\Throwable $e) {
            // The handover is the thing that must survive; a missing log row is repairable from
            // the Vehicles screen, an unrecorded handover is not.
            Log::warning('closing meter not recorded', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not record the closing reading.'];
        }
    }

    /**
     * @param ?int $vacatedMeter  R3 — the CLOSING reading of whatever this rider is stepping OFF
     *                            (step 3 below). Compulsory when that machine is a company one.
     *                            This is a DIFFERENT number from `$handoverMeter`, which is the
     *                            opening reading of the machine he is stepping ON to.
     */
    public function assign(int $vehicleId, int $userId, ?string $onDate = null,
                           ?int $actorId = null, ?string $note = null,
                           ?int $handoverMeter = null,
                           bool $displacedSettleFollows = false,
                           ?int $vacatedMeter = null): array
    {
        if (!$this->available()) return $this->fail('Vehicles are not set up yet (SQL batch 13).');

        $date = $this->safeDate($onDate);

        // ⭐ THE ODOMETER AT THE MOMENT OF HANDOVER (owner ruling Aug-13). Optional
        //   everywhere: with it, that day's run splits exactly between the two
        //   riders; without it, the day stays "shared" and is charged to neither.
        // ⚠⚠ SOFT-VALIDATED ON PURPOSE. A handover is a physical event that has
        //   already happened — refusing to record it because a digit looks wrong
        //   would leave the register lying about who has the bike. Implausible
        //   values are stored and reported, never rejected, and the engine ignores
        //   any reading that falls outside the day it is supposed to split.
        $handoverMeter = ($handoverMeter !== null && $handoverMeter > 0) ? $handoverMeter : null;

        try {
            $v = DB::table(self::T_VEHICLE)->where('id', $vehicleId)->first();
            if (!$v) return $this->fail('That vehicle no longer exists.');
            if ((int) $v->is_active !== 1) return $this->fail('That vehicle is retired — reactivate it first.');

            $profile = DB::table('t_ops_rider_profile')->where('user_id', $userId)->first();
            if (!$profile) return $this->fail('That user has no rider profile, so a vehicle cannot be assigned to him.');

            $current = $this->keeperOf($vehicleId);
            if ($current && (int) $current->user_id === $userId) {
                return ['ok' => true, 'message' => 'Already assigned to that rider.', 'id' => (int) $current->id,
                        'changed' => false];
            }

            /**
             * ⭐⭐ R3 GATE — BOTH ENDS (13-Sep-2026). Checked here so the caller gets a specific,
             *    actionable message; re-checked INSIDE the transaction against the LOCKED rows,
             *    because this read can be stale and a gate that can be raced is not a gate.
             *
             * `meter_missing` names the field the UI must fill, so web, store mode and the rider
             * sheet can all highlight the right box from one server answer.
             */
            if ($this->needsOpeningMeter($vehicleId) && $handoverMeter === null) {
                return $this->fail($this->meterRequiredMessage($vehicleId, 'opening'))
                     + ['meter_missing' => 'handover_meter', 'meter_vehicle_id' => $vehicleId];
            }
            $hisNow = $this->openAssignmentForUser($userId);
            if ($hisNow && (int) $hisNow->vehicle_id !== $vehicleId
                && $this->needsClosingMeter((int) $hisNow->vehicle_id) && $vacatedMeter === null) {
                return $this->fail($this->meterRequiredMessage((int) $hisNow->vehicle_id, 'closing'))
                     + ['meter_missing' => 'vacated_meter', 'meter_vehicle_id' => (int) $hisNow->vehicle_id];
            }

            $newId = null;
            // ⭐⭐ THE LOCKED READ INSIDE THE TRANSACTION IS THE ONLY AUTHORITY ON WHO
            //    LOST THE MACHINE (Sep-2026). `$current` above was read OUTSIDE the
            //    lock, purely to answer "is this already the keeper?" — by the time
            //    the transaction runs it can be stale, which is the entire reason the
            //    lock exists. Everything after the commit (the displaced rider the
            //    caller must settle, the van cargo, the company_bike resync) now reads
            //    `$displaced`, which the closure fills from the LOCKED row.
            // ⚠ Null means "nobody was displaced": either the vehicle was free, or a
            //   concurrent assign had already put this very rider on it.
            $displaced = null;
            // ⭐ The machine the INCOMING rider vacated via step 3 (Sep-01 review):
            //   its assignment history changed too, so its derived caches (service
            //   clock, keeper, transfer days) must be bumped exactly like the
            //   assigned machine's — Rajab's evening give-back closes his VAN row
            //   through this step, and the van's card must not serve stale answers.
            $vacatedVehicleId = null;
            $hasHandoverCol = $this->hasHandoverMeter();
            DB::transaction(function () use ($vehicleId, $userId, $date, $actorId, $note, $v,
                                             $handoverMeter, $hasHandoverCol, $vacatedMeter,
                                             &$newId, &$displaced, &$vacatedVehicleId) {
                // ⚠ RE-READ INSIDE THE TRANSACTION, and lock the rows. The check
                //   above ran outside it, so two managers pressing Assign on the
                //   same bike within the same second would each see "no change
                //   needed", each insert, and leave the vehicle with TWO open
                //   assignments — a state nothing else in this class expects.
                //   lockForUpdate serialises them; the second one now sees the
                //   first one's row and closes it properly.
                $current = DB::table(self::T_ASSIGN)
                    ->where('vehicle_id', $vehicleId)->whereNull('released_on')
                    ->orderByDesc('id')->lockForUpdate()->first();

                // Someone else got there first with the same rider — nothing to do.
                if ($current && (int) $current->user_id === $userId) {
                    $newId = (int) $current->id;
                    return;
                }

                // 2. release the current keeper of THIS vehicle
                if ($current) {
                    $displaced = $current;      // ⭐ the man who ACTUALLY lost it (locked read)
                    $this->closeAssignment((int) $current->id, $date, $actorId);
                    $this->clearMirrorIfPointsAt((int) $current->user_id, $vehicleId);
                }

                // 3. release the RIDER from anything else he holds. Locked for the
                //    same reason as above.
                $his = DB::table(self::T_ASSIGN)->where('user_id', $userId)
                    ->whereNull('released_on')->orderByDesc('id')->lockForUpdate()->first();
                if ($his && (int) $his->vehicle_id !== $vehicleId) {
                    /**
                     * ⚠⚠ R3, AUTHORITATIVELY. The pre-flight gate above read this row OUTSIDE the
                     *    lock; by now it can be a different machine. Throwing here rolls the whole
                     *    handover back rather than letting a company machine slip out of his hands
                     *    unrecorded — which is exactly what happened on prod at 17:46.
                     */
                    if ($this->needsClosingMeter((int) $his->vehicle_id) && $vacatedMeter === null) {
                        throw new \RuntimeException(self::ERR_CLOSING_METER . (int) $his->vehicle_id);
                    }
                    $this->closeAssignment((int) $his->id, $date, $actorId);
                    $vacatedVehicleId = (int) $his->vehicle_id;   // its caches bump post-commit
                }

                // 4. the new assignment
                $row = [
                    'vehicle_id'  => $vehicleId,
                    'user_id'     => $userId,
                    'assigned_on' => $date,
                    'assigned_by' => $actorId,
                    'note'        => $note ? mb_substr($note, 0, 255) : null,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ];
                // Guarded so an upload that lands before the SQL simply ignores the
                // field rather than throwing on every handover.
                if ($hasHandoverCol && $handoverMeter !== null) {
                    $row['handover_meter'] = $handoverMeter;
                }
                $newId = (int) DB::table(self::T_ASSIGN)->insertGetId($row);

                // 5. the mirror — `default_vehicle_id` ONLY. That column is new and
                //    nothing in the live rules reads it yet, so this stays inert.
                //    company_bike is deliberately untouched (block comment above).
                DB::table('t_ops_rider_profile')->where('user_id', $userId)->update([
                    'default_vehicle_id' => $vehicleId,
                    'updated_at'         => now(),
                ]);
            });

            Log::info('Vehicle assigned', [
                'vehicle_id' => $vehicleId, 'to_user' => $userId,
                'from_user'  => $displaced->user_id ?? null,
                'on'         => $date, 'by' => $actorId,
            ]);

            // ⭐ THE CARGO GOES WITH THE VAN (owner ruling Aug-5, from a prod
            //    handover). Boxes already scanned aboard are physically in the
            //    vehicle — when the keys change hands, so do they. Without this
            //    they stayed on the old driver's manifest and were invisible to the
            //    man actually driving them, and the riders waiting at the meet point
            //    had nobody who could hand them over.
            //
            //    OUTSIDE the transaction on purpose: the assignment is the thing
            //    that must be atomic. If the cargo move fails, the handover is still
            //    recorded and the boxes can be re-pointed — the reverse (an
            //    assignment rolled back by a cargo error) would be worse.
            $moved = 0;
            if ($displaced && (string) $v->vtype === 'van') {
                $moved = app(VanService::class)
                    ->moveCargo($vehicleId, (int) $displaced->user_id, $userId, $actorId);
            }

            // ⭐ THE OPEN PROBLEMS GO WITH THE MACHINE TOO (owner ruling 5-Sep) — same reasoning
            //   as the cargo above, and outside the transaction for the same reason: the
            //   assignment is what must be atomic. A note or a push that fails must never undo
            //   a handover that really happened.
            app(VehicleTicketService::class)->onHandover(
                $vehicleId, isset($displaced->user_id) ? (int) $displaced->user_id : null, $userId, $actorId
            );
            // 🔧 …and its booked workshop day: the errand belongs to the machine, so the new
            //   holder is now the one taking it in (and is asked to accept it afresh).
            app(WorkshopVisitService::class)->onHandover(
                $vehicleId, isset($displaced->user_id) ? (int) $displaced->user_id : null, $userId, $actorId
            );

            /**
             * ⚠⚠ AND THE MACHINE HE JUST STEPPED OFF. Giving a rider a bike releases whatever
             *    else he was holding (step 3 above) — that machine changed hands too, and its
             *    threads deserve the same dated line. Without this the visibility DID follow
             *    the registry (he stops seeing its tickets, correctly) while the thread said
             *    nothing about why he stopped answering. Found by walking the displaced side,
             *    not the obvious one.
             *  ⚠ No new holder, so no push — exactly like release().
             */
            /**
             * ⭐⭐ R3 — THE MACHINE HE STEPPED OFF GETS ITS CLOSING READING (13-Sep-2026).
             *
             * ⚠⚠ THIS IS THE PATH THAT RECORDED NOTHING ON PROD. Rajab's 17:46 "give me my own
             *    bike back" was an ASSIGN of the bike, and assign's step 3 closed the VAN row
             *    directly — never going through release(), which was the only place that wrote a
             *    closing reading. So the van's day ended with no number from anybody and Shabib
             *    typed it by hand a minute later. The gate above now demands it; this writes it.
             * ⚠ Outside the transaction, like the cargo and the tickets: the assignment is the
             *   thing that must be atomic. A log row that fails is repairable from the Vehicles
             *   screen; a handover rolled back by a logging error would be worse.
             */
            if ($vacatedVehicleId && $vacatedVehicleId !== $vehicleId && $vacatedMeter !== null) {
                $this->recordClosingMeter($vacatedVehicleId, $date, (int) $vacatedMeter,
                                          $userId, $actorId, 'Handed back on switching machine');
            }

            /**
             * ⭐⭐ …AND THE MACHINE HE JUST STEPPED ON TO (owner ruling, 13-Sep-2026).
             *
             * The opening reading has always been stored on the assignment row above
             * (`handover_meter`, which is what splits the day between two riders). It also
             * belongs in the MACHINE's own log, or the van's day shows a close with no open and
             * "how far did it go today" answers blank. Both ends now go through one place.
             *
             * ⚠ Company machines only: R3 guarantees a reading for those, and an own bike's
             *   day is already told by its owner's attendance row — writing a second copy here
             *   would give `MachineAttribution` two chain points for one set of kilometres.
             * ⚠ Outside the transaction with the rest: a log row that fails is repairable from
             *   the Vehicles screen; a handover rolled back by a logging error is not.
             */
            if ($handoverMeter !== null && $this->needsClosingMeter($vehicleId)) {
                $this->recordOpeningMeter($vehicleId, $date, (int) $handoverMeter,
                                          $userId, $actorId, 'Handed over');
            }

            if ($vacatedVehicleId && $vacatedVehicleId !== $vehicleId) {
                app(VehicleTicketService::class)->onHandover($vacatedVehicleId, $userId, null, $actorId);
                /**
                 * 🔧⚠⚠ …AND ITS WORKSHOP ERRAND (10-Sep-2026 review). `release()` learned to
                 *    tell the workshop service; THIS path never did, because it closes the old
                 *    assignment directly (step 3 above) rather than going through release().
                 *
                 *    That is the ORDINARY workshop day: the bike goes in, the manager hands the
                 *    rider a spare, and his old machine quietly loses its keeper here. Without
                 *    this the errand stayed pointing at him — his phone kept offering
                 *    "Workshop jaa raha hoon" for a bike he no longer had, and once he had set
                 *    off, the live board showed him at the workshop all evening while he was
                 *    out delivering on the spare.
                 *
                 * ⚠ No new keeper, so nothing is re-pointed: the visit is marked as having
                 *   none, exactly as a release does, and stays open for someone to answer.
                 */
                app(WorkshopVisitService::class)->onKeeperLost($vacatedVehicleId, $userId, $actorId);
            }

            // ⚠ A handover re-attributes claims and readings, so the machine's whole
            //   derivation changes. BOTH calls are needed: bumpServiceEvidence clears
            //   this class's memos + the shared cache, and VehicleResolver keeps its
            //   OWN static day-cache that bumpServiceEvidence does not touch.
            self::bumpServiceEvidence($vehicleId);
            if ($vacatedVehicleId) {
                self::bumpServiceEvidence($vacatedVehicleId);
            }
            VehicleResolver::flush();
            // ⚠ AND the leg engine's memos — `ownMachineIdsFor` is keyed on assignment
            //   rows, which this method just rewrote. `settleDisplacedRider` calls
            //   `ownVehicleFor()` (→ that memo) microseconds later IN THIS SAME REQUEST,
            //   so a stale entry would decide the fallback bike from the pre-handover
            //   world. Same reasoning as the VehicleResolver flush above.
            RiderDayLegs::flush();

            // ⭐ THE CHECKBOX FOLLOWS THE KEYS (owner ruling, Aug-16). Both people:
            //   the man who just received the machine, and the one who lost it —
            //   whose flag is recomputed from what he ACTUALLY holds afterwards, so
            //   handing him another company bike in the same breath leaves him ticked.
            $this->syncCompanyBikeFlag($userId);
            if ($displaced && (int) $displaced->user_id !== $userId) {
                $this->syncCompanyBikeFlag((int) $displaced->user_id);
            }

            // ⭐ THE RIDE-HOME TIMER FOLLOWS THE KEYS TOO (Sep-2026). BOTH men are
            //   offered: the one who lost the machine, and the INCOMING rider — who
            //   can also end up without a company machine here, because step 3 closes
            //   his old row and this new machine may be his own bike (exactly the
            //   'own' fallback the displaced flow uses). The method itself is a no-op
            //   for anyone still holding company iron, so offering both is free.
            // ⚠ MUST run after the flushes above, or it reads the pre-handover world.
            //
            // ⚠⚠ THE DISPLACED MAN'S DISARM WAITS FOR HIS SETTLEMENT (Sep-01 review
            //    finding). Between this assign and `settleDisplacedRider` he holds
            //    nothing for a few milliseconds — but if the settle then puts him on
            //    ANOTHER COMPANY machine, a timer erased here could never come back
            //    (arming happens only at checkout), and his machine's closing meter
            //    would silently never be demanded that evening. So a caller that is
            //    about to settle him says so, and judges him AFTER placement via
            //    `disarmIdleHomeJourney()` — when "holds nothing" is a fact, not a
            //    moment mid-handover. Callers that settle nobody keep the old
            //    behaviour: the displaced man is judged (and disarmed) right here.
            $this->disarmHomeJourney($userId);
            if ($displaced && (int) $displaced->user_id !== $userId && !$displacedSettleFollows) {
                $this->disarmHomeJourney((int) $displaced->user_id);
            }

            // ⭐ PHASE D — WHO WAS LEFT WITHOUT A MACHINE BY THIS. The caller asks the
            //   manager what to do about him ("another bike / his own / nothing"),
            //   because the one thing that must not happen is a rider quietly ending
            //   up with no machine while the system still judges him as if he had one.
            return ['ok' => true, 'message' => $this->displayName($v) . ' assigned.', 'id' => $newId,
                    'changed' => true, 'cargo_moved' => $moved,
                    'displaced_user_id' => $displaced ? (int) $displaced->user_id : null];
        } catch (\Throwable $e) {
            // ⚠ The R3 refusal raised inside the transaction comes back as the SAME actionable
            //   answer the pre-flight gate gives — not a generic failure the manager cannot act on.
            if (str_starts_with($e->getMessage(), self::ERR_CLOSING_METER)) {
                $vacatedId = (int) substr($e->getMessage(), strlen(self::ERR_CLOSING_METER));
                return $this->fail($this->meterRequiredMessage($vacatedId, 'closing'))
                     + ['meter_missing' => 'vacated_meter', 'meter_vehicle_id' => $vacatedId];
            }
            Log::error('VehicleService::assign failed', [
                'vehicle_id' => $vehicleId, 'user_id' => $userId, 'error' => $e->getMessage(),
            ]);
            return $this->fail('Could not assign that vehicle.');
        }
    }

    /**
     * ⭐⭐ KEEP `t_ops_rider_profile.company_bike` IN STEP WITH WHAT HE ACTUALLY HOLDS
     *    (owner ruling, Aug-16: "on transfer it should automatically be ticked").
     *
     * ⚠ WHY IT MATTERS EVEN THOUGH THE REGISTRY ALREADY OUTRANKS THE CHECKBOX.
     *   Fuel treatment, the meter demands and the ⛽ attendance tick all ask the
     *   registry per date, so they were already correct. But TWO places still read
     *   the raw column, and they silently skipped a newly-assigned rider:
     *     • `WorkJourneyService::workIssueDays` — selects `company_bike = 1` AND a
     *       home pin, so "no home start", the ride-in ETA and overnight meter
     *       continuity were simply not evaluated for him;
     *     • the DayChecks population widener, which then falls back to guessing from
     *       his ROLE NAME containing "rider".
     *   Measured on the replica: a rider handed a company bike was skipped by both
     *   until a human remembered the tick. Syncing the column closes that hole
     *   without touching the rules that were already right.
     *
     * ⚠⚠ NEVER TOUCHES `meter_required`. That is a deliberate per-person exemption a
     *    manager sets ("this one does not do meters"); the registry already answers
     *    the meter gate, and overwriting it here would erase an intentional decision.
     *
     * ⚠⚠ GATED ON `VEHICLE_RULES`. While the switch is off, an assignment is
     *    "recorded, not yet enforced" — the assign preview says exactly that to the
     *    manager — and the checkbox is the ONLY authority. Writing it then would
     *    enforce the assignment through the back door and make that promise a lie.
     *
     * ⭐ LAST WRITER WINS (owner ruling): a manual edit stands until the rider's
     *    machine actually changes again. No "manually overridden" marker — a flag
     *    that freezes the column would quietly stop tracking reality, which is the
     *    problem this method exists to fix.
     *
     * Non-fatal and idempotent: it writes only on a real change, and a rider with no
     * profile row is a silent no-op (assign() already warns about that case).
     */
    private function syncCompanyBikeFlag(?int $userId): void
    {
        if (!$userId) return;
        try {
            if (!$this->rulesEnabled()) return;
            if (!self::hasCol('t_ops_rider_profile', 'company_bike')) return;

            $profile = DB::table('t_ops_rider_profile')->where('user_id', $userId)
                ->first(['company_bike']);
            if (!$profile) return;                 // not a rider — nothing to keep in step

            // What he holds RIGHT NOW. `currentVehicleFor` (not vehicleForDay) is the
            // right question: the checkbox describes his present arrangement, and on a
            // transfer day the outgoing rider must read as "handed it back" even
            // though that date still counts his morning stint for history.
            $vid = (new VehicleResolver())->currentVehicleFor($userId);
            $now = 0;
            if ($vid) {
                $v = DB::table(self::T_VEHICLE)->where('id', $vid)->first(['is_company']);
                $now = ($v && (int) $v->is_company === 1) ? 1 : 0;
            }

            if ((int) $profile->company_bike === $now) return;   // already agrees

            DB::table('t_ops_rider_profile')->where('user_id', $userId)
                ->update(['company_bike' => $now, 'updated_at' => now()]);

            Log::info('company_bike synced from the vehicle registry', [
                'user_id' => $userId, 'was' => (int) $profile->company_bike,
                'now' => $now, 'vehicle_id' => $vid,
            ]);
        } catch (\Throwable $e) {
            // A handover must never fail because a denormalised flag would not move.
            Log::warning('syncCompanyBikeFlag skipped', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * ⭐ The displaced rider's disarm, run AFTER his settlement — the public half of
     *   the `$displacedSettleFollows` contract in assign()/release(). Judges "holds
     *   nothing" against his FINAL state for this operation, so a man settled onto
     *   another company machine keeps his live ride-home timer, and one settled onto
     *   his own bike (or nothing) loses the timer for the machine that left him.
     *   Safe to call unconditionally: it is a no-op for anyone on company iron.
     */
    public function disarmIdleHomeJourney(int $userId): void
    {
        $this->disarmHomeJourney($userId);
    }

    /**
     * Clear a ride-home timer armed for a machine the rider no longer has.
     * Thin, try-wrapped delegate — see `HomeJourneyService::disarmIfNoCompanyMachine`
     * for the full reasoning and the narrow scope. A handover must never fail
     * because a timer would not clear.
     */
    private function disarmHomeJourney(int $userId): void
    {
        try {
            (new HomeJourneyService())->disarmIfNoCompanyMachine($userId);
        } catch (\Throwable $e) {
            Log::warning('disarmHomeJourney skipped', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /** Take a vehicle back without giving it to anyone else. */
    /**
     * @param ?int $meter  R3 — the machine's CLOSING reading. Compulsory for a company machine.
     *                     ⚠⚠ `release()` HAD NO METER PARAMETER AT ALL until 13-Sep-2026, so a
     *                     web or store-mode "Take back" has never recorded a machine's closing
     *                     odometer — the number simply had nowhere to go. Written to the
     *                     machine's OWN meter log, never to anybody's attendance row.
     */
    public function release(int $vehicleId, ?string $onDate = null, ?int $actorId = null,
                            bool $displacedSettleFollows = false, ?int $meter = null): array
    {
        if (!$this->available()) return $this->fail('Vehicles are not set up yet (SQL batch 13).');

        $date = $this->safeDate($onDate);
        try {
            $current = $this->keeperOf($vehicleId);
            if (!$current) return ['ok' => true, 'message' => 'Nobody holds that vehicle.', 'changed' => false];

            // ⭐ R3 — checked only once somebody actually holds it: releasing a machine nobody
            //   has is a no-op above and must not demand a reading for a handover that isn't one.
            if ($this->needsClosingMeter($vehicleId) && $meter === null) {
                return $this->fail($this->meterRequiredMessage($vehicleId, 'closing'))
                     + ['meter_missing' => 'meter', 'meter_vehicle_id' => $vehicleId];
            }

            DB::transaction(function () use ($current, $vehicleId, $date, $actorId) {
                $this->closeAssignment((int) $current->id, $date, $actorId);
                $this->clearMirrorIfPointsAt((int) $current->user_id, $vehicleId);
            });

            // ⭐ R3 — the closing reading, into the MACHINE's own log. Outside the transaction for
            //   the same reason as assign()'s: the release is what must be atomic.
            if ($meter !== null) {
                $this->recordClosingMeter($vehicleId, $date, (int) $meter,
                                          (int) $current->user_id, $actorId, 'Handed back');
            }

            // Same reason as assign(): attribution just changed for this machine.
            self::bumpServiceEvidence($vehicleId);
            VehicleResolver::flush();
            RiderDayLegs::flush();

            // He just gave a machine back — recompute from whatever he holds now
            // (usually nothing, but a rider can hold his own bike as well).
            $this->syncCompanyBikeFlag((int) $current->user_id);
            // ⚠ Same settle-follows contract as assign() — see the comment there. A
            //   caller about to place this man on a replacement machine judges his
            //   ride-home timer AFTER placement, not in the gap between the two.
            if (!$displacedSettleFollows) {
                $this->disarmHomeJourney((int) $current->user_id);
            }

            // ⭐ The machine's open problems get the same dated line as on assign() — the bike
            //   is now with nobody, and a reader of the thread should be able to see that.
            //   No push here: there is no new holder to warn, and the man who just handed it
            //   back does not need telling about a bike he no longer has.
            app(VehicleTicketService::class)->onHandover(
                $vehicleId, (int) $current->user_id, null, $actorId
            );

            /**
             * 🔧⭐⭐ AN OPEN WORKSHOP ERRAND SURVIVES THE RELEASE (owner ruling, 10-Sep-2026).
             *
             * *"in this case Shabib will simply remove the bike from his name with the change
             * rider option"* — that is how a bike kept overnight is handled. So the release must
             * not take the errand down with it: the work still has to be answered for.
             *
             * ⚠⚠ `release()` HAS NEVER TOLD THE WORKSHOP SERVICE. Only tickets followed a
             *    handover (the call directly above); a released machine left its visit pointing
             *    at a rider who no longer has it, and — once the trip existed — would have left
             *    him showing "at the workshop" on the live board all evening.
             *
             * ⭐ The visit is NOT closed and NOT auto-answered. It is marked as having no keeper,
             *   which is a fact, and the managers are told so somebody records the outcome when
             *   the bike comes back. Answering it here would erase the difference between "he
             *   went" and "he never went" — the rule this whole feature is built around.
             */
            try {
                app(WorkshopVisitService::class)->onKeeperLost($vehicleId, (int) $current->user_id, $actorId);
            } catch (\Throwable $e) {
                Log::warning('workshop no-keeper handoff skipped', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
            }

            Log::info('Vehicle released', ['vehicle_id' => $vehicleId, 'from_user' => $current->user_id, 'on' => $date]);
            return ['ok' => true, 'message' => 'Released.', 'changed' => true,
                    'displaced_user_id' => (int) $current->user_id];
        } catch (\Throwable $e) {
            Log::error('VehicleService::release failed', ['id' => $vehicleId, 'error' => $e->getMessage()]);
            return $this->fail('Could not release that vehicle.');
        }
    }

    // =================================================================
    // WRITES — photos
    // =================================================================

    /**
     * Record a dated condition photo. `$path` is already-stored relative path
     * (the controller does the upload, matching storeMeterPicture's convention).
     * Never blocks an assignment — a handover with no photo is still a handover.
     */
    public function addPhoto(int $vehicleId, string $path, ?string $takenOn, string $context,
                             ?string $note, ?int $actorId, ?int $assignmentId = null): array
    {
        if (!$this->available()) return $this->fail('Vehicles are not set up yet (SQL batch 13).');
        if (!array_key_exists($context, self::CONTEXT_LABELS)) $context = 'condition';

        try {
            $id = (int) DB::table(self::T_PHOTO)->insertGetId([
                'vehicle_id'    => $vehicleId,
                'assignment_id' => $assignmentId,
                'photo_path'    => $path,
                'taken_on'      => $this->safeDate($takenOn),
                'context'       => $context,
                'note'          => $note ? mb_substr($note, 0, 255) : null,
                'uploaded_by'   => $actorId,
                'created_at'    => now(),
            ]);
            return ['ok' => true, 'message' => null, 'id' => $id];
        } catch (\Throwable $e) {
            Log::error('VehicleService::addPhoto failed', ['vehicle_id' => $vehicleId, 'error' => $e->getMessage()]);
            return $this->fail('Could not save that photo.');
        }
    }

    /** Remove a photo row. The file is left on disk — cheap, and undelete is manual. */
    public function deletePhoto(int $photoId): array
    {
        if (!$this->available()) return $this->fail('Vehicles are not set up yet (SQL batch 13).');
        try {
            DB::table(self::T_PHOTO)->where('id', $photoId)->delete();
            return ['ok' => true, 'message' => 'Photo removed.', 'id' => $photoId];
        } catch (\Throwable $e) {
            return $this->fail('Could not remove that photo.');
        }
    }

    // =================================================================
    // internals
    // =================================================================

    /**
     * The vehicle's current odometer.
     *
     * Forward-compatible on purpose: once attendance rows carry `vehicle_id`
     * (Phase B) this reads the MACHINE's own history directly. Until then it is
     * reconstructed from the assignment timeline.
     *
     * ⚠ THE FALLBACK MUST BE DATE-SCOPED, and getting this wrong is not academic.
     *   A first version simply took the CURRENT keeper's highest reading. That is
     *   right only while nobody has ever swapped: the moment BCN-5755 was hosted
     *   over to Danish, the card claimed it read 33,732 km — Danish's OWN bike —
     *   when the machine itself was at 34,071, and the service-due figure inherited
     *   the error. A rider's readings belong to whatever he was riding AT THE TIME.
     *
     *   So each keeper only contributes readings from the window he actually held
     *   this vehicle: [assigned_on, released_on], open-ended while he still has it.
     *   Danish's pre-handover months land on his own bike, where they belong, and
     *   BCN-5755 keeps Arslan's 34,071.
     */
    private function currentMeter(int $vehicleId, ?int $keeperUserId): ?int
    {
        // Memoised with the rest of the service derivation: the card, the schedule
        // and the alert sweep each want this machine's odometer in one render, and
        // it is a multi-window reconstruction every time.
        // ⭐ Meter replaced (Oct-2026): the memo holds the CONTINUOUS distance; what leaves here
        //   is what the meter on the bike shows today. Identical for a machine never replaced.
        return MeterReplacement::toRaw($vehicleId, $this->currentMeterContinuous($vehicleId), null);
    }

    /**
     * The machine's odometer on its continuous scale — total distance, straight through any
     * meter replacement. For ARITHMETIC against other continuous readings (countdowns, spans);
     * `currentMeterFor()` is the one to show a person.
     */
    public function currentMeterContinuous(int $vehicleId): ?int
    {
        if (array_key_exists($vehicleId, self::$meterMemo)) return self::$meterMemo[$vehicleId];
        return self::$meterMemo[$vehicleId] = $this->computeCurrentMeter($vehicleId);
    }

    private function computeCurrentMeter(int $vehicleId): ?int
    {
        try {
            // ⚠ The dropped-digit floor, machine-relative (Aug-2026). Without this a
            //   bike that has genuinely never passed 1,000 km reports NO odometer at
            //   all, so every countdown built on it reads "unknown" — EDN-198 had an
            //   approved oil change and still showed no schedule. `false` for every
            //   5-figure machine, i.e. this changes nothing anywhere else.
            $floor = $this->isLowMileageMachine($vehicleId) ? 0 : self::MIN_METER;

            // ⭐⭐ Aug-22 2026 — the SAME reading-level rule the odometer window now uses.
            //    `SANE_ROW_SQL` is the FOURTH copy of the old predicate and carries the identical
            //    defect: a handover evening (a close with no start) was discarded WHOLE, so a
            //    machine's "current meter" ignored the very last reading it produced. That number
            //    is what the fleet cards print and what the service clock derives from, so the
            //    machine could read stale by a full day's running the moment it changed hands.
            $readingLevel = $this->readingLevelOn();
            $rowSql   = $readingLevel ? self::readingRowFilterSql() : self::SANE_ROW_SQL;
            $highExpr = $readingLevel
                ? self::readingHighExprSql($vehicleId)
                : 'GREATEST(COALESCE(meter_end,0), COALESCE(meter_home,0), COALESCE(meter_start,0))';

            // ⭐ Meter replaced (Oct-2026): every reading below is taken on the machine's
            //   CONTINUOUS scale, so the MAX is its true total distance — not the old meter's
            //   last figure for ever. Both lines are no-ops for a machine never replaced.
            $rowSql   = self::effSql($rowSql, $vehicleId);
            $highExpr = self::effSql($highExpr, $vehicleId);
            $fillExpr = self::fillSql($vehicleId);
            $firstReset = MeterReplacement::rowsFor($vehicleId)[0]['d'] ?? null;

            // 1. The machine's own rows (Phase B onwards).
            $byVehicle = (int) DB::table('t_ops_attendance')
                ->where('vehicle_id', $vehicleId)
                ->whereRaw($rowSql)
                ->selectRaw('MAX(' . $highExpr . ') AS m')
                ->value('m');

            $byFill = (int) DB::table('t_req_master')
                ->where('vehicle_id', $vehicleId)
                ->whereNotNull('meter_at_fill')
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->selectRaw('MAX(' . $fillExpr . ') AS m')
                ->value('m');

            /**
             * ⭐ A RECORDED SERVICE IS A READING TOO (owner ruling Q4, 3-Sep — found NOT built
             *   while re-checking). Until now only attendance meters and claims' `meter_at_fill`
             *   moved the odometer, so a manager recording a service at 28,100 km on a bike last
             *   seen at 27,986 moved the countdown but left "current km" behind — two definitions
             *   of the bike's km. A service log is attributed to the machine the SAME way the
             *   countdowns attribute it (who held which bike on that date) and takes the same
             *   plausibility floor. A typo is correctable via Edit, and the mirror keeps a linked
             *   pair equal, so this can never disagree with the claim's own `meter_at_fill`.
             */
            $byService = 0;
            try {
                if (self::hasTbl('t_fleet_service_log')) {
                    $res = new VehicleResolver();
                    foreach (DB::table('t_fleet_service_log')->whereNotNull('meter')
                                ->where('meter', '>', $floor)
                                ->orderByDesc('meter')->limit(60)
                                ->get(array_merge(['user_id', 'meter', 'service_date'],
                                                  ServiceRecordService::logVehicleCols())) as $sl) {
                        $d = substr((string) $sl->service_date, 0, 10);
                        // Readings off a replaced meter are added below, on the continuous scale.
                        if ($firstReset !== null && $d >= $firstReset) continue;
                        // ⭐ Stamp first — a reading belongs to the machine it was taken on.
                        if (ServiceRecordService::logVehicleOf($sl, $res) !== $vehicleId) continue;
                        $byService = (int) $sl->meter;
                        break;   // ordered by meter desc — the first row on THIS machine is its highest
                    }
                    foreach ($this->serviceLogsOnNewMeter($vehicleId) as $nl) {
                        $byService = max($byService, $nl['m']);
                    }
                }
            } catch (\Throwable $e) {
                $byService = 0;
            }

            $best = max($byVehicle, $byFill, $byService);

            // 2. Reconstruct from who held it when.
            foreach ($this->assignmentWindows($vehicleId) as $w) {
                // ⚠⚠ …MINUS the days inside that window the resolver assigns to a
                //    DIFFERENT machine. Without this a same-day assignment reversed
                //    within the day (EDN-198 → Asim → back, Aug-9) let his OWN bike's
                //    12,390 km become this machine's odometer. See
                //    datesAttributedElsewhere() for the full incident.
                $skip = $this->datesAttributedElsewhere(
                    (int) $w['user_id'], $w['from'], $w['to'], $vehicleId
                );

                $att = (int) DB::table('t_ops_attendance')
                    ->where('user_id', $w['user_id'])
                    ->where('attendance_date', '>=', $w['from'])
                    ->when($w['to'], fn ($q) => $q->where('attendance_date', '<=', $w['to']))
                    ->when($skip, fn ($q) => $q->whereNotIn('attendance_date', $skip))
                    ->whereRaw($rowSql)
                    ->selectRaw('MAX(' . $highExpr . ') AS m')
                    ->value('m');

                $fill = (int) DB::table('t_req_master')
                    ->where('requester_user_id', $w['user_id'])
                    // ⚠⚠ UNSTAMPED ONLY (Aug-22 2026). This query walks claims BY THE KEEPER, and
                    //    without this filter it ingested claims STAMPED TO A DIFFERENT MACHINE:
                    //    Rajab's own bike (~6,600 km) showed "73,562 km" on its card because his
                    //    VAN cash claims (stamped vehicle_id=4, filed in his name by a manager)
                    //    fell inside his window on the bike. A claim that names its machine is
                    //    already counted by $byFill on THAT machine — counting it here too puts
                    //    the same money's odometer on two bikes. meterWindowFor's legacy-claims
                    //    query has always had this filter; this one predates stamping and never did.
                    ->when(self::hasCol('t_req_master', 'vehicle_id'),
                        fn ($q) => $q->whereNull('vehicle_id'))
                    ->whereNotNull('meter_at_fill')
                    ->whereRaw($fillExpr . ' > ?', [$floor])
                    ->whereNotIn('status', ['cancelled', 'rejected'])
                    ->whereRaw('COALESCE(expense_date, DATE(created_at)) >= ?', [$w['from']])
                    ->when($w['to'], fn ($q) => $q->whereRaw('COALESCE(expense_date, DATE(created_at)) <= ?', [$w['to']]))
                    ->when($skip, fn ($q) => $q->whereRaw(
                        'COALESCE(expense_date, DATE(created_at)) NOT IN (' . implode(',', array_fill(0, count($skip), '?')) . ')',
                        $skip))
                    ->selectRaw('MAX(' . $fillExpr . ') AS m')
                    ->value('m');

                $best = max($best, $att, $fill);
            }

            return $best > $floor ? $best : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * ⭐ THE MACHINE'S odometer window around a date — floor (highest plausible
     *    reading before it) and ceiling (lowest after) — for validating a claim's
     *    meter. Phase C: `FuelClaimRules::odometerWindow` delegates here when the
     *    rules are on and the rider holds a registered vehicle.
     *
     * ⚠ WHY. The rider-keyed window breaks the moment a bike changes hands:
     *   Danish's own history peaks ~33,700, so his first fill on DCR-799
     *   (~24,800) reads "lower than this bike's 33,700" and is refused — the
     *   right reading rejected because the window was watching the MAN. Worse,
     *   the mistake it exists to catch (typing the OLD bike's meter) would
     *   PASS. Built from the machine's own series, both come out right.
     *
     * Sources, same trust order as `claimsForVehicle`:
     *   • attendance meters of whoever HELD the bike, inside their window;
     *   • claims STAMPED with this vehicle;
     *   • unstamped claims by a keeper inside his window (pre-Aug history).
     *
     * A machine with no readings yet returns [null, null] — no constraint, which
     * is correct for a fresh bike. Errors return null so the caller can fall
     * back to the rider-keyed window rather than losing the check entirely.
     */
    /**
     * ⭐ THE MACHINE'S fuel average, monthly (owner ruling Aug-4).
     *
     * "This month vs the last 3 months" replaced the old single figure, for two
     * reasons the owner named himself:
     *   • a LIFETIME average is impossible — the bikes' first kilometres predate
     *     any tracking, so the number would be a guess wearing a suit;
     *   • the old figure was keyed to the RIDER's month, so Danish — given
     *     DCR-799 on day one — saw a dash even though the BIKE has a month of
     *     real fills and meters behind it.
     *
     * km for a month = the machine's highest plausible reading before the month
     * ends minus the same before it starts — literally two calls to
     * `meterWindowFor`'s floor, so the odometer, the claim validation and this
     * average all read ONE series and cannot disagree.
     *
     * Fuel Rs comes from `claimsForVehicle` (stamped + window-attributed), the
     * same rows the profile lists — the average is always explainable by the
     * list under it.
     *
     * Returns ['fuel_rs','km','rs_per_km'] — km/rs_per_km null when the meters
     * cannot support the answer (too few readings, or a senseless span). A dash
     * is better than a confident fiction.
     */
    public function monthlyFuelStats(int $vehicleId, string $month): array
    {
        $out = ['month' => $month, 'fuel_rs' => 0.0, 'km' => null, 'rs_per_km' => null];
        if (!$this->available()) return $out;

        try {
            $start = Carbon::parse($month . '-01')->startOfMonth();
            $end   = $start->copy()->endOfMonth();

            $fuel = 0.0;
            foreach ($this->claimsForVehicle($vehicleId, $start->format('Y-m-d'), $end->format('Y-m-d')) as $c) {
                if (($c['category'] ?? '') === 'Petrol') $fuel += (float) $c['amount'];
            }
            $out['fuel_rs'] = round($fuel, 2);

            $before = $this->meterWindowContinuousFor($vehicleId, $start->format('Y-m-d'));
            $after  = $this->meterWindowContinuousFor($vehicleId, $end->copy()->addDay()->format('Y-m-d'));
            $lo = $before['floor'] ?? null;
            $hi = $after['floor'] ?? null;

            // Beyond MAX_MONTH_KM the delta is a typo'd meter, not a distance.
            // (Was 2,000 — which real months exceed; see the constant's comment.)
            if ($lo !== null && $hi !== null && $hi > $lo && ($hi - $lo) <= self::MAX_MONTH_KM) {
                $out['km'] = $hi - $lo;
                if ($out['km'] >= 20 && $fuel > 0) {
                    $out['rs_per_km'] = round($fuel / $out['km'], 2);
                }
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('monthlyFuelStats failed', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
            return $out;
        }
    }

    /**
     * The comparison the profile shows: the selected month vs the THREE months
     * before it, POOLED (total fuel ÷ total km — an average of averages would
     * let one weak month distort the figure).
     */
    public function fuelAverages(int $vehicleId, string $month): array
    {
        $this_ = $this->monthlyFuelStats($vehicleId, $month);

        // ⚠ MATCHED MONTHS ONLY for the ratio. Pooling ALL fuel over only the
        //   months whose kilometres were measurable inflated the figure wildly
        //   (BCN: Rs 68,500 over 1,522 km read as Rs 45/km — three months of
        //   fuel, one month of distance). A month with no usable meter delta
        //   contributes to NEITHER side of the division.
        $fuelAll = 0.0; $fuelMatched = 0.0; $km = 0; $haveKm = false;
        try {
            $c = Carbon::parse($month . '-01');
            for ($i = 1; $i <= 3; $i++) {
                $m = $this->monthlyFuelStats($vehicleId, $c->copy()->subMonthsNoOverflow($i)->format('Y-m'));
                $fuelAll += (float) $m['fuel_rs'];
                if ($m['km'] !== null) {
                    $km          += (int) $m['km'];
                    $fuelMatched += (float) $m['fuel_rs'];
                    $haveKm       = true;
                }
            }
        } catch (\Throwable $e) { /* the comparison is a nicety */ }

        return [
            'this_month' => $this_,
            'last3' => [
                'fuel_rs'   => round($fuelAll, 2),   // total SPEND — still honest as a total
                'km'        => $haveKm ? $km : null,
                'rs_per_km' => ($haveKm && $km >= 20 && $fuelMatched > 0)
                    ? round($fuelMatched / $km, 2) : null,
            ],
        ];
    }

    /**
     * ⭐ THE CURRENT KEEPER'S STINT on this machine (owner ruling Aug-4): his
     *    kilometres and his Rs/km since HE took it — set against the bike's
     *    last-3-months baseline this answers "is the rider driving it badly
     *    while the bike itself was fine?". A monthly figure cannot: a mid-month
     *    handover mixes two riders' days into one number.
     *
     * km  = the machine's reading now minus its reading at the handover.
     *       When the stint predates tracking (Arslan has had BCN since January,
     *       and January has no readings) the baseline falls back to the first
     *       plausible reading INSIDE the stint — fewer km than the truth, never
     *       more, so the average errs against flattery.
     * fuel = HIS petrol rows out of `claimsForVehicle` — the same attribution
     *        the list on screen shows, so the figure is always explainable.
     *
     * Null when there is no keeper; km/ratio null while the stint is too new to
     * measure (day one: a dash, not a guess).
     */
    public function keeperStintStats(int $vehicleId): ?array
    {
        if (!$this->available()) return null;
        try {
            $a = $this->keeperOf($vehicleId);
            if (!$a) return null;

            $since = substr((string) $a->assigned_on, 0, 10);
            $uid   = (int) $a->user_id;
            $days  = max(1, Carbon::parse($since)->diffInDays(Carbon::today()) + 1);

            $startWin = $this->meterWindowContinuousFor($vehicleId, $since);
            $endWin   = $this->meterWindowContinuousFor($vehicleId, Carbon::tomorrow()->format('Y-m-d'));
            $base = $startWin['floor'] ?? $startWin['ceil'] ?? null;   // handover meter, or first reading in the stint
            $now  = $endWin['floor'] ?? null;

            $km = null;
            if ($base !== null && $now !== null && $now >= $base) {
                $delta = $now - $base;
                // ~300 km/day is beyond any rider here — beyond it a typo'd
                // meter is masquerading as distance.
                if ($delta <= $days * 300 + 50) $km = $delta;
            }

            $fuel = 0.0;
            foreach ($this->claimsForVehicle($vehicleId, $since, Carbon::today()->format('Y-m-d')) as $c) {
                if (($c['category'] ?? '') === 'Petrol' && (int) ($c['by_user_id'] ?? 0) === $uid) {
                    $fuel += (float) $c['amount'];
                }
            }

            return [
                'user_id'   => $uid,
                'name'      => DB::table('t_sys_user')->where('id', $uid)->value('fullname'),
                'since'     => $since,
                'days'      => $days,
                'km'        => $km,
                'fuel_rs'   => round($fuel, 2),
                'rs_per_km' => ($km !== null && $km >= 20 && $fuel > 0) ? round($fuel / $km, 2) : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('keeperStintStats failed', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * ⭐ THE MACHINE'S MONTH, DAY BY DAY — the receipt behind the month's kilometres
     *    (owner ruling, Aug-5).
     *
     * The profile states "386 km this month" and, until now, nothing stood behind it.
     * Worse, that figure and the rider's own figure disagree by design (the bike counts
     * every kilometre it turned; a rider's row counts only his metered duty days), and
     * a manager had no way to see WHERE the difference came from. This walks the
     * machine's readings in order and shows each stretch and who held it:
     *
     *   on duty       check-in → day close, from the keeper's own attendance
     *   off duty      between one reading and the next, with nobody on shift
     *   unaccounted   a stretch spanning a day he WORKED without a usable reading —
     *                 part work, part commute, unsplittable, so never silently
     *                 credited to either side
     *
     * ⭐ IT MUST ADD UP. The chain is anchored to the SAME two readings
     *    monthlyFuelStats divides by (`meterWindowFor` floor at the month's start and
     *    at the start of the next), so the rows sum to the headline figure. When they
     *    don't — a meter that ran backwards, an implausible jump — `reconciles` comes
     *    back false and the screen says so rather than quietly showing a total that
     *    disagrees with the number above it.
     *
     * Newest day first, matching the rider drill-down a manager already reads.
     */
    public function monthDays(int $vehicleId, string $month): array
    {
        $out = [
            'month' => $month, 'days' => [], 'reconciles' => false, 'month_km' => null,
            'totals' => ['on_duty' => 0, 'off_duty' => 0, 'unaccounted' => 0, 'total' => 0,
                         'days_counted' => 0, 'no_meter_days' => 0],
        ];
        if (!$this->available()) return $out;

        // ⭐⭐ Aug-2026 — THE WALK NOW LIVES IN `MachineAttribution`, and this method
        //    reshapes its answer into the payload both front ends already read.
        //
        //    Two reasons it had to move. First, the rider view and this view were two
        //    different walks over the same odometer and could disagree about the same
        //    day. Second, the walk below keyed its rows by DATE ALONE — so on the day
        //    a bike changed hands the incoming rider's row OVERWROTE the outgoing
        //    one, and the machine's chain silently lost the morning half of exactly
        //    the day that needed explaining.
        //
        //    The old walk is kept below as the fallback for when the engine cannot
        //    answer (rollback lever off, a build failure); it is byte-compatible.
        $viaEngine = $this->monthDaysFromEngine($vehicleId, $month);
        if ($viaEngine !== null) return $viaEngine;

        try {
            $start = Carbon::parse($month . '-01')->startOfMonth();
            $end   = $start->copy()->endOfMonth();
            $from  = $start->format('Y-m-d');
            $to    = $end->format('Y-m-d');

            $windows = $this->attributionWindows($vehicleId);
            $names   = $this->keeperNames($windows);

            // --- every keeper's attendance, clamped to the days he actually held it ---
            $rows = [];
            foreach ($windows as $w) {
                $lo = max($from, $w['from']);
                $hi = $w['to'] ? min($to, $w['to']) : $to;
                if ($lo > $hi) continue;

                // ⚠ scopeWindowRows: a day the manager has since moved to ANOTHER
                //   machine is no longer this one's — otherwise a borrowed bike's
                //   readings stay in the lender's chain and both bikes read wrong.
                $att = $this->scopeWindowRows(DB::table('t_ops_attendance')
                    ->where('user_id', $w['user_id'])
                    ->whereBetween('attendance_date', [$lo, $hi]), $vehicleId)
                    ->orderBy('attendance_date')
                    ->get(['attendance_date', 'meter_start', 'meter_end', 'meter_home',
                           'login_time', 'logout_time', 'leave_type']);

                foreach ($att as $a) {
                    $d = substr((string) $a->attendance_date, 0, 10);
                    $rows[$d] = [
                        'date'        => $d,
                        'user_id'     => $w['user_id'],
                        'keeper'      => $names[$w['user_id']] ?? null,
                        // Held it before the registry recorded the handover.
                        'assumed'     => !empty($w['assumed']) && $d < $w['real_from'],
                        'meter_start' => $a->meter_start !== null ? (int) $a->meter_start : null,
                        'meter_end'   => $a->meter_end   !== null ? (int) $a->meter_end   : null,
                        'meter_home'  => $a->meter_home  !== null ? (int) $a->meter_home  : null,
                        'leave'       => $a->leave_type ?: null,
                        'worked'      => $a->login_time !== null,
                        'claims'      => [],
                    ];
                }
            }

            // ⭐ Days the manager explicitly gave to THIS machine — the historical
            //   correction ("Danish was on Waseem's bike that day"). Added AFTER the
            //   windows so an override always wins the date, which is the whole
            //   point of it being an override.
            if ($this->hasDayOverride()) {
                $ovNames = [];
                $ovRows = DB::table('t_ops_attendance')
                    ->where('vehicle_id', $vehicleId)
                    ->whereBetween('attendance_date', [$from, $to])
                    ->orderBy('attendance_date')
                    ->get(['user_id', 'attendance_date', 'meter_start', 'meter_end', 'meter_home',
                           'login_time', 'logout_time', 'leave_type']);
                if ($ovRows->count()) {
                    $ovNames = DB::table('t_sys_user')
                        ->whereIn('id', $ovRows->pluck('user_id')->unique()->all())
                        ->pluck('fullname', 'id')->toArray();
                }
                foreach ($ovRows as $a) {
                    $d   = substr((string) $a->attendance_date, 0, 10);
                    $uid = (int) $a->user_id;
                    $rows[$d] = [
                        'date'        => $d,
                        'user_id'     => $uid,
                        'keeper'      => $ovNames[$uid] ?? ($names[$uid] ?? null),
                        'assumed'     => false,          // recorded by a manager, never inferred
                        'overridden'  => true,
                        'meter_start' => $a->meter_start !== null ? (int) $a->meter_start : null,
                        'meter_end'   => $a->meter_end   !== null ? (int) $a->meter_end   : null,
                        'meter_home'  => $a->meter_home  !== null ? (int) $a->meter_home  : null,
                        'leave'       => $a->leave_type ?: null,
                        'worked'      => $a->login_time !== null,
                        'claims'      => $rows[$d]['claims'] ?? [],
                    ];
                }
            }

            // --- the machine's claims land on their day (a claim can fall on a day
            //     with no attendance row at all — a day off, or a fill after handover) ---
            foreach ($this->claimsForVehicle($vehicleId, $from, $to) as $c) {
                $d = $c['date'];
                if (!isset($rows[$d])) {
                    $keeperId = $this->keeperOnDate($windows, $d);
                    $rows[$d] = [
                        'date' => $d, 'user_id' => $keeperId,
                        'keeper' => $keeperId ? ($names[$keeperId] ?? null) : ($c['by_name'] ?? null),
                        'assumed' => false,
                        'meter_start' => null, 'meter_end' => null, 'meter_home' => null,
                        'leave' => null, 'worked' => false, 'claims' => [],
                    ];
                }
                $rows[$d]['claims'][] = $c;
            }

            ksort($rows);

            // --- walk the chain forward, anchored where the money figure is anchored ---
            // ⭐ Meter replaced (Oct-2026): the chain is walked on the machine's CONTINUOUS scale
            //   (`$ms`/`$me`/`$mh` and `meter_cont`), so the day a new meter starts at 0 is an
            //   ordinary day instead of "the meter went backwards". The rows still SHOW the
            //   figures as typed. All identity for a machine never replaced.
            $openWin = $this->meterWindowContinuousFor($vehicleId, $from);
            $prev    = $openWin['floor'] ?? null;      // the reading this month opens on
            $prevDay = null;
            $dirty   = false;                          // a worked-but-unmetered day is behind us

            $days = [];
            foreach ($rows as $d => $r) {
                $ms = MeterReplacement::toContinuous($vehicleId, $r['meter_start'], $d);
                $me = MeterReplacement::toContinuous($vehicleId, $r['meter_end'], $d);
                $mh = MeterReplacement::toContinuous($vehicleId, $r['meter_home'], $d);
                $sane = $ms !== null && $me !== null
                    && $ms > self::MIN_METER
                    && $me >= $ms
                    && ($me - $ms) <= self::MAX_DAY_KM;

                // The reading this day OPENS on: the check-in, or (no attendance) the
                // day's highest claim meter — the only reading that day leaves behind.
                $claimMeters = array_values(array_filter(array_map(
                    fn ($c) => $c['meter_cont'] ?? ($c['meter'] ?? null), $r['claims']),
                    fn ($m) => $m !== null && $m > self::MIN_METER));
                $opensAt = $sane ? $ms : ($claimMeters ? min($claimMeters) : null);
                $closesAt = $sane
                    ? max($me, $mh ?? 0)
                    : ($claimMeters ? max($claimMeters) : null);

                $before = null; $beforeKind = null; $anomaly = null;
                if ($opensAt !== null && $prev !== null) {
                    $gap = $opensAt - $prev;
                    if ($gap < 0)                    { $anomaly = 'meter_back'; }
                    elseif ($gap > self::MAX_GAP_KM) { $anomaly = 'implausible'; }
                    elseif ($gap > 0) {
                        $before     = $gap;
                        $beforeKind = $dirty ? 'unaccounted' : 'off_duty';
                    }
                }

                // The ride home after the office close is outside the shift.
                $homeKm = ($sane && $mh !== null && $mh > $me)
                    ? min($mh - $me, self::MAX_DAY_KM) : 0;

                $work = $sane ? $me - $ms : null;

                // Only a day he WORKED can be "missing" a reading — leave is a state,
                // not a failure, and it must not poison the next gap.
                $status = $sane ? 'ok'
                    : ($r['leave'] ? 'leave'
                    : ($r['worked'] ? 'no_meter'
                    : ($r['claims'] ? 'claim_only' : 'no_attendance')));
                if ($status === 'no_meter') $dirty = true;
                elseif ($sane)              $dirty = false;

                $days[] = [
                    'date'        => $d,
                    'keeper'      => $r['keeper'],
                    'keeper_user_id' => $r['user_id'],
                    'assumed'     => $r['assumed'],
                    'overridden'  => !empty($r['overridden']),
                    'meter_start' => $r['meter_start'],
                    'meter_end'   => $r['meter_end'],
                    'work_km'     => $work,
                    // The stretch from the previous reading up to this day's first one.
                    'gap_km'      => $before,
                    'gap_kind'    => $beforeKind,
                    'gap_since'   => ($before !== null && $prevDay !== null
                                      && Carbon::parse($prevDay)->diffInDays(Carbon::parse($d)) > 1)
                                      ? $prevDay : null,
                    // Office close → home, on the days the meter-out was taken at home.
                    'home_km'     => $homeKm ?: null,
                    'status'      => $status,
                    'anomaly'     => $anomaly,
                    'claims'      => $r['claims'],
                ];

                if ($work !== null)   { $out['totals']['on_duty'] += $work; $out['totals']['days_counted']++; }
                if ($homeKm)          { $out['totals']['off_duty'] += $homeKm; }
                if ($beforeKind === 'off_duty')    $out['totals']['off_duty']    += $before;
                if ($beforeKind === 'unaccounted') $out['totals']['unaccounted'] += $before;
                if ($status === 'no_meter')        $out['totals']['no_meter_days']++;

                if ($closesAt !== null && ($prev === null || $closesAt >= $prev)) {
                    $prev = $closesAt; $prevDay = $d;
                }
            }

            // --- the tail: from the last reading of the month to where the next month
            //     opens. Without it the rows would fall short of the headline figure on
            //     any month whose last movement was never read until the 1st. ---
            $closeWin = $this->meterWindowContinuousFor($vehicleId, $end->copy()->addDay()->format('Y-m-d'));
            $closesOn = $closeWin['floor'] ?? null;
            if ($prev !== null && $closesOn !== null && $closesOn > $prev
                && ($closesOn - $prev) <= self::MAX_GAP_KM) {
                $tail = $closesOn - $prev;
                $days[] = [
                    'date' => $to, 'keeper' => null, 'keeper_user_id' => null, 'assumed' => false,
                    'meter_start' => null, 'meter_end' => null, 'work_km' => null,
                    'gap_km' => $tail, 'gap_kind' => $dirty ? 'unaccounted' : 'off_duty',
                    'gap_since' => $prevDay, 'home_km' => null,
                    'status' => 'tail', 'anomaly' => null, 'claims' => [],
                ];
                if ($dirty) $out['totals']['unaccounted'] += $tail;
                else        $out['totals']['off_duty']    += $tail;
            }

            $out['totals']['total'] = $out['totals']['on_duty']
                + $out['totals']['off_duty'] + $out['totals']['unaccounted'];

            // ⭐ The honesty check: these rows must equal the figure printed above them.
            //   A month with nothing measurable (no usable readings either side) is not
            //   a mismatch — both sides say "nothing measured", and they agree on that.
            $out['month_km']   = $this->monthlyFuelStats($vehicleId, $month)['km'];
            $out['reconciles'] = $out['month_km'] === null
                ? $out['totals']['total'] === 0
                : $out['month_km'] === $out['totals']['total'];

            $out['days'] = array_reverse($days);   // newest first, like the rider view
            return $out;
        } catch (\Throwable $e) {
            Log::warning('monthDays failed', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
            return $out;
        }
    }

    /**
     * The engine's month, reshaped into this method's long-standing contract.
     *
     * Returns null when the engine has no opinion, which is the caller's signal to
     * run the original walk — so a rollback needs no code change.
     *
     * ⭐ ONE VISIBLE DIFFERENCE, AND IT IS THE POINT: a handover day now yields TWO
     *   rows (the outgoing rider's morning, the incoming rider's evening) instead of
     *   one row that silently discarded the first half.
     */
    private function monthDaysFromEngine(int $vehicleId, string $month): ?array
    {
        try {
            if (strtoupper((string) $this->cfg('MACHINE_ATTRIBUTION', 'Y')) !== 'Y') return null;

            $v = (new MachineAttribution())->forVehicle($vehicleId, $month);
            if ($v === null) return null;

            $names = [];
            $days  = [];
            foreach ($v['days'] as $row) {
                $days[] = [
                    'date'           => $row['date'],
                    'keeper'         => $row['keeper'],
                    'keeper_user_id' => $row['user_id'],
                    'assumed'        => false,
                    'overridden'     => false,
                    'meter_start'    => $row['meter_start'],
                    'meter_end'      => $row['meter_end'],
                    'work_km'        => $row['work_km'],
                    'gap_km'         => $row['gap_km'],
                    // 'off_duty' | 'unaccounted' | 'shared' | 'transfer' | 'split'
                    'gap_kind'       => $row['gap_kind'],
                    'gap_since'      => $row['gap_since'],
                    // Who the stretch was between, when it belongs to neither of them.
                    'gap_from'       => $this->nameOf($row['gap_from_user'] ?? null, $names),
                    'gap_to'         => $this->nameOf($row['gap_to_user'] ?? null, $names),
                    'home_km'        => $row['home_km'] ?: null,
                    'status'         => $row['status'],
                    // A single reading on a day the bike changed hands is the NORMAL
                    // shape, not a missed meter — the day list must not scold for it.
                    'handover_day'   => !empty($row['handover_day']),
                    'partial'        => !empty($row['partial']),
                    'anomaly'        => $row['anomaly'] ?? null,
                    'claims'         => $row['claims'],
                ];
            }
            usort($days, fn ($a, $b) => strcmp($b['date'], $a['date']));   // newest first

            $t = $v['totals'];
            return [
                'month'      => $month,
                'days'       => $days,
                'reconciles' => $v['reconciles'],
                'month_km'   => $v['span'],
                'totals'     => [
                    'on_duty'      => $t['on_duty'],
                    'off_duty'     => $t['off_duty'],
                    'unaccounted'  => $t['unaccounted'],
                    'shared'       => $t['shared'],
                    'transfer'     => $t['transfer'],
                    'total'        => $t['total'],
                    'days_counted' => $t['days_counted'],
                    'no_meter_days' => $t['no_meter_days'],
                ],
                // ⭐ WHO RODE IT THIS MONTH — the machine's mirror of the rider view's
                //   "his machines" strip. Same engine, so the two can never disagree.
                'riders'     => $this->ridersOfMonth($vehicleId, $month),
                // ⭐⭐ ONE CARD PER DATE (owner review, Aug-13) — the day read top to
                //   bottom. ADDITIVE: the flat `days` array above is untouched
                //   because the CURRENT APK renders it, and an app in the field must
                //   not break the moment this uploads.
                'day_cards'  => $v['day_cards'],
                'events'     => $v['events'],
            ];
        } catch (\Throwable $e) {
            Log::warning('monthDaysFromEngine failed', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Everyone who rode this machine this month, with their own share of it.
     * Shared and transit kilometres are listed once, on their own line, because
     * they belong to the MACHINE and to neither rider.
     */
    public function ridersOfMonth(int $vehicleId, string $month): array
    {
        try {
            $eng = new MachineAttribution();
            $v = $eng->forVehicle($vehicleId, $month);
            if ($v === null) return [];

            $per = [];
            foreach ($v['legs'] as $l) {
                if ($l['user_id'] === null) continue;
                $uid = $l['user_id'];
                if (!isset($per[$uid])) {
                    $per[$uid] = ['user_id' => $uid, 'name' => null, 'work_km' => 0,
                                  'offduty_km' => 0, 'unaccounted_km' => 0, 'shared_days' => 0,
                                  'fuel_rs' => 0.0, 'maint_rs' => 0.0];
                }
                $map = ['on_duty' => 'work_km', 'off_duty' => 'offduty_km',
                        'unaccounted' => 'unaccounted_km'];
                if (isset($map[$l['kind']])) $per[$uid][$map[$l['kind']]] += $l['km'];
            }

            // Days each man was a party to a handover.
            foreach ($v['legs'] as $l) {
                if ($l['kind'] !== 'shared') continue;
                foreach ([$l['from_user'], $l['to_user']] as $uid) {
                    if ($uid === null) continue;
                    if (!isset($per[$uid])) {
                        $per[$uid] = ['user_id' => $uid, 'name' => null, 'work_km' => 0,
                                      'offduty_km' => 0, 'unaccounted_km' => 0, 'shared_days' => 0,
                                      'fuel_rs' => 0.0, 'maint_rs' => 0.0];
                    }
                    $per[$uid]['shared_days']++;
                }
            }

            foreach ($v['spend'] as $uid => $s) {
                if (!isset($per[$uid])) {
                    $per[$uid] = ['user_id' => $uid, 'name' => null, 'work_km' => 0,
                                  'offduty_km' => 0, 'unaccounted_km' => 0, 'shared_days' => 0,
                                  'fuel_rs' => 0.0, 'maint_rs' => 0.0];
                }
                $per[$uid]['fuel_rs']  += $s['fuel_rs'];
                $per[$uid]['maint_rs'] += $s['maint_rs'];
            }

            $names = [];
            foreach ($per as $uid => &$row) $row['name'] = $this->nameOf($uid, $names);
            unset($row);

            usort($per, fn ($a, $b) => $b['work_km'] <=> $a['work_km']);

            return [
                'riders'      => array_values($per),
                'shared_km'   => $v['totals']['shared'],
                'transfer_km' => $v['totals']['transfer'],
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * ⭐ THE MACHINE'S SERVICE RECORD (owner ask, Aug-13).
     *
     * The multi-month "past services" list only ever existed on the RIDER's
     * drill-down, keyed to `requester_user_id` — so handing a bike over split its
     * own history between two people and neither list was the bike's. This is the
     * same list, attributed to the machine through `claimsForVehicle`, which means
     * stamped rows, window attribution, day overrides and the pre-registry backfill
     * all count here exactly as they count in the money view.
     *
     * Deliberately NOT limited to clock-resetting types: a manager reading a bike's
     * history wants the punctures and the brake shoes too.
     */
    /**
     * ⭐⭐ WHAT HAS THIS MACHINE COST — the question the vehicle page could not answer.
     *
     * ⚠⚠ WHY IT EXISTS. The page showed ONE month, and lumped every non-fuel claim under a
     *    single "maintenance" figure. So "what is my scheduled upkeep costing me versus things
     *    breaking, this month and over the machine's life" could not be read off the screen at
     *    all — it had to be added up by hand from a list capped at 24 rows.
     *
     * THE BUCKETS (owner ruling, 3-Sep):
     *   • REGULAR  — a named type whose bucket is `regular`; legacy untyped rows whose
     *                `service_type` is `oil_change`.
     *   • REPAIRS  — a named type whose bucket is `repair`; legacy `repair` / `general`.
     *   • FUEL     — kept SEPARATE, never folded in: the machine's Rs/km averages are computed
     *                from it (owner: "I need this to calculate the machine average").
     *   • UNCLASSIFIED — a maintenance claim that names neither. 109 of the 140 legacy rows are
     *                these. ⚠ Shown as its own figure, NEVER silently folded into a bucket:
     *                a total that quietly absorbs what it could not identify is a lie.
     *
     * ⚠ APPROVED money only in the bucket totals; anything still `pending` is reported
     *   alongside as `waiting_rs` so an unapproved bill can never read as money spent.
     *   Rejected and cancelled are excluded entirely.
     *
     * @return array{windows: array<string, array<string, mixed>>}
     */
    /**
     * Which bucket a claim's money belongs to. ⚠ ONE definition, used by the cost tiles and by
     * the history rows — two copies would let a row be counted as Repairs in the total and
     * shown as Regular in the list.
     */
    private static function bucketOfClaim(array $c, array $bucketMap): string
    {
        if (($c['category'] ?? '') === 'Petrol') return 'fuel';
        $tid = $c['maintenance_type_id'] ?? null;
        $b   = $tid && isset($bucketMap[$tid]) ? $bucketMap[$tid] : null;
        if ($b === 'regular') return 'regular';
        if ($b === 'repair')  return 'repairs';
        $st = $c['service_type'] ?? null;                       // legacy, pre-picker rows
        if ($st === 'oil_change')                          return 'regular';
        if (in_array($st, ['repair', 'general'], true))    return 'repairs';
        return 'unclassified';
    }

    /**
     * @param string|null $month 'YYYY-MM' — which month the 'month' window covers.
     *
     * ⚠⚠ The 'month' window used to be hard-wired to the CURRENT calendar month whatever
     *    month was being viewed. Once the vehicle panel gained its own month stepper that
     *    became a lie you could see: a tile headed “August 2026 — Nothing filed” sitting
     *    directly above August rows worth Rs 400. The window now follows the month asked for,
     *    and a PAST month is closed at both ends — open-ended would quietly make it
     *    “August onwards” and double-count September.
     */
    public function costSummaryFor(int $vehicleId, ?string $month = null): array
    {
        $blank = ['regular_rs' => 0.0, 'repairs_rs' => 0.0, 'fuel_rs' => 0.0,
                  'unclassified_rs' => 0.0, 'waiting_rs' => 0.0, 'count' => 0];
        $today  = Carbon::today();
        $mStart = ($month && preg_match('/^\d{4}-\d{2}$/', $month))
            ? Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfDay()
            : $today->copy()->startOfMonth();
        // Only a past month needs closing; the running month ends at today by definition.
        $mEnd = $mStart->copy()->endOfMonth();
        $ends = ['month' => $mEnd->format('Y-m-d')];
        $starts = [
            'month'    => $mStart,
            'quarter'  => $today->copy()->subMonthsNoOverflow(2)->startOfMonth(),
            'year'     => $today->copy()->startOfYear(),
            'lifetime' => null,                      // everything this machine has ever carried
        ];
        $out = [];
        foreach ($starts as $k => $_) $out[$k] = $blank;

        try {
            // ⚠ Buckets come from the manager's own type list — never from the type NAME.
            $buckets = [];
            if (self::hasTbl('t_fleet_maintenance_types')) {
                $buckets = DB::table('t_fleet_maintenance_types')->pluck('bucket', 'id')->all();
            }
            // ⚠ A claim already spoken for by a service log is NOT skipped here — unlike the
            //   history list, this is about MONEY, and the money is on the claim. Skipping it
            //   would under-report the machine's cost by every bill filed with its service.
            foreach ($this->allClaimsFor($vehicleId) as $c) {
                $status = (string) ($c['status'] ?? '');
                if (!in_array($status, ['approved', 'pending'], true)) continue;   // rejected/cancelled never count
                $amt = (float) ($c['amount'] ?? 0);
                if ($amt <= 0) continue;
                $date = substr((string) ($c['date'] ?? ''), 0, 10);
                if ($date === '') continue;

                // ⚠ THE SAME classifier the history rows use — see bucketOfClaim. Two copies
                //   would let one row be a Repair in the total and Regular in the list.
                $field = self::bucketOfClaim($c, $buckets) . '_rs';

                foreach ($starts as $k => $from) {
                    if ($from !== null && $date < $from->format('Y-m-d')) continue;
                    if (isset($ends[$k]) && $date > $ends[$k]) continue;   // see the note on $mEnd
                    if ($status === 'pending') {
                        $out[$k]['waiting_rs'] += $amt;
                    } else {
                        $out[$k][$field] += $amt;
                    }
                    $out[$k]['count']++;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('costSummaryFor failed', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
        }

        foreach ($out as $k => $w) {
            foreach (['regular_rs', 'repairs_rs', 'fuel_rs', 'unclassified_rs', 'waiting_rs'] as $f) {
                $out[$k][$f] = round($w[$f], 2);
            }
            // What a manager actually asks: upkeep + breakage, fuel read separately.
            $out[$k]['maintenance_rs'] = round($w['regular_rs'] + $w['repairs_rs'] + $w['unclassified_rs'], 2);
        }
        return ['windows' => $out];
    }

    /**
     * ⚠ `$allTime` lifts the 24-month window (owner ask, 3-Sep: "I can't see lifetime").
     *   Still capped by `$limit` — a machine with hundreds of rows must not blow the payload —
     *   and still newest-first, so "All time" means "as far back as this many rows reach".
     */
    /**
     * 🧾⭐⭐ "WORK WAS DONE AND NOBODY HAS ENTERED WHAT IT COST" — the interim nag, until the
     *    proper unpaid-visits screen is built (owner ask, 11-Sep-2026).
     *
     * ⚠⚠ THIS DATE IS THE WHOLE POINT OF THE FEATURE. Recording a service without an amount only
     *    became the ORDINARY case on 11-Sep, when the photo stopped needing a bill beside it —
     *    the rider is handed a receipt at the workshop, pays nothing, and a manager enters the
     *    figure days later. Every bill-less row before that is history nobody is going to chase,
     *    and flagging it would bury the handful that genuinely need action under years of noise.
     *    The owner asked for the line to be drawn at 1 September, so it is drawn ONCE, here, and
     *    every surface reads it rather than each picking its own.
     */
    public const AMOUNT_NAG_FROM = '2026-09-01';

    /**
     * Does this history row still need somebody to enter what it cost?
     *
     * ⚠ A row is only nagged about when ALL of these hold: it is a hand-recorded SERVICE (a claim
     *   carries its own money by definition), it has no live bill, it has no amount, and it falls
     *   on or after the cut-off. A REJECTED bill counts as no bill — the money did not clear, so
     *   somebody does still have to deal with it.
     */
    public static function rowNeedsAmount(array $row): bool
    {
        if (empty($row['manual']))                     return false;   // a claim is already money
        /**
         * ⚠⚠ ONLY A LIVE BILL SETTLES IT (pre-deploy C1, 29-Sep-2026). `bill_id` stays on the row
         *    whatever the claim's fate, so a REJECTED or CANCELLED bill used to switch the nag off
         *    — and its Rs figure (which never cleared) counted as "it has a figure". `bill_live`
         *    is the one live-status rule; a row built without it keeps the old reading.
         */
        $live = array_key_exists('bill_live', $row) ? !empty($row['bill_live']) : !empty($row['bill_id']);
        if ($live)                                     return false;   // a live bill exists
        if (empty($row['bill_id']) && (float) ($row['amount'] ?? 0) > 0) return false;   // it has a figure
        $d = substr((string) ($row['date'] ?? ''), 0, 10);
        return $d !== '' && $d >= self::AMOUNT_NAG_FROM;
    }

    public function serviceHistoryFor(int $vehicleId, int $limit = 24, bool $allTime = false): array
    {
        if (!$this->available()) return [];

        try {
            // Far enough back to be a history, cheap enough to stay one query set.
            // ⚠ 24 months by default; the whole life of the machine when asked for.
            $from = $allTime
                ? '2000-01-01'
                : Carbon::now()->subMonths(24)->startOfMonth()->format('Y-m-d');
            $to   = Carbon::now()->endOfMonth()->format('Y-m-d');

            /**
             * ⚠⚠ A CLAIM THAT ITS SERVICE LOG ALREADY SPEAKS FOR IS DROPPED HERE (Sep-3) —
             *    otherwise a manager who recorded the work and its bill in one action would
             *    see his single job listed twice, once as "recorded · no bill" and once as an
             *    Rs row, with no way to tell they were the same thing. The log row survives
             *    and now carries the money (see `bill_amount` below), so nothing is lost.
             */
            // ⚠ Same rule as the evidence engine above — a REJECTED bill is not hidden, it is
            //   shown, because the manager has to see that it did not clear.
            $billed = ServiceRecordService::liveBillLinks();

            // The manager's own bucket list — one lookup, shared by both row kinds below so
            // the filter chips and the cost tiles can never classify the same row differently.
            $bucketMap = [];
            if (self::hasTbl('t_fleet_maintenance_types')) {
                $bucketMap = DB::table('t_fleet_maintenance_types')->pluck('bucket', 'id')->all();
            }

            $rows = array_values(array_filter(
                $this->claimsForVehicle($vehicleId, $from, $to),
                fn ($c) => $c['category'] === 'Maintenance'
                        && !(isset($c['id']) && isset($billed[(int) $c['id']]))
            ));

            $out = array_map(fn ($c) => [
                'date'     => $c['date'],
                'amount'   => $c['amount'],
                'status'   => $c['status'],
                'kind'     => $c['kind'],
                'type'     => $c['maintenance_type_id'],
                'meter'    => $c['meter'],
                'by_name'  => $c['by_name'],
                // false = attributed by who held the bike, not stamped at filing.
                'stamped'  => $c['stamped'],
                'assumed'  => $c['assumed'],
                'log_id'   => null,      // not a service log — see `req_id` below
                /**
                 * ⭐ THE CLAIM'S OWN ID (Sep-3). Its reading can now be corrected in place —
                 *   approved or not — through FleetFuelController::correctClaimReading, which
                 *   touches ONLY the odometer and which job it was. The money fields are still
                 *   editClaim's, and still refused once approved.
                 * ⚠ Until this shipped the row reached the screen with no identity at all, so
                 *   the panel could show "Oil Change 767 km overdue" off a typo nobody could
                 *   reach. `log_id` vs `req_id` is what tells the UI which door to open.
                 */
                'req_id'   => isset($c['id']) ? (int) $c['id'] : null,
                // ⭐ C1 (29-Sep-2026): the row IS a bill — does it still stand? Same rule as a
                //   job row's `bill_live`, so a visit total can leave rejected money out.
                'bill_live' => in_array((string) ($c['status'] ?? ''), ServiceRecordService::LIVE_BILL_STATUSES, true),
                /**
                 * ⭐ WHICH KIND OF SPEND THIS IS (owner ask, 3-Sep) — so the page can separate
                 *   scheduled upkeep from things breaking, and the filter chips have something
                 *   to filter on. Derived exactly as costSummaryFor does it, from the manager's
                 *   own type list, never from the type name.
                 * ⚠ `unclassified` is a real answer, not a fallback to hide behind: 109 legacy
                 *   rows genuinely say nothing about which kind of work they paid for.
                 */
                'bucket'   => self::bucketOfClaim($c, $bucketMap),
                // ⭐ The visit this bill belongs to (29-Sep-2026) — same machine, day and
                //   odometer — so separately-billed jobs of one trip sit under one header.
                'visit_key' => $c['meter'] !== null
                    ? 'v' . $vehicleId . '|' . $c['date'] . '|' . (int) $c['meter']
                    : 'c' . (int) ($c['id'] ?? 0),
            ], $rows);

            /**
             * ⭐⭐ MANUALLY RECORDED SERVICES BELONG IN THIS LIST TOO (owner ask, 3-Sep).
             *
             * ⚠⚠ They were nowhere in the product. A "Record service" row fed every countdown
             *    but appeared on NO screen, so a wrong one could not be spotted, let alone
             *    fixed — which is exactly how log #8 sat misfiled and needed hand-written SQL
             *    to find. A record the system acts on must be a record somebody can see.
             *
             * `log_id` is what makes a row correctable: the renderer offers Edit / Remove only
             * where it is present, because a CLAIM carries money and is amended through the
             * claims flow instead.
             *
             * ⚠ Attributed to this machine by the SAME rule the countdowns use
             *   (`vehicleForDay` on the service date), so the list and the schedule can never
             *   disagree about which bike a record belongs to.
             */
            if (self::hasTbl('t_fleet_service_log')) {
                $resolver = new VehicleResolver();
                $manualQ = DB::table('t_fleet_service_log as l')
                    ->leftJoin('t_fleet_maintenance_types as t', 't.id', '=', 'l.maintenance_type_id')
                    ->leftJoin('t_sys_user as u', 'u.id', '=', 'l.created_by')
                    ->whereDate('l.service_date', '>=', $from);

                /**
                 * ⚠⚠ ONE SELECT LIST, BUILT UP — NEVER addSelect() BEFORE get([...]).
                 *    Laravel keeps the columns already on the builder and DISCARDS the ones
                 *    passed to get(), so an addSelect here silently reduced the query to the
                 *    three bill columns: `user_id` came back null, every row failed the
                 *    machine check below, and the whole Past-services list rendered EMPTY.
                 *    Caught end-to-end, not by a linter.
                 */
                $cols = array_merge(['l.id', 'l.user_id', 'l.meter', 'l.service_date', 'l.note',
                                     'l.maintenance_type_id', 't.type_name', 'u.fullname as by_name'],
                                    ServiceRecordService::logVehicleCols('l.'));
                // The bill, when the manager filed one with the service. LEFT-joined so a
                // bill-less record is unaffected, and schema-guarded so this is inert
                // before the link column exists.
                if (Schema::hasColumn('t_fleet_service_log', 'request_id')) {
                    $manualQ = $manualQ->leftJoin('t_req_master as rq', 'rq.id', '=', 'l.request_id');
                    $cols[] = 'l.request_id';
                    $cols[] = 'rq.amount as bill_amount';
                    $cols[] = 'rq.status as bill_status';
                }
                // 📷 The proof photo, once the Sep-12 SQL has run. Selected through the SAME
                //    built-up list for the reason spelled out above — an addSelect here would
                //    silently empty the whole Past-services panel.
                if (ServiceRecordService::logKeepsPhoto()) {
                    $cols[] = 'l.photo_path';
                }
                $manual = $manualQ->get($cols);
                foreach ($manual as $m) {
                    $d = substr((string) $m->service_date, 0, 10);
                    // ⭐ Stamp first, so Past services lists exactly what the countdown counted.
                    if (ServiceRecordService::logVehicleOf($m, $resolver) !== $vehicleId) continue;
                    $out[] = [
                        'date'    => $d,
                        /**
                         * ⭐ THE BILL, WHEN THERE IS ONE (Sep-3). These rows used to be
                         *   bill-less by definition. A manager can now record the work and its
                         *   cost in one action, and when he does, the claim half is dropped
                         *   from this list (see `$billed` above) — so this row has to carry
                         *   the money, or it would vanish from the history entirely.
                         */
                        'amount'  => isset($m->bill_amount) ? (float) $m->bill_amount : 0.0,
                        'status'  => isset($m->bill_status) && $m->bill_status
                                        ? (string) $m->bill_status : 'recorded',
                        'kind'    => $m->type_name ?: 'Service',
                        'type'    => $m->maintenance_type_id ? (int) $m->maintenance_type_id : null,
                        'meter'   => $m->meter !== null ? (int) $m->meter : null,
                        'by_name' => $m->by_name,
                        'stamped' => true,
                        'assumed' => false,
                        'manual'  => true,
                        'note'    => $m->note,
                        'log_id'  => (int) $m->id,      // ← correctable
                        /**
                         * 📷 THE PROOF PHOTO (11-Sep-2026) — the receipt the rider was handed
                         *    at the workshop, kept whether or not any money has been entered
                         *    yet. This is what a manager looks at when he types the amount days
                         *    later, so it must ride on the history row itself.
                         * ⚠ Schema-guarded upstream (`logKeepsPhoto`), so this is simply null
                         *   until the Sep-12 SQL runs.
                         */
                        'photo_url' => !empty($m->photo_path ?? null)
                            ? asset('public-storage/' . ltrim((string) $m->photo_path, '/'))
                            : null,
                        // ⚠ Same shape both ways: a consumer must never have to know which
                        //   branch produced a row to read a key off it.
                        'req_id'  => null,
                        // ⭐ Its bill, if one was filed with it — what makes the pair one row.
                        'bill_id' => isset($m->request_id) && $m->request_id ? (int) $m->request_id : null,
                        /**
                         * ⭐ …and whether that bill still STANDS (pre-deploy C1, 29-Sep-2026).
                         *   `bill_id` is kept as it was for every installed APK, but it survives
                         *   a rejected or cancelled claim, so it cannot answer "is this paid for?".
                         *   This can — the ONE live-status rule (`LIVE_BILL_STATUSES`) — and the
                         *   screens offer "Add the bill" again and nag for the amount when false.
                         */
                        'bill_live' => isset($m->request_id) && $m->request_id
                            && in_array((string) ($m->bill_status ?? ''), ServiceRecordService::LIVE_BILL_STATUSES, true),
                        // ⚠ WHOSE service this is. A bill belongs to a requester, so "Add the
                        //   bill" cannot open the form without it — and the machine's keeper
                        //   today may not be the man the work was recorded against.
                        'rider_id' => (int) $m->user_id,
                        // A recorded service is scheduled work by definition unless its type
                        // says otherwise — same map, so the chips agree with the cost tiles.
                        'bucket'  => $m->maintenance_type_id && isset($bucketMap[$m->maintenance_type_id])
                                        ? $bucketMap[$m->maintenance_type_id] : 'regular',
                        // ⭐ ONE VISIT = same machine, day and odometer (29-Sep-2026) — the key
                        //   `ServiceRecordService::visitKeyOf` derives, so screens group jobs the
                        //   way the engine does and a correction knows what it moves together.
                        'visit_key' => 'v' . $vehicleId . '|' . $d . '|' . (int) $m->meter,
                        'bill_amount' => isset($m->bill_amount) ? (float) $m->bill_amount : null,
                    ];
                }
            }

            // Newest first across BOTH kinds, so the list reads as one history.
            /**
             * 🧾 Stamp the "somebody still has to enter what this cost" flag onto every row, from
             *    ONE rule (`rowNeedsAmount`), so the web list, the phone list and any counter
             *    above them cannot disagree about which rows are outstanding.
             */
            foreach ($out as &$row) { $row['needs_amount'] = self::rowNeedsAmount($row); }
            unset($row);

            /**
             * 🧾 A JOB WHOSE VISIT ALREADY HAS A LIVE BILL IS NOT NAGGED (owner ask, 29-Sep-2026).
             *    "+ Add a job" after the bill went in (Chain Set beside Oil + Tuning, one receipt)
             *    left the new job reading "amount needed" for ever, although the visit's money was
             *    on file. It now says which bill the visit has instead (`visit_bill_id`); "Add the
             *    bill" stays on the visit for the rare job that really had its own receipt.
             * ⚠ Only a LIVE bill (pending/approved) — a rejected one settles nothing, so its
             *   visit keeps nagging. A claim row is itself a bill, so it counts too.
             */
            $visitBill = [];
            foreach ($out as $r) {
                if (empty($r['visit_key']) || empty($r['bill_live'])) continue;
                $bid = !empty($r['manual']) ? ($r['bill_id'] ?? null) : ($r['req_id'] ?? null);
                if ($bid && !isset($visitBill[$r['visit_key']])) $visitBill[$r['visit_key']] = (int) $bid;
            }
            foreach ($out as &$row) {
                $row['visit_bill_id'] = null;
                if (!empty($row['needs_amount']) && !empty($row['visit_key']) && isset($visitBill[$row['visit_key']])) {
                    $row['needs_amount']  = false;
                    $row['visit_bill_id'] = $visitBill[$row['visit_key']];
                }
            }
            unset($row);

            /**
             * ⭐⭐ ONE BILL, SEVERAL JOBS — THE MONEY IS SHOWN ONCE (29-Sep-2026). A receipt that
             *    paid for a whole visit is linked from every job it covers, and the join above
             *    hands each of them the full amount. Left alone, a Rs 5,200 visit would read as
             *    Rs 15,600 on the page. The first job keeps `amount`; the others carry the same
             *    `bill_id` (so none is ever nagged for a figure) with amount 0, and every row
             *    says how many jobs its bill covers.
             */
            $billJobs = [];
            foreach ($out as $r) {
                if (!empty($r['log_id']) && !empty($r['bill_id'])) $billJobs[(int) $r['bill_id']][] = (int) $r['log_id'];
            }
            foreach ($out as &$row) {
                $row['visit_key']   = $row['visit_key'] ?? null;
                $row['bill_covers'] = 1;
                if (!empty($row['log_id']) && !empty($row['bill_id']) && count($billJobs[(int) $row['bill_id']]) > 1) {
                    $ids = $billJobs[(int) $row['bill_id']];
                    $row['bill_covers'] = count($ids);
                    if ((int) $row['log_id'] !== min($ids)) $row['amount'] = 0.0;
                }
            }
            unset($row);

            // Newest first; the jobs of one visit stay together (same key), oldest record first.
            usort($out, function ($a, $b) {
                $c = strcmp((string) $b['date'], (string) $a['date']);
                if ($c !== 0) return $c;
                $c = ((int) ($b['meter'] ?? 0)) <=> ((int) ($a['meter'] ?? 0));
                if ($c !== 0) return $c;
                $c = strcmp((string) ($a['visit_key'] ?? ''), (string) ($b['visit_key'] ?? ''));
                if ($c !== 0) return $c;
                return ((int) ($a['log_id'] ?? $a['req_id'] ?? 0)) <=> ((int) ($b['log_id'] ?? $b['req_id'] ?? 0));
            });

            return array_slice($out, 0, $limit);
        } catch (\Throwable $e) {
            Log::warning('serviceHistoryFor failed', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /** One name lookup per user id per request. */
    private function nameOf(?int $uid, array &$cache): ?string
    {
        if ($uid === null) return null;
        if (!array_key_exists($uid, $cache)) {
            $cache[$uid] = DB::table('t_sys_user')->where('id', $uid)->value('fullname') ?: null;
        }
        return $cache[$uid];
    }

    /** Names for every keeper in these windows — one query, not one per row. */
    private function keeperNames(array $windows): array
    {
        $ids = array_values(array_unique(array_column($windows, 'user_id')));
        if (!$ids) return [];
        try {
            return DB::table('t_sys_user')->whereIn('id', $ids)
                ->pluck('fullname', 'id')->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Who held it on this date, by the windows already loaded. */
    private function keeperOnDate(array $windows, string $date): ?int
    {
        foreach ($windows as $w) {
            if ($date >= $w['from'] && ($w['to'] === null || $date <= $w['to'])) {
                return $w['user_id'];
            }
        }
        return null;
    }

    /**
     * ⭐⭐ THE ONE READING-QUALITY RULE (Aug-22 2026) — JUDGE THE READING, NOT THE ROW.
     *
     * ⚠⚠ THE PROD BUG THIS EXISTS FOR (Waseem, DCR-799, 21-Aug-2026). The predicate these
     *    three helpers replace opened with `meter_start > MIN_METER`, which made `meter_start`
     *    a GATEKEEPER for `meter_end` and `meter_home` — three independent readings judged as
     *    one row. On a handover the incoming rider CLOSES the day but never OPENED it, so his
     *    row carries no start and the WHOLE row was discarded, taking the machine's true
     *    closing reading with it. DCR-799 really closed 20-Aug at 26,530 in Waseem's row; the
     *    window never saw it, fell back to a fuel fill at 26,441, and accused him of an 89 km
     *    overnight gap he had not ridden. It had eaten Danish's 19-Aug close the same way, and
     *    went unnoticed only because his next morning's start happened to repeat the number.
     *
     * ⭐ These mirror `MachineAttribution::pointsForRow()` EXACTLY. That engine (the Bikes
     *   day-by-day view) had the rule right all along — which is precisely why the two screens
     *   disagreed about the same machine on the same day. ONE rule, and now only one definition:
     *   `FuelClaimRules::odometerWindow()` used to carry a verbatim copy of the old predicate.
     *
     * ⚠ Expressed as SQL rather than a PHP row-walk on purpose: it has to apply INSIDE the same
     *   MAX/MIN aggregates the odometer window already runs, and a PHP filter would mean pulling
     *   every attendance row into memory (the same reasoning as `scopeWindowRows`).
     */

    /**
     * The only ROW-level rejection left: both ends present and the span impossible. A day that
     * "ran" 26,261 → 56,403 is a typo and neither of its numbers can be trusted.
     *
     * ⚠⚠ Deliberately NOT `meter_start > MIN_METER`. A row with no start is not a bad row — it
     *   is a handover evening, and its close is the machine's best evidence for that day.
     */
    public static function readingRowFilterSql(): string
    {
        // ⚠⚠ COALESCE IS LOAD-BEARING, NOT TIDINESS. In SQL's three-valued logic `NULL > 0` is
        //    NULL, so `NOT (NULL AND TRUE)` is NULL — which a WHERE treats as FALSE and the row
        //    vanishes. Written without these COALESCEs this predicate silently discards exactly
        //    the start-less handover rows it exists to rescue, i.e. it reproduces the original
        //    bug in new clothes. Caught by the replay harness; do not "simplify" them away.
        // ⭐⭐ STEP C — the span check is only meaningful when BOTH ends are the SAME machine.
        //    On a split day (own bike at 06:00, the van from lunch) `meter_end - meter_start` is
        //    67,000 — not a typo, just two different odometers — and the old unconditional test
        //    threw the whole row away, taking the van's perfectly good close with it. When the
        //    two stamps disagree there is no span to judge, so the check stands down and each
        //    reading is left to the per-reading guards in the high/low expressions.
        $sameMachine = self::stampsAvailable()
            ? ' AND COALESCE(meter_start_vehicle_id, meter_end_vehicle_id, 0)
                   = COALESCE(meter_end_vehicle_id, meter_start_vehicle_id, 0)'
            : '';

        return 'NOT (COALESCE(meter_start,0) > 0 AND COALESCE(meter_end,0) > 0' . $sameMachine . '
                     AND (meter_end < meter_start OR meter_end - meter_start > ' . self::MAX_DAY_KM . '))';
    }

    /**
     * The HIGHEST trustworthy reading on a row — the floor candidate.
     *
     * `meter_home` counts as the ride-home extension only when both ends are known and it sits
     * within MAX_HOME_KM above the close; when they are not known it stands on its own, exactly
     * as `pointsForRow()`'s `max($known)` branch does.
     */
    public static function readingHighExprSql(?int $vehicleId = null): string
    {
        $s = self::stampGuardSql('meter_start', $vehicleId);
        $e = self::stampGuardSql('meter_end',   $vehicleId);
        $h = self::stampGuardSql('meter_home',  $vehicleId);

        return 'GREATEST(
                  CASE WHEN meter_start > 0' . $s . ' THEN meter_start ELSE 0 END,
                  CASE WHEN meter_end   > 0' . $e . ' THEN meter_end   ELSE 0 END,
                  CASE WHEN meter_home  > 0' . $h . '
                            AND (meter_start IS NULL OR meter_start <= 0
                                 OR meter_end IS NULL OR meter_end <= 0
                                 OR (meter_home > meter_end
                                     AND meter_home - meter_end <= ' . self::MAX_HOME_KM . '))
                       THEN meter_home ELSE 0 END)';
    }

    /**
     * ⭐⭐ STEP C — the per-reading machine stamp, consulted by the reading expressions.
     *
     * `<col>_vehicle_id` is written at the MOMENT the reading is taken (see
     * `RiderController::meterStampFields`). It answers the one question the old model could not:
     * on a day a rider used two machines, WHICH machine is this particular number about.
     *
     * The guard is deliberately asymmetric and that is the whole safety story:
     *   • stamp = this vehicle  -> counts
     *   • stamp = another       -> excluded, even though the rider's day resolves here
     *   • stamp NULL            -> counts, i.e. EVERY pre-step-C row behaves exactly as before
     *
     * ⚠ Returns '' when the column is absent, so the code is safe to upload before the SQL.
     */
    private static function stampGuardSql(string $col, ?int $vehicleId): string
    {
        if ($vehicleId === null || !self::stampsAvailable()) return '';
        return ' AND (' . $col . '_vehicle_id IS NULL OR ' . $col . '_vehicle_id = ' . (int) $vehicleId . ')';
    }

    /**
     * ⭐⭐ METER REPLACED (5-Oct-2026) — an attendance-reading SQL fragment, re-read on the
     *    machine's CONTINUOUS scale (see `MeterReplacement`). Every `meter_start` / `meter_end`
     *    / `meter_home` VALUE in the fragment becomes "the reading + what the old meter(s) had
     *    already run"; the `_vehicle_id` stamp columns are left alone (`\b` does not match
     *    inside `meter_start_vehicle_id`).
     *
     * ⭐ Returns the fragment UNCHANGED for a machine with no replacement on record — so every
     *   existing query is byte-identical for the whole fleet until someone records one.
     */
    private static function effSql(string $sql, ?int $vehicleId, string $dateExpr = 'attendance_date'): string
    {
        if (!MeterReplacement::has($vehicleId)) return $sql;
        return preg_replace_callback('/\bmeter_(start|end|home)\b/',
            fn ($m) => MeterReplacement::sql($vehicleId, $m[0], $dateExpr), $sql);
    }

    /** A claim's `meter_at_fill` on the machine's continuous scale (the bare column without a replacement). */
    private static function fillSql(?int $vehicleId): string
    {
        return MeterReplacement::sql($vehicleId, 'meter_at_fill', 'COALESCE(expense_date, DATE(created_at))');
    }

    /**
     * Service-log readings taken on a REPLACED meter, on the continuous scale — the rows the
     * shared `meter > MIN_METER` scans cannot see while the new meter is still under 1,000.
     * Empty for a machine with no replacement, so those scans stay exactly as they were.
     * @return array<int, array{id:int, m:int, d:string}>
     */
    private function serviceLogsOnNewMeter(int $vehicleId, $ignoreIds = null): array
    {
        $first = MeterReplacement::rowsFor($vehicleId)[0] ?? null;
        if (!$first || !self::hasTbl('t_fleet_service_log')) return [];
        $out = [];
        try {
            $resolver = new VehicleResolver();
            $skip = ServiceRecordService::normaliseIds($ignoreIds);
            foreach (DB::table('t_fleet_service_log')
                        ->whereNotNull('meter')->where('meter', '>', 0)
                        ->where('service_date', '>=', $first['d'])
                        ->when($skip, fn ($q, $ids) => $q->whereNotIn('id', $ids))
                        ->get(array_merge(['id', 'user_id', 'meter', 'service_date'],
                                          ServiceRecordService::logVehicleCols())) as $sl) {
                if (ServiceRecordService::logVehicleOf($sl, $resolver) !== $vehicleId) continue;
                $d = substr((string) $sl->service_date, 0, 10);
                $out[] = ['id' => (int) $sl->id, 'd' => $d,
                          'm' => (int) MeterReplacement::toContinuous($vehicleId, (int) $sl->meter, $d)];
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $out;
    }

    /**
     * ⭐ Could this reading be of THIS machine? Rule P's question, asked at WRITE time.
     *
     * Used before a meter reading is stamped, so that a rider typing his own bike's 6,606 while
     * the registry still says he holds the van (a manager forgot to release it) does not freeze
     * that number onto a ~73,800 km machine. Unstamped is recoverable; a wrong stamp is not,
     * because it outranks the derivation that would otherwise have caught it.
     *
     * ⭐ FAILS OPEN — no spine, or any error, means "can't tell", which must never block a write.
     */
    public function readingPlausibleFor(int $vehicleId, int $value, ?string $date = null,
                                        bool $dateCeiling = true): bool
    {
        try {
            // ⭐ Meter replaced (Oct-2026): the reading is judged on the machine's continuous
            //   scale — a new meter's 147 is "the old meter's last + 147". With no date it is
            //   taken as a reading of the meter fitted NOW. Identity without a replacement.
            $value = (int) MeterReplacement::toContinuous($vehicleId, $value, $date);
            /**
             * ⚠⚠ AN ODOMETER NEVER RUNS BACKWARDS — asked whenever the reading has a DATE
             *    (5-Oct-2026). Growing the spine (below) widened the window enough to let
             *    Waseem's DCR-799 fills of 1-Aug (24,153–24,588) back onto EGL-682 by the
             *    first-keeper window guess — the exact Aug-28 CEN-455 incident — and every
             *    later fill of Danish's read "meter vs last fill doesn't add up". A reading
             *    dated 1-Aug cannot be above what the same machine showed on 29-Aug (17,198).
             *    MAX_GAP_KM of slack, so a slightly-off later reading never refuses a real one.
             */
            if ($dateCeiling && $date !== null && ($ceil = $this->lowestMachineReadingAfter($vehicleId, substr($date, 0, 10))) !== null
                && $value > $ceil + self::MAX_GAP_KM) {
                return false;
            }
            $spine = [];
            foreach ($this->machineKeyedReadings($vehicleId) as $r) {
                if ((int) $r['m'] > self::MIN_METER) $spine[] = (int) $r['m'];
            }
            /**
             * ⚠⚠ THE SPINE MUST GROW WITH THE BIKE (5-Oct-2026 prod incident, EGL-682).
             *    Its only keyed reading was a 29-Aug meter log at 17,198, so the window topped out
             *    at 17,198 + 20% = 20,638 for ever. Once the bike honestly ran past that, every
             *    reading of it was "implausible": its attendance stamps silently stopped (28-Sep
             *    close onward) and the service door refused 21,459 while printing "it was last
             *    seen at 21,459 km" — and with the service refused, the workshop visit could not
             *    be closed.
             *
             * ⭐ So readings already STAMPED to this machine join the spine. Each one was accepted
             *   by this very rule (or typed by a manager on the machine's own editor), so the
             *   chain extends a day at a time and a daily run can never outgrow it.
             * ⚠ Deliberately NOT `currentMeterFor()`: that is a MAX over the keeper's UNSTAMPED
             *   rows too, so one wrong-machine reading in it would widen the window and let the
             *   next wrong reading be stamped — the one mistake this rule exists to prevent.
             */
            // ⚠ Only the HIGH end: the low anchor stays the keyed readings, so this never
            //   widens the window downward (the van-vs-own-bike direction).
            if (($stHi = $this->stampedReadings($vehicleId)['hi']) !== null) $spine[] = $stHi;
            if (!$spine) {
                $cur = $this->currentMeterContinuous($vehicleId);
                if ($cur !== null && $cur > self::MIN_METER) $spine[] = (int) $cur;
            }
            if (!$spine) return true;
            $tol = self::spineToleranceKm(max($spine));   // R10 — proportional, see spineToleranceKm()
            return $value >= min($spine) - $tol && $value <= max($spine) + $tol;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Attendance readings STAMPED to this machine (`<col>_vehicle_id`): the highest overall
     * (Rule P's moving end) and the lowest per day (for the date ceiling). Memoised: Rule P
     * runs once per claim in `claimsForVehicle`. ⚠ Fails soft (empty = "no stamped evidence").
     *
     * @return array{hi:?int, days:array<string,int>}
     */
    private function stampedReadings(int $vehicleId): array
    {
        if (array_key_exists($vehicleId, self::$stampedReadMemo)) {
            return self::$stampedReadMemo[$vehicleId];
        }
        $out = ['hi' => null, 'days' => []];
        try {
            if (self::stampsAvailable()) {
                foreach (['meter_start', 'meter_end', 'meter_home'] as $c) {
                    if (!self::hasCol('t_ops_attendance', $c . '_vehicle_id')) continue;
                    // Continuous scale (identity without a meter replacement).
                    $cx = self::effSql($c, $vehicleId);
                    foreach (DB::table('t_ops_attendance')
                                ->where($c . '_vehicle_id', $vehicleId)
                                ->whereRaw($cx . ' > ' . self::MIN_METER)
                                ->groupBy('attendance_date')
                                ->selectRaw('attendance_date AS d, MIN(' . $cx . ') AS lo, MAX(' . $cx . ') AS hi')
                                ->get() as $r) {
                        $d = substr((string) $r->d, 0, 10);
                        $out['hi'] = max((int) $out['hi'], (int) $r->hi);
                        $out['days'][$d] = isset($out['days'][$d]) ? min($out['days'][$d], (int) $r->lo) : (int) $r->lo;
                    }
                }
            }
        } catch (\Throwable $e) {
            $out = ['hi' => null, 'days' => []];
        }
        return self::$stampedReadMemo[$vehicleId] = $out;
    }

    /**
     * The lowest reading this machine is RECORDED at on any day strictly after `$date` — its
     * own meter log / handover readings and attendance readings stamped to it. Null = none.
     */
    private function lowestMachineReadingAfter(int $vehicleId, string $date): ?int
    {
        $low = null;
        foreach ($this->machineKeyedReadings($vehicleId) as $r) {
            if (!empty($r['d']) && $r['d'] > $date && (int) $r['m'] > self::MIN_METER) {
                $low = $low === null ? (int) $r['m'] : min($low, (int) $r['m']);
            }
        }
        foreach ($this->stampedReadings($vehicleId)['days'] as $d => $m) {
            if ($d > $date) $low = $low === null ? $m : min($low, $m);
        }
        return $low;
    }

    /**
     * ⭐⭐ THE ONE WRITER for a machine's own meter-log row (Aug-27 2026).
     *
     * WHY IT MOVED HERE. This was inline in `VehicleController::meterSave`, reachable from
     * exactly one screen. The rider's app now needs the same power — on a day the van owns
     * his attendance meters, his own bike's kilometres have nowhere else to live, and a man
     * should not have to ask a manager to type them in for him. A second screen must never
     * mean a second implementation: this codebase has paid for that lesson repeatedly (three
     * copies of "is this a transfer day", four of the sane-row predicate), and the owner
     * asked for exactly this — the app and the Vehicles page writing through one door.
     *
     * ⚠ EMPTYING BOTH READINGS DELETES THE ROW, deliberately: "nothing to keep" is a
     *   removal, and an empty shell would sit in the machine's chain saying nothing.
     *
     * ⚠ Callers own the PERMISSION question. This writer answers "how", never "who" — the
     *   web gate (`manage_bike_service`) and the rider gate (his own machine, his own day)
     *   are different questions with different answers, and folding them in here would
     *   force one to impersonate the other.
     *
     * @return array{ok:bool, message:string, action:?string, id:?int}
     */
    public function saveMeterLog(int $vehicleId, string $date, ?int $start, ?int $end,
                                 ?int $driverUserId, ?string $note, int $enteredBy): array
    {
        $date = substr($date, 0, 10);
        try {
            if (!self::hasTbl(self::T_METER_LOG)) {
                return ['ok' => false, 'action' => null, 'id' => null,
                        'message' => 'Meter logging is not set up on this server yet (SQL pending).'];
            }

            $existing = DB::table(self::T_METER_LOG)
                ->where('vehicle_id', $vehicleId)->where('log_date', $date)->first();

            if ($start === null && $end === null) {
                if ($existing) {
                    DB::table(self::T_METER_LOG)->where('id', $existing->id)->delete();
                }
                $this->flushAfterMeterLog($date);
                return ['ok' => true, 'action' => 'deleted', 'id' => null,
                        'message' => $existing ? 'Reading removed.' : 'Nothing to save.'];
            }

            $row = [
                'vehicle_id'     => $vehicleId,
                'log_date'       => $date,
                'meter_start'    => $start,
                'meter_end'      => $end,
                'driver_user_id' => $driverUserId,
                'note'           => $note,
                'entered_by'     => $enteredBy,
                'updated_at'     => now(),
            ];

            if ($existing) {
                DB::table(self::T_METER_LOG)->where('id', $existing->id)->update($row);
                $id = (int) $existing->id;
            } else {
                $row['created_at'] = now();
                $id = (int) DB::table(self::T_METER_LOG)->insertGetId($row);
            }

            Log::info('Vehicle meter log saved', [
                'vehicle_id' => $vehicleId, 'date' => $date,
                'driver' => $driverUserId, 'by' => $enteredBy, 'id' => $id,
            ]);
            $this->flushAfterMeterLog($date);

            return ['ok' => true, 'action' => $existing ? 'updated' : 'created',
                    'id' => $id, 'message' => 'Reading saved.'];
        } catch (\Throwable $e) {
            Log::error('saveMeterLog failed', [
                'vehicle_id' => $vehicleId, 'date' => $date, 'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'action' => null, 'id' => null,
                    'message' => 'Could not save that reading.'];
        }
    }

    /** The month's figures are DERIVED — drop every memo that just went stale. */
    /**
     * ⭐⭐ RECORD "THIS MACHINE'S METER WAS REPLACED" (5-Oct-2026) — the one writer.
     *
     * `$oldReading` = the last figure the OLD meter showed; `$newReading` = what the NEW one
     * showed when it was fitted (usually 0). From `$date` on, every rule reads this machine's
     * new readings as `reading + (old - new)` — see `MeterReplacement`.
     *
     * ⚠ One row per machine per day (saving again CORRECTS it). Callers own the permission.
     * ⚠ Refuses a pair that changes nothing (old = new) — that is not a replacement, and an
     *   empty row would only make the next reader wonder what it was for.
     *
     * @return array{ok:bool, message:string, id:?int}
     */
    public function saveMeterReplacement(int $vehicleId, string $date, int $oldReading, int $newReading,
                                         ?string $note, int $enteredBy): array
    {
        $date = substr($date, 0, 10);
        $fail = fn (string $m) => ['ok' => false, 'message' => $m, 'id' => null];
        try {
            if (!MeterReplacement::available()) {
                return $fail('Meter replacement is not set up on this server yet (SQL pending).');
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) {
                return $fail('Choose the day the meter was replaced — today or earlier.');
            }
            if ($oldReading <= 0) return $fail('Enter the last reading the OLD meter showed.');
            if ($newReading < 0)  return $fail('The new meter\'s reading cannot be negative.');
            if ($oldReading === $newReading) {
                return $fail('The old and new readings are the same — that is not a replacement.');
            }

            $existing = DB::table(MeterReplacement::TABLE)
                ->where('vehicle_id', $vehicleId)->where('reset_date', $date)->first();
            $row = [
                'vehicle_id'  => $vehicleId,
                'reset_date'  => $date,
                'old_reading' => $oldReading,
                'new_reading' => $newReading,
                'note'        => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
                'entered_by'  => $enteredBy,
                'updated_at'  => now(),
            ];
            if ($existing) {
                DB::table(MeterReplacement::TABLE)->where('id', $existing->id)->update($row);
                $id = (int) $existing->id;
            } else {
                $row['created_at'] = now();
                $id = (int) DB::table(MeterReplacement::TABLE)->insertGetId($row);
            }
            $this->flushAfterMeterReplacement($vehicleId, $date);

            try {
                \App\Services\AuditLogger::log(
                    $existing ? 'vehicle_meter_replacement_corrected' : 'vehicle_meter_replaced',
                    'vehicle', $vehicleId, $this->find($vehicleId)['name'] ?? ('vehicle ' . $vehicleId),
                    ['reset_date' => $date, 'old_reading' => $oldReading, 'new_reading' => $newReading],
                    null, $row['note']
                );
            } catch (\Throwable $e) { /* auditing must never break the action */ }

            return ['ok' => true, 'id' => $id,
                    'message' => 'Meter replacement recorded — readings from ' . $date
                        . ' on are read on the new meter.'];
        } catch (\Throwable $e) {
            Log::warning('saveMeterReplacement failed', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
            return $fail('Could not record the meter replacement.');
        }
    }

    /** Take back a meter replacement recorded by mistake. */
    public function removeMeterReplacement(int $vehicleId, int $resetId): array
    {
        try {
            if (!MeterReplacement::available()) return ['ok' => false, 'message' => 'Nothing to remove.'];
            $r = DB::table(MeterReplacement::TABLE)->where('id', $resetId)->where('vehicle_id', $vehicleId)->first();
            if (!$r) return ['ok' => false, 'message' => 'That replacement is no longer on record.'];
            DB::table(MeterReplacement::TABLE)->where('id', $resetId)->delete();
            $this->flushAfterMeterReplacement($vehicleId, substr((string) $r->reset_date, 0, 10));
            try {
                \App\Services\AuditLogger::log('vehicle_meter_replacement_removed', 'vehicle', $vehicleId,
                    $this->find($vehicleId)['name'] ?? ('vehicle ' . $vehicleId),
                    ['reset_date' => substr((string) $r->reset_date, 0, 10),
                     'old_reading' => (int) $r->old_reading, 'new_reading' => (int) $r->new_reading]);
            } catch (\Throwable $e) { /* never break the action */ }
            return ['ok' => true, 'message' => 'Meter replacement removed.'];
        } catch (\Throwable $e) {
            Log::warning('removeMeterReplacement failed', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not remove it.'];
        }
    }

    /** The replacements on record for one machine, newest first — for the Meter editor. */
    public function meterReplacementsFor(int $vehicleId): array
    {
        $out = [];
        foreach (array_reverse(MeterReplacement::rowsFor($vehicleId)) as $r) {
            $out[] = ['id' => $r['id'], 'date' => $r['d'], 'old_reading' => $r['old'],
                      'new_reading' => $r['new'], 'note' => $r['note'],
                      'entered_by_name' => $r['entered_by']
                          ? DB::table('t_sys_user')->where('id', $r['entered_by'])->value('fullname') : null];
        }
        return $out;
    }

    /**
     * Everything derived from this machine's readings changes when a replacement is recorded:
     * the in-process memos, the cross-request service cache, the rider legs, and the cached
     * month engine for EVERY month from the replacement to now.
     */
    private function flushAfterMeterReplacement(int $vehicleId, string $date): void
    {
        try {
            self::bumpServiceEvidence($vehicleId);       // also flushes the memos + MeterReplacement
            VehicleResolver::flush();
            RiderDayLegs::flush();
            $eng = new MachineAttribution();
            $m = Carbon::parse($date)->startOfMonth();
            $now = Carbon::today()->startOfMonth();
            for ($i = 0; $m->lte($now) && $i < 36; $i++, $m->addMonth()) {
                $eng->flush($m->format('Y-m'));
            }
        } catch (\Throwable $e) { /* a stale cache must never fail a saved record */ }
    }

    private function flushAfterMeterLog(string $date): void
    {
        try {
            // ⚠ `flush()` is an INSTANCE method on MachineAttribution (a static call here
            //   throws, and the catch below would swallow it — leaving the month's figures
            //   stale for the cache's lifetime with nothing to show why).
            (new MachineAttribution())->flush(substr($date, 0, 7));
            VehicleResolver::flush();
            RiderDayLegs::flush();
            self::flushServiceMemo();      // incl. the Rule P reading spine
        } catch (\Throwable $e) { /* a stale cache must never fail a saved reading */ }
    }

    /** Has SQL-METER-READING-STAMP-AUG22-2026 been run? Memoised — asked inside query builders. */
    public static function stampsAvailable(): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        try {
            $ok = self::hasCol('t_ops_attendance', 'meter_start_vehicle_id');
        } catch (\Throwable $e) {
            $ok = false;
        }
        return $ok;
    }

    /**
     * The LOWEST trustworthy reading on a row — the ceil candidate (the day's opening).
     *
     * ⚠⚠ MIN_METER LIVES HERE AND ONLY HERE NOW, AND THAT IS LOAD-BEARING. Dropping the
     *    row-level floor lets a dropped-digit typo (2,653 for 26,530) reach the aggregates. On
     *    the FLOOR (a MAX) that is harmless — a real reading outranks it. On the CEIL (a MIN) it
     *    is catastrophic: the junk becomes the ceiling and every honest claim above it is
     *    refused, with no honest way for the rider to get past it. So the bound moves off the
     *    ROW and onto the CANDIDATE, where it still does exactly the job it always did.
     */
    public static function readingLowExprSql(?int $vehicleId = null): string
    {
        // Each reading is nulled out when its stamp names a DIFFERENT machine, so the COALESCE
        // falls through to the next one that really is this machine's.
        $pick = fn (string $col) => self::stampsAvailable() && $vehicleId !== null
            ? 'CASE WHEN ' . $col . '_vehicle_id IS NULL OR ' . $col . '_vehicle_id = ' . (int) $vehicleId
              . ' THEN NULLIF(' . $col . ',0) ELSE NULL END'
            : 'NULLIF(' . $col . ',0)';

        $c = 'COALESCE(' . $pick('meter_start') . ', ' . $pick('meter_end') . ', ' . $pick('meter_home') . ')';
        return 'NULLIF(CASE WHEN ' . $c . ' > ' . self::MIN_METER . ' THEN ' . $c . ' ELSE 0 END, 0)';
    }

    /**
     * Rollback valve for the reading-level rule above. `t_fin_config` returns the default for an
     * absent key, so the fix ships ON with NO SQL, and one config row set to 0 reverts every
     * caller without re-uploading a file.
     *
     * ⚠ Memoised per process on purpose: this is asked once per odometer query and
     *   `meterWindowFor` runs in loops (once per machine per date on the Bikes sheet). An
     *   un-memoised `config()` would add a DB round trip to each one.
     * ⚠ A config failure resolves to the FIXED behaviour, not the old one — the old one is the
     *   bug, and an unreachable config table must not quietly restore it.
     */
    private function readingLevelOn(): bool
    {
        static $on = null;
        if ($on !== null) return $on;
        try {
            $on = (int) (new \App\Services\Riders\HomeJourneyService())
                ->config('METER_WINDOW_READING_LEVEL', 1) === 1;
        } catch (\Throwable $e) {
            $on = true;
        }
        return $on;
    }

    /**
     * @param  int|int[]|null $ignoreServiceLogId  ⭐ A service record being CORRECTED must not bound
     *         itself (Sep-20 2026). Re-dating log #28 from 15-Sep to 19-Sep would otherwise be
     *         judged against a floor that log #28's own old row had set. Only the service-log
     *         source honours it — nothing else in this window is a service record.
     */
    public function meterWindowFor(int $vehicleId, string $date, $ignoreServiceLogId = null): ?array
    {
        $w = $this->meterWindowContinuousFor($vehicleId, $date, $ignoreServiceLogId);
        if ($w === null || !MeterReplacement::has($vehicleId)) return $w;
        // ⭐ Meter replaced (Oct-2026): the window is built on the machine's continuous scale;
        //   what leaves here is in the figures of the meter fitted on `$date`, i.e. what a
        //   person looking at the bike that day would read. A floor at or below zero on a new
        //   meter is "the new meter had not started" — no constraint.
        $floor = $w['floor'] !== null ? MeterReplacement::toRaw($vehicleId, (int) $w['floor'], $date) : null;
        $ceil  = $w['ceil']  !== null ? MeterReplacement::toRaw($vehicleId, (int) $w['ceil'],  $date) : null;
        return ['floor' => ($floor !== null && $floor > 0) ? $floor : null,
                'ceil'  => ($ceil  !== null && $ceil  > 0) ? $ceil  : null];
    }

    /**
     * The same window on the machine's CONTINUOUS scale (see `MeterReplacement`) — for callers
     * that do arithmetic with it (a span across a month, a typed reading converted with
     * `MeterReplacement::toContinuous`). Identical to `meterWindowFor` for a machine whose
     * meter was never replaced.
     */
    public function meterWindowContinuousFor(int $vehicleId, string $date, $ignoreServiceLogId = null): ?array
    {
        if (!$this->available()) return null;

        // ⚠⚠ MEMOISED, AND IT MUST BE — same reasoning as `machineKeyedReadings()`
        //    below, which documents the rule this method was missing. Every call
        //    walks EVERY assignment window of the machine and fires 4-6 queries per
        //    window, so the month engine asking for the same (machine, date) twice
        //    costs ~30 queries for an identical answer. Measured on the replica
        //    (rider 95 / August): 10 calls, only 6 distinct.
        // ⚠ Flushed by `flushServiceMemo()` — unlike the schema memo, this IS
        //   evidence-derived, so a reading saved mid-request must invalidate it.
        // ⚠ The ignored row is part of the key: the same (machine, date) with and
        //   without it are two different answers.
        $memoKey = $vehicleId . '|' . substr($date, 0, 10) . (ServiceRecordService::normaliseIds($ignoreServiceLogId) ? '|!' . implode(',', ServiceRecordService::normaliseIds($ignoreServiceLogId)) : '');
        if (array_key_exists($memoKey, self::$windowMemo)) {
            return self::$windowMemo[$memoKey];
        }

        try {
            $readingLevel = $this->readingLevelOn();

            $sane = $readingLevel
                ? self::readingRowFilterSql()
                : 'meter_start > ' . self::MIN_METER . '
                     AND (meter_end IS NULL OR (meter_end >= meter_start AND meter_end - meter_start <= 500))
                     AND (meter_home IS NULL OR (meter_home >= meter_start AND meter_home - meter_start <= 700))';

            $highExpr = $readingLevel
                ? self::readingHighExprSql($vehicleId)
                : 'GREATEST(COALESCE(meter_end,0), COALESCE(meter_home,0), COALESCE(meter_start,0))';

            $lowExpr = $readingLevel
                ? self::readingLowExprSql($vehicleId)
                : 'COALESCE(NULLIF(meter_start,0), NULLIF(meter_end,0), NULLIF(meter_home,0))';

            // ⭐ Meter replaced (Oct-2026): every reading on the continuous scale. No-ops for a
            //   machine never replaced.
            $sane     = self::effSql($sane, $vehicleId);
            $highExpr = self::effSql($highExpr, $vehicleId);
            $lowExpr  = self::effSql($lowExpr, $vehicleId);
            $fillExpr = self::fillSql($vehicleId);
            $firstReset = MeterReplacement::rowsFor($vehicleId)[0]['d'] ?? null;

            // ⭐⭐ TWO CLASSES OF EVIDENCE, AND THEY ARE NOT EQUALLY TRUSTWORTHY (Aug-22 2026).
            //
            //   $floorC / $ceilC          MACHINE-KEYED — the row itself names this vehicle
            //                             (day-override, stamped claims, meter log, handover
            //                             meter). It cannot be about a different machine.
            //   $riskFloorC / $riskCeilC  RIDER-KEYED — an attendance row or an unstamped claim,
            //                             pulled in because the RIDER held this machine that DAY.
            //                             On a day he used two machines, this is how another
            //                             machine's odometer gets in.
            //
            // ⚠⚠ THE PROD INCIDENT (the Van, v4, 20–21 Aug 2026). Rajab photographed his OWN
            //    bike's meter (6,434) at 11:58, and the Van reached him at 12:09. One meter slot
            //    per rider-day plus day-level attribution filed that 6,434 against a van whose
            //    real odometer is ~73,800. The floor shrugged it off (a MAX ignores it) but the
            //    CEIL would have taken it — 6,434 clears MIN_METER — and every honest Van claim
            //    above it would have been refused. `rulePlausible()` below is what stops that.
            $floorC = [0];
            $ceilC  = [];
            $riskFloorC = [];
            $riskCeilC  = [];

            $windows = $this->attributionWindows($vehicleId);
            foreach ($windows as $w) {
                // ⚠⚠ Days inside this window that the resolver gives to ANOTHER
                //    machine are dropped — the same overlap that let a one-day
                //    mis-assignment put someone else's 12,390 km floor on a new bike
                //    and refuse its keeper's honest 659 km claim.
                //    `scopeWindowRows` below only removes manager DAY-OVERRIDES; it
                //    cannot see an overlapping assignment, which is what bit us.
                $skip = $this->datesAttributedElsewhere(
                    (int) $w['user_id'], $w['from'], $w['to'], $vehicleId
                );
                $skipSql = $skip
                    ? 'COALESCE(expense_date, DATE(created_at)) NOT IN ('
                      . implode(',', array_fill(0, count($skip), '?')) . ')'
                    : null;

                // BEFORE the date, clamped to his window — minus any day the manager
                // has since said was spent on a DIFFERENT machine.
                $before = $this->scopeWindowRows(DB::table('t_ops_attendance')
                    ->where('user_id', $w['user_id'])
                    ->where('attendance_date', '>=', $w['from'])
                    ->when($w['to'], fn ($q) => $q->where('attendance_date', '<=', $w['to']))
                    ->when($skip, fn ($q) => $q->whereNotIn('attendance_date', $skip))
                    ->where('attendance_date', '<', $date), $vehicleId)
                    ->whereRaw($sane)
                    ->selectRaw('MAX(' . $highExpr . ') AS m')
                    ->value('m');
                if ((int) $before > 0) $riskFloorC[] = (int) $before;

                // AFTER the date, still inside his window.
                $after = $this->scopeWindowRows(DB::table('t_ops_attendance')
                    ->where('user_id', $w['user_id'])
                    ->where('attendance_date', '>=', $w['from'])
                    ->when($w['to'], fn ($q) => $q->where('attendance_date', '<=', $w['to']))
                    ->when($skip, fn ($q) => $q->whereNotIn('attendance_date', $skip))
                    ->where('attendance_date', '>', $date), $vehicleId)
                    ->whereRaw($sane)
                    ->selectRaw('MIN(' . $lowExpr . ') AS m')
                    ->value('m');
                if ((int) $after > self::MIN_METER) $riskCeilC[] = (int) $after;

                // Unstamped legacy claims by this keeper inside his window.
                if (self::hasCol('t_req_master', 'vehicle_id')) {
                    $legacyQ = fn () => DB::table('t_req_master')
                        ->where('requester_user_id', $w['user_id'])
                        ->whereNull('vehicle_id')
                        ->whereNotNull('meter_at_fill')
                        ->whereRaw($fillExpr . ' > ' . self::MIN_METER)
                        ->whereNotIn('status', ['cancelled', 'rejected'])
                        ->whereRaw('COALESCE(expense_date, DATE(created_at)) >= ?', [$w['from']])
                        ->when($w['to'], fn ($q) => $q->whereRaw(
                            'COALESCE(expense_date, DATE(created_at)) <= ?', [$w['to']]))
                        ->when($skipSql, fn ($q) => $q->whereRaw($skipSql, $skip));
                    $lb = $legacyQ()->whereRaw('COALESCE(expense_date, DATE(created_at)) < ?', [$date])->selectRaw('MAX(' . $fillExpr . ') AS m')->value('m');
                    if ((int) $lb > 0) $riskFloorC[] = (int) $lb;
                    $la = $legacyQ()->whereRaw('COALESCE(expense_date, DATE(created_at)) > ?', [$date])->selectRaw('MIN(' . $fillExpr . ') AS m')->value('m');
                    if ((int) $la > self::MIN_METER) $riskCeilC[] = (int) $la;
                }
            }

            // ⭐ Days the manager pointed AT this machine, whoever the rider was and
            //   whatever the assignment timeline says — the borrowed-for-an-afternoon
            //   case. Outside the window loop because the whole point is that these
            //   days fall outside any window this machine has.
            if ($this->hasDayOverride()) {
                $ovBefore = DB::table('t_ops_attendance')
                    ->where('vehicle_id', $vehicleId)
                    ->where('attendance_date', '<', $date)
                    ->whereRaw($sane)
                    ->selectRaw('MAX(' . $highExpr . ') AS m')
                    ->value('m');
                if ((int) $ovBefore > 0) $floorC[] = (int) $ovBefore;

                $ovAfter = DB::table('t_ops_attendance')
                    ->where('vehicle_id', $vehicleId)
                    ->where('attendance_date', '>', $date)
                    ->whereRaw($sane)
                    ->selectRaw('MIN(' . $lowExpr . ') AS m')
                    ->value('m');
                if ((int) $ovAfter > self::MIN_METER) $ceilC[] = (int) $ovAfter;
            }

            // Claims stamped to THIS machine — whoever filed them.
            if (self::hasCol('t_req_master', 'vehicle_id')) {
                $stampedQ = fn () => DB::table('t_req_master')
                    ->where('vehicle_id', $vehicleId)
                    ->whereNotNull('meter_at_fill')
                    ->whereRaw($fillExpr . ' > ' . self::MIN_METER)
                    ->whereNotIn('status', ['cancelled', 'rejected']);
                $sb = $stampedQ()->whereRaw('COALESCE(expense_date, DATE(created_at)) < ?', [$date])->selectRaw('MAX(' . $fillExpr . ') AS m')->value('m');
                if ((int) $sb > 0) $floorC[] = (int) $sb;
                $sa = $stampedQ()->whereRaw('COALESCE(expense_date, DATE(created_at)) > ?', [$date])->selectRaw('MIN(' . $fillExpr . ') AS m')->value('m');
                if ((int) $sa > self::MIN_METER) $ceilC[] = (int) $sa;
            }

            /**
             * ⭐ SERVICE RECORDS ARE READINGS TOO (3-Sep). The odometer already counts them;
             *   this window — the typo-guard every claim is judged by — did not. So a bike
             *   whose only recent reading came from a recorded service was measured against a
             *   stale floor, and an honest claim could be refused as "far above this bike's
             *   last km". Same date anchor as every source here: strictly before / after.
             *
             * ⚠ MACHINE-KEYED, like the block above: each log is attributed by `vehicleForDay`,
             *   the same rule the countdowns use. A service log is rider-keyed in the table, so
             *   attributing it any other way would let a two-machine rider's van reading bound
             *   his own bike — the leak the $riskFloorC split above exists to contain.
             * ⚠ Bounded scan and fails soft: a guard must never be why a real claim is refused.
             */
            if (self::hasTbl('t_fleet_service_log')) {
                try {
                    $resolver = new VehicleResolver();
                    $svcBefore = 0; $svcAfter = null;
                    foreach (DB::table('t_fleet_service_log')
                                ->whereNotNull('meter')->where('meter', '>', self::MIN_METER)
                                ->when(ServiceRecordService::normaliseIds($ignoreServiceLogId), fn ($q, $ids) => $q->whereNotIn('id', $ids))
                                ->orderByDesc('meter')->limit(120)
                                ->get(array_merge(['id', 'user_id', 'meter', 'service_date'],
                                                  ServiceRecordService::logVehicleCols())) as $sl) {
                        $d = substr((string) $sl->service_date, 0, 10);
                        // Readings off a replaced meter are added below, on the continuous scale.
                        if ($firstReset !== null && $d >= $firstReset) continue;
                        // ⭐ Stamp first — same rule as every other per-machine reader.
                        if (ServiceRecordService::logVehicleOf($sl, $resolver) !== $vehicleId) continue;
                        if ($d < $date) { if ((int) $sl->meter > $svcBefore) $svcBefore = (int) $sl->meter; }
                        elseif ($d > $date) {
                            if ($svcAfter === null || (int) $sl->meter < $svcAfter) $svcAfter = (int) $sl->meter;
                        }
                    }
                    foreach ($this->serviceLogsOnNewMeter($vehicleId, $ignoreServiceLogId) as $nl) {
                        if ($nl['d'] < $date) { if ($nl['m'] > $svcBefore) $svcBefore = $nl['m']; }
                        elseif ($nl['d'] > $date) {
                            if ($svcAfter === null || $nl['m'] < $svcAfter) $svcAfter = $nl['m'];
                        }
                    }
                    if ($svcBefore > 0) $floorC[] = $svcBefore;
                    if ($svcAfter !== null && $svcAfter > self::MIN_METER) $ceilC[] = $svcAfter;
                } catch (\Throwable $e) {
                    // leave the window as the other sources built it
                }
            }

            // ── STEP C — readings STAMPED to this machine, whoever the rider was and whatever
            //    the assignment timeline says. The mirror image of the day-override block above,
            //    and the half that makes stamping actually work: the guards inside $highExpr keep
            //    another machine's readings OUT, and this keeps THIS machine's readings IN even
            //    when the rider's day resolves elsewhere — which is exactly the mid-day handover
            //    (Rajab's evening close belongs to the Van while his day resolves to his own bike,
            //    or the reverse). Machine-keyed by construction, so it is trusted evidence.
            if (self::stampsAvailable()) {
                $stampCols = ['meter_start', 'meter_end', 'meter_home'];
                $stampedRows = fn (string $op) => DB::table('t_ops_attendance')
                    ->where('attendance_date', $op, $date)
                    ->where(function ($q) use ($stampCols, $vehicleId) {
                        foreach ($stampCols as $c) {
                            $q->orWhere($c . '_vehicle_id', $vehicleId);
                        }
                    })
                    ->whereRaw($sane);

                $stBefore = $stampedRows('<')->selectRaw('MAX(' . $highExpr . ') AS m')->value('m');
                if ((int) $stBefore > 0) $floorC[] = (int) $stBefore;

                $stAfter = $stampedRows('>')->selectRaw('MIN(' . $lowExpr . ') AS m')->value('m');
                if ((int) $stAfter > self::MIN_METER) $ceilC[] = (int) $stAfter;
            }

            // ── The machine-keyed evidence this window used to ignore completely ──────────
            //    Both sources name the vehicle in their own row, so they are trusted, and
            //    MachineAttribution has always read them. This is the window catching up with
            //    the engine the Bikes page draws from.
            foreach ($this->machineKeyedReadings($vehicleId) as $r) {
                if ($r['d'] === null) continue;
                if ($r['d'] < $date && $r['m'] > 0)               $floorC[] = $r['m'];
                if ($r['d'] > $date && $r['m'] > self::MIN_METER) $ceilC[]  = $r['m'];
            }

            // ── RULE P — admit a rider-keyed reading only if it is plausible FOR THIS MACHINE.
            //    The spine is everything machine-keyed we just collected; see rulePlausible().
            $spine = array_values(array_filter($floorC, fn ($v) => $v > self::MIN_METER));
            foreach ($ceilC as $v) {
                if ($v > self::MIN_METER) $spine[] = $v;
            }
            foreach ($riskFloorC as $v) {
                if ($this->rulePlausible($v, $spine, $vehicleId, 'floor')) $floorC[] = $v;
            }
            foreach ($riskCeilC as $v) {
                if ($this->rulePlausible($v, $spine, $vehicleId, 'ceil'))  $ceilC[]  = $v;
            }

            $floor = $readingLevel ? $this->guardedFloor($floorC, $vehicleId, $date) : max($floorC);
            return self::$windowMemo[$memoKey] = [
                'floor' => $floor > self::MIN_METER ? $floor : null,
                'ceil'  => $ceilC ? min($ceilC) : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('meterWindowFor failed', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
            // ⚠ A failure is NOT memoised: it is usually transient (a lock, a timeout),
            //   and caching it would keep the caller on the rider-keyed fallback for
            //   the rest of the request.
            return null;
        }
    }

    /**
     * Readings that name THIS machine in their OWN row — the meter log a manager types on the
     * Vehicles page, and the odometer written down at the moment the machine changed hands.
     *
     * ⚠⚠ `meterWindowFor` read NEITHER of these before Aug-22 2026. That is how the Van's day
     *    card could show a 6,434 km attendance reading while `handover_meter` on the very same
     *    day said 73,688: the machine's own evidence existed and the window was not looking at
     *    it. `MachineAttribution` has always read both, which is why the two screens disagreed.
     */
    private function machineKeyedReadings(int $vehicleId): array
    {
        // ⚠⚠ MEMOISED, AND IT MUST BE. `meterWindowFor()` is called ONCE PER MACHINE PER DATE by
        //    the Bikes sheet and the month engine, and this method fires TWO queries every time.
        //    Unmemoised it multiplies the page's query count by the number of dates in the month —
        //    the same mistake `riderDayMap` documents (999 ms / 318 queries against 458 / 156), and
        //    on shared hosting that is the difference between a slow page and a 503.
        //    `rawMachineReadings()` next to it has always had this memo; this one shipped without.
        if (array_key_exists($vehicleId, self::$machineReadMemo)) {
            return self::$machineReadMemo[$vehicleId];
        }

        $out = [];
        try {
            if (self::hasTbl(self::T_METER_LOG)) {
                foreach (DB::table(self::T_METER_LOG)->where('vehicle_id', $vehicleId)
                            ->get(['meter_start', 'meter_end', 'log_date']) as $r) {
                    $d = $r->log_date ? substr((string) $r->log_date, 0, 10) : null;
                    foreach ([$r->meter_start, $r->meter_end] as $m) {
                        if ($m !== null && (int) $m > 0) $out[] = ['m' => (int) $m, 'd' => $d];
                    }
                }
            }
        } catch (\Throwable $e) { /* table absent → no evidence, never an error */ }
        try {
            if ($this->hasHandoverMeter()) {
                foreach (DB::table(self::T_ASSIGN)->where('vehicle_id', $vehicleId)
                            ->whereNotNull('handover_meter')
                            ->get(['handover_meter', 'assigned_on']) as $a) {
                    if ((int) $a->handover_meter > 0 && $a->assigned_on) {
                        $out[] = ['m' => (int) $a->handover_meter,
                                  'd' => substr((string) $a->assigned_on, 0, 10)];
                    }
                }
            }
        } catch (\Throwable $e) { /* column not migrated → nothing to add */ }
        // ⭐ Meter replaced (Oct-2026): on the machine's continuous scale, like every other
        //   reading the window and Rule P weigh these against. No-op without a replacement.
        if (MeterReplacement::has($vehicleId)) {
            foreach ($out as $i => $r) {
                $out[$i]['m'] = (int) MeterReplacement::toContinuous($vehicleId, $r['m'], $r['d']);
            }
        }
        return self::$machineReadMemo[$vehicleId] = $out;
    }

    /**
     * ⭐⭐ RULE P — is a RIDER-keyed reading plausible for THIS machine?
     *
     * ⚠⚠ THE PROD INCIDENT (the Van, 20–21 Aug 2026). Rajab's own bike reads ~6,500; the Van
     *    reads ~73,800. He photographed his own bike's meter at 11:58 and the Van reached him at
     *    12:09 — both entirely correct actions. But `t_ops_attendance` holds ONE meter pair per
     *    rider-day and the registry ONE open assignment per rider, so his own bike's reading was
     *    filed against the Van. A magnitude test against the machine's OWN spine is what tells
     *    the two apart, and it is the same philosophy MachineAttribution states as rule R-B:
     *    plausibility is relative to the machine's own chain, never an absolute floor.
     *
     * ⭐ FAILS OPEN, deliberately. No spine — a machine with no evidence of its own, a brand-new
     *   bike — means every reading is accepted, exactly as before this rule existed. Rejecting on
     *   silence would blank out young machines, which is the precise mistake the absolute
     *   MIN_METER floor made on EDN-198.
     */
    /**
     * ⭐⭐ R10 — HOW FAR FROM ITS OWN CHAIN IS STILL "THE SAME MACHINE"? (Aug-22 2026)
     *
     * ⚠⚠ A FLAT ±MAX_GAP_KM IS WRONG WHEN THE SPINE IS THIN. Rule P's first day on prod refused a
     *    perfectly good 47,275 against a lone 49,521 (AY-4771): with a single known point, ±2,000 km
     *    is a 4,000 km keyhole, and any reading older than a couple of months falls outside it. The
     *    question Rule P asks is "could this be a DIFFERENT machine's odometer?", and that is a
     *    question of PROPORTION — 47,275 vs 49,521 is obviously the same bike; 6,639 vs 73,900
     *    obviously is not.
     *
     * ⭐ So the tolerance scales with the odometer: 20% of the machine's own highest reading, never
     *   less than MAX_GAP_KM. A new bike reading 400 km keeps the flat 2,000 floor (percentages are
     *   useless there); a 74,000 km van gets ~14,800, which still rejects a 6,639 by a mile.
     *
     * ⚠ Deliberately generous. Every failure mode of being too LOOSE is caught downstream (the
     *   floor is a MAX, the ceil keeps MIN_METER, the typo guard still runs); being too TIGHT
     *   silently deletes real history, which is what this incident was.
     */
    private static function spineToleranceKm(int $spineHigh): int
    {
        return (int) max(self::MAX_GAP_KM, round($spineHigh * 0.20));
    }

    private function rulePlausible(int $value, array $spine, int $vehicleId, string $side): bool
    {
        if (!$spine) return true;
        $lo = min($spine);
        $hi = max($spine);
        $tol = self::spineToleranceKm($hi);
        if ($value >= $lo - $tol && $value <= $hi + $tol) {
            return true;
        }
        Log::warning('Rule P: reading rejected as implausible for this machine', [
            'vehicle' => $vehicleId, 'side' => $side, 'value' => $value,
            'spine_lo' => $lo, 'spine_hi' => $hi, 'tolerance_km' => $tol,
            'spine_points' => count($spine),
        ]);
        return false;
    }

    /**
     * R2 — the upward-typo guard. Sorted descending, drop the leader while it stands more than
     * MAX_GAP_KM above the next candidate: an extra digit lands tens of thousands out, a genuine
     * long gap does not.
     *
     * ⭐ Fails OPEN (keeps the value) when there is nothing behind it to compare against, and the
     *   direction is the safe one: when in doubt the floor lands LOWER, which never blocks a
     *   rider and never accuses one — it only makes the claim gate slightly more permissive.
     */
    private function guardedFloor(array $floorC, int $vehicleId, string $date): int
    {
        $c = array_values(array_unique(array_filter($floorC, fn ($v) => $v > 0)));
        if (count($c) < 2) return $floorC ? max($floorC) : 0;
        rsort($c);
        while (count($c) >= 2 && ($c[0] - $c[1]) > self::MAX_GAP_KM) {
            Log::warning('Floor candidate dropped as an implausible jump', [
                'vehicle' => $vehicleId, 'date' => $date, 'dropped' => $c[0], 'next' => $c[1],
            ]);
            array_shift($c);
        }
        return $c[0];
    }

    /**
     * ⭐⭐ PHASE D — DOES THE MANAGER'S DAY-OVERRIDE REACH THE MACHINE'S OWN VIEWS?
     *
     * `t_ops_attendance.vehicle_id` is the manager saying "on THAT day he was on
     * THIS machine" — a bike borrowed for an afternoon, a swap nobody recorded at
     * the time. The rider-side chips honoured it from the start, but every view of
     * the MACHINE (day-by-day, odometer window, claim attribution) read the
     * assignment timeline alone. So a manager could correct a day, watch the chip
     * change, and still see the kilometres sitting on the wrong bike — the
     * correction went nowhere useful.
     *
     * Applied in BOTH directions, which is the part that matters:
     *   • a day pointed AT this machine joins its chain, whoever rode it;
     *   • a day pointed AWAY leaves its keeper's window, so a borrowed bike's
     *     24,800 km reading stops landing in an own-bike chain that reads 9,000.
     *
     * Expressed as a query constraint rather than a PHP set on purpose: it has to
     * apply inside the same MAX/MIN the odometer window already runs, and a set
     * would mean pulling every row into memory to filter it.
     */
    /** Has SQL-BIKES-HANDOVER-METER-AUG2026 been run? */
    private function hasHandoverMeter(): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        try {
            $ok = self::hasCol(self::T_ASSIGN, 'handover_meter');
        } catch (\Throwable $e) {
            $ok = false;
        }
        return $ok;
    }

    private function hasDayOverride(): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        try {
            $ok = self::hasCol('t_ops_attendance', 'vehicle_id');
        } catch (\Throwable $e) {
            $ok = false;
        }
        return $ok;
    }

    /**
     * Rows inside a keeper's window that have NOT been reassigned to another
     * machine. (No override column → every row in the window counts, exactly as
     * before this existed.)
     */
    private function scopeWindowRows($q, int $vehicleId)
    {
        if (!$this->hasDayOverride()) return $q;
        return $q->where(function ($w) use ($vehicleId) {
            $w->whereNull('vehicle_id')->orWhere('vehicle_id', $vehicleId);
        });
    }

    /**
     * ⭐⭐ THE DAYS INSIDE A KEEPER'S WINDOW THAT BELONG TO A DIFFERENT MACHINE.
     *
     * ⚠⚠ THE PROD BUG THIS EXISTS FOR (EDN-198, Aug-16-2026). The odometer
     *    reconstruction and the claim-validation window both walk a keeper's
     *    assignment window and take EVERY reading he made inside those dates. That
     *    is wrong whenever two windows overlap the same day — and a same-day
     *    assignment reversed within the day leaves exactly that.
     *
     *    EDN-198 was mis-assigned to Asim on 2026-08-09 and handed straight back:
     *        Farooq 08-08 → 08-09  ·  Asim 08-09 → 08-09  ·  Farooq 08-09 → OPEN
     *    Asim also holds his OWN bike (an OPEN row). The window loop pulled his
     *    own bike's 12,390 km into a brand-new EDN-198 whose real odometer is ~659,
     *    which then became the floor every claim on it was judged against —
     *    Farooq's honest 659 km oil-change was refused as "lower than this bike's
     *    12,390 km".
     *
     * ⭐ THE RESOLVER ALREADY KNEW BETTER. `vehicleForDay` ranks an OPEN assignment
     *    above a closed one, so it correctly puts Asim's 08-09 readings on his own
     *    bike. The reconstruction simply never asked it. This bridges the two.
     *
     * ⚠⚠ EXCLUDES ONLY WHEN THE RESOLVER NAMES A **DIFFERENT** MACHINE — never on
     *    silence. That asymmetry is deliberate and load-bearing: a day the resolver
     *    cannot place must keep counting exactly as it does today, or the outgoing
     *    rider's last readings would vanish from the machine he just handed over and
     *    we would trade one wrong number for another.
     *
     * @return string[] Y-m-d dates to exclude (usually empty)
     */
    private function datesAttributedElsewhere(int $userId, string $from, ?string $to, int $vehicleId): array
    {
        $to = $to ?: date('Y-m-d');
        $key = $userId . '|' . $from . '|' . $to . '|' . $vehicleId;
        if (array_key_exists($key, self::$elsewhereMemo)) return self::$elsewhereMemo[$key];

        $out = [];
        try {
            foreach ($this->riderDayMap($userId, $from, $to) as $d => $vid) {
                if ($vid === null) continue;                  // no opinion ⇒ keep the day
                if ((int) $vid === $vehicleId) continue;      // his day, on this machine
                $out[] = $d;
            }
        } catch (\Throwable $e) {
            // Fail OPEN: an unresolvable range must not start deleting readings.
            Log::warning('datesAttributedElsewhere failed', [
                'user' => $userId, 'vehicle' => $vehicleId, 'error' => $e->getMessage(),
            ]);
            $out = [];
        }
        return self::$elsewhereMemo[$key] = $out;
    }

    /**
     * date => vehicleId for one rider over one range.
     *
     * ⚠ Memoised WITHOUT the vehicle in the key, and that is the whole point: the
     *   answer "which machine was he on that day" does not depend on who is asking.
     *   Keying it per-vehicle rebuilt the same day-by-day map once per machine and
     *   doubled the Bikes sheet (999 ms / 318 queries against 458 / 156). One rider,
     *   one range, one map — every machine then filters the same copy.
     *
     * @return array<string,int> ['Y-m-d' => vehicle_id]
     */
    private function riderDayMap(int $userId, string $from, string $to): array
    {
        $key = $userId . '|' . $from . '|' . $to;
        if (array_key_exists($key, self::$dayMapMemo)) return self::$dayMapMemo[$key];

        $out = [];
        try {
            foreach ((new VehicleResolver())->vehiclesForDays([$userId], $from, $to) as $k => $vid) {
                $out[substr((string) $k, strpos((string) $k, '|') + 1)] = $vid;
            }
        } catch (\Throwable $e) {
            $out = [];
        }
        return self::$dayMapMemo[$key] = $out;
    }

    /** The most recent keeper of every machine, held or not. One query. */
    private function lastKeepers(): array
    {
        $out = [];
        try {
            $rows = DB::table(self::T_ASSIGN . ' as a')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'a.user_id')
                ->orderBy('a.assigned_on')->orderBy('a.id')
                ->get(['a.vehicle_id', 'a.user_id', 'u.fullname']);
            foreach ($rows as $r) {           // later row wins
                $out[(int) $r->vehicle_id] = ['user_id' => (int) $r->user_id, 'name' => $r->fullname];
            }
        } catch (\Throwable $e) { /* the label is a nicety */ }
        return $out;
    }

    /** Every [from, to] window this vehicle has been held in. `to` null = still held. */
    private function assignmentWindows(int $vehicleId): array
    {
        try {
            return DB::table(self::T_ASSIGN)
                ->where('vehicle_id', $vehicleId)
                ->orderBy('assigned_on')
                ->get(['user_id', 'assigned_on', 'released_on'])
                ->map(fn ($a) => [
                    'user_id' => (int) $a->user_id,
                    'from'    => substr((string) $a->assigned_on, 0, 10),
                    'to'      => $a->released_on ? substr((string) $a->released_on, 0, 10) : null,
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * ⭐ THE SAME WINDOWS, WITH HISTORY BEFORE THE REGISTRY CREDITED TO THE FIRST
     *    KEEPER (owner ruling, Aug-5).
     *
     * The registry was seeded in Aug-2026 with `assigned_on` dates that are when we
     * started RECORDING who had what — not when they got it. The owner confirms the
     * bikes had not changed hands: Kanan, Waseem and Arslan were on the same machines
     * before the seed. Without this, every month before the seed date attributes to
     * nobody, so the last-3-months baseline a manager compares against is built from
     * fuel with no kilometres behind it.
     *
     * ⚠ DERIVED, NEVER HARDCODED. It extends the EARLIEST window backwards — so the
     *   keeper it credits is whoever the seed says held it first. Naming the three
     *   riders in code would be a landmine the day a bike is re-seeded, and there is
     *   more than one "Arslan" on the user table (only the active one holds a window).
     *
     * Rows resolved this way are flagged `assumed` so the screen can say so: an
     * inference is displayed as an inference, never as a recorded fact.
     */
    private function attributionWindows(int $vehicleId): array
    {
        $w = $this->assignmentWindows($vehicleId);
        if (!$w) return $w;

        $w[0]['real_from'] = $w[0]['from'];
        $w[0]['from']      = self::PRE_REGISTRY_FROM;
        $w[0]['assumed']   = true;
        for ($i = 1; $i < count($w); $i++) {
            $w[$i]['real_from'] = $w[$i]['from'];
            $w[$i]['assumed']   = false;
        }
        return $w;
    }

    /** Shape one DB row into the payload both web and mobile read. */
    private function shape($r, ?int $meter, int $defaultInterval, int $photoCount): array
    {
        // ⚠⚠ DERIVED, NOT READ OFF THE ROW (Aug-16). This block used to be computed
        //    straight from `$r->last_service_meter` / `$r->service_interval_km`.
        //    Those columns are a SEED that nothing has ever updated — both service
        //    writers stamp the rider's profile — so from the first service after the
        //    registry was created they froze, and this payload (the rider's My
        //    Vehicle banner and every web fleet card) drifted away from the schedule
        //    panel and the alerts, which derive. One bike read "overdue by 233 km"
        //    on the phone and "142 km left" on the web on the same afternoon.
        //    The engine below reads the machine's own evidence; the seeded columns
        //    are still consulted INSIDE it, as one candidate among several.
        $svc = $this->overallServiceStateFor(
            (int) $r->id,
            $meter,
            $r,
            isset($r->keeper_user_id) && $r->keeper_user_id ? (int) $r->keeper_user_id : null,
            $defaultInterval
        );

        return [
            'id'         => (int) $r->id,
            'vtype'      => (string) $r->vtype,
            'reg_no'     => $r->reg_no,
            'nickname'   => $r->nickname,
            'name'       => $this->displayName($r),
            'is_company' => (int) $r->is_company === 1,
            'make_model' => $r->make_model,
            'is_active'  => (int) $r->is_active === 1,
            'notes'      => $r->notes,

            'keeper_user_id' => $r->keeper_user_id ? (int) $r->keeper_user_id : null,
            'keeper_name'    => $r->keeper_name,
            'assigned_on'    => $r->assigned_on ? substr((string) $r->assigned_on, 0, 10) : null,
            // ⚠ Two consumers (VehicleController::forUser and the mobile fleet card)
            //   have always read `keeper_since`, which this shape never produced — so
            //   the app's "since <date>" line silently rendered nothing. Same value,
            //   under the name they ask for.
            'keeper_since'   => $r->assigned_on ? substr((string) $r->assigned_on, 0, 10) : null,
            'assignment_id'  => $r->assignment_id ? (int) $r->assignment_id : null,

            'current_meter' => $meter,
            // Same keys, same meanings, same thresholds as before — only the source
            // changed. Every installed APK and every web card keeps working unchanged.
            'service' => $svc,

            /**
             * ⚠⚠ THE STORED OVERRIDE, KEPT SEPARATE FROM THE DERIVED INTERVAL.
             *
             * `service.interval_km` is now DERIVED (the due job's own schedule), so an
             * edit form that pre-fills from it would show 1,200 for a bike whose
             * override is NULL — and saving would silently convert "follow the company
             * default" into a hard-coded 1,200 on every bike a manager ever opened.
             * That is a settings field quietly learning a computed value, which is how
             * a derived number becomes a stored one and the whole split-brain starts
             * over. Every EDIT surface must bind to this raw column; only DISPLAY
             * surfaces read `service.interval_km`.
             *
             * null = "follow the company default", and it must stay null.
             */
            'service_interval_override' => $r->service_interval_km !== null
                ? (int) $r->service_interval_km : null,

            'base' => [
                'has_base'  => $r->base_latitude !== null && $r->base_longitude !== null,
                'latitude'  => $r->base_latitude !== null ? (float) $r->base_latitude : null,
                'longitude' => $r->base_longitude !== null ? (float) $r->base_longitude : null,
                'radius_m'  => $r->base_radius_m !== null ? (int) $r->base_radius_m : null,
            ],

            'photo_count' => $photoCount,

            /**
             * ⭐ STANDING NAG: this machine's overnight and morning meter checks
             *    have nowhere to measure from (owner ruling Aug-4: "an alert
             *    should stay telling the manager a home location needs to be set,
             *    and go away once entered").
             *
             *    True only when it MATTERS: a COMPANY machine, actually held by
             *    someone, with neither its own base (the van's parking) nor the
             *    keeper's home pin. A personal bike or an unassigned one is
             *    nobody's problem, so it stays quiet — an alert that cries wolf
             *    gets ignored, and this one has a real job.
             *
             *    It clears itself the moment the pin is saved; there is nothing
             *    to dismiss and nothing to remember.
             */
            'needs_home_pin' => (int) $r->is_company === 1
                && !empty($r->keeper_user_id)
                && $r->base_latitude === null
                && (!property_exists($r, 'keeper_home_lat') || $r->keeper_home_lat === null),

            /**
             * 🏠 THE KEEPER'S SAVED HOME, on the card itself (owner ruling 10-Sep-2026:
             *    "should be able to view the saved"). Until now the only signal was the
             *    absence of one — the nag above — so a manager could see that a pin was
             *    MISSING but never that the one on file was WRONG. A stale pin is the
             *    more expensive failure of the two: the checks run and quietly measure
             *    against the wrong house.
             *
             * ⚠ Read-only colour. Nothing here decides whether the home journey runs —
             *   HomeJourneyService::riderHomePin does, per day, from the registry.
             */
            'keeper_home_pin' => (property_exists($r, 'keeper_home_lat') && $r->keeper_home_lat !== null
                    && property_exists($r, 'keeper_home_lng') && $r->keeper_home_lng !== null)
                ? [
                    'lat' => (float) $r->keeper_home_lat,
                    'lng' => (float) $r->keeper_home_lng,
                    'maps_url' => 'https://www.google.com/maps/search/?api=1&query='
                        . rawurlencode(number_format((float) $r->keeper_home_lat, 7, '.', '')
                            . ',' . number_format((float) $r->keeper_home_lng, 7, '.', '')),
                ]
                : null,
        ];
    }

    /** "AY-4771", or the nickname when there is no plate (the van). */
    /**
     * ⚠⚠ ONE LABEL RULE — see `MeterPairHelper::labelOf` (13-Sep-2026).
     *
     * This was plate-first unconditionally, which named Rajab's PERSONAL bike "APPLIED-FOR"
     * (its placeholder registration). Caught on the real phone: the MY VEHICLE banner read
     * "🏍 APPLIED-FOR · 7,620 km" while the checkout dialog two taps later said "Rajab Masood -
     * own bike" — the same machine, two names, on one screen.
     *
     * ⚠ COMPANY machines are UNCHANGED (plate first), so every fleet card, assignment message
     *   and spare-vehicle picker reads exactly as it did. Only a non-company machine now prefers
     *   its nickname, and only when it has one.
     * ⚠ A row without `is_company` falls back to plate-first, i.e. the old behaviour.
     */
    private function displayName($v): string
    {
        return MeterPairHelper::labelOf($v) ?: ('Vehicle #' . ($v->id ?? '?'));
    }

    private function photoCounts(): array
    {
        try {
            return DB::table(self::T_PHOTO)->selectRaw('vehicle_id, COUNT(*) AS n')
                ->groupBy('vehicle_id')->pluck('n', 'vehicle_id')->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Newest photo per vehicle, for the card thumbnail. One query for the whole
     * grid — a per-card lookup would be a query per vehicle on every load.
     */
    private function latestPhotoPaths(): array
    {
        try {
            $rows = DB::table(self::T_PHOTO)
                ->orderBy('vehicle_id')->orderByDesc('taken_on')->orderByDesc('id')
                ->get(['vehicle_id', 'photo_path']);
            $out = [];
            foreach ($rows as $r) {
                if (!isset($out[$r->vehicle_id])) $out[$r->vehicle_id] = $r->photo_path;
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * ⭐ PHASE D — the rider's OWN registered machine, if it is free to give back.
     *
     * "His own bike" is not a column: it is the most recent NON-company machine the
     * registry has seen him on. When he is put on a company bike that assignment is
     * closed, and until now nothing ever reopened it — so a rider handed back his
     * company bike and silently had NO machine at all, with his own bike sitting
     * unassigned in the registry. This is what the handover prompt offers him.
     *
     * Returns null when he has no own machine on record, or when someone else
     * currently holds it (never take a machine off its keeper to give it back).
     */
    public function ownVehicleFor(int $userId): ?array
    {
        if (!$this->available()) return null;
        try {
            // ⭐⭐ "HIS OWN BIKE" MEANS HE IS ITS FIRST KEEPER, NOT "he rode it once"
            //    (Sep-2026 fix). This used to be "the newest non-company machine EVER
            //    assigned to him", which silently includes a colleague's personal bike
            //    he BORROWED. Live proof on the replica: Waseem held vehicle 5,
            //    *"Danish - own bike"*, for exactly one day (7 Aug). The old rule
            //    therefore answered "Waseem's own bike = Danish's bike" — and because
            //    'own' is the SILENT DEFAULT when the client sends no action, a
            //    displaced Waseem would have been handed Danish's personal bike with
            //    nobody deciding it. That is the same slip the pickers already guard
            //    against in the UI (a colleague's bike is never a quick pick).
            //
            // ⚠⚠ DELIBERATELY LOOSER THAN THE MONEY ENGINE — do not "unify" these.
            //    `RiderDayLegs::ownMachineIdsFor` demands EXCLUSIVITY (only ever him)
            //    because it decides whose kilometres and fuel get credited, and there
            //    ambiguity must mean nobody. Applying that here would punish the
            //    OWNER: one day's loan to Waseem would strip Danish of his own-bike
            //    fallback for good. The question here is different and gentler —
            //    "whose bike is this to hand back?" — so the test is FIRST KEEPER:
            //    the earliest assignment row on a non-company machine. Danish keeps
            //    his bike; Waseem is refused it. Every machine `ownMachineIdsFor`
            //    returns also passes this (only-ever-him ⇒ first-keeper), so this is
            //    a strict superset of the strict rule, never a contradiction of it.
            //
            // ⚠ A machine genuinely handed on for good keeps naming its FIRST keeper,
            //   so the new man gets no automatic fallback — a manager assigns it by
            //   hand. Conservative on purpose: failing to offer a bike is a nuisance,
            //   offering someone else's is the bug being fixed.
            $vid = null;
            $mine = DB::table(self::T_ASSIGN . ' as a')
                ->join(self::T_VEHICLE . ' as v', 'v.id', '=', 'a.vehicle_id')
                ->where('a.user_id', $userId)
                ->where('v.is_company', 0)
                ->where('v.is_active', 1)
                ->orderByDesc('a.assigned_on')->orderByDesc('a.id')
                ->pluck('a.vehicle_id')->map(fn ($x) => (int) $x)->unique()->values()->all();
            foreach ($mine as $candidate) {
                $first = DB::table(self::T_ASSIGN)->where('vehicle_id', $candidate)
                    ->orderBy('assigned_on')->orderBy('id')->first(['user_id']);
                if ($first && (int) $first->user_id === $userId) { $vid = $candidate; break; }
            }
            if (!$vid) return null;

            if ($this->keeperOf((int) $vid)) return null;      // somebody has it

            $v = DB::table(self::T_VEHICLE)->where('id', $vid)->first();
            // ⚠ `vtype`/`is_company` are additive and exist so the phone can apply the ONE
            //   naming rule (machineWords) to this machine too — see the twin note on
            //   VehicleHandoverRequestService::options().
            return $v ? [
                'id'         => (int) $v->id,
                'name'       => $this->displayName($v),
                'vtype'      => (string) ($v->vtype ?? 'bike'),
                'is_company' => (int) ($v->is_company ?? 0) === 1,
            ] : null;
        } catch (\Throwable $e) {
            Log::warning('ownVehicleFor failed', ['user' => $userId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Active machines nobody currently holds — what a displaced rider can be moved
     * onto, and what the "no rider" side of the fleet screen lists.
     */
    public function spareVehicles(): array
    {
        if (!$this->available()) return [];
        try {
            $held = DB::table(self::T_ASSIGN)->whereNull('released_on')
                ->pluck('vehicle_id')->map(fn ($v) => (int) $v)->all();

            return DB::table(self::T_VEHICLE)->where('is_active', 1)
                ->when($held, fn ($q) => $q->whereNotIn('id', $held))
                ->orderByDesc('is_company')->orderBy('id')
                ->get()
                ->map(fn ($v) => [
                    'id'         => (int) $v->id,
                    'name'       => $this->displayName($v),
                    'is_company' => (int) $v->is_company === 1,
                    'vtype'      => (string) $v->vtype,
                ])->all();
        } catch (\Throwable $e) {
            Log::warning('spareVehicles failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function openAssignmentForUser(int $userId)
    {
        try {
            return DB::table(self::T_ASSIGN)->where('user_id', $userId)
                ->whereNull('released_on')->orderByDesc('id')->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Close an assignment row. released_on can never precede assigned_on — a
     * backdated handover would otherwise produce a row that ended before it began
     * and break every "who had it on date X" read.
     */
    private function closeAssignment(int $assignmentId, string $date, ?int $actorId): void
    {
        $row = DB::table(self::T_ASSIGN)->where('id', $assignmentId)->first(['assigned_on']);
        $on  = $date;
        if ($row && $row->assigned_on && substr((string) $row->assigned_on, 0, 10) > $date) {
            $on = substr((string) $row->assigned_on, 0, 10);
        }
        DB::table(self::T_ASSIGN)->where('id', $assignmentId)->update([
            'released_on' => $on,
            'released_by' => $actorId,
            'updated_at'  => now(),
        ]);
    }

    /** Drop the profile mirror only when it still points at the vehicle being taken. */
    private function clearMirrorIfPointsAt(int $userId, int $vehicleId): void
    {
        DB::table('t_ops_rider_profile')
            ->where('user_id', $userId)
            ->where('default_vehicle_id', $vehicleId)
            ->update(['default_vehicle_id' => null, 'updated_at' => now()]);
        // company_bike is deliberately NOT reset here — see assign()'s block comment.
    }

    private function normaliseReg($raw): ?string
    {
        $s = strtoupper(trim((string) $raw));
        if ($s === '') return null;
        $s = preg_replace('/[\s\-_]+/', '-', $s);
        return mb_substr($s, 0, 32);
    }

    /** A real Y-m-d, never in the future (an open row dated tomorrow reads as a bug). */
    private function safeDate($raw): string
    {
        try {
            $c = $raw ? Carbon::parse($raw) : Carbon::today();
        } catch (\Throwable $e) {
            $c = Carbon::today();
        }
        if ($c->gt(Carbon::today())) $c = Carbon::today();
        return $c->format('Y-m-d');
    }

    private function publicUrl(?string $path): ?string
    {
        if (!$path) return null;
        $rel = '/public-storage/' . ltrim($path, '/');
        try {
            $base = request() ? request()->getSchemeAndHttpHost() : null;
        } catch (\Throwable $e) {
            $base = null;
        }
        return $base ? rtrim($base, '/') . $rel : $rel;
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

    private function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'id' => null];
    }
}
