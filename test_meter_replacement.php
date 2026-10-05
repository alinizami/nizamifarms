<?php
/**
 * "THIS MACHINE'S METER WAS REPLACED" (5-Oct-2026) — see app/Services/Riders/MeterReplacement.php.
 *
 * Every odometer rule assumed a machine's reading only goes up. Rajab's own bike got a new
 * meter on 2-Oct (9,133 → 1 → 147 …) and from then on its readings were "implausible", its
 * days "unusable", its km frozen at 9,133 and any typed reading "lower than this bike's 9,133".
 * On a COMPANY bike the same event would refuse every service record and stall the workshop.
 *
 * What this proves:
 *   §1 the conversions themselves (PHP and SQL agree, round-trips, the swap day);
 *   §2 a machine with NO replacement is untouched (the safety property);
 *   §3 Rajab's real case, once the replacement is recorded;
 *   §4 a COMPANY bike swapped mid-day: doors open, countdown keeps counting, month adds up;
 *   §5 the writer: validation, correction, removal.
 *
 * ⚠ Asserts RULES and figures derived from the data, never this month's numbers.
 * ⚠ Every write is inside a transaction that is rolled back. Sends nothing.
 * Run:  php test_meter_replacement.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Riders\FuelClaimRules;
use App\Services\Riders\MachineAttribution;
use App\Services\Riders\MeterPairHelper;
use App\Services\Riders\MeterReplacement as MR;
use App\Services\Riders\RiderDayLegs;
use App\Services\Riders\VehicleResolver;
use App\Services\Riders\VehicleService;
use App\Services\Riders\WorkJourneyService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function ok(string $what, bool $good, string $detail = '') {
    global $pass, $fail;
    $good ? $pass++ : $fail++;
    echo ($good ? '  ✓ ' : '  ✗ ') . $what . ($detail !== '' ? "  [$detail]" : '') . "\n";
}
function head(string $t) { echo "\n== $t ==\n"; }
function flushAll(): void {
    VehicleService::flushServiceMemo();
    VehicleResolver::flush();
    RiderDayLegs::flush();
    try { Cache::flush(); } catch (\Throwable $e) {}
}
if (!MR::available()) { echo "t_ops_vehicle_meter_reset is not on this database — run vehicle_meter_reset_oct2026.sql first.\n"; exit(1); }

$T = MR::TABLE;
$svc = new VehicleService();
flushAll();

// ─────────────────────────────────────────────────────────────────────────────
head('§1 the conversions');
DB::beginTransaction();
try {
    DB::table($T)->insert(['vehicle_id' => 999001, 'reset_date' => '2026-03-10', 'old_reading' => 9133, 'new_reading' => 0]);
    DB::table($T)->insert(['vehicle_id' => 999001, 'reset_date' => '2026-06-01', 'old_reading' => 5000, 'new_reading' => 12]);
    MR::flush();
    $V = 999001;
    ok('before any replacement a reading is itself', MR::toContinuous($V, 8000, '2026-03-01') === 8000);
    ok('after the first: new + old meter\'s last', MR::toContinuous($V, 147, '2026-03-12') === 9280);
    ok('after the second: both carried', MR::toContinuous($V, 100, '2026-06-05') === 100 + 9133 + (5000 - 12));
    ok('swap day, a reading off the OLD meter stays', MR::toContinuous($V, 9100, '2026-03-10') === 9100);
    ok('swap day, a reading off the NEW meter is lifted', MR::toContinuous($V, 35, '2026-03-10') === 9168);
    ok('no date = the meter fitted now', MR::toContinuous($V, 100, null) === 100 + 9133 + 4988);
    ok('0 / null are "no reading", untouched', MR::toContinuous($V, 0, '2026-06-05') === 0 && MR::toContinuous($V, null, '2026-06-05') === null);
    foreach ([[8000, '2026-03-01'], [147, '2026-03-12'], [35, '2026-03-10'], [9100, '2026-03-10'], [100, '2026-06-05'], [4990, '2026-06-01'], [20, '2026-06-01']] as [$raw, $d]) {
        $c = MR::toContinuous($V, $raw, $d);
        ok("round trip $raw on $d (by date and by value)", MR::toRaw($V, $c, $d) === $raw && MR::toRawByValue($V, $c) === $raw, "cont $c");
    }
    // SQL must give the same answer as PHP for every case above.
    $sql = MR::sql($V, 'm', 'd');
    $agree = true;
    foreach ([[8000, '2026-03-01'], [147, '2026-03-12'], [35, '2026-03-10'], [9100, '2026-03-10'], [100, '2026-06-05'], [4990, '2026-06-01'], [20, '2026-06-01']] as [$raw, $d]) {
        $got = (int) DB::selectOne("SELECT $sql AS x FROM (SELECT ? AS m, ? AS d) t", [$raw, $d])->x;
        if ($got !== MR::toContinuous($V, $raw, $d)) { $agree = false; echo "      sql $raw@$d → $got\n"; }
    }
    ok('the SQL expression agrees with PHP on every case', $agree);
    $nul = DB::selectOne("SELECT $sql AS x FROM (SELECT NULL AS m, '2026-06-05' AS d) t")->x;
    $zero = DB::selectOne("SELECT $sql AS x FROM (SELECT 0 AS m, '2026-06-05' AS d) t")->x;
    ok('…and passes NULL and 0 through', $nul === null && (int) $zero === 0);
    ok('a machine with no replacement gets the bare column', MR::sql(999002, 'meter_start', 'attendance_date') === 'meter_start');
} finally { DB::rollBack(); MR::flush(); }

// ─────────────────────────────────────────────────────────────────────────────
head('§2 a machine never replaced is untouched');
$anyRows = DB::table($T)->count();
foreach (DB::table('t_ops_vehicle')->where('is_active', 1)->pluck('id') as $vid) {
    $vid = (int) $vid;
    if (MR::has($vid)) continue;
    $same = $svc->currentMeterFor($vid) === $svc->currentMeterContinuous($vid)
        && json_encode($svc->meterWindowFor($vid, date('Y-m-d'))) === json_encode($svc->meterWindowContinuousFor($vid, date('Y-m-d')));
    ok("vehicle $vid: shown figures = continuous figures", $same);
}

// ─────────────────────────────────────────────────────────────────────────────
head('§3 Rajab\'s own bike (the real case) once the replacement is recorded');
$RAJAB = 95; $OWN = (int) DB::table('t_ops_vehicle')->where('reg_no', 'APPLIED-FOR')->value('id');
$rows = DB::table('t_ops_attendance')->where('user_id', $RAJAB)->where('attendance_date', '>=', '2026-09-28')
    ->orderBy('attendance_date')->get(['attendance_date', 'meter_start', 'meter_end']);
// Find the swap from the data itself: the first day whose start is far below the previous close.
$swap = null; $prevEnd = null;
foreach ($rows as $r) {
    if ($prevEnd !== null && $r->meter_start !== null && (int) $r->meter_start < $prevEnd - 1000) { $swap = ['d' => substr($r->attendance_date, 0, 10), 'old' => $prevEnd]; break; }
    if ($r->meter_end) $prevEnd = (int) $r->meter_end;
}
ok('found the day the readings dropped', $swap !== null, $swap ? "{$swap['d']}, old meter ended {$swap['old']}" : '');
if ($swap && !MR::has($OWN)) {
    $latest = DB::table('t_ops_attendance')->where('user_id', $RAJAB)->where('attendance_date', '>=', $swap['d'])
        ->whereNotNull('meter_end')->orderByDesc('attendance_date')->first(['attendance_date', 'meter_start', 'meter_end']);
    $newNow = (int) $latest->meter_end; $lastDay = substr($latest->attendance_date, 0, 10);
    $nextDay = date('Y-m-d', strtotime($lastDay . ' +1 day'));
    $rules = new FuelClaimRules();

    ok('BEFORE: the new meter\'s reading is refused', !$svc->readingPlausibleFor($OWN, $newNow + 20));
    ok('BEFORE: current km is stuck on the old meter', $svc->currentMeterFor($OWN) === $swap['old']);

    DB::beginTransaction();
    try {
        $r = $svc->saveMeterReplacement($OWN, $swap['d'], $swap['old'], 0, 'test', 79);
        ok('the replacement saves', $r['ok'], $r['message']);
        flushAll(); $svc = new VehicleService();

        ok('current km now shows the NEW meter', $svc->currentMeterFor($OWN) === $newNow, (string) $svc->currentMeterFor($OWN));
        ok('…and total distance carries on from the old one', $svc->currentMeterContinuous($OWN) === $newNow + $swap['old']);
        ok('a reading a little further on is plausible', $svc->readingPlausibleFor($OWN, $newNow + 20));
        ok('a dropped/extra digit is still refused', !$svc->readingPlausibleFor($OWN, ($newNow + 20) * 10 + 7000) || !$svc->readingPlausibleFor($OWN, 75000));
        ok('an OLD-meter figure typed now is refused', !$svc->readingPlausibleFor($OWN, $swap['old'] - 100));
        ok('…but is still right for a day BEFORE the swap', $svc->readingPlausibleFor($OWN, $swap['old'] - 100, date('Y-m-d', strtotime($swap['d'] . ' -1 day'))));
        ok('a claim meter on the new scale is accepted', $rules->odometerObjection($RAJAB, $newNow + 20, $nextDay, $OWN) === null);
        $low = $rules->odometerObjection($RAJAB, max(1, $newNow - 200), $nextDay, $OWN);
        ok('a claim meter BELOW the new meter\'s last is refused, quoting the new meter', $low !== null && str_contains($low, number_format($newNow)), substr((string) $low, 0, 70));
        ok('an old-scale claim meter filed after the swap is refused', $rules->odometerObjection($RAJAB, $swap['old'] + 50, $nextDay, $OWN) !== null);

        $month = substr($swap['d'], 0, 7);
        $md = $svc->monthDays($OWN, $month);
        $anoms = array_values(array_filter(array_map(fn ($d) => $d['anomaly'] ?? null, $md['days'])));
        ok('the bike\'s month has no "meter went backwards" day', !$anoms, implode(',', $anoms));
        ok('…and the month adds up to its odometer', $md['reconciles'] === true && (int) $md['month_km'] > 0, 'km ' . json_encode($md['month_km']));
        $shown = null; foreach ($md['days'] as $d) if ($d['date'] === $lastDay) $shown = $d['meter_end'];
        ok('…while each day still SHOWS the figure as typed', $shown === $newNow, json_encode($shown));

        $rm = app(\App\Services\Riders\FleetFuelService::class)->riderMonth($RAJAB, $month);
        $bad = array_values(array_filter($rm['days'] ?? [], fn ($d) => $d['date'] >= $swap['d'] && ($d['detail'] ?? '') === 'unusable'));
        ok('the rider page no longer marks his days "unusable"', !$bad, count($bad) . ' unusable');

        $legs = (new RiderDayLegs())->forDay($RAJAB, $lastDay);
        ok('his day resolves to his own bike again', count($legs) === 1 && (int) $legs[0]['vehicle_id'] === $OWN);

        $base = (new WorkJourneyService())->continuityBaseline($RAJAB, $nextDay, $newNow + 3);
        ok('next morning\'s "last night\'s meter" is the new meter\'s close', $base === null || (int) $base['value'] === $newNow, json_encode($base['value'] ?? null));
    } finally { DB::rollBack(); flushAll(); $svc = new VehicleService(); }
    ok('rolled back: current km is the old figure again', $svc->currentMeterFor($OWN) === $swap['old']);
} else {
    echo "  (skipped — the replacement is already on record, or the drop was not found)\n";
}

// ─────────────────────────────────────────────────────────────────────────────
head('§4 a COMPANY bike whose meter is swapped MID-DAY');
// Take a real company bike and its keeper's latest full day, and rewrite that day (inside the
// transaction) as: in on the old meter, out on a NEW meter — then the next morning on it.
$EGL = (int) DB::table('t_ops_vehicle')->where('reg_no', 'EGL-682')->value('id');
$keeper = (int) DB::table('t_ops_vehicle_assignment')->where('vehicle_id', $EGL)->whereNull('released_on')->value('user_id');
$day = DB::table('t_ops_attendance')->where('user_id', $keeper)->whereNotNull('meter_start')->whereNotNull('meter_end')
    ->where('meter_end', '>', 0)->orderByDesc('attendance_date')->first();
ok('found a company bike day to work with', $EGL > 0 && $keeper > 0 && $day !== null);
if ($EGL && $keeper && $day && !MR::has($EGL)) {
    $D = substr($day->attendance_date, 0, 10);
    $next = date('Y-m-d', strtotime($D . ' +1 day'));
    $in = (int) $day->meter_start; $rode = (int) $day->meter_end - $in;
    $oldLast = $in + 40;                       // the old meter's last figure, mid-morning
    $newOut  = $rode - 40;                     // what the NEW meter (from 0) showed at close
    $before  = $svc->currentMeterFor($EGL);
    flushAll(); $svc = new VehicleService();
    $schedBefore = $svc->serviceScheduleFor($EGL, $before);

    DB::beginTransaction();
    try {
        DB::table('t_ops_attendance')->where('user_id', $keeper)->where('attendance_date', '>', $D)->delete();
        DB::table('t_ops_attendance')->where('id', $day->id)->update(['meter_end' => $newOut, 'meter_home' => $newOut,
            'meter_end_vehicle_id' => null, 'meter_home_vehicle_id' => null]);
        DB::table('t_req_master')->where('vehicle_id', $EGL)->whereRaw('COALESCE(expense_date, DATE(created_at)) >= ?', [$D])->update(['meter_at_fill' => null]);
        flushAll(); $svc = new VehicleService();

        $fresh = DB::table('t_ops_attendance')->where('id', $day->id)->first();
        ok('BEFORE recording: the swap day reads as a nonsense distance', MeterPairHelper::distance($fresh) > 1000, (string) MeterPairHelper::distance($fresh));
        ok('BEFORE recording: a service at the new reading is refused', !$svc->readingPlausibleFor($EGL, $newOut + 5, $next, false));

        $r = $svc->saveMeterReplacement($EGL, $D, $oldLast, 0, 'test', 79);
        ok('the replacement saves', $r['ok'], $r['message']);
        flushAll(); $svc = new VehicleService(); $rules = new FuelClaimRules();

        ok('the swap day\'s distance is the real ride', MeterPairHelper::distance(DB::table('t_ops_attendance')->where('id', $day->id)->first()) === $rode,
           MeterPairHelper::distance(DB::table('t_ops_attendance')->where('id', $day->id)->first()) . " vs $rode");
        $legs = (new RiderDayLegs())->forDay($keeper, $D);
        ok('…and his day leg carries that km on this bike', count($legs) === 1 && (int) $legs[0]['vehicle_id'] === $EGL && (int) round($legs[0]['km']) === $rode,
           json_encode(array_map(fn ($l) => [$l['vehicle_id'], $l['km']], $legs)));

        ok('current km shows the new meter', $svc->currentMeterFor($EGL) === $newOut, (string) $svc->currentMeterFor($EGL));
        ok('SERVICE DOOR: the new meter\'s reading fits the machine', $svc->readingPlausibleFor($EGL, $newOut + 5, $next, false));
        ok('SERVICE DOOR: and passes the date-anchored check', $rules->odometerObjection($keeper, $newOut + 5, $next, $EGL) === null,
           substr((string) $rules->odometerObjection($keeper, $newOut + 5, $next, $EGL), 0, 80));
        ok('SERVICE DOOR: a service dated the swap day at an OLD-meter figure still fits', $rules->odometerObjection($keeper, $oldLast - 10, $D, $EGL) === null,
           substr((string) $rules->odometerObjection($keeper, $oldLast - 10, $D, $EGL), 0, 80));
        ok('SERVICE DOOR: a missing digit is still refused', !$svc->readingPlausibleFor($EGL, 21, null) || $rules->odometerObjection($keeper, 1, $next, $EGL) !== null);

        // The countdown must carry straight on: same total distance ⇒ same km left.
        $schedAfter = $svc->serviceScheduleFor($EGL, $svc->currentMeterFor($EGL));
        $movedKm = ($in + $rode) - $before;      // how much further the bike now is than `$before` (≤ 0 here: later days were removed)
        $okAll = true; $n = 0;
        foreach ($schedBefore as $i => $t) {
            $a = $schedAfter[$i] ?? null;
            if (!$a || $t['due_in_km'] === null) continue;
            $n++;
            if ($a['due_in_km'] !== $t['due_in_km'] - $movedKm) { $okAll = false; echo "      {$t['name']}: before {$t['due_in_km']}, after {$a['due_in_km']}, moved $movedKm\n"; }
            if ($a['last_meter'] !== $t['last_meter']) { $okAll = false; echo "      {$t['name']}: last_meter shown changed\n"; }
        }
        ok("COUNTDOWNS carry on across the swap ($n jobs)", $okAll && $n > 0);
        $due = null; foreach ($schedAfter as $t) if ($t['due_in_km'] !== null) { $due = $t; break; }
        ok('…and "due at" is quoted on the NEW meter', $due !== null && $due['due_at_km'] === $svc->currentMeterFor($EGL) + $due['due_in_km'],
           $due ? "due_at {$due['due_at_km']}" : '');

        // A service recorded on the NEW meter must reset its countdown.
        $tid = null; foreach ($schedAfter as $t) if ($t['due_in_km'] !== null && !empty($t['has_schedule'])) { $tid = $t['id']; $interval = $t['interval_km']; break; }
        if ($tid) {
            DB::table('t_fleet_service_log')->insert(array_merge(
                ['user_id' => $keeper, 'maintenance_type_id' => $tid, 'meter' => $newOut + 5, 'service_date' => $next, 'created_by' => 79, 'created_at' => now()],
                DB::getSchemaBuilder()->hasColumn('t_fleet_service_log', 'vehicle_id') ? ['vehicle_id' => $EGL] : []));
            VehicleService::bumpServiceEvidence($EGL); flushAll(); $svc = new VehicleService();
            $row = null; foreach ($svc->serviceScheduleFor($EGL, $svc->currentMeterFor($EGL)) as $t) if ($t['id'] === $tid) $row = $t;
            ok('a service recorded on the NEW meter resets that job', $row && $row['last_meter'] === $newOut + 5 && $row['due_in_km'] === $interval,
               $row ? "last {$row['last_meter']} due_in {$row['due_in_km']} of $interval" : '');
            ok('…and "current km" follows it', $svc->currentMeterFor($EGL) === $newOut + 5);
        }

        $md = (new MachineAttribution())->forVehicle($EGL, substr($D, 0, 7), true);
        $anoms = array_values(array_filter(array_map(fn ($d) => $d['anomaly'] ?? null, $md['days'] ?? [])));
        ok('the bike\'s month has no "meter went backwards" day', !$anoms, implode(',', $anoms));
        $sd = null; foreach (($md['days'] ?? []) as $d) if ($d['date'] === $D && (int) ($d['user_id'] ?? 0) === $keeper) $sd = $d;
        ok('…the swap day SHOWS in ' . number_format($in) . ' → out ' . number_format($newOut) . ' and counts the real ride',
           $sd && $sd['meter_start'] === $in && $sd['meter_end'] === $newOut && (int) $sd['work_km'] === $rode, json_encode($sd ? [$sd['meter_start'], $sd['meter_end'], $sd['work_km']] : null));
    } finally { DB::rollBack(); flushAll(); $svc = new VehicleService(); (new MachineAttribution())->flush(substr($D, 0, 7)); }
    ok('rolled back: the bike reads as before', $svc->currentMeterFor($EGL) === $before);
}

// ─────────────────────────────────────────────────────────────────────────────
head('§5 the writer');
DB::beginTransaction();
try {
    $v = (int) DB::table('t_ops_vehicle')->where('is_active', 1)->orderBy('id')->value('id');
    ok('refuses a future date', !$svc->saveMeterReplacement($v, date('Y-m-d', strtotime('+2 day')), 5000, 0, null, 79)['ok']);
    ok('refuses a missing old reading', !$svc->saveMeterReplacement($v, '2026-01-05', 0, 0, null, 79)['ok']);
    ok('refuses old = new (not a replacement)', !$svc->saveMeterReplacement($v, '2026-01-05', 500, 500, null, 79)['ok']);
    $a = $svc->saveMeterReplacement($v, '2026-01-05', 5000, 0, 'first', 79);
    $b = $svc->saveMeterReplacement($v, '2026-01-05', 5200, 3, 'corrected', 79);
    ok('saving the same day again CORRECTS it (one row)', $a['ok'] && $b['ok'] && $a['id'] === $b['id']
        && DB::table($T)->where('vehicle_id', $v)->count() === 1 && (int) DB::table($T)->where('id', $a['id'])->value('old_reading') === 5200);
    ok('it is listed for the editor', ($svc->meterReplacementsFor($v)[0]['old_reading'] ?? null) === 5200);
    ok('removing it takes it off the record', $svc->removeMeterReplacement($v, (int) $a['id'])['ok'] && !MR::has($v));
    ok('removing it twice is refused politely', !$svc->removeMeterReplacement($v, (int) $a['id'])['ok']);
} finally { DB::rollBack(); flushAll(); }

echo "\n" . str_repeat('─', 60) . "\n";
echo ($fail === 0 ? 'ALL GREEN' : 'FAILURES') . " — passed {$pass}, failed {$fail}\n";
exit($fail === 0 ? 0 : 1);
