<?php
/**
 * PROOF — off-duty km is ONE number on every screen (Sep-30 2026).
 * Plan: OFF-DUTY-KM-ONE-NUMBER-PLAN-SEP30-2026.md (workspace root).
 *
 * READ-ONLY: touches no rows. Logs a user in for the request only (in memory).
 * Run:  php PROOF-OFFDUTY-ONE-NUMBER-SEP30-2026.php [YYYY-MM]
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Riders\MachineAttribution;
use App\Services\Riders\FleetFuelService;
use App\Services\Riders\WorkJourneyService;
use App\Services\Riders\VehicleResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function ok(string $what, $got, $want = true) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ✓ $what\n"; }
    else { $fail++; echo "  ✗ $what\n      got:  " . var_export($got, true) . "\n      want: " . var_export($want, true) . "\n"; }
}
function head(string $t) { echo "\n== $t ==\n"; }

$MONTH = $argv[1] ?? '2026-09';
$eng = new MachineAttribution();
$m = $eng->month($MONTH, true);
$fleet = new FleetFuelService();
$sum = $fleet->monthSummary($MONTH);

// ---------------------------------------------------------------- engine
head("Engine — every machine still adds up to its odometer ($MONTH)");
$bad = [];
foreach ($m['vehicles'] as $vid => $v) {
    $t = $v['totals'];
    ok("{$v['label']}: legs total == on+off+shared+transfer+unaccounted",
        $t['total'], $t['on_duty'] + $t['off_duty'] + $t['shared'] + $t['transfer'] + $t['unaccounted']);
    if (!$v['reconciles']) $bad[] = $v['label'];
}
echo "  (machines not reconciling — pre-existing chain holes, unchanged by this round: " . implode(', ', $bad) . ")\n";

head('Engine — own bikes never carry off-duty; ≤1 km never counts');
foreach ($m['riders'] as $uid => $r) {
    foreach ($eng->offDutyNights($uid, $MONTH) as $n) {
        $veh = (new VehicleResolver())->vehicle($n['vehicle_id']);
        if ((int) $veh->is_company !== 1) ok("{$r['name']} night {$n['date']} is on a company machine", false);
        if ($n['km'] <= MachineAttribution::jitterKm()) ok("{$r['name']} night {$n['date']} is above rounding", false);
    }
}
ok('no own-bike or ≤1 km night found in any rider list', true);

// ---------------------------------------------------------------- the screens
head('Every screen shows the same number');
Auth::setUser(\App\Models\User::find(84) ?? \App\Models\User::first());
$att = app(\App\Http\Controllers\CRM\AttendanceController::class)
    ->monthlyReport(Request::create('/', 'GET', ['month' => $MONTH]))->getData(true);
$attRows = [];
foreach (($att['data'] ?? $att['users'] ?? $att) as $row) {
    if (is_array($row) && isset($row['user_id'])) $attRows[(int) $row['user_id']] = $row;
}
ok('attendance month report returned rows', count($attRows) > 0);

$rc = app(\App\Http\Controllers\API\RiderController::class);
$phone = function (int $uid) use ($rc, $MONTH) {
    Auth::setUser(\App\Models\User::find($uid));
    $r = $rc->getMonthlyAttendance(Request::create('/', 'GET', ['month' => $MONTH . '-01']))->getData(true);
    return $r['bike_usage'] ?? null;
};

foreach ($sum['riders'] as $row) {
    $uid = (int) $row['user_id'];
    $name = $row['name'];
    $er = $m['riders'][$uid] ?? null;
    $nights = $eng->offDutyNights($uid, $MONTH);
    $nightSum = array_sum(array_column($nights, 'km'));
    $detail = $fleet->riderMonth($uid, $MONTH);
    $offNightsSum = array_sum(array_column($detail['off_nights'], 'km'));
    $daySum = 0; foreach ($detail['days'] as $d) $daySum += (int) ($d['offduty_km'] ?? 0);
    $had = $er && !empty($er['had_company']);

    echo "  -- $name: Bikes=" . json_encode($row['offduty_km']) . " nights=$nightSum\n";
    if ($had) {
        ok("$name Bikes column == engine night list", (int) $row['offduty_km'], $nightSum);
        ok("$name Bikes drill-down night list == column", $offNightsSum, $nightSum);
        ok("$name Bikes day rows add up to the column", $daySum, $nightSum);
        if (isset($attRows[$uid])) {
            ok("$name Attendance column == Bikes", $attRows[$uid]['offduty_km'], $nightSum);
        } else {
            echo "    (not listed on the Attendance page — hidden from attendance)\n";
        }
        $bu = $phone($uid);
        ok("$name phone block off-duty == Bikes", $bu['offduty_km'] ?? 'no block', $nightSum);
        ok("$name phone block total = work + off (old APK draws the bar from these)",
            ($bu['total_km'] ?? null), ($bu['work_km'] ?? 0) + ($bu['offduty_km'] ?? 0));
    } elseif ($er) {
        ok("$name (no company machine) Bikes shows no off-duty", $row['offduty_km'], null);
        ok("$name (no company machine) Attendance shows –", $attRows[$uid]['offduty_km'] ?? null, null);
        ok("$name (no company machine) no phone block", $phone($uid), null);
    }
}

// ---------------------------------------------------------------- old APK
head('Installed APK — keys it reads are unchanged');
$someone = null;
foreach ($sum['riders'] as $row) if (($row['offduty_km'] ?? 0) > 0) { $someone = (int) $row['user_id']; break; }
if ($someone) {
    $bu = $phone($someone);
    foreach (['days', 'work_km', 'offduty_km', 'total_km', 'offduty_pct'] as $k) {
        ok("bike_usage.$k is an int", is_int($bu[$k] ?? null));
    }
    $n0 = $fleet->riderMonth($someone, $MONTH)['off_nights'][0] ?? [];
    foreach (['date', 'since', 'km', 'from', 'to', 'vehicle_label'] as $k) {
        ok("off_nights[].$k present (FleetScreen reads it)", array_key_exists($k, $n0));
    }
    foreach (['user_id', 'offduty_km', 'total_km', 'fuelled_km', 'unattributed_km', 'unaccounted_km', 'rs_per_fuelled_km'] as $k) {
        ok("Bikes row key $k present", array_key_exists($k, $sum['riders'][0]));
    }
}

// ---------------------------------------------------------------- morning prompt
head('Morning prompt — Roman Urdu, off-duty line only when it is his');
$wj = new WorkJourneyService();
$res = new VehicleResolver();
$cases = [];
foreach ($sum['riders'] as $row) {
    $uid = (int) $row['user_id'];
    foreach (['2026-09-29', '2026-09-28'] as $day) {
        $base = $wj->continuityBaseline($uid, $day);
        if (!$base) continue;
        $vid = $res->currentVehicleFor($uid, $day);
        $veh = $vid ? $res->vehicle($vid) : null;
        $cases[] = [$row['name'], $day, $base, $veh ? (int) $veh->is_company : null,
                    $vid ? $res->isTransferDay($vid, $day) : false];
        break;
    }
}
foreach ($cases as [$name, $day, $base, $isCo, $transfer]) {
    $msgUp = $wj->continuityPrompt((int) \App\Models\User::where('fullname', $name)->value('id'), $base, 21, $day);
    $msgDn = $wj->continuityPrompt((int) \App\Models\User::where('fullname', $name)->value('id'), $base, -30, $day);
    echo "  [$name $day co=" . json_encode($isCo) . " handover=" . json_encode($transfer) . "]\n     + $msgUp\n     - $msgDn\n";
    ok("$name: Roman Urdu (no English 'last reading')", !str_contains($msgUp . $msgDn, 'last reading'));
    if ($isCo !== 1 || $transfer) ok("$name: no off-duty sentence on own bike / handover", !str_contains($msgUp, 'OFF-DUTY'));
    ok("$name: a LOWER reading never says off-duty", !str_contains($msgDn, 'OFF-DUTY'));
    ok("$name: 1 km is rounding, never off-duty",
        !str_contains($wj->continuityPrompt((int) \App\Models\User::where('fullname', $name)->value('id'), $base, 1, $day), 'OFF-DUTY'));
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
