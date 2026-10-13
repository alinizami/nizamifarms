<?php
/**
 * 🧾 Oct-2026 — Expense Types: rename / inactive / order / group / merge. Proof, end to end.
 *
 *   §1  the engine's lists equal the OLD queries (same scope rule) before anything changes
 *   §2  Petrol / Maintenance are locked (no rename, no inactive, no merge in or out)
 *   §3  rename relabels history + titles + quick chips + account label, PINS the account,
 *       maps the old name on write, and undo puts back exactly what it changed
 *   §4  inactive: gone from every list, refused on write (unless already on the request),
 *       dropped from chips, last-in-scope refused, restore + undo
 *   §5  merge (rename onto an existing name): relabels, old type inactive + mapped, posts to
 *       the target account; cross-scope refused; undo
 *   §6  old names on past expenses: listed, merged in, mapped, undo; never into Petrol
 *   §7  create: duplicate by any case, reserved names, inactive twin → restore, key after rename
 *   §8  order + groups: dropdown follows; ungrouped last; web payload identical
 *   §9  ledger posting: unpinned names post exactly where the old formula did; a renamed
 *       type keeps its account
 *   §10 doors: Taimur only for changes; rider/web write guards; phone list payload;
 *       Request Settings "delete" now turns off
 *
 * Everything runs in ONE transaction that is rolled back at the end (+ cache keys forgotten).
 * Run:  php test_expense_types_oct12.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\FIN\ExpenseTypeService as ET;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
function section(string $t) { echo "\n== $t ==\n"; }
function reqCount(string $name): int { return DB::table('t_req_master')->where('expense_category', $name)->count(); }
function bubbles(): array { return json_decode((string) DB::table('t_fin_config')->where('config_key', 'EXPENSE_BUBBLES__EXPENSE__1')->value('config_value'), true) ?: []; }
function oldSlug(string $n): string { return substr('EXP_' . strtoupper(str_replace([' ', '-', '.', '/', '(', ')'], '_', $n)), 0, 50); }
function idOf(string $name): int { return (int) DB::table('t_fin_config')->where('config_key', 'LIKE', 'EXPENSE\_CATEGORY\_%')->where('config_value', $name)->value('id'); }
function call($controller, string $method, array $input, array $args = [], string $path = '/x', string $verb = 'POST') {
    $r = Request::create($path, $verb, $input);
    $r->headers->set('Accept', 'application/json');
    app()->instance('request', $r);
    $res = $method === 'deleteExpenseSubCategory' ? app($controller)->{$method}(...$args) : app($controller)->{$method}($r, ...$args);
    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
}

$bubbleKeys = DB::table('t_fin_config')->where('config_key', 'LIKE', 'EXPENSE\_BUBBLES%')->pluck('config_key')->all();
register_shutdown_function(function () use ($bubbleKeys) {
    global $pass, $fail;
    while (DB::transactionLevel() > 0) { DB::rollBack(); }
    foreach ($bubbleKeys as $k) { Cache::forget("fin_config_{$k}"); }
    echo "\n-- rolled back --\n\nRESULT: {$pass} passed, {$fail} failed\n";
});

ET::forgetAvailability();
ok('SQL columns present on the replica', ET::available(), true);

$taimur = \App\Models\User::find(68);
$other = \App\Models\User::where('is_active', 1)->get()->first(fn ($u) => !$u->hasMobilePermission('create_expense_category') && $u->id !== 68);
Auth::shouldUse('web'); Auth::guard('web')->setUser($taimur);

DB::beginTransaction();

// ─────────────────────────────────────────────────────────────────────────────
section('§1 the engine lists = the old queries (nothing changed yet)');
$old = function ($bu, $rcc) {
    $q = DB::table('t_fin_config')->where('config_key', 'LIKE', 'EXPENSE_CATEGORY_%')->where('config_key', '!=', 'EXPENSE_CATEGORY_STAFF_SALARIES');
    if ($bu) { if ($bu == 1) $q->where(fn ($w) => $w->where('business_unit_id', 1)->orWhereNull('business_unit_id')); else $q->where('business_unit_id', $bu); }
    if ($rcc) { if (in_array($rcc, ['expense', 'khaas_expense'])) $q->where(fn ($w) => $w->whereNull('request_category_code')->orWhere('request_category_code', $rcc)); else $q->where('request_category_code', $rcc); }
    // Oct-12: a type NAMED Staff Salaries (the Khaas copy) is Payroll's too — every door refuses it.
    $n = array_values(array_filter($q->pluck('config_value')->all(), fn ($v) => strtolower(trim($v)) !== 'staff salaries')); sort($n); return $n;
};
foreach ([[1, 'expense'], [1, 'khaas_expense'], [1, 'qurbani'], [2, 'khaas_expense'], [null, null]] as [$bu, $rcc]) {
    $new = ET::names($bu, $rcc); sort($new);
    $o = $old($bu, $rcc);
    if ($bu === null) { $o = array_values(array_unique($o)); }
    ok("scope bu=" . var_export($bu, true) . " rcc=" . var_export($rcc, true) . ' → same ' . count($o) . ' names', $new, $o);
}
ok('Staff Salaries (BU 1) never offered', in_array('Staff Salaries', ET::names(1, 'expense'), true), false);
ok('…nor in the phone\'s first, unscoped load (the Khaas copy)', in_array('Staff Salaries', ET::names(), true), false);
ok('…nor in the Khaas list', in_array('Staff Salaries', ET::names(2, 'khaas_expense'), true), false);

// ─────────────────────────────────────────────────────────────────────────────
section('§2 Petrol / Maintenance are locked');
$petrol = idOf('Petrol'); $maint = idOf('Maintenance');
ok('rename Petrol refused', ET::rename($petrol, 'Fuel')['ok'], false);
ok('turn off Maintenance refused', ET::setActive($maint, false)['ok'], false);
ok('rename another type TO "petrol" refused', ET::previewRename(idOf('Ice'), 'petrol')['ok'], false);
ok('Petrol still active + same name', [ET::find($petrol)['active'], ET::find($petrol)['name']], [true, 'Petrol']);
ok('group on Petrol is allowed', ET::setGroup($petrol, 'Vehicle')['ok'], true);
ET::setGroup($petrol, '');

// ─────────────────────────────────────────────────────────────────────────────
section('§3 rename relabels everything and pins the account');
$cup = idOf('Cup (Tea)');
$cupBefore = reqCount('Cup (Tea)');
$cupTitles = DB::table('t_req_master')->where('expense_category', 'Cup (Tea)')->where('title', 'Cup (Tea)')->count();
$accBefore = DB::table('t_fin_accounts')->where('account_code', 'EXP_CUP__TEA_')->value('account_name');
ok('replica has Cup (Tea) history + chip', $cupBefore > 0 && in_array('Cup (Tea)', bubbles(), true), true);
$p = ET::previewRename($cup, 'Chai Cups');
ok('preview says rename + exact count', [$p['action'], $p['count']], ['rename', $cupBefore]);
$r = ET::rename($cup, 'Chai Cups');
ok('rename ok', $r['ok'], true);
ok("all {$cupBefore} past expenses relabelled", [reqCount('Chai Cups'), reqCount('Cup (Tea)')], [$cupBefore, 0]);
ok("{$cupTitles} titles that were the name relabelled", DB::table('t_req_master')->where('expense_category', 'Chai Cups')->where('title', 'Chai Cups')->count(), $cupTitles);
ok('quick chip renamed in place', [in_array('Chai Cups', bubbles(), true), in_array('Cup (Tea)', bubbles(), true)], [true, false]);
ok('account PINNED to the old code', ET::find($cup)['account_code'], 'EXP_CUP__TEA_');
ok('posting for the new name → the SAME account', ET::accountCodeFor('Chai Cups', 1), 'EXP_CUP__TEA_');
ok('account label follows', DB::table('t_fin_accounts')->where('account_code', 'EXP_CUP__TEA_')->value('account_name'), 'Expense - Chai Cups');
ok('old name remembered', ET::find($cup)['former'], ['Cup (Tea)']);
ok('old phone sending "Cup (Tea)" → stored as "Chai Cups"', ET::checkForWrite('Cup (Tea)', 1)['name'], 'Chai Cups');
ok('dropdown shows new name only', [in_array('Chai Cups', ET::names(1, 'expense'), true), in_array('Cup (Tea)', ET::names(1, 'expense'), true)], [true, false]);
$u = ET::undo((int) $r['undo_id']);
ok('undo ok', $u['ok'], true);
ok('undo: history back', [reqCount('Cup (Tea)'), reqCount('Chai Cups')], [$cupBefore, 0]);
ok('undo: titles back', DB::table('t_req_master')->where('expense_category', 'Cup (Tea)')->where('title', 'Cup (Tea)')->count(), $cupTitles);
ok('undo: chip back', in_array('Cup (Tea)', bubbles(), true), true);
ok('undo: account label + pin back', [DB::table('t_fin_accounts')->where('account_code', 'EXP_CUP__TEA_')->value('account_name'), ET::find($cup)['account_code'], ET::find($cup)['former']], [$accBefore, null, []]);
ok('undo twice refused', ET::undo((int) $r['undo_id'])['ok'], false);
$r2 = ET::rename($cup, 'Chai Cups');
ok('standing "last change" = that rename', ET::lastUndoable(68)['undo_id'] ?? null, $r2['undo_id']);
$u2 = ET::undo((int) $r2['undo_id']);
ok('undo of a rename tells the form to follow back', $u2['renamed'] ?? null, ['from' => 'Chai Cups', 'to' => 'Cup (Tea)']);
ok('nothing left to undo for that type', (ET::lastUndoable(68)['undo_id'] ?? null) === $r2['undo_id'], false);

// ─────────────────────────────────────────────────────────────────────────────
section('§4 inactive');
$ice = idOf('Ice'); $iceUsed = reqCount('Ice');
$h = ET::setActive($ice, false);
ok('turn off Ice ok', $h['ok'], true);
ok('gone from the dropdown (every scope)', [in_array('Ice', ET::names(1, 'expense'), true), in_array('Ice', ET::names(1, 'khaas_expense'), true), in_array('Ice', ET::names(), true)], [false, false, false]);
ok('gone from the web payload', in_array('Ice', array_column(ET::webPayload(), 'name'), true), false);
ok('history untouched', reqCount('Ice'), $iceUsed);
ok('dropped from the chips', in_array('Ice', bubbles(), true), false);
ok('new expense with Ice refused', ET::checkForWrite('Ice', 1)['ok'], false);
ok('…an edit already carrying Ice is fine', ET::checkForWrite('Ice', 1, 'Ice')['ok'], true);
ok('a request being edited keeps it selectable', in_array('Ice', ET::names(1, 'expense', 'Ice'), true), true);
ok('posting a pending Ice expense still uses EXP_ICE', ET::accountCodeFor('Ice', 1), 'EXP_ICE');
$ml = ET::managerList(1, 'expense');
ok('manager list shows it under Inactive', in_array('Ice', array_column($ml['inactive'], 'name'), true), true);
ET::undo((int) $h['undo_id']);
ok('undo of "turn off" brings it + its chip back', [ET::find($ice)['active'], in_array('Ice', bubbles(), true)], [true, true]);
ET::setActive($ice, false);
ok('turned-off type: chip gone', in_array('Ice', bubbles(), true), false);
ok('turn on (restore) ok', ET::setActive($ice, true)['ok'], true);
ok('turn on puts the chip back in its old place', array_search('Ice', bubbles(), true), 3);
ET::setActive($ice, false); ET::setActive($ice, true);
ok('…also after a second off/on cycle', in_array('Ice', bubbles(), true), true);
ok('back in the dropdown', in_array('Ice', ET::names(1, 'expense'), true), true);
ok('a Khaas-only type can be added (Taimur)', ET::create('ZZ Khaas Only', 2, 'khaas_expense')['ok'], true);
$kOnly = (int) DB::table('t_fin_config')->where('config_value', 'ZZ Khaas Only')->value('id');
$kOff = ET::setActive($kOnly, false);
ok('the last active type of a scope cannot be turned off', [$kOff['ok'], str_contains($kOff['message'], 'last active')], [false, true]);

// ─────────────────────────────────────────────────────────────────────────────
section('§5 merge = rename onto an existing name');
ok('create ZZ Test A', ET::create('ZZ Test A', 1, null)['ok'], true);
ok('create ZZ Test B', ET::create('ZZ Test B', 1, null)['ok'], true);
$a = idOf('ZZ Test A'); $b = idOf('ZZ Test B');
$cat = DB::table('t_req_category')->where('category_code', 'expense')->value('id');
foreach ([1, 2] as $i) {
    DB::table('t_req_master')->insert(['request_number' => 'ZZTEST-' . $i, 'category_id' => $cat, 'requester_user_id' => 68, 'title' => 'ZZ Test A',
        'amount' => 10, 'expense_category' => 'ZZ Test A', 'business_unit_id' => 1, 'status' => 'pending', 'created_by' => 68, 'created_at' => now(), 'updated_at' => now()]);
}
$m = ET::rename($a, 'zz test b');
ok('rename onto existing → asks to merge first', [$m['ok'], $m['needs_merge'] ?? false, $m['count'] ?? null], [false, true, 2]);
ok('nothing changed before confirm', reqCount('ZZ Test A'), 2);
$m = ET::rename($a, 'zz test b', true);
ok('merge ok', $m['ok'], true);
ok('2 expenses now carry the target spelling', DB::table('t_req_master')->where('request_number', 'like', 'ZZTEST-%')->pluck('expense_category')->unique()->values()->all(), ['ZZ Test B']);
ok('A inactive + merged into B', [ET::find($a)['active'], ET::find($a)['merged_into_id']], [false, $b]);
ok('old name maps to B on write', ET::checkForWrite('ZZ Test A', 1)['name'], 'ZZ Test B');
ok('unguarded posting of "ZZ Test A" → B\'s account', ET::accountCodeFor('ZZ Test A', 1), 'EXP_ZZ_TEST_B');
ET::undo((int) $m['undo_id']);
ok('undo merge: A back with its 2 expenses', [ET::find($a)['active'], reqCount('ZZ Test A')], [true, 2]);
$qPack = DB::table('t_fin_config')->where('config_key', 'EXPENSE_CATEGORY_PACKAGING')->value('id');
if ($qPack) {
    ok('Qurbani type cannot merge into a shared one', ET::previewRename((int) $qPack, 'Rent')['ok'], false);
}
ok('nothing merges into Maintenance', ET::previewRename($a, 'Maintenance')['ok'], false);

// ─────────────────────────────────────────────────────────────────────────────
section('§6 old names on past expenses');
$orph = array_column(ET::managerList(1, 'expense')['orphans'], 'name');
ok('"Petrol Taimur" listed as an old name', in_array('Petrol Taimur', $orph, true), true);
ok('PENDING / Staff Salaries never listed', [in_array('PENDING', $orph, true), in_array('Staff Salaries', $orph, true)], [false, false]);
$pt = reqCount('Petrol Taimur'); $ptT = reqCount('Petrol - Taimur');
$o = ET::mergeOrphan('Petrol Taimur', idOf('Petrol - Taimur'), 1);
ok("merge {$pt} old expenses in", [$o['ok'], reqCount('Petrol Taimur'), reqCount('Petrol - Taimur')], [true, 0, $pt + $ptT]);
ok('no longer an old name', in_array('Petrol Taimur', array_column(ET::managerList(1, 'expense')['orphans'], 'name'), true), false);
ok('typed again → stored as the type', ET::checkForWrite('Petrol Taimur', 1)['name'], 'Petrol - Taimur');
ET::undo((int) $o['undo_id']);
ok('undo restores them', reqCount('Petrol Taimur'), $pt);
if (in_array('Fuel', $orph, true)) {
    ok('"Fuel" cannot be merged into Petrol', ET::mergeOrphan('Fuel', $petrol, 1)['ok'], false);
}

// ─────────────────────────────────────────────────────────────────────────────
section('§7 create');
ok('"food" duplicate refused', [ET::create('food', 1)['ok'], ET::create('food', 1)['status']], [false, 400]);
ok('Staff Salaries refused', ET::create('Staff Salaries', 1)['status'], 422);
ok('PENDING refused', ET::create('Pending', 1)['ok'], false);
ET::setActive($ice, false);
$t = ET::create('ICE', 1);
ok('inactive twin → 409 + restore id', [$t['status'], $t['restore_id'] ?? null], [409, $ice]);
ET::setActive($ice, true);
ET::rename(idOf('Sugar'), 'Cheeni');
$c = ET::create('Sugar', 1);
ok('a renamed type\'s old name can be added again (new key)', $c['ok'], true);
ok('…as its own row with a suffixed key', DB::table('t_fin_config')->where('config_value', 'Sugar')->value('config_key'), 'EXPENSE_CATEGORY_SUGAR_2');
ok('new type is pinned to the posting account', ET::find(idOf('Sugar'))['account_code'], oldSlug('Sugar'));

// ─────────────────────────────────────────────────────────────────────────────
section('§8 order + groups');
$roti = idOf('Roti'); $milk = idOf('Milk'); $food = idOf('Food');
ET::setGroup($roti, 'Food items'); ET::setGroup($milk, 'food ITEMS'); ET::setGroup($food, 'Food items');
ok('group spelling reused', ET::find($milk)['group'], 'Food items');
ET::reorder([$milk, $food, $roti]);
$names = ET::names(1, 'expense');
ok('grouped types first, in the saved order', array_slice($names, 0, 3), ['Milk', 'Food', 'Roti']);
$opts = ET::options(1, 'expense');
ok('ungrouped types after (no heading)', $opts[3]['group'], null);
$wp = array_values(array_filter(ET::webPayload(), fn ($t) => ET::inScope(['bu_raw' => $t['bu'], 'bu' => (int) ($t['bu'] ?? 1), 'rcc' => $t['rcc']], 1, 'expense')));
ok('web payload in the same order', array_column($wp, 'name'), $names);

// ─────────────────────────────────────────────────────────────────────────────
section('§9 ledger posting account');
$all = DB::table('t_fin_config')->where('config_key', 'LIKE', 'EXPENSE\_CATEGORY\_%')->whereNull('account_code')->pluck('config_value')->all();
$same = 0;
foreach ($all as $n) { if (ET::accountCodeFor($n, 1) === oldSlug($n)) $same++; }
ok("every unpinned type ({$same}/" . count($all) . ') posts to the old-formula account', $same, count($all));
ok('free text posts to the old-formula account', ET::accountCodeFor('Something New 7', 1), oldSlug('Something New 7'));
ok('Society IN/OUT keeps the "/"-aware posting code', ET::accountCodeFor('Society IN/OUT Charges (Van)', 1), 'EXP_SOCIETY_IN_OUT_CHARGES__VAN_');
$lps = new \App\Services\FIN\LedgerPostingService();
$m2 = new ReflectionMethod($lps, 'getOrCreateExpenseAccount'); $m2->setAccessible(true);
$expRoti = DB::table('t_fin_accounts')->where('account_code', 'EXP_ROTI')->value('id');
ET::rename($roti, 'Roti / Naan');
ok('after rename, posting "Roti / Naan" lands on the EXISTING EXP_ROTI account', (int) $m2->invoke($lps, 'Roti / Naan', 1)->id, (int) $expRoti);
ok('…and no EXP_ROTI___NAAN account was opened', DB::table('t_fin_accounts')->where('account_code', oldSlug('Roti / Naan'))->exists(), false);

// ─────────────────────────────────────────────────────────────────────────────
section('§10 doors');
[$st, $j] = call(\App\Http\Controllers\FIN\ExpenseTypeController::class, 'list', ['business_unit_id' => 1, 'request_category_code' => 'expense', 'manage' => 1], [], '/finance/expense-category/types', 'GET');
ok('list as Taimur: can_manage + types + orphans', [$st, $j['can_manage'], count($j['types']) > 10, isset($j['orphans'])], [200, true, true, true]);
if ($other) {
    Auth::guard('web')->setUser($other);
    [$st, $j] = call(\App\Http\Controllers\FIN\ExpenseTypeController::class, 'rename', ['name' => 'Hacked'], [$ice]);
    ok("rename as {$other->fullname} → 403", $st, 403);
    [$st, $j] = call(\App\Http\Controllers\FIN\ExpenseTypeController::class, 'setActive', ['active' => 0], [$ice]);
    ok('turn off as non-Taimur → 403', $st, 403);
    Auth::guard('web')->setUser($taimur);
}
[$st, $j] = call(\App\Http\Controllers\FIN\ExpenseTypeController::class, 'setActive', ['active' => 0], [$ice]);
ok('turn off via controller as Taimur → 200', [$st, $j['success']], [200, true]);
[$st, $j] = call(\App\Http\Controllers\API\RiderController::class, 'getExpenseCategoriesFromConfig', ['business_unit_id' => 1, 'request_category_code' => 'expense'], [], '/api/rider/expenses/categories', 'GET');
ok('phone list: Ice gone, Roti renamed, groups + can_manage sent', [in_array('Ice', $j['categories'], true), in_array('Roti / Naan', $j['categories'], true), $j['types'][0]['group'] ?? null, $j['can_manage']], [false, true, 'Food items', true]);
ok('phone chips never include an inactive type', in_array('Ice', $j['bubbles'], true), false);
[$st, $j] = call(\App\Http\Controllers\API\RiderController::class, 'createRequest', ['category_id' => $cat, 'title' => 'Ice', 'amount' => 50, 'expense_category' => 'Ice'], [], '/api/rider/requests');
ok('phone: new expense with an inactive type → 422 + Roman Urdu message', [$st, str_contains($j['message'] ?? '', 'band kar diya')], [422, true]);
[$st, $j] = call(\App\Http\Controllers\API\RiderController::class, 'getExpenseCategories', [], [], '/api/rider/ledger/expense-categories', 'GET');
ok('short-cash list (admin): PENDING first, no inactive, no drift names', [$j['categories'][0] ?? null, in_array('Ice', $j['categories'], true), in_array('Petrol Taimur', $j['categories'], true)], ['PENDING', false, false]);
[$st, $j] = call(\App\Http\Controllers\Request\RequestController::class, 'store', ['category_id' => $cat, 'title' => 'Ice', 'amount' => 50, 'expense_category' => 'Ice'], [], '/api/requests/store');
ok('web/API request store: inactive type → 422', $st, 422);
ok('rider claim types untouched by the guard', [ET::checkForWrite('Petrol', 1), ET::checkForWrite('Maintenance', 1)], [['ok' => true, 'name' => 'Petrol', 'message' => null], ['ok' => true, 'name' => 'Maintenance', 'message' => null]]);
ok('PENDING short-cash marker passes the guard', ET::checkForWrite('PENDING', 1)['ok'], true);
$milkRow = $milk;
[$st, $j] = call(\App\Http\Controllers\Request\RequestSettingsController::class, 'deleteExpenseSubCategory', [], [$milkRow], '/requests/settings/expense-subcategories/' . $milkRow, 'DELETE');
ok('Request Settings "delete" turns off, never deletes', [$st, DB::table('t_fin_config')->where('id', $milkRow)->exists(), ET::find($milkRow)['active']], [200, true, false]);
