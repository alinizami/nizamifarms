<?php

namespace App\Http\Controllers\Request;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Request\RequestCategoryModel;
use App\Models\Request\RequestCategoryApprovalConfigModel;
use App\Models\SysAdmin\RoleModel;
use App\Models\SysAdmin\RoleApprovalLevelModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RequestSettingsController extends Controller
{
    /**
     * Check if user has permission to manage request settings
     */
    private function checkPermission()
    {
        $user = auth()->user();
        if (!$user->hasPermission('manage_request_settings')) {
            abort(403, 'You do not have permission to manage request settings.');
        }
    }
    
    /**
     * Display request settings page
     */
    public function index()
    {
        // Check permission
        $user = auth()->user();
        if (!$user->hasPermission('manage_request_settings')) {
            return redirect()->route('requests.index')
                ->with('error', 'You do not have permission to manage request settings.');
        }
        
        $categories = RequestCategoryModel::with('approvalConfig')->active()->ordered()->get();
        $roles = RoleModel::where('is_active', 1)->get();
        
        // Get current approval level assignments
        $level1Roles = RoleApprovalLevelModel::with('role')->active()->level(1)->get();
        $level2Roles = RoleApprovalLevelModel::with('role')->active()->level(2)->get();

        // Load existing routing rules for request categories so the UI can show
        // per-category, per-level routing rows (L1/L2, per payment source).
        // We intentionally scope to area_type = 'request_category' only.
        $categoryCodeById = $categories->pluck('category_code', 'id'); // [id => code]
        $categoryIdByCode = $categoryCodeById->flip();                 // [code => id]

        $routingRulesByCategory = [];

        if ($categoryCodeById->isNotEmpty()) {
            // Map ledger transaction types back to request categories for display
            $ledgerAreaToCategoryCode = [
                'employee_deposit' => 'employee_deposit',
                'vendor_payment' => 'vendor_payment',
                'transfer' => 'account_transfer',
                'invoice' => 'invoice_approval', // L2 routing for online invoices
            ];

            $rules = DB::table('t_req_approval_rules as r')
                ->leftJoin('t_req_approval_rule_assignees as ra', 'ra.rule_id', '=', 'r.id')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'ra.user_id')
                ->leftJoin('t_fin_accounts as a', 'a.id', '=', 'r.payment_source_account_id')
                ->where('r.is_active', 1)
                ->where(function($q) use ($categoryCodeById, $ledgerAreaToCategoryCode) {
                    // Request-based rules (by category code)
                    $q->where(function($rq) use ($categoryCodeById) {
                        $rq->where('r.area_type', 'request_category')
                           ->whereIn('r.area_identifier', $categoryCodeById->values());
                    })
                    // Ledger-based rules that we map back to specific categories
                    ->orWhere(function($lq) use ($ledgerAreaToCategoryCode) {
                        $lq->where('r.area_type', 'ledger_transaction')
                           ->whereIn('r.area_identifier', array_keys($ledgerAreaToCategoryCode));
                    });
                })
                ->select(
                    'r.id',
                    'r.area_type',
                    'r.area_identifier',
                    'r.approval_level',
                    'r.payment_source_account_id',
                    'a.account_name',
                    'a.account_code',
                    'ra.user_id',
                    'ra.is_primary',
                    'ra.sequence_order',
                    'u.fullname as user_name'
                )
                ->orderBy('r.approval_level')
                ->orderBy('r.priority')
                ->orderBy('ra.sequence_order')
                ->get();

            foreach ($rules as $rule) {
                // Map back from area_identifier to category_id
                if ($rule->area_type === 'request_category') {
                    $categoryCode = $rule->area_identifier;
                } elseif ($rule->area_type === 'ledger_transaction') {
                    $categoryCode = $ledgerAreaToCategoryCode[$rule->area_identifier] ?? null;
                } else {
                    $categoryCode = null;
                }

                if (!$categoryCode) {
                    continue;
                }

                // $categoryIdByCode is already [category_code => id], so we can use it directly
                $categoryId = $categoryIdByCode[$categoryCode] ?? null;
                if (!$categoryId) {
                    continue;
                }

                $level = (int) $rule->approval_level;

                $routingRulesByCategory[$categoryId][$level][] = [
                    'rule_id' => $rule->id,
                    'area_type' => $rule->area_type,
                    'area_identifier' => $rule->area_identifier,
                    'user_id' => $rule->user_id,
                    'user_name' => $rule->user_name,
                    'payment_source_account_id' => $rule->payment_source_account_id,
                    'payment_source_account_name' => $rule->account_name,
                    'payment_source_account_code' => $rule->account_code,
                    'is_primary' => (bool) $rule->is_primary,
                    'sequence_order' => (int) $rule->sequence_order,
                ];
            }
        }
        
        $businessUnits = DB::table('t_fin_business_units')
            ->where('is_active', 1)
            ->orderBy('display_order')
            ->get(['id', 'code', 'name', 'short_code']);

        try {
            $expenseSubCategories = DB::table('t_fin_config')
                ->where('config_key', 'LIKE', 'EXPENSE_CATEGORY_%')
                ->orderBy('business_unit_id')
                ->orderBy('config_value')
                ->get(['id', 'config_key', 'config_value', 'description', 'business_unit_id', 'request_category_code']);
        } catch (\Exception $e) {
            $expenseSubCategories = DB::table('t_fin_config')
                ->where('config_key', 'LIKE', 'EXPENSE_CATEGORY_%')
                ->orderBy('business_unit_id')
                ->orderBy('config_value')
                ->get(['id', 'config_key', 'config_value', 'description', 'business_unit_id']);
        }

        return view('pages.requests.settings', compact(
            'categories',
            'roles',
            'level1Roles',
            'level2Roles',
            'routingRulesByCategory',
            'businessUnits',
            'expenseSubCategories'
        ));
    }

    /**
     * Update category approval configuration
     */
    public function updateCategoryConfig(Request $request, $categoryId)
    {
        $this->checkPermission();
        
        $validated = $request->validate([
            'requires_level_1' => 'required|boolean',
            'requires_level_2' => 'required|boolean',
            'auto_approve_threshold' => 'nullable|numeric|min:0'
        ]);

        try {
            $category = RequestCategoryModel::findOrFail($categoryId);
            $user = auth()->user();

            $config = RequestCategoryApprovalConfigModel::updateOrCreate(
                ['category_id' => $categoryId],
                [
                    'requires_level_1' => $validated['requires_level_1'],
                    'requires_level_2' => $validated['requires_level_2'],
                    'auto_approve_threshold' => $validated['auto_approve_threshold'] ?? null,
                    'updated_by' => $user->id
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Approval configuration updated successfully',
                'data' => $config
            ]);

        } catch (\Exception $e) {
            Log::error('Category config update error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update configuration: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign role to approval level
     */
    public function assignRoleToLevel(Request $request)
    {
        $this->checkPermission();
        
        $validated = $request->validate([
            'role_id' => 'required|exists:t_sys_role,id',
            'approval_level' => 'required|integer|in:1,2'
        ]);

        try {
            $user = auth()->user();

            // Check if this role already has this level
            $existing = RoleApprovalLevelModel::where('role_id', $validated['role_id'])
                ->where('approval_level', $validated['approval_level'])
                ->first();

            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'This role already has this approval level'
                ], 400);
            }

            $roleLevel = RoleApprovalLevelModel::create([
                'role_id' => $validated['role_id'],
                'approval_level' => $validated['approval_level'],
                'is_active' => 1,
                'created_by' => $user->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Role assigned to approval level successfully',
                'data' => $roleLevel->load('role')
            ]);

        } catch (\Exception $e) {
            Log::error('Role level assignment error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign role: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove role from approval level
     */
    public function removeRoleFromLevel($id)
    {
        $this->checkPermission();
        
        try {
            $roleLevel = RoleApprovalLevelModel::findOrFail($id);
            $roleLevel->delete();

            return response()->json([
                'success' => true,
                'message' => 'Role removed from approval level successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Role level removal error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove role: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get users with approval level (for debugging/info)
     */
    public function getUsersWithLevel($level)
    {
        $this->checkPermission();
        
        try {
            $users = RoleApprovalLevelModel::getUsersWithApprovalLevel($level);

            return response()->json([
                'success' => true,
                'data' => $users
            ]);

        } catch (\Exception $e) {
            Log::error('Get users with level error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get users: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update category details (name, description, etc.)
     */
    public function updateCategory(Request $request, $id)
    {
        $this->checkPermission();
        
        $validated = $request->validate([
            'category_name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'color_class' => 'nullable|string|max:50',
            'is_active' => 'required|boolean',
            'sequence_order' => 'required|integer|min:0',
            'show_in_expenses' => 'nullable|boolean',
            'expense_bu_type' => 'nullable|string|in:nf,khaas,all',
            'form_type' => 'nullable|string|in:expense,leave,salary,general',
            'mobile_permission_code' => 'nullable|string|max:100',
        ]);

        try {
            $category = RequestCategoryModel::findOrFail($id);
            $user = auth()->user();

            $updateData = [
                'category_name' => $validated['category_name'],
                'description' => $validated['description'] ?? null,
                'icon' => $validated['icon'] ?? null,
                'color_class' => $validated['color_class'] ?? null,
                'is_active' => $validated['is_active'],
                'sequence_order' => $validated['sequence_order'],
                'updated_by' => $user->id,
            ];

            if (array_key_exists('show_in_expenses', $validated)) {
                $updateData['show_in_expenses'] = $validated['show_in_expenses'] ?? false;
            }
            if (array_key_exists('expense_bu_type', $validated)) {
                $updateData['expense_bu_type'] = $validated['expense_bu_type'];
            }
            if (array_key_exists('form_type', $validated)) {
                $updateData['form_type'] = $validated['form_type'] ?? 'general';
            }
            if (array_key_exists('mobile_permission_code', $validated)) {
                $updateData['mobile_permission_code'] = $validated['mobile_permission_code'];
            }

            $category->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Category updated successfully',
                'data' => $category
            ]);

        } catch (\Exception $e) {
            Log::error('Category update error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update category: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create new category
     */
    public function createCategory(Request $request)
    {
        $this->checkPermission();
        
        $validated = $request->validate([
            'category_code' => 'required|string|max:50|unique:t_req_category,category_code',
            'category_name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'color_class' => 'nullable|string|max:50',
            'show_in_expenses' => 'nullable|boolean',
            'expense_bu_type' => 'nullable|string|in:nf,khaas,all',
            'form_type' => 'nullable|string|in:expense,leave,salary,general',
            'mobile_permission_code' => 'nullable|string|max:100',
        ]);

        try {
            DB::beginTransaction();
            
            $user = auth()->user();

            $maxSequence = RequestCategoryModel::max('sequence_order') ?? 0;

            $category = RequestCategoryModel::create([
                'category_code' => $validated['category_code'],
                'category_name' => $validated['category_name'],
                'description' => $validated['description'] ?? null,
                'icon' => $validated['icon'] ?? '',
                'color_class' => $validated['color_class'] ?? 'bg-gray-500',
                'is_active' => 1,
                'sequence_order' => $maxSequence + 1,
                'show_in_expenses' => $validated['show_in_expenses'] ?? false,
                'expense_bu_type' => $validated['expense_bu_type'] ?? null,
                'form_type' => $validated['form_type'] ?? 'general',
                'mobile_permission_code' => $validated['mobile_permission_code'] ?? null,
                'created_by' => $user->id
            ]);

            RequestCategoryApprovalConfigModel::create([
                'category_id' => $category->id,
                'requires_level_1' => 1,
                'requires_level_2' => 0,
                'created_by' => $user->id
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Category created successfully',
                'data' => $category->load('approvalConfig')
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Category creation error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create category: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save routing rules for a specific category (both L1 and L2).
     * This replaces any existing request_category rules for this category.
     *
     * Expected payload:
     * {
     *   rules: [
     *     { "level": 1, "user_id": 5, "payment_source_account_id": 10|null },
     *     { "level": 1, "user_id": 7, "payment_source_account_id": 12|null },
     *     { "level": 2, "user_id": 8, "payment_source_account_id": null }
     *   ]
     * }
     */
    public function saveCategoryRouting(Request $request, $categoryId)
    {
        $this->checkPermission();

        $validated = $request->validate([
            'rules' => 'nullable|array',
            'rules.*.level' => 'required|integer|in:1,2',
            'rules.*.user_id' => 'required|exists:t_sys_user,id',
            'rules.*.payment_source_account_id' => 'nullable|exists:t_fin_accounts,id',
        ]);

        try {
            DB::beginTransaction();

            /** @var RequestCategoryModel $category */
            $category = RequestCategoryModel::findOrFail($categoryId);
            $categoryCode = $category->category_code;
            $userId = auth()->id();

            // Determine corresponding ledger transaction type (if any) for this category.
            // This lets us treat request+ledger rules for a logical area as a single set
            // owned by this category in the UI.
            $ledgerAreaIdentifier = null;
            switch ($categoryCode) {
                case 'employee_deposit':
                    $ledgerAreaIdentifier = \App\Models\FIN\LedgerModel::TYPE_EMPLOYEE_DEPOSIT;
                    break;
                case 'vendor_payment':
                    $ledgerAreaIdentifier = \App\Models\FIN\LedgerModel::TYPE_VENDOR_PAYMENT;
                    break;
                case 'account_transfer':
                    $ledgerAreaIdentifier = \App\Models\FIN\LedgerModel::TYPE_TRANSFER;
                    break;
                case 'invoice_approval':
                    $ledgerAreaIdentifier = \App\Models\FIN\LedgerModel::TYPE_INVOICE;
                    break;
            }

            // Remove existing routing rules for this logical category (both request and ledger views)
            $existingRuleIds = DB::table('t_req_approval_rules')
                ->where(function($q) use ($categoryCode, $ledgerAreaIdentifier) {
                    $q->where(function($rq) use ($categoryCode) {
                        $rq->where('area_type', 'request_category')
                           ->where('area_identifier', $categoryCode);
                    });

                    if ($ledgerAreaIdentifier) {
                        $q->orWhere(function($lq) use ($ledgerAreaIdentifier) {
                            $lq->where('area_type', 'ledger_transaction')
                               ->where('area_identifier', $ledgerAreaIdentifier);
                        });
                    }
                })
                ->pluck('id');

            if ($existingRuleIds->isNotEmpty()) {
                DB::table('t_req_approval_rule_assignees')
                    ->whereIn('rule_id', $existingRuleIds)
                    ->delete();

                DB::table('t_req_approval_rules')
                    ->whereIn('id', $existingRuleIds)
                    ->delete();
            }

            // Insert new rules (if any)
            $priorityBase = 100;
            $i = 0;

            foreach ($validated['rules'] ?? [] as $ruleData) {
                // Decide area_type and identifier based on category and level
                $areaType = 'request_category';
                $areaIdentifier = $categoryCode;

                // Finance categories that map directly to ledger transactions
                if (in_array($categoryCode, ['employee_deposit', 'vendor_payment', 'account_transfer'])) {
                    $areaType = 'ledger_transaction';
                    switch ($categoryCode) {
                        case 'employee_deposit':
                            $areaIdentifier = \App\Models\FIN\LedgerModel::TYPE_EMPLOYEE_DEPOSIT;
                            break;
                        case 'vendor_payment':
                            $areaIdentifier = \App\Models\FIN\LedgerModel::TYPE_VENDOR_PAYMENT;
                            break;
                        case 'account_transfer':
                            $areaIdentifier = \App\Models\FIN\LedgerModel::TYPE_TRANSFER;
                            break;
                    }
                }

                // Invoice Approval: routing for both L1 and L2 is now ledger-based (TYPE_INVOICE).
                if ($categoryCode === 'invoice_approval') {
                    $areaType = 'ledger_transaction';
                    $areaIdentifier = \App\Models\FIN\LedgerModel::TYPE_INVOICE;
                }

                $ruleId = DB::table('t_req_approval_rules')->insertGetId([
                    'rule_name' => $category->category_name . ' - L' . $ruleData['level'] . ' Routing',
                    'area_type' => $areaType,
                    'area_identifier' => $areaIdentifier,
                    'approval_level' => $ruleData['level'],
                    'payment_source_account_id' => $ruleData['payment_source_account_id'] ?? null,
                    'payment_mode' => null,
                    'min_amount' => null,
                    'max_amount' => null,
                    'assignment_strategy' => 'single_primary',
                    'priority' => $priorityBase + $i,
                    'is_active' => 1,
                    'created_at' => now(),
                    'created_by' => $userId,
                ]);

                DB::table('t_req_approval_rule_assignees')->insert([
                    'rule_id' => $ruleId,
                    'user_id' => $ruleData['user_id'],
                    'is_primary' => 1,
                    'sequence_order' => 0,
                    'created_at' => now(),
                ]);

                $i++;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Routing rules saved successfully',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Save category routing error: ' . $e->getMessage(), [
                'category_id' => $categoryId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save routing rules: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all routing rules
     */
    public function getRoutingRules()
    {
        $this->checkPermission();
        
        try {
            $rules = DB::table('t_req_approval_rules as r')
                ->leftJoin('t_fin_accounts as a', 'r.payment_source_account_id', '=', 'a.id')
                ->where('r.is_active', 1)
                ->select(
                    'r.*',
                    'a.account_name as payment_source_account_name'
                )
                ->orderBy('r.priority')
                ->orderBy('r.created_at', 'desc')
                ->get();
            
            // Get assignees for each rule
            $rulesWithAssignees = $rules->map(function($rule) {
                $assignees = DB::table('t_req_approval_rule_assignees as ra')
                    ->join('t_sys_user as u', 'ra.user_id', '=', 'u.id')
                    ->where('ra.rule_id', $rule->id)
                    ->orderBy('ra.sequence_order')
                    ->select('ra.*', 'u.fullname as user_name', 'u.name as user_username')
                    ->get()
                    ->map(function($assignee) {
                        return [
                            'user_id' => $assignee->user_id,
                            'user_name' => $assignee->user_name ?? $assignee->user_username,
                            'is_primary' => $assignee->is_primary,
                            'sequence_order' => $assignee->sequence_order
                        ];
                    });
                
                // Format display names
                $areaTypeDisplay = match($rule->area_type) {
                    'request_category' => 'Request Category',
                    'ledger_transaction' => 'Ledger Transaction',
                    'ledger_adjustment' => 'Ledger Adjustment',
                    default => $rule->area_type
                };
                
                $areaIdentifierDisplay = $this->getAreaIdentifierDisplay($rule->area_type, $rule->area_identifier);
                
                return [
                    'id' => $rule->id,
                    'rule_name' => $rule->rule_name,
                    'area_type' => $rule->area_type,
                    'area_type_display' => $areaTypeDisplay,
                    'area_identifier' => $rule->area_identifier,
                    'area_identifier_display' => $areaIdentifierDisplay,
                    'approval_level' => $rule->approval_level,
                    'payment_source_account_id' => $rule->payment_source_account_id,
                    'payment_source_account_name' => $rule->payment_source_account_name,
                    'payment_mode' => $rule->payment_mode,
                    'min_amount' => $rule->min_amount,
                    'max_amount' => $rule->max_amount,
                    'priority' => $rule->priority,
                    'assignees' => $assignees
                ];
            });
            
            return response()->json([
                'success' => true,
                'data' => $rulesWithAssignees
            ]);
            
        } catch (\Exception $e) {
            Log::error('Get routing rules error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get routing rules: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get single routing rule
     */
    public function getRoutingRule($id)
    {
        $this->checkPermission();
        
        try {
            $rule = DB::table('t_req_approval_rules')->where('id', $id)->first();
            
            if (!$rule) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rule not found'
                ], 404);
            }
            
            $assignees = DB::table('t_req_approval_rule_assignees as ra')
                ->join('t_sys_user as u', 'ra.user_id', '=', 'u.id')
                ->where('ra.rule_id', $id)
                ->orderBy('ra.sequence_order')
                ->select('ra.*', 'u.fullname as user_name', 'u.name as user_username')
                ->get()
                ->map(function($assignee) {
                    return [
                        'user_id' => $assignee->user_id,
                        'user_name' => $assignee->user_name ?? $assignee->user_username,
                        'is_primary' => $assignee->is_primary,
                        'sequence_order' => $assignee->sequence_order
                    ];
                });
            
            return response()->json([
                'success' => true,
                'data' => array_merge((array)$rule, ['assignees' => $assignees])
            ]);
            
        } catch (\Exception $e) {
            Log::error('Get routing rule error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get routing rule: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Create routing rule
     */
    public function createRoutingRule(Request $request)
    {
        $this->checkPermission();
        
        $validated = $request->validate([
            'rule_name' => 'required|string|max:255',
            'area_type' => 'required|in:request_category,ledger_transaction,ledger_adjustment',
            'area_identifier' => 'required|string|max:100',
            'approval_level' => 'required|integer|in:1,2',
            'payment_source_account_id' => 'nullable|exists:t_fin_accounts,id',
            'payment_mode' => 'nullable|in:cash,online',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
            'priority' => 'nullable|integer|min:1',
            'assignees' => 'required|array|min:1',
            'assignees.*.user_id' => 'required|exists:t_sys_user,id',
            'assignees.*.is_primary' => 'required|boolean',
            'assignees.*.sequence_order' => 'required|integer|min:0'
        ]);
        
        try {
            DB::beginTransaction();
            
            $user = auth()->user();
            
            // Create rule
            $ruleId = DB::table('t_req_approval_rules')->insertGetId([
                'rule_name' => $validated['rule_name'],
                'area_type' => $validated['area_type'],
                'area_identifier' => $validated['area_identifier'],
                'approval_level' => $validated['approval_level'],
                'payment_source_account_id' => $validated['payment_source_account_id'] ?? null,
                'payment_mode' => $validated['payment_mode'] ?? null,
                'min_amount' => $validated['min_amount'] ?? null,
                'max_amount' => $validated['max_amount'] ?? null,
                'assignment_strategy' => 'single_primary',
                'priority' => $validated['priority'] ?? 100,
                'is_active' => 1,
                'created_at' => now(),
                'created_by' => $user->id
            ]);
            
            // Create assignees
            foreach ($validated['assignees'] as $assignee) {
                DB::table('t_req_approval_rule_assignees')->insert([
                    'rule_id' => $ruleId,
                    'user_id' => $assignee['user_id'],
                    'is_primary' => $assignee['is_primary'],
                    'sequence_order' => $assignee['sequence_order'],
                    'created_at' => now()
                ]);
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Routing rule created successfully',
                'rule_id' => $ruleId
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Create routing rule error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create routing rule: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update routing rule
     */
    public function updateRoutingRule(Request $request, $id)
    {
        $this->checkPermission();
        
        $validated = $request->validate([
            'rule_name' => 'required|string|max:255',
            'area_type' => 'required|in:request_category,ledger_transaction,ledger_adjustment',
            'area_identifier' => 'required|string|max:100',
            'approval_level' => 'required|integer|in:1,2',
            'payment_source_account_id' => 'nullable|exists:t_fin_accounts,id',
            'payment_mode' => 'nullable|in:cash,online',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
            'priority' => 'nullable|integer|min:1',
            'assignees' => 'required|array|min:1',
            'assignees.*.user_id' => 'required|exists:t_sys_user,id',
            'assignees.*.is_primary' => 'required|boolean',
            'assignees.*.sequence_order' => 'required|integer|min:0'
        ]);
        
        try {
            DB::beginTransaction();
            
            $user = auth()->user();
            
            // Update rule
            DB::table('t_req_approval_rules')
                ->where('id', $id)
                ->update([
                    'rule_name' => $validated['rule_name'],
                    'area_type' => $validated['area_type'],
                    'area_identifier' => $validated['area_identifier'],
                    'approval_level' => $validated['approval_level'],
                    'payment_source_account_id' => $validated['payment_source_account_id'] ?? null,
                    'payment_mode' => $validated['payment_mode'] ?? null,
                    'min_amount' => $validated['min_amount'] ?? null,
                    'max_amount' => $validated['max_amount'] ?? null,
                    'priority' => $validated['priority'] ?? 100,
                    'updated_at' => now(),
                    'updated_by' => $user->id
                ]);
            
            // Delete existing assignees
            DB::table('t_req_approval_rule_assignees')->where('rule_id', $id)->delete();
            
            // Create new assignees
            foreach ($validated['assignees'] as $assignee) {
                DB::table('t_req_approval_rule_assignees')->insert([
                    'rule_id' => $id,
                    'user_id' => $assignee['user_id'],
                    'is_primary' => $assignee['is_primary'],
                    'sequence_order' => $assignee['sequence_order'],
                    'created_at' => now()
                ]);
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Routing rule updated successfully'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Update routing rule error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update routing rule: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Delete routing rule
     */
    public function deleteRoutingRule($id)
    {
        $this->checkPermission();
        
        try {
            DB::beginTransaction();
            
            // Delete assignees first
            DB::table('t_req_approval_rule_assignees')->where('rule_id', $id)->delete();
            
            // Delete rule
            DB::table('t_req_approval_rules')->where('id', $id)->delete();
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Routing rule deleted successfully'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Delete routing rule error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete routing rule: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * List expense sub-categories from t_fin_config
     */
    public function getExpenseSubCategories(Request $request)
    {
        $this->checkPermission();

        $categories = DB::table('t_fin_config')
            ->where('config_key', 'LIKE', 'EXPENSE_CATEGORY_%')
            ->orderBy('business_unit_id')
            ->orderBy('config_value')
            ->get(['id', 'config_key', 'config_value', 'description', 'business_unit_id']);

        $businessUnits = DB::table('t_fin_business_units')
            ->where('is_active', 1)
            ->orderBy('display_order')
            ->get(['id', 'code', 'name', 'short_code']);

        return response()->json([
            'success' => true,
            'categories' => $categories,
            'business_units' => $businessUnits,
        ]);
    }

    /**
     * Rename an expense sub-category (= expense type).
     * ⭐ Oct-2026: goes through ExpenseTypeService — the same engine as the drawer next to every
     * Expense Type dropdown — so the account stays pinned and past expenses are relabelled.
     * Same gate as the drawer (Taimur's `create_expense_category` key).
     */
    public function updateExpenseSubCategory(Request $request, $id)
    {
        $this->checkPermission();
        if (!\App\Services\FIN\ExpenseTypeService::canManage()) {
            return response()->json(['success' => false, 'message' => 'Only Taimur can rename expense types.'], 403);
        }
        $validated = $request->validate([
            'config_value' => 'required|string|max:100',
        ]);
        $r = \App\Services\FIN\ExpenseTypeService::rename((int) $id, $validated['config_value'], $request->boolean('merge'));
        $status = $r['ok'] ? 200 : ($r['status'] ?? 422);
        unset($r['status']);
        $r['success'] = $r['ok'];
        unset($r['ok']);
        return response()->json($r, $status);
    }

    /**
     * "Delete" an expense sub-category.
     * ⭐ Oct-2026: never deletes any more — makes the type INACTIVE (no longer offered in any
     * dropdown; every past expense keeps it). Same engine and gate as the drawer.
     */
    public function deleteExpenseSubCategory($id)
    {
        $this->checkPermission();
        if (!\App\Services\FIN\ExpenseTypeService::canManage()) {
            return response()->json(['success' => false, 'message' => 'Only Taimur can switch off expense types.'], 403);
        }
        $r = \App\Services\FIN\ExpenseTypeService::setActive((int) $id, false);
        $status = $r['ok'] ? 200 : ($r['status'] ?? 422);
        unset($r['status']);
        $r['success'] = $r['ok'];
        unset($r['ok']);
        return response()->json($r, $status);
    }

    /**
     * Helper to get display name for area identifier
     */
    private function getAreaIdentifierDisplay($areaType, $identifier)
    {
        if ($areaType === 'request_category') {
            $category = RequestCategoryModel::where('category_code', $identifier)->first();
            return $category ? $category->category_name : $identifier;
        } elseif ($areaType === 'ledger_transaction') {
            $types = [
                'invoice' => 'Invoice',
                'expense' => 'Expense',
                'vendor_payment' => 'Vendor Payment',
                'employee_deposit' => 'Employee Deposit',
                'salary_payment' => 'Salary Payment',
                'transfer' => 'Account Transfer'
            ];
            return $types[$identifier] ?? $identifier;
        } elseif ($areaType === 'ledger_adjustment') {
            return 'Ledger Adjustment';
        }
        
        return $identifier;
    }
}

