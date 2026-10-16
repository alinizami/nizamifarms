<?php
/**
 * PARITY — one number everywhere (Oct-2026). For every active employee in a time-bank month,
 * the LATE minutes and the TIME-BANK chip must be identical on:
 *   payroll row (computeRow) · web attendance month report · mobile store monthly report ·
 *   the rider's own month (getMonthlyAttendance) · the rider's pay screen (mySalary)
 * and the overtime "bonus days" every board shows must equal the engine's.
 * Read-only.  Run: php test_time_bank_parity_oct12.php [YYYY-MM]
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

$month = $argv[1] ?? date('Y-m', strtotime('first day of last month'));
$pass = 0; $fail = 0;
$ok = function ($c, $label, $d = '') use (&$pass, &$fail) { $c ? $pass++ : $fail++; if (!$c) echo "  ✗ $label  $d\n"; };

$manager = \App\Models\User::find(68) ?? null;   // Taimur — has manage_payroll / reports
Auth::setUser($manager);
$req = function (array $q, $user) { $r = Illuminate\Http\Request::create('/x', 'GET', $q); $r->setUserResolver(fn () => $user); return $r; };

// Web attendance month report
$web = (new App\Http\Controllers\CRM\AttendanceController())->monthlyReport($req(['month' => $month], $manager))->getData(true);
$webRows = [];
foreach (($web['data'] ?? $web['users'] ?? $web['report'] ?? []) as $u) { $webRows[(int) $u['user_id']] = $u; }
// Mobile store monthly report
$mob = (new App\Http\Controllers\API\RiderController())->getStoreAttendanceMonthly($req(['month' => $month], $manager))->getData(true);
$mobRows = [];
$walk = function ($a) use (&$walk, &$mobRows) { if (!is_array($a)) return; if (isset($a['user_id'], $a['late_minutes'])) { $mobRows[(int) $a['user_id']] = $a; return; } foreach ($a as $v) $walk($v); };
$walk($mob);

echo "month $month · web rows " . count($webRows) . " · mobile rows " . count($mobRows) . "\n";
$tb = new App\Services\HR\TimeBankService();
$pay = new App\Services\HR\PayrollService();
$checked = 0;
foreach ($webRows as $uid => $w) {
    if (!$tb->covers($uid, $month)) { continue; }
    $checked++;
    $name = $w['fullname'] ?? $uid;
    $row = $pay->computeRow($uid, $month);
    $s = $tb->month($uid, $month);
    $ok((int) $row['late_minutes'] === (int) $w['total_late_minutes'], "$name late: payroll vs web attendance", $row['late_minutes'] . ' vs ' . $w['total_late_minutes']);
    $ok(($row['time_bank']['chip'] ?? '') === ($w['time_bank']['chip'] ?? ''), "$name chip: payroll vs web attendance", ($row['time_bank']['chip'] ?? '') . ' | ' . ($w['time_bank']['chip'] ?? ''));
    // A month the OLD panel already turned into leave keeps that figure on every board.
    $expectedBonus = $tb->legacyOtActed($uid, $month)
        ? (int) (DB::table('t_hr_overtime_carry')->where('user_id', $uid)->where('month', $month)->value('days_granted') ?? 0)
        : (int) (($s['status'] ?? '') === 'decided'
            ? (($s['decision']['ot'] ?? null) === 'give' ? $s['decision']['ot_days'] : 0) : ($s['net']['ot_days'] ?? 0));
    $ok((int) $w['overtime_bonus_leaves'] === $expectedBonus, "$name bonus days: web board vs engine", $w['overtime_bonus_leaves'] . ' vs ' . $expectedBonus);
    // ⭐ Tiles round: the table cells (Late / Extra / by) are ONE payload on every board.
    $ok(json_encode($row['time_bank']['cells'] ?? null) === json_encode($s['cells'] ?? null), "$name cells: payroll = engine");
    $ok(json_encode($w['time_bank']['cells'] ?? null) === json_encode($s['cells'] ?? null), "$name cells: web attendance = engine");
    if (isset($mobRows[$uid])) {
        $m = $mobRows[$uid];
        $ok((int) $m['late_minutes'] === (int) $row['late_minutes'], "$name late: payroll vs phone report", $m['late_minutes'] . ' vs ' . $row['late_minutes']);
        $ok(($m['time_bank']['chip'] ?? '') === ($row['time_bank']['chip'] ?? ''), "$name chip: payroll vs phone report");
        $ok((int) $m['overtime_bonus_leaves'] === (int) $w['overtime_bonus_leaves'], "$name bonus days: phone vs web");
        $ok(json_encode($m['time_bank']['cells'] ?? null) === json_encode($s['cells'] ?? null), "$name cells: phone report = engine");
    }
    // The rider's own screens
    $rider = \App\Models\User::find($uid);
    if ($rider) {
        Auth::setUser($rider);
        $att = (new App\Http\Controllers\API\RiderController())->getMonthlyAttendance($req(['month' => $month . '-01'], $rider))->getData(true);
        $my = (new App\Http\Controllers\API\PayrollController())->mySalary($req(['month' => $month], $rider))->getData(true);
        Auth::setUser($manager);
        if (!empty($att['success'])) {
            $ok((int) $att['summary']['late_minutes'] === (int) $row['late_minutes'], "$name late: payroll vs rider attendance", $att['summary']['late_minutes'] . ' vs ' . $row['late_minutes']);
            $rm = $att['summary']['time_bank']['month'] ?? null;
            $ok($rm && $rm['sentence'] === $s['rider_sentence'], "$name rider sentence = engine", json_encode($rm['sentence'] ?? null));
        }
        // ⭐ The time balance: the manager's summary (web Employee tab / card / phone history) and
        // the rider's summary come from the SAME numbers.
        $sum = $tb->summary($uid);
        $hist = $tb->history($uid);
        $ok(($hist['summary']['lines'] ?? null) === $sum['lines'], "$name summary: history = engine");
        $ok(($att['summary']['time_bank']['summary']['lines'] ?? null) === $sum['rider_lines'], "$name rider summary = engine (attendance)");
        $ok(($my['time_bank']['summary']['lines'] ?? null) === $sum['rider_lines'], "$name rider summary = engine (pay screen)");
        $cur = $tb->month($uid, $sum['month']);
        $ok($sum['bank_late'] === (int) ($cur['late']['in'] ?? 0) && $sum['own_late'] === (int) ($cur['late']['own'] ?? 0),
            "$name summary bank/own late = the running month's card");
        if (!empty($my['success'])) {
            $ok((int) $my['row']['late_minutes'] === (int) $row['late_minutes'], "$name late: payroll vs rider pay screen");
            $ok(!array_key_exists('time_bank', $my['row']), "$name rider pay screen carries no manager suggestions");
            $ok(($my['time_bank']['month']['sentence'] ?? null) === $s['rider_sentence'], "$name rider pay sentence = engine");
        }
    }
}
echo "employees checked: $checked\n$pass passed, $fail failed\n";
