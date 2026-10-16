<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyOrderLineItemModel extends BaseModel
{
    use HasFactory, Notifiable;

    protected $table = 't_crm_shopify_order_line_item';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'order_id',
        'product_id',
        'variant_id',
        'sku',
        'name',
        'quantity',
        'unit_price',
        'line_subtotal',
        'discount_amount',
        'tax_amount',
        'line_total',
        'preparation_status',
        'created_by',
        'updated_by',
    ];

    /**
     * The accessors to append to the model's array form for mobile app compatibility
     */
    protected $appends = ['product_name', 'title', 'unit_price_formatted', 'total_formatted', 'bundle_hint'];

    /**
     * 📦 "= 3 × Chicken Samosa @ Rs 400" for a BUNDLE sku, null otherwise — what this
     * staging line turns into when the order is accepted (App\Services\CRM\ProductBundle).
     * Appended so every surface that serialises a staging line (web list + detail,
     * phone list) gets it without its own lookup.
     */
    public function getBundleHintAttribute(): ?string
    {
        return \App\Services\CRM\ProductBundle::hintForLine($this->sku, $this->quantity);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class, 'product_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariantModel::class, 'variant_id');
    }

    /**
     * Accessor for product_name (mobile app expects this field)
     */
    public function getProductNameAttribute(): ?string
    {
        return $this->name;
    }

    /**
     * Accessor for title (alternative field name for product name)
     */
    public function getTitleAttribute(): ?string
    {
        return $this->name;
    }

    /**
     * Accessor for formatted unit price
     */
    public function getUnitPriceFormattedAttribute(): string
    {
        return 'Rs. ' . number_format($this->unit_price ?? 0, 2);
    }

    /**
     * Accessor for formatted total
     */
    public function getTotalFormattedAttribute(): string
    {
        return 'Rs. ' . number_format($this->line_total ?? 0, 2);
    }

    public function getDisplaySkuAttribute(): string
    {
        $sku = trim((string)($this->sku ?? ''));
        if ($sku !== '') {
            return $sku;
        }

        if ($this->relationLoaded('variant') && $this->variant) {
            $variantSku = trim((string)($this->variant->sku ?? ''));
            if ($variantSku !== '') {
                return $variantSku;
            }
        }

        if ($this->variant_id) {
            $variantSku = trim((string)(ProductVariantModel::where('id', $this->variant_id)->value('sku') ?? ''));
            if ($variantSku !== '') {
                return $variantSku;
            }
        }

        return '';
    }
}


