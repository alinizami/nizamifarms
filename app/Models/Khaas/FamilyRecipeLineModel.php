<?php

namespace App\Models\Khaas;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One ingredient inside one family recipe version — `qty_per_basis` covers the WHOLE batch, in base units. */
class FamilyRecipeLineModel extends Model
{
    protected $table = 't_crm_khaas_family_recipe_line';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = ['family_recipe_id', 'ingredient_id', 'qty_per_basis', 'is_optional', 'sort_order', 'created_at'];

    protected $casts = [
        'family_recipe_id' => 'integer',
        'ingredient_id'    => 'integer',
        'qty_per_basis'    => 'float',
        'is_optional'      => 'boolean',
        'sort_order'       => 'integer',
    ];

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(IngredientModel::class, 'ingredient_id');
    }
}
