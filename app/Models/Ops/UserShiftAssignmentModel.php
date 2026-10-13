<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;

class UserShiftAssignmentModel extends Model
{
    protected $table = 't_ops_user_shift_assignment';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'shift_template_id',
        'location_id',
        'effective_from',
        'effective_to',
        'created_by',
        'updated_by',
        'notified_at',
        'acknowledged_at',
        /**
         * 🔁 Weekly days (Oct-2026) — "different on some days" on THIS row. JSON, NULL = none.
         * Read through App\Services\Ops\WeekdayPattern only. ⚠ Written only when the column
         * exists (ShiftController::withPattern) — the PHP may go up before the SQL.
         */
        'day_pattern',
        /**
         * ⚠ Oct-2026: 1 = a temporary change (one day / date range / workshop pin), 0 = a regular
         * shift (open, or closed because a newer regular began). NULL = written before the column
         * existed → ShiftResolutionService::closedRegularIds() falls back to the audit log.
         */
        'is_temporary',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'notified_at' => 'datetime',
        'acknowledged_at' => 'datetime'
    ];

    /**
     * Relationships
     */
    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    public function shiftTemplate()
    {
        return $this->belongsTo(ShiftTemplateModel::class, 'shift_template_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_by');
    }

    /**
     * Check if this assignment is currently effective
     * 
     * @param string|null $date Date in Y-m-d format, defaults to today
     * @return bool
     */
    public function isEffective(?string $date = null): bool
    {
        $checkDate = $date ? new \DateTime($date) : new \DateTime();
        
        $from = $this->effective_from ? new \DateTime($this->effective_from->format('Y-m-d')) : null;
        $to = $this->effective_to ? new \DateTime($this->effective_to->format('Y-m-d')) : null;

        // If effective_from is set and check date is before it, not effective
        if ($from && $checkDate < $from) {
            return false;
        }

        // If effective_to is set and check date is after it, not effective
        if ($to && $checkDate > $to) {
            return false;
        }

        return true;
    }

    /**
     * Scope for currently effective assignments
     */
    public function scopeEffective($query, ?string $date = null)
    {
        $checkDate = $date ?? now()->format('Y-m-d');
        
        return $query->where(function($q) use ($checkDate) {
            $q->where(function($q2) use ($checkDate) {
                // effective_from is null OR <= check date
                $q2->whereNull('effective_from')
                   ->orWhere('effective_from', '<=', $checkDate);
            })->where(function($q2) use ($checkDate) {
                // effective_to is null OR >= check date
                $q2->whereNull('effective_to')
                   ->orWhere('effective_to', '>=', $checkDate);
            });
        });
    }

    // NOTE: shift resolution goes through ShiftResolutionService::getUserShift(),
    // which applies the latest-effective-first ordering and fallbacks. Do not add a
    // shortcut resolver here — it would miss that ordering and drift from the service.
}



