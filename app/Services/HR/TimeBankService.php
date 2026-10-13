<?php

namespace App\Services\HR;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⏱ TIME BANK — a month's LATE minutes and OVERTIME ("extra") minutes as ONE balance.
 * Plan: TIME-BANK-LATE-OT-PLAN-OCT11-2026.md (workspace root). Owner rulings Oct-11/12 2026.
 *
 *   L = late carried in + this month's late − minutes waived on the card
 *   O = extra carried in + this month's extra
 *   (offered, not automatic) extra covers late at OT_LATE_OFFSET_FACTOR (default 0.5:
 *   9h extra covers 4.5h late)
 *   whole 9-hour days:  late  → take leave / cut salary (1 day's pay) / move to next month
 *                       extra → give bonus leave / move to next month / skip (forfeit)
 *   anything under 9 hours ALWAYS moves to next month.
 *
 * ⭐⭐ ONE ENGINE. Payroll, attendance, reports, the phone (manager AND rider) all read
 * month() / monthAll(); every write goes through decide() / undo() / adjust(). No other code
 * may divide by 540 or multiply by the factor.
 *
 * SHAPE (same discipline as OvertimeCarryService): one FROZEN row per decided user-month in
 * t_hr_time_bank_month, carrying the lots it passed on (`*_lots_out`). Whatever is not a
 * month's own minutes — a manual adjustment, a returned cut, a day changed after its month was
 * decided ("drift") — is a row in t_hr_time_bank_adjust until a decision absorbs it.
 *
 * NOTHING BLOCKS: an undecided month is folded into the next decision ("from Sep, not
 * decided"); a day changed after its month was decided counts in the next open month.
 *
 * START (R9): September 2026 for people with NO September leave action under the old rules,
 * otherwise October — and minutes nobody acted on (extra from Aug, late from Sep) are still
 * offered here as opening lots. Months before a person's start stay with the old screens.
 *
 * ⚠ Schema-guarded: before time_bank_oct2026.sql runs, enabled() is false and payroll keeps
 * the old rules exactly.
 */
class TimeBankService
{
    /**
     * ⭐ CUT-OVER (owner, Oct-12 2026): from 1 September 2026 EVERY employee's late and extra go
     * into the bank — for everyone, whether or not a salary was paid. Paying a salary is never a
     * leave decision. The only thing the old rules keep is a leave decision they actually made
     * (a "+N bonus" / "−1 late" grant row): that KIND is settled for that month and the bank
     * takes nothing from it. Minutes from before September are not counted (add by hand).
     */
    public const START_MONTH = '2026-09';
    /** How far back a decided month is re-checked for drift. */
    private const DRIFT_MONTHS = 3;

    private static ?bool $enabledMemo = null;
    private static array $rowsMemo = [];
    private static array $adjMemo = [];
    private static array $ownMemo = [];
    private static array $stmtMemo = [];
    private static ?array $customMemo = null;

    // =====================================================================
    //  SMALL HELPERS
    // =====================================================================

    public function enabled(): bool
    {
        if (self::$enabledMemo === null) {
            try {
                self::$enabledMemo = Schema::hasTable('t_hr_time_bank_month')
                    && Schema::hasTable('t_hr_time_bank_adjust');
            } catch (\Throwable $e) {
                self::$enabledMemo = false;
            }
        }
        return self::$enabledMemo;
    }

    /** Drop every per-request cache (after any write, or an attendance edit). */
    public static function forget(): void
    {
        self::$rowsMemo = [];
        self::$adjMemo = [];
        self::$ownMemo = [];
        self::$stmtMemo = [];
        self::$customMemo = null;
    }

    /** How many late minutes ONE extra minute covers (R7). 0.1–1, default 0.5. */
    public function factor(): float
    {
        try {
            $v = ConfigMemo::get('OT_LATE_OFFSET_FACTOR');
        } catch (\Throwable $e) {
            $v = null;
        }
        $f = ($v === null || $v === '' || !is_numeric($v)) ? 0.5 : (float) $v;
        return max(0.1, min(1.0, round($f, 2)));
    }

    /** Minutes in one day — the ONE setting (SHIFT_TARGET_HOURS), R1. */
    public function perDay(): int
    {
        return max(60, (new OvertimeService())->minutesPerBonusDay());
    }

    public static function hm(int $minutes): string
    {
        $neg = $minutes < 0;
        $m = abs($minutes);
        $h = intdiv($m, 60);
        $s = $h > 0 ? ($h . 'h ' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT) . 'm') : ($m . 'm');
        return ($neg ? '−' : '') . $s;
    }

    private static function mon(string $month): string
    {
        return date('M', strtotime($month . '-01'));
    }

    private static function monthName(string $month): string
    {
        return date('F Y', strtotime($month . '-01'));
    }

    private static function nextMonth(string $month): string
    {
        return date('Y-m', strtotime($month . '-01 +1 month'));
    }

    private static function prevMonth(string $month, int $n = 1): string
    {
        return date('Y-m', strtotime($month . "-01 -{$n} month"));
    }

    private static function leaves(int $n): string
    {
        return $n . ' leave' . ($n === 1 ? '' : 's');
    }

    private static function chhutti(int $n): string
    {
        return $n . ' chhutti' . ($n === 1 ? '' : 'yan');
    }

    public function currentMonth(): string
    {
        return date('Y-m');
    }

    public function isClosed(string $month): bool
    {
        return $month < $this->currentMonth();
    }

    public function isExcluded(int $userId): bool
    {
        if (self::$customMemo === null) {
            try {
                self::$customMemo = (new PayrollService())->customScheduleUserIds();
            } catch (\Throwable $e) {
                self::$customMemo = [];
            }
        }
        return isset(self::$customMemo[$userId]);
    }

    // =====================================================================
    //  STORED ROWS
    // =====================================================================

    /** month => decoded row, oldest first. */
    private function rows(int $userId): array
    {
        if (!$this->enabled()) { return []; }
        if (!isset(self::$rowsMemo[$userId])) {
            $out = [];
            try {
                foreach (DB::table('t_hr_time_bank_month')->where('user_id', $userId)->orderBy('month')->get() as $r) {
                    $a = (array) $r;
                    foreach (['folded_months', 'late_lots_in', 'ot_lots_in', 'late_lots_out', 'ot_lots_out'] as $k) {
                        $a[$k] = $a[$k] ? (json_decode((string) $a[$k], true) ?: []) : [];
                    }
                    $out[(string) $r->month] = $a;
                }
            } catch (\Throwable $e) { $out = []; }
            self::$rowsMemo[$userId] = $out;
        }
        return self::$rowsMemo[$userId];
    }

    /** All adjust rows for a user (open and absorbed). */
    private function adjustRows(int $userId): array
    {
        if (!$this->enabled()) { return []; }
        if (!isset(self::$adjMemo[$userId])) {
            try {
                self::$adjMemo[$userId] = DB::table('t_hr_time_bank_adjust as a')
                    ->leftJoin('t_sys_user as u', 'u.id', '=', 'a.created_by')
                    ->where('a.user_id', $userId)->orderBy('a.id')
                    ->get(['a.*', 'u.fullname as by_name'])
                    ->map(fn ($r) => (array) $r)->all();
            } catch (\Throwable $e) {
                self::$adjMemo[$userId] = [];
            }
        }
        return self::$adjMemo[$userId];
    }

    // =====================================================================
    //  RAW MINUTES (the only two sources — both already one-engine)
    // =====================================================================

    /** [late, ot] minutes a month produced. Late = net of per-day waives (lateForDay). */
    public function ownMinutes(int $userId, string $month): array
    {
        $k = $userId . '|' . $month;
        if (!isset(self::$ownMemo[$k])) {
            if ($month > $this->currentMonth()) {
                return self::$ownMemo[$k] = ['late' => 0, 'ot' => 0];
            }
            $late = 0; $ot = 0;
            try {
                $late = (int) ((new SalaryCalculationService())->attendanceSummary($userId, $month)['late_minutes'] ?? 0);
            } catch (\Throwable $e) { $late = 0; }
            try {
                $start = $month . '-01';
                $end = min(date('Y-m-t', strtotime($start)), date('Y-m-d'));
                $ot = (int) (new OvertimeService())->overtimeMinutes($userId, $start, $end);
            } catch (\Throwable $e) { $ot = 0; }
            self::$ownMemo[$k] = ['late' => max(0, $late), 'ot' => max(0, $ot)];
        }
        return self::$ownMemo[$k];
    }

    // =====================================================================
    //  START + LEGACY (old-rule months)
    // =====================================================================

    private function legacyReason(string $kind, string $month): string
    {
        return ($kind === 'overtime' ? 'Overtime bonus ' : 'Late penalty ') . date('M Y', strtotime($month . '-01'));
    }

    /** This month's overtime was turned into leave the OLD way (a panel/pay decision + carry row). */
    public function legacyOtActed(int $userId, string $month): bool
    {
        try {
            if ((new OvertimeCarryService())->decided($userId, $month)) { return true; }
            return DB::table('t_hr_leave_grant')->where('user_id', $userId)->where('source', 'overtime')
                ->whereDate('effective_date', $month . '-01')
                ->where('reason', $this->legacyReason('overtime', $month))->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** This month's lateness was settled the OLD way (a −1 leave / waive row). A payment never counts. */
    public function legacyLateActed(int $userId, string $month): bool
    {
        try {
            return DB::table('t_hr_leave_grant')->where('user_id', $userId)->where('source', 'late_penalty')
                ->whereDate('effective_date', $month . '-01')
                ->where('reason', $this->legacyReason('late_penalty', $month))->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** What the old rule already did for a bank month — shown on the card so nothing looks lost. */
    public function legacyNotes(int $userId, string $month): array
    {
        $out = [];
        if ($month < self::START_MONTH) { return $out; }
        try {
            $rows = DB::table('t_hr_leave_grant as g')->leftJoin('t_sys_user as u', 'u.id', '=', 'g.created_by')
                ->where('g.user_id', $userId)->whereDate('g.effective_date', $month . '-01')
                ->whereIn('g.source', ['overtime', 'late_penalty'])
                ->whereIn('g.reason', [$this->legacyReason('overtime', $month), $this->legacyReason('late_penalty', $month)])
                ->get(['g.days', 'g.source', 'g.created_at', 'u.fullname']);
            foreach ($rows as $g) {
                $d = (float) $g->days;
                $who = $g->fullname ? ' (' . $g->fullname . ($g->created_at ? ', ' . date('j M', strtotime((string) $g->created_at)) : '') . ')' : '';
                $out[] = $g->source === 'overtime'
                    ? ($d > 0 ? 'Extra already settled the old way: +' . self::leaves((int) $d) . ' given' . $who
                              : 'Extra already settled the old way: skipped' . $who)
                    : ($d < 0 ? 'Late already settled the old way: ' . self::leaves((int) abs($d)) . ' taken' . $who
                              : 'Late already settled the old way: waived' . $who);
            }
        } catch (\Throwable $e) { /* none */ }
        return $out;
    }

    /** The bank starts 1 September 2026 for everyone (kept as a method — many callers). */
    public function startMonth(int $userId): string
    {
        return self::START_MONTH;
    }

    /** Is this month judged by the bank for this person? */
    public function covers(int $userId, string $month): bool
    {
        return $this->enabled() && !$this->isExcluded($userId) && $month >= self::START_MONTH;
    }

    /**
     * The minutes the BANK counts for a month: this month's own, minus any kind the old rule
     * already turned into leave (that kind reads 0 — the leave was already taken / given).
     */
    public function bankOwn(int $userId, string $month): array
    {
        $own = $this->ownMinutes($userId, $month);
        if ($month < self::START_MONTH) { return $own; }
        if ($own['late'] > 0 && $this->legacyLateActed($userId, $month)) { $own['late'] = 0; }
        if ($own['ot'] > 0 && $this->legacyOtActed($userId, $month)) { $own['ot'] = 0; }
        return $own;
    }

    /**
     * Overtime minutes the OLD carry engine still holds for this person, and the bank month they
     * enter: the survivors of every old-rule carry row (before September, plus any September-on
     * month the old panel decided before the switch), tagged 'legacy'. Replayed by
     * OvertimeCarryService itself, so the figure is exactly what that screen promised.
     *
     * @return array{month:string,lots:array}
     */
    private function legacyOtInjection(int $userId): array
    {
        $carry = new OvertimeCarryService();
        $last = null;
        for ($m = self::START_MONTH; $m <= $this->currentMonth(); $m = self::nextMonth($m)) {
            if (!$carry->decided($userId, $m)) { break; }
            $last = $m;
        }
        $at = $last ?? self::START_MONTH;
        $before = $last ? self::nextMonth($last) : self::START_MONTH;
        $lots = [];
        try {
            foreach ($carry->openLotsBefore($userId, $before) as $l) {
                if ((int) $l['minutes'] > 0) {
                    $lots[] = ['from' => $l['earned_month'], 'minutes' => (int) $l['minutes'], 'src' => 'legacy'];
                }
            }
        } catch (\Throwable $e) { /* none */ }
        return ['month' => $at, 'lots' => $lots];
    }

    // =====================================================================
    //  LOTS INTO A MONTH
    // =====================================================================    // =====================================================================
    //  LOTS INTO A MONTH
    // =====================================================================

    /**
     * Everything carried INTO $month: the previous decision's lots out (or the opening lots),
     * every undecided month in between (folded), open adjustments, and drift.
     */
    private function lotsIn(int $userId, string $month, bool $redecide = false): array
    {
        $rows = $this->rows($userId);
        $start = self::START_MONTH;
        $prev = null;
        foreach ($rows as $m => $r) {
            if ($m < $month) { $prev = $r; }
        }
        $late = []; $ot = [];
        $from = $prev ? self::nextMonth((string) $prev['month']) : $start;
        if ($prev) {
            $late = $prev['late_lots_out'];
            $ot = $prev['ot_lots_out'];
        }
        // Old-engine overtime still owed enters once, in the month after the last old-rule decision.
        $inject = $this->legacyOtInjection($userId);
        $injectAt = ($inject['month'] >= $from && $inject['month'] <= $month) ? $inject['month'] : null;

        $folded = [];
        for ($m = $from; $m < $month; $m = self::nextMonth($m)) {
            $folded[] = $m;
            if ($injectAt === $m) { $ot = array_merge($ot, $inject['lots']); }
            $own = $this->bankOwn($userId, $m);
            if ($own['late'] > 0) { $late[] = ['from' => $m, 'minutes' => $own['late'], 'src' => 'own']; }
            if ($own['ot'] > 0) { $ot[] = ['from' => $m, 'minutes' => $own['ot'], 'src' => 'own']; }
        }
        if ($injectAt === $month) { $ot = array_merge($ot, $inject['lots']); }

        $adjIds = [];
        $notes = [];
        foreach ($this->adjustRows($userId) as $a) {
            // Re-deciding: what THIS month absorbed is open again (drift is re-measured below).
            $reopened = $redecide && $a['absorbed_in_month'] === $month;
            if ($a['absorbed_in_month'] !== null && !$reopened) { continue; }
            if ($reopened && $a['source'] === 'drift') { continue; }
            // A returned cut / drift belongs to the months AFTER its source month — never back
            // into that month itself (a re-decide of Sep must not count Sep's returned cut twice).
            if (in_array($a['source'], ['returned', 'drift'], true) && $a['source_month'] && $a['source_month'] >= $month) { continue; }
            $lot = ['from' => $a['source_month'] ?: substr((string) $a['created_at'], 0, 7),
                    'minutes' => (int) $a['minutes'], 'src' => (string) $a['source'], 'adjust_id' => (int) $a['id']];
            if ($a['kind'] === 'late') { $late[] = $lot; } else { $ot[] = $lot; }
            $adjIds[] = (int) $a['id'];
            $notes[] = [
                'kind' => $a['kind'], 'minutes' => (int) $a['minutes'], 'src' => (string) $a['source'],
                'reason' => (string) ($a['reason'] ?? ''), 'by' => $a['by_name'] ?? null,
                'at' => $a['created_at'] ? substr((string) $a['created_at'], 0, 10) : null,
            ];
        }

        // Drift: a decided month whose days changed afterwards (a waive, a re-stamp, a review).
        $drift = [];
        $floor = self::prevMonth($month, self::DRIFT_MONTHS);
        foreach ($rows as $m => $r) {
            if ($m >= $month || $m < $floor) { continue; }
            $owned = [$m => ['late' => (int) $r['late_own'], 'ot' => (int) $r['ot_own']]];
            foreach ($r['folded_months'] as $fm) {
                $owned[$fm] = ['late' => 0, 'ot' => 0];
            }
            foreach (['late' => 'late_lots_in', 'ot' => 'ot_lots_in'] as $kind => $col) {
                foreach ($r[$col] as $l) {
                    if (($l['src'] ?? '') === 'own' && isset($owned[$l['from']]) && $l['from'] !== $m) {
                        $owned[$l['from']][$kind] += (int) $l['minutes'];
                    }
                }
            }
            foreach ($owned as $om => $frozen) {
                $live = $this->bankOwn($userId, $om);
                foreach (['late', 'ot'] as $kind) {
                    $absorbed = 0;
                    foreach ($this->adjustRows($userId) as $a) {
                        if ($a['source'] === 'drift' && $a['source_month'] === $om && $a['kind'] === $kind
                            && !($redecide && $a['absorbed_in_month'] === $month)) {
                            $absorbed += (int) $a['minutes'];
                        }
                    }
                    $d = $live[$kind] - $frozen[$kind] - $absorbed;
                    if ($d !== 0) {
                        $lot = ['from' => $om, 'minutes' => $d, 'src' => 'drift'];
                        if ($kind === 'late') { $late[] = $lot; } else { $ot[] = $lot; }
                        $drift[] = ['kind' => $kind, 'month' => $om, 'minutes' => $d];
                        $notes[] = ['kind' => $kind, 'minutes' => $d, 'src' => 'drift',
                            'reason' => self::mon($om) . ' changed after it was decided', 'by' => null, 'at' => null];
                    }
                }
            }
        }

        // RAW lots (negatives included): they are netted against this month's own minutes by the
        // caller (withOwn), and stored raw so a folded month's own minutes stay measurable for drift.
        return [
            'late' => $late, 'ot' => $ot,
            'folded' => $folded, 'adjust_ids' => $adjIds, 'drift' => $drift, 'notes' => $notes,
        ];
    }

    /** Carried-in lots + this month's own minutes (newest last), negatives netted, oldest first. */
    private static function withOwn(array $lots, string $month, int $own): array
    {
        if ($own > 0) { $lots[] = ['from' => $month, 'minutes' => $own, 'src' => 'own']; }
        return self::net($lots);
    }

    /** Net negative lots against the oldest positive ones; a bank can never go below zero. */
    private static function net(array $lots): array
    {
        $neg = 0; $pos = [];
        foreach ($lots as $l) {
            if ((int) $l['minutes'] < 0) { $neg += -(int) $l['minutes']; }
            elseif ((int) $l['minutes'] > 0) { $pos[] = $l; }
        }
        return $neg > 0 ? self::consume($pos, $neg) : $pos;
    }

    /** Spend $minutes from the front (oldest first); return what survives. */
    private static function consume(array $lots, int $minutes): array
    {
        $out = [];
        foreach ($lots as $l) {
            $take = min((int) $l['minutes'], max(0, $minutes));
            $minutes -= $take;
            $rem = (int) $l['minutes'] - $take;
            if ($rem > 0) { $l['minutes'] = $rem; $out[] = $l; }
        }
        return $out;
    }

    private static function sum(array $lots): int
    {
        return (int) array_sum(array_map(fn ($l) => (int) $l['minutes'], $lots));
    }

    /** "7h 45m from Aug + 2h 00m from Sep, not decided". */
    private static function describe(array $lots): string
    {
        $by = [];
        foreach ($lots as $l) {
            $tag = self::mon((string) $l['from']);
            if (in_array($l['src'] ?? '', ['unacted', 'own'], true)) { $tag .= ', not decided'; }
            if (in_array($l['src'] ?? '', ['manual', 'returned'], true)) { $tag = 'added by hand'; }
            if (($l['src'] ?? '') === 'drift') { $tag = self::mon((string) $l['from']) . ' change'; }
            $by[$tag] = ($by[$tag] ?? 0) + (int) $l['minutes'];
        }
        $parts = [];
        foreach ($by as $tag => $m) { $parts[] = self::hm($m) . ' from ' . $tag; }
        return implode(' + ', str_replace('from added by hand', 'added by hand', $parts));
    }

    // =====================================================================
    //  THE ARITHMETIC
    // =====================================================================

    /**
     * Pure maths. Exposed for the proof script.
     * @return array{L:int,O:int,cancel:int,spent:int,late_days:int,late_left:int,ot_days:int,ot_left:int}
     */
    public static function arithmetic(int $L, int $O, bool $cover, float $factor, int $per): array
    {
        $L = max(0, $L); $O = max(0, $O);
        $cancel = 0; $spent = 0;
        if ($cover && $L > 0 && $O > 0) {
            $cancel = min($L, (int) floor($O * $factor + 1e-9));
            $spent = min($O, (int) ceil($cancel / $factor - 1e-9));
        }
        $L2 = $L - $cancel; $O2 = $O - $spent;
        return [
            'L' => $L, 'O' => $O, 'cancel' => $cancel, 'spent' => $spent,
            'late_days' => intdiv($L2, $per), 'late_left' => $L2 % $per,
            'ot_days' => intdiv($O2, $per), 'ot_left' => $O2 % $per,
        ];
    }

    /** Leave usable now; when re-deciding, what this month's own decision took/gave is undone first. */
    private function usableLeave(int $userId, ?array $row): array
    {
        $remaining = 0.0;
        try { $remaining = (float) ((new LeavePolicyService())->balance($userId)['remaining'] ?? 0); } catch (\Throwable $e) { $remaining = 0.0; }
        if ($row) {
            $remaining += (int) $row['leave_days'];
            if (($row['ot_decision'] ?? null) === 'give') {
                $remaining -= (int) $row['ot_days'] - (float) $row['absence_covered'];
            }
        }
        return ['remaining' => round($remaining, 1), 'usable' => max(0, (int) floor($remaining + 1e-9))];
    }

    // =====================================================================
    //  READ — the statement every surface renders
    // =====================================================================

    /**
     * @param array $opts cover?:bool, waive?:int, redecide?:bool (preview a decided month as if undone)
     */
    public function month(int $userId, string $month, array $opts = []): array
    {
        $key = $userId . '|' . $month . '|' . md5(json_encode($opts));
        if (isset(self::$stmtMemo[$key])) { return self::$stmtMemo[$key]; }

        $base = ['user_id' => $userId, 'month' => $month, 'month_label' => self::monthName($month)];
        if (!$this->enabled()) { return self::$stmtMemo[$key] = $base + ['status' => 'off']; }
        if ($this->isExcluded($userId)) { return self::$stmtMemo[$key] = $base + ['status' => 'excluded']; }
        if ($month < self::START_MONTH) {
            return self::$stmtMemo[$key] = $base + ['status' => 'before_start', 'start_month' => self::START_MONTH];
        }
        $rows = $this->rows($userId);
        foreach ($rows as $m => $r) {
            if ($m > $month && in_array($month, $r['folded_months'], true)) {
                return self::$stmtMemo[$key] = $base + ['status' => 'folded', 'folded_into' => $m,
                    'chip' => '→ in ' . self::mon($m) . ' decision',
                    'rider_sentence' => self::mon($month) . ' ka hisaab ' . self::mon($m) . ' ke faisle mein shamil hai.'];
            }
        }
        $row = $rows[$month] ?? null;
        if ($row && empty($opts['redecide'])) {
            return self::$stmtMemo[$key] = $this->decidedStatement($userId, $month, $row, $base);
        }
        return self::$stmtMemo[$key] = $this->previewStatement($userId, $month, $row, $opts, $base);
    }

    private function previewStatement(int $userId, string $month, ?array $row, array $opts, array $base): array
    {
        $in = $this->lotsIn($userId, $month, (bool) $row);
        $own = $this->bankOwn($userId, $month);
        $per = $this->perDay();
        $factor = $this->factor();
        // Negatives (a "take away" adjust, a day waived after its month was decided) net against
        // what came in AND this month's own minutes — never silently dropped.
        $L0 = self::sum(self::withOwn($in['late'], $month, $own['late']));
        $O = self::sum(self::withOwn($in['ot'], $month, $own['ot']));
        $lateIn = $L0 - $own['late'];
        $otIn = $O - $own['ot'];
        $waive = max(0, min($L0, (int) ($opts['waive'] ?? 0)));
        $L = $L0 - $waive;
        $canCover = $L > 0 && $O > 0;
        $cover = $canCover && ($opts['cover'] ?? true);
        $a = self::arithmetic($L, $O, $cover, $factor, $per);
        $plain = self::arithmetic($L, $O, false, $factor, $per);
        $running = !$this->isClosed($month);
        $needs = !$running && ($plain['late_days'] > 0 || $plain['ot_days'] > 0);

        $bal = $this->usableLeave($userId, $row);
        $usable = $bal['usable'];
        $dayRate = (new PayrollService())->dayRate($userId, $month);
        $next = self::mon(self::nextMonth($month));
        $ld = $a['late_days'];

        // ── The choices, worded by the server (one vocabulary everywhere — plan §5.0).
        $lateChoices = [];
        if ($ld > 0) {
            $k = min($ld, $usable);
            if ($usable >= $ld) {
                $lateChoices[] = ['value' => 'leave', 'label' => 'Take ' . self::leaves($ld), 'tone' => 'good'];
            } elseif ($k > 0) {
                $lateChoices[] = ['value' => 'leave', 'label' => 'Take ' . self::leaves($k) . ' + move ' . ($ld - $k) . ' to ' . $next, 'tone' => 'good'];
                if ($dayRate > 0) {
                    $lateChoices[] = ['value' => 'leave_salary', 'label' => 'Take ' . self::leaves($k) . ' + cut Rs ' . number_format(($ld - $k) * $dayRate), 'tone' => 'danger'];
                }
            }
            $lateChoices[] = $dayRate > 0
                ? ['value' => 'salary', 'label' => 'Cut Rs ' . number_format($ld * $dayRate) . ' from salary', 'tone' => 'danger']
                : ['value' => 'salary', 'label' => 'Cut salary', 'tone' => 'danger', 'disabled' => true, 'hint' => 'no salary set'];
            $lateChoices[] = ['value' => 'carry', 'label' => 'Move to ' . $next, 'tone' => 'muted'];
        }
        $otChoices = [];
        if ($a['ot_days'] > 0) {
            $otChoices = [
                ['value' => 'give', 'label' => 'Give +' . self::leaves($a['ot_days']), 'tone' => 'good'],
                ['value' => 'carry', 'label' => 'Move to ' . $next, 'tone' => 'muted'],
                ['value' => 'skip', 'label' => 'Skip', 'tone' => 'muted',
                 'hint' => 'no bonus — all ' . self::hm($a['O'] - $a['spent']) . ' extra is dropped'],
            ];
        }
        $willCover = 0;
        if ($a['ot_days'] > 0) {
            try { $willCover = (int) min($a['ot_days'], floor((new AbsenceDecisionService())->outstandingDays($userId))); } catch (\Throwable $e) { $willCover = 0; }
        }

        // ── Words.
        $lines = [];
        if ($L0 > 0 || $lateIn > 0) {
            $lines[] = 'Late ' . self::hm($L0) . ($lateIn > 0 ? ' (' . self::describe($in['late']) . ')' : '')
                . ($waive > 0 ? ' − ' . self::hm($waive) . ' waived' : '');
        }
        if ($O > 0) {
            $lines[] = 'Extra ' . self::hm($O) . ($otIn > 0 ? ' (' . self::describe($in['ot']) . ')' : '');
        }
        foreach ($this->legacyNotes($userId, $month) as $ln) { $lines[] = $ln; }
        $result = $this->resultSentence($a, $next);
        if ($willCover > 0) {
            $result .= ' · ' . $willCover . ' of the extra leaves settle a parked absence';
        }

        $suggestLate = $ld > 0 ? ($usable > 0 ? 'leave' : 'carry') : null;
        $chip = '';
        if ($running) {
            // This month's OWN minutes — the same figures the rider and the attendance board show.
            $ol = $own['late']; $oo = $own['ot'];
            $chip = 'so far: ' . ($ol > 0 ? 'late ' . self::hm($ol) : '') . ($ol > 0 && $oo > 0 ? ' · ' : '') . ($oo > 0 ? 'extra ' . self::hm($oo) : '');
            if ($ol === 0 && $oo === 0) { $chip = ''; }
        } elseif ($ld > 0) {
            $chip = 'Late: −' . self::leaves($ld) . ' · choose';
        } elseif ($a['ot_days'] > 0) {
            $chip = 'Extra: +' . self::leaves($a['ot_days']) . ' · choose';
        } elseif ($needs) {
            // Whole days exist only if extra does NOT cover late — the choice is the cover itself.
            $chip = 'Extra covers late · choose';
        } elseif ($a['late_left'] > 0) {
            $chip = self::hm($a['late_left']) . ' late → ' . $next;
        } elseif ($a['ot_left'] > 0) {
            $chip = self::hm($a['ot_left']) . ' extra → ' . $next;
        }
        $status = $running ? 'running' : ($needs ? 'pending' : 'clear');

        $expect = sha1(json_encode([$L0, $O, $own, $lateIn, $otIn, $bal['usable'], $per, $factor, $dayRate, $in['folded'],
            $row ? [(int) $row['id'], (string) $row['decided_at']] : null]));

        return $base + [
            'status'      => $status,
            'needs'       => $needs,
            'redecide'    => (bool) $row,
            'start_month' => $this->startMonth($userId),
            // `in_settled` = carried from DECIDED months only (not an undecided month that will
            // fold in) — what a running month may show the rider without double-counting the
            // earlier month's own pending row.
            'late'        => ['own' => $own['late'], 'in' => $lateIn, 'in_label' => self::describe($in['late']),
                              'in_settled' => $in['folded'] ? 0 : self::sumSettled($in['late']),
                              'waive' => $waive, 'total' => $L0, 'counted' => $L],
            'ot'          => ['own' => $own['ot'], 'in' => $otIn, 'in_label' => self::describe($in['ot']),
                              'in_settled' => $in['folded'] ? 0 : self::sumSettled($in['ot']), 'total' => $O],
            'folded'      => $in['folded'],
            'notes'       => $in['notes'],
            'cover'       => ['offered' => $canCover, 'on' => $cover, 'factor' => $factor,
                              'cancel' => $a['cancel'], 'spent' => $a['spent'],
                              'label' => 'Use extra to cover late',
                              'hint' => $canCover
                                  ? 'covers ' . self::hm(self::arithmetic($L, $O, true, $factor, $per)['cancel'])
                                    . ' late with ' . self::hm(self::arithmetic($L, $O, true, $factor, $per)['spent'])
                                    . ' extra (' . rtrim(rtrim(number_format($factor, 2), '0'), '.') . ' rate)'
                                  : ''],
            'net'         => $a,
            'per_day_minutes' => $per,
            'balance'     => $bal,
            'day_rate'    => $dayRate,
            'next_label'  => $next,
            'choices'     => ['late' => $lateChoices, 'ot' => $otChoices],
            'suggested'   => ['cover' => $canCover, 'late' => $suggestLate, 'ot' => $a['ot_days'] > 0 ? 'give' : null],
            'will_cover_absence' => $willCover,
            'lines'       => $lines,
            'result'      => $result,
            'chip'        => $chip,
            'chip_tone'   => $running ? 'muted' : ($ld > 0 ? 'late' : ($a['ot_days'] > 0 ? 'extra' : ($needs ? 'late' : 'muted'))),
            'rider_sentence' => $running
                ? 'Is mahine abhi tak: late ' . self::hm($own['late']) . ' · overtime ' . self::hm($own['ot'])
                : ($needs ? self::mon($month) . ' ka faisla abhi baqi hai.' : $this->riderResult($a, $next)),
            'expect'      => $expect,
            'decision'    => null,
        ];
    }

    private function resultSentence(array $a, string $next): string
    {
        $parts = [];
        if ($a['cancel'] > 0) { $parts[] = 'extra covers ' . self::hm($a['cancel']) . ' late (uses ' . self::hm($a['spent']) . ')'; }
        $res = [];
        if ($a['late_days'] > 0) { $res[] = '−' . self::leaves($a['late_days']) . ' for late'; }
        if ($a['ot_days'] > 0) { $res[] = '+' . $a['ot_days'] . ' extra leave' . ($a['ot_days'] === 1 ? '' : 's'); }
        if ($a['late_left'] > 0) { $res[] = self::hm($a['late_left']) . ' late → ' . $next; }
        if ($a['ot_left'] > 0) { $res[] = self::hm($a['ot_left']) . ' extra → ' . $next; }
        $s = $res ? '= ' . implode(' · ', $res) : '= nothing left';
        return $parts ? ucfirst($parts[0]) . ' ' . $s : ucfirst($s);
    }

    private function riderResult(array $a, string $next): string
    {
        $bits = [];
        if ($a['cancel'] > 0) { $bits[] = 'overtime ne ' . self::hm($a['cancel']) . ' late cover kiya'; }
        if ($a['late_left'] > 0) { $bits[] = self::hm($a['late_left']) . ' late ' . $next . ' mein gaya'; }
        if ($a['ot_left'] > 0) { $bits[] = self::hm($a['ot_left']) . ' overtime ' . $next . ' mein gaya'; }
        return $bits ? ucfirst(implode(' · ', $bits)) : 'Koi late ya overtime baqi nahi.';
    }

    private function decidedStatement(int $userId, string $month, array $r, array $base): array
    {
        $next = self::mon(self::nextMonth($month));
        $lateOut = self::sum($r['late_lots_out']);
        $otOut = self::sum($r['ot_lots_out']);
        $lateIn = self::sum($r['late_lots_in']);
        $otIn = self::sum($r['ot_lots_in']);
        $by = null;
        try { $by = $r['decided_by'] ? DB::table('t_sys_user')->where('id', $r['decided_by'])->value('fullname') : null; } catch (\Throwable $e) { $by = null; }
        $salMon = $r['salary_month'] ? self::mon((string) $r['salary_month']) : null;

        $chips = [];
        $rider = [];
        if ((int) $r['late_cancelled'] > 0) {
            $rider[] = 'Overtime ne ' . self::hm((int) $r['late_cancelled']) . ' late cover kiya';
        }
        if ((int) $r['leave_days'] > 0) {
            $chips[] = self::leaves((int) $r['leave_days']) . ' cut for late';
            $rider[] = self::chhutti((int) $r['leave_days']) . ' late ki wajah se kati';
        }
        if ((int) $r['salary_days'] > 0) {
            $chips[] = 'Rs ' . number_format((float) $r['salary_amount']) . ' cut for late';
            $rider[] = 'Rs ' . number_format((float) $r['salary_amount']) . ' ' . ($salMon ?: '') . ' ki tankhwah se kata';
        }
        if (($r['ot_decision'] ?? null) === 'give' && (int) $r['ot_days'] > 0) {
            $chips[] = '+' . (int) $r['ot_days'] . ' extra leave' . ((int) $r['ot_days'] === 1 ? '' : 's') . ' given';
            $rider[] = '+' . self::chhutti((int) $r['ot_days']) . ' extra mili'
                . ((float) $r['absence_covered'] > 0 ? ' (' . rtrim(rtrim(number_format((float) $r['absence_covered'], 1), '0'), '.') . ' ne purani ghair-hazri poori ki)' : '');
        }
        if (($r['ot_decision'] ?? null) === 'skip') {
            $chips[] = 'extra skipped';
            $rider[] = 'Overtime ka bonus nahi diya gaya';
        }
        if ($lateOut > 0 || $otOut > 0) {
            $chips[] = ($lateOut > 0 ? self::hm($lateOut) . ' late' : '') . ($lateOut > 0 && $otOut > 0 ? ' + ' : '')
                . ($otOut > 0 ? self::hm($otOut) . ' extra' : '') . ' → ' . $next;
            $rider[] = ($lateOut > 0 ? self::hm($lateOut) . ' late' : '') . ($lateOut > 0 && $otOut > 0 ? ' aur ' : '')
                . ($otOut > 0 ? self::hm($otOut) . ' overtime' : '') . ' ' . $next . ' mein gaya';
        }
        if ((int) $r['month_waive'] > 0) {
            $rider[] = self::hm((int) $r['month_waive']) . ' late maaf kiya gaya' . ($r['waive_reason'] ? ' (' . $r['waive_reason'] . ')' : '');
        }
        if (!$chips) { $chips[] = 'settled'; }

        $lines = [];
        $lateTotal = (int) $r['late_own'] + $lateIn;
        if ($lateTotal > 0) {
            $lines[] = 'Late ' . self::hm($lateTotal) . ($lateIn > 0 ? ' (' . self::describe($r['late_lots_in']) . ')' : '')
                . ((int) $r['month_waive'] > 0 ? ' − ' . self::hm((int) $r['month_waive']) . ' waived' : '');
        }
        $otTotal = (int) $r['ot_own'] + $otIn;
        if ($otTotal > 0) {
            $lines[] = 'Extra ' . self::hm($otTotal) . ($otIn > 0 ? ' (' . self::describe($r['ot_lots_in']) . ')' : '');
        }
        if ((int) $r['late_cancelled'] > 0) {
            $lines[] = 'Extra covered ' . self::hm((int) $r['late_cancelled']) . ' late (used ' . self::hm((int) $r['ot_spent']) . ')';
        }

        $laterDecided = false;
        foreach ($this->rows($userId) as $m => $x) { if ($m > $month) { $laterDecided = true; } }
        $returned = $this->returnedFor($userId, $month);
        if ($returned > 0) {
            $chips[] = self::hm($returned) . ' late cut not taken → back in the bank';
        }

        return $base + [
            'status'   => 'decided',
            'needs'    => false,
            'start_month' => $this->startMonth($userId),
            'folded'   => $r['folded_months'],
            'lines'    => $lines,
            'result'   => '✓ ' . implode(' · ', $chips),
            'chip'     => '✓ ' . implode(' · ', $chips),
            'chip_tone'=> (int) $r['salary_days'] > 0 ? 'late' : 'done',
            'late'     => ['own' => (int) $r['late_own'], 'in' => $lateIn, 'waive' => (int) $r['month_waive'], 'total' => $lateTotal],
            'ot'       => ['own' => (int) $r['ot_own'], 'in' => $otIn, 'total' => $otTotal],
            'net'      => ['cancel' => (int) $r['late_cancelled'], 'spent' => (int) $r['ot_spent'],
                           'late_days' => (int) $r['late_days'], 'ot_days' => (int) $r['ot_days'],
                           'late_left' => $lateOut, 'ot_left' => $otOut],
            'rider_sentence' => self::mon($month) . ': ' . ($rider ? implode(' · ', $rider) : 'koi asar nahi'),
            'decision' => [
                'cover'        => (bool) $r['cover'],
                'leave'        => (int) $r['leave_days'],
                'salary'       => (int) $r['salary_days'],
                'salary_amount'=> (float) $r['salary_amount'],
                'salary_month' => $r['salary_month'],
                'salary_paid'  => $r['salary_paid_at'] !== null,
                'carry'        => (int) $r['carry_days'],
                'ot'           => $r['ot_decision'],
                'ot_days'      => (int) $r['ot_days'],
                'absence_covered' => (float) $r['absence_covered'],
                'waive'        => (int) $r['month_waive'],
                'waive_reason' => $r['waive_reason'],
                'by'           => $by,
                'at'           => $r['decided_at'] ? substr((string) $r['decided_at'], 0, 10) : null,
                'can_change'   => !$laterDecided && $r['salary_paid_at'] === null && $returned === 0 && $this->isClosed($month),
                'change_blocked' => $laterDecided ? 'a later month is already decided'
                    : ($r['salary_paid_at'] !== null ? 'the salary cut is already paid'
                    : ($returned > 0 ? 'its salary cut went back to the bank at payment' : null)),
            ],
        ];
    }

    /** Every active monthly employee's statement for a month — boards. */
    public function monthAll(string $month): array
    {
        $out = [];
        if (!$this->enabled()) { return $out; }
        try {
            $ids = DB::table('t_sys_user')->where('is_active', 1)->pluck('id');
        } catch (\Throwable $e) { return $out; }
        foreach ($ids as $uid) {
            $uid = (int) $uid;
            if ($this->isExcluded($uid)) { continue; }
            try { $out[$uid] = $this->month($uid, $month); } catch (\Throwable $e) {
                \Log::warning('TimeBank month failed', ['user_id' => $uid, 'month' => $month, 'error' => $e->getMessage()]);
            }
        }
        return $out;
    }

    /**
     * Decided (or folded into a later decision)? Reads only the bank table — cheap, and it never
     * touches attendance, so callers in the middle of an edit can't cache a stale month.
     */
    public function isSettled(int $userId, string $month): bool
    {
        foreach ($this->rows($userId) as $m => $r) {
            if ($m === $month || ($m > $month && in_array($month, $r['folded_months'], true))) { return true; }
        }
        return false;
    }

    /** Salary cuts that land on $month's pay (any earlier bank month may target it). */
    public function cutsLandingOn(int $userId, string $month): array
    {
        $out = [];
        foreach ($this->rows($userId) as $m => $r) {
            if ((string) $r['salary_month'] === $month && (float) $r['salary_amount'] > 0) {
                $out[] = ['from_month' => $m, 'days' => (int) $r['salary_days'],
                          'amount' => (float) $r['salary_amount'], 'paid' => $r['salary_paid_at'] !== null,
                          'label' => self::mon($m) . ' late · ' . self::leaves((int) $r['salary_days'])];
            }
        }
        return $out;
    }

    /** Decided leave/bonus figures for a month (payroll row + old APK keys). */
    public function decidedFigures(int $userId, string $month): array
    {
        $r = $this->rows($userId)[$month] ?? null;
        return [
            'decided'  => $r !== null,
            'leave'    => $r ? (int) $r['leave_days'] : 0,
            'given'    => $r && ($r['ot_decision'] ?? null) === 'give' ? (int) $r['ot_days'] : 0,
        ];
    }

    // =====================================================================
    //  WRITE
    // =====================================================================

    /**
     * Decide a month. $in: cover(bool), late_choice(leave|leave_salary|salary|carry),
     * ot_choice(give|carry|skip), waive(int minutes), waive_reason(string), expect(string|null).
     */
    public function decide(int $userId, string $month, array $in, int $actorId, bool $checkExpect = true): array
    {
        if (!$this->enabled()) { return ['success' => false, 'message' => 'The time bank is not switched on yet (SQL not run).']; }
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) { return ['success' => false, 'message' => 'Bad month.']; }
        if ($this->isExcluded($userId)) { return ['success' => false, 'message' => 'This employee is on a custom pay schedule — the time bank does not apply.']; }
        if ($month < self::START_MONTH) {
            return ['success' => false, 'message' => self::monthName($month) . ' is before the time bank started (September 2026). Use "Adjust time bank" to add anything that was missed.'];
        }
        if (!$this->isClosed($month)) {
            return ['success' => false, 'message' => self::monthName($month) . ' has not finished yet — decide once the month ends.'];
        }
        foreach ($this->rows($userId) as $m => $r) {
            if ($m > $month) {
                return ['success' => false, 'message' => self::monthName($m) . ' is already decided for this employee and it counted what ' . self::mon($month) . ' passed on. Change ' . self::mon($m) . ' first.'];
            }
        }
        $existing = $this->rows($userId)[$month] ?? null;
        if ($existing && $existing['salary_paid_at'] !== null) {
            return ['success' => false, 'message' => 'The salary cut from this decision is already paid, so it can no longer change. Use "Adjust time bank" instead.'];
        }
        if ($existing && $this->returnedFor($userId, $month) > 0) {
            return ['success' => false, 'message' => 'This month\'s salary cut went back to the bank at payment, so it can no longer change. Use "Adjust time bank" instead.'];
        }
        // Fix 3: a decision already exists → only a "change" opened from it (its hash) may replace it.
        if ($existing && $checkExpect && empty($in['expect'])) {
            return ['success' => false, 'stale' => true, 'message' => self::monthName($month) . ' was already decided — open it and press "change" to change it.',
                    'statement' => $this->month($userId, $month)];
        }

        $waive = max(0, (int) ($in['waive'] ?? 0));
        $reason = trim((string) ($in['waive_reason'] ?? ''));
        if ($waive > 0 && $reason === '') {
            return ['success' => false, 'message' => 'Give a reason for waiving ' . self::hm($waive) . '.'];
        }
        $opts = ['cover' => (bool) ($in['cover'] ?? true), 'waive' => $waive, 'redecide' => (bool) $existing];
        $s = $this->month($userId, $month, $opts);
        if (($s['status'] ?? '') === 'folded') {
            return ['success' => false, 'message' => self::monthName($month) . ' is already part of ' . self::monthName($s['folded_into']) . "'s decision."];
        }
        if ($checkExpect && !empty($in['expect']) && $in['expect'] !== $s['expect']) {
            return ['success' => false, 'stale' => true, 'message' => 'The numbers changed since this was opened (a day was changed or someone decided). Here is the fresh card.', 'statement' => $s];
        }
        if ($waive > (int) $s['late']['total']) {
            return ['success' => false, 'message' => 'You can waive at most ' . self::hm((int) $s['late']['total']) . '.'];
        }

        $a = $s['net'];
        $ld = (int) $a['late_days'];
        $usable = (int) $s['balance']['usable'];
        $leave = 0; $salary = 0; $carry = 0;
        if ($ld > 0) {
            $choice = (string) ($in['late_choice'] ?? '');
            $k = min($ld, $usable);
            switch ($choice) {
                case 'leave':        if ($k < 1) { return ['success' => false, 'message' => 'No leave left to take — choose salary or move to next month.']; }
                                     $leave = $k; $carry = $ld - $k; break;
                case 'leave_salary': if ($k < 1) { return ['success' => false, 'message' => 'No leave left to take.']; }
                                     $leave = $k; $salary = $ld - $k; break;
                case 'salary':       $salary = $ld; break;
                case 'carry':        $carry = $ld; break;
                default:             return ['success' => false, 'message' => 'Choose what happens to the ' . self::leaves($ld) . ' of late.'];
            }
            if ($salary > 0 && (float) $s['day_rate'] <= 0) {
                return ['success' => false, 'message' => 'No salary is set for this employee, so nothing can be cut.'];
            }
        }
        $otChoice = null;
        if ((int) $a['ot_days'] > 0) {
            $otChoice = (string) ($in['ot_choice'] ?? '');
            if (!in_array($otChoice, ['give', 'carry', 'skip'], true)) {
                return ['success' => false, 'message' => 'Choose what happens to the +' . self::leaves((int) $a['ot_days']) . ' extra.'];
            }
        }

        // Fix 4: the balance after this decision (what a change undoes is already added back in
        // $s['balance']) may never go below zero — e.g. taking back a bonus that was already used.
        $after = (float) $s['balance']['remaining'] - $leave
            + ($otChoice === 'give' ? (int) $a['ot_days'] - (int) ($s['will_cover_absence'] ?? 0) : 0);
        if ($existing && $after < 0) {
            return ['success' => false, 'message' => 'This change would push the leave balance below zero (leave already used). '
                . 'Keep the extra as given, or use "Adjust time bank" instead.'];
        }

        // ── Lots out (oldest first).
        $per = (int) $s['per_day_minutes'];
        $inLots = $this->lotsIn($userId, $month, (bool) $existing);
        $own = $this->bankOwn($userId, $month);
        $lateQ = self::withOwn($inLots['late'], $month, $own['late']);
        $lateQ = self::consume($lateQ, $waive);
        $lateQ = self::consume($lateQ, (int) $a['cancel']);
        $lateQ = self::consume($lateQ, ($leave + $salary) * $per);
        $otQ = self::withOwn($inLots['ot'], $month, $own['ot']);
        $otQ = self::consume($otQ, (int) $a['spent']);
        if ($otChoice === 'give') { $otQ = self::consume($otQ, (int) $a['ot_days'] * $per); }
        if ($otChoice === 'skip') { $otQ = []; }
        $relabel = fn ($q) => array_map(fn ($l) => ['from' => $l['from'], 'minutes' => (int) $l['minutes'],
            'src' => in_array($l['src'], ['own', 'unacted'], true) ? 'carry' : $l['src']], $q);

        $dayRate = (float) $s['day_rate'];
        $salaryAmount = round($salary * $dayRate, 2);
        $payroll = new PayrollService();
        $salaryMonth = null;
        if ($salary > 0) {
            $salaryMonth = $month;
            for ($i = 0; $i < 24 && $payroll->isPaid($userId, $salaryMonth); $i++) {
                $salaryMonth = self::nextMonth($salaryMonth);
            }
        }

        $grantReason = 'Time bank ' . date('M Y', strtotime($month . '-01'));
        $eff = $month . '-01';
        $covered = 0.0;
        try {
            DB::transaction(function () use (
                $userId, $month, $existing, $actorId, $payroll, $grantReason, $eff, $inLots, $own, $s, $a,
                $waive, $reason, $opts, $leave, $salary, $carry, $salaryAmount, $salaryMonth, $dayRate, $per,
                $otChoice, $lateQ, $otQ, $relabel, &$covered
            ) {
                if ($existing) { $this->undoInside($userId, $month, $existing, $payroll); }

                // Leave effects (status lives on the bank row; the grant is only the ledger).
                if ($leave > 0 || $this->grantExists($userId, 'late_penalty', $grantReason, $eff)) {
                    $payroll->recordLeaveDecision($userId, -1 * $leave, 'late_penalty', $grantReason, $eff, $actorId, true);
                }
                $given = $otChoice === 'give' ? (int) $a['ot_days'] : 0;
                if ($given > 0 || $this->grantExists($userId, 'overtime', $grantReason, $eff)) {
                    $payroll->recordLeaveDecision($userId, $given, 'overtime', $grantReason, $eff, $actorId, true);
                }
                if ($given > 0) {
                    // Bonus days settle PARKED absences first (owner, Sep-2026) — unchanged rule.
                    $covered = (float) $payroll->coverAbsencesWithBonus($userId, $given, $month, $actorId);
                }

                // Open adjustments are taken in; drift is written down as absorbed.
                if ($inLots['adjust_ids']) {
                    DB::table('t_hr_time_bank_adjust')->whereIn('id', $inLots['adjust_ids'])
                        ->update(['absorbed_in_month' => $month]);
                }
                foreach ($inLots['drift'] as $d) {
                    DB::table('t_hr_time_bank_adjust')->insert([
                        'user_id' => $userId, 'kind' => $d['kind'], 'minutes' => $d['minutes'], 'source' => 'drift',
                        'source_month' => $d['month'], 'reason' => self::mon($d['month']) . ' changed after it was decided',
                        'absorbed_in_month' => $month, 'created_by' => $actorId, 'created_at' => now(),
                    ]);
                }

                DB::table('t_hr_time_bank_month')->insert([
                    'user_id' => $userId, 'month' => $month,
                    'folded_months' => json_encode($inLots['folded']),
                    'late_own' => $own['late'], 'ot_own' => $own['ot'],
                    'late_lots_in' => json_encode($inLots['late']), 'ot_lots_in' => json_encode($inLots['ot']),
                    'month_waive' => $waive, 'waive_reason' => $waive > 0 ? mb_substr($reason, 0, 255) : null,
                    'cover' => $s['cover']['on'] ? 1 : 0, 'factor' => $s['cover']['factor'], 'per_day_minutes' => $per,
                    'late_cancelled' => (int) $a['cancel'], 'ot_spent' => (int) $a['spent'],
                    'late_days' => (int) $a['late_days'], 'leave_days' => $leave, 'salary_days' => $salary,
                    'salary_amount' => $salaryAmount, 'day_rate' => $dayRate, 'salary_month' => $salaryMonth,
                    'carry_days' => $carry, 'late_lots_out' => json_encode($relabel($lateQ)),
                    'ot_days' => (int) $a['ot_days'], 'ot_decision' => $otChoice,
                    'ot_lots_out' => json_encode($relabel($otQ)), 'absence_covered' => $covered,
                    'decided_by' => $actorId, 'decided_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            \Log::error('TimeBank decide failed', ['user_id' => $userId, 'month' => $month, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not save: ' . $e->getMessage()];
        }

        self::forget();
        (new PayrollService())->forgetAll();
        $fresh = $this->month($userId, $month);
        $this->notifyRider($userId, $month, $fresh, $existing['outcome_hash'] ?? null);
        \Log::info('TimeBank decided', ['user_id' => $userId, 'month' => $month, 'by' => $actorId,
            'cover' => $opts['cover'], 'leave' => $leave, 'salary' => $salary, 'carry' => $carry, 'ot' => $otChoice, 'waive' => $waive]);

        $name = DB::table('t_sys_user')->where('id', $userId)->value('fullname') ?: 'Employee';
        $msg = $name . ' · ' . self::mon($month) . ': ' . $fresh['chip'];
        if ($salary > 0 && $salaryMonth !== $month) {
            $msg .= ' — ' . self::monthName($month) . ' is already paid, so the cut comes off ' . self::monthName($salaryMonth) . '.';
        }
        return ['success' => true, 'message' => $msg, 'statement' => $fresh];
    }

    /** The suggested decision (bulk "Confirm all suggested", and old APKs' single button). */
    public function decideSuggested(int $userId, string $month, int $actorId): array
    {
        $s = $this->month($userId, $month);
        if (($s['status'] ?? '') !== 'pending') {
            return ['success' => false, 'message' => 'Nothing to decide for this month.'];
        }
        return $this->decide($userId, $month, [
            'cover' => (bool) $s['suggested']['cover'],
            'late_choice' => $s['suggested']['late'],
            'ot_choice' => $s['suggested']['ot'],
        ], $actorId, false);
    }

    private function grantExists(int $userId, string $source, string $reason, string $eff): bool
    {
        return DB::table('t_hr_leave_grant')->where('user_id', $userId)->where('source', $source)
            ->whereDate('effective_date', $eff)->where('reason', $reason)->exists();
    }

    /** Reverse a decided month inside the caller's transaction. */
    private function undoInside(int $userId, string $month, array $row, PayrollService $payroll): void
    {
        $reason = 'Time bank ' . date('M Y', strtotime($month . '-01'));
        $eff = $month . '-01';
        if ($this->grantExists($userId, 'late_penalty', $reason, $eff)) {
            $payroll->recordLeaveDecision($userId, 0, 'late_penalty', $reason, $eff, $userId, true);
        }
        if ($this->grantExists($userId, 'overtime', $reason, $eff)) {
            $payroll->recordLeaveDecision($userId, 0, 'overtime', $reason, $eff, $userId, true);
        }
        if ((float) $row['absence_covered'] > 0) {
            $payroll->uncoverAbsencesFor($userId, $month);
        }
        DB::table('t_hr_time_bank_adjust')->where('user_id', $userId)->where('absorbed_in_month', $month)
            ->where('source', 'drift')->delete();
        DB::table('t_hr_time_bank_adjust')->where('user_id', $userId)->where('absorbed_in_month', $month)
            ->update(['absorbed_in_month' => null]);
        DB::table('t_hr_time_bank_month')->where('user_id', $userId)->where('month', $month)->delete();
    }

    /** Late minutes of THIS month's salary cut that were sent back to the bank at payment. */
    private function returnedFor(int $userId, string $month): int
    {
        $n = 0;
        foreach ($this->adjustRows($userId) as $a) {
            if ($a['source'] === 'returned' && $a['source_month'] === $month) { $n += (int) $a['minutes']; }
        }
        return $n;
    }

    /** Put a decided month back to "choose". */
    public function undo(int $userId, string $month, int $actorId): array
    {
        $row = $this->rows($userId)[$month] ?? null;
        if (!$row) { return ['success' => false, 'message' => 'This month has no decision to undo.']; }
        foreach ($this->rows($userId) as $m => $r) {
            if ($m > $month) {
                return ['success' => false, 'message' => self::monthName($m) . ' is already decided — undo that first.'];
            }
        }
        if ($row['salary_paid_at'] !== null) {
            return ['success' => false, 'message' => 'The salary cut is already paid — use "Adjust time bank" instead.'];
        }
        if (($row['ot_decision'] ?? null) === 'give') {
            $bal = $this->usableLeave($userId, null)['remaining'];
            $back = (int) $row['ot_days'] - (float) $row['absence_covered'] - (int) $row['leave_days'];
            if ($bal - $back < 0) {
                return ['success' => false, 'message' => 'Taking back the +' . self::leaves((int) $row['ot_days'])
                    . ' would push the leave balance below zero (leave already used). Use "Adjust time bank" instead.'];
            }
        }
        try {
            DB::transaction(fn () => $this->undoInside($userId, $month, $row, new PayrollService()));
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Could not undo: ' . $e->getMessage()];
        }
        self::forget();
        (new PayrollService())->forgetAll();
        \Log::info('TimeBank undone', ['user_id' => $userId, 'month' => $month, 'by' => $actorId]);
        return ['success' => true, 'message' => self::monthName($month) . ' is open again.', 'statement' => $this->month($userId, $month)];
    }

    /** Taimur's tool: add or take away minutes by hand (R9). */
    public function adjust(int $userId, string $kind, int $minutes, string $reason, int $actorId): array
    {
        if (!$this->enabled()) { return ['success' => false, 'message' => 'The time bank is not switched on yet.']; }
        if (!in_array($kind, ['late', 'ot'], true)) { return ['success' => false, 'message' => 'Choose late or extra.']; }
        if ($minutes === 0 || abs($minutes) > 200 * 60) { return ['success' => false, 'message' => 'Enter the minutes (up to 200 hours).']; }
        $reason = trim($reason);
        if ($reason === '') { return ['success' => false, 'message' => 'Give a reason — it shows on the card and to the rider.']; }
        if ($this->isExcluded($userId)) { return ['success' => false, 'message' => 'This employee is on a custom pay schedule.']; }
        DB::table('t_hr_time_bank_adjust')->insert([
            'user_id' => $userId, 'kind' => $kind, 'minutes' => $minutes, 'source' => 'manual',
            'source_month' => null, 'reason' => mb_substr($reason, 0, 255),
            'absorbed_in_month' => null, 'created_by' => $actorId, 'created_at' => now(),
        ]);
        self::forget();
        (new PayrollService())->forgetAll();
        \Log::info('TimeBank adjusted', ['user_id' => $userId, 'kind' => $kind, 'minutes' => $minutes, 'by' => $actorId]);
        return ['success' => true, 'message' => ($minutes > 0 ? '+' : '') . self::hm($minutes) . ' '
            . ($kind === 'late' ? 'late' : 'extra') . ' added — it counts in the next decision.'];
    }

    /** Remove an OPEN manual adjustment (mistyped). Absorbed ones are history. */
    public function removeAdjust(int $userId, int $adjustId, int $actorId): array
    {
        $n = DB::table('t_hr_time_bank_adjust')->where('id', $adjustId)->where('user_id', $userId)
            ->where('source', 'manual')->whereNull('absorbed_in_month')->delete();
        self::forget();
        return $n ? ['success' => true, 'message' => 'Removed.']
                  : ['success' => false, 'message' => 'Only an adjustment not yet used in a decision can be removed.'];
    }

    // =====================================================================
    //  PAY HOOKS (called from PayrollService::payRow inside its transaction)
    // =====================================================================

    /** The bank cuts on this pay went out. */
    public function markCutsPaid(int $userId, string $month): void
    {
        if (!$this->enabled()) { return; }
        DB::table('t_hr_time_bank_month')->where('user_id', $userId)->where('salary_month', $month)
            ->whereNull('salary_paid_at')->where('salary_amount', '>', 0)
            ->update(['salary_paid_at' => now(), 'updated_at' => now()]);
        self::forget();
    }

    /**
     * A cut this pay did NOT take (shortfall waive / manual amount) goes back into the bank as
     * late minutes — never lost. The decision row is rewritten to say "moved" instead of "cut".
     */
    public function returnCuts(int $userId, string $month, int $actorId): void
    {
        if (!$this->enabled()) { return; }
        foreach (DB::table('t_hr_time_bank_month')->where('user_id', $userId)->where('salary_month', $month)
                     ->whereNull('salary_paid_at')->where('salary_days', '>', 0)->get() as $r) {
            DB::table('t_hr_time_bank_adjust')->insert([
                'user_id' => $userId, 'kind' => 'late', 'minutes' => (int) $r->salary_days * (int) $r->per_day_minutes,
                'source' => 'returned', 'source_month' => $r->month,
                'reason' => self::mon((string) $r->month) . ' late cut not taken at payment — back in the bank',
                'absorbed_in_month' => null, 'created_by' => $actorId, 'created_at' => now(),
            ]);
            DB::table('t_hr_time_bank_month')->where('id', $r->id)->update([
                'carry_days' => (int) $r->carry_days + (int) $r->salary_days,
                'salary_days' => 0, 'salary_amount' => 0, 'salary_month' => null, 'updated_at' => now(),
            ]);
        }
        self::forget();
    }

    /** A person's bank history: decided months (newest first) + every adjustment. */
    public function history(int $userId): array
    {
        if (!$this->enabled()) { return ['enabled' => false]; }
        $months = [];
        foreach (array_reverse($this->rows($userId), true) as $m => $r) {
            $s = $this->month($userId, (string) $m);
            $months[] = ['month' => $m, 'month_label' => $s['month_label'], 'chip' => $s['chip'] ?? '',
                         'lines' => $s['lines'] ?? [], 'by' => $s['decision']['by'] ?? null,
                         'at' => $s['decision']['at'] ?? null, 'can_change' => $s['decision']['can_change'] ?? false,
                         'change_blocked' => $s['decision']['change_blocked'] ?? null];
        }
        $adj = array_map(fn ($a) => [
            'id' => (int) $a['id'], 'kind' => $a['kind'], 'minutes' => (int) $a['minutes'],
            'label' => ((int) $a['minutes'] > 0 ? '+' : '') . self::hm((int) $a['minutes']) . ' ' . ($a['kind'] === 'late' ? 'late' : 'extra'),
            'source' => $a['source'], 'reason' => $a['reason'], 'by' => $a['by_name'] ?? null,
            'at' => $a['created_at'] ? substr((string) $a['created_at'], 0, 10) : null,
            'open' => $a['absorbed_in_month'] === null,
            'used_in' => $a['absorbed_in_month'],
            'removable' => $a['source'] === 'manual' && $a['absorbed_in_month'] === null,
        ], array_reverse($this->adjustRows($userId)));
        return ['enabled' => true, 'start_month' => $this->startMonth($userId), 'excluded' => $this->isExcluded($userId),
                'months' => $months, 'adjustments' => $adj];
    }

    // =====================================================================
    //  RIDER
    // =====================================================================

    /** What the rider may see: facts and decisions only, never suggestions. */
    public function riderView(int $userId, ?string $month = null): array
    {
        if (!$this->enabled() || $this->isExcluded($userId)) { return ['enabled' => false]; }
        $cur = $this->currentMonth();
        $months = [];
        foreach ([$cur, self::prevMonth($cur), self::prevMonth($cur, 2)] as $m) {
            if ($m < self::START_MONTH) { continue; }
            $s = $this->month($userId, $m);
            if (in_array($s['status'], ['before_start', 'off', 'excluded'], true)) { continue; }
            $months[] = self::riderSlice($s);
        }
        $one = null;
        if ($month && $month >= self::START_MONTH) {
            $s = $this->month($userId, $month);
            if (!in_array($s['status'], ['before_start', 'off', 'excluded'], true)) { $one = self::riderSlice($s); }
        }
        return ['enabled' => true, 'months' => $months, 'month' => $one];
    }

    /** Minutes carried from DECIDED months (lots that are not an undecided month's own). */
    private static function sumSettled(array $lots): int
    {
        return (int) array_sum(array_map(fn ($l) => in_array($l['src'] ?? '', ['own', 'unacted'], true) ? 0 : (int) $l['minutes'], $lots));
    }

    private static function riderSlice(array $s): array
    {
        // A running month shows its OWN minutes + what decided months carried in; an earlier
        // month still waiting for a decision has its own row, so it is not counted twice.
        $running = ($s['status'] ?? '') === 'running';
        $lateIn = (int) ($running ? ($s['late']['in_settled'] ?? 0) : ($s['late']['in'] ?? 0));
        $otIn = (int) ($running ? ($s['ot']['in_settled'] ?? 0) : ($s['ot']['in'] ?? 0));
        return [
            'month'       => $s['month'],
            'month_label' => $s['month_label'],
            'status'      => $s['status'],                   // running | pending | clear | decided | folded
            'late_total'  => (int) ($running ? ($s['late']['own'] ?? 0) + $lateIn : ($s['late']['total'] ?? 0)),
            'late_in'     => $lateIn,
            'ot_total'    => (int) ($running ? ($s['ot']['own'] ?? 0) + $otIn : ($s['ot']['total'] ?? 0)),
            'ot_in'       => $otIn,
            'sentence'    => (string) ($s['rider_sentence'] ?? ''),
            'decided_by'  => $s['decision']['by'] ?? null,
            'decided_at'  => $s['decision']['at'] ?? null,
        ];
    }

    /** One push when a month is decided; again only if the OUTCOME changed (plan E27). */
    private function notifyRider(int $userId, string $month, array $s, ?string $prev): void
    {
        try {
            $hash = sha1((string) ($s['rider_sentence'] ?? ''));
            DB::table('t_hr_time_bank_month')->where('user_id', $userId)->where('month', $month)->update(['outcome_hash' => $hash]);
            if ($prev === $hash) { return; }
            (new \App\Services\FirebaseService())->notifyTimeBankDecided($userId, $month, (string) $s['rider_sentence']);
        } catch (\Throwable $e) {
            \Log::warning('TimeBank rider push failed', ['user_id' => $userId, 'month' => $month, 'error' => $e->getMessage()]);
        }
    }
}
