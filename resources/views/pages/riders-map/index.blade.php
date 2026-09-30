@extends('layouts.app')

@section('title', 'Riders Map')

@push('demo1_css')
<!-- Leaflet CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<style>
    /* Full page container */
    .riders-map-page {
        display: flex;
        flex-direction: column;
        height: calc(100vh - 70px);
        background: #f8fafc;
    }
    
    /* Header bar - orange gradient */
    .riders-map-header {
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        color: white;
        padding: 12px 20px;
        flex-shrink: 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .riders-map-header h1 {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
    }
    
    .header-controls {
        display: flex;
        gap: 12px;
        align-items: center;
    }
    
    /* View toggle buttons */
    .view-toggle {
        display: flex;
        background: rgba(255,255,255,0.2);
        border-radius: 6px;
        overflow: hidden;
    }
    
    .view-toggle button {
        background: transparent;
        border: none;
        color: white;
        padding: 6px 14px;
        cursor: pointer;
        font-size: 13px;
        font-weight: 500;
        transition: background 0.2s;
    }
    
    .view-toggle button.active {
        background: rgba(255,255,255,0.3);
    }
    
    .view-toggle button:hover:not(.active) {
        background: rgba(255,255,255,0.15);
    }

    /* Live layer switch (Riders / Open orders) — sits under the header, inside
       the 🔴 Live tab. Secondary styling on purpose: it is a layer switch, not
       a navigation tab, and must not compete with the toggle above it. */
    .live-layers {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        background: #fff;
        border-bottom: 1px solid #e5e7eb;
    }
    .live-layer {
        background: #f3f4f6;
        border: 1px solid #e5e7eb;
        color: #4b5563;
        padding: 5px 13px;
        border-radius: 999px;
        cursor: pointer;
        font-size: 12.5px;
        font-weight: 500;
        transition: background .15s, color .15s, border-color .15s;
    }
    .live-layer:hover:not(.active) { background: #e5e7eb; }
    .live-layer.active {
        background: #fef3c7;
        border-color: #f59e0b;
        color: #92400e;
        font-weight: 600;
    }
    .live-layer-hint { font-size: 12px; color: #9ca3af; margin-left: 4px; }

    /* Date picker */
    .date-picker {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .date-picker label {
        font-size: 13px;
        font-weight: 500;
    }
    
    .date-picker input[type="date"] {
        padding: 5px 10px;
        border-radius: 6px;
        border: none;
        font-size: 13px;
        background: rgba(255,255,255,0.9);
        color: #374151;
    }
    
    .btn-header {
        background: rgba(255,255,255,0.2);
        border: none;
        color: white;
        padding: 6px 12px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 13px;
        font-weight: 500;
        text-decoration: none;
    }
    
    .btn-header:hover {
        background: rgba(255,255,255,0.3);
    }
    
    /* History banner */
    .history-banner {
        display: none;
        padding: 10px 20px;
        background: #fef3c7;
        border-bottom: 1px solid #fcd34d;
        color: #92400e;
        font-size: 13px;
    }
    
    /* Filters section */
    .filters-section {
        display: none;
        padding: 12px 20px;
        background: #f8fafc;
        border-bottom: 1px solid #e5e7eb;
    }
    
    .filters-row {
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
        align-items: center;
    }
    
    .filter-group {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .filter-group label {
        font-size: 13px;
        font-weight: 500;
        color: #374151;
    }
    
    .filter-group select {
        padding: 6px 10px;
        border-radius: 6px;
        border: 1px solid #d1d5db;
        font-size: 13px;
        min-width: 150px;
    }
    
    .filter-summary {
        margin-left: auto;
        font-size: 13px;
        color: #6b7280;
    }
    
    /* Main content area */
    .main-content {
        flex: 1;
        overflow-y: auto;
        padding: 16px;
    }
    
    /* Riders grid */
    .riders-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 12px;
    }
    
    /* Rider card */
    .rider-card {
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 16px;
        cursor: pointer;
        transition: all 0.2s;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    
    .rider-card:hover {
        border-color: #3b82f6;
        box-shadow: 0 4px 12px rgba(59,130,246,0.15);
    }
    
    /* Map container */
    .map-container {
        height: 400px;
        border-radius: 8px;
        overflow: hidden;
        margin-bottom: 12px;
    }
    
    /* Orders list */
    .orders-list {
        max-height: 300px;
        overflow-y: auto;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: white;
    }
    
    /* Back button */
    .btn-back {
        background: #6b7280;
        border: none;
        color: white;
        padding: 6px 12px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 13px;
    }
    
    .btn-back:hover {
        background: #4b5563;
    }
    
    /* Loading spinner */
    .loading-spinner {
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    
    /* Pulse animation for rider location */
    @keyframes pulse {
        0% { box-shadow: 0 0 0 0 rgba(59,130,246,0.7); }
        70% { box-shadow: 0 0 0 10px rgba(59,130,246,0); }
        100% { box-shadow: 0 0 0 0 rgba(59,130,246,0); }
    }
    
    /* Single rider view header */
    .view-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
        padding: 12px;
        background: #f8fafc;
        border-radius: 8px;
    }
    
    .view-header h3 {
        margin: 0;
        font-size: 16px;
        font-weight: 600;
        color: #1f2937;
    }
    
    .view-summary {
        margin-left: auto;
        display: flex;
        gap: 16px;
        font-size: 13px;
    }
    
    /* Empty state */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #6b7280;
        grid-column: 1 / -1;
    }
    
    .empty-state .icon {
        font-size: 48px;
        margin-bottom: 12px;
    }

    /* ===== DISPATCH TRACKER STYLES ===== */
    .dt-controls {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 0 16px;
    }
    .dt-controls input[type="date"] {
        padding: 7px 12px;
        border-radius: 6px;
        border: 1px solid #d1d5db;
        font-size: 13px;
    }
    .dt-controls .dt-btn {
        padding: 7px 14px;
        border-radius: 6px;
        border: none;
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        color: white;
        background: #f59e0b;
    }
    .dt-controls .dt-btn:hover { background: #d97706; }

    .dt-summary-bar {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin-bottom: 16px;
    }
    .dt-stat {
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 10px 16px;
        min-width: 120px;
        text-align: center;
    }
    .dt-stat .dt-val {
        font-size: 20px;
        font-weight: 700;
        color: #1f2937;
    }
    .dt-stat .dt-label {
        font-size: 11px;
        color: #6b7280;
        margin-top: 2px;
    }
    .dt-stat.green .dt-val { color: #059669; }
    .dt-stat.red .dt-val { color: #dc2626; }
    .dt-stat.blue .dt-val { color: #2563eb; }
    .dt-stat.amber .dt-val { color: #d97706; }

    .dt-rider-card {
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        margin-bottom: 12px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    .dt-rider-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 14px 16px;
        cursor: pointer;
        transition: background 0.15s;
    }
    .dt-rider-header:hover { background: #f9fafb; }
    .dt-rider-name {
        font-size: 15px;
        font-weight: 600;
        color: #1f2937;
    }
    .dt-rider-meta {
        font-size: 12px;
        color: #6b7280;
        margin-top: 3px;
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }
    .dt-rider-badges {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        align-items: center;
    }
    .dt-badge {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        padding: 3px 8px;
        border-radius: 5px;
        font-size: 11px;
        font-weight: 600;
    }
    .dt-badge.green { background: #d1fae5; color: #065f46; }
    .dt-badge.red { background: #fee2e2; color: #991b1b; }
    .dt-badge.blue { background: #dbeafe; color: #1e40af; }
    .dt-badge.amber { background: #fef3c7; color: #92400e; }
    .dt-badge.purple { background: #ede9fe; color: #5b21b6; }
    .dt-badge.gray { background: #f3f4f6; color: #374151; }

    .dt-rider-body {
        display: none;
        border-top: 1px solid #e5e7eb;
    }
    .dt-rider-body.open { display: block; }

    .dt-batch {
        border-bottom: 1px solid #f3f4f6;
    }
    .dt-batch:last-child { border-bottom: none; }
    .dt-batch-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 16px;
        background: #f0fdf4;
        border-bottom: 1px solid #e5e7eb;
        cursor: pointer;
    }
    .dt-batch-header:hover { background: #ecfdf5; }
    .dt-batch-header.undispatched {
        background: #fef2f2;
    }
    .dt-batch-header.undispatched:hover { background: #fee2e2; }
    .dt-batch-title {
        font-size: 13px;
        font-weight: 600;
        color: #1f2937;
    }
    .dt-batch-stats {
        display: flex;
        gap: 8px;
        font-size: 11px;
        align-items: center;
    }
    .dt-batch-orders {
        display: none;
    }
    .dt-batch-orders.open { display: block; }

    .dt-order-row {
        border-bottom: none;
    }
    .dt-order-row:last-child .dt-order-main { border-bottom: none; }
    .dt-order-row { cursor: pointer; }
    .dt-order-main {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 16px 8px 28px;
        border-bottom: 1px solid #f9fafb;
        font-size: 12px;
        gap: 8px;
    }
    .dt-order-details {
        display: none;
        padding: 4px 16px 8px 56px;
        font-size: 11px;
        color: #6b7280;
        background: #f9fafb;
        border-bottom: 1px solid #f3f4f6;
    }
    .dt-order-details.open { display: block; }
    .dt-order-details span { margin-right: 16px; }
    .dt-order-details .dt-detail-label { color: #9ca3af; font-weight: 500; }
    .dt-order-details .dt-detail-value { color: #374151; }
    .dt-order-left {
        display: flex;
        align-items: center;
        gap: 8px;
        min-width: 0;
    }
    .dt-order-seq {
        width: 22px;
        height: 22px;
        border-radius: 50%;
        background: #e5e7eb;
        color: #374151;
        font-weight: 700;
        font-size: 11px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .dt-order-seq.match { background: #d1fae5; color: #065f46; }
    .dt-order-seq.mismatch { background: #fee2e2; color: #991b1b; }
    .dt-order-num {
        font-weight: 600;
        color: #3b82f6;
        white-space: nowrap;
    }
    .dt-order-customer {
        color: #374151;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .dt-order-right {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-shrink: 0;
    }
    .dt-time {
        font-size: 11px;
        color: #6b7280;
        white-space: nowrap;
    }
    .dt-eta-ok { color: #059669; font-weight: 600; }
    .dt-eta-late { color: #dc2626; font-weight: 600; }
    .dt-amount {
        font-weight: 600;
        white-space: nowrap;
    }
    .dt-amount.cash { color: #059669; }
    .dt-amount.online { color: #2563eb; }
</style>
@endpush

@section('content')
<div class="riders-map-page">
    <!-- Header -->
    <div class="riders-map-header">
        <h1 id="pageTitle">📍 All Riders - Live Map</h1>
        <div class="header-controls">
            <!-- View Toggle -->
            <div class="view-toggle">
                {{-- 🔴 Live holds what were two separate tabs (Rider Map + Open
                     Orders); they are now two layers switched by the segmented
                     control below the header, since a manager watching riders
                     almost always wants the open orders in the same glance. --}}
                @unless($bikesOnly ?? false)
                <button id="viewRidersBtn" onclick="switchView('riders')" class="active">🔴 Live</button>
                @endunless
                {{-- Day Review (Jul-2026) replaces the old History and Dispatch Tracker
                     tabs (their markup + JS below are intentionally left in place,
                     unreachable, until a later cleanup pass). ⚠ Issues was NOT absorbed —
                     it carries the RIDER/DAY-level checks (attendance lateness, GPS-off,
                     odd route, missing meters, checkout-at-office) that Day Review's
                     order-level review has no equivalent for, so its tab stays. --}}
                @unless($bikesOnly ?? false)
                <button id="viewDayReviewBtn" onclick="switchView('dayreview')">📅 Day Review</button>
                @endunless
                {{-- Gated on the same permission RiderReportsController enforces, so
                     nobody sees a tab that would only ever answer 403. --}}
                @if($canViewRiderReports ?? false)
                <button id="viewReportsBtn" onclick="switchView('reports')">⚠️ Issues</button>
                @endif
                @if($canViewFleet ?? false)
                <button id="viewFleetBtn" onclick="switchView('fleet')">🏍️ Bikes</button>
                @endif
            </div>
            
            <!-- Date Picker (for riders view) -->
            <div id="datePicker" class="date-picker">
                <label>Date:</label>
                <input type="date" id="dateInput" onchange="onDateChange(this.value)">
                <button class="btn-header" onclick="setToday()">Today</button>
            </div>
            
            <!-- Link to Invoices -->
            <a href="{{ route('orders.index') }}" class="btn-header">📋 Invoices</a>
        </div>
    </div>
    
    <!-- History Banner -->
    <div id="historyBanner" class="history-banner">
        📅 Viewing history - Shows deliveries for the selected date (no live location)
    </div>
    
    <!-- Filters Section (for Open Orders view) -->
    <div id="filtersSection" class="filters-section">
        <div class="filters-row">
            <div class="filter-group">
                <label>Status:</label>
                <select id="statusFilter" onchange="applyFilters()">
                    <option value="all">All Statuses</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Rider:</label>
                <select id="riderFilter" onchange="applyFilters()">
                    <option value="all">All Riders</option>
                </select>
            </div>
            <div id="filterSummary" class="filter-summary">Loading...</div>
        </div>
    </div>
    
    <!-- Live layer switch (Riders ⇄ Open orders) — only shown inside 🔴 Live -->
    <div id="liveLayerBar" class="live-layers" style="display:none;">
        <button id="liveLayerRiders" class="live-layer active" onclick="switchView('riders')">🛵 Riders</button>
        <button id="liveLayerOrders" class="live-layer" onclick="switchView('orders')">📦 Open orders</button>
        <span id="liveLayerHint" class="live-layer-hint"></span>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- ========== RIDERS LIST VIEW ========== -->
        <div id="ridersListView">
            {{-- 🚚 Van panel — above the riders grid, because when a van IS out it
                 is the thing the store is waiting on. Renders nothing on a day
                 without the van, so this view is unchanged the rest of the time.
                 Only the CONTAINER is here; its modal + script are included at
                 page level (see the note in the partial). --}}
            <div id="vanPanel" class="vp-wrap" style="display:none;"></div>

            <div id="ridersContainer" class="riders-grid">
                <div class="empty-state">
                    <svg class="loading-spinner" style="width: 32px; height: 32px; margin: 0 auto 12px;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Loading riders...
                </div>
            </div>
        </div>
        
        <!-- ========== SINGLE RIDER VIEW ========== -->
        <div id="singleRiderView" style="display: none;">
            <div class="view-header">
                <button class="btn-back" onclick="backToRidersList()">← Back</button>
                <h3 id="singleRiderTitle"></h3>
                <div id="singleRiderSummary" class="view-summary"></div>
            </div>
            <div id="singleRiderMap" class="map-container"></div>
            <div id="singleRiderOrders" class="orders-list"></div>
        </div>
        
        <!-- ========== OPEN ORDERS VIEW ========== -->
        <div id="openOrdersView" style="display: none;">
            <div id="openOrdersMap" class="map-container"></div>
            <div id="openOrdersList" class="orders-list"></div>
        </div>
        
        <!-- ========== HISTORY VIEW ========== -->
        <div id="historyView" style="display: none;">
            <!-- Level 1: Rider List -->
            <div id="historyRidersList">
                <div style="padding: 12px; background: #f8fafc; border-radius: 8px; margin-bottom: 16px;">
                    📊 Select a rider to view their delivery history (Last 3 months)
                </div>
                <div id="historyRidersContainer" class="riders-grid">
                    <div class="empty-state">Loading riders...</div>
                </div>
            </div>
            
            <!-- Level 2: Date Groups -->
            <div id="historyDateGroups" style="display: none;">
                <div class="view-header">
                    <button class="btn-back" onclick="backToHistoryRiders()">← Back</button>
                    <h3 id="historyRiderName"></h3>
                    <div id="historyRiderSummary" class="view-summary"></div>
                </div>
                <div id="historyDateContainer" style="max-height: calc(100vh - 280px); overflow-y: auto;"></div>
            </div>
            
            <!-- Level 3: Date Map -->
            <div id="historyDateMap" style="display: none;">
                <div class="view-header" style="flex-wrap: wrap;">
                    <button class="btn-back" onclick="backToHistoryDates()">← Back</button>
                    <h3 id="historyMapTitle"></h3>
                    <div style="margin-left: auto; display: flex; gap: 8px; align-items: center;">
                        <span id="historyMapSummary" style="font-size: 13px; color: #6b7280;"></span>
                        <button onclick="geocodeHistoryAll()" style="background: #3b82f6; border: none; color: white; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px;">🔄 Refresh All</button>
                    </div>
                </div>
                <div id="historyMapContainer" class="map-container"></div>
                <div id="historyOrdersList" class="orders-list"></div>
            </div>
        </div>
        
        <!-- ========== DISPATCH TRACKER VIEW ========== -->
        <div id="dispatchView" style="display: none;">
            <div class="dt-controls">
                <label style="font-weight:500; font-size:13px; color:#374151;">Date:</label>
                <input type="date" id="dtDateInput" onchange="loadDispatchReport(this.value)">
                <button class="dt-btn" onclick="dtSetToday()">Today</button>
                <button class="dt-btn" style="background:#6b7280;" onclick="dtPrevDay()">← Prev Day</button>
                <button class="dt-btn" style="background:#6b7280;" onclick="dtNextDay()">Next Day →</button>
                <span id="dtDateLabel" style="font-size:14px; font-weight:600; color:#1f2937; margin-left:8px;"></span>
            </div>
            <div id="dtSummaryBar" class="dt-summary-bar"></div>
            <div id="dtOngoingContainer"></div>
            <div id="dtRidersContainer"></div>
        </div>

        <!-- ========== RIDER REPORTS (ISSUES) VIEW ========== -->
        <div id="reportsView" style="display: none;">
            <div class="dt-controls">
                <label style="font-weight:500; font-size:13px; color:#374151;">Date:</label>
                <input type="date" id="rrDateInput" onchange="rrLoad(this.value)">
                <button class="dt-btn" onclick="rrSetToday()">Today</button>
                <button class="dt-btn" style="background:#6b7280;" onclick="rrPrevDay()">← Prev</button>
                <button class="dt-btn" style="background:#6b7280;" onclick="rrNextDay()">Next →</button>
                <span id="rrRealtime" style="font-size:12px; color:#6b7280; margin-left:8px;"></span>
                <button class="dt-btn" style="margin-left:auto; background:#475569;" onclick="rrToggleAll()"><span id="rrToggleLabel">Show all riders</span></button>
            </div>
            <div id="rrSummaryBar" class="dt-summary-bar"></div>
            <div id="rrContainer"></div>
        </div>

        <!-- ========== DAY REVIEW (replaces History + Dispatch + Issues) ========== -->
        @include('pages.riders-map.partials.day-review')

        <!-- ========== FLEET & FUEL (monthly bike cost) ========== -->
        @if($canViewFleet ?? false)
        @include('pages.riders-map.partials.fleet')
        @endif
    </div>
</div>

{{-- 🚚 Van panel logic + the meet-up points modal. At PAGE level on purpose: the
     modal must never sit inside a hidden view (a fixed child of a display:none
     parent does not render), because "📍 Meet-up points" is also reachable from
     the Bikes tab while the live view is hidden. --}}
@include('pages.riders-map.partials.van-panel')

<!-- Verified-vs-pressed map modal -->
<div id="rrMapModal" style="display:none; position:fixed; inset:0; z-index:3000; background:rgba(0,0,0,.55); align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:10px; width:min(94vw,660px); overflow:hidden; box-shadow:0 12px 44px rgba(0,0,0,.35);">
        <div style="display:flex; align-items:center; gap:10px; padding:12px 16px; background:#22303F; color:#fff;">
            <b id="rrMapTitle" style="font-size:15px;">Verified vs pressed</b>
            <span id="rrMapDist" style="margin-left:auto; font-size:13px; color:#C9D4DF; font-weight:600;"></span>
            <button onclick="rrCloseMap()" style="background:none; border:none; color:#fff; font-size:22px; cursor:pointer; line-height:1;">×</button>
        </div>
        <div id="rrMap" style="height:380px; width:100%;"></div>
        <div style="padding:10px 16px; display:flex; gap:14px; font-size:12.5px; flex-wrap:wrap; align-items:center;">
            <span><span style="display:inline-block; width:11px; height:11px; border-radius:50%; background:#2E7D4F; vertical-align:middle;"></span> Customer's saved pin</span>
            <span><span style="display:inline-block; width:11px; height:11px; border-radius:50%; background:#B3362B; vertical-align:middle;"></span> Where "Delivered" was pressed</span>
            <a id="rrMapGmaps" href="#" target="_blank" style="margin-left:auto; color:#2E64A6; text-decoration:none;">Open in Google Maps ↗</a>
        </div>
    </div>
</div>

<!-- Per-rider day Timeline modal -->
<div id="rrTimelineModal" style="display:none; position:fixed; inset:0; z-index:3000; background:rgba(0,0,0,.55); align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:10px; width:min(94vw,720px); max-height:90vh; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 12px 44px rgba(0,0,0,.35);">
        <div style="display:flex; align-items:center; gap:10px; padding:12px 16px; background:#22303F; color:#fff;">
            <b id="rrTlTitle" style="font-size:15px;">Timeline</b>
            <span id="rrTlSub" style="margin-left:auto; font-size:12.5px; color:#C9D4DF;"></span>
            <button onclick="rrCloseTimeline()" style="background:none; border:none; color:#fff; font-size:22px; cursor:pointer; line-height:1;">×</button>
        </div>
        <div id="rrTlBody" style="overflow:auto; padding:16px 18px 20px;"></div>
    </div>
</div>

<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<script>
// =============================================
// STATE VARIABLES
// =============================================
let currentView = 'riders'; // 'riders', 'orders', 'history', 'dispatch'
let currentDate = null; // null = today/live
let autoRefreshInterval = null;

// Maps
let singleRiderMap = null;
let openOrdersMap = null;
let historyMap = null;
let allOrdersMarkers = [];

// Current data
let currentRiderId = null;
let currentRiderName = null;
let currentRiderOrders = [];
let allOrdersData = null;

// History state
let historyRiderId = null;
let historyRiderName = null;

// Dispatch Tracker state
let dtCurrentDate = null;
let dtReportData = null;

// =============================================
// INITIALIZATION
// =============================================
document.addEventListener('DOMContentLoaded', function() {
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('dateInput').value = today;
    document.getElementById('dateInput').max = today;

    // Deep link support: /riders-map#day-review opens straight into Day Review.
    // The orders page's rider popup links here, and the old link said
    // "Dispatch Tracker" — which Day Review now contains.
    /* ⭐ DEEP LINK TO ONE MACHINE (Qasim/Shabib, 5-Sep): the service-due / ticket / workshop
       toasts now link to `#bikes?vehicle=ID[&ticket=ID]`. The part before `?` still picks
       the tab exactly as before; the part after is parked for the Bikes partial to act on
       once its list has loaded (see flvLoad → window.flDeepLink). Nothing else on this page
       reads it, and a hash without `?` behaves exactly as it always did. */
    var rawHash = (window.location.hash || '').replace('#', '');
    var qAt = rawHash.indexOf('?');
    if (qAt >= 0) {
        try {
            var dl = new URLSearchParams(rawHash.slice(qAt + 1));
            window.flDeepLink = {
                vehicle: parseInt(dl.get('vehicle') || '0', 10) || null,
                ticket:  parseInt(dl.get('ticket')  || '0', 10) || null
            };
        } catch (e) { window.flDeepLink = null; }
        rawHash = rawHash.slice(0, qAt);
    }
    var hash = rawHash.toLowerCase();
    if (hash === 'day-review' || hash === 'dayreview' || hash === 'dispatch') {
        switchView('dayreview');
        return;
    }
    if (hash === 'bikes' || hash === 'fleet') {
        switchView('fleet');
        return;
    }
    if (hash === 'issues' || hash === 'reports') {
        switchView('reports');
        return;
    }

    // A user whose only access here is Bikes has no Live board to fall back to.
    @if($bikesOnly ?? false)
        switchView('fleet');
        return;
    @endif

    loadRiders();
    startAutoRefresh();
    // 🚚 The default landing view does NOT go through switchView(), so the van
    //    panel has to be started here too — hooking only switchView left it dead
    //    on the very screen it belongs to (caught in browser verification).
    if (typeof vpStart === 'function') vpStart();
});

// =============================================
// VIEW SWITCHING
// =============================================
function switchView(view) {
    currentView = view;
    
    // Update button states.
    // NOTE: History / Dispatch Tracker / Issues no longer have toggle buttons
    // (Day Review replaced them), so every lookup here must tolerate a missing
    // element — switchView('dispatch') is still reachable from old deep links.
    var setActive = function (id, on) {
        var el = document.getElementById(id);
        if (el) el.classList.toggle('active', on);
    };
    // Riders and Open orders are two layers of the SAME 🔴 Live tab, so the tab
    // stays lit for both.
    setActive('viewRidersBtn', view === 'riders' || view === 'orders');
    setActive('viewDayReviewBtn', view === 'dayreview');
    setActive('viewFleetBtn', view === 'fleet');
    setActive('viewHistoryBtn', view === 'history');
    setActive('viewDispatchBtn', view === 'dispatch');
    setActive('viewReportsBtn', view === 'reports');

    // Hide all views
    var hide = function (id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    };
    hide('ridersListView');
    hide('singleRiderView');
    hide('openOrdersView');
    hide('historyView');
    hide('dispatchView');
    hide('reportsView');
    hide('dayReviewView');
    hide('fleetView');
    document.getElementById('filtersSection').style.display = 'none';
    document.getElementById('historyBanner').style.display = 'none';
    hide('liveLayerBar');

    // The Live layer switch is visible for both of its layers.
    var isLive = (view === 'riders' || view === 'orders');
    if (isLive) {
        document.getElementById('liveLayerBar').style.display = 'flex';
        setActive('liveLayerRiders', view === 'riders');
        setActive('liveLayerOrders', view === 'orders');
    }

    // 🚚 The van panel belongs to the LIVE riders layer only. Stopping its poll
    //    everywhere else keeps every other tab exactly as cheap as it was.
    if (typeof vpStop === 'function') vpStop();

    if (view === 'riders') {
        document.getElementById('datePicker').style.display = 'flex';
        document.getElementById('ridersListView').style.display = 'block';
        document.getElementById('pageTitle').textContent = currentDate ? '📅 Riders History' : '🔴 Live — Riders';
        document.getElementById('liveLayerHint').textContent = currentDate ? '' : 'Live positions, refreshing automatically';
        loadRiders();
        startAutoRefresh();
        // Live only — a past date is history, and there is no van out in history.
        if (!currentDate && typeof vpStart === 'function') vpStart();
        else { var vpEl = document.getElementById('vanPanel'); if (vpEl) vpEl.style.display = 'none'; }
    } else if (view === 'orders') {
        document.getElementById('datePicker').style.display = 'none';
        document.getElementById('filtersSection').style.display = 'flex';
        document.getElementById('openOrdersView').style.display = 'block';
        document.getElementById('pageTitle').textContent = '🔴 Live — Open Orders';
        document.getElementById('liveLayerHint').textContent = 'Every order still to be delivered';
        stopAutoRefresh();
        loadOpenOrders();
    } else if (view === 'history') {
        document.getElementById('datePicker').style.display = 'none';
        document.getElementById('historyView').style.display = 'block';
        document.getElementById('pageTitle').textContent = '📅 Delivery History';
        stopAutoRefresh();
        resetHistoryView();
        loadHistoryRiders();
    } else if (view === 'dispatch') {
        document.getElementById('datePicker').style.display = 'none';
        document.getElementById('dispatchView').style.display = 'block';
        document.getElementById('pageTitle').textContent = '🚀 Dispatch Tracker';
        stopAutoRefresh();
        dtInit();
    } else if (view === 'reports') {
        document.getElementById('datePicker').style.display = 'none';
        document.getElementById('reportsView').style.display = 'block';
        document.getElementById('pageTitle').textContent = '⚠️ Daily Issues';
        stopAutoRefresh();
        rrInit();
    } else if (view === 'dayreview') {
        document.getElementById('datePicker').style.display = 'none';
        document.getElementById('dayReviewView').style.display = 'block';
        document.getElementById('pageTitle').textContent = '📅 Day Review';
        stopAutoRefresh();
        drInit();
    } else if (view === 'fleet') {
        document.getElementById('datePicker').style.display = 'none';
        document.getElementById('fleetView').style.display = 'block';
        document.getElementById('pageTitle').textContent = '🏍️ Bikes — fuel & running cost';
        stopAutoRefresh();
        flInit();
    }
}

// =============================================
// DATE HANDLING
// =============================================
function onDateChange(dateValue) {
    const today = new Date().toISOString().split('T')[0];
    
    if (dateValue === today) {
        currentDate = null;
        document.getElementById('pageTitle').textContent = '📍 All Riders - Live Map';
        document.getElementById('historyBanner').style.display = 'none';
        startAutoRefresh();
    } else {
        currentDate = dateValue;
        const dateObj = new Date(dateValue);
        const formattedDate = dateObj.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
        document.getElementById('pageTitle').textContent = `📅 Riders History - ${formattedDate}`;
        document.getElementById('historyBanner').style.display = 'block';
        stopAutoRefresh();
    }
    
    loadRiders();
}

function setToday() {
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('dateInput').value = today;
    onDateChange(today);
}

// =============================================
// AUTO-REFRESH
// =============================================
function startAutoRefresh() {
    stopAutoRefresh();
    if (currentDate === null && currentView === 'riders') {
        autoRefreshInterval = setInterval(() => {
            loadRiders(true);
        }, 30000);
    }
}

function stopAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
    }
}

// =============================================
// RIDERS LIST
// =============================================
async function loadRiders(silent = false) {
    const container = document.getElementById('ridersContainer');
    const isHistory = currentDate !== null;
    
    if (!silent) {
        container.innerHTML = `
            <div class="empty-state">
                <svg class="loading-spinner" style="width: 32px; height: 32px; margin: 0 auto 12px;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Loading ${isHistory ? 'history' : 'riders'}...
            </div>
        `;
    }
    
    try {
        let url = '/orders/riders-map/active';
        if (currentDate) url += `?date=${currentDate}`;
        
        const response = await fetch(url, {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        });
        
        const data = await response.json();
        
        if (data.success && data.riders.length > 0) {
            const isHistoryView = data.is_history === true;
            container.innerHTML = data.riders.map(rider => renderRiderCard(rider, isHistoryView)).join('');
        } else {
            container.innerHTML = `
                <div class="empty-state">
                    <div class="icon">${isHistory ? '📅' : '📍'}</div>
                    <div style="font-weight: 500;">No ${isHistory ? 'activity' : 'active riders'} found</div>
                    <div style="font-size: 13px; margin-top: 4px;">
                        ${isHistory ? 'No riders found for this date' : 'Riders will appear here when they check in or have location data'}
                    </div>
                </div>
            `;
        }
    } catch (error) {
        console.error('Failed to load riders:', error);
        container.innerHTML = `<div class="empty-state"><div class="icon">⚠️</div>Failed to load riders</div>`;
    }
}

function renderRiderCard(rider, isHistoryView) {
    const hasLocation = rider.last_location !== null;
    const hasSync = rider.last_sync !== null;
    const isOnDuty = rider.is_checked_in && !rider.is_checked_out;
    
    let statusColor, statusText, statusBadge;
    
    if (isHistoryView) {
        if (rider.is_checked_in) {
            statusColor = '#10B981';
            statusText = rider.is_checked_out ? '✓ Checked Out' : '✓ Was On Duty';
            statusBadge = `<div style="background: #dbeafe; color: #1e40af; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 500;">📅 ${rider.orders_today.delivered} delivered</div>`;
        } else {
            statusColor = '#6B7280';
            statusText = '— Not checked in';
            statusBadge = `<div style="background: #f3f4f6; color: #6b7280; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 500;">${rider.orders_today.delivered} delivered</div>`;
        }
    } else {
        // ⭐ Show GPS-heartbeat freshness as the primary "Last seen" signal
        //    (this reflects how fresh the rider's location actually is). Only
        //    fall back to app-sync time when there is no GPS data at all.
        let isOnline = false, isAway = false, lastSeenText = 'Never', lastSeenMinutes = null, freshnessSource = null;
        
        if (hasLocation && rider.last_location.age_minutes !== null) {
            lastSeenMinutes = rider.last_location.age_minutes;
            lastSeenText = rider.last_location.age || 'Unknown';
            freshnessSource = 'gps';
            isOnline = lastSeenMinutes <= 6;
            isAway = lastSeenMinutes > 6 && lastSeenMinutes <= 15;
        } else if (hasSync && rider.last_sync.age_minutes !== null) {
            lastSeenMinutes = rider.last_sync.age_minutes;
            lastSeenText = rider.last_sync.age || 'Unknown';
            freshnessSource = 'app';
            isOnline = lastSeenMinutes <= 2;
            isAway = lastSeenMinutes > 2 && lastSeenMinutes <= 10;
        }
        
        if (!isOnDuty) {
            statusColor = '#6B7280';
            statusText = rider.is_checked_out ? '⚫ Checked Out' : '⚫ Offline';
        } else if (isOnline) {
            statusColor = '#10B981';
            statusText = '🟢 Online';
        } else if (isAway) {
            statusColor = '#f59e0b';
            statusText = '🟡 Away';
        } else {
            statusColor = '#6B7280';
            statusText = '⚫ Offline';
        }
        
        const freshLabel = freshnessSource === 'gps' ? '📍 GPS' : '📲 App';
        if (isOnline) {
            statusBadge = `<div style="background: #d1fae5; color: #065f46; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 500;">${freshnessSource === 'gps' ? '📍 GPS live' : '● Active now'}</div>`;
        } else if (lastSeenMinutes !== null) {
            const bg = lastSeenMinutes <= 10 ? '#fef3c7' : '#fee2e2';
            const color = lastSeenMinutes <= 10 ? '#92400e' : '#991b1b';
            statusBadge = `<div style="background: ${bg}; color: ${color}; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 500;">${freshLabel}: ${lastSeenText}</div>`;
        } else {
            statusBadge = `<div style="background: #f3f4f6; color: #6b7280; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 500;">No GPS activity</div>`;
        }
    }
    
    let appStatusHtml = '';
    if (!isHistoryView && rider.app_status) {
        const appLoggedIn = rider.app_status.is_logged_in;
        const appVersion = rider.app_status.app_version;
        const appColor = appLoggedIn ? '#10b981' : '#ef4444';
        const appIcon = appLoggedIn ? '📱' : '📵';
        const appText = appLoggedIn ? 'Logged In' : 'Logged Out';
        appStatusHtml = `<div style="display: flex; align-items: center; gap: 6px; margin-top: 4px; font-size: 11px;"><span>${appIcon}</span><span style="color: ${appColor}; font-weight: 500;">${appText}</span>${appVersion ? `<span style="color: #9ca3af;">v${appVersion}</span>` : ''}</div>`;
    }
    
    return `
        <div class="rider-card" onclick="openRiderMap(${rider.id}, '${rider.name.replace(/'/g, "\\'")}')">
            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                <div>
                    <div style="font-weight: 600; font-size: 16px; color: #1f2937;">${rider.name}</div>
                    <div style="display: flex; align-items: center; gap: 6px; margin-top: 4px;">
                        <span style="font-size: 12px; color: ${statusColor}; font-weight: 500;">${statusText}</span>
                    </div>
                    ${appStatusHtml}
                </div>
                ${statusBadge}
            </div>
            <div style="display: flex; gap: 16px; font-size: 13px;">
                ${isHistoryView ? `
                    <div title="Delivered"><span style="color: #10b981;">✓</span> <span style="font-weight: 600; color: #10b981;">${rider.orders_today.delivered}</span> <span style="color: #6b7280; font-size: 11px;">Delivered</span></div>
                ` : `
                    <div title="Open"><span style="color: #f59e0b;">⏳</span> <span style="font-weight: 600; color: #f59e0b;">${rider.orders_today.pending}</span> <span style="color: #6b7280; font-size: 11px;">Open</span></div>
                    <div title="Today"><span style="color: #10b981;">✓</span> <span style="font-weight: 600; color: #10b981;">${rider.orders_today.delivered}</span> <span style="color: #6b7280; font-size: 11px;">Today</span></div>
                `}
            </div>
        </div>
    `;
}

// =============================================
// SINGLE RIDER MAP
// =============================================
async function openRiderMap(riderId, riderName) {
    currentRiderId = riderId;
    currentRiderName = riderName;
    
    const title = currentDate 
        ? `📅 ${riderName} - ${new Date(currentDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} Deliveries`
        : `📍 ${riderName} - Orders & Deliveries`;
    
    document.getElementById('singleRiderTitle').textContent = title;
    document.getElementById('ridersListView').style.display = 'none';
    document.getElementById('singleRiderView').style.display = 'block';
    
    // Initialize map
    if (singleRiderMap) singleRiderMap.remove();
    singleRiderMap = L.map('singleRiderMap').setView([33.6844, 73.0479], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors' }).addTo(singleRiderMap);
    
    await loadRiderMapData(riderId);
}

async function loadRiderMapData(riderId) {
    try {
        let url = `/orders/riders-map/${riderId}`;
        if (currentDate) url += `?date=${currentDate}`;
        
        const response = await fetch(url, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') }
        });
        
        const data = await response.json();
        if (!data.success) throw new Error(data.message || 'Failed to load');
        
        currentRiderOrders = data.orders || [];
        const withLocation = currentRiderOrders.filter(o => o.location).length;
        const withoutLocation = currentRiderOrders.length - withLocation;
        
        document.getElementById('singleRiderSummary').innerHTML = `
            <span>📦 ${data.summary.total_orders} orders</span>
            <span style="color: #10b981;">✓ ${data.summary.delivered} delivered</span>
            <span style="color: #f59e0b;">⏳ ${data.summary.pending} pending</span>
            <span style="color: #10b981;">📍 ${withLocation}</span>
            <span style="color: #ef4444;">❌ ${withoutLocation}</span>
        `;
        
        const bounds = [];
        
        // Add rider location
        if (data.rider.current_location) {
            const riderIcon = L.divIcon({
                className: 'rider-marker',
                html: `<div style="width: 24px; height: 24px; background: #3b82f6; border: 3px solid white; border-radius: 50%; box-shadow: 0 2px 8px rgba(59,130,246,0.5); animation: pulse 2s infinite;"></div>`,
                iconSize: [24, 24], iconAnchor: [12, 12]
            });
            L.marker([data.rider.current_location.latitude, data.rider.current_location.longitude], {icon: riderIcon})
                .addTo(singleRiderMap)
                .bindPopup(`<b>🚴 ${data.rider.name}</b><br>Last seen: ${data.rider.current_location.age}`);
            bounds.push([data.rider.current_location.latitude, data.rider.current_location.longitude]);
        }
        
        // Add order markers
        data.orders.forEach(order => {
            if (!order.location) return;
            const isDelivered = ['delivered', 'completed'].includes(order.status);
            const color = isDelivered ? '#10b981' : (order.status.includes('delivery') ? '#f59e0b' : '#6b7280');
            const deliveryTime = isDelivered && order.delivered_at ? new Date(order.delivered_at).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true }) : '';
            const etaTime = order.estimated_delivery_at_display || '';
            const etaCompare = order.eta_comparison;
            
            const icon = L.divIcon({
                className: 'order-marker',
                html: `<div style="display: flex; flex-direction: column; align-items: center;">
                    <div style="width: 28px; height: 28px; background: ${color}; border: 2px solid white; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: white; font-size: 14px; box-shadow: 0 2px 6px rgba(0,0,0,0.2);">${isDelivered ? '✓' : '📦'}</div>
                    ${deliveryTime ? `<div style="background: ${color}; color: white; font-size: 9px; padding: 1px 4px; border-radius: 3px; margin-top: 2px; font-weight: 600;">${deliveryTime}</div>` : ''}
                </div>`,
                iconSize: isDelivered ? [40, 55] : [28, 28], iconAnchor: isDelivered ? [20, 28] : [14, 14]
            });
            
            let popupHtml = `<b>${order.order_number}</b><br>${order.customer_name}<br><span style="color: ${color};">${order.status_display}</span>`;
            if (deliveryTime) popupHtml += `<br>🕐 Delivered: ${deliveryTime}`;
            if (etaTime) popupHtml += `<br>⏱️ ETA: ${etaTime}`;
            if (etaCompare) popupHtml += `<br><span style="color: ${etaCompare.status === 'early' ? '#10b981' : '#ef4444'}; font-weight: 600;">${etaCompare.status_text}</span>`;
            
            L.marker([order.location.latitude, order.location.longitude], {icon})
                .addTo(singleRiderMap)
                .bindPopup(popupHtml);
            bounds.push([order.location.latitude, order.location.longitude]);
        });
        
        if (bounds.length > 0) singleRiderMap.fitBounds(bounds, {padding: [50, 50]});
        
        // Render orders list
        const ordersContainer = document.getElementById('singleRiderOrders');
        ordersContainer.innerHTML = data.orders.length > 0 ? data.orders.map(order => {
            const isDelivered = ['delivered', 'completed'].includes(order.status);
            const color = isDelivered ? '#10b981' : '#f59e0b';
            const hasLoc = order.location !== null;
            // ⭐ Planned drop position (Aug-23) — same chip as the van cards' vp-oseq.
            //   Active stops only: on a delivered row the plan is history, and the
            //   delivered list is date-ordered, where stale stop numbers just confuse.
            const seqChip = (!isDelivered && order.delivery_priority != null)
                ? `<span style="flex:0 0 auto;min-width:17px;height:17px;line-height:17px;border-radius:9px;background:#E0E7FF;color:#3730A3;font-weight:800;font-size:10.5px;text-align:center;padding:0 3px;display:inline-block;margin-right:6px;">${order.delivery_priority}</span>`
                : '';
            return `<div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 16px; border-bottom: 1px solid #f3f4f6;">
                <div>${seqChip}<span style="font-weight: 600; color: #3b82f6;">${order.order_number}</span> <span style="color: #374151; margin-left: 8px;">${order.customer_name}</span></div>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <span style="color: ${color}; font-size: 12px;">${order.status_display}</span>
                    <span style="background: ${hasLoc ? '#d1fae5' : '#fee2e2'}; color: ${hasLoc ? '#065f46' : '#991b1b'}; padding: 2px 6px; border-radius: 4px; font-size: 11px;">${hasLoc ? '📍' : '❌'}</span>
                </div>
            </div>`;
        }).join('') : '<div style="padding: 20px; text-align: center; color: #6b7280;">No orders</div>';
        
    } catch (error) {
        console.error('Failed to load rider data:', error);
        document.getElementById('singleRiderSummary').innerHTML = `<span style="color: #ef4444;">Error</span>`;
    }
}

function backToRidersList() {
    document.getElementById('singleRiderView').style.display = 'none';
    document.getElementById('ridersListView').style.display = 'block';
    if (singleRiderMap) { singleRiderMap.remove(); singleRiderMap = null; }
}

// =============================================
// OPEN ORDERS MAP
// =============================================
async function loadOpenOrders() {
    const mapContainer = document.getElementById('openOrdersMap');
    const listContainer = document.getElementById('openOrdersList');
    const summaryEl = document.getElementById('filterSummary');
    
    summaryEl.textContent = 'Loading...';
    listContainer.innerHTML = '<div style="padding: 20px; text-align: center; color: #6b7280;">Loading orders...</div>';
    
    try {
        const statusFilter = document.getElementById('statusFilter').value;
        const riderFilter = document.getElementById('riderFilter').value;
        
        let url = '/orders/riders-map/all-open-orders';
        const params = [];
        if (statusFilter && statusFilter !== 'all') params.push(`status=${statusFilter}`);
        if (riderFilter && riderFilter !== 'all') params.push(`rider_id=${riderFilter}`);
        if (params.length > 0) url += '?' + params.join('&');
        
        const response = await fetch(url, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') }
        });
        
        const data = await response.json();
        if (!data.success) throw new Error(data.message || 'Failed to load');
        
        allOrdersData = data;
        populateFilters(data.filters);
        
        summaryEl.innerHTML = `<span style="font-weight: 600;">${data.summary.total}</span> orders (<span style="color: #10b981;">${data.summary.with_location} with GPS</span>, <span style="color: #ef4444;">${data.summary.without_location} without</span>)`;
        
        // Initialize map
        if (openOrdersMap) openOrdersMap.remove();
        openOrdersMap = L.map('openOrdersMap').setView([33.6844, 73.0479], 11);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors' }).addTo(openOrdersMap);
        
        allOrdersMarkers = [];
        const bounds = [];
        
        // Add markers
        data.orders.forEach((order, index) => {
            if (!order.location) return;
            const statusColors = { 'processing': '#f59e0b', 'confirmed': '#3b82f6', 'out_for_delivery': '#8b5cf6', 'out-for-delivery': '#8b5cf6', 'pending': '#6b7280' };
            const color = statusColors[order.status] || '#f59e0b';
            
            const icon = L.divIcon({
                className: 'order-marker',
                html: `<div style="width: 12px; height: 12px; background: ${color}; border: 2px solid white; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.3);"></div>`,
                iconSize: [12, 12], iconAnchor: [6, 6]
            });
            
            const marker = L.marker([order.location.latitude, order.location.longitude], {icon})
                .addTo(openOrdersMap)
                .bindPopup(`<b>${order.order_number}</b><br>${order.customer_name}<br><span style="color: ${color};">${order.status_display}</span><br>🛵 ${order.rider_name}`);
            
            allOrdersMarkers.push(marker);
            bounds.push([order.location.latitude, order.location.longitude]);
        });
        
        if (bounds.length > 0) openOrdersMap.fitBounds(bounds, {padding: [30, 30]});
        
        // Render list with action buttons
        listContainer.innerHTML = data.orders.length > 0 ? `
            <div style="background: #f8fafc; padding: 8px 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb;">📦 Orders (${data.orders.length})</div>
            ${data.orders.map((order, idx) => {
                const statusColors = { 'processing': '#f59e0b', 'confirmed': '#3b82f6', 'out_for_delivery': '#8b5cf6', 'out-for-delivery': '#8b5cf6', 'pending': '#6b7280' };
                const color = statusColors[order.status] || '#6b7280';
                const hasLoc = order.location !== null;
                const isVerified = order.location_source === 'verified_location';
                const locIcon = hasLoc ? (isVerified ? '📍' : '📍~') : '❌';
                const locTitle = hasLoc ? (isVerified ? 'Verified location' : 'Geocoded (approximate)') : 'No location';
                
                // Action buttons - verify location and geocode
                const verifyBtn = !isVerified && order.customer_id ? 
                    `<button onclick="event.stopPropagation(); openVerifyLocation(${order.customer_id}, ${order.id}, '${(order.customer_name || '').replace(/'/g, "\\'")}', '${(order.address || '').replace(/'/g, "\\'")}')" 
                             style="padding: 2px 6px; background: #10b981; color: white; border: none; border-radius: 4px; font-size: 10px; cursor: pointer;" 
                             title="Set verified location">📌</button>` : '';
                
                const geocodeBtn = order.customer_id && !isVerified ? 
                    `<button onclick="event.stopPropagation(); geocodeCustomer(${order.customer_id}, '${(order.customer_name || '').replace(/'/g, "\\'")}')" 
                             style="padding: 2px 6px; background: #f59e0b; color: white; border: none; border-radius: 4px; font-size: 10px; cursor: pointer;" 
                             title="Re-geocode address">🔄</button>` : '';
                
                return `<div onclick="focusOrder(${idx})" style="display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; border-bottom: 1px solid #f3f4f6; font-size: 13px; cursor: pointer;" onmouseover="this.style.background='#f0f9ff'" onmouseout="this.style.background='transparent'">
                    <div style="flex: 1; min-width: 0;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span onclick="event.stopPropagation(); viewOrderDetails(${order.id})" 
                                  style="font-weight: 600; color: #3b82f6; cursor: pointer; text-decoration: underline;"
                                  title="Click to view order details">${order.order_number}</span>
                            <span style="color: #374151;">${order.customer_name}</span>
                        </div>
                        ${order.address ? `<div style="font-size: 11px; color: #9ca3af; margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${order.address}">📍 ${order.address}</div>` : ''}
                    </div>
                    <div style="display: flex; gap: 6px; align-items: center; flex-shrink: 0;">
                        <span style="color: ${color}; font-size: 12px;">${order.status_display}</span>
                        <span style="color: #6b7280; font-size: 11px;">${order.rider_name}</span>
                        <span title="${locTitle}">${locIcon}</span>
                        ${verifyBtn}
                        ${geocodeBtn}
                    </div>
                </div>`;
            }).join('')}
        ` : '<div style="padding: 20px; text-align: center; color: #6b7280;">No open orders found</div>';
        
    } catch (error) {
        console.error('Failed to load orders:', error);
        summaryEl.innerHTML = `<span style="color: #ef4444;">Failed to load</span>`;
    }
}

function populateFilters(filters) {
    const statusSelect = document.getElementById('statusFilter');
    const riderSelect = document.getElementById('riderFilter');
    
    if (statusSelect.options.length <= 1 && filters.statuses) {
        filters.statuses.forEach(status => {
            const count = filters.status_counts ? filters.status_counts[status] || 0 : 0;
            const opt = document.createElement('option');
            opt.value = status;
            opt.textContent = `${status.replace(/_/g, ' ')} (${count})`;
            statusSelect.appendChild(opt);
        });
    }
    
    if (riderSelect.options.length <= 1 && filters.riders) {
        if (filters.unassigned_count > 0) {
            const opt = document.createElement('option');
            opt.value = 'unassigned';
            opt.textContent = `⚠️ Unassigned (${filters.unassigned_count})`;
            riderSelect.appendChild(opt);
        }
        filters.riders.forEach(rider => {
            const count = filters.rider_counts ? filters.rider_counts[rider.id] || 0 : 0;
            const opt = document.createElement('option');
            opt.value = rider.id;
            opt.textContent = `${rider.name} (${count})`;
            riderSelect.appendChild(opt);
        });
    }
}

function applyFilters() { loadOpenOrders(); }

function focusOrder(index) {
    const orders = allOrdersData?.orders;
    if (!orders || !orders[index]) return;
    
    const order = orders[index];
    if (order.location) {
        openOrdersMap.setView([order.location.latitude, order.location.longitude], 16, { animate: true });
        if (allOrdersMarkers[index]) allOrdersMarkers[index].openPopup();
    }
}

// =============================================
// HISTORY VIEW
// =============================================
function resetHistoryView() {
    document.getElementById('historyRidersList').style.display = 'block';
    document.getElementById('historyDateGroups').style.display = 'none';
    document.getElementById('historyDateMap').style.display = 'none';
    historyRiderData = null; // Clear cached data
}

async function loadHistoryRiders() {
    const container = document.getElementById('historyRidersContainer');
    container.innerHTML = '<div class="empty-state"><svg class="loading-spinner" style="width: 32px; height: 32px; margin: 0 auto 12px;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Loading...</div>';
    
    try {
        const response = await fetch('/orders/riders-map/riders-for-history', {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') }
        });
        const data = await response.json();
        
        if (data.success && data.riders.length > 0) {
            container.innerHTML = data.riders.map(rider => `
                <div class="rider-card" onclick="openRiderHistory(${rider.id}, '${rider.name.replace(/'/g, "\\'")}')">
                    <div style="display: flex; justify-content: space-between; align-items: start;">
                        <div>
                            <div style="font-weight: 600; font-size: 16px; color: #1f2937;">${rider.name}</div>
                            <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">View delivery history</div>
                        </div>
                        <div style="background: #dbeafe; color: #1e40af; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 500;">📅 History</div>
                    </div>
                </div>
            `).join('');
        } else {
            container.innerHTML = '<div class="empty-state"><div class="icon">📅</div>No riders found</div>';
        }
    } catch (error) {
        console.error('Failed to load history riders:', error);
        container.innerHTML = '<div class="empty-state"><div class="icon">⚠️</div>Failed to load</div>';
    }
}

// Store rider history data for use in map view
let historyRiderData = null;

async function openRiderHistory(riderId, riderName) {
    historyRiderId = riderId;
    historyRiderName = riderName;
    
    document.getElementById('historyRidersList').style.display = 'none';
    document.getElementById('historyDateGroups').style.display = 'block';
    document.getElementById('historyRiderName').textContent = riderName;
    
    const container = document.getElementById('historyDateContainer');
    container.innerHTML = '<div style="padding: 40px; text-align: center; color: #6b7280;"><svg class="loading-spinner" style="width: 32px; height: 32px; margin: 0 auto 12px;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Loading...</div>';
    
    try {
        const response = await fetch(`/orders/riders-map/rider-history/${riderId}`, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') }
        });
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.message || 'Failed to load');
        }
        
        // Store for later use
        historyRiderData = data;
        
        // Update summary with cash/online breakdown
        document.getElementById('historyRiderSummary').innerHTML = `
            <span style="font-weight: 600;">${data.summary.total_delivered}</span> delivered • 
            <span style="color: #10b981;">Cash: Rs. ${Math.round(data.summary.cash_total).toLocaleString()} (${data.summary.cash_count})</span> • 
            <span style="color: #3b82f6;">Online: Rs. ${Math.round(data.summary.online_total).toLocaleString()} (${data.summary.online_count})</span>
        `;
        
        // Render date groups - API returns 'dates' array with 'date_display'
        if (data.dates && data.dates.length > 0) {
            container.innerHTML = data.dates.map((dateGroup, idx) => {
                const totalAmount = (dateGroup.cash_total || 0) + (dateGroup.online_total || 0);
                const isExpanded = idx === 0; // First group starts expanded
                return `
                    <div style="background: white; border-radius: 12px; margin-bottom: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 14px 16px; background: #f8fafc; border-bottom: 1px solid #e5e7eb; cursor: pointer;"
                             onclick="toggleHistoryDateGroup('${dateGroup.date}')"
                             title="Click to show/hide orders">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span id="historyToggle_${dateGroup.date}" style="font-size: 14px; color: #9ca3af; transition: transform 0.2s; ${isExpanded ? 'transform: rotate(90deg);' : ''}">▶</span>
                                <div>
                                    <div style="font-size: 14px; font-weight: 600; color: #1f2937;">📅 ${dateGroup.date_display}</div>
                                    <div style="font-size: 12px; color: #6b7280; margin-top: 2px;">
                                        ${dateGroup.total_delivered} orders • Rs. ${Math.round(totalAmount).toLocaleString()} total
                                    </div>
                                </div>
                            </div>
                            <div style="display: flex; gap: 12px; align-items: center;">
                                <div style="display: flex; gap: 8px;">
                                    <span style="background: #d1fae5; color: #065f46; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 500;">
                                        💵 ${dateGroup.cash_count} • Rs. ${Math.round(dateGroup.cash_total || 0).toLocaleString()}
                                    </span>
                                    <span style="background: #dbeafe; color: #1e40af; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 500;">
                                        💳 ${dateGroup.online_count} • Rs. ${Math.round(dateGroup.online_total || 0).toLocaleString()}
                                    </span>
                                </div>
                                <button onclick="event.stopPropagation(); openHistoryDate('${dateGroup.date}', '${dateGroup.date_display}')" 
                                        style="background: #9333EA; color: white; border: none; padding: 6px 12px; border-radius: 6px; font-size: 12px; cursor: pointer;"
                                        title="View on map">
                                    🗺️ Map
                                </button>
                            </div>
                        </div>
                        <div id="historyOrders_${dateGroup.date}" style="display: ${isExpanded ? 'block' : 'none'}; max-height: 300px; overflow-y: auto;">
                            ${(dateGroup.orders || []).map(order => `
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 16px; border-bottom: 1px solid #f3f4f6;">
                                    <div>
                                        <span style="font-weight: 600; color: #3b82f6;">${order.order_number}</span>
                                        <span style="color: #374151; margin-left: 8px;">${order.customer_name}</span>
                                    </div>
                                    <div style="display: flex; gap: 8px; align-items: center;">
                                        <span style="font-weight: 600; color: ${order.payment_type === 'cash' ? '#10b981' : '#3b82f6'};">
                                            ${order.amount_formatted}
                                        </span>
                                        <span style="background: ${order.payment_type === 'cash' ? '#d1fae5' : '#dbeafe'}; color: ${order.payment_type === 'cash' ? '#065f46' : '#1e40af'}; padding: 2px 6px; border-radius: 4px; font-size: 10px;">
                                            ${order.payment_type === 'cash' ? '💵 Cash' : '💳 Online'}
                                        </span>
                                        <span style="color: #6b7280; font-size: 11px;">🕐 ${order.delivered_at}</span>
                                        ${order.estimated_delivery_at_display ? `<span style="color: #8B5CF6; font-size: 11px;">⏱️ ${order.estimated_delivery_at_display}</span>` : ''}
                                        ${order.eta_comparison ? `<span style="color: ${order.eta_comparison.status === 'early' ? '#10b981' : '#ef4444'}; font-size: 10px; font-weight: 600;">${order.eta_comparison.status_text}</span>` : ''}
                                        <span style="color: ${order.location ? '#10b981' : '#ef4444'};">${order.location ? '📍' : '❌'}</span>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            }).join('');
        } else {
            container.innerHTML = '<div style="padding: 60px; text-align: center; color: #6b7280;"><div style="font-size: 48px; margin-bottom: 12px;">📅</div>No delivery history found</div>';
        }
    } catch (error) {
        console.error('Failed to load rider history:', error);
        container.innerHTML = '<div style="padding: 40px; text-align: center; color: #ef4444;">Failed to load: ' + error.message + '</div>';
    }
}

// Toggle date group expansion
function toggleHistoryDateGroup(dateKey) {
    const ordersDiv = document.getElementById('historyOrders_' + dateKey);
    const toggleIcon = document.getElementById('historyToggle_' + dateKey);
    
    if (ordersDiv.style.display === 'none') {
        ordersDiv.style.display = 'block';
        toggleIcon.style.transform = 'rotate(90deg)';
    } else {
        ordersDiv.style.display = 'none';
        toggleIcon.style.transform = 'rotate(0deg)';
    }
}

function backToHistoryRiders() {
    document.getElementById('historyDateGroups').style.display = 'none';
    document.getElementById('historyRidersList').style.display = 'block';
    historyRiderData = null; // Clear cached data
}

async function openHistoryDate(date, displayDate) {
    document.getElementById('historyDateGroups').style.display = 'none';
    document.getElementById('historyDateMap').style.display = 'block';
    document.getElementById('historyMapTitle').textContent = `${historyRiderName} - ${displayDate}`;
    
    // Get orders for this date from cached data
    let dateOrders = [];
    if (historyRiderData && historyRiderData.dates) {
        const dateGroup = historyRiderData.dates.find(d => d.date === date);
        if (dateGroup && dateGroup.orders) {
            dateOrders = dateGroup.orders;
        }
    }
    
    // Initialize map
    if (historyMap) historyMap.remove();
    historyMap = L.map('historyMapContainer').setView([33.6844, 73.0479], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors' }).addTo(historyMap);
    
    // Add markers for orders with location
    const bounds = [];
    let deliveredCount = 0;
    
    dateOrders.forEach(order => {
        deliveredCount++;
        if (!order.location) return;
        
        const color = '#10b981'; // Green for delivered
        const deliveryTime = order.delivered_at || '';
        
        const icon = L.divIcon({
            className: 'order-marker',
            html: `<div style="display: flex; flex-direction: column; align-items: center;">
                <div style="width: 28px; height: 28px; background: ${color}; border: 2px solid white; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: white; font-size: 14px;">✓</div>
                ${deliveryTime ? `<div style="background: ${color}; color: white; font-size: 9px; padding: 1px 4px; border-radius: 3px; margin-top: 2px; font-weight: 600;">${deliveryTime}</div>` : ''}
            </div>`,
            iconSize: [40, 55], iconAnchor: [20, 28]
        });
        
        let histPopup = `<b>${order.order_number}</b><br>${order.customer_name}<br>Delivered`;
        if (deliveryTime) histPopup += `<br>🕐 Delivered: ${deliveryTime}`;
        if (order.estimated_delivery_at_display) histPopup += `<br>⏱️ ETA: ${order.estimated_delivery_at_display}`;
        if (order.eta_comparison) histPopup += `<br><span style="color: ${order.eta_comparison.status === 'early' ? '#10b981' : '#ef4444'}; font-weight: 600;">${order.eta_comparison.status_text}</span>`;
        histPopup += `<br>${order.amount_formatted}`;
        
        L.marker([order.location.latitude, order.location.longitude], {icon})
            .addTo(historyMap)
            .bindPopup(histPopup);
        bounds.push([order.location.latitude, order.location.longitude]);
    });
    
    if (bounds.length > 0) {
        historyMap.fitBounds(bounds, {padding: [50, 50]});
    }
    
    document.getElementById('historyMapSummary').textContent = `${deliveredCount} delivered (${bounds.length} with GPS)`;
    
    // Render orders list
    document.getElementById('historyOrdersList').innerHTML = dateOrders.length > 0 ? dateOrders.map(order => {
        const hasLoc = order.location !== null;
        return `<div style="border-bottom: 1px solid #f3f4f6;">
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 16px;">
                <div>
                    <span style="font-weight: 600; color: #3b82f6;">${order.order_number}</span>
                    <span style="color: #374151; margin-left: 8px;">${order.customer_name}</span>
                </div>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <span style="font-weight: 600; color: ${order.payment_type === 'cash' ? '#10b981' : '#3b82f6'};">
                        ${order.amount_formatted}
                    </span>
                    <span style="background: ${order.payment_type === 'cash' ? '#d1fae5' : '#dbeafe'}; color: ${order.payment_type === 'cash' ? '#065f46' : '#1e40af'}; padding: 2px 6px; border-radius: 4px; font-size: 10px;">
                        ${order.payment_type === 'cash' ? '💵' : '💳'}
                    </span>
                    <span style="color: #6b7280; font-size: 11px;">🕐 ${order.delivered_at}</span>
                    ${order.estimated_delivery_at_display ? `<span style="color: #8B5CF6; font-size: 11px;">⏱️ ${order.estimated_delivery_at_display}</span>` : ''}
                    ${order.eta_comparison ? `<span style="color: ${order.eta_comparison.status === 'early' ? '#10b981' : '#ef4444'}; font-size: 10px; font-weight: 600;">${order.eta_comparison.status_text}</span>` : ''}
                    <span style="color: ${hasLoc ? '#10b981' : '#ef4444'};">${hasLoc ? '📍' : '❌'}</span>
                    <button onclick="event.stopPropagation(); loadDeliveryJourney(${order.id}, '${(order.order_number || '').replace(/'/g, "\\'")}');" style="padding: 2px 8px; background: #8B5CF6; color: white; border: none; border-radius: 4px; font-size: 10px; cursor: pointer; font-weight: 500;" title="View delivery journey">🛣️ Journey</button>
                </div>
            </div>
            <div id="journey-panel-${order.id}" style="display: none;"></div>
        </div>`;
    }).join('') : '<div style="padding: 20px; text-align: center; color: #6b7280;">No orders for this date</div>';
}

function backToHistoryDates() {
    document.getElementById('historyDateMap').style.display = 'none';
    document.getElementById('historyDateGroups').style.display = 'block';
    if (historyMap) { historyMap.remove(); historyMap = null; }
}

function geocodeHistoryAll() {
    // Placeholder for geocoding functionality
    alert('Geocoding not implemented in standalone view. Use the orders page for full functionality.');
}

// =============================================
// ACTION BUTTON FUNCTIONS
// =============================================

// View order details - opens in new tab
function viewOrderDetails(orderId) {
    window.open('/orders?order_id=' + orderId, '_blank');
}

// Open verify location prompt
function openVerifyLocation(customerId, orderId, customerName, address) {
    const url = prompt(`Enter Google Maps URL for ${customerName}:`, '');
    if (url && url.trim()) {
        saveVerifiedLocation(customerId, url.trim());
    }
}

// Save verified location from Google Maps URL
function saveVerifiedLocation(customerId, googleMapsUrl) {
    fetch(`/customers/${customerId}/set-verified-location`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        },
        body: JSON.stringify({
            google_maps_url: googleMapsUrl
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ Verified location saved successfully!');
            // Refresh open orders
            loadOpenOrders();
        } else {
            alert('❌ Failed to save location: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error saving location:', error);
        alert('❌ Error saving location');
    });
}

// =============================================
// DELIVERY JOURNEY
// =============================================
let journeyMaps = {}; // Track Leaflet maps per order to clean up

function loadDeliveryJourney(orderId, orderNumber) {
    const panel = document.getElementById('journey-panel-' + orderId);
    if (!panel) return;
    
    // Toggle: if already visible, hide it
    if (panel.style.display === 'block') {
        panel.style.display = 'none';
        if (journeyMaps[orderId]) {
            journeyMaps[orderId].remove();
            delete journeyMaps[orderId];
        }
        return;
    }
    
    // Show loading
    panel.style.display = 'block';
    panel.innerHTML = '<div style="padding: 16px; text-align: center; color: #6b7280; font-size: 13px;"><span style="display: inline-block; animation: spin 1s linear infinite; font-size: 16px;">⏳</span> Loading journey for ' + orderNumber + '...</div>';
    
    fetch('/orders/delivery-journey/' + orderId, {
        headers: { 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            panel.innerHTML = '<div style="padding: 12px 16px; color: #ef4444; font-size: 12px;">❌ ' + (data.message || 'Failed to load journey') + '</div>';
            return;
        }
        
        const s = data.summary;
        const hasTrail = data.trail && data.trail.length > 1;
        const hasStops = data.stops && data.stops.length > 0;
        
        let html = '<div style="background: #f8fafc; border-top: 1px solid #e5e7eb; padding: 12px 16px;">';
        
        // Summary bar
        html += '<div style="display: flex; gap: 16px; flex-wrap: wrap; align-items: center; margin-bottom: 10px;">';
        html += '<span style="font-size: 12px; font-weight: 600; color: #374151;">🛣️ Journey: ' + data.journey_start_label + '</span>';
        html += '<span style="font-size: 11px; color: #6b7280;">' + data.journey_start + ' → ' + data.journey_end + '</span>';
        html += '<span style="background: #dbeafe; color: #1e40af; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 600;">⏱ ' + s.total_duration_display + '</span>';
        html += '<span style="background: #dcfce7; color: #166534; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 600;">📏 ' + s.total_distance_display + '</span>';
        html += '<span style="background: #f3e8ff; color: #7c3aed; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 600;">🏃 ' + s.moving_time_display + '</span>';
        if (s.stop_count > 0) {
            html += '<span style="background: #fef3c7; color: #92400e; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 600;">🛑 ' + s.stop_count + ' stop' + (s.stop_count > 1 ? 's' : '') + ' (' + s.stopped_time_display + ')</span>';
        }
        if (s.avg_speed_kmh > 0) {
            html += '<span style="font-size: 10px; color: #6b7280;">Avg: ' + s.avg_speed_kmh + ' km/h</span>';
        }
        if (data.eta_comparison) {
            const ec = data.eta_comparison;
            html += '<span style="color: ' + (ec.status === 'early' ? '#10b981' : '#ef4444') + '; font-size: 10px; font-weight: 600;">ETA ' + ec.estimated_at_display + ' → Actual ' + ec.actual_at_display + ' (' + ec.status_text + ')</span>';
        }
        html += '</div>';
        
        // Stops detail (if any)
        if (hasStops) {
            html += '<div style="margin-bottom: 10px;">';
            data.stops.forEach(function(stop, idx) {
                html += '<div style="display: inline-flex; align-items: center; gap: 4px; background: #fef3c7; border: 1px solid #fbbf24; border-radius: 4px; padding: 2px 8px; margin-right: 6px; margin-bottom: 4px; font-size: 10px;">';
                html += '<span style="font-weight: 600; color: #92400e;">🛑 Stop ' + (idx + 1) + '</span>';
                html += '<span style="color: #78350f;">' + stop.start_time + ' - ' + stop.end_time + '</span>';
                html += '<span style="color: #b45309; font-weight: 600;">' + stop.duration_display + '</span>';
                html += '</div>';
            });
            html += '</div>';
        }
        
        // Mini map
        if (hasTrail) {
            html += '<div id="journey-map-' + orderId + '" style="height: 250px; border-radius: 8px; border: 1px solid #e5e7eb;"></div>';
        } else {
            html += '<div style="padding: 20px; text-align: center; color: #9ca3af; font-size: 12px;">No GPS trail data available for this journey segment</div>';
        }
        
        html += '</div>';
        panel.innerHTML = html;
        
        // Render Leaflet map
        if (hasTrail) {
            setTimeout(function() {
                try {
                    const mapEl = document.getElementById('journey-map-' + orderId);
                    if (!mapEl) return;
                    
                    const map = L.map(mapEl);
                    journeyMaps[orderId] = map;
                    
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '© OpenStreetMap',
                        maxZoom: 19
                    }).addTo(map);
                    
                    // Draw trail polyline
                    const trailCoords = data.trail.map(function(p) { return [p.latitude, p.longitude]; });
                    const polyline = L.polyline(trailCoords, {
                        color: '#3b82f6',
                        weight: 3,
                        opacity: 0.8
                    }).addTo(map);
                    
                    // Start marker (green)
                    L.circleMarker(trailCoords[0], {
                        radius: 7, color: '#16a34a', fillColor: '#22c55e', fillOpacity: 1, weight: 2
                    }).addTo(map).bindPopup('<b>Journey Start</b><br>' + data.journey_start + '<br><small>' + data.journey_start_label + '</small>');
                    
                    // End marker (red - delivery point)
                    L.circleMarker(trailCoords[trailCoords.length - 1], {
                        radius: 7, color: '#dc2626', fillColor: '#ef4444', fillOpacity: 1, weight: 2
                    }).addTo(map).bindPopup('<b>Delivered</b><br>' + data.journey_end + '<br>' + (data.address || ''));
                    
                    // Stop markers (orange)
                    if (hasStops) {
                        data.stops.forEach(function(stop, idx) {
                            L.circleMarker([stop.latitude, stop.longitude], {
                                radius: 6, color: '#d97706', fillColor: '#fbbf24', fillOpacity: 0.9, weight: 2
                            }).addTo(map).bindPopup('<b>🛑 Stop ' + (idx + 1) + '</b><br>' + stop.start_time + ' - ' + stop.end_time + '<br>' + stop.duration_display);
                        });
                    }
                    
                    // Fit to trail bounds
                    map.fitBounds(polyline.getBounds(), { padding: [20, 20] });
                    
                } catch(e) {
                    console.error('Journey map render error:', e);
                }
            }, 100);
        }
    })
    .catch(err => {
        console.error('Journey load error:', err);
        panel.innerHTML = '<div style="padding: 12px 16px; color: #ef4444; font-size: 12px;">❌ Error loading journey data</div>';
    });
}

// =============================================
// DISPATCH TRACKER
// =============================================
function dtInit() {
    if (!dtCurrentDate) {
        dtCurrentDate = new Date().toISOString().split('T')[0];
    }
    const inp = document.getElementById('dtDateInput');
    inp.value = dtCurrentDate;
    inp.max = new Date().toISOString().split('T')[0];
    loadDispatchReport(dtCurrentDate);
}

function dtSetToday() {
    dtCurrentDate = new Date().toISOString().split('T')[0];
    document.getElementById('dtDateInput').value = dtCurrentDate;
    loadDispatchReport(dtCurrentDate);
}

function dtPrevDay() {
    const d = new Date(dtCurrentDate);
    d.setDate(d.getDate() - 1);
    dtCurrentDate = d.toISOString().split('T')[0];
    document.getElementById('dtDateInput').value = dtCurrentDate;
    loadDispatchReport(dtCurrentDate);
}

function dtNextDay() {
    const d = new Date(dtCurrentDate);
    d.setDate(d.getDate() + 1);
    const today = new Date().toISOString().split('T')[0];
    if (d.toISOString().split('T')[0] > today) return;
    dtCurrentDate = d.toISOString().split('T')[0];
    document.getElementById('dtDateInput').value = dtCurrentDate;
    loadDispatchReport(dtCurrentDate);
}

async function loadDispatchReport(date) {
    dtCurrentDate = date;
    const container = document.getElementById('dtRidersContainer');
    const summaryBar = document.getElementById('dtSummaryBar');

    container.innerHTML = '<div style="padding:40px; text-align:center; color:#6b7280;"><svg class="loading-spinner" style="width:32px; height:32px; margin:0 auto 12px;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Loading dispatch report...</div>';
    summaryBar.innerHTML = '';

    try {
        const response = await fetch(`/orders/riders-map/dispatch-report?date=${date}`, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') }
        });
        const data = await response.json();

        if (!data.success) throw new Error(data.message || 'Failed');

        dtReportData = data;
        document.getElementById('dtDateLabel').textContent = data.date_display;

        dtRenderSummary(data.summary);
        dtRenderOngoing(data.ongoing || []);
        dtRenderRiders(data.riders);
    } catch (err) {
        console.error('Dispatch report error:', err);
        container.innerHTML = `<div style="padding:40px; text-align:center; color:#ef4444;">Failed to load: ${err.message}</div>`;
        document.getElementById('dtOngoingContainer').innerHTML = '';
    }
}

// ⭐ LIVE ongoing-dispatch tracking (today only). Shows each rider's current
//    state: on route (by dispatch wave w/ ETA windows), waiting at office,
//    returning to office (approx ETA), or left without dispatching.
function dtRenderOngoing(ongoing) {
    const wrap = document.getElementById('dtOngoingContainer');
    if (!ongoing || ongoing.length === 0) { wrap.innerHTML = ''; return; }

    const meta = {
        left_without_dispatch: {label: '⚠️ Left without dispatch', bg: '#fee2e2', color: '#991b1b', border: '#fca5a5'},
        /* 🔧 The workshop errand — same two rungs the orders-page card draws, from the same
             `getRidersLiveStatus` feed. The LABEL is replaced at render time by the server's
             own `workshop_trip.label` when it is present; these are the fallbacks. */
        workshop_en_route:     {label: '🔧 Going to the workshop',  bg: '#fef3c7', color: '#92400e', border: '#fcd34d'},
        at_workshop:           {label: '🔧 At the workshop',        bg: '#fef3c7', color: '#92400e', border: '#fcd34d'},
        waiting_at_office:     {label: '🏠 Waiting at office',      bg: '#fef9c3', color: '#854d0e', border: '#fde68a'},
        returning:             {label: '↩️ Returning to office',    bg: '#e0f2fe', color: '#075985', border: '#bae6fd'},
        on_route:              {label: '🚚 On route',               bg: '#dcfce7', color: '#166534', border: '#bbf7d0'},
        at_office:             {label: '🏠 At office',              bg: '#f3f4f6', color: '#374151', border: '#e5e7eb'},
        away_unknown:          {label: '📍 Away (GPS stale)',       bg: '#f3f4f6', color: '#6b7280', border: '#e5e7eb'},
        idle:                  {label: '• Idle',                    bg: '#f3f4f6', color: '#6b7280', border: '#e5e7eb'},
    };

    const cards = ongoing.map(r => {
        const m0 = meta[r.status] || meta.idle;
        /* 🔧 The server writes the sentence (where, since when, the ETA) so this card, the
             orders-page card, the store phone and the push all say the same thing. The map
             above only supplies the colours and a fallback word. */
        const m = (r.workshop_trip && r.workshop_trip.label)
            ? Object.assign({}, m0, {label: r.workshop_trip.label}) : m0;
        const dist = r.distance_to_office_display ? `<span class="dt-badge gray">📍 ${r.distance_to_office_display} from office</span>` : '';
        // GPS freshness badge (now / minutes ago / stale / none)
        const ageMin = (r.gps_age_minutes !== null && r.gps_age_minutes !== undefined) ? r.gps_age_minutes : null;
        let gpsBadge = '';
        if (ageMin === null) {
            gpsBadge = `<span class="dt-badge gray">⚪ No GPS</span>`;
        } else if (ageMin <= 6) {
            gpsBadge = `<span class="dt-badge green">🟢 GPS now</span>`;
        } else if (ageMin <= 60) {
            gpsBadge = `<span class="dt-badge amber">🟠 GPS ${r.gps_age_text || (ageMin + ' min ago')}</span>`;
        } else {
            gpsBadge = `<span class="dt-badge gray">⚪ GPS ${r.gps_age_text || (ageMin + ' min ago')}</span>`;
        }
        let officeEta = '';
        if (r.return_to_office) {
            const rto = r.return_to_office;
            const approx = rto.source !== 'google_maps' ? ' (approx)' : '';
            officeEta = `<span class="dt-badge blue">🏠 Back in ~${rto.minutes} min · by ${rto.arrival_display}${approx}</span>`;
        } else if (r.eta_to_office_min && r.status === 'returning') {
            officeEta = `<span class="dt-badge blue">↩️ ~${r.eta_to_office_min} min to office (approx)</span>`;
        }

        // Dispatched waves with ETA windows + progress
        const waves = (r.dispatch_batches || []).map(b => {
            const orders = (b.orders || []).map(o => `
                <div style="display:flex; justify-content:space-between; gap:8px; padding:4px 0; border-bottom:1px dashed #f0f0f0; font-size:12px;">
                    <span style="color:#374151;">${o.priority ? `<b>${o.priority}.</b> ` : ''}${o.order_number} — ${o.customer_name}${o.customer_city ? ` <span style="color:#9ca3af;">(${o.customer_city})</span>` : ''}</span>
                    <span style="white-space:nowrap; color:${o.payment_type === 'cash' ? '#166534' : '#1d4ed8'};">${o.eta_window ? `🕒 ${o.eta_window}` : ''}</span>
                </div>`).join('');
            return `
                <div style="margin-top:8px; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden;">
                    <div style="background:#f9fafb; padding:6px 10px; font-size:12px; font-weight:600; color:#374151; display:flex; justify-content:space-between;">
                        <span>🚀 Dispatched ${b.dispatch_time_display}</span>
                        <span style="color:#6b7280;">${b.delivered_count}/${b.total_count} delivered · ${b.pending_count} pending</span>
                    </div>
                    <div style="padding:4px 10px;">${orders}</div>
                </div>`;
        }).join('');

        // Undispatched (forgot / next dispatch) group
        let undispatchedHtml = '';
        if ((r.undispatched_orders || []).length > 0) {
            const list = r.undispatched_orders.map(o => `
                <div style="display:flex; justify-content:space-between; gap:8px; padding:4px 0; border-bottom:1px dashed #fde0e0; font-size:12px;">
                    <span style="color:#7f1d1d;">${o.priority ? `<b>${o.priority}.</b> ` : ''}${o.order_number} — ${o.customer_name}${o.customer_city ? ` <span style="color:#b45309;">(${o.customer_city})</span>` : ''}</span>
                    <span style="white-space:nowrap; color:${o.payment_type === 'cash' ? '#166534' : '#1d4ed8'};">Rs. ${Math.round(o.amount).toLocaleString()}</span>
                </div>`).join('');
            undispatchedHtml = `
                <div style="margin-top:8px; border:1px solid #fca5a5; border-radius:8px; overflow:hidden;">
                    <div style="background:#fef2f2; padding:6px 10px; font-size:12px; font-weight:700; color:#991b1b;">
                        📋 Not dispatched yet · ${r.undispatched_orders.length} order${r.undispatched_orders.length !== 1 ? 's' : ''}
                    </div>
                    <div style="padding:4px 10px;">${list}</div>
                </div>`;
        }

        return `
            <div style="border:1px solid ${m.border}; border-radius:10px; margin-bottom:10px; overflow:hidden;">
                <div style="background:${m.bg}; padding:10px 14px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                    <div style="font-weight:700; color:#1f2937;">🏍️ ${r.rider_name}</div>
                    <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                        <span class="dt-badge" style="background:${m.bg}; color:${m.color}; border:1px solid ${m.border};">${m.label}</span>
                        ${gpsBadge}
                        ${dist}
                        ${officeEta}
                        <span class="dt-badge gray">📦 ${r.ofd_total} out for delivery</span>
                    </div>
                </div>
                <div style="padding:8px 14px;">
                    ${waves || ''}
                    ${undispatchedHtml}
                    ${(!waves && !undispatchedHtml) ? `<div style="font-size:12px; color:#6b7280;">${r.status === 'returning' ? `✅ All dispatched orders delivered${r.delivered_today ? ` (${r.delivered_today} today)` : ''} — heading back to office.` : 'No active stops.'}</div>` : ''}
                </div>
            </div>`;
    }).join('');

    wrap.innerHTML = `
        <div style="margin: 4px 0 16px;">
            <div style="font-size:13px; font-weight:700; color:#374151; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                🔴 LIVE — In Progress Now (${ongoing.length} rider${ongoing.length !== 1 ? 's' : ''})
            </div>
            ${cards}
        </div>`;
}

function dtRenderSummary(s) {
    const bar = document.getElementById('dtSummaryBar');
    const complianceColor = s.dispatch_compliance_pct >= 80 ? 'green' : (s.dispatch_compliance_pct >= 50 ? 'amber' : 'red');
    const etaColor = (s.eta_accuracy_pct !== null && s.eta_accuracy_pct >= 70) ? 'green' : ((s.eta_accuracy_pct !== null && s.eta_accuracy_pct >= 40) ? 'amber' : 'red');

    bar.innerHTML = `
        <div class="dt-stat blue">
            <div class="dt-val">${s.total_delivered}</div>
            <div class="dt-label">Delivered</div>
        </div>
        <div class="dt-stat">${'<div class="dt-val">' + s.total_riders + '</div><div class="dt-label">Riders</div>'}</div>
        <div class="dt-stat ${complianceColor}">
            <div class="dt-val">${s.dispatch_compliance_pct}%</div>
            <div class="dt-label">Dispatched (${s.total_dispatched}/${s.total_delivered})</div>
        </div>
        <div class="dt-stat ${s.total_undispatched > 0 ? 'red' : 'green'}">
            <div class="dt-val">${s.total_undispatched}</div>
            <div class="dt-label">Not Dispatched</div>
        </div>
        ${s.eta_accuracy_pct !== null ? `
        <div class="dt-stat ${etaColor}">
            <div class="dt-val">${s.eta_accuracy_pct}%</div>
            <div class="dt-label">ETA On-Time (${s.eta_on_time}/${s.eta_total})</div>
        </div>` : ''}
        <div class="dt-stat green">
            <div class="dt-val">Rs. ${Math.round(s.cash_total).toLocaleString()}</div>
            <div class="dt-label">Cash</div>
        </div>
        <div class="dt-stat blue">
            <div class="dt-val">Rs. ${Math.round(s.online_total).toLocaleString()}</div>
            <div class="dt-label">Online</div>
        </div>
        <div class="dt-stat ${s.has_verified_count > 0 ? (s.delivered_at_verified_count === s.has_verified_count ? 'green' : 'amber') : 'gray'}">
            <div class="dt-val">${s.delivered_at_verified_count}/${s.has_verified_count}</div>
            <div class="dt-label">At Verified Loc</div>
        </div>
    `;
}

function dtRenderRiders(riders) {
    const container = document.getElementById('dtRidersContainer');

    if (!riders || riders.length === 0) {
        const isToday = dtCurrentDate === new Date().toISOString().slice(0, 10);
        container.innerHTML = `<div style="padding:60px; text-align:center; color:#6b7280;"><div style="font-size:48px; margin-bottom:12px;">📭</div>${isToday ? 'No delivered orders yet today' : 'No delivered orders for this date'}</div>`;
        return;
    }

    container.innerHTML = riders.map((rider, rIdx) => {
        const compPct = rider.dispatch_compliance_pct;
        const compClass = compPct >= 80 ? 'green' : (compPct >= 50 ? 'amber' : 'red');
        const etaPct = rider.eta_accuracy_pct;
        const etaClass = etaPct !== null ? (etaPct >= 70 ? 'green' : (etaPct >= 40 ? 'amber' : 'red')) : 'gray';

        return `
        <div class="dt-rider-card" id="dtRider_${rIdx}" data-rname="${(rider.name||'').replace(/"/g,'&quot;')}">
            <div class="dt-rider-header" onclick="dtToggleRider(${rIdx})">
                <div>
                    <div class="dt-rider-name">🏍️ ${rider.name}</div>
                    <div class="dt-rider-meta">
                        ${rider.login_time ? `<span>🟢 In: ${rider.login_time}</span>` : '<span style="color:#ef4444;">⚠️ No login</span>'}
                        ${rider.logout_time ? `<span>🔴 Out: ${rider.logout_time}</span>` : '<span style="color:#9ca3af;">— Still active</span>'}
                        ${rider.first_dispatch_delay_display ? `<span>⏱️ First dispatch: ${rider.first_dispatch_delay_display} after login</span>` : ''}
                        <span>📦 ${rider.total_orders} orders</span>
                        <span>${rider.num_dispatches} dispatch${rider.num_dispatches !== 1 ? 'es' : ''}</span>
                    </div>
                </div>
                <div class="dt-rider-badges">
                    <span class="dt-badge ${compClass}">${compPct}% dispatched</span>
                    ${rider.undispatched_count > 0 ? `<span class="dt-badge red">⚠️ ${rider.undispatched_count} missed</span>` : ''}
                    ${etaPct !== null ? `<span class="dt-badge ${etaClass}">${etaPct}% on-time</span>` : ''}
                    ${rider.has_verified_count > 0 ? `<span class="dt-badge ${rider.delivered_at_verified_count === rider.has_verified_count ? 'green' : 'amber'}">📍 ${rider.delivered_at_verified_count}/${rider.has_verified_count} at verified</span>` : '<span class="dt-badge gray">📍 0 verified</span>'}
                    <span class="dt-badge green">💵 ${rider.cash_count} · Rs.${Math.round(rider.cash_total).toLocaleString()}</span>
                    <span class="dt-badge blue">💳 ${rider.online_count} · Rs.${Math.round(rider.online_total).toLocaleString()}</span>
                    <span style="font-size:16px; color:#9ca3af; transition:transform 0.2s;" id="dtChevron_${rIdx}">▶</span>
                </div>
            </div>
            <div class="dt-rider-body" id="dtBody_${rIdx}">
                <div style="padding: 6px 16px; text-align: right; border-bottom: 1px solid #f3f4f6;">
                    ${window.rrCanViewReports ? `<button onclick="event.stopPropagation(); rrOpenTimeline(${rider.id}, dtCurrentDate)" style="font-size:11px; padding:3px 10px; border:1px solid #C4D3E2; border-radius:4px; background:#EEF4FA; cursor:pointer; color:#2E64A6; margin-right:6px;">📋 Timeline</button>` : ''}
                    <button onclick="event.stopPropagation(); dtExpandAllOrders(${rIdx})" style="font-size:11px; padding:3px 10px; border:1px solid #d1d5db; border-radius:4px; background:#fff; cursor:pointer; color:#374151;">⬇ Expand All</button>
                    <button onclick="event.stopPropagation(); dtCollapseAllOrders(${rIdx})" style="font-size:11px; padding:3px 10px; border:1px solid #d1d5db; border-radius:4px; background:#fff; cursor:pointer; color:#374151; margin-left:4px;">⬆ Collapse All</button>
                </div>
                ${dtRenderBatches(rider.dispatch_batches, rider.undispatched_group, rIdx)}
            </div>
        </div>`;
    }).join('');
}

function dtToggleRider(rIdx) {
    const body = document.getElementById('dtBody_' + rIdx);
    const chevron = document.getElementById('dtChevron_' + rIdx);
    const isOpen = body.classList.contains('open');
    body.classList.toggle('open');
    chevron.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(90deg)';
}

function dtExpandAllOrders(rIdx) {
    const body = document.getElementById('dtBody_' + rIdx);
    if (!body) return;
    body.querySelectorAll('.dt-order-details').forEach(el => el.classList.add('open'));
}

function dtCollapseAllOrders(rIdx) {
    const body = document.getElementById('dtBody_' + rIdx);
    if (!body) return;
    body.querySelectorAll('.dt-order-details').forEach(el => el.classList.remove('open'));
}

function dtRenderBatches(batches, undispatchedGroup, rIdx) {
    let html = '';

    if (batches && batches.length > 0) {
        batches.forEach((batch, bIdx) => {
            const batchId = `dt_b_${rIdx}_${bIdx}`;
            const etaLabel = batch.eta_total > 0 ? `${batch.eta_on_time}/${batch.eta_total} on-time` : 'No ETAs';
            const priLabel = batch.priority_total > 0 ? `${batch.priority_match}/${batch.priority_total} in sequence` : '';
            const avgGap = batch.time_gaps.length > 0 ? Math.round(batch.time_gaps.reduce((a,b)=>a+b,0) / batch.time_gaps.length) : null;

            const durDisplay = batch.batch_duration_min >= 60
                ? `${Math.floor(batch.batch_duration_min/60)}h ${batch.batch_duration_min%60}m`
                : `${batch.batch_duration_min}m`;

            html += `
            <div class="dt-batch">
                <div class="dt-batch-header" onclick="dtToggleBatch('${batchId}')">
                    <div>
                        <span class="dt-batch-title">🚀 Dispatch at ${batch.dispatch_time_display}${batch.ofd_time_display && batch.ofd_time_display !== batch.dispatch_time_display ? ` · OFD ${batch.ofd_time_display}` : ''}</span>
                        <span style="font-size:11px; color:#6b7280; margin-left:8px;">${batch.order_count} orders</span>
                    </div>
                    <div class="dt-batch-stats">
                        ${batch.first_delivery && batch.last_delivery ? `<span class="dt-badge gray">🕐 ${batch.first_delivery} → ${batch.last_delivery} (${durDisplay})</span>` : ''}
                        <span class="dt-badge ${batch.eta_total > 0 && batch.eta_on_time === batch.eta_total ? 'green' : (batch.eta_total > 0 ? 'amber' : 'gray')}">${etaLabel}</span>
                        ${priLabel ? `<span class="dt-badge ${batch.priority_match === batch.priority_total ? 'green' : 'amber'}">📋 ${priLabel}</span>` : ''}
                        ${avgGap !== null ? `<span class="dt-badge gray">~${avgGap}m between stops</span>` : ''}
                        <span style="font-size:14px; color:#9ca3af;" id="dtBChev_${batchId}">▶</span>
                    </div>
                </div>
                <div class="dt-batch-orders" id="${batchId}">
                    ${dtRenderOrdersWithOfficeReturns(batch.orders, batch.office_returns || [], true)}
                </div>
            </div>`;
        });
    }

    if (undispatchedGroup && undispatchedGroup.order_count > 0) {
        const uBatchId = `dt_b_${rIdx}_undispatched`;
        html += `
        <div class="dt-batch">
            <div class="dt-batch-header undispatched" onclick="dtToggleBatch('${uBatchId}')">
                <div>
                    <span class="dt-batch-title">⚠️ Not Dispatched</span>
                    <span style="font-size:11px; color:#991b1b; margin-left:8px;">${undispatchedGroup.order_count} orders — dispatch button was not pressed</span>
                </div>
                <div class="dt-batch-stats">
                    <span style="font-size:14px; color:#9ca3af;" id="dtBChev_${uBatchId}">▶</span>
                </div>
            </div>
            <div class="dt-batch-orders" id="${uBatchId}">
                ${dtRenderOrders(undispatchedGroup.orders, false)}
            </div>
        </div>`;
    }

    if (!html) {
        html = '<div style="padding:16px; text-align:center; color:#6b7280; font-size:13px;">No orders</div>';
    }

    return html;
}

function dtToggleBatch(batchId) {
    const el = document.getElementById(batchId);
    const chev = document.getElementById('dtBChev_' + batchId);
    const isOpen = el.classList.contains('open');
    el.classList.toggle('open');
    if (chev) chev.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(90deg)';
}

function dtRenderOrdersWithOfficeReturns(orders, officeReturns, showDispatchInfo) {
    if (!orders || orders.length === 0) return '<div style="padding:12px 16px; color:#6b7280; font-size:12px;">No orders</div>';
    const returnMap = {};
    (officeReturns || []).forEach(r => { returnMap[r.after_order_idx] = r.visits; });

    let html = '';
    orders.forEach((o, idx) => {
        html += dtRenderSingleOrder(o, showDispatchInfo);
        if (returnMap[idx]) {
            returnMap[idx].forEach(v => {
                const durH = Math.floor(v.duration_min / 60);
                const durM = v.duration_min % 60;
                const durStr = durH > 0 ? `${durH}h ${durM}m` : `${durM}m`;
                html += `<div class="dt-order-row" style="background:#eff6ff; border-left:3px solid #3b82f6;">
                    <div class="dt-order-left" style="gap:6px;">
                        <div style="width:22px; height:22px; border-radius:50%; background:#3b82f6; color:white; font-size:10px; display:flex; align-items:center; justify-content:center;">🏢</div>
                        <span style="font-weight:600; color:#1e40af; font-size:11px;">Returned to office</span>
                    </div>
                    <div class="dt-order-right">
                        <span class="dt-time" style="color:#1e40af;">🏢 ${v.arrived} → ${v.left} (${durStr})</span>
                    </div>
                </div>`;
            });
        }
    });
    return html;
}

function dtRenderSingleOrder(o, showDispatchInfo) {
    // Use the stable dispatch-order rank (planned_sequence) from the server, NOT the
    // raw delivery_priority (which can be renumbered mid-route and collide).
    const planned = (o.planned_sequence === undefined || o.planned_sequence === null) ? null : o.planned_sequence;
    const seqMatch = planned !== null && o.actual_sequence === planned;
    const seqClass = planned !== null ? (seqMatch ? 'match' : 'mismatch') : '';
    const seqTitle = planned !== null
        ? (seqMatch ? `Planned stop #${planned} — delivered in that position` : `Planned stop #${planned} — actually delivered #${o.actual_sequence}`)
        : 'Not dispatched (no planned position)';

    let etaHtml = '';
    if (showDispatchInfo && o.eta_comparison) {
        etaHtml = `<span class="dt-time">ETA: ${o.eta_comparison.eta_display}</span>
                   <span class="${o.eta_comparison.on_time ? 'dt-eta-ok' : 'dt-eta-late'}" style="font-size:10px;">${o.eta_comparison.label}</span>`;
        // The ETA above is the one that was PROMISED. If a re-dispatch moved it
        // afterwards, say so — otherwise it reads as a time the rider never saw.
        if (o.eta_comparison.retimed) {
            etaHtml += `<span style="font-size:9px;color:#b45309;font-weight:600;" title="The delivery time was recalculated after it had been promised">↻ re-timed to ${o.eta_comparison.live_at_display}${o.eta_comparison.retimed_by_rider ? ' by rider' : ''}</span>`;
        }
    } else if (showDispatchInfo && !o.eta_comparison) {
        etaHtml = '<span class="dt-time" style="color:#d1d5db;">No ETA</span>';
    }

    const priorityHtml = planned !== null
        ? `<span class="dt-badge ${seqMatch ? 'green' : 'amber'}" style="font-size:10px; padding:1px 5px;" title="${seqTitle}">${seqMatch ? '✓ on plan' : 'plan #' + planned}</span>`
        : (showDispatchInfo ? '<span class="dt-badge gray" style="font-size:10px; padding:1px 5px;">Not dispatched</span>' : '');

    let locHtml = '';
    if (o.delivered_at_verified) {
        const distLabel = o.verification_distance_m !== null ? `${o.verification_distance_m}m` : '';
        locHtml = `<span class="dt-badge green" style="font-size:9px; padding:1px 4px;" title="Delivered at verified location (${distLabel})">📍✓</span>`;
    } else if (o.has_verified_location && !o.delivered_at_verified) {
        const distLabel = o.verification_distance_m !== null
            ? (o.verification_distance_m >= 1000 ? `${(o.verification_distance_m/1000).toFixed(1)}km away` : `${o.verification_distance_m}m away`)
            : 'no GPS';
        locHtml = `<span class="dt-badge red" style="font-size:9px; padding:1px 4px;" title="Not at verified location (${distLabel})">📍✗</span>`;
    } else {
        locHtml = `<span class="dt-badge gray" style="font-size:9px; padding:1px 4px;" title="No verified location set">📍—</span>`;
    }

    const regionHtml = o.delivery_region ? `<span><span class="dt-detail-label">Region:</span> <span class="dt-detail-value">${o.delivery_region}</span></span>` : '';
    const addressHtml = o.customer_address ? `<span><span class="dt-detail-label">Address:</span> <span class="dt-detail-value">${o.customer_address}</span></span>` : '';
    const cityHtml = o.customer_city ? `<span><span class="dt-detail-label">City:</span> <span class="dt-detail-value">${o.customer_city}</span></span>` : '';

    return `
    <div class="dt-order-row" onclick="this.querySelector('.dt-order-details').classList.toggle('open')">
        <div class="dt-order-main">
            <div class="dt-order-left">
                <div class="dt-order-seq ${seqClass}" title="${seqTitle}">${o.actual_sequence}</div>
                <span class="dt-order-num">${o.order_number}</span>
                <span class="dt-order-customer">${o.customer_name}${o.customer_city ? ' · ' + o.customer_city : ''}</span>
                ${priorityHtml}
                ${locHtml}
            </div>
            <div class="dt-order-right">
                ${o.ofd_display ? `<span class="dt-time" title="Marked out for delivery">📤 ${o.ofd_display}</span>` : ''}
                <span class="dt-time" title="Delivered at">✅ ${o.delivered_at_display}</span>
                ${etaHtml}
                <span class="dt-amount ${o.payment_type}">${o.payment_type === 'cash' ? '💵' : '💳'} Rs.${Math.round(o.amount).toLocaleString()}</span>
            </div>
        </div>
        <div class="dt-order-details">${addressHtml}${cityHtml}${regionHtml}</div>
    </div>`;
}

function dtRenderOrders(orders, showDispatchInfo) {
    if (!orders || orders.length === 0) return '<div style="padding:12px 16px; color:#6b7280; font-size:12px;">No orders</div>';
    return orders.map(o => dtRenderSingleOrder(o, showDispatchInfo)).join('');
}

// Geocode customer address
function geocodeCustomer(customerId, customerName) {
    if (!confirm(`Re-geocode address for ${customerName}?\n\nThis will update the location based on the stored address.`)) {
        return;
    }
    
    fetch(`/customers/${customerId}/geocode-single`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(`✅ Geocoded successfully!\n\nLocation: ${data.latitude}, ${data.longitude}`);
            // Refresh the map
            loadOpenOrders();
        } else {
            alert('❌ Geocoding failed: ' + (data.message || 'Address could not be geocoded'));
        }
    })
    .catch(error => {
        console.error('Error geocoding:', error);
        alert('❌ Error geocoding address');
    });
}
</script>

<script>
// =====================================================================
// RIDER REPORTS — ⚠ Issues tab + Report Card (Phase 2, Jul-2026)
// Reads /orders/riders-map/reports (real-time). Rule-based chips, no AI.
// =====================================================================
let rrCurrentDate = null;
let rrMode = 'issues';          // 'issues' (problems only) | 'cards' (all riders)
let rrData = null;
let rrCfg = { at_verified_m: 500, late_manager_minutes: 15, late_card_minutes: 10 };
let rrMapObj = null;

function rrEsc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
// U4 — home-journey manager actions from Daily Issues.
function rrHomeRider(userId){ return (window.rrRiderIndex || {})[userId]; }
async function rrHomePost(url, body){
    const res = await fetch(url, { method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').getAttribute('content')}, body:JSON.stringify(body) });
    return res.json();
}
async function rrHomeUnlock(userId){
    const r = rrHomeRider(userId); const hj = r && r.home_journey; if(!hj){ return; }
    const reason = prompt('Rider was late reaching home. Reason to unlock meter entry (required):', '');
    if(!reason) return;
    try { const j = await rrHomePost('/attendance/home-journey/unlock', { attendance_id: hj.attendance_id, reason }); alert(j.message || (j.success?'Unlocked.':'Failed.')); }
    catch(e){ alert('Could not unlock.'); }
}
async function rrHomeEnter(userId){
    const r = rrHomeRider(userId); const hj = r && r.home_journey; if(!hj){ return; }
    const meter = prompt('Enter the home meter reading for ' + (hj.rider_name||'this rider') + ' (phone-dead fallback):', '');
    if(meter===null || meter.trim()==='' || isNaN(parseInt(meter,10))) { if(meter!==null) alert('Enter a number.'); return; }
    const reason = prompt('Reason / note (optional):', '') || '';
    try { const j = await rrHomePost('/attendance/home-journey/enter-meter', { attendance_id: hj.attendance_id, meter_home: parseInt(meter,10), reason }); alert(j.message || (j.success?'Recorded.':'Failed.')); }
    catch(e){ alert('Could not record.'); }
}
function rrChip(text, sev){ // sev: crit|warn|good|info|dim
    const c = { crit:['#F6E3E1','#B3362B'], warn:['#F7EDDA','#7C5710'], good:['#E5F1E9','#2E7D4F'],
                info:['#E3ECF5','#2E64A6'], dim:['#ECEAE3','#4A5568'] }[sev] || ['#ECEAE3','#4A5568'];
    return `<span style="display:inline-block;font-size:12px;font-weight:600;padding:1px 9px;border-radius:3px;white-space:nowrap;background:${c[0]};color:${c[1]};margin:1px 3px 1px 0;">${text}</span>`;
}
function rrNum(v){ return v==null || v==='' ? null : Number(v); }
function rrDist(m){ m = rrNum(m); if(m==null) return ''; return m >= 1000 ? (m/1000).toFixed(2)+' km' : Math.round(m)+' m'; }
function rrLate(o){ const l = rrNum(o.late_minutes); return l==null ? '' : (l>0 ? l+' min late' : (l===0?'on time':(-l)+' min early')); }
// "2026-07-13 17:10:00" -> "5:10 PM" (server sends plain DATETIME strings here)
function rrHhmm(v){
    if(!v) return '';
    const d = new Date(String(v).replace(' ','T'));
    return isNaN(d.getTime()) ? '' : d.toLocaleTimeString([], {hour:'numeric', minute:'2-digit', hour12:true});
}
function rrSigned(n){ return n > 0 ? '+'+n : String(n); }
// A re-dispatch the rider made HIMSELF after already dropping stops — the press
// that moves times customers had already been promised. The server only lists
// these (store-sanctioned re-times and the day's first wave are excluded).
function rrMidRunText(c){
    const parts = ['re-timed own route · '+c.order_count+' order'+(c.order_count===1?'':'s')];
    parts.push(c.delivered_before+' already delivered');
    if(c.avg_shift_min != null && c.avg_shift_min !== 0) parts.push('~'+rrSigned(c.avg_shift_min)+' min');
    return parts.join(' · ');
}
// The store re-dispatched a route the rider HAD dispatched himself (Sep-2026) —
// told once per rider instead of "dispatch by X" on every order, which read as
// "the rider never pressed". c = one entry of r.store_redispatches.
function rrStoreText(c){
    const who = c.by_name || 'Store';
    const verb = c.kind === 'retime' ? ' re-timed ' : (c.kind === 'next' ? ' sent the next wave ' : ' changed the route ');
    const parts = ['↻ '+who+verb+rrHhmm(c.at)];
    if(c.kind === 'retime'){
        parts.push('same route');
    } else if(c.kind === 'next'){
        // new orders only — nothing was re-timed
    } else {
        const bits = [];
        if(rrNum(c.added)) bits.push(c.added+' added');
        if(rrNum(c.removed)) bits.push(c.removed+' removed');
        if(c.reordered) bits.push('re-ordered');
        if(bits.length) parts.push(bits.join(', '));
    }
    parts.push(c.order_count+' order'+(c.order_count===1?'':'s'));
    if(c.shift_min != null && c.shift_min !== 0) parts.push(rrSigned(c.shift_min)+' min');
    if(c.prior_at) parts.push((c.prior_by_self ? 'rider had dispatched ' : 'after '+(c.prior_by_name || 'store')+"'s ")+rrHhmm(c.prior_at)+(c.prior_by_self ? '' : ' dispatch'));
    const where = rrOriginText(c);
    if(where) parts.push(where);
    return parts.join(' · ');
}
// Where the times were measured from and where he was (only on presses logged
// after eta_log_origin_sep2026.sql): "timed from office — he was 13.4 km out".
function rrOriginText(c){
    const src = c.origin_source || '';
    if(!src) return '';
    const d = rrNum(c.rider_distance_m);
    const out = d != null && d > 300 ? ' — he was '+rrDist(d)+' out' : (d != null ? ' — he was at the office' : '');
    const fromOffice = /^(store|office)/.test(src);
    return (fromOffice ? 'timed from office' : 'timed from his location') + (fromOffice ? out : '');
}
// How that wave's deliveries did against the RIDER's own times (the ones the
// store replaced). {late, max, min, rated}
function rrStoreOwnLate(c, r){
    const s = {late:0, min:null, max:null, rated:0};
    if(c.kind !== 'retime') return s;   // a re-route is a new plan — judged on it alone
    (r.orders||[]).forEach(o => {
        if(o.wave !== c.batch_ts) return;
        const own = rrNum(o.own_late_minutes);
        if(own == null) return;
        s.rated++;
        if(own > rrCfg.late_manager_minutes){
            s.late++;
            s.min = s.min == null ? own : Math.min(s.min, own);
            s.max = s.max == null ? own : Math.max(s.max, own);
        }
    });
    return s;
}
function rrStoreOwnText(s){
    if(!s.rated) return '';
    if(!s.late) return 'on time vs his own times too';
    return 'vs his own times: '+s.late+' late ('+(s.min===s.max ? s.min : s.min+'–'+s.max)+' min)';
}
// Timing always visible next to other flags (a "dispatch by" chip used to hide it).
// Empty when there is no ETA, or when lateness is already flagged as its own chip.
function rrTimingChip(o){
    const l = rrNum(o.late_minutes);
    if(l == null || l > rrCfg.late_manager_minutes) return '';
    // ✓ only inside the report's on-time band; a few minutes past it is shown
    // plainly (it is below the manager's "late" flag, but it is not on time).
    return l <= (rrCfg.late_card_minutes ?? 10) ? rrChip('✓ '+rrLate(o), 'good') : rrChip(rrLate(o), 'dim');
}

function rrInit(){
    if(!rrCurrentDate) rrCurrentDate = new Date().toISOString().split('T')[0];
    const inp = document.getElementById('rrDateInput');
    inp.value = rrCurrentDate; inp.max = new Date().toISOString().split('T')[0];
    rrLoad(rrCurrentDate);
}
function rrSetToday(){ rrCurrentDate = new Date().toISOString().split('T')[0]; document.getElementById('rrDateInput').value = rrCurrentDate; rrLoad(rrCurrentDate); }
function rrPrevDay(){ const d=new Date(rrCurrentDate); d.setDate(d.getDate()-1); rrCurrentDate=d.toISOString().split('T')[0]; document.getElementById('rrDateInput').value=rrCurrentDate; rrLoad(rrCurrentDate); }
function rrNextDay(){ const d=new Date(rrCurrentDate); d.setDate(d.getDate()+1); const t=new Date().toISOString().split('T')[0]; if(d.toISOString().split('T')[0]>t) return; rrCurrentDate=d.toISOString().split('T')[0]; document.getElementById('rrDateInput').value=rrCurrentDate; rrLoad(rrCurrentDate); }
function rrToggleAll(){ rrMode = rrMode==='issues' ? 'cards' : 'issues'; document.getElementById('rrToggleLabel').textContent = rrMode==='issues' ? 'Show all riders' : 'Show issues only'; rrRender(); }

async function rrLoad(date){
    rrCurrentDate = date;
    const box = document.getElementById('rrContainer');
    box.innerHTML = '<div style="padding:40px;text-align:center;color:#6b7280;">Loading…</div>';
    try {
        const res = await fetch(`/orders/riders-map/reports?date=${date}`, {headers:{'Accept':'application/json'}});
        if(res.status === 403){ box.innerHTML = '<div style="padding:40px;text-align:center;color:#b3362b;">You do not have access to this report.</div>'; document.getElementById('rrSummaryBar').innerHTML=''; return; }
        const data = await res.json();
        if(!data.success){ box.innerHTML = '<div style="padding:40px;text-align:center;color:#b3362b;">Failed to load.</div>'; return; }
        rrData = data; rrCfg = data.config || rrCfg;
        document.getElementById('rrRealtime').textContent = data.realtime ? '● live (updates as the day runs)' : 'stored snapshot';
        rrRender();
    } catch(e){ box.innerHTML = '<div style="padding:40px;text-align:center;color:#b3362b;">Error loading report.</div>'; }
}

// ---- flag extraction (rule engine, client side over server facts) ----
function rrOrderIssues(o){
    const out = [];
    const late = rrNum(o.late_minutes);
    const hasV = rrNum(o.has_verified) === 1;
    const atV  = rrNum(o.at_verified);          // null = couldn't measure
    const gpsOk = rrNum(o.gps_ok) === 1;
    const dist = rrNum(o.pin_distance_m);
    if(rrNum(o.was_dispatched) === 0) out.push({sev:'warn', text:'dispatch not pressed'});
    // Store pressed dispatch. Only a problem when the rider had NEVER dispatched
    // these orders himself ('first', or pre-log rows with no kind). A store
    // re-time / re-route of a route he HAD dispatched is told once at rider
    // level (rrStoreText); here the order only carries "vs his own time".
    const sk = o.store_kind;
    if(rrNum(o.dispatched_by_other) === 1 && sk !== 'retime' && sk !== 'reroute' && sk !== 'next'){
        out.push({sev:'warn', text:'dispatch by '+(o.dispatched_by_name || 'someone else')+(sk==='first'?' (rider didn\'t press)':'')});
    }
    // Only for a pure re-time: after a genuine route change his old times belong
    // to a different plan, so comparing against them would blame him for it.
    const own = rrNum(o.own_late_minutes);
    if(sk === 'retime' && own != null && o.eta_pre_store){
        out.push({sev:'info', text:'his own time '+rrHhmm(o.eta_pre_store)+': '+(own>0 ? own+' min late' : (own===0?'on time':(-own)+' min early'))});
    }
    if(late != null && late > rrCfg.late_manager_minutes) out.push({sev:'warn', text: late+' min late'});
    // The delivery time moved AFTER it was promised. "X min late" above is measured
    // against the PROMISE, so without this a manager sees "42 min late" on an order
    // whose app screen said 5:40 and can't reconcile the two. Info-only on purpose:
    // it must not make a rider "an issue" by itself — re-timing while still at the
    // office moves nobody's expectations, and the case that DOES matter is the
    // rider-level "route change" row.
    if(rrNum(o.eta_retimed) === 1){
        out.push({sev:'info', text:'promised '+rrHhmm(o.eta_at)+', re-timed to '+rrHhmm(o.eta_live)
            + (rrNum(o.eta_retimed_by_rider) === 1 ? ' by rider' : '')});
    }
    // route order changed vs the planned dispatch priority (P{planned}→#{actual})
    var ps = rrNum(o.planned_seq), as = rrNum(o.actual_seq);
    if(ps != null && as != null && ps !== as) out.push({sev:'info', text:'order changed (P'+ps+'→#'+as+')'});
    // "Delivered N m from pin" now carries the fix quality that produced N. A drop stamped by a
    // +-200 m network fix reads identically to one stamped at +-8 m, and the rider gets blamed
    // either way — so when the phone told us the fix was coarse, say so on the chip.
    if(hasV && atV === 0 && dist != null){
        var facc = rrNum(o.fix_accuracy_m);
        out.push({sev: rrNum(o.fix_coarse) === 1 ? 'warn' : 'crit',
                  text:'delivered '+rrDist(dist)+' from pin'
                       + (facc != null ? ' (fix ±'+Math.round(facc)+'m'+(rrNum(o.fix_coarse) === 1 ? ', coarse' : '')+')' : ''),
                  map:true});
    }
    if(!hasV) out.push({sev:'warn', text:'no pin saved'});
    if(!gpsOk) out.push({sev:'crit', text:'GPS off at delivery'});
    return out;
}
function rrRiderIssueItems(r){
    const items = [];
    (r.orders||[]).forEach(o => {
        const iss = rrOrderIssues(o);
        // an order is a problem only if it has a real (non-info) flag; "order
        // changed" is info-only context and rides along on real problems.
        if(iss.some(x => x.sev !== 'info')) items.push({order:o, issues:iss});
    });
    // on-route stops (orders waiting) always show; idle stops only when long (≥15 min)
    const stopItems = (r.stops||[]).filter(s =>
        rrNum(s.on_board) > 0 || s.context==='on_route' || rrNum(s.min) >= 15);
    // Store re-times/re-routes/next waves: listed for a rider who is shown, as
    // context. Owner ruling (28-Sep): lateness is judged against the store's new
    // times, so "vs his own times" is information and never by itself an issue.
    const storeRows = (r.store_redispatches||[]).map(c => ({c, own: rrStoreOwnLate(c, r)}));
    return {items, stopItems, oddRoutes:(r.odd_routes||[]), missed:(r.missed_dispatch||null),
            midRuns:(r.mid_run_changes||[]), storeRows};
}
function rrHasIssues(r){
    const {items, stopItems, oddRoutes, missed, midRuns} = rrRiderIssueItems(r);
    return items.length>0 || stopItems.length>0 || oddRoutes.length>0 || !!missed || midRuns.length>0 || !!r.lateness || !!(r.checkout && r.checkout.flagged) || !!r.bike_meter || !!r.office_checkout || !!r.home_journey;
}
// short manager-friendly line for a missed-dispatch event
function rrMissedChips(m){
    const what = m.context==='returned_then_left' ? 'came back, left again without dispatch' : 'left office without dispatch';
    let res = '';
    if(m.resolution){
        if(m.resolution.state==='dispatched') res = m.resolution.by_self
            ? 'pressed himself at '+m.resolution.at
            : 'dispatched by '+rrEsc(m.resolution.by_name||'store')+' at '+m.resolution.at;
        else if(m.resolution.state==='still_undispatched') res = 'still not dispatched';
    }
    return rrChip(what+(m.left_at?' · '+m.left_at:''),'crit') + (res? ' '+rrChip(res,'dim') : '');
}

function rrRender(){
    if(!rrData){ return; }
    // build id → object indexes so click handlers pass safe ids, not inline JSON
    window.rrOrderIndex = {}; window.rrRiderIndex = {};
    (rrData.riders||[]).forEach(r => {
        window.rrRiderIndex[r.user_id] = r;
        (r.orders||[]).forEach(o => { window.rrOrderIndex[o.order_id] = o; });
    });
    if(rrMode==='cards') return rrRenderCards();
    return rrRenderIssues();
}

// ---- Manager Issues: problems only, real riders ----
function rrRenderIssues(){
    const box = document.getElementById('rrContainer');
    const riders = (rrData.riders||[]).filter(r => r.is_rider);
    const flagged = riders.filter(rrHasIssues);
    const clean = riders.length - flagged.length;
    const delivered = riders.reduce((a,r)=>a+(r.day.delivered_count||0),0);
    const hidden = (rrData.riders||[]).length - riders.length;

    document.getElementById('rrSummaryBar').innerHTML =
        `<div style="padding:10px 14px;">${rrChip(delivered+' delivered','dim')} ${rrChip(flagged.length+' riders flagged', flagged.length?'warn':'good')} ${rrChip(clean+' clean','good')}${hidden?' '+rrChip(hidden+' office account'+(hidden>1?'s':'')+' hidden','dim'):''}</div>`;

    if(!flagged.length){ box.innerHTML = '<div style="padding:50px;text-align:center;color:#2E7D4F;font-weight:600;">✓ No issues for this date — everyone on time and at the pin.</div>'; return; }

    // red first (any crit item), then amber
    flagged.sort((a,b)=> rrSeverityRank(b) - rrSeverityRank(a));

    box.innerHTML = flagged.map(r => {
        const {items, stopItems, oddRoutes, missed, midRuns, storeRows} = rrRiderIssueItems(r);
        const missedHtml = missed ? `<div style="display:flex;gap:8px;align-items:baseline;flex-wrap:wrap;font-size:13px;padding:2px 0;">
                    <span style="min-width:130px;color:#6b7280;">dispatch</span>${rrMissedChips(missed)}
                </div>` : '';
        const midRunHtml = midRuns.map(c => `<div style="display:flex;gap:8px;align-items:baseline;flex-wrap:wrap;font-size:13px;padding:2px 0;">
                    <span style="min-width:130px;color:#6b7280;">route change</span>
                    ${rrChip(rrMidRunText(c),'warn')}
                    <span style="font-family:monospace;font-size:12px;color:#6b7280;">${rrHhmm(c.at)}</span>
                </div>`).join('');
        const storeHtml = storeRows.map(({c, own}) => {
            const ownTxt = rrStoreOwnText(own);
            return `<div style="display:flex;gap:8px;align-items:baseline;flex-wrap:wrap;font-size:13px;padding:2px 0;">
                    <span style="min-width:130px;color:#6b7280;">re-dispatch</span>
                    ${rrChip(rrEsc(rrStoreText(c)),'info')}
                    ${ownTxt ? rrChip(ownTxt, own.late ? 'dim' : 'good') : ''}
                </div>`;
        }).join('');
        const rowsHtml = items.map(it => {
            const o = it.order;
            const chips = rrTimingChip(o) + ' ' + it.issues.map(x => rrChip(x.text, x.sev) + (x.map ? ` <a href="#" onclick="rrOpenMap(${o.order_id});return false;" style="font-size:12px;color:#2E64A6;text-decoration:none;">🗺️ verified vs pressed</a>` : '')).join(' ');
            return `<div style="display:flex;gap:8px;align-items:baseline;flex-wrap:wrap;font-size:13px;padding:2px 0;">
                        <b style="min-width:130px;">${rrEsc(o.customer_name||'—')}</b>
                        <span style="font-family:monospace;font-size:11.5px;color:#2E64A6;min-width:70px;">${rrEsc(o.order_number||'')}</span>
                        ${chips}
                    </div>`;
        }).join('');
        const stopsHtml = stopItems.map(s => {
            const g = s.context==='on_route' || rrNum(s.on_board) > 0;
            const label = g ? 'stop '+s.min+' min — '+(rrNum(s.on_board)||'')+' orders waiting'
                            : 'stop '+s.min+' min — nothing on board';
            return `<div style="display:flex;gap:8px;align-items:baseline;flex-wrap:wrap;font-size:13px;padding:2px 0;">
                        <span style="min-width:130px;color:#6b7280;">${g?'on-route stop':'idle stop'}</span>
                        ${rrChip(label, g?'crit':'dim')}
                        <span style="font-family:monospace;font-size:12px;color:#6b7280;">${s.from}–${s.to}</span>
                        ${s.map_url?`<a href="${s.map_url}" target="_blank" style="font-size:12px;color:#2E64A6;text-decoration:none;">📍 map</a>`:''}
                    </div>`;
        }).join('');
        const oddHtml = oddRoutes.map(l => `<div style="font-size:13px;padding:2px 0;">${rrChip('odd route '+l.trav_km+' km for '+l.straight_km+' km','warn')}<span style="color:#6b7280;font-size:12px;"> ${rrEsc(l.from)} → ${rrEsc(l.to)}</span></div>`).join('');
        const co = r.checkout;
        const checkoutHtml = (co && co.flagged) ? `<div style="display:flex;gap:8px;align-items:baseline;flex-wrap:wrap;font-size:13px;padding:2px 0;">
                    <span style="min-width:130px;color:#6b7280;">checkout</span>
                    ${rrChip('checked out '+co.pin_distance_m+' m from '+rrEsc(co.customer_name||'address')+"'s saved pin",'warn')}
                    <span style="font-family:monospace;font-size:11.5px;color:#2E64A6;">${rrEsc(co.order_number||'')}</span>
                    <span style="color:#6b7280;font-size:12px;">at ${co.logout_time||''}</span>
                </div>` : '';
        // Company-bike meter issues (info only — no action): overnight grace and/or no reading.
        const bm = r.bike_meter;
        let bikeGraceHtml = '';
        if (bm && bm.grace) {
            const g = bm.grace;
            bikeGraceHtml += `<div style="display:flex;gap:8px;align-items:baseline;flex-wrap:wrap;font-size:13px;padding:2px 0;">
                    <span style="min-width:130px;color:#6b7280;">company bike</span>
                    ${rrChip('🏍 '+g.overnight_km+' km overnight (grace '+g.grace_km+' km)','warn')}
                    <span style="color:#6b7280;font-size:12px;">start ${g.meter_start} − ${g.prev_label ? g.prev_label + "'s last reading" : 'prev end'} ${g.prev_meter_end}${g.prev_date?' ('+g.prev_date+')':''}</span>
                </div>`;
        }
        if (bm && bm.no_meter) {
            const which = bm.no_meter.missing === 'both' ? 'start & end' : bm.no_meter.missing;
            bikeGraceHtml += `<div style="display:flex;gap:8px;align-items:baseline;flex-wrap:wrap;font-size:13px;padding:2px 0;">
                    <span style="min-width:130px;color:#6b7280;">company bike</span>
                    ${rrChip('⛽ no meter reading ('+which+' missing)','warn')}
                    <span style="color:#6b7280;font-size:12px;">bike km can't be tracked</span>
                </div>`;
        }
        // Office-checkout exception (R5): non-bike rider who delivered but came back to the office.
        const oc = r.office_checkout;
        let officeCheckoutHtml = '';
        if (oc) {
            const after = (oc.minutes_after != null) ? (oc.minutes_after + ' min after his last delivery') : 'after delivering';
            officeCheckoutHtml = `<div style="display:flex;gap:8px;align-items:baseline;flex-wrap:wrap;font-size:13px;padding:2px 0;">
                    <span style="min-width:130px;color:#6b7280;">checkout</span>
                    ${rrChip('🏢 checked out at the office ('+after+')','warn')}
                    <span style="color:#6b7280;font-size:12px;">out ${oc.checkout_time||''} · last delivery ${oc.last_delivery_time||''} to ${rrEsc(oc.last_customer||'')}</span>
                </div>`;
        }
        // Company-bike going-home issue (U4): late / locked / unlocked / completed-late.
        const hj = r.home_journey;
        let homeHtml = '';
        if (hj) {
            const lateTxt = (hj.minutes_late != null && hj.minutes_late > 0) ? ('+' + hj.minutes_late + ' min') : 'late';
            let chipTxt, chipSev = 'warn';
            if (hj.state === 'late_locked') { chipTxt = '🏠 late ' + lateTxt + ' — meter locked'; chipSev = 'crit'; }
            else if (hj.state === 'unlocked') { chipTxt = '🏠 unlocked — awaiting meter'; }
            else { chipTxt = '🏠 home late ' + lateTxt + (hj.reason ? ' · excused: ' + rrEsc(hj.reason) : ''); }
            const ctx = 'out ' + (hj.checkout_time || '?') + ' · home ' + (hj.distance_km != null ? hj.distance_km + ' km' : '?') + ' · due ' + (hj.expected_by || '?') + (hj.arrived_at ? ' · home ' + hj.arrived_at : '') + (hj.unlocked_by ? ' · unlocked by ' + rrEsc(hj.unlocked_by) : '');
            const actions = (hj.state === 'late_locked' || hj.state === 'unlocked')
                ? `<a href="#" onclick="rrHomeUnlock(${r.user_id});return false;" style="font-size:11px;color:#B45309;border:1px solid #FDE68A;background:#FEF3C7;border-radius:5px;padding:1px 8px;text-decoration:none;">🔓 Unlock (rider enters)</a>
                   <a href="#" onclick="rrHomeEnter(${r.user_id});return false;" style="font-size:11px;color:#0f766e;border:1px solid #99f6e4;background:#f0fdfa;border-radius:5px;padding:1px 8px;text-decoration:none;margin-left:6px;">✍ Enter meter</a>` : '';
            homeHtml = `<div style="display:flex;gap:8px;align-items:baseline;flex-wrap:wrap;font-size:13px;padding:2px 0;">
                    <span style="min-width:130px;color:#6b7280;">going home</span>
                    ${rrChip(chipTxt, chipSev)}
                    <span style="color:#6b7280;font-size:12px;">${ctx}</span>
                    ${actions ? `<span style="margin-left:auto;">${actions}</span>` : ''}
                </div>`;
        }
        const sev = rrSeverityRank(r) >= 2 ? 'r' : 'a';
        return `<div style="padding:10px 14px;border-bottom:1px solid #eef;">
                    <div style="font-weight:650;font-size:14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${sev==='r'?'#B3362B':'#A8730F'};"></span>
                        ${rrEsc(r.rider_name)}
                        ${r.lateness ? rrChip('late '+r.lateness.today_min+' min today · '+r.lateness.month_min+' min this month'+((r.lateness.month_waived_min||0)>0?' · '+r.lateness.month_waived_min+' min waived':''),'warn') : ''}
                        <span style="margin-left:auto; display:flex; gap:6px;">
                            <a href="#" onclick="rrOpenTimeline(${r.user_id});return false;" style="font-size:12px;color:#2E64A6;text-decoration:none;border:1px solid #C4D3E2;border-radius:4px;padding:1px 8px;background:#EEF4FA;">📋 timeline</a>
                            <a href="#" onclick="openDispatchDetail(${r.user_id});return false;" style="font-size:12px;color:#2E64A6;text-decoration:none;border:1px solid #C4D3E2;border-radius:4px;padding:1px 8px;background:#EEF4FA;">🚀 dispatch detail</a>
                        </span>
                    </div>
                    <div style="margin-top:5px;">${missedHtml}${midRunHtml}${storeHtml}${rowsHtml}${stopsHtml}${oddHtml}${checkoutHtml}${bikeGraceHtml}${officeCheckoutHtml}${homeHtml}</div>
                </div>`;
    }).join('');

    if(clean>0){
        const names = riders.filter(r=>!rrHasIssues(r)).map(r=>rrEsc(r.rider_name)).join(', ');
        box.innerHTML += `<div style="padding:10px 14px;background:#FAF8F2;color:#2E7D4F;font-size:13px;">✓ Clean, not shown: ${names}</div>`;
    }
}
function rrSeverityRank(r){
    let rank = 0;
    if(r.missed_dispatch) rank = 2;
    if(r.lateness) rank = Math.max(rank, 1);
    (r.orders||[]).forEach(o => { rrOrderIssues(o).forEach(x => { if(x.sev==='crit') rank=Math.max(rank,2); else if(x.sev!=='info') rank=Math.max(rank,1); }); });
    (r.stops||[]).forEach(s => { if(rrNum(s.on_board) > 0) rank=Math.max(rank,2); });
    if((r.odd_routes||[]).length) rank=Math.max(rank,1);
    if((r.mid_run_changes||[]).length) rank=Math.max(rank,1);
    if(r.checkout && r.checkout.flagged) rank=Math.max(rank,1);
    if(r.bike_meter) rank=Math.max(rank,1);
    if(r.office_checkout) rank=Math.max(rank,1);
    if(r.home_journey) rank=Math.max(rank, r.home_journey.state==='late_locked'?2:1);
    return rank;
}

// ---- Report Card: every rider, all orders ----
function rrRenderCards(){
    const box = document.getElementById('rrContainer');
    const riders = (rrData.riders||[]).filter(r => r.is_rider);
    document.getElementById('rrSummaryBar').innerHTML = `<div style="padding:10px 14px;">${rrChip(riders.length+' riders','dim')} ${rrChip('report cards','info')}</div>`;
    box.innerHTML = riders.map(r => {
        const d = r.day;
        const summary = [
            rrChip((d.ontime_count||0)+'/'+(d.eta_total||0)+' on time', (rrNum(d.ontime_count)===rrNum(d.eta_total))?'good':'warn'),
            rrChip((d.at_verified_count||0)+'/'+(d.has_verified_count||0)+' at pin', (rrNum(d.at_verified_count)===rrNum(d.has_verified_count))?'good':'warn'),
            (rrNum(d.onroute_stop_count)? rrChip(d.onroute_stop_count+' stop w/ orders waiting','crit'):''),
            (rrNum(d.odd_route_count)? rrChip(d.odd_route_count+' odd route','warn'):''),
            (rrNum(d.gps_off_count)? rrChip(d.gps_off_count+' GPS off','warn'):'')
        ].join(' ');
        const rows = (r.orders||[]).map(o => {
            const iss = rrOrderIssues(o);
            const real = iss.filter(x=>x.sev!=='info'), info = iss.filter(x=>x.sev==='info');
            const chips = (real.length ? rrTimingChip(o)+' '+real.map(x=>rrChip(x.text,x.sev)).join(' ') : (rrTimingChip(o) || rrChip('✓ ok','good'))) + ' ' + info.map(x=>rrChip(x.text,x.sev)).join(' ');
            return `<div style="display:flex;gap:8px;align-items:baseline;flex-wrap:wrap;font-size:13px;padding:4px 0;border-bottom:1px solid #f3f4f6;">
                        <span style="font-family:monospace;font-size:11.5px;color:#2E64A6;min-width:74px;">${rrEsc(o.order_number)}</span>
                        <b style="min-width:130px;">${rrEsc(o.customer_name||'—')}</b>
                        ${chips}
                    </div>`;
        }).join('');
        return `<div style="border:1px solid #e5e7eb;border-radius:8px;margin:10px 14px;overflow:hidden;">
                    <div style="background:#22303F;color:#fff;padding:8px 14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <b>🏍️ ${rrEsc(r.rider_name)}</b>
                        <span style="font-family:monospace;font-size:12px;color:#C9D4DF;">${d.delivered_count||0} delivered</span>
                        <a href="#" onclick="openDispatchDetail(${r.user_id});return false;" style="margin-left:auto;font-size:12px;color:#EEF4FA;text-decoration:none;">🚀 dispatch detail</a>
                    </div>
                    <div style="padding:8px 14px;background:#FAF8F2;">${summary}</div>
                    <div style="padding:4px 14px 8px;">${rows}</div>
                </div>`;
    }).join('');
}

// ---- verified-vs-pressed map modal ----
function rrOpenMap(orderId){
    const o = (window.rrOrderIndex || {})[orderId];
    if(!o || o.pin_lat==null || o.verified_lat==null){ alert('Missing GPS for this order.'); return; }
    const modal = document.getElementById('rrMapModal');
    modal.style.display = 'flex';
    document.getElementById('rrMapTitle').textContent = (o.customer_name||'Order')+' · '+(o.order_number||'');
    document.getElementById('rrMapDist').textContent = rrDist(o.pin_distance_m)+' apart';
    document.getElementById('rrMapGmaps').href = `https://www.google.com/maps/dir/${o.verified_lat},${o.verified_lng}/${o.pin_lat},${o.pin_lng}`;
    setTimeout(() => {
        if(rrMapObj){ rrMapObj.remove(); rrMapObj = null; }
        rrMapObj = L.map('rrMap');
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom:19, attribution:'© OpenStreetMap'}).addTo(rrMapObj);
        const vp = [parseFloat(o.verified_lat), parseFloat(o.verified_lng)];
        const pp = [parseFloat(o.pin_lat), parseFloat(o.pin_lng)];
        const green = L.circleMarker(vp, {radius:9, color:'#fff', weight:2, fillColor:'#2E7D4F', fillOpacity:1}).addTo(rrMapObj).bindPopup("Customer's saved pin");
        const red = L.circleMarker(pp, {radius:9, color:'#fff', weight:2, fillColor:'#B3362B', fillOpacity:1}).addTo(rrMapObj).bindPopup('Pressed "Delivered" here');
        L.polyline([vp, pp], {color:'#B3362B', weight:2, dashArray:'5,6'}).addTo(rrMapObj);
        rrMapObj.fitBounds(L.latLngBounds([vp, pp]).pad(0.4));
    }, 60);
}
function rrCloseMap(){ document.getElementById('rrMapModal').style.display = 'none'; if(rrMapObj){ rrMapObj.remove(); rrMapObj=null; } }

// ---- per-rider day Timeline ----
window.rrCanViewReports = {{ ($canViewRiderReports ?? false) ? 'true' : 'false' }};
async function rrOpenTimeline(userId, date){
    date = date || rrCurrentDate || new Date().toISOString().split('T')[0];
    const modal = document.getElementById('rrTimelineModal');
    modal.style.display = 'flex';
    document.getElementById('rrTlTitle').textContent = '📋 Timeline';
    document.getElementById('rrTlSub').textContent = '';
    document.getElementById('rrTlBody').innerHTML = '<div style="padding:34px;text-align:center;color:#6b7280;">Loading…</div>';
    try {
        const res = await fetch(`/orders/riders-map/timeline?date=${date}&rider=${userId}`, {headers:{'Accept':'application/json'}});
        if(res.status===403){ document.getElementById('rrTlBody').innerHTML = '<div style="padding:34px;text-align:center;color:#b3362b;">No access.</div>'; return; }
        const data = await res.json();
        if(!data.success){ document.getElementById('rrTlBody').innerHTML = '<div style="padding:34px;text-align:center;color:#b3362b;">Failed to load.</div>'; return; }
        rrCfg = data.config || rrCfg;
        window.rrOrderIndex = window.rrOrderIndex || {};
        (data.events||[]).forEach(e => { if(e.kind==='delivery' && e.order) window.rrOrderIndex[e.order.order_id] = e.order; });
        document.getElementById('rrTlTitle').textContent = '📋 ' + rrEsc(data.rider_name||'Rider');
        document.getElementById('rrTlSub').textContent = new Date(data.date).toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric'});
        document.getElementById('rrTlBody').innerHTML = rrRenderTimeline(data.events||[]);
    } catch(e){ document.getElementById('rrTlBody').innerHTML = '<div style="padding:34px;text-align:center;color:#b3362b;">Error loading timeline.</div>'; }
}
function rrCloseTimeline(){ document.getElementById('rrTimelineModal').style.display = 'none'; }
function rrRenderTimeline(events){
    if(!events.length) return '<div style="padding:34px;text-align:center;color:#6b7280;">No activity for this day.</div>';
    return `<div style="border-left:2px solid #e5e7eb; margin-left:58px;">` + events.map(e => {
        let title='', chips='', color='#9ca3af';
        if(e.kind==='delivery'){
            const o=e.order, iss=rrOrderIssues(o);
            const real=iss.filter(x=>x.sev!=='info'), info=iss.filter(x=>x.sev==='info');
            chips = (real.length ? rrTimingChip(o)+' '+real.map(x=>rrChip(x.text,x.sev)+(x.map?` <a href="#" onclick="rrOpenMap(${o.order_id});return false;" style="font-size:11px;color:#2E64A6;text-decoration:none;">🗺️</a>`:'')).join(' ')
                                 : (rrTimingChip(o) || rrChip('✓ ok','good'))) + ' ' + info.map(x=>rrChip(x.text,x.sev)).join(' ');
            color = real.length ? (real.some(x=>x.sev==='crit')?'#B3362B':'#A8730F') : '#2E7D4F';            title = `<b>${rrEsc(o.customer_name||'—')}</b> <span style="font-family:monospace;font-size:11px;color:#2E64A6;">${rrEsc(o.order_number)}</span>`;
        } else if(e.kind==='stop'){
            const known = rrNum(e.is_unknown)!==1;
            const onRoute = e.context==='on_route' || rrNum(e.on_board)>0;
            color = onRoute ? '#B3362B' : (known ? '#6b7280' : '#A8730F');
            title = known ? 'At '+rrEsc(e.label) : (onRoute ? 'Stop — '+(rrNum(e.on_board)||'')+' orders waiting' : 'Stop — nothing on board');
            chips = rrChip(e.min+' min · '+e.time+'–'+e.to, 'dim') + (e.map_url && !known ? ` <a href="${e.map_url}" target="_blank" style="font-size:11px;color:#2E64A6;text-decoration:none;">📍 map</a>` : '');
        } else if(e.kind==='gap'){
            color='#A8730F'; title='GPS gap'; chips=rrChip(e.min+' min · no signal','warn');
        } else if(e.kind==='checkin'){
            color='#2E7D4F'; title='Check-in';
            chips = e.remote ? rrChip('remote'+(e.dist_m?' · '+rrDist(e.dist_m)+' from base':''),'warn') : rrChip('at base','dim');
        } else if(e.kind==='checkout'){
            color='#B3362B'; title='Check-out'; chips='';
        } else if(e.kind==='dispatch'){
            // Who pressed, and what it was — so a store re-time never reads as
            // the rider forgetting (Sep-2026).
            const n = e.order_count+' order'+(e.order_count===1?'':'s');
            const who = rrEsc(e.by_name || 'store');
            color = '#2E64A6';
            if(e.by_self){
                title = '🚀 Dispatched — '+n;
                chips = rrChip('pressed by the rider','dim');
            } else {
                const sk = e.dispatch_kind;
                if(sk==='retime'){
                    title = '↻ '+who+' re-timed the route — '+n;
                    chips = rrChip('same route','info') + (e.shift_min ? ' '+rrChip(rrSigned(e.shift_min)+' min','info') : '');
                } else if(sk==='next'){
                    title = '↻ '+who+' sent the next wave — '+n;
                    chips = rrChip('new orders, rider had dispatched his own wave','dim');
                } else if(sk==='reroute'){
                    title = '↻ '+who+' changed the route — '+n;
                    const bits = [];
                    if(rrNum(e.added)) bits.push(e.added+' added');
                    if(rrNum(e.removed)) bits.push(e.removed+' removed');
                    if(e.reordered) bits.push('re-ordered');
                    chips = bits.length ? rrChip(bits.join(', '),'info') : '';
                } else {
                    title = '🚀 Dispatched by '+who+' — '+n;
                    chips = rrChip("rider didn't press",'warn');
                }
                if(e.after_cancel) chips += ' '+rrChip('cleared & re-dispatched','dim');
            }
            const where = rrOriginText(e);
            if(where) chips += ' '+rrChip(rrEsc(where),'dim');
        } else if(e.kind==='cancel'){
            color = '#6b7280';
            title = '✖ Times cleared'+(e.by_self ? ' by the rider' : ' by '+rrEsc(e.by_name || 'store'))+' — '+e.order_count+' order'+(e.order_count===1?'':'s');
            chips = '';
        }
        return `<div style="position:relative; padding:7px 0 7px 24px;">
            <span style="position:absolute; left:-7px; top:10px; width:11px; height:11px; border-radius:50%; background:${color}; border:2px solid #fff;"></span>
            <span style="position:absolute; left:-58px; top:8px; width:44px; text-align:right; font-family:monospace; font-size:12px; color:#6b7280;">${e.time}</span>
            <div style="font-size:13.5px;">${title}</div>
            ${chips ? `<div style="margin-top:3px;">${chips}</div>` : ''}
        </div>`;
    }).join('') + `</div>`;
}

// ---- drill-down: open the existing Dispatch Tracker for this rider+date ----
function openDispatchDetail(userId){
    const r = (window.rrRiderIndex || {})[userId];
    if(!r) return;
    const riderName = r.rider_name;
    dtCurrentDate = rrCurrentDate;
    switchView('dispatch');
    let tries = 0;
    const iv = setInterval(() => {
        tries++;
        const card = document.querySelector(`#dtRidersContainer .dt-rider-card[data-rname="${(riderName||'').replace(/"/g,'&quot;')}"]`);
        if(card){
            clearInterval(iv);
            const body = card.querySelector('.dt-rider-body');
            if(body && !body.classList.contains('open')) body.classList.add('open');
            card.scrollIntoView({behavior:'smooth', block:'start'});
            card.style.boxShadow = '0 0 0 3px #f59e0b'; setTimeout(()=>{ card.style.boxShadow=''; }, 2500);
        }
        if(tries > 40) clearInterval(iv);
    }, 150);
}
</script>

{{-- 🛢 Service-due banners. ⚠ INSIDE the section — anything after @endsection is
     never rendered. The Bikes page is exactly where a manager can act on them. --}}
@include('partials.service-alerts')
@include('partials.vehicle-ticket-alerts')
@include('partials.workshop-alerts')
@include('partials.shift-approval-alerts')
@endsection
