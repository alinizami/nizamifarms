<?php
/**
 * 🔁 WEEKLY DAYS on a shift — "different on some days" (Oct-13 2026).
 *
 * Proves, against the REAL engine on the local replica, inside ONE transaction that is
 * rolled back at the end:
 *   §1  WeekdayPattern — parse / encode / summary / working days / request checks
 *   §2  Regular + weekly days → every day of a week resolves right (shift, time, place, off)
 *   §3  A one-day cover still beats the weekly day (owner ruling R3)
 *   §4  A screen that sends NO weekly days keeps them (older APK / Shift Types / Attendance popup)
 *   §5  "{}" removes them · history before the change is untouched
 *   §6  The no-op guard: same save twice = one row; one weekday changed = a new row
 *   §7  Date range + weekly days, then back to the regular week; cancel
 *   §8  Bulk: the same weekly days for several people; bulk without them keeps each one's
 *   §9  The gate is asked for EVERY shift type; approval queues + replays the weekly days
 *   §10 A shift type used on a weekly day cannot be deleted
 *   §11 The screens' JSON: web planner grid, phone list, phone month, My Shift, the banner
 *   §12 WhatsApp + push wording (one line, Roman Urdu for the rider)
 *   §13 Stale-row hardening (no-op guard reads the APPLIED row · same-day path closes old rows)
 *
 * ⚠ LOCAL ONLY. Phone pushes and WhatsApp are STUBBED (the dev box holds a real Firebase key
 *   and real staff tokens — see dev-device-verification-setup). Cache is forced to `array`.
 *
 * Run:  php test_shift_weekly_days_oct13.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\Ops\WeekdayPattern as WP;
use App\Services\ShiftResolutionService;

config(['cache.default' => 'array']);

// ── Stubs: nothing may leave this machine ─────────────────────────────────────
$GLOBALS['PUSHES'] = [];
$app->instance(\App\Services\FirebaseService::class, new class {
    public function notifyUser(...$a) { $GLOBALS['PUSHES'][] = $a; return true; }
    public function __call($n, $a) { return true; }
});
$app->instance(\App\Services\WhatsApp\Automation\WhatsAppAutomationService::class, new class {
    public function dispatch(...$a) { return null; }
    public function __call($n, $a) { return null; }
});

$pass = 0; $fail = 0;
function ok(string $what, $got, $want = null, bool $raw = false) {
    global $pass, $fail;
    $good = $raw ? (bool) $got : $got === $want;
    if ($good) { $pass++; echo "  ✓ $what\n"; }
    else { $fail++; echo "  ✗ $what\n";
           if (!$raw) echo "      got:  " . var_export($got, true) . "\n      want: " . var_export($want, true) . "\n"; }
}
function section(string $t) { echo "\n== $t ==\n"; }
function req(array $p, $user, string $method = 'POST', bool $mobile = false): Request {
    if ($method === 'POST' && !array_key_exists('confirm_replace', $p)) $p['confirm_replace'] = 1; // the screens re-send with it after asking
    $r = Request::create('/t', $method, $p);
    $r->setUserResolver(fn () => $user);
    if ($mobile) $r->attributes->set('shift_log_source', 'mobile');
    return $r;
}
function body($resp): array { return json_decode($resp->getContent(), true) ?: []; }
function ctl() { return app(\App\Http\Controllers\Ops\ShiftController::class); }
function fresh(): ShiftResolutionService {
    // Static memos survive between calls in one process — clear them like a new request would.
    (new ShiftResolutionService())->clearAllShiftCaches();
    WP::forgetNames();
    return new ShiftResolutionService();
}
function rowsOf(int $uid) { return DB::table('t_ops_user_shift_assignment')->where('user_id', $uid)->orderBy('id')->get(); }

// ─────────────────────────────────────────────────────────────────────────────
section('§0 people, shift types and places on the replica');
$U = fn ($id) => \App\Models\User::find($id);
$TAIMUR = $U(68); $FAROOQ = $U(74); $SHABIB = $U(79); $HAIDER = $U(71);
$KANAN = $U(76);
ok('Taimur, Farooq, Shabib, Haider, Kanan exist', $TAIMUR && $FAROOQ && $SHABIB && $HAIDER && $KANAN, true);
$T = DB::table('t_ops_shift_template')->pluck('id', 'shift_name')->map(fn ($x) => (int) $x)->all();
$MGR = $T['Manager Shift']; $LAC = $T['LaCarne']; $P11 = $T['Primary 11 AM']; $A12 = $T['Afternoon 12 PM'];
$L = DB::table('t_ops_company_locations')->pluck('id', 'location_name')->map(fn ($x) => (int) $x)->all();
$OFFICE = $L['Nizami Farms Office']; $ORCH = $L['Orchard Lacarne']; $WH = $L['Warehouse'];
$MEETUP = (int) DB::table('t_ops_company_locations')->where('is_handover_point', 1)->where('is_active', 1)->value('id');
ok('Manager Shift has Tuesday off (the week below relies on it)',
   json_decode(DB::table('t_ops_shift_template')->where('id', $MGR)->value('working_days'), true), [1, 3, 4, 5, 6, 7]);
ok('the weekly-days column is on the replica', WP::available() && WP::requestColumnAvailable(), true);

$today = now()->format('Y-m-d');
$mon = \Carbon\Carbon::parse($today)->next(\Carbon\Carbon::MONDAY);          // next week's Monday
$D = []; for ($i = 0; $i < 7; $i++) $D[$i + 1] = $mon->copy()->addDays($i)->format('Y-m-d'); // 1=Mon..7=Sun
echo "  · today {$today} · test week {$D[1]} … {$D[7]}\n";

Auth::setUser($TAIMUR);
DB::beginTransaction();
try {

// ─────────────────────────────────────────────────────────────────────────────
section('§1 WeekdayPattern — the one reader / writer of weekly days');

$wire = ['1' => ['template_id' => $LAC, 'location_id' => $ORCH], '3' => ['template_id' => $LAC, 'location_id' => $ORCH],
         '4' => ['template_id' => $LAC, 'location_id' => $ORCH], '6' => 'off'];
$map = WP::parse(json_encode($wire));
ok('parse keeps 4 weekdays', array_keys($map), [1, 3, 4, 6]);
ok('encode is deterministic + round-trips', WP::parse(WP::encode($map)), $map);
ok('encode puts template_id before location_id (the delete guard LIKE relies on it)',
   str_contains(WP::encode($map), '"template_id":' . $LAC . ',"location_id"'), true);
ok('summary groups days in week order',
   WP::summary($map), 'Mon, Wed, Thu: LaCarne 09:30 @ Orchard Lacarne · Sat: off');
ok('rider wording says chutti', str_ends_with(WP::summary($map, 'ur'), 'Sat: chutti'), true);
ok('working days: Mgr [1,3,4,5,6,7] + LaCarne Mon/Wed/Thu + Sat off', WP::workingDays([1, 3, 4, 5, 6, 7], $map), [1, 3, 4, 5, 7]);
ok('a shift on the row\'s OFF day makes it a working day (R2)',
   WP::workingDays([1, 3, 4, 5, 6, 7], [2 => ['template_id' => $LAC, 'location_id' => null]]), [1, 2, 3, 4, 5, 6, 7]);
ok('garbage is dropped, never guessed', WP::parse('{"9":"off","x":1,"2":{"template_id":0}}'), null);

$r = WP::fromRequest(req(['day_pattern' => json_encode(['2' => ['template_id' => $MGR, 'location_id' => $OFFICE]])], $TAIMUR), $MGR, $OFFICE);
ok('"same shift + place" on the row\'s OFF day is KEPT (it means: work this Tuesday)', array_keys($r['map'] ?? []), [2]);
$r = WP::fromRequest(req(['day_pattern' => json_encode(['1' => ['template_id' => $MGR, 'location_id' => $OFFICE]])], $TAIMUR), $MGR, $OFFICE);
ok('"same shift + place" on a day the row WORKS is dropped (= same as above)', $r['map'], null);
$r = WP::fromRequest(req(['day_pattern' => json_encode(['2' => 'off'])], $TAIMUR), $MGR, $OFFICE);
ok('"off" on a day the row is already off is dropped', $r['map'], null);
$r = WP::fromRequest(req(['day_pattern' => json_encode(['1' => ['template_id' => $LAC, 'location_id' => $MEETUP]])], $TAIMUR), $MGR, $OFFICE);
ok('a van meet-up point is refused as a weekly-day place', str_contains((string) $r['error'], 'Mon') && str_contains((string) $r['error'], 'office'), true);
$all = []; foreach ([1, 3, 4, 5, 6, 7] as $d) $all[(string) $d] = 'off';
$r = WP::fromRequest(req(['day_pattern' => json_encode($all)], $TAIMUR), $MGR, $OFFICE);
ok('every day off is refused', str_contains((string) $r['error'], 'Every day'), true);
DB::table('t_ops_shift_template')->where('id', $A12)->update(['active' => 0]);
$r = WP::fromRequest(req(['day_pattern' => json_encode(['1' => ['template_id' => $A12, 'location_id' => $OFFICE]])], $TAIMUR), $MGR, $OFFICE);
ok('an inactive shift type is refused for a NEW weekly day', str_contains((string) $r['error'], 'not available'), true);
DB::table('t_ops_shift_template')->where('id', $A12)->update(['active' => 1]);
$r = WP::fromRequest(req([], $TAIMUR), $MGR, $OFFICE);
ok('no key at all → given=false (keep)', [$r['given'], $r['map']], [false, null]);
$r = WP::fromRequest(req(['day_pattern' => '{}'], $TAIMUR), $MGR, $OFFICE);
ok('"{}" → given=true, none (remove)', [$r['given'], $r['map']], [true, null]);

// ─────────────────────────────────────────────────────────────────────────────
section('§2 Regular + weekly days → the whole week resolves right');

$PAT = json_encode($wire);
$res = body(ctl()->assignShiftToUser(req(['user_id' => $HAIDER->id, 'shift_template_id' => $MGR, 'mode' => 'until_changed',
    'effective_from' => $today, 'location_id' => $OFFICE, 'day_pattern' => $PAT], $TAIMUR)));
ok('save succeeds', $res['success'] ?? null, true);
$row = DB::table('t_ops_user_shift_assignment')->where('user_id', $HAIDER->id)->whereNull('effective_to')->orderByDesc('id')->first();
ok('ONE open row, from today, Manager Shift @ Office', [(int) $row->shift_template_id, (int) $row->location_id, $row->effective_from], [$MGR, $OFFICE, $today]);
ok('weekly days stored on that row, canonical', $row->day_pattern, WP::encode($map));
ok('his old regular row was closed yesterday',
   DB::table('t_ops_user_shift_assignment')->where('user_id', $HAIDER->id)->whereNull('effective_to')->count(), 1);

$svc = fresh();
$want = [
    1 => ['LaCarne', '09:30', 'Orchard Lacarne', true],
    2 => ['Manager Shift', '11:00', 'Nizami Farms Office', false],
    3 => ['LaCarne', '09:30', 'Orchard Lacarne', true],
    4 => ['LaCarne', '09:30', 'Orchard Lacarne', true],
    5 => ['Manager Shift', '11:00', 'Nizami Farms Office', false],
    6 => ['Manager Shift', '11:00', 'Nizami Farms Office', true],
    7 => ['Manager Shift', '11:00', 'Nizami Farms Office', false],
];
$kinds = [];
foreach ($D as $dow => $date) {
    $s = $svc->getUserShift((int) $HAIDER->id, $date);
    ok("{$date} (" . WP::DAYS[$dow] . ") → {$want[$dow][0]} {$want[$dow][1]} @ {$want[$dow][2]}",
       [$s['shift_name'], $s['shift_start'], $s['location_name'], $s['pattern_day']], $want[$dow]);
    $kinds[$dow] = $svc->dayKind((int) $HAIDER->id, $date);
}
ok('working days the same on every date: [1,3,4,5,7]', $svc->getUserShift((int) $HAIDER->id, $D[2])['working_days'], [1, 3, 4, 5, 7]);
$hol = DB::table('t_ops_public_holidays')->where('is_active', 1)->whereBetween('holiday_date', [$D[1], $D[7]])->count();
ok('dayKind: Tue off (his shift), Sat off (weekly), the rest working' . ($hol ? ' [holiday in week — skipped]' : ''),
   $hol ? true : $kinds, $hol ? true : [1 => 'working', 2 => 'off', 3 => 'working', 4 => 'working', 5 => 'working', 6 => 'off', 7 => 'working']);
ok('shift_id is the DAY\'s shift (attendance snapshot + workshop pin use it)', $svc->getUserShift((int) $HAIDER->id, $D[3])['shift_id'], $LAC);
ok('Mon–Sun of that week counts 5 working days', $hol ? true : $svc->calculateWorkingDays((int) $HAIDER->id, $D[1], $D[7]), $hol ? true : 5);
$log = DB::table('t_ops_shift_assignment_log')->where('user_id', $HAIDER->id)->orderByDesc('id')->first();
ok('the change log names the weekly days', $log->note, 'Weekly days: Mon, Wed, Thu: LaCarne 09:30 @ Orchard Lacarne · Sat: off');
$push = end($GLOBALS['PUSHES']);
ok('the rider push carries the weekly days in Roman Urdu',
   str_contains($push[1]['body'] ?? '', 'Hafte ke din: Mon, Wed, Thu: LaCarne 09:30 @ Orchard Lacarne · Sat: chutti'), true);
ok('…and names the ROW\'s place (the office), not a weekly day\'s', str_contains($push[1]['body'] ?? '', '— Nizami Farms Office'), true);

// ─────────────────────────────────────────────────────────────────────────────
section('§3 A one-day cover still wins its day');

$res = body(ctl()->assignShiftToUser(req(['user_id' => $HAIDER->id, 'shift_template_id' => $P11, 'mode' => 'one_day',
    'effective_from' => $D[3], 'effective_to' => $D[3], 'location_id' => $WH], $TAIMUR)));
ok('one-day cover saved', $res['success'] ?? null, true);
$svc = fresh();
$s = $svc->getUserShift((int) $HAIDER->id, $D[3]);
ok('that Wednesday → Primary 11 AM @ Warehouse, not a weekly day', [$s['shift_name'], $s['location_name'], $s['pattern_day']], ['Primary 11 AM', 'Warehouse', false]);
ok('Thursday is still LaCarne', $svc->getUserShift((int) $HAIDER->id, $D[4])['shift_name'], 'LaCarne');
ok('the following Wednesday is LaCarne again',
   $svc->getUserShift((int) $HAIDER->id, \Carbon\Carbon::parse($D[3])->addWeek()->format('Y-m-d'))['shift_name'], 'LaCarne');
ok('a one-day request that carries weekly days ignores them',
   DB::table('t_ops_user_shift_assignment')->where('user_id', $HAIDER->id)->where('effective_from', $D[3])->value('day_pattern'), null);

// ─────────────────────────────────────────────────────────────────────────────
section('§4 A screen that sends NO weekly days keeps them');

$from4 = \Carbon\Carbon::parse($D[1])->addWeek()->format('Y-m-d');   // Monday after the test week
$res = body(ctl()->assignShiftToUser(req(['user_id' => $HAIDER->id, 'shift_template_id' => $P11, 'mode' => 'until_changed',
    'effective_from' => $from4, 'location_id' => $OFFICE], $TAIMUR)));
ok('regular change from an older screen saves', $res['success'] ?? null, true);
$new = DB::table('t_ops_user_shift_assignment')->where('user_id', $HAIDER->id)->where('effective_from', $from4)->first();
ok('the new row carries the same weekly days', $new->day_pattern, WP::encode($map));
$svc = fresh();
ok('that Monday → LaCarne (kept)', $svc->getUserShift((int) $HAIDER->id, $from4)['shift_name'], 'LaCarne');
ok('that Tuesday → the NEW regular, Primary 11 AM',
   $svc->getUserShift((int) $HAIDER->id, \Carbon\Carbon::parse($from4)->addDay()->format('Y-m-d'))['shift_name'], 'Primary 11 AM');
ok('the test week before it is untouched (Friday still Manager Shift)', $svc->getUserShift((int) $HAIDER->id, $D[5])['shift_name'], 'Manager Shift');
ok('log says kept', str_starts_with((string) DB::table('t_ops_shift_assignment_log')->where('user_id', $HAIDER->id)->orderByDesc('id')->value('note'), 'Weekly days kept:'), true);

// ─────────────────────────────────────────────────────────────────────────────
section('§5 "{}" removes them');

$from5 = \Carbon\Carbon::parse($from4)->addWeek()->format('Y-m-d');
$res = body(ctl()->assignShiftToUser(req(['user_id' => $HAIDER->id, 'shift_template_id' => $P11, 'mode' => 'until_changed',
    'effective_from' => $from5, 'location_id' => $OFFICE, 'day_pattern' => '{}', 'confirm_replace' => 0], $TAIMUR)));
ok('over a change he was already TOLD about, it still asks first (409 question)', [$res['success'] ?? null, $res['needs_confirmation'] ?? null], [false, true]);
$res = body(ctl()->assignShiftToUser(req(['user_id' => $HAIDER->id, 'shift_template_id' => $P11, 'mode' => 'until_changed',
    'effective_from' => $from5, 'location_id' => $OFFICE, 'day_pattern' => '{}'], $TAIMUR)));
ok('saved', $res['success'] ?? null, true);
$svc = fresh();
ok('that Monday → Primary 11 AM (no weekly day)', [$svc->getUserShift((int) $HAIDER->id, $from5)['shift_name'], $svc->getUserShift((int) $HAIDER->id, $from5)['weekly']], ['Primary 11 AM', false]);
ok('the week before still has its weekly Monday', $svc->getUserShift((int) $HAIDER->id, $from4)['shift_name'], 'LaCarne');
ok('log says removed', DB::table('t_ops_shift_assignment_log')->where('user_id', $HAIDER->id)->orderByDesc('id')->value('note'), 'Weekly days removed');
$push = end($GLOBALS['PUSHES']);
ok('the rider push SAYS his weekly days ended', str_contains($push[1]['body'] ?? '', 'Hafte ke alag din khatam'), true);

// ─────────────────────────────────────────────────────────────────────────────
section('§6 The no-op guard');

$n0 = rowsOf((int) $HAIDER->id)->count();
body(ctl()->assignShiftToUser(req(['user_id' => $HAIDER->id, 'shift_template_id' => $P11, 'mode' => 'until_changed',
    'effective_from' => \Carbon\Carbon::parse($from5)->addDays(2)->format('Y-m-d'), 'location_id' => $OFFICE, 'day_pattern' => '{}'], $TAIMUR)));
ok('the same regular shift, place and (no) weekly days again → no new row', rowsOf((int) $HAIDER->id)->count(), $n0);
body(ctl()->assignShiftToUser(req(['user_id' => $HAIDER->id, 'shift_template_id' => $P11, 'mode' => 'until_changed',
    'effective_from' => \Carbon\Carbon::parse($from5)->addDays(2)->format('Y-m-d'), 'location_id' => $OFFICE,
    'day_pattern' => json_encode(['5' => 'off'])], $TAIMUR)));
ok('only the weekly days differ → a new row', rowsOf((int) $HAIDER->id)->count(), $n0 + 1);

// ─────────────────────────────────────────────────────────────────────────────
section('§7 Date range + weekly days');

// Kanan: give her a plain regular first, then a 2-week range with weekly days.
body(ctl()->assignShiftToUser(req(['user_id' => $KANAN->id, 'shift_template_id' => $P11, 'mode' => 'until_changed',
    'effective_from' => $today, 'location_id' => $OFFICE, 'day_pattern' => '{}'], $TAIMUR)));
$r0 = $D[1]; $r1 = \Carbon\Carbon::parse($D[1])->addDays(13)->format('Y-m-d');
$res = body(ctl()->assignShiftToUser(req(['user_id' => $KANAN->id, 'shift_template_id' => $A12, 'mode' => 'date_range',
    'effective_from' => $r0, 'effective_to' => $r1, 'location_id' => $WH,
    'day_pattern' => json_encode(['2' => ['template_id' => $LAC, 'location_id' => $ORCH]])], $TAIMUR)));
ok('range with weekly days saved', $res['success'] ?? null, true);
$svc = fresh();
ok('in range, Tuesday → LaCarne @ Orchard', [$svc->getUserShift((int) $KANAN->id, $D[2])['shift_name'], $svc->getUserShift((int) $KANAN->id, $D[2])['location_name']], ['LaCarne', 'Orchard Lacarne']);
ok('in range, Wednesday → the range shift @ Warehouse', [$svc->getUserShift((int) $KANAN->id, $D[3])['shift_name'], $svc->getUserShift((int) $KANAN->id, $D[3])['location_name']], ['Afternoon 12 PM', 'Warehouse']);
$after = \Carbon\Carbon::parse($r1)->addDays(2)->format('Y-m-d');
ok('after the range → back to her regular, no weekly days', [$svc->getUserShift((int) $KANAN->id, $after)['shift_name'], $svc->getUserShift((int) $KANAN->id, $after)['weekly']], ['Primary 11 AM', false]);
$rangeId = (int) DB::table('t_ops_user_shift_assignment')->where('user_id', $KANAN->id)->where('effective_from', $r0)->where('effective_to', $r1)->value('id');
$res = body(ctl()->cancelShiftChange(req(['assignment_id' => $rangeId], $TAIMUR)));
ok('cancelling the range (not started) removes it with its weekly days', [$res['success'] ?? null, DB::table('t_ops_user_shift_assignment')->where('id', $rangeId)->exists()], [true, false]);
$svc = fresh();
ok('that Tuesday is back to her regular', $svc->getUserShift((int) $KANAN->id, $D[2])['shift_name'], 'Primary 11 AM');

// ─────────────────────────────────────────────────────────────────────────────
section('§8 Bulk');

$res = body(ctl()->bulkAssignShift(req(['user_ids' => [$HAIDER->id, $KANAN->id], 'shift_template_id' => $MGR, 'mode' => 'until_changed',
    'effective_from' => $D[1], 'location_id' => $OFFICE,
    'day_pattern' => json_encode(['4' => ['template_id' => $LAC, 'location_id' => $ORCH]])], $TAIMUR)));
ok('bulk with weekly days saved for both', [$res['success'] ?? null, str_contains($res['message'] ?? '', '2 user')], [true, true]);
$svc = fresh();
ok('both are on LaCarne that Thursday', [$svc->getUserShift((int) $HAIDER->id, $D[4])['shift_name'], $svc->getUserShift((int) $KANAN->id, $D[4])['shift_name']], ['LaCarne', 'LaCarne']);
// Give Haider his own weekly days again, then bulk WITHOUT the key → each keeps his own.
body(ctl()->assignShiftToUser(req(['user_id' => $HAIDER->id, 'shift_template_id' => $MGR, 'mode' => 'until_changed',
    'effective_from' => $D[2], 'location_id' => $OFFICE, 'day_pattern' => $PAT], $TAIMUR)));
$res = body(ctl()->bulkAssignShift(req(['user_ids' => [$HAIDER->id, $KANAN->id], 'shift_template_id' => $P11, 'mode' => 'until_changed',
    'effective_from' => $D[3], 'location_id' => $OFFICE], $TAIMUR)));
ok('bulk from the Shift Types page (no weekly key) saved', $res['success'] ?? null, true);
$svc = fresh();
ok('Haider keeps HIS weekly Thursday (LaCarne) and Saturday off',
   [$svc->getUserShift((int) $HAIDER->id, $D[4])['shift_name'], $svc->dayKind((int) $HAIDER->id, $D[6])], ['LaCarne', $hol ? $svc->dayKind((int) $HAIDER->id, $D[6]) : 'off']);
ok('Kanan keeps HER weekly Thursday (LaCarne)', $svc->getUserShift((int) $KANAN->id, $D[4])['shift_name'], 'LaCarne');
ok('…and both are on the new regular on Friday', [$svc->getUserShift((int) $HAIDER->id, $D[5])['shift_name'], $svc->getUserShift((int) $KANAN->id, $D[5])['shift_name']], ['Primary 11 AM', 'Primary 11 AM']);

// ─────────────────────────────────────────────────────────────────────────────
section('§9 The gate asks about EVERY shift type · approvals replay the weekly days');

DB::table('t_ops_shift_authority')->insert(['user_id' => $HAIDER->id, 'rank' => 0, 'needs_approval' => 0,
    'allowed_template_ids' => json_encode([$MGR]), 'note' => 'test', 'updated_at' => now()]);
app()->forgetInstance(\App\Services\Ops\ShiftAuthorityService::class);
Auth::setUser($SHABIB);
$res = body(ctl()->assignShiftToUser(req(['user_id' => $HAIDER->id, 'shift_template_id' => $MGR, 'mode' => 'until_changed',
    'effective_from' => $D[1], 'location_id' => $OFFICE, 'day_pattern' => $PAT], $SHABIB)));
ok('Shabib may put Haider on Manager Shift, but NOT a weekly LaCarne day (Haider limited to Manager Shift)',
   [$res['success'] ?? null, str_contains($res['message'] ?? '', 'can only be put on')], [false, true]);
$res = body(ctl()->assignShiftToUser(req(['user_id' => $HAIDER->id, 'shift_template_id' => $MGR, 'mode' => 'until_changed',
    'effective_from' => $D[1], 'location_id' => $OFFICE, 'day_pattern' => json_encode(['6' => 'off'])], $SHABIB)));
ok('…an Off weekly day needs no shift type and goes through', $res['success'] ?? null, true);
DB::table('t_ops_shift_authority')->where('user_id', $HAIDER->id)->delete();
app()->forgetInstance(\App\Services\Ops\ShiftAuthorityService::class);

// Farooq (rank 1, needs approval) changed by Shabib (rank 2) → waits for Taimur.
$nF = rowsOf((int) $FAROOQ->id)->count();
$res = body(ctl()->assignShiftToUser(req(['user_id' => $FAROOQ->id, 'shift_template_id' => $MGR, 'mode' => 'until_changed',
    'effective_from' => $D[1], 'location_id' => $OFFICE, 'day_pattern' => $PAT], $SHABIB)));
ok('Shabib → Farooq is SENT for approval, nothing written', [$res['pending'] ?? null, rowsOf((int) $FAROOQ->id)->count()], [true, $nF]);
$reqRow = DB::table('t_ops_shift_change_request')->where('id', $res['request_id'] ?? 0)->first();
ok('the request stores the weekly days verbatim', $reqRow->day_pattern ?? null, WP::encode($map));
$svcR = app(\App\Services\Ops\ShiftChangeRequestService::class);
$pend = collect($svcR->pendingFor($TAIMUR))->firstWhere('id', (int) $reqRow->id);
ok('Taimur\'s approval card SHOWS the weekly days', str_contains($pend['shift_time'] ?? '', '🔁 Mon, Wed, Thu: LaCarne 09:30 @ Orchard Lacarne'), true);
$open = app(\App\Services\Ops\ShiftChangeRequestService::class)->openByUser();
ok('the planner chip for the waiting change shows them too', str_contains(($open[(int) $FAROOQ->id][0]['shift_name'] ?? ''), '🔁'), true);
Auth::setUser($TAIMUR);
$ap = $svcR->approve($TAIMUR, (int) $reqRow->id);
ok('Taimur approves', $ap['ok'], true);
$fr = DB::table('t_ops_user_shift_assignment')->where('user_id', $FAROOQ->id)->where('effective_from', $D[1])->whereNull('effective_to')->first();
ok('the approved row carries the weekly days', $fr->day_pattern ?? null, WP::encode($map));
$res = body(ctl()->assignShiftToUser(req(['user_id' => $FAROOQ->id, 'shift_template_id' => $MGR, 'mode' => 'until_changed',
    'effective_from' => $D[2], 'location_id' => $OFFICE, 'day_pattern' => '{}'], $SHABIB)));
$rq = DB::table('t_ops_shift_change_request')->where('id', $res['request_id'] ?? 0)->first();
ok('"remove weekly days" is queued as {} (not lost as NULL)', $rq->day_pattern ?? null, '{}');
ok('…and the card says so', str_contains(collect($svcR->pendingFor($TAIMUR))->firstWhere('id', (int) $rq->id)['shift_time'] ?? '', 'weekly days removed'), true);
$res = body(ctl()->assignShiftToUser(req(['user_id' => $FAROOQ->id, 'shift_template_id' => $MGR, 'mode' => 'until_changed',
    'effective_from' => $D[3], 'location_id' => $OFFICE], $SHABIB)));
ok('a request from a screen without weekly days stores NULL (keep)', DB::table('t_ops_shift_change_request')->where('id', $res['request_id'] ?? 0)->value('day_pattern'), null);

// ─────────────────────────────────────────────────────────────────────────────
section('§10 A shift type used on a weekly day cannot be deleted');

$newTpl = DB::table('t_ops_shift_template')->insertGetId(['shift_name' => 'ZZ Weekly Test', 'shift_code' => 'ZZWT' . rand(100, 999),
    'shift_start' => '08:00:00', 'working_days' => '[1,2,3,4,5,6,7]', 'is_default' => 0, 'active' => 1,
    'approval_status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
body(ctl()->assignShiftToUser(req(['user_id' => $KANAN->id, 'shift_template_id' => $P11, 'mode' => 'until_changed',
    'effective_from' => $D[6], 'location_id' => $OFFICE,
    'day_pattern' => json_encode(['1' => ['template_id' => $newTpl, 'location_id' => $OFFICE]])], $TAIMUR)));
ok('the new type has no assignment rows of its own', DB::table('t_ops_user_shift_assignment')->where('shift_template_id', $newTpl)->count(), 0);
$res = body(ctl()->destroy(req([], $TAIMUR, 'DELETE'), $newTpl));
ok('…yet deleting it is refused because a weekly day uses it', [$res['success'] ?? null, str_contains($res['message'] ?? '', 'weekly days')], [false, true]);

// ─────────────────────────────────────────────────────────────────────────────
section('§11 What the screens receive');

// Put Haider back on the clean example from today (single regular row in force today).
// (§4–§8 closed today's row when they added later ones — delete those and reopen it.)
DB::table('t_ops_user_shift_assignment')->where('user_id', $HAIDER->id)->where('effective_from', '>', $today)->delete();
DB::table('t_ops_user_shift_assignment')->where('user_id', $HAIDER->id)->where('effective_from', $today)
    ->update(['effective_to' => null, 'shift_template_id' => $MGR, 'location_id' => $OFFICE, 'day_pattern' => WP::encode($map)]);
ok('setup: Haider has exactly one open row, from today',
   DB::table('t_ops_user_shift_assignment')->where('user_id', $HAIDER->id)->whereNull('effective_to')->count(), 1);
fresh();
$wk = body(app(\App\Http\Controllers\Ops\ShiftPlannerController::class)->weekData(req(['start' => $D[1]], $TAIMUR, 'GET')));
$h = collect($wk['riders'] ?? [])->firstWhere('user_id', (int) $HAIDER->id);
ok('web grid: weekly_available', $wk['weekly_available'] ?? null, true);
ok('web grid: Haider\'s regular is Manager Shift @ office', [$h['regular']['shift_name'] ?? null, $h['regular']['location_name'] ?? null], ['Manager Shift', 'Nizami Farms Office']);
ok('web grid: weekly days for the popup prefill', $h['weekly']['days'] ?? null, json_decode(WP::encode($map), true));
ok('web grid: Mon/Wed/Thu/Sat cells are weekly days, the rest not',
   array_map(fn ($c) => $c['pattern_day'], $h['cells'] ?? []), [true, false, true, true, false, true, false]);
ok('web grid: weekly days are NOT drawn as overrides', array_map(fn ($c) => $c['is_override'], $h['cells'] ?? []), array_fill(0, 7, false));

Auth::setUser($TAIMUR);
$rc = app(\App\Http\Controllers\API\RiderController::class);
$ph = body($rc->getStoreShiftRiders(req([], $TAIMUR, 'GET', true)));
$hp = collect($ph['riders'] ?? [])->firstWhere('user_id', (int) $HAIDER->id);
ok('phone list: weekly summary + days + regular', [$hp['weekly']['summary'] ?? null, $hp['regular']['template_id'] ?? null],
   ['Mon, Wed, Thu: LaCarne 09:30 @ Orchard Lacarne · Sat: off', $MGR]);
ok('phone list: weekly_available', $ph['weekly_available'] ?? null, true);
ok('phone list: no workshop / meet-up place is offered', collect($ph['locations'] ?? [])->pluck('id')->contains($MEETUP), false);
$mo = body($rc->getRiderShiftMonth(req(['user_id' => $HAIDER->id, 'month' => substr($D[1], 0, 7)], $TAIMUR, 'GET', true)));
$md = collect($mo['days'] ?? [])->firstWhere('date', $D[1]);
ok('phone month: that Monday is a weekly day at Orchard', [$md['pattern_day'] ?? null, $md['location_name'] ?? null, $md['is_override'] ?? null], [true, 'Orchard Lacarne', false]);

Auth::setUser($HAIDER);
$my = body($rc->getMyShift(req([], $HAIDER, 'GET', true)));
ok('My Shift: has_weekly + 7 days', [$my['has_weekly'] ?? null, count($my['week'] ?? [])], [true, 7]);
$wed = collect($my['week'] ?? [])->first(fn ($d) => $d['dow'] === 'Wed');
ok('My Shift: his Wednesday is LaCarne at Orchard', [$wed['shift_name'] ?? null, $wed['location_name'] ?? null], ['LaCarne', 'Orchard Lacarne']);
DB::table('t_ops_user_shift_assignment')->where('user_id', $HAIDER->id)->whereNull('effective_to')
    ->update(['notified_at' => now(), 'acknowledged_at' => null]);
$pa = body($rc->getPendingShiftAck(req([], $HAIDER, 'GET', true)));
ok('banner: the change he confirms lists the weekly days (Roman Urdu)', $pa['pending_shift_ack']['weekly_summary'] ?? null, 'Mon, Wed, Thu: LaCarne 09:30 @ Orchard Lacarne · Sat: chutti');
// Reminder: does tomorrow differ from today (shift or place)? Compare with the engine itself.
$svc = fresh();
$tom = now()->addDay()->format('Y-m-d');
$a = $svc->getUserShift((int) $HAIDER->id, $today); $b = $svc->getUserShift((int) $HAIDER->id, $tom);
$shouldRemind = in_array((int) now()->addDay()->dayOfWeekIso, $b['working_days'], true)
    && ($a['shift_id'] !== $b['shift_id'] || $a['shift_start'] !== $b['shift_start'] || $a['location_id'] !== $b['location_id']);
$pa = body($rc->getPendingShiftAck(req([], $HAIDER, 'GET', true)));
ok('banner: "kal" reminder fires exactly when tomorrow differs (weekly)', !empty($pa['tomorrow_reminder']), $shouldRemind);

// ─────────────────────────────────────────────────────────────────────────────
section('§12 WhatsApp wording');
$rowH = \App\Models\Ops\UserShiftAssignmentModel::with('shiftTemplate')->where('user_id', $HAIDER->id)->whereNull('effective_to')->first();
$hd = new \App\Services\WhatsApp\Automation\Handlers\ShiftAssignedHandler();
$m = new ReflectionMethod($hd, 'describe'); $m->setAccessible(true);
$txt = $m->invoke($hd, $rowH);
ok('one line (no newline / tab — WhatsApp refuses those)', preg_match('/[\r\n\t]/', $txt) === 0, true);
ok('names the row\'s place and the weekly days', str_contains($txt, 'at Nizami Farms Office') && str_contains($txt, '(weekly days: Mon, Wed, Thu: LaCarne 09:30 @ Orchard Lacarne · Sat: off)'), true);

// ─────────────────────────────────────────────────────────────────────────────
section('§13 Stale-row hardening');
// Recreate Kanan's real situation: several open rows, the newest wins.
DB::table('t_ops_user_shift_assignment')->where('user_id', $KANAN->id)->delete();
$mk = fn ($tpl, $loc, $from) => DB::table('t_ops_user_shift_assignment')->insertGetId(['user_id' => $KANAN->id,
    'shift_template_id' => $tpl, 'location_id' => $loc, 'effective_from' => $from, 'effective_to' => null,
    'created_at' => now(), 'updated_at' => now()]);
$mk($LAC, $ORCH, '2026-08-18'); $mk($P11, $ORCH, '2026-08-31'); $mk($LAC, $L['LaCarne'] ?? $ORCH, '2026-09-24');
$res = body(ctl()->assignShiftToUser(req(['user_id' => $KANAN->id, 'shift_template_id' => $LAC, 'mode' => 'until_changed',
    'effective_from' => $today, 'location_id' => $ORCH], $TAIMUR)));
$svc = fresh();
ok('assigning what a STALE row says is no longer swallowed as a no-op',
   $svc->getUserShift((int) $KANAN->id, $today)['location_name'], 'Orchard Lacarne');
ok('…and every older open row is now closed', DB::table('t_ops_user_shift_assignment')->where('user_id', $KANAN->id)->whereNull('effective_to')->count(), 1);

// ─────────────────────────────────────────────────────────────────────────────
section('§14 Review fixes (independent review, 13-Oct)');

// 14a — a WORKSHOP pin on a weekly day keeps that day's on/off status; only the place moves.
$WS = (int) DB::table('t_ops_company_locations')->where('is_workshop', 1)->value('id');
$hp = array_replace($map, [2 => ['template_id' => $LAC, 'location_id' => $ORCH]]);   // + Tuesday LaCarne (Manager Shift has Tue off)
DB::table('t_ops_user_shift_assignment')->where('user_id', $HAIDER->id)->whereNull('effective_to')->update(['day_pattern' => WP::encode($hp)]);
$wv = app(\App\Services\Riders\WorkshopVisitService::class);
$pinM = new ReflectionMethod($wv, 'applyShiftLocation'); $pinM->setAccessible(true);
fresh();
$pinM->invoke($wv, 990001, (int) $HAIDER->id, $D[6], $WS, null, null);   // Saturday = weekly OFF
$pinM->invoke($wv, 990002, (int) $HAIDER->id, $D[2], $WS, null, null);   // Tuesday = weekly LaCarne on the type's off day
$svc = fresh();
ok('workshop pin on a weekly OFF day: still off (not turned into a working day → no false absence)',
   $hol ? true : $svc->dayKind((int) $HAIDER->id, $D[6]), $hol ? true : 'off');
ok('workshop pin on a weekly shift day whose type has that day off: still WORKING, at the workshop',
   [$hol ? 'working' : $svc->dayKind((int) $HAIDER->id, $D[2]), $svc->getUserShift((int) $HAIDER->id, $D[2])['shift_name'], (int) $svc->getUserShift((int) $HAIDER->id, $D[2])['location_id']],
   ['working', 'LaCarne', $WS]);
// …and nothing changes for somebody without weekly days.
$pinM->invoke($wv, 990003, (int) $FAROOQ->id, \Carbon\Carbon::parse($D[5])->addWeeks(3)->format('Y-m-d'), $WS, null, null);
$fp = DB::table('t_ops_user_shift_assignment')->where('workshop_visit_id', 990003)->first();
ok('workshop pin for someone WITHOUT weekly days writes no weekly days (byte-identical to before)',
   $fp ? ($fp->day_pattern === null || !$svc->getUserShift((int) $FAROOQ->id, $fp->effective_from)['weekly'] ? null : 'x') : null, null);

// 14b — a shift type named only in a WAITING request cannot be deleted either.
$tpl2 = DB::table('t_ops_shift_template')->insertGetId(['shift_name' => 'ZZ Weekly Test 2', 'shift_code' => 'ZZW2' . rand(100, 999),
    'shift_start' => '08:30:00', 'working_days' => '[1,2,3,4,5,6,7]', 'is_default' => 0, 'active' => 1,
    'approval_status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
Auth::setUser($SHABIB);
$res = body(ctl()->assignShiftToUser(req(['user_id' => $FAROOQ->id, 'shift_template_id' => $MGR, 'mode' => 'until_changed',
    'effective_from' => \Carbon\Carbon::parse($D[1])->addWeeks(2)->format('Y-m-d'), 'location_id' => $OFFICE,
    'day_pattern' => json_encode(['3' => ['template_id' => $tpl2, 'location_id' => $OFFICE]])], $SHABIB)));
ok('setup: queued for approval', $res['pending'] ?? null, true);
Auth::setUser($TAIMUR);
$res = body(ctl()->destroy(req([], $TAIMUR, 'DELETE'), $tpl2));
ok('deleting a type that only a waiting request uses is refused', [$res['success'] ?? null, str_contains($res['message'] ?? '', 'weekly days')], [false, true]);

// ─────────────────────────────────────────────────────────────────────────────
section('§16 A closed REGULAR row is not a temporary change (found on the phone, 13-Oct)');
Auth::setUser($TAIMUR);
// Haider: regular Manager Shift from today (§11 setup), then a NEW regular from next Monday.
$nextMon = $D[1];
body(ctl()->assignShiftToUser(req(['user_id' => $HAIDER->id, 'shift_template_id' => $P11, 'mode' => 'until_changed',
    'effective_from' => $nextMon, 'location_id' => $OFFICE], $TAIMUR)));
$closedRow = DB::table('t_ops_user_shift_assignment')->where('user_id', $HAIDER->id)->where('effective_from', $today)->first();
ok('setup: today\'s regular row was closed the day before the new one', (string) $closedRow->effective_to, \Carbon\Carbon::parse($nextMon)->subDay()->format('Y-m-d'));
$svc = fresh();
$cr = $svc->closedRegularIds((int) $HAIDER->id, \App\Models\Ops\UserShiftAssignmentModel::where('user_id', $HAIDER->id)->get());
ok('the closed row is classified as a closed REGULAR (from the audit log)', in_array((int) $closedRow->id, $cr, true), true);
$wk = body(app(\App\Http\Controllers\Ops\ShiftPlannerController::class)->weekData(req(['start' => $today], $TAIMUR, 'GET')));
$h = collect($wk['riders'] ?? [])->firstWhere('user_id', (int) $HAIDER->id);
ok('web grid: this week is NOT painted as temporary changes', array_unique(array_map(fn ($c) => $c['is_override'], $h['cells'] ?? [])), [false]);
ok('web grid: the only listed change is the UPCOMING regular (no cancellable "temporary" for the old row)',
   array_map(fn ($c) => $c['kind'], $h['changes'] ?? []), ['upcoming_primary']);
$mo = body($rc->getRiderShiftMonth(req(['user_id' => $HAIDER->id, 'month' => substr($today, 0, 7)], $TAIMUR, 'GET', true)));
$md = collect($mo['days'] ?? [])->firstWhere('date', $today);
ok('phone month: today is not a "changed" day', $md['is_override'] ?? null, false);
$res = body(ctl()->cancelShiftChange(req(['assignment_id' => $closedRow->id], $TAIMUR)));
ok('cancelling the closed regular row is refused', [$res['success'] ?? null, str_contains($res['message'] ?? '', 'nothing to cancel')], [false, true]);
Auth::setUser($HAIDER);
$my = body($rc->getMyShift(req([], $HAIDER, 'GET', true)));
ok('My Shift: the old regular is not an "upcoming" change to confirm', array_map(fn ($u) => $u['kind'], $my['upcoming'] ?? []), ['primary']);
Auth::setUser($TAIMUR);
// …and a REAL temporary change in the same state still is one.
body(ctl()->assignShiftToUser(req(['user_id' => $HAIDER->id, 'shift_template_id' => $LAC, 'mode' => 'date_range',
    'effective_from' => $D[3], 'effective_to' => $D[4], 'location_id' => $ORCH], $TAIMUR)));
$wk = body(app(\App\Http\Controllers\Ops\ShiftPlannerController::class)->weekData(req(['start' => $D[1]], $TAIMUR, 'GET')));
$h = collect($wk['riders'] ?? [])->firstWhere('user_id', (int) $HAIDER->id);
ok('a real date range still shows as a temporary change with Cancel', in_array('temporary', array_map(fn ($c) => $c['kind'], $h['changes'] ?? []), true), true);
ok('…and paints its two days as overrides', array_map(fn ($c) => $c['is_override'], array_slice($h['cells'] ?? [], 2, 2)), [true, true]);
// Chain rule without the log: delete the log rows and ask again.
DB::table('t_ops_shift_assignment_log')->where('user_id', $HAIDER->id)->delete();
$cr = $svc->closedRegularIds((int) $HAIDER->id, \App\Models\Ops\UserShiftAssignmentModel::where('user_id', $HAIDER->id)->get());
ok('without the audit log the chain rule still classifies the closed row as regular', in_array((int) $closedRow->id, $cr, true), true);
$rangeRow = DB::table('t_ops_user_shift_assignment')->where('user_id', $HAIDER->id)->where('effective_from', $D[3])->where('effective_to', $D[4])->first();
ok('…and the date range as temporary', in_array((int) $rangeRow->id, $cr, true), false);

// ─────────────────────────────────────────────────────────────────────────────
section('§15 A temporary change replaces ONLY the dates it covers (owner, 13-Oct)');
Auth::setUser($TAIMUR);
DB::table('t_ops_user_shift_assignment')->where('user_id', $KANAN->id)->whereNotNull('effective_to')->delete();
$W2 = []; for ($i = 1; $i <= 14; $i++) $W2[$i] = \Carbon\Carbon::parse($D[1])->addDays($i - 1)->format('Y-m-d');   // 14 days from test Monday
// 15a — a 14-day LaCarne range with weekly days, then a one-day Office cover on day 3.
body(ctl()->assignShiftToUser(req(['user_id' => $KANAN->id, 'shift_template_id' => $LAC, 'mode' => 'date_range',
    'effective_from' => $W2[1], 'effective_to' => $W2[14], 'location_id' => $ORCH,
    'day_pattern' => json_encode(['2' => 'off'])], $TAIMUR)));
$rangeId = (int) DB::table('t_ops_user_shift_assignment')->where('user_id', $KANAN->id)->where('effective_from', $W2[1])->value('id');
body(ctl()->assignShiftToUser(req(['user_id' => $KANAN->id, 'shift_template_id' => $P11, 'mode' => 'one_day',
    'effective_from' => $W2[3], 'effective_to' => $W2[3], 'location_id' => $OFFICE], $TAIMUR)));
$svc = fresh();
ok('the range row still exists, untouched', (string) DB::table('t_ops_user_shift_assignment')->where('id', $rangeId)->value('effective_to'), $W2[14]);
ok('day 3 → the one-day cover (Office)', [$svc->getUserShift((int) $KANAN->id, $W2[3])['shift_name'], $svc->getUserShift((int) $KANAN->id, $W2[3])['location_name']], ['Primary 11 AM', 'Nizami Farms Office']);
ok('day 2 and day 4 → still the range (LaCarne @ Orchard)', [$svc->getUserShift((int) $KANAN->id, $W2[4])['shift_name'], $svc->getUserShift((int) $KANAN->id, $W2[4])['location_name']], ['LaCarne', 'Orchard Lacarne']);
ok('the range\'s weekly OFF Tuesday (day 9) still holds', $hol ? true : $svc->dayKind((int) $KANAN->id, $W2[9]), $hol ? true : 'off');
$coverId = (int) DB::table('t_ops_user_shift_assignment')->where('user_id', $KANAN->id)->where('effective_from', $W2[3])->where('effective_to', $W2[3])->value('id');
body(ctl()->cancelShiftChange(req(['assignment_id' => $coverId], $TAIMUR)));
$svc = fresh();
ok('cancelling the cover gives day 3 BACK to the range', $svc->getUserShift((int) $KANAN->id, $W2[3])['shift_name'], 'LaCarne');
// 15b — a new range starting INSIDE the old one: old keeps its head, new wins its own dates.
body(ctl()->assignShiftToUser(req(['user_id' => $KANAN->id, 'shift_template_id' => $A12, 'mode' => 'date_range',
    'effective_from' => $W2[10], 'effective_to' => \Carbon\Carbon::parse($W2[14])->addDays(5)->format('Y-m-d'), 'location_id' => $WH], $TAIMUR)));
$svc = fresh();
ok('old range keeps days 1–9', $svc->getUserShift((int) $KANAN->id, $W2[8])['shift_name'], 'LaCarne');
ok('new range wins days 10–14 (overlap) …', $svc->getUserShift((int) $KANAN->id, $W2[12])['shift_name'], 'Afternoon 12 PM');
ok('… and runs on past the old one', $svc->getUserShift((int) $KANAN->id, \Carbon\Carbon::parse($W2[14])->addDays(3)->format('Y-m-d'))['shift_name'], 'Afternoon 12 PM');
// 15c — an existing range that starts INSIDE the new one is trimmed to start after it.
$lateId = (int) DB::table('t_ops_user_shift_assignment')->where('user_id', $KANAN->id)->where('effective_from', $W2[10])->value('id');
body(ctl()->assignShiftToUser(req(['user_id' => $KANAN->id, 'shift_template_id' => $MGR, 'mode' => 'date_range',
    'effective_from' => $W2[8], 'effective_to' => $W2[12], 'location_id' => $OFFICE], $TAIMUR)));
$svc = fresh();
ok('existing later range is trimmed to start the day after the new one ends', (string) DB::table('t_ops_user_shift_assignment')->where('id', $lateId)->value('effective_from'), $W2[13]);
ok('day 11 → the new range (Manager Shift)', $svc->getUserShift((int) $KANAN->id, $W2[11])['shift_name'], 'Manager Shift');
ok('day 13 → the trimmed range (Afternoon 12 PM)', $svc->getUserShift((int) $KANAN->id, $W2[13])['shift_name'], 'Afternoon 12 PM');
ok('day 7 → the first range (LaCarne)', $svc->getUserShift((int) $KANAN->id, $W2[7])['shift_name'], 'LaCarne');
// 15d — a row fully inside the new dates is removed (nothing of it is left).
$n = DB::table('t_ops_user_shift_assignment')->where('user_id', $KANAN->id)->whereNotNull('effective_to')->count();
body(ctl()->assignShiftToUser(req(['user_id' => $KANAN->id, 'shift_template_id' => $P11, 'mode' => 'date_range',
    'effective_from' => $W2[6], 'effective_to' => $W2[14], 'location_id' => $OFFICE], $TAIMUR)));
ok('the range that sat entirely inside is gone; the first (longer) one stays', [DB::table('t_ops_user_shift_assignment')->where('id', $lateId)->exists() === false
    || (string) DB::table('t_ops_user_shift_assignment')->where('id', $lateId)->value('effective_from') > $W2[14],
    DB::table('t_ops_user_shift_assignment')->where('id', $rangeId)->exists()], [true, true]);

// ─────────────────────────────────────────────────────────────────────────────
section('§17 Second review fixes (13-Oct)');
Auth::setUser($TAIMUR);
DB::table('t_ops_user_shift_assignment')->where('user_id', $KANAN->id)->whereNotNull('effective_to')->delete();
DB::table('t_ops_user_shift_assignment')->where('user_id', $KANAN->id)->whereNull('effective_to')->update(['day_pattern' => null]);
// 17a — a regular change on a day two bounded rows start: the cover must not keep winning that day.
body(ctl()->assignShiftToUser(req(['user_id' => $KANAN->id, 'shift_template_id' => $LAC, 'mode' => 'date_range',
    'effective_from' => $W2[1], 'effective_to' => $W2[5], 'location_id' => $ORCH], $TAIMUR)));
body(ctl()->assignShiftToUser(req(['user_id' => $KANAN->id, 'shift_template_id' => $A12, 'mode' => 'one_day',
    'effective_from' => $W2[1], 'effective_to' => $W2[1], 'location_id' => $WH], $TAIMUR)));
ok('setup: a range and a one-day cover share a start day', DB::table('t_ops_user_shift_assignment')->where('user_id', $KANAN->id)->where('effective_from', $W2[1])->count(), 2);
body(ctl()->assignShiftToUser(req(['user_id' => $KANAN->id, 'shift_template_id' => $MGR, 'mode' => 'until_changed',
    'effective_from' => $W2[1], 'location_id' => $OFFICE], $TAIMUR)));
$svc = fresh();
ok('new regular from that day actually applies on that day (the cover did not shadow it)', $svc->getUserShift((int) $KANAN->id, $W2[1])['shift_name'], 'Manager Shift');
ok('…and only one row starts that day now', DB::table('t_ops_user_shift_assignment')->where('user_id', $KANAN->id)->where('effective_from', $W2[1])->count(), 1);

// 17b — an upcoming regular change must not make this week's one-day cover ask "already told".
$nextMon2 = \Carbon\Carbon::parse($W2[1])->addWeeks(2)->format('Y-m-d');
body(ctl()->assignShiftToUser(req(['user_id' => $KANAN->id, 'shift_template_id' => $P11, 'mode' => 'until_changed',
    'effective_from' => $nextMon2, 'location_id' => $OFFICE], $TAIMUR)));
$res = body(ctl()->assignShiftToUser(req(['user_id' => $KANAN->id, 'shift_template_id' => $LAC, 'mode' => 'one_day',
    'effective_from' => $W2[9], 'effective_to' => $W2[9], 'location_id' => $ORCH, 'confirm_replace' => 0], $TAIMUR)));
if (!($res['success'] ?? false)) {
    $svcD = fresh(); $rowsD = \App\Models\Ops\UserShiftAssignmentModel::where('user_id', $KANAN->id)->get();
    echo "   DIAG closedRegular=" . json_encode($svcD->closedRegularIds((int) $KANAN->id, $rowsD)) . "\n";
    foreach ($rowsD as $x) echo "   row {$x->id} " . ($x->effective_from ? $x->effective_from->format('Y-m-d') : 'null') . '..' . ($x->effective_to ? $x->effective_to->format('Y-m-d') : 'null') . " tpl {$x->shift_template_id}\n";
    foreach (DB::table('t_ops_shift_assignment_log')->where('user_id', $KANAN->id)->where('id', '>', 446)->get() as $l) echo "   log {$l->action} {$l->mode} {$l->effective_from}..{$l->effective_to}\n";
}
ok('a one-day cover before an upcoming regular change is NOT challenged over the closed regular row', [$res['success'] ?? null, $res['message'] ?? null], [true, $res['message'] ?? null]);
// The stamped kind decides; and with the stamp removed (an older row) the chain rule still gets it right
// even though the log holds BOTH a cancelled range and a regular with the very same dates.
$closedReg = DB::table('t_ops_user_shift_assignment')->where('user_id', $KANAN->id)->where('effective_from', $W2[1])->whereNotNull('effective_to')->first();
ok('the closed regular row is stamped is_temporary = 0', (int) $closedReg->is_temporary, 0);
DB::table('t_ops_user_shift_assignment')->where('id', $closedReg->id)->update(['is_temporary' => null]);
$cr = fresh()->closedRegularIds((int) $KANAN->id, \App\Models\Ops\UserShiftAssignmentModel::where('user_id', $KANAN->id)->get());
ok('unstamped + ambiguous log → the chain rule (regular successor next day) still says regular', in_array((int) $closedReg->id, $cr, true), true);
DB::table('t_ops_user_shift_assignment')->where('id', $closedReg->id)->update(['is_temporary' => 0]);

// 17c — a range whose start was trimmed stays a temporary change even when a regular begins right after it.
DB::table('t_ops_user_shift_assignment')->where('user_id', $KANAN->id)->whereNotNull('effective_to')->delete();
DB::table('t_ops_user_shift_assignment')->where('user_id', $KANAN->id)->whereNull('effective_to')->whereDate('effective_from', '>', $today)->delete();
$bEnd = \Carbon\Carbon::parse($W2[14])->addDays(5)->format('Y-m-d');
body(ctl()->assignShiftToUser(req(['user_id' => $KANAN->id, 'shift_template_id' => $A12, 'mode' => 'date_range',
    'effective_from' => $W2[10], 'effective_to' => $bEnd, 'location_id' => $WH], $TAIMUR)));
body(ctl()->assignShiftToUser(req(['user_id' => $KANAN->id, 'shift_template_id' => $MGR, 'mode' => 'date_range',
    'effective_from' => $W2[8], 'effective_to' => $W2[12], 'location_id' => $OFFICE], $TAIMUR)));
$bRow = DB::table('t_ops_user_shift_assignment')->where('user_id', $KANAN->id)->where('effective_to', $bEnd)->first();
ok('setup: the later range was trimmed to start after the new one', (string) $bRow->effective_from, $W2[13]);
body(ctl()->assignShiftToUser(req(['user_id' => $KANAN->id, 'shift_template_id' => $P11, 'mode' => 'until_changed',
    'effective_from' => \Carbon\Carbon::parse($bEnd)->addDay()->format('Y-m-d'), 'location_id' => $OFFICE], $TAIMUR)));
$svc = fresh();
$cr = $svc->closedRegularIds((int) $KANAN->id, \App\Models\Ops\UserShiftAssignmentModel::where('user_id', $KANAN->id)->get());
ok('the trimmed range is still a TEMPORARY change (audit row written on trim)', in_array((int) $bRow->id, $cr, true), false);
$res = body(ctl()->cancelShiftChange(req(['assignment_id' => $bRow->id], $TAIMUR)));
ok('…so it can still be cancelled', $res['success'] ?? null, true);

// 17d — an older range that ENDS inside the new one is trimmed to end the day before (dates stay true).
DB::table('t_ops_user_shift_assignment')->where('user_id', $KANAN->id)->whereNotNull('effective_to')->delete();
body(ctl()->assignShiftToUser(req(['user_id' => $KANAN->id, 'shift_template_id' => $LAC, 'mode' => 'date_range',
    'effective_from' => $W2[1], 'effective_to' => $W2[7], 'location_id' => $ORCH], $TAIMUR)));
$aId = (int) DB::table('t_ops_user_shift_assignment')->where('user_id', $KANAN->id)->where('effective_from', $W2[1])->whereNotNull('effective_to')->value('id');
// 17e — the "already told" question says only PART of it changes.
$res = body(ctl()->assignShiftToUser(req(['user_id' => $KANAN->id, 'shift_template_id' => $A12, 'mode' => 'date_range',
    'effective_from' => $W2[5], 'effective_to' => $W2[10], 'location_id' => $WH, 'confirm_replace' => 0], $TAIMUR)));
ok('the question says only those dates change ("Sirf …")', [$res['needs_confirmation'] ?? null, str_contains($res['message'] ?? '', 'Sirf ')], [true, true]);
body(ctl()->assignShiftToUser(req(['user_id' => $KANAN->id, 'shift_template_id' => $A12, 'mode' => 'date_range',
    'effective_from' => $W2[5], 'effective_to' => $W2[10], 'location_id' => $WH], $TAIMUR)));
ok('the older range now ENDS the day before the new one starts', (string) DB::table('t_ops_user_shift_assignment')->where('id', $aId)->value('effective_to'), $W2[4]);
$svc = fresh();
ok('…answers unchanged: day 4 LaCarne, day 6 Afternoon 12 PM', [$svc->getUserShift((int) $KANAN->id, $W2[4])['shift_name'], $svc->getUserShift((int) $KANAN->id, $W2[6])['shift_name']], ['LaCarne', 'Afternoon 12 PM']);

// 17g — cancelling a cover inside a range names what applies NOW, not "normal shift".
body(ctl()->assignShiftToUser(req(['user_id' => $KANAN->id, 'shift_template_id' => $P11, 'mode' => 'date_range',
    'effective_from' => $today, 'effective_to' => \Carbon\Carbon::parse($today)->addDays(3)->format('Y-m-d'), 'location_id' => $ORCH], $TAIMUR)));
body(ctl()->assignShiftToUser(req(['user_id' => $KANAN->id, 'shift_template_id' => $MGR, 'mode' => 'one_day',
    'effective_from' => $today, 'effective_to' => $today, 'location_id' => $OFFICE], $TAIMUR)));
$cov = (int) DB::table('t_ops_user_shift_assignment')->where('user_id', $KANAN->id)->where('effective_from', $today)->where('effective_to', $today)->value('id');
body(ctl()->cancelShiftChange(req(['assignment_id' => $cov], $TAIMUR)));
$push = end($GLOBALS['PUSHES']);
ok('cancel push names the range that resumes today (Primary 11 AM @ Orchard), not "normal shift"',
   str_contains($push[1]['body'] ?? '', 'ab aap ki shift: Primary 11 AM') && str_contains($push[1]['body'] ?? '', 'Orchard Lacarne'), true);

// 17f — a weekly day on a shift type that was deactivated since: an untouched save still goes through.
// Reopen today's row as his one regular (with the weekly days) and drop everything after it.
DB::table('t_ops_user_shift_assignment')->where('user_id', $HAIDER->id)->whereDate('effective_from', '>', $today)->delete();
DB::table('t_ops_user_shift_assignment')->where('user_id', $HAIDER->id)->where('effective_from', $today)
    ->update(['effective_to' => null, 'day_pattern' => WP::encode($map), 'shift_template_id' => $MGR, 'location_id' => $OFFICE]);
ok('setup: Haider has one open row from today with weekly days', DB::table('t_ops_user_shift_assignment')->where('user_id', $HAIDER->id)->whereNull('effective_to')->count(), 1);
DB::table('t_ops_shift_template')->where('id', $LAC)->update(['active' => 0]);
$withSat = $wire; $withSat['7'] = 'off';
$res = body(ctl()->assignShiftToUser(req(['user_id' => $HAIDER->id, 'shift_template_id' => $MGR, 'mode' => 'until_changed',
    'effective_from' => $today, 'location_id' => $OFFICE, 'day_pattern' => json_encode($withSat)], $TAIMUR)));
ok('changing Sunday while Mon/Wed/Thu keep a now-inactive LaCarne is allowed', [$res['success'] ?? null, $res['message'] ?? null], [true, $res['message'] ?? null]);
$res = body(ctl()->assignShiftToUser(req(['user_id' => $HAIDER->id, 'shift_template_id' => $MGR, 'mode' => 'until_changed',
    'effective_from' => $today, 'location_id' => $OFFICE, 'day_pattern' => json_encode($withSat + ['2' => ['template_id' => $LAC, 'location_id' => $ORCH]])], $TAIMUR)));
ok('…but putting the inactive type on a NEW weekday is refused', [$res['success'] ?? null, str_contains($res['message'] ?? '', 'Tue')], [false, true]);
DB::table('t_ops_shift_template')->where('id', $LAC)->update(['active' => 1]);

} finally {
    DB::rollBack();
}
fresh();
echo "\n" . ($fail ? "❌" : "✅") . " {$pass} passed, {$fail} failed (all writes rolled back; pushes stubbed: " . count($GLOBALS['PUSHES']) . ")\n";
exit($fail ? 1 : 0);
