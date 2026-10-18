@extends('layouts.app')

@section('title', 'Campaigns')

@push('demo1_css')
<style>
#content { display: flex; flex-direction: column; overflow: hidden; }

.camp-page {
    display: flex;
    flex: 1;
    height: calc(100vh - 130px);
    background: #f0f2f5;
    border: 1px solid #dfe5e7;
    border-radius: 4px;
    overflow: hidden;
    margin: 0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

/* Left panel - campaign list */
.camp-list {
    width: 360px;
    min-width: 300px;
    max-width: 400px;
    background: #fff;
    border-right: 1px solid #e2e8f0;
    display: flex;
    flex-direction: column;
}
.camp-list-header {
    padding: 16px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}
.camp-list-header h3 { font-size: 16px; font-weight: 600; margin: 0; color: #1a1a1a; }
.camp-list-items {
    flex: 1;
    overflow-y: auto;
    scrollbar-width: thin;
}
.camp-card {
    padding: 14px 16px;
    border-bottom: 1px solid #f1f5f9;
    cursor: pointer;
    transition: background 0.15s;
}
.camp-card:hover { background: #f8fafc; }
.camp-card.active { background: #ede9fe; border-left: 3px solid #7c3aed; }
.camp-card-name {
    font-size: 14px;
    font-weight: 600;
    color: #1a1a1a;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.camp-card-meta {
    font-size: 12px;
    color: #64748b;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}
.camp-badge {
    font-size: 10px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 10px;
    text-transform: uppercase;
}
.camp-badge.active { background: #dcfce7; color: #166534; }
.camp-badge.ended { background: #f1f5f9; color: #64748b; }
.camp-template-badge {
    font-size: 11px;
    color: #7c3aed;
    margin-top: 4px;
    display: flex;
    align-items: center;
    gap: 4px;
}

/* Right panel - detail / empty */
.camp-detail {
    flex: 1;
    display: flex;
    flex-direction: column;
    background: #fafbfc;
    overflow: hidden;
}
.camp-detail-header {
    padding: 16px 20px;
    background: #fff;
    border-bottom: 1px solid #e2e8f0;
}
.camp-detail-header h3 {
    font-size: 16px;
    font-weight: 600;
    margin: 0 0 6px;
    color: #1a1a1a;
}
.camp-detail-stats {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
}
.camp-stat {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    color: #64748b;
}
.camp-stat b { color: #1a1a1a; }
.camp-actions-bar {
    padding: 10px 20px;
    background: #fff;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.camp-filter-btn {
    padding: 5px 12px;
    border-radius: 16px;
    font-size: 12px;
    font-weight: 500;
    border: 1px solid #e2e8f0;
    background: #fff;
    cursor: pointer;
    transition: all 0.15s;
    color: #334155;
}
.camp-filter-btn:hover { border-color: #7c3aed; color: #7c3aed; }
.camp-filter-btn.active { background: #7c3aed; color: #fff; border-color: #7c3aed; }
.camp-customers-list {
    flex: 1;
    overflow-y: auto;
    padding: 12px 20px;
    scrollbar-width: thin;
}
.camp-customer-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    background: #fff;
    border-radius: 8px;
    margin-bottom: 8px;
    border: 1px solid #e2e8f0;
    transition: all 0.15s;
}
.camp-customer-row:hover { box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
.camp-customer-row .cust-checkbox {
    width: 18px; height: 18px; cursor: pointer; accent-color: #7c3aed;
}
.camp-customer-info { flex: 1; min-width: 0; }
.camp-customer-name {
    font-size: 13px;
    font-weight: 600;
    color: #1a1a1a;
}
.camp-customer-phone {
    font-size: 12px;
    color: #64748b;
}
.camp-customer-details {
    font-size: 11px;
    color: #94a3b8;
    display: flex;
    gap: 10px;
}
.camp-customer-actions {
    display: flex;
    gap: 6px;
    align-items: center;
}
.camp-btn {
    padding: 5px 12px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
    border: none;
    cursor: pointer;
    transition: all 0.15s;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.camp-btn-primary { background: #25d366; color: #fff; }
.camp-btn-primary:hover { background: #1da851; }
.camp-btn-secondary { background: #f1f5f9; color: #475569; }
.camp-btn-secondary:hover { background: #e2e8f0; }
.camp-btn-danger { background: #fee2e2; color: #dc2626; }
.camp-btn-danger:hover { background: #fecaca; }
.camp-btn-purple { background: #7c3aed; color: #fff; }
.camp-btn-purple:hover { background: #6d28d9; }
.camp-btn-lg { padding: 8px 18px; font-size: 13px; }
.camp-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.camp-status-badge {
    font-size: 10px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 10px;
    text-transform: uppercase;
}
.camp-status-sent { background: #dcfce7; color: #166534; }
.camp-status-failed { background: #fee2e2; color: #dc2626; }
.camp-status-skipped { background: #f1f5f9; color: #64748b; }
.camp-status-pending { background: #fef3c7; color: #92400e; }
.camp-status-excluded { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
.camp-error-text {
    font-size: 10px;
    color: #dc2626;
    margin-top: 2px;
}
.camp-excluded-reason {
    font-size: 10px;
    color: #92400e;
    margin-top: 2px;
    font-style: italic;
}

/* Empty state */
.camp-empty {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    color: #94a3b8;
}
.camp-empty i { font-size: 48px; margin-bottom: 12px; }
.camp-empty p { font-size: 14px; }

/* Modal */
.camp-modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 1000;
    background: rgba(0,0,0,0.5);
    display: none;
    align-items: center;
    justify-content: center;
}
.camp-modal-overlay.open { display: flex; }
.camp-modal {
    background: #fff;
    border-radius: 12px;
    width: 560px;
    max-width: 95vw;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.15);
}
.camp-modal-header {
    padding: 18px 24px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.camp-modal-header h3 { font-size: 16px; font-weight: 600; margin: 0; }
.camp-modal-body { padding: 24px; }
.camp-modal-footer {
    padding: 16px 24px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}
.camp-form-group { margin-bottom: 16px; }
.camp-form-group label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #475569;
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.camp-form-group input,
.camp-form-group select,
.camp-form-group textarea {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 13px;
    color: #1a1a1a;
    transition: border-color 0.15s;
}
.camp-form-group input:focus,
.camp-form-group select:focus,
.camp-form-group textarea:focus {
    outline: none;
    border-color: #7c3aed;
    box-shadow: 0 0 0 2px rgba(124,58,237,0.1);
}
.camp-form-row {
    display: flex;
    gap: 12px;
}
.camp-form-row .camp-form-group { flex: 1; }
.camp-template-select-btn {
    width: 100%;
    padding: 10px 12px;
    border: 1px dashed #d1d5db;
    border-radius: 6px;
    background: #fafbfc;
    cursor: pointer;
    text-align: left;
    font-size: 13px;
    color: #64748b;
    transition: all 0.15s;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.camp-template-select-btn:hover { border-color: #7c3aed; }
.camp-template-select-btn.selected { border-color: #7c3aed; border-style: solid; color: #7c3aed; background: #faf5ff; }
.camp-template-option {
    padding: 10px 14px;
    cursor: pointer;
    border-bottom: 1px solid #f1f5f9;
    transition: background 0.15s;
}
.camp-template-option:hover { background: #f8fafc; }
.camp-template-option.active { background: #ede9fe; }
.camp-template-option-name { font-size: 13px; font-weight: 600; color: #1a1a1a; }
.camp-template-option-body { font-size: 11px; color: #64748b; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.camp-template-option-meta { font-size: 10px; color: #94a3b8; margin-top: 3px; }
.camp-preview-count {
    padding: 10px 14px;
    background: #ede9fe;
    border-radius: 8px;
    font-size: 13px;
    color: #5b21b6;
    font-weight: 500;
    text-align: center;
}

/* Bulk actions bar */
.camp-bulk-bar {
    padding: 8px 20px;
    background: #ede9fe;
    border-bottom: 1px solid #ddd6fe;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 13px;
    color: #5b21b6;
}

/* Stats modal */
.camp-stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}
.camp-stats-card {
    padding: 16px;
    background: #f8fafc;
    border-radius: 8px;
    text-align: center;
}
.camp-stats-card .value {
    font-size: 24px;
    font-weight: 700;
    color: #1a1a1a;
}
.camp-stats-card .label {
    font-size: 12px;
    color: #64748b;
    margin-top: 4px;
}

/* Spinner */
.camp-spinner {
    display: inline-block;
    width: 16px; height: 16px;
    border: 2px solid rgba(255,255,255,.3);
    border-radius: 50%;
    border-top-color: #fff;
    animation: spin 0.6s linear infinite;
}
.camp-spinner-dark {
    border-color: rgba(0,0,0,.15);
    border-top-color: #7c3aed;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* Select all checkbox */
.camp-select-all {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: #475569;
    cursor: pointer;
}

/* Filter groups */
.camp-filter-group {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px;
    margin-bottom: 10px;
    background: #fafbfc;
}
.camp-filter-group-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
}
.camp-filter-group-title {
    font-size: 12px;
    font-weight: 600;
    color: #475569;
}
.camp-filter-group-count {
    font-size: 11px;
    color: #7c3aed;
    background: #ede9fe;
    padding: 2px 8px;
    border-radius: 10px;
    font-weight: 600;
}
.camp-filter-group-remove {
    background: none;
    border: none;
    color: #dc2626;
    cursor: pointer;
    font-size: 11px;
    padding: 2px 6px;
}
.camp-filter-group-remove:hover {
    text-decoration: underline;
}
.camp-exclude-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 5px 8px;
    font-size: 12px;
    color: #334155;
    border-radius: 4px;
    cursor: pointer;
}
/* Inside a form group the generic uppercase block-label style used to win
   over the row style (product picker rows rendered one word per line). */
.camp-form-group label.camp-exclude-item {
    display: flex; margin-bottom: 0; font-size: 12px; font-weight: 400;
    color: #334155; text-transform: none; letter-spacing: 0;
}
.camp-form-group label.camp-exclude-item input { width: auto; flex: 0 0 auto; margin: 0; }
.camp-exclude-item:hover {
    background: #f1f5f9;
}
.camp-exclude-item input {
    accent-color: #7c3aed;
}
.camp-reply-badge {
    background: #dbeafe;
    color: #1e40af;
    font-size: 10px;
    font-weight: 600;
    padding: 2px 6px;
    border-radius: 8px;
    margin-left: 4px;
}
/* Oct-2026 — clickable result tiles, engagement badges, timeline */
.camp-tile {
    flex: 1; min-width: 92px; padding: 6px 8px; background: #fff;
    border: 1px solid #e2e8f0; border-radius: 6px; cursor: pointer;
    text-align: left; font: inherit; transition: border-color .15s, box-shadow .15s;
}
.camp-tile:hover { border-color: #a78bfa; }
.camp-tile.active { border-color: #7c3aed; box-shadow: 0 0 0 2px #ede9fe; }
.camp-tile .v { font-size: 16px; font-weight: 700; line-height: 1.1; }
.camp-tile .l { font-size: 10px; color: #64748b; margin-top: 2px; }
.camp-eng-badge {
    font-size: 10px; font-weight: 600; padding: 2px 6px; border-radius: 8px;
    margin-left: 4px; white-space: nowrap;
}
.camp-eng-tap   { background: #f3e8ff; color: #6b21a8; }
.camp-eng-conv  { background: #e0f2fe; color: #075985; }
.camp-eng-wait  { background: #fef3c7; color: #92400e; }
.camp-name-link { cursor: pointer; }
.camp-name-link:hover { color: #7c3aed; text-decoration: underline; }
.camp-tl-item { display: flex; gap: 10px; padding: 7px 0; border-bottom: 1px dashed #eef2f7; font-size: 12px; }
.camp-tl-time { width: 118px; flex-shrink: 0; color: #64748b; font-size: 11px; }
.camp-tl-who  { width: 92px; flex-shrink: 0; font-weight: 600; font-size: 11px; }
.camp-tl-text { flex: 1; color: #1e293b; white-space: pre-wrap; word-break: break-word; }
.camp-shopify-badge {
    background: #dcfce7;
    color: #166534;
    font-size: 10px;
    font-weight: 600;
    padding: 2px 6px;
    border-radius: 8px;
    margin-left: 4px;
    border: 1px solid #86efac;
    white-space: nowrap;
}
.camp-match-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 3px;
    margin-top: 4px;
}
.camp-match-tag {
    display: inline-block;
    background: #f5f3ff;
    color: #6d28d9;
    border: 1px solid #e9d5ff;
    font-size: 10px;
    font-weight: 500;
    padding: 1px 7px;
    border-radius: 10px;
    line-height: 1.5;
    white-space: nowrap;
}
.camp-match-tag.qurbani {
    background: #fef3c7;
    color: #92400e;
    border-color: #fde68a;
}
.camp-match-tag.activity {
    background: #dcfce7;
    color: #15803d;
    border-color: #bbf7d0;
}
.camp-match-tag.city {
    background: #dbeafe;
    color: #1e40af;
    border-color: #bfdbfe;
}
</style>
@endpush

@section('content')
<div class="camp-page">
    <!-- LEFT: Campaign list -->
    <div class="camp-list">
        <div class="camp-list-header" style="flex-wrap:wrap;">
            <h3 style="flex:1;">Campaigns</h3>
            {{-- Direct entry to per-template results. Without this the only way
                 in is via a campaign, which buries the one view that answers
                 "how does this template perform?". --}}
            <button class="camp-btn camp-btn-secondary" onclick="openTemplateResults('')" title="Compare every campaign per template, with combined results counting each customer once.">
                <i class="ki-filled ki-copy" style="font-size:13px;"></i> By Template
            </button>
            <button class="camp-btn camp-btn-purple" onclick="openCreateModal()">
                + New
            </button>
            <div style="position:relative;width:100%;">
                <input type="text" id="campListSearch" oninput="handleCampaignSearch(this.value)"
                       placeholder="Search campaigns or templates..."
                       style="width:100%;padding:6px 26px 6px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;">
                <span id="campListSearchClear" onclick="handleCampaignSearch('')"
                      style="display:none;position:absolute;right:8px;top:50%;transform:translateY(-50%);cursor:pointer;color:#94a3b8;font-size:14px;line-height:1;"
                      title="Clear">&times;</span>
            </div>
        </div>
        <div class="camp-list-items" id="campListItems">
            <div style="text-align: center; padding: 40px;">
                <div class="camp-spinner camp-spinner-dark"></div>
            </div>
        </div>
        {{-- Always-visible allowance footer. The tier is the single most common
             reason a big send stalls, so it should never need a click to find. --}}
        <div id="campQuotaFooter" style="border-top:1px solid #e2e8f0;padding:8px 12px;background:#fafbfc;"></div>
    </div>

    <!-- RIGHT: Overview (landing) or campaign detail -->
    <div class="camp-detail" id="campDetail">
        <div style="text-align:center;padding:60px;">
            <div class="camp-spinner camp-spinner-dark"></div>
        </div>
    </div>
</div>

<!-- Create Campaign Modal -->
<div class="camp-modal-overlay" id="createModal">
    <div class="camp-modal">
        <div class="camp-modal-header">
            <h3>New Campaign</h3>
            <button onclick="closeCreateModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">&times;</button>
        </div>
        <div class="camp-modal-body">
            <div class="camp-form-group">
                <label>Campaign Name</label>
                <input type="text" id="campName" placeholder="e.g. Eid Offer 2026">
            </div>

            <div class="camp-form-group">
                <label>WhatsApp Template</label>
                <button type="button" class="camp-template-select-btn" id="templateSelectBtn" onclick="openTemplatePicker()">
                    <span id="templateSelectLabel">Select a template...</span>
                    <i class="ki-filled ki-down" style="font-size:12px;"></i>
                </button>
            </div>

            <div class="camp-form-group">
                <label>Notes (optional)</label>
                <textarea id="campNotes" rows="2" placeholder="Internal notes about this campaign"></textarea>
            </div>

            <hr style="border:none;border-top:1px solid #e2e8f0;margin:20px 0;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <h4 style="font-size:13px;font-weight:600;color:#475569;margin:0;">Customer Filter Groups</h4>
                <button type="button" class="camp-btn camp-btn-secondary" onclick="addFilterGroup()" style="padding:4px 10px;font-size:11px;">+ Add Group</button>
            </div>
            <div style="font-size:11px;color:#64748b;margin-bottom:12px;line-height:1.4;">
                Customers who match ANY group will be included (union). Duplicates are removed automatically, so you can combine e.g. "Qurbani 2025" + "Qurbani 2024" + "Active 90 days" without the same person being messaged twice.
            </div>
            <div id="filterGroupsContainer"></div>

            <hr style="border:none;border-top:1px solid #e2e8f0;margin:20px 0;">
            <h4 style="font-size:13px;font-weight:600;color:#475569;margin-bottom:12px;">Exclude From Earlier Campaigns</h4>
            <div class="camp-form-group">
                <label>Skip customers already sent in these campaigns</label>
                <div id="excludeCampaignsBox" style="border:1px solid #d1d5db;border-radius:6px;padding:6px;max-height:120px;overflow-y:auto;background:#fafbfc;">
                    <div style="font-size:12px;color:#94a3b8;padding:6px;">Loading campaigns...</div>
                </div>
                <div style="font-size:11px;color:#64748b;margin-top:4px;">Customers who were already <b>sent</b> a message in any of the selected campaigns will be removed from the new list. Useful when you want to message "everyone we haven't already reached".</div>
            </div>

            <hr style="border:none;border-top:1px solid #e2e8f0;margin:20px 0;">
            <h4 style="font-size:13px;font-weight:600;color:#475569;margin-bottom:12px;">Sort &amp; Tracking</h4>

            {{-- Send order matters: the send dialog messages "the first N", and
                 N is taken from this order. Sorting by spend uses LIVE totals,
                 not the stale stored column. --}}
            <div class="camp-form-row">
                <div class="camp-form-group">
                    <label title="Also decides who gets messaged first when you send in batches.">Send order</label>
                    <select id="filterSortBy">
                        <option value="last_order_date">Last order date</option>
                        <option value="spent">Lifetime spend (live)</option>
                        <option value="orders">Number of orders (live)</option>
                        <option value="first_order_date">First order date</option>
                        <option value="created_at">Customer added</option>
                    </select>
                </div>
                <div class="camp-form-group">
                    <label>Direction</label>
                    <select id="filterSortDir">
                        <option value="desc">Highest / newest first</option>
                        <option value="asc">Lowest / oldest first</option>
                    </select>
                </div>
            </div>

            {{-- tracking_type decides what counts as a CONVERSION for this
                 campaign's results. The old dropdown offered values the column
                 rejected under strict SQL mode, which made campaign creation
                 fail outright; these three are the real, supported types. --}}
            <div class="camp-form-row">
                <div class="camp-form-group">
                    <label>What counts as success?</label>
                    <select id="filterTrackingType" onchange="onTrackingTypeChange(this.value)">
                        <option value="general">Any order they place</option>
                        <option value="category">Only orders with a category (e.g. Frozen)</option>
                        <option value="products">Only orders with specific products</option>
                        <option value="app_orders">📱 Only orders placed through the customer app</option>
                    </select>
                </div>
                <div class="camp-form-group">
                    <label title="How long after the message an order still counts.">Count orders for (days)</label>
                    <input type="number" id="filterTrackingDays" value="30" min="1" max="365">
                </div>
            </div>

            {{-- Shown only for the category tracking type (Oct-2026). Same
                 grouping as HQ: Frozen = Khaas + Table Talk products. --}}
            <div class="camp-form-group" id="trackedCategoriesBox" style="display:none;">
                <label>Which categories count?</label>
                <div id="trackedCategoriesList" style="border:1px solid #d1d5db;border-radius:6px;padding:6px;max-height:150px;overflow-y:auto;background:#fafbfc;"></div>
            </div>

            {{-- Shown only for the products tracking type. --}}
            <div class="camp-form-group" id="trackedProductsBox" style="display:none;">
                <label>Which products count?</label>
                <div style="display:flex;gap:6px;margin-bottom:6px;">
                    <input type="text" id="productSearchInput" placeholder="Search products or a type, e.g. Khaas..." oninput="searchProducts(this.value)" style="flex:1;">
                    <button type="button" class="camp-btn camp-btn-secondary" style="padding:3px 10px;font-size:11px;" onclick="selectAllShownProducts()">Select all shown</button>
                </div>
                <div id="trackedProductsList" style="border:1px solid #d1d5db;border-radius:6px;padding:6px;max-height:150px;overflow-y:auto;background:#fafbfc;">
                    <div style="font-size:12px;color:#94a3b8;padding:6px;">Type above to find products.</div>
                </div>
                <div id="trackedProductsCount" style="font-size:11px;color:#64748b;margin-top:4px;"></div>
            </div>

            {{-- App-install campaign helper. Tracking app orders only makes
                 sense alongside a "not on app" audience, so offer to set that
                 up in one click rather than leaving the two to be wired by hand. --}}
            <div id="appCampaignHint" style="display:none;margin-top:8px;padding:10px;background:#faf5ff;border:1px solid #e9d5ff;border-radius:6px;font-size:11px;color:#5b21b6;line-height:1.5;">
                <b>App-install campaign.</b> Success = the customer places an order <b>in the app</b> within the window.
                <div style="margin-top:6px;">
                    <button type="button" class="camp-btn camp-btn-secondary" style="padding:3px 10px;font-size:11px;" onclick="applyAppInstallPreset()">Target only people not on the app</button>
                </div>
                <div style="margin-top:6px;color:#7c3aed;">Note: app orders are only detected on orders placed <b>after</b> app-source tracking went live, so start-of-campaign counts begin from zero.</div>
            </div>

            <div class="camp-form-row" style="margin-top:6px;">
                <div class="camp-form-group" style="flex:1;">
                    <label title="Pre-fills the batch size in the Send dialog. You confirm or change it on every send.">Default batch size per send session</label>
                    <input type="number" id="filterSessionLimit" value="100" min="1" max="100000">
                    <div style="font-size:11px;color:#64748b;margin-top:4px;">
                        Sends go out in confirmed batches instead of all at once. 100 is a safe default against a 2,000/day WhatsApp limit.
                    </div>
                </div>
            </div>

            {{-- Template-dedup guard. When > 0, customers who already
                 received the selected template in the last N days get
                 inserted as 'skipped' instead of 'pending' so they don't
                 get the same message twice in a short window. Set to 0
                 to turn the guard off and send to everyone regardless
                 of prior sends. --}}
            <div class="camp-form-row" style="margin-top:6px;">
                <div class="camp-form-group" style="flex:1;">
                    <label title="Skip customers who already received the selected template within this many days. 0 disables the check.">
                        🚫 Don't re-send if template sent within (days)
                    </label>
                    <input type="number" id="filterDedupDays" value="30" min="0" max="365" placeholder="30">
                    <div style="font-size:11px;color:#64748b;margin-top:4px;">
                        Uses WhatsApp send history. Set to <b>0</b> to disable.
                    </div>
                </div>
            </div>

            <div style="display:flex;gap:10px;align-items:center;margin-top:12px;">
                <button class="camp-btn camp-btn-secondary" onclick="previewCount()" id="previewBtn">
                    Preview Count
                </button>
                <div id="previewResult" style="display:none;flex:1;"></div>
            </div>
        </div>
        <div class="camp-modal-footer">
            <button class="camp-btn camp-btn-secondary" onclick="closeCreateModal()">Cancel</button>
            <button class="camp-btn camp-btn-purple camp-btn-lg" onclick="createCampaign()" id="createBtn">
                Create Campaign
            </button>
        </div>
    </div>
</div>

<!-- Add Customers To Existing Campaign Modal -->
<div class="camp-modal-overlay" id="addCustomersModal">
    <div class="camp-modal">
        <div class="camp-modal-header">
            <h3 id="addCustomersTitle">Add More Customers</h3>
            <button onclick="closeAddCustomersModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">&times;</button>
        </div>
        <div class="camp-modal-body">
            <div style="font-size:12px;color:#64748b;margin-bottom:14px;line-height:1.4;">
                Build a filter for the customers you want to add. Anyone who's already in this campaign (at any status) will be skipped automatically — only truly new customers will be added as <b>Pending</b>.
            </div>

            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <h4 style="font-size:13px;font-weight:600;color:#475569;margin:0;">Filter Groups</h4>
                <button type="button" class="camp-btn camp-btn-secondary" onclick="addFilterGroup('addMore')" style="padding:4px 10px;font-size:11px;">+ Add Group</button>
            </div>
            <div id="filterGroupsContainer_addMore"></div>

            <hr style="border:none;border-top:1px solid #e2e8f0;margin:20px 0;">
            <h4 style="font-size:13px;font-weight:600;color:#475569;margin-bottom:12px;">Exclude</h4>
            <div class="camp-form-group">
                <label>Skip customers sent in these earlier campaigns</label>
                <div id="excludeCampaignsBox_addMore" style="border:1px solid #d1d5db;border-radius:6px;padding:6px;max-height:120px;overflow-y:auto;background:#fafbfc;">
                    <div style="font-size:12px;color:#94a3b8;padding:6px;">Loading campaigns...</div>
                </div>
            </div>

            {{-- Same dedup-window control as the Create modal so the user
                 can refresh the rule when extending an existing campaign.
                 The campaign already knows which template it uses — we
                 just need the window value. --}}
            <div class="camp-form-row" style="margin-top:12px;">
                <div class="camp-form-group" style="flex:1;">
                    <label title="Skip customers who already received this campaign's template within this many days. 0 disables the check.">
                        🚫 Don't re-send if template sent within (days)
                    </label>
                    <input type="number" id="filterDedupDays_addMore" value="30" min="0" max="365" placeholder="30">
                </div>
            </div>

            <div style="display:flex;gap:10px;align-items:center;margin-top:12px;">
                <button class="camp-btn camp-btn-secondary" onclick="previewAddCustomers()" id="previewAddBtn">
                    Preview Count
                </button>
                <div id="previewAddResult" style="display:none;flex:1;"></div>
            </div>
        </div>
        <div class="camp-modal-footer">
            <button class="camp-btn camp-btn-secondary" onclick="closeAddCustomersModal()">Cancel</button>
            <button class="camp-btn camp-btn-purple camp-btn-lg" onclick="confirmAddCustomers()" id="addCustomersBtn">
                Add Customers
            </button>
        </div>
    </div>
</div>

<!-- Template Picker Modal -->
<div class="camp-modal-overlay" id="templatePickerModal">
    <div class="camp-modal" style="width:480px;">
        <div class="camp-modal-header">
            <h3>Select Template</h3>
            <button onclick="closeTemplatePicker()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">&times;</button>
        </div>
        <div class="camp-modal-body" style="padding:0;max-height:400px;overflow-y:auto;">
            <div id="templatePickerList">
                <div style="text-align:center;padding:30px;">
                    <div class="camp-spinner camp-spinner-dark"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Stats Modal -->
<div class="camp-modal-overlay" id="statsModal">
    <div class="camp-modal" style="width:620px;">
        <div class="camp-modal-header">
            <h3>Campaign Results</h3>
            <button onclick="closeStatsModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">&times;</button>
        </div>
        <div class="camp-modal-body" id="statsBody">
            <div style="text-align:center;padding:30px;">
                <div class="camp-spinner camp-spinner-dark"></div>
            </div>
        </div>
    </div>
</div>

{{-- Oct-2026 — one customer's story after the message: receipts, every
     WhatsApp message in the window (who sent it), and the orders. --}}
<div class="camp-modal-overlay" id="timelineModal">
    <div class="camp-modal" style="width:680px;">
        <div class="camp-modal-header">
            <h3 id="timelineTitle">Customer timeline</h3>
            <button onclick="closeTimeline()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">&times;</button>
        </div>
        <div class="camp-modal-body" id="timelineBody"></div>
    </div>
</div>

{{-- Oct-2026 — change what counts as "Ordered" on an existing campaign. --}}
<div class="camp-modal-overlay" id="trackingModal">
    <div class="camp-modal" style="width:560px;">
        <div class="camp-modal-header">
            <h3>Change tracking</h3>
            <button onclick="closeTrackingModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">&times;</button>
        </div>
        <div class="camp-modal-body">
            <div style="font-size:12px;color:#475569;margin-bottom:12px;line-height:1.5;">
                This only changes how results are <b>counted</b> — nobody is messaged, added or removed. Results update straight away, including for messages already sent.
            </div>
            <div class="camp-form-row">
                <div class="camp-form-group">
                    <label>What counts as success?</label>
                    <select id="trkEdit_filterTrackingType" onchange="onTrackingTypeChange(this.value, 'edit')">
                        <option value="general">Any order they place</option>
                        <option value="category">Only orders with a category (e.g. Frozen)</option>
                        <option value="products">Only orders with specific products</option>
                        <option value="app_orders">📱 Only orders placed through the customer app</option>
                    </select>
                </div>
                <div class="camp-form-group">
                    <label title="How long after the message an order still counts.">Count orders for (days)</label>
                    <input type="number" id="trkEdit_filterTrackingDays" value="30" min="1" max="365">
                </div>
            </div>
            <div class="camp-form-group" id="trkEdit_trackedCategoriesBox" style="display:none;">
                <label>Which categories count?</label>
                <div id="trkEdit_trackedCategoriesList" style="border:1px solid #d1d5db;border-radius:6px;padding:6px;max-height:180px;overflow-y:auto;background:#fafbfc;"></div>
            </div>
            <div class="camp-form-group" id="trkEdit_trackedProductsBox" style="display:none;">
                <label>Which products count?</label>
                <div style="display:flex;gap:6px;margin-bottom:6px;">
                    <input type="text" id="trkEdit_productSearchInput" placeholder="Search products or a type, e.g. Khaas..." oninput="searchProducts(this.value, 'edit')" style="flex:1;">
                    <button type="button" class="camp-btn camp-btn-secondary" style="padding:3px 10px;font-size:11px;" onclick="selectAllShownProducts('edit')">Select all shown</button>
                </div>
                <div id="trkEdit_trackedProductsList" style="border:1px solid #d1d5db;border-radius:6px;padding:6px;max-height:180px;overflow-y:auto;background:#fafbfc;"></div>
                <div id="trkEdit_trackedProductsCount" style="font-size:11px;color:#64748b;margin-top:4px;"></div>
            </div>
        </div>
        <div class="camp-modal-footer">
            <button class="camp-btn camp-btn-secondary" onclick="closeTrackingModal()">Cancel</button>
            <button class="camp-btn camp-btn-primary" id="trkEdit_saveBtn" onclick="saveTracking()">Save</button>
        </div>
    </div>
</div>

{{-- ==================================================================
     SEND DIALOG
     Replaces the old browser confirm(). A campaign send spends real money
     and can't be undone, so before anything goes out the operator sees:
     how many will be messaged THIS session, how much of the WhatsApp daily
     allowance that uses, how long it will take, and how many sessions
     remain after it. Foreground vs background is an explicit choice.
     ================================================================== --}}
<div class="camp-modal-overlay" id="sendModal">
    <div class="camp-modal" style="width:560px;">
        <div class="camp-modal-header">
            <h3 id="sendModalTitle">Send Messages</h3>
            <button onclick="closeSendDialog()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">&times;</button>
        </div>
        <div class="camp-modal-body" id="sendModalBody">
            <div style="text-align:center;padding:30px;"><div class="camp-spinner camp-spinner-dark"></div></div>
        </div>
        <div class="camp-modal-footer" id="sendModalFooter"></div>
    </div>
</div>

{{-- Per-template results: every campaign that used a template side by side,
     plus one combined block that counts each customer only once. --}}
<div class="camp-modal-overlay" id="templateResultsModal">
    <div class="camp-modal" style="width:760px;">
        <div class="camp-modal-header">
            <h3 id="templateResultsTitle">Results by Template</h3>
            <button onclick="closeTemplateResults()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">&times;</button>
        </div>
        <div class="camp-modal-body" id="templateResultsBody">
            <div style="text-align:center;padding:30px;"><div class="camp-spinner camp-spinner-dark"></div></div>
        </div>
    </div>
</div>
@endsection

@push('demo1_js')
<script>
const csrf = '{{ csrf_token() }}';
let campaigns = [];
let activeCampaignId = null;
let activeCampaign = null;
let campaignCustomers = [];
let campaignCounts = null;
let campaignEngagement = null;  // Ordered/Tapped/Conversation/Waiting — loadEngagement()
let campaignTracking = null;    // what "Ordered" means for this campaign
let customerStatusFilter = 'pending';
// Client-side in-tab filters that narrow the currently-loaded campaign
// customer list. Live search across name + phone + city, plus a Shopify
// quick-filter chip. Both reset when switching tabs or campaigns so a user
// never sees a mysteriously-empty list because they forgot they had a
// filter on. Nothing here changes what the backend returns — we only
// hide rows in the DOM — so selection, counts and retry-all are
// unaffected unless we explicitly use the filtered set.
let detailSearch = '';
let detailSourceFilter = 'any'; // 'any' | 'shopify' | 'non_shopify'
let selectedCustomerIds = [];
let waTemplates = [];
let selectedTemplateName = '';
let bulkSending = false;

// --- Send control (Jul-2026) ---------------------------------------------
// quota  = WhatsApp messaging-tier allowance for the rolling 24h, refreshed on
//          every list/detail load so the send dialog never guesses.
// sendRun= the in-progress session row (target vs attempted) for the progress bar.
// detailPagination = server-side paging; big campaigns no longer ship every row.
let waQuota = null;
let sendRun = null;
let sendState = 'idle';
let sendPausedReason = null;
let campaignSessionLimit = 100;
let campaignEligible = 0;
let sendRunHistory = [];
let detailPagination = { page: 1, per_page: 100, total: 0, pages: 0 };
// The list's current sort. null = the campaign's stored send order; the server
// echoes back what it actually applied. Changing it also PERSISTS as the
// campaign's send order (for managers), so "send the first 100" always means
// the first 100 of the order on screen.
let detailSort = null;   // {by, dir}
let sendPollTimer = null;
// Pending choices in the send dialog.
let sendDialog = { open: false, mode: 'foreground', limit: 100, includeFailed: false, useSelection: false };
// Per-template results view.
let templateResults = null;
let templateList = [];
let productOptions = [];

// --- Landing view --------------------------------------------------------
// overviewData powers the right panel when no campaign is selected: allowance,
// anything sending right now, per-template results, and a needs-attention list.
let overviewData = null;
let campaignSearch = '';
// Finished campaigns collapse behind one row in the list — they cannot send and
// need no decision, so showing them full-size made the list read as a pile of
// pending work. Session-only; every visit starts focused on live campaigns.
let showEndedCampaigns = false;
let staleDays = 60;
let overviewPollTimer = null;

// Filter-group state keyed by modal scope ('create' or 'addMore').
// Each scope holds an array of filter group objects. Each group is a plain
// object like { activity: '30day', qurbani_year: 2025, ... } — empty keys
// mean "no constraint on that axis".
const filterGroups = { create: [ {} ], addMore: [ {} ] };
let qurbaniYears = [];        // cached list of distinct qurbani years for dropdowns
let cityOptions = [];         // cached city list so we can render both modals
let addCustomersCampaignId = null;

// Every call on this page goes through here. It always resolves to an object
// with a `success` flag — it never throws — because the old version did
// `.then(r => r.json())`, and a non-JSON response (a 404 page, a 500 stack
// trace, or the login redirect after a session expires) makes json() throw. That
// rejection was unhandled, so the caller's render never ran and the panel sat on
// its spinner forever. Turning it into a normal failed result means every caller
// can show a real error instead.
async function apiFetch(url, opts = {}) {
    const defaults = {
        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
    };
    let res;
    try {
        res = await fetch(url, { ...defaults, ...opts });
    } catch (e) {
        return { success: false, message: 'Could not reach the server. Check your connection.' };
    }

    const text = await res.text();
    try {
        return JSON.parse(text);
    } catch (e) {
        // Not JSON. Say something useful about WHY rather than "unexpected token".
        if (res.status === 401 || res.status === 419 || /<form[^>]*login/i.test(text)) {
            return { success: false, message: 'Your session has expired. Please reload the page and sign in again.' };
        }
        if (res.status === 404) {
            return { success: false, message: 'That feature is not available on this server yet (404). It may need the latest upload.' };
        }
        return { success: false, message: `Server error (${res.status}). Check storage/logs/laravel.log.` };
    }
}

// ==========================================================================
// DATA LOADING
// ==========================================================================

// Every mutation in this page already calls loadCampaigns() afterwards, so
// chaining the overview refresh here means the landing view, the allowance
// footer and the attention list can never go stale — including from any future
// action someone adds. Pass false only to avoid a redundant second fetch.
//
// RULE for this page (learned twice now): a fetch that fails or never returns
// must NEVER leave a spinner on screen. A spinner and a silent failure look
// identical to the operator — every load path ends in rendered content OR a
// visible error with a retry.
async function loadCampaigns(refreshOverview = true) {
    let data;
    try {
        data = await apiFetch('/campaigns/list');
    } catch (e) {
        data = { success: false, message: 'Could not reach the server.' };
    }

    if (data.success) {
        campaigns = data.campaigns || [];
        if (data.quota) waQuota = data.quota;
        if (data.stale_days) staleDays = data.stale_days;
        renderCampaignList();
        renderQuotaFooter();
    } else if (!campaigns.length) {
        // Only replace the list when we have nothing to show — a failed
        // refresh must not wipe a list the operator is already using.
        document.getElementById('campListItems').innerHTML = `
            <div style="text-align:center;padding:30px;color:#94a3b8;font-size:13px;">
                Couldn't load campaigns.<br>
                <button class="camp-btn camp-btn-secondary" style="margin-top:10px;" onclick="loadCampaigns()">Try again</button>
            </div>`;
    }
    if (refreshOverview) loadOverview();   // fire and forget
}

// The landing view. Also refreshed after actions that change it (create, end,
// send) so the attention list and template numbers never go stale on screen.
async function loadOverview(render = true) {
    let data;
    try {
        data = await apiFetch('/campaigns/overview');
    } catch (e) {
        data = { success: false, message: 'Could not reach the server.' };
    }

    if (data.success) {
        overviewData = data.overview;
        if (data.quota) waQuota = data.quota;
        renderQuotaFooter();
        // Only paint if the user is still on the landing view — they may have
        // clicked into a campaign while this was in flight.
        if (render && !activeCampaignId) renderOverview();
        manageOverviewPolling();
        return;
    }

    // Failed. If a previous overview is already on screen, keep it (stale
    // beats blank). Otherwise replace the spinner with an honest error —
    // this exact path used to leave the first-load spinner forever.
    if (render && !activeCampaignId && !overviewData) {
        document.getElementById('campDetail').innerHTML = `
        <div style="display:flex;align-items:center;justify-content:center;height:100%;">
            <div style="text-align:center;max-width:340px;">
                <div style="font-size:13px;color:#991b1b;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px;">
                    Couldn't load the overview.${data.message ? '<br><span style="font-size:11px;color:#b91c1c;">' + esc(data.message) + '</span>' : ''}
                </div>
                <button class="camp-btn camp-btn-secondary" style="margin-top:12px;" onclick="loadOverview()">Try again</button>
            </div>
        </div>`;
    }
}

// Poll only while something is actually sending in the background, so an idle
// landing page makes no requests.
function manageOverviewPolling() {
    const running = !!(overviewData && overviewData.running && overviewData.running.length);
    if (running && !activeCampaignId && !overviewPollTimer) {
        // loadCampaigns() chains loadOverview(), so one call refreshes both.
        overviewPollTimer = setInterval(() => {
            if (activeCampaignId) { manageOverviewPolling(); return; }
            loadCampaigns();
        }, 6000);
    } else if ((!running || activeCampaignId) && overviewPollTimer) {
        clearInterval(overviewPollTimer);
        overviewPollTimer = null;
    }
}

// Back to the landing view from a campaign.
function showOverview() {
    activeCampaignId = null;
    activeCampaign = null;
    campaignCustomers = [];
    campaignCounts = null;
    selectedCustomerIds = [];
    if (sendPollTimer) { clearInterval(sendPollTimer); sendPollTimer = null; }
    renderCampaignList();
    renderOverview();          // paint immediately from cached data
    loadCampaigns();           // then refresh list + overview together
}

function handleCampaignSearch(val) {
    campaignSearch = val || '';
    const input = document.getElementById('campListSearch');
    if (input && input.value !== campaignSearch) input.value = campaignSearch;
    const clear = document.getElementById('campListSearchClear');
    if (clear) clear.style.display = campaignSearch ? 'block' : 'none';
    renderCampaignList();
}

// Instant feedback while the detail loads. Shows the campaign's name from the
// list data we already have, so the header appears before the fetch returns.
function renderDetailSkeleton(id) {
    const c = campaigns.find(x => x.id == id);
    const el = document.getElementById('campDetail');
    const bar = (w) => `<div style="height:10px;width:${w};background:#eef2f6;border-radius:4px;margin-bottom:8px;"></div>`;
    el.innerHTML = `
    <div class="camp-detail-header">
        <h3>${c ? esc(c.name) : 'Loading…'}
            ${c ? `<span class="camp-badge ${c.status}">${c.status}</span>` : ''}</h3>
        ${c && (c.template_display_name || c.wa_template_name)
            ? `<div class="camp-template-badge" style="margin-top:4px;"><i class="ki-filled ki-message-text" style="font-size:12px;"></i> Template: ${esc(c.template_display_name || c.wa_template_name)}</div>`
            : ''}
        <div class="camp-detail-stats" style="margin-top:10px;">
            <div class="camp-stat"><span class="camp-spinner camp-spinner-dark"></span> <span style="margin-left:6px;color:#94a3b8;">Loading customers…</span></div>
        </div>
    </div>
    <div style="padding:20px;">
        ${bar('60%')}${bar('85%')}${bar('75%')}${bar('90%')}${bar('50%')}
    </div>`;
}

function renderDetailError(id, message) {
    const c = campaigns.find(x => x.id == id);
    document.getElementById('campDetail').innerHTML = `
    <div class="camp-detail-header">
        <h3><a href="#" onclick="showOverview();return false;" style="color:#7c3aed;text-decoration:none;font-size:14px;margin-right:6px;">&larr;</a>
            ${c ? esc(c.name) : 'Campaign'}</h3>
    </div>
    <div style="padding:24px;">
        <div style="padding:12px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;font-size:13px;color:#991b1b;">
            ${esc(message)}
            <div style="margin-top:8px;">
                <button class="camp-btn camp-btn-secondary" onclick="loadCampaignDetail(${id}, customerStatusFilter)">Try again</button>
                <button class="camp-btn camp-btn-secondary" onclick="showOverview()">Back to overview</button>
            </div>
        </div>
    </div>`;
}

// Guards against fast clicking. Every load takes a ticket; when a response
// arrives we drop it if a newer load has started. Without this, clicking A then
// B could leave A's slower response painting over B — which reads as "the page
// is slow and showed me the wrong campaign".
let detailLoadSeq = 0;

async function loadCampaignDetail(id, statusFilter, page) {
    const sameCampaign = activeCampaignId == id;
    activeCampaignId = id;
    const myLoad = ++detailLoadSeq;

    // Changing tab or campaign resets paging + selection; an explicit page
    // change keeps the tab. Without this, paging would silently drop the
    // operator back to page 1 on every re-render.
    const tabChanged = statusFilter !== undefined && statusFilter !== customerStatusFilter;
    if (statusFilter !== undefined) customerStatusFilter = statusFilter || 'pending';
    if (!sameCampaign || tabChanged) {
        selectedCustomerIds = [];
        detailSearch = '';
        detailSourceFilter = 'any';
        detailPagination.page = 1;
    }
    // Switching campaigns drops back to that campaign's own stored sort;
    // switching tabs within one campaign keeps the chosen sort.
    if (!sameCampaign) detailSort = null;
    if (page) detailPagination.page = page;

    // Paint immediately so the click feels answered. Only when the user is
    // actually navigating (different campaign, tab or page) — a silent refresh
    // after a send must not blank a panel the operator is reading.
    const navigating = !sameCampaign || tabChanged || !!page;
    if (navigating) {
        renderCampaignList();                    // highlight the clicked card now
        renderDetailSkeleton(id);
    }

    let qs = `status=${encodeURIComponent(customerStatusFilter)}&page=${detailPagination.page}&per_page=${detailPagination.per_page}`;
    if (detailSort) qs += `&sort_by=${encodeURIComponent(detailSort.by)}&sort_dir=${encodeURIComponent(detailSort.dir)}`;
    let data;
    try {
        data = await apiFetch(`/campaigns/${id}?${qs}`);
    } catch (e) {
        if (myLoad === detailLoadSeq) renderDetailError(id, 'Could not reach the server.');
        return;
    }

    // A newer click already started — discard this stale response.
    if (myLoad !== detailLoadSeq) return;

    if (!data.success) {
        // Previously a failed load left the old panel on screen, so a 500 looked
        // exactly like "still loading". Say what happened instead.
        renderDetailError(id, data.message || 'This campaign could not be loaded.');
        return;
    }
    activeCampaign = data.campaign;
    campaignCustomers = data.customers || [];
    campaignCounts = data.counts;
    if (!sameCampaign) { campaignEngagement = null; campaignTracking = null; }
    if (data.pagination) detailPagination = { ...detailPagination, ...data.pagination };
    if (data.sort) detailSort = { by: data.sort.by, dir: data.sort.dir };
    if (data.quota) waQuota = data.quota;
    sendRun = data.run || null;
    sendState = data.campaign.send_state || 'idle';
    sendPausedReason = data.campaign.send_paused_reason || null;
    campaignSessionLimit = data.session_limit || (waQuota && waQuota.default_session_limit) || 100;
    campaignEligible = data.eligible || 0;
    sendRunHistory = data.runs || [];
    renderCampaignDetail();
    renderCampaignList();
    renderQuotaFooter();
    managePolling();
    loadEngagement(id, myLoad);
}

// Ordered / Tapped / Conversation / Waiting need orders + the chat log, so
// they arrive a moment after the list (server caches them 60s). The tiles
// show "…" until then rather than a misleading 0.
async function loadEngagement(id, loadSeq) {
    let data;
    try { data = await apiFetch(`/campaigns/${id}/engagement`); } catch (e) { return; }
    if (loadSeq !== detailLoadSeq || activeCampaignId != id || !data || !data.success) return;
    campaignEngagement = data.engagement;
    campaignTracking = data.tracking;
    const strip = document.getElementById('campFunnelStrip');
    if (strip) strip.outerHTML = renderFunnelStrip(campaignCounts || {});
}

// The tile lists live alongside the status tabs; this is their label.
const TILE_FILTER_LABELS = {
    delivered: 'Delivered', read: 'Read', replied: 'Replied', tapped: 'Tapped a button',
    conversation: 'Had a conversation', waiting: 'Waiting on us', ordered: 'Ordered',
};

function trackingLabel(t) {
    if (!t) return '';
    if (t.type === 'category') return (t.categories || []).join(' / ') + ' orders only';
    if (t.type === 'products') return `orders with ${t.product_count} picked product${t.product_count === 1 ? '' : 's'}`;
    if (t.type === 'app_orders') return 'app orders only';
    return 'any order';
}

// While a background send is running, poll a small status endpoint so the
// operator sees progress without refreshing. Stops itself the moment the run
// finishes so an idle page makes no requests.
function managePolling() {
    const shouldPoll = sendState === 'running' || (sendRun && !sendRun.finished_at);
    if (shouldPoll && !sendPollTimer) {
        sendPollTimer = setInterval(pollSendStatus, 5000);
    } else if (!shouldPoll && sendPollTimer) {
        clearInterval(sendPollTimer);
        sendPollTimer = null;
    }
}

async function pollSendStatus() {
    if (!activeCampaignId) return;
    try {
        const data = await apiFetch(`/campaigns/${activeCampaignId}/send-status`);
        if (!data.success) return;
        const wasRunning = sendState === 'running';
        sendState = data.send_state || 'idle';
        sendPausedReason = data.paused_reason || null;
        sendRun = data.run || null;
        campaignCounts = data.counts || campaignCounts;
        campaignEligible = data.eligible || 0;
        if (data.quota) waQuota = data.quota;
        renderCampaignDetail();
        managePolling();
        // When a background run finishes, pull the full list once so the rows
        // and run history reflect the final state.
        if (wasRunning && sendState !== 'running') {
            loadCampaignDetail(activeCampaignId);
            loadCampaigns();
        }
    } catch (e) { /* transient network blips shouldn't kill the poller */ }
}

async function loadTemplates() {
    const data = await apiFetch('/campaigns/templates');
    if (data.success) {
        waTemplates = data.templates || [];
    }
}

async function loadCities() {
    if (cityOptions.length) return;
    const data = await apiFetch('/campaigns/cities');
    if (data.success) {
        cityOptions = data.cities || [];
    }
}

async function loadQurbaniYears() {
    if (qurbaniYears.length) return;
    const data = await apiFetch('/campaigns/qurbani-years');
    if (data.success) {
        qurbaniYears = (data.years || []).map(y => parseInt(y)).filter(y => !!y);
    }
}

// ==========================================================================
// RENDERING
// ==========================================================================

// Short, humane date. "3 days ago" beats a raw timestamp when the question is
// "is this campaign alive or forgotten?".
function relDate(v) {
    if (!v) return null;
    const d = new Date(String(v).replace(' ', 'T'));
    if (isNaN(d)) return null;
    const days = Math.floor((Date.now() - d.getTime()) / 86400000);
    if (days <= 0) return 'today';
    if (days === 1) return 'yesterday';
    if (days < 30) return days + 'd ago';
    if (days < 365) return Math.round(days / 30) + 'mo ago';
    return Math.round(days / 365) + 'y ago';
}

function daysSince(v) {
    if (!v) return Infinity;
    const d = new Date(String(v).replace(' ', 'T'));
    if (isNaN(d)) return Infinity;
    return Math.floor((Date.now() - d.getTime()) / 86400000);
}

function renderCampaignList() {
    const el = document.getElementById('campListItems');

    const q = (campaignSearch || '').trim().toLowerCase();
    const list = q
        ? campaigns.filter(c =>
            (c.name || '').toLowerCase().includes(q) ||
            (c.wa_template_name || '').toLowerCase().includes(q) ||
            (c.template_display_name || '').toLowerCase().includes(q))
        : campaigns;

    if (!campaigns.length) {
        el.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8;font-size:13px;">No campaigns yet</div>';
        return;
    }
    if (!list.length) {
        el.innerHTML = `<div style="text-align:center;padding:30px;color:#94a3b8;font-size:13px;">
            No campaigns match “${esc(campaignSearch)}”.
            <a href="#" onclick="handleCampaignSearch('');return false;" style="color:#7f56d9;display:block;margin-top:6px;">Clear search</a>
        </div>`;
        return;
    }

    // "Overview" pseudo-row so returning to the landing view is always one
    // click, and so it's obvious the landing view is a real destination.
    let html = `
    <div class="camp-card${!activeCampaignId ? ' active' : ''}" onclick="showOverview()" style="border-bottom:2px solid #e2e8f0;">
        <div class="camp-card-name" style="margin-bottom:0;">
            <i class="ki-filled ki-chart-line-up-2" style="font-size:14px;color:#7c3aed;"></i>
            Overview &amp; results
        </div>
    </div>`;

    // Live work first; finished campaigns collapse behind one row.
    //
    // Why: an ended campaign is history — it cannot send and needs no decision —
    // but listed at full size it looks identical to something that still needs
    // attention. With most campaigns ended, the list read as a wall of things to
    // do. They stay one click away, never hidden.
    //
    // Two cases force them open regardless, or the UI would appear to be lying:
    // a search that matches one, and having an ended campaign currently open.
    const activeList = list.filter(c => c.status !== 'ended');
    const endedList  = list.filter(c => c.status === 'ended');
    const searching  = q !== '';
    const viewingEnded = endedList.some(c => activeCampaignId == c.id);
    const endedOpen  = showEndedCampaigns || searching || viewingEnded;

    const renderCard = c => {
        const isActive = activeCampaignId == c.id;
        const total = parseInt(c.total_customers || 0);
        const sent = parseInt(c.sent_count || 0);
        const pending = parseInt(c.pending_count || 0);
        const failed = parseInt(c.failed_count || 0);
        const isEnded = c.status === 'ended';
        const running = (c.send_state === 'running');
        const paused = (c.send_state === 'paused');

        // Dim campaigns that are effectively dormant, so live work stands out.
        // A running campaign is never dimmed no matter how old its last send.
        const stale = !isEnded && !running && pending > 0 && daysSince(c.last_sent_at) > staleDays;
        const dim = (isEnded || stale) ? 'opacity:.62;' : '';

        const lastSent = relDate(c.last_sent_at);
        const created = relDate(c.created_at);
        const tplLabel = c.template_display_name || c.wa_template_name;

        return `
        <div class="camp-card${isActive ? ' active' : ''}" onclick="loadCampaignDetail(${c.id})" style="${dim}">
            <div class="camp-card-name">
                ${esc(c.name)}
                <span class="camp-badge ${c.status}">${c.status}</span>
                ${running ? `<span class="camp-badge" style="background:#dcfce7;color:#166534;">⏳ sending</span>` : ''}
                ${paused ? `<span class="camp-badge" style="background:#fee2e2;color:#991b1b;">paused</span>` : ''}
                ${stale ? `<span class="camp-badge" style="background:#fef3c7;color:#92400e;" title="Nothing sent for over ${staleDays} days while customers are still waiting.">idle</span>` : ''}
            </div>
            ${tplLabel
                ? `<div class="camp-template-badge"><i class="ki-filled ki-message-text" style="font-size:12px;"></i> ${esc(tplLabel)}</div>`
                : `<div class="camp-template-badge" style="color:#dc2626;" title="Without a template this campaign can never send."><i class="ki-filled ki-information-2" style="font-size:12px;"></i> No template set</div>`}
            <div class="camp-card-meta" style="margin-top:6px;">
                <span>Total: ${total}</span>
                <span style="color:#25d366;">Sent: ${sent}</span>
                ${pending > 0 ? `<span style="color:#d97706;">Waiting: ${pending}</span>` : ''}
                ${failed > 0 ? `<span style="color:#dc2626;">Failed: ${failed}</span>` : ''}
            </div>
            <div class="camp-card-meta" style="margin-top:3px;font-size:11px;color:#94a3b8;">
                ${lastSent ? `<span title="Last message sent">Last sent ${lastSent}</span>` : '<span>Never sent</span>'}
                ${created ? `<span title="Campaign created">Created ${created}</span>` : ''}
            </div>
        </div>`;
    };

    html += activeList.map(renderCard).join('');

    if (activeList.length === 0 && endedList.length > 0 && !searching) {
        html += `<div style="padding:16px 14px;color:#94a3b8;font-size:12px;text-align:center;">
            No active campaigns. ${endedList.length} finished one${endedList.length === 1 ? '' : 's'} below.
        </div>`;
    }

    if (endedList.length > 0) {
        const totalSent = endedList.reduce((n, c) => n + parseInt(c.sent_count || 0), 0);
        html += `
        <div onclick="toggleEndedCampaigns()" title="${endedOpen ? 'Hide' : 'Show'} finished campaigns"
             style="display:flex;align-items:center;gap:8px;padding:10px 14px;cursor:pointer;
                    background:#f8fafc;border-top:1px solid #e2e8f0;${endedOpen ? 'border-bottom:1px solid #e2e8f0;' : ''}
                    font-size:12px;color:#64748b;user-select:none;">
            <span style="font-size:10px;display:inline-block;width:10px;transition:transform .15s;transform:rotate(${endedOpen ? '90' : '0'}deg);">▶</span>
            <span style="font-weight:600;">Finished campaigns (${endedList.length})</span>
            <span style="margin-left:auto;color:#94a3b8;">${totalSent.toLocaleString()} sent</span>
        </div>`;

        if (endedOpen) {
            if (searching && !showEndedCampaigns) {
                html += `<div style="padding:6px 14px;font-size:11px;color:#94a3b8;background:#f8fafc;">Shown because they match your search.</div>`;
            }
            html += endedList.map(renderCard).join('');
        }
    }

    el.innerHTML = html;
}

// Finished campaigns are collapsed by default (see renderCampaignList). The
// choice is per-session only — deliberately not persisted, since the useful
// default on every visit is "show me what still needs work".
function toggleEndedCampaigns() {
    showEndedCampaigns = !showEndedCampaigns;
    renderCampaignList();
}

// Always-visible WhatsApp allowance in the list footer.
function renderQuotaFooter() {
    const el = document.getElementById('campQuotaFooter');
    if (!el) return;
    const q = waQuota;
    if (!q) { el.innerHTML = ''; return; }

    if (q.unlimited) {
        el.innerHTML = `<div style="font-size:11px;color:#64748b;">Daily send limit off — nothing will be capped.</div>`;
        return;
    }
    const cap = q.cap || 0;
    const used = q.used || 0;
    const left = (q.remaining === null || q.remaining === undefined) ? Math.max(0, cap - used) : q.remaining;
    const pct = cap > 0 ? Math.min(100, Math.round((used / cap) * 100)) : 0;
    const colour = pct > 85 ? '#dc2626' : (pct > 60 ? '#d97706' : '#10b981');

    el.innerHTML = `
        <div style="display:flex;justify-content:space-between;font-size:11px;color:#475569;margin-bottom:4px;">
            <span>WhatsApp today</span>
            <span><b style="color:${colour};">${left}</b> of ${cap} left</span>
        </div>
        <div style="height:5px;background:#e2e8f0;border-radius:3px;overflow:hidden;">
            <div style="height:100%;width:${pct}%;background:${colour};"></div>
        </div>
        <div style="font-size:10px;color:#94a3b8;margin-top:4px;">Shared with invoices — every template send counts.</div>`;
}

// Apply the in-tab search + source chip to the currently-loaded
// customer list. Runs purely in the browser over `campaignCustomers`,
// which already reflects the selected status tab, so we never show
// rows that don't belong to the active tab. Search is a case-insensitive
// substring match against name, phone and city — the three things a user
// is most likely to remember. Source chip uses the `is_shopify` flag
// already supplied by the backend.
function getFilteredCampaignCustomers() {
    const src = (campaignCustomers || []);
    const q = (detailSearch || '').trim().toLowerCase();
    const source = detailSourceFilter || 'any';
    if (!q && source === 'any') return src;
    return src.filter(cu => {
        if (source === 'shopify' && !Number(cu.is_shopify)) return false;
        if (source === 'non_shopify' && Number(cu.is_shopify)) return false;
        if (!q) return true;
        const name = ((cu.first_name || '') + ' ' + (cu.last_name || '')).trim().toLowerCase();
        const phone = (cu.phone || cu.phone_normalized || '').toLowerCase();
        const city = (cu.city || '').toLowerCase();
        return name.includes(q) || phone.includes(q) || city.includes(q);
    });
}

function handleDetailSearch(val) {
    detailSearch = val || '';
    renderCampaignDetail();
}

function setDetailSourceFilter(val) {
    detailSourceFilter = val || 'any';
    renderCampaignDetail();
}

function clearDetailFilters() {
    detailSearch = '';
    detailSourceFilter = 'any';
    renderCampaignDetail();
}

// ==========================================================================
// LANDING VIEW (right panel when no campaign is selected)
//
// The page used to show an empty bell here. This answers the question the page
// is actually opened with: what's sending, how is each template performing, and
// does anything need me? Template results are reachable in one click.
// ==========================================================================

function renderOverview() {
    const el = document.getElementById('campDetail');
    if (!overviewData) {
        el.innerHTML = '<div style="text-align:center;padding:60px;"><div class="camp-spinner camp-spinner-dark"></div></div>';
        return;
    }

    const o = overviewData;
    let html = `
    <div class="camp-detail-header">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
            <div>
                <h3>Campaigns overview</h3>
                <div style="font-size:12px;color:#64748b;margin-top:2px;">
                    ${o.totals.active_campaigns} active of ${o.totals.total_campaigns} ·
                    ${Number(o.totals.sent_total).toLocaleString()} messages sent ·
                    ${Number(o.totals.pending_total).toLocaleString()} still waiting
                </div>
            </div>
            <button class="camp-btn camp-btn-purple" onclick="openCreateModal()">+ New Campaign</button>
        </div>
    </div>
    <div style="flex:1;overflow-y:auto;padding:16px 20px;">`;

    html += renderOverviewRunning(o.running);
    html += renderOverviewAttention(o.attention);
    html += renderOverviewTemplates(o.templates);

    html += '</div>';
    el.innerHTML = html;
}

// Live background sends. Shown first because it's the only thing here that is
// happening right now and can be stopped.
function renderOverviewRunning(running) {
    if (!running || !running.length) return '';

    let html = `<h4 style="font-size:13px;font-weight:600;margin:0 0 8px;color:#065f46;">Sending right now</h4>`;
    running.forEach(r => {
        const pct = r.target > 0 ? Math.min(100, Math.round((r.attempted / r.target) * 100)) : 0;
        html += `
        <div style="padding:12px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;margin-bottom:8px;cursor:pointer;"
             onclick="loadCampaignDetail(${r.campaign_id})">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
                <div style="font-size:13px;font-weight:600;color:#065f46;">
                    <span class="camp-spinner camp-spinner-dark"></span> ${esc(r.campaign_name)}
                </div>
                <div style="font-size:12px;color:#047857;">${r.attempted} of ${r.target} · ${r.sent} sent${r.failed > 0 ? ` · ${r.failed} failed` : ''}</div>
            </div>
            <div style="margin-top:6px;height:6px;background:#d1fae5;border-radius:3px;overflow:hidden;">
                <div style="height:100%;width:${pct}%;background:#10b981;transition:width .4s;"></div>
            </div>
            ${r.paused_reason ? `<div style="font-size:11px;color:#92400e;margin-top:5px;">${esc(r.paused_reason)}</div>` : ''}
        </div>`;
    });
    return html + '<div style="height:14px;"></div>';
}

// Things a human has to decide about. Deliberately states the fix, not just the
// problem — a list of complaints with no next step is noise.
function renderOverviewAttention(items) {
    if (!items || !items.length) {
        return `<div style="padding:12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;margin-bottom:16px;font-size:12px;color:#166534;">
            ✓ Nothing needs attention — no failures, no dead numbers, no forgotten campaigns.
        </div>`;
    }

    const style = {
        paused:      {bg: '#fef2f2', bd: '#fecaca', fg: '#991b1b', icon: '⏸'},
        no_template: {bg: '#fef2f2', bd: '#fecaca', fg: '#991b1b', icon: '⚠'},
        failed:      {bg: '#fef2f2', bd: '#fecaca', fg: '#991b1b', icon: '✕'},
        undelivered: {bg: '#fff7ed', bd: '#fed7aa', fg: '#9a3412', icon: '📵'},
        stale:       {bg: '#fffbeb', bd: '#fde68a', fg: '#92400e', icon: '💤'},
    };

    let html = `<h4 style="font-size:13px;font-weight:600;margin:0 0 8px;">Needs attention <span style="color:#94a3b8;font-weight:400;">(${items.length})</span></h4>`;
    items.forEach(a => {
        const s = style[a.type] || {bg: '#f8fafc', bd: '#e2e8f0', fg: '#475569', icon: '•'};
        // Deep-link straight to the tab that resolves the problem.
        const tab = a.type === 'failed' ? 'failed' : (a.type === 'undelivered' ? 'undelivered' : 'pending');
        html += `
        <div style="padding:10px 12px;background:${s.bg};border:1px solid ${s.bd};border-radius:6px;margin-bottom:6px;cursor:pointer;"
             onclick="loadCampaignDetail(${a.campaign_id},'${tab}')">
            <div style="display:flex;gap:8px;align-items:flex-start;">
                <span style="font-size:13px;">${s.icon}</span>
                <div style="flex:1;">
                    <div style="font-size:12px;font-weight:600;color:${s.fg};">${esc(a.campaign_name)}</div>
                    <div style="font-size:11px;color:${s.fg};opacity:.9;margin-top:1px;">${esc(a.detail)}</div>
                    <div style="font-size:11px;color:#64748b;margin-top:3px;">→ ${esc(a.action)}</div>
                </div>
            </div>
        </div>`;
    });
    return html + '<div style="height:16px;"></div>';
}

// Per-template results — the headline of this page. Each row is the combined,
// deduplicated performance of one template; clicking opens the full breakdown.
function renderOverviewTemplates(templates) {
    let html = `<h4 style="font-size:13px;font-weight:600;margin:0 0 4px;">Results by template</h4>
        <div style="font-size:11px;color:#64748b;margin-bottom:10px;">
            Each customer counted once per template, even if several campaigns messaged them. Click a row for the campaign-by-campaign breakdown.
        </div>`;

    if (!templates || !templates.length) {
        return html + `<div style="padding:20px;text-align:center;color:#94a3b8;font-size:12px;background:#f8fafc;border-radius:8px;">
            No template has been used by a campaign yet.
        </div>`;
    }

    templates.forEach(t => {
        const f = t.funnel;
        const lastSent = relDate(t.last_sent_at);
        const typeLabel = t.tracking_type === 'app_orders'
            ? '📱 app orders only'
            : (t.tracking_type === 'products' ? 'specific products'
                : (t.tracking_type === 'category' ? 'a product category' : 'any order'));

        html += `
        <div style="padding:12px;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:8px;background:#fff;cursor:pointer;"
             onclick="openTemplateResults('${esc(t.wa_template_name).replace(/'/g, "\\'")}')">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">
                <div>
                    <div style="font-size:13px;font-weight:700;color:#1a1a1a;">${esc(t.display_name)}</div>
                    <div style="font-size:11px;color:#64748b;margin-top:2px;">
                        ${t.campaign_count} campaign${t.campaign_count === 1 ? '' : 's'}${t.active_count > 0 ? ` · ${t.active_count} active` : ''}
                        ${lastSent ? ` · last sent ${lastSent}` : ' · never sent'}
                        · counts ${typeLabel}
                    </div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:16px;font-weight:700;color:#16a34a;">${f.rates.ordered}%</div>
                    <div style="font-size:10px;color:#94a3b8;">ordered after</div>
                </div>
            </div>
            <div style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap;">
                ${overviewStat('Reached', Number(f.sent).toLocaleString(), '#0f172a')}
                ${f.receipts_tracked > 0 ? overviewStat('Read', f.read + ' (' + f.rates.read + '%)', '#7c3aed') : ''}
                ${overviewStat('Replied', f.replied + ' (' + f.rates.replied + '%)', '#2563eb')}
                ${overviewStat('Ordered', f.ordered, '#16a34a')}
                ${overviewStat('Revenue', 'PKR ' + Number(f.revenue || 0).toLocaleString(), '#d97706')}
            </div>
            ${renderTemplateReachNote(f)}
            ${f.undelivered > 0 ? `<div style="font-size:11px;color:#991b1b;margin-top:4px;">
                ${f.undelivered} could not be delivered (${f.rates.undelivered}%).
            </div>` : ''}
        </div>`;
    });

    return html;
}

// Explains where a template's reach came from. The headline counts EVERY send of
// the template — campaign sends plus ones typed by hand in the chat window — so
// the split has to be visible, otherwise "reached 1,176" looks like the campaign
// did more than it did.
function renderTemplateReachNote(f) {
    const byHand = Number(f.outside_campaigns || 0);
    const viaCampaign = Number(f.from_campaign || 0);
    const bits = [];

    if (byHand > 0) {
        bits.push(`<div style="font-size:11px;color:#5b21b6;margin-top:8px;">
            <b>${Number(f.sent).toLocaleString()}</b> people reached —
            ${viaCampaign.toLocaleString()} through campaigns,
            <b>${byHand.toLocaleString()}</b> sent by hand from Messages.
        </div>`);
    }
    if (f.duplicate_sends > 0) {
        bits.push(`<div style="font-size:11px;color:#92400e;margin-top:${byHand > 0 ? '4' : '8'}px;">
            ${Number(f.sends).toLocaleString()} messages went to ${Number(f.sent).toLocaleString()} people — ${f.duplicate_sends} got it more than once.
        </div>`);
    }
    return bits.join('');
}

function overviewStat(label, value, colour) {
    return `<div style="flex:1;min-width:78px;padding:6px 8px;background:#f8fafc;border-radius:6px;">
        <div style="font-size:13px;font-weight:700;color:${colour};line-height:1.2;">${value}</div>
        <div style="font-size:10px;color:#64748b;margin-top:1px;">${label}</div>
    </div>`;
}

// ==========================================================================
// LIST SORT (the "top 100 by revenue" control)
//
// Options are key:direction pairs understood by the server. Spend and order
// counts are computed LIVE (the stored columns are stale), so "highest spend"
// really is the highest.
// ==========================================================================

const CAMP_SORT_OPTIONS = [
    { v: 'last_order_date:desc', l: 'Recent buyers first' },
    { v: 'last_order_date:asc',  l: 'Longest-quiet first (win-back)' },
    { v: 'spent:desc',           l: 'Highest spend first' },
    { v: 'orders:desc',          l: 'Most orders first' },
    { v: 'first_order_date:desc',l: 'Newest customers first' },
    { v: 'created_at:desc',      l: 'Recently added first' },
];

function sortLabel(by, dir) {
    const hit = CAMP_SORT_OPTIONS.find(o => o.v === `${by}:${dir}`);
    return hit ? hit.l : (by + ' ' + dir);
}

function renderSortSelect() {
    const cur = detailSort ? `${detailSort.by}:${detailSort.dir}` : '';
    // A stored sort that isn't one of the presets (e.g. spent:asc) still shows
    // truthfully rather than pretending to be something else.
    const known = CAMP_SORT_OPTIONS.some(o => o.v === cur);
    return `
    <select onchange="changeCampaignSort(this.value)" title="Order of this list AND of sending — 'send the first 100' follows this."
            style="padding:6px 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;background:#fff;max-width:210px;">
        ${!known && cur ? `<option value="${esc(cur)}" selected>${esc(sortLabel(detailSort.by, detailSort.dir))}</option>` : ''}
        ${CAMP_SORT_OPTIONS.map(o => `<option value="${o.v}" ${o.v === cur ? 'selected' : ''}>${o.l}</option>`).join('')}
    </select>`;
}

async function changeCampaignSort(value) {
    const [by, dir] = String(value).split(':');
    if (!by) return;
    detailSort = { by, dir: dir || 'desc' };

    // Persist as the campaign's SEND order so "first N" sends and the
    // background worker follow what's on screen. A viewer without manage
    // rights gets a 403 here — that's fine, their VIEW still re-sorts via the
    // detail request; only the stored send order stays put.
    try {
        await apiFetch(`/campaigns/${activeCampaignId}/sort`, {
            method: 'POST',
            body: JSON.stringify({ sort_by: by, sort_dir: dir }),
        });
    } catch (e) { /* view-only sorting still works below */ }

    loadCampaignDetail(activeCampaignId, customerStatusFilter, 1);
}

// Per-person delivery state. Deliberately only rendered for rows we actually
// have a receipt for — a sent row with no receipt yet shows nothing rather than
// an "unread" badge we can't stand behind.
function renderReceiptBadge(cu) {
    if (cu.campaign_status !== 'sent') return '';
    if (cu.undelivered_at) {
        return '<span class="camp-status-badge camp-status-failed" title="WhatsApp could not deliver this — a dead/blocked number, or WhatsApp\'s marketing frequency cap (the per-row reason says which). Frequency-capped people can be requeued and resent later.">Undelivered</span>';
    }
    if (cu.read_at) {
        return '<span class="camp-status-badge" style="background:#ede9fe;color:#5b21b6;" title="Opened by the customer">Read</span>';
    }
    if (cu.delivered_at) {
        return '<span class="camp-status-badge" style="background:#cffafe;color:#155e75;" title="Reached the phone. No read receipt — either not opened yet, or read receipts are off.">Delivered</span>';
    }
    return '';
}

// The delivery funnel, straight from Meta's receipts.
//
// Honesty rules baked in here, because these numbers are easy to misread:
//  - "Read" is a FLOOR. Customers who switch read receipts off never report a
//    read, so a low number does not mean people ignored the message. The
//    tooltip says so rather than leaving the operator to guess.
//  - Campaigns sent before receipt tracking existed have no data at all, so we
//    show a plain note instead of a row of zeros that looks like total failure.
//  - "Ordered" is orders placed inside the tracking window, which is not proof
//    the message caused them. Labelled as timing, not causation.
function renderFunnelStrip(counts) {
    const sent = parseInt(counts.sent || 0);
    if (!sent) return '';

    const tracked      = parseInt(counts.delivered || 0) + parseInt(counts.undelivered || 0);
    const delivered    = parseInt(counts.delivered || 0);
    const readCount    = parseInt(counts.read || 0);
    const replied      = parseInt(counts.replied || 0);
    const undelivered  = parseInt(counts.undelivered || 0);
    const pct = (n) => sent > 0 ? Math.round((n / sent) * 1000) / 10 : 0;

    // Every tile opens the list of people behind its number (Oct-2026).
    const e = campaignEngagement;
    const cid = activeCampaign ? activeCampaign.id : 0;
    const cell = (filter, label, val, pctVal, color, title) => `
        <button type="button" class="camp-tile${customerStatusFilter === filter ? ' active' : ''}" title="${title} Click to see who."
                onclick="loadCampaignDetail(${cid}, '${filter}')">
            <div class="v" style="color:${color};">${val}</div>
            <div class="l">${label}${pctVal !== null ? ' · ' + pctVal + '%' : ''}</div>
        </button>`;
    const eng = (key) => e ? parseInt(e[key] || 0) : '…';
    const engPct = (key) => e ? pct(parseInt(e[key] || 0)) : null;

    const receiptTiles = tracked === 0 ? '' : `
            ${cell('delivered', 'Delivered', delivered, pct(delivered), '#0891b2', 'Reached the customer\'s phone, confirmed by WhatsApp.')}
            ${cell('read', 'Read', readCount, pct(readCount), '#7c3aed', 'Opened by the customer. This is a minimum — anyone who turns read receipts off never reports a read, so the real number is higher.')}`;

    const orderedVal = e ? parseInt(e.ordered || 0) : '…';
    const orderedSub = e && parseInt(e.orders || 0) > 0
        ? `<div class="l" style="color:#16a34a;">${e.orders} order${e.orders == 1 ? '' : 's'} · PKR ${Math.round(parseFloat(e.revenue || 0)).toLocaleString()}</div>` : '';

    return `
    <div id="campFunnelStrip" style="margin-top:10px;">
        ${tracked === 0 ? `<div style="margin-bottom:6px;padding:6px 10px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:6px;font-size:11px;color:#64748b;">
            <b>Delivered/Read not available for these sends</b> — WhatsApp receipts are recorded from Jul-2026 onwards.
        </div>` : ''}
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
            ${receiptTiles}
            ${cell('replied', 'Replied', replied, pct(replied), '#2563eb', 'Wrote back to us (a message, photo, voice note or reaction) within the tracking window. Button taps are counted separately.')}
            ${cell('tapped', 'Tapped a button', eng('tapped'), engPct('tapped'), '#6b21a8', 'Tapped a quick-reply button on a message (e.g. Menu). The system answers these automatically.')}
            ${cell('conversation', 'Conversation', eng('conversation'), engPct('conversation'), '#0369a1', 'They wrote to us AND someone from the team replied after that.')}
            ${cell('waiting', 'Waiting on us', eng('waiting'), engPct('waiting'), '#b45309', 'They wrote to us and nobody from the team has replied since.')}
            <button type="button" class="camp-tile${customerStatusFilter === 'ordered' ? ' active' : ''}" onclick="loadCampaignDetail(${cid}, 'ordered')"
                    title="Placed an order inside the tracking window that matches this campaign's tracking. Click to see who.">
                <div class="v" style="color:#16a34a;">${orderedVal}</div>
                <div class="l">Ordered${e ? ' · ' + pct(parseInt(e.ordered || 0)) + '%' : ''}</div>
                ${orderedSub}
            </button>
            ${undelivered > 0 ? cell('undelivered', 'Undelivered', undelivered, pct(undelivered), '#dc2626', 'WhatsApp accepted the message but could not deliver it — a dead/blocked number, or WhatsApp\'s marketing frequency cap protecting busy inboxes. Check the reason on each row; frequency-capped ones can be requeued.') : ''}
        </div>
        ${campaignTracking ? `<div style="font-size:11px;color:#64748b;margin-top:6px;">
            "Ordered" counts <b>${esc(trackingLabel(campaignTracking))}</b> within ${campaignTracking.window_days} days of the message.
            <a href="#" onclick="openTrackingModal();return false;" style="color:#7c3aed;margin-left:4px;">Change tracking</a>
            · <a href="#" onclick="openStatsModal(${cid});return false;" style="color:#7c3aed;">By category</a>
        </div>` : ''}
    </div>`;
}

// One clear line about what the sender is doing right now. A stalled campaign
// must never look like a finished one, so a pause always states its reason.
function renderSendStateBanner() {
    const running = sendState === 'running';
    const run = sendRun;

    if (running && run) {
        const done = parseInt(run.attempted || 0);
        const target = parseInt(run.target_count || 0);
        const pct = target > 0 ? Math.min(100, Math.round((done / target) * 100)) : 0;
        const stalled = sendPausedReason ? `<div style="font-size:11px;color:#92400e;margin-top:4px;">${esc(sendPausedReason)}</div>` : '';
        return `
        <div style="margin:10px 20px 0;padding:10px 12px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:6px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                <div style="font-size:12px;color:#065f46;">
                    <span class="camp-spinner camp-spinner-dark"></span>
                    <b>Sending in the background</b> — ${done} of ${target} done (${parseInt(run.sent_count || 0)} sent${parseInt(run.failed_count || 0) > 0 ? `, ${run.failed_count} failed` : ''}).
                    You can safely close this page.
                </div>
                <button class="camp-btn camp-btn-secondary" onclick="pauseBackgroundSend()">Pause</button>
            </div>
            <div style="margin-top:6px;height:6px;background:#d1fae5;border-radius:3px;overflow:hidden;">
                <div style="height:100%;width:${pct}%;background:#10b981;transition:width .4s;"></div>
            </div>
            ${stalled}
        </div>`;
    }

    if (sendState === 'paused') {
        return `<div style="margin:10px 20px 0;padding:10px 12px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;font-size:12px;color:#991b1b;">
            <b>Sending is paused.</b> ${esc(sendPausedReason || 'Paused.')}
            <button class="camp-btn camp-btn-secondary" style="margin-left:8px;" onclick="openSendDialog()">Resume</button>
        </div>`;
    }

    // Idle, but the last run left a message worth reading (e.g. hit the daily cap).
    if (sendPausedReason && (parseInt((campaignCounts || {}).pending || 0) > 0)) {
        return `<div style="margin:10px 20px 0;padding:8px 12px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;font-size:12px;color:#92400e;">
            ${esc(sendPausedReason)}
        </div>`;
    }

    return '';
}

// Server-side paging. Previously the detail endpoint returned every row, so a
// 5,700-recipient campaign shipped the entire list to the browser on open.
function renderPagination() {
    const p = detailPagination;
    if (!p || p.pages <= 1) return '';
    // Pass the current tab explicitly so paging never changes which status the
    // operator is looking at.
    const btn = (label, page, disabled) =>
        `<button class="camp-btn camp-btn-secondary" style="padding:4px 10px;font-size:12px;" ${disabled ? 'disabled' : ''} onclick="loadCampaignDetail(${activeCampaignId}, '${customerStatusFilter}', ${page})">${label}</button>`;
    const from = ((p.page - 1) * p.per_page) + 1;
    const to = Math.min(p.total, p.page * p.per_page);
    return `
    <div style="display:flex;align-items:center;justify-content:center;gap:8px;padding:12px 14px;border-top:1px solid #f1f5f9;">
        ${btn('‹ Prev', p.page - 1, p.page <= 1)}
        <span style="font-size:12px;color:#64748b;">${from}–${to} of ${p.total}</span>
        ${btn('Next ›', p.page + 1, p.page >= p.pages)}
    </div>`;
}

function renderCampaignDetail() {
    const el = document.getElementById('campDetail');
    // No campaign selected = the landing view, not an empty placeholder.
    if (!activeCampaign) {
        renderOverview();
        return;
    }

    // NF: preserve the customer-list scroll position across re-renders.
    // Every checkbox toggle calls renderCampaignDetail() which replaces the
    // entire inner HTML of #campDetail — including #campCustomersList — so the
    // scroll position was resetting to 0 on every click, yanking the user
    // back to the top. We snapshot the scrollTop here and restore it after
    // the new DOM is in place (see end of function).
    const _prevList = document.getElementById('campCustomersList');
    const _prevScrollTop = _prevList ? _prevList.scrollTop : 0;

    // NF: preserve focus + caret on the in-tab search input. The whole
    // detail panel is re-rendered on every keystroke, so without this the
    // input would lose focus after typing the first character.
    const _prevSearchEl = document.getElementById('campDetailSearch');
    const _searchWasFocused = _prevSearchEl && document.activeElement === _prevSearchEl;
    const _searchCaret = _prevSearchEl ? _prevSearchEl.selectionStart : null;

    const c = activeCampaign;
    const counts = campaignCounts || {};
    const isEnded = c.status === 'ended';
    // "Excluded" is only rendered when there's actually someone to show —
    // stops it from cluttering the tab strip for campaigns created before
    // the dedup feature existed (they'll always have excluded=0).
    const hasExcluded = (counts.excluded || 0) > 0 || customerStatusFilter === 'excluded';
    // Undelivered = WhatsApp accepted the message but it never reached the
    // handset (dead/blocked number). Only offered once there's something in it,
    // because it's also a phone-number cleanup list.
    const hasUndelivered = (counts.undelivered || 0) > 0 || customerStatusFilter === 'undelivered';
    const filterKeys = ['pending','sent','failed','skipped'];
    if (hasExcluded) filterKeys.push('excluded');
    if (hasUndelivered) filterKeys.push('undelivered');
    filterKeys.push('all');
    const filterBtns = filterKeys.map(f => {
        const count = f === 'all' ? (counts.total || 0) : (counts[f] || 0);
        const label = f.charAt(0).toUpperCase() + f.slice(1);
        return `<button class="camp-filter-btn${customerStatusFilter === f ? ' active' : ''}" onclick="loadCampaignDetail(${c.id},'${f}')">${label} (${count})</button>`;
    }).join('');

    let html = `
    <div class="camp-detail-header">
        <div style="display:flex;align-items:center;justify-content:space-between;">
            <div>
                <h3>
                    <a href="#" onclick="showOverview();return false;" title="Back to overview"
                       style="color:#7c3aed;text-decoration:none;font-size:14px;margin-right:6px;">&larr;</a>
                    ${esc(c.name)} <span class="camp-badge ${c.status}">${c.status}</span>
                </h3>
                ${c.wa_template_name ? `<div class="camp-template-badge" style="margin-top:4px;"><i class="ki-filled ki-message-text" style="font-size:12px;"></i> Template: ${esc(c.template_display_name || c.wa_template_name)}</div>` : ''}
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                ${(!isEnded && campaignEligible > 0 && c.wa_template_name) ? `<button class="camp-btn camp-btn-primary camp-btn-lg" onclick="openSendDialog()"><i class="ki-filled ki-send" style="font-size:14px;"></i> Send Messages</button>` : ''}
                <button class="camp-btn camp-btn-secondary" onclick="openStatsModal(${c.id})"><i class="ki-filled ki-chart-line-up-2" style="font-size:14px;"></i> Results</button>
                ${c.wa_template_name ? `<button class="camp-btn camp-btn-secondary" onclick="openTemplateResults('${esc(c.wa_template_name).replace(/'/g, "\\'")}')" title="Compare every campaign that used this template, with a combined result that counts each customer once."><i class="ki-filled ki-copy" style="font-size:14px;"></i> By Template</button>` : ''}
                ${(!isEnded && (c.dedup_window_days || 0) > 0 && c.wa_template_name) ? `<button class="camp-btn camp-btn-secondary" onclick="refreshDedup()" title="Re-check pending customers against recent template sends from other campaigns. Newly-matching customers move to the Excluded tab; nothing is sent."><i class="ki-filled ki-arrows-circle" style="font-size:14px;"></i> Refresh Dedup</button>` : ''}
                ${!isEnded ? `<button class="camp-btn camp-btn-purple" onclick="openAddCustomersModal(${c.id}, '${esc(c.name).replace(/'/g, "\\'")}')">+ Add Customers</button>` : ''}
                ${!isEnded ? `<button class="camp-btn camp-btn-danger" onclick="endCampaign(${c.id})">End Campaign</button>` : ''}
            </div>
        </div>
        <div class="camp-detail-stats" style="margin-top:10px;">
            <div class="camp-stat">Total: <b>${counts.total || 0}</b></div>
            <div class="camp-stat" style="color:#25d366;">Sent: <b>${counts.sent || 0}</b></div>
            <div class="camp-stat" style="color:#d97706;">Pending: <b>${counts.pending || 0}</b></div>
            <div class="camp-stat" style="color:#dc2626;">Failed: <b>${counts.failed || 0}</b></div>
            <div class="camp-stat">Skipped: <b>${counts.skipped || 0}</b></div>
            ${(counts.excluded || 0) > 0 ? `<div class="camp-stat" style="color:#92400e;">Excluded: <b>${counts.excluded}</b></div>` : ''}
        </div>
        ${renderFunnelStrip(counts)}
    </div>
    ${renderSendStateBanner()}
    <div class="camp-actions-bar">
        ${filterBtns}
        ${TILE_FILTER_LABELS[customerStatusFilter] ? `<span class="camp-filter-btn active" style="cursor:default;">
            Showing: ${TILE_FILTER_LABELS[customerStatusFilter]} (${detailPagination.total || 0})
            <a href="#" onclick="loadCampaignDetail(${c.id}, 'sent');return false;" title="Back to all sent" style="color:#fff;margin-left:6px;text-decoration:none;">×</a>
        </span>` : ''}
        ${customerStatusFilter === 'undelivered' && (counts.undelivered || 0) > 0 && !isEnded ? `<button class="camp-btn camp-btn-secondary" style="margin-left:6px;" onclick="requeueAllUndelivered(${counts.undelivered})" title="Move every undelivered recipient back to Pending so a normal send can reach them again.">Requeue all ${counts.undelivered}</button>` : ''}
    </div>`;

    // Info strip shown only while the Excluded tab is active, so the
    // operator immediately understands why these rows exist and that
    // the system won't try to send to them.
    if (customerStatusFilter === 'excluded') {
        html += `<div style="margin:0 0 10px 0;padding:10px 12px;background:#fef3c7;border:1px solid #fde68a;border-radius:6px;font-size:12px;color:#92400e;line-height:1.5;">
            <b>These customers matched your filter but were not queued</b> because they already received this template recently (shown per-row below). They will <b>not</b> be sent to — this tab is for audit only.
        </div>`;
    }

    // While a chunked send is in-flight we build a short "(45/180)" suffix to
    // reassure the user the batch is still progressing.
    const progressSuffix = bulkProgress
        ? ` (${bulkProgress.done}/${bulkProgress.total})`
        : '';

    if (selectedCustomerIds.length > 0 && customerStatusFilter === 'pending' && !isEnded) {
        html += `
        <div class="camp-bulk-bar">
            <span>${selectedCustomerIds.length} selected</span>
            <button class="camp-btn camp-btn-primary camp-btn-lg" onclick="bulkSend()" ${bulkSending ? 'disabled' : ''}>
                ${bulkSending ? `<span class="camp-spinner"></span> Sending...${progressSuffix}` : `Send (${selectedCustomerIds.length})`}
            </button>
            <button class="camp-btn camp-btn-secondary" onclick="clearSelection()">Clear</button>
        </div>`;
    }

    // Bulk-retry controls for the Failed tab: "Retry All (N)" always available, plus a
    // selection-aware "Retry Selected" bar when the user has picked a subset of failed rows.
    // Counts come from the server, not the loaded page — with paging on, the
    // page only holds up to per_page rows, so "Retry All" must mean ALL failed.
    const failedTotal = parseInt(counts.failed || 0);
    if (customerStatusFilter === 'failed' && !isEnded && failedTotal > 0) {
        html += `
        <div class="camp-bulk-bar">
            <span>${failedTotal} failed</span>
            <button class="camp-btn camp-btn-primary camp-btn-lg" onclick="retryAllFailed()" ${bulkSending ? 'disabled' : ''}>
                ${bulkSending ? `<span class="camp-spinner"></span> Retrying...${progressSuffix}` : `Retry All (${failedTotal})`}
            </button>
            ${selectedCustomerIds.length > 0 ? `
                <span style="margin-left:8px;">${selectedCustomerIds.length} selected</span>
                <button class="camp-btn camp-btn-secondary" onclick="bulkRetry()" ${bulkSending ? 'disabled' : ''}>Retry Selected</button>
                <button class="camp-btn camp-btn-secondary" onclick="clearSelection()">Clear</button>
            ` : ''}
        </div>`;
    }

    html += `<div class="camp-customers-list" id="campCustomersList">`;

    // In-tab toolbar: live search + Shopify source chips. Only shown when
    // the tab actually has customers loaded, so we don't clutter the
    // empty state. Counts shown as "filtered / total" only when a filter
    // is actually narrowing the list, so honest unfiltered counts stay
    // visible the rest of the time.
    const filteredCustomers = getFilteredCampaignCustomers();
    const hasAnyDetailFilter = (detailSearch && detailSearch.trim() !== '') || (detailSourceFilter && detailSourceFilter !== 'any');
    if (campaignCustomers.length > 0) {
        const qEsc = esc(detailSearch || '');
        const chip = (val, label) => `<button type="button" class="camp-filter-btn${detailSourceFilter === val ? ' active' : ''}" style="padding:4px 10px;font-size:12px;" onclick="setDetailSourceFilter('${val}')">${label}</button>`;
        html += `
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px;padding:0 14px;">
            <div style="position:relative;flex:1;min-width:200px;max-width:340px;">
                <input type="text" id="campDetailSearch" value="${qEsc}" oninput="handleDetailSearch(this.value)" placeholder="Search name, phone or city..." style="width:100%;padding:6px 28px 6px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;">
                ${detailSearch ? `<span onclick="handleDetailSearch('')" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);cursor:pointer;color:#94a3b8;font-size:14px;line-height:1;" title="Clear search">×</span>` : ''}
            </div>
            <div style="display:flex;gap:4px;">
                ${chip('any', 'All')}
                ${chip('shopify', '🛍 Shopify')}
                ${chip('non_shopify', 'Non-Shopify')}
            </div>
            ${renderSortSelect()}
            ${hasAnyDetailFilter ? `
                <span style="font-size:11px;color:#64748b;">Showing <b>${filteredCustomers.length}</b> / ${campaignCustomers.length}</span>
                <button type="button" class="camp-btn camp-btn-secondary" style="padding:3px 8px;font-size:11px;" onclick="clearDetailFilters()">Clear</button>
            ` : ''}
        </div>`;
    }

    const selectableTab = (customerStatusFilter === 'pending' || customerStatusFilter === 'failed') && !isEnded;
    if (selectableTab && filteredCustomers.length > 0) {
        // "Select All" targets the currently-visible (filtered) rows so it
        // matches what the user actually sees. When no filter is active
        // this collapses to the previous behaviour (select every row).
        const selectableVisibleIds = filteredCustomers
            .filter(c => c.campaign_status === (customerStatusFilter === 'failed' ? 'failed' : 'pending'))
            .map(c => c.customer_id);
        const allVisibleSelected = selectableVisibleIds.length > 0 && selectableVisibleIds.every(id => selectedCustomerIds.includes(id));
        html += `
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;padding:0 14px;">
            <label class="camp-select-all">
                <input type="checkbox" id="selectAllCb" onchange="toggleSelectAll(this.checked)" ${allVisibleSelected ? 'checked' : ''}>
                Select All (${selectableVisibleIds.length})
            </label>
        </div>`;
    }

    if (!filteredCustomers.length) {
        html += hasAnyDetailFilter
            ? `<div style="text-align:center;padding:30px;color:#94a3b8;font-size:13px;">No customers match your filters. <a href="#" onclick="clearDetailFilters();return false;" style="color:#7f56d9;">Clear filters</a></div>`
            : '<div style="text-align:center;padding:30px;color:#94a3b8;font-size:13px;">No customers in this filter</div>';
    } else {
        filteredCustomers.forEach(cu => {
            const name = ((cu.first_name || '') + ' ' + (cu.last_name || '')).trim() || 'Unknown';
            const phone = cu.phone || cu.phone_normalized || '-';
            const isPending = cu.campaign_status === 'pending';
            const isFailed = cu.campaign_status === 'failed';
            const showCheckbox = (isPending || isFailed) && !isEnded;
            const isChecked = selectedCustomerIds.includes(cu.customer_id);

            const replied = !!cu.replied_at;
            // `is_shopify` is the derived column returned by the detail
            // endpoint (EXISTS check against t_crm_prod_order SH-prefix
            // within 300 days OR the `shopify` key in external_customer_ids).
            // It comes back as 0/1 from MySQL, so coerce to boolean.
            const isShopify = !!Number(cu.is_shopify);
            const matchTags = parseMatchTags(cu.match_tags);
            const tagsHtml = matchTags.length
                ? `<div class="camp-match-tags">${matchTags.map(t => `<span class="camp-match-tag ${tagClass(t)}">${esc(t)}</span>`).join('')}</div>`
                : '';
            html += `
            <div class="camp-customer-row">
                ${showCheckbox ? `<input type="checkbox" class="cust-checkbox" ${isChecked ? 'checked' : ''} onchange="toggleCustomer(${cu.customer_id}, this.checked)">` : ''}
                <div class="camp-customer-info">
                    <div class="camp-customer-name">${cu.campaign_status === 'sent'
                        ? `<span class="camp-name-link" onclick="openTimeline(${cu.customer_id})" title="See everything that happened after the message">${esc(name)}</span>`
                        : esc(name)}${replied ? `<span class="camp-reply-badge" title="First reply ${esc(fmtDateTime(cu.replied_at))} — ${esc(gapText(cu.sent_at, cu.replied_at))} after the message">Replied</span>` : ''}${cu.tapped_at ? `<span class="camp-eng-badge camp-eng-tap" title="Tapped a button ${esc(fmtDateTime(cu.tapped_at))}">👆 Tapped</span>` : ''}${cu.conversation ? '<span class="camp-eng-badge camp-eng-conv" title="They wrote and the team replied">💬 Conversation</span>' : ''}${cu.waiting ? `<span class="camp-eng-badge camp-eng-wait" title="Wrote ${esc(fmtDateTime(cu.wrote_at))} — no reply from the team since">⏳ Waiting on us</span>` : ''}${isShopify ? '<span class="camp-shopify-badge" title="Customer has ordered via Shopify">🛍 Shopify</span>' : ''}${Number(cu.is_on_mobile_app) ? '<span class="camp-shopify-badge" title="Has ordered through the customer mobile app">📱 On app</span>' : ''}${renderReceiptBadge(cu)}</div>
                    <div class="camp-customer-phone">${esc(phone)}</div>
                    <div class="camp-customer-details">
                        ${cu.city ? `<span>${esc(cu.city)}</span>` : ''}
                        ${cu.last_order_date ? `<span title="Last order">Last: ${esc(String(cu.last_order_date).substring(0,10))}</span>` : '<span style="color:#94a3b8;">Never ordered</span>'}
                        ${Number(cu.lifetime_orders) > 0 ? `<span title="Lifetime, computed live from delivered orders — this is what the spend/orders sort ranks by">${Number(cu.lifetime_orders)} order${Number(cu.lifetime_orders) === 1 ? '' : 's'} · PKR ${Number(cu.lifetime_spent || 0).toLocaleString()}</span>` : ''}
                        ${cu.order_count > 0 ? `<span style="color:#16a34a;font-weight:600;" title="Orders placed after this campaign message, inside the tracking window">${cu.order_count} after${cu.order_revenue ? ' · PKR ' + parseFloat(cu.order_revenue).toLocaleString() : ''}${cu.first_order_at ? ' · first ' + esc(gapText(cu.sent_at, cu.first_order_at)) + ' later' : ''}</span>` : ''}
                    </div>
                    ${tagsHtml}
                    ${(() => {
                        // Dedup-excluded rows carry a 'Excluded:' error_message
                        // prefix set at insert time. Surface it in a softer
                        // amber style so it reads as info, not a hard error
                        // like a failed WhatsApp send would.
                        if (!cu.error_message) return '';
                        const isDedup = typeof cu.error_message === 'string' && cu.error_message.indexOf('Excluded:') === 0;
                        const cls = isDedup ? 'camp-excluded-reason' : 'camp-error-text';
                        return `<div class="${cls}">${esc(cu.error_message)}</div>`;
                    })()}
                </div>
                <div class="camp-customer-actions">
                    ${(() => {
                        // Show the dedicated 'Excluded' badge (amber) for
                        // dedup-excluded rows, and keep the plain 'Skipped'
                        // badge for operator-skipped rows. Both are
                        // campaign_status='skipped' at the DB level.
                        const isDedupExcluded = cu.campaign_status === 'skipped'
                            && typeof cu.error_message === 'string'
                            && cu.error_message.indexOf('Excluded:') === 0;
                        if (isDedupExcluded) {
                            return `<span class="camp-status-badge camp-status-excluded">Excluded</span>`;
                        }
                        return `<span class="camp-status-badge camp-status-${cu.campaign_status}">${cu.campaign_status}</span>`;
                    })()}
                    ${isPending && !isEnded ? `
                        <button class="camp-btn camp-btn-primary" onclick="sendSingle(${cu.customer_id}, '${esc(name)}')">Send</button>
                        <button class="camp-btn camp-btn-secondary" onclick="skipCustomer(${cu.customer_id})">Skip</button>
                    ` : ''}
                    ${isFailed && !isEnded ? `
                        <button class="camp-btn camp-btn-primary" onclick="retrySingle(${cu.customer_id}, '${esc(name)}')">Retry</button>
                        <button class="camp-btn camp-btn-secondary" onclick="skipCustomer(${cu.customer_id})">Skip</button>
                    ` : ''}
                    ${cu.campaign_status === 'sent' && cu.undelivered_at && !isEnded ? `
                        <button class="camp-btn camp-btn-secondary" onclick="requeueSingle(${cu.customer_id}, '${esc(name)}')" title="Put them back in Pending so they can be sent again. WhatsApp refused the last delivery — they never received it, so a resend is a retry, not a repeat.">Requeue</button>
                    ` : ''}
                    ${cu.campaign_status === 'sent' ? `<button class="camp-btn camp-btn-secondary" onclick="openTimeline(${cu.customer_id})" title="Replies, team answers and orders after the message">Timeline</button>` : ''}
                </div>
            </div>`;
        });
    }

    html += renderPagination();
    html += '</div>';
    el.innerHTML = html;

    // NF: restore scroll position after the re-render. Use the fresh node
    // created above (the old reference is now detached).
    const _newList = document.getElementById('campCustomersList');
    if (_newList && _prevScrollTop) {
        _newList.scrollTop = _prevScrollTop;
    }

    // NF: restore focus + caret on the detail search input so typing
    // feels continuous across the re-render triggered by each keystroke.
    if (_searchWasFocused) {
        const _newSearchEl = document.getElementById('campDetailSearch');
        if (_newSearchEl) {
            _newSearchEl.focus();
            try {
                const pos = _searchCaret != null ? _searchCaret : _newSearchEl.value.length;
                _newSearchEl.setSelectionRange(pos, pos);
            } catch (e) { /* ignore — some browsers don't support on all input types */ }
        }
    }
}

// ==========================================================================
// ACTIONS
// ==========================================================================

function toggleCustomer(customerId, checked) {
    if (checked) {
        if (!selectedCustomerIds.includes(customerId)) selectedCustomerIds.push(customerId);
    } else {
        selectedCustomerIds = selectedCustomerIds.filter(id => id !== customerId);
    }
    renderCampaignDetail();
}

function toggleSelectAll(checked) {
    // Select-all works on the currently-visible (filtered) rows so the
    // user's mental model matches the UI. When no filter is active this
    // is identical to selecting every row in the tab. When a filter is
    // active, only visible rows get (de)selected — any prior selections
    // on now-hidden rows are preserved on-check, and on-uncheck we only
    // remove the visible ids so the rest of the selection survives.
    const targetStatus = customerStatusFilter === 'failed' ? 'failed' : 'pending';
    const visible = getFilteredCampaignCustomers()
        .filter(c => c.campaign_status === targetStatus)
        .map(c => c.customer_id);
    if (checked) {
        const merged = new Set(selectedCustomerIds);
        visible.forEach(id => merged.add(id));
        selectedCustomerIds = Array.from(merged);
    } else {
        const visibleSet = new Set(visible);
        selectedCustomerIds = selectedCustomerIds.filter(id => !visibleSet.has(id));
    }
    renderCampaignDetail();
}

function clearSelection() {
    selectedCustomerIds = [];
    renderCampaignDetail();
}

async function requeueSingle(customerId, name) {
    if (!confirm(`Put ${name} back in Pending so they can be sent again?\n\nWhatsApp refused the last delivery — they never received the message, so this is a retry, not a repeat.`)) return;
    const data = await apiFetch(`/campaigns/${activeCampaignId}/requeue-undelivered`, {
        method: 'POST',
        body: JSON.stringify({ customer_ids: [customerId] }),
    });
    if (data.success) {
        loadCampaignDetail(activeCampaignId, customerStatusFilter);
        loadCampaigns();
    } else {
        alert(data.message || 'Could not requeue.');
    }
}

async function requeueAllUndelivered(count) {
    if (!confirm(`Put all ${count} undelivered back in Pending?\n\nTip: waiting a day or two before resending improves the odds WhatsApp delivers them this time.`)) return;
    const data = await apiFetch(`/campaigns/${activeCampaignId}/requeue-undelivered`, {
        method: 'POST',
        body: JSON.stringify({}),
    });
    if (data.success) {
        alert(data.message || 'Done.');
        loadCampaignDetail(activeCampaignId, customerStatusFilter);
        loadCampaigns();
    } else {
        alert(data.message || 'Could not requeue.');
    }
}

async function sendSingle(customerId, name) {
    if (!activeCampaign?.wa_template_name) {
        alert('This campaign has no WhatsApp template configured.');
        return;
    }
    if (!confirm(`Send "${activeCampaign.wa_template_name}" to ${name}?`)) return;

    const data = await apiFetch(`/campaigns/${activeCampaignId}/send`, {
        method: 'POST',
        body: JSON.stringify({ limit: 1, customer_ids: [customerId], body_params: ['@{{customer_name}}'] })
    });

    if (data.success) {
        const r = data.results;
        if (r.failed > 0) {
            alert('Failed: ' + (r.errors?.[0]?.error || 'Unknown error'));
        } else if (data.stop_reason === 'daily_cap' || data.stop_reason === 'rate_limited' || data.stop_reason === 'auth_error') {
            // A one-off send can hit the same limits a batch does; say so plainly
            // instead of silently doing nothing.
            alert(data.message || 'Could not send right now.');
        }
        loadCampaignDetail(activeCampaignId, customerStatusFilter);
        loadCampaigns();
    } else {
        alert(data.message || 'Failed to send');
    }
}

// Progress state for a foreground send — shown on the button and in the
// send dialog so a long batch never looks frozen.
let bulkProgress = null; // {done, total, sent, failed}

// ==========================================================================
// SEND DIALOG + SESSION SENDING
//
// The server now decides WHO is next (in the campaign's own sort order) and
// owns the session accounting, so the browser's only job is: ask for a batch,
// then keep asking while the server says it ran out of time budget. That is
// what makes "send to the first 100" reliable — the count lives on the run row,
// not in this tab, so a refresh, a second tab, or the background worker can
// never turn one session of 100 into 200.
// ==========================================================================

function openSendDialog(opts = {}) {
    if (!activeCampaign?.wa_template_name) {
        alert('This campaign has no WhatsApp template configured.');
        return;
    }

    const useSelection = !!opts.useSelection && selectedCustomerIds.length > 0;
    const includeFailed = !!opts.includeFailed;

    // Default batch = what this campaign used last time, clamped to what's
    // actually left and to today's remaining WhatsApp allowance. A hand-picked
    // selection defaults to the WHOLE selection instead — the remembered limit
    // once shrank a picked 73 to last wave's 61 and read as a re-send.
    const pool = useSelection ? selectedCustomerIds.length : (includeFailed ? (parseInt((campaignCounts||{}).failed||0)) : campaignEligible);
    const quotaLeft = (waQuota && waQuota.remaining !== null && waQuota.remaining !== undefined) ? waQuota.remaining : pool;
    const wanted = useSelection ? pool : Math.max(1, Math.min(campaignSessionLimit || 100, pool));
    const proposed = Math.max(1, Math.min(wanted, quotaLeft || pool));

    sendDialog = {
        open: true, mode: 'foreground', limit: proposed, includeFailed, useSelection, pool,
        // When today's allowance forced a smaller batch than this campaign's
        // usual one, say so — otherwise the operator sees an unexplained number
        // and assumes the setting changed.
        clampedFrom: proposed < wanted ? wanted : null,
    };
    document.getElementById('sendModal').classList.add('open');
    renderSendDialog();
}

function closeSendDialog() {
    sendDialog.open = false;
    document.getElementById('sendModal').classList.remove('open');
}

function setSendLimit(v) {
    const n = parseInt(v);
    sendDialog.limit = Number.isFinite(n) && n > 0 ? n : 1;
    renderSendDialog();
}

function setSendMode(mode) {
    sendDialog.mode = mode;
    renderSendDialog();
}

function applySendPreset(n) {
    sendDialog.limit = Math.max(1, Math.min(n, sendDialog.pool));
    renderSendDialog();
}

function renderSendDialog() {
    const body = document.getElementById('sendModalBody');
    const footer = document.getElementById('sendModalFooter');
    if (!body) return;

    const d = sendDialog;
    const pool = d.pool;
    const limit = Math.min(d.limit, pool);
    const tpl = activeCampaign.wa_template_name || '';
    const q = waQuota || {};
    const quotaLeft = (q.remaining === null || q.remaining === undefined) ? null : q.remaining;
    const capped = quotaLeft !== null && limit > quotaLeft;
    const effective = capped ? quotaLeft : limit;

    // Pacing is server-side (default 250ms), so time is predictable.
    const seconds = Math.ceil(effective * 0.35);
    const eta = seconds < 60 ? `${seconds} seconds` : `${Math.ceil(seconds / 60)} minute${Math.ceil(seconds/60) === 1 ? '' : 's'}`;
    const sessionsLeft = effective > 0 ? Math.ceil((pool - effective) / Math.max(1, effective)) : 0;

    document.getElementById('sendModalTitle').textContent = d.includeFailed ? 'Retry Failed Messages' : 'Send Messages';

    const presets = [50, 100, 250, 500].filter(n => n < pool);

    body.innerHTML = `
        <div style="padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;margin-bottom:16px;font-size:12px;color:#475569;line-height:1.6;">
            Template <b>${esc(tpl)}</b> → <b>${pool}</b> ${d.useSelection ? 'selected' : (d.includeFailed ? 'failed' : 'waiting')} customer${pool === 1 ? '' : 's'}
            ${d.useSelection ? '<div style="margin-top:2px;color:#7c3aed;">Only your current selection will be messaged.</div>' : ''}
            ${(!d.useSelection && detailSort) ? `<div style="margin-top:2px;color:#7c3aed;">Send order: <b>${esc(sortLabel(detailSort.by, detailSort.dir))}</b> — "the first ${Math.min(d.limit, pool)}" means the top of that list.</div>` : ''}
        </div>

        <div class="camp-form-group">
            <label>How many to send in this session?</label>
            <input type="number" id="sendLimitInput" value="${limit}" min="1" max="${pool}" onchange="setSendLimit(this.value)" style="font-size:18px;font-weight:700;">
            <div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;">
                ${presets.map(n => `<button type="button" class="camp-btn camp-btn-secondary" style="padding:3px 10px;font-size:11px;" onclick="applySendPreset(${n})">${n}</button>`).join('')}
                <button type="button" class="camp-btn camp-btn-secondary" style="padding:3px 10px;font-size:11px;" onclick="applySendPreset(${pool})">All ${pool}</button>
            </div>
            <div style="font-size:11px;color:#64748b;margin-top:6px;line-height:1.5;">
                The rest stay <b>Pending</b> and wait for your next session — nobody is dropped, and nobody is messaged twice.
            </div>
        </div>

        <div style="display:flex;gap:8px;margin:14px 0;">
            <div style="flex:1;padding:8px 10px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;">
                <div style="font-size:17px;font-weight:700;color:#0f172a;">${effective}</div>
                <div style="font-size:10px;color:#64748b;">will be messaged now</div>
            </div>
            <div style="flex:1;padding:8px 10px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;">
                <div style="font-size:17px;font-weight:700;color:#0f172a;">~${eta}</div>
                <div style="font-size:10px;color:#64748b;">estimated time</div>
            </div>
            <div style="flex:1;padding:8px 10px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;">
                <div style="font-size:17px;font-weight:700;color:#0f172a;">${Math.max(0, pool - effective)}</div>
                <div style="font-size:10px;color:#64748b;">left after this${sessionsLeft > 0 ? ` (~${sessionsLeft} more session${sessionsLeft === 1 ? '' : 's'})` : ''}</div>
            </div>
        </div>

        ${renderQuotaBlock(effective, capped, quotaLeft, d.clampedFrom)}

        <hr style="border:none;border-top:1px solid #e2e8f0;margin:16px 0;">

        <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer;padding:10px;border:2px solid ${d.mode === 'foreground' ? '#7c3aed' : '#e2e8f0'};border-radius:6px;margin-bottom:8px;">
            <input type="radio" name="sendMode" ${d.mode === 'foreground' ? 'checked' : ''} onchange="setSendMode('foreground')" style="margin-top:2px;">
            <span>
                <b style="font-size:13px;">Send now, while I watch</b>
                <div style="font-size:11px;color:#64748b;margin-top:2px;">Progress shows live here. <b>Keep this page open</b> until it finishes.</div>
            </span>
        </label>

        <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer;padding:10px;border:2px solid ${d.mode === 'background' ? '#7c3aed' : '#e2e8f0'};border-radius:6px;${(q.background_enabled === false) ? 'opacity:.55;' : ''}">
            <input type="radio" name="sendMode" ${d.mode === 'background' ? 'checked' : ''} ${(q.background_enabled === false) ? 'disabled' : ''} onchange="setSendMode('background')" style="margin-top:2px;">
            <span>
                <b style="font-size:13px;">Send in the background</b>
                <div style="font-size:11px;color:#64748b;margin-top:2px;">
                    ${(q.background_enabled === false)
                        ? 'Currently switched off by the system setting <code>wa_campaign_auto_send</code>.'
                        : 'Runs on the server — <b>you can close this page</b>. Best for large batches. Pauses itself if the daily limit is reached and resumes when it resets.'}
                </div>
            </span>
        </label>
        ${d.useSelection && d.mode === 'background' ? `<div style="margin-top:8px;font-size:11px;color:#166534;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:8px;">Only your hand-picked selection will be messaged — the server keeps sending after you close this page.</div>` : ''}
    `;

    const blocked = effective <= 0;
    footer.innerHTML = `
        <button class="camp-btn camp-btn-secondary" onclick="closeSendDialog()">Cancel</button>
        <button class="camp-btn camp-btn-primary camp-btn-lg" id="sendConfirmBtn" ${blocked ? 'disabled' : ''} onclick="confirmSend()">
            ${blocked ? 'Nothing to send' : (d.mode === 'background' ? `Start in background (${effective})` : `Send ${effective} now`)}
        </button>`;
}

// What today's WhatsApp allowance looks like, and what it means for this batch.
// Meta's tier is the single most common reason a big campaign stalls, so it is
// stated before the send, not discovered afterwards.
// clampedFrom = the batch size this campaign would normally send, when today's
// allowance forced a smaller one. Passed in rather than read from the dialog
// state, which is local to renderSendDialog().
function renderQuotaBlock(effective, capped, quotaLeft, clampedFrom) {
    const q = waQuota || {};
    if (q.unlimited) {
        return `<div style="font-size:11px;color:#64748b;padding:8px 10px;background:#f8fafc;border-radius:6px;">Daily send limit is switched off (<code>wa_daily_send_cap</code> = 0), so nothing will be capped.</div>`;
    }
    if (quotaLeft === null || quotaLeft === undefined) return '';

    const cap = q.cap || 0;
    const used = q.used || 0;
    const pct = cap > 0 ? Math.min(100, Math.round((used / cap) * 100)) : 0;

    return `
    <div style="padding:10px;background:${capped ? '#fffbeb' : '#f8fafc'};border:1px solid ${capped ? '#fde68a' : '#e2e8f0'};border-radius:6px;">
        <div style="display:flex;justify-content:space-between;font-size:11px;color:#475569;margin-bottom:5px;">
            <span>WhatsApp allowance today</span>
            <span><b>${used}</b> used of ${cap} · <b>${quotaLeft}</b> left</span>
        </div>
        <div style="height:5px;background:#e2e8f0;border-radius:3px;overflow:hidden;">
            <div style="height:100%;width:${pct}%;background:${pct > 85 ? '#dc2626' : '#10b981'};"></div>
        </div>
        ${capped ? `<div style="font-size:11px;color:#92400e;margin-top:6px;">Your batch is bigger than today's remaining allowance, so only <b>${quotaLeft}</b> will go out now. The rest stay Pending — send them tomorrow.</div>` : ''}
        ${(!capped && clampedFrom) ? `<div style="font-size:11px;color:#92400e;margin-top:6px;">This campaign normally sends <b>${clampedFrom}</b> at a time, but only <b>${quotaLeft}</b> of today's allowance is left — so the batch has been reduced to fit. The rest stay Pending.</div>` : ''}
        <div style="font-size:10px;color:#94a3b8;margin-top:5px;">Counts every template message in the last 24h, including invoices — they share the same WhatsApp limit.</div>
    </div>`;
}

async function confirmSend() {
    const d = sendDialog;
    const btn = document.getElementById('sendConfirmBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="camp-spinner"></span> Starting...'; }

    if (d.mode === 'background') {
        const payload = { limit: d.limit };
        // The worker honours a hand-picked audience via the run row.
        if (d.useSelection) payload.customer_ids = selectedCustomerIds.slice();
        const data = await apiFetch(`/campaigns/${activeCampaignId}/send-background`, {
            method: 'POST',
            body: JSON.stringify(payload),
        });
        closeSendDialog();
        if (data.success) {
            sendState = 'running';
            if (d.useSelection) selectedCustomerIds = [];
            alert(data.message || 'Started in the background.');
            loadCampaignDetail(activeCampaignId, customerStatusFilter);
            loadCampaigns();
        } else {
            alert(data.message || 'Could not start the background send.');
        }
        return;
    }

    // ---- Foreground: run the session to completion in this tab -----------
    closeSendDialog();
    bulkSending = true;
    bulkProgress = { done: 0, total: Math.min(d.limit, d.pool), sent: 0, failed: 0 };
    renderCampaignDetail();

    const agg = await runSendSession(d.limit, d.includeFailed, d.useSelection ? selectedCustomerIds.slice() : null);

    bulkSending = false;
    bulkProgress = null;
    selectedCustomerIds = [];
    await loadCampaignDetail(activeCampaignId, customerStatusFilter);
    loadCampaigns();
    showSendSummary(agg);
}

/**
 * Drives one session to its end. Each POST does a bounded slice of work and
 * comes back; 'time_budget' means "still more to do in this session", anything
 * else means stop. Total sent can never exceed the session limit because the
 * server enforces it against the run row.
 */
async function runSendSession(limit, includeFailed, customerIds) {
    const agg = { sent: 0, failed: 0, excluded: 0, errors: [], stop_reason: null, message: null };
    let runId = null;
    let guard = 0;

    while (guard++ < 400) {
        const body = { limit, body_params: ['@{{customer_name}}'] };
        if (includeFailed) body.include_failed = true;
        if (customerIds) body.customer_ids = customerIds;
        if (runId) body.run_id = runId;

        let data;
        try {
            data = await apiFetch(`/campaigns/${activeCampaignId}/send`, { method: 'POST', body: JSON.stringify(body) });
        } catch (e) {
            agg.errors.push({ error: 'Network error — stopped. Anyone not yet messaged is still Pending.' });
            agg.stop_reason = 'network_error';
            break;
        }

        if (!data.success) {
            agg.errors.push({ error: data.message || 'Send failed' });
            agg.stop_reason = data.busy ? 'busy' : 'error';
            agg.message = data.message;
            break;
        }

        const r = data.results || {};
        agg.sent += (r.sent || 0);
        agg.failed += (r.failed || 0);
        agg.excluded += (r.excluded || 0);
        if (Array.isArray(r.errors)) agg.errors = agg.errors.concat(r.errors);
        agg.stop_reason = data.stop_reason;
        agg.message = data.message;
        runId = data.run_id || runId;
        if (data.quota) waQuota = data.quota;
        if (data.counts) campaignCounts = data.counts;

        bulkProgress = {
            done: agg.sent + agg.failed,
            total: Math.min(limit, bulkProgress ? bulkProgress.total : limit),
            sent: agg.sent, failed: agg.failed,
        };
        renderCampaignDetail();

        // Only 'time_budget' means there is more of THIS session left to do.
        if (data.stop_reason !== 'time_budget') break;
    }

    return agg;
}

// One plain-language summary. The old version reported raw counts only, which
// left the operator guessing why sent+failed didn't add up to what they picked.
function showSendSummary(agg) {
    const lines = [];
    lines.push(agg.message || `Sent ${agg.sent}${agg.failed ? `, ${agg.failed} failed` : ''}.`);
    if (agg.excluded > 0) {
        lines.push(`\n${agg.excluded} moved to Excluded — they had already received this template recently, so they were not messaged again.`);
    }
    if (agg.errors.length) {
        const uniq = [...new Set(agg.errors.map(e => e.error))].slice(0, 4);
        lines.push('\nProblems reported:\n• ' + uniq.join('\n• '));
    }
    alert(lines.join('\n'));
}

async function pauseBackgroundSend() {
    if (!confirm('Pause sending? Anyone not yet messaged stays Pending and you can resume any time.')) return;
    const data = await apiFetch(`/campaigns/${activeCampaignId}/send-pause`, { method: 'POST' });
    if (data.success) {
        sendState = 'idle';
        sendRun = null;
        managePolling();
        loadCampaignDetail(activeCampaignId, customerStatusFilter);
        loadCampaigns();
    } else {
        alert(data.message || 'Could not pause.');
    }
}

function bulkSend() {
    openSendDialog({ useSelection: true });
}

// Manual re-check of the dedup window for this campaign. Re-evaluates
// every pending customer against the current send history; matches move
// to the Excluded tab (status='skipped' + 'Excluded:' prefix). Same
// behaviour as the send-time guard but without actually sending anything
// — useful when the operator wants to know the true pending count
// before hitting Send on an old campaign.
async function refreshDedup() {
    if (!activeCampaignId) return;
    if (!confirm('Refresh Dedup: re-check pending customers against recent sends. Matches will be moved to the Excluded tab (no messages will be sent). Continue?')) return;

    try {
        const data = await apiFetch(`/campaigns/${activeCampaignId}/refresh-dedup`, { method: 'POST' });
        if (!data.success) {
            alert(data.message || 'Failed to refresh dedup');
            return;
        }
        const moved = data.moved || 0;
        const scanned = data.pending_scanned || 0;
        if (moved === 0 && scanned === 0) {
            alert(data.message || 'No pending customers to re-check.');
        } else if (moved === 0) {
            alert(`Scanned ${scanned} pending customer(s). None needed to be excluded — your list is already up to date.`);
        } else {
            alert(`Scanned ${scanned} pending customer(s).\n${moved} moved to the Excluded tab (already received this template in the last ${data.dedup_window_days} day${data.dedup_window_days === 1 ? '' : 's'}).`);
        }
        loadCampaignDetail(activeCampaignId, customerStatusFilter);
        loadCampaigns();
    } catch (e) {
        alert('Network error while refreshing dedup');
    }
}

// ======= RETRY (failed rows) =======
async function retrySingle(customerId, name) {
    if (!activeCampaign?.wa_template_name) {
        alert('This campaign has no WhatsApp template configured.');
        return;
    }
    if (!confirm(`Retry sending "${activeCampaign.wa_template_name}" to ${name}?`)) return;

    const data = await apiFetch(`/campaigns/${activeCampaignId}/send`, {
        method: 'POST',
        body: JSON.stringify({ limit: 1, customer_ids: [customerId], body_params: ['@{{customer_name}}'], include_failed: true })
    });

    if (data.success) {
        const r = data.results;
        if (r.failed > 0) {
            alert('Retry failed: ' + (r.errors?.[0]?.error || 'Unknown error'));
        } else if (['daily_cap', 'rate_limited', 'auth_error'].includes(data.stop_reason)) {
            alert(data.message || 'Could not send right now.');
        }
        loadCampaignDetail(activeCampaignId, customerStatusFilter);
        loadCampaigns();
    } else {
        alert(data.message || 'Failed to retry');
    }
}

// Both retry paths go through the same send dialog as a normal send, so a retry
// of 400 failed rows gets the same batching, allowance check and background
// option instead of firing everything at once.
function bulkRetry() {
    if (!activeCampaign?.wa_template_name || selectedCustomerIds.length === 0) return;
    openSendDialog({ useSelection: true, includeFailed: true });
}

function retryAllFailed() {
    if (!activeCampaign?.wa_template_name) return;
    if ((parseInt((campaignCounts || {}).failed || 0)) === 0) return;
    openSendDialog({ includeFailed: true });
}

async function skipCustomer(customerId) {
    const data = await apiFetch(`/campaigns/${activeCampaignId}/customers/${customerId}/skip`, {
        method: 'POST'
    });
    if (data.success) {
        campaignCounts = data.counts;
        campaignCustomers = campaignCustomers.filter(c => c.customer_id !== customerId);
        selectedCustomerIds = selectedCustomerIds.filter(id => id !== customerId);
        renderCampaignDetail();
        loadCampaigns();
    }
}

async function endCampaign(id) {
    if (!confirm('End this campaign? This cannot be undone.')) return;
    const data = await apiFetch(`/campaigns/${id}/end`, { method: 'POST' });
    if (data.success) {
        loadCampaignDetail(id, customerStatusFilter);
        loadCampaigns();
    }
}

async function openStatsModal(id) {
    document.getElementById('statsModal').classList.add('open');
    document.getElementById('statsBody').innerHTML = '<div style="text-align:center;padding:30px;"><div class="camp-spinner camp-spinner-dark"></div></div>';

    const data = await apiFetch(`/campaigns/${id}/stats`);
    if (!data.success) {
        document.getElementById('statsBody').innerHTML = '<p style="text-align:center;color:#dc2626;">Failed to load results</p>';
        return;
    }

    renderStatsBody(data.stats);
}

function renderStatsBody(s) {
    // A tracking_note means the campaign's setup can't fully deliver what its
    // type implies (e.g. "products" with no products saved). Shown first so the
    // numbers below are read with that caveat in mind.
    const note = s.tracking_note
        ? `<div style="margin-bottom:12px;padding:9px 11px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;font-size:11px;color:#92400e;line-height:1.5;">${esc(s.tracking_note)}</div>`
        : '';

    const changeBtn = `<div style="display:flex;justify-content:flex-end;margin-bottom:8px;">
        <button class="camp-btn camp-btn-secondary" style="padding:3px 10px;font-size:11px;" onclick="openTrackingModal()">Change tracking</button>
    </div>`;

    document.getElementById('statsBody').innerHTML =
        changeBtn
        + note
        + renderFunnelBlock(s.funnel, s.tracking_window_days, s.tracking_type, s.engagement, s.tracked_categories)
        + renderCategoryBreakdown(s.category_breakdown, s.tracked_categories)
        + renderSourceSplitBlock(s.source_split, s.funnel.orders)
        + renderProductBreakdown(s.product_breakdown)
        + renderRunHistoryBlock();
}

// What the people who ordered after the message actually bought — on every
// campaign, whatever it tracks. Answers "of those who ordered, how many took
// frozen vs mutton vs chicken".
function renderCategoryBreakdown(cb, trackedCats) {
    if (!cb || !cb.rows || !cb.rows.length) return '';
    const tracked = trackedCats || [];
    let html = `<h4 style="font-size:13px;font-weight:600;margin:0 0 4px;">By category</h4>
    <div style="font-size:11px;color:#64748b;margin-bottom:8px;line-height:1.5;">
        Everyone who ordered <b>anything</b> after the message (${cb.any.customers} customer${cb.any.customers == 1 ? '' : 's'}, ${cb.any.orders} order${cb.any.orders == 1 ? '' : 's'}), split by what was in their orders.
        One order can contain several categories, so the rows add up to more than the total.
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:6px;">
        <thead><tr style="color:#64748b;text-align:left;">
            <th style="padding:4px 6px;font-weight:600;">Category</th>
            <th style="padding:4px 6px;font-weight:600;text-align:right;">Customers</th>
            <th style="padding:4px 6px;font-weight:600;text-align:right;">Orders</th>
            <th style="padding:4px 6px;font-weight:600;text-align:right;" title="Value of that category's items only, not the whole order">Item value</th>
        </tr></thead><tbody>`;
    cb.rows.forEach(r => {
        const isTracked = tracked.includes(r.category);
        const isFrozen = r.category === cb.frozen_label;
        html += `<tr style="border-top:1px solid #f1f5f9;${isTracked ? 'background:#f5f3ff;font-weight:600;' : ''}">
            <td style="padding:5px 6px;">${esc(r.category)}${isTracked ? ' <span style="font-size:10px;color:#7c3aed;">tracked</span>' : ''}
                ${isFrozen && cb.new_to_frozen > 0 ? `<div style="font-size:10px;color:#16a34a;font-weight:600;">${cb.new_to_frozen} bought frozen for the first time</div>` : ''}</td>
            <td style="padding:5px 6px;text-align:right;">${r.customers}</td>
            <td style="padding:5px 6px;text-align:right;">${r.orders}</td>
            <td style="padding:5px 6px;text-align:right;">PKR ${Math.round(r.value).toLocaleString()}</td>
        </tr>`;
    });
    html += `</tbody></table>
    <div style="font-size:10px;color:#94a3b8;margin-bottom:16px;line-height:1.5;">
        Same grouping as HQ (Frozen = Khaas + Table Talk products). "First time" is only shown for frozen: older meat orders are on the previous catalog, so it can't be worked out reliably for meat.
    </div>`;
    return html;
}

// The full funnel, with each step's meaning spelled out. The percentages are all
// relative to Sent, so they read as one narrowing story rather than five
// unrelated numbers.
function renderFunnelBlock(f, windowDays, trackingType, engagement, trackedCats) {
    const eg = engagement || {};
    const egPct = (n) => f.sent > 0 ? Math.round((n / f.sent) * 1000) / 10 : 0;
    const rows = [
        ['Sent',        f.sent,        null,                'Messages WhatsApp accepted from us.', '#0f172a'],
        ['Delivered',   f.delivered,   f.rates.delivered,   'Confirmed as reaching the phone.', '#0891b2'],
        ['Read',        f.read,        f.rates.read,        'Opened by the customer. A minimum only — people who turn read receipts off never report a read.', '#7c3aed'],
        ['Replied',     f.replied,     f.rates.replied,     'Wrote back to us on WhatsApp — a message, photo, voice note or reaction. Button taps are not counted here.', '#2563eb'],
    ];
    if (engagement) {
        rows.push(['Tapped a button',     eg.tapped || 0,       egPct(eg.tapped || 0),       'Tapped a quick-reply button (e.g. Menu). The system answers these automatically.', '#6b21a8']);
        rows.push(['Had a conversation',  eg.conversation || 0, egPct(eg.conversation || 0), 'They wrote to us and someone from the team replied after that.', '#0369a1']);
        rows.push(['Waiting on us',       eg.waiting || 0,      egPct(eg.waiting || 0),      'They wrote to us and nobody from the team has replied since.', '#b45309']);
    }
    rows.push(['Ordered', f.ordered, f.rates.ordered, `Placed an order within ${windowDays} days of the message. Counted as soon as the order exists — cancelled orders are excluded.`, '#16a34a']);

    const typeNote = trackingType === 'app_orders'
        ? `Only orders placed <b>through the customer app</b> count as a conversion here.`
        : (trackingType === 'products'
            ? `Only orders containing the <b>tracked products</b> count as a conversion here.`
            : (trackingType === 'category'
                ? `Only orders containing <b>${esc((trackedCats || []).join(' / ') || 'the tracked category')}</b> products count as a conversion here.`
                : `Any order placed within the window counts.`));

    let html = `<h4 style="font-size:13px;font-weight:600;margin:0 0 10px;">Funnel</h4>`;

    if (f.receipts_tracked === 0 && f.sent > 0) {
        html += `<div style="margin-bottom:10px;padding:8px 10px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:6px;font-size:11px;color:#64748b;">
            Delivered/Read are blank because these messages were sent before delivery tracking existed (Jul-2026). Replies and orders are still accurate.
        </div>`;
    }

    html += '<div style="margin-bottom:14px;">';
    rows.forEach(([label, val, pct, tip, color]) => {
        const width = f.sent > 0 ? Math.max(1, Math.round((val / f.sent) * 100)) : 0;
        html += `
        <div style="margin-bottom:7px;" title="${tip}">
            <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:2px;">
                <span style="color:#475569;">${label}</span>
                <span style="font-weight:600;color:${color};">${val}${pct !== null ? ` <span style="color:#94a3b8;font-weight:400;">${pct}%</span>` : ''}</span>
            </div>
            <div style="height:6px;background:#f1f5f9;border-radius:3px;overflow:hidden;">
                <div style="height:100%;width:${width}%;background:${color};opacity:.85;"></div>
            </div>
        </div>`;
    });
    html += '</div>';

    if (f.undelivered > 0) {
        html += `<div style="margin-bottom:14px;padding:8px 10px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;font-size:11px;color:#991b1b;">
            <b>${f.undelivered} could not be delivered</b> (${f.rates.undelivered}%) — dead numbers, or WhatsApp's marketing frequency cap. Open the <b>Undelivered</b> tab to review, requeue, or clean them up.
        </div>`;
    }

    html += `
    <div class="camp-stats-grid" style="margin-bottom:6px;">
        <div class="camp-stats-card"><div class="value">${f.orders}</div><div class="label">Orders placed</div></div>
        <div class="camp-stats-card"><div class="value">PKR ${parseFloat(f.revenue || 0).toLocaleString()}</div><div class="label">Order value in window</div></div>
    </div>
    <div style="font-size:10px;color:#94a3b8;margin-bottom:16px;line-height:1.5;">
        ${typeNote} These are orders that happened <b>after</b> the message within ${windowDays} days — timing, not proof the message caused them.
        Counted as soon as the order is placed (cancelled and refunded excluded), so this value <b>includes orders not yet delivered</b>
        and will read higher than the delivered-only revenue on HQ and Reports.
    </div>`;

    return html;
}

// Where the attributed orders actually came from. This is the number that
// answers "is the app campaign working?" — app orders rising is the goal.
function renderSourceSplitBlock(split, totalOrders) {
    if (!split || !totalOrders) return '';
    const pct = (n) => totalOrders > 0 ? Math.round((n / totalOrders) * 100) : 0;
    return `
    <h4 style="font-size:13px;font-weight:600;margin:0 0 8px;">Where those orders came from</h4>
    <div class="camp-stats-grid" style="margin-bottom:16px;">
        <div class="camp-stats-card"><div class="value" style="color:#7c3aed;">${split.app}</div><div class="label">📱 Customer app (${pct(split.app)}%)</div></div>
        <div class="camp-stats-card"><div class="value" style="color:#0891b2;">${split.web}</div><div class="label">🌐 Website (${pct(split.web)}%)</div></div>
        <div class="camp-stats-card"><div class="value" style="color:#64748b;">${split.manual}</div><div class="label">Phone / manual (${pct(split.manual)}%)</div></div>
    </div>`;
}

function renderProductBreakdown(items) {
    if (!items || !items.length) return '';
    let html = '<h4 style="font-size:13px;font-weight:600;margin:0 0 8px;">Tracked products ordered</h4><div style="max-height:180px;overflow-y:auto;margin-bottom:16px;">';
    items.forEach(p => {
        html += `<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:12px;">
            <span>${esc(p.product_name || 'Product #' + p.product_id)}</span>
            <span style="color:#16a34a;">${p.total_qty} × · PKR ${parseFloat(p.total_value).toLocaleString()}</span>
        </div>`;
    });
    return html + '</div>';
}

// Session history — "when did I send, how many, and why did it stop".
function renderRunHistoryBlock() {
    if (!sendRunHistory || !sendRunHistory.length) return '';
    const reasonLabel = {
        completed: 'Finished — everyone messaged',
        target_reached: 'Batch complete',
        daily_cap: 'Stopped — daily WhatsApp limit',
        rate_limited: 'Stopped — WhatsApp throttled us',
        auth_error: 'Stopped — account/token problem',
        too_many_failures: 'Stopped — too many failures',
        operator_paused: 'Paused by user',
        no_eligible: 'Nobody left to send to',
        media_missing: 'Stopped — template image missing on server',
        all_excluded: 'All had the template recently',
        campaign_ended: 'Campaign ended',
        time_budget: 'In progress',
    };
    let html = '<h4 style="font-size:13px;font-weight:600;margin:0 0 8px;">Send sessions</h4><div style="max-height:190px;overflow-y:auto;">';
    sendRunHistory.forEach(r => {
        const when = r.started_at ? String(r.started_at).substring(0, 16).replace('T', ' ') : '';
        const label = reasonLabel[r.stop_reason] || (r.stop_reason || (r.finished_at ? 'Done' : 'Running'));
        html += `<div style="padding:7px 0;border-bottom:1px solid #f1f5f9;font-size:12px;">
            <div style="display:flex;justify-content:space-between;gap:8px;">
                <span style="color:#475569;">${when}${r.mode === 'background' ? ' · background' : ''}${r.started_by_name ? ' · ' + esc(r.started_by_name) : ''}</span>
                <span style="font-weight:600;color:#16a34a;">${r.sent_count} sent${parseInt(r.failed_count) > 0 ? ` <span style="color:#dc2626;">· ${r.failed_count} failed</span>` : ''}</span>
            </div>
            <div style="font-size:10px;color:#94a3b8;margin-top:2px;">Asked for ${r.target_count} · ${esc(label)}</div>
        </div>`;
    });
    return html + '</div>';
}

// ==========================================================================
// TIMELINE (Oct-2026) — one customer's story after the message
// ==========================================================================

function fmtDateTime(v) {
    if (!v) return '';
    const s = String(v).replace('T', ' ');
    const d = new Date(s.replace(' ', 'T'));
    if (isNaN(d)) return s.substring(0, 16);
    return d.toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

// "3h 10m", "2d 4h" — how long after the message something happened.
function gapText(from, to) {
    if (!from || !to) return '';
    const a = new Date(String(from).replace(' ', 'T'));
    const b = new Date(String(to).replace(' ', 'T'));
    if (isNaN(a) || isNaN(b)) return '';
    let mins = Math.max(0, Math.round((b - a) / 60000));
    if (mins < 60) return mins + 'm';
    const hrs = Math.floor(mins / 60); mins = mins % 60;
    if (hrs < 24) return hrs + 'h' + (mins ? ' ' + mins + 'm' : '');
    const days = Math.floor(hrs / 24);
    return days + 'd' + (hrs % 24 ? ' ' + (hrs % 24) + 'h' : '');
}

async function openTimeline(customerId) {
    if (!activeCampaignId) return;
    document.getElementById('timelineModal').classList.add('open');
    document.getElementById('timelineTitle').textContent = 'Customer timeline';
    document.getElementById('timelineBody').innerHTML = '<div style="text-align:center;padding:30px;"><div class="camp-spinner camp-spinner-dark"></div></div>';

    let data;
    try { data = await apiFetch(`/campaigns/${activeCampaignId}/customers/${customerId}/timeline`); } catch (e) { data = null; }
    if (!data || !data.success) {
        document.getElementById('timelineBody').innerHTML = `<p style="text-align:center;color:#dc2626;">${esc((data && data.message) || 'Could not load the timeline.')}</p>`;
        return;
    }
    renderTimeline(data.timeline);
}

function closeTimeline() {
    document.getElementById('timelineModal').classList.remove('open');
}

function renderTimeline(t) {
    const cu = t.customer || {};
    const name = ((cu.first_name || '') + ' ' + (cu.last_name || '')).trim() || 'Customer';
    const phone = cu.phone || cu.phone_normalized || '';
    document.getElementById('timelineTitle').textContent = name;

    const r = t.row || {};
    const s = t.summary;
    const sent = r.sent_at;
    const fact = (label, val, color) => `<div style="flex:1;min-width:120px;padding:7px 9px;border:1px solid #e2e8f0;border-radius:6px;">
        <div style="font-size:10px;color:#64748b;">${label}</div>
        <div style="font-size:12px;font-weight:600;color:${color || '#0f172a'};margin-top:2px;">${val}</div>
    </div>`;
    const when = (v) => v ? `${esc(fmtDateTime(v))} <span style="color:#94a3b8;font-weight:400;">(+${esc(gapText(sent, v))})</span>` : '<span style="color:#94a3b8;font-weight:400;">—</span>';

    let html = `<div style="font-size:12px;color:#64748b;margin-bottom:10px;">
        ${esc(phone)}${cu.city ? ' · ' + esc(cu.city) : ''}
        ${phone ? ` · <a href="/messages?focus_phone=${encodeURIComponent(phone)}" target="_blank" style="color:#7c3aed;">Open chat ↗</a>` : ''}
    </div>`;

    if (r.status !== 'sent' || !s) {
        html += `<div style="padding:12px;background:#f8fafc;border-radius:6px;font-size:12px;color:#64748b;">Not sent yet (status: ${esc(r.status || '?')}).</div>`;
        document.getElementById('timelineBody').innerHTML = html;
        return;
    }

    html += `<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;">
        ${fact('Message sent', esc(fmtDateTime(sent)))}
        ${fact('Delivered', r.undelivered_at ? '<span style="color:#dc2626;">Not delivered</span>' : when(r.delivered_at || r.read_at), '#0891b2')}
        ${fact('Read', when(r.read_at), '#7c3aed')}
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;">
        ${fact('First reply', when(s.replied_at), '#2563eb')}
        ${fact('Tapped a button', when(s.tapped_at), '#6b21a8')}
        ${fact('Team answered', s.team_replied_at
            ? `${esc(fmtDateTime(s.team_replied_at))} <span style="color:#94a3b8;font-weight:400;">(${esc(gapText(s.wrote_at, s.team_replied_at))} after they wrote)</span>`
            : (s.waiting ? '<span style="color:#b45309;">Not yet — waiting on us</span>' : '<span style="color:#94a3b8;font-weight:400;">—</span>'), '#0369a1')}
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;">
        ${fact('First counted order', s.first_order_at ? when(s.first_order_at) : '<span style="color:#94a3b8;font-weight:400;">None</span>', '#16a34a')}
        ${fact('Window ends', esc(fmtDateTime(s.window_ends_at)))}
    </div>`;

    // Orders
    html += `<h4 style="font-size:13px;font-weight:600;margin:0 0 6px;">Orders in the window (${(t.orders || []).length})</h4>`;
    if (!(t.orders || []).length) {
        html += `<div style="font-size:12px;color:#94a3b8;margin-bottom:14px;">No orders within ${t.campaign.window_days} days of the message.</div>`;
    } else {
        html += '<div style="margin-bottom:14px;">';
        t.orders.forEach(o => {
            const cancelled = ['cancelled', 'refunded'].includes(String(o.status || '').toLowerCase());
            html += `<div style="display:flex;gap:8px;align-items:center;padding:6px 8px;border:1px solid ${o.counts ? '#bbf7d0' : '#e2e8f0'};background:${o.counts ? '#f0fdf4' : '#fff'};border-radius:6px;margin-bottom:5px;font-size:12px;flex-wrap:wrap;">
                <b>${esc(o.order_number || ('#' + o.id))}</b>
                <span style="color:#64748b;">${esc(fmtDateTime(o.order_date))} (+${esc(gapText(sent, o.order_date))})</span>
                <span>PKR ${Math.round(o.total_price || 0).toLocaleString()}</span>
                <span style="color:#64748b;">${esc(o.status || '')}</span>
                <span style="flex:1;font-size:11px;color:#475569;">${(o.categories || []).map(esc).join(' · ')}</span>
                <span style="font-size:10px;font-weight:600;color:${o.counts ? '#16a34a' : '#94a3b8'};" title="${o.counts ? 'Counts as Ordered for this campaign' : (cancelled ? 'Cancelled/refunded orders never count' : 'Does not match this campaign\'s tracking')}">${o.counts ? '✓ counts' : (cancelled ? 'cancelled' : 'not tracked')}</span>
            </div>`;
        });
        html += '</div>';
    }

    // Messages
    const who = {
        campaign: ['Campaign', '#7c3aed'], wrote: ['Customer', '#2563eb'], reaction: ['Reaction', '#2563eb'],
        tap: ['Tapped', '#6b21a8'], team: ['Team', '#0369a1'], system: ['Automatic', '#94a3b8'],
    };
    html += `<h4 style="font-size:13px;font-weight:600;margin:0 0 6px;">WhatsApp after the message (${(t.messages || []).length}${s.messages_capped ? '+' : ''})</h4>`;
    if (!(t.messages || []).length) {
        html += `<div style="font-size:12px;color:#94a3b8;">No messages found in the chat for this window${phone ? '' : ' (no phone on record)'}.</div>`;
    } else {
        t.messages.forEach(m => {
            const [label, color] = who[m.kind] || ['', '#64748b'];
            // Templates show their name (the body is a template, not chat);
            // media without a caption shows its type.
            const text = m.template ? `Template: ${m.template}` : (m.text || `[${m.type}]`);
            html += `<div class="camp-tl-item">
                <div class="camp-tl-time">${esc(fmtDateTime(m.at))}</div>
                <div class="camp-tl-who" style="color:${color};">${label}${m.kind === 'team' && m.staff ? `<div style="font-weight:400;color:#64748b;">${esc(m.staff)}</div>` : ''}</div>
                <div class="camp-tl-text"${m.template ? ' style="color:#64748b;"' : ''}>${esc(text)}</div>
            </div>`;
        });
        if (s.messages_capped) html += `<div style="font-size:11px;color:#94a3b8;margin-top:6px;">Showing the first 150 messages — open the chat for the rest.</div>`;
    }

    document.getElementById('timelineBody').innerHTML = html;
}

// ==========================================================================
// CHANGE TRACKING (Oct-2026) — what counts as "Ordered" on a live campaign
// ==========================================================================

async function openTrackingModal() {
    if (!activeCampaign) return;
    const c = activeCampaign;
    const type = c.tracking_type || 'general';
    trackedProductIds = (() => { try { return (JSON.parse(c.tracked_product_ids || '[]') || []).map(Number); } catch (e) { return []; } })();
    trackedCategories = (() => { try { return JSON.parse(c.tracked_categories || '[]') || []; } catch (e) { return []; } })();
    document.getElementById('trkEdit_filterTrackingType').value = type;
    document.getElementById('trkEdit_filterTrackingDays').value = c.tracking_window_days || 30;
    document.getElementById('trkEdit_productSearchInput').value = '';
    document.getElementById('trackingModal').classList.add('open');
    onTrackingTypeChange(type, 'edit');
}

function closeTrackingModal() {
    document.getElementById('trackingModal').classList.remove('open');
}

async function saveTracking() {
    if (!activeCampaign) return;
    const type = document.getElementById('trkEdit_filterTrackingType').value;
    if (type === 'products' && !trackedProductIds.length) { alert('Pick at least one product, or choose another option.'); return; }
    if (type === 'category' && !trackedCategories.length) { alert('Tick at least one category (e.g. Khaas · Frozen), or choose another option.'); return; }

    const btn = document.getElementById('trkEdit_saveBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="camp-spinner"></span> Saving...';
    const data = await apiFetch(`/campaigns/${activeCampaign.id}/tracking`, {
        method: 'POST',
        body: JSON.stringify({
            tracking_type: type,
            tracked_product_ids: type === 'products' ? trackedProductIds : [],
            tracked_categories: type === 'category' ? trackedCategories : [],
            tracking_window_days: parseInt(document.getElementById('trkEdit_filterTrackingDays').value) || 30,
            with_stats: document.getElementById('statsModal').classList.contains('open'),
        })
    });
    btn.disabled = false;
    btn.innerHTML = 'Save';

    if (!data || !data.success) {
        alert((data && data.message) || 'Could not save tracking.');
        return;
    }
    closeTrackingModal();
    campaignEngagement = null;
    campaignTracking = null;
    if (document.getElementById('statsModal').classList.contains('open') && data.stats) renderStatsBody(data.stats);
    loadCampaignDetail(activeCampaign.id);
}

function closeStatsModal() {
    document.getElementById('statsModal').classList.remove('open');
}

// ==========================================================================
// RESULTS BY TEMPLATE
// ==========================================================================

async function openTemplateResults(templateName) {
    document.getElementById('templateResultsModal').classList.add('open');
    document.getElementById('templateResultsBody').innerHTML = '<div style="text-align:center;padding:30px;"><div class="camp-spinner camp-spinner-dark"></div></div>';

    let data = await apiFetch(`/campaigns/by-template?template=${encodeURIComponent(templateName || '')}`);
    if (!data.success) {
        document.getElementById('templateResultsBody').innerHTML = '<p style="text-align:center;color:#dc2626;">Failed to load</p>';
        return;
    }
    templateList = data.templates || [];

    // Opened from the list header with no template — land on the most recently
    // used one instead of an empty screen the user has to fix themselves.
    if (!templateName && !data.result && templateList.length) {
        data = await apiFetch(`/campaigns/by-template?template=${encodeURIComponent(templateList[0].wa_template_name)}`);
        if (data.success) templateList = data.templates || templateList;
    }

    templateResults = data.result;
    renderTemplateResults();
}

function closeTemplateResults() {
    document.getElementById('templateResultsModal').classList.remove('open');
}

function renderTemplateResults() {
    const r = templateResults;
    const body = document.getElementById('templateResultsBody');

    const switcher = `
        <div class="camp-form-group" style="margin-bottom:16px;">
            <label>Template</label>
            <select onchange="openTemplateResults(this.value)">
                ${templateList.map(t => `<option value="${esc(t.wa_template_name)}" ${r && r.template_name === t.wa_template_name ? 'selected' : ''}>${esc(t.display_name)} — ${t.campaign_count} campaign${t.campaign_count === 1 ? '' : 's'}</option>`).join('')}
            </select>
        </div>`;

    if (!r || !r.campaigns.length) {
        body.innerHTML = switcher + '<p style="text-align:center;color:#94a3b8;font-size:13px;padding:20px;">No campaigns have used this template yet.</p>';
        return;
    }

    document.getElementById('templateResultsTitle').textContent = 'Results: ' + (r.template_display_name || r.template_name);
    const cb = r.combined;

    // The combined block is the headline: how the TEMPLATE performs against
    // unique people. It is deliberately NOT the sum of the campaigns below —
    // anyone messaged by two campaigns is counted once here, credited to their
    // most recent send, which is why these two sections legitimately differ.
    let html = switcher + `
    <div style="padding:12px;background:#faf5ff;border:1px solid #e9d5ff;border-radius:8px;margin-bottom:8px;">
        <div style="font-size:13px;font-weight:700;color:#5b21b6;margin-bottom:2px;">Combined — how this template performs</div>
        <div style="font-size:11px;color:#7c3aed;margin-bottom:10px;">
            Every customer counted <b>once</b> — across all ${r.campaign_count} campaigns
            <b>and</b> any time this template was sent by hand from Messages${r.window_mixed ? `, using the widest tracking window (${r.tracking_window_days}d)` : ''}.
        </div>
        <div class="camp-stats-grid" style="margin-bottom:8px;">
            <div class="camp-stats-card"><div class="value">${Number(cb.sent).toLocaleString()}</div><div class="label">People reached</div></div>
            <div class="camp-stats-card"><div class="value" style="color:#2563eb;">${Number(cb.replied).toLocaleString()}</div><div class="label">Replied (${cb.rates.replied}%)</div></div>
            <div class="camp-stats-card"><div class="value" style="color:#16a34a;">${Number(cb.ordered).toLocaleString()}</div><div class="label">Ordered (${cb.rates.ordered}%)</div></div>
            <div class="camp-stats-card"><div class="value">PKR ${parseFloat(cb.revenue || 0).toLocaleString()}</div><div class="label">Revenue</div></div>
        </div>
        ${Number(cb.outside_campaigns || 0) > 0 ? `<div style="font-size:11px;color:#5b21b6;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:6px;padding:8px;margin-bottom:6px;">
            <b>${Number(cb.from_campaign).toLocaleString()}</b> of these were reached by the campaigns below;
            <b>${Number(cb.outside_campaigns).toLocaleString()}</b> got the template sent by hand from Messages
            (those are counted here but belong to no campaign, so the per-campaign rows below will not add up to this total).
        </div>` : ''}
        ${cb.duplicate_sends > 0 ? `<div style="font-size:11px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:8px;">
            <b>${cb.sends} messages went to ${cb.sent} people</b> — ${cb.duplicate_sends} customer${cb.duplicate_sends === 1 ? '' : 's'} received this template more than once. Worth watching for message fatigue.
        </div>` : `<div style="font-size:11px;color:#166534;">No one received this template twice.</div>`}
    </div>
    ${renderSourceSplitBlock(r.combined_source_split, cb.orders)}

    <h4 style="font-size:13px;font-weight:600;margin:16px 0 8px;">Each campaign that used it</h4>
    <div style="font-size:11px;color:#64748b;margin-bottom:10px;">Click a campaign to open it. Adding these up will exceed the combined figure above wherever campaigns overlapped.</div>`;

    r.campaigns.forEach(c => {
        const f = c.funnel;
        const sent = f.sent;
        const dates = c.first_sent_at
            ? `${String(c.first_sent_at).substring(0,10)}${c.last_sent_at && String(c.last_sent_at).substring(0,10) !== String(c.first_sent_at).substring(0,10) ? ' → ' + String(c.last_sent_at).substring(0,10) : ''}`
            : (c.created_at ? 'created ' + String(c.created_at).substring(0,10) : '');

        html += `
        <div style="padding:10px 12px;border:1px solid #e2e8f0;border-radius:6px;margin-bottom:8px;cursor:pointer;background:#fff;" onclick="closeTemplateResults();loadCampaignDetail(${c.campaign_id})">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
                <div>
                    <b style="font-size:13px;">${esc(c.campaign_name)}</b>
                    <span class="camp-badge ${c.status}" style="margin-left:6px;">${c.status}</span>
                    <div style="font-size:11px;color:#64748b;margin-top:2px;">${dates} · ${c.tracking_window_days}d window${c.tracking_type !== 'general' ? ' · ' + esc(c.tracking_type) : ''}</div>
                </div>
                <div style="text-align:right;font-size:12px;">
                    <div><b>${sent}</b> sent</div>
                    <div style="color:#16a34a;">${f.ordered} ordered (${f.rates.ordered}%)</div>
                </div>
            </div>
            <div style="display:flex;gap:12px;margin-top:8px;font-size:11px;color:#64748b;flex-wrap:wrap;">
                ${f.receipts_tracked > 0 ? `<span>Delivered ${f.delivered} (${f.rates.delivered}%)</span><span>Read ${f.read} (${f.rates.read}%)</span>` : '<span style="color:#94a3b8;">No delivery receipts (pre-Jul-2026)</span>'}
                <span>Replied ${f.replied} (${f.rates.replied}%)</span>
                <span>PKR ${parseFloat(f.revenue || 0).toLocaleString()}</span>
            </div>
        </div>`;
    });

    body.innerHTML = html;
}

// ==========================================================================
// CREATE CAMPAIGN MODAL
// ==========================================================================

function openCreateModal() {
    resetCreateForm();
    document.getElementById('createModal').classList.add('open');
    renderFilterGroups('create');       // initial render (with whatever cities/years are cached)
    renderExcludeCampaigns('create');
    Promise.all([loadCities(), loadQurbaniYears()]).then(() => {
        renderFilterGroups('create');   // re-render once city + year lists are loaded
    });
    loadTemplates().then(() => {});
}

function closeCreateModal() {
    document.getElementById('createModal').classList.remove('open');
}

function resetCreateForm() {
    document.getElementById('campName').value = '';
    document.getElementById('campNotes').value = '';
    document.getElementById('filterSortBy').value = 'last_order_date';
    document.getElementById('filterSortDir').value = 'desc';
    document.getElementById('filterTrackingType').value = 'general';
    document.getElementById('filterTrackingDays').value = '30';
    // Reset the template-dedup window to the default 30 days every time
    // we open Create — it's a per-campaign decision, not a session
    // preference. Present as a number input so the user can type 0 to
    // disable the check if they want to force a re-send.
    var dedupEl = document.getElementById('filterDedupDays');
    if (dedupEl) dedupEl.value = '30';
    // Pre-fill the batch size from the system default so the operator sees the
    // house rule rather than having to invent a number.
    var sessEl = document.getElementById('filterSessionLimit');
    if (sessEl) sessEl.value = String((waQuota && waQuota.default_session_limit) || 100);
    document.getElementById('previewResult').style.display = 'none';
    selectedTemplateName = '';
    trackedProductIds = [];
    trackedCategories = [];
    updateTemplateSelectBtn();
    onTrackingTypeChange('general');
    // New campaigns skip phoneless customers by default — they can only ever
    // become failures.
    filterGroups.create = [ { require_phone: 1 } ];
}

// ==========================================================================
// TRACKING TYPE (what counts as a conversion)
// ==========================================================================

let trackedProductIds = [];
let trackedCategories = [];
let categoryOptions = null;     // [{category, products}] — loaded once
let categoryTrackingAvailable = true;

// The tracking editor appears twice: in the Create form and in the
// "Change tracking" dialog. Same functions, different element ids —
// the dialog's ids carry a 'trkEdit_' prefix.
function trkId(base, scope) {
    return scope === 'edit' ? 'trkEdit_' + base : base;
}

function onTrackingTypeChange(val, scope) {
    const box  = document.getElementById(trkId('trackedProductsBox', scope));
    const cbox = document.getElementById(trkId('trackedCategoriesBox', scope));
    const hint = scope === 'edit' ? null : document.getElementById('appCampaignHint');
    if (box)  box.style.display  = val === 'products'   ? 'block' : 'none';
    if (cbox) cbox.style.display = val === 'category'   ? 'block' : 'none';
    if (hint) hint.style.display = val === 'app_orders' ? 'block' : 'none';
    if (val === 'products') searchProducts(document.getElementById(trkId('productSearchInput', scope))?.value || '', scope);
    if (val === 'category') loadCategoryOptions(scope);
}

async function loadCategoryOptions(scope) {
    if (!categoryOptions) {
        const data = await apiFetch('/campaigns/categories');
        if (data.success) {
            categoryOptions = data.categories || [];
            categoryTrackingAvailable = data.available !== false;
        } else {
            categoryOptions = [];
        }
    }
    renderCategoryPicker(scope);
}

function renderCategoryPicker(scope) {
    const el = document.getElementById(trkId('trackedCategoriesList', scope));
    if (!el) return;
    if (!categoryTrackingAvailable) {
        el.innerHTML = '<div style="font-size:12px;color:#b45309;padding:6px;">Category tracking needs the Oct-2026 database update before it can be saved.</div>';
        return;
    }
    if (!categoryOptions || !categoryOptions.length) {
        el.innerHTML = '<div style="font-size:12px;color:#94a3b8;padding:6px;">No categories found.</div>';
        return;
    }
    el.innerHTML = categoryOptions.map(c => `
        <label class="camp-exclude-item">
            <input type="checkbox" ${trackedCategories.includes(c.category) ? 'checked' : ''} onchange="toggleTrackedCategory('${esc(c.category).replace(/'/g, "\\'")}', this.checked)">
            <span style="flex:1;">${esc(c.category)}</span>
            <span style="font-size:11px;color:#94a3b8;">${c.products} product${c.products === 1 ? '' : 's'}</span>
        </label>`).join('');
}

function toggleTrackedCategory(cat, checked) {
    if (checked) {
        if (!trackedCategories.includes(cat)) trackedCategories.push(cat);
    } else {
        trackedCategories = trackedCategories.filter(x => x !== cat);
    }
}

// One-click setup for the app-install campaign: audience = people NOT already
// on the app. Without this the operator could easily track app orders while
// still messaging existing app users, which wastes the send.
function applyAppInstallPreset() {
    filterGroups.create.forEach(g => { g.mobile_app = 'not_on_app'; g.require_phone = 1; });
    if (!filterGroups.create.length) filterGroups.create = [ { mobile_app: 'not_on_app', require_phone: 1 } ];
    renderFilterGroups('create');
    alert('Audience set to customers who are NOT on the app yet. Preview the count to see how many that is.');
}

let productSearchSeq = 0;
async function searchProducts(q, scope) {
    const mine = ++productSearchSeq;
    const data = await apiFetch(`/campaigns/products?q=${encodeURIComponent(q || '')}`);
    if (mine !== productSearchSeq) return;   // a newer keystroke won
    if (data.success) {
        productOptions = data.products || [];
        renderProductPicker(scope);
    }
}

function renderProductPicker(scope) {
    const el = document.getElementById(trkId('trackedProductsList', scope));
    if (!el) return;
    const countEl = document.getElementById(trkId('trackedProductsCount', scope));
    if (countEl) countEl.textContent = trackedProductIds.length ? `${trackedProductIds.length} product${trackedProductIds.length === 1 ? '' : 's'} selected` : '';
    if (!productOptions.length) {
        el.innerHTML = '<div style="font-size:12px;color:#94a3b8;padding:6px;">No products found.</div>';
        return;
    }
    el.innerHTML = productOptions.map(p => `
        <label class="camp-exclude-item">
            <input type="checkbox" ${trackedProductIds.includes(p.id) ? 'checked' : ''} onchange="toggleTrackedProduct(${p.id}, this.checked, '${scope || ''}')">
            <span style="flex:1;">${esc(p.title || ('Product #' + p.id))}</span>
            ${p.category ? `<span style="font-size:10px;color:#64748b;background:#f1f5f9;padding:1px 6px;border-radius:8px;white-space:nowrap;">${esc(p.category)}</span>` : ''}
        </label>`).join('');
}

function toggleTrackedProduct(id, checked, scope) {
    if (checked) {
        if (!trackedProductIds.includes(id)) trackedProductIds.push(id);
    } else {
        trackedProductIds = trackedProductIds.filter(x => x !== id);
    }
    const countEl = document.getElementById(trkId('trackedProductsCount', scope));
    if (countEl) countEl.textContent = trackedProductIds.length ? `${trackedProductIds.length} product${trackedProductIds.length === 1 ? '' : 's'} selected` : '';
}

// Tick every product the current search shows — search "Khaas" and one
// click picks the whole frozen range instead of 20 separate ticks.
function selectAllShownProducts(scope) {
    productOptions.forEach(p => { if (!trackedProductIds.includes(p.id)) trackedProductIds.push(p.id); });
    renderProductPicker(scope);
}

// ==========================================================================
// FILTER GROUPS (shared by create + add-more-customers modals)
// ==========================================================================

function addFilterGroup(scope = 'create') {
    filterGroups[scope].push({});
    renderFilterGroups(scope);
}

function removeFilterGroup(scope, index) {
    filterGroups[scope].splice(index, 1);
    if (filterGroups[scope].length === 0) filterGroups[scope].push({});
    renderFilterGroups(scope);
}

function updateFilterGroup(scope, index, field, value) {
    filterGroups[scope][index] = filterGroups[scope][index] || {};
    if (value === '' || value === null) {
        delete filterGroups[scope][index][field];
    } else {
        filterGroups[scope][index][field] = value;
    }
}

function renderFilterGroups(scope = 'create') {
    const containerId = scope === 'create' ? 'filterGroupsContainer' : 'filterGroupsContainer_addMore';
    const el = document.getElementById(containerId);
    if (!el) return;
    const groups = filterGroups[scope];

    const cityOpts = '<option value="">All Cities</option>' +
        cityOptions.map(c => `<option value="${esc(c.city)}">${esc(c.city)} (${c.count})</option>`).join('');
    const qurbaniOpts = '<option value="">Not a Qurbani filter</option>' +
        qurbaniYears.map(y => `<option value="${y}">Qurbani ${y}</option>`).join('');

    let html = '';
    groups.forEach((g, i) => {
        const removable = groups.length > 1;
        html += `
        <div class="camp-filter-group">
            <div class="camp-filter-group-header">
                <span class="camp-filter-group-title">Group ${i + 1}</span>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span class="camp-filter-group-count" id="groupCount_${scope}_${i}" style="display:none;">0</span>
                    ${removable ? `<button type="button" class="camp-filter-group-remove" onclick="removeFilterGroup('${scope}', ${i})">Remove</button>` : ''}
                </div>
            </div>
            {{-- WHEN they last ordered. This is the churn dial: "Active" for
                 people still buying, "Gone quiet" for win-back, or an exact
                 date window when you want a specific slice of lapsed
                 customers (e.g. last ordered 6–12 months ago). --}}
            <div class="camp-form-row">
                <div class="camp-form-group">
                    <label>Ordering activity</label>
                    <select onchange="updateFilterGroup('${scope}', ${i}, 'activity', this.value)">
                        <option value="" ${!g.activity ? 'selected' : ''}>Any</option>
                        <option value="30day"  ${g.activity === '30day'  ? 'selected' : ''}>Active — ordered in last 30 days</option>
                        <option value="90day"  ${g.activity === '90day'  ? 'selected' : ''}>Active — ordered in last 90 days</option>
                        <option value="180day" ${g.activity === '180day' ? 'selected' : ''}>Active — ordered in last 6 months</option>
                        <option value="365day" ${g.activity === '365day' ? 'selected' : ''}>Active — ordered in last year</option>
                    </select>
                </div>
                <div class="camp-form-group">
                    <label title="Customers who have NOT ordered for at least this many days. Includes people who never ordered.">Gone quiet for at least</label>
                    <select onchange="updateFilterGroup('${scope}', ${i}, 'inactive_days', this.value ? parseInt(this.value) : null)">
                        <option value=""    ${!g.inactive_days ? 'selected' : ''}>Not filtering by this</option>
                        <option value="30"  ${String(g.inactive_days) === '30'  ? 'selected' : ''}>30 days</option>
                        <option value="60"  ${String(g.inactive_days) === '60'  ? 'selected' : ''}>60 days</option>
                        <option value="90"  ${String(g.inactive_days) === '90'  ? 'selected' : ''}>90 days (3 months)</option>
                        <option value="180" ${String(g.inactive_days) === '180' ? 'selected' : ''}>180 days (6 months)</option>
                        <option value="365" ${String(g.inactive_days) === '365' ? 'selected' : ''}>1 year</option>
                        <option value="730" ${String(g.inactive_days) === '730' ? 'selected' : ''}>2 years</option>
                    </select>
                </div>
            </div>

            <div class="camp-form-group">
                <label>…or pick the exact window their last order falls in</label>
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="date" value="${g.last_order_from || ''}" onchange="updateFilterGroup('${scope}', ${i}, 'last_order_from', this.value || null)" style="flex:1;">
                    <span style="font-size:11px;color:#94a3b8;">to</span>
                    <input type="date" value="${g.last_order_to || ''}" onchange="updateFilterGroup('${scope}', ${i}, 'last_order_to', this.value || null)" style="flex:1;">
                </div>
                <div style="font-size:10px;color:#94a3b8;margin-top:4px;">
                    Targets customers by <b>how stale</b> they are. Example: from 2025-01-01 to 2025-06-30 reaches people who last bought in the first half of 2025 — old enough to win back, recent enough to remember you.
                </div>
            </div>

            <div class="camp-form-row">
                <div class="camp-form-group">
                    <label>City</label>
                    <select onchange="updateFilterGroup('${scope}', ${i}, 'city', this.value)">
                        ${cityOpts.replace(`value="${esc(g.city || '')}"`, `value="${esc(g.city || '')}" selected`)}
                    </select>
                </div>
                <div class="camp-form-group">
                    <label>Customer type</label>
                    <select onchange="updateFilterGroup('${scope}', ${i}, 'customer_type', this.value || null)">
                        <option value=""        ${!g.customer_type ? 'selected' : ''}>Any</option>
                        <option value="regular" ${g.customer_type === 'regular' ? 'selected' : ''}>Regular customers</option>
                        <option value="shop"    ${g.customer_type === 'shop'    ? 'selected' : ''}>Shops / wholesale</option>
                    </select>
                </div>
            </div>

            {{-- Spend + order count are computed LIVE from the order tables.
                 They used to read the stored total_spent / total_orders columns,
                 which are stale for thousands of customers — a "spent 50k+"
                 filter was silently missing about a third of the people who
                 qualified. --}}
            <div class="camp-form-row">
                <div class="camp-form-group">
                    <label>Min lifetime spend (PKR)</label>
                    <input type="number" value="${g.min_spend != null ? g.min_spend : ''}" placeholder="0" onchange="updateFilterGroup('${scope}', ${i}, 'min_spend', this.value || null)">
                </div>
                <div class="camp-form-group">
                    <label>Max lifetime spend (PKR)</label>
                    <input type="number" value="${g.max_spend != null ? g.max_spend : ''}" placeholder="No limit" onchange="updateFilterGroup('${scope}', ${i}, 'max_spend', this.value || null)">
                </div>
            </div>
            <div class="camp-form-row">
                <div class="camp-form-group">
                    <label>Min orders</label>
                    <input type="number" value="${g.min_orders != null ? g.min_orders : ''}" placeholder="Any" onchange="updateFilterGroup('${scope}', ${i}, 'min_orders', this.value || null)">
                </div>
                <div class="camp-form-group">
                    <label>Max orders</label>
                    <input type="number" value="${g.max_orders != null ? g.max_orders : ''}" placeholder="Any" onchange="updateFilterGroup('${scope}', ${i}, 'max_orders', this.value || null)">
                </div>
            </div>
            <div style="font-size:10px;color:#94a3b8;margin:-6px 0 12px;">
                Counted live from delivered orders (current + historical), matching the Customers page. <b>Max orders = 1</b> finds one-time buyers who never came back.
            </div>

            <div class="camp-form-group">
                <label>When they first bought (acquisition cohort)</label>
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="date" value="${g.first_order_from || ''}" onchange="updateFilterGroup('${scope}', ${i}, 'first_order_from', this.value || null)" style="flex:1;">
                    <span style="font-size:11px;color:#94a3b8;">to</span>
                    <input type="date" value="${g.first_order_to || ''}" onchange="updateFilterGroup('${scope}', ${i}, 'first_order_to', this.value || null)" style="flex:1;">
                </div>
                <div style="font-size:10px;color:#94a3b8;margin-top:4px;">Reach everyone who joined during a particular season — e.g. last Qurbani's new customers.</div>
            </div>

            <div class="camp-form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:500;">
                    <input type="checkbox" ${g.never_ordered ? 'checked' : ''} onchange="updateFilterGroup('${scope}', ${i}, 'never_ordered', this.checked ? 1 : null)">
                    Only customers who have <b>never</b> ordered
                </label>
                <div style="font-size:10px;color:#94a3b8;margin-top:4px;">On file but never bought — good for a first-purchase offer.</div>
            </div>
            <div class="camp-form-group">
                <label>Qurbani Year</label>
                <select onchange="updateFilterGroup('${scope}', ${i}, 'qurbani_year', this.value ? parseInt(this.value) : null)">
                    ${qurbaniOpts.replace(`value="${g.qurbani_year || ''}"`, `value="${g.qurbani_year || ''}" selected`)}
                </select>
                <div style="font-size:10px;color:#94a3b8;margin-top:4px;">Only customers who placed a Qurbani order in this year (matches the Qurbani Dashboard definition).</div>
            </div>
            <div class="camp-form-group">
                <label>Source</label>
                <select onchange="updateFilterGroup('${scope}', ${i}, 'source', this.value || null)">
                    <option value=""             ${!g.source                  ? 'selected' : ''}>Any</option>
                    <option value="shopify"      ${g.source === 'shopify'     ? 'selected' : ''}>🛍 Shopify only</option>
                    <option value="non_shopify"  ${g.source === 'non_shopify' ? 'selected' : ''}>Non-Shopify only</option>
                </select>
                <div style="font-size:10px;color:#94a3b8;margin-top:4px;">Shopify = at least one SH-prefixed order in the last 300 days, or a Shopify customer-id on file.</div>
            </div>
            <div class="camp-form-group">
                <label>Mobile app</label>
                <select onchange="updateFilterGroup('${scope}', ${i}, 'mobile_app', this.value || null)">
                    <option value=""            ${!g.mobile_app                    ? 'selected' : ''}>Any</option>
                    <option value="not_on_app"  ${g.mobile_app === 'not_on_app'    ? 'selected' : ''}>Not on app (exclude app users)</option>
                    <option value="on_app"      ${g.mobile_app === 'on_app'        ? 'selected' : ''}>📱 On app only</option>
                </select>
                <div style="font-size:10px;color:#94a3b8;margin-top:4px;">On app = has placed an order via the mobile app. Use “Not on app” so people already on the app don’t get the message.</div>
            </div>
            <div class="camp-form-group" style="margin-bottom:0;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:500;">
                    <input type="checkbox" ${g.require_phone ? 'checked' : ''} onchange="updateFilterGroup('${scope}', ${i}, 'require_phone', this.checked ? 1 : null)">
                    Skip customers with no phone number
                </label>
                <div style="font-size:10px;color:#94a3b8;margin-top:4px;">They can never receive a WhatsApp message, so including them just creates failures. On by default for new campaigns.</div>
            </div>
        </div>`;
    });
    el.innerHTML = html;
}

// ==========================================================================
// EXCLUDE CAMPAIGNS
// ==========================================================================

function renderExcludeCampaigns(scope = 'create') {
    const containerId = scope === 'create' ? 'excludeCampaignsBox' : 'excludeCampaignsBox_addMore';
    const el = document.getElementById(containerId);
    if (!el) return;

    // Don't show the current campaign in its own add-more-customers exclude list.
    const list = campaigns.filter(c => !(scope === 'addMore' && c.id === addCustomersCampaignId));
    if (!list.length) {
        el.innerHTML = '<div style="font-size:12px;color:#94a3b8;padding:6px;">No earlier campaigns yet.</div>';
        return;
    }

    let html = '';
    list.forEach(c => {
        const sent = parseInt(c.sent_count || 0);
        const dt = c.created_at ? new Date(c.created_at).toLocaleDateString() : '';
        html += `
        <label class="camp-exclude-item">
            <input type="checkbox" value="${c.id}" data-scope="${scope}" data-exclude="1">
            <span style="flex:1;">${esc(c.name)} <span style="color:#94a3b8;font-size:11px;">— ${sent} sent · ${dt}</span></span>
        </label>`;
    });
    el.innerHTML = html;
}

function getExcludeCampaignIds(scope = 'create') {
    const containerId = scope === 'create' ? 'excludeCampaignsBox' : 'excludeCampaignsBox_addMore';
    const el = document.getElementById(containerId);
    if (!el) return [];
    return Array.from(el.querySelectorAll('input[type="checkbox"]:checked')).map(cb => parseInt(cb.value));
}

// ==========================================================================
// BUILD PAYLOAD / PREVIEW / CREATE
// ==========================================================================

function buildFilters(scope = 'create') {
    // Strip empty groups so an empty-by-default group doesn't act as "all customers".
    const groups = filterGroups[scope].filter(g => Object.values(g).some(v => v !== null && v !== '' && v !== undefined));

    const payload = {
        // If the user left every group empty we send a single empty group
        // which the backend interprets as "all customers" (same as legacy).
        groups: groups.length ? groups : [ {} ],
        exclude_campaign_ids: getExcludeCampaignIds(scope),
    };

    if (scope === 'create') {
        payload.sort_by = document.getElementById('filterSortBy').value;
        payload.sort_dir = document.getElementById('filterSortDir').value;
    }

    return payload;
}

function renderPreviewResult(resEl, data, scope = 'create') {
    const pieces = [`<b>${data.count}</b> unique customer${data.count === 1 ? '' : 's'} match`];
    if (data.excluded_count > 0) pieces.push(`${data.excluded_count} excluded`);
    let html = `<div class="camp-preview-count">${pieces.join(' · ')}</div>`;

    // Template-dedup breakdown. Only shown when the backend actually
    // ran a dedup check (i.e. template + window were both provided).
    // The "net will be sent" number is what really matters to the user
    // — it's what ends up as 'pending' after dedup. Excluded customers
    // are added to the campaign but land in a dedicated 'Excluded' tab
    // for audit — they are NOT sent to.
    if (data.already_sent_count > 0) {
        const tpl = data.wa_template_name ? ` <span style="color:#64748b;">(${esc(data.wa_template_name)})</span>` : '';
        html += `<div style="margin-top:8px;padding:8px 10px;background:#fef3c7;border:1px solid #fde68a;border-radius:6px;font-size:12px;color:#92400e;line-height:1.5;">
            <div><b>🚫 ${data.already_sent_count}</b> already received this template in the last <b>${data.dedup_window_days}</b> day${data.dedup_window_days === 1 ? '' : 's'}${tpl}</div>
            <div style="margin-top:2px;"><b>✅ ${data.net_to_send}</b> will be queued to send. The other ${data.already_sent_count} will appear under the <b>Excluded</b> tab (not sent to).</div>
        </div>`;
    } else if (data.dedup_window_days > 0 && data.count > 0 && data.wa_template_name) {
        // Reassurance state — the user picked a template + window but no
        // one matches the recent-send set, so everybody will receive it.
        html += `<div style="margin-top:6px;font-size:11px;color:#059669;">✅ No recent duplicates — all ${data.count} will be queued.</div>`;
    }

    // Customers with no phone number can never receive the message. Say so at
    // preview time rather than letting them turn into a pile of failures.
    if (data.no_phone_count > 0) {
        html += `<div style="margin-top:6px;font-size:11px;color:#92400e;">⚠ ${data.no_phone_count} of these have no phone number and cannot be messaged. Tick “Skip customers with no phone number” to leave them out.</div>`;
    }

    // How many send sessions this audience will take at the chosen batch size —
    // sets expectations before the campaign even exists.
    const sess = parseInt(document.getElementById('filterSessionLimit')?.value) || 0;
    const net = data.net_to_send != null ? data.net_to_send : data.count;
    if (scope === 'create' && sess > 0 && net > sess) {
        const sessions = Math.ceil(net / sess);
        html += `<div style="margin-top:6px;font-size:11px;color:#64748b;">At ${sess} per session this will take about <b>${sessions} sessions</b>${data.quota && !data.quota.unlimited ? ` (daily WhatsApp limit is ${data.quota.cap})` : ''}.</div>`;
    }

    // Show per-group counts as a tiny breakdown
    const groups = filterGroups[scope];
    if (data.group_counts && groups && groups.length > 0) {
        html += '<div style="font-size:11px;color:#64748b;margin-top:6px;text-align:center;">';
        html += (data.group_counts || []).map((n, i) => {
            const groupExists = groups[i];
            if (!groupExists) return null;
            return `Group ${i + 1}: ${n}`;
        }).filter(Boolean).join(' · ');
        html += '</div>';
        // Also paint the chip on each group card
        (data.group_counts || []).forEach((n, i) => {
            const chip = document.getElementById(`groupCount_${scope}_${i}`);
            if (chip) { chip.textContent = n; chip.style.display = 'inline-block'; }
        });
    }
    resEl.style.display = 'block';
    resEl.innerHTML = html;
}

async function previewCount() {
    const btn = document.getElementById('previewBtn');
    const res = document.getElementById('previewResult');
    btn.disabled = true;
    btn.innerHTML = '<span class="camp-spinner camp-spinner-dark"></span>';

    // Pass the selected template + dedup window so the backend can tell
    // us how many of the matched customers already received this
    // template recently. Both are optional: without a template, or with
    // window=0, the backend simply returns already_sent_count=0.
    const dedupDays = parseInt(document.getElementById('filterDedupDays')?.value);
    const data = await apiFetch('/campaigns/preview', {
        method: 'POST',
        body: JSON.stringify({
            filters: buildFilters('create'),
            wa_template_name: selectedTemplateName || null,
            dedup_window_days: Number.isFinite(dedupDays) ? dedupDays : 0,
        })
    });

    btn.disabled = false;
    btn.innerHTML = 'Preview Count';

    if (data.success) renderPreviewResult(res, data, 'create');
}

async function createCampaign() {
    const name = document.getElementById('campName').value.trim();
    if (!name) { alert('Please enter a campaign name'); return; }
    if (!selectedTemplateName) { alert('Please select a WhatsApp template'); return; }

    const btn = document.getElementById('createBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="camp-spinner"></span> Creating...';

    const dedupDays = parseInt(document.getElementById('filterDedupDays')?.value);
    const trackingType = document.getElementById('filterTrackingType').value;
    const sessionLimit = parseInt(document.getElementById('filterSessionLimit')?.value);

    if (trackingType === 'products' && trackedProductIds.length === 0) {
        // Re-enable before bailing out, or the operator is left with a dead
        // "Creating..." button and no way to retry without reopening the modal.
        btn.disabled = false;
        btn.innerHTML = 'Create Campaign';
        alert('You chose to track specific products but haven\'t picked any. Select at least one product, or switch back to "Any order they place".');
        return;
    }
    if (trackingType === 'category' && trackedCategories.length === 0) {
        btn.disabled = false;
        btn.innerHTML = 'Create Campaign';
        alert('You chose to track a category but haven\'t picked one. Tick a category (e.g. Khaas · Frozen), or switch back to "Any order they place".');
        return;
    }

    const data = await apiFetch('/campaigns/create', {
        method: 'POST',
        body: JSON.stringify({
            name,
            wa_template_name: selectedTemplateName,
            notes: document.getElementById('campNotes').value.trim(),
            filters: buildFilters('create'),
            tracking_type: trackingType,
            tracked_product_ids: trackingType === 'products' ? trackedProductIds : [],
            tracked_categories: trackingType === 'category' ? trackedCategories : [],
            tracking_window_days: parseInt(document.getElementById('filterTrackingDays').value) || 30,
            dedup_window_days: Number.isFinite(dedupDays) ? dedupDays : 0,
            session_limit: Number.isFinite(sessionLimit) && sessionLimit > 0 ? sessionLimit : 100,
        })
    });

    btn.disabled = false;
    btn.innerHTML = 'Create Campaign';

    if (data.success) {
        // Friendly one-liner summarising the dedup outcome. Only shown
        // when dedup actually removed somebody; otherwise we stay silent.
        // excluded_by_dedup customers are NOT in the campaign at all —
        // the campaign's Total already reflects the net count.
        if ((data.excluded_by_dedup || 0) > 0) {
            alert(
                `Campaign created.\n\n` +
                `${data.pending_count} customer${data.pending_count === 1 ? '' : 's'} queued to send.\n` +
                `${data.excluded_by_dedup} moved to the Excluded tab — already received "${selectedTemplateName}" in the last ${data.dedup_window_days} day${data.dedup_window_days === 1 ? '' : 's'} (will not be sent to).`
            );
        }
        closeCreateModal();
        loadCampaigns();
        loadCampaignDetail(data.campaign_id, 'pending');
    } else {
        alert(data.message || 'Failed to create campaign');
    }
}

// ==========================================================================
// ADD MORE CUSTOMERS TO EXISTING CAMPAIGN
// ==========================================================================

function openAddCustomersModal(campaignId, campaignName) {
    addCustomersCampaignId = campaignId;
    filterGroups.addMore = [ { require_phone: 1 } ];
    document.getElementById('addCustomersTitle').textContent = `Add More Customers — ${campaignName}`;
    document.getElementById('previewAddResult').style.display = 'none';
    // Default the dedup window to 30 every time we open, to match the
    // Create Campaign modal and because this is a per-add choice rather
    // than a persistent preference.
    var dedupEl = document.getElementById('filterDedupDays_addMore');
    if (dedupEl) dedupEl.value = '30';
    document.getElementById('addCustomersModal').classList.add('open');
    renderFilterGroups('addMore');
    renderExcludeCampaigns('addMore');
    Promise.all([loadCities(), loadQurbaniYears()]).then(() => {
        renderFilterGroups('addMore');
    });
}

function closeAddCustomersModal() {
    document.getElementById('addCustomersModal').classList.remove('open');
    addCustomersCampaignId = null;
}

async function previewAddCustomers() {
    const btn = document.getElementById('previewAddBtn');
    const res = document.getElementById('previewAddResult');
    btn.disabled = true;
    btn.innerHTML = '<span class="camp-spinner camp-spinner-dark"></span>';

    // When adding to an existing campaign the template is fixed by the
    // campaign itself — we look it up from the active-campaigns list so
    // the backend can apply the same dedup check. If the campaign isn't
    // resolvable for any reason we just skip the template param and the
    // preview falls back to plain filter-count.
    const campaign = campaigns.find(c => c.id === addCustomersCampaignId);
    const dedupDays = parseInt(document.getElementById('filterDedupDays_addMore')?.value);
    const data = await apiFetch('/campaigns/preview', {
        method: 'POST',
        body: JSON.stringify({
            filters: buildFilters('addMore'),
            wa_template_name: campaign?.wa_template_name || null,
            dedup_window_days: Number.isFinite(dedupDays) ? dedupDays : 0,
        })
    });

    btn.disabled = false;
    btn.innerHTML = 'Preview Count';

    if (data.success) renderPreviewResult(res, data, 'addMore');
}

async function confirmAddCustomers() {
    if (!addCustomersCampaignId) return;
    const btn = document.getElementById('addCustomersBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="camp-spinner"></span> Adding...';

    const dedupDays = parseInt(document.getElementById('filterDedupDays_addMore')?.value);
    const data = await apiFetch(`/campaigns/${addCustomersCampaignId}/add-customers`, {
        method: 'POST',
        body: JSON.stringify({
            filters: buildFilters('addMore'),
            dedup_window_days: Number.isFinite(dedupDays) ? dedupDays : 0,
        })
    });

    btn.disabled = false;
    btn.innerHTML = 'Add Customers';

    if (data.success) {
        const skipped = data.already_in_campaign || 0;
        const excluded = data.excluded_count || 0;
        // excluded_by_dedup = "new candidates we dropped from the insert
        // because they already got this campaign's template recently".
        // Distinct from already_in_campaign (= already part of this
        // campaign at any status).
        const dedupExcluded = data.excluded_by_dedup || 0;
        alert(
            `Added ${data.added} new customer${data.added === 1 ? '' : 's'}.` +
            (skipped > 0 ? `\n${skipped} already in this campaign (not inserted).` : '') +
            (excluded > 0 ? `\n${excluded} excluded via earlier campaigns.` : '') +
            (dedupExcluded > 0 ? `\n${dedupExcluded} moved to the Excluded tab — already received this template in the last ${data.dedup_window_days} day${data.dedup_window_days === 1 ? '' : 's'} (will not be sent to).` : '')
        );
        closeAddCustomersModal();
        loadCampaignDetail(activeCampaignId, customerStatusFilter);
        loadCampaigns();
    } else {
        alert(data.message || 'Failed to add customers');
    }
}

// ==========================================================================
// TEMPLATE PICKER
// ==========================================================================

function openTemplatePicker() {
    document.getElementById('templatePickerModal').classList.add('open');
    renderTemplatePicker();
}

function closeTemplatePicker() {
    document.getElementById('templatePickerModal').classList.remove('open');
}

function renderTemplatePicker() {
    const el = document.getElementById('templatePickerList');
    if (!waTemplates.length) {
        el.innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;font-size:13px;">No approved templates found</div>';
        return;
    }

    let html = '';
    waTemplates.forEach(t => {
        const isActive = selectedTemplateName === t.name;
        html += `
        <div class="camp-template-option${isActive ? ' active' : ''}" onclick="selectTemplate('${esc(t.name)}')">
            <div class="camp-template-option-name">${esc(t.display_name)}</div>
            <div class="camp-template-option-body">${esc(t.body_text || '')}</div>
            <div class="camp-template-option-meta">${esc(t.category)} &middot; ${esc(t.language)} &middot; ${t.variable_count} variable(s)</div>
        </div>`;
    });
    el.innerHTML = html;
}

function selectTemplate(name) {
    selectedTemplateName = name;
    updateTemplateSelectBtn();
    closeTemplatePicker();
}

function updateTemplateSelectBtn() {
    const btn = document.getElementById('templateSelectBtn');
    const label = document.getElementById('templateSelectLabel');
    if (selectedTemplateName) {
        const t = waTemplates.find(t => t.name === selectedTemplateName);
        label.textContent = t ? t.display_name : selectedTemplateName;
        btn.classList.add('selected');
    } else {
        label.textContent = 'Select a template...';
        btn.classList.remove('selected');
    }
}

// ==========================================================================
// UTILS
// ==========================================================================

function esc(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Parse the match_tags column (may come back as a JSON string or already an
// array) into a clean array of labels. Safe against nulls / legacy rows.
function parseMatchTags(raw) {
    if (!raw) return [];
    if (Array.isArray(raw)) return raw.filter(Boolean);
    try {
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed.filter(Boolean) : [];
    } catch (e) {
        return [];
    }
}

// Colour tags by their content so "Qurbani 2025", "90d active", "Lahore"
// are visually distinct at a glance.
function tagClass(label) {
    const s = String(label).toLowerCase();
    if (s.includes('qurbani')) return 'qurbani';
    if (s.includes('active') || s.includes('day')) return 'activity';
    if (/^[a-z\s]+$/i.test(s) && !s.includes('group')) return 'city';
    return '';
}

// Close modals on overlay click
['createModal', 'templatePickerModal', 'statsModal', 'addCustomersModal'].forEach(id => {
    document.getElementById(id)?.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('open');
        }
    });
});

// ==========================================================================
// INIT
// ==========================================================================

// First load fires BOTH requests at once — the old sequential chain (list,
// then overview) doubled the time before the right panel had anything, which
// is what made the landing feel stuck on a slow dev server. Each side paints
// (or shows its own error) independently.
loadCampaigns(false);
loadOverview();

// Stop the pollers when the tab is hidden — no point polling a page nobody is
// looking at, and it keeps a backgrounded tab from holding the send lock busy
// with status requests.
document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        if (overviewPollTimer) { clearInterval(overviewPollTimer); overviewPollTimer = null; }
        if (sendPollTimer) { clearInterval(sendPollTimer); sendPollTimer = null; }
    } else {
        // Coming back: refresh once, then let the managers decide whether to
        // resume polling based on what's actually running.
        if (activeCampaignId) {
            loadCampaignDetail(activeCampaignId, customerStatusFilter);
        } else {
            loadCampaigns();   // chains the overview refresh
        }
    }
});
</script>
@endpush
