<?php

namespace App\Services\FIN;

/**
 * ⭐⭐ THE ONE PLACE THAT KNOWS WHAT A PURCHASE UNIT MEANS (Sep-2026).
 *
 * Before this, "what is a kg / a pack / a litre" was written five times — PHP
 * `impliedPackQty`, the web products page, the web scan card, the phone's
 * `needsPackSize` and the phone scanner's own map — and they had already drifted
 * (the phone knew "ltr", the server did not; the web select had "liter", no g or ml).
 * The screens now render what this class sends them and the server decides.
 *
 * Kinds are the ingredient base units: g (weight), ml (volume), pcs (count).
 * A "container" unit (pack, box) is any kind — its size is asked, in the ingredient's
 * own unit ("how many pieces in one box?"). That is how Tazo's 400 g pack works.
 *
 * ⚠ `kg` and `piece` are spelled EXACTLY as they always have been:
 *   CategorySalesPurchaseService counts a line as weight only when unit === 'kg'.
 */
class VendorUnits
{
    public const KIND_WEIGHT = 'g';
    public const KIND_VOLUME = 'ml';
    public const KIND_COUNT  = 'pcs';

    /**
     * code => [kind|null (container), factor to kind's base|null, whole numbers?, qty label, one, many]
     */
    public const UNITS = [
        'kg'    => ['g',   1000.0, false, 'Weight (kg)',    'kg',    'kg'],
        'g'     => ['g',   1.0,    false, 'Weight (g)',     'g',     'g'],
        'litre' => ['ml',  1000.0, false, 'Volume (litre)', 'litre', 'litres'],
        'ml'    => ['ml',  1.0,    false, 'Volume (ml)',    'ml',    'ml'],
        'piece' => ['pcs', 1.0,    true,  'Qty (pieces)',   'piece', 'pieces'],
        'dozen' => ['pcs', 12.0,   true,  'Qty (dozens)',   'dozen', 'dozens'],
        'pack'  => [null,  null,   true,  'Qty (packs)',    'pack',  'packs'],
        'box'   => [null,  null,   true,  'Qty (boxes)',    'box',   'boxes'],
    ];

    /** Spellings people and old screens actually typed, mapped onto one code. */
    private const ALIASES = [
        'kgs' => 'kg', 'kilo' => 'kg', 'kilos' => 'kg', 'kilogram' => 'kg', 'kilograms' => 'kg',
        'gm' => 'g', 'gms' => 'g', 'gram' => 'g', 'grams' => 'g', 'gr' => 'g',
        'l' => 'litre', 'ltr' => 'litre', 'ltrs' => 'litre', 'liter' => 'litre', 'liters' => 'litre',
        'litres' => 'litre',
        'pc' => 'piece', 'pcs' => 'piece', 'pieces' => 'piece', 'nos' => 'piece', 'no' => 'piece',
        'dz' => 'dozen', 'doz' => 'dozen', 'dozens' => 'dozen',
        'pkt' => 'pack', 'packet' => 'pack', 'packets' => 'pack', 'packs' => 'pack',
        'ctn' => 'box', 'carton' => 'box', 'cartons' => 'box', 'boxes' => 'box',
    ];

    /** Legacy spellings that still READ correctly but are no longer offered. */
    private const LEGACY = [
        'ton' => ['g', 1000000.0, false, 'Weight (ton)', 'ton', 'tons'],
    ];

    /** The one unit that goes with an ingredient kind, when nothing else is said. */
    private const SUGGESTED = ['g' => 'kg', 'ml' => 'litre', 'pcs' => 'piece'];

    private const KIND_WORD = ['g' => 'weight', 'ml' => 'volume', 'pcs' => 'pieces'];
    private const BASE_WORD = ['g' => 'grams', 'ml' => 'ml', 'pcs' => 'pieces'];

    /** "kg", "KG ", "Kgs", "ltr" → canonical code; null when it is not a unit at all. */
    public static function canonical(?string $unit): ?string
    {
        $u = strtolower(trim((string) $unit));
        if ($u === '') {
            return null;
        }
        if (isset(self::UNITS[$u]) || isset(self::LEGACY[$u])) {
            return $u;
        }
        return self::ALIASES[$u] ?? null;
    }

    private static function row(?string $code): ?array
    {
        return $code === null ? null : (self::UNITS[$code] ?? self::LEGACY[$code] ?? null);
    }

    /** g | ml | pcs, or null for a container (pack/box) or an unknown unit. */
    public static function kindOf(?string $unit): ?string
    {
        return self::row(self::canonical($unit))[0] ?? null;
    }

    public static function isContainer(?string $unit): bool
    {
        $code = self::canonical($unit);
        return $code !== null && self::row($code) !== null && self::row($code)[0] === null;
    }

    /**
     * ⭐ Whole numbers only? Counted units, on FROZEN vendors only (owner, Sep-26):
     *   BU 1 meat genuinely buys 7.5 trotters and 22.5 paaye, and must keep doing so.
     */
    public static function wholeOnly(?string $unit, ?int $vendorBusinessUnit): bool
    {
        if ((int) $vendorBusinessUnit !== 2) {
            return false;
        }
        return (bool) (self::row(self::canonical($unit))[2] ?? false);
    }

    public static function qtyLabel(?string $unit): string
    {
        return self::row(self::canonical($unit))[3] ?? 'Qty';
    }

    public static function word(?string $unit, bool $plural = false): string
    {
        $r = self::row(self::canonical($unit));
        return $r ? ($plural ? $r[5] : $r[4]) : (trim((string) $unit) ?: 'unit');
    }

    public static function suggestedFor(?string $baseUnit): ?string
    {
        return self::SUGGESTED[$baseUnit] ?? null;
    }

    public static function kindWord(?string $baseUnit): string
    {
        return self::KIND_WORD[$baseUnit] ?? 'units';
    }

    public static function baseWord(?string $baseUnit): string
    {
        return self::BASE_WORD[$baseUnit] ?? 'units';
    }

    /** Units a product tagged to an ingredient of this base unit may use. */
    public static function allowedFor(?string $baseUnit): array
    {
        return array_values(array_filter(array_keys(self::UNITS), function ($code) use ($baseUnit) {
            $kind = self::UNITS[$code][0];
            return $kind === null || $kind === $baseUnit;
        }));
    }

    /**
     * How many ingredient base units ONE purchase unit is:
     *   float  — obvious (kg → 1000 g, dozen → 12 pcs)
     *   null   — it is a container: only the buyer knows (ask "how many … in one pack?")
     *   0.0    — the kinds disagree (kg against a pieces ingredient): a MISMATCH
     */
    public static function factorTo(?string $unit, ?string $baseUnit): ?float
    {
        $r = self::row(self::canonical($unit));
        if (!$r) {
            return 0.0;
        }
        if ($r[0] === null) {
            return null;
        }
        return $r[0] === $baseUnit ? (float) $r[1] : 0.0;
    }

    /** The catalogue the screens render. Offered units only — legacy spellings read, never offered. */
    public static function catalogue(): array
    {
        $out = [];
        foreach (self::UNITS as $code => [$kind, $factor, $whole, $label, $one, $many]) {
            $out[] = [
                'code'      => $code,
                'kind'      => $kind,           // null = pack/box, sized per product
                'factor'    => $factor,
                'whole'     => $whole,          // on Frozen vendors
                'qty_label' => $label,
                'one'       => $one,
                'many'      => $many,
            ];
        }
        return $out;
    }

    /**
     * What a screen needs to know about one product's unit. Added to every product the
     * list endpoint returns, so no client has to guess.
     */
    public static function describe(?string $unit, ?int $vendorBusinessUnit): array
    {
        $code = self::canonical($unit);
        $whole = self::wholeOnly($unit, $vendorBusinessUnit);
        return [
            'unit_code'  => $code,               // null = a legacy free-text unit ("800gm")
            'unit_kind'  => self::kindOf($unit),
            'unit_label' => self::qtyLabel($unit),
            'whole_only' => $whole,
            'qty_step'   => $whole ? 1 : 0.001,
        ];
    }

    /**
     * Receipt pack size → the product unit and pack size to prefill.
     * "Canola 1Ltr × 5" → pack of 1000 ml; loose weight → kg; "Eggs 30's" → pack of 30 pcs.
     * A size whose kind the ingredient does not share is left for the person to answer.
     *
     * @return array{unit: string, pack_qty_base: ?float}
     */
    public static function fromReceipt(?string $soldBy, ?float $packValue, ?string $packUnit, ?string $ingredientBase = null): array
    {
        $sizeUnit = self::canonical($packUnit === 'L' ? 'litre' : $packUnit);
        $sizeKind = self::kindOf($sizeUnit);

        if ($packValue && $sizeKind) {
            $base = round($packValue * (float) self::row($sizeUnit)[1], 3);
            if ($ingredientBase && $ingredientBase !== $sizeKind) {
                return ['unit' => 'pack', 'pack_qty_base' => null];
            }
            return ['unit' => 'pack', 'pack_qty_base' => $base];
        }

        if ($soldBy === 'weight') {
            return ['unit' => 'kg', 'pack_qty_base' => null];
        }

        // A counted line with no printed size: pieces for a pieces ingredient, otherwise
        // a pack whose size must be asked — never a guessed kg.
        if ($ingredientBase === 'pcs') {
            return ['unit' => 'piece', 'pack_qty_base' => null];
        }
        return ['unit' => 'pack', 'pack_qty_base' => null];
    }
}
