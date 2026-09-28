<?php

namespace App\Services\Khaas;

use App\Models\FIN\VendorProductModel;
use App\Models\Khaas\IngredientModel;
use App\Models\Khaas\RecipeLineModel;
use App\Services\FIN\VendorUnits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ⭐⭐ ONE UNIT PER INGREDIENT, CHANGED IN ONE PLACE, EVERYWHERE AT ONCE (owner, Sep-26).
 *
 * "If I change it in vendor from pieces to kg, it should tell the user that this will
 *  change it in the recipe as well."
 *
 * The ingredient owns the unit. A vendor product and a recipe line only ever speak in
 * it. So switching Chicken Cubes from pieces to grams is ONE operation that:
 *   • changes the ingredient,
 *   • re-types every recipe line that uses it (a person gives the new amount — "2
 *     pieces" has no gram value without knowing what a cube weighs),
 *   • re-sizes every vendor product tagged to it (automatic when the product's own
 *     unit already speaks the new kind — a kg product against grams is 1000 — asked
 *     otherwise), and
 *   • is REFUSED when real history would silently change meaning: tagged purchase
 *     lines, recorded consumption, or opening counts. Those numbers were written in
 *     the old unit and nobody is standing there to re-type them.
 *
 * impact() is what the confirm dialog shows; apply() does it in one transaction.
 */
class IngredientUnitChangeService
{
    /**
     * @return array{ingredient: array, from: string, to: string, can_change: bool,
     *               locked_reasons: string[], recipe_lines: array, products: array}
     */
    public function impact(IngredientModel $ingredient, string $toBase, array $productUnitOverrides = []): array
    {
        $from = $ingredient->base_unit;

        $locked = $this->lockReasons($ingredient);

        $lines = RecipeLineModel::query()
            ->join('t_crm_khaas_recipe as r', 'r.id', '=', 't_crm_khaas_recipe_line.recipe_id')
            ->leftJoin('t_crm_prod_product as p', 'p.id', '=', 'r.product_id')
            ->where('t_crm_khaas_recipe_line.ingredient_id', $ingredient->id)
            ->orderBy('p.title')->orderBy('r.version')
            ->get([
                't_crm_khaas_recipe_line.id', 't_crm_khaas_recipe_line.qty_per_basis',
                'r.id as recipe_id', 'r.version', 'r.is_current', 'r.basis_packets', 'p.title as product_name',
            ])
            ->map(fn ($l) => [
                'id'           => (int) $l->id,
                'recipe_id'    => (int) $l->recipe_id,
                'product_name' => $l->product_name ?: 'Recipe #' . $l->recipe_id,
                'version'      => (int) $l->version,
                'is_current'   => (bool) $l->is_current,
                'basis_packets' => (int) ($l->basis_packets ?? 0),
                'qty'          => (float) $l->qty_per_basis,
                'unit'         => $from,
            ])->values()->all();

        $products = VendorProductModel::query()
            ->leftJoin('t_fin_vendors as v', 'v.id', '=', 't_fin_vendor_products.vendor_id')
            ->where('t_fin_vendor_products.ingredient_id', $ingredient->id)
            ->get(['t_fin_vendor_products.id', 't_fin_vendor_products.product_name', 't_fin_vendor_products.unit',
                   't_fin_vendor_products.pack_qty_base', 't_fin_vendor_products.is_active', 'v.vendor_name'])
            ->map(function ($p) use ($toBase, $productUnitOverrides) {
                $unit   = $productUnitOverrides[(int) $p->id] ?? $p->unit;
                $factor = VendorUnits::factorTo($unit, $toBase);
                return [
                    'id'            => (int) $p->id,
                    'vendor_name'   => $p->vendor_name,
                    'product_name'  => $p->product_name,
                    'unit'          => VendorUnits::canonical($unit) ?? $unit,
                    'is_active'     => (bool) $p->is_active,
                    // A kg product against grams needs nothing typed; a piece product
                    // against grams needs "how many grams in one piece?".
                    'new_pack_qty_base' => $factor ?: null,
                    'needs_size'    => !$factor,
                ];
            })->values()->all();

        return [
            'ingredient'     => ['id' => (int) $ingredient->id, 'name' => $ingredient->name],
            'from'           => $from,
            'to'             => $toBase,
            'from_word'      => VendorUnits::kindWord($from),
            'to_word'        => VendorUnits::kindWord($toBase),
            'to_base_word'   => VendorUnits::baseWord($toBase),
            'can_change'     => empty($locked) && $from !== $toBase,
            'locked_reasons' => $locked,
            'recipe_lines'   => $lines,
            'products'       => $products,
        ];
    }

    /**
     * Why this ingredient's unit may NOT change. Empty = free to change (with re-typing).
     * @return string[]
     */
    public function lockReasons(IngredientModel $ingredient): array
    {
        $reasons = [];
        try {
            $n = (int) DB::table('t_fin_vendor_purchase_items')->where('ingredient_id', $ingredient->id)->count();
            if ($n) {
                $reasons[] = "{$n} recorded purchase line" . ($n === 1 ? '' : 's') . " already count it in "
                    . VendorUnits::baseWord($ingredient->base_unit);
            }
        } catch (\Throwable $e) {
        }
        try {
            $n = (int) DB::table('t_crm_khaas_batch_consumption')->where('ingredient_id', $ingredient->id)->count();
            if ($n) {
                $reasons[] = "{$n} batch" . ($n === 1 ? '' : 'es') . " already used it";
            }
        } catch (\Throwable $e) {
        }
        try {
            $n = (int) DB::table('t_crm_khaas_ingredient_opening')->where('ingredient_id', $ingredient->id)->count();
            if ($n) {
                $reasons[] = "{$n} stock count" . ($n === 1 ? '' : 's') . " already recorded";
            }
        } catch (\Throwable $e) {
        }
        return $reasons;
    }

    /**
     * Do it — all or nothing.
     *
     * @param array<int,float> $recipeQty     recipe_line_id => new qty, in the NEW base unit
     * @param array<int,float> $productSizes  vendor_product_id => new pack_qty_base (only where asked)
     * @param array<int,string> $productUnits vendor_product_id => new unit (the product that started it)
     */
    public function apply(IngredientModel $ingredient, string $toBase, array $recipeQty, array $productSizes,
                          array $productUnits, int $userId): array
    {
        if (!in_array($toBase, IngredientModel::BASE_UNITS, true)) {
            throw new \InvalidArgumentException('Choose weight, volume or pieces.');
        }

        return DB::transaction(function () use ($ingredient, $toBase, $recipeQty, $productSizes, $productUnits, $userId) {
            $ingredient = IngredientModel::whereKey($ingredient->id)->lockForUpdate()->firstOrFail();
            $impact = $this->impact($ingredient, $toBase, $productUnits);

            if ($ingredient->base_unit === $toBase) {
                throw new \InvalidArgumentException("{$ingredient->name} is already counted in " . VendorUnits::kindWord($toBase) . '.');
            }
            if (!$impact['can_change']) {
                throw new \InvalidArgumentException("{$ingredient->name} cannot change its unit: "
                    . implode('; ', $impact['locked_reasons']) . '. Add a new product instead.');
            }

            // Every recipe line needs its new amount — nothing is converted by guessing.
            foreach ($impact['recipe_lines'] as $l) {
                $q = (float) ($recipeQty[$l['id']] ?? 0);
                if ($q <= 0) {
                    throw new \InvalidArgumentException("Type the new amount for {$l['product_name']} v{$l['version']}, in "
                        . VendorUnits::baseWord($toBase) . '.');
                }
                if ($toBase === 'pcs' && floor($q) != $q) {
                    throw new \InvalidArgumentException("{$l['product_name']} v{$l['version']}: pieces are whole numbers.");
                }
            }
            foreach ($impact['products'] as $p) {
                if ($p['needs_size'] && (float) ($productSizes[$p['id']] ?? 0) <= 0) {
                    throw new \InvalidArgumentException("How many " . VendorUnits::baseWord($toBase) . " are in one "
                        . VendorUnits::word($p['unit']) . " of {$p['product_name']} ({$p['vendor_name']})?");
                }
            }

            foreach ($impact['recipe_lines'] as $l) {
                RecipeLineModel::whereKey($l['id'])->update(['qty_per_basis' => round((float) $recipeQty[$l['id']], 3)]);
            }
            foreach ($impact['products'] as $p) {
                $set = ['pack_qty_base' => $p['needs_size']
                    ? round((float) $productSizes[$p['id']], 3)
                    : $p['new_pack_qty_base']];
                if (isset($productUnits[$p['id']])) {
                    $set['unit'] = VendorUnits::canonical($productUnits[$p['id']]) ?? $productUnits[$p['id']];
                }
                VendorProductModel::whereKey($p['id'])->update($set);
            }

            $display = IngredientModel::DISPLAY_UNITS[$toBase];
            $ingredient->base_unit    = $toBase;
            $ingredient->display_unit = $display[count($display) - 1];
            $ingredient->save();

            Log::info('Ingredient unit changed everywhere', [
                'ingredient' => $ingredient->id, 'name' => $ingredient->name,
                'from' => $impact['from'], 'to' => $toBase, 'by' => $userId,
                'recipe_lines' => count($impact['recipe_lines']), 'products' => count($impact['products']),
            ]);

            return [
                'ingredient'   => $ingredient->shape(),
                'recipe_lines' => count($impact['recipe_lines']),
                'products'     => count($impact['products']),
            ];
        });
    }
}
