<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderLineItemModel extends BaseModel
{
    use HasFactory, Notifiable;
    
    protected $table = 't_crm_prod_order_line_item';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'order_id',
        'external_line_item_id',
        'product_id',
        'variant_id',
        'sku',
        'name',
        'vendor',
        'quantity',
        'original_quantity',          // first quantity before any post-order change (NULL = never changed) — see supportsOriginalQuantity()
        'quantity_source',           // 'manual' | 'barcode' (weighed) | 'box_scan' (counted box confirmed)
        'quantity_updated_by',
        'quantity_updated_at',
        'quantity_scanned_barcode',
        'unit_price',
        'line_subtotal',
        'discount_amount',
        'tax_amount',
        'line_total',
        'preparation_status',
        'inventory_deducted',
        'is_free',
        'qurbani_day',
        'qurbani_slot',
        'qurbani_slot_start_minute',
        'qurbani_slot_end_minute',
        'qurbani_region',
        'qurbani_sub_region',
        'qurbani_delivery_type',
        'qurbani_type',
        'qurbani_paya',
        'qurbani_item_status',
        'qurbani_assigned_rider_user_id',
        'qurbani_status_updated_at',
        'qurbani_status_updated_by',
        'instructions',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'original_quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'line_subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'quantity_updated_at' => 'datetime'
    ];

    /**
     * Does t_crm_prod_order_line_item have `original_quantity` yet? (Oct-19-2026,
     * database/migrations/line_item_original_quantity_oct2026.sql.)
     *
     * Every writer and every explicit column list checks this first, so web files
     * uploaded before the SQL simply record and show nothing instead of failing an
     * INSERT / SELECT on a column that is not there. Memoised per request.
     */
    public static function supportsOriginalQuantity(): bool
    {
        static $supported = null;
        if ($supported === null) {
            try {
                $supported = \Illuminate\Support\Facades\Schema::hasColumn('t_crm_prod_order_line_item', 'original_quantity');
            } catch (\Throwable $e) {
                $supported = false;
            }
        }
        return $supported;
    }

    /**
     * The quantity to keep as "originally ordered" when a line's quantity moves
     * from $oldQuantity to $newQuantity. The one already kept always wins — it is
     * written ONCE and never overwritten; otherwise the old quantity, but only
     * when the number really moves. NULL = nothing to keep.
     */
    public static function originalToKeep($alreadyKept, float $oldQuantity, float $newQuantity): ?float
    {
        if ($alreadyKept !== null && $alreadyKept !== '') {
            return (float) $alreadyKept;
        }
        if (abs($oldQuantity - $newQuantity) < 0.0005 || $oldQuantity <= 0) {
            return null;
        }
        return round($oldQuantity, 3);
    }

    // Relationships
    public function order(): BelongsTo
    {
        return $this->belongsTo(OrderModel::class, 'order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class, 'product_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariantModel::class, 'variant_id');
    }

    /** Who last set the quantity (barcode-qty feature). Serializes as `qty_updater`. */
    public function qtyUpdater(): BelongsTo
    {
        return $this->belongsTo(\App\Models\SysAdmin\UserModel::class, 'quantity_updated_by');
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

    // Helper methods
    public function getCalculatedTotalAttribute(): float
    {
        return ($this->line_subtotal ?? 0) - ($this->discount_amount ?? 0) + ($this->tax_amount ?? 0);
    }

    /**
     * Deduct inventory for this line item (when marked as prepared).
     * Only deducts if not already deducted (inventory_deducted = 0).
     * Returns true if inventory was actually deducted, false if skipped.
     */
    public function deductInventory(): bool
    {
        if ($this->inventory_deducted) {
            return false; // Already deducted — prevent double deduction
        }

        $variantId = $this->variant_id;
        $quantity = $this->quantity ?? 0;

        // Fallback: resolve variant by SKU if variant_id is missing
        if (!$variantId && !empty($this->sku)) {
            $resolvedVariant = ProductVariantModel::where('sku', $this->sku)->first();
            if ($resolvedVariant) {
                $variantId = $resolvedVariant->id;
            }
        }

        if ($variantId && $quantity > 0) {
            $variant = ProductVariantModel::find($variantId);
            if ($variant) {
                $variant->decrement('inventory_quantity', $quantity);

                // Sync product.total_inventory from variants
                if ($variant->product_id) {
                    $product = ProductModel::find($variant->product_id);
                    if ($product) {
                        $newTotal = ProductVariantModel::where('product_id', $product->id)
                            ->sum('inventory_quantity');
                        $product->update(['total_inventory' => $newTotal]);
                    }
                }

                $this->inventory_deducted = 1;
                $this->save();

                \Log::info('Inventory deducted (mark as prepared)', [
                    'line_item_id' => $this->id,
                    'order_id' => $this->order_id,
                    'variant_id' => $variantId,
                    'product_id' => $variant->product_id,
                    'sku' => $variant->sku,
                    'quantity_deducted' => $quantity,
                    'new_variant_inventory' => $variant->fresh()->inventory_quantity,
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * Restore inventory for this line item (when un-prepared or order cancelled).
     * Only restores if previously deducted (inventory_deducted = 1).
     * Returns true if inventory was actually restored, false if skipped.
     */
    public function restoreInventory(): bool
    {
        if (!$this->inventory_deducted) {
            return false; // Nothing to restore
        }

        $variantId = $this->variant_id;
        $quantity = $this->quantity ?? 0;

        // Fallback: resolve variant by SKU if variant_id is missing
        if (!$variantId && !empty($this->sku)) {
            $resolvedVariant = ProductVariantModel::where('sku', $this->sku)->first();
            if ($resolvedVariant) {
                $variantId = $resolvedVariant->id;
            }
        }

        if ($variantId && $quantity > 0) {
            $variant = ProductVariantModel::find($variantId);
            if ($variant) {
                $variant->increment('inventory_quantity', $quantity);

                // Sync product.total_inventory from variants
                if ($variant->product_id) {
                    $product = ProductModel::find($variant->product_id);
                    if ($product) {
                        $newTotal = ProductVariantModel::where('product_id', $product->id)
                            ->sum('inventory_quantity');
                        $product->update(['total_inventory' => $newTotal]);
                    }
                }

                $this->inventory_deducted = 0;
                $this->save();

                \Log::info('Inventory restored', [
                    'line_item_id' => $this->id,
                    'order_id' => $this->order_id,
                    'variant_id' => $variantId,
                    'product_id' => $variant->product_id,
                    'sku' => $variant->sku,
                    'quantity_restored' => $quantity,
                    'new_variant_inventory' => $variant->fresh()->inventory_quantity,
                ]);

                return true;
            }
        }

        return false;
    }

    // Automatically calculate line_total if not provided
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($lineItem) {
            if (is_null($lineItem->line_total)) {
                $lineItem->line_total = $lineItem->getCalculatedTotalAttribute();
            }
        });

        static::updating(function ($lineItem) {
            if (is_null($lineItem->line_total)) {
                $lineItem->line_total = $lineItem->getCalculatedTotalAttribute();
            }
        });

        // Phase 4 (May-2026) — keep qurbani_slot_start_minute /
        // qurbani_slot_end_minute in sync with qurbani_slot. Fires
        // on both create and update so any time the slot string
        // changes the derived integers re-derive themselves and
        // dashboards never read stale values.
        //
        // Resolution priority:
        //   1. Explicit override in t_crm_qurbani_field_options
        //      (set via the Qurbani Settings UI)
        //   2. QurbaniSlotParser auto-detect from the slot string
        //
        // We only re-parse when qurbani_slot is dirty (or on
        // creation when it was set explicitly) — saves a
        // micro-cycle when other fields update.
        //
        // For raw DB::table()->update() paths that bypass Eloquent
        // (we still have a few in qurbani controllers), the
        // backfill artisan command can be re-run any time.
        static::saving(function ($lineItem) {
            if (!$lineItem->exists || $lineItem->isDirty('qurbani_slot')) {
                $slot = $lineItem->qurbani_slot;
                if ($slot === null || trim((string) $slot) === '') {
                    $lineItem->qurbani_slot_start_minute = null;
                    $lineItem->qurbani_slot_end_minute = null;
                } else {
                    // 1) try the settings override first
                    $override = \Illuminate\Support\Facades\DB::table('t_crm_qurbani_field_options')
                        ->where('field_name', 'qurbani_slot')
                        ->where('option_value', $slot)
                        ->where('is_active', 1)
                        ->whereNotNull('slot_end_minute')
                        ->select('slot_start_minute', 'slot_end_minute')
                        ->first();
                    if ($override) {
                        $lineItem->qurbani_slot_start_minute = $override->slot_start_minute;
                        $lineItem->qurbani_slot_end_minute   = $override->slot_end_minute;
                    } else {
                        // 2) parser fallback
                        [$start, $end] = \App\Services\QurbaniSlotParser::parse($slot);
                        $lineItem->qurbani_slot_start_minute = $start;
                        $lineItem->qurbani_slot_end_minute   = $end;
                    }
                }
            }
        });
    }
}
