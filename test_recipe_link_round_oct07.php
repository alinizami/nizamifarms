<?php
/**
 * 🔗🧾 RECIPE LINK ROUND (Oct-7 2026) — what Qasim's two screenshots were about.
 *
 *   §1 ONE active-tag rule: a product switched OFF borrows no older-bill price
 *      (Tazo product 58 is off on prod since 6-Oct, yet Cheese still read "Rs 2,150 · Tazo").
 *   §2 Earlier bills of a product tagged TODAY: preview says what would move, in numbers
 *      and in Roman Urdu; apply stamps exactly those lines; the recipe then prices from
 *      the newer bill (the 3-Oct Imtiaz Puck Cream Cheese, once its 2023 date is fixed).
 *   §3 A tag MOVED from one ingredient to another offers the lines stamped with the old one.
 *   §4 A line typed in another unit than the product's is never guessed at.
 *   §5 The two doors: count-past-bills / unlink-ingredient — who may, what they say.
 *   §6 The product save itself carries the offer (`recount`), and not when the tag is unchanged.
 *   §7 Month Review: one-time cost shown as a divider, still out of the all-in figure.
 *
 * Everything runs inside one transaction that is rolled back. Nothing is left behind.
 * Run:  php test_recipe_link_round_oct07.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\FIN\VendorProductController;
use App\Models\FIN\VendorProductModel;
use App\Services\Khaas\FrozenMonthService;
use App\Services\Khaas\IngredientPriceService;
use App\Services\Khaas\PurchaseLineRestamp;
use App\Services\Khaas\RecipeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
function call(string $method, string $url, array $body, string $action, ...$args): array {
    $req = Request::create($url, $method, $body);
    $req->headers->set('Accept', 'application/json');
    $res = (new VendorProductController())->$action($req, ...$args);
    return ['status' => $res->getStatusCode(), 'body' => json_decode($res->getContent(), true)];
}

const BU = 2; const CHEESE = 23; const TAZO_PRODUCT = 58; const IMTIAZ = 50; const PUCK_1000 = 81;
const BILL_2023 = 23681; const PUCK_LINE = 3620;

Auth::shouldUse('web'); Auth::loginUsingId(91); // Qasim — khaas role
$prices = new IngredientPriceService();

DB::beginTransaction();
try {
    // The replica (5-Oct) still has the Tazo product ON and tagged; prod switched it off on 6-Oct.
    DB::table('t_fin_vendor_products')->where('id', TAZO_PRODUCT)->update(['is_active' => 1, 'ingredient_id' => CHEESE]);
    // And the Imtiaz bill is still dated 2023 — exactly the prod state before the SQL fix.
    DB::table('t_fin_ledger')->where('id', BILL_2023)->update(['transaction_date' => '2023-10-03']);
    DB::table('t_fin_vendor_products')->where('id', PUCK_1000)->update(['ingredient_id' => null, 'pack_qty_base' => null]);
    DB::table('t_fin_vendor_purchase_items')->where('id', PUCK_LINE)->update(['ingredient_id' => null, 'qty_base' => null]);

    head('§1 a product switched OFF borrows no older-bill price');
    $p = $prices->latest(BU, [CHEESE]);
    ok('with the Tazo product ON, Cheese prices from its July bill (older_bill, Tazo)',
        [$p[CHEESE]['source'] ?? null, $p[CHEESE]['vendor_name'] ?? null, $p[CHEESE]['bought_on'] ?? null],
        ['older_bill', 'Tazo cheese', '2026-07-12']);
    ok('  …at Rs 2,150 a kg (Rs 860 / 400 g)', $p[CHEESE]['price_text'] ?? null, 'Rs 2,150 a kg');

    DB::table('t_fin_vendor_products')->where('id', TAZO_PRODUCT)->update(['is_active' => 0]);   // what Qasim did on 6-Oct
    $p = $prices->latest(BU, [CHEESE]);
    ok('with it OFF (tag still there, as on prod), Cheese has NO price — nothing is borrowed', isset($p[CHEESE]), false);
    $facts = (new RecipeService())->lineFacts([CHEESE]);
    ok('  …and the recipe line facts agree: not in "Linked now", no last-bought date',
        [$facts[CHEESE]['linked_products'], $facts[CHEESE]['last_bought_on']], [0, null]);

    head('§2 the newer Imtiaz bill, once dated right and the product tagged');
    DB::table('t_fin_ledger')->where('id', BILL_2023)->update(['transaction_date' => '2026-10-03']);  // the prod SQL fix
    $p = $prices->latest(BU, [CHEESE]);
    ok('date fixed but product still untagged / line unstamped: still no Cheese price', isset($p[CHEESE]), false);

    $r = call('PUT', '/api/vendors/' . IMTIAZ . '/products/' . PUCK_1000,
        ['product_name' => 'Puck Cream Cheese 1000g', 'unit' => 'kg', 'rate_per_unit' => 4118, 'ingredient_id' => CHEESE],
        'update', IMTIAZ, PUCK_1000);
    ok('Qasim links the product to Cheese (PUT update) — 200', $r['status'], 200);
    $rc = $r['body']['recount'] ?? null;
    ok('  …the save carries the OFFER: 1 earlier line, Rs 2,059, dated 3-Oct',
        [$rc['uncounted']['n'] ?? null, (float) ($rc['uncounted']['total'] ?? 0), $rc['uncounted']['first'] ?? null, (array_key_exists('moved', $rc ?? []) ? $rc['moved'] : 'x')],
        [1, 2059.0, '2026-10-03', null]);
    ok('  …named for the person: product + ingredient',
        [$rc['product_name'] ?? null, $rc['ingredient_name'] ?? null], ['Puck Cream Cheese 1000g', 'Cheese']);
    ok('  …in Roman Urdu with the numbers in it',
        str_contains($rc['message'] ?? '', 'Puck Cream Cheese 1000g ke 1 purane bill (3 Oct, Rs 2,059) abhi Cheese mein nahi gin rahe'), true);
    $p = $prices->latest(BU, [CHEESE]);
    ok('  …but the tag alone changes nothing: still no Cheese price (post-22-Sep line is never borrowed)', isset($p[CHEESE]), false);

    $r = call('POST', '/api/vendors/' . IMTIAZ . '/products/' . PUCK_1000 . '/count-past-bills', [], 'countPastBills', IMTIAZ, PUCK_1000);
    ok('"Haan, gin lo" — count-past-bills 200, 1 line stamped', [$r['status'], $r['body']['done'] ?? null], [200, ['uncounted' => 1, 'moved' => 0]]);
    ok('  …says so in Roman Urdu', str_contains($r['body']['message'] ?? '', '1 purane bill ab Cheese mein gin rahe hain'), true);
    $line = DB::table('t_fin_vendor_purchase_items')->where('id', PUCK_LINE)->first();
    ok('  …the line now carries Cheese and 500 g (0.5 kg × 1000)', [(int) $line->ingredient_id, (float) $line->qty_base], [CHEESE, 500.0]);
    $p = $prices->latest(BU, [CHEESE]);
    ok('  …and Cheese prices from the Imtiaz bill of 3-Oct: a stamped "bill", Rs 4,118 a kg',
        [$p[CHEESE]['source'] ?? null, $p[CHEESE]['vendor_name'] ?? null, $p[CHEESE]['bought_on'] ?? null, $p[CHEESE]['price_text'] ?? null],
        ['bill', 'Imtiaz Store frozen', '2026-10-03', 'Rs 4,118 a kg']);
    $facts = (new RecipeService())->lineFacts([CHEESE]);
    ok('  …the recipe line facts: 1 linked product, last bought 3-Oct', [$facts[CHEESE]['linked_products'], $facts[CHEESE]['last_bought_on']], [1, '2026-10-03']);

    $r = call('POST', '/api/vendors/' . IMTIAZ . '/products/' . PUCK_1000 . '/count-past-bills', [], 'countPastBills', IMTIAZ, PUCK_1000);
    ok('a second "Haan" finds nothing left to count (idempotent)', [$r['status'], $r['body']['done'] ?? null], [200, ['uncounted' => 0, 'moved' => 0]]);

    head('§3 a tag MOVED offers the lines stamped with the old ingredient');
    $capsicum = VendorProductModel::find(91);  // Imtiaz Capsicum, tagged 18, line 3616 stamped 18
    $stamped18 = DB::table('t_fin_vendor_purchase_items')->where('vendor_product_id', 91)->where('ingredient_id', 18)->count();
    ok('setup: Capsicum has lines stamped with Capsicum (18)', $stamped18 > 0, true);
    $capsicum->update(['ingredient_id' => 12]);  // moved to Green Onion by mistake
    $pv = (new PurchaseLineRestamp())->preview($capsicum->fresh(), 18);
    ok('preview after the move: nothing uncounted, but the old-ingredient lines are offered',
        [$pv['uncounted']['n'] ?? null, $pv['moved']['n'] ?? null, $pv['moved']['from_name'] ?? null],
        [0, $stamped18, 'Capsicum (Shimla Mirch)']);
    ok('  …message names both', str_contains($pv['message'], 'abhi Capsicum (Shimla Mirch) mein ginay hain') && str_contains($pv['message'], 'Green Onion'), true);
    $r = call('POST', '/api/vendors/' . IMTIAZ . '/products/91/count-past-bills', ['from_ingredient_id' => 18, 'include_moved' => 0], 'countPastBills', IMTIAZ, 91);
    ok('include_moved=0 moves nothing', $r['body']['done'] ?? null, ['uncounted' => 0, 'moved' => 0]);
    $r = call('POST', '/api/vendors/' . IMTIAZ . '/products/91/count-past-bills', ['from_ingredient_id' => 18, 'include_moved' => 1], 'countPastBills', IMTIAZ, 91);
    ok('include_moved=1 moves exactly those lines', $r['body']['done'] ?? null, ['uncounted' => 0, 'moved' => $stamped18]);
    ok('  …now stamped with the new ingredient', DB::table('t_fin_vendor_purchase_items')->where('vendor_product_id', 91)->where('ingredient_id', 12)->count(), $stamped18);
    $pv = (new PurchaseLineRestamp())->preview($capsicum->fresh(), 12);
    ok('the save that does NOT change the tag has nothing to offer', $pv, null);

    head('§4 a line in another unit is never guessed at');
    DB::table('t_fin_vendor_purchase_items')->where('id', PUCK_LINE)->update(['ingredient_id' => null, 'qty_base' => null, 'unit' => 'pack']);
    $pv = (new PurchaseLineRestamp())->preview(VendorProductModel::find(PUCK_1000), null);
    ok('a "pack" line on a kg product: nothing to offer', $pv, null);
    DB::table('t_fin_vendor_purchase_items')->where('id', PUCK_LINE)->update(['unit' => 'kg']);
    $pv = (new PurchaseLineRestamp())->preview(VendorProductModel::find(PUCK_1000), null);
    ok('  …back in kg: offered again', $pv['uncounted']['n'] ?? null, 1);

    head('§5 the two doors');
    $r = call('POST', '/api/vendors/' . IMTIAZ . '/products/' . PUCK_1000 . '/unlink-ingredient', [], 'unlinkIngredient', IMTIAZ, PUCK_1000);
    ok('"Remove" — unlink 200', $r['status'], 200);
    $vp = VendorProductModel::find(PUCK_1000);
    ok('  …tag and size cleared, name / unit / rate / active untouched',
        [$vp->ingredient_id, $vp->pack_qty_base, $vp->product_name, $vp->unit, (float) $vp->rate_per_unit, (bool) $vp->is_active],
        [null, null, 'Puck Cream Cheese 1000g', 'kg', 4118.0, true]);
    ok('  …says what changes and what does not (Roman Urdu)',
        str_contains($r['body']['message'], 'Link hat gaya') && str_contains($r['body']['message'], 'Purane bills jaise the waise hi rahenge'), true);
    $audit = DB::table('t_sys_audit_log')->where('entity_type', 'vendor_product')->where('entity_id', PUCK_1000)->where('action', 'ingredient_unlinked')->count();
    ok('  …audit row written', $audit >= 1, true);
    $r = call('POST', '/api/vendors/' . IMTIAZ . '/products/' . PUCK_1000 . '/unlink-ingredient', [], 'unlinkIngredient', IMTIAZ, PUCK_1000);
    ok('unlinking an unlinked product is a calm 200, not an error', [$r['status'], $r['body']['success']], [200, true]);
    $r = call('POST', '/api/vendors/' . IMTIAZ . '/products/' . PUCK_1000 . '/count-past-bills', [], 'countPastBills', IMTIAZ, PUCK_1000);
    ok('count-past-bills on an untagged product is refused with a sentence (422)', [$r['status'], str_contains($r['body']['message'], 'linked nahi hai')], [422, true]);
    $r = call('POST', '/api/vendors/' . IMTIAZ . '/products/999999/count-past-bills', [], 'countPastBills', IMTIAZ, PUCK_1000);
    ok('a product of another vendor is a 404 (scoped by vendor)', (function () {
        try { call('POST', '/api/vendors/' . IMTIAZ . '/products/' . TAZO_PRODUCT . '/count-past-bills', [], 'countPastBills', IMTIAZ, TAZO_PRODUCT); return 'no'; }
        catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) { return '404'; }
    })(), '404');

    // Someone with neither key: find one among active users.
    $nobody = null;
    foreach (\App\Models\User::query()->orderBy('id')->limit(80)->get() as $u) {
        if (!$u->hasMobilePermission('manage_vendor_transactions') && !$u->hasMobilePermission('access_khaas_mode')) { $nobody = $u; break; }
    }
    if ($nobody) {
        Auth::loginUsingId($nobody->id);
        $r = call('POST', '/api/vendors/' . IMTIAZ . '/products/91/count-past-bills', [], 'countPastBills', IMTIAZ, 91);
        ok("user {$nobody->id} (neither key): count-past-bills 403", $r['status'], 403);
        $r = call('POST', '/api/vendors/' . IMTIAZ . '/products/91/unlink-ingredient', [], 'unlinkIngredient', IMTIAZ, 91);
        ok("user {$nobody->id} (neither key): unlink 403", $r['status'], 403);
        Auth::loginUsingId(91);
    } else {
        ok('(no user without both keys found in the first 80 — gate not exercised)', true, true);
    }

    head('§6 the product save carries the offer only when the tag changes');
    $r = call('PUT', '/api/vendors/' . IMTIAZ . '/products/' . PUCK_1000,
        ['product_name' => 'Puck Cream Cheese 1000g', 'unit' => 'kg', 'rate_per_unit' => 4118, 'ingredient_id' => CHEESE], 'update', IMTIAZ, PUCK_1000);
    ok('tag set → recount present', ($r['body']['recount']['uncounted']['n'] ?? null), 1);
    $r = call('PUT', '/api/vendors/' . IMTIAZ . '/products/' . PUCK_1000,
        ['product_name' => 'Puck Cream Cheese 1000g', 'unit' => 'kg', 'rate_per_unit' => 4200, 'ingredient_id' => CHEESE], 'update', IMTIAZ, PUCK_1000);
    ok('same tag, only the rate edited → recount null (nothing re-asked)', (array_key_exists('recount', $r['body']) ? $r['body']['recount'] : 'missing'), null);
    $r = call('PUT', '/api/vendors/' . IMTIAZ . '/products/' . PUCK_1000,
        ['product_name' => 'Puck Cream Cheese 1000g', 'unit' => 'kg', 'rate_per_unit' => 4200], 'update', IMTIAZ, PUCK_1000);
    ok('tag absent from the body (phone edit of the name) → tag kept, recount null', [$r['body']['product']['ingredient_id'] ?? null, (array_key_exists('recount', $r['body']) ? $r['body']['recount'] : 'missing')], [CHEESE, null]);
    $r = call('POST', '/api/vendors/' . IMTIAZ . '/products',
        ['product_name' => 'Test Cheese Block', 'unit' => 'kg', 'rate_per_unit' => 100, 'ingredient_id' => CHEESE], 'store', IMTIAZ);
    ok('a NEW tagged product: recount key present and null (no bills yet)', [array_key_exists('recount', $r['body']), $r['body']['recount']], [true, null]);

    head('§7 Month Review: one-time cost as a divider, out of the all-in figure');
    $mr = (new FrozenMonthService())->monthReview(BU, '2026-09');
    $h  = $mr['headline'];
    $made = (int) $h['made'];
    ok('September has packs made', $made > 0, true);
    ok('one_time_per_pack = one_time / made', $h['one_time_per_pack'], round($h['one_time'] / max(1, $made), 2));
    ok('all_in_per_pack still EXCLUDES one-time', $h['all_in_per_pack'], round(($h['product'] + $h['fixed']) / max(1, $made), 2));
    ok('all_in_with_one_time_per_pack = (product + fixed + one_time) / made',
        $h['all_in_with_one_time_per_pack'], round(($h['product'] + $h['fixed'] + $h['one_time']) / max(1, $made), 2));
    printf("     Sep-2026: made %d · product Rs %s · fixed Rs %s · one-time Rs %s → %s / %s / %s a pack\n",
        $made, number_format($h['product']), number_format($h['fixed']), number_format($h['one_time']),
        $h['product_per_pack'], $h['fixed_per_pack'], $h['one_time_per_pack']);
} finally {
    DB::rollBack();
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
