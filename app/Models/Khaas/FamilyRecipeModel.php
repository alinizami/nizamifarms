<?php

namespace App\Models\Khaas;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 🍱 One version of a FAMILY recipe: what one reference batch of `basis_pieces` pieces uses.
 *
 * ⭐ The benchmark is the PIECE, not a pack size. Qasim keeps typing what one batch uses;
 *   the only question that changed is "how many PIECES does it make?". Per piece and per
 *   pack of any size are derived, never stored:
 *       per pack = qty_per_basis × pieces_per_pack ÷ basis_pieces   (one step, one rounding)
 *
 * Each pack size gets an exact per-product snapshot of every version (RecipeModel with
 * `family_recipe_id`), so consumption, costs and Month Review read what they always read.
 */
class FamilyRecipeModel extends Model
{
    protected $table = 't_crm_khaas_family_recipe';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'family_id', 'version', 'basis_pieces', 'effective_from', 'is_current', 'note', 'created_by',
    ];

    protected $casts = [
        'family_id'      => 'integer',
        'version'        => 'integer',
        'basis_pieces'   => 'integer',
        'effective_from' => 'date',
        'is_current'     => 'boolean',
        'created_by'     => 'integer',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(FamilyRecipeLineModel::class, 'family_recipe_id')->orderBy('sort_order')->orderBy('id');
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(RecipeFamilyModel::class, 'family_id');
    }

    /** The version that governs a date — newest effective_from on or before it. */
    public static function currentFor(int $familyId, ?string $onDate = null): ?self
    {
        $q = self::where('family_id', $familyId);
        if ($onDate) {
            $q->whereDate('effective_from', '<=', $onDate);
        }
        return $q->orderByDesc('effective_from')->orderByDesc('version')->first();
    }
}
