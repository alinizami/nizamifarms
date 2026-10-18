<?php
// Replica scenario probe — EVERYTHING inside a transaction that is rolled back.
require 'C:/NF App/nizamifarms/vendor/autoload.php';
$app = require 'C:/NF App/nizamifarms/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

$U = 78; $VAN = 4; $D = date('Y-m-d', strtotime('-1 day')); $M = substr($D, 0, 7);
function flags($x, $path = '') { $o = [];
  if (is_array($x) || is_object($x)) foreach ((array) $x as $k => $v) {
    $p = $path . '.' . $k;
    if (is_scalar($v) || $v === null) { if (preg_match('/meter|issue|flag|miss|no_end|status|detail|warn/i', (string) $k)) $o[$p] = $v; }
    else $o += flags($v, $p);
  } return $o; }

function scenario($label, $startVid, $disableWrite = false) {
  global $U, $VAN, $D, $M;
  DB::beginTransaction();
  try {
    if (DB::table('t_ops_attendance')->where('user_id', $U)->where('attendance_date', $D)->exists()) { echo "today row exists — abort\n"; return; }
    $attId = DB::table('t_ops_attendance')->insertGetId([
      'user_id' => $U, 'attendance_date' => $D, 'login_time' => '10:00:00',
      'meter_start' => 76030, 'meter_start_vehicle_id' => $startVid,
      'created_at' => now(), 'updated_at' => now(),
    ]);
    $reqId = DB::table('t_ops_vehicle_handover_request')->insertGetId([
      'user_id' => $U, 'direction' => 'return', 'vehicle_id' => $VAN, 'give_back_vehicle_id' => null,
      'meter_claimed' => 76070, 'status' => 'pending', 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $res = (new App\Services\Riders\VehicleHandoverRequestService())->decide($reqId, true, 79);
    echo "\n=== $label ===\n decide: ", json_encode(['ok' => $res['ok'], 'message' => $res['message'] ?? null]), "\n";
    if ($disableWrite) DB::table('t_ops_attendance')->where('id', $attId)->update(['meter_end' => null, 'meter_end_vehicle_id' => null]);
    $a = DB::table('t_ops_attendance')->where('id', $attId)->first();
    echo " attendance: start {$a->meter_start} (vid ", json_encode($a->meter_start_vehicle_id), ") end ", json_encode($a->meter_end), " (vid ", json_encode($a->meter_end_vehicle_id), ")\n";
    echo " holds now: ", json_encode((new App\Services\Riders\VehicleResolver())->currentVehicleFor($U)),
         " · meterRequiredNow: ", json_encode((new App\Services\Riders\VehicleResolver())->meterRequiredNow($U, $D)), "\n";
    // he presses OUT (what checkOut writes once the gate passes)
    DB::table('t_ops_attendance')->where('id', $attId)->update(['logout_time' => '18:00:00']);

    $ff = (new App\Services\Riders\FleetFuelService())->riderMonth($U, $M);
    $day = $ff['days'][$D] ?? null;
    if ($day === null && isset($ff['days']) && is_array($ff['days'])) foreach ($ff['days'] as $dd) if (($dd['date'] ?? null) === $D) $day = $dd;
    echo " FUEL day: ", json_encode($day ? array_intersect_key($day, array_flip(['status','detail','meter_ok','work_km','meter_start','meter_end'])) : 'not listed'), "\n";
    echo " DAY CHECKS meterMissDays: ", json_encode((new App\Services\Riders\DayChecksService())->meterMissDays([$U], $D, $D)), "\n";
    try {
      $rd = app(App\Services\Riders\RiderDayReviewService::class)->riderDay($U, $D);
      echo " DAY REVIEW flags: ", json_encode(array_slice(flags($rd), 0, 25)), "\n";
    } catch (\Throwable $e) { echo " DAY REVIEW error: ", $e->getMessage(), "\n"; }
  } finally { DB::rollBack(); }
}
scenario('A: start stamped to the van → write expected', $VAN);
scenario('B: start UNSTAMPED (checked in before van assigned) → write expected', null);
scenario('C: control — same as A with the write undone (= behaviour without this change)', $VAN, true);
scenario('D: start stamped to ANOTHER machine → no write expected', 8);
echo "\nafter: today rows for $U = ", DB::table('t_ops_attendance')->where('user_id', $U)->where('attendance_date', $D)->count(),
     " · holds = ", json_encode((new App\Services\Riders\VehicleResolver())->currentVehicleFor($U)), "\n";
// E: control for D — two-machine day closed ON the van at OUT (today's behaviour, no hand-back):
DB::beginTransaction();
try {
  $attId = DB::table('t_ops_attendance')->insertGetId(['user_id' => $U, 'attendance_date' => $D, 'login_time' => '10:00:00',
    'logout_time' => '18:00:00', 'meter_start' => 76030, 'meter_start_vehicle_id' => 8, 'meter_end' => 76070, 'meter_end_vehicle_id' => 4,
    'created_at' => now(), 'updated_at' => now()]);
  $ff = (new App\Services\Riders\FleetFuelService())->riderMonth($U, $M);
  $day = null; foreach (($ff['days'] ?? []) as $k => $dd) if ((($dd['date'] ?? $k)) === $D) $day = $dd;
  echo "\n=== E: control for D — start bike 8, closed on van at OUT (no hand-back) ===\n FUEL day: ",
       json_encode($day ? array_intersect_key($day, array_flip(['status','detail','meter_ok'])) : 'not listed'), "\n";
} finally { DB::rollBack(); }
