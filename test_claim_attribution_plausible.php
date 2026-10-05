<?php
/**
 * A CLAIM'S ODOMETER MUST FIT THE MACHINE IT IS ATTRIBUTED TO (Aug-28 2026).
 *
 * The reported symptom: Danish's 30-Aug fill on CEN-455 (meter 17,286) showed
 * "meter vs last fill doesn't add up", even though his 29-Aug fill on the same bike
 * (17,209) sat right before it — 77 km earlier.
 *
 * The cause was not the fill chain. CEN-455 was registered on 22-Aug with Waseem as
 * its first keeper, and `attributionWindows` extends a first keeper's window back to
 * PRE_REGISTRY_FROM so pre-registry history is not lost. That swept every unstamped
 * claim Waseem had ever filed onto CEN-455 — including his DCR-799 fills at
 * 24,588–24,822. Those became CEN-455's fuel chain, so its last plausible anchor sat
 * at 24,822 and every later fill was measured against it and flagged. Permanently:
 * the anchor only advances on a plausible delta, so the series never healed.
 *
 * What these prove:
 *   §1 an impossible odometer no longer attaches to a machine by window guess;
 *   §2 the money is not lost — it lands on the machine it actually fits;
 *   §3 recorded facts (a stamp, a day override) are never second-guessed;
 *   §4 it fails open — a machine with no spine still inherits its keeper's history;
 *   §5 the end result: the chain the user expected.
 *
 * Run:  php test_claim_attribution_plausible.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Riders\VehicleService;
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
function flushAll(): void {
    VehicleService::flushServiceMemo();
    \App\Services\Riders\VehicleResolver::flush();
    try { Cache::flush(); } catch (\Throwable $e) {}
}

const CEN = 10;   // CEN-455 — registered 22-Aug, runs at ~17,2xx
const DCR = 3;    // DCR-799 — Waseem's machine, ~24,1xx-27,5xx in this window
const WASEEM = 73;

$svc = new VehicleService();
flushAll();

/** ids attributed to a machine over the whole year. */
$idsOn = function (int $vid) use ($svc) {
    $out = [];
    foreach ($svc->claimsForVehicle($vid, '2026-01-01', '2026-12-31') as $c) $out[] = (int) $c['id'];
    return $out;
};

head('§1 an impossible odometer does not attach by guess');
// Waseem's real DCR-799 fills, all unstamped, all in the 24,5xx-24,8xx range.
$ORPHANS = DB::table('t_req_master')
    ->whereIn('meter_at_fill', [24588, 24667, 24697, 24822])
    ->where('requester_user_id', WASEEM)
    ->pluck('id')->map(fn ($v) => (int) $v)->all();
ok('found the real prod claims to test with', count($ORPHANS) >= 4, true);

$onCen = $idsOn(CEN);
$stillWrong = array_values(array_intersect($ORPHANS, $onCen));
ok('none of them is attributed to CEN-455 any more', $stillWrong, []);

// And the machine's own spine says why.
ok('24,822 is not a believable CEN-455 reading', $svc->readingPlausibleFor(CEN, 24822), false);
ok('17,209 is', $svc->readingPlausibleFor(CEN, 17209), true);
ok('17,286 is', $svc->readingPlausibleFor(CEN, 17286), true);

head('§2 the money is not lost — it lands where it fits');
$onDcr = $idsOn(DCR);
$missing = array_values(array_diff($ORPHANS, $onDcr));
ok('every orphaned claim is on DCR-799 instead', $missing, []);
ok('…whose own spine accepts that reading', $svc->readingPlausibleFor(DCR, 24822), true);

head('§3 recorded facts are never second-guessed');
// ⚠⚠ EACH CASE IN ITS OWN TRANSACTION, and the plausibility judged BEFORE anything is
//    inserted. A STAMPED claim is evidence: stamping one at 99,999 puts 99,999 into the
//    machine's own spine, after which 99,999 is — correctly — plausible. Testing both
//    cases in one transaction let the first insert move the goalposts for the second.
$ODD = 99999;
ok('before anything is inserted, ' . $ODD . ' is impossible for CEN-455',
   $svc->readingPlausibleFor(CEN, $ODD), false);

$cat = DB::table('t_req_category')->where('category_code', 'expense')->value('id');
$mkClaim = function (?int $vehicleId, $meter, string $cat2, string $num) use ($cat) {
    return DB::table('t_req_master')->insertGetId([
        'request_number' => $num, 'category_id' => $cat, 'requester_user_id' => WASEEM,
        'title' => 'Expense', 'expense_category' => $cat2, 'expense_date' => '2026-08-05',
        'amount' => 1000, 'status' => 'approved', 'meter_at_fill' => $meter,
        'vehicle_id' => $vehicleId, 'created_at' => now(), 'updated_at' => now(),
    ]);
};

// (a) STAMPED — a recorded fact, kept whatever the guess would have said.
DB::beginTransaction();
try {
    $stampedId = $mkClaim(CEN, $ODD, 'Petrol', 'TEST-ATTR-1');
    flushAll();
    ok('a STAMPED claim keeps its machine however odd the reading',
       in_array($stampedId, $idsOn(CEN), true), true);
} finally { DB::rollBack(); flushAll(); }

// (b) UNSTAMPED — the same reading, only a guess behind it, so it is refused.
DB::beginTransaction();
try {
    $looseId = $mkClaim(null, $ODD, 'Petrol', 'TEST-ATTR-2');
    flushAll();
    ok('an UNSTAMPED claim with the same reading is refused',
       in_array($looseId, $idsOn(CEN), true), false);
} finally { DB::rollBack(); flushAll(); }

// (c) no odometer at all — nothing to judge, so it is kept.
DB::beginTransaction();
try {
    $noMeterId = $mkClaim(null, null, 'Maintenance', 'TEST-ATTR-3');
    flushAll();
    ok('a claim with no odometer is still attributed (nothing to judge)',
       in_array($noMeterId, $idsOn(CEN), true), true);
} finally { DB::rollBack(); flushAll(); }

head('§4 it fails open — a machine with no spine keeps its history');
// Every machine on this database happens to have evidence, so the fail-open branch is
// exercised deliberately rather than hoped for: a brand-new bike, no readings, no
// claims — it must accept anything, or registering a machine would erase the history
// its first keeper brings with him.
DB::beginTransaction();
try {
    $newVid = (int) DB::table('t_ops_vehicle')->insertGetId([
        'vtype' => 'bike', 'reg_no' => 'TEST-NEW-1', 'is_company' => 1, 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    flushAll();
    ok('a brand-new machine has no spine', $svc->currentMeterFor($newVid), null);
    ok('  …so Rule P accepts any reading', $svc->readingPlausibleFor($newVid, 999999), true);
    ok('  …and a wild one too', $svc->readingPlausibleFor($newVid, 1), true);

    // Give it a keeper whose unstamped history is in a completely different range:
    // with no spine of its own it must still inherit, exactly as before this rule.
    DB::table(VehicleService::T_ASSIGN)->insert([
        'vehicle_id' => $newVid, 'user_id' => WASEEM, 'assigned_on' => '2026-08-20',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    flushAll();
    $inherited = 0;
    foreach ($svc->claimsForVehicle($newVid, '2026-01-01', '2026-12-31') as $c) {
        if (($c['meter'] ?? null) !== null) $inherited++;
    }
    ok('  …and still inherits its keeper\'s metered history', $inherited > 0, true);
} finally { DB::rollBack(); flushAll(); }
head('§5 every machine now holds a coherent odometer range');
$bad = [];
foreach (DB::table('t_ops_vehicle')->where('is_active', 1)->get(['id', 'reg_no', 'nickname']) as $v) {
    foreach ($svc->claimsForVehicle((int) $v->id, '2026-01-01', '2026-12-31') as $c) {
        $m = $c['meter'] ?? null;
        if ($m === null || !empty($c['stamped'])) continue;   // stamped rows are facts
        if (!$svc->readingPlausibleFor((int) $v->id, (int) $m)) {
            $bad[] = ($v->reg_no ?: $v->nickname) . ' <- ' . $m;
        }
    }
}
ok('no machine carries an impossible unstamped claim any more', $bad, []);

// The specific outcome the owner asked for.
// ⚠ 5-Oct-2026: these were literal 28-Aug numbers ("within 2,000 km", "≤ 20,000") and the bike
//   has since honestly run to 21k — and a stamped claim of 0 exists (a recorded fact). What must
//   hold for ever is the RULE: no UNSTAMPED guess on CEN-455 from another machine's odometer,
//   and the 5-Oct regression (the spine grew, Waseem's 24k fills came back) stays shut.
$cenGuessed = [];
foreach ($svc->claimsForVehicle(CEN, '2026-01-01', '2026-12-31') as $c) {
    if (($c['meter'] ?? null) !== null && empty($c['stamped'])) $cenGuessed[] = (int) $c['meter'];
}
ok('CEN-455 carries no unstamped reading from DCR-799\'s 24k range',
   array_values(array_filter($cenGuessed, fn ($m) => $m >= 24000)), []);
ok('  …and Waseem\'s 1-Aug 24,153 cannot attach by date (odometer never runs backwards)',
   $svc->readingPlausibleFor(CEN, 24153, '2026-08-01'), false);
ok('  …while today\'s real 21,459 still can',
   $svc->readingPlausibleFor(CEN, 21459, date('Y-m-d')), true);

echo "\n" . str_repeat('─', 60) . "\n";
echo ($fail === 0 ? "ALL GREEN" : "FAILURES") . " — passed {$pass}, failed {$fail}\n";
exit($fail === 0 ? 0 : 1);
