<?php

namespace App\Models\HR;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Shared\BaseModel;
use App\Models\SysAdmin\UserModel;
use App\Models\FIN\LedgerModel;

class SalarySlipModel extends BaseModel
{
    use HasFactory;
    
    protected $table = 't_hr_salary_slips';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'salary_month',
        'slip_number',
        // Earnings
        'base_salary',
        'overtime_hours',
        'overtime_amount',
        'bonuses',
        'allowances',
        'other_earnings',
        'other_earnings_desc',
        'gross_salary',
        // Deductions
        'late_minutes',
        // Sep-2026 — the day-review split. `late_minutes` above is NET of minutes a manager
        // forgave, and a slip is frozen forever; these two are what let it still account for
        // a figure smaller than the month's own days. ⚠ Both MUST stay in $fillable or
        // create() silently drops them and the split is lost with no error.
        'late_waived_minutes',
        'late_raw_minutes',
        'late_deduction',
        'absent_days',
        'absent_deduction',
        'salary_advance',
        'loan_installment',
        'tax_deduction',
        'other_deductions',
        'other_deductions_desc',
        'total_deductions',
        // Final
        'net_salary',
        // Attendance
        'working_days',
        'present_days',
        'leave_days',
        'half_days',
        // Overrides
        'late_deduction_overridden',
        'overtime_overridden',
        'absent_deduction_overridden',
        'loan_installment_skipped',
        'has_manual_adjustments',
        'override_notes',
        // Status
        'slip_status',
        'approved_by',
        'approved_at',
        'paid_at',
        'payment_method',
        'ledger_transaction_id',
        // References
        'advance_request_ids',
        'loan_ids',
        // Audit
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'salary_month' => 'date',
        'base_salary' => 'decimal:2',
        'overtime_hours' => 'decimal:2',
        'overtime_amount' => 'decimal:2',
        'bonuses' => 'decimal:2',
        'allowances' => 'decimal:2',
        'other_earnings' => 'decimal:2',
        'gross_salary' => 'decimal:2',
        'late_minutes' => 'decimal:2',
        'late_deduction' => 'decimal:2',
        'absent_deduction' => 'decimal:2',
        'salary_advance' => 'decimal:2',
        'loan_installment' => 'decimal:2',
        'tax_deduction' => 'decimal:2',
        'other_deductions' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_salary' => 'decimal:2',
        'late_deduction_overridden' => 'boolean',
        'overtime_overridden' => 'boolean',
        'absent_deduction_overridden' => 'boolean',
        'loan_installment_skipped' => 'boolean',
        'has_manual_adjustments' => 'boolean',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime'
    ];

    // Status constants
    const STATUS_DRAFT = 'draft';
    const STATUS_APPROVED = 'approved';
    const STATUS_PAID = 'paid';
    const STATUS_CANCELLED = 'cancelled';

    // Relationships

    /**
     * Employee who owns this slip
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'user_id', 'id');
    }

    /**
     * User who approved this slip
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'approved_by', 'id');
    }

    /**
     * User who created this slip
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'created_by', 'id');
    }

    /**
     * Ledger transaction when paid
     */
    public function ledgerTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerModel::class, 'ledger_transaction_id', 'id');
    }

    // Scopes

    /**
     * Draft slips only
     */
    public function scopeDraft($query)
    {
        return $query->where('slip_status', self::STATUS_DRAFT);
    }

    /**
     * Approved slips only
     */
    public function scopeApproved($query)
    {
        return $query->where('slip_status', self::STATUS_APPROVED);
    }

    /**
     * Paid slips only
     */
    public function scopePaid($query)
    {
        return $query->where('slip_status', self::STATUS_PAID);
    }

    /**
     * For a specific user
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * For a specific month
     * Uses date range to handle both old (-01) and new (-02) date formats
     */
    public function scopeForMonth($query, string $month)
    {
        $monthStart = date('Y-m-01', strtotime($month));
        $monthEnd = date('Y-m-t', strtotime($month));
        return $query->whereBetween('salary_month', [$monthStart, $monthEnd]);
    }

    /**
     * Recent slips first
     */
    public function scopeRecent($query)
    {
        return $query->orderBy('salary_month', 'desc')
                    ->orderBy('created_at', 'desc');
    }

    // Helper Methods

    /**
     * Check if slip is draft
     */
    public function isDraft(): bool
    {
        return $this->slip_status === self::STATUS_DRAFT;
    }

    /**
     * Check if slip is approved
     */
    public function isApproved(): bool
    {
        return $this->slip_status === self::STATUS_APPROVED;
    }

    /**
     * Check if slip is paid
     */
    public function isPaid(): bool
    {
        return $this->slip_status === self::STATUS_PAID;
    }

    /**
     * Check if slip is cancelled
     */
    public function isCancelled(): bool
    {
        return $this->slip_status === self::STATUS_CANCELLED;
    }

    /**
     * Can this slip be edited?
     */
    public function canBeEdited(): bool
    {
        return $this->isDraft();
    }

    /**
     * Can this slip be approved?
     */
    public function canBeApproved(): bool
    {
        return $this->isDraft();
    }

    /**
     * Approve this salary slip
     */
    public function approve(int $approverId): bool
    {
        if (!$this->canBeApproved()) {
            return false;
        }

        $this->slip_status = self::STATUS_APPROVED;
        $this->approved_by = $approverId;
        $this->approved_at = now();

        return $this->save();
    }

    /**
     * Mark slip as paid
     */
    public function markAsPaid(int $ledgerTransactionId, string $paymentMethod = 'cash'): bool
    {
        if (!$this->isApproved()) {
            return false;
        }

        $this->slip_status = self::STATUS_PAID;
        $this->paid_at = now();
        $this->payment_method = $paymentMethod;
        $this->ledger_transaction_id = $ledgerTransactionId;

        return $this->save();
    }

    /**
     * Cancel this slip
     */
    public function cancel(): bool
    {
        if ($this->isPaid()) {
            return false; // Cannot cancel paid slips
        }

        $this->slip_status = self::STATUS_CANCELLED;
        return $this->save();
    }

    /**
     * Get formatted month
     */
    public function getFormattedMonthAttribute(): string
    {
        return $this->salary_month->format('F Y');
    }

    /**
     * Get formatted net salary
     */
    public function getFormattedNetSalaryAttribute(): string
    {
        return number_format($this->net_salary, 2) . ' PKR';
    }

    /**
     * Get list of advance request IDs
     */
    public function getAdvanceRequestIdsArray(): array
    {
        if (empty($this->advance_request_ids)) {
            return [];
        }
        return array_map('intval', explode(',', $this->advance_request_ids));
    }

    /**
     * Get list of loan IDs
     */
    public function getLoanIdsArray(): array
    {
        if (empty($this->loan_ids)) {
            return [];
        }
        return array_map('intval', explode(',', $this->loan_ids));
    }

    /**
     * Get detailed breakdown for display
     */
    /**
     * ⭐ The late split to FREEZE on a new slip (Oct-2026) — the server's answer, used by the
     * web form and the phone alike, so neither client can save a split that contradicts itself.
     *
     *   · raw / waived come from the client when it sent them (the calculate endpoint gave
     *     them to the form); an older APK sends neither, so for a whole-month slip the server
     *     reads them from the engine itself — the same attendanceSummary the calculation uses.
     *     A weekly / extra slip (allow_multiple) covers part of a month, so the month's split
     *     would be wrong for it: raw = net, waived 0, exactly as before.
     *   · waived is clamped to [0, raw].
     *   · `late_minutes` itself is never changed here: a manager who typed a different figure
     *     keeps it. lateNote() then says "set by hand" instead of a sum that doesn't add up.
     *
     * @return array{raw:int,waived:int}
     */
    public static function lateSplitForNewSlip(int $userId, string $salaryMonth, $net, $postedRaw, $postedWaived, bool $partOfMonth = false): array
    {
        $net = (int) round((float) ($net ?? 0));
        if ($postedRaw === null && !$partOfMonth) {
            try {
                $att = (new \App\Services\HR\SalaryCalculationService())->attendanceSummary($userId, $salaryMonth);
                if (isset($att['late_raw_minutes'])) {
                    $postedRaw = (int) $att['late_raw_minutes'];
                    $postedWaived = (int) ($att['late_waived_minutes'] ?? 0);
                }
            } catch (\Throwable $e) { /* fall through to raw = net */ }
        }
        $raw = $postedRaw !== null ? max(0, (int) $postedRaw) : $net;
        $waived = max(0, min($raw, (int) ($postedWaived ?? 0)));
        return ['raw' => $raw, 'waived' => $waived];
    }

    /**
     * The one line that explains a slip's late figure, or null when the figure explains itself.
     * Same wording on the web slip page and (via getDetailedBreakdown) the phone.
     */
    public static function lateNote($net, $raw, $waived): ?string
    {
        if ($raw === null) { return null; }                       // slip older than the split
        $net = (int) round((float) $net); $raw = (int) $raw; $waived = (int) ($waived ?? 0);
        if ($raw - $waived === $net) {
            return $waived > 0 ? $raw . ' mins late · ' . $waived . ' waived by a manager' : null;
        }
        return $raw . ' mins late' . ($waived > 0 ? ' · ' . $waived . ' waived by a manager' : '')
            . ' · set to ' . $net . ' by hand on this slip';
    }

    /** Does this slip have any lateness worth a line, even with no money taken for it? */
    public function hasLateness(): bool
    {
        return (float) $this->late_minutes > 0 || (int) ($this->late_raw_minutes ?? 0) > 0
            || (float) $this->late_deduction > 0;
    }

    public function getDetailedBreakdown(): array
    {
        return [
            'slip_id' => $this->id,
            'slip_number' => $this->slip_number,
            'employee_name' => $this->employee->fullname ?? 'Unknown',
            'salary_month' => $this->formatted_month,
            'status' => $this->slip_status,
            
            // Earnings
            'earnings' => [
                'base_salary' => $this->base_salary,
                'overtime_hours' => $this->overtime_hours,
                'overtime_amount' => $this->overtime_amount,
                'bonuses' => $this->bonuses,
                'allowances' => $this->allowances,
                'other_earnings' => $this->other_earnings,
                'other_earnings_desc' => $this->other_earnings_desc,
                'gross_salary' => $this->gross_salary
            ],
            
            // Deductions
            'deductions' => [
                'late_minutes' => $this->late_minutes,
                // Oct-2026 — the day-review split frozen on the slip (null on slips made before
                // the columns existed; the phone then shows the net figure alone, as before).
                'late_waived_minutes' => $this->late_waived_minutes,
                'late_raw_minutes' => $this->late_raw_minutes,
                // Oct-2026 — the explanation line, worded once here for every surface.
                'late_note' => self::lateNote($this->late_minutes, $this->late_raw_minutes, $this->late_waived_minutes),
                'late_deduction' => $this->late_deduction,
                'absent_days' => $this->absent_days,
                'absent_deduction' => $this->absent_deduction,
                'salary_advance' => $this->salary_advance,
                'loan_installment' => $this->loan_installment,
                'tax_deduction' => $this->tax_deduction,
                'other_deductions' => $this->other_deductions,
                'other_deductions_desc' => $this->other_deductions_desc,
                'total_deductions' => $this->total_deductions
            ],
            
            // Attendance
            'attendance' => [
                'working_days' => $this->working_days,
                'present_days' => $this->present_days,
                'leave_days' => $this->leave_days,
                'half_days' => $this->half_days,
                'absent_days' => $this->absent_days
            ],
            
            // Overrides
            'overrides' => [
                'late_deduction_overridden' => $this->late_deduction_overridden,
                'overtime_overridden' => $this->overtime_overridden,
                'absent_deduction_overridden' => $this->absent_deduction_overridden,
                'loan_installment_skipped' => $this->loan_installment_skipped,
                'has_manual_adjustments' => $this->has_manual_adjustments,
                'override_notes' => $this->override_notes
            ],
            
            // Final
            'net_salary' => $this->net_salary,
            'formatted_net_salary' => $this->formatted_net_salary,
            
            // Metadata
            'approved_by_name' => $this->approver->fullname ?? null,
            'approved_at' => $this->approved_at?->format('M d, Y H:i'),
            'paid_at' => $this->paid_at?->format('M d, Y H:i'),
            'payment_method' => $this->payment_method,
            'created_by_name' => $this->creator->fullname ?? 'System'
        ];
    }

    /**
     * Generate unique slip number
     */
    public static function generateSlipNumber(int $userId, string $month): string
    {
        $monthFormatted = date('Ym', strtotime($month));
        
        $lastSlip = self::where('user_id', $userId)
            ->whereYear('salary_month', date('Y', strtotime($month)))
            ->orderBy('id', 'desc')
            ->first();

        $sequence = $lastSlip ? (intval(substr($lastSlip->slip_number, -3)) + 1) : 1;
        
        return 'SAL-' . $userId . '-' . $monthFormatted . '-' . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Check if slip already exists for user and month
     * Uses date range to handle both old (-01) and new (-02) date formats
     */
    public static function existsForUserMonth(int $userId, string $month, ?string $status = null): bool
    {
        // Use year-month range comparison instead of exact date match
        $monthStart = date('Y-m-01', strtotime($month));
        $monthEnd = date('Y-m-t', strtotime($month));
        
        $query = self::where('user_id', $userId)
            ->whereBetween('salary_month', [$monthStart, $monthEnd]);
        
        if ($status) {
            $query->where('slip_status', $status);
        } else {
            // Exclude cancelled
            $query->where('slip_status', '!=', self::STATUS_CANCELLED);
        }
        
        return $query->exists();
    }
}

