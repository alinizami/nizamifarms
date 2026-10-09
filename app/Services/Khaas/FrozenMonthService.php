<?php

namespace App\Services\Khaas;

use App\Models\CRM\ProductBatchModel;
use App\Models\CRM\ProductModel;
use App\Models\CRM\WarehouseInventoryLogModel;
use App\Models\CRM\WarehouseInventoryModel;
use App\Models\CRM\WarehouseTransferModel;
use App\Models\FIN\CostTypeMapModel;
use App\Models\FIN\LedgerModel;
use App\Services\HR\SalaryCostService;
use App\Services\QurbaniFinanceFilter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ONE month engine for the Frozen (Khaas) business unit.
 *
 * ⭐⭐ THE ALIGNMENT CONTRACT (Sep-2026, owner's binding requirement).
 * Qasim reads production off the mobile "Inventory Report"; the owner reads
 * the same month on "Month Review". They must never disagree — "we made 449"
 * on one screen and "no, 350" on the other is the failure mode that killed
 * trust before. So BOTH screens read THIS class:
 *
 *      WarehouseController::inventoryReport()  -> inventoryReportPayload()
 *      KhaasController::monthReview()          -> monthReview()
 *
 * There is no second implementation to drift. If you need to change what a
 * month figure MEANS, change it here once and both screens move together.
 *
 * The production definitions below are the ones the Inventory Report has
 * always used; this class did not invent any of them. The single deliberate
 * correction is documented at SALES WINDOW below.
 */
class FrozenMonthService
{
    /** Ledger rows whose balance has posted (L1 onward) — same set HQ uses. */
    private const POSTED_STATUSES = [
        LedgerModel::STATUS_APPROVED,
        LedgerModel::STATUS_PENDING_L2,
    ];

    /** Storage orders are internal transfers, never customer sales. */
    private const STORAGE_SOURCE = 'khaas_storage';

    // =================================================================
    //  WINDOW
    // =================================================================

    /** @return array{0:Carbon,1:Carbon} */
    public function window(string $month): array
    {
        $start = Carbon::createFromFormat('!Y-m', $month)->startOfMonth();
        return [$start, $start->copy()->endOfMonth()];
    }

    public static function isValidMonth(?string $month): bool
    {
        if (!$month) {
            return false;
        }
        try {
            Carbon::createFromFormat('!Y-m', $month);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // =================================================================
    //  PRODUCTION  (the half Qasim already sees)
    // =================================================================

    /**
     * Every production/stock figure for the month, per product plus totals.
     *
     * Superset of what the Inventory Report renders: it adds the batch/manual
     * split, the counts-vs-rejected split, and free quantities, which the
     * Month Review needs. Extra keys are additive — inventoryReportPayload()
     * picks only the original keys so that response stays byte-identical.
     */
    public function production(int $businessUnitId, string $month): array
    {
        [$start, $end] = $this->window($month);

        $products = ProductModel::where('business_unit_id', $businessUnitId)
            ->where('is_active', 1)
            ->orderBy('title')
            ->get(['id', 'title', 'featured_image', 'total_inventory', 'product_type', 'price_min', 'price_max']);

        if ($products->isEmpty()) {
            return ['products' => [], 'totals' => $this->emptyTotals(), 'month' => $month];
        }

        $productIds = $products->pluck('id')->toArray();

        $price       = $this->pricePerProduct($products, $productIds);
        $batch       = $this->batchProduced($businessUnitId, $productIds, $start, $end);
        $log         = $this->warehouseLog($businessUnitId, $productIds, $start, $end);
        $transferred = $this->transferredToShop($businessUnitId, $productIds, $start, $end);
        $sales       = $this->sales($productIds, $start, $end);
        $warehouse   = $this->warehouseQty($businessUnitId, $productIds);
        $daily       = $this->dailyBreakdown($businessUnitId, $productIds, $start, $end);

        $rows = [];
        $t = $this->emptyTotals();

        foreach ($products as $p) {
            $pid = (int) $p->id;

            $stockIn   = (int) ($log['stock_in'][$pid] ?? 0);
            $inBatch   = (int) ($log['stock_in_batch'][$pid] ?? 0);
            $whQty     = (int) ($warehouse[$pid] ?? 0);
            $shopQty   = (int) ($p->total_inventory ?? 0);
            $unitPrice = (float) ($price[$pid] ?? 0);

            $row = [
                'product_id'   => $pid,
                'product_name' => $p->title ?? 'Unknown',
                'product_type' => $p->product_type,
                'image'        => $p->featured_image,
                'selling_price' => $unitPrice,

                // ── production ──────────────────────────────────────
                // "Made" is stock_in: the packs that physically entered the
                // warehouse. `produced` (completed batches) is a SUBSET of it
                // — in Aug-2026 three batches were closed at 0 and 111 packs
                // were entered by hand, so batches alone understate the month.
                'produced'          => (int) ($batch[$pid] ?? 0),
                'stock_in'          => $stockIn,
                'stock_in_batch'    => $inBatch,
                'stock_in_manual'   => $stockIn - $inBatch,
                'stock_out'         => (int) ($log['stock_out'][$pid] ?? 0),

                // ⚠ The Inventory Report's single "adjustments" figure mixes
                // two unrelated things. Kept as-is for that screen, split here.
                'adjustments'          => (int) ($log['adjustments'][$pid] ?? 0),
                'adjustments_counts'   => (int) ($log['counts'][$pid] ?? 0),
                'adjustments_returned' => (int) ($log['rejected_back'][$pid] ?? 0),

                'transferred_to_shop' => (int) ($transferred[$pid] ?? 0),

                // ── sales ───────────────────────────────────────────
                'sold'         => (float) ($sales['qty'][$pid] ?? 0),
                'sold_free'    => (float) ($sales['free'][$pid] ?? 0),
                'sales_amount' => round((float) ($sales['amount'][$pid] ?? 0), 2),

                // ── stock, live (not month-end) ─────────────────────
                'current_warehouse_qty' => $whQty,
                'current_shop_qty'      => $shopQty,
                'inventory_value'       => round(($whQty + $shopQty) * $unitPrice, 2),

                // ── what the month's making was worth at shelf price ─
                'made_value' => round($stockIn * $unitPrice, 2),

                'daily_breakdown' => $daily[$pid] ?? [],
            ];

            $rows[] = $row;

            $t['produced']        += $row['produced'];
            $t['made']            += $row['stock_in'];
            $t['made_batch']      += $row['stock_in_batch'];
            $t['made_manual']     += $row['stock_in_manual'];
            $t['made_value']      += $row['made_value'];
            $t['counts']          += $row['adjustments_counts'];
            $t['returned']        += $row['adjustments_returned'];
            $t['transferred']     += $row['transferred_to_shop'];
            $t['sold']            += $row['sold'];
            $t['sold_free']       += $row['sold_free'];
            $t['sales_amount']    += $row['sales_amount'];
            $t['warehouse_stock'] += $whQty;
            $t['shop_stock']      += $shopQty;
            $t['warehouse_value'] += $whQty * $unitPrice;
            $t['shop_value']      += $shopQty * $unitPrice;
        }

        $t['planned_weight']  = round($daily['__planned_weight'] ?? 0, 2);
        $t['inventory_value'] = round($t['warehouse_value'] + $t['shop_value'], 2);
        $t['warehouse_value'] = round($t['warehouse_value'], 2);
        $t['shop_value']      = round($t['shop_value'], 2);
        $t['made_value']      = round($t['made_value'], 2);
        $t['sales_amount']    = round($t['sales_amount'], 2);

        return ['products' => $rows, 'totals' => $t, 'month' => $month];
    }

    /**
     * The Inventory Report's response, built from the same numbers.
     *
     * ⚠ Returns EXACTLY the keys that endpoint has always returned — the
     * mobile screen is in the field and an old APK must keep working.
     */
    public function inventoryReportPayload(int $businessUnitId, string $month): array
    {
        $data = $this->production($businessUnitId, $month);

        $products = array_map(function (array $r) {
            return [
                'product_id'   => $r['product_id'],
                'product_name' => $r['product_name'],
                'product_type' => $r['product_type'],
                'image'        => $r['image'],
                'selling_price' => $r['selling_price'],
                'produced'     => $r['produced'],
                'stock_in'     => $r['stock_in'],
                'stock_out'    => $r['stock_out'],
                'adjustments'  => $r['adjustments'],
                'transferred_to_shop' => $r['transferred_to_shop'],
                'sold'         => $r['sold'],
                'sales_amount' => $r['sales_amount'],
                'current_warehouse_qty' => $r['current_warehouse_qty'],
                'current_shop_qty'      => $r['current_shop_qty'],
                'inventory_value'       => $r['inventory_value'],
                'daily_breakdown'       => $r['daily_breakdown'],
            ];
        }, $data['products']);

        $t = $data['totals'];

        return [
            'products' => $products,
            'totals' => [
                'produced'        => $t['produced'],
                'planned_weight'  => $t['planned_weight'],
                'transferred'     => $t['transferred'],
                'sold'            => $t['sold'],
                'sales_amount'    => $t['sales_amount'],
                'warehouse_stock' => $t['warehouse_stock'],
                'shop_stock'      => $t['shop_stock'],
                'warehouse_value' => $t['warehouse_value'],
                'shop_value'      => $t['shop_value'],
                'inventory_value' => $t['inventory_value'],
            ],
        ];
    }

    private function emptyTotals(): array
    {
        return [
            'produced' => 0, 'made' => 0, 'made_batch' => 0, 'made_manual' => 0,
            'made_value' => 0.0, 'counts' => 0, 'returned' => 0,
            'planned_weight' => 0.0, 'transferred' => 0,
            'sold' => 0.0, 'sold_free' => 0.0, 'sales_amount' => 0.0,
            'warehouse_stock' => 0, 'shop_stock' => 0,
            'warehouse_value' => 0.0, 'shop_value' => 0.0, 'inventory_value' => 0.0,
        ];
    }

    // ── production sub-queries (each guarded: a failure degrades to 0) ──

    /**
     * The shelf price per product — the SAME rule Month Review values packs at, so the
     * recipe card's "% of the price" and the page's shelf value speak of one price.
     *
     * @return array<int,float>
     */
    public function sellingPrices(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }
        $products = ProductModel::whereIn('id', $productIds)->get(['id', 'price_min']);
        return $this->pricePerProduct($products, $productIds);
    }

    private function pricePerProduct($products, array $productIds): array
    {
        $out = [];
        try {
            $rows = DB::table('t_crm_prod_product_variant')
                ->whereIn('product_id', $productIds)
                ->where('price', '>', 0)
                ->select('product_id', DB::raw('MIN(price) as variant_price'))
                ->groupBy('product_id')
                ->get();
            foreach ($rows as $r) {
                if ($r->variant_price > 0) {
                    $out[(int) $r->product_id] = round((float) $r->variant_price, 2);
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('Frozen month: variant prices failed', ['error' => $e->getMessage()]);
        }

        foreach ($products as $p) {
            $pid = (int) $p->id;
            if (!isset($out[$pid]) && $p->price_min > 0) {
                $out[$pid] = round((float) $p->price_min, 2);
            }
        }
        return $out;
    }

    private function batchProduced(int $bu, array $productIds, Carbon $start, Carbon $end): array
    {
        $out = [];
        try {
            $rows = ProductBatchModel::where('business_unit_id', $bu)
                ->where('status', ProductBatchModel::STATUS_COMPLETED)
                ->whereNotNull('ended_at')
                ->whereBetween('ended_at', [$start, $end])
                ->whereIn('product_id', $productIds)
                ->select('product_id', DB::raw('SUM(quantity_produced) as total_produced'))
                ->groupBy('product_id')
                ->get();
            foreach ($rows as $r) {
                $out[(int) $r->product_id] = (int) ($r->total_produced ?? 0);
            }
        } catch (\Throwable $e) {
            \Log::warning('Frozen month: batch production failed', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    /**
     * Warehouse ledger movements for the month.
     *
     * ⚠ change_type is an ENUM (stock_in, stock_out, adjustment, transfer,
     * count) — never introduce a value. `transfer` rows are deliberately
     * skipped here: transfers are counted from the transfer table by
     * approval date, and counting both would double them.
     *
     * ⚠ A REJECTED transfer returns stock as change_type='adjustment' with
     * reference_type='transfer_rejected'. It is stock coming back, not a
     * correction, so it is tracked separately as well as inside the combined
     * `adjustments` figure the Inventory Report has always shown.
     */
    private function warehouseLog(int $bu, array $productIds, Carbon $start, Carbon $end): array
    {
        $out = ['stock_in' => [], 'stock_in_batch' => [], 'stock_out' => [],
                'adjustments' => [], 'counts' => [], 'rejected_back' => []];
        try {
            $rows = WarehouseInventoryLogModel::where('business_unit_id', $bu)
                ->whereIn('product_id', $productIds)
                ->whereBetween('created_at', [$start, $end])
                ->select('product_id', 'change_type', 'reference_type',
                    DB::raw('SUM(quantity_change) as total_change'))
                ->groupBy('product_id', 'change_type', 'reference_type')
                ->get();

            foreach ($rows as $r) {
                $pid = (int) $r->product_id;
                $val = (int) ($r->total_change ?? 0);
                $ref = $r->reference_type;

                switch ($r->change_type) {
                    case 'stock_in':
                        $out['stock_in'][$pid] = ($out['stock_in'][$pid] ?? 0) + $val;
                        if ($ref === 'batch') {
                            $out['stock_in_batch'][$pid] = ($out['stock_in_batch'][$pid] ?? 0) + $val;
                        }
                        break;
                    case 'stock_out':
                        $out['stock_out'][$pid] = ($out['stock_out'][$pid] ?? 0) + abs($val);
                        break;
                    case 'adjustment':
                    case 'count':
                        $out['adjustments'][$pid] = ($out['adjustments'][$pid] ?? 0) + $val;
                        if ($ref === 'transfer_rejected') {
                            $out['rejected_back'][$pid] = ($out['rejected_back'][$pid] ?? 0) + $val;
                        } else {
                            $out['counts'][$pid] = ($out['counts'][$pid] ?? 0) + $val;
                        }
                        break;
                    case 'transfer':
                        break;
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('Frozen month: warehouse logs failed', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    private function transferredToShop(int $bu, array $productIds, Carbon $start, Carbon $end): array
    {
        $out = [];
        try {
            $rows = WarehouseTransferModel::where('business_unit_id', $bu)
                ->where('status', WarehouseTransferModel::STATUS_APPROVED)
                ->where('to_location', 'store')
                ->whereIn('product_id', $productIds)
                ->whereNotNull('approved_at')
                ->whereBetween('approved_at', [$start, $end])
                ->select('product_id', DB::raw('SUM(quantity) as total_transferred'))
                ->groupBy('product_id')
                ->get();
            foreach ($rows as $r) {
                $out[(int) $r->product_id] = (int) ($r->total_transferred ?? 0);
            }
        } catch (\Throwable $e) {
            \Log::warning('Frozen month: transfers failed', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    /**
     * Customer sales of this unit's products in the month.
     *
     * ⚠ SALES WINDOW (Sep-2026 correction, applies to BOTH screens):
     * this used to compare a DATETIME column against bare date strings, so
     * '...' BETWEEN '2026-08-01' AND '2026-08-31' resolved the upper bound to
     * midnight and silently dropped every order placed DURING the last day of
     * the month. Now bounded by the real datetimes. Because the Inventory
     * Report reads this same method, both screens moved together — they are
     * still identical to each other, and now both include the last day.
     *
     * ⚠ Storage orders (KS-xx) are the warehouse buying its own raw meat.
     * They are excluded or the unit would appear to sell to itself.
     */
    private function sales(array $productIds, Carbon $start, Carbon $end): array
    {
        $out = ['qty' => [], 'free' => [], 'amount' => []];
        try {
            $rows = DB::table('t_crm_prod_order_line_item as li')
                ->join('t_crm_prod_order as o', 'o.id', '=', 'li.order_id')
                ->whereIn('li.product_id', $productIds)
                ->whereNotIn('o.order_status', ['cancelled'])
                ->where(function ($q) {
                    $q->whereNull('o.external_source')
                      ->orWhere('o.external_source', '!=', self::STORAGE_SOURCE);
                })
                ->whereBetween('o.order_date', [$start, $end])
                ->select('li.product_id',
                    DB::raw('SUM(li.quantity) as total_qty'),
                    DB::raw('SUM(CASE WHEN COALESCE(li.is_free, 0) = 1 THEN li.quantity ELSE 0 END) as free_qty'),
                    DB::raw('SUM(li.line_total) as total_amount'))
                ->groupBy('li.product_id')
                ->get();

            foreach ($rows as $r) {
                $pid = (int) $r->product_id;
                $out['qty'][$pid]    = (float) ($r->total_qty ?? 0);
                $out['free'][$pid]   = (float) ($r->free_qty ?? 0);
                $out['amount'][$pid] = round((float) ($r->total_amount ?? 0), 2);
            }
        } catch (\Throwable $e) {
            \Log::warning('Frozen month: sales failed', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    private function warehouseQty(int $bu, array $productIds): array
    {
        $out = [];
        try {
            $rows = WarehouseInventoryModel::where('business_unit_id', $bu)
                ->where('is_active', true)
                ->whereIn('product_id', $productIds)
                ->select('product_id', DB::raw('SUM(quantity) as total_qty'))
                ->groupBy('product_id')
                ->get();
            foreach ($rows as $r) {
                $out[(int) $r->product_id] = (int) ($r->total_qty ?? 0);
            }
        } catch (\Throwable $e) {
            \Log::warning('Frozen month: warehouse qty failed', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    /** Per-product day rows: planned kg from demands + packs into the warehouse. */
    private function dailyBreakdown(int $bu, array $productIds, Carbon $start, Carbon $end): array
    {
        $byProduct = [];
        $plannedWeight = 0.0;

        try {
            $demands = DB::table('t_crm_khaas_production_demand_item as di')
                ->join('t_crm_khaas_production_demand as d', 'd.id', '=', 'di.demand_id')
                ->where('d.business_unit_id', $bu)
                ->whereIn('di.khaas_product_id', $productIds)
                ->whereBetween('d.demand_date', [$start->toDateString(), $end->toDateString()])
                ->whereNotIn('d.status', ['cancelled'])
                ->select('di.khaas_product_id as product_id', 'd.demand_date',
                    DB::raw('SUM(di.quantity_kg) as plan_qty'))
                ->groupBy('di.khaas_product_id', 'd.demand_date')
                ->get();

            foreach ($demands as $r) {
                $pid = (int) $r->product_id;
                $date = $r->demand_date;
                $byProduct[$pid][$date] = $byProduct[$pid][$date] ?? ['plan' => 0, 'warehouse_in' => 0];
                $byProduct[$pid][$date]['plan'] += round((float) $r->plan_qty, 3);
                $plannedWeight += (float) $r->plan_qty;
            }

            $stockIn = WarehouseInventoryLogModel::where('business_unit_id', $bu)
                ->whereIn('product_id', $productIds)
                ->where('change_type', 'stock_in')
                ->whereBetween('created_at', [$start, $end])
                ->select('product_id', DB::raw('DATE(created_at) as log_date'),
                    DB::raw('SUM(quantity_change) as total_in'))
                ->groupBy('product_id', DB::raw('DATE(created_at)'))
                ->get();

            foreach ($stockIn as $r) {
                $pid = (int) $r->product_id;
                $date = $r->log_date;
                $byProduct[$pid][$date] = $byProduct[$pid][$date] ?? ['plan' => 0, 'warehouse_in' => 0];
                $byProduct[$pid][$date]['warehouse_in'] += (int) $r->total_in;
            }
        } catch (\Throwable $e) {
            \Log::warning('Frozen month: daily breakdown failed', ['error' => $e->getMessage()]);
        }

        $out = ['__planned_weight' => round($plannedWeight, 2)];
        foreach ($byProduct as $pid => $dates) {
            $list = [];
            foreach ($dates as $date => $v) {
                $list[] = ['date' => $date, 'plan' => $v['plan'], 'warehouse_in' => $v['warehouse_in']];
            }
            usort($list, fn ($a, $b) => strcmp($a['date'], $b['date']));
            $out[$pid] = $list;
        }
        return $out;
    }

    // =================================================================
    //  COST  (product / fixed / one-time / unclassified)
    // =================================================================

    /**
     * Every rupee the unit spent in the month, grouped into cost types.
     *
     * Sources are exactly HQ's, so the four buckets always sum to what the
     * HQ Executive screen shows for Frozen: posted ledger rows stamped with
     * this business unit (vendor purchases, expenses, asset purchases) plus
     * SalaryCostService. Nothing is invented and nothing is dropped —
     * anything the map does not know lands in 'unclassified', visibly.
     */
    public function costs(int $businessUnitId, string $month): array
    {
        [$start, $end] = $this->window($month);
        $map = CostTypeMapModel::mapFor($businessUnitId);

        $buckets = [
            CostTypeMapModel::TYPE_PRODUCT      => ['total' => 0.0, 'rows' => []],
            CostTypeMapModel::TYPE_FIXED        => ['total' => 0.0, 'rows' => []],
            CostTypeMapModel::TYPE_ONE_TIME     => ['total' => 0.0, 'rows' => []],
            CostTypeMapModel::TYPE_UNCLASSIFIED => ['total' => 0.0, 'rows' => []],
        ];

        $add = function (string $type, array $row) use (&$buckets) {
            $buckets[$type]['rows'][] = $row;
            $buckets[$type]['total'] += $row['amount'];
        };

        foreach ($this->vendorPurchases($businessUnitId, $start, $end) as $v) {
            $type = CostTypeMapModel::resolve($map, CostTypeMapModel::KIND_VENDOR, $v['vendor_id']);
            $add($type, [
                'label'       => $v['vendor'],
                'amount'      => $v['amount'],
                'bills'       => $v['bills'],
                'source_kind' => CostTypeMapModel::KIND_VENDOR,
                'source_key'  => (string) $v['vendor_id'],
                'cost_type'   => $type,
                'editable'    => $v['vendor_id'] !== null,
                'is_meat'     => $v['is_meat'],
            ]);
        }

        foreach ($this->expenses($businessUnitId, $start, $end) as $e) {
            $type = CostTypeMapModel::resolve($map, CostTypeMapModel::KIND_EXPENSE_CATEGORY, $e['category']);
            $add($type, [
                'label'       => $e['category'],
                'amount'      => $e['amount'],
                'bills'       => $e['bills'],
                'source_kind' => CostTypeMapModel::KIND_EXPENSE_CATEGORY,
                'source_key'  => $e['category'],
                'cost_type'   => $type,
                'editable'    => true,
                'is_meat'     => false,
            ]);
        }

        $assets = $this->assetPurchases($businessUnitId, $start, $end);
        if ($assets['amount'] > 0) {
            $type = CostTypeMapModel::resolve($map, CostTypeMapModel::KIND_ASSET_PURCHASE, CostTypeMapModel::KEY_ALL);
            $add($type, [
                'label'       => 'Equipment and assets',
                'amount'      => $assets['amount'],
                'bills'       => $assets['count'],
                'source_kind' => CostTypeMapModel::KIND_ASSET_PURCHASE,
                'source_key'  => CostTypeMapModel::KEY_ALL,
                'cost_type'   => $type,
                'editable'    => true,
                'is_meat'     => false,
                'detail'      => $assets['names'],
            ]);
        }

        $salary = $this->salaries($businessUnitId, $start, $end);
        if ($salary['amount'] != 0.0) {
            $type = CostTypeMapModel::resolve($map, CostTypeMapModel::KIND_SALARY, CostTypeMapModel::KEY_ALL);
            $add($type, [
                'label'       => 'Salaries',
                'amount'      => $salary['amount'],
                'bills'       => $salary['count'],
                'source_kind' => CostTypeMapModel::KIND_SALARY,
                'source_key'  => CostTypeMapModel::KEY_ALL,
                'cost_type'   => $type,
                'editable'    => true,
                'is_meat'     => false,
                'detail'      => $salary['names'],
            ]);
        }

        foreach ($buckets as $k => $b) {
            usort($buckets[$k]['rows'], fn ($a, $c) => $c['amount'] <=> $a['amount']);
            $buckets[$k]['total'] = round($b['total'], 2);
        }

        $grand = 0.0;
        foreach ($buckets as $b) {
            $grand += $b['total'];
        }

        return [
            'buckets'      => $buckets,
            'grand_total'  => round($grand, 2),
            'map_available' => CostTypeMapModel::available(),
        ];
    }

    /**
     * ⚠ Qualify every column: joining the ledger to accounts/vendors puts
     * three `business_unit_id` columns in scope and an unqualified one has
     * 500'd these drills before.
     */
    private function vendorPurchases(int $bu, Carbon $start, Carbon $end): array
    {
        $meatVendorId = $this->storageVendorId($bu);

        $q = DB::table('t_fin_ledger as l')
            ->leftJoin('t_fin_accounts as a', 'a.id', '=', 'l.to_account_id')
            ->leftJoin('t_fin_vendors as v', 'v.account_id', '=', 'a.id')
            ->where('l.business_unit_id', $bu)
            ->where('l.transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE)
            ->whereIn('l.approval_status', self::POSTED_STATUSES)
            ->whereBetween('l.transaction_date', [$start->toDateString(), $end->toDateString()]);

        QurbaniFinanceFilter::applyToLedgerQuery($q, 'l', QurbaniFinanceFilter::MODE_EXCLUDE);

        $rows = $q->select(
            DB::raw('COALESCE(v.id, 0) as vendor_id'),
            DB::raw('COALESCE(v.vendor_name, a.account_name, "Unknown vendor") as vendor'),
            DB::raw('COUNT(*) as bills'),
            DB::raw('SUM(l.amount) as amount')
        )->groupBy('vendor_id', 'vendor')->get();

        $out = [];
        foreach ($rows as $r) {
            $vid = (int) $r->vendor_id;
            $out[] = [
                'vendor_id' => $vid ?: null,
                'vendor'    => $r->vendor,
                'bills'     => (int) $r->bills,
                'amount'    => round((float) $r->amount, 2),
                'is_meat'   => $meatVendorId && $vid === $meatVendorId,
            ];
        }
        return $out;
    }

    /** Same category expression HQ and the Finance expense screen group by. */
    private function expenses(int $bu, Carbon $start, Carbon $end): array
    {
        $expr = 'COALESCE(NULLIF(TRIM(r.expense_category), ""), rc.category_name, "Uncategorised")';

        $q = DB::table('t_fin_ledger as l')
            ->leftJoin('t_req_master as r', 'r.id', '=', 'l.request_id')
            ->leftJoin('t_req_category as rc', 'rc.id', '=', 'r.category_id')
            ->where('l.business_unit_id', $bu)
            ->where('l.transaction_type', LedgerModel::TYPE_EXPENSE)
            ->whereIn('l.approval_status', self::POSTED_STATUSES)
            ->whereBetween('l.transaction_date', [$start->toDateString(), $end->toDateString()]);

        QurbaniFinanceFilter::applyToLedgerQuery($q, 'l', QurbaniFinanceFilter::MODE_EXCLUDE);

        // ⚠ GROUP BY the SELECT ALIAS, never the repeated expression —
        // MariaDB ONLY_FULL_GROUP_BY throws 1055 on a repeated COALESCE.
        $rows = $q->select(
            DB::raw($expr . ' as category'),
            DB::raw('COUNT(*) as bills'),
            DB::raw('SUM(l.amount) as amount')
        )->groupBy('category')->get();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'category' => $r->category,
                'bills'    => (int) $r->bills,
                'amount'   => round((float) $r->amount, 2),
            ];
        }
        return $out;
    }

    private function assetPurchases(int $bu, Carbon $start, Carbon $end): array
    {
        try {
            $rows = DB::table('t_fin_ledger as l')
                ->where('l.business_unit_id', $bu)
                ->where('l.transaction_type', 'asset_purchase')
                ->whereIn('l.approval_status', self::POSTED_STATUSES)
                ->whereBetween('l.transaction_date', [$start->toDateString(), $end->toDateString()])
                ->select('l.amount', 'l.description')
                ->get();

            $names = [];
            $amount = 0.0;
            foreach ($rows as $r) {
                $amount += (float) $r->amount;
                $names[] = trim((string) $r->description) ?: 'Asset purchase';
            }
            return ['amount' => round($amount, 2), 'count' => count($rows), 'names' => $names];
        } catch (\Throwable $e) {
            \Log::warning('Frozen month: asset purchases failed', ['error' => $e->getMessage()]);
            return ['amount' => 0.0, 'count' => 0, 'names' => []];
        }
    }

    /**
     * Salaries through the SAME engine HQ, Reports and Expenses use, so the
     * four screens can never disagree about a month's wage bill. It is
     * ACCRUAL — keyed to the month WORKED, not the day Pay was pressed — and
     * counts advances already paid out but not yet recovered.
     */
    private function salaries(int $bu, Carbon $start, Carbon $end): array
    {
        try {
            $svc = new SalaryCostService();
            $names = [];
            foreach ($svc->detailForWindow($start, $end, $bu) as $row) {
                if (!empty($row['is_khaas'])) {
                    $names[] = $row['employee'] . ($row['note'] ? ' · ' . $row['note'] : '');
                }
            }
            return [
                'amount' => round((float) ($svc->costForWindow($start, $end, $bu)['kh'] ?? 0), 2),
                'count'  => count($names),
                'names'  => $names,
            ];
        } catch (\Throwable $e) {
            \Log::warning('Frozen month: salaries failed', ['error' => $e->getMessage()]);
            return ['amount' => 0.0, 'count' => 0, 'names' => []];
        }
    }

    /** Vendor the Meat Order receipt auto-bills; config first, name second. */
    private function storageVendorId(int $bu): ?int
    {
        try {
            $configured = DB::table('t_fin_config')
                ->where('config_key', 'KHAAS_STORAGE_VENDOR_ID')
                ->where('business_unit_id', $bu)
                ->value('config_value');
            if ($configured) {
                return (int) $configured;
            }
            $id = DB::table('t_fin_vendors')->where('vendor_name', 'Nizami Farms')->value('id');
            return $id ? (int) $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // =================================================================
    //  MEAT — bought vs used
    // =================================================================

    /**
     * Raw meat bought into storage vs actually consumed by production.
     *
     * Buying 64 kg and using 51 kg in the same month is normal; the balance
     * is stock, not cost. The page can therefore value product cost either
     * way. "As bought" is the default because it ties to the HQ vendor drill
     * to the rupee; "as used" answers what the month's packs really absorbed.
     */
    public function meat(int $businessUnitId, string $month): array
    {
        [$start, $end] = $this->window($month);

        $rate = $this->meatRates($businessUnitId, $start, $end);

        $rows = [];
        $boughtKg = 0.0; $usedKg = 0.0; $usedValue = 0.0; $onHand = 0.0;

        try {
            $moves = DB::table('t_crm_khaas_storage_log as sl')
                ->join('t_crm_prod_product as p', 'p.id', '=', 'sl.source_product_id')
                ->where('sl.khaas_business_unit_id', $businessUnitId)
                ->whereBetween('sl.created_at', [$start, $end])
                ->select('sl.source_product_id', 'p.title',
                    DB::raw('SUM(CASE WHEN sl.change_type = "received" THEN sl.quantity_change ELSE 0 END) as received'),
                    DB::raw('SUM(CASE WHEN sl.change_type = "used" THEN -sl.quantity_change ELSE 0 END) as used'),
                    DB::raw('SUM(CASE WHEN sl.change_type IN ("adjustment","wastage") THEN sl.quantity_change ELSE 0 END) as adjusted'))
                ->groupBy('sl.source_product_id', 'p.title')
                ->get();

            foreach ($moves as $m) {
                $pid = (int) $m->source_product_id;
                $perKg = (float) ($rate[$pid] ?? 0);
                $used  = round((float) $m->used, 3);
                $recv  = round((float) $m->received, 3);

                $rows[] = [
                    'product_id'  => $pid,
                    'name'        => $m->title,
                    'received_kg' => $recv,
                    'used_kg'     => $used,
                    'adjusted_kg' => round((float) $m->adjusted, 3),
                    'rate_per_kg' => round($perKg, 2),
                    'used_value'  => round($used * $perKg, 2),
                ];

                $boughtKg  += $recv;
                $usedKg    += $used;
                $usedValue += $used * $perKg;
            }

            usort($rows, fn ($a, $b) => $b['used_value'] <=> $a['used_value']);

            $onHand = (float) DB::table('t_crm_khaas_storage_inventory')
                ->where('khaas_business_unit_id', $businessUnitId)
                ->sum('quantity_on_hand');
        } catch (\Throwable $e) {
            \Log::warning('Frozen month: meat failed', ['error' => $e->getMessage()]);
        }

        return [
            'rows'          => $rows,
            'bought_kg'     => round($boughtKg, 3),
            'used_kg'       => round($usedKg, 3),
            'used_value'    => round($usedValue, 2),
            'on_hand_kg'    => round($onHand, 3),
        ];
    }

    /**
     * Rs/kg per raw material: this month's Meat Order lines first, then the
     * most recent earlier price, so a meat used but not re-bought this month
     * is still valued instead of counting as free.
     */
    private function meatRates(int $bu, Carbon $start, Carbon $end): array
    {
        $out = [];
        try {
            $inMonth = DB::table('t_crm_prod_order as o')
                ->join('t_crm_prod_order_line_item as li', 'li.order_id', '=', 'o.id')
                ->where('o.external_source', self::STORAGE_SOURCE)
                ->whereNotIn('o.order_status', ['cancelled'])
                ->whereBetween('o.order_date', [$start, $end])
                ->select('li.product_id',
                    DB::raw('SUM(li.line_total) as rs'),
                    DB::raw('SUM(li.quantity) as kg'))
                ->groupBy('li.product_id')
                ->get();
            foreach ($inMonth as $r) {
                if ((float) $r->kg > 0) {
                    $out[(int) $r->product_id] = (float) $r->rs / (float) $r->kg;
                }
            }

            $earlier = DB::table('t_crm_prod_order as o')
                ->join('t_crm_prod_order_line_item as li', 'li.order_id', '=', 'o.id')
                ->where('o.external_source', self::STORAGE_SOURCE)
                ->whereNotIn('o.order_status', ['cancelled'])
                ->where('o.order_date', '<', $start)
                ->where('li.quantity', '>', 0)
                ->orderBy('o.order_date', 'desc')
                ->select('li.product_id', 'li.line_total', 'li.quantity')
                ->get();
            foreach ($earlier as $r) {
                $pid = (int) $r->product_id;
                if (!isset($out[$pid]) && (float) $r->quantity > 0) {
                    $out[$pid] = (float) $r->line_total / (float) $r->quantity;
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('Frozen month: meat rates failed', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    // =================================================================
    //  THE PAGE
    // =================================================================

    /**
     * Everything the Month Review renders.
     *
     * @param string $basis 'bought' (default, ties to HQ) or 'used'
     */
    public function monthReview(int $businessUnitId, string $month, string $basis = 'bought'): array
    {
        $basis = $basis === 'used' ? 'used' : 'bought';

        $production = $this->production($businessUnitId, $month);
        $costs      = $this->costs($businessUnitId, $month);
        $meat       = $this->meat($businessUnitId, $month);

        // "As used" swaps the meat vendor's row for what production consumed.
        // Only the meat line moves; every other row is untouched, and the
        // swap is disclosed on screen so the total is never silently different.
        $meatBought = 0.0;
        foreach ($costs['buckets'] as $b) {
            foreach ($b['rows'] as $r) {
                if (!empty($r['is_meat'])) {
                    $meatBought += $r['amount'];
                }
            }
        }
        $meatAdjustment = $basis === 'used' ? round($meat['used_value'] - $meatBought, 2) : 0.0;

        if ($basis === 'used') {
            foreach ($costs['buckets'] as $type => $bucket) {
                foreach ($bucket['rows'] as $i => $r) {
                    if (!empty($r['is_meat'])) {
                        $costs['buckets'][$type]['rows'][$i]['amount'] = $meat['used_value'];
                        $costs['buckets'][$type]['rows'][$i]['label'] .= ' (meat used)';
                        $costs['buckets'][$type]['total'] = round($bucket['total'] + $meatAdjustment, 2);
                    }
                }
            }
            $costs['grand_total'] = round($costs['grand_total'] + $meatAdjustment, 2);
        }

        $made      = (int) $production['totals']['made'];
        $madeValue = (float) $production['totals']['made_value'];

        $product  = $costs['buckets'][CostTypeMapModel::TYPE_PRODUCT]['total'];
        $fixed    = $costs['buckets'][CostTypeMapModel::TYPE_FIXED]['total'];
        $oneTime  = $costs['buckets'][CostTypeMapModel::TYPE_ONE_TIME]['total'];
        $unknown  = $costs['buckets'][CostTypeMapModel::TYPE_UNCLASSIFIED]['total'];

        $avgPrice   = $made > 0 ? $madeValue / $made : 0.0;
        $perPackVar = $made > 0 ? $product / $made : 0.0;
        $margin     = $avgPrice - $perPackVar;

        // ── the recipe half ────────────────────────────────────────────────
        // Estimate only, and labelled as one everywhere it is shown. It sits BESIDE the
        // money above and never changes it: `product`, `fixed`, `total_spend` and every
        // figure HQ reconciles against are untouched by anything below this line.
        $costing     = new FrozenCostingService();
        $ingredients = $costing->ingredientMonth($businessUnitId, $month);
        $productCost = $costing->productMonth($businessUnitId, $month);

        $madeBatch  = (int) $production['totals']['made_batch'];
        $madeManual = (int) $production['totals']['made_manual'];

        $recipeVsPurchases = $this->recipeVsPurchases(
            // Always the purchases AS BOUGHT: "as used" swaps the meat row for what
            // production took, which would compare the recipe with itself.
            $production, round($product - $meatAdjustment, 2), $meatBought,
            $ingredients, $productCost, $madeBatch, $madeManual, $businessUnitId, $month
        );

        return [
            'recipe_vs_purchases' => $recipeVsPurchases,
            'month'        => $month,
            'basis'        => $basis,
            'production'   => $production,
            'costs'        => $costs,
            'meat'         => $meat,
            'ingredients'  => $ingredients,
            'product_costs' => $productCost,
            'made_split'   => $this->madeSplit($made, $madeBatch, $madeManual, $productCost),
            'headline'   => [
                'made'            => $made,
                'made_batch'      => $madeBatch,
                'made_manual'     => $madeManual,
                'ingredient_cost'     => (float) $productCost['totals']['cost'],
                'ingredient_per_pack' => (float) $productCost['totals']['cost_per_pack'],
                'made_value'      => round($madeValue, 2),
                'avg_price'       => round($avgPrice, 2),
                'product'         => $product,
                'fixed'           => $fixed,
                'one_time'        => $oneTime,
                'unclassified'    => $unknown,
                'total_spend'     => $costs['grand_total'],
                'product_per_pack' => round($perPackVar, 2),
                'fixed_per_pack'   => $made > 0 ? round($fixed / $made, 2) : 0.0,
                // One-time is deliberately OUT of the per-pack maths: building
                // a kitchen is not a cost of the packs made that month.
                'all_in_per_pack'  => $made > 0 ? round(($product + $fixed) / $made, 2) : 0.0,
                // ⭐ Oct-7 (owner): still OUT of all_in_per_pack, but SHOWN as a divider —
                //   "if this month's one-time spend were spread over this month's packs, it
                //   would be Rs X a pack" — so the maths is visible, not hidden.
                'one_time_per_pack' => $made > 0 ? round($oneTime / $made, 2) : 0.0,
                'all_in_with_one_time_per_pack' => $made > 0 ? round(($product + $fixed + $oneTime) / $made, 2) : 0.0,
                'margin_per_pack'  => round($margin, 2),
                // Packs needed to cover the fixed base at this month's prices
                // and product cost. Meaningless when a pack loses money before
                // fixed cost, so the page hides it and explains instead.
                'breakeven_packs'  => $margin > 0 ? (int) ceil($fixed / $margin) : null,
                'meat_adjustment'  => $meatAdjustment,
            ],
        ];
    }

    /**
     * 💲 Recipe vs purchases — "N packs were made: what should they have cost, and what
     * did we buy?" Owner's ask, 27-Sep. The gap analysis, in three honest parts:
     *
     *   1. What CAN be compared — ingredients bought on itemised bills against what the
     *      recipes say the month's packs used (same month-average prices as the
     *      Ingredients panel, so the two panels never disagree), and meat: what the
     *      recipes say against what storage says left the freezer.
     *   2. Purchase money that CANNOT be compared — bills recorded as one total, with no
     *      product lines, so nothing ties them to an ingredient.
     *   3. Packs that CANNOT be costed — made with no recipe behind them.
     *
     * ⭐ Owner ruling 27-Sep: history is NOT restated. Packs made before a recipe existed,
     *   and bills recorded as a total, stay outside; the block fills in as itemised bills
     *   and recipes accumulate, and says plainly how much sits outside meanwhile.
     *
     * ⚠ A difference is not a loss. Bought-but-not-used is usually still on the shelf;
     *   the block says so every time, rather than inviting the wrong conclusion.
     */
    private function recipeVsPurchases(
        array $production,
        float $productBought,
        float $meatBought,
        array $ingredients,
        array $productCost,
        int $madeBatch,
        int $madeManual,
        int $businessUnitId,
        string $month
    ): array {
        $made     = (int) $production['totals']['made'];
        $costed   = (int) ($productCost['totals']['made'] ?? 0);
        $uncosted = max(0, $made - $costed);

        $boughtIng = 0.0;   // itemised, non-meat, this month
        $usedIng   = 0.0;   // recipe standard of the same ingredients, at the month's rates
        $rows      = [];
        $meatRows  = [];

        foreach ($ingredients['rows'] ?? [] as $r) {
            if (empty($r['has_movement']) && empty($r['standard_qty'])) {
                continue;
            }
            $rate = (float) ($r['rate_per_base'] ?? 0);

            if (!empty($r['is_meat'])) {
                // Recipe standard vs the storage ledger — both in grams here.
                $recipeQty  = (float) $r['standard_qty'];
                $storageQty = (float) $r['used_qty'];
                if ($recipeQty <= 0 && $storageQty <= 0) {
                    continue;
                }
                $diff = $storageQty - $recipeQty;
                $meatRows[] = [
                    'name'         => $r['name'],
                    'recipe_text'  => $r['standard_text'],
                    'storage_text' => $r['used_text'],
                    'diff_qty'     => round($diff, 3),
                    'diff_text'    => $this->kgText($diff),
                    'diff_value'   => round($diff * $rate, 2),
                    'direction'    => $recipeQty <= 0 ? 'no_recipe'
                        : ($diff > 0.5 ? 'more_used' : ($diff < -0.5 ? 'less_used' : 'matches')),
                ];
                continue;
            }

            $bought = (float) $r['bought_qty'];
            $used   = (float) $r['used_qty'];
            $boughtIng += (float) ($r['bought_cost'] ?? 0);
            $usedIng   += (float) ($r['used_value'] ?? 0);

            $diff = $bought - $used;
            $rows[] = [
                'name'        => $r['name'],
                'base_unit'   => $r['base_unit'],
                'bought_text' => $r['bought_text'],
                'used_text'   => $r['used_text'],
                'diff_qty'    => round($diff, 3),
                'diff_value'  => round($diff * $rate, 2),
                'direction'   => $bought <= 0 ? 'not_bought'
                    : ($used <= 0 ? 'not_used' : ($diff >= 0 ? 'bought_more' : 'used_more')),
                'has_rate'    => $rate > 0,
            ];
        }

        // Biggest rupee gap first; a gap with no rate cannot be ranked by money.
        usort($rows, fn ($a, $b) => abs($b['diff_value']) <=> abs($a['diff_value']));

        // Lines that exist but carry no ingredient (a product not linked yet, a bag, a
        // POS charge) are a different fix from a bill with no lines at all.
        $untagged    = $this->untaggedLineTotal($businessUnitId, $month);
        $rest        = max(0.0, round($productBought - $meatBought - $boughtIng, 2));
        $untagged    = min($untagged, $rest);
        $notItemised = round($rest - $untagged, 2);

        // Per product: the recipe cost of what was made, against its price.
        $prices   = [];
        foreach ($production['products'] ?? [] as $p) {
            $prices[(int) $p['product_id']] = (float) ($p['selling_price'] ?? 0);
        }
        $products = [];
        foreach ($productCost['rows'] ?? [] as $pc) {
            $price = $prices[(int) $pc['product_id']] ?? 0.0;
            $products[] = [
                'product_name'   => $pc['product_name'],
                'made'           => $pc['made'],
                'cost_per_pack'  => $pc['cost_per_pack'],
                'selling_price'  => $price ?: null,
                'share_of_price' => $price > 0 ? round($pc['cost_per_pack'] * 100 / $price, 1) : null,
            ];
        }

        $notes = [];
        if ($made === 0) {
            $notes[] = 'Nothing was made this month, so there is nothing to compare.';
        } elseif ($costed === 0) {
            $notes[] = sprintf(
                'None of the %d packs had a recipe when they were made, so there is no recipe cost to compare yet. '
                . 'Packs made from now on, for products with a recipe, fill this in.', $made);
        } elseif ($uncosted > 0) {
            $notes[] = sprintf(
                '%d of the %d packs had no recipe when they were made, so they are outside the recipe cost.',
                $uncosted, $made);
        }
        if ($untagged > 0) {
            $notes[] = 'Some bills do have product lines, but those products are not linked to an ingredient yet. '
                . "Link them on the vendor's Products screen and their next bills count here.";
        }
        if ($notItemised > 0) {
            $notes[] = 'Some purchases were recorded as one total with no product lines, so they cannot be matched '
                . 'to a recipe. Switching those vendors to by-weight and entering their bills line by line brings them in.';
        }
        $notes[] = 'A difference is not a loss by itself: what was bought and not used is usually still on the shelf. '
            . 'Enter a stock count for an ingredient on the Planning page to see what is actually left.';

        return [
            'has_data'  => $costed > 0 || $boughtIng > 0,
            'packs'     => [
                'made'     => $made,
                'plan'     => $madeBatch,
                'direct'   => $madeManual,
                'costed'   => $costed,
                'uncosted' => $uncosted,
            ],
            'recipe_cost'     => (float) ($productCost['totals']['cost'] ?? 0),
            'recipe_per_pack' => (float) ($productCost['totals']['cost_per_pack'] ?? 0),
            'purchases'       => [
                'total'        => round($productBought, 2),
                'meat'         => round($meatBought, 2),
                'itemised'     => round($boughtIng, 2),
                'untagged'     => round($untagged, 2),
                'not_itemised' => $notItemised,
            ],
            'ingredients'     => [
                'bought'     => round($boughtIng, 2),
                'used'       => round($usedIng, 2),
                'difference' => round($boughtIng - $usedIng, 2),
                'rows'       => $rows,
            ],
            'meat'            => $meatRows,
            'products'        => $products,
            'notes'           => $notes,
        ];
    }

    /** Rs on purchase lines this month that carry no ingredient. */
    private function untaggedLineTotal(int $bu, string $month): float
    {
        [$start, $end] = $this->window($month);
        try {
            $q = DB::table('t_fin_vendor_purchase_items as i')
                ->join('t_fin_ledger as l', 'l.id', '=', 'i.ledger_id')
                ->where('l.business_unit_id', $bu)
                ->where('l.transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE)
                ->whereIn('l.approval_status', [LedgerModel::STATUS_APPROVED, LedgerModel::STATUS_PENDING_L2])
                ->whereBetween('l.transaction_date', [$start->toDateString(), $end->toDateString()])
                ->whereNull('i.ingredient_id')
                ->where('i.line_total', '>', 0);
            QurbaniFinanceFilter::applyToLedgerQuery($q, 'l', QurbaniFinanceFilter::MODE_EXCLUDE);
            return round((float) $q->sum('i.line_total'), 2);
        } catch (\Throwable $e) {
            \Log::warning('Frozen month: untagged lines failed', ['error' => $e->getMessage()]);
            return 0.0;
        }
    }

    private function kgText(float $grams): string
    {
        $kg = abs($grams) / 1000;
        return ($grams < 0 ? '−' : '') . rtrim(rtrim(number_format($kg, 3), '0'), '.') . ' kg';
    }

    /**
     * How the month's packs reached the warehouse, said out loud.
     *
     * ⭐⭐ "449 made" is two different stories. Some packs went through a production
     * plan — their meat came off storage, a batch was opened and closed, and the system
     * watched it happen. The rest were typed straight into the warehouse because the
     * batch was closed at 0 or never opened. Both are real production and both are
     * costed identically here; what differs is how much the system actually saw.
     *
     * So the number is shown split rather than blended, and a line of plain English
     * says which is which. Owner's ask, 21-Sep: "we can have an alert text in the month
     * review to flag how many were made through plan and how many direct inputs."
     *
     * ⚠ This is NOT a complaint about direct entry. The standing ruling is that
     *   whatever number Qasim enters is what was produced; the batch-end guard was
     *   deliberately dropped and must not come back. This only tells the reader how the
     *   figure was assembled.
     */
    private function madeSplit(int $made, int $madeBatch, int $madeManual, array $productCost): array
    {
        $uncosted = max(0, $made - (int) ($productCost['totals']['made'] ?? 0));

        $planShare = $made > 0 ? round($madeBatch * 100 / $made, 1) : 0.0;

        if ($made === 0) {
            $note = 'Nothing was made this month.';
        } elseif ($madeManual === 0) {
            $note = sprintf('All %d packs came through a production plan.', $made);
        } elseif ($madeBatch === 0) {
            $note = sprintf('All %d packs were entered straight into the warehouse, with no production plan behind them.', $made);
        } else {
            $note = sprintf(
                '%d of %d packs came through a production plan; %d were entered directly into the warehouse.',
                $madeBatch, $made, $madeManual
            );
        }

        $warnings = [];
        if ($uncosted > 0) {
            $warnings[] = sprintf(
                '%d of the %d packs have no recipe behind them, so their ingredients are not in the figures below.',
                $uncosted, $made
            );
        }

        return [
            'made'            => $made,
            'plan'            => $madeBatch,
            'direct'          => $madeManual,
            'plan_share_pct'  => $planShare,
            'costed'          => (int) ($productCost['totals']['made'] ?? 0),
            'uncosted'        => $uncosted,
            'note'            => $note,
            'warnings'        => $warnings,
        ];
    }
}
