<?php
/**
 * PAY-TIME SHORTFALL checks — what happens when a month's deductions come to more than the
 * salary, and the manager answers the pay dialog.
 *
 * Written Sep-7 2026, after the option the dialog offered turned out to have never worked:
 * both controllers validated `shortfall` against `in:carry,writeoff` while the dialog sent
 * `waive_deductions`, so picking it 422'd and failed the whole batch. Nothing tested the
 * round trip, so nobody found out. These checks now cover it end to end.
 *
 * Uses a throwaway user id far outside the real range, and cleans up after itself. Nothing
 * here touches a real employee's pay.
 *
 *   php test_payroll_shortfall.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\HR\AbsenceDecisionService;
use App\Services\HR\PayrollService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

const U     = 999912;
const ACTOR = 1;
const BASE  = 60000;      // monthly salary
const ADV   = 90000;      // open advance — far more than the salary can absorb

$pass = 0; $fail = 0;

function check(string $what, $got, $want) {
    global $pass, $fail;
    $ok = json_encode($got) === json_encode($want);
    if ($ok) { $pass++; echo "  ok   $what\n"; }
    else { $fail++; echo "  FAIL $what\n         got  " . json_encode($got) . "\n         want " . json_encode($want) . "\n"; }
}
function near(string $what, $got, $want, float $tol = 0.5) {
    global $pass, $fail;
    if (abs((float) $got - (float) $want) <= $tol) { $pass++; echo "  ok   $what\n"; }
    else { $fail++; echo "  FAIL $what\n         got  $got\n         want ~$want\n"; }
}

// ─────────────────────────────────────────────────────────────────────────────
//  Seed / teardown
// ─────────────────────────────────────────────────────────────────────────────
function advCategoryId(): ?int {
    return DB::table('t_req_category')->where('category_code', 'salary_advance')->value('id');
}

function seed(string $month): void {
    wipe();
    DB::table('t_sys_user')->insert([
        'id' => U, 'fullname' => 'ZZ Shortfall Test', 'email' => 'zz.shortfall@test.invalid',
        'password' => bcrypt('x'), 'is_active' => 1, 'created_at' => now(), 'created_by' => ACTOR,
    ]);
    DB::table('t_hr_employee_profile')->insert([
        'user_id' => U, 'base_salary' => BASE,
    ]);
    // An approved, unsettled salary advance dated inside the month being paid.
    DB::table('t_req_master')->insert([
        'request_number'    => 'ZZ-SHORT-' . U,
        'category_id'       => advCategoryId(),
        'requester_user_id' => U,
        'title'             => 'Test advance',
        'amount'            => ADV,
        'status'            => 'approved',
        'settlement_status' => 'pending',   // what giveAdvance() writes
        'ledger_transaction_id' => 999999999, // money "left" — the accrual engine only counts posted advances
        'created_at'        => $month . '-05 10:00:00',
        'updated_at'        => now(),
    ]);
}

/** Drop every per-request memo, exactly as a new HTTP request would. */
function fresh(): void {
    \App\Services\HR\SalaryCalculationService::forgetAttendanceMemo();
    // computeRow()'s memo is STATIC (per request); a script is one long request.
    $p = new \ReflectionProperty(PayrollService::class, 'rowMemo');
    $p->setAccessible(true);
    $p->setValue(null, []);
}
function wipe(): void {
    DB::table('t_hr_payroll_payment')->where('user_id', U)->delete();
    DB::table('t_req_master')->where('requester_user_id', U)->delete();
    DB::table('t_hr_employee_profile')->where('user_id', U)->delete();
    DB::table('t_hr_absence_decision')->where('user_id', U)->delete();
    DB::table('t_hr_leave_grant')->where('user_id', U)->delete();
    DB::table('t_sys_user')->where('id', U)->delete();
    try { DB::table('t_hr_overtime_carry')->where('user_id', U)->delete(); } catch (\Throwable $e) {}
    \App\Services\HR\SalaryCalculationService::forgetAttendanceMemo();
}

/** A CLOSED month, so absent days are the whole month rather than "not happened yet". */
$month = date('Y-m', strtotime('first day of last month'));
// ⏱ Oct-2026: from Sep 2026 lateness is the TIME BANK's (no free-form late cut — the card
// decides; covered by test_time_bank_oct12.php). This file proves the OLD-rule shortfall
// behaviour, so it runs on the last old-rule month.
if ($month >= \App\Services\HR\TimeBankService::START_MONTH) { $month = '2026-08'; }

echo "month under test: $month\n";
echo "absence engine:   " . ((new AbsenceDecisionService())->enabled() ? 'on' : 'off') . "\n\n";

// ─────────────────────────────────────────────────────────────────────────────
//  1. The validation round trip — the bug that shipped
// ─────────────────────────────────────────────────────────────────────────────
echo "1. What the two controllers accept as a shortfall answer\n";
$rules = ['items.*.shortfall' => ['nullable', Rule::in(PayrollService::SHORTFALL_MODES)]];
foreach ([['carry', true], ['writeoff', true], ['waive_deductions', true], ['forgive', false], [null, true]] as [$mode, $want]) {
    $v = Validator::make(['items' => [['user_id' => 1, 'shortfall' => $mode]]], $rules);
    check(($want ? 'accepts ' : 'rejects ') . var_export($mode, true), !$v->fails(), $want);
}
// The two payloads the real screens send must both survive their own controller's rules.
foreach ([
    'web  ' => 'app/Http/Controllers/HR/PayrollController.php',
    'phone' => 'app/Http/Controllers/API/PayrollController.php',
] as $who => $file) {
    $src = file_get_contents(__DIR__ . '/' . $file);
    check(trim($who) . ' controller validates against the service list',
        (bool) preg_match('/items\.\*\.shortfall.*PayrollService::SHORTFALL_MODES/s', $src), true);
    check(trim($who) . ' controller has no hand-typed mode list',
        (bool) preg_match('/items\.\*\.shortfall.*in:carry/s', $src), false);
}

// ─────────────────────────────────────────────────────────────────────────────
//  2. WRITE OFF — today's behaviour, now stated out loud
// ─────────────────────────────────────────────────────────────────────────────
echo "\n2. Deduct as normal (write off the rest)\n";
seed($month);
$svc = new PayrollService();
$row = $svc->computeRow(U, $month);
$absentDed = (float) $row['absent_deduction'];
$netRaw    = (float) $row['net_raw'];
check('a shortfall really is on the sheet', $netRaw < 0, true);
check('the month has absent days to decide', $row['absent_days'] > 0, true);

$res = $svc->payRow(U, $month, ['funding' => 'cash', 'actor_id' => ACTOR, 'shortfall' => 'writeoff']);
check('paid', !empty($res['success']), true);
near('takes home nothing', $res['net'] ?? -1, 0);
$pay = DB::table('t_hr_payroll_payment')->where('user_id', U)->where('pay_month', $month)->first();
near('receipt keeps the absent cut', $pay->absent_deduction, $absentDed);
check('note says it was written off', str_contains((string) $pay->notes, 'written off'), true);
check('the advance is closed', DB::table('t_req_master')->where('requester_user_id', U)->value('settlement_status'), 'settled');
$dec = (new AbsenceDecisionService())->decisionFor(U, $month);
check('absence recorded as CUT', $dec['decision'] ?? null, 'cut');
near('and the cut is booked as money taken', $dec['amount_cut_now'] ?? -1, $absentDed);

// ─────────────────────────────────────────────────────────────────────────────
//  3. WAIVE — drop this month's absent/late cut so it recovers more of the advance
// ─────────────────────────────────────────────────────────────────────────────
echo "\n3. Don't deduct the absent / late\n";
seed($month);
$svc = new PayrollService();
$row = $svc->computeRow(U, $month);
$absentDed = (float) $row['absent_deduction'];
$writtenOffBefore = abs((float) $row['net_raw']);

$res = $svc->payRow(U, $month, ['funding' => 'cash', 'actor_id' => ACTOR, 'shortfall' => 'waive_deductions']);
check('paid', !empty($res['success']), true);
$pay = DB::table('t_hr_payroll_payment')->where('user_id', U)->where('pay_month', $month)->first();
near('receipt shows NO absent cut', $pay->absent_deduction, 0);
near('receipt shows NO late cut', $pay->late_deduction, 0);
check('note says the cut was waived', str_contains((string) $pay->notes, 'waived'), true);
check('note names WHAT was waived', str_contains((string) $pay->notes, 'absent deduction of'), true);
// ⭐ The whole point: the waived cut goes into the advance instead, so less is lost.
preg_match('/shortfall of ([\d,]+) written off/', (string) $pay->notes, $m);
$writtenOffAfter = (float) str_replace(',', '', $m[1] ?? '0');
near('exactly the waived cut less is written off', $writtenOffBefore - $writtenOffAfter, $absentDed);
$dec = (new AbsenceDecisionService())->decisionFor(U, $month);
// ⚠⚠ The fix this file was written for: this used to record CUT, booking absence money the
// payment never collected, on a payslip that says in the same breath that it was waived.
check('absence recorded as EXCUSE, not cut', $dec['decision'] ?? null, 'excuse');
near('no absence money booked', $dec['amount_cut_now'] ?? -1, 0);
check('nothing left owed', (new AbsenceDecisionService())->outstandingDays(U), 0.0);

// ─────────────────────────────────────────────────────────────────────────────
//  4. A month the manager ALREADY decided is not overridden at pay time
// ─────────────────────────────────────────────────────────────────────────────
echo "\n4. Waiving never overwrites a decision somebody already made\n";
seed($month);
$svc = new PayrollService();
$row = $svc->computeRow(U, $month);
// Park the days: they are owed, and parking already zeroes this month's cut.
(new AbsenceDecisionService())->commit(U, $month, (float) $row['absent_days'], 'park',
    (float) $row['absence_day_rate'], ACTOR, 'parked before pay');
fresh();
$svc = new PayrollService();
$res = $svc->payRow(U, $month, ['funding' => 'cash', 'actor_id' => ACTOR, 'shortfall' => 'waive_deductions']);
check('paid', !empty($res['success']), true);
$dec = (new AbsenceDecisionService())->decisionFor(U, $month);
check('the park decision stands', $dec['decision'] ?? null, 'park');
check('and the days are still owed', (new AbsenceDecisionService())->outstandingDays(U) > 0, true);

// ...and with a late cut in play, ONLY that is waived — and the receipt says only that.
echo "\n4b. A decided absence + a late cut: only the late cut is droppable\n";
seed($month);
$svc = new PayrollService();
$row = $svc->computeRow(U, $month);
(new AbsenceDecisionService())->commit(U, $month, (float) $row['absent_days'], 'park',
    (float) $row['absence_day_rate'], ACTOR, 'parked before pay');
fresh();
$svc = new PayrollService();
$res = $svc->payRow(U, $month, [
    'funding' => 'cash', 'actor_id' => ACTOR,
    'late_deduction' => 500, 'shortfall' => 'waive_deductions',
]);
check('paid', !empty($res['success']), true);
$pay = DB::table('t_hr_payroll_payment')->where('user_id', U)->where('pay_month', $month)->first();
near('the late cut is gone from the receipt', $pay->late_deduction, 0);
// ⚠ A receipt reading "absent + late waived" on a month where only the late cut moved is a
// small lie that outlives everyone who could correct it.
check('the note names ONLY the late cut', str_contains((string) $pay->notes, 'late deduction of 500'), true);
check('...and does not claim the absence was waived too',
    str_contains((string) $pay->notes, 'absent'), false);
check('the park decision is still untouched',
    (new AbsenceDecisionService())->decisionFor(U, $month)['decision'] ?? null, 'park');

// ─────────────────────────────────────────────────────────────────────────────
//  5. An unknown / missing answer falls back to today's behaviour, never to a crash
// ─────────────────────────────────────────────────────────────────────────────
echo "\n5. A missing or unknown answer is the safe default — which is now CARRY (owner's rule)\n";
seed($month);
$svc = new PayrollService();
$res = $svc->payRow(U, $month, ['funding' => 'cash', 'actor_id' => ACTOR]);   // nothing sent (old APK)
$pay = DB::table('t_hr_payroll_payment')->where('user_id', U)->where('pay_month', $month)->first();
check('old client with no answer still pays', !empty($res['success']), true);
check('and nothing is written off — the remainder moves on', str_contains((string) $pay->notes, 'moved to'), true);
check('the advance is still open', DB::table('t_req_master')->where('requester_user_id', U)->value('settlement_status'), 'pending');

seed($month);
$svc = new PayrollService();
$svc->payRow(U, $month, ['funding' => 'cash', 'actor_id' => ACTOR, 'shortfall' => 'forgive']);
$pay = DB::table('t_hr_payroll_payment')->where('user_id', U)->where('pay_month', $month)->first();
check('a mode nobody built is not silently treated as waive',
    str_contains((string) $pay->notes, 'waived'), false);

// ─────────────────────────────────────────────────────────────────────────────
//  6. No shortfall = no behaviour change at all
// ─────────────────────────────────────────────────────────────────────────────
echo "\n6. A normal month is untouched by any of this\n";
wipe();
DB::table('t_sys_user')->insert([
    'id' => U, 'fullname' => 'ZZ Shortfall Test', 'email' => 'zz.shortfall@test.invalid',
    'password' => bcrypt('x'), 'is_active' => 1, 'created_at' => now(), 'created_by' => ACTOR,
]);
DB::table('t_hr_employee_profile')->insert(['user_id' => U, 'base_salary' => BASE]);
$svc = new PayrollService();
$row = $svc->computeRow(U, $month);
check('no advance, so no shortfall', (float) $row['net_raw'] >= 0 || $row['absent_days'] > 0, true);
$before = json_encode([$row['absent_deduction'], $row['late_deduction']]);
$res = $svc->payRow(U, $month, ['funding' => 'cash', 'actor_id' => ACTOR, 'shortfall' => 'waive_deductions']);
$pay = DB::table('t_hr_payroll_payment')->where('user_id', U)->where('pay_month', $month)->first();
check('waive changes nothing when nothing is short',
    json_encode([(float) $pay->absent_deduction, (float) $pay->late_deduction]), $before);
check('and writes no shortfall note', (string) ($pay->notes ?? ''), '');

// ─────────────────────────────────────────────────────────────────────────────
//  7. ⭐ CARRY — the uncovered part of an advance moves to NEXT month (owner, Sep-7 2026:
//     "an advance is always a deduction from the salary"; nobody pays it back)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n7. Carry: what August cannot absorb comes off September\n";
$next = date('Y-m', strtotime($month . '-01 +1 month'));
seed($month);
$svc = new PayrollService();
$row = $svc->computeRow(U, $month);
// Park the absences so the salary is actually available for the advance — an employee
// absent the whole month absorbs nothing, which is a valid but uninteresting carry.
(new AbsenceDecisionService())->commit(U, $month, (float) $row['absent_days'], 'park',
    (float) $row['absence_day_rate'], ACTOR, 'parked');
fresh();
$svc = new PayrollService();
$row = $svc->computeRow(U, $month);
near('the whole salary is available for the advance', $row['net_raw'], BASE - ADV);
check('the grid knows carry is possible', $row['carry_available'], true);

$res = $svc->payRow(U, $month, ['funding' => 'cash', 'actor_id' => ACTOR, 'shortfall' => 'carry']);
check('paid', !empty($res['success']), true);
near('takes home nothing', $res['net'] ?? -1, 0);
$req = DB::table('t_req_master')->where('requester_user_id', U)->first();
check('the advance stays OPEN', (string) $req->settlement_status, 'pending');
near('what August took is recorded on it', $req->settled_amount, BASE);
check('the rest is stamped to next month', (string) $req->carry_month, $next);
check('and the row says so in words', str_contains((string) $req->settlement_notes, 'moved to'), true);
$pay = DB::table('t_hr_payroll_payment')->where('user_id', U)->where('pay_month', $month)->first();
near('the receipt books ONLY what August absorbed', $pay->advance_total, BASE);
check('the payment note names the month', str_contains((string) $pay->notes,
    'moved to ' . date('M Y', strtotime($next . '-01'))), true);
check('nothing written off', str_contains((string) $pay->notes, 'written off'), false);

fresh();
$svc = new PayrollService();
$nrow = $svc->computeRow(U, $next);
near('September deducts the remainder', $nrow['advance_total'], ADV - BASE);
check('and says where it came from', $nrow['advances'][0]['carried_from'] ?? null, $month);
near('nothing reads as open against August any more',
    array_sum(array_column($svc->computeRow(U, $month)['advances'], 'amount')), 0);

// ⭐ Accrual: August's wage bill = what August absorbed; September carries the rest as an
// open advance until its own pay absorbs it. One rupee, counted once.
$cost = new \App\Services\HR\SalaryCostService();
$mine = fn (array $rows) => array_values(array_filter($rows, fn ($r) => (int) ($r['user_id'] ?? 0) === U));
$aug = $mine($cost->detailForWindow($month . '-01', date('Y-m-t', strtotime($month . '-01')), 999999));
near('HQ books August at what August absorbed', array_sum(array_column($aug, 'amount')), BASE);
check('with no "open advance" row against August', in_array('advance_open', array_column($aug, 'kind'), true), false);
$sep = $mine($cost->detailForWindow($next . '-01', date('Y-m-t', strtotime($next . '-01')), 999999));
$sepOpen = array_values(array_filter($sep, fn ($r) => $r['kind'] === 'advance_open'));
near('and September carries the remainder as an open advance', $sepOpen[0]['amount'] ?? -1, ADV - BASE);

// A part-deducted advance can no longer be voided — a payslip already took some of it.
$v = $svc->voidAdvance((int) $req->id, 'test', ACTOR);
check('void refused once part of it is deducted', !empty($v['success']), false);
check('...and says why', str_contains((string) ($v['message'] ?? ''), 'already deducted'), true);

// ─────────────────────────────────────────────────────────────────────────────
//  8. ⭐ THE CAP — an advance may not exceed what is left of the month's salary
// ─────────────────────────────────────────────────────────────────────────────
echo "\n8. Cap: the excess is recorded against a later month\n";
seed($month);   // Rs 90,000 open against a Rs 60,000 salary → August has no room at all
$svc = new PayrollService();
$g = $svc->giveAdvance(U, 1000, 'cash', null, null, ACTOR, $month);
check('a month already fully advanced refuses even Rs 1,000', !empty($g['success']), false);
check('...and points at the next month', str_contains((string) $g['message'], date('F Y', strtotime($next . '-01'))), true);
$g = $svc->giveAdvance(U, 70000, 'cash', null, null, ACTOR, $next);
check('more than a month’s salary is refused', !empty($g['success']), false);
check('...naming what fits', str_contains((string) $g['message'], 'Rs 60,000'), true);
// The positive side, without moving money: the cap itself says an in-room amount is fine.
$capFn = (new \ReflectionClass(PayrollService::class))->getMethod('advanceCapMessage');
$capFn->setAccessible(true);
check('exactly the salary fits', $capFn->invoke($svc, U, $next, 60000), null);
check('one rupee more does not', $capFn->invoke($svc, U, $next, 60001) !== null, true);
check('no money moved for any of the refusals',
    DB::table('t_req_master')->where('requester_user_id', U)->count(), 1);

// An employee-raised request bigger than the room is refused at approval, not trimmed.
DB::table('t_req_master')->insert([
    'request_number' => 'ZZ-ASK-' . U, 'category_id' => advCategoryId(), 'requester_user_id' => U,
    'title' => 'Ask', 'amount' => 70000, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
]);
$askId = (int) DB::table('t_req_master')->where('request_number', 'ZZ-ASK-' . U)->value('id');
$a = $svc->approveAdvanceRequest($askId, 'cash', null, ACTOR);
check('an over-cap request is refused at approval', !empty($a['success']), false);
check('...and still pending, untouched', DB::table('t_req_master')->where('id', $askId)->value('status'), 'pending');

wipe();
echo "\n" . str_repeat('─', 60) . "\n";
echo ($fail === 0 ? "ALL GREEN" : "$fail FAILED") . "  ($pass passed)\n";
exit($fail === 0 ? 0 : 1);
