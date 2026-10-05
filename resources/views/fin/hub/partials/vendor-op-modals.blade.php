{{-- Vendor operational modals: purchase (by_total), weighted purchase (line items), payment,
     report, edit/delete transaction. Post to the existing fin.vendors.* endpoints.
     Expects $vendor, $payable, $paymentSources, $receivingBanks. --}}
@php
    $venBanks = $receivingBanks;
@endphp

{{-- The report renderer shared with the old vendor page — same markup, same print. --}}
@include('fin.partials.vendor-report-render')

{{-- ===== Purchase (by_total) ===== --}}
<div class="hubmodal" id="hubPurchase" onclick="if(event.target===this)hubClose('hubPurchase')">
  <div class="hubmodal-box">
    <div class="hubmodal-head"><div><h3>Record purchase</h3><div class="hm-sub">{{ $vendor->vendor_name }} · on account</div></div><button class="hubmodal-x" type="button" onclick="hubClose('hubPurchase')">✕</button></div>
    <div class="hubmodal-body">
      <div class="m-err" id="hubPuErr"></div>
      <form id="hubPurchaseForm" onsubmit="return false" enctype="multipart/form-data">@csrf
        <div class="fld-row">
          <div class="fld"><label>Amount (Rs.)</label><input type="number" step="0.01" min="0.01" name="amount" placeholder="0.00"></div>
          <div class="fld"><label>Date</label><input type="date" name="transaction_date" value="{{ now()->format('Y-m-d') }}"></div>
        </div>
        <div class="fld"><label>Description</label><textarea name="description" rows="2" placeholder="Entry by {{ auth()->user()->fullname ?? '' }}">Entry by {{ auth()->user()->fullname ?? '' }}</textarea></div>
        <div class="fld"><label>Bill images (optional — pick several)</label><input type="file" name="bill_images[]" accept="image/*" multiple></div>
      </form>
    </div>
    <div class="hubmodal-foot"><button class="btn" type="button" onclick="hubClose('hubPurchase')">Cancel</button><button class="btn primary" type="button" onclick="hubSubmitPurchase()">Record purchase</button></div>
  </div>
</div>

{{-- ===== Weighted purchase (line items) ===== --}}
<div class="hubmodal" id="hubWeighted" onclick="if(event.target===this)hubClose('hubWeighted')">
  <div class="hubmodal-box xwide">
    <div class="hubmodal-head"><div><h3 id="hubWtTitle">Purchase by weight</h3><div class="hm-sub" id="hubWtSub">{{ $vendor->vendor_name }} · line items</div></div><button class="hubmodal-x" type="button" onclick="hubClose('hubWeighted')">✕</button></div>
    <div class="hubmodal-body">
      <div class="m-err" id="hubWtErr"></div>
      <div class="wt-head"><span>Product</span><span>Qty</span><span>Rate</span><span>Line total</span><span></span></div>
      <div id="hubWtLines"></div>
      <button class="mini-btn" type="button" onclick="hubWtAddLine()" style="margin:6px 0 12px">＋ Add line</button>
      <div class="fld-row">
        <div class="fld"><label>Adjustment (Rs., +/−)</label><input type="number" step="0.01" id="hubWtAdj" value="0" oninput="hubWtTotal()"><span class="hint">discount (−) or extra (+)</span></div>
        <div class="fld"><label>Date</label><input type="date" id="hubWtDate" value="{{ now()->format('Y-m-d') }}"></div>
      </div>
      <div class="fld"><label>Description</label><input type="text" id="hubWtDesc" placeholder="Entry by {{ auth()->user()->fullname ?? '' }}" value="Entry by {{ auth()->user()->fullname ?? '' }}"></div>
      <div class="fld">
        <label id="hubWtBillLabel">Bill images (optional — pick several) </label>
        {{-- Editing: current images render here with per-image remove (hubImgMgr). --}}
        <div class="img-mgr" id="hubWtImgs" style="display:none"></div>
        <input type="file" id="hubWtBill" accept="image/*" multiple>
      </div>
      <div class="inv-total"><span>Grand total</span><span class="num" id="hubWtGrand">Rs. 0.00</span></div>
    </div>
    <div class="hubmodal-foot"><button class="btn" type="button" onclick="hubClose('hubWeighted')">Cancel</button><button class="btn primary" type="button" id="hubWtSubmit" onclick="hubSubmitWeighted()" disabled>Record purchase</button></div>
  </div>
</div>

{{-- ===== Payment ===== --}}
<div class="hubmodal" id="hubPay" onclick="if(event.target===this)hubClose('hubPay')">
  <div class="hubmodal-box">
    <div class="hubmodal-head"><div><h3>Record payment</h3><div class="hm-sub">{{ $vendor->vendor_name }} · owed Rs. {{ number_format($payable, 0) }}</div></div><button class="hubmodal-x" type="button" onclick="hubClose('hubPay')">✕</button></div>
    <div class="hubmodal-body">
      <div class="m-err" id="hubPayErr"></div>
      <form id="hubPayForm" onsubmit="return false" enctype="multipart/form-data">@csrf
        <div class="fld-row">
          <div class="fld"><label>Amount (Rs.)</label><input type="number" step="0.01" min="0.01" max="{{ $payable }}" name="amount" placeholder="0.00"><span class="hint">max Rs. {{ number_format($payable, 2) }}</span></div>
          <div class="fld"><label>Pay from</label>
            <select name="payment_source_account_id" id="hubPaySrc" onchange="hubPaySrcChange()">
              <option value="" data-cat="cash">NF Cash (default)</option>
              @foreach($paymentSources as $ps)
                <option value="{{ $ps->id }}" data-cat="{{ $ps->account_category }}">{{ $ps->account_name }}</option>
              @endforeach
            </select>
          </div>
        </div>
        <div class="fld" id="hubPayBankWrap" style="display:none">
          <label>🏦 Which bank is this paid from?</label>
          <div class="bankchips" id="hubPayBankChips"></div>
          <input type="hidden" name="receiving_account_id" id="hubPayBank">
        </div>
        <div class="fld-row">
          <div class="fld"><label>Payment date</label><input type="date" name="transaction_date" value="{{ now()->format('Y-m-d') }}"></div>
          <div class="fld"><label>Posted date</label><input type="date" name="posted_date" value="{{ now()->format('Y-m-d') }}"></div>
        </div>
        <div class="fld"><label>Description (optional)</label><textarea name="description" rows="2"></textarea></div>
        <div class="fld"><label>Receipt images (optional — pick several)</label><input type="file" name="receipt_images[]" accept="image/*" multiple></div>
      </form>

      {{-- Shown AFTER the payment is recorded, when a bank debit matching it is
           still waiting in the assistant's money box. Answering files the SMS
           against this entry; skipping leaves it exactly where it was. --}}
      <div id="hubPaySms" style="display:none">
        <div class="smsq-head">✅ Payment recorded. Is this its bank SMS?</div>
        <div class="smsq-sub">Filing it clears the message from your money box, and records which entry it belongs to.</div>
        <div id="hubPaySmsList"></div>
      </div>
    </div>
    <div class="hubmodal-foot" id="hubPayFoot"><button class="btn" type="button" onclick="hubClose('hubPay')">Cancel</button><button class="btn primary" type="button" onclick="hubSubmitPayment()">Record payment</button></div>
    <div class="hubmodal-foot" id="hubPaySmsFoot" style="display:none"><button class="btn" type="button" onclick="hubPaySmsDone()">Not this one — leave it</button></div>
  </div>
</div>

{{-- ===== Report ===== --}}
<div class="hubmodal" id="hubReport" onclick="if(event.target===this)hubClose('hubReport')">
  <div class="hubmodal-box wide">
    <div class="hubmodal-head"><div><h3>Vendor report</h3><div class="hm-sub">{{ $vendor->vendor_name }}</div></div><button class="hubmodal-x" type="button" onclick="hubClose('hubReport')">✕</button></div>
    <div class="hubmodal-body">
      <div class="filter-row" style="margin-bottom:12px">
        <div class="fld"><label>From</label><input type="date" id="hubRepFrom" value="{{ now()->startOfMonth()->format('Y-m-d') }}"></div>
        <div class="fld"><label>To</label><input type="date" id="hubRepTo" value="{{ now()->format('Y-m-d') }}"></div>
        <div class="fld" style="justify-content:flex-end"><label style="text-transform:none;letter-spacing:0"><input type="checkbox" id="hubRepPay" checked style="width:auto"> Show payments</label></div>
        <div class="fld"><label>&nbsp;</label><button class="btn primary" type="button" onclick="hubRunReport()">Generate</button></div>
      </div>
      <div class="m-err" id="hubRepErr"></div>
      <div id="hubRepOut"></div>
    </div>
    <div class="hubmodal-foot">
      <button class="btn" type="button" onclick="hubClose('hubReport')">Close</button>
      <button class="btn" type="button" id="hubRepCsv" onclick="hubReportCsv()" style="display:none">⤓ Excel (CSV)</button>
      <button class="btn primary" type="button" id="hubRepPrint" onclick="hubReportPrint()" style="display:none">🖨 Print</button>
    </div>
  </div>
</div>

{{-- ===== Edit transaction (simple) ===== --}}
<div class="hubmodal" id="hubEditTxn" onclick="if(event.target===this)hubClose('hubEditTxn')">
  <div class="hubmodal-box">
    <div class="hubmodal-head"><div><h3 id="hubEtTitle">Edit transaction</h3><div class="hm-sub">append-only — corrections keep history</div></div><button class="hubmodal-x" type="button" onclick="hubClose('hubEditTxn')">✕</button></div>
    <div class="hubmodal-body">
      <div class="m-err" id="hubEtErr"></div>
      <form id="hubEtForm" onsubmit="return false">@csrf
        <input type="hidden" id="hubEtId">
        <div class="fld-row">
          <div class="fld"><label>Amount (Rs.)</label><input type="number" step="0.01" min="0.01" id="hubEtAmount"></div>
          <div class="fld"><label>Date</label><input type="date" id="hubEtDate"></div>
        </div>
        <div class="fld"><label>Description</label><textarea id="hubEtDesc" rows="2"></textarea></div>
        {{-- Only for ONLINE payments: which of OUR banks it left from. Re-tag only — per-bank
             balances are derived from this tag, and the description's "· via XX" follows it. --}}
        <div class="fld" id="hubEtBankWrap" style="display:none">
          <label>🏦 Paid from bank</label>
          <div class="bankchips" id="hubEtBankChips"></div>
        </div>
        <div class="fld">
          <label>Bill / receipt images</label>
          {{-- Current images with per-image ✕ (fetched on open; falls back to the plain
               "replace" input below if that fetch fails). --}}
          <div class="img-mgr" id="hubEtImgs" style="display:none"></div>
          <input type="file" id="hubEtBill" accept="image/*" multiple>
          <span class="hint" id="hubEtBillHint">Pick files to ADD images; ✕ a thumbnail to remove it. Nothing changes until you save.</span>
        </div>
      </form>
    </div>
    <div class="hubmodal-foot"><button class="btn" type="button" onclick="hubClose('hubEditTxn')">Cancel</button><button class="btn primary" type="button" onclick="hubSubmitEditTxn()">Save changes</button></div>
  </div>
</div>

<script>
(function(){
    var VID = @json($vendor->id);
    var VNAME = @json($vendor->vendor_name);
    var PAYABLE = @json((float)$payable);
    var BANKS = @json($venBanks);
    var csrf = (document.querySelector('meta[name="csrf-token"]')||{}).content || '';
    var products = null;
    var fmt2 = function(n){ return Number(n).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); };
    // ⚠ Deliberately NOT named fmt0/esc: every hub partial on this page shares
    // ONE script scope, and sibling partials already define a `fmt0` with a
    // different contract (theirs prefixes "Rs. "). Distinct names cannot be
    // shadowed by include order.
    var smsFmt0 = function(n){ return Number(n).toLocaleString(undefined,{maximumFractionDigits:0}); };
    var smsEsc  = function(s){ var d=document.createElement('div'); d.textContent=(s==null?'':String(s)); return d.innerHTML; };

    window.hubClose = function(id){ document.getElementById(id).classList.remove('on'); };
    function open(id){ document.getElementById(id).classList.add('on'); }
    function errShow(id, m){ var e=document.getElementById(id); e.textContent=m; e.classList.add('on'); }
    function errHide(id){ document.getElementById(id).classList.remove('on'); }

    async function post(url, body, acceptJson){
        var headers = {'X-Requested-With':'XMLHttpRequest','Accept':'application/json'};
        if(!(body instanceof FormData)){ headers['Content-Type']='application/json'; }
        return fetch(url, {method:'POST', headers: headers, body: body});
    }
    function done(msg){ hubToast(msg); setTimeout(function(){ location.reload(); }, 800); }

    // View a row's bill/receipt image. Accepts a relative storage path (tries /storage first —
    // symlink, fast — then /public-storage for hosts where the symlink is missing, the attendance
    // pattern) or an already-full URL (the transaction-details payload returns one).
    window.hubViewBill = function(p){
        if(/^https?:\/\//.test(p)){ window.open(p,'_blank'); return; }
        var u='/storage/'+p, f='/public-storage/'+p, probe=new Image();
        probe.onload=function(){ window.open(u,'_blank'); };
        probe.onerror=function(){ window.open(f,'_blank'); };
        probe.src=u;
    };

    // Multi-image viewer for a row's 📎 chip. Renders an in-page overlay rather than opening
    // tabs: the fetch makes any window.open() async, and popup blockers kill async opens — the
    // second image would silently never appear. Click an image inside the overlay (a direct
    // user gesture) to open it full-size. Falls back to the legacy single-path viewer if the
    // fetch fails, so the chip never dead-ends.
    window.hubViewImages = function(ledgerId, fallbackPath){
        fetch('/finance/ledger/transaction/'+ledgerId, {headers:{'Accept':'application/json'}})
            .then(function(r){ return r.json(); })
            .then(function(j){
                var imgs = (j && j.success && j.transaction && j.transaction.bill_images) || [];
                if(!imgs.length){ if(fallbackPath) hubViewBill(fallbackPath); return; }
                if(imgs.length === 1){ window.open(imgs[0].url, '_blank'); return; }
                var ov = document.getElementById('hubImgOverlay');
                if(!ov){
                    ov = document.createElement('div');
                    ov.id = 'hubImgOverlay';
                    ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.82);z-index:12000;'
                        + 'overflow-y:auto;padding:28px;cursor:zoom-out';
                    ov.onclick = function(e){ if(e.target === ov) ov.style.display = 'none'; };
                    document.body.appendChild(ov);
                    document.addEventListener('keydown', function(e){ if(e.key === 'Escape') ov.style.display = 'none'; });
                }
                ov.innerHTML = '<div style="max-width:760px;margin:0 auto;display:flex;flex-direction:column;gap:14px">'
                    + '<div style="color:#e2e8f0;font-size:13px;display:flex;justify-content:space-between;align-items:center">'
                    + '<span>' + imgs.length + ' attached images — click one for full size, click outside to close</span>'
                    + '<button type="button" style="background:none;border:0;color:#e2e8f0;font-size:20px;cursor:pointer" '
                    + 'onclick="document.getElementById(\'hubImgOverlay\').style.display=\'none\'">✕</button></div>'
                    + imgs.map(function(im){
                        return '<a href="' + im.url + '" target="_blank" rel="noopener" style="cursor:zoom-in">'
                            + '<img src="' + im.url + '" alt="Attached bill or receipt" '
                            + 'style="width:100%;border-radius:10px;display:block;background:#fff"></a>';
                    }).join('')
                    + '</div>';
                ov.style.display = 'block';
            })
            .catch(function(){ if(fallbackPath) hubViewBill(fallbackPath); });
    };

    // ---------- Image manager (edit surfaces) ----------
    // Renders a row's CURRENT images as thumbnails, each with a ✕ that toggles it for removal
    // (dimmed until save — nothing is deleted before the form is submitted). One state entry per
    // host container, so the Edit modal, the weighted editor and the drawer can't collide.
    var imgMgrState = {};
    window.hubImgMgr = {
        render: function(hostId, images){
            var host = document.getElementById(hostId);
            if(!host) return;
            imgMgrState[hostId] = {removed: {}};
            var list = images || [];
            if(!list.length){ host.style.display='none'; host.innerHTML=''; return; }
            host.style.display='';
            host.innerHTML = list.map(function(im){
                return '<span class="im-th" data-img-id="'+im.id+'">'
                    + '<a href="'+im.url+'" target="_blank" rel="noopener"><img src="'+im.url+'" alt="Attached image"></a>'
                    + '<button type="button" class="im-x" title="Remove this image (applies when you save)">✕</button>'
                    + '</span>';
            }).join('');
            host.querySelectorAll('.im-x').forEach(function(btn){
                btn.onclick = function(){
                    var th = btn.closest('.im-th');
                    var id = th.getAttribute('data-img-id');
                    var st = imgMgrState[hostId];
                    st.removed[id] = !st.removed[id];
                    th.classList.toggle('rm', !!st.removed[id]);
                    btn.title = st.removed[id] ? 'Removal pending — click to keep it' : 'Remove this image (applies when you save)';
                };
            });
        },
        removeIds: function(hostId){
            var st = imgMgrState[hostId];
            if(!st) return [];
            return Object.keys(st.removed).filter(function(k){ return st.removed[k] && k !== 'null'; });
        },
        clear: function(hostId){
            var host = document.getElementById(hostId);
            if(host){ host.innerHTML=''; host.style.display='none'; }
            delete imgMgrState[hostId];
        }
    };

    // ---------- Bank chip picker (edit surfaces) ----------
    // Same chips the payment modal records with, for RE-tagging which bank an online payment
    // left from. Selection state lives per host; value() returns the picked id (string) or null.
    // A pick can be switched but never cleared — clicking the selected chip keeps it selected,
    // matching the backend rule that an online payment always carries a bank.
    var bankPickState = {};
    window.hubBankChips = {
        render: function(hostId, selectedId){
            var host = document.getElementById(hostId);
            if(!host) return;
            bankPickState[hostId] = selectedId != null ? String(selectedId) : null;
            host.innerHTML = BANKS.map(function(b){
                var on = String(b.id) === bankPickState[hostId];
                return '<span class="bankchip'+(on?' on':'')+'" data-bank="'+b.id+'">'+(b.short_code||b.name)+'</span>';
            }).join('');
            host.querySelectorAll('.bankchip').forEach(function(chip){
                chip.onclick = function(){
                    bankPickState[hostId] = chip.getAttribute('data-bank');
                    host.querySelectorAll('.bankchip').forEach(function(c){ c.classList.remove('on'); });
                    chip.classList.add('on');
                };
            });
        },
        value: function(hostId){ return bankPickState[hostId] || null; },
        clear: function(hostId){
            var host = document.getElementById(hostId);
            if(host) host.innerHTML='';
            delete bankPickState[hostId];
        }
    };

    // ---------- Purchase (by_total) ----------
    // Optional date: the day-header ＋ buttons prefill the day they sit on.
    window.hubOpenPurchase = function(date){
        errHide('hubPuErr');
        var f=document.getElementById('hubPurchaseForm'); f.reset();
        if(date) f.transaction_date.value=date;
        open('hubPurchase');
    };
    window.hubSubmitPurchase = async function(){
        errHide('hubPuErr'); var f=document.getElementById('hubPurchaseForm');
        if(!(parseFloat(f.amount.value)>0)) return errShow('hubPuErr','Enter an amount.');
        try{
            var r = await post('/finance/vendors/'+VID+'/purchase', new FormData(f), true);
            if(r.status===422){ var j=await r.json(); return errShow('hubPuErr', Object.values(j.errors||{}).flat()[0]||'Check the form.'); }
            var j2 = await r.json().catch(function(){return{success:r.ok};});
            if(j2.success) return done('Purchase recorded');
            errShow('hubPuErr', j2.message || 'Could not record the purchase.');
        }catch(e){ errShow('hubPuErr','Network error.'); }
    };

    // ---------- Weighted purchase ----------
    var wtEditId = null; // null = recording a new purchase; an id = editing that one

    async function wtLoadProducts(){
        if(products!==null) return;
        try{ var r=await fetch('/finance/vendors/'+VID+'/products/list',{headers:{'Accept':'application/json'}}); var j=await r.json(); products=(j.products||[]); wtSupportsIng = j.supports_ingredients === true; }
        catch(e){ products=[]; }
    }
    // ❄🧂 Sep-30: under each line, what it COUNTS AS for Frozen stock — or that it will not count.
    //   The pack size is set once on the product (Products → Edit), never per purchase.
    var wtSupportsIng = false;
    function wtBaseText(q, base){
        q = Number(q) || 0;
        if(base === 'g' && q >= 1000) return (Math.round(q/10)/100) + ' kg';
        if(base === 'ml' && q >= 1000) return (Math.round(q/10)/100) + ' L';
        return (Math.round(q*1000)/1000) + ' ' + (base === 'pcs' ? 'pcs' : (base || ''));
    }
    function wtNote(row){
        var el = row.querySelector('.wt-note'); if(!el) return;
        var sel = row.querySelector('.wt-prod');
        var p = wtSupportsIng && sel.value ? (products||[]).filter(function(x){ return String(x.id) === String(sel.value); })[0] : null;
        if(!p){ el.textContent = ''; el.style.display = 'none'; return; }
        var container = p.unit_code === 'pack' || p.unit_code === 'box';
        var t;
        if(p.ingredient_id){
            t = '❄ counts towards ' + (p.ingredient_name || 'its ingredient');
            if(container && Number(p.pack_qty_base) > 0){
                t += ' · ' + wtBaseText(p.pack_qty_base, p.ingredient_base_unit) + ' in one ' + p.unit_code;
                var q = parseFloat(row.querySelector('.wt-qty').value) || 0;
                if(q > 0) t += ' = ' + wtBaseText(q * Number(p.pack_qty_base), p.ingredient_base_unit);
            }
            if(p.on_shelf_text) t += ' · 🧂 ' + p.on_shelf_text + ' on the shelf now';
            el.style.color = '#065F46';
        } else {
            // ⚠ The Hub's own Products window has no ingredient field — send them to the FULL page.
            t = '⚠ Not linked to a recipe ingredient, so this line will not count as bought. If it goes into a recipe: Products → "full page ↗" → Edit → pick the ingredient' +
                (container ? ' and type how much is in one ' + p.unit_code : '') + '.';
            el.style.color = '#B45309';
        }
        el.textContent = t; el.style.display = '';
    }
    function wtSetMode(editing){
        wtEditId = editing || null;
        document.getElementById('hubWtTitle').textContent = editing ? 'Edit purchase' : 'Purchase by weight';
        document.getElementById('hubWtSub').textContent = VNAME + (editing ? ' · editing line items' : ' · line items');
        document.getElementById('hubWtSubmit').textContent = editing ? 'Save changes' : 'Record purchase';
        // Editing shows the current images with per-image remove; picked files ADD images in
        // both modes. hubImgMgr.render() is called from hubOpenWeightedEdit once the fetch lands.
        document.getElementById('hubWtBillLabel').firstChild.textContent =
            (editing ? 'Images — ✕ removes, picking files adds ' : 'Bill images (optional — pick several) ');
        hubImgMgr.clear('hubWtImgs');
    }

    window.hubOpenWeighted = async function(date){
        errHide('hubWtErr'); document.getElementById('hubWtLines').innerHTML=''; document.getElementById('hubWtAdj').value='0';
        document.getElementById('hubWtDesc').value = 'Entry by ' + @json(auth()->user()->fullname ?? '');
        document.getElementById('hubWtBill').value = '';
        document.getElementById('hubWtDate').value = date || @json(now()->format('Y-m-d'));
        wtSetMode(null);
        open('hubWeighted');
        await wtLoadProducts();
        if(!products.length){ errShow('hubWtErr','No products for this vendor yet. Add products first (Products).'); }
        hubWtAddLine();
    };

    // Edit an existing weighted purchase IN the Hub (this used to bounce to the old page).
    // Reuses the existing /finance/ledger/transaction/{id} payload — no new endpoint.
    window.hubOpenWeightedEdit = async function(id){
        errHide('hubWtErr');
        document.getElementById('hubWtLines').innerHTML='';
        document.getElementById('hubWtBill').value = '';
        wtSetMode(id);
        open('hubWeighted');
        await wtLoadProducts();
        var t;
        try{
            var r = await fetch('/finance/ledger/transaction/'+id, {headers:{'Accept':'application/json'}});
            var j = await r.json();
            if(!j.success) throw new Error(j.message||'Could not load');
            t = j.transaction;
        }catch(e){
            errShow('hubWtErr','Could not load this purchase. Try again.');
            return;
        }

        var lines = t.line_items || [];
        if(!lines.length){
            // A by-weight vendor can still have a plain (no line items) purchase — editing THAT as a
            // line-item grid would wipe its amount. Hand it to the simple editor instead.
            hubClose('hubWeighted');
            hubOpenEditTxn({id:t.id, amount:t.amount, date:t.transaction_date, desc:t.description, label:'purchase', bill:t.bill_image||null});
            return;
        }

        document.getElementById('hubWtDate').value = t.transaction_date;
        document.getElementById('hubWtDesc').value = t.description || '';
        document.getElementById('hubWtAdj').value = t.adjustment_amount || 0;
        hubImgMgr.render('hubWtImgs', t.bill_images || []);
        lines.forEach(function(li){ hubWtAddLine(li); });
        hubWtTotal();
    };
    // $preset (an existing line being edited) may point at a product that has since been switched
    // off — it is no longer in the active list. Re-add it so editing an old purchase can never
    // silently drop or re-point a line; the row still exists in the DB so the id validates.
    function prodOptions(preset){
        var list = products.slice();
        var known = preset && preset.vendor_product_id
            && !list.some(function(p){ return String(p.id) === String(preset.vendor_product_id); });
        if(known){
            list.push({id: preset.vendor_product_id, product_name: preset.product_name + ' (inactive)',
                       unit: preset.unit, rate_per_unit: preset.rate_per_unit});
        }
        var selId = preset ? String(preset.vendor_product_id || '') : null;
        return '<option value="">— product —</option>' + list.map(function(p){
            var sel = selId !== null ? (String(p.id) === selId) : !!p.is_default;
            return '<option value="'+p.id+'" data-rate="'+p.rate_per_unit+'" data-unit="'+p.unit+'" data-name="'+String(p.product_name).replace(/"/g,'&quot;')+'" '+(sel?'selected':'')+'>'+p.product_name+' ('+p.unit+' @ '+p.rate_per_unit+')</option>';
        }).join('');
    }
    window.hubWtAddLine = function(preset){
        // Layout lives in styles.blade.php (.wt-line grid) — inline flex used to overflow the modal.
        var row = document.createElement('div'); row.className='wt-line';
        row.innerHTML =
            '<select class="wt-prod" onchange="hubWtProd(this)">'+prodOptions(preset)+'</select>'+
            '<input class="wt-qty" type="number" step="0.001" min="0.001" placeholder="qty" oninput="hubWtTotal()">'+
            '<input class="wt-rate" type="number" step="0.01" placeholder="rate" oninput="hubWtTotal()">'+
            '<span class="wt-lt num">0.00</span>'+
            '<button class="hubmodal-x" type="button" title="Remove this line" onclick="this.parentNode.remove();hubWtTotal()" style="font-size:15px;margin:0;padding:2px 4px">✕</button>'+
            '<div class="wt-note" style="grid-column:1 / -1;font-size:11.5px;margin-top:-2px;display:none"></div>';
        document.getElementById('hubWtLines').appendChild(row);
        var sel = row.querySelector('.wt-prod');
        if(preset){
            // The saved qty/rate win over the product's current default rate — an old purchase must
            // keep the price it was actually bought at.
            row.querySelector('.wt-qty').value = preset.quantity;
            row.querySelector('.wt-rate').value = preset.rate_per_unit;
        } else if(sel.value){
            hubWtProd(sel);
        }
        hubWtTotal();
    };
    window.hubWtProd = function(sel){
        var o = sel.selectedOptions[0]; var row = sel.closest('.wt-line');
        if(o && o.value){ row.querySelector('.wt-rate').value = o.dataset.rate; }
        hubWtTotal();
    };
    window.hubWtTotal = function(){
        var sum=0, n=0;
        document.querySelectorAll('#hubWtLines .wt-line').forEach(function(r){
            var q=parseFloat(r.querySelector('.wt-qty').value)||0, rate=parseFloat(r.querySelector('.wt-rate').value)||0;
            var lt=q*rate; r.querySelector('.wt-lt').textContent=fmt2(lt); sum+=lt; if(r.querySelector('.wt-prod').value&&q>0)n++;
            wtNote(r);
        });
        var adj=parseFloat(document.getElementById('hubWtAdj').value)||0;
        var grand=sum+adj;
        document.getElementById('hubWtGrand').textContent='Rs. '+fmt2(grand);
        document.getElementById('hubWtSubmit').disabled = !(grand>0 && n>0);
    };
    window.hubSubmitWeighted = async function(){
        errHide('hubWtErr');
        var fd=new FormData(); fd.append('_token',csrf);
        fd.append('transaction_date', document.getElementById('hubWtDate').value);
        fd.append('description', document.getElementById('hubWtDesc').value);
        fd.append('adjustment_amount', document.getElementById('hubWtAdj').value||'0');
        // Images: picked files ADD (recording → bill_images[], editing → new_images[]);
        // ✕-marked thumbnails from the edit view are removed on save.
        var billFiles = document.getElementById('hubWtBill').files;
        for(var bi=0; bi<billFiles.length; bi++){
            fd.append(wtEditId ? 'new_images[]' : 'bill_images[]', billFiles[bi]);
        }
        if(wtEditId){
            hubImgMgr.removeIds('hubWtImgs').forEach(function(id){ fd.append('remove_image_ids[]', id); });
        }
        var i=0, ok=false;
        document.querySelectorAll('#hubWtLines .wt-line').forEach(function(r){
            var o=r.querySelector('.wt-prod').selectedOptions[0]; var q=parseFloat(r.querySelector('.wt-qty').value)||0; var rate=parseFloat(r.querySelector('.wt-rate').value)||0;
            if(o&&o.value&&q>0&&rate>0){ ok=true;
                fd.append('items['+i+'][product_id]', o.value);
                fd.append('items['+i+'][quantity]', q);
                fd.append('items['+i+'][rate]', rate);
                fd.append('items['+i+'][unit]', o.dataset.unit||'');
                fd.append('items['+i+'][product_name]', o.dataset.name||'');
                i++;
            }
        });
        if(!ok) return errShow('hubWtErr','Add at least one product line with qty and rate.');
        // Editing posts to the SAME endpoint the old page used (updateTransaction): it swaps the
        // line items, recomputes amount = lines + adjustment, and moves balances by the difference
        // only when the row's money is actually applied.
        var url = wtEditId
            ? '/finance/vendors/transaction/'+wtEditId+'/update'
            : '/finance/vendors/'+VID+'/weighted-purchase';
        try{
            var r=await post(url, fd, true);
            if(r.status===422){ var j=await r.json(); return errShow('hubWtErr', Object.values(j.errors||{}).flat()[0]||'Check the lines.'); }
            var j2=await r.json().catch(function(){return{success:r.ok};});
            if(j2.success) return done(wtEditId ? 'Purchase updated' : 'Weighted purchase recorded');
            errShow('hubWtErr', j2.message||'Could not save.');
        }catch(e){ errShow('hubWtErr','Network error.'); }
    };

    // ---------- Payment ----------
    // Optional prefill from a day header: both dates = that day (payments key off posted_date, and
    // "pay for this day" means the money moved that day), amount = the day's still-owed net —
    // capped at the vendor's payable so the max-guard can't instantly reject it.
    window.hubOpenPayment = function(date, amt){
        errHide('hubPayErr');
        var f=document.getElementById('hubPayForm'); f.reset();
        document.getElementById('hubPayBankWrap').style.display='none'; document.getElementById('hubPayBank').value='';
        if(date){ f.transaction_date.value=date; f.posted_date.value=date; }
        var v=Math.min(parseFloat(amt)||0, PAYABLE);
        if(v>0) f.amount.value=v.toFixed(2);
        open('hubPay');
    };
    window.hubPaySrcChange = function(){
        var o=document.getElementById('hubPaySrc').selectedOptions[0];
        var isBank = o && o.dataset.cat==='bank';
        document.getElementById('hubPayBankWrap').style.display = isBank ? '' : 'none';
        if(isBank && !document.getElementById('hubPayBankChips').innerHTML){
            document.getElementById('hubPayBankChips').innerHTML = BANKS.map(function(b){
                return '<span class="bankchip" onclick="hubPayPickBank(this,'+b.id+')">'+(b.short_code||b.name)+'</span>';
            }).join('');
        }
        if(!isBank) document.getElementById('hubPayBank').value='';
    };
    window.hubPayPickBank = function(el,id){ document.querySelectorAll('#hubPayBankChips .bankchip').forEach(function(c){c.classList.remove('on');}); el.classList.add('on'); document.getElementById('hubPayBank').value=id; };
    window.hubSubmitPayment = async function(){
        errHide('hubPayErr'); var f=document.getElementById('hubPayForm'); var amt=parseFloat(f.amount.value);
        if(!(amt>0)) return errShow('hubPayErr','Enter an amount.');
        if(amt-PAYABLE>0.01) return errShow('hubPayErr','Payment cannot exceed the balance (Rs. '+fmt2(PAYABLE)+').');
        if(document.getElementById('hubPayBankWrap').style.display!=='none' && !document.getElementById('hubPayBank').value) return errShow('hubPayErr','Pick which bank this is paid from.');
        try{
            var r=await post('/finance/vendors/'+VID+'/payment', new FormData(f), true);
            if(r.status===422){ var j=await r.json(); return errShow('hubPayErr', (j.message||Object.values(j.errors||{}).flat()[0])||'Check the form.'); }
            var j2=await r.json().catch(function(){return{success:r.ok};});
            if(j2.success){
                // The payment IS recorded at this point. Everything below only
                // decides what we say next, so any failure here still ends in
                // the same reload — it can never lose the payment.
                var p=j2.bank_sms_prompt;
                if(p && p.action==='tagged') return done(p.message||'Payment recorded');
                if(p && p.action==='ask' && (p.candidates||[]).length) return hubPaySmsAsk(j2.transaction_id, p.candidates);
                return done('Payment recorded');
            }
            errShow('hubPayErr', j2.message||'Could not record the payment.');
        }catch(e){ errShow('hubPayErr','Network error.'); }
    };

    // ---------- "is this its bank SMS?" ----------
    // Swap the modal from a form into a question. The payment is already saved,
    // so every exit from here — answer, skip, or close — simply reloads.
    function hubPaySmsAsk(ledgerId, cands){
        var box=document.getElementById('hubPaySmsList');
        box.innerHTML=cands.map(function(c){
            var meta=[c.counterparty, (c.date||'')+(c.time?' '+c.time:''), c.bank, c.reference?('TID '+c.reference):'']
                     .filter(Boolean).map(smsEsc).join(' · ');
            return '<div class="smsq-row"><div class="sq-main">'+
                   '<div class="sq-amt">Rs. '+smsFmt0(c.amount)+'</div>'+
                   '<div class="sq-meta">'+meta+'</div></div>'+
                   '<button class="btn primary sq-ok" type="button" data-sms="'+c.id+'" data-led="'+ledgerId+'">Yes, that\'s it</button></div>';
        }).join('');
        box.querySelectorAll('button[data-sms]').forEach(function(b){
            b.addEventListener('click', function(){ hubPaySmsTag(b.dataset.sms, b.dataset.led, b); });
        });
        document.getElementById('hubPayForm').style.display='none';
        document.getElementById('hubPaySms').style.display='block';
        document.getElementById('hubPayFoot').style.display='none';
        document.getElementById('hubPaySmsFoot').style.display='flex';
    }

    async function hubPaySmsTag(smsId, ledgerId, btn){
        btn.disabled=true; btn.textContent='Filing…';
        try{
            // ⚠ post() sends no CSRF header — the other calls here carry it in
            // the form body via @csrf. This FormData is built by hand, so the
            // token has to be added explicitly or the request 419s.
            var fd=new FormData(); fd.append('_token',csrf);
            fd.append('sms_id',smsId); fd.append('ledger_id',ledgerId);
            var r=await post('{{ url('assistant-view/money-out/tag') }}', fd, true);
            var j=await r.json().catch(function(){return{success:false};});
            if(j.success){
                document.getElementById('hubPaySmsList').innerHTML=
                    '<div class="smsq-done">'+smsEsc(j.message||'Filed against this entry.')+'</div>';
                return setTimeout(function(){ location.reload(); }, 900);
            }
            btn.disabled=false; btn.textContent="Yes, that's it";
            errShow('hubPayErr', j.message||'Could not file that bank SMS.');
        }catch(e){
            btn.disabled=false; btn.textContent="Yes, that's it";
            errShow('hubPayErr','Network error — the payment is recorded; the SMS is still in your money box.');
        }
    }

    window.hubPaySmsDone = function(){ done('Payment recorded'); };

    // ---------- Report ----------
    var reportData = null;
    window.hubOpenReport = function(){ errHide('hubRepErr'); document.getElementById('hubRepOut').innerHTML=''; document.getElementById('hubRepPrint').style.display='none'; document.getElementById('hubRepCsv').style.display='none'; open('hubReport'); };
    window.hubRunReport = async function(){
        errHide('hubRepErr');
        var from=document.getElementById('hubRepFrom').value, to=document.getElementById('hubRepTo').value;
        if(!from||!to) return errShow('hubRepErr','Pick both dates.');
        var sp=document.getElementById('hubRepPay').checked?'1':'0';
        try{
            var r=await fetch('/finance/vendors/report?vendor_id='+VID+'&date_from='+from+'&date_to='+to+'&show_payments='+sp, {headers:{'Accept':'application/json'}});
            var j=await r.json();
            if(!j.success) return errShow('hubRepErr', j.message||'Could not generate.');
            reportData=j.report; hubRenderReport(j.report);
            // Nothing in the range = nothing to print or export; leave both hidden.
            var has = !!reportHtml;
            document.getElementById('hubRepPrint').style.display = has ? '' : 'none';
            document.getElementById('hubRepCsv').style.display = has ? '' : 'none';
        }catch(e){ errShow('hubRepErr','Network error.'); }
    };
    // Aug-2026: this used to be its own thin renderer, which is why the hub's print
    // came out as unstyled text while the old vendor page printed a proper statement.
    // Both now build the SAME markup via nfVendorReportHtml() (fin/partials/vendor-
    // report-render), which is self-contained — it carries its own CSS, so it survives
    // being written into a blank popup window that has none of this page's styles.
    var reportHtml = '';
    function hubRenderReport(rep){
        reportHtml = window.nfVendorReportHtml(rep, {
            vendorName: VNAME,
            showPayments: document.getElementById('hubRepPay').checked
        });
        var out = document.getElementById('hubRepOut');
        if(!reportHtml){ out.innerHTML = '<div class="empty">No activity in this range.</div>'; return; }
        out.innerHTML = reportHtml;
    }
    window.hubReportPrint = function(){
        if(!reportHtml) return;
        window.nfVendorReportPrint(reportHtml, VNAME + ' report');
    };
    window.hubReportCsv = function(){
        if(!reportData) return; var v=(reportData.vendors||[])[0]; if(!v) return;
        var rows=[['Date','Type','Description','Amount','Mode']];
        (v.daily_summary||[]).forEach(function(d){ (d.transactions||[]).forEach(function(t){ rows.push([d.date, t.type, (t.description||'').replace(/,/g,' '), t.amount, t.payment_mode||'']); }); });
        rows.push([]); rows.push(['Total purchases', v.total_purchases]); rows.push(['Total payments', v.total_payments]); rows.push(['Balance', v.current_balance]);
        var csv=rows.map(function(r){return r.join(',');}).join('\n');
        var a=document.createElement('a'); a.href='data:text/csv;charset=utf-8,'+encodeURIComponent(csv); a.download=VNAME.replace(/\s+/g,'_')+'_report.csv'; a.click();
    };

    // ---------- Edit / delete transaction ----------
    window.hubOpenEditTxn = function(t){
        errHide('hubEtErr');
        document.getElementById('hubEtId').value=t.id;
        document.getElementById('hubEtAmount').value=t.amount;
        document.getElementById('hubEtDate').value=t.date;
        document.getElementById('hubEtDesc').value=t.desc||'';
        document.getElementById('hubEtTitle').textContent='Edit '+t.label;
        document.getElementById('hubEtBill').value='';
        hubImgMgr.clear('hubEtImgs');
        hubBankChips.clear('hubEtBankChips');
        document.getElementById('hubEtBankWrap').style.display='none';
        open('hubEditTxn');
        // Current images + bank tag arrive async (images carry the ids removal needs; the bank
        // row only shows for ONLINE payments). A failed fetch leaves both off — the base edit
        // still works, per-image removal and re-tagging simply aren't offered.
        fetch('/finance/ledger/transaction/'+t.id, {headers:{'Accept':'application/json'}})
            .then(function(r){ return r.json(); })
            .then(function(j){
                // Guard against the response landing after the user moved to ANOTHER row's edit.
                if(String(document.getElementById('hubEtId').value) !== String(t.id)) return;
                if(!(j && j.success && j.transaction)) return;
                var tx = j.transaction;
                hubImgMgr.render('hubEtImgs', tx.bill_images || []);
                if(tx.is_vendor_payment && (tx.mode === 'online' || tx.receiving_account_id) && BANKS.length){
                    hubBankChips.render('hubEtBankChips', tx.receiving_account_id);
                    document.getElementById('hubEtBankWrap').style.display='';
                }
            })
            .catch(function(){});
    };
    // ⭐ ONE save path for a SIMPLE (no line items) vendor transaction. The Edit modal and the
    // drawer's quick edit both go through this, so validation, the endpoint and the guards can
    // never drift apart between the two surfaces.
    //   fields: {id, transaction_date, amount, description,
    //            newFiles: FileList|array (ADD these images),
    //            removeImageIds: array    (take these off the row),
    //            billFile: File|null      (legacy single REPLACE — old callers only)}
    // Returns {ok:true} or {ok:false, message} — the caller decides where to show the message.
    //
    // ⚠ Never call this for a purchase that HAS line items: updateTransaction only recomputes the
    // amount from items when items[] is posted, so a bare amount here would detach the stored
    // total from its lines. Callers must route those to hubOpenWeightedEdit instead.
    window.hubSaveVendorTxn = async function(fields){
        if(!fields.transaction_date) return {ok:false, message:'Pick a date.'};
        // Guarded on the client too, so a cleared box reads as an error rather than a 500: an
        // empty amount reaches PHP as NULL and the column is NOT NULL.
        if(!(parseFloat(fields.amount) > 0)) return {ok:false, message:'Enter an amount greater than zero.'};

        var fd=new FormData(); fd.append('_token',csrf);
        fd.append('transaction_date', fields.transaction_date);
        fd.append('amount', fields.amount);
        // Always sent, and every caller must PREFILL it: updateTransaction overwrites the
        // description whenever the key is present, so posting a blank one wipes what was there.
        fd.append('description', fields.description == null ? '' : fields.description);
        var nf = fields.newFiles || [];
        for(var i=0;i<nf.length;i++){ fd.append('new_images[]', nf[i]); }
        (fields.removeImageIds || []).forEach(function(id){ fd.append('remove_image_ids[]', id); });
        // Legacy single replacement — updateTransaction swaps the FIRST image for this file.
        if(fields.billFile) fd.append('bill_image', fields.billFile);
        // Bank re-tag (ONLINE payments only — callers only set this when chips were offered).
        // The backend ignores it on purchases/cash rows and swaps the description's bank token.
        if(fields.receivingAccountId) fd.append('receiving_account_id', fields.receivingAccountId);
        try{
            var r=await post('/finance/vendors/transaction/'+fields.id+'/update', fd, true);
            if(r.status===422){ var j=await r.json(); return {ok:false, message: Object.values(j.errors||{}).flat()[0]||'Check the form.'}; }
            var j2=await r.json();
            return j2.success ? {ok:true} : {ok:false, message: j2.message||'Could not update.'};
        }catch(e){ return {ok:false, message:'Network error.'}; }
    };

    window.hubSubmitEditTxn = async function(){
        errHide('hubEtErr');
        var res = await window.hubSaveVendorTxn({
            id: document.getElementById('hubEtId').value,
            transaction_date: document.getElementById('hubEtDate').value,
            amount: document.getElementById('hubEtAmount').value,
            description: document.getElementById('hubEtDesc').value,
            newFiles: document.getElementById('hubEtBill').files,
            removeImageIds: hubImgMgr.removeIds('hubEtImgs'),
            receivingAccountId: hubBankChips.value('hubEtBankChips'),
        });
        if(res.ok) return done('Transaction updated');
        errShow('hubEtErr', res.message);
    };
    window.hubDeleteTxn = async function(id){
        if(!confirm('Delete this transaction?\n\nIt reverses the balances and removes the row. This cannot be undone.')) return;
        try{
            var r=await post('/finance/vendors/transaction/'+id+'/delete', (function(){var f=new FormData();f.append('_token',csrf);return f;})(), true);
            var j=await r.json(); if(j.success) return done('Transaction deleted');
            alert(j.message||'Could not delete.');
        }catch(e){ alert('Network error.'); }
    };
    // Enter anywhere in the ⚖ modal's inputs posts the purchase — typing a qty and hitting Enter is
    // the whole flow. Tab is untouched (it still walks to the next field/line). Deliberately NOT
    // bound to the product <select> (Enter there belongs to the dropdown) or to buttons, and it
    // no-ops while the submit is disabled (nothing entered yet).
    document.getElementById('hubWeighted').addEventListener('keydown', function(e){
        if(e.key !== 'Enter' || e.shiftKey) return;
        var t = e.target;
        if(!t || t.tagName !== 'INPUT' || t.type === 'file') return;
        e.preventDefault();
        if(!document.getElementById('hubWtSubmit').disabled) hubSubmitWeighted();
    });

    document.addEventListener('keydown', function(e){ if(e.key==='Escape'){ ['hubPurchase','hubWeighted','hubPay','hubReport','hubEditTxn'].forEach(function(id){ document.getElementById(id).classList.remove('on'); }); } });
})();
</script>
