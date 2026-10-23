<?php

namespace App\Services\CRM;

use App\Models\CRM\OrderLineItemModel;
use App\Models\CRM\OrderModel;
use App\Models\FIN\PaymentSignal;
use App\Services\AuditLogger;
use App\Services\Payments\Signals\PaymentProofStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * ✂ SPLIT INVOICE (Oct-2026) — THE one engine.
 *
 * Items on an open invoice that can't go today are MOVED (never copied, never
 * deleted) into a new invoice numbered <root>-A, then -B, -C … The web orders
 * page and the mobile store card both call this through OrderSplitController;
 * neither decides anything itself.
 *
 * Owner rulings (Oct-19-2026):
 *  - Only BEFORE the order leaves the store (OPEN_STATUSES).
 *  - Delivery charge, discounts, account credit and tip all STAY on the
 *    original. The new invoice is delivered free (it is our shortfall); a
 *    charge can still be added on it by hand afterwards.
 *  - The new invoice starts as `new`, no rider. The team moves it on by hand.
 *  - If the original already has a payment proof, the person splitting is
 *    asked whether the moved items are covered by that same payment. Yes ⇒
 *    the proof is linked to both invoices (the matcher's own combined-payment
 *    link), so the new one shows the same Proof received / Verified badge.
 *  - Splitting again is allowed: always lettered off the ROOT number.
 *
 * Why the money side is safe: the ledger invoice is posted only when an order
 * is DELIVERED (LedgerPostingService::postInvoiceFromOrder). A split before
 * that touches no ledger row — each half posts its own invoice on its own
 * delivery. The guards below refuse everything that would break that
 * (invoiced, payments recorded, closed, Qurbani, Khaas storage, returned).
 *
 * ⭐ Lines move by UPDATE order_id: ids, prep status, scan stamps and the
 * inventory_deducted flag ride along, so no stock moves and no scan is lost.
 * ⭐ Totals: LineItemQuantityService::recomputeOrderTotals() — the existing
 * formula, not a new copy.
 */
class OrderSplitService
{
    /** Statuses where the order is still in the store. Anything else is refused. */
    public const OPEN_STATUSES = ['new', 'pending', 'processing', 'on_hold', 'on-hold', 'priority'];

    public const STATUS_FOR_NEW = 'new';

    /** Cached per request — the column arrives with add_order_split_oct2026.sql. */
    private static ?bool $ready = null;

    public function __construct(private LineItemQuantityService $lines)
    {
    }

    // =====================================================================
    // GATES
    // =====================================================================

    /**
     * The ONE permission check for both doors. The web role grant and the mobile
     * grant are the same key, seeded to the same roles; either one is enough so a
     * role ticked on only one admin page is not locked out of the other screen.
     */
    public function userCanSplit($user): bool
    {
        if (!$user) {
            return false;
        }
        try {
            return (method_exists($user, 'hasPermission') && $user->hasPermission('split_orders'))
                || (method_exists($user, 'hasMobilePermission') && $user->hasMobilePermission('split_orders'));
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function isReady(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Schema::hasColumn('t_crm_prod_order', 'split_from_order_id');
            } catch (\Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    /**
     * Why this ORDER cannot be split at all (before looking at a selection), or null.
     * Wording is for the person holding the phone: what is wrong and what to do.
     */
    public function blockedReason(OrderModel $order): ?string
    {
        if (!self::isReady()) {
            return 'Split is not switched on yet — the database update for it has not been run.';
        }

        $num = (string) $order->order_number;
        $status = (string) $order->order_status;

        if (stripos($num, 'QUR') === 0 || $order->hasQurbaniItems()) {
            return 'Qurbani invoices cannot be split.';
        }
        if (stripos($num, 'KS-') === 0 || $order->external_source === 'khaas_storage') {
            return 'Khaas storage orders cannot be split.';
        }
        if (!in_array($status, self::OPEN_STATUSES, true)) {
            $label = str_replace(['_', '-'], ' ', $status);
            return "{$num} is already {$label}. An invoice can only be split before it leaves the store.";
        }
        if (!empty($order->ledger_transaction_id)) {
            return "{$num} is already invoiced in the ledger, so it cannot be split.";
        }
        if ($order->hasPreReceivedPayments()) {
            return "{$num} has payments recorded against it, so it cannot be split. Remove the payment first if the split is really needed.";
        }
        try {
            if (app(OrderReturnService::class)->isLocked((int) $order->id)) {
                return OrderReturnService::LOCK_MESSAGE;
            }
        } catch (\Throwable $e) {
            // returns table not deployed — nothing can be locked by it
        }

        $count = OrderLineItemModel::where('order_id', $order->id)->count();
        if ($count < 2) {
            return "{$num} has only one item — there is nothing to split off.";
        }

        return null;
    }

    // =====================================================================
    // PREVIEW (dry run) — what the two invoices would look like
    // =====================================================================

    /**
     * @param  array<int>  $lineIds  may be empty: then the screen just gets the
     *                               item list + whether this order can be split.
     */
    public function preview(OrderModel $order, array $lineIds): array
    {
        $order->loadMissing('customer');
        $blocked = $this->blockedReason($order);

        $base = [
            'order_id'      => (int) $order->id,
            'order_number'  => (string) $order->order_number,
            'customer_name' => $this->customerName($order),
            'order_status'  => (string) $order->order_status,
        ];

        if ($blocked !== null) {
            return ['success' => true, 'eligible' => false, 'blocked' => $blocked] + $base;
        }

        $all = OrderLineItemModel::where('order_id', $order->id)->orderBy('id')->get();
        $selected = $this->cleanIds($lineIds);

        $out = $base + [
            'success'          => true,
            'eligible'         => true,
            'new_order_number' => $this->nextNumber($this->rootOf($order)),
            'lines'            => $all->map(fn ($l) => $this->lineRow($l, in_array((int) $l->id, $selected, true)))->values()->all(),
            'shipping_total'   => round((float) $order->shipping_total, 2),
            'discount_total'   => round((float) $order->discount_total, 2),
            'tip_amount'       => round((float) $order->tip_amount, 2),
            'current_total'    => round((float) $order->total_price, 2),
            'proof'            => $this->proofSummary($order),
            'packets_reset'    => (int) ($order->expected_packets ?: 0) > 0 ? (int) $order->expected_packets : null,
        ];

        if (empty($selected)) {
            $out['ready'] = false;
            $out['hint']  = 'Tick the items that cannot go with this invoice.';
            return $out;
        }

        $check = $this->checkSelection($order, $all, $selected);
        if (!$check['ok']) {
            $out['ready'] = false;
            $out['hint']  = $check['message'];
            return $out;
        }

        $out['ready']    = true;
        $out['original'] = $check['original'];
        $out['new']      = $check['new'];
        $out['hint']     = null;
        return $out;
    }

    // =====================================================================
    // SPLIT (the write)
    // =====================================================================

    /**
     * @param  bool|null  $splitPartPaid  required (true/false) only when the original
     *                                    has a payment proof; ignored otherwise.
     * @return array{success:bool, message:string, code?:string, original?:array, new?:array, packets_reset?:int|null}
     */
    public function split(int $orderId, array $lineIds, $user, ?bool $splitPartPaid = null): array
    {
        if (!$this->userCanSplit($user)) {
            return ['success' => false, 'code' => 'forbidden', 'message' => 'You do not have permission to split invoices.'];
        }

        $selected = $this->cleanIds($lineIds);
        if (empty($selected)) {
            return ['success' => false, 'code' => 'no_lines', 'message' => 'Tick at least one item to move.'];
        }

        $userId = $user->id ?? null;
        $result = null;

        try {
            // Find the ROOT first and lock it before anything else, so two splits of
            // the same invoice (from the web and a phone at once) queue up and can
            // never hand out the same letter. Every split of one invoice locks the
            // same root row, in the same order: root, then the order being split.
            $peek = OrderModel::find($orderId);
            if (!$peek) {
                return ['success' => false, 'code' => 'not_found', 'message' => 'Order not found.'];
            }
            $rootId = (int) ($peek->split_from_order_id ?: $peek->id);

            $result = DB::transaction(function () use ($orderId, $rootId, $selected, $userId, $splitPartPaid) {
                $root  = OrderModel::where('id', $rootId)->lockForUpdate()->first();
                $order = $rootId === $orderId ? $root : OrderModel::where('id', $orderId)->lockForUpdate()->first();
                if (!$root || !$order) {
                    return ['success' => false, 'code' => 'not_found', 'message' => 'Order not found.'];
                }

                // Everything is re-checked under the lock: a screen left open while the
                // order was dispatched, invoiced or scanned cannot split it now.
                if ($why = $this->blockedReason($order)) {
                    return ['success' => false, 'code' => 'blocked', 'message' => $why];
                }

                $all = OrderLineItemModel::where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
                $check = $this->checkSelection($order, $all, $selected);
                if (!$check['ok']) {
                    return ['success' => false, 'code' => 'bad_selection', 'message' => $check['message']];
                }

                $proof = $this->proofSummary($order);
                if ($proof !== null && $splitPartPaid === null) {
                    return [
                        'success' => false,
                        'code'    => 'needs_paid_answer',
                        'message' => "{$order->order_number} already has a payment ({$proof['label']}). Say whether the items being moved are paid by it too.",
                        'proof'   => $proof,
                    ];
                }

                $newNumber = $this->nextNumber($root);
                if ($newNumber === null) {
                    return ['success' => false, 'code' => 'too_many', 'message' => "{$root->order_number} has already been split 26 times."];
                }

                // ── 1. The new invoice. Same customer, address and payment method; none
                //       of the money, custody or scan state (none of it is true of the
                //       new invoice). Written directly — NOT through storeOrderFromApi,
                //       which would bump the customer's order count / spend and try to
                //       upsert on external_id.
                $new = new OrderModel();
                $new->forceFill([
                    'customer_id'          => $order->customer_id,
                    'external_source'      => $order->external_source,
                    'order_source_channel' => $order->order_source_channel,
                    'external_id'          => null,
                    'external_customer_id' => $order->external_customer_id,
                    'order_number'         => $newNumber,
                    'split_from_order_id'  => (int) $root->id,
                    'order_status'         => self::STATUS_FOR_NEW,
                    'order_date'           => $order->order_date ?? now(),
                    'name'                 => $order->name,
                    'currency'             => $order->currency,
                    'contact_email'        => $order->contact_email,
                    'subtotal_price'       => 0,
                    'discount_total'       => 0,
                    'shipping_total'       => 0,
                    'total_tax'            => 0,
                    'tip_amount'           => 0,
                    'total_price'          => 0,
                    'address_first_name'   => $order->address_first_name,
                    'address_last_name'    => $order->address_last_name,
                    'address_company'      => $order->address_company,
                    'address_email'        => $order->address_email,
                    'address_phone'        => $order->address_phone,
                    'address_line1'        => $order->address_line1,
                    'address_line2'        => $order->address_line2,
                    'address_city'         => $order->address_city,
                    'address_province'     => $order->address_province,
                    'address_postal_code'  => $order->address_postal_code,
                    'address_country'      => $order->address_country,
                    'payment_method'       => $order->payment_method,
                    'payment_status'       => 'unpaid',
                    'total_paid'           => 0,
                    'note'                 => $order->note,
                    'delivery_region_id'   => $order->delivery_region_id,
                    'converted'            => 0,
                    'created_by'           => $userId,
                    'updated_by'           => $userId,
                ]);
                $new->save();
                $new->createInitialStatusHistory();

                // ── 2. Move the lines (in place — ids, prep, scans, stock flag ride along).
                $moved = OrderLineItemModel::whereIn('id', $selected)
                    ->where('order_id', $order->id)
                    ->update(['order_id' => $new->id, 'updated_by' => $userId, 'updated_at' => now()]);
                if ($moved !== count($selected)) {
                    throw new \RuntimeException("Moved {$moved} of " . count($selected) . ' items — split rolled back.');
                }

                // ── 3. Totals: the existing formula, for both. Shipping/discount/tip stay
                //       on the original (owner ruling) — the new row was created with 0s.
                $this->lines->recomputeOrderTotals($order);
                $this->lines->recomputeOrderTotals($new);
                $order->refresh();
                $new->refresh();

                if ((float) $order->total_price < -0.005) {
                    throw new \RuntimeException('The original would go below zero — split rolled back.');
                }

                // ── 4. Packet count was for the whole order — it is wrong now. Clear it so
                //       the store recounts (and reprints labels) instead of a rider being
                //       blocked at the door by a count that can no longer be met.
                $packetsReset = null;
                if ((int) ($order->expected_packets ?: 0) > 0) {
                    $packetsReset = (int) $order->expected_packets;
                    $order->expected_packets = null;
                }
                $order->updated_by = $userId;
                // ⚠ The bump matters: a web edit form opened BEFORE the split carries the
                // old updated_at and gets the existing 409 instead of re-adding the moved
                // items to this order on save.
                $order->updated_at = now();
                $order->save();

                // ── 5. Already paid? Link the same proof to the new invoice.
                $proofLinked = 0;
                if ($proof !== null && $splitPartPaid === true) {
                    $proofLinked = $this->linkProofToSplit($order, $new);
                }

                return [
                    'success'       => true,
                    'message'       => "{$new->order_number} created with " . count($selected) . ' item' . (count($selected) === 1 ? '' : 's') . " moved from {$order->order_number}.",
                    'original'      => $this->orderSummary($order),
                    'new'           => $this->orderSummary($new),
                    'packets_reset' => $packetsReset,
                    'proof_linked'  => $proofLinked,
                    'split_part_paid' => $proof !== null ? (bool) $splitPartPaid : null,
                    '_order'        => $order,
                    '_new'          => $new,
                    '_lines'        => $selected,
                ];
            });
        } catch (\Throwable $e) {
            Log::error('OrderSplitService::split failed', ['order_id' => $orderId, 'lines' => $selected, 'error' => $e->getMessage()]);
            return ['success' => false, 'code' => 'error', 'message' => 'The split could not be saved: ' . $e->getMessage()];
        }

        if (empty($result['success'])) {
            return $result;
        }

        // ── After commit: side effects that must never undo the split.
        $order = $result['_order'];
        $new   = $result['_new'];
        unset($result['_order'], $result['_new']);
        $lineIds = $result['_lines'];
        unset($result['_lines']);

        $this->clearInvoiceImages([(string) $order->order_number, (string) $new->order_number]);
        try {
            (new \App\Services\Riders\ScanHelpService())->clear($order, 'invoice split');
        } catch (\Throwable $e) {
            // decoration only
        }

        $names = OrderLineItemModel::whereIn('id', $lineIds)->pluck('name')->all();
        $changes = [
            'moved_items'  => $names,
            'line_ids'     => $lineIds,
            'from'         => $order->order_number,
            'to'           => $new->order_number,
            'proof_linked' => $result['proof_linked'] ?? 0,
        ];
        AuditLogger::log('split', 'order', $order->id, (string) $order->order_number, $changes, (int) $order->id,
            'Split: ' . count($lineIds) . ' item(s) moved to ' . $new->order_number);
        AuditLogger::log('split_created', 'order', $new->id, (string) $new->order_number, $changes, (int) $new->id,
            'Split from ' . $order->order_number);

        return $result;
    }

    // =====================================================================
    // READS used by lists / invoices (one query for many orders)
    // =====================================================================

    /**
     * [orderId => ['split_from' => 'NF-19945'|null, 'split_into' => ['NF-19945-A', …]]]
     * for the given orders. Only orders that are part of a split appear.
     * Dormant-safe: [] until the SQL has run.
     *
     * @param  array<int>  $orderIds
     */
    public static function relationsFor(array $orderIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if (empty($ids) || !self::isReady()) {
            return [];
        }

        try {
            $out = [];

            // A split part (-A, -B …) → names the original it came from.
            $children = DB::table('t_crm_prod_order as c')
                ->join('t_crm_prod_order as r', 'r.id', '=', 'c.split_from_order_id')
                ->whereIn('c.id', $ids)
                ->get(['c.id', 'r.order_number as root_number']);
            foreach ($children as $c) {
                $out[(int) $c->id] = ['split_from' => (string) $c->root_number, 'split_into' => []];
            }

            // An original → names every part split off it.
            $parts = DB::table('t_crm_prod_order')
                ->whereIn('split_from_order_id', $ids)
                ->orderBy('order_number')
                ->get(['order_number', 'split_from_order_id']);
            foreach ($parts as $p) {
                $rid = (int) $p->split_from_order_id;
                $out[$rid] = $out[$rid] ?? ['split_from' => null, 'split_into' => []];
                $out[$rid]['split_into'][] = (string) $p->order_number;
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('OrderSplitService::relationsFor failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // =====================================================================
    // INTERNALS
    // =====================================================================

    /** @return array{ok:bool, message?:string, original?:array, new?:array} */
    private function checkSelection(OrderModel $order, $all, array $selected): array
    {
        $ids = $all->pluck('id')->map(fn ($v) => (int) $v)->all();
        $foreign = array_diff($selected, $ids);
        if (!empty($foreign)) {
            return ['ok' => false, 'message' => 'Some ticked items are no longer on this invoice — reload it and try again.'];
        }
        if (count($selected) >= count($ids)) {
            return ['ok' => false, 'message' => 'At least one item has to stay on the original invoice. To move everything, change the order instead.'];
        }

        $moving  = $all->filter(fn ($l) => in_array((int) $l->id, $selected, true));
        $staying = $all->reject(fn ($l) => in_array((int) $l->id, $selected, true));

        // Same arithmetic recomputeOrderTotals() will store — read off the stored
        // line_total (free lines already hold 0), nothing re-derived here.
        $stayingSub = round((float) $staying->sum('line_total'), 2);
        $movingSub  = round((float) $moving->sum('line_total'), 2);
        $discount   = (float) $order->discount_total;
        $shipping   = (float) $order->shipping_total;
        $tip        = (float) $order->tip_amount;
        $origTotal  = round($stayingSub - $discount + $shipping + $tip, 2);

        if ($stayingSub - $discount < -0.005) {
            return ['ok' => false, 'message' => 'The discount / account credit on this invoice (Rs ' . number_format($discount, 0)
                . ') is more than the items left on it (Rs ' . number_format($stayingSub, 0)
                . '). Keep more items on it, or remove the discount first.'];
        }

        return [
            'ok'       => true,
            'original' => [
                'order_number' => (string) $order->order_number,
                'items_count'  => $staying->count(),
                'subtotal'     => $stayingSub,
                'discount'     => round($discount, 2),
                'shipping'     => round($shipping, 2),
                'tip'          => round($tip, 2),
                'total'        => $origTotal,
            ],
            'new' => [
                'order_number' => $this->nextNumber($this->rootOf($order)),
                'items_count'  => $moving->count(),
                'subtotal'     => $movingSub,
                'discount'     => 0.0,
                'shipping'     => 0.0,
                'tip'          => 0.0,
                'total'        => $movingSub,
            ],
        ];
    }

    private function rootOf(OrderModel $order): OrderModel
    {
        if (!empty($order->split_from_order_id)) {
            $root = OrderModel::find($order->split_from_order_id);
            if ($root) {
                return $root;
            }
        }
        return $order;
    }

    /** Next free letter off the ROOT number: NF-19945 → NF-19945-A, -B … null after Z. */
    private function nextNumber(OrderModel $root): ?string
    {
        $base = (string) $root->order_number;
        $taken = DB::table('t_crm_prod_order')
            ->where('order_number', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $base) . '-%')
            ->pluck('order_number');

        $max = 0;
        foreach ($taken as $num) {
            if (preg_match('/^' . preg_quote($base, '/') . '-([A-Z])$/', (string) $num, $m)) {
                $max = max($max, ord($m[1]) - 64);
            }
        }
        return $max >= 26 ? null : $base . '-' . chr(65 + $max);
    }

    /**
     * The original's payment proof, as the badges show it — or null when there is
     * none. Uses the SAME status the Open Orders / Approvals badges use.
     */
    private function proofSummary(OrderModel $order): ?array
    {
        try {
            $p = app(PaymentProofStatusService::class)->forOrder((int) $order->id, false);
        } catch (\Throwable $e) {
            return null;
        }
        if (($p['status'] ?? PaymentProofStatusService::NONE) === PaymentProofStatusService::NONE) {
            return null;
        }
        return [
            'status' => $p['status'],
            'label'  => $p['label'] ?? PaymentProofStatusService::label($p['status']),
            'color'  => $p['color'] ?? null,
            'amount' => $p['proof_amount'] ?? null,
        ];
    }

    /**
     * "Yes, the moved items are paid by the same payment": every proof signal that
     * covers the original now covers BOTH invoices, through the matcher's own
     * combined-payment link table (t_fin_payment_signal_order). Each covered
     * invoice carries its share in balance_at_match, so the shares still add up
     * to what the customer sent and the Approvals proof panel reads it as one
     * payment for two invoices ("1 of 2") — never as an overpayment.
     *
     * Nothing about the signal itself changes (status, matched order, pairing).
     *
     * @return int number of signals linked
     */
    private function linkProofToSplit(OrderModel $order, OrderModel $new): int
    {
        $statuses = [PaymentSignal::STATUS_MATCHED, PaymentSignal::STATUS_AMOUNT_MISMATCH];

        $direct = PaymentSignal::where('matched_order_id', $order->id)->whereIn('status', $statuses)->pluck('id')->all();
        $viaLink = DB::table('t_fin_payment_signal_order as l')
            ->join('t_fin_payment_signal as s', 's.id', '=', 'l.signal_id')
            ->where('l.order_id', $order->id)
            ->whereIn('s.status', $statuses)
            ->pluck('l.signal_id')->all();
        $signalIds = array_values(array_unique(array_map('intval', array_merge($direct, $viaLink))));
        if (empty($signalIds)) {
            return 0;
        }

        $origBal = round((float) $order->total_price - (float) ($order->total_paid ?? 0), 2);
        $newBal  = round((float) $new->total_price, 2);
        $now = now();

        foreach ($signalIds as $sid) {
            $hasOrig = DB::table('t_fin_payment_signal_order')->where('signal_id', $sid)->where('order_id', $order->id)->exists();
            if ($hasOrig) {
                DB::table('t_fin_payment_signal_order')->where('signal_id', $sid)->where('order_id', $order->id)
                    ->update(['balance_at_match' => $origBal, 'updated_at' => $now]);
            } else {
                DB::table('t_fin_payment_signal_order')->insert([
                    'signal_id' => $sid, 'order_id' => $order->id, 'balance_at_match' => $origBal,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            DB::table('t_fin_payment_signal_order')->insert([
                'signal_id' => $sid, 'order_id' => $new->id, 'balance_at_match' => $newBal,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return count($signalIds);
    }

    private function clearInvoiceImages(array $numbers): void
    {
        foreach ($numbers as $num) {
            foreach (['png', 'jpg'] as $ext) {
                try {
                    $path = 'whatsapp-invoices/Invoice-' . $num . '.' . $ext;
                    if (Storage::disk('public')->exists($path)) {
                        Storage::disk('public')->delete($path);
                    }
                } catch (\Throwable $e) {
                    // a stale cached image is re-captured on the next send anyway
                }
            }
        }
    }

    private function lineRow(OrderLineItemModel $l, bool $selected): array
    {
        return [
            'id'          => (int) $l->id,
            'name'        => (string) $l->name,
            'sku'         => $l->sku,
            'quantity'    => (float) $l->quantity,
            'unit_price'  => round((float) $l->unit_price, 2),
            'line_total'  => round((float) $l->line_total, 2),
            'is_free'     => (bool) $l->is_free,
            'prepared'    => !empty($l->preparation_status),
            'selected'    => $selected,
        ];
    }

    private function orderSummary(OrderModel $o): array
    {
        return [
            'id'           => (int) $o->id,
            'order_number' => (string) $o->order_number,
            'order_status' => (string) $o->order_status,
            'items_count'  => OrderLineItemModel::where('order_id', $o->id)->count(),
            'subtotal'     => round((float) $o->subtotal_price, 2),
            'shipping'     => round((float) $o->shipping_total, 2),
            'discount'     => round((float) $o->discount_total, 2),
            'tip'          => round((float) $o->tip_amount, 2),
            'total'        => round((float) $o->total_price, 2),
        ];
    }

    private function customerName(OrderModel $order): string
    {
        $n = trim((string) ($order->name ?? ''));
        if ($n === '') {
            $n = trim(($order->address_first_name ?? '') . ' ' . ($order->address_last_name ?? ''));
        }
        if ($n === '' && $order->customer) {
            $n = trim(($order->customer->first_name ?? '') . ' ' . ($order->customer->last_name ?? ''));
        }
        return $n !== '' ? $n : 'Customer';
    }

    /** @return array<int> */
    private function cleanIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn ($v) => $v > 0)));
    }
}
