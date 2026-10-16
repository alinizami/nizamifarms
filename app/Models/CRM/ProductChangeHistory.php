<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\SysAdmin\UserModel;

class ProductChangeHistory extends Model
{
    use HasFactory;
    
    protected $table = 't_crm_prod_product_change_history';
    protected $primaryKey = 'id';
    public $timestamps = false; // We only use created_at
    
    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;

    protected $fillable = [
        'product_id',
        'variant_id',
        'user_id',
        'change_type',
        'field_name',
        'old_value',
        'new_value',
        'change_source',
        'ip_address',
        'user_agent',
        'notes',
        'created_at'
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    // Change type constants
    const TYPE_SKU_CHANGE = 'sku_change';
    const TYPE_PRICE_CHANGE = 'price_change';
    const TYPE_CATEGORY_CHANGE = 'category_change';
    const TYPE_NAME_CHANGE = 'name_change';
    const TYPE_VENDOR_CHANGE = 'vendor_change';
    const TYPE_STATUS_CHANGE = 'status_change';
    const TYPE_INVENTORY_CHANGE = 'inventory_change';
    const TYPE_WEIGHT_FACTOR_CHANGE = 'weight_factor_change';
    const TYPE_UNIT_WEIGHT_CHANGE = 'unit_weight_change';
    const TYPE_LEAN_STATUS_CHANGE = 'lean_status_change';
    const TYPE_CZERLOP_CHANGE = 'czerlop_change';
    const TYPE_SELL_UNIT_CHANGE = 'sell_unit_change';
    const TYPE_BUNDLE_CHANGE = 'bundle_change';
    const TYPE_PRODUCT_CREATED = 'product_created';
    const TYPE_VARIANT_CREATED = 'variant_created';
    const TYPE_VARIANT_DELETED = 'variant_deleted';

    // Source constants
    const SOURCE_WEB = 'web';
    const SOURCE_MOBILE = 'mobile';
    const SOURCE_API = 'api';
    const SOURCE_SYSTEM = 'system';

    // Relationships
    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class, 'product_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariantModel::class, 'variant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'user_id');
    }

    /**
     * Log a product change
     */
    public static function logChange(array $data): self
    {
        $request = request();
        
        return static::create([
            'product_id' => $data['product_id'],
            'variant_id' => $data['variant_id'] ?? null,
            'user_id' => $data['user_id'] ?? (auth()->check() ? auth()->id() : null),
            'change_type' => $data['change_type'],
            'field_name' => $data['field_name'],
            'old_value' => is_array($data['old_value'] ?? null) ? json_encode($data['old_value']) : ($data['old_value'] ?? null),
            'new_value' => is_array($data['new_value'] ?? null) ? json_encode($data['new_value']) : ($data['new_value'] ?? null),
            'change_source' => $data['change_source'] ?? self::detectSource(),
            'ip_address' => $request ? $request->ip() : null,
            'user_agent' => $request ? substr($request->userAgent() ?? '', 0, 500) : null,
            'notes' => $data['notes'] ?? null,
            'created_at' => now(),
        ]);
    }

    /**
     * Log multiple changes at once
     */
    public static function logChanges(int $productId, array $changes, ?int $variantId = null): void
    {
        foreach ($changes as $change) {
            static::logChange([
                'product_id' => $productId,
                'variant_id' => $variantId ?? ($change['variant_id'] ?? null),
                'change_type' => $change['change_type'],
                'field_name' => $change['field_name'],
                'old_value' => $change['old_value'] ?? null,
                'new_value' => $change['new_value'] ?? null,
                'notes' => $change['notes'] ?? null,
                'change_source' => $change['change_source'] ?? null,
            ]);
        }
    }

    /**
     * Detect the source of the change based on request
     */
    public static function detectSource(): string
    {
        $request = request();
        if (!$request) {
            return self::SOURCE_SYSTEM;
        }

        $userAgent = strtolower($request->userAgent() ?? '');
        
        // Check for mobile app
        if (str_contains($userAgent, 'nizamifarms') || 
            str_contains($userAgent, 'okhttp') || 
            str_contains($userAgent, 'expo') ||
            $request->hasHeader('X-Mobile-App')) {
            return self::SOURCE_MOBILE;
        }

        // Check for API request
        if ($request->expectsJson() || 
            $request->is('api/*') || 
            $request->header('X-Requested-With') === 'XMLHttpRequest') {
            return self::SOURCE_API;
        }

        return self::SOURCE_WEB;
    }

    /**
     * Get human-readable change type label
     */
    public function getChangeTypeLabelAttribute(): string
    {
        return match($this->change_type) {
            self::TYPE_SKU_CHANGE => 'SKU Changed',
            self::TYPE_PRICE_CHANGE => 'Price Changed',
            self::TYPE_CATEGORY_CHANGE => 'Category Changed',
            self::TYPE_NAME_CHANGE => 'Name Changed',
            self::TYPE_VENDOR_CHANGE => 'Vendor Changed',
            self::TYPE_STATUS_CHANGE => 'Status Changed',
            self::TYPE_INVENTORY_CHANGE => 'Inventory Changed',
            self::TYPE_WEIGHT_FACTOR_CHANGE => 'Weight Factor Changed',
            self::TYPE_UNIT_WEIGHT_CHANGE => 'Unit Weight (kg) Changed',
            self::TYPE_LEAN_STATUS_CHANGE => 'Lean Status Changed',
            self::TYPE_CZERLOP_CHANGE => 'Czerlop ID Changed',
            self::TYPE_SELL_UNIT_CHANGE => 'Sold By Changed (kg / pcs)',
            self::TYPE_PRODUCT_CREATED => 'Product Created',
            self::TYPE_VARIANT_CREATED => 'Variant Added',
            self::TYPE_VARIANT_DELETED => 'Variant Removed',
            default => ucwords(str_replace('_', ' ', $this->change_type)),
        };
    }

    /**
     * Get formatted change description
     */
    public function getDescriptionAttribute(): string
    {
        $old = $this->old_value ?? 'empty';
        $new = $this->new_value ?? 'empty';
        
        // Truncate long values
        if (strlen($old) > 50) $old = substr($old, 0, 50) . '...';
        if (strlen($new) > 50) $new = substr($new, 0, 50) . '...';

        return match($this->change_type) {
            self::TYPE_PRODUCT_CREATED => "Product was created",
            self::TYPE_VARIANT_CREATED => "New variant added",
            self::TYPE_VARIANT_DELETED => "Variant removed",
            default => "{$this->field_name}: '{$old}' → '{$new}'",
        };
    }

    /**
     * Scope to get recent changes for a product
     */
    public function scopeForProduct($query, int $productId, int $limit = 10)
    {
        return $query->where('product_id', $productId)
            ->orderBy('created_at', 'desc')
            ->limit($limit);
    }

    /**
     * Get the latest changes for a product with user info
     */
    public static function getRecentChanges(int $productId, int $limit = 3): array
    {
        $changes = static::with('user:id,fullname,email')
            ->where('product_id', $productId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return $changes->map(function ($change) {
            $userName = 'System';
            if ($change->user) {
                $userName = trim($change->user->fullname ?? '');
                if (empty($userName)) {
                    $userName = $change->user->email ?? 'Unknown User';
                }
            }

            return [
                'id' => $change->id,
                'change_type' => $change->change_type,
                'change_type_label' => $change->change_type_label,
                'field_name' => $change->field_name,
                'old_value' => $change->old_value,
                'new_value' => $change->new_value,
                'description' => $change->description,
                'change_source' => $change->change_source,
                'user_name' => $userName,
                'created_at' => $change->created_at->format('Y-m-d H:i:s'),
                'created_at_human' => $change->created_at->diffForHumans(),
            ];
        })->toArray();
    }
}

