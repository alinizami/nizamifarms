<?php

namespace App\Services\CRM;

use App\Models\CRM\ProductModel;
use App\Models\CRM\ProductVariantModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 📦 BUNDLED PRODUCTS — the ONE engine (Oct-2026).
 *
 * A product marked "Bundled" is sold on Shopify as one item but is really packets
 * of one or more OTHER products:
 *     EK123 = 3 × Chicken Samosa @ 400                         (Shopify: 1 × 1,200)
 *     EK200 = 2 × Chicken Samosa @ 500 + 1 × Chicken Roll @ 500 (Shopify: 1 × 1,500)
 * The team must see the packets, so a bundle line is turned into one ordinary order
 * line per product inside, at the door (Shopify accept / order create).
 *
 * ⭐ Owner rulings (Oct-15): the price of ONE packet of each product inside is entered
 *    on the bundle (t_crm_prod_product_bundle_item.unit_price). Nothing is divided:
 *        quantity   = bundles × packets
 *        unit_price = that row's packet price
 *        line_total = quantity × unit_price
 *    The bundle's own variant price is kept = the bundle total (the Shopify price), so
 *    any path that does NOT split a bundle still bills the right money.
 *
 * ⭐ An expanded line IS the product inside — its sku / variant_id / product_id. Open
 *    Quantities, the stock-deduction fallback, box scanning and the frozen demand count
 *    all find a line's product BY ITS SKU. A product inside can never itself be a bundle,
 *    so an expanded line is never expanded twice.
 *
 * ⭐ A bundle must never STOP an order: a broken bundle (product inside deleted) is kept
 *    as one line at the bundle price with a warning, never a refusal. Unexpected errors
 *    are logged and the line is treated as ordinary.
 *
 * Every read is gated on supported(): the web files may land before the SQL
 * (product_bundles_oct2026.sql) and then every product is an ordinary one.
 */
final class ProductBundle
{
    public const TABLE = 't_crm_prod_product_bundle_item';
    public const MAX_ITEMS = 20;
    public const MAX_QTY = 1000;
    public const MAX_PRICE = 10000000;

    private static ?bool $supported = null;
    /** @var array<int,bool>|null bundle product ids */
    private static ?array $bundleIds = null;
    /** @var array<int,array> product id → item rows */
    private static array $rows = [];
    /** @var array<string,?string> sku → hint */
    private static array $hints = [];

    /** Is the bundle table on this database yet? Cached per request. */
    public static function supported(): bool
    {
        if (self::$supported === null) {
            try {
                self::$supported = \Illuminate\Support\Facades\Schema::hasTable(self::TABLE);
            } catch (\Throwable $e) {
                self::$supported = false;
            }
        }
        return self::$supported;
    }

    /** Drop the per-request caches (after a bundle is saved; between test sections). */
    public static function forget(): void
    {
        self::$bundleIds = null;
        self::$rows = [];
        self::$hints = [];
    }

    /** @return array<int,bool> every bundle product id — ONE small query per request */
    private static function bundleIds(): array
    {
        if (self::$bundleIds === null) {
            self::$bundleIds = [];
            if (self::supported()) {
                foreach (DB::table(self::TABLE)->distinct()->pluck('bundle_product_id') as $id) {
                    self::$bundleIds[(int) $id] = true;
                }
            }
        }
        return self::$bundleIds;
    }

    /** Is this product set up as a bundle? */
    public static function isBundle(?ProductModel $product): bool
    {
        return $product !== null && self::isBundleProductId((int) $product->id);
    }

    public static function isBundleProductId(int $productId): bool
    {
        return isset(self::bundleIds()[$productId]);
    }

    /**
     * A bundle's rows as stored, in order.
     * @return array<int, array{variant_id:int, qty:int, unit_price:float}>
     */
    public static function itemRows(int $productId): array
    {
        if (!self::isBundleProductId($productId)) {
            return [];
        }
        if (!array_key_exists($productId, self::$rows)) {
            self::$rows[$productId] = DB::table(self::TABLE)
                ->where('bundle_product_id', $productId)
                ->orderBy('position')->orderBy('id')
                ->get(['variant_id', 'qty', 'unit_price'])
                ->map(fn ($r) => ['variant_id' => (int) $r->variant_id, 'qty' => (int) $r->qty, 'unit_price' => (float) $r->unit_price])
                ->all();
        }
        return self::$rows[$productId];
    }

    /** One bundle's price = Σ packets × packet price. */
    public static function totalOf(array $rows): float
    {
        return round(array_sum(array_map(fn ($r) => (int) $r['qty'] * (float) $r['unit_price'], $rows)), 2);
    }

    /**
     * The bundle behind a sold variant, or null for an ordinary product.
     *
     * @return array{bundle_sku:?string, total:float,
     *               items: array<int, array{qty:int, unit_price:float, inner:ProductVariantModel, name:string}>}|null
     * @throws \RuntimeException the product IS a bundle but a product inside can't be used
     */
    public static function forVariant(?ProductVariantModel $variant): ?array
    {
        if (!$variant || !self::supported() || !self::isBundleProductId((int) $variant->product_id)) {
            return null;
        }
        $label = $variant->sku ?: ('#' . $variant->product_id);
        $rows = self::itemRows((int) $variant->product_id);
        $inners = ProductVariantModel::with('product')->whereIn('id', array_column($rows, 'variant_id'))->get()->keyBy('id');

        $items = [];
        foreach ($rows as $r) {
            $inner = $inners->get($r['variant_id']);
            if (!$inner || !$inner->product) {
                throw new \RuntimeException("Bundle '{$label}': a product inside it no longer exists");
            }
            if ((int) $inner->product_id === (int) $variant->product_id || self::isBundleProductId((int) $inner->product_id)) {
                throw new \RuntimeException("Bundle '{$label}' contains another bundle");
            }
            $items[] = [
                'qty'        => $r['qty'],
                'unit_price' => $r['unit_price'],
                'inner'      => $inner,
                'name'       => self::lineName($inner),
            ];
        }
        if (!$items) {
            return null;
        }
        return ['bundle_sku' => $variant->sku, 'total' => self::totalOf($rows), 'items' => $items];
    }

    /**
     * forVariant() that NEVER throws — for the order doors, which must never stop.
     *
     * @return array{0: ?array, 1: ?string} [bundle or null, warning for staff or null]
     */
    public static function resolve(?ProductVariantModel $variant): array
    {
        try {
            return [self::forVariant($variant), null];
        } catch (\RuntimeException $e) {
            Log::warning('ProductBundle: broken bundle kept as one line', ['variant_id' => $variant->id ?? null, 'error' => $e->getMessage()]);
            return [null, $e->getMessage() . ' — kept as ONE line at the bundle price; fix the bundle in Products'];
        } catch (\Throwable $e) {
            Log::error('ProductBundle: lookup failed, line treated as ordinary', ['variant_id' => $variant->id ?? null, 'error' => $e->getMessage()]);
            return [null, null];
        }
    }

    /**
     * The order lines a bundle line becomes: one per product inside, each built from
     * $line (so any other field it carries is kept), with the packets, packet price,
     * total and the INSIDE product's sku / variant / product / name.
     * Per-line extras that must not be counted twice (instructions, discount, tax)
     * stay on the first line only.
     */
    public static function expandLine(array $line, array $bundle, float $bundles): array
    {
        $isFree = !empty($line['is_free']);
        $out = [];
        foreach ($bundle['items'] as $k => $it) {
            $row = $line;
            $qty = $bundles * $it['qty'];
            $row['quantity'] = $qty;
            $row['unit_price'] = $it['unit_price'];
            $row['line_total'] = $isFree ? 0 : round($qty * $it['unit_price'], 2);
            if (array_key_exists('line_subtotal', $line)) {
                $row['line_subtotal'] = $row['line_total'];
            }
            $row['name'] = $it['name'];
            $row['sku'] = $it['inner']->sku;
            $row['variant_id'] = (int) $it['inner']->id;
            $row['product_id'] = (int) $it['inner']->product_id;
            if ($k > 0) {
                unset($row['instructions']);
                foreach (['discount_amount', 'tax_amount'] as $f) {
                    if (array_key_exists($f, $row)) {
                        $row[$f] = 0;
                    }
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    /** resolve() by a variant id ("variant_123" or 123). */
    public static function resolveVariantId($variantId): array
    {
        if (!self::supported() || $variantId === null || $variantId === '') {
            return [null, null];
        }
        $id = (string) $variantId;
        if (str_starts_with($id, 'variant_')) {
            $id = substr($id, 8);
        }
        if (!ctype_digit($id)) {
            return [null, null];
        }
        return self::resolve(ProductVariantModel::find((int) $id));
    }

    /**
     * Order-form lines (OrderController::store) with every line that still names a
     * BUNDLE replaced by its products inside. Lines that are not bundles come back
     * untouched. The current web and phone forms already send the products inside, so
     * this only fires for an older client. Never throws, never stops the order.
     *
     * A line is matched by variant_id (123 / "variant_123"), else a "variant_" product_id,
     * else — only when it has no variant at all — a SKU that names exactly one variant.
     */
    public static function expandFormItems(array $items): array
    {
        if (!self::supported() || !self::bundleIds()) {
            return $items;
        }
        $out = [];
        foreach ($items as $item) {
            $ref = $item['variant_id'] ?? null;
            if (!$ref && str_starts_with((string) ($item['product_id'] ?? ''), 'variant_')) {
                $ref = $item['product_id'];
            }
            if ($ref) {
                [$bundle] = self::resolveVariantId($ref);
            } elseif (!empty($item['sku'])) {
                $bySku = ProductVariantModel::where('sku', $item['sku'])->get();
                [$bundle] = $bySku->count() === 1 ? self::resolve($bySku->first()) : [null];
            } else {
                $bundle = null;
            }
            if (!$bundle) {
                $out[] = $item;
                continue;
            }
            foreach (self::expandLine($item, $bundle, (float) $item['quantity']) as $row) {
                // The order form validator speaks strings for ids.
                $row['variant_id'] = (string) $row['variant_id'];
                $row['product_id'] = (string) $row['product_id'];
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * The name an order line gets for a variant — the same rule as the order product
     * search: the product title, plus the variant title only when the product has
     * more than one variant.
     */
    public static function lineName(ProductVariantModel $variant): string
    {
        $product = $variant->product;
        $name = (string) ($product->title ?? $variant->title ?? '');
        if ($product && $product->variants()->count() > 1 && $variant->title && $variant->title !== $product->title) {
            $name .= ' - ' . $variant->title;
        }
        return $name;
    }

    /**
     * What a Shopify APPROVAL-QUEUE line for this SKU becomes once accepted —
     * "= 6 × Chicken Samosa @ Rs 400" / "= 2 × Samosa @ Rs 500 + 1 × Roll @ Rs 500" —
     * or null for an ordinary SKU. Never throws; one query per request for the set of
     * bundle SKUs, so a list of hundreds of lines costs nothing for ordinary ones.
     */
    public static function hintForLine(?string $sku, $quantity): ?string
    {
        $sku = trim((string) $sku);
        if ($sku === '' || !self::supported()) {
            return null;
        }
        try {
            if (!self::bundleIds()) {
                return null;
            }
            if (!array_key_exists($sku, self::$hints)) {
                self::$hints[$sku] = null;
                $variants = ProductVariantModel::where('sku', $sku)->get();
                if ($variants->count() === 1 && self::isBundleProductId((int) $variants->first()->product_id)) {
                    [$b] = self::resolve($variants->first());
                    if ($b) {
                        self::$hints[$sku] = json_encode(array_map(fn ($it) => [$it['qty'], $it['name'], $it['unit_price']], $b['items']));
                    }
                }
            }
            if (self::$hints[$sku] === null) {
                return null;
            }
            $bundles = (int) round((float) $quantity);
            $parts = array_map(
                fn ($it) => ($bundles * $it[0]) . ' × ' . $it[1] . ' @ Rs ' . number_format($it[2], 0),
                json_decode(self::$hints[$sku], true)
            );
            return '= ' . implode(' + ', $parts);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * What a bundle result in the product search tells the order forms, or null.
     * Never throws: a broken bundle shows as an ordinary product (its price = the total).
     *
     * @return array{bundle_sku:?string, total:float, items: array<int, array>}|null
     */
    public static function searchInfo(ProductVariantModel $variant): ?array
    {
        [$b] = self::resolve($variant);
        if (!$b) {
            return null;
        }
        return [
            'bundle_sku' => $b['bundle_sku'],
            'total'      => $b['total'],
            'items'      => array_map(fn ($it) => [
                'id'         => 'variant_' . $it['inner']->id,
                'variant_id' => (int) $it['inner']->id,
                'product_id' => (int) $it['inner']->product_id,
                'sku'        => $it['inner']->sku,
                'name'       => $it['name'],
                'qty'        => $it['qty'],
                'unit_price' => $it['unit_price'],
            ], $b['items']),
        ];
    }

    /**
     * Normalise posted bundle rows ([variant_id, qty, unit_price] each; "variant_" prefix ok),
     * dropping blank rows the form leaves behind.
     * @return array<int, array{variant_id:int, qty:mixed, unit_price:mixed}>
     */
    public static function normaliseRows($rows): array
    {
        $out = [];
        foreach ((array) $rows as $r) {
            $vid = preg_replace('/^variant_/', '', (string) ($r['variant_id'] ?? ''));
            if ($vid === '' && ($r['qty'] ?? '') === '' && ($r['unit_price'] ?? '') === '') {
                continue;
            }
            $out[] = ['variant_id' => ctype_digit($vid) ? (int) $vid : 0, 'qty' => $r['qty'] ?? null, 'unit_price' => $r['unit_price'] ?? null];
        }
        return $out;
    }

    /**
     * Why this bundle set-up can't be saved, or null when it is fine.
     *
     * @param int|null $productId     the bundle product being saved (null = being created)
     * @param array    $rows          normaliseRows() output
     * @param int      $variantCount  how many variants (SKUs) the bundle product will have
     */
    public static function setupError(?int $productId, array $rows, int $variantCount): ?string
    {
        if ($variantCount !== 1) {
            return 'Bundle: a bundle has exactly ONE SKU (the Shopify SKU) — remove the extra variants first.';
        }
        if (!$rows) {
            return 'Bundle: add at least one product inside the bundle.';
        }
        if (count($rows) > self::MAX_ITEMS) {
            return 'Bundle: at most ' . self::MAX_ITEMS . ' products inside one bundle.';
        }
        $seen = [];
        $packets = 0;
        foreach ($rows as $i => $r) {
            $n = $i + 1;
            $inner = $r['variant_id'] ? ProductVariantModel::with('product')->find($r['variant_id']) : null;
            if (!$inner || !$inner->product) {
                return "Bundle row {$n}: pick the product inside.";
            }
            $title = $inner->product->title;
            if ($productId !== null && (int) $inner->product_id === $productId) {
                return 'Bundle: a product cannot be inside itself.';
            }
            if (self::isBundleProductId((int) $inner->product_id)) {
                return "Bundle: '{$title}' is itself a bundle — pick the plain product.";
            }
            if (empty($inner->sku)) {
                return "Bundle: '{$title}' has no SKU — give it one first (packing and scanning find products by SKU).";
            }
            if (isset($seen[$inner->id])) {
                return "Bundle: '{$title}' is listed twice — put all its packets on one row.";
            }
            $seen[$inner->id] = true;
            $q = $r['qty'];
            if (!is_numeric($q) || (int) $q != $q || (int) $q < 1 || (int) $q > self::MAX_QTY) {
                return "Bundle row {$n} ('{$title}'): packets must be a whole number from 1 to " . self::MAX_QTY . '.';
            }
            $p = $r['unit_price'];
            if (!is_numeric($p) || (float) $p < 0 || (float) $p > self::MAX_PRICE) {
                return "Bundle row {$n} ('{$title}'): enter the price of ONE packet.";
            }
            $packets += (int) $q;
        }
        if ($packets < 2) {
            return 'Bundle: one bundle must hold at least 2 packets in total.';
        }
        if ($productId !== null) {
            $variantIds = ProductVariantModel::where('product_id', $productId)->pluck('id');
            $usedBy = $variantIds->isEmpty() ? null : DB::table(self::TABLE . ' as bi')
                ->join('t_crm_prod_product as p', 'p.id', '=', 'bi.bundle_product_id')
                ->whereIn('bi.variant_id', $variantIds)
                ->where('bi.bundle_product_id', '!=', $productId)
                ->value('p.title');
            if ($usedBy) {
                return "Bundle: this product is inside the bundle '{$usedBy}', so it cannot be a bundle itself.";
            }
        }
        return null;
    }

    /** The bundle that holds any of these variants (for the delete guard), or null. */
    public static function bundleHolding(iterable $variantIds, ?int $exceptProductId = null): ?string
    {
        if (!self::supported()) {
            return null;
        }
        $ids = collect($variantIds)->map(fn ($v) => (int) $v)->filter()->values();
        if ($ids->isEmpty()) {
            return null;
        }
        $q = DB::table(self::TABLE . ' as bi')
            ->join('t_crm_prod_product as p', 'p.id', '=', 'bi.bundle_product_id')
            ->whereIn('bi.variant_id', $ids);
        if ($exceptProductId !== null) {
            $q->where('bi.bundle_product_id', '!=', $exceptProductId);
        }
        return $q->value('p.title');
    }

    /** "3 × KS-CCS @ 400 + 1 × KS-CR @ 500" — the bundle as one line of text (product history). */
    public static function summary(int $productId): string
    {
        if (!self::supported()) {
            return '';
        }
        return DB::table(self::TABLE . ' as bi')
            ->leftJoin('t_crm_prod_product_variant as v', 'v.id', '=', 'bi.variant_id')
            ->where('bi.bundle_product_id', $productId)
            ->orderBy('bi.position')->orderBy('bi.id')
            ->get(['bi.qty', 'bi.unit_price', 'v.sku', 'bi.variant_id'])
            ->map(fn ($r) => $r->qty . ' × ' . ($r->sku ?: '#' . $r->variant_id) . ' @ ' . rtrim(rtrim(number_format((float) $r->unit_price, 2, '.', ''), '0'), '.'))
            ->implode(' + ');
    }

    /** Replace a product's bundle rows (empty = not a bundle any more). Caller owns the transaction. */
    public static function saveRows(int $productId, array $rows, ?int $userId): void
    {
        DB::table(self::TABLE)->where('bundle_product_id', $productId)->delete();
        $now = now();
        foreach (array_values($rows) as $i => $r) {
            DB::table(self::TABLE)->insert([
                'bundle_product_id' => $productId,
                'variant_id'        => (int) $r['variant_id'],
                'qty'               => (int) $r['qty'],
                'unit_price'        => round((float) $r['unit_price'], 2),
                'position'          => $i + 1,
                'created_by'        => $userId,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
        }
        self::forget();
    }
}
