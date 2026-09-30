{{-- resources/views/pages/requests/create.blade.php --}}

@extends('layouts.app')

@section('title', 'Create New Request')

@section('content')

<div class="kt-container-fixed">
    <div class="grid gap-5 lg:gap-7.5">
        <div class="kt-card max-w-3xl mx-auto">
            <!-- Header -->
            <div class="kt-card-header">
                <h3 class="kt-card-title text-lg font-semibold">Create New Request</h3>
                <div class="kt-card-toolbar">
                    <a href="{{ route('requests.index') }}" class="kt-btn kt-btn-light">
                        <i class="ki-filled ki-left"></i> Back
                    </a>
                </div>
            </div>

            <!-- Form -->
            <div class="kt-card-body">
                <form id="request-form">
                    @csrf
                    
                    @php
                        // Check if user can create requests for others (admin/manager)
                        $canCreateForOthers = false;
                        $debugRoles = [];
                        if (auth()->check()) {
                            // Get ALL user's roles (not just the first one!)
                            $userRoles = \DB::table('t_sys_user_role as ur')
                                ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                                ->where('ur.user_id', auth()->id())
                                ->select('r.type', 'r.urole_name')
                                ->get();
                            
                            $debugRoles = $userRoles; // For debugging
                            
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
                                
                                // If ANY role matches, user can create for others
                                if ($typeMatch || $nameMatch) {
                                    $canCreateForOthers = true;
                                    break;
                                }
                            }
                        }
                    @endphp
                    
                    <!-- User Selection (Admin/Manager Only) -->
                    @if($canCreateForOthers)
                    <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded">
                        <div class="flex items-center gap-2 mb-3">
                            <i class="ki-filled ki-information text-blue-600"></i>
                            <span class="text-sm font-medium text-blue-800">Admin/Manager Mode</span>
                        </div>
                        <label class="kt-label">Create Request For (Leave blank to create for yourself)</label>
                        <select id="requester_user_id" name="requester_user_id" class="kt-select">
                            <option value="">-- Myself --</option>
                            @php
                                $activeUsers = \DB::table('t_sys_user')
                                    ->where('is_active', 1)
                                    ->whereNotIn('id', [auth()->id()])
                                    ->orderBy('fullname')
                                    ->get();
                            @endphp
                            @foreach($activeUsers as $user)
                            <option value="{{ $user->id }}">{{ $user->fullname }} ({{ $user->email }})</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-blue-600 mt-2">
                            💡 Select an employee to create a request on their behalf. The approval flow will apply normally.
                        </p>
                    </div>
                    @endif
                    
                    <!-- Category Selection -->
                    <div class="mb-6">
                        <label class="kt-label required">Request Category</label>
                        <select id="category_id" name="category_id" class="kt-select" required onchange="handleCategoryChange()">
                            <option value="">Select Category</option>
                            @foreach($categories as $category)
                            {{-- data-bu: which books this category spends from. Comes from
                                 expense_bu_type (nf|khaas|all), the mapping Request Settings
                                 already maintains — a Khaas Expense must offer Khaas accounts
                                 and stamp the request BU 2. Until Aug-2026 this form sent no
                                 business unit at all, so a Khaas expense filed here was
                                 stamped Nizami Farms. --}}
                            <option value="{{ $category->id }}"
                                    data-code="{{ $category->category_code }}"
                                    data-bu="{{ ($category->expense_bu_type ?? 'nf') === 'khaas' ? 2 : 1 }}"
                                    data-requires-l1="{{ $category->requiresLevel1() ? '1' : '0' }}"
                                    data-requires-l2="{{ $category->requiresLevel2() ? '1' : '0' }}">
                                {{ $category->category_name }}
                            </option>
                            @endforeach
                        </select>
                        <div class="mt-2 text-sm text-gray-600" id="approval-info"></div>
                    </div>

                    <!-- Leave-specific fields (hidden by default) -->
                    <div id="leave-fields" style="display: none;">
                        <div class="grid grid-cols-2 gap-4 mb-6">
                            <div>
                                <label class="kt-label required">Leave Start Date</label>
                                <input type="date" name="leave_start_date" class="kt-input" onchange="calculateLeaveDays()">
                            </div>
                            <div>
                                <label class="kt-label required">Leave End Date</label>
                                <input type="date" name="leave_end_date" class="kt-input" onchange="calculateLeaveDays()">
                            </div>
                        </div>

                        <!-- Hidden field: Leave Type defaults to 'planned' (advance);
                             self-service same-day applies are set to 'emergency' server-side. -->
                        <input type="hidden" name="leave_type" value="planned">

                        <div class="mb-6">
                            <div class="kt-alert kt-alert-info" id="leave-days-info" style="display: none;">
                                <div class="kt-alert-title">
                                    <span id="leave-days-text"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Title - hidden for leave requests, auto-filled -->
                    <input type="hidden" name="title" id="hidden-title" value="leave">

                    <!-- Expense Category (for expense requests only) -->
                    <div id="expense-category-field" style="display: none;" class="mb-6">
                        <label class="kt-label required">Expense Type</label>
                        <select name="expense_category" id="expense_category" class="kt-select" onchange="handleExpenseCategoryChange()">
                            <option value="">Select Expense Type</option>
                            @php
                                $expenseCategories = \App\Models\FIN\ConfigModel::where('config_key', 'LIKE', 'EXPENSE_CATEGORY_%')
                                    ->where('config_key', '!=', 'EXPENSE_CATEGORY_STAFF_SALARIES') // salaries go through Payroll now
                                    ->orderBy('config_value')
                                    ->pluck('config_value');
                            @endphp
                            @if($expenseCategories->count() > 0)
                                @foreach($expenseCategories as $cat)
                                    <option value="{{ $cat }}">{{ $cat }}</option>
                                @endforeach
                            @else
                                {{-- Fallback if no categories in database --}}
                                <option value="Petrol">Petrol</option>
                                <option value="Rent">Rent</option>
                                <option value="Office Supplies">Office Supplies</option>
                            @endif
                            <option value="__ADD_NEW__" style="background-color: #f3f4f6; font-weight: bold; color: #059669;">➕ Add New Category...</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Select the type of expense for proper accounting, or add a new category</p>
                    </div>

                    <!-- 🔧 Bike capture (Jul-2026): Maintenance AND Petrol.
                         Petrol used to have no meter field here at all, and the server
                         discarded the reading even if sent — so a manager-filed
                         company-bike fill was invisible to km-since-last-fill and to
                         the Bikes running-cost figures. Both categories now carry it,
                         and the server (FuelClaimRules) REQUIRES it on a company bike.
                         Service type stays Maintenance-only. -->
                    <div id="bike-service-fields" style="display: none;" class="mb-6">
                        <label class="kt-label" id="bike-fields-label">Bike Service (only if this is for a rider's bike)</label>
                        {{-- The manager's own categories, grouped by kind. `service_type`
                             is DERIVED server-side from the chosen type's bucket, so this
                             select posts a type id and the machine flag is never trusted
                             from the client. Falls back to the original two options when
                             types are not configured yet (batch 12 not run). --}}
                        @php
                            $maintTypes = app(\App\Services\Riders\MaintenanceTypeService::class)->options();
                        @endphp
                        {{-- ⚠ Exactly ONE of these renders, and it always carries
                             id="service_type" — the show/hide JS below and the
                             deep-link handler both look that id up, and two elements
                             sharing it would leave one of them silently unmanaged. --}}
                        @if(!empty($maintTypes))
                        <select name="maintenance_type_id" id="service_type" class="kt-select mb-2">
                            <option value="">Not a bike / other maintenance</option>
                            @foreach(['regular' => '🛢️ Regular service', 'repair' => '🔧 Repair'] as $bucket => $groupLabel)
                                @php $inGroup = array_values(array_filter($maintTypes, fn($t) => $t['bucket'] === $bucket)); @endphp
                                @if($inGroup)
                                <optgroup label="{{ $groupLabel }}">
                                    @foreach($inGroup as $t)
                                        <option value="{{ $t['id'] }}" data-bucket="{{ $t['bucket'] }}">
                                            {{ $t['name'] }} — due {{ $t['due_label'] }}
                                        </option>
                                    @endforeach
                                </optgroup>
                                @endif
                            @endforeach
                        </select>
                        @else
                        {{-- Types not configured yet (batch 12 not run): the original
                             two-option picker, posting the raw machine flag. --}}
                        <select name="service_type" id="service_type" class="kt-select mb-2">
                            <option value="">Not a bike / other maintenance</option>
                            <option value="oil_change">🛢️ Regular service (oil change / tuning)</option>
                            <option value="repair">🔧 Repair (anything broken)</option>
                        </select>
                        @endif
                        <input type="number" name="meter_at_fill" id="meter_at_fill" class="kt-input" placeholder="Odometer at the service (km)" min="0" max="9999999" step="1" style="display: none;">
                        <p class="text-xs text-gray-500 mt-1" id="bike-service-hint" style="display: none;">
                            A <b>Regular service</b> with the odometer resets that job's own countdown on approval. A Repair never does.
                        </p>
                    </div>

                    <!-- Amount field (for advance/expense) -->
                    <div id="amount-field" style="display: none;" class="mb-6">
                        <label class="kt-label">Amount (Rs.)</label>
                        <input type="number" name="amount" class="kt-input" placeholder="0.00" step="0.01" min="0">
                    </div>

                    <!-- 💳 Paid from — money categories only. The select is DISABLED
                         while hidden so FormData omits it entirely: a leave request
                         must never carry a payment source.
                         Options for BOTH books are rendered and filtered by the
                         selected category's data-bu, so switching to Khaas Expense
                         swaps the accounts without a round trip. The server re-derives
                         the same list and rejects anything not on it. -->
                    <input type="hidden" name="business_unit_id" id="business_unit_id" value="1">
                    <div id="pay-from-field" style="display: none;" class="mb-6">
                        <label class="kt-label">💳 Paid from</label>
                        <select name="payment_source_account_id" id="payment_source_account_id" class="kt-select" disabled onchange="handlePaySourceChange()">
                            @foreach($paySources ?? [] as $src)
                                <option value="{{ $src['id'] }}"
                                        {{-- The unit this row was built FOR, not the account's own
                                             books. The shared online bank appears in both lists and
                                             belongs in both — keyed on its own business_unit_id it
                                             would vanish from the Frozen list and double up in NF's. --}}
                                        data-bu="{{ $src['for_business_unit_id'] ?? $src['business_unit_id'] ?? 1 }}"
                                        data-online="{{ $src['is_online'] ? '1' : '0' }}"
                                        data-default="{{ $src['is_default'] ? '1' : '0' }}"
                                        data-bank="{{ $src['preferred_bank_id'] ?? '' }}">
                                    {{ $src['display_name'] ?: $src['name'] }}
                                </option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-500 mt-1" id="pay-from-hint">The account this money actually left.</p>
                    </div>

                    <!-- Which of OUR banks an online payment came from. Mandatory for a
                         bank source, or the per-bank balances drift. -->
                    <div id="pay-bank-field" style="display: none;" class="mb-6">
                        <label class="kt-label required">🏦 From which bank</label>
                        <select name="receiving_account_id" id="receiving_account_id" class="kt-select" disabled>
                            <option value="">Select the bank…</option>
                            @foreach($payBanks ?? [] as $bank)
                                <option value="{{ $bank['id'] }}">{{ $bank['name'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Description - Optional for leave requests -->
                    <div class="mb-6">
                        <label class="kt-label" id="description-label">Description</label>
                        <textarea name="description" id="description-field" class="kt-input" rows="4" placeholder="Provide detailed information about your request (optional for leave requests)"></textarea>
                    </div>

                    <!-- Priority -->
                    <div class="mb-6">
                        <label class="kt-label">Priority</label>
                        <select name="priority" class="kt-select">
                            <option value="normal">Normal</option>
                            <option value="low">Low</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>

                    <!-- Submit Button -->
                    <div class="flex gap-2">
                        <button type="submit" class="kt-btn kt-btn-primary">
                            <i class="ki-filled ki-check"></i> Submit Request
                        </button>
                        <a href="{{ route('requests.index') }}" class="kt-btn kt-btn-light">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
/**
 * Show "Paid from" only where money actually moves. Same category set the
 * server treats as money-moving in RequestController::store() — keep them in
 * step, or the form will ask for a bank the server does not want (or skip one
 * it demands).
 *
 * Hidden fields are DISABLED, not just display:none — a disabled control is
 * left out of FormData, which is what stops a leave request from carrying a
 * payment source.
 */
function updatePayFromFields(categoryCode) {
    const payField = document.getElementById('pay-from-field');
    const paySelect = document.getElementById('payment_source_account_id');
    if (!payField || !paySelect) return;

    // Which books does this category spend from? Drives BOTH the account list and
    // the business_unit_id the request is stamped with — they must never disagree,
    // or the expense lands in one set of books funded from the other's account.
    const catOpt = document.getElementById('category_id').selectedOptions[0];
    const bu = (catOpt && catOpt.dataset.bu) ? catOpt.dataset.bu : '1';
    document.getElementById('business_unit_id').value = bu;

    const isMoney = ['expense', 'khaas_expense', 'qurbani', 'advance', 'salary_advance'].includes(categoryCode);

    // Show only this book's accounts. A hidden option is also DISABLED so it can
    // never be submitted, and deselected so it cannot stay chosen from before.
    let visible = 0, preferred = null, firstVisible = null;
    Array.from(paySelect.options).forEach(o => {
        const match = (o.dataset.bu || '1') === bu;
        o.hidden = !match;
        o.disabled = !match;
        if (match) {
            visible++;
            if (!firstVisible) firstVisible = o;
            if (o.dataset.default === '1') preferred = o;
        } else if (o.selected) {
            o.selected = false;
        }
    });

    const show = isMoney && visible > 0;
    payField.style.display = show ? 'block' : 'none';
    paySelect.disabled = !show;

    // Pre-select this user's account for THIS book (their star), else the first.
    if (show && !Array.from(paySelect.options).some(o => o.selected && !o.hidden)) {
        (preferred || firstVisible).selected = true;
    }

    // One allowed account is still worth showing — "it came out of the fund" is
    // information the filer wants confirmed — but there is nothing to choose.
    document.getElementById('pay-from-hint').textContent = visible === 1
        ? 'This is the only account you can spend from.'
        : 'The account this money actually left.';

    handlePaySourceChange();
}

/** A bank source must name the bank; a cash source must never carry one. */
function handlePaySourceChange() {
    const paySelect = document.getElementById('payment_source_account_id');
    const bankField = document.getElementById('pay-bank-field');
    const bankSelect = document.getElementById('receiving_account_id');
    if (!paySelect || !bankField || !bankSelect) return;

    const opt = paySelect.options[paySelect.selectedIndex];
    const needsBank = !paySelect.disabled
        && !!(opt && opt.dataset.online === '1')
        && bankSelect.options.length > 1;

    bankField.style.display = needsBank ? 'block' : 'none';
    bankSelect.disabled = !needsBank;
    bankSelect.required = needsBank;
    if (!needsBank) { bankSelect.value = ''; return; }

    // Pre-select the bank this person usually pays from, if one is tagged. Saying
    // "Online Bank" does not say WHICH bank, and the wrong pick silently skews a
    // per-bank balance.
    const pref = opt.dataset.bank;
    if (pref && !bankSelect.value) {
        if (Array.from(bankSelect.options).some(o => o.value === pref)) bankSelect.value = pref;
    }
}

function handleCategoryChange() {
    const select = document.getElementById('category_id');
    const selectedOption = select.options[select.selectedIndex];
    const categoryCode = selectedOption.dataset.code;
    const requiresL1 = selectedOption.dataset.requiresL1 === '1';
    const requiresL2 = selectedOption.dataset.requiresL2 === '1';
    const hiddenTitle = document.getElementById('hidden-title');
    
    // Show/hide fields based on category
    const leaveFields = document.getElementById('leave-fields');
    const amountField = document.getElementById('amount-field');
    const expenseCategoryField = document.getElementById('expense-category-field');
    const descriptionLabel = document.getElementById('description-label');
    const descriptionField = document.getElementById('description-field');
    const expenseCategorySelect = document.getElementById('expense_category');
    
    if (categoryCode === 'leave') {
        leaveFields.style.display = 'block';
        amountField.style.display = 'none';
        expenseCategoryField.style.display = 'none';
        // Make leave fields required
        document.querySelector('[name="leave_start_date"]').required = true;
        document.querySelector('[name="leave_end_date"]').required = true;
        document.querySelector('[name="amount"]').required = false;
        expenseCategorySelect.required = false;
        // Description optional for leave
        descriptionField.required = false;
        descriptionLabel.classList.remove('required');
        descriptionField.placeholder = 'Optional: Provide additional details about your leave';
        // Set title to "leave"
        hiddenTitle.value = 'leave';
    } else if (categoryCode === 'expense') {
        // EXPENSE: Show both expense category AND amount field
        leaveFields.style.display = 'none';
        expenseCategoryField.style.display = 'block';
        amountField.style.display = 'block';
        // Make expense category and amount required
        expenseCategorySelect.required = true;
        document.querySelector('[name="amount"]').required = true;
        document.querySelector('[name="leave_start_date"]').required = false;
        document.querySelector('[name="leave_end_date"]').required = false;
        // Description required for expense
        descriptionField.required = true;
        descriptionLabel.classList.add('required');
        // Set title to "expense" (will be updated when expense category is selected)
        hiddenTitle.value = 'expense';
    } else if (categoryCode === 'advance' || categoryCode === 'salary_advance') {
        // ADVANCE/SALARY ADVANCE: Show only amount field, NO expense category
        leaveFields.style.display = 'none';
        expenseCategoryField.style.display = 'none';
        amountField.style.display = 'block';
        // Make amount required
        const amountInput = document.querySelector('[name="amount"]');
        amountInput.required = true;
        // Set default value to 5000 for salary advance (approvers can change it)
        if (!amountInput.value || amountInput.value == '0') {
            amountInput.value = '5000.00';
            amountInput.placeholder = 'Default: 5000.00 (can be changed by approver)';
        }
        document.querySelector('[name="leave_start_date"]').required = false;
        document.querySelector('[name="leave_end_date"]').required = false;
        expenseCategorySelect.required = false;
        // Set title to "advance"
        hiddenTitle.value = 'advance';
        // Description required for advance
        descriptionField.required = true;
        descriptionLabel.classList.add('required');
        descriptionField.placeholder = 'Provide detailed information about your request';
    } else {
        leaveFields.style.display = 'none';
        amountField.style.display = 'none';
        expenseCategoryField.style.display = 'none';
        document.querySelector('[name="amount"]').required = false;
        document.querySelector('[name="leave_start_date"]').required = false;
        document.querySelector('[name="leave_end_date"]').required = false;
        // Description required for other types
        descriptionField.required = true;
        descriptionLabel.classList.add('required');
        descriptionField.placeholder = 'Provide detailed information about your request';
    }

    // 🔧 Keep the bike-service fields in step with the expense group — switching
    // away from Expense must hide AND clear them (stale service_type guard).
    updateBikeServiceFields();

    // 💳 And the pay-from group, on the same principle.
    updatePayFromFields(categoryCode);

    // Show approval info
    const approvalInfo = document.getElementById('approval-info');
    if (select.value) {
        let infoText = 'This request will require: ';
        if (requiresL1 && requiresL2) {
            infoText += '<strong>Level 1 AND Level 2 approval</strong>';
        } else if (requiresL1) {
            infoText += '<strong>Level 1 approval only</strong>';
        } else {
            infoText += '<strong>No approval required</strong> (auto-approved)';
        }
        approvalInfo.innerHTML = infoText;
    } else {
        approvalInfo.innerHTML = '';
    }
}

function calculateLeaveDays() {
    const startDate = document.querySelector('[name="leave_start_date"]').value;
    const endDate = document.querySelector('[name="leave_end_date"]').value;
    
    if (startDate && endDate) {
        const start = new Date(startDate);
        const end = new Date(endDate);
        const diffTime = Math.abs(end - start);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
        
        const infoDiv = document.getElementById('leave-days-info');
        const textSpan = document.getElementById('leave-days-text');
        
        if (diffDays > 0) {
            textSpan.textContent = `Total leave days: ${diffDays} day(s)`;
            infoDiv.style.display = 'block';
        } else {
            infoDiv.style.display = 'none';
        }
    }
}

function handleExpenseCategoryChange() {
    const expenseCategorySelect = document.getElementById('expense_category');
    const selectedValue = expenseCategorySelect.value;

    if (selectedValue === '__ADD_NEW__') {
        // Open modal to add new category
        openInlineExpenseCategoryModal();
        // Reset selection
        expenseCategorySelect.value = '';
    } else {
        // Update title
        updateExpenseTitle();
    }

    // 🔧 Bike service fields only make sense for Maintenance. Values are CLEARED
    // when hidden so switching category can never submit a stale service_type
    // (the server also guards, but don't rely on it).
    updateBikeServiceFields();
}

function updateBikeServiceFields() {
    const expenseCategorySelect = document.getElementById('expense_category');
    const wrap = document.getElementById('bike-service-fields');
    if (!wrap) return;
    // Only when the expense-category group itself is visible — switching the
    // request category to Leave hides the group but keeps its value, and the
    // bike fields must never outlive it.
    const groupVisible = (document.getElementById('expense-category-field') || {}).style
        ? document.getElementById('expense-category-field').style.display !== 'none' : false;
    const cat = groupVisible && expenseCategorySelect ? expenseCategorySelect.value : '';
    const isMaint  = cat === 'Maintenance';
    const isPetrol = cat === 'Petrol';
    const st = document.getElementById('service_type');
    const mf = document.getElementById('meter_at_fill');
    const hint = document.getElementById('bike-service-hint');
    const label = document.getElementById('bike-fields-label');

    // The block now serves BOTH bike categories. Petrol shows the odometer alone
    // (no service type), and shows it straight away — on a company bike the server
    // will refuse the claim without it.
    wrap.style.display = (isMaint || isPetrol) ? 'block' : 'none';
    if (st) st.style.display = isMaint ? 'block' : 'none';
    if (label) label.textContent = isPetrol
        ? "Bike meter (required for a company bike)"
        : "Bike Service (only if this is for a rider's bike)";

    if (isPetrol) {
        if (st) st.value = '';                       // never a service type on petrol
        if (mf) { mf.style.display = 'block'; mf.placeholder = 'Odometer at the fill (km)'; }
        if (hint) {
            hint.style.display = 'block';
            hint.innerHTML = 'The odometer at the moment of filling. It ties this fill to the '
                + "bike's kilometres, and is <b>required</b> for a company bike.";
        }
    } else if (isMaint) {
        if (mf) mf.placeholder = 'Odometer at the service (km)';
        // Odometer + hint stay driven by the service-type picker below.
        const show = st && st.value !== '';
        if (mf) mf.style.display = show ? 'block' : 'none';
        if (hint) {
            hint.style.display = show ? 'block' : 'none';
            hint.innerHTML = 'A <b>Regular service</b> with the odometer resets that '
                + "job's own countdown on approval. A Repair never does.";
        }
    } else {
        if (st) st.value = '';
        if (mf) { mf.value = ''; mf.style.display = 'none'; }
        if (hint) hint.style.display = 'none';
    }
}

// Odometer + hint appear once a bike service type is chosen.
document.addEventListener('DOMContentLoaded', function () {
    const st = document.getElementById('service_type');
    if (st) {
        st.addEventListener('change', function () {
            const mf = document.getElementById('meter_at_fill');
            const hint = document.getElementById('bike-service-hint');
            const show = st.value !== '';
            if (mf) { mf.style.display = show ? 'block' : 'none'; if (!show) mf.value = ''; }
            if (hint) hint.style.display = show ? 'block' : 'none';
        });
    }

    // ⛽/🔧 Deep link from the Bikes tab: /requests/create?expense_category=Petrol
    // preselects Request Type = Expense and that category, so the manager lands on
    // a form that already knows what he pressed. Only the two bike categories are
    // honoured — this is a shortcut, not a way to drive the form from a URL.
    try {
        const wanted = new URLSearchParams(window.location.search).get('expense_category');
        if (!['Petrol', 'Maintenance'].includes(wanted)) return;

        const catSel = document.getElementById('category_id');
        if (catSel) {
            const expenseOpt = [...catSel.options].find(o => o.dataset && o.dataset.code === 'expense');
            if (expenseOpt) {
                catSel.value = expenseOpt.value;
                catSel.dispatchEvent(new Event('change'));
            }
        }
        const expSel = document.getElementById('expense_category');
        if (expSel && [...expSel.options].some(o => o.value === wanted)) {
            expSel.value = wanted;
            expSel.dispatchEvent(new Event('change'));
            if (typeof handleExpenseCategoryChange === 'function') handleExpenseCategoryChange();
        }
    } catch (e) { /* prefill is a convenience — never block the form */ }
});

function updateExpenseTitle() {
    const expenseCategorySelect = document.getElementById('expense_category');
    const hiddenTitle = document.getElementById('hidden-title');
    const selectedExpense = expenseCategorySelect.value;
    
    if (selectedExpense && selectedExpense !== '__ADD_NEW__') {
        // Set title to the selected expense category
        hiddenTitle.value = selectedExpense;
    } else {
        // Fallback to "expense" if no category selected yet
        hiddenTitle.value = 'expense';
    }
}

function openInlineExpenseCategoryModal() {
    const modal = document.getElementById('inlineExpenseCategoryModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    // Focus the input
    setTimeout(() => document.getElementById('inline_category_name').focus(), 100);
}

function closeInlineExpenseCategoryModal() {
    const modal = document.getElementById('inlineExpenseCategoryModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    document.getElementById('inline_category_name').value = '';
}

function submitInlineCategory() {
    const categoryName = document.getElementById('inline_category_name').value.trim();
    
    if (!categoryName) {
        alert('Please enter a category name');
        return;
    }
    
    // Show loading
    const submitBtn = document.querySelector('#inlineExpenseCategoryModal button[type="button"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '⏳ Creating...';
    submitBtn.disabled = true;
    
    // Submit via AJAX
    fetch('{{ route("fin.expense-category.store") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({ category_name: categoryName })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success || data.message.includes('successfully')) {
            // Add to dropdown
            const expenseCategorySelect = document.getElementById('expense_category');
            const newOption = document.createElement('option');
            newOption.value = categoryName;
            newOption.textContent = categoryName;
            
            // Insert before the "Add New" option
            const addNewOption = expenseCategorySelect.querySelector('option[value="__ADD_NEW__"]');
            expenseCategorySelect.insertBefore(newOption, addNewOption);
            
            // Select the new option
            expenseCategorySelect.value = categoryName;
            updateExpenseTitle();
            
            // Close modal
            closeInlineExpenseCategoryModal();
            document.getElementById('inline_category_name').value = '';
            
            alert('✓ Category "' + categoryName + '" created successfully!');
        } else {
            alert('Error: ' + (data.message || 'Failed to create category'));
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    })
    .finally(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
}

document.getElementById('request-form').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const data = Object.fromEntries(formData.entries());
    
    // Show loading state
    const submitBtn = this.querySelector('[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="ki-filled ki-loading animate-spin"></i> Submitting...';
    
    fetch('{{ route("requests.store") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('[name="_token"]').value
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Request submitted successfully!');
            window.location.href = '/requests/' + data.request_id;
        } else {
            alert('Error: ' + data.message);
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
});
</script>

<!-- Inline Expense Category Modal - Fixed Centering -->
<div id="inlineExpenseCategoryModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); z-index: 99999; align-items: center; justify-content: center; padding: 20px;" onclick="closeInlineExpenseCategoryModal()">
    <div style="background: white; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); max-width: 450px; width: 100%; margin: auto;" onclick="event.stopPropagation()">
        <!-- Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(135deg, #f3e8ff 0%, #ffffff 100%); border-radius: 12px 12px 0 0;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h2 style="font-size: 18px; font-weight: 600; color: #1f2937; margin: 0;">➕ Add New Expense Category</h2>
                <button onclick="closeInlineExpenseCategoryModal()" style="background: none; border: none; color: #9ca3af; font-size: 28px; cursor: pointer; line-height: 1; padding: 0;">&times;</button>
            </div>
        </div>
        
        <!-- Body -->
        <div style="padding: 24px;">
            <div style="margin-bottom: 20px;">
                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Category Name <span style="color: #ef4444;">*</span></label>
                <input type="text" id="inline_category_name"
                       style="width: 100%; padding: 12px 16px; border: 2px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box; transition: border-color 0.2s;"
                       onfocus="this.style.borderColor='#8b5cf6'"
                       onblur="this.style.borderColor='#d1d5db'"
                       placeholder="e.g., Fuel, Marketing, Travel">
            </div>
            
            <div style="padding: 16px; background: #f5f3ff; border: 1px solid #ddd6fe; border-radius: 8px; margin-bottom: 24px;">
                <p style="font-size: 12px; color: #6d28d9; margin: 0 0 8px 0; font-weight: 600;">
                    ℹ️ System will automatically:
                </p>
                <ul style="font-size: 12px; color: #7c3aed; margin: 0; padding-left: 20px;">
                    <li style="margin-bottom: 4px;">Create an expense account (e.g., EXP_FUEL)</li>
                    <li style="margin-bottom: 4px;">Add to expense type dropdown</li>
                    <li>Make it available for all expense requests</li>
                </ul>
            </div>
            
            <div style="display: flex; gap: 12px;">
                <button type="button" onclick="closeInlineExpenseCategoryModal()" 
                        style="flex: 1; padding: 12px 20px; border: 1px solid #d1d5db; background: white; color: #374151; font-weight: 500; border-radius: 8px; cursor: pointer; font-size: 14px;">
                    Cancel
                </button>
                <button type="button" onclick="submitInlineCategory()" 
                        style="flex: 1; padding: 12px 20px; border: none; background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); color: white; font-weight: 600; border-radius: 8px; cursor: pointer; font-size: 14px; box-shadow: 0 4px 6px -1px rgba(139, 92, 246, 0.3);">
                    ✓ Create & Select
                </button>
            </div>
        </div>
    </div>
</div>

@endsection

