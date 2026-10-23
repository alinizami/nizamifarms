<?php
/**
 * PROOF — web files uploaded BEFORE the Oct-19 SQL (original_quantity missing) still work.
 *
 * Run ONLY with the column dropped, then put it back:
 *   mysql … -e "ALTER TABLE t_crm_prod_order_line_item DROP COLUMN original_quantity"
 *   php test_original_quantity_presql_oct19.php
 *   mysql … < database/migrations/line_item_original_quantity_oct2026.sql
 *
 * Every write is inside a transaction that is ROLLED BACK.
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\API\RiderController;
use App\Http\Controllers\CRM\OrderController;
use App\Models\CRM\OrderLineItemModel;
use App\Models\CRM\OrderModel;
use App\Services\CRM\LineItemQuantityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function check(string $what, $got, $want): void {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ✓ $what\n"; }
    else { $fail++; echo "  ✗ $what\n      got:  " . var_export($got, true) . "\n      want: " . var_export($want, true) . "\n"; }
}
function mreq(string $method = 'POST', array $params = []): Request {
    $r = Request::create('/x', $method, $params);
    $r->setUserResolver(fn () => Auth::user());
    app()->instance('request', $r);
    return $r;
}
function boxLabel(int $plu): string {
    $body = '20' . str_pad((string) $plu, 5, '0', STR_PAD_LEFT) . '00001';
    $sum = 0;
    foreach (str_split($body) as $i => $d) { $sum += (int) $d * ($i % 2 === 0 ? 1 : 3); }
    return $body . ((10 - $sum % 10) % 10);
}

$has = DB::selectOne("SHOW COLUMNS FROM t_crm_prod_order_line_item LIKE 'original_quantity'");
if ($has) { echo "⚠ the column still exists — drop it first (see the header)\n"; exit(2); }
check('guard says NO', OrderLineItemModel::supportsOriginalQuantity(), false);

$box = DB::table('t_crm_prod_order_line_item as li')
    ->join('t_crm_prod_order as o', 'o.id', '=', 'li.order_id')
    ->join('t_crm_prod_product_variant as pv', 'pv.sku', '=', 'li.sku')
    ->join('t_crm_prod_product as p', 'p.id', '=', 'pv.product_id')
    ->whereNotIn('o.order_status', LineItemQuantityService::CLOSED_STATUSES)
    ->whereNull('o.ledger_transaction_id')->where('p.sell_unit', 'pcs')->where('li.quantity', '>=', 1)
    ->orderByDesc('o.id')->first(['o.id as oid', 'li.id as lid', 'li.sku', 'li.quantity', 'p.czerlop_product_id as plu']);

DB::beginTransaction();
try {
    Auth::shouldUse('web'); Auth::loginUsingId(74);
    $q = (int) round((float) $box->quantity);
    $res = (new RiderController())->confirmLineItemBoxScan(mreq('POST', ['scanned_barcode' => boxLabel((int) $box->plu), 'quantity' => $q + 1]), $box->oid, $box->lid);
    $b = json_decode($res->getContent(), true);
    check('box count saves (200)', $res->getStatusCode(), 200);
    check('quantity moved', (float) OrderLineItemModel::find($box->lid)->quantity, (float) ($q + 1));
    check('original reported as null', array_key_exists('original_quantity', $b) ? $b['original_quantity'] : 'missing', null);

    $light = (new RiderController())->getStoreOpenOrdersLight(mreq('GET'));
    check('light list still answers 200 (no missing-column SELECT)', $light->getStatusCode(), 200);
    $details = (new RiderController())->getStoreOpenOrderDetails($box->oid);
    check('order details still 200', $details->getStatusCode(), 200);

    Auth::loginUsingId(79);
    $order = OrderModel::with('lineItems')->find($box->oid);
    $items = []; $sub = 0;
    foreach ($order->lineItems as $li) {
        $qq = $li->id === $box->lid ? (float) $li->quantity + 1 : (float) $li->quantity;
        $lt = (int) $li->is_free ? 0 : round($qq * (float) $li->unit_price, 2);
        $sub += $lt;
        $items[] = ['name' => $li->name, 'quantity' => $qq, 'unit_price' => (float) $li->unit_price, 'line_total' => $lt,
            'sku' => $li->sku, 'variant_id' => $li->variant_id ? (string) $li->variant_id : null,
            'product_id' => $li->product_id ? (string) $li->product_id : null, 'is_free' => (bool) $li->is_free];
    }
    $res = app(OrderController::class)->update(mreq('PUT', ['_partial_update' => 1, 'order_date' => (string) $order->created_at,
        'items' => $items, 'subtotal_price' => $sub, 'shipping_total' => (float) $order->shipping_total,
        'total_price' => $sub + (float) $order->shipping_total, 'customer_id' => $order->customer_id, 'name' => $order->name]), $box->oid);
    check('web Edit Invoice save with a changed qty still 200 (no INSERT of a missing column)', $res->getStatusCode(), 200);
} finally {
    DB::rollBack();
}
echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILURES") . " — $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
