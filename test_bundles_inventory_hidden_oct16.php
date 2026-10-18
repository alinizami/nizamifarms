<?php
/**
 * PROOF — bundles stay out of INVENTORY lists, and nothing else changes (Oct-16).
 * Run: php test_bundles_inventory_hidden_oct16.php
 *
 * For every inventory surface: result WITH bundles present == result with NO bundles
 * minus the bundle products. Baseline = the same calls after all bundle rows are
 * deleted inside the transaction (then excludeBundles() is a no-op). Rolled back.
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\CRM\ProductBundle as PB;
use App\Models\CRM\ProductVariantModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

$pass = 0; $fail = 0;
function ok($cond, $label, $extra = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; } else { $fail++; echo "  ✗ $label" . ($extra !== '' ? "  → $extra" : '') . "\n"; }
}
function req(string $path, array $q = []): Request {
    $r = Request::create($path, 'GET', $q);
    $r->headers->set('Accept', 'application/json');
    $r->headers->set('X-Requested-With', 'XMLHttpRequest');
    app()->instance('request', $r);
    return $r;
}
function ids($rows, $key = 'id'): array {
    $out = collect($rows)->map(fn ($r) => (int) (is_array($r) ? ($r[$key] ?? 0) : ($r->{$key} ?? 0)))->filter()->sort()->values()->all();
    return $out;
}

$FROZEN = 2; $NF = 1; $month = date('Y-m');
$user = \App\Models\User::query()->whereHas('roles', fn ($q) => $q->whereRaw('LOWER(urole_name) = ?', ['taimur']))->first()
    ?? \App\Models\User::query()->where('is_active', 1)->first();
auth()->setUser($user);

$pc = app(\App\Http\Controllers\CRM\ProductController::class);
$wc = app(\App\Http\Controllers\CRM\WarehouseController::class);
$kc = app(\App\Http\Controllers\KhaasController::class);

/** Every surface → product ids it lists. */
$surfaces = function () use ($pc, $wc, $kc, $FROZEN, $NF, $month) {
    PB::forget();
    $s = [];
    // Phone: Khaas Shop Inventory / frozen Products / storage picker (all /api/products?business_unit_id=)
    $s['phone /products (frozen BU)'] = ids($pc->index(req('/api/products', ['business_unit_id' => $FROZEN, 'per_page' => 200, 'status' => 'active']))->getData(true)['products'] ?? []);
    $s['phone /products (NF BU)'] = ids($pc->index(req('/api/products', ['business_unit_id' => $NF, 'search' => 'ZZ INV']))->getData(true)['products'] ?? []);
    // Must NOT change: phone ordinary Products list (no BU) and the web Products page
    $s['phone /products (no BU) — keeps bundles'] = ids($pc->index(req('/api/products', ['search' => 'ZZ INV']))->getData(true)['products'] ?? []);
    $web = $pc->index(req('/products', ['search' => 'ZZ INV']));
    $s['web /products — keeps bundles'] = method_exists($web, 'getData') && is_array($web->getData(true)) && isset($web->getData(true)['products'])
        ? ids($web->getData(true)['products']) : ids(($web->getData()['products'] ?? collect())->items());
    // Warehouse / frozen screens (phone + web)
    $s['storage products (NF)'] = ids($wc->getAvailableStorageProducts(req('/api/warehouse/storage/products'))->getData(true)['products'] ?? []);
    $s['demand products (frozen)'] = ids($wc->getDemandProducts(req('/api/warehouse/demand/products', ['business_unit_id' => $FROZEN]))->getData(true)['products'] ?? [], 'khaas_product_id');
    $d = $wc->dailyInventoryReport(req('/api/warehouse/inventory-report/daily', ['business_unit_id' => $FROZEN, 'month' => $month]))->getData(true);
    $s['daily inventory report'] = array_values(array_unique(ids(array_merge([], ...array_map(fn ($day) => $day['products'] ?? [], $d['days'] ?? [])), 'product_id')));
    $s['inventory report / month review'] = ids(app(\App\Services\Khaas\FrozenMonthService::class)->production($FROZEN, $month)['products'] ?? [], 'product_id');
    $s['recipe coverage'] = ids(app(\App\Services\Khaas\RecipeService::class)->coverage($FROZEN), 'product_id');
    $ov = app(\App\Http\Controllers\CRM\OvernightStorageController::class)->getProductsMap(req('/api/overnight/products', ['all' => 1]))->getData(true);
    $s['overnight manual products'] = ids($ov['manual_products'] ?? []);
    // Web Khaas Products page + dashboard count
    $view = $kc->products(req('/khaas/products', ['per_page' => 1000]));
    $vp = $view->getData()['products'] ?? [];
    $s['web Khaas Products page'] = ids(method_exists($vp, 'items') ? $vp->items() : $vp);
    $dash = $kc->dashboard();
    $s['web Khaas dashboard count'] = [(int) ($dash->getData()['productCount'] ?? -1)];
    return $s;
};

DB::beginTransaction();
try {
    // Two bundles: one frozen (BU 2) and one NF (BU 1)
    $inner = ProductVariantModel::whereHas('product', fn ($q) => $q->where('business_unit_id', $FROZEN)->where('is_active', 1))->whereNotNull('sku')->first();
    $mk = function ($bu, $title, $sku) use ($inner) {
        $pid = DB::table('t_crm_prod_product')->insertGetId(['title' => $title, 'status' => 'active', 'is_active' => 1, 'business_unit_id' => $bu, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('t_crm_prod_product_variant')->insert(['product_id' => $pid, 'title' => $title, 'sku' => $sku, 'price' => 1200, 'available' => 1, 'inventory_quantity' => 0, 'created_at' => now(), 'updated_at' => now()]);
        return $pid;
    };
    $bFrozen = $mk($FROZEN, 'ZZ INV Bundle frozen', 'ZZINV-F');
    $bNf = $mk($NF, 'ZZ INV Bundle nf', 'ZZINV-N');

    // Baseline: same data, but NOTHING is a bundle (all bundle rows removed)
    DB::table(PB::TABLE)->delete();
    $base = $surfaces();
    // Now make them bundles (plus the owner's local test bundle, if any, is gone from baseline only)
    PB::saveRows($bFrozen, [['variant_id' => $inner->id, 'qty' => 3, 'unit_price' => 400]], null);
    PB::saveRows($bNf, [['variant_id' => $inner->id, 'qty' => 2, 'unit_price' => 600]], null);
    $bundleIds = PB::bundleProductIds();
    $after = $surfaces();

    echo "── Inventory lists drop the bundles, and ONLY the bundles\n";
    foreach ($after as $name => $list) {
        if ($name === 'web Khaas dashboard count') {
            ok($list[0] === $base[$name][0] - 1, "$name: one less (the frozen bundle)", $base[$name][0] . ' → ' . $list[0]);
            continue;
        }
        $keeps = str_contains($name, 'keeps bundles');
        $expected = $keeps ? $base[$name] : array_values(array_diff($base[$name], $bundleIds));
        ok($list === $expected, $keeps ? "$name: unchanged (" . count($list) . ')' : "$name: " . count($base[$name]) . ' → ' . count($list), json_encode(array_values(array_diff($list, $expected))) . ' / missing ' . json_encode(array_values(array_diff($expected, $list))));
    }
    ok(in_array($bFrozen, $after['phone /products (no BU) — keeps bundles'], true), 'phone ordinary Products list still shows the bundle');
    ok(in_array($bFrozen, $after['web /products — keeps bundles'], true), 'web Products page still shows the bundle');
    ok(!in_array($bFrozen, $after['phone /products (frozen BU)'], true) && in_array($bFrozen, $base['phone /products (frozen BU)'], true), 'Khaas Shop Inventory / frozen Products: bundle was there, now gone');

    echo "── Billing untouched\n";
    $s = $pc->search(req('/api/products/search', ['q' => 'ZZINV-F']))->getData(true);
    $hit = collect($s['products'] ?? [])->firstWhere('sku', 'ZZINV-F');
    ok($hit && !empty($hit['bundle']['items']), 'order product search still finds the bundle (with its items)');
    $out = PB::expandFormItems([['name' => 'x', 'quantity' => 1, 'unit_price' => 1200, 'line_total' => 1200, 'sku' => 'ZZINV-F']]);
    ok(count($out) === 1 && (float) $out[0]['quantity'] === 3.0 && $out[0]['sku'] === $inner->sku, 'order lines still split into the product inside');
} finally {
    DB::rollBack();
    PB::forget();
}
ok(!DB::table('t_crm_prod_product')->where('title', 'like', 'ZZ INV%')->exists() && DB::table(PB::TABLE)->count() >= 0, 'rolled back');
echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILURES") . " — $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
