<?php
/**
 * PROOF — ✂ Split invoice (Oct-19-2026). Runs on the local replica inside ONE
 * transaction that is ROLLED BACK at the end: nothing persists.
 *   php test_order_split_oct19.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CRM\OrderLineItemModel;
use App\Models\CRM\OrderModel;
use App\Models\User;
use App\Services\CRM\OrderSplitService;
use App\Services\Payments\Signals\PaymentProofStatusService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function ok($cond, $label) { global $pass, $fail; if ($cond) { $pass++; echo "  ✔ $label\n"; } else { $fail++; echo "  ✘ $label\n"; } }

$farooq = User::find(74);   // supervisor 2
$shabib = User::find(79);   // Management
$taimur = User::find(68);   // Taimur
$rider  = User::find(75);   // a rider role — no grant
$svc = app(OrderSplitService::class);

DB::beginTransaction();
try {
    echo "\n[1] Permission gate\n";
    ok($svc->userCanSplit($farooq), 'Farooq (supervisor 2) may split');
    ok($svc->userCanSplit($shabib), 'Shabib (Management) may split');
    ok($svc->userCanSplit($taimur), 'Taimur may split');
    ok(!$svc->userCanSplit($rider), 'a rider may NOT split');
    $r = $svc->split(18522, [1], $rider);
    ok(!$r['success'] && $r['code'] === 'forbidden', 'rider split refused (forbidden)');

    echo "\n[2] Preview — SH-23808 (8 lines, shipping 300)\n";
    $o = OrderModel::find(18522);
    $o->expected_packets = 4; $o->save();                  // pretend the store already counted
    $lines = OrderLineItemModel::where('order_id', 18522)->orderBy('id')->get();
    $before = $lines->mapWithKeys(fn ($l) => [$l->id => [$l->preparation_status, $l->inventory_deducted, $l->quantity_source, (float) $l->line_total]])->all();
    $origTotalBefore = (float) $o->total_price;
    $p0 = $svc->preview($o, []);
    ok($p0['eligible'] && !$p0['ready'] && count($p0['lines']) === 8, 'no selection → eligible, 8 lines, not ready');
    ok($p0['new_order_number'] === 'SH-23808-A', 'next number SH-23808-A');
    $move = [$lines[1]->id, $lines[4]->id];
    $moveSum = round((float) $lines[1]->line_total + (float) $lines[4]->line_total, 2);
    $p1 = $svc->preview($o, $move);
    ok($p1['ready'] && $p1['new']['total'] == $moveSum, "new total = moved lines ({$moveSum})");
    ok($p1['original']['total'] == round($origTotalBefore - $moveSum, 2), 'original total = old − moved (shipping stays)');
    ok($p1['original']['shipping'] == 300 && $p1['new']['shipping'] == 0, 'shipping 300 stays on original, 0 on new');
    $pAll = $svc->preview($o, $lines->pluck('id')->all());
    ok(!$pAll['ready'], 'all 8 ticked → not allowed: ' . $pAll['hint']);
    $pForeign = $svc->preview($o, [$lines[0]->id, 999999999]);
    ok(!$pForeign['ready'], 'foreign line id → refused');

    echo "\n[3] Split as Farooq\n";
    $auditBefore = DB::table('t_sys_audit_log')->count();
    $r = $svc->split(18522, $move, $farooq);
    ok($r['success'], 'split succeeded: ' . $r['message']);
    $o->refresh();
    $a = OrderModel::where('order_number', 'SH-23808-A')->first();
    ok($a && (int) $a->split_from_order_id === 18522, 'SH-23808-A exists, points at root 18522');
    ok($a->order_status === 'new' && empty($a->assigned_rider_user_id), 'new invoice: status new, no rider');
    ok((int) $a->customer_id === (int) $o->customer_id && $a->address_line1 === $o->address_line1 && $a->payment_method === $o->payment_method, 'same customer, address, payment method');
    ok(empty($a->ledger_transaction_id) && (float) $a->total_paid == 0 && empty($a->external_id), 'no ledger link, nothing paid, no external id');
    ok(OrderLineItemModel::whereIn('id', $move)->where('order_id', $a->id)->count() === 2, 'the 2 lines now belong to -A (same ids)');
    ok(OrderLineItemModel::where('order_id', 18522)->count() === 6, 'original keeps 6 lines');
    $same = true;
    foreach (OrderLineItemModel::whereIn('id', $lines->pluck('id'))->get() as $l) {
        if ([$l->preparation_status, $l->inventory_deducted, $l->quantity_source, (float) $l->line_total] !== $before[$l->id]) { $same = false; }
    }
    ok($same, 'prep status, stock flag, scan source, line totals untouched on every line');
    ok((float) $a->total_price == $moveSum && (float) $a->shipping_total == 0, "-A total {$a->total_price} = moved lines, no delivery charge");
    ok(abs((float) $o->total_price - ($origTotalBefore - $moveSum)) < 0.01 && (float) $o->shipping_total == 300, "original total {$o->total_price} (shipping kept)");
    ok(abs(((float) $o->total_price + (float) $a->total_price) - ($origTotalBefore)) < 0.01, 'the two totals add up to the old total');
    ok($o->expected_packets === null && $r['packets_reset'] === 4, 'packet count 4 cleared on original, reported back');
    ok(DB::table('t_crm_order_status_history')->where('order_id', $a->id)->where('status_code', 'new')->where('is_current', 1)->exists(), '-A has its initial status history row');
    ok(DB::table('t_sys_audit_log')->count() - $auditBefore >= 2
        && DB::table('t_sys_audit_log')->where('action', 'split')->where('related_order_id', 18522)->exists()
        && DB::table('t_sys_audit_log')->where('action', 'split_created')->where('related_order_id', $a->id)->exists(), 'audit rows on BOTH orders');
    ok(DB::table('t_fin_ledger')->whereIn('order_id', [18522, $a->id])->count() === 0, 'zero ledger rows for either (pre-delivery)');

    echo "\n[4] Split again → letters off the ROOT\n";
    $l2 = OrderLineItemModel::where('order_id', 18522)->orderBy('id')->first();
    $r = $svc->split(18522, [$l2->id], $shabib);
    ok($r['success'] && $r['new']['order_number'] === 'SH-23808-B', 'second split of original → SH-23808-B');
    $aLine = OrderLineItemModel::where('order_id', $a->id)->orderBy('id')->first();
    $r = $svc->split($a->id, [$aLine->id], $taimur);
    ok($r['success'] && $r['new']['order_number'] === 'SH-23808-C', 'splitting -A → SH-23808-C (root letters)');
    $c = OrderModel::where('order_number', 'SH-23808-C')->first();
    ok((int) $c->split_from_order_id === 18522, '-C also points at the root');
    $rel = OrderSplitService::relationsFor([18522, $a->id, $c->id]);
    ok(($rel[18522]['split_into'] ?? []) === ['SH-23808-A', 'SH-23808-B', 'SH-23808-C'], 'root lists A, B, C');
    ok(($rel[$a->id]['split_from'] ?? null) === 'SH-23808' && ($rel[$c->id]['split_from'] ?? null) === 'SH-23808', 'parts name the root');
    $r = $svc->split($a->id, [OrderLineItemModel::where('order_id', $a->id)->value('id')], $farooq);
    ok(!$r['success'], '-A down to 1 item → cannot split again: ' . $r['message']);

    echo "\n[5] Refusals\n";
    $r = $svc->split(18521, [1], $shabib);
    ok(!$r['success'] && $r['code'] === 'blocked', 'delivered order refused: ' . $r['message']);
    $x = OrderModel::find(18513); $x->order_status = 'out_for_delivery'; $x->save();
    $r = $svc->split(18513, [OrderLineItemModel::where('order_id', 18513)->value('id')], $shabib);
    ok(!$r['success'], 'out for delivery refused: ' . $r['message']);
    $x->order_status = 'new'; $x->ledger_transaction_id = 1; $x->save();
    $r = $svc->split(18513, [OrderLineItemModel::where('order_id', 18513)->value('id')], $shabib);
    ok(!$r['success'], 'invoiced refused: ' . $r['message']);
    $x->ledger_transaction_id = null; $x->discount_total = 11000; $x->save();
    $big = OrderLineItemModel::where('order_id', 18513)->orderByDesc('line_total')->first();
    $r = $svc->split(18513, [$big->id], $shabib);
    ok(!$r['success'] && $r['code'] === 'bad_selection', 'discount bigger than what stays → refused: ' . $r['message']);
    ok(OrderModel::where('order_number', 'SH-23805-A')->doesntExist(), '…and nothing was created');
    $r = $svc->split(18518, [OrderLineItemModel::where('order_id', 18518)->value('id')], $shabib);
    ok(!$r['success'], 'single-line order refused: ' . $r['message']);

    echo "\n[6] Already-paid order — SH-23803 (has a proof)\n";
    $pr = $svc->preview(OrderModel::find(18510), []);
    ok(!empty($pr['proof']), 'preview reports the proof: ' . ($pr['proof']['label'] ?? '—'));
    $pl = OrderLineItemModel::where('order_id', 18510)->orderBy('id')->get();
    $r = $svc->split(18510, [$pl[0]->id], $shabib);
    ok(!$r['success'] && $r['code'] === 'needs_paid_answer', 'no answer → asked (409 needs_paid_answer)');
    ok(OrderModel::where('order_number', 'SH-23803-A')->doesntExist(), '…and nothing was created');
    $r = $svc->split(18510, [$pl[0]->id], $shabib, true);
    ok($r['success'] && $r['proof_linked'] >= 1, "answered YES → split + {$r['proof_linked']} proof(s) linked");
    $pa = OrderModel::where('order_number', 'SH-23803-A')->first();
    $ps = app(PaymentProofStatusService::class)->forOrders([18510, $pa->id], false);
    ok($ps[$pa->id]['status'] !== 'none', '-A shows the proof badge: ' . $ps[$pa->id]['label']);
    ok(!empty($ps[$pa->id]['is_combined']) && ($ps[$pa->id]['combined_count'] ?? 0) === 2, '-A badge says combined (1 of 2)');
    ok($ps[18510]['status'] !== 'none', 'original keeps its proof badge');
    $shares = DB::table('t_fin_payment_signal_order')->whereIn('order_id', [18510, $pa->id])->sum('balance_at_match');
    $sigs = DB::table('t_fin_payment_signal_order')->whereIn('order_id', [18510, $pa->id])->distinct()->count('signal_id');
    ok(abs($shares / max(1, $sigs) - 9800) < 0.01, 'per signal, the two shares add up to the old 9,800');
    $r = $svc->split(18510, [$pl[1]->id], $shabib, false);
    $pb = OrderModel::where('order_number', 'SH-23803-B')->first();
    ok($r['success'] && $r['proof_linked'] === 0 && app(PaymentProofStatusService::class)->forOrder($pb->id, false)['status'] === 'none', 'answered NO → -B split with no proof');

    echo "\n[7] HTTP doors (same controller, web + api)\n";
    Auth::setUser($farooq);
    $ctl = app(\App\Http\Controllers\CRM\OrderSplitController::class);
    $resp = $ctl->preview(\Illuminate\Http\Request::create('/api/rider/orders/18511/split/preview', 'GET', ['line_ids' => '']), 18511);
    ok($resp->getStatusCode() === 200 && $resp->getData(true)['eligible'] === true, 'preview via controller (no ids) 200 eligible');
    $first = OrderLineItemModel::where('order_id', 18511)->orderBy('id')->value('id');
    $resp = $ctl->store(\Illuminate\Http\Request::create('/api/rider/orders/18511/split', 'POST', ['line_ids' => [$first]]), 18511);
    ok($resp->getStatusCode() === 200 && $resp->getData(true)['new']['order_number'] === 'SH-23804-A', 'store via controller → SH-23804-A');
    Auth::setUser($rider);
    $resp = $ctl->preview(\Illuminate\Http\Request::create('/x', 'GET'), 18511);
    ok($resp->getStatusCode() === 403, 'rider → 403 on preview');
    $routes = collect(app('router')->getRoutes())->filter(fn ($rt) => str_contains($rt->uri(), 'split') && str_contains($rt->getActionName(), 'OrderSplitController'));
    ok($routes->count() === 4, 'web + api routes both point at OrderSplitController (4 routes)');
} catch (\Throwable $e) {
    $fail++;
    echo "  ✘ EXCEPTION: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
}

echo "\n" . ($fail === 0 ? "ALL GREEN" : "FAILURES") . " — {$pass} passed, {$fail} failed (rolled back)\n";
echo 'after rollback: SH-23808-A exists? ' . (OrderModel::where('order_number', 'SH-23808-A')->exists() ? 'YES ✘' : 'no ✔') . "\n";
