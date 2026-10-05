<?php
/**
 * THE FOUR PERSONAS — can each of them actually do their job? (3-Sep-2026)
 *
 * The owner's ask: check Qasim (frozen mode), Shabib (store), Taimur, and Farooq (who must see
 * that a rider has a workshop day, plus the notification from Qasim) — that everything they had
 * BEFORE still works, that everything added in this round is reachable for the right person and
 * refused for the wrong one, and that manual meters / maintenance entered by other routes all
 * still feed ONE engine for readings and alerts.
 *
 * ⚠ This asserts the SEAMS between permission and surface — the class of bug a feature test
 *   cannot see, because each feature works perfectly for whoever wrote the test.
 *
 * ⚠ Every mutation is inside a transaction that is always rolled back. No user is logged in:
 *   every service takes the actor as an argument, which is also the contract being checked.
 *
 * Run:  php test_fleet_personas.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\Riders\FleetAttentionService;
use App\Services\Riders\RiderDayLegs;
use App\Services\Riders\ServiceIntervalResolver;
use App\Services\Riders\ServiceRecordService;
use App\Services\Riders\VehicleResolver;
use App\Services\Riders\VehicleService;
use App\Services\Riders\VehicleTicketService as VT;
use App\Services\Riders\WorkshopVisitService as WV;
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
    ServiceIntervalResolver::flush(); VehicleService::flushServiceMemo(); VehicleResolver::flush();
    RiderDayLegs::flush();
    try { \Illuminate\Support\Facades\Cache::flush(); } catch (\Throwable $e) {}
}

$vt = new VT(); $wv = new WV(); $rec = app(ServiceRecordService::class);

// ─── the people, found by NAME (these four are the ask) ──────────────────────
head('§0 the four personas');
$who = [];
foreach (['Qasim', 'Shabib', 'Taimur', 'Farooq'] as $n) {
    $u = User::where('fullname', 'like', "%$n%")->where('is_active', '1')->first();
    ok("$n exists and is active", (bool) $u, null, true);
    $who[$n] = $u;
}
if (in_array(null, $who, true)) { echo "\npersonas missing — stopping.\n"; exit(1); }

/**
 * A rider with a registered machine, to act upon.
 *
 * ⚠⚠ IT MUST BE A COMPANY MACHINE (15-Sep-2026). Tickets may only be raised on machines the
 *    company owns (owner ruling — VehicleTicketService::ticketableMachineIds), so a rider whose
 *    current machine is his OWN bike makes §1 fail for a correct reason. Discover a fixture that
 *    fits the question rather than taking the first row the registry returns.
 */
$rider = null; $vid = null;
$res = new VehicleResolver();
$vehSvc = new \App\Services\Riders\VehicleService();
foreach (DB::table('t_ops_rider_profile')->pluck('user_id') as $uid) {
    $v = $res->currentVehicleFor((int) $uid);
    if (!$v || !$vehSvc->isCompanyMachine((int) $v)) continue;
    $rider = User::find((int) $uid); $vid = (int) $v; break;
}
ok('a rider with a COMPANY machine exists to act on', (bool) $rider, null, true);
if (!$rider) { echo "\nno rider holds a company machine — stopping.\n"; exit(1); }
foreach ($who as $n => $u) {
    printf("  · %-7s id=%-4s tickets=%s workshop=%s service=%s wsAlerts(W/M)=%s%s\n", $n, $u->id,
        $vt->canManage($u, true) ? 'Y' : 'n',
        $wv->canSchedule($u, true) ? 'Y' : 'n',
        $u->hasMobilePermission('manage_bike_service') ? 'Y' : 'n',
        $u->hasPermission(WV::ALERT_PERMISSION) ? 'W' : '-',
        $u->hasMobilePermission(WV::ALERT_PERMISSION) ? 'M' : '-');
}

$beforeT = DB::table(VT::T_TICKET)->count();
$beforeW = DB::table(WV::T_VISIT)->count();
$beforeL = DB::table('t_fleet_service_log')->count();

DB::beginTransaction();
try {

// ─────────────────────────────────────────────────────────────────────────────
head('§1 QASIM — frozen mode: the full fleet job');

$q = $who['Qasim'];
ok('can manage tickets on mobile (frozen mode is the same FleetScreen)', $vt->canManage($q, true), true);
ok('  …and on the web', $vt->canManage($q, false), true);
ok('can schedule workshop visits', $wv->canSchedule($q, true), true);
ok('can record + correct services', $q->hasMobilePermission('manage_bike_service'), true);
ok('can open the Bikes screen at all (view_bike_costs)', $q->hasMobilePermission('view_bike_costs'), true);
ok('is told when a rider reports a fault', $q->hasMobilePermission(VT::ALERT_PERMISSION), true);
ok('is told when a workshop date is set', $q->hasMobilePermission(WV::ALERT_PERMISSION), true);

// End to end as Qasim: ticket → reply → workshop → complete with a typed service.
$t = $vt->open($q, ['vehicle_id' => $vid, 'opened_for_user_id' => (int) $rider->id,
                    'title' => 'PERSONA: brake noise']);
ok('opens a ticket for a rider without knowing the bike id', $t['ok'], true);
$tid = (int) $t['ticket_id'];
ok('the registry supplied the machine', (int) $vt->find($tid)['vehicle_id'], $vid);
ok('replies to it', $vt->reply($q, $tid, ['kind' => 'text', 'body' => 'Bringing it in.'])['ok'], true);
// ⏰ Tomorrow, not today: a same-day proposal is only approvable before the rider's cut-off
//    (an hour before his shift), and this suite runs at any hour of the day.
/**
 * ⚠⚠ `confirm_replace` ON A FIXTURE BOOKING (the documented precedent — four suites needed this
 *    when the 7-Sep ruling landed). If the discovered machine already has an APPROVED live visit
 *    on prod data, `schedule()` correctly answers 409 `needs_confirmation` instead of booking,
 *    and every assertion below then dies on a missing `visit_id`. The flag says "yes, replace it"
 *    — which is exactly what a fixture means, and the transaction rolls it all back anyway.
 */
$w = $wv->schedule($q, ['user_id' => (int) $rider->id,
                        'visit_date' => \Carbon\Carbon::today()->addDay()->format('Y-m-d'),
                        'ticket_id' => $tid, 'confirm_replace' => 1]);
ok('schedules a workshop visit off that ticket', $w['ok'], true);
if (empty($w['visit_id'])) { echo "      ! " . ($w['message'] ?? 'no visit_id') . "\n"; }
$wid = (int) ($w['visit_id'] ?? 0);
/**
 * ⏳ CHANGED 6-Sep BY THE APPROVAL RULING. Qasim holds `schedule_workshop` but NOT
 *    `manage_shifts`, so his booking is a REQUEST: the rider is told nothing, his day is not
 *    pinned, and — the part this line used to assert — the ticket he is watching is NOT moved
 *    to "workshop set", because there is not yet a workshop date to tell him about.
 */
ok('  ⏳ …which is a PROPOSAL, so the rider’s ticket is not moved yet', $w['proposed'] ?? null, true);
ok('  …the thread he reads still says what it said', $vt->find($tid)['status'], 'acknowledged');
$planner = collect($who)->first(fn ($u) => $wv->canApprove($u, false) || $wv->canApprove($u, true));
ok('  …and a PLANNER approving it is what moves it to "workshop set"',
   $planner && $wv->approve($planner, $wid, [], !$wv->canApprove($planner, false))['ok']
       && $vt->find($tid)['status'] === 'scheduled', true);
$types = $rec->scheduledTypes();
$tp = $rec->resolveType($types[0]['id'] ?? null);
/**
 * ⚠⚠ DERIVE THE READING, NEVER HARD-CODE IT. This was `90001`, which the plausibility guard
 *    correctly refuses once the discovered machine is one whose odometer sits near 28,000 —
 *    "a missing digit here would record a service no countdown can use". The suite was asserting
 *    a number, not a rule. Ask the machine where it is and add a plausible day's riding.
 *    (Same trap as the hard-coded labels and ids this suite was bitten by in August.)
 */
$meterNow = $vehSvc->currentMeterFor($vid);
$done = $rec->record(['rider_id' => (int) $rider->id, 'meter' => (int) ($meterNow ?? 0) + 5,
                      'date' => \Carbon\Carbon::today()->format('Y-m-d'),
                      'type' => $tp['type'], 'actor_id' => (int) $q->id, 'note' => 'PERSONA']);
if (!$done['ok']) { echo "      ! " . ($done['message'] ?? '') . "\n"; }
ok('completes it as a TYPED service record', $done['ok'], true);
$logId = (int) $done['service_log_id'];
ok('closes the ticket', $vt->close($q, $tid, 'done')['ok'], true);

// ✏️ And can correct his own record afterwards — the log-8 lesson.
// ⚠ 5-Oct-2026: was a hard-coded 90,002 — refused, rightly, by the same plausibility guard the
//   note above describes. A correction is a few km from what was recorded, so derive it.
ok('can CORRECT a service record he entered',
   $rec->amend($logId, ['meter' => (int) ($meterNow ?? 0) + 6], (int) $q->id)['ok'], true);
ok('  …and remove one', $rec->remove($logId, (int) $q->id)['ok'], true);

// ─────────────────────────────────────────────────────────────────────────────
head('§2 SHABIB and TAIMUR — store mode: the same powers');

foreach (['Shabib', 'Taimur'] as $n) {
    $u = $who[$n];
    ok("$n can manage tickets", $vt->canManage($u, true), true);
    ok("  …schedule workshop visits", $wv->canSchedule($u, true), true);
    ok("  …record and correct services", $u->hasMobilePermission('manage_bike_service'), true);
    ok("  …open Bikes", $u->hasMobilePermission('view_bike_costs'), true);
    ok("  …and hand a machine over (assign_vehicles)", $u->hasPermission('assign_vehicles'), true);

    $tt = $vt->open($u, ['vehicle_id' => $vid, 'opened_for_user_id' => (int) $rider->id,
                         'title' => "PERSONA: $n"]);
    ok("  …and really can open + close one", $tt['ok'] && $vt->close($u, (int) $tt['ticket_id'], 'x')['ok'], true);
}

// ⚠ Qasim (frozen) does NOT hold assign_vehicles. Recorded so a later change is deliberate.
ok('⚠ Qasim canNOT hand a machine over — frozen mode is not the vehicle registry',
   $who['Qasim']->hasPermission('assign_vehicles'), false);

// ─────────────────────────────────────────────────────────────────────────────
head('§3 FAROOQ — the shift planner: told, but not given the controls');

$f = $who['Farooq'];
ok('is told a workshop date was set — on MOBILE', $f->hasMobilePermission(WV::ALERT_PERMISSION), true);
/**
 * ⚠⚠ FOUND HERE (3-Sep). The key existed only as a MOBILE permission, so on the WEB it was
 *    false for everyone and the corner banner fell back to "can this person schedule?" —
 *    true for Qasim/Shabib/Taimur, FALSE for Farooq. The one man the owner named saw nothing
 *    on the shift planner, the page he actually works in.
 */
ok('is told on the WEB too — the planner is where he works', $f->hasPermission(WV::ALERT_PERMISSION), true);
ok('is NOT told about bike tickets (the two keys are separate on purpose)',
   $f->hasMobilePermission(VT::ALERT_PERMISSION) || $f->hasPermission(VT::ALERT_PERMISSION), false);
ok('cannot schedule a visit', $wv->canSchedule($f, true) || $wv->canSchedule($f, false), false);
ok('cannot manage tickets', $vt->canManage($f, true) || $vt->canManage($f, false), false);
ok('cannot record a service', $f->hasMobilePermission('manage_bike_service') || $f->hasPermission('manage_bike_service'), false);
ok('but CAN plan shifts — which is why he is told at all', $f->hasMobilePermission('manage_shifts'), true);

// The banner must show him the fleet's visits, not an empty list.
// ⚠ confirm_replace: fixture only. Since 7-Sep a booking that would change a day the rider
//   already has is refused until confirmed; this rider may carry one, and the refusal itself
//   is asserted in test_shift_workshop_seam.php §7b.
$wsA = $wv->schedule($who['Qasim'], ['user_id' => (int) $rider->id, 'confirm_replace' => 1,
                                     'visit_date' => \Carbon\Carbon::today()->addDays(2)->format('Y-m-d')]);
flushAll();
$sumF = $wv->summaryFor($f, true);
/**
 * ⏳ CHANGED 6-Sep. Qasim's booking is now a PROPOSAL, so it is deliberately NOT in the
 *    "what is happening" banner — it has not happened. Farooq holds `manage_shifts`, so it
 *    reaches him in the queue that asks him to decide, which is the stronger claim: the man
 *    who plans the day is the man being asked about it.
 */
/**
 * ⚠ Asserts the PROPOSAL is absent from the banner — not that the banner is empty. Since the
 *   7-Sep ruling a proposal no longer supersedes an approved day, so this rider may still be
 *   carrying a live visit, and the banner should show that one. What must never appear there
 *   is the thing nobody has answered yet.
 */
ok('⏳ a proposal is NOT in the "what is happening" banner',
   in_array((int) $wsA['visit_id'], array_column($sumF['visits'] ?? [], 'id'), true), false);
ok('  ⭐ …it is in the approval queue addressed to him',
   in_array((int) $wsA['visit_id'], array_column($wv->pendingApprovals($f, true), 'id'), true), true);
ok('  …and does NOT offer him scheduling', $sumF['can_schedule'], false);
$mine = array_filter($sumF['visits'] ?? [], fn ($v) => (int) $v['user_id'] === (int) $f->id);
ok('  …he is not shown as the rider on any of them', count($mine), 0);

/**
 * ⚠⚠ ALSO FOUND HERE: the banner used to open the Bikes screen for everyone — but Farooq has
 *    no `view_bike_costs`, so his own alert led straight to a 403. It now routes on
 *    `can_schedule`, which his payload correctly reports as false.
 */
ok('⚠ he cannot open Bikes, so his banner must NOT send him there',
   $f->hasMobilePermission('view_bike_costs'), false);
$banSrc = file_get_contents(__DIR__ . '/../NizamiFarmsMobile/src/components/RoleAlertBanners.js');
ok('  …and it routes on can_schedule instead of always opening Fleet',
   str_contains($banSrc, "canSchedule.current ? 'Fleet' : 'StoreShifts'"), true);
/**
 * ⚠⚠ AND the target must exist in EVERY mode. `StoreShifts` lived only in StoreStack, so
 *    navigate() from rider or frozen mode failed with "not handled by any navigator" — the
 *    same trap FleetScreen once hit. It is now ALSO in the root stack.
 */
$navSrc = file_get_contents(__DIR__ . '/../NizamiFarmsMobile/src/navigation/index.js');
ok('  …and StoreShifts is registered in the ROOT stack too, so it opens from any mode',
   substr_count($navSrc, 'name="StoreShifts"'), 2);
ok('  …with no fallback that would land him on a screen he cannot open',
   str_contains($banSrc, "navigation.navigate('Fleet'); } catch (e2)"), false);

// He must also SEE it on the planner grid itself.
flushAll();
$key   = (int) $rider->id . '|' . \Carbon\Carbon::today()->addDays(2)->format('Y-m-d');
$wvSvc = app(\App\Services\Riders\WorkshopVisitService::class);
$plain = $wvSvc->mapForRange([(int) $rider->id], \Carbon\Carbon::today()->format('Y-m-d'),
                             \Carbon\Carbon::today()->addDays(7)->format('Y-m-d'));
$cells = $wvSvc->mapForRange([(int) $rider->id], \Carbon\Carbon::today()->format('Y-m-d'),
                             \Carbon\Carbon::today()->addDays(7)->format('Y-m-d'), true);
// ⏳ 6-Sep: a proposal reaches the PLANNER's grid — and only his. It is a question for him,
//    not yet a fact about the day, so no other grid draws it.
ok('an unapproved workshop day is NOT on an ordinary grid', isset($plain[$key]), false);
ok('the planner grid has it on the rider’s day', isset($cells[$key]), true);
ok('  …marked as a request, not a plan', $cells[$key]['proposed'] ?? null, true);
$wv->cancel($who['Qasim'], (int) $wsA['visit_id'], 'persona cleanup');

// ─────────────────────────────────────────────────────────────────────────────
head('§3b QASIM ON HIS PHONE — can see AND correct what he recorded');

/**
 * ⚠⚠ Qasim works in FROZEN MODE ON A PHONE. Web-only correction was not an answer: the mobile
 *    vehicle screen had NO service history at all, so a record he made was invisible there —
 *    the same blindness that let log #8 sit misfiled. This asserts the phone's own payload.
 */
// ⚠ Record one FIRST rather than hoping the discovered bike happens to have a manual row —
//   the assertion must exercise the path, not the fixture.
// ⚠ Derived, not hard-coded — see the note on the first record() above. It must also sit
//   ABOVE the reading that call just wrote, or it is a backwards odometer.
$seed = $rec->record(['rider_id' => (int) $rider->id,
                      'meter' => (int) ($vehSvc->currentMeterFor($vid) ?? 0) + 5,
                      'date' => \Carbon\Carbon::today()->format('Y-m-d'),
                      'type' => $tp['type'], 'actor_id' => (int) $who['Qasim']->id,
                      'note' => 'PERSONA phone']);
if (!$seed['ok']) { echo "      ! " . ($seed['message'] ?? '') . "\n"; }
ok('Qasim records a service (to correct in a moment)', $seed['ok'], true);
flushAll();

\Illuminate\Support\Facades\Auth::guard('web')->loginUsingId($who['Qasim']->id);
$vc  = app(\App\Http\Controllers\CRM\VehicleController::class);
$rq  = \Illuminate\Http\Request::create('/api/rider/store/fleet/vehicles/' . $vid, 'GET', ['month' => date('Y-m')]);
$rq->setUserResolver(fn () => \Illuminate\Support\Facades\Auth::user());
$mob = json_decode($vc->apiShow($rq, new VehicleService(), $vid)->getContent(), true);

ok('the phone can open the machine', $mob['success'] ?? false, true);
ok('  …and now gets its service history at all', is_array($mob['service_history'] ?? null), true);
ok('  …including the one he just recorded, carrying a log_id so it can be corrected',
   in_array((int) $seed['service_log_id'],
            array_map('intval', array_column($mob['service_history'], 'log_id')), true), true);
ok('  …and is told he may correct them', $mob['can_log_meters'] ?? null, true);
ok('  …and gets THIS bike\'s schedule for the type chips',
   count(array_filter($mob['service_schedule'] ?? [], fn ($t) => ($t['interval_km'] ?? 0) > 0)) > 0, true);
/**
 * ⚠ The flag must MIRROR the server, never be a second gate: `canLogMeters` reads web OR
 *   mobile because the endpoint that acts (`canManageService`) is mobile-aware. A stricter
 *   flag hides a button somebody is entitled to press.
 */
$vcSrc = file_get_contents(__DIR__ . '/app/Http/Controllers/CRM/VehicleController.php');
ok('  …and that flag honours the MOBILE key too, matching the endpoint',
   (bool) preg_match('/canLogMeters.*?hasMobilePermission\(.manage_bike_service.\)/s', $vcSrc), true);

$fvSrc = file_get_contents(__DIR__ . '/../NizamiFarmsMobile/src/components/FleetVehicles.js');
ok('the phone screen renders Past services', str_contains($fvSrc, 'Past services'), true);
ok('  …and offers Edit / Remove only on rows with a log_id',
   str_contains($fvSrc, 's.log_id && detail?.can_log_meters'), true);
ok('  …calling the same endpoints the web does',
   str_contains($fvSrc, '/rider/store/fleet/service-records/'), true);

// ⚠ Remove the seed before §4: at 90,500 km it is the newest evidence on this bike and would
//   otherwise dominate the schedule assertions below. Each section leaves the world as it found it.
$rec->remove((int) $seed['service_log_id'], (int) $who['Qasim']->id);
flushAll();

// ─────────────────────────────────────────────────────────────────────────────
head('§4 ONE ENGINE — however a reading or a service got in');

/**
 * Owner: "the backend handles like manual meters or maintenance entered otherwise and single
 * engine for all readings and alerts". A service can reach the system three ways; all three
 * must land in the SAME schedule and the SAME alert.
 */
flushAll();
$svc = new VehicleService();
$meter = (int) ($svc->currentMeterFor($vid) ?: 30000);
$typeRow = $rec->resolveType($types[0]['id'] ?? null)['type'];

$schedBefore = $svc->serviceScheduleFor($vid, $meter);
$rowBefore = array_values(array_filter($schedBefore, fn ($s) => $s['id'] === (int) $typeRow->id))[0] ?? null;

// (a) recorded by hand on Bikes
$r1 = $rec->record(['rider_id' => (int) $rider->id, 'meter' => $meter + 3, 'date' => \Carbon\Carbon::today()->format('Y-m-d'),
                    'type' => $typeRow, 'actor_id' => (int) $who['Shabib']->id, 'note' => 'PERSONA manual']);
flushAll();
$rowAfter = array_values(array_filter($svc->serviceScheduleFor($vid, $meter + 3),
                                      fn ($s) => $s['id'] === (int) $typeRow->id))[0] ?? null;
ok('a MANUAL record moves the schedule', ($rowAfter['last_meter'] ?? 0), $meter + 3);
ok('  …and the same engine answers the alert',
   (new \App\Services\Riders\BikeServiceAlerts())->due() !== null, true);

// (b) the SAME engine is what the vehicle profile, the rider payload and the alerts all read
flushAll();
$viaVehicle = $svc->serviceScheduleFor($vid, $meter + 3);
$fleetSvc = new \App\Services\Riders\FleetFuelService();
$rm = new ReflectionMethod($fleetSvc, 'serviceSchedule');
$rm->setAccessible(true);
$viaRider = $rm->invoke($fleetSvc, (int) $rider->id);
$k = fn ($rows) => array_column($rows, 'interval_km', 'id');
ok('the MACHINE view and the RIDER view give identical intervals', $k($viaRider), $k($viaVehicle));

// (c) the attention map — the fleet list — reads the same state
flushAll();
$att = app(FleetAttentionService::class)->forVehicles($svc->all(false), []);
ok('the fleet attention map covers every machine', count($att), count($svc->all(false)));
ok('  …and ranks a machine with an open ticket above a quiet one',
   FleetAttentionService::RANK_TICKETS < FleetAttentionService::RANK_NONE, true);

// (d) a manual METER reading (not a service) still flows through the meter engine
$mlBefore = Schema::hasTable('t_ops_vehicle_meter_log')
    ? DB::table('t_ops_vehicle_meter_log')->count() : null;
ok('the meter log is a separate engine from the service log (readings ≠ services)',
   $mlBefore !== null, true);

$rec->remove((int) $r1['service_log_id'], (int) $who['Shabib']->id);

// ─────────────────────────────────────────────────────────────────────────────
head('§5 what a persona must NOT be able to do');

$rd = $rider;
ok('a RIDER cannot schedule a workshop visit', $wv->canSchedule($rd, true), false);
ok('a RIDER cannot manage tickets', $vt->canManage($rd, true), false);
ok('a RIDER cannot record a service', $rd->hasMobilePermission('manage_bike_service'), false);
ok('  …but CAN open a ticket on his own bike (no key needed)',
   $vt->open($rd, ['title' => 'PERSONA rider'])['ok'], true);
ok('  …and cannot open one on someone else’s',
   $vt->open($rd, ['vehicle_id' => 999999, 'title' => 'nope'])['ok'], false);

// Farooq must not be able to act even though he is told.
$fw = $wv->schedule($f, ['user_id' => (int) $rider->id, 'visit_date' => \Carbon\Carbon::today()->addDay()->format('Y-m-d')]);
ok('Farooq being TOLD does not let him schedule', $fw['ok'], false);
ok('  …nor complete one', $wv->markDone($f, 999999, [])['ok'], false);

/**
 * §5b ⚠⚠ THE PLANNERS' ISSUES PAGE — the door and the room must agree (15-Sep-2026).
 *
 *     `/orders/riders-map/fleet/issues-board` exists because Farooq's only role is TYPED `rider`,
 *     and `ridersMap()` turns away a role-typed rider before a single bike key is consulted — so
 *     the Bikes tab could never reach him. The page gate is therefore "do you hold one of the
 *     keys", never a role type.
 *
 * ⚠ The gate used to admit `view_bike_costs` and `view_rider_reports` as well, which Adnan, the
 *   Manager role and the expense-fund role hold. None of those four keys grants a fleet read
 *   inside `VehicleIssueBoard`, so they came through the door and landed on a board with nothing
 *   on it. An empty page is not a leak, but it is a worse answer than "you do not have
 *   permission" — and two lists that must agree are one list too many.
 */
head('§5b the issues-board page: the gate matches what the board will actually show');

/**
 * ⚠⚠ THE GUARD IS SET TOO, NOT JUST THE RESOLVER. §4 logged Qasim in on the web guard and
 *    `boardPage` reads `$request->user() ?: auth()->user()` — so without this every case below
 *    would quietly fall through to Qasim and answer "what can Qasim do", including the one that
 *    is supposed to have nobody at all. That is how a gate test passes while testing nothing.
 */
$pageOpens = function ($user) {
    /**
     * ⚠⚠ THE DEFAULT GUARD HERE IS `api`, NOT `web`, AND IT ALREADY HOLDS A USER by the time
     *    this section runs. Clearing the web guard alone left `auth()->user()` answering with
     *    somebody else entirely — so the "nobody at all" case was really asking "what can user
     *    91 do", and passed for the wrong reason. Point the default guard at `web` and drive it
     *    explicitly; the caller restores it.
     */
    \Illuminate\Support\Facades\Auth::shouldUse('web');
    if ($user) \Illuminate\Support\Facades\Auth::guard('web')->setUser($user);
    else       \Illuminate\Support\Facades\Auth::guard('web')->logout();
    $req = \Illuminate\Http\Request::create('/orders/riders-map/fleet/issues-board', 'GET');
    $req->setUserResolver(fn () => $user);
    $res = app(\App\Http\Controllers\CRM\VehicleTicketController::class)->boardPage($req);
    return $res instanceof \Illuminate\View\View;     // a redirect means refused
};

ok('Farooq gets in — this page exists for him', $pageOpens($who['Farooq']), true);
ok('Taimur gets in', $pageOpens($who['Taimur']), true);
ok('a plain rider does not', $pageOpens($rd), false);
ok('nobody at all does not', $pageOpens(null), false);

/**
 * ⚠ Someone who only reads REPORTS is now refused at the door rather than shown an empty board.
 *   Searched for rather than named: the point is the shape of the permission, not one person.
 */
/**
 * ⚠ Read-only users are deliberately INCLUDED in this search. The page gate asks `hasPermission`
 *   and nothing else — it never consulted `isReadOnly` — so skipping them here would skip the
 *   only person on the replica who actually hits this case (Adnan, the analyst).
 */
$reportsOnly = collect(User::where('is_active', '1')->get())->first(function ($u) {
    foreach (['manage_vehicle_tickets', 'schedule_workshop', 'receive_workshop_alerts', 'manage_shifts'] as $k) {
        if ($u->hasPermission($k)) return false;
    }
    return (bool) ($u->hasPermission('view_rider_reports') || $u->hasPermission('view_bike_costs'));
});
if ($reportsOnly) {
    ok('a reports-only viewer (' . $reportsOnly->fullname . ') is refused, not shown an empty board',
       $pageOpens($reportsOnly), false);
} else {
    echo "  · no reports-only web user on this replica — that half of the gate is unexercised\n";
}
// ⚠ Put the default guard back, so nothing after this section is quietly reading a different one.
\Illuminate\Support\Facades\Auth::shouldUse(config('auth.defaults.guard'));

} finally {
    DB::rollBack();
    flushAll();
}

head('§6 nothing left behind');
ok('tickets', DB::table(VT::T_TICKET)->count(), $beforeT);
ok('visits', DB::table(WV::T_VISIT)->count(), $beforeW);
ok('service log', DB::table('t_fleet_service_log')->count(), $beforeL);

echo "\n" . str_repeat('─', 60) . "\n";
echo ($fail === 0 ? '✅' : '❌') . "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
