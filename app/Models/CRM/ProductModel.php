<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use App\Models\FIN\BusinessUnitModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductModel extends BaseModel
{
    use HasFactory, Notifiable;
    
    protected $table = 't_crm_prod_product';
    protected $primaryKey = 'id';
    public $timestamps = true;

    /**
     * Default business unit ID (Nizami Farms)
     */
    const DEFAULT_BUSINESS_UNIT_ID = 1;

    /**
     * Boot method to auto-set business_unit_id if not provided
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            // ⭐ CRITICAL: Default to Nizami Farms (1) if business_unit_id not set
            if (empty($model->business_unit_id)) {
                $model->business_unit_id = self::DEFAULT_BUSINESS_UNIT_ID;
            }
        });
    }

    /**
     * How ONE unit of quantity on an order line is measured.
     *
     * ⭐⭐ This is what tells the barcode scanner how to read a scale label. The
     * label's five middle digits are just digits: the Czerlop scale writes GRAMS
     * for a weighed product and a PIECE COUNT for one printed in pieces mode, and
     * the barcode carries nothing that distinguishes the two. Without this flag a
     * "1 pcs" frozen box (…00001…) reads as 0.001 kg, rounds to 0, and is refused
     * — exactly what happened on 12-Sep-2026.
     *
     * KG  — weighed. The label's digits ARE the grams. Every meat product.
     * PCS — counted. The label identifies the PRODUCT; its digits are ignored,
     *       because every box of a product prints the identical code and the
     *       count comes from the order, not the label.
     */
    const SELL_UNIT_KG = 'kg';
    const SELL_UNIT_PCS = 'pcs';
    const SELL_UNITS = [self::SELL_UNIT_KG, self::SELL_UNIT_PCS];

    /**
     * Is the `sell_unit` column on this database yet?
     *
     * Deploy here is manual and web files can land before the SQL does, so every
     * read/write of sell_unit is gated on this — with the column absent the whole
     * feature is simply invisible and every product behaves as a weighed one
     * (today's behaviour), instead of 500ing on an unknown column. Cached per
     * request: Schema::hasColumn hits information_schema and the product list
     * would otherwise ask once per row.
     */
    public static function supportsSellUnit(): bool
    {
        static $supported = null;
        if ($supported === null) {
            try {
                $supported = \Illuminate\Support\Facades\Schema::hasColumn('t_crm_prod_product', 'sell_unit');
            } catch (\Throwable $e) {
                $supported = false;
            }
        }
        return $supported;
    }

    /**
     * Normalise anything into a storable sell unit. Unknown / empty / absent all
     * mean 'kg', so a missing column, an old mobile payload and a blank form field
     * can never silently turn a weighed product into a counted one.
     */
    public static function normaliseSellUnit($value): string
    {
        $v = strtolower(trim((string) $value));
        return in_array($v, self::SELL_UNITS, true) ? $v : self::SELL_UNIT_KG;
    }

    /** Is this product counted in boxes/packs rather than weighed? */
    public function isSoldByPiece(): bool
    {
        return self::supportsSellUnit()
            && self::normaliseSellUnit($this->sell_unit) === self::SELL_UNIT_PCS;
    }

    protected $fillable = [
        'shopify_product_id',
        'shopify_handle',
        'title',
        'description',
        'vendor',
        'product_type',
        'attribute_1',
        'attribute_2',
        'attribute_3',
        'status',
        'published_at',
        'price_min',
        'price_max',
        'compare_at_price',
        'total_inventory',
        'track_inventory',
        'is_lean',
        'is_lean_override',
        'weight_factor',
        'unit_weight_kg', // ⭐ Kg per ONE qty unit (0.5 = 500g pack). Display-only, drives the
                          //    Weight column on Open Order Quantities. NOT the weighing divisor.
        'czerlop_product_id', // ⭐ Scale PLU embedded in the weight barcode (barcode-qty feature)
        'sell_unit',          // ⭐ 'kg' (weighed) | 'pcs' (counted boxes) — see SELL_UNIT_* below
        'recipe_family_id',   // 🍱 Oct-19: which preparation this pack is (RecipeFamilyService writes it)
        'pieces_per_pack',    // 🍱 Oct-19: pieces in one pack — required with a family
        'seo_title',
        'seo_description',
        'featured_image',
        'images',
        'tags',
        'options',
        'shopify_created_at',
        'shopify_updated_at',
        'sync_status',
        'last_synced_at',
        'sync_error',
        'is_active',
        'business_unit_id', // ⭐ FK to t_fin_business_units
        'current_batch_number', // ⭐ Current/last batch number reference
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'price_min' => 'decimal:2',
        'price_max' => 'decimal:2',
        'compare_at_price' => 'decimal:2',
        'total_inventory' => 'integer',
        'track_inventory' => 'boolean',
        'is_active' => 'boolean',
        'weight_factor' => 'decimal:2',
        'unit_weight_kg' => 'decimal:3',
        'czerlop_product_id' => 'integer',
        'images' => 'json',
        'tags' => 'json',
        'options' => 'json'
    ];

    // Mutators to format datetime values for MySQL
    public function setPublishedAtAttribute($value)
    {
        if (!$value) {
            $this->attributes['published_at'] = null;
            return;
        }
        
        try {
            $date = \Carbon\Carbon::parse($value);
            $this->attributes['published_at'] = $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            $this->attributes['published_at'] = null;
        }
    }
    
    public function setShopifyCreatedAtAttribute($value)
    {
        if (!$value) {
            $this->attributes['shopify_created_at'] = null;
            return;
        }
        
        try {
            $date = \Carbon\Carbon::parse($value);
            $this->attributes['shopify_created_at'] = $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            $this->attributes['shopify_created_at'] = null;
        }
    }
    
    public function setShopifyUpdatedAtAttribute($value)
    {
        if (!$value) {
            $this->attributes['shopify_updated_at'] = null;
            return;
        }
        
        try {
            $date = \Carbon\Carbon::parse($value);
            $this->attributes['shopify_updated_at'] = $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            $this->attributes['shopify_updated_at'] = null;
        }
    }
    
    public function setLastSyncedAtAttribute($value)
    {
        if (!$value) {
            $this->attributes['last_synced_at'] = null;
            return;
        }
        
        try {
            $date = \Carbon\Carbon::parse($value);
            $this->attributes['last_synced_at'] = $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            $this->attributes['last_synced_at'] = null;
        }
    }

    // Accessors to parse datetime fields for display
    public function getPublishedAtAttribute($value)
    {
        if (!$value) { return null; }
        if ($value instanceof \Carbon\Carbon) { return $value; }
        try { 
            $carbon = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $value);
            return $carbon->setTimezone(config('app.timezone', 'UTC'));
        } catch (\Exception $e) { 
            try { return \Carbon\Carbon::parse($value); } catch (\Exception $e2) { return $value; }
        }
    }
    
    public function getShopifyCreatedAtAttribute($value)
    {
        if (!$value) { return null; }
        if ($value instanceof \Carbon\Carbon) { return $value; }
        try { 
            $carbon = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $value);
            return $carbon->setTimezone(config('app.timezone', 'UTC'));
        } catch (\Exception $e) { 
            try { return \Carbon\Carbon::parse($value); } catch (\Exception $e2) { return $value; }
        }
    }
    
    public function getShopifyUpdatedAtAttribute($value)
    {
        if (!$value) { return null; }
        if ($value instanceof \Carbon\Carbon) { return $value; }
        try { 
            $carbon = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $value);
            return $carbon->setTimezone(config('app.timezone', 'UTC'));
        } catch (\Exception $e) { 
            try { return \Carbon\Carbon::parse($value); } catch (\Exception $e2) { return $value; }
        }
    }
    
    public function getLastSyncedAtAttribute($value)
    {
        if (!$value) { return null; }
        if ($value instanceof \Carbon\Carbon) { return $value; }
        try { 
            $carbon = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $value);
            return $carbon->setTimezone(config('app.timezone', 'UTC'));
        } catch (\Exception $e) { 
            try { return \Carbon\Carbon::parse($value); } catch (\Exception $e2) { return $value; }
        }
    }

    // Relationships
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariantModel::class, 'product_id');
    }

    public function changeHistory(): HasMany
    {
        return $this->hasMany(ProductChangeHistory::class, 'product_id')->orderBy('created_at', 'desc');
    }

    /**
     * Business Unit relationship
     */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnitModel::class, 'business_unit_id', 'id');
    }

    /**
     * Scope: Filter by business unit
     */
    public function scopeForBusinessUnit($query, $businessUnitId)
    {
        if ($businessUnitId) {
            return $query->where('business_unit_id', $businessUnitId);
        }
        return $query;
    }

    /**
     * Tracked fields for change logging
     */
    private static $trackedProductFields = [
        'title' => ['type' => ProductChangeHistory::TYPE_NAME_CHANGE, 'label' => 'Title'],
        'vendor' => ['type' => ProductChangeHistory::TYPE_VENDOR_CHANGE, 'label' => 'Vendor'],
        'attribute_1' => ['type' => ProductChangeHistory::TYPE_CATEGORY_CHANGE, 'label' => 'Category Level 1'],
        'attribute_2' => ['type' => ProductChangeHistory::TYPE_CATEGORY_CHANGE, 'label' => 'Category Level 2'],
        'attribute_3' => ['type' => ProductChangeHistory::TYPE_CATEGORY_CHANGE, 'label' => 'Category Level 3'],
        'product_type' => ['type' => ProductChangeHistory::TYPE_CATEGORY_CHANGE, 'label' => 'Product Type'],
        'status' => ['type' => ProductChangeHistory::TYPE_STATUS_CHANGE, 'label' => 'Status'],
        'weight_factor' => ['type' => ProductChangeHistory::TYPE_WEIGHT_FACTOR_CHANGE, 'label' => 'Weight Factor'],
        'unit_weight_kg' => ['type' => ProductChangeHistory::TYPE_UNIT_WEIGHT_CHANGE, 'label' => 'Unit Weight (kg)'],
        'is_lean' => ['type' => ProductChangeHistory::TYPE_LEAN_STATUS_CHANGE, 'label' => 'Lean Product'],
        'czerlop_product_id' => ['type' => ProductChangeHistory::TYPE_CZERLOP_CHANGE, 'label' => 'Czerlop Product ID'],
        'sell_unit' => ['type' => ProductChangeHistory::TYPE_SELL_UNIT_CHANGE, 'label' => 'Sold By'],
    ];

    private static $trackedVariantFields = [
        'sku' => ['type' => ProductChangeHistory::TYPE_SKU_CHANGE, 'label' => 'SKU'],
        'price' => ['type' => ProductChangeHistory::TYPE_PRICE_CHANGE, 'label' => 'Price'],
        'inventory_quantity' => ['type' => ProductChangeHistory::TYPE_INVENTORY_CHANGE, 'label' => 'Inventory'],
    ];

    /**
     * Compare and log changes between old and new product data
     */
    private static function logProductChanges(self $product, array $oldData, array $newData, string $source = 'web'): void
    {
        foreach (self::$trackedProductFields as $field => $config) {
            $oldValue = $oldData[$field] ?? null;
            $newValue = $newData[$field] ?? null;
            
            // Normalize for comparison
            $oldNormalized = is_bool($oldValue) ? ($oldValue ? '1' : '0') : (string)($oldValue ?? '');
            $newNormalized = is_bool($newValue) ? ($newValue ? '1' : '0') : (string)($newValue ?? '');
            
            if ($oldNormalized !== $newNormalized) {
                ProductChangeHistory::logChange([
                    'product_id' => $product->id,
                    'change_type' => $config['type'],
                    'field_name' => $config['label'],
                    'old_value' => $oldValue === null ? null : (string)$oldValue,
                    'new_value' => $newValue === null ? null : (string)$newValue,
                    'change_source' => $source,
                ]);
            }
        }
    }

    /**
     * Compare and log changes between old and new variant data
     */
    private static function logVariantChanges(int $productId, int $variantId, array $oldData, array $newData, string $source = 'web'): void
    {
        foreach (self::$trackedVariantFields as $field => $config) {
            $oldValue = $oldData[$field] ?? null;
            $newValue = $newData[$field] ?? null;
            
            // Normalize for comparison (handle decimals and integers)
            $oldNormalized = $oldValue === null ? '' : (string)$oldValue;
            $newNormalized = $newValue === null ? '' : (string)$newValue;
            
            // For price/inventory, compare as numbers to avoid "100.00" vs "100" issues
            if (in_array($field, ['price', 'inventory_quantity'])) {
                $oldNum = (float)($oldValue ?? 0);
                $newNum = (float)($newValue ?? 0);
                if (abs($oldNum - $newNum) < 0.001) continue; // No significant change
            } elseif ($oldNormalized === $newNormalized) {
                continue; // No change
            }
            
            ProductChangeHistory::logChange([
                'product_id' => $productId,
                'variant_id' => $variantId,
                'change_type' => $config['type'],
                'field_name' => $config['label'],
                'old_value' => $oldValue === null ? null : (string)$oldValue,
                'new_value' => $newValue === null ? null : (string)$newValue,
                'change_source' => $source,
            ]);
        }
    }

    /**
     * Apply attribute group mapping to this product for a given attribute key (1..3)
     */
    public function applyAttributeFromRules(int $attributeKey): void
    {
        $title = (string) ($this->title ?? '');
        $group = \DB::table('t_crm_prod_attribute_groups')
            ->where('attribute_key', $attributeKey)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->get()
            ->first(function ($g) use ($title) {
                // Explicit assignment has priority; handled in controller apply flow
                if (!empty($g->match_string)) {
                    return stripos($title, $g->match_string) !== false;
                }
                return false;
            });

        if ($group) {
            $column = 'attribute_' . $attributeKey;
            $this->{$column} = $group->group_name;
            $this->save();
        }
    }

    // Helper methods
    public function getPriceRangeAttribute(): string
    {
        if ($this->price_min == $this->price_max) {
            return number_format($this->price_min, 2);
        }
        return number_format($this->price_min, 2) . ' - ' . number_format($this->price_max, 2);
    }

    public function getMainImageAttribute(): ?string
    {
        return $this->featured_image;
    }

    /**
     * Find product by Shopify ID
     */
    public static function findByShopifyId(string $shopifyId): ?self
    {
        return static::where('shopify_product_id', $shopifyId)->first();
    }

    /**
     * Create or update product from Shopify data
     */
    public static function createOrUpdateFromShopify(array $shopifyProduct): self
    {
        $existingProduct = static::findByShopifyId($shopifyProduct['id']);
        
        if ($existingProduct) {
            // Update existing product
            \Log::info("Updating existing product: {$shopifyProduct['title']} (Shopify ID: {$shopifyProduct['id']})");
            $product = $existingProduct;
        } else {
            // Create new product
            \Log::info("Creating new product: {$shopifyProduct['title']} (Shopify ID: {$shopifyProduct['id']})");
            $product = new static();
        }
        
        // Map Shopify data to our format
        $productData = static::mapShopifyProduct($shopifyProduct);
        
        // Update or create the product
        $product->fill($productData);
        $product->save();
        
        // Handle variants
        if (isset($shopifyProduct['variants']) && is_array($shopifyProduct['variants'])) {
            static::syncVariants($product, $shopifyProduct['variants']);
        }
        
        return $product;
    }

    /**
     * Sync product variants
     */
    protected static function syncVariants(self $product, array $shopifyVariants): void
    {
        // Get existing variant IDs
        $existingVariantIds = $product->variants()->pluck('shopify_variant_id')->toArray();
        $shopifyVariantIds = array_column($shopifyVariants, 'id');
        
        // Delete variants that no longer exist in Shopify
        $variantsToDelete = array_diff($existingVariantIds, $shopifyVariantIds);
        if (!empty($variantsToDelete)) {
            $product->variants()->whereIn('shopify_variant_id', $variantsToDelete)->delete();
            \Log::info("Deleted " . count($variantsToDelete) . " variants for product: {$product->title}");
        }
        
        // Create or update variants
        foreach ($shopifyVariants as $shopifyVariant) {
            $variant = $product->variants()->where('shopify_variant_id', $shopifyVariant['id'])->first();
            
            if (!$variant) {
                $variant = new \App\Models\CRM\ProductVariantModel();
                $variant->product_id = $product->id;
            }
            
            $variantData = \App\Models\CRM\ProductVariantModel::mapShopifyVariant($shopifyVariant);
            $variant->fill($variantData);
            $variant->save();
        }
    }

    /**
     * Map Shopify product data to our format
     */
    public static function mapShopifyProduct(array $shopifyProduct): array
    {
        // Calculate price range from variants
        $variants = $shopifyProduct['variants'] ?? [];
        $prices = array_column($variants, 'price');
        $priceMin = !empty($prices) ? min($prices) : 0;
        $priceMax = !empty($prices) ? max($prices) : 0;

        // Calculate total inventory
        $totalInventory = 0;
        foreach ($variants as $variant) {
            $totalInventory += $variant['inventory_quantity'] ?? 0;
        }

        // Extract images
        $images = [];
        $featuredImage = null;
        if (!empty($shopifyProduct['images'])) {
            foreach ($shopifyProduct['images'] as $image) {
                $images[] = $image['src'];
            }
            $featuredImage = $shopifyProduct['image']['src'] ?? $images[0] ?? null;
        }

        $productData = [
            'shopify_product_id' => $shopifyProduct['id'],
            'shopify_handle' => $shopifyProduct['handle'] ?? null,
            'title' => $shopifyProduct['title'],
            'description' => $shopifyProduct['body_html'] ?? null,
            'vendor' => $shopifyProduct['vendor'] ?? null,
            'product_type' => $shopifyProduct['product_type'] ?? null,
            'status' => $shopifyProduct['status'] ?? 'active',
            'published_at' => $shopifyProduct['published_at'] ?? null,
            
            // Pricing
            'price_min' => $priceMin,
            'price_max' => $priceMax,
            
            // Inventory
            'total_inventory' => $totalInventory,
            
            // Media
            'featured_image' => $featuredImage,
            'images' => $images,
            
            // SEO
            'seo_title' => $shopifyProduct['seo_title'] ?? null,
            'seo_description' => $shopifyProduct['seo_description'] ?? null,
            
            // Organization
            'tags' => !empty($shopifyProduct['tags']) ? explode(',', $shopifyProduct['tags']) : [],
            'options' => $shopifyProduct['options'] ?? [],
            
            // Shopify metadata
            'shopify_created_at' => $shopifyProduct['created_at'] ?? null,
            'shopify_updated_at' => $shopifyProduct['updated_at'] ?? null,
            
            // Sync status
            'sync_status' => 'synced',
            'last_synced_at' => now(),
            
            // Variants data for separate processing
            'variants' => $variants
        ];

        return $productData;
    }

    /**
     * Store product with variants from Shopify API
     */
    public static function storeProductFromApi(array $productData): self
    {
        \DB::beginTransaction();
        
        try {
            // Check for price-only update flag
            $priceOnlyUpdate = $productData['_price_only_update'] ?? false;
            unset($productData['_price_only_update']); // Remove flag from data
            
            // Check for SKU-primary flag (WooCommerce sync uses this)
            $skuPrimary = $productData['_sku_primary'] ?? false;
            unset($productData['_sku_primary']); // Remove flag from data
            
            // Check for existing product
            $existingProduct = null;
            $matchedBySku = false;
            $isWebUIUpdate = false; // Flag for updates via web UI (uses internal variant IDs)
            
            // First check if we have an explicit existing product ID (for manual/web UI updates)
            if (isset($productData['existing_product_id'])) {
                $existingProduct = static::find($productData['existing_product_id']);
                // Web UI updates should ALWAYS use internal variant IDs, regardless of sync status
                // This prevents the bug where internal IDs are confused with shopify_variant_ids
                if ($existingProduct) {
                    $isWebUIUpdate = true;
                }
                unset($productData['existing_product_id']); // Remove from data to avoid DB issues
            }
            // SKU-Primary mode: Check SKU FIRST before platform ID (for WooCommerce sync)
            elseif ($skuPrimary && isset($productData['variants']) && !empty($productData['variants'])) {
                foreach ($productData['variants'] as $incomingVariant) {
                    $incomingSku = $incomingVariant['sku'] ?? null;
                    if ($incomingSku) {
                        // Find existing product with this SKU
                        $existingVariant = ProductVariantModel::where('sku', $incomingSku)->first();
                        if ($existingVariant) {
                            $existingProduct = $existingVariant->product;
                            $matchedBySku = true;
                            // Link this product to the platform product if not already linked
                            if (empty($existingProduct->shopify_product_id) && !empty($productData['shopify_product_id'])) {
                                $existingProduct->shopify_product_id = $productData['shopify_product_id'];
                                $existingProduct->sync_status = 'synced';
                                $existingProduct->save();
                                \Log::info("Linked product #{$existingProduct->id} to WooCommerce #{$productData['shopify_product_id']} via SKU: {$incomingSku}");
                            }
                            break; // Found a match, stop looking
                        }
                    }
                }
                
                // If not found by SKU, also check by platform ID as fallback
                if (!$existingProduct && isset($productData['shopify_product_id'])) {
                    $existingProduct = static::where('shopify_product_id', $productData['shopify_product_id'])->first();
                }
            }
            // Then check for Shopify/WooCommerce products by their platform ID
            elseif (isset($productData['shopify_product_id'])) {
                $existingProduct = static::where('shopify_product_id', $productData['shopify_product_id'])->first();
                
                // If not found by platform ID, try matching by SKU (for manual products that should be linked)
                if (!$existingProduct && isset($productData['variants']) && !empty($productData['variants'])) {
                    foreach ($productData['variants'] as $incomingVariant) {
                        $incomingSku = $incomingVariant['sku'] ?? null;
                        if ($incomingSku) {
                            // Find existing product with this SKU
                            $existingVariant = ProductVariantModel::where('sku', $incomingSku)->first();
                            if ($existingVariant) {
                                $existingProduct = $existingVariant->product;
                                $matchedBySku = true;
                                // Link this manual product to the platform product
                                $existingProduct->shopify_product_id = $productData['shopify_product_id'];
                                $existingProduct->sync_status = 'synced';
                                $existingProduct->save();
                                \Log::info("Linked manual product #{$existingProduct->id} to WooCommerce product #{$productData['shopify_product_id']} via SKU: {$incomingSku}");
                                break; // Found a match, stop looking
                            }
                        }
                    }
                }
            }

            // Prepare product data
            $productAttributes = $productData;
            $productAttributes['created_by'] = auth()->check() ? auth()->id() : null;
            $productAttributes['updated_by'] = auth()->check() ? auth()->id() : null;
            
            // Extract variants
            $variants = $productAttributes['variants'] ?? [];
            unset($productAttributes['variants']);

            // Determine change source for logging
            $changeSource = $isWebUIUpdate ? 'web' : ($skuPrimary ? 'system' : ProductChangeHistory::detectSource());
            
            // Create or update product
            if ($existingProduct) {
                // ⭐ Capture old values for change logging
                $oldProductData = $existingProduct->only(array_keys(self::$trackedProductFields));
                
                if ($priceOnlyUpdate || $skuPrimary) {
                    // For price-only updates or SKU-primary mode (WooCommerce), 
                    // only update title, price, and sync fields - PRESERVE categories
                    $updateFields = [
                        'updated_by' => $productAttributes['updated_by'],
                        'updated_at' => now(),
                    ];
                    
                    // For SKU-primary mode, also update title
                    if ($skuPrimary && isset($productAttributes['title'])) {
                        $updateFields['title'] = $productAttributes['title'];
                    }
                    
                    // Update price fields
                    if (isset($productAttributes['price_min'])) {
                        $updateFields['price_min'] = $productAttributes['price_min'];
                    }
                    if (isset($productAttributes['price_max'])) {
                        $updateFields['price_max'] = $productAttributes['price_max'];
                    }
                    
                    // Only update sync-related fields if they exist
                    if (isset($productAttributes['sync_status'])) {
                        $updateFields['sync_status'] = $productAttributes['sync_status'];
                    }
                    if (isset($productAttributes['last_synced_at'])) {
                        $updateFields['last_synced_at'] = $productAttributes['last_synced_at'];
                    }
                    
                    // Update status if provided
                    if (isset($productAttributes['status'])) {
                        $updateFields['status'] = $productAttributes['status'];
                    }
                    
                    $existingProduct->update($updateFields);
                    \Log::info(($skuPrimary ? "SKU-primary" : "Price-only") . " update for product: {$existingProduct->title}");
                } else {
                    // Full update - but PRESERVE existing category values if new values are empty
                    // This prevents accidental overwrites when editing products via web UI or mobile app
                    $categoryFields = ['attribute_1', 'attribute_2', 'attribute_3', 'product_type', 'vendor'];
                    foreach ($categoryFields as $field) {
                        // If new value is empty/null but existing has a value, preserve the existing value
                        $newValue = $productAttributes[$field] ?? null;
                        $existingValue = $existingProduct->{$field};
                        
                        if ((empty($newValue) || $newValue === '') && !empty($existingValue)) {
                            // Preserve existing value - don't include in update
                            $productAttributes[$field] = $existingValue;
                            \Log::info("Preserved {$field} for product #{$existingProduct->id}: '{$existingValue}' (new value was empty)");
                        }
                    }
                    
                    $existingProduct->update($productAttributes);
                    
                    // ⭐ Log product changes
                    self::logProductChanges($existingProduct, $oldProductData, $productAttributes, $changeSource);
                }
                $product = $existingProduct;
            } else {
                $product = static::create($productAttributes);
                
                // ⭐ Log product creation
                ProductChangeHistory::logChange([
                    'product_id' => $product->id,
                    'change_type' => ProductChangeHistory::TYPE_PRODUCT_CREATED,
                    'field_name' => 'Product',
                    'old_value' => null,
                    'new_value' => $product->title,
                    'change_source' => $changeSource,
                    'notes' => 'Product created',
                ]);
            }

            // Store variants
            if (!empty($variants)) {
                if ($existingProduct && ($priceOnlyUpdate || $skuPrimary)) {
                    // For price-only updates or SKU-primary mode, match variants by SKU and update prices only
                    foreach ($variants as $variantData) {
                        $mappedVariant = ProductVariantModel::mapShopifyVariant($variantData);
                        $variantId = $mappedVariant['shopify_variant_id'] ?? null;
                        $sku = $mappedVariant['sku'] ?? null;
                        
                        $existingVariant = null;
                        
                        // For SKU-primary mode, check SKU first
                        if ($skuPrimary && $sku) {
                            $existingVariant = $product->variants()->where('sku', $sku)->first();
                        }
                        
                        // Then try to match by shopify_variant_id (WooCommerce/Shopify variant ID)
                        if (!$existingVariant && $variantId) {
                            $existingVariant = $product->variants()->where('shopify_variant_id', $variantId)->first();
                        }
                        
                        // If still not found and SKU exists, try matching by SKU
                        if (!$existingVariant && $sku) {
                            $existingVariant = $product->variants()->where('sku', $sku)->first();
                        }
                        
                        if ($existingVariant) {
                            // Update only price-related fields
                            $priceUpdateFields = [
                                'price' => $mappedVariant['price'] ?? $existingVariant->price,
                                'compare_at_price' => $mappedVariant['compare_at_price'] ?? $existingVariant->compare_at_price,
                                'cost_price' => $mappedVariant['cost_price'] ?? $existingVariant->cost_price,
                                'updated_at' => now(),
                            ];
                            
                            // Link variant to platform ID if not already linked
                            if ($skuPrimary && $variantId && empty($existingVariant->shopify_variant_id)) {
                                $priceUpdateFields['shopify_variant_id'] = $variantId;
                            }
                            
                            $existingVariant->update($priceUpdateFields);
                            \Log::info("Updated prices for variant SKU: {$sku}" . ($variantId ? ", ID: {$variantId}" : ""));
                        } else {
                            // New variant - add it
                            $mappedVariant['product_id'] = $product->id;
                            $mappedVariant['created_by'] = auth()->check() ? auth()->id() : null;
                            ProductVariantModel::create($mappedVariant);
                            \Log::info("Added new variant SKU: {$sku}" . ($variantId ? ", ID: {$variantId}" : ""));
                        }
                    }
                } elseif ($isWebUIUpdate && $existingProduct) {
                    // ⭐ WEB UI UPDATE: Update variants in place using internal ID
                    // This applies to BOTH manual products AND synced products edited via web UI
                    // This prevents the bug where internal variant IDs are mistakenly used as shopify_variant_id
                    \Log::info("Web UI update for product #{$product->id} - updating variants in place");
                    
                    // Collect variant IDs that should remain
                    $incomingVariantIds = [];
                    $newVariants = [];
                    
                    foreach ($variants as $variant) {
                        $variantInternalId = $variant['id'] ?? null;
                        
                        if ($variantInternalId) {
                            // Existing variant - update it by internal ID
                            $incomingVariantIds[] = $variantInternalId;
                            
                            $existingVariant = $product->variants()->where('id', $variantInternalId)->first();
                            if ($existingVariant) {
                                // ⭐ Capture old values for change logging
                                $oldVariantData = [
                                    'sku' => $existingVariant->sku,
                                    'price' => $existingVariant->price,
                                    'inventory_quantity' => $existingVariant->inventory_quantity,
                                ];
                                
                                // ⭐ SKU PROTECTION: Never overwrite existing SKU with empty/null
                                // Only update SKU if new value is non-empty AND different from existing
                                $newSku = $variant['sku'] ?? null;
                                $preservedSku = $existingVariant->sku;
                                if (!empty($newSku) && $newSku !== $existingVariant->sku) {
                                    $preservedSku = $newSku;
                                    \Log::info("SKU changed for variant #{$existingVariant->id}: '{$existingVariant->sku}' → '{$newSku}'");
                                } elseif (empty($newSku) && !empty($existingVariant->sku)) {
                                    \Log::warning("Blocked attempt to clear SKU for variant #{$existingVariant->id}, preserving: '{$existingVariant->sku}'");
                                }
                                
                                // Prepare new values
                                $newPrice = $variant['price'] ?? $existingVariant->price;
                                $newInventory = $variant['inventory_quantity'] ?? $existingVariant->inventory_quantity;
                                
                                // Update only the editable fields, NEVER touch shopify_variant_id
                                $existingVariant->update([
                                    'title' => $variant['title'] ?? $existingVariant->title,
                                    'sku' => $preservedSku,
                                    'barcode' => $variant['barcode'] ?? $existingVariant->barcode,
                                    'price' => $newPrice,
                                    'compare_at_price' => $variant['compare_at_price'] ?? $existingVariant->compare_at_price,
                                    'cost_price' => $variant['cost_price'] ?? $existingVariant->cost_price,
                                    'inventory_quantity' => $newInventory,
                                    'inventory_policy' => $variant['inventory_policy'] ?? $existingVariant->inventory_policy,
                                    'weight' => $variant['weight'] ?? $existingVariant->weight,
                                    'weight_unit' => $variant['weight_unit'] ?? $existingVariant->weight_unit,
                                    'position' => $variant['position'] ?? $existingVariant->position,
                                    'available' => $variant['available'] ?? $existingVariant->available,
                                    'updated_by' => auth()->check() ? auth()->id() : null,
                                ]);
                                
                                // ⭐ Log variant changes
                                $newVariantData = [
                                    'sku' => $preservedSku,
                                    'price' => $newPrice,
                                    'inventory_quantity' => $newInventory,
                                ];
                                self::logVariantChanges($product->id, $existingVariant->id, $oldVariantData, $newVariantData, $changeSource);
                                
                                \Log::info("Updated variant ID: {$variantInternalId} for product #{$product->id}");
                            } else {
                                \Log::warning("Variant ID {$variantInternalId} not found for product #{$product->id}, treating as new");
                                $newVariants[] = $variant;
                            }
                        } else {
                            // New variant (no ID) - will be created
                            $newVariants[] = $variant;
                        }
                    }
                    
                    // Delete variants that are no longer in the incoming list
                    if (!empty($incomingVariantIds)) {
                        // ⭐ Log variant deletions before deleting
                        $variantsToDelete = $product->variants()->whereNotIn('id', $incomingVariantIds)->get();
                        foreach ($variantsToDelete as $deletedVariant) {
                            ProductChangeHistory::logChange([
                                'product_id' => $product->id,
                                'variant_id' => $deletedVariant->id,
                                'change_type' => ProductChangeHistory::TYPE_VARIANT_DELETED,
                                'field_name' => 'Variant',
                                'old_value' => $deletedVariant->title . ' (SKU: ' . ($deletedVariant->sku ?? 'none') . ')',
                                'new_value' => null,
                                'change_source' => $changeSource,
                            ]);
                        }
                        
                        $deletedCount = $product->variants()->whereNotIn('id', $incomingVariantIds)->delete();
                        if ($deletedCount > 0) {
                            \Log::info("Deleted {$deletedCount} removed variants from product #{$product->id}");
                        }
                    }
                    
                    // Create any new variants
                    // Note: New variants added via web UI don't get shopify_variant_id
                    // They will get linked if/when synced from Shopify later (matched by SKU)
                    foreach ($newVariants as $variant) {
                        $newVariantModel = ProductVariantModel::create([
                            'product_id' => $product->id,
                            'shopify_variant_id' => null, // New variants don't have Shopify IDs until synced
                            'title' => $variant['title'] ?? $product->title,
                            'sku' => $variant['sku'] ?? null,
                            'barcode' => $variant['barcode'] ?? null,
                            'price' => $variant['price'] ?? 0,
                            'compare_at_price' => $variant['compare_at_price'] ?? null,
                            'cost_price' => $variant['cost_price'] ?? null,
                            'inventory_quantity' => $variant['inventory_quantity'] ?? 0,
                            'inventory_policy' => $variant['inventory_policy'] ?? 'deny',
                            'weight' => $variant['weight'] ?? null,
                            'weight_unit' => $variant['weight_unit'] ?? 'g',
                            'position' => $variant['position'] ?? 1,
                            'available' => $variant['available'] ?? true,
                            'created_by' => auth()->check() ? auth()->id() : null,
                        ]);
                        
                        // ⭐ Log new variant creation
                        ProductChangeHistory::logChange([
                            'product_id' => $product->id,
                            'variant_id' => $newVariantModel->id,
                            'change_type' => ProductChangeHistory::TYPE_VARIANT_CREATED,
                            'field_name' => 'Variant',
                            'old_value' => null,
                            'new_value' => ($newVariantModel->title ?? 'Default') . ' (SKU: ' . ($newVariantModel->sku ?? 'none') . ', Price: ' . $newVariantModel->price . ')',
                            'change_source' => $changeSource,
                        ]);
                        
                        \Log::info("Created new variant for product #{$product->id}");
                    }
                } else {
                    // Full update or new product (Shopify/WooCommerce) - delete and recreate variants
                    if ($existingProduct) {
                        $product->variants()->delete();
                    }

                    $variantModels = [];
                    foreach ($variants as $variant) {
                        $variantData = ProductVariantModel::mapShopifyVariant($variant);
                        $variantData['product_id'] = $product->id;
                        $variantData['created_by'] = auth()->check() ? auth()->id() : null;
                        $variantModels[] = new ProductVariantModel($variantData);
                    }
                    
                    $product->variants()->saveMany($variantModels);
                }
            }

            // Auto-assign optional attribute labels by match rules stored in JSON (title contains)
            // ⚠️ ONLY run for NEW products to avoid accidentally changing existing products
            // For existing products, categories should be changed explicitly via the UI
            if (!$existingProduct) {
                try {
                    $title = (string) ($product->title ?? '');
                    $labelsPath = storage_path('app/private/attribute_auto_rules.json');
                    if (is_file($labelsPath) && $title !== '') {
                        $rules = json_decode(file_get_contents($labelsPath), true) ?: [];
                        $autoAssigned = false;
                        
                        foreach ([1,2,3] as $key) {
                            $column = 'attribute_' . $key;
                            if (!empty($rules[(string)$key]) && empty($product->{$column})) {
                                foreach ($rules[(string)$key] as $rule) { // assume already sorted by priority desc
                                    $needle = (string) ($rule['match'] ?? '');
                                    $groupName = (string) ($rule['group'] ?? '');
                                    if ($needle !== '' && $groupName !== '' && stripos($title, $needle) !== false) {
                                        $product->{$column} = $groupName;
                                        $autoAssigned = true;
                                        
                                        // ⭐ Log the auto-assignment
                                        ProductChangeHistory::logChange([
                                            'product_id' => $product->id,
                                            'change_type' => ProductChangeHistory::TYPE_CATEGORY_CHANGE,
                                            'field_name' => 'Category Level ' . $key . ' (Auto)',
                                            'old_value' => null,
                                            'new_value' => $groupName,
                                            'change_source' => 'system',
                                            'notes' => "Auto-assigned based on title match: '{$needle}'",
                                        ]);
                                        
                                        \Log::info("Auto-assigned {$column} = '{$groupName}' to new product #{$product->id} (matched: '{$needle}')");
                                        break;
                                    }
                                }
                            }
                        }
                        
                        // Only save if we actually made changes
                        if ($autoAssigned) {
                            $product->save();
                        }
                    }
                } catch (\Throwable $e) {
                    \Log::warning("Auto-assign category failed for product #{$product->id}: " . $e->getMessage());
                    // fail silently to avoid impacting core flows
                }
            }

            \DB::commit();
            return $product->load('variants');
            
        } catch (\Exception $e) {
            \DB::rollBack();
            
            // Mark product as sync error
            if (isset($product)) {
                $product->update([
                    'sync_status' => 'error',
                    'sync_error' => $e->getMessage()
                ]);
            }
            
            throw $e;
        }
    }
}
