{{--
    🍱 Recipe family (Oct-19-2026) — the product forms' block, web create AND edit (the phone has the same
    block in ProductsScreen). Which preparation this pack is, and how many pieces it holds. The title only
    SUGGESTS (RecipeFamilyService::suggest); what is saved is what is chosen here.

    • Shown only for a Frozen product that is not a bundle. While hidden, its inputs are DISABLED so the
      browser sends nothing — and the server reads "absent" as "keep" (ProductController::readRecipeFamily).
    • Checked on the server before the product is saved; applied after it, through RecipeFamilyService.
--}}
@php
    $rfReady   = \App\Services\Khaas\RecipeFamilyService::ready();
    $rfFamilies = $rfReady ? array_values(array_filter((new \App\Services\Khaas\RecipeFamilyService())->families(2), fn ($f) => $f['is_active'])) : [];
    $rfProduct = $familyProduct ?? null;
    $rfChosen  = old('recipe_family_id', $rfProduct->recipe_family_id ?? '');
    $rfPieces  = old('pieces_per_pack', $rfProduct->pieces_per_pack ?? '');
    $rfNew     = old('recipe_family_new', '');
@endphp
@if($rfReady)
<div id="rfBlock" class="flex flex-col gap-2 mt-5 p-4 rounded-lg" style="background:#ECFDF5;border:1px solid #A7F3D0;">
    <label class="form-label text-sm font-medium flex items-center gap-2" style="color:#065F46;">
        🍱 Recipe family
        <span class="text-xs font-normal" style="color:#047857;">(what the kitchen cooks — every pack size shares ONE recipe)</span>
    </label>
    <div class="flex flex-wrap items-end gap-3">
        <div class="flex flex-col gap-1">
            <span class="text-xs text-gray-600">Family</span>
            <select name="recipe_family_id" id="rfFamily" class="form-select" style="min-width:280px;">
                <option value="">— not in a family (not made in the kitchen) —</option>
                @foreach($rfFamilies as $f)
                    <option value="{{ $f['id'] }}" {{ (string) $rfChosen === (string) $f['id'] ? 'selected' : '' }}>
                        {{ $f['name'] }}{{ $f['members'] ? ' — ' . implode(', ', array_column($f['members'], 'label')) : '' }}
                    </option>
                @endforeach
                <option value="__new__" {{ $rfNew !== '' ? 'selected' : '' }}>＋ A new family…</option>
            </select>
        </div>
        <div class="flex flex-col gap-1" id="rfNewWrap" style="{{ $rfNew !== '' ? '' : 'display:none;' }}">
            <span class="text-xs text-gray-600">New family name</span>
            <input type="text" name="recipe_family_new" id="rfNew" class="form-control" style="min-width:260px;"
                   value="{{ $rfNew }}" placeholder="e.g. Chicken Tikka Samosa">
        </div>
        <div class="flex flex-col gap-1">
            <span class="text-xs text-gray-600">Pieces in one pack</span>
            <input type="number" name="pieces_per_pack" id="rfPieces" class="form-control" style="width:120px;"
                   min="1" max="999" step="1" value="{{ $rfPieces }}" placeholder="e.g. 12">
        </div>
    </div>
    <div id="rfHint" class="text-xs" style="color:#047857;"></div>
    <div id="rfWarn" class="text-xs" style="color:#B45309;"></div>
    @error('recipe_family_id')
        <span class="form-hint text-danger">{{ $message }}</span>
    @enderror
    <div class="text-xs text-gray-600">
        A new size of an existing preparation (a 12-pcs pack of Chicken Cheese Samosa) joins its family and is
        costed, planned and deducted from day one — nothing to copy. The recipe itself is edited once, on
        Frozen → Planning → 🧂 Ingredients &amp; Quantities.
    </div>
</div>

<script>
(function () {
    var SUGGEST = @json(route('khaas.recipe-family.suggest'));
    var PRODUCT_ID = @json($rfProduct->id ?? null);
    var FAMILIES = @json($rfFamilies);
    var block  = document.getElementById('rfBlock');
    var sel    = document.getElementById('rfFamily');
    var newIn  = document.getElementById('rfNew');
    var newWrap= document.getElementById('rfNewWrap');
    var pieces = document.getElementById('rfPieces');
    var hint   = document.getElementById('rfHint');
    var warn   = document.getElementById('rfWarn');
    var title  = document.querySelector('input[name="title"]');
    var bu     = document.querySelector('select[name="business_unit_id"]');
    var bundle = document.getElementById('bundleToggle');
    var touched = !!(sel.value || pieces.value);   // an edit form that already has an answer is "touched"
    var parsed = null, timer = null;

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

    // Frozen and not a bundle → show; otherwise hide AND disable (disabled inputs are not sent = keep)
    function applyVisibility() {
        var show = bu && String(bu.value) === '2' && !(bundle && bundle.checked);
        block.style.display = show ? '' : 'none';
        [sel, newIn, pieces].forEach(function (el) { el.disabled = !show; });
    }

    function familyById(id) { return FAMILIES.filter(function (f) { return String(f.id) === String(id); })[0]; }

    function describe() {
        warn.textContent = '';
        var f = familyById(sel.value);
        newWrap.style.display = sel.value === '__new__' ? '' : 'none';
        if (f) {
            hint.innerHTML = 'Part of <b>' + esc(f.name) + '</b> — ' + (f.recipe
                ? 'uses its recipe (v' + f.recipe.version + ', ' + f.recipe.basis_pieces + ' pieces a batch).'
                : 'the family has no recipe yet; write it once and every size uses it.');
        } else if (sel.value === '__new__') {
            hint.textContent = 'A new family — write its recipe after saving (Frozen → Planning → 🧂 Ingredients & Quantities).';
        } else {
            hint.textContent = 'Not in a family: this pack keeps its own recipe, if it has one — no other size shares it.';
        }
        if (parsed && parsed.pieces && pieces.value && Number(pieces.value) !== Number(parsed.pieces)) {
            warn.textContent = '⚠ The title says ' + parsed.pieces + ' pcs but the pieces box says ' + pieces.value + '.';
        }
        if ((f || sel.value === '__new__') && !pieces.value) {
            warn.textContent = 'How many pieces are in one pack? A whole number, e.g. 8 or 12.';
        }
    }

    function suggest() {
        if (!title || !title.value.trim() || block.style.display === 'none') { return; }
        var url = SUGGEST + '?business_unit_id=2&title=' + encodeURIComponent(title.value) + (PRODUCT_ID ? '&product_id=' + PRODUCT_ID : '');
        fetch(url, {headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.success) { return; }
                parsed = d.parsed;
                // NEW product: fill the suggestion in (shown, changeable). EDIT: never change a saved
                // product by itself — only OFFER the family (same rule as the phone, recipeFamilyForm.js).
                if (!touched && PRODUCT_ID && !sel.value && d.match) {
                    describe();
                    warn.innerHTML = 'Looks like part of <a href="#" id="rfOffer" style="text-decoration:underline;">' + esc(d.match.name) + '</a>' + (parsed && parsed.pieces ? ' (' + parsed.pieces + ' pcs)' : '') + ' — click to use it.';
                    document.getElementById('rfOffer').onclick = function (e) {
                        e.preventDefault();
                        sel.value = String(d.match.id);
                        if (parsed && parsed.pieces && !pieces.value) { pieces.value = parsed.pieces; }
                        touched = true;
                        describe();
                    };
                    return;
                }
                if (!touched && !PRODUCT_ID) {
                    if (d.match) {
                        sel.value = String(d.match.id);
                    } else if (parsed && parsed.pieces) {
                        sel.value = '__new__';
                        newIn.value = parsed.name;
                    }
                    if (parsed && parsed.pieces) { pieces.value = parsed.pieces; }
                }
                describe();
                if (!d.match && (!sel.value || sel.value === '__new__') && (d.did_you_mean || []).length) {
                    var dm = d.did_you_mean[0];
                    warn.innerHTML = 'Did you mean <a href="#" id="rfDym" style="text-decoration:underline;">' + esc(dm.name) + '</a>?';
                    document.getElementById('rfDym').onclick = function (e) {
                        e.preventDefault();
                        sel.value = String(dm.id);
                        touched = true;
                        describe();
                    };
                }
            })
            .catch(function () { /* no Frozen access or offline: the block still works by hand */ });
    }

    sel.addEventListener('change', function () {
        touched = true;
        // the title already says "(8 pcs)" — fill an EMPTY pieces box from it (same as the phone)
        if (sel.value && !pieces.value && parsed && parsed.pieces) { pieces.value = parsed.pieces; }
        if (sel.value === '__new__' && !newIn.value && parsed && parsed.name) { newIn.value = parsed.name; }
        describe();
    });
    pieces.addEventListener('input', function () { touched = true; describe(); });
    if (title) { title.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(suggest, 400); }); }
    if (bu) { bu.addEventListener('change', function () { applyVisibility(); suggest(); }); }
    if (bundle) { bundle.addEventListener('change', applyVisibility); }

    applyVisibility();
    describe();
    if (!touched) { suggest(); }
})();
</script>
@endif
