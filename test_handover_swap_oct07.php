<?php
/**
 * 🔁🚚 7-Oct-2026 — "Bike badlein" for riders WITHOUT their own bike. Proof, end to end.
 *
 *   §1  a rider holding NOTHING can ask for the van (Mashood's 3/4-Oct mornings)
 *   §2  …and approving it hands the van over with its opening reading
 *   §3  a user with no rider profile is told at the door, not at approval
 *   §4  company → company SWAP in ONE request: both readings demanded, both stored
 *   §5  approving the swap moves BOTH machines and writes the hand-back reading
 *   §6  the world moved (his bike was taken back first) — the stale reading is NOT written
 *   §7  he was put on ANOTHER company machine after asking — approval asks for THAT reading
 *   §8  R4 unchanged: van → his own bike is still a RETURN of the van
 *   §9  the controller passes `close_meter` in and `meter_missing` out
 *
 * Fixtures are made at run time (temp vehicles; riders 81/82/83 who hold nothing on the replica)
 * and everything is undone from a shutdown function. Do not pipe through `head`.
 *
 * Run:  php test_handover_swap_oct07.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\CRM\VehicleHandoverController;
use App\Services\Riders\RiderDayLegs;
use App\Services\Riders\VehicleHandoverRequestService as HRS;
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
// ⚠ NOT head() — that shadows Laravel's helper the validator calls (traps index).
function section(string $t) { echo "\n== $t ==\n"; }
function flushAll(): void {
    VehicleService::flushServiceMemo(); VehicleResolver::flush(); RiderDayLegs::flush();
}

$UNDO = [];
$undo = function (callable $fn) use (&$UNDO) { $UNDO[] = $fn; };
register_shutdown_function(function () use (&$UNDO) {
    global $pass, $fail;
    echo "\n-- restoring --\n";
    foreach (array_reverse($UNDO) as $fn) {
        try { $fn(); } catch (\Throwable $e) { echo "  ! undo failed: " . $e->getMessage() . "\n"; }
    }
    echo "-- restored --\n\nRESULT: {$pass} passed, {$fail} failed\n";
});
$mkVehicle = function (string $nick, int $isCompany, string $vtype = 'bike') use ($undo): int {
    $id = DB::table(VehicleService::T_VEHICLE)->insertGetId([
        'vtype' => $vtype, 'nickname' => $nick, 'is_company' => $isCompany,
        'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $undo(fn () => DB::table(VehicleService::T_VEHICLE)->where('id', $id)->delete());
    $undo(fn () => DB::table(VehicleService::T_ASSIGN)->where('vehicle_id', $id)->delete());
    $undo(fn () => DB::table(VehicleService::T_METER_LOG)->where('vehicle_id', $id)->delete());
    return (int) $id;
};

const U1   = 82;   // Mashood-shape: holds nothing, no own bike
const U2   = 83;   // Waseem-shape: on a company bike, no own bike
const U3   = 81;
const BOSS = 79;   // Shabib — no rider profile, holds assign_vehicles
$TODAY = now()->format('Y-m-d');
$USERS = [U1, U2, U3];

// ---- preconditions: these riders must hold nothing, or the suite would move real machines
$res = new VehicleResolver();
foreach ($USERS as $u) {
    if ($res->currentVehicleFor($u)) { echo "ABORT: user $u holds a machine on this box — pick other fixtures.\n"; exit(1); }
    if (!DB::table('t_ops_rider_profile')->where('user_id', $u)->exists()) { echo "ABORT: user $u has no rider profile.\n"; exit(1); }
}
foreach ($USERS as $u) {
    $p = DB::table('t_ops_rider_profile')->where('user_id', $u)->first(['default_vehicle_id', 'company_bike']);
    $undo(fn () => DB::table('t_ops_rider_profile')->where('user_id', $u)->update([
        'default_vehicle_id' => $p->default_vehicle_id, 'company_bike' => $p->company_bike,
    ]));
}
$maxReq = (int) DB::table(HRS::TABLE)->max('id');
$undo(fn () => DB::table(HRS::TABLE)->where('id', '>', $maxReq)->delete());

$svc = new VehicleService();
$hrs = new HRS();
$keeper = function (int $vid) use ($svc) { flushAll(); $k = $svc->keeperOf($vid); return $k ? (int) $k->user_id : null; };
$logEnd = fn (int $vid) => ($r = DB::table(VehicleService::T_METER_LOG)->where('vehicle_id', $vid)
            ->where('log_date', $TODAY)->first(['meter_end'])) && $r->meter_end !== null ? (int) $r->meter_end : null;
$liveOne = fn (int $id) => (array_values(array_filter($hrs->live(), fn ($x) => (int) $x['id'] === $id))[0] ?? null);

ok('swap columns are in (SQL run on this box)', $hrs->swapReady(), true);

$VAN = $mkVehicle('TEST Van swap', 1, 'van');
$A   = $mkVehicle('TEST company bike A', 1);
$C   = $mkVehicle('TEST company bike C', 1);
$D   = $mkVehicle('TEST company bike D', 1);
flushAll();

// ============================================================================
section('§1 a rider holding NOTHING can ask for the van');
$o = $hrs->options(U1);
ok('he holds nothing', $o['holding'], null);
ok('the van is offered to him', in_array($VAN, array_column($o['options'], 'id'), true), true);
ok('the server says the swap is ready', $o['swap_ready'] ?? null, true);

$r = $hrs->raise(U1, 'take', $VAN, null, null);
ok('without the van\'s reading it is refused', $r['ok'], false);
ok('   naming the box', $r['meter_missing'] ?? null, 'meter');
$r = $hrs->raise(U1, 'take', $VAN, 1000, 'morning');
ok('with the reading it is accepted', $r['ok'], true);
$req1 = (int) ($r['id'] ?? 0);
ok('nothing moved yet', $keeper($VAN), null);
ok('his card shows it waiting', ($hrs->openFor(U1)['id'] ?? null), $req1);
ok('it is no swap', $hrs->find($req1)['swap_from_vehicle_id'], null);

// ============================================================================
section('§2 approving it hands the van over');
$d = $hrs->decide($req1, true, BOSS);
ok('approval succeeds', $d['ok'], true);
ok('the van is his', $keeper($VAN), U1);
ok('the opening reading is on his assignment row',
   (int) DB::table(VehicleService::T_ASSIGN)->where('vehicle_id', $VAN)->whereNull('released_on')->value('handover_meter'), 1000);

// ============================================================================
section('§3 no rider profile ⇒ told at the door');
$before = (int) DB::table(HRS::TABLE)->max('id');
$r = $hrs->raise(BOSS, 'take', $A, 500, null);
ok('refused', $r['ok'], false);
ok('   saying why', str_contains($r['message'] ?? '', 'rider profile'), true);
ok('   and nothing was written', (int) DB::table(HRS::TABLE)->max('id'), $before);

// ============================================================================
section('§4 company → company SWAP in ONE request');
$svc->assign($A, U2, $TODAY, BOSS, 'fixture', 500);
flushAll();
ok('fixture: he is on company bike A', $res->currentVehicleFor(U2), $A);

$r = $hrs->raise(U2, 'take', $C, 2000, null);                // no hand-back reading
ok('without A\'s closing reading it is refused', $r['ok'], false);
ok('   naming the second box', $r['meter_missing'] ?? null, 'close_meter');
ok('   asking for the reading first (not the old flat "Pehle … wapas karein" refusal)',
   str_starts_with($r['message'] ?? '', 'Pehle'), false);
ok('   while still telling an OLD app (no second box) the two-step way',
   str_contains($r['message'] ?? '', 'Purani app'), true);

$r = $hrs->raise(U2, 'take', $C, 2000, null, null, 520);
ok('with both readings it is accepted', $r['ok'], true);
$req4 = (int) ($r['id'] ?? 0);
$row = DB::table(HRS::TABLE)->where('id', $req4)->first();
ok('stored as a TAKE of C', [$row->direction, (int) $row->vehicle_id, (int) $row->meter_claimed], ['take', $C, 2000]);
ok('with A as the machine he hands back', (int) $row->swap_from_vehicle_id, $A);
ok('and A\'s closing reading', (int) $row->swap_from_meter, 520);
$card = $liveOne($req4);
ok('the approver\'s card names A', $card['swap_from_name'] ?? null, $res->labelFor($A));
ok('   with its reading', $card['swap_from_meter'] ?? null, 520);
ok('nothing moved yet', [$keeper($A), $keeper($C)], [U2, null]);

// ============================================================================
section('§5 approving the swap moves BOTH machines');
$d = $hrs->decide($req4, true, BOSS);
ok('approval succeeds', $d['ok'], true);
ok('C is his', $keeper($C), U2);
ok('A is free again', $keeper($A), null);
ok('A\'s day closes on the reading he typed', $logEnd($A), 520);
ok('C opens on his reading',
   (int) DB::table(VehicleService::T_ASSIGN)->where('vehicle_id', $C)->whereNull('released_on')->value('handover_meter'), 2000);
ok('the outcome sentence says A came back', str_contains($d['message'] ?? '', 'handed back at 520'), true);

// ============================================================================
section('§6 his bike was taken back BEFORE approval — the stale reading is not written');
$r = $hrs->raise(U2, 'take', $A, 530, null, null, 2100);     // swap C → A, C closes at 2,100
ok('swap C → A accepted', $r['ok'], true);
$req6 = (int) ($r['id'] ?? 0);
$svc->release($C, $TODAY, BOSS, false, 2060);                 // a manager took C back at 2,060
flushAll();
ok('fixture: he now holds nothing', $res->currentVehicleFor(U2), null);
$d = $hrs->decide($req6, true, BOSS);
ok('approval still succeeds (a plain take now)', $d['ok'], true);
ok('A is his', $keeper($A), U2);
ok('C keeps the manager\'s 2,060 — NOT the request\'s 2,100', $logEnd($C), 2060);
ok('and the sentence does not claim C was handed back', str_contains($d['message'] ?? '', 'handed back'), false);

// ============================================================================
section('§7 he was put on ANOTHER company machine after asking');
$r = $hrs->raise(U2, 'take', $C, 2070, null, null, 540);      // swap A → C, A closes at 540
ok('swap A → C accepted', $r['ok'], true);
$req7 = (int) ($r['id'] ?? 0);
$svc->assign($D, U2, $TODAY, BOSS, 'fixture', 3000, false, 545); // manager moved him onto D
flushAll();
ok('fixture: he now holds D', $res->currentVehicleFor(U2), $D);
$d = $hrs->decide($req7, true, BOSS);
ok('approval is refused…', $d['ok'], false);
ok('   asking for the hand-back reading', $d['meter_missing'] ?? null, 'close_meter');
ok('   of D, by id', $d['meter_vehicle_id'] ?? null, $D);
ok('   and the request is open again', DB::table(HRS::TABLE)->where('id', $req7)->value('status'), 'pending');
ok('nothing moved', [$keeper($C), $keeper($D)], [null, U2]);
$d = $hrs->decide($req7, true, BOSS, ['close_meter' => 3010]);
ok('the approver types D\'s reading → approved', $d['ok'], true);
ok('C is his', $keeper($C), U2);
ok('D closes on the approver\'s 3,010', $logEnd($D), 3010);
ok('the sentence names D — the machine that actually came back — not A',
   [str_contains($d['message'] ?? '', $res->labelFor($D) . ' handed back at 3,010'),
    str_contains($d['message'] ?? '', $res->labelFor($A) . ' handed back')], [true, false]);
ok('A was NOT given the request\'s stale 540', $logEnd($A) !== 540 || $logEnd($A) === null, true, true);

// ============================================================================
section('§8 R4 unchanged — van → his own bike is a RETURN of the van');
$OWN = $mkVehicle('TEST U1 own bike', 0);
DB::table(VehicleService::T_ASSIGN)->insert([          // he is its FIRST keeper
    'vehicle_id' => $OWN, 'user_id' => U1, 'assigned_on' => '2026-01-01', 'released_on' => '2026-01-02',
    'assigned_by' => BOSS, 'created_at' => now(), 'updated_at' => now(),
]);
flushAll();
$r = $hrs->raise(U1, 'take', $OWN, 1100, null);
ok('accepted', $r['ok'], true);
$row = DB::table(HRS::TABLE)->where('id', (int) ($r['id'] ?? 0))->first();
ok('filed as a RETURN of the van', [$row->direction, (int) $row->vehicle_id, (int) $row->give_back_vehicle_id], ['return', $VAN, $OWN]);
ok('never as a swap', $row->swap_from_vehicle_id, null);
$hrs->cancel((int) $row->id, U1);

// ============================================================================
section('§9 the controller: close_meter in, meter_missing out');
$ctl = new VehicleHandoverController();
$call = function (string $method, array $body, int $as, ...$args) use ($ctl) {
    $rq = Request::create('/x', 'POST', $body);
    $rq->headers->set('Accept', 'application/json');
    // ⚠ Bind FIRST: rebinding 'request' makes Laravel's auth provider overwrite the resolver.
    app()->instance('request', $rq);
    $rq->setUserResolver(fn () => \App\Models\User::find($as));
    try { $resp = $ctl->{$method}($rq, ...$args); }
    catch (\Illuminate\Validation\ValidationException $e) { return [422, ['validation' => $e->errors()]]; }
    return [$resp->getStatusCode(), $resp->getData(true)];
};
// U2 holds C (company); asks for A (company, free) without the hand-back reading
[$st, $js] = $call('raise', ['vehicle_id' => $A, 'meter' => 600], U2);
ok('refused with 422', $st, 422);
ok('   meter_missing reaches the phone', $js['meter_missing'] ?? null, 'close_meter');
[$st, $js] = $call('raise', ['vehicle_id' => $A, 'meter' => 600, 'close_meter' => 2120], U2);
ok('with close_meter it is accepted', [$st, $js['success'] ?? null], [200, true]);
$req9 = (int) ($js['id'] ?? 0);
ok('   and stored', (int) DB::table(HRS::TABLE)->where('id', $req9)->value('swap_from_meter'), 2120);
$hrs->cancel($req9, U2);

echo "\n";
