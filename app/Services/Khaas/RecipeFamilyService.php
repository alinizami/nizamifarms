<?php

namespace App\Services\Khaas;

use App\Models\CRM\ProductModel;
use App\Models\Khaas\FamilyRecipeLineModel;
use App\Models\Khaas\FamilyRecipeModel;
use App\Models\Khaas\IngredientModel;
use App\Models\Khaas\RecipeFamilyModel;
use App\Models\Khaas\RecipeLineModel;
use App\Models\Khaas\RecipeModel;
use App\Services\CRM\ProductBundle;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 🍱 RECIPE FAMILIES — the ONE engine (owner, Oct-19-2026).
 *
 * "Chicken Cheese Samosa" is what the kitchen cooks; "(8 pcs)" and "(12 pcs)" are packs of
 * it. Before this, every pack size was its own product with its own recipe and its own meat
 * mapping, so a new size started with nothing behind it (the seven 12-pcs products deducted
 * 0 kg of meat and consumed no salt, oil or maida).
 *
 * WHAT THIS OWNS — every surface (web pages, phone, product forms, the setup screen) goes
 * through here, so no two can disagree:
 *   • families, and which pack belongs to which (the stored link; a title only SUGGESTS);
 *   • the family recipe, per reference batch in PIECES ("one batch makes 80 pieces");
 *   • each pack's per-product recipe row — an EXACT SNAPSHOT of the family recipe, so the
 *     readers that already exist (consumption, costs, Month Review, the stock sheet, the
 *     Replace / unit-change doors) keep reading what they always read;
 *   • the legacy meat mapping (t_crm_khaas_product_recipe) kept IDENTICAL across a family,
 *     so a new size deducts meat at Accept Demand from its first day.
 *
 * ⭐⭐ THE MATHS — one formula, integers only, one rounding:
 *     per pack of P pieces = qty_per_batch × P ÷ basis_pieces
 *   The snapshot stores it the way RecipeModel already divides (qty ÷ basis_packets):
 *     g = gcd(basis_pieces, P);  basis_packets = basis_pieces ÷ g;  qty = family qty × (P ÷ g)
 *   both divisions are exact, the multiplier is a whole number, so nothing is rounded on the
 *   way. 80 pieces with an 8-pack → g = 8 → "10 packs, 1000 g" — byte-for-byte the recipe it
 *   replaces. With a 12-pack → g = 4 → "20 packs, 3000 g" → 150 g a pack = 1.5 × exactly.
 */
class RecipeFamilyService
{
    public const FROZEN_BU  = 2;
    public const MAX_PIECES = 999;

    /** The SQL (database/migrations/recipe_families_oct2026.sql) has been run. Never throws. */
    public static function ready(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                $ok = Schema::hasTable('t_crm_khaas_recipe_family')
                    && Schema::hasTable('t_crm_khaas_family_recipe')
                    && Schema::hasTable('t_crm_khaas_family_recipe_line')
                    && Schema::hasColumn('t_crm_prod_product', 'recipe_family_id')
                    && Schema::hasColumn('t_crm_prod_product', 'pieces_per_pack')
                    && Schema::hasColumn('t_crm_khaas_recipe', 'family_recipe_id');
            } catch (\Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    private function needReady(): void
    {
        if (!self::ready()) {
            throw new \InvalidArgumentException('Recipe families are not set up on this server yet (the SQL has not been run).');
        }
    }

    // =================================================================
    //  THE MATHS
    // =================================================================

    public static function gcd(int $a, int $b): int
    {
        $a = abs($a);
        $b = abs($b);
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        return max(1, $a);
    }

    /**
     * How a family batch of $basisPieces looks from a pack of $pieces.
     * @return array{basis_packets:int, scale:int}  member qty = family qty × scale
     */
    public static function scaleFor(int $basisPieces, int $pieces): array
    {
        $g = self::gcd($basisPieces, $pieces);
        return ['basis_packets' => intdiv($basisPieces, $g), 'scale' => intdiv($pieces, $g)];
    }

    /** qty_per_batch × pieces ÷ basis_pieces — in ONE step, rounded once. */
    public static function amountFor(float $qtyPerBatch, int $pieces, int $basisPieces, int $dp = 3): float
    {
        if ($basisPieces < 1) {
            return 0.0;
        }
        return round($qtyPerBatch * $pieces / $basisPieces, $dp);
    }

    // =================================================================
    //  TITLES — they SUGGEST, the stored link decides
    // =================================================================

    /**
     * "NF – Chicken Cheese Samosa (12 pcs) - Single Pack" → ['name' => 'Chicken Cheese Samosa', 'pieces' => 12].
     * A title without "(N pcs)" gives its name with pieces = null (a family pack named in
     * words still finds its family; the pieces are typed).
     */
    public static function parseTitle(?string $title): ?array
    {
        $t = trim(preg_replace('/\s+/u', ' ', (string) $title));
        if ($t === '') {
            return null;
        }
        $t = preg_replace('/^(?:NF|Khaas|KW|DKC)\s*[–—-]\s*/iu', '', $t);

        if (preg_match('/^(.+?)\s*\(\s*(\d{1,4})\s*(?:pcs?|pieces?)\.?\s*\)/iu', $t, $m)) {
            $name   = trim($m[1], " \t-–—");
            $pieces = (int) $m[2];
            if ($name !== '') {
                return ['name' => $name, 'pieces' => ($pieces >= 1 && $pieces <= self::MAX_PIECES) ? $pieces : null];
            }
        }
        // No size in brackets: the name is what comes before " - " (or the whole title).
        $name = trim(preg_split('/\s+[–—-]\s+/u', $t)[0] ?? '', " \t-–—");
        return $name !== '' ? ['name' => $name, 'pieces' => null] : null;
    }

    /** For comparing names: case, spacing and punctuation never make two families. */
    public static function normalise(?string $name): string
    {
        $n = mb_strtolower((string) $name);
        $n = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $n);
        return trim(preg_replace('/\s+/u', ' ', $n));
    }

    /**
     * What a product form shows while the title is typed: the family it belongs to, or the
     * one it is probably misspelling, or "new family". Bundles and non-Frozen get nothing.
     */
    public function suggest(?string $title, int $businessUnitId = self::FROZEN_BU, ?int $productId = null): array
    {
        $families = self::ready()
            ? RecipeFamilyModel::where('business_unit_id', $businessUnitId)->where('is_active', 1)->orderBy('name')->get()
            : collect();

        $parsed = self::parseTitle($title);
        $match = null;
        $did = [];
        if ($parsed) {
            $want = self::normalise($parsed['name']);
            foreach ($families as $f) {
                if (self::normalise($f->name) === $want) {
                    $match = $f;
                    break;
                }
            }
            if (!$match && $want !== '') {
                foreach ($families as $f) {
                    $have = self::normalise($f->name);
                    $len  = max(mb_strlen($want), mb_strlen($have));
                    $dist = levenshtein($want, $have);
                    // "Chicken Reshmi Kebab" vs "Chicken Reshmi Kabab" — one or two letters
                    if ($dist <= max(2, (int) floor($len * 0.15))) {
                        $did[] = ['id' => (int) $f->id, 'name' => $f->name];
                    }
                }
                $did = array_slice($did, 0, 3);
            }
        }

        $current = null;
        if ($productId && self::ready()) {
            $p = ProductModel::find($productId);
            if ($p && $p->recipe_family_id) {
                $current = ['family_id' => (int) $p->recipe_family_id, 'pieces_per_pack' => $p->pieces_per_pack ? (int) $p->pieces_per_pack : null];
            }
        }

        return [
            'ready'        => self::ready(),
            'parsed'       => $parsed,
            'match'        => $match ? $this->summary((int) $match->id) : null,
            'did_you_mean' => $did,
            'families'     => $families->map(fn ($f) => ['id' => (int) $f->id, 'name' => $f->name])->values()->all(),
            'current'      => $current,
        ];
    }

    // =================================================================
    //  READS
    // =================================================================

    /** The packs of a family, smallest first. */
    public function members(int $familyId, bool $activeOnly = false)
    {
        return ProductModel::where('recipe_family_id', $familyId)
            ->whereNotNull('pieces_per_pack')
            ->where('pieces_per_pack', '>', 0)
            ->when($activeOnly, fn ($q) => $q->where('is_active', 1))
            ->orderBy('pieces_per_pack')
            ->orderBy('title')
            ->get();
    }

    /** "12 pcs" — how a size is named on a chip. */
    public static function sizeLabel(int $pieces): string
    {
        return $pieces . ' pcs';
    }

    /** The facts a screen needs to name a family and its sizes. Null when it does not exist. */
    public function summary(int $familyId): ?array
    {
        if (!self::ready()) {
            return null;
        }
        $f = RecipeFamilyModel::find($familyId);
        if (!$f) {
            return null;
        }
        $current = FamilyRecipeModel::where('family_id', $f->id)->where('is_current', 1)->orderByDesc('version')->first();

        return [
            'id'        => (int) $f->id,
            'name'      => $f->name,
            'is_active' => (bool) $f->is_active,
            'members'   => $this->members((int) $f->id)->map(fn ($p) => [
                'product_id'      => (int) $p->id,
                'title'           => $p->title,
                'pieces_per_pack' => (int) $p->pieces_per_pack,
                'label'           => self::sizeLabel((int) $p->pieces_per_pack),
                'is_active'       => (bool) $p->is_active,
            ])->values()->all(),
            'recipe'    => $current ? [
                'family_recipe_id' => (int) $current->id,
                'version'          => (int) $current->version,
                'basis_pieces'     => (int) $current->basis_pieces,
                'effective_from'   => optional($current->effective_from)->toDateString(),
            ] : null,
        ];
    }

    /** Every family of a unit, with its sizes. */
    public function families(int $businessUnitId = self::FROZEN_BU): array
    {
        if (!self::ready()) {
            return [];
        }
        return RecipeFamilyModel::where('business_unit_id', $businessUnitId)
            ->orderByDesc('is_active')->orderBy('name')->pluck('id')
            ->map(fn ($id) => $this->summary((int) $id))
            ->filter()->values()->all();
    }

    /**
     * ⭐ The FAMILY recipe as both editors open it: the batch lines, per piece, and per pack
     * for EVERY size in the family — all derived here, in one formula, so the web page and
     * the phone show the same grams.
     */
    public function familyRecipe(int $familyId): array
    {
        $this->needReady();
        $summary = $this->summary($familyId);
        if (!$summary) {
            throw new \InvalidArgumentException('That recipe family no longer exists.');
        }
        $fv = FamilyRecipeModel::where('family_id', $familyId)->where('is_current', 1)->orderByDesc('version')->first();
        $sizes = array_values(array_filter($summary['members'], fn ($m) => $m['is_active']))
            ?: $summary['members'];

        $out = [
            'family'         => ['id' => $summary['id'], 'name' => $summary['name'], 'is_active' => $summary['is_active']],
            'members'        => $summary['members'],
            'sizes'          => [],
            'has_recipe'     => (bool) $fv,
            'family_recipe_id' => $fv ? (int) $fv->id : null,
            'version'        => $fv ? (int) $fv->version : 0,
            'basis_pieces'   => $fv ? (int) $fv->basis_pieces : null,
            'effective_from' => $fv ? optional($fv->effective_from)->toDateString() : null,
            'note'           => $fv ? $fv->note : null,
            'lines'          => [],
            'warnings'       => [],
            'history'        => $this->history($familyId),
            'start_from'     => $fv ? [] : $this->startCandidates($familyId),
        ];

        $B = $fv ? (int) $fv->basis_pieces : 0;
        foreach ($sizes as $m) {
            $out['sizes'][] = [
                'product_id'       => $m['product_id'],
                'label'            => $m['label'],
                'pieces_per_pack'  => $m['pieces_per_pack'],
                // "80 pieces = 10 packs of 8 = 6.67 packs of 12" — a reading aid, never stored
                'packs_per_batch'  => $B ? round($B / $m['pieces_per_pack'], 2) : null,
            ];
        }

        if ($fv) {
            $recipes = new RecipeService();
            $raw = $fv->lines()->get()->map(fn ($l) => [
                'ingredient_id' => (int) $l->ingredient_id,
                'qty_per_basis' => (float) $l->qty_per_basis,
                'is_optional'   => (bool) $l->is_optional,
            ])->all();
            $lines = $recipes->shapeLines($raw, fn (float $q) => $q / max(1, $B));
            $ings  = \App\Models\Khaas\IngredientModel::whereIn('id', array_column($lines, 'ingredient_id') ?: [0])->get()->keyBy('id');

            foreach ($lines as &$l) {
                $ing = $ings->get($l['ingredient_id']);
                $perPiece = $l['qty_per_basis'] / max(1, $B);
                $l['per_piece']      = round($perPiece, 6);
                $l['per_piece_text'] = $ing ? $ing->phrase(round($perPiece, 3)) : '';
                $l['per_pack'] = [];
                foreach ($out['sizes'] as $s) {
                    $q = self::amountFor($l['qty_per_basis'], $s['pieces_per_pack'], $B);
                    $l['per_pack'][(string) $s['product_id']] = [
                        'qty'  => $q,
                        'text' => $ing ? $ing->phrase($q) : (string) $q,
                    ];
                }
                // the old keys, filled with the SMALLEST pack, so an older client reads a pack figure
                if ($out['sizes']) {
                    $first = $l['per_pack'][(string) $out['sizes'][0]['product_id']];
                    $l['per_packet'] = $first['qty'];
                    $l['per_packet_text'] = $first['text'];
                }
            }
            unset($l);
            $out['lines'] = $lines;
            $out['warnings'] = $this->warningsFor($lines, $out['sizes'], $B);
        }

        return $out;
    }

    /** A pack-sized view of a family recipe shape — what IngredientPriceService::costRecipe reads. */
    public static function packShape(array $familyShape, int $pieces): array
    {
        $B = (int) ($familyShape['basis_pieces'] ?? 0);
        if ($B < 1 || $pieces < 1) {
            return ['basis_packets' => 0, 'lines' => []];
        }
        $s = self::scaleFor($B, $pieces);
        return [
            'has_recipe'    => true,
            'basis_packets' => $s['basis_packets'],
            'lines'         => array_map(fn ($l) => array_merge($l, [
                'qty_per_basis' => round((float) $l['qty_per_basis'] * $s['scale'], 3),
            ]), $familyShape['lines'] ?? []),
        ];
    }

    /** Every version of a family recipe, newest first. */
    public function history(int $familyId): array
    {
        return FamilyRecipeModel::where('family_id', $familyId)->orderByDesc('version')->get()
            ->map(fn ($r) => [
                'family_recipe_id' => (int) $r->id,
                'version'          => (int) $r->version,
                'basis_pieces'     => (int) $r->basis_pieces,
                'effective_from'   => optional($r->effective_from)->toDateString(),
                'is_current'       => (bool) $r->is_current,
                'note'             => $r->note,
                'line_count'       => $r->lines()->count(),
            ])->values()->all();
    }

    /**
     * A family with no recipe yet: which of its packs already HAS one (written per pack,
     * before families), converted to pieces, so the editor can say "Start from 8 pcs v17 —
     * 10 packs × 8 = 80 pieces".
     */
    public function startCandidates(int $familyId): array
    {
        $out = [];
        foreach ($this->members($familyId) as $p) {
            $r = RecipeModel::where('product_id', $p->id)->whereNull('family_recipe_id')
                ->orderByDesc('effective_from')->orderByDesc('version')->first();
            if (!$r) {
                continue;
            }
            $out[] = [
                'product_id'     => (int) $p->id,
                'title'          => $p->title,
                'pieces_per_pack'=> (int) $p->pieces_per_pack,
                'recipe_id'      => (int) $r->id,
                'version'        => (int) $r->version,
                'basis_packets'  => (int) $r->basis_packets,
                'basis_pieces'   => (int) $r->basis_packets * (int) $p->pieces_per_pack,
                'effective_from' => optional($r->effective_from)->toDateString(),
                'line_count'     => $r->lines()->count(),
            ];
        }
        return $out;
    }

    /** Meat per pack outside 40–400 g, checked for EVERY size — warns, never refuses. */
    private function warningsFor(array $lines, array $sizes, int $B): array
    {
        $w = [];
        foreach ($lines as $l) {
            if (empty($l['is_meat']) || ($l['base_unit'] ?? '') !== 'g') {
                continue;
            }
            foreach ($sizes as $s) {
                $per = self::amountFor($l['qty_per_basis'], $s['pieces_per_pack'], $B);
                if ($per > 0 && $per < 40) {
                    $w[] = sprintf('A pack of %s gets only %s g of %s. Check the pieces a batch makes.', $s['label'], round($per), $l['ingredient_name']);
                } elseif ($per > 400) {
                    $w[] = sprintf('A pack of %s gets %s g of %s, which is a lot. Check the pieces a batch makes.', $s['label'], round($per), $l['ingredient_name']);
                }
            }
        }
        return $w;
    }

    // =================================================================
    //  WRITES — families
    // =================================================================

    /** A family by name; an existing one of the same name (any case / spacing) is reused. */
    public function createFamily(string $name, int $businessUnitId = self::FROZEN_BU, ?int $userId = null): RecipeFamilyModel
    {
        $this->needReady();
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if (mb_strlen($name) < 2) {
            throw new \InvalidArgumentException('Give the family a name, e.g. "Chicken Cheese Samosa".');
        }
        if (mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('That family name is too long.');
        }
        $want = self::normalise($name);
        foreach (RecipeFamilyModel::where('business_unit_id', $businessUnitId)->get() as $f) {
            if (self::normalise($f->name) === $want) {
                if (!$f->is_active) {
                    $f->is_active = 1;
                    $f->save();
                }
                return $f;
            }
        }
        return RecipeFamilyModel::create([
            'business_unit_id' => $businessUnitId,
            'name'             => $name,
            'is_active'        => 1,
            'created_by'       => $userId,
        ]);
    }

    /** Rename a family. Its packs keep pointing at it; their titles are not touched. */
    public function rename(int $familyId, string $name): RecipeFamilyModel
    {
        $this->needReady();
        $f = RecipeFamilyModel::find($familyId);
        if (!$f) {
            throw new \InvalidArgumentException('That recipe family no longer exists.');
        }
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Give the family a name between 2 and 120 letters.');
        }
        foreach (RecipeFamilyModel::where('business_unit_id', $f->business_unit_id)->where('id', '!=', $f->id)->get() as $o) {
            if (self::normalise($o->name) === self::normalise($name)) {
                throw new \InvalidArgumentException("\"{$o->name}\" is already a family. Move its packs there instead.");
            }
        }
        $f->name = $name;
        $f->save();
        return $f;
    }

    // =================================================================
    //  WRITES — the family recipe and its snapshots
    // =================================================================

    /**
     * Save the family recipe as a NEW VERSION and write an exact snapshot for every pack.
     * Never edits in place, same as a pack recipe: consumption already written keeps its version.
     *
     * @param array $clean  RecipeService::cleanLines() output (base units)
     */
    public function saveFamilyRecipe(int $familyId, int $basisPieces, array $clean, ?string $effectiveFrom = null,
                                     ?string $note = null, ?int $userId = null): FamilyRecipeModel
    {
        $this->needReady();
        $family = RecipeFamilyModel::find($familyId);
        if (!$family) {
            throw new \InvalidArgumentException('That recipe family no longer exists.');
        }
        if (!$family->is_active) {
            throw new \InvalidArgumentException("{$family->name} is hidden — show it again before changing its recipe.");
        }
        if ($basisPieces < 1) {
            throw new \InvalidArgumentException('Say how many pieces one batch makes — at least 1.');
        }
        if ($basisPieces > 1000000) {
            throw new \InvalidArgumentException('That is more pieces than one batch can make — check the number.');
        }
        if (!$clean) {
            throw new \InvalidArgumentException('A recipe needs at least one ingredient.');
        }

        $effectiveFrom = $effectiveFrom ?: now()->toDateString();
        try {
            $effectiveFrom = Carbon::parse($effectiveFrom)->toDateString();
        } catch (\Throwable $e) {
            $effectiveFrom = now()->toDateString();
        }

        return DB::transaction(function () use ($family, $basisPieces, $clean, $effectiveFrom, $note, $userId) {
            $next = (int) FamilyRecipeModel::where('family_id', $family->id)->max('version') + 1;
            FamilyRecipeModel::where('family_id', $family->id)->update(['is_current' => 0]);

            $fv = FamilyRecipeModel::create([
                'family_id'      => $family->id,
                'version'        => $next,
                'basis_pieces'   => $basisPieces,
                'effective_from' => $effectiveFrom,
                'is_current'     => 1,
                'note'           => $note ? mb_substr($note, 0, 255) : null,
                'created_by'     => $userId,
            ]);
            foreach (array_values($clean) as $i => $row) {
                FamilyRecipeLineModel::create([
                    'family_recipe_id' => $fv->id,
                    'ingredient_id'    => (int) $row['ingredient_id'],
                    'qty_per_basis'    => round((float) $row['qty_per_basis'], 3),
                    'is_optional'      => !empty($row['is_optional']),
                    'sort_order'       => $i,
                    'created_at'       => now(),
                ]);
            }

            foreach ($this->members((int) $family->id) as $member) {
                $this->snapshot($fv, $member, $effectiveFrom, $userId);
            }

            Log::info('Recipe family: recipe saved', [
                'family' => $family->id, 'version' => $next, 'basis_pieces' => $basisPieces, 'by' => $userId,
            ]);
            return $fv;
        });
    }

    /**
     * A save aimed at ONE pack of a family (an older app, the Replace door): "B packs of P
     * pieces" is B × P pieces with the SAME batch quantities — exact, nothing divided.
     * Returns that pack's new snapshot, which is what the old callers expect back.
     */
    public function saveFromPack(ProductModel $product, int $basisPackets, array $clean, ?string $effectiveFrom,
                                 ?string $note, ?int $userId): RecipeModel
    {
        $pieces = (int) $product->pieces_per_pack;
        $fv = $this->saveFamilyRecipe(
            (int) $product->recipe_family_id,
            $basisPackets * $pieces,
            $clean,
            $effectiveFrom,
            $note ?: "Saved from {$product->title} ({$basisPackets} packs × {$pieces} pieces)",
            $userId
        );
        $snap = RecipeModel::where('product_id', $product->id)->where('family_recipe_id', $fv->id)->first();
        if (!$snap) {
            // the pack was saved through, so it must have a snapshot — write it if it did not
            $snap = $this->snapshot($fv, $product->fresh(), optional($fv->effective_from)->toDateString(), $userId);
        }
        return $snap;
    }

    /**
     * ⭐ One pack's EXACT copy of one family version, as a per-product recipe row.
     *   g = gcd(B, P) · basis_packets = B ÷ g · qty = family qty × (P ÷ g)
     */
    private function snapshot(FamilyRecipeModel $fv, ProductModel $member, string $effectiveFrom, ?int $userId): RecipeModel
    {
        $pieces = (int) $member->pieces_per_pack;
        $B      = (int) $fv->basis_pieces;
        $s      = self::scaleFor($B, $pieces);
        $family = $fv->family ?: RecipeFamilyModel::find($fv->family_id);

        $next = (int) RecipeModel::where('product_id', $member->id)->max('version') + 1;
        RecipeModel::where('product_id', $member->id)->update(['is_current' => 0]);

        $r = RecipeModel::create([
            'product_id'       => $member->id,
            'version'          => $next,
            'basis_packets'    => $s['basis_packets'],
            'effective_from'   => $effectiveFrom,
            'is_current'       => 1,
            'note'             => mb_substr(sprintf('From the %s family recipe v%d (%d pieces a batch)',
                $family->name ?? 'family', $fv->version, $B), 0, 255),
            'created_by'       => $userId,
            'family_recipe_id' => $fv->id,
            'family_scale'     => $s['scale'],
        ]);
        foreach ($fv->lines()->get() as $l) {
            RecipeLineModel::create([
                'recipe_id'     => $r->id,
                'ingredient_id' => (int) $l->ingredient_id,
                'qty_per_basis' => round((float) $l->qty_per_basis * $s['scale'], 3),
                'is_optional'   => (bool) $l->is_optional,
                'sort_order'    => (int) $l->sort_order,
                'created_at'    => now(),
            ]);
        }
        return $r;
    }

    // =================================================================
    //  WRITES — a pack joins / leaves / changes its pieces
    // =================================================================

    /**
     * Checked BEFORE a product form saves anything, so a bad family answer is a 422 and not a
     * half-saved product. Returns the cleaned request (family id or new name, pieces).
     *
     * @return array{family_id:?int, new_name:?string, pieces:?int, leave:bool}|null  null = not asked
     */
    public function readAssignment(array $input, int $businessUnitId, bool $isBundle): ?array
    {
        $has = array_key_exists('recipe_family_id', $input) || array_key_exists('recipe_family_new', $input)
            || array_key_exists('pieces_per_pack', $input);
        if (!$has || !self::ready()) {
            return null;   // ABSENT = KEEP: an older form or app never touches the family
        }
        $id   = (int) ($input['recipe_family_id'] ?? 0);
        $new  = trim((string) ($input['recipe_family_new'] ?? ''));
        $rawP = $input['pieces_per_pack'] ?? null;
        $pieces = ($rawP === null || $rawP === '') ? null : (int) $rawP;

        if (!$id && $new === '') {
            return ['family_id' => null, 'new_name' => null, 'pieces' => $pieces, 'leave' => true];
        }
        if ($isBundle) {
            throw new \InvalidArgumentException('A bundle is not cooked — its products inside carry the recipe. Leave its family empty.');
        }
        if ($businessUnitId !== self::FROZEN_BU) {
            throw new \InvalidArgumentException('Recipe families are for Frozen products only.');
        }
        if ($pieces === null || $pieces < 1 || $pieces > self::MAX_PIECES || (string) (int) $rawP !== trim((string) $rawP)) {
            throw new \InvalidArgumentException('How many pieces are in one pack? A whole number, e.g. 8 or 12.');
        }
        if ($id) {
            $f = RecipeFamilyModel::find($id);
            if (!$f || (int) $f->business_unit_id !== $businessUnitId) {
                throw new \InvalidArgumentException('That recipe family no longer exists.');
            }
            if (!$f->is_active) {
                throw new \InvalidArgumentException("{$f->name} is hidden — pick another family or show it again.");
            }
        } elseif (mb_strlen($new) < 2 || mb_strlen($new) > 120) {
            throw new \InvalidArgumentException('Give the new family a name, e.g. "Chicken Tikka Samosa".');
        }
        return ['family_id' => $id ?: null, 'new_name' => $id ? null : $new, 'pieces' => $pieces, 'leave' => false];
    }

    /**
     * Put a pack in a family (or take it out, or change its pieces).
     *
     * Joining:
     *  • a pack with NO recipe of its own gets every family version mirrored, on the same
     *    dates — so the day it joins it is costed exactly as its siblings were;
     *  • a pack WITH its own recipe keeps it as history and gets the family's current version
     *    from today (its own versions are never rewritten);
     *  • the meat mapping becomes the family's (a new size deducts meat from day one).
     * Leaving: the pack keeps its last snapshot as its own recipe (now editable on its own).
     */
    public function assign(int $productId, ?int $familyId, ?int $pieces, ?int $userId = null, ?string $newFamilyName = null): array
    {
        $this->needReady();
        $p = ProductModel::find($productId);
        if (!$p) {
            throw new \InvalidArgumentException('That product no longer exists.');
        }
        $bu = (int) ($p->business_unit_id ?: self::FROZEN_BU);
        $check = $this->readAssignment([
            'recipe_family_id'  => $familyId,
            'recipe_family_new' => $newFamilyName,
            'pieces_per_pack'   => $pieces,
        ], $bu, ProductBundle::supported() && ProductBundle::isBundleProductId((int) $p->id));

        return DB::transaction(function () use ($p, $check, $userId) {
            $before = ['family_id' => $p->recipe_family_id ? (int) $p->recipe_family_id : null,
                       'pieces'    => $p->pieces_per_pack ? (int) $p->pieces_per_pack : null];

            if ($check['leave']) {
                $p->recipe_family_id = null;
                if ($check['pieces'] !== null && $check['pieces'] >= 1 && $check['pieces'] <= self::MAX_PIECES) {
                    $p->pieces_per_pack = $check['pieces'];
                }
                $p->save();
                return [
                    'family'    => null,
                    'snapshots' => 0,
                    'mapping'   => null,
                    'message'   => $before['family_id']
                        ? 'Taken out of its family. It keeps its last recipe as its own.'
                        : 'No family.',
                ];
            }

            $family = $check['family_id']
                ? RecipeFamilyModel::find($check['family_id'])
                : $this->createFamily($check['new_name'], (int) ($p->business_unit_id ?: self::FROZEN_BU), $userId);

            $p->recipe_family_id = $family->id;
            $p->pieces_per_pack  = $check['pieces'];
            $p->save();

            $changed = $before['family_id'] !== (int) $family->id || $before['pieces'] !== (int) $check['pieces'];
            $snaps = $changed ? $this->snapshotOnJoin($p, $family, $userId) : 0;
            $mapping = $before['family_id'] !== (int) $family->id ? $this->syncMappingOnJoin($p, $family) : null;

            $hasRecipe = FamilyRecipeModel::where('family_id', $family->id)->exists();
            return [
                'family'    => $this->summary((int) $family->id),
                'snapshots' => $snaps,
                'mapping'   => $mapping,
                'message'   => $hasRecipe
                    ? sprintf('Part of %s — costed from its recipe, %d pieces a pack.', $family->name, $check['pieces'])
                    : sprintf('Part of %s. The family has no recipe yet — write it once and every size uses it.', $family->name),
            ];
        });
    }

    /** @return int snapshots written */
    private function snapshotOnJoin(ProductModel $p, RecipeFamilyModel $family, ?int $userId): int
    {
        $versions = FamilyRecipeModel::where('family_id', $family->id)->orderBy('effective_from')->orderBy('version')->get();
        if ($versions->isEmpty()) {
            return 0;
        }
        $ownLatest = RecipeModel::where('product_id', $p->id)->orderByDesc('effective_from')->orderByDesc('version')->first();

        if (!$ownLatest) {
            foreach ($versions as $fv) {
                $this->snapshot($fv, $p, optional($fv->effective_from)->toDateString(), $userId);
            }
            return $versions->count();
        }
        $current = $versions->sortByDesc(fn ($v) => [$v->is_current ? 1 : 0, $v->version])->first();
        $from = max(
            now()->toDateString(),
            optional($current->effective_from)->toDateString(),
            optional($ownLatest->effective_from)->toDateString()
        );
        $this->snapshot($current, $p, $from, $userId);
        return 1;
    }

    // =================================================================
    //  THE MEAT MAPPING — identical across a family
    // =================================================================

    /** Active mapping rows of one pack. */
    private function mappingRows(int $productId)
    {
        return DB::table('t_crm_khaas_product_recipe')->where('khaas_product_id', $productId)->where('is_active', 1)->get();
    }

    /** The family member whose mapping the others follow (lowest id that has one). */
    private function mappingSource(int $familyId, ?int $exceptProductId = null): ?int
    {
        $ids = $this->members($familyId)->pluck('id')->map(fn ($i) => (int) $i)
            ->reject(fn ($i) => $i === $exceptProductId)->values()->all();
        if (!$ids) {
            return null;
        }
        $row = DB::table('t_crm_khaas_product_recipe')->whereIn('khaas_product_id', $ids)->where('is_active', 1)
            ->orderBy('khaas_product_id')->first();
        return $row ? (int) $row->khaas_product_id : null;
    }

    private function syncMappingOnJoin(ProductModel $p, RecipeFamilyModel $family): array
    {
        $src = $this->mappingSource((int) $family->id, (int) $p->id);
        if ($src) {
            $before = $this->mappingRows((int) $p->id)->pluck('storage_product_id')->filter()->sort()->values()->all();
            $this->copyMapping($src, [(int) $p->id]);
            $after = $this->mappingRows((int) $p->id)->pluck('storage_product_id')->filter()->sort()->values()->all();
            return ['copied_from' => $src, 'replaced' => $before && $before !== $after];
        }
        if ($this->mappingRows((int) $p->id)->isNotEmpty()) {
            $n = $this->fanOutMapping((int) $p->id);
            return ['shared_with' => $n];
        }
        return ['none' => true];
    }

    /**
     * After the meat mapping of ONE member changed (the 🔗 Recipes tab, phone or web), copy it to
     * every other member so the family deducts the same meat whatever size was asked for.
     * @return int members updated
     */
    public function fanOutMapping(int $productId): int
    {
        if (!self::ready()) {
            return 0;
        }
        $p = ProductModel::find($productId);
        if (!$p || !$p->recipe_family_id) {
            return 0;
        }
        $others = $this->members((int) $p->recipe_family_id)->pluck('id')->map(fn ($i) => (int) $i)
            ->reject(fn ($i) => $i === (int) $p->id)->values()->all();
        if ($others) {
            $this->copyMapping((int) $p->id, $others);
        }
        return count($others);
    }

    /** Make each target's ACTIVE mapping exactly the source's (same meats, ratios, variants). */
    private function copyMapping(int $fromProductId, array $toProductIds): void
    {
        $rows = $this->mappingRows($fromProductId);
        foreach ($toProductIds as $to) {
            DB::table('t_crm_khaas_product_recipe')->where('khaas_product_id', $to)->update(['is_active' => 0, 'updated_at' => now()]);
            foreach ($rows as $r) {
                $key = $r->custom_material_id
                    ? ['khaas_product_id' => $to, 'custom_material_id' => $r->custom_material_id]
                    : ['khaas_product_id' => $to, 'storage_product_id' => $r->storage_product_id];
                $exists = DB::table('t_crm_khaas_product_recipe')->where($key)->exists();
                $vals = [
                    'storage_product_id' => $r->storage_product_id,
                    'storage_variant_id' => $r->storage_variant_id,
                    'custom_material_id' => $r->custom_material_id,
                    'ratio_kg'           => $r->ratio_kg,
                    'is_active'          => 1,
                    'updated_at'         => now(),
                ];
                if ($exists) {
                    DB::table('t_crm_khaas_product_recipe')->where($key)->update($vals);
                } else {
                    DB::table('t_crm_khaas_product_recipe')->insert($key + $vals + ['created_at' => now()]);
                }
            }
        }
    }

    // =================================================================
    //  ONE-TIME SET-UP — "Set up recipe families"
    // =================================================================

    /**
     * What the set-up screen suggests: Frozen packs that are in NO family, grouped by the name
     * before "(N pcs)", each with its pieces, whether it already has a recipe, and which recipe
     * would become the family's. Bundles are never offered.
     */
    public function setupPlan(int $businessUnitId = self::FROZEN_BU): array
    {
        if (!self::ready()) {
            return ['ready' => false, 'groups' => [], 'unparsed' => []];
        }
        $q = ProductModel::where('business_unit_id', $businessUnitId)->where('is_active', 1)->whereNull('recipe_family_id');
        if (ProductBundle::supported()) {
            $q = ProductBundle::excludeBundles($q);
        }
        $products = $q->orderBy('title')->get();
        $families = RecipeFamilyModel::where('business_unit_id', $businessUnitId)->get();

        $groups = [];
        $unparsed = [];
        foreach ($products as $p) {
            $parsed = self::parseTitle($p->title);
            if (!$parsed || !$parsed['pieces']) {
                $unparsed[] = ['product_id' => (int) $p->id, 'title' => $p->title];
                continue;
            }
            $key = self::normalise($parsed['name']);
            if (!isset($groups[$key])) {
                $existing = $families->first(fn ($f) => self::normalise($f->name) === $key);
                $groups[$key] = [
                    'key'      => $key,
                    'name'     => $existing ? $existing->name : $parsed['name'],
                    'family'   => $existing ? ['id' => (int) $existing->id, 'name' => $existing->name] : null,
                    'members'  => [],
                ];
            }
            $own = RecipeModel::where('product_id', $p->id)->orderByDesc('effective_from')->orderByDesc('version')->first();
            $meat = DB::table('t_crm_khaas_product_recipe as r')
                ->leftJoin('t_crm_prod_product as sp', 'sp.id', '=', 'r.storage_product_id')
                ->where('r.khaas_product_id', $p->id)->where('r.is_active', 1)->whereNotNull('r.storage_product_id')
                ->pluck('sp.title')->all();
            $groups[$key]['members'][] = [
                'product_id'      => (int) $p->id,
                'title'           => $p->title,
                'pieces_per_pack' => (int) $parsed['pieces'],
                'recipe'          => $own ? [
                    'version'        => (int) $own->version,
                    'basis_packets'  => (int) $own->basis_packets,
                    'effective_from' => optional($own->effective_from)->toDateString(),
                    'basis_pieces'   => (int) $own->basis_packets * (int) $parsed['pieces'],
                    'line_count'     => $own->lines()->count(),
                ] : null,
                'meat'            => $meat,
            ];
        }

        foreach ($groups as &$g) {
            usort($g['members'], fn ($a, $b) => $a['pieces_per_pack'] <=> $b['pieces_per_pack']);
            $withRecipe = array_values(array_filter($g['members'], fn ($m) => $m['recipe']));
            usort($withRecipe, fn ($a, $b) => strcmp($b['recipe']['effective_from'] ?? '', $a['recipe']['effective_from'] ?? ''));
            $g['source_product_id'] = $withRecipe ? $withRecipe[0]['product_id'] : null;
            $g['recipe_choices']    = count($withRecipe);
            $meats = array_values(array_unique(array_map(fn ($m) => implode(' + ', $m['meat']), array_filter($g['members'], fn ($m) => $m['meat']))));
            $g['meat']           = $meats[0] ?? null;
            $g['meat_conflict']  = count($meats) > 1;
            $g['family_has_recipe'] = $g['family'] ? FamilyRecipeModel::where('family_id', $g['family']['id'])->exists() : false;
        }
        unset($g);

        return ['ready' => true, 'groups' => array_values($groups), 'unparsed' => $unparsed];
    }

    /**
     * Do one family of the set-up: create (or reuse) the family, turn the chosen pack's recipe
     * into the family recipe (same batch, counted in pieces, same date it was in force from),
     * then put every chosen pack in it — the source pack first, so its meat becomes the family's.
     *
     * @param array{name:string, family_id?:int, source_product_id?:?int,
     *              members: array<int,array{product_id:int, pieces_per_pack:int}>} $group
     */
    public function setupFamily(array $group, int $businessUnitId, ?int $userId): array
    {
        $this->needReady();
        $members = array_values(array_filter($group['members'] ?? [], fn ($m) => (int) ($m['product_id'] ?? 0) > 0));
        if (!$members) {
            throw new \InvalidArgumentException('Tick at least one pack for this family.');
        }
        foreach ($members as $m) {
            $pc = (int) ($m['pieces_per_pack'] ?? 0);
            if ($pc < 1 || $pc > self::MAX_PIECES) {
                throw new \InvalidArgumentException('Every pack needs its pieces — a whole number like 8 or 12.');
            }
        }
        $sourceId = (int) ($group['source_product_id'] ?? 0) ?: null;
        if ($sourceId && !in_array($sourceId, array_map(fn ($m) => (int) $m['product_id'], $members), true)) {
            throw new \InvalidArgumentException('The recipe to start from must be one of the ticked packs.');
        }

        return DB::transaction(function () use ($group, $members, $sourceId, $businessUnitId, $userId) {
            $family = !empty($group['family_id'])
                ? RecipeFamilyModel::find((int) $group['family_id'])
                : $this->createFamily((string) ($group['name'] ?? ''), $businessUnitId, $userId);
            if (!$family) {
                throw new \InvalidArgumentException('That recipe family no longer exists.');
            }

            $converted = null;
            if ($sourceId && !FamilyRecipeModel::where('family_id', $family->id)->exists()) {
                $src = ProductModel::find($sourceId);
                $pieces = (int) collect($members)->firstWhere('product_id', $sourceId)['pieces_per_pack'];
                // 🍱 Oct-20 (owner): the family takes the source pack's WHOLE recipe history, each on its
                //    own date — not only the newest version. Otherwise a family whose recipe was edited
                //    today starts today, and a new size that joins (the 12-pcs) has no recipe for the
                //    batches it already made: Re-apply cannot cost them. One family version per date —
                //    the version that governed that day (the last one saved for it), oldest first.
                $history = RecipeModel::where('product_id', $sourceId)
                    ->orderBy('effective_from')->orderBy('version')->get()
                    ->groupBy(fn ($r) => optional($r->effective_from)->toDateString())
                    ->map(fn ($sameDay) => $sameDay->sortBy('version')->last())
                    ->values();
                foreach ($history as $own) {
                    $clean = $own->lines()->get()->map(fn ($l) => [
                        'ingredient_id' => (int) $l->ingredient_id,
                        'qty_per_basis' => (float) $l->qty_per_basis,
                        'is_optional'   => (bool) $l->is_optional,
                        'sort_order'    => (int) $l->sort_order,
                    ])->all();
                    if (!$clean || (int) $own->basis_packets < 1) {
                        continue;   // an empty or broken old version cannot become a family version
                    }
                    $basisPieces = (int) $own->basis_packets * $pieces;
                    // no members yet → no snapshots here; they come as each pack joins below
                    $fv = $this->saveFamilyRecipe((int) $family->id, $basisPieces, $clean,
                        optional($own->effective_from)->toDateString(),
                        sprintf('Converted from %s v%d: %d packs × %d = %d pieces', $src->title ?? 'pack', $own->version,
                            $own->basis_packets, $pieces, $basisPieces),
                        $userId);
                    $converted = ['version' => (int) $fv->version, 'basis_pieces' => $basisPieces,
                                  'from' => $src->title ?? null, 'from_version' => (int) $own->version,
                                  'versions' => ($converted['versions'] ?? 0) + 1,
                                  'first_date' => $converted['first_date'] ?? optional($own->effective_from)->toDateString()];
                }
            }

            // the source pack first: its meat mapping is the one the family keeps
            usort($members, fn ($a, $b) => ((int) $b['product_id'] === $sourceId) <=> ((int) $a['product_id'] === $sourceId));
            $joined = [];
            foreach ($members as $m) {
                $r = $this->assign((int) $m['product_id'], (int) $family->id, (int) $m['pieces_per_pack'], $userId);
                $joined[] = ['product_id' => (int) $m['product_id'], 'snapshots' => $r['snapshots'], 'mapping' => $r['mapping']];
            }

            return ['family' => $this->summary((int) $family->id), 'converted' => $converted, 'joined' => $joined];
        });
    }

    // =================================================================
    //  SIZES — for End Batch and Repack
    // =================================================================

    /**
     * The sizes a batch of this product may be packed into: its family's active packs, or
     * just itself when it has no family. Each with its pieces.
     * @return array<int,array{product_id:int, title:string, label:string, pieces_per_pack:?int}>
     */
    // =================================================================
    //  THE PLAN IS BY FAMILY (Oct-19, owner): "we are making Chicken Cheese Samosa", kilos of
    //  meat once; WHICH packs came out is said when the batch ends. A plan line still stores a
    //  product id (its family's ANCHOR = the smallest active size) so every older reader, the
    //  meat mapping and the batch keep working unchanged.
    // =================================================================

    /** The pack a family's plan line stands on: the smallest active size. Null = no active size. */
    public function anchorFor(int $familyId): ?ProductModel
    {
        return self::ready() ? $this->members($familyId, true)->first() : null;
    }

    /**
     * What a plan line is CALLED, per product id: the family's name for a pack in a family, the
     * product's title otherwise. One query for many ids — demand lists, accept messages, history.
     *
     * @return array<int, array{name: string, family_id: ?int, family_name: ?string, sku_name: string}>
     */
    public function planNames(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        if (!$ids) {
            return [];
        }
        $rows = DB::table('t_crm_prod_product as p')
            ->leftJoin('t_crm_khaas_recipe_family as f', function ($j) {
                $j->on('f.id', '=', 'p.recipe_family_id');
            })
            ->whereIn('p.id', $ids)
            ->select('p.id', 'p.title', self::ready() ? 'p.recipe_family_id' : DB::raw('NULL as recipe_family_id'),
                self::ready() ? 'f.name as family_name' : DB::raw('NULL as family_name'))
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $fam = self::ready() && $r->recipe_family_id ? (int) $r->recipe_family_id : null;
            $out[(int) $r->id] = [
                'name'        => $fam && $r->family_name ? $r->family_name : (string) $r->title,
                'family_id'   => $fam,
                'family_name' => $fam ? $r->family_name : null,
                'sku_name'    => (string) $r->title,
            ];
        }
        return $out;
    }

    /**
     * Two plan lines of ONE family = the meat taken twice. The sentence that refuses it, or null.
     * @param array<int, array{khaas_product_id: mixed}> $items
     */
    public function planLineProblem(array $items): ?string
    {
        if (!self::ready()) {
            return null;
        }
        $ids = array_map(fn ($i) => (int) ($i['khaas_product_id'] ?? 0), $items);
        $names = $this->planNames($ids);
        $byFamily = [];
        foreach ($ids as $pid) {
            $fam = $names[$pid]['family_id'] ?? null;
            if ($fam) {
                $byFamily[$fam][] = $pid;
            }
        }
        foreach ($byFamily as $fam => $pids) {
            if (count($pids) > 1) {
                $sizes = array_map(function ($pid) {
                    $p = ProductModel::find($pid);
                    return $p && $p->pieces_per_pack ? self::sizeLabel((int) $p->pieces_per_pack) : ($p->title ?? "#$pid");
                }, $pids);
                $famName = $names[$pids[0]]['family_name'] ?? 'This preparation';
                return sprintf('One line per preparation: %s is on this plan %d times (%s). Plan its kilos once — which sizes are packed is decided when the batch ends.',
                    $famName, count($pids), implode(', ', $sizes));
            }
        }
        return null;
    }

    /**
     * 🍱 End Batch's live check: what ONE pack of this size takes, per ingredient, from the recipe in
     * force today (a family pack's snapshot — so a 12-pack carries exactly 1.5 × an 8-pack's line).
     * Empty when the pack has no recipe.
     *
     * @return array<int, array{ingredient_id:int, name:string, base_unit:string, is_meat:bool, per_pack:float, text:string}>
     */
    public function perPackLines(int $productId, ?string $onDate = null): array
    {
        $recipe = RecipeModel::currentFor($productId, $onDate ?: now()->toDateString());
        if (!$recipe) {
            return [];
        }
        $out = [];
        foreach ($recipe->lines()->get() as $line) {
            $ing = IngredientModel::find($line->ingredient_id);
            if (!$ing) {
                continue;
            }
            $per = $recipe->perPacket($line);
            $out[] = [
                'ingredient_id' => (int) $ing->id,
                'name'          => $ing->name,
                'base_unit'     => $ing->base_unit,
                'is_meat'       => $ing->isMeat(),
                'per_pack'      => $per,
                'text'          => $ing->phrase($per),
            ];
        }
        return $out;
    }

    public function sizesFor(int $productId): array
    {
        $p = ProductModel::find($productId);
        if (!$p) {
            return [];
        }
        if (!self::ready() || !$p->recipe_family_id || !$p->pieces_per_pack) {
            return [[
                'product_id'      => (int) $p->id,
                'title'           => $p->title,
                'label'           => $p->pieces_per_pack ? self::sizeLabel((int) $p->pieces_per_pack) : $p->title,
                'pieces_per_pack' => $p->pieces_per_pack ? (int) $p->pieces_per_pack : null,
            ]];
        }
        return $this->members((int) $p->recipe_family_id, true)->map(fn ($m) => [
            'product_id'      => (int) $m->id,
            'title'           => $m->title,
            'label'           => self::sizeLabel((int) $m->pieces_per_pack),
            'pieces_per_pack' => (int) $m->pieces_per_pack,
        ])->values()->all();
    }
}
