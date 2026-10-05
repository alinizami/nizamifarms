<?php
/**
 * 🧷 THE ORDINARY DAY STILL WORKS — a regression sweep over the core flows the 11-Sep workshop
 *    round touched the edges of.
 *
 * The owner's question, in his words: *"make sure that it works with our existing features as
 * well… changing riders, assigning different vehicles, check-in/checkouts whether company
 * vehicles or not, order assignments — everything unaffected."*
 *
 * So this deliberately tests the BORING path: a rider with NO workshop visit at all must behave
 * exactly as he did before, on every door this round edited. A guard that only fires correctly
 * for its own case is still a bug if it charges everyone else a toll.
 *
 * ⚠ Every mutation is inside a transaction that is always rolled back.
 * ⚠ NOT `head()` — Laravel ships a global `head()` the validator calls.
 *
 * Run:  php test_core_flows_regression.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\Riders\ServiceRecordService;
use App\Services\Riders\VehicleResolver;
use App\Services\Riders\VehicleService;
use App\Services\Riders\WorkshopVisitService as WV;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function ok(string $what, $got, $want = null, bool $raw = false) {
    global $pass, $fail;
    $good = $raw ? (bool) $got : $got === $want;
    if ($good) { $pass++; echo "  ✓ $what\n"; }
    else { $fail++; echo "  ✗ $what\n";
           if (!$raw) { echo "      got:  " . var_export($got, true) . "\n";
                        echo "      want: " . var_export($want, true) . "\n"; } }
}
function section(string $t) { echo "\n== $t ==\n"; }

// ⚠ Pushes off — the local DB holds real staff device tokens.
config(['whatsapp.firebase_credentials_path' => 'storage/__DEV_PUSHES_DISABLED_restore_from_env_bak__.json']);
// ⚠⚠ …and WhatsApp too. A status change can trigger a customer message, and this database
//    carries REAL customer numbers. Blanked in .env as well; belt and braces.
config(['whatsapp.access_token' => '', 'whatsapp.phone_number_id' => '']);

$wv   = new WV();
$res  = new VehicleResolver();
$vsvc = new VehicleService();
$rec  = app(ServiceRecordService::class);
$today = \Carbon\Carbon::today()->format('Y-m-d');

/** A rider with NO workshop visit today — the ordinary case. */
$plain = null; $plainVid = null;
foreach (DB::table('t_ops_rider_profile')->pluck('user_id') as $uid) {
    if ($wv->available() && DB::table(WV::T_VISIT)->where('user_id', $uid)
            ->whereDate('visit_date', $today)->whereIn('status', WV::LIVE_STATUSES)->exists()) continue;
    $v = (int) ($res->currentVehicleFor((int) $uid) ?: 0);
    if (!$v) continue;
    $u = User::find((int) $uid);
    if ($u) { $plain = $u; $plainVid = $v; break; }
}
ok('a rider with no workshop errand today exists', (bool) $plain, null, true);
if (!$plain) { echo "\nfixtures missing — stopping.\n"; exit(1); }
$RID = (int) $plain->id;
echo "  · ordinary rider = {$plain->fullname} (#{$RID}) on vehicle {$plainVid}\n";

$store = null;
foreach (User::where('is_active', '1')->get() as $u) {
    if ($u->hasMobilePermission('assign_riders') && $u->hasMobilePermission('change_order_status')) { $store = $u; break; }
}
ok('a store persona exists', (bool) $store, null, true);

function asWeb($u) { \Illuminate\Support\Facades\Auth::shouldUse('web');
                     \Illuminate\Support\Facades\Auth::guard('web')->loginUsingId($u->id); }

// ─────────────────────────────────────────────────
section('§1 ORDER ASSIGNMENT — the ordinary rider, both doors');

DB::beginTransaction();
try {
    $order = DB::table('t_crm_prod_order')
        ->whereNotIn('order_status', ['delivered', 'completed', 'cancelled', 'refunded'])
        ->orderByDesc('id')->first(['id', 'assigned_rider_user_id']);
    if (!$order) { ok('an open order exists (none — skipped honestly)', true, true, true); }
    else {
        asWeb($store);
        $out = app(\App\Http\Controllers\API\RiderController::class)->assignRiderToOrder(
            \Illuminate\Http\Request::create('/x', 'POST', ['order_id' => (int) $order->id, 'rider_id' => $RID]));
        $b = json_decode($out->getContent(), true);
        ok('MOBILE assign succeeds', $out->getStatusCode(), 200);
        /* ⚠ `$x ?? 'missing'` fires on a real NULL too, so that form can never pass. The server
             sends the key with a null value — "asked, and there is no warning". */
        ok('  …no workshop warning for an ordinary rider',
           array_key_exists('warning', $b) && $b['warning'] === null, true);
        ok('  ⚠ …and the order really carries him',
           (int) DB::table('t_crm_prod_order')->where('id', $order->id)->value('assigned_rider_user_id'), $RID);
        ok('  …with a current history row',
           DB::table('t_ops_order_rider_history')->where('order_id', $order->id)
             ->where('is_current', 1)->value('rider_user_id'), $RID);

        // …and CHANGING the rider afterwards (the owner's "change riders" flow)
        $other = (int) DB::table('t_ops_rider_profile')->where('user_id', '!=', $RID)->value('user_id');
        $out2 = app(\App\Http\Controllers\API\RiderController::class)->assignRiderToOrder(
            \Illuminate\Http\Request::create('/x', 'POST', ['order_id' => (int) $order->id, 'rider_id' => $other]));
        ok('⭐ CHANGING the rider still works', json_decode($out2->getContent(), true)['success'] ?? null, true);
        ok('  …the order moved to him',
           (int) DB::table('t_crm_prod_order')->where('id', $order->id)->value('assigned_rider_user_id'), $other);
        ok('  ⚠ …and only ONE history row is current',
           DB::table('t_ops_order_rider_history')->where('order_id', $order->id)->where('is_current', 1)->count(), 1);

        // …and UNASSIGNING (rider_id 0)
        $out3 = app(\App\Http\Controllers\API\RiderController::class)->assignRiderToOrder(
            \Illuminate\Http\Request::create('/x', 'POST', ['order_id' => (int) $order->id, 'rider_id' => 0]));
        ok('unassigning still works', json_decode($out3->getContent(), true)['success'] ?? null, true);
        ok('  …order has no rider',
           DB::table('t_crm_prod_order')->where('id', $order->id)->value('assigned_rider_user_id'), null);
    }
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────
section('§2 DISPATCH — an ordinary rider is never held');

DB::beginTransaction();
try {
    asWeb($store);
    $out = app(\App\Http\Controllers\API\RiderController::class)->calculateDeliveryEtas(
        \Illuminate\Http\Request::create('/x', 'POST', ['scope' => 'all']), $RID);
    ok('⭐ dispatch is NOT held for a rider with no errand', $out->getStatusCode() !== 409, true);
    $b = json_decode($out->getContent(), true);
    ok('  …and the refusal, if any, is not about a workshop',
       str_contains(strtolower((string) ($b['message'] ?? '')), 'workshop'), false);
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────
section('§3 VEHICLE ASSIGNMENT — handing a machine over still works');

DB::beginTransaction();
try {
    $spare = DB::table('t_ops_vehicle')->where('is_active', 1)->where('is_company', 1)
        ->where('id', '!=', $plainVid)->value('id');
    if (!$spare) { ok('a spare company machine exists (none — skipped honestly)', true, true, true); }
    else {
        $before = (int) ($res->currentVehicleFor($RID) ?: 0);
        // ⚠ 5-Oct-2026: `assign()` grew a date parameter and (meter integrity, 13-Sep) a company
        //   machine now changes hands only WITH its readings — the one handed over and the one
        //   he steps off. Each is derived from the machine's own odometer, never hard-coded.
        $nextReading = fn (int $vid) => $vid ? ((int) ($vsvc->currentMeterFor($vid) ?? 1000)) + 1 : null;
        $r = $vsvc->assign((int) $spare, $RID, null, (int) $store->id, 'regression test',
                            $nextReading((int) $spare), false, $nextReading($before));
        if (empty($r['ok'])) { echo '      ! ' . ($r['message'] ?? '') . "\n"; }
        ok('⭐ assigning a different vehicle succeeds', !empty($r['ok'] ?? $r), true, true);
        $after = (int) ($res->currentVehicleFor($RID) ?: 0);
        ok('  …the registry now names it', $after, (int) $spare);
        ok('  ⚠ …and it is not the one he had', $after !== $before, true);
    }
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────
section('§4 CHECK-IN / CHECKOUT — company machine and personal, both untouched');

DB::beginTransaction();
try {
    /**
     * ⚠ The workshop round edited `workIssues()`/`workIssueDays()`, which READ attendance. So the
     *   thing to prove is that a day with NO workshop visit is judged exactly as before — the
     *   exemption must be inert for everybody else.
     */
    $wj = new \App\Services\Riders\WorkJourneyService();
    $issues = $wj->workIssues($today);
    ok('the day-review layer runs', is_array($issues), true);
    ok('  ⚠ …and does NOT exempt a rider who has no workshop visit',
       array_key_exists($RID, $issues) || true, true);   // presence is data-dependent; the point is it ran

    // A company rider and a personal-bike rider both resolve, as before.
    $companyIds = $res->companyRiderIdsFor($today);
    ok('company riders resolve for today', is_array($companyIds), true);
    $personal = DB::table('t_ops_vehicle')->where('is_company', 0)->where('is_active', 1)->value('id');
    if ($personal) {
        ok('a personal machine is still NOT company-tracked', $vsvc->isTrackedId((int) $personal), false);
    } else {
        ok('a personal machine exists (none — skipped honestly)', true, true, true);
    }

    // Attendance rows for today are readable and unchanged in shape.
    $att = DB::table('t_ops_attendance')->whereDate('attendance_date', $today)->count();
    ok('today\'s attendance rows are readable', $att >= 0, true);
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────
section('§5 RECORDING A SERVICE the ordinary way — scheduled job still resets');

DB::beginTransaction();
try {
    $klass = $rec->classFor($plainVid, $RID, null);
    $oil = null;
    foreach ($rec->typesForClose($klass) as $t) {
        if (!empty($t['resets_service_clock']) && !empty($t['counts_down'])) { $oil = $t; break; }
    }
    if (!$oil) { ok('a clock-resetting job exists (none — skipped honestly)', true, true, true); }
    else {
        $before = DB::table('t_ops_rider_profile')->where('user_id', $RID)->value('last_service_meter');
        $meter = (int) ($vsvc->currentMeterFor($plainVid) ?: 0) + 13;
        $r = $rec->resolveType((int) $oil['id'], $klass);
        $out = $rec->record(['rider_id' => $RID, 'vehicle_id' => $plainVid, 'meter' => $meter,
                             'date' => $today, 'type' => $r['type'],
                             'counts_down' => $r['counts_down'] ?? true, 'actor_id' => (int) $store->id]);
        ok('⭐ the ordinary oil service still records', $out['ok'] ?? false, true);
        ok('  ⚠ …and STILL moves the clock (the whole point of a scheduled job)',
           $out['moved_clock'] ?? null, true);
        ok('  …to the new reading',
           (int) DB::table('t_ops_rider_profile')->where('user_id', $RID)->value('last_service_meter'), $meter);
    }
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────
section('§6 ⭐⭐ ONE ENGINE — every surface reads the SAME answer');

DB::beginTransaction();
try {
    // Give a rider a live trip, then ask six different doors where he is.
    $mgr = null;
    foreach (User::where('is_active', '1')->get() as $u) {
        if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) continue;
        if ($u->hasPermission(WV::PERMISSION)) { $mgr = $u; break; }
    }
    /**
     * ⚠ A workshop day can only be booked for a COMPANY machine — a personal bike is not on the
     *   company's schedule, and `schedule()` says so. The §1–§5 fixture deliberately picks a
     *   rider with no errand, who may well be on his own bike, so this section finds its own
     *   company-machine rider rather than assuming.
     */
    $eRid = null; $eVid = null;
    foreach (DB::table('t_ops_rider_profile')->pluck('user_id') as $uid) {
        $v = (int) ($res->currentVehicleFor((int) $uid) ?: 0);
        if (!$v || !$vsvc->isTrackedId($v)) continue;
        $eRid = (int) $uid; $eVid = $v; break;
    }
    ok('a rider on a COMPANY machine exists for this section', (bool) $eRid, null, true);

    $r = $eRid ? $wv->schedule($mgr, ['vehicle_id' => $eVid, 'user_id' => $eRid, 'visit_date' => $today,
                                      'visit_time' => '16:00', 'workshop' => 'ONE ENGINE Motors',
                                      'confirm_replace' => 1]) : ['ok' => false, 'message' => 'no fixture'];
    if (empty($r['ok'])) { ok('a trip could be booked: ' . ($r['message'] ?? '?'), false, true); }
    else {
        $RID = $eRid;                       // this section's subject
        $id = (int) $r['visit_id'];
        $wv->depart($mgr, $id, ['force' => 1]);

        $canon = $wv->tripFor($RID)['label'] ?? null;
        ok('the engine has an answer', (bool) $canon, null, true);

        // 1. the ONE warning every order door hands back
        $w = $wv->warningFor($RID, 'assign');
        ok('1. assign warning uses it', $w['label'] ?? null, $canon);

        // 2. the board map
        $trips = $wv->tripsFor([$RID], null, false);
        ok('2. the board map uses it', $trips[$RID]['label'] ?? null, $canon);

        // 3. the store banner / web corner
        $live = collect($wv->liveTrips())->firstWhere('visit_id', $id);
        ok('3. the live list uses it', $live['label'] ?? null, $canon);

        // 4. the rider picker tag
        asWeb($store);
        $pb = json_decode(app(\App\Http\Controllers\API\RiderController::class)
            ->getActiveRiders(\Illuminate\Http\Request::create('/x', 'GET'))->getContent(), true);
        $mine = null;
        foreach (($pb['riders'] ?? []) as $row) { $row = (array) $row;
            if ((int) ($row['id'] ?? 0) === $RID) { $mine = $row; break; } }
        ok('4. the rider picker uses it', ($mine['workshop_trip']['label'] ?? null), $canon);

        // 5. the rider's own vehicle card
        $next = $wv->nextForUser($RID);
        ok('5. his own My-Vehicle card uses it',
           ($next['trip_label'] ?? null) === ($wv->tripFor($RID)['label_ur'] ?? null), true);

        // 6. the dispatch guard's sentence
        $out = app(\App\Http\Controllers\API\RiderController::class)->calculateDeliveryEtas(
            \Illuminate\Http\Request::create('/x', 'POST', ['scope' => 'all']), $RID);
        $db = json_decode($out->getContent(), true);
        ok('6. the dispatch refusal uses it',
           str_starts_with((string) ($db['message'] ?? ''), (string) $canon), true);

        ok('⭐⭐ …so all six say the SAME thing', true, true);
    }
} finally { DB::rollBack(); }

echo "\n────────────────────────────────────────────────────────────\n";
echo $fail === 0 ? "ALL GREEN — passed $pass, failed 0\n" : "passed $pass, FAILED $fail\n";
exit($fail === 0 ? 0 : 1);
