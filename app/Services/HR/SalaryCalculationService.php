<?php

namespace App\Services\HR;

use App\Models\HR\EmployeeProfileModel;
use App\Models\HR\EmployeeLoanModel;
use App\Models\HR\SalarySlipModel;
use App\Models\Request\RequestModel;
use App\Services\ShiftResolutionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalaryCalculationService
{
    /**
     * Calculate salary for a given user and month
     * Returns detailed breakdown of earnings and deductions
     *
     * @param int $userId
     * @param string $month Format: YYYY-MM-01
     * @param array $overrides Manual overrides from manager
     * @return array
     */
    public function calculateSalary(int $userId, string $month, array $overrides = []): array
    {
        try {
            // 1. Get employee profile
            $profile = EmployeeProfileModel::with('user')->where('user_id', $userId)->first();
            if (!$profile || !$profile->hasSalaryConfigured()) {
                return $this->errorResponse('Employee salary not configured');
            }

            // 2. Get attendance data for the month
            $attendanceData = $this->getAttendanceData($userId, $month);

            // 3. Get salary advances for the month
            $advances = $this->getSalaryAdvances($userId, $month);

            // 4. Get active loans
            $loans = $this->getActiveLoans($userId);

            // 5. Calculate earnings
            $earnings = $this->calculateEarnings($profile, $attendanceData, $overrides);

            // 6. Calculate deductions
            $deductions = $this->calculateDeductions($profile, $attendanceData, $advances, $loans, $overrides);

            // 7. Calculate net salary
            $netSalary = $earnings['gross_salary'] - $deductions['total_deductions'];

            return [
                'success' => true,
                'user_id' => $userId,
                'month' => $month,
                'salary_month' => $month,
                'employee' => [
                    'id' => $profile->user->id,
                    'fullname' => $profile->user->fullname,
                    'email' => $profile->user->email ?? ''
                ],
                'profile' => [
                    'employee_code' => $profile->employee_code,
                    'designation' => $profile->designation,
                    'department' => $profile->department
                ],
                'employee_profile' => [
                    'base_salary' => $profile->base_salary,
                    'overtime_rate' => $profile->overtime_rate,
                    'late_deduction_rate' => $profile->late_deduction_rate,
                    'currency' => $profile->salary_currency
                ],
                'base_salary' => $profile->base_salary,
                'overtime_hours' => $earnings['overtime_hours'],
                'overtime_amount' => $earnings['overtime_amount'],
                'bonuses' => $earnings['bonuses'],
                'allowances' => $earnings['allowances'],
                'other_earnings' => $earnings['other_earnings'],
                'gross_salary' => $earnings['gross_salary'],
                
                // Top-level attendance fields (for frontend compatibility)
                'working_days' => $attendanceData['working_days'],
                'present_days' => $attendanceData['present_days'],
                'absent_days' => $attendanceData['absent_days'],
                'leave_days' => $attendanceData['leave_days'],
                'late_minutes' => $attendanceData['late_minutes'],
                // Oct-2026 — beside it, so the phone's slip form can show and freeze the waive.
                'late_waived_minutes' => (int) ($attendanceData['late_waived_minutes'] ?? 0),
                'late_raw_minutes' => (int) ($attendanceData['late_raw_minutes'] ?? $attendanceData['late_minutes']),
                'overtime_minutes' => $attendanceData['overtime_minutes'],

                // Top-level deduction fields
                'late_deduction' => $deductions['late_deduction'],
                'absent_deduction' => $deductions['absent_deduction'],
                'salary_advance' => $deductions['salary_advance'],
                'loan_installment' => $deductions['loan_installment'],
                'tax_deduction' => $deductions['tax_deduction'],
                'other_deductions' => $deductions['other_deductions'],
                'total_deductions' => $deductions['total_deductions'],
                
                // CRITICAL: IDs needed for settlement tracking (must be at top level for frontend)
                'advance_request_ids' => $deductions['advance_request_ids'],
                'loan_ids' => $deductions['loan_ids'],
                
                // Nested objects (for detailed breakdown)
                'attendance' => $attendanceData,
                'earnings' => $earnings,
                'deductions' => $deductions,
                'net_salary' => $netSalary,
                'advances' => $advances,
                'loans' => $loans,
                'overrides_applied' => !empty($overrides)
            ];

        } catch (\Exception $e) {
            Log::error('Salary calculation error', [
                'user_id' => $userId,
                'month' => $month,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Get attendance data for employee and month
     */
    protected function getAttendanceData(int $userId, string $month): array
    {
        $startDate = date('Y-m-01', strtotime($month));
        $endDate = date('Y-m-t', strtotime($month));
        
        // CRITICAL: Use today's date as the effective end date if we're in the current month
        // This prevents penalizing employees for future days that haven't occurred yet
        $today = date('Y-m-d');
        $effectiveEndDate = ($endDate > $today) ? $today : $endDate;

        // Query attendance records (with null-safety)
        // IMPORTANT: Only count days up to today (or end of month if past month)
        $attendanceQuery = "
            SELECT 
                COUNT(DISTINCT CASE WHEN login_time IS NOT NULL AND login_time != '' THEN attendance_date END) as present_days,
                SUM(CASE WHEN login_time IS NOT NULL AND login_time != '' THEN 1 ELSE 0 END) as days_with_login,
                SUM(CASE 
                    WHEN (login_time IS NULL OR login_time = '') AND attendance_date IS NOT NULL THEN 1 
                    ELSE 0 
                END) as absent_days,
                0 as leave_days
            FROM t_ops_attendance
            WHERE user_id = ?
            AND attendance_date IS NOT NULL
            AND attendance_date BETWEEN ? AND ?
        ";

        $attendance = DB::selectOne($attendanceQuery, [$userId, $startDate, $effectiveEndDate]);

        // Per-date + snapshot-aware late/overtime totals. Replaces the old single-shift
        // SQL that applied today's shift to the whole month (rewriting past lateness
        // whenever a rider's shift changed). Prefers the frozen check-in snapshot per day.
        $shiftService = new \App\Services\ShiftResolutionService();
        $lateOt = $shiftService->sumLateOvertimeMinutes($userId, $startDate, $effectiveEndDate);

        // Calculate working days using the SAME logic as attendance reports
        // This considers user's shift schedule AND public holidays
        // IMPORTANT: Only count working days up to today (or end of month if past month)
        $shiftService = new \App\Services\ShiftResolutionService();
        $workingDays = $shiftService->calculateWorkingDays($userId, $startDate, $effectiveEndDate);

        // Calculate leave days from approved/pending leave requests
        // IMPORTANT: Same logic as attendance reports for consistency
        // CRITICAL: Only count leaves up to today (or end of month if past month)
        $leaveDays = 0;
        $leaveRequests = RequestModel::where('requester_user_id', $userId)
            ->whereIn('status', ['approved', 'pending'])
            ->whereHas('category', function($q) {
                $q->where('category_code', 'leave');
            })
            ->where(function($q) use ($startDate, $effectiveEndDate) {
                $q->whereBetween('leave_start_date', [$startDate, $effectiveEndDate])
                  ->orWhereBetween('leave_end_date', [$startDate, $effectiveEndDate])
                  ->orWhere(function($q2) use ($startDate, $effectiveEndDate) {
                      $q2->where('leave_start_date', '<=', $startDate)
                         ->where('leave_end_date', '>=', $effectiveEndDate);
                  });
            })
            ->get();

        foreach ($leaveRequests as $request) {
            if ($request->leave_start_date && $request->leave_end_date) {
                // A half-day counts 0.5 (display consistency with the leave balance).
                // No money impact either way: pay deducts by ABSENT days (a half-day has a
                // login, so it is never absent) and late minutes (suppressed for half-days
                // inside sumLateOvertimeMinutes).
                $weight = strtolower((string) ($request->leave_type ?? '')) === 'half_day' ? 0.5 : 1;
                $leaveStart = new \DateTime($request->leave_start_date);
                $leaveEnd = new \DateTime($request->leave_end_date);
                $current = clone $leaveStart;

                while ($current <= $leaveEnd) {
                    $dateStr = $current->format('Y-m-d');
                    // Only count if within the effective date range (up to today or end of month)
                    if ($dateStr >= $startDate && $dateStr <= $effectiveEndDate) {
                        $leaveDays += $weight;
                    }
                    $current->modify('+1 day');
                }
            }
        }

        $presentDays = $attendance->present_days ?? 0;

        // "Not needed" days (manager-tagged) are PAID AS PRESENT: the day stays a working
        // day (so the per-day rate is unchanged) but must NOT count as absent. Subtract the
        // tagged working-days that would otherwise fall into absent (no login, not leave).
        // Guarded: before the Phase-E t_ops_day_tag table exists this is 0 → byte-identical.
        $notNeededDays = $this->notNeededPaidDays($userId, $startDate, $effectiveEndDate, $shiftService);

        // Absences use the SAME canonical definition as the attendance Month tab, the year count,
        // the drill-down, and the rider app: a working-kind day (dayKind='working' — excludes
        // off/holiday/before-hire/not-needed) with no login and no APPROVED leave. This replaces
        // the old `working − present − leave − not_needed` formula, which over-excused when a leave
        // fell on someone's OFF day (subtracting a day working_days never counted) and could drift
        // from what managers see on screen. Pending leave counts as unexcused here (managers clear
        // pending approvals before running payroll — owner rule, Jul 2026). not_needed is still
        // excluded (dayKind handles it), so a "not needed" day is never deducted.
        $absentDays = count((new AttendanceYearService())
            ->absentWorkingDates($userId, $shiftService, $startDate, $effectiveEndDate));

        Log::info('Salary calculation - attendance breakdown', [
            'user_id' => $userId,
            'month' => $month,
            'working_days' => $workingDays,
            'present_days' => $presentDays,
            'leave_days' => $leaveDays,
            'not_needed_days' => $notNeededDays,
            'absent_days' => $absentDays
        ]);

        return [
            'working_days' => $workingDays,
            'present_days' => $presentDays,
            'absent_days' => $absentDays, // subtract leave + not-needed
            'leave_days' => $leaveDays,
            'late_minutes' => $lateOt['late_minutes'],
            // ⭐ Sep-6 2026 — `late_minutes` above is already NET of anything a manager waived,
            // so the buffer rule downstream meets the right figure. These two carry the split
            // through so a payslip can say "400 min late, 200 waived" instead of quietly
            // showing the smaller number and looking like the engine changed its mind.
            'late_waived_minutes' => $lateOt['late_waived_minutes'] ?? 0,
            'late_raw_minutes' => $lateOt['late_raw_minutes'] ?? $lateOt['late_minutes'],
            'overtime_minutes' => $lateOt['overtime_minutes'],
            'overtime_hours' => round($lateOt['overtime_minutes'] / 60, 2)
        ];
    }

    /**
     * Public passthrough to the unified per-month attendance summary
     * (working_days/present_days/absent_days/leave_days/late_minutes/overtime_minutes).
     * The Payroll engine reuses THIS so its absent/late numbers are the same
     * harness-proven values the salary slip uses. Read-only; does not change the slip path.
     */
    /**
     * ⚠ PERF: a month's attendance is expensive (it resolves a shift for every day) and the
     * same (user, month) is asked for repeatedly in one request — the grid, the leave panel
     * and the absence nag all want it. Held for the request only; any write that changes
     * attendance happens in a different request.
     */
    private static array $attMemo = [];

    public function attendanceSummary(int $userId, string $month): array
    {
        $k = $userId . '|' . $month;
        if (!isset(self::$attMemo[$k])) {
            self::$attMemo[$k] = $this->getAttendanceData($userId, $month);
        }
        return self::$attMemo[$k];
    }

    /** Drop the cached months (an attendance edit inside this request invalidates them). */
    public static function forgetAttendanceMemo(): void
    {
        self::$attMemo = [];
    }

    /**
     * Count "not needed" tagged days in [start,end] that would otherwise be ABSENT — i.e.
     * a would-be working day (dayKind='not_needed'), with no login, and not on leave. These
     * are subtracted from absent so the rider is paid as present. Guarded: no table → 0.
     */
    protected function notNeededPaidDays(int $userId, string $start, string $end, \App\Services\ShiftResolutionService $shiftService): int
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('t_ops_day_tag')) {
                return 0;
            }
            $tagged = DB::table('t_ops_day_tag')->where('user_id', $userId)
                ->whereBetween('tag_date', [$start, $end])->pluck('tag_date');
            if ($tagged->isEmpty()) {
                return 0;
            }
            // Dates the rider actually logged in (present) — never subtract these.
            $login = [];
            foreach (DB::table('t_ops_attendance')->where('user_id', $userId)
                        ->whereBetween('attendance_date', [$start, $end])
                        ->whereNotNull('login_time')->where('login_time', '!=', '')
                        ->pluck('attendance_date') as $d) {
                $login[substr((string) $d, 0, 10)] = true;
            }
            // Approved/pending leave dates in range — already handled as leave, never subtract.
            $leave = [];
            $leaveRequests = RequestModel::where('requester_user_id', $userId)
                ->whereIn('status', ['approved', 'pending'])
                ->whereHas('category', function ($q) { $q->where('category_code', 'leave'); })
                ->where('leave_start_date', '<=', $end)
                ->where('leave_end_date', '>=', $start)
                ->get(['leave_start_date', 'leave_end_date']);
            foreach ($leaveRequests as $lr) {
                if (!$lr->leave_start_date || !$lr->leave_end_date) { continue; }
                for ($c = new \DateTime($lr->leave_start_date); $c <= new \DateTime($lr->leave_end_date); $c->modify('+1 day')) {
                    $leave[$c->format('Y-m-d')] = true;
                }
            }
            $n = 0;
            foreach ($tagged as $td) {
                $ds = substr((string) $td, 0, 10);
                if (isset($login[$ds]) || isset($leave[$ds])) { continue; }
                if ($shiftService->dayKind($userId, $ds) === 'not_needed') { $n++; }
            }
            return $n;
        } catch (\Throwable $e) {
            \Log::warning('notNeededPaidDays failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Get approved salary advances for the month
     * ⭐ FIX: Include ALL pending advances (not settled), regardless of when approved
     */
    protected function getSalaryAdvances(int $userId, string $month): array
    {
        try {
            // ⭐ Get ALL approved advances that are NOT YET SETTLED
            // This ensures advances from previous months are also deducted
            // Note: settlement_status can be 'not_required', 'open', 'pending', 'settled', or NULL
            // We want everything EXCEPT 'settled'
            $advances = RequestModel::where('requester_user_id', $userId)
                ->whereHas('category', function($q) {
                    $q->where('category_code', 'salary_advance');
                })
                ->where('status', 'approved')
                ->where(function($q) {
                    // Include advances that are NOT settled
                    // This covers: NULL, 'not_required', 'open', 'pending'
                    $q->whereNull('settlement_status')
                      ->orWhere('settlement_status', '!=', 'settled');
                })
                ->orderBy('created_at', 'asc') // Oldest first
                ->get()
                ->map(function($request) {
                    return [
                        'request_id' => $request->id,
                        'amount' => $request->amount ?? 0,
                        'approved_date' => $request->completed_at ?? $request->created_at,
                        'description' => $request->description ?? ''
                    ];
                })
                ->toArray();

            $totalAdvance = array_sum(array_column($advances, 'amount'));
            
            Log::debug('Found salary advances', [
                'user_id' => $userId,
                'count' => count($advances),
                'total' => $totalAdvance,
                'request_ids' => implode(',', array_column($advances, 'request_id'))
            ]);

            return [
                'requests' => $advances,
                'total_amount' => $totalAdvance,
                'request_ids' => implode(',', array_column($advances, 'request_id'))
            ];
        } catch (\Exception $e) {
            Log::error('Error getting salary advances', [
                'user_id' => $userId,
                'month' => $month,
                'error' => $e->getMessage()
            ]);
            
            return [
                'requests' => [],
                'total_amount' => 0,
                'request_ids' => ''
            ];
        }
    }

    /**
     * Get active loans for employee
     * ⭐ FIX: Cap installment at outstanding balance to prevent over-deduction
     */
    protected function getActiveLoans(int $userId): array
    {
        $loans = EmployeeLoanModel::active()
            ->where('user_id', $userId)
            ->get()
            ->map(function($loan) {
                // ⭐ CRITICAL: Use lesser of (monthly_installment, outstanding_balance)
                // This prevents over-deduction when loan is nearly paid off
                $effectiveInstallment = min($loan->monthly_installment, $loan->outstanding_balance);
                
                return [
                    'loan_id' => $loan->id,
                    'loan_number' => $loan->loan_number,
                    'outstanding_balance' => $loan->outstanding_balance,
                    'monthly_installment' => $loan->monthly_installment,
                    'effective_installment' => $effectiveInstallment, // ⭐ Capped amount
                    'loan_type' => $loan->loan_type
                ];
            })
            ->toArray();

        // ⭐ Use effective_installment (capped) for total, not monthly_installment
        $totalInstallment = array_sum(array_column($loans, 'effective_installment'));

        return [
            'loans' => $loans,
            'total_installment' => $totalInstallment,
            'loan_ids' => implode(',', array_column($loans, 'loan_id'))
        ];
    }

    /**
     * Calculate earnings (gross salary)
     */
    protected function calculateEarnings(EmployeeProfileModel $profile, array $attendanceData, array $overrides): array
    {
        // Base salary
        $baseSalary = $profile->base_salary;

        // Overtime calculation
        $overtimeHours = $overrides['overtime_hours'] ?? $attendanceData['overtime_hours'];
        $overtimeAmount = $overtimeHours * $profile->overtime_rate;
        $overtimeOverridden = isset($overrides['overtime_hours']);

        // Bonuses and allowances
        $bonuses = $overrides['bonuses'] ?? 0;
        $allowances = $overrides['allowances'] ?? 0;
        $otherEarnings = $overrides['other_earnings'] ?? 0;
        $otherEarningsDesc = $overrides['other_earnings_desc'] ?? null;

        // Gross salary
        $grossSalary = $baseSalary + $overtimeAmount + $bonuses + $allowances + $otherEarnings;

        return [
            'base_salary' => $baseSalary,
            'overtime_hours' => $overtimeHours,
            'overtime_amount' => $overtimeAmount,
            'overtime_overridden' => $overtimeOverridden,
            'bonuses' => $bonuses,
            'allowances' => $allowances,
            'other_earnings' => $otherEarnings,
            'other_earnings_desc' => $otherEarningsDesc,
            'gross_salary' => $grossSalary
        ];
    }

    /**
     * Calculate deductions
     */
    protected function calculateDeductions(
        EmployeeProfileModel $profile,
        array $attendanceData,
        array $advances,
        array $loans,
        array $overrides
    ): array {
        
        // Late deduction
        $lateMinutes = $attendanceData['late_minutes'];
        $lateHours = $lateMinutes / 60;
        $lateDeduction = $overrides['waive_late'] ?? false ? 0 : ($lateHours * $profile->late_deduction_rate);
        $lateDeductionOverridden = isset($overrides['waive_late']) && $overrides['waive_late'];

        // Absent deduction (pro-rata calculation)
        $absentDays = $attendanceData['absent_days'];
        $perDaySalary = $attendanceData['working_days'] > 0 
            ? $profile->base_salary / $attendanceData['working_days'] 
            : 0;
        $absentDeduction = $overrides['absent_deduction'] ?? ($absentDays * $perDaySalary);
        $absentDeductionOverridden = isset($overrides['absent_deduction']);

        // Salary advance
        $salaryAdvance = $advances['total_amount'];

        // Loan installment
        $loanInstallment = $overrides['skip_loan'] ?? false ? 0 : $loans['total_installment'];
        $loanInstallmentSkipped = isset($overrides['skip_loan']) && $overrides['skip_loan'];

        // Tax and other deductions
        $taxDeduction = $overrides['tax_deduction'] ?? 0;
        $otherDeductions = $overrides['other_deductions'] ?? 0;
        $otherDeductionsDesc = $overrides['other_deductions_desc'] ?? null;

        // Total deductions
        $totalDeductions = $lateDeduction + $absentDeduction + $salaryAdvance + $loanInstallment + $taxDeduction + $otherDeductions;

        return [
            'late_minutes' => $lateMinutes,
            // ⚠⚠ Sep-6 2026 — `late_minutes` above is NET of anything a manager waived, and a
            // salary slip FREEZES it forever. Without these two the receipt would record "120
            // minutes late" for a month the engine measured at 400, and the 280 forgiven
            // minutes would be unrecoverable from the slip. The deduction is right either way;
            // this is about the slip still being able to explain itself a year later.
            'late_waived_minutes' => (int) ($attendanceData['late_waived_minutes'] ?? 0),
            'late_raw_minutes' => (int) ($attendanceData['late_raw_minutes'] ?? $lateMinutes),
            'late_deduction' => $lateDeduction,
            'late_deduction_overridden' => $lateDeductionOverridden,
            'absent_days' => $absentDays,
            'absent_deduction' => $absentDeduction,
            'absent_deduction_overridden' => $absentDeductionOverridden,
            'salary_advance' => $salaryAdvance,
            'advance_request_ids' => $advances['request_ids'],
            'loan_installment' => $loanInstallment,
            'loan_installment_skipped' => $loanInstallmentSkipped,
            'loan_ids' => $loans['loan_ids'],
            'tax_deduction' => $taxDeduction,
            'other_deductions' => $otherDeductions,
            'other_deductions_desc' => $otherDeductionsDesc,
            'total_deductions' => $totalDeductions
        ];
    }

    /**
     * Generate and save salary slip
     */
    public function generateSlip(int $userId, string $month, array $overrides = [], ?int $createdBy = null): array
    {
        try {
            // Check if slip already exists (non-cancelled)
            if (SalarySlipModel::existsForUserMonth($userId, $month)) {
                return $this->errorResponse('Salary slip already exists for this month. Please edit the existing slip or cancel it first.');
            }

            // Calculate salary
            $calculation = $this->calculateSalary($userId, $month, $overrides);
            
            if (!$calculation['success']) {
                return $calculation;
            }

            // Generate slip number
            $slipNumber = SalarySlipModel::generateSlipNumber($userId, $month);

            // Check if any manual adjustments were made
            $hasManualAdjustments = !empty($overrides);

            // Create salary slip
            $slip = SalarySlipModel::create([
                'user_id' => $userId,
                'salary_month' => $month,
                'slip_number' => $slipNumber,
                
                // Earnings
                'base_salary' => $calculation['earnings']['base_salary'],
                'overtime_hours' => $calculation['earnings']['overtime_hours'],
                'overtime_amount' => $calculation['earnings']['overtime_amount'],
                'bonuses' => $calculation['earnings']['bonuses'],
                'allowances' => $calculation['earnings']['allowances'],
                'other_earnings' => $calculation['earnings']['other_earnings'],
                'other_earnings_desc' => $calculation['earnings']['other_earnings_desc'],
                'gross_salary' => $calculation['earnings']['gross_salary'],
                
                // Deductions
                'late_minutes' => $calculation['deductions']['late_minutes'],
                'late_deduction' => $calculation['deductions']['late_deduction'],
                'absent_days' => $calculation['deductions']['absent_days'],
                'absent_deduction' => $calculation['deductions']['absent_deduction'],
                'salary_advance' => $calculation['deductions']['salary_advance'],
                'loan_installment' => $calculation['deductions']['loan_installment'],
                'tax_deduction' => $calculation['deductions']['tax_deduction'],
                'other_deductions' => $calculation['deductions']['other_deductions'],
                'other_deductions_desc' => $calculation['deductions']['other_deductions_desc'],
                'total_deductions' => $calculation['deductions']['total_deductions'],
                
                // Net salary
                'net_salary' => $calculation['net_salary'],
                
                // Attendance
                'working_days' => $calculation['attendance']['working_days'],
                'present_days' => $calculation['attendance']['present_days'],
                'leave_days' => $calculation['attendance']['leave_days'],
                'half_days' => 0,
                
                // Overrides
                'late_deduction_overridden' => $calculation['deductions']['late_deduction_overridden'],
                'overtime_overridden' => $calculation['earnings']['overtime_overridden'],
                'absent_deduction_overridden' => $calculation['deductions']['absent_deduction_overridden'],
                'loan_installment_skipped' => $calculation['deductions']['loan_installment_skipped'],
                'has_manual_adjustments' => $hasManualAdjustments,
                'override_notes' => $overrides['override_notes'] ?? null,
                
                // References
                'advance_request_ids' => $calculation['deductions']['advance_request_ids'],
                'loan_ids' => $calculation['deductions']['loan_ids'],
                
                // Status
                'slip_status' => SalarySlipModel::STATUS_DRAFT,
                'created_by' => $createdBy ?? auth()->id()
            ]);

            return [
                'success' => true,
                'message' => 'Salary slip generated successfully',
                'slip' => $slip->getDetailedBreakdown()
            ];

        } catch (\Exception $e) {
            Log::error('Salary slip generation error', [
                'user_id' => $userId,
                'month' => $month,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to generate salary slip: ' . $e->getMessage());
        }
    }

    /**
     * Error response helper
     */
    protected function errorResponse(string $message): array
    {
        return [
            'success' => false,
            'error' => $message
        ];
    }
}

