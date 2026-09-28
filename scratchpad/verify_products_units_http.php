<?php
// Round B (Sep-26): render the Manage Products page and the vendor page through the REAL
// HTTP kernel as Taimur, check the unit catalogue / mismatch hooks are there, and node --check
// every inline <script> (a route() inside a JS string is invisible to the Blade compiler).
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
use Illuminate\Http\Request; use Illuminate\Support\Str; use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Crypt; use Illuminate\Cookie\CookieValuePrefix;
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class); $kernel->bootstrap();
$sid = Str::random(40);
file_put_contents(storage_path('framework/sessions/' . $sid), serialize(['login_web_' . sha1(SessionGuard::class) => 68, '_token' => Str::random(40)]));
$cookie = config('session.cookie');
$enc = Crypt::encrypt(CookieValuePrefix::create($cookie, Crypt::getKey()) . $sid, false);
$pass = 0; $fail = 0;
function ok($w, $c, $x = '') { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $w\n"; } else { $fail++; echo "  ✗ $w" . ($x ? " ($x)" : '') . "\n"; } }
function get($url) { global $kernel, $cookie, $enc; $r = Request::create($url, 'GET'); $r->cookies->set($cookie, $enc); $res = $kernel->handle($r); return [$res->getStatusCode(), $res->getContent()]; }
function checkScripts($label, $html) {
    preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $html, $m);
    $bad = 0;
    foreach ($m[1] as $i => $js) {
        if (trim($js) === '') { continue; }
        $tmp = sys_get_temp_dir() . "/vpu_{$i}.js";
        file_put_contents($tmp, $js);
        exec('node --check ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
        if ($rc !== 0) { $bad++; echo "     script #$i: " . implode(' ', array_slice($out, 0, 3)) . "\n"; }
        $out = [];
    }
    ok("$label: every inline script parses (" . count($m[1]) . ')', $bad === 0);
}

echo "=== Manage Products — Imtiaz Store frozen (50) ===\n";
[$st, $b] = get('/finance/vendors/50/products');
ok('200', $st === 200, (string) $st);
ok('the ONE unit catalogue is on the page', str_contains($b, 'const UNITS = [{"code":"kg"'));
ok('litre / ml / g are offered now', str_contains($b, 'value="litre"') && str_contains($b, 'value="ml"') && str_contains($b, 'value="g"'));
ok('"liter" and "ton" are no longer offered', !str_contains($b, 'value="liter"') && !str_contains($b, 'value="ton"'));
ok('the old OBVIOUS_UNITS map is gone', !str_contains($b, 'OBVIOUS_UNITS'));
ok('the mismatch box and change panel exist on both forms', str_contains($b, 'id="unit_mismatch"') && str_contains($b, 'id="edit_unit_mismatch"') && str_contains($b, 'id="edit_change_panel"'));
ok('purchase counts (for the lock) are on the page', str_contains($b, 'const PURCHASE_COUNTS = {"76":1}') || preg_match('/const PURCHASE_COUNTS = \{[^}]*"76":1/', $b));
ok('change-unit posts to the khaas ingredients route', (bool) preg_match("#const CHANGE_UNIT_URL = 'http[^']*/khaas/ingredients'#", $b));
ok('ingredients carry recipe_count ("in your recipes")', str_contains($b, '"recipe_count":2'));
checkScripts('products page', $b);
// Hand the page's own script to the vm harness (scratchpad/products_units_harness.cjs).
preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $b, $mm);
foreach ($mm[1] as $js) {
    if (str_contains($js, 'const UNITS =')) { file_put_contents(__DIR__ . '/products_page_script.js', $js); }
}

echo "\n=== Meat vendor — Ghousia (4): still no Frozen vocabulary ===\n";
[$st, $b] = get('/finance/vendors/4/products');
ok('200', $st === 200, (string) $st);
ok('no ingredient field', !str_contains($b, 'id="ingredient_id"'));
ok('the units are there all the same', str_contains($b, 'value="piece"'));
checkScripts('meat products page', $b);

echo "\n=== Vendor page — Imtiaz (50) ===\n";
[$st, $b] = get('/finance/vendors/50');
ok('200', $st === 200, (string) $st);
checkScripts('vendor page', $b);

echo "\n=== Khaas Products — open orders card (Round C) ===\n";
[$st, $b] = get('/khaas/products');
ok('200', $st === 200, (string) $st);
ok('the card says "Open orders" with the split', str_contains($b, '🛒 Open orders') && str_contains($b, '✅ Accepted'));
ok('"Pending orders" headline is gone', !str_contains($b, '🛒 Pending orders'));
ok('the popup shows the later-day section', str_contains($b, "'🗓 Later day'"));
checkScripts('khaas products page', $b);

@unlink(storage_path('framework/sessions/' . $sid));
echo "\n" . ($fail === 0 ? '✅' : '❌') . "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
