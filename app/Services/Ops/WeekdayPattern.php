<?php

namespace App\Services\Ops;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🔁 WEEKLY DAYS — "different on some days" (owner ask, 12-Oct-2026).
 *
 * Taimur's example: Haider is at LaCarne 09:30 (Orchard Lacarne) on Mon / Wed / Thu and on
 * Manager Shift 11:00 at the office every other day. Before this he typed a one-day cover
 * for every LaCarne day — 182 of them since August.
 *
 * ⭐⭐ THE ONE DESIGN DECISION. The weekly days ride ON the shift row they belong to
 *    (`t_ops_user_shift_assignment.day_pattern`), never in a table of their own. So:
 *      · a regular shift + its weekly days are ONE thing, closed / replaced / cancelled /
 *        ended together by the engine that already does it;
 *      · a past month keeps the weekly days that were in force then (the row is frozen);
 *      · the rider confirms it the way he confirms any change (assignment_id);
 *      · the resolver needs no new ordering rule — the row that wins a date today still
 *        wins it, and only then is its weekday entry applied;
 *      · a one-day cover is its own (later) row, so it still beats the weekly day.
 *
 * Stored / wire format (the SAME on both sides, so the phone and the planner prefill from
 * exactly what is saved):
 *     {"1":{"template_id":9,"location_id":8},"2":"off","3":{"template_id":9,"location_id":8}}
 *   key   = ISO weekday (1 = Mon … 7 = Sun)
 *   value = "off"  →  a day off that week day
 *         | {template_id, location_id|null} → that shift at that place (a WORKING day, even
 *           if that shift type's own days say off — owner ruling R2)
 *   a weekday that is ABSENT = "same as the row" (the shift picked above, its own off days).
 *
 * ⚠ Everything here is pure or read-only. Writing is the engine's job (ShiftController).
 */
final class WeekdayPattern
{
    public const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    /** Request key on every assign door (web planner, phone, approval replay). */
    public const INPUT = 'day_pattern';

    private static ?bool $hasColumn = null;
    private static ?bool $hasRequestColumn = null;

    /** Is `t_ops_user_shift_assignment.day_pattern` there yet? (deploy-order safe) */
    public static function available(): bool
    {
        if (self::$hasColumn === null) {
            try {
                self::$hasColumn = Schema::hasColumn('t_ops_user_shift_assignment', 'day_pattern');
            } catch (\Throwable $e) { self::$hasColumn = false; }
        }
        return self::$hasColumn;
    }

    /** Is `t_ops_shift_change_request.day_pattern` there yet? */
    public static function requestColumnAvailable(): bool
    {
        if (self::$hasRequestColumn === null) {
            try {
                self::$hasRequestColumn = Schema::hasColumn('t_ops_shift_change_request', 'day_pattern');
            } catch (\Throwable $e) { self::$hasRequestColumn = false; }
        }
        return self::$hasRequestColumn;
    }

    /** Test seam only — a harness that adds/drops the column inside one process. */
    public static function forgetSchema(): void
    {
        self::$hasColumn = null;
        self::$hasRequestColumn = null;
        self::$names = null;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Reading
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Stored / wire value → normalised map, or NULL when there are no weekly days.
     *   [dow => ['off' => true]  |  ['template_id' => int, 'location_id' => ?int]]
     * Anything malformed is DROPPED, never guessed: a bad entry means "same as the row".
     */
    public static function parse($raw): ?array
    {
        if ($raw === null || $raw === '' || $raw === []) return null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) return null;
        // Tolerate a wrapper {"days":{…}} — the shape is simple enough to accept both.
        if (isset($raw['days']) && is_array($raw['days'])) $raw = $raw['days'];

        $out = [];
        foreach ($raw as $k => $v) {
            $dow = (int) $k;
            if ($dow < 1 || $dow > 7 || (string) $dow !== (string) $k) continue;
            if ($v === 'off' || (is_array($v) && (($v['kind'] ?? null) === 'off' || !empty($v['off'])))) {
                $out[$dow] = ['off' => true];
                continue;
            }
            if (is_array($v)) {
                $t = (int) ($v['template_id'] ?? $v['t'] ?? 0);
                if ($t <= 0) continue;                      // "same as above"
                $l = $v['location_id'] ?? $v['l'] ?? null;
                $l = ($l === null || $l === '' || (int) $l <= 0) ? null : (int) $l;
                $out[$dow] = ['template_id' => $t, 'location_id' => $l];
            }
        }
        if (!$out) return null;
        ksort($out);
        return $out;
    }

    /** Normalised map → stored string (deterministic, so equality is a string compare). */
    public static function encode(?array $map): ?string
    {
        if (!$map) return null;
        ksort($map);
        $o = [];
        foreach ($map as $dow => $e) {
            $o[(string) $dow] = !empty($e['off'])
                ? 'off'
                : ['template_id' => (int) $e['template_id'],
                   'location_id' => $e['location_id'] !== null ? (int) $e['location_id'] : null];
        }
        return json_encode($o, JSON_UNESCAPED_UNICODE);
    }

    /** The same map as the wire object a screen can prefill from (null when none). */
    public static function toWire(?array $map): ?array
    {
        $s = self::encode($map);
        return $s === null ? null : json_decode($s, true);
    }

    /**
     * The working days of a row with weekly days on it.
     * ⭐ R2 (owner, 12-Oct): an entry with a shift = WORKING that day; "off" = off;
     *   no entry = the row's own shift type decides, exactly as it does today.
     */
    public static function workingDays(array $baseDays, ?array $map): array
    {
        $base = array_map('intval', $baseDays);
        if (!$map) return $base;
        $out = [];
        for ($d = 1; $d <= 7; $d++) {
            $e = $map[$d] ?? null;
            if ($e === null) {
                if (in_array($d, $base, true)) $out[] = $d;
            } elseif (empty($e['off'])) {
                $out[] = $d;
            }
        }
        return $out;
    }

    /** Every shift type a map points at (for the gate and the template-delete guard). */
    public static function templateIds(?array $map): array
    {
        if (!$map) return [];
        $ids = [];
        foreach ($map as $e) {
            if (empty($e['off'])) $ids[] = (int) $e['template_id'];
        }
        return array_values(array_unique($ids));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  The request
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Read and CHECK the weekly days a screen sent.
     *
     * @return array{given:bool, map:?array, error:?string}
     *   given = false → the screen did not send the key at all (an older APK, the Shift
     *           Types page, the Attendance popup). ⭐ A screen that cannot SHOW weekly days
     *           never removes them: on a regular change the engine carries them over.
     *   given = true, map = null → the screen showed them and the planner cleared them.
     */
    public static function fromRequest(Request $request, int $baseTemplateId, ?int $baseLocationId, ?array $keep = null): array
    {
        if (!$request->exists(self::INPUT)) {
            return ['given' => false, 'map' => null, 'error' => null];
        }
        $raw = $request->input(self::INPUT);
        if (is_string($raw) && trim($raw) !== '' && json_decode($raw, true) === null && trim($raw) !== 'null') {
            return ['given' => true, 'map' => null, 'error' => 'The weekly days could not be read. Please open the form again.'];
        }
        $map = self::parse($raw);
        if (!$map) return ['given' => true, 'map' => null, 'error' => null];

        $baseDays = [];
        try {
            $wd = DB::table('t_ops_shift_template')->where('id', $baseTemplateId)->value('working_days');
            $baseDays = array_map('intval', is_string($wd) ? (json_decode($wd, true) ?: []) : []);
        } catch (\Throwable $e) { $baseDays = [1, 2, 3, 4, 5, 6, 7]; }

        // A weekday that says exactly what the row already says is not a different day —
        // drop it, so "nothing changed" compares equal and the no-op guard keeps working.
        // ⚠ Only when the row's own shift WORKS that day. "Manager Shift on Tuesday" where
        //   Manager Shift has Tuesday off is a real instruction (work this Tuesday) and stays.
        //   The same goes for "off" on a day the row is already off.
        foreach ($map as $dow => $e) {
            $baseWorks = in_array($dow, $baseDays, true);
            if (empty($e['off'])) {
                if ($baseWorks && (int) $e['template_id'] === $baseTemplateId
                    && ($e['location_id'] ?? null) === $baseLocationId) {
                    unset($map[$dow]);
                }
            } elseif (!$baseWorks) {
                unset($map[$dow]);
            }
        }
        if (!$map) return ['given' => true, 'map' => null, 'error' => null];

        // Every shift type must be one that may be assigned today.
        $ids = self::templateIds($map);
        if ($ids) {
            try {
                $q = DB::table('t_ops_shift_template')->whereIn('id', $ids)->where('active', 1);
                if (Schema::hasColumn('t_ops_shift_template', 'approval_status')) {
                    $q->where(function ($w) { $w->where('approval_status', 'approved')->orWhereNull('approval_status'); });
                }
                $ok = $q->pluck('id')->map(fn ($x) => (int) $x)->all();
            } catch (\Throwable $e) { $ok = $ids; }
            foreach ($map as $dow => $e) {
                if (empty($e['off']) && !in_array((int) $e['template_id'], $ok, true)) {
                    // Unchanged from what he already has on that weekday → allowed to stay.
                    $had = $keep[$dow] ?? null;
                    if ($had && empty($had['off']) && (int) $had['template_id'] === (int) $e['template_id']
                        && ($had['location_id'] ?? null) === ($e['location_id'] ?? null)) {
                        continue;
                    }
                    return ['given' => true, 'map' => null,
                            'error' => 'The shift chosen for ' . self::DAYS[$dow] . ' is not available any more. Pick another one.'];
                }
            }
        }

        // Every place must be a real, active OFFICE — never a van meet-up point or a
        // workshop (the check-in rules honour an assigned location; same rule as withLocation()).
        foreach ($map as $dow => $e) {
            $l = $e['location_id'] ?? null;
            if (!$l) continue;
            $active = false;
            try {
                $active = DB::table('t_ops_company_locations')->where('id', $l)->where('is_active', 1)->exists();
            } catch (\Throwable $ex) { $active = true; }
            if (!$active || !\App\Services\LocationService::isAssignableOffice($l)) {
                return ['given' => true, 'map' => null,
                        'error' => 'The place chosen for ' . self::DAYS[$dow] . ' cannot be a work location. Pick an office.'];
            }
        }

        // At least one working day must be left in the week.
        if (!self::workingDays($baseDays, $map)) {
            return ['given' => true, 'map' => null,
                    'error' => 'Every day of the week is off. Leave at least one working day.'];
        }

        ksort($map);
        return ['given' => true, 'map' => $map, 'error' => null];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Words
    // ─────────────────────────────────────────────────────────────────────────────

    /** In-request cache of template / location names (a summary per grid row). */
    private static ?array $names = null;

    private static function names(): array
    {
        if (self::$names === null) {
            self::$names = ['t' => [], 'l' => []];
            try {
                foreach (DB::table('t_ops_shift_template')->get(['id', 'shift_name', 'shift_start', 'shift_end']) as $t) {
                    self::$names['t'][(int) $t->id] = $t;
                }
                foreach (DB::table('t_ops_company_locations')->get(['id', 'location_name']) as $l) {
                    self::$names['l'][(int) $l->id] = $l->location_name;
                }
            } catch (\Throwable $e) { /* words are best-effort */ }
        }
        return self::$names;
    }

    /** Drop the name cache (a template was just created / renamed in this request). */
    public static function forgetNames(): void { self::$names = null; }

    /**
     * One line, e.g. "Mon, Wed, Thu: LaCarne 09:30 @ Orchard Lacarne · Tue: off".
     * Days that say the same thing are grouped, in week order. Single line on purpose — it
     * goes into a WhatsApp template variable, which may not contain a newline.
     *
     * @param string $lang 'en' (planner screens) | 'ur' (what the RIDER reads — "chutti")
     */
    public static function summary(?array $map, string $lang = 'en'): string
    {
        if (!$map) return '';
        $n = self::names();
        $groups = [];   // label => [day names]
        foreach (self::DAYS as $dow => $dn) {
            $e = $map[$dow] ?? null;
            if ($e === null) continue;
            if (!empty($e['off'])) {
                $label = $lang === 'ur' ? 'chutti' : 'off';
            } else {
                $t = $n['t'][(int) $e['template_id']] ?? null;
                $label = $t
                    ? ($t->shift_name . ' ' . substr((string) $t->shift_start, 0, 5)
                        . ($t->shift_end ? '–' . substr((string) $t->shift_end, 0, 5) : ''))
                    : 'shift #' . (int) $e['template_id'];
                $l = $e['location_id'] ?? null;
                if ($l && isset($n['l'][(int) $l])) $label .= ' @ ' . $n['l'][(int) $l];
            }
            $groups[$label][] = $dn;
        }
        $parts = [];
        foreach ($groups as $label => $days) {
            $parts[] = implode(', ', $days) . ': ' . $label;
        }
        return implode(' · ', $parts);
    }
}
