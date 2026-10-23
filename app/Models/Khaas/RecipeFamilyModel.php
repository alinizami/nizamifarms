<?php

namespace App\Models\Khaas;

use App\Models\CRM\ProductModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 🍱 A preparation the kitchen cooks — "Chicken Cheese Samosa".
 *
 * Its pack sizes ("(8 pcs) - Single Pack", "(12 pcs) - Single Pack") are ordinary
 * products that point here through `t_crm_prod_product.recipe_family_id` and say how
 * many pieces they hold (`pieces_per_pack`). The family owns ONE recipe, written per
 * reference batch in PIECES; see FamilyRecipeModel and App\Services\Khaas\RecipeFamilyService.
 *
 * Membership is the stored link. A product's title only SUGGESTS a family (the product
 * forms pre-fill it), so a rename or a typo never moves a product between families.
 */
class RecipeFamilyModel extends Model
{
    protected $table = 't_crm_khaas_recipe_family';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = ['business_unit_id', 'name', 'is_active', 'created_by'];

    protected $casts = [
        'business_unit_id' => 'integer',
        'is_active'        => 'boolean',
        'created_by'       => 'integer',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(ProductModel::class, 'recipe_family_id');
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(FamilyRecipeModel::class, 'family_id');
    }
}
