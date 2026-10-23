<?php
/**
 * REVIEW PROOF — ✂ Split invoice, downstream behaviour (Oct-19-2026).
 * Replica, ONE transaction, ROLLED BACK. Covers what test_order_split_oct19.php does not:
 * new-order numbering beside a -A row, the stale web edit form, delivery → ledger invoice
 * for BOTH halves (cash + online), customer/address fields, invoice send plan, customer app.
 *   php test_order_split_review_oct19.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CRM\OrderLineItemModel;
use App\Models\CRM\OrderModel;
use App\Models\User;
use App\Services\CRM\OrderSplitService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function ok($cond, $label) { global $pass, $fail; if ($cond) { $pass++; echo "  ✔ $label\n"; } else { $fail++; echo "  ✘ $label\n"; } }

$shabib = User::find(79);
$svc = app(OrderSplitService::class);
Auth::setUser($shabib);

DB::beginTransaction();
try {
    echo "\n[1] Numbering — a -A row must not disturb the NF-/SH- sequence\n";
    echo "    sql_mode: " . DB::selectOne('SELECT @@SESSION.sql_mode m')->m . "\n";
    $maxBefore = (int) DB::selectOne("SELECT MAX(CAST(SUBSTRING(order_number, 4) AS UNSIGNED)) m FROM t_crm_prod_order WHERE order_number LIKE 'NF-%'")->m;
    $nf = OrderModel::where('order_number', 'NF-' . $maxBefore)->first();      // the newest NF order
    ok($nf !== null && OrderLineItemModel::where('order_id', $nf->id)->count() >= 1, "newest NF order is NF-{$maxBefore}");
    // give it a 2nd line so it can be split, then split it
    $l = OrderLineItemModel::where('order_id', $nf->id)->first();
    $copy = $l->replicate(); $copy->save();
    $nf->order_status = 'new'; $nf->ledger_transaction_id = null; $nf->save();
    $r = $svc->split($nf->id, [$copy->id], $shabib, false);
    ok($r['success'] && $r['new']['order_number'] === "NF-{$maxBefore}-A", "split → NF-{$maxBefore}-A");
    $maxAfter = (int) DB::selectOne("SELECT MAX(CAST(SUBSTRING(order_number, 4) AS UNSIGNED)) m FROM t_crm_prod_order WHERE order_number LIKE 'NF-%'")->m;
    ok($maxAfter === $maxBefore, "the store() MAX query still answers {$maxBefore} (no error under strict mode, sequence unchanged)");
    // the real create path: a brand-new NF order must get NF-{max+1}
    $firstLine = OrderLineItemModel::where('order_id', $nf->id)->first();
    $ctl = app(\App\Http\Controllers\CRM\OrderController::class);
    $req = \Illuminate\Http\Request::create('/orders', 'POST', [
        'customer_id' => $nf->customer_id, 'payment_method' => 'cash', 'order_date' => now()->format('Y-m-d'),
        'order_status' => 'new',
        'items' => [['product_id' => (string) $firstLine->product_id, 'variant_id' => (string) $firstLine->variant_id, 'name' => $firstLine->name, 'sku' => (string) $firstLine->sku, 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]],
        'subtotal_price' => 100, 'total_price' => 100, 'shipping_total' => 0,
    ]);
    $req->headers->set('Accept', 'application/json');
    $resp = $ctl->store($req);
    $body = json_decode($resp->getContent(), true);
    $newNum = $body['order']['order_number'] ?? null;
    ok($resp->getStatusCode() === 200 && $newNum === 'NF-' . ($maxBefore + 1), "the REAL create path, beside NF-{$maxBefore}-A, numbers the next order NF-" . ($maxBefore + 1) . " (got " . var_export($newNum, true) . ')');

    echo "\n[2] Stale web edit form cannot re-add the moved items\n";
    $o = OrderModel::find(18522);                      // SH-23808, 8 lines
    $loadedAt = $o->updated_at->copy();
    $lines = OrderLineItemModel::where('order_id', 18522)->orderBy('id')->get();
    sleep(3);                                           // the guard allows a 2 s skew
    $r = $svc->split(18522, [$lines[0]->id], $shabib);
    ok($r['success'], 'split SH-23808 (1 line) → ' . $r['new']['order_number']);
    $stale = \Illuminate\Http\Request::create('/orders/18522', 'PUT', [
        'expected_updated_at' => $loadedAt->format('Y-m-d H:i:s'),
        'items' => $lines->map(fn ($x) => ['product_id' => $x->product_id, 'variant_id' => $x->variant_id, 'name' => $x->name, 'sku' => $x->sku, 'quantity' => (float) $x->quantity, 'unit_price' => (float) $x->unit_price, 'line_total' => (float) $x->line_total])->all(),
        'subtotal_price' => 21515, 'total_price' => 21815, 'shipping_total' => 300, 'payment_method' => 'cash',
    ]);
    $stale->headers->set('Accept', 'application/json');
    $resp = $ctl->update($stale, 18522);
    ok($resp->getStatusCode() === 409, 'a form opened BEFORE the split gets 409 on save (status ' . $resp->getStatusCode() . ')');
    ok(OrderLineItemModel::where('order_id', 18522)->count() === 7, 'original still has 7 lines (nothing re-added)');

    echo "\n[3] Customer + address fields on the new invoice\n";
    $a = OrderModel::where('order_number', $r['new']['order_number'])->first();
    $same = ['customer_id', 'name', 'contact_email', 'address_first_name', 'address_last_name', 'address_phone', 'address_line1', 'address_line2', 'address_city', 'address_province', 'address_postal_code', 'address_country', 'payment_method', 'delivery_region_id', 'order_source_channel', 'external_source', 'note', 'currency'];
    $diff = [];
    foreach ($same as $f) { if ((string) $o->$f !== (string) $a->$f) { $diff[] = $f; } }
    ok(empty($diff), 'identical: ' . implode(', ', $same) . (empty($diff) ? '' : ' — DIFF: ' . implode(', ', $diff)));
    ok($a->order_date->eq($o->order_date), 'same order_date (sits beside the original in every list)');
    $empty = ['ledger_transaction_id', 'external_id', 'assigned_rider_user_id', 'estimated_delivery_at', 'delivery_priority', 'van_user_id', 'van_trip_id', 'dispatch_scanned_at', 'delivery_scanned_at', 'invoice_request_id', 'expected_packets', 'paid_stamp_date', 'online_message_sent_at'];
    $leak = [];
    foreach ($empty as $f) { if (!empty($a->$f)) { $leak[] = $f; } }
    ok(empty($leak), 'empty on the new invoice: ' . implode(', ', $empty) . (empty($leak) ? '' : ' — LEAK: ' . implode(', ', $leak)));
    ok($a->payment_status === 'unpaid' && (float) $a->total_paid == 0 && (int) $a->converted === 0, 'unpaid, nothing paid, not converted');
    ok((int) $a->created_by === 79, 'created_by = the person who split (Shabib)');
    $hist = DB::table('t_crm_order_status_history')->where('order_id', $a->id)->get();
    ok($hist->count() === 1 && $hist[0]->status_code === 'new' && (int) $hist[0]->is_current === 1, 'exactly one status-history row: new, current');

    echo "\n[4] Deliver BOTH halves — each posts its OWN ledger invoice (cash)\n";
    $rider = DB::table('t_sys_user')->where('id', 75)->value('id');      // Kamran
    $o->refresh();
    $o->assignRider($rider, 79); $a->assignRider($rider, 79);
    $o->refresh(); $a->refresh();
    ok((int) $o->assigned_rider_user_id === 75 && (int) $a->assigned_rider_user_id === 75, 'rider 75 assigned to both');
    ok($a->changeStatus('delivered', 'review proof', 79), 'new invoice delivered');
    $a->refresh();
    $la = DB::table('t_fin_ledger')->where('id', $a->ledger_transaction_id)->first();
    ok($la && $la->transaction_type === 'invoice' && abs((float) $la->amount - (float) $a->total_price) < 0.01 && (int) $la->order_id === (int) $a->id,
        "ledger invoice #{$a->ledger_transaction_id} for {$a->order_number}: Rs {$la->amount} = its total, approved={$la->approval_status}");
    ok($o->refresh()->ledger_transaction_id === null, 'original NOT invoiced by the new one\'s delivery');
    ok($o->changeStatus('delivered', 'review proof', 79), 'original delivered');
    $o->refresh();
    $lo = DB::table('t_fin_ledger')->where('id', $o->ledger_transaction_id)->first();
    ok($lo && abs((float) $lo->amount - (float) $o->total_price) < 0.01 && (int) $lo->order_id === (int) $o->id, "ledger invoice #{$o->ledger_transaction_id} for {$o->order_number}: Rs {$lo->amount} = its reduced total");
    ok(abs(((float) $lo->amount + (float) $la->amount) - 21815) < 0.01, 'the two invoices add up to the old Rs 21,815');
    ok(DB::table('t_fin_ledger')->whereIn('order_id', [$o->id, $a->id])->where('transaction_type', 'invoice')->count() === 2, 'exactly 2 invoice rows, no duplicates');
    ok($lo->to_account_id === $la->to_account_id, 'both land in the same rider cash account');
    $blocked = $svc->split($o->id, [OrderLineItemModel::where('order_id', $o->id)->value('id')], $shabib);
    ok(!$blocked['success'], 'delivered + invoiced original now refuses a split: ' . $blocked['message']);

    echo "\n[5] Online half — goes to Online Approvals with ITS amount\n";
    $on = OrderModel::find(18507);                                      // SH-23800, bank_transfer, 3 lines
    $onl = OrderLineItemModel::where('order_id', 18507)->orderBy('id')->get();
    $r = $svc->split(18507, [$onl[0]->id], $shabib, false);
    ok($r['success'], 'split SH-23800 → ' . $r['new']['order_number']);
    $b = OrderModel::where('order_number', $r['new']['order_number'])->first();
    ok($b->payment_method === 'bank_transfer', 'new invoice keeps bank_transfer');
    $b->assignRider(75, 79); $b->refresh();
    ok($b->changeStatus('delivered', 'review proof', 79), 'delivered');
    $b->refresh();
    $lb = DB::table('t_fin_ledger')->where('id', $b->ledger_transaction_id)->first();
    ok($lb && $lb->mode === 'online' && in_array($lb->approval_status, ['pending_l1', 'pending_l2', 'approved'], true) && abs((float) $lb->amount - (float) $b->total_price) < 0.01,
        "online invoice row: mode={$lb->mode}, status={$lb->approval_status}, Rs {$lb->amount}");
    Auth::setUser($shabib);
    $ac = app(\App\Http\Controllers\ApprovalController::class);
    $queue = DB::table('t_fin_ledger')->where('order_id', $b->id)->where('transaction_type', 'invoice')->where('approval_status', 'like', 'pending%')->exists();
    ok($queue, 'sits in the online approvals queue (pending row present)');

    echo "\n[6] Invoice send plan + customer app for a -A number\n";
    $plan = app(\App\Services\WhatsApp\InvoiceSendPlanService::class)->for((int) $a->id, false);
    ok(!empty($plan['template_name'] ?? $plan['template'] ?? null) || !empty($plan), 'send plan built: ' . json_encode(array_intersect_key($plan, array_flip(['template_name', 'template', 'body_params', 'variables']))));
    ok(in_array($a->order_number, (array) ($plan['body_params'] ?? $plan['variables'] ?? []), true) || str_contains(json_encode($plan), $a->order_number), 'the -A number is in the template variables');
    $cac = app(\App\Http\Controllers\API\CustomerAppController::class);
    $m = new ReflectionMethod($cac, 'resolveAnyOrderNumber'); $m->setAccessible(true);
    ok($m->invoke($cac, '23808-A') === 'SH-23808-A', 'customer app "23808-A" → SH-23808-A');
    $listed = DB::table('t_crm_prod_order')->where('customer_id', $o->customer_id)->whereIn('id', [$o->id, $a->id])->count();
    ok($listed === 2, 'both invoices belong to the same customer (customer-app order list shows both)');

    echo "\n[7] Mobile store list carries what the card needs\n";
    \Laravel\Sanctum\Sanctum::actingAs(User::find(74), ['*']);
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $o2 = OrderModel::find(18513);
    $resp = $kernel->handle(\Illuminate\Http\Request::create('/api/rider/store/open-orders-light', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']));
    $rows = collect(json_decode($resp->getContent(), true)['orders'] ?? []);
    $row = $rows->firstWhere('id', 18513);
    ok($row && array_key_exists('split_from', $row) && array_key_exists('split_into', $row) && array_key_exists('external_source', $row) && !empty($row['line_items']),
        'light row has split_from / split_into / external_source / line_items (what canOfferSplit reads)');
} catch (\Throwable $e) {
    $fail++;
    echo "  ✘ EXCEPTION: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
}

echo "\n" . ($fail === 0 ? 'ALL GREEN' : 'FAILURES') . " — {$pass} passed, {$fail} failed (rolled back)\n";
echo 'after rollback, any -A rows? ' . (OrderModel::where('order_number', 'like', '%-A')->exists() ? 'YES ✘' : 'no ✔') . "\n";
