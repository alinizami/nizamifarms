<?php
/**
 * Rajab, 5-Oct-2026 — why his own-bike fuel claim (518 → 628, 110 km) was refused.
 *
 * Rebuilds the day exactly as the production log tells it, INSIDE a transaction that is always
 * rolled back, then asks the same rules the rider app's claim goes through:
 *   10:56  checks in on his own bike (APPLIED-FOR, v9) at 518 — "stamp skipped, implausible"
 *   14:06  takes the van (CAD-2958, v4) at 75,932   · 14:33 returns it at 75,945 → own bike
 *   21:03  checks out at 628 — "stamp skipped, implausible"
 * His own bike's meter was REPLACED on 2-Oct (it read 9,133 on 1-Oct).
 *
 * Run:  php PROOF-RAJAB-OCT05-OWN-BIKE-CLAIM.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Riders\FuelClaimRules;
use App\Services\Riders\MeterReplacement as MR;
use App\Services\Riders\RiderDayLegs;
use App\Services\Riders\VehicleResolver;
use App\Services\Riders\VehicleService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

$U = 95; $OWN = 9; $VAN = 4; $D = '2026-10-05';
$flush = function () {
    VehicleService::flushServiceMemo(); VehicleResolver::flush(); RiderDayLegs::flush();
    try { Cache::flush(); } catch (\Throwable $e) {}
};
$show = function (string $label) use ($U, $D, $OWN) {
    $legs = (new RiderDayLegs())->forDay($U, $D);
    echo "  $label\n";
    echo "    day legs: " . (json_encode(array_map(fn ($l) => [
        'machine' => $l['label'] ?? $l['vehicle_id'], 'company' => !empty($l['is_company']),
        'start' => $l['meter_start'], 'end' => $l['meter_end'], 'km' => $l['km']], $legs)) ?: '[]') . "\n";
    $attId = (int) DB::table('t_ops_attendance')->where('user_id', $U)->where('attendance_date', $D)->value('id');
    $r = (new FuelClaimRules())->checkMeteredPetrol($U, $D, 110, $attId);
    echo "    rider claims 110 km (no machine named): " . ($r['ok'] ? 'ACCEPTED on vehicle ' . $r['vehicle_id'] : 'REFUSED — ' . $r['message']) . "\n";
    $r = (new FuelClaimRules())->checkMeteredPetrol($U, $D, 110, $attId, $OWN);
    echo "    rider claims 110 km on his own bike:   " . ($r['ok'] ? 'ACCEPTED' : 'REFUSED — ' . $r['message']) . "\n";
    $v = new VehicleService();
    echo "    518 plausible for his own bike? " . json_encode($v->readingPlausibleFor($OWN, 518, $D))
       . " · own-meter door (628)? " . json_encode($v->readingPlausibleFor($OWN, 628, $D, false)) . "\n";

    // ── what HIS PHONE receives: the rider-mode attendance month ─────────────
    $flushAll = function () { VehicleService::flushServiceMemo(); VehicleResolver::flush(); RiderDayLegs::flush(); };
    $flushAll();
    $rajab = \App\Models\User::find($U);
    \Illuminate\Support\Facades\Auth::shouldUse('web');
    \Illuminate\Support\Facades\Auth::setUser($rajab);
    $req = \Illuminate\Http\Request::create('/x', 'GET', ['month' => '2026-10-01']);
    $req->setUserResolver(fn () => $rajab);
    $j = json_decode(app(\App\Http\Controllers\API\RiderController::class)->getMonthlyAttendance($req)->getContent(), true);
    $days = $j['data']['history'] ?? $j['history'] ?? $j['data']['attendance'] ?? [];
    if (!$days) { foreach (($j['data'] ?? $j) as $val) { if (is_array($val) && isset($val[0]['date'])) { $days = $val; break; } } }
    $day = null; foreach ($days as $h) if (($h['date'] ?? null) === $D) $day = $h;
    if (!$day) { echo "    phone: 5-Oct row not found (" . substr(json_encode(array_keys($j['data'] ?? $j)), 0, 120) . ")\n"; return; }
    echo "    PHONE (current APK reads these): meter " . json_encode($day['meter_start'] ?? null) . " → " . json_encode($day['meter_end'] ?? null)
       . ", meter_distance=" . json_encode($day['meter_distance'] ?? null)
       . ($day['meter_warning'] ?? null ? ", warning=\"" . $day['meter_warning'] . "\"" : '') . "\n";
    foreach (($day['vehicles'] ?? []) as $vr) {
        echo "    PHONE machine row: {$vr['label']} {$vr['km']} km, can_claim=" . json_encode($vr['can_claim'])
           . ($vr['claim_status'] ? ", existing claim {$vr['claim_status']}" : '') . "\n";
    }
    echo "    can_add_own_meter=" . json_encode($day['can_add_own_meter'] ?? null) . "\n";
};

DB::beginTransaction();
try {
    // ── the day, as the production log tells it ──────────────────────────────
    DB::table('t_ops_vehicle_assignment')->where('user_id', $U)->whereNull('released_on')->update(['released_on' => $D]);
    DB::table('t_ops_vehicle_assignment')->where('vehicle_id', $VAN)->whereNull('released_on')->update(['released_on' => $D]);
    DB::table('t_ops_vehicle_assignment')->insert([
        ['vehicle_id' => $VAN, 'user_id' => $U, 'assigned_on' => $D, 'released_on' => $D, 'handover_meter' => 75932,
         'assigned_by' => 79, 'created_at' => "$D 14:06:21", 'updated_at' => "$D 14:33:40"],
        ['vehicle_id' => $OWN, 'user_id' => $U, 'assigned_on' => $D, 'released_on' => null, 'handover_meter' => null,
         'assigned_by' => 79, 'created_at' => "$D 14:33:40", 'updated_at' => "$D 14:33:40"],
    ]);
    DB::table('t_ops_vehicle_meter_log')->insert(['vehicle_id' => $VAN, 'log_date' => $D, 'meter_start' => 75932,
        'meter_end' => 75945, 'driver_user_id' => $U, 'entered_by' => 79, 'created_at' => now(), 'updated_at' => now()]);
    $tpl = (array) DB::table('t_ops_attendance')->where('user_id', $U)->orderByDesc('attendance_date')->first();
    unset($tpl['id']);
    foreach ($tpl as $k => $v) {
        if (str_starts_with($k, 'meter_') || str_ends_with($k, '_vehicle_id') || in_array($k, ['vehicle_id', 'picture_start', 'picture_end', 'picture_home'], true)) $tpl[$k] = null;
    }
    $tpl = array_merge($tpl, ['attendance_date' => $D, 'login_time' => '10:57:50', 'logout_time' => '21:03:13',
        'meter_start' => 518, 'meter_end' => 628, 'created_at' => "$D 10:57:50", 'updated_at' => "$D 21:03:13"]);
    DB::table('t_ops_attendance')->insert($tpl);
    $flush();

    echo "\nRajab, 5-Oct: own bike 518 → 628 (110 km), van 75,932 → 75,945 (13 km)\n\n";
    $show('A. AS PRODUCTION IS TODAY — the meter replacement is not recorded');

    if (MR::available()) {
        DB::table(MR::TABLE)->insert(['vehicle_id' => $OWN, 'reset_date' => '2026-10-02',
            'old_reading' => 9133, 'new_reading' => 0, 'entered_by' => 79]);
        $flush();
        echo "\n";
        $show('B. AFTER the upload, once the 2-Oct replacement (9,133 → 0) is recorded');
    }
} finally {
    DB::rollBack();
    $flush();
}
echo "\n(rolled back — nothing was saved)\n";
