<?php

namespace App\Services\Riders;

use App\Models\Riders\MaintenanceTypeModel;
use Illuminate\Support\Facades\Schema;

/**
 * Everything the maintenance-type list is allowed to decide, in one place.
 *
 * Aug-2026. The manager wanted regular maintenance split into named categories
 * he can edit himself (Oil Change 1,200 km / Oil + Tuning 2,500 km / Brake Shoe
 * 10,000 km / Chain Set / Misc), with repair types he adds as he goes.
 *
 * ⭐ The type is a LABEL on top of the existing machine flag, never a replacement
 * for it — see MaintenanceTypeModel. This service is the only thing that turns a
 * chosen type into `service_type`, and the only thing that builds the picker.
 *
 * ⚠ Guarded on Schema::hasTable throughout: the web files may be uploaded before
 * batch 12 is run, and that must degrade to today's plain Regular/Repair picker
 * rather than 500 every bike form.
 */
class MaintenanceTypeService
{
    private ?bool $tableExists = null;
    private static ?bool $classAware = null;

    public function available(): bool
    {
        if ($this->tableExists === null) {
            try {
                $this->tableExists = Schema::hasTable('t_fleet_maintenance_types');
            } catch (\Throwable $e) {
                $this->tableExists = false;
            }
        }
        return $this->tableExists;
    }

    /**
     * Has the Sep-2026 class/basis migration run?
     *
     * ⚠ The web files may be uploaded before the SQL, exactly as batch 12 was. When
     *   the columns are absent EVERY class resolves to the bike numbers — which is
     *   precisely today's behaviour — so the fleet keeps working and nothing 500s.
     *   Asserted by test_service_intervals.php §7f, which drops the columns inside a
     *   rolled-back transaction and compares the whole payload to the baseline.
     */
    public function classAware(): bool
    {
        if (self::$classAware === null) {
            try {
                self::$classAware = $this->available()
                    && Schema::hasColumn('t_fleet_maintenance_types', 'applies_to');
            } catch (\Throwable $e) {
                self::$classAware = false;
            }
        }
        return self::$classAware;
    }

    /** Tests, and anything that changes the schema mid-process. */
    public static function flushSchemaMemo(): void
    {
        self::$classAware = null;
    }

    /**
     * The picker list — active types, repair last, in the manager's own order.
     * Shape is deliberately flat and small: it rides along on payloads the bike
     * screens already fetch rather than needing an endpoint of its own (the same
     * lesson as pay_sources — a separate endpoint would have its own permission
     * gate and lock out the very users who need it).
     *
     * @return array<int, array<string, mixed>>
     */
    public function options(bool $includeInactive = false): array
    {
        if (!$this->available()) {
            return [];
        }
        try {
            $q = MaintenanceTypeModel::query();
            if (!$includeInactive) {
                $q->where('is_active', 1);
            }
            return $q->orderByRaw("CASE WHEN bucket = 'regular' THEN 0 ELSE 1 END")
                ->orderBy('sort_order')->orderBy('type_name')
                ->get()
                ->map(fn (MaintenanceTypeModel $t) => $this->rawRow($t))->all();
        } catch (\Throwable $e) {
            \Log::warning('Maintenance type options failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * ⭐⭐ THE PICKER LIST **FOR ONE KIND OF MACHINE** (Sep-2026).
     *
     * Every screen that knows which vehicle it is talking about asks for this, and
     * gets rows whose `interval_km` / `due_label` are ALREADY the number that machine
     * follows. That is what keeps the change small: the mobile chips, the web
     * optgroups and the record-service prompt all read the same keys they always
     * read, and simply receive van numbers on a van.
     *
     * Jobs that do not apply to the class are DROPPED — a van keeper is never offered
     * "Chain Set", and a bike rider is never offered a van-only job.
     *
     * ⚠ Jobs the class has no NUMBER for are kept, labelled "as conditions": the job
     *   still exists and a bill may still be filed against it, there is simply nothing
     *   to count down to. That is the van's honest state on day one, before management
     *   has entered any van figures.
     *
     * @param  ?string $class  'bike' | 'van' (anything else reads as bike)
     */
    public function optionsFor(?string $class, bool $includeInactive = false): array
    {
        if (!$this->available()) {
            return [];
        }
        // Pre-SQL: one list, bike numbers, exactly as before this feature existed.
        if (!$this->classAware()) {
            return $this->options($includeInactive);
        }
        $class = MaintenanceTypeModel::normaliseClass($class);
        try {
            $q = MaintenanceTypeModel::query();
            if (!$includeInactive) {
                $q->where('is_active', 1);
            }
            return $q->orderByRaw("CASE WHEN bucket = 'regular' THEN 0 ELSE 1 END")
                ->orderBy('sort_order')->orderBy('type_name')
                ->get()
                ->filter(fn (MaintenanceTypeModel $t) => $t->appliesToClass($class))
                ->map(fn (MaintenanceTypeModel $t) => $this->resolvedRow($t, $class))
                ->values()->all();
        } catch (\Throwable $e) {
            \Log::warning('Maintenance type options (class) failed',
                          ['class' => $class, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /** The unresolved row — BOTH classes' numbers. Only the Types EDITOR wants this. */
    private function rawRow(MaintenanceTypeModel $t): array
    {
        return [
            'id'                   => $t->id,
            'name'                 => $t->type_name,
            'bucket'               => $t->bucket,
            'service_type'         => $t->service_type,
            // ⚠ Unchanged meaning for every existing reader: the BIKE number.
            'interval_km'          => $t->interval_km !== null ? (int) $t->interval_km : null,
            'due_label'            => $t->due_label,
            'resets_service_clock' => (bool) $t->resets_service_clock,
            'is_active'            => (bool) $t->is_active,
            'sort_order'           => $t->sort_order,
            // ---- additive, Sep-2026 ------------------------------------------
            'applies_to'           => (string) ($t->applies_to ?: MaintenanceTypeModel::APPLIES_BIKE),
            'basis'                => (string) ($t->basis ?: MaintenanceTypeModel::BASIS_KM),
            'interval_km_van'      => $t->interval_km_van !== null ? (int) $t->interval_km_van : null,
            'interval_days'        => $t->interval_days !== null ? (int) $t->interval_days : null,
            'interval_days_van'    => $t->interval_days_van !== null ? (int) $t->interval_days_van : null,
            // Ready-made phrasing per class, so the editor never composes its own.
            'bike_label'           => $t->appliesToClass(MaintenanceTypeModel::CLASS_BIKE)
                                        ? $t->scheduleForClass(MaintenanceTypeModel::CLASS_BIKE)['label'] : null,
            'van_label'            => $t->appliesToClass(MaintenanceTypeModel::CLASS_VAN)
                                        ? $t->scheduleForClass(MaintenanceTypeModel::CLASS_VAN)['label'] : null,
        ];
    }

    /** One row already narrowed to a class — what every picker and panel gets. */
    private function resolvedRow(MaintenanceTypeModel $t, string $class): array
    {
        $s = $t->scheduleForClass($class);

        return [
            'id'                   => $t->id,
            'name'                 => $t->type_name,
            'bucket'               => $t->bucket,
            'service_type'         => $t->service_type,
            // ⚠⚠ THE CLASS'S OWN NUMBER, under the key every screen already reads.
            //    A km job on a van gets the van's km; a TIME job reports 0 here so an
            //    old APK's `interval_km > 0` filter hides it rather than drawing a
            //    countdown in the wrong unit. New clients read `has_schedule`.
            'interval_km'          => $s['basis'] === MaintenanceTypeModel::BASIS_KM ? $s['km'] : 0,
            'due_label'            => $s['label'],
            'resets_service_clock' => (bool) $t->resets_service_clock,
            'is_active'            => (bool) $t->is_active,
            'sort_order'           => $t->sort_order,
            // ---- additive, Sep-2026 ------------------------------------------
            'vehicle_class'        => $class,
            'basis'                => $s['basis'],
            'interval_days'        => $s['days'],
            'interval_label'       => $s['label'],
            // The ONE flag a client should test for "does this job count down at all",
            // whatever it is measured in.
            'has_schedule'         => (bool) $s['has'],
        ];
    }

    /** One type by id, active or not (history must still resolve a retired type). */
    public function find($id): ?MaintenanceTypeModel
    {
        if (!$this->available() || !$id) {
            return null;
        }
        try {
            return MaintenanceTypeModel::find((int) $id);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Turn what a form submitted into the pair actually stored.
     *
     * The type id is authoritative when present — the server derives service_type
     * from its bucket and IGNORES whatever service_type the client sent, so a
     * stale or hand-crafted payload cannot pair "Oil Change" with `repair` and
     * quietly dodge the meter requirement.
     *
     * With no type id it falls through to the raw service_type: that is every
     * installed APK, and the web forms until this ships.
     *
     * @return array{0: ?string, 1: ?int}  [service_type, maintenance_type_id]
     */
    public function resolve($typeId, ?string $rawServiceType): array
    {
        $type = $this->find($typeId);
        if ($type) {
            return [$type->service_type, $type->id];
        }

        $legacy = in_array($rawServiceType, ['oil_change', 'repair', 'general'], true)
            ? $rawServiceType
            : null;

        return [$legacy, null];
    }

    /**
     * Does an approved claim of this type reset the bike's service-due clock?
     * Untyped rows keep the pre-Aug-2026 answer: any oil change resets it.
     */
    public function resetsClock($typeId, ?string $serviceType): bool
    {
        $type = $this->find($typeId);
        if ($type) {
            return (bool) $type->resets_service_clock;
        }
        return in_array($serviceType, ['oil_change', 'general'], true);
    }

    /**
     * What to show on a claim row. Falls back to the bucket label so the 108
     * existing untyped Maintenance rows read exactly as they do today.
     */
    /**
     * ⭐ `$requestId` (29-Sep-2026): the CLAIM being labelled. One bill can pay for several
     *   jobs of a visit, and then its label is every job it covers ("Oil + Tuning + Brake
     *   Shoe"), from `ServiceRecordService::jobLabelsForClaims` — the one place that answers.
     *   Without it the claim prints only its lead job and looks like it paid for that alone.
     * ⚠ Optional and trailing on purpose: a caller built before this still gets today's answer.
     */
    public function labelFor($typeId, ?string $serviceType, $requestId = null): ?string
    {
        if ($requestId) {
            $combined = app(ServiceRecordService::class)->jobLabelsForClaims([(int) $requestId]);
            if (!empty($combined[(int) $requestId])) return $combined[(int) $requestId];
        }
        $type = $this->find($typeId);
        return $type ? $type->type_name : MaintenanceTypeModel::bucketLabel($serviceType);
    }

    /**
     * Names for a set of type ids in one query — for list payloads that would
     * otherwise call find() per row.
     *
     * @return array<int, string>
     */
    public function namesByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$this->available() || !$ids) {
            return [];
        }
        try {
            return MaintenanceTypeModel::whereIn('id', $ids)
                ->pluck('type_name', 'id')->map(fn ($v) => (string) $v)->all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
