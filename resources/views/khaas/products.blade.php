@extends('layouts.app')

@section('title', '🌿 Khaas Products & Inventory')

@section('content')
<div class="container-fluid px-6 py-6">
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('khaas.dashboard') }}" class="text-gray-400 hover:text-amber-600 transition-colors">
                <i class="ki-filled ki-arrow-left text-lg"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">📦 {{ $khaasBU->name }} Products & Inventory</h1>
                <p class="text-sm text-gray-600 mt-0.5">Store vs Warehouse inventory comparison · {{ $products->total() }} products</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium" style="background-color: {{ $khaasBU->color_hex ?? '#f59e0b' }}20; color: {{ $khaasBU->color_hex ?? '#f59e0b' }};">
                🌿 {{ $khaasBU->name }}
            </span>
        </div>
    </div>

    @if(session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg">
            <p class="text-sm text-green-800">✅ {{ session('success') }}</p>
        </div>
    @endif
    @if(session('error'))
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg">
            <p class="text-sm text-red-800">❌ {{ session('error') }}</p>
        </div>
    @endif

    <!-- Filters -->
    <div class="bg-white border border-gray-200 rounded-xl p-4 mb-6">
        <form method="GET" action="{{ route('khaas.products') }}" class="flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-medium text-gray-600 mb-1">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Product name, SKU..." 
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-amber-500 focus:border-amber-500">
            </div>
            <div class="min-w-[140px]">
                <label class="block text-xs font-medium text-gray-600 mb-1">Status</label>
                <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-amber-500 focus:border-amber-500">
                    <option value="">All</option>
                    <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Active</option>
                    <option value="draft" {{ request('status') == 'draft' ? 'selected' : '' }}>Draft</option>
                    <option value="archived" {{ request('status') == 'archived' ? 'selected' : '' }}>Archived</option>
                </select>
            </div>
            <div class="min-w-[160px]">
                <label class="block text-xs font-medium text-gray-600 mb-1">Category</label>
                <select name="category" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-amber-500 focus:border-amber-500">
                    <option value="">All Categories</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat }}" {{ request('category') == $cat ? 'selected' : '' }}>{{ $cat }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="px-4 py-2 bg-amber-600 text-white text-sm font-medium rounded-lg hover:bg-amber-700 transition-colors">
                <i class="ki-filled ki-magnifier mr-1"></i> Filter
            </button>
            @if(request()->hasAny(['search', 'status', 'category']))
                <a href="{{ route('khaas.products') }}" class="px-4 py-2 bg-gray-100 text-gray-600 text-sm font-medium rounded-lg hover:bg-gray-200 transition-colors">
                    Clear
                </a>
            @endif
        </form>
    </div>

    {{-- ⭐ Aug-2026 TRANSFER REQUESTS — "the store is asking for stock".
         Sits ABOVE the transfer-approvals banner on purpose: a request is the
         EARLIER step (nothing has moved yet), so reading the page top-to-bottom
         follows the real sequence — asked → in transit → received. --}}
    @if($pendingRequestRecords->count() > 0)
    <div class="bg-white border rounded-xl mb-6 overflow-hidden" style="border-color:#93c5fd; background-color:#eff6ff;">
        <div class="px-5 py-3 border-b flex items-center justify-between" style="border-color:#bfdbfe; background:linear-gradient(to right,#eff6ff,#dbeafe);">
            <div class="flex items-center gap-2">
                <span class="text-lg">📨</span>
                <h3 class="font-semibold text-gray-900 text-sm">Transfer Requests</h3>
                <span class="px-2 py-0.5 rounded-full text-xs font-bold" style="background-color:#2563eb; color:#ffffff;">{{ $pendingRequestRecords->count() }}</span>
            </div>
            <span class="text-[11px]" style="color:#1d4ed8;">Stock the store has asked for — nothing has moved yet</span>
        </div>
        <div class="divide-y" style="border-color:#dbeafe;">
            @foreach($pendingRequestRecords as $tr)
            @php
                // Same ageing rule as the approvals banner below, but the meaning is
                // different: here nobody is waiting on stock in transit, someone is
                // waiting on an ANSWER. Amber past 24h, red past 48h.
                // (int): Carbon 3 diffInHours returns a float — uncast the chip prints "waiting 2.33333h"
                $trAgeHours = $tr->created_at ? (int) abs($tr->created_at->diffInHours(now())) : 0;
                $trIsOld = $trAgeHours >= 48;
                $trIsAging = !$trIsOld && $trAgeHours >= 24;
                $trRowStyle = $trIsOld
                    ? 'border-left: 3px solid #dc2626; background-color: #fef2f2;'
                    : ($trIsAging ? 'border-left: 3px solid #f59e0b;' : '');
                $trWarehouseQty = $warehouseInventory[$tr->product_id]['warehouse_qty'] ?? 0;
                $trShort = $trWarehouseQty < $tr->quantity;
            @endphp
            <div class="px-5 py-3 flex items-center justify-between gap-4" style="{{ $trRowStyle }}">
                <div class="flex items-center gap-3 flex-1 min-w-0">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center text-sm font-bold shrink-0" style="background-color:#dbeafe; color:#1e40af;">
                        {{ strtoupper(substr($tr->product ? $tr->product->title : '?', 0, 1)) }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-semibold text-gray-900 truncate">
                            {{ $tr->product ? $tr->product->title : 'Product #' . $tr->product_id }}
                            @if($tr->variant)
                                <span class="text-[10px] px-1 py-0.5 bg-gray-100 text-gray-500 rounded ml-1">{{ $tr->variant->title }}</span>
                            @endif
                        </div>
                        <div class="flex items-center flex-wrap gap-2 text-xs text-gray-500 mt-0.5">
                            <span class="font-medium" style="color:#1d4ed8;">asked for {{ $tr->quantity }} units</span>
                            <span>· by {{ $tr->requester ? $tr->requester->fullname : '—' }}</span>
                            <span>· {{ $tr->created_at->format('M d, h:i A') }}</span>
                            {{-- Live warehouse stock right here: the whole decision is
                                 "can I send this?", and making him leave the row to find
                                 out is how a request sits unanswered for two days. --}}
                            <span class="px-1.5 py-0.5 rounded font-medium text-[10px]"
                                  style="{{ $trShort ? 'background-color:#fee2e2; color:#991b1b;' : 'background-color:#f3f4f6; color:#4b5563;' }}">
                                🏭 warehouse has {{ $trWarehouseQty }}{{ $trShort ? ' — not enough' : '' }}
                            </span>
                            @if($trIsOld || $trIsAging)
                                <span class="px-1.5 py-0.5 rounded font-bold text-[10px]"
                                      style="{{ $trIsOld ? 'background-color:#fee2e2; color:#991b1b;' : 'background-color:#fef3c7; color:#92400e;' }}">
                                    ⏱ waiting {{ $trAgeHours }}h
                                </span>
                            @endif
                        </div>
                        @if($tr->notes)
                            <div class="text-[10px] text-gray-400 mt-0.5 truncate">📝 {{ $tr->notes }}</div>
                        @endif
                    </div>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    @if($canFulfilRequests)
                        <button type="button"
                            onclick="openAcceptRequestModal({{ $tr->id }}, '{{ $tr->product ? addslashes($tr->product->title) : '' }}', {{ $tr->quantity }}, {{ $trWarehouseQty }})"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg shadow-sm" style="background-color:#16a34a; color:#ffffff;" onmouseover="this.style.backgroundColor='#15803d'" onmouseout="this.style.backgroundColor='#16a34a'">✓ Send</button>
                        <button type="button"
                            onclick="openDeclineRequestModal({{ $tr->id }}, '{{ $tr->product ? addslashes($tr->product->title) : '' }}', {{ $tr->quantity }})"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg" style="background-color:#fef2f2; color:#dc2626; border:1px solid #fecaca;" onmouseover="this.style.backgroundColor='#fee2e2'" onmouseout="this.style.backgroundColor='#fef2f2'">✕ Decline</button>
                    @endif
                    <form method="POST" action="{{ url('khaas/transfer-requests') }}/{{ $tr->id }}/cancel" onsubmit="return confirm('Cancel this request?');" style="display:inline;">
                        @csrf
                        <button type="submit" class="px-2 py-1.5 text-xs font-medium rounded-lg" style="background-color:#ffffff; color:#6b7280; border:1px solid #e5e7eb;" title="Withdraw this request">Cancel</button>
                    </form>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Recently declined. The web has no push, so without this a manager's request
         would simply vanish and he would never learn it was refused. --}}
    @if($declinedRequestRecords->count() > 0)
    <div class="rounded-xl mb-6 px-5 py-3" style="background-color:#f9fafb; border:1px solid #e5e7eb;">
        <div class="text-xs font-semibold text-gray-500 mb-2">Recently declined requests (last 7 days)</div>
        <div class="flex flex-col gap-1">
            @foreach($declinedRequestRecords as $dr)
            <div class="text-xs text-gray-500">
                <span class="font-medium text-gray-700">{{ $dr->product ? $dr->product->title : 'Product #' . $dr->product_id }}</span>
                — {{ $dr->quantity }} units declined by {{ $dr->decliner ? $dr->decliner->fullname : '—' }}
                <span class="text-gray-400">· {{ $dr->declined_at ? $dr->declined_at->format('M d, h:i A') : '' }}</span>
                @if($dr->decline_reason)
                    <span class="text-gray-400">· “{{ $dr->decline_reason }}”</span>
                @endif
            </div>
            @endforeach
        </div>
    </div>
    @endif

    <!-- ⭐ Pending Transfer Approvals (inline on products page) -->
    @if($pendingTransferRecords->count() > 0)
    <div class="bg-white border border-amber-300 rounded-xl mb-6 overflow-hidden" style="background-color: #fffbeb;">
        <div class="px-5 py-3 border-b border-amber-200 flex items-center justify-between" style="background: linear-gradient(to right, #fffbeb, #fef3c7);">
            <div class="flex items-center gap-2">
                <span class="text-lg">⏳</span>
                <h3 class="font-semibold text-gray-900 text-sm">Pending Transfer Approvals</h3>
                <span class="px-2 py-0.5 rounded-full text-xs font-bold" style="background-color: #f59e0b; color: #ffffff;">{{ $pendingTransferRecords->count() }}</span>
            </div>
            <a href="{{ route('khaas.operations', ['tab' => 'transfers']) }}" class="text-xs font-medium" style="color: #d97706;">View All →</a>
        </div>
        <div class="divide-y divide-amber-100">
            @foreach($pendingTransferRecords as $ptr)
            @php
                // Aging: stock has already left the warehouse, so a transfer sitting here is
                // stock nobody can sell. Amber past 24h, red past 48h.
                // (int): same Carbon-3 float issue as the requests banner above.
                $ptrAgeHours = $ptr->created_at ? (int) abs($ptr->created_at->diffInHours(now())) : 0;
                $ptrIsOld = $ptrAgeHours >= 48;
                $ptrIsAging = !$ptrIsOld && $ptrAgeHours >= 24;
                $ptrRowStyle = $ptrIsOld
                    ? 'border-left: 3px solid #dc2626; background-color: #fef2f2;'
                    : ($ptrIsAging ? 'border-left: 3px solid #f59e0b;' : '');
            @endphp
            <div class="px-5 py-3 flex items-center justify-between gap-4" style="{{ $ptrRowStyle }}">
                <div class="flex items-center gap-3 flex-1 min-w-0">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center text-sm font-bold shrink-0" style="background-color: #fef3c7; color: #92400e;">
                        {{ strtoupper(substr($ptr->product ? $ptr->product->title : '?', 0, 1)) }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-semibold text-gray-900 truncate">
                            {{ $ptr->product ? $ptr->product->title : 'Product #' . $ptr->product_id }}
                            @if($ptr->variant)
                                <span class="text-[10px] px-1 py-0.5 bg-gray-100 text-gray-500 rounded ml-1">{{ $ptr->variant->title }}</span>
                            @endif
                        </div>
                        <div class="flex items-center gap-2 text-xs text-gray-500 mt-0.5">
                            <span class="font-medium" style="color: #92400e;">{{ $ptr->quantity }} units</span>
                            <span>🏭→🏪</span>
                            <span>by {{ $ptr->requester ? $ptr->requester->fullname : '—' }}</span>
                            <span>· {{ $ptr->created_at->format('M d, h:i A') }}</span>
                            @if($ptrIsOld || $ptrIsAging)
                                <span class="px-1.5 py-0.5 rounded font-bold text-[10px]"
                                      style="{{ $ptrIsOld ? 'background-color:#fee2e2; color:#991b1b;' : 'background-color:#fef3c7; color:#92400e;' }}">
                                    ⏱ pending {{ $ptrAgeHours }}h
                                </span>
                            @endif
                        </div>
                        @if($ptr->notes)
                            <div class="text-[10px] text-gray-400 mt-0.5 truncate">📝 {{ $ptr->notes }}</div>
                        @endif
                    </div>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    {{-- Aug-2026: was a bare confirm()+POST. Opens the modal so the
                         approver can name whoever actually counted the stock. --}}
                    <button type="button" onclick="openProductApproveModal({{ $ptr->id }}, '{{ $ptr->product ? addslashes($ptr->product->title) : '' }}', {{ $ptr->quantity }})"
                        class="px-3 py-1.5 text-xs font-medium rounded-lg shadow-sm" style="background-color: #16a34a; color: #ffffff;" onmouseover="this.style.backgroundColor='#15803d'" onmouseout="this.style.backgroundColor='#16a34a'">✓ Approve</button>
                    <button type="button" onclick="openProductRejectModal({{ $ptr->id }}, '{{ $ptr->product ? addslashes($ptr->product->title) : '' }}', {{ $ptr->quantity }})"
                        class="px-3 py-1.5 text-xs font-medium rounded-lg" style="background-color: #fef2f2; color: #dc2626; border: 1px solid #fecaca;" onmouseover="this.style.backgroundColor='#fee2e2'" onmouseout="this.style.backgroundColor='#fef2f2'">✕ Reject</button>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    <!-- Products Grid with Store vs Warehouse Comparison -->
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        @forelse($products as $product)
        @php
            $firstVariant = $product->variants->first();
            // NOTE: `?:` would treat a genuine zero as "missing" and fall through to the
            // denormalised total_inventory column, showing stale stock for a sold-out product.
            $storeQty = $product->variants->isNotEmpty()
                ? (int) $product->variants->sum('inventory_quantity')
                : (int) ($product->total_inventory ?? 0);
            $price = $firstVariant ? $firstVariant->price : 0;
            $whData = $warehouseInventory[$product->id] ?? null;
            $warehouseQty = $whData['warehouse_qty'] ?? 0;
            $pendingQty = $pendingTransfers[$product->id] ?? 0;
            $unit = $whData['unit'] ?? 'pcs';
            $minStock = $whData['min_stock_level'] ?? 0;
            $isLowStock = $minStock > 0 && $warehouseQty <= $minStock;

            // ⭐ Outstanding order demand — units already sold that have NOT yet come
            // out of the Store figure above (prepared items are excluded server-side,
            // because those have already been deducted and counting them would make
            // the manager request twice as much as he needs).
            // ⭐ Sep-26: the SAME number the phone shows (OpenOrderDemandService) — open
            //   orders = accepted (today) + pending (accepted for a later day). The Shopify
            //   approval queue is shown beside it, not inside it (owner).
            $demandRow = $orderDemand[$product->id] ?? null;
            $demandTotal = $demandRow['total'] ?? 0;
            $demandShopify = $demandRow['shopify'] ?? 0;
            $demandAccepted = $demandRow['accepted'] ?? 0;
            $demandLater = $demandRow['pending'] ?? 0;

            $openRequest = $pendingRequestsByProduct[$product->id] ?? null;
            $requestedQty = $openRequest ? (int) $openRequest->quantity : 0;

            // What is genuinely missing: demand the store cannot currently cover, after
            // counting what it holds, what is already on its way, and what has already
            // been asked for. This is the number pre-filled into the request box.
            $shortfall = max(0, $demandTotal - $storeQty - $pendingQty - $requestedQty);
        @endphp
        <div class="bg-white border {{ $isLowStock ? 'border-red-300' : 'border-gray-200' }} rounded-xl overflow-hidden hover:shadow-md transition-shadow">
            <!-- Product Header -->
            <div class="px-4 py-3 border-b {{ $isLowStock ? 'border-red-200 bg-red-50' : 'border-gray-100' }}">
                <div class="flex items-start justify-between">
                    <div class="flex-1 min-w-0">
                        <h3 class="font-semibold text-gray-900 text-sm truncate">{{ $product->title }}</h3>
                        <div class="flex items-center gap-2 mt-1">
                            <span class="text-xs text-gray-500">SKU: {{ $firstVariant->sku ?? 'N/A' }}</span>
                            <span class="text-xs font-medium text-amber-700">PKR {{ number_format($price) }}</span>
                        </div>
                    </div>
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase
                        {{ $product->status === 'active' ? 'bg-green-100 text-green-700' : '' }}
                        {{ $product->status === 'draft' ? 'bg-yellow-100 text-yellow-700' : '' }}
                        {{ $product->status === 'archived' ? 'bg-gray-100 text-gray-500' : '' }}
                    ">{{ $product->status }}</span>
                </div>
                @if($product->attribute_1)
                    <div class="mt-1">
                        <span class="text-[10px] px-1.5 py-0.5 bg-gray-100 text-gray-600 rounded">{{ $product->attribute_1 }}</span>
                    </div>
                @endif
            </div>

            <!-- ⭐ Store vs Warehouse Comparison -->
            <div class="px-4 py-3">
                <div class="grid grid-cols-2 gap-3">
                    <!-- Store Inventory (clickable for transaction log) -->
                    <div class="bg-blue-50 rounded-lg p-3 text-center cursor-pointer hover:bg-blue-100 transition-colors group"
                         onclick="openStoreLogModal({{ $product->id }}, '{{ addslashes($product->title) }}', {{ $storeQty }})"
                         title="Click to see inventory transaction log">
                        <div class="text-xs text-blue-600 font-medium mb-1">🏪 Store <span class="opacity-0 group-hover:opacity-100 transition-opacity text-[9px]">📋</span></div>
                        <div class="text-xl font-bold text-blue-800">{{ $storeQty }}</div>
                        <div class="text-[10px] text-blue-500">{{ $unit }}</div>
                        @if($pendingQty > 0)
                            <div class="mt-1 text-[10px] text-amber-600 font-medium">⏳ +{{ $pendingQty }} pending</div>
                        @endif
                    </div>
                    <!-- Warehouse Inventory (clickable for the full warehouse ledger) -->
                    <div class="rounded-lg p-3 text-center cursor-pointer transition-colors group {{ $isLowStock ? 'bg-red-50 hover:bg-red-100' : 'bg-amber-50 hover:bg-amber-100' }}"
                         onclick="openWarehouseLogModal({{ $product->id }}, '{{ addslashes($product->title) }}', {{ $warehouseQty }})"
                         title="Click to see the full warehouse in/out history">
                        <div class="text-xs {{ $isLowStock ? 'text-red-600' : 'text-amber-600' }} font-medium mb-1">🏭 Warehouse <span class="opacity-0 group-hover:opacity-100 transition-opacity text-[9px]">📋</span></div>
                        <div class="text-xl font-bold {{ $isLowStock ? 'text-red-800' : 'text-amber-800' }}">{{ $warehouseQty }}</div>
                        <div class="text-[10px] {{ $isLowStock ? 'text-red-500' : 'text-amber-500' }}">{{ $unit }}</div>
                        @if($isLowStock)
                            <div class="mt-1 text-[10px] text-red-600 font-bold">⚠️ Low Stock</div>
                        @endif
                    </div>
                </div>

                <!-- Total Combined -->
                <div class="mt-2 bg-gray-50 rounded-lg px-3 py-2 flex items-center justify-between">
                    <span class="text-xs text-gray-600">Combined Total</span>
                    <span class="text-sm font-bold text-gray-800">{{ $storeQty + $warehouseQty }} {{ $unit }}</span>
                </div>

                {{-- ❄ This month's recipe picture: what was made, how it came in, and
                     roughly what the ingredients cost. An ESTIMATE, labelled as one.
                     Quantities show to anyone in Frozen mode; the rupees need
                     view_khaas_costing. A product with no recipe says so and offers
                     the way to write one, rather than showing a confident zero. --}}
                @php
                    $rc  = $recipeCosts[$product->id] ?? null;
                    $cov = $recipeCoverage[$product->id] ?? null;
                    $rp  = ($recipePrices ?? [])[$product->id] ?? null;
                @endphp
                {{-- 💲 Sep-27: the recipe at today's prices. A partial figure says how many
                     lines it leaves out, so it never reads as the whole cost. --}}
                @if($rp)
                    <div class="mt-1 rounded-lg px-3 py-2"
                         style="background-color:#F0FDF4; border:1px solid #BBF7D0;">
                        @if($canSeeIngredientCost && $rp['per_pack'] !== null && $rp['priced_lines'] > 0)
                            <div class="flex items-center justify-between">
                                <span class="text-[11px]" style="color:#166534;">Recipe at today's prices</span>
                                <span class="text-xs font-bold" style="color:#14532D;">Rs {{ number_format($rp['per_pack'], 2) }} / {{ Str::singular($unit) }}</span>
                            </div>
                            @if($rp['share_of_price'] !== null)
                                <div class="text-[10px] mt-0.5" style="color:#15803D;">{{ $rp['share_of_price'] }}% of the Rs {{ number_format($rp['selling_price']) }} price</div>
                            @endif
                        @elseif($rp['priced_lines'] === 0)
                            <span class="text-[11px]" style="color:#166534;">Recipe has no priced line yet</span>
                        @else
                            <span class="text-[11px]" style="color:#166534;">Recipe: {{ $rp['priced_lines'] }} line(s) priced</span>
                        @endif
                        @if($rp['unpriced_lines'] > 0)
                            <div class="text-[10px] mt-0.5" style="color:#B45309;"
                                 title="{{ implode(', ', $rp['unpriced_names']) }}">
                                ⚠ {{ $rp['unpriced_lines'] }} ingredient(s) not priced yet{{ $rp['priced_lines'] > 0 ? ' — real cost is higher' : '' }}
                            </div>
                        @endif
                        @if($rp['flagged_prices'] > 0)
                            <div class="text-[10px] mt-0.5" style="color:#B45309;">⚠ {{ $rp['flagged_prices'] }} old or jumped price(s) — see the recipe</div>
                        @endif
                    </div>
                @endif
                @if($cov && !$cov['has_recipe'])
                    <div class="mt-1 rounded-lg px-3 py-2 flex items-center justify-between"
                         style="background-color:#F9FAFB; border:1px solid #E5E7EB;">
                        <span class="text-[11px] text-gray-500">No recipe yet</span>
                        @if($canManageRecipes)
                            <a href="{{ route('khaas.inventory', ['tab' => 'ingredients']) }}"
                               class="text-[10px] font-bold px-2 py-0.5 rounded"
                               style="background-color:#FEF3C7;color:#B45309;border:1px solid #FDE68A;">Write one</a>
                        @endif
                    </div>
                @elseif($rc && $rc['made'] > 0)
                    <div class="mt-1 rounded-lg px-3 py-2"
                         style="background-color:#EEF2FF; border:1px solid #C7D2FE;">
                        <div class="flex items-center justify-between">
                            <span class="text-[11px]" style="color:#4338CA;">Made this month</span>
                            <span class="text-xs font-bold" style="color:#312E81;">{{ number_format($rc['made']) }} {{ $unit }}</span>
                        </div>
                        <div class="text-[10px] mt-0.5" style="color:#4F46E5;">
                            {{ number_format($rc['made_plan']) }} through a plan ·
                            {{ number_format($rc['made_direct']) }} entered directly
                        </div>
                        @if($canSeeIngredientCost && $rc['cost_per_pack'] !== null)
                            <div class="flex items-center justify-between mt-1 pt-1" style="border-top:1px solid #C7D2FE;">
                                <span class="text-[11px]" style="color:#4338CA;">Ingredients, estimated</span>
                                <span class="text-xs font-bold" style="color:#312E81;">Rs {{ number_format($rc['cost_per_pack'], 2) }} / {{ Str::singular($unit) }}</span>
                            </div>
                        @endif
                    </div>
                @endif
                {{-- In-transit: a pending transfer has ALREADY left the warehouse but has not yet
                     been accepted into the store, so it is in neither tile and NOT in the
                     Combined Total above. Shown explicitly rather than silently missing. --}}
                @if($pendingQty > 0)
                <div class="mt-1 rounded-lg px-3 py-2 flex items-center justify-between" style="background-color:#fffbeb; border:1px solid #fde68a;">
                    <span class="text-xs" style="color:#92400e;">🚚 In transit <span class="text-[10px]" style="color:#b45309;">(left warehouse, awaiting store)</span></span>
                    <span class="text-sm font-bold" style="color:#92400e;">{{ $pendingQty }} {{ $unit }}</span>
                </div>
                @endif

                {{-- Pending order demand. Clickable: the number alone invites "says who?",
                     so the popup lists the exact orders behind it. Hidden entirely at 0
                     to keep cards clean. --}}
                @if($demandTotal > 0 || $demandShopify > 0)
                <div class="mt-1 rounded-lg px-3 py-2" style="background-color:#faf5ff; border:1px solid #e9d5ff;">
                    <div class="flex items-center justify-between cursor-pointer group"
                         onclick="openPendingOrdersModal({{ $product->id }}, '{{ addslashes($product->title) }}')"
                         title="Click to see which orders need this">
                        <span class="text-xs" style="color:#6b21a8;">
                            🛒 Open orders
                            <span class="text-[10px] opacity-0 group-hover:opacity-100 transition-opacity">📋</span>
                        </span>
                        <span class="text-sm font-bold group-hover:underline" style="color:#6b21a8;">{{ $demandTotal }} {{ $unit }}</span>
                    </div>
                    <div class="flex items-center justify-between mt-0.5">
                        <span class="text-[10px]" style="color:#7e22ce;">
                            @if($demandTotal > 0)✅ Accepted {{ $demandAccepted }} · 🗓 Later day {{ $demandLater }}@endif
                        </span>
                        @if($shortfall > 0)
                            <span class="px-1.5 py-0.5 rounded text-[10px] font-bold" style="background-color:#fee2e2; color:#991b1b;">Short by {{ $shortfall }}</span>
                        @endif
                    </div>
                    @if($demandShopify > 0)
                    <div class="text-[10px] mt-0.5" style="color:#9ca3af;">⏳ {{ $demandShopify }} more in the Shopify approval queue (not counted)</div>
                    @endif
                </div>
                @endif

                {{-- An open request: shown as its own row rather than a tile badge, because
                     it is neither stock nor in transit — it is a question awaiting an answer. --}}
                @if($openRequest)
                <div class="mt-1 rounded-lg px-3 py-2 flex items-center justify-between cursor-pointer"
                     style="background-color:#eff6ff; border:1px solid #bfdbfe;"
                     onclick="openRequestModal({{ $product->id }}, '{{ addslashes($product->title) }}', {{ $firstVariant->id ?? 'null' }}, {{ $warehouseQty }}, {{ $demandTotal }}, {{ $storeQty }}, {{ $pendingQty }}, {{ $shortfall }}, {{ $requestedQty }})"
                     title="Click to change or withdraw this request">
                    <span class="text-xs" style="color:#1e40af;">📨 Requested <span class="text-[10px]" style="color:#2563eb;">(awaiting warehouse)</span></span>
                    <span class="text-sm font-bold" style="color:#1e40af;">{{ $requestedQty }} {{ $unit }}</span>
                </div>
                @endif
            </div>

            <!-- Actions -->
            <div class="px-4 py-3 border-t border-gray-100 bg-gray-50">
                <div class="flex items-center gap-2">
                    <button onclick="openStockModal({{ $product->id }}, '{{ addslashes($product->title) }}', {{ $firstVariant->id ?? 'null' }}, {{ $warehouseQty }})"
                        class="flex-1 px-2 py-1.5 bg-white border border-gray-300 text-gray-700 text-xs font-medium rounded-lg hover:bg-gray-100 transition-colors text-center">
                        📥 Warehouse
                    </button>
                    <button onclick="openStoreStockModal({{ $product->id }}, '{{ addslashes($product->title) }}', {{ $firstVariant->id ?? 'null' }}, {{ $storeQty }})"
                        class="flex-1 px-2 py-1.5 bg-blue-50 border border-blue-300 text-blue-700 text-xs font-medium rounded-lg hover:bg-blue-100 transition-colors text-center">
                        🏪 Store Adjust
                    </button>
                    <button onclick="openTransferModal({{ $product->id }}, '{{ addslashes($product->title) }}', {{ $firstVariant->id ?? 'null' }}, {{ $warehouseQty }})"
                        class="flex-1 px-2 py-1.5 border text-xs font-medium rounded-lg transition-colors text-center" style="background-color: #fef3c7; color: #92400e; border-color: #fbbf24;" onmouseover="this.style.backgroundColor='#fde68a'" onmouseout="this.style.backgroundColor='#fef3c7'">
                        🔄 Transfer
                    </button>
                </div>
                {{-- Request lives on its own row rather than as a fourth cramped button:
                     the three above ACT on stock directly, this one asks someone else to.
                     "Transfer" is deliberately kept for everyone (owner ruling) — this is
                     an additional path, not a replacement. --}}
                <button onclick="openRequestModal({{ $product->id }}, '{{ addslashes($product->title) }}', {{ $firstVariant->id ?? 'null' }}, {{ $warehouseQty }}, {{ $demandTotal }}, {{ $storeQty }}, {{ $pendingQty }}, {{ $shortfall }}, {{ $requestedQty }})"
                    class="w-full mt-2 px-2 py-1.5 border text-xs font-medium rounded-lg transition-colors text-center"
                    style="background-color:{{ $openRequest ? '#dbeafe' : '#eff6ff' }}; color:#1e40af; border-color:#93c5fd;"
                    onmouseover="this.style.backgroundColor='#bfdbfe'" onmouseout="this.style.backgroundColor='{{ $openRequest ? '#dbeafe' : '#eff6ff' }}'">
                    📨 {{ $openRequest ? 'Change request (' . $requestedQty . ')' : 'Request from warehouse' }}
                </button>
            </div>
        </div>
        @empty
        <div class="col-span-full bg-white border border-gray-200 rounded-xl p-12 text-center">
            <div class="text-4xl mb-3">📦</div>
            <h3 class="text-lg font-semibold text-gray-700">No Khaas Products Found</h3>
            <p class="text-sm text-gray-500 mt-1">Products assigned to the {{ $khaasBU->name }} business unit will appear here.</p>
        </div>
        @endforelse
    </div>

    <!-- Pagination -->
    @if($products->hasPages())
    <div class="mt-6">
        {{ $products->appends(request()->query())->links() }}
    </div>
    @endif
</div>
@endsection

{{-- ⭐ MODALS: Pushed to @stack('modals') so they render at <body> level for proper fixed positioning --}}
@push('modals')
<!-- Stock In/Adjust Modal -->
{{-- ⚠️ Shell is inline-styled deliberately. inset-0, flex, max-w-*, max-h-*, overflow-y-auto
     and flex-shrink-0 are ALL PURGED from the built styles.css (verified: 0 occurrences), so a
     class-only shell renders un-positioned and its body cannot scroll. Same fix as invLogModal
     below. See the metronic-v9 purge note. --}}
<div id="stockModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 999999; top:0; right:0; bottom:0; left:0; background-color:rgba(0,0,0,0.5); padding:1rem;" onclick="if(event.target===this)closeStockModal()">
    <div class="w-full max-w-lg bg-white rounded-2xl shadow-2xl text-left overflow-hidden" style="width:100%; max-width:32rem; max-height:90vh; display:flex; flex-direction:column; background:#fff; border-radius:1rem; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.10), 0 10px 10px -5px rgba(0,0,0,0.04);" onclick="event.stopPropagation()">
        <!-- Header -->
        <div class="px-6 py-4 border-b border-gray-100" style="background: linear-gradient(to right, #fffbeb, #fff7ed); flex-shrink:0;">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-100 flex items-center justify-center">
                        <span class="text-xl">📥</span>
                    </div>
                    <div>
                        <h3 id="stock-modal-title" class="text-lg font-bold text-gray-900">Warehouse Stock Update</h3>
                        <p class="text-xs text-gray-500 mt-0.5">Adjust inventory levels for this product</p>
                    </div>
                </div>
                <button onclick="closeStockModal()" class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
        <form method="POST" action="{{ route('khaas.warehouse.stock') }}" style="display:flex; flex-direction:column; flex:1 1 auto; min-height:0;">
            @csrf
            <input type="hidden" name="product_id" id="stock_product_id">
            <input type="hidden" name="product_variant_id" id="stock_variant_id">
            <input type="hidden" name="business_unit_id" value="{{ $khaasBU->id }}">
            <div class="px-6 py-4 space-y-4 max-h-[70vh] overflow-y-auto" style="flex:1 1 auto; min-height:0; overflow-y:auto; overscroll-behavior:contain;">
                <!-- Product Info Card -->
                <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-xl border border-gray-100">
                    <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center text-lg font-bold text-amber-700" id="stock_product_initial">—</div>
                    <div class="flex-1">
                        <div id="stock_product_name" class="text-sm font-semibold text-gray-900"></div>
                        <div id="stock_current_qty" class="text-xs text-gray-500 mt-0.5"></div>
                    </div>
                </div>
                <!-- Action Type -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Action Type</label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="relative flex items-center gap-2 p-2.5 border-2 rounded-xl cursor-pointer transition-all hover:border-amber-300" style="border-color: #f59e0b; background-color: #fffbeb;" id="stock_radio_stock_in">
                            <input type="radio" name="change_type" value="stock_in" class="sr-only" checked onchange="updateStockRadioStyles()">
                            <span class="text-lg">📥</span>
                            <div>
                                <div class="text-sm font-medium text-gray-900">Stock In</div>
                                <div class="text-[10px] text-gray-500">Add inventory</div>
                            </div>
                        </label>
                        <label class="relative flex items-center gap-2 p-2.5 border-2 border-gray-200 rounded-xl cursor-pointer transition-all hover:border-amber-300" id="stock_radio_stock_out">
                            <input type="radio" name="change_type" value="stock_out" class="sr-only" onchange="updateStockRadioStyles()">
                            <span class="text-lg">📤</span>
                            <div>
                                <div class="text-sm font-medium text-gray-900">Stock Out</div>
                                <div class="text-[10px] text-gray-500">Remove inventory</div>
                            </div>
                        </label>
                        <label class="relative flex items-center gap-2 p-2.5 border-2 border-gray-200 rounded-xl cursor-pointer transition-all hover:border-amber-300" id="stock_radio_count">
                            <input type="radio" name="change_type" value="count" class="sr-only" onchange="updateStockRadioStyles()">
                            <span class="text-lg">📊</span>
                            <div>
                                <div class="text-sm font-medium text-gray-900">Count</div>
                                <div class="text-[10px] text-gray-500">Set exact qty</div>
                            </div>
                        </label>
                        <label class="relative flex items-center gap-2 p-2.5 border-2 border-gray-200 rounded-xl cursor-pointer transition-all hover:border-amber-300" id="stock_radio_adjustment">
                            <input type="radio" name="change_type" value="adjustment" class="sr-only" onchange="updateStockRadioStyles()">
                            <span class="text-lg">🔧</span>
                            <div>
                                <div class="text-sm font-medium text-gray-900">Adjust</div>
                                <div class="text-[10px] text-gray-500">Correction</div>
                            </div>
                        </label>
                    </div>
                </div>
                <!-- Adjust direction (only meaningful for change_type=adjustment) -->
                <div id="stock_adjust_direction_wrap" style="display:none;">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Correction Direction</label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="flex items-center justify-center gap-2 p-2.5 border-2 rounded-xl cursor-pointer transition-all" style="border-color: #f59e0b; background-color: #fffbeb;" id="stock_dir_add">
                            <input type="radio" name="adjust_direction" value="add" class="sr-only" checked onchange="updateStockDirStyles()">
                            <span class="text-sm font-medium text-gray-900">➕ Add</span>
                        </label>
                        <label class="flex items-center justify-center gap-2 p-2.5 border-2 border-gray-200 rounded-xl cursor-pointer transition-all" id="stock_dir_reduce">
                            <input type="radio" name="adjust_direction" value="reduce" class="sr-only" onchange="updateStockDirStyles()">
                            <span class="text-sm font-medium text-gray-900">➖ Reduce</span>
                        </label>
                    </div>
                    <p class="text-[11px] text-gray-400 mt-1">Reduce subtracts from the warehouse. Stock cannot go below zero.</p>
                </div>
                <!-- Quantity -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Quantity</label>
                    <input type="number" name="quantity_change" min="0" required placeholder="Enter quantity..."
                        class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500 transition-colors">
                </div>
                <!-- Notes -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Notes <span class="font-normal text-gray-400">(optional)</span></label>
                    <textarea name="notes" rows="2" placeholder="Reason for this update..."
                        class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500 transition-colors resize-none"></textarea>
                </div>
            </div>
            <!-- Footer -->
            <div class="px-6 py-3 border-t border-gray-100 bg-gray-50 flex items-center justify-end gap-3" style="flex-shrink:0; display:flex; align-items:center; justify-content:flex-end; gap:0.75rem;">
                <button type="button" onclick="closeStockModal()" class="px-5 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50 transition-colors">Cancel</button>
                <button type="submit" class="px-5 py-2.5 text-sm font-medium text-white rounded-xl shadow-sm transition-colors" style="background-color: #d97706;" onmouseover="this.style.backgroundColor='#b45309'" onmouseout="this.style.backgroundColor='#d97706'">
                    Update Stock
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Store Stock Adjust Modal -->
<div id="storeStockModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 999999; top:0; right:0; bottom:0; left:0; background-color:rgba(0,0,0,0.5); padding:1rem;" onclick="if(event.target===this)closeStoreStockModal()">
    <div class="w-full max-w-lg bg-white rounded-2xl shadow-2xl text-left overflow-hidden" style="width:100%; max-width:32rem; max-height:90vh; display:flex; flex-direction:column; background:#fff; border-radius:1rem; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.10), 0 10px 10px -5px rgba(0,0,0,0.04);" onclick="event.stopPropagation()">
        <!-- Header -->
        <div class="px-6 py-4 border-b border-gray-100" style="background: linear-gradient(to right, #eff6ff, #e0f2fe); flex-shrink:0;">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-blue-100 flex items-center justify-center">
                        <span class="text-xl">🏪</span>
                    </div>
                    <div>
                        <h3 id="store-stock-modal-title" class="text-lg font-bold text-gray-900">Store Stock Adjust</h3>
                        <p class="text-xs text-gray-500 mt-0.5">Adjust store inventory levels for this product</p>
                    </div>
                </div>
                <button onclick="closeStoreStockModal()" class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
        <form method="POST" action="{{ route('khaas.store.stock') }}" style="display:flex; flex-direction:column; flex:1 1 auto; min-height:0;">
            @csrf
            <input type="hidden" name="product_id" id="store_stock_product_id">
            <input type="hidden" name="product_variant_id" id="store_stock_variant_id">
            <input type="hidden" name="business_unit_id" value="{{ $khaasBU->id }}">
            <div class="px-6 py-4 space-y-4 max-h-[70vh] overflow-y-auto" style="flex:1 1 auto; min-height:0; overflow-y:auto; overscroll-behavior:contain;">
                <!-- Product Info Card -->
                <div class="flex items-center gap-3 p-3 bg-blue-50 rounded-xl border border-blue-100">
                    <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center text-lg font-bold text-blue-700" id="store_stock_product_initial">—</div>
                    <div class="flex-1">
                        <div id="store_stock_product_name" class="text-sm font-semibold text-gray-900"></div>
                        <div id="store_stock_current_qty" class="text-xs text-blue-600 mt-0.5"></div>
                    </div>
                </div>
                <!-- Action Type -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Action Type</label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="relative flex items-center gap-2 p-2.5 border-2 rounded-xl cursor-pointer transition-all hover:border-blue-300" style="border-color: #3b82f6; background-color: #eff6ff;" id="store_stock_radio_store_stock_in">
                            <input type="radio" name="change_type" value="store_stock_in" class="sr-only" checked onchange="updateStoreStockRadioStyles()">
                            <span class="text-lg">📥</span>
                            <div>
                                <div class="text-sm font-medium text-gray-900">Stock In</div>
                                <div class="text-[10px] text-gray-500">Add to store</div>
                            </div>
                        </label>
                        <label class="relative flex items-center gap-2 p-2.5 border-2 border-gray-200 rounded-xl cursor-pointer transition-all hover:border-blue-300" id="store_stock_radio_store_stock_out">
                            <input type="radio" name="change_type" value="store_stock_out" class="sr-only" onchange="updateStoreStockRadioStyles()">
                            <span class="text-lg">📤</span>
                            <div>
                                <div class="text-sm font-medium text-gray-900">Stock Out</div>
                                <div class="text-[10px] text-gray-500">Remove from store</div>
                            </div>
                        </label>
                        <label class="relative flex items-center gap-2 p-2.5 border-2 border-gray-200 rounded-xl cursor-pointer transition-all hover:border-blue-300" id="store_stock_radio_store_count">
                            <input type="radio" name="change_type" value="store_count" class="sr-only" onchange="updateStoreStockRadioStyles()">
                            <span class="text-lg">📊</span>
                            <div>
                                <div class="text-sm font-medium text-gray-900">Count</div>
                                <div class="text-[10px] text-gray-500">Set exact qty</div>
                            </div>
                        </label>
                        <label class="relative flex items-center gap-2 p-2.5 border-2 border-gray-200 rounded-xl cursor-pointer transition-all hover:border-blue-300" id="store_stock_radio_store_adjustment">
                            <input type="radio" name="change_type" value="store_adjustment" class="sr-only" onchange="updateStoreStockRadioStyles()">
                            <span class="text-lg">🔧</span>
                            <div>
                                <div class="text-sm font-medium text-gray-900">Adjust</div>
                                <div class="text-[10px] text-gray-500">Correction</div>
                            </div>
                        </label>
                    </div>
                </div>
                <!-- Adjust direction (only meaningful for change_type=store_adjustment) -->
                <div id="store_adjust_direction_wrap" style="display:none;">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Correction Direction</label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="flex items-center justify-center gap-2 p-2.5 border-2 rounded-xl cursor-pointer transition-all" style="border-color: #3b82f6; background-color: #eff6ff;" id="store_dir_add">
                            <input type="radio" name="adjust_direction" value="add" class="sr-only" checked onchange="updateStoreDirStyles()">
                            <span class="text-sm font-medium text-gray-900">➕ Add</span>
                        </label>
                        <label class="flex items-center justify-center gap-2 p-2.5 border-2 border-gray-200 rounded-xl cursor-pointer transition-all" id="store_dir_reduce">
                            <input type="radio" name="adjust_direction" value="reduce" class="sr-only" onchange="updateStoreDirStyles()">
                            <span class="text-sm font-medium text-gray-900">➖ Reduce</span>
                        </label>
                    </div>
                    <p class="text-[11px] text-gray-400 mt-1">Reduce subtracts from store stock. Stock cannot go below zero.</p>
                </div>
                <!-- Quantity -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Quantity</label>
                    <input type="number" name="quantity_change" min="0" required placeholder="Enter quantity..."
                        class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                </div>
                <!-- Notes -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Notes <span class="font-normal text-gray-400">(optional)</span></label>
                    <textarea name="notes" rows="2" placeholder="Reason for this store adjustment..."
                        class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors resize-none"></textarea>
                </div>
            </div>
            <!-- Footer -->
            <div class="px-6 py-3 border-t border-gray-100 bg-gray-50 flex items-center justify-end gap-3" style="flex-shrink:0; display:flex; align-items:center; justify-content:flex-end; gap:0.75rem;">
                <button type="button" onclick="closeStoreStockModal()" class="px-5 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50 transition-colors">Cancel</button>
                <button type="submit" class="px-5 py-2.5 text-sm font-medium text-white rounded-xl shadow-sm transition-colors" style="background-color: #2563eb;" onmouseover="this.style.backgroundColor='#1d4ed8'" onmouseout="this.style.backgroundColor='#2563eb'">
                    Update Store Stock
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Approve Transfer Modal (Aug-2026) — captures WHO COUNTED the stock -->
{{-- Same shell rules as the reject modal below: inset-0 / max-w-* / flex-shrink-0
     are purged so they're inline, but NEVER put `display` on the outer overlay —
     it would outrank .hidden{display:none} and the modal could never close. --}}
<div id="productApproveModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 999999; top:0; right:0; bottom:0; left:0; background-color:rgba(0,0,0,0.5); padding:1rem;" onclick="if(event.target===this)closeProductApproveModal()">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden" style="width:100%; max-width:28rem; max-height:90vh; display:flex; flex-direction:column; background:#fff; border-radius:1rem; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.10), 0 10px 10px -5px rgba(0,0,0,0.04);" onclick="event.stopPropagation()">
        <div class="px-6 py-5 border-b border-gray-100" style="background: linear-gradient(to right, #f0fdf4, #ecfdf5); flex-shrink:0;">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-green-100 flex items-center justify-center text-xl">✅</div>
                <div>
                    <h3 class="text-lg font-bold text-gray-900">Approve Transfer</h3>
                    <p class="text-xs text-gray-500" id="productApproveModalInfo"></p>
                </div>
            </div>
        </div>
        <form id="productApproveForm" method="POST" style="display:flex; flex-direction:column; flex:1 1 auto; min-height:0;">
            @csrf
            <div class="px-6 py-5" style="flex:1 1 auto; min-height:0; overflow-y:auto;">
                <label class="block text-sm font-semibold text-gray-700 mb-2">Counted by</label>
                <select name="counted_by" id="productApproveCountedBy"
                        class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500"
                        style="width:100%; padding:0.75rem 1rem; border:2px solid #e5e7eb; border-radius:0.75rem; font-size:0.875rem; background:#fff;">
                    @foreach($countedByUsers ?? [] as $u)
                        <option value="{{ $u->id }}" @selected($u->id == auth()->id())>{{ $u->fullname }}@if($u->id == auth()->id()) (me)@endif</option>
                    @endforeach
                    <option value="">— Not recorded —</option>
                </select>
                <p class="text-xs text-gray-400 mt-2">Defaults to you. Change it if someone else did the physical count.</p>
            </div>
            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex items-center justify-end gap-3" style="flex-shrink:0; display:flex; align-items:center; justify-content:flex-end; gap:0.75rem;">
                <button type="button" onclick="closeProductApproveModal()" class="px-5 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-5 py-2.5 text-sm font-medium text-white rounded-xl shadow-sm" style="background-color: #16a34a;" onmouseover="this.style.backgroundColor='#15803d'" onmouseout="this.style.backgroundColor='#16a34a'">Approve Transfer</button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Transfer Modal (for pending approvals) -->
<div id="productRejectModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 999999; top:0; right:0; bottom:0; left:0; background-color:rgba(0,0,0,0.5); padding:1rem;" onclick="if(event.target===this)closeProductRejectModal()">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden" style="width:100%; max-width:28rem; max-height:90vh; display:flex; flex-direction:column; background:#fff; border-radius:1rem; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.10), 0 10px 10px -5px rgba(0,0,0,0.04);" onclick="event.stopPropagation()">
        <div class="px-6 py-5 border-b border-gray-100" style="background: linear-gradient(to right, #fef2f2, #fff7ed); flex-shrink:0;">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-red-100 flex items-center justify-center text-xl">❌</div>
                <div>
                    <h3 class="text-lg font-bold text-gray-900">Reject Transfer</h3>
                    <p class="text-xs text-gray-500" id="productRejectModalInfo"></p>
                </div>
            </div>
        </div>
        <form id="productRejectForm" method="POST" style="display:flex; flex-direction:column; flex:1 1 auto; min-height:0;">
            @csrf
            <div class="px-6 py-5" style="flex:1 1 auto; min-height:0; overflow-y:auto;">
                <label class="block text-sm font-semibold text-gray-700 mb-2">Reason for Rejection</label>
                <textarea name="reason" rows="3" class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-red-500 focus:border-red-500 resize-none" placeholder="Provide a reason..." required></textarea>
            </div>
            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex items-center justify-end gap-3" style="flex-shrink:0; display:flex; align-items:center; justify-content:flex-end; gap:0.75rem;">
                <button type="button" onclick="closeProductRejectModal()" class="px-5 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-5 py-2.5 text-sm font-medium text-white rounded-xl shadow-sm" style="background-color: #dc2626;" onmouseover="this.style.backgroundColor='#b91c1c'" onmouseout="this.style.backgroundColor='#dc2626'">Reject Transfer</button>
            </div>
        </form>
    </div>
</div>

<!-- Transfer to Store Modal -->
<div id="transferModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 999999; top:0; right:0; bottom:0; left:0; background-color:rgba(0,0,0,0.5); padding:1rem;" onclick="if(event.target===this)closeTransferModal()">
    <div class="w-full max-w-lg bg-white rounded-2xl shadow-2xl text-left overflow-hidden" style="width:100%; max-width:32rem; max-height:90vh; display:flex; flex-direction:column; background:#fff; border-radius:1rem; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.10), 0 10px 10px -5px rgba(0,0,0,0.04);" onclick="event.stopPropagation()">
        <!-- Header -->
        <div class="px-6 py-4 border-b border-gray-100" style="background: linear-gradient(to right, #eff6ff, #eef2ff); flex-shrink:0;">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-blue-100 flex items-center justify-center">
                        <span class="text-xl">🔄</span>
                    </div>
                    <div>
                        <h3 id="transfer-modal-title" class="text-lg font-bold text-gray-900">Transfer to Store</h3>
                        <p class="text-xs text-gray-500 mt-0.5">Move stock from warehouse → store</p>
                    </div>
                </div>
                <button onclick="closeTransferModal()" class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
        <form method="POST" action="{{ route('khaas.warehouse.transfer') }}" style="display:flex; flex-direction:column; flex:1 1 auto; min-height:0;">
            @csrf
            <input type="hidden" name="product_id" id="transfer_product_id">
            <input type="hidden" name="product_variant_id" id="transfer_variant_id">
            <input type="hidden" name="business_unit_id" value="{{ $khaasBU->id }}">
            <div class="px-6 py-4 space-y-4 max-h-[70vh] overflow-y-auto" style="flex:1 1 auto; min-height:0; overflow-y:auto; overscroll-behavior:contain;">
                <!-- Product Info Card -->
                <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-xl border border-gray-100">
                    <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center text-lg font-bold text-blue-700" id="transfer_product_initial">—</div>
                    <div class="flex-1">
                        <div id="transfer_product_name" class="text-sm font-semibold text-gray-900"></div>
                        <div id="transfer_current_qty" class="text-xs text-gray-500 mt-0.5"></div>
                    </div>
                </div>
                <!-- Flow Diagram -->
                <div class="flex items-center justify-center gap-3 py-2">
                    <div class="flex flex-col items-center gap-1">
                        <div class="w-11 h-11 rounded-xl bg-amber-100 flex items-center justify-center text-lg">🏭</div>
                        <span class="text-[10px] font-medium text-gray-600">Warehouse</span>
                    </div>
                    <div class="flex flex-col items-center">
                        <svg class="w-7 h-7 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                        <span class="text-[10px] text-blue-500 font-medium">Transfer</span>
                    </div>
                    <div class="flex flex-col items-center gap-1">
                        <div class="w-11 h-11 rounded-xl bg-blue-100 flex items-center justify-center text-lg">🏪</div>
                        <span class="text-[10px] font-medium text-gray-600">Store</span>
                    </div>
                </div>
                <!-- Info Banner -->
                <div class="flex items-start gap-2 p-2.5 bg-blue-50 rounded-xl border border-blue-100">
                    <span class="text-sm mt-0.5">💡</span>
                    <p class="text-[11px] text-blue-700 leading-relaxed">
                        Stock will be <strong>deducted from warehouse immediately</strong>. A transfer request will be created pending admin approval. Once approved, stock is added to the store.
                    </p>
                </div>
                <!-- Quantity -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Quantity to Transfer</label>
                    <input type="number" name="quantity" id="transfer_qty" min="1" required placeholder="Enter quantity..."
                        class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                    <p class="text-xs text-gray-400 mt-1" id="transfer_max_hint"></p>
                </div>
                <!-- Notes -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Notes <span class="font-normal text-gray-400">(optional)</span></label>
                    <textarea name="notes" rows="2" placeholder="Reason for transfer..."
                        class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors resize-none"></textarea>
                </div>
            </div>
            <!-- Footer -->
            <div class="px-6 py-3 border-t border-gray-100 bg-gray-50 flex items-center justify-end gap-3" style="flex-shrink:0; display:flex; align-items:center; justify-content:flex-end; gap:0.75rem;">
                <button type="button" onclick="closeTransferModal()" class="px-5 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50 transition-colors">Cancel</button>
                <button type="submit" class="px-5 py-2.5 text-sm font-medium text-white rounded-xl shadow-sm transition-colors" style="background-color: #2563eb;" onmouseover="this.style.backgroundColor='#1d4ed8'" onmouseout="this.style.backgroundColor='#2563eb'">
                    🔄 Initiate Transfer
                </button>
            </div>
        </form>
    </div>
</div>
{{-- ⭐ Aug-2026 REQUEST modal — ask the warehouse to send stock. Nothing moves here.
     Doubles as the EDIT modal for the product's existing open request (owner ruling:
     one open request per product, a second ask REPLACES the first), which is why the
     title, button label and prefilled quantity are all set in JS rather than markup.
     ⚠️ Inline-styled shell — see the note on invLogModal below. --}}
<div id="requestModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 999999; top:0; right:0; bottom:0; left:0; background-color:rgba(0,0,0,0.5); padding:1rem;" onclick="if(event.target===this)closeRequestModal()">
    <div class="w-full max-w-lg bg-white rounded-2xl shadow-2xl text-left overflow-hidden" style="width:100%; max-width:32rem; max-height:90vh; display:flex; flex-direction:column; background:#fff; border-radius:1rem; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.10), 0 10px 10px -5px rgba(0,0,0,0.04);" onclick="event.stopPropagation()">
        <div class="px-6 py-4 border-b border-gray-100" style="background: linear-gradient(to right, #eff6ff, #dbeafe); flex-shrink:0;">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center" style="background-color:#dbeafe;"><span class="text-xl">📨</span></div>
                    <div>
                        <h3 id="request_modal_title" class="text-lg font-bold text-gray-900">Request from Warehouse</h3>
                        <p class="text-xs text-gray-500 mt-0.5">Ask the warehouse to send stock to the store</p>
                    </div>
                </div>
                <button onclick="closeRequestModal()" class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
        {{-- ⚠⚠ THE FORM MUST BE THE FLEX COLUMN, NOT A PLAIN BLOCK.
             The shell above is `display:flex; flex-direction:column; max-height:90vh;
             overflow:hidden`, and its only sizeable child is this form. Without
             these four declarations the form stays `display:block` and sizes to its
             CONTENT, so the scroll body's `flex:1 1 auto; overflow-y:auto` had nothing
             to size against: the body never scrolled, the form overflowed the shell,
             and `overflow:hidden` clipped the footer — putting "Send request" below
             the fold on any short window. Measured on a 620px-tall viewport: form
             bottom 640 vs viewport 620, button bottom 627, body scrollable = false.
             Users worked around it by zooming out; the modal looked "stuck". --}}
        <form method="POST" action="{{ route('khaas.transfer-requests.create') }}"
              style="display:flex; flex-direction:column; flex:1 1 auto; min-height:0;">
            @csrf
            <input type="hidden" name="product_id" id="request_product_id">
            <input type="hidden" name="product_variant_id" id="request_variant_id">
            <div class="px-6 py-4 space-y-4" style="flex:1 1 auto; min-height:0; overflow-y:auto; overscroll-behavior:contain;">
                <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-xl border border-gray-100">
                    <div class="w-10 h-10 rounded-lg flex items-center justify-center text-lg font-bold" style="background-color:#dbeafe; color:#1d4ed8;" id="request_product_initial">—</div>
                    <div class="flex-1">
                        <div id="request_product_name" class="text-sm font-semibold text-gray-900"></div>
                        <div id="request_warehouse_hint" class="text-xs text-gray-500 mt-0.5"></div>
                    </div>
                </div>

                {{-- The four numbers behind the suggestion, so the manager can sanity-check
                     it instead of trusting a black box. --}}
                <div class="grid grid-cols-4 gap-2" id="request_context_strip">
                    <div class="rounded-lg px-2 py-2 text-center" style="background-color:#faf5ff; border:1px solid #e9d5ff;">
                        <div class="text-[10px]" style="color:#7e22ce;">Pending orders</div>
                        <div class="text-sm font-bold" style="color:#6b21a8;" id="request_ctx_demand">0</div>
                    </div>
                    <div class="rounded-lg px-2 py-2 text-center" style="background-color:#eff6ff; border:1px solid #bfdbfe;">
                        <div class="text-[10px]" style="color:#2563eb;">In store</div>
                        <div class="text-sm font-bold" style="color:#1e40af;" id="request_ctx_store">0</div>
                    </div>
                    <div class="rounded-lg px-2 py-2 text-center" style="background-color:#fffbeb; border:1px solid #fde68a;">
                        <div class="text-[10px]" style="color:#b45309;">In transit</div>
                        <div class="text-sm font-bold" style="color:#92400e;" id="request_ctx_transit">0</div>
                    </div>
                    <div class="rounded-lg px-2 py-2 text-center" style="background-color:#f0fdf4; border:1px solid #bbf7d0;">
                        <div class="text-[10px]" style="color:#15803d;">Suggested</div>
                        <div class="text-sm font-bold" style="color:#166534;" id="request_ctx_short">0</div>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Quantity to request</label>
                    <input type="number" name="quantity" id="request_qty" min="1" required placeholder="Enter quantity..."
                        class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                    <p class="text-xs text-gray-400 mt-1" id="request_qty_hint"></p>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Note <span class="font-normal text-gray-400">(optional)</span></label>
                    <textarea name="notes" id="request_notes" rows="2" placeholder="e.g. needed for tomorrow morning..."
                        class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors resize-none"></textarea>
                </div>

                <div class="flex items-start gap-2 p-2.5 rounded-xl" style="background-color:#eff6ff; border:1px solid #dbeafe;">
                    <span class="text-sm mt-0.5">💡</span>
                    <p class="text-[11px] leading-relaxed" style="color:#1d4ed8;">
                        <strong>No stock moves yet.</strong> The warehouse sees this request and can send the
                        full amount, a smaller amount, or decline it. Stock only leaves the warehouse when they accept.
                    </p>
                </div>
            </div>
            <div class="px-6 py-3 border-t border-gray-100 bg-gray-50 flex items-center justify-end gap-3" style="flex-shrink:0; display:flex; align-items:center; justify-content:flex-end; gap:0.75rem;">
                <button type="button" onclick="closeRequestModal()" class="px-5 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50 transition-colors">Cancel</button>
                <button type="submit" id="request_submit_btn" class="px-5 py-2.5 text-sm font-medium text-white rounded-xl shadow-sm transition-colors" style="background-color: #2563eb;" onmouseover="this.style.backgroundColor='#1d4ed8'" onmouseout="this.style.backgroundColor='#2563eb'">
                    📨 Send request
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Accept a request: the ONE place a transfer quantity can be edited. Safe precisely
     because nothing has moved yet — once the transfer exists the quantity is locked. --}}
<div id="acceptRequestModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 999999; top:0; right:0; bottom:0; left:0; background-color:rgba(0,0,0,0.5); padding:1rem;" onclick="if(event.target===this)closeAcceptRequestModal()">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-2xl text-left overflow-hidden" style="width:100%; max-width:28rem; max-height:90vh; display:flex; flex-direction:column; background:#fff; border-radius:1rem; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.10), 0 10px 10px -5px rgba(0,0,0,0.04);" onclick="event.stopPropagation()">
        <div class="px-6 py-4 border-b border-gray-100" style="background: linear-gradient(to right, #f0fdf4, #dcfce7); flex-shrink:0;">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center" style="background-color:#dcfce7;"><span class="text-xl">✅</span></div>
                <div>
                    <h3 class="text-lg font-bold text-gray-900">Send Stock to Store</h3>
                    <p class="text-xs text-gray-500" id="acceptRequestInfo"></p>
                </div>
            </div>
        </div>
        <form id="acceptRequestForm" method="POST" style="display:flex; flex-direction:column; flex:1 1 auto; min-height:0;">
            @csrf
            <div class="px-6 py-5 space-y-4" style="flex:1 1 auto; min-height:0; overflow-y:auto;">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Quantity to send</label>
                    <input type="number" name="quantity" id="acceptRequestQty" min="1" required
                        class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500 transition-colors">
                    <p class="text-xs text-gray-400 mt-1" id="acceptRequestHint"></p>
                </div>
                <div class="flex items-start gap-2 p-2.5 rounded-xl" style="background-color:#fffbeb; border:1px solid #fde68a;">
                    <span class="text-sm mt-0.5">⚠️</span>
                    <p class="text-[11px] leading-relaxed" style="color:#92400e;">
                        This <strong>deducts stock from the warehouse now</strong> and creates a transfer.
                        The store still has to confirm receipt before it becomes shop stock.
                    </p>
                </div>
            </div>
            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex items-center justify-end gap-3" style="flex-shrink:0; display:flex; align-items:center; justify-content:flex-end; gap:0.75rem;">
                <button type="button" onclick="closeAcceptRequestModal()" class="px-5 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-5 py-2.5 text-sm font-medium text-white rounded-xl shadow-sm" style="background-color: #16a34a;" onmouseover="this.style.backgroundColor='#15803d'" onmouseout="this.style.backgroundColor='#16a34a'">✓ Send stock</button>
            </div>
        </form>
    </div>
</div>

{{-- Decline a request. Touches no stock — it only stamps the row. --}}
<div id="declineRequestModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 999999; top:0; right:0; bottom:0; left:0; background-color:rgba(0,0,0,0.5); padding:1rem;" onclick="if(event.target===this)closeDeclineRequestModal()">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-2xl text-left overflow-hidden" style="width:100%; max-width:28rem; max-height:90vh; display:flex; flex-direction:column; background:#fff; border-radius:1rem; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.10), 0 10px 10px -5px rgba(0,0,0,0.04);" onclick="event.stopPropagation()">
        <div class="px-6 py-4 border-b border-gray-100" style="background: linear-gradient(to right, #fef2f2, #fee2e2); flex-shrink:0;">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-red-100 flex items-center justify-center text-xl">✕</div>
                <div>
                    <h3 class="text-lg font-bold text-gray-900">Decline Request</h3>
                    <p class="text-xs text-gray-500" id="declineRequestInfo"></p>
                </div>
            </div>
        </div>
        <form id="declineRequestForm" method="POST" style="display:flex; flex-direction:column; flex:1 1 auto; min-height:0;">
            @csrf
            <div class="px-6 py-5" style="flex:1 1 auto; min-height:0; overflow-y:auto;">
                <label class="block text-sm font-semibold text-gray-700 mb-2">Reason</label>
                {{-- Preset buttons, because a reason typed at 6am is usually one of three
                     things and an empty box gets skipped. Free text still allowed. --}}
                <div class="flex flex-wrap gap-2 mb-2">
                    <button type="button" onclick="setDeclineReason('Not enough stock in warehouse')" class="px-2.5 py-1 text-xs rounded-lg" style="background-color:#f3f4f6; color:#374151; border:1px solid #e5e7eb;">Not enough stock</button>
                    <button type="button" onclick="setDeclineReason('Will send later today')" class="px-2.5 py-1 text-xs rounded-lg" style="background-color:#f3f4f6; color:#374151; border:1px solid #e5e7eb;">Will send later</button>
                    <button type="button" onclick="setDeclineReason('Still in production')" class="px-2.5 py-1 text-xs rounded-lg" style="background-color:#f3f4f6; color:#374151; border:1px solid #e5e7eb;">Still in production</button>
                </div>
                <textarea name="reason" id="declineRequestReason" rows="3" class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-red-500 focus:border-red-500 resize-none" placeholder="Tell the store why..." required></textarea>
            </div>
            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex items-center justify-end gap-3" style="flex-shrink:0; display:flex; align-items:center; justify-content:flex-end; gap:0.75rem;">
                <button type="button" onclick="closeDeclineRequestModal()" class="px-5 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-5 py-2.5 text-sm font-medium text-white rounded-xl shadow-sm" style="background-color: #dc2626;" onmouseover="this.style.backgroundColor='#b91c1c'" onmouseout="this.style.backgroundColor='#dc2626'">Decline</button>
            </div>
        </form>
    </div>
</div>

{{-- The orders behind the "Pending orders" number on a card. Read-only. --}}
<div id="pendingOrdersModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 999999; top:0; right:0; bottom:0; left:0; background-color:rgba(0,0,0,0.5); padding:1rem;" onclick="if(event.target===this)closePendingOrdersModal()">
    <div class="w-full max-w-xl bg-white rounded-2xl shadow-2xl text-left overflow-hidden" style="width:100%; max-width:36rem; max-height:85vh; display:flex; flex-direction:column; background:#fff; border-radius:1rem; box-shadow:0 20px 25px -5px rgba(0,0,0,0.10), 0 10px 10px -5px rgba(0,0,0,0.04);" onclick="event.stopPropagation()">
        <div class="px-6 py-4 border-b border-gray-100" style="background: linear-gradient(to right, #faf5ff, #f3e8ff); flex-shrink:0;">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center" style="background-color:#f3e8ff;"><span class="text-xl">🛒</span></div>
                    <div>
                        <h3 id="pendingOrdersTitle" class="text-base font-bold text-gray-900">Pending Orders</h3>
                        <p id="pendingOrdersSubtitle" class="text-xs text-gray-500 mt-0.5">Orders still waiting on this product</p>
                    </div>
                </div>
                <button onclick="closePendingOrdersModal()" class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
        <div id="pendingOrdersBody" class="px-0 py-0" style="flex:1 1 auto; min-height:0; overflow-y:auto; overscroll-behavior:contain;"></div>
        <div class="px-6 py-3 border-t border-gray-100 bg-gray-50 flex items-center justify-between" style="flex-shrink:0;">
            <span id="pendingOrdersFooter" class="text-xs text-gray-500"></span>
            <button onclick="closePendingOrdersModal()" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50">Close</button>
        </div>
    </div>
</div>

{{-- Inventory Transaction Log Modal — shared by BOTH the Store tile and the Warehouse tile.
     One modal, two modes (see openStoreLogModal / openWarehouseLogModal below).
     ⚠️ The shell is inline-styled on purpose: the purged styles.css drops inset-0 / max-h /
     max-w / overflow-y-auto / flex-shrink-0, which makes class-only modal shells render in
     the top-left corner and refuse to scroll. Do not "clean this up" into classes. --}}
<div id="invLogModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 999999; top:0; right:0; bottom:0; left:0; background-color:rgba(0,0,0,0.5); padding:1rem;" onclick="if(event.target===this)closeInvLogModal()">
    <div class="w-full max-w-xl bg-white rounded-2xl shadow-2xl text-left overflow-hidden" style="width:100%; max-width:36rem; max-height:85vh; display:flex; flex-direction:column; background:#fff; border-radius:1rem; box-shadow:0 20px 25px -5px rgba(0,0,0,0.10), 0 10px 10px -5px rgba(0,0,0,0.04);" onclick="event.stopPropagation()">
        <!-- Header (fixed) -->
        <div id="inv-log-header" class="px-6 py-4 border-b border-gray-100" style="background: linear-gradient(to right, #eff6ff, #e0f2fe); flex-shrink:0; padding:1rem 1.5rem;">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div id="inv-log-icon-wrap" class="w-10 h-10 rounded-xl flex items-center justify-center" style="background-color:#dbeafe;">
                        <span id="inv-log-icon" class="text-xl">📋</span>
                    </div>
                    <div>
                        <h3 id="inv-log-title" class="text-lg font-bold text-gray-900">Inventory Log</h3>
                        <p class="text-xs text-gray-500 mt-0.5">
                            Current balance: <span id="inv-log-balance" class="font-bold" style="color:#1d4ed8;">—</span> <span id="inv-log-unit">units</span>
                        </p>
                    </div>
                </div>
                <button onclick="closeInvLogModal()" class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <!-- Type filter chips (warehouse mode only; hidden for the store log) -->
            <div id="inv-log-filters" class="flex flex-wrap items-center gap-1.5 mt-3" style="display:none;"></div>
        </div>
        <!-- Body (scrolls; header & footer stay pinned) -->
        <div id="inv-log-body" style="flex:1 1 auto; min-height:0; overflow-y:auto; overscroll-behavior:contain;">
            <div class="flex items-center justify-center py-12 text-gray-400">
                <svg class="animate-spin w-6 h-6 mr-2" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                Loading transactions...
            </div>
        </div>
        <!-- Footer (fixed) -->
        <div class="px-6 py-3 border-t border-gray-100 bg-gray-50 flex items-center justify-between gap-3" style="flex-shrink:0; padding:0.75rem 1.5rem;">
            <p id="inv-log-footnote" class="text-[10px] text-gray-400">Showing recent transactions</p>
            <div class="flex items-center gap-2">
                <button id="inv-log-more" onclick="loadMoreInvLog()" class="px-3 py-2 text-sm font-medium rounded-lg transition-colors" style="display:none; background-color:#f3f4f6; color:#374151; border:1px solid #d1d5db;">Load more</button>
                <button onclick="closeInvLogModal()" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">Close</button>
            </div>
        </div>
    </div>
</div>
@endpush

@push('demo1_js')
<script>
function openStockModal(productId, productName, variantId, currentQty) {
    document.getElementById('stock_product_id').value = productId;
    document.getElementById('stock_variant_id').value = variantId || '';
    document.getElementById('stock_product_name').textContent = productName;
    document.getElementById('stock_product_initial').textContent = productName.charAt(0).toUpperCase();
    document.getElementById('stock_current_qty').textContent = 'Current warehouse stock: ' + currentQty + ' units';
    // Reset radio to stock_in
    var stockInRadio = document.querySelector('input[name="change_type"][value="stock_in"]');
    if (stockInRadio) stockInRadio.checked = true;
    // Reset the correction direction so a previous "Reduce" can't leak into the next open.
    var stockDirAdd = document.querySelector('#stockModal input[name="adjust_direction"][value="add"]');
    if (stockDirAdd) stockDirAdd.checked = true;
    updateStockDirStyles();
    updateStockRadioStyles();
    var modal = document.getElementById('stockModal');
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function closeStockModal() {
    var modal = document.getElementById('stockModal');
    modal.classList.add('hidden');
    document.body.style.overflow = '';
}
function openTransferModal(productId, productName, variantId, currentQty) {
    document.getElementById('transfer_product_id').value = productId;
    document.getElementById('transfer_variant_id').value = variantId || '';
    document.getElementById('transfer_product_name').textContent = productName;
    document.getElementById('transfer_product_initial').textContent = productName.charAt(0).toUpperCase();
    document.getElementById('transfer_current_qty').textContent = 'Available warehouse stock: ' + currentQty + ' units';
    document.getElementById('transfer_qty').max = currentQty;
    document.getElementById('transfer_max_hint').textContent = 'Maximum: ' + currentQty + ' units available';
    var modal = document.getElementById('transferModal');
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function closeTransferModal() {
    var modal = document.getElementById('transferModal');
    modal.classList.add('hidden');
    document.body.style.overflow = '';
}

// ═══ Aug-2026 TRANSFER REQUESTS ═══════════════════════════════════════════
// One modal serves both "ask" and "change the ask": there is at most one open
// request per product (enforced in the DB), so a second Request on the same
// product must edit the first rather than queue a rival row.
function openRequestModal(productId, productName, variantId, warehouseQty, demandTotal, storeQty, transitQty, shortfall, existingQty) {
    document.getElementById('request_product_id').value = productId;
    document.getElementById('request_variant_id').value = variantId || '';
    document.getElementById('request_product_name').textContent = productName;
    document.getElementById('request_product_initial').textContent = productName.charAt(0).toUpperCase();
    document.getElementById('request_warehouse_hint').textContent = 'Warehouse currently holds ' + warehouseQty + ' units';

    document.getElementById('request_ctx_demand').textContent = demandTotal;
    document.getElementById('request_ctx_store').textContent = storeQty;
    document.getElementById('request_ctx_transit').textContent = transitQty;
    document.getElementById('request_ctx_short').textContent = shortfall;

    var isEdit = existingQty > 0;
    document.getElementById('request_modal_title').textContent = isEdit ? 'Change Request' : 'Request from Warehouse';
    document.getElementById('request_submit_btn').innerHTML = isEdit ? '📨 Update request' : '📨 Send request';

    // Prefill: the current ask when editing, otherwise the shortfall. Deliberately NOT
    // capped to warehouse stock — asking for more than is on hand is legitimate (the
    // warehouse can send what it has and produce the rest), and silently shrinking the
    // number would hide the real need.
    var prefill = isEdit ? existingQty : (shortfall > 0 ? shortfall : '');
    document.getElementById('request_qty').value = prefill;
    document.getElementById('request_notes').value = '';

    var hint = document.getElementById('request_qty_hint');
    if (isEdit) {
        hint.textContent = 'Currently requested: ' + existingQty + ' units. Saving replaces that request.';
    } else if (shortfall > 0) {
        hint.textContent = 'Suggested ' + shortfall + ' = ' + demandTotal + ' pending orders − ' + storeQty + ' in store − ' + transitQty + ' in transit.';
    } else if (demandTotal > 0) {
        hint.textContent = 'The store can already cover its ' + demandTotal + ' pending units.';
    } else {
        hint.textContent = 'No pending orders for this product right now.';
    }

    document.getElementById('requestModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function closeRequestModal() {
    document.getElementById('requestModal').classList.add('hidden');
    document.body.style.overflow = '';
}

function openAcceptRequestModal(requestId, productName, askedQty, warehouseQty) {
    var form = document.getElementById('acceptRequestForm');
    form.action = '{{ url("khaas/transfer-requests") }}/' + requestId + '/accept';
    document.getElementById('acceptRequestInfo').textContent = productName + ' — asked for ' + askedQty;
    var qty = document.getElementById('acceptRequestQty');
    qty.value = askedQty;
    qty.max = warehouseQty;
    document.getElementById('acceptRequestHint').textContent =
        warehouseQty < askedQty
            ? '⚠ Warehouse only has ' + warehouseQty + '. Send what you can, or decline.'
            : 'Warehouse has ' + warehouseQty + '. Change this if you are sending a different amount.';
    document.getElementById('acceptRequestModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function closeAcceptRequestModal() {
    document.getElementById('acceptRequestModal').classList.add('hidden');
    document.body.style.overflow = '';
}

function openDeclineRequestModal(requestId, productName, askedQty) {
    var form = document.getElementById('declineRequestForm');
    form.action = '{{ url("khaas/transfer-requests") }}/' + requestId + '/decline';
    document.getElementById('declineRequestInfo').textContent = productName + ' — asked for ' + askedQty;
    document.getElementById('declineRequestReason').value = '';
    document.getElementById('declineRequestModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function closeDeclineRequestModal() {
    document.getElementById('declineRequestModal').classList.add('hidden');
    document.body.style.overflow = '';
}
function setDeclineReason(text) {
    document.getElementById('declineRequestReason').value = text;
}

// ═══ Pending-orders breakdown ═════════════════════════════════════════════
// Lists exactly the rows the card number counted, so the total always reconciles.
function openPendingOrdersModal(productId, productName) {
    var modal = document.getElementById('pendingOrdersModal');
    var body = document.getElementById('pendingOrdersBody');
    document.getElementById('pendingOrdersTitle').textContent = productName;
    document.getElementById('pendingOrdersSubtitle').textContent = 'Orders still waiting on this product';
    document.getElementById('pendingOrdersFooter').textContent = '';
    body.innerHTML = '<div class="px-6 py-10 text-center text-sm text-gray-400">Loading…</div>';
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    fetch('{{ url("khaas/products") }}/' + productId + '/pending-orders', { headers: { 'Accept': 'application/json' } })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d || !d.success) {
                body.innerHTML = '<div class="px-6 py-10 text-center text-sm text-gray-400">Could not load orders.</div>';
                return;
            }
            var html = '';
            var open = d.open || [];
            var acc = open.filter(function (r) { return r.bucket !== 'pending'; });
            var later = open.filter(function (r) { return r.bucket === 'pending'; });
            html += renderPendingOrderSection('✅ Accepted', 'Open, not yet prepared', acc, d.accepted_total != null ? d.accepted_total : acc.reduce(function (s, r) { return s + r.qty; }, 0), '#eff6ff', '#1e40af');
            html += renderPendingOrderSection('🗓 Later day', 'Accepted for delivery on a later day (pending)', later, d.pending_total != null ? d.pending_total : later.reduce(function (s, r) { return s + r.qty; }, 0), '#f0fdf4', '#166534');
            html += renderPendingOrderSection('⏳ Shopify approval queue', 'Not accepted yet — not counted in the total', d.shopify, d.shopify_total, '#faf5ff', '#6b21a8');
            if (!d.total && !d.shopify_total) {
                html = '<div class="px-6 py-10 text-center text-sm text-gray-400">Nothing pending for this product.</div>';
            }
            body.innerHTML = html;
            document.getElementById('pendingOrdersFooter').textContent =
                'Open orders: ' + d.total + ' units · already-prepared items are excluded' + (d.shopify_total ? ' · Shopify queue ' + d.shopify_total + ' not counted' : '');
        })
        .catch(function() {
            body.innerHTML = '<div class="px-6 py-10 text-center text-sm text-gray-400">Could not load orders.</div>';
        });
}
function renderPendingOrderSection(title, subtitle, rows, total, bg, color) {
    if (!rows || !rows.length) return '';
    var html = '<div class="px-6 py-2 flex items-center justify-between" style="background-color:' + bg + ';">'
             + '<div><span class="text-xs font-bold" style="color:' + color + ';">' + title + '</span>'
             + '<span class="text-[10px] text-gray-500 ml-2">' + subtitle + '</span></div>'
             + '<span class="text-xs font-bold" style="color:' + color + ';">' + total + ' units</span></div>';
    rows.forEach(function(r) {
        // An order sitting unfulfilled for weeks is usually stuck, not real demand —
        // flag it so a high number can be explained rather than blindly requested.
        var stale = r.age_days >= 14
            ? '<span class="text-[10px] px-1 py-0.5 rounded ml-1" style="background-color:#fee2e2; color:#991b1b;">⚠ ' + r.age_days + 'd old</span>'
            : '';
        var status = r.status
            ? '<span class="text-[10px] px-1.5 py-0.5 rounded ml-1" style="background-color:#f3f4f6; color:#4b5563;">' + escInvLog(r.status) + '</span>'
            : '';
        html += '<div class="px-6 py-2.5 border-b border-gray-50 flex items-center justify-between gap-3">'
              + '<div class="min-w-0 flex-1">'
              + '<div class="text-xs font-semibold text-gray-800 truncate">' + escInvLog(r.order_number) + status + stale + '</div>'
              + '<div class="text-[10px] text-gray-400 mt-0.5">' + escInvLog(r.customer_name) + ' · ' + escInvLog(r.date) + '</div>'
              + '</div>'
              + '<span class="text-sm font-bold shrink-0" style="color:' + color + ';">' + r.qty + '</span>'
              + '</div>';
    });
    return html;
}
function closePendingOrdersModal() {
    document.getElementById('pendingOrdersModal').classList.add('hidden');
    document.body.style.overflow = '';
}
function updateStockRadioStyles() {
    var labels = ['stock_in', 'stock_out', 'count', 'adjustment'];
    var selected = null;
    labels.forEach(function(val) {
        var label = document.getElementById('stock_radio_' + val);
        var radio = label ? label.querySelector('input[type="radio"]') : null;
        if (label && radio) {
            if (radio.checked) {
                selected = val;
                label.style.borderColor = '#f59e0b';
                label.style.backgroundColor = '#fffbeb';
            } else {
                label.style.borderColor = '#e5e7eb';
                label.style.backgroundColor = '';
            }
        }
    });
    // The Add/Reduce direction only applies to a correction; the server ignores the field for
    // every other action, but hiding it keeps the form honest.
    var wrap = document.getElementById('stock_adjust_direction_wrap');
    if (wrap) wrap.style.display = (selected === 'adjustment') ? 'block' : 'none';
}

function updateStockDirStyles() {
    ['add', 'reduce'].forEach(function(dir) {
        var label = document.getElementById('stock_dir_' + dir);
        var radio = label ? label.querySelector('input[type="radio"]') : null;
        if (label && radio) {
            if (radio.checked) {
                label.style.borderColor = dir === 'reduce' ? '#ea580c' : '#f59e0b';
                label.style.backgroundColor = dir === 'reduce' ? '#fff7ed' : '#fffbeb';
            } else {
                label.style.borderColor = '#e5e7eb';
                label.style.backgroundColor = '';
            }
        }
    });
}

// === Store Stock Adjust Modal ===
function openStoreStockModal(productId, productName, variantId, currentQty) {
    document.getElementById('store_stock_product_id').value = productId;
    document.getElementById('store_stock_variant_id').value = variantId || '';
    document.getElementById('store_stock_product_name').textContent = productName;
    document.getElementById('store_stock_product_initial').textContent = productName.charAt(0).toUpperCase();
    document.getElementById('store_stock_current_qty').textContent = 'Current store stock: ' + currentQty + ' units';
    // Reset radio to store_stock_in
    var storeStockInRadio = document.querySelector('#storeStockModal input[name="change_type"][value="store_stock_in"]');
    if (storeStockInRadio) storeStockInRadio.checked = true;
    var storeDirAdd = document.querySelector('#storeStockModal input[name="adjust_direction"][value="add"]');
    if (storeDirAdd) storeDirAdd.checked = true;
    updateStoreDirStyles();
    updateStoreStockRadioStyles();
    var modal = document.getElementById('storeStockModal');
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function closeStoreStockModal() {
    var modal = document.getElementById('storeStockModal');
    modal.classList.add('hidden');
    document.body.style.overflow = '';
}
function updateStoreStockRadioStyles() {
    var labels = ['store_stock_in', 'store_stock_out', 'store_count', 'store_adjustment'];
    var selected = null;
    labels.forEach(function(val) {
        var label = document.getElementById('store_stock_radio_' + val);
        var radio = label ? label.querySelector('input[type="radio"]') : null;
        if (label && radio) {
            if (radio.checked) {
                selected = val;
                label.style.borderColor = '#3b82f6';
                label.style.backgroundColor = '#eff6ff';
            } else {
                label.style.borderColor = '#e5e7eb';
                label.style.backgroundColor = '';
            }
        }
    });
    var wrap = document.getElementById('store_adjust_direction_wrap');
    if (wrap) wrap.style.display = (selected === 'store_adjustment') ? 'block' : 'none';
}

function updateStoreDirStyles() {
    ['add', 'reduce'].forEach(function(dir) {
        var label = document.getElementById('store_dir_' + dir);
        var radio = label ? label.querySelector('input[type="radio"]') : null;
        if (label && radio) {
            if (radio.checked) {
                label.style.borderColor = dir === 'reduce' ? '#ea580c' : '#3b82f6';
                label.style.backgroundColor = dir === 'reduce' ? '#fff7ed' : '#eff6ff';
            } else {
                label.style.borderColor = '#e5e7eb';
                label.style.backgroundColor = '';
            }
        }
    });
}

// === Reject Transfer Modal (for pending approvals on products page) ===
function openProductRejectModal(transferId, productName, quantity) {
    document.getElementById('productRejectModalInfo').textContent = quantity + ' units of "' + productName + '" — stock returns to warehouse';
    document.getElementById('productRejectForm').action = '{{ url("khaas/transfers") }}/' + transferId + '/reject';
    document.getElementById('productRejectModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function closeProductRejectModal() {
    document.getElementById('productRejectModal').classList.add('hidden');
    document.body.style.overflow = '';
}

// === Approve Transfer Modal (records WHO COUNTED the stock) ===
function openProductApproveModal(transferId, productName, quantity) {
    document.getElementById('productApproveModalInfo').textContent = quantity + ' units of "' + productName + '" — stock moves into the shop';
    document.getElementById('productApproveForm').action = '{{ url("khaas/transfers") }}/' + transferId + '/approve';
    // Reset to the default (me) each time so a pick made for one transfer
    // doesn't quietly carry over to the next one approved in this session.
    var sel = document.getElementById('productApproveCountedBy');
    if (sel) sel.value = '{{ auth()->id() }}';
    document.getElementById('productApproveModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function closeProductApproveModal() {
    document.getElementById('productApproveModal').classList.add('hidden');
    document.body.style.overflow = '';
}

// ═══════════════════════════════════════════════════════════════
// ⭐ Inventory Transaction Log — ONE implementation, TWO modes
//
//   Store mode     → /khaas/products/{id}/store-log      (reconstructed history)
//   Warehouse mode → /khaas/products/{id}/warehouse-log  (real ledger, pageable)
//
// Both endpoints return the same `events` shape (label / change / detail / sub_detail /
// balance_before / balance_after / date_day / date_day_label / count_value), so the day
// grouping and row rendering below are shared. Day open/close is computed CLIENT-side from
// the flat event list so that "Load more" keeps the day totals correct across pages.
// ═══════════════════════════════════════════════════════════════

var invLog = {
    mode: 'store',
    productId: null,
    productName: '',
    events: [],
    currentQty: 0,
    unit: 'units',
    typeFilter: '',
    nextBeforeId: null,
    hasMore: false,
    loading: false
};

var INV_LOG_MODES = {
    store: {
        title: 'Store Log',
        icon: '🏪',
        iconBg: '#dbeafe',
        headerBg: 'linear-gradient(to right, #eff6ff, #e0f2fe)',
        accent: '#1d4ed8',
        balanceLabel: 'Current Store Balance',
        balanceBg: '#eff6ff',
        balanceText: '#1d4ed8',
        footnote: 'Reconstructed from transfers, order deductions and manual store adjustments',
        showFilters: false,
        emptyText: 'No store inventory transactions found yet.',
        legend: [
            ['#22c55e', 'Transfer In'],
            ['#ef4444', 'Order Deduction'],
            ['#3b82f6', 'Cancelled Restore'],
            ['#8b5cf6', 'Manual Adjust']
        ]
    },
    warehouse: {
        title: 'Warehouse Ledger',
        icon: '🏭',
        iconBg: '#fef3c7',
        headerBg: 'linear-gradient(to right, #fffbeb, #fff7ed)',
        accent: '#b45309',
        balanceLabel: 'Current Warehouse Balance',
        balanceBg: '#fffbeb',
        balanceText: '#92400e',
        footnote: 'Complete in/out from the warehouse ledger — every movement, with who made it',
        showFilters: true,
        emptyText: 'No warehouse movements recorded for this product yet.',
        legend: [
            ['#22c55e', 'Stock In'],
            ['#14b8a6', 'Batch Production'],
            ['#ef4444', 'Stock Out'],
            ['#f59e0b', 'Transfer to Store'],
            ['#3b82f6', 'Rejected Return'],
            ['#8b5cf6', 'Count'],
            ['#f97316', 'Adjustment']
        ]
    }
};

var INV_LOG_FILTERS = [
    {key: '', label: 'All'},
    {key: 'stock_in', label: '📥 In'},
    {key: 'stock_out', label: '📤 Out'},
    {key: 'transfer', label: '🔄 Transfers'},
    {key: 'count', label: '📊 Counts'},
    {key: 'adjustment', label: '🔧 Adjustments'}
];

function openStoreLogModal(productId, productName, currentQty) {
    openInvLogModal('store', productId, productName, currentQty);
}

function openWarehouseLogModal(productId, productName, currentQty) {
    openInvLogModal('warehouse', productId, productName, currentQty);
}

function openInvLogModal(mode, productId, productName, currentQty) {
    var cfg = INV_LOG_MODES[mode];
    invLog.mode = mode;
    invLog.productId = productId;
    invLog.productName = productName;
    invLog.events = [];
    invLog.currentQty = currentQty;
    invLog.unit = 'units';
    invLog.typeFilter = '';
    invLog.nextBeforeId = null;
    invLog.hasMore = false;

    document.getElementById('inv-log-title').textContent = productName + ' — ' + cfg.title;
    document.getElementById('inv-log-balance').textContent = currentQty;
    document.getElementById('inv-log-balance').style.color = cfg.balanceText;
    document.getElementById('inv-log-unit').textContent = 'units';
    document.getElementById('inv-log-icon').textContent = cfg.icon;
    document.getElementById('inv-log-icon-wrap').style.backgroundColor = cfg.iconBg;
    document.getElementById('inv-log-header').style.background = cfg.headerBg;
    document.getElementById('inv-log-footnote').textContent = cfg.footnote;
    document.getElementById('inv-log-more').style.display = 'none';

    // Filter chips only make sense for the ledger-backed warehouse view.
    var filterWrap = document.getElementById('inv-log-filters');
    if (cfg.showFilters) {
        filterWrap.innerHTML = INV_LOG_FILTERS.map(function(f) {
            var active = f.key === invLog.typeFilter;
            return '<button type="button" onclick="setInvLogFilter(\'' + f.key + '\')" '
                + 'class="px-2 py-1 rounded-full text-[11px] font-medium transition-colors" '
                + 'style="' + (active
                    ? 'background-color:#d97706; color:#fff; border:1px solid #d97706;'
                    : 'background-color:#fff; color:#92400e; border:1px solid #fde68a;') + '">'
                + f.label + '</button>';
        }).join('');
        filterWrap.style.display = 'flex';
    } else {
        filterWrap.innerHTML = '';
        filterWrap.style.display = 'none';
    }

    showInvLogLoading();
    document.getElementById('invLogModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    fetchInvLog(false);
}

function closeInvLogModal() {
    document.getElementById('invLogModal').classList.add('hidden');
    document.body.style.overflow = '';
}

function showInvLogLoading() {
    document.getElementById('inv-log-body').innerHTML =
        '<div class="flex items-center justify-center py-12 text-gray-400">' +
        '<svg class="animate-spin w-6 h-6 mr-2" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>' +
        'Loading transactions...</div>';
}

function setInvLogFilter(key) {
    if (invLog.loading || key === invLog.typeFilter) return;
    invLog.typeFilter = key;
    invLog.events = [];
    invLog.nextBeforeId = null;
    invLog.hasMore = false;

    // Repaint chips
    var filterWrap = document.getElementById('inv-log-filters');
    filterWrap.innerHTML = INV_LOG_FILTERS.map(function(f) {
        var active = f.key === invLog.typeFilter;
        return '<button type="button" onclick="setInvLogFilter(\'' + f.key + '\')" '
            + 'class="px-2 py-1 rounded-full text-[11px] font-medium transition-colors" '
            + 'style="' + (active
                ? 'background-color:#d97706; color:#fff; border:1px solid #d97706;'
                : 'background-color:#fff; color:#92400e; border:1px solid #fde68a;') + '">'
            + f.label + '</button>';
    }).join('');

    showInvLogLoading();
    fetchInvLog(false);
}

function loadMoreInvLog() {
    if (invLog.loading || !invLog.hasMore) return;
    var btn = document.getElementById('inv-log-more');
    btn.textContent = 'Loading…';
    btn.disabled = true;
    fetchInvLog(true);
}

function fetchInvLog(append) {
    invLog.loading = true;

    var base = '{{ url("khaas/products") }}/' + invLog.productId +
        (invLog.mode === 'warehouse' ? '/warehouse-log' : '/store-log');
    var params = ['limit=30'];
    if (invLog.mode === 'warehouse') {
        if (invLog.typeFilter) params.push('type=' + encodeURIComponent(invLog.typeFilter));
        if (append && invLog.nextBeforeId) params.push('before_id=' + encodeURIComponent(invLog.nextBeforeId));
    }

    fetch(base + '?' + params.join('&'))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            invLog.loading = false;
            if (!data.success) {
                if (!append) renderInvLogError(data.message);
                return;
            }

            var incoming = data.events || [];
            invLog.events = append ? invLog.events.concat(incoming) : incoming;
            // The store endpoint has no cursor — it always returns its full window.
            invLog.hasMore = invLog.mode === 'warehouse' ? !!data.has_more : false;
            invLog.nextBeforeId = data.next_before_id || null;
            invLog.currentQty = (invLog.mode === 'warehouse')
                ? (data.current_warehouse_qty != null ? data.current_warehouse_qty : invLog.currentQty)
                : (data.current_store_qty != null ? data.current_store_qty : invLog.currentQty);
            if (data.unit) {
                invLog.unit = data.unit;
                document.getElementById('inv-log-unit').textContent = data.unit;
            }
            document.getElementById('inv-log-balance').textContent = invLog.currentQty;

            renderInvLog(data.multi_row_warning === true);
        })
        .catch(function(err) {
            invLog.loading = false;
            if (!append) renderInvLogError(null);
            else {
                var btn = document.getElementById('inv-log-more');
                btn.textContent = 'Load more';
                btn.disabled = false;
            }
            console.error('Inventory log error:', err);
        });
}

function renderInvLogError(msg) {
    document.getElementById('inv-log-body').innerHTML =
        '<div class="text-center py-12 text-red-400">' +
        '<div class="text-3xl mb-2">⚠️</div>' +
        '<p class="text-sm">' + escInvLog(msg || 'Failed to load transactions.') + '</p></div>';
    document.getElementById('inv-log-more').style.display = 'none';
}

// Group a flat event list into days, computing opening/closing/net per day. Done here rather
// than server-side so paged results stay correct when a day spans two pages.
function groupInvLogDays(events) {
    var days = [];
    var byKey = {};
    for (var i = 0; i < events.length; i++) {
        var ev = events[i];
        var key = ev.date_day || 'unknown';
        if (!byKey[key]) {
            byKey[key] = {date: key, label: ev.date_day_label || key, events: []};
            days.push(byKey[key]);
        }
        byKey[key].events.push(ev);
    }
    for (var d = 0; d < days.length; d++) {
        var list = days[d].events;
        days[d].closing_balance = list[0].balance_after;
        days[d].opening_balance = list[list.length - 1].balance_before;
        days[d].net_change = days[d].closing_balance - days[d].opening_balance;
    }
    return days;
}

function invLogRowStyle(ev) {
    var isPositive = ev.change > 0;
    // Warehouse ledger types
    if (ev.type === 'stock_in') {
        return ev.reference_type === 'batch'
            ? {change: 'color:#0d9488;', border: 'border-left: 3px solid #14b8a6;', iconBg: 'background-color:#ccfbf1;'}
            : {change: 'color:#16a34a;', border: 'border-left: 3px solid #22c55e;', iconBg: 'background-color:#dcfce7;'};
    }
    if (ev.type === 'stock_out') {
        return {change: 'color:#dc2626;', border: 'border-left: 3px solid #ef4444;', iconBg: 'background-color:#fee2e2;'};
    }
    if (ev.type === 'transfer') {
        return {change: 'color:#b45309;', border: 'border-left: 3px solid #f59e0b;', iconBg: 'background-color:#fef3c7;'};
    }
    if (ev.type === 'count') {
        return {change: 'color:#7c3aed;', border: 'border-left: 3px solid #8b5cf6;', iconBg: 'background-color:#ede9fe;'};
    }
    if (ev.type === 'adjustment') {
        return ev.reference_type === 'transfer_rejected'
            ? {change: 'color:#2563eb;', border: 'border-left: 3px solid #3b82f6;', iconBg: 'background-color:#dbeafe;'}
            : {change: isPositive ? 'color:#16a34a;' : 'color:#ea580c;', border: 'border-left: 3px solid #f97316;', iconBg: 'background-color:#fff7ed;'};
    }
    // Store log types
    if (ev.type === 'transfer_in') {
        return {change: 'color:#16a34a;', border: 'border-left: 3px solid #22c55e;', iconBg: 'background-color:#dcfce7;'};
    }
    if (ev.type === 'order_deduction') {
        return {change: 'color:#dc2626;', border: 'border-left: 3px solid #ef4444;', iconBg: 'background-color:#fee2e2;'};
    }
    if (ev.type === 'cancellation_restore') {
        return {change: 'color:#2563eb;', border: 'border-left: 3px solid #3b82f6;', iconBg: 'background-color:#dbeafe;'};
    }
    if (ev.type === 'store_adjustment') {
        return {
            change: isPositive ? 'color:#7c3aed;' : 'color:#ea580c;',
            border: isPositive ? 'border-left: 3px solid #8b5cf6;' : 'border-left: 3px solid #f97316;',
            iconBg: isPositive ? 'background-color:#ede9fe;' : 'background-color:#fff7ed;'
        };
    }
    return {
        change: isPositive ? 'color:#16a34a;' : 'color:#dc2626;',
        border: 'border-left: 3px solid #9ca3af;',
        iconBg: 'background-color:#f3f4f6;'
    };
}

function renderInvLog(multiRowWarning) {
    var cfg = INV_LOG_MODES[invLog.mode];
    var events = invLog.events;

    if (!events || events.length === 0) {
        document.getElementById('inv-log-body').innerHTML =
            '<div class="text-center py-12 text-gray-400">' +
            '<div class="text-3xl mb-2">📭</div>' +
            '<p class="text-sm">' + escInvLog(invLog.typeFilter ? 'No movements of this type.' : cfg.emptyText) + '</p></div>';
        document.getElementById('inv-log-more').style.display = 'none';
        return;
    }

    var days = groupInvLogDays(events);
    var html = '';

    html += '<div class="px-5 py-3 flex items-center justify-between" style="position:sticky; top:0; z-index:2; background-color:' + cfg.balanceBg + '; padding:0.75rem 1.25rem; border-bottom:1px solid rgba(0,0,0,0.06);">';
    html += '<span class="text-xs font-semibold" style="color:' + cfg.balanceText + ';">' + cfg.balanceLabel + '</span>';
    html += '<span class="text-lg font-bold" style="color:' + cfg.balanceText + ';">' + invLog.currentQty + ' ' + escInvLog(invLog.unit) + '</span>';
    html += '</div>';

    if (multiRowWarning) {
        html += '<div class="px-5 py-2" style="background-color:#fef2f2; border-bottom:1px solid #fecaca;">';
        html += '<p class="text-[11px]" style="color:#991b1b;">⚠️ This product has more than one warehouse record. The balances below track one record, so they will not add up to the combined total above.</p>';
        html += '</div>';
    }

    for (var d = 0; d < days.length; d++) {
        var day = days[d];
        var netSign = day.net_change > 0 ? '+' : '';
        var netColor = day.net_change > 0 ? '#16a34a' : day.net_change < 0 ? '#dc2626' : '#6b7280';

        html += '<div class="px-5 py-2 bg-gray-100 border-y border-gray-200 flex items-center justify-between">';
        html += '<span class="text-xs font-bold text-gray-700">📅 ' + escInvLog(day.label) + '</span>';
        html += '<div class="flex items-center gap-3 text-[10px]">';
        html += '<span class="text-gray-500">Open: <b>' + day.opening_balance + '</b></span>';
        html += '<span class="text-gray-500">Close: <b>' + day.closing_balance + '</b></span>';
        html += '<span style="color:' + netColor + ';font-weight:700;">' + netSign + day.net_change + '</span>';
        html += '</div></div>';

        html += '<div class="divide-y divide-gray-50">';
        for (var i = 0; i < day.events.length; i++) {
            var ev = day.events[i];
            var isPositive = ev.change > 0;
            var st = invLogRowStyle(ev);

            html += '<div class="px-5 py-2.5 hover:bg-gray-50 transition-colors" style="' + st.border + '">';
            html += '<div class="flex items-start gap-3">';
            html += '<div class="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5" style="' + st.iconBg + '">';
            html += '<span class="text-xs">' + escInvLog(ev.icon) + '</span></div>';
            html += '<div class="flex-1 min-w-0">';
            html += '<div class="flex items-center justify-between">';
            html += '<span class="text-xs font-semibold text-gray-900">' + escInvLog(ev.label) + '</span>';
            if (ev.count_value !== null && ev.count_value !== undefined) {
                // Physical count: the number the user SET is the headline; delta is secondary,
                // otherwise a count that matches the balance reads as a no-op.
                var countDelta = ev.change === 0 ? 'no change' : ((ev.change > 0 ? '+' : '') + ev.change);
                html += '<span class="text-xs font-bold" style="color:#7c3aed;">Counted: ' + ev.count_value
                     + ' <span style="color:#9ca3af;font-weight:600;">(' + countDelta + ')</span></span>';
            } else {
                html += '<span class="text-xs font-bold" style="' + st.change + '">' + (isPositive ? '+' : '') + ev.change + '</span>';
            }
            html += '</div>';
            if (ev.detail) {
                html += '<div class="text-[11px] text-gray-600 mt-0.5">' + escInvLog(ev.detail) + '</div>';
            }
            if (ev.sub_detail) {
                html += '<div class="text-[10px] text-gray-400 mt-0.5">' + escInvLog(ev.sub_detail) + '</div>';
            }
            html += '<div class="flex items-center justify-between mt-1">';
            html += '<span class="text-[10px] text-gray-400">' + escInvLog(ev.date_formatted) + '</span>';
            html += '<span class="text-[10px] font-medium text-gray-500 bg-gray-100 px-1.5 py-0.5 rounded">' + ev.balance_before + ' → ' + ev.balance_after + '</span>';
            html += '</div></div></div></div>';

            if (ev.gap) {
                html += '<div class="px-5 py-1" style="background-color:#fffbeb;">';
                html += '<p class="text-[10px] text-center" style="color:#b45309;">· earlier records may be incomplete here ·</p></div>';
            }
        }
        html += '</div>';
    }

    // Legend
    html += '<div class="px-5 py-3 bg-gray-50 border-t border-gray-100">';
    html += '<div class="flex flex-wrap items-center gap-3 text-[10px] text-gray-500">';
    for (var l = 0; l < cfg.legend.length; l++) {
        html += '<span><span style="display:inline-block;width:8px;height:8px;background:' + cfg.legend[l][0] + ';border-radius:2px;margin-right:3px;"></span>' + cfg.legend[l][1] + '</span>';
    }
    html += '</div></div>';

    document.getElementById('inv-log-body').innerHTML = html;

    var btn = document.getElementById('inv-log-more');
    btn.textContent = 'Load more';
    btn.disabled = false;
    btn.style.display = invLog.hasMore ? 'inline-block' : 'none';

    document.getElementById('inv-log-footnote').textContent =
        events.length + ' movement' + (events.length === 1 ? '' : 's') + ' shown'
        + (invLog.hasMore ? ' · more available' : ' · that is everything');
}

function escInvLog(str) {
    if (str === null || str === undefined) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Close on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeStockModal();
        closeStoreStockModal();
        closeTransferModal();
        closeProductRejectModal();
        // Was missing: the approve modal could only be dismissed with the mouse.
        closeProductApproveModal();
        closeInvLogModal();
        closeRequestModal();
        closeAcceptRequestModal();
        closeDeclineRequestModal();
        closePendingOrdersModal();
    }
});
</script>
@endpush
