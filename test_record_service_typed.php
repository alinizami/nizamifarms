<?php
/**
 * RECORD SERVICE — THE TYPE IS REQUIRED, NOTHING IS GUESSED (Sep-2 2026).
 *
 * The bug: mobile "Record service" (the SAME FleetScreen in Store and Frozen mode) asked
 * only for the odometer and posted no `maintenance_type_id`. The server then filed the
 * work against "the shortest `resets_service_clock` type" — which since 22-Aug is
 * Oil + Tuning (2,000 km), NOT Oil Change (1,000, resets=0). Live proof:
 * `t_fleet_service_log` #8, Qasim on Arslan Aslam's bike, 1-Sep, 36,387 km.
 *
 * Owner ruling: REJECT an untyped meter — no guessing.
 *
 * What these prove:
 *   §1 an untyped meter is refused while scheduled types exist, and writes NOTHING;
 *   §2 a typed meter lands on THAT type — never a guess;
 *   §3 which clock moves is still decided by the type, not by the caller;
 *   §4 the schedule-only path (`interval_km`) is untouched and still needs no type;
 *   §5 the date is honoured, bounded, and reported back;
 *   §6 the guessing fallback is GONE from the source;
 *   §7 the filing pickers can now read this bike's EFFECTIVE schedule (forUser).
 *
 * ⚠ Every mutation is inside a transaction that is always rolled back.
 * ⚠ ONE user is authenticated for the whole process — see [[replica-scenario-probes-aug27]].
 *
 * Run:  php test_record_service_typed.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\CRM\FleetFuelController;
use App\Http\Controllers\CRM\VehicleController;
use App\Services\Riders\ServiceIntervalResolver;
use App\Services\Riders\VehicleResolver;
use App\Services\Riders\VehicleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
    ServiceIntervalResolver::flush();
    VehicleService::flushServiceMemo();
    VehicleResolver::flush();
    try { Cache::flush(); } catch (\Throwable $e) {}
}

/** Call markServiced and get back [httpStatus, decodedBody]. */
function post(array $payload): array {
    $req = Request::create('/orders/riders-map/fleet/mark-serviced', 'POST', $payload);
    try {
        $res = app(FleetFuelController::class)->markServiced($req);
        return [$res->getStatusCode(), json_decode($res->getContent(), true)];
    } catch (ValidationException $e) {
        return [422, ['success' => false, 'message' => $e->validator->errors()->first(), 'validation' => true]];
    }
}

// ─── fixtures: DISCOVERED, never hard-coded (the fixture-drift lesson) ────────
head('§0 fixtures');

$types = DB::table('t_fleet_maintenance_types')->where('is_active', 1)->get();
$resetting = $types->first(fn ($t) => $t->resets_service_clock && (int) $t->interval_km > 0);
$nonResetting = $types->first(fn ($t) => !$t->resets_service_clock && (int) $t->interval_km > 0);
$asConditions = $types->first(fn ($t) => (int) $t->interval_km <= 0);
ok('a clock-resetting scheduled type exists', (bool) $resetting, null, true);
ok('a scheduled type that does NOT reset the clock exists', (bool) $nonResetting, null, true);
ok('an "as conditions" type exists', (bool) $asConditions, null, true);

// The manager: whoever actually holds the WEB key, discovered not named.
$actor = null;
foreach (\App\Models\User::where('is_active', '1')->get() as $u) {
    if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) continue;
    if ($u->hasPermission('manage_bike_service')) { $actor = $u; break; }
}
ok('a user holding web manage_bike_service exists', (bool) $actor, null, true);
if (!$actor || !$resetting || !$nonResetting) { echo "\nfixtures missing — stopping.\n"; exit(1); }
Auth::guard('web')->loginUsingId($actor->id);   // ⚠ the ONLY login in this process

/**
 * A rider with a profile AND a machine the registry can name, so the schedule is real.
 *
 * ⚠ Sep-2026: it must be a COMPANY machine. Personal bikes are no longer on the
 *   company schedule at all (owner ruling, 10-Sep), so the first rider in id order
 *   could be holding a machine with — correctly — no schedule to test against, and
 *   this whole section would fail for the right reason at the wrong place.
 */
$rider = null; $vid = null;
$vr = new VehicleResolver();
$vsvc0 = new VehicleService();
foreach (DB::table('t_ops_rider_profile')->pluck('user_id') as $uid) {
    $v = $vr->currentVehicleFor((int) $uid);
    if ($v && $vsvc0->isTrackedId((int) $v)) { $rider = (int) $uid; $vid = (int) $v; break; }
}
ok('a rider with a registered COMPANY machine exists', (bool) $rider, null, true);
if (!$rider) { echo "\nno registry fixture — stopping.\n"; exit(1); }
$riderClass = $vsvc0->classOf($vid);

$svc = new VehicleService();
$baseMeter = (int) ($svc->currentMeterFor($vid) ?: 30000);
echo "  · actor={$actor->id} rider={$rider} vehicle={$vid} meter={$baseMeter}\n";
echo "  · resetting={$resetting->type_name}({$resetting->interval_km})"
   . " non-resetting={$nonResetting->type_name}({$nonResetting->interval_km})\n";

$profileBefore = DB::table('t_ops_rider_profile')->where('user_id', $rider)
    ->first(['last_service_meter', 'last_service_at', 'service_interval_km']);
$logCountBefore = DB::table('t_fleet_service_log')->count();
// ⚠ The high-water mark, so §12/§13 can remove exactly the rows they meant to write.
$maxLogIdBefore = (int) (DB::table('t_fleet_service_log')->max('id') ?: 0);

DB::beginTransaction();
try {

// ─────────────────────────────────────────────────────────────────────────────
head('§1 an untyped meter is REFUSED — and writes nothing');

flushAll();
[$st, $body] = post(['rider_id' => $rider, 'meter' => $baseMeter + 10]);
ok('rejected with 422', $st, 422);
ok('  …and says which question is unanswered',
   (bool) preg_match('/which service/i', $body['message'] ?? ''), null, true);
ok('  …and NO service-log row was written',
   DB::table('t_fleet_service_log')->count(), $logCountBefore);
$p = DB::table('t_ops_rider_profile')->where('user_id', $rider)->first(['last_service_meter']);
ok('  …and the profile stamp is untouched',
   (int) ($p->last_service_meter ?? 0), (int) ($profileBefore->last_service_meter ?? 0));

// The old behaviour, asserted as GONE: before this change the same payload succeeded
// and silently created a row against the shortest clock-resetting type.
ok('  …so the guess that misfiled log #8 can no longer happen', $body['success'] ?? null, false);

// ─────────────────────────────────────────────────────────────────────────────
head('§2 a TYPED meter lands on that exact type');

flushAll();
$m2 = $baseMeter + 20;
[$st, $body] = post(['rider_id' => $rider, 'meter' => $m2, 'maintenance_type_id' => $resetting->id]);
ok('accepted', $st, 200);
$row = DB::table('t_fleet_service_log')->orderByDesc('id')->first();
ok('one log row written', DB::table('t_fleet_service_log')->count(), $logCountBefore + 1);
ok('  …against the type that was CHOSEN', (int) $row->maintenance_type_id, (int) $resetting->id);
ok('  …at the meter given', (int) $row->meter, $m2);
ok('  …attributed to the rider', (int) $row->user_id, $rider);
ok('  …recorded by the signed-in manager', (int) $row->created_by, (int) $actor->id);
ok('  …and the note no longer claims a type was assumed',
   (bool) preg_match('/no service type given/i', (string) $row->note), false);
// ⚠ str_contains, not strpos — the job name is at position 0, and `(bool) 0` is false.
ok('the receipt names the job', str_contains($body['message'] ?? '', $resetting->type_name), null, true);
ok('  …and states the next due',
   str_contains($body['message'] ?? '', number_format($m2 + (int) $resetting->interval_km)), null, true);

// ─────────────────────────────────────────────────────────────────────────────
head('§3 WHICH clock moves is still the type\'s decision, not the caller\'s');

flushAll();
$m3 = $baseMeter + 30;
[$st, $body] = post(['rider_id' => $rider, 'meter' => $m3, 'maintenance_type_id' => $nonResetting->id]);
ok('a non-clock-resetting job is accepted', $st, 200);
$p = DB::table('t_ops_rider_profile')->where('user_id', $rider)->first(['last_service_meter']);
ok('  …but does NOT move the overall clock', (int) $p->last_service_meter, $m2);
/* ⭐ 29-Sep-2026 (owner): there is no second "overall clock" to report on any more — every job
     resets its OWN countdown and the bike's chip is simply the most urgent of those. So the
     receipt names this job's own next due and never mentions an overall clock. */
ok('  …and its receipt states the job\'s OWN next due, with no "overall clock" sentence',
   str_contains($body['message'] ?? '', 'next due') && !preg_match('/overall/i', $body['message'] ?? ''), null, true);
ok('  …while still writing its own log row',
   (int) DB::table('t_fleet_service_log')->orderByDesc('id')->first()->maintenance_type_id,
   (int) $nonResetting->id);

if ($asConditions) {
    flushAll();
    [$st, $body] = post(['rider_id' => $rider, 'meter' => $baseMeter + 40,
                         'maintenance_type_id' => $asConditions->id]);
    ok('an "as conditions" job is still refused (nothing to count down)', $st, 422);
    ok('  …and points at the maintenance request instead',
       (bool) preg_match('/maintenance request/i', $body['message'] ?? ''), null, true);
}

flushAll();
[$st, $body] = post(['rider_id' => $rider, 'meter' => $baseMeter + 50, 'maintenance_type_id' => 999999]);
ok('an unknown type id is refused', $st, 422);

// ─────────────────────────────────────────────────────────────────────────────
head('§4 the SCHEDULE-only path is RETIRED (Sep-2026)');

/**
 * ⚠⚠ THIS SECTION IS THE REVERSE OF WHAT IT WAS, DELIBERATELY.
 *
 * `interval_km` on this endpoint was the "⚙️ This bike's schedule" control: ONE number
 * per machine that named no job. It could not work — since the Aug-27 resolver the job's
 * own standard always wins, so the number changed nothing on any screen — and the owner's
 * 10-Sep ruling replaces it with a real per-(vehicle, job) editor.
 *
 * An old APK still posts the field, so the rules are:
 *   • alone            → REFUSED, with a sentence telling the manager where the setting
 *                        moved. Accepting and ignoring it is how somebody comes to
 *                        believe he has configured something he has not.
 *   • beside a meter   → the service is recorded and the stale field is dropped. A
 *                        service happening must never be turned away over an extra.
 *   • the legacy column is NOT cleared — it stays readable as a last-resort fallback.
 */
flushAll();
$logNow  = DB::table('t_fleet_service_log')->count();
$ovrWas  = DB::table('t_ops_rider_profile')->where('user_id', $rider)->value('service_interval_km');
[$st, $body] = post(['rider_id' => $rider, 'interval_km' => 1500]);
ok('the single-number schedule is refused on its own', $st, 422);
ok('  …and says where the setting moved to',
   (bool) preg_match('/vehicle page|This vehicle\'s schedule/i', $body['message'] ?? ''), null, true);
ok('  …records NO service', DB::table('t_fleet_service_log')->count(), $logNow);
ok('  …and writes no override',
   DB::table('t_ops_rider_profile')->where('user_id', $rider)->value('service_interval_km'), $ovrWas);

flushAll();
$m4 = $baseMeter + 40;
[$st, $body] = post(['rider_id' => $rider, 'meter' => $m4,
                     'maintenance_type_id' => $resetting->id, 'interval_km' => 1500]);
ok('a real recording carrying the stale field still succeeds', $st, 200);
ok('  …and still writes no override',
   DB::table('t_ops_rider_profile')->where('user_id', $rider)->value('service_interval_km'), $ovrWas);
ok('  …and says nothing about a schedule change',
   (bool) preg_match('/due every/i', $body['message'] ?? ''), false);

flushAll();
[$st, $body] = post(['rider_id' => $rider]);
ok('neither a meter nor a schedule is still refused', $st, 422);

// ─────────────────────────────────────────────────────────────────────────────
head('§5 the service DATE');

flushAll();
$yesterday = \Carbon\Carbon::today()->subDay()->format('Y-m-d');
$m5 = $baseMeter + 60;
[$st, $body] = post(['rider_id' => $rider, 'meter' => $m5,
                     'maintenance_type_id' => $resetting->id, 'date' => $yesterday]);
ok('a backdated service is accepted', $st, 200);
ok('  …and the log row carries THAT day',
   substr((string) DB::table('t_fleet_service_log')->orderByDesc('id')->first()->service_date, 0, 10),
   $yesterday);
ok('  …and the receipt says the date, so it cannot read as "today"',
   (bool) preg_match('/ on /', $body['message'] ?? ''), null, true);

flushAll();
$tomorrow = \Carbon\Carbon::today()->addDay()->format('Y-m-d');
[$st, $body] = post(['rider_id' => $rider, 'meter' => $baseMeter + 70,
                     'maintenance_type_id' => $resetting->id, 'date' => $tomorrow]);
ok('a FUTURE service date is refused', $st, 422);
ok('  …by validation, before anything is written', $body['validation'] ?? false, true);

flushAll();
[$st, $body] = post(['rider_id' => $rider, 'meter' => $baseMeter + 80,
                     'maintenance_type_id' => $resetting->id]);
ok('no date still means today', substr((string) DB::table('t_fleet_service_log')
    ->orderByDesc('id')->first()->service_date, 0, 10), \Carbon\Carbon::today()->format('Y-m-d'));
ok('  …and the receipt then says no date at all',
   (bool) preg_match('/ on /', $body['message'] ?? ''), false);

// ─────────────────────────────────────────────────────────────────────────────
head('§7 the filing pickers can read THIS bike\'s effective schedule');

flushAll();
$vreq = Request::create('/orders/riders-map/fleet/vehicles/for-user', 'GET',
                        ['user_id' => $rider, 'date' => \Carbon\Carbon::today()->format('Y-m-d')]);
$vres = json_decode(app(VehicleController::class)->forUser($vreq, new VehicleResolver(), new VehicleService())->getContent(), true);
ok('forUser names the machine', (int) ($vres['vehicle']['id'] ?? 0), $vid);
ok('  …and now ships service_schedule', is_array($vres['service_schedule'] ?? null), true);
/**
 * ⚠ Counted against the jobs scheduled FOR THIS MACHINE'S CLASS (Sep-2026), not the
 *   whole type list — a van and a bike no longer have the same number of countdowns.
 */
$classTypes = app(\App\Services\Riders\MaintenanceTypeService::class)->optionsFor($riderClass);
ok('  …with a row per scheduled job',
   count($vres['service_schedule'] ?? []),
   count(array_filter($classTypes, fn ($t) => !empty($t['has_schedule'])
                                              || (int) ($t['interval_km'] ?? 0) > 0)));

$direct = (new VehicleService())->serviceScheduleFor($vid, (new VehicleService())->currentMeterFor($vid));
ok('  …identical to what the engine itself answers',
   array_column($vres['service_schedule'] ?? [], 'interval_km', 'id'),
   array_column($direct, 'interval_km', 'id'));
ok('  …and every row carries what a picker label needs',
   (bool) array_diff(['id', 'name', 'interval_km'], array_keys(($vres['service_schedule'] ?? [[]])[0])), false);

// ─────────────────────────────────────────────────────────────────────────────
head('§9 the two schedule branches have ONE shape');

/**
 * A rider the registry can name gets the MACHINE-keyed schedule; one it cannot gets
 * the rider-keyed reconstruction. Both feed the very same prompt and pickers, so a
 * key present in one and missing from the other is a silent wrong answer — which is
 * exactly what `resets_clock` was: absent from the rider-keyed branch, so the
 * Record-service prompt told an unregistered rider that an oil job leaves the bike's
 * overall service untouched.
 */
flushAll();
$machineRows = (new VehicleService())->serviceScheduleFor($vid, (new VehicleService())->currentMeterFor($vid));
$rm = new ReflectionMethod(\App\Services\Riders\FleetFuelService::class, 'serviceScheduleByRider');
$rm->setAccessible(true);
$riderRows = $rm->invoke(new \App\Services\Riders\FleetFuelService(), $rider);

ok('both branches return rows', (bool) (count($machineRows) && count($riderRows)), null, true);
if ($machineRows && $riderRows) {
    /**
     * The contract is not "identical keys" — it is "every key a shared consumer makes a
     * CLAIM from". `last_by` / `covered_by` / `assumed` are optional suffixes
     * (`x ? ' · ' + x : ''`) that the rider-keyed reconstruction genuinely has no
     * evidence for; absent, they simply do not render. `resets_clock` is different in
     * kind: the prompt asserts one of two opposite sentences from it, so absent meant
     * stating the opposite of the truth.
     */
    $CLAIMED = ['id', 'name', 'interval_km', 'resets_clock',
                'interval_overridden', 'interval_source_label', 'due_in_km', 'state'];
    $missing = array_values(array_diff($CLAIMED, array_keys($riderRows[0])));
    ok('the rider-keyed branch carries every key a consumer states a fact from', $missing, []);
    ok('  …and the machine-keyed branch carries them too',
       array_values(array_diff($CLAIMED, array_keys($machineRows[0]))), []);
    // Documented, deliberate difference — evidence the reconstruction cannot have.
    ok('  …the only extras are the optional evidence suffixes',
       array_values(array_diff(array_keys($machineRows[0]), array_keys($riderRows[0]))),
       ['last_by', 'assumed', 'covered_by']);
    // The value must be right, not merely present.
    $byId = [];
    foreach ($riderRows as $r) { $byId[$r['id']] = $r; }
    ok('  …and it agrees with the type table for the clock-resetting job',
       $byId[$resetting->id]['resets_clock'] ?? null, true);
    ok('  …and for the one that does not reset it',
       $byId[$nonResetting->id]['resets_clock'] ?? null, false);
}

// ─────────────────────────────────────────────────────────────────────────────
head('§10 CORRECTING a service record (owner ask, 3-Sep)');

/**
 * "Make sure Qasim or Shabib or Taimur can modify these service dates later on."
 * These rows were INSERT-ONLY, so a wrong one could be fixed only by hand-written SQL —
 * exactly the situation log #8 left us in. And they appeared on NO screen, so a wrong one
 * could not even be SEEN. Both halves are covered here.
 */
$rec = app(\App\Services\Riders\ServiceRecordService::class);
flushAll();
$mFix = $baseMeter + 200;
[$st, $body] = post(['rider_id' => $rider, 'meter' => $mFix, 'maintenance_type_id' => $resetting->id]);
ok('a record to correct exists', $st, 200);
$logRow = DB::table('t_fleet_service_log')->orderByDesc('id')->first();
$logId  = (int) $logRow->id;

// ⭐ IT MUST BE VISIBLE — the half that made #8 invisible.
flushAll();
$vidFix = (new VehicleResolver())->currentVehicleFor($rider);
$hist = (new VehicleService())->serviceHistoryFor((int) $vidFix);
$mine = array_values(array_filter($hist, fn ($h) => ($h['log_id'] ?? null) === $logId));
ok('the manual record now APPEARS in the vehicle history', count($mine), 1);
ok('  …flagged as recorded by hand, with no bill', [$mine[0]['manual'] ?? null, $mine[0]['amount']], [true, 0.0]);
ok('  …and carries the log_id that makes it correctable', $mine[0]['log_id'], $logId);
ok('a CLAIM in the same list is NOT correctable here (money lives in the claims flow)',
   array_values(array_unique(array_map(fn ($h) => $h['log_id'] === null,
       array_filter($hist, fn ($h) => empty($h['manual']))))) ?: [true], [true]);

// --- the wrong TYPE, which is precisely the log-8 case ---
flushAll();
$r = $rec->amend($logId, ['maintenance_type_id' => $nonResetting->id], (int) $actor->id);
ok('the TYPE can be corrected', $r['ok'], true);
$row = DB::table('t_fleet_service_log')->where('id', $logId)->first();
ok('  …the row now names the right job', (int) $row->maintenance_type_id, (int) $nonResetting->id);
ok('  …and the note records that it was corrected, and by whom',
   (bool) preg_match('/corrected .* by /i', (string) $row->note), null, true);

/**
 * ⭐⭐ THE STAMP IS REBUILT, NOT PATCHED. The record was clock-resetting and is now not, so
 *    the profile stamp must fall back to older evidence — patching it would leave the bike
 *    claiming a service it no longer has.
 */
flushAll();
$p = DB::table('t_ops_rider_profile')->where('user_id', $rider)->first(['last_service_meter']);
ok('the profile stamp no longer claims the corrected meter',
   (int) ($p->last_service_meter ?? 0) === $mFix, false);

// --- meter and date ---
flushAll();
$r = $rec->amend($logId, ['meter' => $mFix + 5, 'date' => \Carbon\Carbon::today()->subDay()->format('Y-m-d')], (int) $actor->id);
ok('the METER and DATE can be corrected', $r['ok'], true);
$row = DB::table('t_fleet_service_log')->where('id', $logId)->first();
ok('  …both are stored', [(int) $row->meter, substr((string) $row->service_date, 0, 10)],
   [$mFix + 5, \Carbon\Carbon::today()->subDay()->format('Y-m-d')]);

ok('a FUTURE date is refused', $rec->amend($logId, ['date' => \Carbon\Carbon::today()->addDay()->format('Y-m-d')], (int) $actor->id)['ok'], false);
ok('a malformed date is refused', $rec->amend($logId, ['date' => 'soon'], (int) $actor->id)['ok'], false);
ok('an "as conditions" type is refused, same as when recording',
   $asConditions ? $rec->amend($logId, ['maintenance_type_id' => $asConditions->id], (int) $actor->id)['ok'] : false, false);
ok('changing nothing is refused', $rec->amend($logId, [], (int) $actor->id)['ok'], false);
ok('an unknown record is refused', $rec->amend(99999999, ['meter' => 5], (int) $actor->id)['ok'], false);

// --- removal ---
flushAll();
$before = DB::table('t_fleet_service_log')->count();
$r = $rec->remove($logId, (int) $actor->id);
ok('a record can be REMOVED', $r['ok'], true);
ok('  …and is gone', DB::table('t_fleet_service_log')->count(), $before - 1);
ok('removing it twice is refused', $rec->remove($logId, (int) $actor->id)['ok'], false);
flushAll();
ok('  …and it disappears from the history too',
   count(array_filter((new VehicleService())->serviceHistoryFor((int) $vidFix),
                      fn ($h) => ($h['log_id'] ?? null) === $logId)), 0);

// --- the permission gate is the same one as recording ---
$fcSrc2 = file_get_contents(__DIR__ . '/app/Http/Controllers/CRM/FleetFuelController.php');
ok('both endpoints are gated on canManageService, like recording is',
   substr_count($fcSrc2, 'Not authorised to change service records'), 2);

// ⭐ ONE WRITER. markServiced no longer keeps its own copy of the insert.
// ⚠ 29-Sep-2026: the ONE writer is now `recordVisit` (several jobs per visit); `record()` wraps it.
ok('markServiced writes through the shared recorder, not its own insert',
   (bool) preg_match('/(ServiceRecordService::class\)|\$svcRec)->record(Visit)?\(/', $fcSrc2), null, true);
ok('  …and no second insert into the service log is left in the controller',
   substr_count($fcSrc2, "table('t_fleet_service_log')->insert"), 0);

// ─────────────────────────────────────────────────────────────────────────────
head('§11 correcting the reading on an APPROVED claim (owner ask, 3-Sep)');
/**
 * ⚠⚠ THE HOLE THIS CLOSES. `editClaim` refuses every field once a claim is approved —
 *    "money in the ledger, reverse it and file it again". Right about money, wrong about the
 *    ODOMETER, which is not money. Live proof: AY-4771 read "Oil Change 767 km overdue" from an
 *    approved 17-Aug claim at 48,777 km, and if that figure were a typo NOBODY could reach it.
 *
 * ⭐ The line: the odometer and which job it was are OBSERVATIONS about a machine. The amount,
 *   the date and the vehicle are money. Only the observations may be corrected here — asserted
 *   in both directions below, because a leak either way is the bug.
 */
// ⚠ The type must be one the panel actually COUNTS DOWN (interval_km > 0). A repair-bucket
//   claim has no schedule row, so the "did the schedule follow?" assertion below would be
//   vacuous against it — and a vacuous assertion is worse than none.
$claim = DB::table('t_req_master as r')
    ->join('t_fleet_maintenance_types as t', 't.id', '=', 'r.maintenance_type_id')
    ->where('r.expense_category', 'Maintenance')
    ->where('r.status', 'approved')->whereNotNull('r.meter_at_fill')
    ->whereNotNull('r.vehicle_id')->where('t.interval_km', '>', 0)
    ->orderByDesc('r.id')->first(['r.*']);
ok('an approved maintenance claim exists to test with', (bool) $claim, null, true);
if ($claim) {
    $svcRec = app(\App\Services\Riders\ServiceRecordService::class);
    DB::beginTransaction();
    try {
        $wasMeter = (int) $claim->meter_at_fill;
        $r = $svcRec->correctClaim((int) $claim->id, ['meter' => $wasMeter + 123], (int) $actor->id);
        ok('an APPROVED claim\'s odometer can be corrected', $r['ok'], true);
        $now = DB::table('t_req_master')->where('id', $claim->id)->first();
        ok('  …the odometer moved', (int) $now->meter_at_fill, $wasMeter + 123);
        // ⚠ The whole point: nothing that touches money may have moved with it.
        ok('  …the AMOUNT is untouched', (string) $now->amount, (string) $claim->amount);
        ok('  …the DATE is untouched', (string) $now->expense_date, (string) $claim->expense_date);
        ok('  …the VEHICLE is untouched', (int) $now->vehicle_id, (int) $claim->vehicle_id);
        ok('  …the STATUS is still approved', $now->status, $claim->status);
        ok('  …and the correction is on the record',
           str_contains((string) $now->description, 'Service reading corrected'), true);

        // The countdown is derived, so it must follow without anything being rebuilt by hand.
        flushAll();
        $sched = (new \App\Services\Riders\VehicleService())
            ->serviceScheduleFor((int) $claim->vehicle_id,
                (new \App\Services\Riders\VehicleService())->currentMeterFor((int) $claim->vehicle_id));
        $hit = null;
        foreach ($sched as $t) if ((int) $t['id'] === (int) $claim->maintenance_type_id) $hit = $t;
        ok('  …and the schedule picked the corrected reading up',
           $hit ? (int) $hit['last_meter'] : -1, $wasMeter + 123);

        /**
         * ⚠⚠ THE FROZEN FIGURE GOES WITH THE READING IT WAS FROZEN FROM (review, 3-Sep).
         *    `service_due_km` is stamped at approval from THIS claim's odometer and printed on
         *    the claim card as "done N km overdue". Left alone after a correction it keeps
         *    quoting a number computed from the figure just declared wrong — proven: a
         *    48,777 → 48,000 correction left it at −564. Cleared, the card derives live.
         */
        if (\Illuminate\Support\Facades\Schema::hasColumn('t_req_master', 'service_due_km')) {
            ok('  …and the frozen "done N km overdue" figure is cleared with it',
               DB::table('t_req_master')->where('id', $claim->id)->value('service_due_km'), null);
        }

        ok('a zero odometer is refused',
           $svcRec->correctClaim((int) $claim->id, ['meter' => 0], (int) $actor->id)['ok'], false);
        ok('an empty correction is refused',
           $svcRec->correctClaim((int) $claim->id, [], (int) $actor->id)['ok'], false);
    } finally {
        DB::rollBack();
        flushAll();
    }
}
// ⚠ A claim that is not maintenance carries no service reading at all.
$petrol = DB::table('t_req_master')->where('expense_category', 'Petrol')->value('id');
if ($petrol) {
    ok('a PETROL claim is refused',
       app(\App\Services\Riders\ServiceRecordService::class)
           ->correctClaim((int) $petrol, ['meter' => 1], (int) $actor->id)['ok'], false);
}
// ⭐ And the money door itself must NOT have been loosened on the way past.
$ffSrc = file_get_contents(__DIR__ . '/app/Http/Controllers/CRM/FleetFuelController.php');
ok('editClaim still refuses an approved claim',
   str_contains($ffSrc, 'An approved claim has money in the ledger'), true);
ok('the new door never writes amount / expense_date / vehicle_id',
   (bool) preg_match('/function correctClaim\b.*?\n    }/s', file_get_contents(__DIR__ . '/app/Services/Riders/ServiceRecordService.php'), $m)
   && !preg_match('/[\'"](amount|expense_date|vehicle_id)[\'"]\s*=>/', $m[0]), true);

// ─────────────────────────────────────────────────────────────────────────────
head('§12 recording a service WITH its bill — one job, one row (owner ask, 3-Sep)');
/**
 * ⭐⭐ THE RULE THIS PROTECTS: ONE JOB = ONE ROW.
 *
 * ⚠⚠ Both the service log and approved maintenance CLAIMS feed the same countdown engine,
 *    and beatsEvidence() keeps the HIGHEST meter per type. So a job recorded twice would
 *    show twice in Past services, and if the two meters ever disagreed the higher one would
 *    silently win with nothing on screen saying they were the same job. An amount box that
 *    quietly filed a SEPARATE claim would have manufactured that at scale — which is why the
 *    two halves are linked by `t_fleet_service_log.request_id` and the claim half is dropped
 *    from both the history and the evidence.
 *
 * ⚠ The bill is filed through RequestController::store, NOT inserted here — so it inherits
 *   the request number, the L1/L2 auto-approval rule, the ledger posting and the vehicle
 *   stamping rather than a second copy of them that would drift.
 */
if (!Illuminate\Support\Facades\Schema::hasColumn('t_fleet_service_log', 'request_id')) {
    echo "  · link column not applied — skipping (run service_log_request_link_sep2026.sql)\n";
} else {
    DB::beginTransaction();
    try {
        flushAll();
        $claimsBefore = DB::table('t_req_master')->count();
        $logsBefore   = DB::table('t_fleet_service_log')->count();
        $m12 = $baseMeter + 640;

        [$st12, $b12] = post(['rider_id' => $rider, 'meter' => $m12,
                              'maintenance_type_id' => $resetting->id, 'amount' => 2400]);
        ok('a service WITH a bill is accepted', $st12, 200);
        ok('  …exactly ONE claim was filed', DB::table('t_req_master')->count() - $claimsBefore, 1);
        ok('  …and exactly ONE service log written',
           DB::table('t_fleet_service_log')->count() - $logsBefore, 1);

        $log12 = DB::table('t_fleet_service_log')->orderByDesc('id')->first();
        ok('  …the two are LINKED', (bool) $log12->request_id, true);
        $req12 = DB::table('t_req_master')->where('id', $log12->request_id)->first();
        ok('  …the claim carries the money', (float) $req12->amount, 2400.0);
        ok('  …and the same odometer as the service', (int) $req12->meter_at_fill, $m12);
        ok('  …and the same job', (int) $req12->maintenance_type_id, (int) $resetting->id);
        ok('  …filed as Maintenance', $req12->expense_category, 'Maintenance');
        ok('  …against the RIDER, not the manager', (int) $req12->requester_user_id, $rider);
        // ⭐ The receipt must say what happened to the money, not just to the reading.
        ok('the receipt mentions the expense',
           (bool) preg_match('/expense/i', $b12['message'] ?? ''), null, true);

        // ── the whole point ──────────────────────────────────────────────────────
        flushAll();
        $hist = (new VehicleService())->serviceHistoryFor($vid);
        $atMeter = array_values(array_filter($hist, fn ($h) => (int) ($h['meter'] ?? 0) === $m12));
        ok('Past services shows the job ONCE, not twice', count($atMeter), 1);
        ok('  …and that row carries the bill', (float) ($atMeter[0]['amount'] ?? 0), 2400.0);
        ok('  …and is still the correctable LOG row', (bool) ($atMeter[0]['log_id'] ?? null), true);
        ok('  …naming the claim it is linked to', (int) ($atMeter[0]['bill_id'] ?? 0), (int) $log12->request_id);

        // ⚠⚠ And the evidence engine must not count the pair twice either. Force the two
        //    halves to DISAGREE — the exact case where a double count picks a silent winner.
        DB::table('t_req_master')->where('id', $log12->request_id)
            ->update(['meter_at_fill' => $m12 + 5000]);
        flushAll();
        $sched12 = (new VehicleService())->serviceScheduleFor($vid, (new VehicleService())->currentMeterFor($vid));
        $row12 = null;
        foreach ($sched12 as $t) if ((int) $t['id'] === (int) $resetting->id) $row12 = $t;
        ok('the countdown follows the LOG, not the linked claim',
           $row12 ? (int) $row12['last_meter'] : -1, $m12);

        // ── review, 3-Sep: the linked pair must stay ONE truth ───────────────────
        /**
         * ⭐⭐ Amending the log alone would leave the claim carrying the old odometer — harmless
         *    while linked, but the moment the log is removed the claim resurfaces with the
         *    figure just declared wrong. So the reading is mirrored onto the claim, and the
         *    amount is never touched.
         */
        $svcRec12 = app(\App\Services\Riders\ServiceRecordService::class);
        $am12 = $svcRec12->amend((int) $log12->id, ['meter' => $m12 + 7], (int) $actor->id);
        ok('amending a LINKED log succeeds', $am12['ok'], true);
        $claimAfter = DB::table('t_req_master')->where('id', $log12->request_id)->first();
        ok('  …and mirrors the odometer onto the linked claim', (int) $claimAfter->meter_at_fill, $m12 + 7);
        ok('  …without touching the amount', (float) $claimAfter->amount, 2400.0);
        ok('  …and says so', str_contains($am12['message'], 'linked expense'), true);
        // ⚠ The bill is Bikes money — Nizami Farms, business unit 1 — never the other books.
        ok('the bill was filed into business unit 1', (int) $claimAfter->business_unit_id, 1);

        /**
         * ⚠⚠ DELETING A SERVICE NEVER DELETES MONEY. The claim stays and the manager is told.
         */
        $rm12 = $svcRec12->remove((int) $log12->id, (int) $actor->id);
        ok('removing a LINKED log succeeds', $rm12['ok'], true);
        ok('  …the claim is NOT deleted', DB::table('t_req_master')->where('id', $log12->request_id)->exists(), true);
        ok('  …and the manager is told the expense stands', str_contains($rm12['message'], 'NOT removed'), true);
        flushAll();
        $histAfterRm = (new VehicleService())->serviceHistoryFor($vid);
        $back = array_values(array_filter($histAfterRm, fn ($h) => (int) ($h['req_id'] ?? 0) === (int) $log12->request_id));
        ok('  …and the claim resurfaces in Past services as an ordinary row', count($back), 1);
    } finally {
        DB::rollBack();
        flushAll();
    }

    // ── 🧾 THE BILL PHOTO ────────────────────────────────────────────────────
    /**
     * ⭐ OWNER ASK (3-Sep): "if we are raising a request for expense from the same, the bill
     *   should also be there." It rides through to the real request under the field
     *   RequestController::store reads (`attachment_image`), so it is stored, named and
     *   attached by the SAME code that handles a rider's own receipt.
     * ⚠ Forwarded as the same UploadedFile instance — a copy would land as an ordinary file
     *   and fail the `image` rule.
     */
    DB::beginTransaction();
    $tmpBill = null; $storedPaths = [];
    try {
        flushAll();
        $tmpBill = sys_get_temp_dir() . '/bill_test_' . uniqid() . '.png';
        file_put_contents($tmpBill, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $upload = new \Illuminate\Http\UploadedFile($tmpBill, 'bill.png', 'image/png', null, true);

        $m13 = $baseMeter + 680;
        $req13 = Request::create('/orders/riders-map/fleet/mark-serviced', 'POST',
            ['rider_id' => $rider, 'meter' => $m13,
             'maintenance_type_id' => $resetting->id, 'amount' => 3100],
            [], ['bill_image' => $upload]);
        $res13 = app(FleetFuelController::class)->markServiced($req13);
        $b13 = json_decode($res13->getContent(), true);
        ok('a service with a BILL PHOTO is accepted', $res13->getStatusCode(), 200);

        $log13 = DB::table('t_fleet_service_log')->orderByDesc('id')->first();
        $req13row = $log13->request_id
            ? DB::table('t_req_master')->where('id', $log13->request_id)->first() : null;
        ok('  …the claim carries an attachment', (bool) ($req13row->attachments ?? null), true);
        $storedPaths = json_decode($req13row->attachments ?? '[]', true) ?: [];
        ok('  …and the file really landed on disk',
           count($storedPaths) === 1 && \Illuminate\Support\Facades\Storage::disk('public')->exists($storedPaths[0]), true);
        ok('  …the receipt confirms the bill went with it',
           str_contains($b13['message'] ?? '', 'Bill attached'), true);

        // ⚠ And the manager must be TOLD when he filed money with no bill — that is a thing
        //   to notice now, not weeks later when someone audits the expense.
        $req14 = Request::create('/orders/riders-map/fleet/mark-serviced', 'POST',
            ['rider_id' => $rider, 'meter' => $baseMeter + 700,
             'maintenance_type_id' => $resetting->id, 'amount' => 900]);
        $b14 = json_decode(app(FleetFuelController::class)->markServiced($req14)->getContent(), true);
        ok('an expense with NO bill says so plainly',
           str_contains($b14['message'] ?? '', 'No bill photo attached'), true);
    } finally {
        // ⚠ Storage is NOT transactional — the file survives the rollback unless we remove it.
        foreach ($storedPaths as $sp) {
            try { \Illuminate\Support\Facades\Storage::disk('public')->delete($sp); } catch (\Throwable $e) {}
        }
        if ($tmpBill && is_file($tmpBill)) @unlink($tmpBill);
        DB::rollBack();
        flushAll();
    }
}

// ⭐ A blank amount must behave EXACTLY as before — no claim, no money, no noise.
DB::beginTransaction();
try {
    flushAll();
    $claimsBefore = DB::table('t_req_master')->count();
    [$stNo, $bNo] = post(['rider_id' => $rider, 'meter' => $baseMeter + 660,
                          'maintenance_type_id' => $resetting->id]);
    ok('a service with NO amount still works', $stNo, 200);
    ok('  …and files no claim at all', DB::table('t_req_master')->count(), $claimsBefore);
    ok('  …and says nothing about money',
       (bool) preg_match('/expense|Rs /i', $bNo['message'] ?? ''), false);
} finally {
    DB::rollBack();
    flushAll();
}

} finally {
    DB::rollBack();
    flushAll();
}

// ─────────────────────────────────────────────────────────────────────────────
head('§13 a maintenance CLAIM obeys the same "which service?" rule (owner, 3-Sep)');
/**
 * ⚠⚠ THE GAP THE OWNER FOUND ON A RIDER'S PHONE. The manager's Record service refused an
 *    untyped odometer (§1). The rider's own request — and a manager's claim for him — did
 *    not: a legacy "Regular service" was filed as [oil_change, NULL type], and the evidence
 *    engine skips every untyped claim, so the reading fed NO countdown. 116 of 140
 *    maintenance claims were untyped. Two rules for one fact; this makes it one.
 *
 * ⚠ Narrower than resolveType on purpose: a General Repair BILL (interval 0) is a valid
 *   claim even though it can never be RECORDED as a service. And with NO odometer nothing
 *   can feed a countdown, so an untyped bill is still accepted — an older APK can still
 *   file a repair receipt.
 */
$svcRec13 = app(\App\Services\Riders\ServiceRecordService::class);
$repairType = DB::table('t_fleet_maintenance_types')->where('is_active', 1)->where('interval_km', '<=', 0)->value('id');
ok('untyped + odometer is REFUSED', $svcRec13->requireTypeForClaim(null, true)['ok'], false);
ok('  …with the same "choose which service" wording the manager gets',
   str_contains($svcRec13->requireTypeForClaim(null, true)['message'], 'Choose which service was done'), true);
ok('untyped with NO odometer is still accepted (a bill can carry no reading)',
   $svcRec13->requireTypeForClaim(null, false)['ok'], true);
ok('a scheduled type + odometer is accepted', $svcRec13->requireTypeForClaim($resetting->id, true)['ok'], true);
if ($repairType) {
    ok('an "as conditions" type (General Repair) + odometer is accepted for a CLAIM',
       $svcRec13->requireTypeForClaim($repairType, true)['ok'], true);
    ok('  …even though resolveType refuses it for RECORDING a service',
       app(\App\Services\Riders\ServiceRecordService::class)->resolveType($repairType)['ok'], false);
}

// Both creation doors must go through it — asserted at the source, since the rider door
// authenticates its own user and this process holds ONE login.
$rcSrc  = file_get_contents(__DIR__ . '/app/Http/Controllers/API/RiderController.php');
$rqcSrc = file_get_contents(__DIR__ . '/app/Http/Controllers/Request/RequestController.php');
ok('the RIDER request door calls requireTypeForClaim', str_contains($rcSrc, 'requireTypeForClaim('), true);
ok('the WEB / manager request door calls requireTypeForClaim', str_contains($rqcSrc, 'requireTypeForClaim('), true);

// The rider's form: the fetch must not strip the type list, and the fallback pair must
// never send an odometer.
$reqSvc = __DIR__ . '/../NizamiFarmsMobile/src/services/requestService.js';
$reqScr = __DIR__ . '/../NizamiFarmsMobile/src/screens/RequestsScreen.js';
if (is_file($reqSvc) && is_file($reqScr)) {
    ok('the app\'s categories fetch keeps maintenance_types',
       str_contains(file_get_contents($reqSvc), 'maintenance_types'), true);
    ok('the fallback pair refuses to send an odometer',
       str_contains(file_get_contents($reqScr), 'Service types did not load'), true);
}

// ─────────────────────────────────────────────────────────────────────────────
head('§14 a bill is tied to a service by being CHOSEN (owner ruling, 3-Sep)');
/**
 * ⭐⭐ THE RULING. An earlier draft matched a bill to a reading on "meter within 100 km, date
 *    within 7 days". The owner rejected it: guessing is what misfiled service log #8, and a
 *    tolerance is not auditable. So the filer PICKS the service from his own un-billed
 *    readings — and the claim then INHERITS that reading, so he never retypes a meter he has
 *    already entered.
 *
 * ⚠⚠ AND THE DOUBLE-MONEY GUARD. Without it, a manager who records the service WITH the
 *    receipt and a rider who then files the same receipt from his phone send money out twice.
 */
if (!Illuminate\Support\Facades\Schema::hasColumn('t_fleet_service_log', 'request_id')) {
    echo "  · link column not applied — skipping\n";
} else {
    DB::beginTransaction();
    try {
        flushAll();
        $svcRec14 = app(\App\Services\Riders\ServiceRecordService::class);
        $m14 = $baseMeter + 900;

        // A reading with NO bill — service day, or Qasim entering a meter.
        [$st14] = post(['rider_id' => $rider, 'meter' => $m14, 'maintenance_type_id' => $resetting->id]);
        ok('a service recorded with no bill', $st14, 200);
        $log14 = DB::table('t_fleet_service_log')->orderByDesc('id')->first();

        // It is offered to him — and to nobody else.
        // ⚠ A REAL second rider, discovered — an undefined variable would have made the
        //   "not offered to someone else" assertions pass against user 0 and prove nothing.
        $otherRiderId = (int) DB::table('t_ops_rider_profile')->where('user_id', '!=', $rider)->value('user_id');
        ok('a second rider exists to test isolation against', $otherRiderId > 0, true);

        $offered = $svcRec14->unbilledServicesFor($rider);
        ok('  …is offered in his un-billed list',
           (bool) array_filter($offered, fn ($u) => $u['log_id'] === (int) $log14->id), true);
        ok('  …labelled so he can recognise it',
           (bool) preg_match('/\d+ \w+ · .+ · [\d,]+ km/u', $offered[0]['label'] ?? ''), true);
        ok('  …and NOT offered to a different rider',
           (bool) array_filter($svcRec14->unbilledServicesFor($otherRiderId),
                               fn ($u) => $u['log_id'] === (int) $log14->id), false);

        // Choosing it hands back the reading to inherit — the point of the whole feature.
        $v14 = $svcRec14->validateBillTarget($log14->id, $rider);
        ok('choosing it is allowed', $v14['ok'], true);
        ok('  …and hands back the meter to inherit', (int) $v14['inherit']['meter'], $m14);
        ok('  …and the job', (int) $v14['inherit']['maintenance_type_id'], (int) $resetting->id);

        // Attaching a bill, then the guard.
        $svcRec14->attachBillToService((int) $log14->id, 999999);   // a claim id that is not live
        ok('a link to a non-live claim does NOT lock the service',
           $svcRec14->validateBillTarget($log14->id, $rider)['ok'], true);

        // A REAL live claim locks it.
        $realClaim = DB::table('t_req_master')->where('expense_category', 'Maintenance')
            ->whereIn('status', ['approved', 'pending'])->orderByDesc('id')->first();
        if ($realClaim) {
            $svcRec14->attachBillToService((int) $log14->id, (int) $realClaim->id);
            $guard = $svcRec14->validateBillTarget($log14->id, $rider);
            ok('a service that already has a LIVE bill is REFUSED', $guard['ok'], false);
            ok('  …and the refusal names the bill so he can check it',
               str_contains($guard['message'], 'already has a bill'), true);
            ok('  …and it drops out of the picker',
               (bool) array_filter($svcRec14->unbilledServicesFor($rider),
                                   fn ($u) => $u['log_id'] === (int) $log14->id), false);

            // ⚠ Rejecting the bill RELEASES the service — the link is live-status only.
            DB::table('t_req_master')->where('id', $realClaim->id)->update(['status' => 'rejected']);
            flushAll();
            ok('rejecting the bill releases the service again',
               $svcRec14->validateBillTarget($log14->id, $rider)['ok'], true);
            ok('  …and the rejected claim is no longer hidden from the history',
               isset(\App\Services\Riders\ServiceRecordService::liveBillLinks()[(int) $realClaim->id]), false);
        }

        // Someone else's service can never be billed by this rider.
        ok('another rider\'s service is refused',
           $svcRec14->validateBillTarget($log14->id, $otherRiderId)['ok'], false);
        ok('a service that does not exist is refused',
           $svcRec14->validateBillTarget(99999999, $rider)['ok'], false);
        // Nothing chosen = "a new service" = exactly today's behaviour.
        ok('choosing nothing is allowed (a new service)', $svcRec14->validateBillTarget(null, $rider)['ok'], true);
    } finally {
        DB::rollBack();
        flushAll();
    }
}
// Both bill doors must consult the gate, and neither may match on meter/date.
$rcSrc14  = file_get_contents(__DIR__ . '/app/Http/Controllers/API/RiderController.php');
$rqcSrc14 = file_get_contents(__DIR__ . '/app/Http/Controllers/Request/RequestController.php');
ok('the RIDER bill door validates the chosen service', str_contains($rcSrc14, 'validateBillTarget('), true);
ok('the WEB bill door validates the chosen service', str_contains($rqcSrc14, 'validateBillTarget('), true);
ok('the RIDER door links the bill afterwards', str_contains($rcSrc14, 'attachBillToService('), true);
ok('the WEB door links the bill afterwards', str_contains($rqcSrc14, 'attachBillToService('), true);
/**
 * ⚠ The rejected draft, asserted ABSENT — from the CODE, with comments stripped.
 *   The first version of this check searched the raw file and matched the docblock that
 *   *explains* why tolerance matching was rejected: a test that fails on its own
 *   documentation proves nothing about behaviour. Strip the prose, then look.
 */
$srCode14 = '';
foreach (token_get_all(file_get_contents(__DIR__ . '/app/Services/Riders/ServiceRecordService.php')) as $tk) {
    if (is_array($tk)) {
        if ($tk[0] === T_COMMENT || $tk[0] === T_DOC_COMMENT) continue;
        $srCode14 .= $tk[1];
    } else {
        $srCode14 .= $tk;
    }
}
ok('the linker code contains no meter tolerance',
   (bool) preg_match('/abs\s*\(|between\s*\(.*meter|meter.*[<>]=?\s*\$?\w*\s*[+-]\s*\d{2,}/i', $srCode14), false);
ok('  …and no date window', (bool) preg_match('/subDays\(\s*7\s*\)|addDays\(\s*7\s*\)/', $srCode14), false);
ok('  …while the un-billed LIST does bound itself by age (a picker, not a matcher)',
   str_contains($srCode14, 'subDays($days)'), true);

// ─────────────────────────────────────────────────────────────────────────────
head('§16 ADD THE BILL to a service recorded earlier (owner ask, 3-Sep)');
/**
 * ⭐⭐ THIS IS THE MANAGER'S PATH, and the data says so plainly: every service log on the
 *    system was recorded by Shabib or Qasim, all of them un-billed. A rider's own request form
 *    has always FORCED an amount (138 of 140 claims carry one), so a rider never leaves a
 *    reading behind for a bill to catch up with — only a manager does, and then the workshop
 *    receipt is handed over afterwards.
 *
 * ⚠⚠ AND THE CONTRADICTION THIS FIXED. `FuelClaimRules::checkOdometer` refused the bill for a
 *    reading the system had already ACCEPTED as a service log — "47,013 km is lower than this
 *    bike's 47,580 km". So the log stood while the bill for that very service could not be
 *    filed: a legitimate action nobody could complete, and two engines disagreeing about one
 *    number. An INHERITED reading is not new information, so it is no longer re-judged.
 * ⚠ Only inherited readings. A claim that ASSERTS a meter is judged exactly as before.
 */
/**
 * ⚠ DISCOVERED, not assumed. The fixture rider's machine need not be the one carrying an
 *   un-billed reading — asserting against `$vid` alone made this section fail on a bike that
 *   simply had none, which says nothing about the feature.
 */
$svcRec16 = app(\App\Services\Riders\ServiceRecordService::class);
$unbilled = []; $vid16 = null;
foreach (DB::table('t_fleet_service_log')->distinct()->pluck('user_id') as $lu) {
    $lv = (new VehicleResolver())->currentVehicleFor((int) $lu);
    if (!$lv) continue;
    $cand = array_values(array_filter((new VehicleService())->serviceHistoryFor((int) $lv, 60, true),
        fn ($r) => !empty($r['log_id']) && empty($r['bill_id'])));
    if ($cand) { $unbilled = $cand; $vid16 = (int) $lv; break; }
}
ok('an un-billed service exists to bill', count($unbilled) > 0, true);
if ($unbilled) {
    $u16 = $unbilled[0];
    ok('  …and the row carries the rider the bill must belong to', !empty($u16['rider_id']), true);
    DB::beginTransaction();
    try {
        flushAll();
        $cat16 = DB::table('t_req_category')->where('category_code', 'expense')->value('id');
        $mk16 = fn (array $extra = []) => Request::create('/x', 'POST', array_merge([
            'category_id' => $cat16, 'requester_user_id' => $u16['rider_id'],
            'title' => 'Workshop bill', 'amount' => 4200,
            'expense_category' => 'Maintenance', 'service_log_id' => $u16['log_id'],
            'business_unit_id' => 1,
        ], $extra));
        $res16 = app(\App\Http\Controllers\Request\RequestController::class)->store($mk16());
        $b16 = json_decode($res16->getContent(), true);
        ok('the bill is accepted with the AMOUNT alone', $res16->getStatusCode(), 200);
        $cl16 = DB::table('t_req_master')->where('id', $b16['request_id'] ?? 0)->first();
        ok('  …inheriting the odometer', (int) $cl16->meter_at_fill, (int) $u16['meter']);
        ok('  …the job', (int) $cl16->maintenance_type_id, (int) $u16['type']);
        ok('  …and the date of the WORK, not of the filing',
           substr((string) $cl16->expense_date, 0, 10), $u16['date']);

        flushAll();
        $after16 = (new VehicleService())->serviceHistoryFor($vid16, 60, true);
        $same16 = array_values(array_filter($after16, fn ($r) => (int) ($r['log_id'] ?? 0) === (int) $u16['log_id']));
        ok('the job is still ONE row', count($same16), 1);
        ok('  …now carrying the money', (float) $same16[0]['amount'], 4200.0);
        ok('  …and naming its bill', (int) ($same16[0]['bill_id'] ?? 0), (int) $cl16->id);
        // The guard still holds on this path.
        ok('billing the same service twice is refused',
           app(\App\Http\Controllers\Request\RequestController::class)->store($mk16())->getStatusCode(), 422);
    } finally { DB::rollBack(); flushAll(); }
}
// ⚠ The skip is NARROW: only an inherited reading escapes the plausibility check.
$fcSrc = file_get_contents(__DIR__ . '/app/Services/Riders/FuelClaimRules.php');
ok('the odometer check is skipped ONLY for an inherited reading',
   str_contains($fcSrc, "empty(\$input['meter_from_service_log'])"), true);
foreach (['Request/RequestController.php' => "'meter_from_service_log' =>",
          'API/RiderController.php'        => '"meter_from_service_log" =>'] as $f => $needle) {
    ok("  …and $f says when a reading was inherited",
       str_contains(file_get_contents(__DIR__ . '/app/Http/Controllers/' . $f), $needle), true);
}
/**
 * ⚠⚠ ONE VISIT, SEVERAL JOBS — checked against the data, not assumed. Every same-odometer
 *    pair on this system is two DIFFERENT jobs done in one visit (Waseem, 27,906 km:
 *    Oil + Tuning Rs 3,500 AND Brake Shoe Rs 650), never one job billed twice. So each job
 *    is its own service record with its own bill, and the guard must NOT stand in the way of
 *    the second job. This is the case that would otherwise block a manager from entering
 *    real work.
 */
if ($unbilled && $vid16) {
    DB::beginTransaction();
    try {
        flushAll();
        $rider16 = (int) $u16['rider_id'];
        $meter16 = (int) ((new VehicleService())->currentMeterFor($vid16) ?: 30000) + 1500;
        $cat16b  = DB::table('t_req_category')->where('category_code', 'expense')->value('id');
        $two = $types->filter(fn ($t) => (int) $t->interval_km > 0)->take(2)->values();
        $filed = 0;
        foreach ($two as $i => $t16) {
            [$stx] = post(['rider_id' => $rider16, 'meter' => $meter16, 'maintenance_type_id' => $t16->id]);
            if ($stx !== 200) continue;
            $lg = DB::table('t_fleet_service_log')->orderByDesc('id')->first();
            $rr = app(\App\Http\Controllers\Request\RequestController::class)->store(
                Request::create('/x', 'POST', ['category_id' => $cat16b, 'requester_user_id' => $rider16,
                    'title' => $t16->type_name, 'amount' => 500 + $i, 'expense_category' => 'Maintenance',
                    'service_log_id' => $lg->id, 'business_unit_id' => 1]));
            if ($rr->getStatusCode() === 200) $filed++;
        }
        ok('two DIFFERENT jobs at the same odometer can both be billed', $filed, 2);
        flushAll();
        $atMeter16 = array_values(array_filter((new VehicleService())->serviceHistoryFor($vid16, 60, true),
            fn ($r) => (int) ($r['meter'] ?? 0) === $meter16));
        ok('  …and each appears as its own row', count($atMeter16), 2);
        ok('  …each carrying its own bill',
           count(array_filter($atMeter16, fn ($r) => !empty($r['bill_id']))), 2);
    } finally { DB::rollBack(); flushAll(); }
}
// ⚠ And when the SAME service is billed twice, the refusal must name the way out.
$dead = app(\App\Services\Riders\ServiceRecordService::class);
$anyLinked = DB::table('t_fleet_service_log as l')->join('t_req_master as r', 'r.id', '=', 'l.request_id')
    ->whereIn('r.status', ['approved', 'pending'])->first(['l.id', 'l.user_id']);
if ($anyLinked) {
    $msg = $dead->validateBillTarget($anyLinked->id, (int) $anyLinked->user_id)['message'];
    ok('the double-bill refusal tells him what to do instead',
       str_contains($msg, 'DIFFERENT job done in the same visit'), true);
}

$bl16 = __DIR__ . '/resources/views/pages/riders-map/partials/fleet.blade.php';
if (is_file($bl16)) {
    $b = file_get_contents($bl16);
    ok('the vehicle page offers "Add the bill"', str_contains($b, 'flvAddBill('), true);
    // ⚠ 29-Sep-2026 (C1): the server's bill_live decides — bill_id survives a rejected claim.
    ok('  …only on rows with no live bill', str_contains($b, 's.log_id && !flvBillLive(s) && canFix'), true);
    ok('  …and sends the chosen service', str_contains($b, 'body.service_log_id = flNewSvcLogId'), true);
    // ⚠ A stale id would attach the NEXT claim to the wrong reading.
    ok('  …clearing it on every open of the form', str_contains($b, 'flNewSvcLogId = null;'), true);
}
// Mobile parity — the same two things, wired the same way.
$fvJs = __DIR__ . '/../NizamiFarmsMobile/src/components/FleetVehicles.js';
$fsJs = __DIR__ . '/../NizamiFarmsMobile/src/screens/FleetScreen.js';
if (is_file($fvJs) && is_file($fsJs)) {
    $fv = file_get_contents($fvJs); $fs = file_get_contents($fsJs);
    ok('mobile shows the cost strip', str_contains($fv, 'detail?.cost_summary?.windows'), true);
    ok('  …with fuel on its own line', str_contains($fv, "line('⛽ Fuel', w.fuel_rs)"), true);
    ok('  …and unclassified shown, not hidden', str_contains($fv, "line('❓ Unclassified'"), true);
    ok('mobile offers "Add the bill"', str_contains($fv, 'onAddBill(s.log_id, s.rider_id'), true);
    ok('  …only where there is no live bill', str_contains($fv, 's.log_id && !billLive(s) && detail?.can_log_meters'), true);
    // ⚠ It must REUSE the claim sheet that already has the payment sources.
    ok('  …handed up to the screen that owns the claim form', str_contains($fs, 'onAddBill={async'), true);
    ok('  …which sends the chosen service', str_contains($fs, 'body.service_log_id = newClaim.serviceLogId'), true);
    ok('  …and stops asking for a meter it will inherit', str_contains($fs, '{!newClaim?.serviceLogId ? ('), true);
}

// ─────────────────────────────────────────────────────────────────────────────
head('§17 the workshop bill, filed with the visit (owner ruling Q5)');
/**
 * ⭐ The receipt is handed over on the day, so the rider can file it with the reading instead
 *   of leaving an un-billed service for a manager to chase.
 *
 * ⚠⚠ THE BUG THIS CAUGHT. `RequestController::store` treats the PRESENCE of
 *    `requester_user_id` as "on behalf of another user", which needs a permission no rider
 *    holds — so a rider filing HIS OWN workshop bill was refused outright. It is now sent only
 *    when someone else is filing for him. Both actors are asserted below, because fixing one
 *    and breaking the other is the obvious way to get this wrong.
 */
$wvSvc17 = new \App\Services\Riders\WorkshopVisitService();
$riderU  = \App\Models\User::find($rider);
if ($riderU) {
    /**
     * ⚠ ONE LOGIN PER PROCESS (see the header) means `auth()->user()` is the MANAGER for the
     *   whole run — and `RequestController::store` resolves the filer from exactly that. So
     *   this harness cannot produce a rider-authenticated claim, and BOTH cases land approved
     *   here. What it does prove is the part that was broken: the claim is created, it belongs
     *   to the RIDER, and it is LINKED to his service. Approval routing is proved separately,
     *   from the L1 rights themselves.
     */
    foreach ([['the RIDER files his own bill', $riderU, 'approved'],
              ['a MANAGER files it for him',   $actor,  'approved']] as $i => [$tag, $who, $expect]) {
        DB::beginTransaction();
        try {
            flushAll();
            $w17 = $wvSvc17->schedule($actor, ['user_id' => $rider, 'visit_date' => date('Y-m-d'),
                'purpose' => 'service', 'maintenance_type_id' => $resetting->id]);
            $wvSvc17->accept($riderU, (int) $w17['visit_id']);
            $req17 = Request::create('/x', 'POST', ['meter' => $baseMeter + 1200 + $i, 'amount' => 2600]);
            $req17->setUserResolver(fn () => $who);
            $res17 = app(\App\Http\Controllers\CRM\WorkshopVisitController::class)
                ->done($req17, (int) $w17['visit_id']);
            $b17 = json_decode($res17->getContent(), true);
            ok("$tag — accepted", $res17->getStatusCode(), 200);
            $lg17 = DB::table('t_fleet_service_log')->where('id', $b17['service_log_id'] ?? 0)->first();
            ok('  …the service is recorded', (bool) $lg17, true);
            ok('  …and its bill is LINKED to it', (bool) ($lg17->request_id ?? null), true);
            $cl17 = $lg17 && $lg17->request_id
                ? DB::table('t_req_master')->where('id', $lg17->request_id)->first() : null;
            ok('  …the claim belongs to the RIDER, never the filer',
               (int) ($cl17->requester_user_id ?? 0), $rider);
            // ⚠ Approval follows the FILER's own rights, exactly as any other claim of his.
            ok('  …and it lands with a real status', in_array($cl17->status ?? '', ['approved','pending'], true), true);
            ok('  …the receipt says what happened to the money',
               (bool) preg_match('/bill (added and approved|sent for approval)/i', $b17['message'] ?? ''), true);
        } finally { DB::rollBack(); flushAll(); }
    }
}
// Blank amount must remain the ordinary case: service recorded, nothing spent.
DB::beginTransaction();
try {
    flushAll();
    $claimsB4 = DB::table('t_req_master')->count();
    $w17b = $wvSvc17->schedule($actor, ['user_id' => $rider, 'visit_date' => date('Y-m-d'),
        'purpose' => 'service', 'maintenance_type_id' => $resetting->id]);
    $r17b = Request::create('/x', 'POST', ['meter' => $baseMeter + 1300]);
    $r17b->setUserResolver(fn () => $actor);
    $x17 = app(\App\Http\Controllers\CRM\WorkshopVisitController::class)->done($r17b, (int) $w17b['visit_id']);
    ok('completing with NO amount still works', $x17->getStatusCode(), 200);
    ok('  …and files no claim at all', DB::table('t_req_master')->count(), $claimsB4);
} finally { DB::rollBack(); flushAll(); }

$wvcSrc = file_get_contents(__DIR__ . '/app/Http/Controllers/CRM/WorkshopVisitController.php');
// ⚠ 29-Sep-2026: the workshop close and the Bikes screen share ONE bill door,
//   `ServiceRecordService::fileBill`, so the rules are asserted there.
$fbSrc = file_get_contents(__DIR__ . '/app/Services/Riders/ServiceRecordService.php');
// ⚠ 29-Sep-2026 (A1): both doors decide through billRecordedVisit, which files via fileBill.
ok('the visit bill goes through the ONE shared bill door', str_contains($wvcSrc, '->billRecordedVisit(')
   && str_contains($fbSrc, '$this->fileBill($request, array_merge('), true);
ok('  …which files through the real request door', str_contains($fbSrc, 'RequestController::class)->store('), true);
ok('  …and omits requester_user_id when he files his own',
   str_contains($fbSrc, '$onBehalf ? $riderId : null'), true);
$wopJs = __DIR__ . '/../NizamiFarmsMobile/src/components/WorkshopOutcomePrompt.js';
if (is_file($wopJs)) {
    $wop = file_get_contents($wopJs);
    ok('the rider\'s prompt asks for the bill', str_contains($wop, 'Bill kitne ka hai?'), true);
    ok('  …in Roman Urdu, since he must act on it', str_contains($wop, 'approval ke liye jayega'), true);
    ok('  …and sends it only when entered', str_contains($wop, 'if (Number(amount) > 0) body.amount'), true);
}
// Mobile history filter parity.
if (is_file($fvJs ?? '')) {
    $fv2 = file_get_contents($fvJs);
    ok('mobile filters the history too', str_contains($fv2, "histFilter === 'all' || (s.bucket"), true);
    ok('  …resetting when another machine is opened', str_contains($fv2, "setHistFilter('all')"), true);
}

// ─────────────────────────────────────────────────────────────────────────────
head('§18 the rider\'s own Create Request — untouched, plus the picker');
/**
 * ⭐ The ordinary path must be exactly as it was: he picks the service type, types the meter
 *   and the amount, attaches the receipt, and it goes for approval. Every change this round
 *   is additive to it — `service_log_id` is optional and absent here.
 *
 * ⚠ The picker only appears when he HAS a service with no bill, which happens after answering
 *   "ho gaya?" with a meter and no amount. Then the claim INHERITS that record's odometer, job
 *   and DATE — so a bill filed today for work done on Monday lands on Monday, which is what the
 *   countdown and the P&L both need.
 */
$rqScr = __DIR__ . '/../NizamiFarmsMobile/src/screens/RequestsScreen.js';
if (is_file($rqScr)) {
    $rs = file_get_contents($rqScr);
    ok('the rider form asks his un-billed services', str_contains($rs, "api.get('/rider/store/fleet/unbilled-services')"), true);
    ok('  …offers "Nayi service" as the default', str_contains($rs, 'Nayi service'), true);
    // ⚠ 29-Sep-2026: the pick is a VISIT (one bill may cover several jobs) — one job still
    //   goes as `service_log_id`, several as `service_log_ids`.
    ok('  …sends the chosen service', str_contains($rs, 'payload.service_log_id = ids[0]')
       && str_contains($rs, 'payload.service_log_ids = ids'), true);
    ok('  …and stops sending a meter it will inherit', str_contains($rs, 'delete payload.meter_at_fill'), true);
    ok('  …hiding the type picker too when one is chosen',
       str_contains($rs, "expenseCategory === 'Maintenance' && !billForLogId"), true);
    // ⚠ A stale pick would attach the NEXT bill to the wrong service.
    ok('  …and clearing the pick when the form resets', str_contains($rs, 'setBillForLogId(null);'), true);
    ok('the picker is hidden when he has no un-billed service',
       str_contains($rs, "unbilledVisits.length > 0 &&"), true);
}
ok('the API door accepts an optional service_log_id',
   str_contains(file_get_contents(__DIR__ . '/app/Http/Controllers/API/RiderController.php'),
                "'service_log_id' => 'nullable|integer'"), true);

// ─────────────────────────────────────────────────────────────────────────────
head('§19 the mirror is WHOLE — correcting the claim reaches its log (found unbuilt in review)');
/**
 * ⚠⚠ I had recorded this as fixed and it was not. `amend()` on a log mirrored to the claim;
 *    `correctClaim()` on the claim did NOT reach the log. Because the history and the countdown
 *    follow the LOG, a manager's correction on the claim side appeared to do nothing at all.
 *    Both directions are asserted here so neither can quietly go missing again.
 */
if (Illuminate\Support\Facades\Schema::hasColumn('t_fleet_service_log', 'request_id')) {
    DB::beginTransaction();
    try {
        flushAll();
        $m19 = $baseMeter + 2100;
        [$st19] = post(['rider_id' => $rider, 'meter' => $m19, 'maintenance_type_id' => $resetting->id, 'amount' => 1100]);
        ok('a service recorded WITH its bill', $st19, 200);
        $lg19 = DB::table('t_fleet_service_log')->orderByDesc('id')->first();
        ok('  …is linked', (bool) $lg19->request_id, true);

        $svc19 = app(\App\Services\Riders\ServiceRecordService::class);
        $r19 = $svc19->correctClaim((int) $lg19->request_id, ['meter' => $m19 + 33], (int) $actor->id);
        ok('correcting the CLAIM succeeds', $r19['ok'], true);
        ok('  …and reaches the linked LOG', (int) DB::table('t_fleet_service_log')->where('id', $lg19->id)->value('meter'), $m19 + 33);
        ok('  …and says so', str_contains($r19['message'], 'linked service record'), true);
        ok('  …without touching the amount',
           (float) DB::table('t_req_master')->where('id', $lg19->request_id)->value('amount'), 1100.0);

        // And the other way still holds.
        $svc19->amend((int) $lg19->id, ['meter' => $m19 + 44], (int) $actor->id);
        ok('correcting the LOG still reaches the claim',
           (int) DB::table('t_req_master')->where('id', $lg19->request_id)->value('meter_at_fill'), $m19 + 44);
    } finally { DB::rollBack(); flushAll(); }
}

// ─────────────────────────────────────────────────────────────────────────────
head('§20 the THIRD door — editing a pending claim reaches its log, and record-with-bill is one path');
/**
 * ⚠⚠ TWO THINGS THE REVIEW FOUND.
 *  1. `recordServiceBill` RESENT meter/type/date, so the bill door re-judged a reading the
 *     service door had just accepted — a service 2,000 km on recorded fine, then its own bill
 *     was refused as "far above this bike's last reading". It now sends `service_log_id` and
 *     inherits, exactly like "Add the bill later". One path for every bill.
 *  2. `editClaim` — the original pending-claim door — knew nothing about links. Editing the
 *     meter there left the linked log on the old number, and the history follows the log.
 */
if (Illuminate\Support\Facades\Schema::hasColumn('t_fleet_service_log', 'request_id')) {
    DB::beginTransaction();
    try {
        flushAll();
        // 1. A big jump: the service is accepted, so its bill must be too.
        $m20 = $baseMeter + 2100;
        [$st20, $b20] = post(['rider_id' => $rider, 'meter' => $m20, 'maintenance_type_id' => $resetting->id, 'amount' => 1300]);
        ok('record-with-bill on a large jump: service accepted', $st20, 200);
        ok('  …and the bill was NOT refused for the same reading', empty($b20['bill_failed']), true);
        $lg20 = DB::table('t_fleet_service_log')->orderByDesc('id')->first();
        ok('  …and the pair is linked', (bool) $lg20->request_id, true);
        $cl20 = DB::table('t_req_master')->where('id', $lg20->request_id)->first();
        ok('  …the claim inherited the meter', (int) $cl20->meter_at_fill, $m20);

        /**
         * 2. Edit a linked PENDING claim through the OLD door and the log must follow.
         * ⚠ A SEPARATE, realistic pair. The edit door ASSERTS a new meter, so it is judged by
         *   the plausibility rule like any fresh claim — and that rule measures against the
         *   readings it knows, which do NOT include service logs. Editing the +2,100 km pair
         *   above is therefore refused as "far above the last reading" — correct behaviour for
         *   an asserted figure, and a fixture problem, not a code one. So the edit is proved on
         *   a small, plausible jump. (That the rules do not see service logs is flagged to the
         *   owner separately; it is a fuel-rules question, not a linking one.)
         */
        $m20b = $baseMeter + 60;
        [$stB] = post(['rider_id' => $rider, 'meter' => $m20b, 'maintenance_type_id' => $resetting->id, 'amount' => 700]);
        ok('a second, plausible linked pair', $stB, 200);
        $lg20b = DB::table('t_fleet_service_log')->orderByDesc('id')->first();
        DB::table('t_req_master')->where('id', $lg20b->request_id)->update(['status' => 'pending']);  // editable state
        $edit = Request::create('/x', 'POST', ['meter_at_fill' => $m20b + 21, 'maintenance_type_id' => $resetting->id]);
        $er = app(FleetFuelController::class)->editClaim($edit, $lg20b->request_id);
        ok('editing the PENDING claim (old door) succeeds', $er->getStatusCode(), 200);
        ok('  …and its linked log follows',
           (int) DB::table('t_fleet_service_log')->where('id', $lg20b->id)->value('meter'), $m20b + 21);
    } finally { DB::rollBack(); flushAll(); }
}
$ffSrc20 = file_get_contents(__DIR__ . '/app/Http/Controllers/CRM/FleetFuelController.php');
// ⚠ 29-Sep-2026: the bill door is ONE shared method, `ServiceRecordService::fileBill`, used by the
//   Bikes screen and the workshop close alike. It sends the job ids and lets store() inherit.
$recSrc20 = file_get_contents(__DIR__ . '/app/Services/Riders/ServiceRecordService.php');
ok('the shared bill door sends the job ids instead of resending the reading',
   (bool) preg_match("/'service_log_ids'\s*=>\s*\\\$ids/", $recSrc20)
   && !preg_match("/'meter_at_fill'\s*=>/", substr($recSrc20, strpos($recSrc20, 'function fileBill'), 6000)), true);
ok('  …and no longer resends meter_at_fill', substr_count($ffSrc20, "'meter_at_fill'      => isset(\$data['meter'])"), 0);

// ─────────────────────────────────────────────────────────────────────────────
head('§15 what this machine has COST — buckets, windows, lifetime (owner ask, 3-Sep)');
/**
 * ⚠⚠ THE PAGE COULD NOT ANSWER IT. One month, with every non-fuel claim under a single
 *    "maintenance" figure and a history capped at 24 rows inside a 24-month window. So
 *    "is my money going on scheduled upkeep or on things breaking, this month and over its
 *    life" had to be added up by hand.
 *
 * ⭐ Fuel is its OWN bucket, never folded in — the machine's Rs/km averages come from it.
 * ⚠ "Unclassified" is REPORTED, never absorbed: 109 legacy rows say nothing about what kind
 *   of work they paid for, and a total that quietly swallows them is a lie.
 */
$costSvc = new VehicleService();
$cost = $costSvc->costSummaryFor($vid);
ok('every window is present', array_keys($cost['windows']), ['month', 'quarter', 'year', 'lifetime']);
$lt = $cost['windows']['lifetime'];
foreach (['regular_rs', 'repairs_rs', 'fuel_rs', 'unclassified_rs', 'waiting_rs', 'maintenance_rs'] as $f) {
    ok("lifetime carries $f", array_key_exists($f, $lt), true);
}
ok('maintenance_rs = regular + repairs + unclassified (fuel EXCLUDED)',
   round($lt['regular_rs'] + $lt['repairs_rs'] + $lt['unclassified_rs'], 2), $lt['maintenance_rs']);
ok('  …so fuel is never folded into maintenance',
   $lt['fuel_rs'] > 0 ? $lt['maintenance_rs'] !== round($lt['maintenance_rs'] + $lt['fuel_rs'], 2) : true, true);
// Lifetime is a superset of the year, which is a superset of the month.
ok('lifetime ≥ year ≥ month (the windows nest)',
   $lt['count'] >= $cost['windows']['year']['count']
   && $cost['windows']['year']['count'] >= $cost['windows']['month']['count'], true);

/**
 * ⚠⚠ ONE CLASSIFIER. The cost tiles and the history rows must never disagree about what a
 *    row is — a claim counted as a Repair in the total and shown as Regular in the list is
 *    exactly the kind of thing nobody would ever reconcile.
 */
$histAll = $costSvc->serviceHistoryFor($vid, 200, true);
$hist24  = $costSvc->serviceHistoryFor($vid, 24, false);
ok('all-time reaches further back than the 24-month window',
   count($histAll) >= count($hist24), true);
ok('every history row carries a bucket',
   count(array_filter($histAll, fn ($h) => !empty($h['bucket']))), count($histAll));
ok('  …and only ever one of the four',
   count(array_filter($histAll, fn ($h) => !in_array($h['bucket'], ['regular', 'repairs', 'fuel', 'unclassified'], true))), 0);
$vsSrc = file_get_contents(__DIR__ . '/app/Services/Riders/VehicleService.php');
ok('the classifier exists exactly once', substr_count($vsSrc, 'private static function bucketOfClaim'), 1);
ok('  …and the cost engine uses it rather than its own copy',
   str_contains($vsSrc, "self::bucketOfClaim(\$c, \$buckets) . '_rs'"), true);

// Rejected money must never appear as spent.
$rejClaim = DB::table('t_req_master')->where('expense_category', 'Maintenance')
    ->whereNotNull('vehicle_id')->where('status', 'approved')->orderByDesc('id')->first();
if ($rejClaim) {
    DB::beginTransaction();
    try {
        flushAll();
        $before = (new VehicleService())->costSummaryFor((int) $rejClaim->vehicle_id)['windows']['lifetime'];
        DB::table('t_req_master')->where('id', $rejClaim->id)->update(['status' => 'rejected']);
        flushAll();
        $after = (new VehicleService())->costSummaryFor((int) $rejClaim->vehicle_id)['windows']['lifetime'];
        ok('rejecting a claim removes it from the cost totals',
           $after['maintenance_rs'] + $after['fuel_rs'] < $before['maintenance_rs'] + $before['fuel_rs'], true);
    } finally { DB::rollBack(); flushAll(); }
}

// The vehicle page's own actions, and the payload they gate on.
$vcSrc = file_get_contents(__DIR__ . '/app/Http/Controllers/CRM/VehicleController.php');
ok('both vehicle payloads ship the cost summary', substr_count($vcSrc, "'cost_summary' =>"), 2);
ok('the WEB detail payload answers can_log_meters itself',
   substr_count($vcSrc, "'can_log_meters' => \$this->canLogMeters()"), 3);
$blSrc = __DIR__ . '/resources/views/pages/riders-map/partials/fleet.blade.php';
if (is_file($blSrc)) {
    $bl = file_get_contents($blSrc);
    ok('the vehicle page offers New maintenance', str_contains($bl, 'flvNewMaintenance('), true);
    ok('  …and Record service', str_contains($bl, 'flvRecordService('), true);
    // ⚠ They must REUSE the existing forms, not add a second way to file a claim.
    ok('  …reusing the existing claim form', str_contains($bl, "flOpenNew('Maintenance')"), true);
    ok('  …and the existing record-service flow', str_contains($bl, 'flMarkServiced(keeperUserId'), true);
    ok('the cost strip is mounted', str_contains($bl, '+   costStrip'), true);
}

// ─────────────────────────────────────────────────────────────────────────────
head('§6 the guessing fallback is gone from the SOURCE');

$src = file_get_contents(__DIR__ . '/app/Http/Controllers/CRM/FleetFuelController.php');
$mark = substr($src, strpos($src, 'public function markServiced'),
                strpos($src, 'public function setDefaultInterval') - strpos($src, 'public function markServiced'));
ok('markServiced no longer queries for a substitute type',
   (bool) preg_match('/resets_service_clock.*1.*orderBy\(.interval_km.\)/s', $mark ?: $src), false);
ok('  …and no longer writes a "no service type given" note',
   (bool) strpos($src, 'no service type given, treated as the routine service'), false);

/**
 * ⭐ THE RULE MOVED, ON PURPOSE (Phase 3). Three callers with three different permission
 *   gates must apply it identically — the Bikes screen, completing a workshop visit, and
 *   the RIDER answering "did it get done?" (no `manage_bike_service` at all). So the gate
 *   stays in each controller and the rule lives in ServiceRecordService.
 */
$recSrc = file_get_contents(__DIR__ . '/app/Services/Riders/ServiceRecordService.php');
ok('  …the type-required refusal now lives in the shared recorder',
   (bool) preg_match('/Choose which service was done/', $recSrc), null, true);
/**
 * ⚠ Accepts either call shape. Sep-2026 the controller began resolving the recorder
 *   into a local first (it needs it twice — once for the machine's class, once for the
 *   type), so pinning the exact `app(...)->resolveType` spelling was testing a coding
 *   style rather than the rule. What matters is that the controller asks the SHARED
 *   recorder, which the next assertion's "no duplicate refusal here" pins down.
 */
ok('  …and markServiced delegates to it rather than keeping a second copy',
   (bool) preg_match('/(ServiceRecordService::class\)\s*|\$rec\s*)->resolve(Type|Jobs)/', $src), null, true);
ok('  …with no duplicate of the refusal left in the controller',
   (bool) preg_match('/Choose which service was done/', $src), false);

head('§12 🏍 THE RECORD FOLLOWS THE VEHICLE, NOT THE ROSTER (owner ask, 10-Sep-2026)');

/**
 * ⭐⭐ THE CASE THIS EXISTS FOR, and it is the ORDINARY workshop morning:
 *
 *    The bike goes IN. The manager hands the rider a spare for the day. Every reader then
 *    asked `vehicleForDay(rider, date)` — "what was he on that day" — and got the SPARE. So
 *    the oil change was credited to a machine that never had one; the visit read "done" with
 *    a `service_log_id`; and the real bike's countdown kept running with nothing anywhere
 *    saying why.
 *
 * ⭐ The record now carries the machine, frozen at filing time — the same treatment
 *   `stampClaim()` has always given money.
 */
flushAll();
$spare = DB::table('t_ops_vehicle')->where('is_active', 1)->where('is_company', 1)
    ->where('id', '!=', $vid)->orderBy('id')->value('id');
ok('a second COMPANY machine exists to stand in as the spare', (bool) $spare, null, true);

if ($spare) {
    $spare = (int) $spare;
    $rec   = app(\App\Services\Riders\ServiceRecordService::class);

    ok('the stamp column is applied on this database',
       \App\Services\Riders\ServiceRecordService::logStampsVehicle(), true);

    // ── the resolver: an explicit machine WINS, a bad one is ignored, absent derives ──
    ok('an explicit machine wins over the roster', $rec->vehicleForRecord($spare, $rider), $spare);
    ok('  …absent falls back to the registry, exactly as before',
       $rec->vehicleForRecord(null, $rider), $vid);
    ok('  ⚠ …and an id that names no vehicle is IGNORED, never trusted',
       $rec->vehicleForRecord(99999999, $rider), $vid);

    // ── the recording itself carries it ──
    [$st9, $b9] = post(['rider_id' => $rider, 'meter' => $baseMeter + 40,
                        'maintenance_type_id' => $resetting->id, 'vehicle_id' => $vid]);
    ok('a recording naming the machine succeeds', $st9, 200);
    $log = DB::table('t_fleet_service_log')->orderByDesc('id')->first();
    ok('  ⭐ …and the row carries it', (int) $log->vehicle_id, $vid);
    ok('  …the response says which machine it landed on', $b9['vehicle_id'] ?? null, $vid);

    /**
     * ⭐⭐ THE HEADLINE. Move the rider onto the spare — the registry now says "spare" for
     *    today — and the record just filed must STILL belong to the bike at the workshop.
     */
    $before = DB::table('t_ops_vehicle_assignment')->where('user_id', $rider)
        ->whereNull('released_on')->first(['id']);
    DB::table('t_ops_vehicle_assignment')->where('user_id', $rider)->whereNull('released_on')
        ->update(['released_on' => \Carbon\Carbon::today()->format('Y-m-d')]);
    DB::table('t_ops_vehicle_assignment')->insert([
        'vehicle_id' => $spare, 'user_id' => $rider,
        'assigned_on' => \Carbon\Carbon::today()->format('Y-m-d'),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    flushAll();
    ok('the registry now hands him the SPARE', (new VehicleResolver())->currentVehicleFor($rider), $spare);
    ok('  ⭐⭐ …and the record still belongs to the machine at the workshop',
       \App\Services\Riders\ServiceRecordService::logVehicleOf($log), $vid);

    // …which is what the countdown reads. The evidence engine must see it on the RIGHT bike.
    $sawOnReal  = false; $sawOnSpare = false;
    foreach ($svc->serviceScheduleFor($vid, $baseMeter + 40) as $row) {
        if ((int) $row['id'] === (int) $resetting->id && (int) ($row['last_meter'] ?? 0) === $baseMeter + 40) $sawOnReal = true;
    }
    foreach ($svc->serviceScheduleFor($spare, $baseMeter + 40) as $row) {
        if ((int) $row['id'] === (int) $resetting->id && (int) ($row['last_meter'] ?? 0) === $baseMeter + 40) $sawOnSpare = true;
    }
    ok('  ⭐ the COUNTDOWN on the real machine was reset by it', $sawOnReal, true);
    ok('  ⚠ …and the spare was NOT given a service it never had', $sawOnSpare, false);

    /**
     * ⚠⚠ AND THE HISTORY THE OLD CODE WROTE STILL READS THE OLD WAY. Nothing is back-filled,
     *    so an unstamped row keeps being attributed by `vehicleForDay` — which is what makes
     *    this migration safe to run on a live table with no countdown moving underneath it.
     */
    DB::table('t_fleet_service_log')->where('id', $log->id)->update(['vehicle_id' => null]);
    ok('an UNSTAMPED row is still attributed by the registry, unchanged',
       \App\Services\Riders\ServiceRecordService::logVehicleOf(
           DB::table('t_fleet_service_log')->where('id', $log->id)->first()), $spare);

    // Put the roster back before the plausibility checks below.
    DB::table('t_ops_vehicle_assignment')->where('user_id', $rider)
        ->where('vehicle_id', $spare)->whereNull('released_on')->delete();
    if ($before) {
        DB::table('t_ops_vehicle_assignment')->where('id', $before->id)->update(['released_on' => null]);
    }
    flushAll();
}

// ─────────────────────────────────────────────────────────────────────────────
head('§13 ⚠ a dropped digit is refused, not recorded');

/**
 * ⚠⚠ Nothing checked the odometer on this door, and the evidence readers silently SKIP an
 *    implausible row — so "36500" typed as "3650" produced a receipt, a log row, a visit
 *    marked done, and a countdown that never reset. Every screen said the service had
 *    happened; none of them counted it.
 *
 * ⭐ Judged on the SAME spine every meter reading is judged on, so what this door accepts is
 *   exactly what the countdown will later count.
 */
flushAll();
$logsBefore10 = DB::table('t_fleet_service_log')->count();
[$st10, $b10] = post(['rider_id' => $rider, 'meter' => (int) round($baseMeter / 10),
                      'maintenance_type_id' => $resetting->id, 'vehicle_id' => $vid]);
ok('a reading a digit short is refused', $st10, 422);
ok('  …naming the machine and what it last read',
   (bool) preg_match('/does not fit|last seen at/i', $b10['message'] ?? ''), null, true);
ok('  …and NOTHING was written', DB::table('t_fleet_service_log')->count(), $logsBefore10);

// ⭐ A genuine BACK-DATED service at a lower odometer still passes — it sits inside the
//   machine's own range, which is the difference between "earlier" and "impossible".
[$st10b, ] = post(['rider_id' => $rider, 'meter' => max(1, $baseMeter - 200),
                   'maintenance_type_id' => $resetting->id, 'vehicle_id' => $vid,
                   'date' => \Carbon\Carbon::today()->subDays(5)->format('Y-m-d')]);
ok('a genuine back-dated service at a lower reading is still accepted', $st10b, 200);

/**
 * ⚠ These two sections DELIBERATELY record real services, so they tidy up after themselves —
 *   §8 below asserts that the code under test leaves nothing behind, and a section that
 *   knowingly writes has to hand the table back as it found it or that check means nothing.
 */
DB::table('t_fleet_service_log')->where('id', '>', $maxLogIdBefore)->delete();
DB::table('t_ops_rider_profile')->where('user_id', $rider)->update((array) $profileBefore);
flushAll();

head('§8 nothing was left behind');
ok('service-log row count back to where it started',
   DB::table('t_fleet_service_log')->count(), $logCountBefore);
$after = DB::table('t_ops_rider_profile')->where('user_id', $rider)
    ->first(['last_service_meter', 'last_service_at', 'service_interval_km']);
ok('the rider profile is byte-identical', (array) $after, (array) $profileBefore);

echo "\n" . str_repeat('─', 60) . "\n";
echo ($fail === 0 ? "✅" : "❌") . "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
