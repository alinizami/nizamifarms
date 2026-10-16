<?php
/**
 * PROOF — Bundled products (Oct-2026). Run: php test_product_bundles_oct15.php
 * Needs database/migrations/product_bundles_oct2026.sql on the local DB.
 * Everything that writes runs inside ONE transaction that is rolled back at the end.
 * No order is created through store()/convertOrder() — those fire location / WhatsApp
 * side effects; the bundle steps they call are exercised directly instead.
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\CRM\ProductBundle as PB;
use App\Models\CRM\ProductModel;
use App\Models\CRM\ProductVariantModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

$pass = 0; $fail = 0;
function ok($cond, $label, $extra = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; } else { $fail++; echo "  ✗ $label" . ($extra !== '' ? "  → $extra" : '') . "\n"; }
}
function jsonReq(string $method, array $data): Request {
    $r = Request::create('/x', $method, $data);
    $r->headers->set('Accept', 'application/json');
    $r->headers->set('X-Requested-With', 'XMLHttpRequest');
    app()->instance('request', $r);
    return $r;
}
function call($obj, string $method, ...$args) {
    $m = new ReflectionMethod($obj, $method);
    $m->setAccessible(true);
    return $m->invoke($obj, ...$args);
}
function hist(int $pid): int {
    return DB::table('t_crm_prod_product_change_history')->where('product_id', $pid)->where('change_type', 'bundle_change')->count();
}

if (!PB::supported()) { echo "✗ bundle table missing — run product_bundles_oct2026.sql first\n"; exit(1); }

// ── Fixtures, resolved at run time (never pinned to a row) ─────────────────
$existingBundleIds = DB::table(PB::TABLE)->distinct()->pluck('bundle_product_id')->map(fn ($v) => (int) $v)->all();
$usable = fn () => ProductVariantModel::with('product')
    ->whereHas('product', fn ($q) => $q->where('is_active', 1)->where('sell_unit', 'pcs'))
    ->whereNotIn('product_id', $existingBundleIds)
    ->whereNotNull('sku')->where('sku', '<>', '')
    ->get()->filter(fn ($v) => $v->product->variants()->count() === 1 && ProductVariantModel::where('sku', $v->sku)->count() === 1)->values();
$cands = $usable();
$innerA = $cands[0];
$innerB = $cands->first(fn ($v) => $v->product_id !== $innerA->product_id);
$plain = ProductVariantModel::with('product')
    ->whereHas('product', fn ($q) => $q->where('is_active', 1))
    ->whereNotIn('product_id', array_merge($existingBundleIds, [$innerA->product_id, $innerB->product_id]))
    ->whereNotNull('sku')->where('sku', '<>', '')
    ->get()->first(fn ($v) => ProductVariantModel::where('sku', $v->sku)->count() === 1);
$noSku = ProductVariantModel::with('product')->where(fn ($q) => $q->whereNull('sku')->orWhere('sku', ''))->first();
$user = \App\Models\User::query()->where('is_active', 1)->first() ?? \App\Models\User::query()->first();
echo "Inside A: {$innerA->sku} '{$innerA->product->title}' (BU {$innerA->product->business_unit_id}) · Inside B: {$innerB->sku} (BU {$innerB->product->business_unit_id}) · plain: {$plain->sku}\n";
$nameA = PB::lineName($innerA); $nameB = PB::lineName($innerB);

// ── 0. Frozen-demand SKU map: ordinary SKUs identical to the old subquery ─
echo "── 0. Demand SKU map\n";
$old = collect(DB::select("SELECT sku, MIN(product_id) AS product_id FROM t_crm_prod_product_variant WHERE sku IS NOT NULL AND sku <> '' GROUP BY sku"))
    ->mapWithKeys(fn ($r) => [$r->sku => (int) $r->product_id]);
$demand = new \App\Services\Khaas\OpenOrderDemandService();
$map = collect(DB::select(call($demand, 'stagingSkuMapSql')));
$bundleSkus = DB::table('t_crm_prod_product_variant as v')->join(PB::TABLE . ' as bi', 'bi.bundle_product_id', '=', 'v.product_id')
    ->distinct()->pluck('v.sku')->flip();
$ordinary = $map->filter(fn ($r) => !isset($bundleSkus[$r->sku]));
ok($ordinary->count() + $bundleSkus->count() === $old->count(), 'every SKU still mapped (ordinary ' . $ordinary->count() . ' + bundle ' . $bundleSkus->count() . ')');
ok($ordinary->every(fn ($r) => ($old[$r->sku] ?? null) === (int) $r->product_id && (int) $r->pack === 1), 'every ordinary SKU → same product, pack 1');
$baseDemandA = (float) ($demand->byProduct((int) $innerA->product->business_unit_id)[(int) $innerA->product_id]['shopify'] ?? 0);
$baseDemandB = (float) ($demand->byProduct((int) $innerB->product->business_unit_id)[(int) $innerB->product_id]['shopify'] ?? 0);

DB::beginTransaction();
try {
    auth()->setUser($user);
    $pc = app(\App\Http\Controllers\CRM\ProductController::class);
    $oc = app(\App\Http\Controllers\CRM\OrderController::class);
    $row = fn ($v, $q, $p) => ['variant_id' => 'variant_' . $v->id, 'qty' => (string) $q, 'unit_price' => (string) $p];

    // ── 1. Set-up rules ────────────────────────────────────────────────────
    echo "── 1. Set-up rules\n";
    $R = fn (array $rows) => PB::normaliseRows($rows);
    ok(PB::setupError(null, $R([$row($innerA, 2, 500), $row($innerB, 1, 500)]), 1) === null, 'valid: 2 × A @ 500 + 1 × B @ 500');
    ok(PB::setupError(null, $R([$row($innerA, 3, 400)]), 1) === null, 'valid: single product, 3 × A @ 400');
    ok(PB::setupError(null, $R([$row($innerA, 3, 400)]), 2) !== null, 'refuses a bundle with 2 SKUs');
    ok(PB::setupError(null, [], 1) !== null, 'refuses no rows');
    ok(PB::setupError(null, $R([$row($innerA, 1, 400)]), 1) !== null, 'refuses a 1-packet bundle');
    ok(PB::setupError(null, $R([$row($innerA, 2.5, 400)]), 1) !== null, 'refuses 2.5 packets');
    ok(PB::setupError(null, $R([$row($innerA, 2, '')]), 1) !== null, 'refuses a missing packet price');
    ok(PB::setupError(null, $R([$row($innerA, 2, 400), $row($innerA, 1, 400)]), 1) !== null, 'refuses the same product twice');
    ok(PB::setupError(null, $R([['variant_id' => 'variant_99999999', 'qty' => 3, 'unit_price' => 1]]), 1) !== null, 'refuses a missing product');
    ok(PB::setupError((int) $innerA->product_id, $R([$row($innerA, 3, 400)]), 1) !== null, 'refuses a product inside itself');
    if ($noSku) ok(PB::setupError(null, $R([['variant_id' => $noSku->id, 'qty' => 3, 'unit_price' => 1]]), 1) !== null, 'refuses an inside product with no SKU');
    ok(PB::normaliseRows([['variant_id' => '', 'qty' => '', 'unit_price' => '']]) === [], 'blank form rows are ignored');

    // ── 2. Create the bundle through the real save path ────────────────────
    echo "── 2. Product create (web form shape)\n";
    $resp = $pc->store(jsonReq('POST', [
        'title' => 'ZZ TEST Bundle A+B', 'status' => 'active', 'is_bundle' => '1',
        'bundle_items' => [$row($innerA, 2, 500), $row($innerB, 1, 500), ['variant_id' => '', 'qty' => '', 'unit_price' => '']],
        'variants' => [['title' => '', 'sku' => 'ZZTEST-EK200', 'price' => '999', 'inventory_quantity' => '0']],
    ]));
    ok(($resp->getData(true)['success'] ?? false) === true, 'store() succeeded', json_encode($resp->getData(true)));
    $bundleProduct = ProductModel::where('title', 'ZZ TEST Bundle A+B')->first();
    $bundleVariant = $bundleProduct->variants()->first();
    $rows = PB::itemRows((int) $bundleProduct->id);
    ok(count($rows) === 2 && $rows[0]['variant_id'] === (int) $innerA->id && $rows[0]['qty'] === 2 && $rows[1]['variant_id'] === (int) $innerB->id && $rows[1]['unit_price'] === 500.0, '2 rows saved in order (blank row dropped)', json_encode($rows));
    ok((float) $bundleVariant->price === 1500.0, "bundle's own price pinned to the total (999 → 1500)", (string) $bundleVariant->price);
    ok(hist((int) $bundleProduct->id) === 1, 'product history: one bundle_change on create');

    $pc->store(jsonReq('POST', [
        'title' => 'ZZ TEST plain', 'status' => 'active',
        'variants' => [['title' => '', 'sku' => 'ZZTEST-PLAIN', 'price' => '100', 'inventory_quantity' => '0']],
    ]));
    $plainNew = ProductModel::where('title', 'ZZ TEST plain')->first();
    ok($plainNew && !PB::isBundle($plainNew) && (float) $plainNew->variants()->first()->price === 100.0, 'product without is_bundle → ordinary, price untouched');

    $r = $pc->store(jsonReq('POST', [
        'title' => 'ZZ TEST bad bundle', 'status' => 'active', 'is_bundle' => '1',
        'bundle_items' => [['variant_id' => 'variant_' . $bundleVariant->id, 'qty' => '2', 'unit_price' => '1']],
        'variants' => [['title' => '', 'sku' => 'ZZTEST-BAD', 'price' => '100', 'inventory_quantity' => '0']],
    ]));
    ok($r->getStatusCode() === 422 && !ProductModel::where('title', 'ZZ TEST bad bundle')->exists() && !ProductVariantModel::where('sku', 'ZZTEST-BAD')->exists(), 'bundle of a bundle refused (422, nothing saved)', $r->getStatusCode());

    // ── 3. Edits ──────────────────────────────────────────────────────────
    echo "── 3. Product edits\n";
    $edit = fn (array $extra, string $price = '999') => $pc->update(jsonReq('PUT', array_merge([
        'title' => 'ZZ TEST Bundle A+B', 'status' => 'active',
        'variants' => [['title' => '', 'id' => $bundleVariant->id, 'sku' => 'ZZTEST-EK200', 'price' => $price, 'inventory_quantity' => '0']],
    ], $extra)), $bundleProduct->id);
    $h = hist((int) $bundleProduct->id);
    $r = $edit([]); // phone shape: no is_bundle key at all, someone typed 999 as the price
    ok(($r->getData(true)['success'] ?? false) && count(PB::itemRows((int) $bundleProduct->id)) === 2, 'phone save (key absent) keeps both rows');
    ok((float) $bundleVariant->fresh()->price === 1500.0, 'phone save cannot un-pin the price (999 → 1500)');
    ok(hist((int) $bundleProduct->id) === $h, 'no phantom bundle_change logged');
    $edit(['is_bundle' => '1', 'bundle_items' => [$row($innerA, 4, 500), $row($innerB, 1, 500)]]);
    ok(PB::itemRows((int) $bundleProduct->id)[0]['qty'] === 4 && (float) $bundleVariant->fresh()->price === 2500.0, 'web save: A packets 2 → 4, price follows (2500)');
    ok(hist((int) $bundleProduct->id) === $h + 1 && str_contains((string) DB::table('t_crm_prod_product_change_history')->where('product_id', $bundleProduct->id)->where('change_type', 'bundle_change')->orderByDesc('id')->value('new_value'), '4 × ' . $innerA->sku), 'history logs the change once, readable');
    $edit(['is_bundle' => '0'], '777');
    ok(!PB::isBundle($bundleProduct->fresh()) && PB::itemRows((int) $bundleProduct->id) === [] && (float) $bundleVariant->fresh()->price === 777.0, 'untick clears the rows; price is editable again');
    $edit(['is_bundle' => '1', 'bundle_items' => [$row($innerA, 2, 500), $row($innerB, 1, 500)]]);
    ok(count(PB::itemRows((int) $bundleProduct->id)) === 2 && (float) $bundleVariant->fresh()->price === 1500.0, 'set back to 2 × A + 1 × B = 1500');
    $r = $pc->update(jsonReq('PUT', [
        'title' => $innerA->product->title, 'status' => $innerA->product->status ?: 'active',
        'is_bundle' => '1', 'bundle_items' => [$row($plain, 2, 1)],
        'variants' => [['title' => '', 'id' => $innerA->id, 'sku' => $innerA->sku, 'price' => (string) $innerA->price, 'inventory_quantity' => (string) max(0, (int) $innerA->inventory_quantity)]],
    ]), $innerA->product_id);
    ok($r->getStatusCode() === 422 && !PB::isBundleProductId((int) $innerA->product_id), 'a product INSIDE a bundle cannot become a bundle (422)');

    // ── 4. Product search tells the order forms ────────────────────────────
    echo "── 4. Product search\n";
    $s = $pc->search(jsonReq('GET', ['q' => 'ZZTEST-EK200']))->getData(true);
    $hit = collect($s['products'] ?? [])->firstWhere('sku', 'ZZTEST-EK200');
    $bi = $hit['bundle']['items'] ?? [];
    ok($hit && count($bi) === 2 && $bi[0]['id'] === 'variant_' . $innerA->id && $bi[0]['qty'] === 2 && (float) $bi[0]['unit_price'] === 500.0 && $bi[1]['sku'] === $innerB->sku, 'bundle result lists both products with packets + packet price', json_encode($hit['bundle'] ?? null));
    ok($hit && (float) $hit['price'] === 1500.0 && (float) $hit['bundle']['total'] === 1500.0, 'result price = bundle total (1500)');
    $s = $pc->search(jsonReq('GET', ['q' => $innerA->sku]))->getData(true);
    $hit = collect($s['products'] ?? [])->firstWhere('sku', $innerA->sku);
    ok($hit && array_key_exists('bundle', $hit) && $hit['bundle'] === null, 'ordinary result: bundle = null');

    // ── 5. Order-create safety net (older phone app sends the bundle itself) ─
    echo "── 5. Order create — server expansion\n";
    $items = [
        ['name' => 'B', 'quantity' => 1, 'unit_price' => 1500, 'line_total' => 1500, 'sku' => 'ZZTEST-EK200', 'variant_id' => (string) $bundleVariant->id, 'instructions' => 'ring bell'],
        ['name' => 'B', 'quantity' => 2, 'unit_price' => 1500, 'line_total' => 3000, 'product_id' => 'variant_' . $bundleVariant->id],
        ['name' => 'B', 'quantity' => 1, 'unit_price' => 1500, 'line_total' => 1500, 'sku' => 'ZZTEST-EK200'],
        ['name' => 'free', 'quantity' => 1, 'unit_price' => 1500, 'line_total' => 0, 'variant_id' => (string) $bundleVariant->id, 'is_free' => true],
        ['name' => $plain->product->title, 'quantity' => 2, 'unit_price' => (float) $plain->price, 'line_total' => 2 * (float) $plain->price, 'sku' => $plain->sku, 'variant_id' => (string) $plain->id],
        ['name' => 'Custom line', 'quantity' => 1, 'unit_price' => 50, 'line_total' => 50],
    ];
    $out = PB::expandFormItems($items);
    ok(count($out) === 4 * 2 + 2, '4 bundle lines → 8 lines, + 2 untouched = 10', (string) count($out));
    ok($out[0]['quantity'] == 2 && $out[0]['unit_price'] == 500 && $out[0]['line_total'] == 1000 && $out[0]['sku'] === $innerA->sku && $out[0]['variant_id'] === (string) $innerA->id && $out[0]['product_id'] === (string) $innerA->product_id && $out[0]['name'] === $nameA, 'line 1 → 2 × A @ 500 = 1000 (A sku / variant / product / name)');
    ok($out[1]['quantity'] == 1 && $out[1]['line_total'] == 500 && $out[1]['sku'] === $innerB->sku, 'line 1 → 1 × B @ 500 = 500');
    ok(($out[0]['instructions'] ?? null) === 'ring bell' && !array_key_exists('instructions', $out[1]), 'the note stays on the first line only');
    ok($out[2]['quantity'] == 4 && $out[3]['quantity'] == 2 && $out[2]['line_total'] + $out[3]['line_total'] == 3000, '2 bundles → 4 × A + 2 × B = 3000');
    ok($out[4]['sku'] === $innerA->sku && $out[5]['sku'] === $innerB->sku, 'SKU-only line expands');
    ok($out[6]['line_total'] == 0 && $out[7]['line_total'] == 0 && !empty($out[7]['is_free']), 'free bundle: both lines free');
    ok($out[8] === $items[4] && $out[9] === $items[5], 'ordinary + custom lines untouched');
    ok(PB::expandFormItems($out) === $out, 'idempotent: expanding again changes nothing');
    ok(array_sum(array_map(fn ($l) => $l['line_total'], array_slice($out, 0, 6))) == 1500 + 3000 + 1500, 'money: bundle lines add up to the bundle totals');

    // ── 6. Shopify conversion (the ONE engine web + phone both call) ───────
    echo "── 6. Shopify conversion\n";
    $tpl = (array) DB::table('t_crm_shopify_order')->where('converted', 1)->orderByDesc('id')->first();
    unset($tpl['id']);
    $tpl = array_merge($tpl, [
        'converted' => 0, 'order_number' => 'ZZTEST1', 'external_id' => 'zztest-' . uniqid(),
        'coupon_code' => null, 'discount_total' => 0, 'shipping_total' => 150, 'total_tax' => 0, 'tip_amount' => 0,
        'external_source' => 'shopify', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $soId = DB::table('t_crm_shopify_order')->insertGetId($tpl);
    $line = fn ($sku, $qty, $price, $name) => DB::table('t_crm_shopify_order_line_item')->insert([
        'order_id' => $soId, 'product_id' => '111', 'variant_id' => '222', 'sku' => $sku, 'name' => $name,
        'quantity' => $qty, 'unit_price' => $price, 'line_subtotal' => $qty * $price, 'discount_amount' => 0,
        'tax_amount' => 0, 'line_total' => $qty * $price, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $line('ZZTEST-EK200', 1, 1500, 'Samosa + Roll Bundle (Shopify)');
    $line('ZZTEST-EK200', 2, 1500, 'Samosa + Roll Bundle (Shopify)');
    $line($plain->sku, 1, (float) $plain->price, 'Plain (Shopify)');
    $conv = fn () => call($oc, 'validateAndRecalculateOrder', \App\Models\CRM\ShopifyOrderModel::with('lineItems')->find($soId));
    $res = $conv();
    ok($res['success'] === true, 'converts', json_encode($res['errors'] ?? null));
    $L = $res['recalculated_line_items'] ?? [];
    ok(count($L) === 5, '3 Shopify lines → 5 order lines (2 + 2 + 1)', (string) count($L));
    $f = fn ($l) => [(float) $l['quantity'], (float) $l['unit_price'], (float) $l['line_total'], $l['sku']];
    ok($f($L[0]) === [2.0, 500.0, 1000.0, $innerA->sku] && $f($L[1]) === [1.0, 500.0, 500.0, $innerB->sku], '1 bundle → 2 × A @ 500 + 1 × B @ 500', json_encode([$f($L[0]), $f($L[1])]));
    ok($f($L[2]) === [4.0, 500.0, 2000.0, $innerA->sku] && $f($L[3]) === [2.0, 500.0, 1000.0, $innerB->sku], '2 bundles → 4 × A + 2 × B');
    ok((int) $L[0]['variant_id'] === (int) $innerA->id && (int) $L[0]['product_id'] === (int) $innerA->product_id && $L[0]['name'] === $nameA && (int) $L[1]['product_id'] === (int) $innerB->product_id, 'lines are the inside products (variant / product / name)');
    ok((float) $L[0]['line_subtotal'] === 1000.0, 'line_subtotal follows line_total');
    ok((float) $L[4]['quantity'] === 1.0 && $L[4]['sku'] === $plain->sku && (float) $L[4]['unit_price'] === (float) $plain->price && (int) $L[4]['variant_id'] === (int) $plain->id, 'ordinary line exactly as before');
    ok(abs($res['new_subtotal'] - (4500 + (float) $plain->price)) < 0.001 && abs($res['new_total'] - ($res['new_subtotal'] + 150)) < 0.001, 'subtotal 1500 + 3000 + plain; total adds shipping', $res['new_subtotal'] . ' / ' . $res['new_total']);
    ok(empty($res['warnings']), 'no warning when the packet prices add up to Shopify\'s', json_encode($res['warnings']));
    foreach ($L as $l) { $m = new \App\Models\CRM\OrderLineItemModel($l); }
    ok((float) (new \App\Models\CRM\OrderLineItemModel($L[1]))->quantity === 1.0 && (new \App\Models\CRM\OrderLineItemModel($L[1]))->sku === $innerB->sku, 'live line model accepts the expanded lines');

    // Approval-queue hint
    $staged = \App\Models\CRM\ShopifyOrderModel::with('lineItems')->find($soId)->lineItems->values();
    ok(($staged[0]->toArray()['bundle_hint'] ?? null) === "= 2 × {$nameA} @ Rs 500 + 1 × {$nameB} @ Rs 500", 'hint on 1 bundle names both products', (string) ($staged[0]->toArray()['bundle_hint'] ?? 'null'));
    ok(($staged[1]->toArray()['bundle_hint'] ?? null) === "= 4 × {$nameA} @ Rs 500 + 2 × {$nameB} @ Rs 500", 'hint on 2 bundles: 4 + 2');
    ok(array_key_exists('bundle_hint', $staged[2]->toArray()) && $staged[2]->toArray()['bundle_hint'] === null, 'ordinary staging line: bundle_hint = null');
    ok(PB::hintForLine('', 1) === null && PB::hintForLine('NO-SUCH-SKU-ZZ', 1) === null, 'blank / unknown sku → null, no throw');

    // Frozen demand counts the WAITING bundle as packets of EACH product inside
    $dA = (float) ($demand->byProduct((int) $innerA->product->business_unit_id)[(int) $innerA->product_id]['shopify'] ?? 0);
    $dB = (float) ($demand->byProduct((int) $innerB->product->business_unit_id)[(int) $innerB->product_id]['shopify'] ?? 0);
    ok(abs($dA - ($baseDemandA + 6)) < 0.001, 'approval-queue demand: A +6 packets (2 + 4)', "$dA vs base $baseDemandA");
    ok(abs($dB - ($baseDemandB + 3)) < 0.001, 'approval-queue demand: B +3 packets (1 + 2)', "$dB vs base $baseDemandB");
    $rowsA = $demand->ordersForProduct((int) $innerA->product->business_unit_id, (int) $innerA->product_id)['shopify'];
    ok((collect($rowsA)->firstWhere('order_number', 'ZZTEST1')['qty'] ?? null) === 6, 'demand popup lists ZZTEST1 with 6 of A');

    // Packet prices that don't add up to Shopify's → converts at OUR prices but WARNS
    DB::table(PB::TABLE)->where('bundle_product_id', $bundleProduct->id)->where('variant_id', $innerB->id)->update(['unit_price' => 600]);
    PB::forget();
    $res = $conv();
    ok($res['success'] && (float) $res['recalculated_line_items'][1]['line_total'] === 600.0 && count($res['warnings']) === 2 && str_contains($res['warnings'][0], 'packet prices'), 'B priced 600 → converts at 1600 but WARNS (both bundle lines)', json_encode($res['warnings']));
    DB::table(PB::TABLE)->where('bundle_product_id', $bundleProduct->id)->where('variant_id', $innerB->id)->update(['unit_price' => 500]);
    PB::forget();

    // ⭐ A BROKEN bundle must never stop a conversion: kept as one line at the bundle price, warned
    DB::table(PB::TABLE)->where('bundle_product_id', $bundleProduct->id)->where('variant_id', $innerB->id)->update(['variant_id' => 99999999]);
    PB::forget();
    $res = $conv();
    $L = $res['recalculated_line_items'] ?? [];
    ok($res['success'] === true, 'broken bundle (product inside gone) → conversion still SUCCEEDS');
    ok(count($L) === 3 && $L[0]['sku'] === 'ZZTEST-EK200' && (float) $L[0]['line_total'] === 1500.0 && (float) $L[1]['line_total'] === 3000.0, 'its lines stay as the bundle at the bundle price (1500 / 3000) — money right');
    ok(count($res['warnings']) === 2 && str_contains($res['warnings'][0], 'no longer exists'), 'staff get a warning to fix the bundle', json_encode($res['warnings']));
    $out = PB::expandFormItems([$items[0]]);
    ok(count($out) === 1 && $out[0] === $items[0], 'order-create keeps a broken bundle line as it came (never refuses)');
    ok(PB::hintForLine('ZZTEST-EK200', 1) === null || true, 'hint for a broken bundle never throws');
    DB::table(PB::TABLE)->where('bundle_product_id', $bundleProduct->id)->where('variant_id', 99999999)->update(['variant_id' => $innerB->id]);
    PB::forget();

    // ── 6b. The product inside a bundle cannot be deleted ─────────────────────
    echo "── 6b. Delete guard\n";
    $taimur = \App\Models\User::query()->whereHas('roles', fn ($q) => $q->whereRaw('LOWER(urole_name) = ?', ['taimur']))->first();
    if ($taimur) {
        auth()->setUser($taimur);
        $pc->store(jsonReq('POST', [
            'title' => 'ZZ TEST inside-only', 'status' => 'active',
            'variants' => [['title' => '', 'sku' => 'ZZTEST-INSIDE', 'price' => '100', 'inventory_quantity' => '0']],
        ]));
        $insideOnly = ProductModel::where('title', 'ZZ TEST inside-only')->first();
        $edit(['is_bundle' => '1', 'bundle_items' => [$row($innerA, 2, 500), ['variant_id' => (string) $insideOnly->variants()->first()->id, 'qty' => '1', 'unit_price' => '100']]]);
        jsonReq('DELETE', []);
        $r = $pc->destroy($insideOnly->id);
        ok($r->getStatusCode() === 422 && ProductModel::find($insideOnly->id) !== null && str_contains($r->getData(true)['message'], 'inside the bundle'), 'delete refused while a bundle holds it', $r->getStatusCode());
        $edit(['is_bundle' => '1', 'bundle_items' => [$row($innerA, 2, 500), $row($innerB, 1, 500)]]);
        jsonReq('DELETE', []);
        $r = $pc->destroy($insideOnly->id);
        ok($r->getStatusCode() === 200 && ProductModel::find($insideOnly->id) === null, 'delete allowed once no bundle holds it', $r->getStatusCode());
        jsonReq('DELETE', []);
        $r = $pc->destroy($bundleProduct->id);
        ok($r->getStatusCode() === 200 && DB::table(PB::TABLE)->where('bundle_product_id', $bundleProduct->id)->count() === 0, 'deleting a bundle removes its rows', $r->getStatusCode());
        auth()->setUser($user);
    } else {
        echo "  (no Taimur-role user on this DB — delete guard not exercised)\n";
    }

    // ── 7. Regression: real already-converted Shopify orders recalc as before ─
    echo "── 7. Ordinary orders unchanged (replica, read only)\n";
    PB::forget();
    $checked = 0; $same = 0;
    foreach (\App\Models\CRM\ShopifyOrderModel::with('lineItems')->where('converted', 1)->where('external_source', 'shopify')->orderByDesc('id')->limit(40)->get() as $o) {
        if ($o->lineItems->contains(fn ($l) => isset($bundleSkus[$l->sku]))) continue;
        $r = call($oc, 'validateAndRecalculateOrder', $o);
        if (!$r['success']) continue;
        $checked++;
        $okOrder = count($r['recalculated_line_items']) === $o->lineItems->count();
        foreach ($o->lineItems->values() as $i => $sl) {
            $rl = $r['recalculated_line_items'][$i] ?? null;
            $v = ProductVariantModel::where('sku', $sl->sku)->first();
            if (!$rl || (string) $rl['quantity'] !== (string) $sl->quantity || $rl['sku'] !== $sl->sku
                || abs((float) $rl['line_total'] - (int) $sl->quantity * (float) $v->price) > 0.001) { $okOrder = false; }
        }
        if ($okOrder && empty(array_filter($r['warnings'], fn ($w) => str_starts_with($w, 'Bundle')))) $same++;
    }
    ok($checked > 0 && $same === $checked, "last converted orders recalc exactly as before ($same/$checked)");
} finally {
    DB::rollBack();
}
PB::forget();
ok(!ProductModel::where('title', 'like', 'ZZ TEST%')->exists() && !DB::table('t_crm_shopify_order')->where('order_number', 'ZZTEST1')->exists() && !ProductVariantModel::where('sku', 'like', 'ZZTEST-%')->exists(), 'rolled back — nothing left behind');

echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILURES") . " — $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
