<?php
// Sep-27 recipe costing round: render the three pages through the REAL HTTP kernel as
// Taimur (68, sees rupees) and Qasim (91, quantities only), check the new blocks and that
// every inline script parses; then read the recipe JSON both ways.
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
use Illuminate\Http\Request; use Illuminate\Support\Str; use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Crypt; use Illuminate\Cookie\CookieValuePrefix;
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class); $kernel->bootstrap();
$pass = 0; $fail = 0; $sessions = [];
function ok($w, $c, $x = '') { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $w\n"; } else { $fail++; echo "  ✗ $w" . ($x ? " ($x)" : '') . "\n"; } }
function as_user($uid) {
    global $sessions;
    $sid = Str::random(40);
    file_put_contents(storage_path('framework/sessions/' . $sid), serialize(['login_web_' . sha1(SessionGuard::class) => $uid, '_token' => Str::random(40)]));
    $sessions[] = $sid;
    $cookie = config('session.cookie');
    return [$cookie, Crypt::encrypt(CookieValuePrefix::create($cookie, Crypt::getKey()) . $sid, false)];
}
function get($who, $url, $json = false) {
    global $kernel, $app; [$c, $e] = $who;
    $app["auth"]->forgetGuards();  // the kernel keeps the last user between requests
    $r = Request::create($url, 'GET'); $r->cookies->set($c, $e);
    if ($json) { $r->headers->set('Accept', 'application/json'); $r->headers->set('X-Requested-With', 'XMLHttpRequest'); }
    $res = $kernel->handle($r); return [$res->getStatusCode(), $res->getContent()];
}
function checkScripts($label, $html) {
    preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $html, $m);
    $bad = 0;
    foreach ($m[1] as $i => $js) {
        if (trim($js) === '') { continue; }
        $tmp = sys_get_temp_dir() . "/vcost_{$i}.js"; file_put_contents($tmp, $js);
        exec('node --check ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
        if ($rc !== 0) { $bad++; echo "     script #$i: " . implode(' ', array_slice($out, 0, 3)) . "\n"; }
        $out = [];
    }
    ok("$label: every inline script parses (" . count($m[1]) . ')', $bad === 0);
    return $m[1];
}
$taimur = as_user(68); $qasim = as_user(91);
$qUser = App\Models\User::find(91);
$qCost = $qUser && $qUser->hasMobilePermission('view_khaas_costing');
echo "(Qasim view_khaas_costing: " . ($qCost ? 'YES' : 'no') . ")\n";

echo "=== Planning → Ingredients (recipe editor) ===\n";
[$st, $b] = get($taimur, '/khaas/inventory?tab=ingredients');
ok('200', $st === 200, (string) $st);
ok('cost box + cost column are on the page', str_contains($b, 'id="recCost"') && str_contains($b, 'rec-cost'));
ok('change-how-it-is-measured button + panel', str_contains($b, 'id="ingUnitChangeBtn"') && str_contains($b, 'id="ingUnitChange"'));
ok('impact/change URLs rendered with a placeholder id', str_contains($b, '__ID__') && str_contains($b, 'unit-impact') && str_contains($b, 'change-unit'));
$scripts = checkScripts('planning page', $b);
foreach ($scripts as $js) { if (str_contains($js, 'function updateCost')) { file_put_contents(__DIR__ . '/planning_page_script.js', $js); } }

echo "\n=== Recipe JSON (khaas.recipe.show) ===\n";
[$st, $j] = get($taimur, '/khaas/recipe?business_unit_id=2&product_id=1313', true); $d = json_decode($j, true);
ok('Taimur 200 + cost', $st === 200 && isset($d['cost']), (string) $st);
ok('Taimur: Rs 139.50 a pack (1 kg chicken Rs 750 + 300 g cheese Rs 645, over 10)', abs(($d['cost']['per_pack'] ?? 0) - 139.5) < 0.01, json_encode($d['cost']['per_pack'] ?? null));
ok('Taimur: 9 lines unpriced and named', ($d['cost']['unpriced_lines'] ?? 0) === 9 && !$d['cost']['complete']);
ok('Taimur: price book carries cheese at Rs 2,150 a kg (older bill, stale)', ($d['prices']['23']['price_text'] ?? '') === 'Rs 2,150 a kg' && $d['prices']['23']['source'] === 'older_bill' && $d['prices']['23']['stale'] === true);
ok('Taimur: Feb vegetable "money as quantity" lines give NO price', !isset($d['prices']['13']) && !isset($d['prices']['15']));
file_put_contents(__DIR__ . '/recipe_1313_taimur.json', json_encode($d));
[$st, $ij] = get($taimur, '/khaas/ingredients?business_unit_id=2', true);
file_put_contents(__DIR__ . '/ingredients_bu2.json', $ij);
[$st, $j] = get($qasim, '/khaas/recipe?business_unit_id=2&product_id=1313', true); $q = json_decode($j, true);
file_put_contents(__DIR__ . '/recipe_1313_qasim.json', $j);
ok('Qasim 200', $st === 200, (string) $st);
ok('Qasim: the same line states, rupees nulled', ($q['cost']['unpriced_lines'] ?? -1) === 9 && ($qCost || ($q['cost']['per_pack'] === null && $q['cost']['batch_cost'] === null)));
ok('Qasim: price book keys present, prices nulled', array_key_exists('23', $q['prices'] ?? []) && ($qCost || $q['prices']['23']['price_per_base'] === null));
ok('Qasim: no rupee string anywhere in the cost', $qCost || !preg_match('/Rs [0-9]/', json_encode($q['cost'])));

echo "\n=== product-costs (the phone's card chips) ===\n";
[$st, $j] = get($taimur, '/khaas/product-costs?business_unit_id=2', true); $d = json_decode($j, true);
ok('200 + recipe_costs', $st === 200 && isset($d['recipe_costs']['1313']), (string) $st);
ok('1313 per pack 139.5, share 26.8%', abs(($d['recipe_costs']['1313']['per_pack'] ?? 0) - 139.5) < 0.01 && ($d['recipe_costs']['1313']['share_of_price'] ?? 0) == 26.8);
[$st, $j] = get($qasim, '/khaas/product-costs?business_unit_id=2', true); $q = json_decode($j, true);
ok('Qasim: chip keys there, rupees nulled', isset($q['recipe_costs']['1313']) && ($qCost || $q['recipe_costs']['1313']['per_pack'] === null));

echo "\n=== Khaas Products page ===\n";
[$st, $b] = get($taimur, '/khaas/products');
ok('200', $st === 200, (string) $st);
ok('Taimur: "Recipe at today\'s prices" Rs 139.50', str_contains($b, "Recipe at today's prices") && str_contains($b, 'Rs 139.50'));
ok('Taimur: unpriced warning', str_contains($b, 'ingredient(s) not priced yet'));
checkScripts('products page', $b);
[$st, $b] = get($qasim, '/khaas/products');
ok('Qasim 200', $st === 200, (string) $st);
ok('Qasim: no Rs 139.50, still told what is not priced', ($qCost || !str_contains($b, 'Rs 139.50')) && str_contains($b, 'not priced yet'));

echo "\n=== Month Review Sep-2026 ===\n";
[$st, $b] = get($taimur, '/khaas/month-review?month=2026-09');
ok('200', $st === 200, (string) $st);
ok('the block is there with 0 of 514 costed', str_contains($b, 'Recipe vs purchases') && str_contains($b, '0 of 514 packs costed'));
ok('Taimur: purchase split — Rs 178,948 as one total, Rs 6,900 lines not linked', str_contains($b, 'Rs 178,948') && str_contains($b, 'Rs 6,900'));
ok('meat: storage says 12.79 kg of thigh, no recipe covered it', str_contains($b, '12.79 kg') && str_contains($b, 'no recipe covered these packs'));
ok('the shelf caveat is said', str_contains($b, 'A difference is not a loss by itself'));
checkScripts('month review', $b);
[$st, $b] = get($qasim, '/khaas/month-review?month=2026-09');
ok('Qasim 200 + block', $st === 200 && str_contains($b, 'Recipe vs purchases'), (string) $st);
ok('Qasim: no purchase rupees in the block', $qCost || !str_contains($b, 'Rs 178,948'));

echo "\n=== 🔗 Linking from the recipe (Sep-27) ===\n";
[$st, $j] = get($qasim, '/khaas/ingredients?business_unit_id=2', true); $d = json_decode($j, true);
ok('ingredients carry link_vendors = the by-weight Frozen vendors', array_column($d['link_vendors'] ?? [], 'vendor_name') === ['B.B.Q', 'Imtiaz Store frozen', 'Tazo cheese'], json_encode($d['link_vendors'] ?? null));
$cap = collect($d['gaps']['items'] ?? [])->firstWhere('name', 'Capsicum (Shimla Mirch)');
ok('Capsicum is flagged as linked only where bills are one total', ($cap['at_total_only'] ?? null) === true);
[$st, $j] = get($qasim, '/khaas/recipe?business_unit_id=2&product_id=1313', true); $d = json_decode($j, true);
ok('the recipe reply carries link_vendors too', count($d['link_vendors'] ?? []) === 3);
$cheese = collect($d['recipe']['lines'])->firstWhere('ingredient_name', 'Cheese');
ok('each line lists WHICH products it is linked to', ($cheese['linked_list'][0]['product_name'] ?? '') === 'Taxo Cheese per 400 grams' && $cheese['linked_list'][0]['vendor_by_weight'] === true);
[$st, $b] = get($qasim, '/finance/vendors/50/products?link_ingredient=28');
ok('Manage Products opens with ?link_ingredient (200)', $st === 200, (string) $st);
ok('…and its script reads it', str_contains($b, "get('link_ingredient')") && str_contains($b, 'linkIngredientNote'));
$sc = checkScripts('products page with link_ingredient', $b);
foreach ($sc as $js) { if (str_contains($js, "get('link_ingredient')")) { file_put_contents(__DIR__ . '/products_page_script_link.js', $js); } }
[$st, $b] = get($taimur, '/khaas/inventory?tab=ingredients');
ok('Planning page carries the vendor-products url template', str_contains($b, 'VENDOR_PRODUCTS_URL') && str_contains($b, 'vendors\/__VID__\/products'));

foreach ($sessions as $s) { @unlink(storage_path('framework/sessions/' . $s)); }
echo "\n" . ($fail === 0 ? '✅' : '❌') . "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
