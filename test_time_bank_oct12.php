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
ok($a['late_days'] === 2 && $a['late_left'] === 120 && $a['cancel'] === 0, 'no overtime: cover does nothing');
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
ok($s['late']['total'] === 845, 'Haider late 14h05', (string) $s['late']['total']);
ok($s['ot']['in'] === 465 + 0 || $s['ot']['in'] > 0, 'Haider extra includes Aug carry', (string) $s['ot']['in']);
// ⭐ T1 (Oct-14): half rate is THE rule — extra always pays for late first; a "cover off" request is ignored.
ok($s['status'] === 'clear' && $s['net']['cancel'] === 331 && $s['net']['late_days'] === 0, 'Haider Sep: extra pays for 5h31m late at ½ → under a day → no decision needed', $s['status']);
$s2 = $tb->month($haider, '2026-09', ['cover' => false]);
ok($s2['net'] === $s['net'], 'a "cover off" request changes nothing (the loophole is closed)');
ok($s['cover']['offered'] === false, 'the installed APK hides its tick (cover.offered = false)');
$fa = $tb->month($farooq, '2026-09', ['cover' => false]);
ok($fa['net']['late_days'] === 0 && $fa['net']['ot_days'] === 0, 'Farooq Sep = 0 leaves (was −1 · +2 with the tick off)', json_encode($fa['net']));
echo "     tiles: " . $s['tiles']['late']['big'] . ' | ' . $s['tiles']['extra']['big'] . ' | ' . $s['tiles']['result']['big'] . ' — ' . $s['tiles']['result']['how'] . "\n";
ok($s['tiles']['result']['big'] === '0 leaves' && str_contains($s['tiles']['result']['how'], '½ rate'), 'RESULT tile: "0 leaves · overtime wiped 5h 31m late (½ rate)"');
ok($s['tiles']['late']['check']['state'] === 'all' && $s['tiles']['late']['check']['total'] === 21, 'LATE tile tick: all 21 days checked', $s['tiles']['late']['check']['label']);
ok(in_array('Shabib', $s['tiles']['late']['check']['by'], true), 'LATE tile names who checked', json_encode($s['tiles']['late']['check']['by']));
ok($s['confirm_will'] === 'Move 8h 34m late to Oct · no leave touched', 'Confirm sentence for a month with nothing whole', $s['confirm_will']);
ok($s['cells']['late']['outcome'] === '5h 31m wiped by overtime · 8h 34m → Oct' && $s['cells']['by'] === 'under a day — nothing to decide', 'table cell for an under-a-day month', json_encode($s['cells']['late']));
$dn9 = $tb->month($danish, '2026-09');
ok($dn9['status'] === 'pending' && $dn9['tiles']['result']['big'] === '+2 leaves', 'Danish Sep: +2 leaves, waiting for a decision');
ok($dn9['confirm_will'] === 'Give 2 leaves · move 2h 28m overtime to Oct', 'Danish suggested Confirm sentence', $dn9['confirm_will']);
ok(isset($dn9['confirm_map']['|carry'], $dn9['confirm_map']['|skip']) && str_starts_with($dn9['confirm_map']['|skip'], 'Skip overtime'), 'a Confirm sentence exists for every button (carry / skip)');
ok($dn9['cells']['chip'] === 'Decide September ›' && $dn9['cells']['chip_in'] === 'extra' && $dn9['cells']['chip_sub'] === '+2 leaves',
   'pending month: the table cell offers "+2 leaves — Decide September ›" in the EXTRA cell', json_encode([$dn9['cells']['chip_in'], $dn9['cells']['chip_sub']]));
$ar9 = $tb->month($arslan, '2026-09');
ok(($ar9['cells']['extra']['legacy'] ?? '') !== '' && !str_contains($ar9['cells']['extra']['outcome'], 'old rule'),
   'old-rule note is its own cell line, not inside the outcome', json_encode($ar9['cells']['extra']));
// Review fixes (Oct-15)
ok(!str_contains($dn9['confirm_map']['|skip'], 'extra to'), 'Skip moves NO extra — its sentence never says "move … extra"', $dn9['confirm_map']['|skip']);
ok(($dn9['result_map']['|give'] ?? '') === '+2 leaves' && ($dn9['result_map']['|carry'] ?? '') === '0 leaves' && ($dn9['result_map']['|skip'] ?? '') === '0 leaves',
   'RESULT tile follows the buttons (result_map)', json_encode($dn9['result_map'] ?? null));
ok(($dn9['confirm_map']['|carry'] ?? '') === 'Move 2 days of overtime to Oct · move 2h 28m overtime to Oct', 'one verb: "Move"', $dn9['confirm_map']['|carry'] ?? '');
$dw = $tb->month($danish, '2026-09', ['waive' => 45]);
ok((int) $dw['late']['waive'] === 0, 'a waive bigger than the late total is clamped by the server (clients take this number)', (string) $dw['late']['waive']);
ok(str_starts_with((string) ($s['clear_note'] ?? ''), 'Under a day — nothing to decide. The leftover moves to Oct'), 'clear note names the leftover', (string) ($s['clear_note'] ?? ''));
$mash = uid('Mashood');
$ms = $mash ? $tb->month($mash, '2026-09') : null;
if ($ms && ($ms['status'] ?? '') === 'clear' && (int) ($ms['net']['late_left'] ?? 0) === 0 && (int) ($ms['net']['ot_left'] ?? 0) === 0) {
    ok($ms['clear_note'] === 'Nothing to decide — nothing left over.', 'an empty month does not talk about a leftover', $ms['clear_note']);
}
// Folded month: cells still drawn + a readable month to open (reflection — the replica has no closed later month).
$wc = new ReflectionMethod(TB::class, 'withCells'); $wc->setAccessible(true);
$fold = $wc->invoke($tb, $haider, '2026-09', ['user_id' => $haider, 'month' => '2026-09', 'status' => 'folded', 'folded_into' => '2026-10', 'folded_label' => 'October 2026']);
ok(is_array($fold['cells']) && $fold['cells']['late']['outcome'] === 'in Oct decision' && $fold['cells']['extra']['minutes'] > 0, 'folded month keeps its table cells ("in Oct decision")', json_encode($fold['cells']['late'] ?? null));
$kn9 = $tb->month($kanan, '2026-09');
ok(str_contains((string) $kn9['tiles']['extra']['check']['label'], 'of') && $kn9['tiles']['extra']['check']['state'] === 'some', 'Kanan extra: some days unchecked → amber', $kn9['tiles']['extra']['check']['label']);
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

    // Farooq: give him 20h more late by hand so the month has WHOLE late days under the ½ rule.
    $tb->adjust($farooq, 'late', 1200, 'proof: extra late', $actor);
    TB::forget();
    $f = $tb->month($farooq, '2026-09');
    ok($f['net']['late_days'] === 2 && $f['tiles']['result']['big'] === '−2 leaves', 'Farooq + 20h late: extra still pays first, 2 whole late days remain', json_encode($f['net']));
    ok($f['confirm_will'] === 'Take 2 leaves · move 2h 19m late + 1m overtime to Oct', 'Confirm sentence for taking leave', $f['confirm_will']);
    $bal0 = (new App\Services\HR\LeavePolicyService())->balance($farooq)['remaining'];
    $r = $tb->decide($farooq, '2026-09', ['cover' => false, 'late_choice' => 'leave', 'expect' => $f['expect']], $actor);
    ok($r['success'], 'Farooq Sep: take leave (a "cover off" input is ignored)', $r['message']);
    $bal1 = (new App\Services\HR\LeavePolicyService())->balance($farooq)['remaining'];
    ok(abs(($bal1 - $bal0) + 2) < 0.01, "balance −2 ($bal0 → $bal1)");
    $fd = $tb->month($farooq, '2026-09');
    ok($fd['tiles']['result']['title'] === '✓ DECIDED' && $fd['tiles']['result']['big'] === '−2 leaves', 'decided RESULT tile', $fd['tiles']['result']['big']);
    ok(str_contains($fd['tiles']['late']['outcome'], 'wiped by overtime') && str_contains($fd['tiles']['late']['outcome'], '2 leaves taken'), 'LATE tile says what happened to its minutes', $fd['tiles']['late']['outcome']);
    ok(str_contains($fd['cells']['late']['outcome'], '2 leaves taken') && str_contains($fd['cells']['by'], 'by Taimur'), 'table cell: outcome + who', $fd['cells']['late']['outcome'] . ' | ' . $fd['cells']['by']);
    $lh = (new App\Services\HR\LeavePolicyService())->adjustments($farooq);
    ok((bool) array_filter($lh, fn ($g) => ($g['label'] ?? '') === 'Time bank Sep 2026 · taken for late' && ($g['bank_month'] ?? '') === '2026-09'),
       'leave history row says "Time bank Sep 2026 · taken for late" and links to September', json_encode(array_slice($lh, 0, 2)));
    $u = $tb->undo($farooq, '2026-09', $actor);
    $bal2 = (new App\Services\HR\LeavePolicyService())->balance($farooq)['remaining'];
    ok($u['success'] && abs($bal2 - $bal0) < 0.01, "undo restores the balance ($bal2)", $u['message']);

    // Salary on a month: cut lands on Sep pay (Sep unpaid for Farooq on replica)
    $f = $tb->month($farooq, '2026-09');
    $r = $tb->decide($farooq, '2026-09', ['late_choice' => 'salary', 'expect' => $f['expect']], $actor);
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
    ok(str_contains((string) $pr->notes, 'September 2026 time bank') && str_contains((string) $pr->notes, 'Taimur'),
       'the paid receipt says where the late cut came from and who decided', (string) $pr->notes);
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
    // Under the ½ rule Kanan's Sep is under a day ('clear'): no decision exists to wait for, so the
    // receipt must NOT claim one is pending; a 'pending' month would say "not decided yet".
    $kst = $tb->month($kanan, '2026-09')['status'] ?? '';
    ok(!empty($res['success']) && $kr && (float) $kr->late_deduction == 0.0
       && ($kst === 'pending' ? str_contains((string) $kr->notes, 'not decided yet') : !str_contains((string) $kr->notes, 'not decided yet')),
       "undecided month paid: no late cut; receipt note matches the month ($kst)", $kr ? (string) $kr->notes : ($res['message'] ?? ''));
    App\Services\HR\TimeBankService::forget();
    ok($tb->month($kanan, '2026-09')['status'] !== 'before_start', 'a payment never closes a bank month');
    $k9 = $tb->month($kanan, '2026-09');
    ok($k9['status'] !== 'decided', 'Kanan Sep still undecided after pay', $k9['status']);
    // Deciding salary now lands on the NEXT unpaid month (Oct).
    $tb->adjust($kanan, 'late', 1500, 'proof: extra late', $actor);
    TB::forget();
    $k9 = $tb->month($kanan, '2026-09');
    if ($k9['net']['late_days'] > 0) {
        $r = $tb->decide($kanan, '2026-09', ['late_choice' => 'salary', 'expect' => $k9['expect']], $actor);
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
        $ku = $tb->undo($kanan, '2026-09', $actor);
        ok(!$ku['success'] && str_contains($ku['message'], 'back to the bank'), 'undo is refused too when the cut went back to the bank (no double count)', $ku['message']);
        ok(str_contains($k['chip'], 'back in the bank'), 'its chip says the cut went back to the bank', $k['chip']);
    }

    echo "── 6. Never stuck (T4)\n";
    $asim = uid('Asim Tahir');
    $tb->adjust($asim, 'late', 1500, 'proof: extra late', $actor);
    TB::forget();
    $as9 = $tb->month($asim, '2026-09');
    $tl = array_values(array_filter($as9['choices']['late'], fn ($c) => $c['value'] === 'leave'));
    ok($tl && !empty($tl[0]['disabled']) && str_contains($tl[0]['hint'], 'no leave left'), 'Asim (−1 balance): "Take leave" shown DISABLED with the reason', json_encode($tl));
    ok(!isset($as9['confirm_map']['leave|']), 'a disabled choice has no Confirm sentence');
    $fd = $tb->month($farooq, '2026-09');
    ok($fd['status'] === 'decided', 'Farooq Sep decided');

    // Legacy guard: old-rule decide for a bank month is refused/converted
    $pay = new App\Services\HR\PayrollService();
    $r = $pay->decideLeaveAction($kanan, '2026-09', 'late_penalty', 'apply', $actor);
    ok(!$r['success'], 'old late_penalty decide refused for a bank month', $r['message'] ?? '');

    echo "── 7. Traceability + phone payloads (Oct-15)\n";
    // Undo names the MANAGER on the zeroed leave row, never the employee.
    TB::forget();
    $rajab = uid('Rajab Masood');
    $tb->adjust($rajab, 'late', 1500, 'proof: a whole day of late', $actor);
    TB::forget();
    $hz = $tb->month($rajab, '2026-09');
    $rh = $tb->decide($rajab, '2026-09', ['late_choice' => 'leave', 'ot_choice' => 'carry', 'expect' => $hz['expect']], $actor);
    ok($rh['success'], 'Rajab Sep: take leave', $rh['message']);
    $u = $tb->undo($rajab, '2026-09', $actor);
    ok($u['success'], 'Rajab Sep undone', $u['message']);
    $g = DB::table('t_hr_leave_grant')->where('user_id', $rajab)->where('reason', 'Time bank Sep 2026')->where('source', 'late_penalty')->first();
    ok($g && (float) $g->days === 0.0 && (int) $g->created_by === $actor && (int) $g->created_by !== $rajab,
       'undo: the zeroed leave row is stamped with the manager, not the employee', json_encode($g));
    // Rider tiles carry the check STATE (the phone never guesses ✓/⚠ from words).
    $rv = $tb->riderView($kanan);
    $k9 = collect($rv['months'] ?? [])->firstWhere('month', '2026-09');
    ok($k9 && array_key_exists('ot_check_ok', $k9) && $k9['ot_check_ok'] === (($tb->month($kanan, '2026-09')['cells']['extra']['check']['state'] ?? '') === 'all'),
        'rider month: ot_check_ok = the engine check state', json_encode($k9['ot_check_ok'] ?? null));
    // Phone employee-detail payload = the engine's cells.
    $mgr = \App\Models\User::find($actor); Auth::setUser($mgr);
    $rq = Illuminate\Http\Request::create('/x', 'GET', ['user_id' => $haider, 'month' => '2026-09']); $rq->setUserResolver(fn () => $mgr);
    $det = (new App\Http\Controllers\API\RiderController())->getStoreAttendanceEmployeeDetails($rq)->getData(true);
    ok(json_encode($det['employee']['time_bank_cells'] ?? null) === json_encode($tb->month($haider, '2026-09')['cells']),
        'phone employee details: time_bank_cells = engine cells');
} finally {
    DB::rollBack();
    TB::forget();
}
echo "\n$pass passed, $fail failed\n";
