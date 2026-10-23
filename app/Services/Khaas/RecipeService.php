<?php

namespace App\Services\Khaas;

use App\Models\CRM\ProductModel;
use App\Models\Khaas\IngredientModel;
use App\Models\Khaas\RecipeLineModel;
use App\Models\Khaas\RecipeModel;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The ingredient master and the recipe behind each frozen product.
 *
 * ⭐ ONE SHAPE FOR BOTH SURFACES. Everything a screen renders comes out of this class,
 * so the web Planning tab and the phone cannot drift apart — the same rule that keeps
 * the Inventory Report and Month Review honest.
 *
 * ⚠ The legacy meat mapping in t_crm_khaas_product_recipe is NOT replaced and NOT
 *   migrated. It still does its own job: deducting kg of raw meat from storage when a
 *   production plan is accepted. This class only READS it, to pre-fill the meat line
 *   when a product's first recipe is being written, so Qasim does not retype what the
 *   system already knows.
 */
class RecipeService
{
    public const FROZEN_BU = 2;

    /** A pack holding less than this much meat, or more than that, is worth a second look. */
    private const MEAT_PER_PACK_MIN_G = 40;
    private const MEAT_PER_PACK_MAX_G = 400;

    // =================================================================
    //  INGREDIENTS
    // =================================================================

    /** @return array<int,array> */
    public function ingredients(int $businessUnitId = self::FROZEN_BU, bool $includeInactive = false): array
    {
        // ⭐ Sep-26: how many recipe lines use each — additive, one grouped query. The
        //   product form says "counted in PIECES in your recipes" before Save, not after.
        $recipeCounts = RecipeLineModel::query()
            ->groupBy('ingredient_id')
            ->pluck(DB::raw('COUNT(*)'), 'ingredient_id')
            ->all();

        return IngredientModel::where('business_unit_id', $businessUnitId)
            ->when(!$includeInactive, fn ($q) => $q->where('is_active', 1))
            ->orderByRaw("FIELD(kind,'meat','vegetable','dairy','dry','packaging','other')")
            ->orderBy('name')
            ->get()
            ->map(fn (IngredientModel $i) => $i->shape() + ['recipe_count' => (int) ($recipeCounts[$i->id] ?? 0)])
            ->values()
            ->all();
    }

    /**
     * Create or edit one ingredient.
     *
     * @throws \InvalidArgumentException with a message meant for a person
     */
    public function saveIngredient(array $data, ?int $userId = null, int $businessUnitId = self::FROZEN_BU): IngredientModel
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Give the ingredient a name.');
        }

        $id        = (int) ($data['id'] ?? 0);
        $existing  = $id ? IngredientModel::find($id) : null;

        if ($id && !$existing) {
            throw new \InvalidArgumentException('That ingredient no longer exists.');
        }

        // ⚠⚠ AN ABSENT KEY MEANS "LEAVE IT ALONE", NOT "RESET IT".
        //    The edit form sends id, name and kind — nothing else. Defaulting the rest
        //    would have silently turned a millilitre ingredient into grams, cleared its
        //    Roman-Urdu name, unlinked a meat ingredient from storage and re-activated a
        //    retired one, all from someone fixing a spelling mistake.
        $baseUnit = array_key_exists('base_unit', $data)
            ? (string) $data['base_unit']
            : ($existing->base_unit ?? IngredientModel::UNIT_G);

        if (!in_array($baseUnit, IngredientModel::BASE_UNITS, true)) {
            throw new \InvalidArgumentException('Unit must be grams, millilitres or pieces.');
        }

        $kind = array_key_exists('kind', $data)
            ? (string) $data['kind']
            : ($existing->kind ?? IngredientModel::KIND_OTHER);
        if (!in_array($kind, IngredientModel::KINDS, true)) {
            $kind = IngredientModel::KIND_OTHER;
        }

        $allowedDisplay = IngredientModel::DISPLAY_UNITS[$baseUnit];
        $displayUnit = array_key_exists('display_unit', $data)
            ? (string) $data['display_unit']
            : ($existing->display_unit ?? $allowedDisplay[count($allowedDisplay) - 1]);
        if (!in_array($displayUnit, $allowedDisplay, true)) {
            $displayUnit = $allowedDisplay[count($allowedDisplay) - 1];
        }

        $clash = IngredientModel::where('business_unit_id', $businessUnitId)
            ->where('name', $name)
            ->when($id, fn ($q) => $q->where('id', '!=', $id))
            ->first();
        if ($clash) {
            throw new \InvalidArgumentException("\"{$name}\" is already on the ingredient list.");
        }

        $ingredient = $existing ?: new IngredientModel();

        // ⚠ The base unit is frozen once anything references it. Changing g to ml under
        //   a recipe line or a purchase line would silently restate every number that
        //   was ever stored against it.
        if ($ingredient->exists && $ingredient->base_unit !== $baseUnit && $this->ingredientInUse($ingredient)) {
            throw new \InvalidArgumentException(
                'This ingredient is already used in a recipe or a purchase, so its unit cannot change. '
                . IngredientUnitChangeService::replaceAdvice((string) $ingredient->name, $baseUnit)
            );
        }

        $ingredient->fill([
            'business_unit_id' => $ingredient->exists ? $ingredient->business_unit_id : $businessUnitId,
            'name'             => $name,
            'base_unit'        => $baseUnit,
            'display_unit'     => $displayUnit,
            'kind'             => $kind,
            'name_urdu'        => array_key_exists('name_urdu', $data)
                ? (trim((string) $data['name_urdu']) ?: null)
                : $ingredient->name_urdu,
            'storage_product_id' => array_key_exists('storage_product_id', $data)
                ? (($data['storage_product_id'] ?: null))
                : $ingredient->storage_product_id,
            'is_active'        => array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : ($ingredient->exists ? (bool) $ingredient->is_active : true),
        ]);

        if (!$ingredient->exists) {
            $ingredient->created_by = $userId;
        }

        $ingredient->save();

        return $ingredient;
    }

    /**
     * ⚠ Anything that stores a number in this ingredient's unit. The plain edit form may
     *   not change the unit under any of these — the "change everywhere" operation
     *   (IngredientUnitChangeService) is the door that re-types them.
     *   Sep-26: now also tagged vendor products (their pack size is in this unit — product
     *   #82's "1 piece" would silently have become 1 g), consumption rows and openings.
     */
    public function ingredientInUse(IngredientModel $ingredient): bool
    {
        if (RecipeLineModel::where('ingredient_id', $ingredient->id)->exists()) {
            return true;
        }

        foreach (['t_fin_vendor_purchase_items', 't_fin_vendor_products',
                  't_crm_khaas_batch_consumption', 't_crm_khaas_ingredient_opening'] as $table) {
            try {
                if (DB::table($table)->where('ingredient_id', $ingredient->id)->exists()) {
                    return true;
                }
            } catch (\Throwable $e) {
                // column / table not there yet on this database
            }
        }

        return false;
    }

    // =================================================================
    //  RECIPES
    // =================================================================

    /**
     * The recipe a screen should show for one product, with per-pack figures derived.
     *
     * When the product has no recipe yet, `lines` comes back with the meat line
     * suggested from the legacy mapping and `is_suggestion` true, so the form opens
     * part-filled rather than blank.
     */
    public function recipeFor(int $productId, ?string $onDate = null): array
    {
        $product = ProductModel::find($productId);
        $recipe  = RecipeModel::currentFor($productId, $onDate);

        if (!$recipe) {
            return [
                'product_id'     => $productId,
                'product_name'   => $product->title ?? 'Unknown',
                'has_recipe'     => false,
                'is_suggestion'  => true,
                'recipe_id'      => null,
                'version'        => 0,
                'basis_packets'  => null,
                'effective_from' => null,
                'note'           => null,
                'lines'          => $this->suggestedLines($productId),
                'warnings'       => [],
            ];
        }

        $lines = $this->shapeLines(
            $recipe->lines()->get()->map(fn ($l) => [
                'ingredient_id' => (int) $l->ingredient_id,
                'qty_per_basis' => (float) $l->qty_per_basis,
                'is_optional'   => (bool) $l->is_optional,
            ])->all(),
            fn (float $qty) => $recipe->perPacket(new RecipeLineModel(['qty_per_basis' => $qty]))
        );

        return [
            'product_id'     => $productId,
            'product_name'   => $product->title ?? 'Unknown',
            'has_recipe'     => true,
            'is_suggestion'  => false,
            'recipe_id'      => (int) $recipe->id,
            'version'        => (int) $recipe->version,
            'basis_packets'  => (int) $recipe->basis_packets,
            'effective_from' => optional($recipe->effective_from)->toDateString(),
            'note'           => $recipe->note,
            'lines'          => $lines,
            'warnings'       => $this->warningsFor($recipe->basis_packets, $lines),
        ] + $this->familyContext($product);
    }

    /**
     * 🍱 Oct-19: for a pack that belongs to a recipe FAMILY, the facts every screen needs to
     * say so ("Chicken Cheese Samosa — family recipe · used by 8 pcs, 12 pcs") and to open the
     * family editor instead of this pack's derived snapshot. Empty for everything else, so old
     * clients see exactly what they always saw.
     */
    public function familyContext(?ProductModel $product): array
    {
        if (!$product || !RecipeFamilyService::ready() || !$product->recipe_family_id || !$product->pieces_per_pack) {
            return [];
        }
        try {
            $f = (new RecipeFamilyService())->summary((int) $product->recipe_family_id);
        } catch (\Throwable $e) {
            return [];
        }
        if (!$f) {
            return [];
        }
        return ['family' => $f + ['pieces_per_pack' => (int) $product->pieces_per_pack]];
    }

    /**
     * ⭐ ONE line shape for every recipe screen — a product's own recipe and a family recipe
     * alike — with the supply badge facts (linked / bought) the editors show per line.
     *
     * @param array<int,array{ingredient_id:int, qty_per_basis:float, is_optional:bool}> $raw
     * @param callable(float):float $perUnit  batch quantity → per-pack (or per-piece) quantity
     */
    public function shapeLines(array $raw, callable $perUnit): array
    {
        $lineIds     = array_map(fn ($l) => (int) $l['ingredient_id'], $raw);
        $ingredients = IngredientModel::whereIn('id', $lineIds ?: [0])->get()->keyBy('id');
        $facts       = $this->lineFacts($lineIds);

        $lines = [];
        foreach ($raw as $line) {
            $ing = $ingredients->get((int) $line['ingredient_id']);
            if (!$ing) {
                continue;
            }

            $qty       = (float) $line['qty_per_basis'];
            $perPacket = (float) $perUnit($qty);
            $fact      = $facts[(int) $line['ingredient_id']] ?? ['linked_products' => 0, 'linked_vendors' => [], 'last_bought_on' => null];

            // What the editor badges: meat comes off storage and is never bought through a
            // catalogue line, so it is "supplied", not "not linked".
            $supply = $ing->isMeat()
                ? 'storage'
                : ($fact['linked_products'] === 0 ? 'not_linked'
                    : ($fact['last_bought_on'] === null ? 'never_bought' : 'bought'));

            $lines[] = [
                'supply_state'    => $supply,
                'linked_products' => $fact['linked_products'],
                'linked_vendors'  => $fact['linked_vendors'],
                // 🔗 Sep-27: WHICH products, so the recipe sheet can open one to change it.
                'linked_list'     => $fact['linked_list'] ?? [],
                'last_bought_on'  => $fact['last_bought_on'],
                'ingredient_id'   => (int) $line['ingredient_id'],
                'ingredient_name' => $ing->name,
                'kind'            => $ing->kind,
                'kind_label'      => $ing->kindLabel(),
                'base_unit'       => $ing->base_unit,
                'is_meat'         => $ing->isMeat(),
                'is_optional'     => (bool) $line['is_optional'],
                'qty_per_basis'   => $qty,
                'basis_text'      => $ing->phrase($qty),
                'per_packet'      => round($perPacket, 3),
                'per_packet_text' => $ing->phrase($perPacket),
            ];
        }
        return $lines;
    }

    /**
     * Save a recipe as a NEW VERSION.
     *
     * Never edits in place: consumption already written keeps pointing at the version
     * it was made with, so the past is not silently restated. "Re-apply recipe for this
     * month" is the explicit way to restate it.
     *
     * @param array<int,array{ingredient_id:int, qty:float, unit?:string, is_optional?:bool}> $lines
     * @throws \InvalidArgumentException
     */
    public function saveRecipe(
        int $productId,
        int $basisPackets,
        array $lines,
        ?string $effectiveFrom = null,
        ?string $note = null,
        ?int $userId = null
    ): RecipeModel {
        $product = ProductModel::find($productId);
        if (!$product) {
            throw new \InvalidArgumentException('That product no longer exists.');
        }

        if ($basisPackets < 1) {
            throw new \InvalidArgumentException('Say how many packs the batch makes — it must be at least 1.');
        }

        $clean = $this->cleanLines($lines);

        // 🍱 Oct-19: a pack that belongs to a FAMILY has no recipe of its own — its row is a
        //    snapshot of the family's. A save aimed at the pack (an older app, the Replace
        //    door) is the family's recipe seen through this pack: "B packs of N pieces" is
        //    B × N pieces, with the SAME batch quantities. Exact, nothing divided.
        if (RecipeFamilyService::ready() && $product->recipe_family_id && (int) $product->pieces_per_pack > 0) {
            return (new RecipeFamilyService())->saveFromPack(
                $product, $basisPackets, $clean, $effectiveFrom, $note, $userId
            );
        }

        $effectiveFrom = $effectiveFrom ?: now()->toDateString();
        try {
            Carbon::parse($effectiveFrom);
        } catch (\Throwable $e) {
            $effectiveFrom = now()->toDateString();
        }

        return DB::transaction(function () use ($productId, $basisPackets, $clean, $effectiveFrom, $note, $userId) {
            $nextVersion = (int) RecipeModel::where('product_id', $productId)->max('version') + 1;

            RecipeModel::where('product_id', $productId)->update(['is_current' => 0]);

            $recipe = RecipeModel::create([
                'product_id'     => $productId,
                'version'        => $nextVersion,
                'basis_packets'  => $basisPackets,
                'effective_from' => $effectiveFrom,
                'is_current'     => 1,
                'note'           => $note ? mb_substr($note, 0, 255) : null,
                'created_by'     => $userId,
            ]);

            foreach ($clean as $row) {
                RecipeLineModel::create($row + [
                    'recipe_id'  => $recipe->id,
                    'created_at' => now(),
                ]);
            }

            return $recipe;
        });
    }

    /**
     * The typed lines → base-unit rows, checked. ONE door for both a pack's recipe and a family
     * recipe, so "kg against a pieces ingredient" is refused the same way everywhere.
     *
     * @return array<int,array{ingredient_id:int, qty_per_basis:float, is_optional:bool, sort_order:int}>
     * @throws \InvalidArgumentException
     */
    public function cleanLines(array $lines): array
    {
        $clean = [];
        $seen  = [];
        foreach ($lines as $row) {
            $ingredientId = (int) ($row['ingredient_id'] ?? 0);
            if (!$ingredientId || isset($seen[$ingredientId])) {
                continue;
            }

            $ingredient = IngredientModel::find($ingredientId);
            if (!$ingredient) {
                continue;
            }

            // The form sends what the person typed plus the unit they typed it in;
            // conversion happens HERE, once, on the way into base units.
            //
            // ⚠⚠ The unit must be one this ingredient can legally be typed in. The web
            //    dropdown only ever offers those two, but an API caller is not the web
            //    dropdown: "kg" against a pieces ingredient would have stored 1000
            //    pieces per batch, and an unknown unit would have been taken as base.
            $qty  = (float) ($row['qty'] ?? 0);
            $unit = (string) ($row['unit'] ?? $ingredient->base_unit);

            $allowed = IngredientModel::DISPLAY_UNITS[$ingredient->base_unit] ?? [$ingredient->base_unit];
            if (!in_array($unit, $allowed, true)) {
                throw new \InvalidArgumentException(
                    "{$ingredient->name} is measured in " . implode(' or ', $allowed) . ", not {$unit}."
                );
            }

            $base = IngredientModel::toBase($qty, $unit);

            if ($base <= 0) {
                throw new \InvalidArgumentException("How much {$ingredient->name} does the batch use?");
            }

            $seen[$ingredientId] = true;
            $clean[] = [
                'ingredient_id' => $ingredientId,
                'qty_per_basis' => $base,
                'is_optional'   => !empty($row['is_optional']),
                'sort_order'    => count($clean),
            ];
        }

        if (!$clean) {
            throw new \InvalidArgumentException('A recipe needs at least one ingredient.');
        }

        return $clean;
    }

    /** Every version ever written for a product, newest first. */
    public function history(int $productId): array
    {
        return RecipeModel::where('product_id', $productId)
            ->orderByDesc('version')
            ->get()
            ->map(function (RecipeModel $r) {
                return [
                    'recipe_id'      => (int) $r->id,
                    'version'        => (int) $r->version,
                    'basis_packets'  => (int) $r->basis_packets,
                    'effective_from' => optional($r->effective_from)->toDateString(),
                    'is_current'     => (bool) $r->is_current,
                    'note'           => $r->note,
                    'line_count'     => $r->lines()->count(),
                ];
            })->values()->all();
    }

    /** Which products have a recipe and which do not — drives the "no recipe" chips. */
    public function coverage(int $businessUnitId = self::FROZEN_BU): array
    {
        // 📦 A bundle is never cooked — its products inside carry the recipes — so it
        // must not show up as "no recipe".
        $withFamily = RecipeFamilyService::ready();
        $products = \App\Services\CRM\ProductBundle::excludeBundles(
            ProductModel::where('business_unit_id', $businessUnitId)->where('is_active', 1)
        )
            ->orderBy('title')
            ->get($withFamily ? ['id', 'title', 'recipe_family_id', 'pieces_per_pack'] : ['id', 'title']);

        $current = RecipeModel::whereIn('product_id', $products->pluck('id'))
            ->where('is_current', 1)
            ->get()
            ->keyBy('product_id');

        // 🥔 Oct-19: which current recipes want something Storage counts (meat, potato…) — a
        //    preparation with none is planned and accepted with nothing taken from Storage.
        $storageRecipeIds = [];
        try {
            $storageRecipeIds = \Illuminate\Support\Facades\DB::table('t_crm_khaas_recipe_line as l')
                ->join('t_crm_khaas_ingredient as i', 'i.id', '=', 'l.ingredient_id')
                ->whereIn('l.recipe_id', $current->pluck('id')->all() ?: [0])
                ->whereNotNull('i.storage_product_id')
                ->distinct()
                ->pluck('l.recipe_id')
                ->map(fn ($id) => (int) $id)
                ->flip()
                ->all();
        } catch (\Throwable $e) {
            $storageRecipeIds = [];
        }

        // 🍱 Oct-19: which family each pack belongs to, so both pickers can group by family
        $families = $withFamily
            ? \App\Models\Khaas\RecipeFamilyModel::whereIn('id', $products->pluck('recipe_family_id')->filter()->unique()->all() ?: [0])
                ->pluck('name', 'id')->all()
            : [];

        return $products->map(function ($p) use ($current, $families, $withFamily, $storageRecipeIds) {
            $r = $current->get($p->id);
            $fid = $withFamily && $p->recipe_family_id && $p->pieces_per_pack ? (int) $p->recipe_family_id : null;
            return [
                'product_id'    => (int) $p->id,
                'product_name'  => $p->title,
                'has_recipe'    => (bool) $r,
                'has_storage_line' => $r ? isset($storageRecipeIds[(int) $r->id]) : false,
                'version'       => $r ? (int) $r->version : 0,
                'basis_packets' => $r ? (int) $r->basis_packets : null,
                'family_id'     => $fid,
                'family_name'   => $fid ? ($families[$fid] ?? null) : null,
                'pieces_per_pack' => $withFamily && $p->pieces_per_pack ? (int) $p->pieces_per_pack : null,
            ];
        })->values()->all();
    }

    // =================================================================
    //  WHERE THE RECIPES AND THE PURCHASING DISAGREE
    // =================================================================

    /**
     * For every ingredient a current recipe names: is it linked to a vendor product at
     * all, and has it ever actually been bought?
     *
     * ⭐ Owner's ask, 22-Sep: Qasim writes "salt" in a recipe and must write the SAME
     * "salt" when he adds the vendor product, or the two never meet and the cost per
     * pack silently omits it. This is the list of where they have not met yet, in the
     * two states a person can actually act on:
     *
     *   not_linked   — in a recipe, but no vendor product carries this ingredient's tag.
     *                  Remedy: add it as a product under the vendor you buy it from
     *                  (the name suggests itself from this list).
     *   never_bought — linked to a product, but no purchase line has ever been recorded
     *                  against it. Remedy: nothing to fix, just know the cost is
     *                  missing until the first bill.
     *
     * Meat ingredients are left out: they are bought through the storage ledger, which
     * is exact, and never through a vendor catalogue line.
     *
     * @return array{items: array, not_linked: int, never_bought: int}
     */
    public function gaps(int $businessUnitId = self::FROZEN_BU): array
    {
        $usage = $this->ingredientUsage($businessUnitId);
        if (!$usage) {
            return ['items' => [], 'not_linked' => 0, 'never_bought' => 0];
        }

        $ingredients = IngredientModel::whereIn('id', array_keys($usage))
            ->where('is_active', 1)
            ->whereNull('storage_product_id')
            ->orderBy('name')
            ->get();

        $links = $this->catalogueLinks($ingredients->pluck('id')->all());
        $last  = $this->lastBought($ingredients->pluck('id')->all());

        $items = [];
        $notLinked = 0;
        $neverBought = 0;

        foreach ($ingredients as $ing) {
            $id   = (int) $ing->id;
            $link = $links[$id] ?? ['count' => 0, 'vendors' => []];
            $when = $last[$id] ?? null;

            if ($link['count'] === 0) {
                $state = 'not_linked';
                $notLinked++;
            } elseif ($when === null) {
                $state = 'never_bought';
                $neverBought++;
            } else {
                continue;   // linked and bought: nothing to say
            }

            $items[] = [
                'ingredient_id'   => $id,
                'name'            => $ing->name,
                'base_unit'       => $ing->base_unit,
                'state'           => $state,
                'recipe_count'    => (int) $usage[$id]['count'],
                'products'        => $usage[$id]['products'],
                'linked_products' => (int) $link['count'],
                // 🔗 Sep-27: linked, but only at vendors whose bills are one total
                'at_total_only'   => !empty($link['list'])
                    && !array_filter($link['list'], fn ($x) => $x['vendor_by_weight']),
                'linked_vendors'  => $link['vendors'],
                'last_bought_on'  => $when,
                'hint'            => $state === 'not_linked'
                    ? 'Add it as a product, with this exact name, under the vendor you buy it from.'
                    : 'Linked to ' . implode(', ', $link['vendors']) . ' but nothing has been bought yet.',
            ];
        }

        return ['items' => $items, 'not_linked' => $notLinked, 'never_bought' => $neverBought];
    }

    /**
     * The same facts for the lines of ONE recipe, so the editor can badge each row.
     *
     * @return array<int,array{linked_products:int, last_bought_on:?string, linked_vendors:array}>
     */
    public function lineFacts(array $ingredientIds): array
    {
        if (!$ingredientIds) {
            return [];
        }
        $links = $this->catalogueLinks($ingredientIds);
        $last  = $this->lastBought($ingredientIds);

        $out = [];
        foreach ($ingredientIds as $id) {
            $id = (int) $id;
            $out[$id] = [
                'linked_products' => (int) ($links[$id]['count'] ?? 0),
                'linked_list'     => $links[$id]['list'] ?? [],
                'linked_vendors'  => $links[$id]['vendors'] ?? [],
                'last_bought_on'  => $last[$id] ?? null,
            ];
        }
        return $out;
    }

    /** ingredient id => ['count' => recipes using it, 'products' => [names]] */
    private function ingredientUsage(int $businessUnitId): array
    {
        $rows = DB::table('t_crm_khaas_recipe_line as l')
            ->join('t_crm_khaas_recipe as r', 'r.id', '=', 'l.recipe_id')
            ->join('t_crm_prod_product as p', 'p.id', '=', 'r.product_id')
            ->where('r.is_current', 1)
            ->where('p.business_unit_id', $businessUnitId)
            ->where('p.is_active', 1)
            ->select('l.ingredient_id', 'p.title')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r->ingredient_id;
            $out[$id] ??= ['count' => 0, 'products' => []];
            $out[$id]['count']++;
            $out[$id]['products'][] = $r->title;
        }
        return $out;
    }

    /** ingredient id => ['count' => active catalogue products tagged, 'vendors' => [names]] */
    private function catalogueLinks(array $ingredientIds): array
    {
        $out = [];
        try {
            $rows = DB::table('t_fin_vendor_products as vp')
                ->join('t_fin_vendors as v', 'v.id', '=', 'vp.vendor_id')
                ->whereIn('vp.ingredient_id', $ingredientIds)
                ->where('vp.is_active', 1)
                ->select('vp.ingredient_id', 'v.vendor_name', 'vp.id', 'vp.vendor_id', 'vp.product_name',
                    'vp.unit', 'v.default_purchase_method')
                ->get();

            foreach ($rows as $r) {
                $id = (int) $r->ingredient_id;
                $out[$id] ??= ['count' => 0, 'vendors' => [], 'list' => []];
                $out[$id]['count']++;
                $out[$id]['list'][] = [
                    'product_id'   => (int) $r->id,
                    'vendor_id'    => (int) $r->vendor_id,
                    'vendor_name'  => $r->vendor_name,
                    'product_name' => $r->product_name,
                    'unit'         => $r->unit,
                    // ⚠ a product at a by-TOTAL vendor never gets a bill line, so never a price
                    'vendor_by_weight' => $r->default_purchase_method === 'by_weight',
                ];
                if (!in_array($r->vendor_name, $out[$id]['vendors'], true)) {
                    $out[$id]['vendors'][] = $r->vendor_name;
                }
            }
        } catch (\Throwable $e) {
            // Migration not run yet: nothing is linked, which is the honest answer.
        }
        return $out;
    }

    /** ingredient id => the latest posted purchase date, or absent */
    private function lastBought(array $ingredientIds): array
    {
        $out = [];
        try {
            $rows = DB::table('t_fin_vendor_purchase_items as i')
                ->join('t_fin_ledger as l', 'l.id', '=', 'i.ledger_id')
                ->whereIn('i.ingredient_id', $ingredientIds)
                ->where('l.transaction_type', 'vendor_purchase')
                ->whereIn('l.approval_status', ['approved', 'pending_l2'])
                ->select('i.ingredient_id', DB::raw('MAX(l.transaction_date) as last_on'))
                ->groupBy('i.ingredient_id')
                ->get();

            foreach ($rows as $r) {
                $out[(int) $r->ingredient_id] = $r->last_on ? substr((string) $r->last_on, 0, 10) : null;
            }
        } catch (\Throwable $e) {
            // same as above
        }

        // 💲 Sep-27: a bill recorded BEFORE stamping existed still counts as "bought" —
        //    the recipe card prices it (labelled "older bill"), so the badge beside the
        //    same line must not say "never bought yet".
        try {
            $older = (new IngredientPriceService())->olderBillDates(self::FROZEN_BU, $ingredientIds);
            foreach ($older as $id => $d) {
                if (!isset($out[$id]) || $out[$id] === null || $d > $out[$id]) {
                    $out[$id] = $d;
                }
            }
        } catch (\Throwable $e) {
            // pricing is a nicety here; the stamped answer above stands
        }
        return $out;
    }

    // =================================================================
    //  INTERNALS
    // =================================================================

    /**
     * The meat line the legacy mapping already implies, offered as a starting point.
     * Quantity is left at 0 on purpose — only Qasim knows how many kilos the batch takes.
     */
    private function suggestedLines(int $productId): array
    {
        try {
            $storageIds = DB::table('t_crm_khaas_product_recipe')
                ->where('khaas_product_id', $productId)
                ->where('is_active', 1)
                ->whereNotNull('storage_product_id')
                ->pluck('storage_product_id')
                ->all();

            if (!$storageIds) {
                return [];
            }

            return IngredientModel::whereIn('storage_product_id', $storageIds)
                ->where('is_active', 1)
                ->get()
                ->map(function (IngredientModel $ing) {
                    return [
                        'ingredient_id'   => (int) $ing->id,
                        'ingredient_name' => $ing->name,
                        'kind'            => $ing->kind,
                        'kind_label'      => $ing->kindLabel(),
                        'base_unit'       => $ing->base_unit,
                        'is_meat'         => true,
                        'is_optional'     => false,
                        'qty_per_basis'   => 0.0,
                        'basis_text'      => '',
                        'per_packet'      => 0.0,
                        'per_packet_text' => '',
                    ];
                })->values()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Sanity notes shown beside the form. They WARN, they never refuse: an odd-looking
     * recipe is usually a real one, and a guard people learn to click through protects
     * nothing.
     */
    private function warningsFor(int $basisPackets, array $lines): array
    {
        $warnings = [];
        $basis = max(1, $basisPackets);

        foreach ($lines as $line) {
            if (!$line['is_meat'] || $line['base_unit'] !== IngredientModel::UNIT_G) {
                continue;
            }

            $perPack = $line['qty_per_basis'] / $basis;

            if ($perPack > 0 && $perPack < self::MEAT_PER_PACK_MIN_G) {
                $warnings[] = sprintf(
                    'That works out to only %s of %s per pack. Check the pack count.',
                    round($perPack) . ' g',
                    $line['ingredient_name']
                );
            } elseif ($perPack > self::MEAT_PER_PACK_MAX_G) {
                $warnings[] = sprintf(
                    'That works out to %s of %s per pack, which is a lot. Check the pack count.',
                    round($perPack) . ' g',
                    $line['ingredient_name']
                );
            }
        }

        return $warnings;
    }
}
