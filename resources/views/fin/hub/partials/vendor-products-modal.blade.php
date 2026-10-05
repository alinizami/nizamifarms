{{-- In-Hub product manager for a by-weight vendor.

     Same operations, same endpoints as the old /finance/vendors/{id}/products page
     (VendorProductController — store/update/toggle/set-default/destroy) — only the UI is Hub-styled.
     Rows are server-rendered from $vendorProducts (ALL products, inactive included: managing means
     seeing what's switched off; the ⚖ purchase modal keeps its active-only JSON list). Writes reload
     the page, the same pattern as every other Hub modal.

     ❄ Oct-2026 (owner: "add the ingredient and grams fields to the hub products window … add all
     features here"): the window now carries everything the full page's form does, fed by the SAME
     VendorProductController::managerContext() — the ONE unit list (no free-text unit), the purchase
     lock on the unit, Category, and for a Frozen vendor the ingredient tag with "how much is in one
     pack", the wrong-kind warning with "Use …" / "Change … everywhere", and the server's refusals in
     words. The server rules are unchanged and still decide.

     ⚠⚠ The ingredient key is sent ONLY when an ingredient is chosen, or when an existing tag is
        cleared on purpose. Absent = the server keeps the tag (VendorProductController::ingredientFields),
        so a rate edit can never untag a product — not even one whose ingredient is now hidden.

     Read-only users can open and look; every write control is hidden. --}}
@php
    $prReadOnly    = auth()->user()?->isReadOnly();
    $pm            = $productManager ?? [];
    $prIngredients = $pm['ingredients'] ?? [];
    $prUnits       = $pm['unitCatalogue'] ?? \App\Services\FIN\VendorUnits::catalogue();
    $prCounts      = $pm['purchaseCounts'] ?? [];
    $prCategories  = $pm['categories'] ?? [];
    // Which ingredients this vendor already has a product for — offered second, never dropped
    // (a second pack size of the same ingredient needs the same name). Same rule as the full page.
    $prAdded = [];
    foreach ($vendorProducts as $vp) {
        $iid = (int) ($vp->ingredient_id ?? 0);
        if ($iid && !isset($prAdded[$iid])) { $prAdded[$iid] = trim((string) $vp->product_name); }
    }
    $prIngFresh = array_values(array_filter($prIngredients, fn ($i) => !isset($prAdded[(int) $i['id']])));
    $prIngOld   = array_values(array_filter($prIngredients, fn ($i) =>  isset($prAdded[(int) $i['id']])));
    // Tags on this vendor's products that are NOT in the live list (hidden since) — named so a row
    // and the edit form can still say what the product counts towards.
    $prTagNames = [];
    try {
        $tagIds = $vendorProducts->pluck('ingredient_id')->filter()->unique()->values()->all();
        if ($tagIds) {
            $prTagNames = \App\Models\Khaas\IngredientModel::whereIn('id', $tagIds)->get(['id', 'name', 'base_unit', 'is_active'])
                ->mapWithKeys(fn ($i) => [(int) $i->id => ['name' => $i->name, 'base_unit' => $i->base_unit, 'active' => (bool) $i->is_active]])->all();
        }
    } catch (\Throwable $e) {
        $prTagNames = [];
    }
    $prBaseWord = ['g' => 'g', 'ml' => 'ml', 'pcs' => 'pcs'];
@endphp
<div class="hubmodal" id="hubProducts" onclick="if(event.target===this)hubClose('hubProducts')">
    <div class="hubmodal-box wide">
        <div class="hubmodal-head">
            <div>
                <h3>Products</h3>
                <div class="hm-sub">{{ $vendor->vendor_name }} · line items for ⚖ purchases · <a href="/finance/vendors/{{ $vendor->id }}/products" style="color:inherit">full page ↗</a></div>
            </div>
            <button class="hubmodal-x" type="button" onclick="hubClose('hubProducts')" aria-label="Close">✕</button>
        </div>
        <div class="hubmodal-body">
            <div class="m-err" id="hubPrErr"></div>

            @unless($prReadOnly)
            {{-- Add / edit form. One form, two modes — hubPrEditId decides which endpoint. --}}
            <div class="pr-form" id="hubPrForm">
                <input type="hidden" id="hubPrEditId" value="">
                <div class="fld-row" style="align-items:flex-end">
                    <div class="fld" style="flex:2"><label>Product</label>
                        <input type="text" id="hubPrName" placeholder="e.g. Mutton (boneless)" autocomplete="off" @if(!empty($prIngredients)) list="hubPrIngNames" @endif>
                    </div>
                    <div class="fld"><label>Unit</label>
                        {{-- ⭐ the ONE unit list (App\Services\FIN\VendorUnits) — the full page and the phone render the same one --}}
                        <select id="hubPrUnit">
                            @foreach($prUnits as $u)
                                <option value="{{ $u['code'] }}" title="{{ $u['qty_label'] }}" @if($u['code'] === 'kg') selected @endif>{{ $u['one'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="fld"><label>Rate / unit (Rs.)</label><input type="number" step="0.01" min="0.01" id="hubPrRate" placeholder="0.00"></div>
                    <div class="fld" style="flex:0 0 auto"><label style="text-transform:none;letter-spacing:0"><input type="checkbox" id="hubPrDefault" style="width:auto"> Default</label></div>
                    <div class="fld" style="flex:0 0 auto">
                        <button class="btn primary" type="button" id="hubPrSubmit" onclick="hubPrSubmit()">＋ Add product</button>
                        <button class="btn" type="button" id="hubPrCancelEdit" onclick="hubPrResetForm()" style="display:none">Cancel</button>
                    </div>
                </div>
                <div id="hubPrNameHint" class="hint" style="display:none;color:#4338CA;margin:-6px 0 8px"></div>
                <div id="hubPrUnitLock" class="hint" style="display:none;color:#92400E;margin:-6px 0 8px"></div>

                <div class="fld-row" style="align-items:flex-start">
                    @if(!empty($prCategories))
                    <div class="fld"><label>Category</label>
                        <select id="hubPrCategory">
                            <option value="">— untagged —</option>
                            @foreach($prCategories as $cat)
                                <option value="{{ $cat }}">{{ $cat }}</option>
                            @endforeach
                        </select>
                        <span class="hint">Groups this vendor's purchases in the Sales vs Purchase report.</span>
                    </div>
                    @endif
                    @if(!empty($prIngredients))
                    <div class="fld" style="flex:2"><label>Frozen ingredient</label>
                        <select id="hubPrIngredient">
                            <option value="">— not an ingredient —</option>
                            @foreach($prIngFresh as $ing)
                                <option value="{{ $ing['id'] }}">{{ $ing['name'] }}</option>
                            @endforeach
                            @if(!empty($prIngOld))
                            <optgroup label="Already on this vendor's list">
                                @foreach($prIngOld as $ing)
                                    <option value="{{ $ing['id'] }}">{{ $ing['name'] }} — already “{{ $prAdded[(int) $ing['id']] }}”</option>
                                @endforeach
                            </optgroup>
                            @endif
                        </select>
                        <span class="hint">Purchases of a tagged product count towards that ingredient's stock and cost per pack.</span>
                    </div>
                    <div class="fld" id="hubPrPackWrap" style="display:none"><label id="hubPrPackLabel">How much in one pack?</label>
                        <input type="number" step="0.001" min="0.001" id="hubPrPack" placeholder="e.g. 500">
                        <span class="hint" id="hubPrPackHint">A pack could be any size, so say how many grams one holds.</span>
                    </div>
                    @endif
                </div>
                @if(!empty($prIngredients))
                <div id="hubPrMismatch" style="display:none;margin:-4px 0 10px;padding:8px 10px;border-radius:8px;font-size:12px;background:#FFF7ED;border:1px solid #FDBA74;color:#9A3412"></div>
                <div id="hubPrChange" style="display:none;margin:0 0 10px"></div>
                @endif
                <span class="hint">The default product comes pre-selected on every ⚖ purchase line. Rates here are the prefills — each purchase line can still override its rate.</span>
            </div>
            @if(!empty($prIngredients))
            <datalist id="hubPrIngNames">
                @foreach($prIngFresh as $ing)<option value="{{ $ing['name'] }}"></option>@endforeach
                @foreach($prIngOld as $ing)<option value="{{ $ing['name'] }}" label="already added"></option>@endforeach
            </datalist>
            @endif
            @endunless

            @forelse($vendorProducts as $p)
                @php
                    $tag    = $p->ingredient_id ? ($prTagNames[(int) $p->ingredient_id] ?? null) : null;
                    $nBuys  = (int) ($prCounts[$p->id] ?? 0);
                    $isPack = in_array(\App\Services\FIN\VendorUnits::canonical($p->unit), ['pack', 'box'], true);
                @endphp
                <div class="pr-row {{ $p->is_active ? '' : 'off' }}">
                    <span class="pr-name">
                        @if($p->is_default)<span class="pr-star" title="Default — pre-selected on purchase lines">★</span>@endif
                        {{ $p->product_name }}
                        @unless($p->is_active)<span class="inactive-tag">inactive</span>@endunless
                        @if($tag)
                            <span style="display:inline-block;margin-left:6px;padding:1px 7px;border-radius:999px;background:#EEF2FF;color:#3730A3;font-size:11px;font-weight:600"
                                  title="Purchases count towards this Frozen ingredient">❄ {{ $tag['name'] }}@if($isPack && (float) $p->pack_qty_base > 0) · {{ rtrim(rtrim(number_format((float) $p->pack_qty_base, 3, '.', ''), '0'), '.') }} {{ $prBaseWord[$tag['base_unit']] ?? '' }} in one {{ \App\Services\FIN\VendorUnits::canonical($p->unit) }}@endif{{ $tag['active'] ? '' : ' (hidden)' }}</span>
                        @elseif(!empty($prIngredients) && $isPack)
                            <span style="display:inline-block;margin-left:6px;font-size:11px;color:#B45309">not linked to an ingredient</span>
                        @endif
                    </span>
                    <span class="pr-rate num">Rs. {{ number_format((float) $p->rate_per_unit, 2) }} <span class="pr-unit">/ {{ $p->unit }}</span></span>
                    @unless($prReadOnly)
                    <span class="row-actions">
                        <button class="mini-btn" type="button"
                            data-p='{{ json_encode([
                                'id' => $p->id, 'name' => $p->product_name, 'unit' => $p->unit, 'rate' => (float) $p->rate_per_unit,
                                'def' => (bool) $p->is_default, 'cat' => (string) $p->category_level_1,
                                'ing' => (int) ($p->ingredient_id ?? 0), 'ing_name' => $tag['name'] ?? null, 'ing_base' => $tag['base_unit'] ?? null,
                                'pack' => (float) ($p->pack_qty_base ?? 0), 'n' => $nBuys,
                            ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}'
                            onclick="hubPrEdit(JSON.parse(this.dataset.p))">Edit</button>
                        @if(!$p->is_default && $p->is_active)
                            <button class="mini-btn" type="button" onclick="hubPrAction('{{ $p->id }}/set-default', 'Default set')" title="Pre-select this product on purchase lines">★ Default</button>
                        @endif
                        <button class="mini-btn" type="button" onclick="hubPrAction('{{ $p->id }}/toggle', '{{ $p->is_active ? 'Switched off' : 'Switched on' }}')">{{ $p->is_active ? 'Turn off' : 'Turn on' }}</button>
                        <button class="mini-btn danger" type="button" onclick="hubPrDelete({{ $p->id }}, @json($p->product_name))">Delete</button>
                    </span>
                    @endunless
                </div>
            @empty
                <div class="empty">No products yet{{ $prReadOnly ? '' : ' — add the first one above' }}.</div>
            @endforelse
        </div>
        <div class="hubmodal-foot">
            <button class="btn" type="button" onclick="hubClose('hubProducts')">Close</button>
        </div>
    </div>
</div>

<script>
(function(){
    var VID = @json($vendor->id);
    var csrf = (document.querySelector('meta[name="csrf-token"]')||{}).content || '';
    var el = function(id){ return document.getElementById(id); };
    // ❄ the same facts the full page reads (VendorProductController::managerContext)
    var ING   = @json($prIngredients);
    var UNITS = @json($prUnits);
    var INGREDIENTS_URL = @json(route('khaas.ingredients'));
    var BASE_WORD = {g:'grams', ml:'ml', pcs:'pieces'};
    var KIND_WORD = {g:'weight', ml:'volume', pcs:'pieces'};
    var SUGGESTED = {g:'kg', ml:'litre', pcs:'piece'};
    var LEGACY    = {pcs:'piece', liter:'litre', l:'litre', ltr:'litre', gm:'g'};
    var editing = null;   // the product being edited (its data-p), or null when adding

    function err(m){ var e=el('hubPrErr'); e.textContent=m; e.classList.add('on'); e.scrollIntoView({block:'nearest'}); }
    function errHide(){ el('hubPrErr').classList.remove('on'); }
    function done(msg){ if(window.hubToast) hubToast(msg); setTimeout(function(){ location.reload(); }, 700); }
    function escH(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function fd(extra){
        var f = new FormData(); f.append('_token', csrf);
        Object.keys(extra||{}).forEach(function(k){ f.append(k, extra[k]); });
        return f;
    }
    // ⭐ A refusal the server EXPLAINED (unit_locked, pack_size_needed, unit_mismatch …) is shown in
    //   its own words — the old handler only read validation `errors` and said "Check the fields."
    async function post(url, body){
        var r = await fetch(url, {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}, body: body});
        if(r.status === 422){
            var j = await r.json().catch(function(){ return {}; });
            var e1 = new Error(Object.values(j.errors||{}).flat()[0] || j.message || 'Check the fields.');
            e1.data = j; throw e1;
        }
        var j2 = await r.json().catch(function(){ return {success:r.ok}; });
        if(!j2.success){ var e2 = new Error(j2.message || 'Could not save.'); e2.data = j2; throw e2; }
        return j2;
    }
    function unitRow(code){ code = String(code||'').toLowerCase(); return UNITS.filter(function(u){ return u.code === code; })[0] || null; }
    function ingById(id){ return ING.filter(function(i){ return String(i.id) === String(id); })[0] || null; }
    /** The ingredient the form currently means — a hidden one kept from the product counts too. */
    function currentIng(){
        var s = el('hubPrIngredient'); if(!s || !s.value) return null;
        var i = ingById(s.value);
        if(i) return i;
        if(editing && String(editing.ing) === String(s.value)) return {id: editing.ing, name: editing.ing_name || 'its ingredient', base_unit: editing.ing_base || 'g', recipe_count: 0};
        return null;
    }

    window.hubOpenProducts = function(){ errHide(); open('hubProducts'); };
    function open(id){ el(id).classList.add('on'); }

    /** A legacy free-text unit ("100gm") is shown as it is, and must be re-picked. */
    function setUnit(unit){
        var u = el('hubPrUnit');
        u.querySelectorAll('option[data-legacy]').forEach(function(o){ o.remove(); });
        var canon = unitRow(unit) ? String(unit).toLowerCase() : (LEGACY[String(unit||'').toLowerCase()] || null);
        if(canon){ u.value = canon; }
        else if(unit){ var o=document.createElement('option'); o.value=unit; o.textContent=unit+' — not a unit, pick one'; o.dataset.legacy='1'; u.appendChild(o); u.value=unit; }
        else { u.value = 'kg'; }
    }

    /** Pack-size box, wrong-kind warning and the unit lock — the full page's rules, Hub-styled. */
    function sync(){
        var wrap = el('hubPrPackWrap'); if(!wrap) return;
        var ing = currentIng(), unit = el('hubPrUnit').value, row = unitRow(unit);
        var needs = !!ing && !!row && row.kind === null;
        wrap.style.display = needs ? '' : 'none';
        var pack = el('hubPrPack');
        if(needs){
            el('hubPrPackLabel').textContent = 'How many ' + (BASE_WORD[ing.base_unit]||'grams') + ' of ' + ing.name + ' in one ' + unit + '?';
            // 🔒 A size already set on a product with purchases stays — old bills were counted with it.
            //    An EMPTY size can always be filled (the Sep-30 Red Chilli case). Mirrors the server.
            var locked = !!editing && editing.n > 0 && editing.pack > 0 && String(editing.ing) === String(ing.id);
            pack.readOnly = locked;
            pack.style.background = locked ? 'var(--surface2, #F3F4F6)' : '';
            el('hubPrPackHint').textContent = locked
                ? '🔒 The size stays — ' + editing.n + ' recorded purchase' + (editing.n === 1 ? ' was' : 's were') + ' counted with it.'
                : 'A ' + unit + ' could be any size, so say how many ' + (BASE_WORD[ing.base_unit]||'grams') + ' one holds.';
        }
        var box = el('hubPrMismatch');
        var wrong = !!ing && !!row && row.kind !== null && row.kind !== ing.base_unit;
        box.style.display = wrong ? '' : 'none';
        if(wrong){
            var s = SUGGESTED[ing.base_unit], unitLocked = !!editing && editing.n > 0;
            box.innerHTML = '<b>' + escH(ing.name) + ' is counted in ' + escH((KIND_WORD[ing.base_unit]||'').toUpperCase())
                + (ing.recipe_count > 0 ? ' in your recipes' : '') + ', but this product is set to ' + escH(unit) + '.</b>'
                + (unitLocked
                    ? '<div style="margin-top:6px">Its unit is locked by ' + editing.n + ' recorded purchase' + (editing.n === 1 ? '' : 's') + ', so it cannot switch. Leave it untagged, or add a new ' + escH(s) + ' product for ' + escH(ing.name) + ' and switch this one off.</div>'
                      + '<div style="margin-top:6px"><button type="button" class="mini-btn" onclick="hubPrUntag()">Leave it untagged</button></div>'
                    : '<div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap">'
                      + '<button type="button" class="mini-btn solid" onclick="hubPrUseUnit(\'' + s + '\')">Use ' + escH(unitRow(s) ? unitRow(s).many : s) + '</button>'
                      + '<button type="button" class="mini-btn" onclick="hubPrChangeEverywhere()">Change ' + escH(ing.name) + ' to ' + escH(KIND_WORD[row.kind]) + ' everywhere…</button></div>');
        } else {
            var cp = el('hubPrChange'); if(cp){ cp.style.display='none'; cp.innerHTML=''; }
        }
    }

    window.hubPrUseUnit = function(code){ el('hubPrUnit').value = code; var cp=el('hubPrChange'); if(cp){cp.style.display='none';cp.innerHTML='';} sync(); };
    window.hubPrUntag = function(){ var s=el('hubPrIngredient'); if(s){ s.value=''; } sync(); };

    /** ⭐ Typing a product name that IS an ingredient's name picks that ingredient (case-insensitive). */
    function matchName(){
        var s = el('hubPrIngredient'); if(!s) return;
        var name = (el('hubPrName').value||'').trim().toLowerCase();
        var hit = ING.filter(function(i){ return i.name.toLowerCase() === name; })[0];
        var hint = el('hubPrNameHint');
        if(hit){
            s.value = String(hit.id);
            hint.textContent = '✓ Matched the Frozen ingredient “' + hit.name + '” — purchases of this will count towards it.';
            hint.style.display = '';
            ingChanged();
        } else { hint.style.display = 'none'; }
    }
    function ingChanged(){
        var ing = currentIng();
        if(ing && !el('hubPrName').value.trim()){ el('hubPrName').value = ing.name; }
        // the unit follows the ingredient when the chosen one does not fit (and is not locked)
        var u = el('hubPrUnit'), row = unitRow(u.value);
        if(ing && !u.disabled && (!row || (row.kind !== null && row.kind !== ing.base_unit))){ u.value = SUGGESTED[ing.base_unit] || u.value; }
        sync();
    }

    window.hubPrResetForm = function(){
        editing = null; errHide();
        el('hubPrEditId').value=''; el('hubPrName').value=''; setUnit('kg');
        el('hubPrUnit').disabled = false; el('hubPrUnitLock').style.display='none';
        el('hubPrRate').value=''; el('hubPrDefault').checked=false;
        if(el('hubPrCategory')) el('hubPrCategory').value='';
        var s = el('hubPrIngredient');
        if(s){ s.querySelectorAll('option[data-kept]').forEach(function(o){ o.remove(); }); s.value=''; }
        if(el('hubPrPack')){ el('hubPrPack').value=''; el('hubPrPack').readOnly=false; el('hubPrPack').style.background=''; }
        el('hubPrNameHint').style.display='none';
        var cp=el('hubPrChange'); if(cp){ cp.style.display='none'; cp.innerHTML=''; }
        el('hubPrSubmit').textContent='＋ Add product'; el('hubPrCancelEdit').style.display='none';
        sync();
    };
    window.hubPrEdit = function(p){
        hubPrResetForm();
        editing = p;
        el('hubPrEditId').value=p.id; el('hubPrName').value=p.name; setUnit(p.unit);
        el('hubPrRate').value=p.rate; el('hubPrDefault').checked=!!p.def;
        if(el('hubPrCategory')){
            var c = el('hubPrCategory');
            if(p.cat && !Array.prototype.some.call(c.options, function(o){ return o.value === p.cat; })){
                var oc=document.createElement('option'); oc.value=p.cat; oc.textContent=p.cat; c.appendChild(oc);
            }
            c.value = p.cat || '';
        }
        // 🔒 purchase history locks the unit (old bills are re-stamped from the product on edit)
        var lock = el('hubPrUnitLock');
        el('hubPrUnit').disabled = p.n > 0;
        lock.style.display = p.n > 0 ? '' : 'none';
        lock.textContent = p.n > 0 ? '🔒 ' + p.n + ' purchase' + (p.n === 1 ? '' : 's') + ' recorded in ' + p.unit + ', so the unit stays. For a different unit, add a new product and turn this one off.' : '';
        var s = el('hubPrIngredient');
        if(s){
            if(p.ing && !ingById(p.ing)){
                // its ingredient is hidden now — keep it visible and selected, so nothing untags it
                var o=document.createElement('option'); o.value=String(p.ing); o.dataset.kept='1';
                o.textContent=(p.ing_name || 'Ingredient #' + p.ing) + ' (hidden)'; s.appendChild(o);
            }
            s.value = p.ing ? String(p.ing) : '';
            if(el('hubPrPack')) el('hubPrPack').value = p.pack > 0 ? p.pack : '';
        }
        el('hubPrSubmit').textContent='Save changes'; el('hubPrCancelEdit').style.display='';
        sync();
        el('hubPrForm').scrollIntoView({block:'nearest'});
        el('hubPrName').focus();
    };

    /** The ingredient fields to post — see the ⚠⚠ note at the top of this file. */
    function ingredientFields(){
        var s = el('hubPrIngredient'); if(!s) return {};
        var out = {};
        if(s.value){
            out.ingredient_id = Number(s.value);
            var wrap = el('hubPrPackWrap'), pack = el('hubPrPack');
            if(wrap && wrap.style.display !== 'none' && pack.value) out.pack_qty_base = Number(pack.value);
        } else if(editing && editing.ing){
            out.ingredient_id = 0;   // an existing tag cleared on purpose = "not an ingredient"
        }
        return out;
    }

    window.hubPrSubmit = async function(){
        errHide();
        var name=el('hubPrName').value.trim(), unit=el('hubPrUnit').value, rate=parseFloat(el('hubPrRate').value);
        if(!name) return err('Give the product a name.');
        if(!unit || !unitRow(unit)) return err('Choose a unit from the list (kg, g, piece, pack, …).');
        if(!(rate>0)) return err('Enter a rate per unit.');
        var ing = currentIng(), row = unitRow(unit);
        if(ing && row && row.kind === null && el('hubPrPack') && !(parseFloat(el('hubPrPack').value) > 0)){
            el('hubPrPack').focus();
            return err('How many ' + (BASE_WORD[ing.base_unit]||'grams') + ' of ' + ing.name + ' are in one ' + unit + '?');
        }
        if(editing && editing.ing && !(el('hubPrIngredient')||{}).value
            && !confirm('Remove the link to ' + (editing.ing_name || 'its ingredient') + '? Its purchases will stop counting towards it from now on.')) return;
        var id=el('hubPrEditId').value;
        var data = {product_name:name, unit:unit, rate_per_unit:rate, is_default: el('hubPrDefault').checked ? 1 : 0};
        if(el('hubPrCategory')) data.category_level_1 = el('hubPrCategory').value;
        var extra = ingredientFields();
        Object.keys(extra).forEach(function(k){ data[k] = extra[k]; });
        var body = fd(data);
        var btn=el('hubPrSubmit'); btn.disabled=true;
        try{
            if(id){ body.append('_method','PUT'); await post('/finance/vendors/'+VID+'/products/'+id, body); done('Product updated'); }
            else { await post('/finance/vendors/'+VID+'/products', body); done('Product added'); }
        }catch(e){
            btn.disabled=false;
            if(e.data && e.data.code === 'unit_mismatch' && e.data.change){ renderChange(e.data.change); }
            err(e.message);
        }
    };

    // ── "Change <ingredient> to weight everywhere…" — the same endpoints the full page uses ──
    window.hubPrChangeEverywhere = async function(){
        var ing = currentIng(), unit = el('hubPrUnit').value, row = unitRow(unit);
        if(!ing || !row || !row.kind) return;
        var pid = el('hubPrEditId').value;
        try{
            var r = await fetch(INGREDIENTS_URL + '/' + ing.id + '/unit-impact?to=' + row.kind + (pid ? '&product_id=' + pid + '&unit=' + encodeURIComponent(unit) : ''), {headers:{'Accept':'application/json'}});
            var d = await r.json();
            if(!d.success) return err(d.message || 'Could not check what would change.');
            renderChange(d);
        }catch(e){ err('Could not check what would change.'); }
    };
    function renderChange(c){
        var p = el('hubPrChange'); if(!p) return;
        var h = '<div style="padding:10px;border-radius:8px;font-size:12.5px;background:#EEF2FF;border:1px solid #A5B4FC">'
            + '<b style="color:#312E81">Change ' + escH(c.ingredient.name) + ' from ' + escH(c.from_word) + ' to ' + escH(c.to_word) + ' everywhere?</b>';
        if(!c.can_change){
            h += '<div style="margin-top:6px;color:#991B1B">It cannot change: ' + escH((c.locked_reasons||[]).join('; ')) + '. Those numbers were written in ' + escH(BASE_WORD[c.from]) + '. Use ' + escH(SUGGESTED[c.from]) + ' for this product instead — or, on Frozen Inventory → Ingredients, “Replace with another ingredient…”.</div>';
        } else if(c.can_manage === false){
            h += '<div style="margin-top:6px;color:#991B1B">Only Taimur, Shabib or Qasim can change an ingredient\'s unit.</div>';
        } else {
            if((c.recipe_lines||[]).length){
                h += '<div style="margin-top:6px">These recipes use it — type each new amount in ' + escH(c.to_base_word) + ':</div>';
                c.recipe_lines.forEach(function(l){
                    h += '<label style="display:flex;gap:8px;align-items:center;margin-top:4px"><span style="flex:1">' + escH(l.product_name) + ' v' + l.version + (l.is_current ? ' (current)' : '') + ' — was ' + Number(l.qty) + ' ' + escH(BASE_WORD[c.from]) + '</span>'
                        + '<input type="number" step="0.001" min="0.001" class="hub-cu-recipe" data-id="' + l.id + '" placeholder="' + escH(c.to_base_word) + '" style="width:110px"></label>';
                });
            } else { h += '<div style="margin-top:6px">No recipe uses it yet.</div>'; }
            (c.products||[]).forEach(function(pr){
                h += '<div style="display:flex;gap:8px;align-items:center;margin-top:4px"><span style="flex:1">' + escH(pr.vendor_name||'') + ' · ' + escH(pr.product_name) + ' (' + escH(pr.unit) + ')'
                    + (pr.needs_size ? '' : ' — automatic, ' + pr.new_pack_qty_base + ' ' + escH(c.to_base_word) + ' each') + '</span>'
                    + (pr.needs_size ? '<input type="number" step="0.001" min="0.001" class="hub-cu-size" data-id="' + pr.id + '" placeholder="' + escH(c.to_base_word) + ' in one" style="width:110px">' : '') + '</div>';
            });
            h += '<div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap"><button type="button" class="mini-btn solid" onclick="hubPrApplyChange()">Change everywhere &amp; save the product</button>';
        }
        h += (c.can_change && c.can_manage !== false ? '' : '<div style="margin-top:8px">') + '<button type="button" class="mini-btn" onclick="hubPrUseUnit(\'' + SUGGESTED[c.from] + '\')">Keep ' + escH(c.ingredient.name) + ' in ' + escH(c.from_word) + '</button></div></div>';
        p.innerHTML = h; p.dataset.ingredient = c.ingredient.id; p.dataset.to = c.to; p.style.display = '';
    }
    window.hubPrApplyChange = async function(){
        var p = el('hubPrChange');
        var body = {to: p.dataset.to, recipe_qty: {}, product_sizes: {}}, missing = false;
        p.querySelectorAll('.hub-cu-recipe').forEach(function(i){ if(!(Number(i.value) > 0)) missing = true; body.recipe_qty[i.dataset.id] = Number(i.value); });
        p.querySelectorAll('.hub-cu-size').forEach(function(i){ if(!(Number(i.value) > 0)) missing = true; body.product_sizes[i.dataset.id] = Number(i.value); });
        if(missing) return err('Type every new amount before changing.');
        var pid = el('hubPrEditId').value;
        if(pid){ body.product_units = {}; body.product_units[pid] = el('hubPrUnit').value; }
        try{
            var r = await fetch(INGREDIENTS_URL + '/' + p.dataset.ingredient + '/change-unit', {method:'POST',
                headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},
                body: JSON.stringify(body)});
            var d = await r.json();
            if(!d.success) return err(d.message || 'Nothing was changed.');
            // the ingredient now speaks the new kind — then save the product itself
            var ing = ingById(p.dataset.ingredient); if(ing) ing.base_unit = p.dataset.to;
            if(editing && String(editing.ing) === String(p.dataset.ingredient)) editing.ing_base = p.dataset.to;
            p.style.display='none'; p.innerHTML='';
            if(window.hubToast) hubToast(d.message || 'Changed everywhere');
            sync();
            hubPrSubmit();
        }catch(e){ err('Could not change the unit. Nothing was changed.'); }
    };

    window.hubPrAction = async function(path, msg){
        errHide();
        try{ await post('/finance/vendors/'+VID+'/products/'+path, fd()); done(msg); }
        catch(e){ err(e.message); }
    };
    window.hubPrDelete = async function(id, name){
        if(!confirm('Delete "'+name+'"? If it has purchase history it will be switched off instead.')) return;
        errHide();
        var body = fd({_method:'DELETE'});
        try{
            var j = await post('/finance/vendors/'+VID+'/products/'+id, body);
            done(j.deactivated ? 'Product switched off (has purchase history)' : 'Product deleted');
        }catch(e){ err(e.message); }
    };

    // wiring (the form only exists for someone who can write)
    if(el('hubPrForm')){
        el('hubPrUnit').addEventListener('change', sync);
        if(el('hubPrIngredient')){
            el('hubPrIngredient').addEventListener('change', ingChanged);
            el('hubPrName').addEventListener('input', matchName);
            el('hubPrName').addEventListener('change', matchName);
        }
        sync();
    }
})();
</script>
