<?php

namespace App\Services\HR;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Day review — verifying overtime and waiving late minutes on the day they happen.
 * (September 2026. Plan: DAY-REVIEW-AND-LEAVE-PANEL-PLAN-SEP2026.md)
 *
 * ⭐⭐ THE RULE THIS WHOLE CLASS IS BUILT AROUND:
 *     an UNREVIEWED day counts IN FULL, exactly as it did before this existed.
 * Every read path here returns "no adjustment" when there is no live row, so a manager
 * who never opens the queue changes nothing. Only `adjusted` / `waived` move a number.
 * That is the owner's rule: a rider is never penalised for a manager's negligence.
 *
 * ⭐ It is also why every entry point is `Schema::hasTable`-guarded: before the SQL runs on
 * prod, this class is inert and the engines behave exactly as they do today.
 *
 * The supersede rule is what makes a verdict honest. `source_hash` fingerprints the
 * attendance fields the figure was derived from. Edit the day afterwards — type a new
 * checkout, grant or clear a bypass — and the hash stops matching: the review is marked
 * superseded, stops affecting any number, and the day returns to the queue flagged. A
 * verified day can never be quietly edited out from under its verdict.
 */
class DayReviewService
{
    /** Overtime is judged as a whole figure; lateness is forgiven in minutes. */
    public const KINDS    = ['overtime', 'late'];
    public const VERDICTS = ['verified', 'adjusted', 'waived'];

    /** Per-request cache of the table-exists check (null = not looked yet). */
    private static ?bool $hasTable = null;

    /**
     * All live reviews for one user, keyed "Y-m-d|kind". Loaded ONCE per user per request.
     * ⚠ Never query per day — `lateForDay()` runs inside per-day loops on every attendance
     * screen, so a per-day lookup here would be a query per row on every payroll page.
     * Mirrors AbsenceDecisionService::rows(), the same shape for the same reason.
     * @var array<int, array<string, array>>
     */
    private static array $memo = [];

    public function enabled(): bool
    {
        if (self::$hasTable === null) {
            try { self::$hasTable = Schema::hasTable('t_hr_day_review'); }
            catch (\Throwable $e) { self::$hasTable = false; }
        }
        return self::$hasTable;
    }

    /** First reviewable day. Nothing before it is queued, decidable, or counted. */
    public function startDate(): string
    {
        // ⚠ ConfigMemo::get() takes ONE argument and returns null when the key is absent —
        // it has no default parameter. Before the SQL runs on prod there is no row, so the
        // fallback here is what keeps the whole feature inert rather than reaching back
        // into history with an empty start date.
        $v = (string) (ConfigMemo::get('DAY_REVIEW_START') ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '2026-09-01';
    }

    /** Drop the cache for one user (or everyone) after a write. */
    public function forget(?int $userId = null): void
    {
        if ($userId === null) { self::$memo = []; }
        else { unset(self::$memo[$userId]); }
        // ⚠ The item list embeds each day's verdict, so it is stale the moment a review is
        // written. Dropping only $memo would leave the chips showing the OLD standing.
        self::$itemMemo = [];
        self::$dayMemo = [];
        self::$currentMemo = [];
        // The overtime engine caches ranges per request and those embed the verdicts too.
        OvertimeService::forgetRanges();
        // ⭐ And the bulb's cross-request count, so a manager who has just checked a day
        // watches the number fall instead of waiting out the poll interval.
        try { \Illuminate\Support\Facades\Cache::forget(self::COUNT_CACHE_KEY); }
        catch (\Throwable $e) { /* no cache store — nothing to drop */ }
    }

    // ───────────────────────────────────────────────────────────────────────────
    // Reading
    // ───────────────────────────────────────────────────────────────────────────

    /** Every LIVE (non-superseded) review for a user, keyed "date|kind". */
    private function rows(int $userId): array
    {
        if (!isset(self::$memo[$userId])) {
            $out = [];
            if ($this->enabled()) {
                try {
                    $rs = DB::table('t_hr_day_review as d')
                        ->leftJoin('t_sys_user as u', 'u.id', '=', 'd.reviewed_by')
                        ->where('d.user_id', $userId)
                        ->whereNull('d.superseded_at')
                        ->get([
                            'd.id', 'd.review_date', 'd.kind', 'd.computed_minutes', 'd.verdict',
                            'd.effective_minutes', 'd.waived_minutes', 'd.reason', 'd.source_hash',
                            'd.reviewed_at', 'u.fullname as reviewed_by_name',
                        ]);
                    foreach ($rs as $r) {
                        $date = substr((string) $r->review_date, 0, 10);
                        $out[$date . '|' . $r->kind] = [
                            'id'                => (int) $r->id,
                            'date'              => $date,
                            'kind'              => (string) $r->kind,
                            'computed_minutes'  => (int) $r->computed_minutes,
                            'verdict'           => (string) $r->verdict,
                            'effective_minutes' => (int) $r->effective_minutes,
                            'waived_minutes'    => (int) $r->waived_minutes,
                            'reason'            => $r->reason,
                            'source_hash'       => (string) $r->source_hash,
                            'reviewed_by'       => $r->reviewed_by_name,
                            'reviewed_at'       => $r->reviewed_at ? substr((string) $r->reviewed_at, 0, 10) : null,
                        ];
                    }
                } catch (\Throwable $e) { $out = []; }
            }
            self::$memo[$userId] = $out;
        }
        return self::$memo[$userId];
    }

    /**
     * The live review for one day, or null. Returns null for anything before the start
     * date, so switching the feature on can never reach back into settled history.
     */
    public function reviewFor(int $userId, string $date, string $kind, bool $includeStale = false): ?array
    {
        $date = substr($date, 0, 10);
        if ($date < $this->startDate()) { return null; }
        $r = $this->rows($userId)[$date . '|' . $kind] ?? null;
        /**
         * ⚠⚠ A STALE REVIEW MOVES NO NUMBER (5-Oct-2026 audit). A review whose day was edited
         *    afterwards — a back-dated shift re-stamp, a CSV import, any path that rewrote the
         *    check-in or the frozen minutes without calling supersedeIfChanged() — used to keep
         *    reducing pay while the queue showed the same day as "pending, 0 waived". Kanan:
         *    103 late, 90 waived, re-stamped to 150 → the queue said pending, payroll counted
         *    60. Now every reader sees what the queue sees: an out-of-date verdict is no
         *    verdict. Only the two places that must see it anyway (the supersede check and the
         *    queue card that flags it) ask for it with $includeStale.
         */
        if ($r !== null && !$includeStale && !$this->reviewIsCurrent($userId, $r)) {
            return null;
        }
        return $r;
    }

    /** user => date => bool. Per-request; dropped with the rest on forget(). */
    private static array $currentMemo = [];

    /**
     * Does this stored review still match its day? Reads the day through the per-user day
     * cache, loading ALL of this user's reviewed days in one query the first time — this runs
     * inside per-day payroll loops and must never become a query per day.
     */
    private function reviewIsCurrent(int $userId, array $r): bool
    {
        $date = $r['date'];
        $key = $date . '|' . $r['kind'];
        if (isset(self::$currentMemo[$userId][$key])) { return self::$currentMemo[$userId][$key]; }
        if (!isset(self::$dayMemo[$userId]) || !array_key_exists($date, self::$dayMemo[$userId])) {
            $dates = array_values(array_unique(array_column($this->rows($userId), 'date')));
            try {
                $found = DB::table('t_ops_attendance')
                    ->where('user_id', $userId)
                    ->whereIn('attendance_date', $dates)
                    ->get($this->attendanceCols());
                foreach ($dates as $d) {
                    if (!isset(self::$dayMemo[$userId]) || !array_key_exists($d, self::$dayMemo[$userId])) {
                        self::$dayMemo[$userId][$d] = null;
                    }
                }
                foreach ($found as $a) {
                    self::$dayMemo[$userId][substr((string) $a->attendance_date, 0, 10)] = $a;
                }
            } catch (\Throwable $e) { /* fall back to the per-day read below */ }
        }
        $row = $this->attendanceRow($userId, $date);
        return self::$currentMemo[$userId][$key] = $this->isFresh($r['kind'], $row, $r['source_hash']);
    }

    /**
     * Effective overtime minutes for a day, given what the engine computed.
     * No review (or the feature is off) → the computed figure, untouched.
     */
    public function effectiveOvertime(int $userId, string $date, int $computed): int
    {
        $r = $this->reviewFor($userId, $date, 'overtime');
        return $r === null ? $computed : max(0, (int) $r['effective_minutes']);
    }

    /**
     * Minutes forgiven on a late day. Never more than the day's own lateness, so a stale
     * waive (the day was later edited DOWN) can't push the month negative.
     */
    public function waivedLate(int $userId, string $date, int $computed): int
    {
        $r = $this->reviewFor($userId, $date, 'late');
        if ($r === null) { return 0; }
        return max(0, min($computed, (int) $r['waived_minutes']));
    }

    /**
     * Live reviews for MANY employees on ONE day, keyed "user_id|kind", in a single query.
     *
     * ⚠ For the Today board, which lists every rider. Going through `rows()` there would be a
     * query per rider on the busiest screen in the app; this is one.
     */
    public function reviewsOn(array $userIds, string $date): array
    {
        $out = [];
        $date = substr($date, 0, 10);
        if (!$this->enabled() || !$userIds || $date < $this->startDate()) { return $out; }
        try {
            $rs = DB::table('t_hr_day_review as d')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'd.reviewed_by')
                ->whereIn('d.user_id', $userIds)
                ->whereDate('d.review_date', $date)
                ->whereNull('d.superseded_at')
                ->get(['d.user_id', 'd.kind', 'd.verdict', 'd.effective_minutes',
                       'd.waived_minutes', 'd.reason', 'd.source_hash', 'u.fullname as reviewed_by_name']);
            // ⭐ Oct-2026: the board marker follows the same "a stale verdict is no verdict"
            // rule as the figures beside it — ONE more query for the whole board, not per rider.
            $days = [];
            if (count($rs)) {
                $days = DB::table('t_ops_attendance')
                    ->whereIn('user_id', $rs->pluck('user_id')->unique()->all())
                    ->whereDate('attendance_date', $date)
                    ->get(array_merge(['user_id'], $this->attendanceCols()))
                    ->keyBy('user_id');
            }
            foreach ($rs as $r) {
                if (!$this->isFresh((string) $r->kind, $days[$r->user_id] ?? null, (string) $r->source_hash)) {
                    continue;
                }
                $out[$r->user_id . '|' . $r->kind] = [
                    'verdict'   => (string) $r->verdict,
                    'effective' => (int) $r->effective_minutes,
                    'waived'    => (int) $r->waived_minutes,
                    'reason'    => $r->reason,
                    'by'        => $r->reviewed_by_name,
                ];
            }
        } catch (\Throwable $e) { $out = []; }
        return $out;
    }

    /** Reviews for a whole range, keyed "date|kind" — for drills and summaries. */
    public function forRange(int $userId, string $start, string $end): array
    {
        $out = [];
        foreach ($this->rows($userId) as $key => $r) {
            if ($r['date'] >= substr($start, 0, 10) && $r['date'] <= substr($end, 0, 10)
                && $r['date'] >= $this->startDate()
                // Same rule as reviewFor(): a verdict the day has since moved away from is
                // not shown as standing (the rider's own month reads this).
                && $this->reviewIsCurrent($userId, $r)) {
                $out[$key] = $r;
            }
        }
        return $out;
    }

    // ───────────────────────────────────────────────────────────────────────────
    // The fingerprint, and the supersede rule
    // ───────────────────────────────────────────────────────────────────────────

    /**
     * Fingerprint the attendance fields a day's figures are derived from. Anything that can
     * change overtime or lateness belongs in here — if it isn't, an edit to it would slip
     * past a verified day and silently change the money.
     */
    public function sourceHash(?object $row, ?string $kind = null): string
    {
        if (!$row) { return md5('missing'); }
        /**
         * ⚠⚠ LATENESS IS FINGERPRINTED ONLY BY WHAT LATENESS IS MADE OF (5-Oct-2026 prod).
         *    Shabib waived 90 of Kanan's 103 late minutes on 4-Oct at 17:18; Kanan checked out
         *    at 17:59. The checkout changed `logout_time`, the one shared fingerprint stopped
         *    matching, and the waive went back into the queue as "changed since review" — while
         *    the money engine kept applying it. A checkout cannot move a check-in, so the late
         *    fingerprint blanks the checkout-side fields. Same field order as the full hash, so
         *    a late review taken BEFORE checkout (those fields were empty then) matches it
         *    exactly — the existing rows heal without a data change.
         */
        $late = $kind === 'late';
        return md5(implode('|', [
            (string) ($row->login_time ?? ''),
            $late ? '' : (string) ($row->logout_time ?? ''),
            $late ? '' : (string) ($row->checkout_unlock_until ?? ''),
            (string) ($row->expected_shift_start ?? ''),
            (string) ($row->late_minutes ?? ''),
            $late ? '' : (string) ($row->overtime_minutes ?? ''),
        ]));
    }

    /**
     * Does a stored verdict still describe the day? The kind's own fingerprint, or — for a
     * late review written before 5-Oct-2026 with the full fingerprint — the full one.
     */
    private function isFresh(string $kind, ?object $row, string $stored): bool
    {
        return $stored === $this->sourceHash($row, $kind) || $stored === $this->sourceHash($row);
    }

    /** Cached answer to "does this database have the checkout-unlock columns yet". */
    private static ?bool $hasUnlockCols = null;

    /**
     * Every column the fingerprint and the readiness checks need.
     * ⚠ The hasColumn() answer is cached — it is an information_schema query, and this method
     * runs once per employee per page load.
     */
    private function attendanceCols(): array
    {
        $cols = ['id', 'attendance_date', 'login_time', 'logout_time',
                 'expected_shift_start', 'late_minutes', 'overtime_minutes'];
        if (self::$hasUnlockCols === null) {
            try { self::$hasUnlockCols = Schema::hasColumn('t_ops_attendance', 'checkout_unlock_until'); }
            catch (\Throwable $e) { self::$hasUnlockCols = false; }
        }
        if (self::$hasUnlockCols) {
            $cols[] = 'checkout_unlock_until';
            $cols[] = 'checkout_unlock_by';
            $cols[] = 'checkout_unlock_reason';
        }
        return $cols;
    }

    /**
     * The attendance row behind a day, with every column the fingerprint needs.
     * ⚠⚠ Reads through the per-user day cache. This used to be a query per item, which on the
     * payroll grid meant one query per reviewable DAY per employee — the exact N+1 shape the
     * page was optimised out of. Never turn this back into a bare SELECT.
     */
    public function attendanceRow(int $userId, string $date): ?object
    {
        $date = substr($date, 0, 10);
        if (isset(self::$dayMemo[$userId]) && array_key_exists($date, self::$dayMemo[$userId])) {
            return self::$dayMemo[$userId][$date];
        }
        try {
            $row = DB::table('t_ops_attendance')
                ->where('user_id', $userId)
                ->whereDate('attendance_date', $date)
                ->first($this->attendanceCols());
        } catch (\Throwable $e) { $row = null; }
        self::$dayMemo[$userId][$date] = $row;
        return $row;
    }

    /** Load a whole range of attendance rows in ONE query, into the day cache. */
    private function preloadDays(int $userId, string $start, string $end): void
    {
        try {
            $rows = DB::table('t_ops_attendance')
                ->where('user_id', $userId)
                ->whereBetween('attendance_date', [$start, $end])
                ->get($this->attendanceCols());
            foreach ($rows as $r) {
                self::$dayMemo[$userId][substr((string) $r->attendance_date, 0, 10)] = $r;
            }
        } catch (\Throwable $e) { /* fall back to per-day reads */ }
    }

    /** user => date => attendance row (or null when there is none). Per-request. */
    private static array $dayMemo = [];

    /**
     * Has this day been edited since it was reviewed? If so the review is retired: it stops
     * affecting every number immediately and the day comes back into the queue with a note
     * saying what moved. Called from every path that writes an attendance time.
     *
     * @return bool true when a review was actually superseded
     */
    public function supersedeIfChanged(int $userId, string $date, ?string $note = null): bool
    {
        if (!$this->enabled()) { return false; }
        $date = substr($date, 0, 10);
        // ⚠ This runs right AFTER a write to the day. A row cached earlier in the same request
        // is the PRE-edit row and would call every review fresh — so read the day anew.
        unset(self::$dayMemo[$userId][$date], self::$currentMemo[$userId]);
        $row  = $this->attendanceRow($userId, $date);
        $hit  = false;
        foreach (self::KINDS as $kind) {
            $r = $this->reviewFor($userId, $date, $kind, true);
            if ($r === null || $this->isFresh($kind, $row, $r['source_hash'])) { continue; }
            try {
                DB::table('t_hr_day_review')->where('id', $r['id'])->update([
                    'superseded_at'   => now(),
                    'superseded_note' => $note !== null ? mb_substr($note, 0, 200) : 'the day was edited after this review',
                ]);
                $hit = true;
            } catch (\Throwable $e) { /* never break the edit that triggered this */ }
        }
        if ($hit) { $this->forget($userId); }
        return $hit;
    }

    // ───────────────────────────────────────────────────────────────────────────
    // Writing
    // ───────────────────────────────────────────────────────────────────────────

    /**
     * Record one verdict. Upsert on (user, date, kind) — re-deciding a day UPDATES in place
     * and the actor moves to whoever decided last, the same idiom recordLeaveDecision() uses.
     *
     * @param  array  $opts  minutes (adjusted overtime) · waived (late) · reason · evidence
     * @return array  ['success' => bool, 'message' => string]
     */
    public function record(int $userId, string $date, string $kind, string $verdict, int $actorId, array $opts = []): array
    {
        if (!$this->enabled()) {
            return ['success' => false, 'message' => 'Day review is not set up on this server yet.'];
        }
        if (!in_array($kind, self::KINDS, true)) {
            return ['success' => false, 'message' => 'Unknown kind.'];
        }
        if (!in_array($verdict, self::VERDICTS, true)) {
            return ['success' => false, 'message' => 'Choose verified, adjusted or waived.'];
        }
        $date = substr($date, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['success' => false, 'message' => 'Bad date.'];
        }
        // ⚠ Order matters: the start-date refusal has to come BEFORE "nothing to review",
        // or an old day reports the wrong reason and the manager chases a ghost.
        if ($date < $this->startDate()) {
            return ['success' => false, 'message' => 'Days before ' . $this->startDate() . ' are not reviewed.'];
        }
        if ($date > date('Y-m-d')) {
            return ['success' => false, 'message' => 'That day has not happened yet.'];
        }
        if ($verdict === 'adjusted' && $kind !== 'overtime') {
            return ['success' => false, 'message' => 'Lateness is forgiven in minutes — use waive.'];
        }

        $row = $this->attendanceRow($userId, $date);
        if (!$row) {
            return ['success' => false, 'message' => 'There is no attendance for that day.'];
        }
        // Overtime is only final once the day is closed AND any checkout unlock has expired —
        // until then the counted end can still move, and a verdict would freeze the wrong figure.
        if ($kind === 'overtime') {
            if (empty($row->logout_time)) {
                return ['success' => false, 'message' => 'He has not checked out yet — the overtime can still change.'];
            }
            $until = $row->checkout_unlock_until ?? null;
            if ($until && strtotime((string) $until) > time()) {
                return ['success' => false, 'message' => 'His checkout is still unlocked — review this once the window closes.'];
            }
        }

        $computed = $this->computedMinutes($userId, $date, $kind);
        if ($computed <= 0 && $verdict !== 'verified') {
            return ['success' => false, 'message' => 'There is nothing to adjust on that day.'];
        }

        $reason = trim((string) ($opts['reason'] ?? ''));
        if ($verdict !== 'verified' && $reason === '') {
            return ['success' => false, 'message' => 'Say why — an adjustment without a reason cannot be explained later.'];
        }

        $waived = 0;
        $effective = $computed;
        if ($verdict === 'adjusted') {
            $effective = max(0, (int) ($opts['minutes'] ?? 0));
            if ($effective > $computed) {
                return ['success' => false, 'message' => 'That is more than the day earned (' . $computed . ' min).'];
            }
            if ($effective === $computed) {
                // Nothing actually changed — record it as what it is.
                $verdict = 'verified';
            }
        } elseif ($verdict === 'waived') {
            if ($kind === 'late') {
                $waived = (int) ($opts['waived'] ?? $computed);
                if ($waived <= 0) {
                    return ['success' => false, 'message' => 'How many minutes are being waived?'];
                }
                if ($waived > $computed) {
                    return ['success' => false, 'message' => 'He was only ' . $computed . ' minutes late that day.'];
                }
                $effective = $computed - $waived;
            } else {
                $effective = 0;   // "not overtime at all"
            }
        }

        try {
            DB::table('t_hr_day_review')->updateOrInsert(
                ['user_id' => $userId, 'review_date' => $date, 'kind' => $kind],
                [
                    'computed_minutes'  => $computed,
                    'verdict'           => $verdict,
                    'effective_minutes' => $effective,
                    'waived_minutes'    => $waived,
                    'reason'            => $reason !== '' ? mb_substr($reason, 0, 200) : null,
                    // ⭐ The card AS JUDGED, frozen. Months later "why did Shabib call that 30
                    // minutes?" is answerable from the row itself, even after the day has been
                    // edited, the bypass cleared, or the orders re-assigned. Built here rather
                    // than trusted from the caller, so every door records the same thing.
                    'evidence'          => json_encode($opts['evidence'] ?? $this->evidenceFor($userId, $date, $kind, $row)),
                    'source_hash'       => $this->sourceHash($row, $kind),
                    'reviewed_by'       => $actorId,
                    'reviewed_at'       => now(),
                    // Re-deciding a superseded day revives it.
                    'superseded_at'     => null,
                    'superseded_note'   => null,
                ]
            );
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Could not save that review.'];
        }

        $this->forget($userId);
        // Every figure downstream is memoised per month; drop them so the screen that asked
        // for this shows the new number immediately.
        try { app(PayrollService::class)->forgetAll(); } catch (\Throwable $e) { /* optional */ }

        try {
            \App\Services\AuditLogger::log(
                'day_reviewed', 'attendance', (int) ($row->id ?? 0),
                trim(($this->nameOf($userId) ?? 'rider') . ' ' . $date),
                ['kind' => $kind, 'verdict' => $verdict, 'computed' => $computed,
                 'effective' => $effective, 'waived' => $waived],
                null, $reason !== '' ? $reason : null
            );
        } catch (\Throwable $e) { /* auditing must never break the action */ }

        $msg = $this->confirmation($kind, $verdict, $computed, $effective, $waived);
        // ⭐ Owner ruling 5-Oct-2026: allowed in a month whose pay is already processed, but
        // said plainly — the verdict changes every screen, not the money already paid.
        $warning = $this->payProcessedNote($userId, $date);
        return [
            'success'       => true,
            'message'       => $warning !== null ? $msg . "\n\n⚠ " . $warning : $msg,
            'warning'       => $warning,
            'pay_processed' => $warning !== null,
        ];
    }

    /** PayrollService's "is this day's pay already processed?" — null when not (or unknown). */
    public function payProcessedNote(int $userId, string $date): ?string
    {
        try {
            // Held: item() asks once per queue card, and building PayrollService is not free.
            static $payroll = null;
            $payroll = $payroll ?? app(PayrollService::class);
            return $payroll->payProcessedNote($userId, $date);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * A snapshot of what the manager was looking at when he decided: the times, the counted
     * end, who granted any bypass, the delivery span, and who last typed a time on the day.
     * Stored on the review row so the decision stays explainable after the day itself moves.
     */
    private function evidenceFor(int $userId, string $date, string $kind, ?object $row): array
    {
        $out = [
            'kind'    => $kind,
            'login'   => $row->login_time ?? null,
            'logout'  => $row->logout_time ?? null,
            'shift'   => $row->expected_shift_start ?? null,
            'unlock'  => $row->checkout_unlock_until ?? null,
            'unlock_by'     => $row->checkout_unlock_by ?? null,
            'unlock_reason' => $row->checkout_unlock_reason ?? null,
        ];
        try {
            foreach ($this->itemsFor($userId, $date, $date, true) as $it) {
                if ($it['kind'] === $kind) { $out['meta'] = $it['meta']; $out['minutes'] = $it['minutes']; }
            }
        } catch (\Throwable $e) { /* evidence is a nicety; never block the decision */ }
        try {
            // Who last typed a time on this day, if anyone — the single most useful thing to
            // know when a figure looks wrong (see the Phase-0 audit rows).
            if (Schema::hasTable('t_sys_audit_log')) {
                $a = DB::table('t_sys_audit_log')
                    ->where('entity_type', 'attendance')
                    ->where('entity_id', (int) ($row->id ?? 0))
                    ->whereIn('action', ['attendance_time_edited', 'checkout_unlock_cleared', 'checkout_undone'])
                    ->orderByDesc('id')->first(['action', 'at', 'user_id', 'note']);
                if ($a) { $out['last_edit'] = (array) $a; }
            }
        } catch (\Throwable $e) { /* optional */ }
        return $out;
    }

    private function confirmation(string $kind, string $verdict, int $computed, int $effective, int $waived): string
    {
        if ($verdict === 'verified') {
            return $kind === 'overtime' ? 'Overtime verified.' : 'Lateness confirmed as fair.';
        }
        if ($kind === 'late') {
            return $waived . ' min waived — ' . $effective . ' min still counts this month.';
        }
        return $verdict === 'adjusted'
            ? 'Overtime set to ' . $this->hm($effective) . ' (was ' . $this->hm($computed) . ').'
            : 'Recorded as not overtime — this day no longer counts.';
    }

    // ───────────────────────────────────────────────────────────────────────────
    // The queue
    // ───────────────────────────────────────────────────────────────────────────

    /**
     * Days that need a manager. ⭐ Owner ruling: ONLY actionable days — a day with no
     * overtime and no lateness has nothing to decide and never appears. A day whose review
     * was superseded comes BACK, flagged with what changed.
     *
     * @param  array $opts  user_id · from · to · limit · include_reviewed
     * @return array list of items, newest day first
     */
    public function pending(array $opts = []): array
    {
        if (!$this->enabled()) { return []; }
        $start = max($opts['from'] ?? $this->startDate(), $this->startDate());
        $end   = min($opts['to'] ?? date('Y-m-d'), date('Y-m-d'));
        if ($start > $end) { return []; }

        $users = isset($opts['user_id'])
            ? [(int) $opts['user_id']]
            : $this->reviewableUsers($start, $end);

        $limit = (int) ($opts['limit'] ?? 200);
        $out = [];
        foreach ($users as $uid) {
            foreach ($this->itemsFor($uid, $start, $end, true) as $item) {
                if ($item['status'] !== 'pending') { continue; }
                $out[] = $item;
                if (count($out) >= $limit * 3) { break 2; }   // hard stop, sorted below
            }
        }
        usort($out, fn ($a, $b) => [$b['date'], $a['fullname']] <=> [$a['date'], $b['fullname']]);
        return array_slice($out, 0, $limit);
    }

    /**
     * Just the count — what the bulb shows.
     *
     * ⚠⚠ NOT cheap underneath: building it walks every employee's overtime and lateness for
     * the window (78 queries, ~0.5s measured on 12 employees). The web pill polls this every
     * 60 seconds on EVERY open page, so an uncached version would put that load on the
     * database once a minute per browser tab per manager. The number is the same for every
     * manager, so it is cached globally for slightly less than the poll interval.
     *
     * ⭐ The cache is dropped by `forget()`, which every write already calls — so a manager
     * who checks a day sees the count fall immediately rather than up to a minute later.
     */
    public function pendingCount(array $opts = []): int
    {
        // A filtered count (one employee, a custom window) is rare and not worth caching.
        if ($opts) {
            return count($this->pending(['limit' => 500] + $opts));
        }
        try {
            return (int) \Illuminate\Support\Facades\Cache::remember(
                self::COUNT_CACHE_KEY, 55, fn () => count($this->pending(['limit' => 500]))
            );
        } catch (\Throwable $e) {
            // No cache store configured / unreachable → answer honestly rather than not at all.
            return count($this->pending(['limit' => 500]));
        }
    }

    private const COUNT_CACHE_KEY = 'day_review_pending_count';

    /**
     * Tell the managers that days are waiting — at most once an hour.
     *
     * ⚠⚠ There is no scheduler on prod, so nothing here can run on a timer. This is called
     * from the RIDER'S OWN check-in / check-out (the action that creates the thing to review)
     * through `app()->terminating()`, so the rider's response has already gone out before any
     * of it runs. Every failure is swallowed: a push that cannot be sent must never break a
     * rider checking in.
     *
     * The hour bucket in the dedupe key is what turns "one push per rider action" into "one
     * push while a batch of them lands", the same shape the transfer alerts use.
     */
    public function pushDueOnce(): void
    {
        try {
            if (!$this->enabled()) { return; }
            if (!Schema::hasTable('t_ops_service_alert_push')) { return; }
            $key = 'day_review:' . date('Y-m-d-H');
            if (DB::table('t_ops_service_alert_push')->where('alert_key', $key)->exists()) { return; }

            $n = $this->pendingCount();
            if ($n <= 0) { return; }

            // Stamp BEFORE sending, so a push that throws cannot be retried on every
            // subsequent check-in for the rest of the hour.
            DB::table('t_ops_service_alert_push')->updateOrInsert(['alert_key' => $key], ['pushed_at' => now()]);
            (new \App\Services\FirebaseService())->notifyDayReviewsDue($n);
        } catch (\Throwable $e) { /* never break the action that triggered this */ }
    }

    /**
     * Every reviewable day for one user in a range, pending or already judged. This is the
     * one place that decides what a "day needing review" IS; the queue, the drill and the
     * month summary all read it, so they cannot disagree.
     */
    public function itemsFor(int $userId, string $start, string $end, bool $withEvidence = false): array
    {
        if (!$this->enabled()) { return []; }
        $start = max($start, $this->startDate());
        $end   = min($end, date('Y-m-d'));
        if ($start > $end) { return []; }

        // ⚠⚠ PERFORMANCE. The payroll grid calls summary() once per employee, so this method
        // runs 12+ times on a page load. Two things keep that affordable and BOTH matter:
        //   1. delivery evidence is opt-in — it is a second query per employee and only the
        //      queue card ever shows it. summary() must never ask for it.
        //   2. the result is memoised per (user, range, evidence), so the summary, the chip
        //      and the queue in one request share a single pass.
        // Without these the page took ~1.7s longer and 200 extra queries — undoing the N+1
        // work that got it from 14s to 2.3s in the first place.
        $memoKey = $userId . '|' . $start . '|' . $end . '|' . ($withEvidence ? 'e' : '');
        if (isset(self::$itemMemo[$memoKey])) { return self::$itemMemo[$memoKey]; }

        // ONE query for the whole range's attendance rows; every readiness and staleness check
        // below then reads from memory instead of asking the database per day.
        $this->preloadDays($userId, $start, $end);

        $ot   = app(OvertimeService::class)->overtimeForRange($userId, $start, $end, $withEvidence);
        $late = app(\App\Services\ShiftResolutionService::class)->lateDaysBreakdown($userId, $start, $end, $withEvidence);
        $name = $this->nameOf($userId);
        $today = date('Y-m-d');

        $items = [];
        foreach (($ot['details'] ?? []) as $date => $meta) {
            // `raw_minutes` is what the ENGINE computed before any verdict — judging must be
            // against the machine's figure, never against a number a previous verdict moved.
            $raw = (int) ($meta['raw_minutes'] ?? $meta['minutes'] ?? 0);
            if ($raw <= 0) { continue; }
            $items[] = $this->item($userId, $name, $date, 'overtime', $raw, $meta, $today);
        }
        foreach ($late as $d) {
            $raw = (int) ($d['raw_minutes'] ?? $d['minutes'] ?? 0);
            if ($raw <= 0) { continue; }
            $items[] = $this->item($userId, $name, $d['date'], 'late', $raw, [
                'login' => $d['login'] ?? null, 'shift_start' => $d['shift_start'] ?? null,
            ], $today);
        }
        usort($items, fn ($a, $b) => [$b['date'], $b['kind']] <=> [$a['date'], $a['kind']]);
        return self::$itemMemo[$memoKey] = $items;
    }

    /** (user|range|evidence) => items. Per-request; dropped on every write. */
    private static array $itemMemo = [];

    /** One queue item: the figure, the evidence, and where the verdict stands. */
    private function item(int $userId, ?string $name, string $date, string $kind, int $raw, array $meta, string $today): array
    {
        $r = $this->reviewFor($userId, $date, $kind, true);
        $row = null;
        $stale = false;
        if ($r !== null) {
            $row = $this->attendanceRow($userId, $date);
            $stale = !$this->isFresh($kind, $row, $r['source_hash']);
        }
        // A day still open (no checkout, or an unlock window still running) is not yet
        // judgeable for overtime — offering it would freeze a figure that can still move.
        $notReady = null;
        if ($kind === 'overtime') {
            $row = $row ?: $this->attendanceRow($userId, $date);
            if ($row && empty($row->logout_time)) { $notReady = 'still on duty'; }
            $until = $row->checkout_unlock_until ?? null;
            if ($until && strtotime((string) $until) > time()) { $notReady = 'checkout still unlocked'; }
        }

        $status = 'pending';
        if ($r !== null && !$stale) { $status = $r['verdict']; }

        return [
            'user_id'   => $userId,
            'fullname'  => $name,
            'date'      => $date,
            'kind'      => $kind,
            'minutes'   => $raw,
            'status'    => $status,               // pending | verified | adjusted | waived
            'stale'     => $stale,
            'not_ready' => $notReady,
            'effective' => $r && !$stale ? (int) $r['effective_minutes'] : $raw,
            'waived'    => $r && !$stale ? (int) $r['waived_minutes'] : 0,
            'reason'    => $r && !$stale ? $r['reason'] : null,
            'by'        => $r && !$stale ? $r['reviewed_by'] : null,
            'at'        => $r && !$stale ? $r['reviewed_at'] : null,
            'meta'      => $meta,
            'label'     => $this->label($kind, $raw, $r, $stale),
            // ⭐ Oct-2026: set when this day's pay is already processed (paid month, paid
            // period, or a salary slip). The card shows it BEFORE the manager decides.
            'pay_processed' => $this->payProcessedNote($userId, $date),
        ];
    }

    private function label(string $kind, int $raw, ?array $r, bool $stale): string
    {
        if ($r === null || $stale) {
            return $kind === 'overtime' ? $this->hm($raw) . ' overtime' : $this->hm($raw) . ' late';
        }
        if ($r['verdict'] === 'verified') {
            return ($kind === 'overtime' ? $this->hm($raw) . ' overtime' : $this->hm($raw) . ' late') . ' · verified';
        }
        if ($kind === 'late') {
            return $this->hm($r['waived_minutes']) . ' waived · ' . $this->hm($r['effective_minutes']) . ' counts';
        }
        return $r['effective_minutes'] > 0
            ? $this->hm($r['effective_minutes']) . ' overtime (was ' . $this->hm($raw) . ')'
            : 'not overtime';
    }

    /**
     * The month's review standing for one employee, for the payroll chips:
     * how many days needed a judgement, how many got one, and what moved.
     */
    public function summary(int $userId, string $month): array
    {
        $blank = [
            'enabled' => false,
            'overtime' => ['days' => 0, 'reviewed' => 0, 'pending' => 0, 'changed' => 0, 'stale' => 0],
            'late'     => ['days' => 0, 'reviewed' => 0, 'pending' => 0, 'waived_minutes' => 0, 'stale' => 0],
        ];
        if (!$this->enabled()) { return $blank; }
        $start = $month . '-01';
        $end   = date('Y-m-t', strtotime($start));
        if ($end < $this->startDate()) { return $blank; }

        $out = $blank;
        $out['enabled'] = true;
        foreach ($this->itemsFor($userId, $start, $end) as $it) {
            $k = $it['kind'];
            $out[$k]['days']++;
            if ($it['stale']) { $out[$k]['stale']++; }
            if ($it['status'] === 'pending') {
                $out[$k]['pending']++;
            } else {
                $out[$k]['reviewed']++;
                if ($k === 'late') { $out['late']['waived_minutes'] += (int) $it['waived']; }
                elseif ($it['status'] !== 'verified') { $out['overtime']['changed']++; }
            }
        }
        return $out;
    }

    // ───────────────────────────────────────────────────────────────────────────
    // Helpers
    // ───────────────────────────────────────────────────────────────────────────

    /** What the ENGINE says a day is worth, before any verdict. */
    private function computedMinutes(int $userId, string $date, string $kind): int
    {
        if ($kind === 'overtime') {
            $d = app(OvertimeService::class)->overtimeForRange($userId, $date, $date, false);
            $meta = $d['details'][$date] ?? null;
            return (int) ($meta['raw_minutes'] ?? $meta['minutes'] ?? 0);
        }
        $rows = app(\App\Services\ShiftResolutionService::class)->lateDaysBreakdown($userId, $date, $date);
        foreach ($rows as $r) {
            if ($r['date'] === $date) { return (int) ($r['raw_minutes'] ?? $r['minutes'] ?? 0); }
        }
        return 0;
    }

    /** Users with attendance in the window who are visible to payroll. */
    private function reviewableUsers(string $start, string $end): array
    {
        try {
            $q = DB::table('t_ops_attendance as a')
                ->join('t_sys_user as u', 'u.id', '=', 'a.user_id')
                ->whereBetween('a.attendance_date', [$start, $end])
                ->whereNotNull('a.login_time')->where('a.login_time', '!=', '')
                ->where('u.is_active', 1);
            if (Schema::hasTable('t_ops_attendance_visibility')) {
                $q->leftJoin('t_ops_attendance_visibility as av', 'av.user_id', '=', 'u.id')
                  ->where(function ($w) {
                      $w->whereNull('av.is_visible')->orWhere('av.is_visible', 1);
                  });
            }
            return $q->distinct()->pluck('u.id')->map(fn ($v) => (int) $v)->all();
        } catch (\Throwable $e) { return []; }
    }

    private static array $nameMemo = [];

    private function nameOf(int $userId): ?string
    {
        if (!array_key_exists($userId, self::$nameMemo)) {
            try { self::$nameMemo[$userId] = DB::table('t_sys_user')->where('id', $userId)->value('fullname'); }
            catch (\Throwable $e) { self::$nameMemo[$userId] = null; }
        }
        return self::$nameMemo[$userId];
    }

    public function hm(int $m): string
    {
        $m = max(0, $m);
        $h = intdiv($m, 60);
        return $h > 0 ? ($h . 'h ' . ($m % 60) . 'm') : ($m . 'm');
    }
}
