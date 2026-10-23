{{--
    ✂ SPLIT INVOICE (Oct-2026).

    Tick the items that can't go with this invoice → they MOVE to a new invoice
    <number>-A (then -B, -C …). Nothing is deleted.

    ⚠ This markup only asks; OrderSplitService decides. Every tick re-asks the
    server for both totals (GET /orders/{id}/split/preview) — the page never does
    the arithmetic itself, so web and phone can never disagree — and the final
    POST re-checks everything (an order dispatched while this was open is refused).
    The phone's store card calls the SAME controller via /api/rider/orders/{id}/split.

    ⚠⚠ OWN SCOPED CSS, NO TAILWIND (same lesson as return-modal.blade.php: the
    loaded stylesheet is Metronic — fixed/inset/hidden utility classes do nothing).

    Included unconditionally; the ✂ button only renders when the server said this
    user holds split_orders, and the server checks it again on every call.
--}}
@php
    $nfSplitSvc = app(\App\Services\CRM\OrderSplitService::class);
    $nfCanSplit = auth()->check() && $nfSplitSvc->userCanSplit(auth()->user()) && \App\Services\CRM\OrderSplitService::isReady();
@endphp
<style>
#nfsScrim{position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:10000;display:none}
#nfsScrim.nfs-on{display:block}
#nfsBox{position:fixed;z-index:10001;left:50%;top:50%;transform:translate(-50%,-50%);
  width:min(720px,calc(100vw - 32px));max-height:calc(100vh - 32px);background:#fff;border-radius:14px;
  box-shadow:0 24px 60px rgba(15,23,42,.3);font-family:inherit;display:none;overflow:hidden;
  flex-direction:column;color:#0f172a}
#nfsBox.nfs-on{display:flex}
.nfs-head{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #eef2f7}
.nfs-title{font-size:15px;font-weight:800;margin:0}
.nfs-x{background:none;border:0;font-size:24px;line-height:1;color:#94a3b8;cursor:pointer;padding:0 4px}
.nfs-x:hover{color:#0f172a}
.nfs-body{padding:14px 18px;overflow-y:auto;flex:1}
.nfs-loading{padding:34px 18px;text-align:center;color:#64748b;font-size:13px}
.nfs-blocked{margin:16px 18px;border:1px solid #fcd34d;background:#fffbeb;color:#92400e;border-radius:10px;padding:12px 14px;font-size:13px}
.nfs-order{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:9px 12px;font-size:13px;margin-bottom:12px}
.nfs-order small{display:block;color:#64748b;font-size:11.5px;margin-top:2px}
.nfs-q{font-size:13px;font-weight:700;color:#334155;margin:0 0 6px}
.nfs-line{display:flex;gap:10px;align-items:center;border:1px solid #e2e8f0;border-radius:9px;padding:8px 11px;margin:5px 0;cursor:pointer;background:#fff;font-size:13px}
.nfs-line:hover{background:#f8fafc}
.nfs-line.nfs-sel{border-color:#d97706;background:#fffbeb}
.nfs-line input{flex:none;width:16px;height:16px}
.nfs-line-name{flex:1;min-width:0}
.nfs-line-name small{display:block;color:#64748b;font-size:11.5px}
.nfs-line-amt{font-weight:700;white-space:nowrap}
.nfs-tag{display:inline-block;margin-left:6px;font-size:10px;font-weight:800;border-radius:5px;padding:1px 6px;vertical-align:middle}
.nfs-tag-prep{color:#166534;background:#dcfce7}
.nfs-tag-free{color:#1e40af;background:#dbeafe}
.nfs-sum{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:14px 0 4px}
.nfs-card{border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;font-size:12.5px}
.nfs-card b{display:block;font-size:13.5px;margin-bottom:3px}
.nfs-card .nfs-total{font-size:16px;font-weight:800;margin-top:4px}
.nfs-card.nfs-new{border-color:#fcd34d;background:#fffbeb}
.nfs-hint{font-size:12.5px;color:#92400e;margin-top:8px}
.nfs-proof{margin-top:14px;border:1px solid #c7d2fe;background:#eef2ff;border-radius:10px;padding:10px 12px;font-size:13px;color:#312e81}
.nfs-opt{display:flex;gap:8px;align-items:flex-start;margin-top:8px;cursor:pointer}
.nfs-opt input{margin-top:3px}
.nfs-note{font-size:12px;color:#64748b;margin-top:10px}
.nfs-err{border:1px solid #fecaca;background:#fef2f2;color:#b91c1c;border-radius:10px;padding:9px 12px;font-size:13px;margin-top:10px}
.nfs-foot{display:flex;align-items:center;justify-content:flex-end;gap:10px;padding:12px 18px;border-top:1px solid #eef2f7;background:#f8fafc}
.nfs-foot small{font-size:11.5px;color:#64748b;flex:1}
.nfs-btn{border:1px solid #cbd5e1;background:#fff;color:#334155;border-radius:8px;padding:9px 14px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit}
.nfs-btn:hover{background:#f1f5f9}
.nfs-btn.nfs-go{background:#d97706;border-color:#d97706;color:#fff}
.nfs-btn.nfs-go:hover{background:#b45309}
.nfs-btn:disabled{opacity:.5;cursor:default}
.nfs-hide{display:none !important}
@media (max-width:560px){.nfs-sum{grid-template-columns:1fr}}
</style>

<div id="nfsScrim" onclick="nfCloseSplitForm()"></div>
<div id="nfsBox" role="dialog" aria-modal="true" aria-labelledby="nfsTitle">
    <div class="nfs-head">
        <h3 class="nfs-title" id="nfsTitle">✂ Split invoice</h3>
        <button type="button" class="nfs-x" onclick="nfCloseSplitForm()" aria-label="Close">&times;</button>
    </div>

    <div id="nfSplitLoading" class="nfs-loading">Reading this invoice…</div>

    <div id="nfSplitBlocked" class="nfs-hide">
        <div class="nfs-blocked" id="nfSplitBlockedText"></div>
        <div class="nfs-foot"><button type="button" class="nfs-btn" onclick="nfCloseSplitForm()">Close</button></div>
    </div>

    <div id="nfSplitForm" class="nfs-hide" style="display:flex;flex-direction:column;min-height:0">
        <div class="nfs-body">
            <div class="nfs-order">
                <b id="nfSplitOrderLine"></b>
                <small id="nfSplitSubLine"></small>
            </div>

            <p class="nfs-q">Tick the items that can't go with this invoice</p>
            <div id="nfSplitLines"></div>

            <div class="nfs-sum">
                <div class="nfs-card">
                    <b id="nfSplitOrigTitle"></b>
                    <div id="nfSplitOrigDetail"></div>
                    <div class="nfs-total" id="nfSplitOrigTotal">—</div>
                </div>
                <div class="nfs-card nfs-new">
                    <b id="nfSplitNewTitle"></b>
                    <div id="nfSplitNewDetail"></div>
                    <div class="nfs-total" id="nfSplitNewTotal">—</div>
                </div>
            </div>
            <div id="nfSplitHint" class="nfs-hint nfs-hide"></div>

            <div id="nfSplitProof" class="nfs-proof nfs-hide">
                <div id="nfSplitProofText"></div>
                <label class="nfs-opt"><input type="radio" name="nfSplitPaid" value="yes"> <span id="nfSplitPaidYes"></span></label>
                <label class="nfs-opt"><input type="radio" name="nfSplitPaid" value="no"> <span id="nfSplitPaidNo"></span></label>
            </div>

            <div id="nfSplitPackets" class="nfs-note nfs-hide"></div>
            <div id="nfSplitError" class="nfs-err nfs-hide"></div>
        </div>

        <div class="nfs-foot">
            <small>Moved items keep their prep and scans. Delivery charge, discounts and tip stay on the original.</small>
            <button type="button" class="nfs-btn" onclick="nfCloseSplitForm()">Cancel</button>
            <button type="button" id="nfSplitSubmit" class="nfs-btn nfs-go" onclick="nfSubmitSplit()" disabled>Split invoice</button>
        </div>
    </div>
</div>

<script>
window.nfCanSplitOrders = @json($nfCanSplit);
window.nfSplitOpenStatuses = @json(\App\Services\CRM\OrderSplitService::OPEN_STATUSES);
(function () {
    let state = null;        // latest server preview
    let orderId = null;
    let selected = new Set();
    let seq = 0;             // ignore out-of-order preview replies
    let timer = null;
    let busy = false;

    const $ = (id) => document.getElementById(id);
    const show = (id, on) => $(id).classList.toggle('nfs-hide', !on);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const money = (n) => 'Rs ' + Number(n || 0).toLocaleString('en-PK', { maximumFractionDigits: 2 });
    const qty = (n) => Number(n || 0).toLocaleString('en-PK', { maximumFractionDigits: 3 });

    // Split only ever applies to LIVE orders (t_crm_prod_order). Staging ids
    // collide with live ids — the button never renders on the Shopify tab, and
    // this makes that a rule, not a hope.
    function liveOnly() {
        const src = (typeof currentOrderSource === 'function') ? currentOrderSource() : (window.currentSource || '');
        return String(src || '').toLowerCase() !== 'shopify';
    }

    /** Row action button — only for users who may split, on still-open orders. */
    window.nfSplitButtonHtml = function (order) {
        if (!window.nfCanSplitOrders || !order || !liveOnly()) return '';
        if (!(window.nfSplitOpenStatuses || []).includes(String(order.order_status || ''))) return '';
        return `<button onclick="event.stopPropagation(); nfOpenSplitForm(${Number(order.id)})" class="inline-flex items-center justify-center w-7 h-7 rounded transition-all duration-200" style="color:#b45309;background:#fffbeb;border:1px solid #fcd34d;" title="Split invoice — move items that can't go to a new invoice">✂</button>`;
    };

    /** Chip under the order number: "✂ from SH-23808" / "✂ → SH-23808-A". */
    window.nfSplitChipHtml = function (order) {
        if (!order) return '';
        const chip = (txt, tip) => `<div title="${esc(tip)}" style="display:inline-block;margin-top:2px;padding:1px 6px;border-radius:8px;font-size:10px;font-weight:700;color:#92400e;background:#fffbeb;border:1px solid #fcd34d;white-space:nowrap;">${esc(txt)}</div>`;
        if (order.split_from) return chip('✂ from ' + order.split_from, 'Split from ' + order.split_from);
        if (Array.isArray(order.split_into) && order.split_into.length) {
            return chip('✂ → ' + order.split_into.join(', '), 'Items moved to ' + order.split_into.join(', '));
        }
        return '';
    };

    window.nfOpenSplitForm = async function (id) {
        if (!liveOnly()) { alert('Split works on live invoices. Open the order from the Open Orders tab.'); return; }
        orderId = Number(id);
        selected = new Set();
        state = null;
        $('nfsScrim').classList.add('nfs-on');
        $('nfsBox').classList.add('nfs-on');
        show('nfSplitLoading', true); show('nfSplitForm', false); show('nfSplitBlocked', false); show('nfSplitError', false);

        const j = await fetchPreview();
        show('nfSplitLoading', false);
        if (!j) return;
        if (!j.success || j.eligible === false) {
            $('nfSplitBlockedText').textContent = j.blocked || j.message || 'This invoice cannot be split.';
            show('nfSplitBlocked', true);
            return;
        }
        state = j;
        renderStatic();
        renderPreview();
        show('nfSplitForm', true);
    };

    window.nfCloseSplitForm = function () {
        $('nfsScrim').classList.remove('nfs-on');
        $('nfsBox').classList.remove('nfs-on');
        state = null; orderId = null;
    };

    async function fetchPreview() {
        const my = ++seq;
        try {
            const ids = Array.from(selected).join(',');
            const r = await fetch(`/orders/${orderId}/split/preview?line_ids=${encodeURIComponent(ids)}`, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
            const j = await r.json();
            if (my !== seq) return null;             // a newer tick already asked
            if (r.status === 403 || r.status === 404) {
                show('nfSplitLoading', false);
                $('nfSplitBlockedText').textContent = j.message || 'Not allowed.';
                show('nfSplitForm', false); show('nfSplitBlocked', true);
                return null;
            }
            return j;
        } catch (e) {
            if (my !== seq) return null;
            show('nfSplitLoading', false);
            $('nfSplitBlockedText').textContent = 'Could not read this invoice: ' + e.message;
            show('nfSplitForm', false); show('nfSplitBlocked', true);
            return null;
        }
    }

    function renderStatic() {
        $('nfSplitOrderLine').textContent = `${state.order_number} — ${state.customer_name} — ${money(state.current_total)}`;
        $('nfSplitSubLine').textContent = `Ticked items move to a new invoice ${state.new_order_number}. Nothing is deleted.`;
        $('nfSplitLines').innerHTML = (state.lines || []).map(l => `
            <label class="nfs-line" data-line="${l.id}">
                <input type="checkbox" value="${l.id}" onchange="nfSplitToggle(this)">
                <span class="nfs-line-name">${esc(l.name)}${l.prepared ? '<span class="nfs-tag nfs-tag-prep">prepared</span>' : ''}${l.is_free ? '<span class="nfs-tag nfs-tag-free">FREE</span>' : ''}
                    <small>Qty ${qty(l.quantity)}${l.sku ? ' · ' + esc(l.sku) : ''}</small></span>
                <span class="nfs-line-amt">${l.is_free ? 'Free' : money(l.line_total)}</span>
            </label>`).join('');

        // Proof question — only when the original already carries a payment proof.
        const p = state.proof;
        document.querySelectorAll('input[name="nfSplitPaid"]').forEach(r => { r.checked = false; r.onchange = renderPreview; });
        if (p) {
            $('nfSplitProofText').innerHTML = `<b>${esc(state.order_number)} already has a payment</b> — ${esc(p.label)}${p.amount ? ' (' + money(p.amount) + ')' : ''}. Are the items you are moving paid by the same payment?`;
            $('nfSplitPaidYes').textContent = `Yes — ${state.new_order_number} is paid too (it gets the same proof)`;
            $('nfSplitPaidNo').textContent = `No — the customer will pay for ${state.new_order_number} separately`;
        }
        show('nfSplitProof', !!p);

        if (state.packets_reset) {
            $('nfSplitPackets').textContent = `📦 The packet count (${state.packets_reset}) on ${state.order_number} will be reset — recount and reprint labels for both invoices.`;
        }
        show('nfSplitPackets', !!state.packets_reset);
    }

    function renderPreview() {
        if (!state) return;
        const o = state.original, n = state.new;
        $('nfSplitOrigTitle').textContent = `Stays on ${state.order_number}`;
        $('nfSplitNewTitle').textContent = `Moves to ${state.new_order_number}`;
        if (state.ready && o && n) {
            const extras = [];
            if (o.shipping > 0) extras.push('delivery ' + money(o.shipping));
            if (o.discount > 0) extras.push('discount −' + money(o.discount));
            if (o.tip > 0) extras.push('tip ' + money(o.tip));
            $('nfSplitOrigDetail').textContent = `${o.items_count} item${o.items_count === 1 ? '' : 's'}${extras.length ? ' · ' + extras.join(' · ') : ''}`;
            $('nfSplitOrigTotal').textContent = money(o.total);
            $('nfSplitNewDetail').textContent = `${n.items_count} item${n.items_count === 1 ? '' : 's'} · no delivery charge`;
            $('nfSplitNewTotal').textContent = money(n.total);
        } else {
            $('nfSplitOrigDetail').textContent = ''; $('nfSplitOrigTotal').textContent = '—';
            $('nfSplitNewDetail').textContent = ''; $('nfSplitNewTotal').textContent = '—';
        }
        $('nfSplitHint').textContent = state.hint || '';
        show('nfSplitHint', !!state.hint);

        const needsAnswer = !!state.proof && !document.querySelector('input[name="nfSplitPaid"]:checked');
        $('nfSplitSubmit').disabled = !state.ready || needsAnswer || busy;
    }

    window.nfSplitToggle = function (cb) {
        const id = Number(cb.value);
        if (cb.checked) selected.add(id); else selected.delete(id);
        cb.closest('.nfs-line').classList.toggle('nfs-sel', cb.checked);
        $('nfSplitSubmit').disabled = true;     // until the server has re-priced
        clearTimeout(timer);
        timer = setTimeout(async () => {
            const j = await fetchPreview();
            if (!j || !state) return;
            if (!j.success || j.eligible === false) {
                $('nfSplitBlockedText').textContent = j.blocked || j.message || 'This invoice cannot be split any more.';
                show('nfSplitForm', false); show('nfSplitBlocked', true);
                return;
            }
            const keep = { proof: state.proof };
            state = Object.assign(j, keep);
            renderPreview();
        }, 220);
    };

    window.nfSubmitSplit = async function () {
        if (!state || busy || !state.ready) return;
        const paidEl = document.querySelector('input[name="nfSplitPaid"]:checked');
        if (state.proof && !paidEl) { showError('Say whether the moved items are paid by the same payment.'); return; }

        busy = true;
        $('nfSplitSubmit').disabled = true;
        $('nfSplitSubmit').textContent = 'Splitting…';
        show('nfSplitError', false);
        try {
            const r = await fetch(`/orders/${orderId}/split`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: JSON.stringify({
                    line_ids: Array.from(selected),
                    split_part_paid: state.proof ? (paidEl.value === 'yes') : null
                })
            });
            const j = await r.json();
            if (!j.success) { showError(j.message || 'The split could not be saved.'); return; }

            nfCloseSplitForm();
            let msg = j.message;
            if (j.packets_reset) msg += ` Packet count on ${j.original.order_number} was reset — recount and reprint labels.`;
            if (typeof showNotification === 'function') showNotification(msg, 'success'); else alert(msg);
            if (typeof loadOrders === 'function') loadOrders(); else window.location.reload();
        } catch (e) {
            showError('Something went wrong: ' + e.message);
        } finally {
            busy = false;
            $('nfSplitSubmit').textContent = 'Split invoice';
            renderPreview();
        }
    };

    function showError(msg) { $('nfSplitError').textContent = msg; show('nfSplitError', true); }
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && $('nfsBox').classList.contains('nfs-on')) nfCloseSplitForm(); });
})();
</script>
