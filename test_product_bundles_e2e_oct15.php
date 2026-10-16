<?php
/**
 * PROOF (end to end) — a Shopify bundle order is saved as a REAL live order and every
 * invoice view renders it. Run: php test_product_bundles_e2e_oct15.php
 *
 * Does exactly what OrderController::convertOrder() does up to the save —
 * validateAndRecalculateOrder() + the same $orderData + OrderModel::storeOrderFromApi()
 * — then reads the saved order back and renders invoice / invoice-print / invoice-image
 * (the WhatsApp invoice picture is made from invoice-image) and invoice-pdf.
 * Skipped on purpose: the after-save extras of convertOrder (WhatsApp promise, geocode,
 * location request, proof re-match) — they don't touch lines or money and would message
 * a real customer copied into the dev DB. Everything runs in ONE transaction, rolled back.
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\CRM\ProductBundle as PB;
use App\Models\CRM\ProductVariantModel;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function ok($cond, $label, $extra = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; } else { $fail++; echo "  ✗ $label" . ($extra !== '' ? "  → $extra" : '') . "\n"; }
}
function call($obj, string $method, ...$args) {
    $m = new ReflectionMethod($obj, $method);
    $m->setAccessible(true);
    return $m->invoke($obj, ...$args);
}
if (!PB::supported()) { echo "✗ run product_bundles_oct2026.sql first\n"; exit(1); }
config(['customer_app.enabled' => false]); // belt and braces: no outbound customer-app webhooks

$existing = DB::table(PB::TABLE)->distinct()->pluck('bundle_product_id')->all();
$cands = ProductVariantModel::with('product')
    ->whereHas('product', fn ($q) => $q->where('is_active', 1)->where('sell_unit', 'pcs'))
    ->whereNotIn('product_id', $existing)->whereNotNull('sku')->where('sku', '<>', '')
    ->get()->filter(fn ($v) => $v->product->variants()->count() === 1 && ProductVariantModel::where('sku', $v->sku)->count() === 1)->values();
$A = $cands[0]; $B = $cands->first(fn ($v) => $v->product_id !== $A->product_id);
$plain = $cands->first(fn ($v) => !in_array($v->product_id, [$A->product_id, $B->product_id]));
echo "A {$A->sku} · B {$B->sku} · plain {$plain->sku} @ {$plain->price}\n";

DB::beginTransaction();
try {
    auth()->setUser(\App\Models\User::query()->where('is_active', 1)->first());
    $oc = app(\App\Http\Controllers\CRM\OrderController::class);

    // A bundle product: 2 × A @ 500 + 1 × B @ 500 = 1,500
    $pid = DB::table('t_crm_prod_product')->insertGetId(['title' => 'ZZ E2E Bundle', 'status' => 'active', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('t_crm_prod_product_variant')->insert(['product_id' => $pid, 'title' => 'ZZ E2E Bundle', 'sku' => 'ZZE2E-BUNDLE', 'price' => 1500, 'available' => 1, 'created_at' => now(), 'updated_at' => now()]);
    PB::saveRows($pid, [['variant_id' => $A->id, 'qty' => 2, 'unit_price' => 500], ['variant_id' => $B->id, 'qty' => 1, 'unit_price' => 500]], null);

    // A staged Shopify order: 2 bundles + 1 ordinary line, shipping 150
    $tpl = (array) DB::table('t_crm_shopify_order')->where('converted', 1)->orderByDesc('id')->first();
    unset($tpl['id']);
    $tpl = array_merge($tpl, ['converted' => 0, 'order_number' => 'ZZE2E1', 'external_id' => 'zze2e-' . uniqid(),
        'coupon_code' => null, 'discount_total' => 0, 'shipping_total' => 150, 'total_tax' => 0, 'tip_amount' => 0,
        'external_source' => 'shopify', 'created_at' => now(), 'updated_at' => now()]);
    $soId = DB::table('t_crm_shopify_order')->insertGetId($tpl);
    foreach ([['ZZE2E-BUNDLE', 2, 1500, 'Bundle (Shopify)'], [$plain->sku, 1, (float) $plain->price, 'Plain (Shopify)']] as [$sku, $q, $p, $n]) {
        DB::table('t_crm_shopify_order_line_item')->insert(['order_id' => $soId, 'product_id' => '1', 'variant_id' => '1', 'sku' => $sku, 'name' => $n,
            'quantity' => $q, 'unit_price' => $p, 'line_subtotal' => $q * $p, 'discount_amount' => 0, 'tax_amount' => 0, 'line_total' => $q * $p,
            'created_at' => now(), 'updated_at' => now()]);
    }

    echo "── Conversion → real save (same steps as convertOrder)\n";
    $original = \App\Models\CRM\ShopifyOrderModel::with(['customer', 'lineItems'])->find($soId);
    $v = call($oc, 'validateAndRecalculateOrder', $original);
    ok($v['success'] === true && empty($v['warnings']), 'engine: success, no warnings', json_encode($v['warnings'] ?? $v));
    $orderData = $original->toArray();
    unset($orderData['id'], $orderData['created_at'], $orderData['updated_at'], $orderData['converted']);
    $orderData['external_source'] = 'webapp';
    $orderData['external_id'] = null;
    $orderData['external_customer_id'] = null;
    $orderData['order_number'] = 'SH-ZZE2E1';
    $orderData['_force_prod_order'] = true;
    $orderData['order_date'] = now();
    $orderData['line_items'] = $v['recalculated_line_items'];
    $orderData['subtotal_price'] = $v['new_subtotal'];
    $orderData['total_price'] = $v['new_total'];
    $orderData['order_status'] = $orderData['order_status'] ?? 'new';
    $order = \App\Models\CRM\OrderModel::storeOrderFromApi($orderData);
    $order = \App\Models\CRM\OrderModel::with('lineItems')->find($order->id);
    ok($order && $order instanceof \App\Models\CRM\OrderModel && $order->getTable() === 't_crm_prod_order', 'saved as a LIVE order (t_crm_prod_order)');
    $L = $order->lineItems->sortBy('id')->values();
    ok($L->count() === 3, 'live lines: 2 bundles → A + B, plus the ordinary line = 3', (string) $L->count());
    $row = fn ($l) => [$l->sku, (float) $l->quantity, (float) $l->unit_price, (float) $l->line_total];
    ok($row($L[0]) === [$A->sku, 4.0, 500.0, 2000.0], 'line 1 saved: A 4 × 500 = 2000', json_encode($row($L[0])));
    ok($row($L[1]) === [$B->sku, 2.0, 500.0, 1000.0], 'line 2 saved: B 2 × 500 = 1000', json_encode($row($L[1])));
    ok($row($L[2]) === [$plain->sku, 1.0, (float) $plain->price, (float) $plain->price], 'line 3 saved: ordinary, untouched');
    ok((int) $L[0]->variant_id === (int) $A->id && (int) $L[0]->product_id === (int) $A->product_id && (int) $L[1]->product_id === (int) $B->product_id, 'saved lines point at the real products (stock / packing / scan)');
    $sumLines = round($L->sum(fn ($l) => (float) $l->line_total), 2);
    ok(abs((float) $order->subtotal_price - $sumLines) < 0.001 && abs($sumLines - (3000 + (float) $plain->price)) < 0.001, 'order subtotal = Σ lines = 3000 + plain', $order->subtotal_price . ' vs ' . $sumLines);
    ok(abs((float) $order->total_price - ($sumLines + 150)) < 0.001, 'order total = subtotal + shipping (what the ledger invoice posts)', (string) $order->total_price);

    echo "── Invoices render from the saved order\n";
    $qf = call($oc, 'isQurbaniOrder', $order) ? call($oc, 'getQurbaniInvoiceFields') : [];
    foreach (['pages.orders.invoice', 'pages.orders.invoice-print', 'pages.orders.invoice-image', 'pages.orders.invoice-pdf'] as $viewName) {
        try {
            $html = view($viewName, ['order' => $order, 'qurbaniInvoiceFields' => $qf, 'filename' => 'zz.png'])->render();
            $text = preg_replace('/\s+/', ' ', strip_tags($html));
            $names = str_contains($text, PB::lineName($A)) && str_contains($text, PB::lineName($B));
            $money = str_contains($text, '2,000') && str_contains($text, '1,000') && str_contains($text, number_format((float) $order->total_price));
            ok($names && $money, "$viewName: both products, their line totals and the order total", $names ? 'money missing' : 'names missing');
        } catch (\Throwable $e) {
            ok(false, "$viewName renders", $e->getMessage());
        }
    }

    echo "── Staging hint + the original Shopify order is untouched\n";
    ok(str_starts_with((string) \App\Models\CRM\ShopifyOrderModel::with('lineItems')->find($soId)->lineItems->first()->bundle_hint, '= 4 × '), 'approval hint present on the staging line');
    ok((int) DB::table('t_crm_shopify_order_line_item')->where('order_id', $soId)->count() === 2, 'staging lines unchanged (still 2)');
} finally {
    DB::rollBack();
}
PB::forget();
ok(!DB::table('t_crm_prod_order')->where('order_number', 'SH-ZZE2E1')->exists() && !DB::table('t_crm_prod_product')->where('title', 'ZZ E2E Bundle')->exists(), 'rolled back — nothing left behind');
echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILURES") . " — $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
