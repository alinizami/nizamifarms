@extends('layouts.app')

@section('title', '📋 Khaas Planning')

@section('content')
<div class="container-fluid px-6 py-6">
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-5">
        <div class="flex items-center gap-3">
            <a href="{{ route('khaas.dashboard') }}" class="text-gray-400 hover:text-gray-700 transition-colors">
                <i class="ki-filled ki-arrow-left text-lg"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">📋 {{ $khaasBU->name }} Planning</h1>
                <p class="text-sm text-gray-600 mt-0.5">Stock levels, production planning, recipes & configuration</p>
            </div>
        </div>
        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium" style="background-color: {{ $khaasBU->color_hex ?? '#f59e0b' }}20; color: {{ $khaasBU->color_hex ?? '#f59e0b' }};">
            🌿 {{ $khaasBU->name }}
        </span>
    </div>

    @if(session('success'))
        <div class="mb-4 p-3 border rounded-lg flex items-center gap-2" style="background-color: #f0fdf4; border-color: #bbf7d0;">
            <span>✅</span>
            <p class="text-sm" style="color: #166534;">{{ session('success') }}</p>
        </div>
    @endif
    @if(session('error'))
        <div class="mb-4 p-3 border rounded-lg flex items-center gap-2" style="background-color: #fef2f2; border-color: #fecaca;">
            <span>❌</span>
            <p class="text-sm" style="color: #991b1b;">{{ session('error') }}</p>
        </div>
    @endif

    <!-- Tab Navigation -->
    <div class="border-b border-gray-200 mb-6">
        <nav class="flex gap-1 -mb-px flex-wrap" aria-label="Tabs">
            @php
                $tabs = [
                    'stock' => ['icon' => '📦', 'label' => 'Current Stock', 'count' => $stockItems->count()],
                    'production' => ['icon' => '📋', 'label' => 'Production Plan', 'count' => null],
                    'recipes' => ['icon' => '🔗', 'label' => 'Recipes', 'count' => null],
                    // ❄ Sep-2026. A SEPARATE tab on purpose. The 🔗 Recipes tab above is
                    // the meat mapping that deducts kilos from storage when a plan is
                    // accepted — it still does that job and is untouched. This one is
                    // the quantified recipe: how much of what goes into a pack.
                    'ingredients' => ['icon' => '🧂', 'label' => 'Ingredients & Quantities', 'count' => null],
                    'config' => ['icon' => '⚙️', 'label' => 'Configure Products', 'count' => null],
                    'history' => ['icon' => '📜', 'label' => 'History', 'count' => $demandHistory->count()],
                ];
            @endphp
            @foreach($tabs as $tabKey => $tabInfo)
            <a href="{{ route('khaas.inventory', ['tab' => $tabKey]) }}"
               class="inline-flex items-center gap-2 px-5 py-3 text-sm font-medium border-b-2 transition-colors"
               style="{{ $activeTab === $tabKey ? 'border-color: #D97706; color: #B45309;' : 'border-color: transparent; color: #6B7280;' }}">
                {{ $tabInfo['icon'] }} {{ $tabInfo['label'] }}
                @if($tabKey === 'production' && $pendingDemandCount > 0)
                    <span class="ml-1 px-2 py-0.5 rounded-full text-xs font-bold" style="background-color: #D97706; color: white;">{{ $pendingDemandCount }}</span>
                @elseif($tabInfo['count'] !== null)
                    <span class="ml-1 px-2 py-0.5 rounded-full text-xs" style="{{ $activeTab === $tabKey ? 'background-color: #FEF3C7; color: #B45309;' : 'background-color: #F3F4F6; color: #6B7280;' }}">{{ $tabInfo['count'] }}</span>
                @endif
            </a>
            @endforeach
        </nav>
    </div>

    {{-- ====================== CURRENT STOCK TAB ====================== --}}
    @if($activeTab === 'stock')
    <div>
        <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                            <th class="px-5 py-3 text-right text-xs font-medium text-gray-500 uppercase">Available</th>
                            <th class="px-5 py-3 text-right text-xs font-medium text-gray-500 uppercase">Processing</th>
                            <th class="px-5 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Last Received</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($stockItems as $item)
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-lg flex items-center justify-center text-sm font-bold text-white" style="background-color: {{ $khaasBU->color_hex ?? '#f59e0b' }};">
                                        {{ strtoupper(substr($item->name, 0, 2)) }}
                                    </div>
                                    <div>
                                        <div class="font-semibold text-gray-900 text-sm">{{ $item->name }}</div>
                                        @if($item->variant_title)
                                            <div class="text-xs text-gray-400">{{ $item->variant_title }}</div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <span class="text-sm font-bold" style="color: {{ $item->quantity > 0 ? '#059669' : '#9CA3AF' }};">
                                    {{ $item->quantity }} {{ $item->unit }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                @if($item->processing_qty > 0)
                                    <span class="text-sm font-medium" style="color: #D97706;">{{ $item->processing_qty }} {{ $item->unit }}</span>
                                @else
                                    <span class="text-xs text-gray-300">—</span>
                                @endif
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <span class="text-sm font-bold text-gray-900">{{ round($item->quantity + $item->processing_qty, 3) }} {{ $item->unit }}</span>
                            </td>
                            <td class="px-5 py-3.5 whitespace-nowrap">
                                @if($item->last_received)
                                    <span class="text-xs text-gray-500">{{ \Carbon\Carbon::parse($item->last_received)->format('M d, Y') }}</span>
                                @else
                                    <span class="text-xs text-gray-300">—</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="px-5 py-10 text-center">
                                <div class="text-3xl mb-2">📦</div>
                                <p class="text-sm text-gray-500">No storage products configured.</p>
                                <a href="{{ route('khaas.inventory', ['tab' => 'config']) }}" class="text-xs hover:underline mt-1 inline-block" style="color: #D97706;">Configure products →</a>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif

    {{-- ====================== PRODUCTION PLAN TAB ====================== --}}
    @if($activeTab === 'production')
    <div>
        <!-- Action Bar with New Plan Button -->
        <div class="bg-white border border-gray-200 rounded-xl p-4 mb-5 flex items-center justify-between">
            <div class="text-sm text-gray-600">
                <span class="font-medium text-gray-900">{{ $demands->count() }}</span> active production plan{{ $demands->count() !== 1 ? 's' : '' }}
            </div>
            @if($canCreateDemand ?? false)
            <button onclick="document.getElementById('createDemandModal').classList.remove('hidden')"
                class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold rounded-lg shadow-sm transition-colors"
                style="background-color: #D97706; color: white;"
                onmouseover="this.style.backgroundColor='#B45309'"
                onmouseout="this.style.backgroundColor='#D97706'">
                + New Production Plan
            </button>
            @endif
        </div>

        @if($demands->count() === 0)
        <div class="bg-white border border-gray-200 rounded-xl p-10 text-center">
            <div class="text-3xl mb-2">📋</div>
            <p class="text-sm text-gray-500">No active production demands.</p>
            <p class="text-xs text-gray-400 mt-1">Click "New Production Plan" above to create one.</p>
        </div>
        @else
        <div class="space-y-4">
            @foreach($demands as $demand)
            @php
                $statusStyles = [
                    'submitted' => ['bg' => '#FEF3C7', 'text' => '#B45309', 'icon' => '⏳'],
                    'accepted' => ['bg' => '#DBEAFE', 'text' => '#1D4ED8', 'icon' => '✅'],
                    'in_progress' => ['bg' => '#F3E8FF', 'text' => '#7E22CE', 'icon' => '🔥'],
                    'completed' => ['bg' => '#DCFCE7', 'text' => '#166534', 'icon' => '✅'],
                    'cancelled' => ['bg' => '#FEE2E2', 'text' => '#991B1B', 'icon' => '❌'],
                ];
                $dStyle = $statusStyles[$demand->status] ?? $statusStyles['submitted'];
                $totalKg = $demand->items->sum('quantity_kg');
            @endphp
            <div class="bg-white border border-gray-200 rounded-xl overflow-hidden hover:shadow-sm transition-shadow">
                <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg flex items-center justify-center text-sm" style="background-color: {{ $dStyle['bg'] }}; color: {{ $dStyle['text'] }};">
                            {{ $dStyle['icon'] }}
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h4 class="font-semibold text-gray-900 text-sm">Demand for {{ \Carbon\Carbon::parse($demand->demand_date)->format('M d, Y') }}</h4>
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium" style="background-color: {{ $dStyle['bg'] }}; color: {{ $dStyle['text'] }};">
                                    {{ ucfirst(str_replace('_', ' ', $demand->status)) }}
                                </span>
                            </div>
                            <div class="flex items-center gap-3 text-xs text-gray-500 mt-0.5">
                                <span>Created {{ \Carbon\Carbon::parse($demand->created_at)->format('M d, h:i A') }}</span>
                                @if($demand->created_by_name)
                                    <span>by {{ $demand->created_by_name }}</span>
                                @endif
                                <span class="font-medium text-gray-700">{{ round($totalKg, 2) }} kg total</span>
                            </div>
                        </div>
                    </div>
                    {{-- Action Buttons --}}
                    <div class="flex items-center gap-2">
                        @if($demand->status === 'submitted')
                        <form method="POST" action="{{ route('khaas.inventory.demand.accept', $demand->id) }}" onsubmit="return confirm('Accept this demand? This will deduct raw materials from storage and start production batches.');">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg transition-colors"
                                style="background-color: #2563EB; color: white;"
                                onmouseover="this.style.backgroundColor='#1D4ED8'"
                                onmouseout="this.style.backgroundColor='#2563EB'">
                                ✅ Accept & Start
                            </button>
                        </form>
                        <form method="POST" action="{{ route('khaas.inventory.demand.cancel', $demand->id) }}" onsubmit="return confirm('Cancel this demand?');">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border transition-colors"
                                style="background-color: #F3F4F6; color: #4B5563; border-color: #D1D5DB;"
                                onmouseover="this.style.backgroundColor='#FEF2F2'; this.style.color='#DC2626';"
                                onmouseout="this.style.backgroundColor='#F3F4F6'; this.style.color='#4B5563';">
                                ✕ Cancel
                            </button>
                        </form>
                        @endif
                    </div>
                </div>

                {{-- Items Table --}}
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-5 py-2 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                                <th class="px-5 py-2 text-right text-xs font-medium text-gray-500 uppercase">Qty (kg)</th>
                                <th class="px-5 py-2 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach($demand->items as $item)
                            @php
                                $itemStyles = [
                                    'pending' => ['bg' => '#F3F4F6', 'text' => '#4B5563'],
                                    'accepted' => ['bg' => '#DBEAFE', 'text' => '#1D4ED8'],
                                    'in_progress' => ['bg' => '#F3E8FF', 'text' => '#7E22CE'],
                                    'completed' => ['bg' => '#DCFCE7', 'text' => '#166534'],
                                    'cancelled' => ['bg' => '#FEE2E2', 'text' => '#991B1B'],
                                ];
                                $iStyle = $itemStyles[$item->status] ?? $itemStyles['pending'];
                            @endphp
                            <tr>
                                <td class="px-5 py-2.5 text-sm text-gray-900">{{ $item->product_name ?? 'Product #' . $item->khaas_product_id }}</td>
                                <td class="px-5 py-2.5 text-right text-sm font-medium text-gray-900">{{ round($item->quantity_kg, 2) }}</td>
                                <td class="px-5 py-2.5 text-center">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium" style="background-color: {{ $iStyle['bg'] }}; color: {{ $iStyle['text'] }};">
                                        {{ ucfirst(str_replace('_', ' ', $item->status)) }}
                                    </span>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($demand->notes)
                <div class="px-5 py-2 border-t border-gray-100 bg-gray-50">
                    <p class="text-xs text-gray-500">📝 {{ $demand->notes }}</p>
                </div>
                @endif
            </div>
            @endforeach
        </div>
        @endif

    </div>
    @endif

    {{-- ============ INGREDIENTS & QUANTITIES TAB (Sep-2026) ============
         The quantified recipe: "9 kg chicken makes 80 packs, with 90 g salt and 1 L oil".
         Everything on this tab talks to Khaas\RecipeController — the SAME controller the
         phone calls — so the page and the app can never show different recipes.
         The 🔗 Recipes tab is a different thing and is deliberately left alone. --}}
    @if($activeTab === 'ingredients')
    <div x-data>
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">

            {{-- ── LEFT: the ingredient master ─────────────────────────── --}}
            <div class="lg:col-span-2 bg-white border border-gray-200 rounded-xl overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
                    <div>
                        <h3 class="font-semibold text-gray-900 text-sm">🧂 Ingredients</h3>
                        <p class="text-xs text-gray-500 mt-0.5">Everything that goes into a pack</p>
                    </div>
                    <button type="button" id="ingAddBtn"
                            class="px-3 py-1.5 rounded-lg text-xs font-semibold"
                            style="background-color:#FEF3C7;color:#B45309;border:1px solid #FDE68A;">＋ Ingredient</button>
                </div>

                {{-- add / edit form, hidden until wanted --}}
                <div id="ingForm" class="hidden px-5 py-4 border-b border-gray-100" style="background:#FFFBEB;">
                    <input type="hidden" id="ingFormId" value="">
                    <div class="mb-3">
                        <label class="block text-xs font-medium text-gray-700 mb-1">Name</label>
                        <input type="text" id="ingFormName" placeholder="e.g. Canola oil"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    </div>
                    <div class="grid grid-cols-2 gap-3 mb-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Measured in</label>
                            <select id="ingFormUnit" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white">
                                <option value="g">Weight (kg / g)</option>
                                <option value="ml">Volume (L / ml)</option>
                                <option value="pcs">Pieces</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Kind</label>
                            <select id="ingFormKind" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white">
                                <option value="meat">Meat</option>
                                <option value="vegetable">Vegetables</option>
                                <option value="dairy">Dairy</option>
                                <option value="dry">Dry goods</option>
                                <option value="packaging">Packaging</option>
                                <option value="other" selected>Other</option>
                            </select>
                        </div>
                    </div>
                    <p id="ingFormLock" class="hidden text-xs mb-3" style="color:#B45309;">
                        How it is measured is changed everywhere at once — its recipes and the vendor products
                        linked to it — so it is not changed here.
                        <button type="button" id="ingUnitChangeBtn" class="underline font-semibold ml-1">Change how it is measured…</button>
                    </p>
                    {{-- 🔀 Sep-27: the same change-everywhere engine the vendor product form uses. --}}
                    <div id="ingUnitChange" class="hidden mb-3"></div>
                    <div class="flex gap-2">
                        <button type="button" id="ingFormSave"
                                class="flex-1 px-3 py-2 rounded-lg text-sm font-semibold text-white"
                                style="background-color:#B45309;">Save</button>
                        <button type="button" id="ingFormCancel"
                                class="px-3 py-2 rounded-lg text-sm border border-gray-300 text-gray-700">Cancel</button>
                    </div>
                </div>

                {{-- ❄ Where the recipes and the purchasing have not met yet. Filled by JS from
                     the `gaps` half of the ingredients payload; hidden when there is nothing
                     to say. Each line names the exact spelling to use on the vendor side. --}}
                <div id="ingGaps" class="hidden px-5 py-3 border-b border-gray-100" style="background:#FFFBEB;"></div>

                <div id="ingList" class="divide-y divide-gray-100 max-h-[560px] overflow-y-auto">
                    <div class="px-5 py-8 text-center text-sm text-gray-400">Loading…</div>
                </div>
            </div>

            {{-- ── RIGHT: the recipe for one product ───────────────────── --}}
            <div class="lg:col-span-3 bg-white border border-gray-200 rounded-xl overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 bg-gray-50">
                    <h3 class="font-semibold text-gray-900 text-sm">📖 Recipe</h3>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Enter one batch the way you make it, and the per-pack amounts work themselves out
                    </p>
                </div>

                <div class="px-5 py-4">
                    <label class="block text-xs font-medium text-gray-700 mb-1">Product</label>
                    <select id="recProduct" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white mb-4">
                        <option value="">Choose a product…</option>
                    </select>

                    <div id="recBody" class="hidden">
                        <div class="rounded-lg p-4 mb-4" style="background:#F9FAFB;">
                            <label class="block text-xs font-medium text-gray-700 mb-1">
                                One batch like this makes how many packs?
                            </label>
                            <input type="number" id="recBasis" min="1" step="1" placeholder="e.g. 80"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                            <p class="text-xs text-gray-500 mt-1">
                                Everything below is what that ONE batch uses, not one pack.
                            </p>
                        </div>

                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-semibold text-gray-700 uppercase tracking-wide">What goes in</span>
                            <button type="button" id="recAddLine"
                                    class="text-xs font-semibold px-2.5 py-1 rounded"
                                    style="background-color:#FEF3C7;color:#B45309;border:1px solid #FDE68A;">＋ Add ingredient</button>
                        </div>

                        <div id="recLines" class="space-y-2 mb-3"></div>
                        <div id="recEmpty" class="hidden text-sm text-gray-400 py-6 text-center">
                            Nothing added yet. Press “Add ingredient”.
                        </div>

                        <div id="recWarn" class="hidden rounded-lg px-3 py-2 mb-3 text-xs"
                             style="background:#FFFBEB;border:1px solid #FDE68A;color:#92400E;"></div>

                        {{-- 💲 Sep-27: the recipe at TODAY'S prices (each ingredient's newest bill),
                             recalculated as you type. Lines with no price are named, never zero. --}}
                        <div id="recCost" class="hidden rounded-lg px-3 py-2 mb-3 text-xs"
                             style="background:#EEF2FF;border:1px solid #C7D2FE;color:#312E81;"></div>

                        <div class="mb-3">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Note (optional)</label>
                            <input type="text" id="recNote" maxlength="255" placeholder="e.g. as made with Sabir"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                        </div>

                        <div class="flex items-center justify-between gap-3">
                            <p id="recVersion" class="text-xs text-gray-500"></p>
                            <button type="button" id="recSave"
                                    class="px-4 py-2 rounded-lg text-sm font-semibold text-white"
                                    style="background-color:#B45309;">Save recipe</button>
                        </div>

                        <p class="text-xs text-gray-400 mt-3 leading-relaxed">
                            Saving makes a new version. Packs already made keep the version they were made with,
                            so correcting a recipe never quietly changes last month. To apply a correction
                            backwards, use “Re-apply recipes for this month” on Month Review.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ====================== RECIPES TAB ====================== --}}
    @if($activeTab === 'recipes')
    <div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- Existing Recipes (grouped by Khaas product) --}}
            <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 bg-gray-50">
                    <h3 class="font-semibold text-gray-900 text-sm">🔗 Product Recipe Mappings</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Each finished product can use multiple raw materials</p>
                </div>
                <div class="divide-y divide-gray-100">
                    @forelse($recipes as $group)
                    <div class="px-5 py-3">
                        <div class="text-xs font-bold mb-2" style="color: #B45309;">🍴 {{ $group['khaas_product_name'] }}</div>
                        @foreach($group['materials'] as $m)
                        <div class="flex items-center justify-between py-1.5 pl-4 border-b border-gray-50 last:border-0">
                            @if($m['is_custom'] ?? false)
                            <span class="text-sm text-gray-700">← 🧂 {{ $m['storage_product_name'] }} <span class="text-[10px] px-1.5 py-0.5 rounded bg-amber-50 text-amber-600 font-medium ml-1">manual</span></span>
                            @else
                            <span class="text-sm text-gray-700">← 🥩 {{ $m['storage_product_name'] }}</span>
                            @endif
                            <form method="POST" action="{{ route('khaas.inventory.recipe.delete', $m['recipe_id']) }}" onsubmit="return confirm('Remove this mapping?');" class="inline">
                                @csrf
                                <button type="submit" class="text-sm px-2 py-0.5 rounded transition-colors" style="color: #F87171;" onmouseover="this.style.color='#DC2626'" onmouseout="this.style.color='#F87171'">
                                    ✕
                                </button>
                            </form>
                        </div>
                        @endforeach
                        @php
                            $invCount = collect($group['materials'])->where('is_custom', false)->count();
                        @endphp
                        @if($invCount > 1)
                        <div class="text-xs text-gray-400 mt-1 pl-4">{{ $invCount }} inventory materials — full qty deducted from each on production</div>
                        @endif
                    </div>
                    @empty
                    <div class="px-5 py-10 text-center">
                        <div class="text-2xl mb-1">🔗</div>
                        <p class="text-sm text-gray-400">No recipes configured yet</p>
                        <p class="text-xs text-gray-400 mt-0.5">Add mappings using the form on the right</p>
                    </div>
                    @endforelse
                </div>
            </div>

            {{-- Add New Recipe --}}
            <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 bg-gray-50">
                    <h3 class="font-semibold text-gray-900 text-sm">➕ Add New Mapping</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Select a Khaas product, then tick the raw materials it needs</p>
                </div>
                <div class="px-5 py-4">
                    <div class="mb-4">
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Finished Product (Khaas)</label>
                        <select id="recipeKhaasProduct" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white" onchange="updateRecipeMaterialList()">
                            <option value="">Select a product...</option>
                            @foreach($khaasProducts as $kp)
                            <option value="{{ $kp->id }}">{{ $kp->title }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div id="recipeMaterialSection" class="mb-4 hidden">
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Raw Materials (from storage)</label>
                        <p class="text-xs text-gray-400 mb-2">Tick all raw materials this product needs. Full qty deducted from each on production.</p>
                        <div class="border border-gray-200 rounded-lg divide-y divide-gray-100 max-h-48 overflow-y-auto">
                            @foreach($storageProductsForRecipe as $sp)
                            <label class="flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-gray-50 transition-colors recipe-material-item" data-product-id="{{ $sp->product_id }}">
                                <input type="checkbox" class="recipe-material-checkbox rounded border-gray-300 text-amber-600 focus:ring-amber-500" value="{{ $sp->product_id }}" style="accent-color: #D97706;">
                                <span class="text-sm text-gray-700">🥩 {{ $sp->name }}</span>
                                <span class="recipe-material-badge hidden ml-auto text-xs px-1.5 py-0.5 rounded-full" style="background-color: #DCFCE7; color: #166534;">already mapped</span>
                            </label>
                            @endforeach
                        </div>

                        {{-- Custom Materials --}}
                        <label class="block text-xs font-medium text-gray-600 mt-4 mb-1.5">🧂 Other Materials (not in inventory)</label>
                        <p class="text-xs text-gray-400 mb-2">Add materials like Aloo, Cheese etc. These won't be deducted from inventory.</p>
                        <div id="customMaterialsList" class="border border-gray-200 rounded-lg divide-y divide-gray-100 max-h-32 overflow-y-auto mb-2">
                            @foreach($customMaterials as $cm)
                            <label class="flex items-center gap-3 px-3 py-2 cursor-pointer hover:bg-amber-50 transition-colors custom-material-item" data-cm-id="{{ $cm->id }}">
                                <input type="checkbox" class="custom-material-checkbox rounded border-gray-300 text-amber-600 focus:ring-amber-500" value="{{ $cm->id }}" style="accent-color: #D97706;">
                                <span class="text-sm text-gray-700">🧂 {{ $cm->name }} <span class="text-[10px] text-gray-400">({{ $cm->unit }})</span></span>
                                <span class="custom-material-badge hidden ml-auto text-xs px-1.5 py-0.5 rounded-full" style="background-color: #DCFCE7; color: #166534;">mapped</span>
                            </label>
                            @endforeach
                        </div>
                        <div class="flex gap-2">
                            <input type="text" id="newCustomMaterialName" placeholder="New material name (e.g. Aloo)" class="flex-1 px-3 py-1.5 border border-gray-300 rounded-lg text-sm">
                            <select id="newCustomMaterialUnit" class="px-2 py-1.5 border border-gray-300 rounded-lg text-sm">
                                <option value="kg">kg</option>
                                <option value="pcs">pcs</option>
                                <option value="litre">litre</option>
                                <option value="grams">grams</option>
                                <option value="pack">pack</option>
                            </select>
                            <button type="button" onclick="addCustomMaterial()" class="px-3 py-1.5 text-xs font-medium rounded-lg border transition-colors" style="background-color: #fffbeb; color: #B45309; border-color: #fde68a;">
                                + Add
                            </button>
                        </div>
                    </div>

                    <button type="button" id="saveRecipeMappingsBtn" onclick="saveRecipeMappings()" disabled
                        class="w-full px-4 py-2.5 text-sm font-medium rounded-lg shadow-sm transition-colors"
                        style="background-color: #D1D5DB; color: #6B7280; cursor: not-allowed;">
                        💾 Save Mapping
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ====================== CONFIGURE PRODUCTS TAB ====================== --}}
    @if($activeTab === 'config')
    <div>
        <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
                <div>
                    <h3 class="font-semibold text-gray-900 text-sm">⚙️ Configure Storage Products</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Select NF products to track in storage for meat delivery</p>
                </div>
                <div class="relative">
                    <input type="text" id="configSearch" placeholder="Search products..." onkeyup="filterConfigProducts()" class="w-60 px-3 py-1.5 border border-gray-300 rounded-lg text-sm pl-8">
                    <span class="absolute left-2.5 top-2 text-gray-400 text-sm">🔍</span>
                </div>
            </div>
            <div class="divide-y divide-gray-100 max-h-[600px] overflow-y-auto" id="configProductList">
                @forelse($availableProducts as $product)
                @php
                    $isConfigured = in_array($product['id'], $configuredProductIds);
                @endphp
                <div class="config-product-item flex items-center justify-between px-5 py-3 hover:bg-gray-50 transition-colors" data-name="{{ strtolower($product['title']) }}">
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium text-gray-900">{{ $product['title'] }}</div>
                        <div class="text-xs text-gray-400">{{ $product['vendor'] ?? '' }} {{ !empty($product['product_type']) ? '· ' . $product['product_type'] : '' }}</div>
                    </div>
                    <form method="POST" action="{{ route('khaas.inventory.storage-config') }}">
                        @csrf
                        <input type="hidden" name="product_id" value="{{ $product['id'] }}">
                        <input type="hidden" name="display_name" value="{{ $product['title'] }}">
                        <input type="hidden" name="default_unit" value="kg">
                        @if($isConfigured)
                        <input type="hidden" name="action" value="remove">
                        <button type="submit" onclick="return confirm('Remove {{ addslashes($product['title']) }} from storage?')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border transition-colors"
                            style="background-color: #f0fdf4; color: #15803d; border-color: #bbf7d0;">
                            ✅ Configured
                        </button>
                        @else
                        <input type="hidden" name="action" value="add">
                        <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border transition-colors"
                            style="background-color: #F9FAFB; color: #6B7280; border-color: #E5E7EB;">
                            ➕ Add
                        </button>
                        @endif
                    </form>
                </div>
                @empty
                <div class="px-5 py-10 text-center">
                    <div class="text-2xl mb-1">⚙️</div>
                    <p class="text-sm text-gray-400">No products available</p>
                </div>
                @endforelse
            </div>
        </div>
    </div>
    @endif

    {{-- ====================== HISTORY TAB ====================== --}}
    @if($activeTab === 'history')
    <div>
        @if($demandHistory->count() === 0)
        <div class="bg-white border border-gray-200 rounded-xl p-10 text-center">
            <div class="text-3xl mb-2">📜</div>
            <p class="text-sm text-gray-500">No completed or cancelled production plans yet.</p>
        </div>
        @else
        <div class="space-y-3">
            @foreach($demandHistory as $demand)
            @php
                $isCompleted = $demand->status === 'completed';
                $totalKg = $demand->items->sum('quantity_kg');
            @endphp
            <div class="bg-white border border-gray-200 rounded-xl p-4 hover:shadow-sm transition-shadow" style="{{ $isCompleted ? '' : 'opacity: 0.6;' }}">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-start gap-3 flex-1">
                        <div class="w-9 h-9 rounded-lg flex items-center justify-center text-sm shrink-0" style="background-color: {{ $isCompleted ? '#DCFCE7' : '#FEE2E2' }}; color: {{ $isCompleted ? '#059669' : '#DC2626' }};">
                            {{ $isCompleted ? '✅' : '❌' }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <h4 class="font-semibold text-gray-900 text-sm">Demand for {{ \Carbon\Carbon::parse($demand->demand_date)->format('M d, Y') }}</h4>
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium" style="background-color: {{ $isCompleted ? '#DCFCE7' : '#FEE2E2' }}; color: {{ $isCompleted ? '#166534' : '#991B1B' }};">
                                    {{ ucfirst($demand->status) }}
                                </span>
                            </div>
                            <div class="flex items-center gap-3 mt-1 text-xs text-gray-500">
                                <span>{{ \Carbon\Carbon::parse($demand->created_at)->format('M d, Y') }}</span>
                                @if($demand->created_by_name)
                                    <span>by {{ $demand->created_by_name }}</span>
                                @endif
                                <span class="font-medium text-gray-700">{{ round($totalKg, 2) }} kg</span>
                            </div>
                            <div class="mt-1.5 flex flex-wrap gap-1">
                                @foreach($demand->items as $item)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs bg-gray-100 text-gray-600">
                                    {{ $item->product_name ?? 'Product' }} · {{ round($item->quantity_kg, 2) }}kg
                                </span>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
        @endif
    </div>
    @endif
</div>

{{-- ============ CREATE DEMAND MODAL (outside container for proper fixed positioning) ============ --}}
@if($activeTab === 'production')
<div id="createDemandModal" class="hidden" style="position:fixed;inset:0;z-index:9999;">
    <div style="position:fixed;inset:0;background:rgba(0,0,0,0.5);" onclick="document.getElementById('createDemandModal').classList.add('hidden')"></div>
    <div style="position:fixed;inset:0;display:flex;align-items:center;justify-content:center;padding:1rem;">
        <div class="bg-white rounded-xl shadow-2xl w-full flex flex-col" style="max-width:32rem;max-height:calc(100vh - 4rem);z-index:10;position:relative;">
            <div class="px-6 py-4 border-b border-gray-200 shrink-0">
                <h3 class="text-lg font-bold text-gray-900">📋 New Production Plan</h3>
                <p class="text-xs text-gray-500 mt-0.5">Enter weight (kg) for each product to produce</p>
            </div>
            <form method="POST" action="{{ route('khaas.inventory.demand.create') }}" onsubmit="return validateDemand()" class="flex flex-col min-h-0 flex-1">
                @csrf
                <div class="px-6 py-4 overflow-y-auto flex-1 min-h-0">
                    <div class="mb-4">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Production Date</label>
                        <input type="date" name="demand_date" value="{{ now()->addDay()->toDateString() }}" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    </div>
                    @if($demandProducts->count() > 0)
                    <div class="space-y-3">
                        @foreach($demandProducts as $idx => $dp)
                        <div class="flex items-center gap-3 py-2 border-b border-gray-100">
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-semibold text-gray-900 truncate">{{ $dp['product_name'] }}</div>
                                <div class="mt-0.5">
                                    @foreach(($dp['raw_materials'] ?? []) as $rm)
                                    <div class="flex items-center gap-2">
                                        @if($rm['is_custom'] ?? false)
                                        <span class="text-xs text-amber-500">← 🧂 {{ $rm['raw_material_name'] }}</span>
                                        <span class="text-[10px] text-amber-400">(manual)</span>
                                        @else
                                        <span class="text-xs text-gray-400">← {{ $rm['raw_material_name'] }}</span>
                                        <span class="text-xs font-semibold" style="color: {{ ($rm['raw_material_available'] ?? 0) > 0 ? '#059669' : '#DC2626' }};">
                                            ({{ $rm['raw_material_available'] ?? 0 }}kg)
                                        </span>
                                        @endif
                                    </div>
                                    @endforeach
                                    @if(empty($dp['raw_materials']))
                                    <span class="text-xs text-gray-400 italic">No recipe</span>
                                    <a href="{{ route('khaas.inventory', ['tab' => 'recipes']) }}" class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold transition-colors" style="background-color: #FEF3C7; color: #B45309; border: 1px solid #FDE68A;" onmouseover="this.style.backgroundColor='#FDE68A'" onmouseout="this.style.backgroundColor='#FEF3C7'">+ Add Recipe</a>
                                    @endif
                                </div>
                            </div>
                            <input type="hidden" name="items[{{ $idx }}][khaas_product_id]" value="{{ $dp['khaas_product_id'] }}">
                            <input type="number" step="0.1" min="0" name="items[{{ $idx }}][quantity_kg]"
                                class="demand-qty w-20 px-3 py-1.5 border border-gray-300 rounded-lg text-sm text-center font-bold"
                                placeholder="0">
                            <span class="text-xs text-gray-400 w-5">kg</span>
                        </div>
                        @endforeach
                    </div>
                    @else
                    <div class="text-center py-8">
                        <p class="text-sm text-gray-400">No active products found for this business unit.</p>
                    </div>
                    @endif

                    <div class="mt-4">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Notes (optional)</label>
                        <textarea name="notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm resize-none" placeholder="Any notes..."></textarea>
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 flex justify-end gap-3 rounded-b-xl shrink-0">
                    <button type="button" onclick="document.getElementById('createDemandModal').classList.add('hidden')" class="px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="px-5 py-2 text-sm font-medium rounded-lg shadow-sm transition-colors"
                        style="background-color: #D97706; color: white;"
                        onmouseover="this.style.backgroundColor='#B45309'"
                        onmouseout="this.style.backgroundColor='#D97706'">
                        Submit Plan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

@endsection

@push('demo1_js')
<script>
function validateDemand() {
    var inputs = document.querySelectorAll('.demand-qty');
    var hasQty = false;
    inputs.forEach(function(input) { if (parseFloat(input.value) > 0) hasQty = true; });
    if (!hasQty) {
        alert('Enter weight for at least one product.');
        return false;
    }
    return confirm('Submit this production plan?');
}

function filterConfigProducts() {
    var search = document.getElementById('configSearch').value.toLowerCase();
    var items = document.querySelectorAll('.config-product-item');
    items.forEach(function(item) {
        var name = item.getAttribute('data-name');
        item.style.display = name.includes(search) ? '' : 'none';
    });
}

// Existing recipe data for highlighting already-mapped materials
var existingRecipes = @json($activeTab === 'recipes' ? $recipes : []);

function updateRecipeMaterialList() {
    var khaasId = document.getElementById('recipeKhaasProduct').value;
    var section = document.getElementById('recipeMaterialSection');
    var btn = document.getElementById('saveRecipeMappingsBtn');

    if (!khaasId) {
        section.classList.add('hidden');
        btn.disabled = true;
        btn.style.backgroundColor = '#D1D5DB';
        btn.style.color = '#6B7280';
        btn.style.cursor = 'not-allowed';
        return;
    }

    section.classList.remove('hidden');

    // Find existing mappings for this product
    var mappedStorage = [];
    var mappedCustom = [];
    existingRecipes.forEach(function(group) {
        if (String(group.khaas_product_id) === String(khaasId)) {
            (group.materials || []).forEach(function(m) {
                if (m.is_custom) {
                    mappedCustom.push(String(m.custom_material_id));
                } else {
                    mappedStorage.push(String(m.storage_product_id));
                }
            });
        }
    });

    // Update storage checkboxes and badges
    document.querySelectorAll('.recipe-material-item').forEach(function(item) {
        var productId = item.getAttribute('data-product-id');
        var checkbox = item.querySelector('.recipe-material-checkbox');
        var badge = item.querySelector('.recipe-material-badge');
        var isMapped = mappedStorage.includes(productId);

        checkbox.checked = isMapped;
        checkbox.dataset.wasMapped = isMapped ? '1' : '0';
        badge.classList.toggle('hidden', !isMapped);
    });

    // Update custom material checkboxes and badges
    document.querySelectorAll('.custom-material-item').forEach(function(item) {
        var cmId = item.getAttribute('data-cm-id');
        var checkbox = item.querySelector('.custom-material-checkbox');
        var badge = item.querySelector('.custom-material-badge');
        var isMapped = mappedCustom.includes(cmId);

        checkbox.checked = isMapped;
        checkbox.dataset.wasMapped = isMapped ? '1' : '0';
        badge.classList.toggle('hidden', !isMapped);
    });

    updateSaveBtn();
}

function updateSaveBtn() {
    var allCbs = document.querySelectorAll('.recipe-material-checkbox, .custom-material-checkbox');
    var hasNew = false;
    var hasAny = false;
    allCbs.forEach(function(cb) {
        if (cb.checked) hasAny = true;
        if (cb.checked && cb.dataset.wasMapped !== '1') hasNew = true;
    });

    var btn = document.getElementById('saveRecipeMappingsBtn');
    var khaasId = document.getElementById('recipeKhaasProduct').value;

    if (khaasId && (hasNew || hasAny)) {
        btn.disabled = false;
        btn.style.backgroundColor = '#D97706';
        btn.style.color = 'white';
        btn.style.cursor = 'pointer';
    } else {
        btn.disabled = true;
        btn.style.backgroundColor = '#D1D5DB';
        btn.style.color = '#6B7280';
        btn.style.cursor = 'not-allowed';
    }
}

// Attach change listeners to checkboxes
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.recipe-material-checkbox, .custom-material-checkbox').forEach(function(cb) {
        cb.addEventListener('change', updateSaveBtn);
    });
});

function addCustomMaterial() {
    var nameEl = document.getElementById('newCustomMaterialName');
    var unitEl = document.getElementById('newCustomMaterialUnit');
    var name = nameEl.value.trim();
    var unit = unitEl.value;
    if (!name) { alert('Enter a material name.'); return; }

    var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    fetch('{{ route("khaas.inventory.custom-material.save") }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
        body: JSON.stringify({ business_unit_id: {{ $khaasBU->id }}, name: name, unit: unit })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success && data.material) {
            var list = document.getElementById('customMaterialsList');
            var existing = list.querySelector('[data-cm-id="' + data.material.id + '"]');
            if (!existing) {
                var label = document.createElement('label');
                label.className = 'flex items-center gap-3 px-3 py-2 cursor-pointer hover:bg-amber-50 transition-colors custom-material-item';
                label.setAttribute('data-cm-id', data.material.id);
                label.innerHTML = '<input type="checkbox" class="custom-material-checkbox rounded border-gray-300 text-amber-600 focus:ring-amber-500" value="' + data.material.id + '" style="accent-color: #D97706;" checked>'
                    + '<span class="text-sm text-gray-700">🧂 ' + data.material.name + ' <span class="text-[10px] text-gray-400">(' + data.material.unit + ')</span></span>'
                    + '<span class="custom-material-badge hidden ml-auto text-xs px-1.5 py-0.5 rounded-full" style="background-color: #DCFCE7; color: #166534;">mapped</span>';
                list.appendChild(label);
                label.querySelector('.custom-material-checkbox').addEventListener('change', updateSaveBtn);
            } else {
                existing.querySelector('.custom-material-checkbox').checked = true;
            }
            nameEl.value = '';
            updateSaveBtn();
        } else {
            alert(data.message || 'Failed to create material.');
        }
    })
    .catch(function(e) { alert('Error: ' + e.message); });
}

function saveRecipeMappings() {
    var khaasId = document.getElementById('recipeKhaasProduct').value;
    if (!khaasId) return;

    var selectedIds = [];
    document.querySelectorAll('.recipe-material-checkbox').forEach(function(cb) {
        if (cb.checked) selectedIds.push(cb.value);
    });

    var selectedCustomIds = [];
    document.querySelectorAll('.custom-material-checkbox').forEach(function(cb) {
        if (cb.checked) selectedCustomIds.push(cb.value);
    });

    if (selectedIds.length === 0 && selectedCustomIds.length === 0) {
        alert('Select at least one raw material.');
        return;
    }

    var btn = document.getElementById('saveRecipeMappingsBtn');
    btn.disabled = true;
    btn.textContent = 'Saving...';

    var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    fetch('{{ route("khaas.inventory.recipe.save") }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
        body: JSON.stringify({
            khaas_product_id: khaasId,
            storage_product_id: selectedIds,
            custom_material_ids: selectedCustomIds
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            window.location.href = '{{ route("khaas.inventory", ["tab" => "recipes"]) }}';
        } else {
            alert(data.message || 'Failed to save mappings.');
            btn.disabled = false;
            btn.textContent = '💾 Save Mapping';
        }
    })
    .catch(function(e) {
        alert('Error saving mappings: ' + e.message);
        btn.disabled = false;
        btn.textContent = '💾 Save Mapping';
    });
}

// ══════════════════════════════════════════════════════════════════════════
//  ❄ INGREDIENTS & QUANTITIES TAB (Sep-2026)
//
//  Everything here talks to Khaas\RecipeController, the same controller the phone
//  calls, so the page and the app cannot show different recipes.
//
//  ⚠ Wrapped in an IIFE on purpose. A top-level `let` in a pushed script block is
//    SCRIPT-scoped, not sandboxed — two blades that both declare one collide and the
//    second script dies silently with "already declared".
//
//  ⚠⚠ And never spell a Blade directive out in here, not even inside a JS comment.
//     Blade compiles the whole file before any of it is JavaScript, so the bare word
//     turned into a live push call in the middle of this comment and took the page
//     down with a 500. Double the at-sign if one ever has to be named.
// ══════════════════════════════════════════════════════════════════════════
(function () {
    var tab = @json($activeTab);
    if (tab !== 'ingredients') { return; }

    var CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var BU   = @json($khaasBU->id ?? 2);

    var ingredients = [];   // the master list
    var gaps        = {items: [], not_linked: 0, never_bought: 0}; // recipe names purchasing has not met
    var lines       = [];   // rows in the recipe being edited
    var current     = null; // the recipe as loaded

    // Which display units a base unit may be typed in, and what they are worth.
    var UNITS = {g: ['g', 'kg'], ml: ['ml', 'L'], pcs: ['pcs']};

    function api(url, opts) {
        opts = opts || {};
        opts.headers = Object.assign({
            'Accept': 'application/json',
            'X-CSRF-TOKEN': CSRF,
            'X-Requested-With': 'XMLHttpRequest'
        }, opts.headers || {});
        if (opts.body && typeof opts.body !== 'string') {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(opts.body);
        }
        return fetch(url, opts)
            .then(function (r) {
                return r.json().catch(function () {
                    return {success: false, message: 'The server replied with something unreadable.'};
                });
            });
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    // ── ingredient master ──────────────────────────────────────────────
    function loadIngredients() {
        return api('{{ route('khaas.ingredients') }}?business_unit_id=' + BU)
            .then(function (d) {
                if (!d.success) { throw new Error(d.message || 'Could not load ingredients.'); }
                ingredients = d.ingredients || [];
                gaps = d.gaps || {items: [], not_linked: 0, never_bought: 0};
                LINK_VENDORS = d.link_vendors || [];
                renderIngredients(d.can_manage);
                renderGaps();
                return ingredients;
            })
            .catch(function (e) {
                document.getElementById('ingList').innerHTML =
                    '<div class="px-5 py-8 text-center text-sm" style="color:#B91C1C;">' + esc(e.message) + '</div>';
            });
    }

    function renderIngredients(canManage) {
        var box = document.getElementById('ingList');
        if (!ingredients.length) {
            box.innerHTML = '<div class="px-5 py-8 text-center text-sm text-gray-400">' +
                'No ingredients yet. Add salt, oil, cheese — whatever goes into a pack.</div>';
            return;
        }

        var lastKind = null;
        box.innerHTML = ingredients.map(function (i) {
            var head = '';
            if (i.kind_label !== lastKind) {
                lastKind = i.kind_label;
                head = '<div class="px-5 py-1.5 text-[10px] font-semibold uppercase tracking-wide text-gray-500" ' +
                       'style="background:#F9FAFB;">' + esc(i.kind_label) + '</div>';
            }
            var unit = i.base_unit === 'pcs' ? 'pieces' : (i.base_unit === 'ml' ? 'volume' : 'weight');
            return head +
                '<div class="px-5 py-2.5 flex items-center justify-between gap-3">' +
                    '<div class="min-w-0">' +
                        '<div class="text-sm text-gray-900 truncate">' + esc(i.name) +
                            (i.is_meat ? '<span class="ml-2 text-[10px] px-1.5 py-0.5 rounded-full" ' +
                                'style="background:#FEE2E2;color:#991B1B;">from storage</span>' : '') +
                        '</div>' +
                        '<div class="text-[11px] text-gray-500">measured by ' + unit + gapBadge(i.id) + '</div>' +
                    '</div>' +
                    (canManage
                        ? '<div class="flex items-center gap-3 shrink-0">' +
                            '<button type="button" class="text-xs text-gray-500 hover:text-gray-800 ing-stock" ' +
                              'data-id="' + i.id + '" title="Say how much is on the shelf">Stock</button>' +
                            '<button type="button" class="text-xs text-gray-500 hover:text-gray-800 ing-edit" ' +
                              'data-id="' + i.id + '">Edit</button>' +
                          '</div>'
                        : '') +
                '</div>';
        }).join('');

        Array.prototype.forEach.call(box.querySelectorAll('.ing-edit'), function (b) {
            b.onclick = function () { openIngForm(Number(b.getAttribute('data-id'))); };
        });
        Array.prototype.forEach.call(box.querySelectorAll('.ing-stock'), function (b) {
            b.onclick = function () { openStock(Number(b.getAttribute('data-id'))); };
        });
    }

    /**
     * ❄ Opening stock, and later physical counts.
     *
     * Without a starting figure "what is left" is honestly NOT TRACKED — remaining is
     * cumulative, so there is nothing to count down from. This is the door that starts
     * it, and the same door records a count later.
     *
     * ⚠ A count RE-ANCHORS the running figure on its date; it does not write a shortfall
     *   off as consumption. Inventing usage would inflate a cost that is already an
     *   estimate, and nobody could separate the invented part afterwards.
     */
    function openStock(id) {
        var ing = ingredients.filter(function (x) { return x.id === id; })[0];
        if (!ing) { return; }

        var units = ing.display_units || [ing.base_unit];
        var unit  = units[units.length - 1];

        var isCount = confirm(
            'Stock for ' + ing.name + '\n\n' +
            'OK      = a physical COUNT (what is on the shelf right now)\n' +
            'Cancel  = OPENING stock (the starting figure)\n\n' +
            'Either way the next screen asks how much.'
        );

        var typed = prompt(
            (isCount ? 'Counted' : 'Opening') + ' stock of ' + ing.name + ', in ' + unit + ':',
            ''
        );
        if (typed === null) { return; }

        var qty = parseFloat(String(typed).replace(/,/g, ''));
        if (!isFinite(qty) || qty < 0) {
            alert('Give a number that is zero or more.');
            return;
        }

        api('{{ route('khaas.ingredients.opening') }}', {
            method: 'POST',
            body: {
                business_unit_id: BU,
                ingredient_id: id,
                qty: qty,
                unit: unit,
                kind: isCount ? 'count' : 'opening'
            }
        })
        .then(function (d) {
            alert((d && d.message) ? d.message : (d && d.success ? 'Saved.' : 'Could not save that.'));
        })
        .catch(function () { alert('Could not reach the server. Nothing was saved.'); });
    }

    /** The badge beside an ingredient that a recipe names but purchasing has not met. */
    function gapBadge(ingredientId) {
        var g = (gaps.items || []).filter(function (x) { return x.ingredient_id === ingredientId; })[0];
        if (!g) { return ''; }
        var label = g.state === 'not_linked' ? 'no vendor product yet' : 'never bought yet';
        return ' · <span style="color:#B45309;font-weight:600;">' + label + '</span>';
    }

    /**
     * ❄ The box at the top of the list: every ingredient a recipe names that purchasing
     * has never seen, with the exact spelling to use on the vendor side. This is how
     * "salt" in a recipe and "salt" as a vendor product end up being the same salt.
     */
    // 🔗 Sep-27: "Link at…" — open that vendor's Manage Products with the ingredient
    // pre-chosen. Only vendors whose bills are entered line by line are offered.
    var LINK_VENDORS = [];
    var VENDOR_PRODUCTS_URL = @json(route('fin.vendors.products', ['id' => '__VID__']));

    function linkPicker(ingredientId) {
        if (!LINK_VENDORS.length) { return ''; }
        return ' <select class="ing-link text-[11px] ml-1 px-1 py-0.5 border border-indigo-200 rounded bg-white" ' +
            'style="color:#4338CA;" data-id="' + ingredientId + '">' +
            '<option value="">🔗 Link at…</option>' +
            LINK_VENDORS.map(function (v) { return '<option value="' + v.id + '">' + esc(v.vendor_name) + '</option>'; }).join('') +
            '</select>';
    }

    function wireLinkPickers(box) {
        Array.prototype.forEach.call(box.querySelectorAll('.ing-link'), function (s) {
            s.onchange = function () {
                if (!s.value) { return; }
                window.location.href = VENDOR_PRODUCTS_URL.replace('__VID__', s.value) +
                    '?link_ingredient=' + encodeURIComponent(s.getAttribute('data-id'));
            };
        });
    }

    function renderGaps() {
        var box = document.getElementById('ingGaps');
        var items = gaps.items || [];
        if (!items.length) { box.classList.add('hidden'); box.innerHTML = ''; return; }

        var notLinked = items.filter(function (x) { return x.state === 'not_linked'; });
        var neverBought = items.filter(function (x) { return x.state === 'never_bought'; });

        var html = '<div class="text-xs font-semibold mb-1" style="color:#92400E;">' +
            '⚠ In a recipe, but purchasing has not seen it</div>';

        if (notLinked.length) {
            html += '<div class="text-[11px] text-gray-700 mb-1">Not linked to any vendor product — add each one as a product, ' +
                    '<b>with this exact name</b>, under the vendor you buy it from:</div>' +
                    '<ul class="text-[12px] mb-2" style="color:#78350F;">' +
                    notLinked.map(function (g) {
                        return '<li>• <b>' + esc(g.name) + '</b> <span class="text-gray-500">— in ' + g.recipe_count +
                               ' recipe' + (g.recipe_count === 1 ? '' : 's') + '</span>' + linkPicker(g.ingredient_id) + '</li>';
                    }).join('') + '</ul>';
        }
        if (neverBought.length) {
            html += '<div class="text-[11px] text-gray-700 mb-1">Linked to a vendor product, but nothing has been bought yet — ' +
                    'the cost per pack leaves these out until the first bill:</div>' +
                    '<ul class="text-[12px]" style="color:#78350F;">' +
                    neverBought.map(function (g) {
                        // ⭐ linked only where bills are one total: no bill line will ever price it
                        var why = g.at_total_only
                            ? ' <span style="color:#B45309;font-weight:600;">— ' + esc((g.linked_vendors || []).join(', ')) +
                              ' enters bills as one total, so no price will come; switch it to by weight or link elsewhere</span>'
                            : ' <span class="text-gray-500">— ' + esc((g.linked_vendors || []).join(', ')) + '</span>';
                        return '<li>• <b>' + esc(g.name) + '</b>' + why + linkPicker(g.ingredient_id) + '</li>';
                    }).join('') + '</ul>';
        }

        box.innerHTML = html;
        box.classList.remove('hidden');
        wireLinkPickers(box);
    }

    function openIngForm(id) {
        var form = document.getElementById('ingForm');
        var ing  = id ? ingredients.filter(function (x) { return x.id === id; })[0] : null;

        document.getElementById('ingFormId').value    = ing ? ing.id : '';
        document.getElementById('ingFormName').value  = ing ? ing.name : '';
        document.getElementById('ingFormUnit').value  = ing ? ing.base_unit : 'g';
        document.getElementById('ingFormKind').value  = ing ? ing.kind : 'other';
        // The server refuses a unit change on an ingredient already in use; say so here
        // rather than letting someone type it and be turned away.
        document.getElementById('ingFormUnit').disabled = !!ing;
        document.getElementById('ingFormLock').classList.toggle('hidden', !ing);
        var uc = document.getElementById('ingUnitChange');
        uc.classList.add('hidden');
        uc.innerHTML = '';
        document.getElementById('ingUnitChangeBtn').onclick = function () { if (ing) { openUnitChange(ing); } };

        form.classList.remove('hidden');
        document.getElementById('ingFormName').focus();
    }

    document.getElementById('ingAddBtn').onclick   = function () { openIngForm(null); };
    document.getElementById('ingFormCancel').onclick = function () {
        document.getElementById('ingForm').classList.add('hidden');
    };

    document.getElementById('ingFormSave').onclick = function () {
        var btn  = this;
        var name = document.getElementById('ingFormName').value.trim();
        if (!name) { alert('Give the ingredient a name.'); return; }

        btn.disabled = true;
        var payload = {
            business_unit_id: BU,
            name: name,
            kind: document.getElementById('ingFormKind').value
        };
        var id = document.getElementById('ingFormId').value;
        if (id) { payload.id = Number(id); }
        else    { payload.base_unit = document.getElementById('ingFormUnit').value; }

        api('{{ route('khaas.ingredients.save') }}', {method: 'POST', body: payload})
            .then(function (d) {
                btn.disabled = false;
                if (!d.success) { alert(d.message || 'Could not save that.'); return; }
                document.getElementById('ingForm').classList.add('hidden');
                loadIngredients().then(refreshLineSelects);
            })
            .catch(function () {
                btn.disabled = false;
                alert('Could not reach the server. Nothing was saved.');
            });
    };

    // ── the recipe editor ──────────────────────────────────────────────
    function loadProducts() {
        return api('{{ route('khaas.recipe.coverage') }}?business_unit_id=' + BU)
            .then(function (d) {
                if (!d.success) { return; }
                var sel = document.getElementById('recProduct');
                (d.products || []).forEach(function (p) {
                    var o = document.createElement('option');
                    o.value = p.product_id;
                    o.textContent = p.product_name + (p.has_recipe ? '' : '  — no recipe yet');
                    sel.appendChild(o);
                });
            });
    }

    function unitOptions(baseUnit, chosen) {
        return (UNITS[baseUnit] || [baseUnit]).map(function (u) {
            return '<option value="' + u + '"' + (u === chosen ? ' selected' : '') + '>' + u + '</option>';
        }).join('');
    }

    function renderLines() {
        var box = document.getElementById('recLines');
        document.getElementById('recEmpty').classList.toggle('hidden', lines.length > 0);

        box.innerHTML = lines.map(function (ln, idx) {
            var ing = ingredients.filter(function (x) { return x.id === ln.ingredient_id; })[0];
            var base = ing ? ing.base_unit : 'g';
            return '<div class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2">' +
                '<select class="rec-ing flex-1 min-w-0 px-2 py-1.5 border border-gray-300 rounded text-sm bg-white" data-i="' + idx + '">' +
                    ingredients.map(function (i) {
                        return '<option value="' + i.id + '"' + (i.id === ln.ingredient_id ? ' selected' : '') + '>' +
                               esc(i.name) + '</option>';
                    }).join('') +
                '</select>' +
                '<input type="number" step="0.001" min="0" value="' + (ln.qty || '') + '" ' +
                    'class="rec-qty w-24 px-2 py-1.5 border border-gray-300 rounded text-sm" data-i="' + idx + '" placeholder="0">' +
                '<select class="rec-unit w-20 px-2 py-1.5 border border-gray-300 rounded text-sm bg-white" data-i="' + idx + '">' +
                    unitOptions(base, ln.unit) +
                '</select>' +
                '<span class="rec-per text-[11px] text-gray-500 w-24 text-right"></span>' +
                '<span class="rec-cost text-[11px] w-28 text-right"></span>' +
                '<button type="button" class="rec-del text-gray-400 hover:text-red-600 px-1" data-i="' + idx + '">✕</button>' +
            '</div>';
        }).join('');

        Array.prototype.forEach.call(box.querySelectorAll('.rec-ing'), function (el) {
            el.onchange = function () {
                var i = Number(el.getAttribute('data-i'));
                lines[i].ingredient_id = Number(el.value);
                var ing = ingredients.filter(function (x) { return x.id === lines[i].ingredient_id; })[0];
                // Keep the typed unit legal for the new ingredient.
                var allowed = UNITS[ing ? ing.base_unit : 'g'] || ['g'];
                if (allowed.indexOf(lines[i].unit) === -1) { lines[i].unit = allowed[allowed.length - 1]; }
                renderLines();
            };
        });
        Array.prototype.forEach.call(box.querySelectorAll('.rec-qty'), function (el) {
            el.oninput = function () {
                lines[Number(el.getAttribute('data-i'))].qty = el.value;
                updatePerPack();
            };
        });
        Array.prototype.forEach.call(box.querySelectorAll('.rec-unit'), function (el) {
            el.onchange = function () {
                lines[Number(el.getAttribute('data-i'))].unit = el.value;
                updatePerPack();
            };
        });
        Array.prototype.forEach.call(box.querySelectorAll('.rec-del'), function (el) {
            el.onclick = function () {
                lines.splice(Number(el.getAttribute('data-i')), 1);
                renderLines();
            };
        });

        updatePerPack();
    }

    // The per-pack column is the whole point of entering a batch: it shows, live, what
    // the number typed actually means for one pack.
    function updatePerPack() {
        var basis = parseInt(document.getElementById('recBasis').value, 10) || 0;
        var box   = document.getElementById('recLines');
        var spans = box.querySelectorAll('.rec-per');

        Array.prototype.forEach.call(spans, function (span, idx) {
            var ln  = lines[idx];
            var qty = parseFloat(ln && ln.qty);
            if (!basis || !qty || qty <= 0) { span.textContent = ''; return; }

            var factor = (ln.unit === 'kg' || ln.unit === 'L') ? 1000 : 1;
            var base   = qty * factor / basis;
            var ing    = ingredients.filter(function (x) { return x.id === ln.ingredient_id; })[0];
            var u      = ing ? ing.base_unit : 'g';

            var shown = base, unit = u;
            if ((u === 'g' || u === 'ml') && Math.abs(base) >= 1000) {
                shown = base / 1000;
                unit  = (u === 'g') ? 'kg' : 'L';
            }
            span.textContent = (Math.round(shown * 1000) / 1000) + ' ' + unit + '/pack';
        });

        updateCost();
    }

    // ── 💲 the recipe at today's prices ────────────────────────────────
    // PRICES is the unit's price book from the server: ingredient id => the newest
    // bill's price per BASE unit (g / ml / piece). A missing key means "no price" — it
    // is never treated as zero. Without view_khaas_costing the keys arrive with the
    // rupees nulled, so the page can still say which lines are priced.
    var PRICES   = {};
    var CAN_COST = false;
    var SELL     = null;
    var SUPPLY   = {};   // ingredient id => supply_state, from the loaded recipe

    function rs(n, dp) {
        return 'Rs ' + Number(n).toLocaleString('en-PK', {minimumFractionDigits: dp || 0, maximumFractionDigits: dp || 0});
    }

    function lineBase(ln) {
        var qty = parseFloat(ln && ln.qty);
        if (!(qty > 0)) { return 0; }
        return qty * ((ln.unit === 'kg' || ln.unit === 'L') ? 1000 : 1);
    }

    function whyNoPrice(ln) {
        var ing = ingredients.filter(function (x) { return x.id === ln.ingredient_id; })[0];
        if (ing && ing.is_meat) { return 'no meat order price yet'; }
        var st = SUPPLY[ln.ingredient_id];
        if (!st) {
            // A line just added: the page-wide gaps list may still know its state.
            var g = (gaps.items || []).filter(function (x) { return x.ingredient_id === ln.ingredient_id; })[0];
            st = g ? g.state : null;
        }
        if (st === 'not_linked') { return 'not a vendor product yet'; }
        if (st === 'never_bought') { return 'no bill yet'; }
        return 'no price yet';
    }

    function updateCost() {
        var basis = parseInt(document.getElementById('recBasis').value, 10) || 0;
        var spans = document.getElementById('recLines').querySelectorAll('.rec-cost');
        var box   = document.getElementById('recCost');

        var batch = 0, priced = 0, missing = [], old = [], jumps = [];

        Array.prototype.forEach.call(spans, function (span, idx) {
            var ln = lines[idx];
            var p  = ln ? PRICES[ln.ingredient_id] : null;
            var ing = ln ? ingredients.filter(function (x) { return x.id === ln.ingredient_id; })[0] : null;
            var name = ing ? ing.name : 'an ingredient';
            if (!ln) { span.textContent = ''; return; }

            if (!p) {
                span.innerHTML = '<span style="color:#B45309;">' + esc(whyNoPrice(ln)) + '</span>';
                missing.push(name + ' (' + whyNoPrice(ln) + ')');
                return;
            }
            priced++;
            if (p.stale) { old.push(name + (CAN_COST && p.price_text ? ' — ' + p.price_text : '') + ', bill of ' + p.bought_on); }
            if (p.jump && p.previous) {
                jumps.push(name + (CAN_COST && p.price_text ? ': ' + p.price_text + ' on ' + p.bought_on +
                    ', was ' + p.previous.price_text + ' on ' + p.previous.bought_on : ''));
            }
            if (!CAN_COST || p.price_per_base == null) { span.textContent = '✓ priced'; span.style.color = '#4338CA'; return; }

            var cost = lineBase(ln) * p.price_per_base;
            batch += cost;
            span.style.color = '#312E81';
            span.title = p.price_text + ' · ' + (p.source === 'meat_order' ? 'meat order' : (p.source === 'older_bill' ? 'older bill' : 'bill')) +
                ' of ' + p.bought_on + (p.vendor_name ? ' · ' + p.vendor_name : '');
            span.textContent = lineBase(ln) > 0 ? rs(cost) + (basis ? ' · ' + rs(cost / basis, 2) + '/pack' : '') : '';
        });

        if (!lines.length) { box.classList.add('hidden'); return; }

        var h = '';
        if (CAN_COST && priced > 0) {
            h += '<div class="font-semibold">💲 At today’s prices: ' + rs(batch) + ' a batch' +
                (basis ? ' · <b>' + rs(batch / basis, 2) + ' a pack</b>' : '') +
                (basis && SELL ? ' — ' + (Math.round(batch / basis * 1000 / SELL) / 10) + '% of the ' + rs(SELL) + ' price' : '') +
                '</div>';
        } else if (priced === 0) {
            h += '<div class="font-semibold">💲 No line has a price yet, so this recipe cannot be costed.</div>';
        }
        if (missing.length) {
            h += '<div class="mt-1" style="color:#92400E;">Not counted' + (priced ? ', so the real cost is higher' : '') +
                ': ' + esc(missing.join(', ')) + '.</div>';
        }
        if (old.length) {
            h += '<div class="mt-1" style="color:#92400E;">⚠ Old price (over 60 days): ' + esc(old.join('; ')) + '.</div>';
        }
        if (jumps.length) {
            h += '<div class="mt-1" style="color:#B91C1C;">⚠ Price jumped 3× or more — check the bill (kg typed as g?): ' +
                esc(jumps.join('; ')) + '.</div>';
        }
        h += '<div class="mt-1 text-[10px]" style="color:#6366F1;">Each price is from that ingredient’s newest bill; ' +
             'meat from the latest meat order. Month Review uses the month’s average instead.</div>';
        box.innerHTML = h;
        box.classList.remove('hidden');
    }

    // ── 🔀 change how an ingredient is measured, everywhere ─────────────
    var UNIT_IMPACT_URL = @json(route('khaas.ingredients.unit-impact', ['id' => '__ID__']));
    var CHANGE_UNIT_URL = @json(route('khaas.ingredients.change-unit', ['id' => '__ID__']));
    var KIND_WORD = {g: 'weight', ml: 'volume', pcs: 'pieces'};

    function openUnitChange(ing) {
        var box = document.getElementById('ingUnitChange');
        box.innerHTML = '<div class="text-xs text-gray-700 mb-1">Change ' + esc(ing.name) + ' from ' +
            esc(KIND_WORD[ing.base_unit]) + ' to:</div>' +
            ['g', 'ml', 'pcs'].filter(function (u) { return u !== ing.base_unit; }).map(function (u) {
                return '<button type="button" class="ing-to text-xs px-2.5 py-1 mr-1 rounded border border-gray-300 bg-white" data-to="' +
                    u + '">' + esc(KIND_WORD[u]) + '</button>';
            }).join('');
        box.classList.remove('hidden');
        Array.prototype.forEach.call(box.querySelectorAll('.ing-to'), function (b) {
            b.onclick = function () {
                api(UNIT_IMPACT_URL.replace('__ID__', ing.id) + '?to=' + b.getAttribute('data-to'))
                    .then(function (d) {
                        if (!d.success) { alert(d.message || 'Could not check that.'); return; }
                        renderUnitImpact(d);
                    })
                    .catch(function () { alert('Could not reach the server.'); });
            };
        });
    }

    function renderUnitImpact(c) {
        var box = document.getElementById('ingUnitChange');
        var h = '<div class="p-3 rounded-md text-xs" style="background:#EEF2FF;border:1px solid #A5B4FC;">' +
            '<div class="font-semibold" style="color:#312E81;">Change ' + esc(c.ingredient.name) + ' from ' + esc(c.from_word) +
            ' to ' + esc(c.to_word) + ' everywhere?</div>';
        if (!c.can_change) {
            h += '<p class="mt-2" style="color:#991B1B;">It cannot change: ' + esc((c.locked_reasons || []).join('; ')) +
                 '. Those numbers were written in ' + esc(c.from_word) + ' and would silently mean something else.</p>';
        } else if (c.can_manage === false) {
            h += '<p class="mt-2" style="color:#991B1B;">Only Taimur, Shabib or Qasim can change an ingredient’s unit.</p>';
        } else {
            if ((c.recipe_lines || []).length) {
                h += '<p class="mt-2 text-gray-700">These recipes use it. ' + esc(c.from_word) + ' cannot be turned into ' +
                     esc(c.to_word) + ' by arithmetic, so type each new amount in ' + esc(c.to_base_word) + ':</p>';
                c.recipe_lines.forEach(function (l) {
                    h += '<label class="flex items-center gap-2 mt-1"><span class="flex-1">' + esc(l.product_name) + ' v' + l.version +
                         (l.is_current ? ' (current)' : '') + ' — was ' + Number(l.qty) + ' ' + esc(c.from_base_word || c.from) + '</span>' +
                         '<input type="number" step="0.001" min="0.001" class="iu-recipe w-24 px-2 py-1 border rounded" data-id="' + l.id +
                         '" placeholder="' + esc(c.to_base_word) + '"></label>';
                });
            } else {
                h += '<p class="mt-2 text-gray-700">No recipe uses it yet.</p>';
            }
            (c.products || []).forEach(function (pr) {
                h += '<div class="flex items-center gap-2 mt-1"><span class="flex-1">' + esc(pr.vendor_name || '') + ' · ' +
                     esc(pr.product_name) + ' (' + esc(pr.unit) + ')' +
                     (pr.needs_size ? '' : ' — automatic, ' + pr.new_pack_qty_base + ' ' + esc(c.to_base_word) + ' each') + '</span>' +
                     (pr.needs_size ? '<input type="number" step="0.001" min="0.001" class="iu-size w-24 px-2 py-1 border rounded" data-id="' +
                        pr.id + '" placeholder="' + esc(c.to_base_word) + ' in one">' : '') + '</div>';
            });
            h += '<button type="button" id="iuApply" class="mt-3 px-3 py-1.5 rounded text-white font-semibold" style="background:#4F46E5;">Change everywhere</button>';
        }
        h += ' <button type="button" id="iuCancel" class="mt-3 px-3 py-1.5 text-gray-600">Keep it in ' + esc(c.from_word) + '</button></div>';
        box.innerHTML = h;

        document.getElementById('iuCancel').onclick = function () { box.classList.add('hidden'); box.innerHTML = ''; };
        var apply = document.getElementById('iuApply');
        if (!apply) { return; }
        apply.onclick = function () {
            var body = {to: c.to, recipe_qty: {}, product_sizes: {}};
            var bad = null;
            Array.prototype.forEach.call(box.querySelectorAll('.iu-recipe'), function (i) {
                var v = Number(i.value);
                if (!(v > 0) || (c.to === 'pcs' && Math.round(v) !== v)) { bad = bad || i; }
                body.recipe_qty[i.getAttribute('data-id')] = v;
            });
            Array.prototype.forEach.call(box.querySelectorAll('.iu-size'), function (i) {
                if (!(Number(i.value) > 0)) { bad = bad || i; }
                body.product_sizes[i.getAttribute('data-id')] = Number(i.value);
            });
            if (bad) {
                alert(c.to === 'pcs' ? 'Type every new amount — pieces are whole numbers.' : 'Type every new amount before changing.');
                bad.focus();
                return;
            }
            apply.disabled = true;
            api(CHANGE_UNIT_URL.replace('__ID__', c.ingredient.id), {method: 'POST', body: body})
                .then(function (d) {
                    apply.disabled = false;
                    alert(d.message || (d.success ? 'Changed.' : 'Nothing was changed.'));
                    if (!d.success) { return; }
                    box.classList.add('hidden');
                    box.innerHTML = '';
                    document.getElementById('ingForm').classList.add('hidden');
                    // Recipes were re-typed on the server: reload what is on screen.
                    loadIngredients().then(function () {
                        var sel = document.getElementById('recProduct');
                        if (sel.value) { sel.dispatchEvent(new Event('change')); }
                    });
                })
                .catch(function () {
                    apply.disabled = false;
                    alert('Could not reach the server. Nothing was changed.');
                });
        };
    }

    function refreshLineSelects() { if (lines.length) { renderLines(); } }

    document.getElementById('recBasis').oninput = updatePerPack;

    document.getElementById('recAddLine').onclick = function () {
        if (!ingredients.length) {
            alert('Add an ingredient on the left first.');
            return;
        }
        var first = ingredients[0];
        var allowed = UNITS[first.base_unit] || ['g'];
        lines.push({ingredient_id: first.id, qty: '', unit: allowed[allowed.length - 1]});
        renderLines();
    };

    document.getElementById('recProduct').onchange = function () {
        var pid = this.value;
        var body = document.getElementById('recBody');
        if (!pid) { body.classList.add('hidden'); return; }

        api('{{ route('khaas.recipe.show') }}?business_unit_id=' + BU + '&product_id=' + pid)
            .then(function (d) {
                if (!d.success) { alert(d.message || 'Could not load that recipe.'); return; }

                current = d.recipe;
                ingredients = d.ingredients || ingredients;
                PRICES   = d.prices || {};
                CAN_COST = !!d.can_see_cost;
                SELL     = d.selling_price || null;
                SUPPLY   = {};
                (current.lines || []).forEach(function (l) { SUPPLY[l.ingredient_id] = l.supply_state; });

                document.getElementById('recBasis').value = current.basis_packets || '';
                document.getElementById('recNote').value  = current.note || '';

                lines = (current.lines || []).map(function (l) {
                    // Show a stored base quantity back in the unit a person would type.
                    var qty = l.qty_per_basis, unit = l.base_unit;
                    if ((l.base_unit === 'g' || l.base_unit === 'ml') && Math.abs(qty) >= 1000) {
                        qty = qty / 1000;
                        unit = (l.base_unit === 'g') ? 'kg' : 'L';
                    }
                    return {ingredient_id: l.ingredient_id, qty: qty ? String(qty) : '', unit: unit};
                });

                var v = document.getElementById('recVersion');
                if (current.has_recipe) {
                    v.textContent = 'Version ' + current.version + ', in force from ' + current.effective_from;
                } else if (current.is_suggestion && lines.length) {
                    v.textContent = 'No recipe yet — the meat is filled in from the existing mapping.';
                } else {
                    v.textContent = 'No recipe yet.';
                }

                var warn = document.getElementById('recWarn');
                if ((current.warnings || []).length) {
                    warn.innerHTML = current.warnings.map(esc).join('<br>');
                    warn.classList.remove('hidden');
                } else {
                    warn.classList.add('hidden');
                }

                body.classList.remove('hidden');
                renderLines();
            });
    };

    document.getElementById('recSave').onclick = function () {
        var btn   = this;
        var pid   = document.getElementById('recProduct').value;
        var basis = parseInt(document.getElementById('recBasis').value, 10) || 0;

        if (!pid) { alert('Choose a product first.'); return; }
        if (basis < 1) { alert('Say how many packs one batch makes.'); return; }
        if (!lines.length) { alert('A recipe needs at least one ingredient.'); return; }

        var bad = lines.filter(function (l) { return !(parseFloat(l.qty) > 0); });
        if (bad.length) { alert('Every ingredient needs a quantity.'); return; }

        btn.disabled = true;
        btn.textContent = 'Saving…';

        api('{{ route('khaas.recipe.save') }}', {
            method: 'POST',
            body: {
                business_unit_id: BU,
                product_id: Number(pid),
                basis_packets: basis,
                note: document.getElementById('recNote').value,
                lines: lines.map(function (l) {
                    return {ingredient_id: l.ingredient_id, qty: parseFloat(l.qty), unit: l.unit};
                })
            }
        })
        .then(function (d) {
            btn.disabled = false;
            btn.textContent = 'Save recipe';
            alert(d.message || (d.success ? 'Saved.' : 'Could not save that recipe.'));
            if (d.success) { document.getElementById('recProduct').dispatchEvent(new Event('change')); }
        })
        .catch(function () {
            btn.disabled = false;
            btn.textContent = 'Save recipe';
            alert('Could not reach the server. Nothing was saved.');
        });
    };

    loadIngredients().then(loadProducts);
})();
</script>
@endpush
