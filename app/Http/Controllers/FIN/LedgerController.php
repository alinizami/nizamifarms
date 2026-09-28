<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\AccountModel;
use App\Models\FIN\InvoiceSettlementModel;
use App\Models\Request\RequestCategoryModel;
use App\Models\SysAdmin\RoleApprovalLevelModel;
use App\Services\FIN\BalancePostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LedgerController extends Controller
{
    /**
     * Display overall ledger (all transactions)
     */
    public function index(Request $request)
    {
        // Get date range for filters (default to current month)
        $startDate = $request->start_date ?? now()->startOfMonth()->format('Y-m-d');
        $endDate = $request->end_date ?? now()->endOfMonth()->format('Y-m-d');
        
        $query = LedgerModel::with(['fromAccount', 'toAccount', 'createdBy', 'order.customer']);

        // Exclude transactions involving private accounts for non-Taimur users
        $webUser = auth()->user();
        $isWebTaimur = $webUser && $webUser->roles()
            ->whereRaw('LOWER(urole_name) = ?', ['taimur'])
            ->exists();
        if (!$isWebTaimur) {
            $privateIds = AccountModel::where('is_private', 1)->pluck('id')->toArray();
            if (!empty($privateIds)) {
                $query->whereNotIn('from_account_id', $privateIds)
                      ->whereNotIn('to_account_id', $privateIds);
            }
        }

        // Filter by date range
        // For vendor payments, use posted_date; for others, use transaction_date
        if ($startDate) {
            $query->where(function($q) use ($startDate) {
                $q->where(function($subQ) use ($startDate) {
                    // Vendor payments use posted_date
                    $subQ->where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT)
                         ->where(function($dateQ) use ($startDate) {
                             $dateQ->where('posted_date', '>=', $startDate)
                                   ->orWhere(function($fallbackQ) use ($startDate) {
                                       $fallbackQ->whereNull('posted_date')
                                                 ->where('transaction_date', '>=', $startDate);
                                   });
                         });
                })->orWhere(function($subQ) use ($startDate) {
                    // All other transactions use transaction_date
                    $subQ->where('transaction_type', '!=', LedgerModel::TYPE_VENDOR_PAYMENT)
                         ->where('transaction_date', '>=', $startDate);
                });
            });
        }
        if ($endDate) {
            $query->where(function($q) use ($endDate) {
                $q->where(function($subQ) use ($endDate) {
                    // Vendor payments use posted_date
                    $subQ->where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT)
                         ->where(function($dateQ) use ($endDate) {
                             $dateQ->where('posted_date', '<=', $endDate)
                                   ->orWhere(function($fallbackQ) use ($endDate) {
                                       $fallbackQ->whereNull('posted_date')
                                                 ->where('transaction_date', '<=', $endDate);
                                   });
                         });
                })->orWhere(function($subQ) use ($endDate) {
                    // All other transactions use transaction_date
                    $subQ->where('transaction_type', '!=', LedgerModel::TYPE_VENDOR_PAYMENT)
                         ->where('transaction_date', '<=', $endDate);
                });
            });
        }

        // Filter by transaction type
        if ($request->has('type') && $request->type) {
            $query->where('transaction_type', $request->type);
        }
        
        // Special filter for vendor transactions (purchases + payments)
        if ($request->has('vendor_filter') && $request->vendor_filter) {
            $query->whereIn('transaction_type', [
                LedgerModel::TYPE_VENDOR_PURCHASE,
                LedgerModel::TYPE_VENDOR_PAYMENT
            ]);
        }

        // Filter by mode
        if ($request->has('mode') && $request->mode) {
            $query->where('mode', $request->mode);
        }

        // Filter by approval status
        if ($request->has('status') && $request->status) {
            $query->where('approval_status', $request->status);
        }

        // Filter by account (from or to)
        if ($request->has('account_id') && $request->account_id) {
            $accountId = $request->account_id;
            $query->where(function($q) use ($accountId) {
                $q->where('from_account_id', $accountId)
                  ->orWhere('to_account_id', $accountId);
            });
        }

        // Search by description
        if ($request->has('search') && $request->search) {
            $query->where('description', 'LIKE', "%{$request->search}%");
        }

        // Order by date (newest first)
        $ledger = $query->orderBy('transaction_date', 'desc')
                       ->orderBy('created_at', 'desc')
                       ->paginate(50);

        // Get all accounts for filters — hide private accounts for non-Taimur
        $accounts = AccountModel::where('is_active', 1)
                                ->visibleTo(auth()->user())
                                ->orderBy('account_name', 'asc')
                                ->get();

        // Get transaction types (all types from LedgerModel)
        $transactionTypes = [
            LedgerModel::TYPE_INVOICE => 'Invoice',
            LedgerModel::TYPE_EMPLOYEE_DEPOSIT => 'Employee Deposit',
            LedgerModel::TYPE_EXPENSE => 'Expense',
            LedgerModel::TYPE_VENDOR_PURCHASE => 'Vendor Purchase',
            LedgerModel::TYPE_VENDOR_PAYMENT => 'Vendor Payment',
            LedgerModel::TYPE_SUPPLY_PURCHASE => 'Storage stock purchase',
            LedgerModel::TYPE_SALARY_ADVANCE => 'Salary Advance',
            LedgerModel::TYPE_SALARY_PAYMENT => 'Salary Payment',
            LedgerModel::TYPE_REIMBURSEMENT_ACCRUAL => 'Reimbursement Accrual',
            LedgerModel::TYPE_REIMBURSEMENT_PAYMENT => 'Reimbursement Payment',
            LedgerModel::TYPE_SETTLEMENT => 'Expense Settlement',
            LedgerModel::TYPE_TRANSFER => 'Account Transfer',
            LedgerModel::TYPE_ADJUSTMENT => 'Adjustment',
            LedgerModel::TYPE_OPENING_BALANCE => 'Opening Balance',
        ];

        // Calculate pending summary (for ALL pending, not filtered)
        $pendingSummary = [
            'total_count' => LedgerModel::where('approval_status', LedgerModel::STATUS_PENDING)->count(),
            'total_amount' => LedgerModel::where('approval_status', LedgerModel::STATUS_PENDING)->sum('amount'),
            'by_type' => LedgerModel::where('approval_status', LedgerModel::STATUS_PENDING)
                                    ->select('transaction_type', 
                                             DB::raw('COUNT(*) as count'),
                                             DB::raw('SUM(amount) as amount'))
                                    ->groupBy('transaction_type')
                                    ->get()
                                    ->keyBy('transaction_type')
        ];

        // Calculate KPI summary (respecting date filters)
        $summaryKPIs = $this->calculateKPIs($startDate, $endDate);

        return view('fin.ledger.index', compact('ledger', 'accounts', 'transactionTypes', 'pendingSummary', 'summaryKPIs', 'startDate', 'endDate'));
    }
    
    /**
     * Calculate KPI summary data (reuses logic from EmployeeCashController for consistency)
     */
    private function calculateKPIs($startDate, $endDate)
    {
        // Phase 6 — Qurbani orders are stripped from the operational
        // Ledger KPIs because the Qurbani delivery flow deliberately
        // skips posting an INVOICE ledger row (see OrderModel:1465 —
        // `hasPreReceivedPayments()` guard). Qurbani payments are
        // collected separately through the rider/manager add-payment
        // flow, which posts their own ledger rows against the
        // dedicated **Qurbani Cash / Qurbani Online** accounts
        // (not NF Cash/Online).
        //
        // If we kept Qurbani delivered orders in this set,
        // $invoicesCash (from `t_crm_prod_order`) would include
        // Qurbani total but $cashInvoiceLedgers (from `t_fin_ledger`)
        // would not — producing phantom "Cash Pending With Rider".
        // The Qurbani-segregated view lives on the dedicated Qurbani
        // Expenses screen (web tab + mobile screen) and on the
        // Monthly Reports tab.
        $invoicesQuery = \DB::table('t_crm_order_status_history as h')
            ->leftJoin('t_crm_prod_order as o', 'o.id', '=', 'h.order_id')
            ->where('h.status_code', 'delivered')
            ->where('h.is_current', 1)
            ->tap(function ($q) {
                \App\Services\QurbaniFinanceFilter::applyToOrderQuery(
                    $q, 'o', \App\Services\QurbaniFinanceFilter::MODE_EXCLUDE
                );
            });

        if ($startDate && $endDate) {
            $invoicesQuery->whereBetween('h.changed_at', [$startDate, $endDate]);
        }

        $deliveredOrderIds = $invoicesQuery->pluck('h.order_id');
        
        // === KPI 1: INVOICES ===
        $totalInvoices = \DB::table('t_crm_prod_order')
            ->whereIn('id', $deliveredOrderIds)
            ->sum('total_price') ?? 0;
        
        $invoicesCash = \DB::table('t_crm_prod_order')
            ->whereIn('id', $deliveredOrderIds)
            ->whereIn('payment_method', ['cash', 'cash_on_delivery', 'Cash', 'COD'])
            ->sum('total_price') ?? 0;
        
        $nfCashAccount = AccountModel::where('account_code', 'NF_CASH')->first();
        $cashDeposits = 0;
        $shortCashTotal = 0;
        
        if ($nfCashAccount) {
            $cashDeposits = LedgerModel::where('to_account_id', $nfCashAccount->id)
                ->where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
                ->where('approval_status', LedgerModel::STATUS_APPROVED)
                ->whereBetween('transaction_date', [$startDate, $endDate])
                ->sum('amount') ?? 0;
            
            $shortCashTotal = \App\Models\Request\RequestModel::where('status', 'approved')
                ->whereHas('category', function($q) {
                    $q->where('category_code', 'expense');
                })
                ->whereHas('paymentSourceAccount', function($q) {
                    $q->where('account_category', 'employee_cash');
                })
                ->whereBetween('created_at', [$startDate, $endDate])
                ->sum('amount') ?? 0;
        }
        
        $invoicesOnline = \DB::table('t_crm_prod_order')
            ->whereIn('id', $deliveredOrderIds)
            ->whereIn('payment_method', ['online', 'Online', 'bank_transfer', 'card', 'online_payment'])
            ->sum('total_price') ?? 0;
        
        // === ONLINE INVOICES: Enhanced with L1/L2 split (matching EmployeeCashController) ===
        // Get ALL online invoice ledgers
        // Check ONLINE account (to_account) instead of order payment method for accuracy
        $onlineAccount = AccountModel::where('account_code', 'ONLINE')->first();
        $onlineInvoiceLedgersQuery = LedgerModel::where('transaction_type', LedgerModel::TYPE_INVOICE)
            ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
            ->whereBetween('transaction_date', [$startDate, $endDate]);
        
        if ($onlineAccount) {
            $onlineInvoiceLedgersQuery->where('to_account_id', $onlineAccount->id);
        }
        
        $onlineInvoiceLedgers = $onlineInvoiceLedgersQuery->get();
        
        $onlineApproved = $onlineInvoiceLedgers
            ->where('approval_status', LedgerModel::STATUS_APPROVED)
            ->sum('amount');
        
        // Split pending by approval level (L1 vs L2)
        // Legacy 'pending' status is treated as pending_l1
        $onlinePendingL1 = $onlineInvoiceLedgers
            ->filter(function ($invoice) {
                return in_array($invoice->approval_status, [
                    LedgerModel::STATUS_PENDING,      // Legacy: treat as L1
                    LedgerModel::STATUS_PENDING_L1,
                ], true);
            })
            ->sum('amount');
        
        $onlinePendingL2 = $onlineInvoiceLedgers
            ->where('approval_status', LedgerModel::STATUS_PENDING_L2)
            ->sum('amount');
        
        // Total pending (for backward compatibility)
        $onlinePending = $onlinePendingL1 + $onlinePendingL2;
        
        // === KPI 2: EXPENSES ===
        // Phase 6 — Qurbani-tagged ledger rows excluded so the
        // operational Expenses figure aligns with the Qurbani
        // segregation already applied to Invoices above.
        $ledgerExpenses = LedgerModel::where('transaction_type', LedgerModel::TYPE_EXPENSE)
            ->where('approval_status', LedgerModel::STATUS_APPROVED)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->tap(function ($q) {
                \App\Services\QurbaniFinanceFilter::applyToLedgerQuery(
                    $q, 't_fin_ledger', \App\Services\QurbaniFinanceFilter::MODE_EXCLUDE
                );
            })
            ->sum('amount') ?? 0;
        
        $salaryExpenses = \App\Models\HR\SalarySlipModel::whereIn('slip_status', ['approved', 'paid'])
            ->whereNotNull('ledger_transaction_id')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('net_salary') ?? 0;
        
        $totalExpenses = $ledgerExpenses + $salaryExpenses;
        $regularExpenses = $ledgerExpenses;
        $salaryExpensesForDisplay = $salaryExpenses;
        
        $expensesNeedingSettlement = \App\Models\Request\RequestModel::where('status', 'approved')
            ->where('settlement_status', 'pending')
            ->whereHas('category', function($q) {
                $q->where('category_code', 'expense');
            })
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('amount') ?? 0;
        
        // === KPI 3: VENDOR BALANCE ===
        // Phase 6 — Qurbani vendor activity lives on the Qurbani
        // Expenses screen, not in the operational ledger view.
        $vendorPurchases = LedgerModel::where('transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE)
            ->where('approval_status', LedgerModel::STATUS_APPROVED)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->tap(function ($q) {
                \App\Services\QurbaniFinanceFilter::applyToLedgerQuery(
                    $q, 't_fin_ledger', \App\Services\QurbaniFinanceFilter::MODE_EXCLUDE
                );
            })
            ->sum('amount') ?? 0;

        $vendorPayments = LedgerModel::where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT)
            ->where('approval_status', LedgerModel::STATUS_APPROVED)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->tap(function ($q) {
                \App\Services\QurbaniFinanceFilter::applyToLedgerQuery(
                    $q, 't_fin_ledger', \App\Services\QurbaniFinanceFilter::MODE_EXCLUDE
                );
            })
            ->sum('amount') ?? 0;
        
        $vendorBalance = $vendorPurchases - $vendorPayments;
        
        // === KPI 4: RIDERS BALANCE ===
        $ridersBalance = AccountModel::where('account_category', AccountModel::CATEGORY_EMPLOYEE_CASH)
            ->where('is_active', 1)
            ->sum('current_balance') ?? 0;
        
        $pendingDeposits = LedgerModel::where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
            ->where('approval_status', LedgerModel::STATUS_PENDING)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->sum('amount') ?? 0;
        
        $pendingExpenses = \App\Models\Request\RequestModel::where('status', 'pending')
            ->whereHas('category', function($q) {
                $q->where('category_code', 'expense');
            })
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('amount') ?? 0;
        
        // === KPI 5: NF BALANCE (PROFIT) ===
        $profit = $totalInvoices - $totalExpenses - $vendorPurchases;
        
        return [
            'total_invoices' => $totalInvoices,
            'invoices_cash' => $invoicesCash,
            'cash_deposits' => $cashDeposits,
            'short_cash_total' => $shortCashTotal,
            'invoices_online' => $invoicesOnline,
            'online_approved' => $onlineApproved,
            'online_pending' => $onlinePending,
            'online_pending_l1' => $onlinePendingL1, // NEW: Pending L1 split
            'online_pending_l2' => $onlinePendingL2, // NEW: Pending L2 split
            'total_expenses' => $totalExpenses,
            'regular_expenses' => $regularExpenses,
            'salary_expenses' => $salaryExpensesForDisplay,
            'expenses_needing_settlement' => $expensesNeedingSettlement,
            'vendor_balance' => $vendorBalance,
            'vendor_purchases' => $vendorPurchases,
            'vendor_payments' => $vendorPayments,
            'riders_balance' => $ridersBalance,
            'pending_deposits' => $pendingDeposits,
            'pending_expenses' => $pendingExpenses,
            'profit' => $profit,
            'profit_invoices' => $totalInvoices,
            'profit_expenses' => $totalExpenses,
            'profit_vendor_purchases' => $vendorPurchases,
        ];
    }

    /**
     * Show transfer form
     */
    public function createTransfer()
    {
        // Get all active accounts — hide private for non-Taimur
        $accounts = AccountModel::where('is_active', 1)
                                ->visibleTo(auth()->user())
                                ->orderBy('account_name', 'asc')
                                ->get()
                                ->groupBy('account_type');

        // Receiving banks (with computed balances) — mandatory picker when the
        // transfer touches an ONLINE bank-category account.
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

        return view('fin.ledger.transfer', compact('accounts', 'receivingBanks'));
    }

    /**
     * Process account transfer
     */
    public function storeTransfer(Request $request)
    {
        $request->validate([
            'from_account_id' => 'required|exists:t_fin_accounts,id',
            'to_account_id' => 'required|exists:t_fin_accounts,id|different:from_account_id',
            'amount' => 'required|numeric|min:0.01',
            'transaction_date' => 'required|date',
            'description' => 'required|string|max:500',
            'mode' => 'required|in:cash,online',
            // Which of OUR banks the transfer touches — required below when
            // either side is an ONLINE bank-category account.
            'receiving_account_id' => 'nullable|integer|exists:t_fin_online_receiving_accounts,id',
        ]);

        try {
            DB::beginTransaction();

            $fromAccount = AccountModel::findOrFail($request->from_account_id);
            $toAccount = AccountModel::findOrFail($request->to_account_id);

            // A transfer touching an ONLINE bank account (deposit into a bank,
            // withdrawal out of one) must say WHICH bank, so per-bank balances
            // reconcile. Transfers between two bank accounts net to zero for
            // the tag, but we still capture it for the statement view.
            $touchesBank = $fromAccount->account_category === AccountModel::CATEGORY_BANK
                || $toAccount->account_category === AccountModel::CATEGORY_BANK;
            $transferBankId = $touchesBank ? $request->receiving_account_id : null;
            if ($touchesBank && !$transferBankId) {
                DB::rollBack();
                return back()->withInput()
                    ->with('error', 'Select which bank this transfer goes through.');
            }

            // Determine approval status.
            // Online transfers require approval — UNLESS the person entering it already holds
            // every approval level `account_transfer` requires, in which case queueing it just
            // asks them to approve their own entry. (Aug-2026 owner ruling; the internal-request
            // flow has behaved this way since forever — see SelfApprovalPolicy for the full note.
            // Cash transfers were always auto-approved and are untouched by this.)
            $selfApproved = $request->mode === 'online'
                && app(\App\Services\FIN\SelfApprovalPolicy::class)
                    ->canSelfApprove(LedgerModel::TYPE_TRANSFER, auth()->id());

            $approvalStatus = ($request->mode === 'online' && !$selfApproved)
                ? LedgerModel::STATUS_PENDING
                : LedgerModel::STATUS_APPROVED;

            // Name the bank in the description for the ledger listing.
            $transferDescription = $request->description;
            if ($transferBankId) {
                $transferBankShort = \App\Models\FIN\OnlineReceivingAccountModel::find($transferBankId)?->short_code;
                if ($transferBankShort) {
                    $transferDescription .= " · via {$transferBankShort}";
                }
            }

            // Create ledger entry.
            // A self-approved row is STAMPED (approved_by / approval_date / comments) so it reads
            // as a deliberate decision in the audit trail and shows up in the Approvals "Approved"
            // tab, rather than looking like a row that quietly never needed approving.
            $ledger = LedgerModel::create([
                'transaction_date' => $request->transaction_date,
                'transaction_type' => LedgerModel::TYPE_TRANSFER,
                'description' => $transferDescription,
                'from_account_id' => $fromAccount->id,
                'to_account_id' => $toAccount->id,
                'amount' => $request->amount,
                'mode' => $request->mode,
                'receiving_account_id' => $transferBankId,
                'approval_status' => $approvalStatus,
                'approval_date' => $selfApproved ? now()->toDateString() : null,
                'approved_by' => $selfApproved ? auth()->id() : null,
                'comments' => $selfApproved
                    ? app(\App\Services\FIN\SelfApprovalPolicy::class)
                        ->auditNote(LedgerModel::TYPE_TRANSFER, auth()->id())
                    : null,
                'created_by' => auth()->id()
            ]);

            // Update balances (only if approved or cash).
            // Ledger L3: routed through the canonical gate instead of the old inline
            // "asset-aware" arithmetic. apply() moves BOTH legs by the one convention, locks
            // each account row FOR UPDATE (no lost read-modify-write), and — critically —
            // sets balance_updated so a later reject/edit/delete knows the money was applied
            // and can reverse it. The inline version left the flag at 0, which made
            // reverse() a silent no-op and hid the row from the Finance Hub.
            // Verified equivalent to the previous arithmetic for every account pair this
            // path produces (asset↔asset), so no number changes.
            if ($approvalStatus === LedgerModel::STATUS_APPROVED) {
                (new \App\Services\FIN\BalancePostingService())->apply($ledger);
            }

            DB::commit();

            $message = $approvalStatus === LedgerModel::STATUS_PENDING
                ? 'Transfer created and pending approval!'
                : ($selfApproved
                    ? 'Transfer completed and posted — no approval needed, you hold the rights for it.'
                    : 'Transfer completed successfully!');

            return redirect()->route('fin.ledger.index')
                           ->with('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error processing transfer: " . $e->getMessage());
            
            return back()->withInput()
                       ->with('error', 'Error processing transfer: ' . $e->getMessage());
        }
    }

    /**
     * Show transaction details
     */
    public function show($id)
    {
        // Store the previous URL (with filters) in session for "Back to Approvals" button
        if (url()->previous() !== url()->current()) {
            $previousUrl = url()->previous();
            // Only store if it's from the approvals index page
            if (strpos($previousUrl, route('approvals.index')) !== false || strpos($previousUrl, '/approvals') !== false) {
                session(['approvals_return_url' => $previousUrl]);
            }
        }
        
        $transaction = LedgerModel::with(['fromAccount', 'toAccount', 'createdBy', 'approvedBy', 'order.customer', 'request'])
                                  ->findOrFail($id);

        // ⭐ Fetch related approvals for the same order (if this is an invoice transaction)
        $relatedApprovals = $this->getRelatedApprovalsForOrder($transaction);

        return view('fin.ledger.show', compact('transaction', 'relatedApprovals'));
    }
    
    /**
     * Get related pending/approved approvals for the same order
     * This helps approvers see all related items for the same order at a glance
     */
    private function getRelatedApprovalsForOrder(LedgerModel $transaction): array
    {
        $relatedApprovals = [];
        
        // Only applicable for invoice transactions with an order_id
        if (!$transaction->order_id || $transaction->transaction_type !== LedgerModel::TYPE_INVOICE) {
            return $relatedApprovals;
        }
        
        $orderId = $transaction->order_id;
        $currentLedgerId = $transaction->id;
        
        // 1. Find other ledger transactions for the same order (excluding current)
        $relatedLedgers = LedgerModel::where('order_id', $orderId)
            ->where('id', '!=', $currentLedgerId)
            ->whereIn('approval_status', [
                LedgerModel::STATUS_PENDING,
                LedgerModel::STATUS_PENDING_L1,
                LedgerModel::STATUS_PENDING_L2,
                LedgerModel::STATUS_APPROVED
            ])
            ->with(['fromAccount', 'toAccount'])
            ->orderBy('created_at', 'desc')
            ->get();
        
        foreach ($relatedLedgers as $ledger) {
            $relatedApprovals[] = [
                'type' => 'ledger',
                'id' => $ledger->id,
                'title' => 'Invoice Transaction #' . $ledger->id,
                'description' => $ledger->description,
                'amount' => $ledger->amount,
                'status' => $ledger->approval_status,
                'status_label' => $this->formatApprovalStatus($ledger->approval_status),
                'date' => $ledger->created_at ? $ledger->created_at->format('M j, Y') : '-',
                'is_pending' => $ledger->isPending(),
                'view_url' => route('fin.ledger.show', ['id' => $ledger->id, 'origin' => 'approvals'])
            ];
        }
        
        // 2. Find ledger adjustments for the same order
        $relatedAdjustments = \App\Models\FIN\LedgerAdjustmentModel::where('order_id', $orderId)
            ->whereIn('adjustment_status', [
                \App\Models\FIN\LedgerAdjustmentModel::STATUS_PENDING,
                \App\Models\FIN\LedgerAdjustmentModel::STATUS_APPROVED
            ])
            ->with(['ledger', 'requestedBy'])
            ->orderBy('created_at', 'desc')
            ->get();
        
        foreach ($relatedAdjustments as $adj) {
            $isPending = $adj->adjustment_status === \App\Models\FIN\LedgerAdjustmentModel::STATUS_PENDING;
            $statusLabel = $isPending ? 'Pending' : 'Approved';
            
            // Check approval levels for pending adjustments
            if ($isPending) {
                if ($adj->level_1_status === 'pending') {
                    $statusLabel = 'Pending L1';
                } elseif ($adj->level_1_status === 'approved' && $adj->level_2_status === 'pending') {
                    $statusLabel = 'Pending L2';
                }
            }
            
            $relatedApprovals[] = [
                'type' => 'adjustment',
                'id' => $adj->id,
                'title' => 'Ledger Adjustment #' . $adj->id,
                'description' => $adj->adjustment_reason ?? 'Amount adjustment: Rs. ' . number_format(abs($adj->adjustment_amount), 2),
                'amount' => abs($adj->adjustment_amount),
                'status' => $adj->adjustment_status,
                'status_label' => $statusLabel,
                'date' => $adj->requested_at ? $adj->requested_at->format('M j, Y') : '-',
                'is_pending' => $isPending,
                'view_url' => route('fin.ledger.adjustments.show', $adj->id)
            ];
        }
        
        return $relatedApprovals;
    }
    
    /**
     * Format approval status for display
     */
    private function formatApprovalStatus(string $status): string
    {
        return match($status) {
            LedgerModel::STATUS_PENDING, LedgerModel::STATUS_PENDING_L1 => 'Pending L1',
            LedgerModel::STATUS_PENDING_L2 => 'Pending L2',
            LedgerModel::STATUS_APPROVED => 'Approved',
            LedgerModel::STATUS_REJECTED => 'Rejected',
            LedgerModel::STATUS_REVERSED => 'Reversed',
            default => ucfirst($status)
        };
    }

    /**
     * Approve pending transaction
     */
    /**
     * Resolve which receiving bank an online approval should be tagged with.
     * Priority: (1) the bank the approver explicitly picked, (2) a bank already
     * on the ledger row (captured at L1, now approving L2), (3) the bank the
     * customer's payment proof was detected in. Returns null when none applies —
     * the caller then requires a manual pick for online movements.
     */
    private function resolveReceivingBankId(Request $request, LedgerModel $ledger): ?int
    {
        if ($request->receiving_account_id) {
            return (int) $request->receiving_account_id;
        }
        if ($ledger->receiving_account_id) {
            return (int) $ledger->receiving_account_id;
        }
        if ($ledger->order_id && config('payment_signals.enabled')) {
            try {
                $proof = app(\App\Services\Payments\Signals\PaymentProofStatusService::class)
                    ->forOrder((int) $ledger->order_id);
                if (!empty($proof['suggested_receiving_account_id'])) {
                    return (int) $proof['suggested_receiving_account_id'];
                }
            } catch (\Throwable $e) {
                // Detection is best-effort; fall through to a manual requirement.
            }
        }
        return null;
    }

    /**
     * Append "· via {SHORT}" to the ledger description once the receiving bank
     * is known, so the main ledger listing shows the bank at a glance. Guarded:
     * never appends twice (L1 then L2 approvals both pass through here).
     */
    private function appendBankToDescription(LedgerModel $ledger): void
    {
        if (!$ledger->receiving_account_id) {
            return;
        }
        if ($ledger->description && str_contains($ledger->description, '· via ')) {
            return; // already named (stamped at creation or at L1)
        }
        $short = \App\Models\FIN\OnlineReceivingAccountModel::find($ledger->receiving_account_id)?->short_code;
        if ($short) {
            $ledger->description = trim((string) $ledger->description) . " · via {$short}";
        }
    }

    /**
     * [Ledger approval gate — C1 fix, Jul-2026] Enforce approval rights on the SERVER.
     *
     * approve()/approveAtL1Only()/reject() previously ran under plain auth:sanctum with NO
     * rights check, so any authenticated token (every rider carries one) could approve/reject
     * and post balances — only the mobile/web UI hid the buttons. Rule (verified against live
     * approver data before rollout):
     *   • Need at least Level 1 to approve/reject at all.
     *   • Acting on an item already at L2 (pending_l2) needs Level 2, because that step confirms
     *     or reverses an already-posted balance (owner ruling R1).
     * Single-level items (e.g. order_payment — no approval category, so requiresL2=false) finalize
     * at L1, so a Level-1 approver is unaffected; an L1+L2 approver can do everything.
     *
     * Returns a JsonResponse/redirect to abort with, or null to proceed. Callers are inside an
     * open DB transaction, so they MUST DB::rollBack() before returning the response.
     */
    private function guardApprovalRights(Request $request, int $currentLevel)
    {
        $hasL2 = RoleApprovalLevelModel::userHasApprovalLevel(auth()->id(), 2);
        $authorized = $currentLevel >= 2
            ? $hasL2
            : ($hasL2 || RoleApprovalLevelModel::userHasApprovalLevel(auth()->id(), 1));

        if ($authorized) {
            return null;
        }

        $msg = $currentLevel >= 2
            ? 'This transaction is pending Level 2 verification, which you are not authorized to finalize.'
            : 'You do not have approval rights for this action.';

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['success' => false, 'message' => $msg], 403);
        }
        return back()->with('error', $msg);
    }

    public function approve(Request $request, $id)
    {
        $request->validate([
            'approval_notes' => 'nullable|string|max:500',
            'override_destination_account_id' => 'nullable|exists:t_fin_accounts,id',
            'override_source_account_id' => 'nullable|exists:t_fin_accounts,id',
            'force_full_approval' => 'nullable|boolean', // ⭐ Allow bypassing L2 if user has L2 rights
            'proof_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120', // ⭐ Optional proof of payment image (max 5MB)
            'receiving_account_id' => 'nullable|exists:t_fin_online_receiving_accounts,id', // ⭐ Which bank received this payment
            // Optional customer-side transaction ID (e.g. from their banking
            // app). Not part of finance logic — purely for audit / recall.
            'transaction_reference' => 'nullable|string|max:255',
        ]);

        try {
            DB::beginTransaction();

            $ledger = LedgerModel::with(['fromAccount', 'toAccount'])->findOrFail($id);

            // [Ledger approval gate — C1 fix] Enforce approval rights before any side effects
            // (image upload / balance posting). See guardApprovalRights() docblock.
            $gateLevel = $ledger->approval_status === LedgerModel::STATUS_PENDING_L2 ? 2 : 1;
            if ($gateResp = $this->guardApprovalRights($request, $gateLevel)) {
                DB::rollBack();
                return $gateResp;
            }

            // ⭐ Handle optional proof of payment image upload
            $proofImagePath = $this->handleApprovalImageUpload($request, $ledger);

            if (!$ledger->isPending()) {
                throw new \Exception("Transaction is not pending approval");
            }

            // Handle account overrides if provided
            $originalFrom = $ledger->from_account_id;
            $originalTo = $ledger->to_account_id;
            
            if ($request->override_source_account_id) {
                $ledger->from_account_id = $request->override_source_account_id;
                $ledger->comments = ($ledger->comments ?? '') . " | Source changed from Account ID {$originalFrom} to {$request->override_source_account_id}";
            }
            
            if ($request->override_destination_account_id) {
                $ledger->to_account_id = $request->override_destination_account_id;
                $ledger->comments = ($ledger->comments ?? '') . " | Destination changed from Account ID {$originalTo} to {$request->override_destination_account_id}";
            }

            // ⭐ [Ledger L2] If this approval OVERRIDES the destination/source TO a bank-category
            // account, the physical bank must be named — otherwise the override could mint an
            // untagged online row. Fires ONLY on an explicit override to a bank; the effective bank
            // is applied to the row further below (resolveReceivingBankId). Normal approvals (no
            // override) are unaffected, so the 22 existing pending ONLINE deposits are NOT blocked.
            $overrodeToBank = $request->override_destination_account_id
                && optional(AccountModel::find($request->override_destination_account_id))->account_category === AccountModel::CATEGORY_BANK;
            $overrodeFromBank = $request->override_source_account_id
                && optional(AccountModel::find($request->override_source_account_id))->account_category === AccountModel::CATEGORY_BANK;
            if (($overrodeToBank || $overrodeFromBank) && !$request->receiving_account_id && !$ledger->receiving_account_id) {
                DB::rollBack();
                $msg = 'This override moves money through a bank — select which bank before approving.';
                if ($request->ajax() || $request->wantsJson()) {
                    return response()->json(['success' => false, 'message' => $msg], 422);
                }
                return back()->with('error', $msg);
            }

            // ================== APPROVAL LEVEL LOGIC ==================
            // Determine current level from status
            $currentLevel = 1;
            if ($ledger->approval_status === LedgerModel::STATUS_PENDING_L2) {
                $currentLevel = 2;
            }

            // Determine required levels from Request Settings category config
            $categoryCode = null;
            switch ($ledger->transaction_type) {
                case LedgerModel::TYPE_INVOICE:
                    $categoryCode = 'invoice_approval';
                    break;
                case LedgerModel::TYPE_EMPLOYEE_DEPOSIT:
                    $categoryCode = 'employee_deposit';
                    break;
                case LedgerModel::TYPE_VENDOR_PAYMENT:
                    $categoryCode = 'vendor_payment';
                    break;
                case LedgerModel::TYPE_TRANSFER:
                    $categoryCode = 'account_transfer';
                    break;
            }

            $requiresL1 = true;
            $requiresL2 = false;

            if ($categoryCode) {
                $category = RequestCategoryModel::getByCode($categoryCode);
                if ($category) {
                    $requiresL1 = $category->requiresLevel1();
                    $requiresL2 = $category->requiresLevel2();
                }
            }

            // Decide new status. Balances are applied below by BalancePostingService::apply()
            // which is idempotent on balance_updated — so L1 applies once, L2 is a no-op, and the
            // "posted at L1, still shows in L2 so Taimur can roll back" behaviour is preserved
            // (reject()/revertToPending() call reverse() to undo an L1 posting). The engine owns
            // balance_updated; the status branches no longer set it manually.
            $finalApproval = false;
            $forceFullApproval = $request->boolean('force_full_approval', false);

            // ⭐ If force_full_approval is requested, check if user has L2 rights
            $userHasL2Rights = RoleApprovalLevelModel::userHasApprovalLevel(auth()->id(), 2);

            if ($currentLevel === 1 && $requiresL2 && !($forceFullApproval && $userHasL2Rights)) {
                // L1 → L2 pending: balance is applied now, L2 is verification only
                $ledger->approval_status = LedgerModel::STATUS_PENDING_L2;
                $ledger->comments = ($ledger->comments ?? '') .
                    " | L1 approved by User ID " . auth()->id();
            } elseif ($currentLevel === 2) {
                // L2 approval: verification step — mark approved (balance already applied at L1)
                $ledger->approval_status = LedgerModel::STATUS_APPROVED;
                $ledger->approved_by = auth()->id();
                $ledger->approval_date = now();
                $finalApproval = true;
            } else {
                // Final approval: single-level, or force_full with L2 rights
                $ledger->approval_status = LedgerModel::STATUS_APPROVED;
                $ledger->approved_by = auth()->id();
                $ledger->approval_date = now();
                $finalApproval = true;

                if ($forceFullApproval && $currentLevel === 1 && $requiresL2 && $userHasL2Rights) {
                    $ledger->comments = ($ledger->comments ?? '') .
                        " | Fully approved (L1+L2) by User ID " . auth()->id() . " with L2 rights";
                }
            }

            // Add approval notes to comments field
            if ($request->approval_notes) {
                $ledger->comments = ($ledger->comments ? $ledger->comments . "\n\n" : '') . 
                                   "Approval Notes: " . $request->approval_notes;
            }

            // ⭐ Save proof of payment image if uploaded
            if ($proofImagePath) {
                $ledger->bill_image = $proofImagePath;
            }

            // ⭐ Receiving bank: explicit choice wins; otherwise auto-apply the
            // bank detected from the customer's payment proof. Mandatory ONLY
            // for the Online-Approvals queue types (invoice / order_payment) —
            // vendor payments, deposits, transfers and asset purchases can also
            // be mode=online but their approval UIs have no bank picker, so we
            // never block them (untagged ones surface in the "Unassigned"
            // bucket on Bank Balances instead).
            $effectiveBankId = $this->resolveReceivingBankId($request, $ledger);
            if ($effectiveBankId) {
                $ledger->receiving_account_id = $effectiveBankId;
            }
            $bankMandatory = $ledger->mode === LedgerModel::MODE_ONLINE
                && in_array($ledger->transaction_type, [LedgerModel::TYPE_INVOICE, LedgerModel::TYPE_ORDER_PAYMENT], true);
            if ($bankMandatory && !$ledger->receiving_account_id) {
                DB::rollBack();
                $msg = 'Select which bank received this online payment before approving.';
                if ($request->ajax() || $request->wantsJson()) {
                    return response()->json(['success' => false, 'message' => $msg], 422);
                }
                return back()->with('error', $msg);
            }
            // Name the bank in the description so the ledger listing shows it.
            // Guarded so an L1-then-L2 approval doesn't append twice.
            $this->appendBankToDescription($ledger);

            // Save customer-side transaction reference if provided. Only
            // overwrite when the caller actually sent a value, so repeat
            // approvals (e.g. L1 then L2) don't wipe what L1 captured.
            if ($request->filled('transaction_reference')) {
                $ledger->transaction_reference = trim($request->input('transaction_reference'));
            }

            $ledger->save();

            // Jun-2026 — On FINAL approval, learn this customer's bank-account
            // name from any payment-proof signals matched to the order, so
            // future bank emails can identify them even without a WhatsApp
            // screenshot. Read-only learning; never blocks approval.
            if ($finalApproval && config('payment_signals.enabled') && $ledger->order_id) {
                try {
                    $aliasCustomerId = \DB::table('t_crm_prod_order')
                        ->where('id', $ledger->order_id)->value('customer_id');
                    app(\App\Services\Payments\Signals\CustomerBankAliasService::class)
                        ->learnFromApprovedOrder(
                            (int) $ledger->order_id,
                            $aliasCustomerId ? (int) $aliasCustomerId : null,
                            auth()->check() ? (int) auth()->id() : null
                        );
                } catch (\Throwable $aliasErr) {
                    \Log::debug('Payment alias learn on approve skipped (non-fatal)', [
                        'error' => $aliasErr->getMessage(),
                    ]);
                }
            }

            // Reload accounts (in case they were changed)
            $ledger->load(['fromAccount', 'toAccount']);
            $fromAccount = $ledger->fromAccount;
            $toAccount = $ledger->toAccount;

            // Apply balances via the single canonical engine. Idempotent on balance_updated:
            // applies once at L1 (early reflect) / force-full / single-level, and is a no-op at L2
            // (already applied) or for a historical row that somehow already carries the flag.
            (new BalancePostingService())->apply($ledger);
            // Refresh the in-memory account objects so any code below reads post-apply balances.
            $ledger->load(['fromAccount', 'toAccount']);
            $fromAccount = $ledger->fromAccount;
            $toAccount = $ledger->toAccount;

            // ========== SETTLEMENT PROCESSING ==========
            // If this is an employee deposit with settlement intent, process it
            if ($ledger->transaction_type === LedgerModel::TYPE_EMPLOYEE_DEPOSIT) {
                // Try to get settlement data from ledger metadata (NEW) or fallback to session (OLD)
                $settlementData = $ledger->settlement_metadata;
                
                // Fallback to session for old deposits
                if (!$settlementData) {
                    $sessionKey = "settlement_pending_{$ledger->id}";
                    $settlementData = \Session::get($sessionKey);
                    \Log::info("Using session fallback for settlement data", [
                        'deposit_id' => $ledger->id
                    ]);
                }
                
                \Log::info("Checking for settlement data", [
                    'deposit_id' => $ledger->id,
                    'has_metadata' => $ledger->settlement_metadata ? 'yes' : 'no',
                    'has_data' => $settlementData ? 'yes' : 'no',
                    'data' => $settlementData
                ]);
                
                if ($settlementData && isset($settlementData['invoice_ids'])) {
                    \Log::info("Processing invoice settlement", [
                        'deposit_id' => $ledger->id,
                        'invoice_count' => count($settlementData['invoice_ids']),
                        'invoice_ids' => $settlementData['invoice_ids']
                    ]);
                    
                    $this->processInvoiceSettlement($ledger, $settlementData);
                    
                    // Check if this is a short cash settlement with linked expense request
                    if (isset($settlementData['is_short_cash_settlement']) && 
                        $settlementData['is_short_cash_settlement'] && 
                        isset($settlementData['expense_request_id'])) {
                        
                        $expenseRequestId = $settlementData['expense_request_id'];
                        
                        \Log::info("Auto-approving linked short cash expense", [
                            'deposit_id' => $ledger->id,
                            'expense_request_id' => $expenseRequestId
                        ]);
                        
                        // Auto-approve the linked expense request
                        $expenseRequest = \App\Models\Request\RequestModel::find($expenseRequestId);
                        
                        if ($expenseRequest && $expenseRequest->status === 'pending') {
                            // Process the approval (level, approverId, action, comments)
                            $expenseRequest->processApproval(1, auth()->id(), 'approved', 'Auto-approved with deposit settlement');
                            
                            \Log::info("Short cash expense auto-approved", [
                                'expense_request_id' => $expenseRequestId,
                                'amount' => $expenseRequest->amount
                            ]);
                        }
                    }
                    
                    // Clean up session if it was used
                    \Session::forget("settlement_pending_{$ledger->id}");
                } else {
                    \Log::warning("No settlement data found for deposit - invoices will not be auto-settled", [
                        'deposit_id' => $ledger->id,
                        'description' => $ledger->description
                    ]);
                }
            }

            DB::commit();

            // Decide success message based on whether this was final approval or L1 only
            $successMessage = $finalApproval
                ? 'Transaction approved successfully!'
                : 'Transaction approved at Level 1 (reflected in balances). Awaiting Level 2 verification.';

            // If AJAX request (from modal), return JSON with updated balance
            if ($request->ajax() || $request->wantsJson()) {
                $updatedToAccount = $ledger->to_account_id ? AccountModel::find($ledger->to_account_id) : null;
                return response()->json([
                    'success' => true,
                    'message' => $successMessage,
                    'new_balance' => $updatedToAccount ? $updatedToAccount->current_balance : null,
                    // Aug-2026 — lets the approvals screen ask about an overpayment
                    // straight after settling the invoice. Purely informational; the
                    // approval is already committed by this point.
                    'order_id' => $ledger->order_id,
                ]);
            }

            // Redirect back to where they came from (if from outstanding invoices page, stay there)
            if ($request->input('_origin') === 'outstanding-invoices' || str_contains(url()->previous(), 'outstanding-invoices')) {
                return redirect()->route('fin.employee.all-outstanding-invoices')
                               ->with('success', $successMessage);
            }

            if ($request->input('_origin') === 'approvals' || str_contains(url()->previous(), 'approvals')) {
                return redirect()->route('approvals.index')
                               ->with('success', $successMessage);
            }

            return redirect()->route('fin.ledger.index')
                           ->with('success', $successMessage);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error approving transaction: " . $e->getMessage());
            
            // If AJAX request, return JSON error
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error approving transaction: ' . $e->getMessage()
                ], 500);
            }
            
            if ($request->input('_origin') === 'outstanding-invoices') {
                return redirect()->route('fin.employee.all-outstanding-invoices')
                               ->with('error', 'Error approving transaction: ' . $e->getMessage());
            }
            return back()->with('error', 'Error approving transaction: ' . $e->getMessage());
        }
    }

    /**
     * Approve transaction at L1 only (for L2 users who want to use proper two-stage approval)
     * This forces the transaction to move to L2 pending, even if the user has L2 rights
     */
    public function approveAtL1Only(Request $request, $id)
    {
        $request->validate([
            'approval_notes' => 'nullable|string|max:500',
            'proof_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120', // ⭐ Optional proof of payment image
            'receiving_account_id' => 'nullable|exists:t_fin_online_receiving_accounts,id', // ⭐ Which bank received this payment
            'transaction_reference' => 'nullable|string|max:255',
        ]);

        try {
            DB::beginTransaction();

            $ledger = LedgerModel::with(['fromAccount', 'toAccount'])->findOrFail($id);

            // [Ledger approval gate — C1 fix] L1-only approval requires at least Level 1.
            if ($gateResp = $this->guardApprovalRights($request, 1)) {
                DB::rollBack();
                return $gateResp;
            }

            // ⭐ Handle optional proof of payment image upload
            $proofImagePath = $this->handleApprovalImageUpload($request, $ledger);

            // Validation: Must be at L1 pending stage
            if (!in_array($ledger->approval_status, [LedgerModel::STATUS_PENDING, LedgerModel::STATUS_PENDING_L1])) {
                throw new \Exception("Transaction is not at L1 pending stage");
            }

            // Determine required levels from Request Settings
            $categoryCode = null;
            switch ($ledger->transaction_type) {
                case LedgerModel::TYPE_INVOICE:
                    $categoryCode = 'invoice_approval';
                    break;
                case LedgerModel::TYPE_EMPLOYEE_DEPOSIT:
                    $categoryCode = 'employee_deposit';
                    break;
                case LedgerModel::TYPE_VENDOR_PAYMENT:
                    $categoryCode = 'vendor_payment';
                    break;
                case LedgerModel::TYPE_TRANSFER:
                    $categoryCode = 'account_transfer';
                    break;
            }

            $requiresL2 = false;
            if ($categoryCode) {
                $category = RequestCategoryModel::getByCode($categoryCode);
                if ($category) {
                    $requiresL2 = $category->requiresLevel2();
                }
            }

            // Validation: Must require L2 approval
            if (!$requiresL2) {
                throw new \Exception("This transaction does not require L2 approval");
            }

            // Move to L2 pending; balances applied below via the engine (L2 is verification only).
            $ledger->approval_status = LedgerModel::STATUS_PENDING_L2;
            $ledger->comments = ($ledger->comments ?? '') .
                " | L1 approved by User ID " . auth()->id() . " (L1-only approval)";

            // Add approval notes if provided
            if ($request->approval_notes) {
                $ledger->comments = ($ledger->comments ? $ledger->comments . "\n\n" : '') . 
                                   "L1 Approval Notes: " . $request->approval_notes;
            }

            // ⭐ Save proof of payment image if uploaded
            if ($proofImagePath) {
                $ledger->bill_image = $proofImagePath;
            }

            // ⭐ Receiving bank: explicit choice wins; else auto-apply the bank
            // detected from the payment proof. Mandatory ONLY for the
            // Online-Approvals queue types (see approve() for the rationale).
            $effectiveBankId = $this->resolveReceivingBankId($request, $ledger);
            if ($effectiveBankId) {
                $ledger->receiving_account_id = $effectiveBankId;
            }
            $bankMandatory = $ledger->mode === LedgerModel::MODE_ONLINE
                && in_array($ledger->transaction_type, [LedgerModel::TYPE_INVOICE, LedgerModel::TYPE_ORDER_PAYMENT], true);
            if ($bankMandatory && !$ledger->receiving_account_id) {
                DB::rollBack();
                $msg = 'Select which bank received this online payment before approving.';
                if ($request->ajax() || $request->wantsJson()) {
                    return response()->json(['success' => false, 'message' => $msg], 422);
                }
                return back()->with('error', $msg);
            }
            // Name the bank in the description (guarded against double-append).
            $this->appendBankToDescription($ledger);

            // Save optional customer-side transaction reference on L1-only.
            if ($request->filled('transaction_reference')) {
                $ledger->transaction_reference = trim($request->input('transaction_reference'));
            }

            $ledger->save();

            // Apply balances at L1 via the single canonical engine (idempotent; L2 verification
            // will not re-apply, and reject()/revertToPending() reverse() to roll back).
            (new BalancePostingService())->apply($ledger);

            DB::commit();

            $successMessage = "Transaction approved at Level 1 and reflected in balances. Now pending Level 2 verification.";

            // If AJAX request (from modal), return JSON with updated balance
            if ($request->ajax() || $request->wantsJson()) {
                $updatedToAccount = $ledger->to_account_id ? AccountModel::find($ledger->to_account_id) : null;
                return response()->json([
                    'success' => true,
                    'message' => $successMessage,
                    'new_balance' => $updatedToAccount ? $updatedToAccount->current_balance : null,
                    // Aug-2026 — lets the approvals screen ask about an overpayment
                    // straight after settling the invoice. Purely informational; the
                    // approval is already committed by this point.
                    'order_id' => $ledger->order_id,
                ]);
            }

            // Redirect based on origin
            if ($request->input('_origin') === 'approvals' || str_contains(url()->previous(), 'approvals')) {
                return redirect()->route('approvals.index')
                               ->with('success', $successMessage);
            }

            return redirect()->route('fin.ledger.index')
                           ->with('success', $successMessage);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error approving transaction at L1: " . $e->getMessage());
            
            // If AJAX request, return JSON error
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error: ' . $e->getMessage()
                ], 500);
            }
            
            return back()->with('error', 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Reject pending transaction
     */
    public function reject(Request $request, $id)
    {
        // Make rejection_reason optional for quick rejects
        $request->validate([
            'rejection_reason' => 'nullable|string|max:500'
        ]);

        try {
            DB::beginTransaction();

            $ledger = LedgerModel::with(['fromAccount', 'toAccount'])->findOrFail($id);

            // [Ledger approval gate — C1 fix] Rejecting requires L1; rejecting an L2-pending item
            // (which reverses an already-posted balance) requires L2 — owner ruling R1.
            $gateLevel = $ledger->approval_status === LedgerModel::STATUS_PENDING_L2 ? 2 : 1;
            if ($gateResp = $this->guardApprovalRights($request, $gateLevel)) {
                DB::rollBack();
                return $gateResp;
            }

            if (!$ledger->isPending()) {
                throw new \Exception("Transaction is not pending approval");
            }

            $wasAtL2 = $ledger->approval_status === LedgerModel::STATUS_PENDING_L2;

            $ledger->approval_status = LedgerModel::STATUS_REJECTED;
            $ledger->approved_by = auth()->id();
            $ledger->approval_date = now()->toDateString();
            
            // Add rejection reason to comments (if provided)
            if ($request->rejection_reason) {
                $ledger->comments = ($ledger->comments ? $ledger->comments . "\n\n" : '') . 
                                   "Rejection Reason: " . $request->rejection_reason;
            }

            // Reverse account balances if they were already applied at L1 — the "roll back an L1
            // mistake" path. The engine is the exact inverse of approve()'s apply() and no-ops if
            // nothing was applied.
            if ($ledger->balance_updated) {
                (new BalancePostingService())->reverse($ledger);
                $ledger->comments = ($ledger->comments ? $ledger->comments . "\n" : '') .
                    "Balance reversed due to L2 rejection by User ID " . auth()->id();
                Log::info("Reversed account balances on L2 rejection", [
                    'ledger_id' => $ledger->id,
                    'amount' => $ledger->amount,
                    'from_account' => $ledger->from_account_id,
                    'to_account' => $ledger->to_account_id,
                ]);
            }
            
            $ledger->save();

            DB::commit();
            
            // Clean up settlement data from session
            \Session::forget("settlement_pending_{$ledger->id}");

            $successMessage = 'Transaction rejected successfully!';

            // If AJAX request (from modal), return JSON with updated balance
            if ($request->ajax() || $request->wantsJson()) {
                $updatedToAccount = $ledger->to_account_id ? AccountModel::find($ledger->to_account_id) : null;
                return response()->json([
                    'success' => true,
                    'message' => $successMessage,
                    'new_balance' => $updatedToAccount ? $updatedToAccount->current_balance : null,
                    // Aug-2026 — lets the approvals screen ask about an overpayment
                    // straight after settling the invoice. Purely informational; the
                    // approval is already committed by this point.
                    'order_id' => $ledger->order_id,
                ]);
            }

            // Redirect back to where they came from (if from outstanding invoices page, stay there)
            if ($request->input('_origin') === 'outstanding-invoices' || str_contains(url()->previous(), 'outstanding-invoices')) {
                return redirect()->route('fin.employee.all-outstanding-invoices')
                               ->with('success', 'Settlement deposit rejected successfully.');
            }

            if ($request->input('_origin') === 'approvals' || str_contains(url()->previous(), 'approvals')) {
                return redirect()->route('approvals.index')
                               ->with('success', $successMessage);
            }

            return redirect()->route('fin.ledger.index')
                           ->with('success', $successMessage);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error rejecting transaction: " . $e->getMessage());
            
            // If AJAX request, return JSON error
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error rejecting transaction: ' . $e->getMessage()
                ], 500);
            }
            
            if ($request->input('_origin') === 'outstanding-invoices') {
                return redirect()->route('fin.employee.all-outstanding-invoices')
                               ->with('error', 'Error rejecting transaction: ' . $e->getMessage());
            }
            return back()->with('error', 'Error rejecting transaction: ' . $e->getMessage());
        }
    }

    /**
     * Revert an APPROVED online invoice back to "pending approval" (Level 1).
     *
     * Purpose: undo an accidental approval of an online payment so it returns
     * to the approval queue, with balances corrected exactly. This is the
     * precise inverse of approve() and reuses reject()'s balance-reversal
     * logic (the app's own, production-tested inverse of approve()).
     *
     * SAFETY GUARDS (intentionally strict — do not loosen without re-checking
     * the balance math for the new case):
     *  - Only the "Taimur" role may call this.
     *  - Single transaction only (route takes one id; no bulk).
     *  - Only transactions that are currently APPROVED.
     *  - Only ONLINE INVOICE transactions. These are guaranteed to have been
     *    approved through the pending queue (invoice_approval has no
     *    auto-approve threshold, so the balance was always applied via
     *    approve() — from(income) += amount, to(asset) += amount). That makes
     *    reject()'s reversal the correct inverse. Order payments / deposits /
     *    expenses / transfers are deliberately excluded because their approval
     *    has different (or settlement) side-effects.
     *  - Refused if any invoice-settlement rows reference this ledger.
     */
    public function revertToPending(Request $request, $id)
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        // --- Authorization: ONLY the "Taimur" role may revert an approval ---
        $user = auth()->user();
        $isTaimur = $user && $user->roles()
            ->whereRaw('LOWER(urole_name) = ?', ['taimur'])
            ->exists();
        if (!$isTaimur) {
            $msg = 'Only the Taimur role can revert an approved transaction.';
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $msg], 403);
            }
            return back()->with('error', $msg);
        }

        try {
            DB::beginTransaction();

            $ledger = LedgerModel::with(['fromAccount', 'toAccount'])->findOrFail($id);

            // Guard 1: must currently be approved.
            if ($ledger->approval_status !== LedgerModel::STATUS_APPROVED) {
                throw new \Exception('Only approved transactions can be reverted to pending.');
            }

            // Guard 2: scope strictly to ONLINE INVOICE transactions.
            if ($ledger->transaction_type !== LedgerModel::TYPE_INVOICE
                || $ledger->mode !== LedgerModel::MODE_ONLINE) {
                throw new \Exception('Only online invoice payments can be reverted from here.');
            }

            // Guard 3: never touch anything tied to invoice settlements
            // (defensive — online invoices are not settlement deposits, but be
            // safe). The junction links a settling deposit (settlement_deposit_id)
            // to the invoice it settled (invoice_ledger_id); refuse if this
            // ledger appears as either side.
            $hasSettlements = DB::table('t_fin_invoice_settlements')
                ->where('settlement_deposit_id', $ledger->id)
                ->orWhere('invoice_ledger_id', $ledger->id)
                ->exists();
            if ($hasSettlements) {
                throw new \Exception('This transaction has linked settlements and cannot be reverted here.');
            }

            // Reverse the balances EXACTLY like reject() (the canonical inverse of approve()).
            // Only when they were actually applied. The engine no-ops if the flag is already 0.
            if ($ledger->balance_updated) {
                if (!$ledger->from_account_id || !$ledger->to_account_id) {
                    throw new \Exception('Linked accounts are missing; cannot safely reverse balances.');
                }
                (new BalancePostingService())->reverse($ledger);
            }

            // Send it back to the start of the approval queue (Level 1). This
            // restores the exact post-creation state: pending_l1, no balance
            // applied, no approver. Re-approving later re-applies the balance.
            $reason = trim((string) $request->input('reason'));
            $ledger->approval_status = LedgerModel::STATUS_PENDING_L1;
            $ledger->approved_by = null;
            $ledger->approval_date = null;
            $ledger->comments = ($ledger->comments ? $ledger->comments . "\n" : '')
                . 'Approval reverted to pending (L1) by ' . ($user->fullname ?? $user->name ?? ('User ID ' . $user->id))
                . ' on ' . now()->toDateTimeString()
                . ($reason !== '' ? ' — Reason: ' . $reason : '');
            $ledger->save();

            DB::commit();

            $msg = 'Transaction reverted to pending approval.';
            if ($request->ajax() || $request->wantsJson()) {
                $updatedToAccount = $ledger->to_account_id ? AccountModel::find($ledger->to_account_id) : null;
                return response()->json([
                    'success' => true,
                    'message' => $msg,
                    'new_balance' => $updatedToAccount ? $updatedToAccount->current_balance : null,
                ]);
            }

            return back()->with('success', $msg);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error reverting transaction to pending: ' . $e->getMessage(), ['ledger_id' => $id]);

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }
            return back()->with('error', 'Error reverting transaction: ' . $e->getMessage());
        }
    }

    /**
     * Process invoice settlement when deposit is approved
     * 
     * @param LedgerModel $depositLedger The approved deposit transaction
     * @param array $settlementData Contains invoice_ids, deposit_amount, total_outstanding
     */
    private function processInvoiceSettlement(LedgerModel $depositLedger, array $settlementData)
    {
        try {
            $invoiceIds = $settlementData['invoice_ids'];
            $depositAmount = $settlementData['deposit_amount'];
            $totalOutstanding = $settlementData['total_outstanding'];
            
            // Check if this is a short cash settlement or partial payment
            $isShortCash = $settlementData['is_short_cash_settlement'] ?? false;
            $isPartialPayment = $settlementData['is_partial_payment'] ?? false;
            $shortCashAmount = $settlementData['short_cash_amount'] ?? 0;
            
            // For short cash, the total amount settling invoices = deposit + expense
            // For partial payment, only the deposit amount is used (remaining stays open)
            // 2dp — old metadata may carry raw floats (pre-Jul-2026 deposits)
            if ($isShortCash) {
                $totalSettlementAmount = round($depositAmount + $shortCashAmount, 2);
            } else {
                $totalSettlementAmount = round($depositAmount, 2);
            }
            
            \Log::info("Processing invoice settlement", [
                'deposit_id' => $depositLedger->id,
                'is_short_cash' => $isShortCash,
                'is_partial_payment' => $isPartialPayment,
                'deposit_amount' => $depositAmount,
                'short_cash_amount' => $shortCashAmount,
                'total_settlement_amount' => $totalSettlementAmount
            ]);
            
            // Get the invoices that need to be settled (in order) - include both open and partial
            $invoices = LedgerModel::whereIn('id', $invoiceIds)
                ->whereIn('settlement_status', ['open', 'partial'])
                ->orderBy('transaction_date', 'asc')
                ->get();
            
            $remainingAmount = $totalSettlementAmount;

            foreach ($invoices as $invoice) {
                $outstandingForThisInvoice = round($invoice->amount - ($invoice->settled_amount ?? 0), 2);

                if ($remainingAmount < 0.01) {
                    break; // No more money to allocate
                }

                // Calculate how much to settle on this invoice (2dp)
                $amountToSettle = round(min($remainingAmount, $outstandingForThisInvoice), 2);
                if ($amountToSettle <= 0) {
                    continue; // nothing outstanding on this invoice — skip, no zero audit rows
                }

                // Update invoice
                $invoice->settled_amount = round(($invoice->settled_amount ?? 0) + $amountToSettle, 2);

                if ($invoice->settled_amount >= $invoice->amount) {
                    // Fully settled
                    $invoice->settlement_status = 'settled';
                    $invoice->settled_at = now();
                    $invoice->settled_via_ledger_id = $depositLedger->id;
                } else {
                    // Partially settled: keep status 'open' (legacy behavior). We infer partial by settled_amount > 0
                    // Do not write a non-existent enum value like 'partial' to the database.
                }
                $invoice->save();

                // Create audit record
                \App\Models\FIN\InvoiceSettlementModel::create([
                    'settlement_deposit_id' => $depositLedger->id,
                    'invoice_ledger_id' => $invoice->id,
                    'settled_amount' => $amountToSettle
                ]);

                $remainingAmount = round($remainingAmount - $amountToSettle, 2);
            }

            // An un-allocatable remainder means an invoice in this deposit was settled by
            // ANOTHER deposit between create and approval. Never drop it silently (Jul-2026:
            // Rs 4,956.10 of deposit 17018 vanished this way) — flag it on the deposit.
            if ($remainingAmount >= 0.01) {
                \Log::warning("Settlement deposit approved with UNALLOCATED remainder", [
                    'deposit_id' => $depositLedger->id,
                    'amount_remaining' => $remainingAmount,
                ]);
                $note = "⚠ Rs. " . number_format($remainingAmount, 2) . " of this deposit could not be applied to any invoice (already settled elsewhere at approval time). Needs manual review.";
                $depositLedger->comments = trim(($depositLedger->comments ? $depositLedger->comments . " | " : "") . $note);
                $depositLedger->save();
            }

            \Log::info("Invoice settlement completed", [
                'deposit_id' => $depositLedger->id,
                'invoices_count' => $invoices->count(),
                'total_settlement_amount' => $totalSettlementAmount,
                'amount_allocated' => $totalSettlementAmount - $remainingAmount,
                'amount_remaining' => $remainingAmount,
                'is_short_cash' => $isShortCash,
                'is_partial_payment' => $isPartialPayment
            ]);
            
        } catch (\Exception $e) {
            \Log::error("Error processing invoice settlement: " . $e->getMessage(), [
                'deposit_id' => $depositLedger->id,
                'settlement_data' => $settlementData
            ]);
            throw $e;
        }
    }

    /**
     * Get approval details for a transaction (for audit trail modal)
     */
    public function getApprovalDetails($id)
    {
        try {
            $transaction = LedgerModel::with(['approvedBy', 'fromAccount', 'toAccount', 'order'])
                ->findOrFail($id);
            
            // Get invoice details if this is a settlement transaction
            $invoices = [];
            if ($transaction->settlement_metadata && isset($transaction->settlement_metadata['invoice_ids'])) {
                $invoiceIds = $transaction->settlement_metadata['invoice_ids'];
                $invoiceModels = LedgerModel::whereIn('id', $invoiceIds)
                    ->with('order')
                    ->orderBy('transaction_date', 'asc')
                    ->get();
                
                foreach ($invoiceModels as $invoice) {
                    $invoices[] = [
                        'id' => $invoice->id,
                        'order_number' => $invoice->order ? $invoice->order->order_number : 'Invoice #' . $invoice->id,
                        'order_id' => $invoice->order_id,
                        'date' => $invoice->transaction_date->format('M j, Y'),
                        'amount' => $invoice->amount,
                        'settlement_status' => $invoice->settlement_status,
                        'settled_amount' => $invoice->settled_amount ?? 0
                    ];
                }
            }
            
            return response()->json([
                'success' => true,
                'transaction' => [
                    'id' => $transaction->id,
                    'transaction_type' => ucfirst(str_replace('_', ' ', $transaction->transaction_type)),
                    'description' => $transaction->description,
                    'amount' => $transaction->amount,
                    'approval_status' => $transaction->approval_status,
                    'approval_date' => $transaction->approval_date,
                    'approver_name' => $transaction->approvedBy ? $transaction->approvedBy->fullname : 'System',
                    'from_account' => $transaction->fromAccount ? $transaction->fromAccount->account_name : null,
                    'to_account' => $transaction->toAccount ? $transaction->toAccount->account_name : null,
                    'invoices' => $invoices,
                    'total_outstanding' => $transaction->settlement_metadata['total_outstanding'] ?? null
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error("Error getting approval details: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error loading approval details'
            ], 500);
        }
    }

    /**
     * Get transaction details for viewing
     */
    public function getTransactionDetails($id)
    {
        try {
            $transaction = LedgerModel::with(['fromAccount', 'toAccount', 'createdBy'])
                ->findOrFail($id);

            // Fetch line items if this is a weighted purchase
            $lineItems = [];
            if ($transaction->transaction_type === LedgerModel::TYPE_VENDOR_PURCHASE) {
                $items = \App\Models\FIN\VendorPurchaseItemModel::where('ledger_id', $transaction->id)->get();
                // ❄ Which lines were deliberately "not an ingredient" (a carrier bag bought
                //   under a tagged product). An edit that does not send this back used to
                //   re-count the bag as cheese. Additive key.
                $taggedProducts = [];
                try {
                    if (\App\Models\FIN\VendorPurchaseItemModel::supportsIngredients()) {
                        $taggedProducts = \App\Models\FIN\VendorProductModel::whereIn('id', $items->pluck('vendor_product_id')->filter()->all())
                            ->whereNotNull('ingredient_id')->pluck('id')->flip()->all();
                    }
                } catch (\Throwable $e) {
                    $taggedProducts = [];
                }
                $lineItems = $items
                    ->map(function($item) use ($taggedProducts) {
                        return [
                            'vendor_product_id' => $item->vendor_product_id,
                            'product_name' => $item->product_name,
                            'quantity' => $item->quantity,
                            'unit' => $item->unit,
                            'rate_per_unit' => $item->rate_per_unit,
                            'line_total' => $item->line_total,
                            'not_ingredient' => isset($taggedProducts[$item->vendor_product_id]) && empty($item->ingredient_id),
                        ];
                    })
                    ->toArray();
            }

            // ⭐ Generate full image URL if bill_image exists (using public-storage proxy like attendance)
            $toUrl = function ($path) {
                if (!$path) {
                    return null;
                }
                if (str_starts_with($path, 'http')) {
                    return $path;
                }
                // Use public-storage proxy endpoint (works for both web and mobile)
                $base = request()->getSchemeAndHttpHost();
                return rtrim($base, '/') . '/public-storage/' . ltrim($path, '/');
            };
            $billImageUrl = $toUrl($transaction->bill_image);

            // ⭐ ALL attached images (Aug-2026 multi-image). `bill_image` above stays the FIRST
            // one — that is what the currently-installed APK and older surfaces read. Before the
            // t_fin_ledger_images SQL runs this degrades to a one-entry list built from the column.
            $billImages = [];
            if (\App\Models\FIN\LedgerImageModel::ready()) {
                $billImages = \App\Models\FIN\LedgerImageModel::forLedger((int) $transaction->id)
                    ->map(function ($img) use ($toUrl) {
                        return ['id' => $img->id, 'url' => $toUrl($img->image_path)];
                    })
                    ->values()
                    ->toArray();
            } elseif ($billImageUrl) {
                $billImages = [['id' => null, 'url' => $billImageUrl]];
            }

            return response()->json([
                'success' => true,
                'transaction' => [
                    'id' => $transaction->id,
                    'transaction_date' => $transaction->transaction_date ? $transaction->transaction_date->format('Y-m-d') : '-',
                    'transaction_date_formatted' => $transaction->transaction_date ? $transaction->transaction_date->format('M j, Y') : '-',
                    'posted_date' => $transaction->posted_date ? $transaction->posted_date->format('Y-m-d') : ($transaction->transaction_date ? $transaction->transaction_date->format('Y-m-d') : '-'),
                    'transaction_type' => ucfirst(str_replace('_', ' ', $transaction->transaction_type)),
                    'description' => $transaction->description,
                    'amount' => (float) $transaction->amount, // Ensure numeric
                    'adjustment_amount' => (float) ($transaction->adjustment_amount ?? 0), // For weighted purchases
                    'bill_image' => $billImageUrl, // ⭐ Full URL using public-storage proxy
                    'bill_images' => $billImages, // ⭐ [{id, url}] — every attached image, display order
                    // ⭐ For the bank re-tag UI: raw mode + current bank tag, and a plain flag
                    // (transaction_type above is prettified for display, so it can't be tested).
                    'mode' => $transaction->mode,
                    'receiving_account_id' => $transaction->receiving_account_id,
                    'is_vendor_payment' => $transaction->transaction_type === LedgerModel::TYPE_VENDOR_PAYMENT,
                    'line_items' => $lineItems,
                    'from_account' => $transaction->fromAccount ? $transaction->fromAccount->account_name : '-',
                    'from_account_id' => $transaction->from_account_id,
                    'to_account' => $transaction->toAccount ? $transaction->toAccount->account_name : '-',
                    'to_account_id' => $transaction->to_account_id,
                    // fullname, not name — UserModel has no `name`, so this always returned '-'
                    'created_by' => $transaction->createdBy ? $transaction->createdBy->fullname : '-',
                    'created_at' => $transaction->created_at ? $transaction->created_at->format('M j, Y g:i A') : '-',
                ]
            ]);
        } catch (\Exception $e) {
            Log::error("Error getting transaction details: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error loading transaction details'
            ], 500);
        }
    }

    /**
     * Update transaction details
     */
    public function updateTransaction(Request $request, $id)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:500',
            'bill_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120' // Max 5MB
        ]);

        try {
            DB::beginTransaction();

            $transaction = LedgerModel::with(['fromAccount', 'toAccount'])->findOrFail($id);
            
            // Only allow editing of vendor purchases and payments
            if (!in_array($transaction->transaction_type, [
                LedgerModel::TYPE_VENDOR_PURCHASE,
                LedgerModel::TYPE_VENDOR_PAYMENT
            ])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only vendor transactions can be edited'
                ], 400);
            }

            $oldAmount = $transaction->amount;
            $newAmount = $request->amount;
            $amountDifference = $newAmount - $oldAmount;

            // Handle bill image upload. Aug-2026: a ledger row's images now live in
            // t_fin_ledger_images with bill_image as a MIRROR of the first (see
            // vendor_multi_bill_images_aug2026.sql). This single-file edit keeps its
            // replace semantics, but must replace the first TABLE row too — updating
            // only the column would leave the gallery pointing at the file deleted
            // below, and the two stores disagreeing.
            if ($request->hasFile('bill_image')) {
                // Delete old image if exists
                if ($transaction->bill_image && \Storage::disk('public')->exists($transaction->bill_image)) {
                    \Storage::disk('public')->delete($transaction->bill_image);
                }

                $file = $request->file('bill_image');
                $filename = 'vendor_' . time() . '_' . uniqid() . '_edit.' . $file->getClientOriginalExtension();
                $billImagePath = $file->storeAs('vendor_bills', $filename, 'public');
                $transaction->bill_image = $billImagePath;

                if (\App\Models\FIN\LedgerImageModel::ready()) {
                    $first = \App\Models\FIN\LedgerImageModel::where('ledger_id', $transaction->id)
                        ->orderBy('sort_order')->orderBy('id')->first();
                    if ($first) {
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
                }
            }

            // Update transaction
            $transaction->amount = $newAmount;
            $transaction->description = $request->description ?? $transaction->description;
            $transaction->updated_by = auth()->id();
            $transaction->save();

            // Update account balances by the DELTA, using the VendorController convention —
            // verified against production data (every vendor's stored balance equals
            // SUM(purchases) − SUM(payments) exactly):
            //   vendor_purchase (expense → vendor):  BOTH legs +amount
            //     (expense accumulates; vendor owed MORE — positive balance = we owe him)
            //   vendor_payment  (cash/bank → vendor): BOTH legs −amount
            //     (till/bank drops; vendor owed LESS)
            // So an amount edit moves both accounts by +diff (purchase) / −diff (payment).
            // [Ledger L1 fix — B5, corrected in L3 review] The original code added +diff to BOTH
            // accounts for BOTH types — right for purchases, wrong for payments (a Bank→Vendor
            // payment edited up moved Bank UP). The first fix used approve()'s asset-aware rule —
            // right for payments, wrong for purchases (vendor moved DOWN on an upward edit,
            // because the purchase row is oriented expense→vendor, not vendor→expense).
            // Guard on approval_status: vendor rows are created APPROVED with balances applied
            // at creation but WITHOUT balance_updated being set (VendorController + legacy
            // imports), so the flag cannot be trusted here — approved ⇒ applied is the reliable
            // rule for vendor transactions. A pending row (if one ever exists) must not move
            // balances (approve() applies them later).
            if ($amountDifference != 0 && $transaction->approval_status === LedgerModel::STATUS_APPROVED) {
                $sign = $transaction->transaction_type === LedgerModel::TYPE_VENDOR_PURCHASE ? 1 : -1;

                if ($transaction->fromAccount) {
                    $transaction->fromAccount->current_balance += $sign * $amountDifference;
                    $transaction->fromAccount->save();
                }

                if ($transaction->toAccount) {
                    $transaction->toAccount->current_balance += $sign * $amountDifference;
                    $transaction->toAccount->save();
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Transaction updated successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error updating transaction: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error updating transaction: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ⭐ Handle optional proof of payment image upload during approval
     * Supports both multipart file upload (web/mobile) and base64 (mobile fallback)
     * Follows same pattern as VendorController::handleImageUpload
     */
    private function handleApprovalImageUpload(Request $request, LedgerModel $ledger): ?string
    {
        // Check for multipart file upload (web & mobile FormData)
        if ($request->hasFile('proof_image')) {
            $file = $request->file('proof_image');
            $filename = 'approval_' . $ledger->id . '_' . time() . '.' . $file->getClientOriginalExtension();
            return $file->storeAs('approval_proofs', $filename, 'public');
        }

        // Check for base64 upload (mobile fallback)
        if ($request->has('proof_image_base64') && $request->input('proof_image_base64')) {
            $base64Image = $request->input('proof_image_base64');
            $image = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $base64Image));
            $filename = 'approval_' . $ledger->id . '_' . time() . '.jpg';
            \Storage::disk('public')->put('approval_proofs/' . $filename, $image);
            return 'approval_proofs/' . $filename;
        }

        return null;
    }
}

