<?php

namespace App\Services\HR;

use Illuminate\Support\Facades\DB;

/**
 * TARGET-BASED overtime for DISPLAY (Phase F preview / manager reporting): the minutes a
 * rider worked BEYOND the configured shift length (SHIFT_TARGET_HOURS). Worked = logout −
 * login on a day the rider actually checked out.
 *
 * NOTE: this is deliberately SEPARATE from `t_ops_attendance.overtime_minutes` (which is
 * shift-end based and feeds salary). Overtime here is NOT paid — it accumulates toward bonus
 * leaves (Phase F). Touching this never affects salary.
 */
class OvertimeService
{
    private ?float $targetHoursMemo = null;

    /** 'uid|date' → last-delivered epoch (or null). A month walk asks per day. */
    private array $lastDeliveryMemo = [];

    private ?bool $unlockColMemo = null;

    /** Is the manager-unlock column deployed? (Guarded — dev DBs may lag.) */
    public function hasUnlockColumn(): bool
    {
        if ($this->unlockColMemo === null) {
            try {
                $this->unlockColMemo = \Illuminate\Support\Facades\Schema::hasColumn(
                    't_ops_attendance', 'checkout_unlock_until');
            } catch (\Throwable $e) {
                $this->unlockColMemo = false;
            }
        }
        return $this->unlockColMemo;
    }

    /**
     * ⭐ DID THIS CHECKOUT RIDE A MANAGER UNLOCK? (owner ruling, Aug-8)
     *
     * TIGHT on purpose: the logout must fall INSIDE the unlock window. The
     * "bypassed" chip on the attendance page uses a looser test (unlock row +
     * any logout), which also matches a rider who was unlocked at 14:00 but then
     * checked out NORMALLY at 21:30 after the unlock expired — and the owner's
     * rule is explicit that a regular checkout, wherever it happens, keeps its
     * real time. Only a checkout that actually USED the valve is re-based.
     *
     * Logout is a bare TIME on the row; past-midnight rolls to the next day
     * (same rule as dailyOvertimeMinutes). 2 min slack for clock skew.
     */
    public function bypassedCheckout(string $date, ?string $login, ?string $logout, $unlockUntil): bool
    {
        if (empty($logout) || empty($unlockUntil)) {
            return false;
        }
        $o = strtotime($date . ' ' . $logout);
        if ($o === false) {
            return false;
        }
        if (!empty($login)) {
            $l = strtotime($date . ' ' . $login);
            if ($l !== false && $o < $l) {
                $o += 86400;
            }
        }
        $u = strtotime((string) $unlockUntil);
        return $u !== false && $o <= $u + 120;
    }

    /**
     * When this rider DELIVERED his last order that day (epoch), or null.
     * The same join CheckoutClassifierService trusts for "away on a delivery".
     */
    public function lastDeliveryTs(int $userId, string $date): ?int
    {
        $key = $userId . '|' . $date;
        if (array_key_exists($key, $this->lastDeliveryMemo)) {
            return $this->lastDeliveryMemo[$key];
        }
        $ts = null;
        try {
            $t = DB::table('t_crm_order_status_history as h')
                ->join('t_crm_prod_order as o', 'o.id', '=', 'h.order_id')
                ->where('o.assigned_rider_user_id', $userId)
                ->where('h.status_code', 'delivered')
                ->whereDate('h.changed_at', $date)
                ->max('h.changed_at');
            $ts = $t ? (strtotime((string) $t) ?: null) : null;
        } catch (\Throwable $e) {
            $ts = null;
        }
        return $this->lastDeliveryMemo[$key] = $ts;
    }

    /**
     * ⭐⭐ THE OT-COUNTABLE END OF A DAY (epoch), owner ruling Aug-8.
     *
     * A normal checkout — at the office, at the last drop, wherever — keeps its
     * real time: that is the day as worked. A checkout that rode a manager
     * unlock is different: the timestamp is when the VALVE was used, not when
     * the work ended (the live case: a rider bypassed at 00:05 was credited
     * 13h13m and ~4h of bonus-leave overtime for a day that ended with his last
     * drop hours earlier). For those days the end becomes the LAST DELIVERED
     * ORDER — "that's when he was supposed to" — clamped to never exceed the
     * actual logout. A bypassed day with NO deliveries returns null: nothing
     * provable happened past the shift, so nothing is counted.
     */
    public function otEndTs(int $userId, string $date, ?string $login, ?string $logout, $unlockUntil): ?int
    {
        if (empty($logout)) {
            return null;
        }
        $o = strtotime($date . ' ' . $logout);
        if ($o === false) {
            return null;
        }
        if (!empty($login)) {
            $l = strtotime($date . ' ' . $login);
            if ($l !== false && $o < $l) {
                $o += 86400;
            }
        }

        if (!$this->bypassedCheckout($date, $login, $logout, $unlockUntil)) {
            return $o;                        // a real checkout keeps its real time
        }

        $last = $this->lastDeliveryTs($userId, $date);
        if ($last === null) {
            return null;                      // bypassed + nothing delivered → no measurable OT
        }
        return min($last, $o);                // never later than the actual logout
    }

    /**
     * ⭐ DISPLAY payload for a checkout the OT engine RE-BASED (owner ruling Aug-8) —
     * so every screen can SHOW the end the day was actually counted to, instead of
     * leaving the manager to reconcile a "bypassed" chip with an OT number that
     * doesn't match the logout. ONE brain: today view, the employee-detail modal
     * and the mobile day-checks chip all read this.
     *
     * null  = the checkout kept its real time (normal checkout, or an unlock that
     *         expired before a regular checkout) → show nothing extra.
     * time  = 'H:i' of the counted end (the last delivered order, clamped to the
     *         real logout); null time with basis 'no_deliveries' = bypassed day
     *         with nothing delivered → nothing past the shift is counted.
     */
    public function countedCheckout(int $userId, string $date, ?string $login, ?string $logout, $unlockUntil): ?array
    {
        if (!$this->bypassedCheckout($date, $login, $logout, $unlockUntil)) {
            return null;
        }
        $end = $this->otEndTs($userId, $date, $login, $logout, $unlockUntil);
        return [
            'time'  => $end !== null ? date('H:i', $end) : null,
            'basis' => $end !== null ? 'last_delivery' : 'no_deliveries',
        ];
    }

    /** A stored time ('10:52:41', or a full datetime) → 'H:i'. null when unreadable. */
    private function hm(?string $t): ?string
    {
        if ($t === null || $t === '') { return null; }
        // First H:MM in the string — works for a bare TIME and for a datetime alike.
        if (preg_match('/(\d{1,2}):(\d{2})/', (string) $t, $m)) {
            return str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2];
        }
        return null;
    }

    private function targetHours(): float
    {
        if ($this->targetHoursMemo === null) {
            try {
                $v = \App\Services\HR\ConfigMemo::get('SHIFT_TARGET_HOURS');
                $this->targetHoursMemo = ($v !== null && $v !== '') ? (float) $v : 9.0;
            } catch (\Throwable $e) {
                $this->targetHoursMemo = 9.0;
            }
        }
        return $this->targetHoursMemo;
    }

    /** Overtime minutes for one day from its login/logout TIME strings (0 when not checked out). */
    public function dailyOvertimeMinutes(?string $login, ?string $logout): int
    {
        if (empty($login) || empty($logout)) {
            return 0;
        }
        $l = strtotime('2000-01-01 ' . $login);
        $o = strtotime('2000-01-01 ' . $logout);
        if ($l === false || $o === false) {
            return 0;
        }
        if ($o < $l) {
            $o += 86400; // logout after midnight → next day
        }
        $workedMin = ($o - $l) / 60;
        $targetMin = $this->targetHours() * 60;
        return $workedMin > $targetMin ? (int) round($workedMin - $targetMin) : 0;
    }

    /**
     * Per-day delivery stats for a range: ['Y-m-d' => ['orders'=>n, 'first'=>'H:i', 'last'=>'H:i']].
     * Context for "was this day busy enough to have earned overtime?".
     *
     * ⭐ Deliberately the SAME attribution as lastDeliveryTs() — `t_crm_prod_order`
     * `.assigned_rider_user_id` — because on a BYPASSED day the drill prints "counted <time> ·
     * last delivery" right beside these numbers. Sourcing them differently would let the block
     * contradict itself (measured on the replica: `t_ops_order_rider_history` disagrees on ~3%
     * of days, once by 1h40m on the last-delivery time, and can miss an order entirely).
     * One query for the whole range.
     */
    public function deliveryStatsForRange(int $userId, string $start, string $end): array
    {
        $out = [];
        try {
            $rows = DB::table('t_crm_order_status_history as h')
                ->join('t_crm_prod_order as o', 'o.id', '=', 'h.order_id')
                ->where('o.assigned_rider_user_id', $userId)
                ->where('h.status_code', 'delivered')
                ->whereBetween(DB::raw('DATE(h.changed_at)'), [$start, $end])
                ->groupBy(DB::raw('DATE(h.changed_at)'))
                ->selectRaw('DATE(h.changed_at) as d, COUNT(DISTINCT h.order_id) as orders,'
                    . ' MIN(h.changed_at) as first_at, MAX(h.changed_at) as last_at')
                ->get();
            foreach ($rows as $r) {
                $out[substr((string) $r->d, 0, 10)] = [
                    'orders' => (int) $r->orders,
                    'first'  => $this->hm($r->first_at),
                    'last'   => $this->hm($r->last_at),
                ];
            }
        } catch (\Throwable $e) { /* context only — never break the figure it decorates */ }
        return $out;
    }

    /**
     * Overtime across [$start,$end]. Returns
     *   ['total'=>minutes, 'dates'=>['Y-m-d'=>minutes], 'details'=>['Y-m-d'=>[...]]].
     * Only days with BOTH a login and a logout count (you can't measure OT without a checkout).
     *
     * ⭐ `details` is built in the SAME loop as `dates` — so the times a drill-down shows can
     * never disagree with the minutes beside them. Per day it carries the check-in, the
     * checkout AS RECORDED, the `counted` payload when that checkout rode a manager bypass
     * (see countedCheckout), and the worked/target minutes the OT figure came from.
     * Additive: existing callers read only 'total'/'dates' and are unaffected.
     */
    /**
     * (user|start|end|deliveries) => result. Per-request only.
     *
     * ⚠⚠ Sep-6 2026 — added because the payroll grid now walks this range TWICE per employee
     * (once for the month's minutes, once for the review standing), and the loop inside issues
     * a last-delivery query for every bypassed day. Unmemoised that doubled the page's query
     * count. It is a pure read of frozen attendance, so caching it for one request is safe —
     * `forgetRanges()` drops it whenever something that feeds it is written.
     */
    private static array $rangeMemo = [];

    /** Drop the per-request range cache (a review or an attendance edit changed an input). */
    public static function forgetRanges(): void
    {
        self::$rangeMemo = [];
    }

    public function overtimeForRange(int $userId, string $start, string $end, bool $withDeliveries = false): array
    {
        $memoKey = $userId . '|' . $start . '|' . $end . '|' . ($withDeliveries ? 'd' : '');
        if (isset(self::$rangeMemo[$memoKey])) {
            return self::$rangeMemo[$memoKey];
        }
        $total = 0;
        $dates = [];
        $details = [];
        try {
            $cols = ['attendance_date', 'login_time', 'logout_time'];
            if ($this->hasUnlockColumn()) {
                $cols[] = 'checkout_unlock_until';   // the bypass tell (guarded — dev DBs may lag)
            }
            $rows = DB::table('t_ops_attendance')->where('user_id', $userId)
                ->whereBetween('attendance_date', [$start, $end])
                ->whereNotNull('login_time')->where('login_time', '!=', '')
                ->whereNotNull('logout_time')->where('logout_time', '!=', '')
                ->get($cols);
            // A HALF-DAY counts no overtime (owner's rule) — mirror the late/OT suppression in
            // sumLateOvertimeMinutes so target-OT (and its bonus-leave accrual) can't reward
            // a day the rider only half-worked.
            $halfDays = (new \App\Services\HR\LeavePolicyService())->halfDayDates($userId, $start, $end);
            // Resolved once for the whole range. Its own per-user cache means the per-day
            // lookups below cost no queries; null when the feature is not installed yet.
            $reviewSvc = null;
            try {
                $svc = app(DayReviewService::class);
                if ($svc->enabled()) { $reviewSvc = $svc; }
            } catch (\Throwable $e) { $reviewSvc = null; }
            foreach ($rows as $r) {
                $date = substr((string) $r->attendance_date, 0, 10);
                if (isset($halfDays[$date])) { continue; }
                // ⭐ The day ends when the WORK ended (owner ruling Aug-8): a checkout
                //   that rode a manager unlock is re-based to the last delivered order;
                //   a normal checkout keeps its real time. null = nothing countable.
                $unlock = $r->checkout_unlock_until ?? null;
                $endTs = $this->otEndTs($userId, $date, $r->login_time, $r->logout_time, $unlock);
                if ($endTs === null) { continue; }
                $l = strtotime($date . ' ' . $r->login_time);
                if ($l === false || $endTs <= $l) { continue; }
                $workedMin = ($endTs - $l) / 60;
                $targetMin = $this->targetHours() * 60;
                $ot = $workedMin > $targetMin ? (int) round($workedMin - $targetMin) : 0;
                if ($ot > 0) {
                    // ⭐⭐ THE DAY REVIEW HOOK (Sep-6 2026). A manager can verify this figure,
                    // adjust it, or say the day was not overtime at all. With no review — the
                    // normal case, and the only case before the feature is switched on —
                    // `effective` IS `$ot`, so every number downstream is untouched. That is
                    // the owner's rule: an unreviewed day counts in full.
                    $review = $reviewSvc ? $reviewSvc->reviewFor($userId, $date, 'overtime') : null;
                    $effective = $review === null ? $ot : max(0, (int) $review['effective_minutes']);

                    if ($effective > 0) {
                        $total += $effective;
                        $dates[$date] = $effective;
                    }
                    // ⚠ The evidence is kept whenever the day RAW-earned overtime, even when a
                    // verdict took it to zero — otherwise a day a manager judged disappears from
                    // the drill entirely and he cannot see (or undo) what he decided.
                    // `counted` is null for a normal checkout (incl. one made after the unlock
                    // expired, which keeps its real time) — the tight bypassedCheckout test decides.
                    $details[$date] = [
                        'minutes'        => $effective,
                        'raw_minutes'    => $ot,
                        'login'          => $this->hm($r->login_time),
                        'logout'         => $this->hm($r->logout_time),
                        'counted'        => $this->countedCheckout($userId, $date, $r->login_time, $r->logout_time, $unlock),
                        'worked_minutes' => (int) round($workedMin),
                        'target_minutes' => (int) round($targetMin),
                        'review'         => $review === null ? null : [
                            'verdict' => $review['verdict'],
                            'by'      => $review['reviewed_by'],
                            'at'      => $review['reviewed_at'],
                            'reason'  => $review['reason'],
                        ],
                    ];
                }
            }
            ksort($dates);
            ksort($details);

            // How busy the day was — opt-in, because the payroll/attendance TOTALS call this
            // per employee and would pay for a query they never read. Only the drill-downs,
            // which actually show the numbers, ask for them.
            if ($withDeliveries && $details) {
                $deliv = $this->deliveryStatsForRange($userId, $start, $end);
                foreach ($details as $d => $meta) {
                    $s = $deliv[$d] ?? null;
                    $details[$d]['orders'] = $s['orders'] ?? 0;
                    $details[$d]['first_delivery'] = $s['first'] ?? null;
                    $details[$d]['last_delivery'] = $s['last'] ?? null;
                }
            }
        } catch (\Throwable $e) { /* no data → zero */ }
        return self::$rangeMemo[$memoKey] = ['total' => $total, 'dates' => $dates, 'details' => $details];
    }

    /** Just the total minutes across [$start,$end]. */
    public function overtimeMinutes(int $userId, string $start, string $end): int
    {
        return $this->overtimeForRange($userId, $start, $end)['total'];
    }

    /**
     * Overtime minutes → whole BONUS LEAVE DAYS. One full target day of extra work earns
     * one day off; the remainder does not carry over.
     *
     * ⭐ THE one implementation. PayrollService::grantOnce() writes leave grants from this,
     * and every attendance/report screen displays this — so what a manager is shown and what
     * payroll actually grants cannot drift apart. If the rule ever changes (part-days,
     * carry-over, a different divisor), change it HERE and nowhere else.
     */
    public function bonusLeaves(int $overtimeMinutes): int
    {
        if ($overtimeMinutes <= 0) { return 0; }
        $perDay = max(1, (int) round($this->targetHours() * 60));
        return (int) floor($overtimeMinutes / $perDay);
    }

    /**
     * Bonus days for ONE employee's month, CARRY-AWARE — the number every screen must show.
     *
     * `bonusLeaves()` above is the raw converter (floor of minutes ÷ target). Since Sep-2026
     * the minutes left under the line CARRY into the next month (OvertimeCarryService), so a
     * month's real bonus days = floor((carried-in + own) ÷ target). Payroll grants from that;
     * the attendance month tab, the report tile and the rider's phone read THIS so they can
     * never disagree with the payslip — the owner's "one engine" rule.
     *
     * A range that is not exactly one calendar month (a custom report window) has no carry
     * semantics, so it falls back to the raw conversion. The open month is fine: its end is
     * clipped to today, still inside the month.
     */
    public function bonusLeavesFor(int $userId, string $start, string $end, int $overtimeMinutes): int
    {
        $s = substr((string) $start, 0, 10);
        $e = substr((string) $end, 0, 10);
        $month = substr($s, 0, 7);
        $wholeMonth = $s === ($month . '-01') && substr($e, 0, 7) === $month;
        if ($wholeMonth) {
            // ⏱ Time-bank month (Oct-2026): the bank owns the answer — what was GIVEN once
            // decided, otherwise the suggested extra days (extra covering late first). Every
            // board that calls this therefore shows the payroll card's number.
            $tb = new TimeBankService();
            // A month the OLD panel already turned into leave keeps its old figure (the bank
            // takes nothing from that kind) — only un-decided months answer from the bank.
            if ($tb->covers($userId, $month) && !$tb->legacyOtActed($userId, $month)) {
                $s = $tb->month($userId, $month);
                if (($s['status'] ?? '') === 'decided') {
                    return ($s['decision']['ot'] ?? null) === 'give' ? (int) ($s['decision']['ot_days'] ?? 0) : 0;
                }
                return (int) ($s['net']['ot_days'] ?? 0);
            }
            return (new OvertimeCarryService())->preview($userId, $month, $overtimeMinutes)['days'];
        }
        return $this->bonusLeaves($overtimeMinutes);
    }

    /** Minutes still to run before the NEXT bonus day (0 when none are being earned). */
    public function minutesToNextBonus(int $overtimeMinutes): int
    {
        if ($overtimeMinutes <= 0) { return 0; }
        $perDay = max(1, (int) round($this->targetHours() * 60));
        return $perDay - ($overtimeMinutes % $perDay);
    }

    /** The divisor itself, so a screen can explain "540 min = 1 day" without re-deriving it. */
    public function minutesPerBonusDay(): int
    {
        return max(1, (int) round($this->targetHours() * 60));
    }
}
