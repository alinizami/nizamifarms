{{-- resources/views/pages/roles/permissions.blade.php
     Phase 3 (Jul-2026) redesign. Groups mirror the sidebar; only the 6 enforced keys
     are surfaced as normal controls, the dead keys move to a collapsed "Legacy" drawer
     (still submitted, so a save stays byte-identical), the web_menu_* restricted-menu
     keys get their first real UI (amber card), and manage_asset_categories is added.
     Colors live in a scoped <style> (hex) because the app's Tailwind color utilities
     are purged — structural utilities only from Tailwind. Form field names, action,
     method and the BU section are unchanged. --}}

@extends('layouts.app')

@section('title', 'Role Access — Web')

@section('content')
<style>
  .nfp-wrap { max-width: 920px; margin: 0 auto; padding: 24px 16px 120px; color: #111827; }
  .nfp-head { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 6px; }
  .nfp-ava { width: 44px; height: 44px; border-radius: 11px; background: #EFF6FF; color: #1D4ED8; display: grid; place-items: center; font-weight: 800; font-size: 16px; flex: none; }
  .nfp-title { font-size: 20px; font-weight: 750; margin: 0; }
  .nfp-sub { font-size: 12.5px; color: #6B7280; margin: 2px 0 0; }
  .nfp-head-actions { margin-left: auto; display: flex; gap: 8px; flex-wrap: wrap; }
  .nfp-btn { appearance: none; cursor: pointer; border: 1px solid #D1D5DB; background: #fff; color: #374151; border-radius: 8px; padding: 8px 14px; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; }
  .nfp-btn:hover { background: #F9FAFB; }

  .nfp-tabs { display: inline-flex; gap: 2px; background: #F3F4F6; border: 1px solid #E5E7EB; border-radius: 9px; padding: 3px; margin: 14px 0 4px; }
  .nfp-tab { appearance: none; border: 0; cursor: pointer; font-size: 13px; font-weight: 600; color: #4B5563; background: transparent; padding: 7px 16px; border-radius: 7px; text-decoration: none; }
  .nfp-tab.is-on { background: #fff; color: #1D4ED8; box-shadow: 0 1px 2px rgba(0,0,0,.08); }

  .nfp-authbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 12px 0 2px; padding: 10px 12px; background: #F9FAFB; border: 1px solid #EEF1F5; border-radius: 10px; }
  .nfp-authlbl { font-size: 10.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #9CA3AF; }
  .nfp-authchip { appearance: none; cursor: pointer; border: 1px solid #D1D5DB; background: #fff; color: #4B5563; border-radius: 20px; padding: 6px 14px; font-size: 12.5px; font-weight: 650; display: inline-flex; align-items: center; gap: 7px; }
  .nfp-authchip::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: #CBD5E1; flex: none; }
  .nfp-authchip[aria-pressed="true"] { border-color: #059669; background: #ECFDF5; color: #059669; }
  .nfp-authchip[aria-pressed="true"]::before { background: #059669; }
  .nfp-authchip:disabled { opacity: .6; cursor: default; }
  .nfp-authhint { font-size: 11px; color: #9CA3AF; margin-left: auto; }

  .nfp-legend { display: flex; flex-wrap: wrap; gap: 8px 18px; font-size: 11.5px; color: #6B7280; margin: 12px 0 4px; }
  .nfp-legend b { color: #374151; }

  .nfp-tools { position: sticky; top: 0; z-index: 5; background: #fff; padding: 10px 0; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; border-bottom: 1px solid #EEF1F5; }
  .nfp-find { flex: 1; min-width: 200px; display: flex; align-items: center; gap: 8px; border: 1px solid #E5E7EB; border-radius: 9px; padding: 9px 12px; background: #F9FAFB; }
  .nfp-find input { border: 0; outline: 0; background: transparent; font-size: 13px; width: 100%; color: #111827; }
  .nfp-changes { font-size: 12px; color: #D97706; font-weight: 650; display: none; }
  .nfp-changes.is-on { display: inline; }

  .nfp-group { padding: 18px 0 4px; border-bottom: 1px solid #F1F3F6; }
  .nfp-group > h3 { margin: 0 0 2px; font-size: 12px; font-weight: 750; letter-spacing: .05em; text-transform: uppercase; color: #111827; display: flex; align-items: center; gap: 8px; }
  .nfp-group-tag { font-size: 9.5px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; color: #1D4ED8; background: #EFF6FF; padding: 2px 8px; border-radius: 20px; }
  .nfp-ghint { margin: 0 0 10px; font-size: 11.5px; color: #9CA3AF; }

  .nfp-row { display: flex; align-items: center; gap: 14px; padding: 9px 10px; border-radius: 10px; }
  .nfp-row:hover { background: #F9FAFB; }
  .nfp-row .meta { flex: 1; min-width: 0; }
  .nfp-row .name { font-size: 13.5px; font-weight: 600; color: #111827; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .nfp-row .name code { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 11px; color: #9CA3AF; font-weight: 500; }
  .nfp-row .help { font-size: 11.5px; color: #9CA3AF; margin-top: 1px; }
  .nfp-row.legacy .name { color: #9CA3AF; font-weight: 500; }

  input.nfp-cb { width: 18px; height: 18px; flex: none; accent-color: #2563EB; cursor: pointer; }
  input.nfp-cb.on { accent-color: #059669; }

  .nfp-tag { font-size: 9.5px; font-weight: 800; letter-spacing: .03em; text-transform: uppercase; padding: 2px 7px; border-radius: 20px; }
  .nfp-tag.enforced { color: #059669; background: #ECFDF5; }
  .nfp-tag.new { color: #7C3AED; background: #F5F3FF; }
  .nfp-tag.dead { color: #6B7280; background: #F3F4F6; border: 1px solid #E5E7EB; }

  .nfp-tier { display: inline-flex; background: #F3F4F6; border: 1px solid #E5E7EB; border-radius: 9px; padding: 3px; gap: 2px; flex: none; }
  .nfp-tier button { appearance: none; border: 0; cursor: pointer; font-size: 11.5px; font-weight: 650; color: #6B7280; background: transparent; padding: 6px 12px; border-radius: 6px; }
  .nfp-tier button:hover { color: #374151; }
  .nfp-tier button[aria-pressed="true"] { background: #fff; color: #1D4ED8; box-shadow: 0 1px 2px rgba(0,0,0,.1); }
  .nfp-tier button.off[aria-pressed="true"] { color: #6B7280; }

  .nfp-warncard { margin: 16px 0 6px; border: 1px solid #F59E0B; border-radius: 12px; background: #FFFBEB; padding: 16px 18px; }
  .nfp-warncard h3 { margin: 0 0 4px; font-size: 13px; font-weight: 750; color: #B45309; display: flex; align-items: center; gap: 8px; }
  .nfp-warncard p { margin: 0 0 12px; font-size: 12px; color: #6B7280; }
  .nfp-wrow { display: flex; align-items: center; gap: 10px; padding: 7px 4px; font-size: 13px; color: #374151; }
  .nfp-wrow input { width: 16px; height: 16px; accent-color: #D97706; flex: none; }
  .nfp-wrow code { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 11px; color: #9CA3AF; margin-left: auto; }

  details.nfp-legacydraw { margin: 12px 0 4px; border: 1px dashed #D1D5DB; border-radius: 12px; overflow: hidden; }
  details.nfp-legacydraw > summary { cursor: pointer; list-style: none; padding: 12px 16px; background: #F9FAFB; color: #4B5563; font-size: 12.5px; font-weight: 650; display: flex; align-items: center; gap: 8px; }
  details.nfp-legacydraw > summary::-webkit-details-marker { display: none; }
  details.nfp-legacydraw > summary .chev { transition: transform .18s; }
  details.nfp-legacydraw[open] > summary .chev { transform: rotate(90deg); }
  .nfp-legacybody { padding: 6px 12px 14px; }
  .nfp-legacybody .note { font-size: 11.5px; color: #6B7280; padding: 4px 6px 8px; }

  .nfp-bucard { margin: 18px 0 6px; border: 1px solid #E5E7EB; border-radius: 12px; padding: 16px 18px; background: #FAFAFB; }
  .nfp-bucard h3 { margin: 0 0 4px; font-size: 13px; font-weight: 750; color: #111827; }
  .nfp-bucard p.hint { margin: 0 0 12px; font-size: 12px; color: #6B7280; }
  .nfp-bucard label.fld { display: block; font-size: 12px; font-weight: 650; color: #374151; margin: 10px 0 4px; }
  .nfp-bucard select { width: 100%; padding: 8px 10px; border: 1px solid #D1D5DB; border-radius: 8px; background: #fff; font-size: 13px; color: #111827; }
  .nfp-bucard .bu-list { background: #fff; border: 1px solid #E5E7EB; border-radius: 8px; padding: 8px; }
  .nfp-bucard .bu-list label { display: flex; align-items: center; gap: 10px; padding: 6px 6px; font-size: 13px; }

  .nfp-savebar { position: fixed; left: 0; right: 0; bottom: 0; z-index: 20; background: #fff; border-top: 1px solid #E5E7EB; box-shadow: 0 -4px 16px -8px rgba(0,0,0,.2); padding: 12px 16px; display: flex; align-items: center; gap: 12px; }
  .nfp-savebar .inner { max-width: 920px; margin: 0 auto; width: 100%; display: flex; align-items: center; gap: 12px; }
  .nfp-save { appearance: none; border: 0; cursor: pointer; background: #2563EB; color: #fff; font-weight: 700; font-size: 13.5px; padding: 10px 20px; border-radius: 9px; margin-left: auto; }
  .nfp-save:hover { background: #1D4ED8; }
</style>

<div class="nfp-wrap">
    <div class="nfp-head">
        <div class="nfp-ava">{{ strtoupper(substr($role->urole_name ?? 'R', 0, 2)) }}</div>
        <div>
            <h2 class="nfp-title">{{ $role->urole_name }}</h2>
            <p class="nfp-sub">Role Access · type <strong>{{ ucfirst($role->type) }}</strong>
               @isset($roleUserCount) · {{ $roleUserCount }} user{{ $roleUserCount == 1 ? '' : 's' }}@endisset</p>
        </div>
        <div class="nfp-head-actions">
            <button type="button" class="nfp-btn"
                    onclick="if(confirm('Reset to default permissions for the {{ $role->type }} role? This overwrites the current selections.')) { window.location='{{ route('roles.permissions.defaults', $role->id) }}' }">
                Set Defaults for {{ ucfirst($role->type) }}
            </button>
            <a href="{{ route('roles.index') }}" class="nfp-btn">Back to Roles</a>
        </div>
    </div>

    <div class="nfp-tabs">
        <span class="nfp-tab is-on">Web</span>
        <a class="nfp-tab" href="{{ route('roles.mobile-permissions', $role->id) }}">Mobile</a>
    </div>

    {{-- Approval authority (moved here from Request Settings; same table + endpoints).
         Interactive for users who can manage request settings; read-only otherwise.
         Changes apply immediately (AJAX), separate from the permission Save button. --}}
    <div class="nfp-authbar" id="nfp-authbar" data-role-id="{{ $role->id }}">
        <span class="nfp-authlbl">Approval authority</span>
        <button type="button" class="nfp-authchip" data-level="1" data-id="{{ $approvalL1Id ?? '' }}"
                aria-pressed="{{ !empty($approvalL1Id) ? 'true' : 'false' }}" {{ empty($canManageApprovals) ? 'disabled' : '' }}>
            Level 1 approver
        </button>
        <button type="button" class="nfp-authchip" data-level="2" data-id="{{ $approvalL2Id ?? '' }}"
                aria-pressed="{{ !empty($approvalL2Id) ? 'true' : 'false' }}" {{ empty($canManageApprovals) ? 'disabled' : '' }}>
            Level 2 approver
        </button>
        <span class="nfp-authhint">A role can hold both. @if(empty($canManageApprovals))Read-only — needs the Requests-settings permission.@else Changes apply immediately.@endif</span>
    </div>

    <div class="nfp-legend">
        <span><span class="nfp-tag enforced">Enforced</span> actually checked in code</span>
        <span><span class="nfp-tag new">New</span> was previously grantable only by DB edit</span>
        <span><b>Off / Own / All</b> = one control for a view / view-all pair</span>
        <span><b>Legacy</b> drawer = keys nothing checks (kept, not deleted)</span>
    </div>

    <form method="POST" action="{{ route('roles.permissions.update', $role->id) }}" id="nfp-form">
        @csrf
        @method('PUT')

        <div class="nfp-tools">
            <div class="nfp-find">
                <span aria-hidden="true">🔍</span>
                <input type="text" id="nfp-search" placeholder="Filter permissions…" autocomplete="off" spellcheck="false">
            </div>
            <span class="nfp-changes" id="nfp-changes">● unsaved changes</span>
        </div>

        {{-- ============ Orders & Dispatch ============ --}}
        <div class="nfp-group">
            <h3>Orders &amp; Dispatch <span class="nfp-group-tag">Orders</span></h3>
            {{-- Orders tier: view_orders (Own) + view_all_orders (All, enforced) --}}
            <div class="nfp-row">
                <div class="meta">
                    <div class="name">Orders <span class="nfp-tag enforced">Enforced</span> <code>view_orders · view_all_orders</code></div>
                    <div class="help">Off = no access · Own only = assigned orders (view_orders) · All = every order (view_all_orders, the enforced one)</div>
                </div>
                <div class="nfp-tier" data-nf-tier>
                    <input type="checkbox" class="nfp-tierbox" name="permissions[view_orders]" value="1" data-nf-own hidden {{ ($currentPermissions['view_orders'] ?? false) ? 'checked' : '' }}>
                    <input type="checkbox" class="nfp-tierbox" name="permissions[view_all_orders]" value="1" data-nf-all hidden {{ ($currentPermissions['view_all_orders'] ?? false) ? 'checked' : '' }}>
                    <button type="button" class="off" data-v="off" aria-pressed="false">Off</button>
                    <button type="button" data-v="own" aria-pressed="false">Own only</button>
                    <button type="button" data-v="all" aria-pressed="false">All</button>
                </div>
            </div>
            @foreach([
                'create_orders' => ['Create Orders', 'Create new orders (gates the create button on the Orders page)'],
                'view_shopify_orders' => ['Shopify Approval Queue', 'See orders from the Shopify approval queue'],
                'view_open_quantities' => ['Open Order Quantities', 'Access the Open Order Quantities dashboard'],
                'split_orders' => ['Split an Invoice', 'Move items that cannot go today out of an open invoice into a new invoice (NF-12345-A). Web + phone.'],
                // ⚠ Oct-2026: this key is in RolePermissionController's list but had no
                // checkbox, so every Save of this page wrote it back as not-allowed.
                'return_orders' => ['Return a Delivered Order', 'Take a delivered order back: decides whether the money is reversed, refunded or credited, and writes stock off. Normally Shabib and Taimur only.'],
            ] as $key => $cfg)
            <label class="nfp-row">
                <div class="meta">
                    <div class="name">{{ $cfg[0] }} <span class="nfp-tag enforced">Enforced</span> <code>{{ $key }}</code></div>
                    <div class="help">{{ $cfg[1] }}</div>
                </div>
                <input type="checkbox" class="nfp-cb on" name="permissions[{{ $key }}]" value="1" {{ ($currentPermissions[$key] ?? false) ? 'checked' : '' }}>
            </label>
            @endforeach
        </div>

        {{-- ============ Approvals & Requests ============ --}}
        <div class="nfp-group">
            <h3>Approvals &amp; Requests <span class="nfp-group-tag">Approvals</span></h3>
            <div class="nfp-row">
                <div class="meta">
                    <div class="name">Requests <span class="nfp-tag enforced">Enforced</span> <code>view_requests · view_all_requests</code></div>
                    <div class="help">Off = no access · Own only = own requests (view_requests) · All = every request (view_all_requests, the enforced one)</div>
                </div>
                <div class="nfp-tier" data-nf-tier>
                    <input type="checkbox" class="nfp-tierbox" name="permissions[view_requests]" value="1" data-nf-own hidden {{ ($currentPermissions['view_requests'] ?? false) ? 'checked' : '' }}>
                    <input type="checkbox" class="nfp-tierbox" name="permissions[view_all_requests]" value="1" data-nf-all hidden {{ ($currentPermissions['view_all_requests'] ?? false) ? 'checked' : '' }}>
                    <button type="button" class="off" data-v="off" aria-pressed="false">Off</button>
                    <button type="button" data-v="own" aria-pressed="false">Own only</button>
                    <button type="button" data-v="all" aria-pressed="false">All</button>
                </div>
            </div>
            <label class="nfp-row">
                <div class="meta">
                    <div class="name">Requests Setup <span class="nfp-tag enforced">Enforced</span> <code>manage_request_settings</code></div>
                    <div class="help">Access the Requests Setup screen (request types, sub-categories, approval routing)</div>
                </div>
                <input type="checkbox" class="nfp-cb on" name="permissions[manage_request_settings]" value="1" {{ ($currentPermissions['manage_request_settings'] ?? false) ? 'checked' : '' }}>
            </label>
        </div>

        {{-- ============ Finance ============ --}}
        <div class="nfp-group">
            <h3>Finance <span class="nfp-group-tag">Finance</span></h3>
            <label class="nfp-row">
                <div class="meta">
                    <div class="name">Manage Asset Categories <span class="nfp-tag new">New</span> <code>manage_asset_categories</code></div>
                    <div class="help">Create / edit fixed-asset categories (enforced on the Add Asset screen)</div>
                </div>
                <input type="checkbox" class="nfp-cb" name="permissions[manage_asset_categories]" value="1" {{ ($currentPermissions['manage_asset_categories'] ?? false) ? 'checked' : '' }}>
            </label>
        </div>

        {{-- ============ Restricted Web Menu (web_menu_*) ============ --}}
        <div class="nfp-warncard">
            <h3>⚠ Restricted-menu mode <span class="nfp-tag dead">special</span></h3>
            <p>If <b>any</b> box below is ticked, users in this role see <b>only</b> the ticked sections on the web sidebar — everything else disappears. Leave all unticked for the normal full menu.</p>
            @foreach([
                'web_menu_hq' => 'HQ · Executive only',
                'web_menu_dashboards' => 'Dashboards only',
                'web_menu_invoices' => 'Invoices / Approvals only',
                'web_menu_customers' => 'Customers only',
                'web_menu_finance' => 'Finance only',
            ] as $key => $label)
            <label class="nfp-wrow">
                <input type="checkbox" name="permissions[{{ $key }}]" value="1" {{ ($currentPermissions[$key] ?? false) ? 'checked' : '' }}>
                {{ $label }} <code>{{ $key }}</code>
            </label>
            @endforeach
        </div>

        {{-- ============ Legacy drawer (dead keys, still submitted) ============ --}}
        @php
            $nfLegacy = [
                'view_dashboard' => 'View Dashboard',
                'edit_orders' => 'Edit Orders',
                'view_order_status' => 'Manage Order Status',
                'view_status_history' => 'View Status History',
                'assign_riders' => 'Assign Riders to Orders',
                'bulk_operations' => 'Bulk Operations',
                'view_invoices' => 'View Invoices',
                'view_all_invoices' => 'View All Invoices',
                'view_riders' => 'View Riders List',
                'view_all_riders' => 'View All Riders',
                'edit_riders' => 'Edit Rider Profiles',
                'view_customers' => 'View Customers',
                'edit_customers' => 'Edit Customers',
                'view_products' => 'View Products',
                'edit_products' => 'Edit Products',
                'view_attendance' => 'View Attendance',
                'view_all_attendance' => 'View All Attendance',
                'create_requests' => 'Create Requests',
                'approve_requests' => 'Approve / Reject Requests',
                'view_users' => 'Manage Users',
                'view_roles' => 'Manage Roles',
                'view_logs' => 'View Error Logs',
                'view_operations' => 'Access Operations',
            ];
        @endphp
        <details class="nfp-legacydraw">
            <summary>
                <svg class="chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
                Legacy — currently no effect ({{ count($nfLegacy) }})
                <span class="nfp-tag dead" style="margin-left:8px">hidden by default</span>
            </summary>
            <div class="nfp-legacybody">
                <div class="note">Defined years ago but checked nowhere in the code. Kept in the database and still saved — just not presented as working toggles. (The two tier "Own only" halves — <code>view_orders</code>, <code>view_requests</code> — are also unenforced today.)</div>
                @foreach($nfLegacy as $key => $label)
                <label class="nfp-row legacy">
                    <div class="meta">
                        <div class="name">{{ $label }} <span class="nfp-tag dead">no effect</span> <code>{{ $key }}</code></div>
                    </div>
                    <input type="checkbox" class="nfp-cb" name="permissions[{{ $key }}]" value="1" {{ ($currentPermissions[$key] ?? false) ? 'checked' : '' }}>
                </label>
                @endforeach
            </div>
        </details>

        {{-- ============ Business Unit Access (unchanged mechanics) ============ --}}
        <div class="nfp-bucard">
            <h3>🏢 Business Unit Access</h3>
            <p class="hint">Which business units this role can access for expenses, vendors and company accounts.</p>
            <label class="fld">Access Type</label>
            <select name="business_unit_access" id="business_unit_access" onchange="toggleBusinessUnitOptions()">
                <option value="all" {{ ($role->business_unit_access ?? 'all') == 'all' ? 'selected' : '' }}>All Business Units (Full Access)</option>
                <option value="single" {{ ($role->business_unit_access ?? '') == 'single' ? 'selected' : '' }}>Single Business Unit Only</option>
                <option value="multiple" {{ ($role->business_unit_access ?? '') == 'multiple' ? 'selected' : '' }}>Multiple Business Units</option>
            </select>
            <div id="default_bu_section">
                <label class="fld">Default Business Unit</label>
                <select name="default_business_unit_id">
                    @foreach($businessUnits ?? [] as $bu)
                        <option value="{{ $bu->id }}" {{ ($role->default_business_unit_id ?? 1) == $bu->id ? 'selected' : '' }} style="color: {{ $bu->color_hex ?? '#374151' }}">
                            {{ $bu->name }} {{ $bu->short_code ? '(' . $bu->short_code . ')' : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div id="multiple_bu_section" style="display: none;">
                <label class="fld">Assigned Business Units</label>
                <div class="bu-list">
                    @foreach($businessUnits ?? [] as $bu)
                        <label>
                            <input type="checkbox" name="assigned_business_units[]" value="{{ $bu->id }}" {{ in_array($bu->id, $assignedBusinessUnits ?? []) ? 'checked' : '' }} style="accent-color:#7C3AED">
                            <span style="color: {{ $bu->color_hex ?? '#374151' }}">{{ $bu->name }} {{ $bu->short_code ? '(' . $bu->short_code . ')' : '' }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="nfp-savebar">
            <div class="inner">
                <span class="nfp-changes" id="nfp-changes2">● unsaved changes — remember to save</span>
                <button type="submit" class="nfp-save">💾 Save Web Permissions</button>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    "use strict";
    // Tier controls → drive the two hidden checkboxes with the real permission names.
    document.querySelectorAll('[data-nf-tier]').forEach(function (t) {
        var own = t.querySelector('[data-nf-own]');
        var all = t.querySelector('[data-nf-all]');
        var btns = t.querySelectorAll('button[data-v]');
        function sync() {
            var v = all.checked ? 'all' : (own.checked ? 'own' : 'off');
            btns.forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-v') === v ? 'true' : 'false'); });
        }
        btns.forEach(function (b) {
            b.addEventListener('click', function () {
                var v = b.getAttribute('data-v');
                own.checked = (v === 'own' || v === 'all');
                all.checked = (v === 'all');
                sync();
                markChanged();
            });
        });
        sync();
    });

    // Unsaved-changes hint.
    function markChanged() {
        var a = document.getElementById('nfp-changes');
        var b = document.getElementById('nfp-changes2');
        if (a) a.classList.add('is-on');
        if (b) b.classList.add('is-on');
    }
    window.__nfpMarkChanged = markChanged;
    document.getElementById('nfp-form').addEventListener('change', markChanged);

    // Filter.
    var search = document.getElementById('nfp-search');
    if (search) {
        search.addEventListener('input', function () {
            var term = this.value.trim().toLowerCase();
            document.querySelectorAll('.nfp-group, .nfp-warncard, .nfp-legacydraw').forEach(function (grp) {
                var rows = grp.querySelectorAll('.nfp-row, .nfp-wrow');
                var any = false;
                rows.forEach(function (r) {
                    var txt = (r.textContent || '').toLowerCase();
                    var m = !term || txt.indexOf(term) !== -1;
                    r.style.display = m ? '' : 'none';
                    if (m) any = true;
                });
                grp.style.display = (!term || any) ? '' : 'none';
            });
            if (term) { var d = document.querySelector('.nfp-legacydraw'); if (d) d.open = true; }
        });
    }

    // Business Unit section (unchanged behaviour).
    window.toggleBusinessUnitOptions = function () {
        var accessType = document.getElementById('business_unit_access').value;
        var multipleBuSection = document.getElementById('multiple_bu_section');
        var defaultBuSection = document.getElementById('default_bu_section');
        multipleBuSection.style.display = (accessType === 'multiple') ? 'block' : 'none';
        defaultBuSection.style.display = 'block';
    };
    document.addEventListener('DOMContentLoaded', window.toggleBusinessUnitOptions);
    window.toggleBusinessUnitOptions();

    // Approval-authority chips → immediate AJAX to the existing Request-Settings
    // endpoints (same table the old card wrote). Separate from the permission Save.
    (function () {
        var bar = document.getElementById('nfp-authbar');
        if (!bar) return;
        var tokenEl = document.querySelector('#nfp-form input[name=_token]');
        var token = tokenEl ? tokenEl.value : '';
        var roleId = bar.getAttribute('data-role-id');
        var assignUrl = "{{ route('requests.settings.roles.assign') }}";
        var removeUrlTpl = "{{ route('requests.settings.roles.remove', '__ID__') }}";
        bar.querySelectorAll('.nfp-authchip').forEach(function (chip) {
            if (chip.disabled) return;
            chip.addEventListener('click', function () {
                var level = chip.getAttribute('data-level');
                var id = chip.getAttribute('data-id');
                var on = chip.getAttribute('aria-pressed') === 'true';
                chip.disabled = true;
                var req;
                if (on && id) {
                    req = fetch(removeUrlTpl.replace('__ID__', id), {
                        method: 'DELETE',
                        headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                    });
                } else {
                    req = fetch(assignUrl, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': token, 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                        body: JSON.stringify({ role_id: roleId, approval_level: level })
                    });
                }
                req.then(function (r) { return r.json().catch(function () { return {}; }); })
                   .then(function (d) {
                       if (d && d.success) {
                           if (on) { chip.setAttribute('aria-pressed', 'false'); chip.setAttribute('data-id', ''); }
                           else { chip.setAttribute('aria-pressed', 'true'); chip.setAttribute('data-id', d.data && d.data.id ? d.data.id : ''); }
                       } else {
                           alert((d && d.message) || 'Could not update approval level.');
                       }
                   })
                   .catch(function () { alert('Network error updating approval level.'); })
                   .finally(function () { chip.disabled = false; });
            });
        });
    })();
})();
</script>
@endsection
