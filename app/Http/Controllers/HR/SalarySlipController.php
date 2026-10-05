<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\HR\SalarySlipModel;
use App\Models\HR\EmployeeProfileModel;
use App\Services\HR\SalaryCalculationService;
use App\Services\FIN\LedgerPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalarySlipController extends Controller
{
    protected $salaryService;
    protected $ledgerService;

    public function __construct(SalaryCalculationService $salaryService, LedgerPostingService $ledgerService)
    {
        $this->salaryService = $salaryService;
        $this->ledgerService = $ledgerService;
    }

    /**
     * Display list of all salary slips
     */
    public function index(Request $request)
    {
        // For API requests
        if ($request->wantsJson() || $request->has('api')) {
            return $this->getData($request);
        }

        // For web view
        return view('pages.hr.salary-slips.index');
    }

    /**
     * Get salary slips data (API)
     */
    public function getData(Request $request)
    {
        $query = SalarySlipModel::with(['employee', 'approver']);

        // Filter by user
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by month (use date range to handle both -01 and -02 formats)
        if ($request->filled('month')) {
            $filterMonthStart = date('Y-m-01', strtotime($request->month));
            $filterMonthEnd = date('Y-m-t', strtotime($request->month));
            $query->whereBetween('salary_month', [$filterMonthStart, $filterMonthEnd]);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('slip_status', $request->status);
        }

        // Search by employee name or slip number
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('slip_number', 'like', "%{$search}%")
                  ->orWhereHas('employee', function($empQuery) use ($search) {
                      $empQuery->where('fullname', 'like', "%{$search}%");
                  });
            });
        }

        // Order
        $query->orderBy('salary_month', 'desc')
              ->orderBy('created_at', 'desc');

        // Paginate
        $perPage = $request->input('per_page', 50);
        $slips = $query->paginate($perPage);

        // Get all slips (not paginated for simplicity)
        $allSlips = $query->get();

        // Transform data
        $slipsData = $allSlips->map(function($slip) {
            return [
                'id' => $slip->id,
                'slip_number' => $slip->slip_number,
                'employee' => [
                    'fullname' => $slip->employee->fullname ?? 'Unknown',
                    'email' => $slip->employee->email ?? ''
                ],
                'user_id' => $slip->user_id,
                'salary_month' => $slip->salary_month,
                'gross_salary' => (float) $slip->gross_salary,
                'total_deductions' => (float) $slip->total_deductions,
                'net_salary' => (float) $slip->net_salary,
                'slip_status' => $slip->slip_status,
                'has_manual_adjustments' => (bool) $slip->has_manual_adjustments,
                'created_at' => $slip->created_at->toIso8601String()
            ];
        });

        // Calculate statistics
        $statistics = [
            'total' => $allSlips->count(),
            'draft' => $allSlips->where('slip_status', 'draft')->count(),
            'approved' => $allSlips->where('slip_status', 'approved')->count(),
            'paid' => $allSlips->where('slip_status', 'paid')->count(),
            'cancelled' => $allSlips->where('slip_status', 'cancelled')->count(),
            'total_net_salary' => $allSlips->whereIn('slip_status', ['approved', 'paid'])->sum('net_salary')
        ];

        return response()->json([
            'success' => true,
            'slips' => $slipsData,
            'statistics' => $statistics
        ]);
    }

    /**
     * Show form to create/generate salary slip for a user
     */
    public function create(Request $request, ?int $userId = null)
    {
        if (!$userId) {
            $userId = $request->input('user_id');
        }

        // Get employee profile
        $profile = null;
        if ($userId) {
            $profile = EmployeeProfileModel::with('user')->where('user_id', $userId)->first();
        }

        // Get all employees with salary configured
        $employees = EmployeeProfileModel::with('user')
            ->active()
            ->withSalary()
            ->get()
            ->map(function($profile) {
                return [
                    'user_id' => $profile->user_id,
                    'name' => $profile->user->fullname ?? 'Unknown',
                    'email' => $profile->user->email ?? '',
                    'base_salary' => $profile->base_salary
                ];
            });

        return view('pages.hr.salary-slips.create', compact('profile', 'employees', 'userId'));
    }

    /**
     * Calculate salary (AJAX endpoint)
     */
    public function calculate(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|integer|exists:t_sys_user,id',
                'month' => 'required|date_format:Y-m-d',
                'overrides' => 'nullable|array'
            ]);

            // Check if salary slip already exists for this user and month
            // Use year-month comparison to handle both old (-01) and new (-02) date formats
            $monthStart = date('Y-m-01', strtotime($validated['month']));
            $monthEnd = date('Y-m-t', strtotime($validated['month']));
            $existingSlip = \App\Models\HR\SalarySlipModel::where('user_id', $validated['user_id'])
                ->whereBetween('salary_month', [$monthStart, $monthEnd])
                ->whereIn('slip_status', ['draft', 'approved', 'paid'])
                ->first();
            
            if ($existingSlip) {
                return response()->json([
                    'success' => false,
                    'error' => 'A salary slip already exists for this employee for the selected month (Slip #' . $existingSlip->slip_number . '). Please edit the existing slip or delete it before creating a new one.'
                ], 400);
            }

            $result = $this->salaryService->calculateSalary(
                $validated['user_id'],
                $validated['month'],
                $validated['overrides'] ?? []
            );

            return response()->json($result);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed: ' . implode(', ', $e->validator->errors()->all())
            ], 422);

        } catch (\Exception $e) {
            Log::error('Salary calculation endpoint error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Error calculating salary: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store (generate and save) salary slip
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'salary_month' => 'required|date_format:Y-m-d',
            'slip_status' => 'required|in:draft,approved',
            
            // Earnings
            'base_salary' => 'required|numeric|min:0',
            'overtime_hours' => 'nullable|numeric|min:0',
            'overtime_amount' => 'nullable|numeric|min:0',
            'bonuses' => 'nullable|numeric|min:0',
            'allowances' => 'nullable|numeric|min:0',
            'other_earnings' => 'nullable|numeric|min:0',
            'other_earnings_desc' => 'nullable|string|max:500',
            
            // Deductions
            'late_minutes' => 'nullable|numeric|min:0',
            'late_waived_minutes' => 'nullable|integer|min:0',
            'late_raw_minutes' => 'nullable|integer|min:0',
            'late_deduction' => 'nullable|numeric|min:0',
            'absent_days' => 'nullable|integer|min:0',
            'absent_deduction' => 'nullable|numeric|min:0',
            'salary_advance' => 'nullable|numeric|min:0',
            'loan_installment' => 'nullable|numeric|min:0',
            'tax_deduction' => 'nullable|numeric|min:0',
            'other_deductions' => 'nullable|numeric|min:0',
            'other_deductions_desc' => 'nullable|string|max:500',
            
            // Attendance
            'working_days' => 'nullable|integer|min:0',
            'present_days' => 'nullable|integer|min:0',
            'leave_days' => 'nullable|integer|min:0',
            'half_days' => 'nullable|integer|min:0',
            
            // Overrides
            'late_deduction_overridden' => 'nullable|boolean',
            'overtime_overridden' => 'nullable|boolean',
            'absent_deduction_overridden' => 'nullable|boolean',
            'loan_installment_skipped' => 'nullable|boolean',
            'salary_advance_overridden' => 'nullable|boolean',
            'has_manual_adjustments' => 'nullable|boolean',
            'override_notes' => 'nullable|string|max:1000',
            
            // Totals
            'gross_salary' => 'required|numeric|min:0',
            'total_deductions' => 'required|numeric|min:0',
            'net_salary' => 'required|numeric',
            
            // IDs
            'advance_request_ids' => 'nullable|string',
            'loan_ids' => 'nullable|string'
        ]);

        try {
            DB::beginTransaction();
            
            // Check if salary slip already exists for this user and month
            // Use year-month range to handle both old (-01) and new (-02) date formats
            $storeMonthStart = date('Y-m-01', strtotime($validated['salary_month']));
            $storeMonthEnd = date('Y-m-t', strtotime($validated['salary_month']));
            $existingSlip = \App\Models\HR\SalarySlipModel::where('user_id', $validated['user_id'])
                ->whereBetween('salary_month', [$storeMonthStart, $storeMonthEnd])
                ->whereIn('slip_status', ['draft', 'approved', 'paid'])
                ->first();
            
            if ($existingSlip) {
                return response()->json([
                    'success' => false,
                    'message' => 'A salary slip already exists for this employee for the selected month (Slip #' . $existingSlip->slip_number . '). Please edit the existing slip or cancel it before creating a new one.'
                ], 400);
            }
            
            $lateSplit = \App\Models\HR\SalarySlipModel::lateSplitForNewSlip(
                (int) $validated['user_id'], (string) $validated['salary_month'],
                $validated['late_minutes'] ?? 0,
                $validated['late_raw_minutes'] ?? null, $validated['late_waived_minutes'] ?? null
            );

            // Create salary slip with status 'draft' first
            $slip = \App\Models\HR\SalarySlipModel::create([
                'user_id' => $validated['user_id'],
                'salary_month' => $validated['salary_month'],
                'slip_status' => 'draft',  // Always create as draft first
                
                // Earnings
                'base_salary' => $validated['base_salary'],
                'overtime_hours' => $validated['overtime_hours'] ?? 0,
                'overtime_amount' => $validated['overtime_amount'] ?? 0,
                'bonuses' => $validated['bonuses'] ?? 0,
                'allowances' => $validated['allowances'] ?? 0,
                'other_earnings' => $validated['other_earnings'] ?? 0,
                'other_earnings_description' => $validated['other_earnings_desc'] ?? null,
                
                // Deductions
                'late_minutes' => $validated['late_minutes'] ?? 0,
                // Frozen alongside it: what the month really was and what a manager forgave.
                // Without these the receipt records a reduced figure it cannot account for.
                // ⚠ Posted by the create screen, which reads them from the calculate endpoint.
                // Defaulting `raw` to the net figure is right for every slip made before day
                // review existed and for any month with no waiver: raw and net are the same.
                // Oct-2026: settled by the server, the same helper the phone's door uses.
                'late_waived_minutes' => $lateSplit['waived'],
                'late_raw_minutes' => $lateSplit['raw'],
                'late_deduction' => $validated['late_deduction'] ?? 0,
                'absent_days' => $validated['absent_days'] ?? 0,
                'absent_deduction' => $validated['absent_deduction'] ?? 0,
                'salary_advance' => $validated['salary_advance'] ?? 0,
                'loan_installment' => $validated['loan_installment'] ?? 0,
                'tax_deduction' => $validated['tax_deduction'] ?? 0,
                'other_deductions' => $validated['other_deductions'] ?? 0,
                'other_deductions_description' => $validated['other_deductions_desc'] ?? null,
                
                // Attendance
                'working_days' => $validated['working_days'] ?? 0,
                'present_days' => $validated['present_days'] ?? 0,
                'leave_days' => $validated['leave_days'] ?? 0,
                'half_days' => $validated['half_days'] ?? 0,
                
                // Overrides
                'late_deduction_overridden' => $validated['late_deduction_overridden'] ?? false,
                'overtime_overridden' => $validated['overtime_overridden'] ?? false,
                'absent_deduction_overridden' => $validated['absent_deduction_overridden'] ?? false,
                'loan_installment_skipped' => $validated['loan_installment_skipped'] ?? false,
                'salary_advance_overridden' => $validated['salary_advance_overridden'] ?? false,
                'has_manual_adjustments' => $validated['has_manual_adjustments'] ?? false,
                'override_notes' => $validated['override_notes'] ?? null,
                
                // Totals
                'gross_salary' => $validated['gross_salary'],
                'total_deductions' => $validated['total_deductions'],
                'net_salary' => $validated['net_salary'],
                
                // IDs
                'advance_request_ids' => $validated['advance_request_ids'] ?? null,
                'loan_ids' => $validated['loan_ids'] ?? null,
                
                // Meta
                'created_by' => auth()->id()
            ]);
            
            // CRITICAL: If user selected "approved", trigger the full approval process
            if ($validated['slip_status'] === 'approved') {
                // 1. Approve the slip
                $slip->approve(auth()->id());
                
                // 2. Create ledger entry for salary payment
                $ledgerEntry = $this->createSalaryLedgerEntry($slip);
                
                if ($ledgerEntry) {
                    // 3. Mark slip as paid and link to ledger
                    $slip->markAsPaid($ledgerEntry->id, 'cash');
                    
                    // 4. Process loan payments (update loan balances)
                    $this->processLoanPayments($slip);
                    
                    // 5. Settle salary advances (mark requests as settled)
                    $this->settleSalaryAdvances($slip);
                }
            }
            
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $validated['slip_status'] === 'approved' 
                    ? 'Salary slip approved and posted to ledger successfully'
                    : 'Salary slip saved as draft successfully',
                'slip' => $slip->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error storing salary slip', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $validated['user_id'],
                'salary_month' => $validated['salary_month']
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save salary slip: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show salary slip details
     */
    public function show($id)
    {
        $slip = SalarySlipModel::with(['employee', 'approver', 'creator'])->findOrFail($id);
        
        $detailedBreakdown = $slip->getDetailedBreakdown();

        if (request()->wantsJson()) {
            return response()->json([
                'success' => true,
                'slip' => $detailedBreakdown
            ]);
        }

        return view('pages.hr.salary-slips.show', compact('slip', 'detailedBreakdown'));
    }

    /**
     * Approve salary slip
     */
    public function approve(Request $request, $id)
    {
        try {
            $slip = SalarySlipModel::findOrFail($id);

            if (!$slip->canBeApproved()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This salary slip cannot be approved'
                ], 400);
            }

            DB::beginTransaction();

            // Approve the slip
            $slip->approve(auth()->id());

            // Create ledger entry for salary payment
            $ledgerEntry = $this->createSalaryLedgerEntry($slip);

            if ($ledgerEntry) {
                // Mark slip as paid and link to ledger
                $slip->markAsPaid($ledgerEntry->id, $request->input('payment_method', 'cash'));

                // Process loan payments
                $this->processLoanPayments($slip);
                
                // Mark salary advances as settled
                $this->settleSalaryAdvances($slip);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Salary slip approved and posted to ledger successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error approving salary slip', [
                'slip_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve salary slip: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete salary slip and rollback all related changes
     */
    public function destroy($id)
    {
        try {
            $slip = SalarySlipModel::with(['employee'])->findOrFail($id);
            
            // Only allow deletion of draft, approved, or paid slips
            // Cancelled slips can't be deleted (already cancelled)
            if ($slip->slip_status === 'cancelled') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete a cancelled salary slip'
                ], 400);
            }
            
            DB::beginTransaction();
            
            Log::info('Starting salary slip deletion', [
                'slip_id' => $slip->id,
                'slip_number' => $slip->slip_number,
                'user_id' => $slip->user_id,
                'status' => $slip->slip_status,
                'net_salary' => $slip->net_salary
            ]);
            
            // Step 1: Rollback ledger entry if exists
            if ($slip->ledger_transaction_id) {
                $ledger = \App\Models\FIN\LedgerModel::find($slip->ledger_transaction_id);
                
                if ($ledger) {
                    Log::info('Rolling back ledger entry', [
                        'ledger_id' => $ledger->id,
                        'transaction_id' => $ledger->transaction_id,
                        'amount' => $ledger->amount
                    ]);
                    
                    // Reverse via the canonical engine (payment source +). The employee-cash leg is
                    // skipped automatically because salary_payment is an excluded type — matching the
                    // note that to_account was never touched. Self-guards on balance_updated.
                    (new \App\Services\FIN\BalancePostingService())->reverse($ledger);

                    // Delete the ledger entry
                    $ledger->delete();
                    Log::info('Ledger entry deleted');
                }
            }
            
            // Step 2: Rollback loan installment payments
            if ($slip->loan_installment > 0 && !empty($slip->loan_ids)) {
                $loanIds = [];
                
                // Parse loan IDs (could be JSON or comma-separated)
                if (is_string($slip->loan_ids)) {
                    $decoded = json_decode($slip->loan_ids, true);
                    if (is_array($decoded)) {
                        $loanIds = $decoded;
                    } else {
                        $loanIds = explode(',', $slip->loan_ids);
                    }
                } elseif (is_array($slip->loan_ids)) {
                    $loanIds = $slip->loan_ids;
                }
                
                foreach ($loanIds as $loanId) {
                    $loanId = trim($loanId);
                    if (empty($loanId)) continue;
                    
                    $loan = \App\Models\HR\EmployeeLoanModel::find($loanId);
                    if ($loan) {
                        // Add back the installment amount to outstanding balance
                        $loan->outstanding_balance += $slip->loan_installment;
                        
                        // If loan was marked as completed, revert to active
                        if ($loan->loan_status === 'completed' && $loan->outstanding_balance > 0) {
                            $loan->loan_status = 'active';
                        }
                        
                        $loan->save();
                        
                        Log::info('Rolled back loan installment', [
                            'loan_id' => $loan->id,
                            'loan_number' => $loan->loan_number,
                            'installment_amount' => $slip->loan_installment,
                            'new_outstanding_balance' => $loan->outstanding_balance,
                            'new_status' => $loan->loan_status
                        ]);
                    }
                }
            }
            
            // Step 3: Rollback salary advance settlements
            if ($slip->salary_advance > 0 && !empty($slip->advance_request_ids)) {
                $advanceIds = [];
                
                // Parse advance IDs (could be JSON or comma-separated)
                if (is_string($slip->advance_request_ids)) {
                    $decoded = json_decode($slip->advance_request_ids, true);
                    if (is_array($decoded)) {
                        $advanceIds = $decoded;
                    } else {
                        $advanceIds = explode(',', $slip->advance_request_ids);
                    }
                } elseif (is_array($slip->advance_request_ids)) {
                    $advanceIds = $slip->advance_request_ids;
                }
                
                foreach ($advanceIds as $advanceId) {
                    $advanceId = trim($advanceId);
                    if (empty($advanceId)) continue;
                    
                    $advance = \App\Models\Request\RequestModel::find($advanceId);
                    if ($advance && $advance->settlement_status === 'settled') {
                        // Mark as pending settlement again
                        $advance->settlement_status = 'pending';
                        $advance->settled_at = null;
                        $advance->settlement_notes = null;
                        $advance->save();
                        
                        Log::info('Rolled back salary advance settlement', [
                            'advance_id' => $advance->id,
                            'request_number' => $advance->request_number,
                            'amount' => $advance->amount,
                            'new_settlement_status' => 'pending'
                        ]);
                    }
                }
            }
            
            // Step 4: Delete the salary slip itself
            $slipNumber = $slip->slip_number;
            $employeeName = $slip->employee->fullname ?? 'Unknown';
            $slip->delete();
            
            Log::info('Salary slip deleted successfully', [
                'slip_number' => $slipNumber,
                'employee' => $employeeName
            ]);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Salary slip deleted successfully. All related transactions have been rolled back.'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error deleting salary slip', [
                'slip_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete salary slip: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create ledger entry for salary payment
     * Now follows same pattern as expenses (supports payment source selection & settlement)
     */
    protected function createSalaryLedgerEntry(SalarySlipModel $slip)
    {
        try {
            // Get employee cash account
            $employeeCashAccount = \App\Models\FIN\AccountModel::where('user_id', $slip->user_id)
                ->where('account_category', 'employee_cash')
                ->first();

            if (!$employeeCashAccount) {
                // Auto-create employee cash account if doesn't exist
                $employeeCashAccount = $this->createEmployeeCashAccount($slip->user_id);
            }

            // Get payment source account
            // For salary slips, always use EXP_FUND (configurable default)
            $paymentSource = \App\Models\FIN\ConfigModel::getExpenseFundingAccount();
            
            if (!$paymentSource) {
                $paymentSource = \App\Models\FIN\AccountModel::getByCode('EXP_FUND');
            }

            if (!$paymentSource || !$employeeCashAccount) {
                throw new \Exception('Required accounts not found');
            }

            // Create ledger transaction using the model
            $ledger = \App\Models\FIN\LedgerModel::create([
                'transaction_date' => now(),
                'transaction_type' => 'salary_payment',
                'description' => 'Salary payment - ' . $slip->employee->fullname . ' - ' . $slip->formatted_month,
                'from_account_id' => $paymentSource->id,
                'to_account_id' => $employeeCashAccount->id,
                'amount' => $slip->net_salary,
                'mode' => 'cash',
                'approval_status' => 'approved',
                'approval_date' => now(),
                'approved_by' => auth()->id(),
                'external_source' => 'hr_salary_system',
                'external_ref_id' => $slip->slip_number,
                'comments' => "Paid from: {$paymentSource->account_name}",
                'created_by' => auth()->id()
            ]);

            // Apply via the canonical engine (payment source −). 'salary_payment' is an EXCLUDED
            // employee-cash type, so the engine AUTOMATICALLY skips the employee leg — enforcing the
            // rule that salary is a personal payment TO the employee, not company cash they hold
            // (replaces the hand-maintained commented-out credit). Row-locked; sets balance_updated.
            (new \App\Services\FIN\BalancePostingService())->apply($ledger);

            Log::info("Salary payment posted to ledger", [
                'slip_id' => $slip->id,
                'ledger_id' => $ledger->id,
                'amount' => $slip->net_salary,
                'from_account' => $paymentSource->account_code,
                'to_account' => $employeeCashAccount->account_name
            ]);

            return $ledger;

        } catch (\Exception $e) {
            Log::error('Error creating salary ledger entry', [
                'slip_id' => $slip->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Process loan payments from salary slip
     */
    protected function processLoanPayments(SalarySlipModel $slip)
    {
        Log::info('processLoanPayments called', [
            'slip_id' => $slip->id,
            'loan_installment' => $slip->loan_installment,
            'skipped' => $slip->loan_installment_skipped,
            'loan_ids' => $slip->loan_ids
        ]);
        
        if ($slip->loan_installment <= 0 || $slip->loan_installment_skipped) {
            Log::info('processLoanPayments skipped - no installment or skipped');
            return; // No loan payment or skipped
        }

        $loanIds = $slip->getLoanIdsArray();
        Log::info('processLoanPayments - loan IDs array', ['loan_ids' => $loanIds]);
        
        foreach ($loanIds as $loanId) {
            $loan = \App\Models\HR\EmployeeLoanModel::find($loanId);
            if ($loan && $loan->isActive()) {
                // ⭐ CRITICAL: Cap payment at outstanding balance to prevent over-payment
                $paymentAmount = min($loan->monthly_installment, $loan->outstanding_balance);
                
                Log::info('Processing loan payment', [
                    'loan_id' => $loanId,
                    'monthly_installment' => $loan->monthly_installment,
                    'outstanding_balance' => $loan->outstanding_balance,
                    'actual_payment' => $paymentAmount, // ⭐ Capped amount
                ]);
                
                $payment = $loan->recordPayment(
                    $paymentAmount, // ⭐ Use capped amount
                    $slip->id,
                    'salary_deduction',
                    'Deducted from salary slip: ' . ($slip->slip_number ?? 'SLIP-' . $slip->id),
                    auth()->id()
                );
                
                if ($payment) {
                    $loan->refresh();
                    Log::info('Loan payment recorded', [
                        'loan_id' => $loanId,
                        'payment_id' => $payment->id,
                        'balance_after' => $loan->outstanding_balance
                    ]);
                } else {
                    Log::warning('Loan payment NOT recorded', ['loan_id' => $loanId]);
                }
            } else {
                Log::warning('Loan not found or not active', [
                    'loan_id' => $loanId,
                    'found' => $loan ? 'yes' : 'no',
                    'active' => $loan ? $loan->isActive() : 'N/A'
                ]);
            }
        }
    }

    /**
     * Mark salary advances as settled after deduction
     */
    protected function settleSalaryAdvances(SalarySlipModel $slip)
    {
        Log::info('settleSalaryAdvances called', [
            'slip_id' => $slip->id,
            'salary_advance' => $slip->salary_advance,
            'advance_request_ids' => $slip->advance_request_ids
        ]);
        
        if ($slip->salary_advance <= 0 || empty($slip->advance_request_ids)) {
            Log::info('settleSalaryAdvances skipped - no advance or no IDs');
            return; // No salary advance or no request IDs
        }

        // Handle both JSON array and comma-separated string formats
        $advanceIds = [];
        
        if (is_string($slip->advance_request_ids)) {
            // Try to decode as JSON first
            $decoded = json_decode($slip->advance_request_ids, true);
            if (is_array($decoded)) {
                $advanceIds = $decoded;
            } else {
                // Fallback to comma-separated string
                $advanceIds = explode(',', $slip->advance_request_ids);
            }
        } elseif (is_array($slip->advance_request_ids)) {
            $advanceIds = $slip->advance_request_ids;
        }
        
        Log::info('settleSalaryAdvances - advance IDs array', ['advance_ids' => $advanceIds]);
        
        foreach ($advanceIds as $advanceId) {
            $advanceId = trim($advanceId);
            if (empty($advanceId)) {
                Log::warning('Empty advance ID, skipping');
                continue;
            }
            
            $advance = \App\Models\Request\RequestModel::find($advanceId);
            if ($advance && $advance->status === 'approved') {
                Log::info('Settling advance', [
                    'advance_id' => $advanceId,
                    'amount' => $advance->amount,
                    'current_status' => $advance->settlement_status
                ]);
                
                $advance->settlement_status = 'settled';
                $advance->settled_at = now();
                $advance->settlement_notes = 'Deducted from salary slip: ' . ($slip->slip_number ?? 'SLIP-' . $slip->id);
                $advance->save();
                
                Log::info('Salary advance marked as settled', [
                    'advance_id' => $advanceId,
                    'slip_id' => $slip->id,
                    'amount' => $advance->amount,
                    'new_status' => $advance->settlement_status
                ]);
            } else {
                Log::warning('Advance not found or not approved', [
                    'advance_id' => $advanceId,
                    'found' => $advance ? 'yes' : 'no',
                    'status' => $advance ? $advance->status : 'N/A'
                ]);
            }
        }
    }

    /**
     * Create employee cash account
     */
    protected function createEmployeeCashAccount(int $userId)
    {
        $user = \App\Models\SysAdmin\UserModel::find($userId);
        if (!$user) {
            return null;
        }

        // Single source of truth for employee-cash accounts — this used to be a
        // blind INSERT with its own name formula, which could mint a duplicate
        // account alongside the one the rest of the system uses.
        return \App\Models\FIN\AccountModel::createEmployeeCashAccount(
            $userId,
            $user->fullname ?? $user->name ?? 'User #' . $userId
        );
    }

    /**
     * Get status badge class
     */
    protected function getStatusBadge(string $status): string
    {
        $badges = [
            'draft' => 'badge-secondary',
            'approved' => 'badge-success',
            'paid' => 'badge-primary',
            'cancelled' => 'badge-danger'
        ];

        return $badges[$status] ?? 'badge-secondary';
    }

    /**
     * Download salary slip as PDF
     */
    public function downloadPdf($id)
    {
        $slip = SalarySlipModel::with(['employee', 'approver'])->findOrFail($id);
        
        // TODO: Implement PDF generation
        // For now, return JSON
        return response()->json([
            'success' => true,
            'message' => 'PDF generation coming soon',
            'slip' => $slip->getDetailedBreakdown()
        ]);
    }

    /**
     * Cancel salary slip
     */
    public function cancel($id)
    {
        try {
            $slip = SalarySlipModel::findOrFail($id);

            if ($slip->isPaid()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot cancel a paid salary slip'
                ], 400);
            }

            $slip->cancel();

            return response()->json([
                'success' => true,
                'message' => 'Salary slip cancelled successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel salary slip: ' . $e->getMessage()
            ], 500);
        }
    }
}

