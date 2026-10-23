<?php
/**
 * PROOF (Oct-19-2026): saving a role's permissions page keeps return_orders + split_orders.
 * Renders the real page for Management (role 10), submits exactly the boxes the page has
 * ticked (what a browser Save sends), then reads the grants back. ROLLED BACK.
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{Auth, DB};

Auth::setUser(App\Models\User::find(79));
$ctl = app(App\Http\Controllers\SysAdmin\RolePermissionController::class);
$html = $ctl->manage(10)->render();

preg_match_all('/<input[^>]*name="permissions\[([a-z0-9_]+)\]"[^>]*>/i', $html, $m, PREG_SET_ORDER);
$ticked = [];
foreach ($m as $tag) { if (stripos($tag[0], 'checked') !== false) { $ticked[$tag[1]] = '1'; } }
echo 'return_orders box on page: ' . (str_contains($html, 'permissions[return_orders]') ? 'yes' : 'NO') . ', ticked: ' . (isset($ticked['return_orders']) ? 'yes' : 'no') . "\n";
echo 'split_orders box on page: ' . (str_contains($html, 'permissions[split_orders]') ? 'yes' : 'NO') . ', ticked: ' . (isset($ticked['split_orders']) ? 'yes' : 'no') . "\n";

$roleRow = DB::table('t_sys_role')->where('id', 10)->first();
DB::beginTransaction();
try {
    $req = Illuminate\Http\Request::create('/roles/10/permissions', 'PUT', [
        'permissions' => $ticked,
        'business_unit_access' => $roleRow->business_unit_access ?? 'all',
        'default_business_unit_id' => $roleRow->default_business_unit_id ?? 1,
    ]);
    $ctl->update($req, 10);
    foreach (['return_orders', 'split_orders'] as $k) {
        $v = DB::table('t_sys_role_permissions')->where('role_id', 10)->where('permission_key', $k)->value('is_allowed');
        echo "after Save: $k = " . var_export((int) $v, true) . ((int) $v === 1 ? ' ✔' : ' ✘') . "\n";
    }
} finally {
    DB::rollBack();
}
