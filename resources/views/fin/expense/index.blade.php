@extends('layouts.app')

@section('title', 'Expense Management')

@section('content')
<style>
@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

#newRequestModal .form-field-enhanced {
    transition: all 0.2s ease;
}

#newRequestModal .form-field-enhanced:focus {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.15);
}

#newRequestModal .radio-card {
    transition: all 0.2s ease;
}

#newRequestModal .radio-card:hover {
    transform: translateX(4px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

#newRequestModal .radio-card input:checked + span {
    color: #059669;
    font-weight: 600;
}
</style>

<script>
window._isTaimurRole = {{ (!empty($isTaimurRole) && $isTaimurRole) ? 'true' : 'false' }};

// Define openNewRequestModal FIRST before any HTML that uses it
function openNewRequestModal() {
    const modal = document.getElementById('newRequestModal');
    if (!modal) {
        alert('Form not ready. Please refresh the page.');
        return;
    }

    // Refresh expense_date to the browser's local "today" so it stays
    // correct even if the page was loaded hours ago or the server
    // timezone differs from the user's wall clock.
    const dateInput = document.getElementById('quick_expense_date');
    if (dateInput) {
        const now = new Date();
        const ymd = now.getFullYear() + '-' +
                    String(now.getMonth() + 1).padStart(2, '0') + '-' +
                    String(now.getDate()).padStart(2, '0');
        dateInput.value = ymd;
        if (dateInput.getAttribute('max')) dateInput.max = ymd;
    }
    
    // Make modal visible
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeNewRequestModal() {
    const modal = document.getElementById('newRequestModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
        
        // Reset form
        document.getElementById('quickRequestForm').reset();
    }
}

// Quick-launch helper that opens the New Request modal pre-filled for a
// Qurbani expense — Business Unit = NF (id 1), Request Type = Qurbani,
// Pay From Account = Qurbani Online. Saves finance users from clicking
// the same dropdowns every time during the Qurbani season.
//
// Order of operations matters here:
//   1. Open the modal (renders all dropdowns).
//   2. Force BU to NF (id=1) and trigger onBusinessUnitChanged so the
//      Request Type list filters down to NF-eligible categories.
//   3. Select the qurbani category by data-code and call
//      handleQuickCategoryChange — that's what reveals the Pay From
//      and Expense Type fields and runs filterPaymentSourcesByBU.
//   4. Finally, with Pay From visible, find the QURBANI_ONLINE option
//      and select it.
function openQurbaniExpenseModal() {
    openNewRequestModal();

    // Defer a tick so the DOM finishes rendering / the modal animation
    // doesn't interfere with focus, then walk the dropdown chain.
    setTimeout(function() {
        // Step 1: Business Unit = NF (1). The select might not exist if
        // the user only has one BU in scope (the field is conditionally
        // rendered). In that case BU is implicitly NF and we skip.
        var buSelect = document.getElementById('quick_business_unit');
        if (buSelect) {
            var nfOption = Array.from(buSelect.options).find(function(o){return o.value === '1';});
            if (nfOption) {
                buSelect.value = '1';
                if (typeof onBusinessUnitChanged === 'function') onBusinessUnitChanged();
            }
        }

        // Step 2: Request Type = qurbani (matched on data-code).
        var catSelect = document.getElementById('quick_category_id');
        if (catSelect) {
            var qOpt = Array.from(catSelect.options).find(function(o){
                return (o.dataset && o.dataset.code === 'qurbani') && !o.disabled;
            });
            if (qOpt) {
                catSelect.value = qOpt.value;
                if (typeof handleQuickCategoryChange === 'function') handleQuickCategoryChange();
            } else {
                console.warn('[QurbaniExpense] qurbani category not found in dropdown — was the migration run?');
            }
        }

        // Step 3: Pay From Account = QURBANI_ONLINE. handleQuickCategoryChange
        // already revealed the field and ran filterPaymentSourcesByBU; we
        // just need to pick the right option among the visible ones.
        var paySelect = document.getElementById('quick_payment_source');
        if (paySelect) {
            var qAcct = Array.from(paySelect.options).find(function(o){
                return o.dataset && o.dataset.accountCode === 'QURBANI_ONLINE' && !o.disabled;
            });
            if (qAcct) {
                paySelect.value = qAcct.value;
            } else {
                console.warn('[QurbaniExpense] QURBANI_ONLINE account not visible — falling back to default');
            }
        }
    }, 50);
}

// Make globally available
window.openNewRequestModal = openNewRequestModal;
window.closeNewRequestModal = closeNewRequestModal;
window.openQurbaniExpenseModal = openQurbaniExpenseModal;

console.log('[NewRequest] Functions defined and ready');

// Attach click handler when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    const newRequestBtn = document.getElementById('newRequestBtn');
    if (newRequestBtn) {
        newRequestBtn.addEventListener('click', openNewRequestModal);
        console.log('[NewRequest] Click handler attached');
    }
    // Load banks + refresh the receiving-bank picker whenever the pay-from
    // account changes (shows only for ONLINE bank sources).
    if (typeof loadExpenseBanks === 'function') { loadExpenseBanks(); }
    const _paySel = document.getElementById('quick_payment_source');
    if (_paySel) {
        _paySel.addEventListener('change', function() {
            if (typeof renderExpenseBankPicker === 'function') { renderExpenseBankPicker(); }
        });
    }
    const qurbaniExpenseBtn = document.getElementById('qurbaniExpenseBtn');
    if (qurbaniExpenseBtn) {
        qurbaniExpenseBtn.addEventListener('click', openQurbaniExpenseModal);
        console.log('[QurbaniExpense] Quick-launch handler attached');
    }
    // Auto-open new request modal if ?auto_new=1 is in URL (shortcut from sidebar)
    if (new URLSearchParams(window.location.search).get('auto_new') === '1') {
        setTimeout(function() { openNewRequestModal(); }, 300);
        window.history.replaceState({}, '', window.location.pathname);
    }
    // Same shortcut for Qurbani: ?auto_qurbani=1 lands users straight on
    // the pre-filled form (useful for sidebar / dashboard cards later).
    if (new URLSearchParams(window.location.search).get('auto_qurbani') === '1') {
        setTimeout(function() { openQurbaniExpenseModal(); }, 300);
        window.history.replaceState({}, '', window.location.pathname);
    }
});
</script>

<div class="container-fluid px-6 py-6">
    <!-- Page Header -->
    @php
        // Detect whether the Qurbani request type is configured + active.
        // Drives the "Qurbani Expense" quick button next to "New Request".
        // The button pre-fills Request Type=Qurbani + Pay From=Qurbani Online
        // so finance users don't have to walk through the dropdowns every
        // time during the Qurbani season.
        try {
            $hasQurbaniRequestType = \App\Models\Request\RequestCategoryModel::where('category_code', 'qurbani')
                ->where('is_active', 1)
                ->where('show_in_expenses', 1)
                ->exists();
        } catch (\Exception $e) {
            $hasQurbaniRequestType = false;
        }
    @endphp
    @php
        $qurbaniMode    = isset($qurbaniMode) ? (bool) $qurbaniMode : false;
        $availableYears = $availableYears ?? null;
        $currentYear    = $currentYear ?? null;
    @endphp
    <div class="flex items-center justify-between mb-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">
                @if($qurbaniMode)
                    🐐 Qurbani Expenses
                @else
                    💰 Expense Management
                @endif
            </h1>
            <p class="text-sm text-gray-600 mt-1">
                @if($qurbaniMode)
                    Year-accumulating view of Qurbani spend &amp; revenue · regular expenses live on the other tab
                @else
                    Track all expenses and manage requests
                @endif
            </p>
        </div>
        <div class="flex items-center gap-2">
            @if($hasQurbaniRequestType)
            <button id="qurbaniExpenseBtn" type="button" role="button"
                    class="px-4 py-2 bg-amber-50 border border-amber-500 text-amber-800 hover:bg-amber-100 hover:text-amber-900 text-sm font-semibold rounded-lg shadow-sm transition-colors flex items-center gap-2"
                    title="Quick request: Request Type set to Qurbani, Pay From set to Qurbani Online">
                <span class="text-lg">🐐</span>
                <span class="font-bold">Qurbani Expense</span>
            </button>
            @endif
            <button id="newRequestBtn" type="button" role="button"
                    class="px-4 py-2 bg-white border border-blue-600 text-blue-600 hover:bg-blue-50 hover:text-blue-700 text-sm font-semibold rounded-lg shadow-sm transition-colors flex items-center gap-2">
                <span class="text-lg">➕</span>
                <span class="font-bold">New Request</span>
            </button>
        </div>
    </div>

    {{-- May-2026 (Phase 3) — Regular vs Qurbani tabs. Both routes
         render this same view; the controller flips $qurbaniMode and
         narrows the data accordingly. --}}
    <div class="flex items-center gap-1 border-b border-gray-200 mb-4">
        <a href="{{ route('fin.expenses.index') }}"
           class="px-4 py-2 text-sm font-semibold rounded-t-md border-b-2 -mb-[2px] {{ $qurbaniMode ? 'text-gray-500 border-transparent hover:text-gray-700 hover:border-gray-300' : 'text-blue-700 border-blue-600 bg-blue-50' }}">
            📊 Regular Expenses
        </a>
        <a href="{{ route('fin.expenses.qurbani') }}"
           class="px-4 py-2 text-sm font-semibold rounded-t-md border-b-2 -mb-[2px] {{ $qurbaniMode ? 'text-amber-800 border-amber-600 bg-amber-50' : 'text-gray-500 border-transparent hover:text-gray-700 hover:border-gray-300' }}">
            🐐 Qurbani Expenses
        </a>
    </div>

    @if($qurbaniMode)
        @php
            $qBooked  = $qurbaniBooked  ?? 0;
            $qPaid    = $qurbaniPaid    ?? 0;
            $qPending = $qurbaniPending ?? 0;
            $paidPct  = $qBooked > 0 ? round(($qPaid / $qBooked) * 100) : 0;
        @endphp
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-3 mb-4">
            <div class="bg-white border border-gray-200 rounded-lg p-3">
                <div class="text-xs text-gray-500 uppercase font-medium">📅 Year</div>
                <form method="get" action="{{ route('fin.expenses.qurbani') }}" class="mt-1">
                    <select name="year" onchange="this.form.submit()"
                            class="w-full border border-gray-300 rounded-md px-2 py-1.5 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-amber-500">
                        @foreach(($availableYears ?: [(int) date('Y')]) as $y)
                            <option value="{{ $y }}" {{ ($currentYear ?? (int) date('Y')) === (int) $y ? 'selected' : '' }}>
                                {{ $y }}
                            </option>
                        @endforeach
                    </select>
                </form>
                <div class="text-xs text-gray-500 mt-1">All Qurbani activity for selected year</div>
            </div>
            {{-- Booked revenue card — every non-cancelled qurbani
                 order placed this year, with a Paid / Pending split
                 driven by `t_crm_prod_order.total_paid`. The thin
                 progress bar at the bottom mirrors the same ratio so
                 the operator can scan collection health at a glance. --}}
            <div class="bg-emerald-50 border border-emerald-300 rounded-lg p-3">
                <div class="text-xs text-emerald-700 uppercase font-medium">🧾 Booked Revenue</div>
                <div class="text-lg font-bold text-emerald-900 mt-1">Rs. {{ number_format($qBooked, 2) }}</div>
                <div class="flex items-center justify-between text-xs mt-1">
                    <span class="text-emerald-700 font-semibold">✅ Paid Rs. {{ number_format($qPaid, 0) }}</span>
                    <span class="text-amber-700 font-semibold">⏳ Pending Rs. {{ number_format($qPending, 0) }}</span>
                </div>
                <div class="mt-1.5 h-1.5 w-full bg-emerald-200 rounded-full overflow-hidden">
                    <div class="h-full bg-emerald-600" style="width: {{ $paidPct }}%"></div>
                </div>
                <div class="text-[10px] text-emerald-700 mt-1">{{ $paidPct }}% collected · non-cancelled orders</div>
            </div>
            <div class="bg-rose-50 border border-rose-300 rounded-lg p-3">
                <div class="text-xs text-rose-700 uppercase font-medium">💸 Qurbani Expenses</div>
                <div class="text-lg font-bold text-rose-900 mt-1">
                    Rs. {{ number_format($kpis['total_expenses'] ?? 0, 2) }}
                </div>
                <div class="text-xs text-rose-600 mt-1">{{ $allExpenses->count() }} approved request(s)</div>
            </div>
            <div class="bg-amber-50 border border-amber-300 rounded-lg p-3">
                <div class="text-xs text-amber-700 uppercase font-medium">🛒 Vendor Purchases</div>
                <div class="text-lg font-bold text-amber-900 mt-1">
                    Rs. {{ number_format(($qurbaniVendorPurchases ?? 0), 2) }}
                </div>
                <div class="text-xs text-amber-600 mt-1">Tied to Qurbani orders / requests</div>
            </div>
        </div>
        @php
            $qurbaniNet = $qBooked - ($kpis['total_expenses'] ?? 0) - ($qurbaniVendorPurchases ?? 0);
        @endphp
        <div class="bg-gradient-to-r from-amber-100 to-orange-100 border border-amber-300 rounded-lg p-3 mb-4 flex items-center justify-between">
            <div>
                <div class="text-xs text-amber-800 uppercase font-semibold">📈 Qurbani Net (Booked − Expenses − Vendor)</div>
                <div class="text-xs text-amber-700">Headline for Qurbani {{ $currentYear }} · Pending Rs. {{ number_format($qPending, 0) }} still to collect</div>
            </div>
            <div class="text-2xl font-extrabold {{ $qurbaniNet >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">
                Rs. {{ number_format($qurbaniNet, 2) }}
            </div>
        </div>
    @endif

    <!-- KPI Cards - Redesigned Layout: 4 cards (2x2) on left, 1 large card on right -->
    @php
        $expenseFund = \App\Models\FIN\ConfigModel::getExpenseFundingAccount() 
            ?? \App\Models\FIN\AccountModel::where('account_code', 'EXP_FUND')->first();
    @endphp
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 mb-4">
        <!-- Left Side: 4 Small Cards in 2x2 Grid -->
        <div class="lg:col-span-2 grid grid-cols-2 gap-3">
            <!-- Total Expenses (Filtered) -->
            <div class="bg-white border border-gray-200 rounded-lg p-3">
                <div class="text-xs text-gray-500 uppercase font-medium">📊 Total Expenses</div>
                <div class="text-lg font-bold text-gray-900 mt-1">Rs. {{ number_format($kpis['total_expenses'], 2) }}</div>
                <div class="text-xs text-gray-500 mt-1">
                    @if($dateFrom && $dateTo)
                        {{ date('M d', strtotime($dateFrom)) }} - {{ date('M d', strtotime($dateTo)) }}
                    @elseif($category)
                        {{ $category }}
                    @else
                        All time
                    @endif
                </div>
            </div>

            <!-- Pending Approvals (Real-time) - Clickable -->
            <div class="bg-yellow-50 border border-yellow-300 rounded-lg p-3 cursor-pointer hover:bg-yellow-100 transition-colors" 
                 onclick="openPendingApprovalsModal()"
                 title="Click to view and approve pending requests">
                <div class="text-xs text-yellow-700 uppercase font-medium">⏳ Pending Approvals</div>
                <div class="text-lg font-bold text-yellow-900 mt-1">Rs. {{ number_format($kpis['pending_approvals'], 2) }}</div>
                <div class="text-xs text-yellow-600 mt-1">
                    {{ $kpis['pending_approvals_count'] }} request(s)
                    <span class="ml-1">👆 Click</span>
                </div>
            </div>

            <!-- Approved Expenses (Real-time) -->
            <div class="bg-green-50 border border-green-300 rounded-lg p-3">
                <div class="text-xs text-green-700 uppercase font-medium">✅ Approved Expenses</div>
                <div class="text-lg font-bold text-green-900 mt-1">{{ $allExpenses->count() + ($kpis['salary_slips_count'] ?? 0) }}</div>
                <div class="text-xs text-green-600 mt-1">
                    @if($dateFrom && $dateTo)
                        {{ date('M d', strtotime($dateFrom)) }} - {{ date('M d', strtotime($dateTo)) }}
                    @else
                        This period
                    @endif
                </div>
            </div>

            <!-- Expense Fund Balance (Real-time) -->
            <div class="bg-blue-50 border border-blue-300 rounded-lg p-3">
                <div class="text-xs text-blue-700 uppercase font-medium">💰 Fund Balance</div>
                <div class="text-lg font-bold text-blue-900 mt-1">
                    Rs. {{ $expenseFund ? number_format($expenseFund->current_balance, 2) : '0.00' }}
                </div>
                <div class="text-xs text-blue-600 mt-1">Available</div>
            </div>
        </div>

        <!-- Right Side: Large Card with Top Categories + User Drill-down -->
        <div class="bg-gradient-to-br from-purple-50 to-indigo-50 border border-purple-200 rounded-lg p-4">
            <div class="flex items-center justify-between mb-3">
                <div class="text-sm text-purple-700 uppercase font-semibold">📊 Top Expense Categories</div>
                <span class="text-xs text-purple-600">Click to filter · ▶ to expand</span>
            </div>
            <div class="space-y-1 max-h-[260px] overflow-y-auto" id="categoriesList">
                @php
                    // Phase 4 — when both BUs are present, render a
                    // BU-first drill (NF / Khaas) above the legacy
                    // category list. The legacy list is hidden inside
                    // each BU group so behaviour is identical: click
                    // BU → expand → see categories → click cat → see
                    // users. The Khaas group disappears entirely if
                    // the user lacks access_khaas_mode (controller
                    // already strips it from $kpis['bu_breakdown']).
                    $buBreakdown = $kpis['bu_breakdown'] ?? [];
                    $buMeta = [
                        'NF'    => ['label' => 'NF Expenses',    'emoji' => '📦', 'tint' => 'blue'],
                        'KHAAS' => ['label' => 'Khaas Expenses', 'emoji' => '🌿', 'tint' => 'emerald'],
                    ];
                    $hasBuSplit = count(array_filter($buBreakdown, fn($p) => ($p['total'] ?? 0) > 0)) >= 1;
                    $activeBu = $buFilter ?? null;
                @endphp

                @if($hasBuSplit)
                    @foreach($buBreakdown as $buCode => $buPayload)
                        @if(($buPayload['total'] ?? 0) <= 0 && empty($buPayload['categories']))
                            @continue
                        @endif
                        @php
                            $meta = $buMeta[$buCode] ?? ['label' => $buCode, 'emoji' => '•', 'tint' => 'gray'];
                            $buId = 'bu_' . $buCode;
                            // Default-expand the active drilled BU so the
                            // user lands on it after clicking. Otherwise
                            // collapsed.
                            $expanded = $activeBu === $buCode;
                        @endphp
                        <div class="mb-1 border border-{{ $meta['tint'] }}-200 rounded">
                            <div class="flex items-center p-2 bg-{{ $meta['tint'] }}-50 hover:bg-{{ $meta['tint'] }}-100 transition-colors">
                                <button type="button" onclick="toggleCategory('{{ $buId }}')" class="w-5 h-5 flex items-center justify-center text-{{ $meta['tint'] }}-700 mr-1 flex-shrink-0" id="toggle_{{ $buId }}">
                                    {{ $expanded ? '▼' : '▶' }}
                                </button>
                                <span class="text-sm font-bold text-{{ $meta['tint'] }}-900 mr-2 flex-1 cursor-pointer" onclick="filterByBu('{{ $buCode }}')">
                                    {{ $meta['emoji'] }} {{ $meta['label'] }}
                                </span>
                                <span class="text-sm font-extrabold text-{{ $meta['tint'] }}-900 whitespace-nowrap">Rs. {{ number_format($buPayload['total'] ?? 0, 0) }}</span>
                            </div>
                            <div id="{{ $buId }}" class="{{ $expanded ? '' : 'hidden' }} pl-2 pr-2 py-1 bg-white">
                                @if(empty($buPayload['categories']))
                                    <div class="text-xs text-gray-400 px-2 py-1 italic">No expenses for this period.</div>
                                @endif
                                @foreach(($buPayload['categories'] ?? []) as $cat => $catData)
                                    @php
                                        $catTotal = is_array($catData) ? ($catData['total'] ?? 0) : $catData;
                                        $catUsers = is_array($catData) ? ($catData['users'] ?? []) : [];
                                        $hasUsers = count($catUsers) > 0;
                                        $catId = 'cat_' . $buCode . '_' . md5($cat);
                                    @endphp
                                    <div>
                                        <div class="flex items-center p-1.5 hover:bg-purple-50 rounded transition-colors">
                                            @if($hasUsers)
                                                <button type="button" onclick="toggleCategory('{{ $catId }}')" class="w-5 h-5 flex items-center justify-center text-gray-400 hover:text-purple-600 mr-1 flex-shrink-0" id="toggle_{{ $catId }}">▶</button>
                                            @else
                                                <span class="w-5 mr-1 flex-shrink-0"></span>
                                            @endif
                                            <span class="text-xs font-medium text-gray-700 truncate mr-2 flex-1 cursor-pointer" onclick="filterByCategory('{{ $cat }}')">{{ $cat }}</span>
                                            <span class="text-xs font-bold text-purple-900 whitespace-nowrap">Rs. {{ number_format($catTotal, 0) }}</span>
                                        </div>
                                        @if($hasUsers)
                                            <div id="{{ $catId }}" class="hidden ml-6 mb-1 pl-2 border-l-2 border-purple-200">
                                                @foreach($catUsers as $userName => $userAmount)
                                                    <div class="flex items-center justify-between py-1 px-2 hover:bg-purple-50 rounded cursor-pointer" onclick="filterByEmployee('{{ $userName }}')">
                                                        <span class="text-xs text-blue-600 hover:text-blue-800 truncate mr-2">↳ {{ $userName }}</span>
                                                        <span class="text-xs font-medium text-purple-700 whitespace-nowrap">Rs. {{ number_format($userAmount, 0) }}</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                @else
                    {{-- Fallback to the legacy flat layout when neither
                         BU has any spend (covers fresh installs / empty
                         filter ranges). --}}
                    @foreach($kpis['top_categories'] ?? [] as $cat => $catData)
                        @php
                            $catTotal = is_array($catData) ? ($catData['total'] ?? 0) : $catData;
                            $catUsers = is_array($catData) ? ($catData['users'] ?? []) : [];
                            $hasUsers = count($catUsers) > 0;
                            $catId = 'cat_' . md5($cat);
                        @endphp
                        <div>
                            <div class="flex items-center p-2 bg-white rounded hover:bg-purple-100 transition-colors border border-transparent hover:border-purple-300 group">
                                @if($hasUsers)
                                    <button type="button" onclick="toggleCategory('{{ $catId }}')" class="w-5 h-5 flex items-center justify-center text-gray-400 hover:text-purple-600 mr-1 flex-shrink-0 transition-transform" id="toggle_{{ $catId }}">▶</button>
                                @else
                                    <span class="w-5 mr-1 flex-shrink-0"></span>
                                @endif
                                <span class="text-xs font-medium text-gray-700 truncate mr-2 flex-1 cursor-pointer" onclick="filterByCategory('{{ $cat }}')">{{ $cat }}</span>
                                <span class="text-xs font-bold text-purple-900 whitespace-nowrap">Rs. {{ number_format($catTotal, 0) }}</span>
                            </div>
                            @if($hasUsers)
                                <div id="{{ $catId }}" class="hidden ml-6 mb-1 pl-2 border-l-2 border-purple-200">
                                    @foreach($catUsers as $userName => $userAmount)
                                        <div class="flex items-center justify-between py-1 px-2 hover:bg-purple-50 rounded cursor-pointer" onclick="filterByEmployee('{{ $userName }}')">
                                            <span class="text-xs text-blue-600 hover:text-blue-800 truncate mr-2">↳ {{ $userName }}</span>
                                            <span class="text-xs font-medium text-purple-700 whitespace-nowrap">Rs. {{ number_format($userAmount, 0) }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                @endif
            </div>
        </div>
    </div>

    <!-- Filter Bar - Redesigned Simple & Effective -->
    @if(!$qurbaniMode && !empty($buFilter))
        {{-- Phase 4 — active BU drill chip. Lets the user see which
             half of the page they're scoped into and click out. --}}
        <div class="flex items-center gap-2 mb-3">
            <span class="text-xs text-gray-500">Drilled into:</span>
            <span class="inline-flex items-center gap-2 px-3 py-1 bg-{{ $buFilter === 'KHAAS' ? 'emerald' : 'blue' }}-100 border border-{{ $buFilter === 'KHAAS' ? 'emerald' : 'blue' }}-300 text-{{ $buFilter === 'KHAAS' ? 'emerald' : 'blue' }}-800 rounded-full text-xs font-semibold">
                {{ $buFilter === 'KHAAS' ? '🌿 Khaas Expenses' : '📦 NF Expenses' }}
                <button type="button" onclick="clearBuFilter()" class="text-{{ $buFilter === 'KHAAS' ? 'emerald' : 'blue' }}-600 hover:text-{{ $buFilter === 'KHAAS' ? 'emerald' : 'blue' }}-900">✕</button>
            </span>
        </div>
    @endif

    <div class="bg-white border border-gray-200 rounded-lg p-4 mb-4">
        <form method="GET" action="{{ route('fin.expenses.index') }}" id="filterForm">
            {{-- Persist the active BU drill across other filter
                 changes (date / category / status / payment source). --}}
            <input type="hidden" name="bu" value="{{ $buFilter ?? '' }}">
            <div class="flex flex-wrap items-end gap-3">
                <!-- Quick Month Selector -->
                <div class="flex-shrink-0">
                    <label class="text-xs font-medium text-gray-700 block mb-1">📅 Quick Select</label>
                    <select id="quickMonthSelect" onchange="setMonthRange(this.value)" class="rounded border-gray-300 text-sm py-2 px-3 focus:ring-2 focus:ring-blue-500">
                        <option value="">Custom Range</option>
                        <option value="current_month" {{ !request()->has('date_from') && !request()->has('date_to') ? 'selected' : '' }}>This Month</option>
                        <option value="last_month">Last Month</option>
                        <option value="last_3_months">Last 3 Months</option>
                        <option value="last_6_months">Last 6 Months</option>
                        <option value="this_year">This Year</option>
                    </select>
                </div>

                <!-- Date Range -->
                <div class="flex items-center gap-2">
                    <div>
                        <label class="text-xs font-medium text-gray-700 block mb-1">From</label>
                        <input type="date" name="date_from" id="dateFrom" value="{{ $dateFrom }}" 
                               class="rounded border-gray-300 text-sm py-2 px-3 focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="self-end pb-2 text-gray-400">→</div>
                    <div>
                        <label class="text-xs font-medium text-gray-700 block mb-1">To</label>
                        <input type="date" name="date_to" id="dateTo" value="{{ $dateTo }}" 
                               class="rounded border-gray-300 text-sm py-2 px-3 focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>

                <!-- Request Type -->
                @if(isset($requestTypes) && $requestTypes->count() > 1)
                <div>
                    <label class="text-xs font-medium text-gray-700 block mb-1">Request Type</label>
                    <select name="request_type" class="rounded border-gray-300 text-sm py-2 px-3 focus:ring-2 focus:ring-blue-500">
                        <option value="" {{ empty($requestType) ? 'selected' : '' }}>All Types</option>
                        @foreach($requestTypes as $rt)
                            <option value="{{ $rt->category_code }}" {{ !empty($requestType) && $requestType == $rt->category_code ? 'selected' : '' }}>
                                {{ $rt->category_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                @endif

                <!-- Category -->
                <div>
                    <label class="text-xs font-medium text-gray-700 block mb-1">Category</label>
                    <select name="category" id="category" class="rounded border-gray-300 text-sm py-2 px-3 focus:ring-2 focus:ring-blue-500">
                        <option value="" {{ empty($category) ? 'selected' : '' }}>All Categories</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat }}" {{ !empty($category) && $category == $cat ? 'selected' : '' }}>{{ $cat }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Payment Source -->
                <div>
                    <label class="text-xs font-medium text-gray-700 block mb-1">Payment Source</label>
                    <select name="payment_source" class="rounded border-gray-300 text-sm py-2 px-3 focus:ring-2 focus:ring-blue-500">
                        <option value="">All Sources</option>
                        @foreach($paymentSources as $source)
                            <option value="{{ $source->id }}" {{ $paymentSource == $source->id ? 'selected' : '' }}>
                                {{ $source->account_name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Hidden employee filter -->
                <input type="hidden" name="employee" id="employeeFilter" value="{{ $employeeFilter ?? '' }}">

                <!-- Employee Filter Badge -->
                @if(!empty($employeeFilter))
                <div class="flex items-center">
                    <span class="inline-flex items-center gap-1 px-3 py-1.5 bg-indigo-100 text-indigo-800 text-xs font-medium rounded-full">
                        👤 {{ $employeeFilter }}
                        <button type="button" onclick="clearEmployeeFilter()" class="ml-1 text-indigo-500 hover:text-indigo-800">&times;</button>
                    </span>
                </div>
                @endif

                <!-- Action Buttons -->
                <div class="flex gap-2 ml-auto">
                    <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg shadow-sm transition-colors">
                        🔍 Apply Filters
                    </button>
                    <button type="button" onclick="clearFilters()" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-lg transition-colors">
                        ✕ Clear
                    </button>
                </div>
            </div>
        </form>
    </div>
    
    <script>
    // Quick month selector helper
    function setMonthRange(period) {
        const dateFrom = document.getElementById('dateFrom');
        const dateTo = document.getElementById('dateTo');
        const today = new Date();
        
        switch(period) {
            case 'current_month':
                dateFrom.value = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0];
                dateTo.value = new Date(today.getFullYear(), today.getMonth() + 1, 0).toISOString().split('T')[0];
                break;
            case 'last_month':
                dateFrom.value = new Date(today.getFullYear(), today.getMonth() - 1, 1).toISOString().split('T')[0];
                dateTo.value = new Date(today.getFullYear(), today.getMonth(), 0).toISOString().split('T')[0];
                break;
            case 'last_3_months':
                dateFrom.value = new Date(today.getFullYear(), today.getMonth() - 3, 1).toISOString().split('T')[0];
                dateTo.value = today.toISOString().split('T')[0];
                break;
            case 'last_6_months':
                dateFrom.value = new Date(today.getFullYear(), today.getMonth() - 6, 1).toISOString().split('T')[0];
                dateTo.value = today.toISOString().split('T')[0];
                break;
            case 'this_year':
                dateFrom.value = new Date(today.getFullYear(), 0, 1).toISOString().split('T')[0];
                dateTo.value = today.toISOString().split('T')[0];
                break;
        }
        
        // Auto-submit if a quick period is selected
        if (period) {
            document.getElementById('filterForm').submit();
        }
    }
    
    // Toggle category user drill-down
    function toggleCategory(catId) {
        const el = document.getElementById(catId);
        const toggle = document.getElementById('toggle_' + catId);
        if (el) {
            const isHidden = el.classList.contains('hidden');
            el.classList.toggle('hidden');
            if (toggle) {
                toggle.style.transform = isHidden ? 'rotate(90deg)' : '';
            }
        }
    }
    
    // Filter by category (from top 10 categories card)
    function filterByCategory(category) {
        const form = document.getElementById('filterForm');
        const categoryInput = document.getElementById('category');
        
        if (categoryInput) {
            categoryInput.value = category;
            form.submit();
        } else {
            // If category input doesn't exist in form, add it
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'category';
            input.value = category;
            form.appendChild(input);
            form.submit();
        }
    }
    
    function filterByEmployee(name) {
        const form = document.getElementById('filterForm');
        document.getElementById('employeeFilter').value = name;
        form.submit();
    }

    // Phase 4 — BU drill (NF / KHAAS). Adds a hidden `bu` field to
    // the filter form so the controller scopes every query to that
    // business unit. Reusing the same filterForm preserves date /
    // category / status / payment-source state through the drill.
    function filterByBu(buCode) {
        const form = document.getElementById('filterForm');
        let buInput = form.querySelector('input[name="bu"]');
        if (!buInput) {
            buInput = document.createElement('input');
            buInput.type = 'hidden';
            buInput.name = 'bu';
            form.appendChild(buInput);
        }
        // Toggle behaviour — clicking the same BU again clears the
        // drill so users have a quick way back to the combined view.
        const current = new URLSearchParams(window.location.search).get('bu');
        buInput.value = current === buCode ? '' : buCode;
        form.submit();
    }
    function clearBuFilter() {
        const form = document.getElementById('filterForm');
        let buInput = form.querySelector('input[name="bu"]');
        if (buInput) buInput.value = '';
        form.submit();
    }

    function clearEmployeeFilter() {
        const form = document.getElementById('filterForm');
        document.getElementById('employeeFilter').value = '';
        form.submit();
    }
    
    // Clear all filters
    function clearFilters() {
        window.location.href = '{{ route('fin.expenses.index') }}';
    }
    
    // On page load, ensure dropdown reflects the actual filter value
    document.addEventListener('DOMContentLoaded', function() {
        const categoryDropdown = document.getElementById('category');
        const urlParams = new URLSearchParams(window.location.search);
        const categoryParam = urlParams.get('category');
        
        if (categoryDropdown) {
            // If no category in URL or it's empty, set to "All Categories"
            if (!categoryParam || categoryParam === '') {
                categoryDropdown.value = '';
            } else {
                categoryDropdown.value = categoryParam;
            }
        }
    });
    </script>

    <!-- Expenses Table -->
    <div class="bg-white border border-gray-200 rounded-lg">
        <!-- Header -->
        <div class="border-b border-gray-200 px-6 py-4">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-900">📋 All Expenses ({{ $allExpensesForDisplay->count() }})</h3>
            </div>
        </div>

        <!-- Table Content -->
        <div class="p-6">
            <div id="content-all">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Request #</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Employee</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Paid From</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse($allExpensesForDisplay as $expense)
                            <tr class="hover:bg-gray-50 {{ isset($expense->type) && $expense->type === 'salary' ? 'bg-purple-50' : '' }}">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium {{ isset($expense->type) && $expense->type === 'salary' ? 'text-purple-600' : 'text-blue-600' }}">
                                    @if(isset($expense->type) && $expense->type === 'salary')
                                        {{ $expense->request_number }}
                                    @else
                                        <a href="javascript:void(0)" onclick="openRequestDetailModal({{ $expense->id }})" class="hover:underline cursor-pointer">
                                            {{ $expense->request_number }}
                                        </a>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ ($expense->expense_date ?? $expense->created_at)->format('M d, Y') }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    {{ $expense->requester->fullname ?? 'Unknown' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ $expense->expense_category ?? ($expense->category ? $expense->category->category_name : 'N/A') }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">
                                    Rs. {{ number_format($expense->amount, 2) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ $expense->paymentSourceAccount ? $expense->paymentSourceAccount->account_name : 'N/A' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    @if(isset($expense->type) && $expense->type === 'salary')
                                        <span class="px-2 py-1 text-xs font-medium bg-green-100 text-green-800 rounded">✅ {{ ucfirst($expense->status ?? 'Paid') }}</span>
                                    @else
                                        <span class="px-2 py-1 text-xs font-medium bg-green-100 text-green-800 rounded">✅ Approved</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <div class="flex items-center gap-2">
                                        @if(!isset($expense->type) || $expense->type !== 'salary')
                                            <a href="javascript:void(0)" onclick="openRequestDetailModal({{ $expense->id }})" 
                                               class="text-blue-600 hover:text-blue-800 text-xs font-medium">
                                                View
                                            </a>
                                        @else
                                            <span class="text-gray-400 text-xs">{{ ucfirst($expense->status) }}</span>
                                        @endif
                                        
                                        {{-- Delete button - only for non-salary expenses --}}
                                        @if(!isset($expense->type) || $expense->type !== 'salary')
                                            <button onclick="confirmDeleteExpense({{ $expense->id }}, '{{ $expense->request_number }}', {{ $expense->amount }})" 
                                                    class="text-red-500 hover:text-red-700 ml-2" 
                                                    title="Delete expense">
                                                🗑️
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="8" class="px-6 py-12 text-center text-gray-500">
                                    <div class="text-4xl mb-2">📭</div>
                                    <p>No expenses found matching your filters</p>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- New Request Modal -->
<div id="newRequestModal" class="hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 20px; overflow-y: auto;" onclick="if(event.target === this) closeNewRequestModal()">
    <div onclick="event.stopPropagation()" style="background: white; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 700px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
        <!-- Fixed Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(135deg, #d1fae5 0%, #ffffff 100%); flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: #6ee7b7; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    ➕
                </div>
                <div>
                    <h3 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">Create New Request</h3>
                    <p style="font-size: 12px; color: #6b7280; margin: 2px 0 0 0;">Quick request submission from Expense Management</p>
                </div>
            </div>
            <button type="button" onclick="closeNewRequestModal()" style="background: none; border: none; color: #9ca3af; font-size: 28px; line-height: 1; cursor: pointer; padding: 4px 8px;">&times;</button>
        </div>
        
        <!-- Scrollable Content -->
        <div id="newRequestModalQuick" style="overflow-y: auto; flex: 1; padding: 20px 24px;">
            <form id="quickRequestForm">
                @csrf
                
                @php
                    // Check if user can create requests for others
                    $canCreateForOthers = false;
                    $expenseBackdateDays = 0; // ⭐ Default: current date only
                    
                    if (auth()->check()) {
                        $userRoles = \DB::table('t_sys_user_role as ur')
                            ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                            ->where('ur.user_id', auth()->id())
                            ->select('r.type', 'r.urole_name', 'r.expense_backdate_days')
                            ->get();
                        
                        foreach ($userRoles as $roleInfo) {
                            if (in_array(strtolower($roleInfo->type ?? ''), ['admin', 'manager', 'supervisor'])) {
                                $canCreateForOthers = true;
                            }
                            // ⭐ Get the maximum backdate days from any of user's roles
                            $expenseBackdateDays = max($expenseBackdateDays, (int)($roleInfo->expense_backdate_days ?? 0));
                        }
                    }
                    
                    // Get request categories configured to show in expense management.
                    // Graceful fallback: if show_in_expenses column doesn't exist yet, use hardcoded list.
                    try {
                        $limitedCategories = \App\Models\Request\RequestCategoryModel::with('approvalConfig')
                            ->where('show_in_expenses', 1)
                            ->where('is_active', 1)
                            ->orderBy('sequence_order')
                            ->get();
                    } catch (\Exception $e) {
                        $limitedCategories = \App\Models\Request\RequestCategoryModel::with('approvalConfig')
                            ->whereIn('category_code', ['expense', 'salary_advance', 'leave', 'khaas_expense'])
                            ->where('is_active', 1)
                            ->orderBy('sequence_order')
                            ->get();
                    }
                @endphp
                
                <!-- Step 1: Create For (if admin/manager) -->
                       @if($canCreateForOthers)
                       <div class="mb-6 p-5 bg-gradient-to-br from-blue-50 to-blue-100 border-2 border-blue-200 rounded-xl shadow-sm">
                           <label class="block text-base font-bold text-blue-900 mb-4 flex items-center gap-2">
                               <span class="text-xl">👤</span>
                               <span>Create Request For:</span>
                           </label>
                           <div class="space-y-3">
                               <label class="radio-card flex items-center p-4 bg-white border-2 border-blue-200 rounded-xl cursor-pointer hover:border-blue-400 shadow-sm">
                                   <input type="radio" name="request_for" value="myself" checked onchange="handleRequestForChange()" class="w-5 h-5 text-blue-600">
                                   <span class="ml-3 text-base font-medium text-gray-900">Myself</span>
                               </label>
                               <label class="radio-card flex items-center p-4 bg-white border-2 border-blue-200 rounded-xl cursor-pointer hover:border-blue-400 shadow-sm">
                                   <input type="radio" name="request_for" value="someone_else" onchange="handleRequestForChange()" class="w-5 h-5 text-blue-600">
                                   <span class="ml-3 text-base font-medium text-gray-900">Someone Else</span>
                               </label>
                           </div>
                    
                    <div id="userSelectField" class="mt-3" style="display: none;">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Select Employee</label>
                        <select name="requester_user_id" id="requester_user_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">-- Select Employee --</option>
                            @php
                                $activeUsers = \DB::table('t_sys_user')
                                    ->where('is_active', 1)
                                    ->whereNotIn('id', [auth()->id()])
                                    ->orderBy('fullname')
                                    ->get();
                            @endphp
                            @foreach($activeUsers as $user)
                            <option value="{{ $user->id }}">{{ $user->fullname }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                @endif

                       <!-- ⭐ Business Unit (moved above Request Type so it filters request types) -->
                       @if(count($businessUnits ?? []) > 1)
                       <div id="quick-business-unit-field-top" class="mb-6">
                           <label class="block text-base font-semibold text-gray-800 mb-3 flex items-center gap-2">
                               <span>💼 Business Unit</span>
                           </label>
                           <select name="business_unit_id" id="quick_business_unit" onchange="onBusinessUnitChanged()" class="form-field-enhanced w-full px-4 py-3 border-2 border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 text-base font-medium bg-white shadow-sm">
                               @foreach($businessUnits ?? [] as $bu)
                                   <option value="{{ $bu->id }}" {{ $bu->id == ($userDefaultBuId ?? 1) ? 'selected' : '' }} style="color: {{ $bu->color_hex ?? '#374151' }}">
                                       {{ $bu->name }} {{ $bu->short_code ? '(' . $bu->short_code . ')' : '' }}
                                   </option>
                               @endforeach
                           </select>
                           <p class="text-xs text-gray-500 mt-1">Select which business unit this request belongs to</p>
                       </div>
                       @endif
                
                       <!-- Request Category (Limited) -->
                       <div class="mb-6">
                           <label class="block text-base font-semibold text-gray-800 mb-3 flex items-center gap-2">
                               <span>Request Type</span>
                               <span class="text-red-500 text-lg">*</span>
                           </label>
                           <select id="quick_category_id" name="category_id" required onchange="handleQuickCategoryChange()" class="form-field-enhanced w-full px-4 py-3 border-2 border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 text-base font-medium bg-white shadow-sm">
                        <option value="">Select Request Type</option>
                        @foreach($limitedCategories as $category)
                        @php
                            $buTypeFallback = $category->expense_bu_type ?? ($category->category_code === 'khaas_expense' ? 'khaas' : 'nf');
                            $formTypeFallback = $category->form_type ?? (in_array($category->category_code, ['expense', 'khaas_expense']) ? 'expense' : ($category->category_code === 'salary_advance' ? 'salary' : ($category->category_code === 'leave' ? 'leave' : 'general')));
                        @endphp
                        <option value="{{ $category->id }}" 
                                data-code="{{ $category->category_code }}"
                                data-form-type="{{ $formTypeFallback }}"
                                data-bu-type="{{ $buTypeFallback }}"
                                data-requires-l1="{{ $category->requiresLevel1() ? '1' : '0' }}"
                                data-requires-l2="{{ $category->requiresLevel2() ? '1' : '0' }}">
                            {{ $category->category_name }}
                        </option>
                        @endforeach
                    </select>
                    <div class="mt-2 text-sm text-gray-600" id="quick-approval-info"></div>
                </div>

                <!-- Leave Fields -->
                <div id="quick-leave-fields" style="display: none;">
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Start Date <span class="text-red-500">*</span></label>
                            <input type="date" name="leave_start_date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500" onchange="calculateQuickLeaveDays()">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">End Date <span class="text-red-500">*</span></label>
                            <input type="date" name="leave_end_date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500" onchange="calculateQuickLeaveDays()">
                        </div>
                    </div>
                    <input type="hidden" name="leave_type" value="planned">
                    <div id="quick-leave-days-info" class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-lg" style="display: none;">
                        <span class="text-sm text-blue-800 font-medium" id="quick-leave-days-text"></span>
                    </div>
                </div>

                       <!-- Expense Type (for Expense Reimbursement) -->
                       <div id="quick-expense-category-field" style="display: none;" class="mb-6">
                           <label class="block text-base font-semibold text-gray-800 mb-3 flex items-center gap-2">
                               <span>Expense Type</span>
                               <span class="text-red-500 text-lg">*</span>
                               @if(\App\Services\FIN\ExpenseTypeService::canManage())
                                   {{-- ⭐ Oct-2026: rename / turn off / order / group, right where the list is used --}}
                                   <button type="button" class="nfet-trigger" onclick="NFExpenseTypes.open('quick_expense_category')" title="Rename, turn off, reorder or group expense types">✎ Manage types</button>
                               @endif
                           </label>
                           <select name="expense_category" id="quick_expense_category" class="form-field-enhanced w-full px-4 py-3 border-2 border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 text-base font-medium bg-white shadow-sm" onchange="handleQuickExpenseCategoryChange()">
                        <option value="">Select Expense Type</option>
                        {{-- Oct-2026: active types from the one engine (ExpenseTypeService), Taimur's order.
                             NFExpenseTypes re-renders this list with group headings for the chosen BU + type. --}}
                        @foreach(\App\Services\FIN\ExpenseTypeService::webPayload() as $cat)
                            <option value="{{ $cat['name'] }}" data-business-unit-id="{{ $cat['bu'] ?? 1 }}" data-request-type="{{ $cat['rcc'] ?? '' }}">{{ $cat['name'] }}</option>
                        @endforeach
                        <option value="__ADD_NEW__" style="background-color: #f3f4f6; font-weight: bold; color: #059669;">➕ Add New Category...</option>
                    </select>
                    @include('fin.expense-types._manager')
                    <script>
                        NFExpenseTypes.register('quick_expense_category', {
                            scope: function () {
                                var bu = document.getElementById('quick_business_unit');
                                var cat = document.getElementById('quick_category_id');
                                var opt = cat ? cat.options[cat.selectedIndex] : null;
                                return {bu: bu ? bu.value : 1, rcc: (opt && opt.dataset && opt.dataset.code) || 'expense'};
                            },
                            label: function () {
                                var bu = document.getElementById('quick_business_unit');
                                var cat = document.getElementById('quick_category_id');
                                var c = cat && cat.selectedIndex > 0 ? cat.options[cat.selectedIndex].text.trim() : 'Expense Reimbursement';
                                var b = bu && bu.selectedIndex >= 0 ? bu.options[bu.selectedIndex].text.trim() : '';
                                return b ? c + ' · ' + b : c;
                            }
                        });
                    </script>
                </div>

                       <!-- ⭐ Pay From Account (for Expense Reimbursement) - filtered by selected Business Unit -->
                       <div id="quick-pay-from-field" style="display: none;" class="mb-6">
                           <label class="block text-base font-semibold text-gray-800 mb-3 flex items-center gap-2">
                               <span>💳 Pay From Account</span>
                           </label>
                           @php $defaultPayFromCode = (!empty($isTaimurRole) && $isTaimurRole) ? 'ONLINE' : 'EXP_FUND'; @endphp
                           <select name="payment_source_account_id" id="quick_payment_source" class="form-field-enhanced w-full px-4 py-3 border-2 border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 text-base font-medium bg-white shadow-sm">
                               @foreach($accessibleCompanyAccounts ?? [] as $acc)
                                   <option value="{{ $acc->id }}"
                                       data-business-unit-id="{{ $acc->business_unit_id }}"
                                       data-account-code="{{ $acc->account_code }}"
                                       data-account-category="{{ $acc->account_category }}"
                                       {{ $acc->account_code == $defaultPayFromCode ? 'selected' : '' }}>
                                       {{ $acc->account_name }}
                                   </option>
                               @endforeach
                           </select>
                           <p class="text-xs text-gray-500 mt-1">Select which company account to debit for this expense</p>
                       </div>

                       <!-- ⭐ Receiving bank — only when the Pay-From account is an ONLINE bank -->
                       <div id="quick-expense-bank-field" style="display: none;" class="mb-6">
                           <label class="block text-base font-semibold text-gray-800 mb-3 flex items-center gap-2">
                               <span>🏦 Received in Bank</span>
                               <span class="text-red-500 text-lg">*</span>
                           </label>
                           <div id="quickExpenseBankChips" style="display:flex; flex-wrap:wrap; gap:6px;"></div>
                           <input type="hidden" name="receiving_account_id" id="quick_receiving_account_id">
                           <p class="text-xs text-gray-500 mt-1">Which bank this online payment is made from</p>
                       </div>

                       <!-- Amount (for Expense & Salary Advance) -->
                       <div id="quick-amount-field" style="display: none;" class="mb-6">
                           <label class="block text-base font-semibold text-gray-800 mb-3 flex items-center gap-2">
                               <span>Amount (Rs.)</span>
                               <span class="text-red-500 text-lg">*</span>
                           </label>
                           <input type="number" name="amount" class="form-field-enhanced w-full px-4 py-3 border-2 border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 text-base font-medium shadow-sm" placeholder="0.00" step="0.01" min="0">
                       </div>

                       <!-- ⭐ Expense Date (shows when backdate is allowed) -->
                       <div id="quick-expense-date-field" style="display: none;" class="mb-6">
                           <label class="block text-base font-semibold text-gray-800 mb-3 flex items-center gap-2">
                               <span>📅 Expense Date</span>
                               @if($expenseBackdateDays > 0)
                               <span class="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded-full">Up to {{ $expenseBackdateDays }} days back allowed</span>
                               @endif
                           </label>
                           @if($expenseBackdateDays > 0)
                           <input type="date" name="expense_date" id="quick_expense_date" 
                                  class="form-field-enhanced w-full px-4 py-3 border-2 border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 text-base font-medium shadow-sm"
                                  value="{{ date('Y-m-d') }}"
                                  max="{{ date('Y-m-d') }}"
                                  min="{{ date('Y-m-d', strtotime('-' . $expenseBackdateDays . ' days')) }}">
                           <p class="text-xs text-gray-500 mt-1">You can select dates from {{ date('M d, Y', strtotime('-' . $expenseBackdateDays . ' days')) }} to today</p>
                           @else
                           <input type="date" name="expense_date" id="quick_expense_date" 
                                  class="form-field-enhanced w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-base font-medium shadow-sm bg-gray-50"
                                  value="{{ date('Y-m-d') }}"
                                  readonly>
                           <p class="text-xs text-gray-500 mt-1">Expense will be recorded for today's date</p>
                           @endif
                       </div>

                       <!-- Description -->
                       <div class="mb-6">
                           <label class="block text-base font-semibold text-gray-800 mb-3" id="quick-description-label">Description</label>
                           <textarea name="description" id="quick-description-field" rows="4" class="form-field-enhanced w-full px-4 py-3 border-2 border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 text-base shadow-sm resize-none" placeholder="Provide details about your request"></textarea>
                       </div>

                       <!-- Priority - hidden, defaults to normal -->
                       <input type="hidden" name="priority" value="normal">

                <!-- Hidden Fields -->
                <input type="hidden" name="title" id="quick-hidden-title" value="">
            </form>
        </div>
        
        <!-- Fixed Footer with Action Buttons -->
        <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; background: #f9fafb; flex-shrink: 0; display: flex; gap: 12px;">
            <button type="button" onclick="closeNewRequestModal()" style="flex: 1; padding: 12px 24px; background: #e5e7eb; color: #374151; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                Cancel
            </button>
            <button type="submit" form="quickRequestForm" style="flex: 2; padding: 12px 24px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.3); transition: all 0.2s;">
                ✓ Submit Request
            </button>
        </div>
    </div>
</div>

<!-- Add New Expense Category Modal (Inline) -->
<div id="quickExpenseCategoryModal" class="hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 100000; display: none; align-items: center; justify-content: center; padding: 20px;" onclick="if(event.target === this) closeQuickExpenseCategoryModal()">
    <div onclick="event.stopPropagation()" style="background: white; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 500px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
        <!-- Fixed Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(135deg, #f3e8ff 0%, #ffffff 100%); flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: #d8b4fe; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    ➕
                </div>
                <div>
                    <h4 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">Add New Expense Category</h4>
                    <p style="font-size: 12px; color: #6b7280; margin: 2px 0 0 0;">Create a new category for expense tracking</p>
                </div>
            </div>
            <button type="button" onclick="closeQuickExpenseCategoryModal()" style="background: none; border: none; color: #9ca3af; font-size: 28px; line-height: 1; cursor: pointer; padding: 4px 8px;">&times;</button>
        </div>
        
        <!-- Scrollable Content -->
        <div style="overflow-y: auto; flex: 1; padding: 20px 24px;">
            <div style="display: flex; flex-direction: column; gap: 16px;">
                <div>
                    <label style="display: block; font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 8px;">
                        Category Name <span style="color: #ef4444;">*</span>
                    </label>
                    <input type="text" id="quick_inline_category_name" placeholder="e.g., Transportation, Equipment, Travel" style="width: 100%; padding: 12px 16px; border: 2px solid #d1d5db; border-radius: 8px; font-size: 14px; transition: all 0.2s;" onfocus="this.style.borderColor='#9333ea'; this.style.boxShadow='0 0 0 3px rgba(147, 51, 234, 0.1)';" onblur="this.style.borderColor='#d1d5db'; this.style.boxShadow='none';">
                </div>
                
                <div style="padding: 12px; background: #fef3c7; border: 1px solid #fbbf24; border-radius: 8px;">
                    <p style="font-size: 12px; color: #92400e; margin: 0; line-height: 1.5;">
                        <strong>ℹ️ Note:</strong> The system will automatically create an expense account and make it available in all expense forms.
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Fixed Footer with Action Buttons -->
        <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; background: #f9fafb; flex-shrink: 0; display: flex; gap: 12px;">
            <button type="button" onclick="closeQuickExpenseCategoryModal()" style="flex: 1; padding: 12px 24px; background: #e5e7eb; color: #374151; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                Cancel
            </button>
            <button type="button" onclick="submitQuickInlineCategory()" style="flex: 2; padding: 12px 24px; background: linear-gradient(135deg, #a855f7 0%, #7e22ce 100%); color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; box-shadow: 0 4px 6px -1px rgba(168, 85, 247, 0.3); transition: all 0.2s;">
                ✓ Create Category
            </button>
        </div>
    </div>
</div>

<script>
// New Request Modal Functions - REMOVED DUPLICATE (defined at top of file)

function handleRequestForChange() {
    const forSomeoneElse = document.querySelector('input[name="request_for"]:checked').value === 'someone_else';
    const userSelectField = document.getElementById('userSelectField');
    const requesterSelect = document.getElementById('requester_user_id');
    
    if (forSomeoneElse) {
        userSelectField.style.display = 'block';
        requesterSelect.required = true;
    } else {
        userSelectField.style.display = 'none';
        requesterSelect.required = false;
        requesterSelect.value = '';
    }
}

function handleQuickCategoryChange() {
    const select = document.getElementById('quick_category_id');
    const selectedOption = select.options[select.selectedIndex];
    const categoryCode = selectedOption.dataset.code;
    const requiresL1 = selectedOption.dataset.requiresL1 === '1';
    const requiresL2 = selectedOption.dataset.requiresL2 === '1';
    const hiddenTitle = document.getElementById('quick-hidden-title');
    
    // Show/hide fields based on category
    const leaveFields = document.getElementById('quick-leave-fields');
    const amountField = document.getElementById('quick-amount-field');
    const expenseCategoryField = document.getElementById('quick-expense-category-field');
    const descriptionLabel = document.getElementById('quick-description-label');
    const descriptionField = document.getElementById('quick-description-field');
    const expenseCategorySelect = document.getElementById('quick_expense_category');
    const form = document.getElementById('quickRequestForm');
    
    // Reset all fields first
    leaveFields.style.display = 'none';
    amountField.style.display = 'none';
    expenseCategoryField.style.display = 'none';
    // ⭐ Hide expense date field by default
    const expenseDateField = document.getElementById('quick-expense-date-field');
    if (expenseDateField) expenseDateField.style.display = 'none';
    // ⭐ Hide pay from field by default
    const payFromField = document.getElementById('quick-pay-from-field');
    if (payFromField) payFromField.style.display = 'none';
    form.querySelector('[name="leave_start_date"]').required = false;
    form.querySelector('[name="leave_end_date"]').required = false;
    form.querySelector('[name="amount"]').required = false;
    expenseCategorySelect.required = false;
    
    const formType = selectedOption.dataset.formType || 'general';

    if (formType === 'leave' || categoryCode === 'leave') {
        leaveFields.style.display = 'block';
        form.querySelector('[name="leave_start_date"]').required = true;
        form.querySelector('[name="leave_end_date"]').required = true;
        descriptionField.required = false;
        descriptionField.placeholder = 'Optional: Provide additional details about your leave';
        hiddenTitle.value = 'leave';
    } else if (formType === 'expense' || categoryCode === 'expense' || categoryCode === 'khaas_expense') {
        expenseCategoryField.style.display = 'block';
        amountField.style.display = 'block';
        expenseCategorySelect.required = true;
        form.querySelector('[name="amount"]').required = true;
        descriptionField.required = true;
        descriptionField.placeholder = 'Required: Provide details about this expense';
        hiddenTitle.value = categoryCode || 'expense';
        const expenseDateField = document.getElementById('quick-expense-date-field');
        if (expenseDateField) expenseDateField.style.display = 'block';
        const payFromField = document.getElementById('quick-pay-from-field');
        if (payFromField) payFromField.style.display = 'block';
        filterPaymentSourcesByBU();
        filterExpenseTypesByBU();
    } else if (formType === 'salary' || categoryCode === 'salary_advance') {
        amountField.style.display = 'block';
        form.querySelector('[name="amount"]').required = true;
        descriptionField.required = true;
        descriptionField.placeholder = 'Required: Explain why you need this advance';
        hiddenTitle.value = 'salary advance';
        // Salary advances can be paid from an ONLINE bank account — show the
        // pay-from field so the (mandatory) receiving-bank picker can appear.
        const salaryPayFromField = document.getElementById('quick-pay-from-field');
        if (salaryPayFromField) salaryPayFromField.style.display = 'block';
        filterPaymentSourcesByBU();
    }
    
    // Update approval info
    let approvalText = 'This request will require: ';
    if (requiresL1 && requiresL2) {
        approvalText += 'Level 1 approval only';
    } else if (requiresL1) {
        approvalText += 'Level 1 approval';
    } else {
        approvalText += 'No approval';
    }
    document.getElementById('quick-approval-info').textContent = approvalText;
}

// ⭐ Filter payment source options based on selected business unit
// EXP_FUND belongs to NF main (BU 1) and should be the DEFAULT for NF expenses
function filterPaymentSourcesByBU() {
    const buSelect = document.getElementById('quick_business_unit');
    const paymentSelect = document.getElementById('quick_payment_source');
    if (!buSelect || !paymentSelect) return;
    
    const selectedBuId = buSelect.value;
    const isNfMain = selectedBuId === '1'; // BU 1 = Nizami Farms main
    const options = paymentSelect.querySelectorAll('option');
    let firstVisibleOption = null;
    let hasSelectedVisible = false;
    
    options.forEach(option => {
        const optionBuId = option.dataset.businessUnitId;
        
        // Show only accounts matching the selected BU (including EXP_FUND which belongs to BU 1)
        if (optionBuId === selectedBuId) {
            option.style.display = '';
            option.disabled = false;
            if (!firstVisibleOption) firstVisibleOption = option;
            if (option.selected) hasSelectedVisible = true;
        } else {
            option.style.display = 'none';
            option.disabled = true;
            if (option.selected) option.selected = false;
        }
    });
    
    // If no option is selected (or previously selected one got hidden), auto-select a default
    if (!hasSelectedVisible && firstVisibleOption) {
        var preferredCode = window._isTaimurRole ? 'ONLINE' : 'EXP_FUND';
        if (isNfMain) {
            var preferredOption = Array.from(options).find(o => 
                o.dataset.accountCode === preferredCode && !o.disabled
            );
            if (!preferredOption) {
                preferredOption = Array.from(options).find(o => 
                    o.dataset.accountCode === 'EXP_FUND' && !o.disabled
                );
            }
            if (preferredOption) {
                preferredOption.selected = true;
            } else {
                firstVisibleOption.selected = true;
            }
        } else {
            firstVisibleOption.selected = true;
        }
    }
    // The selected source may have changed → refresh the receiving-bank picker.
    renderExpenseBankPicker();
}

// ⭐ Receiving-bank picker for ONLINE expenses -------------------------------
// Shown only when the chosen Pay-From account is an online bank (account_code
// contains 'ONLINE'). Chips come from t_fin_online_receiving_accounts.
window.expenseBanks = [];
function loadExpenseBanks() {
    fetch('/online-receiving-accounts', { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(res => {
            if (res && res.success) {
                window.expenseBanks = (res.data || []).filter(b => b.is_active != 0);
                // Refresh in case the expense form is already open on an online source.
                if (typeof renderExpenseBankPicker === 'function') { renderExpenseBankPicker(); }
            }
        })
        .catch(() => {});
}
function selectedPaySourceIsOnline() {
    const sel = document.getElementById('quick_payment_source');
    if (!sel) return false;
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.dataset) return false;
    // Match the SERVER rule (account_category === 'bank'); fall back to the
    // code heuristic for safety if the category attribute is ever missing.
    if (opt.dataset.accountCategory) {
        return opt.dataset.accountCategory === 'bank';
    }
    return ((opt.dataset.accountCode || '').indexOf('ONLINE') !== -1);
}
function selectExpenseBank(id) {
    document.getElementById('quick_receiving_account_id').value = id;
    renderExpenseBankPicker();
}
function renderExpenseBankPicker() {
    const field = document.getElementById('quick-expense-bank-field');
    const chips = document.getElementById('quickExpenseBankChips');
    const hidden = document.getElementById('quick_receiving_account_id');
    if (!field || !chips || !hidden) return;

    const payFromVisible = (document.getElementById('quick-pay-from-field') || {}).style?.display !== 'none';
    if (!payFromVisible || !selectedPaySourceIsOnline() || window.expenseBanks.length === 0) {
        field.style.display = 'none';
        hidden.value = '';
        return;
    }
    field.style.display = 'block';
    const current = hidden.value;
    chips.innerHTML = window.expenseBanks.map(b => {
        const active = String(current) === String(b.id);
        const color = b.color_hex || '#3B82F6';
        // Show the bank's computed current balance on the chip so the user
        // knows how much sits in the account they're paying from.
        const bal = (b.balance !== undefined && b.balance !== null)
            ? ` · Rs ${Math.round(Number(b.balance)).toLocaleString()}`
            : '';
        return `<button type="button" onclick="selectExpenseBank(${b.id})" style="padding:6px 14px; border-radius:16px; border:1px solid ${active ? color : '#CBD5E1'}; background:${active ? color : '#F1F5F9'}; color:${active ? '#fff' : '#475569'}; font-size:13px; font-weight:600; cursor:pointer;">${(b.short_code || b.name)}<span style="font-weight:500; opacity:0.85;">${bal}</span></button>`;
    }).join('');
}

// ⭐ Master handler when business unit changes — filters Request Type, Expense Type, and Pay From Account
function onBusinessUnitChanged() {
    filterPaymentSourcesByBU();
    filterRequestTypesByBU();
    filterExpenseTypesByBU();
}

// ⭐ Filter Request Type options based on selected business unit
// NF (BU 1) → expense, salary_advance, leave | Non-NF (Khaas etc.) → khaas_expense
function filterRequestTypesByBU() {
    const buSelect = document.getElementById('quick_business_unit');
    const categorySelect = document.getElementById('quick_category_id');
    if (!buSelect || !categorySelect) return;
    
    const selectedBuId = buSelect.value;
    const isNF = (selectedBuId === '1');
    const options = categorySelect.querySelectorAll('option[data-bu-type]');
    let firstVisibleOption = null;
    let hasSelectedVisible = false;
    
    options.forEach(option => {
        const buType = option.dataset.buType; // 'nf' or 'khaas'
        const shouldShow = buType === 'all' || (isNF && buType === 'nf') || (!isNF && buType === 'khaas');
        
        if (shouldShow) {
            option.style.display = '';
            option.disabled = false;
            if (!firstVisibleOption) firstVisibleOption = option;
            if (option.selected) hasSelectedVisible = true;
        } else {
            option.style.display = 'none';
            option.disabled = true;
            if (option.selected) option.selected = false;
        }
    });
    
    // Auto-select first visible option if current selection was hidden
    if (!hasSelectedVisible && firstVisibleOption) {
        firstVisibleOption.selected = true;
    }
    
    // Trigger the category change handler to update form fields
    handleQuickCategoryChange();
}

// Filter Expense Type options based on selected BU and request type
function filterExpenseTypesByBU() {
    // Oct-2026: one renderer for every Expense Type list (scope = BU + request type, same
    // rule as the phone), with Taimur's order and group headings. Keeps the current choice
    // when it is still offered.
    if (window.NFExpenseTypes) NFExpenseTypes.render('quick_expense_category');
}

// ⭐ Run BU filters on page load to match default BU selection
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        filterPaymentSourcesByBU();
        filterRequestTypesByBU();
        filterExpenseTypesByBU();
    }, 100);
});

function calculateQuickLeaveDays() {
    const startDate = document.querySelector('#quickRequestForm [name="leave_start_date"]').value;
    const endDate = document.querySelector('#quickRequestForm [name="leave_end_date"]').value;
    
    if (startDate && endDate) {
        const start = new Date(startDate);
        const end = new Date(endDate);
        const diffTime = Math.abs(end - start);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
        
        const infoDiv = document.getElementById('quick-leave-days-info');
        const textSpan = document.getElementById('quick-leave-days-text');
        
        if (diffDays > 0) {
            textSpan.textContent = `Total leave days: ${diffDays} day(s)`;
            infoDiv.style.display = 'block';
        } else {
            infoDiv.style.display = 'none';
        }
    }
}

function handleQuickExpenseCategoryChange() {
    const expenseCategorySelect = document.getElementById('quick_expense_category');
    const selectedValue = expenseCategorySelect.value;
    
    if (selectedValue === '__ADD_NEW__') {
        openQuickExpenseCategoryModal();
        expenseCategorySelect.value = '';
    } else {
        updateQuickExpenseTitle();
    }
}

function updateQuickExpenseTitle() {
    const expenseCategorySelect = document.getElementById('quick_expense_category');
    const hiddenTitle = document.getElementById('quick-hidden-title');
    const selectedExpense = expenseCategorySelect.value;
    
    if (selectedExpense && selectedExpense !== '__ADD_NEW__') {
        hiddenTitle.value = selectedExpense;
    } else {
        hiddenTitle.value = 'expense';
    }
}

function openQuickExpenseCategoryModal() {
    const modal = document.getElementById('quickExpenseCategoryModal');
    if (modal) {
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        
        // Focus on the input field
        setTimeout(() => {
            document.getElementById('quick_inline_category_name').focus();
        }, 100);
    }
}

function closeQuickExpenseCategoryModal() {
    const modal = document.getElementById('quickExpenseCategoryModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
        document.getElementById('quick_inline_category_name').value = '';
    }
}

function submitQuickInlineCategory() {
    const categoryName = document.getElementById('quick_inline_category_name').value.trim();
    
    if (!categoryName) {
        alert('Please enter a category name');
        return;
    }
    
    const submitBtn = event.target;
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '⏳ Creating...';
    submitBtn.disabled = true;
    
    fetch('{{ route("fin.expense-category.store") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            category_name: categoryName,
            request_category_code: (document.getElementById('quick_category_id')?.options[document.getElementById('quick_category_id').selectedIndex]?.dataset?.code) || ''
        })
    })
    .then(response => {
        if (!response.ok) {
            return response.json().then(err => Promise.reject(err));
        }
        return response.json();
    })
    .then(data => {
        if (data.success || (data.message && data.message.includes('successfully'))) {
            const expenseCategorySelect = document.getElementById('quick_expense_category');
            const newOption = document.createElement('option');
            newOption.value = categoryName;
            newOption.textContent = categoryName;
            
            const addNewOption = expenseCategorySelect.querySelector('option[value="__ADD_NEW__"]');
            expenseCategorySelect.insertBefore(newOption, addNewOption);
            expenseCategorySelect.value = categoryName;
            updateQuickExpenseTitle();
            
            closeQuickExpenseCategoryModal();
            // Oct-2026: re-sync with the engine (order, groups) — keeps the new type selected.
            if (window.NFExpenseTypes) NFExpenseTypes.refresh();
            alert('✓ Category "' + categoryName + '" created successfully!');
        } else {
            alert('Error: ' + (data.message || 'Failed to create category'));
        }
    })
    .catch(error => {
        console.error('Error creating category:', error);
        const errorMessage = error.message || (error.errors ? JSON.stringify(error.errors) : 'Failed to create category');
        alert('Error: ' + errorMessage);
    })
    .finally(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
}

// Submit Quick Request Form
document.getElementById('quickRequestForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const data = Object.fromEntries(formData.entries());
    
    // Remove requester_user_id if creating for myself
    if (!data.requester_user_id || data.requester_user_id === '') {
        delete data.requester_user_id;
    }
    
    // Get submit button by form attribute (it's outside the form)
    const submitBtn = document.querySelector('button[type="submit"][form="quickRequestForm"]');
    if (!submitBtn) {
        console.error('Submit button not found');
        return;
    }
    
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '⏳ Submitting...';
    
    fetch('{{ route("requests.store") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify(data)
    })
    .then(response => {
        if (!response.ok) {
            return response.json().then(err => Promise.reject(err));
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            alert('✓ Request submitted successfully!');
            closeNewRequestModal();
            // Reload page to refresh KPIs
            window.location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to submit request'));
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error submitting request:', error);
        alert('Error: ' + (error.message || 'Failed to submit request'));
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
});
</script>
@endsection

{{-- Settlement modal removed — settlement now handled via mobile only --}}

<script>

// Open pending approvals modal
function openPendingApprovalsModal() {
    const modal = document.getElementById('pendingApprovalsModal');
    if (modal) {
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

// Close pending approvals modal
function closePendingApprovalsModal() {
    const modal = document.getElementById('pendingApprovalsModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}

// Build request detail HTML from JSON data (avoids CSS conflicts from loading full page)
function _buildRequestDetailHtml(r) {
    const statusColors = {pending:'background:#fef3c7;color:#92400e',approved:'background:#d1fae5;color:#065f46',rejected:'background:#fee2e2;color:#991b1b',cancelled:'background:#f3f4f6;color:#4b5563'};
    const statusStyle = statusColors[r.status] || statusColors.cancelled;

    let html = '<div style="padding:24px;">';

    // Header
    html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">';
    html += '<div style="display:flex;align-items:center;gap:12px;">';
    html += '<button onclick="closeRequestDetailModal()" style="background:#f3f4f6;border:none;border-radius:50%;width:32px;height:32px;cursor:pointer;font-size:16px;color:#2563eb;">←</button>';
    html += '<span style="font-size:18px;font-weight:600;color:#111;">Request #' + r.request_number + '</span>';
    html += '</div>';
    html += '<span style="padding:4px 12px;border-radius:9999px;font-size:12px;font-weight:700;' + statusStyle + '">' + (r.status||'').toUpperCase() + '</span>';
    html += '</div>';

    // Fields grid
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px;">';
    html += _field('Category', r.category_name);
    html += _field('Requester (Employee)', r.requester_name + (r.created_by_differs && r.created_by_name ? '<br><span style="font-size:11px;color:#2563eb;">Created by ' + r.created_by_name + ' on behalf</span>' : ''));
    if (r.submitted_at) html += _field('Submitted', r.submitted_at);
    if (r.completed_at) html += _field('Completed', r.completed_at);
    if (r.amount) html += _field('Amount', 'Rs. ' + Number(r.amount).toLocaleString(undefined,{minimumFractionDigits:2}));
    if (r.expense_category) html += _field('Expense Category', r.expense_category);
    if (r.expense_date) html += _field('Expense Date', r.expense_date);
    if (r.payment_source) html += _field('💳 Payment Source', r.payment_source + ' <span style="font-size:11px;color:#6b7280;">(Selected by requester)</span>');
    if (r.leave_start_date) html += _field('Leave Period', r.leave_start_date + ' to ' + r.leave_end_date + ' (' + r.leave_days + ' days)');
    if (r.leave_type) html += _field('Leave Type', r.leave_type);
    html += '<div style="grid-column:span 2;">' + _fieldInner('Title', r.title || '-') + '</div>';
    html += '<div style="grid-column:span 2;">' + _fieldInner('Description', (r.description || '-').replace(/\n/g,'<br>')) + '</div>';
    if (r.rejection_reason) html += '<div style="grid-column:span 2;">' + _fieldInner('<span style="color:#991b1b;">Rejection Reason</span>', '<span style="color:#dc2626;">' + r.rejection_reason + '</span>') + '</div>';
    html += '</div>';

    // Approval Timeline
    html += '<div style="border-top:1px solid #e5e7eb;padding-top:20px;margin-bottom:16px;">';
    html += '<h3 style="font-size:16px;font-weight:600;margin-bottom:16px;color:#111;">Approval Timeline</h3>';
    if (r.requires_level_1) html += _approvalLevel(1, r.level_1_status, r.l1_approver_name, r.l1_action_date, r.l1_comments, r.l1_status);
    if (r.requires_level_2) html += _approvalLevel(2, r.level_2_status, r.l2_approver_name, r.l2_action_date, r.l2_comments, r.l2_status);
    if (!r.requires_level_1 && !r.requires_level_2) html += '<p style="color:#6b7280;">No approval required for this request.</p>';
    html += '</div>';

    // Approval actions (if user can approve)
    if (r.can_approve_level_1 || r.can_approve_level_2) {
        const level = r.can_approve_level_1 ? 1 : 2;
        html += '<div style="border-top:1px solid #e5e7eb;padding-top:20px;">';
        html += '<h3 style="font-size:16px;font-weight:600;margin-bottom:12px;color:#111;">Take Action</h3>';
        html += '<div style="margin-bottom:12px;"><label style="font-size:13px;font-weight:600;color:#374151;">Comments</label>';
        html += '<textarea id="reqDetailComments" rows="3" style="width:100%;margin-top:4px;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;resize:vertical;" placeholder="Add comments (required for rejection)"></textarea></div>';
        html += '<div style="display:flex;gap:12px;">';
        html += '<button onclick="approveRequestFromModal(' + r.id + ',' + level + ')" style="flex:1;padding:10px 20px;background:#059669;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;">✓ Approve</button>';
        html += '<button onclick="rejectRequestFromModal(' + r.id + ',' + level + ')" style="flex:1;padding:10px 20px;background:#dc2626;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;">✗ Reject</button>';
        html += '</div></div>';
    }

    html += '</div>';
    return html;
}
function _field(label, value) { return '<div>' + _fieldInner(label, value) + '</div>'; }
function _fieldInner(label, value) { return '<div style="font-size:12px;font-weight:600;color:#6b7280;margin-bottom:2px;">' + label + '</div><div style="font-size:14px;color:#111;">' + (value||'-') + '</div>'; }
function _approvalLevel(level, status, approverName, actionDate, comments, actionStatus) {
    const color = status === 'approved' ? '#059669' : status === 'rejected' ? '#dc2626' : '#9ca3af';
    let h = '<div style="display:flex;gap:12px;margin-bottom:16px;">';
    h += '<div style="width:36px;height:36px;border-radius:50%;background:' + color + ';color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;flex-shrink:0;">' + level + '</div>';
    h += '<div>';
    h += '<div style="font-size:15px;font-weight:600;">Level ' + level + ' Approval</div>';
    h += '<div style="font-size:13px;color:#6b7280;">Status: <strong>' + ((status||'pending').charAt(0).toUpperCase() + (status||'pending').slice(1)) + '</strong></div>';
    if (approverName) {
        h += '<div style="font-size:13px;margin-top:4px;"><strong>' + (actionStatus === 'approved' ? 'Approved' : 'Rejected') + ' by:</strong> ' + approverName + '</div>';
        if (actionDate) h += '<div style="font-size:12px;color:#6b7280;">' + actionDate + '</div>';
        if (comments) h += '<div style="font-size:13px;margin-top:4px;font-style:italic;color:#374151;">"' + comments + '"</div>';
    }
    h += '</div></div>';
    return h;
}

// Approve/reject from the modal
function approveRequestFromModal(requestId, level) {
    if (!confirm('Are you sure you want to approve this request?')) return;
    const comments = (document.getElementById('reqDetailComments')?.value || '').trim();
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('[name="_token"]')?.value || '';
    fetch('/requests/' + requestId + '/approve', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-CSRF-TOKEN':csrf},
        body: JSON.stringify({level: level, comments: comments})
    }).then(r => r.json()).then(d => {
        if (d.success) { alert('Request approved successfully!'); closeRequestDetailModal(false); if (typeof closePendingApprovalsModal === 'function') closePendingApprovalsModal(); location.reload(); }
        else alert('Error: ' + (d.message||'Unknown error'));
    }).catch(e => { console.error(e); alert('An error occurred.'); });
}
function rejectRequestFromModal(requestId, level) {
    const comments = (document.getElementById('reqDetailComments')?.value || '').trim();
    if (!comments) { alert('Please enter comments explaining the rejection.'); document.getElementById('reqDetailComments')?.focus(); return; }
    if (!confirm('Are you sure you want to reject this request?')) return;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('[name="_token"]')?.value || '';
    fetch('/requests/' + requestId + '/reject', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-CSRF-TOKEN':csrf},
        body: JSON.stringify({level: level, comments: comments})
    }).then(r => r.json()).then(d => {
        if (d.success) { alert('Request rejected.'); closeRequestDetailModal(false); if (typeof closePendingApprovalsModal === 'function') closePendingApprovalsModal(); location.reload(); }
        else alert('Error: ' + (d.message||'Unknown error'));
    }).catch(e => { console.error(e); alert('An error occurred.'); });
}

// Open request detail modal (fetches JSON, builds HTML client-side)
async function openRequestDetailModal(requestId) {
    const modal = document.getElementById('requestDetailModal');
    const content = document.getElementById('requestDetailContent');
    if (!modal || !content) return;

    modal.classList.remove('hidden');
    Object.assign(modal.style, {
        display: 'block',
        position: 'fixed',
        top: '0', left: '0', right: '0', bottom: '0',
        zIndex: '999999',
        overflowY: 'auto',
        padding: '2rem 1rem',
        backgroundColor: 'rgba(0,0,0,0.5)'
    });
    document.body.style.overflow = 'hidden';

    // Show loading
    content.innerHTML = '<div style="text-align:center;padding:48px 0;"><div style="width:36px;height:36px;border:3px solid #e5e7eb;border-top-color:#2563eb;border-radius:50%;animation:spin 0.6s linear infinite;margin:0 auto;"></div><p style="margin-top:12px;color:#6b7280;">Loading request details...</p></div>';

    try {
        const response = await fetch('/requests/' + requestId + '?format=json', {
            headers: {'Accept':'application/json','X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''}
        });
        if (!response.ok) throw new Error('Failed to load request (HTTP ' + response.status + ')');
        const data = await response.json();
        if (!data.success) throw new Error(data.message || 'Failed');
        content.innerHTML = _buildRequestDetailHtml(data.request);
    } catch (error) {
        console.error('Error loading request:', error);
        content.innerHTML = '<div style="padding:24px;text-align:center;"><div style="font-size:20px;margin-bottom:8px;">❌</div><p style="font-weight:500;color:#374151;">Failed to load request details</p><p style="font-size:13px;color:#6b7280;margin-top:4px;">' + error.message + '</p><button onclick="closeRequestDetailModal()" style="margin-top:16px;padding:8px 20px;background:#e5e7eb;border:none;border-radius:6px;cursor:pointer;">Close</button></div>';
    }
}

// Close request detail modal and refresh if approval/rejection happened
function closeRequestDetailModal(shouldReload = false) {
    const modal = document.getElementById('requestDetailModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
    
    // If request was approved/rejected, reload page to update counts
    if (shouldReload) {
        location.reload();
    }
}

// Ensure all functions are globally accessible
window.openPendingApprovalsModal = openPendingApprovalsModal;
window.closePendingApprovalsModal = closePendingApprovalsModal;
window.openRequestDetailModal = openRequestDetailModal;
window.closeRequestDetailModal = closeRequestDetailModal;
// Expose new request modal handlers globally
window.openNewRequestModal = openNewRequestModal;
window.closeNewRequestModal = closeNewRequestModal;

console.log('Expense Management JS loaded. Functions:', {
    openPendingApprovalsModal: typeof window.openPendingApprovalsModal,
    openRequestDetailModal: typeof window.openRequestDetailModal,
    openNewRequestModal: typeof window.openNewRequestModal
});

// ⭐ Delete Expense Confirmation
async function confirmDeleteExpense(expenseId, requestNumber, amount) {
    const reason = prompt(
        `⚠️ DELETE EXPENSE\n\n` +
        `Request #: ${requestNumber}\n` +
        `Amount: Rs. ${amount.toLocaleString()}\n\n` +
        `This will:\n` +
        `• Mark the expense as cancelled\n` +
        `• Reverse all ledger entries\n` +
        `• Restore account balances\n\n` +
        `Enter reason for deletion (or Cancel to abort):`
    );
    
    if (reason === null) {
        // User clicked Cancel
        return;
    }
    
    if (!reason.trim()) {
        alert('Please provide a reason for deletion.');
        return;
    }
    
    try {
        const response = await fetch(`/finance/expenses/${expenseId}`, {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ notes: reason.trim() })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('✅ ' + data.message);
            // Refresh the page to show updated list
            window.location.reload();
        } else {
            alert('❌ ' + (data.message || 'Failed to delete expense'));
        }
    } catch (error) {
        console.error('Delete expense error:', error);
        alert('❌ An error occurred while deleting the expense. Please try again.');
    }
}

// Make function globally available
window.confirmDeleteExpense = confirmDeleteExpense;
</script>




<!-- Pending Approvals Modal (Portalized - matches working modals) -->
<div id="pendingApprovalsModal" class="hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 20px; overflow-y: auto;" onclick="if(event.target === this) closePendingApprovalsModal()">
    <div onclick="event.stopPropagation()" style="background: white; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 1200px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
        <!-- Fixed Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(135deg, #fef3c7 0%, #ffffff 100%); flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: #fde68a; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    ⏳
                </div>
                <div>
                    <h2 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">Pending Expense Approvals</h2>
                    <p style="font-size: 12px; color: #6b7280; margin: 2px 0 0 0;">Review and approve pending expense requests</p>
                </div>
            </div>
            <button type="button" onclick="closePendingApprovalsModal()" style="background: none; border: none; color: #9ca3af; font-size: 28px; line-height: 1; cursor: pointer; padding: 4px 8px;">&times;</button>
        </div>
        
        <!-- Scrollable Content Area -->
        <div style="overflow-y: auto; flex: 1; padding: 20px 24px;">
            
            @if($pendingApprovals && $pendingApprovals->count() > 0)
            <!-- Pending Requests List -->
            <div class="space-y-3">
                @foreach($pendingApprovals as $request)
                <div class="border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition-colors">
                    <div class="flex items-start justify-between">
                        <!-- Request Details -->
                        <div class="flex-1">
                            <div class="flex items-center gap-3 mb-2">
                                <span class="text-sm font-semibold text-blue-600">{{ $request->request_number }}</span>
                                <span class="px-2 py-0.5 bg-yellow-100 text-yellow-800 text-xs font-medium rounded">
                                    {{ $request->status }}
                                </span>
                                <span class="text-xs text-gray-500">
                                    {{ $request->created_at ? $request->created_at->format('M d, Y h:i A') : '-' }}
                                </span>
                            </div>
                            
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                                <div>
                                    <span class="text-gray-500">Employee:</span>
                                    <span class="font-medium text-gray-900 ml-1">
                                        {{ $request->requester->fullname ?? 'Unknown' }}
                                    </span>
                                </div>
                                <div>
                                    <span class="text-gray-500">Category:</span>
                                    <span class="font-medium text-gray-900 ml-1">
                                        {{ $request->expense_category ?? 'N/A' }}
                                    </span>
                                </div>
                                <div>
                                    <span class="text-gray-500">Amount:</span>
                                    <span class="font-bold text-green-700 ml-1">
                                        Rs. {{ number_format($request->amount, 2) }}
                                    </span>
                                </div>
                                <div>
                                    <span class="text-gray-500">Payment From:</span>
                                    <span class="font-medium text-gray-900 ml-1">
                                        {{ $request->paymentSourceAccount->account_name ?? 'Expense Fund' }}
                                    </span>
                                </div>
                            </div>
                            
                            @if($request->description)
                            <div class="mt-2 text-sm text-gray-600">
                                <span class="text-gray-500">Description:</span> {{ Str::limit($request->description, 100) }}
                            </div>
                            @endif
                        </div>
                        
                        <!-- Action Button -->
                        <div class="ml-4">
                            <button onclick="openRequestDetailModal({{ $request->id }})" 
                                    class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg transition-colors shadow-sm"
                                    style="background-color: #059669 !important;">
                                <span style="color: white !important;">View & Approve</span>
                            </button>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <!-- No Pending Requests -->
            <div class="text-center py-12">
                <div class="text-6xl mb-3">&#10004;</div>
                <h3 class="text-lg font-semibold text-gray-700">All Caught Up!</h3>
                <p class="text-gray-500 mt-1">No pending expense requests at the moment.</p>
            </div>
            @endif
        </div>
        
        <!-- Fixed Footer -->
        <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; background: #f9fafb; flex-shrink: 0; display: flex; justify-content: flex-end;">
            <button type="button" onclick="closePendingApprovalsModal()" style="padding: 12px 24px; background: #e5e7eb; color: #374151; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                Close
            </button>
        </div>
    </div>
</div>

<!-- Request Detail Modal (for approving without leaving page) -->
<!-- ⭐ z-index must be higher than pendingApprovalsModal (99999) so it appears on top when opened from there -->
<!-- Using inline styles (not Tailwind classes) because the JS opener overrides styles anyway -->
<div id="requestDetailModal" class="hidden" onclick="if(event.target===this)closeRequestDetailModal()" style="position:fixed;top:0;left:0;right:0;bottom:0;z-index:999999;background:rgba(0,0,0,0.5);overflow-y:auto;padding:2rem 1rem;">
    <div onclick="event.stopPropagation()" style="background:#fff;border-radius:12px;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);max-width:48rem;width:100%;margin:0 auto;position:relative;">
        <div id="requestDetailContent" style="position:relative;">
            <!-- Content will be loaded here via AJAX -->
            <div style="text-align:center;padding:3rem 0;">
                <div style="width:3rem;height:3rem;border:2px solid #e5e7eb;border-top-color:#2563eb;border-radius:50%;animation:spin 0.6s linear infinite;margin:0 auto;"></div>
                <p style="margin-top:1rem;color:#6b7280;">Loading request details...</p>
            </div>
        </div>
    </div>
</div>

