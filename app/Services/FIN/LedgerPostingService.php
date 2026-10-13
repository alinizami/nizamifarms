<?php

namespace App\Services\FIN;

use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\ConfigModel;
use App\Models\CRM\OrderModel;
use App\Models\Request\RequestModel;
use App\Models\Request\RequestCategoryModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LedgerPostingService
{
    /**
     * Post invoice to ledger when order is delivered
     */
    public function postInvoiceFromOrder(OrderModel $order)
    {
        try {
            // Check if automatic posting is enabled
            $autoPostEnabled = ConfigModel::where('config_key', 'LEDGER_AUTO_POST_ENABLED')
                ->value('config_value');
            
            if ($autoPostEnabled !== '1') {
                Log::info("Automatic ledger posting is disabled", ['order_id' => $order->id]);
                return ['success' => false, 'message' => 'Automatic posting disabled'];
            }

            // Check if already posted
            if ($order->ledger_transaction_id) {
                Log::info("Order already has ledger entry", ['order_id' => $order->id]);
                return ['success' => true, 'message' => 'Already posted'];
            }

            // Check if order is actually delivered
            if ($order->order_status !== 'delivered') {
                return ['success' => false, 'message' => 'Order must be delivered to post to ledger'];
            }

            // ⭐ [Ledger L3] Qurbani orders are ALWAYS settled through the incremental order_payment
            // flow (like shop online), NEVER a full invoice. The caller's hasPreReceivedPayments()
            // guard was timing-dependent: a qurbani order delivered BEFORE its payment was recorded
            // slipped past it, posted an invoice, and the later payment DOUBLE-BOOKED it
            // (QUR26-607/883/890). This type-based skip is timing-independent and closes that hole.
            if ($order->qurbani_day || $order->qurbani_slot || $order->qurbani_region || $order->qurbani_delivery_type) {
                Log::info("Skipping invoice posting - qurbani order (settled via payments)", [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]);
                return ['success' => true, 'message' => 'Qurbani order — settled via payments, invoice posting skipped'];
            }

            // Load customer relationship if not already loaded
            if (!$order->relationLoaded('customer')) {
                $order->load('customer');
            }

            // Shop customers settle their ONLINE invoices via incremental
            // payments (like Qurbani), so we never post a full invoice for them.
            // Cash shop orders fall through to the normal rider-settlement
            // invoice flow, exactly like a regular customer.
            if ($order->customer
                && $order->customer->isShop()
                && in_array($order->payment_method, ['online', 'Online', 'bank_transfer', 'card', 'online_payment'], true)) {
                Log::info("Skipping invoice posting - shop customer online order (settled via payments)", [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]);
                return ['success' => true, 'message' => 'Shop online order — invoice settled via payments, posting skipped'];
            }

            DB::beginTransaction();

            // Concurrency guard (D6): lock the order row and RE-CHECK under the lock, so two
            // simultaneous delivery submissions can't both slip past the early ledger_transaction_id
            // check and post two invoices for the same order. The early check above stays as a cheap
            // fast-path; this is the race-safe one. (Backstopped at the DB level by the unique
            // active-invoice-per-order index — see LEDGER-L3-CONCURRENCY-GUARDS.sql.)
            $lockedOrder = OrderModel::where('id', $order->id)->lockForUpdate()->first();
            if ($lockedOrder && $lockedOrder->ledger_transaction_id) {
                DB::rollBack();
                Log::info("Order already has ledger entry (locked re-check)", ['order_id' => $order->id]);
                return ['success' => true, 'message' => 'Already posted'];
            }

            $salesAccount = ConfigModel::getSalesRevenueAccount();
            if (!$salesAccount) {
                throw new \Exception("Sales revenue account not found");
            }

            // Determine destination account based on payment method
            // Online payments: online, bank_transfer, card (require approval)
            $onlinePaymentMethods = ['online', 'Online', 'bank_transfer', 'card', 'online_payment'];

            // Set true when a CASH invoice with no assigned rider is routed to the
            // NF Cash (Main Till) account instead of a rider's cash account (owner
            // rule, Jul-2026). Used to mark the ledger row + notify the caller.
            $noRiderMainTill = false;
            
            if (in_array($order->payment_method, $onlinePaymentMethods)) {
                // Online invoice - use configured online bank account and approval levels
                $toAccount = ConfigModel::getOnlineBankAccount();
                if (!$toAccount) {
                    throw new \Exception("Online bank account not configured");
                }

                $mode = LedgerModel::MODE_ONLINE;

                // Determine required ledger approval level(s) from Invoice Approval category
                $invoiceCategory = RequestCategoryModel::getByCode('invoice_approval');
                $approvalStatus = LedgerModel::STATUS_APPROVED;

                if ($invoiceCategory) {
                    $config = $invoiceCategory->approvalConfig;
                    $requiresL1 = $invoiceCategory->requiresLevel1();
                    $requiresL2 = $invoiceCategory->requiresLevel2();

                    // Auto-approve small invoices if threshold is set
                    if ($config && $config->canAutoApprove((float) $order->total_price)) {
                        $approvalStatus = LedgerModel::STATUS_APPROVED;
                    } elseif ($requiresL1) {
                        // ⭐ FIX: Always start at L1 if L1 is required, regardless of L2
                        // After L1 approval, the approve() method will move it to L2 if needed
                        $approvalStatus = LedgerModel::STATUS_PENDING_L1;
                    } elseif ($requiresL2) {
                        // Only L2 required (no L1) - unlikely but handle it
                        $approvalStatus = LedgerModel::STATUS_PENDING_L2;
                    } else {
                        // No approval required
                        $approvalStatus = LedgerModel::STATUS_APPROVED;
                    }
                } else {
                    // Fallback: require L1 approval for online invoices by default
                    // L1 approval will move to L2 if needed based on category config
                    $approvalStatus = LedgerModel::STATUS_PENDING_L1;
                }
            } else {
                // Cash payment - find rider's cash account
                if ($order->assigned_rider_user_id) {
                    $rider = \App\Models\SysAdmin\UserModel::find($order->assigned_rider_user_id);
                    if ($rider) {
                        $toAccount = AccountModel::createEmployeeCashAccount($rider->id, $rider->fullname ?? $rider->name);
                    } else {
                        // Create action item for missing rider user
                        \App\Models\FIN\ActionItemModel::create([
                            'item_type' => 'missing_rider',
                            'severity' => 'high',
                            'title' => "Order #{$order->order_number} - Rider user not found",
                            'description' => "Rider with ID {$order->assigned_rider_user_id} not found in system",
                            'related_entity_type' => 'order',
                            'order_id' => $order->id,
                            'suggested_action' => 'Verify rider user exists or reassign order to valid rider',
                            'created_by' => auth()->id() ?? 1
                        ]);
                        throw new \Exception("Rider not found");
                    }
                } else {
                    // No rider assigned. Owner rule (Jul-2026): do NOT leave the
                    // delivery un-invoiced — post the CASH invoice to NF Cash
                    // (Main Till, account code NF_CASH) so the money is tracked,
                    // and flag it so the delivering user is warned. (Cash only;
                    // online orders never use a rider account.) Still record the
                    // missing-rider action item so ops can review these.
                    \App\Models\FIN\ActionItemModel::createMissingRiderItem($order);
                    $toAccount = AccountModel::getByCode('NF_CASH');
                    if (!$toAccount) {
                        // Misconfigured — fall back to the old hard failure rather
                        // than post to a null account (money must land somewhere real).
                        throw new \Exception("No rider assigned and NF Cash (Main Till) account 'NF_CASH' not found");
                    }
                    $noRiderMainTill = true;
                }
                $mode = LedgerModel::MODE_CASH;
                $approvalStatus = LedgerModel::STATUS_APPROVED; // Cash is auto-approved
            }

            if (!$toAccount) {
                throw new \Exception("Destination account not found");
            }

            // Build description with customer name
            // Use SAME priority as web app for customer name
            $customerName = 'Unknown Customer';
            
            // PRIORITY 1: order.name field (customer name from address)
            if (!empty($order->name) && trim($order->name)) {
                $customerName = trim($order->name);
            }
            // PRIORITY 2: customer relationship
            elseif ($order->customer) {
                if (!empty($order->customer->full_name) && trim($order->customer->full_name)) {
                    $customerName = trim($order->customer->full_name);
                } elseif (!empty($order->customer->name) && trim($order->customer->name)) {
                    $customerName = trim($order->customer->name);
                }
            }
            // PRIORITY 3: Fallback to address fields
            else {
                $firstName = trim($order->address_first_name ?? '');
                $lastName = trim($order->address_last_name ?? '');
                if ($firstName || $lastName) {
                    $customerName = trim("{$firstName} {$lastName}");
                }
            }
            
            $description = "Invoice #{$order->order_number} - Delivered ({$customerName})";
            if ($noRiderMainTill) {
                $description .= " [no rider — Main Till]";
            }

            // Determine if balances should be applied now:
            // - approved: yes (auto-approved or cash)
            // - pending_l2: yes (L2 is verification only, balance reflects at L1/creation)
            // - pending_l1: no (awaiting L1 approval)
            $applyBalanceNow = in_array($approvalStatus, [
                LedgerModel::STATUS_APPROVED,
                LedgerModel::STATUS_PENDING_L2,
            ]);

            // Create ledger entry
            $ledger = LedgerModel::create([
                'transaction_date' => now(),
                'transaction_type' => LedgerModel::TYPE_INVOICE,
                'description' => $description,
                'from_account_id' => $salesAccount->id,
                'to_account_id' => $toAccount->id,
                'amount' => $order->total_price,
                'mode' => $mode,
                'approval_status' => $approvalStatus,
                'balance_updated' => 0, // engine owns this (applies below when it should reflect now)
                'settlement_status' => 'open',
                'settled_amount' => 0.00,
                'approval_date' => $approvalStatus === LedgerModel::STATUS_APPROVED ? now() : null,
                'approved_by' => $approvalStatus === LedgerModel::STATUS_APPROVED ? auth()->id() : null,
                'order_id' => $order->id,
                'created_by' => auth()->id() ?? 1
            ]);

            // Apply balances via the canonical engine when this row should reflect now (approved or
            // pending_l2 — L2 is verification only). Same net move as before (revenue −, holder +),
            // now with account row-locking; the engine sets balance_updated. pending_l1 stays flag=0
            // and is applied later when the online approval posts it.
            if ($applyBalanceNow) {
                (new BalancePostingService())->apply($ledger);
            }

            // ⭐ Rs 0 CASH invoice (free / replacement order): nothing for the rider to
            // hand over, so settle it now — see LedgerModel::isSettledNothingToCollect().
            // Posted (not skipped) so the order keeps its ledger link. Engine apply above
            // moved 0, so balances are untouched either way.
            $ledger->refreshNothingToCollectSettlement('posted at delivery');
            if ($ledger->isDirty()) {
                $ledger->save();
            }

            // Link ledger to order
            $order->ledger_transaction_id = $ledger->id;
            $order->save();

            DB::commit();

            Log::info("Invoice posted to ledger", [
                'order_id' => $order->id,
                'ledger_id' => $ledger->id,
                'amount' => $order->total_price
            ]);

            return [
                'success' => true,
                'message' => $noRiderMainTill
                    ? 'No rider was assigned, so the cash invoice was posted to NF Cash (Main Till).'
                    : 'Invoice posted to ledger successfully',
                'ledger_id' => $ledger->id,
                'posted_to_main_till' => $noRiderMainTill,
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to post invoice to ledger", [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to post to ledger: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Post expense from approved request
     */
    public function postExpenseFromRequest(RequestModel $request)
    {
        try {
            // Check if already posted
            if ($request->ledger_transaction_id) {
                Log::info("Request already has ledger entry", ['request_id' => $request->id]);
                return ['success' => true, 'message' => 'Already posted'];
            }

            // Check if request is actually approved
            if ($request->status !== 'approved') {
                return ['success' => false, 'message' => 'Request must be approved to post to ledger'];
            }

            DB::beginTransaction();

            // Get funding account (payment source)
            // Priority: 1) payment_source_account_id, 2) Config default, 3) EXP_FUND
            $fundingAccount = null;
            
            if ($request->payment_source_account_id) {
                $fundingAccount = AccountModel::find($request->payment_source_account_id);
            }
            
            if (!$fundingAccount) {
                $fundingAccount = ConfigModel::getExpenseFundingAccount();
            }
            
            if (!$fundingAccount) {
                $fundingAccount = AccountModel::getByCode('EXP_FUND');
            }

            if (!$fundingAccount) {
                throw new \Exception("Payment source account not found");
            }
            
            // IMPORTANT: Save the funding account back to request if it was defaulted
            if (!$request->payment_source_account_id) {
                $request->payment_source_account_id = $fundingAccount->id;
                // Don't save yet - will save with ledger_transaction_id below
            }

            // Get or create expense account
            // Priority: 1) expense_category (specific), 2) category name (general)
            $expenseAccountName = $request->expense_category ?? $request->category->category_name;
            $expenseAccount = $this->getOrCreateExpenseAccount($expenseAccountName, $request->business_unit_id);

            if (!$expenseAccount) {
                throw new \Exception("Expense account not found");
            }

            // Build description (include requester name for ledger clarity)
            $requesterName = '';
            if ($request->relationLoaded('requester') && $request->requester) {
                $requesterName = $request->requester->fullname;
            } else {
                $requester = $request->requester()->first();
                $requesterName = $requester ? $requester->fullname : '';
            }

            $description = "Expense Request #{$request->request_number}";
            if ($request->expense_category) {
                $description .= " - {$request->expense_category}";
            } else {
                $description .= " - {$request->category->category_name}";
            }
            if ($requesterName) {
                $description .= " ({$requesterName})";
            }
            
            // When the expense is funded from an ONLINE bank account, mark the
            // ledger row 'online' and tag which bank it was paid from, so the
            // outflow is attributed to that bank's balance (mirrors how online
            // inflows are tagged). Cash-funded expenses stay mode=null / no bank.
            $isOnlineFunding = $fundingAccount->account_category === AccountModel::CATEGORY_BANK;
            $expenseBankId = $isOnlineFunding ? $request->receiving_account_id : null;

            // Name the bank in the description so the ledger listing shows at a
            // glance which bank the money left from.
            if ($expenseBankId) {
                $bankShort = \App\Models\FIN\OnlineReceivingAccountModel::find($expenseBankId)?->short_code;
                if ($bankShort) {
                    $description .= " · via {$bankShort}";
                }
            }

            // Create ledger entry
            // ⭐ Use expense_date for backdated expenses, otherwise approved_at or now
            $ledger = LedgerModel::create([
                'transaction_date' => $request->expense_date ?? $request->approved_at ?? now(),
                'transaction_type' => LedgerModel::TYPE_EXPENSE,
                'description' => $description,
                'from_account_id' => $fundingAccount->id,
                'to_account_id' => $expenseAccount->id,
                'amount' => $request->amount,
                'mode' => $isOnlineFunding ? LedgerModel::MODE_ONLINE : null,
                'receiving_account_id' => $expenseBankId,
                'approval_status' => LedgerModel::STATUS_APPROVED,
                'approval_date' => $request->approved_at,
                'request_id' => $request->id,
                'business_unit_id' => $request->business_unit_id ?? 1, // ⭐ Use request's BU
                'created_by' => $request->requester_user_id,
                'comments' => "Paid from: {$fundingAccount->account_name}"
            ]);

            // Apply via the canonical engine (funding −, expense +), row-locked; sets balance_updated.
            // Same net move as the old inline code. If the expense was paid from a rider's held cash
            // (from = employee_cash), that leg IS applied (expense is NOT an excluded type), which is
            // the intended R1 behaviour — the held-cash shortage is later bridged by the settlement.
            (new BalancePostingService())->apply($ledger);

            // Link ledger to request
            $request->ledger_transaction_id = $ledger->id;

            // Mark settlement status
            $this->markSettlementStatus($request, $fundingAccount);
            
            $request->save();

            DB::commit();

            Log::info("Expense posted to ledger", [
                'request_id' => $request->id,
                'ledger_id' => $ledger->id,
                'amount' => $request->amount
            ]);

            return [
                'success' => true,
                'message' => 'Expense posted to ledger successfully',
                'ledger_id' => $ledger->id
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to post expense to ledger", [
                'request_id' => $request->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to post to ledger: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Post salary advance from approved request
     * Follows same pattern as expense requests
     */
    public function postSalaryAdvanceFromRequest(RequestModel $request)
    {
        try {
            // Check if already posted
            if ($request->ledger_transaction_id) {
                Log::info("Request already has ledger entry", ['request_id' => $request->id]);
                return ['success' => true, 'message' => 'Already posted'];
            }

            // Check if request is actually approved
            if ($request->status !== 'approved') {
                return ['success' => false, 'message' => 'Request must be approved to post to ledger'];
            }

            DB::beginTransaction();

            // Get funding account (payment source)
            // Priority: 1) payment_source_account_id, 2) Config default, 3) EXP_FUND
            $fundingAccount = null;
            
            if ($request->payment_source_account_id) {
                $fundingAccount = AccountModel::find($request->payment_source_account_id);
            }
            
            if (!$fundingAccount) {
                $fundingAccount = ConfigModel::getExpenseFundingAccount();
            }
            
            if (!$fundingAccount) {
                $fundingAccount = AccountModel::getByCode('EXP_FUND');
            }

            if (!$fundingAccount) {
                throw new \Exception("Payment source account not found");
            }
            
            // IMPORTANT: Save the funding account back to request if it was defaulted
            if (!$request->payment_source_account_id) {
                $request->payment_source_account_id = $fundingAccount->id;
            }

            // Get or create employee cash account
            $employeeCashAccount = AccountModel::where('user_id', $request->requester_user_id)
                ->where('account_category', 'employee_cash')
                ->first();

            if (!$employeeCashAccount) {
                // Auto-create employee cash account
                $user = $request->requester;
                if (!$user) {
                    throw new \Exception('User not found');
                }

                $employeeCashAccount = AccountModel::createEmployeeCashAccount(
                    $request->requester_user_id,
                    $user->fullname
                );
            }

            if (!$employeeCashAccount) {
                throw new \Exception("Employee cash account not found or could not be created");
            }

            // Build description
            $description = "Salary Advance - {$request->requester->fullname}";
            if ($request->description) {
                $description .= " - {$request->description}";
            }
            
            // When the advance is funded from an ONLINE bank account, tag which
            // bank it left from so per-bank balances stay reconciled. Mode stays
            // 'cash' (a personal payment) — the bank math reads the accounts +
            // tag, not the mode.
            $advanceBankId = ($fundingAccount->account_category === AccountModel::CATEGORY_BANK)
                ? $request->receiving_account_id
                : null;

            // Name the bank in the description for the ledger listing.
            if ($advanceBankId) {
                $bankShort = \App\Models\FIN\OnlineReceivingAccountModel::find($advanceBankId)?->short_code;
                if ($bankShort) {
                    $description .= " · via {$bankShort}";
                }
            }

            // Create ledger entry
            $ledger = LedgerModel::create([
                // The day the money actually left, which is what `expense_date` now carries
                // (Sep-2026) — so a late-entered advance for last month is dated when the
                // transfer really happened and the ledger reconciles against the bank
                // statement. `posted_date` keeps the day it was TYPED as an audit breadcrumb.
                // Falls back to the old behaviour when no expense_date is set.
                'transaction_date' => $request->expense_date ?? $request->completed_at ?? now(),
                'posted_date' => now(),
                'transaction_type' => 'salary_advance',
                'description' => $description,
                'from_account_id' => $fundingAccount->id,
                'to_account_id' => $employeeCashAccount->id,
                'amount' => $request->amount,
                'mode' => 'cash',
                'receiving_account_id' => $advanceBankId,
                'approval_status' => LedgerModel::STATUS_APPROVED,
                'approval_date' => $request->completed_at,
                'approved_by' => $request->updated_by,
                'request_id' => $request->id,
                'external_source' => 'hr_salary_advance',
                'external_ref_id' => $request->request_number,
                'created_by' => $request->requester_user_id,
                'balance_updated' => 0, // engine applies below and sets this
                'comments' => "Paid from: {$fundingAccount->account_name}"
            ]);

            // Apply balances via the canonical engine. It debits the funding account (asset −) and,
            // because 'salary_advance' is an EXCLUDED employee-cash type, AUTOMATICALLY skips the
            // employee-cash leg — enforcing centrally the rule that a salary advance is a personal
            // payment TO the employee, not company cash they hold. Replaces the manual funding debit
            // plus the deliberately-omitted employee-cash credit (which used to be a hand-maintained
            // comment that the engine now guarantees).
            (new BalancePostingService())->apply($ledger);

            // Link ledger to request
            $request->ledger_transaction_id = $ledger->id;
            
            // Mark settlement status (same as expenses)
            $this->markSettlementStatus($request, $fundingAccount);
            
            $request->save();

            DB::commit();

            Log::info("Salary advance posted to ledger", [
                'request_id' => $request->id,
                'ledger_id' => $ledger->id,
                'amount' => $request->amount,
                'from_account' => $fundingAccount->account_code,
                'settlement_status' => $request->settlement_status
            ]);

            return [
                'success' => true,
                'message' => 'Salary advance posted to ledger successfully',
                'ledger_id' => $ledger->id
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to post salary advance to ledger", [
                'request_id' => $request->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to post to ledger: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get or create expense account for category
     */
    private function getOrCreateExpenseAccount($categoryName, $businessUnitId = null)
    {
        // ⭐ Oct-2026: a renamed / merged expense type keeps posting to the account it always
        // used (its pinned account_code). An unpinned name gets the same EXP_<NAME> slug as
        // before — ExpenseTypeService::slugCode() is this exact formula, moved.
        $code = ExpenseTypeService::accountCodeFor((string) $categoryName, $businessUnitId);

        return AccountModel::firstOrCreate(
            ['account_code' => $code],
            [
                'account_name' => 'Expense - ' . $categoryName,
                'account_type' => AccountModel::TYPE_EXPENSE,
                'account_category' => AccountModel::CATEGORY_EXPENSE,
                'is_active' => 1,
                'created_by' => auth()->id() ?? 1
            ]
        );
    }

    /**
     * Mark expense settlement status based on payment source
     * 
     * @param RequestModel $request
     * @param AccountModel $fundingAccount
     * @return void
     */
    private function markSettlementStatus($request, $fundingAccount): void
    {
        // ⭐ Storage (Supplies): a take-out is funded from the SUPPLIES_STOCK asset
        // account, so nobody is out of pocket — the cash left when the stock was
        // bought. Without this it would be flagged "pending settlement" and sit in
        // the settle queue forever with no one to reimburse.
        if ($fundingAccount->account_category === 'supplies_stock') {
            $request->settlement_status = 'not_required';
            return;
        }

        // Get Expense Fund account
        $expenseFund = ConfigModel::getExpenseFundingAccount();

        if (!$expenseFund) {
            $expenseFund = AccountModel::where('account_code', 'EXP_FUND')->first();
        }

        // If paid from Expense Fund, no settlement needed
        if ($expenseFund && $fundingAccount->id == $expenseFund->id) {
            $request->settlement_status = 'not_required';
        } else {
            // Otherwise, mark as pending settlement
            $request->settlement_status = 'pending';
        }
    }

    /**
     * Create invoice approval request for online payments
     * This replaces direct ledger posting for online invoices
     * 
     * @param OrderModel $order
     * @return array
     */
    private function createInvoiceApprovalRequest(OrderModel $order): array
    {
        try {
            DB::beginTransaction();
            
            // Get invoice_approval category
            $category = \App\Models\Request\RequestCategoryModel::getByCode('invoice_approval');
            if (!$category) {
                throw new \Exception("Invoice approval category not found. Please run the approval routing migration.");
            }
            
            // Get approval config
            $requiresL1 = $category->requiresLevel1();
            $requiresL2 = $category->requiresLevel2();
            
            // Determine assignee using routing rules
            $assignedToL1 = $this->getAssigneeForApproval(
                'request_category',
                'invoice_approval',
                1,
                [
                    'payment_mode' => 'online',
                    'amount' => $order->total_price
                ]
            );
            
            // Build customer name for description
            $customerName = $this->getCustomerNameFromOrder($order);
            
            // Create request
            $request = RequestModel::create([
                'request_number' => RequestModel::generateRequestNumber(),
                'category_id' => $category->id,
                'order_id' => $order->id,
                'requester_user_id' => $order->created_by ?? 1,
                'title' => "Online Invoice Approval - Order #{$order->order_number}",
                'description' => "Online payment invoice for {$customerName}\nOrder: {$order->order_number}\nPayment Method: {$order->payment_method}\nAmount: Rs. " . number_format($order->total_price, 2),
                'amount' => $order->total_price,
                'status' => RequestModel::STATUS_PENDING,
                'requires_level_1' => $requiresL1,
                'requires_level_2' => $requiresL2,
                'level_1_status' => $requiresL1 ? RequestModel::APPROVAL_STATUS_PENDING : null,
                'level_1_assigned_to' => $assignedToL1,
                'level_2_status' => $requiresL2 ? RequestModel::APPROVAL_STATUS_PENDING : null,
                'submitted_at' => now(),
                'created_by' => $order->created_by ?? 1
            ]);
            
            // Link request to order
            $order->invoice_request_id = $request->id;
            $order->save();
            
            DB::commit();
            
            Log::info("Invoice approval request created for online payment", [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'request_id' => $request->id,
                'request_number' => $request->request_number,
                'amount' => $order->total_price,
                'assigned_to' => $assignedToL1,
                'payment_method' => $order->payment_method
            ]);
            
            return [
                'success' => true,
                'message' => 'Invoice approval request created',
                'request_id' => $request->id,
                'request_number' => $request->request_number
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to create invoice approval request", [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to create approval request: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get assignee based on routing rules
     * 
     * @param string $areaType
     * @param string $areaIdentifier
     * @param int $level
     * @param array $context
     * @return int|null
     */
    private function getAssigneeForApproval(
        string $areaType,
        string $areaIdentifier,
        int $level,
        array $context = []
    ): ?int
    {
        try {
            // Query rules matching the criteria
            $query = DB::table('t_req_approval_rules')
                ->where('area_type', $areaType)
                ->where('area_identifier', $areaIdentifier)
                ->where('approval_level', $level)
                ->where('is_active', 1);
            
            // Apply contextual filters
            if (isset($context['payment_source_account_id'])) {
                $query->where(function($q) use ($context) {
                    $q->where('payment_source_account_id', $context['payment_source_account_id'])
                      ->orWhereNull('payment_source_account_id');
                });
            }
            
            if (isset($context['payment_mode'])) {
                $query->where(function($q) use ($context) {
                    $q->where('payment_mode', $context['payment_mode'])
                      ->orWhereNull('payment_mode');
                });
            }
            
            if (isset($context['amount'])) {
                $amount = $context['amount'];
                $query->where(function($q) use ($amount) {
                    $q->where(function($subQ) use ($amount) {
                        $subQ->whereNull('min_amount')
                             ->orWhere('min_amount', '<=', $amount);
                    })
                    ->where(function($subQ) use ($amount) {
                        $subQ->whereNull('max_amount')
                             ->orWhere('max_amount', '>=', $amount);
                    });
                });
            }
            
            // Get highest priority rule
            $rule = $query->orderBy('priority', 'asc')->first();
            
            if (!$rule) {
                Log::debug("No routing rule found for approval", [
                    'area_type' => $areaType,
                    'area_identifier' => $areaIdentifier,
                    'level' => $level,
                    'context' => $context
                ]);
                return null; // No rule found, use default behavior
            }
            
            // Get primary assignee for this rule
            $assignee = DB::table('t_req_approval_rule_assignees')
                ->where('rule_id', $rule->id)
                ->where('is_primary', 1)
                ->orderBy('sequence_order', 'asc')
                ->first();
            
            if ($assignee) {
                Log::debug("Assignee found via routing rule", [
                    'rule_id' => $rule->id,
                    'rule_name' => $rule->rule_name,
                    'user_id' => $assignee->user_id
                ]);
            }
            
            return $assignee ? $assignee->user_id : null;
            
        } catch (\Exception $e) {
            Log::error("Error getting assignee for approval", [
                'error' => $e->getMessage(),
                'area_type' => $areaType,
                'area_identifier' => $areaIdentifier
            ]);
            return null;
        }
    }

    /**
     * Get customer name from order
     * 
     * @param OrderModel $order
     * @return string
     */
    private function getCustomerNameFromOrder(OrderModel $order): string
    {
        $customerName = 'Unknown Customer';
        
        // PRIORITY 1: order.name field (customer name from address)
        if (!empty($order->name) && trim($order->name)) {
            $customerName = trim($order->name);
        }
        // PRIORITY 2: customer relationship
        elseif ($order->customer) {
            if (!empty($order->customer->full_name) && trim($order->customer->full_name)) {
                $customerName = trim($order->customer->full_name);
            } elseif (!empty($order->customer->name) && trim($order->customer->name)) {
                $customerName = trim($order->customer->name);
            }
        }
        // PRIORITY 3: Fallback to address fields
        else {
            $firstName = trim($order->address_first_name ?? '');
            $lastName = trim($order->address_last_name ?? '');
            if ($firstName || $lastName) {
                $customerName = trim("{$firstName} {$lastName}");
            }
        }
        
        return $customerName;
    }
}

