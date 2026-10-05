<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ⭐⭐ ONE ANSWER TO "HOW FAR DID HE RIDE TODAY?" WHEN THE DAY HAS TWO ODOMETERS.
 *
 * ⚠⚠ THE BUG THIS EXISTS TO KILL (Rajab, 11-Sep-2026). `t_ops_attendance` holds ONE meter pair
 *    per rider-day. On a day he arrives on his own bike (7,610) and takes the van out (75,484),
 *    `meter_end - meter_start` is **67,874 km** — and that number was rendered as a distance on
 *    the attendance row, in the monthly report, in the day-details Meter modal ("DAY RIDDEN
 *    67874 km · Δ 67804") and on the manager's phone. It is not a distance at all; it is the
 *    difference between two unrelated odometers.
 *
 * ⭐ The reading STAMPS (`meter_start_vehicle_id` / `meter_end_vehicle_id`, written at the moment
 *   of capture by RiderController::meterStampFields) already record which machine each end is of.
 *   Every surface that subtracts must ask this class first, so they can never drift apart again.
 *
 * ⚠ SILENT AND BACKWARD-COMPATIBLE BY DESIGN:
 *   · stamps not migrated yet  → `isSplit` is false → distance behaves exactly as it always has;
 *   · only ONE end stamped     → "we cannot say it is two machines", NOT a split. Today's number
 *                                stands. (Same rule as the `meter_machines.split` flag.)
 *   · both stamped and equal   → ordinary single-machine day, unchanged.
 *   Only BOTH-stamped-and-different suppresses the number — the one case where it is a lie.
 *
 * ⚠ Per-machine kilometres are NOT computed here. They live in `RiderDayLegs`, which reconstructs
 *   a leg per machine from the stamps, the assignment table and the vehicle meter log. This class
 *   only answers "is the single pair honest, and if so what is it" — deliberately cheap, no
 *   queries on the hot path, so the attendance page can call it once per row.
 */
class MeterPairHelper
{
    /**
     * Does this row's pair describe TWO different machines?
     *
     * ⚠ Requires BOTH readings present AND both stamped AND the stamps different. Anything
     *   less is "no opinion" and must keep rendering as it does today.
     *
     * @param  object|array|null $att  a t_ops_attendance row
     */
    public static function isSplit($att): bool
    {
        $a = self::obj($att);
        if (!$a) return false;

        try {
            if (!VehicleService::stampsAvailable()) return false;
        } catch (\Throwable $e) {
            return false;
        }

        // A stamp only speaks for a reading that actually exists.
        $ms = self::num($a->meter_start ?? null);
        $me = self::num($a->meter_end ?? null);
        if ($ms === null || $me === null) return false;

        $sv = self::vid($a->meter_start_vehicle_id ?? null);
        $ev = self::vid($a->meter_end_vehicle_id ?? null);
        if (!$sv || !$ev) return false;

        return $sv !== $ev;
    }

    /**
     * The day's meter distance, or NULL when there isn't an honest one.
     *
     * NULL means "do not print a number" — the caller shows the two-machines marker instead.
     * Non-split behaviour is byte-for-byte the old `abs((int) $end - (int) $start)`, including
     * the abs(), so no existing row changes value.
     *
     * @return int|null
     */
    public static function distance($att): ?int
    {
        $a = self::obj($att);
        if (!$a) return null;

        // ⭐ Meter replaced (Oct-2026): on the day a machine's meter is swapped the two readings
        //   are off two meters — measured on the continuous scale that is the real ride, where
        //   the bare `abs()` reads "21,365 km". The same object comes back (and nothing
        //   changes) unless a reading is off a replaced meter.
        $a = MeterReplacement::liftRow($a);

        $ms = self::num($a->meter_start ?? null);
        $me = self::num($a->meter_end ?? null);
        if ($ms === null || $me === null) return null;

        if (self::isSplit($a)) return null;

        return (int) abs($me - $ms);
    }

    /**
     * ⭐ THE LABEL RULE FOR A MACHINE IN A METER SENTENCE.
     *
     * ⚠ NOT `VehicleService::displayName` (plate first). On a personal bike the plate is often a
     *   placeholder — Rajab's own bike is registered "APPLIED-FOR" — and a manager reading
     *   "start 7,610 on APPLIED-FOR" sees a data error rather than "his own bike". So a
     *   NON-company machine shows its nickname ("Rajab Masood - own bike") and a company machine
     *   shows its plate ("CAD-2958"), each falling back to the other, then to "Vehicle #id".
     *
     * @param object|array|null $v a t_ops_vehicle row (needs id, reg_no, nickname, is_company)
     */
    public static function labelOf($v): ?string
    {
        $v = self::obj($v);
        if (!$v) return null;

        $reg  = trim((string) ($v->reg_no ?? ''));
        $nick = trim((string) ($v->nickname ?? ''));
        $own  = (int) ($v->is_company ?? 1) === 0;

        if ($own) {
            if ($nick !== '') return $nick;
            if ($reg !== '')  return $reg;
        } else {
            if ($reg !== '')  return $reg;
            if ($nick !== '') return $nick;
        }

        return 'Vehicle #' . ($v->id ?? '?');
    }

    /**
     * Labels for a set of vehicle ids, in ONE query — `[id => label]`.
     * Unknown / unreadable ids simply do not appear, so callers must null-coalesce.
     */
    public static function labels(array $vehicleIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $vehicleIds))));
        if (!$ids) return [];

        try {
            $out = [];
            foreach (DB::table(VehicleService::T_VEHICLE)->whereIn('id', $ids)
                        ->get(['id', 'reg_no', 'nickname', 'is_company', 'vtype']) as $v) {
                $out[(int) $v->id] = self::labelOf($v);
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('MeterPairHelper::labels failed (non-fatal)', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * The `meter_machines` block a row carries to the UI — the SAME shape the attendance page
     * has rendered since 10-Sep, kept here so the modal, the row and the monthly report build
     * it identically.
     *
     * @param  array $labels  optional pre-fetched [id => label] (the page batches them)
     * @return array|null     null when there is nothing to say (no stamps at all)
     */
    public static function machines($att, array $labels = []): ?array
    {
        $a = self::obj($att);
        if (!$a) return null;

        try {
            if (!VehicleService::stampsAvailable()) return null;
        } catch (\Throwable $e) {
            return null;
        }

        $sv = self::num($a->meter_start ?? null) !== null ? self::vid($a->meter_start_vehicle_id ?? null) : null;
        $ev = self::num($a->meter_end   ?? null) !== null ? self::vid($a->meter_end_vehicle_id   ?? null) : null;
        if (!$sv && !$ev) return null;

        if (!$labels) {
            $labels = self::labels([$sv, $ev]);
        }

        return [
            'start_id'    => $sv,
            'start_label' => $sv ? ($labels[$sv] ?? null) : null,
            'end_id'      => $ev,
            'end_label'   => $ev ? ($labels[$ev] ?? null) : null,
            // ⚠ TRUE only when BOTH are stamped and they differ — see isSplit().
            'split'       => ($sv && $ev && $sv !== $ev),
        ];
    }

    // ── tiny coercions, so every caller can pass a row, an array or null ──────────────
    private static function obj($r)
    {
        if ($r === null) return null;
        if (is_array($r)) return (object) $r;
        return is_object($r) ? $r : null;
    }

    /** A reading counts only when it is a real, positive number (0 / '' / null = absent). */
    private static function num($v): ?int
    {
        if ($v === null || $v === '') return null;
        if (!is_numeric($v)) return null;
        $n = (int) $v;
        return $n > 0 ? $n : null;
    }

    private static function vid($v): ?int
    {
        if ($v === null || $v === '') return null;
        $n = (int) $v;
        return $n > 0 ? $n : null;
    }
}
