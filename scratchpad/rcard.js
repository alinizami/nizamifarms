
(function () {
    var VENDOR = 50;
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
        fetch('http://localhost/finance/vendors/50/products/list', {
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
        })
        .then(function (r) { return r.json().catch(function () { return {}; }); })
        .then(function (d) {
            if (!(d && d.success)) { return; }
            PRODUCTS = d.products || [];
            SUPPORTS_ING = d.supports_ingredients === true;
            // ❄ Only a Frozen vendor gets the ingredient list; fails soft to none.
            if (SUPPORTS_ING && !INGREDIENTS.length) {
                fetch('http://localhost/khaas/ingredients', {
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

        fetch('http://localhost/finance/vendors/50/receipt/extract', {
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
              '<div style="font-size:12.5px; font-weight:700; color:#111827;">New product for Imtiaz Store frozen</div>' +
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
    var RC_UNITS = [{"code":"kg","kind":"g","factor":1000,"whole":false,"qty_label":"Weight (kg)","one":"kg","many":"kg"},{"code":"g","kind":"g","factor":1,"whole":false,"qty_label":"Weight (g)","one":"g","many":"g"},{"code":"litre","kind":"ml","factor":1000,"whole":false,"qty_label":"Volume (litre)","one":"litre","many":"litres"},{"code":"ml","kind":"ml","factor":1,"whole":false,"qty_label":"Volume (ml)","one":"ml","many":"ml"},{"code":"piece","kind":"pcs","factor":1,"whole":true,"qty_label":"Qty (pieces)","one":"piece","many":"pieces"},{"code":"dozen","kind":"pcs","factor":12,"whole":true,"qty_label":"Qty (dozens)","one":"dozen","many":"dozens"},{"code":"pack","kind":null,"factor":null,"whole":true,"qty_label":"Qty (packs)","one":"pack","many":"packs"},{"code":"box","kind":null,"factor":null,"whole":true,"qty_label":"Qty (boxes)","one":"box","many":"boxes"}];
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

        fetch('http://localhost/finance/vendors/50/products', {
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
        fetch('http://localhost/finance/vendors/50/receipt/record', {
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
