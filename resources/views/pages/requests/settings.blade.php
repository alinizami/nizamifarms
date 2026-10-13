{{-- resources/views/pages/requests/settings.blade.php --}}

@extends('layouts.app')

@section('title', 'Requests Setup')

@section('content')

<style>
    /* Requests Setup (Phase 4) — scoped hex styles; Tailwind color utilities are
       purged in this app, so anything colored must be spelled out here or inline. */
    .nfrs-head { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
    .nfrs-ava { width: 44px; height: 44px; border-radius: 11px; background: #FFFBEB; color: #B45309; display: grid; place-items: center; font-weight: 800; font-size: 18px; flex: none; }
    .nfrs-title { font-size: 20px; font-weight: 700; margin: 0; color: #111827; }
    .nfrs-sub { font-size: 12.5px; color: #6B7280; margin: 2px 0 0; }
    .nfrs-moved { display: flex; gap: 12px; align-items: flex-start; padding: 14px 16px; border: 1px solid #BFDBFE; background: #EFF6FF; border-radius: 12px; font-size: 12.5px; color: #374151; }
    .nfrs-moved b { color: #1D4ED8; }
    .nfrs-rolechips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
    .nfrs-rolechip { display: inline-flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 650; padding: 4px 10px; border-radius: 20px; background: #fff; border: 1px solid #D1D5DB; color: #374151; text-decoration: none; }
    .nfrs-rolechip:hover { border-color: #93C5FD; color: #1D4ED8; }
    .nfrs-rolechip .lvl { font-size: 9px; font-weight: 800; letter-spacing: .04em; padding: 1px 5px; border-radius: 999px; }
    .nfrs-rolechip .lvl.l1 { background: #ECFDF5; color: #059669; }
    .nfrs-rolechip .lvl.l2 { background: #EFF6FF; color: #1D4ED8; }
</style>

<div class="kt-container-fixed">
    <div class="grid gap-5 lg:gap-7.5">

        <!-- Page Header -->
        <div class="flex items-center justify-between">
            <div class="nfrs-head">
                <div class="nfrs-ava">⚙</div>
                <div>
                    <h1 class="nfrs-title">Requests Setup</h1>
                    <p class="nfrs-sub">Request types, expense sub-categories &amp; approval routing</p>
                </div>
            </div>
            <a href="{{ route('requests.index') }}" class="kt-btn kt-btn-light">
                <i class="ki-filled ki-left"></i> Back to Requests
            </a>
        </div>

        {{-- Approval levels MOVED (Phase 4): who approves at L1/L2 is now edited on each
             role's Access page (Roles → permissions), which shows the same data as chips.
             This read-only summary keeps the at-a-glance answer to "who are my approvers?".
             Same table (t_sys_role_approval_level); no behaviour change. --}}
        <div class="nfrs-moved">
            <div>
                <b>Approval levels have moved.</b> Who can approve at Level 1 / Level 2 is now set on each
                role's Access page (<a href="{{ route('roles.index') }}" style="color:#1D4ED8;text-decoration:underline;">Roles</a> → permissions → "Approval authority" chips).
                Current approvers — click a role to manage it:
                <div class="nfrs-rolechips">
                    @forelse($level1Roles as $roleLevel)
                        <a class="nfrs-rolechip" href="{{ route('roles.permissions.manage', $roleLevel->role_id) }}">
                            <span class="lvl l1">L1</span> {{ $roleLevel->role->urole_name }}
                        </a>
                    @empty
                        <span class="nfrs-rolechip"><span class="lvl l1">L1</span> none assigned</span>
                    @endforelse
                    @forelse($level2Roles as $roleLevel)
                        <a class="nfrs-rolechip" href="{{ route('roles.permissions.manage', $roleLevel->role_id) }}">
                            <span class="lvl l2">L2</span> {{ $roleLevel->role->urole_name }}
                        </a>
                    @empty
                        <span class="nfrs-rolechip"><span class="lvl l2">L2</span> none assigned</span>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- ======= REQUEST TYPE MANAGEMENT ======= -->
        <div class="kt-card">
            <div class="kt-card-header" style="display:flex;align-items:center;justify-content:space-between;">
                <h3 class="kt-card-title">Request Types</h3>
                <button onclick="showCreateTypeModal()" class="kt-btn kt-btn-sm kt-btn-primary">
                    <i class="ki-filled ki-plus"></i> Add Request Type
                </button>
            </div>
            <div class="kt-card-body">
                @php
                    // Two kinds of category share this table: forms people submit vs
                    // ledger buckets money approvals flow through. Split for clarity.
                    $ledgerCodes = ['employee_deposit', 'vendor_payment', 'account_transfer', 'invoice_approval', 'invoice_adjustment'];
                    $formCats   = $categories->filter(fn($c) => !in_array($c->category_code, $ledgerCodes));
                    $ledgerCats = $categories->filter(fn($c) =>  in_array($c->category_code, $ledgerCodes));
                @endphp

                {{-- Request forms: what people actually submit from the app --}}
                <h4 class="font-semibold text-sm text-gray-800 mb-1">Request forms</h4>
                <p class="text-sm text-gray-600 mb-3">Types people submit from the app. "In Expenses" controls whether a type appears on the web Expense Management screen. A mobile permission code hides a type <strong>in the mobile app only</strong> — the web Expense screen is controlled purely by "In Expenses".</p>
                <div class="overflow-x-auto mb-7">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 border-b">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">In Expenses</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Form Type</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Active</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($formCats as $cat)
                            <tr class="border-b hover:bg-gray-50" id="typeRow-{{ $cat->id }}"
                                data-id="{{ $cat->id }}" data-code="{{ $cat->category_code }}" data-name="{{ $cat->category_name }}"
                                data-desc="{{ $cat->description }}" data-icon="{{ $cat->icon }}" data-show="{{ $cat->show_in_expenses ? '1' : '0' }}"
                                data-bu="{{ $cat->expense_bu_type ?? '' }}" data-form="{{ $cat->form_type ?? 'general' }}"
                                data-perm="{{ $cat->mobile_permission_code ?? '' }}" data-active="{{ $cat->is_active ? '1' : '0' }}" data-seq="{{ $cat->sequence_order }}">
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-2">
                                        <span class="text-lg">{{ $cat->icon ?: '📋' }}</span>
                                        <div>
                                            <div class="font-medium">{{ $cat->category_name }}@if($cat->mobile_permission_code)<span title="Hidden in the mobile app for roles without this permission" style="margin-left:5px;font-size:9px;font-weight:700;background:#F0FDFA;color:#0F766E;border-radius:20px;padding:1px 6px;">📱 {{ $cat->mobile_permission_code }}</span>@endif</div>
                                            <div class="font-mono text-xs text-gray-400">{{ $cat->category_code }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-xs text-gray-500">{{ Str::limit($cat->description, 44) }}</td>
                                <td class="px-3 py-2 text-center">
                                    <input type="checkbox" class="kt-checkbox" {{ $cat->show_in_expenses ? 'checked' : '' }}
                                           onchange="quickToggle({{ $cat->id }}, 'show_in_expenses', this.checked)" title="Toggle expense management visibility">
                                </td>
                                <td class="px-3 py-2 text-center text-xs">{{ $cat->form_type ?? 'general' }}</td>
                                <td class="px-3 py-2 text-center">{!! $cat->is_active ? '<span class="text-green-600">✓</span>' : '<span class="text-red-400">✗</span>' !!}</td>
                                <td class="px-3 py-2 text-center"><button onclick="editTypeRow({{ $cat->id }})" class="kt-btn kt-btn-xs kt-btn-light">Edit</button></td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Ledger approval categories: system money approvals, not user forms --}}
                <h4 class="font-semibold text-sm text-gray-800 mb-1">Ledger approval categories</h4>
                <p class="text-sm text-gray-600 mb-3">System buckets that money approvals flow through (deposits, vendor payments, transfers, invoices). Nobody submits these as a request — they only carry the approval settings configured below. The In-Expenses / Form / BU options don't apply here.</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 border-b">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Active</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($ledgerCats as $cat)
                            <tr class="border-b hover:bg-gray-50" id="typeRow-{{ $cat->id }}"
                                data-id="{{ $cat->id }}" data-code="{{ $cat->category_code }}" data-name="{{ $cat->category_name }}"
                                data-desc="{{ $cat->description }}" data-icon="{{ $cat->icon }}" data-show="{{ $cat->show_in_expenses ? '1' : '0' }}"
                                data-bu="{{ $cat->expense_bu_type ?? '' }}" data-form="{{ $cat->form_type ?? 'general' }}"
                                data-perm="{{ $cat->mobile_permission_code ?? '' }}" data-active="{{ $cat->is_active ? '1' : '0' }}" data-seq="{{ $cat->sequence_order }}">
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-2">
                                        <span class="text-lg">{{ $cat->icon ?: '📒' }}</span>
                                        <div>
                                            <div class="font-medium">{{ $cat->category_name }}</div>
                                            <div class="font-mono text-xs text-gray-400">{{ $cat->category_code }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-xs text-gray-500">{{ Str::limit($cat->description, 52) }}</td>
                                <td class="px-3 py-2 text-center">{!! $cat->is_active ? '<span class="text-green-600">✓</span>' : '<span class="text-red-400">✗</span>' !!}</td>
                                <td class="px-3 py-2 text-center"><button onclick="editTypeRow({{ $cat->id }})" class="kt-btn kt-btn-xs kt-btn-light">Edit</button></td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ======= EXPENSE TYPES (grouped by request type) — Oct-2026: one engine + the shared drawer ======= -->
        @php $nfetCanManage = \App\Services\FIN\ExpenseTypeService::canManage(); @endphp
        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Expense Types (Sub-Categories)</h3>
            </div>
            <div class="kt-card-body">
                <p class="text-sm text-gray-600 mb-4">The Expense Type list on every expense form (web and phone), in the order shown here. Expense Reimbursement and Khaas Expense share the grey types; amber types belong to that request type only.
                    @if($nfetCanManage) Use <b>✎ Manage types</b> to rename, turn off, reorder or group them — the same panel opens from the ✎ button next to the Expense Type dropdown. @else Renaming, turning off and ordering is done by Taimur. @endif
                </p>

                @php
                    $expenseTypes = $categories->filter(function($c) {
                        $ft = $c->form_type ?? (in_array($c->category_code, ['expense', 'khaas_expense']) ? 'expense' : 'other');
                        return $ft === 'expense';
                    });
                @endphp

                @foreach($expenseTypes as $etype)
                @php
                    $etBuId = null;
                    $etBuType = $etype->expense_bu_type ?? ($etype->category_code === 'khaas_expense' ? 'khaas' : 'nf');
                    foreach ($businessUnits as $bu) {
                        if (($etBuType === 'nf' && $bu->id == 1) || ($etBuType === 'khaas' && $bu->id != 1)) {
                            $etBuId = $bu->id;
                            break;
                        }
                    }
                    if (!$etBuId && $businessUnits->count() > 0) $etBuId = $businessUnits->first()->id;
                    $etOptions = \App\Services\FIN\ExpenseTypeService::options($etBuId, $etype->category_code);
                    $etHasGroups = collect($etOptions)->contains(fn ($o) => !empty($o['group']));
                    $etScopeById = collect(\App\Services\FIN\ExpenseTypeService::rows())->pluck('rcc', 'id');
                    $etLastGroup = '__none__';
                @endphp
                <div class="mb-5 p-4 border border-gray-200 rounded-lg bg-gray-50">
                    <h4 class="font-semibold text-sm text-gray-800 mb-2 flex items-center gap-2">
                        <span class="text-lg">{{ $etype->icon ?: '📋' }}</span>
                        {{ $etype->category_name }}
                        <span class="text-xs text-gray-400 font-normal">({{ $etBuType === 'khaas' ? 'Khaas' : 'NF' }} BU &middot; {{ count($etOptions) }} active)</span>
                        @if($nfetCanManage)
                            <button type="button" class="nfet-trigger" onclick="NFExpenseTypes.open({bu: {{ (int) $etBuId }}, rcc: @js($etype->category_code), label: @js($etype->category_name)})">✎ Manage types</button>
                        @endif
                    </h4>

                    @if(count($etOptions) > 0)
                    <div class="flex flex-wrap gap-2 mb-2 items-center">
                        @foreach($etOptions as $sc)
                            @if($etHasGroups && ($sc['group'] ?? 'Other') !== $etLastGroup)
                                @php $etLastGroup = $sc['group'] ?? 'Other'; @endphp
                                <span class="w-full text-[11px] font-bold uppercase tracking-wide text-gray-500 mt-1">{{ $sc['group'] ?? 'Other' }}</span>
                            @endif
                            <span class="inline-flex items-center gap-1 px-3 py-1 {{ ($etScopeById[$sc['id']] ?? null) ? 'bg-amber-50 border-amber-200' : 'bg-gray-100 border-gray-200' }} border rounded-full text-sm">
                                {{ $sc['name'] }}@if($sc['locked']) <span title="Used by bike, fuel and workshop records">🔒</span>@endif
                            </span>
                        @endforeach
                    </div>
                    @else
                    <p class="text-sm text-gray-400 italic mb-2">No types yet. Add some below.</p>
                    @endif

                    <div class="mt-2 flex items-center gap-2">
                        <input type="text" id="newSubCat-{{ $etype->category_code }}" placeholder="New expense type name..." class="kt-input kt-input-sm" style="max-width:220px;">
                        <button onclick="addSubCategoryForType({{ $etBuId }}, '{{ $etype->category_code }}')" class="kt-btn kt-btn-xs kt-btn-primary">+ Add</button>
                    </div>
                </div>
                @endforeach
                @include('fin.expense-types._manager')
                <script>
                    // Chips here are server-rendered: reload once after the drawer changed something.
                    window.addEventListener('nfet:closed', function (e) { if (e.detail && e.detail.changed) location.reload(); });
                </script>
            </div>
        </div>

        <!-- Category Configuration with Integrated Routing -->
        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Category Approval Configuration & Routing</h3>
            </div>
            
            <div class="kt-card-body">
                <div class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded">
                    <p class="text-sm text-gray-700 mb-2">
                        <strong>📍 How Approval Routing Works:</strong>
                    </p>
                    <p class="text-xs text-gray-600">
                        Configure which users should approve each category. You can set specific assignees for L1 and L2, or leave empty to use role-based approval (any user with L1/L2 role can approve).
                    </p>
                </div>

                <div class="space-y-4">
                    @foreach($categories as $category)
                    @php
                        $categoryRouting = $routingRulesByCategory[$category->id] ?? [];
                        $l1Routing = $categoryRouting[1] ?? [];
                        $l2Routing = $categoryRouting[2] ?? [];

                        // Detect whether this category has request-based routing,
                        // ledger-based routing, or both (for display badges).
                        // 1) Look at actual rules (area_type).
                        $hasRequestRouting = false;
                        $hasLedgerRouting = false;

                        foreach ([$l1Routing, $l2Routing] as $levelRows) {
                            foreach ($levelRows as $row) {
                                if (isset($row['area_type'])) {
                                    if ($row['area_type'] === 'request_category') {
                                        $hasRequestRouting = true;
                                    } elseif ($row['area_type'] === 'ledger_transaction') {
                                        $hasLedgerRouting = true;
                                    }
                                }
                            }
                        }

                        // 2) Apply sensible defaults based on category code so the flow
                        //    badge is still meaningful even when there are no explicit rules.
                        $code = $category->category_code;

                        // Pure request-driven categories
                        if (in_array($code, ['leave', 'expense', 'salary_advance'])) {
                            $hasRequestRouting = true;
                        }

                        // Pure ledger-driven categories
                        if (in_array($code, ['employee_deposit', 'vendor_payment', 'account_transfer', 'invoice_approval', 'invoice_adjustment'])) {
                            $hasLedgerRouting = true;
                        }
                    @endphp
                    <div class="border rounded-lg p-4 bg-white hover:bg-gray-50">
                        <div class="flex items-start justify-between mb-3">
                            <div>
                                <h4 class="font-semibold text-lg">{{ $category->category_name }}</h4>
                                <p class="text-sm text-gray-600">{{ $category->description }}</p>

                                @if($hasRequestRouting || $hasLedgerRouting)
                                    <div class="mt-1 flex flex-wrap gap-1 text-xs">
                                        @if($hasRequestRouting)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-green-100 text-green-800 border border-green-200" title="Approvals start as a request (e.g., Leave, Expense, Salary Advance)">
                                            Request flow
                                        </span>
                                        @endif
                                        @if($hasLedgerRouting)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-800 border border-indigo-200" title="Approvals happen directly on ledger entries (e.g., Employee Deposit, Vendor Payment, Account Transfer, Online Invoice)">
                                            Ledger flow
                                        </span>
                                        @endif
                                    </div>
                                @endif
                            </div>
                            <button onclick="toggleCategoryDetails({{ $category->id }})" class="kt-btn kt-btn-sm kt-btn-light">
                                <i class="ki-filled ki-down" id="icon-{{ $category->id }}"></i>
                            </button>
                        </div>
                        
                        <!-- How many approval steps this category needs (saves immediately) -->
                        <div class="flex items-center gap-5 text-sm flex-wrap">
                            <span class="text-xs text-gray-500 uppercase font-semibold" style="letter-spacing:.04em;">Approval steps needed</span>
                            <label class="flex items-center gap-1 cursor-pointer">
                                <input type="checkbox" class="kt-checkbox"
                                       data-category-id="{{ $category->id }}" data-field="requires_level_1"
                                       onchange="saveRequires({{ $category->id }})"
                                       {{ $category->approvalConfig && $category->approvalConfig->requires_level_1 ? 'checked' : '' }}>
                                Needs Level-1 approval
                            </label>
                            <label class="flex items-center gap-1 cursor-pointer">
                                <input type="checkbox" class="kt-checkbox"
                                       data-category-id="{{ $category->id }}" data-field="requires_level_2"
                                       onchange="saveRequires({{ $category->id }})"
                                       {{ $category->approvalConfig && $category->approvalConfig->requires_level_2 ? 'checked' : '' }}>
                                Needs Level-2 approval
                            </label>
                            <span id="reqsaved-{{ $category->id }}" style="display:none;font-size:11px;color:#059669;font-weight:600;">✓ saved</span>
                        </div>
                        
                        <!-- Detailed Configuration (Hidden by default) -->
                        <div id="details-{{ $category->id }}" class="hidden mt-4 pt-4 border-t">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <!-- Level 1 Routing -->
                                <div class="border rounded p-3 bg-blue-50">
                                    <h5 class="font-medium mb-2 flex items-center gap-2">
                                        <span class="w-6 h-6 rounded-full bg-blue-500 text-white flex items-center justify-center text-xs">L1</span>
                                        Level 1 Approvers
                                    </h5>
                                    <div id="l1-rules-{{ $category->id }}" class="space-y-2">
                                        @php
                                            $l1Rows = count($l1Routing) ? $l1Routing : [['user_id' => null, 'payment_source_account_id' => null]];
                                        @endphp
                                        @foreach($l1Rows as $row)
                                        <div class="flex items-center gap-2 routing-row" data-level="1">
                                            <div class="flex-1">
                                                <label class="text-xs text-gray-600">Assign Specific User (Optional)</label>
                                                <select class="kt-select kt-select-sm w-full" data-role="user">
                                                    <option value="">Any L1 user (role-based)</option>
                                                    @foreach(\App\Models\SysAdmin\UserModel::where('is_active', 1)->orderBy('fullname')->get() as $user)
                                                    <option value="{{ $user->id }}"
                                                        {{ isset($row['user_id']) && (int)$row['user_id'] === (int)$user->id ? 'selected' : '' }}>
                                                        {{ $user->fullname ?? $user->name }}
                                                    </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="flex-1">
                                                <label class="text-xs text-gray-600">Payment Source (Optional)</label>
                                                <select class="kt-select kt-select-sm w-full" data-role="payment_source">
                                                    <option value="">Any payment source</option>
                                                    @foreach(\App\Models\FIN\AccountModel::where('is_active', 1)->whereIn('account_category', ['cash', 'bank'])->orderBy('account_name')->get() as $account)
                                                    <option value="{{ $account->id }}"
                                                        {{ isset($row['payment_source_account_id']) && (int)$row['payment_source_account_id'] === (int)$account->id ? 'selected' : '' }}>
                                                        {{ $account->account_name }}
                                                    </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <button type="button"
                                                    class="kt-btn kt-btn-sm kt-btn-danger mt-6"
                                                    onclick="removeRoutingRow(this)">
                                                <i class="ki-filled ki-trash"></i>
                                            </button>
                                        </div>
                                        @endforeach
                                    </div>
                                    <button type="button"
                                            class="kt-btn kt-btn-xs kt-btn-light mt-2"
                                            onclick="addRoutingRow({{ $category->id }}, 1)">
                                        <i class="ki-filled ki-plus"></i> Add L1 Rule
                                    </button>
                                </div>
                                
                                <!-- Level 2 Routing -->
                                <div class="border rounded p-3 bg-purple-50">
                                    <h5 class="font-medium mb-2 flex items-center gap-2">
                                        <span class="w-6 h-6 rounded-full bg-purple-500 text-white flex items-center justify-center text-xs">L2</span>
                                        Level 2 Approvers
                                    </h5>
                                    <div id="l2-rules-{{ $category->id }}" class="space-y-2">
                                        @php
                                            $l2Rows = count($l2Routing) ? $l2Routing : [['user_id' => null, 'payment_source_account_id' => null]];
                                        @endphp
                                        @foreach($l2Rows as $row)
                                        <div class="flex items-center gap-2 routing-row" data-level="2">
                                            <div class="flex-1">
                                                <label class="text-xs text-gray-600">Assign Specific User (Optional)</label>
                                                <select class="kt-select kt-select-sm w-full" data-role="user">
                                                    <option value="">Any L2 user (role-based)</option>
                                                    @foreach(\App\Models\SysAdmin\UserModel::where('is_active', 1)->orderBy('fullname')->get() as $user)
                                                    <option value="{{ $user->id }}"
                                                        {{ isset($row['user_id']) && (int)$row['user_id'] === (int)$user->id ? 'selected' : '' }}>
                                                        {{ $user->fullname ?? $user->name }}
                                                    </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="flex-1">
                                                <label class="text-xs text-gray-600">Payment Source (Optional)</label>
                                                <select class="kt-select kt-select-sm w-full" data-role="payment_source">
                                                    <option value="">Any payment source</option>
                                                    @foreach(\App\Models\FIN\AccountModel::where('is_active', 1)->whereIn('account_category', ['cash', 'bank'])->orderBy('account_name')->get() as $account)
                                                    <option value="{{ $account->id }}"
                                                        {{ isset($row['payment_source_account_id']) && (int)$row['payment_source_account_id'] === (int)$account->id ? 'selected' : '' }}>
                                                        {{ $account->account_name }}
                                                    </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <button type="button"
                                                    class="kt-btn kt-btn-sm kt-btn-danger mt-6"
                                                    onclick="removeRoutingRow(this)">
                                                <i class="ki-filled ki-trash"></i>
                                            </button>
                                        </div>
                                        @endforeach
                                    </div>
                                    <button type="button"
                                            class="kt-btn kt-btn-xs kt-btn-light mt-2"
                                            onclick="addRoutingRow({{ $category->id }}, 2)">
                                        <i class="ki-filled ki-plus"></i> Add L2 Rule
                                    </button>
                                </div>
                            </div>
                            
                            <div class="mt-3 flex items-center justify-end gap-3">
                                <span class="text-xs" style="color:#B45309;">⚠ Saving replaces this category's existing routing rows with the ones above.</span>
                                <button onclick="saveCategoryConfigAndRouting({{ $category->id }})"
                                        class="kt-btn kt-btn-sm kt-btn-primary">
                                    <i class="ki-filled ki-check"></i> Save Routing
                                </button>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>

                <div class="mt-6 p-4 bg-yellow-50 rounded">
                    <p class="text-sm text-gray-700">
                        <strong>💡 Configuration Tips:</strong><br>
                        • <strong>Role-based (default):</strong> Leave user assignments empty - any L1/L2 user can approve<br>
                        • <strong>User-specific:</strong> Assign specific users for dedicated approval paths<br>
                        • <strong>Payment source filters:</strong> Route based on which account the payment comes from<br>
                        • <strong>Auto-approve:</strong> Set threshold for automatic approval of small amounts
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Toggle category details
function toggleCategoryDetails(categoryId) {
    const details = document.getElementById(`details-${categoryId}`);
    const icon = document.getElementById(`icon-${categoryId}`);
    
    if (details.classList.contains('hidden')) {
        details.classList.remove('hidden');
        icon.classList.remove('ki-down');
        icon.classList.add('ki-up');
    } else {
        details.classList.add('hidden');
        icon.classList.remove('ki-up');
        icon.classList.add('ki-down');
    }
}

// Add a new routing row for a given category and level (1 or 2)
function addRoutingRow(categoryId, level) {
    const container = document.getElementById(`l${level}-rules-${categoryId}`);
    if (!container) return;

    const firstRow = container.querySelector('.routing-row');
    let newRow;

    if (firstRow) {
        newRow = firstRow.cloneNode(true);
        // Reset selects in cloned row
        newRow.querySelectorAll('select').forEach(sel => {
            sel.value = '';
        });
    } else {
        // Fallback: no existing row (should not normally happen)
        newRow = document.createElement('div');
        newRow.className = 'flex items-center gap-2 routing-row';
        newRow.dataset.level = String(level);
        newRow.innerHTML = '<span class="text-xs text-red-600">No template row found</span>';
    }

    container.appendChild(newRow);
}

// Remove a routing row (but keep at least one row for UX)
function removeRoutingRow(button) {
    const row = button.closest('.routing-row');
    if (!row) return;

    const container = row.parentElement;
    const rows = container.querySelectorAll('.routing-row');

    if (rows.length <= 1) {
        // Just clear selections instead of removing the last row
        row.querySelectorAll('select').forEach(sel => {
            sel.value = '';
        });
    } else {
        row.remove();
    }
}

// Save "needs L1/L2" immediately when a checkbox changes (no expand/Save needed).
function saveRequires(categoryId) {
    const l1 = document.querySelector(`input[data-category-id="${categoryId}"][data-field="requires_level_1"]`).checked;
    const l2 = document.querySelector(`input[data-category-id="${categoryId}"][data-field="requires_level_2"]`).checked;
    fetch(`/requests/settings/categories/${categoryId}/config`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        body: JSON.stringify({ requires_level_1: l1, requires_level_2: l2 })
    })
    .then(r => r.json())
    .then(data => {
        const el = document.getElementById('reqsaved-' + categoryId);
        if (data.success) {
            if (el) { el.style.display = ''; setTimeout(() => { el.style.display = 'none'; }, 1500); }
        } else {
            alert('Could not save: ' + (data.message || 'error'));
        }
    })
    .catch(e => { console.error(e); alert('Network error saving approval steps.'); });
}

// Save category routing (and the "needs L1/L2" state) from the expanded panel.
function saveCategoryConfigAndRouting(categoryId) {
    // Get basic config
    const requiresL1 = document.querySelector(`input[data-category-id="${categoryId}"][data-field="requires_level_1"]`).checked;
    const requiresL2 = document.querySelector(`input[data-category-id="${categoryId}"][data-field="requires_level_2"]`).checked;

    // Build routing rules array from UI
    const rules = [];
    [1, 2].forEach(level => {
        const container = document.getElementById(`l${level}-rules-${categoryId}`);
        if (!container) return;

        container.querySelectorAll('.routing-row').forEach(row => {
            const userSelect = row.querySelector('select[data-role="user"]');
            const accountSelect = row.querySelector('select[data-role="payment_source"]');

            if (!userSelect || !userSelect.value) {
                return; // Skip rows without user selected
            }

            const rule = {
                level: level,
                user_id: parseInt(userSelect.value, 10),
                payment_source_account_id: accountSelect && accountSelect.value
                    ? parseInt(accountSelect.value, 10)
                    : null
            };

            rules.push(rule);
        });
    });

    // First, save basic config
    fetch(`/requests/settings/categories/${categoryId}/config`, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({
            requires_level_1: requiresL1,
            requires_level_2: requiresL2
        })
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            alert('Error: ' + data.message);
            return;
        }

        // Now save routing rules (this will also clear any existing rules for this category)
        fetch(`/requests/settings/categories/${categoryId}/routing`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ rules })
        })
        .then(response => response.json())
        .then(routingData => {
            if (routingData.success) {
                alert('Configuration and routing saved successfully!');
            } else {
                alert('Configuration saved, but routing failed: ' + routingData.message);
            }
        })
        .catch(error => {
            console.error('Error saving routing rules:', error);
            alert('Configuration saved, but routing had errors. Check console.');
        });
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
}

// NOTE (Phase 4): assignRoleToLevel/removeRoleFromLevel moved to the Role Access
// screens (approval-authority chips on pages/roles/permissions + mobile-permissions).
// The endpoints (requests.settings.roles.assign / .remove) are still owned by
// RequestSettingsController and used from there — only this page's editing UI moved.

// ======= REQUEST TYPE MANAGEMENT =======

function quickToggle(catId, field, value) {
    const row = document.getElementById('typeRow-' + catId);
    if (!row) return;

    const payload = {
        category_name: row.dataset.name,
        description: row.dataset.desc || null,
        icon: row.dataset.icon || null,
        color_class: 'bg-gray-500',
        is_active: row.dataset.active === '1',
        sequence_order: parseInt(row.dataset.seq) || 0,
        show_in_expenses: field === 'show_in_expenses' ? value : (row.dataset.show === '1'),
        expense_bu_type: row.dataset.bu || null,
        form_type: row.dataset.form || 'general',
        mobile_permission_code: row.dataset.perm || null,
    };

    fetch('/requests/settings/categories/' + catId, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            row.dataset.show = payload.show_in_expenses ? '1' : '0';
        } else {
            alert('Error: ' + data.message);
            location.reload();
        }
    })
    .catch(e => { console.error(e); alert('Failed'); location.reload(); });
}

function showCreateTypeModal() {
    const html = `<div id="typeModal" style="position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);">
        <div style="background:#fff;border-radius:12px;padding:24px;width:520px;max-width:95vw;max-height:90vh;overflow-y:auto;">
            <h3 style="font-size:18px;font-weight:600;margin-bottom:16px;">Create New Request Type</h3>
            <div style="display:grid;gap:12px;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div><label class="text-xs text-gray-600">Code (lowercase, no spaces) *</label><input id="tm_code" class="kt-input kt-input-sm w-full" placeholder="e.g. qurbani"></div>
                    <div><label class="text-xs text-gray-600">Name *</label><input id="tm_name" class="kt-input kt-input-sm w-full" placeholder="e.g. Qurbani"></div>
                </div>
                <div><label class="text-xs text-gray-600">Description</label><input id="tm_desc" class="kt-input kt-input-sm w-full" placeholder="Short description"></div>
                <div>
                    <label class="text-xs text-gray-600">Icon (emoji)</label>
                    <input id="tm_icon" class="kt-input kt-input-sm w-full" placeholder="🐄">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div><label class="text-xs text-gray-600">Show in Expense Management</label><select id="tm_show_expenses" class="kt-select kt-select-sm w-full"><option value="0">No</option><option value="1">Yes</option></select></div>
                    <div><label class="text-xs text-gray-600">BU Type (for expense filtering)</label><select id="tm_bu_type" class="kt-select kt-select-sm w-full"><option value="">None</option><option value="nf">NF (Nizami Farms)</option><option value="khaas">Khaas</option><option value="all">All BUs</option></select></div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div><label class="text-xs text-gray-600">Form Type</label><select id="tm_form_type" class="kt-select kt-select-sm w-full"><option value="general">General</option><option value="expense">Expense (amount, category, receipt)</option><option value="salary">Salary (amount only)</option><option value="leave">Leave (date range)</option></select></div>
                    <div><label class="text-xs text-gray-600">Mobile Permission Code</label><input id="tm_perm" class="kt-input kt-input-sm w-full" placeholder="e.g. expense_type_qurbani"></div>
                </div>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px;">
                <button onclick="document.getElementById('typeModal').remove()" class="kt-btn kt-btn-sm kt-btn-light">Cancel</button>
                <button onclick="submitCreateType()" class="kt-btn kt-btn-sm kt-btn-primary">Create</button>
            </div>
        </div>
    </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
}

function submitCreateType() {
    const code = document.getElementById('tm_code').value.trim().toLowerCase().replace(/\s+/g, '_');
    const name = document.getElementById('tm_name').value.trim();
    if (!code || !name) { alert('Code and Name are required'); return; }

    fetch('{{ route("requests.settings.category.create") }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        body: JSON.stringify({
            category_code: code,
            category_name: name,
            description: document.getElementById('tm_desc').value.trim() || null,
            icon: document.getElementById('tm_icon').value.trim() || null,
            color_class: 'bg-gray-500',
            show_in_expenses: document.getElementById('tm_show_expenses').value === '1',
            expense_bu_type: document.getElementById('tm_bu_type').value || null,
            form_type: document.getElementById('tm_form_type').value || 'general',
            mobile_permission_code: document.getElementById('tm_perm').value.trim() || null,
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) { alert('Request type created!'); location.reload(); }
        else { alert('Error: ' + data.message); }
    })
    .catch(e => { console.error(e); alert('Failed to create.'); });
}

function editTypeRow(catId) {
    const row = document.getElementById('typeRow-' + catId);
    if (!row) return;
    const d = row.dataset;
    const code = d.code;
    const name = d.name;
    const desc = d.desc || '';
    const icon = d.icon || '';
    const showExp = d.show === '1';
    const buType = d.bu || '';
    const formType = d.form || 'general';
    const perm = d.perm || '';
    const active = d.active === '1';
    const seq = d.seq || '0';

    const html = `<div id="typeEditModal" style="position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);">
        <div style="background:#fff;border-radius:12px;padding:24px;width:520px;max-width:95vw;max-height:90vh;overflow-y:auto;">
            <h3 style="font-size:18px;font-weight:600;margin-bottom:16px;">Edit: ${name} <span style="font-size:12px;color:#9ca3af;">(${code})</span></h3>
            <div style="display:grid;gap:12px;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div><label class="text-xs text-gray-600">Name *</label><input id="te_name" class="kt-input kt-input-sm w-full" value="${name}"></div>
                    <div><label class="text-xs text-gray-600">Icon</label><input id="te_icon" class="kt-input kt-input-sm w-full" value="${icon}"></div>
                </div>
                <div><label class="text-xs text-gray-600">Description</label><input id="te_desc" class="kt-input kt-input-sm w-full" value="${desc}"></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div><label class="text-xs text-gray-600">Show in Expense Management</label><select id="te_show_expenses" class="kt-select kt-select-sm w-full"><option value="0" ${!showExp?'selected':''}>No</option><option value="1" ${showExp?'selected':''}>Yes</option></select></div>
                    <div><label class="text-xs text-gray-600">BU Type</label><select id="te_bu_type" class="kt-select kt-select-sm w-full"><option value="" ${!buType?'selected':''}>None</option><option value="nf" ${buType==='nf'?'selected':''}>NF</option><option value="khaas" ${buType==='khaas'?'selected':''}>Khaas</option><option value="all" ${buType==='all'?'selected':''}>All</option></select></div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div><label class="text-xs text-gray-600">Form Type</label><select id="te_form_type" class="kt-select kt-select-sm w-full"><option value="general" ${formType==='general'?'selected':''}>General</option><option value="expense" ${formType==='expense'?'selected':''}>Expense</option><option value="salary" ${formType==='salary'?'selected':''}>Salary</option><option value="leave" ${formType==='leave'?'selected':''}>Leave</option></select></div>
                    <div><label class="text-xs text-gray-600">Mobile Permission</label><input id="te_perm" class="kt-input kt-input-sm w-full" value="${perm}"></div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div><label class="text-xs text-gray-600">Active</label><select id="te_active" class="kt-select kt-select-sm w-full"><option value="1" ${active?'selected':''}>Yes</option><option value="0" ${!active?'selected':''}>No</option></select></div>
                    <div><label class="text-xs text-gray-600">Sequence Order</label><input id="te_seq" type="number" class="kt-input kt-input-sm w-full" value="${seq}" min="0"></div>
                </div>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px;">
                <button onclick="document.getElementById('typeEditModal').remove()" class="kt-btn kt-btn-sm kt-btn-light">Cancel</button>
                <button onclick="submitEditType(${catId})" class="kt-btn kt-btn-sm kt-btn-primary">Save</button>
            </div>
        </div>
    </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
}

function submitEditType(catId) {
    const name = document.getElementById('te_name').value.trim();
    if (!name) { alert('Name is required'); return; }

    fetch('/requests/settings/categories/' + catId, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        body: JSON.stringify({
            category_name: name,
            description: document.getElementById('te_desc').value.trim() || null,
            icon: document.getElementById('te_icon').value.trim() || null,
            color_class: 'bg-gray-500',
            is_active: document.getElementById('te_active').value === '1',
            sequence_order: parseInt(document.getElementById('te_seq').value) || 0,
            show_in_expenses: document.getElementById('te_show_expenses').value === '1',
            expense_bu_type: document.getElementById('te_bu_type').value || null,
            form_type: document.getElementById('te_form_type').value || 'general',
            mobile_permission_code: document.getElementById('te_perm').value.trim() || null,
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) { alert('Updated!'); location.reload(); }
        else { alert('Error: ' + data.message); }
    })
    .catch(e => { console.error(e); alert('Failed to update.'); });
}

// ======= EXPENSE SUB-CATEGORIES =======

function addSubCategoryForType(buId, requestCategoryCode) {
    const inputId = requestCategoryCode ? 'newSubCat-' + requestCategoryCode : 'newSubCat-shared-' + buId;
    const input = document.getElementById(inputId);
    if (!input) { alert('Input not found'); return; }
    const name = input.value.trim();
    if (!name) { alert('Enter a sub-category name'); return; }

    const payload = { category_name: name, business_unit_id: buId };
    if (requestCategoryCode) {
        payload.request_category_code = requestCategoryCode;
    }

    fetch('{{ route("fin.expense-category.store") }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success || (data.message && data.message.includes('successfully'))) {
            alert('Sub-category added!');
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed'));
        }
    })
    .catch(e => { console.error(e); alert('Failed to add.'); });
}

// Oct-2026: no longer reachable from this page (types are turned off from ✎ Manage types).
// The endpoint now makes the type INACTIVE through ExpenseTypeService — it never deletes.
function deleteSubCategory(id, name) {
    if (!confirm('Turn off "' + name + '"? It stops showing in the Expense Type dropdown; past expenses keep it.')) return;

    fetch('/requests/settings/expense-subcategories/' + id, {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const el = document.getElementById('subCat-' + id);
            if (el) el.remove();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(e => { console.error(e); alert('Failed to delete.'); });
}
</script>

@endsection

