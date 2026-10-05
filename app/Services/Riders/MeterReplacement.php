<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐ "THIS MACHINE'S ODOMETER WAS REPLACED" (5-Oct-2026).
 *
 * Every odometer rule in the fleet code assumes a machine's reading only ever goes UP. A new
 * meter breaks that: Rajab's own bike read 9,133 on 1-Oct and 1 → 147 on 2-Oct, and from then
 * on its readings were "implausible", its days "unusable", its current km frozen at 9,133 and
 * any typed reading "lower than this bike's 9,133". On a COMPANY bike the same event would
 * refuse every service record and stall the workshop close.
 *
 * THE MODEL — one continuous distance.
 *   A row in `t_ops_vehicle_meter_reset` says: on `reset_date` the old meter last read
 *   `old_reading` and the new one read `new_reading`. A reading taken on the NEW meter is
 *   `raw + (old_reading - new_reading)` on the machine's continuous scale; earlier readings
 *   are already on it. Engines do their arithmetic on the continuous scale (so MAX, floors,
 *   ceilings, day spans, fill chains and service countdowns all keep working untouched) and
 *   convert back to "what the meter on the bike shows" only when a number is shown to a
 *   person or compared with a number a person typed.
 *
 * ⭐ THE SAFETY PROPERTY: a machine with NO row has offset 0 everywhere — `toContinuous()` and
 *   `toRaw()` are the identity and `sql()` returns the bare column — so nothing changes for it,
 *   by construction. The risk of this feature is confined to machines someone has recorded a
 *   replacement on.
 *
 * ⚠ THE REPLACEMENT DAY ITSELF carries readings from BOTH meters (in at 21,400 on the old one,
 *   out at 35 on the new one). A reading on that day belongs to whichever meter's hand-over
 *   figure it is nearer to; a reading with no value to judge is taken as the new meter.
 */
class MeterReplacement
{
    public const TABLE = 't_ops_vehicle_meter_reset';

    /** [vehicleId => [['id','d','old','new','k'], …] oldest first] — the whole (tiny) table. */
    private static ?array $rows = null;
    private static ?bool $hasTable = null;

    public static function flush(): void
    {
        self::$rows = null;
    }

    public static function available(): bool
    {
        if (self::$hasTable === null) {
            try { self::$hasTable = Schema::hasTable(self::TABLE); }
            catch (\Throwable $e) { self::$hasTable = false; }
        }
        return self::$hasTable;
    }

    /** Every replacement on record for one machine, oldest first. */
    public static function rowsFor(int $vehicleId): array
    {
        if (self::$rows === null) {
            $all = [];
            if (self::available()) {
                try {
                    foreach (DB::table(self::TABLE)->orderBy('reset_date')->orderBy('id')
                                ->get(['id', 'vehicle_id', 'reset_date', 'old_reading', 'new_reading', 'note', 'entered_by']) as $r) {
                        $all[(int) $r->vehicle_id][] = [
                            'id'   => (int) $r->id,
                            'd'    => substr((string) $r->reset_date, 0, 10),
                            'old'  => (int) $r->old_reading,
                            'new'  => (int) $r->new_reading,
                            'k'    => (int) $r->old_reading - (int) $r->new_reading,
                            'note' => $r->note,
                            'entered_by' => $r->entered_by !== null ? (int) $r->entered_by : null,
                        ];
                    }
                } catch (\Throwable $e) { $all = []; }
            }
            self::$rows = $all;
        }
        return self::$rows[$vehicleId] ?? [];
    }

    public static function has(?int $vehicleId): bool
    {
        return $vehicleId !== null && $vehicleId > 0 && (bool) self::rowsFor($vehicleId);
    }

    /** Is there ANY replacement on record, on any machine? The fast exit for rider-keyed code. */
    public static function any(): bool
    {
        self::rowsFor(0);
        return (bool) self::$rows;
    }

    /**
     * ⭐ A RIDER'S ATTENDANCE ROW, with its readings on the continuous scale of the machine each
     *   one was taken on — for the rider-keyed screens that judge a day from the row alone
     *   ("is 161 → 340 a sane day?", "how far since last night?") and have no machine in hand.
     *
     * The machine is the reading's own stamp, else the manager's day override, else whoever
     * the registry says he was riding that day. Returns the SAME object when no reading on it
     * belongs to a replaced machine — which is every row in the fleet until a replacement is
     * recorded — and a lifted COPY otherwise, so the original is still there to show.
     */
    public static function liftRow(object $row, ?int $userId = null, ?string $date = null): object
    {
        if (!self::any()) return $row;
        try {
            $uid = $userId ?? (isset($row->user_id) ? (int) $row->user_id : null);
            $d   = $date ?? (isset($row->attendance_date) ? substr((string) $row->attendance_date, 0, 10) : null);
            if ($d === null) return $row;

            $dayVid = null; $asked = false;
            $out = null;
            foreach (['meter_start', 'meter_end', 'meter_home'] as $col) {
                $val = $row->$col ?? null;
                if ($val === null || (int) $val <= 0) continue;
                $vid = !empty($row->{$col . '_vehicle_id'}) ? (int) $row->{$col . '_vehicle_id'}
                     : (!empty($row->vehicle_id) ? (int) $row->vehicle_id : null);
                if ($vid === null) {
                    if (!$asked && $uid) {
                        $dayVid = (new VehicleResolver())->vehicleForDay($uid, $d);
                        $asked = true;
                    }
                    $vid = $dayVid ? (int) $dayVid : null;
                }
                if (!self::has($vid)) continue;
                $lifted = self::toContinuous($vid, (int) $val, $d);
                if ($lifted === (int) $val) continue;
                $out = $out ?? clone $row;
                $out->$col = $lifted;
            }
            return $out ?? $row;
        } catch (\Throwable $e) {
            return $row;
        }
    }

    /**
     * What to ADD to a raw reading taken on `$date` to put it on the continuous scale.
     * `$date` null = "now" (every replacement applies). `$raw` only matters on a replacement
     * day, to tell which of the two meters the reading came off.
     */
    public static function offset(int $vehicleId, ?string $date, ?int $raw = null): int
    {
        $k = 0;
        $d = $date !== null ? substr($date, 0, 10) : null;
        foreach (self::rowsFor($vehicleId) as $r) {
            if ($d === null || $d > $r['d']) { $k += $r['k']; continue; }
            if ($d === $r['d'] && ($raw === null || abs($raw - $r['new']) < abs($raw - $r['old']))) {
                $k += $r['k'];
            }
        }
        return $k;
    }

    /** Raw reading (as the meter showed it on `$date`) → the machine's continuous distance. */
    public static function toContinuous(?int $vehicleId, ?int $raw, ?string $date): ?int
    {
        if ($raw === null || $raw <= 0 || !self::has($vehicleId)) return $raw;
        return $raw + self::offset((int) $vehicleId, $date, $raw);
    }

    /**
     * Continuous distance → what the meter fitted on `$date` would show. On a replacement day
     * it answers in the scale the VALUE itself falls in (at or below the old meter's last
     * reading = still the old meter), so a floor taken from that morning reads as it was typed.
     */
    public static function toRaw(?int $vehicleId, ?int $cont, ?string $date): ?int
    {
        if ($cont === null || !self::has($vehicleId)) return $cont;
        $k = 0; $base = 0;      // $base = continuous value at which each new meter took over
        $d = $date !== null ? substr($date, 0, 10) : null;
        foreach (self::rowsFor((int) $vehicleId) as $r) {
            $takeover = $r['old'] + $base;                     // continuous reading at the swap
            if ($d === null || $d > $r['d'] || ($d === $r['d'] && $cont > $takeover)) {
                $k += $r['k'];
                $base = $k;
            }
        }
        return $cont - $k;
    }

    /**
     * One rider-keyed reading (no row to hand) on the continuous scale of the machine the
     * registry says he was riding that day. The value itself when nothing has been replaced.
     */
    public static function liftValue(int $userId, ?int $raw, ?string $date): ?int
    {
        if ($raw === null || $raw <= 0 || $date === null || !self::any()) return $raw;
        try {
            $vid = (new VehicleResolver())->vehicleForDay($userId, substr($date, 0, 10));
            return $vid ? self::toContinuous((int) $vid, $raw, $date) : $raw;
        } catch (\Throwable $e) {
            return $raw;
        }
    }

    /**
     * Continuous distance → the figure AS IT WAS TYPED, decided by the value alone: distance
     * only ever grows, so a continuous value at or below the point a new meter took over was
     * read off the old one. For showing a reading whose own date is not at hand (a stretch
     * that started before a replacement and ended after it).
     */
    public static function toRawByValue(?int $vehicleId, ?int $cont): ?int
    {
        if ($cont === null || !self::has($vehicleId)) return $cont;
        $k = 0;
        foreach (self::rowsFor((int) $vehicleId) as $r) {
            if ($cont > $r['old'] + $k) $k += $r['k'];
            else break;
        }
        return $cont - $k;
    }

    /**
     * SQL for "this column on the continuous scale". Returns the bare column for a machine
     * with no replacement, so every existing query is byte-identical for it.
     * NULL and 0 ("no reading") pass through untouched — callers test `col > 0`.
     */
    public static function sql(?int $vehicleId, string $col, string $dateExpr): string
    {
        if (!self::has($vehicleId)) return $col;
        $parts = [];
        foreach (self::rowsFor((int) $vehicleId) as $r) {
            if ($r['k'] === 0) continue;
            $d = "'" . $r['d'] . "'";
            $parts[] = 'CASE WHEN ' . $dateExpr . ' > ' . $d
                . ' OR (' . $dateExpr . ' = ' . $d . ' AND ABS(' . $col . ' - ' . $r['new'] . ') < ABS(' . $col . ' - ' . $r['old'] . '))'
                . ' THEN ' . $r['k'] . ' ELSE 0 END';
        }
        if (!$parts) return $col;
        return '(CASE WHEN ' . $col . ' IS NULL OR ' . $col . ' <= 0 THEN ' . $col
            . ' ELSE ' . $col . ' + ' . implode(' + ', $parts) . ' END)';
    }

    /** The latest replacement on or before `$date` (null date = the latest of all), or null. */
    public static function latest(int $vehicleId, ?string $date = null): ?array
    {
        $hit = null;
        $d = $date !== null ? substr($date, 0, 10) : null;
        foreach (self::rowsFor($vehicleId) as $r) {
            if ($d === null || $r['d'] <= $d) $hit = $r;
        }
        return $hit;
    }
}
