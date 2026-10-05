<?php
/**
 * RULE P MUST MOVE WITH THE BIKE — AND STILL NEVER RUN BACKWARDS (5-Oct-2026).
 *
 * Two incidents on one day, both in `VehicleService::readingPlausibleFor`:
 *
 *  1. THE CAP. EGL-682's only machine-keyed reading was a 29-Aug meter log at 17,198, so the
 *     window topped out at 17,198 + 20% = 20,638 for ever. The bike honestly passed it on
 *     28-Sep; from then its attendance stamps silently stopped and the service door refused
 *     21,459 ("last seen at 21,459") — the workshop visit could not be closed.
 *     Fix: the highest reading STAMPED to the machine joins the spine.
 *
 *  2. THE REGRESSION THAT FIX CAUSED. The wider window let Waseem's DCR-799 fills of 1-Aug
 *     (24,153–24,588) attach to EGL-682 again by the first-keeper window guess (the Aug-28
 *     CEN-455 incident), and every later fill of Danish's read "meter vs last fill doesn't
 *     add up". Fix: a DATED reading may not exceed what the machine showed on a LATER day.
 *
 * ⚠ Asserts RULES, not this month's numbers: every figure is derived from the data, so the
 *   suite keeps meaning something as the bikes keep running. Read-only.
 * Run:  php test_rule_p_moving_spine.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Riders\VehicleService;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function ok(string $what, bool $good, string $detail = '') {
    global $pass, $fail;
    $good ? $pass++ : $fail++;
    echo ($good ? '  ✓ ' : '  ✗ ') . $what . ($detail !== '' ? "  [$detail]" : '') . "\n";
}
$svc = new VehicleService();
VehicleService::flushServiceMemo();

echo "\n== §1 every company machine accepts its own latest stamped reading ==\n";
// The cap bug in general form: a machine that keeps running must keep being itself.
foreach (DB::table('t_ops_vehicle')->where('is_active', 1)->get(['id', 'reg_no', 'nickname']) as $v) {
    $hi = null;
    foreach (['meter_start', 'meter_end'] as $c) {
        $m = DB::table('t_ops_attendance')->where($c . '_vehicle_id', $v->id)->where($c, '>', VehicleService::MIN_METER)->max($c);
        if ($m !== null) $hi = max((int) $hi, (int) $m);
    }
    if ($hi === null) continue;
    $name = $v->reg_no ?: $v->nickname;
    ok("$name accepts its own " . number_format($hi), $svc->readingPlausibleFor((int) $v->id, $hi));
    ok("  …and a normal week's running past it (+1,000)", $svc->readingPlausibleFor((int) $v->id, $hi + 1000));
}

echo "\n== §2 EGL-682 — the 5-Oct incident ==\n";
$EGL = (int) DB::table('t_ops_vehicle')->where('reg_no', 'EGL-682')->value('id');
$cur = $svc->currentMeterFor($EGL);
ok('the reading the service door refused ("last seen at") is accepted', $svc->readingPlausibleFor($EGL, (int) $cur), 'current ' . $cur);
ok('a dropped digit is still refused', !$svc->readingPlausibleFor($EGL, intdiv((int) $cur, 10)));
ok('an extra digit is still refused', !$svc->readingPlausibleFor($EGL, (int) $cur * 10));
ok('the van\'s ~75k is still refused', !$svc->readingPlausibleFor($EGL, 75000));

echo "\n== §3 an odometer never runs backwards (dated readings) ==\n";
// Every UNSTAMPED claim of another rider that the first-keeper window could sweep onto
// EGL-682 must be refused when its date says the bike was already lower later on.
$waseem = DB::table('t_req_master')->where('requester_user_id', 73)->whereNull('vehicle_id')
    ->where('meter_at_fill', '>=', 24000)->where('meter_at_fill', '<', 25000)
    ->get(['id', 'meter_at_fill', 'expense_date', 'created_at']);
ok('found Waseem\'s DCR-799 24k fills to test with', $waseem->count() > 0, (string) $waseem->count());
$leak = [];
foreach ($waseem as $c) {
    $d = substr((string) ($c->expense_date ?: $c->created_at), 0, 10);
    if ($svc->readingPlausibleFor($EGL, (int) $c->meter_at_fill, $d)) $leak[] = $c->id;
}
ok('none of them is plausible for EGL-682 on its own date', !$leak, implode(',', $leak));
$onEgl = array_map(fn ($c) => (int) $c['id'], $svc->claimsForVehicle($EGL, '2025-01-01', '2026-12-31'));
ok('…and none is attributed to EGL-682', !array_intersect($onEgl, $waseem->pluck('id')->map(fn ($v) => (int) $v)->all()));
ok('an undated reading is unaffected by the date rule (fails open as before)',
   $svc->readingPlausibleFor($EGL, (int) $cur) === $svc->readingPlausibleFor($EGL, (int) $cur, null));
ok('today\'s real reading passes WITH its date', $svc->readingPlausibleFor($EGL, (int) $cur, date('Y-m-d')));

echo "\n== §4 the fill chain the owner looks at ==\n";
// Danish's fills on EGL-682 must chain from each other, never from another bike's anchor.
$f = app(\App\Services\Riders\FleetFuelService::class);
$odd = 0; $n = 0;
foreach (($f->riderMonth(84, date('Y-m')) ['days'] ?? []) as $day) {
    foreach (($day['claims'] ?? []) as $c) {
        if (($c['kind'] ?? '') !== 'fuel' || empty($c['meter_at_fill'])) continue;
        $n++; if (!empty($c['km_since_fill_odd'])) $odd++;
    }
}
ok("Danish's fills this month: none flagged \"meter vs last fill doesn't add up\"", $odd === 0, "$odd of $n");

echo "\n" . str_repeat('─', 60) . "\n";
echo ($fail === 0 ? 'ALL GREEN' : 'FAILURES') . " — passed {$pass}, failed {$fail}\n";
exit($fail === 0 ? 0 : 1);
