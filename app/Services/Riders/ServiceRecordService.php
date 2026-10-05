<?php

namespace App\Services\Riders;

use App\Models\Riders\MaintenanceTypeModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 🛢 RECORDING THAT A SERVICE HAPPENED — the ONE place that rule lives (Sep-2026).
 *
 * ⭐⭐ WHY THIS EXISTS. Three different people can now tell the system a service was done:
 *      • a manager, from the Bikes screen (`FleetFuelController::markServiced`);
 *      • a manager, by completing a workshop visit;
 *      • THE RIDER, by answering "did it get done?" on his own visit (owner ruling,
 *        2-Sep) — and he holds no `manage_bike_service` key at all.
 *    Their PERMISSION gates differ, so they cannot share a controller method. Their
 *    RULE must not differ, or this round's whole point is lost: which countdown resets,
 *    whether the bike's overall clock moves, and that a type is mandatory. So the gate
 *    stays in each caller and the rule lives here, called by all three.
 *
 * ⚠⚠ THE TYPE IS REQUIRED whenever a meter is given and scheduled types exist. The old
 *    "guess the shortest clock-resetting type" fallback silently misfiled a real service
 *    (t_fleet_service_log #8) and is deliberately gone. See
 *    [[record-service-untyped-fallback-trap]].
 *
 * ⚠ This writes the SERVICE RECORD only. It never touches `service_interval_km` — the
 *   schedule is a separate decision with a separate button, and conflating them is what
 *   made "Record service" and "This bike" behave identically once before.
 */
class ServiceRecordService
{
    /** Active maintenance types that actually have a countdown to reset. */
    /**
     * The jobs that can be RECORDED, for one kind of machine (class-aware Sep-2026).
     *
     * ⚠ `has_schedule`, not `interval_km > 0`: a TIME-based job counts down in days
     *   and reports 0 km by design, so the old test would have hidden it from every
     *   picker and left it with a visible countdown nothing could reset — exactly the
     *   Brake Shoe bug from Aug-3, one unit over.
     *
     * @param ?string $class 'bike' | 'van' — null keeps the pre-class behaviour
     */
    public function scheduledTypes(?string $class = null): array
    {
        try {
            $svc  = app(MaintenanceTypeService::class);
            $rows = $class === null ? $svc->options() : $svc->optionsFor($class);
            return array_values(array_filter(
                $rows,
                fn ($t) => !empty($t['has_schedule']) || (int) ($t['interval_km'] ?? 0) > 0
            ));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * ⭐⭐ EVERY JOB THAT CAN BE RECORDED — not only the ones with a countdown
     *    (owner ruling, 11-Sep-2026).
     *
     * ⚠⚠ WHY THIS EXISTS. `scheduledTypes()` answers "which countdowns can be reset", and the
     *    close dialogs were using it as if it answered "what can a workshop have done" — two
     *    different questions. On prod only two of the four types carry a kilometre figure, so a
     *    manager closing a visit saw TWO choices and no way to record the brake shoes he had
     *    just paid for. The owner's rule: *"even though there are no intervals set, it should
     *    still log it."* Work that happened must be recordable; whether a clock moves is a
     *    SEPARATE fact, and it travels on the row as `counts_down`.
     *
     * ⭐ Scheduled jobs come FIRST so the ordinary case is still the top of the list, and every
     *   row says which kind it is, so a picker can label the rest "no countdown" rather than
     *   hiding them.
     *
     * @param ?string $class 'bike' | 'van' — null keeps the pre-class behaviour
     * @return array<int, array> each row + counts_down:bool, applies:bool
     */
    public function typesForClose(?string $class = null): array
    {
        try {
            $svc = app(MaintenanceTypeService::class);
            $all = $svc->options();

            /**
             * ⚠⚠ THE UNION, NOT `optionsFor()` ALONE — and this is the whole van bug.
             *    `optionsFor($class)` drops every type whose `applies_to` does not match, and
             *    after the Sep-10 SQL every type defaults to **bike**. So a van's list came
             *    back EMPTY and its visit could not be closed with a meter at all. Caught by
             *    the proof, not by reading the code.
             *
             * ⭐ So: start from every active job, and let the class-resolved list supply the
             *   per-class FIGURES for the ones that do apply. A job that does not apply to this
             *   machine is still offered — labelled, and counting down nothing. A van job typed
             *   as a bike job is a labelling error for a manager to fix later; it must never be
             *   the reason the work cannot be written down.
             */
            $applicable = [];
            if ($class !== null) {
                foreach ($svc->optionsFor($class) as $t) {
                    $applicable[(int) ($t['id'] ?? 0)] = $t;
                }
            }

            $out = [];
            foreach ($all as $raw) {
                $id      = (int) ($raw['id'] ?? 0);
                $applies = $class === null || isset($applicable[$id]);
                // Per-class figures when they exist, the raw row otherwise.
                $t       = $applies && isset($applicable[$id]) ? $applicable[$id] : $raw;

                $counts = $applies
                    && (!empty($t['has_schedule']) || (int) ($t['interval_km'] ?? 0) > 0);

                $out[] = $t + [
                    'counts_down' => $counts,
                    'applies'     => $applies,
                ];
            }

            usort($out, function ($a, $b) {
                if ($a['counts_down'] !== $b['counts_down']) return $a['counts_down'] ? -1 : 1;
                return strcasecmp((string) ($a['type_name'] ?? ''), (string) ($b['type_name'] ?? ''));
            });
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** The class of the machine this rider is on today — for the pickers and gates. */
    public function classForRider(?int $riderId, ?string $date = null): ?string
    {
        return $this->classFor(null, $riderId, $date);
    }

    /**
     * ⭐⭐ WHICH MACHINE IS THIS SERVICE ABOUT? — THE ONE ANSWER (owner ask, 10-Sep-2026:
     *    *"the maintenance records follow the vehicle, and the registry decides which user
     *    is writing it"*).
     *
     * Every door that records work asks THIS, and nothing else:
     *   • the workshop completion         → the VISIT names the machine, explicitly;
     *   • the Bikes screen / vehicle card → the CARD is a machine, so it sends its id;
     *   • the rider's own "ho gaya?"      → the visit again;
     *   • a rider-first form with no machine in scope, or an older client
     *                                     → the registry, `vehicleForDay(rider, date)`.
     *
     * ⚠⚠ WHY AN EXPLICIT ID MUST WIN. The registry answers "what is this man on THAT DAY",
     *    which stops being the same question as "which bike was serviced" the moment the two
     *    diverge — and taking a bike to the workshop is precisely when they diverge, because
     *    the manager hands him a spare while it is in. Deriving then credits the oil change
     *    to the spare, silently, while the real machine's countdown keeps running.
     *
     * ⚠ An id that names no vehicle is IGNORED rather than trusted — it falls through to the
     *   registry, which is the old behaviour and never worse than it.
     */
    public function vehicleForRecord($explicitVehicleId, ?int $riderId, ?string $date = null): ?int
    {
        $day = $date ?: \Carbon\Carbon::today()->format('Y-m-d');
        $vid = (int) ($explicitVehicleId ?: 0);
        try {
            if ($vid > 0 && (new VehicleService())->find($vid)) return $vid;
        } catch (\Throwable $e) {
            // fall through to the registry — a lookup wobble must not lose the recording
        }
        if (!$riderId) return null;
        try {
            $d = (new VehicleResolver())->vehicleForDay((int) $riderId, $day);
            return $d ? (int) $d : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The class (bike|van) whose schedule this recording is judged against — resolved from
     * the MACHINE when one is known, and only then from the rider's day.
     * ⚠ Same precedence as `vehicleForRecord`, deliberately: the job list a form offers and
     *   the machine the record lands on must never come from two different answers.
     */
    public function classFor($vehicleId, ?int $riderId, ?string $date = null): ?string
    {
        $vid = $this->vehicleForRecord($vehicleId, $riderId, $date);
        if (!$vid) return null;
        try {
            return (new VehicleService())->classOf($vid);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @internal memo for logStampsVehicle(); a class static so a test can reset it. */
    private static ?bool $logVehMemo = null;

    /**
     * Does `t_fleet_service_log` carry the machine yet? (`service_log_vehicle_sep2026.sql`)
     *
     * ⚠ Memoised per process and consulted by every reader in VehicleService too, so there
     *   is ONE answer to "may I trust the stamp" rather than six schema calls per render.
     */
    public static function logStampsVehicle(): bool
    {
        if (self::$logVehMemo !== null) return self::$logVehMemo;
        try {
            self::$logVehMemo = Schema::hasTable('t_fleet_service_log')
                && Schema::hasColumn('t_fleet_service_log', 'vehicle_id');
        } catch (\Throwable $e) {
            self::$logVehMemo = false;
        }
        return self::$logVehMemo;
    }

    /** @internal memo for logKeepsPhoto(); see logStampsVehicle() for why it is a static. */
    private static ?bool $logPhotoMemo = null;

    /**
     * Does `t_fleet_service_log` carry the proof photo yet?
     * (`PENDING-PROD-SEP12-2026-WORKSHOP-R2.sql`)
     *
     * ⚠ Before that SQL runs the photo is simply not kept — the service record itself is
     *   written exactly as before, so this file is safe to upload first.
     */
    public static function logKeepsPhoto(): bool
    {
        if (self::$logPhotoMemo !== null) return self::$logPhotoMemo;
        try {
            self::$logPhotoMemo = Schema::hasTable('t_fleet_service_log')
                && Schema::hasColumn('t_fleet_service_log', 'photo_path');
        } catch (\Throwable $e) {
            self::$logPhotoMemo = false;
        }
        return self::$logPhotoMemo;
    }

    /** Test seam — see the ALTER-inside-a-transaction trap in the workshop round. */
    public static function flushSchemaMemo(): void
    {
        self::$logVehMemo = null;
        self::$logPhotoMemo = null;
    }

    /**
     * ⭐⭐ WHICH MACHINE DOES THIS LOG ROW BELONG TO — the ONE rule every reader applies.
     *
     * The stamp when there is one (a recorded fact), the registry when there is not (a row
     * filed before the column existed). Nothing else may decide this, or the countdown, the
     * history list, the meter chain and the alert sweep start disagreeing about one row.
     *
     * @param object|array $row  needs `user_id` and `service_date`, plus `vehicle_id` when
     *                           the caller selected it (it must, once the column exists)
     */
    public static function logVehicleOf($row, ?VehicleResolver $resolver = null): ?int
    {
        $get = fn (string $k) => is_array($row) ? ($row[$k] ?? null) : ($row->$k ?? null);

        $stamped = $get('vehicle_id');
        if (self::logStampsVehicle() && $stamped !== null && (int) $stamped > 0) {
            return (int) $stamped;
        }
        $uid = (int) ($get('user_id') ?: 0);
        if (!$uid) return null;
        try {
            $res = $resolver ?: new VehicleResolver();
            $d   = substr((string) $get('service_date'), 0, 10);
            $v   = $res->vehicleForDay($uid, $d);
            return $v ? (int) $v : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** The columns a per-machine reader must select — `vehicle_id` only once it exists. */
    public static function logVehicleCols(string $prefix = ''): array
    {
        return self::logStampsVehicle() ? [$prefix . 'vehicle_id'] : [];
    }

    /**
     * ⭐⭐ A BILL IS TIED TO A SERVICE BY BEING **CHOSEN**, NEVER BY BEING GUESSED
     *    (owner ruling, 3-Sep). An earlier draft of this matched a bill to a reading on
     *    "meter within 100 km and date within 7 days". The owner rejected that, and he was
     *    right: guessing is what misfiled service log #8, and a tolerance is not auditable —
     *    nobody can later say *why* two rows were treated as one job. So the person filing
     *    the bill picks the service from a list of his own un-billed readings, or says it is
     *    a new one. Nothing is ever inferred.
     *
     * ⚠⚠ A LINK IS ONLY LIVE WHILE THE CLAIM IS. Found in review: the de-duplication hid a
     *    linked claim whatever its status, and nothing on the reject path touched the log —
     *    so a REJECTED bill left the service reading as paid for ever, and a re-filed bill
     *    had nothing to attach to. A link therefore counts only while its claim is pending
     *    or approved; rejected and cancelled release the service to be billed again.
     */
    public const LIVE_BILL_STATUSES = ['pending', 'approved'];

    /**
     * Request ids whose link to a service log is LIVE, as [request_id => log_id].
     * The one reader of "is this claim already spoken for?" — used by both de-duplication
     * sites in VehicleService so the history and the evidence engine cannot disagree.
     */
    public static function liveBillLinks(): array
    {
        try {
            if (!Schema::hasTable('t_fleet_service_log')
                || !Schema::hasColumn('t_fleet_service_log', 'request_id')) {
                return [];
            }
            return DB::table('t_fleet_service_log as l')
                ->join('t_req_master as r', 'r.id', '=', 'l.request_id')
                ->whereNotNull('l.request_id')
                ->whereIn('r.status', self::LIVE_BILL_STATUSES)
                ->pluck('l.id', 'l.request_id')
                ->map(fn ($v) => (int) $v)
                ->all();
        } catch (\Throwable $e) {
            // A lookup failure must never hide real rows — fall back to "nothing is linked",
            // which shows both halves rather than silently dropping one.
            return [];
        }
    }

    /**
     * ⭐⭐ WHICH JOB RECORDS ARE ALREADY PAID FOR — as [log_id => request_id] (29-Sep-2026).
     *
     * ⚠⚠ WHY `liveBillLinks()` CANNOT ANSWER THIS. It is keyed by the BILL, and one bill may
     *    now cover several jobs (Qasim's one receipt for Oil + Tuning, Brake Shoe and Chain
     *    Set). Flipping it keeps ONE job per bill and silently drops the rest — which then read
     *    as un-billed, and can be billed a SECOND time. That is double money, so every
     *    "is this job billed?" question asks this instead. `liveBillLinks()` stays for the
     *    other question ("does a job speak for this claim?"), where bill-keyed is right.
     */
    public static function liveBilledLogIds(): array
    {
        try {
            if (!Schema::hasTable('t_fleet_service_log')
                || !Schema::hasColumn('t_fleet_service_log', 'request_id')) {
                return [];
            }
            $out = [];
            foreach (DB::table('t_fleet_service_log as l')
                        ->join('t_req_master as r', 'r.id', '=', 'l.request_id')
                        ->whereNotNull('l.request_id')
                        ->whereIn('r.status', self::LIVE_BILL_STATUSES)
                        ->get(['l.id', 'l.request_id']) as $r) {
                $out[(int) $r->id] = (int) $r->request_id;
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * ⭐⭐ WHAT A VISIT IS — the one derived definition (29-Sep-2026).
     *
     * The same machine, the same day, the same odometer. That is physically one trip: a bike
     * cannot show one reading on two separate visits. It is DERIVED rather than stored, so it
     * needs no new column and it already groups every record filed before this existed (Kanan,
     * 19-Sep: Chain Set + Oil + Tuning at 52,766 were entered one at a time and are one visit).
     *
     * ⚠ The machine comes from `logVehicleOf()` — the same rule every reader uses — so the
     *   visit a screen draws and the machine a countdown moves can never disagree.
     *
     * @param object|array $row needs user_id, service_date, meter (+ vehicle_id once it exists)
     */
    public static function visitKeyOf($row, ?VehicleResolver $resolver = null): string
    {
        $get = fn (string $k) => is_array($row) ? ($row[$k] ?? null) : ($row->$k ?? null);
        $vid = self::logVehicleOf($row, $resolver);
        return ($vid ? 'v' . $vid : 'u' . (int) $get('user_id'))
            . '|' . substr((string) $get('service_date'), 0, 10)
            . '|' . (int) $get('meter');
    }

    /**
     * The other job records of the same visit (never the row itself). Used by a visit edit, so
     * one wrong odometer is corrected on every job it was typed for — and by Remove, so a
     * workshop visit's link can move to a job that survives.
     *
     * @return array<int, object>
     */
    public function visitSiblingsOf(object $row): array
    {
        try {
            $cols = array_merge(['id', 'user_id', 'meter', 'service_date', 'maintenance_type_id', 'note'],
                                self::logVehicleCols(),
                                Schema::hasColumn('t_fleet_service_log', 'request_id') ? ['request_id'] : []);
            $key = self::visitKeyOf($row);
            $out = [];
            foreach (DB::table('t_fleet_service_log')
                        ->where('id', '<>', (int) $row->id)
                        ->whereDate('service_date', substr((string) $row->service_date, 0, 10))
                        ->where('meter', (int) $row->meter)
                        ->orderBy('id')
                        ->get($cols) as $r) {
                if (self::visitKeyOf($r) === $key) $out[] = $r;
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The job names of a set of records, in ONE order everywhere: oil services first (they are
     * what a manager reads first), then as they were recorded. "Oil + Tuning + Brake Shoe".
     * It is the bill's title when filed AND the label every claim list prints — one function,
     * so the approval queue, Daily Closing and the vehicle page read identically.
     */
    public function jobNamesOfLogs(array $logIds): string
    {
        $ids = self::normaliseIds($logIds);
        if (!$ids) return '';
        try {
            $rows = DB::table('t_fleet_service_log as l')
                ->leftJoin('t_fleet_maintenance_types as t', 't.id', '=', 'l.maintenance_type_id')
                ->whereIn('l.id', $ids)
                ->orderByDesc('t.resets_service_clock')->orderBy('l.id')
                ->get(['l.id', 't.type_name']);
            $names = [];
            foreach ($rows as $r) {
                $n = trim((string) ($r->type_name ?? '')) ?: 'Service';
                if (!in_array($n, $names, true)) $names[] = $n;
            }
            return implode(' + ', $names);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** @internal per-process memo for jobLabelsForClaims(); cleared by bustCaches(). */
    private static array $claimLabelMemo = [];

    /**
     * ⭐⭐ WHAT WAS THIS BILL FOR — for bills that cover MORE THAN ONE job (29-Sep-2026).
     *
     * A claim carries one `maintenance_type_id` (the lead job, so every older reader keeps
     * working), but a shared bill paid for several. Every place that prints a claim's job
     * asks `MaintenanceTypeService::labelFor(..., $requestId)`, which asks this — so Daily
     * Closing, the Bikes claim list, the vehicle page and spend-by-job all say
     * "Oil + Tuning + Brake Shoe" rather than quietly crediting one job with the whole receipt.
     *
     * @param int[] $requestIds
     * @return array<int, string> [request_id => "A + B"] — only claims linked to 2+ jobs
     */
    public function jobLabelsForClaims(array $requestIds): array
    {
        $ids = self::normaliseIds($requestIds);
        if (!$ids) return [];
        $want = array_values(array_filter($ids, fn ($i) => !array_key_exists($i, self::$claimLabelMemo)));
        if ($want) {
            foreach ($want as $i) self::$claimLabelMemo[$i] = null;
            try {
                if (Schema::hasTable('t_fleet_service_log') && Schema::hasColumn('t_fleet_service_log', 'request_id')) {
                    $by = [];
                    foreach (DB::table('t_fleet_service_log')->whereIn('request_id', $want)
                                ->orderBy('id')->get(['id', 'request_id']) as $r) {
                        $by[(int) $r->request_id][] = (int) $r->id;
                    }
                    foreach ($by as $rid => $logIds) {
                        if (count($logIds) > 1) self::$claimLabelMemo[$rid] = $this->jobNamesOfLogs($logIds) ?: null;
                    }
                }
            } catch (\Throwable $e) {
                // no label is better than a wrong one — the claim's own type still prints
            }
        }
        $out = [];
        foreach ($ids as $i) {
            if (!empty(self::$claimLabelMemo[$i])) $out[$i] = self::$claimLabelMemo[$i];
        }
        return $out;
    }

    /** Test seam + write hook: forget every combined claim label. */
    public static function flushClaimLabels(): void
    {
        self::$claimLabelMemo = [];
    }

    /**
     * The lead job of a set of records — the first OIL service, otherwise the first recorded.
     * The shared bill carries this job's type so every single-type reader (and every old APK)
     * still sees a sensible, real job on it.
     */
    /** The lead record of a set of ids — see leadLogOf(). A workshop visit links to this one. */
    public function leadLogId(array $logIds): ?int
    {
        $ids = self::normaliseIds($logIds);
        if (!$ids) return null;
        try {
            $rows = DB::table('t_fleet_service_log')->whereIn('id', $ids)->get(['id', 'maintenance_type_id'])->keyBy('id');
            $ordered = [];
            foreach ($ids as $id) if (isset($rows[$id])) $ordered[] = $rows[$id];
            $lead = $this->leadLogOf($ordered);
            return $lead ? (int) $lead->id : $ids[0];
        } catch (\Throwable $e) {
            return $ids[0];
        }
    }

    private function leadLogOf(array $logs): ?object
    {
        if (!$logs) return null;
        try {
            $typeIds = array_values(array_unique(array_filter(array_map(fn ($l) => (int) $l->maintenance_type_id, $logs))));
            $resets  = $typeIds
                ? DB::table('t_fleet_maintenance_types')->whereIn('id', $typeIds)->pluck('resets_service_clock', 'id')->all()
                : [];
            foreach ($logs as $l) {
                if (!empty($resets[(int) $l->maintenance_type_id])) return $l;
            }
        } catch (\Throwable $e) {
            // fall through to the first recorded
        }
        return reset($logs) ?: null;
    }

    /**
     * 🧾 THE SERVICES A BILL CAN BE ATTACHED TO — this rider's own readings that no live bill
     *    speaks for yet, newest first. One row per JOB (the shape every installed APK reads),
     *    each carrying its `visit_key` so a newer screen can offer the whole visit at once.
     *
     * ⚠ Scoped to ONE rider because a claim belongs to a requester: attaching a bill to
     *   another man's service would move his countdown and his money together.
     * ⚠ `$vehicleId` narrows it on the vehicle page, where the machine is already the subject.
     *
     * @return array<int, array<string, mixed>>
     */
    public function unbilledServicesFor(int $riderId, ?int $vehicleId = null, int $days = 60): array
    {
        try {
            if (!Schema::hasTable('t_fleet_service_log')) return [];
            // ⚠⚠ Keyed by JOB, not by bill — see liveBilledLogIds() for the double-money reason.
            $live = self::liveBilledLogIds();

            $rows = DB::table('t_fleet_service_log as l')
                ->leftJoin('t_fleet_maintenance_types as t', 't.id', '=', 'l.maintenance_type_id')
                ->where('l.user_id', $riderId)
                ->whereNotNull('l.meter')
                ->whereDate('l.service_date', '>=', \Carbon\Carbon::today()->subDays($days)->format('Y-m-d'))
                ->orderByDesc('l.service_date')->orderByDesc('l.meter')->orderByDesc('t.resets_service_clock')->orderBy('l.id')
                ->limit(40)
                ->get(array_merge(['l.id', 'l.user_id', 'l.meter', 'l.service_date',
                                   'l.maintenance_type_id', 't.type_name', 't.bucket'],
                                  self::logVehicleCols('l.')));

            $resolver = new VehicleResolver();
            $out = [];
            foreach ($rows as $r) {
                if (isset($live[(int) $r->id])) continue;   // a live bill already speaks for it
                // ⚠ The machine is resolved the SAME way the countdowns resolve it — the
                //   stamp first, the registry for a row filed before the stamp existed — so
                //   the vehicle page never offers a service that belongs to a different bike.
                $vid = self::logVehicleOf($r, $resolver);
                if ($vehicleId && (int) $vid !== (int) $vehicleId) continue;

                $out[] = [
                    'log_id'              => (int) $r->id,
                    'meter'               => (int) $r->meter,
                    'date'                => substr((string) $r->service_date, 0, 10),
                    'maintenance_type_id' => $r->maintenance_type_id ? (int) $r->maintenance_type_id : null,
                    'type_name'           => $r->type_name ?: 'Service',
                    'bucket'              => $r->bucket,
                    'vehicle_id'          => $vid ? (int) $vid : null,
                    'visit_key'           => self::visitKeyOf($r, $resolver),
                    // What the picker shows: "30 Aug · Oil + Tuning · 27,906 km"
                    'label'               => \Carbon\Carbon::parse($r->service_date)->format('j M')
                                             . ' · ' . ($r->type_name ?: 'Service')
                                             . ' · ' . number_format((int) $r->meter) . ' km',
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('unbilledServicesFor failed', ['rider' => $riderId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * 🧾⭐⭐ THE VISITS A BILL CAN BE ATTACHED TO (29-Sep-2026) — the same un-billed jobs as
     *    `unbilledServicesFor()`, grouped the ONE way a visit is defined. One receipt usually
     *    covers everything done on that trip, so a picker offers the visit with all its jobs
     *    ticked and lets the person untick any that were billed separately.
     *
     * @return array<int, array{visit_key:string, date:string, meter:int, vehicle_id:?int,
     *                          log_ids:int[], jobs:array, label:string}>
     */
    public function unbilledVisitsFor(int $riderId, ?int $vehicleId = null, int $days = 60): array
    {
        $visits = [];
        foreach ($this->unbilledServicesFor($riderId, $vehicleId, $days) as $s) {
            $k = $s['visit_key'];
            if (!isset($visits[$k])) {
                $visits[$k] = [
                    'visit_key'  => $k,
                    'date'       => $s['date'],
                    'meter'      => $s['meter'],
                    'vehicle_id' => $s['vehicle_id'],
                    'log_ids'    => [],
                    'jobs'       => [],
                ];
            }
            $visits[$k]['log_ids'][] = $s['log_id'];
            $visits[$k]['jobs'][]    = ['log_id' => $s['log_id'], 'maintenance_type_id' => $s['maintenance_type_id'],
                                        'type_name' => $s['type_name']];
        }
        foreach ($visits as &$v) {
            $v['label'] = \Carbon\Carbon::parse($v['date'])->format('j M')
                . ' · ' . ($this->jobNamesOfLogs($v['log_ids']) ?: 'Service')
                . ' · ' . number_format((int) $v['meter']) . ' km';
        }
        unset($v);
        return array_values($visits);
    }

    /**
     * ⭐⭐ MAY THIS BILL BE ATTACHED TO THESE JOBS? — the one gate every bill door calls.
     *
     * Accepts ONE log id (every installed APK and the old forms) or a LIST of them (29-Sep-2026:
     * one receipt for a visit's several jobs). Returns the reading to INHERIT so the filer never
     * retypes a meter he has already entered: the claim takes the visit's odometer and date,
     * and the LEAD job's type (the first oil service, otherwise the first job).
     *
     * ⚠⚠ THE DOUBLE-MONEY GUARD LIVES HERE. If ANY chosen job already has a live bill, this
     *    refuses — naming the job, the bill, its amount and who filed it. That is the case where
     *    a manager records the service with the receipt and the rider then files the same
     *    receipt from his phone: without this, the money goes out twice.
     * ⚠ Several jobs must be ONE visit (same machine, day and odometer). A receipt from one trip
     *   cannot pay for work done on another, and the claim can only carry one reading.
     *
     * @param int|int[]|string|null $logIds
     * @return array{ok:bool, message:string, log_ids?:int[],
     *               inherit?:array{meter:int,maintenance_type_id:?int,date:string}}
     */
    public function validateBillTarget($logIds, int $requesterId): array
    {
        $ids = self::normaliseIds($logIds);
        if (!$ids) return ['ok' => true, 'message' => ''];
        try {
            if (!Schema::hasTable('t_fleet_service_log')) {
                return ['ok' => false, 'message' => 'Service records are not set up yet.'];
            }
            $found = DB::table('t_fleet_service_log')->whereIn('id', $ids)->get()->keyBy('id');
            $logs  = [];
            foreach ($ids as $id) {
                if (!isset($found[$id])) {
                    return ['ok' => false, 'message' => 'That service record no longer exists. Refresh and choose again.'];
                }
                $logs[] = $found[$id];
            }
            $names = count($logs) > 1
                ? DB::table('t_fleet_maintenance_types')->pluck('type_name', 'id')->all() : [];
            $jobOf = fn ($l) => count($logs) > 1 ? (($names[(int) $l->maintenance_type_id] ?? 'That job') . ': ') : '';

            foreach ($logs as $log) {
                // ⚠ A claim belongs to its requester — attaching it to someone else's service
                //   would move another man's countdown and his money in one step.
                if ((int) $log->user_id !== $requesterId) {
                    return ['ok' => false, 'message' => $jobOf($log) . 'That service was recorded for a different rider.'];
                }
                if ($log->meter === null) {
                    return ['ok' => false, 'message' => $jobOf($log) . 'That service record has no odometer reading to bill against.'];
                }
            }
            if (count($logs) > 1) {
                $resolver = new VehicleResolver();
                $keys = array_unique(array_map(fn ($l) => self::visitKeyOf($l, $resolver), $logs));
                if (count($keys) > 1) {
                    return ['ok' => false, 'message' =>
                        'One bill can only cover jobs from the SAME visit — the same machine, day and '
                        . 'odometer. File a separate bill for the other visit.'];
                }
            }

            if (Schema::hasColumn('t_fleet_service_log', 'request_id')) {
                foreach ($logs as $log) {
                    if (empty($log->request_id)) continue;
                    $live = DB::table('t_req_master')->where('id', $log->request_id)
                        ->whereIn('status', self::LIVE_BILL_STATUSES)->first(['id', 'amount', 'created_by', 'status']);
                    if (!$live) continue;   // a dead link (rejected / cancelled) is simply overwritten
                    /**
                     * ⚠⚠ A REFUSAL MUST NAME THE WAY OUT, and it must name the RIGHT one.
                     *    Several jobs can share one bill, and separate jobs can carry separate
                     *    bills — but ONE job can never be paid for twice. The way out is to
                     *    reverse the wrong bill, or to leave this job out of the new one.
                     */
                    return ['ok' => false, 'message' => $jobOf($log)
                        . 'That service already has a bill — Rs ' . number_format((float) $live->amount)
                        . ' filed by ' . $this->nameOf($live->created_by ? (int) $live->created_by : null)
                        . ($live->status === 'pending' ? ' (waiting for approval)' : '')
                        . '. If that bill is wrong, reverse it first. If this bill is for a DIFFERENT '
                        . 'job done in the same visit, record that job as its own service (same '
                        . 'odometer) and attach the bill to it.'];
                }
            }

            $lead = $this->leadLogOf($logs) ?: $logs[0];
            /**
             * ⭐ …and the MACHINE (29-Sep-2026, found on the device). The bill used to inherit
             *   the reading, job and date but not the bike, so the claim was stamped from "what
             *   was he on that day" — the spare, in exactly the workshop case the service record
             *   is stamped to avoid. The money now lands on the machine the work was done on.
             */
            $vid = self::logVehicleOf($lead);
            return ['ok' => true, 'message' => '', 'log_ids' => $ids, 'inherit' => [
                'meter'               => (int) $lead->meter,
                'maintenance_type_id' => $lead->maintenance_type_id ? (int) $lead->maintenance_type_id : null,
                'date'                => substr((string) $lead->service_date, 0, 10),
                'vehicle_id'          => $vid ? (int) $vid : null,
            ]];
        } catch (\Throwable $e) {
            Log::error('validateBillTarget failed', ['log' => $ids, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not check that service record.'];
        }
    }

    /**
     * Tie a freshly created bill to the job record(s) it was filed for — one id, or every job
     * of a visit that one receipt paid for. Each record carries the SAME `request_id`: that is
     * the whole of "one bill, several jobs", and it needs no new table.
     *
     * @param int|int[] $logIds
     */
    public function attachBillToService($logIds, int $requestId): void
    {
        $ids = self::normaliseIds($logIds);
        if (!$ids) return;
        try {
            if (!Schema::hasColumn('t_fleet_service_log', 'request_id')) return;
            $cols = ['id', 'user_id', 'note', 'service_date', 'meter'];
            if (self::logKeepsPhoto()) $cols[] = 'photo_path';
            $cols = array_merge($cols, self::logVehicleCols());
            $rows = DB::table('t_fleet_service_log')->whereIn('id', $ids)->orderBy('id')->get($cols);
            if ($rows->isEmpty()) return;

            $photo = null;
            foreach ($rows as $row) {
                DB::table('t_fleet_service_log')->where('id', $row->id)->update([
                    'request_id' => $requestId,
                    'note'       => mb_substr(trim(($row->note ? $row->note . ' · ' : '') . 'bill attached'), 0, 250),
                ]);
                if (!$photo && !empty($row->photo_path ?? null)) $photo = $row->photo_path;
            }

            /**
             * 📷⭐⭐ THE BILL INHERITS THE RIDER'S PHOTO (owner ruling, 11-Sep-2026).
             *
             * ⭐ This is the point of storing the picture on the WORK. The rider photographs
             *   the receipt at the workshop and files nothing; days later a manager enters the
             *   amount from the vehicle page — and the claim he creates now carries the same
             *   photo, so whoever approves it can see what is being paid for.
             *
             * ⚠ NEVER overwrites. A manager who attached his own picture to the claim has
             *   given the better evidence; this only fills an empty hand.
             * ⚠ Non-fatal: failing to decorate a claim must not unlink a filed bill.
             */
            if ($photo) {
                try {
                    // ⚠ `t_req_master` — the requests table (RequestModel::$table), NOT the
                    //   't_sys_*' the naming convention would suggest. `attachments` is a JSON
                    //   array of storage paths, the same shape RequestController::store writes.
                    $req = DB::table('t_req_master')->where('id', $requestId)->first(['id', 'attachments']);
                    $existing = $req && !empty($req->attachments) ? json_decode($req->attachments, true) : null;
                    if ($req && empty($existing)) {
                        DB::table('t_req_master')->where('id', $requestId)
                            ->update(['attachments' => json_encode([$photo])]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('bill did not inherit the service photo',
                        ['log' => $ids, 'request' => $requestId, 'error' => $e->getMessage()]);
                }
            }

            $first = $rows->first();
            $this->bustCaches((int) $first->user_id, self::logVehicleOf($first));
        } catch (\Throwable $e) {
            Log::error('attachBillToService failed', ['log' => $ids, 'request' => $requestId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * 💰⭐⭐ FILE THE BILL FOR A VISIT — the ONE bill door for "record a service and pay for
     *    it" (29-Sep-2026). The Bikes screen and the workshop close each had their own copy;
     *    they differed in the claim's title (the workshop named the job it was BOOKED for, not
     *    the one done) and in who counted as "on behalf of". One copy now.
     *
     * ⭐⭐ THIS DELIBERATELY DOES NOT INSERT A CLAIM. Filing a maintenance expense means a
     *    request number, the L1/L2 auto-approval rule, the ledger posting, the BikeServiceClock
     *    hook and the vehicle stamping. All of that already lives in RequestController::store,
     *    so the money goes through the real door and inherits every rule, including later ones.
     * ⚠ The reading, job and date are INHERITED from the records (`service_log_ids`), never
     *   resent — resending made the bill door re-judge a reading the service door had just
     *   accepted (review, 3-Sep).
     * ⚠ Failure is NOT fatal to the service. The caller keeps the records and reports that the
     *   bill did not file; it can be added later from the vehicle page.
     *
     * @param array $o {log_ids:int[], rider_id:int, amount:float, payment_source_account_id?,
     *                  description?, note_after?}
     * @return array{ok:bool, message:string, request_id?:int, auto_approved?:bool}
     */
    public function fileBill(\Illuminate\Http\Request $request, array $o): array
    {
        $ids = self::normaliseIds($o['log_ids'] ?? []);
        try {
            if (!$ids) return ['ok' => false, 'message' => 'there was no service record to bill against.'];
            $amount = (float) ($o['amount'] ?? 0);
            if ($amount <= 0) return ['ok' => false, 'message' => 'no amount was given.'];

            $category = \App\Models\Request\RequestCategoryModel::where('category_code', 'expense')
                ->where('is_active', 1)->first();
            if (!$category) return ['ok' => false, 'message' => 'the expense category is not set up.'];

            /**
             * 🧾 THE BILL PHOTO rides through under the field RequestController::store reads
             *    (`attachment_image`), as the SAME UploadedFile instance — a copy would land as an
             *    ordinary file and fail the `image` rule.
             */
            $files = [];
            if ($request->hasFile('bill_image')) $files['attachment_image'] = $request->file('bill_image');

            /**
             * ⚠⚠ A RIDER FILING HIS OWN BILL MUST NOT LOOK LIKE FILING FOR SOMEONE ELSE.
             *    store() treats `requester_user_id` as "on behalf of", which needs a right no
             *    rider holds. Omitted when the filer IS the rider; sent when a manager files for
             *    him. The filer is read the way store() reads it (auth()->user()), so the two
             *    halves cannot disagree about who is filing.
             */
            $riderId  = (int) ($o['rider_id'] ?? 0);
            $actorId  = (int) (auth()->id() ?: (($request->user())->id ?? 0));
            $onBehalf = $riderId && $riderId !== $actorId;

            $sub = \Illuminate\Http\Request::create('/api/requests/store', 'POST', array_filter([
                'category_id'        => $category->id,
                'requester_user_id'  => $onBehalf ? $riderId : null,
                // ⭐ Every job it pays for, in the one order every claim list prints.
                'title'              => mb_substr($this->jobNamesOfLogs($ids) ?: 'Maintenance', 0, 190),
                'description'        => $o['description'] ?? 'Filed with the service record.',
                'amount'             => $amount,
                'expense_category'   => 'Maintenance',
                'service_log_ids'    => $ids,
                'payment_source_account_id' => $o['payment_source_account_id'] ?? null,
                // ⚠ Bikes is Nizami Farms operations — ALWAYS business unit 1, never Khaas, so a
                //   Khaas-mode manager like Qasim can never file a bike bill into the other books.
                'business_unit_id'   => 1,
            ], fn ($v) => $v !== null), [], $files);
            // The sub-request acts as the SAME signed-in user — the approval decision hangs on it.
            $sub->setUserResolver($request->getUserResolver());

            $res  = app(\App\Http\Controllers\Request\RequestController::class)->store($sub);
            $body = json_decode($res->getContent(), true);
            if ($res->getStatusCode() < 200 || $res->getStatusCode() >= 300 || empty($body['success'])) {
                return ['ok' => false, 'message' => $body['message'] ?? 'the request was refused.'];
            }
            $reqId = (int) ($body['request_id'] ?? 0);

            if ($reqId && !empty($o['note_after']) && Schema::hasColumn('t_fleet_service_log', 'request_id')) {
                DB::table('t_fleet_service_log')->whereIn('id', $ids)->where('request_id', $reqId)
                    ->update(['note' => mb_substr((string) $o['note_after'], 0, 250)]);
            }

            // ⭐ `auto_approved` is the server's own answer to "did this land in the ledger
            //   already?" — echoed, never re-derived, so the message cannot contradict it.
            $approved = !empty($body['auto_approved']);
            return ['ok' => true, 'request_id' => $reqId, 'auto_approved' => $approved, 'message' =>
                'Rs ' . number_format($amount) . ' bill '
                . ($approved ? 'added and approved' : 'sent for approval') . ' as an expense.'
                . (count($ids) > 1 ? ' One bill for all ' . count($ids) . ' jobs.' : '')
                . (!empty($files) ? ' Bill attached.' : ' No bill photo attached.')];
        } catch (\Throwable $e) {
            Log::error('ServiceRecordService::fileBill failed', ['log' => $ids, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'it could not be filed.'];
        }
    }

    /**
     * 💰⭐⭐ THE BILL FOR A SAVE THAT HAS JUST RECORDED A VISIT — the ONE decision both "record a
     *    service" doors take, the Bikes screen and the workshop close (29-Sep-2026, pre-deploy A1).
     *
     * ⚠⚠ WHY THIS EXISTS. Those doors used to leave out the jobs that already carried a live bill
     *    and file a NEW bill, for the FULL amount typed, on the rest. Re-save a visit to add Chain
     *    Set with the same Rs 5,200 still in the box and the one receipt was paid twice. So: if ANY
     *    job of this save (the duplicates `recordVisit` handed back included) is already under a
     *    live bill, NO bill is filed. The jobs stay recorded; the reply names the bill that is
     *    already there and says what to do if this really is a second receipt.
     * ⭐ When no job is billed, every job of the save is billed — including an all-duplicate save
     *   whose first bill never filed (or which was first recorded with no money). The double-money
     *   guard in `validateBillTarget` still runs inside `fileBill`.
     *
     * @param array $recorded the result of recordVisit()
     * @param array $o        fileBill()'s options, without `log_ids`
     * @return array{ok:bool, filed:bool, skipped_existing:bool, existing_bill_ids:int[], message:string,
     *               request_id?:int, auto_approved?:bool}
     */
    public function billRecordedVisit(\Illuminate\Http\Request $request, array $recorded, array $o): array
    {
        $ids     = self::normaliseIds($recorded['service_log_ids'] ?? []);
        $paid    = $ids ? self::liveBilledLogIds() : [];
        $covered = array_values(array_filter($ids, fn ($id) => isset($paid[$id])));
        if ($covered) {
            $billIds = array_values(array_unique(array_map(fn ($id) => (int) $paid[$id], $covered)));
            return ['ok' => true, 'filed' => false, 'skipped_existing' => true, 'existing_bill_ids' => $billIds,
                    'message' => $this->alreadyBilledNote($ids, $covered, $billIds)];
        }
        /**
         * ⚠⚠ …AND THE REST OF THE VISIT (29-Sep-2026 verification). Ticking ONLY the new job
         *    (Chain Set) beside an already-billed one (Oil + Tuning) and typing the receipt's
         *    amount again got past the check above — none of the TICKED jobs was billed — and
         *    filed the same receipt twice. The visit's live bill now counts too — found by the
         *    SAME rule the "visit bill #N" line on Past services uses (`visit_bill_id`).
         * ⚠ Only when the amount typed MATCHES that bill (same whole rupees): the receipt typed again.
         *   A DIFFERENT amount is a separate receipt at the same visit — a real pattern (Waseem
         *   30-Aug: Rs 3,500 + Rs 650, two bills, one visit) — and still files, exactly as before.
         *   Way out either way: "Add the bill" on the visit.
         */
        if ($ids && ($visitBill = $this->liveBillOfVisit($recorded, $ids, (float) ($o['amount'] ?? 0)))) {
            return ['ok' => true, 'filed' => false, 'skipped_existing' => true, 'existing_bill_ids' => [$visitBill],
                    'message' => $this->alreadyBilledNote($ids, [], [$visitBill])];
        }
        $bill = $this->fileBill($request, array_merge($o, ['log_ids' => $ids]));
        return $bill + ['filed' => (bool) $bill['ok'], 'skipped_existing' => false, 'existing_bill_ids' => []];
    }

    /**
     * The live bill (a linked job's bill, or a claim) already standing on the visit these jobs
     * were just recorded into, for the SAME amount — read from Past services itself, so it is
     * the bill the screen names as "visit bill #N". Null when there is none.
     * ⚠ FAILS OPEN (null) on an error: the save then bills as before, and approval is the net.
     */
    private function liveBillOfVisit(array $recorded, array $ids, float $amount): ?int
    {
        try {
            $vid   = (int) ($recorded['vehicle_id'] ?? 0);
            $first = DB::table('t_fleet_service_log')->where('id', (int) $ids[0])->first(['meter', 'service_date']);
            if (!$vid || !$first || $first->meter === null) return null;
            $key = 'v' . $vid . '|' . substr((string) $first->service_date, 0, 10) . '|' . (int) $first->meter;
            foreach ((new VehicleService())->serviceHistoryFor($vid, 200) as $r) {
                if (($r['visit_key'] ?? null) !== $key || empty($r['bill_live'])) continue;
                if (!empty($r['log_id']) && in_array((int) $r['log_id'], $ids, true)) continue;
                $bid = !empty($r['manual']) ? ($r['bill_id'] ?? null) : ($r['req_id'] ?? null);
                if (!$bid) continue;
                // the bill's OWN amount — a shared bill's later jobs carry 0 on the row
                $billAmt = (float) DB::table('t_req_master')->where('id', (int) $bid)->value('amount');
                if ($amount > 0 && (int) round($billAmt) === (int) round($amount)) return (int) $bid;   // same rupees
            }
        } catch (\Throwable $e) {
            Log::warning('liveBillOfVisit failed (billing as before)', ['ids' => $ids, 'error' => $e->getMessage()]);
        }
        return null;
    }

    /**
     * "No second bill was filed — this visit already has bill #123 (Rs 5,200) for Oil + Tuning +
     * Brake Shoe. If Chain Set is on that same receipt, it is already paid; …" — the way out is
     * always named: the visit's "Add the bill" still bills whatever is left un-billed.
     */
    private function alreadyBilledNote(array $ids, array $covered, array $billIds): string
    {
        $parts = [];
        try {
            $bills = DB::table('t_req_master')->whereIn('id', $billIds)->get(['id', 'amount', 'status'])->keyBy('id');
            foreach ($billIds as $rid) {
                $b = $bills[$rid] ?? null;
                $jobs = $this->jobNamesOfLogs(DB::table('t_fleet_service_log')->where('request_id', $rid)
                                                ->pluck('id')->all());
                $parts[] = 'bill #' . $rid
                    . ($b ? ' (Rs ' . number_format((float) $b->amount) . ($b->status === 'pending' ? ', waiting for approval' : '') . ')' : '')
                    . ($jobs !== '' ? ' for ' . $jobs : '');
            }
        } catch (\Throwable $e) {
            foreach ($billIds as $rid) $parts[] = 'bill #' . $rid;
        }
        $s = 'No second bill was filed — this visit already has ' . implode('; ', $parts) . '.';
        $rest = array_values(array_diff($ids, $covered));
        if ($rest) {
            $n = $this->jobNamesOfLogs($rest) ?: 'the new job';
            $s .= ' If ' . $n . ' is on that same receipt, it is already paid; if it is a separate '
                . 'receipt, use "Add the bill" on the visit.';
        }
        return $s;
    }

    /**
     * 🔗⭐ A SERVICE LINK BELONGS ON A MAINTENANCE BILL ONLY (29-Sep-2026, pre-deploy C6). Every
     *    bill door that takes `service_log_id(s)` asks this, so a petrol or other expense can
     *    never be tied to a service record — which would reset nothing, yet hide the job from
     *    "Add the bill" as if it were paid. Null = fine (no link, or a Maintenance bill).
     */
    public static function linkRefusalForCategory(?string $expenseCategory, array $linkIds): ?string
    {
        if (!$linkIds || $expenseCategory === 'Maintenance') return null;
        return 'Only a maintenance bill can be linked to a service. Choose Maintenance as the '
            . 'category, or file this expense without choosing a service.';
    }

    /** @internal save locks this process already holds — a nested bill door must not wait on its own caller. */
    private static array $heldSaveLocks = [];

    public const SAVE_BUSY_MESSAGE = 'Another save for this bike is in progress — try again in a moment.';

    /**
     * 🔒 ONE SAVE AT A TIME PER RIDER (29-Sep-2026, pre-deploy C3). Web and phone pressing Save on
     *    the same service at the same moment both passed the double-tap and double-money checks
     *    before either had written — two rows, or two bills. Record-and-bill now holds a short
     *    cache lock (database store; `cache_locks`) keyed by the rider the work belongs to, which
     *    covers every machine he holds.
     *
     * ⚠ FAILS OPEN. If the lock itself cannot be taken (store down, table missing) the save goes
     *   ahead exactly as before and a warning is logged — a lock must never be why a real service
     *   cannot be recorded. Only a lock another save genuinely HOLDS refuses, with a way out.
     * ⚠ Re-entrant within one process: the Bikes screen files its bill through
     *   RequestController::store, which asks for the same lock.
     *
     * @return array{ok:bool, message:string, release:callable}
     */
    public static function acquireSaveLock(int $riderId): array
    {
        $noop = function () {};
        $key  = 'svc_save_rider_' . $riderId;
        if ($riderId <= 0 || isset(self::$heldSaveLocks[$key])) {
            return ['ok' => true, 'message' => '', 'release' => $noop];
        }
        $lock = null;
        try {
            $lock = Cache::lock($key, 30);
            if (!$lock->get()) {
                return ['ok' => false, 'message' => self::SAVE_BUSY_MESSAGE, 'release' => $noop];
            }
        } catch (\Throwable $e) {
            Log::warning('Service save lock unavailable — saving without it', ['rider' => $riderId, 'error' => $e->getMessage()]);
            $lock = null;
        }
        self::$heldSaveLocks[$key] = true;
        return ['ok' => true, 'message' => '', 'release' => function () use ($key, $lock) {
            unset(self::$heldSaveLocks[$key]);
            if ($lock) {
                try { $lock->release(); } catch (\Throwable $e) { /* it expires on its own */ }
            }
        }];
    }

    /**
     * ⭐⭐ THE SAME RULE FOR A MAINTENANCE **CLAIM** (owner, 3-Sep: "same engine rule").
     *
     * ⚠⚠ WHAT WAS HAPPENING. A manager recording a service is REFUSED an untyped odometer
     *    (resolveType above — owner ruling, no guessing). But a rider's own request, and a
     *    manager's claim for him, went through `MaintenanceTypeService::resolve()`, which
     *    turned a legacy "Regular service" into `[oil_change, null]` and FILED IT. The
     *    evidence engine skips every claim with no `maintenance_type_id`, so those claims
     *    never reset a per-type countdown — 116 of 140 maintenance claims are untyped. Two
     *    rules for one fact, and the one riders hit was the silent one.
     *
     * ⚠ Narrower than resolveType on purpose. resolveType also refuses "as conditions" work
     *   (General Repair, Chain Set) because there is no countdown to RECORD against — but a
     *   General Repair BILL is a perfectly good claim. So here: a Maintenance claim that
     *   carries an ODOMETER must name a type when the list exists; any active type will do.
     *   No odometer → nothing can feed a countdown → untyped is harmless and still accepted,
     *   which is what keeps an older APK able to file a repair bill.
     *
     * @return array{ok: bool, message: string}
     */
    public function requireTypeForClaim($typeId, bool $hasMeter): array
    {
        if (!$hasMeter || !empty($typeId)) return ['ok' => true, 'message' => ''];
        try {
            $svc = app(MaintenanceTypeService::class);
            if (!$svc->available() || empty($svc->options())) {
                // No list to choose from (pre-batch-12): behave as before types existed.
                return ['ok' => true, 'message' => ''];
            }
        } catch (\Throwable $e) {
            return ['ok' => true, 'message' => ''];   // fail OPEN — a lookup error must never block a bill
        }
        return ['ok' => false, 'message' =>
            'Choose which service was done — the odometer alone does not say which countdown '
            . 'to reset. If the app only offers "Regular service / Repair", pull down to refresh '
            . 'the form or update the app.'];
    }

    /**
     * Turn a submitted type id into the row to record against, applying the two rules
     * that decide whether it may be recorded at all.
     *
     * @return array{ok: bool, type: ?object, message: string}
     */
    public function resolveType($typeId, ?string $class = null): array
    {
        /**
         * ⚠⚠ ASKED OF EVERY SELECTABLE JOB, NOT ONLY THE SCHEDULED ONES (11-Sep-2026). This
         *    used to read `scheduledTypes()`, which on a VAN is empty — so a blank pick was
         *    waved through as "no types exist here", and `record()` below then treated a null
         *    type as "move the overall clock". A van service with no job named would have
         *    reset the bike-style countdown for nothing. If the picker can offer anything at
         *    all, a choice is required.
         */
        $selectable = $this->typesForClose($class);

        if (empty($typeId)) {
            if ($selectable) {
                // ⭐ REFUSED, never guessed (owner ruling 2-Sep).
                return ['ok' => false, 'type' => null, 'message' =>
                    'Choose which service was done — the odometer alone does not say which '
                    . 'countdown to reset. Please update the app if it does not ask you.'];
            }
            // No type list at all (pre-batch-12): nothing to choose, behave as before
            // types existed rather than blocking the action outright.
            return ['ok' => true, 'type' => null, 'counts_down' => true, 'message' => ''];
        }

        $type = app(MaintenanceTypeService::class)->find($typeId);
        if (!$type) {
            return ['ok' => false, 'type' => null, 'message' => 'That maintenance type no longer exists.'];
        }
        /**
         * ⭐⭐ WORK THAT HAPPENED IS ALWAYS RECORDABLE (owner ruling, 11-Sep-2026).
         *
         * ⚠⚠ THIS USED TO REFUSE TWICE, AND BOTH REFUSALS WERE WRONG IN THE SAME WAY. A job with
         *    no figure for this machine ("has no schedule for vans yet") and an as-conditions job
         *    ("done as conditions require") were both turned away with "file it as a maintenance
         *    request instead". But the bike HAD been to the workshop, the brake shoes HAD been
         *    changed, and the man standing there with a receipt had nowhere to put it. Worse, the
         *    two refusals were reachable only on prod, where just two of four types carry a
         *    kilometre figure — which is why a manager saw a two-item list and assumed the app
         *    was broken.
         *
         * ⭐ The question "may this be recorded?" is now always YES for an active type. The
         *   separate question "does a countdown move?" is answered by `counts_down`, which
         *   `record()` uses and the receipt states out loud. Nothing silently resets.
         * ⚠ An unreadable or inactive type is still refused — that is a mis-selection, not work.
         */
        $countsDown = $class !== null
            ? (bool) ($type->scheduleForClass($class)['has'] ?? false)
            : ((int) $type->interval_km > 0);

        return ['ok' => true, 'type' => $type, 'counts_down' => $countsDown, 'message' => ''];
    }

    /**
     * ⭐⭐ THE JOB IDS A FORM SENT, AS ONE CLEAN LIST (29-Sep-2026).
     *
     * Every door accepts BOTH shapes — the new `maintenance_type_ids[]` and the old scalar
     * `maintenance_type_id` an installed APK still posts — and they must mean the same thing
     * everywhere, so the merging lives here once. Order is kept (the first ticked job leads),
     * repeats and non-positive values are dropped. A comma string ("1,2") is accepted too:
     * that is what a FormData `String(array)` produces, and refusing it would only punish an
     * old client for a shape it could not help.
     *
     * @return int[]
     */
    public static function normaliseIds(...$sources): array
    {
        $out = [];
        foreach ($sources as $s) {
            if ($s === null || $s === '' || $s === false) continue;
            if (is_string($s) && str_contains($s, ',')) $s = explode(',', $s);
            foreach ((array) $s as $v) {
                $n = (int) $v;
                if ($n > 0 && !in_array($n, $out, true)) $out[] = $n;
            }
        }
        return $out;
    }

    /**
     * ⭐⭐ SEVERAL JOBS IN ONE VISIT (owner ask, 29-Sep-2026 — Qasim ticks Oil + Tuning, Brake
     *    Shoe and Chain Set for one trip to the workshop, with one receipt).
     *
     * Every id is judged by `resolveType()` — the ONE rule — and a single bad id refuses the
     * whole visit, naming the job. A visit is never half-written: the manager would otherwise
     * see two of his three jobs reset and have no idea the third was dropped.
     *
     * ⚠ No ids at all goes through `resolveType(null)` so the "Choose which service was done"
     *   refusal (and the pre-batch-12 no-type-list behaviour) stays in exactly one place.
     *
     * @return array{ok:bool, jobs:array<int,array{type:?object,counts_down:bool}>, message:string}
     */
    public function resolveJobs($typeIds, ?string $class = null): array
    {
        $ids = self::normaliseIds($typeIds);
        if (!$ids) {
            $r = $this->resolveType(null, $class);
            return $r['ok']
                ? ['ok' => true, 'jobs' => [['type' => null, 'counts_down' => true]], 'message' => '']
                : ['ok' => false, 'jobs' => [], 'message' => $r['message']];
        }
        $jobs = [];
        foreach ($ids as $id) {
            $r = $this->resolveType($id, $class);
            if (!$r['ok']) {
                return ['ok' => false, 'jobs' => [], 'message' =>
                    (count($ids) > 1 ? 'Nothing was saved. ' : '') . $r['message']];
            }
            $jobs[] = ['type' => $r['type'], 'counts_down' => (bool) ($r['counts_down'] ?? true)];
        }
        return ['ok' => true, 'jobs' => $jobs, 'message' => ''];
    }

    /**
     * Write ONE job's service record — the pre-29-Sep shape, kept for every caller that
     * records a single job. It is `recordVisit()` with a list of one, so there is still ONE
     * writer and one set of guards.
     *
     * @param array $in {rider_id, meter, date, type (?object from resolveType), actor_id, note}
     * @return array{ok: bool, service_log_id: ?int, moved_clock: bool, message: string}
     */
    public function record(array $in): array
    {
        $in['jobs'] = [[
            'type'        => $in['type'] ?? null,
            'counts_down' => array_key_exists('counts_down', $in) ? (bool) $in['counts_down'] : true,
        ]];
        $r = $this->recordVisit($in);
        $first = $r['jobs'][0] ?? null;
        return [
            'ok'             => $r['ok'],
            'service_log_id' => $first['log_id'] ?? null,
            'moved_clock'    => $r['moved_clock'],
            'vehicle_id'     => $r['vehicle_id'] ?? null,
            'duplicate'      => !empty($first['duplicate']),
            'message'        => $r['message'],
        ];
    }

    /**
     * ⭐⭐ WRITE A VISIT — THE ONE WRITER OF `t_fleet_service_log` (29-Sep-2026).
     *
     * One row per job, all sharing the machine, the odometer, the date, the note, the person
     * and the proof photo. That is not a new model: every countdown reader already keeps the
     * furthest reading PER JOB, and two jobs at one odometer on one day never bound each other
     * in the meter window (strict before/after), so N rows reset N countdowns on every screen —
     * the Bikes chip, the vehicle page, the rider's phone and the alerts — with nothing else
     * changing. Staff were already doing exactly this by hand, one job at a time.
     *
     * ⚠⚠ ALL OR NOTHING. The odometer is judged ONCE (it is the same number for every job),
     *    and the rows are written in one transaction, so a failure can never leave two of three
     *    jobs recorded and a manager believing all three were.
     *
     * ⚠⚠ A DOUBLE TAP IS NOT A SECOND SERVICE. Rider 77 carries four identical Oil + Tuning
     *    rows from 2-Sep — one tap, three retries. The same job on the same machine at the same
     *    odometer on the same day cannot have been done twice, so it is not inserted again and
     *    the receipt says so. When EVERY job is such a repeat the answer is still `ok`: the
     *    work is on file, which is what the person pressing Save wanted to know.
     *
     * @param array $in {rider_id, vehicle_id?, meter, date?, jobs: [{type, counts_down}], actor_id,
     *                   note?, photo_path?}
     * @return array{ok:bool, service_log_ids:int[], jobs:array, moved_clock:bool, vehicle_id:?int,
     *               all_duplicate:bool, message:string}
     */
    public function recordVisit(array $in): array
    {
        $riderId = (int) ($in['rider_id'] ?? 0);
        $meter   = (int) ($in['meter'] ?? 0);
        $date    = !empty($in['date']) ? substr((string) $in['date'], 0, 10) : \Carbon\Carbon::today()->format('Y-m-d');
        $actorId = (int) ($in['actor_id'] ?? 0);
        $jobs    = array_values((array) ($in['jobs'] ?? []));

        $fail = fn (string $m) => ['ok' => false, 'service_log_ids' => [], 'jobs' => [], 'moved_clock' => false,
                                   'vehicle_id' => null, 'all_duplicate' => false, 'message' => $m];

        if (!$riderId || $meter <= 0) {
            return $fail('A rider and an odometer reading are both needed.');
        }
        if (!$jobs) {
            return $fail('Choose which service was done.');
        }

        /**
         * ⭐⭐ THE MACHINE, RESOLVED ONCE AND FROZEN (owner ask, 10-Sep-2026). Callers that
         *    know the bike — the workshop visit, a vehicle card — pass it; everyone else
         *    falls back to the registry exactly as before. See `vehicleForRecord()` for why
         *    an explicit id has to win.
         */
        $vehicleId = $this->vehicleForRecord($in['vehicle_id'] ?? null, $riderId, $date);

        /**
         * ⚠⚠ A DROPPED DIGIT MUST NOT PASS AS A SERVICE (10-Sep-2026). Nothing checked the
         *    odometer here, and the evidence readers silently SKIP an implausible row — so
         *    "36500" typed as "3650" gave a receipt, a log row, a visit marked done, and a
         *    countdown that never reset, with nothing anywhere saying why.
         *
         * ⭐ The SAME spine every meter reading is judged against (`readingPlausibleFor`),
         *   so what this door accepts is exactly what the countdown will later count.
         * ⚠ Fails OPEN when the machine is unknown or the check throws — a guard must never
         *   be the reason a real service cannot be recorded.
         */
        if ($vehicleId) {
            try {
                $veh = new VehicleService();
                // The service DATE rides along only so a reading off a since-replaced meter is
                // read on the right scale; the date-anchored bounds are `odometerObjection`'s
                // job just below (and its refusals name the record that set them).
                if (!$veh->readingPlausibleFor($vehicleId, $meter, $date, false)) {
                    $cur  = $veh->currentMeterFor($vehicleId);
                    $name = $veh->find($vehicleId)['name'] ?? 'that machine';
                    return $fail(number_format($meter) . ' km does not fit ' . $name . '\'s own readings'
                        . ($cur !== null ? ' (it was last seen at ' . number_format($cur) . ' km)' : '')
                        . '. Check the odometer — a missing digit here would record a service '
                        . 'that no countdown can use.');
                }
            } catch (\Throwable $e) {
                // A plausibility wobble must never lose a real recording.
            }
        }

        // ⭐⭐ THE SAME ODOMETER RULE EVERY CLAIM ANSWERS (Sep-20 2026), asked once for the
        //    whole visit — every job carries the same reading and the same date.
        if ($bad = $this->odometerObjection($riderId, $meter, $date, $vehicleId)) {
            return $fail($bad);
        }

        try {
            $canLog = Schema::hasTable('t_fleet_service_log');

            /**
             * 📷⭐⭐ THE PROOF PHOTO BELONGS TO THE WORK, NOT TO A BILL (owner ruling,
             *    11-Sep-2026). One photo, one receipt — every job of the visit points at it.
             * ⚠ Schema-guarded: the photo is simply not kept until the Sep-12 columns exist.
             */
            $photoCols = [];
            if (!empty($in['photo_path']) && self::logKeepsPhoto()) {
                $photoCols = [
                    'photo_path' => (string) $in['photo_path'],
                    'photo_by'   => $actorId ?: null,
                    'photo_at'   => now(),
                ];
            }

            $out = [];
            $movedAny = false;
            DB::transaction(function () use ($jobs, $canLog, $riderId, $vehicleId, $meter, $date, $actorId,
                                             $photoCols, $in, &$out, &$movedAny) {
                $seen = [];
                foreach ($jobs as $j) {
                    $type   = $j['type'] ?? null;
                    $counts = array_key_exists('counts_down', $j) ? (bool) $j['counts_down'] : true;
                    $tid    = $type ? (int) $type->id : null;
                    if ($tid !== null && isset($seen[$tid])) continue;
                    if ($tid !== null) $seen[$tid] = true;

                    /**
                     * ⭐ ONLY AN OIL SERVICE stamps the rider-profile fallback clock. Every job
                     *   still resets its OWN countdown — that is derived from the row itself —
                     *   but the profile stamp is the one-clock-era seed for a rider with no
                     *   registered machine, and a brake job must never make an overdue oil
                     *   change look done there. A job with no countdown on this machine moves
                     *   nothing at all (owner ruling, 11-Sep: "it won't reset any countdowns").
                     */
                    $moved = $counts && (!$type || $type->resets_service_clock);

                    $logId = null;
                    $dup   = false;
                    if ($type && $canLog) {
                        $existing = $this->existingJobRecord($riderId, $vehicleId, (int) $type->id, $date, $meter);
                        if ($existing) {
                            $logId = $existing;
                            $dup   = true;
                        } else {
                            $logId = (int) DB::table('t_fleet_service_log')->insertGetId(
                                // ⭐ The stamp, when the column exists. Schema-guarded so this
                                //   file is safe to upload before service_log_vehicle_sep2026.sql.
                                (self::logStampsVehicle() && $vehicleId ? ['vehicle_id' => $vehicleId] : [])
                                + $photoCols + [
                                'user_id'             => $riderId,
                                'maintenance_type_id' => (int) $type->id,
                                'meter'               => $meter,
                                'service_date'        => $date,
                                'note'                => mb_substr((string) ($in['note'] ?? 'Recorded on the Bikes screen (no bill filed)'), 0, 250),
                                'created_by'          => $actorId ?: null,
                                'created_at'          => now(),
                            ]);
                        }
                    }
                    if ($moved && !$dup) $movedAny = true;

                    $out[] = [
                        'type'        => $type,
                        'type_id'     => $tid,
                        'name'        => $type ? (string) $type->type_name : 'Service',
                        'log_id'      => $logId,
                        'counts_down' => $counts,
                        'moved_clock' => $moved,
                        'duplicate'   => $dup,
                    ];
                }

                if ($movedAny) {
                    DB::table('t_ops_rider_profile')->where('user_id', $riderId)->update([
                        'last_service_meter' => $meter,
                        'last_service_at'    => $date,
                        'updated_at'         => now(),
                    ]);
                }
            });

            // ⚠ The MACHINE's caches, not just the rider's — the record may be for a bike he
            //   is not on today, which is the whole reason the stamp exists.
            $this->bustCaches($riderId, $vehicleId);

            // Each job says when it is next due — the same resolver every countdown reads.
            $class = null;
            try {
                $class = $vehicleId ? (new VehicleService())->classOf($vehicleId) : null;
            } catch (\Throwable $e) {
                $class = null;
            }
            foreach ($out as &$o) {
                $o['next_due'] = $this->nextDueText($o['type'], $o['counts_down'], $vehicleId, $class,
                                                    $riderId, $meter, $date);
            }
            unset($o);

            $allDup = $out && !array_filter($out, fn ($o) => empty($o['duplicate']));
            $ids    = array_values(array_filter(array_map(fn ($o) => $o['log_id'], $out)));

            return [
                'ok'              => true,
                'service_log_ids' => $ids,
                'jobs'            => array_map(function ($o) {
                    unset($o['type']);
                    return $o;
                }, $out),
                'moved_clock'     => $movedAny,
                'vehicle_id'      => $vehicleId,
                'all_duplicate'   => (bool) $allDup,
                'message'         => $this->visitReceipt($out, $meter, $date),
            ];
        } catch (\Throwable $e) {
            Log::error('ServiceRecordService::recordVisit failed', ['rider' => $riderId, 'error' => $e->getMessage()]);
            return $fail('Could not save the service record.');
        }
    }

    /**
     * The row that already says "this job was done on this machine, this day, at this
     * reading" — the double-tap guard. Keyed on the MACHINE when the stamp exists; a row
     * filed before the stamp is matched on the rider instead, exactly as the readers do.
     */
    private function existingJobRecord(int $riderId, ?int $vehicleId, int $typeId, string $date, int $meter): ?int
    {
        try {
            $q = DB::table('t_fleet_service_log')
                ->where('maintenance_type_id', $typeId)
                ->whereDate('service_date', $date)
                ->where('meter', $meter);
            if ($vehicleId && self::logStampsVehicle()) {
                $q->where(function ($w) use ($vehicleId, $riderId) {
                    $w->where('vehicle_id', $vehicleId)
                      ->orWhere(fn ($x) => $x->whereNull('vehicle_id')->where('user_id', $riderId));
                });
            } else {
                $q->where('user_id', $riderId);
            }
            $id = $q->orderBy('id')->value('id');
            return $id ? (int) $id : null;
        } catch (\Throwable $e) {
            return null;   // a guard that cannot answer must not block the recording
        }
    }

    /**
     * "next due at 56,125 km" / "next due on 27 Mar 2027" / "logged as work done, no
     * countdown" — ONE job's effect, from the same resolver every countdown reads, so the
     * receipt can never quote a figure the schedule panel then contradicts (the old receipt
     * used the raw bike figure, which on the van was simply wrong).
     */
    private function nextDueText($type, bool $countsDown, ?int $vehicleId, ?string $class, ?int $riderId,
                                 int $meter, string $date): string
    {
        if (!$type) return '';
        if (!$countsDown) return 'logged as work done — no countdown';
        try {
            $r = (new ServiceIntervalResolver())->resolveFor($vehicleId, $class, $type, $riderId);
            if (($r['basis'] ?? 'km') === MaintenanceTypeModel::BASIS_TIME && !empty($r['days'])) {
                return 'next due on ' . \Carbon\Carbon::parse($date)->addDays((int) $r['days'])->format('j M Y');
            }
            if (!empty($r['km'])) {
                return 'next due at ' . number_format($meter + (int) $r['km']) . ' km';
            }
        } catch (\Throwable $e) {
            // fall through to the job's own figure
        }
        return (int) ($type->interval_km ?? 0) > 0
            ? 'next due at ' . number_format($meter + (int) $type->interval_km) . ' km'
            : '';
    }

    /**
     * ⭐ WHAT ACTUALLY HAPPENED, IN WORDS — one sentence for one job, one line per job for a
     *   visit. Every door prints this, so the Bikes screen, the workshop close and the rider's
     *   "did it get done?" can never describe the same save differently.
     *
     * ⚠ No "overall service clock" sentence any more (owner, 29-Sep-2026). Every job resets its
     *   own countdown and says when it is next due; the bike's summary chip is simply the most
     *   urgent of those, so there is no second clock to report on.
     *
     * @param array $jobs rows from recordVisit (need name, counts_down, duplicate, next_due)
     */
    public function visitReceipt(array $jobs, int $meter, string $date): string
    {
        $backdated = $date !== \Carbon\Carbon::today()->format('Y-m-d');
        $when = $backdated ? ' on ' . \Carbon\Carbon::parse($date)->format('D j M') : '';
        $at   = number_format($meter) . ' km' . $when;

        $fresh = array_values(array_filter($jobs, fn ($j) => empty($j['duplicate'])));
        $dups  = array_values(array_filter($jobs, fn ($j) => !empty($j['duplicate'])));

        if (!$fresh) {
            $names = implode(' + ', array_map(fn ($j) => $j['name'], $dups));
            return 'Already recorded — ' . $names . ' at ' . $at . ' is on file, so nothing was added again.';
        }

        if (count($jobs) === 1) {
            $j = $fresh[0];
            $s = $j['name'] . ' recorded at ' . $at;
            if (empty($j['counts_down']) && !empty($j['type_id'])) {
                return $s . '. Recorded as work done — no countdown was reset, because this job is not on a schedule';
            }
            return $s . (!empty($j['next_due']) ? ' — ' . $j['next_due'] : '');
        }

        $lines = array_map(fn ($j) => $j['name'] . (!empty($j['next_due']) ? ' (' . $j['next_due'] . ')' : ''), $fresh);
        $s = count($fresh) . ' jobs recorded at ' . $at . ': ' . implode(' · ', $lines);
        if ($dups) {
            $s .= '. Already on file, not added again: ' . implode(' + ', array_map(fn ($j) => $j['name'], $dups));
        }
        return $s;
    }

    /**
     * ✏️ CORRECT A SERVICE RECORD (owner ask, 3-Sep): "make sure Qasim or Shabib or Taimur can
     *    modify these service dates later on as well if needed."
     *
     * ⭐⭐ WHY THIS MATTERS MORE THAN IT LOOKS. Until now these rows were INSERT-ONLY. A record
     *    filed against the wrong job, the wrong day or the wrong odometer could be fixed only
     *    by hand-written SQL — which is exactly the situation log row #8 left us in, and the
     *    reason that repair is still sitting in a file waiting for someone to run it. A manager
     *    who can make the record must be able to correct it.
     *
     * ⚠ The countdown is DERIVED from these rows, so an edit self-corrects every surface the
     *   moment the caches are busted — there is nothing else to update, and no frozen figure
     *   to chase (unlike an approved claim, which carries money and is deliberately NOT
     *   editable here).
     *
     * ⚠ The profile stamp is REBUILT from the evidence rather than patched: a correction can
     *   move a record backwards, change its type so it no longer resets the clock, or delete
     *   it entirely, and only a rebuild is right in all three cases.
     *
     * @return array{ok: bool, message: string}
     */
    public function amend(int $logId, array $in, int $actorId): array
    {
        try {
            if (!Schema::hasTable('t_fleet_service_log')) {
                return ['ok' => false, 'message' => 'Service records are not set up yet.'];
            }
            $row = DB::table('t_fleet_service_log')->where('id', $logId)->first();
            if (!$row) return ['ok' => false, 'message' => 'That service record no longer exists.'];

            /**
             * ⭐⭐ A VISIT IS CORRECTED AS ONE (owner ruling, 29-Sep-2026). The jobs of one trip
             *    share one odometer and one day because they ARE one reading. Fixing a typo on
             *    one job and leaving it on the others would leave the visit with two truths —
             *    and the wrong one still inside a countdown. So a meter or date fix moves every
             *    job of the visit unless the caller explicitly says `apply_to_visit = 0`.
             * ⚠ WHICH JOB it was stays per row: correcting Oil Change → Oil + Tuning is about
             *   one job only.
             */
            $toVisit  = !array_key_exists('apply_to_visit', $in) || $in['apply_to_visit'] === null
                        || filter_var($in['apply_to_visit'], FILTER_VALIDATE_BOOLEAN);
            $siblings = $this->visitSiblingsOf($row);
            $addIds   = self::normaliseIds($in['add_type_ids'] ?? null);

            $update = [];
            if (array_key_exists('maintenance_type_id', $in) && $in['maintenance_type_id']
                && (int) $in['maintenance_type_id'] !== (int) $row->maintenance_type_id) {
                $t = $this->resolveType($in['maintenance_type_id']);
                if (!$t['ok']) return ['ok' => false, 'message' => $t['message']];
                // ⚠ Re-typing a job into one the visit already has would record it twice.
                foreach ($siblings as $s) {
                    if ((int) $s->maintenance_type_id === (int) $t['type']->id) {
                        return ['ok' => false, 'message' => $t['type']->type_name . ' is already recorded for '
                            . 'this visit. Remove this record instead of changing it.'];
                    }
                }
                $update['maintenance_type_id'] = (int) $t['type']->id;
            }
            if (!empty($in['meter']) && (int) $in['meter'] !== (int) $row->meter) {
                if ((int) $in['meter'] <= 0) return ['ok' => false, 'message' => 'Give the odometer in kilometres.'];
                $update['meter'] = (int) $in['meter'];
            }
            if (!empty($in['date']) && $in['date'] !== substr((string) $row->service_date, 0, 10)) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['date'])) {
                    return ['ok' => false, 'message' => 'Give the date as YYYY-MM-DD.'];
                }
                // ⚠ Same rule as recording one: work cannot have happened in the future.
                if ($in['date'] > \Carbon\Carbon::today()->format('Y-m-d')) {
                    return ['ok' => false, 'message' => 'That date is in the future.'];
                }
                $update['service_date'] = $in['date'];
            }
            if (!$update && !$addIds) return ['ok' => false, 'message' => 'Nothing to change.'];

            $moves   = isset($update['meter']) || isset($update['service_date']);
            $targets = [$row];
            if ($moves && $toVisit) {
                $targets = array_merge($targets, $siblings);
            } elseif (isset($update['meter']) && !empty($row->request_id)) {
                /**
                 * ⚠⚠ ONE BILL, ONE READING. Jobs paid by the same receipt share the claim's single
                 *    odometer, and the claim mirrors onto every job it pays for — so moving just
                 *    one of them would be undone by its own bill a moment later. Even "this job
                 *    only" therefore carries the jobs on the same bill.
                 */
                foreach ($siblings as $s) {
                    if (!empty($s->request_id) && (int) $s->request_id === (int) $row->request_id) $targets[] = $s;
                }
            }
            $targetIds = array_map(fn ($r) => (int) $r->id, $targets);

            $newMeter = (int) ($update['meter'] ?? $row->meter);
            $newDate  = (string) ($update['service_date'] ?? substr((string) $row->service_date, 0, 10));

            /**
             * ⚠⚠ A MOVE MUST NOT LAND ON A TWIN (pre-deploy C5, 29-Sep-2026). A meter or date fix
             *    that puts a job exactly where the SAME job is already recorded — same machine,
             *    day and odometer, a different record — would leave one job on file twice, the
             *    very shape the double-tap guard exists to prevent. Only a job change was checked
             *    before; a move is now checked the same way, and the refusal names the twin.
             */
            if ($moves) {
                foreach ($targets as $t) {
                    $tid = (int) ((int) $t->id === (int) $row->id && isset($update['maintenance_type_id'])
                        ? $update['maintenance_type_id'] : $t->maintenance_type_id);
                    if ($tid <= 0) continue;
                    $moved = clone $t;
                    $moved->meter = $newMeter;
                    $moved->service_date = $newDate;
                    $key = self::visitKeyOf($moved);
                    foreach (DB::table('t_fleet_service_log')
                                ->whereNotIn('id', $targetIds)
                                ->where('maintenance_type_id', $tid)
                                ->whereDate('service_date', $newDate)
                                ->where('meter', $newMeter)
                                ->orderBy('id')
                                ->get(array_merge(['id', 'user_id', 'meter', 'service_date'], self::logVehicleCols())) as $twin) {
                        if (self::visitKeyOf($twin) !== $key) continue;
                        $jobName = (string) (DB::table('t_fleet_maintenance_types')->where('id', $tid)->value('type_name') ?: 'this job');
                        return ['ok' => false, 'message' => 'Service record #' . (int) $twin->id . ' dated '
                            . \Carbon\Carbon::parse($newDate)->format('j M Y') . ' already has ' . $jobName
                            . ' at ' . number_format($newMeter) . ' km — remove one of them first, then correct the other.'];
                    }
                }
            }

            // ⭐⭐ A CORRECTION IS JUDGED LIKE A NEW ENTRY (Sep-20 2026) — on the pair it
            //    will LEAVE BEHIND, with every record being moved left out of the window so the
            //    visit can never bound its own correction.
            if ($moves) {
                $bad = $this->odometerObjection((int) $row->user_id, $newMeter, $newDate,
                                                self::logVehicleOf($row), $targetIds);
                if ($bad) return ['ok' => false, 'message' => $bad];
            }

            // ⭐ The correction is part of the record. Without this an audit cannot tell a
            //   figure someone chose from one someone later fixed.
            $stamp = 'corrected ' . \Carbon\Carbon::today()->format('j M Y') . ' by ' . $this->nameOf($actorId);

            $claims = [];
            DB::transaction(function () use ($targets, $row, $update, $stamp, &$claims) {
                foreach ($targets as $t) {
                    $u = array_intersect_key($update, array_flip(['meter', 'service_date']));
                    if ((int) $t->id === (int) $row->id && isset($update['maintenance_type_id'])) {
                        $u['maintenance_type_id'] = $update['maintenance_type_id'];
                    }
                    if (!$u) continue;
                    $u['note'] = mb_substr(trim(($t->note ? $t->note . ' · ' : '') . $stamp), 0, 250);
                    DB::table('t_fleet_service_log')->where('id', $t->id)->update($u);
                    if (!empty($t->request_id)) {
                        $claims[(int) $t->request_id] = ($claims[(int) $t->request_id] ?? false)
                            || ((int) $t->id === (int) $row->id && isset($u['maintenance_type_id']));
                    }
                }
            });

            /**
             * ⭐⭐ ONE JOB = ONE TRUTH (review, 3-Sep). A bill filed with these records carries
             *    the same odometer. Correct the records alone and the two halves disagree — so
             *    the reading is mirrored onto each bill through the same narrow door a manager
             *    would use by hand. The AMOUNT is never touched.
             * ⚠ The JOB is mirrored only onto a bill that pays for this one job alone. A shared
             *   bill keeps its lead job; its label is read from the records it pays for.
             */
            $mirrored = '';
            foreach ($claims as $rid => $typeChangedHere) {
                $linkedCount = (int) DB::table('t_fleet_service_log')->where('request_id', $rid)->count();
                $mc = [];
                if (isset($update['meter'])) $mc['meter'] = $update['meter'];
                if ($typeChangedHere && $linkedCount === 1) $mc['maintenance_type_id'] = $update['maintenance_type_id'];
                if (!$mc) continue;
                $m = $this->correctClaim($rid, $mc, $actorId);
                $mirrored = $m['ok'] ? ' The linked expense now carries the same reading.'
                                     : ' ⚠ The linked expense could NOT be updated: ' . $m['message'];
            }

            /**
             * ➕ A JOB FORGOTTEN AT THE TIME (29-Sep-2026) — recorded into the SAME visit through
             *    the one writer, so it is judged, stamped and de-duplicated exactly like the rest.
             */
            $added = '';
            if ($addIds) {
                $vid   = self::logVehicleOf($row);
                $class = null;
                try {
                    $class = $vid ? (new VehicleService())->classOf($vid) : null;
                } catch (\Throwable $e) {
                    $class = null;
                }
                $jobs = $this->resolveJobs($addIds, $class);
                if (!$jobs['ok']) {
                    return ['ok' => false, 'message' => ($update ? 'The correction was saved, but ' : '')
                        . $jobs['message']];
                }
                $rec = $this->recordVisit([
                    'rider_id'   => (int) $row->user_id,
                    'vehicle_id' => $vid,
                    'meter'      => $newMeter,
                    'date'       => $newDate,
                    'jobs'       => $jobs['jobs'],
                    'actor_id'   => $actorId,
                    'note'       => 'Added to this visit ' . \Carbon\Carbon::today()->format('j M Y')
                                    . ' by ' . $this->nameOf($actorId),
                ]);
                if (!$rec['ok']) {
                    return ['ok' => false, 'message' => ($update ? 'The correction was saved, but the job could not be added: ' : '')
                        . $rec['message']];
                }
                $added = ' ' . $rec['message'] . '.';
            }

            $this->rebuildProfileStamp((int) $row->user_id);
            // ⚠ The machine the row is ABOUT — a correction to a service on a bike he no
            //   longer holds must still clear that bike's countdown cache.
            $this->bustCaches((int) $row->user_id, self::logVehicleOf($row));

            $n = count($targets);
            $said = $update
                ? ($n > 1 && $moves
                    ? 'Visit corrected — all ' . $n . ' jobs now read ' . number_format($newMeter)
                      . ' km on ' . \Carbon\Carbon::parse($newDate)->format('j M') . '.'
                    : 'Service record corrected.')
                : '';
            return ['ok' => true, 'message' => trim($said . $mirrored . $added)];
        } catch (\Throwable $e) {
            Log::error('ServiceRecordService::amend failed', ['log' => $logId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not correct that record.'];
        }
    }

    /**
     * ⭐⭐ CORRECT THE ODOMETER (and the job) ON A MAINTENANCE **CLAIM** — including an
     *    APPROVED one. The narrow second door asked for on 3-Sep.
     *
     * ⚠⚠ WHY THIS EXISTS. `FleetFuelController::editClaim` refuses any edit once a claim is
     *    approved — *"an approved claim has money in the ledger — reverse it and file it again
     *    instead."* That guard is right about MONEY and wrong about everything else, and it
     *    locked a field that is not money at all. Live proof: AY-4771 read "Oil Change 767 km
     *    overdue" off an approved 17-Aug claim at 48,777 km. If that odometer were a typo,
     *    nobody could fix it — and the only workaround, recording a manual service at the right
     *    meter, leaves the wrong number in the history for ever.
     *
     * ⭐ THE LINE THIS DRAWS: the odometer and which job was done are OBSERVATIONS about a
     *   machine. The amount, the date and the vehicle are MONEY — they set what was spent, which
     *   period it lands in, and which bike carries the cost. Only the observations are editable
     *   here; for the rest, reverse and re-file remains the right answer.
     *
     * ⚠ Deliberately NOT a relaxation of `editClaim`. That method still refuses approved claims
     *   for every field it owns. This is a separate, narrower entrance with its own permission.
     *
     * ⭐ Nothing needs recomputing afterwards: every countdown is DERIVED from this row, so a
     *   correction self-corrects the schedule panel, the alerts, the rider's chip and the web
     *   card at once. We only have to invalidate the caches in front of them.
     */
    public function correctClaim(int $requestId, array $in, int $actorId): array
    {
        try {
            $row = DB::table('t_req_master')->where('id', $requestId)->first();
            if (!$row) return ['ok' => false, 'message' => 'That claim no longer exists.'];
            if (($row->expense_category ?? '') !== 'Maintenance') {
                return ['ok' => false, 'message' => 'Only a maintenance claim carries a service reading.'];
            }

            $update = [];

            if (array_key_exists('maintenance_type_id', $in) && $in['maintenance_type_id']) {
                $t = $this->resolveType($in['maintenance_type_id']);
                if (!$t['ok']) return ['ok' => false, 'message' => $t['message']];
                $update['maintenance_type_id'] = (int) $t['type']->id;
                // ⚠ The legacy machine flag is DERIVED from the type's bucket and is what the
                //   older rules branch on — leaving it stale would make the claim read as one
                //   kind of work to this engine and another to those.
                $update['service_type'] = $t['type']->bucket === 'regular' ? 'oil_change' : 'repair';
            }

            if (array_key_exists('meter', $in) && $in['meter'] !== null && $in['meter'] !== '') {
                if ((int) $in['meter'] <= 0) return ['ok' => false, 'message' => 'Give the odometer in kilometres.'];
                $update['meter_at_fill'] = (int) $in['meter'];
            }

            if (!$update) return ['ok' => false, 'message' => 'Nothing to change.'];

            // ⭐ The correction is part of the record, exactly as it is for a service log —
            //   without this an audit cannot tell a figure someone chose from one someone
            //   later fixed. Appended, never overwriting what the filer wrote.
            $note = trim((string) ($row->description ?? ''));
            $stamp = 'Service reading corrected ' . \Carbon\Carbon::today()->format('j M Y')
                   . ' by ' . $this->nameOf($actorId)
                   . (isset($update['meter_at_fill'])
                        ? ' (odometer ' . ($row->meter_at_fill ?? '—') . ' → ' . $update['meter_at_fill'] . ')' : '')
                   . ' — the amount was not changed.';
            $update['description'] = mb_substr(($note !== '' ? $note . "\n" : '') . $stamp, 0, 2000);
            $update['updated_by']  = $actorId;
            $update['updated_at']  = now();

            /**
             * ⚠⚠ THE FROZEN FIGURE MUST GO WITH THE READING IT WAS FROZEN FROM (review, 3-Sep).
             *    `service_due_km` is stamped at approval as "km until due, measured from THIS
             *    claim's odometer" and the claim card prints it as "done N km overdue". Correct
             *    the odometer and leave it, and the card keeps quoting a number computed from
             *    the figure just declared wrong — proven: 48,777 → 48,000 left it at −564.
             *    Cleared, the card falls back to the live derivation, which is the truth.
             */
            if (isset($update['meter_at_fill']) && Schema::hasColumn('t_req_master', 'service_due_km')) {
                $update['service_due_km'] = null;
            }

            DB::table('t_req_master')->where('id', $requestId)->update($update);

            /**
             * ⭐⭐ THE OTHER HALF OF THE MIRROR (review, 3-Sep — found NOT built while re-checking).
             *    `amend()` on a log already mirrors the reading onto its claim. This is the
             *    reverse: correcting the claim must reach the LOG it is linked to, or the pair
             *    silently disagrees — and the history and countdown follow the log, so the
             *    correction the manager just made would appear to have done nothing.
             * ⚠ Only a LIVE link, and only the two observation fields. No amount, ever.
             */
            $mirrored = '';
            try {
                if (Schema::hasColumn('t_fleet_service_log', 'request_id')) {
                    /**
                     * ⚠⚠ EVERY record the bill pays for (29-Sep-2026) — one receipt may cover a
                     *    whole visit, and those jobs share one odometer. Mirroring onto the first
                     *    only would split the visit into two readings.
                     * ⚠ The JOB is mirrored only when the bill pays for exactly one record: a
                     *   shared bill's lead job is not "the" job of its other records.
                     */
                    $logs = DB::table('t_fleet_service_log')->where('request_id', $requestId)
                        ->orderBy('id')->get(['id', 'user_id']);
                    if ($logs->isNotEmpty()) {
                        $lu = [];
                        if (isset($update['meter_at_fill'])) $lu['meter'] = $update['meter_at_fill'];
                        if (isset($update['maintenance_type_id']) && $logs->count() === 1) {
                            $lu['maintenance_type_id'] = $update['maintenance_type_id'];
                        }
                        if ($lu) {
                            DB::table('t_fleet_service_log')->whereIn('id', $logs->pluck('id')->all())->update($lu);
                            $this->rebuildProfileStamp((int) $logs->first()->user_id);
                            $mirrored = $logs->count() > 1
                                ? ' All ' . $logs->count() . ' linked service records now carry the same reading.'
                                : ' The linked service record now carries the same reading.';
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('correctClaim: log mirror failed', ['request' => $requestId, 'error' => $e->getMessage()]);
            }

            /**
             * ⚠ Keyed on the claim's OWN machine, not on what its requester holds today — a
             *   claim from July belongs to the bike it was filed against, and the rider may be
             *   on a different one now. An UNSTAMPED (pre-registry) claim is attributed by who
             *   held which machine on its date, so resolve it the same way the evidence engine
             *   will, or the correction sits behind a 5-minute cache on the wrong vehicle.
             */
            $vid = $row->vehicle_id ? (int) $row->vehicle_id : null;
            if (!$vid && $row->requester_user_id) {
                try {
                    $vid = (new VehicleResolver())->vehicleForDay((int) $row->requester_user_id,
                        $row->expense_date ? substr((string) $row->expense_date, 0, 10) : \Carbon\Carbon::today()->format('Y-m-d'));
                } catch (\Throwable $e) {
                    $vid = null;
                }
            }
            VehicleService::bumpServiceEvidence($vid ? (int) $vid : null);
            if ($row->requester_user_id) $this->bustCaches((int) $row->requester_user_id);

            return ['ok' => true, 'message' => 'Service reading corrected. The amount is unchanged.' . $mirrored];
        } catch (\Throwable $e) {
            Log::error('ServiceRecordService::correctClaim failed',
                ['request' => $requestId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not correct that reading.'];
        }
    }

    /**
     * Remove ONE job record that should never have been there. Always per job — unticking
     * Chain Set from a visit removes Chain Set, never the Oil + Tuning done beside it.
     */
    public function remove(int $logId, int $actorId): array
    {
        try {
            if (!Schema::hasTable('t_fleet_service_log')) {
                return ['ok' => false, 'message' => 'Service records are not set up yet.'];
            }
            $row = DB::table('t_fleet_service_log')->where('id', $logId)->first();
            if (!$row) return ['ok' => false, 'message' => 'That service record no longer exists.'];
            // ⚠ Read BEFORE the delete — afterwards there is no row to resolve the machine from.
            $wasFor   = self::logVehicleOf($row);
            $siblings = $this->visitSiblingsOf($row);

            DB::table('t_fleet_service_log')->where('id', $logId)->delete();
            /**
             * ⚠ A workshop visit that produced this record must stop pointing at a row that is
             *   gone. When another job of the SAME visit survives, the link moves to it (the
             *   visit still produced a service); only a visit with nothing left loses it.
             */
            try {
                if (Schema::hasTable(WorkshopVisitService::T_VISIT)) {
                    DB::table(WorkshopVisitService::T_VISIT)
                        ->where('service_log_id', $logId)
                        ->update(['service_log_id' => $siblings ? (int) $siblings[0]->id : null]);
                }
            } catch (\Throwable $e) { /* the visit stays, it just loses the link */ }

            $this->rebuildProfileStamp((int) $row->user_id);

            /**
             * ⚠⚠ DELETING A SERVICE NEVER DELETES MONEY (review, 3-Sep). When this record was
             *    filed with its bill, the claim stays exactly as it is — approved, in the
             *    ledger, or in a queue. The manager is told so, because "I removed it" must not
             *    be read as "the expense is gone too".
             * ⭐ A bill SHARED by several jobs (29-Sep-2026) keeps covering the ones that
             *   remain, and its lead job moves to one of them — otherwise the bill would go on
             *   printing the name of the job just removed.
             */
            $kept = '';
            if (!empty($row->request_id)) {
                $amt  = DB::table('t_req_master')->where('id', $row->request_id)->value('amount');
                $left = DB::table('t_fleet_service_log')->where('request_id', $row->request_id)
                    ->orderBy('id')->get(['id', 'maintenance_type_id']);
                if ($left->isNotEmpty()) {
                    try {
                        $claimType = DB::table('t_req_master')->where('id', $row->request_id)->value('maintenance_type_id');
                        if (!$left->contains(fn ($l) => (int) $l->maintenance_type_id === (int) $claimType)) {
                            $lead = $this->leadLogOf($left->all());
                            $t = $lead ? app(MaintenanceTypeService::class)->find($lead->maintenance_type_id) : null;
                            if ($t) {
                                DB::table('t_req_master')->where('id', $row->request_id)->update([
                                    'maintenance_type_id' => (int) $t->id,
                                    'service_type'        => $t->bucket === 'regular' ? 'oil_change' : 'repair',
                                ]);
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::warning('remove: shared bill lead not moved', ['log' => $logId, 'error' => $e->getMessage()]);
                    }
                    $kept = ' The Rs ' . number_format((float) $amt) . ' bill stays on record and now covers '
                          . ($this->jobNamesOfLogs($left->pluck('id')->all()) ?: 'the remaining jobs') . '.';
                } else {
                    $kept = ' The Rs ' . number_format((float) $amt) . ' expense filed with it is NOT removed'
                          . ' — it stays on record; reverse it from the claims flow if it should not stand.'
                          . $this->releaseClaimAsService((int) $row->request_id, $row, $actorId);
                }
            }
            $this->bustCaches((int) $row->user_id, $wasFor);
            return ['ok' => true, 'message' => 'Service record removed.' . $kept];
        } catch (\Throwable $e) {
            Log::error('ServiceRecordService::remove failed', ['log' => $logId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not remove that record.'];
        }
    }

    /**
     * ⭐⭐ THE LAST JOB ON A BILL WAS REMOVED — the bill stops counting as that service (owner
     *    ruling D7, 29-Sep-2026: "asks first and says the clock goes back, and the clock really
     *    does go back").
     *
     * ⚠⚠ WHY SOMETHING HAS TO CHANGE ON THE CLAIM. While a job record carries the bill, the
     *    evidence engine hides the claim (the job speaks for it). Remove the last job and the
     *    APPROVED claim steps back in as evidence at the same odometer — so the countdown the
     *    manager just took back would never move. The claim's job type is cleared, and for an
     *    oil-bucket claim its legacy `service_type` too: an untyped claim marked oil_change is
     *    counted by the legacy "untyped oil change" readers (overallServiceStateFor,
     *    lastServicePointBefore, the rider fallback anchor), which would put the clock right
     *    back. A `repair` flag is kept — no clock reads it, and its "Repair" label stays.
     * ⭐ The MONEY is untouched: amount, category (Maintenance), status, date, machine, the
     *   ledger posting and the title that names the job. It reads as a plain maintenance
     *   expense from here on; the description records why, and a manager can re-type a pending
     *   one through the claim edit if the work did happen after all.
     * ⚠ Only a LIVE bill — a rejected or cancelled one is not evidence of anything already.
     *
     * @return string the sentence for the receipt ('' when nothing changed)
     */
    private function releaseClaimAsService(int $requestId, object $removedRow, int $actorId): string
    {
        try {
            $claim = DB::table('t_req_master')->where('id', $requestId)
                ->first(['id', 'status', 'expense_category', 'maintenance_type_id', 'service_type', 'description']);
            if (!$claim || !in_array($claim->status, self::LIVE_BILL_STATUSES, true)
                || ($claim->expense_category ?? '') !== 'Maintenance') {
                return '';
            }
            $job = (string) (DB::table('t_fleet_maintenance_types')->where('id', (int) $removedRow->maintenance_type_id)
                                ->value('type_name') ?: 'this job');
            $u = ['maintenance_type_id' => null];
            if (in_array($claim->service_type, ['oil_change', 'general'], true)) $u['service_type'] = null;
            $note = trim((string) ($claim->description ?? ''));
            $u['description'] = mb_substr(($note !== '' ? $note . "\n" : '')
                . 'Service record removed ' . \Carbon\Carbon::today()->format('j M Y') . ' by ' . $this->nameOf($actorId)
                . ' (' . $job . ') — this bill no longer counts as a service; the amount was not changed.', 0, 2000);
            $u['updated_by'] = $actorId ?: null;
            $u['updated_at'] = now();
            DB::table('t_req_master')->where('id', $requestId)->update($u);
            return ' This was the last job on bill #' . $requestId . ', so that bill no longer counts as a '
                . 'service for ' . $job . ' — its countdown goes back to the previous ' . $job . ' on record.';
        } catch (\Throwable $e) {
            Log::warning('remove: claim not released as a service', ['request' => $requestId, 'error' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * Recompute `last_service_meter` / `last_service_at` from the evidence that remains.
     *
     * ⚠⚠ REBUILT, NEVER PATCHED. An edit can move a record backwards, change its type so it no
     *    longer resets the overall clock, or remove it altogether — patching the stamp would be
     *    right for none of those. The stamp is only a fallback seed anyway (the real countdown
     *    is derived), but a stale one shows up on riders with no registered machine.
     */
    /**
     * The one odometer rule, asked of a service reading — `FuelClaimRules::odometerObjection`,
     * the same window every petrol and maintenance claim is judged by on every surface.
     *
     * ⚠ FAILS OPEN on an error, exactly like the magnitude test above it: a guard must never be
     *   the reason a real service cannot be recorded. It refuses only on positive evidence.
     */
    private function odometerObjection(int $riderId, int $meter, string $date, ?int $vehicleId,
                                       $ignoreLogId = null): ?string   // int, or a whole visit's ids
    {
        try {
            return (new FuelClaimRules())->odometerObjection($riderId, $meter, $date, $vehicleId, $ignoreLogId);
        } catch (\Throwable $e) {
            Log::warning('ServiceRecordService: odometer guard failed open', [
                'rider' => $riderId, 'meter' => $meter, 'date' => $date, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function rebuildProfileStamp(int $riderId): void
    {
        try {
            $latest = DB::table('t_fleet_service_log as l')
                ->join('t_fleet_maintenance_types as t', 't.id', '=', 'l.maintenance_type_id')
                ->where('l.user_id', $riderId)
                ->where('t.resets_service_clock', 1)
                ->orderByDesc('l.meter')->orderByDesc('l.id')
                ->first(['l.meter', 'l.service_date']);
            DB::table('t_ops_rider_profile')->where('user_id', $riderId)->update([
                'last_service_meter' => $latest->meter ?? null,
                'last_service_at'    => $latest->service_date ?? null,
                'updated_at'         => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Profile service stamp not rebuilt', ['rider' => $riderId, 'error' => $e->getMessage()]);
        }
    }

    private function nameOf(?int $userId): string
    {
        if (!$userId) return 'someone';
        try {
            return (string) (DB::table('t_sys_user')->where('id', $userId)->value('fullname') ?: 'someone');
        } catch (\Throwable $e) {
            return 'someone';
        }
    }

    /**
     * One job, in words — kept for any caller that still thinks in single jobs. It is the
     * visit receipt for a list of one, so there is ONE sentence for "what just happened".
     * ⚠ `$movedClock` no longer changes the wording: every job resets its own countdown and
     *   says when it is next due; the old "overall clock unchanged" line is gone (29-Sep-2026).
     */
    public function receipt($type, int $meter, string $date, bool $movedClock): string
    {
        $counts = $type ? ((int) ($type->interval_km ?? 0) > 0 || !empty($type->interval_days)) : true;
        return $this->visitReceipt([[
            'name'        => $type ? (string) $type->type_name : 'Service',
            'type_id'     => $type ? (int) $type->id : null,
            'counts_down' => $counts,
            'duplicate'   => false,
            'next_due'    => $this->nextDueText($type, $counts, null, null, null, $meter, $date),
        ]], $meter, $date);
    }

    /**
     * ⚠ The derived service state is memoised per process AND cached across requests —
     *   bump the machine's evidence version so both die, or the very next render answers
     *   from evidence gathered before this write and tells the user the service he just
     *   recorded has not happened.
     */
    /**
     * @param ?int $vehicleId ⭐ THE MACHINE THE RECORD IS ABOUT, when the caller knows it
     *        (10-Sep-2026). This used to bump only `currentVehicleFor($rider)` — what he is
     *        holding NOW — which is the wrong machine in exactly the case that matters: the
     *        bike is at the workshop and he has been given a spare. The service then landed
     *        on the right bike and the right bike's cached countdown was never cleared.
     */
    public function bustCaches(int $riderId, ?int $vehicleId = null): void
    {
        // A write may have linked a bill to more jobs, or taken one away — forget the labels.
        self::flushClaimLabels();
        try {
            $vid = (new VehicleResolver())->currentVehicleFor($riderId);
        } catch (\Throwable $e) {
            $vid = null;
        }
        VehicleService::bumpServiceEvidence($vid ? (int) $vid : null);
        if ($vehicleId && (int) $vehicleId !== (int) $vid) {
            VehicleService::bumpServiceEvidence((int) $vehicleId);
        }

        // Targeted only — a global flush would also wipe unrelated caches.
        try {
            $this_ = \Carbon\Carbon::today()->format('Y-m');
            $prev  = \Carbon\Carbon::today()->subMonthNoOverflow()->format('Y-m');
            foreach ([$this_, $prev] as $m) {
                Cache::forget("fleet_fuel_month_{$m}");
                Cache::forget("fleet_fuel_rider_{$riderId}_{$m}");
            }
        } catch (\Throwable $e) {
            // caches expire on their own within CACHE_SECS
        }
    }
}
