@extends('layouts.app')

@push('custom_css')
{{-- NF (Jul-2026): several Tailwind colour utilities are purged from the built
     styles.css (the @vite build is off), so elements that rely on them for a
     coloured BACKGROUND render with none — and any text-white / light text on
     top becomes invisible. Most visible here: the purple rider header bars,
     where the rider name and Total Outstanding amount showed white-on-white.
     Backfill the exact purged classes used on this page that carry light text.
     Page-scoped (only renders with this view). --}}
<style>
    .bg-gradient-to-r.from-purple-600.to-indigo-600 {
        background-image: linear-gradient(to right, #9333ea, #4f46e5);
    }
    .text-purple-100 { color: #ede9fe; }
    /* count/amount badges that use text-white on a purged solid background */
    .bg-green-600  { background-color: #16a34a; }
    .bg-red-500    { background-color: #ef4444; }
    .bg-yellow-600 { background-color: #ca8a04; }
    .bg-orange-900 { background-color: #7c2d12; }

    @verbatim
    /* ── Daily Closing split view (Aug-2026) ───────────────────────────────
       The day's work now sits in two panes — Requests (petrol + maintenance)
       on the left, Payment Follow-ups on the right — each with its own
       scrollbar, so neither queue can push the rider closings section off the
       bottom of the screen. Styled HERE rather than with utility classes for
       the same reason as the colour backfill above: the Vite build is off and
       the grid/overflow utilities this needs are purged from styles.css. */
    .dc-split { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; align-items: start; margin-bottom: 16px; }
    /* One column on anything narrower than a desktop — the office tablet and
       small laptops get the old stacked reading order, not two cramped panes. */
    @media (max-width: 1100px) { .dc-split { grid-template-columns: 1fr; } }
    .dc-pane { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; display: flex; flex-direction: column; min-width: 0; }
    .dc-pane-hd { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; padding: 7px 12px; background: #f8fafc; border-bottom: 1px solid #e5e7eb; }
    .dc-pane-ttl { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; font-size: 12.5px; font-weight: 700; color: #334155; }
    .dc-pane-badge { font-size: 11px; font-weight: 700; border-radius: 999px; padding: 2px 9px; background: #eef2f7; color: #475569; white-space: nowrap; }
    .dc-max-btn { background: #e2e8f0; color: #334155; border: 0; font-size: 11px; font-weight: 700; border-radius: 6px; padding: 4px 10px; cursor: pointer; white-space: nowrap; }
    .dc-max-btn:hover { background: #cbd5e1; }
    .dc-pane-body { overflow-y: auto; overflow-x: hidden; max-height: 56vh; }
    .dc-panel { margin: 8px; }
    .dc-pane-empty { padding: 14px 16px; color: #94a3b8; font-size: 12.5px; font-style: italic; }
    /* Sep-2026 — the ONLY visible trace of a background refresh. A quiet "updated
       17:42" that fades, not a call to action: the operator should notice that
       the pane is live, without being pulled away from what they are doing. */
    .dc-fresh { font-size: 10.5px; color: #64748b; margin-left: auto; margin-right: 8px;
                opacity: 0; transition: opacity .5s ease; white-space: nowrap; }
    .dc-fresh.dc-fresh-on { opacity: 1; }
    @media (prefers-reduced-motion: reduce) { .dc-fresh { transition: none; } }

    /* Maximize puts a class on the pane ITSELF — the node is never re-parented,
       so every button, <select> and inline handler inside keeps working exactly
       as it does unmaximized. z-index deliberately sits BELOW the fuel month
       view (4000) and the payment-proof viewer (99999) so both still open on
       top of a maximized pane. */
    .dc-pane.dc-maxed { position: fixed; top: 12px; left: 12px; right: 12px; bottom: 12px; z-index: 3000; box-shadow: 0 24px 70px rgba(0,0,0,.35); }
    .dc-pane.dc-maxed .dc-pane-body { max-height: none; flex: 1 1 auto; }
    .dc-scrim { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15,23,42,.45); z-index: 2990; }
    .dc-scrim.dc-on { display: block; }
    body.dc-locked { overflow: hidden; }

    /* Glance strip — the two rows of stat cards as one scannable line. */
    .dc-glance { display: flex; flex-wrap: wrap; gap: 7px; margin-bottom: 14px; }
    .dc-chip { display: inline-flex; align-items: center; gap: 7px; background: #fff; border: 1px solid #e5e7eb; border-radius: 999px; padding: 6px 13px; font-size: 12px; font-weight: 600; color: #374151; cursor: pointer; font-variant-numeric: tabular-nums; }
    .dc-chip:hover { border-color: #8b5cf6; color: #6d28d9; }
    .dc-chip .dc-n { font-size: 13px; font-weight: 800; }
    .dc-chip .dc-rs { color: #6b7280; font-weight: 600; }
    .dc-chip.dc-active { border-color: #7c3aed; background: #f5f3ff; color: #5b21b6; }

    /* 💬 opens the customer's real WhatsApp thread in the shared drawer. */
    .dc-chat-btn { background: #fff; border: 1.5px solid #25D366; color: #128C4A; font-weight: 800; font-size: 12px; border-radius: 7px; width: 28px; height: 24px; cursor: pointer; line-height: 1; padding: 0; }
    .dc-chat-btn:hover { background: #ECFDF5; }
    @endverbatim
</style>
@endpush

@section('title', 'Daily Closing')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    
    <!-- Compact Header & Filters in One Row -->
    <div class="bg-gradient-to-r from-purple-600 to-indigo-600 rounded-lg shadow-lg p-4 mb-4">
        <form id="filter-form" method="GET" action="{{ route('fin.employee.all-outstanding-invoices') }}" class="flex flex-wrap items-center gap-3">
            <div class="flex-shrink-0">
                <h1 class="text-lg font-bold text-white">📊 Invoice Tracker</h1>
            </div>
            <select name="rider" style="color: #1f2937 !important; background-color: white !important;" class="px-3 py-1.5 text-xs rounded-md focus:outline-none border-0">
                <option value="all">All Riders</option>
                @foreach($allRiders as $rider)
                <option value="{{ $rider->id }}" {{ $filters['rider'] == $rider->id ? 'selected' : '' }}>{{ $rider->account_name }}</option>
                @endforeach
            </select>
            <input type="date" name="date_from" value="{{ $filters['date_from'] }}" 
                   style="color: #1f2937 !important; background-color: white !important;" 
                   class="px-3 py-1.5 text-xs rounded-md focus:outline-none border-0">
            <input type="date" name="date_to" value="{{ $filters['date_to'] }}"
                   style="color: #1f2937 !important; background-color: white !important;" 
                   class="px-3 py-1.5 text-xs rounded-md focus:outline-none border-0">
            <!-- ⭐ Group By (only for settled view) -->
            @if($filters['status'] == 'settled')
            <select name="group_by" style="color: #1f2937 !important; background-color: white !important;" class="px-3 py-1.5 text-xs rounded-md focus:outline-none border-0">
                <option value="date" {{ ($filters['group_by'] ?? 'date') == 'date' ? 'selected' : '' }}>Group by Date</option>
                <option value="rider" {{ ($filters['group_by'] ?? 'date') == 'rider' ? 'selected' : '' }}>Group by Rider</option>
            </select>
            <!-- ⭐ Include Online toggle (default ON) -->
            @php $includeOnlineChecked = $filters['include_online'] ?? true; @endphp
            <label class="flex items-center gap-2 px-3 py-1.5 rounded-md cursor-pointer transition-all" 
                   style="{{ $includeOnlineChecked ? 'background: #10b981 !important; color: white;' : 'background: white !important; color: #1f2937;' }}">
                <input type="checkbox" name="include_online" value="1" {{ $includeOnlineChecked ? 'checked' : '' }} 
                       style="accent-color: {{ $includeOnlineChecked ? 'white' : '#10b981' }}; width: 16px; height: 16px;">
                <span class="text-xs font-semibold">🏦 Include Online</span>
            </label>
            @endif
            <button type="submit" style="background-color: white !important; color: #7c3aed !important;" class="px-3 py-1.5 font-medium text-xs rounded-md hover:opacity-90 transition-opacity">
                Apply
            </button>
            <input type="hidden" name="status" id="status-filter" value="{{ $filters['status'] }}">
            <div class="ml-auto">
                <a href="{{ route('fin.employee.index') }}" style="background-color: rgba(255, 255, 255, 0.2) !important; color: white !important;" class="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-md hover:opacity-90 transition-opacity">
                    ← Back
                </a>
            </div>
        </form>
    </div>

    {{-- Flash messages for approve/reject actions --}}
    @if(session('success'))
    <div class="mb-3 rounded-md border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">
        {{ session('success') }}
    </div>
    @elseif(session('error'))
    <div class="mb-3 rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">
        {{ session('error') }}
    </div>
    @endif

    @php
        // Request-queue totals. Computed here because BOTH the glance chip above
        // and the left pane's header below report them — one source, so they can
        // never disagree.
        $dcPetrolCount = $pendingPetrolRequests['total_count'] ?? 0;
        $dcMaintCount  = $pendingMaintenanceRequests['total_count'] ?? 0;
        $dcReqAmount   = ($pendingPetrolRequests['total_amount'] ?? 0) + ($pendingMaintenanceRequests['total_amount'] ?? 0);
    @endphp

    {{-- Glance strip (Aug-2026) — the same figures the two rows of stat cards
         carried, compressed into one scannable line so the rider closings
         section starts near the top of the screen instead of below two full
         panels. Every chip performs the SAME action as the card it replaces:
         filterByStatus() for the invoice states, a scroll for the queues.
         The two cards that were BOTH labelled "⏳ PENDING" — settlement
         deposits and expense requests — now say which is which. --}}
    <div class="dc-glance">
        <button type="button" onclick="filterByStatus('open')" title="Invoices with nothing settled yet"
                class="dc-chip {{ $filters['status'] == 'open' ? 'dc-active' : '' }}">
            🔴 Open <span class="dc-n" style="color:#b91c1c;">{{ $stats['open_count'] }}</span>
            <span class="dc-rs">Rs. {{ number_format($stats['open_total'], 0) }}</span>
        </button>

        <button type="button" onclick="filterByStatus('partial')" title="Invoices partly settled"
                class="dc-chip {{ $filters['status'] == 'partial' ? 'dc-active' : '' }}">
            🟡 Partial <span class="dc-n" style="color:#b45309;">{{ $stats['partial_count'] }}</span>
            <span class="dc-rs">Rs. {{ number_format($stats['partial_total'], 0) }}</span>
        </button>

        <button type="button" onclick="filterByStatus('all')" title="All outstanding invoices"
                class="dc-chip {{ $filters['status'] == 'all' ? 'dc-active' : '' }}">
            📊 Total <span class="dc-n" style="color:#6d28d9;">{{ $stats['open_count'] + $stats['partial_count'] }}</span>
            <span class="dc-rs">Rs. {{ number_format($stats['total_outstanding'], 0) }}</span>
        </button>

        {{-- Was "⏳ PENDING" card #1: rider settlement deposits waiting for
             approval. They render inline inside each rider card below, which is
             where this now scrolls to. --}}
        <button type="button" onclick="dcJump('dc-closings')" title="Rider settlement deposits waiting for your approval — shown inside the rider cards below"
                class="dc-chip">
            💰 Deposits to approve <span class="dc-n" style="color:#1d4ed8;">{{ $stats['pending_settlement_count'] }}</span>
            <span class="dc-rs">Rs. {{ number_format($stats['pending_settlement_total'], 0) }}</span>
        </button>

        {{-- Was "⏳ PENDING" card #2: expense requests waiting for approval —
             the queue that lives in the left pane.

             ⚠ Aug-2026: this used to read $stats['pending_approvals_*'], which
             counts ONLY requests paid from NF Cash or a rider's own balance. A
             petrol claim filed against Online Bank or the Expense Fund is not in
             that number, so the chip could say "0" while the pane right below it
             listed three requests worth Rs 14,045. It now counts exactly what
             the pane shows. (The old page had the same blind spot — its card
             just said "⏳ PENDING", so nobody could tell the two disagreed.) --}}
        <button type="button" onclick="dcJump('dc-pane-requests')" title="Petrol &amp; maintenance requests waiting for your approval"
                class="dc-chip">
            🧾 Expenses to approve <span class="dc-n" style="color:#b45309;">{{ $dcPetrolCount + $dcMaintCount }}</span>
            <span class="dc-rs">Rs. {{ number_format($dcReqAmount, 0) }}</span>
        </button>

        <button type="button" onclick="dcJump('dc-closings')" title="Cash the riders are still holding"
                class="dc-chip">
            💸 Short cash <span class="dc-n" style="color:#15803d;">{{ $stats['short_cash_count'] }}</span>
            <span class="dc-rs">Rs. {{ number_format($stats['short_cash_amount'], 0) }}</span>
        </button>

        @if(!empty($onlineFollowUp) && ($onlineFollowUp['chase_count'] ?? 0) > 0)
        <button type="button" onclick="dcJump('dc-pane-messages')" title="Online deliveries with no payment proof yet"
                class="dc-chip">
            💬 To chase <span class="dc-n" style="color:#be123c;">{{ $onlineFollowUp['chase_count'] }}</span>
            @if(($onlineFollowUp['new_customer_count'] ?? 0) > 0)
            <span class="dc-rs" style="color:#b45309;">{{ $onlineFollowUp['new_customer_count'] }} new</span>
            @endif
        </button>
        @endif
    </div>

    <!-- ⭐ View Settled Invoices Button - AT THE TOP -->
    @if($filters['status'] != 'settled' && $stats['settled_count'] > 0)
    <div class="mb-4">
        <div style="background: linear-gradient(to right, #ecfdf5, #d1fae5); border: 2px solid #86efac;" class="rounded-lg p-4 flex items-center justify-between">
            <div>
                <h3 style="color: #166534 !important;" class="text-sm font-bold mb-1">✅ Settled Invoices Available</h3>
                <p style="color: #15803d !important;" class="text-xs">{{ $stats['settled_count'] }} invoice(s) totaling Rs. {{ number_format($stats['settled_total'], 2) }} have been settled.</p>
            </div>
            <button onclick="filterByStatus('settled')" style="background: linear-gradient(to right, #16a34a, #15803d) !important; color: white !important;" class="inline-flex items-center px-5 py-2.5 text-sm font-semibold rounded-lg shadow-md hover:opacity-90 transition-opacity">
                View Settled Invoices →
            </button>
        </div>
    </div>
    @endif

    <!-- No separate pending settlements section - they'll be shown inline with invoices -->

    {{-- Staleness bar (Aug-2026). This page is a load-time snapshot — no polling
         of any kind — so a delivery or a payment proof that lands after it opens
         is invisible until a manual reload. Rather than auto-reloading a
         62-query page (and destroying scroll position, expanded groups and any
         half-finished approval), the page carries the counters it was born with,
         polls 3 cheap COUNTs, and offers a refresh when they move. Hidden until
         something actually changes. --}}
    {{-- Sep-2026 — the "Refresh page" bar that used to live here is GONE. It asked
         the operator to do something the page can do for itself, and it cost a
         full ~1,130ms / 62-query reload that threw away scroll position, the open
         groups and any half-finished approval.

         Now the page polls the same cheap counters and, when they move, quietly
         re-renders ONLY the two panes above — never the rider closings below,
         where the money is actually approved. This element is just the baseline
         it compares against; it renders nothing.

         ⚠ The bar never worked anyway: its endpoint was swallowed by the `/{id}`
         route registered before it (see routes/web.php), so it 404'd on every
         poll from the day it shipped. Fixed in the same round. --}}
    @if(isset($onlineFollowUpHeartbeat) && $onlineFollowUpHeartbeat)
    <div id="dc-refresh-state" class="hidden"
         data-baseline="{{ json_encode($onlineFollowUpHeartbeat) }}"
         data-rider="{{ $filters['rider'] ?? 'all' }}"></div>
    @endif

    {{-- ══ The day's two queues, side by side (Aug-2026) ═══════════════════
         Requests (petrol + maintenance) on the LEFT, Payment Follow-ups on the
         RIGHT, each pane scrolling on its own and maximizable. This is a
         LAYOUT change only: the three panels below are the same markup they
         have always been — same ids, same onclick handlers, same approve /
         reject / send flows — they have simply moved inside a pane. Before
         this, a busy day pushed the rider closings (the section this page is
         named after) two or three screens down. --}}
    <div class="dc-split">

        {{-- ══════════ LEFT PANE · REQUESTS ══════════ --}}
        <section class="dc-pane" id="dc-pane-requests" aria-label="Pending expense requests">
            <div class="dc-pane-hd">
                <div class="dc-pane-ttl">
                    <span>🧾 Requests</span>
                    {{-- ⚠ Always RENDERED, hidden when zero, rather than omitted by
                         an @if. The background refresh updates these in place, and
                         a badge that does not exist in the DOM cannot be revealed
                         when its count rises from 0 — the header would keep saying
                         nothing while new requests piled up in the pane below. --}}
                    <span class="dc-pane-badge {{ $dcPetrolCount > 0 ? '' : 'hidden' }}" data-dc-badge="petrol" style="background:#ffedd5; color:#c2410c;">⛽ {{ $dcPetrolCount }}</span>
                    <span class="dc-pane-badge {{ $dcMaintCount > 0 ? '' : 'hidden' }}" data-dc-badge="maint" style="background:#ccfbf1; color:#0f766e;">🔧 {{ $dcMaintCount }}</span>
                    <span class="dc-pane-badge {{ $dcReqAmount > 0 ? '' : 'hidden' }}" data-dc-badge="reqamt">Rs. {{ number_format($dcReqAmount) }}</span>
                </div>
                <span class="dc-fresh" id="dc-fresh-requests" aria-live="polite"></span>
                <button type="button" class="dc-max-btn" onclick="dcToggleMax('dc-pane-requests', this)">⛶ Maximize</button>
            </div>
            {{-- id + data-pane: the background refresh swaps THIS element's
                 contents and nothing else on the page. --}}
            <div class="dc-pane-body" id="dc-body-requests" data-pane="requests">
                @include('fin.employee.partials.panel-requests')
            </div>
        </section>

        {{-- ══════════ RIGHT PANE · MESSAGES ══════════ --}}
        <section class="dc-pane" id="dc-pane-messages" aria-label="Payment follow-up messages">
            <div class="dc-pane-hd">
                <div class="dc-pane-ttl">
                    <span>💬 Messages</span>
                    @php
                        $dcChaseCount = !empty($onlineFollowUp) ? ($onlineFollowUp['chase_count'] ?? 0) : 0;
                        // Counts the REVIEW group (?? for a stale server): the L1-done
                        // rows have their own line, and this number is what the
                        // in-place decrement moves — the total would bounce back
                        // up on reload after an approval.
                        $dcProofCount = !empty($onlineFollowUp)
                            ? ($onlineFollowUp['proof_review_count'] ?? $onlineFollowUp['proof_in_count'] ?? 0)
                            : 0;
                    @endphp
                    {{-- Always rendered, hidden when zero — see the note on the
                         Requests badges. --}}
                    <span class="dc-pane-badge {{ $dcChaseCount > 0 ? '' : 'hidden' }}" data-dc-badge="chase" style="background:#ffe4e6; color:#be123c;">{{ $dcChaseCount }} to chase</span>
                    <span class="dc-pane-badge {{ $dcProofCount > 0 ? '' : 'hidden' }}" data-dc-badge="proof" data-fu-proof style="background:#fef3c7; color:#92400e;">{{ $dcProofCount }} proof in</span>
                </div>
                <span class="dc-fresh" id="dc-fresh-messages" aria-live="polite"></span>
                <button type="button" class="dc-max-btn" onclick="dcToggleMax('dc-pane-messages', this)">⛶ Maximize</button>
            </div>
            <div class="dc-pane-body" id="dc-body-messages" data-pane="messages">
                @include('fin.employee.partials.panel-messages')
            </div>
        </section>
    </div>

    {{-- Scroll target for the glance chips. --}}
    <div id="dc-closings"></div>
    <!-- Invoices Section -->
    @if($invoicesByRider->isEmpty())
    <!-- No Invoices State -->
    <div class="bg-white rounded-lg shadow-sm p-16 text-center">
        <div class="text-6xl mb-4">
            @if($filters['status'] == 'settled')
                📦
            @else
                ✅
            @endif
        </div>
        <h2 class="text-2xl font-bold text-gray-800 mb-2">
            @if($filters['status'] == 'settled')
                No Settled Invoices
            @else
                No Outstanding Invoices
            @endif
        </h2>
        <p class="text-gray-600 mb-4">
            @if($filters['status'] == 'settled')
                No invoices have been settled in the selected period.
            @else
                All invoices are settled! Great job keeping up with payments.
            @endif
        </p>
        <button onclick="filterByStatus('all')" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-md">
            View All Invoices
        </button>
    </div>
    @else
    
    @if($filters['status'] == 'settled' && ($filters['group_by'] ?? 'rider') == 'date' && isset($invoicesByDate) && $invoicesByDate->count() > 0)
    <!-- ⭐ Summary Header (Like Delivery History) -->
    @php
        $totalCashCount = 0;
        $totalCashAmount = 0;
        $totalOnlineCount = 0;
        $totalOnlineAmount = 0;
        $totalOnlineApprovedCount = 0;
        $totalOnlineApprovedAmount = 0;
        $totalOnlinePendingCount = 0;
        $totalOnlinePendingAmount = 0;
        foreach($invoicesByDate as $dayData) {
            if(isset($dayData['riders'])) {
                // Handle both Collection and array
                $riders = is_array($dayData['riders']) ? collect($dayData['riders']) : $dayData['riders'];
                $totalCashCount += $riders->sum('count');
                $totalCashAmount += $riders->sum('total_amount');
            }
            if(isset($dayData['online'])) {
                $totalOnlineCount += $dayData['online']['count'] ?? 0;
                $totalOnlineAmount += $dayData['online']['total_amount'] ?? 0;
                $totalOnlineApprovedCount += $dayData['online']['approved_count'] ?? 0;
                $totalOnlineApprovedAmount += $dayData['online']['approved_amount'] ?? 0;
                $totalOnlinePendingCount += $dayData['online']['pending_count'] ?? 0;
                $totalOnlinePendingAmount += $dayData['online']['pending_amount'] ?? 0;
            }
        }
        $grandTotal = $totalCashAmount + $totalOnlineAmount;
        $grandCount = $totalCashCount + $totalOnlineCount;
    @endphp
    <div class="bg-gradient-to-r from-green-600 to-emerald-600 rounded-lg shadow-lg p-4 mb-4">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div class="flex items-center gap-3">
                <span class="text-3xl">✅</span>
                <div>
                    <h2 class="text-white font-bold text-lg">Daily Settlement Summary</h2>
                    <p class="text-green-100 text-sm">{{ $grandCount }} invoices • Rs. {{ number_format($grandTotal, 0) }} total</p>
                </div>
            </div>
            <div class="flex items-center gap-3 flex-wrap">
                <!-- Cash Total Badge -->
                @if($totalCashCount > 0)
                <div class="bg-white bg-opacity-20 rounded-lg px-4 py-2 text-center">
                    <div class="text-white text-xs font-medium">💵 Cash Settled</div>
                    <div class="text-white font-bold">{{ $totalCashCount }} • Rs. {{ number_format($totalCashAmount, 0) }}</div>
                </div>
                @endif
                
                <!-- Online Approved Badge -->
                @if($totalOnlineApprovedCount > 0)
                <div class="bg-white bg-opacity-20 rounded-lg px-4 py-2 text-center">
                    <div class="text-white text-xs font-medium">🏦 Online ✓</div>
                    <div class="text-white font-bold">{{ $totalOnlineApprovedCount }} • Rs. {{ number_format($totalOnlineApprovedAmount, 0) }}</div>
                </div>
                @endif
                
                <!-- Online Pending Badge -->
                @if($totalOnlinePendingCount > 0)
                <div class="bg-yellow-400 bg-opacity-30 rounded-lg px-4 py-2 text-center">
                    <div class="text-white text-xs font-medium">⏳ Online Pending</div>
                    <div class="text-white font-bold">{{ $totalOnlinePendingCount }} • Rs. {{ number_format($totalOnlinePendingAmount, 0) }}</div>
                </div>
                @endif
            </div>
        </div>
    </div>
    
    <!-- ⭐ Invoices by Date (Date-Level Grouping) - Delivery History Style -->
    <div class="space-y-2">
        @php
            $dateIndex = 0;
        @endphp
        @foreach($invoicesByDate as $date => $dayData)
        @php
            // Handle both Collection and array for riders
            $ridersData = isset($dayData['riders']) ? (is_array($dayData['riders']) ? collect($dayData['riders']) : $dayData['riders']) : collect();
            $cashCount = $ridersData->sum('count');
            $cashAmount = $ridersData->sum('total_amount');
            $onlineCount = isset($dayData['online']) ? ($dayData['online']['count'] ?? 0) : 0;
            $onlineAmount = isset($dayData['online']) ? ($dayData['online']['total_amount'] ?? 0) : 0;
            $isFirstDate = $dateIndex === 0;
            $dateIndex++;
        @endphp
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <!-- Date Header Row (Like Delivery History) -->
            <div class="flex items-center justify-between px-4 py-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100" onclick="toggleDateGroup('{{ str_replace(['-', ' '], '_', $date) }}')">
                <div class="flex items-center gap-3">
                    <!-- Expand/Collapse Arrow -->
                    <span id="toggle-icon-{{ str_replace(['-', ' '], '_', $date) }}" class="text-gray-400 text-sm transition-transform {{ $isFirstDate ? '' : 'rotate-[-90deg]' }}">▼</span>
                    
                    <!-- Date Info -->
                    <div class="flex items-center gap-2">
                        <span class="text-lg">📅</span>
                        <div>
                            <h3 class="font-bold text-gray-800">{{ \Carbon\Carbon::parse($date)->format('D, M d, Y') }}</h3>
                            <p class="text-xs text-gray-500">
                                {{ $dayData['total_count'] }} invoice(s) • Rs. {{ number_format($dayData['total_amount'], 0) }} total
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Summary Badges (Like Delivery History) -->
                <div class="flex items-center gap-2">
                    <!-- Cash Badge (Green) -->
                    @if($cashCount > 0)
                    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold" style="background: #dcfce7; color: #166534;">
                        💵 {{ $cashCount }} • Rs. {{ number_format($cashAmount, 0) }}
                    </span>
                    @endif
                    
                    <!-- Online Badge (Blue) - Show TOTAL online (approved + pending combined) -->
                    @if($onlineCount > 0)
                    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold" style="background: #dbeafe; color: #1e40af;">
                        🏦 {{ $onlineCount }} • Rs. {{ number_format($onlineAmount, 0) }}
                    </span>
                    @endif
                </div>
            </div>
            
            <!-- Date Content (Expandable) -->
            <div id="date-content-{{ str_replace(['-', ' '], '_', $date) }}" class="{{ $isFirstDate ? '' : 'hidden' }}">
                <!-- Cash Settlements by Rider -->
                @if(isset($dayData['riders']) && count($dayData['riders']) > 0)
                <div class="px-4 py-3 border-b border-gray-100">
                    <h4 class="text-sm font-bold text-green-700 mb-3 flex items-center gap-2">
                        💵 Cash Settlements
                        <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full">
                            {{ $cashCount }} invoices • Rs. {{ number_format($cashAmount, 0) }}
                        </span>
                    </h4>
                    <div class="space-y-3">
                        @foreach($dayData['riders'] as $riderGroup)
                        <div class="bg-gray-50 rounded-lg p-3">
                            <div class="flex items-center justify-between mb-2 pb-2 border-b border-gray-200">
                                <span class="font-semibold text-purple-700 flex items-center gap-2">
                                    👤 {{ $riderGroup['rider_name'] }}
                                </span>
                                <span class="text-sm font-bold text-gray-700">
                                    {{ $riderGroup['count'] }} inv • Rs. {{ number_format($riderGroup['total_amount'], 0) }}
                                </span>
                            </div>
                            <div class="text-sm text-gray-600 space-y-1">
                                @foreach($riderGroup['invoices'] as $invoice)
                                <div class="flex justify-between items-center py-1.5 border-b border-gray-100 last:border-0">
                                    <div>
                                        <span class="font-medium text-blue-600">#{{ $invoice['order_number'] }}</span>
                                        @if($invoice['customer_name'])
                                            <span class="text-gray-600"> - {{ $invoice['customer_name'] }}</span>
                                        @endif
                                        @php $invProof = $invoice['payment_proof'] ?? null; @endphp
                                        @if($invProof && ($invProof['status'] ?? 'none') !== 'none')
                                            <span class="text-xs font-bold px-1.5 py-0.5 rounded-full text-white whitespace-nowrap ml-1"
                                                  style="background-color: {{ $invProof['color'] }};{{ !empty($invoice['order_id']) ? ' cursor: pointer;' : '' }}"
                                                  @if(!empty($invoice['order_id']))onclick="event.stopPropagation(); openProofModal({{ $invoice['order_id'] }}, '{{ addslashes($invoice['order_number']) }}')"@endif
                                                  title="{{ $invProof['label'] }} — click to view the screenshot / bank email">
                                                {{ $invProof['has_whatsapp'] ? '📷' : '' }}{{ !empty($invProof['has_sms']) ? '📱' : '' }}{{ $invProof['has_email'] ? '✉️' : '' }} {{ $invProof['label'] }}@if(!empty($invoice['order_id'])) 🔍@endif
                                            </span>
                                        @endif
                                    </div>
                                    @if((float) $invoice['amount'] < 0.01)
                                        {{-- Rs 0 free / replacement order, auto-settled at delivery --}}
                                        <span class="font-medium text-gray-500" title="Free / replacement order — nothing to collect">Free</span>
                                    @else
                                        <span class="font-semibold text-gray-800">Rs. {{ number_format($invoice['amount'], 0) }}</span>
                                    @endif
                                </div>
                                @endforeach
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif
                
                <!-- Online Payments -->
                @if(isset($dayData['online']) && $dayData['online']['count'] > 0)
                @php
                    $onlineApprovedCount = isset($dayData['online']['approved_count']) ? $dayData['online']['approved_count'] : 0;
                    $onlineApprovedAmount = isset($dayData['online']['approved_amount']) ? $dayData['online']['approved_amount'] : 0;
                    $onlinePendingCount = isset($dayData['online']['pending_count']) ? $dayData['online']['pending_count'] : 0;
                    $onlinePendingAmount = isset($dayData['online']['pending_amount']) ? $dayData['online']['pending_amount'] : 0;
                @endphp
                <div class="px-4 py-3">
                    <h4 class="text-sm font-bold text-blue-700 mb-3 flex items-center gap-2">
                        🏦 Online Payments
                        <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">
                            {{ $onlineCount }} orders • Rs. {{ number_format($onlineAmount, 0) }}
                        </span>
                    </h4>
                    
                    <!-- Approved Online Payments (Blue) -->
                    @if($onlineApprovedCount > 0)
                    <div class="bg-blue-50 rounded-lg p-3 mb-2">
                        <div class="flex items-center justify-between mb-2 pb-2 border-b border-blue-200">
                            <span class="font-semibold text-blue-700">✓ Approved</span>
                            <span class="text-sm font-bold text-gray-700">{{ $onlineApprovedCount }} • Rs. {{ number_format($onlineApprovedAmount, 0) }}</span>
                        </div>
                        <div class="text-sm text-gray-600 space-y-1">
                            @foreach($dayData['online']['approved_transactions'] ?? [] as $txn)
                            <div class="flex justify-between items-center py-1.5 border-b border-blue-100 last:border-0">
                                <div>
                                    <span class="font-medium text-blue-600">#{{ $txn['order_number'] }}</span>
                                    <span class="text-gray-600"> - {{ $txn['customer_name'] }}</span>
                                </div>
                                <span class="font-semibold text-gray-800">Rs. {{ number_format($txn['amount'], 0) }}</span>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endif
                    
                    <!-- Pending Online Payments (Yellow) -->
                    @if($onlinePendingCount > 0)
                    <div class="bg-yellow-50 rounded-lg p-3">
                        <div class="flex items-center justify-between mb-2 pb-2 border-b border-yellow-200">
                            <span class="font-semibold text-yellow-700">⏳ Pending Approval</span>
                            <span class="text-sm font-bold text-gray-700">{{ $onlinePendingCount }} • Rs. {{ number_format($onlinePendingAmount, 0) }}</span>
                        </div>
                        <div class="text-sm text-gray-600 space-y-1">
                            @foreach($dayData['online']['pending_transactions'] ?? [] as $txn)
                            <div class="flex justify-between items-center py-1.5 border-b border-yellow-100 last:border-0">
                                <div>
                                    <span class="font-medium text-yellow-600">#{{ $txn['order_number'] }}</span>
                                    <span class="text-gray-600"> - {{ $txn['customer_name'] }}</span>
                                </div>
                                <span class="font-semibold text-gray-800">Rs. {{ number_format($txn['amount'], 0) }}</span>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endif
                </div>
                @endif
                
                <!-- Empty state if no data -->
                @if((!isset($dayData['riders']) || count($dayData['riders']) == 0) && (!isset($dayData['online']) || ($dayData['online']['count'] ?? 0) == 0))
                <div class="px-4 py-6 text-center text-gray-500 text-sm">
                    No settlement details available for this date.
                </div>
                @endif
            </div>
        </div>
        @endforeach
    </div>
    
    <!-- Online Pending Approval Section (if include_online is enabled) -->
    @if(isset($onlineData) && $onlineData['pending_approval']['count'] > 0)
    <div class="mt-4">
        <div class="bg-yellow-50 border-2 border-yellow-300 rounded-lg p-4">
            <h3 class="text-sm font-bold text-yellow-800 mb-3 flex items-center gap-2">
                ⏳ Online Payments - Pending Approval
                <span class="bg-yellow-500 text-white px-2 py-0.5 rounded-full text-xs">
                    {{ $onlineData['pending_approval']['count'] }} pending
                </span>
            </h3>
            <div class="text-xs text-gray-600">
                <table class="w-full">
                    <thead class="bg-yellow-100">
                        <tr>
                            <th class="text-left px-2 py-1">Order #</th>
                            <th class="text-left px-2 py-1">Customer</th>
                            <th class="text-left px-2 py-1">Date</th>
                            <th class="text-right px-2 py-1">Amount</th>
                            <th class="text-center px-2 py-1">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($onlineData['pending_approval']['transactions'] as $txn)
                        <tr class="border-b border-yellow-100">
                            <td class="px-2 py-1 font-medium text-blue-600">#{{ $txn['order_number'] }}</td>
                            <td class="px-2 py-1">{{ $txn['customer_name'] }}</td>
                            <td class="px-2 py-1">{{ \Carbon\Carbon::parse($txn['transaction_date'])->format('M d') }}</td>
                            <td class="px-2 py-1 text-right font-semibold">Rs. {{ number_format($txn['amount'], 2) }}</td>
                            <td class="px-2 py-1 text-center">
                                <span class="bg-yellow-200 text-yellow-800 px-2 py-0.5 rounded text-xs">
                                    {{ ucfirst(str_replace('_', ' ', $txn['approval_status'])) }}
                                </span>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-yellow-100">
                        <tr>
                            <td colspan="3" class="px-2 py-1 font-bold text-right">Total Pending:</td>
                            <td class="px-2 py-1 text-right font-bold text-yellow-800">Rs. {{ number_format($onlineData['pending_approval']['amount'], 2) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    @endif
    
    @else
    <!-- Invoices by Rider (Default View) -->
    <div class="space-y-3">
        @foreach($invoicesByRider as $riderData)
        <div class="bg-white rounded-lg shadow-md border border-gray-200 overflow-hidden hover:shadow-lg transition-shadow">
            <!-- Rider Header -->
            <div class="bg-gradient-to-r from-purple-600 to-indigo-600 px-4 py-3 flex items-center justify-between cursor-pointer select-none"
                 onclick="dcToggleRider({{ $riderData['account']->id }})"
                 title="Click to collapse or expand this rider's invoices">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-white bg-opacity-20 rounded-full flex items-center justify-center border-2 border-white">
                        <span class="text-white font-bold text-lg">
                            {{ substr($riderData['account']->account_name, 0, 1) }}
                        </span>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-white">{{ $riderData['account']->account_name }}</h3>
                        <p class="text-xs text-purple-100">{{ $riderData['account']->account_code }} • {{ $riderData['invoice_count'] }} invoice(s)</p>
                        @if(!empty($riderData['cash_confirmation']))
                            @php
                                $cc = $riderData['cash_confirmation'];
                                $ccIssue = (($cc->cash_confirm_status ?? 'confirmed') === 'issue');
                            @endphp
                            <span style="display:inline-flex;align-items:center;gap:4px;margin-top:5px;padding:2px 9px;border-radius:9999px;font-size:11px;font-weight:700;background:{{ $ccIssue ? '#fde68a' : '#dcfce7' }};color:{{ $ccIssue ? '#92400e' : '#166534' }};"
                                  title="Rider {{ $ccIssue ? 'flagged the cash amount as wrong' : 'confirmed the cash he is holding' }} at check-out">
                                {{ $ccIssue ? '⚠️ Flagged issue' : '✓ Confirmed' }} · Rs. {{ number_format($cc->cash_confirmed_amount ?? 0, 0) }} · {{ \Carbon\Carbon::parse($cc->cash_confirmed_at)->format('M j, g:i A') }}
                            </span>
                        @endif
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <div class="text-right">
                        <p class="text-xs text-purple-100">Total Outstanding</p>
                        <p class="text-2xl font-bold text-white">Rs. {{ number_format($riderData['total_outstanding'], 2) }}</p>
                    </div>
                    {{-- Collapse chevron. Purely decorative: the whole header is
                         the click target, so this never needs its own handler. --}}
                    <span id="dc-rider-chev-{{ $riderData['account']->id }}"
                          class="text-white text-xl leading-none" style="width:14px;text-align:center;">&#9662;</span>
                </div>
            </div>

            {{-- Everything below the header collapses together (invoice table +
                 the quick-actions footer), so a collapsed card is just the
                 purple summary bar: rider, invoice count and total outstanding.
                 Expanded by default — collapsing is a per-session choice, and
                 the page never auto-reloads, so nothing resets under him. --}}
            <div id="dc-rider-body-{{ $riderData['account']->id }}">

            <!-- Invoices Table -->
            <div class="overflow-x-auto">
                @if($filters['status'] == 'settled' && $riderData['invoices_by_date'])
                    <!-- Day-Grouped View for Settled Invoices -->
                    @foreach($riderData['invoices_by_date'] as $date => $dayData)
                    <div class="mb-2 last:mb-0">
                        <!-- Day Header with Total -->
                        <div style="background: linear-gradient(to right, #dcfce7, #bbf7d0) !important;" class="px-4 py-2 border-b-2 border-green-300 flex justify-between items-center">
                            <div>
                                <span class="text-sm font-bold text-green-900">📅 {{ \Carbon\Carbon::parse($date)->format('l, M j, Y') }}</span>
                                <span class="text-xs text-green-700 ml-2">({{ $dayData['count'] }} invoice{{ $dayData['count'] > 1 ? 's' : '' }})</span>
                            </div>
                            <div class="text-sm font-bold text-green-900">
                                Day Total: Rs. {{ number_format($dayData['day_total'], 2) }}
                            </div>
                        </div>
                        
                        <!-- Invoices for this day -->
                        <table class="min-w-full divide-y divide-gray-100">
                            <thead style="background-color: #f9fafb !important;">
                                <tr>
                                    <th class="px-3 py-1.5 text-left text-xs font-semibold text-gray-600 uppercase">Order #</th>
                                    <th class="px-3 py-1.5 text-left text-xs font-semibold text-gray-600 uppercase">Invoice Date</th>
                                    <th class="px-3 py-1.5 text-left text-xs font-semibold text-gray-600 uppercase">Description</th>
                                    <th class="px-3 py-1.5 text-right text-xs font-semibold text-gray-600 uppercase">Amount</th>
                                    <th class="px-3 py-1.5 text-right text-xs font-semibold text-gray-600 uppercase">Settled</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-50">
                                @foreach($dayData['invoices'] as $invoice)
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        <span class="text-xs font-bold text-purple-700">{{ $invoice->order ? $invoice->order->order_number : 'N/A' }}</span>
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap text-xs text-gray-600">
                                        {{ $invoice->transaction_date->format('M j') }}
                                    </td>
                                    <td class="px-3 py-2 text-xs text-gray-600 max-w-xs truncate">
                                        {{ $invoice->description }}
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap text-xs text-right font-semibold text-gray-900">
                                        Rs. {{ number_format($invoice->amount, 2) }}
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap text-xs text-right">
                                        @if((float) $invoice->amount < 0.01)
                                            {{-- Rs 0 free / replacement order, auto-settled at delivery --}}
                                            <span class="text-gray-500 font-medium" title="Free / replacement order — nothing to collect">Free</span>
                                        @else
                                            <span class="text-green-700 font-medium">Rs. {{ number_format($invoice->settled_amount, 2) }}</span>
                                        @endif
                                        @if(isset($invoice->settlement_breakdown) && $invoice->settlement_breakdown)
                                            <div class="text-xs text-blue-600 mt-1" style="white-space: nowrap;">
                                                💸 Rs. {{ number_format($invoice->settlement_breakdown['deposit_amount'], 0) }} + 
                                                Rs. {{ number_format($invoice->settlement_breakdown['expense_amount'], 0) }} ({{ $invoice->settlement_breakdown['expense_category'] }})
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endforeach
                @else
                    <!-- Standard View for Open/Partial Invoices -->
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-700 uppercase">Order #</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-700 uppercase">Date</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-700 uppercase">Description</th>
                                <th class="px-3 py-2 text-center text-xs font-semibold text-gray-700 uppercase">Status</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold text-gray-700 uppercase">Amount</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold text-gray-700 uppercase">Settled</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold text-gray-700 uppercase">Outstanding</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            @foreach($riderData['invoices'] as $invoice)
                            <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-3 py-2">
                                <div class="text-xs font-bold text-purple-700">{{ $invoice['order_number'] }}</div>
                                @if(isset($invoice['customer_name']) && $invoice['customer_name'])
                                <div class="text-xs text-gray-600 mt-0.5">{{ $invoice['customer_name'] }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-gray-600">
                                {{ $invoice['transaction_date']->format('M j, Y') }}
                            </td>
                            <td class="px-3 py-2 text-xs text-gray-600 max-w-xs truncate">
                                {{ $invoice['description'] }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-center">
                                @if($invoice['is_pending_approval'])
                                    <span class="px-2 py-0.5 text-xs font-bold bg-amber-100 text-amber-800 rounded-full animate-pulse">
                                        💰 Deposit Pending
                                    </span>
                                @elseif($invoice['settlement_status'] === 'settled')
                                    <span class="px-2 py-0.5 text-xs font-bold bg-green-100 text-green-800 rounded-full">
                                        ✅ Settled
                                    </span>
                                @elseif($invoice['settled_amount'] > 0)
                                    <span class="px-2 py-0.5 text-xs font-bold bg-yellow-100 text-yellow-800 rounded-full">
                                        🟡 Partial
                                    </span>
                                @else
                                    <span class="px-2 py-0.5 text-xs font-bold bg-red-100 text-red-800 rounded-full">
                                        🔴 Open
                                    </span>
                                @endif
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-right font-semibold text-gray-900">
                                Rs. {{ number_format($invoice['amount'], 2) }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-right">
                                @if($invoice['settled_amount'] > 0)
                                    <span class="text-green-700 font-medium">Rs. {{ number_format($invoice['settled_amount'], 2) }}</span>
                                    @if($invoice['settled_at'])
                                        <div class="text-xs text-gray-500">{{ \Carbon\Carbon::parse($invoice['settled_at'])->format('M j') }}</div>
                                    @endif
                                    @if(isset($invoice['settlement_breakdown']) && $invoice['settlement_breakdown'])
                                        <div class="text-xs text-blue-600 mt-1" style="white-space: nowrap;">
                                            💸 Rs. {{ number_format($invoice['settlement_breakdown']['deposit_amount'], 0) }} + 
                                            Rs. {{ number_format($invoice['settlement_breakdown']['expense_amount'], 0) }} ({{ $invoice['settlement_breakdown']['expense_category'] }})
                                        </div>
                                    @endif
                                @else
                                    <span class="text-gray-400">-</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-right">
                                @if($invoice['outstanding_amount'] > 0)
                                    <span class="font-bold text-red-700">Rs. {{ number_format($invoice['outstanding_amount'], 2) }}</span>
                                @elseif((float) $invoice['amount'] < 0.01)
                                    {{-- Rs 0 free / replacement order: nothing was ever owed --}}
                                    <span class="text-gray-500 font-medium" title="Free / replacement order — nothing to collect">Free</span>
                                @else
                                    <span class="text-green-600 font-medium">✓ Paid</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                    
                    <!-- Pending Settlement Deposit Rows (Inline) -->
                    @if($riderData['pending_settlements']->count() > 0)
                        @foreach($riderData['pending_settlements'] as $settlement)
                        <tbody style="background: linear-gradient(to right, #fef3c7, #fde68a) !important;" class="border-t-2 border-amber-400">
                            <tr>
                                <td colspan="2" class="px-3 py-3">
                                    <div class="flex items-center gap-2">
                                        <span class="text-lg">💰</span>
                                        <div>
                                            <p class="text-xs font-bold text-amber-900">Settlement Deposit</p>
                                            <p class="text-xs text-amber-700">{{ $settlement->created_at->format('M j, Y g:i A') }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td colspan="2" class="px-3 py-3">
                                    <p class="text-xs text-amber-800">{{ $settlement->description }}</p>
                                    @if($settlement->comments)
                                    <p class="text-xs text-amber-600 mt-1">{{ $settlement->comments }}</p>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-center">
                                    <span class="px-2 py-1 text-xs font-bold bg-amber-200 text-amber-900 rounded-full">
                                        ⏳ PENDING APPROVAL
                                    </span>
                                </td>
                                <td colspan="2" class="px-3 py-3 text-right">
                                    <p class="text-lg font-bold text-amber-900">Rs. {{ number_format($settlement->amount, 2) }}</p>
                                    <p class="text-xs text-amber-700">
                                        {{ $settlement->invoices->count() }} invoice(s) • 
                                        @if($settlement->amount >= $settlement->total_outstanding)
                                            <span class="text-green-700">Full Payment</span>
                                        @else
                                            <span class="text-red-700">Short Rs. {{ number_format($settlement->total_outstanding - $settlement->amount, 2) }}</span>
                                        @endif
                                    </p>
                                </td>
                            </tr>
                            <tr style="background-color: #fffbeb !important;">
                                <td colspan="7" class="px-3 py-2">
                                    <div class="flex items-center justify-between">
                                        <a href="{{ route('fin.ledger.show', $settlement->id) }}" class="text-xs text-amber-700 hover:text-amber-900 font-medium">
                                            View in Approvals →
                                        </a>
                                        <div class="flex gap-2">
                                            <form method="POST" action="{{ route('fin.ledger.approve', $settlement->id) }}" class="inline" onsubmit="return confirm('Approve this settlement deposit of Rs. {{ number_format($settlement->amount, 2) }}?');">
                                                @csrf
                                                <input type="hidden" name="_origin" value="outstanding-invoices">
                                                <button type="submit" style="background: linear-gradient(to right, #16a34a, #15803d) !important; color: white !important;" class="inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded-md shadow-sm hover:opacity-90">
                                                    ✓ Approve
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('fin.ledger.reject', $settlement->id) }}" class="inline" onsubmit="return confirm('Reject this settlement?');">
                                                @csrf
                                                <input type="hidden" name="_origin" value="outstanding-invoices">
                                                <button type="submit" style="background: linear-gradient(to right, #dc2626, #b91c1c) !important; color: white !important;" class="inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded-md shadow-sm hover:opacity-90">
                                                    ✗ Reject
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                        @endforeach
                    @endif
                    
                    <tfoot class="bg-gray-50">
                        <tr>
                            <td colspan="6" class="px-3 py-2 text-right text-xs font-bold text-gray-700">
                                Subtotal:
                            </td>
                            <td class="px-3 py-2 text-right">
                                <span class="text-sm font-bold text-purple-700">Rs. {{ number_format($riderData['total_outstanding'], 2) }}</span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                @endif
            </div>

            <!-- Quick Actions -->
            <div class="bg-gray-50 px-4 py-2 border-t border-gray-200 flex justify-between items-center">
                <span class="text-xs text-gray-500">{{ $riderData['invoice_count'] }} invoice(s)</span>
                <a href="{{ route('fin.employee.show', $riderData['account']->id) }}" 
                   class="inline-flex items-center px-3 py-1.5 bg-purple-600 hover:bg-purple-700 text-white text-xs font-medium rounded-md transition-colors">
                    View Ledger →
                </a>
            </div>
            </div>{{-- /dc-rider-body --}}
        </div>
        @endforeach
    </div>
    @endif
    @endif

</div>

{{-- Backdrop for a maximized pane. Outside the page wrapper so it is
     never clipped by an ancestor. --}}
<div id="dc-scrim" class="dc-scrim" onclick="dcRestoreMax()"></div>

@if($canWaChat)
{{-- The customer's WhatsApp conversation, in a drawer on this page — the same
     one Online Approvals uses, so the chat opens identically on both. Rendered
     only for users who may read messages; /messages would answer 403 inside the
     frame otherwise. --}}
@include('partials.wa-chat-drawer')
@endif

{{-- ONE payment-proof card renderer, shared with Online Approvals. Before this,
     Daily Closing had its own older copy that knew only "screenshot or email" —
     so every bank-SMS proof (the majority of confirmations) was captioned
     "Bank email" and its text was offered as "Show raw email text". --}}
@include('partials.proof-signal-card')

<!-- Payment Proof viewer (screenshot + parsed bank email) -->
{{-- ── Sep-2026 · "which bills should this reminder cover?" ─────────────────
     This board is per ORDER. A customer with a second unpaid invoice — an older
     one that has aged out of the 3-day window, or a second delivery sitting on
     this very board — looked here like a one-invoice chase, and got the
     one-invoice reminder. Only Online Approvals, which groups by customer, knew
     better and switched to the multi-invoice template on its own.

     ⭐⭐ EVERY OTHER BILL STARTS UNTICKED, including ones with no proof. The
     workflow this serves is: the operator sees there is more outstanding, asks
     the manager, and only then widens the message. Defaulting to "all" would
     turn a hurried click into a bigger demand than anyone authorised, so the
     default is exactly what the button did before this existed. --}}
<div id="fuBillsModal" style="display:none; position:fixed; inset:0; z-index:99999; background:rgba(17,24,39,0.6); align-items:center; justify-content:center; padding:16px;" onclick="if(event.target===this)fuCloseBills()">
    <div style="background:#fff; border-radius:14px; max-width:660px; width:100%; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(0,0,0,0.3);" onclick="event.stopPropagation()">
        <div style="display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid #eef2f7;">
            <div style="font-weight:700; color:#111827;">Payment reminder <span id="fuBillsCustomer" style="color:#6b7280; font-weight:600;"></span></div>
            <button type="button" onclick="fuCloseBills()" style="border:0; background:#f3f4f6; width:30px; height:30px; border-radius:8px; cursor:pointer; font-size:16px; color:#374151;">&times;</button>
        </div>
        <div id="fuBillsIntro" style="padding:12px 18px; background:#fffbeb; border-bottom:1px solid #fde68a; font-size:13px; color:#92400e;"></div>
        <div style="padding:14px 18px; overflow-y:auto; flex:1 1 auto; min-height:0;">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:9px;">
                <div style="font-size:11px; font-weight:700; color:#6b7280; letter-spacing:0.03em;">TICK THE BILLS THIS MESSAGE SHOULD COVER</div>
                <button type="button" id="fuBillsAllBtn" onclick="fuToggleAllBills()"
                        style="border:0; background:#eff6ff; color:#1d4ed8; font-size:11.5px; font-weight:700; padding:4px 9px; border-radius:7px; cursor:pointer;"></button>
            </div>
            <div id="fuBillsList"></div>
            <div style="margin-top:14px;">
                <div style="font-size:11px; font-weight:700; color:#166534; margin-bottom:6px;">💬 WHAT THE CUSTOMER WILL RECEIVE</div>
                <div id="fuBillsPreview" style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:13px; font-size:12.5px; color:#1f2937; line-height:1.6; white-space:pre-wrap; max-height:190px; overflow-y:auto;"></div>
            </div>
        </div>
        <div style="border-top:1px solid #eef2f7; background:#f8fafc; padding:12px 18px; border-radius:0 0 14px 14px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
            <span id="fuBillsSummary" style="font-size:12.5px; color:#6b7280;"></span>
            <div style="display:flex; gap:8px;">
                <button type="button" onclick="fuCloseBills()" style="border:0; background:#e5e7eb; color:#374151; border-radius:9px; padding:9px 16px; font-size:13px; font-weight:700; cursor:pointer;">Cancel</button>
                <button type="button" id="fuBillsSendBtn" onclick="fuSendSelectedBills()"
                        style="background:#25D366; color:#fff; border:0; border-radius:9px; padding:9px 18px; font-size:13.5px; font-weight:700; cursor:pointer; white-space:nowrap;"></button>
            </div>
        </div>
    </div>
</div>

<div id="proofModal" style="display:none; position:fixed; inset:0; z-index:99999; background:rgba(17,24,39,0.6); align-items:center; justify-content:center; padding:16px;" onclick="if(event.target===this)closeProofModal()">
    <div style="background:#fff; border-radius:14px; max-width:680px; width:100%; max-height:90vh; overflow:auto; box-shadow:0 20px 60px rgba(0,0,0,0.3);" onclick="event.stopPropagation()">
        <div style="display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid #eef2f7;">
            <div style="font-weight:700; color:#111827;">Payment proof <span id="proofModalOrder" style="color:#6b7280; font-weight:600;"></span></div>
            <button type="button" onclick="closeProofModal()" style="border:0; background:#f3f4f6; width:30px; height:30px; border-radius:8px; cursor:pointer; font-size:16px; color:#374151;">&times;</button>
        </div>
        <div id="proofModalBody" style="padding:16px 18px;">
            <div style="text-align:center; color:#6b7280; padding:24px;">Loading…</div>
        </div>
        {{-- ⭐ APPROVE LIVES HERE, UNDER THE EVIDENCE — never on the row itself.
             The manager asked to see each proof before accepting it, so the only
             route to the button is through opening the proof. There is
             deliberately no bulk approve on this page (Online Approvals keeps
             that for its own reviewed queue). Hidden unless this user holds L1
             AND the row carries an approvable invoice. --}}
        <div id="proofApproveBar" style="display:none; border-top:1px solid #eef2f7; background:#f8fafc; padding:13px 18px; border-radius:0 0 14px 14px;">
            <div id="proofApproveBanks" style="display:none; align-items:center; gap:7px; flex-wrap:wrap; margin-bottom:11px;"></div>
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                <span id="proofApproveNote" style="font-size:12px; color:#6b7280;"></span>
                <button type="button" id="proofApproveBtn" onclick="dcApproveFromProof()"
                        style="background:#16a34a; color:#fff; border:0; border-radius:9px; padding:9px 20px; font-size:13.5px; font-weight:700; cursor:pointer; white-space:nowrap;">
                    ✅ Approve payment (L1)
                </button>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript for Interactive Filters -->
<script>
// ⭐ The proof viewer. Opened from a row's badge via dcOpenProof(), which also
// carries what this order can be approved as.
//
// Aug-2026: the per-signal card is now rendered by the SHARED partial
// (partials/proof-signal-card, the renderer Online Approvals uses). The copy
// that used to live here knew only "whatsapp or else", so every bank-SMS
// confirmation — the majority — was captioned "Bank email", and its text was
// offered as "Show raw email text". Same proof, two screens, two stories.
var dcProofCtx = null;   // { orderId, orderNumber, ledgerId, amount, canApprove }
var dcProofBank = null;  // chosen receiving bank for the approval

function dcOpenProof(el) {
    var ctx = {};
    try { ctx = JSON.parse(el.getAttribute('data-proof') || '{}'); } catch (e) { ctx = {}; }
    openProofModal(ctx.orderId, ctx.orderNumber, ctx);
}

function openProofModal(orderId, orderNumber, ctx) {
    var modal = document.getElementById('proofModal');
    var body = document.getElementById('proofModalBody');
    dcProofCtx = ctx || null;
    dcProofBank = null;
    dcHideApproveBar();
    document.getElementById('proofModalOrder').textContent = orderNumber ? ('— ' + orderNumber) : '';
    body.innerHTML = '<div style="text-align:center; color:#6b7280; padding:24px;">Loading…</div>';
    modal.style.display = 'flex';

    fetch('/admin/payments/order/' + orderId + '/signals', { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.success || !d.signals || d.signals.length === 0) {
                body.innerHTML = '<div style="text-align:center; color:#6b7280; padding:24px;">No proof details found for this order.</div>';
                return;
            }
            body.innerHTML = renderCombinedHint(d.combined)
                + d.signals.map(function (sig) {
                    return nfProofSignalCard(sig, { orderId: orderId });
                }).join('');
            dcShowApproveBar(d);
        })
        .catch(function () {
            body.innerHTML = '<div style="text-align:center; color:#dc2626; padding:24px;">Could not load proof details.</div>';
        });
}

function renderCombinedHint(c) {
    if (!c || !c.invoices || c.invoices.length < 2) return '';
    var fmt = function (n) { return Number(n || 0).toLocaleString(); };
    var rows = c.invoices.map(function (inv) {
        return '<div style="display:flex; justify-content:space-between; font-size:12px; padding:2px 0;">'
            + '<span style="color:#92400e;">' + (inv.order_number || ('#' + inv.order_id)) + '</span>'
            + '<span style="color:#92400e; font-weight:600;">Rs. ' + fmt(inv.balance) + '</span></div>';
    }).join('');
    return '<div style="margin-bottom:12px; padding:10px 12px; background:#fffbeb; border:1px solid #fde68a; border-radius:10px;">'
        + '<div style="font-weight:700; color:#92400e; margin-bottom:6px;">🔗 Looks like a combined payment</div>'
        + '<div style="font-size:12px; color:#92400e; margin-bottom:6px;">The paid amount (Rs. ' + fmt(c.amount)
        + ') matches the total of this customer\'s open invoices (Rs. ' + fmt(c.open_total)
        + '). It likely covers all of these — apply it across them manually:</div>'
        + rows + '</div>';
}

// (renderProofSignal lived here — replaced by the shared nfProofSignalCard.)

function closeProofModal() {
    document.getElementById('proofModal').style.display = 'none';
    dcProofCtx = null;
    dcHideApproveBar();
}

// ── Approve a payment at Level 1, from inside its proof ─────────────────────
//
// WHY L1 FROM HERE: L1 is the moment the money lands in the balances
// (BalancePostingService runs at L1; L2 only verifies), so the closing manager
// approving what he has just read is the same act he would perform in Online
// Approvals — one screen instead of two. The server re-checks his approval
// rights on every call, so this page can only ever OFFER the button.
//
// ⭐ NO BULK. Every approval passes through one open proof by construction.
const DC_PAY_BANKS = @json($petrolPayBanks ?? []);
const DC_CAN_APPROVE_L1 = @json($canApproveL1 ?? false);

function dcHideApproveBar() {
    var bar = document.getElementById('proofApproveBar');
    if (bar) bar.style.display = 'none';
}

function dcShowApproveBar(d) {
    var bar = document.getElementById('proofApproveBar');
    if (!bar || !DC_CAN_APPROVE_L1 || !dcProofCtx || !dcProofCtx.canApprove || !dcProofCtx.ledgerId) return;

    // Which of OUR banks received it. An online invoice MUST name one or the
    // per-bank balances never see the money — the server refuses without it, so
    // the picker exists wherever the refusal can happen (same invariant as the
    // petrol/maintenance rows).
    // ⚠ Only trust a suggestion the operator can actually SEE and change. A
    // detected bank that is not in the selectable list (deactivated since the
    // proof was read) would otherwise be posted with no chip lit and no prompt
    // — the one state where the page decides which bank got the money and the
    // operator never knows. Unknown suggestion => no preselection, and the
    // "pick the bank" prompt appears.
    var suggested = d && d.proof ? parseInt(d.proof.suggested_receiving_account_id, 10) : NaN;
    var offered = DC_PAY_BANKS.some(function (b) { return parseInt(b.id, 10) === suggested; });
    dcProofBank = offered ? suggested : null;
    dcRenderApproveBanks();

    var note = document.getElementById('proofApproveNote');
    if (note) {
        note.innerHTML = 'Approving posts <b>Rs. ' + Number(dcProofCtx.amount || 0).toLocaleString()
            + '</b> to the balances now. Level 2 verification still follows.';
    }
    var btn = document.getElementById('proofApproveBtn');
    if (btn) { btn.disabled = false; btn.textContent = '✅ Approve payment (L1)'; btn.style.background = '#16a34a'; }
    bar.style.display = 'block';
}

function dcRenderApproveBanks() {
    var wrap = document.getElementById('proofApproveBanks');
    if (!wrap) return;
    if (!DC_PAY_BANKS.length) { wrap.style.display = 'none'; return; }   // old server / none configured
    var chips = DC_PAY_BANKS.map(function (b) {
        var on = dcProofBank === parseInt(b.id, 10);
        // Only a well-formed hex colour reaches the style attribute.
        var hex = /^#[0-9a-fA-F]{3,8}$/.test(String(b.color_hex || '')) ? b.color_hex : '#7c3aed';
        return '<button type="button" onclick="dcPickApproveBank(' + parseInt(b.id, 10) + ')" '
            + 'style="border:1px solid ' + (on ? hex : '#d1d5db') + '; '
            + 'background:' + (on ? hex : '#fff') + '; color:' + (on ? '#fff' : '#374151') + '; '
            + 'border-radius:999px; padding:4px 12px; font-size:11.5px; font-weight:700; cursor:pointer;">'
            + (on ? '✓ ' : '') + nfEsc(b.short_code || b.name) + '</button>';
    }).join('');
    wrap.innerHTML = '<span style="font-size:11.5px; color:#6b7280;">🏦 Received in:</span>' + chips
        + (dcProofBank ? '' : '<span style="font-size:11.5px; color:#b45309;">pick the bank this landed in</span>');
    wrap.style.display = 'flex';
}

function dcPickApproveBank(id) {
    dcProofBank = parseInt(id, 10);
    dcRenderApproveBanks();
}

function nfEsc(v) {
    return v == null ? '' : String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

async function dcApproveFromProof() {
    if (!dcProofCtx || !dcProofCtx.ledgerId) return;
    var btn = document.getElementById('proofApproveBtn');

    if (DC_PAY_BANKS.length && !dcProofBank) {
        alert('Choose which bank this payment landed in before approving.');
        return;
    }

    // The same advisory payer check Online Approvals runs: when the match was a
    // guess (amount-only, or a name the system read), say so before the money
    // moves. Never blocks on its own failure.
    if (!(await dcPayerCheck(dcProofCtx.orderId))) return;

    if (!confirm('Approve Rs. ' + Number(dcProofCtx.amount || 0).toLocaleString()
        + ' for ' + (dcProofCtx.orderNumber || 'this order') + ' at Level 1?\n\n'
        + 'This posts the payment to the balances now.')) return;

    dcPaneTouched('messages');

    btn.disabled = true;
    btn.textContent = 'Approving…';
    btn.style.background = '#9ca3af';

    var payload = { approval_notes: 'Approved from Daily Closing (payment follow-ups)' };
    if (dcProofBank) payload.receiving_account_id = dcProofBank;

    try {
        const resp = await fetch('/finance/ledger/' + dcProofCtx.ledgerId + '/approve-l1-only', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': fuCsrf(),
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload)
        });
        const data = await resp.json().catch(function () { return {}; });

        if (resp.ok && data && data.success) {
            dcMarkRowApproved(dcProofCtx);
            closeProofModal();
        } else {
            alert((data && data.message) || 'Could not approve this payment.');
            btn.disabled = false;
            btn.textContent = '✅ Approve payment (L1)';
            btn.style.background = '#16a34a';
        }
    } catch (e) {
        alert('Could not reach the server. The payment was NOT approved.');
        btn.disabled = false;
        btn.textContent = '✅ Approve payment (L1)';
        btn.style.background = '#16a34a';
    }
}

// Advisory only — a check that cannot answer must never stop a legitimate
// approval (identical stance to Online Approvals' runPayerCheck).
async function dcPayerCheck(orderId) {
    try {
        const res = await fetch('/admin/payments/approval-check', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': fuCsrf(),
                'Accept': 'application/json'
            },
            body: JSON.stringify({ order_ids: [orderId] })
        });
        if (!res.ok) return true;
        const data = await res.json();
        const flagged = (data && data.items) || [];
        if (!flagged.length) return true;
        const f = flagged[0];
        return confirm('⚠ How this payment was matched is a guess.\n\n'
            + (f.message || (f.order_number + ' — the payer could not be confirmed.'))
            + '\n\nApprove anyway?');
    } catch (e) {
        return true;
    }
}

// Move the row out of "proof in" and into "L1 done" in place. A full reload
// would cost ~1.1s of SQL and throw away scroll position, the open groups and
// any half-finished work elsewhere on the page.
function dcMarkRowApproved(ctx) {
    // Find the row by READING each badge's payload rather than matching on it
    // with an attribute selector: the payload is JSON, so a selector for it
    // needs nested quotes and is a CSS SyntaxError — which threw here and left
    // the modal open on a successful approval.
    var group = document.getElementById('followup-proof');
    if (!group) return;
    var badge = null;
    group.querySelectorAll('[data-proof]').forEach(function (el) {
        if (badge) return;
        try {
            if (JSON.parse(el.getAttribute('data-proof')).orderId === ctx.orderId) badge = el;
        } catch (e) { /* a malformed payload just isn't the row we want */ }
    });
    if (!badge) return;

    var row = badge.closest('div.flex.items-center.justify-between');
    var actions = badge.parentElement;
    if (row) row.style.backgroundColor = '#f0f7ff';

    // The row can no longer be approved a second time.
    badge.setAttribute('data-proof', JSON.stringify(Object.assign({}, ctx, { canApprove: false, ledgerId: null })));
    badge.innerHTML = badge.innerHTML.replace(/🔍 review/, '🔍');

    if (actions && !actions.querySelector('.dc-l1-done')) {
        var done = document.createElement('span');
        done.className = 'dc-l1-done text-xs font-bold px-1.5 py-0.5 rounded';
        done.style.cssText = 'background-color:#dbeafe; color:#1e40af;';
        done.textContent = '✓ approved (L1)';
        actions.insertBefore(done, actions.firstChild);
    }

    dcBumpProofCounts(ctx.amount);
}

// Keep the group headers honest after a row moves — count AND amount together,
// because "0 proof in · Rs. 5,400" is a contradiction on a money screen.
//
// Deliberately escape-free regexes ([0-9] rather than the shorter form): this
// text passes through a generator, and a lost backslash silently turns a digit
// class into a literal letter.
function dcBumpProofCounts(amount) {
    var head = document.querySelector('[onclick*="followup-proof"] .text-xs.font-bold');
    if (head) {
        var left = null;
        var txt = head.textContent
            .replace(/([0-9]+) proof in/, function (m, n) {
                left = Math.max(0, parseInt(n, 10) - 1);
                return left + ' proof in';
            })
            .replace(/Rs[.] ([0-9,]+)/, function (m, n) {
                var v = Math.max(0, parseInt(n.replace(/,/g, ''), 10) - Math.round(amount || 0));
                return 'Rs. ' + v.toLocaleString();
            });
        // Nothing left to review reads better than a zero beside a total.
        head.textContent = (left === 0) ? '✓ all proofs reviewed' : txt;
    }
    document.querySelectorAll('[data-fu-proof]').forEach(function (chip) {
        var left = 0;
        chip.textContent = chip.textContent.replace(/^([0-9]+)/, function (m, n) {
            left = Math.max(0, parseInt(n, 10) - 1);
            return String(left);
        });
        // Sep-2026: the badge is now always in the DOM (hidden when zero) so the
        // background refresh can reveal it again. Hide it at zero here too, or
        // approving the last proof leaves a "0 proof in" chip sitting in the
        // header claiming there is work left.
        chip.classList.toggle('hidden', left === 0);
    });
}

function filterByStatus(status) {
    document.getElementById('status-filter').value = status;
    document.getElementById('filter-form').submit();
}

function togglePendingSettlements() {
    const section = document.getElementById('pending-settlements-section');
    if (section) {
        section.classList.toggle('hidden');
    }
}

// ⭐ Toggle date group visibility
function toggleDateGroup(date) {
    const content = document.getElementById('date-content-' + date);
    const icon = document.getElementById('toggle-icon-' + date);
    if (content) {
        content.classList.toggle('hidden');
        if (icon) {
            // Rotate arrow: pointing right when collapsed, down when expanded
            if (content.classList.contains('hidden')) {
                icon.style.transform = 'rotate(-90deg)';
            } else {
                icon.style.transform = 'rotate(0deg)';
            }
        }
    }
}

// Auto-show pending settlements if there are any
@if($stats['pending_settlement_count'] > 0 && $filters['status'] == 'all')
    // Optionally auto-show on page load
    // togglePendingSettlements();
@endif

// Jul-2026 rewrite: the old version stripped ONE leading zero then prepended
// +92 with no last-10 step, so an order typed "00923215793000" became
// "+920923215793000" — an undeliverable junk number (prod 131026 failure,
// order SH-21020). Now mirrors the server's last-10 rule: any PK-shaped
// number collapses to +92 + last-10; a number that clearly carries its own
// country code (11+ digits, no leading 0/92 after stripping a "00" prefix)
// passes through. This matters here even though the server also heals the
// number, because the wa.me FALLBACK below opens WhatsApp directly from the
// browser and never touches our server. See WHATSAPP-PHONE-HANDLING.md.
function formatPhoneForWhatsApp(phone) {
    if (!phone) return null;
    let digits = phone.replace(/\D/g, '');
    if (digits.startsWith('00')) digits = digits.substring(2);
    if (digits.startsWith('92') && digits.length === 12) return '+' + digits;
    if (digits.length >= 11 && !digits.startsWith('0') && !digits.startsWith('92')) return '+' + digits;
    if (digits.length >= 10) return '+92' + digits.slice(-10);
    return null;
}

// ── Payment Follow-ups send flow (Aug-2026) ────────────────────────────────
// Replaces sendOnlineWhatsApp(). Three behavioural changes over the old one:
//
//  1. TEMPLATE LADDER. Day 1 sends the delivery confirmation. Day 2-3 sends
//     payment_reminder_single, which carries the invoice image — re-sending
//     "your order was delivered today!" three days running reads badly and
//     burns trust.
//     Aug-2026: day 1 is now delivery_confirmation_online_v2, which offers a
//     "Get bank details" button instead of printing the accounts (tapping it
//     replies with them automatically). Same 4 variables, so nothing else here
//     changed. The order_delivered_payment_confirmation automation normally
//     sends this at delivery — this button is the manual path for when that is
//     off, skipped, or failed, and an automated send stamps the same
//     online_message_sent_at, so a row it already handled shows "reminded
//     today" here instead of inviting a duplicate.
//
//  2. HONEST STAMPING. The old flow marked the order "sent" even when the API
//     call failed and it merely opened a wa.me tab — a green tick could mean
//     nothing was sent. Chasing over several days is worthless if "reminded
//     yesterday" might be a lie, so we now stamp only on a confirmed API send,
//     or after the operator confirms they really sent the manual fallback.
//
//  3. RAW PHONE TO THE SERVER. The page-local formatPhoneForWhatsApp() mangles
//     already-international numbers (it turns 00923215793000 into
//     +920923215793000 — see WHATSAPP-PHONE-HANDLING.md). The server's
//     resolveDialPhone() handles this correctly and is a no-op for PK numbers,
//     so the API send passes the number through untouched. The local formatter
//     is still used for the wa.me fallback only, which has no server in the path.
const FU_TEMPLATE_DAY_ONE = 'delivery_confirmation_online_v2';
const FU_TEMPLATE_FOLLOW_UP = 'payment_reminder_single';
// Sep-2026 — one reminder covering SEVERAL of a customer's unpaid bills.
// ⚠⚠ It declares NO media header, so it must never be sent with an order_id:
// order_id triggers the invoice-image attach and Meta rejects a header component
// on a template that doesn't declare one, failing the whole send.
const FU_TEMPLATE_FOLLOW_UP_MULTI = 'payment_reminder_multiples';

// wa.me manual fallback text, used only when the API send fails and the operator
// sends by hand. Accounts come from the server (BankDetailsProvider) so this can
// never drift from what the "Get bank details" button replies with — they used
// to be separate hardcoded lists and had already diverged (this one still named
// HBL, which is no longer in use).
//
// The json directive below is deliberate: it emits a real JSON string literal
// with escaped newlines. Interpolating this multi-line text with an echo braces
// expression instead would put raw newlines and escaped quote entities inside a
// JS string literal and kill every handler on the page.
window.FU_BANK_ACCOUNTS = @json(\App\Services\WhatsApp\BankDetailsProvider::accountsBlock());

function fuBankDetailsMessage(row) {
    var deliveryInfo = row.delivery_date + (row.delivery_time ? ' at ' + row.delivery_time : '');
    return 'Dear ' + row.customer_name + ',\n\n'
        + 'We are happy to confirm that your order #' + row.order_number + ' has been successfully delivered on ' + deliveryInfo + ' by our rider ' + row.rider_name + '.\n\n'
        + 'Your payment method is Online Bank Transfer. Please share a screenshot of the transfer here once the transaction has been made.\n\n'
        + (window.FU_BANK_ACCOUNTS || '') + '\n\n'
        + 'Thank you for choosing Nizami Farms!';
}

function fuBodyParams(templateName, row) {
    if (templateName === FU_TEMPLATE_FOLLOW_UP) {
        // Same three params the Online Approvals reminder uses.
        return [row.customer_name, row.order_number, Number(row.amount).toLocaleString()];
    }
    var deliveryInfo = row.delivery_date + (row.delivery_time ? ' at ' + row.delivery_time : '');
    return [row.customer_name, row.order_number, deliveryInfo, row.rider_name];
}

function fuStatus(btn, text, color) {
    var wrap = btn.closest('div');
    var el = wrap ? wrap.querySelector('.fu-status') : null;
    if (el) {
        el.textContent = text || '';
        el.style.color = color || '#6b7280';
    }
}

function fuCsrf() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

function fuPostTemplate(row, templateName) {
    var payload = {
        phone: row.customer_phone,
        template_name: templateName,
        body_params: fuBodyParams(templateName, row)
    };

    if (templateName === FU_TEMPLATE_FOLLOW_UP) {
        // order_id drives the invoice-image auto-attach AND the history stamp.
        payload.order_id = row.id;
    } else {
        // delivery_confirmation_online has NO media header — passing order_id
        // would attach an invoice image and Meta rejects a header component on a
        // template that doesn't declare one. related_order_number stamps the send
        // history without touching the header.
        payload.related_order_number = row.order_number;
    }

    return fetch('/messages/send-template', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': fuCsrf(),
            'Accept': 'application/json'
        },
        body: JSON.stringify(payload)
    }).then(function (resp) {
        return resp.json().catch(function () { return {}; }).then(function (data) {
            return { ok: resp.ok && data && data.success, status: resp.status, data: data || {} };
        });
    });
}

function fuMarkReminded(row, btn, note) {
    return fetch('{{ route("fin.employee.mark-online-message-sent", ["orderId" => "__ID__"]) }}'.replace('__ID__', row.id), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': fuCsrf(),
            'Accept': 'application/json'
        },
        body: JSON.stringify({})
    })
    .then(function (resp) { return resp.json(); })
    .then(function (data) {
        if (!data.success) {
            fuStatus(btn, 'Sent, but not recorded — refresh', '#b91c1c');
            return;
        }
        // The row stays in the list (it only leaves when proof arrives, the money
        // is approved, or it ages out of the window) — but it can't be reminded
        // again until tomorrow.
        btn.disabled = true;
        btn.style.backgroundColor = '#cbd5e1';
        btn.textContent = '✓ Reminded today';
        btn.title = 'Already reminded today — try again tomorrow';
        fuStatus(btn, note || ('sent ' + (data.sent_at || 'now')), '#166534');
    })
    .catch(function () {
        fuStatus(btn, 'Sent, but not recorded — refresh', '#b91c1c');
    });
}

function fuManualFallback(row, btn, reason) {
    var formatted = formatPhoneForWhatsApp(row.customer_phone);
    if (!formatted) {
        fuStatus(btn, 'No usable phone number', '#b91c1c');
        btn.disabled = false;
        return;
    }
    var waUrl = 'https://wa.me/' + formatted.replace('+', '') + '?text=' + encodeURIComponent(fuBankDetailsMessage(row));
    window.open(waUrl, '_blank');

    // Only the operator knows whether they actually pressed send in WhatsApp, so
    // ask rather than assume. Answering "no" leaves the row chaseable.
    setTimeout(function () {
        var sent = confirm(
            (reason ? reason + '\n\n' : '')
            + 'WhatsApp was opened for ' + row.customer_name + ' (' + row.order_number + ').\n\n'
            + 'Did you send the message?\n\n'
            + 'OK = yes, record it.   Cancel = no, leave it on the list.'
        );
        if (sent) {
            fuMarkReminded(row, btn, 'sent manually');
        } else {
            btn.disabled = false;
            fuStatus(btn, 'not sent', '#b91c1c');
        }
    }, 400);
}

function sendFollowUp(btn) {
    var row;
    try {
        row = JSON.parse(btn.dataset.row);
    } catch (e) {
        alert('Could not read this row. Please refresh the page.');
        return;
    }

    if (!row.customer_phone) {
        fuStatus(btn, 'No phone number on this order', '#b91c1c');
        return;
    }

    // Sep-2026 — this customer owes on more than this one invoice, so ask WHICH
    // bills the message should cover before sending anything. A row whose
    // customer has no other open bill is untouched and still sends immediately:
    // everything below this branch is the pre-existing single-invoice flow.
    if (row.other_bills_count > 0) {
        fuOpenBills(row, btn);
        return;
    }

    // NOTE: there is deliberately no page-load proof check here any more. It used
    // to warn from row.proof_label, but that data is only as fresh as the page —
    // it missed exactly the case that matters (proof arriving after load) while
    // adding a second dialog for the case the server already catches. The
    // precheck below asks the server instead, and is strictly better informed.
    // Hands the Messages pane off the background refresh while this send runs
    // and for a few seconds after, so the row cannot be swapped mid-flight and
    // the "✓ Reminded today" it turns into is actually seen.
    dcPaneTouched('messages');

    btn.disabled = true;
    fuStatus(btn, 'checking…', '#6b7280');

    // Ask the server what this order looks like RIGHT NOW before sending. The
    // page's own proof data is only as fresh as the page, and a proof that
    // landed five minutes ago would otherwise go unnoticed — we'd nag a customer
    // who has already paid, which is the exact mistake this panel exists to
    // prevent. Fails open: a precheck problem must never block a real reminder.
    fuPrecheck(row)
        .then(function (pre) {
            if (pre && pre.stale) {
                var proceed = confirm(
                    '⚠️ This changed after the page was loaded\n\n'
                    + row.order_number + ' — ' + row.customer_name + '\n\n'
                    + pre.message + '\n\n'
                    + 'Send the reminder anyway?'
                );
                if (!proceed) {
                    btn.disabled = false;
                    fuStatus(btn, 'not sent — refresh the page', '#b91c1c');
                    return;
                }
            }
            return fuDispatch(row, btn);
        })
        .catch(function () {
            return fuDispatch(row, btn);
        });
}

function fuPrecheck(row) {
    return fetch('{{ route("fin.employee.followup-precheck", ["orderId" => "__ID__"]) }}'.replace('__ID__', row.id), {
        headers: { 'Accept': 'application/json' }
    })
    .then(function (resp) { return resp.json(); })
    .catch(function () { return null; });
}

function fuDispatch(row, btn) {
    fuStatus(btn, 'sending…', '#6b7280');

    var template = row.template || FU_TEMPLATE_DAY_ONE;

    return fuPostTemplate(row, template)
        .then(function (res) {
            if (res.ok) {
                return fuMarkReminded(row, btn);
            }

            // payment_reminder_single is refused (422) when the invoice image was
            // never captured for this order — the PNG is produced in the browser
            // on the invoice page, not server-side, so it simply may not exist.
            // Fall back to the day-1 template, which needs no image and still
            // carries the bank details, rather than leaving a dead button.
            if (res.status === 422 && template === FU_TEMPLATE_FOLLOW_UP) {
                fuStatus(btn, 'no invoice image — sending confirmation…', '#92400e');
                return fuPostTemplate(row, FU_TEMPLATE_DAY_ONE).then(function (res2) {
                    if (res2.ok) {
                        return fuMarkReminded(row, btn, 'sent (delivery confirmation — no invoice image)');
                    }
                    fuManualFallback(row, btn, 'Automatic send failed: ' + (res2.data.message || 'unknown error'));
                });
            }

            fuManualFallback(row, btn, 'Automatic send failed: ' + (res.data.message || ('HTTP ' + res.status)));
        })
        .catch(function (err) {
            console.error('Follow-up send failed:', err);
            fuManualFallback(row, btn, 'Could not reach the server.');
        });
}

// ── Multi-bill reminders (Sep-2026) ────────────────────────────────────────
//
// The problem this solves: the follow-up board is built per ORDER over a 3-day
// window, so a customer with a second unpaid invoice — an older one that has
// aged past the window, or a second delivery sitting on this very board — read
// here as a one-invoice chase and got the one-invoice reminder. The operator had
// no way to know. Online Approvals, which groups by customer, already switches
// to `payment_reminder_multiples` on its own; this brings the same knowledge and
// the same template to the screen the chasing actually happens on.
//
// ⭐⭐ The other bills start UNTICKED. The real workflow is "notice there's more,
// ask the manager, then widen the message" — so the default must stay exactly
// what the button did before, and widening has to be a deliberate act. Ticking
// everything by default would let a hurried click demand more than was agreed.
//
// ⭐ A selection of exactly the row that was clicked hands straight back to
// fuDispatch() — the untouched pre-existing path, with its 422-to-day-one
// fallback and its manual wa.me fallback. The new code below only ever runs for
// a selection that the old flow could not express.
var fuBillsState = null;   // { row, btn, bills: [...], selected: [ids] }

// Own escaper on purpose. This page has no global escapeHtml — the one in
// partials/proof-signal-card is private to that renderer — and an apostrophe in
// a customer name must never be able to break the modal's markup.
function fuEsc(s) {
    return String(s === null || s === undefined ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function fuMoney(n) {
    return Number(n || 0).toLocaleString();
}

// payment_reminder_multiples greets with the first name only — same as the
// Online Approvals send, so one customer never gets two different salutations.
function fuFirstName(name) {
    return String(name || '').split(' ')[0] || name || '';
}

function fuSingleReminderText(name, orderNumber, amount) {
    return 'Assalamoalikum ' + name + ',\n\n'
        + 'We hope this message finds you well. We are writing to kindly remind you of an outstanding invoice '
        + orderNumber + ' on your account. Please settle the payment of Rs ' + fuMoney(amount)
        + ' at your earliest convenience.\n\n'
        + 'If you have already made the payment, kindly share a screenshot of the transaction so we can update our records accordingly.\n\n'
        + 'Thank you for your understanding and cooperation';
}

function fuMultiReminderText(firstName, numbers, total) {
    return 'Dear ' + firstName + ',\n\n'
        + 'This is a payment reminder from Nizami Farms for invoice(s): ' + numbers + '.\n\n'
        + 'Total pending amount: PKR ' + fuMoney(total) + '.\n\n'
        + 'If payment has already been made, please reply with the payment confirmation so we can update your account.\n\n'
        + 'Thank you,\nNizami Farms';
}

function fuOpenBills(row, btn) {
    var bills = [{
        id: row.id,
        order_number: row.order_number,
        amount: Number(row.amount) || 0,
        delivery_date: row.delivery_date,
        // The board clamps day_number to the 3-day window, so phrase the
        // clicked row's age from that rather than inventing a second number.
        age_label: row.day_number > 1 ? (row.day_number - 1) + ' days ago' : 'today',
        in_window: true,
        has_proof: !!row.proof_label,
        proof_label: row.proof_label || null,
        settled: false,
        fresh_note: null,
        is_primary: true
    }];

    (row.other_open_bills || []).forEach(function (b) {
        bills.push({
            id: b.id,
            order_number: b.order_number,
            amount: Number(b.amount) || 0,
            delivery_date: b.delivery_date,
            age_label: b.age_label,
            in_window: !!b.in_window,
            has_proof: !!b.has_proof,
            proof_label: b.proof_label || null,
            settled: false,
            fresh_note: null,
            is_primary: false
        });
    });

    fuBillsState = { row: row, btn: btn, bills: bills, selected: [row.id] };

    document.getElementById('fuBillsCustomer').textContent = '— ' + row.customer_name;

    var openCount = row.other_bills_open_count || 0;
    var proofCount = row.other_bills_proof_count || 0;
    var intro = '<strong>' + fuEsc(row.customer_name) + '</strong> has '
        + (row.other_bills_count === 1 ? 'another unpaid bill' : row.other_bills_count + ' other unpaid bills')
        + ' besides this one';
    if (openCount > 0) {
        intro += ' — Rs. ' + fuMoney(row.other_bills_open_amount) + ' on top of this invoice.';
    } else {
        intro += '.';
    }
    if (proofCount > 0) {
        intro += ' ' + proofCount + ' of them already ' + (proofCount === 1 ? 'has' : 'have')
            + ' payment proof and ' + (proofCount === 1 ? 'is' : 'are') + ' left unticked.';
    }
    intro += '<br><span style="color:#78350f;">Only this invoice is ticked. Confirm with the manager before widening the message.</span>';
    document.getElementById('fuBillsIntro').innerHTML = intro;

    fuRenderBills();
    document.getElementById('fuBillsModal').style.display = 'flex';

    // Ask the server what each bill looks like RIGHT NOW. The page's own data is
    // only as fresh as the last load, and a proof that landed after it would
    // otherwise go unseen at exactly the moment the operator is deciding how much
    // money to ask for. Fails open, per bill: a precheck problem never blocks or
    // changes a send, it only annotates.
    bills.forEach(function (bill) {
        fuPrecheck({ id: bill.id }).then(function (pre) {
            if (!pre || !pre.success || pre.error) return;

            if (pre.settled) {
                bill.settled = true;
                bill.fresh_note = 'Payment already APPROVED in the ledger';
                // Money that is in the ledger is not money to chase — untick it
                // even if it is the row that was clicked.
                fuBillsState.selected = fuBillsState.selected.filter(function (id) { return id !== bill.id; });
            } else if (pre.has_proof && !bill.has_proof) {
                bill.has_proof = true;
                bill.proof_label = pre.proof_label;
                bill.fresh_note = 'Proof arrived since this page loaded';
            }

            if (bill.fresh_note) fuRenderBills();
        });
    });
}

function fuRenderBills() {
    if (!fuBillsState) return;

    var html = '';

    fuBillsState.bills.forEach(function (bill) {
        var ticked = fuBillsState.selected.indexOf(bill.id) !== -1;
        var blocked = bill.settled;
        var border = ticked ? '#86efac' : (bill.has_proof || blocked ? '#fca5a5' : '#e5e7eb');
        var bg = ticked ? '#f0fdf4' : (bill.has_proof || blocked ? '#fef2f2' : '#fff');

        var chips = '';
        if (bill.is_primary) {
            chips += '<span style="background:#e0e7ff; color:#3730a3; font-size:10.5px; font-weight:700; padding:1px 6px; border-radius:5px;">the row you clicked</span>';
        } else if (bill.in_window) {
            chips += '<span style="background:#e0f2fe; color:#075985; font-size:10.5px; font-weight:700; padding:1px 6px; border-radius:5px;">also on this board</span>';
        } else {
            chips += '<span style="background:#f1f5f9; color:#475569; font-size:10.5px; font-weight:700; padding:1px 6px; border-radius:5px;">older than this board</span>';
        }
        if (bill.proof_label) {
            chips += ' <span style="background:#fee2e2; color:#991b1b; font-size:10.5px; font-weight:700; padding:1px 6px; border-radius:5px;">' + fuEsc(bill.proof_label) + '</span>';
        }

        html += '<label style="display:flex; gap:10px; align-items:flex-start; padding:9px 11px; border:1px solid ' + border
            + '; background:' + bg + '; border-radius:10px; margin-bottom:7px; cursor:' + (blocked ? 'not-allowed' : 'pointer') + ';">'
            + '<input type="checkbox" style="margin-top:3px; width:16px; height:16px; cursor:' + (blocked ? 'not-allowed' : 'pointer') + ';"'
            + ' data-bill-id="' + bill.id + '" onchange="fuBillToggled(this)"'
            + (ticked ? ' checked' : '') + (blocked ? ' disabled' : '') + '>'
            + '<div style="flex:1; min-width:0;">'
            + '<div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">'
            + '<span style="font-family:ui-monospace,monospace; font-weight:700; color:#111827; font-size:13px;">' + fuEsc(bill.order_number) + '</span>'
            + chips + '</div>'
            + '<div style="font-size:11.5px; color:#6b7280; margin-top:2px;">delivered '
            + fuEsc(bill.delivery_date || 'date unknown')
            + (bill.age_label ? ' · ' + fuEsc(bill.age_label) : '') + '</div>'
            + (bill.fresh_note
                ? '<div style="font-size:11.5px; color:#b91c1c; font-weight:700; margin-top:3px;">⚠ ' + fuEsc(bill.fresh_note) + '</div>'
                : '')
            + '</div>'
            + '<div style="font-weight:800; color:#111827; font-size:13px; white-space:nowrap;">Rs. ' + fuMoney(bill.amount) + '</div>'
            + '</label>';
    });

    document.getElementById('fuBillsList').innerHTML = html;
    fuUpdateBillsFooter();
}

function fuBillToggled(el) {
    if (!fuBillsState) return;
    var id = parseInt(el.getAttribute('data-bill-id'), 10);
    var at = fuBillsState.selected.indexOf(id);

    if (el.checked && at === -1) {
        fuBillsState.selected.push(id);
    } else if (!el.checked && at !== -1) {
        fuBillsState.selected.splice(at, 1);
    }

    fuRenderBills();
}

// One button for both directions: widen to every chaseable bill, or fall back to
// the single invoice the operator started from.
function fuToggleAllBills() {
    if (!fuBillsState) return;

    var chaseable = fuBillsState.bills.filter(function (b) { return !b.settled && !b.has_proof; });
    var allTicked = chaseable.length > 0 && chaseable.every(function (b) {
        return fuBillsState.selected.indexOf(b.id) !== -1;
    });

    if (allTicked) {
        // Back to exactly what the row's own button would have sent.
        fuBillsState.selected = fuBillsState.bills.filter(function (b) {
            return b.is_primary && !b.settled;
        }).map(function (b) { return b.id; });
    } else {
        // Bills that already carry proof stay out — they are the ones the
        // customer has most likely already paid.
        fuBillsState.selected = chaseable.map(function (b) { return b.id; });
    }

    fuRenderBills();
}

function fuSelectedBills() {
    if (!fuBillsState) return [];
    return fuBillsState.bills.filter(function (b) {
        return fuBillsState.selected.indexOf(b.id) !== -1;
    });
}

function fuUpdateBillsFooter() {
    var sel = fuSelectedBills();
    var total = sel.reduce(function (sum, b) { return sum + b.amount; }, 0);
    var btn = document.getElementById('fuBillsSendBtn');
    var summary = document.getElementById('fuBillsSummary');
    var allBtn = document.getElementById('fuBillsAllBtn');

    var chaseable = fuBillsState.bills.filter(function (b) { return !b.settled && !b.has_proof; });
    var allTicked = chaseable.length > 0 && chaseable.every(function (b) {
        return fuBillsState.selected.indexOf(b.id) !== -1;
    });
    allBtn.textContent = allTicked ? '↩ Just this bill' : '✓ Tick all ' + chaseable.length + ' chaseable';
    allBtn.style.display = chaseable.length > 1 ? '' : 'none';

    if (sel.length === 0) {
        summary.textContent = 'Nothing ticked — the customer gets no message.';
        btn.disabled = true;
        btn.style.opacity = '0.5';
        btn.style.cursor = 'not-allowed';
        btn.textContent = 'Send reminder';
        document.getElementById('fuBillsPreview').textContent = 'Tick at least one bill to see the message.';
        return;
    }

    btn.disabled = false;
    btn.style.opacity = '1';
    btn.style.cursor = 'pointer';
    summary.innerHTML = '<strong style="color:#111827;">' + sel.length + ' '
        + (sel.length === 1 ? 'bill' : 'bills') + ' · Rs. ' + fuMoney(total) + '</strong> in one message';
    btn.textContent = sel.length === 1
        ? '📱 Send reminder'
        : '📱 Send one reminder for ' + sel.length + ' bills';

    document.getElementById('fuBillsPreview').textContent = fuPreviewText(sel);
}

function fuPreviewText(sel) {
    var row = fuBillsState.row;

    if (sel.length > 1) {
        var total = sel.reduce(function (sum, b) { return sum + b.amount; }, 0);
        var numbers = sel.map(function (b) { return b.order_number; }).join(', ');
        return fuMultiReminderText(fuFirstName(row.customer_name), numbers, total);
    }

    // A single tick on the row that was clicked is the untouched original send —
    // on day 1 that is still the delivery confirmation, not a payment chase.
    if (sel[0].id === row.id && row.template === FU_TEMPLATE_DAY_ONE) {
        return fuBankDetailsMessage(row);
    }

    return fuSingleReminderText(row.customer_name, sel[0].order_number, sel[0].amount)
        + '\n\n[the invoice image is attached to this one]';
}

function fuCloseBills() {
    document.getElementById('fuBillsModal').style.display = 'none';
    if (fuBillsState && fuBillsState.btn) {
        // Nothing was sent, so the row stays actionable.
        fuBillsState.btn.disabled = false;
    }
    fuBillsState = null;
}

// Grey out another row on the board that this message also covered, so the
// operator cannot chase the same bill twice in one day from two rows.
function fuGreyRow(orderId, label) {
    var rowEl = document.getElementById('fu-row-' + orderId);
    if (!rowEl) return;
    var btn = rowEl.querySelector('button[data-row]');
    if (!btn) return;
    btn.disabled = true;
    btn.style.backgroundColor = '#cbd5e1';
    btn.textContent = label || '✓ Reminded today';
    btn.title = 'Already reminded today — try again tomorrow';
}

function fuSendSelectedBills() {
    if (!fuBillsState) return;

    var sel = fuSelectedBills();
    if (sel.length === 0) return;

    dcPaneTouched('messages');

    var row = fuBillsState.row;
    var btn = fuBillsState.btn;

    // Exactly the row that was clicked, and nothing else: hand back to the
    // original single-invoice path untouched — same template ladder, same
    // 422-to-day-one fallback, same manual wa.me fallback, same stamping.
    if (sel.length === 1 && sel[0].id === row.id) {
        fuCloseBills();
        btn.disabled = true;
        fuStatus(btn, 'checking…', '#6b7280');
        fuPrecheck(row)
            .then(function (pre) {
                if (pre && pre.stale) {
                    var proceed = confirm(
                        '⚠️ This changed after the page was loaded\n\n'
                        + row.order_number + ' — ' + row.customer_name + '\n\n'
                        + pre.message + '\n\nSend the reminder anyway?'
                    );
                    if (!proceed) {
                        btn.disabled = false;
                        fuStatus(btn, 'not sent — refresh the page', '#b91c1c');
                        return;
                    }
                }
                return fuDispatch(row, btn);
            })
            .catch(function () { return fuDispatch(row, btn); });
        return;
    }

    var sendBtn = document.getElementById('fuBillsSendBtn');
    sendBtn.disabled = true;
    sendBtn.style.opacity = '0.6';
    sendBtn.textContent = '⏳ Checking…';

    // Re-ask the server about every ticked bill at the moment of sending. The
    // modal may have been open for a while, and this is the check that decides
    // whether real money gets demanded twice. Fails open, as everywhere else.
    Promise.all(sel.map(function (b) {
        return fuPrecheck({ id: b.id }).then(function (pre) { return { bill: b, pre: pre }; });
    }))
    .then(function (results) {
        var stale = results.filter(function (r) { return r.pre && r.pre.stale; });

        if (stale.length > 0) {
            var lines = stale.map(function (r) { return '• ' + r.bill.order_number + ' — ' + r.pre.message; }).join('\n');
            var proceed = confirm(
                '⚠️ Some of these changed after the page was loaded\n\n'
                + row.customer_name + '\n\n' + lines
                + '\n\nSend the reminder anyway?'
            );
            if (!proceed) {
                sendBtn.disabled = false;
                sendBtn.style.opacity = '1';
                fuUpdateBillsFooter();
                return;
            }
        }

        return fuDispatchBills(row, btn, sel, sendBtn);
    })
    .catch(function () {
        return fuDispatchBills(row, btn, sel, sendBtn);
    });
}

function fuDispatchBills(row, btn, sel, sendBtn) {
    var numbers = sel.map(function (b) { return b.order_number; });
    var ids = sel.map(function (b) { return b.id; });
    var total = sel.reduce(function (sum, b) { return sum + b.amount; }, 0);
    var isMulti = sel.length > 1;

    var payload = {
        phone: row.customer_phone,
        template_name: isMulti ? FU_TEMPLATE_FOLLOW_UP_MULTI : FU_TEMPLATE_FOLLOW_UP,
        body_params: isMulti
            ? [fuFirstName(row.customer_name), numbers.join(', '), fuMoney(total)]
            : [row.customer_name, numbers[0], fuMoney(total)]
    };

    if (isMulti) {
        // ⚠⚠ NO order_id on the multi template. order_id triggers the
        // invoice-image attach and payment_reminder_multiples declares no media
        // header — Meta rejects a header component on a template that doesn't
        // have one, which fails the whole send. The orders it covers ride in
        // related_order_numbers instead, which stamps history without touching
        // the header (see WhatsAppService::saveOutboundMessage).
        payload.related_order_numbers = numbers;
        payload.related_order_number = numbers[0];
    } else {
        // A single bill that is NOT the clicked row: the invoice-bearing
        // reminder, with its own invoice image.
        payload.order_id = ids[0];
    }

    sendBtn.textContent = '⏳ Sending…';

    return fetch('/messages/send-template', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': fuCsrf(),
            'Accept': 'application/json'
        },
        body: JSON.stringify(payload)
    })
    .then(function (resp) {
        return resp.json().catch(function () { return {}; }).then(function (data) {
            return { ok: resp.ok && data && data.success, status: resp.status, data: data || {} };
        });
    })
    .then(function (res) {
        // payment_reminder_single is refused 422 when no invoice PNG was ever
        // captured for that order — the image is made in the BROWSER on the
        // invoice page, so for an older bill it may simply not exist. Retry as
        // the multi template, which carries no image and still reads as a
        // payment chase (the day-one confirmation would be the wrong message
        // for a bill this old).
        if (!res.ok && res.status === 422 && !isMulti) {
            var retry = {
                phone: row.customer_phone,
                template_name: FU_TEMPLATE_FOLLOW_UP_MULTI,
                body_params: [fuFirstName(row.customer_name), numbers[0], fuMoney(total)],
                related_order_number: numbers[0]
            };
            return fetch('/messages/send-template', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': fuCsrf(),
                    'Accept': 'application/json'
                },
                body: JSON.stringify(retry)
            })
            .then(function (r2) {
                return r2.json().catch(function () { return {}; }).then(function (d2) {
                    return { ok: r2.ok && d2 && d2.success, status: r2.status, data: d2 || {}, noImage: true };
                });
            });
        }
        return res;
    })
    .then(function (res) {
        if (!res.ok) {
            sendBtn.disabled = false;
            sendBtn.style.opacity = '1';
            fuUpdateBillsFooter();
            alert('Could not send the reminder.\n\n'
                + (res.data.message || ('HTTP ' + res.status))
                + '\n\nNothing was sent and nothing was marked as reminded.');
            return;
        }

        return fuMarkRemindedBills(row, btn, ids, sel, res.noImage);
    });
}

// Stamp EVERY order the message named. Without this the bills that were not the
// primary still read "never reminded" and get chased again tomorrow for money
// that was already asked for today.
function fuMarkRemindedBills(row, btn, ids, sel, noImage) {
    return fetch('{{ route("fin.employee.mark-online-message-sent", ["orderId" => "__ID__"]) }}'.replace('__ID__', row.id), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': fuCsrf(),
            'Accept': 'application/json'
        },
        body: JSON.stringify({ order_ids: ids })
    })
    .then(function (resp) { return resp.json(); })
    .then(function (data) {
        fuCloseBills();

        var note = sel.length > 1
            ? 'sent — ' + sel.length + ' bills'
            : 'sent' + (noImage ? ' (no invoice image)' : '');

        if (!data || !data.success) {
            fuStatus(btn, 'Sent, but not recorded — refresh', '#b91c1c');
            return;
        }

        // Every ticked bill that is also a row on this board goes grey, not just
        // the one that was clicked.
        sel.forEach(function (b) {
            if (b.id === row.id) return;
            if (b.in_window) fuGreyRow(b.id, '✓ Reminded today');
        });

        if (ids.indexOf(row.id) !== -1) {
            btn.disabled = true;
            btn.style.backgroundColor = '#cbd5e1';
            btn.textContent = '✓ Reminded today';
            btn.title = 'Already reminded today — try again tomorrow';
            fuStatus(btn, note, '#166534');
        } else {
            // The clicked row was deliberately unticked, so it stays chaseable.
            btn.disabled = false;
            fuStatus(btn, 'other bills reminded — this one not sent', '#92400e');
        }
    })
    .catch(function () {
        fuCloseBills();
        fuStatus(btn, 'Sent, but not recorded — refresh', '#b91c1c');
    });
}

// ⭐ Show the 🏦 bank select only while a BANK account is chosen, and never leave a
// stale bank id behind on a cash source — a bank tag on a cash row is drift in the
// opposite direction (it credits a bank that never moved).
function petrolSourceChanged(requestId) {
    var sel  = document.getElementById('petrol-pay-src-' + requestId);
    var wrap = document.getElementById('petrol-bank-wrap-' + requestId);
    var bank = document.getElementById('petrol-pay-bank-' + requestId);
    if (!sel || !wrap) return;
    var opt = sel.options[sel.selectedIndex];
    var isOnline = opt && opt.getAttribute('data-online') === '1';
    wrap.style.display = isOnline ? 'flex' : 'none';
    if (!isOnline && bank) bank.value = '';
}

// ── Live panes: silent background refresh (Sep-2026) ───────────────────────
//
// REPLACES the Aug-2026 "Refresh page" bar. That bar asked the operator to do
// something the page can do for itself, and the reload it triggered cost
// ~1,130ms of SQL and threw away scroll position, every open group and any
// half-finished approval. (It also never once fired: its endpoint was swallowed
// by the `/{id}` route registered above it — see routes/web.php.)
//
// Now: poll the same handful of cheap COUNTs (~12ms) and, only when they move,
// fetch and swap the two pane BODIES in place.
//
// ⭐⭐ THE ONE RULE: this may only ever touch #dc-body-requests and
// #dc-body-messages. Everything below them — the rider closings, their deposit
// and settlement forms, the invoice tables, where the actual money is approved —
// is never in the response and never re-rendered. A refresh cannot disturb work
// in progress down there because it cannot reach it.
//
// ⭐ And inside those two panes it still refuses to swap while the operator is
// working: a modal open, a native dialog up, focus or a text selection inside
// the pane, a half-chosen pay-source or bank <select>, or a recent local action.
// A deferred swap is kept and retried, never dropped.
(function () {
    var state = document.getElementById('dc-refresh-state');
    if (!state) return;

    var baseline;
    try {
        baseline = JSON.parse(state.dataset.baseline);
    } catch (e) {
        return; // No baseline, nothing to compare against.
    }

    var RIDER    = state.dataset.rider || 'all';
    var POLL_MS  = 45000;   // how often to ask "did anything change?"
    var RETRY_MS = 7000;    // a deferred swap looks again sooner than a poll
    var QUIET_MS = 20000;   // hands off a pane this long after a local action

    var PANES = {
        requests: { bodyId: 'dc-body-requests', freshId: 'dc-fresh-requests', key: 'requests_html' },
        messages: { bodyId: 'dc-body-messages', freshId: 'dc-fresh-messages', key: 'messages_html' }
    };

    // A fetched payload waiting for a safe moment. Held, never discarded: if the
    // operator is busy for five minutes, the swap happens when they stop.
    //
    // ⚠ `pendingDone` tracks which panes of THIS payload are already swapped, and
    // lives here rather than on the payload object. A partial swap must not be
    // re-applied on the retry (it would throw away whatever the operator did in
    // the pane meanwhile), and writing that flag onto the response would quietly
    // make the server's data carry client state.
    var pending = null;
    var pendingDone = {};
    var retryTimer = null;

    // ── Is it safe to touch anything at all? ───────────────────────────────
    // A native confirm()/prompt() blocks every timer while it is up, so it needs
    // no guard of its own — JS simply cannot run underneath it.
    function anyModalOpen() {
        var ids = ['fuBillsModal', 'proofModal', 'fmModal'];
        for (var i = 0; i < ids.length; i++) {
            var el = document.getElementById(ids[i]);
            if (el && el.style.display && el.style.display !== 'none') return true;
        }
        var drawer = document.getElementById('waChatOverlay');
        if (drawer && drawer.classList.contains('open')) return true;
        return false;
    }

    // ── Is it safe to touch THIS pane? ─────────────────────────────────────
    function paneBusy(body) {
        // Something in it has focus — they are tabbing, typing or picking.
        var active = document.activeElement;
        if (active && active !== document.body && body.contains(active)) return true;

        // They are selecting text inside it (reading a request out to someone,
        // copying an order number).
        var sel = window.getSelection && window.getSelection();
        if (sel && !sel.isCollapsed && sel.rangeCount > 0) {
            var node = sel.getRangeAt(0).commonAncestorContainer;
            if (node && body.contains(node.nodeType === 1 ? node : node.parentNode)) return true;
        }

        // ⭐⭐ A half-made choice. The pay-source and bank <select>s next to each
        // approve button hold the operator's decision and NOTHING else does — it
        // lives nowhere but that DOM node until Approve is pressed. Swapping the
        // pane would silently reset a chosen bank to the default, and the next
        // click would then post the money against the wrong account. Any select
        // moved off its rendered default freezes this pane.
        var selects = body.querySelectorAll('select');
        for (var i = 0; i < selects.length; i++) {
            if (dcSelectChanged(selects[i])) return true;
        }

        return false;
    }

    // "Changed" means: differs from the option the server marked selected. Read
    // off the DOM's own defaultSelected rather than a remembered snapshot, so it
    // stays correct across any number of swaps.
    function dcSelectChanged(sel) {
        for (var i = 0; i < sel.options.length; i++) {
            if (sel.options[i].defaultSelected) {
                return sel.value !== sel.options[i].value;
            }
        }
        // No explicit default: the browser picks the first option, so anything
        // else is a deliberate choice.
        return sel.selectedIndex > 0;
    }

    function quietFor(pane) {
        var until = (window.dcPaneQuietUntil || {})[pane] || 0;
        return Date.now() < until;
    }

    // ── Preserve what the operator set up ──────────────────────────────────
    // Which groups they collapsed, and where they had scrolled to. Only the
    // `hidden` class is carried over, and only for ids that still exist.
    function snapshot(body) {
        var hidden = {};
        var nodes = body.querySelectorAll('[id]');
        for (var i = 0; i < nodes.length; i++) {
            hidden[nodes[i].id] = nodes[i].classList.contains('hidden');
        }
        return { hidden: hidden, scrollTop: body.scrollTop };
    }

    function restore(body, snap) {
        Object.keys(snap.hidden).forEach(function (id) {
            var el = document.getElementById(id);
            if (!el || !body.contains(el)) return;
            el.classList.toggle('hidden', snap.hidden[id]);
        });
        body.scrollTop = snap.scrollTop;
    }

    function flash(freshId, when) {
        var el = document.getElementById(freshId);
        if (!el) return;
        el.textContent = 'updated ' + when;
        el.classList.add('dc-fresh-on');
        clearTimeout(el._dcFade);
        el._dcFade = setTimeout(function () { el.classList.remove('dc-fresh-on'); }, 6000);
    }

    function setBadges(b) {
        if (!b) return;
        // Each badge is rendered only when non-zero, so a badge that does not
        // exist yet is left alone rather than invented — the pane header would
        // otherwise grow a "0" chip the server never shows.
        var map = [
            ['[data-dc-badge="petrol"]', b.petrol_count, '⛽ ' + b.petrol_count],
            ['[data-dc-badge="maint"]',  b.maint_count,  '🔧 ' + b.maint_count],
            ['[data-dc-badge="reqamt"]', b.req_amount,   'Rs. ' + Number(b.req_amount).toLocaleString()],
            ['[data-dc-badge="chase"]',  b.chase_count,  b.chase_count + ' to chase'],
            ['[data-dc-badge="proof"]',  b.proof_count,  b.proof_count + ' proof in']
        ];
        map.forEach(function (row) {
            document.querySelectorAll(row[0]).forEach(function (el) {
                if (!row[1]) { el.classList.add('hidden'); return; }
                el.classList.remove('hidden');
                el.textContent = row[2];
            });
        });
    }

    // ── Apply a payload, pane by pane ──────────────────────────────────────
    // Returns true when BOTH panes have been dealt with, so the payload can be
    // dropped. A pane that was busy leaves the payload pending for a retry.
    function apply(data) {
        if (anyModalOpen()) return false;

        var when = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        var allDone = true;

        Object.keys(PANES).forEach(function (pane) {
            var cfg  = PANES[pane];
            var body = document.getElementById(cfg.bodyId);
            var html = data[cfg.key];

            // A pane the server did not send is not a pane we are waiting on.
            if (!body || typeof html !== 'string') return;
            if (pendingDone[pane]) return;   // already swapped on an earlier try

            if (paneBusy(body) || quietFor(pane)) { allDone = false; return; }

            var snap = snapshot(body);
            body.innerHTML = html;
            restore(body, snap);

            pendingDone[pane] = true;
            flash(cfg.freshId, when);
        });

        // Badges only once both panes carry the same data, so a header count can
        // never describe rows that are not on screen yet.
        if (allDone) setBadges(data.badges);

        return allDone;
    }

    function scheduleRetry() {
        clearTimeout(retryTimer);
        retryTimer = setTimeout(function () {
            if (!pending) return;
            if (apply(pending)) {
                pending = null;
            } else {
                scheduleRetry();
            }
        }, RETRY_MS);
    }

    function changed(counts) {
        if (!counts) return false;
        return Object.keys(counts).some(function (k) {
            return (counts[k] || 0) !== (baseline[k] || 0);
        });
    }

    function fetchPanels() {
        fetch('{{ route("fin.employee.panels-refresh") }}?rider=' + encodeURIComponent(RIDER),
              { headers: { 'Accept': 'application/json' } })
            .then(function (resp) { return resp.json(); })
            .then(function (data) {
                if (!data || !data.success) return;

                // Re-baseline the moment the fresh markup is IN HAND, not when it
                // is shown. Otherwise a pane deferred for a busy operator would
                // re-report the same change on every poll for as long as they
                // stayed busy, and each poll would refetch the panels.
                if (data.heartbeat) baseline = data.heartbeat;

                pending = data;
                pendingDone = {};   // a new payload: every pane is owed a swap again
                if (apply(pending)) {
                    pending = null;
                } else {
                    scheduleRetry();
                }
            })
            .catch(function () { /* offline or a blip — the next poll retries */ });
    }

    function poll() {
        // Already holding markup nobody could accept yet: don't pile up another.
        if (pending) { scheduleRetry(); return; }

        fetch('{{ route("fin.employee.followup-heartbeat") }}', { headers: { 'Accept': 'application/json' } })
            .then(function (resp) { return resp.json(); })
            .then(function (data) {
                if (!data || !data.success || !data.counts) return;
                // ⭐ Any movement, in either direction. The old bar deliberately
                // reported growth only, because it was asking a human to act on
                // the news. Nobody is being asked anything now, so a proof that
                // was un-matched or an approval that was reversed is just as
                // worth showing as a new one.
                if (changed(data.counts)) fetchPanels();
            })
            .catch(function () { /* offline or a blip — try again next tick */ });
    }

    setInterval(poll, POLL_MS);

    // Coming back to the tab is the moment the page is most likely to be stale,
    // and the moment the operator is about to trust what it says.
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) poll();
    });
})();

// Called by every action that changes a pane, BEFORE its request goes out.
// Holds the background refresh off that pane for a few seconds so it cannot
// swap the row out from under a click, and so the operator actually sees their
// own "✅ Approved" / "✓ Reminded today" confirmation before the list rebuilds.
function dcPaneTouched(pane) {
    window.dcPaneQuietUntil = window.dcPaneQuietUntil || {};
    window.dcPaneQuietUntil[pane] = Date.now() + 20000;
}

function approvePetrolRequest(requestId, level) {
    // Read the selected payment source for this request
    var paymentSourceSelect = document.getElementById('petrol-pay-src-' + requestId);
    var paymentSourceAccountId = paymentSourceSelect ? paymentSourceSelect.value : null;

    // ⭐ An ONLINE source must say WHICH bank it left from, or BankBalanceService
    // never sees the money and the per-bank split drifts by this amount. Checked
    // BEFORE the confirm so the manager is not asked twice.
    var srcOpt = paymentSourceSelect ? paymentSourceSelect.options[paymentSourceSelect.selectedIndex] : null;
    var srcIsOnline = srcOpt && srcOpt.getAttribute('data-online') === '1';
    var bankSelect = document.getElementById('petrol-pay-bank-' + requestId);
    var bankId = bankSelect ? bankSelect.value : '';
    if (srcIsOnline && bankSelect && !bankId) {
        alert('This is paid from an online account — choose which bank it leaves from first.');
        if (bankSelect.focus) bankSelect.focus();
        return;
    }

    if (!confirm('Approve this request?')) return;

    // Hands off this pane for a few seconds: the background refresh must not
    // swap the row out from under this click, and the operator should see their
    // own "✅ Approved" before the list rebuilds without it.
    dcPaneTouched('requests');

    var btn = event.target;
    btn.disabled = true;
    btn.textContent = 'Approving...';

    var payload = { level: level, comments: 'Approved from daily closing' };
    if (paymentSourceAccountId) {
        payload.payment_source_account_id = parseInt(paymentSourceAccountId);
    }
    if (srcIsOnline && bankId) {
        payload.receiving_account_id = parseInt(bankId, 10);
    }

    fetch('/requests/' + requestId + '/approve', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Accept': 'application/json'
        },
        body: JSON.stringify(payload)
    })
    .then(function(resp) { return resp.json(); })
    .then(function(data) {
        if (data.success) {
            var row = document.getElementById('petrol-req-' + requestId);
            if (row) {
                row.style.transition = 'all 0.3s ease';
                row.style.backgroundColor = '#f0fdf4';
                row.style.borderColor = '#86efac';
                row.innerHTML = '<div class="px-4 py-3 flex items-center justify-between"><span class="text-xs font-bold text-green-700">✅ Approved</span><span class="text-xs text-gray-400">' + (data.request_status || '') + '</span></div>';
            }
        } else {
            alert(data.message || 'Failed to approve');
            btn.disabled = false;
            btn.textContent = '✅ Approve';
        }
    })
    .catch(function(err) {
        console.error('Petrol approve error:', err);
        alert('Error approving petrol request');
        btn.disabled = false;
        btn.textContent = '✅ Approve';
    });
}

function rejectPetrolRequest(requestId, level) {
    var reason = prompt('Reason for rejecting this request:');
    if (!reason) return;

    dcPaneTouched('requests');

    var btn = event.target;
    btn.disabled = true;
    btn.textContent = 'Rejecting...';
    
    fetch('/requests/' + requestId + '/reject', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Accept': 'application/json'
        },
        body: JSON.stringify({ level: level, comments: reason })
    })
    .then(function(resp) { return resp.json(); })
    .then(function(data) {
        if (data.success) {
            var row = document.getElementById('petrol-req-' + requestId);
            if (row) {
                row.style.transition = 'all 0.3s ease';
                row.style.backgroundColor = '#fef2f2';
                row.style.borderColor = '#fca5a5';
                row.innerHTML = '<div class="px-4 py-3 flex items-center justify-between"><span class="text-xs font-bold text-red-700">❌ Rejected</span><span class="text-xs text-gray-400">' + reason + '</span></div>';
            }
        } else {
            alert(data.message || 'Failed to reject');
            btn.disabled = false;
            btn.textContent = '❌ Reject';
        }
    })
    .catch(function(err) {
        console.error('Petrol reject error:', err);
        alert('Error rejecting petrol request');
        btn.disabled = false;
        btn.textContent = '❌ Reject';
    });
}

// ── Split-pane maximize (Aug-2026) ─────────────────────────────────────────
// Toggles a class on the pane ITSELF. The pane is never re-parented and its
// contents are never re-rendered, so every button, <select> and inline handler
// inside it — approve/reject, the bank picker, Send, Month view — keeps working
// exactly as it does unmaximized. Esc or a click on the scrim restores.
function dcToggleMax(paneId, btn) {
    var pane  = document.getElementById(paneId);
    var scrim = document.getElementById('dc-scrim');
    if (!pane) return;
    var turnOn = !pane.classList.contains('dc-maxed');

    // Only one pane maximized at a time — restore any other first.
    document.querySelectorAll('.dc-pane.dc-maxed').forEach(function (p) {
        p.classList.remove('dc-maxed');
        var b = p.querySelector('.dc-max-btn');
        if (b) b.textContent = '⛶ Maximize';
    });

    if (turnOn) {
        pane.classList.add('dc-maxed');
        if (btn) btn.textContent = '✕ Restore';
    }
    if (scrim) scrim.classList.toggle('dc-on', turnOn);
    document.body.classList.toggle('dc-locked', turnOn);
}

function dcRestoreMax() {
    var maxed = document.querySelector('.dc-pane.dc-maxed');
    if (maxed) dcToggleMax(maxed.id);
}

// Esc restores a maximized pane — but only when nothing is open on top of it,
// so it never steals the key from the payment-proof viewer or the fuel month
// view (both of which deliberately render ABOVE a maximized pane).
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var proof = document.getElementById('proofModal');
    var fm    = document.getElementById('fmModal');
    if (proof && proof.style.display === 'flex') return;
    if (fm && fm.style.display === 'flex') return;
    // The chat drawer owns Esc while it is open. Two checks, because the two
    // listeners can run in either order: the flag catches "the drawer already
    // closed on this same keypress", the open-test catches the reverse order.
    if (e.nfHandledByChatDrawer) return;
    if (typeof waChatDrawerIsOpen === 'function' && waChatDrawerIsOpen()) return;
    dcRestoreMax();
});

// Glance chip → the section it summarises. Restores a maximized pane first,
// otherwise the scroll happens behind the overlay and looks like nothing did.
// Sep-2026 — collapse an individual rider closing card. Same interaction the
// Payment Follow-up groups and the Petrol/Maintenance panels use: a plain
// class toggle, no persistence, nothing sent to the server.
function dcToggleRider(accountId) {
    var body = document.getElementById('dc-rider-body-' + accountId);
    if (!body) { return; }
    var collapsed = body.classList.toggle('hidden');
    var chev = document.getElementById('dc-rider-chev-' + accountId);
    if (chev) { chev.innerHTML = collapsed ? '&#9656;' : '&#9662;'; }
}

function dcJump(id) {
    dcRestoreMax();
    var el = document.getElementById(id);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>

<!-- CSS for animations -->
<style>
.stat-card {
    cursor: pointer;
}
.stat-card:active {
    transform: scale(0.98);
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
.animate-fadeIn {
    animation: fadeIn 0.3s ease-in-out;
}
</style>

{{-- =====================================================================
     ⛽ Rider fuel MONTH VIEW popup (Jul-2026)

     Lets the approver see the rider's whole month — meter km per day, every
     approved/pending claim, duplicate flags, service state — before pressing
     Approve on the request in front of them. Same data as the riders-map ⛽
     Fleet tab (same endpoint, fresh=1 so a claim filed seconds ago shows).

     Shell is INLINE-STYLED on purpose: the purged utility classes (inset-0,
     max-h-*, flex) render class-based modals top-left and unscrollable on
     this stack — see the Metronic legacy-class note.
===================================================================== --}}
<div id="fmModal" onclick="if (event.target === this) fmClose()"
     style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; z-index:4000;
            background:rgba(0,0,0,.55); align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:10px; width:min(96vw, 880px); max-height:88vh;
                display:flex; flex-direction:column; overflow:hidden; box-shadow:0 12px 44px rgba(0,0,0,.35);">
        <div style="display:flex; align-items:center; gap:10px; padding:12px 16px; border-bottom:1px solid #e5e7eb; background:#f9fafb;">
            <span style="font-size:16px;">⛽</span>
            <b id="fmTitle" style="font-size:14px; color:#111827;">Fuel month</b>
            <span id="fmSub" style="font-size:12px; color:#6b7280;"></span>
            <button onclick="fmClose()" title="Close"
                    style="margin-left:auto; border:none; background:none; font-size:22px; color:#9ca3af; cursor:pointer; line-height:1;">&times;</button>
        </div>
        <div id="fmBody" style="overflow-y:auto; padding:6px 0;"></div>
    </div>
</div>

<div id="fmLightbox" onclick="this.style.display='none'"
     style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; z-index:4100;
            background:rgba(0,0,0,.78); align-items:center; justify-content:center; cursor:zoom-out;">
    <img id="fmLightboxImg" src="" alt="Receipt"
         style="max-width:92vw; max-height:88vh; border-radius:8px; background:#fff;">
</div>

<script>
let fmApproval = null;   // what this user may approve + payment sources

function fmOpen(riderId, riderName) {
    const modal = document.getElementById('fmModal');
    window.fmRiderId = riderId; window.fmRiderName = riderName;
    document.getElementById('fmTitle').textContent = riderName + ' — fuel this month';
    document.getElementById('fmSub').textContent = '';
    document.getElementById('fmBody').innerHTML =
        '<div style="padding:26px; text-align:center; color:#9ca3af; font-size:13px;">Loading…</div>';
    modal.style.display = 'flex';

    const month = new Date().toISOString().substring(0, 7);
    fetch('/orders/riders-map/fleet/rider?month=' + month + '&rider_id=' + riderId + '&fresh=1')
        .then(r => r.status === 403 ? Promise.reject(new Error('403')) : r.json())
        .then(res => {
            if (!res.success || !res.rider) throw new Error(res.message || 'Failed');
            fmApproval = res.approval || null;
            fmRender(res.rider);
        })
        .catch(err => {
            document.getElementById('fmBody').innerHTML =
                '<div style="padding:26px; text-align:center; color:#b91c1c; font-size:13px;">' +
                (err.message === '403' ? 'You do not have permission to see fleet costs.'
                                       : 'Could not load this rider\'s month.') + '</div>';
        });
}

function fmClose() { document.getElementById('fmModal').style.display = 'none'; }

/**
 * Approve / reject from inside the month view. Uses the SAME endpoint, level and
 * payload as the panel behind this popup, so money is booked identically. The
 * panel row is greyed out too, so the approver never acts on it twice.
 */
// ⭐ Show the 🏦 bank select only while a BANK account is chosen; clear it otherwise
// so a cash-funded approval can never carry a stale bank tag.
function fmSrcChanged(id) {
    const sel = document.getElementById('fmSrc' + id);
    const bankSel = document.getElementById('fmBank' + id);
    if (!sel || !bankSel) return;
    const opt = sel.options[sel.selectedIndex];
    const isOnline = opt && opt.getAttribute('data-online') === '1';
    bankSel.style.display = isOnline ? '' : 'none';
    if (!isOnline) bankSel.value = '';
}

function fmAct(id, level, action) {
    // 🏦 Asked BEFORE the confirm, so the approver is not made to answer twice.
    if (action === 'approve') {
        const src = document.getElementById('fmSrc' + id);
        const opt = src ? src.options[src.selectedIndex] : null;
        const bankSel = document.getElementById('fmBank' + id);
        if (opt && opt.getAttribute('data-online') === '1' && bankSel && !bankSel.value) {
            alert('This is paid from an online account — choose which bank it leaves from first.');
            if (bankSel.focus) bankSel.focus();
            return;
        }
    }
    if (action === 'approve') {
        if (!confirm('Approve this claim?')) return;
    }
    let comments = 'Approved from month view';
    if (action === 'reject') {
        const reason = window.prompt('Why is this being rejected? (the rider sees this)');
        if (reason === null) return;
        if (!String(reason).trim()) { alert('Please give a short reason.'); return; }
        comments = reason.trim();
    }

    const payload = {level: level, comments: comments};
    if (action === 'approve') {
        const sel = document.getElementById('fmSrc' + id);
        if (sel && sel.value) payload.payment_source_account_id = parseInt(sel.value, 10);
        // 🏦 Already demanded above; only a bank source may carry the tag, so a
        // cash approval never sends one.
        const opt = sel ? sel.options[sel.selectedIndex] : null;
        const bankSel = document.getElementById('fmBank' + id);
        if (opt && opt.getAttribute('data-online') === '1' && bankSel && bankSel.value) {
            payload.receiving_account_id = parseInt(bankSel.value, 10);
        }
    }

    const box = document.getElementById('fmAct' + id);
    if (box) box.innerHTML = '<span style="color:#6b7280; font-size:11px;">working…</span>';

    fetch('/requests/' + id + '/' + action, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json', 'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) throw new Error(res.message || 'Failed');
        const row = document.getElementById('fmClaim' + id);
        if (row) {
            row.style.background = action === 'approve' ? '#f0fdf4' : '#fef2f2';
            row.style.borderColor = action === 'approve' ? '#86efac' : '#fecaca';
            row.innerHTML = '<b style="color:' + (action === 'approve' ? '#15803d' : '#b91c1c') + ';">' +
                (action === 'approve' ? '✅ Approved' : '❌ Rejected') + '</b>';
        }
        // The same request is listed in the panel behind — mark it done there too
        // so nobody tries to approve it a second time from the other surface.
        const panelRow = document.getElementById('petrol-req-' + id) || document.getElementById('maint-req-' + id);
        if (panelRow) {
            panelRow.style.opacity = '0.55';
            panelRow.innerHTML = '<div class="px-4 py-3 text-xs font-bold" style="color:' +
                (action === 'approve' ? '#15803d' : '#b91c1c') + ';">' +
                (action === 'approve' ? '✅ Approved' : '❌ Rejected') + ' from month view</div>';
        }
    })
    .catch(err => {
        alert(err.message || 'Could not complete that.');
        if (window.fmRiderId) fmOpen(window.fmRiderId, window.fmRiderName || '');
    });
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') fmClose(); });

function fmRender(r) {
    const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    const num = n => Number(n ?? 0).toLocaleString('en-PK', {maximumFractionDigits: 0});
    const dt = d => { const x = new Date(String(d).substring(0,10) + 'T12:00:00');
                      return isNaN(x) ? d : x.toLocaleDateString('en-GB', {weekday:'short', day:'numeric', month:'short'}); };
    const flagText = {
        double_tap: 'same amount filed minutes apart — likely a double tap',
        flat_on_metered_day: 'cash claim on a day the meter already paid for',
        second_same_day: 'second cash claim of the day'
    };

    document.getElementById('fmSub').textContent =
        (r.bike === 'company' ? '🏢 company bike' : r.bike === 'own' ? '👤 own bike' : '❓ bike unclassified');

    // month roll-up across what the popup shows (approved + pending)
    let approvedRs = 0, pendingRs = 0, flags = 0;
    (r.days || []).forEach(d => (d.claims || []).forEach(c => {
        if (c.kind !== 'fuel') return;
        if (c.status === 'approved') approvedRs += c.amount; else pendingRs += c.amount;
        if (c.flag) flags++;
    }));

    let html = '<div style="display:flex; gap:14px; flex-wrap:wrap; padding:10px 16px; font-size:12.5px; color:#374151; border-bottom:1px solid #f1f5f9;">' +
        '<span>Fuel approved: <b>Rs ' + num(approvedRs) + '</b></span>' +
        '<span>Pending: <b>Rs ' + num(pendingRs) + '</b></span>' +
        (flags ? '<span style="color:#b45309;">⚠ <b>' + flags + '</b> flagged claim' + (flags === 1 ? '' : 's') + '</span>' : '') +
        /* ⚠ A TIME-based job can be the overdue one now (29-Sep-2026): no km figure, so the server's own phrase. */
        (r.service && r.service.state === 'overdue' ? '<span style="color:#b91c1c;">🔴 service '
            + ((r.service.due_in_km === null || r.service.due_in_km === undefined) && r.service.due_text
                ? esc(r.service.due_text) : 'overdue ' + num(Math.abs(r.service.due_in_km)) + ' km') + '</span>' : '') +
        '</div>';

    (r.days || []).forEach(d => {
        let km;
        if (d.work_km !== null && d.work_km !== undefined) {
            km = d.meter_start + ' → ' + d.meter_end + ' · <b>' + d.work_km + ' km</b>' +
                 (d.offduty_km ? ' · +' + d.offduty_km + ' km off-duty' + (d.offduty_since ? ' since ' + dt(d.offduty_since) : '') : '') +
                 // Same stretch the Bikes screen shows. Deliberately NOT called
                 // off-duty: it spans a day he worked with no meter, so part of it
                 // is work and it cannot be split. Approvers see the same words here.
                 (d.unattributed_km
                    ? ' · <span style="color:#b45309;">+' + d.unattributed_km + ' km unattributed'
                      + (d.offduty_since ? ' since ' + dt(d.offduty_since) : '') + '</span>'
                    : '');
        } else if (d.meter_start !== null || d.meter_end !== null) {
            km = '<span style="color:#9ca3af;">meter reading unusable</span>';
        } else {
            km = '<span style="color:#9ca3af;">no meter reading</span>';
        }

        let claims = '';
        (d.claims || []).forEach(c => {
            const photo = c.photo
                ? '<img src="' + c.photo + '" alt="" onclick="document.getElementById(\'fmLightboxImg\').src=this.src; document.getElementById(\'fmLightbox\').style.display=\'flex\';"' +
                  ' style="width:34px; height:34px; object-fit:cover; border-radius:5px; border:1px solid #d1d5db; cursor:zoom-in; flex-shrink:0;">'
                : '<div style="width:34px; height:34px; border-radius:5px; border:1px dashed #d1d5db; flex-shrink:0;"></div>';
            const status = c.status === 'approved'
                ? '<span style="background:#dcfce7; color:#15803d; border-radius:999px; padding:1px 8px; font-size:10.5px; font-weight:600;">✓ approved</span>'
                : '<span style="background:#fef3c7; color:#b45309; border-radius:999px; padding:1px 8px; font-size:10.5px; font-weight:600;">⏳ pending</span>';
            const flag = c.flag
                ? ' <span title="' + esc(flagText[c.flag] || '') + '" style="background:#fef3c7; color:#b45309; border-radius:999px; padding:1px 8px; font-size:10.5px; font-weight:600;">⚠ ' + esc(flagText[c.flag] || c.flag) + '</span>'
                : '';
            // Approve / reject in place — this popup is opened FROM the approval
            // queue, so the decision belongs here. Same endpoint/level/payload as
            // the panel behind it.
            let actions = '';
            if (c.status === 'pending' && fmApproval && fmApproval.can_approve
                && c.next_level && fmApproval.levels.indexOf(c.next_level) !== -1) {
                // data-online carries the ONE test for "does this need a bank?" —
                // never an account-code match, which misses QURBANI_ONLINE and any
                // bank account added later.
                const accs = (fmApproval.accounts || []).map(a =>
                    '<option value="' + a.id + '" data-online="' + (a.is_online ? '1' : '0') + '">'
                    + esc(a.display_name || a.name || a.account_name) + '</option>').join('');
                // 🏦 An ONLINE source must say WHICH bank it left from, or the
                // per-bank balances never see this claim. Hidden until one is chosen.
                const fmBanks = (fmApproval.banks || []);
                const bankOpts = fmBanks.map(b =>
                    '<option value="' + b.id + '">' + esc(b.name) + '</option>').join('');
                actions = '<span style="margin-left:auto; display:flex; gap:6px; align-items:center; flex-wrap:wrap;" id="fmAct' + c.id + '">' +
                    (accs ? '<select id="fmSrc' + c.id + '" onchange="fmSrcChanged(' + c.id + ')" style="border:1px solid #d1d5db; border-radius:6px; padding:3px 6px; font-size:11px; max-width:140px;">' + accs + '</select>' : '') +
                    (bankOpts ? '<select id="fmBank' + c.id + '" style="display:none; border:1px solid #d1d5db; border-radius:6px; padding:3px 6px; font-size:11px; max-width:140px;"><option value="">🏦 Which bank?</option>' + bankOpts + '</select>' : '') +
                    '<button onclick="fmAct(' + c.id + ',' + c.next_level + ',\'approve\')" style="background:#16a34a; color:#fff; border:none; border-radius:6px; padding:4px 10px; font-size:11px; font-weight:700; cursor:pointer;">✅ Approve</button>' +
                    '<button onclick="fmAct(' + c.id + ',' + c.next_level + ',\'reject\')" style="background:#dc2626; color:#fff; border:none; border-radius:6px; padding:4px 10px; font-size:11px; font-weight:700; cursor:pointer;">❌ Reject</button>' +
                    '</span>';
            }

            // ⛽🏍 WHICH MACHINE THIS MONEY IS FOR — personal bike / company bike / van,
            // and its plate. Composed server-side (VehicleResolver::machineChip) so this
            // popup, the Requests pane behind it and the phone read identically; a UI that
            // derives "is it a van" itself is how a company van once got drawn as a bike.
            // ⚠ An unstamped claim says so out loud and is NEVER filled in from the day's
            //   machine — the day has two exactly when this matters.
            const machine = c.vehicle_kind
                ? ' <span title="' + esc(c.vehicle_plate_note || 'The machine this claim is filed against') + '"'
                  + ' style="background:#fff; border:1px solid #d1d5db; color:#374151; border-radius:999px; padding:1px 8px; font-size:10.5px; font-weight:600;">'
                  + esc(c.vehicle_icon || '') + ' ' + esc(c.vehicle_text) + '</span>'
                : ' <span title="This claim does not name a machine — claims filed before August 2026 were not stamped"'
                  + ' style="background:#fef3c7; color:#b45309; border-radius:999px; padding:1px 8px; font-size:10.5px; font-weight:600;">❓ machine not recorded</span>';

            const svcLabel = {oil_change: 'regular service', general: 'general service', repair: 'repair', other: 'other'}[c.service_type] || '';
            // "▲ N km since last fill" — the number the approver needs: how far
            // the bike went on the previous tank before this request was made.
            const since = c.km_since_fill
                ? ' <span style="background:#e0e7ff; color:#3730a3; border-radius:999px; padding:1px 8px; font-size:10.5px; font-weight:600;">▲ ' + num(c.km_since_fill) + ' km since last fill</span>'
                : (c.km_since_fill_odd
                    ? ' <span style="background:#fef3c7; color:#b45309; border-radius:999px; padding:1px 8px; font-size:10.5px; font-weight:600;" title="This reading and the previous fill\'s don\'t add up — typo or a different bike">⚠ meter vs last fill doesn\'t add up</span>'
                    : '');

            // Service context, so a maintenance bill is never approved blind.
            // A pending regular service hasn't reset the bike's clock yet, so the
            // overdue figure from the rider card belongs right here, next to the
            // Approve button. Once approved, the frozen snapshot takes over.
            const warnPill = (t, title) =>
                ' <span title="' + esc(title || '') + '" style="background:#fee2e2; color:#b91c1c; border-radius:999px; padding:1px 8px; font-size:10.5px; font-weight:700;">' + t + '</span>';
            const dimPill = (t) =>
                ' <span style="background:#e0e7ff; color:#3730a3; border-radius:999px; padding:1px 8px; font-size:10.5px; font-weight:600;">' + t + '</span>';

            let svcCtx = '';
            if (c.overdue_now_km) {
                svcCtx += warnPill('🔴 bike is ' + num(c.overdue_now_km) + ' km overdue',
                    'The bike has run past its service schedule and this request is not approved yet');
            }
            if (c.service_early_by) {
                svcCtx += warnPill('⏱ serviced ' + num(c.service_early_by) + ' km early',
                    num(c.km_since_service) + ' km since the last service; schedule is ' + num(c.service_interval) + ' km');
            } else if (c.service_late_by) {
                svcCtx += warnPill('⏱ serviced ' + num(c.service_late_by) + ' km overdue',
                    num(c.km_since_service) + ' km since the last service; schedule is ' + num(c.service_interval) + ' km');
            }
            if (c.service_due_km_at_approval !== null && c.service_due_km_at_approval !== undefined) {
                const dk = c.service_due_km_at_approval;
                svcCtx += dk < 0
                    ? warnPill('🔴 done ' + num(-dk) + ' km overdue', 'Recorded when this was approved')
                    : (dk > 25 ? dimPill('⏱ done ' + num(dk) + ' km before due') : dimPill('⏱ done on schedule'));
            }

            // Who typed what, and who signed it off from where.
            let trail = '';
            (c.approval_notes || []).forEach(n => {
                trail += '<div style="width:100%; font-size:11px; color:#3730a3;">💬 ' + esc(n.text) +
                         ' <span style="color:#9ca3af;">— ' + esc(n.by || 'approver') + '</span></div>';
            });
            (c.approval_actions || []).forEach(a => {
                trail += '<div style="width:100%; font-size:10.5px; color:#4b5563;">' +
                         (a.status === 'rejected' ? '❌ Rejected' : '✅ Approved') +
                         (a.level ? ' (L' + a.level + ')' : '') +
                         ' by <b style="color:#111827;">' + esc(a.by || 'unknown') + '</b>' +
                         (a.source ? ' from ' + esc(a.source) : '') + '</div>';
            });

            claims += '<div id="fmClaim' + c.id + '" style="display:flex; align-items:center; gap:9px; margin-top:5px; padding:6px 9px; ' +
                'background:' + (c.flag ? '#fffbeb' : '#f9fafb') + '; border:1px solid ' + (c.flag ? '#fcd34d' : '#e5e7eb') + '; border-radius:7px; font-size:12px; flex-wrap:wrap;">' +
                photo + '<b>Rs ' + num(c.amount) + '</b> ' +
                (c.kind === 'fuel' ? '⛽' : '🔧' + (svcLabel ? ' <span style="color:#6b7280;">' + svcLabel + '</span>' : '')) + ' ' +
                (c.source === 'meter' ? '<span style="color:#6b7280;">' + c.meter_distance + ' km × ' + c.petrol_rate + '</span>' : '<span style="color:#6b7280;">cash claim</span>') +
                (c.meter_at_fill ? ' <span style="color:#6b7280;">· meter ' + num(c.meter_at_fill) + '</span>' : '') +
                (c.litres ? ' <span style="color:#6b7280;">· ' + c.litres + ' L</span>' : '') +
                machine + since + svcCtx +
                ' ' + status + flag + actions + trail + '</div>';
        });

        html += '<div style="padding:8px 16px; border-bottom:1px solid #f1f5f9;">' +
            '<div style="display:flex; gap:10px; font-size:12.5px;">' +
            '<span style="font-weight:600; color:#111827; min-width:92px;">' + dt(d.date) + '</span>' +
            '<span style="color:#6b7280;">' + km + '</span></div>' + claims + '</div>';
    });

    if (!(r.days || []).length) {
        html += '<div style="padding:26px; text-align:center; color:#9ca3af; font-size:13px;">Nothing recorded this month.</div>';
    }

    document.getElementById('fmBody').innerHTML = html;

    // The rows are built as an HTML string, so no change event has fired yet —
    // sync each 🏦 select to the account that came up preselected, or a claim whose
    // default source is a bank would show the Approve button with the bank picker
    // still hidden (and then refuse on click for a reason the approver can't see).
    document.querySelectorAll('#fmBody select[id^="fmSrc"]').forEach(function (sel) {
        fmSrcChanged(sel.id.substring(5));
    });
}
</script>

@endsection
