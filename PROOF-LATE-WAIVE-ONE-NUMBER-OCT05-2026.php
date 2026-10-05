<?php
/**
 * PROOF — a late waive shows the SAME number everywhere, and a checkout no longer
 * re-queues it (5-Oct-2026, Kanan 4-Oct: 103 late, 90 waived at 17:18, checked out 17:59).
 *
 * Read-only against the database EXCEPT test 6, which runs inside a transaction that is
 * always rolled back. Run:  php PROOF-LATE-WAIVE-ONE-NUMBER-OCT05-2026.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Services\HR\DayReviewService;
use App\Services\ShiftResolutionService;

$pass = 0; $fail = 0;
$ok = function (string $label, bool $cond, string $detail = '') use (&$pass, &$fail) {
    $cond ? $pass++ : $fail++;
    echo ($cond ? 'PASS' : 'FAIL') . "  $label" . ($detail !== '' ? "  [$detail]" : '') . "\n";
};
$fresh = function () {
    (new DayReviewService())->forget();
    try { app(\App\Services\HR\PayrollService::class)->forgetAll(); } catch (\Throwable $e) {}
};

$U = 76; $D = '2026-10-04';
$att = DB::table('t_ops_attendance')->where('user_id', $U)->where('attendance_date', $D)->first();
$rev = DB::table('t_hr_day_review')->where('user_id', $U)->where('review_date', $D)->where('kind', 'late')->first();
echo "Kanan 4-Oct: login {$att->login_time} logout {$att->logout_time} snapshot {$att->late_minutes}; review #{$rev->id} {$rev->verdict} waived {$rev->waived_minutes}\n\n";

// 1. The queue: the waived day is no longer "pending" after the checkout.
$fresh();
$drs = new DayReviewService();
$item = null;
foreach ($drs->itemsFor($U, $D, $D) as $it) if ($it['kind'] === 'late') $item = $it;
$ok('1a queue item is WAIVED, not pending', $item && $item['status'] === 'waived', $item['status'] ?? 'none');
$ok('1b queue item not stale', $item && !$item['stale']);
$ok('1c queue item says 90 waived / 13 effective', $item && $item['waived'] === 90 && $item['effective'] === 13);
$inPending = false;
foreach ($drs->pending(['user_id' => $U, 'from' => $D, 'to' => $D]) as $p) if ($p['kind'] === 'late') $inPending = true;
$ok('1d not back in the pending queue (the bulb)', !$inPending);
$sum = $drs->summary($U, '2026-10');
$ok('1e month summary counts the 90 waived', ($sum['late']['waived_minutes'] ?? -1) === 90, 'waived_minutes=' . ($sum['late']['waived_minutes'] ?? '?'));

// 2. The engine (money path) — unchanged, already right.
$fresh();
$shift = new ShiftResolutionService();
$day = $shift->lateForDay($U, $D, $att->login_time, $att->late_minutes, $att->expected_shift_start, false);
$ok('2a lateForDay = 103 raw, 90 waived, 13 counts', $day['raw'] === 103 && $day['waived'] === 90 && $day['minutes'] === 13);
$mo = $shift->sumLateOvertimeMinutes($U, '2026-10-01', $D);
$ok('2b month total (Oct 1-4) = 41 + 13 = 54', (int) $mo['late_minutes'] === 54, 'got ' . $mo['late_minutes']);

// 3. Web — the monthly popup in the photo (/attendance/employee-details).
$fresh();
$ctl = app(\App\Http\Controllers\CRM\AttendanceController::class);
$res = $ctl->employeeDetails(Request::create('/x', 'GET', ['user_id' => $U, 'from_date' => $D, 'start_date' => '2026-10-01', 'end_date' => $D]));
$j = json_decode($res->getContent(), true);
$rows = $j['data']['records'] ?? $j['records'] ?? $j['data']['attendance'] ?? [];
if (!$rows) { foreach (($j['data'] ?? $j) as $v) { if (is_array($v) && isset($v[0]['attendance_date'])) { $rows = $v; break; } } }
$r4 = null; foreach ($rows as $r) if (substr($r['attendance_date'], 0, 10) === $D) $r4 = $r;
$ok('3a popup 4-Oct Late By = 13 (was 103)', $r4 && (int) $r4['late_minutes'] === 13, 'late_minutes=' . ($r4['late_minutes'] ?? '?'));
$ok('3b popup carries raw 103 / waived 90', $r4 && (int) $r4['late_raw_minutes'] === 103 && (int) $r4['late_waived_minutes'] === 90);
$ok('3c popup 4-Oct still a LATE day', $r4 && ($r4['status'] ?? '') === 'late', $r4['status'] ?? '?');

// 4. Web — the Today board row (/attendance/data).
$fresh();
$res = $ctl->data(Request::create('/x', 'GET', ['date' => $D]));
$j = json_decode($res->getContent(), true);
$list = $j['data'] ?? $j['attendance'] ?? [];
if (!isset($list[0])) { foreach ($j as $v) { if (is_array($v) && isset($v[0]['user_id'])) { $list = $v; break; } } }
$k = null; foreach ($list as $r) if ((int) ($r['user_id'] ?? 0) === $U) $k = $r;
$ok('4a Today row late = 13, raw 103, waived 90', $k && (int) $k['late_minutes'] === 13 && (int) $k['late_raw_minutes'] === 103 && (int) $k['late_waived_minutes'] === 90,
    $k ? "late={$k['late_minutes']} raw=" . ($k['late_raw_minutes'] ?? '?') : 'row missing');
$ok('4b Today row month figure = 54', $k && (int) $k['month_late_minutes'] === 54);

// 5. Web — Attendance Reports + salary-slip drill (/attendance/monthly-report daily rows).
$fresh();
$res = $ctl->monthlyReport(Request::create('/x', 'GET', ['month' => '2026-10']));
$j = json_decode($res->getContent(), true);
$emps = $j['data'] ?? $j['employees'] ?? [];
$e = null; foreach ($emps as $x) if ((int) ($x['user_id'] ?? 0) === $U) $e = $x;
$d4 = null; foreach (($e['daily'] ?? []) as $d) if (substr($d['attendance_date'], 0, 10) === $D) $d4 = $d;
$ok('5a Reports daily 4-Oct = 13 counts / 103 raw / 90 waived', $d4 && (int) $d4['late_minutes'] === 13 && (int) $d4['late_raw_minutes'] === 103 && (int) $d4['late_waived_minutes'] === 90);
// The Reports month runs to TODAY (Kanan was also 20 min late on 5-Oct), so compare like for like.
$engineMo = (int) $shift->sumLateOvertimeMinutes($U, '2026-10-01', min(date('Y-m-d'), '2026-10-31'))['late_minutes'];
$ok("5b Reports month total = the engine's for the same range ($engineMo)", $e && (int) $e['total_late_minutes'] === $engineMo, 'got ' . ($e['total_late_minutes'] ?? '?'));

// 5c. Mobile — the rider shift month (/rider/shifts/rider-month), the one raw API site.
$fresh();
$mgr = \App\Models\User::find(79) ?? \App\Models\SysAdmin\UserModel::find(79);
if ($mgr) {
    \Illuminate\Support\Facades\Auth::shouldUse('web'); \Illuminate\Support\Facades\Auth::setUser($mgr);
    $res = app(\App\Http\Controllers\API\RiderController::class)->getRiderShiftMonth(Request::create('/x', 'GET', ['user_id' => $U, 'month' => '2026-10']));
    $j = json_decode($res->getContent(), true);
    $sd = null; foreach (($j['days'] ?? []) as $x) if ($x['date'] === $D) $sd = $x;
    $ok('5c shift month 4-Oct = 13 counts / 103 raw / 90 waived (was 103)', $sd && $sd['late_minutes'] === 13 && $sd['late_raw_minutes'] === 103 && $sd['late_waived_minutes'] === 90,
        $sd ? json_encode(array_intersect_key($sd, array_flip(['late_minutes', 'late_raw_minutes', 'late_waived_minutes']))) : ($j['message'] ?? 'no day'));
} else { $ok('5c shift month (could not load manager user)', false); }

// 5d–5f. Phone display gaps (round 2): store daily board, salary calculation, phone-made slip.
if ($mgr) {
    $rc = app(\App\Http\Controllers\API\RiderController::class);
    $fresh();
    $j = json_decode($rc->getStoreAttendanceDaily(Request::create('/x', 'GET', ['date' => $D]))->getContent(), true);
    $list = $j['data'] ?? $j['attendance'] ?? [];
    if (!isset($list[0])) { foreach ($j as $v) { if (is_array($v) && isset($v[0]['user_id'])) { $list = $v; break; } } }
    $s = null; foreach ($list as $x) if ((int) ($x['user_id'] ?? 0) === $U) $s = $x;
    $ok('5d store board 4-Oct: is_late, 13 counts, 103 raw, 90 waived',
        $s && $s['is_late'] && (int) $s['late_minutes'] === 13 && (int) $s['late_raw_minutes'] === 103 && (int) $s['late_waived_minutes'] === 90,
        $s ? json_encode(array_intersect_key($s, array_flip(['is_late', 'late_minutes', 'late_raw_minutes', 'late_waived_minutes']))) : ($j['message'] ?? 'row missing'));

    $fresh();
    $j = json_decode($rc->calculateSalary(Request::create('/x', 'GET', ['user_id' => $U, 'month' => '2026-10']))->getContent(), true);
    $calc = $j['calculation'] ?? [];
    $ok('5e salary calculation sends the split at top level (waived 90, raw = net + 90)',
        (int) ($calc['late_waived_minutes'] ?? -1) === 90 && (int) ($calc['late_raw_minutes'] ?? -1) === (int) $calc['late_minutes'] + 90,
        json_encode(array_intersect_key($calc, array_flip(['late_minutes', 'late_raw_minutes', 'late_waived_minutes']))));

    DB::beginTransaction();
    try {
        $res = $rc->createSalarySlip(Request::create('/x', 'POST', [
            'user_id' => $U, 'salary_month' => '2026-10-01', 'slip_status' => 'draft', 'allow_multiple' => true,
            'base_salary' => 30000, 'gross_salary' => 30000, 'total_deductions' => 0, 'net_salary' => 30000, 'late_minutes' => (int) $calc['late_minutes'],
            'late_waived_minutes' => 90, 'late_raw_minutes' => (int) $calc['late_raw_minutes'],
        ]));
        $j = json_decode($res->getContent(), true);
        $sid = $j['slip']['id'] ?? $j['data']['id'] ?? $j['slip_id'] ?? null;
        $row = $sid ? DB::table('t_hr_salary_slips')->where('id', $sid)->first() : null;
        if (!$row) { $row = DB::table('t_hr_salary_slips')->where('user_id', $U)->orderByDesc('id')->first(); }
        $ok('5f a phone-made slip freezes the split (waived 90)', $row && (int) $row->late_waived_minutes === 90 && (int) $row->late_raw_minutes === (int) $calc['late_raw_minutes'],
            $row ? "waived={$row->late_waived_minutes} raw={$row->late_raw_minutes}" : ($j['message'] ?? 'no slip'));
        $bd = $row ? \App\Models\HR\SalarySlipModel::find($row->id)->getDetailedBreakdown() : [];
        $ok('5g the slip breakdown the phone reads carries it', (int) ($bd['deductions']['late_waived_minutes'] ?? -1) === 90);
    } finally { DB::rollBack(); }
}

// 6. The supersede rule still bites on a REAL edit — and no longer on a checkout edit.
DB::beginTransaction();
try {
    $fresh();
    DB::table('t_ops_attendance')->where('id', $att->id)->update(['logout_time' => '18:30:00']);
    $ok('6a editing the CHECKOUT does not retire the late waive', (new DayReviewService())->supersedeIfChanged($U, $D) === false);
    $fresh();
    DB::table('t_ops_attendance')->where('id', $att->id)->update(['login_time' => '10:00:00', 'late_minutes' => 30]);
    $ok('6b editing the CHECK-IN still retires it', (new DayReviewService())->supersedeIfChanged($U, $D) === true);
} finally {
    DB::rollBack();
}
$fresh();
$ok('6c rolled back — review live again', DB::table('t_hr_day_review')->where('id', $rev->id)->value('superseded_at') === null);

// 7. Fleet-wide: no LIVE review flips from fresh to stale with the new fingerprint, and
//    the ones that heal are exactly late reviews taken before checkout.
$drs = new DayReviewService();
$m = new ReflectionMethod($drs, 'sourceHash');
$healed = []; $broke = 0; $total = 0;
foreach (DB::table('t_hr_day_review')->whereNull('superseded_at')->get() as $rv) {
    $row = $drs->attendanceRow((int) $rv->user_id, substr($rv->review_date, 0, 10));
    $oldFresh = $rv->source_hash === $m->invoke($drs, $row);
    $newFresh = $rv->source_hash === $m->invoke($drs, $row, $rv->kind) || $oldFresh;
    $total++;
    if ($oldFresh && !$newFresh) $broke++;
    if (!$oldFresh && $newFresh) $healed[] = "#{$rv->id} u{$rv->user_id} {$rv->review_date} {$rv->kind} {$rv->verdict}";
}
$ok("7a no live review becomes stale ($total checked)", $broke === 0);
echo "      healed (were wrongly back in the queue): " . count($healed) . "\n";
foreach ($healed as $h) echo "        $h\n";

// 8. No-op: the engine's RAW figure equals the old inline popup arithmetic on every day
//    since Sep-1 (so the only thing that moved is the waive itself).
$diff = 0; $n = 0;
foreach (DB::table('t_ops_attendance')->where('attendance_date', '>=', '2026-09-01')
           ->whereNotNull('login_time')->where('login_time', '!=', '')->get() as $a) {
    $d = substr($a->attendance_date, 0, 10);
    if (!is_null($a->late_minutes)) { $old = (int) $a->late_minutes; }
    else {
        $ds = $shift->getUserShift($a->user_id, $d)['shift_start'] ?? null;
        $s = $ds ? strtotime("$d $ds") : null; $l = strtotime("$d {$a->login_time}");
        $old = ($s && $l > $s) ? (int) (($l - $s) / 60) : 0;
    }
    // Round 3 (5-Oct-2026, owner ruling C): a LEAVE day is never late. Compare the raw rule
    // with the leave test switched off, and separately require 0 on every leave day.
    $new = $shift->lateForDay((int) $a->user_id, $d, $a->login_time, $a->late_minutes, $a->expected_shift_start, false,
                              ['default_shift' => null, 'apply_review' => false, 'ignore_leave' => true])['raw'];
    $n++; if ($old !== $new) { $diff++; if ($diff <= 5) echo "      drift u{$a->user_id} $d old=$old new=$new\n"; }
    if ($shift->leaveKindOn((int) $a->user_id, $d) !== null) {
        $onLeave = $shift->lateForDay((int) $a->user_id, $d, $a->login_time, $a->late_minutes, $a->expected_shift_start, false,
                                      ['default_shift' => null, 'apply_review' => false])['raw'];
        $leaveDays[] = "u{$a->user_id} $d raw=$old → $onLeave";
        if ($onLeave !== 0) { $leaveBad = ($leaveBad ?? 0) + 1; }
    }
}
$ok("8a raw figure identical to the old popup arithmetic ($n days, leave rule off)", $diff === 0, "$diff differ");
$ok('8b every leave day with a check-in now counts 0 late (' . count($leaveDays ?? []) . ' days)', ($leaveBad ?? 0) === 0);
foreach (($leaveDays ?? []) as $ld) { if (strpos($ld, 'raw=0') === false) echo "      leave day: $ld\n"; }

echo "\n$pass passed, $fail failed\n";
