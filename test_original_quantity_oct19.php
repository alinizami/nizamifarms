<?php
/**
 * PROOF — frozen box COUNT on scan + "originally ordered" quantity (Oct-19-2026).
 * Run: php test_original_quantity_oct19.php
 *
 *  A. The column, the guard and the keep-once rule.
 *  B. Box-scan endpoint WITH a count (Farooq): same count, different count, keep-first,
 *     set back, older phone (no count), bad counts, meat line, wrong label, closed + invoiced.
 *  C. Stock follows a changed count exactly like a weight scan (deducted line).
 *  D. ⭐⭐ Meat: a weight scan keeps the ordered kg; a second scan keeps the FIRST; same value keeps nothing.
 *  E. Manual "Save all" keeps the original too.
 *  F. Both phone payloads carry original_quantity (light list + order details).
 *  G. Web Edit Invoice save (delete + recreate): carries it, stamps a typed change, keeps the first.
 *  H. After the rollback the replica is exactly as it was.
 *  (Before-the-SQL behaviour is proven separately: test_original_quantity_presql_oct19.php.)
 *
 * Reads the dev replica. Every write is inside a transaction that is ROLLED BACK.
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\API\RiderController;
use App\Http\Controllers\CRM\OrderController;
use App\Models\CRM\OrderLineItemModel;
use App\Models\CRM\OrderModel;
use App\Models\CRM\ProductModel;
use App\Services\CRM\LineItemQuantityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

const FAROOQ = 74;
const SHABIB = 79;

$pass = 0; $fail = 0;
function section(string $t): void { echo "\n=== $t ===\n"; }
function check(string $what, $got, $want): void {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ✓ $what\n"; }
    else { $fail++; echo "  ✗ $what\n      got:  " . var_export($got, true) . "\n      want: " . var_export($want, true) . "\n"; }
}
function truthy(string $what, $got): void {
    global $pass, $fail;
    if ($got) { $pass++; echo "  ✓ $what\n"; } else { $fail++; echo "  ✗ $what (got " . var_export($got, true) . ")\n"; }
}
function near($a, $b): bool { return $a !== null && $b !== null && abs((float) $a - (float) $b) < 0.0005; }
function mreq(string $method = 'POST', array $params = []): Request {
    $r = Request::create('/x', $method, $params);
    $r->setUserResolver(fn () => Auth::user());
    app()->instance('request', $r);
    return $r;
}
function as_user(int $uid): void { Auth::shouldUse('web'); Auth::loginUsingId($uid); }
/** POST the box-scan endpoint; returns [status, body]. A validation refusal comes back as 422. */
function boxScan(int $orderId, int $lineId, string $barcode, $boxes = '__none__'): array {
    as_user(FAROOQ);
    $p = ['scanned_barcode' => $barcode];
    if ($boxes !== '__none__') { $p['quantity'] = $boxes; }
    try {
        $res = (new RiderController())->confirmLineItemBoxScan(mreq('POST', $p), $orderId, $lineId);
        return [$res->getStatusCode(), json_decode($res->getContent(), true)];
    } catch (\Illuminate\Validation\ValidationException $e) {
        return [422, ['success' => false, 'errors' => $e->errors()]];
    }
}
function weighScan(int $orderId, int $lineId, float $qty, ?string $barcode, string $source = 'barcode'): array {
    as_user(FAROOQ);
    $res = (new RiderController())->updateLineItemQuantity(
        mreq('PUT', ['quantity' => $qty, 'source' => $source, 'scanned_barcode' => $barcode]), $orderId, $lineId);
    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
}
function line(int $id): OrderLineItemModel { return OrderLineItemModel::find($id); }
/** The printed box label: flag 20, PLU, one unit, EAN-13 check digit. */
function boxLabel(int $plu): string {
    $body = '20' . str_pad((string) $plu, 5, '0', STR_PAD_LEFT) . '00001';
    $sum = 0;
    foreach (str_split($body) as $i => $d) { $sum += (int) $d * ($i % 2 === 0 ? 1 : 3); }
    return $body . ((10 - $sum % 10) % 10);
}
function weighLabel(int $plu, float $kg): string {
    $body = '20' . str_pad((string) $plu, 5, '0', STR_PAD_LEFT) . str_pad((string) round($kg * 1000), 5, '0', STR_PAD_LEFT);
    $sum = 0;
    foreach (str_split($body) as $i => $d) { $sum += (int) $d * ($i % 2 === 0 ? 1 : 3); }
    return $body . ((10 - $sum % 10) % 10);
}

/** An OPEN, NOT invoiced order line whose product (by SKU) matches $pcs. */
function fixture(bool $pcs): ?object {
    return DB::table('t_crm_prod_order_line_item as li')
        ->join('t_crm_prod_order as o', 'o.id', '=', 'li.order_id')
        ->join('t_crm_prod_product_variant as pv', 'pv.sku', '=', 'li.sku')
        ->join('t_crm_prod_product as p', 'p.id', '=', 'pv.product_id')
        ->whereNotIn('o.order_status', LineItemQuantityService::CLOSED_STATUSES)
        ->whereNull('o.ledger_transaction_id')
        ->where('p.sell_unit', $pcs ? 'pcs' : 'kg')
        ->whereNotNull('p.czerlop_product_id')
        ->where('li.quantity', '>=', 1)
        ->where(function ($q) use ($pcs) { if ($pcs) { $q->whereRaw('li.quantity = FLOOR(li.quantity)'); } else { $q->where('p.weight_factor', 1); } })
        ->orderByDesc('o.id')
        ->first(['o.id as oid', 'o.order_number', 'li.id as lid', 'li.sku', 'li.quantity', 'p.czerlop_product_id as plu', 'p.id as pid']);
}

// ─────────────────────────────────────────────────────────────────────────────
section('A. the column, the guard, the keep-once rule');
$col = DB::selectOne("SHOW COLUMNS FROM t_crm_prod_order_line_item LIKE 'original_quantity'");
check('column exists as decimal(10,3) NULL', $col ? [$col->Type, $col->Null] : null, ['decimal(10,3)', 'YES']);
truthy('supportsOriginalQuantity() says yes', OrderLineItemModel::supportsOriginalQuantity());
check('first change keeps the OLD quantity', OrderLineItemModel::originalToKeep(null, 3.0, 2.0), 3.0);
check('a later change keeps the FIRST original', OrderLineItemModel::originalToKeep('3.000', 2.0, 5.0), 3.0);
check('no move → nothing kept', OrderLineItemModel::originalToKeep(null, 3.0, 3.0), null);
check('a 0 quantity is never kept as "ordered"', OrderLineItemModel::originalToKeep(null, 0.0, 2.0), null);
check('kept to 3 decimals', OrderLineItemModel::originalToKeep(null, 1.23456, 2.0), 1.235);

$box = fixture(true);
$meat = fixture(false);
if (!$box || !$meat) { echo "\n⚠ no open un-invoiced box/meat line on the replica — cannot run B–G\n"; exit(1); }
echo "  ℹ box line:  order {$box->order_number} (#{$box->oid}) line {$box->lid} {$box->sku} qty {$box->quantity} PLU {$box->plu}\n";
echo "  ℹ meat line: order {$meat->order_number} (#{$meat->oid}) line {$meat->lid} {$meat->sku} qty {$meat->quantity} PLU {$meat->plu}\n";
$LABEL = boxLabel((int) $box->plu);
$otherPlu = (int) ProductModel::where('sell_unit', 'pcs')->where('czerlop_product_id', '!=', $box->plu)->value('czerlop_product_id');

// ─────────────────────────────────────────────────────────────────────────────
section('B. box-scan endpoint WITH a count');
DB::beginTransaction();
try {
    // start clean: an untouched line
    DB::table('t_crm_prod_order_line_item')->where('id', $box->lid)->update(['original_quantity' => null]);
    $L = line($box->lid);
    $ordered = (int) round((float) $L->quantity);
    $unit = (float) $L->unit_price;
    $orderTotal0 = (float) OrderModel::find($box->oid)->total_price;
    $lineTotal0 = (float) $L->line_total;

    // 1. Yes — the same count
    [$st, $b] = boxScan($box->oid, $box->lid, $LABEL, $ordered);
    check('1. same count → 200', $st, 200);
    truthy('   quantity_applied: true', !empty($b['quantity_applied']));
    check('   quantity unchanged', (float) line($box->lid)->quantity, (float) $ordered);
    check('   source = box_scan', line($box->lid)->quantity_source, 'box_scan');
    check('   original stays NULL (nothing changed)', line($box->lid)->original_quantity, null);
    check('   response original_quantity null', array_key_exists('original_quantity', $b) ? $b['original_quantity'] : 'missing', null);
    check('   order total unchanged', (float) OrderModel::find($box->oid)->total_price, $orderTotal0);

    // 2. a different count
    $new = $ordered > 1 ? $ordered - 1 : $ordered + 1;
    [$st, $b] = boxScan($box->oid, $box->lid, $LABEL, $new);
    $L = line($box->lid);
    check("2. count {$ordered} → {$new}: 200", $st, 200);
    check('   quantity is the new count', (float) $L->quantity, (float) $new);
    check('   original_quantity = the ordered count', (float) $L->original_quantity, (float) $ordered);
    check('   response carries it', (float) ($b['original_quantity'] ?? -1), (float) $ordered);
    check('   response new_quantity', (float) ($b['new_quantity'] ?? -1), (float) $new);
    check('   line total = unit × new count', (float) $L->line_total, (int) $L->is_free ? 0.0 : round($unit * $new, 2));
    $expectTotal = round($orderTotal0 - $lineTotal0 + (float) $L->line_total, 2);
    check('   order total moved by exactly the line difference', (float) OrderModel::find($box->oid)->total_price, $expectTotal);
    check('   still stamped box_scan + the label', [$L->quantity_source, $L->quantity_scanned_barcode], ['box_scan', $LABEL]);
    check('   stamped by Farooq', (int) $L->quantity_updated_by, FAROOQ);

    // 3. another change keeps the FIRST original
    [$st, $b] = boxScan($box->oid, $box->lid, $LABEL, $new + 2);
    check('3. a second change keeps the FIRST original', (float) line($box->lid)->original_quantity, (float) $ordered);
    check('   …and the quantity follows', (float) line($box->lid)->quantity, (float) ($new + 2));

    // 4. set back to the order
    [$st, $b] = boxScan($box->oid, $box->lid, $LABEL, $ordered);
    check('4. set back → quantity = ordered', (float) line($box->lid)->quantity, (float) $ordered);
    check('   original still kept (screens hide it because they match)', (float) line($box->lid)->original_quantity, (float) $ordered);
    check('   order total back to where it started', (float) OrderModel::find($box->oid)->total_price, $orderTotal0);

    // 5. an OLDER phone: no count at all
    [$st, $b] = boxScan($box->oid, $box->lid, $LABEL);
    check('5. older phone (no count) → 200', $st, 200);
    truthy('   NO quantity_applied key — the old contract', !array_key_exists('quantity_applied', $b));
    check('   quantity untouched', (float) line($box->lid)->quantity, (float) $ordered);

    // 6. bad counts are refused before anything is touched
    foreach ([0, 2.5, 1000, 'abc', -1] as $bad) {
        [$st, $b] = boxScan($box->oid, $box->lid, $LABEL, $bad);
        check('6. count ' . var_export($bad, true) . ' refused (422)', $st, 422);
    }
    check('   quantity still untouched', (float) line($box->lid)->quantity, (float) $ordered);

    // 7. a MEAT line can never be counted through the box door
    $meatQty0 = (float) line($meat->lid)->quantity;
    [$st, $b] = boxScan($meat->oid, $meat->lid, weighLabel((int) $meat->plu, 1.0), 5);
    check('7. meat line via box endpoint → not_a_box', $b['code'] ?? null, 'not_a_box');
    check('   meat quantity untouched', (float) line($meat->lid)->quantity, $meatQty0);

    // 8. a label of ANOTHER box product
    [$st, $b] = boxScan($box->oid, $box->lid, boxLabel($otherPlu), 2);
    check('8. foreign label → plu_mismatch', $b['code'] ?? null, 'plu_mismatch');
    check('   quantity untouched', (float) line($box->lid)->quantity, (float) $ordered);

    // 9. invoiced, then closed
    DB::table('t_crm_prod_order')->where('id', $box->oid)->update(['ledger_transaction_id' => (int) DB::table('t_fin_ledger')->max('id')]);
    [$st, $b] = boxScan($box->oid, $box->lid, $LABEL, $ordered + 1);
    check('9. invoiced order + different count → refused "invoiced"', $b['code'] ?? null, 'invoiced');
    check('   quantity untouched', (float) line($box->lid)->quantity, (float) $ordered);
    DB::table('t_crm_prod_order')->where('id', $box->oid)->update(['ledger_transaction_id' => null, 'order_status' => 'delivered']);
    [$st, $b] = boxScan($box->oid, $box->lid, $LABEL, $ordered + 1);
    check('   delivered order → refused "not_open"', $b['code'] ?? null, 'not_open');
    check('   quantity untouched', (float) line($box->lid)->quantity, (float) $ordered);
} finally {
    DB::rollBack();
}

// ─────────────────────────────────────────────────────────────────────────────
section('C. stock follows a changed count (deducted line)');
DB::beginTransaction();
try {
    $L = line($box->lid);
    DB::table('t_crm_prod_order_line_item')->where('id', $L->id)->update(['inventory_deducted' => 0, 'original_quantity' => null]);
    $L = line($box->lid);
    $variantId = $L->variant_id ?: DB::table('t_crm_prod_product_variant')->where('sku', $L->sku)->value('id');
    $stock0 = (float) DB::table('t_crm_prod_product_variant')->where('id', $variantId)->value('inventory_quantity');
    $L->deductInventory();
    $q = (float) $L->quantity;
    $stock1 = (float) DB::table('t_crm_prod_product_variant')->where('id', $variantId)->value('inventory_quantity');
    check('deducted at the ordered count', round($stock0 - $stock1, 3), round($q, 3));
    [$st, $b] = boxScan($box->oid, $box->lid, $LABEL, (int) $q + 2);
    $stock2 = (float) DB::table('t_crm_prod_product_variant')->where('id', $variantId)->value('inventory_quantity');
    check('two more boxes → two more out of stock', round($stock1 - $stock2, 3), 2.0);
    truthy('line still marked deducted', (bool) line($box->lid)->inventory_deducted);
} finally {
    DB::rollBack();
}

// ─────────────────────────────────────────────────────────────────────────────
section('D. ⭐⭐ meat: a weight scan keeps the ordered kg');
DB::beginTransaction();
try {
    DB::table('t_crm_prod_order_line_item')->where('id', $meat->lid)->update(['original_quantity' => null]);
    $q0 = (float) line($meat->lid)->quantity;
    $kg1 = round($q0 + 0.15, 2);
    [$st, $b] = weighScan($meat->oid, $meat->lid, $kg1, weighLabel((int) $meat->plu, $kg1));
    check('scan → 200', $st, 200);
    check('quantity = scanned kg', (float) line($meat->lid)->quantity, $kg1);
    check('original_quantity = what was ordered', (float) line($meat->lid)->original_quantity, $q0);
    check('response carries it', (float) ($b['original_quantity'] ?? -1), $q0);
    check('source still barcode (meat path unchanged)', line($meat->lid)->quantity_source, 'barcode');
    $kg2 = round($q0 - 0.1, 2);
    weighScan($meat->oid, $meat->lid, $kg2, weighLabel((int) $meat->plu, $kg2));
    check('a re-scan keeps the FIRST original', (float) line($meat->lid)->original_quantity, $q0);
    check('…and takes the new weight', (float) line($meat->lid)->quantity, $kg2);

    DB::table('t_crm_prod_order_line_item')->where('id', $meat->lid)->update(['original_quantity' => null]);
    $now = (float) line($meat->lid)->quantity;
    weighScan($meat->oid, $meat->lid, $now, weighLabel((int) $meat->plu, $now));
    check('scanning the SAME weight keeps nothing', line($meat->lid)->original_quantity, null);
} finally {
    DB::rollBack();
}

// ─────────────────────────────────────────────────────────────────────────────
section('E. manual "Save all" keeps the original too');
DB::beginTransaction();
try {
    DB::table('t_crm_prod_order_line_item')->where('id', $meat->lid)->update(['original_quantity' => null]);
    $q0 = (float) line($meat->lid)->quantity;
    as_user(FAROOQ);
    $res = (new RiderController())->updateLineItemQuantitiesBatch(
        mreq('PUT', ['items' => [['line_item_id' => $meat->lid, 'quantity' => $q0 + 1, 'source' => 'manual']]]), $meat->oid);
    $b = json_decode($res->getContent(), true);
    check('batch → 200', $res->getStatusCode(), 200);
    check('original kept', (float) line($meat->lid)->original_quantity, $q0);
    check('batch row carries it', (float) ($b['items'][0]['original_quantity'] ?? -1), $q0);
} finally {
    DB::rollBack();
}

// ─────────────────────────────────────────────────────────────────────────────
section('F. both phone payloads carry original_quantity');
DB::beginTransaction();
try {
    DB::table('t_crm_prod_order_line_item')->where('id', $box->lid)->update(['original_quantity' => null]);
    boxScan($box->oid, $box->lid, $LABEL, (int) round((float) line($box->lid)->quantity) + 1);
    $origBox = (float) line($box->lid)->original_quantity;
    as_user(FAROOQ);
    $d = json_decode((new RiderController())->getStoreOpenOrderDetails($box->oid)->getContent(), true);
    $lines = $d['data']['line_items'] ?? $d['order']['line_items'] ?? $d['line_items'] ?? [];
    $hit = collect($lines)->firstWhere('id', $box->lid);
    check('details: the changed line says ordered', (float) ($hit['original_quantity'] ?? -1), $origBox);
    $other = collect($lines)->first(fn ($l) => $l['id'] !== $box->lid && empty(DB::table('t_crm_prod_order_line_item')->where('id', $l['id'])->value('original_quantity')));
    if ($other) { check('details: an untouched line says null', array_key_exists('original_quantity', $other) ? $other['original_quantity'] : 'missing', null); }

    $light = json_decode((new RiderController())->getStoreOpenOrdersLight(mreq('GET'))->getContent(), true);
    $orders = $light['data'] ?? $light['orders'] ?? [];
    if (isset($orders['data'])) { $orders = $orders['data']; }
    $o = collect($orders)->firstWhere('id', $box->oid);
    if ($o) {
        $hit = collect($o['line_items'] ?? [])->firstWhere('id', $box->lid);
        check('light list: the changed line says ordered', (float) ($hit['original_quantity'] ?? -1), $origBox);
    } else {
        echo "  ℹ light list does not include order #{$box->oid} (outside its window) — skipped\n";
    }
} finally {
    DB::rollBack();
}

// ─────────────────────────────────────────────────────────────────────────────
section('G. web Edit Invoice save (delete + recreate)');
/** Save the order through the real controller in pop-out (partial) mode as Shabib. */
$webSave = function (int $oid, array $qtyById): array {
    as_user(SHABIB);
    $order = OrderModel::with('lineItems')->find($oid);
    $items = []; $sub = 0;
    foreach ($order->lineItems as $li) {
        $q = $qtyById[$li->id] ?? (float) $li->quantity;
        $lt = (int) $li->is_free ? 0 : round($q * (float) $li->unit_price, 2);
        $sub += $lt;
        $items[] = ['name' => $li->name, 'quantity' => $q, 'unit_price' => (float) $li->unit_price, 'line_total' => $lt,
            'sku' => $li->sku, 'variant_id' => $li->variant_id ? (string) $li->variant_id : null,
            'product_id' => $li->product_id ? (string) $li->product_id : null, 'is_free' => (bool) $li->is_free];
    }
    $req = mreq('PUT', ['_partial_update' => 1, 'order_date' => (string) $order->created_at, 'items' => $items,
        'subtotal_price' => $sub, 'shipping_total' => (float) $order->shipping_total, 'total_price' => $sub + (float) $order->shipping_total,
        'customer_id' => $order->customer_id, 'name' => $order->name]);
    $res = app(OrderController::class)->update($req, $oid);
    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
};
/** The recreated line for the same product (ids change on every save). */
$sameLine = function (int $oid, string $sku) { return OrderLineItemModel::where('order_id', $oid)->where('sku', $sku)->first(); };
DB::beginTransaction();
try {
    DB::table('t_crm_prod_order_line_item')->where('id', $box->lid)->update(['original_quantity' => null]);
    $ordered = (int) round((float) line($box->lid)->quantity);
    boxScan($box->oid, $box->lid, $LABEL, $ordered + 1);              // Farooq packs one more
    [$st] = $webSave($box->oid, []);                                   // Shabib saves without touching it
    check('1. untouched save → 200', $st, 200);
    $L = $sameLine($box->oid, $box->sku);
    check('   original carried across the recreate', (float) $L->original_quantity, (float) $ordered);
    check('   source + count carried', [$L->quantity_source, (float) $L->quantity], ['box_scan', (float) ($ordered + 1)]);

    [$st] = $webSave($box->oid, [$L->id => $ordered + 3]);            // Shabib types a new number
    $L = $sameLine($box->oid, $box->sku);
    check('2. a typed change keeps the FIRST original', (float) $L->original_quantity, (float) $ordered);
    check('   …and is marked manual', $L->quantity_source, 'manual');

    // a line nobody touched before: a typed change stamps its old number
    $other = OrderLineItemModel::where('order_id', $box->oid)->where('sku', '!=', $box->sku)->whereNull('original_quantity')->first();
    if ($other) {
        $q0 = (float) $other->quantity;
        [$st] = $webSave($box->oid, [$other->id => $q0 + 1]);
        $O = $sameLine($box->oid, $other->sku);
        check('3. first typed change on a fresh line keeps its old number', (float) $O->original_quantity, $q0);
    } else {
        echo "  ℹ the box order has one product only — 3 skipped\n";
    }
} finally {
    DB::rollBack();
}

// ─────────────────────────────────────────────────────────────────────────────
section('H. after rollback nothing is left behind');
check('box line back to its replica state', (float) line($box->lid)->quantity, (float) $box->quantity);
check('meat line back to its replica state', (float) line($meat->lid)->quantity, (float) $meat->quantity);

echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILURES") . " — $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
