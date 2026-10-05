<?php

namespace App\Http\Controllers\Khaas;

use App\Http\Controllers\Controller;
use App\Models\Khaas\IngredientModel;
use App\Models\Khaas\IngredientOpeningModel;
use App\Services\Khaas\ConsumptionService;
use App\Services\Khaas\FrozenCostingService;
use App\Services\Khaas\FrozenMonthService;
use App\Services\Khaas\IngredientPriceService;
use App\Services\Khaas\IngredientReplaceService;
use App\Services\Khaas\IngredientStockService;
use App\Services\Khaas\RecipeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ingredients, recipes and what they cost — one controller, both surfaces.
 *
 * Web routes live under /khaas/*, the phone's under /api/warehouse/*, and both land
 * here, so the two can never answer differently.
 *
 * ⚠ /api/warehouse/* sits in one auth:sanctum group with no permission middleware — a
 *   known, owner-accepted gap. Rather than wait for a group-level gate, every WRITE
 *   below checks its own permission in the method, the same way approveTransfer does.
 */
class RecipeController extends Controller
{
    private RecipeService $recipes;
    private FrozenCostingService $costing;
    private ConsumptionService $consumption;

    public function __construct()
    {
        $this->recipes     = new RecipeService();
        $this->costing     = new FrozenCostingService();
        $this->consumption = new ConsumptionService();
    }

    // =================================================================
    //  GATES
    // =================================================================

    private function canAccess(): bool
    {
        $user = auth()->user();
        return $user ? $user->hasMobilePermission('access_khaas_mode') : false;
    }

    private function canManage(): bool
    {
        $user = auth()->user();
        return $user ? $user->hasMobilePermission('manage_khaas_recipes') : false;
    }

    /**
     * ❄ Sep-30: may SET the stock figure (a start, or an adjustment). Granted at first to the
     * same four roles as recipes; the owner "locks manual adjustments" by unticking it on the
     * khaas role. Without it a weigh-in is still recorded — as a check that moves nothing.
     */
    private function canAdjustStock(): bool
    {
        $user = auth()->user();
        return $user ? $user->hasMobilePermission('adjust_khaas_stock') : false;
    }

    private function canSeeCost(): bool
    {
        $user = auth()->user();
        return $user ? $user->hasMobilePermission('view_khaas_costing') : false;
    }

    /** A refusal that names the way out, never a bare "access denied". */
    private function deny(string $what)
    {
        return response()->json([
            'success' => false,
            'message' => $what,
        ], 403);
    }

    /**
     * ⚠⚠ THE BUSINESS UNIT IS NOT THE CLIENT'S TO CHOOSE.
     *
     * This used to take whatever `business_unit_id` arrived. That is harmless on a read
     * and very much not on `reapply`, which DELETES a month of consumption for the unit
     * it is handed — anyone with `manage_khaas_recipes` could have wiped another unit's
     * rows by posting `business_unit_id=1`. Every caller of this feature is Frozen, so
     * the value is pinned and a mismatched request is refused rather than quietly
     * answered about the wrong unit.
     */
    private function businessUnitId(Request $request): int
    {
        $asked = (int) $request->input('business_unit_id', 0);

        if ($asked && $asked !== RecipeService::FROZEN_BU) {
            abort(response()->json([
                'success' => false,
                'message' => 'Recipes and ingredient costing are a Frozen feature; that is not a Frozen business unit.',
            ], 422));
        }

        return RecipeService::FROZEN_BU;
    }

    // =================================================================
    //  INGREDIENTS
    // =================================================================

    public function ingredients(Request $request)
    {
        if (!$this->canAccess()) {
            return $this->deny('Frozen mode access is needed to see ingredients.');
        }

        $bu = $this->businessUnitId($request);

        return response()->json([
            'success'     => true,
            'ingredients' => $this->recipes->ingredients($bu, (bool) $request->boolean('include_inactive')),
            // Where a recipe names something purchasing has never seen — the list both
            // surfaces show so Qasim knows what to add, and under what exact name.
            'gaps'        => $this->recipes->gaps($bu),
            'kinds'       => IngredientModel::KIND_LABELS,
            'units'       => IngredientModel::DISPLAY_UNITS,
            'can_manage'  => $this->canManage(),
            // 🔗 Sep-27: where the Planning page's "Link at…" picker can send someone.
            'link_vendors' => $this->linkVendors($bu),
        ]);
    }

    public function saveIngredient(Request $request)
    {
        if (!$this->canManage()) {
            return $this->deny('Only Taimur, Shabib or Qasim can change the ingredient list. Ask one of them to add it.');
        }

        try {
            $ingredient = $this->recipes->saveIngredient(
                $request->all(),
                auth()->id(),
                $this->businessUnitId($request)
            );

            return response()->json([
                'success'    => true,
                'message'    => 'Saved.',
                'ingredient' => $ingredient->shape(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            \Log::error('Frozen recipes: ingredient save failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not save that ingredient.'], 500);
        }
    }

    /**
     * ⭐ What changing this ingredient's unit would touch — the confirm dialog's content.
     *   ?to=g|ml|pcs  [&product_id=82&unit=kg — the product whose edit started it]
     */
    public function unitImpact(Request $request, $id)
    {
        if (!$this->canAccess()) {
            return $this->deny('Frozen mode access is needed to see ingredients.');
        }
        $ingredient = IngredientModel::find($id);
        if (!$ingredient) {
            return response()->json(['success' => false, 'message' => 'That ingredient no longer exists.'], 404);
        }
        $to = (string) $request->query('to');
        if (!in_array($to, IngredientModel::BASE_UNITS, true)) {
            return response()->json(['success' => false, 'message' => 'Choose weight (g), volume (ml) or pieces (pcs).'], 422);
        }
        $overrides = $request->filled('product_id') && $request->filled('unit')
            ? [(int) $request->query('product_id') => (string) $request->query('unit')] : [];

        $impact = app(\App\Services\Khaas\IngredientUnitChangeService::class)->impact($ingredient, $to, $overrides);

        return response()->json(['success' => true, 'can_manage' => $this->canManage()] + $impact);
    }

    /**
     * ⭐ Change an ingredient's unit EVERYWHERE, in one transaction: the ingredient, every
     *   recipe line (new amounts typed by the person) and every tagged vendor product.
     *   Body: to, recipe_qty{line_id: qty}, product_sizes{product_id: pack_qty_base},
     *         product_units{product_id: unit}
     */
    public function changeUnit(Request $request, $id)
    {
        if (!$this->canManage()) {
            return $this->deny('Only Taimur, Shabib or Qasim can change an ingredient\'s unit. Ask one of them.');
        }
        $ingredient = IngredientModel::find($id);
        if (!$ingredient) {
            return response()->json(['success' => false, 'message' => 'That ingredient no longer exists.'], 404);
        }

        $num = fn ($arr) => collect(is_array($arr) ? $arr : [])
            ->mapWithKeys(fn ($v, $k) => [(int) $k => (float) $v])->all();

        try {
            $result = app(\App\Services\Khaas\IngredientUnitChangeService::class)->apply(
                $ingredient,
                (string) $request->input('to'),
                $num($request->input('recipe_qty')),
                $num($request->input('product_sizes')),
                collect((array) $request->input('product_units', []))->mapWithKeys(fn ($v, $k) => [(int) $k => (string) $v])->all(),
                (int) auth()->id()
            );

            return response()->json([
                'success' => true,
                'message' => "{$result['ingredient']['name']} is now counted in "
                    . \App\Services\FIN\VendorUnits::kindWord($result['ingredient']['base_unit'])
                    . " everywhere — {$result['recipe_lines']} recipe line(s) and {$result['products']} product(s) updated.",
            ] + $result);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            \Log::error('Frozen recipes: unit change failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not change the unit. Nothing was changed.'], 500);
        }
    }

    public function deactivateIngredient(Request $request, $id)
    {
        if (!$this->canManage()) {
            return $this->deny('Only Taimur, Shabib or Qasim can change the ingredient list.');
        }

        $ingredient = IngredientModel::find($id);
        if (!$ingredient) {
            return response()->json(['success' => false, 'message' => 'That ingredient no longer exists.'], 404);
        }

        // Deactivating is always allowed — the history it is attached to is unaffected,
        // and a wrong ingredient nobody can hide is worse than one that stops appearing.
        $ingredient->is_active = 0;
        $ingredient->save();

        $inUse = $this->recipes->ingredientInUse($ingredient);

        return response()->json([
            'success' => true,
            'message' => $inUse
                ? "\"{$ingredient->name}\" is hidden from new recipes. Past purchases and batches keep it."
                : "\"{$ingredient->name}\" is hidden.",
        ]);
    }

    // =================================================================
    //  RECIPES
    // =================================================================

    public function show(Request $request)
    {
        if (!$this->canAccess()) {
            return $this->deny('Frozen mode access is needed to see a recipe.');
        }

        $productId = (int) $request->input('product_id');
        if (!$productId) {
            return response()->json(['success' => false, 'message' => 'Which product?'], 400);
        }

        $bu     = $this->businessUnitId($request);
        $recipe = $this->recipes->recipeFor($productId, $request->input('on_date'));

        return response()->json([
            'success'     => true,
            'recipe'      => $recipe,
            'ingredients' => $this->recipes->ingredients($bu),
            'history'     => $this->recipes->history($productId),
            'can_manage'  => $this->canManage(),
            // 🔗 Sep-27: the vendors a recipe line can be linked through, straight from the
            //   recipe sheet — Frozen vendors whose bills are entered line by line (a by-total
            //   vendor's bills have no lines, so a link there would never price anything).
            'link_vendors' => $this->linkVendors($bu),
            // ❄🧂 Sep-30: what is on the shelf, per ingredient id — beside each recipe line.
            //   Only tracked, non-meat ingredients appear; the rest are simply absent.
            'on_shelf'     => (object) array_filter($this->onShelfFor($bu)),
        ] + $this->pricedRecipe($bu, $recipe));
    }

    /** @return array<int,array{id:int,vendor_name:string}> */
    private function linkVendors(int $bu): array
    {
        try {
            return \App\Models\FIN\VendorModel::where('business_unit_id', $bu)
                ->where('is_active', 1)
                ->where('default_purchase_method', 'by_weight')
                ->orderBy('vendor_name')
                ->get(['id', 'vendor_name'])
                ->map(fn ($v) => ['id' => (int) $v->id, 'vendor_name' => $v->vendor_name])
                ->values()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 💲 The recipe at today's prices + the unit's price book (so the editor can price a
     * line the moment an ingredient is picked). Rupees need view_khaas_costing and are
     * stripped HERE, on the server — the keys stay, nulled, so no client guesses.
     * A pricing failure never takes the recipe down with it.
     */
    private function pricedRecipe(int $bu, array $recipe): array
    {
        $canCost = $this->canSeeCost();
        try {
            $p = (new IngredientPriceService())->forRecipe($bu, $recipe);
        } catch (\Throwable $e) {
            \Log::warning('Frozen recipes: pricing failed', ['error' => $e->getMessage()]);
            return ['cost' => null, 'prices' => (object) [], 'selling_price' => null, 'can_see_cost' => $canCost];
        }
        if (!$canCost) {
            $p['cost'] = $p['cost'] ? IngredientPriceService::stripRupees($p['cost']) : null;
            $p['prices'] = (object) IngredientPriceService::stripPriceBook((array) $p['prices']);
            $p['selling_price'] = null;
        }
        return $p + ['can_see_cost' => $canCost];
    }

    public function save(Request $request)
    {
        if (!$this->canManage()) {
            return $this->deny('Only Taimur, Shabib or Qasim can change a recipe. Ask one of them.');
        }

        $lines = $request->input('lines', []);
        if (!is_array($lines)) {
            $lines = [];
        }

        try {
            $recipe = $this->recipes->saveRecipe(
                (int) $request->input('product_id'),
                (int) $request->input('basis_packets'),
                $lines,
                $request->input('effective_from'),
                $request->input('note'),
                auth()->id()
            );

            $shaped = $this->recipes->recipeFor((int) $recipe->product_id);

            return response()->json([
                'success' => true,
                'message' => "Saved as version {$recipe->version}.",
                'recipe'  => $shaped,
            ] + $this->pricedRecipe($this->businessUnitId($request), $shaped));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            \Log::error('Frozen recipes: save failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not save that recipe.'], 500);
        }
    }

    /** Which products have a recipe — drives the "no recipe" chips on both surfaces. */
    public function coverage(Request $request)
    {
        if (!$this->canAccess()) {
            return $this->deny('Frozen mode access is needed.');
        }

        return response()->json([
            'success'  => true,
            'products' => $this->recipes->coverage($this->businessUnitId($request)),
            'can_manage' => $this->canManage(),
        ]);
    }

    // =================================================================
    //  CONSUMPTION AND COST
    // =================================================================

    /** The ingredient lines behind one stock-in or one batch. */
    public function consumption(Request $request)
    {
        if (!$this->canAccess()) {
            return $this->deny('Frozen mode access is needed.');
        }

        $logId   = (int) $request->input('log_id') ?: null;
        $batchId = (int) $request->input('batch_id') ?: null;

        if (!$logId && !$batchId) {
            return response()->json(['success' => false, 'message' => 'Which stock-in or batch?'], 400);
        }

        return response()->json([
            'success' => true,
            'lines'   => $this->consumption->linesFor($logId, $batchId),
        ]);
    }

    /**
     * One product's estimated cost per pack, for the card chip.
     * Quantities are open to anyone in Frozen mode; the rupees are not.
     */
    public function productCost(Request $request)
    {
        if (!$this->canAccess()) {
            return $this->deny('Frozen mode access is needed.');
        }

        $productId = (int) $request->input('product_id');
        $month     = $request->input('month');
        if (!FrozenMonthService::isValidMonth($month)) {
            $month = now()->format('Y-m');
        }

        $bu   = $this->businessUnitId($request);
        $cost = $this->costing->costPerPackFor($bu, $productId, $month);

        if ($cost && !$this->canSeeCost()) {
            $cost['cost_per_pack'] = null;
        }

        return response()->json([
            'success'       => true,
            'cost'          => $cost,
            'can_see_cost'  => $this->canSeeCost(),
            'recipe'        => $this->recipes->recipeFor($productId),
        ]);
    }

    /**
     * Cost chips for a whole screen in one call, so the Products list does not fire one
     * request per card. Returns a map keyed by product id.
     */
    public function productCosts(Request $request)
    {
        if (!$this->canAccess()) {
            return $this->deny('Frozen mode access is needed.');
        }

        $month = $request->input('month');
        if (!FrozenMonthService::isValidMonth($month)) {
            $month = now()->format('Y-m');
        }

        $bu      = $this->businessUnitId($request);
        $data    = $this->costing->productMonth($bu, $month);
        $canCost = $this->canSeeCost();

        $map = [];
        foreach ($data['rows'] as $row) {
            $map[(string) $row['product_id']] = [
                'made'          => $row['made'],
                'made_plan'     => $row['made_plan'],
                'made_direct'   => $row['made_direct'],
                'cost_per_pack' => $canCost ? $row['cost_per_pack'] : null,
            ];
        }

        // 💲 Sep-27: the recipe at TODAY'S prices, for every product that has one — shown
        //    on the card even before anything is made this month.
        try {
            $recipeCosts = (new IngredientPriceService())->forProducts($bu);
        } catch (\Throwable $e) {
            \Log::warning('Frozen recipes: card pricing failed', ['error' => $e->getMessage()]);
            $recipeCosts = [];
        }
        if (!$canCost) {
            $recipeCosts = IngredientPriceService::stripProductCosts($recipeCosts);
        }

        return response()->json([
            'success'      => true,
            'month'        => $month,
            'costs'        => (object) $map,
            'recipe_costs' => (object) array_combine(array_map('strval', array_keys($recipeCosts)), array_values($recipeCosts)),
            'coverage'     => $this->recipes->coverage($bu),
            'can_see_cost' => $canCost,
        ]);
    }

    /**
     * Restate a month from the recipes as they stand now.
     *
     * The one deliberate way history is rewritten — needed because a product's first
     * recipe is usually typed after some packs were already made.
     */
    public function reapply(Request $request)
    {
        if (!$this->canManage()) {
            return $this->deny('Only Taimur, Shabib or Qasim can re-apply a month.');
        }

        $month = $request->input('month');
        if (!FrozenMonthService::isValidMonth($month)) {
            return response()->json(['success' => false, 'message' => 'Which month?'], 400);
        }

        $result = $this->consumption->reapplyMonth(
            $this->businessUnitId($request),
            $month,
            (int) $request->input('product_id') ?: null
        );

        $message = sprintf(
            'Re-applied %s: %d stock-ins read, %d ingredient lines written.',
            $month, $result['logs'], $result['rows']
        );

        if ($result['skipped'] > 0) {
            $message .= sprintf(' %d had no recipe on their date and were left alone.', $result['skipped']);
        }

        return response()->json(['success' => true, 'message' => $message, 'result' => $result]);
    }

    // =================================================================
    //  OPENING STOCK AND COUNTS
    // =================================================================

    /**
     * ⚠ The older one-ingredient door (the web page's pop-up, before Sep-30). Kept so nothing
     *   that still posts here breaks, but it now goes through the SAME rules as the sheet: the
     *   server picks start / adjust / weigh-in from the person's permission, not the client.
     */
    public function saveOpening(Request $request)
    {
        if (!$this->canManage()) {
            return $this->deny('Only Taimur, Shabib or Qasim can enter stock.');
        }

        $request->merge([
            'entries' => [[
                'ingredient_id' => (int) $request->input('ingredient_id'),
                'qty'           => $request->input('qty'),
                'unit'          => $request->input('unit'),
                'note'          => $request->input('note'),
            ]],
            'date' => $request->input('counted_on') ?: now()->toDateString(),
        ]);

        return $this->saveStock($request);
    }

    // =================================================================
    //  ❄🧂 INGREDIENT STOCK (Sep-30) — start + bought − used = left
    // =================================================================

    /** The whole sheet: every non-meat ingredient, its figure and its last weigh-in. */
    public function stockSheet(Request $request)
    {
        if (!$this->canAccess()) {
            return $this->deny('Frozen mode access is needed.');
        }
        $bu = $this->businessUnitId($request);

        try {
            $sheet = (new IngredientStockService())->sheet($bu, $this->stockPrices($bu));
        } catch (\Throwable $e) {
            \Log::error('Frozen stock: sheet failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not load the stock sheet. Try again in a moment.'], 500);
        }

        return response()->json([
            'success'      => true,
            'can_manage'   => $this->canManage(),
            'can_adjust'   => $this->canAdjustStock(),
            'can_see_cost' => $this->canSeeCost(),
            'units'        => IngredientModel::DISPLAY_UNITS,
        ] + $sheet);
    }

    /** Every weigh-in for one ingredient, newest first, with the gap each one showed. */
    public function stockHistory(Request $request, $id)
    {
        if (!$this->canAccess()) {
            return $this->deny('Frozen mode access is needed.');
        }
        $bu  = $this->businessUnitId($request);
        $ing = IngredientModel::where('id', (int) $id)->where('business_unit_id', $bu)->first();
        if (!$ing) {
            return response()->json(['success' => false, 'message' => 'That ingredient is not on the Frozen list.'], 404);
        }
        $prices = $this->stockPrices($bu);

        return response()->json([
            'success'    => true,
            'ingredient' => $ing->shape(),
            'history'    => (new IngredientStockService())->history($bu, $ing, $prices[(int) $ing->id] ?? null),
        ]);
    }

    /**
     * Save a sheet — all or nothing. {date: today|yesterday, entries: [{ingredient_id, qty, unit, note}]}
     * The KIND is decided on the server from `adjust_khaas_stock`.
     */
    public function saveStock(Request $request)
    {
        if (!$this->canManage()) {
            return $this->deny('Only Taimur, Shabib or Qasim can enter stock.');
        }
        $bu      = $this->businessUnitId($request);
        $entries = $request->input('entries');
        if (!is_array($entries)) {
            return response()->json(['success' => false, 'message' => 'Nothing to save.'], 422);
        }

        $result = (new IngredientStockService())->save(
            $bu, $entries, $request->input('date'), (int) auth()->id(), $this->canAdjustStock()
        );

        return response()->json([
            'success' => $result['ok'],
            'message' => $result['message'],
            'errors'  => (object) $result['errors'],
            'saved'   => $result['saved'],
        ], $result['ok'] ? 200 : 422);
    }

    /** Bring a hidden ingredient back. */
    public function activateIngredient(Request $request, $id)
    {
        if (!$this->canManage()) {
            return $this->deny('Only Taimur, Shabib or Qasim can change the ingredient list.');
        }
        $ing = IngredientModel::where('id', (int) $id)->where('business_unit_id', $this->businessUnitId($request))->first();
        if (!$ing) {
            return response()->json(['success' => false, 'message' => 'That ingredient no longer exists.'], 404);
        }
        $ing->is_active = 1;
        $ing->save();

        return response()->json(['success' => true, 'message' => "\"{$ing->name}\" is back on the list."]);
    }

    /**
     * 🔁 What "replace X with Y" would change. Y is an existing ingredient (?to=ID), or a new
     * one about to be made (?new_unit=g|ml|pcs) — then only the unit matters for the preview.
     */
    public function replaceImpact(Request $request, $id)
    {
        if (!$this->canManage()) {
            return $this->deny('Only Taimur, Shabib or Qasim can change the ingredient list.');
        }
        $bu   = $this->businessUnitId($request);
        $from = IngredientModel::where('id', (int) $id)->where('business_unit_id', $bu)->first();
        if (!$from) {
            return response()->json(['success' => false, 'message' => 'That ingredient no longer exists.'], 404);
        }
        $to = $request->filled('to')
            ? IngredientModel::where('id', (int) $request->input('to'))->where('business_unit_id', $bu)->first()
            : null;
        if ($request->filled('to') && !$to) {
            return response()->json(['success' => false, 'message' => 'That ingredient no longer exists.'], 404);
        }
        $newUnit = in_array($request->input('new_unit'), IngredientModel::BASE_UNITS, true) ? $request->input('new_unit') : null;

        return response()->json(['success' => true]
            + (new IngredientReplaceService())->impact($from, $to, $newUnit));
    }

    /**
     * 🔁 Do it. Either {to: ID} or {new_name, new_unit, new_kind?} for a brand-new ingredient —
     * when the new name is the old one's (Chicken Powder → Chicken Powder, in grams), the old
     * one is renamed "… (old)" first so the list never shows two of the same name.
     */
    public function replaceIngredient(Request $request, $id)
    {
        if (!$this->canManage()) {
            return $this->deny('Only Taimur, Shabib or Qasim can change the ingredient list.');
        }
        $bu   = $this->businessUnitId($request);
        $from = IngredientModel::where('id', (int) $id)->where('business_unit_id', $bu)->first();
        if (!$from) {
            return response()->json(['success' => false, 'message' => 'That ingredient no longer exists.'], 404);
        }

        try {
            $result = DB::transaction(function () use ($request, $bu, $from) {
                if ($request->filled('to')) {
                    $to = IngredientModel::where('id', (int) $request->input('to'))->where('business_unit_id', $bu)->first();
                    if (!$to) {
                        throw new \InvalidArgumentException('That ingredient no longer exists.');
                    }
                } else {
                    $name = trim((string) $request->input('new_name'));
                    $unit = (string) $request->input('new_unit');
                    if ($name === '') {
                        throw new \InvalidArgumentException('Give the new ingredient a name.');
                    }
                    if (!in_array($unit, IngredientModel::BASE_UNITS, true)) {
                        throw new \InvalidArgumentException('Unit must be grams, millilitres or pieces.');
                    }
                    if (mb_strtolower($name) === mb_strtolower($from->name)) {
                        $from->name = mb_substr($from->name . ' (old)', 0, 120);
                        $from->save();
                    }
                    $to = $this->recipes->saveIngredient([
                        'name'      => $name,
                        'base_unit' => $unit,
                        'kind'      => $request->input('new_kind') ?: $from->kind,
                    ], (int) auth()->id(), $bu);
                }

                return (new IngredientReplaceService())->apply(
                    $from->fresh(), $to->fresh(),
                    array_map('floatval', (array) $request->input('recipe_qty', [])),
                    array_map('floatval', (array) $request->input('product_sizes', [])),
                    (int) auth()->id()
                );
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            \Log::error('Frozen recipes: replace failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not replace it. Nothing was changed.'], 500);
        }

        return response()->json(['success' => true, 'message' => $result['message']]);
    }

    /** ingredient id => {qty, text} | null for every active non-meat ingredient. Never throws. */
    private function onShelfFor(int $bu): array
    {
        try {
            $ids = IngredientModel::where('business_unit_id', $bu)->where('is_active', 1)
                ->whereNull('storage_product_id')->pluck('id')->all();
            return (new IngredientStockService())->onShelfShaped($bu, $ids);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Latest bill prices, only for someone allowed to see rupees. @return array<int,array>|null */
    private function stockPrices(int $bu): ?array
    {
        if (!$this->canSeeCost()) {
            return null;
        }
        try {
            return (new IngredientPriceService())->latest($bu);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** The whole ingredient panel for a month, for the phone and the page alike. */
    public function ingredientMonth(Request $request)
    {
        if (!$this->canAccess()) {
            return $this->deny('Frozen mode access is needed.');
        }

        $month = $request->input('month');
        if (!FrozenMonthService::isValidMonth($month)) {
            $month = now()->format('Y-m');
        }

        $bu   = $this->businessUnitId($request);
        $data = $this->costing->ingredientMonth($bu, $month);

        if (!$this->canSeeCost()) {
            foreach ($data['rows'] as $i => $row) {
                foreach (['bought_cost', 'used_value', 'rate_per_base', 'rate_text', 'adjusted_value', 'last_check_value'] as $k) {
                    $data['rows'][$i][$k] = null;
                }
            }
            foreach (['bought_cost', 'used_value', 'meat_used_value', 'other_used_value'] as $k) {
                $data['totals'][$k] = null;
            }
        }

        return response()->json([
            'success'      => true,
            'month'        => $month,
            'ingredients'  => $data,
            'can_see_cost' => $this->canSeeCost(),
            'can_manage'   => $this->canManage(),
        ]);
    }
}
