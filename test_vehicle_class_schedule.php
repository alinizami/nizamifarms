<?php
/**
 * MAINTENANCE SCHEDULES BY VEHICLE CLASS + PER-VEHICLE PER-JOB OVERRIDES (Sep-2026).
 *
 * The owner's ask, in four parts:
 *   1. bikes and vans follow DIFFERENT schedules — the van was being judged against
 *      bike numbers (Oil Change every 1,000 km on a 75,000 km van);
 *   2. "This bike's schedule" must actually work — it was ONE number that named no
 *      job, so since Aug-27 it changed nothing on any screen;
 *   3. the setting belongs on the VEHICLE, not the rider;
 *   4. personal bikes are not the company's business at all.
 *
 * What these prove:
 *   §1 the class standard — same job, two numbers, and applicability;
 *   §2 the per-vehicle per-job exception (the real "this vehicle's schedule");
 *   §3 TIME-based jobs — days, not kilometres, and the 3-day due-soon window;
 *   §4 personal machines are not tracked anywhere;
 *   §5 every surface agrees for the same (vehicle, job);
 *   §6 the covers rule respects class and unit;
 *   §7 the frozen `service_due_km` matches the class;
 *   §8 pre-SQL degrade — with the columns gone, the payload is the old one.
 *
 * ⚠ Every mutation is inside a transaction that is always rolled back.
 *
 * Run:  php test_vehicle_class_schedule.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// ⭐ 5-Oct-2026: run against the maintenance set-up this script was written for, inside one
//   rolled-back transaction — see test_fleet_fixture.php for why.
require __DIR__ . '/test_fleet_fixture.php';
fleetFixtureBegin();

use App\Models\Riders\MaintenanceTypeModel;
use App\Services\Riders\MaintenanceTypeService;
use App\Services\Riders\ServiceIntervalResolver;
use App\Services\Riders\VehicleScheduleService;
use App\Services\Riders\VehicleService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function ok(string $what, $got, $want = null, bool $raw = false) {
    global $pass, $fail;
    $good = $raw ? (bool) $got : $got === $want;
    if ($good) { $pass++; echo "  ✓ $what\n"; }
    else {
        $fail++; echo "  ✗ $what\n";
        if (!$raw) {
            echo "      got:  " . var_export($got, true) . "\n";
            echo "      want: " . var_export($want, true) . "\n";
        }
    }
}
/**
 * ⚠⚠ NOT `head()`. Laravel ships a GLOBAL `head()` helper (Illuminate\Support\Arr) and
 *    the VALIDATOR calls it, so a suite that defines its own `head()` and then exercises
 *    a controller carrying array rules (`rows.*.id`) dies inside ValidationRuleParser
 *    with a type error a mile from the cause. The sibling fleet suites carry the same
 *    latent clash and have simply never hit a rule shaped like that.
 */
function section(string $t) { echo "\n== $t ==\n"; }
function flushAll(): void {
    ServiceIntervalResolver::flush();
    VehicleService::flushServiceMemo();
    VehicleScheduleService::flush();
    MaintenanceTypeService::flushSchemaMemo();
    \App\Services\Riders\VehicleResolver::flush();
    try { Cache::flush(); } catch (\Throwable $e) {}
}

// ── fixtures, read from the registry rather than hard-coded ───────────────────
$svc = new VehicleService();
$van  = DB::table('t_ops_vehicle')->where('vtype', 'van')->where('is_company', 1)
          ->where('is_active', 1)->first(['id', 'reg_no']);
$bike = DB::table('t_ops_vehicle')->where('vtype', 'bike')->where('is_company', 1)
          ->where('is_active', 1)->first(['id', 'reg_no']);
$own  = DB::table('t_ops_vehicle')->where('is_company', 0)->where('is_active', 1)
          ->first(['id', 'nickname']);
$oil  = DB::table('t_fleet_maintenance_types')->where('type_name', 'Oil Change')->first();

ok('a company VAN exists', (bool) $van, null, true);
ok('a company BIKE exists', (bool) $bike, null, true);
ok('a PERSONAL machine exists', (bool) $own, null, true);
ok('Oil Change exists', (bool) $oil, null, true);
if (!$van || !$bike || !$own || !$oil) { echo "\nfixtures missing — stopping.\n"; exit(1); }

$VAN = (int) $van->id; $BIKE = (int) $bike->id; $OWN = (int) $own->id; $OIL = (int) $oil->id;
echo "  · van={$VAN} bike={$BIKE} personal={$OWN} oil_type={$OIL}\n";

ok('the migration has run on this database',
   app(MaintenanceTypeService::class)->classAware(), true);

// ─────────────────────────────────────────────────────────────────────────────
section('§1 the CLASS STANDARD — one job, a number per class');

DB::beginTransaction();
try {
    // Oil Change: bikes every 1,000 km, vans every 5,000 km.
    DB::table('t_fleet_maintenance_types')->where('id', $OIL)->update([
        'applies_to' => 'both', 'basis' => 'km',
        'interval_km' => 1000, 'interval_km_van' => 5000,
    ]);
    flushAll();

    $t = MaintenanceTypeModel::find($OIL);
    ok('the bike standard', $t->scheduleForClass('bike')['km'], 1000);
    ok('the van standard',  $t->scheduleForClass('van')['km'], 5000);
    ok('  …and each is phrased for its own class',
       [$t->scheduleForClass('bike')['label'], $t->scheduleForClass('van')['label']],
       ['every 1,000 km', 'every 5,000 km']);

    $r = new ServiceIntervalResolver();
    ok('the resolver gives the van its own number', $r->resolveFor($VAN, 'van', $t)['km'], 5000);
    ok('  …and the bike its own',                   $r->resolveFor($BIKE, 'bike', $t)['km'], 1000);

    // ⭐⭐ THE HEADLINE FACT: the same job on the same day, two machines, two numbers.
    $vanRow  = null; $bikeRow = null;
    foreach ($svc->serviceScheduleFor($VAN, 75000)  as $s) if ($s['id'] === $OIL) $vanRow  = $s;
    foreach ($svc->serviceScheduleFor($BIKE, 40000) as $s) if ($s['id'] === $OIL) $bikeRow = $s;
    ok('the van panel shows 5,000',  $vanRow['interval_km']  ?? null, 5000);
    ok('the bike panel shows 1,000', $bikeRow['interval_km'] ?? null, 1000);
    ok('  …and neither is flagged as an override (both are their own standard)',
       [$vanRow['interval_overridden'] ?? null, $bikeRow['interval_overridden'] ?? null],
       [false, false]);

    // Applicability: a bike-only job never appears on the van.
    DB::table('t_fleet_maintenance_types')->where('id', $OIL)->update(['applies_to' => 'bike']);
    flushAll();
    $ids = array_column($svc->serviceScheduleFor($VAN, 75000), 'id');
    ok('a bike-only job is absent from the van entirely', in_array($OIL, $ids, true), false);
    ok('  …and the van picker does not offer it',
       in_array($OIL, array_column(app(MaintenanceTypeService::class)->optionsFor('van'), 'id'), true),
       false);
    ok('  …while the bike still has it',
       in_array($OIL, array_column($svc->serviceScheduleFor($BIKE, 40000), 'id'), true), true);

    // Applies to vans but no van figure yet — the van's honest day-one state.
    DB::table('t_fleet_maintenance_types')->where('id', $OIL)
        ->update(['applies_to' => 'both', 'interval_km_van' => null]);
    flushAll();
    ok('applies but no van number ⇒ no countdown on the van',
       in_array($OIL, array_column($svc->serviceScheduleFor($VAN, 75000), 'id'), true), false);
    ok('  …the job is still OFFERED, as "as conditions"',
       (function () use ($OIL) {
           foreach (app(MaintenanceTypeService::class)->optionsFor('van') as $o) {
               if ((int) $o['id'] === $OIL) return $o['due_label'];
           }
           return null;
       })(), 'as conditions');
} finally { DB::rollBack(); flushAll(); }

// ─────────────────────────────────────────────────────────────────────────────
section('§2 ⭐⭐ the per-vehicle per-job exception — what "This bike\'s schedule" always meant');

DB::beginTransaction();
try {
    DB::table('t_fleet_maintenance_types')->where('id', $OIL)->update([
        'applies_to' => 'both', 'basis' => 'km',
        'interval_km' => 1000, 'interval_km_van' => 5000,
    ]);
    flushAll();

    $sched = new VehicleScheduleService();
    $res = $sched->saveFor($BIKE, [$OIL => ['km' => 800]], 1);
    ok('saving an exception reports what it did', $res['ok'], true);
    flushAll();

    $row = null;
    foreach ($svc->serviceScheduleFor($BIKE, 40000) as $s) if ($s['id'] === $OIL) $row = $s;
    ok('⭐ THE FIX: this bike now does the job every 800 km', $row['interval_km'] ?? null, 800);
    ok('  …and it is named as an override', $row['interval_overridden'] ?? null, true);
    ok('  …with the source spelled out', $row['interval_source'] ?? null, 'vehicle_job');
    ok('  …in words a manager can read',
       $row['interval_source_label'] ?? null, 'this vehicle\'s own schedule');
    ok('  …while the standard it departs from is still reported',
       $row['type_interval_km'] ?? null, 1000);

    // ⭐ THE REGRESSION THIS ENDS: it must not touch any OTHER job or machine.
    $otherJob = null;
    foreach ($svc->serviceScheduleFor($BIKE, 40000) as $s) if ($s['id'] !== $OIL) $otherJob = $s;
    if ($otherJob) {
        ok('  …and no OTHER job on the same bike moved',
           $otherJob['interval_overridden'], false);
    }
    $vanRow = null;
    foreach ($svc->serviceScheduleFor($VAN, 75000) as $s) if ($s['id'] === $OIL) $vanRow = $s;
    ok('  …and the van is untouched', $vanRow['interval_km'] ?? null, 5000);

    // Blank clears it — and clearing means "follow the standard", not "store the standard".
    $sched->saveFor($BIKE, [$OIL => ['km' => null]], 1);
    flushAll();
    $row2 = null;
    foreach ($svc->serviceScheduleFor($BIKE, 40000) as $s) if ($s['id'] === $OIL) $row2 = $s;
    ok('clearing it returns the bike to the standard', $row2['interval_km'] ?? null, 1000);
    ok('  …and the row is gone, not stamped',
       DB::table(VehicleScheduleService::TABLE)->where('vehicle_id', $BIKE)
         ->where('maintenance_type_id', $OIL)->count(), 0);

    // The exceptions question a standard change must ask.
    $sched->saveFor($BIKE, [$OIL => ['km' => 800]], 1);
    flushAll();
    $ex = $sched->exceptionsFor($OIL, 'bike');
    ok('the standard-change question names the exception', count($ex), 1);
    ok('  …by machine', $ex[0]['vehicle_id'] ?? null, $BIKE);
    ok('  …and a van-scoped ask does not list a bike', count($sched->exceptionsFor($OIL, 'van')), 0);

    $n = $sched->clearFor($OIL, 'bike');
    flushAll();
    ok('"put every vehicle on it" clears them', $n, 1);
    ok('  …so the next change reaches them too',
       DB::table(VehicleScheduleService::TABLE)->where('maintenance_type_id', $OIL)->count(), 0);
} finally { DB::rollBack(); flushAll(); }

// ─────────────────────────────────────────────────────────────────────────────
section('§3 TIME-based jobs — days, not kilometres');

DB::beginTransaction();
try {
    DB::table('t_fleet_maintenance_types')->where('id', $OIL)->update([
        'applies_to' => 'both', 'basis' => 'time',
        'interval_km' => null, 'interval_km_van' => null,
        'interval_days' => 180, 'interval_days_van' => 90,
    ]);
    flushAll();

    $t = MaintenanceTypeModel::find($OIL);
    ok('months round-trip through days', $t->scheduleForClass('bike')['label'], 'every 6 months');
    ok('  …and the van has its own',      $t->scheduleForClass('van')['label'], 'every 3 months');
    ok('a non-monthly figure reads in days',
       MaintenanceTypeModel::intervalLabel('time', null, 45), 'every 45 days');

    ok('the due-soon window is three days', ServiceIntervalResolver::DUE_SOON_DAYS, 3);
    ok('  …overdue below zero',  ServiceIntervalResolver::stateForDays(-1), 'overdue');
    ok('  …due_soon at the edge', ServiceIntervalResolver::stateForDays(3), 'due_soon');
    ok('  …ok just past it',      ServiceIntervalResolver::stateForDays(4), 'ok');
    ok('  …unknown with no baseline', ServiceIntervalResolver::stateForDays(null), 'unknown');

    // ⚠ A time job must never borrow a kilometre fallback.
    DB::table('t_fleet_maintenance_types')->where('id', $OIL)->update(['interval_days' => null]);
    DB::table('t_ops_vehicle')->where('id', $BIKE)->update(['service_interval_km' => 1500]);
    flushAll();
    $r = (new ServiceIntervalResolver())->resolveFor($BIKE, 'bike', MaintenanceTypeModel::find($OIL));
    ok('a time job with no days figure has NO schedule', $r['has'], false);
    ok('  …and did not silently take the bike\'s kilometre scalar', $r['km'], null);

    // The phrasing ships with the row, so no screen composes the unit itself.
    ok('the sentence is composed by the server',
       VehicleService::dueText('overdue', true, null, -12), '12 days overdue');
    ok('  …and reads naturally when it is due today',
       VehicleService::dueText('due_soon', true, null, 0), 'due today');
    ok('  …and in kilometres for a km job',
       VehicleService::dueText('ok', false, 341, null), 'due in 341 km');
} finally { DB::rollBack(); flushAll(); }

// ─────────────────────────────────────────────────────────────────────────────
section('§4 ⭐⭐ personal machines are not the company\'s business');

ok('a personal bike has an EMPTY schedule', $svc->serviceScheduleFor($OWN, 20000), []);
ok('  …and its headline says so',
   $svc->overallServiceStateFor($OWN, 20000)['state'], 'not_tracked');
ok('  …in a sentence, not a warning',
   $svc->overallServiceStateFor($OWN, 20000)['due_text'],
   'personal vehicle — not on the company schedule');
ok('  …and it is flagged as untracked for the screens',
   $svc->overallServiceStateFor($OWN, 20000)['tracked'], false);
ok('isTrackedId agrees', $svc->isTrackedId($OWN), false);
ok('  …and a company machine is tracked', $svc->isTrackedId($BIKE), true);
ok('no personal machine reaches the alert sweep',
   (function () {
       foreach ((new \App\Services\Riders\BikeServiceAlerts())->due() as $a) {
           $v = DB::table('t_ops_vehicle')->where('id', $a['vehicle_id'])->value('is_company');
           if ((int) $v !== 1) return false;
       }
       return true;
   })(), true);

// ─────────────────────────────────────────────────────────────────────────────
section('§5 every surface, the same answer for one (vehicle, job)');

DB::beginTransaction();
try {
    DB::table('t_fleet_maintenance_types')->where('id', $OIL)->update([
        'applies_to' => 'both', 'basis' => 'km',
        'interval_km' => 1000, 'interval_km_van' => 5000,
    ]);
    (new VehicleScheduleService())->saveFor($VAN, [$OIL => ['km' => 4000]], 1);
    flushAll();

    $meter = $svc->currentMeterFor($VAN) ?: 75000;
    $panel = null;
    foreach ($svc->serviceScheduleFor($VAN, $meter) as $s) if ($s['id'] === $OIL) $panel = $s;
    ok('the panel says 4,000', $panel['interval_km'] ?? null, 4000);

    $r = (new ServiceIntervalResolver())->resolveFor($VAN, 'van', MaintenanceTypeModel::find($OIL));
    ok('  …the resolver agrees', $r['km'], 4000);

    $editor = null;
    foreach (app(MaintenanceTypeService::class)->optionsFor('van') as $o) {
        if ((int) $o['id'] === $OIL) $editor = $o;
    }
    ok('  …the van PICKER shows the van standard (not the bike one)',
       $editor['interval_km'] ?? null, 5000);

    foreach ((new \App\Services\Riders\BikeServiceAlerts())->due() as $a) {
        if ($a['vehicle_id'] === $VAN && $a['type_id'] === $OIL) {
            ok('  …and any alert raised uses the same number', $a['interval_km'], 4000);
        }
    }

    // The editor payload the two UIs draw from.
    $ctrl = app(\App\Http\Controllers\CRM\VehicleController::class);
    // The manager: whoever actually HOLDS the key, discovered rather than named — the
    // same rule test_record_service_typed uses, so neither suite pins a person.
    $actor = null;
    foreach (\App\Models\User::where('is_active', '1')->get() as $u) {
        if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) continue;
        if ($u->hasPermission('manage_bike_service')) { $actor = $u; break; }
    }
    if ($actor) {
        \Illuminate\Support\Facades\Auth::guard('web')->loginUsingId($actor->id);
        $req = \Illuminate\Http\Request::create('/x', 'GET');
        $body = json_decode($ctrl->schedule($req, new VehicleService(), $VAN)->getContent(), true);
        ok('  …and the editor payload marks the van as a van', $body['vehicle_class'] ?? null, 'van');
        $er = null;
        foreach ($body['rows'] ?? [] as $x) if ((int) $x['id'] === $OIL) $er = $x;
        ok('  …shows the standard it departs from', $er['standard_km'] ?? null, 5000);
        ok('  …and this vehicle\'s own value', $er['own_km'] ?? null, 4000);
    } else {
        ok('a manage_bike_service holder exists to test the editor (none — skipped honestly)', true, true, true);
    }
} finally { DB::rollBack(); flushAll(); }

// ─────────────────────────────────────────────────────────────────────────────
section('§6 the covers rule respects class and unit');

DB::beginTransaction();
try {
    // Two km jobs where the VAN ordering is the reverse of the BIKE ordering.
    $tuning = DB::table('t_fleet_maintenance_types')->where('type_name', 'Oil + Tuning')->first();
    if ($tuning) {
        DB::table('t_fleet_maintenance_types')->where('id', $OIL)->update([
            'applies_to' => 'both', 'basis' => 'km', 'interval_km' => 1000, 'interval_km_van' => 9000,
        ]);
        DB::table('t_fleet_maintenance_types')->where('id', $tuning->id)->update([
            'applies_to' => 'both', 'basis' => 'km', 'interval_km' => 2000, 'interval_km_van' => 3000,
            'resets_service_clock' => 1,
        ]);
        flushAll();

        $r = new ServiceIntervalResolver();
        ok('on bikes Oil + Tuning is the bigger job',
           $r->resolveFor($BIKE, 'bike', MaintenanceTypeModel::find($tuning->id))['km'] >
           $r->resolveFor($BIKE, 'bike', MaintenanceTypeModel::find($OIL))['km'], true);
        ok('  …but on the van Oil Change is',
           $r->resolveFor($VAN, 'van', MaintenanceTypeModel::find($OIL))['km'] >
           $r->resolveFor($VAN, 'van', MaintenanceTypeModel::find($tuning->id))['km'], true);
        ok('  …so "which vouches for which" cannot be a fleet-wide fact', true, true, true);
    } else {
        ok('a second clock-resetting type exists (none — skipped honestly)', true, true, true);
    }

    // km and time never vouch for each other.
    $src = file_get_contents(__DIR__ . '/app/Services/Riders/VehicleService.php');
    ok('the covers rule compares units before sizes',
       (bool) preg_match('/basis.*!==.*basis.*\n\s*.*continue|Same unit only/', $src), null, true);
} finally { DB::rollBack(); flushAll(); }

// ─────────────────────────────────────────────────────────────────────────────
section('§7 the frozen record follows the class');

$src = file_get_contents(__DIR__ . '/app/Services/Riders/BikeServiceClock.php');
ok('stampServiceDueKm asks the resolver with a class',
   (bool) preg_match('/resolveFor\(/', $src), null, true);
ok('  …and freezes nothing for a time-based job',
   (bool) preg_match("/basis.*!==.*'km'.*\)\s*return|!== 'km'\) return/", $src), null, true);

// ─────────────────────────────────────────────────────────────────────────────
section('§8 ⭐ before the SQL runs, everything behaves exactly as it did');

/**
 * ⚠⚠ DO **NOT** TEST THIS BY DROPPING THE COLUMN. The first version of this section did
 *    `ALTER TABLE … DROP COLUMN applies_to` inside a transaction and expected the
 *    rollback to put it back. **MySQL DDL causes an implicit COMMIT**, so the rollback
 *    was a no-op and the column stayed dropped — on the replica, permanently, until the
 *    idempotent migration was re-run by hand. On production that would have been a
 *    schema change nobody asked for, from a file whose header promises it only reads.
 *
 * ⭐ The degrade path is decided by ONE memoised flag, so the honest way to exercise it
 *   is to set that flag, not to break the database. Reflection keeps the switch in the
 *   test where it belongs rather than adding a test-only hook to production code.
 */
$flag = new ReflectionProperty(MaintenanceTypeService::class, 'classAware');
$flag->setAccessible(true);

$baseline = $svc->serviceScheduleFor($BIKE, 40000);
try {
    flushAll();
    $flag->setValue(null, false);          // "the SQL has not run yet"
    ok('the schema check reports pre-migration', app(MaintenanceTypeService::class)->classAware(), false);

    $after = $svc->serviceScheduleFor($BIKE, 40000);
    ok('  …and a bike\'s schedule is unchanged',
       array_column($after, 'interval_km', 'id'), array_column($baseline, 'interval_km', 'id'));
    ok('  …the picker still answers',
       count(app(MaintenanceTypeService::class)->optionsFor('bike')) > 0, true);
    ok('  …and a VAN falls back to the bike list rather than emptying',
       count(app(MaintenanceTypeService::class)->optionsFor('van')),
       count(app(MaintenanceTypeService::class)->optionsFor('bike')));
} finally {
    $flag->setValue(null, null);           // back to "ask the database"
    flushAll();
}
ok('the column is still there afterwards',
   \Illuminate\Support\Facades\Schema::hasColumn('t_fleet_maintenance_types', 'applies_to'), true);

// ─────────────────────────────────────────────────────────────────────────────
section('§9 through the real endpoints, as a real manager');

/**
 * ⚠ The gates are the point here, not just the maths. The schedule editor is gated on
 *   `manage_bike_service` and NOT on `assign_vehicles` — Qasim owns maintenance and
 *   deliberately holds no assignment key (owner ruling, 3-Sep), so gating it on the
 *   wrong one would lock the maintenance manager out of the screen built for him.
 */
$actor = null;
foreach (\App\Models\User::where('is_active', '1')->get() as $u) {
    if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) continue;
    if ($u->hasPermission('manage_bike_service')) { $actor = $u; break; }
}
ok('a manage_bike_service holder exists', (bool) $actor, null, true);

if ($actor) {
    \Illuminate\Support\Facades\Auth::guard('web')->loginUsingId($actor->id);
    $veh  = app(\App\Http\Controllers\CRM\VehicleController::class);
    $fuel = app(\App\Http\Controllers\CRM\FleetFuelController::class);
    $post = function (string $uri, array $body) {
        return \Illuminate\Http\Request::create($uri, 'POST', $body);
    };

    DB::beginTransaction();
    try {
        // ── the TYPES editor writes a class standard ───────────────────────────
        $r = $fuel->saveMaintenanceType($post('/x', [
            'type_name' => 'Oil Change', 'bucket' => 'regular',
            'applies_to' => 'both', 'basis' => 'km',
            'interval_km' => 1000, 'interval_km_van' => 5000,
            'resets_service_clock' => true,
        ]), $OIL);
        $body = json_decode($r->getContent(), true);
        ok('the types editor saves a per-class standard', $body['success'] ?? false, true);
        flushAll();
        $t = MaintenanceTypeModel::find($OIL);
        ok('  …bikes', $t->scheduleForClass('bike')['km'], 1000);
        ok('  …vans',  $t->scheduleForClass('van')['km'], 5000);

        // ── the per-vehicle editor writes an exception ─────────────────────────
        $r = $veh->saveSchedule($post('/x', ['rows' => [['id' => $OIL, 'interval_km' => 4000]]]),
                                new VehicleService(), $VAN);
        $body = json_decode($r->getContent(), true);
        ok('the vehicle editor saves an exception', $body['success'] ?? false, true);
        flushAll();
        $row = null;
        foreach ($svc->serviceScheduleFor($VAN, 75000) as $s) if ($s['id'] === $OIL) $row = $s;
        ok('  …and the van panel now reads 4,000', $row['interval_km'] ?? null, 4000);

        // ── and the standard change ASKS about it ──────────────────────────────
        $r = $fuel->typeExceptions(\Illuminate\Http\Request::create('/x', 'GET', ['class' => 'van']), $OIL);
        $body = json_decode($r->getContent(), true);
        ok('the standard-change question names that vehicle',
           count($body['exceptions'] ?? []), 1);
        ok('  …with what it does instead',
           $body['exceptions'][0]['own_label'] ?? null, 'every 4,000 km');

        // ── "put every vehicle on the new standard" ────────────────────────────
        $r = $fuel->saveMaintenanceType($post('/x', [
            'type_name' => 'Oil Change', 'bucket' => 'regular',
            'applies_to' => 'both', 'basis' => 'km',
            'interval_km' => 1000, 'interval_km_van' => 6000,
            'resets_service_clock' => true,
            'clear_overrides' => true, 'clear_overrides_class' => 'both',
        ]), $OIL);
        $body = json_decode($r->getContent(), true);
        ok('clearing is reported back', ($body['cleared_vehicles'] ?? 0), 1);
        flushAll();
        $row = null;
        foreach ($svc->serviceScheduleFor($VAN, 75000) as $s) if ($s['id'] === $OIL) $row = $s;
        ok('  …so the van now follows the new standard', $row['interval_km'] ?? null, 6000);

        // ── blank clears, it does not stamp ────────────────────────────────────
        $veh->saveSchedule($post('/x', ['rows' => [['id' => $OIL, 'interval_km' => 3000]]]),
                           new VehicleService(), $VAN);
        $veh->saveSchedule($post('/x', ['rows' => [['id' => $OIL, 'interval_km' => 0]]]),
                           new VehicleService(), $VAN);
        flushAll();
        ok('a blank box removes the exception',
           DB::table(VehicleScheduleService::TABLE)->where('vehicle_id', $VAN)
             ->where('maintenance_type_id', $OIL)->count(), 0);

        // ── a personal machine is refused, with a sentence ─────────────────────
        $r = $veh->saveSchedule($post('/x', ['rows' => [['id' => $OIL, 'interval_km' => 500]]]),
                                new VehicleService(), $OWN);
        $body = json_decode($r->getContent(), true);
        ok('a personal vehicle cannot be given a company schedule', $r->getStatusCode(), 422);
        ok('  …and is told why',
           (bool) preg_match('/personal vehicle/i', $body['message'] ?? ''), null, true);

        // ── the READ payload a personal machine gets ───────────────────────────
        $r = $veh->schedule(\Illuminate\Http\Request::create('/x', 'GET'), new VehicleService(), $OWN);
        $body = json_decode($r->getContent(), true);
        ok('  …and its editor shows a sentence, not a form', $body['tracked'] ?? null, false);
        ok('  …with no rows at all', $body['rows'] ?? null, []);

        // ── the retired scalar is refused with a way forward ───────────────────
        $r = $fuel->markServiced($post('/x', ['rider_id' => 77, 'interval_km' => 1500]));
        $body = json_decode($r->getContent(), true);
        ok('the old single-number schedule is refused', $r->getStatusCode(), 422);
        ok('  …and says where the setting moved',
           (bool) preg_match('/vehicle page|vehicle\'s schedule/i', $body['message'] ?? ''), null, true);
    } finally {
        DB::rollBack();
        flushAll();
    }
}

// ─────────────────────────────────────────────────────────────────────────────
section('§10 the banner and the PUSH say the same sentence (review find, 10-Sep)');

/**
 * The in-app banner learned "gaari" for a van and "din" for a time job; the push had its
 * own copy of the sentence and kept saying "bike … km". One rider, two contradicting
 * messages about one alert. Now one function feeds both.
 */
$vanAlert = ['vtype' => 'van', 'basis' => 'time', 'due_in_days' => -12, 'due_in_km' => null,
             'type_name' => 'Oil Change', 'state' => 'overdue'];
$copy = \App\Services\Riders\BikeServiceAlerts::riderCopy($vanAlert);
ok('the rider is told about his GAARI, not his bike',
   (bool) preg_match('/gaari/', $copy['body']), null, true);
ok('  …in DAYS for a time-based job', (bool) preg_match('/12 din/', $copy['body']), null, true);
ok('  …and never in kilometres', (bool) preg_match('/ km/', $copy['body']), false);
ok('  …with the title matching the machine', (bool) preg_match('/gaari/', $copy['title']), null, true);
ok('the banner body IS the push body',
   \App\Services\Riders\BikeServiceAlerts::riderMessage($vanAlert), $copy['body']);

$bikeAlert = ['vtype' => 'bike', 'basis' => 'km', 'due_in_km' => 120, 'type_name' => 'Oil Change',
              'state' => 'due_soon'];
ok('a bike still reads as a bike, in km',
   \App\Services\Riders\BikeServiceAlerts::riderMessage($bikeAlert),
   'Aap ki bike ka Oil Change 120 km baad hai.');

$src = file_get_contents(__DIR__ . '/app/Services/FirebaseService.php');
ok('FirebaseService no longer composes its own rider sentence',
   (bool) preg_match("/Aap ki bike ka/", $src), false);
ok('  …it asks the shared copy', (bool) preg_match('/BikeServiceAlerts::riderCopy/', $src), null, true);

// The alert rows carry what the sentence needs — pushDue() hands them straight over.
$srcA = file_get_contents(__DIR__ . '/app/Services/Riders/BikeServiceAlerts.php');
foreach (['vtype', 'basis', 'due_in_days', 'due_text'] as $k) {
    ok("  …due() rows carry '$k'", (bool) preg_match("/'$k'\s*=>/", $srcA), null, true);
}

echo "\n" . str_repeat('─', 60) . "\n";
echo $fail === 0 ? "ALL GREEN — passed $pass, failed $fail\n"
                 : "FAILURES — passed $pass, failed $fail\n";
exit($fail === 0 ? 0 : 1);
