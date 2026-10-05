<?php

namespace App\Models\Khaas;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The start line for "how much is left", and the physical counts that reset it.
 *
 * Remaining is CUMULATIVE — a January bag of salt is still on the shelf in March — so
 * without a row here an ingredient honestly reports "not tracked" instead of a number
 * that looks precise and is wrong.
 *
 * A `count` RE-ANCHORS the running figure on its date: from then on, remaining is the
 * counted number plus what was bought after it, minus what was used after it.
 *
 * ⚠ It does NOT write a shortfall off as consumption, and it does not cost one. That is
 *   deliberate and it is NOT what the Supplies count does — there, the money is already
 *   spent on a packet that has left the shelf, so writing it off is just bookkeeping.
 *   Here, inventing consumption would inflate a cost per pack that is already an
 *   estimate, and nobody could tell the invented part from the measured part afterwards.
 *   A gap between the count and what the recipes expected is a fact worth SHOWING, not
 *   a number worth manufacturing.
 *
 * ⚠ "Strictly after" matters: a count already reflects everything that happened on its
 *   own day, so that day's purchases and usage are not applied on top of it.
 */
class IngredientOpeningModel extends Model
{
    protected $table = 't_crm_khaas_ingredient_opening';
    protected $primaryKey = 'id';
    public $timestamps = false;

    public const KIND_OPENING = 'opening';
    public const KIND_COUNT   = 'count';
    /**
     * ❄ Sep-30: a weigh-in that is RECORDED (its gap is shown) but does not move the figure.
     * Saved for someone without `adjust_khaas_stock`. Never an anchor — see IngredientStockService.
     */
    public const KIND_CHECK   = 'check';

    protected $fillable = [
        'ingredient_id',
        'kind',
        'counted_on',
        'qty_base',
        'rupees',
        'note',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'ingredient_id' => 'integer',
        'counted_on'    => 'date',
        'qty_base'      => 'float',
        'rupees'        => 'float',
        'created_by'    => 'integer',
    ];

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(IngredientModel::class, 'ingredient_id');
    }
}
