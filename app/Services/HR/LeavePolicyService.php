<?php

namespace App\Services\HR;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single source of truth for a rider's leave policy — quota, balance, and the
 * same-day-application cap. The web attendance page, the mobile rider app, and both
 * leave-application validators (web + mobile) all call this so the numbers can never
 * disagree.
 *
 * MODEL (owner-confirmed 2026-07-12): ONE pool of leaves (default 10) per year cycle.
 * "Emergency" is NOT a separate balance — it just means a SAME-DAY application, and those
 * are capped per cycle (default 4, before a cutoff time) so people don't all apply in the
 * morning. Same-day leaves draw from the same single pool.
 *
 * Fully guarded: works before the Phase-E SQL (t_hr_leave_grant) is run — a missing table
 * just means zero adjustments.
 */
class LeavePolicyService
{
    private static ?bool $hasGrantTable = null;

    /**
     * 🚫 Owner ruling (Sep-3 2026): a leave balance must NEVER go below zero.
     *
     * Before this, a manager could push someone from 0 to −1 by granting leave on their behalf
     * (the old flow warned once and then allowed an override) or by typing a negative manual
     * adjustment. A negative balance is not a real thing — the year's allowance is what it is —
     * and it quietly changed what payroll charged for lateness later.
     *
     * The fix the owner asked for is to refuse and say what to do instead: raise the quota on
     * the Attendance page first, deliberately, so the extra days are a recorded decision rather
     * than a side effect of approving one leave.
     *
     * @param float $days How many days this action would CONSUME (positive) — a manual
     *                    adjustment of −2 is passed as 2.
     * @return string|null The refusal to show, or null when there is room.
     */
    public function overQuotaRefusal(int $userId, float $days): ?string
    {
        if ($days <= 0) { return null; }
        try {
            $bal  = $this->balance($userId);
            $left = (float) ($bal['remaining'] ?? 0);
            if ($days <= $left) { return null; }
            $name = DB::table('t_sys_user')->where('id', $userId)->value('fullname') ?: 'This employee';
            $leftTxt = $left > 0 ? rtrim(rtrim(number_format($left, 1), '0'), '.') : '0';
            $daysTxt = rtrim(rtrim(number_format($days, 1), '0'), '.');
            return $name . ' has ' . $leftTxt . ' leave' . ($leftTxt === '1' ? '' : 's')
                . ' left this year and this needs ' . $daysTxt . '. '
                . 'A leave balance cannot go below zero — increase the leave quota first from the '
                . 'Attendance page (leave balance › adjust), then do this again.';
        } catch (\Throwable $e) {
            return null;   // never block on a read failure
        }
    }

    /** t_fin_config read with a default; never throws. */
    private function config(string $key, $default)
    {
        try {
            $v = \App\Services\HR\ConfigMemo::get($key);
            return ($v === null || $v === '') ? $default : $v;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /** The configured yearly cycle (mirrors AttendanceController::attendanceCycle). */
    public function cycle(): array
    {
        try {
            $s = \App\Services\HR\ConfigMemo::get('ATTENDANCE_CYCLE_START');
            $e = \App\Services\HR\ConfigMemo::get('ATTENDANCE_CYCLE_END');
            if ($s && $e && strtotime((string) $s) && strtotime((string) $e)) {
                $s = substr((string) $s, 0, 10);
                $e = substr((string) $e, 0, 10);
                if ($s <= $e) {
                    return ['start' => $s, 'end' => $e];
                }
            }
        } catch (\Throwable $ex) { /* fall through */ }
        $y = date('Y');
        return ['start' => "$y-01-01", 'end' => "$y-12-31"];
    }

    public function quotaTotal(): float   { return (float) $this->config('LEAVE_QUOTA_TOTAL', 10); }
    public function samedayCap(): int     { return (int) $this->config('LEAVE_SAMEDAY_CAP', 4); }
    public function samedayCutoff(): string { return (string) $this->config('LEAVE_SAMEDAY_CUTOFF', '10:00'); }

    private function grantTableExists(): bool
    {
        if (self::$hasGrantTable === null) {
            try { self::$hasGrantTable = Schema::hasTable('t_hr_leave_grant'); }
            catch (\Throwable $e) { self::$hasGrantTable = false; }
        }
        return self::$hasGrantTable;
    }

    /**
     * Approved leave DAYS taken in [start,end], counted per distinct date so overlapping
     * requests can't double-count — the exact definition the Month tab / drill-down use.
     *
     * WEIGHTED: a date covered by a full-day leave counts 1.0; a date covered ONLY by a
     * half-day leave (leave_type='half_day') counts 0.5. A full-day row on the same date
     * always wins (so a full leave over a half never under-counts).
     */
    public function takenDays(int $userId, string $start, string $end): float
    {
        $full = []; $half = [];
        try {
            $rows = DB::table('t_req_master as r')
                ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
                ->where('c.category_code', 'leave')
                ->where('r.requester_user_id', $userId)
                ->where('r.status', 'approved')
                ->where('r.leave_start_date', '<=', $end)
                ->where('r.leave_end_date', '>=', $start)
                ->select('r.leave_start_date', 'r.leave_end_date', 'r.leave_type')
                ->get();
            foreach ($rows as $lv) {
                $ls = max($start, substr((string) $lv->leave_start_date, 0, 10));
                $le = min($end, substr((string) $lv->leave_end_date, 0, 10));
                $isHalf = strtolower((string) $lv->leave_type) === 'half_day';
                for ($c = new \DateTime($ls); $c <= new \DateTime($le); $c->modify('+1 day')) {
                    $d = $c->format('Y-m-d');
                    if ($isHalf) { $half[$d] = true; } else { $full[$d] = true; }
                }
            }
        } catch (\Throwable $e) { /* no leaves */ }
        $total = (float) count($full);
        foreach (array_keys($half) as $d) {
            if (!isset($full[$d])) { $total += 0.5; } // full-day on the same date wins
        }
        return $total;
    }

    /**
     * Dates in [from,to] the rider has an approved/pending HALF-DAY leave on (['Y-m-d'=>true]).
     * The single source every surface consults to SUPPRESS late/overtime for a half-day (owner's
     * rule: a half-day counts no lateness and no overtime, and never shows in daily issues).
     * Read-only + non-mutating — undoing the half-day instantly restores the real lateness, because
     * nothing was written onto the attendance row.
     */
    public function halfDayDates(int $userId, string $from, string $to): array
    {
        $out = [];
        try {
            $rows = DB::table('t_req_master as r')
                ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
                ->where('c.category_code', 'leave')
                ->where('r.requester_user_id', $userId)
                ->whereIn('r.status', ['approved', 'pending'])
                ->where('r.leave_type', 'half_day')
                ->where('r.leave_start_date', '<=', $to)
                ->where('r.leave_end_date', '>=', $from)
                ->select('r.leave_start_date', 'r.leave_end_date')
                ->get();
            foreach ($rows as $lv) {
                $ls = max($from, substr((string) $lv->leave_start_date, 0, 10));
                $le = min($to, substr((string) $lv->leave_end_date, 0, 10));
                for ($c = new \DateTime($ls); $c <= new \DateTime($le); $c->modify('+1 day')) {
                    $out[$c->format('Y-m-d')] = true;
                }
            }
        } catch (\Throwable $e) { /* none */ }
        return $out;
    }

    /**
     * Taken leave DAYS in the CURRENT cycle split into emergency (same-day, leave_type='emergency')
     * vs planned (everything else). Counted per distinct date (no double-count); a date that is both
     * is counted as emergency. Powers the "planned vs emergency" line on the manager detail screen.
     */
    public function takenByType(int $userId): array
    {
        $cycle = $this->cycle();
        $emergency = [];
        $planned = [];
        try {
            $rows = DB::table('t_req_master as r')
                ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
                ->where('c.category_code', 'leave')
                ->where('r.requester_user_id', $userId)
                ->where('r.status', 'approved')
                ->where('r.leave_start_date', '<=', $cycle['end'])
                ->where('r.leave_end_date', '>=', $cycle['start'])
                ->select('r.leave_start_date', 'r.leave_end_date', 'r.leave_type')
                ->get();
            foreach ($rows as $lv) {
                $ls = max($cycle['start'], substr((string) $lv->leave_start_date, 0, 10));
                $le = min($cycle['end'], substr((string) $lv->leave_end_date, 0, 10));
                $type = strtolower((string) $lv->leave_type);
                if ($type === 'half_day') { continue; } // partial — shown in taken_total, not the planned/emergency split
                $isEmerg = $type === 'emergency';
                for ($c = new \DateTime($ls); $c <= new \DateTime($le); $c->modify('+1 day')) {
                    $d = $c->format('Y-m-d');
                    if ($isEmerg) { $emergency[$d] = true; } else { $planned[$d] = true; }
                }
            }
            foreach (array_keys($emergency) as $d) { unset($planned[$d]); } // emergency wins a shared date
        } catch (\Throwable $e) { /* none */ }
        return ['planned' => count($planned), 'emergency' => count($emergency)];
    }

    /**
     * How many SAME-DAY (emergency) applications the rider has made this cycle — the cap
     * counter. Counts REQUESTS (rows), not days, and includes pending ones (a pending
     * same-day request has already used a slot). Marked by leave_type='emergency' at apply.
     */
    public function samedayUsed(int $userId, string $start, string $end): int
    {
        try {
            return (int) DB::table('t_req_master as r')
                ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
                ->where('c.category_code', 'leave')
                ->where('r.requester_user_id', $userId)
                ->where('r.leave_type', 'emergency')
                ->whereIn('r.status', ['approved', 'pending'])
                ->whereBetween('r.leave_start_date', [$start, $end])
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Net ledger adjustment (grants − penalties) applying to the cycle. */
    public function ledgerAdjustment(int $userId, string $start, string $end): float
    {
        if (!$this->grantTableExists()) { return 0.0; }
        try {
            // A row belongs to the cycle by its effective_date, else by created_at date.
            return (float) DB::table('t_hr_leave_grant')
                ->where('user_id', $userId)
                ->whereRaw('COALESCE(effective_date, DATE(created_at)) BETWEEN ? AND ?', [$start, $end])
                ->sum('days');
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /**
     * The ledger adjustment split by SOURCE (overtime / late_penalty / manual / half_day),
     * so surfaces can show WHERE a rider's extra/missing leaves came from instead of one
     * opaque number. Signed (overtime + / late_penalty −). Same cycle-membership rule as
     * ledgerAdjustment() — their totals always reconcile.
     */
    public function adjustmentBySource(int $userId, string $start, string $end): array
    {
        // 'absence_cover' (Sep-2026): bonus days spent settling PARKED absences instead of
        // becoming leave. Negative. Listed here so it shows as itself — an unlabelled source
        // still lands in the total via ledgerAdjustment(), which would leave the employee
        // looking at a balance he cannot account for.
        $out = ['overtime' => 0.0, 'late_penalty' => 0.0, 'manual' => 0.0, 'half_day' => 0.0, 'absence_cover' => 0.0];
        if (!$this->grantTableExists()) { return $out; }
        try {
            $rows = DB::table('t_hr_leave_grant')
                ->where('user_id', $userId)
                ->whereRaw('COALESCE(effective_date, DATE(created_at)) BETWEEN ? AND ?', [$start, $end])
                ->selectRaw('source, SUM(days) as total')
                ->groupBy('source')
                ->pluck('total', 'source');
            foreach ($rows as $src => $total) {
                $out[$src] = ($out[$src] ?? 0.0) + (float) $total;
            }
        } catch (\Throwable $e) { /* none */ }
        return $out;
    }

    /**
     * Dated, attributed leave-adjustment history for a rider in the current cycle — the raw
     * t_hr_leave_grant rows joined to the actor's name. Powers the "who/when/why" history
     * sheet on both the rider self-view and the manager screens. Newest first.
     */
    public function adjustments(int $userId): array
    {
        if (!$this->grantTableExists()) { return []; }
        $cycle = $this->cycle();
        try {
            return DB::table('t_hr_leave_grant as g')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'g.created_by')
                ->where('g.user_id', $userId)
                // A 0-day row is not an adjustment — it is payroll's record of a WAIVED leave
                // action (see PayrollService::recordLeaveDecision), occupying the dedupe key so
                // the decision can't drift back to "pending". It moves no balance, and listing
                // it here would print "+0 leave" on every history surface (web, both mobile
                // sheets, the rider's own screen — including already-shipped APKs).
                ->where('g.days', '!=', 0)
                ->whereRaw('COALESCE(g.effective_date, DATE(g.created_at)) BETWEEN ? AND ?', [$cycle['start'], $cycle['end']])
                ->orderByRaw('COALESCE(g.effective_date, DATE(g.created_at)) DESC')
                ->orderBy('g.id', 'desc')
                ->get(['g.days', 'g.source', 'g.reason', 'g.effective_date', 'g.created_at', 'u.fullname as by_name'])
                ->map(fn ($g) => [
                    'days'    => (float) $g->days,
                    'source'  => $g->source,
                    'reason'  => $g->reason,
                    // ⏱ Oct-2026 — WHERE the leave came from, in the card's words, so a leave
                    // history row explains itself ("Time bank Sep 2026 · taken for late").
                    'label'   => self::grantLabel((string) $g->source, (string) $g->reason),
                    // The month whose card produced it (time-bank rows) — surfaces link to it.
                    'bank_month' => str_starts_with((string) $g->reason, 'Time bank ')
                        ? date('Y-m', strtotime('1 ' . substr((string) $g->reason, 10))) : null,
                    'date'    => $g->effective_date ? substr((string) $g->effective_date, 0, 10)
                        : ($g->created_at ? substr((string) $g->created_at, 0, 10) : null),
                    'by_name' => $g->by_name,
                ])->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** One label per leave-ledger row — same words as the time-bank card on every surface. */
    public static function grantLabel(string $source, string $reason): string
    {
        if (str_starts_with($reason, 'Time bank ')) {
            return $reason . ($source === 'late_penalty' ? ' · taken for late' : ($source === 'overtime' ? ' · given for overtime' : ''));
        }
        return match ($source) {
            'overtime'      => 'From overtime' . ($reason !== '' ? ' · ' . $reason : ''),
            'late_penalty'  => 'Taken for late' . ($reason !== '' ? ' · ' . $reason : ''),
            'absence_cover' => $reason !== '' ? $reason : 'Covered a parked absence',
            'half_day'      => 'Half day' . ($reason !== '' ? ' · ' . $reason : ''),
            default         => $reason !== '' ? $reason : 'Manual adjustment',
        };
    }

    /**
     * Full balance snapshot for a rider in the CURRENT cycle. Every surface renders from
     * this so the number is identical everywhere.
     */
    public function balance(int $userId): array
    {
        $cycle = $this->cycle();
        $quota = $this->quotaTotal();
        $extra = $this->ledgerAdjustment($userId, $cycle['start'], $cycle['end']);
        $taken = $this->takenDays($userId, $cycle['start'], $cycle['end']);
        $samedayUsed = $this->samedayUsed($userId, $cycle['start'], $cycle['end']);
        $samedayCap = $this->samedayCap();
        $bySource = $this->adjustmentBySource($userId, $cycle['start'], $cycle['end']);

        $effectiveQuota = $quota + $extra;          // extra can be negative (penalties)
        $remaining = $effectiveQuota - $taken;

        return [
            'quota_total'      => round($quota, 1),
            'extra_granted'    => round($extra, 1),
            // Segregated so surfaces can show "+N earned from overtime" / "−N late" separately.
            'earned_overtime'  => round($bySource['overtime'] ?? 0, 1),
            'late_penalties'   => round($bySource['late_penalty'] ?? 0, 1),   // negative
            'manual_adjust'    => round(($bySource['manual'] ?? 0) + ($bySource['half_day'] ?? 0), 1),
            // Bonus days used to settle parked absences rather than becoming leave (negative).
            'absence_cover'    => round($bySource['absence_cover'] ?? 0, 1),
            'effective_quota'  => round($effectiveQuota, 1),
            'taken_total'      => round($taken, 1),
            'remaining'        => round($remaining, 1),
            'sameday_used'     => $samedayUsed,
            'sameday_cap'      => $samedayCap,
            'sameday_left'     => max(0, $samedayCap - $samedayUsed),
            'sameday_cutoff'   => $this->samedayCutoff(),
            'cycle_start'      => $cycle['start'],
            'cycle_end'        => $cycle['end'],
        ];
    }

    /**
     * Validate a self-service leave application against the policy. Returns
     * ['ok'=>bool, 'message'=>?string, 'is_sameday'=>bool]. Server-authoritative — both the
     * web and mobile apply paths call this so an old app build can't bypass a rule.
     *
     * @param string $start  Y-m-d leave start
     * @param string $end    Y-m-d leave end
     * @param string $today  Y-m-d "now" date (injected for testability)
     * @param string $nowHm  HH:MM local time now
     */
    public function validateApplication(int $userId, string $start, string $end, string $today, string $nowHm): array
    {
        $days = (new \DateTime($start))->diff(new \DateTime($end))->days + 1;
        $isSameday = ($start <= $today); // starting today (or, defensively, earlier) = same-day ask
        $bal = $this->balance($userId);

        // Advance leave must be for tomorrow onward.
        if (!$isSameday && $start <= $today) {
            return ['ok' => false, 'message' => 'Please choose a future date for an advance leave.', 'is_sameday' => false];
        }

        if ($isSameday) {
            if ($nowHm > $this->samedayCutoff()) {
                return ['ok' => false, 'message' => 'Same-day (emergency) leave is not allowed after ' . $this->samedayCutoff() . '. Please contact your manager.', 'is_sameday' => true];
            }
            if ($bal['sameday_left'] <= 0) {
                return ['ok' => false, 'message' => 'You have used all ' . $bal['sameday_cap'] . ' emergency (same-day) leaves this year. Please contact your manager.', 'is_sameday' => true];
            }
        }

        if ($days > $bal['remaining']) {
            $rem = $bal['remaining'];
            return ['ok' => false, 'message' => 'This needs ' . $days . ' leave' . ($days > 1 ? 's' : '') . ' but you have ' . ($rem > 0 ? $rem : 0) . ' left this year. Please ask your manager.', 'is_sameday' => $isSameday];
        }

        return ['ok' => true, 'message' => null, 'is_sameday' => $isSameday];
    }
}
