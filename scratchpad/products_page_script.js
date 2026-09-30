
const vendorId = 50;

// ❄ Frozen ingredients on this page (empty when the feature has not reached this DB).
const INGREDIENTS = [{"id":32,"name":"Black Pepper","name_urdu":"Kali Mirch","base_unit":"g","display_unit":"kg","display_units":["g","kg"],"kind":"dry","kind_label":"Dry goods","is_meat":false,"storage_product_id":null,"is_active":true,"recipe_count":3},{"id":25,"name":"Black Pepper Powder","name_urdu":"Kaali Mirch","base_unit":"g","display_unit":"kg","display_units":["g","kg"],"kind":"dry","kind_label":"Dry goods","is_meat":false,"storage_product_id":null,"is_active":true,"recipe_count":2},{"id":30,"name":"Cabbage","name_urdu":"Band Gobhi","base_unit":"g","display_unit":"kg","display_units":["g","kg"],"kind":"vegetable","kind_label":"Vegetables","is_meat":false,"storage_product_id":null,"is_active":true,"recipe_count":3},{"id":8,"name":"Cabbage (Band Gobi)","name_urdu":null,"base_unit":"g","display_unit":"kg","display_units":["g","kg"],"kind":"vegetable","kind_label":"Vegetables","is_meat":false,"storage_product_id":null,"is_active":true,"recipe_count":0},{"id":18,"name":"Capsicum (Shimla Mirch)","name_urdu":null,"base_unit":"g","display_unit":"kg","display_units":["g","kg"],"kind":"vegetable","kind_label":"Vegetables","is_meat":false,"storage_product_id":null,"is_active":true,"recipe_count":2},{"id":31,"name":"Carrot","name_urdu":"Gajar","base_unit":"g","display_unit":"kg","display_units":["g","kg"],"kind":"vegetable","kind_label":"Vegetables","is_meat":false,"storage_product_id":null,"is_active":true,"recipe_count":3},{"id":9,"name":"Carrot (Gajar)","name_urdu":null,"base_unit":"g","display_unit":"kg","display_units":["g","kg"],"kind":"vegetable","kind_label":"Vegetables","is_meat":false,"storage_product_id":null,"is_active":true,"recipe_count":0},{"id":23,"name":"Cheese","name_urdu":null,"base_unit":"g","display_unit":"kg","display_units":["g","kg"],"kind":"dairy","kind_label":"Dairy","is_meat":false,"storage_product_id":null,"is_active":true,"recipe_count":2},{"id":26,"name":"Chicken Cubes","name_urdu":null,"base_unit":"pcs","display_unit":"pcs","display_units":["pcs"],"kind":"dry","kind_label":"Dry goods","is_meat":false,"storage_product_id":null,"is_active":true,"recipe_count":2},{"id":24,"name":"Cooking Oil","name_urdu":null,"base_unit":"ml","display_unit":"L","display_units":["ml","L"],"kind":"other","kind_label":"Other","is_meat":false,"storage_product_id":null,"is_active":true,"recipe_count":4},{"id":29,"name":"Corn Flour","name_urdu":null,"base_unit":"g","display_unit":"kg","display_units":["g","kg"],"kind":"dry","kind_label":"Dry goods","is_meat":false,"storage_product_id":null,"is_active":true,"recipe_count":4},{"id":16,"name":"Corriander (Dhaniya)","name_urdu":null,"base_unit":"g","display_unit":"kg","display_units":["g","kg"],"kind":"vegetable","kind_label":"Vegetables","is_meat":false,"storage_product_id":null,"is_active":true,"recipe_count":0},{"id":27,"name":"Eggs","name_urdu":null,"base_unit":"pcs","display_unit":"pcs","display_units":["pcs"],"kind":"dairy","kind_label":"Dairy","is_meat":false,"storage_product_id":null,"is_active":true,"recipe_count":2},{"id":13,"name":"Garlic (Lehsan)","name_urdu":null,"base_unit":"g","display_unit":"kg","display_units":["g","kg"],"kind":"vegetable","kind_label":"Vegetables","is_meat":false,"storage_product_id":null,"is_active":true,"recipe_count":0},{"id":14,"name":"Ginger (Adrak)","name_urdu":null,"base_unit":"g","display_unit":"kg","display_units":["g","kg"],"kind":"vegetable","kind_label":"Vegetables","is_meat":false,"storage_product_id":null,"is_active":true,"recipe_count":0},{"id":35,"name":"Green Chilli","name_urdu":"Sabz Mirch","base_unit":"g","display_unit":"kg","display_units":["g","kg"],"kind":"dry","kind_label":"Dry goods","is_meat":false,"storage_product_id":null,"is_active":true,"recipe_count":3},{"id":15,"name":"Green Chilli (Hari Mirch)","name_urdu":null,"base_unit":"g","display_unit":"kg","display_units":["g","kg"],"kind":"vegetable","kind_label":"Vegetables","is_meat":false,"storage_product_id":null,"is_active":true,"recipe_count":2},{"id":12,"name":"Green Onion (Hari Piyaaz)","name_urdu":null,"base_unit":"g","display_unit":"kg","display_units":["g","kg"],"kind":"vegetable","kind_label":"Vegetables","is_meat":false,"storage_product_id":null,"is_active":true,"recipe_count":2},{"id":28,"name":"Maida","name_urdu":null,"base_unit":"g","display_unit":"kg","display_units":["g","kg"],"kind":"dry","kind_label":"Dry goods","is_meat":false,"storage_product_id":null,"is_active":true,"recipe_count":4},{"id":17,"name":"Mint (Podina)","name_urdu":null,"base_unit":"g","display_unit":"kg","display_units":["g","kg"],"kind":"vegetable","kind_label":"Vegetables","is_meat":false,"storage_product_id":null,"is_active":true,"recipe_count":0},{"id":10,"name":"Onions (Piyaaz)","name_urdu":null,"base_unit":"g","display_unit":"kg","display_units":["g","kg"],"kind":"vegetable","kind_label":"Vegetables","is_meat":false,"storage_product_id":null,"is_active":true,"recipe_count":0},{"id":11,"name":"Potato (Aaloo)","name_urdu":null,"base_unit":"g","display_unit":"kg","display_units":["g","kg"],"kind":"vegetable","kind_label":"Vegetables","is_meat":false,"storage_product_id":null,"is_active":true,"recipe_count":0},{"id":34,"name":"Red Chilli","name_urdu":"Laal Mirch","base_unit":"g","display_unit":"kg","display_units":["g","kg"],"kind":"dry","kind_label":"Dry goods","is_meat":false,"storage_product_id":null,"is_active":true,"recipe_count":3},{"id":33,"name":"Salt","name_urdu":"Namak","base_unit":"g","display_unit":"kg","display_units":["g","kg"],"kind":"dry","kind_label":"Dry goods","is_meat":false,"storage_product_id":null,"is_active":true,"recipe_count":3},{"id":36,"name":"Soya Sauce","name_urdu":null,"base_unit":"ml","display_unit":"L","display_units":["ml","L"],"kind":"dry","kind_label":"Dry goods","is_meat":false,"storage_product_id":null,"is_active":true,"recipe_count":3}];

// ⭐ Sep-26: the ONE unit catalogue (App\Services\FIN\VendorUnits) — the same list the phone
//   renders. kind: g | ml | pcs, or null for a pack/box whose size is asked.
const UNITS = [{"code":"kg","kind":"g","factor":1000,"whole":false,"qty_label":"Weight (kg)","one":"kg","many":"kg"},{"code":"g","kind":"g","factor":1,"whole":false,"qty_label":"Weight (g)","one":"g","many":"g"},{"code":"litre","kind":"ml","factor":1000,"whole":false,"qty_label":"Volume (litre)","one":"litre","many":"litres"},{"code":"ml","kind":"ml","factor":1,"whole":false,"qty_label":"Volume (ml)","one":"ml","many":"ml"},{"code":"piece","kind":"pcs","factor":1,"whole":true,"qty_label":"Qty (pieces)","one":"piece","many":"pieces"},{"code":"dozen","kind":"pcs","factor":12,"whole":true,"qty_label":"Qty (dozens)","one":"dozen","many":"dozens"},{"code":"pack","kind":null,"factor":null,"whole":true,"qty_label":"Qty (packs)","one":"pack","many":"packs"},{"code":"box","kind":null,"factor":null,"whole":true,"qty_label":"Qty (boxes)","one":"box","many":"boxes"}];
const PURCHASE_COUNTS = {"76":1};
const CHANGE_UNIT_URL = 'http://localhost/khaas/ingredients';
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
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': 'kaxhjOX1IBvlTVW0Ey8tO8JQYqynp87IJhaEIMyG'},
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
            'X-CSRF-TOKEN': 'kaxhjOX1IBvlTVW0Ey8tO8JQYqynp87IJhaEIMyG'
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
            'X-CSRF-TOKEN': 'kaxhjOX1IBvlTVW0Ey8tO8JQYqynp87IJhaEIMyG'
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
            'X-CSRF-TOKEN': 'kaxhjOX1IBvlTVW0Ey8tO8JQYqynp87IJhaEIMyG'
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
            'X-CSRF-TOKEN': 'kaxhjOX1IBvlTVW0Ey8tO8JQYqynp87IJhaEIMyG'
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
            'X-CSRF-TOKEN': 'kaxhjOX1IBvlTVW0Ey8tO8JQYqynp87IJhaEIMyG'
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
