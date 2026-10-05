<?php
/**
 * PROOF — late waive, round 3 (5-Oct-2026 owner rulings A/B/C + the audit fixes).
 *
 *   C  a leave day (full or half, approved or pending) is never late — total = the rows
 *   2  a STALE review moves no number (queue, engine, rider month, Today marker agree), and a
 *      re-stamp / import retires it into the queue
 *   B  a waive in a month whose pay is processed is ALLOWED and WARNS (card + save)
 *   3  a slip's late split is settled by the server; "set by hand" instead of a bad sum
 *   8  the web summary cards count late days with the one engine
 *
 * Read-only EXCEPT the blocks marked TX, which run inside a transaction that is always
 * rolled back. Run:  php PROOF-LATE-WAIVE-ROUND3-OCT05-2026.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Services\HR\DayReviewService;
use App\Services\HR\PayrollService;
use App\Services\ShiftResolutionService;
use App\Models\HR\SalarySlipModel;

$pass = 0; $fail = 0;
$ok = function (string $label, bool $cond, string $detail = '') use (&$pass, &$fail) {
    $cond ? $pass++ : $fail++;
    echo ($cond ? 'PASS' : 'FAIL') . "  $label" . ($detail !== '' ? "  [$detail]" : '') . "\n";
};
$fresh = function () {
    (new DayReviewService())->forget();
    ShiftResolutionService::forgetLeaveDays();
    PayrollService::forgetProcessed();
    \App\Services\HR\SalaryCalculationService::forgetAttendanceMemo();
    try { app(PayrollService::class)->forgetAll(); } catch (\Throwable $e) {}
};
$shift = new ShiftResolutionService();
$drs = new DayReviewService();

// ── C. Leave day: Rajab (95) 19-Sep, an approved EMERGENCY (full) leave, checked in 11:10.
$fresh();
$R = 95; $RD = '2026-09-19';
$a = DB::table('t_ops_attendance')->where('user_id', $R)->where('attendance_date', $RD)->first();
$ok('C0 fixture: Rajab 19-Sep has a check-in and a frozen late figure', $a && $a->login_time && (int) $a->late_minutes > 0, 'late=' . ($a->late_minutes ?? '?'));
$ok('C1 leaveKindOn = full', $shift->leaveKindOn($R, $RD) === 'full');
$d0 = $shift->lateForDay($R, $RD, $a->login_time, $a->late_minutes, $a->expected_shift_start, false);
$ok('C2 lateForDay on the leave day = 0 raw / 0 counts', $d0['raw'] === 0 && $d0['minutes'] === 0);
$d1 = $shift->lateForDay($R, $RD, $a->login_time, $a->late_minutes, $a->expected_shift_start, false, ['ignore_leave' => true]);
$ok('C3 …and the frozen figure is untouched (ignore_leave reads it back)', $d1['raw'] === (int) $a->late_minutes, 'raw=' . $d1['raw']);
$sum = $shift->sumLateOvertimeMinutes($R, '2026-09-01', '2026-09-30');
$brk = $shift->lateDaysBreakdown($R, '2026-09-01', '2026-09-30', false);
$ok('C4 month total = sum of the listed days', (int) $sum['late_minutes'] === array_sum(array_column($brk, 'minutes')),
    $sum['late_minutes'] . ' vs ' . array_sum(array_column($brk, 'minutes')));
$ok('C5 late-day count = listed days, and 19-Sep is not one', (int) $sum['late_days'] === count($brk) && !in_array($RD, array_column($brk, 'date'), true));
$inQ = false; foreach ($drs->itemsFor($R, $RD, $RD) as $it) if ($it['kind'] === 'late') $inQ = true;
$ok('C6 the leave day is not in the review queue', !$inQ);
// The rider's own month (phone) and the web Reports row agree.
$fresh();
\Illuminate\Support\Facades\Auth::shouldUse('web');
\Illuminate\Support\Facades\Auth::setUser(\App\Models\User::find($R));
$j = json_decode(app(\App\Http\Controllers\API\RiderController::class)
    ->getMonthlyAttendance(Request::create('/x', 'GET', ['month' => '2026-09-01']))->getContent(), true);
$hist = collect($j['data']['history'] ?? $j['history'] ?? [])->keyBy('date');
$rowSum = 0; foreach ($hist as $h) { $rowSum += (int) ($h['late_minutes'] ?? 0); }
$tile = (int) ($j['data']['summary']['late_minutes'] ?? $j['summary']['late_minutes'] ?? -1);
$ok('C7 rider phone: Late tile = sum of the day rows (was bigger by the leave day)', $tile === $rowSum, "tile=$tile rows=$rowSum");
// TX: a PENDING leave also counts as leave (same rule as the screens).
DB::beginTransaction();
try {
    $fresh();
    $K = 76; $KD = '2026-10-04';
    $cat = DB::table('t_req_category')->where('category_code', 'leave')->value('id');
    $tmpl = (array) DB::table('t_req_master')->where('category_id', $cat)->orderByDesc('id')->first();
    unset($tmpl['id']);
    DB::table('t_req_master')->insert(array_merge($tmpl, [
        'request_number' => 'REQ-PROOF-R3', 'requester_user_id' => $K, 'status' => 'pending', 'leave_type' => 'planned',
        'leave_start_date' => $KD, 'leave_end_date' => $KD,
    ]));
    $ka = DB::table('t_ops_attendance')->where('user_id', $K)->where('attendance_date', $KD)->first();
    $kd = $shift->lateForDay($K, $KD, $ka->login_time, $ka->late_minutes, $ka->expected_shift_start, false);
    $ok('C8 TX a PENDING full-day leave also zeroes the day (Kanan 4-Oct 103 → 0)', $kd['raw'] === 0 && $kd['waived'] === 0);
} finally { DB::rollBack(); }

// ── 2. A stale review moves no number. TX: Kanan 4-Oct, waived 90 of 103.
DB::beginTransaction();
try {
    $fresh();
    $K = 76; $KD = '2026-10-04';
    $ka = DB::table('t_ops_attendance')->where('user_id', $K)->where('attendance_date', $KD)->first();
    $before = $shift->lateForDay($K, $KD, $ka->login_time, $ka->late_minutes, $ka->expected_shift_start, false);
    $ok('2a fixture: 103 raw / 90 waived / 13 counts', $before['raw'] === 103 && $before['waived'] === 90 && $before['minutes'] === 13);
    // A path that rewrites the frozen minutes WITHOUT calling supersede (as restamp / import did).
    DB::table('t_ops_attendance')->where('id', $ka->id)->update(['late_minutes' => 150]);
    $fresh();
    $after = $shift->lateForDay($K, $KD, $ka->login_time, 150, $ka->expected_shift_start, false);
    $ok('2b engine: the stale waive no longer reduces pay (150 / 0 / 150)', $after['raw'] === 150 && $after['waived'] === 0 && $after['minutes'] === 150,
        json_encode($after));
    $it = null; foreach ($drs->itemsFor($K, $KD, $KD) as $x) if ($x['kind'] === 'late') $it = $x;
    $ok('2c queue: same day is pending + stale, 0 waived (agrees with the engine)', $it && $it['status'] === 'pending' && $it['stale'] && $it['waived'] === 0);
    $ok('2d rider month (forRange) no longer shows the old verdict', !isset($drs->forRange($K, $KD, $KD)[$KD . '|late']));
    $ok('2e Today-board marker (reviewsOn) no longer shows it', !isset($drs->reviewsOn([$K], $KD)[$K . '|late']));
    $s = $drs->summary($K, '2026-10');
    $ok('2f month summary: waived total drops the stale 90', (int) $s['late']['waived_minutes'] === 0, 'waived=' . $s['late']['waived_minutes']);
    // Put it back → the review is current again, nothing was lost.
    DB::table('t_ops_attendance')->where('id', $ka->id)->update(['late_minutes' => 103]);
    $fresh();
    $back = $shift->lateForDay($K, $KD, $ka->login_time, 103, $ka->expected_shift_start, false);
    $ok('2g undo the edit → the waive applies again (13 counts)', $back['minutes'] === 13 && $back['waived'] === 90);
    // A re-stamp after the check-in moved: restampRange retires the review into the queue.
    DB::table('t_ops_attendance')->where('id', $ka->id)->update(['login_time' => '16:30:00']);
    $fresh();
    $shift->restampRange($K, $KD, $KD);
    $rv = DB::table('t_hr_day_review')->where('user_id', $K)->where('review_date', $KD)->where('kind', 'late')->first();
    $ok('2h restampRange retires the review, saying why', $rv && $rv->superseded_at !== null && stripos((string) $rv->superseded_note, 're-stamped') !== false,
        (string) ($rv->superseded_note ?? 'none'));
    $nowRow = DB::table('t_ops_attendance')->where('id', $ka->id)->first();
    $ok('2i …and the new figure counts in full', (int) $nowRow->late_minutes > 103
        && $shift->lateForDay($K, $KD, $nowRow->login_time, $nowRow->late_minutes, $nowRow->expected_shift_start, false)['waived'] === 0,
        'late=' . $nowRow->late_minutes);
} finally { DB::rollBack(); }
$fresh();
$ok('2j rolled back — review live, 90 waived again', DB::table('t_hr_day_review')->where('user_id', 76)->where('review_date', '2026-10-04')
    ->where('kind', 'late')->whereNull('superseded_at')->value('waived_minutes') == 90);

// ── B. Pay already processed → allowed, with a plain warning. TX.
DB::beginTransaction();
try {
    $fresh();
    $K = 76; $KD = '2026-10-04';
    $ok('Ba no warning while October is unpaid', $drs->payProcessedNote($K, $KD) === null);
    DB::table('t_hr_payroll_payment')->insert(['user_id' => $K, 'pay_month' => '2026-10', 'status' => 'paid', 'paid_at' => '2026-11-01 10:00:00']);
    $fresh();
    $note = $drs->payProcessedNote($K, $KD);
    $ok('Bb paid month → note names the month and that money does NOT move', $note !== null && stripos($note, 'October 2026') !== false && stripos($note, 'NOT the money') !== false, (string) $note);
    $it = null; foreach ($drs->itemsFor($K, $KD, $KD) as $x) if ($x['kind'] === 'late') $it = $x;
    $ok('Bc queue card carries it (shown BEFORE deciding)', $it && $it['pay_processed'] === $note);
    $res = $drs->record($K, $KD, 'late', 'waived', 1, ['waived' => 100, 'reason' => 'proof']);
    $ok('Bd the waive is ALLOWED', $res['success'] === true, $res['message']);
    $ok('Be …and the save says it again (warning + message)', ($res['warning'] ?? null) === $note && strpos($res['message'], $note) !== false && ($res['pay_processed'] ?? false) === true);
    $ok('Bf a day in another (unpaid) month is not flagged', $drs->payProcessedNote($K, '2026-09-30') === null);
    // A salary slip also counts as processed.
    DB::table('t_hr_payroll_payment')->where('user_id', $K)->where('pay_month', '2026-10')->delete();
    DB::table('t_hr_salary_slips')->insert(['user_id' => $K, 'salary_month' => '2026-10-01', 'slip_status' => 'draft', 'slip_number' => 'SAL-PROOF']);
    $fresh();
    $n2 = $drs->payProcessedNote($K, $KD);
    $ok('Bg a salary slip for the month also warns, naming the slip', $n2 !== null && strpos($n2, 'SAL-PROOF') !== false, (string) $n2);
    // A khata (balance) payment never covers days.
    DB::table('t_hr_salary_slips')->where('slip_number', 'SAL-PROOF')->delete();
    if (\Illuminate\Support\Facades\Schema::hasColumn('t_hr_payroll_payment', 'entry_kind')) {
        DB::table('t_hr_payroll_payment')->insert(['user_id' => $K, 'pay_month' => '2026-10', 'status' => 'paid', 'entry_kind' => 'balance_payment']);
        $fresh();
        $ok('Bh a khata balance payment does NOT count as processed', $drs->payProcessedNote($K, $KD) === null);
    }
} finally { DB::rollBack(); }

// ── 3. Slip split, settled by the server.
$fresh();
$sp = SalarySlipModel::lateSplitForNewSlip(76, '2026-10-01', 74, null, null);
$att = (new \App\Services\HR\SalaryCalculationService())->attendanceSummary(76, '2026-10-01');
$ok('3a old APK (no split posted) → the month\'s real split from the engine', $sp['raw'] === (int) $att['late_raw_minutes'] && $sp['waived'] === (int) $att['late_waived_minutes'],
    json_encode($sp));
$ok('3b weekly/extra slip (part of a month) → raw = net, 0 waived', SalarySlipModel::lateSplitForNewSlip(76, '2026-10-01', 30, null, null, true) === ['raw' => 30, 'waived' => 0]);
$ok('3c waived clamped to raw', SalarySlipModel::lateSplitForNewSlip(76, '2026-10-01', 0, 50, 80) === ['raw' => 50, 'waived' => 50]);
$ok('3d note: consistent split', SalarySlipModel::lateNote(13, 103, 90) === '103 mins late · 90 waived by a manager');
$ok('3e note: nothing waived, nothing edited → no line', SalarySlipModel::lateNote(40, 40, 0) === null);
$ok('3f note: edited by hand says so', SalarySlipModel::lateNote(60, 103, 90) === '103 mins late · 90 waived by a manager · set to 60 by hand on this slip');
$ok('3g note: slip older than the split → nothing', SalarySlipModel::lateNote(60, null, null) === null);
$slip = new SalarySlipModel(['late_minutes' => 0, 'late_raw_minutes' => 103, 'late_waived_minutes' => 103, 'late_deduction' => 0]);
$ok('3h fully waived, Rs 0 slip still has a late line', $slip->hasLateness());

// ── 8. Web summary cards (/attendance/summary) count late days with the one engine.
$fresh();
$res = app(\App\Http\Controllers\CRM\AttendanceController::class)->summary(Request::create('/x', 'GET', ['start' => '2026-09-01', 'end' => '2026-09-30']));
$cards = json_decode($res->getContent(), true)['data'] ?? [];
$expect = 0;
foreach (DB::table('t_ops_attendance as a')->join('t_sys_user as u', 'u.id', '=', 'a.user_id')
           ->whereBetween('a.attendance_date', ['2026-09-01', '2026-09-30'])->whereNotNull('a.login_time')->where('a.login_time', '!=', '')
           ->get(['a.user_id', 'a.attendance_date', 'a.login_time', 'a.late_minutes', 'a.expected_shift_start']) as $r) {
    if ($shift->lateForDay((int) $r->user_id, substr($r->attendance_date, 0, 10), $r->login_time, $r->late_minutes, $r->expected_shift_start ?: null, false, ['apply_review' => false])['raw'] > 0) $expect++;
}
$ok('8a summary "late" = engine raw late days (no leave days, no seconds quirk)', (int) ($cards['late'] ?? -1) === $expect, 'cards=' . ($cards['late'] ?? '?') . " engine=$expect");

echo "\n$pass passed, $fail failed\n";
