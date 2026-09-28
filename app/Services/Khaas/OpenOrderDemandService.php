<?php

namespace App\Services\Khaas;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 🛒 OPEN-ORDER DEMAND per Frozen product — ONE definition for the web Khaas Products
 * card, its popup and the phone's Frozen Products cards (owner, Sep-26: "the same number
 * everywhere, the same logic that's on the web").
 *
 * Moved here UNCHANGED from KhaasController (openOrderDemandQuery / stagingDemandQuery /
 * pendingOrderDemandByProduct), plus one thing the owner asked for — the split:
 *
 *   ✅ ACCEPTED  — every open status except `pending` (new, processing, priority,
 *                  on_hold, on the van …): wanted today.
 *   🗓 PENDING   — `pending`: an order ACCEPTED FOR A LATER DAY. Shopify conversion puts
 *                  "deliver tomorrow / Wednesday / Thursday" orders here and the store moves
 *                  them to `new` on the day (OrderController::convertOrder). In the evening
 *                  most Frozen demand is this kind (Sep-25 19:10: 9 of 9).
 *
 * The headline is accepted + pending. The Shopify APPROVAL QUEUE (orders not yet accepted
 * at all) is kept out of it for now (owner) and reported separately — on the web card and,
 * since Sep-27, on the phone card and popup too ("how many open in our system, how many in
 * Shopify").
 *
 * ⚠⚠ Staging and live order ids OVERLAP as unrelated orders — the two sources are summed
 *    separately and never joined on an order id.
 */
class OpenOrderDemandService
{
    /** Statuses that mean an order is DONE and no longer needs stock. Canonical Open Orders definition. */
    public const CLOSED_STATUSES = ['delivered', 'completed', 'cancelled', 'refunded'];

    /** Accepted, but for a later day. */
    public const LATER_STATUSES = ['pending'];

    /**
     * Line items on OPEN LIVE orders that have not yet consumed store stock.
     *
     * ⭐⭐ `inventory_deducted = 0` is the whole point (owner ruling): store stock is
     * deducted when an item is PREPARED (or auto-prepared on out-for-delivery), so a
     * prepared line has ALREADY come out of the Store number on the card.
     */
    public function openLinesQuery(int $buId)
    {
        return DB::table('t_crm_prod_order_line_item as li')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'li.order_id')
            ->join('t_crm_prod_product as p', 'p.id', '=', 'li.product_id')
            ->whereNotIn('o.order_status', self::CLOSED_STATUSES)
            ->whereRaw('COALESCE(li.inventory_deducted, 0) = 0')
            ->where('p.business_unit_id', $buId)
            ->where(function ($q) {
                $q->whereNull('p.attribute_1')->orWhereRaw('LOWER(p.attribute_1) <> ?', ['qurbani']);
            });
    }

    /**
     * Line items in the Shopify APPROVAL QUEUE (converted NULL/0). Staging lines carry
     * Shopify's own ids, so the link to a local product is the SKU, pre-grouped so one SKU
     * mapping to two variants cannot double the SUM.
     */
    public function stagingLinesQuery(int $buId)
    {
        return DB::table('t_crm_shopify_order_line_item as li')
            ->join('t_crm_shopify_order as so', 'so.id', '=', 'li.order_id')
            ->join(DB::raw('(SELECT sku, MIN(product_id) AS product_id
                             FROM t_crm_prod_product_variant
                             WHERE sku IS NOT NULL AND sku <> \'\'
                             GROUP BY sku) as v'), 'v.sku', '=', 'li.sku')
            ->join('t_crm_prod_product as p', 'p.id', '=', 'v.product_id')
            ->where(function ($q) {
                $q->whereNull('so.converted')->orWhere('so.converted', 0);
            })
            // converted: NULL/0 = waiting for approval · 1 = approved (now a live order,
            // counted above) · 2 = ignored/rejected. ⚠ Sep-27: a queued order that Shopify
            // itself cancelled or refunded needs no stock either.
            ->where(function ($q) {
                $q->whereNull('so.order_status')
                  ->orWhereRaw("LOWER(so.order_status) NOT IN ('cancelled', 'canceled', 'refunded')");
            })
            ->whereNotNull('li.sku')
            ->where('li.sku', '<>', '')
            ->where('p.business_unit_id', $buId)
            ->where(function ($q) {
                $q->whereNull('p.attribute_1')->orWhereRaw('LOWER(p.attribute_1) <> ?', ['qurbani']);
            });
    }

    private function bucketSql(): string
    {
        $later = implode(',', array_map(fn ($s) => DB::getPdo()->quote($s), self::LATER_STATUSES));
        return "CASE WHEN LOWER(o.order_status) IN ($later) THEN 'pending' ELSE 'accepted' END";
    }

    /**
     * @return array<int, array{accepted:int, pending:int, total:int, shopify:int}>
     *   Only products with demand appear. Fails soft to [] — demand is decoration on an
     *   inventory screen and must never blank it.
     */
    public function byProduct(int $buId, bool $withShopify = true): array
    {
        $demand = [];
        try {
            // selectRaw + get, not pluck(DB::raw(...)) — pluck cannot read a raw aggregate.
            $open = $this->openLinesQuery($buId)
                ->groupBy('p.id', DB::raw($this->bucketSql()))
                ->selectRaw('p.id as product_id, ' . $this->bucketSql() . ' as bucket, SUM(li.quantity) as qty')
                ->get();
            foreach ($open as $row) {
                $demand[(int) $row->product_id][$row->bucket] = ($demand[(int) $row->product_id][$row->bucket] ?? 0) + (float) $row->qty;
            }

            if ($withShopify) {
                $staging = $this->stagingLinesQuery($buId)
                    ->groupBy('p.id')
                    ->selectRaw('p.id as product_id, SUM(li.quantity) as qty')
                    ->get();
                foreach ($staging as $row) {
                    $demand[(int) $row->product_id]['shopify'] = (float) $row->qty;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Khaas open-order demand failed', ['error' => $e->getMessage()]);
            return [];
        }

        $out = [];
        foreach ($demand as $pid => $row) {
            // Each part rounded once; the total is the sum of the rounded parts, so the
            // numbers on the screen always add up.
            $accepted = (int) round($row['accepted'] ?? 0);
            $pending  = (int) round($row['pending'] ?? 0);
            $out[$pid] = [
                'accepted' => $accepted,
                'pending'  => $pending,
                'total'    => $accepted + $pending,
                'shopify'  => (int) round($row['shopify'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * The orders behind one product's number — exactly the rows counted, one per ORDER.
     *
     * @return array{open: array, shopify: array}
     */
    public function ordersForProduct(int $buId, int $productId, bool $withShopify = true): array
    {
        $open = $this->openLinesQuery($buId)
            ->where('p.id', $productId)
            ->groupBy('o.id', 'o.order_number', 'o.name', 'o.order_date', 'o.order_status')
            ->selectRaw('o.id as order_id, o.order_number, o.name as customer_name,
                         o.order_date, o.order_status, SUM(li.quantity) as qty')
            ->orderBy('o.order_date')
            ->get()
            ->map(fn ($r) => [
                'order_number'  => $r->order_number ?: ('#' . $r->order_id),
                'customer_name' => $r->customer_name ?: '—',
                'status'        => $r->order_status,
                'bucket'        => in_array(strtolower((string) $r->order_status), self::LATER_STATUSES, true) ? 'pending' : 'accepted',
                'date'          => $r->order_date ? date('M d', strtotime($r->order_date)) : '',
                'age_days'      => $r->order_date ? (int) floor((time() - strtotime($r->order_date)) / 86400) : 0,
                'qty'           => (int) round((float) $r->qty),
            ])->values()->all();

        $shopify = !$withShopify ? [] : $this->stagingLinesQuery($buId)
            ->where('p.id', $productId)
            ->groupBy('so.id', 'so.order_number', 'so.name', 'so.order_date')
            ->selectRaw('so.id as order_id, so.order_number, so.name as customer_name,
                         so.order_date, SUM(li.quantity) as qty')
            ->orderBy('so.order_date')
            ->get()
            ->map(fn ($r) => [
                'order_number'  => $r->order_number ?: ('#' . $r->order_id),
                'customer_name' => $r->customer_name ?: '—',
                'date'          => $r->order_date ? date('M d', strtotime($r->order_date)) : '',
                'age_days'      => $r->order_date ? (int) floor((time() - strtotime($r->order_date)) / 86400) : 0,
                'qty'           => (int) round((float) $r->qty),
            ])->values()->all();

        return ['open' => $open, 'shopify' => $shopify];
    }
}
