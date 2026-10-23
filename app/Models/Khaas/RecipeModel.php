<?php

namespace App\Models\Khaas;

use App\Models\CRM\ProductModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of one frozen product's recipe, written as a REFERENCE BATCH.
 *
 * ⭐⭐ WHY A BATCH AND NOT A PACKET. Qasim does not know "112 g of chicken per samosa
 * pack"; he knows "9 kg of chicken makes 80 packs". So that is what he types, and the
 * per-pack figure is DERIVED (`qty_per_basis / basis_packets`) and never stored. One
 * number to correct instead of two that can disagree, and the same denominator — packs
 * — that "Made" is already counted in everywhere else in Frozen.
 *
 * ⭐ WHY VERSIONS. Consumption rows already written keep pointing at the version they
 * were made with, so correcting a recipe never silently restates last month. Restating
 * is the deliberate "Re-apply recipe for this month" action and nothing else.
 *
 * A product with no current recipe is not an error: it simply produces no consumption
 * and shows "no recipe" on the cost tile. Making packs must never be blocked by
 * paperwork.
 */
class RecipeModel extends Model
{
    protected $table = 't_crm_khaas_recipe';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'product_id',
        'version',
        'basis_packets',
        'effective_from',
        'is_current',
        'note',
        'created_by',
        // 🍱 Oct-19: set when this row is an exact snapshot of a FAMILY recipe version, written by
        //    RecipeFamilyService (never by hand). member qty = family qty × family_scale.
        'family_recipe_id',
        'family_scale',
    ];

    protected $casts = [
        'product_id'     => 'integer',
        'version'        => 'integer',
        'basis_packets'  => 'integer',
        'effective_from' => 'date',
        'is_current'     => 'boolean',
        'created_by'     => 'integer',
        'family_recipe_id' => 'integer',
        'family_scale'   => 'integer',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(RecipeLineModel::class, 'recipe_id')->orderBy('sort_order')->orderBy('id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class, 'product_id');
    }

    /**
     * The version that governs a given date.
     *
     * Newest `effective_from` on or before the date wins; if a product's first recipe
     * was written after some older stock_in, that older stock_in has no recipe and is
     * left alone rather than being costed with a recipe that did not exist yet.
     */
    public static function currentFor(int $productId, ?string $onDate = null): ?self
    {
        $q = self::where('product_id', $productId);

        if ($onDate) {
            $q->whereDate('effective_from', '<=', $onDate);
        }

        return $q->orderByDesc('effective_from')->orderByDesc('version')->first();
    }

    /** Per-pack quantity of one line, in base units. */
    public function perPacket(RecipeLineModel $line): float
    {
        $basis = max(1, (int) $this->basis_packets);
        return round(((float) $line->qty_per_basis) / $basis, 6);
    }
}
