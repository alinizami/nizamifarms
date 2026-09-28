<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use App\Models\FIN\VendorModel;
use App\Models\FIN\VendorProductModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\FIN\VendorUnits;

class VendorProductController extends Controller
{
    /**
     * ❄ The Frozen business unit. Ingredients belong to it and to nothing else.
     */
    private const FROZEN_BU = 2;

    /**
     * ⚠⚠ DOES THIS VENDOR DEAL IN FROZEN INGREDIENTS AT ALL?
     *
     * The first version asked the wrong question — it only asked whether the ingredient
     * COLUMNS existed, so every by-weight vendor in the company got the Frozen ingredient
     * list. On this database that is **11 meat suppliers** on BU 1: open Jilani Meat or
     * Ghousia Beef, start typing a product name, and the box suggests "Cheese" and
     * "Cooking oil". Ingredients are a Frozen concept and must not appear anywhere else.
     * Caught by the owner, 22-Sep.
     *
     * This gates the SUGGESTIONS (below) and the TAG ITSELF (`ingredientFields()`), so a
     * hand-made API call cannot tag a meat vendor's product either — which would have
     * fed that vendor's purchases into Frozen consumption maths.
     */
    private function dealsInIngredients(?VendorModel $vendor): bool
    {
        if (!$vendor || (int) ($vendor->business_unit_id ?? 0) !== self::FROZEN_BU) {
            return false;
        }

        // Manual deploy: the PHP can land before the SQL. Writing or reading a column
        // that is not there yet would break catalogue management for every vendor.
        try {
            return \Illuminate\Support\Facades\Schema::hasColumn('t_fin_vendor_products', 'ingredient_id');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Show vendor products management page
     */
    public function index($vendorId)
    {
        $vendor = VendorModel::findOrFail($vendorId);
        $products = VendorProductModel::forVendor($vendorId)
                                      ->orderBy('product_name')
                                      ->get();

        // Level-1 categories, read from the SALES catalogue so a purchase
        // can never be filed under a category that sales doesn't use.
        $categories = app(\App\Services\CategorySalesPurchaseService::class)->categoryVocabulary();

        // ❄ The Frozen ingredient list, for the "what is this, for Frozen?" field and for
        // the product-name suggestions. ⭐ The names here are the STANDARD: a product typed
        // with the same name as an ingredient is what makes purchasing and the recipe meet.
        // Fails soft to an empty list on a database the migration has not reached yet.
        // ⚠⚠ FROZEN VENDORS ONLY. An empty list here switches off the datalist, the tag
        //    field on both forms, the Ingredient column and the INGREDIENTS payload —
        //    every one of them already renders behind `@if(!empty($ingredients))`.
        $ingredients = [];
        if ($this->dealsInIngredients($vendor)) {
            try {
                $recipeCounts = \App\Models\Khaas\RecipeLineModel::query()->groupBy('ingredient_id')
                    ->pluck(\Illuminate\Support\Facades\DB::raw('COUNT(*)'), 'ingredient_id')->all();
                $ingredients = \App\Models\Khaas\IngredientModel::where('business_unit_id', self::FROZEN_BU)
                    ->where('is_active', 1)->whereNull('storage_product_id')
                    ->orderBy('name')->get()
                    ->map(fn ($i) => $i->shape() + ['recipe_count' => (int) ($recipeCounts[$i->id] ?? 0)])->values()->all();
            } catch (\Throwable $e) {
                $ingredients = [];
            }
        }

        // ⭐ Sep-26: the ONE unit list, and which products are locked by purchase history —
        //   the same facts products/list gives the phone.
        $unitCatalogue  = VendorUnits::catalogue();
        $purchaseCounts = $this->purchaseCounts($products->pluck('id')->all());

        return view('fin.vendor.products', compact('vendor', 'products', 'categories', 'ingredients', 'unitCatalogue', 'purchaseCounts'));
    }

    /**
     * Get products list as JSON (for AJAX)
     */
    public function list(Request $request, $vendorId)
    {
        $vendor = VendorModel::find($vendorId);

        // ⭐ ?all=1 — the phone's Products screen shows retired products too, so one can be
        //   switched back on (and an old purchase of a retired product can still be edited).
        //   Absent = active only, exactly what every existing caller has always received.
        $products = VendorProductModel::forVendor($vendorId)
                                      ->when(!$request->boolean('all'), fn ($q) => $q->active())
                                      ->orderBy('product_name')
                                      ->get();

        // ❄ Carry the ingredient's NAME beside its id so a picker can show "Onions
        // (Piyaaz)" next to the product instead of a number. Additive: every key the
        // phone already reads is untouched, and a product with no tag gets null.
        try {
            $ids = $products->pluck('ingredient_id')->filter()->unique()->all();
            $ings = $ids
                ? \App\Models\Khaas\IngredientModel::whereIn('id', $ids)->get(['id', 'name', 'base_unit'])->keyBy('id')
                : collect();
            $products->each(function ($p) use ($ings) {
                $ing = $p->ingredient_id ? $ings->get($p->ingredient_id) : null;
                $p->setAttribute('ingredient_name', $ing->name ?? null);
                $p->setAttribute('ingredient_base_unit', $ing->base_unit ?? null);
            });
        } catch (\Throwable $e) {
            // Migration not run yet: the attribute simply stays absent.
        }

        // ⭐ What each product's unit means, from the ONE unit engine — the qty label,
        //   whether it takes whole numbers, its kind. Additive keys.
        $bu = (int) ($vendor->business_unit_id ?? 1);
        $purchaseCounts = $this->purchaseCounts($products->pluck('id')->all());
        $products->each(function ($p) use ($bu, $purchaseCounts) {
            foreach (VendorUnits::describe($p->unit, $bu) as $k => $v) {
                $p->setAttribute($k, $v);
            }
            $p->setAttribute('purchase_count', $purchaseCounts[$p->id] ?? 0);
        });

        return response()->json([
            'success' => true,
            'products' => $products,
            // ❄ Does this vendor deal in Frozen ingredients? The phone asks here rather
            //   than guessing from the vendor payload, so the rule lives in ONE place.
            //   False for every BU 1 vendor, which is what keeps "Cheese" and "Cooking
            //   oil" out of the meat suppliers' product forms.
            'supports_ingredients' => $this->dealsInIngredients($vendor),
            // ⭐ The unit catalogue both surfaces render — offered units only.
            'unit_catalogue'       => VendorUnits::catalogue(),
            'whole_numbers_apply'  => $bu === self::FROZEN_BU,
        ]);
    }

    /** product id => number of purchase lines that reference it. */
    private function purchaseCounts(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }
        try {
            return \Illuminate\Support\Facades\DB::table('t_fin_vendor_purchase_items')
                ->whereIn('vendor_product_id', $productIds)
                ->groupBy('vendor_product_id')
                ->pluck(\Illuminate\Support\Facades\DB::raw('COUNT(*)'), 'vendor_product_id')
                ->map(fn ($n) => (int) $n)->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * ⭐ The unit a product may be saved with: one of the engine's codes. A free-text unit
     *   ("800gm", "1") is refused with the list — the SIZE belongs in the pack size.
     *   Refused as JSON outside any try, so it reaches the person as the sentence it is.
     */
    private function unitOrRefuse(Request $request): string
    {
        $raw  = trim((string) $request->input('unit'));
        $code = VendorUnits::canonical($raw);
        if ($code === null) {
            abort(response()->json([
                'success' => false,
                'code'    => 'unit_unknown',
                'message' => "\"{$raw}\" is not a unit. Choose kg, g, piece, dozen, litre, ml, pack or box — "
                    . 'and if it is a pack of a certain size (like 800 g), choose "pack" and give the size.',
                'allowed_units' => array_keys(VendorUnits::UNITS),
            ], 422));
        }
        return $code;
    }

    /**
     * ⚠⚠ A product with purchase history keeps its unit. Editing an old bill re-stamps its
     *    lines from the product AS IT STANDS, so "5 kg" silently becoming "5 pieces" on
     *    the next edit is the failure this prevents. The way out is a new product.
     */
    private function refuseUnitChangeWithHistory(VendorProductModel $product, string $newUnit, Request $request): void
    {
        $oldCode = VendorUnits::canonical($product->unit) ?? strtolower(trim((string) $product->unit));
        $sizeChanged = $request->filled('pack_qty_base') && $product->pack_qty_base !== null
            && abs((float) $request->input('pack_qty_base') - (float) $product->pack_qty_base) > 0.0005;

        if ($oldCode === $newUnit && !$sizeChanged) {
            return;
        }
        $n = $this->purchaseCounts([$product->id])[$product->id] ?? 0;
        if ($n === 0) {
            return;
        }
        abort(response()->json([
            'success' => false,
            'code'    => 'unit_locked',
            'message' => "{$product->product_name} is on {$n} recorded purchase" . ($n === 1 ? '' : 's')
                . " as {$product->unit}" . ($sizeChanged ? ' with its current size' : '') . ', so its '
                . ($sizeChanged ? 'size' : 'unit') . ' cannot change — old bills would silently change meaning. '
                . 'Add it as a new product with the right unit, and switch this one off.',
            'purchase_count' => $n,
        ], 422));
    }

    /**
     * Store a new vendor product
     */
    public function store(Request $request, $vendorId)
    {
        $request->validate([
            'product_name' => 'required|string|max:255',
            'unit' => 'required|string|max:50',
            'rate_per_unit' => 'required|numeric|min:0.01',
            'is_default' => 'nullable|boolean',
            'category_level_1' => 'nullable|string|max:50'
        ]);

        // ⚠ Resolved OUTSIDE the try below on purpose. The helper refuses an unsizeable tag
        //   with a 422 by throwing; inside the try that catch-all turns it into a bare
        //   500 "Error adding product: " and the person never sees the question.
        $unit = $this->unitOrRefuse($request);
        $request->merge(['unit' => $unit]);
        $ingredientFields = $this->ingredientFields($request, null, $vendorId);

        try {
            // If this is being set as default, unset any existing defaults
            if ($request->is_default) {
                VendorProductModel::where('vendor_id', $vendorId)
                                  ->where('is_default', 1)
                                  ->update(['is_default' => 0]);
            }

            $product = VendorProductModel::create([
                'vendor_id' => $vendorId,
                'product_name' => $request->product_name,
                'category_level_1' => $this->cleanCategory($request->category_level_1),
                'unit' => $unit,
                'rate_per_unit' => $request->rate_per_unit,
                'is_active' => 1,
                'is_default' => $request->is_default ? 1 : 0
            ] + $ingredientFields);

            return response()->json([
                'success' => true,
                'message' => 'Product added successfully!',
                'product' => $product
            ]);

        } catch (\Exception $e) {
            Log::error("Error adding vendor product: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error adding product: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a vendor product
     */
    public function update(Request $request, $vendorId, $productId)
    {
        $request->validate([
            'product_name' => 'required|string|max:255',
            'unit' => 'required|string|max:50',
            'rate_per_unit' => 'required|numeric|min:0.01',
            'is_default' => 'nullable|boolean',
            'category_level_1' => 'nullable|string|max:50'
        ]);

        // ⚠ Both resolved OUTSIDE the try: a missing product becomes Laravel's own 404,
        //   and an unsizeable tag reaches the person as the 422 question it is, instead
        //   of being swallowed into "Error updating product: ".
        $product = VendorProductModel::where('vendor_id', $vendorId)->findOrFail($productId);
        $unit = $this->unitOrRefuse($request);
        $request->merge(['unit' => $unit]);
        $this->refuseUnitChangeWithHistory($product, $unit, $request);
        $ingredientFields = $this->ingredientFields($request, $product, $vendorId);

        try {
            // If this is being set as default, unset any existing defaults
            if ($request->is_default && !$product->is_default) {
                VendorProductModel::where('vendor_id', $vendorId)
                                  ->where('id', '!=', $productId)
                                  ->where('is_default', 1)
                                  ->update(['is_default' => 0]);
            }

            $product->update([
                'product_name' => $request->product_name,
                // ⚠⚠ ABSENT = KEEP. This used to always write the field, so any caller that
                //    did not send the category (the phone's edit) wiped it to NULL and the
                //    product fell out of the Category Report. Sent-but-blank still clears.
                'category_level_1' => $request->has('category_level_1')
                    ? $this->cleanCategory($request->category_level_1)
                    : $product->category_level_1,
                'unit' => $unit,
                'rate_per_unit' => $request->rate_per_unit,
                // ⚠ Same rule for the default star: an edit that does not mention it keeps it.
                'is_default' => $request->has('is_default') ? ($request->is_default ? 1 : 0) : $product->is_default,
            ] + $ingredientFields);

            return response()->json([
                'success' => true,
                'message' => 'Product updated successfully!',
                'product' => $product
            ]);

        } catch (\Exception $e) {
            Log::error("Error updating vendor product: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error updating product: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ❄ Sep-2026 — the ingredient tag on a catalogue product.
     *
     * "One unit of this product is how many base units of that ingredient?" A kg of
     * onions is 1000 g; a 1 L canola pack is 1000 ml; one egg is 1 pc. When the caller
     * does not say, the unit answers for the obvious cases so nobody has to type 1000
     * for every vegetable — a bare "kg" against a grams ingredient can only mean 1000.
     *
     * An untagged product returns nulls and behaves exactly as it always has.
     * On an edit, an absent ingredient_id LEAVES the existing tag alone rather than
     * wiping it: ConvertEmptyStringsToNull makes a blank field present-but-null, and
     * the old mobile form does not send these keys at all.
     */
    private function ingredientFields(Request $request, ?VendorProductModel $existing = null, $vendorId = null): array
    {
        // ⚠⚠ A NON-FROZEN VENDOR HAS NO INGREDIENTS, whatever the request says.
        //    `dealsInIngredients()` also covers the manual-deploy case where this PHP
        //    arrives before the SQL: writing a column that is not there yet would kill
        //    catalogue creation for every vendor, not just Frozen ones.
        $vendor = null;
        try {
            $vendor = $vendorId !== null ? VendorModel::find($vendorId) : null;
        } catch (\Throwable $e) {
            $vendor = null;
        }

        if (!$this->dealsInIngredients($vendor)) {
            return [];
        }

        if (!$request->has('ingredient_id')) {
            return $existing
                ? []                                        // edit: keep what is there
                : ['ingredient_id' => null, 'pack_qty_base' => null];
        }

        $ingredientId = (int) $request->input('ingredient_id');

        if (!$ingredientId) {
            // Explicitly cleared — "this is not an ingredient".
            return ['ingredient_id' => null, 'pack_qty_base' => null];
        }

        $ingredient = \App\Models\Khaas\IngredientModel::find($ingredientId);
        if (!$ingredient) {
            return ['ingredient_id' => null, 'pack_qty_base' => null];
        }

        // ⚠ Only a live, Frozen, purchasable ingredient can be a tag. Meat ingredients are
        //   the storage ledger's (the web page never offered them; the API used to accept
        //   them), and a retired one must not quietly come back through a product.
        $sameTag = $existing && (int) $existing->ingredient_id === (int) $ingredient->id;
        if (!$sameTag && ((int) $ingredient->business_unit_id !== self::FROZEN_BU
                || $ingredient->storage_product_id || !$ingredient->is_active)) {
            abort(response()->json([
                'success' => false,
                'code'    => 'ingredient_not_taggable',
                'message' => $ingredient->storage_product_id
                    ? "{$ingredient->name} is meat — the storage ledger already tracks it, so a vendor product cannot count towards it."
                    : "{$ingredient->name} is not an active Frozen ingredient, so it cannot be tagged here.",
            ], 422));
        }

        $unit   = (string) $request->input('unit');
        $factor = VendorUnits::factorTo($unit, $ingredient->base_unit);

        if ($factor === 0.0) {
            // ⭐⭐ ONE UNIT PER INGREDIENT (owner, Sep-26). "kg" against a pieces ingredient
            //    used to be ACCEPTED once somebody typed "how many pieces in one kg" — an
            //    average that drifts with every bag. Refused now, with the two ways out:
            //    use the ingredient's own kind of unit, or change the ingredient's unit
            //    everywhere (recipes and all), which the impact below describes.
            abort(response()->json($this->mismatchPayload($ingredient, $unit, $existing), 422));
        }

        $packQty = $factor === null ? (float) $request->input('pack_qty_base', 0) : $factor;

        if ($factor === null && $packQty > 0 && $ingredient->base_unit === 'pcs' && floor($packQty) != $packQty) {
            abort(response()->json([
                'success' => false,
                'code'    => 'whole_number',
                'message' => "How many whole pieces of {$ingredient->name} are in one " . VendorUnits::word($unit) . '? Pieces are whole numbers.',
            ], 422));
        }

        if ($packQty <= 0) {
            // ⚠⚠ We know what it is but not how much of it. The first version dropped the
            //    tag silently here — Qasim would tag "Tazo cheese 400 g pack" as Cheese,
            //    press Save, and the product would come back untagged with no word why.
            //    A tag the person chose must either stick or be refused OUT LOUD.
            $unitWord = strtolower(trim((string) $request->input('unit'))) ?: 'unit';
            abort(response()->json([
                'success' => false,
                'code'    => 'pack_size_needed',
                'message' => "How much {$ingredient->name} is in one {$unitWord}? "
                    . "A {$unitWord} could be any size, so type the amount (in "
                    . ($ingredient->base_unit === 'pcs' ? 'pieces' : ($ingredient->base_unit === 'ml' ? 'ml' : 'grams'))
                    . ") before saving.",
            ], 422));
        }

        return ['ingredient_id' => $ingredientId, 'pack_qty_base' => round($packQty, 3)];
    }

    /**
     * The 422 a unit mismatch answers with. Both surfaces draw the same panel from it:
     * a "Use pieces" button, and — when nothing forbids it — "Change Chicken Cubes to
     * weight everywhere", listing every recipe line and product that moves with it.
     * ⚠ Old APKs show only `message`, so the sentence carries the fix on its own.
     */
    private function mismatchPayload(\App\Models\Khaas\IngredientModel $ingredient, string $unit, ?VendorProductModel $existing): array
    {
        $base      = $ingredient->base_unit;
        $unitKind  = VendorUnits::kindOf($unit);
        $suggested = VendorUnits::suggestedFor($base);
        $inRecipes = \App\Models\Khaas\RecipeLineModel::where('ingredient_id', $ingredient->id)->exists();

        $impact = null;
        if ($unitKind) {
            $overrides = $existing ? [(int) $existing->id => $unit] : [];
            try {
                $impact = app(\App\Services\Khaas\IngredientUnitChangeService::class)->impact($ingredient, $unitKind, $overrides);
            } catch (\Throwable $e) {
                $impact = null;
            }
        }

        $user = auth()->user();

        return [
            'success'        => false,
            'code'           => 'unit_mismatch',
            'message'        => "{$ingredient->name} is counted in " . strtoupper(VendorUnits::kindWord($base))
                . ($inRecipes ? ' in your recipes' : '') . ', but this product is set to ' . VendorUnits::word($unit)
                . '. Choose ' . VendorUnits::word($suggested) . ' (or pack / box with the number of '
                . VendorUnits::baseWord($base) . ' in one)'
                . ($unitKind ? ", or change {$ingredient->name} to " . VendorUnits::kindWord($unitKind) . ' everywhere.' : '.'),
            'ingredient'     => [
                'id'        => (int) $ingredient->id,
                'name'      => $ingredient->name,
                'base_unit' => $base,
                'kind_word' => VendorUnits::kindWord($base),
                'in_recipes'=> $inRecipes,
            ],
            'unit'           => VendorUnits::canonical($unit) ?? $unit,
            'unit_kind'      => $unitKind,
            'suggested_unit' => $suggested,
            'allowed_units'  => VendorUnits::allowedFor($base),
            // The second way out, described in full so the confirm needs no second request.
            'change'         => $impact ? $impact + [
                'can_manage' => $user ? $user->hasMobilePermission('manage_khaas_recipes') : false,
            ] : null,
        ];
    }

    /**
     * Toggle product active status
     */
    public function toggleStatus($vendorId, $productId)
    {
        try {
            $product = VendorProductModel::where('vendor_id', $vendorId)
                                         ->findOrFail($productId);

            $product->is_active = !$product->is_active;
            $product->save();

            return response()->json([
                'success' => true,
                'message' => 'Product status updated!',
                'is_active' => $product->is_active
            ]);

        } catch (\Exception $e) {
            Log::error("Error toggling vendor product status: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error updating status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Set a product as default for the vendor
     */
    public function setAsDefault($vendorId, $productId)
    {
        try {
            // First, unset any existing default for this vendor
            VendorProductModel::where('vendor_id', $vendorId)
                              ->where('is_default', 1)
                              ->update(['is_default' => 0]);

            // Set the selected product as default
            $product = VendorProductModel::where('vendor_id', $vendorId)
                                         ->findOrFail($productId);
            
            $product->is_default = 1;
            $product->save();

            return response()->json([
                'success' => true,
                'message' => 'Default product updated successfully!',
                'product' => $product
            ]);

        } catch (\Exception $e) {
            Log::error("Error setting default vendor product: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error setting default product: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Normalise a submitted Level-1 category: blank becomes NULL, and a
     * value outside the sales vocabulary is rejected to NULL rather than
     * stored, so the purchase side can never drift from the sales side by
     * a typo. (Free text here is what would break the whole comparison —
     * purchases are typed as "Veal" but sold as "Beef".)
     */
    private function cleanCategory($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $vocab = app(\App\Services\CategorySalesPurchaseService::class)->categoryVocabulary();

        return in_array($value, $vocab, true) ? $value : null;
    }

    /**
     * Delete a vendor product
     */
    public function destroy($vendorId, $productId)
    {
        try {
            $product = VendorProductModel::where('vendor_id', $vendorId)
                                         ->findOrFail($productId);

            // Check if product has been used in any purchases
            if ($product->purchaseItems()->count() > 0) {
                // Soft delete by deactivating instead
                $product->is_active = 0;
                $product->save();

                return response()->json([
                    'success' => true,
                    'message' => 'Product deactivated (has purchase history)',
                    'deactivated' => true
                ]);
            }

            // Safe to delete if no purchase history
            $product->delete();

            return response()->json([
                'success' => true,
                'message' => 'Product deleted successfully!'
            ]);

        } catch (\Exception $e) {
            Log::error("Error deleting vendor product: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error deleting product: ' . $e->getMessage()
            ], 500);
        }
    }
}

