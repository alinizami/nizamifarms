<?php
/**
 * FUEL ON A TWO-VEHICLE DAY (Aug-27 2026) — the rider who arrives on his own bike and
 * takes the company van out mid-shift.
 *
 * What these prove:
 *   §1  RiderDayLegs reassembles a day into one leg per machine, from every place a
 *       reading can live (attendance stamps + the vehicle meter log).
 *   §2  The per-km claim is refused on company kilometres (the double-payment hole) and
 *       ALLOWED on his own bike's — including on a mixed day, which used to be refused
 *       outright.
 *   §3  Real prod data: every claim Rajab was actually paid still passes, unchanged.
 *   §4  The rider payload keeps its old shape for installed APKs and gains vehicles[].
 *   §5  A claim is stamped to the machine that earned it, on both filing paths.
 *   §6  The meter editor suggests that day's driver.
 *   §7  One meter-log writer, shared by the manager's page and the rider's app.
 *   §8  Both attendance-correction doors can name the machine.
 *
 * ⚠ Every mutation runs inside a transaction that is ALWAYS rolled back. Synthetic rows
 *   use 2027 dates so they cannot collide with real ones.
 *
 * Run:  php test_two_vehicle_fuel.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Riders\FuelClaimRules;
use App\Services\Riders\MeterCorrectionService;
use App\Services\Riders\RiderDayLegs;
use App\Services\Riders\VehicleResolver;
use App\Services\Riders\VehicleService;
use Illuminate\Http\Request;
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

/** Drop every memo these services hold, so a mutation is actually observed. */
function flushAll(): void {
    RiderDayLegs::flush();
    VehicleResolver::flush();
    VehicleService::flushServiceMemo();
}

// The live cast, straight from the registry.
const RAJAB = 95;      // holds the Van, owns v9
const VAN   = 4;       // company
const OWN   = 9;       // APPLIED-FOR — Rajab's own bike
const KANAN = 76;      // company bike AY-4771, no rate group
const ASIM  = 70;      // per-km rider on his own bike v6

$legsSvc = new RiderDayLegs();
$rules   = new FuelClaimRules();

DB::beginTransaction();
try {

// ─────────────────────────────────────────────────────────────────────────────
head('§1 the day, reassembled per machine');

/** Insert an attendance row and return its id. */
$att = function (string $date, ?int $s, ?int $e, ?int $sv, ?int $ev, int $user = RAJAB) {
    return DB::table('t_ops_attendance')->insertGetId([
        'user_id' => $user, 'attendance_date' => $date,
        'login_time' => '11:00:00', 'logout_time' => '22:00:00',
        'meter_start' => $s, 'meter_end' => $e,
        'meter_start_vehicle_id' => $sv, 'meter_end_vehicle_id' => $ev,
        'created_at' => now(), 'updated_at' => now(),
    ]);
};
$log = function (string $date, int $vid, ?int $s, ?int $e, ?int $driver = null, int $by = 79) {
    return DB::table(VehicleService::T_METER_LOG)->insertGetId([
        'vehicle_id' => $vid, 'log_date' => $date, 'meter_start' => $s, 'meter_end' => $e,
        'driver_user_id' => $driver, 'entered_by' => $by,
        'created_at' => now(), 'updated_at' => now(),
    ]);
};

// (a) own bike both ends — the ordinary day, must be untouched
$aOwn = $att('2027-04-01', 6700, 6800, OWN, OWN);
flushAll();
$L = $legsSvc->forDay(RAJAB, '2027-04-01');
ok('own-only day → 1 leg', count($L), 1);
ok('  …on his own bike', $L[0]['vehicle_id'] ?? null, OWN);
ok('  …100 km', $L[0]['km'] ?? null, 100.0);
ok('  …claimable', count(RiderDayLegs::claimable($L)), 1);

// (b) van both ends — the double-pay shape
$aVan = $att('2027-04-02', 74117, 74284, VAN, VAN);
flushAll();
$L = $legsSvc->forDay(RAJAB, '2027-04-02');
ok('van-only day → 1 company leg', ($L[0]['is_company'] ?? null) === true && count($L) === 1, true);
ok('  …nothing claimable', count(RiderDayLegs::claimable($L)), 0);
ok('  …company detected', RiderDayLegs::hasCompany($L), true);

// (c) SPLIT — own start, van close. The case that used to kill the whole day.
$aSplit = $att('2027-04-03', 6800, 74400, OWN, VAN);
flushAll();
$L = $legsSvc->forDay(RAJAB, '2027-04-03');
ok('split day → 2 legs', count($L), 2);
ok('  …own leg first (his to act on)', $L[0]['vehicle_id'] ?? null, OWN);
// ⚠ `?? ` treats null as absent — compare the values directly, or a null km reads as the default.
ok('  …neither has a distance yet', $L[0]['km'] === null && $L[1]['km'] === null, true);
ok('  …so nothing is claimable', count(RiderDayLegs::claimable($L)), 0);

// (d) SPLIT + a manager-entered own-bike stint = his kilometres become claimable.
//     This is the owner's q3 in one assertion.
$log('2027-04-03', OWN, 6800, 6840, null, 79);
flushAll();
$L = $legsSvc->forDay(RAJAB, '2027-04-03');
$own = RiderDayLegs::forVehicle($L, OWN);
ok('manager-entered stint gives the own leg its 40 km', $own['km'] ?? null, 40.0);
ok('  …and it is claimable', count(RiderDayLegs::claimable($L)), 1);
ok('  …provenance names the manager', $own['entered_by_name'] ?? null, 'Shabib');
ok('  …the van leg is still company', (RiderDayLegs::forVehicle($L, VAN)['is_company'] ?? null), true);

// (e) HALF-STAMPED — own start stamped, close unstamped but plainly the same odometer.
$aHalf = $att('2027-04-04', 6900, 6950, OWN, null);
flushAll();
$L = $legsSvc->forDay(RAJAB, '2027-04-04');
ok('half-stamped, coherent pair → ONE leg (inherit)', count($L), 1);
ok('  …50 km on his own bike', ($L[0]['km'] ?? null) === 50.0 && $L[0]['vehicle_id'] === OWN, true);

// (f) HALF-STAMPED but incoherent — 67,000 km apart is two odometers, never one.
$aBad = $att('2027-04-05', 6960, 74500, OWN, null);
flushAll();
$L = $legsSvc->forDay(RAJAB, '2027-04-05');
ok('half-stamped, impossible pair → never merged into one distance',
   count(RiderDayLegs::claimable($L)), 0);

// (g) a log row that merely REPEATS the attendance reading is not a second stint
$aDup = $att('2027-04-06', 7000, 7100, OWN, OWN);
$log('2027-04-06', OWN, 7000, null, null, 79);          // same morning reading, typed twice
flushAll();
$L = $legsSvc->forDay(RAJAB, '2027-04-06');
ok('duplicate log row dropped → still 100 km, not 200', $L[0]['km'] ?? null, 100.0);
ok('  …and one leg only', count($L), 1);

// (h) a genuinely DIFFERENT second stint on the same machine is summed
$aTwo = $att('2027-04-07', 7200, 7300, OWN, OWN);
$log('2027-04-07', OWN, 7350, 7375, null, 79);
flushAll();
$L = $legsSvc->forDay(RAJAB, '2027-04-07');
ok('two real stints on one machine → summed', $L[0]['km'] ?? null, 125.0);
ok('  …reported as 2 parts', $L[0]['parts'] ?? null, 2);

// (i) driverless log on a COMPANY machine is never his
$aNone = $att('2027-04-08', null, null, null, null);
$log('2027-04-08', VAN, 74600, 74700, null, 79);
flushAll();
$L = $legsSvc->forDay(RAJAB, '2027-04-08');
ok('driverless log on the VAN is not claimed by him', count(RiderDayLegs::claimable($L)), 0);

// ─────────────────────────────────────────────────────────────────────────────
head('§2 the claim guard');

$r = fn ($date, $km, $aid, $vid = null, $ignore = null) =>
    $rules->checkMeteredPetrol(RAJAB, $date, $km, $aid, $vid, $ignore);

$res = $r('2027-04-02', 167.0, $aVan);
ok('van-only day: per-km claim REFUSED', $res['ok'], false);
// ⚠ Ask the resolver what the machine is CALLED rather than hard-coding a name.
//   The shared rule is "plate, else nickname" (VehicleResolver::labelFor and
//   RiderDayLegs::labelOf), so the moment prod fills in the van's registration the
//   label flips from 'Van' to 'CAD-2958' and a literal assertion goes red for
//   fixture drift rather than for a real fault.
ok('  …and the reason names the machine',
    str_contains($res['message'] ?? '', app(VehicleResolver::class)->labelFor(VAN) ?? '###'), true);

$res = $r('2027-04-01', 100.0, $aOwn);
ok('own-only day: allowed', $res['ok'], true);
ok('  …stamped to his own bike', $res['vehicle_id'], OWN);

$res = $r('2027-04-03', 40.0, $aSplit);
ok('MIXED day: his own 40 km allowed (was a blanket refusal)', $res['ok'], true);
ok('  …stamped to his own bike, not the van', $res['vehicle_id'], OWN);

$res = $r('2027-04-03', 116.0, $aSplit, VAN);
ok('MIXED day: naming the van is refused', $res['ok'], false);

$res = $r('2027-04-03', 999.0, $aSplit);
ok('a stale/forged distance is refused', $res['ok'], false);
ok('  …message states the real figure', str_contains($res['message'] ?? '', '40 km'), true);

$res = $r('2027-04-05', 67540.0, $aBad);
ok('the six-figure split distance is refused', $res['ok'], false);

// duplicate, per machine
$dupId = DB::table('t_req_master')->insertGetId([
    'request_number' => 'TEST-DUP-1', 'category_id' => 1, 'requester_user_id' => RAJAB,
    'title' => 'Expense', 'expense_category' => 'Petrol', 'expense_date' => '2027-04-01',
    'amount' => 950, 'status' => 'pending', 'attendance_id' => $aOwn,
    'meter_distance' => 100, 'petrol_rate' => 9.5, 'vehicle_id' => OWN,
    'created_at' => now(), 'updated_at' => now(),
]);
$res = $r('2027-04-01', 100.0, $aOwn);
ok('a second claim for the same machine+day is refused', $res['ok'], false);
$res = $r('2027-04-01', 100.0, $aOwn, null, $dupId);
ok('  …unless it IS that row being edited', $res['ok'], true);
DB::table('t_req_master')->where('id', $dupId)->delete();

// the kill switch
DB::table('t_fin_config')->insert(['config_key' => 'METERED_COMPANY_GUARD', 'config_value' => 'N']);
flushAll();
$res = $r('2027-04-02', 167.0, $aVan);
ok('kill switch off → the company refusal stands down', $res['ok'], true);
$dupId2 = DB::table('t_req_master')->insertGetId([
    'request_number' => 'TEST-DUP-2', 'category_id' => 1, 'requester_user_id' => RAJAB,
    'title' => 'Expense', 'expense_category' => 'Petrol', 'expense_date' => '2027-04-02',
    'amount' => 100, 'status' => 'pending', 'attendance_id' => $aVan,
    'created_at' => now(), 'updated_at' => now(),
]);
$res = $r('2027-04-02', 167.0, $aVan);
ok('  …but the one-per-day rule NEVER stands down', $res['ok'], false);
DB::table('t_req_master')->where('id', $dupId2)->delete();
DB::table('t_fin_config')->where('config_key', 'METERED_COMPANY_GUARD')->delete();
flushAll();

// fail-open: a person the registry knows nothing about
$res = $rules->checkMeteredPetrol(99999, '2027-04-01', 50.0, null);
ok('unknown rider → no opinion, never blocked', $res['ok'], true);

// ─────────────────────────────────────────────────────────────────────────────
head('§5 the claim is stamped to the machine that earned it');

$resolver = new VehicleResolver();
$mk = function (string $date, ?int $aid) {
    return DB::table('t_req_master')->insertGetId([
        'request_number' => 'TEST-STAMP-' . uniqid(), 'category_id' => 1,
        'requester_user_id' => RAJAB, 'title' => 'Expense', 'expense_category' => 'Petrol',
        'expense_date' => $date, 'amount' => 100, 'status' => 'pending',
        'attendance_id' => $aid, 'created_at' => now(), 'updated_at' => now(),
    ]);
};
$id1 = $mk('2027-04-03', $aSplit);
$resolver->stampClaim($id1, RAJAB, 'Petrol', '2027-04-03', $aSplit, OWN);
ok('explicit machine wins on a SPLIT day (stamps cannot answer)',
   (int) DB::table('t_req_master')->where('id', $id1)->value('vehicle_id'), OWN);

$id2 = $mk('2027-04-01', $aOwn);
$resolver->stampClaim($id2, RAJAB, 'Petrol', '2027-04-01', $aOwn, null);
ok('without one, the reading stamps still answer', (int) DB::table('t_req_master')->where('id', $id2)->value('vehicle_id'), OWN);

$id3 = $mk('2027-04-01', $aOwn);
DB::table('t_req_master')->where('id', $id3)->update(['vehicle_id' => VAN]);
$resolver->stampClaim($id3, RAJAB, 'Petrol', '2027-04-01', $aOwn, OWN);
ok('a deliberate correction is never overwritten', (int) DB::table('t_req_master')->where('id', $id3)->value('vehicle_id'), VAN);
DB::table('t_req_master')->whereIn('id', [$id1, $id2, $id3])->delete();

// ─────────────────────────────────────────────────────────────────────────────
head('§7 one meter-log writer, two doors');

$svc = new VehicleService();
$w = $svc->saveMeterLog(OWN, '2027-04-09', 7500, 7560, null, 'via writer', 79);
ok('writer creates', $w['action'] ?? null, 'created');
$w = $svc->saveMeterLog(OWN, '2027-04-09', 7500, 7570, null, null, 79);
ok('writer updates in place', $w['action'] ?? null, 'updated');
ok('  …one row only', DB::table(VehicleService::T_METER_LOG)->where('vehicle_id', OWN)->where('log_date', '2027-04-09')->count(), 1);
$w = $svc->saveMeterLog(OWN, '2027-04-09', null, null, null, null, 79);
ok('emptying both readings removes the row', $w['action'] ?? null, 'deleted');
ok('  …really gone', DB::table(VehicleService::T_METER_LOG)->where('vehicle_id', OWN)->where('log_date', '2027-04-09')->count(), 0);

// the rider's own door — gates only (the writer itself is proven above)
$mv = new \App\Http\Controllers\API\MyVehicleController();
// ⚠ The default guard here is a RequestGuard (sanctum) and has no loginUsingId. Only ONE
//   user is ever logged in per process — the standing harness rule about guard leakage.
\Illuminate\Support\Facades\Auth::guard('web')->loginUsingId(RAJAB);      // ONE user this process
$call = function (array $body) use ($mv) {
    $req = Request::create('/api/rider/my-vehicle/meter', 'POST', $body);
    $req->setUserResolver(fn () => \App\Models\SysAdmin\UserModel::find(RAJAB));
    try { return $mv->saveMeter($req, new VehicleService()); }
    catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json(['success' => false, 'message' => $e->validator->errors()->first()], 422);
    }
};
$today = date('Y-m-d');
$out = $call(['vehicle_id' => VAN, 'date' => $today, 'meter_start' => 74800]);
ok('rider cannot write the COMPANY van', $out->getStatusCode(), 403);

$out = $call(['vehicle_id' => OWN, 'date' => $today, 'meter_start' => 999999]);
ok('an implausible reading is refused', $out->getStatusCode(), 422);

$out = $call(['vehicle_id' => OWN, 'date' => date('Y-m-d', strtotime('-60 days')), 'meter_start' => 6700]);
ok('a day outside the window is refused', $out->getStatusCode(), 422);

$out = $call(['vehicle_id' => OWN, 'date' => date('Y-m-d', strtotime('+2 days')), 'meter_start' => 6700]);
ok('a FUTURE day is refused', $out->getStatusCode(), 422);

$svc->saveMeterLog(OWN, $today, 6700, null, null, 'manager row', 79);   // entered by someone else
flushAll();
$out = $call(['vehicle_id' => OWN, 'date' => $today, 'meter_start' => 6710]);
ok('he cannot overwrite his manager\'s row', $out->getStatusCode(), 422);
DB::table(VehicleService::T_METER_LOG)->where('vehicle_id', OWN)->where('log_date', $today)->delete();
flushAll();

$out = $call(['vehicle_id' => OWN, 'date' => $today, 'meter_start' => 6700, 'meter_end' => 6740]);
ok('his own bike, today, plausible → saved', $out->getStatusCode(), 200);
$row = DB::table(VehicleService::T_METER_LOG)->where('vehicle_id', OWN)->where('log_date', $today)->first();
ok('  …recorded as HIS driving', (int) ($row->driver_user_id ?? 0), RAJAB);
ok('  …and entered by him', (int) ($row->entered_by ?? 0), RAJAB);

// ─────────────────────────────────────────────────────────────────────────────
head('§8 both correction doors can name the machine');

$corr = new MeterCorrectionService();
$aCorr = $att('2027-04-10', null, null, null, null);
$corr->correct($aCorr, true, 7600, true, 7650, 79, OWN);
$row = DB::table('t_ops_attendance')->where('id', $aCorr)->first();
ok('a named machine is stamped onto both readings',
   [(int) $row->meter_start_vehicle_id, (int) $row->meter_end_vehicle_id], [OWN, OWN]);

$aCorr2 = $att('2027-04-11', 7700, 7750, VAN, VAN);
$corr->correct($aCorr2, true, 7710, false, null, 79, null);
$row = DB::table('t_ops_attendance')->where('id', $aCorr2)->first();
ok('naming nothing preserves the existing stamp', (int) $row->meter_start_vehicle_id, VAN);
ok('  …and still writes the reading', (int) $row->meter_start, 7710);

flushAll();
$L = $legsSvc->forDay(RAJAB, '2027-04-10');
ok('a corrected+stamped day becomes claimable at once', count(RiderDayLegs::claimable($L)), 1);

} finally {
    DB::rollBack();
    flushAll();
}

// ─────────────────────────────────────────────────────────────────────────────
// Everything below runs on REAL data with no mutation at all.
head('§3 real prod data — nothing that was paid may break');

$claims = DB::table('t_req_master')->where('requester_user_id', RAJAB)
    ->where('expense_category', 'Petrol')->whereNotNull('attendance_id')
    ->whereNotIn('status', ['cancelled', 'rejected'])->orderBy('expense_date')
    ->get(['id', 'expense_date', 'meter_distance', 'attendance_id']);
$allPass = true; $allOwn = true;
foreach ($claims as $c) {
    $res = $rules->checkMeteredPetrol(RAJAB, $c->expense_date, (float) $c->meter_distance,
                                      (int) $c->attendance_id, null, (int) $c->id);
    if (!$res['ok']) { $allPass = false; echo "      ⚠ {$c->expense_date}: {$res['message']}\n"; }
    // ⚠ 5-Oct-2026: his own bike's METER WAS REPLACED on 2-Oct. Until a manager records that
    //   (Bikes → 🧾 Meter → "Meter replaced"), the new meter's low readings cannot be placed
    //   on any machine, so those days honestly resolve to NOTHING. What must never happen is
    //   the dangerous answer — an own-bike claim landing on the VAN.
    $rv = $res['vehicle_id'] ?? null;
    if ($rv !== OWN && $rv !== null) { $allOwn = false; }
    if ($rv === VAN) { $allOwn = false; }
}
ok('every claim Rajab was actually paid still passes (' . $claims->count() . ')', $allPass, true);
ok('  …and every one resolves to his own bike (or to nothing — never to the van)', $allOwn, true);

flushAll();
$k = $legsSvc->forRange(KANAN, '2026-08-19', '2026-08-22');
$kOk = count($k) > 0;
foreach ($k as $d => $legs) {
    if (count($legs) !== 1 || !$legs[0]['is_company']) $kOk = false;
}
ok('a company-bike rider still reads as one company machine a day', $kOk, true);

flushAll();
$a = $legsSvc->forRange(ASIM, '2026-08-22', '2026-08-22');
ok('an ordinary own-bike per-km rider is unaffected',
   ($a['2026-08-22'][0]['is_company'] ?? true) === false, true);

// ─────────────────────────────────────────────────────────────────────────────
head('§4 the rider payload — old APKs must not notice');

// ⚠ The default guard here is a RequestGuard (sanctum) and has no loginUsingId. Only ONE
//   user is ever logged in per process — the standing harness rule about guard leakage.
\Illuminate\Support\Facades\Auth::guard('web')->loginUsingId(RAJAB);
$ctrl = new \App\Http\Controllers\API\RiderController();
$req  = Request::create('/api/rider/attendance/monthly', 'GET', ['month' => '2026-08-01']);
$req->setUserResolver(fn () => \App\Models\SysAdmin\UserModel::find(RAJAB));
$payload = json_decode($ctrl->getMonthlyAttendance($req)->getContent(), true);

ok('endpoint still succeeds', $payload['success'] ?? false, true);
$flatKeys = ['id','date','date_formatted','login_time','login_time_formatted','logout_time',
             'logout_time_formatted','status','late_minutes','notes','picture_start','picture_end',
             'meter_start','meter_end','meter_distance','meter_warning','checkin_latitude',
             'checkin_longitude','checkin_distance_from_base','is_remote_checkin',
             'checkout_latitude','checkout_longitude'];
$withRow = null;
foreach ($payload['history'] ?? [] as $h) { if (!empty($h['id'])) { $withRow = $h; break; } }
$missing = array_values(array_diff($flatKeys, array_keys($withRow ?? [])));
ok('every key the installed APK reads is still present', $missing, []);
ok('vehicles[] is additive and present', isset($withRow['vehicles']), true);
ok('petrol_requests is still an attendance-id MAP, not a list',
   is_array($payload['petrol_requests'] ?? null)
     && (empty($payload['petrol_requests']) || !array_is_list($payload['petrol_requests'])), true);
ok('petrol_window_days still sent', isset($payload['petrol_window_days']), true);

// The claimed days must present a distance equal to what was paid.
$byDate = [];
foreach ($payload['history'] ?? [] as $h) { $byDate[$h['date']] = $h; }
$agree = true;
foreach ($claims as $c) {
    $h = $byDate[substr((string) $c->expense_date, 0, 10)] ?? null;
    if (!$h) continue;
    if (abs((float) $h['meter_distance'] - (float) $c->meter_distance) > 0.01) {
        $agree = false;
        echo "      ⚠ {$c->expense_date}: payload {$h['meter_distance']} vs paid {$c->meter_distance}\n";
    }
}
ok('the distance shown equals the distance paid, on every claimed day', $agree, true);

// ─────────────────────────────────────────────────────────────────────────────
head('§9 end to end — the rider\'s phone on a MIXED day');
// The whole point, exercised through the real endpoints: a day whose attendance
// readings are the VAN's, with his own bike's kilometres recorded beside them.

DB::beginTransaction();
try {
    // ⚠ A REAL, RECENT date is required here, unlike the synthetic 2027 days above:
    //   the month endpoint stops at today, and both the claim window and the rider's
    //   meter door are bounded by PETROL_WINDOW_DAYS.
    // ⚠⚠ It must also be a day he WORKS. `history` lists working days only, so his
    //   weekly off (Tuesday, as the live data shows) silently produces no row at all
    //   and every assertion below would read null — a fixture fault that looks exactly
    //   like a broken feature.
    $shift = new \App\Services\ShiftResolutionService();
    $D = null;
    for ($back = 1; $back <= 5; $back++) {
        $cand = date('Y-m-d', strtotime("-{$back} days"));
        if (DB::table('t_ops_attendance')->where('user_id', RAJAB)->where('attendance_date', $cand)->exists()) continue;
        $kind = $shift->dayKind(RAJAB, $cand);
        if (in_array($kind, ['off', 'holiday', 'not_joined'], true)) continue;
        $D = $cand; break;
    }
    ok('a free WORKING day inside the claim window was found', $D !== null, true, true);
    if ($D === null) { throw new \RuntimeException('no usable fixture date'); }
    $aid = DB::table('t_ops_attendance')->insertGetId([
        'user_id' => RAJAB, 'attendance_date' => $D,
        'login_time' => '11:00:00', 'logout_time' => '22:00:00',
        'meter_start' => 74117, 'meter_end' => 74284,
        'meter_start_vehicle_id' => VAN, 'meter_end_vehicle_id' => VAN,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    flushAll();

    // (1) Before his own bike is recorded, the day offers him nothing to claim —
    //     and crucially does NOT offer the van's 167 km at his per-km rate.
    $ctrl = new \App\Http\Controllers\API\RiderController();
    $mk = function () use ($D) { $r = Request::create('/api/rider/attendance/monthly', 'GET',
                            ['month' => substr($D, 0, 7) . '-01']);
                        $r->setUserResolver(fn () => \App\Models\SysAdmin\UserModel::find(RAJAB)); return $r; };
    $day = null;
    foreach (json_decode($ctrl->getMonthlyAttendance($mk())->getContent(), true)['history'] ?? [] as $h) {
        if ($h['date'] === $D) { $day = $h; }
    }
    ok('the van day appears', $day !== null, true, true);
    // ⚠ Direct access, not `??` — null is the ANSWER here, and `??` would read it as
    //   "absent" and substitute the fallback (the same trap as in §1).
    ok('  …no claimable distance is offered (the Rs 1,586 bug)', $day['meter_distance'], null);
    ok('  …and he is told why', str_contains($day['meter_warning'] ?? '', 'company buys its fuel'), true);
    ok('  …the van leg is shown, marked company', $day['vehicles'][0]['is_company'] ?? null, true);
    ok('  …with no claim button on it', $day['vehicles'][0]['can_claim'] ?? null, false);
    ok('  …and the door to add his own bike is open', $day['can_add_own_meter'] ?? null, true);

    // (2) He records his own bike through his own endpoint.
    $mv  = new \App\Http\Controllers\API\MyVehicleController();
    $req = Request::create('/api/rider/my-vehicle/meter', 'POST', [
        'vehicle_id' => OWN, 'date' => $D, 'meter_start' => 6800, 'meter_end' => 6840,
    ]);
    $req->setUserResolver(fn () => \App\Models\SysAdmin\UserModel::find(RAJAB));
    try {
        $code = $mv->saveMeter($req, new VehicleService())->getStatusCode();
    } catch (\Illuminate\Validation\ValidationException $ve) {
        $code = $ve->validator->errors()->first();
    }
    ok('he can record his own bike for that day', $code, 200);
    flushAll();

    // (3) The same day now offers exactly his own 40 km.
    $day = null;
    foreach (json_decode($ctrl->getMonthlyAttendance($mk())->getContent(), true)['history'] ?? [] as $h) {
        if ($h['date'] === $D) { $day = $h; }
    }
    $own = null; $van = null;
    foreach ($day['vehicles'] ?? [] as $v) { if ($v['is_company']) $van = $v; else $own = $v; }
    // ⚠ Compared NUMERICALLY: JSON renders 40.0 as `40`, so a strict === against a
    //   float fails on a payload that is perfectly correct.
    ok('his own bike now appears as its own leg', (float) ($own['km'] ?? 0), 40.0);
    ok('  …claimable', $own['can_claim'] ?? null, true);
    ok('  …the van is still there and still the company\'s', $van['can_claim'] ?? null, false);
    ok('  …installed APKs now see HIS distance, not the van\'s', (float) ($day['meter_distance'] ?? 0), 40.0);
    ok('  …and his own readings, so the figures agree',
       [(float) $day['meter_start'], (float) $day['meter_end']], [6800.0, 6840.0]);

    // (4) He files it. This is the money.
    $cat = DB::table('t_req_category')->where('category_code', 'expense')->value('id');
    $post = function (array $extra) use ($cat, $D) {
        $r = Request::create('/api/rider/requests', 'POST', array_merge([
            'category_id' => $cat, 'title' => 'Expense', 'expense_category' => 'Petrol',
            'expense_date' => $D, 'petrol_rate' => 9.5,
        ], $extra));
        $r->setUserResolver(fn () => \App\Models\SysAdmin\UserModel::find(RAJAB));
        return $r;
    };
    $resp = $ctrl->createRequest($post([
        'amount' => 380, 'meter_distance' => 40, 'attendance_id' => $aid, 'vehicle_id' => OWN,
    ]));
    $body = json_decode($resp->getContent(), true);
    ok('the per-km claim for his own bike is accepted', $body['success'] ?? false, true);
    $newId = $body['request']['id'] ?? DB::table('t_req_master')->where('attendance_id', $aid)->max('id');
    ok('  …and stamped to his own bike, never the van',
       (int) DB::table('t_req_master')->where('id', $newId)->value('vehicle_id'), OWN);

    // (5) The van's kilometres stay unclaimable, and the day cannot be claimed twice.
    $resp = $ctrl->createRequest($post([
        'amount' => 1586.5, 'meter_distance' => 167, 'attendance_id' => $aid, 'vehicle_id' => VAN,
    ]));
    ok('claiming the VAN\'s kilometres is refused', $resp->getStatusCode(), 422);
    $resp = $ctrl->createRequest($post([
        'amount' => 380, 'meter_distance' => 40, 'attendance_id' => $aid, 'vehicle_id' => OWN,
    ]));
    ok('a second claim on the same machine+day is refused', $resp->getStatusCode(), 422);

    flushAll();
    $day = null;
    foreach (json_decode($ctrl->getMonthlyAttendance($mk())->getContent(), true)['history'] ?? [] as $h) {
        if ($h['date'] === $D) { $day = $h; }
    }
    foreach ($day['vehicles'] ?? [] as $v) { if (!$v['is_company']) $own = $v; }
    ok('his phone now shows the claim as pending', $own['claim_status'] ?? null, 'pending');
    ok('  …and offers no second button', $own['can_claim'] ?? null, false);

} finally {
    DB::rollBack();
    flushAll();
}

// ─────────────────────────────────────────────────────────────────────────────
head('§10 the backdating window — rider vs manager');

$fr = new FuelClaimRules();
$riderWin = $fr->petrolWindowDays(false);
$mgrWin   = $fr->petrolWindowDays(true);
ok('the rider window is the configured one', $riderWin, 5);
ok('the manager on-behalf window is longer', $mgrWin > $riderWin, true);
ok('  …and defaults to 30 days', $mgrWin, 30);

DB::beginTransaction();
try {
    // A manager can never end up with LESS room than the rider, whatever is stored.
    DB::table('t_fin_config')->insert(['config_key' => 'PETROL_WINDOW_DAYS_MANAGER', 'config_value' => '2']);
    ok('a manager window below the rider window is floored to it',
       (new FuelClaimRules())->petrolWindowDays(true), $riderWin);
    DB::table('t_fin_config')->where('config_key', 'PETROL_WINDOW_DAYS_MANAGER')->update(['config_value' => '15']);
    ok('a configured 15 is honoured', (new FuelClaimRules())->petrolWindowDays(true), 15);
    DB::table('t_fin_config')->where('config_key', 'PETROL_WINDOW_DAYS_MANAGER')->delete();

    // ⭐⭐ THE REPORTED CASE: a manager filing a 6-day-old day. Under the rider window
    //    this was the red "…only be raised for the last 5 days" 422 in his screenshot.
    $sixBack = date('Y-m-d', strtotime('-6 days'));
    ok('6 days back is OUTSIDE the rider window',
       $sixBack >= date('Y-m-d', strtotime("-{$riderWin} days")), false);
    ok('  …but INSIDE the manager window',
       $sixBack >= date('Y-m-d', strtotime("-{$mgrWin} days")), true);

    // And the picker no longer offers what the endpoint would refuse.
    $ctrl = new \App\Http\Controllers\CRM\VehicleController();
    $far  = date('Y-m-d', strtotime('-400 days'));
    $req  = Request::create('/x', 'GET', ['user_id' => RAJAB, 'date' => $far]);
    $req->setUserResolver(fn () => \App\Models\SysAdmin\UserModel::find(79));
    $body = json_decode($ctrl->petrolContext($req, new VehicleResolver())->getContent(), true);
    ok('the modal reports the window it is judged by', $body['window_days'] ?? null, $mgrWin);
    ok('  …and marks a far-past date out of window', $body['in_window'] ?? null, false);
    $offered = array_filter($body['vehicles'] ?? [], fn ($v) => $v['can_meter_claim']);
    ok('  …so no per-km claim is offered for it', count($offered), 0);
} finally {
    DB::rollBack();
    flushAll();
}

// ─────────────────────────────────────────────────────────────────────────────
head('§6 the meter editor suggests that day\'s driver');

$res = new VehicleResolver();
ok('the van on 22-Aug names its holder', $res->riderForVehicleDay(VAN, '2026-08-22'), RAJAB);
ok('the van on 1-Feb names the man who had it then', $res->riderForVehicleDay(VAN, '2026-02-01'), 78);
ok('a machine nobody held that day names nobody', $res->riderForVehicleDay(OWN, '2020-01-01'), null);

echo "\n" . str_repeat('─', 60) . "\n";
echo ($fail === 0 ? "ALL GREEN" : "FAILURES") . " — passed {$pass}, failed {$fail}\n";
exit($fail === 0 ? 0 : 1);
