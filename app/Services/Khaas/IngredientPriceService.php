<?php

namespace App\Services\Khaas;

use App\Models\FIN\LedgerModel;
use App\Models\Khaas\IngredientModel;
use App\Services\FIN\VendorUnits;
use App\Services\QurbaniFinanceFilter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 💲 What an ingredient costs TODAY — the price on its newest bill — and what a recipe
 * costs at those prices.
 *
 * ⭐⭐ TWO PRICES, ON PURPOSE (owner ruling, 27-Sep-2026).
 *   • The RECIPE CARD asks "what does this pack cost to make now?" — so it reads the
 *     NEWEST bill. Cubes at Rs 50 last month and Rs 100 this week is Rs 100.
 *   • MONTH REVIEW asks "what did this month's packs cost, against this month's
 *     spend?" — so it keeps the month's AVERAGE rate (FrozenCostingService). Mixing the
 *     two would compare this month's purchases with next month's prices.
 *   Both read the same purchase lines; neither writes anything. No ledger row is read
 *   for its balance, written, or changed.
 *
 * ⭐ WHERE A PRICE COMES FROM, most trusted first:
 *   1. `bill`       — a purchase line stamped with the ingredient when it was recorded
 *                     (qty_base is the fact of that day).
 *   2. `older_bill` — a line recorded BEFORE ingredient stamping existed, reached
 *                     through its catalogue product's CURRENT tag. Labelled as such.
 *                     ⚠ Only before LEGACY_BEFORE: after that date an unstamped line
 *                     on a tagged product may be a deliberate "not an ingredient"
 *                     (a carrier bag), and borrowing the tag would price the bag.
 *   3. `meat_order` — meat is bought through the storage Meat Order, never a vendor
 *                     line; its newest order line gives Rs/kg.
 *   Nothing else. An ingredient with none of these has NO price and says so; it is
 *   never valued at zero, because a free ingredient makes a pack look cheap.
 */
class IngredientPriceService
{
    /** A price older than this is shown, but marked "old price". */
    public const STALE_DAYS = 60;

    /** Newest price this many times above or below the previous one is flagged. */
    public const JUMP_FACTOR = 3.0;

    /** Ingredient stamping (and "not an ingredient") arrived with the Sep-22 round. */
    public const LEGACY_BEFORE = '2026-09-22';

    /** Only look back this far for a bill. */
    private const LOOKBACK_DAYS = 400;

    private const POSTED_STATUSES = [
        LedgerModel::STATUS_APPROVED,
        LedgerModel::STATUS_PENDING_L2,
    ];

    private const MEAT_SOURCE = 'khaas_storage';

    /**
     * ⚠⚠ A line that recorded MONEY, not a quantity: "350 kg of garlic at Rs 1" is a
     *    Rs 350 bill typed into the quantity box. Found on the replica, 27-Sep: all eight
     *    February vegetable lines at Vegetable Supplies are this shape, and they priced
     *    garlic at Rs 1 a kg. The money on those bills is right; the quantity is not a
     *    quantity, so such a line can never set a price.
     */
    public const NOT_MONEY_AS_QUANTITY = 'NOT (i.rate_per_unit = 1 AND ABS(i.quantity - i.line_total) < 0.01)';

    // =================================================================
    //  PRICES
    // =================================================================

    /**
     * The newest price per ingredient. Ingredients without one are simply absent.
     *
     * @param  int[]|null $ingredientIds  null = every ingredient of the unit
     * @return array<int,array> ingredient id => price facts
     */
    public function latest(int $businessUnitId, ?array $ingredientIds = null): array
    {
        $q = IngredientModel::where('business_unit_id', $businessUnitId);
        if ($ingredientIds !== null) {
            if (!$ingredientIds) {
                return [];
            }
            $q->whereIn('id', $ingredientIds);
        }
        $ingredients = $q->get()->keyBy('id');
        if ($ingredients->isEmpty()) {
            return [];
        }

        $meat   = $ingredients->filter(fn (IngredientModel $i) => $i->isMeat());
        $bought = $ingredients->reject(fn (IngredientModel $i) => $i->isMeat());

        // ingredient id => [date => ['qty' => , 'cost' => , 'vendor' => , 'product' => , 'source' => ]]
        $byDay = [];
        foreach ($this->billLines($businessUnitId, $bought->keys()->all()) as $row) {
            $this->addToDay($byDay, $row);
        }
        foreach ($this->olderBillLines($businessUnitId, $bought->keys()->all()) as $row) {
            $this->addToDay($byDay, $row);
        }

        $out = [];
        foreach ($byDay as $iid => $days) {
            $ing = $ingredients->get($iid);
            if ($ing) {
                $out[$iid] = $this->shapePrice($ing, $days);
            }
        }

        foreach ($this->meatOrderDays($meat) as $iid => $days) {
            $out[$iid] = $this->shapePrice($ingredients->get($iid), $days);
        }

        return $out;
    }

    /**
     * The newest date an ingredient was bought on an OLDER, unstamped bill. Lets the
     * "linked but never bought" badge agree with the price the same screen shows.
     *
     * @return array<int,string> ingredient id => Y-m-d
     */
    public function olderBillDates(int $businessUnitId, array $ingredientIds): array
    {
        $out = [];
        foreach ($this->olderBillLines($businessUnitId, $ingredientIds) as $row) {
            $id = (int) $row['ingredient_id'];
            if (!isset($out[$id]) || $row['date'] > $out[$id]) {
                $out[$id] = $row['date'];
            }
        }
        return $out;
    }

    // =================================================================
    //  RECIPE COST
    // =================================================================

    /**
     * A recipe (as RecipeService::recipeFor shapes it) priced at today's prices.
     *
     * ⭐ A PARTIAL TOTAL NEVER LOOKS COMPLETE. Every line without a price is counted
     *   and named, and `complete` is false until there are none — so "Rs 124 a pack"
     *   with three unpriced lines reads as a floor, not as the answer.
     * ⚠ Optional lines are shown with their price but left OUT of the total: the
     *   recipe says they are sometimes not used.
     *
     * @param array      $recipe  RecipeService::recipeFor() output
     * @param array      $prices  latest() output
     */
    public function costRecipe(array $recipe, array $prices, ?float $sellingPrice = null): array
    {
        $basis = (int) ($recipe['basis_packets'] ?? 0);

        $lines = [];
        $batch = 0.0;
        $priced = 0;
        $unpriced = [];
        $flags = 0;

        foreach ($recipe['lines'] ?? [] as $l) {
            $iid   = (int) $l['ingredient_id'];
            $p     = $prices[$iid] ?? null;
            $qty   = (float) ($l['qty_per_basis'] ?? 0);
            $opt   = !empty($l['is_optional']);

            if ($p) {
                $cost = round($qty * $p['price_per_base'], 2);
                if (!$opt) {
                    $batch += $cost;
                    $priced++;
                }
                if ($p['stale'] || $p['jump']) {
                    $flags++;
                }
                $lines[] = [
                    'ingredient_id' => $iid,
                    'name'          => $l['ingredient_name'] ?? '',
                    'state'         => 'priced',
                    'is_optional'   => $opt,
                    'batch_cost'    => $cost,
                    'per_pack'      => $basis > 0 ? round($cost / $basis, 2) : null,
                    'price'         => $p,
                ];
                continue;
            }

            $state = !empty($l['is_meat']) ? 'no_meat_price'
                : (($l['supply_state'] ?? '') === 'not_linked' ? 'not_linked' : 'never_bought');

            if (!$opt) {
                $unpriced[] = $l['ingredient_name'] ?? '';
            }
            $lines[] = [
                'ingredient_id' => $iid,
                'name'          => $l['ingredient_name'] ?? '',
                'state'         => $state,
                'is_optional'   => $opt,
                'batch_cost'    => null,
                'per_pack'      => null,
                'price'         => null,
                'why'           => $this->whyUnpriced($state),
            ];
        }

        $perPack = $basis > 0 ? round($batch / $basis, 2) : null;
        $share   = ($perPack !== null && $sellingPrice && $sellingPrice > 0 && $priced > 0)
            ? round($perPack * 100 / $sellingPrice, 1) : null;

        return [
            'basis_packets'   => $basis,
            'batch_cost'      => round($batch, 2),
            'per_pack'        => $perPack,
            'priced_lines'    => $priced,
            'unpriced_lines'  => count($unpriced),
            'unpriced_names'  => $unpriced,
            'complete'        => $priced > 0 && !$unpriced,
            'flagged_prices'  => $flags,
            'selling_price'   => $sellingPrice,
            'share_of_price'  => $share,
            'lines'           => $lines,
            'summary'         => $this->summary($priced, $unpriced, $perPack, $share, $sellingPrice),
            'note'            => 'At the price on each ingredient\'s newest bill.',
        ];
    }

    /**
     * One recipe, priced — what the recipe editor opens with.
     *
     * @return array{cost: array, prices: array}  prices = the whole unit's price book,
     *         so the editor can price a line the moment an ingredient is picked.
     */
    public function forRecipe(int $businessUnitId, array $recipe): array
    {
        $prices = $this->latest($businessUnitId);
        $pid    = (int) ($recipe['product_id'] ?? 0);
        $sell   = $pid ? ((new FrozenMonthService())->sellingPrices([$pid])[$pid] ?? null) : null;

        return [
            'cost'   => !empty($recipe['has_recipe']) || !empty($recipe['lines'])
                ? $this->costRecipe($recipe, $prices, $sell) : null,
            'prices' => (object) $prices,
            'selling_price' => $sell,
        ];
    }

    /**
     * Every product with a current recipe, priced — for the card chips on both
     * surfaces, in one pass so a list does not fire one request per card.
     *
     * @return array<int,array> product id => short cost facts
     */
    public function forProducts(int $businessUnitId): array
    {
        $recipes = new RecipeService();
        $with    = array_values(array_filter($recipes->coverage($businessUnitId), fn ($c) => $c['has_recipe']));
        if (!$with) {
            return [];
        }

        $prices = $this->latest($businessUnitId);
        $sell   = (new FrozenMonthService())->sellingPrices(array_column($with, 'product_id'));

        $out = [];
        foreach ($with as $c) {
            $pid = (int) $c['product_id'];
            try {
                $cost = $this->costRecipe($recipes->recipeFor($pid), $prices, $sell[$pid] ?? null);
            } catch (\Throwable $e) {
                Log::warning('Ingredient prices: product cost failed', ['product_id' => $pid, 'error' => $e->getMessage()]);
                continue;
            }
            $out[$pid] = [
                'per_pack'       => $cost['per_pack'],
                'complete'       => $cost['complete'],
                'priced_lines'   => $cost['priced_lines'],
                'unpriced_lines' => $cost['unpriced_lines'],
                'unpriced_names' => $cost['unpriced_names'],
                'flagged_prices' => $cost['flagged_prices'],
                'share_of_price' => $cost['share_of_price'],
                'selling_price'  => $cost['selling_price'],
            ];
        }
        return $out;
    }

    /** forProducts() without rupees. */
    public static function stripProductCosts(array $map): array
    {
        return array_map(fn (array $c) => array_merge($c, [
            'per_pack' => null, 'share_of_price' => null, 'selling_price' => null,
        ]), $map);
    }

    /**
     * Only the money-free parts, for a person without view_khaas_costing: which lines
     * would be priced and which would not. Keys stay present so no client guesses.
     */
    public static function stripRupees(array $cost): array
    {
        $cost['batch_cost'] = null;
        $cost['per_pack'] = null;
        $cost['share_of_price'] = null;
        $cost['selling_price'] = null;
        $cost['summary'] = $cost['unpriced_lines'] > 0
            ? $cost['unpriced_lines'] . ' of the lines have no price yet: ' . implode(', ', $cost['unpriced_names']) . '.'
            : null;
        $cost['lines'] = array_map(function (array $l) {
            $l['batch_cost'] = null;
            $l['per_pack'] = null;
            if ($l['price']) {
                $l['price'] = array_merge($l['price'], [
                    'price_per_base' => null,
                    'price_text'     => null,
                    'previous'       => null,
                ]);
            }
            return $l;
        }, $cost['lines']);
        return $cost;
    }

    /** latest() without rupees — same keys, prices nulled. */
    public static function stripPriceBook(array $prices): array
    {
        return array_map(fn (array $p) => array_merge($p, [
            'price_per_base' => null,
            'price_text'     => null,
            'previous'       => null,
        ]), $prices);
    }

    // =================================================================
    //  INTERNALS
    // =================================================================

    /** Stamped lines: ingredient + qty_base written on the day. */
    private function billLines(int $bu, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        try {
            $q = DB::table('t_fin_vendor_purchase_items as i')
                ->join('t_fin_ledger as l', 'l.id', '=', 'i.ledger_id')
                ->leftJoin('t_fin_vendor_products as vp', 'vp.id', '=', 'i.vendor_product_id')
                ->leftJoin('t_fin_vendors as v', 'v.id', '=', 'vp.vendor_id')
                ->where('l.business_unit_id', $bu)
                ->where('l.transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE)
                ->whereIn('l.approval_status', self::POSTED_STATUSES)
                ->where('l.transaction_date', '>=', now()->subDays(self::LOOKBACK_DAYS)->toDateString())
                ->whereIn('i.ingredient_id', $ids)
                ->where('i.qty_base', '>', 0)
                ->where('i.line_total', '>', 0)
                ->whereRaw(self::NOT_MONEY_AS_QUANTITY);
            QurbaniFinanceFilter::applyToLedgerQuery($q, 'l', QurbaniFinanceFilter::MODE_EXCLUDE);

            return $q->select('i.ingredient_id', 'i.qty_base', 'i.line_total', 'i.product_name',
                    'l.transaction_date', 'v.vendor_name')
                ->get()
                ->map(fn ($r) => [
                    'ingredient_id' => (int) $r->ingredient_id,
                    'date'          => substr((string) $r->transaction_date, 0, 10),
                    'qty'           => (float) $r->qty_base,
                    'cost'          => (float) $r->line_total,
                    'vendor'        => $r->vendor_name,
                    'product'       => $r->product_name,
                    'source'        => 'bill',
                ])->all();
        } catch (\Throwable $e) {
            Log::warning('Ingredient prices: bill lines failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Unstamped lines from before stamping existed, reached through the product's
     * CURRENT tag — converted exactly as the stamp would have been
     * (quantity × pack_qty_base), and only when the line was bought in the product's
     * own unit, so a line typed in a different unit is never guessed at.
     */
    private function olderBillLines(int $bu, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        try {
            $q = DB::table('t_fin_vendor_purchase_items as i')
                ->join('t_fin_ledger as l', 'l.id', '=', 'i.ledger_id')
                ->join('t_fin_vendor_products as vp', 'vp.id', '=', 'i.vendor_product_id')
                ->leftJoin('t_fin_vendors as v', 'v.id', '=', 'vp.vendor_id')
                ->where('l.business_unit_id', $bu)
                ->where('l.transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE)
                ->whereIn('l.approval_status', self::POSTED_STATUSES)
                ->where('l.transaction_date', '>=', now()->subDays(self::LOOKBACK_DAYS)->toDateString())
                ->where('i.created_at', '<', self::LEGACY_BEFORE)
                ->whereNull('i.ingredient_id')
                ->whereIn('vp.ingredient_id', $ids)
                ->where('vp.pack_qty_base', '>', 0)
                ->where('i.quantity', '>', 0)
                ->where('i.line_total', '>', 0)
                ->whereRaw(self::NOT_MONEY_AS_QUANTITY);
            QurbaniFinanceFilter::applyToLedgerQuery($q, 'l', QurbaniFinanceFilter::MODE_EXCLUDE);

            $rows = $q->select('vp.ingredient_id', 'vp.unit as product_unit', 'vp.pack_qty_base',
                    'i.unit', 'i.quantity', 'i.line_total', 'i.product_name',
                    'l.transaction_date', 'v.vendor_name')
                ->get();

            $out = [];
            foreach ($rows as $r) {
                $lineUnit = VendorUnits::canonical($r->unit);
                if ($r->unit !== null && $r->unit !== '' && $lineUnit !== VendorUnits::canonical($r->product_unit)) {
                    continue;
                }
                $out[] = [
                    'ingredient_id' => (int) $r->ingredient_id,
                    'date'          => substr((string) $r->transaction_date, 0, 10),
                    'qty'           => round((float) $r->quantity * (float) $r->pack_qty_base, 3),
                    'cost'          => (float) $r->line_total,
                    'vendor'        => $r->vendor_name,
                    'product'       => $r->product_name,
                    'source'        => 'older_bill',
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('Ingredient prices: older bill lines failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Meat: newest Meat Order line per storage product, Rs/kg → Rs/g.
     *
     * @return array<int,array> ingredient id => days (same shape as addToDay builds)
     */
    private function meatOrderDays($meatIngredients): array
    {
        if ($meatIngredients->isEmpty()) {
            return [];
        }
        $byProduct = [];
        foreach ($meatIngredients as $ing) {
            $byProduct[(int) $ing->storage_product_id][] = (int) $ing->id;
        }

        $out = [];
        try {
            $rows = DB::table('t_crm_prod_order as o')
                ->join('t_crm_prod_order_line_item as li', 'li.order_id', '=', 'o.id')
                ->leftJoin('t_crm_prod_product as p', 'p.id', '=', 'li.product_id')
                ->where('o.external_source', self::MEAT_SOURCE)
                ->whereNotIn('o.order_status', ['cancelled'])
                ->whereIn('li.product_id', array_keys($byProduct))
                ->where('o.order_date', '>=', now()->subDays(self::LOOKBACK_DAYS)->toDateString())
                ->where('li.quantity', '>', 0)
                ->where('li.line_total', '>', 0)
                ->select('li.product_id', 'li.quantity', 'li.line_total', 'o.order_date', 'p.title')
                ->get();

            foreach ($rows as $r) {
                foreach ($byProduct[(int) $r->product_id] ?? [] as $iid) {
                    $this->addToDay($out, [
                        'ingredient_id' => $iid,
                        'date'          => substr((string) $r->order_date, 0, 10),
                        'qty'           => (float) $r->quantity * 1000,   // kg → g
                        'cost'          => (float) $r->line_total,
                        'vendor'        => 'Meat order',
                        'product'       => $r->title,
                        'source'        => 'meat_order',
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Ingredient prices: meat orders failed', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    /** Several lines on one day (two bills, two pack sizes) become one weighted price. */
    private function addToDay(array &$byDay, array $row): void
    {
        $iid = $row['ingredient_id'];
        $d   = $row['date'];
        $byDay[$iid][$d] ??= ['qty' => 0.0, 'cost' => 0.0, 'vendor' => null, 'product' => null,
                              'source' => $row['source'], 'lines' => 0];
        $day = &$byDay[$iid][$d];
        $day['qty']  += $row['qty'];
        $day['cost'] += $row['cost'];
        $day['lines']++;
        $day['vendor']  ??= $row['vendor'];
        $day['product'] ??= $row['product'];
        // A stamped bill outranks an older one on the same day.
        if ($row['source'] === 'bill') {
            $day['source'] = 'bill';
        }
    }

    private function shapePrice(IngredientModel $ing, array $days): array
    {
        krsort($days);
        $dates = array_keys($days);
        $now   = $days[$dates[0]];
        $prev  = isset($dates[1]) ? $days[$dates[1]] : null;

        $price = $now['qty'] > 0 ? $now['cost'] / $now['qty'] : 0.0;
        $prevPrice = ($prev && $prev['qty'] > 0) ? $prev['cost'] / $prev['qty'] : null;

        $age  = Carbon::parse($dates[0])->startOfDay()->diffInDays(now()->startOfDay());
        $jump = $prevPrice && $prevPrice > 0 && $price > 0
            && ($price / $prevPrice >= self::JUMP_FACTOR || $prevPrice / $price >= self::JUMP_FACTOR);

        return [
            'ingredient_id'  => (int) $ing->id,
            'base_unit'      => $ing->base_unit,
            'price_per_base' => round($price, 6),
            'price_text'     => self::priceText($ing->base_unit, $price),
            'bought_on'      => $dates[0],
            'days_old'       => (int) $age,
            'vendor_name'    => $now['vendor'],
            'product_name'   => $now['product'],
            'source'         => $now['source'],
            'stale'          => $age > self::STALE_DAYS,
            'jump'           => (bool) $jump,
            'previous'       => $prevPrice !== null ? [
                'price_per_base' => round($prevPrice, 6),
                'price_text'     => self::priceText($ing->base_unit, $prevPrice),
                'bought_on'      => $dates[1],
            ] : null,
        ];
    }

    /** Rs per gram is unreadable; quote the unit a person buys in. */
    public static function priceText(string $baseUnit, float $perBase): string
    {
        if ($perBase <= 0) {
            return '—';
        }
        [$mult, $word] = match ($baseUnit) {
            IngredientModel::UNIT_G  => [1000, 'a kg'],
            IngredientModel::UNIT_ML => [1000, 'a litre'],
            default                  => [1, 'each'],
        };
        $v = $perBase * $mult;
        $txt = $v >= 100 ? number_format($v) : rtrim(rtrim(number_format($v, 2), '0'), '.');
        return 'Rs ' . $txt . ' ' . $word;
    }

    private function whyUnpriced(string $state): string
    {
        return match ($state) {
            'not_linked'    => 'Not a vendor product yet — add it under the vendor you buy it from, with this exact name.',
            'no_meat_price' => 'No meat order price found for it yet.',
            default         => 'Linked to a vendor product, but no bill has it yet.',
        };
    }

    private function summary(int $priced, array $unpriced, ?float $perPack, ?float $share, ?float $sellingPrice): string
    {
        if ($priced === 0) {
            return 'No line of this recipe has a price yet, so it cannot be costed.';
        }
        $s = 'Rs ' . number_format((float) $perPack, 2) . ' a pack in ingredients';
        if ($share !== null) {
            $s .= ' — ' . $share . '% of the Rs ' . number_format((float) $sellingPrice) . ' price';
        }
        $s .= '.';
        if ($unpriced) {
            $s .= ' Not counted yet (no price): ' . implode(', ', $unpriced) . ', so the real cost is higher.';
        }
        return $s;
    }
}
