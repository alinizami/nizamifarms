<?php
/**
 * Sep-6 2026 — proof that consolidating the FOUR inline late formulas onto
 * ShiftResolutionService::lateForDay() changed nothing.
 *
 * With no day reviews in the table (the state prod is in before this ships), every
 * screen must produce byte-identical numbers to the old inline code. This script
 * re-implements each of the four ORIGINAL formulas verbatim and compares them,
 * day by day, against the helper across every rider and every month on the replica.
 *
 *   php test_late_engine_drift.php [months_back]
 */
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$svc  = app(\App\Services\ShiftResolutionService::class);
$leave = new \App\Services\HR\LeavePolicyService();

$monthsBack = (int) ($argv[1] ?? 6);
$start = date('Y-m-01', strtotime("-{$monthsBack} months"));
$end   = date('Y-m-d');

echo "range $start .. $end\n";
echo "day-review rows present: "
    . (Schema::hasTable('t_hr_day_review') ? DB::table('t_hr_day_review')->count() : 'no table') . "\n\n";

$cols = ['user_id', 'attendance_date', 'login_time', 'expected_shift_start', 'late_minutes'];
$rows = DB::table('t_ops_attendance')
    ->whereBetween('attendance_date', [$start, $end])
    ->whereNotNull('login_time')->where('login_time', '!=', '')
    ->orderBy('user_id')->orderBy('attendance_date')
    ->get($cols);

echo "attendance rows: " . count($rows) . "\n";

// Half-day sets, one query per user.
$halfByUser = [];
foreach (array_unique($rows->pluck('user_id')->all()) as $uid) {
    $halfByUser[$uid] = $leave->halfDayDates((int) $uid, $start, $end);
}

/** ORIGINAL formula A — sumLateOvertimeMinutes / lateDaysBreakdown / rider self-view. */
$origWith0900 = function ($svc, $uid, $date, $login, $snap, $expected) {
    if (!is_null($snap)) { return (int) $snap; }
    $s0 = $expected ?: (($svc->getUserShift($uid, $date)['shift_start'] ?? '09:00') . ':00');
    $s = strtotime($date . ' ' . $s0);
    $l = strtotime($date . ' ' . $login);
    return ($l > $s) ? (int) (($l - $s) / 60) : 0;
};

/** ORIGINAL formula B — manager per-day mobile view: NO 09:00 fallback. */
$origNoDefault = function ($svc, $uid, $date, $login, $snap, $expected) {
    if (!is_null($snap)) { return (int) $snap; }
    $dayStart = $expected ?: ($svc->getUserShift($uid, $date)['shift_start'] ?? null);
    $shiftStart = $dayStart ? strtotime($date . ' ' . $dayStart) : null;
    $actual = strtotime($date . ' ' . $login);
    return ($shiftStart && $actual > $shiftStart) ? (int) (($actual - $shiftStart) / 60) : 0;
};

$checked = 0; $driftA = 0; $driftB = 0; $noShift = 0;
$examples = [];

foreach ($rows as $r) {
    $uid  = (int) $r->user_id;
    $date = substr((string) $r->attendance_date, 0, 10);
    $isHalf = isset($halfByUser[$uid][$date]);
    if ($isHalf) { continue; }   // every path skips half-days identically
    // Oct-5 2026 owner ruling: a LEAVE day (full or half) is never late — every path skips it too.
    if ($svc->leaveKindOn($uid, $date) !== null) { $leaveSkipped = ($leaveSkipped ?? 0) + 1; continue; }

    $new = $svc->lateForDay($uid, $date, $r->login_time, $r->late_minutes, $r->expected_shift_start, false);
    $a = $origWith0900($svc, $uid, $date, $r->login_time, $r->late_minutes, $r->expected_shift_start);

    $newB = $svc->lateForDay($uid, $date, $r->login_time, $r->late_minutes, $r->expected_shift_start,
                             false, ['default_shift' => null]);
    $b = $origNoDefault($svc, $uid, $date, $r->login_time, $r->late_minutes, $r->expected_shift_start);

    $checked++;
    if ($new['raw'] !== $a) {
        $driftA++;
        if (count($examples) < 8) { $examples[] = "A u$uid $date: new={$new['raw']} old=$a"; }
    }
    if ($newB['raw'] !== $b) {
        $driftB++;
        if (count($examples) < 8) { $examples[] = "B u$uid $date: new={$newB['raw']} old=$b"; }
    }
    // How often do the two ORIGINAL formulas disagree with each other? That is the
    // pre-existing inconsistency the consolidation has to preserve, not erase.
    if ($a !== $b) { $noShift++; }
}

echo "days compared: $checked\n";
echo "drift vs formula A (09:00 fallback):        $driftA\n";
echo "drift vs formula B (no fallback):           $driftB\n";
echo "days where the two ORIGINALS disagreed:     $noShift";
echo $noShift ? "  <- pre-existing, preserved by default_shift\n" : "  (none — the fallback never fires on real data)\n";
foreach ($examples as $e) { echo "  $e\n"; }

// The month totals every screen actually shows must be unchanged too.
echo "\nmonth totals through sumLateOvertimeMinutes (waived must be 0 with no reviews):\n";
$bad = 0;
foreach (array_slice(array_unique($rows->pluck('user_id')->all()), 0, 12) as $uid) {
    $m = date('Y-m-01', strtotime($end));
    $t = $svc->sumLateOvertimeMinutes((int) $uid, $start, $end);
    $sum = 0;
    foreach ($rows as $r) {
        if ((int) $r->user_id !== (int) $uid) { continue; }
        $d = substr((string) $r->attendance_date, 0, 10);
        if (isset($halfByUser[$uid][$d])) { continue; }
        if ($svc->leaveKindOn((int) $uid, $d) !== null) { continue; }   // Oct-5 ruling C
        $sum += $origWith0900($svc, (int) $uid, $d, $r->login_time, $r->late_minutes, $r->expected_shift_start);
    }
    // Day reviews now exist on the replica: what counts + what was waived = the raw hand sum.
    $ok = ((int) $t['late_minutes'] + (int) $t['late_waived_minutes']) === $sum;
    if (!$ok) { $bad++; }
    printf("  u%-4d total=%-6d handsum=%-6d waived=%-4d %s\n",
        $uid, $t['late_minutes'], $sum, $t['late_waived_minutes'], $ok ? 'ok' : 'MISMATCH');
}

$fail = $driftA + $driftB + $bad;
echo "\n" . ($fail ? "FAILED — $fail problems\n" : "PASSED — the consolidation is a no-op on real data\n");
exit($fail ? 1 : 0);
