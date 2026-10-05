<?php
/**
 * THE REGRESSION GATE for the machine-keyed CLAIM CHAINS (Aug-20-2026).
 *
 * The Bikes screen chains two things per claim: "km since last fill" and "km since
 * last service". Both were keyed to the RIDER while everything around them had moved
 * to the MACHINE, so both were wrong the moment a bike changed hands. This proves:
 *
 *   1. the fuel chip now measures the BIKE's previous tank, whoever filled it —
 *      including the handover rows that used to inflate (600 for a 142 km tank) and
 *      the ones that used to print nothing at all;
 *   2. the service chip can no longer contradict the frozen `service_due_km` printed
 *      on the same row, and non-clock-resetting jobs are no longer judged at all;
 *   3. a machine that has genuinely never passed 1,000 km can hold a service clock,
 *      and NO other machine's answer moves by a kilometre;
 *   4. money never moves: the month's headline fuel/maintenance totals are identical
 *      with the engine on and off;
 *   5. `MACHINE_ATTRIBUTION = 'N'` still restores the old rider-keyed answers exactly.
 *
 * ⚠ It writes a config row and MUST put it back — undo is registered before the first
 *   mutation and unwound in reverse (the standing harness rule). Nothing else is
 *   written: every other assertion is a read.
 *
 * Run:  php test_claim_chain.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// ⭐ 5-Oct-2026: run against the maintenance set-up this script was written for, inside one
//   rolled-back transaction — see test_fleet_fixture.php for why.
require __DIR__ . '/test_fleet_fixture.php';
fleetFixtureBegin();
// The frozen figures this script checks were stamped under the AUGUST schedules (Oil Change
// every 1,200 km, Oil + Tuning every 2,500) — put those back for the run (rolled back with it).
\Illuminate\Support\Facades\DB::table('t_fleet_maintenance_types')->where('id', 1)->update(['interval_km' => 1200]);
\Illuminate\Support\Facades\DB::table('t_fleet_maintenance_types')->where('id', 2)->update(['interval_km' => 2500]);
\App\Services\Riders\VehicleService::bumpServiceConfig();
\App\Services\Riders\VehicleService::flushServiceMemo();

use App\Services\Riders\FleetFuelService;
use App\Services\Riders\VehicleService;
use App\Services\Riders\VehicleResolver;
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

// --- the lever, with its undo registered FIRST ------------------------------
$existing = DB::table('t_fin_config')->where('config_key', 'MACHINE_ATTRIBUTION')->first();
register_shutdown_function(function () use ($existing) {
    if ($existing) {
        DB::table('t_fin_config')->where('config_key', 'MACHINE_ATTRIBUTION')
            ->update(['config_value' => $existing->config_value]);
    } else {
        DB::table('t_fin_config')->where('config_key', 'MACHINE_ATTRIBUTION')->delete();
    }
    Cache::flush();
});

function setLever(string $v): void {
    $row = DB::table('t_fin_config')->where('config_key', 'MACHINE_ATTRIBUTION')->first();
    if ($row) DB::table('t_fin_config')->where('config_key', 'MACHINE_ATTRIBUTION')->update(['config_value' => $v]);
    else      DB::table('t_fin_config')->insert(['config_key' => 'MACHINE_ATTRIBUTION', 'config_value' => $v]);
    Cache::flush();
    VehicleService::flushServiceMemo();
    VehicleResolver::flush();
}

/** Every claim chip for one rider-month, flattened to [claim_id => row]. */
function chips(int $uid, string $month): array {
    $out = [];
    foreach ((new FleetFuelService())->riderMonth($uid, $month)['days'] ?? [] as $d) {
        foreach ($d['claims'] ?? [] as $c) $out[$c['id']] = $c;
    }
    return $out;
}

const MONTH   = '2026-08';
const WASEEM  = 73;
const DANISH  = 84;
const FAROOQ  = 74;
const KANAN   = 76;
const ARSLAN  = 77;
const RAJAB   = 95;
const TAIMUR  = 68;
const DCR799  = 3;
const EDN198  = 8;

setLever('Y');

// ═══════════════════════════════════════════════════════════════════════════
head('1. THE REPORTED BUG — the fuel chip measures the BIKE, not the man');

$w = chips(WASEEM, MONTH);
$d = chips(DANISH, MONTH);
$f = chips(FAROOQ, MONTH);
$r = chips(RAJAB,  MONTH);

// #2661: Waseem filled DCR-799 at 25,621 on 10 Aug. His OWN previous fill was
// 25,021 on the 6th (600 km) but DANISH had the bike in between and filled it at
// 25,479 on the 9th. The tank covered 142 km, and that is the approver's number.
ok('#2661 handover row reads the bike, not the rider', $w[2661]['km_since_fill'] ?? null, 142);
ok('#2661 names the man whose tank it follows',        $w[2661]['km_since_fill_by'] ?? null, 'Danish Ali');
ok('#2661 carries the anchor date',                    $w[2661]['km_since_fill_on'] ?? null, '2026-08-09');
ok('#2661 carries the anchor reading',                 $w[2661]['km_since_fill_from'] ?? null, 25479);

// #2642: Danish's FIRST fill on a bike he had just been given. Rider-keyed it had no
// anchor at all and printed nothing; the bike's own previous tank is Waseem's 25,021.
ok('#2642 first fill after a handover is no longer blank', $d[2642]['km_since_fill'] ?? null, 229);
ok('#2642 names Waseem',                                   $d[2642]['km_since_fill_by'] ?? null, 'Waseem');

// #2650: Danish's SECOND fill — his own previous tank, so nothing to attribute.
ok('#2650 same-rider row keeps its number', $d[2650]['km_since_fill'] ?? null, 229);
ok('#2650 says nothing about who filled it', $d[2650]['km_since_fill_by'] ?? null, null);

// The van: two men, one machine, one day. MachineAttribution's own docblock cites
// these 68 km; until now the chip could not see them.
ok('#2765 van pair reads 68 km across two riders', $r[2765]['km_since_fill'] ?? null, 68);
ok('#2765 names the other driver', ($r[2765]['km_since_fill_by'] ?? null) !== null, true);

// R1: a fill with NO reading still breaks the chain — for the whole machine now.
// #2653 is a Rs 300 cash fill on EDN-198 with no meter, so #2759 after it has no
// honest anchor and must stay silent rather than span an unmeasured tank.
ok('#2759 stays blank behind a meterless fill', $f[2759]['km_since_fill'] ?? null, null);
ok('#2759 is not flagged as odd either',        $f[2759]['km_since_fill_odd'] ?? null, false);

// ═══════════════════════════════════════════════════════════════════════════
head('2. NOTHING ELSE MOVED — every non-handover fill is unchanged');

$expected = [2763 => 57, 2740 => 156, 2748 => 122, 2686 => 149,
             2598 => 84, 2586 => 115, 2573 => 125, 2554 => 109, 2543 => 174];
foreach ($expected as $id => $km) {
    ok("#$id untouched at {$km} km", $w[$id]['km_since_fill'] ?? null, $km);
    ok("#$id has nobody to attribute", $w[$id]['km_since_fill_by'] ?? null, null);
}

// ═══════════════════════════════════════════════════════════════════════════
head('3. THE SERVICE CHIP CAN NO LONGER CONTRADICT THE FROZEN RECORD');

$k = chips(KANAN,  MONTH);
$a = chips(ARSLAN, MONTH);

/** early_by/late_by must be exactly the frozen figure, whatever the interval. */
function agrees(array $c): bool {
    $frozen = $c['service_due_km_at_approval'] ?? null;
    if ($frozen === null) return true;                       // nothing to agree with
    if ($frozen > 25)  return ($c['early_by'] ?? $c['service_early_by'] ?? null) === $frozen;
    if ($frozen < -25) return ($c['service_late_by'] ?? null) === -$frozen;
    return ($c['service_early_by'] ?? null) === null && ($c['service_late_by'] ?? null) === null;
}

// The row from the owner's screenshot: "⏱ serviced 297 km early" beside
// "🔴 done 43 km overdue". The anchor was a CHAIN SET (resets_service_clock = 0).
ok('#2738 no longer claims "early"',        $w[2738]['service_early_by'] ?? null, null);
ok('#2738 says late by exactly the frozen 43', $w[2738]['service_late_by'] ?? null, 43);
ok('#2738 since-last-service is 1,243 not 903', $w[2738]['km_since_service'] ?? null, 1243);
ok('#2738 agrees with its own frozen figure',   agrees($w[2738]), true);

// The worst disagreement in the data: 787 early vs 564 overdue, 1,351 km apart.
ok('#2756 no longer claims "early"',          $k[2756]['service_early_by'] ?? null, null);
ok('#2756 says overdue by exactly the frozen 564', $k[2756]['service_late_by'] ?? null, 564);
ok('#2756 agrees with its own frozen figure',      agrees($k[2756]), true);

// A Brake Shoe is regular work on a 10,000 km cycle that does NOT reset the clock.
// It was being judged against the 1,200 km oil schedule and called 151 km overdue.
ok('#2676 Brake Shoe is not judged at all (late)',  $k[2676]['service_late_by'] ?? null, null);
ok('#2676 Brake Shoe is not judged at all (early)', $k[2676]['service_early_by'] ?? null, null);
ok('#2676 Brake Shoe has no since-figure',          $k[2676]['km_since_service'] ?? null, null);

// An Oil + Tuning measured on ITS own 2,500 km schedule, matching what was frozen.
ok('#2594 Oil + Tuning measured on its own schedule', $a[2594]['service_interval'] ?? null, 2500);
ok('#2594 early_by equals the frozen 2,125',          $a[2594]['service_early_by'] ?? null, 2125);
ok('#2594 agrees with its own frozen figure',         agrees($a[2594]), true);

// …and the sweep: EVERY approved regular row in both months agrees with its stamp.
$disagree = [];
foreach ([WASEEM, DANISH, FAROOQ, KANAN, ARSLAN, RAJAB, TAIMUR] as $uid) {
    foreach (['2026-07', '2026-08'] as $mo) {
        foreach (chips($uid, $mo) as $id => $c) {
            if (($c['kind'] ?? null) !== 'maintenance') continue;
            if (!agrees($c)) $disagree[] = $id;
        }
    }
}
ok('no maintenance row disagrees with its frozen figure', $disagree, []);

// ═══════════════════════════════════════════════════════════════════════════
head('4. THE 1,000 km FLOOR IS NOW THE MACHINE\'S, NOT AN ABSOLUTE');

$veh = new VehicleService();
// ⚠ 5-Oct-2026: these were literal 16-Aug figures (EDN-198 at 710 km, every other odometer to
//   the kilometre) and went red the day the bikes were next ridden. The RULE is what must hold:
//   a machine is "low-mileage" exactly while everything it has read is under the floor, it
//   reports an odometer either way, and its countdown is its own arithmetic.
$edn    = $veh->currentMeterFor(EDN198);
$ednLow = $edn !== null && $edn <= VehicleService::MIN_METER;
ok('EDN-198 reports an odometer (it showed none while under 1,000 km)', $edn !== null && $edn > 0, true);
ok('EDN-198 is "low-mileage" exactly while it is under the 1,000 km floor', $veh->isLowMileageMachine(EDN198), $ednLow);

$sched = collect($veh->serviceScheduleFor(EDN198, $edn))->firstWhere('name', 'Oil Change');
ok('EDN-198 Oil Change has a last-done point', ($sched['last_meter'] ?? null) !== null, true);
ok('EDN-198 Oil Change counts down from it',   $sched['due_in_km'] ?? null,
   ($sched['last_meter'] ?? null) !== null ? $sched['interval_km'] - ($edn - $sched['last_meter']) : null);

// ⚠⚠ THE CONTAINMENT PROOF. No 5-figure machine may be reclassified, and none may read
//    BELOW what it read on 16-Aug (an odometer only climbs) — which is what makes relaxing a
//    safety floor safe at all. (#9 is left out: its 16-Aug figure was the van's, a bug fixed
//    on 22-Aug, and its meter has since been replaced.)
foreach ([1 => 48920, 2 => 35596, 3 => 26254, 4 => 73410, 5 => 33732,
          6 => 13465, 7 => 45554] as $vid => $meter) {
    ok("vehicle #$vid is NOT reclassified as low-mileage", $veh->isLowMileageMachine($vid), false);
    ok("vehicle #$vid still reads at or above its 16-Aug " . number_format($meter), $veh->currentMeterFor($vid) >= $meter, true);
}

// A dropped digit on a 5-figure bike is still refused — the guard's actual job.
ok('800 km on DCR-799 is still a dropped digit', $veh->plausibleServiceMeter(800, DCR799), false);
ok('659 km on EDN-198 is accepted at its own date', $veh->plausibleServiceMeter(659, EDN198, '2026-08-16'), true);
ok('a reading with no machine keeps the strict rule', $veh->plausibleServiceMeter(800, null), false);
ok('zero is never plausible',                    $veh->plausibleServiceMeter(0, EDN198), false);
ok('null is never plausible',                    $veh->plausibleServiceMeter(null, EDN198), false);

// ⚠⚠ THE CLIFF TEST (review, Aug-20). The verdict is anchored to the READING'S OWN
//    DATE — judged only against what the machine had read BY then — so it can never
//    change as the bike ages. The first cut classified whole machines by their MAX
//    reading, which would have thrown the 659 km service away all over again the
//    week EDN-198's odometer passed 1,000.
ok('the verdict is date-anchored, judged against pre-Aug-16 readings only',
   $veh->plausibleServiceMeter(659, EDN198, '2026-08-16'), true);
// A back-dated low claim on EDN-198 plugs onto the FRONT of its chain (min 342)…
ok('a back-dated low reading near a new bike\'s chain is accepted',
   $veh->plausibleServiceMeter(300, EDN198, '2026-08-01'), true);
// …but the same trick on a 5-figure bike is still a dropped digit: nothing in
// DCR-799's chain is anywhere near 300, whatever date the claim wears.
ok('a back-dated low reading on a 5-figure bike is still refused',
   $veh->plausibleServiceMeter(300, DCR799, '2026-07-01'), false);

// ═══════════════════════════════════════════════════════════════════════════
head('5. MONEY NEVER MOVED — totals identical with the engine on and off');

$svc = new FleetFuelService();
setLever('Y'); $on  = (new FleetFuelService())->monthSummary(MONTH);
setLever('N'); $off = (new FleetFuelService())->monthSummary(MONTH);

ok('month fuel total unchanged by the engine',  $on['totals']['fuel_rs'],  $off['totals']['fuel_rs']);
ok('month maint total unchanged by the engine', $on['totals']['maint_rs'], $off['totals']['maint_rs']);

$sumOn = $sumOff = 0.0;
foreach ($on['riders']  as $x) { $sumOn  += $x['fuel_rs'] + $x['maint_rs']; }
foreach ($off['riders'] as $x) { $sumOff += $x['fuel_rs'] + $x['maint_rs']; }
ok('per-rider money identical', round($sumOn, 2), round($sumOff, 2));

// ═══════════════════════════════════════════════════════════════════════════
head('6. THE ROLLBACK LEVER STILL RESTORES THE OLD ANSWERS EXACTLY');

// Still OFF from the block above.
$wOff = chips(WASEEM, MONTH);
$dOff = chips(DANISH, MONTH);
$fOff = chips(FAROOQ, MONTH);

ok('lever off: #2661 back to the rider-keyed 600', $wOff[2661]['km_since_fill'] ?? null, 600);
ok('lever off: #2642 blank again',                 $dOff[2642]['km_since_fill'] ?? null, null);
ok('lever off: no attribution is offered',         $wOff[2661]['km_since_fill_by'] ?? null, null);
ok('lever off: Farooq\'s hint falls back',         $fOff[2759]['km_since_fill'] ?? null, null);

// ⚠ The TYPE rules are NOT part of the lever and must not be: a Chain Set has never
//   been a service, on or off, and the frozen figure is a stored fact either way.
ok('lever off: Brake Shoe is still not judged', $wOff[2738]['service_early_by'] ?? null, null);
ok('lever off: #2738 still agrees with its stamp', agrees($wOff[2738]), true);

setLever('Y');

// ═══════════════════════════════════════════════════════════════════════════
head('7. UNTRACKED RIDERS ARE BYTE-IDENTICAL EITHER WAY');

// Anyone the registry cannot place must not be able to notice this code exists.
$untracked = DB::table('t_req_master')
    ->whereIn('expense_category', ['Petrol', 'Maintenance'])
    ->whereNotIn('status', ['cancelled', 'rejected'])
    ->distinct()->pluck('requester_user_id')
    ->map(fn ($v) => (int) $v)
    ->filter(fn ($uid) => !(new VehicleResolver())->trackedByRegistry($uid))
    ->values()->all();

$diffs = [];
foreach ($untracked as $uid) {
    setLever('Y'); $withEngine = chips($uid, MONTH);
    setLever('N'); $without    = chips($uid, MONTH);
    foreach ($withEngine as $id => $c) {
        foreach (['km_since_fill', 'km_since_fill_odd', 'km_since_service',
                  'service_early_by', 'service_late_by'] as $key) {
            if (($c[$key] ?? null) !== ($without[$id][$key] ?? null)) $diffs[] = "$uid/$id/$key";
        }
    }
}
setLever('Y');
echo "  (" . count($untracked) . " untracked riders compared)\n";
ok('no untracked rider changed in any way', $diffs, []);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n────────────────────────────────────────\n";
echo ($fail === 0 ? "ALL GREEN" : "FAILURES") . " — $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
