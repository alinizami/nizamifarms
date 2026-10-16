{{--
    📦 Bundled product (Oct-2026) — shared by products/create and products/edit.
    One sale of a bundled product = packets of one or more OTHER products, each at the
    price of ONE packet entered here (owner ruling — nothing is divided):
        EK200 = 2 × Chicken Samosa @ 500 + 1 × Chicken Roll @ 500   → Shopify 1 × 1,500
    The product's own price is pinned to the bundle total (the Shopify price).
    Engine: App\Services\CRM\ProductBundle. Hidden until the SQL has run.
    Expects $bundleProduct (the product being edited, or null on create).
--}}
@php
    $bundleSupported = \App\Services\CRM\ProductBundle::supported();
    $bundleIsOn = false;
    $bundleRows = [];
    $bundleOwnVariantIds = [];
    if ($bundleSupported) {
        $bundleIsOn = (string) old('is_bundle', ($bundleProduct && \App\Services\CRM\ProductBundle::isBundle($bundleProduct)) ? '1' : '0') === '1';
        $bundleSource = old('bundle_items');
        if ($bundleSource === null) {
            $bundleSource = $bundleProduct ? \App\Services\CRM\ProductBundle::itemRows((int) $bundleProduct->id) : [];
        }
        foreach ((array) $bundleSource as $r) {
            $vid = (int) preg_replace('/^variant_/', '', (string) ($r['variant_id'] ?? ''));
            $label = '';
            if ($vid > 0) {
                $v = \App\Models\CRM\ProductVariantModel::with('product')->find($vid);
                $label = ($v && $v->product)
                    ? \App\Services\CRM\ProductBundle::lineName($v) . ($v->sku ? ' · SKU ' . $v->sku : '')
                    : '⚠ This product no longer exists — pick it again';
            }
            $bundleRows[] = [
                'variant_id' => $vid > 0 ? (string) $vid : '',
                'label' => $label,
                'qty' => (string) ($r['qty'] ?? ''),
                'unit_price' => isset($r['unit_price']) && $r['unit_price'] !== '' ? (string) (0 + $r['unit_price']) : '',
            ];
        }
        if ($bundleProduct) {
            $bundleOwnVariantIds = $bundleProduct->variants->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        }
    }
@endphp
@if($bundleSupported)
<div class="flex flex-col gap-2 mt-5 p-4 bg-amber-50 border border-amber-200 rounded-lg" id="bundleSection">
    <label class="form-label text-sm font-medium flex items-center gap-2" style="cursor: pointer;">
        <input type="hidden" name="is_bundle" value="0">
        <input type="checkbox" name="is_bundle" value="1" id="bundleToggle" {{ $bundleIsOn ? 'checked' : '' }}>
        📦 Bundled product
        <span class="text-xs text-gray-500 font-normal">(one sale = packets of one or more OTHER products)</span>
    </label>

    <div id="bundleFields" style="{{ $bundleIsOn ? '' : 'display: none;' }}">
        <div class="text-sm font-medium mt-2">Inside the bundle</div>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; min-width: 560px;">
                <thead>
                    <tr style="font-size: 12px; color: #6b7280; text-align: left;">
                        <th style="padding: 4px 6px;">Product</th>
                        <th style="padding: 4px 6px; width: 90px;">Packets</th>
                        <th style="padding: 4px 6px; width: 120px;">Price of 1 packet</th>
                        <th style="padding: 4px 6px; width: 110px; text-align: right;">Line</th>
                        <th style="width: 36px;"></th>
                    </tr>
                </thead>
                <tbody id="bundleRows"></tbody>
            </table>
        </div>
        <button type="button" id="bundleAddRow" class="btn btn-sm btn-light mt-2" style="font-size: 12px;">+ Add product</button>

        <div class="text-sm mt-3" id="bundleTotal" style="font-weight: 600;"></div>
        <div class="text-xs text-gray-700 mt-2 p-2 bg-white border border-amber-200 rounded" id="bundlePreview"></div>

        <div class="text-xs text-gray-600 mt-2">
            Enter the price of <strong>ONE packet</strong> of each product (e.g. 400, not 1,200). The bundle total
            must equal the Shopify price — this product's own price is set to that total automatically. When
            Shopify sends this product, or someone adds it to an order, the order shows the products inside, each
            packets × its price — so the team packs every packet. Stock comes off the products inside.
        </div>
        <div class="text-xs text-gray-600 mt-1">
            This product's SKU must be <strong>exactly the Shopify SKU</strong> of the bundle, and a bundle has only
            that one SKU. Give it the same category as the main product inside — the WhatsApp delivery schedule
            reads it while the order is still waiting for approval.
        </div>
    </div>
    @error('bundle_items')
        <span class="form-hint text-danger">{{ $message }}</span>
    @enderror
</div>

<script>
(function () {
    var ownVariantIds = @json($bundleOwnVariantIds);
    var rows = @json($bundleRows);
    var toggle = document.getElementById('bundleToggle');
    var fields = document.getElementById('bundleFields');
    var tbody = document.getElementById('bundleRows');
    var addBtn = document.getElementById('bundleAddRow');
    var totalBox = document.getElementById('bundleTotal');
    var preview = document.getElementById('bundlePreview');
    var timers = {};

    if (!rows.length) rows.push({variant_id: '', label: '', qty: '', unit_price: ''});

    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    function money(n) { return 'Rs ' + Math.round(n).toLocaleString('en-US'); }
    function priceInput() { return document.querySelector('input[name="variants[0][price]"]'); }
    function num(v) { var n = parseFloat(v); return isNaN(n) ? 0 : n; }

    function total() {
        return rows.reduce(function (s, r) { return s + (parseInt(r.qty, 10) || 0) * num(r.unit_price); }, 0);
    }

    // The product's own price = the bundle total while it is a bundle (the server pins it too).
    function syncPrice() {
        var p = priceInput();
        if (!p) return;
        if (toggle.checked) {
            p.value = Math.round(total() * 100) / 100;
            p.readOnly = true;
            p.style.backgroundColor = '#f3f4f6';
            p.title = 'A bundle\'s price is the total of the products inside';
        } else {
            p.readOnly = false;
            p.style.backgroundColor = '';
            p.title = '';
        }
    }

    function renderSummary() {
        var t = total();
        totalBox.textContent = 'One bundle = ' + money(t) + ' (this is the price Shopify should charge)';
        var parts = rows.filter(function (r) { return r.variant_id && parseInt(r.qty, 10) > 0; }).map(function (r) {
            var name = r.label.split(' · SKU')[0];
            return '"' + name + '" ' + r.qty + ' × ' + money(num(r.unit_price)) + ' = ' + money(parseInt(r.qty, 10) * num(r.unit_price));
        });
        preview.textContent = parts.length
            ? 'One bundle sold → ' + parts.join('  +  ') + '.  Total ' + money(t) + '.'
            : 'Pick the products inside, their packets and the price of one packet to see how a bundle will show on the order.';
        syncPrice();
    }

    function render() {
        tbody.innerHTML = rows.map(function (r, i) {
            return '<tr data-row="' + i + '" style="vertical-align: top;">'
                + '<td style="padding: 4px 6px; position: relative;">'
                +   '<input type="hidden" name="bundle_items[' + i + '][variant_id]" value="' + esc(r.variant_id) + '">'
                +   '<input type="text" class="form-control bundle-search" data-row="' + i + '" autocomplete="off" placeholder="Search name or SKU…" style="font-size: 13px;">'
                +   '<div class="bundle-dd" data-row="' + i + '" style="display:none; position:absolute; z-index:50; left:6px; right:6px; background:#fff; border:1px solid #e5e7eb; border-radius:6px; max-height:240px; overflow-y:auto; box-shadow:0 4px 12px rgba(0,0,0,.08);"></div>'
                +   '<div style="font-size: 12px; margin-top: 3px; ' + (r.label ? 'font-weight:600;' : 'color:#6b7280;') + '">' + esc(r.label || 'Nothing picked yet') + '</div>'
                + '</td>'
                + '<td style="padding: 4px 6px;"><input type="number" class="form-control bundle-qty" data-row="' + i + '" name="bundle_items[' + i + '][qty]" min="1" max="1000" step="1" value="' + esc(r.qty) + '" placeholder="e.g. 2"></td>'
                + '<td style="padding: 4px 6px;"><input type="number" class="form-control bundle-price" data-row="' + i + '" name="bundle_items[' + i + '][unit_price]" min="0" step="0.01" value="' + esc(r.unit_price) + '" placeholder="e.g. 500"></td>'
                + '<td style="padding: 10px 6px 4px; text-align: right; font-size: 13px;" class="bundle-line" data-row="' + i + '">' + money((parseInt(r.qty, 10) || 0) * num(r.unit_price)) + '</td>'
                + '<td style="padding: 4px 6px;">' + (rows.length > 1 ? '<button type="button" class="bundle-remove" data-row="' + i + '" title="Remove" style="border:none;background:#fee2e2;color:#b91c1c;border-radius:4px;width:28px;height:30px;cursor:pointer;">×</button>' : '') + '</td>'
                + '</tr>';
        }).join('');
        setEnabled(toggle.checked);
        renderSummary();
    }

    function setEnabled(on) {
        fields.style.display = on ? '' : 'none';
        // Disabled inputs are not submitted, so an unticked box can never fail on stale rows.
        fields.querySelectorAll('input, button').forEach(function (el) { el.disabled = !on; });
    }

    function showResults(i, products) {
        var dd = tbody.querySelector('.bundle-dd[data-row="' + i + '"]');
        var list = (products || []).filter(function (p) {
            var id = parseInt(String(p.id || '').replace('variant_', ''), 10);
            // A bundle cannot hold another bundle, nor itself.
            return !p.bundle && ownVariantIds.indexOf(id) === -1;
        });
        dd._results = list;
        dd.innerHTML = list.length ? list.map(function (p, k) {
            return '<div data-k="' + k + '" style="padding:8px;cursor:pointer;border-bottom:1px solid #f3f4f6;">'
                + '<div style="font-weight:500;font-size:13px;">' + esc(p.name) + '</div>'
                + '<div style="font-size:11px;color:#6b7280;">' + (p.sku ? 'SKU: ' + esc(p.sku) + ' | ' : '') + 'Price: PKR ' + esc(p.price) + '</div></div>';
        }).join('') : '<div style="padding:8px;color:#6b7280;font-size:12px;">No products found</div>';
        dd.style.display = 'block';
    }

    tbody.addEventListener('input', function (e) {
        var i = parseInt(e.target.getAttribute('data-row'), 10);
        if (isNaN(i)) return;
        if (e.target.classList.contains('bundle-search')) {
            clearTimeout(timers[i]);
            var q = e.target.value.trim();
            var dd = tbody.querySelector('.bundle-dd[data-row="' + i + '"]');
            if (q.length < 2) { dd.style.display = 'none'; return; }
            timers[i] = setTimeout(function () {
                fetch('/api/products/search?q=' + encodeURIComponent(q) + '&limit=15', {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (d && d.success) showResults(i, d.products); })
                .catch(function () { dd.style.display = 'none'; });
            }, 300);
            return;
        }
        if (e.target.classList.contains('bundle-qty')) rows[i].qty = e.target.value;
        if (e.target.classList.contains('bundle-price')) rows[i].unit_price = e.target.value;
        var cell = tbody.querySelector('.bundle-line[data-row="' + i + '"]');
        if (cell) cell.textContent = money((parseInt(rows[i].qty, 10) || 0) * num(rows[i].unit_price));
        renderSummary();
    });

    tbody.addEventListener('mousedown', function (e) {
        var pick = e.target.closest('[data-k]');
        if (pick) {
            e.preventDefault();
            var dd = pick.closest('.bundle-dd');
            var i = parseInt(dd.getAttribute('data-row'), 10);
            var p = dd._results[parseInt(pick.getAttribute('data-k'), 10)];
            if (!p) return;
            rows[i].variant_id = String(p.id).replace('variant_', '');
            rows[i].label = p.name + (p.sku ? ' · SKU ' + p.sku : '');
            // Deliberately NOT pre-filled from the product's normal price: the packet
            // price inside a bundle is Taimur's number and is usually lower.
            render();
        }
    });
    tbody.addEventListener('focusout', function (e) {
        if (e.target.classList.contains('bundle-search')) {
            var dd = tbody.querySelector('.bundle-dd[data-row="' + e.target.getAttribute('data-row') + '"]');
            setTimeout(function () { if (dd) dd.style.display = 'none'; }, 200);
        }
    });
    tbody.addEventListener('click', function (e) {
        if (e.target.classList.contains('bundle-remove')) {
            rows.splice(parseInt(e.target.getAttribute('data-row'), 10), 1);
            render();
        }
    });
    addBtn.addEventListener('click', function () {
        rows.push({variant_id: '', label: '', qty: '', unit_price: ''});
        render();
    });
    toggle.addEventListener('change', function () { setEnabled(toggle.checked); renderSummary(); });

    render();
    // The variant price box sits further down the page than this script, so pin it
    // again once the whole form exists.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderSummary);
    }
})();
</script>
@endif
