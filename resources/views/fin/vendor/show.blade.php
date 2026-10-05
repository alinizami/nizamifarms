@extends('layouts.app')

@section('title', e($vendor->vendor_name))

@section('content')
<style>
@media print {
    /* Hide everything except the report content */
    body * {
        visibility: hidden;
    }
    
    /* Show only the printable report */
    #printableVendorReport,
    #printableVendorReport * {
        visibility: visible;
    }
    
    /* Position the report at the top of the page */
    #printableVendorReport {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        padding: 20px;
    }
    
    /* Hide the modal overlay and wrapper */
    #vendorReportModal {
        background: white !important;
        backdrop-filter: none !important;
        position: static !important;
        padding: 0 !important;
    }
    
    /* Remove shadows for print but keep borders */
    #printableVendorReport {
        box-shadow: none !important;
    }
    
    /* Hide print and excel buttons when printing */
    button[onclick="printVendorReport()"],
    button[onclick="exportVendorToExcel()"] {
        display: none !important;
    }
    
    /* Ensure proper page breaks */
    .print\\:break-inside-avoid {
        break-inside: avoid;
        page-break-inside: avoid;
    }
    
    /* Preserve table borders */
    table, th, td {
        border: 1px solid #000 !important;
    }
    
    /* Preserve background colors for headers */
    .print\\:bg-white {
        background-color: white !important;
    }
    
    .print\\:bg-purple-200 {
        background-color: #e9d5ff !important;
    }
    
    /* Preserve colors for amounts */
    .text-red-600, .text-red-700 {
        color: #dc2626 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    .text-green-600, .text-green-700 {
        color: #16a34a !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    .text-blue-600 {
        color: #2563eb !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    .text-orange-600 {
        color: #ea580c !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    /* Preserve gradient backgrounds */
    .bg-gradient-to-r {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
        color-adjust: exact;
    }
    
    /* Ensure text is black for better print quality */
    body {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    /* Custom page margins and settings */
    @page {
        margin: 0.5cm;
        size: A4;
    }
    
    /* Add company name to footer */
    #printableVendorReport::after {
        content: "Nizami Farms";
        display: block;
        text-align: left;
        margin-top: 20px;
        padding-top: 10px;
        border-top: 1px solid #e5e7eb;
        font-size: 10pt;
        color: #666;
        visibility: visible;
    }
    
    /* Ensure content starts from top */
    html, body {
        margin: 0 !important;
        padding: 0 !important;
    }
}
</style>

{{-- The report renderer, shared with the Ledger Hub vendor page so the two can't drift. --}}
@include('fin.partials.vendor-report-render')
<div class="max-w-7xl mx-auto p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">{{ $vendor->vendor_name }}</h1>
        <a href="{{ route('fin.vendors.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            ← Back to Vendors
        </a>
    </div>

    <!-- Success Message -->
    @if(session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-md">
            <p class="text-sm text-green-800">{{ session('success') }}</p>
        </div>
    @endif

    <!-- Error Message -->
    @if(session('error'))
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md">
            <p class="text-sm text-red-800">{{ session('error') }}</p>
        </div>
    @endif

    {{-- "Is this the bank SMS for the payment you just recorded?" — flashed by
         VendorController@recordPayment when a matching debit is still waiting in
         the assistant's money box. Answering files it against this entry; doing
         nothing leaves it exactly where it was. --}}
    @if(session('bank_sms_prompt') && (session('bank_sms_prompt')['action'] ?? null) === 'ask')
        @php $nfSmsPrompt = session('bank_sms_prompt'); @endphp
        <div class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded-md" id="nfSmsPrompt">
            <p class="text-sm font-semibold text-blue-900 mb-1">Is this the bank SMS for that payment?</p>
            <p class="text-xs text-blue-700 mb-3">Filing it clears the message from your money box and records which entry it belongs to.</p>
            <div id="nfSmsPromptMsg" class="text-sm text-blue-900 mb-2" style="display:none"></div>
            <div id="nfSmsPromptRows">
                @foreach($nfSmsPrompt['candidates'] as $cand)
                    <div class="flex items-center gap-3 bg-white border border-blue-200 rounded-md p-3 mb-2">
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-semibold text-gray-900">Rs. {{ number_format($cand['amount'], 0) }}</div>
                            <div class="text-xs text-gray-500">
                                {{ collect([$cand['counterparty'], trim(($cand['date'] ?? '') . ' ' . ($cand['time'] ?? '')), $cand['bank'], $cand['reference'] ? 'TID ' . $cand['reference'] : null])->filter()->implode(' · ') }}
                            </div>
                        </div>
                        <button type="button"
                                class="px-3 py-1.5 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 whitespace-nowrap nf-sms-yes"
                                data-sms="{{ $cand['id'] }}" data-ledger="{{ $nfSmsPrompt['ledger_id'] }}">Yes, that's it</button>
                    </div>
                @endforeach
            </div>
            <button type="button" class="text-xs text-blue-700 underline"
                    onclick="document.getElementById('nfSmsPrompt').remove()">Not this one — leave it</button>
        </div>
        {{-- ⚠ Inline, NOT @push('scripts'): this layout stacks 'demo1_js' and
             'modals' — there is no 'scripts' stack, so a push would compile
             fine and silently never render. This page's own scripts are inline
             inside @section('content') too, so this matches it. --}}
        <script>
        // Standalone on purpose: this page's other scripts are unrelated, and a
        // banner that fails must never take the vendor page down with it.
        (function () {
            var box = document.getElementById('nfSmsPrompt');
            if (!box) return;
            var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
            box.querySelectorAll('.nf-sms-yes').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    btn.disabled = true; btn.textContent = 'Filing…';
                    var fd = new FormData();
                    fd.append('_token', token);
                    fd.append('sms_id', btn.dataset.sms);
                    fd.append('ledger_id', btn.dataset.ledger);
                    fetch('{{ url('assistant-view/money-out/tag') }}', {
                        method: 'POST', body: fd,
                        headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}
                    }).then(function (r) { return r.json(); }).then(function (j) {
                        var msg = document.getElementById('nfSmsPromptMsg');
                        msg.textContent = j.message || (j.success ? 'Filed.' : 'Could not file that bank SMS.');
                        msg.style.display = 'block';
                        if (j.success) { document.getElementById('nfSmsPromptRows').remove(); }
                        else { btn.disabled = false; btn.textContent = "Yes, that's it"; }
                    }).catch(function () {
                        btn.disabled = false; btn.textContent = "Yes, that's it";
                        var msg = document.getElementById('nfSmsPromptMsg');
                        msg.textContent = 'Network error — the payment is recorded; the SMS is still in your money box.';
                        msg.style.display = 'block';
                    });
                });
            });
        })();
        </script>
    @endif

    <!-- Date Range Filter -->
    <div class="bg-gradient-to-r from-blue-50 to-blue-100 rounded-lg p-3 mb-4 border border-blue-300 shadow-sm">
        <form method="GET" action="{{ route('fin.vendors.show', $vendor->id) }}" class="flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[150px]">
                <label class="block text-xs font-semibold text-gray-700 mb-1">From Date</label>
                <input type="date" name="date_from" value="{{ request('date_from', date('Y-m-01')) }}"
                       class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 text-gray-900">
            </div>
            <div class="flex-1 min-w-[150px]">
                <label class="block text-xs font-semibold text-gray-700 mb-1">To Date</label>
                <input type="date" name="date_to" value="{{ request('date_to', date('Y-m-d')) }}"
                       class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 text-gray-900">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="px-4 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded shadow-sm transition-colors">
                    🔍 Filter
                </button>
                @if(request('date_from') || request('date_to'))
                    <a href="{{ route('fin.vendors.show', $vendor->id) }}" class="px-4 py-1.5 bg-gray-300 hover:bg-gray-400 text-gray-900 text-sm font-semibold rounded shadow-sm transition-colors">
                        ✕ Clear
                    </a>
                @endif
            </div>
        </form>
    </div>

    <!-- Vendor Summary Cards - Single Row Layout -->
    <div class="grid grid-cols-3 gap-2 mb-6">
        <!-- Card 1: Balance -->
        <div class="bg-white border {{ $summary['current_balance'] > 0 ? 'border-red-300' : 'border-gray-300' }} rounded-lg p-2 shadow-sm">
            <div class="flex items-center gap-2">
                <div class="text-2xl flex-shrink-0">💰</div>
                <div class="flex-1 min-w-0">
                    <div class="text-[10px] font-semibold text-gray-600 uppercase">Balance</div>
                    <div class="text-base font-bold {{ $summary['current_balance'] > 0 ? 'text-red-600' : 'text-green-600' }} truncate">
                        Rs. {{ number_format($summary['current_balance'], 2) }}
                    </div>
                    @if($summary['last_payment_date'] && $summary['last_payment_amount'])
                        <div class="text-[10px] text-gray-500">
                            Last: Rs. {{ number_format($summary['last_payment_amount'], 0) }} • {{ \Carbon\Carbon::parse($summary['last_payment_date'])->format('M d') }}
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Card 2: Purchases -->
        <div class="bg-white border border-orange-300 rounded-lg p-2 shadow-sm">
            <div class="flex items-center gap-2">
                <div class="text-2xl flex-shrink-0">📦</div>
                <div class="flex-1 min-w-0">
                    <div class="text-[10px] font-semibold text-gray-600 uppercase">
                        Purchases
                    </div>
                    <div class="text-base font-bold text-orange-600 truncate">
                        Rs. {{ number_format($summary['filtered_purchases'], 0) }}
                    </div>
                    <div class="flex gap-2 text-[10px] text-gray-600">
                        <span>This Wk: <strong>{{ number_format($summary['purchases_this_week'], 0) }}</strong></span>
                        <span>Last: <strong>{{ number_format($summary['purchases_last_week'], 0) }}</strong></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Card 3: Payments -->
        <div class="bg-white border border-green-300 rounded-lg p-2 shadow-sm">
            <div class="flex items-center gap-2">
                <div class="text-2xl flex-shrink-0">💵</div>
                <div class="flex-1 min-w-0">
                    <div class="text-[10px] font-semibold text-gray-600 uppercase">
                        Payments
                    </div>
                    <div class="text-base font-bold text-green-600 truncate">
                        Rs. {{ number_format($summary['filtered_payments'], 2) }}
                    </div>
                    @if($summary['last_five_payments']->isNotEmpty())
                        <div class="text-[10px] text-gray-600">
                            Last 5: 
                            @foreach($summary['last_five_payments']->take(3) as $payment)
                                <span class="text-green-700 font-medium">{{ number_format($payment->amount, 0) }}</span>{{ !$loop->last ? ',' : '' }}
                            @endforeach
                            @if($summary['last_five_payments']->count() > 3)...@endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="flex gap-3 mb-6">
        <!-- Debug: Current method is {{ $vendor->default_purchase_method ?? 'NULL' }} -->
        @if(isset($vendor->default_purchase_method) && $vendor->default_purchase_method == 'by_weight')
            <button onclick="openWeightedPurchaseModal()" 
                    class="inline-flex items-center px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white text-sm font-medium rounded-md"
                    style="background-color: #ea580c !important; color: white !important;">
                <span style="color: white !important;">⚖️ Purchase by Weight</span>
            </button>
            {{-- 🧾 Sep-2026. Photograph the bill and every line is filled in. Only on
                 itemised vendors: a by_total vendor has no line items for a card to
                 land in. Nothing is recorded until the card is checked and submitted,
                 and the submit goes through the SAME weighted-purchase endpoint. --}}
            <button onclick="rcOpen()" type="button"
                    class="inline-flex items-center px-4 py-2 text-sm font-medium rounded-md"
                    style="background-color:#FEF3C7 !important; color:#B45309 !important; border:1px solid #FDE68A;">
                <span>🧾 Scan a Bill</span>
            </button>
            <a href="{{ route('fin.vendors.products', $vendor->id) }}"
               class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md"
               style="background-color: #2563eb !important; color: white !important;">
                <span style="color: white !important;">🛒 Manage Products</span>
            </a>
        @else
            <button onclick="openPurchaseModal()" 
                    class="inline-flex items-center px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md"
                    style="background-color: #dc2626 !important; color: white !important;">
                <span style="color: white !important;">📦 Record Purchase</span>
            </button>
        @endif
        <button onclick="openPaymentModal()" 
                class="inline-flex items-center px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-md"
                style="background-color: #059669 !important; color: white !important;">
            <span style="color: white !important;">💰 Record Payment</span>
        </button>
        <button onclick="openVendorReportModal()" 
                class="inline-flex items-center px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium rounded-md"
                style="background-color: #9333ea !important; color: white !important;">
            <span style="color: white !important;">📊 Vendor Report</span>
        </button>
    </div>

    <!-- Ledger Transactions -->
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-lg font-medium text-gray-900">Transaction History</h2>
            <button id="toggleExpandBtn" onclick="toggleExpandAll()" 
                    class="px-4 py-2 text-sm font-medium rounded-md transition-colors"
                    style="background-color: #6366f1; color: white;">
                <span id="toggleExpandText">{{ $expandAll ? '📕 Collapse All' : '📖 Expand All' }}</span>
            </button>
        </div>
        
        <div class="overflow-x-auto">
            @forelse($groupedTransactions as $date => $transactions)
                @php
                    $summary = $dailySummaries[$date];
                    $dateObj = $date !== 'unknown' ? \Carbon\Carbon::parse($date) : null;
                @endphp
                
                <!-- Date Group Header -->
                <div class="border-b border-gray-200 bg-gray-50 hover:bg-gray-100 transition-colors">
                    <div class="px-6 py-3 flex justify-between items-center">
                        <div class="flex items-center gap-3 cursor-pointer flex-1" onclick="toggleDateGroup('{{ $date }}')">
                            <span class="text-2xl" id="icon-{{ $date }}">{{ $expandAll ? '📂' : '📁' }}</span>
                            <div>
                                <div class="text-sm font-semibold text-gray-900">
                                    {{ $dateObj ? $dateObj->format('l, F j, Y') : 'Unknown Date' }}
                                </div>
                                <div class="text-xs text-gray-500">
                                    {{ $summary['transaction_count'] }} transaction(s)
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 text-sm">
                            @if($summary['purchases'] > 0)
                                <div class="text-red-600 font-medium">
                                    📦 Rs. {{ number_format($summary['purchases'], 0) }}
                                </div>
                            @endif
                            @if($summary['payments'] > 0)
                                <div class="text-green-600 font-medium">
                                    💵 Rs. {{ number_format($summary['payments'], 0) }}
                                </div>
                            @endif
                            <div class="px-3 py-1 rounded-full text-xs font-semibold
                                {{ $summary['net'] > 0 ? 'bg-red-100 text-red-800' : ($summary['net'] < 0 ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800') }}">
                                Net: Rs. {{ number_format(abs($summary['net']), 0) }}
                                {{ $summary['net'] > 0 ? '↑' : ($summary['net'] < 0 ? '↓' : '→') }}
                            </div>
                            <div class="text-gray-700 font-bold">
                                Balance: Rs. {{ number_format($summary['end_balance'], 0) }}
                            </div>
                            
                            <!-- Quick Add Buttons for this Date -->
                            @if($date !== 'unknown')
                                <div class="flex items-center gap-1 ml-2 border-l border-gray-300 pl-3">
                                    @if(isset($vendor->default_purchase_method) && $vendor->default_purchase_method == 'by_weight')
                                        <button onclick="event.stopPropagation(); openWeightedPurchaseModalWithDate('{{ $date }}')" 
                                                class="px-2 py-1 text-xs font-semibold rounded-md transition-all hover:scale-105"
                                                style="background: #fed7aa; color: #c2410c; border: 1px solid #fdba74;"
                                                title="Add weighted purchase for {{ $dateObj ? $dateObj->format('M d, Y') : $date }}">
                                            ＋ ⚖️
                                        </button>
                                    @else
                                        <button onclick="event.stopPropagation(); openPurchaseModalWithDate('{{ $date }}')" 
                                                class="px-2 py-1 text-xs font-semibold rounded-md transition-all hover:scale-105"
                                                style="background: #fecaca; color: #dc2626; border: 1px solid #fca5a5;"
                                                title="Add purchase for {{ $dateObj ? $dateObj->format('M d, Y') : $date }}">
                                            ＋ 📦
                                        </button>
                                    @endif
                                    <button onclick="event.stopPropagation(); openPaymentModalWithDate('{{ $date }}', {{ $summary['net'] > 0 ? $summary['net'] : 0 }})" 
                                            class="px-2 py-1 text-xs font-semibold rounded-md transition-all hover:scale-105"
                                            style="background: #bbf7d0; color: #15803d; border: 1px solid #86efac;"
                                            title="Add payment for {{ $dateObj ? $dateObj->format('M d, Y') : $date }}">
                                        ＋ 💰
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
                
                <!-- Transactions Table for this Date -->
                <div id="group-{{ $date }}" class="{{ $expandAll ? '' : 'hidden' }}">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase">Time</th>
                                <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                <th class="px-6 py-2 text-right text-xs font-medium text-gray-500 uppercase">Purchase</th>
                                <th class="px-6 py-2 text-right text-xs font-medium text-gray-500 uppercase">Payment</th>
                                <th class="px-6 py-2 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                                <th class="px-6 py-2 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($transactions as $transaction)
                                <tr class="hover:bg-gray-50 cursor-pointer transition-colors" onclick="viewTransactionDetails({{ $transaction->id }})">
                                    <td class="px-6 py-3 whitespace-nowrap text-xs text-gray-600">
                                        {{ $transaction->created_at ? $transaction->created_at->format('h:i A') : '-' }}
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full 
                                            {{ $transaction->transaction_type == 'vendor_purchase' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">
                                            {{ ucfirst(str_replace('_', ' ', $transaction->transaction_type)) }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-3 text-sm text-gray-900">
                                        {{ $transaction->description }}
                                        @if($transaction->comments)
                                            <div class="text-xs text-gray-500">{{ $transaction->comments }}</div>
                                        @endif
                                        @if($transaction->bill_image)
                                            <div class="text-xs text-blue-600 mt-1">📎 Has Bill Image</div>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-right text-sm font-medium text-red-600">
                                        @if($transaction->transaction_type === 'vendor_purchase')
                                            Rs. {{ number_format($transaction->amount, 2) }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-right text-sm font-medium text-green-600">
                                        @if($transaction->transaction_type === 'vendor_payment')
                                            Rs. {{ number_format($transaction->amount, 2) }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-right text-sm font-bold {{ $transaction->running_balance > 0 ? 'text-red-600' : 'text-gray-900' }}">
                                        Rs. {{ number_format($transaction->running_balance, 2) }}
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-center text-sm" onclick="event.stopPropagation()">
                                        <button onclick="viewTransactionDetails({{ $transaction->id }})" 
                                                class="text-blue-600 hover:text-blue-900 mr-2"
                                                title="View Details">
                                            👁️
                                        </button>
                                        <button onclick="openEditTransactionModal({{ $transaction->id }})" 
                                                class="text-indigo-600 hover:text-indigo-900 mr-2"
                                                title="Edit Transaction">
                                            ✏️
                                        </button>
                                        <button onclick="confirmDeleteTransaction({{ $transaction->id }}, '{{ $transaction->transaction_type }}', {{ $transaction->amount }})" 
                                                class="text-red-600 hover:text-red-900"
                                                title="Delete Transaction">
                                            🗑️
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @empty
                <div class="px-6 py-8 text-center text-sm text-gray-500">
                    No transactions found for this vendor.
                </div>
            @endforelse
        </div>
    </div>
</div>

<!-- Record Purchase Modal - Elegant Design (Matching Weighted Purchase Style) -->
<div id="purchaseModal" class="hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 20px; overflow-y: auto;">
    <div onclick="event.stopPropagation()" style="background: white; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 600px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
        <!-- Fixed Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(135deg, #fef2f2 0%, #ffffff 100%); flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: #fecaca; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    📦
                </div>
                <div>
                    <h3 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">Record Purchase</h3>
                    <p style="font-size: 12px; color: #6b7280; margin: 2px 0 0 0;">Enter total purchase amount from vendor</p>
                </div>
            </div>
            <button type="button" onclick="closePurchaseModal()" style="background: none; border: none; color: #9ca3af; font-size: 28px; line-height: 1; cursor: pointer; padding: 4px 8px;">&times;</button>
        </div>
        
        <!-- Scrollable Content -->
        <div style="overflow-y: auto; flex: 1; padding: 20px 24px;">
            <form action="{{ route('fin.vendors.purchase', $vendor->id) }}" method="POST" id="purchaseForm" enctype="multipart/form-data">
                @csrf
                <div style="display: flex; flex-direction: column; gap: 20px;">
                    <!-- Date Field -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Date <span class="text-red-500">*</span></label>
                        <input type="date" name="transaction_date" value="{{ date('Y-m-d') }}" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent">
                    </div>
                    
                    <!-- Amount Field -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Amount (Rs.) <span class="text-red-500">*</span></label>
                        <input type="number" name="amount" step="0.01" min="0.01" required placeholder="0.00"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent text-lg font-semibold">
                    </div>
                    
                    <!-- Description -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Description</label>
                        <textarea name="description" rows="2" placeholder="Add any notes about this purchase..."
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent text-sm">Entry by {{ auth()->user()->fullname ?? 'User' }}</textarea>
                    </div>
                    
                    <!-- Bill Images Upload (Aug-2026: several allowed; recordPurchase reads bill_images[]) -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Bill Images 📷</label>
                        <input type="file" name="bill_images[]" accept="image/*" multiple
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent text-sm file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-red-50 file:text-red-700 hover:file:bg-red-100">
                        <p class="text-xs text-gray-500 mt-1">📸 Upload the vendor's bill/receipt photos (optional — pick several)</p>
                    </div>

                    <!-- Warning -->
                    <div style="padding: 12px; background: #fef2f2; border: 2px solid #fecaca; border-radius: 8px;">
                        <p style="font-size: 12px; color: #991b1b; font-weight: 600; margin: 0;">
                            ⚠️ This will increase the amount payable to this vendor.
                        </p>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Fixed Footer with Actions -->
        <div style="border-top: 1px solid #e5e7eb; background: #f9fafb; padding: 20px 24px; flex-shrink: 0;">
            <div style="display: flex; gap: 12px;">
                <button type="button" onclick="closePurchaseModal()" style="flex: 1; padding: 12px 16px; border: 1px solid #d1d5db; background: white; color: #374151; font-weight: 500; border-radius: 8px; cursor: pointer; font-size: 14px;">
                    Cancel
                </button>
                <button type="submit" form="purchaseForm"
                        style="flex: 1; padding: 12px 16px; background: #dc2626; color: white; font-weight: 500; border-radius: 8px; cursor: pointer; border: none; font-size: 14px;">
                    ✓ Record Purchase
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Purchase by Weight Modal - Elegant Design with Line Items -->
<div id="weightedPurchaseModal" class="hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 20px; overflow-y: auto;">
    <div onclick="event.stopPropagation()" style="background: white; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 1000px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
        <!-- Fixed Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(135deg, #fff7ed 0%, #ffffff 100%); flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: #fed7aa; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    ⚖️
                </div>
                <div>
                    <h3 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">Purchase by Weight</h3>
                    <p style="font-size: 12px; color: #6b7280; margin: 2px 0 0 0;">Add products and quantities - totals calculate automatically</p>
                </div>
            </div>
            <button type="button" onclick="closeWeightedPurchaseModal()" style="background: none; border: none; color: #9ca3af; font-size: 28px; line-height: 1; cursor: pointer; padding: 4px 8px;">&times;</button>
        </div>
        
        <!-- Scrollable Content -->
        <div style="overflow-y: auto; flex: 1; padding: 20px 24px;">
            <form action="{{ route('fin.vendors.weighted-purchase', $vendor->id) }}" method="POST" id="weightedPurchaseForm" enctype="multipart/form-data">
                @csrf
                <div style="display: flex; flex-direction: column; gap: 20px;">
                    <!-- Date Field -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">Date <span class="text-red-500">*</span></label>
                            <input type="date" name="transaction_date" value="{{ date('Y-m-d') }}" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                        </div>
                        <div class="flex items-end">
                            <button type="button" onclick="addLineItem(true)" class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-150 text-sm font-medium">
                                + Add Line Item
                            </button>
                        </div>
                    </div>
                    
                    <!-- Line Items Section -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Purchase Items</label>
                        <div id="lineItemsContainer" class="space-y-3">
                            <!-- Line items will be added here dynamically -->
                        </div>
                        <p class="text-xs text-gray-500 mt-2 text-center" id="emptyLineItemsMsg">Click "Add Product Line" to start adding items</p>
                    </div>
                    
                    <!-- Description -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Description</label>
                        <textarea name="description" rows="2" placeholder="Add any notes about this purchase..."
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent text-sm">Entry by {{ auth()->user()->fullname ?? 'User' }}</textarea>
                    </div>
                    
                    <!-- Bill Images Upload (Aug-2026: several allowed; recordWeightedPurchase reads bill_images[]) -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Bill Images 📷</label>
                        <input type="file" name="bill_images[]" accept="image/*" multiple
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent text-sm file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-orange-50 file:text-orange-700 hover:file:bg-orange-100">
                        <p class="text-xs text-gray-500 mt-1">📸 Upload the vendor's bill/receipt photos (optional — pick several)</p>
                    </div>

                    <!-- Hidden adjustment field (synced with visible input in footer) -->
                    <input type="hidden" name="adjustment_amount" id="hiddenAdjustmentAmount" value="0">
                </div>
            </form>
        </div>
        
        <!-- Fixed Footer with Total and Actions - Compact Design -->
        <div style="border-top: 1px solid #e5e7eb; background: #f9fafb; padding: 12px 24px; flex-shrink: 0;">
            <!-- Compact Totals Row -->
            <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 12px; padding: 10px 12px; background: linear-gradient(135deg, #fed7aa 0%, #ffedd5 100%); border: 1px solid #fb923c; border-radius: 8px;">
                <!-- Items Total -->
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span style="font-size: 12px; color: #9a3412;">Items:</span>
                    <span style="font-size: 14px; font-weight: 600; color: #9a3412;" id="itemsTotal">Rs. 0.00</span>
                </div>
                <!-- Adjustment Input (Inline) -->
                <div style="display: flex; align-items: center; gap: 6px; flex: 1;">
                    <span style="font-size: 12px; color: #9a3412; white-space: nowrap;">Adj:</span>
                    <input type="number" id="adjustmentAmount" step="0.01" value="" placeholder="-500"
                           style="width: 90px; padding: 4px 8px; border: 1px solid #fb923c; border-radius: 4px; font-size: 13px; text-align: right;"
                           oninput="syncAdjustmentAmount(); updateGrandTotal()"
                           onfocus="if(this.value === '' || this.value === '0') this.value = '-'">
                    <span style="font-size: 10px; color: #9a3412;">(- disc)</span>
                </div>
                <!-- Grand Total -->
                <div style="display: flex; align-items: center; gap: 8px; padding-left: 12px; border-left: 1px solid #fb923c;">
                    <span style="font-size: 13px; font-weight: 600; color: #7c2d12;">Total:</span>
                    <span style="font-size: 20px; font-weight: bold; color: #7c2d12;" id="grandTotal">Rs. 0.00</span>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div style="display: flex; gap: 12px;">
                <button type="button" onclick="closeWeightedPurchaseModal()" style="flex: 1; padding: 10px 16px; border: 1px solid #d1d5db; background: white; color: #374151; font-weight: 500; border-radius: 8px; cursor: pointer; font-size: 14px;">
                    Cancel
                </button>
                <button type="submit" form="weightedPurchaseForm" id="submitWeightedPurchase"
                        style="flex: 1; padding: 10px 16px; background: #ea580c; color: white; font-weight: 500; border-radius: 8px; cursor: pointer; border: none; font-size: 14px;">
                    ✓ Record Purchase
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Record Payment Modal - Elegant Design (Matching Purchase Style) -->
<div id="paymentModal" class="hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 20px; overflow-y: auto;">
    <div onclick="event.stopPropagation()" style="background: white; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 600px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
        <!-- Fixed Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(135deg, #d1fae5 0%, #ffffff 100%); flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: #6ee7b7; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    💰
                </div>
                <div>
                    <h3 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">Record Payment</h3>
                    <p style="font-size: 12px; color: #6b7280; margin: 2px 0 0 0;">Pay vendor for outstanding purchases</p>
                </div>
            </div>
            <button type="button" onclick="closePaymentModal()" style="background: none; border: none; color: #9ca3af; font-size: 28px; line-height: 1; cursor: pointer; padding: 4px 8px;">&times;</button>
        </div>
        
        <!-- Scrollable Content -->
        <div style="overflow-y: auto; flex: 1; padding: 20px 24px;">
            <form action="{{ route('fin.vendors.payment', $vendor->id) }}" method="POST" id="paymentForm">
                @csrf
                <div style="display: flex; flex-direction: column; gap: 20px;">
                    <!-- Date Fields -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">Transaction Date <span class="text-red-500">*</span></label>
                            <input type="date" name="transaction_date" value="{{ date('Y-m-d') }}" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent">
                            <p class="text-xs text-gray-500 mt-1">Actual payment date</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">Posted Date <span class="text-red-500">*</span></label>
                            <input type="date" name="posted_date" value="{{ date('Y-m-d') }}" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent">
                            <p class="text-xs text-gray-500 mt-1">Ledger entry date</p>
                        </div>
                    </div>
                    
                    <!-- Amount Field -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Amount (Rs.) <span class="text-red-500">*</span></label>
                        <input type="number" name="amount" step="0.01" min="0.01" max="{{ $vendor->getBalance() }}" required placeholder="0.00"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent text-lg font-semibold">
                        <p class="text-xs text-gray-500 mt-1">Current payable: Rs. {{ number_format($vendor->getBalance(), 2) }}</p>
                    </div>
                    
                    <!-- Payment Source Selection -->
                    <div style="padding: 12px; background: #d1fae5; border: 2px solid #6ee7b7; border-radius: 8px;">
                        <label class="block text-sm font-medium text-gray-800 mb-2">💳 Pay From:</label>
                        <select name="payment_source_account_id" id="payment_source_account_id"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent">
                            @php
                                $vendorDefaultPaymentSourceId = $vendor->default_payment_source_id;
                            @endphp
                            @foreach($accessibleCompanyAccounts ?? [] as $source)
                                @php
                                    // Skip Expense Fund — it's only for expense reimbursements, not vendor payments
                                    if ($source->account_code === 'EXP_FUND') continue;
                                    // Only show payment sources matching this vendor's business unit
                                    if ($source->business_unit_id != ($vendor->business_unit_id ?? 1)) continue;
                                    $isSelected = $vendorDefaultPaymentSourceId == $source->id;
                                    $requiresApproval = $source->account_code === 'ONLINE';
                                @endphp
                                <option value="{{ $source->id }}" data-account-category="{{ $source->account_category }}" {{ $isSelected ? 'selected' : '' }}>
                                    {{ $source->account_name }} (Rs. {{ number_format($source->current_balance, 2) }})
                                    @if($requiresApproval) - Requires Approval @endif
                                </option>
                            @endforeach
                        </select>
                        <p style="font-size: 11px; color: #047857; font-weight: 600; margin: 6px 0 0 0;">
                            ⚠️ Online payments require approval
                        </p>
                    </div>

                    <!-- ⭐ Receiving bank — mandatory when paying from an ONLINE bank account -->
                    <div id="vendorBankField" style="display: none; padding: 12px; background: #EFF6FF; border: 2px solid #BFDBFE; border-radius: 8px;">
                        <label class="block text-sm font-medium text-gray-800 mb-2">🏦 Paid from Bank <span class="text-red-500">*</span></label>
                        <div id="vendorBankChips" style="display: flex; flex-wrap: wrap; gap: 6px;"></div>
                        <input type="hidden" name="receiving_account_id" id="vendor_receiving_account_id">
                        <p style="font-size: 11px; color: #1D4ED8; margin: 6px 0 0 0;">Which bank this online payment leaves from — keeps per-bank balances correct.</p>
                    </div>

                    <!-- Description -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Description (Optional)</label>
                        <textarea name="description" rows="2" placeholder="Add any notes about this payment..."
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent text-sm"></textarea>
                    </div>
                    
                    <!-- Warning -->
                    <div style="padding: 12px; background: #d1fae5; border: 2px solid #6ee7b7; border-radius: 8px;">
                        <p style="font-size: 12px; color: #065f46; font-weight: 600; margin: 0;">
                            ✓ This will reduce the amount payable to this vendor.
                        </p>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Fixed Footer with Actions -->
        <div style="border-top: 1px solid #e5e7eb; background: #f9fafb; padding: 20px 24px; flex-shrink: 0;">
            <div style="display: flex; gap: 12px;">
                <button type="button" onclick="closePaymentModal()" style="flex: 1; padding: 12px 16px; border: 1px solid #d1d5db; background: white; color: #374151; font-weight: 500; border-radius: 8px; cursor: pointer; font-size: 14px;">
                    Cancel
                </button>
                <button type="submit" form="paymentForm"
                        style="flex: 1; padding: 12px 16px; background: #059669; color: white; font-weight: 500; border-radius: 8px; cursor: pointer; border: none; font-size: 14px;">
                    ✓ Record Payment
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Vendor Data
const vendorName = '{{ $vendor->vendor_name }}';

// Vendor Products Data (fetched from server)
let vendorProducts = [];
let vendorSupportsIng = false;
let lineItemCounter = 0;

// Fetch vendor products on page load
document.addEventListener('DOMContentLoaded', function() {
    fetchVendorProducts();
});

function fetchVendorProducts() {
    fetch('{{ route('fin.vendors.products.list', $vendor->id) }}')
        .then(response => response.json())
        .then(data => {
            vendorProducts = data.products || [];
            vendorSupportsIng = data.supports_ingredients === true;
        })
        .catch(error => {
            console.error('Error fetching vendor products:', error);
            vendorProducts = [];
        });
}

function openPurchaseModal() {
    openPurchaseModalWithDate(null);
}

// Open purchase modal with a specific date pre-filled
function openPurchaseModalWithDate(transactionDate) {
    const modal = document.getElementById('purchaseModal');
    if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }
    modal.classList.remove('hidden');
    Object.assign(modal.style, {
        display: 'flex',
        position: 'fixed',
        top: '0',
        left: '0',
        right: '0',
        bottom: '0',
        zIndex: '99999'
    });
    document.body.style.overflow = 'hidden';
    
    // Pre-fill dates if provided
    if (transactionDate) {
        const transactionDateInput = modal.querySelector('input[name="transaction_date"]');
        const postedDateInput = modal.querySelector('input[name="posted_date"]');
        if (transactionDateInput) transactionDateInput.value = transactionDate;
        if (postedDateInput) postedDateInput.value = transactionDate;
    } else {
        // Reset to today's date when opening normally
        const today = new Date().toISOString().split('T')[0];
        const transactionDateInput = modal.querySelector('input[name="transaction_date"]');
        const postedDateInput = modal.querySelector('input[name="posted_date"]');
        if (transactionDateInput) transactionDateInput.value = today;
        if (postedDateInput) postedDateInput.value = today;
    }
}

function closePurchaseModal() {
    const modal = document.getElementById('purchaseModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}

function openWeightedPurchaseModal() {
    openWeightedPurchaseModalWithDate(null);
}

// Open weighted purchase modal with a specific date pre-filled
function openWeightedPurchaseModalWithDate(transactionDate) {
    const modal = document.getElementById('weightedPurchaseModal');
    if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }
    modal.classList.remove('hidden');
    Object.assign(modal.style, {
        display: 'flex',
        position: 'fixed',
        top: '0',
        left: '0',
        right: '0',
        bottom: '0',
        zIndex: '99999'
    });
    document.body.style.overflow = 'hidden';
    
    // Pre-fill dates if provided
    if (transactionDate) {
        const transactionDateInput = modal.querySelector('input[name="transaction_date"]');
        const postedDateInput = modal.querySelector('input[name="posted_date"]');
        if (transactionDateInput) transactionDateInput.value = transactionDate;
        if (postedDateInput) postedDateInput.value = transactionDate;
    } else {
        // Reset to today's date when opening normally
        const today = new Date().toISOString().split('T')[0];
        const transactionDateInput = modal.querySelector('input[name="transaction_date"]');
        const postedDateInput = modal.querySelector('input[name="posted_date"]');
        if (transactionDateInput) transactionDateInput.value = today;
        if (postedDateInput) postedDateInput.value = today;
    }
    
    // Automatically add the first product line item with default product pre-selected
    const container = document.getElementById('lineItemsContainer');
    if (!container.children.length || container.children.length === 0) {
        addLineItem(true); // Pass true to auto-select default product
    }
}

function closeWeightedPurchaseModal() {
    const modal = document.getElementById('weightedPurchaseModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
        
        // Clear line items
        document.getElementById('lineItemsContainer').innerHTML = '';
        lineItemCounter = 0;
        
        // Reset adjustment fields (visible and hidden) - use empty string for negative default on focus
        const adjustmentInput = document.getElementById('adjustmentAmount');
        const hiddenAdjustmentInput = document.getElementById('hiddenAdjustmentAmount');
        if (adjustmentInput) adjustmentInput.value = '';
        if (hiddenAdjustmentInput) hiddenAdjustmentInput.value = '0';
        
        updateGrandTotal();
        
        // Show empty message again
        const emptyMsg = document.getElementById('emptyLineItemsMsg');
        if (emptyMsg) emptyMsg.style.display = 'block';
    }
}

function addLineItem(isInitialLoad = false) {
    lineItemCounter++;
    const container = document.getElementById('lineItemsContainer');
    const emptyMsg = document.getElementById('emptyLineItemsMsg');
    if (emptyMsg) emptyMsg.style.display = 'none';
    
    // Find default product if this is initial load or user wants default
    const defaultProduct = vendorProducts.find(p => p.is_default === 1 || p.is_default === true);
    
    // Create product options HTML
    let productOptions = '<option value="">-- Select Product --</option>';
    vendorProducts.forEach(product => {
        const isSelected = isInitialLoad && defaultProduct && product.id === defaultProduct.id ? 'selected' : '';
        productOptions += `<option value="${product.id}" data-rate="${product.rate_per_unit}" data-unit="${product.unit}" ${isSelected}>${product.product_name} (${product.unit}) - Rs. ${parseFloat(product.rate_per_unit).toFixed(2)}/${product.unit}</option>`;
    });
    
    const lineItem = document.createElement('div');
    lineItem.className = 'line-item-row bg-white p-4 rounded-lg border-2 border-gray-200 hover:border-orange-300 transition-colors duration-150';
    lineItem.id = `lineItem${lineItemCounter}`;
    lineItem.innerHTML = `
        <div class="flex items-start gap-3">
            <div class="flex-1 grid grid-cols-12 gap-3">
                <div class="col-span-6">
                    <label class="text-xs font-medium text-gray-600 mb-1 block">Product</label>
                    <select name="items[${lineItemCounter}][product_id]" onchange="updateLineItem(${lineItemCounter})" required
                            class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                        ${productOptions}
                    </select>
                    <div class="line-shelf text-[11px] mt-1" style="color:#065F46;"></div>
                </div>
                <div class="col-span-2">
                    <label class="text-xs font-medium text-gray-600 mb-1 block">Qty</label>
                    <input type="number" name="items[${lineItemCounter}][quantity]" step="0.001" min="0.001" required
                           onchange="updateLineItem(${lineItemCounter})" oninput="updateLineItem(${lineItemCounter})"
                           class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent text-right"
                           placeholder="0">
                </div>
                <div class="col-span-2">
                    <label class="text-xs font-medium text-gray-600 mb-1 block">Rate</label>
                    <input type="number" name="items[${lineItemCounter}][rate]" step="0.01" min="0.01" readonly
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg bg-gray-50 text-right"
                           placeholder="0.00">
                </div>
                <div class="col-span-2">
                    <label class="text-xs font-medium text-gray-600 mb-1 block">Total</label>
                    <input type="number" id="lineTotal${lineItemCounter}" readonly
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg bg-gradient-to-r from-orange-50 to-white font-semibold text-right text-orange-700"
                           placeholder="0.00">
                </div>
            </div>
            <div class="flex-shrink-0" style="padding-top: 22px;">
                <button type="button" onclick="removeLineItem(${lineItemCounter})"
                        class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors duration-150" title="Remove item">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
        <input type="hidden" name="items[${lineItemCounter}][unit]" value="">
        <input type="hidden" name="items[${lineItemCounter}][product_name]" value="">
    `;
    
    // Insert at the beginning instead of end for easier access
    container.insertBefore(lineItem, container.firstChild);
}

function updateLineItem(id) {
    const row = document.getElementById(`lineItem${id}`);
    if (!row) return;
    
    const productSelect = row.querySelector('select[name*="[product_id]"]');
    const quantityInput = row.querySelector('input[name*="[quantity]"]');
    const rateInput = row.querySelector('input[name*="[rate]"]');
    const totalInput = row.querySelector(`#lineTotal${id}`);
    const unitInput = row.querySelector('input[name*="[unit]"]');
    const nameInput = row.querySelector('input[name*="[product_name]"]');
    
    const selectedOption = productSelect.options[productSelect.selectedIndex];

    // ❄🧂 Sep-30: what is on the shelf now (Frozen by-weight vendors, tracked ingredients only)
    //   …and what the line COUNTS AS, or that it will not count (the pack size lives on the product).
    const shelfEl = row.querySelector('.line-shelf');
    if (shelfEl) {
        const vp = selectedOption && selectedOption.value
            ? vendorProducts.find(p => String(p.id) === String(selectedOption.value)) : null;
        let t = '';
        shelfEl.style.color = '#065F46';
        if (vp && vendorSupportsIng) {
            const container = vp.unit_code === 'pack' || vp.unit_code === 'box';
            if (vp.ingredient_id) {
                t = '❄ counts towards ' + (vp.ingredient_name || 'its ingredient') +
                    (container && Number(vp.pack_qty_base) > 0 ? ' · ' + Number(vp.pack_qty_base) + ' ' + (vp.ingredient_base_unit || '') + ' in one ' + vp.unit_code : '');
            } else {
                t = '⚠ Not linked to a recipe ingredient, so this line will not count as bought. If it goes into a recipe: Products → Edit → pick the ingredient' +
                    (container ? ' and type how much is in one ' + vp.unit_code : '') + '.';
                shelfEl.style.color = '#B45309';
            }
        }
        if (vp && vp.on_shelf_text) {
            t += (t ? ' · ' : '') + '🧂 ' + vp.on_shelf_text + ' of ' + (vp.ingredient_name || 'it') + ' on the shelf now';
        }
        shelfEl.textContent = t;
    }

    if (selectedOption && selectedOption.value) {
        const rate = parseFloat(selectedOption.getAttribute('data-rate')) || 0;
        const unit = selectedOption.getAttribute('data-unit') || '';
        const productName = selectedOption.text.split(' (')[0]; // Extract product name
        
        rateInput.value = rate.toFixed(2);
        unitInput.value = unit;
        nameInput.value = productName;
        
        const quantity = parseFloat(quantityInput.value) || 0;
        const lineTotal = quantity * rate;
        
        totalInput.value = lineTotal.toFixed(2);
    } else {
        rateInput.value = '';
        totalInput.value = '';
        unitInput.value = '';
        nameInput.value = '';
    }
    
    updateGrandTotal();
}

function removeLineItem(id) {
    const row = document.getElementById(`lineItem${id}`);
    if (row) {
        row.remove();
        updateGrandTotal();
        
        // Show empty message if no items left
        const container = document.getElementById('lineItemsContainer');
        const emptyMsg = document.getElementById('emptyLineItemsMsg');
        if (container.querySelectorAll('.line-item-row').length === 0 && emptyMsg) {
            emptyMsg.style.display = 'block';
        }
    }
}

// Sync visible adjustment input to hidden form field (for create form)
function syncAdjustmentAmount() {
    const visibleInput = document.getElementById('adjustmentAmount');
    const hiddenInput = document.getElementById('hiddenAdjustmentAmount');
    if (visibleInput && hiddenInput) {
        hiddenInput.value = visibleInput.value || 0;
    }
}

// Sync visible adjustment input to hidden form field (for edit form)
function syncEditAdjustmentAmount() {
    const visibleInput = document.getElementById('editAdjustmentAmount');
    const hiddenInput = document.getElementById('hiddenEditAdjustmentAmount');
    if (visibleInput && hiddenInput) {
        hiddenInput.value = visibleInput.value || 0;
    }
}

function updateGrandTotal() {
    // Sync adjustment to hidden field first
    syncAdjustmentAmount();
    
    // Calculate items total
    let itemsTotal = 0;
    document.querySelectorAll('[id^="lineTotal"]').forEach(input => {
        const value = parseFloat(input.value) || 0;
        itemsTotal += value;
    });
    
    // Get adjustment amount
    const adjustmentInput = document.getElementById('adjustmentAmount');
    const adjustmentAmount = parseFloat(adjustmentInput?.value) || 0;
    
    // Calculate grand total
    const grandTotal = itemsTotal + adjustmentAmount;
    
    // Update displays
    const itemsTotalEl = document.getElementById('itemsTotal');
    if (itemsTotalEl) {
        itemsTotalEl.textContent = `Rs. ${itemsTotal.toFixed(2)}`;
    }
    
    const adjustmentRow = document.getElementById('adjustmentRow');
    const adjustmentDisplay = document.getElementById('adjustmentDisplay');
    if (adjustmentRow && adjustmentDisplay) {
        if (adjustmentAmount !== 0) {
            adjustmentRow.classList.remove('hidden');
            const prefix = adjustmentAmount > 0 ? '+' : '';
            adjustmentDisplay.textContent = `${prefix}Rs. ${adjustmentAmount.toFixed(2)}`;
        } else {
            adjustmentRow.classList.add('hidden');
        }
    }
    
    document.getElementById('grandTotal').textContent = `Rs. ${grandTotal.toFixed(2)}`;
    
    // Update submit button state
    const submitBtn = document.getElementById('submitWeightedPurchase');
    if (grandTotal > 0 && document.querySelectorAll('.line-item-row').length > 0) {
        submitBtn.disabled = false;
        submitBtn.style.opacity = '1';
    } else {
        submitBtn.disabled = true;
        submitBtn.style.opacity = '0.5';
    }
}

function openPaymentModal() {
    openPaymentModalWithDate(null, 0);
}

// Open payment modal with a specific date pre-filled and optional payable amount
function openPaymentModalWithDate(transactionDate, payableAmount = 0) {
    const modal = document.getElementById('paymentModal');
    if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }
    modal.classList.remove('hidden');
    Object.assign(modal.style, {
        display: 'flex',
        position: 'fixed',
        top: '0',
        left: '0',
        right: '0',
        bottom: '0',
        zIndex: '99999'
    });
    document.body.style.overflow = 'hidden';
    
    // Pre-fill dates if provided
    if (transactionDate) {
        const transactionDateInput = modal.querySelector('input[name="transaction_date"]');
        const postedDateInput = modal.querySelector('input[name="posted_date"]');
        if (transactionDateInput) transactionDateInput.value = transactionDate;
        if (postedDateInput) postedDateInput.value = transactionDate;
    } else {
        // Reset to today's date when opening normally
        const today = new Date().toISOString().split('T')[0];
        const transactionDateInput = modal.querySelector('input[name="transaction_date"]');
        const postedDateInput = modal.querySelector('input[name="posted_date"]');
        if (transactionDateInput) transactionDateInput.value = today;
        if (postedDateInput) postedDateInput.value = today;
    }
    
    // Pre-fill amount if payable amount is positive
    const amountInput = modal.querySelector('input[name="amount"]');
    if (amountInput) {
        if (payableAmount > 0) {
            amountInput.value = payableAmount.toFixed(2);
        } else {
            amountInput.value = '0.00'; // Reset to 0 when no payable amount
        }
    }

    // ⭐ Refresh the receiving-bank picker for the currently selected source.
    renderVendorBankPicker();
}

// ============================================================
// ⭐ Receiving-bank picker for ONLINE vendor payments
// Shown (and required) only when the selected Pay-From account is a
// bank-category account. Chips show each bank's computed balance.
// ============================================================
const vendorReceivingBanks = @json($receivingBanks ?? []);

function vendorPaySourceIsOnline() {
    const sel = document.getElementById('payment_source_account_id');
    if (!sel) return false;
    const opt = sel.options[sel.selectedIndex];
    return !!(opt && opt.dataset && opt.dataset.accountCategory === 'bank');
}

function selectVendorBank(id) {
    document.getElementById('vendor_receiving_account_id').value = id;
    renderVendorBankPicker();
}

function renderVendorBankPicker() {
    const field = document.getElementById('vendorBankField');
    const chips = document.getElementById('vendorBankChips');
    const hidden = document.getElementById('vendor_receiving_account_id');
    if (!field || !chips || !hidden) return;

    if (!vendorPaySourceIsOnline() || vendorReceivingBanks.length === 0) {
        field.style.display = 'none';
        hidden.value = '';
        return;
    }
    field.style.display = 'block';
    const current = hidden.value;
    chips.innerHTML = vendorReceivingBanks.map(b => {
        const active = String(current) === String(b.id);
        const color = b.color_hex || '#3B82F6';
        const bal = (b.balance !== undefined && b.balance !== null)
            ? ` · Rs ${Math.round(Number(b.balance)).toLocaleString()}`
            : '';
        return `<button type="button" onclick="selectVendorBank(${b.id})" style="padding:6px 14px; border-radius:16px; border:1px solid ${active ? color : '#CBD5E1'}; background:${active ? color : '#F1F5F9'}; color:${active ? '#fff' : '#475569'}; font-size:13px; font-weight:600; cursor:pointer;">${(b.short_code || b.name)}<span style="font-weight:500; opacity:0.85;">${bal}</span></button>`;
    }).join('');
}

document.addEventListener('DOMContentLoaded', function() {
    const paySel = document.getElementById('payment_source_account_id');
    if (paySel) {
        paySel.addEventListener('change', renderVendorBankPicker);
    }
    // Block submit when an online source is chosen but no bank picked.
    const payForm = document.getElementById('paymentForm');
    if (payForm) {
        payForm.addEventListener('submit', function(e) {
            if (vendorPaySourceIsOnline() && !document.getElementById('vendor_receiving_account_id').value) {
                e.preventDefault();
                alert('Select which bank this online payment is made from.');
            }
        });
    }
});

function closePaymentModal() {
    const modal = document.getElementById('paymentModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}

// Close modals when clicking outside
document.addEventListener('click', function(event) {
    const purchaseModal = document.getElementById('purchaseModal');
    const paymentModal = document.getElementById('paymentModal');
    const weightedPurchaseModal = document.getElementById('weightedPurchaseModal');
    
    if (event.target === purchaseModal) {
        closePurchaseModal();
    }
    if (event.target === paymentModal) {
        closePaymentModal();
    }
    if (event.target === weightedPurchaseModal) {
        closeWeightedPurchaseModal();
    }
});

// View Transaction Details
function viewTransactionDetails(transactionId) {
    fetch(`/finance/ledger/transaction/${transactionId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showTransactionModal(data.transaction);
            } else {
                alert('Error loading transaction details');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading transaction details');
        });
}

function showTransactionModal(transaction) {
    console.log('Transaction data received:', transaction); // DEBUG
    console.log('Bill image path:', transaction.bill_image); // DEBUG
    
    const modal = document.getElementById('transactionDetailsModal');
    const content = document.getElementById('transactionDetailsContent');
    const footer = document.getElementById('transactionDetailsFooter');
    
    // Calculate items total if line items exist
    let itemsTotal = 0;
    if (transaction.line_items && transaction.line_items.length > 0) {
        itemsTotal = transaction.line_items.reduce((sum, item) => sum + parseFloat(item.line_total || 0), 0);
    }
    const adjustmentAmount = parseFloat(transaction.adjustment_amount || 0);
    const hasAdjustment = adjustmentAmount !== 0;
    
    let html = `
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 4px;">Date</label>
                <p style="font-size: 14px; color: #111827;">${transaction.transaction_date || '-'}</p>
            </div>
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 4px;">Type</label>
                <p style="font-size: 14px; color: #111827;">${transaction.transaction_type || '-'}</p>
            </div>
            <div style="grid-column: 1 / -1;">
                <label style="display: block; font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 4px;">Description</label>
                <p style="font-size: 14px; color: #111827;">${transaction.description || '-'}</p>
            </div>
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 4px;">Amount</label>
                <p style="font-size: 18px; font-weight: 700; color: #111827;">Rs. ${parseFloat(transaction.amount).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</p>
            </div>
            ${hasAdjustment ? `
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 4px;">Adjustment/Discount</label>
                <p style="font-size: 16px; font-weight: 600; color: ${adjustmentAmount < 0 ? '#059669' : '#ea580c'};">
                    ${adjustmentAmount > 0 ? '+' : ''}Rs. ${adjustmentAmount.toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2})}
                </p>
            </div>
            ` : ''}
        </div>
    `;
    
    // Show line items if available
    if (transaction.line_items && transaction.line_items.length > 0) {
        html += `
            <div style="margin-top: 20px;">
                <label style="display: block; font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 8px;">📦 Purchase Items</label>
                <div style="border: 2px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
                    <table style="width: 100%; font-size: 13px;">
                        <thead style="background: #f9fafb; border-bottom: 1px solid #e5e7eb;">
                            <tr>
                                <th style="padding: 8px; text-align: left; font-weight: 600; color: #374151;">Product</th>
                                <th style="padding: 8px; text-align: right; font-weight: 600; color: #374151;">Qty</th>
                                <th style="padding: 8px; text-align: right; font-weight: 600; color: #374151;">Rate</th>
                                <th style="padding: 8px; text-align: right; font-weight: 600; color: #374151;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
        `;
        
        transaction.line_items.forEach(item => {
            html += `
                <tr style="border-bottom: 1px solid #f3f4f6;">
                    <td style="padding: 8px; color: #111827;">${item.product_name}</td>
                    <td style="padding: 8px; text-align: right; color: #6b7280;">${item.quantity} ${item.unit}</td>
                    <td style="padding: 8px; text-align: right; color: #6b7280;">Rs. ${parseFloat(item.rate_per_unit).toFixed(2)}</td>
                    <td style="padding: 8px; text-align: right; font-weight: 600; color: #111827;">Rs. ${parseFloat(item.line_total).toFixed(2)}</td>
                </tr>
            `;
        });
        
        html += `
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    }
    
    // Aug-2026: EVERY attached image (bill_images from the details endpoint — full URLs).
    // Fallback to the single bill_image for a not-yet-updated backend. NOTE: the endpoint
    // returns FULL URLs; the old code prepended /storage/ to one, which double-prefixed it.
    const galleryImages = (transaction.bill_images && transaction.bill_images.length)
        ? transaction.bill_images.map(im => im.url)
        : (transaction.bill_image ? [transaction.bill_image] : []);
    if (galleryImages.length) {
        const galleryHtml = galleryImages.map(u => {
            const url = /^https?:\/\//.test(u) ? u : ('/storage/' + u);
            const fb = url.replace('/public-storage/', '/storage/');
            return `
                <img src="${url}"
                     alt="Bill Image"
                     style="width: 100%; max-height: 400px; object-fit: contain; border-radius: 4px; cursor: pointer; margin-bottom: 8px;"
                     onclick="window.open(this.src, '_blank')"
                     onerror="
                        if (!this.dataset.triedFallback) {
                            this.dataset.triedFallback = 'true';
                            this.src = '${fb}';
                        } else {
                            this.style.display = 'none';
                        }
                     ">`;
        }).join('');
        html += `
            <div style="margin-top: 20px;">
                <label style="display: block; font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 8px;">📎 Bill Image${galleryImages.length > 1 ? 's · ' + galleryImages.length : ''}</label>
                <div style="border: 2px solid #e5e7eb; border-radius: 8px; padding: 8px; background: #f9fafb;" id="billImageContainer">
                    ${galleryHtml}
                    <p style="text-align: center; font-size: 11px; color: #6b7280; margin-top: 4px;">Click an image to view full size</p>
                </div>
            </div>
        `;
    } else {
        console.log('No bill image to display'); // DEBUG
    }
    
    content.innerHTML = html;
    
    // Update footer with Edit button
    footer.innerHTML = `
        <button type="button" onclick="openEditTransactionModal(${transaction.id})" 
                style="padding: 10px 24px; border: 2px solid #3b82f6; background: white; color: #3b82f6; font-weight: 600; border-radius: 8px; cursor: pointer; transition: all 0.15s;">
            ✏️ Edit
        </button>
        <button type="button" onclick="closeTransactionModal()" 
                style="padding: 10px 24px; border: none; background: #3b82f6; color: white; font-weight: 600; border-radius: 8px; cursor: pointer; transition: all 0.15s; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            Close
        </button>
    `;
    
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
}

function closeTransactionModal() {
    const modal = document.getElementById('transactionDetailsModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}

// Edit Transaction Functions
function openEditTransactionModal(transactionId) {
    // Close the view modal first
    closeTransactionModal();
    
    // Fetch transaction details
    fetch(`/finance/ledger/transaction/${transactionId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const transaction = data.transaction;
                
                // Check if this is a weighted purchase (has line items)
                if (transaction.line_items && transaction.line_items.length > 0) {
                    // Open weighted purchase edit modal
                    openWeightedPurchaseEditModal(transaction);
                    return;
                }
                
                // Populate form for simple transactions
                document.getElementById('edit_transaction_id').value = transaction.id;
                document.getElementById('edit_transaction_date').value = transaction.transaction_date.split(' ')[0];
                document.getElementById('edit_amount').value = transaction.amount;
                document.getElementById('edit_description').value = transaction.description || '';
                
                // Handle existing bill image. The endpoint returns a FULL URL — use it as-is
                // (the old /storage/ prefixing double-prefixed it and always fell to "not
                // available"). Relative paths (older payloads) keep the prefix fallback chain.
                if (transaction.bill_image) {
                    document.getElementById('currentImageSection').style.display = 'block';
                    const imgEl = document.getElementById('currentBillImage');
                    const isAbs = /^https?:\/\//.test(transaction.bill_image);
                    const primary = isAbs ? transaction.bill_image : ('/storage/' + transaction.bill_image);
                    const fallbackUrl = isAbs
                        ? transaction.bill_image.replace('/public-storage/', '/storage/')
                        : ('/public-storage/' + transaction.bill_image);
                    imgEl.dataset.triedFallback = '';
                    imgEl.style.display = '';
                    imgEl.src = primary;
                    imgEl.onerror = function() {
                        if (!this.dataset.triedFallback) {
                            this.dataset.triedFallback = 'true';
                            this.src = fallbackUrl;
                        } else {
                            // Image not available in either location
                            this.style.display = 'none';
                        }
                    };
                    document.getElementById('billImageLabel').textContent = 'Replace Bill Image 📷 (first image)';
                    document.getElementById('billImageHint').textContent = 'Uploading replaces the FIRST image; use the Ledger Hub to manage several.';
                } else {
                    document.getElementById('currentImageSection').style.display = 'none';
                    document.getElementById('billImageLabel').textContent = 'Bill Image 📷';
                    document.getElementById('billImageHint').textContent = 'Upload vendor\'s bill/receipt (optional)';
                }
                
                // Show modal
                const modal = document.getElementById('editTransactionModal');
                modal.classList.remove('hidden');
                modal.style.display = 'flex';
            } else {
                alert('Error loading transaction details');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading transaction details');
        });
}

// Edit Weighted Purchase Modal
function openWeightedPurchaseEditModal(transaction) {
    console.log('Opening weighted purchase edit modal with transaction:', transaction);
    
    // Populate edit form with existing data
    document.getElementById('edit_weighted_transaction_id').value = transaction.id;
    
    // Set date directly (backend now returns Y-m-d format)
    document.getElementById('edit_weighted_date').value = transaction.transaction_date;
    document.getElementById('edit_weighted_description').value = transaction.description || '';
    
    // Set adjustment amount (if exists) and sync to hidden field
    const adjustmentInput = document.getElementById('editAdjustmentAmount');
    const hiddenAdjustmentInput = document.getElementById('hiddenEditAdjustmentAmount');
    if (adjustmentInput) {
        adjustmentInput.value = transaction.adjustment_amount || 0;
    }
    if (hiddenAdjustmentInput) {
        hiddenAdjustmentInput.value = transaction.adjustment_amount || 0;
    }
    
    // Clear and populate line items
    const container = document.getElementById('editLineItemsContainer');
    container.innerHTML = '';
    
    let editLineItemCounter = 0;
    transaction.line_items.forEach((item, index) => {
        console.log('Adding line item:', item);
        addEditLineItem(item, editLineItemCounter++);
    });
    
    // Update grand total after a short delay to ensure DOM is ready
    setTimeout(() => {
        updateEditGrandTotal();
    }, 100);
    
    // Show modal
    const modal = document.getElementById('editWeightedPurchaseModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
}

function closeEditWeightedPurchaseModal() {
    const modal = document.getElementById('editWeightedPurchaseModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
    document.getElementById('editLineItemsContainer').innerHTML = '';
    
    // Reset adjustment fields (visible and hidden) - use empty string for negative default on focus
    const adjustmentInput = document.getElementById('editAdjustmentAmount');
    const hiddenAdjustmentInput = document.getElementById('hiddenEditAdjustmentAmount');
    if (adjustmentInput) {
        adjustmentInput.value = '';
    }
    if (hiddenAdjustmentInput) {
        hiddenAdjustmentInput.value = '0';
    }
}

function addEditLineItem(existingItem = null, index = null, isInitialLoad = false) {
    const container = document.getElementById('editLineItemsContainer');
    const itemIndex = index !== null ? index : Date.now();
    
    console.log('addEditLineItem called with:', {existingItem, index, itemIndex, isInitialLoad});
    
    // Find default product if this is initial load and no existing item
    const defaultProduct = !existingItem && isInitialLoad ? vendorProducts.find(p => p.is_default === 1 || p.is_default === true) : null;
    
    // Build product options HTML
    let productOptionsHtml = '<option value="">Select Product</option>';
    vendorProducts.forEach(p => {
        let selected = '';
        if (existingItem && existingItem.vendor_product_id == p.id) {
            selected = 'selected';
        } else if (defaultProduct && p.id === defaultProduct.id) {
            selected = 'selected';
        }
        console.log(`Product ${p.product_name}: existingItem.vendor_product_id=${existingItem?.vendor_product_id}, p.id=${p.id}, selected=${selected}`);
        productOptionsHtml += `<option value="${p.id}" data-unit="${p.unit}" data-rate="${p.rate_per_unit}" data-name="${p.product_name}" ${selected}>${p.product_name}</option>`;
    });
    
    // Use default product values if available and no existing item
    const itemData = existingItem || (defaultProduct ? {
        product_name: defaultProduct.product_name,
        quantity: '',
        unit: defaultProduct.unit,
        rate_per_unit: defaultProduct.rate_per_unit,
        line_total: 0
    } : {});
    
    const itemHtml = `
        <div class="edit-line-item border border-gray-300 rounded-lg p-3 bg-gray-50" data-index="${itemIndex}">
            <div class="grid grid-cols-12 gap-2 items-end">
                <div class="col-span-4">
                    <label class="block text-xs font-medium text-gray-700 mb-1">Product</label>
                    <select name="items[${itemIndex}][product_id]" required onchange="updateEditProductDetails(${itemIndex})" class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-orange-500">
                        ${productOptionsHtml}
                    </select>
                    <input type="hidden" name="items[${itemIndex}][product_name]" value="${itemData.product_name || ''}">
                </div>
                <div class="col-span-2">
                    <label class="block text-xs font-medium text-gray-700 mb-1">Qty</label>
                    <input type="number" name="items[${itemIndex}][quantity]" step="0.001" min="0.001" value="${itemData.quantity || ''}" required onchange="calculateEditLineTotal(${itemIndex})" class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-orange-500">
                </div>
                <div class="col-span-2">
                    <label class="block text-xs font-medium text-gray-700 mb-1">Unit</label>
                    <input type="text" name="items[${itemIndex}][unit]" value="${itemData.unit || ''}" required readonly class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded bg-gray-100">
                </div>
                <div class="col-span-2">
                    <label class="block text-xs font-medium text-gray-700 mb-1">Rate</label>
                    <input type="number" name="items[${itemIndex}][rate]" step="0.01" min="0.01" value="${itemData.rate_per_unit || ''}" required onchange="calculateEditLineTotal(${itemIndex})" class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-orange-500">
                </div>
                <div class="col-span-1">
                    <label class="block text-xs font-medium text-gray-700 mb-1">Total</label>
                    <input type="text" readonly class="line-total w-full px-2 py-1.5 text-sm border border-gray-300 rounded bg-gray-100 font-semibold" value="${itemData.line_total ? parseFloat(itemData.line_total).toFixed(2) : '0.00'}">
                </div>
                <div class="col-span-1 flex items-end justify-center">
                    <button type="button" onclick="removeEditLineItem(${itemIndex})" class="px-2 py-1.5 text-red-600 hover:text-red-800 text-lg font-bold">×</button>
                </div>
            </div>
        </div>
    `;
    
    // Insert at the beginning instead of end for easier access
    container.insertAdjacentHTML('afterbegin', itemHtml);
}

function updateEditProductDetails(index) {
    const item = document.querySelector(`.edit-line-item[data-index="${index}"]`);
    const select = item.querySelector('select');
    const selectedOption = select.options[select.selectedIndex];
    
    if (selectedOption.value) {
        item.querySelector('input[name*="[unit]"]').value = selectedOption.dataset.unit || '';
        item.querySelector('input[name*="[rate]"]').value = selectedOption.dataset.rate || '';
        item.querySelector('input[name*="[product_name]"]').value = selectedOption.dataset.name || selectedOption.text;
        calculateEditLineTotal(index);
    }
}

function calculateEditLineTotal(index) {
    const item = document.querySelector(`.edit-line-item[data-index="${index}"]`);
    const qty = parseFloat(item.querySelector('input[name*="[quantity]"]').value) || 0;
    const rate = parseFloat(item.querySelector('input[name*="[rate]"]').value) || 0;
    const total = qty * rate;
    
    item.querySelector('.line-total').value = total.toFixed(2);
    updateEditGrandTotal();
}

function removeEditLineItem(index) {
    document.querySelector(`.edit-line-item[data-index="${index}"]`).remove();
    updateEditGrandTotal();
}

function updateEditGrandTotal() {
    // Sync adjustment to hidden field first
    syncEditAdjustmentAmount();
    
    // Calculate items total
    let itemsTotal = 0;
    document.querySelectorAll('#editLineItemsContainer .line-total').forEach(input => {
        itemsTotal += parseFloat(input.value) || 0;
    });
    
    // Get adjustment amount
    const adjustmentInput = document.getElementById('editAdjustmentAmount');
    const adjustmentAmount = parseFloat(adjustmentInput?.value) || 0;
    
    // Calculate grand total
    const grandTotal = itemsTotal + adjustmentAmount;
    
    // Update displays
    const itemsTotalEl = document.getElementById('editItemsTotal');
    if (itemsTotalEl) {
        itemsTotalEl.textContent = `Rs. ${itemsTotal.toFixed(2)}`;
    }
    
    const adjustmentRow = document.getElementById('editAdjustmentRow');
    const adjustmentDisplay = document.getElementById('editAdjustmentDisplay');
    if (adjustmentRow && adjustmentDisplay) {
        if (adjustmentAmount !== 0) {
            adjustmentRow.classList.remove('hidden');
            const prefix = adjustmentAmount > 0 ? '+' : '';
            adjustmentDisplay.textContent = `${prefix}Rs. ${adjustmentAmount.toFixed(2)}`;
        } else {
            adjustmentRow.classList.add('hidden');
        }
    }
    
    document.getElementById('editGrandTotal').textContent = 'Rs. ' + grandTotal.toFixed(2);
}

function submitEditWeightedPurchase() {
    const form = document.getElementById('editWeightedPurchaseForm');
    const formData = new FormData(form);
    const transactionId = document.getElementById('edit_weighted_transaction_id').value;
    
    // Validate that we have at least one line item
    const lineItems = document.querySelectorAll('.edit-line-item');
    if (lineItems.length === 0) {
        alert('Please add at least one product line item');
        return;
    }
    
    fetch(`/finance/vendors/transaction/${transactionId}/update`, {
        method: 'POST',
        body: formData,
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeEditWeightedPurchaseModal();
            // Force a full page reload to refresh all data including balance cards
            window.location.reload(true);
        } else {
            alert('Error: ' + (data.message || 'Failed to update transaction'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error updating transaction');
    });
}

function closeEditTransactionModal() {
    const modal = document.getElementById('editTransactionModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
    
    // Reset form
    document.getElementById('editTransactionForm').reset();
}

function submitEditTransaction() {
    const transactionId = document.getElementById('edit_transaction_id').value;
    const form = document.getElementById('editTransactionForm');
    const formData = new FormData(form);
    
    fetch(`/finance/vendors/transaction/${transactionId}/update`, {
        method: 'POST',
        body: formData,
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeEditTransactionModal();
            // Force a full page reload to refresh all data including balance cards
            window.location.reload(true);
        } else {
            alert('Error: ' + (data.message || 'Failed to update transaction'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error updating transaction');
    });
}

// Delete Transaction Function
function confirmDeleteTransaction(transactionId, transactionType, amount) {
    const typeName = transactionType === 'vendor_purchase' ? 'Purchase' : 'Payment';
    const message = `Are you sure you want to delete this ${typeName} of Rs. ${parseFloat(amount).toFixed(2)}?\n\nThis will:\n- Remove the transaction from ledger\n- Reverse the account balances\n- Delete any associated line items\n\nThis action cannot be undone!`;
    
    if (confirm(message)) {
        // Show loading state
        const deleteBtn = event.target;
        const originalText = deleteBtn.innerHTML;
        deleteBtn.innerHTML = '⏳';
        deleteBtn.disabled = true;
        
        fetch(`/finance/vendors/transaction/${transactionId}/delete`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Transaction deleted successfully!');
                location.reload();
            } else {
                alert('Error: ' + (data.message || 'Failed to delete transaction'));
                deleteBtn.innerHTML = originalText;
                deleteBtn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error deleting transaction');
            deleteBtn.innerHTML = originalText;
            deleteBtn.disabled = false;
        });
    }
}

// ===== DATE GROUPING EXPAND/COLLAPSE FUNCTIONS =====
let expandAllState = {{ $expandAll ? 'true' : 'false' }};

function toggleDateGroup(date) {
    const group = document.getElementById('group-' + date);
    const icon = document.getElementById('icon-' + date);
    
    if (group.classList.contains('hidden')) {
        group.classList.remove('hidden');
        icon.textContent = '📂';
    } else {
        group.classList.add('hidden');
        icon.textContent = '📁';
    }
}

function toggleExpandAll() {
    expandAllState = !expandAllState;
    
    // Update UI
    const allGroups = document.querySelectorAll('[id^="group-"]');
    const allIcons = document.querySelectorAll('[id^="icon-"]');
    const toggleText = document.getElementById('toggleExpandText');
    
    allGroups.forEach(group => {
        if (expandAllState) {
            group.classList.remove('hidden');
        } else {
            group.classList.add('hidden');
        }
    });
    
    allIcons.forEach(icon => {
        icon.textContent = expandAllState ? '📂' : '📁';
    });
    
    toggleText.textContent = expandAllState ? '📕 Collapse All' : '📖 Expand All';
    
    // Save preference to session
    fetch('{{ route('fin.vendors.toggle-expand') }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({
            expand_all: expandAllState
        })
    })
    .then(response => response.json())
    .then(data => {
        console.log('Expand preference saved:', data);
    })
    .catch(error => {
        console.error('Error saving preference:', error);
    });
}
</script>

<!-- Transaction Details Modal -->
<div id="transactionDetailsModal" class="hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 20px;" onclick="closeTransactionModal()">
    <div onclick="event.stopPropagation()" style="background: white; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 700px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
        <!-- Fixed Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(135deg, #dbeafe 0%, #ffffff 100%); flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: #93c5fd; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    📄
                </div>
                <div>
                    <h3 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">Transaction Details</h3>
                    <p style="font-size: 12px; color: #6b7280; margin: 2px 0 0 0;">View complete transaction information</p>
                </div>
            </div>
            <button type="button" onclick="closeTransactionModal()" style="background: none; border: none; color: #9ca3af; font-size: 28px; line-height: 1; cursor: pointer; padding: 4px 8px;">&times;</button>
        </div>
        
        <!-- Scrollable Content -->
        <div id="transactionDetailsContent" style="overflow-y: auto; flex: 1; padding: 20px 24px;">
            <!-- Content will be populated by JavaScript -->
        </div>
        
        <!-- Fixed Footer -->
        <div id="transactionDetailsFooter" style="padding: 16px 24px; border-top: 1px solid #e5e7eb; background: #f9fafb; flex-shrink: 0; display: flex; justify-content: center; gap: 12px;">
            <button type="button" onclick="closeTransactionModal()" 
                    style="padding: 10px 24px; border: none; background: #3b82f6; color: white; font-weight: 600; border-radius: 8px; cursor: pointer; transition: all 0.15s; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                Close
            </button>
        </div>
    </div>
</div>

<!-- Edit Transaction Modal -->
<div id="editTransactionModal" class="hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 20px;" onclick="closeEditTransactionModal()">
    <div onclick="event.stopPropagation()" style="background: white; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 600px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
        <!-- Fixed Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(135deg, #fef3c7 0%, #ffffff 100%); flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: #fde68a; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    ✏️
                </div>
                <div>
                    <h3 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">Edit Transaction</h3>
                    <p style="font-size: 12px; color: #6b7280; margin: 2px 0 0 0;">Update transaction details</p>
                </div>
            </div>
            <button type="button" onclick="closeEditTransactionModal()" style="background: none; border: none; color: #9ca3af; font-size: 28px; line-height: 1; cursor: pointer; padding: 4px 8px;">&times;</button>
        </div>
        
        <!-- Scrollable Content -->
        <div style="overflow-y: auto; flex: 1; padding: 20px 24px;">
            <form id="editTransactionForm" method="POST" enctype="multipart/form-data">
                @csrf
                <input type="hidden" id="edit_transaction_id" name="transaction_id">
                
                <div style="display: flex; flex-direction: column; gap: 16px;">
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Date <span class="text-red-600">*</span></label>
                        <input type="date" id="edit_transaction_date" name="transaction_date" required
                               class="w-full px-3 py-2 border-2 border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500 text-gray-900">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Amount (Rs.) <span class="text-red-600">*</span></label>
                        <input type="number" id="edit_amount" name="amount" step="0.01" min="0.01" required
                               class="w-full px-3 py-2 border-2 border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500 text-gray-900">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Description</label>
                        <textarea id="edit_description" name="description" rows="3"
                                  class="w-full px-3 py-2 border-2 border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500 text-gray-900"></textarea>
                    </div>
                    
                    <div id="currentImageSection" style="display: none;">
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Current Bill Image</label>
                        <div style="border: 2px solid #e5e7eb; border-radius: 8px; padding: 8px; background: #f9fafb;">
                            <img id="currentBillImage" src="" alt="Current Bill" 
                                 style="width: 100%; max-height: 200px; object-fit: contain; border-radius: 4px; cursor: pointer;"
                                 onclick="window.open(this.src, '_blank')">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">
                            <span id="billImageLabel">Bill Image 📷</span>
                        </label>
                        <input type="file" id="edit_bill_image" name="bill_image" accept="image/*"
                               class="w-full px-3 py-2 border-2 border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500 text-gray-900 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-yellow-50 file:text-yellow-700 hover:file:bg-yellow-100">
                        <p class="text-xs text-gray-600 mt-1">📸 <span id="billImageHint">Upload vendor's bill/receipt (optional)</span></p>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Fixed Footer -->
        <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; background: #f9fafb; flex-shrink: 0; display: flex; gap: 12px;">
            <button type="button" onclick="closeEditTransactionModal()" 
                    style="flex: 1; padding: 10px 16px; border: 2px solid #d1d5db; background: white; color: #374151; font-weight: 600; border-radius: 8px; cursor: pointer; transition: all 0.15s;">
                Cancel
            </button>
            <button type="button" onclick="submitEditTransaction()"
                    style="flex: 1; padding: 10px 16px; border: none; background: #f59e0b; color: white; font-weight: 600; border-radius: 8px; cursor: pointer; transition: all 0.15s; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                ✓ Update Transaction
            </button>
        </div>
    </div>
</div>

<!-- Edit Weighted Purchase Modal -->
<div id="editWeightedPurchaseModal" class="hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 20px; overflow-y: auto;">
    <div onclick="event.stopPropagation()" style="background: white; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 1000px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
        <!-- Fixed Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(135deg, #fff7ed 0%, #ffffff 100%); flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: #fed7aa; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    ✏️
                </div>
                <div>
                    <h3 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">Edit Weighted Purchase</h3>
                    <p style="font-size: 12px; color: #6b7280; margin: 2px 0 0 0;">Modify products, quantities, and rates</p>
                </div>
            </div>
            <button type="button" onclick="closeEditWeightedPurchaseModal()" style="background: none; border: none; color: #9ca3af; font-size: 28px; line-height: 1; cursor: pointer; padding: 4px 8px;">&times;</button>
        </div>
        
        <!-- Scrollable Content -->
        <div style="overflow-y: auto; flex: 1; padding: 20px 24px;">
            <form id="editWeightedPurchaseForm" method="POST" enctype="multipart/form-data">
                @csrf
                <input type="hidden" id="edit_weighted_transaction_id" name="transaction_id">
                
                <div style="display: flex; flex-direction: column; gap: 20px;">
                    <!-- Date Field -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">Date <span class="text-red-500">*</span></label>
                            <input type="date" id="edit_weighted_date" name="transaction_date" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                        </div>
                        <div class="flex items-end">
                            <button type="button" onclick="addEditLineItem(null, null, true)" class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-150 text-sm font-medium">
                                + Add Line Item
                            </button>
                        </div>
                    </div>
                    
                    <!-- Line Items Section -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Purchase Items</label>
                        <div id="editLineItemsContainer" class="space-y-3">
                            <!-- Line items will be added here dynamically -->
                        </div>
                    </div>
                    
                    <!-- Description -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Description (Optional)</label>
                        <textarea id="edit_weighted_description" name="description" rows="2" placeholder="Add any notes about this purchase..."
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent text-sm"></textarea>
                    </div>
                    
                    <!-- Hidden adjustment field (synced with visible input in footer) -->
                    <input type="hidden" name="adjustment_amount" id="hiddenEditAdjustmentAmount" value="0">
                </div>
            </form>
        </div>
        
        <!-- Fixed Footer with Total and Actions - Compact Design -->
        <div style="border-top: 1px solid #e5e7eb; background: #f9fafb; padding: 12px 24px; flex-shrink: 0;">
            <!-- Compact Totals Row -->
            <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 12px; padding: 10px 12px; background: linear-gradient(135deg, #fed7aa 0%, #ffedd5 100%); border: 1px solid #fb923c; border-radius: 8px;">
                <!-- Items Total -->
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span style="font-size: 12px; color: #9a3412;">Items:</span>
                    <span style="font-size: 14px; font-weight: 600; color: #9a3412;" id="editItemsTotal">Rs. 0.00</span>
                </div>
                <!-- Adjustment Input (Inline) -->
                <div style="display: flex; align-items: center; gap: 6px; flex: 1;">
                    <span style="font-size: 12px; color: #9a3412; white-space: nowrap;">Adj:</span>
                    <input type="number" id="editAdjustmentAmount" step="0.01" value="" placeholder="-500"
                           style="width: 90px; padding: 4px 8px; border: 1px solid #fb923c; border-radius: 4px; font-size: 13px; text-align: right;"
                           oninput="syncEditAdjustmentAmount(); updateEditGrandTotal()"
                           onfocus="if(this.value === '' || this.value === '0') this.value = '-'">
                    <span style="font-size: 10px; color: #9a3412;">(- disc)</span>
                </div>
                <!-- Grand Total -->
                <div style="display: flex; align-items: center; gap: 8px; padding-left: 12px; border-left: 1px solid #fb923c;">
                    <span style="font-size: 13px; font-weight: 600; color: #7c2d12;">Total:</span>
                    <span style="font-size: 20px; font-weight: bold; color: #7c2d12;" id="editGrandTotal">Rs. 0.00</span>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div style="display: flex; gap: 12px;">
                <button type="button" onclick="closeEditWeightedPurchaseModal()" style="flex: 1; padding: 10px 16px; border: 1px solid #d1d5db; background: white; color: #374151; font-weight: 500; border-radius: 8px; cursor: pointer; font-size: 14px;">
                    Cancel
                </button>
                <button type="button" onclick="submitEditWeightedPurchase()"
                        style="flex: 1; padding: 10px 16px; background: #ea580c; color: white; font-weight: 500; border-radius: 8px; cursor: pointer; border: none; font-size: 14px;">
                    ✓ Update Purchase
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Vendor Report Modal (Pre-filtered for this vendor) -->
<div id="vendorReportModal" class="hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 20px; overflow-y: auto;" onclick="if(event.target === this) closeVendorReportModal()">
    <div onclick="event.stopPropagation()" style="background: white; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 1400px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
        <!-- Fixed Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(135deg, #f3e8ff 0%, #ffffff 100%); flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: #c084fc; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    📊
                </div>
                <div>
                    <h3 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">{{ $vendor->vendor_name }} - Report</h3>
                    <p style="font-size: 12px; color: #6b7280; margin: 2px 0 0 0;">View detailed purchase and payment summary</p>
                </div>
            </div>
            <button type="button" onclick="closeVendorReportModal()" style="background: none; border: none; color: #9ca3af; font-size: 28px; line-height: 1; cursor: pointer; padding: 4px 8px;">&times;</button>
        </div>
        
        <!-- Scrollable Content -->
        <div style="overflow-y: auto; flex: 1; padding: 20px 24px;">
            <!-- Report Filters - Compact Design -->
            <div class="bg-gradient-to-r from-purple-50 to-purple-100 rounded-lg p-3 border border-purple-300 mb-4 shadow-sm">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-[150px]">
                        <label class="block text-xs font-semibold text-gray-700 mb-1">From <span class="text-red-600">*</span></label>
                        <input type="date" id="vendor_report_date_from" value="{{ date('Y-m-01') }}"
                               class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-purple-500 focus:border-purple-500 text-gray-900">
                    </div>
                    <div class="flex-1 min-w-[150px]">
                        <label class="block text-xs font-semibold text-gray-700 mb-1">To <span class="text-red-600">*</span></label>
                        <input type="date" id="vendor_report_date_to" value="{{ date('Y-m-d') }}"
                               class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-purple-500 focus:border-purple-500 text-gray-900">
                    </div>
                    <div class="flex items-end">
                        <label class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded cursor-pointer hover:bg-gray-50 transition-colors">
                            <input type="checkbox" id="vendor_show_payments" checked class="w-4 h-4 text-purple-600 border-gray-300 rounded focus:ring-purple-500 focus:ring-2">
                            <span class="ml-2 text-sm font-medium text-gray-700">Show Payments</span>
                        </label>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="generateVendorReport()" 
                                class="px-4 py-1.5 bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold rounded shadow-sm transition-colors">
                            🔍 Generate
                        </button>
                        <button onclick="printVendorReport()" id="vendorPrintBtn" style="display: none;"
                                class="px-4 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded shadow-sm transition-colors">
                            🖨️ Print
                        </button>
                        <button onclick="exportVendorToExcel()" id="vendorExcelBtn" style="display: none;"
                                class="px-4 py-1.5 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded shadow-sm transition-colors">
                            📊 Excel
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Report Content -->
            <div id="vendorReportContent" style="display: none;">
                <!-- Report will be loaded here -->
            </div>
        </div>
    </div>
</div>

<script>
// Vendor Report Functions
function openVendorReportModal() {
    const modal = document.getElementById('vendorReportModal');
    if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }
    modal.classList.remove('hidden');
    Object.assign(modal.style, {
        display: 'flex',
        position: 'fixed',
        top: '0',
        left: '0',
        right: '0',
        bottom: '0',
        zIndex: '99999'
    });
    document.body.style.overflow = 'hidden';
}

function closeVendorReportModal() {
    const modal = document.getElementById('vendorReportModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
}

function generateVendorReport() {
    const dateFrom = document.getElementById('vendor_report_date_from').value;
    const dateTo = document.getElementById('vendor_report_date_to').value;
    const showPayments = document.getElementById('vendor_show_payments').checked;
    
    if (!dateFrom || !dateTo) {
        alert('Please select both From and To dates');
        return;
    }
    
    // Show loading
    const reportContent = document.getElementById('vendorReportContent');
    reportContent.style.display = 'block';
    reportContent.innerHTML = '<div class="text-center py-8"><div class="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-purple-600"></div><p class="mt-4 text-gray-600">Generating report...</p></div>';
    
    // Fetch report data (pre-filtered for this vendor)
    fetch(`/finance/vendors/report?vendor_id={{ $vendor->id }}&date_from=${dateFrom}&date_to=${dateTo}&show_payments=${showPayments ? 1 : 0}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayVendorReport(data.report);
                document.getElementById('vendorPrintBtn').style.display = 'inline-block';
                document.getElementById('vendorExcelBtn').style.display = 'inline-block';
            } else {
                reportContent.innerHTML = '<div class="text-center py-8 text-red-600">Error generating report</div>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            reportContent.innerHTML = '<div class="text-center py-8 text-red-600">Error generating report</div>';
        });
}

// Aug-2026: the ~330-line renderer that used to live here now lives in
// fin/partials/vendor-report-render.blade.php, so the Ledger Hub's vendor report
// prints byte-identical markup instead of its own thinner version. The output here
// is unchanged: same table, same colours, same totals — the Tailwind classes were
// translated to their literal values so the markup no longer depends on this page's
// CSS (the hub writes it into a blank popup window that has none).
// rootId keeps this page's @media print rules (#printableVendorReport) working.
function displayVendorReport(report) {
    const reportContent = document.getElementById('vendorReportContent');
    const showPayments = document.getElementById('vendor_show_payments').checked;
    const html = window.nfVendorReportHtml(report, {
        vendorName: vendorName,
        showPayments: showPayments,
        rootId: 'printableVendorReport'
    });
    if (!html) {
        reportContent.innerHTML = '<div class="text-center py-8 text-red-600">No data found for this period</div>';
        return;
    }
    reportContent.innerHTML = html;
}

function printVendorReport() {
    // Show instructions for better print quality
    const userConfirmed = confirm(
        'Print Tips:\n\n' +
        '1. In the print dialog, click "More settings"\n' +
        '2. Uncheck "Headers and footers" to hide the URL\n' +
        '3. Set margins to "None" or "Minimum" for best results\n\n' +
        'Click OK to continue to print dialog.'
    );
    
    if (userConfirmed) {
        window.print();
    }
}

function exportVendorToExcel() {
    const dateFrom = document.getElementById('vendor_report_date_from').value;
    const dateTo = document.getElementById('vendor_report_date_to').value;
    const showPayments = document.getElementById('vendor_show_payments').checked;
    
    window.location.href = `/finance/vendors/report/export?vendor_id={{ $vendor->id }}&date_from=${dateFrom}&date_to=${dateTo}&show_payments=${showPayments ? 1 : 0}`;
}
</script>

{{-- ═══════════════════════════════════════════════════════════════════════════
     🧾 SCAN A BILL (Sep-2026)

     Photograph in, a card to check, then the ordinary weighted-purchase save.

     ⭐⭐ Nothing here writes money. The card is a SUGGESTION; it becomes a purchase
     only when the person presses Record, and only through
     /finance/vendors/{id}/weighted-purchase — the same door a hand-typed purchase
     uses. A bad read wastes a minute; it cannot book a wrong purchase.

     ⚠ Inline, NOT @push — this layout stacks 'demo1_js' and a plain @push is dead
       here, the same reason the block at the top of this file is inline.
     ⚠ Wrapped in an IIFE: a top-level `let` in a Blade view is script-scoped, and a
       second view declaring the same name kills this script silently.
     ═══════════════════════════════════════════════════════════════════════════ --}}
<div id="rcModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:9999; overflow-y:auto; padding:24px 12px;">
  <div style="max-width:760px; margin:0 auto; background:#fff; border-radius:14px; padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px;">
      <div>
        <h3 style="margin:0; font-size:18px; font-weight:700; color:#111827;">🧾 Scan a bill</h3>
        <p style="margin:2px 0 0; font-size:13px; color:#6B7280;">{{ $vendor->vendor_name }}</p>
      </div>
      <button type="button" onclick="rcClose()" style="border:none; background:none; font-size:22px; color:#9CA3AF; cursor:pointer;">&times;</button>
    </div>

    <div id="rcPick" style="padding:24px 0;">
      <p style="font-size:13.5px; color:#374151; line-height:1.6; text-align:center; margin-bottom:16px;">
        Choose a photo of the bill and every line will be filled in for you.<br>
        Nothing is recorded until you check it and press Record.
      </p>
      <input type="file" id="rcFile" accept="image/*" capture="environment"
             style="display:block; margin:0 auto;">
    </div>

    <div id="rcReading" style="display:none; padding:36px 0; text-align:center;">
      <p style="font-size:14px; font-weight:600; color:#374151;">Reading the bill…</p>
      <p style="font-size:12px; color:#9CA3AF;">This takes a few seconds.</p>
    </div>

    <div id="rcError" style="display:none; padding:18px 0;">
      <p id="rcErrorText" style="font-size:13px; color:#B91C1C; text-align:center; line-height:1.6;"></p>
    </div>

    <div id="rcCard" style="display:none;">
      <div id="rcWarnings"></div>
      <div id="rcMeta" style="font-size:12px; color:#6B7280; margin-bottom:10px;"></div>
      <div id="rcLines"></div>

      <div style="background:#F9FAFB; border-radius:10px; padding:12px; margin-top:10px;">
        <div style="display:flex; justify-content:space-between; font-size:13px; color:#374151;">
          <span>Lines add up to</span><b id="rcLinesTotal">Rs 0</b>
        </div>
        <div id="rcPrintedRow" style="display:flex; justify-content:space-between; font-size:13px; color:#374151; margin-top:3px;">
          <span>The bill says</span><b id="rcPrinted">—</b>
        </div>
        <div style="margin-top:10px;">
          <label style="font-size:12px; color:#6B7280;">Adjustment (discount or rounding)</label>
          <input type="number" step="0.01" id="rcAdjust" value="0"
                 style="width:100%; padding:7px 10px; border:1px solid #D1D5DB; border-radius:8px; font-size:13px; margin-top:4px;">
        </div>
        <div style="display:flex; justify-content:space-between; font-size:15px; font-weight:700; color:#111827; margin-top:10px;">
          <span>Will be recorded as</span><span id="rcGrand">Rs 0</span>
        </div>
      </div>

      <p style="font-size:11.5px; color:#9CA3AF; margin-top:10px; text-align:center;">
        Check every line against the paper. Nothing is saved until you press Record.
      </p>
    </div>

    <div style="display:flex; gap:10px; margin-top:16px;">
      <button type="button" onclick="rcClose()"
              style="flex:1; padding:11px; border:1px solid #D1D5DB; border-radius:10px; background:#fff; color:#374151; font-weight:600; cursor:pointer;">Cancel</button>
      <button type="button" id="rcSubmit" onclick="rcRecord()" disabled
              style="flex:1; padding:11px; border:none; border-radius:10px; background:#B45309; color:#fff; font-weight:700; cursor:pointer; opacity:.5;">Record purchase</button>
    </div>
  </div>
</div>

<script>
(function () {
    var VENDOR = {{ (int) $vendor->id }};
    var CSRF   = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    // ⚠ Fetched, not embedded. The show() controller does not eager-load the vendor's
    //   purchase catalogue, and reaching for `$vendor->products` in the Blade silently
    //   rendered an empty array — measured, not assumed. The phone reads the same
    //   endpoint, so both pickers offer the same list.
    var PRODUCTS = [];

    // ❄ Whether this vendor deals in Frozen ingredients (the server says, off the same
    //   product list), the ingredient list itself, and the "add a new product" form state:
    //   which line opened it and the tag chosen for it. Kept OUTSIDE the DOM because
    //   render() rebuilds every line from `card`.
    var SUPPORTS_ING = false, INGREDIENTS = [];
    var newFor = null, newIng = null;

    var card = null, draftId = null, photoFile = null;

    // ⚠ Minted when the sheet OPENS, kept across a failed save, so a retry after a
    //   timeout resolves to the same purchase instead of booking a second one.
    var clientUuid = null;

    function uuid() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }

    function money(n) { return 'Rs ' + Math.round(Number(n) || 0).toLocaleString(); }

    window.rcOpen = function () {
        card = null; draftId = null; photoFile = null;
        clientUuid = uuid();
        document.getElementById('rcFile').value = '';
        document.getElementById('rcPick').style.display = 'block';
        document.getElementById('rcReading').style.display = 'none';
        document.getElementById('rcError').style.display = 'none';
        document.getElementById('rcCard').style.display = 'none';
        document.getElementById('rcSubmit').disabled = true;
        document.getElementById('rcSubmit').style.opacity = '.5';
        document.getElementById('rcModal').style.display = 'block';
        loadProducts();
    };

    function loadProducts() {
        if (PRODUCTS.length) { return; }
        fetch('{{ route('fin.vendors.products.list', $vendor->id) }}', {
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
        })
        .then(function (r) { return r.json().catch(function () { return {}; }); })
        .then(function (d) {
            if (!(d && d.success)) { return; }
            PRODUCTS = d.products || [];
            SUPPORTS_ING = d.supports_ingredients === true;
            // ❄ Only a Frozen vendor gets the ingredient list; fails soft to none.
            if (SUPPORTS_ING && !INGREDIENTS.length) {
                fetch('{{ route('khaas.ingredients') }}', {
                    headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
                })
                .then(function (r) { return r.json().catch(function () { return {}; }); })
                .then(function (j) { if (j && j.success) { INGREDIENTS = j.ingredients || []; } })
                .catch(function () {});
            }
            if (card) { render(); }   // the list arrived after the card: redraw the pickers
        })
        .catch(function () { /* the picker simply stays empty; the card still shows */ });
    }

    /** Which ingredient each of this vendor's products already stands for. */
    function addedIngredientNames() {
        var out = {};
        PRODUCTS.forEach(function (p) {
            if (p.ingredient_id && !out[p.ingredient_id]) { out[p.ingredient_id] = p.product_name; }
        });
        return out;
    }

    window.rcClose = function () {
        document.getElementById('rcModal').style.display = 'none';
    };

    document.getElementById('rcFile').onchange = function () {
        if (!this.files || !this.files[0]) { return; }
        photoFile = this.files[0];
        read();
    };

    function read() {
        document.getElementById('rcPick').style.display = 'none';
        document.getElementById('rcError').style.display = 'none';
        document.getElementById('rcCard').style.display = 'none';
        document.getElementById('rcReading').style.display = 'block';

        var form = new FormData();
        form.append('client_uuid', clientUuid);
        form.append('image', photoFile);

        fetch('{{ route('fin.vendors.receipt.extract', $vendor->id) }}', {
            method: 'POST',
            headers: {'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json'},
            body: form
        })
        .then(function (r) { return r.json().catch(function () { return {success:false, message:'The server replied with something unreadable.'}; }); })
        .then(function (d) {
            document.getElementById('rcReading').style.display = 'none';
            if (!d.success) {
                // ⚠⚠ The uuid belongs to a bill that WAS recorded (a save whose answer was lost):
                //    start a fresh card instead of refusing every new bill for this vendor.
                if (/already been recorded/i.test(d.message || '')) {
                    clientUuid = uuid();
                    showError('The last bill was already recorded — nothing was booked twice. Choose the photo again to read this one.');
                    return;
                }
                showError(d.message || 'Could not read that photo.');
                return;
            }
            card = rcStampRead(d.card); draftId = d.draft_id;
            // ⭐ Printed discounts start in the adjustment, so the purchase matches the paper.
            document.getElementById('rcAdjust').value = card.discount_adjustment ? String(card.discount_adjustment) : '0';
            render();
        })
        .catch(function () {
            document.getElementById('rcReading').style.display = 'none';
            showError('Could not reach the server. The photo is still on your computer — try again.');
        });
    }

    function showError(msg) {
        document.getElementById('rcErrorText').textContent = msg;
        document.getElementById('rcError').style.display = 'block';
        document.getElementById('rcPick').style.display = 'block';
    }

    /**
     * ⭐ "Not found" is a question, not a dead end. An unmatched line's closest products
     *   (ranked by the server) come first under "Did you mean", then the full list, then
     *   the way out for something genuinely new.
     */
    function productOptions(selected, line) {
        var sug = (line && !line.product_id && line.suggestions) ? line.suggestions : [];
        var sugIds = sug.map(function (x) { return String(x.id); });
        var opt = function (id, name) {
            return '<option value="' + id + '"' + (String(id) === String(selected) ? ' selected' : '') + '>' + esc(name) + '</option>';
        };
        var out = '<option value="">— pick a product —</option>';
        if (sug.length) {
            out += '<optgroup label="Did you mean…">';
            sug.forEach(function (x) { out += opt(x.id, x.name); });
            out += '</optgroup>';
            out += '<optgroup label="All products">';
        }
        PRODUCTS.forEach(function (p) {
            if (sugIds.indexOf(String(p.id)) >= 0) { return; }
            out += opt(p.id, p.product_name);
        });
        if (sug.length) { out += '</optgroup>'; }
        out += '<option value="__new__">➕ None of these — add it as a new product</option>';
        return out;
    }

    /** The inline "add a new product" form for line i, prefilled from the printed line. */
    function newProductForm(i) {
        var l = card.lines[i] || {};
        var packUnit = String(l.pack_size_unit || '').toLowerCase();
        var packValue = Number(l.pack_size_value) || 0;
        var isPack = l.sold_by === 'pack' && packValue > 0;
        var toBase = {l:1000, ltr:1000, litre:1000, liter:1000, kg:1000, g:1, ml:1, pcs:1};
        // ⭐ The server's own reading of the line (VendorUnits::fromReceipt) — never a guessed kg.
        var unit = l.suggested_unit || (isPack ? 'pack' : (l.sold_by === 'weight' ? 'kg' : 'pack'));
        var packQty = l.suggested_pack_qty_base ? String(l.suggested_pack_qty_base)
            : ((isPack && toBase[packUnit]) ? String(packValue * toBase[packUnit]) : '');
        var inp = 'padding:5px 7px; border:1px solid #D1D5DB; border-radius:7px; font-size:12.5px;';
        var html =
            '<div class="rc-new" data-i="' + i + '" style="background:#F9FAFB; border:1px solid #D1D5DB; border-radius:10px; padding:10px; margin-top:8px;">' +
              '<div style="font-size:12.5px; font-weight:700; color:#111827;">New product for {{ e($vendor->vendor_name) }}</div>' +
              '<div style="font-size:11px; color:#6B7280; margin-bottom:6px;">The bill printed “' + esc(l.raw_name) + '”.</div>' +
              '<input class="rc-new-name" data-i="' + i + '" value="' + esc(String(l.raw_name || '').trim()) + '" placeholder="Product name" list="rcIngNames" autocomplete="off" style="width:100%; ' + inp + ' margin-bottom:6px;">' +
              '<div style="display:flex; gap:6px; margin-bottom:6px;">' +
                '<select class="rc-new-unit" data-i="' + i + '" style="flex:1; ' + inp + '">' + RC_UNITS.map(function (u) {
                    return '<option value="' + u.code + '"' + (u.code === unit ? ' selected' : '') + '>' + esc(u.one) + '</option>';
                }).join('') + '</select>' +
                '<input class="rc-new-rate" data-i="' + i + '" type="number" step="0.01" value="' + (l.unit_price == null ? '' : l.unit_price) + '" placeholder="rate / unit" style="flex:1; ' + inp + '">' +
              '</div>';
        if (SUPPORTS_ING && INGREDIENTS.length) {
            var added = addedIngredientNames();
            var fresh = INGREDIENTS.filter(function (x) { return !added[x.id]; });
            var old   = INGREDIENTS.filter(function (x) { return  added[x.id]; });
            var o = function (x, suffix) {
                return '<option value="' + x.id + '" data-unit="' + esc(x.base_unit) + '"' + (newIng && String(newIng.id) === String(x.id) ? ' selected' : '') + '>' + esc(x.name) + (suffix || '') + '</option>';
            };
            html += '<label style="font-size:11.5px; color:#374151; font-weight:600;">Frozen ingredient (optional)</label>' +
                    '<select class="rc-new-ing" data-i="' + i + '" style="width:100%; ' + inp + ' margin:3px 0 6px;">' +
                      '<option value="">— not an ingredient —</option>';
            fresh.forEach(function (x) { html += o(x); });
            if (old.length) {
                html += '<optgroup label="Already on this vendor\'s list">';
                old.forEach(function (x) { html += o(x, ' — already “' + esc(added[x.id]) + '”'); });
                html += '</optgroup>';
            }
            html += '</select>';
            // ⚠ A "pack" could be any size — the server refuses a tag it cannot size.
            var needs = rcNeedsSize(unit, newIng);
            html += '<div class="rc-new-packwrap" data-i="' + i + '" style="' + (needs ? '' : 'display:none;') + '">' +
                      '<label style="font-size:11.5px; color:#374151; font-weight:600;">How many <span class="rc-new-baseword">' + (newIng ? baseWord(newIng) : 'grams') + '</span> in one <span class="rc-new-unitword">' + esc(unit) + '</span>?</label>' +
                      '<input class="rc-new-pack" data-i="' + i + '" type="number" step="0.001" value="' + esc(packQty) + '" placeholder="e.g. 1000" style="width:100%; ' + inp + ' margin:3px 0 4px;">' +
                      '<div style="font-size:11px; color:#6B7280; margin-bottom:6px;">Filled in from the bill when it printed a size — check it against the paper.</div>' +
                    '</div>';
        }
        html += '<div style="display:flex; justify-content:flex-end; gap:8px;">' +
                  '<button type="button" class="rc-new-cancel" data-i="' + i + '" style="border:none; background:none; color:#6B7280; font-size:12.5px; cursor:pointer;">Cancel</button>' +
                  '<button type="button" class="rc-new-save" data-i="' + i + '" style="border:none; background:#4338CA; color:#fff; font-size:12.5px; font-weight:700; padding:6px 12px; border-radius:7px; cursor:pointer;">Add and use it</button>' +
                '</div>' +
              '</div>';
        return html;
    }

    // ⭐ Sep-26: the ONE unit catalogue (App\Services\FIN\VendorUnits), same as the phone.
    var RC_UNITS = @json(\App\Services\FIN\VendorUnits::catalogue());
    var RC_SUGGESTED = {g: 'kg', ml: 'litre', pcs: 'piece'};
    var RC_KIND = {g: 'weight', ml: 'volume', pcs: 'pieces'};
    function rcUnit(code) { return RC_UNITS.filter(function (u) { return u.code === String(code || '').toLowerCase(); })[0] || null; }
    /** A pack / box against an ingredient asks its size; kg against grams does not. */
    function rcNeedsSize(unit, ing) { var r = rcUnit(unit); return !!ing && !!r && r.kind === null; }
    /** kg for an ingredient counted in PIECES is the wrong kind. */
    function rcWrongKind(unit, ing) { var r = rcUnit(unit); return !!ing && !!r && r.kind !== null && r.kind !== ing.base_unit; }
    function baseWord(ing) { return ing.base_unit === 'pcs' ? 'pieces' : (ing.base_unit === 'ml' ? 'millilitres' : 'grams'); }
    function ingById(id) { return INGREDIENTS.filter(function (x) { return String(x.id) === String(id); })[0] || null; }

    // ── 🤖 the reader's suggestion + ⚖ the quantity check (Sep-27) ─────────────────
    // Same rules as the phone (NizamiFarmsMobile/src/utils/receiptQty.js). Nothing here is
    // ever applied on its own: the person taps "Yes, use it" / "Record …".
    var RC_ALIAS = {pcs: 'piece', pc: 'piece', pieces: 'piece', ltr: 'litre', l: 'litre', liter: 'litre',
                    kgs: 'kg', gm: 'g', gms: 'g', gram: 'g', grams: 'g'};
    var RC_PRINTED = {kg: {kind: 'g', factor: 1000}, g: {kind: 'g', factor: 1}, L: {kind: 'ml', factor: 1000},
                      ml: {kind: 'ml', factor: 1}, pcs: {kind: 'pcs', factor: 1}};
    function rcUnitAny(code) { var c = String(code || '').toLowerCase().trim(); return rcUnit(RC_ALIAS[c] || c); }
    function rcNum(v) { var n = Number(String(v == null ? '' : v).replace(/,/g, '')); return isFinite(n) ? n : null; }
    /** A value that was actually read/typed — rcNum() alone turns null and '' into 0. */
    function rcHas(v) { return v !== null && v !== undefined && String(v).trim() !== '' && rcNum(v) !== null; }
    function rcRound(n, dp) { var f = Math.pow(10, dp); return Math.round(n * f) / f; }
    function rcBaseText(n, kind) {
        if (kind === 'g') { return n >= 1000 ? rcRound(n / 1000, 3) + ' kg' : rcRound(n, 3) + ' g'; }
        if (kind === 'ml') { return n >= 1000 ? rcRound(n / 1000, 3) + ' L' : rcRound(n, 3) + ' ml'; }
        return rcRound(n, 3) + ' pcs';
    }
    function rcProduct(l) {
        return PRODUCTS.filter(function (x) { return String(x.id) === String(l.product_id); })[0]
            || {id: l.product_id, product_name: l.product_name, unit: l.unit};
    }
    /**
     * null | {kind:'convert', qty, rate, text} | {kind:'warn', text}
     * ⚠⚠ Pre-deploy (Sep-29), same as the phone:
     *   A2  the money kept is qty × PRINTED price — what this card records without a conversion
     *       (a printed discount sits in the adjustment). It used to keep the line TOTAL, which
     *       had the discount off already, so the discount came off twice. The total is used
     *       only when no price was read.
     *   C14 from the quantity AS READ (`read_qty`), and only while the line still shows it: a
     *       quantity the person typed is theirs, never the start of a new offer.
     */
    function rcPackFit(l, p) {
        if (!l || !p || l.qty_converted) { return null; }
        var pr = rcUnitAny(p.unit);
        if (!pr) { return null; }
        var qty = rcNum(l.qty), name = p.product_name || 'this product';
        var printed = RC_PRINTED[l.pack_size_unit] || null, size = rcNum(l.pack_size_value);
        if (printed && size > 0) {
            var packBase = size * printed.factor;
            if (pr.kind && pr.kind === printed.kind && pr.factor) {
                var readQty = rcHas(l.read_qty) ? rcNum(l.read_qty) : qty;
                if (!(readQty > 0) || qty !== readQty) { return null; }
                var nq = rcRound(readQty * packBase / pr.factor, 3);
                if (Math.abs(nq - readQty) < 0.0005) { return null; }
                var money = rcHas(l.unit_price) ? readQty * rcNum(l.unit_price) : (rcNum(l.line_total) || 0);
                return {kind: 'convert', qty: nq, rate: nq > 0 ? rcRound(money / nq, 2) : 0,
                        text: 'The bill has ' + rcRound(readQty, 3) + ' × ' + rcRound(size, 3) + ' ' + l.pack_size_unit + ' = ' + nq + ' ' + (nq === 1 ? pr.one : pr.many) + ' of ' + name + '.'};
            }
            if (pr.kind === null && rcNum(p.pack_qty_base) > 0 && p.ingredient_base_unit === printed.kind) {
                var own = rcNum(p.pack_qty_base);
                if (Math.abs(own - packBase) / own > 0.02) {
                    return {kind: 'warn', text: name + ' is a ' + rcBaseText(own, printed.kind) + ' ' + p.unit + ', but this bill\'s is ' + rcBaseText(packBase, printed.kind) + '. Check it is the same item — or record the weight on a product bought by weight.'};
                }
                return null;
            }
            if (pr.kind && pr.kind !== printed.kind && printed.kind !== 'pcs' && pr.kind !== 'pcs') {
                return {kind: 'warn', text: name + ' is bought by ' + RC_KIND[pr.kind] + ', but the bill shows ' + rcRound(size, 3) + ' ' + l.pack_size_unit + ' packs (' + RC_KIND[printed.kind] + '). Check the quantity before recording.'};
            }
            if (pr.kind && pr.kind !== printed.kind && printed.kind === 'pcs') {
                return {kind: 'warn', text: name + ' is bought by ' + RC_KIND[pr.kind] + ', but the bill counts ' + rcRound(size, 3) + ' pieces a pack. Type the ' + RC_KIND[pr.kind] + ' before recording.'};
            }
            return null;
        }
        if (l.sold_by === 'weight' && pr.kind && pr.kind !== 'g') {
            return {kind: 'warn', text: 'The bill weighs this item, but ' + name + ' is bought by ' + RC_KIND[pr.kind] + '. Check the quantity before recording.'};
        }
        if (l.sold_by === 'pack' && (pr.kind === 'g' || pr.kind === 'ml')) {
            return {kind: 'warn', text: 'The bill counts packs, but ' + name + ' is bought per ' + pr.one + '. Type the ' + RC_KIND[pr.kind] + ' before recording.'};
        }
        return null;
    }
    function rcHints(l, i) {
        var box = 'margin-top:6px; border-radius:8px; padding:7px 9px; font-size:12px; ';
        var btn = 'border:none; border-radius:6px; padding:4px 10px; font-size:12px; font-weight:700; cursor:pointer; color:#fff; ';
        var lnk = 'border:none; background:none; color:#4B5563; font-size:12px; text-decoration:underline; cursor:pointer;';
        if (!l.product_id) {
            if (l.ai_dismissed) { return ''; }
            if (l.ai_suggestion) {
                return '<div class="rc-ai" data-i="' + i + '" style="' + box + 'background:#EEF2FF; border:1px solid #C7D2FE; color:#312E81;">' +
                    '🤖 Looks like <b>' + esc(l.ai_suggestion.name) + '</b> ' +
                    '<button type="button" class="rc-ai-yes" data-i="' + i + '" style="' + btn + 'background:#4F46E5; margin-left:6px;">Yes, use it</button> ' +
                    '<button type="button" class="rc-ai-no" data-i="' + i + '" style="' + lnk + '">Not this</button></div>';
            }
            if (l.ai_ingredient && SUPPORTS_ING) {
                return '<div class="rc-ai" data-i="' + i + '" style="' + box + 'background:#EEF2FF; border:1px solid #C7D2FE; color:#312E81;">' +
                    '🤖 Not on this vendor\'s list yet — it looks like the ingredient <b>' + esc(l.ai_ingredient.name) + '</b>. ' +
                    '<button type="button" class="rc-ai-new" data-i="' + i + '" style="' + btn + 'background:#4F46E5; margin-left:6px;">Add it as a product</button> ' +
                    '<button type="button" class="rc-ai-no" data-i="' + i + '" style="' + lnk + '">No</button></div>';
            }
            return '';
        }
        var fit = rcPackFit(l, rcProduct(l));
        if (!fit) {
            return l.qty_converted
                ? '<div style="font-size:11px; color:#047857; margin-top:4px;">⚖ Quantity converted from the bill (' + esc(String(l.printed_qty)) + ' as printed).</div>'
                : '';
        }
        if (fit.kind === 'convert') {
            var pr = rcUnitAny(rcProduct(l).unit);
            return '<div class="rc-fit" data-i="' + i + '" style="' + box + 'background:#ECFDF5; border:1px solid #A7F3D0; color:#065F46;">⚖ ' + esc(fit.text) +
                ' <button type="button" class="rc-fit-use" data-i="' + i + '" data-qty="' + fit.qty + '" data-rate="' + fit.rate + '" style="' + btn + 'background:#047857; margin-left:6px;">Record ' + fit.qty + ' ' + esc(fit.qty === 1 ? pr.one : pr.many) + '</button></div>';
        }
        return '<div class="rc-fit-warn" style="font-size:11.5px; color:#B45309; margin-top:6px;">⚠ ' + esc(fit.text) + '</div>';
    }
    /**
     * Point line i at product p — putting the printed numbers back if an earlier product
     * converted them, but only while the line still shows exactly what that conversion set
     * (C14): a quantity or rate typed afterwards is the person's to keep.
     */
    function rcChoose(i, p) {
        var l = card.lines[i];
        if (l.qty_converted) {
            var untouched = l.converted_qty === undefined
                || (rcNum(l.qty) === rcNum(l.converted_qty) && rcNum(l.unit_price) === rcNum(l.converted_rate));
            if (untouched) { l.qty = l.printed_qty; l.unit_price = l.printed_rate; }
            l.qty_converted = false;
        }
        l.product_id = p ? Number(p.id) : null;
        l.product_name = p ? p.product_name : null;
        l.unit = p ? p.unit : null;
        l.ingredient_name = p ? (p.ingredient_name || null) : null;
        l.qty_base_text = p ? rcQtyBaseText(l, p) : null;
    }
    /** ⚖ "Record 2.4 kg": remember the printed numbers AND what the conversion set. */
    function rcConvert(l, fit, p) {
        l.printed_qty = l.qty; l.printed_rate = l.unit_price;
        l.qty = fit.qty; l.unit_price = fit.rate;
        l.converted_qty = fit.qty; l.converted_rate = fit.rate;
        l.qty_converted = true;
        l.qty_base_text = rcQtyBaseText(l, p);
    }
    /** C14: every line's quantity AS READ, stamped once when a card arrives. */
    function rcStampRead(c) {
        ((c && c.lines) || []).forEach(function (l) { if (l && l.read_qty === undefined) { l.read_qty = l.qty; } });
        return c;
    }
    /**
     * The "· 2.4 kg" beside the ingredient: qty × the product's pack size, worded like the
     * server's IngredientModel::display(). null when the product carries no ingredient size.
     */
    function rcQtyBaseText(l, p) {
        var pack = rcNum(p && p.pack_qty_base), base = p && p.ingredient_base_unit;
        if (!l || !rcHas(l.qty) || !(pack > 0) || !base) { return null; }
        var q = rcRound(rcNum(l.qty) * pack, 3), u = base;
        if (base === 'g' && Math.abs(q) >= 1000) { q = q / 1000; u = 'kg'; }
        else if (base === 'ml' && Math.abs(q) >= 1000) { q = q / 1000; u = 'L'; }
        var parts = rcRound(q, 3).toFixed(3).replace(/\.?0+$/, '').split('.');
        return parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (parts[1] ? '.' + parts[1] : '') + ' ' + u;
    }

    function render() {
        var w = document.getElementById('rcWarnings');
        w.innerHTML = (card.warnings || []).map(function (x) {
            return '<div style="font-size:12px; color:#92400E; background:#FFFBEB; border:1px solid #FDE68A; ' +
                   'border-radius:8px; padding:8px 10px; margin-bottom:6px;">⚠ ' + esc(x) + '</div>';
        }).join('');

        document.getElementById('rcMeta').innerHTML =
            [card.store_name, card.receipt_no ? 'Bill ' + card.receipt_no : '', card.receipt_date]
                .filter(Boolean).map(esc).join(' · ');

        document.getElementById('rcLines').innerHTML = (card.lines || []).map(function (l, i) {
            var attention = !l.product_id;
            return '<div style="border:1px solid ' + (attention ? '#FDE68A' : '#E5E7EB') + '; ' +
                   'background:' + (attention ? '#FFFBEB' : '#fff') + '; border-radius:10px; padding:10px; margin-bottom:7px;">' +
                '<div style="display:flex; gap:10px; align-items:flex-start;">' +
                    '<div style="flex:1; min-width:0;">' +
                        '<div style="font-size:12.5px; color:#111827;">' + esc(l.raw_name) + '</div>' +
                        '<select class="rc-prod" data-i="' + i + '" style="margin-top:4px; width:100%; max-width:320px; padding:4px 6px; border:1px solid #D1D5DB; border-radius:6px; font-size:12px;">' +
                            productOptions(l.product_id, l) +
                        '</select>' +
                        '<div class="rc-hintbox" data-i="' + i + '">' + rcHints(l, i) + '</div>' +
                        (newFor === i ? newProductForm(i) : '') +
                        (l.ingredient_name
                            ? '<div class="rc-ingline" data-i="' + i + '" style="font-size:11px; color:#4F46E5; margin-top:3px;">' + rcIngText(l) + '</div>'
                            : '') +
                    '</div>' +
                    '<button type="button" class="rc-del" data-i="' + i + '" style="border:none; background:none; color:#9CA3AF; font-size:16px; cursor:pointer;">&times;</button>' +
                '</div>' +
                '<div style="display:flex; align-items:center; gap:6px; margin-top:6px;">' +
                    '<input type="number" step="0.001" class="rc-qty" data-i="' + i + '" value="' + (l.qty == null ? '' : l.qty) + '" placeholder="qty" style="width:90px; padding:5px 7px; border:1px solid #D1D5DB; border-radius:7px; font-size:12.5px; text-align:right;">' +
                    '<span style="color:#9CA3AF;">×</span>' +
                    '<input type="number" step="0.01" class="rc-rate" data-i="' + i + '" value="' + (l.unit_price == null ? '' : l.unit_price) + '" placeholder="rate" style="width:100px; padding:5px 7px; border:1px solid #D1D5DB; border-radius:7px; font-size:12.5px; text-align:right;">' +
                    '<span class="rc-total" data-i="' + i + '" style="flex:1; text-align:right; font-weight:700; font-size:12.5px; color:#111827;"></span>' +
                '</div>' +
                // ❄ Frozen vocabulary: shown only where the vendor deals in ingredients.
                (SUPPORTS_ING
                    ? '<label style="display:flex; align-items:center; gap:6px; margin-top:5px; font-size:11px; color:#6B7280; cursor:pointer;">' +
                          '<input type="checkbox" class="rc-noting" data-i="' + i + '"' + (l.not_ingredient ? ' checked' : '') + '> Not an ingredient (money only)' +
                      '</label>'
                    : '') +
            '</div>';
        }).join('') +
        // One spelling: the ingredient names suggest themselves in the new-product name box.
        (INGREDIENTS.length
            ? '<datalist id="rcIngNames">' + INGREDIENTS.map(function (x) { return '<option value="' + esc(x.name) + '"></option>'; }).join('') + '</datalist>'
            : '');

        bind();
        totals();

        document.getElementById('rcPrintedRow').style.display = card.grand_total ? 'flex' : 'none';
        document.getElementById('rcPrinted').textContent = card.grand_total ? money(card.grand_total) : '—';
        document.getElementById('rcCard').style.display = 'block';
        document.getElementById('rcSubmit').disabled = false;
        document.getElementById('rcSubmit').style.opacity = '1';
    }

    function bind() {
        var box = document.getElementById('rcLines');
        box.querySelectorAll('.rc-prod').forEach(function (el) {
            el.onchange = function () {
                var i = +el.getAttribute('data-i');
                if (el.value === '__new__') {
                    // Open the inline form under this line; the select goes back to blank.
                    // 🤖 the reader's ingredient guess first, then an exact spelling match
                    var ai = card.lines[i].ai_ingredient;
                    newFor = i; newIng = (ai && ingById(ai.id)) || ingById(exactIngredientId(card.lines[i].raw_name));
                    render();
                    return;
                }
                var p = PRODUCTS.filter(function (x) { return String(x.id) === el.value; })[0];
                rcChoose(i, el.value ? (p || {id: Number(el.value)}) : null);
                if (newFor === i) { newFor = null; newIng = null; }
                render();
            };
        });
        // 🤖 confirm / reject the reader's suggestion, or start the product it names
        box.querySelectorAll('.rc-ai-yes').forEach(function (el) {
            el.onclick = function () {
                var i = +el.getAttribute('data-i'), s = card.lines[i].ai_suggestion;
                if (!s) { return; }
                var p = PRODUCTS.filter(function (x) { return String(x.id) === String(s.id); })[0];
                rcChoose(i, p || {id: s.id, product_name: s.name, unit: s.unit});
                render();
            };
        });
        box.querySelectorAll('.rc-ai-no').forEach(function (el) {
            el.onclick = function () { card.lines[+el.getAttribute('data-i')].ai_dismissed = true; render(); };
        });
        box.querySelectorAll('.rc-ai-new').forEach(function (el) {
            el.onclick = function () {
                var i = +el.getAttribute('data-i'), ai = card.lines[i].ai_ingredient;
                newFor = i; newIng = (ai && ingById(ai.id)) || ingById(exactIngredientId(card.lines[i].raw_name));
                render();
            };
        });
        // ⚖ record the converted quantity (the rupee total stays the same). ⚠ Worked out again
        //   from the line as it is NOW, not from the button's numbers — the qty/rate boxes
        //   change the line without a re-render (C14).
        box.querySelectorAll('.rc-fit-use').forEach(function (el) {
            el.onclick = function () {
                var l = card.lines[+el.getAttribute('data-i')];
                var fit = rcPackFit(l, rcProduct(l));
                if (fit && fit.kind === 'convert') { rcConvert(l, fit, rcProduct(l)); }
                render();
            };
        });
        // The inline new-product form. Its text lives in the DOM until Save — render() is
        // only called on tag change and unit change, and those re-read the fields first.
        box.querySelectorAll('.rc-new-cancel').forEach(function (el) {
            el.onclick = function () { newFor = null; newIng = null; render(); };
        });
        box.querySelectorAll('.rc-new-save').forEach(function (el) {
            el.onclick = function () { rcAddProduct(+el.getAttribute('data-i')); };
        });
        box.querySelectorAll('.rc-new-name').forEach(function (el) {
            // ⭐ Typing an ingredient's exact spelling selects that ingredient — one name.
            el.oninput = function () {
                var hit = ingById(exactIngredientId(el.value));
                var sel = box.querySelector('.rc-new-ing[data-i="' + el.getAttribute('data-i') + '"]');
                if (hit && sel) { sel.value = String(hit.id); newIng = hit; syncPackWrap(+el.getAttribute('data-i')); }
            };
        });
        box.querySelectorAll('.rc-new-ing').forEach(function (el) {
            el.onchange = function () {
                var i = +el.getAttribute('data-i');
                newIng = ingById(el.value);
                var name = box.querySelector('.rc-new-name[data-i="' + i + '"]');
                if (newIng && name && !name.value.trim()) { name.value = newIng.name; }
                syncPackWrap(i);
            };
        });
        box.querySelectorAll('.rc-new-unit').forEach(function (el) {
            // (a <select> now — change as well as input, for older browsers)
            el.oninput = el.onchange = function () { syncPackWrap(+el.getAttribute('data-i')); };
        });
        box.querySelectorAll('.rc-qty').forEach(function (el) {
            el.oninput = function () { var i = +el.getAttribute('data-i'); card.lines[i].qty = el.value; rcRefreshLine(i); totals(); };
        });
        box.querySelectorAll('.rc-rate').forEach(function (el) {
            el.oninput = function () { var i = +el.getAttribute('data-i'); card.lines[i].unit_price = el.value; rcRefreshLine(i); totals(); };
        });
        box.querySelectorAll('.rc-noting').forEach(function (el) {
            el.onchange = function () { card.lines[+el.getAttribute('data-i')].not_ingredient = el.checked; };
        });
        box.querySelectorAll('.rc-del').forEach(function (el) {
            el.onclick = function () {
                card.lines.splice(+el.getAttribute('data-i'), 1);
                render();
            };
        });
        document.getElementById('rcAdjust').oninput = totals;
    }

    function totals() {
        var sum = 0;
        (card.lines || []).forEach(function (l, i) {
            var t = (parseFloat(l.qty) || 0) * (parseFloat(l.unit_price) || 0);
            sum += t;
            var cell = document.querySelector('.rc-total[data-i="' + i + '"]');
            if (cell) { cell.textContent = money(t); }
        });
        var adj = parseFloat(document.getElementById('rcAdjust').value) || 0;
        document.getElementById('rcLinesTotal').textContent = money(sum);
        document.getElementById('rcGrand').textContent = money(sum + adj);
    }

    /** "Onions · 2.4 kg" under a line's product. */
    function rcIngText(l) {
        return esc(l.ingredient_name) + (l.qty_base_text ? ' · ' + esc(l.qty_base_text) : '');
    }

    /**
     * C14: after a qty / rate keystroke the "· 2.4 kg" line and the ⚖ offer follow the line
     * WITHOUT a full re-render (which would take the cursor out of the box being typed in).
     * A typed quantity hides the offer; typing the read quantity back brings it back.
     */
    function rcRefreshLine(i) {
        var l = card.lines[i], box = document.getElementById('rcLines');
        if (!l || !box) { return; }
        if (l.product_id) { l.qty_base_text = rcQtyBaseText(l, rcProduct(l)); }
        var h = box.querySelector('.rc-hintbox[data-i="' + i + '"]');
        if (h) { h.innerHTML = rcHints(l, i); }
        var g = box.querySelector('.rc-ingline[data-i="' + i + '"]');
        if (g) { g.innerHTML = rcIngText(l); }
        bind();
    }

    /** The ingredient whose name is EXACTLY what was typed (case/space-insensitive), or null. */
    function exactIngredientId(typed) {
        var key = String(typed || '').toLowerCase().replace(/\s+/g, ' ').trim();
        if (!key) { return null; }
        var hit = INGREDIENTS.filter(function (x) { return String(x.name).toLowerCase().replace(/\s+/g, ' ').trim() === key; })[0];
        return hit ? hit.id : null;
    }

    /** Show the "how many in one pack?" box only when the unit cannot size itself. */
    function syncPackWrap(i) {
        var box  = document.getElementById('rcLines');
        var wrap = box.querySelector('.rc-new-packwrap[data-i="' + i + '"]');
        var unitEl = box.querySelector('.rc-new-unit[data-i="' + i + '"]');
        if (!wrap || !unitEl) { return; }
        var unit = (unitEl.value || '').trim().toLowerCase();
        var needs = rcNeedsSize(unit, newIng);
        wrap.style.display = needs ? '' : 'none';
        if (needs) {
            wrap.querySelector('.rc-new-baseword').textContent = baseWord(newIng);
            wrap.querySelector('.rc-new-unitword').textContent = unit || 'unit';
        }
    }

    /**
     * ⭐ Add a genuinely new product WITHOUT leaving the bill half-entered. Posts to the
     *   same catalogue endpoint the Manage Products page uses — one door, not a second one.
     */
    window.rcAddProduct = function (i) {
        var box  = document.getElementById('rcLines');
        var name = (box.querySelector('.rc-new-name[data-i="' + i + '"]').value || '').trim();
        var unit = (box.querySelector('.rc-new-unit[data-i="' + i + '"]').value || 'kg').trim();
        var rate = parseFloat(box.querySelector('.rc-new-rate[data-i="' + i + '"]').value);
        var packEl = box.querySelector('.rc-new-pack[data-i="' + i + '"]');
        var pack = packEl ? parseFloat(packEl.value) : 0;
        if (!name) { alert('Give the product a name before saving it.'); return; }
        if (!(rate > 0)) { alert('Give the product a rate per unit before saving it.'); return; }
        if (rcWrongKind(unit, newIng)) {
            // ⭐⭐ One unit per ingredient. The quick fix is right here; changing the
            //    ingredient's own unit everywhere lives on Manage Products.
            var s = RC_SUGGESTED[newIng.base_unit];
            if (confirm(newIng.name + ' is counted in ' + RC_KIND[newIng.base_unit].toUpperCase() + ', but this product is set to ' + unit + '.\n\nUse ' + (rcUnit(s) ? rcUnit(s).many : s) + ' instead?\n\n(To change ' + newIng.name + ' itself to ' + RC_KIND[rcUnit(unit).kind] + ' everywhere, use Manage Products.)')) {
                unit = s;
                box.querySelector('.rc-new-unit[data-i="' + i + '"]').value = s;
            } else {
                return;
            }
        }
        var needs = rcNeedsSize(unit, newIng);
        if (needs && !(pack > 0)) {
            alert('A ' + unit + ' could be any size. Say how many ' + baseWord(newIng) + ' one holds, or untag it.');
            return;
        }

        var body = {product_name: name, unit: unit, rate_per_unit: rate, is_default: 0};
        if (newIng) { body.ingredient_id = newIng.id; if (needs) { body.pack_qty_base = pack; } }

        var btn = box.querySelector('.rc-new-save[data-i="' + i + '"]');
        btn.disabled = true; btn.textContent = 'Adding…';

        fetch('{{ route('fin.vendors.products.store', $vendor->id) }}', {
            method: 'POST',
            headers: {'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json', 'Content-Type': 'application/json'},
            body: JSON.stringify(body)
        })
        .then(function (r) { return r.json().catch(function () { return {success:false, message:'Unexpected reply'}; }); })
        .then(function (d) {
            if (!d.success || !d.product || !d.product.id) {
                btn.disabled = false; btn.textContent = 'Add and use it';
                alert(d.message || 'Could not add that product.');
                return;
            }
            // The store reply is the bare model: carry the ingredient NAME across ourselves.
            var created = d.product;
            created.ingredient_name = (created.ingredient_id && newIng) ? newIng.name : null;
            PRODUCTS.push(created);
            card.lines[i].product_id = created.id;
            card.lines[i].product_name = created.product_name;
            card.lines[i].unit = created.unit;
            card.lines[i].ingredient_name = created.ingredient_name;
            newFor = null; newIng = null;
            render();
        })
        .catch(function () {
            btn.disabled = false; btn.textContent = 'Add and use it';
            alert('Could not reach the server. Try again in a moment.');
        });
    };

    window.rcRecord = function () {
        var usable = (card.lines || []).filter(function (l) { return l.product_id; });
        if (!usable.length) {
            alert('Every line needs a product from this vendor\'s list. Pick one for each, or remove the lines you do not want.');
            return;
        }

        var unpicked = (card.lines || []).length - usable.length;
        if (unpicked > 0 && !confirm(
            unpicked + ' line' + (unpicked === 1 ? '' : 's') + ' have no product picked and will NOT be recorded.\n\nCarry on?')) {
            return;
        }

        // ⭐ Pieces / packs / boxes are whole on a Frozen vendor (the server says which).
        var frac = usable.filter(function (l) {
            var p = PRODUCTS.filter(function (x) { return String(x.id) === String(l.product_id); })[0];
            return p && p.whole_only && Math.floor(parseFloat(l.qty)) !== parseFloat(l.qty);
        })[0];
        if (frac) { alert('"' + frac.raw_name + '" is counted in whole ' + ((rcUnit(frac.unit) || {}).many || 'units') + ' — the quantity cannot be ' + frac.qty + '.'); return; }

        var btn = document.getElementById('rcSubmit');
        btn.disabled = true;
        btn.textContent = 'Recording…';

        var form = new FormData();
        // ⚠ The LOCAL date, not toISOString() (UTC — a bill recorded before 5 am landed on yesterday).
        var now = new Date();
        var localYmd = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
        form.append('transaction_date', card.receipt_date || localYmd);
        form.append('description', ('Receipt ' + (card.receipt_no || '') + (card.store_name ? ' · ' + card.store_name : '')).trim());
        form.append('adjustment_amount', String(parseFloat(document.getElementById('rcAdjust').value) || 0));
        form.append('draft_id', String(draftId || ''));
        form.append('client_uuid', clientUuid);
        if (photoFile) { form.append('bill_images[]', photoFile); }

        usable.forEach(function (l, i) {
            var p = PRODUCTS.filter(function (x) { return String(x.id) === String(l.product_id); })[0];
            form.append('items[' + i + '][product_id]', l.product_id);
            form.append('items[' + i + '][quantity]', parseFloat(l.qty) || 0);
            form.append('items[' + i + '][rate]', parseFloat(l.unit_price) || 0);
            form.append('items[' + i + '][unit]', (p && p.unit) || l.unit || 'kg');
            form.append('items[' + i + '][product_name]', (p && p.product_name) || l.raw_name);
            // ⭐ What the slip PRINTED, so the server learns this shop's wording for the
            //   product he just confirmed and stops asking next time.
            if (l.raw_name) { form.append('items[' + i + '][raw_name]', String(l.raw_name)); }
            if (l.not_ingredient) { form.append('items[' + i + '][not_ingredient]', '1'); }
        });

        // ⭐⭐ Posts at the RECEIPT route, not straight at weighted-purchase. That route
        //    claims this draft under a row lock before it touches money, which is what
        //    makes the retry message below true rather than hopeful.
        fetch('{{ route('fin.vendors.receipt.record', $vendor->id) }}', {
            method: 'POST',
            headers: {'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json'},
            body: form
        })
        .then(function (r) { return r.json().catch(function () { return {success:false, message:'Unexpected reply'}; }); })
        .then(function (d) {
            btn.disabled = false;
            btn.textContent = 'Record purchase';
            if (d.success) {
                if (d.already) {
                    alert(d.message);
                } else if (d.learned && d.learned.length) {
                    // ⭐ Say what it learned, by name — a wrong lesson must be visible.
                    alert('Recorded, and remembered. Next time this bill says:\n\n' +
                        d.learned.map(function (x) { return '“' + x.printed + '”  →  ' + x.product; }).join('\n') +
                        '\n\nit will fill in on its own.');
                }
                window.location.reload();
            } else {
                alert(d.message || 'Could not record that purchase.');
            }
        })
        .catch(function () {
            btn.disabled = false;
            btn.textContent = 'Record purchase';
            // ⚠ Deliberately does NOT claim nothing was recorded — it cannot know.
            alert('Could not reach the server. Press Record again in a moment; it will not book this twice.');
        });
    };
})();
</script>

@endsection

