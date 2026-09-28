<?php

namespace App\Http\Controllers\Request;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Request\RequestModel;
use App\Models\Request\RequestCategoryModel;
use App\Models\SysAdmin\RoleApprovalLevelModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RequestController extends Controller
{
    /**
     * Display requests index page
     */
    public function index(Request $request)
    {
        $categories = RequestCategoryModel::getActiveCategories();
        $user = auth()->user();
        
        // Check if user has approval rights
        $canApproveLevel1 = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1);
        $canApproveLevel2 = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2);
        
        return view('pages.requests.index', compact('categories', 'canApproveLevel1', 'canApproveLevel2'));
    }

    /**
     * Get requests data (AJAX)
     */
    public function data(Request $request)
    {
        $user = auth()->user();
        $view = $request->input('view', 'my'); // my, pending_approval, all
        
        $query = RequestModel::with(['category', 'requester', 'approvals.approver', 'createdBy'])
            ->orderByDesc('created_at');

        // Check if user has permission to view all requests
        $canViewAllRequests = $user->hasPermission('view_all_requests');
        
        // Filter based on view
        if ($view === 'my') {
            $query->where('requester_user_id', $user->id);
        } elseif ($view === 'pending_approval') {
            // Get requests where user can approve
            $canApproveLevel1 = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1);
            $canApproveLevel2 = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2);
            
            $query->where('status', RequestModel::STATUS_PENDING);
            
            $query->where(function($q) use ($canApproveLevel1, $canApproveLevel2) {
                if ($canApproveLevel1) {
                    $q->orWhere(function($subQ) {
                        $subQ->where('requires_level_1', 1)
                             ->where('level_1_status', RequestModel::APPROVAL_STATUS_PENDING);
                    });
                }
                if ($canApproveLevel2) {
                    $q->orWhere(function($subQ) {
                        $subQ->where('requires_level_2', 1)
                             ->where('level_2_status', RequestModel::APPROVAL_STATUS_PENDING)
                             ->where(function($approvedL1) {
                                 $approvedL1->where('requires_level_1', 0)
                                            ->orWhere('level_1_status', RequestModel::APPROVAL_STATUS_APPROVED);
                             });
                    });
                }
            });
        } elseif ($view === 'all') {
            // Only users with 'view_all_requests' permission can view all
            if (!$canViewAllRequests) {
                // Fall back to 'my requests' if they don't have permission
                $query->where('requester_user_id', $user->id);
            }
            // Otherwise, show all (no filter applied)
        }

        // Additional filters
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $requests = $query->limit(500)->get();

        return response()->json([
            'success' => true,
            'data' => $requests
        ]);
    }

    /**
     * Lightweight summary for the in-app "new leave added" banner.
     * Returns the count of pending leave requests + the newest request id (the
     * banner uses latest_id as a high-water mark so it only alerts on genuinely
     * NEW leaves, never the existing backlog) + a short title for the newest one.
     * Audience is gated on the mobile side by the 'receive_leave_alerts' permission.
     */
    public function leaveAlertSummary(Request $request)
    {
        try {
            $agg = DB::table('t_req_master as r')
                ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
                ->where('c.category_code', 'leave')
                ->where('r.status', RequestModel::STATUS_PENDING)
                ->selectRaw('COUNT(*) as cnt, MAX(r.id) as latest_id')
                ->first();

            $count = (int) ($agg->cnt ?? 0);
            $latestId = (int) ($agg->latest_id ?? 0);
            $latestTitle = null;

            if ($latestId > 0) {
                $latest = DB::table('t_req_master as r')
                    ->leftJoin('t_sys_user as u', 'u.id', '=', 'r.requester_user_id')
                    ->where('r.id', $latestId)
                    ->select('u.fullname', 'r.leave_start_date', 'r.leave_end_date')
                    ->first();
                if ($latest) {
                    $name = $latest->fullname ?: 'An employee';
                    $range = '';
                    if ($latest->leave_start_date) {
                        $s = date('M j', strtotime($latest->leave_start_date));
                        $e = $latest->leave_end_date ? date('M j', strtotime($latest->leave_end_date)) : $s;
                        $range = ($s === $e) ? $s : "{$s} – {$e}";
                    }
                    $latestTitle = trim($name . ($range ? " · {$range}" : ''));
                }
            }

            return response()->json([
                'success' => true,
                'count' => $count,
                'latest_id' => $latestId,
                'latest_title' => $latestTitle,
            ]);
        } catch (\Exception $e) {
            \Log::error('leaveAlertSummary failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => true, 'count' => 0, 'latest_id' => 0, 'latest_title' => null]);
        }
    }

    /**
     * Show create request form
     */
    public function create()
    {
        $categories = RequestCategoryModel::getActiveCategories();

        // 💳 "Paid from". This form used to send nothing, so every expense filed
        // here landed on the Expense Fund at posting time regardless of where the
        // money really came from. Options come from PaymentSourceService, which
        // encodes the SAME rules store() enforces below — so the picker can never
        // offer an account this user's own submit would 403.
        //
        // Both books are rendered and the view filters by the chosen category's
        // business unit (see its data-bu), so picking "Khaas Expense" swaps the
        // accounts without a round trip. Only units this user can actually reach
        // are included — sourcesFor() returns nothing for the others.
        $paySvc     = app(\App\Services\FIN\PaymentSourceService::class);
        $paySources = [];
        foreach (\App\Models\FIN\BusinessUnitModel::where('is_active', 1)->pluck('id') as $buId) {
            foreach ($paySvc->sourcesFor(auth()->user(), (int) $buId) as $row) {
                $paySources[] = $row;
            }
        }
        $payBanks = $paySvc->banks();

        return view('pages.requests.create', compact('categories', 'paySources', 'payBanks'));
    }

    /**
     * Store new request
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:t_req_category,id',
            'requester_user_id' => 'nullable|exists:t_sys_user,id', // For admin/manager creating for others
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'amount' => 'nullable|numeric|min:0',
            'expense_category' => 'nullable|string|max:255', // For expense requests
            'expense_date' => 'nullable|date', // ⭐ For backdated expense entries
            // 🔧 Bike service capture (Jul-2026). Until now only the MOBILE form sent
            // these, so a web-filed Maintenance row had service_type = NULL and could
            // never reset the bike's service clock on approval. Whitelisted to the two
            // values the mobile picker offers; blank = not bike-related (unchanged).
            'service_type' => 'nullable|in:oil_change,repair',
            /**
             * 🧾 WHICH ALREADY-RECORDED SERVICE THIS BILL IS FOR (owner ruling, 3-Sep) —
             *    chosen from the requester's own un-billed readings, never matched by
             *    meter or date. Absent = a new service, i.e. today's behaviour.
             */
            'service_log_id' => 'nullable|integer',
            // The named type. When present the server derives service_type from its
            // bucket and ignores whatever the client sent for it.
            'maintenance_type_id' => 'nullable|integer',
            'meter_at_fill' => 'nullable|integer|min:0|max:9999999',
            // ⭐⭐ METERED (per-km) PETROL FILED ON A RIDER'S BEHALF (Aug-27 2026).
            //   This path could only ever create the FLAT cash kind, so when a manager
            //   filed a per-km rider's own-bike petrol it landed as an untethered cash
            //   claim: no attendance row, no distance, no rate — invisible to the
            //   one-per-day rule and to the rider's own screen, which then still showed
            //   the day as unclaimed. With these three it files the very same
            //   self-auditing row the rider's app files.
            'attendance_id'  => 'nullable|integer',
            'meter_distance' => 'nullable|numeric|min:0',
            'petrol_rate'    => 'nullable|numeric|min:0',
            // Which machine the claim is for — the manager picks it off the rider's day.
            'vehicle_id'     => 'nullable|integer',
            'payment_source_account_id' => 'nullable|exists:t_fin_accounts,id', // Payment source selection
            'receiving_account_id' => 'nullable|integer|exists:t_fin_online_receiving_accounts,id', // Which bank an ONLINE expense is paid from
            'business_unit_id' => 'nullable|exists:t_fin_business_units,id', // ⭐ Business unit for expense
            'leave_start_date' => 'nullable|date',
            'leave_end_date' => 'nullable|date|after_or_equal:leave_start_date',
            'leave_type' => 'nullable|string',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'attachment_image' => 'nullable|image|max:5120', // 5MB max for receipt/bill images
        ]);

        // Salaries are now handled exclusively by the Payroll screen. Block the legacy
        // "Staff Salaries" expense category so it can't create a duplicate salary expense
        // — even from a stale open form or a cached mobile app that still lists it.
        if (in_array(strtolower(trim((string) ($validated['expense_category'] ?? ''))), ['staff salaries', 'staff salary'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Staff salaries must be paid from the Payroll screen, not entered as an expense.'
            ], 422);
        }

        // A per-km claim anchored to a real attendance row + meter reading. Same
        // discriminator the rider's own endpoint uses, so the two agree by construction.
        $isMeteredPetrol = $request->filled('attendance_id')
            && (($validated['expense_category'] ?? null) === 'Petrol');

        // ⭐ Validate expense_date is within allowed backdate range
        if ($request->filled('expense_date')) {
            $loggedInUser = auth()->user();
            $expenseDate = \Carbon\Carbon::parse($validated['expense_date']);
            $today = \Carbon\Carbon::today();

            // Get user's max backdate days from their roles
            $maxBackdateDays = DB::table('t_sys_user_role as ur')
                ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                ->where('ur.user_id', $loggedInUser->id)
                ->max('r.expense_backdate_days') ?? 0;

            // Check if date is in the future
            if ($expenseDate->gt($today)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Expense date cannot be in the future'
                ], 422);
            }

            // ⭐ Metered petrol has its OWN window and is exempt from the per-role cap —
            //   exactly as on the rider's endpoint. The claim is anchored to an attendance
            //   row and its meter readings, so the generic cap (which can be 0) would
            //   otherwise stop a manager recording yesterday's kilometres for a rider,
            //   which is the entire purpose of the on-behalf path.
            if ($isMeteredPetrol) {
                // ⭐ A manager filing FOR someone gets the on-behalf window, not the
                //   rider's — the repairs this path exists for are found late. One
                //   definition, in FuelClaimRules::petrolWindowDays().
                $onBehalf = $request->filled('requester_user_id')
                    && (int) $validated['requester_user_id'] !== (int) $loggedInUser->id;
                $petrolWindow = (new \App\Services\Riders\FuelClaimRules())->petrolWindowDays($onBehalf);
                if ($expenseDate->lt($today->copy()->subDays($petrolWindow))) {
                    return response()->json([
                        'success' => false,
                        'message' => "Petrol requests from meter readings can only be raised for the last {$petrolWindow} days."
                    ], 422);
                }
            } else {
                // Check if date is too far in the past
                /**
                 * ⚠⚠ abs() — THE CAP HAS NEVER ACTUALLY FIRED (found 3-Sep).
                 *
                 *    On Carbon 2 `$today->diffInDays($past)` returned an absolute value; on
                 *    Carbon 3 (this codebase runs 3.8.4) it returns a SIGNED float, so a past
                 *    date gives −20.0 and `−20 > 14` is false. Every backdate was allowed, for
                 *    everyone, however old — measured: a 400-day-old expense passed a 14-day
                 *    window. The message even printed the negative number.
                 *
                 * ⚠ Fixing this TIGHTENS a control that has been open, so it can newly refuse
                 *   people who were filing old dates unchallenged. The two managers who record
                 *   maintenance are moved to 14 days in the same batch
                 *   (`fleet_backdate_windows_sep3_2026.sql`) so this does not take away work
                 *   they do today.
                 */
                $daysDiff = (int) abs($today->diffInDays($expenseDate));
                if ($daysDiff > $maxBackdateDays) {
                    return response()->json([
                        'success' => false,
                        'message' => "You can only backdate expenses up to {$maxBackdateDays} days. Selected date is {$daysDiff} days ago."
                    ], 422);
                }
            }
        }

        // Server-side payment source validation.
        // ⭐ Aug-2026: the account allow-list (t_fin_account_users) decides this now,
        // via PaymentSourceService — the SAME call that built the picker, so the form
        // can never offer something the submit rejects, and a crafted request cannot
        // reach an account the picker would not have shown. It also enforces the
        // business unit implicitly: candidates are scoped to the request's BU, so a
        // Khaas account can no longer fund an NF expense (that mis-book was possible
        // until today). Private accounts are excluded by `visibleTo` inside the
        // service; the explicit check below is only kept for its clearer message.
        if ($request->filled('payment_source_account_id')) {
            $loggedInUserForSource = auth()->user();
            $sourceAccount = \App\Models\FIN\AccountModel::find($validated['payment_source_account_id']);

            if ($sourceAccount && $sourceAccount->is_private) {
                $isTaimurRole = $loggedInUserForSource->roles()->whereRaw('LOWER(urole_name) = ?', ['taimur'])->exists();
                if (!$isTaimurRole) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This payment source is not available.'
                    ], 403);
                }
            }

            $catForSource = RequestCategoryModel::find($validated['category_id']);
            $sourcePurpose = ($catForSource && $catForSource->category_code === 'salary_advance')
                ? \App\Services\FIN\PaymentSourceService::PURPOSE_ADVANCE
                : \App\Services\FIN\PaymentSourceService::PURPOSE_EXPENSE;

            // A LEAVE request carries no money. The web form used to submit its
            // hidden pay-from select for leaves too; blocking those would be a
            // regression, so only money-moving categories are gated.
            $sourceIsMoneyCategory = $catForSource && (
                in_array($catForSource->category_code, ['expense', 'khaas_expense', 'salary_advance', 'qurbani'], true)
                || in_array($catForSource->form_type ?? null, ['expense', 'salary'], true)
            );

            if ($sourceIsMoneyCategory && !app(\App\Services\FIN\PaymentSourceService::class)->allows(
                    $loggedInUserForSource,
                    $validated['payment_source_account_id'],
                    $validated['business_unit_id'] ?? null,
                    $sourcePurpose
            )) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not set up to pay from this account. Ask Taimur or Shabib to add you to it (Ledger Hub → the account → "Who uses this account").'
                ], 403);
            }
            // When an EXPENSE or SALARY ADVANCE is paid from an ONLINE bank
            // account, the specific receiving bank is mandatory so per-bank
            // balances stay correct. Gated to money-moving categories: the web
            // form submits the (hidden) pay-from select for LEAVE requests too,
            // and leaves move no money — they must never be blocked.
            if ($sourceAccount && $sourceAccount->account_category === \App\Models\FIN\AccountModel::CATEGORY_BANK
                && !$request->filled('receiving_account_id')) {
                $catForBankCheck = RequestCategoryModel::find($validated['category_id']);
                $isMoneyCategory = $catForBankCheck && (
                    in_array($catForBankCheck->category_code, ['expense', 'khaas_expense', 'salary_advance'], true)
                    || in_array($catForBankCheck->form_type ?? null, ['expense', 'salary'], true)
                );
                if ($isMoneyCategory) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Select which bank this online payment is made from.'
                    ], 422);
                }
            }
        }

        // Prevent duplicate leave requests for overlapping dates
        if ($request->filled('leave_start_date') && $request->filled('leave_end_date')) {
            $leaveStart = $validated['leave_start_date'];
            $leaveEnd = $validated['leave_end_date'];
            $leaveUserId = $validated['requester_user_id'] ?? auth()->id();

            $overlapping = DB::table('t_req_master as r')
                ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
                ->where('c.category_code', 'leave')
                ->where('r.requester_user_id', $leaveUserId)
                ->whereNotIn('r.status', ['cancelled', 'rejected'])
                ->where('r.leave_start_date', '<=', $leaveEnd)
                ->where('r.leave_end_date', '>=', $leaveStart)
                ->select('r.leave_start_date', 'r.leave_end_date', 'r.request_number')
                ->first();

            if ($overlapping) {
                $existingStart = \Carbon\Carbon::parse($overlapping->leave_start_date)->format('M d');
                $existingEnd = \Carbon\Carbon::parse($overlapping->leave_end_date)->format('M d');
                return response()->json([
                    'success' => false,
                    'message' => "Leave already raised for {$existingStart} - {$existingEnd} ({$overlapping->request_number}). Cannot create overlapping leave request."
                ], 422);
            }
        }

        // ── Leave policy (single pool + same-day cap). Applies only to a rider applying
        //    for THEMSELVES; a manager creating a leave for someone else is unbound
        //    (that path is the Attendance apply-leave). Sets $policyLeaveType so the row
        //    records how it was applied (planned = advance, emergency = same-day).
        $policyLeaveType = null;
        if ($request->filled('leave_start_date') && $request->filled('leave_end_date')) {
            $catForPolicy = RequestCategoryModel::find($validated['category_id']);
            $actorId = (int) auth()->id();
            $forUserId = (int) ($validated['requester_user_id'] ?? $actorId);
            if ($catForPolicy && $catForPolicy->category_code === 'leave') {
                if ($forUserId === $actorId) {
                    $policyRes = (new \App\Services\HR\LeavePolicyService())->validateApplication(
                        $forUserId, $validated['leave_start_date'], $validated['leave_end_date'],
                        date('Y-m-d'), date('H:i')
                    );
                    if (!$policyRes['ok']) {
                        return response()->json(['success' => false, 'message' => $policyRes['message']], 422);
                    }
                    // planned = applied in advance; emergency = same-day (feeds the cap).
                    $policyLeaveType = $policyRes['is_sameday'] ? 'emergency' : 'planned';
                } else {
                    // Manager creating a leave FOR someone else: honor their explicit
                    // choice but whitelist it — anything other than 'emergency'
                    // (including legacy/junk values) is stored as 'planned'.
                    $posted = strtolower(trim((string) ($validated['leave_type'] ?? '')));
                    $policyLeaveType = $posted === 'emergency' ? 'emergency' : 'planned';
                    // 🚫 Owner ruling (Sep-3 2026): a balance may not go below zero, and a
                    // manager filing the leave on someone's behalf is not an exception —
                    // that was one of the two doors to a −1 balance.
                    try {
                        $dLeave = \Carbon\Carbon::parse($validated['leave_start_date'])
                            ->diffInDays(\Carbon\Carbon::parse($validated['leave_end_date'])) + 1;
                        $refusal = (new \App\Services\HR\LeavePolicyService())->overQuotaRefusal($forUserId, (float) $dLeave);
                        if ($refusal !== null) {
                            return response()->json(['success' => false, 'message' => $refusal], 422);
                        }
                    } catch (\Throwable $e) { /* a parse failure must not block the request */ }
                }
            }
        }

        try {
            DB::beginTransaction();

            $category = RequestCategoryModel::with('approvalConfig')->findOrFail($validated['category_id']);
            $loggedInUser = auth()->user();
            
            // Determine who the request is for
            $requesterId = $loggedInUser->id; // Default to logged-in user
            $createdByNote = '';
            
            // If requester_user_id is provided, verify permission
            if ($request->filled('requester_user_id') && $validated['requester_user_id']) {
                // Check if logged-in user has permission to create for others
                // Get ALL user's roles (not just the first one!)
                $userRoles = DB::table('t_sys_user_role as ur')
                    ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                    ->where('ur.user_id', $loggedInUser->id)
                    ->select('r.type', 'r.urole_name')
                    ->get();
                
                $hasPermission = false;
                
                // Check by role type OR role name
                $allowedTypes = ['admin', 'manager', 'supervisor'];
                $allowedNamePatterns = ['admin', 'manager', 'supervisor', 'super'];
                
                // Check each role
                foreach ($userRoles as $roleInfo) {
                    // Check type field
                    $typeMatch = in_array(strtolower($roleInfo->type ?? ''), $allowedTypes);
                    
                    // Check name field (case-insensitive, partial match)
                    $nameMatch = false;
                    $roleName = strtolower($roleInfo->urole_name ?? '');
                    foreach ($allowedNamePatterns as $pattern) {
                        if (strpos($roleName, $pattern) !== false) {
                            $nameMatch = true;
                            break;
                        }
                    }
                    
                    // If ANY role matches, user has permission
                    if ($typeMatch || $nameMatch) {
                        $hasPermission = true;
                        break;
                    }
                }
                
                // 🏍️ BIKE CLAIMS ONLY: `manage_bike_service` holders may file
                // petrol/maintenance for a rider even without an admin/manager
                // role — Qasim (role type 'user', name 'khaas') runs the Bikes
                // screen in Frozen mode and must be able to raise these. The
                // bypass is scoped to the two bike categories, so it grants no
                // general on-behalf power (leaves, advances, vendor bills still
                // need the roles above).
                if (!$hasPermission
                    && in_array($validated['expense_category'] ?? null, ['Petrol', 'Maintenance'], true)
                    && $loggedInUser->hasPermission('manage_bike_service')) {
                    $hasPermission = true;
                }

                if (!$hasPermission) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You do not have permission to create requests for other users'
                    ], 403);
                }
                
                // Valid - create for the specified user
                $requesterId = $validated['requester_user_id'];
                $requesterUser = \App\Models\SysAdmin\UserModel::find($requesterId);
                $createdByNote = "\n\n[Created by {$loggedInUser->fullname} on behalf of employee]";
            }

            // ⭐ Resolve the named maintenance type into the machine flag BEFORE the
            //    rules run — the meter requirement keys off service_type, so the
            //    rules must judge the type actually being stored, not whatever the
            //    client happened to send alongside it.
            $svcResolved = app(\App\Services\Riders\MaintenanceTypeService::class)->resolve(
                $validated['maintenance_type_id'] ?? null,
                $validated['service_type'] ?? null
            );

            // ⭐⭐ ONE RULE FOR "WHICH SERVICE?" (owner, 3-Sep) — see the same block in
            //    API\RiderController::createRequest. A Maintenance claim with an odometer must
            //    name a type when the list exists; the rule lives in ServiceRecordService.
            /**
             * 🧾 THE BILL IS FOR A SERVICE ALREADY RECORDED — see the twin block in
             *    API\RiderController::createRequest. The claim inherits that reading, so the
             *    manager never retypes a meter the rider already gave; and if the chosen
             *    service already carries a live bill this REFUSES, which is what stops the
             *    same receipt being filed twice from two different screens.
             * ⚠ Keyed to $requesterId — the service belongs to the rider the claim is FOR,
             *   never to the manager filing it.
             */
            $svcLink = app(\App\Services\Riders\ServiceRecordService::class)
                ->validateBillTarget($validated['service_log_id'] ?? null, (int) $requesterId);
            if (!$svcLink['ok']) {
                return response()->json(['success' => false, 'message' => $svcLink['message']], 422);
            }
            if (!empty($svcLink['inherit'])) {
                $validated['meter_at_fill']       = $svcLink['inherit']['meter'];
                $validated['expense_date']        = $svcLink['inherit']['date'];
                $validated['maintenance_type_id'] = $svcLink['inherit']['maintenance_type_id'];
                $svcResolved = app(\App\Services\Riders\MaintenanceTypeService::class)->resolve(
                    $svcLink['inherit']['maintenance_type_id'], $validated['service_type'] ?? null
                );
            }

            if (($validated['expense_category'] ?? null) === 'Maintenance') {
                $typeGate = app(\App\Services\Riders\ServiceRecordService::class)
                    // ⚠ "has a meter" = a positive reading, the same test the rider door applies.
                    ->requireTypeForClaim($svcResolved[1], (int) ($validated['meter_at_fill'] ?? 0) > 0);
                if (!$typeGate['ok']) {
                    return response()->json(['success' => false, 'message' => $typeGate['message']], 422);
                }
            }

            // ⭐ FUEL / MAINTENANCE RULES — the SAME service the rider's own app calls
            //    (API\RiderController::createRequest). This path used to enforce none
            //    of them, so a manager filing for a rider bypassed the company-bike
            //    meter requirement, the odometer sanity check and the double-tap
            //    block. Keyed to $requesterId — the bike and the odometer history
            //    belong to the rider the claim is FOR, never to the manager filing it.
            $fuelRules = (new \App\Services\Riders\FuelClaimRules())->check(
                (int) $requesterId,
                $validated['expense_category'] ?? null,
                [
                    'amount'        => $validated['amount'] ?? null,
                    'expense_date'  => $validated['expense_date'] ?? null,
                    'meter_at_fill' => $validated['meter_at_fill'] ?? null,
                    'service_type'  => $svcResolved[0],
                    // ⚠ The meter came FROM an accepted service record, so it is not being
                    //   asserted here — see FuelClaimRules. Ordinary claims are judged as before.
                    'meter_from_service_log' => !empty($svcLink['inherit']),
                    // ⭐ Aug-27 2026: this path CAN now create the self-auditing metered
                    //   row — a manager filing a rider's own-bike kilometres files the
                    //   same kind of claim the rider's own screen would, and it is judged
                    //   by the same rule (`checkMeteredPetrol`): the kilometres must be on
                    //   a machine the rider owns, and must match what is recorded.
                    'attendance_id'  => $request->filled('attendance_id') ? (int) $validated['attendance_id'] : null,
                    'meter_distance' => $validated['meter_distance'] ?? null,
                    'vehicle_id'     => $validated['vehicle_id'] ?? null,
                ],
                // WHO is filing. When a manager picks a rider, actor ≠ requester and
                // the own-bike "don't file your own petrol" rule steps aside — that
                // rule exists to stop a rider double-claiming, not to stop a manager
                // recording a genuine one for him.
                (int) $loggedInUser->id
            );
            if (!$fuelRules['ok']) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => $fuelRules['message']], 422);
            }
            // The machine the rules settled on — stamped onto the claim below, so the
            // money follows the same machine as the kilometres that earned it.
            $claimVehicleId = $fuelRules['vehicle_id'] ?? ($validated['vehicle_id'] ?? null);
            // Soft flag (2nd cash claim of the day, or a day the meter already paid
            // for) — carried into the description so the approver sees it.
            if (!empty($fuelRules['notice'])) {
                $createdByNote .= "\n[" . $fuelRules['notice'] . ']';
            }

            // Calculate leave days if it's a leave request
            $leaveDays = null;
            if ($request->filled('leave_start_date') && $request->filled('leave_end_date')) {
                $start = \Carbon\Carbon::parse($validated['leave_start_date']);
                $end = \Carbon\Carbon::parse($validated['leave_end_date']);
                $leaveDays = $end->diffInDays($start) + 1;
            }

            // Check if the logged-in user has L1 or L2 approval rights for auto-approval
            $userHasL1 = RoleApprovalLevelModel::userHasApprovalLevel($loggedInUser->id, 1);
            $userHasL2 = RoleApprovalLevelModel::userHasApprovalLevel($loggedInUser->id, 2);
            
            // Determine initial approval statuses based on user's approval rights
            $requiresL1 = $category->requiresLevel1();
            $requiresL2 = $category->requiresLevel2();
            
            // Auto-approve L1 if user has L1 rights.
            // (WHO approved is recorded in the t_req_approval rows written after create()
            //  — t_req_master has no level_*_approved_by/_at columns to hold it.)
            $level1Status = null;
            if ($requiresL1) {
                $level1Status = $userHasL1
                    ? RequestModel::APPROVAL_STATUS_APPROVED
                    : RequestModel::APPROVAL_STATUS_PENDING;
            }

            // Auto-approve L2 if user has L2 rights (and L1 is approved or not required)
            $level2Status = null;
            if ($requiresL2) {
                $level2Status = ($userHasL2 && (!$requiresL1 || $level1Status === RequestModel::APPROVAL_STATUS_APPROVED))
                    ? RequestModel::APPROVAL_STATUS_APPROVED
                    : RequestModel::APPROVAL_STATUS_PENDING;
            }
            
            // Determine overall status
            $overallStatus = RequestModel::STATUS_PENDING;
            if ((!$requiresL1 || $level1Status === RequestModel::APPROVAL_STATUS_APPROVED) &&
                (!$requiresL2 || $level2Status === RequestModel::APPROVAL_STATUS_APPROVED)) {
                $overallStatus = RequestModel::STATUS_APPROVED;
            }
            
            // Get assignees based on routing rules (if not auto-approved)
            $assignedToL1 = null;
            $assignedToL2 = null;
            if ($level1Status === RequestModel::APPROVAL_STATUS_PENDING) {
                $assignedToL1 = $this->getAssigneeForApproval(
                    'request_category',
                    $category->category_code,
                    1,
                    [
                        'payment_source_account_id' => $validated['payment_source_account_id'] ?? null,
                        'amount' => $validated['amount'] ?? null
                    ]
                );
            }
            if ($requiresL2 && $level2Status === RequestModel::APPROVAL_STATUS_PENDING) {
                $assignedToL2 = $this->getAssigneeForApproval(
                    'request_category',
                    $category->category_code,
                    2,
                    [
                        'payment_source_account_id' => $validated['payment_source_account_id'] ?? null,
                        'amount' => $validated['amount'] ?? null
                    ]
                );
            }
            
            // Store attachment image if provided (e.g. fuel bill photo)
            $attachments = null;
            if ($request->hasFile('attachment_image')) {
                $file = $request->file('attachment_image');
                $date = now();
                $filename = "req_{$requesterId}_{$date->format('Ymd_His')}.jpg";
                $path = "requests/attachments/{$date->format('Y')}/{$date->format('m')}/{$filename}";
                \Storage::disk('public')->put($path, file_get_contents($file));
                $attachments = [$path];
            }

            // Only keep the receiving bank when the source is an online bank
            // account — never tag a cash-funded expense with a bank.
            $expenseSourceAcct = isset($validated['payment_source_account_id'])
                ? \App\Models\FIN\AccountModel::find($validated['payment_source_account_id'])
                : null;
            $expenseBankId = ($expenseSourceAcct && $expenseSourceAcct->account_category === \App\Models\FIN\AccountModel::CATEGORY_BANK)
                ? ($validated['receiving_account_id'] ?? null)
                : null;

            // Create request
            $requestModel = RequestModel::create([
                'request_number' => RequestModel::generateRequestNumber(),
                'category_id' => $validated['category_id'],
                'requester_user_id' => $requesterId,
                'title' => $validated['title'],
                'description' => ($validated['description'] ?? '') . $createdByNote,
                'amount' => $validated['amount'] ?? null,
                'expense_category' => $validated['expense_category'] ?? null,
                'expense_date' => $validated['expense_date'] ?? now()->toDateString(),
                // 🔧 Only meaningful on a Maintenance row — never stamp a service type
                // onto an unrelated expense if a stale form submits the hidden fields.
                // ⭐ Aug-2026: when a named type is chosen, the SERVER derives
                // service_type from that type's bucket (see $svcResolved above) —
                // a stale or hand-made payload cannot pair "Oil Change" with
                // `repair` and dodge the meter requirement.
                'service_type' => (($validated['expense_category'] ?? null) === 'Maintenance')
                    ? $svcResolved[0] : null,
                'maintenance_type_id' => (($validated['expense_category'] ?? null) === 'Maintenance')
                    ? $svcResolved[1] : null,
                // ⚠ This used to be stored for Maintenance ONLY, which silently threw
                // away the odometer on every PETROL claim filed here — so a
                // manager-filed company-bike fill was invisible to km-since-last-fill
                // and to the Bikes tank economics. Both categories keep it now.
                'meter_at_fill' => in_array($validated['expense_category'] ?? null, ['Maintenance', 'Petrol'], true)
                    ? ($validated['meter_at_fill'] ?? null) : null,
                // ⭐ The per-km trio — written ONLY for a metered petrol claim, so a stale
                //   form sending stray fields on some other category cannot turn it into
                //   one. These three are what make the row self-auditing: they tie it to
                //   the day, the distance and the rate it was computed from.
                'attendance_id'  => $isMeteredPetrol ? (int) $validated['attendance_id'] : null,
                'meter_distance' => $isMeteredPetrol ? ($validated['meter_distance'] ?? null) : null,
                'petrol_rate'    => $isMeteredPetrol ? ($validated['petrol_rate'] ?? null) : null,
                'payment_source_account_id' => $validated['payment_source_account_id'] ?? null,
                'receiving_account_id' => $expenseBankId,
                'business_unit_id' => $validated['business_unit_id'] ?? 1,
                'leave_start_date' => $validated['leave_start_date'] ?? null,
                'leave_end_date' => $validated['leave_end_date'] ?? null,
                // Self-service leaves record how they were applied (casual/emergency);
                // manager/other paths keep whatever was passed.
                'leave_type' => $policyLeaveType ?? ($validated['leave_type'] ?? null),
                'leave_days' => $leaveDays,
                'attachments' => $attachments,
                'status' => $overallStatus,
                'priority' => $validated['priority'] ?? 'normal',
                'requires_level_1' => $requiresL1,
                'requires_level_2' => $requiresL2,
                'level_1_status' => $level1Status,
                'level_1_assigned_to' => $assignedToL1,
                'level_2_status' => $level2Status,
                'level_2_assigned_to' => $assignedToL2,
                // ⚠ `level_1_approved_by` / `level_1_approved_at` / `level_2_*` used to be
                // passed here. Those COLUMNS DO NOT EXIST on t_req_master — Eloquent
                // dropped all four silently, so this path has never recorded who
                // auto-approved a request. Removed, and replaced by the real
                // t_req_approval rows written just below, which is where every screen
                // reads "who approved" from anyway.
                'submitted_at' => now(),
                'created_by' => $loggedInUser->id,
            ]);

            // ⭐ A request approved AT CREATION never passes through processApproval(),
            // so it used to leave NO approval record at all: status said "approved"
            // while the approvals history was blank. Write the same rows processApproval
            // writes, so an auto-approved request reads like any other approval.
            foreach ([1 => $level1Status, 2 => $level2Status] as $lvl => $lvlStatus) {
                if ($lvlStatus !== RequestModel::APPROVAL_STATUS_APPROVED) {
                    continue;
                }
                \App\Models\Request\RequestApprovalModel::create([
                    'request_id' => $requestModel->id,
                    'approval_level' => $lvl,
                    'approver_user_id' => $loggedInUser->id,
                    'status' => RequestModel::APPROVAL_STATUS_APPROVED,
                    'comments' => 'Auto-approved on creation — the creator holds Level '
                        . $lvl . ' approval rights.',
                    'action_date' => now(),
                    'created_by' => $loggedInUser->id,
                ]);
            }

            // 🧾 Tie the bill to the service that was chosen for it, before anything else
            //    reads the pair — the approval hook below can post to the ledger immediately.
            if (!empty($validated['service_log_id'])) {
                app(\App\Services\Riders\ServiceRecordService::class)
                    ->attachBillToService((int) $validated['service_log_id'], (int) $requestModel->id);
            }

            // 🏍️ Record which machine this claim was for (Aug-2026). Keyed to the
            // REQUESTER, never the manager filing it — same rule FuelClaimRules
            // follows. Non-fatal and guarded; no-ops entirely before batch 13.
            (new \App\Services\Riders\VehicleResolver())->stampClaim(
                $requestModel->id, (int) $requesterId,
                $validated['expense_category'] ?? null,
                $validated['expense_date'] ?? null,
                isset($validated['attendance_id']) ? (int) $validated['attendance_id'] : null,
                // ⭐⭐ The manager PICKED the machine on the modal, or the rules resolved it
                //   from the rider's day. Either way somebody knows, and being told beats
                //   the date guess that put own-bike money on the van in the first place.
                $claimVehicleId ? (int) $claimVehicleId : null
            );

            // If auto-approved and it's an expense-type request, post to ledger.
            // Uses RequestModel::isExpenseTypeCategory() so any category with form_type='expense'
            // (qurbani, future custom expense types) is handled the same as the original expense/khaas_expense.
            if ($overallStatus === RequestModel::STATUS_APPROVED) {
                $categoryCode = $category->category_code;
                $requestModel->setRelation('category', $category); // ensure relation is loaded for helper

                // 🔧 A request approved AT CREATION (category with no approval levels)
                // never passes through processApproval(), so the bike service-clock
                // hook must fire here too. Non-fatal; no-op unless it is an approved
                // oil-change/general Maintenance row with a meter reading.
                \App\Services\Riders\BikeServiceClock::onRequestApproved($requestModel);

                if ($requestModel->isExpenseTypeCategory() && $requestModel->amount > 0) {
                    try {
                        $ledgerService = app(\App\Services\FIN\LedgerPostingService::class);
                        $ledgerService->postExpenseFromRequest($requestModel);
                        Log::info("Auto-approved expense posted to ledger", [
                            'request_id' => $requestModel->id,
                            'request_number' => $requestModel->request_number,
                            'category_code' => $categoryCode
                        ]);
                    } catch (\Exception $e) {
                        Log::error("Failed to post auto-approved expense to ledger: " . $e->getMessage(), [
                            'request_id' => $requestModel->id
                        ]);
                        // Don't fail the request creation, just log the error
                    }
                } elseif ($categoryCode === 'salary_advance' && $requestModel->amount > 0) {
                    try {
                        $requestModel->postSalaryAdvanceToLedger();
                        Log::info("Auto-approved salary advance posted to ledger", [
                            'request_id' => $requestModel->id,
                            'request_number' => $requestModel->request_number
                        ]);
                    } catch (\Exception $e) {
                        Log::error("Failed to post auto-approved salary advance to ledger: " . $e->getMessage(), [
                            'request_id' => $requestModel->id
                        ]);
                    }
                }
            }

            DB::commit();

            // ⭐ Alert management (permission: receive_leave_alerts) when a NEW leave
            // request is added and is pending review. Skips auto-approved leaves and
            // never notifies the creator about their own entry. Best-effort.
            if (($category->category_code ?? null) === 'leave'
                && $overallStatus === RequestModel::STATUS_PENDING) {
                try {
                    $applicantName = 'An employee';
                    if ($requesterId === $loggedInUser->id) {
                        $applicantName = $loggedInUser->fullname ?: 'An employee';
                    } elseif (isset($requesterUser) && $requesterUser) {
                        $applicantName = $requesterUser->fullname ?: 'An employee';
                    }
                    $range = null;
                    if ($requestModel->leave_start_date) {
                        $s = date('M j', strtotime($requestModel->leave_start_date));
                        $e2 = $requestModel->leave_end_date ? date('M j', strtotime($requestModel->leave_end_date)) : $s;
                        $range = ($s === $e2) ? $s : "{$s} – {$e2}";
                    }
                    app(\App\Services\FirebaseService::class)->notifyLeaveAdded(
                        (int) $requestModel->id,
                        $applicantName,
                        $range,
                        (int) $loggedInUser->id
                    );
                } catch (\Throwable $e) {
                    \Log::warning('Leave alert failed (web/store)', ['error' => $e->getMessage()]);
                }
            }

            $autoApprovedNote = ($userHasL1 || $userHasL2) && $overallStatus === RequestModel::STATUS_APPROVED
                ? ' (Auto-approved)' 
                : '';
            
            $message = $requesterId !== $loggedInUser->id 
                ? 'Request created successfully for ' . ($requesterUser->fullname ?? 'employee') . $autoApprovedNote
                : 'Request submitted successfully' . $autoApprovedNote;

            return response()->json([
                'success' => true,
                'message' => $message,
                'request_id' => $requestModel->id,
                'request_number' => $requestModel->request_number,
                'auto_approved' => $overallStatus === RequestModel::STATUS_APPROVED,
                // ⭐ "Is this expense's bank SMS still in the money box?"
                // Only answerable once a LEDGER row exists, which for an
                // expense happens on posting — immediate when the requester
                // holds the approval levels (measured: about half of them),
                // hours later otherwise. When it isn't there yet this is null
                // and the money-inbox sweep remains the safety net, exactly as
                // before. Additive and best-effort: it cannot affect the
                // request that was just created.
                'bank_sms_prompt' => $this->bankSmsPromptForRequest($requestModel->id),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Request creation error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create request: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show request details
     */
    public function show($id, \Illuminate\Http\Request $httpRequest)
    {
        // Store the previous URL (with filters) in session for smart back navigation
        if (url()->previous() !== url()->current()) {
            $previousUrl = url()->previous();
            // Only store if it's from the approvals index page
            if (strpos($previousUrl, route('approvals.index')) !== false || strpos($previousUrl, '/approvals') !== false) {
                session(['approvals_return_url' => $previousUrl]);
            }
        }
        
        $request = RequestModel::with([
            'category',
            'requester',
            'approvals.approver',
            'createdBy',
            'updatedBy',
            'paymentSourceAccount'
        ])->findOrFail($id);

        $user = auth()->user();
        
        // Check if user can approve this request
        $canApproveLevel1 = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1) 
            && $request->canBeApprovedByLevel(1);
        $canApproveLevel2 = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2) 
            && $request->canBeApprovedByLevel(2);

        if ($httpRequest->wantsJson() || $httpRequest->query('format') === 'json') {
            $l1 = $request->getLevel1Approver();
            $l2 = $request->getLevel2Approver();
            return response()->json([
                'success' => true,
                'request' => [
                    'id' => $request->id,
                    'request_number' => $request->request_number,
                    'status' => $request->status,
                    'priority' => $request->priority,
                    'category_name' => $request->category->category_name ?? '-',
                    'requester_name' => $request->requester->fullname ?? '-',
                    'created_by_name' => $request->createdBy->fullname ?? null,
                    'created_by_differs' => $request->created_by !== $request->requester_user_id,
                    'submitted_at' => $request->submitted_at?->format('Y-m-d H:i'),
                    'completed_at' => $request->completed_at?->format('Y-m-d H:i'),
                    'amount' => $request->amount,
                    'expense_category' => $request->expense_category,
                    'expense_date' => $request->expense_date?->format('Y-m-d'),
                    'payment_source' => $request->paymentSourceAccount?->account_name,
                    'title' => $request->title,
                    'description' => $request->description,
                    'rejection_reason' => $request->rejection_reason,
                    'leave_start_date' => $request->leave_start_date?->format('Y-m-d'),
                    'leave_end_date' => $request->leave_end_date?->format('Y-m-d'),
                    'leave_days' => $request->leave_days,
                    'leave_type' => $request->leave_type,
                    'attachments' => $request->attachments,
                    'requires_level_1' => $request->requires_level_1,
                    'requires_level_2' => $request->requires_level_2,
                    'level_1_status' => $request->level_1_status,
                    'level_2_status' => $request->level_2_status,
                    'l1_approver_name' => $l1?->approver?->fullname,
                    'l1_action_date' => $l1?->action_date?->format('Y-m-d H:i'),
                    'l1_comments' => $l1?->comments,
                    'l1_status' => $l1?->status,
                    'l2_approver_name' => $l2?->approver?->fullname,
                    'l2_action_date' => $l2?->action_date?->format('Y-m-d H:i'),
                    'l2_comments' => $l2?->comments,
                    'l2_status' => $l2?->status,
                    'can_approve_level_1' => $canApproveLevel1,
                    'can_approve_level_2' => $canApproveLevel2,
                ],
            ]);
        }

        return view('pages.requests.show', compact('request', 'canApproveLevel1', 'canApproveLevel2'));
    }

    /**
     * Update request (only for requester, and only if pending)
     */
    public function update(Request $request, $id)
    {
        $requestModel = RequestModel::findOrFail($id);
        $user = auth()->user();

        // Only requester can update, and only if pending
        if ($requestModel->requester_user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You can only update your own requests'
            ], 403);
        }

        if (!$requestModel->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update request that is not pending'
            ], 400);
        }

        // ⚠ A Storage take-out's expense is priced by the stock it drew, not typed. Editing its
        // amount here would post a different figure than left the shelf, and the stock account
        // and the shelf would never agree again (Sep-26 review). Storage has its own door.
        if (!empty($requestModel->supply_takeout_id)) {
            return response()->json([
                'success' => false,
                'message' => 'This expense comes from a Storage take-out. Undo it, or ask Taimur or Shabib '
                    . 'to edit or delete the take-out on the Storage page.',
            ], 422);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'amount' => 'nullable|numeric|min:0',
            'leave_start_date' => 'nullable|date',
            'leave_end_date' => 'nullable|date|after_or_equal:leave_start_date',
            'leave_type' => 'nullable|string',
            'priority' => 'nullable|in:low,normal,high,urgent'
        ]);

        try {
            // Calculate leave days if it's a leave request.
            // NB: Carbon 3 diffInDays is SIGNED — end->diff(start) goes BACKWARDS (negative for a
            // normal range), which used to store e.g. -1 for a 3-day leave. Use abs().
            $leaveDays = null;
            if ($request->filled('leave_start_date') && $request->filled('leave_end_date')) {
                $start = \Carbon\Carbon::parse($validated['leave_start_date']);
                $end = \Carbon\Carbon::parse($validated['leave_end_date']);
                $leaveDays = abs($start->diffInDays($end)) + 1;
            }

            // Whitelist leave_type on SELF-update, same rule as create: only emergency/planned.
            // 'half_day' is a MANAGER-ONLY grant (attendance page) — a rider must not be able to
            // edit his pending request into one (0.5 charge + late suppression on approval).
            $leaveType = null;
            if ($request->filled('leave_type')) {
                $posted = strtolower(trim((string) $validated['leave_type']));
                $leaveType = $posted === 'emergency' ? 'emergency' : 'planned';
            }

            $requestModel->update([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'amount' => $validated['amount'] ?? null,
                'leave_start_date' => $validated['leave_start_date'] ?? null,
                'leave_end_date' => $validated['leave_end_date'] ?? null,
                'leave_type' => $leaveType,
                'leave_days' => $leaveDays,
                'priority' => $validated['priority'] ?? $requestModel->priority,
                'updated_by' => $user->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Request updated successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Request update error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update request: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel request (only for requester, and only if pending)
     */
    public function cancel($id)
    {
        $request = RequestModel::findOrFail($id);
        $user = auth()->user();

        if ($request->requester_user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You can only cancel your own requests'
            ], 403);
        }

        if (!$request->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot cancel request that is not pending'
            ], 400);
        }

        try {
            $request->update([
                'status' => RequestModel::STATUS_CANCELLED,
                'updated_by' => $user->id
            ]);

            // ⭐ Storage (Supplies): cancelling a take-out is how a scanner undoes a
            // wrong scan — the packet goes back on the shelf. No-op otherwise.
            if ($request->supply_takeout_id) {
                app(\App\Services\FIN\SupplyStockService::class)->syncWithRequest($request);
            }

            return response()->json([
                'success' => true,
                'message' => $request->supply_takeout_id
                    ? 'Take-out cancelled — the packet is back in Storage.'
                    : 'Request cancelled successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Request cancellation error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel request: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get assignee based on routing rules
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
            if (isset($context['payment_source_account_id']) && $context['payment_source_account_id']) {
                $query->where(function($q) use ($context) {
                    $q->where('payment_source_account_id', $context['payment_source_account_id'])
                      ->orWhereNull('payment_source_account_id');
                });
            }
            
            if (isset($context['amount']) && $context['amount']) {
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
                return null; // No rule found, use default behavior
            }
            
            // Get primary assignee for this rule
            $assignee = DB::table('t_req_approval_rule_assignees')
                ->where('rule_id', $rule->id)
                ->where('is_primary', 1)
                ->orderBy('sequence_order', 'asc')
                ->first();
            
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
     * Find request by request number (for clickable links in ledger)
     */
    public function findByNumber($requestNumber)
    {
        try {
            $request = RequestModel::where('request_number', $requestNumber)->first();
            
            if (!$request) {
                return response()->json([
                    'success' => false,
                    'message' => 'Request not found'
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'request_id' => $request->id,
                'request_number' => $request->request_number
            ]);
            
        } catch (\Exception $e) {
            Log::error("Error finding request by number: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error finding request'
            ], 500);
        }
    }

    /**
     * "You just recorded this expense — is this the bank SMS for it?"
     *
     * An expense reaches the ledger only when it POSTS, so this asks the ledger
     * (via request_id) rather than assuming a row exists. No row yet — the
     * common case when approval is still pending — means no question, and the
     * money-inbox sweep stays the safety net exactly as before.
     *
     * Never throws: the request is already created and must not be endangered
     * by a question about it.
     */
    private function bankSmsPromptForRequest(int $requestId): ?array
    {
        try {
            if (!auth()->check() || !auth()->user()->hasMobilePermission('use_ai_assistant')) {
                return null;
            }
            $ledgerId = DB::table('t_fin_ledger')
                ->where('request_id', $requestId)
                ->where('transaction_type', 'expense')
                ->orderByDesc('id')
                ->value('id');
            if (!$ledgerId) {
                return null;
            }
            return app(\App\Services\Assistant\MoneyOutTagService::class)
                ->afterEntry((int) $ledgerId, (int) auth()->id());
        } catch (\Throwable $e) {
            Log::warning('[bankSmsPromptForRequest] ' . $e->getMessage(), ['request' => $requestId]);
            return null;
        }
    }
}

