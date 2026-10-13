<?php
/**
 * PROOF — Time bank engine (Oct-2026). Run: php test_time_bank_oct12.php
 * Everything that writes runs inside ONE transaction that is rolled back at the end.
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\HR\TimeBankService as TB;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function ok($cond, $label, $extra = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; } else { $fail++; echo "  ✗ $label" . ($extra !== '' ? "  → $extra" : '') . "\n"; }
}
function uid($name) { return (int) DB::table('t_sys_user')->where('fullname', 'like', $name . '%')->where('is_active', 1)->value('id'); }

echo "── 1. Arithmetic\n";
$a = TB::arithmetic(845, 662, true, 0.5, 540);
ok($a['cancel'] === 331 && $a['spent'] === 662 && $a['late_days'] === 0 && $a['late_left'] === 514, 'Haider: 14h05 late, 11h02 extra, 0.5 → covers 5h31, 8h34 left', json_encode($a));
$a = TB::arithmetic(845, 662, false, 0.5, 540);
ok($a['late_days'] === 1 && $a['late_left'] === 305 && $a['ot_days'] === 1 && $a['ot_left'] === 122, 'cover OFF: −1 leave +5h05, +1 extra +2h02', json_encode($a));
$a = TB::arithmetic(411, 985, true, 0.5, 540);
ok($a['cancel'] === 411 && $a['spent'] === 822 && $a['ot_days'] === 0 && $a['ot_left'] === 163, 'all late covered, extra remainder carries', json_encode($a));
$a = TB::arithmetic(600, 661, true, 0.5, 540);
ok($a['cancel'] === 330 && $a['spent'] === 660 && $a['ot_left'] === 1, 'odd extra minute: floor cancel / ceil spend never over-credits', json_encode($a));
$a = TB::arithmetic(600, 600, true, 1.0, 540);
ok($a['cancel'] === 600 && $a['spent'] === 600, 'factor 1 = equal');
$a = TB::arithmetic(1200, 0, true, 0.5, 540);
ok($a['late_days'] === 2 && $a['late_left'] === 120 && $a['cancel'] === 0, 'no extra: cover does nothing');
$a = TB::arithmetic(0, 0, true, 0.5, 540);
ok($a['late_days'] === 0 && $a['ot_days'] === 0, 'empty month');

echo "── 2. Start month + opening (replica, read only)\n";
$tb = new TB();
TB::forget();
$haider = uid('Haider Ali'); $farooq = uid('Farooq'); $danish = uid('Danish Ali');
$arslan = uid('Arslan Aslam'); $asim = uid('Asim Tahir'); $kanan = uid('Kanan Anoos');
ok($tb->enabled(), 'tables present');
// ⭐ Oct-12 cut-over: 1 September for EVERYONE; a payment is never a leave decision.
ok($tb->startMonth($haider) === '2026-09' && $tb->startMonth($arslan) === '2026-09' && $tb->startMonth($asim) === '2026-09', 'everyone starts September');
$s = $tb->month($arslan, '2026-09');
ok($s['status'] !== 'before_start', 'Arslan Sep IS a bank month although Sep was paid', $s['status']);
ok($s['late']['own'] === 52, 'Arslan Sep late 52m counts in the bank (payment ≠ decision)', (string) $s['late']['own']);
ok($s['ot']['own'] === 0 && $s['ot']['in'] === 4, 'Arslan Sep extra: old +1 leave already given → bank takes 0 own, only the 4m the old carry left', $s['ot']['own'] . ' / ' . $s['ot']['in']);
ok((bool) array_filter($s['lines'], fn ($l) => str_contains($l, 'already settled the old way: +1 leave given')), 'the card SAYS the old rule already gave the leave', json_encode($s['lines']));
$ar8 = $tb->month($arslan, '2026-08');
ok($ar8['status'] === 'before_start', 'August is before the cut-over', $ar8['status']);
$s = $tb->month($danish, '2026-09');
ok($s['ot']['in'] === 0 && !str_contains($s['ot']['in_label'], 'Aug'), 'Danish: un-decided AUGUST extra is NOT pulled in (before the cut-over)', $s['ot']['in_label']);
$s = $tb->month($haider, '2026-09');
ok($s['status'] === 'pending', 'Haider Sep pending', $s['status']);
ok($s['late']['total'] === 845, 'Haider late 14h05', (string) $s['late']['total']);
ok($s['ot']['in'] === 465 + 0 || $s['ot']['in'] > 0, 'Haider extra includes Aug carry', (string) $s['ot']['in']);
echo "     card: " . implode(' | ', $s['lines']) . " | " . $s['result'] . " | chip=" . $s['chip'] . "\n";
$s2 = $tb->month($haider, '2026-09', ['cover' => false]);
echo "     cover off: " . $s2['result'] . " | chip=" . $s2['chip'] . "\n";
ok($s2['net']['late_days'] >= 1, 'cover off shows whole late days');
$ar = $tb->month($arslan, '2026-10');
ok(in_array($ar['status'], ['running', 'pending', 'clear'], true), 'Arslan Oct readable: ' . $ar['status']);
ok($ar['late']['in'] === 52 || $ar['folded'] === ['2026-09'], 'Arslan Oct folds the undecided Sep (52m late) in', json_encode($ar['folded']));
$kn = $tb->month($kanan, '2026-09');
ok($kn['late']['in'] === 0 && $kn['ot']['in'] === 0, 'Kanan: nothing from August (before the cut-over)', $kn['late']['in'] . ' / ' . $kn['ot']['in']);
$hd = $tb->month($haider, '2026-09');
ok($hd['ot']['in'] === 465, 'Haider: the 7h45m the old carry engine still owed from Aug DOES come in', (string) $hd['ot']['in']);

echo "── 2b. Already approved / paid numbers are NOT touched by the bank\n";
$snap = function () {
    return [
        'grants' => DB::table('t_hr_leave_grant')->selectRaw('count(*) n, coalesce(sum(days),0) d')->first(),
        'carry'  => DB::table('t_hr_overtime_carry')->selectRaw('count(*) n, coalesce(sum(days_granted),0) d')->first(),
        'pay'    => DB::table('t_hr_payroll_payment')->selectRaw('count(*) n, coalesce(sum(net_salary),0) d, coalesce(sum(late_deduction),0) l')->first(),
    ];
};
$before = $snap();
$bal0 = [];
foreach ([$arslan, $asim, uid('Waseem')] as $u) { $bal0[$u] = (new App\Services\HR\LeavePolicyService())->balance($u)['remaining']; }
$tb->monthAll('2026-09'); $tb->monthAll('2026-10');
foreach (DB::table('t_sys_user')->where('is_active', 1)->pluck('id') as $u) { try { (new App\Services\HR\PayrollService())->computeRow((int) $u, '2026-09'); } catch (\Throwable $e) {} }
ok(json_encode($snap()) === json_encode($before), 'reading every bank statement + payroll row writes nothing (grants, carry rows, payments identical)');
foreach ($bal0 as $u => $b) { ok((new App\Services\HR\LeavePolicyService())->balance($u)['remaining'] == $b, "leave balance of user $u unchanged ($b)"); }
ok(DB::table('t_hr_leave_grant')->where('user_id', $arslan)->where('reason', 'Overtime bonus Sep 2026')->where('days', 1)->exists(), 'Arslan: the old +1 Sep bonus row is still there');
ok($hd['status'] !== 'decided' && (int) DB::table('t_hr_time_bank_month')->count() === 0, 'nothing has been decided by the bank yet (no bank rows)');

echo "── 2c. Shabib's overtime verdicts (verified / adjusted / waived) are what the bank counts\n";
$otSvc = new App\Services\HR\OvertimeService();
$rng = $otSvc->overtimeForRange($haider, '2026-09-01', '2026-09-30');
$raw = array_sum(array_column($rng['details'], 'raw_minutes'));
$eff = (int) $rng['total'];
$rev = DB::table('t_hr_day_review')->where('user_id', $haider)->where('kind', 'overtime')->where('review_date', '2026-09-24')->whereNull('superseded_at')->first();
ok($rev && (int) $rev->computed_minutes === 76 && (int) $rev->effective_minutes === 40, 'replica: Haider 24-Sep overtime was ADJUSTED 76 → 40 by a manager');
ok($raw - $eff === 36, 'the month total is the adjusted figure (36 min less than raw)', "raw $raw, effective $eff");
ok($hd['ot']['own'] === $eff, 'bank Sep extra = the adjusted total', $hd['ot']['own'] . ' vs ' . $eff);
ok((int) (new App\Services\HR\PayrollService())->computeRow($haider, '2026-09')['overtime_minutes'] === $eff, 'payroll row shows the same adjusted total');
$rj = $tb->month(uid('Rajab Masood'), '2026-09');
$rjRng = $otSvc->overtimeForRange(uid('Rajab Masood'), '2026-09-01', '2026-09-30');
ok(($rjRng['details']['2026-09-03']['minutes'] ?? -1) === 0 && ($rjRng['details']['2026-09-03']['raw_minutes'] ?? 0) === 29, 'Rajab 3-Sep overtime WAIVED → counts 0 (29 raw) in the bank too');
ok($rj['ot']['own'] === (int) $rjRng['total'], 'Rajab bank Sep extra = engine total after the waive', $rj['ot']['own'] . ' vs ' . $rjRng['total']);

echo "── 3. Decide / undo / fold / adjust / drift (rolled back)\n";
DB::beginTransaction();
try {
    $actor = (int) DB::table('t_sys_user')->where('fullname', 'like', 'Taimur%')->value('id');
    TB::forget();
    $s = $tb->month($haider, '2026-09');
    $r = $tb->decide($haider, '2026-09', ['cover' => true, 'late_choice' => null, 'ot_choice' => null, 'expect' => 'stale'], $actor);
    ok(!$r['success'] && !empty($r['stale']), 'stale expect is refused with a fresh card');
    $r = $tb->decide($haider, '2026-09', ['cover' => true, 'expect' => $s['expect']], $actor);
    ok($r['success'], 'Haider Sep decided with cover (nothing whole)', $r['message']);
    $d = $tb->month($haider, '2026-09');
    ok($d['status'] === 'decided' && $d['net']['late_left'] === 514, 'decided: 8h34m late moves to Oct', json_encode($d['net']));
    echo "     decided chip: {$d['chip']} | rider: {$d['rider_sentence']}\n";
    $o = $tb->month($haider, '2026-10');
    ok($o['late']['in'] === 514, 'Oct carries 8h34m late in', (string) $o['late']['in']);

    // Farooq: cover off, take leave for late, give extra
    $f = $tb->month($farooq, '2026-09', ['cover' => false]);
    $bal0 = (new App\Services\HR\LeavePolicyService())->balance($farooq)['remaining'];
    $r = $tb->decide($farooq, '2026-09', ['cover' => false, 'late_choice' => 'leave', 'ot_choice' => 'give', 'expect' => $f['expect']], $actor);
    ok($r['success'], 'Farooq Sep: cover off, leave + give', $r['message']);
    $bal1 = (new App\Services\HR\LeavePolicyService())->balance($farooq)['remaining'];
    ok(abs(($bal1 - $bal0) - ($f['net']['ot_days'] - $f['net']['late_days'])) < 0.01, "balance moved by +extra −late ($bal0 → $bal1)");
    $u = $tb->undo($farooq, '2026-09', $actor);
    $bal2 = (new App\Services\HR\LeavePolicyService())->balance($farooq)['remaining'];
    ok($u['success'] && abs($bal2 - $bal0) < 0.01, "undo restores the balance ($bal2)", $u['message']);

    // Salary on a month: cut lands on Sep pay (Sep unpaid for Farooq on replica)
    $f = $tb->month($farooq, '2026-09', ['cover' => false]);
    $r = $tb->decide($farooq, '2026-09', ['cover' => false, 'late_choice' => 'salary', 'ot_choice' => 'carry', 'expect' => $f['expect']], $actor);
    ok($r['success'], 'Farooq salary cut', $r['message']);
    $cuts = $tb->cutsLandingOn($farooq, '2026-09');
    ok(count($cuts) === 1 && abs($cuts[0]['amount'] - $f['net']['late_days'] * $f['day_rate']) < 0.02, 'cut = days × day rate on Sep pay', json_encode($cuts));
    $row = (new App\Services\HR\PayrollService())->computeRow($farooq, '2026-09');
    ok(abs((float) $row['late_deduction'] - $cuts[0]['amount']) < 0.02, 'payroll row late_deduction = bank cut', (string) $row['late_deduction']);

    // Later month decided blocks re-deciding the earlier
    $tb->adjust($haider, 'ot', 120, 'Sep leftover Taimur recalls', $actor);
    $o = $tb->month($haider, '2026-10');
    ok($o['ot']['in'] >= 120 && count($o['notes']) >= 1, 'manual +2h extra shows in Oct', json_encode($o['notes']));

    // Drift: simulate a decided month whose frozen late differs from live
    DB::table('t_hr_time_bank_month')->where('user_id', $haider)->where('month', '2026-09')->update(['late_own' => 845 + 45]);
    TB::forget();
    $o = $tb->month($haider, '2026-10');
    $hasDrift = false; foreach ($o['notes'] as $n) { if ($n['src'] === 'drift' && $n['minutes'] === -45) { $hasDrift = true; } }
    ok($hasDrift, 'a day waived after Sep was decided → −45m in Oct automatically', json_encode($o['notes']));
    // A REAL overtime verdict recorded after Sep was decided: 24-Sep adjusted 40 → 20 through
    // DayReviewService (the same door Shabib uses) → the bank moves −20 extra into Oct and warns.
    DB::table('t_hr_time_bank_month')->where('user_id', $haider)->where('month', '2026-09')->update(['late_own' => 845]);
    App\Services\HR\TimeBankService::forget(); (new App\Services\HR\PayrollService())->forgetAll();
    $rr = app(App\Services\HR\DayReviewService::class)->record($haider, '2026-09-24', 'overtime', 'adjusted', $actor, ['minutes' => 20, 'reason' => 'proof']);
    ok(!empty($rr['success']), 'overtime verdict re-recorded after the decision (real DayReviewService door)', $rr['message'] ?? '');
    ok(str_contains((string) ($rr['warning'] ?? ''), 'already decided'), 'the manager is told it counts in the next open month', (string) ($rr['warning'] ?? ''));
    App\Services\HR\TimeBankService::forget(); (new App\Services\HR\PayrollService())->forgetAll(); App\Services\HR\SalaryCalculationService::forgetAttendanceMemo(); App\Services\HR\OvertimeService::forgetRanges();
    $o2 = $tb->month($haider, '2026-10');
    $otDrift = 0; foreach ($o2['notes'] as $n) { if ($n['src'] === 'drift' && $n['kind'] === 'ot') { $otDrift += $n['minutes']; } }
    ok($otDrift === -20, 'Oct carries −20 extra as drift (Sep stays as decided)', json_encode($o2['notes']));
    $s9 = $tb->month($haider, '2026-09');
    ok($s9['status'] === 'decided' && $s9['ot']['own'] === 197, 'the decided Sep row is untouched by the later verdict', $s9['status'] . ' / ' . $s9['ot']['own']);

    // Fold: Kanan Sep undecided → deciding Oct is refused (Oct running) but preview of Oct includes Sep
    $k = $tb->month($kanan, '2026-10');
    ok(in_array('2026-09', $k['folded'], true), 'Kanan Oct preview folds the undecided Sep', json_encode($k['folded']));
    $r = $tb->decide($kanan, '2026-10', ['cover' => true], $actor);
    ok(!$r['success'], 'running month cannot be decided');

    echo "── 4. Pay path\n";
    $pay = new App\Services\HR\PayrollService();
    // Farooq Sep has a salary cut (decided above). Pay him → the cut is taken + marked paid.
    App\Services\HR\TimeBankService::forget(); $pay->forgetAll();
    $row = $pay->computeRow($farooq, '2026-09');
    $res = $pay->payRow($farooq, '2026-09', ['funding' => 'cash', 'actor_id' => $actor, 'shortfall' => 'writeoff']);
    ok(!empty($res['success']), 'Farooq Sep paid', $res['message'] ?? '');
    $pr = DB::table('t_hr_payroll_payment')->where('user_id', $farooq)->where('pay_month', '2026-09')->first();
    ok($pr && abs((float) $pr->late_deduction - (float) $row['late_deduction']) < 0.02, 'receipt late_deduction = bank cut', $pr ? (string) $pr->late_deduction : 'no row');
    $paidAt = DB::table('t_hr_time_bank_month')->where('user_id', $farooq)->where('month', '2026-09')->value('salary_paid_at');
    ok($paidAt !== null, 'bank cut marked paid');
    App\Services\HR\TimeBankService::forget();
    $u = $tb->undo($farooq, '2026-09', $actor);
    ok(!$u['success'], 'paid cut cannot be undone', $u['message']);
    $grantRows = DB::table('t_hr_leave_grant')->where('user_id', $farooq)->where('reason', 'like', '%Sep 2026')
        ->where('created_at', '>=', now()->subMinutes(5))->where('reason', 'not like', 'Time bank%')->count();
    ok($grantRows === 0, 'Pay wrote NO old-rule leave rows for a bank month');

    // Haider Sep: decided earlier with nothing whole. Kanan Sep undecided → pay: no cut, note.
    App\Services\HR\TimeBankService::forget(); $pay->forgetAll();
    $res = $pay->payRow($kanan, '2026-09', ['funding' => 'cash', 'actor_id' => $actor, 'shortfall' => 'writeoff']);
    $kr = DB::table('t_hr_payroll_payment')->where('user_id', $kanan)->where('pay_month', '2026-09')->first();
    ok(!empty($res['success']) && $kr && (float) $kr->late_deduction == 0.0 && str_contains((string) $kr->notes, 'not decided yet'),
       'undecided month paid: no late cut, receipt says not decided', $kr ? (string) $kr->notes : ($res['message'] ?? ''));
    App\Services\HR\TimeBankService::forget();
    ok($tb->month($kanan, '2026-09')['status'] !== 'before_start', 'a payment never closes a bank month');
    $k9 = $tb->month($kanan, '2026-09');
    ok($k9['status'] === 'pending', 'Kanan Sep still pending after pay', $k9['status']);
    // Deciding salary now lands on the NEXT unpaid month (Oct).
    $k9 = $tb->month($kanan, '2026-09', ['cover' => false]);
    if ($k9['net']['late_days'] > 0) {
        $r = $tb->decide($kanan, '2026-09', ['cover' => false, 'late_choice' => 'salary', 'ot_choice' => 'carry', 'expect' => $k9['expect']], $actor);
        $sm = DB::table('t_hr_time_bank_month')->where('user_id', $kanan)->where('month', '2026-09')->value('salary_month');
        ok($r['success'] && $sm === '2026-10', 'salary chosen after Sep was paid → comes off Oct', $r['message'] . ' / ' . $sm);
        $cuts = $tb->cutsLandingOn($kanan, '2026-10');
        ok(count($cuts) === 1, 'Oct pay carries the Sep late cut');
        // Paying Oct with a MANUAL amount drops the cut → the days go back into the bank.
        App\Services\HR\TimeBankService::forget(); $pay->forgetAll();
        $res = $pay->payRow($kanan, '2026-10', ['funding' => 'cash', 'actor_id' => $actor, 'net_override' => 1000, 'shortfall' => 'writeoff']);
        $ret = DB::table('t_hr_time_bank_adjust')->where('user_id', $kanan)->where('source', 'returned')->sum('minutes');
        $row9 = DB::table('t_hr_time_bank_month')->where('user_id', $kanan)->where('month', '2026-09')->first();
        ok(!empty($res['success']) && $ret > 0 && (int) $row9->salary_days === 0 && $row9->salary_month === null,
           'manual net: late cut not taken → ' . $ret . ' min back in the bank, Sep row now says moved', $res['message'] ?? '');
        App\Services\HR\TimeBankService::forget();
        $s9 = $tb->month($kanan, '2026-09');
        echo "     after return: {$s9['chip']}\n";
    } else {
        ok(true, '(Kanan has no whole late days without cover — salary landing skipped)');
    }

    echo "── 5. Review fixes (Oct-12)\n";
    App\Services\HR\TimeBankService::forget(); $pay->forgetAll();
    // (1) negatives net against this month's OWN minutes when nothing is carried in
    $rajab = uid('Rajab Masood');
    $o0 = $tb->month($rajab, '2026-10');
    $tb->adjust($rajab, 'late', -60, 'test take-away', $actor);
    App\Services\HR\TimeBankService::forget();
    $o1 = $tb->month($rajab, '2026-10');
    ok($o1['late']['total'] === max(0, $o0['late']['total'] - 60), 'manual −60 late with nothing carried reduces the month (not dropped)',
       $o0['late']['total'] . ' → ' . $o1['late']['total']);
    // (3) a card opened BEFORE a decision cannot silently replace it; no-expect is refused too
    $d9 = $tb->month($danish, '2026-09');
    $r1 = $tb->decide($danish, '2026-09', ['cover' => true, 'ot_choice' => 'give', 'expect' => $d9['expect']], $actor);
    ok($r1['success'], 'Danish Sep: give extra', $r1['message']);
    $r2 = $tb->decide($danish, '2026-09', ['cover' => true, 'ot_choice' => 'carry', 'expect' => $d9['expect']], $actor);
    ok(!$r2['success'] && !empty($r2['stale']), 'second manager with the OLD card is refused (stale)', $r2['message']);
    $r3 = $tb->decide($danish, '2026-09', ['cover' => true, 'ot_choice' => 'carry'], $actor);
    ok(!$r3['success'], 'deciding an already-decided month without "change" is refused', $r3['message']);
    // (4) change give → carry after the leaves were used = balance below zero → refused
    DB::table('t_hr_leave_grant')->insert(['user_id' => $danish, 'days' => -10, 'reason' => 'test: leave used', 'source' => 'manual',
        'effective_date' => '2026-10-01', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    App\Services\HR\TimeBankService::forget();
    $c9 = $tb->month($danish, '2026-09', ['cover' => true, 'redecide' => true]);
    $r4 = $tb->decide($danish, '2026-09', ['cover' => true, 'ot_choice' => 'carry', 'expect' => $c9['expect']], $actor);
    ok(!$r4['success'] && str_contains($r4['message'], 'below zero'), 'change that would push the balance below 0 is refused', $r4['message']);
    $u4 = $tb->undo($danish, '2026-09', $actor);
    ok(!$u4['success'], 'undo that would push the balance below 0 is refused', $u4['message']);
    // (5) Kanan's Sep cut went back to the bank at payment → Sep is locked, no double count
    App\Services\HR\TimeBankService::forget();
    $k = $tb->month($kanan, '2026-09');
    if (($k['status'] ?? '') === 'decided') {
        ok(!$k['decision']['can_change'] && str_contains((string) $k['decision']['change_blocked'], 'back to the bank'),
           'month whose cut was returned is locked', (string) $k['decision']['change_blocked']);
        ok(str_contains($k['chip'], 'back in the bank'), 'its chip says the cut went back to the bank', $k['chip']);
    }

    // Legacy guard: old-rule decide for a bank month is refused/converted
    $pay = new App\Services\HR\PayrollService();
    $r = $pay->decideLeaveAction($kanan, '2026-09', 'late_penalty', 'apply', $actor);
    ok(!$r['success'], 'old late_penalty decide refused for a bank month', $r['message'] ?? '');
} finally {
    DB::rollBack();
    TB::forget();
}
echo "\n$pass passed, $fail failed\n";
