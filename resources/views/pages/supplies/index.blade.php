@extends('layouts.app')

@section('title', '📦 Storage')

@php
    // Trim trailing zeros: 5.970 -> 5.97, 3.000 -> 3
    $supFmtQty = function ($qty) {
        $s = number_format((float) $qty, 3, '.', '');
        return rtrim(rtrim($s, '0'), '.');
    };
@endphp

@push('custom_css')
<style>
/* ⚠ Every class here is prefixed sup- on purpose. A bare name like .card/.fixed/.active
   collides with the global Metronic/Tailwind sheet, which is loaded BEFORE this block —
   an unprefixed rule inherits whatever that sheet declares (position, display, colour)
   and the page breaks in ways that look unrelated. Verified: 0 matches for ".sup-". */
.sup-wrap { padding: 24px; }
.sup-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:18px; }
.sup-h1 { font-size:22px; font-weight:700; color:#111827; margin:0; }
.sup-sub { font-size:13px; color:#6B7280; margin-top:2px; }
.sup-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.sup-btn { padding:9px 16px; font-size:13px; font-weight:600; border-radius:10px; border:1px solid #D1D5DB;
           background:#fff; color:#374151; cursor:pointer; transition:background .15s; }
.sup-btn:hover { background:#F9FAFB; }
.sup-btn-main { background:#0EA5E9; border-color:#0EA5E9; color:#fff; }
.sup-btn-main:hover { background:#0284C7; }
.sup-btn-danger { color:#B91C1C; border-color:#FCA5A5; }
.sup-btn-danger:hover { background:#FEF2F2; }
.sup-btn:disabled { opacity:.5; cursor:not-allowed; }

.sup-tabs { display:flex; gap:4px; border-bottom:1px solid #E5E7EB; margin-bottom:18px; }
.sup-tab { padding:9px 16px; font-size:13px; font-weight:600; color:#6B7280; cursor:pointer;
           border:0; background:none; border-bottom:2px solid transparent; }
.sup-tab-on { color:#0369A1; border-bottom-color:#0EA5E9; }

.sup-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:14px; }
.sup-card { border:1px solid #E5E7EB; border-radius:14px; padding:16px; background:#fff; }
.sup-card-top { display:flex; align-items:flex-start; justify-content:space-between; gap:8px; }
.sup-pname { font-size:15px; font-weight:700; color:#111827; }
.sup-pmeta { font-size:11px; color:#6B7280; margin-top:2px; }
.sup-qty { font-size:26px; font-weight:700; color:#0F172A; margin:10px 0 2px; }
.sup-val { font-size:13px; color:#6B7280; }
.sup-pool-toggle { margin-top:6px; padding:0; background:none; border:0; font-size:12px; font-weight:600;
                   color:#0EA5E9; cursor:pointer; }
.sup-pool-toggle:hover { text-decoration:underline; }
.sup-pool-detail { margin-top:6px; border-top:1px dashed #E5E7EB; padding-top:6px; font-size:12px; color:#4B5563; }
.sup-pool-row { display:flex; justify-content:space-between; gap:8px; padding:3px 0; }
.sup-pool-next { color:#065F46; font-weight:600; }
.sup-chip { display:inline-block; padding:2px 8px; font-size:11px; font-weight:600; border-radius:999px; }
.sup-chip-low { background:#FEF3C7; color:#92400E; }
.sup-chip-mode { background:#F1F5F9; color:#475569; }
.sup-chip-warn { background:#FEE2E2; color:#991B1B; }
.sup-card-btns { display:flex; gap:8px; margin-top:12px; }
.sup-card-btns .sup-btn { flex:1; text-align:center; }

.sup-tablewrap { overflow-x:auto; border:1px solid #E5E7EB; border-radius:12px; background:#fff; }
.sup-table { width:100%; border-collapse:collapse; font-size:13px; min-width:640px; }
.sup-table th { text-align:left; padding:10px 12px; background:#F9FAFB; color:#6B7280;
                font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:.03em;
                border-bottom:1px solid #E5E7EB; white-space:nowrap; }
.sup-table td { padding:10px 12px; border-bottom:1px solid #F3F4F6; color:#374151; vertical-align:top; }
.sup-table tr:last-child td { border-bottom:0; }
.sup-money { font-variant-numeric:tabular-nums; white-space:nowrap; }

.sup-empty { padding:36px; text-align:center; color:#6B7280; font-size:13px;
             border:1px dashed #E5E7EB; border-radius:12px; background:#fff; }

.sup-modal { position:fixed; inset:0; background:rgba(15,23,42,.45); display:none;
             align-items:flex-start; justify-content:center; z-index:1080; padding:24px 16px; overflow-y:auto; }
.sup-modal-on { display:flex; }
.sup-box { background:#fff; border-radius:16px; width:100%; max-width:560px; padding:22px;
           box-shadow:0 20px 45px rgba(0,0,0,.22); }
.sup-box-title { font-size:17px; font-weight:700; color:#111827; margin-bottom:14px; }
.sup-field { margin-bottom:12px; }
.sup-label { display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:5px; }
.sup-hint { font-size:11px; color:#6B7280; margin-top:4px; }
.sup-input, .sup-select { width:100%; padding:9px 11px; font-size:14px; border:1px solid #D1D5DB;
                          border-radius:9px; color:#111827; background:#fff; }
.sup-input:focus, .sup-select:focus { outline:2px solid #BAE6FD; border-color:#0EA5E9; }
.sup-two { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.sup-staged { border:1px solid #E5E7EB; border-radius:10px; max-height:190px; overflow-y:auto; }
.sup-staged-row { display:flex; align-items:center; justify-content:space-between; gap:8px;
                  padding:7px 11px; font-size:13px; border-bottom:1px solid #F3F4F6; }
.sup-staged-row:last-child { border-bottom:0; }
.sup-x { border:0; background:none; color:#B91C1C; cursor:pointer; font-size:15px; line-height:1; padding:0 4px; }
.sup-total { display:flex; justify-content:space-between; font-size:13px; font-weight:700;
             color:#0F172A; padding:9px 11px; background:#F8FAFC; border-radius:9px; margin-top:8px; }
.sup-msg { font-size:12.5px; padding:9px 11px; border-radius:9px; margin-bottom:12px; display:none; }
.sup-msg-err { background:#FEF2F2; color:#991B1B; border:1px solid #FECACA; }
.sup-msg-ok { background:#F0FDF4; color:#166534; border:1px solid #BBF7D0; }
.sup-modal-btns { display:flex; gap:9px; justify-content:flex-end; margin-top:16px; }
.sup-none { display:none !important; }
.sup-switch { display:flex; align-items:center; gap:7px; font-size:12px; color:#374151;
              padding:7px 12px; border:1px solid #E5E7EB; border-radius:10px; background:#fff; }
</style>
@endpush

@section('content')
<div class="sup-wrap"
     id="supRoot"
     data-can-manage="{{ $canManage ? '1' : '0' }}"
     data-needs-approval="{{ $needsApproval ? '1' : '0' }}">

    <div class="sup-head">
        <div>
            <h1 class="sup-h1">📦 Storage</h1>
            <p class="sup-sub">
                Packaging bought in bulk. The cost is charged to expenses one packet at a time,
                on the day it is used — not all at once when it is bought.
            </p>
        </div>
        <div class="sup-actions">
            @if($pendingCount > 0)
                <span class="sup-chip sup-chip-warn">{{ $pendingCount }} take-out(s) awaiting approval</span>
            @endif
            @if($canManage)
                <label class="sup-switch" title="When off, a take-out is booked to expenses immediately with no approval step.">
                    <input type="checkbox" id="supApprovalSwitch" {{ $needsApproval ? 'checked' : '' }}>
                    Take-outs need approval
                </label>
                <button class="sup-btn" onclick="supOpenProduct()">＋ Product</button>
                <button class="sup-btn sup-btn-main" onclick="supOpenBookIn()">＋ Add stock</button>
            @endif
        </div>
    </div>

    <div class="sup-tabs">
        <button class="sup-tab sup-tab-on" id="supTabStock" onclick="supShowTab('stock')">Stock</button>
        <button class="sup-tab" id="supTabBatches" onclick="supShowTab('batches')">Purchases</button>
        <button class="sup-tab" id="supTabTakeouts" onclick="supShowTab('takeouts')">Take-outs</button>
        <button class="sup-tab" id="supTabHistory" onclick="supShowTab('history')">History</button>
    </div>

    {{-- STOCK --}}
    <div id="supPaneStock">
        @if(count($stock) === 0)
            <div class="sup-empty">
                No storage products yet.
                @if($canManage) Use <strong>＋ Product</strong> to add one (e.g. Packaging - Bags). @else Ask Taimur or Shabib to add one. @endif
            </div>
        @else
            <div class="sup-cards">
                @foreach($stock as $row)
                    @php $p = $row['product']; @endphp
                    <div class="sup-card">
                        <div class="sup-card-top">
                            <div>
                                <div class="sup-pname">{{ $p->name }}</div>
                                <div class="sup-pmeta">
                                    charged to {{ $p->expense_category_name }}
                                </div>
                            </div>
                            <span class="sup-chip sup-chip-mode">
                                {{ $p->mode === 'weight' ? 'by weight' : ($p->mode === 'scan' ? 'by packet' : 'by piece') }}
                            </span>
                        </div>

                        <div class="sup-qty">
                            {{ $supFmtQty($row['qty_remaining']) }}
                            <span style="font-size:14px;font-weight:600;color:#6B7280;">
                                {{ $p->mode === 'weight' ? 'kg' : ($p->mode === 'scan' ? 'packets' : 'pcs') }}
                            </span>
                        </div>
                        <div class="sup-val">
                            Rs {{ number_format((float) $row['value_remaining'], 0) }} still on the shelf
                            @if($row['packets_in_stock'] !== null)
                                · {{ $row['packets_in_stock'] }} packet(s)
                            @endif
                        </div>
                        {{-- ⭐ One line per product, and tapping it shows what the pool is made
                             of. The owner keeps buying before the shelf runs out, so a weighed
                             product is normally several purchases at once — he asked to be able
                             to see which ones without leaving the card. --}}
                        @if(($row['open_purchases'] ?? 0) > 1)
                            <button type="button" class="sup-pool-toggle" onclick="supTogglePool({{ $p->id }}, this)">
                                {{ $row['open_purchases'] }} purchases in this pool ▾
                            </button>
                            <div class="sup-pool-detail sup-none" id="supPool{{ $p->id }}"></div>
                        @elseif(($row['open_purchases'] ?? 0) === 1)
                            <div class="sup-pmeta" style="margin-top:4px;">from one purchase</div>
                        @endif
                        @if($row['low_stock'])
                            <div style="margin-top:8px;"><span class="sup-chip sup-chip-low">Running low</span></div>
                        @endif

                        <div class="sup-card-btns">
                            <button class="sup-btn sup-btn-main" onclick="supOpenTakeOut({{ $p->id }})">Take out</button>
                            <button class="sup-btn" onclick="supShowBatches({{ $p->id }})">Purchases</button>
                            @if($canManage)
                                <button class="sup-btn" onclick="supOpenCount({{ $p->id }})">Count</button>
                                <button class="sup-btn" onclick="supOpenProduct({{ $p->id }})">Edit</button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- PURCHASES --}}
    <div id="supPaneBatches" class="sup-none">
        <div class="sup-field" style="max-width:320px;">
            <label class="sup-label">Product</label>
            <select class="sup-select" id="supBatchProduct" onchange="supLoadBatches()">
                @foreach($stock as $row)
                    <option value="{{ $row['product']->id }}">{{ $row['product']->name }}</option>
                @endforeach
            </select>
        </div>
        <div id="supBatchList"></div>
    </div>

    {{-- TAKE-OUTS — where a mistake gets fixed. Managers see everyone's. --}}
    <div id="supPaneTakeouts" class="sup-none">
        <div id="supTakeoutList"></div>
    </div>

    {{-- HISTORY --}}
    <div id="supPaneHistory" class="sup-none">
        <div id="supHistoryList"></div>
    </div>
</div>
@endsection

@push('modals')
{{-- Manager-only forms. Gated here as well as on the buttons, the JS (CAN_MANAGE) and the
     server (403) — a staff user should not carry a form in their DOM that they could open
     from the console only to be refused. Take-out below is for everyone. --}}
@if($canManage)
{{-- ⭐ CORRECT A TAKE-OUT'S WEIGHT. Money moves, so nothing happens until the preview has
     been seen — and a month already reported is named before it is restated. --}}
<div class="sup-modal" id="supEditModal">
    <div class="sup-box">
        <div class="sup-box-title" id="supEdTitle">Correct the weight</div>
        <div class="sup-msg sup-msg-err" id="supEdMsg"></div>

        <div class="sup-field">
            <label class="sup-label">What it should be</label>
            <input type="number" class="sup-input" id="supEdQty" min="0.001" step="0.001" oninput="document.getElementById('supEdApply').classList.add('sup-none')">
            <div class="sup-hint">
                The stock goes back and the right amount comes out again, priced from the same
                purchases. The expense keeps its original date.
            </div>
        </div>

        <div class="sup-field">
            <label class="sup-label">Why (optional)</label>
            <input type="text" class="sup-input" id="supEdReason" maxlength="255" placeholder="e.g. label misread">
        </div>

        <button class="sup-btn" onclick="supPreviewEditTakeout()">Show me what changes</button>
        <div class="sup-staged" id="supEdPreview" style="margin-top:10px;"></div>

        <div class="sup-modal-btns">
            <button class="sup-btn" onclick="supClose('supEditModal')">Cancel</button>
            <button class="sup-btn sup-btn-main sup-none" id="supEdApply" onclick="supApplyEditTakeout()">Apply the correction</button>
        </div>
    </div>
</div>

{{-- BOOK IN --}}
<div class="sup-modal" id="supBookInModal">
    <div class="sup-box">
        <div class="sup-box-title">Add stock</div>
        <div class="sup-msg sup-msg-err" id="supBookInMsg"></div>

        <div class="sup-field">
            <label class="sup-label">Product</label>
            <select class="sup-select" id="supBiProduct" onchange="supBiProductChanged()"></select>
        </div>

        {{-- weight / scan: staged packets --}}
        <div class="sup-field" id="supBiScanBlock">
            <label class="sup-label" id="supBiScanLabel">Scan each packet</label>
            <input type="text" class="sup-input" id="supBiScanInput"
                   placeholder="Scan a label, or type it and press Enter"
                   onkeydown="supBiScanKey(event)" autocomplete="off">
            <div class="sup-hint" id="supBiScanHint"></div>
            <div class="sup-staged" id="supBiStaged" style="margin-top:8px;"></div>
            <div class="sup-total"><span id="supBiCountLabel">0 packets</span><span id="supBiQtyLabel">0</span></div>
            <button class="sup-btn" style="margin-top:8px;" onclick="supBiAddManual()" id="supBiManualBtn">＋ Add without scanning</button>

            {{-- ⭐ The cross-check that catches what the per-packet guard cannot: a packet
                 missed entirely, or one label counted twice. Both leave every scan looking
                 perfectly normal, and both mis-cost the WHOLE batch, because the price is
                 divided across the total. Optional — the bill is not always to hand. --}}
            <div class="sup-field" style="margin-top:10px;">
                <label class="sup-label" id="supBiExpectedLabel">Total on the bill (optional)</label>
                <input type="number" class="sup-input" id="supBiExpected" min="0" step="0.001"
                       placeholder="e.g. 2 — leave blank to skip the check">
                <div class="sup-hint" id="supBiExpectedHint">
                    If you know what the whole lot weighs, type it here and we will tell you
                    if the scanned packets do not add up to it.
                </div>
            </div>
        </div>

        {{-- pieces, and packet products: a typed count --}}
        <div class="sup-field sup-none" id="supBiPiecesBlock">
            <label class="sup-label" id="supBiPiecesLabel">How many pieces?</label>
            <input type="number" class="sup-input" id="supBiPieces" min="1" step="1" placeholder="e.g. 500">
        </div>

        <div class="sup-two">
            <div class="sup-field">
                <label class="sup-label">Total paid (Rs)</label>
                <input type="number" class="sup-input" id="supBiCost" min="0" step="0.01" placeholder="e.g. 20000">
            </div>
            <div class="sup-field">
                <label class="sup-label">Purchase date</label>
                <input type="date" class="sup-input" id="supBiDate">
            </div>
        </div>

        <div class="sup-field">
            <label class="sup-label">Paid from</label>
            <select class="sup-select" id="supBiSource" onchange="supBiSourceChanged()"></select>
        </div>

        <div class="sup-field sup-none" id="supBiBankField">
            <label class="sup-label">Which bank did it leave from?</label>
            <select class="sup-select" id="supBiBank"></select>
        </div>

        <div class="sup-field">
            <label class="sup-label" style="font-weight:500;">
                <input type="checkbox" id="supBiStockOnly" onchange="supBiStockOnlyChanged()">
                Already expensed — track the stock only
            </label>
            <div class="sup-hint">
                For packaging you already booked as an expense in an earlier month. It goes on the
                shelf, but taking it out will not charge anything.
            </div>
        </div>

        <div class="sup-field">
            <label class="sup-label">Note (optional)</label>
            <input type="text" class="sup-input" id="supBiNote" maxlength="255">
        </div>

        <div class="sup-modal-btns">
            <button class="sup-btn" onclick="supClose('supBookInModal')">Cancel</button>
            <button class="sup-btn sup-btn-main" id="supBiSave" onclick="supBookIn()">Save</button>
        </div>
    </div>
</div>
@endif

{{-- TAKE OUT --}}
<div class="sup-modal" id="supTakeOutModal">
    <div class="sup-box" style="max-width:480px;">
        <div class="sup-box-title" id="supToTitle">Take out</div>
        <div class="sup-msg sup-msg-err" id="supToMsg"></div>
        <div id="supToBody"></div>
        <div class="sup-modal-btns">
            <button class="sup-btn" onclick="supClose('supTakeOutModal')">Cancel</button>
            <button class="sup-btn sup-btn-main" id="supToConfirm" onclick="supTakeOut()">Take out</button>
        </div>
    </div>
</div>

{{-- FIX PRICE (managers only) --}}
@if($canManage)
<div class="sup-modal" id="supCorrectModal">
    <div class="sup-box" style="max-width:560px;">
        <div class="sup-box-title">Correct the price</div>
        <div class="sup-msg sup-msg-err" id="supCoMsg"></div>

        <div class="sup-field">
            <label class="sup-label">What was actually paid (Rs)</label>
            <input type="number" class="sup-input" id="supCoAmount" min="0.01" step="0.01">
            <div class="sup-hint" id="supCoWas"></div>
        </div>

        <div class="sup-field">
            <label class="sup-label">Why (optional)</label>
            <input type="text" class="sup-input" id="supCoReason" maxlength="255" placeholder="e.g. invoice said 45,000">
        </div>

        <button class="sup-btn" onclick="supPreviewCorrect()">Show me what changes</button>

        <div id="supCoPreview" class="sup-none" style="margin-top:14px;"></div>

        <div class="sup-modal-btns">
            <button class="sup-btn" onclick="supClose('supCorrectModal')">Cancel</button>
            <button class="sup-btn sup-btn-main sup-none" id="supCoApply" onclick="supApplyCorrect()">Apply the correction</button>
        </div>
    </div>
</div>
@endif

{{-- STOCK COUNT (managers only) --}}
@if($canManage)
<div class="sup-modal" id="supCountModal">
    <div class="sup-box" style="max-width:480px;">
        <div class="sup-box-title" id="supCnTitle">Count the shelf</div>
        <div class="sup-msg sup-msg-err" id="supCnMsg"></div>

        <div class="sup-field">
            <div class="sup-hint" id="supCnExpected"></div>
        </div>

        <div class="sup-field">
            <label class="sup-label">How many are actually there?</label>
            <input type="number" class="sup-input" id="supCnCounted" min="0" step="0.001">
        </div>

        <div class="sup-field">
            <label class="sup-label" style="font-weight:500;">
                <input type="checkbox" id="supCnWriteOff" checked>
                If any are missing, book them as used
            </label>
            <div class="sup-hint">
                A packet that is gone was used — someone just didn't scan it. Ticking this charges
                it to expenses so the shelf and the books agree. Leave it unticked to record the
                count without charging anything.
            </div>
        </div>

        <div class="sup-field">
            <label class="sup-label">Note (optional)</label>
            <input type="text" class="sup-input" id="supCnNote" maxlength="255">
        </div>

        <div class="sup-modal-btns">
            <button class="sup-btn" onclick="supClose('supCountModal')">Cancel</button>
            <button class="sup-btn sup-btn-main" id="supCnSave" onclick="supSaveCount()">Record the count</button>
        </div>
    </div>
</div>
@endif

{{-- PRODUCT (managers only — see the note above) --}}
@if($canManage)
<div class="sup-modal" id="supProductModal">
    <div class="sup-box" style="max-width:520px;">
        <div class="sup-box-title" id="supPrTitle">New storage product</div>
        <div class="sup-msg sup-msg-err" id="supPrMsg"></div>

        <div class="sup-field">
            <label class="sup-label">Name</label>
            <input type="text" class="sup-input" id="supPrName" maxlength="150" placeholder="e.g. Packaging - Bags">
        </div>

        {{-- ⚠ Shown when the product already has stock: mode / PLU / barcode are frozen,
             here AND on the server, because the packets on the shelf were recorded under
             the old ones. --}}
        <div class="sup-hint sup-none" id="supPrLockedHint"
             style="background:#FEF3C7;border:1px solid #FDE68A;border-radius:8px;padding:9px 11px;margin-bottom:12px;color:#92400E;"></div>

        <div class="sup-field">
            <label class="sup-label">How is one packet counted?</label>
            <select class="sup-select" id="supPrMode" onchange="supPrModeChanged()">
                <option value="weight">By weight — scan the scale label (kg)</option>
                <option value="scan">By packet — scan a barcode or scale label, 1 scan = 1 packet</option>
                <option value="pieces">By piece — no scanning, type the count</option>
            </select>
        </div>

        <div class="sup-field sup-none" id="supPrPluField">
            <label class="sup-label">Scale PLU</label>
            <input type="number" class="sup-input" id="supPrPlu" min="1" max="999999" placeholder="e.g. 190">
            <div class="sup-hint">The product number programmed on the Czerlop scale. Highest one in shop use today is 189.</div>
        </div>

        <div class="sup-field sup-none" id="supPrBarcodeField">
            <label class="sup-label">Product barcode</label>
            <input type="text" class="sup-input" id="supPrBarcode" maxlength="40" placeholder="Scan it, or type it">
            {{-- ⭐ Sep-25: labels printed on the Czerlop scale carry a count ("1 pcs") inside
                 the barcode, so the full 13 digits change with the count. The bare scale
                 number is what identifies the product — the server reads 1-6 digits here
                 as a scale number and accepts every label that carries it. --}}
            <div class="sup-hint">The vendor's barcode — or, if you print the labels on the Czerlop scale,
                just the scale number (e.g. <b>176</b>). Every label with that number then counts as one
                packet, whatever count it prints.</div>
        </div>

        {{-- ⭐ Only needed when the INNER packets carry a vendor barcode with no weight in
             it. If they are weighed on the Czerlop like everything else, leave both blank. --}}
        <div class="sup-two sup-none" id="supPrPacketField">
            <div class="sup-field">
                <label class="sup-label">Inner packet barcode (optional)</label>
                <input type="text" class="sup-input" id="supPrPacketBarcode" maxlength="40" placeholder="Scan one packet">
            </div>
            <div class="sup-field">
                <label class="sup-label">One packet weighs (kg)</label>
                <input type="number" class="sup-input" id="supPrPacketKg" min="0.001" step="0.001" placeholder="e.g. 1.5">
            </div>
            <div class="sup-hint" style="grid-column:1/-1;">
                Fill these in only if the small packets have the vendor's own barcode, which carries no
                weight. One scan of it then takes this many kg off the shelf. If each packet is weighed
                on the scale instead, leave both blank.
            </div>
        </div>

        <div class="sup-field sup-none" id="supPrPiecesField">
            <label class="sup-label">Pieces per packet (optional)</label>
            <input type="number" class="sup-input" id="supPrPiecesPer" min="1" step="1" placeholder="e.g. 50">
        </div>

        <div class="sup-field">
            <label class="sup-label">Charge its use to</label>
            <select class="sup-select" id="supPrCategory"></select>
            <div class="sup-hint">The expense category each take-out is booked against. You can change it later — entries already made keep the category they were booked under.</div>
        </div>

        <div class="sup-two">
            <div class="sup-field">
                <label class="sup-label">Warn when below (optional)</label>
                <input type="number" class="sup-input" id="supPrLow" min="0" step="0.001">
            </div>
            <div class="sup-field">
                <label class="sup-label">Active</label>
                <select class="sup-select" id="supPrActive">
                    <option value="1">Yes</option>
                    <option value="0">No</option>
                </select>
            </div>
        </div>

        <div class="sup-modal-btns">
            <button class="sup-btn" onclick="supClose('supProductModal')">Cancel</button>
            <button class="sup-btn sup-btn-main" id="supPrSave" onclick="supSaveProduct()">Save</button>
        </div>
    </div>
</div>
@endif
@endpush

@push('custom_js')
<script>
/* Storage (Supplies).
   NOTE: no Blade echo of any kind inside this block. Script content is raw text, so an
   escaped echo renders HTML entities and kills the whole block silently. Everything the
   page needs comes from data- attributes or the JSON endpoints. */
(function () {
    'use strict';

    var root = document.getElementById('supRoot');
    if (!root) { return; }

    var CAN_MANAGE = root.dataset.canManage === '1';
    var CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    var products = [];      // catalogue for the forms
    var categories = [];
    var paySources = [];
    var banks = [];
    var staged = [];        // packets being entered
    var editingProductId = null;
    var takeOut = { productId: null, mode: null, packets: [], chosen: null };

    /* ⭐⭐ ONE uuid per ATTEMPT, minted when the form opens — never per click.
       It used to be created inside supBookIn(), which defeated the whole point: when a
       Save times out the page says "nothing was recorded", but the server may well have
       committed — and the next Save carried a NEW uuid, so the purchase was booked twice
       and the money left twice. Held here and cleared only on success or Cancel, so every
       retry of the same intake is the same request as far as the server is concerned. */
    var bookInUuid = null;

    // ---------- misread guard (mirrors mobile utils/barcodeDecode.js) ----------
    /* ⚠⚠ A VALID CHECK DIGIT IS NOT PROOF OF A CORRECT READ. Paired digit flips whose
       weighted deltas cancel mod 10 pass EAN-13 cleanly, and a real 0.505 kg label once
       decoded as 9.205 kg (Aug-28-2026). The order scanner compares against the line's
       quantity; intake has no such baseline, so each read is compared against the MEDIAN
       of the packets already staged in THIS batch.

       It must be a median, not a mean: one wild read drags a mean far enough to then
       ACCEPT the next bad one, which is the exact failure this is here to stop.

       The band is deliberately wide — a false alarm interrupts a busy manager, a miss
       mis-costs every packet in the batch permanently. Same two numbers as the phone;
       change them in both places or the two surfaces start disagreeing. */
    var OUTLIER_RATIO = 4;
    var OUTLIER_MIN_GAP_KG = 1;

    function medianOf(weights) {
        var xs = (weights || []).map(function (n) { return parseFloat(n); })
            .filter(function (n) { return n > 0; })
            .sort(function (a, b) { return a - b; });
        if (!xs.length) { return 0; }               // first packet has no baseline, by design
        var mid = Math.floor(xs.length / 2);
        return xs.length % 2 ? xs[mid] : (xs[mid - 1] + xs[mid]) / 2;
    }

    function isWeightOutlier(nextQty, currentQty) {
        var next = parseFloat(nextQty), cur = parseFloat(currentQty);
        if (!(next > 0) || !(cur > 0)) { return false; }
        if (Math.abs(next - cur) < OUTLIER_MIN_GAP_KG) { return false; }
        return next >= cur * OUTLIER_RATIO || next <= cur / OUTLIER_RATIO;
    }

    // ---------- helpers ----------
    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: JSON.stringify(body || {})
        }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); });
    }
    function get(url) {
        return fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); });
    }
    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function money(n) { return Number(n || 0).toLocaleString('en-PK', { maximumFractionDigits: 0 }); }
    function trimQty(n) {
        var s = Number(n || 0).toFixed(3);
        return s.replace(/0+$/, '').replace(/\.$/, '');
    }
    function show(id, on) {
        var el = document.getElementById(id);
        if (el) { el.classList.toggle('sup-none', !on); }
    }
    function msg(id, text, kind) {
        var el = document.getElementById(id);
        if (!el) { return; }
        el.textContent = text || '';
        el.style.display = text ? 'block' : 'none';
        el.className = 'sup-msg ' + (kind === 'ok' ? 'sup-msg-ok' : 'sup-msg-err');
    }
    window.supClose = function (id) {
        var el = document.getElementById(id);
        if (el) { el.classList.remove('sup-modal-on'); }
        // Walking away from Add stock ends that attempt — the next one is a new purchase
        // and must carry a new uuid, or it would collapse onto the abandoned one.
        if (id === 'supBookInModal') { bookInUuid = null; }
    };
    function open(id) {
        var el = document.getElementById(id);
        if (el) { el.classList.add('sup-modal-on'); }
    }
    function productById(id) {
        for (var i = 0; i < products.length; i++) {
            if (Number(products[i].id) === Number(id)) { return products[i]; }
        }
        return null;
    }

    // ---------- tabs ----------
    window.supShowTab = function (tab) {
        ['stock', 'batches', 'takeouts', 'history'].forEach(function (t) {
            show('supPane' + t.charAt(0).toUpperCase() + t.slice(1), t === tab);
            var btn = document.getElementById('supTab' + t.charAt(0).toUpperCase() + t.slice(1));
            if (btn) { btn.classList.toggle('sup-tab-on', t === tab); }
        });
        if (tab === 'batches') { supLoadBatches(); }
        if (tab === 'takeouts') { supLoadTakeouts(); }
        if (tab === 'history') { supLoadHistory(); }
    };

    /* ⭐ The take-outs list — the screen where a mistake gets fixed without anyone going
       near the Expenses page. A manager sees everyone's; a store user sees his own. */
    window.supLoadTakeouts = function () {
        var box = document.getElementById('supTakeoutList');
        box.innerHTML = '<div class="sup-empty">Loading…</div>';
        get('/supplies/my-takeouts').then(function (r) {
            var rows = (r.data && r.data.takeouts) || [];
            if (!rows.length) { box.innerHTML = '<div class="sup-empty">No take-outs yet.</div>'; return; }
            box.innerHTML =
                (r.data.shows_everyone ? '<div class="sup-hint" style="margin-bottom:8px;">Showing everyone\'s take-outs.</div>' : '') +
                '<div class="sup-tablewrap"><table class="sup-table"><thead><tr>' +
                '<th>When</th><th>Item</th><th>Out</th><th>Cost</th><th>Who</th><th>Status</th><th></th>' +
                '</tr></thead><tbody>' +
                rows.map(function (t) {
                    var gone = t.status === 'undone' || t.status === 'rejected';
                    return '<tr' + (gone ? ' style="opacity:.55;"' : '') + '>' +
                        '<td>' + esc(t.at || '') + '</td>' +
                        '<td>' + esc(t.product_name || '') +
                            (t.typed ? ' <span class="sup-pmeta">(typed)</span>' : '') + '</td>' +
                        '<td>' + esc(t.qty_label || (trimQty(t.qty) + ' ' + (t.unit || ''))) + '</td>' +
                        '<td>' + (t.cost > 0 ? 'Rs ' + money(t.cost) : '—') + '</td>' +
                        '<td>' + esc(t.taken_by_name || (t.mine ? 'you' : '')) + '</td>' +
                        '<td>' + esc(t.status) + (t.request_number ? '<div class="sup-pmeta">' + esc(t.request_number) + '</div>' : '') + '</td>' +
                        '<td style="white-space:nowrap;">' +
                          (t.can_edit ? '<button class="sup-btn" onclick="supOpenEditTakeout(' + t.id + ',' + Number(t.qty) + ',\'' + esc(t.product_name) + '\',\'' + esc(t.unit || '') + '\')">' + (t.unit === 'pcs' ? 'Edit count' : 'Edit weight') + '</button> ' : '') +
                          (t.can_delete ? '<button class="sup-btn sup-btn-danger" onclick="supDeleteTakeout(' + t.id + ',\'' + esc(t.qty_label || '') + '\')">Delete</button>' : '') +
                        '</td></tr>';
                }).join('') + '</tbody></table></div>';
        });
    };

    /* The stock cards are drawn by the server, so a fix still reloads the page — but it
       comes back on the Take-outs tab, where the manager was working (Sep-26). */
    function supReloadOnTakeouts() {
        try { window.sessionStorage.setItem('supTab', 'takeouts'); } catch (e) { /* private mode */ }
        window.location.reload();
    }

    window.supDeleteTakeout = function (id, label) {
        if (!window.confirm('Delete this take-out?\n\n' + label + ' goes back into Storage, and if it was ' +
                            'already booked to expenses that entry is reversed.\n\nThis is recorded with your name.')) { return; }
        post('/supplies/take-out/' + id + '/delete', { reason: 'Deleted from the Storage page' }).then(function (r) {
            if (!r.ok || !r.data.success) { window.alert((r.data && r.data.message) || 'Could not delete it.'); return; }
            window.alert(r.data.message);
            supReloadOnTakeouts();
        }).catch(function () { window.alert('Could not reach the server. Nothing was changed.'); });
    };

    /* ⭐ Editing a weight moves money, so the figure is previewed BEFORE anything happens —
       and if it would restate a month already reported, it says so. */
    var editingTakeout = null;
    window.supOpenEditTakeout = function (id, qty, name, unit) {
        editingTakeout = id;
        msg('supEdMsg', '');
        document.getElementById('supEdTitle').textContent =
            (unit === 'pcs' ? 'Correct the count — ' : 'Correct the weight — ') + name;
        document.getElementById('supEdQty').step = unit === 'pcs' ? '1' : '0.001';
        document.getElementById('supEdQty').value = qty;
        document.getElementById('supEdReason').value = '';
        document.getElementById('supEdPreview').innerHTML = 'Change the figure and press <b>Show me what changes</b>.';
        document.getElementById('supEdApply').classList.add('sup-none');
        open('supEditModal');
    };

    window.supPreviewEditTakeout = function () {
        var qty = parseFloat(document.getElementById('supEdQty').value || '0');
        if (!(qty > 0)) { msg('supEdMsg', 'Enter a quantity above zero — use Delete to reverse it entirely.'); return; }
        msg('supEdMsg', '');
        post('/supplies/take-out/' + editingTakeout + '/preview-edit', { qty: qty }).then(function (r) {
            var d = r.data || {};
            if (!r.ok || !d.success) { msg('supEdMsg', d.message || 'Could not work that out.'); return; }
            document.getElementById('supEdPreview').innerHTML =
                '<div><b>' + esc(d.was_qty_label) + '</b> · Rs ' + money(d.was_cost) +
                '  →  <b>' + esc(d.qty_label) + '</b> · Rs ' + money(d.cost) + '</div>' +
                '<div class="sup-pmeta" style="margin-top:6px;">' +
                  (d.legs || []).map(function (l) {
                      return 'from the purchase of ' + esc(l.purchase_date) + ': ' + trimQty(l.qty) + ' · Rs ' + money(l.cost);
                  }).join('<br>') + '</div>' +
                (d.restates_earlier_month
                    ? '<div style="margin-top:8px;color:#B45309;"><b>Careful:</b> this expense is dated ' +
                      esc(d.expense_month) + ', a month that has already been reported. Correcting it changes ' +
                      'that month\'s packaging cost — which is the honest figure, but it will move.</div>'
                    : '');
            document.getElementById('supEdApply').classList.remove('sup-none');
        }).catch(function () { msg('supEdMsg', 'Could not reach the server. Nothing was changed.'); });
    };

    window.supApplyEditTakeout = function () {
        var qty = parseFloat(document.getElementById('supEdQty').value || '0');
        var btn = document.getElementById('supEdApply');
        btn.disabled = true;
        post('/supplies/take-out/' + editingTakeout + '/edit', {
            qty: qty, reason: document.getElementById('supEdReason').value || null
        }).then(function (r) {
            btn.disabled = false;
            if (!r.ok || !r.data.success) { msg('supEdMsg', (r.data && r.data.message) || 'Could not save.'); return; }
            window.alert(r.data.message);
            supReloadOnTakeouts();
        }).catch(function () { btn.disabled = false; msg('supEdMsg', 'Could not reach the server. Nothing was changed.'); });
    };

    // ---------- catalogue ----------
    function loadCatalogue() {
        return get('/supplies/products').then(function (r) {
            if (!r.ok || !r.data.success) { return; }
            products = r.data.products || [];
            categories = r.data.expense_categories || [];
            paySources = r.data.payment_sources || [];
            banks = r.data.banks || [];
        });
    }

    // ---------- purchases ----------
    window.supShowBatches = function (productId) {
        supShowTab('batches');
        var sel = document.getElementById('supBatchProduct');
        if (sel) { sel.value = String(productId); }
        supLoadBatches();
    };

    window.supLoadBatches = function () {
        var sel = document.getElementById('supBatchProduct');
        var box = document.getElementById('supBatchList');
        if (!sel || !sel.value || !box) { return; }
        box.innerHTML = '<div class="sup-empty">Loading…</div>';

        get('/supplies/' + encodeURIComponent(sel.value) + '/batches').then(function (r) {
            if (!r.ok || !r.data.success) { box.innerHTML = '<div class="sup-empty">Could not load.</div>'; return; }
            var rows = r.data.batches || [];
            if (!rows.length) { box.innerHTML = '<div class="sup-empty">No purchases recorded yet.</div>'; return; }

            var html = '<div class="sup-tablewrap"><table class="sup-table"><thead><tr>' +
                '<th>Date</th><th>Bought</th><th>Used</th><th>Left</th><th>Cost</th><th>Paid from</th><th>Status</th><th></th>' +
                '</tr></thead><tbody>';

            rows.forEach(function (b) {
                var status = b.status === 'stock_only' ? 'no charge'
                           : (b.status === 'voided' ? 'voided' : 'ok');
                html += '<tr>' +
                    '<td>' + esc(b.purchase_date || '') + '</td>' +
                    '<td>' + esc(trimQty(b.qty_total)) + (b.packet_count ? ' · ' + b.packet_count + ' packet(s)' : '') + '</td>' +
                    '<td>' + esc(trimQty(b.qty_used)) +
                        (Number(b.cost_used) > 0 ? '<div class="sup-pmeta">Rs ' + money(b.cost_used) + '</div>' : '') + '</td>' +
                    '<td>' + esc(b.qty_label || trimQty(b.qty_remaining)) +
                        (Number(b.cost_remaining) > 0 ? '<div class="sup-pmeta">Rs ' + money(b.cost_remaining) + '</div>' : '') + '</td>' +
                    '<td class="sup-money">Rs ' + money(b.total_cost) + '</td>' +
                    '<td>' + esc(b.paid_from || '—') + '</td>' +
                    '<td>' + esc(status) + '</td>' +
                    '<td style="white-space:nowrap;">' +
                        (b.can_correct ? '<button class="sup-btn" onclick="supOpenCorrect(' + Number(b.id) + ',' + Number(b.total_cost) + ')">Fix price</button> ' : '') +
                        (b.can_void
                            ? '<button class="sup-btn sup-btn-danger" onclick="supVoidBatch(' + Number(b.id) + ')">Void</button>'
                            // ⭐ Not a dead button — say WHY it cannot be voided and what to do
                            // instead. A purchase that has been drawn on has to have its
                            // take-outs deleted first (Take-outs tab), or its price fixed.
                            : (Number(b.takeouts_from_it) > 0
                                ? '<span class="sup-pmeta">' + Number(b.takeouts_from_it) +
                                  ' take-out(s) came out of this — delete those first, or use Fix price</span>'
                                : '')) +
                    '</td>' +
                    '</tr>';
            });
            box.innerHTML = html + '</tbody></table></div>';
        });
    };

    window.supVoidBatch = function (batchId) {
        if (!window.confirm('Void this purchase? The payment is reversed and the packets are removed. Only possible while none of it has been used.')) { return; }
        post('/supplies/batches/' + batchId + '/void', {}).then(function (r) {
            window.alert((r.data && r.data.message) || (r.ok ? 'Voided.' : 'Could not void.'));
            if (r.ok && r.data.success) { window.location.reload(); }
        });
    };

    // ---------- history ----------
    window.supLoadHistory = function () {
        var box = document.getElementById('supHistoryList');
        if (!box) { return; }
        box.innerHTML = '<div class="sup-empty">Loading…</div>';

        get('/supplies/history?limit=150').then(function (r) {
            if (!r.ok || !r.data.success) { box.innerHTML = '<div class="sup-empty">Could not load.</div>'; return; }
            var rows = r.data.history || [];
            if (!rows.length) { box.innerHTML = '<div class="sup-empty">Nothing yet.</div>'; return; }

            var label = { 'in': 'added', 'out': 'taken out', 'undo': 'put back', 'void': 'voided' };
            var html = '<div class="sup-tablewrap"><table class="sup-table"><thead><tr>' +
                '<th>When</th><th>What</th><th>Item</th><th>Qty</th><th>Value</th><th>By</th>' +
                '</tr></thead><tbody>';
            rows.forEach(function (h) {
                html += '<tr>' +
                    '<td>' + esc(h.at || '') + '</td>' +
                    '<td>' + esc(label[h.action] || h.action) + '</td>' +
                    '<td>' + esc(h.product_name || '') + '</td>' +
                    '<td>' + esc(trimQty(h.qty)) + ' ' + esc(h.unit || '') + '</td>' +
                    '<td class="sup-money">' + (Number(h.cost) > 0 ? 'Rs ' + money(h.cost) : '—') + '</td>' +
                    '<td>' + esc(h.by || '') + '</td>' +
                    '</tr>';
            });
            box.innerHTML = html + '</tbody></table></div>';
        });
    };

    // ---------- book in ----------
    window.supOpenBookIn = function () {
        if (!CAN_MANAGE) { return; }
        staged = [];
        msg('supBookInMsg', '');
        // One uuid for this whole intake attempt — see the note where bookInUuid is declared.
        bookInUuid = 'web-' + Date.now() + '-' + Math.random().toString(16).slice(2, 10);
        loadCatalogue().then(function () {
            var sel = document.getElementById('supBiProduct');
            sel.innerHTML = products.filter(function (p) { return Number(p.is_active) === 1 || p.is_active === true; })
                .map(function (p) { return '<option value="' + p.id + '">' + esc(p.name) + '</option>'; }).join('');

            // ⚠ Read `is_online` / `display_name` / `is_default` / `preferred_bank_id` —
            // the fields PaymentSourceService actually returns and every other picker in
            // the app already uses. (It does NOT return account_category; keying off that
            // silently left the bank field hidden, so an online purchase could never be
            // completed — the server would keep asking which bank it left from.)
            var src = document.getElementById('supBiSource');
            src.innerHTML = paySources.map(function (a) {
                return '<option value="' + a.id +
                    '" data-online="' + (a.is_online ? '1' : '0') +
                    '" data-bank="' + esc(a.preferred_bank_id || '') + '"' +
                    (a.is_default ? ' selected' : '') + '>' +
                    esc(a.display_name || a.account_name) + '</option>';
            }).join('');

            var bank = document.getElementById('supBiBank');
            bank.innerHTML = '<option value="">— choose —</option>' + banks.map(function (b) {
                return '<option value="' + b.id + '">' + esc(b.short_code || b.bank_name || b.name || ('#' + b.id)) + '</option>';
            }).join('');

            document.getElementById('supBiDate').value = new Date().toISOString().slice(0, 10);
            document.getElementById('supBiCost').value = '';
            document.getElementById('supBiPieces').value = '';
            document.getElementById('supBiNote').value = '';
            document.getElementById('supBiExpected').value = '';
            document.getElementById('supBiStockOnly').checked = false;

            supBiProductChanged();
            supBiSourceChanged();
            supBiStockOnlyChanged();
            renderStaged();
            open('supBookInModal');
            setTimeout(function () {
                var i = document.getElementById('supBiScanInput');
                if (i && !i.closest('.sup-none')) { i.focus(); }
            }, 120);
        });
    };

    window.supBiProductChanged = function () {
        var p = productById(document.getElementById('supBiProduct').value);
        staged = [];
        renderStaged();
        if (!p) { return; }
        // ⭐ Only a WEIGHED product is scanned in — each label carries a different weight.
        // A packet product is booked by COUNT ("10 packets, Rs X"): its labels are all the
        // same, so scanning ten of them tells the server nothing a typed 10 does not.
        var typedCount = p.mode === 'pieces' || p.mode === 'scan';
        show('supBiScanBlock', !typedCount);
        show('supBiPiecesBlock', typedCount);
        document.getElementById('supBiPiecesLabel').textContent =
            p.mode === 'scan' ? 'How many packets?' : 'How many pieces?';
        document.getElementById('supBiPieces').placeholder = p.mode === 'scan' ? 'e.g. 10' : 'e.g. 500';
        document.getElementById('supBiPieces').max = p.mode === 'scan' ? '200' : '';

        var label = document.getElementById('supBiScanLabel');
        var hint = document.getElementById('supBiScanHint');
        var manual = document.getElementById('supBiManualBtn');
        var expLabel = document.getElementById('supBiExpectedLabel');
        var expHint = document.getElementById('supBiExpectedHint');
        var expInput = document.getElementById('supBiExpected');
        expInput.value = '';

        if (p.mode === 'weight') {
            label.textContent = 'Scan each packet';
            hint.textContent = 'Every scale label is one packet. Two packets of the same weight print the same label — that is fine, scan both.';
            manual.textContent = '＋ Add without scanning';
            expLabel.textContent = 'Total weight on the bill, kg (optional)';
            expInput.step = '0.001';
            expHint.textContent = 'If you know what the whole lot weighs, type it here and we will check the scanned packets add up to it.';
        } else if (p.mode === 'scan') {
            label.textContent = 'Scan each packet';
            hint.textContent = 'One scan = one packet.';
            manual.textContent = '＋ Add one packet';
            expLabel.textContent = 'How many packets on the bill (optional)';
            expInput.step = '1';
            expHint.textContent = 'If you know how many packets came, type it here and we will check the scans match.';
        }
    };

    window.supBiSourceChanged = function () {
        var sel = document.getElementById('supBiSource');
        var opt = sel.options[sel.selectedIndex];
        var isBank = !!(opt && opt.dataset.online === '1');
        show('supBiBankField', isBank && !document.getElementById('supBiStockOnly').checked);

        // Preselect the bank this account normally pays from, the same courtesy the
        // other pickers give — the person can still change it.
        if (isBank && opt.dataset.bank) {
            var bank = document.getElementById('supBiBank');
            if (bank && !bank.value) { bank.value = opt.dataset.bank; }
        }
    };

    window.supBiStockOnlyChanged = function () {
        var on = document.getElementById('supBiStockOnly').checked;
        document.getElementById('supBiCost').disabled = on;
        document.getElementById('supBiSource').disabled = on;
        if (on) { document.getElementById('supBiCost').value = ''; }
        supBiSourceChanged();
    };

    window.supBiScanKey = function (ev) {
        if (ev.key !== 'Enter') { return; }
        ev.preventDefault();
        var input = ev.target;
        var raw = (input.value || '').trim();
        input.value = '';
        if (!raw) { return; }

        var p = productById(document.getElementById('supBiProduct').value);
        if (!p) { return; }

        if (p.mode === 'scan') {
            if (p.barcode && raw !== String(p.barcode)) {
                msg('supBookInMsg', 'That barcode is not ' + p.name + '.');
                return;
            }
            msg('supBookInMsg', '');
            staged.push({ qty: 1, barcode: p.barcode, source: 'scan' });
            renderStaged();
            return;
        }

        // weight: the SERVER decodes, so the page never re-implements the EAN maths
        post('/supplies/decode', { barcode: raw }).then(function (r) {
            if (!r.ok || !r.data.success) {
                msg('supBookInMsg', (r.data && r.data.message) || 'Could not read that label.');
                return;
            }
            if (Number(r.data.plu) !== Number(p.plu)) {
                msg('supBookInMsg', 'That label is PLU ' + r.data.plu + ', not ' + p.name + '.');
                return;
            }

            // ⭐ The guard. Compare against the median of what is already in this batch;
            // the first packet has nothing to compare with and is never questioned.
            var baseline = medianOf(staged.map(function (s) { return Number(s.qty) || 0; }));
            if (baseline > 0 && isWeightOutlier(r.data.weight_kg, baseline)) {
                // ⚠ With only ONE packet staged the baseline is that single packet, so we
                // genuinely cannot tell which of the two misread — and if the FIRST scan
                // was the bad one it becomes the baseline and every good packet after it
                // gets questioned. Say so plainly and point at the ✕, instead of implying
                // the new read is the guilty one.
                var comparison = staged.length === 1
                    ? 'The only other packet in this batch is ' + trimQty(baseline) + ' kg, so one of the '
                      + 'two misread — check both labels, and use ✕ to remove whichever is wrong.'
                    : 'The other packets in this batch are around ' + trimQty(baseline) + ' kg.';

                var keep = window.confirm(
                    'Unusual weight — ' + trimQty(r.data.weight_kg) + ' kg.\n\n' +
                    comparison + '\n' +
                    'A label can misread and still look valid, and a wrong weight here re-prices ' +
                    'EVERY packet in this batch.\n\n' +
                    'OK = the weight is correct, add it.\nCancel = scan the label again.');
                if (!keep) {
                    msg('supBookInMsg', 'Not added — scan that label again.');
                    return;
                }
            }

            msg('supBookInMsg', '');
            staged.push({ qty: r.data.weight_kg, barcode: r.data.barcode, source: 'scan' });
            renderStaged();
        });
    };

    window.supBiAddManual = function () {
        var p = productById(document.getElementById('supBiProduct').value);
        if (!p) { return; }
        if (p.mode === 'scan') {
            staged.push({ qty: 1, barcode: p.barcode, source: 'manual' });
            renderStaged();
            return;
        }
        var kg = window.prompt('Weight of this packet in kg (e.g. 1.25)');
        if (kg === null) { return; }
        var n = parseFloat(kg);
        if (!(n > 0)) { msg('supBookInMsg', 'Enter a weight greater than zero.'); return; }
        staged.push({ qty: n, source: 'manual' });
        renderStaged();
    };

    window.supBiRemove = function (i) { staged.splice(i, 1); renderStaged(); };

    function renderStaged() {
        var box = document.getElementById('supBiStaged');
        var p = productById((document.getElementById('supBiProduct') || {}).value);
        if (!box) { return; }
        if (!staged.length) {
            box.innerHTML = '<div class="sup-staged-row" style="color:#9CA3AF;">Nothing scanned yet.</div>';
        } else {
            box.innerHTML = staged.map(function (s, i) {
                var q = (p && p.mode === 'weight') ? trimQty(s.qty) + ' kg' : '1 packet';
                return '<div class="sup-staged-row"><span>' + (i + 1) + '. ' + esc(q) +
                    (s.source === 'manual' ? ' <span class="sup-pmeta">(typed)</span>' : '') +
                    '</span><button class="sup-x" onclick="supBiRemove(' + i + ')">✕</button></div>';
            }).join('');
        }
        var total = staged.reduce(function (a, s) { return a + Number(s.qty || 0); }, 0);
        document.getElementById('supBiCountLabel').textContent = staged.length + ' packet(s)';
        document.getElementById('supBiQtyLabel').textContent =
            (p && p.mode === 'weight') ? trimQty(total) + ' kg' : staged.length + ' packet(s)';
    }

    /* Does the scanned batch add up to what the bill says? Returns true to carry on.
       Blank = skip (the bill is not always to hand). A weight lot is allowed 0.5 % or
       5 g of slack, whichever is larger — scale rounding, not a missing packet. A packet
       count must match exactly: you cannot be half a packet out. */
    function supBiExpectedOk(p) {
        var raw = (document.getElementById('supBiExpected').value || '').trim();
        if (raw === '') { return true; }
        var expected = parseFloat(raw);
        if (!(expected > 0)) { return true; }

        var byWeight = p.mode === 'weight';
        var actual = byWeight
            ? staged.reduce(function (a, s) { return a + (Number(s.qty) || 0); }, 0)
            : staged.length;
        var tolerance = byWeight ? Math.max(expected * 0.005, 0.005) : 0;

        if (Math.abs(actual - expected) <= tolerance) { return true; }

        var unit = byWeight ? ' kg' : ' packet(s)';
        var shown = byWeight ? trimQty(actual) : String(actual);
        return window.confirm(
            'That does not add up.\n\n' +
            'Scanned: ' + shown + unit + ' in ' + staged.length + ' packet(s)\n' +
            'On the bill: ' + trimQty(expected) + unit + '\n\n' +
            (actual < expected
                ? 'A packet may be missing, or one label did not read.'
                : 'A label may have been scanned twice — two packets of the same weight print the same label.') +
            '\n\nThe price is divided across the total, so if this is wrong EVERY packet ' +
            'in this batch is priced wrong.\n\nOK = save it anyway.\nCancel = go back and fix it.');
    }

    window.supBookIn = function () {
        var p = productById(document.getElementById('supBiProduct').value);
        if (!p) { return; }
        var stockOnly = document.getElementById('supBiStockOnly').checked;
        // ⚠ REUSE the uuid minted when the form opened. Generating one here meant a retry
        // after a timeout was a brand-new purchase to the server. Never regenerate it on
        // a failure path — that failure is exactly when it matters.
        if (!bookInUuid) { bookInUuid = 'web-' + Date.now() + '-' + Math.random().toString(16).slice(2, 10); }

        var body = {
            product_id: p.id,
            purchase_date: document.getElementById('supBiDate').value || null,
            total_cost: stockOnly ? 0 : parseFloat(document.getElementById('supBiCost').value || '0'),
            stock_only: stockOnly,
            note: document.getElementById('supBiNote').value || null,
            client_uuid: bookInUuid
        };

        if (p.mode === 'pieces') {
            body.pieces_qty = parseFloat(document.getElementById('supBiPieces').value || '0');
            if (!(body.pieces_qty > 0)) { msg('supBookInMsg', 'How many pieces are you adding?'); return; }
        } else if (p.mode === 'scan') {
            var n = Number(document.getElementById('supBiPieces').value || '0');
            if (!(n >= 1) || Math.floor(n) !== n) { msg('supBookInMsg', 'How many packets are you adding? (a whole number)'); return; }
            if (n > 200) { msg('supBookInMsg', 'At most 200 packets in one purchase — split it into two.'); return; }
            body.packet_count = n;
        } else {
            if (!staged.length) { msg('supBookInMsg', 'Scan at least one packet.'); return; }
            body.packets = staged;

            // ⭐ The total cross-check. A missed packet and a double-scanned label both
            // look perfectly normal packet-by-packet, and both re-price the whole batch.
            if (!supBiExpectedOk(p)) { return; }
        }

        if (!stockOnly) {
            if (!(body.total_cost > 0)) { msg('supBookInMsg', 'Enter what was paid for this stock.'); return; }
            body.payment_source_account_id = parseInt(document.getElementById('supBiSource').value, 10) || null;
            if (!body.payment_source_account_id) { msg('supBookInMsg', 'Choose which account paid.'); return; }
            var bankField = document.getElementById('supBiBankField');
            if (!bankField.classList.contains('sup-none')) {
                body.receiving_account_id = parseInt(document.getElementById('supBiBank').value, 10) || null;
                if (!body.receiving_account_id) { msg('supBookInMsg', 'Select which bank this payment came from.'); return; }
            }
        }

        var btn = document.getElementById('supBiSave');
        btn.disabled = true;
        post('/supplies/batches', body).then(function (r) {
            btn.disabled = false;
            if (!r.ok || !r.data.success) {
                msg('supBookInMsg', (r.data && r.data.message) || 'Could not save. Nothing was recorded.');
                return;
            }
            bookInUuid = null;               // this attempt is finished
            window.location.reload();
        }).catch(function () {
            btn.disabled = false;
            // ⚠ Honest copy. The old line claimed "nothing was recorded", which we cannot
            // know — the request may have committed and the reply been lost. The uuid is
            // deliberately KEPT, so pressing Save again lands on the same batch instead of
            // booking a second one.
            msg('supBookInMsg', 'Could not reach the server. Press Save again — the same '
                + 'purchase is never booked twice.');
        });
    };

    // ---------- take out ----------
    /* ⭐ What the pool is made of. The owner keeps buying before the shelf runs out, so a
       weighed product is normally two or three purchases at once — and FIFO means the
       OLDEST is what the next packet will be priced from. Showing which one, and at what
       rate, is the difference between "trust it" and "see it". */
    window.supTogglePool = function (productId, btn) {
        var box = document.getElementById('supPool' + productId);
        if (!box) { return; }
        var open = !box.classList.contains('sup-none');
        if (open) {
            box.classList.add('sup-none');
            btn.textContent = btn.textContent.replace('▴', '▾');
            return;
        }
        box.classList.remove('sup-none');
        btn.textContent = btn.textContent.replace('▾', '▴');
        box.innerHTML = '<div class="sup-pool-row">Loading…</div>';

        get('/supplies/' + encodeURIComponent(productId) + '/batches').then(function (r) {
            var open = ((r.data && r.data.batches) || []).filter(function (b) { return b.is_active; });
            if (!open.length) { box.innerHTML = '<div class="sup-pool-row">Nothing on the shelf.</div>'; return; }
            // oldest first — that is the order they will be drawn down in
            open.sort(function (a, b) { return String(a.purchase_date).localeCompare(String(b.purchase_date)) || a.id - b.id; });
            box.innerHTML = open.map(function (b, i) {
                var rate = b.qty_total > 0 ? (b.total_cost / b.qty_total) : 0;
                return '<div class="sup-pool-row' + (i === 0 ? ' sup-pool-next' : '') + '">' +
                    '<span>' + (i === 0 ? '→ ' : '') + esc(b.qty_label) + ' left of ' + trimQty(b.qty_total) +
                    ' · bought ' + esc(String(b.purchase_date || '').slice(0, 10)) +
                    (b.paid_from ? ' · ' + esc(b.paid_from) : '') + '</span>' +
                    '<span>Rs ' + money(b.cost_remaining) + ' @ Rs ' + money(rate) + '/unit</span></div>';
            }).join('') +
            '<div class="sup-pool-row" style="color:#9CA3AF;">The next take-out comes off the one marked →.</div>';
        });
    };

    window.supOpenTakeOut = function (productId) {
        msg('supToMsg', '');
        takeOut = { productId: productId, mode: null, packets: [], chosen: null };
        var body = document.getElementById('supToBody');
        body.innerHTML = '<div class="sup-empty">Loading…</div>';
        open('supTakeOutModal');

        loadCatalogue().then(function () {
            var p = productById(productId);
            if (!p) { body.innerHTML = '<div class="sup-empty">Product not found.</div>'; return; }
            takeOut.mode = p.mode;
            takeOut.pooled = (p.mode === 'weight' || p.mode === 'pieces');
            document.getElementById('supToTitle').textContent = 'Take out — ' + p.name;

            // ⭐⭐ A POOLED product is taken out by QUANTITY, not by choosing a packet.
            // The bale was booked under one tray label; what leaves is the small packet in
            // your hand. Scan it (the server reads the weight off the label) or type it —
            // a torn label must never stop the store.
            if (takeOut.pooled) {
                var isWeight = p.mode === 'weight';
                // (Sep-26: exactly as it was — the owner ruled weighed and counted products
                // stay typeable for everyone; only PACKET products are scan-only for staff.)
                body.innerHTML =
                    '<div class="sup-field">' +
                      '<label class="sup-label">Scan the packet</label>' +
                      '<input type="text" class="sup-input" id="supToScan" autocomplete="off"' +
                        ' placeholder="Scan the label, or type the ' + (isWeight ? 'weight' : 'count') + ' below"' +
                        ' onkeydown="supToScanKey(event)">' +
                    '</div>' +
                    '<div class="sup-field">' +
                      '<label class="sup-label">' + (isWeight ? 'Weight (kg)' : 'How many?') + '</label>' +
                      '<input type="number" class="sup-input" id="supToQty" min="0.001" step="' +
                        (isWeight ? '0.001' : '1') + '" oninput="supToQuoteSoon()">' +
                      '<div class="sup-hint" id="supToQuote">It comes off the oldest purchase first, so the cost is what was actually paid for it.</div>' +
                    '</div>' +
                    '<div class="sup-msg sup-msg-err sup-none" id="supToWarn"></div>';
                document.getElementById('supToConfirm').disabled = false;
                takeOut.confirmed = [];
                takeOut.scanned = null;
                setTimeout(function () {
                    var i = document.getElementById('supToScan');
                    if (i) { i.focus(); }
                }, 120);
                return;
            }

            // ⭐ A PACKET product: one scan = one packet, the oldest on the shelf. The
            // server picks it and prices it; this page only shows what it said. There is
            // no "which packet?" list any more — every packet is the same thing, and the
            // list was a take-out without a scan, open to everyone.
            takeOut.chosen = null;
            takeOut.scanned = null;
            document.getElementById('supToConfirm').disabled = true;
            body.innerHTML =
                '<div class="sup-field">' +
                  '<label class="sup-label">Scan the packet</label>' +
                  '<input type="text" class="sup-input" id="supToScan" autocomplete="off"' +
                    ' placeholder="Scan the label" onkeydown="supToPacketScanKey(event)">' +
                  '<div class="sup-hint" id="supToQuote">One scan = one packet. The oldest one on the shelf is taken first.</div>' +
                '</div>' +
                (CAN_MANAGE
                    ? '<button class="sup-btn" onclick="supToWithoutScan()">Take one out without scanning</button>'
                    : '');
            setTimeout(function () {
                var i = document.getElementById('supToScan');
                if (i) { i.focus(); }
            }, 120);
        });
    };

    /* Show the packet the server picked — "1 packet — Rs X · of 10 packets on the shelf". */
    function supToShowPacket(d, scanned) {
        takeOut.chosen = d.packet.id;
        takeOut.scanned = scanned || null;
        var hint = document.getElementById('supToQuote');
        if (hint) {
            hint.innerHTML = '<b>' + esc(d.packet.qty_label) + '</b> — ' +
                (d.packet.free ? 'no charge (already expensed)' : 'Rs ' + money(d.packet.cost)) +
                (d.on_shelf_label ? ' · of ' + esc(d.on_shelf_label) + ' on the shelf' : '') +
                (d.packet.batch_date ? ' · bought ' + esc(d.packet.batch_date) : '') +
                (scanned ? '' : ' <span style="color:#B45309;">(without scanning)</span>');
        }
        document.getElementById('supToConfirm').disabled = false;
    }

    window.supToPacketScanKey = function (ev) {
        if (ev.key !== 'Enter') { return; }
        ev.preventDefault();
        var input = ev.target;
        var raw = (input.value || '').trim();
        input.value = '';
        if (!raw) { return; }

        post('/supplies/resolve-scan', { barcode: raw }).then(function (r) {
            var d = r.data || {};
            if (!r.ok || !d.success || !d.packet) {
                msg('supToMsg', d.message || 'Could not read that label.');
                return;
            }
            if (Number(d.product && d.product.id) !== Number(takeOut.productId)) {
                msg('supToMsg', 'That label is ' + esc((d.product || {}).name || 'another item') + '.');
                return;
            }
            msg('supToMsg', '');
            supToShowPacket(d, d.scanned_barcode || raw);
        }).catch(supToOffline);
    };

    /* Managers only — the server refuses anyone else. */
    window.supToWithoutScan = function () {
        post('/supplies/take-out/quote', { product_id: takeOut.productId }).then(function (r) {
            var d = r.data || {};
            if (!r.ok || !d.success || !d.packet) {
                msg('supToMsg', d.message || 'Could not work that out.');
                return;
            }
            msg('supToMsg', '');
            supToShowPacket(d, null);
        }).catch(supToOffline);
    };

    /* The scan box on a pooled take-out. The SERVER reads the weight off the label — the
       page never re-implements the EAN maths, exactly as the intake box already works. */
    window.supToScanKey = function (ev) {
        if (ev.key !== 'Enter') { return; }
        ev.preventDefault();
        var input = ev.target;
        var raw = (input.value || '').trim();
        input.value = '';
        if (!raw) { return; }

        post('/supplies/resolve-scan', { barcode: raw }).then(function (r) {
            var d = r.data || {};
            if (!r.ok || !d.success) {
                msg('supToMsg', d.message || 'Could not read that label.');
                return;
            }
            if (Number(d.product && d.product.id) !== Number(takeOut.productId)) {
                msg('supToMsg', 'That label is ' + esc((d.product || {}).name || 'another item') + '.');
                return;
            }
            msg('supToMsg', '');
            takeOut.scanned = d.scanned_barcode || raw;
            document.getElementById('supToQty').value = d.qty;
            supToShowQuote(d);
        }).catch(supToOffline);
    };

    /* Sep-26: a dropped connection used to clear the scan box and say nothing at all. */
    function supToOffline() {
        msg('supToMsg', 'Could not reach the server — check the connection and scan again. Nothing was recorded.');
    }

    /* Price what has been typed, so the figure is on screen BEFORE the button is pressed.
       Debounced — a person typing "1.25" would otherwise fire three requests. */
    var quoteTimer = null;
    window.supToQuoteSoon = function () {
        takeOut.scanned = null;                       // typed now, not scanned
        takeOut.confirmed = [];
        // ⚠ Sep-26: a new figure has not been checked yet. Keeping the old warnings meant a
        // press inside the 350 ms debounce sent THEM back as "confirmed" for this figure.
        supToShowWarnings([]);
        if (quoteTimer) { clearTimeout(quoteTimer); }
        quoteTimer = setTimeout(function () {
            var qty = parseFloat((document.getElementById('supToQty') || {}).value || '0');
            if (!(qty > 0)) { return; }
            post('/supplies/take-out/quote', { product_id: takeOut.productId, qty: qty }).then(function (r) {
                if (r.ok && r.data && r.data.success) { msg('supToMsg', ''); supToShowQuote(r.data); }
                else { msg('supToMsg', (r.data && r.data.message) || ''); }
            }).catch(supToOffline);
        }, 350);
    };

    function supToShowQuote(d) {
        var hint = document.getElementById('supToQuote');
        if (hint) {
            hint.innerHTML = '<b>' + esc(d.qty_label) + '</b> — ' +
                (d.free ? 'no charge (already expensed)' : 'Rs ' + money(d.cost)) +
                ' · of ' + esc(d.pool_label) + ' on the shelf' +
                (d.capped ? ' <span style="color:#B45309;">(that is all that is left)</span>' : '');
        }
        supToShowWarnings(d.warnings || []);
    }

    function supToShowWarnings(warnings) {
        var warn = document.getElementById('supToWarn');
        takeOut.warnings = warnings || [];
        if (warn) {
            if (takeOut.warnings.length) {
                warn.classList.remove('sup-none');
                warn.innerHTML = takeOut.warnings.map(function (w) {
                    return '<div><b>' + esc(w.title) + ':</b> ' + esc(w.message) + '</div>';
                }).join('') + '<div style="margin-top:4px;">Press <b>Take out</b> again to confirm.</div>';
            } else {
                warn.classList.add('sup-none');
                warn.innerHTML = '';
            }
        }
    }

    window.supTakeOut = function () {
        var body = { product_id: takeOut.productId, source: 'manual' };
        if (takeOut.pooled) {
            body.qty = parseFloat((document.getElementById('supToQty') || {}).value || '0');
            if (!(body.qty > 0)) { msg('supToMsg', 'How much are you taking out?'); return; }
            if (takeOut.scanned) { body.scanned_barcode = takeOut.scanned; body.source = 'scan'; }
            // ⚠ Confirmations are sent back BY CODE, and the server re-runs every guard
            // against them. Pressing the button a second time is the deliberate act.
            body.confirmed_warnings = (takeOut.warnings || []).map(function (w) { return w.code; });
        } else {
            if (!takeOut.chosen) { msg('supToMsg', 'Scan the packet first.'); return; }
            body.packet_id = takeOut.chosen;
            if (takeOut.scanned) { body.scanned_barcode = takeOut.scanned; body.source = 'scan'; }
        }

        var btn = document.getElementById('supToConfirm');
        btn.disabled = true;
        post('/supplies/take-out', body).then(function (r) {
            btn.disabled = false;
            if (!r.ok || !r.data.success) {
                // The server raised a guard the page had not shown yet (it re-checks
                // everything, and a stale quote can miss one). Show it and let the next
                // press confirm — never write behind a warning nobody has seen.
                if (r.data && r.data.code === 'needs_confirmation') {
                    // ⚠ Sep-26: the WARNINGS only. This used to repaint the whole quote line
                    // as "Rs 0 · of  on the shelf" right before the confirming press.
                    supToShowWarnings(r.data.warnings || []);
                    msg('supToMsg', '');
                    return;
                }
                msg('supToMsg', (r.data && r.data.message) || 'Could not take it out.');
                return;
            }
            window.alert(r.data.message || 'Taken out.');
            window.location.reload();
        }).catch(function () {
            btn.disabled = false;
            // Honest copy (Sep-27): the reply may have been lost after the server saved it.
            msg('supToMsg', 'Could not reach the server. Check the Take-outs tab before trying again — it may already be recorded.');
        });
    };

    // ---------- product form ----------
    window.supOpenProduct = function (id) {
        if (!CAN_MANAGE) { return; }
        msg('supPrMsg', '');
        editingProductId = id || null;
        loadCatalogue().then(function () {
            var cat = document.getElementById('supPrCategory');
            cat.innerHTML = categories.map(function (c) {
                return '<option value="' + c.id + '">' + esc(c.name) + '</option>';
            }).join('');

            var p = id ? productById(id) : null;
            document.getElementById('supPrTitle').textContent = p ? ('Edit ' + p.name) : 'New storage product';
            document.getElementById('supPrName').value = p ? p.name : '';
            document.getElementById('supPrMode').value = p ? p.mode : 'weight';
            document.getElementById('supPrPlu').value = p && p.plu ? p.plu : '';
            document.getElementById('supPrBarcode').value = p && p.barcode ? p.barcode : '';
            document.getElementById('supPrPacketBarcode').value = p && p.packet_barcode ? p.packet_barcode : '';
            document.getElementById('supPrPacketKg').value = p && p.packet_kg ? p.packet_kg : '';
            document.getElementById('supPrPiecesPer').value = p && p.pieces_per_packet ? p.pieces_per_packet : '';
            // trimQty: "2.000" -> "2" (the column is decimal:3, but packets are whole)
            document.getElementById('supPrLow').value = p && p.low_stock_qty ? trimQty(p.low_stock_qty) : '';
            document.getElementById('supPrActive').value = (p && !(Number(p.is_active) === 1 || p.is_active === true)) ? '0' : '1';
            if (p && p.expense_config_id) { cat.value = String(p.expense_config_id); }

            // ⭐ Mode, PLU and barcode are FROZEN once the product has stock — changing
            // any of them re-reads the packets already on the shelf in a different unit
            // or under a code that no longer finds them. The server refuses it too
            // (SupplyStorageController::saveProduct); this is so the manager sees why
            // before filling the form instead of bouncing off a 422 afterwards.
            var locked = !!(p && p.has_stock);
            var lockHint = document.getElementById('supPrLockedHint');
            ['supPrMode', 'supPrPlu', 'supPrBarcode'].forEach(function (f) {
                var el = document.getElementById(f);
                if (el) { el.disabled = locked; }
            });
            show('supPrLockedHint', locked);
            if (locked) {
                lockHint.textContent = p.name + ' already has stock booked in, so how it is counted, '
                    + 'its PLU and its barcode are locked — the packets on the shelf were recorded that way. '
                    + 'Everything else can still be changed. For different packaging, add it as a new product.';
            }

            supPrModeChanged();
            open('supProductModal');
        });
    };

    window.supPrModeChanged = function () {
        var m = document.getElementById('supPrMode').value;
        show('supPrPluField', m === 'weight');
        show('supPrBarcodeField', m === 'scan');
        show('supPrPacketField', m === 'weight');
        show('supPrPiecesField', m === 'pieces');
    };

    window.supSaveProduct = function () {
        var body = {
            name: document.getElementById('supPrName').value.trim(),
            mode: document.getElementById('supPrMode').value,
            plu: parseInt(document.getElementById('supPrPlu').value, 10) || null,
            barcode: document.getElementById('supPrBarcode').value.trim() || null,
            // Additive, so the round-2 "frozen once it has stock" lock deliberately allows
            // these two to be filled in later — nothing already on the shelf is re-read.
            packet_barcode: document.getElementById('supPrPacketBarcode').value.trim() || null,
            packet_kg: parseFloat(document.getElementById('supPrPacketKg').value) || null,
            pieces_per_packet: parseInt(document.getElementById('supPrPiecesPer').value, 10) || null,
            expense_config_id: parseInt(document.getElementById('supPrCategory').value, 10) || null,
            low_stock_qty: parseFloat(document.getElementById('supPrLow').value) || null,
            is_active: document.getElementById('supPrActive').value === '1'
        };
        if (!body.name) { msg('supPrMsg', 'Give it a name.'); return; }
        if (!body.expense_config_id) { msg('supPrMsg', 'Choose which expense category its use is charged to.'); return; }

        var btn = document.getElementById('supPrSave');
        btn.disabled = true;
        post('/supplies/products' + (editingProductId ? '/' + editingProductId : ''), body).then(function (r) {
            btn.disabled = false;
            if (!r.ok || !r.data.success) {
                msg('supPrMsg', (r.data && r.data.message) || 'Could not save.');
                return;
            }
            if (r.data.warning) { window.alert(r.data.warning); }
            window.location.reload();
        }).catch(function () {
            btn.disabled = false;
            msg('supPrMsg', 'Could not reach the server.');
        });
    };

    // ---------- fix price ----------
    var correcting = null;

    window.supOpenCorrect = function (batchId, oldTotal) {
        if (!CAN_MANAGE) { return; }
        correcting = {batchId: batchId, oldTotal: oldTotal};
        msg('supCoMsg', '');
        document.getElementById('supCoAmount').value = '';
        document.getElementById('supCoReason').value = '';
        document.getElementById('supCoWas').textContent = 'Currently recorded as Rs ' + money(oldTotal) + '.';
        document.getElementById('supCoPreview').innerHTML = '';
        show('supCoPreview', false);
        show('supCoApply', false);
        open('supCorrectModal');
    };

    window.supPreviewCorrect = function () {
        var amount = parseFloat(document.getElementById('supCoAmount').value);
        if (!(amount > 0)) { msg('supCoMsg', 'Enter what was actually paid.'); return; }
        msg('supCoMsg', '');

        post('/supplies/batches/' + correcting.batchId + '/preview-correction', {total_cost: amount})
            .then(function (r) {
                if (!r.ok || !r.data.success) {
                    msg('supCoMsg', (r.data && r.data.message) || 'Could not work that out.');
                    return;
                }
                var p = r.data.preview;
                var diff = Number(p.difference);
                var html = '<div class="sup-total"><span>' +
                    (diff < 0 ? 'Comes back to ' : 'Comes out of ') + esc(p.paid_from || 'the account') +
                    '</span><span>Rs ' + money(Math.abs(diff)) + '</span></div>';

                if (!p.expenses.length) {
                    html += '<div class="sup-hint" style="margin-top:8px;">' +
                        'Nothing has been taken out of this purchase yet, so no expense entries change.</div>';
                } else {
                    html += '<div class="sup-hint" style="margin-top:10px;"><strong>' + p.expenses.length +
                        ' expense entr' + (p.expenses.length === 1 ? 'y' : 'ies') +
                        '</strong> already posted will be corrected, on their original dates:</div>' +
                        '<div class="sup-staged" style="margin-top:6px;">' +
                        p.expenses.map(function (e) {
                            return '<div class="sup-staged-row"><span>' + esc(e.request_number || '') +
                                ' <span class="sup-pmeta">' + esc(e.month || '') + '</span></span>' +
                                '<span>Rs ' + money(e.was) + ' → <strong>Rs ' + money(e.now) + '</strong></span></div>';
                        }).join('') + '</div>';

                    if (p.months_affected.length) {
                        html += '<div class="sup-msg sup-msg-err" style="display:block;margin-top:10px;">' +
                            '⚠ This changes the packaging figure for ' + esc(p.months_affected.join(', ')) +
                            '. If you have already reported that month, it will move.</div>';
                    }
                }
                document.getElementById('supCoPreview').innerHTML = html;
                show('supCoPreview', true);
                show('supCoApply', true);
            });
    };

    window.supApplyCorrect = function () {
        var amount = parseFloat(document.getElementById('supCoAmount').value);
        if (!(amount > 0)) { return; }
        var btn = document.getElementById('supCoApply');
        btn.disabled = true;
        post('/supplies/batches/' + correcting.batchId + '/correct-price', {
            total_cost: amount,
            reason: document.getElementById('supCoReason').value || null
        }).then(function (r) {
            btn.disabled = false;
            if (!r.ok || !r.data.success) {
                msg('supCoMsg', (r.data && r.data.message) || 'Could not correct it.');
                return;
            }
            window.alert(r.data.message);
            window.location.reload();
        }).catch(function () {
            btn.disabled = false;
            msg('supCoMsg', 'Could not reach the server. Nothing was changed.');
        });
    };

    // ---------- stock count ----------
    var counting = null;

    window.supOpenCount = function (productId) {
        if (!CAN_MANAGE) { return; }
        msg('supCnMsg', '');
        document.getElementById('supCnCounted').value = '';
        document.getElementById('supCnNote').value = '';
        document.getElementById('supCnWriteOff').checked = true;

        get('/supplies/stock').then(function (r) {
            var row = ((r.data && r.data.products) || []).filter(function (x) {
                return Number(x.id) === Number(productId);
            })[0];
            counting = {productId: productId, expected: row ? row.qty_remaining : 0};
            document.getElementById('supCnTitle').textContent =
                'Count the shelf — ' + ((row && row.name) || '');
            document.getElementById('supCnExpected').textContent = row
                ? ('The system thinks there ' + (row.packets_in_stock === 1 ? 'is' : 'are') + ' ' +
                   (row.packets_in_stock != null
                        ? row.packets_in_stock + ' packet(s)'
                        : trimQty(row.qty_remaining) + ' ' + row.unit) + ' on the shelf.')
                : '';
            open('supCountModal');
        });
    };

    window.supSaveCount = function () {
        var counted = parseFloat(document.getElementById('supCnCounted').value);
        if (!(counted >= 0)) { msg('supCnMsg', 'Enter how many are actually there.'); return; }
        var btn = document.getElementById('supCnSave');
        btn.disabled = true;
        post('/supplies/' + counting.productId + '/count', {
            counted: counted,
            write_off: document.getElementById('supCnWriteOff').checked,
            note: document.getElementById('supCnNote').value || null
        }).then(function (r) {
            btn.disabled = false;
            if (!r.ok || !r.data.success) {
                msg('supCnMsg', (r.data && r.data.message) || 'Could not record the count.');
                return;
            }
            window.alert(r.data.message);
            window.location.reload();
        }).catch(function () {
            btn.disabled = false;
            msg('supCnMsg', 'Could not reach the server. Nothing was recorded.');
        });
    };

    // ---------- approval switch ----------
    var sw = document.getElementById('supApprovalSwitch');
    if (sw) {
        sw.addEventListener('change', function () {
            var want = sw.checked;
            sw.disabled = true;
            post('/admin/operations/supply-approval', { required: want }).then(function (r) {
                sw.disabled = false;
                if (!r.ok || !r.data.success) { sw.checked = !want; window.alert('Could not change that.'); return; }
                window.alert(r.data.message);
            }).catch(function () { sw.disabled = false; sw.checked = !want; });
        });
    }

    // preload so the first modal opens instantly
    loadCatalogue();

    // back on the tab a delete / edit reloaded from (see supReloadOnTakeouts)
    try {
        if (window.sessionStorage.getItem('supTab') === 'takeouts') {
            window.sessionStorage.removeItem('supTab');
            supShowTab('takeouts');
        }
    } catch (e) { /* storage blocked — the Stock tab is a fine default */ }
})();
</script>
@endpush
