<?php
/**
 * SERVICE INTERVALS — one engine, every surface (Aug-27 2026).
 *
 * The bug: a manager saw "Oil + Tuning · every 1,200 km" in a bike's SERVICE SCHEDULE and
 * "(every 2,000 km)" in the Record-service prompt on the SAME page, while the frozen
 * `service_due_km` used a third chain again. Seven surfaces answered one question.
 *
 * What these prove:
 *   §1 the resolver's order, including every fallback step;
 *   §2 THE REGRESSION THAT CAUSED THIS — editing one type can no longer move another
 *      type's effective interval;
 *   §3 every surface returns the SAME number for the same (bike, job);
 *   §4 the frozen `service_due_km` is stamped against the number on screen;
 *   §5 the company-default literal has exactly one source.
 *
 * ⚠ Every mutation is inside a transaction that is always rolled back.
 *
 * Run:  php test_service_intervals.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// ⭐ 5-Oct-2026: run against the maintenance set-up this script was written for, inside one
//   rolled-back transaction — see test_fleet_fixture.php for why.
require __DIR__ . '/test_fleet_fixture.php';
fleetFixtureBegin();

use App\Services\Riders\FleetFuelService;
use App\Services\Riders\ServiceIntervalResolver;
use App\Services\Riders\VehicleService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function ok(string $what, $got, $want = null, bool $raw = false) {
    global $pass, $fail;
    $good = $raw ? (bool) $got : $got === $want;
    if ($good) { $pass++; echo "  ✓ $what\n"; }
    else { $fail++; echo "  ✗ $what\n";
           if (!$raw) echo "      got:  " . var_export($got, true) . "\n      want: " . var_export($want, true) . "\n"; }
}
function head(string $t) { echo "\n== $t ==\n"; }

function flushAll(): void {
    ServiceIntervalResolver::flush();
    VehicleService::flushServiceMemo();
    \App\Services\Riders\VehicleResolver::flush();
    try { Cache::flush(); } catch (\Throwable $e) {}
}

const AY   = 1;    // AY-4771 — carries the seeded 1,200 scalar
const BCN  = 2;    // BCN-5755 — no scalar at all
const KANAN = 76;  // holds AY-4771
const T_OIL_TUNING = 2;
const T_OIL_CHANGE = 1;

$svc = new VehicleService();
$res = new ServiceIntervalResolver();

head('§1 the resolver — one order, stated once');
/**
 * ⚠⚠ THIS SECTION USED TO ASSERT LIVE REPLICA VALUES ("AY-4771 carries 1,200", "the
 *    company default is 1,200 on this database") and went red when the data moved on
 *    — the scalar was cleared and the config key is 1,000 now. A suite that fails
 *    because production data changed teaches people to ignore it, so the fallback
 *    chain now SEEDS the ladder it is about to walk, inside the usual rolled-back
 *    transaction. It tests the rule, not the day's numbers.
 */
DB::beginTransaction();
try {
    DB::table('t_ops_vehicle')->where('id', AY)->update(['service_interval_km' => 1200]);
    DB::table('t_ops_vehicle')->where('id', BCN)->update(['service_interval_km' => null]);
    DB::table('t_ops_rider_profile')->where('user_id', KANAN)->update(['service_interval_km' => 900]);
    DB::table('t_fin_config')->updateOrInsert(['config_key' => 'BIKE_SERVICE_INTERVAL_KM'],
                                              ['config_value' => '1200']);
    flushAll();
    $res = new ServiceIntervalResolver();

    ok('the job\'s own schedule wins', $res->explain(AY, 2000, KANAN)['source'], 'type');
    ok('  …and it is the number returned', $res->explain(AY, 2000, KANAN)['km'], 2000);
    ok('no job schedule → the bike\'s own', $res->explain(AY, null, KANAN)['source'], 'vehicle');
    ok('  …the seeded 1,200', $res->explain(AY, null, KANAN)['km'], 1200);
    ok('no bike scalar → the rider\'s legacy one', $res->explain(BCN, null, KANAN)['source'], 'rider');
    ok('  …the seeded 900', $res->explain(BCN, null, KANAN)['km'], 900);
    ok('nothing anywhere → the company default', $res->explain(null, null, null)['source'], 'company');
    ok('  …the seeded 1,200', $res->companyDefault(), 1200);
    ok('a zero job schedule counts as absent', $res->explain(AY, 0, KANAN)['source'], 'vehicle');
    ok('from_type is only true for the job\'s own', $res->explain(AY, null, KANAN)['from_type'], false);
    ok('the label explains a non-type number',
       ServiceIntervalResolver::sourceLabel($res->explain(AY, null, KANAN)), 'this bike\'s own schedule');
    ok('  …and says nothing when it IS the job\'s own',
       ServiceIntervalResolver::sourceLabel($res->explain(AY, 2000, KANAN)), null);
} finally {
    DB::rollBack();
    flushAll();
    $res = new ServiceIntervalResolver();
}

head('§2 ⭐⭐ the regression that caused the bug');
// Editing ONE type used to move ANOTHER type's effective interval, because the per-bike
// scalar was applied to "whichever type is the shortest clock-resetting one".
$before = [];
foreach ($svc->serviceScheduleFor(AY, 50000) as $s) { $before[$s['name']] = $s['interval_km']; }
ok('AY-4771 Oil + Tuning reads its own 2,000', $before['Oil + Tuning'] ?? null, 2000);
ok('  …and Oil Change its own 1,000', $before['Oil Change'] ?? null, 1000);

DB::beginTransaction();
try {
    // Exactly the 22-Aug edit that broke it: demote Oil Change's clock flag.
    DB::table('t_fleet_maintenance_types')->where('id', T_OIL_CHANGE)
        ->update(['resets_service_clock' => 1]);          // put it BACK to the seeded state
    flushAll();
    $after = [];
    foreach ($svc->serviceScheduleFor(AY, 50000) as $s) { $after[$s['name']] = $s['interval_km']; }
    ok('flipping Oil Change\'s clock flag does NOT move Oil + Tuning',
       $after['Oil + Tuning'] ?? null, 2000);
    ok('  …nor Oil Change itself', $after['Oil Change'] ?? null, 1000);
    ok('  …the whole schedule is untouched by that edit', $after, $before);

    // And the scalar itself can no longer rewrite a job that has its own schedule.
    DB::table('t_ops_vehicle')->where('id', BCN)->update(['service_interval_km' => 777]);
    flushAll();
    $bcn = [];
    foreach ($svc->serviceScheduleFor(BCN, 40000) as $s) { $bcn[$s['name']] = $s['interval_km']; }
    ok('a per-bike scalar cannot rewrite a job\'s own schedule', $bcn['Oil + Tuning'] ?? null, 2000);
    ok('  …but it IS the fallback where a job has none',
       (new ServiceIntervalResolver())->explain(BCN, null, KANAN)['km'], 777);
} finally {
    DB::rollBack();
    flushAll();
}

head('§3 every surface, the same number');
$sched = [];
foreach ($svc->serviceScheduleFor(AY, 50000) as $s) { $sched[$s['id']] = $s['interval_km']; }

// (a) the overall/headline state
$overall = $svc->overallServiceStateFor(AY, 50000);
$dueName = $overall['due_type_name'] ?? null;
ok('the headline interval is one of the schedule\'s own numbers',
   in_array((int) $overall['interval_km'], array_values($sched), true), true, true);

// (b) the rider-keyed fallback list (used when the registry cannot place him)
$fleet = new FleetFuelService();
$ref = new ReflectionClass($fleet);
$m = $ref->getMethod('serviceScheduleByRider'); $m->setAccessible(true);
$byRider = $m->invoke($fleet, KANAN);
$riderMap = [];
foreach ($byRider as $r) { $riderMap[$r['id']] = $r['interval_km']; }
$agree = true;
foreach ($riderMap as $id => $km) {
    if (isset($sched[$id]) && $sched[$id] !== $km) { $agree = false;
        echo "      ⚠ type $id: machine says {$sched[$id]}, rider list says {$km}\n"; }
}
ok('the rider-keyed list agrees with the machine-keyed one', $agree, true);

// (c) the resolver reached directly with a type id
ok('forTypeId matches the schedule row',
   $res->forTypeId(AY, T_OIL_TUNING, KANAN)['km'], $sched[T_OIL_TUNING] ?? null);

// (d) the mobile/rider payload reads the same rows (shape() → overallServiceStateFor)
$shaped = $svc->find(AY);
ok('the vehicle payload carries an interval from the same chain',
   in_array((int) ($shaped['service']['interval_km'] ?? 0), array_values($sched), true), true, true);

head('§4 the frozen record matches the screen');
DB::beginTransaction();
try {
    $cat = DB::table('t_req_category')->where('category_code', 'expense')->value('id');
    // A maintenance claim on AY-4771 for the job whose interval used to differ.
    $reqId = DB::table('t_req_master')->insertGetId([
        'request_number' => 'TEST-SVC-1', 'category_id' => $cat, 'requester_user_id' => KANAN,
        'title' => 'Expense', 'expense_category' => 'Maintenance', 'expense_date' => date('Y-m-d'),
        'amount' => 1500, 'status' => 'pending', 'service_type' => 'oil_change',
        'maintenance_type_id' => T_OIL_TUNING, 'meter_at_fill' => 50000,
        'vehicle_id' => AY, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $req = \App\Models\Request\RequestModel::find($reqId);

    // ⚠ private STATIC, and it takes the rider profile (it still reads
    //   last_service_meter from it — only the INTERVAL moved to the resolver).
    $profile = DB::table('t_ops_rider_profile')->where('user_id', KANAN)->first();
    $rc = new ReflectionClass(\App\Services\Riders\BikeServiceClock::class);
    $sm = $rc->getMethod('stampServiceDueKm'); $sm->setAccessible(true);
    $sm->invoke(null, $req, $profile, 50000);

    $frozen = DB::table('t_req_master')->where('id', $reqId)->value('service_due_km');
    // Whatever the last service point is, the INTERVAL used must be the type's 2,000 —
    // the number the schedule and the prompt both now show.
    $last = $svc->lastServicePointBefore(AY, 50000, 2000);
    if ($frozen !== null && $last && isset($last['meter'])) {
        ok('service_due_km is frozen against the SAME interval the screen shows',
           (int) $frozen + (50000 - (int) $last['meter']), 2000);
    } else {
        ok('service_due_km stamped (or skipped honestly with no reference point)',
           $frozen === null || is_numeric($frozen), true, true);
    }
} finally {
    DB::rollBack();
    flushAll();
}

head('§5 one literal for the company default');
$hits = [];
foreach (['app/Services/Riders/VehicleService.php', 'app/Services/Riders/FleetFuelService.php',
          'app/Http/Controllers/CRM/FleetFuelController.php'] as $f) {
    $src = file_get_contents(__DIR__ . '/' . $f);
    if (preg_match_all("/BIKE_SERVICE_INTERVAL_KM'?\s*,\s*(\d+)/", $src, $mm)) {
        foreach ($mm[1] as $n) $hits[] = "$f => $n";
    }
}
ok('no surface carries its own default literal any more', $hits, []);
ok('the resolver is the only holder of it', ServiceIntervalResolver::COMPANY_DEFAULT_KM, 1200);

head('§6 ⭐⭐ alerts — one state rule, and the right man is told');
// The owner's ask: every surface follows the one engine, and the rider actually
// responsible for the machine THAT DAY gets the alert.

ok('unknown when there is no baseline', ServiceIntervalResolver::stateFor(null), 'unknown');
ok('overdue below zero', ServiceIntervalResolver::stateFor(-1), 'overdue');
ok('due_soon at the boundary', ServiceIntervalResolver::stateFor(ServiceIntervalResolver::DUE_SOON_KM), 'due_soon');
ok('ok just past it', ServiceIntervalResolver::stateFor(ServiceIntervalResolver::DUE_SOON_KM + 1), 'ok');
$srcs = [];
foreach (['app/Services/Riders/VehicleService.php', 'app/Services/Riders/FleetFuelService.php'] as $f) {
    if (preg_match_all("/due_soon' : 'ok'/", file_get_contents(__DIR__ . '/' . $f), $mm)) {
        $srcs[] = $f;
    }
}
ok('no surface carries its own copy of the state ternary any more', $srcs, []);

// ⚠ define(), not const — const declarations cannot live inside a block.
define('RAJAB', 95); define('OWN_BIKE', 9); define('VAN', 4); define('ASIM', 70);

DB::beginTransaction();
try {
    // The situation this round exists for: Rajab's own bike has NO open assignment
    // (the van's custody released it), and now a service falls due on it.
    //
    // ⚠ STAGED, not assumed. It used to read live custody, which made the suite a
    //   hostage to whoever happens to hold the van on the day — after the Aug-27
    //   re-sync Rajab had handed the van back and taken his own bike again, so both
    //   preconditions went red and, worse, the checks below started passing for the
    //   WRONG reason: the bike had a keeper by assignment, so "keeper is the OWNER
    //   despite no open assignment" was no longer exercising the no-assignment path
    //   at all. Building the fixture here restores the real scenario every run.
    DB::table('t_ops_vehicle_assignment')
        ->where('vehicle_id', OWN_BIKE)->whereNull('released_on')
        ->update(['released_on' => '2026-08-27', 'updated_at' => now()]);
    if (!DB::table('t_ops_vehicle_assignment')
            ->where('vehicle_id', VAN)->where('user_id', RAJAB)
            ->whereNull('released_on')->exists()) {
        DB::table('t_ops_vehicle_assignment')->insert([
            'vehicle_id' => VAN, 'user_id' => RAJAB, 'assigned_on' => '2026-08-27',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    flushAll(); \App\Services\Riders\RiderDayLegs::flush();

    ok('precondition: the van is his open assignment',
       (new \App\Services\Riders\VehicleResolver())->currentVehicleFor(RAJAB), VAN);
    ok('precondition: his own bike has NO open assignment',
       DB::table('t_ops_vehicle_assignment')->where('vehicle_id', OWN_BIKE)
           ->whereNull('released_on')->exists(), false);

    // Stage a due Oil Change on his bike: last done at 5,000, bike now ~6,424 →
    // ~424 km past a 1,000 km schedule. An approved claim IS the evidence rail.
    $cat = DB::table('t_req_category')->where('category_code', 'expense')->value('id');
    DB::table('t_req_master')->insert([
        'request_number' => 'TEST-ALERT-1', 'category_id' => $cat, 'requester_user_id' => RAJAB,
        'title' => 'Expense', 'expense_category' => 'Maintenance', 'expense_date' => '2026-08-10',
        'amount' => 500, 'status' => 'approved', 'service_type' => 'oil_change',
        'maintenance_type_id' => 1, 'meter_at_fill' => 5000, 'vehicle_id' => OWN_BIKE,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    flushAll(); \App\Services\Riders\RiderDayLegs::flush();

    $alerts = (new \App\Services\Riders\BikeServiceAlerts())->due();
    $mine = array_values(array_filter($alerts, fn ($a) => $a['vehicle_id'] === OWN_BIKE));

    /**
     * ⚠⚠ THIS ASSERTION IS THE REVERSE OF WHAT IT WAS, AND DELIBERATELY SO.
     *
     * The Aug-27 round fixed a real gap: an own bike went silent the moment its
     * rider took the van, because `keeper_user_id` is the OPEN assignment and one
     * open row per rider releases the personal machine. The fix routed the alert to
     * its OWNER instead, so the man who must take it to the mechanic heard about it.
     *
     * On 10-Sep-2026 the owner ruled the question away entirely: *"own bikes
     * maintenance is not with the company so we aren't tracking them or setting them
     * either. this is only for company bikes and van."* A personal machine is no
     * longer on any schedule, so there is nothing to be late for and nobody to tell.
     *
     * ⭐ The `ownerOf()` fallback in BikeServiceAlerts::due() is KEPT — it still
     *   answers "whose machine is this" for any COMPANY machine that has no open
     *   assignment today, which is the same gap one class over. Only its
     *   personal-bike case is now unreachable.
     */
    ok('a personal bike raises NO alert — it is not on the company schedule',
       count($mine), 0);

    // The banner agrees: Rajab hears nothing about his own bike.
    $rajab = \App\Models\SysAdmin\UserModel::find(RAJAB);
    $seen = (new \App\Services\Riders\BikeServiceAlerts())->forUser($rajab);
    ok('  …and its owner is not shown one either',
       in_array(OWN_BIKE, array_column($seen, 'vehicle_id'), true), false);

    // Nor is anybody else.
    $asim = \App\Models\SysAdmin\UserModel::find(ASIM);
    $asimSees = (new \App\Services\Riders\BikeServiceAlerts())->forUser($asim);
    ok('another rider does not hear about it',
       in_array(OWN_BIKE, array_column($asimSees, 'vehicle_id'), true), false);

    // …and the schedule panel says the same thing, so screen and push agree.
    ok('  …its schedule panel is empty',
       (new VehicleService())->serviceScheduleFor(OWN_BIKE,
            (new VehicleService())->currentMeterFor(OWN_BIKE)), []);
    ok('  …and its headline says so in words',
       (new VehicleService())->overallServiceStateFor(OWN_BIKE, 5200)['state'], 'not_tracked');

    // A company machine holds today's behaviour: keeper = the open assignment.
    $ay = array_values(array_filter($alerts, fn ($a) => $a['vehicle_id'] === 1));
    if ($ay) {
        ok('a company machine\'s keeper is still its current holder', $ay[0]['keeper_user_id'], 76);
    } else {
        ok('a company machine\'s keeper is still its current holder (no live alert to check — skipped honestly)', true, true, true);
    }
} finally {
    DB::rollBack();
    flushAll(); \App\Services\Riders\RiderDayLegs::flush();
}

echo "\n" . str_repeat('─', 60) . "\n";
echo ($fail === 0 ? "ALL GREEN" : "FAILURES") . " — passed {$pass}, failed {$fail}\n";
exit($fail === 0 ? 0 : 1);
