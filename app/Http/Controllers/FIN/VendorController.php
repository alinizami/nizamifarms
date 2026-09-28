<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FIN\VendorModel;
use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\BusinessUnitModel;
use App\Models\FIN\VendorProductModel;
use App\Services\FIN\VendorUnits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class VendorController extends Controller
{
    /**
     * Display vendor list
     */
    public function index(Request $request)
    {
        $query = VendorModel::with(['account', 'defaultPaymentSource', 'businessUnit']);

        // Search
        // ⚠⚠ THIS WAS BROKEN TWO WAYS AND 500'd ON EVERY USE (fixed Aug-28 2026):
        //   1. `vendor_contact` and `vendor_email` are NOT columns of t_fin_vendors —
        //      the real ones are contact_person / contact_phone / contact_email. Typing
        //      anything into the web page's search box produced
        //      "Unknown column 'vendor_contact'" — a 500, not a result.
        //   2. The orWhere chain was UNGROUPED, so once it ran, SQL precedence let it
        //      escape the is_active and business_unit filters below and match vendors
        //      the page had explicitly excluded. HubController::vendors already wraps
        //      its own search in a closure for exactly this reason; this copy never was.
        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($w) use ($search) {
                $w->where('vendor_name', 'LIKE', "%{$search}%")
                  ->orWhere('vendor_code', 'LIKE', "%{$search}%")
                  ->orWhere('contact_person', 'LIKE', "%{$search}%")
                  ->orWhere('contact_phone', 'LIKE', "%{$search}%")
                  ->orWhere('contact_email', 'LIKE', "%{$search}%");
            });
        }

        // ⭐ Status. The app has always SENT `status` (active|inactive|all) and this
        //   method ignored it and hardcoded is_active = 1 — so its "Inactive" pill
        //   silently returned active vendors and an inactive vendor was unreachable
        //   from the phone entirely. Absent/unknown = active, which is exactly what
        //   the web page relies on (its form sends no status at all).
        $status = strtolower((string) $request->input('status', 'active'));
        if (!in_array($status, ['active', 'inactive', 'all'], true)) {
            $status = 'active';
        }
        if ($status === 'active') {
            $query->where('is_active', 1);
        } elseif ($status === 'inactive') {
            $query->where('is_active', 0);
        }

        // ⭐ Filter by business unit — defaults to BU 1 (Nizami Farms) unless explicitly set
        // Use "all" to see all business units, or a specific BU ID
        $buFilter = $request->input('business_unit_id');
        if ($buFilter === null && !$request->has('business_unit_id')) {
            // No filter param at all → default to BU 1 (Nizami Farms) for web views
            // For API requests, respect the absence (mobile passes its own filter)
            if (!$request->expectsJson() && !$request->is('api/*')) {
                $buFilter = '1';
            }
        }
        if ($buFilter && $buFilter !== 'all') {
            $query->where('business_unit_id', $buFilter);
        }

        $query->orderBy('vendor_name', 'asc');

        // Calculate total balance across the SAME set the list is showing, so the
        // headline can never describe a different population from the rows beneath it.
        $totalBalanceQuery = VendorModel::with('account');
        if ($status === 'active')        { $totalBalanceQuery->where('is_active', 1); }
        elseif ($status === 'inactive')  { $totalBalanceQuery->where('is_active', 0); }
        if ($buFilter && $buFilter !== 'all') {
            $totalBalanceQuery->where('business_unit_id', $buFilter);
        }
        $totalBalance = $totalBalanceQuery->get()
            ->sum(function($vendor) {
                return $vendor->account ? $vendor->account->current_balance : 0;
            });

        // ⭐⭐ THE API GETS THE WHOLE LIST (fixed Aug-28 2026).
        //
        // ⚠⚠ THE BUG: this method paginated at 20 for BOTH surfaces, and the phone
        //    reads only `vendors` — it never looks at `pagination.last_page` and its
        //    list has no infinite scroll, so page 2 was never requested by anything.
        //    Sorted by name, that silently cut every vendor past the 20th: with "All"
        //    business units selected the app showed 20 of 37 and everything from M
        //    onward simply did not exist as far as the phone was concerned. Searching
        //    could not rescue it either — the app filters the rows it already has, so
        //    a missing vendor read as "No vendors found".
        //
        // ⭐ The web page keeps its paginator (its Blade calls ->hasPages()/->links()),
        //   so nothing about that screen changes. `per_page` is honoured if a client
        //   ever wants pages back; absent, an API caller gets everything.
        if ($request->expectsJson() || $request->is('api/*')) {
            $perPage = (int) $request->input('per_page', 0);
            if ($perPage > 0) {
                $paged = $query->paginate($perPage);
                return response()->json([
                    'success' => true,
                    'vendors' => $paged->items(),
                    'total_balance' => $totalBalance,
                    'pagination' => [
                        'current_page' => $paged->currentPage(),
                        'last_page'    => $paged->lastPage(),
                        'per_page'     => $paged->perPage(),
                        'total'        => $paged->total(),
                    ],
                ]);
            }

            $all = $query->get();
            return response()->json([
                'success' => true,
                'vendors' => $all,
                'total_balance' => $totalBalance,
                // Kept for shape compatibility — one page containing everything, so a
                // client that DOES read it can never conclude there is more to fetch.
                'pagination' => [
                    'current_page' => 1,
                    'last_page'    => 1,
                    'per_page'     => $all->count(),
                    'total'        => $all->count(),
                ],
            ]);
        }

        $vendors = $query->paginate(20);

        // ⭐ Get business units for dropdown - filtered by user's access
        $businessUnits = AccountModel::getUserAccessibleBusinessUnits();
        
        // ⭐ Get accessible company accounts for payment source dropdown
        $accessibleCompanyAccounts = AccountModel::getAccessibleCompanyAccounts();
        
        // ⭐ Get user's default business unit ID
        $userDefaultBuId = AccountModel::getUserDefaultBusinessUnitId();

        // ⭐⭐ THE REPORT MODAL'S VENDOR PICKER NEEDS THE WHOLE LIST (Aug-28 2026).
        //    It used to iterate `$vendors`, i.e. the PAGINATED rows — so a vendor on
        //    page 2 could not be chosen for a report at all, and the choices silently
        //    changed with the page and the search box. A picker is not a page of
        //    results; it is the set of things that exist.
        // ⚠ Deliberately unfiltered by the page's search/BU controls for the same
        //   reason — narrowing the table must not narrow what you may report on.
        $pickerVendors = VendorModel::where('is_active', 1)
            ->orderBy('vendor_name', 'asc')
            ->get(['id', 'vendor_name']);

        return view('fin.vendor.index', compact('vendors', 'totalBalance', 'businessUnits', 'accessibleCompanyAccounts', 'userDefaultBuId', 'pickerVendors'));
    }

    /**
     * Get monthly purchase/payment summary for vendors in a business unit.
     * Used by Khaas mode mobile screen to show total purchases & payments for a month.
     *
     * GET /vendors/monthly-summary?business_unit_id=X&month=YYYY-MM
     */
    public function monthlySummary(Request $request)
    {
        try {
            $businessUnitId = $request->input('business_unit_id');
            $month = $request->input('month', now()->format('Y-m')); // Default: current month

            if (!$businessUnitId) {
                return response()->json(['success' => false, 'message' => 'business_unit_id is required'], 400);
            }

            // Parse month into date range
            $startDate = \Carbon\Carbon::createFromFormat('!Y-m', $month)->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();

            // Get all active vendors for this BU
            $vendors = VendorModel::with('account')
                ->where('is_active', 1)
                ->where('business_unit_id', $businessUnitId)
                ->orderBy('vendor_name')
                ->get();

            $vendorPurchases = [];
            $vendorPayments = [];
            $totalPurchases = 0;
            $totalPayments = 0;

            foreach ($vendors as $vendor) {
                if (!$vendor->account) continue;

                $purchases = LedgerModel::where('to_account_id', $vendor->account->id)
                    ->where('transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE)
                    ->whereBetween('transaction_date', [$startDate, $endDate])
                    ->sum('amount');

                $payments = LedgerModel::where('to_account_id', $vendor->account->id)
                    ->where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT)
                    ->whereBetween('transaction_date', [$startDate, $endDate])
                    ->sum('amount');

                if ($purchases > 0) {
                    $vendorPurchases[] = [
                        'vendor_id' => $vendor->id,
                        'vendor_name' => $vendor->vendor_name,
                        'amount' => round($purchases, 2),
                    ];
                    $totalPurchases += $purchases;
                }

                if ($payments > 0) {
                    $vendorPayments[] = [
                        'vendor_id' => $vendor->id,
                        'vendor_name' => $vendor->vendor_name,
                        'amount' => round($payments, 2),
                    ];
                    $totalPayments += $payments;
                }
            }

            // Sort by amount descending
            usort($vendorPurchases, fn($a, $b) => $b['amount'] <=> $a['amount']);
            usort($vendorPayments, fn($a, $b) => $b['amount'] <=> $a['amount']);

            // ⭐ Get the BU's default expense/fund account balance
            // Same logic as expenses: for non-NF BUs use getBuDefaultExpenseAccount, for NF use EXP_FUND
            $fundBalance = null;
            $fundAccountName = null;
            if ((int) $businessUnitId !== 1) {
                $fundAccount = \App\Models\FIN\ConfigModel::getBuDefaultExpenseAccount((int) $businessUnitId);
            } else {
                $fundAccount = \App\Models\FIN\ConfigModel::getExpenseFundingAccount()
                    ?? AccountModel::where('account_code', 'EXP_FUND')->first();
            }
            if ($fundAccount) {
                $fundBalance = round((float) $fundAccount->current_balance, 2);
                $fundAccountName = $fundAccount->account_name;
            }

            return response()->json([
                'success' => true,
                'month' => $month,
                'total_purchases' => round($totalPurchases, 2),
                'total_payments' => round($totalPayments, 2),
                'vendor_purchases' => $vendorPurchases,
                'vendor_payments' => $vendorPayments,
                'fund_balance' => $fundBalance,
                'fund_account_name' => $fundAccountName,
            ]);

        } catch (\Exception $e) {
            Log::error("Error fetching vendor monthly summary: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching summary: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show create vendor form
     */
    public function create()
    {
        return view('fin.vendor.create');
    }

    /**
     * Store new vendor
     */
    public function store(Request $request)
    {
        $request->validate([
            'vendor_name' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9\s\-\_\.\(\)]+$/'],
            'contact_person' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'default_purchase_method' => 'required|in:by_weight,by_total',
            'default_payment_source_id' => 'nullable|exists:t_fin_accounts,id',
            'opening_balance' => 'nullable|numeric|min:0',
            'business_unit_id' => 'nullable|exists:t_fin_business_units,id'
        ], [
            'vendor_name.regex' => 'Vendor name can only contain letters, numbers, spaces, hyphens (-), underscores (_), dots (.), and parentheses.'
        ]);

        try {
            DB::beginTransaction();

            // Create vendor account
            $account = AccountModel::createVendorAccount($request->vendor_name);

            // Set opening balance if provided
            if ($request->opening_balance && $request->opening_balance > 0) {
                $account->opening_balance = $request->opening_balance;
                $account->current_balance = $request->opening_balance;
                $account->save();

                // Create opening balance ledger entry
                $openingEquity = AccountModel::getByCode('EQUITY_OPENING');
                if ($openingEquity) {
                    LedgerModel::create([
                        'transaction_date' => now(),
                        'transaction_type' => LedgerModel::TYPE_OPENING_BALANCE,
                        'description' => "Opening balance for vendor: {$request->vendor_name}",
                        'from_account_id' => $openingEquity->id,
                        'to_account_id' => $account->id,
                        'amount' => $request->opening_balance,
                        'mode' => null,
                        'approval_status' => LedgerModel::STATUS_APPROVED,
                        'business_unit_id' => $request->business_unit_id ?? 1, // ⭐ Use selected BU
                        'created_by' => auth()->id()
                    ]);
                }
            }

            // Generate vendor code
            $vendorCode = 'VEN_' . strtoupper(str_replace([' ', '-', '.', '(', ')'], '_', $request->vendor_name));
            $vendorCode = substr($vendorCode, 0, 50);
            
            // Create vendor
            $vendor = VendorModel::create([
                'vendor_code' => $vendorCode,
                'vendor_name' => $request->vendor_name,
                'contact_person' => $request->contact_person,
                'contact_phone' => $request->contact_phone,
                'contact_email' => $request->contact_email,
                'default_purchase_method' => $request->default_purchase_method,
                'default_payment_source_id' => $request->default_payment_source_id,
                'account_id' => $account->id,
                'business_unit_id' => $request->business_unit_id ?? 1, // Default to Nizami Farms (id=1)
                'is_active' => 1,
                'created_by' => auth()->id()
            ]);

            DB::commit();

            return redirect()->route('fin.vendors.index')
                           ->with('success', 'Vendor created successfully!');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error creating vendor: " . $e->getMessage());
            
            return back()->withInput()
                       ->with('error', 'Error creating vendor: ' . $e->getMessage());
        }
    }

    /**
     * Show vendor details
     */
    public function show(Request $request, $id)
    {
        $vendor = VendorModel::with(['account', 'defaultPaymentSource'])->findOrFail($id);
        
        // Get date range from request
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        
        // Get ledger transactions with optional date filter
        if ($dateFrom && $dateTo) {
            $ledger = $vendor->getLedger()
                ->whereBetween('transaction_date', [$dateFrom, $dateTo]);
        } else {
            $ledger = $vendor->getLedger();
        }
        
        // Calculate running balance
        $runningBalance = $vendor->account ? $vendor->account->opening_balance : 0;
        $vendorAccountId = $vendor->account ? $vendor->account->id : null;
        
        $ledgerWithBalance = $ledger->map(function($transaction) use (&$runningBalance, $vendorAccountId) {
            // Check transaction type instead of account direction
            if ($transaction->transaction_type === 'vendor_purchase') {
                // Purchase - increases liability (vendor owes us or we owe vendor)
                $runningBalance += $transaction->amount;
            } elseif ($transaction->transaction_type === 'vendor_payment') {
                // Payment - decreases liability (we pay vendor)
                $runningBalance -= $transaction->amount;
            }
            
            $transaction->running_balance = $runningBalance;
            return $transaction;
        });

        // Get last payment info
        $lastPayment = null;
        if ($vendor->account) {
            $lastPayment = LedgerModel::where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT)
                ->where('to_account_id', $vendor->account->id)
                ->orderBy('transaction_date', 'desc')
                ->orderBy('created_at', 'desc')
                ->first();
        }

        // Calculate week dates (Tuesday to Monday)
        // Week starts on Tuesday and ends on Monday
        // Tuesday purchases count towards the NEXT week (starting that Tuesday)
        $today = \Carbon\Carbon::now();
        $dayOfWeek = $today->dayOfWeek; // 0=Sunday, 1=Monday, 2=Tuesday, 3=Wednesday, etc.
        
        // Find the start of current week (last Tuesday)
        if ($dayOfWeek >= 2) { // Tuesday to Saturday
            $thisWeekStart = $today->copy()->subDays($dayOfWeek - 2)->startOfDay();
        } else { // Sunday or Monday
            $thisWeekStart = $today->copy()->subDays($dayOfWeek + 5)->startOfDay();
        }
        
        $thisWeekEnd = $thisWeekStart->copy()->addDays(6)->endOfDay(); // Next Monday 23:59:59
        $lastWeekStart = $thisWeekStart->copy()->subWeek()->startOfDay();
        $lastWeekEnd = $thisWeekStart->copy()->subDay()->endOfDay();

        // Get purchases for this week and last week
        $purchasesThisWeek = 0;
        $purchasesLastWeek = 0;
        
        if ($vendor->account) {
            $purchasesThisWeek = LedgerModel::where('to_account_id', $vendor->account->id)
                ->where('transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE)
                ->whereBetween('transaction_date', [$thisWeekStart, $thisWeekEnd])
                ->sum('amount');
            
            $purchasesLastWeek = LedgerModel::where('to_account_id', $vendor->account->id)
                ->where('transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE)
                ->whereBetween('transaction_date', [$lastWeekStart, $lastWeekEnd])
                ->sum('amount');
        }

        // Get last 5 payments
        $lastFivePayments = [];
        if ($vendor->account) {
            $lastFivePayments = LedgerModel::where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT)
                ->where('to_account_id', $vendor->account->id)
                ->orderBy('transaction_date', 'desc')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get();
        }

        // Get filtered purchases and payments based on date range
        $filteredPurchases = 0;
        $filteredPayments = 0;
        
        if ($vendor->account) {
            $purchaseQuery = LedgerModel::where('to_account_id', $vendor->account->id)
                ->where('transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE);
            
            $paymentQuery = LedgerModel::where('to_account_id', $vendor->account->id)
                ->where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT);
            
            if ($dateFrom && $dateTo) {
                $purchaseQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
                $paymentQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
            }
            
            $filteredPurchases = $purchaseQuery->sum('amount');
            $filteredPayments = $paymentQuery->sum('amount');
        }
        
        // If no filter applied, use total values
        if (!$dateFrom && !$dateTo) {
            $filteredPurchases = $vendor->getTotalPurchases();
            $filteredPayments = $vendor->getTotalPayments();
        }

        // Get summary
        $summary = [
            'current_balance' => $vendor->getBalance(),
            'last_payment_date' => $lastPayment ? $lastPayment->transaction_date : null,
            'last_payment_amount' => $lastPayment ? $lastPayment->amount : null,
            'purchases_this_week' => $purchasesThisWeek,
            'purchases_last_week' => $purchasesLastWeek,
            'filtered_purchases' => $filteredPurchases,
            'filtered_payments' => $filteredPayments,
            'total_payments' => $vendor->getTotalPayments(),
            'last_five_payments' => $lastFivePayments
        ];

        // Group transactions by date for better organization
        $groupedTransactions = $ledgerWithBalance->groupBy(function($transaction) {
            return $transaction->transaction_date ? $transaction->transaction_date->format('Y-m-d') : 'unknown';
        })->sortKeysDesc(); // Sort dates descending (latest first)
        
        // Calculate daily summaries
        $dailySummaries = [];
        foreach ($groupedTransactions as $date => $transactions) {
            $purchases = 0;
            $payments = 0;
            $endBalance = 0;
            
            foreach ($transactions as $txn) {
                if ($txn->transaction_type === 'vendor_purchase') {
                    $purchases += $txn->amount;
                }
                if ($txn->transaction_type === 'vendor_payment') {
                    $payments += $txn->amount;
                }
                $endBalance = $txn->running_balance; // Last transaction's balance
            }
            
            $dailySummaries[$date] = [
                'purchases' => $purchases,
                'payments' => $payments,
                'net' => $purchases - $payments,
                'end_balance' => $endBalance,
                'transaction_count' => $transactions->count()
            ];
        }
        
        // Get expand preference from session (default: collapsed)
        $expandAll = session('vendor_transactions_expand_all', false);
        
        // Return JSON for API requests
        if ($request->expectsJson() || $request->is('api/*')) {
            // Format grouped transactions for API
            $formattedTransactions = [];
            foreach ($groupedTransactions as $date => $transactions) {
                $formattedTransactions[$date] = [
                    'transactions' => $transactions->toArray(),
                    'summary' => $dailySummaries[$date] ?? []
                ];
            }
            
            return response()->json([
                'success' => true,
                'vendor' => $vendor,
                'grouped_transactions' => $formattedTransactions,
                'summary' => $summary,
                // 🔀 Sep-27: the phone shows the "by weight / by total" switch only when true.
                'can_switch_purchase_method' => $this->canSwitchPurchaseMethod($vendor),
            ]);
        }
        
        // ⭐ Get accessible company accounts for payment source dropdown
        $accessibleCompanyAccounts = AccountModel::getAccessibleCompanyAccounts();

        // Receiving banks (with computed balances) for the mandatory bank picker
        // shown when an ONLINE payment source is chosen.
        $bankBalances = app(\App\Services\FIN\BankBalanceService::class)->balancesByBank();
        $receivingBanks = \App\Models\FIN\OnlineReceivingAccountModel::active()->ordered()
            ->get(['id', 'name', 'short_code', 'color_hex', 'opening_balance'])
            ->map(fn ($acc) => [
                'id' => $acc->id,
                'name' => $acc->name,
                'short_code' => $acc->short_code,
                'color_hex' => $acc->color_hex,
                'balance' => $bankBalances[(int) $acc->id]['balance'] ?? (float) $acc->opening_balance,
            ])->values();

        return view('fin.vendor.show', compact('vendor', 'ledgerWithBalance', 'groupedTransactions', 'dailySummaries', 'summary', 'expandAll', 'accessibleCompanyAccounts', 'receivingBanks'));
    }

    /**
     * Show edit form
     */
    public function edit($id)
    {
        $vendor = VendorModel::findOrFail($id);
        
        return view('fin.vendor.edit', compact('vendor'));
    }

    /**
     * Update vendor
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'vendor_name' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9\s\-\_\.\(\)]+$/'],
            'contact_person' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'default_purchase_method' => 'required|in:by_weight,by_total',
            'default_payment_source_id' => 'nullable|exists:t_fin_accounts,id',
            'business_unit_id' => 'nullable|exists:t_fin_business_units,id'
        ], [
            'vendor_name.regex' => 'Vendor name can only contain letters, numbers, spaces, hyphens (-), underscores (_), dots (.), and parentheses.'
        ]);

        try {
            $vendor = VendorModel::findOrFail($id);

            $vendor->update([
                'vendor_name' => $request->vendor_name,
                'contact_person' => $request->contact_person,
                'contact_phone' => $request->contact_phone,
                'contact_email' => $request->contact_email,
                'default_purchase_method' => $request->default_purchase_method,
                'default_payment_source_id' => $request->default_payment_source_id,
                'business_unit_id' => $request->business_unit_id ?? $vendor->business_unit_id ?? 1,
                'updated_by' => auth()->id()
            ]);

            return redirect()->route('fin.vendors.index')
                           ->with('success', 'Vendor updated successfully!');

        } catch (\Exception $e) {
            Log::error("Error updating vendor: " . $e->getMessage());
            
            return back()->withInput()
                       ->with('error', 'Error updating vendor: ' . $e->getMessage());
        }
    }

    /**
     * Toggle vendor active/inactive status
     */
    public function toggleStatus($id)
    {
        try {
            $vendor = VendorModel::findOrFail($id);
            
            $vendor->is_active = !$vendor->is_active;
            $vendor->updated_by = auth()->id();
            $vendor->save();

            return response()->json([
                'success' => true,
                'message' => 'Vendor status updated successfully',
                'is_active' => $vendor->is_active
            ]);

        } catch (\Exception $e) {
            Log::error('Vendor toggle status error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update vendor status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete vendor (only if balance is zero)
     */
    public function destroy($id)
    {
        try {
            $vendor = VendorModel::with('account')->findOrFail($id);

            // Safety check: Only allow deletion if balance is zero
            if ($vendor->account && $vendor->account->current_balance != 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete vendor with non-zero balance. Current balance: Rs. ' . number_format($vendor->account->current_balance, 2)
                ], 400);
            }

            // Check if there are any ledger transactions
            $hasTransactions = LedgerModel::where(function($q) use ($vendor) {
                $q->where('from_account_id', $vendor->account_id)
                  ->orWhere('to_account_id', $vendor->account_id);
            })->exists();

            if ($hasTransactions) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete vendor with transaction history. Consider marking as inactive instead.'
                ], 400);
            }

            DB::beginTransaction();

            // Store account ID before deleting vendor
            $accountId = $vendor->account_id;

            // Set account_id to null first to avoid foreign key constraint
            $vendor->account_id = null;
            $vendor->save();

            // Delete the vendor
            $vendor->delete();

            // Delete the vendor account
            if ($accountId) {
                $account = AccountModel::find($accountId);
                if ($account) {
                    $account->delete();
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Vendor deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Vendor deletion error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete vendor: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Record purchase
     */
    public function recordPurchase(Request $request, $id)
    {
        $request->validate(array_merge([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:500',
            'transaction_date' => 'required|date',
        ], $this->imageValidationRules('bill_image')));

        try {
            DB::beginTransaction();

            $vendor = VendorModel::with('account')->findOrFail($id);
            $purchaseAccount = AccountModel::getByCode('EXP_PURCHASES');

            if (!$purchaseAccount) {
                throw new \Exception("Purchase expense account not found");
            }

            // All attached bill images (plural fields + the legacy single field).
            $imagePaths = $this->collectImageUploads($request, 'bill_image', $vendor);

            // Create ledger entry. bill_image is written by attachImages() below
            // (mirror of the first image — the legacy-reader contract).
            $ledger = LedgerModel::create([
                'transaction_date' => $request->transaction_date,
                'transaction_type' => LedgerModel::TYPE_VENDOR_PURCHASE,
                'description' => $request->description ?: "Purchase from {$vendor->vendor_name}",
                'from_account_id' => $purchaseAccount->id,
                'to_account_id' => $vendor->account->id,
                'amount' => $request->amount,
                'mode' => LedgerModel::MODE_CASH,
                'approval_status' => LedgerModel::STATUS_APPROVED,
                'business_unit_id' => $vendor->business_unit_id ?? 1, // ⭐ Use vendor's BU
                'created_by' => auth()->id()
            ]);
            $this->attachImages($ledger, $imagePaths);

            // Apply via the canonical engine (vendor_purchase: purchases/expense +, vendor owed +),
            // row-locked; sets balance_updated. Same net move as the old inline code.
            (new \App\Services\FIN\BalancePostingService())->apply($ledger);

            DB::commit();

            // Return JSON for API requests
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => true,
                    'message' => 'Purchase recorded successfully!'
                ]);
            }

            return redirect()->route('fin.vendors.show', $vendor->id)
                           ->with('success', 'Purchase recorded successfully!');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error recording purchase: " . $e->getMessage());
            
            // Return JSON for API requests
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error recording purchase: ' . $e->getMessage()
                ], 500);
            }
            
            return back()->withInput()
                       ->with('error', 'Error recording purchase: ' . $e->getMessage());
        }
    }

    /**
     * Record payment
     */
    public function recordPayment(Request $request, $id)
    {
        $request->validate(array_merge([
            'amount' => 'required|numeric|min:0.01',
            'payment_source_account_id' => 'nullable|exists:t_fin_accounts,id',
            // Which of OUR banks an ONLINE vendor payment is made from —
            // required below when the paying account is a bank.
            'receiving_account_id' => 'nullable|integer|exists:t_fin_online_receiving_accounts,id',
            'description' => 'nullable|string|max:500',
            'transaction_date' => 'required|date',
            'posted_date' => 'nullable|date', // Date when entry is posted to ledger
        ], $this->imageValidationRules('receipt_image')));

        try {
            DB::beginTransaction();

            $vendor = VendorModel::with('account')->findOrFail($id);

            // Get payment source account (user selection or default to NF Cash)
            if ($request->payment_source_account_id) {
                $paymentAccount = AccountModel::findOrFail($request->payment_source_account_id);
            } else {
                $paymentAccount = AccountModel::getByCode('NF_CASH');
            }

            if (!$paymentAccount) {
                throw new \Exception("Payment source account not found");
            }

            // ONLINE vendor payment → the specific bank is mandatory so per-bank
            // balances reconcile with the ONLINE account.
            $isOnlinePayment = $paymentAccount->account_category === AccountModel::CATEGORY_BANK;
            $vendorBankId = $isOnlinePayment ? $request->receiving_account_id : null;
            if ($isOnlinePayment && !$vendorBankId) {
                DB::rollBack();
                $msg = 'Select which bank this online payment is made from.';
                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json(['success' => false, 'message' => $msg], 422);
                }
                return back()->withInput()->with('error', $msg);
            }

            // Check if payment amount exceeds vendor balance
            if ($request->amount > $vendor->getBalance()) {
                throw new \Exception("Payment amount cannot exceed vendor balance");
            }

            // All attached receipt images (plural fields + the legacy single field).
            $receiptImagePaths = $this->collectImageUploads($request, 'receipt_image', $vendor);

            // Check approval configuration for vendor payments
            $vendorPaymentCategory = \App\Models\Request\RequestCategoryModel::getByCode('vendor_payment');
            $requiresApproval = false;
            
            if ($vendorPaymentCategory && $vendorPaymentCategory->approvalConfig) {
                // Check if any approval level is required
                $requiresApproval = $vendorPaymentCategory->approvalConfig->requires_level_1 || 
                                   $vendorPaymentCategory->approvalConfig->requires_level_2;
            }
            
            // Aug-2026: even when the category demands approval, don't queue it against someone
            // who already holds every level it requires — they would only be approving their own
            // entry. `vendor_payment` needs NO levels today, so this changes nothing right now;
            // it is here so that turning approval ON in Request Settings can never strand the
            // approvers' own payments the way it stranded online transfers. (SelfApprovalPolicy.)
            $selfApproved = $requiresApproval
                && app(\App\Services\FIN\SelfApprovalPolicy::class)
                    ->canSelfApprove(LedgerModel::TYPE_VENDOR_PAYMENT, auth()->id());

            $approvalStatus = ($requiresApproval && !$selfApproved)
                ? LedgerModel::STATUS_PENDING
                : LedgerModel::STATUS_APPROVED;
            // Any bank-category paying account is an online movement (matches the
            // server-wide rule used by expenses / Bank Balances), not just the
            // account literally coded 'ONLINE'.
            $mode = $isOnlinePayment ? LedgerModel::MODE_ONLINE : LedgerModel::MODE_CASH;
            
            \Log::info("Vendor payment approval check", [
                'vendor_id' => $vendor->id,
                'vendor_name' => $vendor->vendor_name,
                'amount' => $request->amount,
                'payment_source' => $paymentAccount->account_name,
                'payment_source_code' => $paymentAccount->account_code,
                'requires_approval' => $requiresApproval,
                'approval_status' => $approvalStatus,
                'category_found' => $vendorPaymentCategory ? true : false,
                'config_exists' => $vendorPaymentCategory && $vendorPaymentCategory->approvalConfig ? true : false
            ]);

            // Create ledger entry
            // Dr Vendor Account (liability decreases) → Cr Payment Account (cash/bank decreases)
            // posted_date: date when entry is posted to ledger (for balance calculations)
            // transaction_date: actual date of transaction (for vendor records)
            $postedDate = $request->posted_date ?? $request->transaction_date;
            
            // Always name the VENDOR in the description — a custom note is
            // appended, never substituted, so listings always show who was paid.
            $vendorPayDescription = "Payment to {$vendor->vendor_name}";
            if ($request->filled('description')) {
                $vendorPayDescription .= " — " . trim($request->description);
            }
            // Name the bank too, so the ledger listing shows at a glance which
            // bank the money left from.
            if ($vendorBankId) {
                $vendorBankShort = \App\Models\FIN\OnlineReceivingAccountModel::find($vendorBankId)?->short_code;
                if ($vendorBankShort) {
                    $vendorPayDescription .= " · via {$vendorBankShort}";
                }
            }

            $ledger = LedgerModel::create([
                'transaction_date' => $request->transaction_date,
                'posted_date' => $postedDate,
                'transaction_type' => LedgerModel::TYPE_VENDOR_PAYMENT,
                'description' => $vendorPayDescription,
                'from_account_id' => $paymentAccount->id,  // Money leaving from payment source
                'to_account_id' => $vendor->account->id,   // Money going to settle vendor liability
                'amount' => $request->amount,
                'mode' => $mode,
                // Which of OUR banks the online payment left from (null for cash)
                // — keeps per-bank balances reconciled.
                'receiving_account_id' => $vendorBankId,
                'approval_status' => $approvalStatus,
                'approval_date' => ($approvalStatus === LedgerModel::STATUS_APPROVED) ? now() : null,
                'business_unit_id' => $vendor->business_unit_id ?? 1, // ⭐ Use vendor's BU
                'approved_by' => ($approvalStatus === LedgerModel::STATUS_APPROVED) ? auth()->id() : null,
                // bill_image is written by attachImages() below (mirror of the first
                // receipt — receipts live in the same field/table as purchase bills).
                'created_by' => auth()->id(),
                'comments' => "Paid from: {$paymentAccount->account_name}"
                    . ($selfApproved
                        ? ' | ' . app(\App\Services\FIN\SelfApprovalPolicy::class)
                            ->auditNote(LedgerModel::TYPE_VENDOR_PAYMENT, auth()->id())
                        : '')
            ]);
            $this->attachImages($ledger, $receiptImagePaths);

            // Apply via the canonical engine when approved (vendor_payment: vendor owed −, till/bank −),
            // row-locked; sets balance_updated. A pending payment stays flag=0 and is applied later by
            // the online approval (approve() → engine). Same net move as the old inline code.
            if ($approvalStatus === LedgerModel::STATUS_APPROVED) {
                (new \App\Services\FIN\BalancePostingService())->apply($ledger);
            }

            DB::commit();

            $message = ($approvalStatus === LedgerModel::STATUS_PENDING) 
                ? 'Payment recorded and pending approval!' 
                : 'Payment recorded successfully!';

            // Return JSON for API requests
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    // ⭐ Which ledger row this became. Purely ADDITIVE (Aug-2026)
                    // — no existing caller reads it, the web branch below is
                    // untouched. Two things need it: the assistant stamps it
                    // onto the bank SMS that proves the payment ("what was
                    // tagged to what"), and the money-inbox sweep counts a row
                    // it can name as CLAIMED, so the same entry can never also
                    // close a look-alike debit. Vendor payments returned no id
                    // at all before this, which is why they were invisible to
                    // that claimed-set.
                    'transaction_id' => $ledger->id,
                    // ⭐ "Is this payment's bank SMS still sitting in the money
                    // box?" Read-only and best-effort — it only ever ADDS a
                    // question to the response, and a failure here cannot
                    // affect the payment that was just recorded. null = say
                    // nothing (the normal case).
                    'bank_sms_prompt' => $this->bankSmsPrompt($ledger->id),
                ]);
            }

            // The classic vendor page posts a normal form and lands back here,
            // so its version of "is this the bank SMS?" travels in the session
            // rather than a JSON key. Same service, same answer — only the
            // delivery differs. Flashed only when there is something to ask.
            $redirect = redirect()->route('fin.vendors.show', $vendor->id)
                           ->with('success', $message);
            $prompt = $this->bankSmsPrompt($ledger->id);
            if ($prompt) {
                $redirect->with('bank_sms_prompt', $prompt);
            }
            return $redirect;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error recording payment: " . $e->getMessage());
            
            // Return JSON for API requests
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error recording payment: ' . $e->getMessage()
                ], 500);
            }
            
            return back()->withInput()
                       ->with('error', 'Error recording payment: ' . $e->getMessage());
        }
    }

    /**
     * ⭐ Sep-26 — what every weighted-purchase line must satisfy, on create AND edit:
     *   • it names a product on THIS vendor's list (the validator only checked the id
     *     existed anywhere — a line could carry another Frozen vendor's tagged product
     *     and push that ingredient's rate around);
     *   • a counted unit (piece / dozen / pack / box) is a whole number on a Frozen
     *     vendor. BU 1 meat keeps its 7.5 trotters (owner, Sep-26).
     * Returns the 422 to send, or null.
     */
    private function purchaseLinesProblem($items, VendorModel $vendor): ?\Illuminate\Http\JsonResponse
    {
        $items = is_array($items) ? array_values($items) : [];
        $ids = collect($items)->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->all();
        $products = VendorProductModel::whereIn('id', $ids)->get()->keyBy('id');

        foreach ($items as $i => $item) {
            $p = $products->get((int) ($item['product_id'] ?? 0));
            $n = $i + 1;
            if (!$p || (int) $p->vendor_id !== (int) $vendor->id) {
                return response()->json([
                    'success' => false,
                    'code'    => 'product_not_on_vendor',
                    'message' => "Line {$n}: that product is not on {$vendor->vendor_name}'s list. Pick one of this vendor's products.",
                    'line'    => $i,
                ], 422);
            }
            $qty = (float) ($item['quantity'] ?? 0);
            if (VendorUnits::wholeOnly($p->unit, (int) $vendor->business_unit_id) && floor($qty) != $qty) {
                return response()->json([
                    'success' => false,
                    'code'    => 'whole_number',
                    'message' => "Line {$n}: {$p->product_name} is counted in " . VendorUnits::word($p->unit, true)
                        . " — whole numbers only (you typed {$qty}).",
                    'line'    => $i,
                ], 422);
            }
        }
        return null;
    }

    /** The unit a line is stored with: its catalogue product's, falling back to what was sent. */
    private function lineUnit(array $item, $products): string
    {
        $p = $products->get((int) ($item['product_id'] ?? 0));
        return $p && trim((string) $p->unit) !== '' ? (string) $p->unit : (string) ($item['unit'] ?? 'kg');
    }

    /**
     * ⚠⚠ WHO MAY EDIT OR DELETE A VENDOR PURCHASE / PAYMENT (owner, Sep-26: "the regular
     *    rules — keep what Qasim has"). Before this the two doors checked nothing, so any
     *    logged-in token — a rider's included — could rewrite or delete any vendor's
     *    money in any business unit. In fact only Taimur, Shabib and Qasim ever have
     *    (audit log, all time), and Qasim only ever on Frozen rows.
     *    Rule: `manage_vendor_transactions`, or Frozen mode on a Frozen row.
     */
    private function canChangeVendorTransaction(LedgerModel $transaction): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }
        if ($user->hasMobilePermission('manage_vendor_transactions')) {
            return true;
        }
        return (int) $transaction->business_unit_id === 2 && $user->hasMobilePermission('access_khaas_mode');
    }

    /**
     * 🔀 Who may switch a vendor between "by total" and "by weight" (itemised).
     * Owner, 27-Sep: Qasim and the team decide which Frozen vendors get itemised bills,
     * so Frozen mode may switch a FROZEN vendor; everyone else needs the same key that
     * edits vendor purchases.
     */
    private function canSwitchPurchaseMethod(VendorModel $vendor): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }
        if ($user->hasMobilePermission('manage_vendor_transactions')) {
            return true;
        }
        return (int) $vendor->business_unit_id === 2 && $user->hasMobilePermission('access_khaas_mode');
    }

    /**
     * 🔀 Switch how this vendor's bills are recorded. Nothing already recorded changes:
     * a bill keeps the lines (or the single total) it was saved with, and the product
     * list is kept either way, so switching back loses nothing.
     */
    public function setPurchaseMethod(Request $request, $id)
    {
        $vendor = VendorModel::find($id);
        if (!$vendor) {
            return response()->json(['success' => false, 'message' => 'That vendor no longer exists.'], 404);
        }
        if (!$this->canSwitchPurchaseMethod($vendor)) {
            return response()->json([
                'success' => false,
                'message' => 'Only Frozen staff (for Frozen vendors), Taimur or Shabib can change how a vendor\'s bills are recorded.',
            ], 403);
        }

        $method = (string) $request->input('purchase_method');
        if (!in_array($method, ['by_weight', 'by_total'], true)) {
            return response()->json(['success' => false, 'message' => 'Choose "by weight" or "by total".'], 422);
        }

        if ($vendor->default_purchase_method === $method) {
            return response()->json(['success' => true, 'message' => 'No change.', 'purchase_method' => $method]);
        }

        $vendor->default_purchase_method = $method;
        $vendor->updated_by = auth()->id();
        $vendor->save();

        Log::info('Vendor purchase method switched', [
            'vendor_id' => $vendor->id, 'to' => $method, 'by' => auth()->id(),
        ]);

        return response()->json([
            'success'         => true,
            'purchase_method' => $method,
            'message'         => $method === 'by_weight'
                ? "Bills from {$vendor->vendor_name} are now entered line by line. Add its products on the Products screen; bills already recorded are unchanged."
                : "Bills from {$vendor->vendor_name} are now entered as one total. Its product list is kept, and bills already recorded are unchanged.",
        ]);
    }

    private function refuseVendorTransactionChange(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'You can record purchases and payments, but not change or delete them. Ask Taimur or Shabib.',
        ], 403);
    }

    /**
     * Record weighted purchase (with line items)
     */
    public function recordWeightedPurchase(Request $request, $id)
    {
        $request->validate(array_merge([
            'transaction_date' => 'required|date',
            'description' => 'nullable|string|max:500',
            'adjustment_amount' => 'nullable|numeric', // Can be positive or negative
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:t_fin_vendor_products,id',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.rate' => 'required|numeric|min:0.01',
            'items.*.unit' => 'required|string|max:50',
            'items.*.product_name' => 'required|string|max:255',
            'client_uuid' => 'nullable|string|max:36',
        ], $this->imageValidationRules('bill_image')));

        // ⭐ Sep-26 — checked BEFORE the try, so each refusal reaches the person as the
        //   sentence it is (inside, the catch-all would turn it into a bare 500).
        // ⚠ The web "Record purchase" is a plain form POST, not fetch — a JSON refusal there
        //   would render as a raw JSON page. The phone and the scan card get JSON.
        $refuse = function (\Illuminate\Http\JsonResponse $j) use ($request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return $j;
            }
            return back()->withInput()->with('error', $j->getData(true)['message'] ?? 'Not recorded.');
        };
        $vendorForCheck = VendorModel::find($id);
        if (!$vendorForCheck) {
            return $refuse(response()->json(['success' => false, 'message' => 'That vendor no longer exists.'], 404));
        }
        if ($problem = $this->purchaseLinesProblem($request->items, $vendorForCheck)) {
            return $refuse($problem);
        }
        $lineProducts = VendorProductModel::whereIn('id', collect($request->items)->pluck('product_id')->all())
            ->get()->keyBy('id');

        // ⭐⭐ ONE PRESS, ONE PURCHASE. The phone's 30 s timeout used to say "Failed" while
        //    the server had in fact committed, and the retry booked the bill twice. A
        //    client that sends a uuid (the new APK) gets the first answer back instead.
        //    An old APK sends none and behaves exactly as before.
        $idemKey = null;
        $idemLock = null;
        $uuid = (string) $request->input('client_uuid');
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            $idemKey = 'wpurchase:' . auth()->id() . ':' . strtolower($uuid);
            if ($done = \Cache::get($idemKey)) {
                return response()->json([
                    'success' => true, 'already' => true, 'transaction_id' => $done,
                    'message' => 'This purchase was already recorded — nothing was booked twice.',
                ]);
            }
            $idemLock = \Cache::lock($idemKey . ':lock', 60);
            if (!$idemLock->get()) {
                return response()->json([
                    'success' => false, 'in_progress' => true,
                    'message' => 'This purchase is still being saved. Wait a moment, then check the list before trying again.',
                ], 409);
            }
        }

        try {
            DB::beginTransaction();

            $vendor = VendorModel::with('account')->findOrFail($id);
            $purchaseAccount = AccountModel::getByCode('EXP_PURCHASES');

            if (!$purchaseAccount) {
                throw new \Exception("Purchase expense account not found");
            }

            // All attached bill images (plural fields + the legacy single field).
            $imagePaths = $this->collectImageUploads($request, 'bill_image', $vendor);

            // Calculate grand total from line items
            $itemsTotal = 0;
            $itemsSummary = [];
            
            foreach ($request->items as $item) {
                $lineTotal = $item['quantity'] * $item['rate'];
                $itemsTotal += $lineTotal;
                $lineUnit = $this->lineUnit($item, $lineProducts);

                $itemsSummary[] = "{$item['product_name']} ({$item['quantity']} {$lineUnit} @ Rs.{$item['rate']})";
            }

            // Apply adjustment amount (can be positive or negative)
            $adjustmentAmount = floatval($request->adjustment_amount ?? 0);
            $grandTotal = $itemsTotal + $adjustmentAmount;

            // Create description
            $description = $request->description ?? "Weighted purchase with " . count($request->items) . " items";
            if ($adjustmentAmount != 0) {
                $adjustmentLabel = $adjustmentAmount > 0 ? '+' : '';
                $description .= " (Adjustment: {$adjustmentLabel}" . number_format($adjustmentAmount, 2) . ")";
            }
            $comments = implode(', ', $itemsSummary);

            // Create ledger entry. bill_image is written by attachImages() below
            // (mirror of the first image — the legacy-reader contract).
            $ledger = LedgerModel::create([
                'transaction_date' => $request->transaction_date,
                'transaction_type' => LedgerModel::TYPE_VENDOR_PURCHASE,
                'description' => $description,
                'from_account_id' => $purchaseAccount->id,
                'to_account_id' => $vendor->account->id,
                'amount' => $grandTotal,
                'adjustment_amount' => $adjustmentAmount,
                'mode' => LedgerModel::MODE_CASH,
                'approval_status' => LedgerModel::STATUS_APPROVED,
                'business_unit_id' => $vendor->business_unit_id ?? 1, // ⭐ Use vendor's BU
                'created_by' => auth()->id(),
                'comments' => $comments
            ]);
            $this->attachImages($ledger, $imagePaths);

            // Create line items
            foreach ($request->items as $item) {
                // ❄ Sep-2026: stamp what this line was in ingredient terms, so Frozen
                // can later say "we bought 5 L of oil and used 3.2". Nulls when the
                // catalogue product is untagged or the line is not an ingredient —
                // which is every line that exists today, so nothing changes for them.
                // Returns an EMPTY array when the migration has not run yet, so the two
                // new keys are simply absent and the insert is byte-identical to the one
                // that has always run here.
                $ingredient = \App\Models\FIN\VendorPurchaseItemModel::supportsIngredients()
                    ? \App\Models\FIN\VendorPurchaseItemModel::ingredientColumnsFor(
                        $item['product_id'] ?? null, (float) $item['quantity'], $item)
                    : [];

                \App\Models\FIN\VendorPurchaseItemModel::create(array_merge([
                    'ledger_id' => $ledger->id,
                    'vendor_product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'quantity' => $item['quantity'],
                    // ⭐ The PRODUCT's unit, not whatever the client sent — the line and
                    //   the catalogue can no longer disagree (0 lines differ today).
                    'unit' => $this->lineUnit($item, $lineProducts),
                    'rate_per_unit' => $item['rate'],
                    'line_total' => $item['quantity'] * $item['rate'],
                ], $ingredient));
            }

            // Apply via the canonical engine (vendor_purchase: purchases +, vendor owed +), row-locked;
            // sets balance_updated. Same net move as the old inline code.
            (new \App\Services\FIN\BalancePostingService())->apply($ledger);

            DB::commit();
            if ($idemKey) {
                \Cache::put($idemKey, $ledger->id, now()->addDays(2));
                optional($idemLock)->release();
            }

            // Return JSON for API requests
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => true,
                    // Which ledger row this became — additive (Aug-2026), same
                    // as recordPayment. The assistant needs it to record what a
                    // confirmed card produced; no existing caller reads it and
                    // the web redirect branch below is untouched.
                    'transaction_id' => $ledger->id,
                    'message' => 'Weighted purchase recorded successfully! Total: Rs. ' . number_format($grandTotal, 2)
                ]);
            }

            return redirect()->route('fin.vendors.show', $vendor->id)
                           ->with('success', 'Weighted purchase recorded successfully! Total: Rs. ' . number_format($grandTotal, 2));

        } catch (\Exception $e) {
            DB::rollBack();
            optional($idemLock)->release();
            Log::error("Error recording weighted purchase: " . $e->getMessage());
            
            // Return JSON for API requests
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error recording weighted purchase: ' . $e->getMessage()
                ], 500);
            }
            
            return back()->withInput()
                       ->with('error', 'Error recording weighted purchase: ' . $e->getMessage());
        }
    }

    /**
     * Generate vendor report with daily summary
     */
    public function getReport(Request $request)
    {
        try {
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');
            $vendorId = $request->input('vendor_id');
            $showPayments = $request->input('show_payments', '1') === '1'; // Default to showing payments

            if (!$dateFrom || !$dateTo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please provide both start and end dates'
                ], 400);
            }

            // Build vendor query
            $vendorQuery = VendorModel::with('account')->where('is_active', 1);
            
            if ($vendorId) {
                $vendorQuery->where('id', $vendorId);
            }
            
            $vendors = $vendorQuery->orderBy('vendor_name')->get();

            $reportData = [];
            $grandTotalPurchases = 0;
            $grandTotalPayments = 0;

            foreach ($vendors as $vendor) {
                if (!$vendor->account) {
                    continue;
                }

                // Get all transactions for this vendor in the date range
                $transactionTypes = [LedgerModel::TYPE_VENDOR_PURCHASE];
                if ($showPayments) {
                    $transactionTypes[] = LedgerModel::TYPE_VENDOR_PAYMENT;
                }
                
                $transactions = LedgerModel::with('fromAccount')
                    ->where(function($q) use ($vendor) {
                        $q->where('from_account_id', $vendor->account->id)
                          ->orWhere('to_account_id', $vendor->account->id);
                    })
                    ->whereBetween('transaction_date', [$dateFrom, $dateTo])
                    ->whereIn('transaction_type', $transactionTypes)
                    ->orderBy('transaction_date')
                    ->orderBy('created_at')
                    ->get();

                if ($transactions->isEmpty()) {
                    continue; // Skip vendors with no transactions in this period
                }

                // Group transactions by date
                $dailySummary = [];
                $vendorTotalPurchases = 0;
                $vendorTotalPayments = 0;

                foreach ($transactions as $txn) {
                    // Format date with day of week: "Nov 7, 2025 Friday"
                    $date = $txn->transaction_date->format('M j, Y l');
                    
                    if (!isset($dailySummary[$date])) {
                        $dailySummary[$date] = [
                            'date' => $date,
                            'total_purchases' => 0,
                            'total_payments' => 0,
                            'total_payments_online' => 0,
                            'total_payments_cash' => 0,
                            'transactions' => []
                        ];
                    }

                    $isPurchase = $txn->transaction_type === LedgerModel::TYPE_VENDOR_PURCHASE;
                    $amount = $txn->amount;

                    // Fetch line items for weighted purchases
                    $lineItems = [];
                    if ($isPurchase) {
                        $lineItems = \App\Models\FIN\VendorPurchaseItemModel::where('ledger_id', $txn->id)
                            ->get()
                            ->map(function($item) {
                                return [
                                    'product_name' => $item->product_name,
                                    'quantity' => $item->quantity,
                                    'unit' => $item->unit,
                                    'rate_per_unit' => $item->rate_per_unit,
                                    'line_total' => $item->line_total
                                ];
                            })
                            ->toArray();
                    }

                    // Determine payment mode for payments (Online or Cash)
                    $paymentMode = null;
                    if (!$isPurchase) {
                        // Load the fromAccount to check account_code
                        $fromAccount = $txn->fromAccount;
                        if ($fromAccount) {
                            // If account_code is 'ONLINE', it's Online, otherwise it's Cash
                            $paymentMode = ($fromAccount->account_code === 'ONLINE') ? 'Online' : 'Cash';
                        }
                    }

                    $dailySummary[$date]['transactions'][] = [
                        'transaction_id' => $txn->transaction_id,
                        'type' => $isPurchase ? 'purchase' : 'payment',
                        'amount' => $amount,
                        'adjustment_amount' => $txn->adjustment_amount ?? 0,
                        'description' => $txn->description,
                        'line_items' => $lineItems,
                        'payment_mode' => $paymentMode
                    ];

                    if ($isPurchase) {
                        $dailySummary[$date]['total_purchases'] += $amount;
                        $vendorTotalPurchases += $amount;
                    } else {
                        $dailySummary[$date]['total_payments'] += $amount;
                        $vendorTotalPayments += $amount;
                        
                        // Track payment mode totals
                        if ($paymentMode === 'Online') {
                            $dailySummary[$date]['total_payments_online'] += $amount;
                        } else {
                            $dailySummary[$date]['total_payments_cash'] += $amount;
                        }
                    }
                }

                $grandTotalPurchases += $vendorTotalPurchases;
                $grandTotalPayments += $vendorTotalPayments;

                $reportData[] = [
                    'vendor_name' => $vendor->vendor_name,
                    'contact_person' => $vendor->contact_person,
                    'contact_phone' => $vendor->contact_phone,
                    'current_balance' => $vendor->account->current_balance,
                    'total_purchases' => $vendorTotalPurchases,
                    'total_payments' => $vendorTotalPayments,
                    'daily_summary' => array_values($dailySummary)
                ];
            }

            return response()->json([
                'success' => true,
                'report' => [
                    'date_from' => \Carbon\Carbon::parse($dateFrom)->format('M j, Y'),
                    'date_to' => \Carbon\Carbon::parse($dateTo)->format('M j, Y'),
                    'vendors' => $reportData,
                    'grand_total' => [
                        'total_purchases' => $grandTotalPurchases,
                        'total_payments' => $grandTotalPayments
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Error generating vendor report: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error generating report: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete vendor transaction (purchase or payment)
     * Reverses all ledger entries and balances
     */
    public function deleteTransaction($transactionId)
    {
        $guard = LedgerModel::find($transactionId);
        if ($guard && !$this->canChangeVendorTransaction($guard)) {
            return $this->refuseVendorTransactionChange();
        }

        try {
            DB::beginTransaction();

            // Find the transaction
            $transaction = LedgerModel::findOrFail($transactionId);
            
            // Verify it's a vendor transaction
            if (!in_array($transaction->transaction_type, [
                LedgerModel::TYPE_VENDOR_PURCHASE,
                LedgerModel::TYPE_VENDOR_PAYMENT
            ])) {
                throw new \Exception("This is not a vendor transaction");
            }

            // Get the vendor account - for both purchases and payments, vendor is to_account_id
            $vendorAccount = AccountModel::find($transaction->to_account_id);
            if (!$vendorAccount) {
                Log::error("Vendor account not found. Transaction ID: {$transactionId}, to_account_id: {$transaction->to_account_id}");
                throw new \Exception("Vendor account not found (ID: {$transaction->to_account_id})");
            }
            
            // Verify it's actually a vendor account (allow both 'vendor' and 'vendor_payable')
            $validCategories = ['vendor', 'vendor_payable'];
            if ($vendorAccount->account_category && !in_array($vendorAccount->account_category, $validCategories)) {
                Log::error("Account is not a vendor account. Category: {$vendorAccount->account_category}");
                throw new \Exception("This is not a vendor account (Category: {$vendorAccount->account_category})");
            }

            $isPurchase = $transaction->transaction_type === LedgerModel::TYPE_VENDOR_PURCHASE;

            // Reverse via the canonical engine — exact inverse of the vendor posting (purchase → both
            // legs −, payment → both legs +). Self-guards on balance_updated and is idempotent.
            // REQUIRES the vendor flag backfill (LEDGER-L3-VENDOR-FLAG-BACKFILL.sql) to have run first:
            // legacy vendor rows were applied but carry balance_updated=0, so without the backfill the
            // engine would treat them as un-applied and SKIP the reversal. After the backfill every
            // applied vendor row is flag=1 and reverses correctly. The row is hard-deleted below.
            (new \App\Services\FIN\BalancePostingService())->reverse($transaction);

            // Delete associated line items if it's a weighted purchase
            if ($isPurchase) {
                \App\Models\FIN\VendorPurchaseItemModel::where('ledger_id', $transaction->id)->delete();
            }

            // Delete attached images — rows AND files (the old code left the file orphaned).
            if (\App\Models\FIN\LedgerImageModel::ready()) {
                foreach (\App\Models\FIN\LedgerImageModel::where('ledger_id', $transaction->id)->get() as $img) {
                    if ($img->image_path && \Storage::disk('public')->exists($img->image_path)) {
                        \Storage::disk('public')->delete($img->image_path);
                    }
                    $img->delete();
                }
            } elseif ($transaction->bill_image && \Storage::disk('public')->exists($transaction->bill_image)) {
                \Storage::disk('public')->delete($transaction->bill_image);
            }

            // Delete the transaction
            $transaction->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Transaction deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error deleting vendor transaction: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error deleting transaction: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update vendor transaction
     * Allows editing date, amount, description, and line items
     */
    public function updateTransaction(Request $request, $transactionId)
    {
        $request->validate([
            'transaction_date' => 'required|date',
            'posted_date' => 'nullable|date',
            'amount' => 'nullable|numeric|min:0.01',
            'adjustment_amount' => 'nullable|numeric', // Can be positive or negative
            'description' => 'nullable|string|max:500',
            // Which of OUR banks an ONLINE payment left from — a re-TAG only (see below).
            'receiving_account_id' => 'nullable|integer|exists:t_fin_online_receiving_accounts,id',
            'bill_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120', // legacy: replace first
            'new_images' => 'nullable|array|max:10',
            'new_images.*' => 'image|mimes:jpeg,png,jpg,gif|max:5120',
            'new_images_base64' => 'nullable|array|max:10',
            'new_images_base64.*' => 'string',
            'remove_image_ids' => 'nullable|array',
            'remove_image_ids.*' => 'integer',
            'items' => 'nullable|array',
            'items.*.product_id' => 'required_with:items|exists:t_fin_vendor_products,id',
            'items.*.quantity' => 'required_with:items|numeric|min:0.001',
            'items.*.rate' => 'required_with:items|numeric|min:0.01',
            'items.*.unit' => 'required_with:items|string|max:50',
            'items.*.product_name' => 'required_with:items|string|max:255'
        ]);

        $guard = LedgerModel::find($transactionId);
        if ($guard && !$this->canChangeVendorTransaction($guard)) {
            return $this->refuseVendorTransactionChange();
        }
        $lineProducts = collect();
        if ($guard && $request->has('items') && is_array($request->items)) {
            // Which vendor this bill belongs to: the vendor whose account it posts to.
            $guardVendor = VendorModel::where('account_id', $guard->to_account_id)->first();
            if ($guardVendor && ($problem = $this->purchaseLinesProblem($request->items, $guardVendor))) {
                return $problem;
            }
            $lineProducts = VendorProductModel::whereIn('id', collect($request->items)->pluck('product_id')->all())
                ->get()->keyBy('id');
        }

        try {
            DB::beginTransaction();

            $transaction = LedgerModel::findOrFail($transactionId);
            
            // Verify it's a vendor transaction
            if (!in_array($transaction->transaction_type, [
                LedgerModel::TYPE_VENDOR_PURCHASE,
                LedgerModel::TYPE_VENDOR_PAYMENT
            ])) {
                throw new \Exception("This is not a vendor transaction");
            }

            $isPurchase = $transaction->transaction_type === LedgerModel::TYPE_VENDOR_PURCHASE;
            $oldAmount = $transaction->amount;
            
            // Check if this is a weighted purchase
            $hasLineItems = \App\Models\FIN\VendorPurchaseItemModel::where('ledger_id', $transaction->id)->exists();

            // Handle weighted purchase updates
            if ($hasLineItems && $request->has('items')) {
                // Delete old line items
                \App\Models\FIN\VendorPurchaseItemModel::where('ledger_id', $transaction->id)->delete();
                
                // Calculate new total from items
                $itemsTotal = 0;
                $itemsSummary = [];
                foreach ($request->items as $item) {
                    $lineTotal = $item['quantity'] * $item['rate'];
                    $itemsTotal += $lineTotal;
                    $lineUnit = $this->lineUnit($item, $lineProducts);
                    $itemsSummary[] = "{$item['product_name']} ({$item['quantity']} {$lineUnit} @ Rs.{$item['rate']})";
                    
                    // Create new line item
                    // ❄ Same ingredient stamp as the create path. An edit re-resolves
                    // from the catalogue as it stands NOW, because the person editing
                    // is correcting what the line should have said all along.
                    $ingredient = \App\Models\FIN\VendorPurchaseItemModel::supportsIngredients()
                        ? \App\Models\FIN\VendorPurchaseItemModel::ingredientColumnsFor(
                            $item['product_id'] ?? null, (float) $item['quantity'], $item)
                        : [];

                    \App\Models\FIN\VendorPurchaseItemModel::create(array_merge([
                        'ledger_id' => $transaction->id,
                        'vendor_product_id' => $item['product_id'],
                        'product_name' => $item['product_name'],
                        'quantity' => $item['quantity'],
                        'unit' => $this->lineUnit($item, $lineProducts),
                        'rate_per_unit' => $item['rate'],
                        'line_total' => $lineTotal,
                    ], $ingredient));
                }

                // Apply adjustment amount (can be positive or negative)
                $adjustmentAmount = floatval($request->adjustment_amount ?? 0);
                $newAmount = $itemsTotal + $adjustmentAmount;

                $transaction->amount = $newAmount;
                $transaction->adjustment_amount = $adjustmentAmount;
                // ⭐ The one-line summary the web list shows is rebuilt from the NEW lines.
                //   It used to keep the original text: 42 of 110 edited purchases still
                //   read "Veal Boneless 15 kg @1670" over lines that now say otherwise.
                $transaction->comments = implode(', ', $itemsSummary);
            } elseif ($request->filled('amount')) {
                // Simple transaction - use provided amount.
                // filled(), not has(): ConvertEmptyStringsToNull turns a blank amount field into
                // NULL with the key still PRESENT, so has() was true and this assigned NULL to a
                // NOT NULL column — the save threw and the whole edit failed with a 500. An empty
                // amount now means "leave it alone", which is what every caller actually intends.
                $newAmount = $request->amount;
                $transaction->amount = $newAmount;
            } else {
                $newAmount = $oldAmount;
            }

            // Update basic fields
            $transaction->transaction_date = $request->transaction_date;
            if ($request->filled('posted_date')) {
                $transaction->posted_date = $request->posted_date;
            } elseif (!$transaction->posted_date) {
                // If no posted_date exists, default to transaction_date
                $transaction->posted_date = $request->transaction_date;
            }
            if ($request->has('description')) {
                $transaction->description = $request->description;
            }

            // ---- Bank re-tag (Aug-2026) -----------------------------------------------------
            // receiving_account_id says which of OUR banks an ONLINE payment left from. It is a
            // TAG: per-bank balances are DERIVED from it (BankBalanceService sums tagged
            // movement), and the ledger legs stay ONLINE→vendor either way — so switching it
            // moves the payment between banks without touching any stored balance. Three rules:
            //   * payments only, and only rows that are already online (mode, or an existing
            //     tag). A cash payment has no bank and a purchase never carries one — for those
            //     the field is silently ignored, mirroring how the record flow scopes it.
            //   * the tag can be SWITCHED, never cleared — recording requires it for online
            //     payments and a bare online payment would fall out of every bank's balance.
            //   * the description carries the bank baked in ("· via MBL-4237"): swap exactly
            //     the OLD bank's token. Never a blanket "via X" replace — descriptions also say
            //     "via NF Assistant".
            if (!$isPurchase && $request->filled('receiving_account_id')) {
                $newBankId = (int) $request->receiving_account_id;
                $oldBankId = (int) ($transaction->receiving_account_id ?? 0);
                $isOnlineRow = $transaction->mode === LedgerModel::MODE_ONLINE || $oldBankId;
                if ($isOnlineRow && $newBankId !== $oldBankId) {
                    $newBank = \App\Models\FIN\OnlineReceivingAccountModel::find($newBankId);
                    $oldShort = $oldBankId
                        ? \App\Models\FIN\OnlineReceivingAccountModel::find($oldBankId)?->short_code
                        : null;
                    $transaction->receiving_account_id = $newBankId;

                    $desc = (string) $transaction->description;
                    if ($newBank?->short_code) {
                        if ($oldShort && str_contains($desc, "· via {$oldShort}")) {
                            $desc = str_replace("· via {$oldShort}", "· via {$newBank->short_code}", $desc);
                        } elseif (!str_contains($desc, "· via {$newBank->short_code}")) {
                            $desc = trim($desc) !== '' ? ($desc . " · via {$newBank->short_code}") : "· via {$newBank->short_code}";
                        }
                        $transaction->description = $desc;
                    }
                }
            }

            // ---- Images -------------------------------------------------------------------
            // Three independent operations, all optional on any one edit:
            //   remove_image_ids[] — take specific images off this row (rows + files);
            //   new_images[] (+ new_images_base64[] from mobile) — append more;
            //   bill_image (legacy single) — REPLACE the first image, the pre-multi behaviour
            //                                the current APK and the old page edit still post.
            // Everything funnels through t_fin_ledger_images and ends with syncMirror(), so the
            // ledger's bill_image column always points at the first remaining image. When the
            // images table hasn't been created yet, only the legacy replace runs — byte-identical
            // to the old code.
            $imagesReady = \App\Models\FIN\LedgerImageModel::ready();

            if ($imagesReady && $request->filled('remove_image_ids')) {
                $ids = array_filter((array) $request->input('remove_image_ids'), 'is_numeric');
                if ($ids) {
                    // Scoped to THIS ledger row — a stray id can never touch another row's images.
                    $victims = \App\Models\FIN\LedgerImageModel::where('ledger_id', $transaction->id)
                        ->whereIn('id', $ids)->get();
                    foreach ($victims as $img) {
                        if ($img->image_path && \Storage::disk('public')->exists($img->image_path)) {
                            \Storage::disk('public')->delete($img->image_path);
                        }
                        $img->delete();
                    }
                }
            }

            if ($imagesReady) {
                $newPaths = [];
                if ($request->hasFile('new_images')) {
                    $files = $request->file('new_images');
                    foreach (is_array($files) ? $files : [$files] as $file) {
                        if (!$file || !$file->isValid()) {
                            continue;
                        }
                        $filename = 'vendor_bill_' . $transaction->id . '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                        $newPaths[] = $file->storeAs('vendor_bills', $filename, 'public');
                    }
                }
                $b64List = $request->input('new_images_base64');
                if (is_array($b64List)) {
                    foreach ($b64List as $b64) {
                        if (!is_string($b64) || $b64 === '') {
                            continue;
                        }
                        $image = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $b64));
                        if ($image === false || $image === '') {
                            continue;
                        }
                        $filename = 'vendor_bill_' . $transaction->id . '_' . time() . '_' . uniqid() . '.jpg';
                        \Storage::disk('public')->put('vendor_bills/' . $filename, $image);
                        $newPaths[] = 'vendor_bills/' . $filename;
                    }
                }
                if ($newPaths) {
                    $nextSort = (int) \App\Models\FIN\LedgerImageModel::where('ledger_id', $transaction->id)->max('sort_order') + 1;
                    foreach ($newPaths as $i => $path) {
                        \App\Models\FIN\LedgerImageModel::create([
                            'ledger_id' => $transaction->id,
                            'image_path' => $path,
                            'sort_order' => $nextSort + $i,
                            'created_by' => auth()->id(),
                        ]);
                    }
                }
            }

            // Legacy single-image replace (old APK / old page edit form).
            if ($request->hasFile('bill_image')) {
                $file = $request->file('bill_image');
                $filename = 'vendor_bill_' . $transaction->id . '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $billImagePath = $file->storeAs('vendor_bills', $filename, 'public');

                if ($imagesReady) {
                    // Replace the FIRST image in the table (that is what bill_image mirrored),
                    // leaving any further images untouched.
                    $first = \App\Models\FIN\LedgerImageModel::where('ledger_id', $transaction->id)
                        ->orderBy('sort_order')->orderBy('id')->first();
                    if ($first) {
                        if ($first->image_path && \Storage::disk('public')->exists($first->image_path)) {
                            \Storage::disk('public')->delete($first->image_path);
                        }
                        $first->image_path = $billImagePath;
                        $first->save();
                    } else {
                        \App\Models\FIN\LedgerImageModel::create([
                            'ledger_id' => $transaction->id,
                            'image_path' => $billImagePath,
                            'sort_order' => 0,
                            'created_by' => auth()->id(),
                        ]);
                    }
                } else {
                    // Pre-SQL behaviour, unchanged: delete old file, point the column at the new.
                    if ($transaction->bill_image && \Storage::disk('public')->exists($transaction->bill_image)) {
                        \Storage::disk('public')->delete($transaction->bill_image);
                    }
                    $transaction->bill_image = $billImagePath;
                }
            }

            if ($imagesReady) {
                // One authoritative pass: bill_image = first remaining image (or NULL).
                // Assigned on the model (not via the raw mirror update) because the
                // save() below would otherwise overwrite it with the stale in-memory value.
                $transaction->bill_image = \App\Models\FIN\LedgerImageModel::where('ledger_id', $transaction->id)
                    ->orderBy('sort_order')->orderBy('id')->value('image_path');
            }

            $transaction->save();

            // Update account balances if the amount changed — but ONLY when the row's money is
            // actually applied. balance_updated is the engine's truth of applied-ness: a pending
            // payment is flag=0 (its later approval applies the NEW amount, so no delta here); an
            // L1-approved payment (pending_l2) IS applied. The old approval_status===approved check
            // wrongly skipped pending_l2 — editing one changed the row but not the balances, the
            // exact silent drift the balance_updated hardening exists to prevent. Same sign
            // convention as BalancePostingService: purchase applies both legs +, payment both −.
            if ($oldAmount != $newAmount && $transaction->balance_updated) {
                $amountDiff = $newAmount - $oldAmount;

                // Get accounts
                $vendorAccount = AccountModel::find($transaction->to_account_id);

                if ($isPurchase) {
                    // Update vendor balance
                    if ($vendorAccount) {
                        $vendorAccount->current_balance += $amountDiff;
                        $vendorAccount->save();
                    }

                    // Update purchase account
                    $purchaseAccount = AccountModel::find($transaction->from_account_id);
                    if ($purchaseAccount) {
                        $purchaseAccount->current_balance += $amountDiff;
                        $purchaseAccount->save();
                    }
                } else {
                    if ($vendorAccount) {
                        $vendorAccount->current_balance -= $amountDiff;
                        $vendorAccount->save();
                    }

                    $paymentAccount = AccountModel::find($transaction->from_account_id);
                    if ($paymentAccount) {
                        $paymentAccount->current_balance -= $amountDiff;
                        $paymentAccount->save();
                    }
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Transaction updated successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error updating vendor transaction: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error updating transaction: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Toggle expand/collapse preference for vendor transactions
     */
    public function toggleExpandAll(Request $request)
    {
        $expandAll = $request->input('expand_all', false);
        session(['vendor_transactions_expand_all' => $expandAll]);
        
        return response()->json([
            'success' => true,
            'expand_all' => $expandAll
        ]);
    }

    /**
     * Helper method to handle image uploads from both web (multipart) and mobile (base64)
     */
    private function handleImageUpload(Request $request, $fieldName, $vendor)
    {
        // Check for traditional file upload (web)
        if ($request->hasFile($fieldName)) {
            $file = $request->file($fieldName);
            // uniqid, not bare time(): two images stored in the same second used to get the SAME
            // filename and storeAs silently overwrote the first with the second.
            $filename = 'vendor_' . $vendor->id . '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            return $file->storeAs('vendor_bills', $filename, 'public');
        }

        // Check for base64 upload (mobile)
        $base64Field = $fieldName . '_base64';
        if ($request->has($base64Field) && $request->input($base64Field)) {
            $base64Image = $request->input($base64Field);

            // Remove data:image prefix if present
            $image = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $base64Image));

            $filename = 'vendor_' . $vendor->id . '_' . time() . '_' . uniqid() . '.jpg';
            Storage::disk('public')->put('vendor_bills/' . $filename, $image);

            return 'vendor_bills/' . $filename;
        }

        return null;
    }

    /**
     * Collect EVERY image on a record request, in the order they should display:
     * the plural fields first (`{$fieldName}s[]` files, `{$fieldName}s_base64[]`),
     * then the legacy single fields via handleImageUpload() — so the current APK
     * and any old form keep working unchanged. Returns an array of stored paths.
     */
    /**
     * "You just recorded this payment — is this the bank SMS for it?"
     *
     * The money box holds bank debits waiting to be sorted; the person who just
     * typed this entry is the one who knows whether one of them is this very
     * transfer. Returns null unless there is something worth asking, and never
     * throws: the payment is already recorded and must not be endangered by a
     * question about it. Honours the user's own prompt/auto/off setting inside
     * MoneyOutTagService.
     */
    private function bankSmsPrompt(int $ledgerId): ?array
    {
        try {
            if (!auth()->check() || !auth()->user()->hasMobilePermission('use_ai_assistant')) {
                return null;
            }
            return app(\App\Services\Assistant\MoneyOutTagService::class)
                ->afterEntry($ledgerId, (int) auth()->id());
        } catch (\Throwable $e) {
            Log::warning('[bankSmsPrompt] ' . $e->getMessage(), ['ledger' => $ledgerId]);
            return null;
        }
    }

    private function collectImageUploads(Request $request, string $fieldName, $vendor): array
    {
        $paths = [];

        // Plural file uploads: bill_images[] / receipt_images[]
        $plural = $fieldName . 's';
        if ($request->hasFile($plural)) {
            $files = $request->file($plural);
            foreach (is_array($files) ? $files : [$files] as $file) {
                if (!$file || !$file->isValid()) {
                    continue;
                }
                $filename = 'vendor_' . $vendor->id . '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $paths[] = $file->storeAs('vendor_bills', $filename, 'public');
            }
        }

        // Plural base64 (mobile): bill_images_base64[]
        $b64List = $request->input($plural . '_base64');
        if (is_array($b64List)) {
            foreach ($b64List as $b64) {
                if (!is_string($b64) || $b64 === '') {
                    continue;
                }
                $image = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $b64));
                if ($image === false || $image === '') {
                    continue;
                }
                $filename = 'vendor_' . $vendor->id . '_' . time() . '_' . uniqid() . '.jpg';
                Storage::disk('public')->put('vendor_bills/' . $filename, $image);
                $paths[] = 'vendor_bills/' . $filename;
            }
        }

        // Legacy single field (current APK, old forms) — appended, not replaced.
        $single = $this->handleImageUpload($request, $fieldName, $vendor);
        if ($single) {
            $paths[] = $single;
        }

        return $paths;
    }

    /**
     * Persist a record-time image list for a new ledger row: rows in
     * t_fin_ledger_images (the truth) + the ledger's bill_image mirrored to the
     * first (the contract every legacy reader depends on). When the images table
     * has not been created yet, degrades to exactly the old single-image write.
     */
    private function attachImages(LedgerModel $ledger, array $paths): void
    {
        if (empty($paths)) {
            return;
        }

        if (!\App\Models\FIN\LedgerImageModel::ready()) {
            // SQL not applied yet — keep the first image so behaviour matches today.
            $ledger->bill_image = $paths[0];
            $ledger->save();
            return;
        }

        foreach (array_values($paths) as $i => $path) {
            \App\Models\FIN\LedgerImageModel::create([
                'ledger_id' => $ledger->id,
                'image_path' => $path,
                'sort_order' => $i,
                'created_by' => auth()->id(),
            ]);
        }
        \App\Models\FIN\LedgerImageModel::syncMirror($ledger->id);
    }

    /**
     * Shared validation rules for the image fields on record/update requests.
     * The plural base64 strings are intentionally NOT max-length-capped here:
     * a 5MB image is ~6.8M base64 characters and 'max:' on strings counts
     * characters, so a cap either blocks legit photos or means nothing.
     */
    private function imageValidationRules(string $fieldName): array
    {
        $plural = $fieldName . 's';
        return [
            $fieldName => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120',
            $plural => 'nullable|array|max:10',
            $plural . '.*' => 'image|mimes:jpeg,png,jpg,gif|max:5120',
            $plural . '_base64' => 'nullable|array|max:10',
            $plural . '_base64.*' => 'string',
        ];
    }
}

