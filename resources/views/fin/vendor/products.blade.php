@extends('layouts.app')

@section('title', e($vendor->vendor_name) . ' — Products')

@section('content')
@php
    /*
     * ⭐ Which ingredients this vendor ALREADY has a product for. A list of 13 where 11 are
     *   on the shelf hides the 2 that still need adding, so the not-yet-added ones go
     *   first and the rest sit under a heading.
     *
     * ⚠⚠ MARKED, NEVER REMOVED. A vendor legitimately stocks two products for one
     *    ingredient — oil in a 1-litre bottle and a 5-litre tin, cheese in a 400 g pack
     *    and a 1 kg block. Drop the name the moment the first one exists and whoever adds
     *    the second invents their own spelling, which is the exact drift this whole
     *    feature exists to prevent.
     */
    $ingAdded = [];
    foreach ($products as $vp) {
        $iid = (int) ($vp->ingredient_id ?? 0);
        if ($iid && !isset($ingAdded[$iid])) {
            $ingAdded[$iid] = trim((string) $vp->product_name);
        }
    }
    $ingFresh = array_values(array_filter($ingredients, fn ($i) => !isset($ingAdded[(int) $i['id']])));
    $ingOld   = array_values(array_filter($ingredients, fn ($i) =>  isset($ingAdded[(int) $i['id']])));
@endphp
<div class="max-w-7xl mx-auto p-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">{{ $vendor->vendor_name }} - Products</h1>
            <p class="text-sm text-gray-600 mt-1">Manage vendor-specific products for weighted purchases</p>
        </div>
        <a href="{{ route('fin.vendors.show', $vendor->id) }}" 
           class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            ← Back to Vendor
        </a>
    </div>

    <!-- Success/Error Messages -->
    <div id="messageContainer"></div>

    <!-- Add Product Form -->
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-6 mb-6">
        <h2 class="text-lg font-medium text-gray-900 mb-4">➕ Add New Product</h2>
        <form id="addProductForm" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Product Name <span class="text-red-500">*</span></label>
                {{-- ❄ list="ingNames": the Frozen ingredient names suggest themselves as you type,
                     so "salt" here is spelled the way the recipe spells it. Picking one also
                     fills the ingredient field below (see the JS). --}}
                <input type="text" id="product_name" required placeholder="e.g., Chicken Breast" list="ingNames" autocomplete="off"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                <p id="product_name_hint" class="text-xs mt-1 hidden" style="color:#4338CA;"></p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Unit <span class="text-red-500">*</span></label>
                {{-- ⭐ Sep-26: the ONE unit list (App\Services\FIN\VendorUnits) — the phone renders the same one. --}}
                <select id="unit" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">-- Select Unit --</option>
                    @foreach($unitCatalogue as $u)
                        <option value="{{ $u['code'] }}">{{ $u['one'] }} — {{ $u['qty_label'] }}</option>
                    @endforeach
                </select>
                <div id="unit_mismatch" class="hidden mt-2 p-2 rounded-md text-xs" style="background:#FFF7ED;border:1px solid #FDBA74;color:#9A3412;"></div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Rate per Unit (Rs.) <span class="text-red-500">*</span></label>
                <input type="number" id="rate_per_unit" step="0.01" min="0.01" required placeholder="0.00"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            @if(!empty($ingredients))
            <div>
                {{-- ❄ What this product IS, for Frozen. Optional; an untagged product behaves
                     exactly as it always has. Once tagged, every purchase of it counts
                     towards that ingredient's stock and its cost per pack. --}}
                <label class="block text-sm font-medium text-gray-700 mb-1">Frozen ingredient</label>
                <select id="ingredient_id"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">— not an ingredient —</option>
                    {{-- ⭐ The ones this vendor has no product for come first. The rest are
                         grouped, not dropped — a second pack size needs the same name. --}}
                    @foreach($ingFresh as $ing)
                        <option value="{{ $ing['id'] }}" data-unit="{{ $ing['base_unit'] }}">{{ $ing['name'] }}</option>
                    @endforeach
                    @if(!empty($ingOld))
                    <optgroup label="Already on this vendor's list">
                        @foreach($ingOld as $ing)
                            <option value="{{ $ing['id'] }}" data-unit="{{ $ing['base_unit'] }}">{{ $ing['name'] }} — already “{{ $ingAdded[(int) $ing['id']] }}”</option>
                        @endforeach
                    </optgroup>
                    @endif
                </select>
            </div>
            <div id="pack_qty_wrap" class="hidden">
                <label class="block text-sm font-medium text-gray-700 mb-1">How much in one <span id="pack_qty_unit">unit</span>?</label>
                <input type="number" id="pack_qty_base" step="0.001" min="0.001" placeholder="e.g. 400"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                <p class="text-xs text-gray-500 mt-1" id="pack_qty_help">A pack could be any size, so say how many <span class="pq-base">grams</span> one holds.</p>
            </div>
            <div id="change_panel" class="hidden md:col-span-4"></div>
            @endif
            <div>
                {{-- Feeds the Category Report (Products → Sales vs Purchase). --}}
                <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                <select id="category_level_1"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">— untagged —</option>
                    @foreach(($categories ?? []) as $cat)
                        <option value="{{ $cat }}">{{ $cat }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="inline-flex items-center cursor-pointer mb-2">
                    <input type="checkbox" id="is_default" class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                    <span class="ml-2 text-sm font-medium text-gray-700">⭐ Set as Default</span>
                </label>
                <button type="submit" 
                        class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md"
                        style="background-color: #2563eb !important; color: white !important;">
                    <span style="color: white !important;">✓ Add Product</span>
                </button>
            </div>
        </form>
    </div>

    <!-- Products List -->
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-medium text-gray-900">Product Catalog ({{ count($products) }} items)</h2>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200" id="productsTable">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Product Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Unit</th>
                        @if(!empty($ingredients))
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Frozen ingredient</th>
                        @endif
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Rate per Unit</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Default</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200" id="productsTableBody">
                    @forelse($products as $product)
                        <tr class="hover:bg-gray-50" data-product-id="{{ $product->id }}">
                            <td class="px-6 py-4 text-sm text-gray-900 font-medium">
                                {{ $product->product_name }}
                                @if($product->is_default)
                                    <span class="ml-2 text-yellow-500" title="Default Product">⭐</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm">
                                @if(trim((string) $product->category_level_1) !== '')
                                    <span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#F3F4F6;color:#374151;font-size:11px;font-weight:600;">{{ $product->category_level_1 }}</span>
                                @else
                                    <span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#FEF3C7;color:#92400E;font-size:11px;font-weight:600;">untagged</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600">{{ $product->unit }}</td>
                            @if(!empty($ingredients))
                            <td class="px-6 py-4 text-sm">
                                @php $ingName = collect($ingredients)->firstWhere('id', (int) ($product->ingredient_id ?? 0))['name'] ?? null; @endphp
                                @if($ingName)
                                    <span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#EEF2FF;color:#3730A3;font-size:11px;font-weight:600;" title="one {{ $product->unit }} = {{ rtrim(rtrim(number_format((float) $product->pack_qty_base, 3, '.', ','), '0'), '.') }} base units">{{ $ingName }}</span>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                            @endif
                            <td class="px-6 py-4 text-sm text-gray-900 text-right font-semibold">Rs. {{ number_format($product->rate_per_unit, 2) }}</td>
                            <td class="px-6 py-4 text-center">
                                <button onclick="setAsDefault({{ $product->id }}, {{ $product->is_default ? 'true' : 'false' }})"
                                        class="px-3 py-1 text-xs rounded-md default-badge-{{ $product->id }}
                                            {{ $product->is_default ? 'bg-yellow-100 text-yellow-800 cursor-not-allowed' : 'bg-gray-100 text-gray-600 hover:bg-yellow-50' }}">
                                    {{ $product->is_default ? '⭐ Default' : 'Set Default' }}
                                </button>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span class="px-2 py-1 text-xs font-semibold rounded-full status-badge-{{ $product->id }}
                                    {{ $product->is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                    {{ $product->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <div class="flex justify-center gap-2">
                                    <button onclick='editProduct({{ $product->id }}, {{ json_encode($product->product_name) }}, "{{ $product->unit }}", {{ $product->rate_per_unit }}, {{ $product->is_default ? 'true' : 'false' }}, {{ json_encode((string) $product->category_level_1) }}, {{ (int) ($product->ingredient_id ?? 0) }}, {{ (float) ($product->pack_qty_base ?? 0) }})'
                                            class="px-3 py-1 text-xs bg-blue-100 text-blue-700 rounded-md hover:bg-blue-200">
                                        ✏️ Edit
                                    </button>
                                    <button onclick="toggleStatus({{ $product->id }}, {{ $product->is_active ? 'true' : 'false' }})"
                                            class="px-3 py-1 text-xs bg-yellow-100 text-yellow-700 rounded-md hover:bg-yellow-200">
                                        {{ $product->is_active ? '🔒 Disable' : '✅ Enable' }}
                                    </button>
                                    <button onclick="deleteProduct({{ $product->id }})"
                                            class="px-3 py-1 text-xs bg-red-100 text-red-700 rounded-md hover:bg-red-200">
                                        🗑️ Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-sm text-gray-500">
                                No products added yet. Add your first product above.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Product Modal -->
<div id="editModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full" onclick="event.stopPropagation()">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-gray-800">✏️ Edit Product</h3>
                <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            <form id="editProductForm">
                <input type="hidden" id="edit_product_id">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Product Name <span class="text-red-500">*</span></label>
                        <input type="text" id="edit_product_name" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Unit <span class="text-red-500">*</span></label>
                        <select id="edit_unit" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            @foreach($unitCatalogue as $u)
                                <option value="{{ $u['code'] }}">{{ $u['one'] }} — {{ $u['qty_label'] }}</option>
                            @endforeach
                        </select>
                        <p id="edit_unit_lock" class="hidden text-xs mt-1" style="color:#92400E;"></p>
                        <div id="edit_unit_mismatch" class="hidden mt-2 p-2 rounded-md text-xs" style="background:#FFF7ED;border:1px solid #FDBA74;color:#9A3412;"></div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Rate per Unit (Rs.) <span class="text-red-500">*</span></label>
                        <input type="number" id="edit_rate_per_unit" step="0.01" min="0.01" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                        <select id="edit_category_level_1"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">— untagged —</option>
                            @foreach(($categories ?? []) as $cat)
                                <option value="{{ $cat }}">{{ $cat }}</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Groups this vendor's purchases in the Sales vs Purchase report.</p>
                    </div>
                    @if(!empty($ingredients))
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Frozen ingredient</label>
                        <select id="edit_ingredient_id"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">— not an ingredient —</option>
                            {{-- Same grouping as the add form. ⚠ The product being edited is
                                 itself "already added", so its own ingredient sits in the second
                                 group — which is correct, and it still selects normally. --}}
                            @foreach($ingFresh as $ing)
                                <option value="{{ $ing['id'] }}" data-unit="{{ $ing['base_unit'] }}">{{ $ing['name'] }}</option>
                            @endforeach
                            @if(!empty($ingOld))
                            <optgroup label="Already on this vendor's list">
                                @foreach($ingOld as $ing)
                                    <option value="{{ $ing['id'] }}" data-unit="{{ $ing['base_unit'] }}">{{ $ing['name'] }} — already “{{ $ingAdded[(int) $ing['id']] }}”</option>
                                @endforeach
                            </optgroup>
                            @endif
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Purchases of a tagged product count towards that ingredient's stock and cost per pack.</p>
                    </div>
                    <div id="edit_pack_qty_wrap" class="hidden">
                        <label class="block text-sm font-medium text-gray-700 mb-1">How much in one <span id="edit_pack_qty_unit">unit</span>?</label>
                        <input type="number" id="edit_pack_qty_base" step="0.001" min="0.001" placeholder="e.g. 400"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <p class="text-xs text-gray-500 mt-1">A pack could be any size, so say how many <span class="pq-base">grams</span> one holds.</p>
                    </div>
                    <div id="edit_change_panel" class="hidden"></div>
                    @endif
                    <div>
                        <label class="inline-flex items-center cursor-pointer">
                            <input type="checkbox" id="edit_is_default" class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                            <span class="ml-2 text-sm font-medium text-gray-700">⭐ Set as Default</span>
                        </label>
                    </div>
                    <div class="flex gap-3 mt-6">
                        <button type="button" onclick="closeEditModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 font-medium rounded-md hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="submit" 
                                class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-md"
                                style="background-color: #2563eb !important; color: white !important;">
                            <span style="color: white !important;">✓ Update Product</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- ❄ The Frozen ingredient names, offered as you type a product name. The point is
     ONE spelling: the recipe says "Cheese", so the vendor product is "Cheese" — and then
     the purchase and the recipe meet. --}}
@if(!empty($ingredients))
<datalist id="ingNames">
    {{-- Not-yet-added first; the browser keeps this order. An already-added name still
         appears, labelled, so a second pack size keeps the same spelling. --}}
    @foreach($ingFresh as $ing)
        <option value="{{ $ing['name'] }}"></option>
    @endforeach
    @foreach($ingOld as $ing)
        <option value="{{ $ing['name'] }}" label="already added"></option>
    @endforeach
</datalist>
@endif

<script>
const vendorId = {{ $vendor->id }};

// ❄ Frozen ingredients on this page (empty when the feature has not reached this DB).
const INGREDIENTS = @json($ingredients ?? []);

// ⭐ Sep-26: the ONE unit catalogue (App\Services\FIN\VendorUnits) — the same list the phone
//   renders. kind: g | ml | pcs, or null for a pack/box whose size is asked.
const UNITS = @json($unitCatalogue);
const PURCHASE_COUNTS = @json((object) $purchaseCounts);
const CHANGE_UNIT_URL = '{{ route('khaas.ingredients') }}';
const KIND_WORD = {g: 'weight', ml: 'volume', pcs: 'pieces'};
const BASE_WORD = {g: 'grams', ml: 'ml', pcs: 'pieces'};
const SUGGESTED = {g: 'kg', ml: 'litre', pcs: 'piece'};

function ingredientById(id) {
    return INGREDIENTS.filter(function (i) { return String(i.id) === String(id); })[0] || null;
}

function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
    });
}
function unitRow(code) {
    return UNITS.filter(function (u) { return u.code === String(code || '').toLowerCase(); })[0] || null;
}

/**
 * The pack-size box shows only for a pack / box against an ingredient (a kg of onions is
 * obviously 1000 g). ⭐ And a unit of the WRONG KIND — kg for Chicken Cubes, which the
 * recipes count in pieces — is said at once, with the two ways out.
 */
function syncPackQty(selId, unitId, wrapId, unitLabelId) {
    var sel = document.getElementById(selId), wrap = document.getElementById(wrapId);
    if (!sel || !wrap) { return; }
    var ing  = ingredientById(sel.value);
    var unit = (document.getElementById(unitId).value || '').toLowerCase();
    var row  = unitRow(unit);
    var needs = !!ing && !!row && row.kind === null;
    wrap.classList.toggle('hidden', !needs);
    if (needs) {
        document.getElementById(unitLabelId).textContent = unit || 'unit';
        wrap.querySelectorAll('.pq-base').forEach(function (el) { el.textContent = BASE_WORD[ing.base_unit] || 'grams'; });
    }
    var prefix = selId.indexOf('edit_') === 0 ? 'edit_' : '';
    var box = document.getElementById(prefix + 'unit_mismatch');
    if (!box) { return; }
    var wrong = !!ing && !!row && row.kind !== null && row.kind !== ing.base_unit;
    box.classList.toggle('hidden', !wrong);
    if (!wrong) { return; }
    var s = SUGGESTED[ing.base_unit];
    box.innerHTML = '<div class="font-semibold">' + esc(ing.name) + ' is counted in ' + esc((KIND_WORD[ing.base_unit] || '').toUpperCase())
        + (ing.recipe_count > 0 ? ' in your recipes' : '') + ', but this product is set to ' + esc(unit) + '.</div>'
        + '<div class="mt-2 flex flex-wrap gap-2">'
        + '<button type="button" class="px-2 py-1 rounded text-white" style="background:#EA580C;" onclick="useSuggested(\'' + prefix + '\', \'' + s + '\')">Use ' + esc(unitRow(s) ? unitRow(s).many : s) + '</button>'
        + '<button type="button" class="px-2 py-1 rounded" style="border:1px solid #A5B4FC;color:#3730A3;background:#fff;" onclick="openChangeEverywhere(\'' + prefix + '\')">Change ' + esc(ing.name) + ' to ' + esc(KIND_WORD[row.kind]) + ' everywhere…</button>'
        + '</div>';
}

function useSuggested(prefix, code) {
    var u = document.getElementById(prefix + 'unit');
    u.value = code;
    u.dispatchEvent(new Event('change'));
    var p = document.getElementById(prefix + 'change_panel');
    if (p) { p.classList.add('hidden'); p.innerHTML = ''; }
}

/** Ask the server what changing the ingredient's unit would touch, then show it to fill in. */
function openChangeEverywhere(prefix) {
    var ing  = ingredientById(document.getElementById(prefix + 'ingredient_id').value);
    var unit = document.getElementById(prefix + 'unit').value;
    var row  = unitRow(unit);
    if (!ing || !row || !row.kind) { return; }
    var pid = prefix ? document.getElementById('edit_product_id').value : '';
    var url = CHANGE_UNIT_URL + '/' + ing.id + '/unit-impact?to=' + row.kind + (pid ? '&product_id=' + pid + '&unit=' + encodeURIComponent(unit) : '');
    fetch(url, {headers: {'Accept': 'application/json'}})
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d.success) { renderChangePanel(prefix, d); } else { showMessage('error', d.message); } })
        .catch(function () { showMessage('error', 'Could not check what would change.'); });
}

function renderChangePanel(prefix, c) {
    var p = document.getElementById(prefix + 'change_panel');
    if (!p) { return; }
    var h = '<div class="p-3 rounded-md text-sm" style="background:#EEF2FF;border:1px solid #A5B4FC;">'
        + '<div class="font-semibold" style="color:#312E81;">Change ' + esc(c.ingredient.name) + ' from ' + esc(c.from_word) + ' to ' + esc(c.to_word) + ' everywhere?</div>';
    if (!c.can_change) {
        h += '<p class="mt-2" style="color:#991B1B;">It cannot change: ' + esc((c.locked_reasons || []).join('; ')) + '. Those numbers were written in ' + esc(BASE_WORD[c.from]) + '. Use ' + esc(SUGGESTED[c.from]) + ' for this product instead.</p>';
    } else if (c.can_manage === false) {
        h += '<p class="mt-2" style="color:#991B1B;">Only Taimur, Shabib or Qasim can change an ingredient\'s unit.</p>';
    } else {
        if ((c.recipe_lines || []).length) {
            h += '<p class="mt-2 text-gray-700">These recipes use it. ' + esc(c.from_word) + ' cannot be turned into ' + esc(c.to_word) + ' by arithmetic, so type each new amount in ' + esc(c.to_base_word) + ':</p>';
            c.recipe_lines.forEach(function (l) {
                h += '<label class="flex items-center gap-2 mt-1"><span class="flex-1">' + esc(l.product_name) + ' v' + l.version + (l.is_current ? ' (current)' : '') + ' — was ' + Number(l.qty) + ' ' + esc(BASE_WORD[c.from]) + '</span>'
                    + '<input type="number" step="0.001" min="0.001" class="cu-recipe w-28 px-2 py-1 border rounded" data-id="' + l.id + '" placeholder="' + esc(c.to_base_word) + '"></label>';
            });
        } else {
            h += '<p class="mt-2 text-gray-700">No recipe uses it yet.</p>';
        }
        (c.products || []).forEach(function (pr) {
            h += '<div class="flex items-center gap-2 mt-1"><span class="flex-1">' + esc(pr.vendor_name || '') + ' · ' + esc(pr.product_name) + ' (' + esc(pr.unit) + ')'
                + (pr.needs_size ? '' : ' — automatic, ' + pr.new_pack_qty_base + ' ' + esc(c.to_base_word) + ' each') + '</span>'
                + (pr.needs_size ? '<input type="number" step="0.001" min="0.001" class="cu-size w-28 px-2 py-1 border rounded" data-id="' + pr.id + '" placeholder="' + esc(c.to_base_word) + ' in one">' : '')
                + '</div>';
        });
        h += '<button type="button" class="mt-3 px-3 py-2 rounded text-white" style="background:#4F46E5;" onclick="applyChangeEverywhere(\'' + prefix + '\')">Change everywhere &amp; save the product</button>';
    }
    h += ' <button type="button" class="mt-3 px-3 py-2 text-gray-600" onclick="useSuggested(\'' + prefix + '\', \'' + SUGGESTED[c.from] + '\')">Keep ' + esc(c.ingredient.name) + ' in ' + esc(c.from_word) + '</button></div>';
    p.innerHTML = h;
    p.dataset.ingredient = c.ingredient.id;
    p.dataset.to = c.to;
    p.classList.remove('hidden');
}

function applyChangeEverywhere(prefix) {
    var p = document.getElementById(prefix + 'change_panel');
    var body = {to: p.dataset.to, recipe_qty: {}, product_sizes: {}};
    var missing = false;
    p.querySelectorAll('.cu-recipe').forEach(function (i) { if (!(Number(i.value) > 0)) { missing = true; } body.recipe_qty[i.dataset.id] = Number(i.value); });
    p.querySelectorAll('.cu-size').forEach(function (i) { if (!(Number(i.value) > 0)) { missing = true; } body.product_sizes[i.dataset.id] = Number(i.value); });
    if (missing) { showMessage('error', 'Type every new amount before changing.'); return; }
    if (prefix) { body.product_units = {}; body.product_units[document.getElementById('edit_product_id').value] = document.getElementById('edit_unit').value; }
    fetch(CHANGE_UNIT_URL + '/' + p.dataset.ingredient + '/change-unit', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}'},
        body: JSON.stringify(body)
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (!d.success) { showMessage('error', d.message); return; }
        // The ingredient now speaks the new kind — then save the product itself.
        var ing = ingredientById(p.dataset.ingredient);
        if (ing) { ing.base_unit = p.dataset.to; }
        p.classList.add('hidden');
        showMessage('success', d.message);
        document.getElementById(prefix ? 'editProductForm' : 'addProductForm').requestSubmit();
    })
    .catch(function () { showMessage('error', 'Could not change the unit. Nothing was changed.'); });
}

/** A refusal the server explained (unit_mismatch / unit_locked / …) — shown where it belongs. */
function handleRefusal(prefix, data) {
    if (data.code === 'unit_mismatch' && data.change) {
        renderChangePanel(prefix, data.change);
    }
    showMessage('error', data.message || 'Not saved.');
}

function ingredientPayload(selId, pqId) {
    var sel = document.getElementById(selId);
    if (!sel) { return {}; }                       // page has no Frozen field
    var out = {ingredient_id: sel.value ? Number(sel.value) : 0};
    var pq  = document.getElementById(pqId);
    if (pq && !pq.closest('.hidden') && pq.value) { out.pack_qty_base = Number(pq.value); }
    return out;
}

/**
 * ⭐ Typing a product name that IS an ingredient name selects that ingredient — the
 * whole point of the suggestion list. Case-insensitive, trimmed; a near miss is left
 * alone rather than guessed.
 */
function matchNameToIngredient(nameId, selId, hintId) {
    var name = (document.getElementById(nameId).value || '').trim().toLowerCase();
    var sel  = document.getElementById(selId);
    var hint = document.getElementById(hintId);
    if (!sel) { return; }
    var hit = INGREDIENTS.filter(function (i) { return i.name.toLowerCase() === name; })[0];
    if (hit) {
        sel.value = String(hit.id);
        if (hint) { hint.textContent = '✓ Matched the Frozen ingredient “' + hit.name + '” — purchases of this will count towards it.'; hint.classList.remove('hidden'); }
    } else if (hint) {
        hint.classList.add('hidden');
    }
    sel.dispatchEvent(new Event('change'));
}

(function () {
    var name = document.getElementById('product_name');
    if (name && document.getElementById('ingredient_id')) {
        name.addEventListener('input',  function () { matchNameToIngredient('product_name', 'ingredient_id', 'product_name_hint'); });
        name.addEventListener('change', function () { matchNameToIngredient('product_name', 'ingredient_id', 'product_name_hint'); });
        document.getElementById('ingredient_id').addEventListener('change', function () {
            // Picking an ingredient with the name still blank fills the name with the standard spelling.
            var ing = ingredientById(this.value);
            if (ing && !name.value.trim()) { name.value = ing.name; }
            // ⭐ …and the unit follows the ingredient when none that fits was chosen.
            var u = document.getElementById('unit'), row = unitRow(u.value);
            if (ing && (!row || (row.kind !== null && row.kind !== ing.base_unit))) { u.value = SUGGESTED[ing.base_unit] || u.value; }
            syncPackQty('ingredient_id', 'unit', 'pack_qty_wrap', 'pack_qty_unit');
        });
        document.getElementById('unit').addEventListener('change', function () {
            syncPackQty('ingredient_id', 'unit', 'pack_qty_wrap', 'pack_qty_unit');
        });
    }
    var editName = document.getElementById('edit_product_name');
    if (editName && document.getElementById('edit_ingredient_id')) {
        editName.addEventListener('input', function () { matchNameToIngredient('edit_product_name', 'edit_ingredient_id', null); });
        document.getElementById('edit_ingredient_id').addEventListener('change', function () {
            syncPackQty('edit_ingredient_id', 'edit_unit', 'edit_pack_qty_wrap', 'edit_pack_qty_unit');
        });
        document.getElementById('edit_unit').addEventListener('change', function () {
            syncPackQty('edit_ingredient_id', 'edit_unit', 'edit_pack_qty_wrap', 'edit_pack_qty_unit');
        });
    }
})();

// Add Product
document.getElementById('addProductForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = {
        product_name: document.getElementById('product_name').value,
        category_level_1: document.getElementById('category_level_1').value,
        unit: document.getElementById('unit').value,
        rate_per_unit: document.getElementById('rate_per_unit').value,
        is_default: document.getElementById('is_default').checked ? 1 : 0,
        ...ingredientPayload('ingredient_id', 'pack_qty_base')
    };
    
    fetch(`/finance/vendors/${vendorId}/products`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify(formData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage('success', data.message);
            document.getElementById('addProductForm').reset();
            setTimeout(() => window.location.reload(), 1000);
        } else {
            handleRefusal('', data);
        }
    })
    .catch(error => {
        showMessage('error', 'Error adding product');
        console.error('Error:', error);
    });
});

// Edit Product
function editProduct(id, name, unit, rate, isDefault, category, ingredientId, packQty) {
    document.getElementById('edit_product_id').value = id;
    document.getElementById('edit_product_name').value = name;
    // ⚠ A legacy free-text unit ("800gm", "pcs") is shown as it is, and must be re-picked.
    var eu = document.getElementById('edit_unit');
    eu.querySelectorAll('option[data-legacy]').forEach(function (o) { o.remove(); });
    var canon = unitRow(unit) ? unit : ({pcs: 'piece', liter: 'litre', l: 'litre', ltr: 'litre', gm: 'g'}[String(unit).toLowerCase()] || null);
    if (canon) {
        eu.value = canon;
    } else {
        var o = document.createElement('option');
        o.value = unit; o.textContent = unit + ' — not a unit, pick one'; o.dataset.legacy = '1';
        eu.appendChild(o); eu.value = unit;
    }
    // 🔒 Purchase history locks the unit (old bills are re-stamped from the product on edit).
    var n = PURCHASE_COUNTS[id] || 0, lock = document.getElementById('edit_unit_lock');
    eu.disabled = n > 0;
    if (lock) {
        lock.classList.toggle('hidden', n === 0);
        lock.textContent = n > 0 ? '🔒 ' + n + ' purchase' + (n === 1 ? '' : 's') + ' recorded in ' + unit + ', so the unit stays. For a different unit, add a new product and disable this one.' : '';
    }
    var cp = document.getElementById('edit_change_panel');
    if (cp) { cp.classList.add('hidden'); cp.innerHTML = ''; }
    document.getElementById('edit_rate_per_unit').value = rate;
    document.getElementById('edit_is_default').checked = isDefault;
    document.getElementById('edit_category_level_1').value = category || '';
    // ❄ the tag, when the field exists on this page
    var ingSel = document.getElementById('edit_ingredient_id');
    if (ingSel) {
        ingSel.value = ingredientId ? String(ingredientId) : '';
        var pq = document.getElementById('edit_pack_qty_base');
        if (pq) { pq.value = packQty ? packQty : ''; }
        syncPackQty('edit_ingredient_id', 'edit_unit', 'edit_pack_qty_wrap', 'edit_pack_qty_unit');
    }
    
    const modal = document.getElementById('editModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
}

function closeEditModal() {
    const modal = document.getElementById('editModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}

document.getElementById('editProductForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const productId = document.getElementById('edit_product_id').value;
    const formData = {
        product_name: document.getElementById('edit_product_name').value,
        category_level_1: document.getElementById('edit_category_level_1').value,
        unit: document.getElementById('edit_unit').value,
        rate_per_unit: document.getElementById('edit_rate_per_unit').value,
        is_default: document.getElementById('edit_is_default').checked ? 1 : 0,
        ...ingredientPayload('edit_ingredient_id', 'edit_pack_qty_base')
    };
    
    fetch(`/finance/vendors/${vendorId}/products/${productId}`, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify(formData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage('success', data.message);
            closeEditModal();
            setTimeout(() => window.location.reload(), 1000);
        } else {
            handleRefusal('edit_', data);
        }
    })
    .catch(error => {
        showMessage('error', 'Error updating product');
        console.error('Error:', error);
    });
});

// Toggle Status
function toggleStatus(id, currentStatus) {
    if (!confirm(`Are you sure you want to ${currentStatus ? 'disable' : 'enable'} this product?`)) {
        return;
    }
    
    fetch(`/finance/vendors/${vendorId}/products/${id}/toggle`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage('success', data.message);
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showMessage('error', data.message);
        }
    })
    .catch(error => {
        showMessage('error', 'Error updating status');
        console.error('Error:', error);
    });
}

// Set as Default
function setAsDefault(productId, isCurrentlyDefault) {
    if (isCurrentlyDefault) {
        showMessage('info', 'This product is already set as default');
        return;
    }
    
    if (!confirm('Set this product as default? This will unset any other default product.')) {
        return;
    }
    
    fetch(`/finance/vendors/${vendorId}/products/${productId}/set-default`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage('success', 'Default product updated!');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showMessage('error', data.message);
        }
    })
    .catch(error => {
        showMessage('error', 'Error setting default product');
        console.error('Error:', error);
    });
}

// Delete Product
function deleteProduct(id) {
    if (!confirm('Are you sure you want to delete this product? If it has purchase history, it will be deactivated instead.')) {
        return;
    }
    
    fetch(`/finance/vendors/${vendorId}/products/${id}`, {
        method: 'DELETE',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage('success', data.message);
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showMessage('error', data.message);
        }
    })
    .catch(error => {
        showMessage('error', 'Error deleting product');
        console.error('Error:', error);
    });
}

// Show Message
function showMessage(type, message) {
    const container = document.getElementById('messageContainer');
    const bgColor = type === 'success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800';
    
    container.innerHTML = `
        <div class="mb-4 p-4 ${bgColor} border rounded-md">
            <p class="text-sm">${message}</p>
        </div>
    `;
    
    setTimeout(() => {
        container.innerHTML = '';
    }, 5000);
}

// 🔗 Sep-27: arrived from the Planning page's "Link at…" — the ingredient is pre-chosen in
// the add form (name filled from it) and a note says the other way: Edit an existing
// product and choose the ingredient there.
(function () {
    var wanted = null;
    try { wanted = new URLSearchParams(window.location.search).get('link_ingredient'); } catch (e) { wanted = null; }
    var sel = document.getElementById('ingredient_id');
    if (!wanted || !sel) { return; }
    var opt = Array.prototype.filter.call(sel.options, function (o) { return o.value === String(wanted); })[0];
    if (!opt) { return; }
    sel.value = opt.value;
    var ing = ingredientById(opt.value);
    var nameBox = document.getElementById('product_name');
    if (ing && nameBox && !nameBox.value) { nameBox.value = ing.name; }
    try { syncPackQty('ingredient_id', 'unit', 'pack_qty_wrap', 'pack_qty_unit'); } catch (e) { /* the form still works */ }
    var note = document.createElement('div');
    note.id = 'linkIngredientNote';
    note.className = 'mb-4 p-4 border rounded-md text-sm';
    note.style.cssText = 'background:#EEF2FF;border-color:#C7D2FE;color:#312E81;';
    note.innerHTML = '🔗 <b>Linking ' + esc(ing ? ing.name : 'the ingredient') + '</b> — add it below as a new product, ' +
        'or use <b>Edit</b> on an existing product in the list and choose it as the Frozen ingredient there.';
    var form = document.getElementById('addProductForm');
    form.parentNode.insertBefore(note, form);
    note.scrollIntoView({block: 'center'});
})();

// Close modal on outside click
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeEditModal();
    }
});
</script>

@endsection

