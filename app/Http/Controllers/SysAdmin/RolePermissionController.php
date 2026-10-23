<?php

namespace App\Http\Controllers\SysAdmin;

use App\Http\Controllers\Controller;
use App\Models\SysAdmin\RoleModel;
use App\Models\SysAdmin\RolePermissionModel;
use App\Models\FIN\BusinessUnitModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RolePermissionController extends Controller
{
    // Available permissions in the system
    private function getAvailablePermissions()
    {
        return [
            // Core Navigation
            'view_dashboard' => 'View Dashboard',
            
            // Orders & Deliveries
            'view_orders' => 'View Orders',
            'view_all_orders' => 'View All Orders (vs own assigned)',
            'create_orders' => 'Create New Orders',
            'edit_orders' => 'Edit Orders',
            'view_shopify_orders' => 'View Shopify Orders',
            'view_order_status' => 'Manage Order Status',
            'view_status_history' => 'View Status History',
            'assign_riders' => 'Assign Riders to Orders',
            'bulk_operations' => 'Bulk Operations (status, rider assign)',
            // Sep-2026 — returning a DELIVERED order. Not a view permission: it
            // decides where the customer's money goes and writes stock off.
            'return_orders' => 'Return a Delivered Order (money + stock)',
            // Oct-2026 — move items that can't go today into a new <number>-A invoice
            // (OrderSplitService). ⚠ Must ALSO have a checkbox on permissions.blade.php,
            // or every Save of the role page writes it back as not-allowed.
            'split_orders' => 'Split an Invoice (move items to a new -A order)',

            // Invoices & Quantities
            'view_invoices' => 'View Invoices',
            'view_all_invoices' => 'View All Invoices (vs own orders)',
            'view_open_quantities' => 'View Open Order Quantities',
            
            // Riders
            'view_riders' => 'View Riders List',
            'view_all_riders' => 'View All Riders (vs only self)',
            'edit_riders' => 'Edit Rider Profiles',
            
            // Customers & Products
            'view_customers' => 'View Customers',
            'edit_customers' => 'Edit Customers',
            'view_products' => 'View Products',
            'edit_products' => 'Edit Products',
            
            // Attendance
            'view_attendance' => 'View Attendance',
            'view_all_attendance' => 'View All Attendance (vs own)',

            // Payroll (Jul-2026) — the new Payroll screen: view the month grid,
            // set base salaries, give advances and pay. One key gates all of it.
            'manage_payroll' => 'Manage Payroll (view, generate & pay salaries)',
            
            // Requests & Approvals
            'view_requests' => 'View Requests',
            'view_all_requests' => 'View All Requests (vs own)',
            'create_requests' => 'Create Requests',
            'approve_requests' => 'Approve/Reject Requests',
            'manage_request_settings' => 'Manage Request Settings',
            
            // Administration
            'view_users' => 'Manage Users',
            'view_roles' => 'Manage Roles',
            'view_logs' => 'View Error Logs',
            'view_operations' => 'Access Operations (imports, bulk actions)',

            // Finance (Jul-2026) — enforced in fin/asset/create.blade; previously
            // grantable only by raw SQL, now managed on the permissions screen.
            'manage_asset_categories' => 'Manage Asset Categories',

            // Bikes & Khaas reporting (Jul-2026). Standalone keys so a person who
            // needs only ONE of these — e.g. a Khaas-mode user reviewing bike
            // running costs — can be given it without any rider-ops or finance
            // access coming along with it.
            'view_bike_costs' => 'View Bikes (fuel & running cost per rider)',
            'manage_bike_service' => 'Set bike service schedules (record a service, per-bike & company-wide interval)',
            // ⚠ This registry is the ONLY source the Roles screen enumerates from — a key
            //   seeded in t_sys_role_permissions but missing here cannot be toggled by anyone.
            'manage_vehicle_tickets' => 'Respond to and close bike tickets (faults riders report)',
            // ⚠ Pre-existing gap found in the same Sep-2 review: the Sep-01 handover work checks
            //   `assign_vehicles` but never listed it here, so the right could not be granted or
            //   revoked from this screen by anyone.
            'assign_vehicles' => 'Assign and take back vehicles (approve rider handover requests)',
            'schedule_workshop' => 'Schedule workshop visits for bikes (set / move / cancel / mark done)',
            'receive_workshop_alerts' => 'See workshop visit alerts (banner on attendance, riders map and the shift planner)',
            'view_khaas_sales_report' => 'View Khaas Sales Report',

            // Restricted Web Menu keys (Jul-2026). Rendered in the amber warning card
            // on the permissions screen, NOT as ordinary checkboxes. If a role holds
            // ANY of these = 1, its users see ONLY those sidebar sections (+ Logout).
            'web_menu_hq' => 'Restricted menu: HQ · Executive only',
            'web_menu_dashboards' => 'Restricted menu: Dashboards only',
            'web_menu_invoices' => 'Restricted menu: Invoices / Approvals only',
            'web_menu_invoices_analysis' => 'Restricted menu: Invoices — Analysis (read-only explorer)',
            'web_menu_customers' => 'Restricted menu: Customers only',
            'web_menu_finance' => 'Restricted menu: Finance only',
            'web_menu_finance_hub' => 'Restricted menu: Ledger Hub only',
        ];
    }

    /**
     * The web_menu_* keys are a RESTRICTED-MENU mechanism, not ordinary feature
     * grants: any of them = 1 collapses a role's sidebar to only those sections.
     * They must never be part of an "all permissions" default (that would trap an
     * admin's own menu), so callers building default sets exclude them via this list.
     */
    private function webMenuKeys(): array
    {
        return array_values(array_filter(
            array_keys($this->getAvailablePermissions()),
            fn ($k) => strpos($k, 'web_menu_') === 0
        ));
    }

    public function manage($roleId)
    {
        $role = RoleModel::findOrFail($roleId);
        $availablePermissions = $this->getAvailablePermissions();
        
        // Get current permissions for this role
        $currentPermissions = RolePermissionModel::where('role_id', $roleId)
            ->pluck('is_allowed', 'permission_key')
            ->toArray();

        // Get all active business units
        $businessUnits = BusinessUnitModel::where('is_active', 1)->ordered()->get();
        
        // Get assigned business units for this role (if 'multiple' access type)
        $assignedBusinessUnits = DB::table('t_sys_role_business_unit')
            ->where('role_id', $roleId)
            ->pluck('business_unit_id')
            ->toArray();

        // Role Access header extras (Phase 3): user count + approval-level authority.
        // The L1/L2 chips are wired to the existing Request-Settings endpoints, so no
        // approval write-path changes here — we only read current state for display.
        $roleUserCount = DB::table('t_sys_user_role')->where('role_id', $roleId)->count();
        $approvalRows = \App\Models\SysAdmin\RoleApprovalLevelModel::where('role_id', $roleId)
            ->where('is_active', 1)->get()->keyBy('approval_level');
        $approvalL1Id = optional($approvalRows->get(1))->id;
        $approvalL2Id = optional($approvalRows->get(2))->id;
        $canManageApprovals = auth()->user()->hasPermission('manage_request_settings');

        return view('pages.roles.permissions', compact(
            'role', 'availablePermissions', 'currentPermissions', 'businessUnits',
            'assignedBusinessUnits', 'roleUserCount', 'approvalL1Id', 'approvalL2Id', 'canManageApprovals'
        ));
    }

    public function update(Request $request, $roleId)
    {
        $role = RoleModel::findOrFail($roleId);
        $availablePermissions = array_keys($this->getAvailablePermissions());
        
        try {
            DB::beginTransaction();

            // Delete only the permissions THIS form manages. Any out-of-list grant
            // (e.g. web_menu_* restricted-menu keys or manage_asset_categories, which
            // are inserted via SQL and never appear on this screen) must survive a save
            // untouched — otherwise clicking Save silently wipes them.
            RolePermissionModel::where('role_id', $roleId)
                ->whereIn('permission_key', $availablePermissions)
                ->delete();

            // Insert new permissions
            foreach ($availablePermissions as $permissionKey) {
                $isAllowed = $request->has("permissions.{$permissionKey}");
                
                RolePermissionModel::create([
                    'role_id' => $roleId,
                    'permission_key' => $permissionKey,
                    'permission_name' => $this->getAvailablePermissions()[$permissionKey],
                    'is_allowed' => $isAllowed,
                    'created_by' => auth()->id()
                ]);
            }
            
            // ⭐ Update Business Unit Access settings (graceful if columns not yet migrated)
            $businessUnitAccess = $request->input('business_unit_access', 'all');
            $defaultBusinessUnitId = $request->input('default_business_unit_id', 1);
            
            try {
                $role->update([
                    'business_unit_access' => $businessUnitAccess,
                    'default_business_unit_id' => $defaultBusinessUnitId,
                    'updated_by' => auth()->id()
                ]);
                
                // ⭐ Handle assigned business units for 'multiple' access type
                // First, delete existing assignments
                DB::table('t_sys_role_business_unit')->where('role_id', $roleId)->delete();
                
                if ($businessUnitAccess === 'multiple') {
                    // Insert new assignments
                    $assignedUnits = $request->input('assigned_business_units', []);
                    foreach ($assignedUnits as $buId) {
                        DB::table('t_sys_role_business_unit')->insert([
                            'role_id' => $roleId,
                            'business_unit_id' => $buId,
                            'is_default' => ($buId == $defaultBusinessUnitId) ? 1 : 0,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                    }
                } elseif ($businessUnitAccess === 'single') {
                    // For single access, just insert the default BU
                    DB::table('t_sys_role_business_unit')->insert([
                        'role_id' => $roleId,
                        'business_unit_id' => $defaultBusinessUnitId,
                        'is_default' => 1,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
                // For 'all' access, no entries needed - they have full access
            } catch (\Illuminate\Database\QueryException $e) {
                // business_unit_access / default_business_unit_id columns or t_sys_role_business_unit table not yet created
                \Log::warning('BU access columns/table missing in t_sys_role - skipping BU settings save. Run business_unit_role_access.sql migration.', [
                    'error' => $e->getMessage()
                ]);
            }
            
            DB::commit();

            return redirect()->route('roles.index')
                ->with('success', "Permissions updated for role: {$role->urole_name}");
                
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()
                ->with('error', 'Error updating permissions: ' . $e->getMessage());
        }
    }

    // Set default permissions for a role type
    public function setDefaults($roleId)
    {
        $role = RoleModel::findOrFail($roleId);
        $availablePermissions = $this->getAvailablePermissions();
        
        // Default permissions by role type
        $defaults = [
            'rider' => [
                'view_dashboard',
                'view_orders', // Only assigned orders (no view_all_orders)
                'view_invoices', // Only own orders (no view_all_invoices)
                'view_riders', // Can see riders list but only self (no view_all_riders)
                'view_attendance', // Only own (no view_all_attendance)
                'view_requests', // Only own (no view_all_requests)
                'create_requests',
                // NOT: create_orders, edit_orders, view_shopify_orders, view_open_quantities, view_all_*
            ],
            'manager' => [
                'view_dashboard',
                'view_orders', 'view_all_orders', 'create_orders', 'edit_orders', 'view_shopify_orders',
                'view_order_status', 'view_status_history', 'assign_riders', 'bulk_operations',
                'view_invoices', 'view_all_invoices', 'view_open_quantities',
                'view_riders', 'view_all_riders', 'edit_riders',
                'view_customers', 'edit_customers',
                'view_products', 'edit_products',
                'view_attendance', 'view_all_attendance',
                'view_requests', 'view_all_requests', 'create_requests', 'approve_requests',
            ],
            // "All permissions" EXCEPT the web_menu_* restricted-menu keys — granting
            // those to an admin/super-admin would collapse their own sidebar.
            'admin' => array_values(array_diff(array_keys($availablePermissions), $this->webMenuKeys())),
            'super_admin' => array_values(array_diff(array_keys($availablePermissions), $this->webMenuKeys()))
        ];

        $rolePermissions = $defaults[$role->type] ?? [];

        // "Set Defaults" resets FEATURE permissions only. The web_menu_* keys are the
        // restricted-menu CONFIGURATION of a role — resetting them here would silently
        // strip a restricted role's menu lock (or, if ever defaulted on, trap an admin).
        // So they are excluded from both the delete scope and the rebuild: whatever
        // restriction rows exist survive a defaults reset untouched.
        $defaultManagedKeys = array_values(array_diff(array_keys($availablePermissions), $this->webMenuKeys()));

        RolePermissionModel::where('role_id', $roleId)
            ->whereIn('permission_key', $defaultManagedKeys)
            ->delete();

        foreach ($defaultManagedKeys as $key) {
            RolePermissionModel::create([
                'role_id' => $roleId,
                'permission_key' => $key,
                'permission_name' => $availablePermissions[$key],
                'is_allowed' => in_array($key, $rolePermissions),
                'created_by' => auth()->id()
            ]);
        }

        return redirect()->route('roles.permissions.manage', $roleId)
            ->with('success', 'Default permissions set for ' . $role->type);
    }
}
