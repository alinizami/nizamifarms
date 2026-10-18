<?php

namespace App\Services\CRM;

use Illuminate\Support\Facades\DB;

/**
 * The ONE place a product's `product_type` is rolled up into a selling
 * category (Beef / Mutton / Chicken / Khaas · Frozen …).
 *
 * The catalog mixes real categories (Beef, Chicken) with cut-level types
 * (Back Chops, Raan & Dasti…), so every report that wants "how much mutton"
 * needs this map. It lived privately inside the HQ executive service until
 * Oct-2026, when campaign tracking needed the same answer ("did they order
 * FROZEN after the frozen campaign?") — moved here so HQ and Campaigns can
 * never disagree about what counts as Frozen.
 *
 * Unmapped types pass through under their own name — a new type stays
 * visible instead of hiding in Others. Keys are entity-DECODED ("&", not
 * "&amp;"); the catalog stores some of them encoded.
 */
class ProductCategoryRollup
{
    public const FROZEN = 'Khaas · Frozen';

    /** Line items whose product no longer exists in the catalog. */
    public const UNLINKED = 'Old catalog · unlinked';

    public const MAP = [
        'Beef' => 'Beef', 'Boneless LEAN Beef' => 'Beef',
        'Bihari & Pasanday' => 'Beef', 'Steaks' => 'Beef',
        'Mutton' => 'Mutton', 'Lean Whole/Half Bakra' => 'Mutton',
        'Whole/Half Bakra' => 'Mutton', 'Raan & Dasti Lean' => 'Mutton',
        'Raan & Dasti' => 'Mutton', 'Raan Choice Lean' => 'Mutton',
        'Raan Choice' => 'Mutton', 'Back Chops (Puth)' => 'Mutton',
        'Front Chops' => 'Mutton', 'Boneless' => 'Mutton', 'Lean Meat' => 'Mutton',
        'Whole LEAN Meat Cut' => 'Mutton', 'Whole Meat Cut' => 'Mutton',
        'Mix' => 'Mutton', 'Mix Lean' => 'Mutton', 'Lamb' => 'Mutton',
        'Chicken' => 'Chicken', 'Boneless LEAN Chicken' => 'Chicken',
        'Boneless Chicken' => 'Chicken', 'Thigh LEAN' => 'Chicken',
        'Thigh' => 'Chicken', 'LEAN Drumsticks' => 'Chicken',
        'Drumsticks' => 'Chicken', 'Wings' => 'Chicken',
        'Minced (Qeema)' => 'Minced (Qeema)',
        'Paaye' => 'Paaye',
        'Khaas' => self::FROZEN, 'Table Talk' => self::FROZEN,
        'Sadqa' => 'Sadqa & Aqeeqa',
        'Dog Food' => 'Dog Food',
        'Others' => 'Others',
        'Qurbani 2026' => 'Qurbani',
        // "Qurbani 2023" is a STALE type shared by two very different things:
        // the Aqeeqa listings — which are what actually sells on regular orders
        // (verified Jul-2026) — and three dead 2023 Qurbani listings whose only
        // orders since are cancelled, so they never reach this tab (delivered
        // basis). Hence Aqeeqa. Never classify Qurbani by this type.
        'Qurbani 2023' => 'Sadqa & Aqeeqa',
        'Goat Qurbani Day 1' => 'Qurbani', 'Goat Qurbani Day 2' => 'Qurbani',
        'Goat Qurbani Day 3' => 'Qurbani',
    ];

    public static function rollup(?string $type): string
    {
        $t = html_entity_decode(trim((string) $type), ENT_QUOTES);
        if ($t === '') return 'Others';
        return self::MAP[$t] ?? $t;
    }

    /**
     * The raw `product_type` values (exactly as stored, encoding included)
     * that roll up into any of $categories. Read from the live catalog, so a
     * newly added cut type is picked up without a code change.
     */
    public static function rawTypesFor(array $categories): array
    {
        $wanted = array_flip(array_map('strval', $categories));
        if (empty($wanted)) return [];

        $types = DB::table('t_crm_prod_product')
            ->whereNotNull('product_type')
            ->distinct()
            ->pluck('product_type');

        return $types->filter(fn($t) => isset($wanted[self::rollup($t)]))->values()->all();
    }

    /**
     * Categories that currently have at least one ACTIVE product — what a
     * picker should offer. Frozen first (the reason this exists), then by
     * product count.
     */
    public static function selectable(): array
    {
        $rows = DB::table('t_crm_prod_product')
            ->where('is_active', 1)
            ->selectRaw('product_type, COUNT(*) n')
            ->groupBy('product_type')
            ->get();

        $counts = [];
        foreach ($rows as $r) {
            $cat = self::rollup($r->product_type);
            $counts[$cat] = ($counts[$cat] ?? 0) + (int) $r->n;
        }
        uksort($counts, function ($a, $b) use ($counts) {
            if ($a === self::FROZEN) return -1;
            if ($b === self::FROZEN) return 1;
            return $counts[$b] <=> $counts[$a];
        });

        $out = [];
        foreach ($counts as $cat => $n) $out[] = ['category' => $cat, 'products' => $n];
        return $out;
    }
}
