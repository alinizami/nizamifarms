<?php
/**
 * PROOF — the unit-lock dead end now points at Replace, and grams ↔ ml amounts are suggested (Oct-18).
 * Run: php test_ingredient_replace_door_oct18.php
 *
 * Qasim's Cooking Oil (ingredient 24, grams; 1 bill, 5 batches, 4 current recipes): every
 * "cannot change its unit" refusal used to say "add a new product" — and the one-unit rule
 * then refused that product. Now every refusal names "Replace with…", the product form's
 * mismatch carries a `replace` hint, the Replace preview suggests 1 g ≈ 1 ml amounts, and
 * the replace response returns the NEW ingredient (`to`).
 *
 * Reads the dev replica (synced Oct-18). Writes are inside a transaction that is ROLLED BACK.
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

use App\Models\Khaas\IngredientModel;
use App\Models\FIN\VendorProductModel;
use App\Services\Khaas\IngredientReplaceService;
use App\Services\Khaas\IngredientUnitChangeService;
use App\Services\Khaas\RecipeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

$pass = 0; $fail = 0;
function ok($cond, $label, $extra = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; } else { $fail++; echo "  ✗ $label" . ($extra !== '' ? "  → $extra" : '') . "\n"; }
}
function call(string $method, string $uri, array $data = []): array {
    global $kernel;
    $req = Request::create($uri, $method, $data, [], [], ['HTTP_ACCEPT' => 'application/json']);
    $res = $kernel->handle($req);
    $body = json_decode($res->getContent(), true);
    return [$res->getStatusCode(), is_array($body) ? $body : ['_raw' => mb_substr((string) $res->getContent(), 0, 300)]];
}

$oil = IngredientModel::find(24);
ok($oil && $oil->base_unit === 'g', 'Cooking Oil (24) is counted in grams on the replica');
$p130 = VendorProductModel::find(130);
ok($p130 && $p130->unit === 'litre' && !$p130->ingredient_id, 'Canola Cooking Oil (130) is a litre product with NO link — what Qasim was left with');

// ── 1. every refusal names the Replace door ──────────────────────────────────
echo "1. the refusals\n";
$msg = '';
try { (new IngredientUnitChangeService())->apply($oil, 'ml', [], [], [], 91); } catch (\InvalidArgumentException $e) { $msg = $e->getMessage(); }
ok(str_contains($msg, 'cannot change its unit') && str_contains($msg, 'Replace with…') && str_contains($msg, 'counted in volume'), 'change-unit refusal: locked, and says Replace with… in volume', $msg);
ok(!str_contains($msg, 'Add a new product'), '…and no longer says "Add a new product"');

$msg = '';
DB::beginTransaction();
try { (new RecipeService())->saveIngredient(['id' => 24, 'name' => 'Cooking Oil', 'base_unit' => 'ml'], 91, 2); } catch (\InvalidArgumentException $e) { $msg = $e->getMessage(); }
DB::rollBack();
ok(str_contains($msg, 'unit cannot change') && str_contains($msg, 'Replace with…'), 'plain ingredient save with a new unit: refused, says Replace with…', $msg);

// the product form's mismatch (litre product → grams ingredient), as the phone/web receive it
$ctrl = new \App\Http\Controllers\FIN\VendorProductController();
$mm = new ReflectionMethod($ctrl, 'mismatchPayload'); $mm->setAccessible(true);
$app->instance('request', Request::create('/api/vendors/50/products/130', 'PUT'));
Sanctum::actingAs(\App\Models\User::find(91));
$payload = $mm->invoke($ctrl, $oil, 'litre', $p130);
ok(($payload['code'] ?? '') === 'unit_mismatch', 'mismatch payload');
ok(str_contains($payload['message'], 'Replace with…') && !str_contains($payload['message'], 'everywhere.'), 'its sentence (what old APKs show) says Replace with…, not "change everywhere"', $payload['message']);
ok(($payload['replace']['ingredient_id'] ?? 0) === 24 && ($payload['replace']['to_base'] ?? '') === 'ml', 'and carries the replace hint {ingredient 24 → ml}', json_encode($payload['replace'] ?? null));
ok(is_array($payload['change']) && $payload['change']['can_change'] === false, 'the change-everywhere impact still rides along, marked locked');

// a NOT-locked ingredient keeps the old sentence and no replace hint
$fresh = IngredientModel::where('business_unit_id', 2)->where('is_active', 1)->whereNull('storage_product_id')
    ->get()->first(fn ($i) => empty((new IngredientUnitChangeService())->lockReasons($i)) && $i->base_unit === 'g');
if ($fresh) {
    $pl = $mm->invoke($ctrl, $fresh, 'litre', null);
    ok(str_contains($pl['message'], 'everywhere.') && empty($pl['replace']), "an unlocked ingredient ({$fresh->name}) still offers \"change everywhere\" and no replace hint");
} else {
    ok(true, '(no unlocked grams ingredient on the replica to contrast with — skipped)');
}

// ── 2. Replace preview: grams → ml suggests the old amounts ──────────────────
echo "2. replace-impact suggestions\n";
$imp = (new IngredientReplaceService())->impact($oil, null, 'ml');
ok($imp['can_replace'] === true, 'Cooking Oil CAN be replaced (the door that works)');
ok(count($imp['recipes']) === 4 && collect($imp['recipes'])->every(fn ($r) => $r['needs_qty'] && $r['suggested_qty'] === $r['old_qty']), '4 current recipes, each asked for ml with the old grams suggested 1:1', json_encode(array_map(fn ($r) => [$r['old_qty'], $r['suggested_qty']], $imp['recipes'])));
ok(is_string($imp['suggestion_note']) && str_contains($imp['suggestion_note'], '1 g per ml'), 'the note says why (1 g ≈ 1 ml)');
ok(count($imp['products']) === 0, 'no ACTIVE product is tagged any more (Qasim switched the kg one off), so nothing to size');

// a kg product tagged to it would be suggested 1000 ml per kg — reactivate 76 inside a transaction
DB::beginTransaction();
VendorProductModel::where('id', 76)->update(['is_active' => 1]);
$imp2 = (new IngredientReplaceService())->impact($oil->fresh(), null, 'ml');
DB::rollBack();
$p76 = collect($imp2['products'])->firstWhere('id', 76);
ok($p76 && $p76['needs_size'] && (float) $p76['suggested_size'] === 1000.0, 'a kg product moving to ml: suggested 1000 ml per kg', json_encode($p76));

// pieces → grams: no suggestion (nothing honest to suggest)
$pcs = IngredientModel::where('business_unit_id', 2)->where('base_unit', 'pcs')->where('is_active', 1)->first();
if ($pcs) {
    $imp3 = (new IngredientReplaceService())->impact($pcs, null, 'g');
    ok(collect($imp3['recipes'])->every(fn ($r) => $r['suggested_qty'] === null) && $imp3['suggestion_note'] === null, "pieces → grams ({$pcs->name}): no suggestion, no note");
}
// same unit: amounts carry over, nothing suggested
$imp4 = (new IngredientReplaceService())->impact($oil, null, 'g');
ok(collect($imp4['recipes'])->every(fn ($r) => !$r['needs_qty'] && $r['suggested_qty'] === null), 'grams → grams: amounts carry over, no suggestion');

// ── 3. the whole door over HTTP as Qasim, rolled back ────────────────────────
echo "3. POST /api/warehouse/ingredients/24/replace (rolled back)\n";
DB::beginTransaction();
try {
    [$st, $b] = call('GET', '/api/warehouse/ingredients/24/replace-impact?new_unit=ml&business_unit_id=2');
    ok($st === 200 && ($b['success'] ?? false) && ($b['recipes'][0]['suggested_qty'] ?? null) !== null, 'GET replace-impact carries suggested_qty', "$st " . mb_substr(json_encode($b), 0, 200));
    $qty = [];
    foreach ($b['recipes'] as $r) { $qty[$r['recipe_id']] = $r['suggested_qty']; }
    [$st, $b] = call('POST', '/api/warehouse/ingredients/24/replace', [
        'business_unit_id' => 2, 'new_name' => 'Cooking Oil', 'new_unit' => 'ml', 'recipe_qty' => $qty, 'product_sizes' => [],
    ]);
    ok($st === 200 && ($b['success'] ?? false), 'replace succeeded', "$st " . mb_substr(json_encode($b), 0, 300));
    $to = $b['to'] ?? null;
    ok(is_array($to) && ($to['base_unit'] ?? '') === 'ml' && ($to['name'] ?? '') === 'Cooking Oil' && (int) $to['id'] !== 24, 'response carries the NEW ingredient `to` (Cooking Oil, ml)', json_encode($to));
    $old = IngredientModel::find(24);
    ok((int) $old->is_active === 0 && $old->name === 'Cooking Oil (old)', 'the old one is hidden and renamed "(old)"');
    $cur = DB::table('t_crm_khaas_recipe_line as l')->join('t_crm_khaas_recipe as r', 'r.id', '=', 'l.recipe_id')
        ->where('r.is_current', 1)->where('l.ingredient_id', (int) $to['id'])->count();
    ok($cur === 4, '4 current recipes now use the new ml ingredient', (string) $cur);
    ok(DB::table('t_crm_khaas_recipe_line as l')->join('t_crm_khaas_recipe as r', 'r.id', '=', 'l.recipe_id')->where('r.is_current', 1)->where('l.ingredient_id', 24)->count() === 0, '…and none the old one');
    // now the litre product CAN be linked — the one-unit rule sizes it itself (1000 ml per litre)
    $ifm = new ReflectionMethod($ctrl, 'ingredientFields'); $ifm->setAccessible(true);
    $req = Request::create('/x', 'PUT', ['unit' => 'litre', 'ingredient_id' => (int) $to['id']]);
    $fields = $ifm->invoke($ctrl, $req, $p130, 50);
    ok(($fields['ingredient_id'] ?? 0) === (int) $to['id'] && (float) ($fields['pack_qty_base'] ?? 0) === 1000.0, '⭐ Canola Cooking Oil (litre) now links to it: 1000 ml per litre, automatically');
} finally {
    DB::rollBack();
}
ok(IngredientModel::find(24)->is_active == 1 && IngredientModel::find(24)->name === 'Cooking Oil', 'rolled back: Cooking Oil untouched on the replica');

// ── 4. "switch the old product off instead" (owner): the kg oil need not be re-sized ─
echo "4. deactivate_products — the old kg product switched off, not re-sized (rolled back)\n";
DB::beginTransaction();
try {
    VendorProductModel::where('id', 76)->update(['is_active' => 1]);          // Qasim's old kg oil, back on for the test
    $svc = new IngredientReplaceService();
    $oilFresh = IngredientModel::find(24);
    $imp = $svc->impact($oilFresh, null, 'ml');
    ok(collect($imp['products'])->firstWhere('id', 76)['needs_size'] ?? false, 'preview: the kg product needs a size (or a switch-off)');
    // without a size and without switching it off → asked
    $toNew = (new RecipeService())->saveIngredient(['name' => 'Cooking Oil (ml test)', 'base_unit' => 'ml', 'kind' => 'other'], 91, 2);
    $qty = []; foreach ($imp['recipes'] as $r) { $qty[$r['recipe_id']] = $r['suggested_qty']; }
    $msg = '';
    try { $svc->apply($oilFresh->fresh(), $toNew, $qty, [], 91); } catch (\InvalidArgumentException $e) { $msg = $e->getMessage(); }
    ok(str_contains($msg, 'How many ml') && str_contains($msg, 'Cooking Oil'), 'no size, not switched off → asked for the size', $msg);
    // switched off → no size needed, product off, tag untouched
    $done = $svc->apply($oilFresh->fresh(), $toNew->fresh(), $qty, [], 91, [76, 999999]);
    $p76 = VendorProductModel::find(76);
    ok((int) $p76->is_active === 0, '⏸ the old kg product is switched off');
    ok((int) $p76->ingredient_id === 24 && (float) $p76->pack_qty_base === 1000.0, '…and keeps its OLD tag and size (its bills still read as written)');
    ok(($done['switched_off'] ?? 0) === 1 && ($done['products'] ?? -1) === 0, 'result: 1 switched off, 0 moved (an unknown id is ignored)', json_encode($done));
    ok(str_contains($done['message'], '1 old product switched off (bills kept)'), 'the message says so', $done['message']);
    ok((int) IngredientModel::find(24)->is_active === 0, 'old ingredient hidden');
    ok(DB::table('t_crm_khaas_recipe_line as l')->join('t_crm_khaas_recipe as r', 'r.id', '=', 'l.recipe_id')->where('r.is_current', 1)->where('l.ingredient_id', $toNew->id)->count() === 4, '4 current recipes on the new one');
} finally {
    DB::rollBack();
}
// over HTTP, as the web/phone post it
DB::beginTransaction();
try {
    VendorProductModel::where('id', 76)->update(['is_active' => 1]);
    [$st, $b] = call('GET', '/api/warehouse/ingredients/24/replace-impact?new_unit=ml&business_unit_id=2');
    $qty = []; foreach ($b['recipes'] as $r) { $qty[$r['recipe_id']] = $r['suggested_qty']; }
    [$st, $b] = call('POST', '/api/warehouse/ingredients/24/replace', [
        'business_unit_id' => 2, 'new_name' => 'Cooking Oil', 'new_unit' => 'ml', 'recipe_qty' => $qty,
        'product_sizes' => [], 'deactivate_products' => ['76'],
    ]);
    ok($st === 200 && ($b['success'] ?? false) && str_contains($b['message'] ?? '', 'switched off'), 'HTTP replace with deactivate_products ["76"] (string ids, as a form posts them) → ok, switched off', "$st " . mb_substr(json_encode($b), 0, 200));
    ok((int) VendorProductModel::find(76)->is_active === 0 && ($b['to']['base_unit'] ?? '') === 'ml', 'product off, new ml ingredient returned');
} finally {
    DB::rollBack();
}
ok((int) VendorProductModel::find(76)->is_active === 0 && (int) VendorProductModel::find(130)->is_active === 1, 'rolled back: replica products as they were (76 off, 130 on)');

echo "\nPASS $pass  FAIL $fail\n";
exit($fail ? 1 : 0);
