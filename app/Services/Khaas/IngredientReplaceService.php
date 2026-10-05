<?php

namespace App\Services\Khaas;

use App\Models\FIN\VendorProductModel;
use App\Models\Khaas\IngredientModel;
use App\Models\Khaas\RecipeModel;
use App\Services\FIN\VendorUnits;
use Illuminate\Support\Facades\DB;

/**
 * 🔁 "Replace with another ingredient" (owner, Sep-30-2026).
 *
 * The one door for two jobs that the unit lock and the "cannot delete" rule left no way
 * out of:
 *   • a DUPLICATE (Red Chilli vs Red Dhara Mirch) that a current recipe still uses, and
 *   • an ingredient in the WRONG UNIT that already has history (Chicken Powder counted in
 *     pieces, bought and weighed) — replaced by a new one in grams.
 *
 * What it does, all or nothing:
 *   1. every CURRENT recipe that uses the old one gets a NEW VERSION with the line swapped
 *      (the old version stays exactly as it was — batches made with it keep their story);
 *   2. every ACTIVE vendor product tagged to the old one is re-tagged to the new one, with
 *      its size in the new unit (automatic when the product's unit already speaks it);
 *   3. the old one is HIDDEN.
 *
 * What it never does: rewrite a past purchase line, a batch's consumption, a weigh-in or
 * an old recipe version. Those were true in the old unit on their day. So the new
 * ingredient's shelf figure starts fresh — the screen says "enter its starting stock".
 */
class IngredientReplaceService
{
    public function impact(IngredientModel $from, ?IngredientModel $to, ?string $toBase = null): array
    {
        $toBase = $to ? $to->base_unit : ($toBase ?: $from->base_unit);
        $refusals = $this->refusals($from, $to);

        $recipes = [];
        foreach ($this->currentRecipesUsing($from) as $r) {
            $line = $r->lines->firstWhere('ingredient_id', $from->id);
            $hasTo = $to ? $r->lines->contains('ingredient_id', $to->id) : false;
            $recipes[] = [
                'recipe_id'     => (int) $r->id,
                'product_id'    => (int) $r->product_id,
                'product_name'  => $r->product->title ?? ('Product #' . $r->product_id),
                'version'       => (int) $r->version,
                'basis_packets' => (int) $r->basis_packets,
                'old_qty'       => (float) $line->qty_per_basis,
                'old_text'      => $from->phrase((float) $line->qty_per_basis),
                // Same unit → the amount carries over; a different unit must be typed.
                'new_qty'       => $from->base_unit === $toBase ? (float) $line->qty_per_basis : null,
                'needs_qty'     => $from->base_unit !== $toBase,
                'already_has_new' => $hasTo,
            ];
            if ($hasTo) {
                $refusals[] = ($r->product->title ?? 'A product') . "'s recipe already has " . $to->name
                    . ' — remove one of the two lines in that recipe first.';
            }
        }

        $products = [];
        foreach ($this->productsTagged($from) as $p) {
            $factor = VendorUnits::factorTo($p->unit, $toBase);
            $keep   = $from->base_unit === $toBase && (float) $p->pack_qty_base > 0 ? (float) $p->pack_qty_base : null;
            $size   = $factor ?: $keep;
            $products[] = [
                'id'           => (int) $p->id,
                'vendor_name'  => $p->vendor_name,
                'product_name' => $p->product_name,
                'unit'         => VendorUnits::canonical($p->unit) ?? $p->unit,
                'new_pack_qty_base' => $size,
                'needs_size'   => !$size,
            ];
        }

        $tracked = DB::table('t_crm_khaas_ingredient_opening')->where('ingredient_id', $from->id)->exists();

        return [
            'from'      => $from->shape(),
            'to'        => $to ? $to->shape() : null,
            'to_base'   => $toBase,
            'to_base_word' => VendorUnits::baseWord($toBase),
            'recipes'   => $recipes,
            'products'  => $products,
            'from_was_tracked' => $tracked,
            'can_replace' => empty($refusals),
            'refusals'  => array_values(array_unique($refusals)),
        ];
    }

    /**
     * @param array<int,float> $recipeQty    recipe_id => amount per batch in the NEW base unit (where asked)
     * @param array<int,float> $productSizes vendor_product_id => size in the NEW base unit (where asked)
     * @throws \InvalidArgumentException with a message meant for a person
     */
    public function apply(IngredientModel $from, IngredientModel $to, array $recipeQty, array $productSizes, int $userId): array
    {
        $impact = $this->impact($from, $to);
        if (!$impact['can_replace']) {
            throw new \InvalidArgumentException(implode(' ', $impact['refusals']));
        }

        foreach ($impact['recipes'] as $r) {
            $q = $r['needs_qty'] ? (float) ($recipeQty[$r['recipe_id']] ?? 0) : (float) $r['new_qty'];
            if ($q <= 0) {
                throw new \InvalidArgumentException("How much {$to->name} does {$r['product_name']}'s batch use, in {$impact['to_base_word']}?");
            }
            if ($to->base_unit === IngredientModel::UNIT_PCS && abs($q - round($q)) > 0.0005) {
                throw new \InvalidArgumentException("{$to->name} is counted in whole pieces.");
            }
        }
        foreach ($impact['products'] as $p) {
            if ($p['needs_size'] && !((float) ($productSizes[$p['id']] ?? 0) > 0)) {
                throw new \InvalidArgumentException("How many {$impact['to_base_word']} of {$to->name} are in one {$p['unit']} of {$p['product_name']}?");
            }
        }

        $recipes = app(RecipeService::class);

        return DB::transaction(function () use ($from, $to, $impact, $recipeQty, $productSizes, $userId, $recipes) {
            $versions = 0;
            foreach ($impact['recipes'] as $r) {
                $recipe = RecipeModel::with('lines')->find($r['recipe_id']);
                $q = $r['needs_qty'] ? (float) $recipeQty[$r['recipe_id']] : (float) $r['new_qty'];
                $lines = [];
                foreach ($recipe->lines->sortBy('sort_order') as $l) {
                    $isOld = (int) $l->ingredient_id === (int) $from->id;
                    $ing   = $isOld ? $to : IngredientModel::find($l->ingredient_id);
                    if (!$ing) {
                        continue;
                    }
                    $lines[] = [
                        'ingredient_id' => (int) $ing->id,
                        'qty'           => $isOld ? $q : (float) $l->qty_per_basis,
                        'unit'          => $ing->base_unit,
                        'is_optional'   => (bool) $l->is_optional,
                    ];
                }
                $recipes->saveRecipe(
                    (int) $recipe->product_id,
                    (int) $recipe->basis_packets,
                    $lines,
                    now()->toDateString(),
                    mb_substr("{$from->name} replaced with {$to->name}", 0, 255),
                    $userId
                );
                $versions++;
            }

            $moved = 0;
            foreach ($impact['products'] as $p) {
                $size = $p['needs_size'] ? (float) $productSizes[$p['id']] : (float) $p['new_pack_qty_base'];
                VendorProductModel::where('id', $p['id'])->update([
                    'ingredient_id' => $to->id,
                    'pack_qty_base' => round($size, 3),
                ]);
                $moved++;
            }

            $from->is_active = 0;
            $from->save();

            $parts = [];
            if ($versions) {
                $parts[] = $versions . ' recipe' . ($versions === 1 ? '' : 's') . ' now use ' . $to->name;
            }
            if ($moved) {
                $parts[] = $moved . ' vendor product' . ($moved === 1 ? '' : 's') . ' now count towards it';
            }
            $parts[] = "{$from->name} is hidden (its history is kept)";
            $message = implode('; ', $parts) . '.';
            if ($impact['from_was_tracked']) {
                $message .= " Enter a starting stock for {$to->name} on the Ingredient stock sheet.";
            }

            return ['recipes' => $versions, 'products' => $moved, 'message' => $message];
        });
    }

    /** @return string[] */
    private function refusals(IngredientModel $from, ?IngredientModel $to): array
    {
        $r = [];
        if ($from->isMeat()) {
            $r[] = "{$from->name} is meat — it is linked to Storage and cannot be replaced here.";
        }
        if ($to) {
            if ((int) $to->id === (int) $from->id) {
                $r[] = 'Pick a different ingredient.';
            }
            if ($to->isMeat()) {
                $r[] = "{$to->name} is meat — pick a non-meat ingredient.";
            }
            if (!$to->is_active) {
                $r[] = "{$to->name} is hidden — unhide it first.";
            }
            if ((int) $to->business_unit_id !== (int) $from->business_unit_id) {
                $r[] = 'Both ingredients must be on the Frozen list.';
            }
        }
        return $r;
    }

    private function currentRecipesUsing(IngredientModel $ing)
    {
        return RecipeModel::with(['lines', 'product'])
            ->where('is_current', 1)
            ->whereHas('lines', fn ($q) => $q->where('ingredient_id', $ing->id))
            ->get();
    }

    private function productsTagged(IngredientModel $ing)
    {
        return VendorProductModel::query()
            ->leftJoin('t_fin_vendors as v', 'v.id', '=', 't_fin_vendor_products.vendor_id')
            ->where('t_fin_vendor_products.ingredient_id', $ing->id)
            ->where('t_fin_vendor_products.is_active', 1)
            ->get(['t_fin_vendor_products.id', 't_fin_vendor_products.product_name', 't_fin_vendor_products.unit',
                   't_fin_vendor_products.pack_qty_base', 'v.vendor_name']);
    }
}
