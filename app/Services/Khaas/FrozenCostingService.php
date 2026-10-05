<?php

namespace App\Services\Khaas;

use App\Models\CRM\ProductModel;
use App\Models\FIN\LedgerModel;
use App\Models\Khaas\IngredientModel;
use App\Services\QurbaniFinanceFilter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bought, used, left — and roughly what it cost.
 *
 * ⭐⭐ THIS IS AN ESTIMATE AND SAYS SO. It multiplies a recipe standard by an average
 * purchase rate. It is not a stock audit and nothing here is an accounting entry: no
 * ledger row is read for its balance, written, or changed. Money keeps flowing exactly
 * as it does today and this class reads quantities beside it.
 *
 * ⭐ WHY AVERAGE RATE AND NOT FIFO. The Supplies pool draws FIFO because every take-out
 * posts a real expense against a real purchase. Nothing posts here, so FIFO would buy
 * legs, residue sweeps and a replay problem for no answer the owner would read
 * differently. The month's average rate per ingredient (falling back to the last month
 * that had one) gives the same management figure with none of that machinery — and the
 * consumption rows are already the right shape to add legs later if that changes.
 *
 * ⚠ MEAT IS NOT ESTIMATED. Meat is bought and consumed through the storage ledger,
 *   which is exact, so for meat ingredients this class reports what the ledger says and
 *   shows the recipe standard beside it as a comparison, never in place of it.
 */
class FrozenCostingService
{
    /** Same posted set HQ, Month Review and the Finance screens use. */
    private const POSTED_STATUSES = [
        LedgerModel::STATUS_APPROVED,
        LedgerModel::STATUS_PENDING_L2,
    ];

    /** How far back to look for a rate before giving up on one. */
    private const RATE_FALLBACK_MONTHS = 12;

    // =================================================================
    //  THE PANEL
    // =================================================================

    /**
     * Every ingredient for the month: what came in, what the recipes say went out,
     * what that was worth, and what should still be on the shelf.
     *
     * @return array{rows:array,totals:array,as_of:string,notes:array}
     */
    public function ingredientMonth(int $businessUnitId, string $month): array
    {
        [$start, $end] = (new FrozenMonthService())->window($month);

        // ⚠ Active ingredients PLUS any inactive one that actually moved this month.
        //   Filtering on is_active alone would drop a retired ingredient's usage off the
        //   panel while `productMonth()` still counted its cost — the panel and the
        //   per-product figures would quietly disagree, which is the one thing this
        //   whole feature exists to avoid.
        $movedIds = [];
        try {
            $movedIds = DB::table('t_crm_khaas_batch_consumption')
                ->where('business_unit_id', $businessUnitId)
                ->whereBetween('made_on', [$start->toDateString(), $end->toDateString()])
                ->distinct()->pluck('ingredient_id')->all();
        } catch (\Throwable $e) {
            $movedIds = [];
        }

        $ingredients = IngredientModel::where('business_unit_id', $businessUnitId)
            ->where(function ($q) use ($movedIds) {
                $q->where('is_active', 1);
                if ($movedIds) {
                    $q->orWhereIn('id', $movedIds);
                }
            })
            ->orderByRaw("FIELD(kind,'meat','vegetable','dairy','dry','packaging','other')")
            ->orderBy('name')
            ->get();

        if ($ingredients->isEmpty()) {
            return ['rows' => [], 'totals' => $this->emptyTotals(), 'as_of' => $month, 'notes' => []];
        }

        $ids = $ingredients->pluck('id')->all();

        $purchased = $this->purchasedByIngredient($businessUnitId, $ids, $start, $end);
        $used      = $this->usedByIngredient($businessUnitId, $ids, $start, $end);
        $rates     = $this->ratesFor($businessUnitId, $ids, $month);
        $meat      = $this->meatByStorageProduct($businessUnitId, $start, $end);
        $remaining = $this->remainingByIngredient($businessUnitId, $ids, $end);
        // ❄ Sep-30: start · adjusted · latest weigh-in, from the same stock engine.
        $stock     = (new IngredientStockService())->monthColumns($businessUnitId, $ids, $start, $end);

        $rows   = [];
        $totals = $this->emptyTotals();
        $notes  = [];

        // Which ingredients have ANY vendor product tagged to them — so a zero-rate note
        // can say "never bought" or "not linked", which need different fixes.
        $linked = [];
        try {
            foreach (DB::table('t_fin_vendor_products')->whereIn('ingredient_id', $ids)
                ->where('is_active', 1)->distinct()->pluck('ingredient_id') as $lid) {
                $linked[(int) $lid] = true;
            }
        } catch (\Throwable $e) {
            $linked = [];
        }

        foreach ($ingredients as $ing) {
            $id = (int) $ing->id;

            $boughtQty  = (float) ($purchased[$id]['qty'] ?? 0);
            $boughtCost = (float) ($purchased[$id]['cost'] ?? 0);
            $usedQty    = (float) ($used[$id] ?? 0);
            $rate       = (float) ($rates[$id]['rate'] ?? 0);
            $rateSource = (string) ($rates[$id]['source'] ?? 'none');

            // Meat overrides both sides from the storage ledger, which is exact.
            $isMeat     = $ing->isMeat();
            $standardQty = $usedQty;
            $meatRow    = $isMeat ? ($meat[(int) $ing->storage_product_id] ?? null) : null;

            if ($meatRow) {
                // Storage speaks kg; ingredients speak base units (g for meat).
                $boughtQty  = $meatRow['received_kg'] * 1000;
                $usedQty    = $meatRow['used_kg'] * 1000;
                $rate       = $meatRow['rate_per_kg'] / 1000;
                $rateSource = 'storage';
                $boughtCost = round($meatRow['received_kg'] * $meatRow['rate_per_kg'], 2);
            }

            $usedValue = round($usedQty * $rate, 2);
            $left      = $remaining[$id] ?? null;

            $row = [
                'ingredient_id'   => $id,
                'name'            => $ing->name,
                'kind'            => $ing->kind,
                'kind_label'      => $ing->kindLabel(),
                'base_unit'       => $ing->base_unit,
                'is_meat'         => $isMeat,

                'bought_qty'      => round($boughtQty, 3),
                'bought_text'     => $ing->phrase($boughtQty),
                'bought_cost'     => round($boughtCost, 2),

                'used_qty'        => round($usedQty, 3),
                'used_text'       => $ing->phrase($usedQty),
                'used_value'      => $usedValue,

                // What the recipes said, kept beside the ledger truth for meat so the
                // two can be compared instead of one quietly replacing the other.
                'standard_qty'    => round($standardQty, 3),
                'standard_text'   => $ing->phrase($standardQty),

                'rate_per_base'   => $rate,
                'rate_text'       => $this->rateText($ing, $rate),
                'rate_source'     => $rateSource,

                'remaining_qty'   => $left === null ? null : round($left, 3),
                'remaining_text'  => $left === null ? null : $ing->phrase($left),
                'tracked'         => $left !== null,

                // Did anything happen to it this month? Drives the ordering below and
                // lets both screens fold the silent ones away.
                'has_movement'    => ($boughtQty > 0 || $usedQty > 0),
            ];

            // ❄ Sep-30 — start + bought − used ± adjusted = left (a full month). Meat is
            //   Storage's and gets none of these.
            $st = (!$isMeat && isset($stock[$id])) ? $stock[$id] : null;
            $startQty = $st['start_qty'] ?? null;
            $row['start_qty']      = $startQty;
            $row['start_text']     = $startQty === null ? null : $ing->phrase($startQty);
            $row['started_on']     = $st['started_on'] ?? null;
            $row['started_text']   = ($st && $st['started_on'])
                ? 'started ' . Carbon::parse($st['started_on'])->format('j M') . ' at ' . $ing->phrase((float) $st['started_qty'])
                : null;
            $row['partial']        = (bool) ($st['partial'] ?? false);
            $row['adjusted_qty']   = $st ? $st['adjusted'] : null;
            $row['adjusted_text']  = ($st && abs($st['adjusted']) >= 0.0005)
                ? ($st['adjusted'] > 0 ? '+' : '−') . $ing->phrase(abs($st['adjusted']))
                : null;
            $row['adjusted_value'] = ($st && $rate > 0) ? round($st['adjusted'] * $rate, 2) : null;
            $row['last_check']     = null;
            $row['last_check_value'] = null;
            if ($st && $st['last_check']) {
                $chk = $st['last_check'];
                $gap = $chk['gap']['gap'] ?? null;
                $row['last_check'] = [
                    'date_text' => Carbon::parse($chk['entry']['date'])->format('j M'),
                    'qty_text'  => $ing->phrase($chk['entry']['qty']),
                    'gap'       => $gap,
                    'gap_text'  => $gap === null ? null : (new IngredientStockService())->gapText($ing, $gap),
                ];
                $row['last_check_value'] = ($gap !== null && $rate > 0) ? round($gap * $rate, 2) : null;
            }
            // A weigh-in or an adjustment IS something that happened this month — never fold it away.
            if ($row['adjusted_text'] !== null || $row['last_check'] !== null) {
                $row['has_movement'] = true;
            }

            // ⚠ Used more than was bought is SHOWN, never clamped: a negative is the
            //   signal that a purchase was not tagged to this ingredient, and hiding it
            //   would hide the very thing the owner needs to fix.
            // ⚠ Sep-30: the STARTING stock counts too — 1 kg on the shelf + 200 g bought
            //   covers 500 g used, and saying "a purchase is probably untagged" was false.
            $startCovers = (float) ($startQty ?? ($st['started_qty'] ?? 0));
            $row['short'] = $usedQty > 0 && $boughtQty > 0 && $usedQty > $boughtQty + max(0.0, $startCovers) && !$isMeat;
            if ($row['short']) {
                $notes[] = sprintf(
                    'More %s was used than bought this month. A purchase of it is probably untagged.',
                    $ing->name
                );
            }
            if ($usedQty > 0 && $rate <= 0) {
                // Say WHY there is no rate — the two causes have different remedies, and
                // "not linked" is the one Qasim can fix himself by adding the product.
                $notes[] = $isMeat
                    ? sprintf('No purchase rate known for %s yet, so its cost shows as zero.', $ing->name)
                    : sprintf(
                        '%s is in a recipe but %s, so its cost shows as zero.',
                        $ing->name,
                        isset($linked[$id])
                            ? 'nothing has ever been bought against it'
                            : 'no vendor product carries it — add it as a product, with this exact name, under the vendor you buy it from'
                    );
            }

            $rows[] = $row;

            $totals['bought_cost'] += $row['bought_cost'];
            $totals['used_value']  += $row['used_value'];
            if ($isMeat) {
                $totals['meat_used_value'] += $row['used_value'];
            } else {
                $totals['other_used_value'] += $row['used_value'];
            }
        }

        // ⭐ What MOVED this month comes first. Seen on the device 22-Sep: eleven
        //    vegetables with nothing bought and nothing used pushed the two ingredients
        //    that actually moved off the screen. A row with no movement says nothing
        //    about the month — it is still listed, but after the ones that do, and the
        //    screens fold it away behind a count.
        usort($rows, function (array $a, array $b) {
            if ($a['has_movement'] !== $b['has_movement']) {
                return $b['has_movement'] <=> $a['has_movement'];
            }
            if ($a['has_movement']) {
                return ($b['used_qty'] <=> $a['used_qty']) ?: ($b['bought_qty'] <=> $a['bought_qty']);
            }
            return strcmp($a['name'], $b['name']);
        });

        $totals['moved']            = count(array_filter($rows, fn ($r) => $r['has_movement']));
        $totals['idle']             = count($rows) - $totals['moved'];
        $totals['bought_cost']      = round($totals['bought_cost'], 2);
        $totals['used_value']       = round($totals['used_value'], 2);
        $totals['meat_used_value']  = round($totals['meat_used_value'], 2);
        $totals['other_used_value'] = round($totals['other_used_value'], 2);
        $totals['ingredients']      = count($rows);

        return [
            'rows'   => $rows,
            'totals' => $totals,
            'as_of'  => $month,
            'notes'  => array_values(array_unique($notes)),
        ];
    }

    /**
     * Cost per product for the month, and the plan-vs-direct split behind it.
     *
     * ⭐ The split is the point, not decoration. "449 made" is two different stories:
     * 338 packs that went through a production plan and had their meat deducted from
     * storage, and 111 that were typed straight in. Both are costed the same way here,
     * and the screen says which is which so the owner can judge the number.
     *
     * ⚠⚠ MEAT IS A STANDARD HERE, NOT THE LEDGER TRUTH — the one place in this class
     *    where that is so, and the screen must say it. `ingredientMonth()` reports meat
     *    from the storage ledger because that is exact for a MONTH. It cannot be exact
     *    per PRODUCT: storage records that 9 kg of thigh left the freezer, not which
     *    pack it became. So a per-product cost has to use the recipe standard, and the
     *    two meat figures on Month Review will differ whenever production ran off
     *    recipe. That difference is information, but only if it is labelled.
     *
     * @return array{rows:array,totals:array}
     */
    public function productMonth(int $businessUnitId, string $month): array
    {
        [$start, $end] = (new FrozenMonthService())->window($month);

        $ingredients = IngredientModel::where('business_unit_id', $businessUnitId)->get()->keyBy('id');
        $rates       = $this->ratesFor($businessUnitId, $ingredients->keys()->all(), $month);
        $meat        = $this->meatByStorageProduct($businessUnitId, $start, $end);

        // Meat rate per base unit comes from the storage ledger, same as the panel.
        foreach ($ingredients as $ing) {
            if ($ing->isMeat() && isset($meat[(int) $ing->storage_product_id])) {
                $rates[(int) $ing->id] = [
                    'rate'   => $meat[(int) $ing->storage_product_id]['rate_per_kg'] / 1000,
                    'source' => 'storage',
                ];
            }
        }

        $rows = [];
        $totals = ['made' => 0, 'made_plan' => 0, 'made_direct' => 0, 'cost' => 0.0];

        try {
            $consumption = DB::table('t_crm_khaas_batch_consumption')
                ->where('business_unit_id', $businessUnitId)
                ->whereBetween('made_on', [$start->toDateString(), $end->toDateString()])
                ->select('product_id', 'ingredient_id', 'source_kind',
                    DB::raw('SUM(qty_base) as qty'))
                ->groupBy('product_id', 'ingredient_id', 'source_kind')
                ->get();

            // Packs are counted once per (product, source, log) — summing `packets`
            // across ingredient rows would multiply the batch by its ingredient count.
            $packs = DB::table('t_crm_khaas_batch_consumption')
                ->where('business_unit_id', $businessUnitId)
                ->whereBetween('made_on', [$start->toDateString(), $end->toDateString()])
                ->select('product_id', 'source_kind', 'warehouse_log_id',
                    DB::raw('MAX(packets) as packets'))
                ->groupBy('product_id', 'source_kind', 'warehouse_log_id')
                ->get();

            $byProduct = [];

            foreach ($packs as $p) {
                $pid = (int) $p->product_id;
                $byProduct[$pid] ??= ['made' => 0, 'plan' => 0, 'direct' => 0, 'cost' => 0.0, 'lines' => []];
                $byProduct[$pid]['made'] += (int) $p->packets;
                $byProduct[$pid][$p->source_kind === 'plan' ? 'plan' : 'direct'] += (int) $p->packets;
            }

            foreach ($consumption as $c) {
                $pid = (int) $c->product_id;
                $iid = (int) $c->ingredient_id;
                $ing = $ingredients->get($iid);
                if (!$ing) {
                    continue;
                }

                $qty  = (float) $c->qty;
                $rate = (float) ($rates[$iid]['rate'] ?? 0);
                $cost = round($qty * $rate, 2);

                $byProduct[$pid] ??= ['made' => 0, 'plan' => 0, 'direct' => 0, 'cost' => 0.0, 'lines' => []];
                $byProduct[$pid]['cost'] += $cost;

                $key = $iid;
                $byProduct[$pid]['lines'][$key] ??= [
                    'ingredient_id' => $iid,
                    'name'          => $ing->name,
                    'kind'          => $ing->kind,
                    'qty_base'      => 0.0,
                    'cost'          => 0.0,
                ];
                $byProduct[$pid]['lines'][$key]['qty_base'] += $qty;
                $byProduct[$pid]['lines'][$key]['cost']     += $cost;
            }

            $products = ProductModel::whereIn('id', array_keys($byProduct))->get()->keyBy('id');

            foreach ($byProduct as $pid => $agg) {
                $product = $products->get($pid);
                $made    = (int) $agg['made'];

                $lines = array_values(array_map(function (array $l) use ($ingredients) {
                    $ing = $ingredients->get($l['ingredient_id']);
                    $l['qty_text'] = $ing ? $ing->phrase($l['qty_base']) : (string) round($l['qty_base'], 3);
                    $l['qty_base'] = round($l['qty_base'], 3);
                    $l['cost']     = round($l['cost'], 2);
                    return $l;
                }, $agg['lines']));

                usort($lines, fn ($a, $b) => $b['cost'] <=> $a['cost']);

                $rows[] = [
                    'product_id'    => $pid,
                    'product_name'  => $product->title ?? 'Unknown',
                    'made'          => $made,
                    'made_plan'     => (int) $agg['plan'],
                    'made_direct'   => (int) $agg['direct'],
                    'cost'          => round($agg['cost'], 2),
                    'cost_per_pack' => $made > 0 ? round($agg['cost'] / $made, 2) : 0.0,
                    'lines'         => $lines,
                ];

                $totals['made']        += $made;
                $totals['made_plan']   += (int) $agg['plan'];
                $totals['made_direct'] += (int) $agg['direct'];
                $totals['cost']        += $agg['cost'];
            }

            usort($rows, fn ($a, $b) => $b['cost'] <=> $a['cost']);
        } catch (\Throwable $e) {
            Log::warning('Frozen costing: product month failed', ['error' => $e->getMessage()]);
        }

        $totals['cost']          = round($totals['cost'], 2);
        $totals['cost_per_pack'] = $totals['made'] > 0 ? round($totals['cost'] / $totals['made'], 2) : 0.0;

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * One product's estimated ingredient cost per pack, for the card chip.
     * Returns null when there is nothing honest to show.
     */
    public function costPerPackFor(int $businessUnitId, int $productId, string $month): ?array
    {
        $data = $this->productMonth($businessUnitId, $month);

        foreach ($data['rows'] as $row) {
            if ($row['product_id'] === $productId) {
                return [
                    'cost_per_pack' => $row['cost_per_pack'],
                    'made'          => $row['made'],
                    'made_plan'     => $row['made_plan'],
                    'made_direct'   => $row['made_direct'],
                    'month'         => $month,
                ];
            }
        }

        return null;
    }

    // =================================================================
    //  THE PIECES
    // =================================================================

    /**
     * What was bought, per ingredient, in base units and rupees.
     *
     * Reads the PURCHASE LINE's stamped ingredient_id and qty_base, not the catalogue's
     * — so re-tagging a catalogue product tomorrow never rewrites what a line meant on
     * the day it was recorded. (Deliberately the opposite choice from category_level_1,
     * which is an opinion you may revise; a quantity is a fact about that day.)
     */
    private function purchasedByIngredient(int $bu, array $ingredientIds, Carbon $start, Carbon $end): array
    {
        $out = [];

        if (!$ingredientIds) {
            return $out;
        }

        try {
            $q = DB::table('t_fin_vendor_purchase_items as i')
                ->join('t_fin_ledger as l', 'l.id', '=', 'i.ledger_id')
                ->where('l.business_unit_id', $bu)
                ->where('l.transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE)
                ->whereIn('l.approval_status', self::POSTED_STATUSES)
                ->whereBetween('l.transaction_date', [$start->toDateString(), $end->toDateString()])
                ->whereIn('i.ingredient_id', $ingredientIds)
                ->whereNotNull('i.qty_base');

            QurbaniFinanceFilter::applyToLedgerQuery($q, 'l', QurbaniFinanceFilter::MODE_EXCLUDE);

            $rows = $q->select('i.ingredient_id',
                DB::raw('SUM(i.qty_base) as qty'),
                DB::raw('SUM(i.line_total) as cost'))
                ->groupBy('i.ingredient_id')
                ->get();

            foreach ($rows as $r) {
                $out[(int) $r->ingredient_id] = [
                    'qty'  => (float) $r->qty,
                    'cost' => (float) $r->cost,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('Frozen costing: purchases failed', ['error' => $e->getMessage()]);
        }

        return $out;
    }

    /** What the recipes say went out, per ingredient. */
    private function usedByIngredient(int $bu, array $ingredientIds, Carbon $start, Carbon $end): array
    {
        $out = [];

        if (!$ingredientIds) {
            return $out;
        }

        try {
            $rows = DB::table('t_crm_khaas_batch_consumption')
                ->where('business_unit_id', $bu)
                ->whereIn('ingredient_id', $ingredientIds)
                ->whereBetween('made_on', [$start->toDateString(), $end->toDateString()])
                ->select('ingredient_id', DB::raw('SUM(qty_base) as qty'))
                ->groupBy('ingredient_id')
                ->get();

            foreach ($rows as $r) {
                $out[(int) $r->ingredient_id] = (float) $r->qty;
            }
        } catch (\Throwable $e) {
            Log::warning('Frozen costing: consumption failed', ['error' => $e->getMessage()]);
        }

        return $out;
    }

    /**
     * Rs per BASE unit, per ingredient.
     *
     * This month's own purchases first; if it did not buy any, the most recent earlier
     * month that did — so an ingredient used but not re-bought is still valued instead
     * of counting as free. Lines with no quantity (a discount, a POS charge, a service)
     * are excluded by the qty_base filter, so they stay in the money and out of the rate.
     */
    private function ratesFor(int $bu, array $ingredientIds, string $month): array
    {
        $out = [];

        if (!$ingredientIds) {
            return $out;
        }

        [$start, $end] = (new FrozenMonthService())->window($month);

        $inMonth = $this->rateQuery($bu, $ingredientIds, $start, $end);
        foreach ($inMonth as $id => $rate) {
            $out[$id] = ['rate' => $rate, 'source' => 'month'];
        }

        $missing = array_values(array_diff($ingredientIds, array_keys($out)));
        if ($missing) {
            $earlierStart = $start->copy()->subMonths(self::RATE_FALLBACK_MONTHS);
            $earlier = $this->rateQuery($bu, $missing, $earlierStart, $start->copy()->subDay());

            foreach ($earlier as $id => $rate) {
                $out[$id] = ['rate' => $rate, 'source' => 'earlier'];
            }
        }

        foreach ($ingredientIds as $id) {
            $out[(int) $id] ??= ['rate' => 0.0, 'source' => 'none'];
        }

        return $out;
    }

    /** @return array<int,float> ingredient id => Rs per base unit */
    private function rateQuery(int $bu, array $ingredientIds, Carbon $from, Carbon $to): array
    {
        $out = [];

        try {
            $q = DB::table('t_fin_vendor_purchase_items as i')
                ->join('t_fin_ledger as l', 'l.id', '=', 'i.ledger_id')
                ->where('l.business_unit_id', $bu)
                ->where('l.transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE)
                ->whereIn('l.approval_status', self::POSTED_STATUSES)
                ->whereBetween('l.transaction_date', [$from->toDateString(), $to->toDateString()])
                ->whereIn('i.ingredient_id', $ingredientIds)
                ->where('i.qty_base', '>', 0)
                // ⚠ Sep-27: a Rs 350 bill typed as "350 at Rs 1" is money, not a quantity —
                //   it must not set a rate here either (same rule as the recipe card).
                ->whereRaw(IngredientPriceService::NOT_MONEY_AS_QUANTITY);

            QurbaniFinanceFilter::applyToLedgerQuery($q, 'l', QurbaniFinanceFilter::MODE_EXCLUDE);

            $rows = $q->select('i.ingredient_id',
                DB::raw('SUM(i.line_total) as cost'),
                DB::raw('SUM(i.qty_base) as qty'))
                ->groupBy('i.ingredient_id')
                ->get();

            foreach ($rows as $r) {
                $qty = (float) $r->qty;
                if ($qty > 0) {
                    $out[(int) $r->ingredient_id] = round(((float) $r->cost) / $qty, 6);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Frozen costing: rate query failed', ['error' => $e->getMessage()]);
        }

        return $out;
    }

    /**
     * Meat, straight from the storage ledger — received kg, used kg and Rs/kg.
     * Reuses FrozenMonthService so there is one definition of meat in Frozen, not two.
     *
     * @return array<int,array{received_kg:float,used_kg:float,rate_per_kg:float}>
     */
    private function meatByStorageProduct(int $bu, Carbon $start, Carbon $end): array
    {
        $out = [];

        try {
            $month = $start->format('Y-m');
            $meat  = (new FrozenMonthService())->meat($bu, $month);

            foreach ($meat['rows'] as $row) {
                $out[(int) $row['product_id']] = [
                    'received_kg' => (float) $row['received_kg'],
                    'used_kg'     => (float) $row['used_kg'],
                    'rate_per_kg' => (float) $row['rate_per_kg'],
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('Frozen costing: meat read failed', ['error' => $e->getMessage()]);
        }

        return $out;
    }

    /**
     * What should still be on the shelf, per ingredient, at the end of $asOf.
     *
     * ⭐ Sep-30: ONE engine — IngredientStockService. The same figure the stock sheet, the
     * recipe editor and the purchase sheet show, so Month Review can never disagree with
     * them. Rules unchanged: from the latest start/adjust, strictly after its day; a
     * weigh-in "check" never moves it; no start = null ("not tracked").
     *
     * @return array<int,float|null>
     */
    private function remainingByIngredient(int $bu, array $ingredientIds, Carbon $asOf): array
    {
        return (new IngredientStockService())->onShelf($bu, $ingredientIds, $asOf->toDateString());
    }

    private function rateText(IngredientModel $ing, float $ratePerBase): string
    {
        if ($ratePerBase <= 0) {
            return '—';
        }

        // Rs per gram is unreadable; quote the unit a person buys in.
        $per = $ing->base_unit === IngredientModel::UNIT_PCS ? 1 : 1000;
        $unit = match ($ing->base_unit) {
            IngredientModel::UNIT_G   => 'kg',
            IngredientModel::UNIT_ML  => 'L',
            default                   => 'pc',
        };

        return 'Rs ' . number_format($ratePerBase * $per, 2) . ' / ' . $unit;
    }

    private function emptyTotals(): array
    {
        return [
            'ingredients'      => 0,
            'moved'            => 0,
            'idle'             => 0,
            'bought_cost'      => 0.0,
            'used_value'       => 0.0,
            'meat_used_value'  => 0.0,
            'other_used_value' => 0.0,
        ];
    }
}
