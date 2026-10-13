<?php
/**
 * 💲 PRICE FROM THE LINKED PRODUCT'S LAST BILL (Oct-12 2026, owner).
 *
 * A recipe ingredient is priced from the newest bill of the product it is LINKED to,
 * even when the line was never stamped ("count earlier bills" not pressed).
 *   §1 Daal Channa: 2 unstamped Imtiaz Store frozen bills (3 + 5 Oct) → Rs 280 a kg (5 Oct).
 *   §2 ⚠ another vendor's Daal Channa (staff food) never prices it — the product is not linked.
 *   §3 a linked product that is switched OFF prices nothing (Zeera Sabat → Zeera Powder).
 *   §4 PRICE ONLY: stock / Month Review quantities still count stamped lines only.
 *   §5 a stamped line and an unstamped one on the same day make one weighted price.
 *   §6 a line in another unit than the product's is not guessed at.
 *   §7 Beef Shami's cost per pack.
 * Rolled back. Run:  php test_price_from_linked_product_oct12.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Khaas\FrozenCostingService;
use App\Services\Khaas\IngredientPriceService;
use App\Services\Khaas\RecipeService;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function ok(string $what, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ✓ $what\n"; }
    else { $fail++; echo "  ✗ $what\n      got:  " . var_export($got, true) . "\n      want: " . var_export($want, true) . "\n"; } }
function head(string $t) { echo "\n== $t ==\n"; }

const BU = 2; const DAAL = 45; const ZEERA_P = 40; const DAAL_PRODUCT = 101; const SHAMI = 1318;
$ps = new IngredientPriceService();

DB::beginTransaction();
try {
    head('§1 Daal Channa prices from its linked product\'s newest bill');
    $stamped = DB::table('t_fin_vendor_purchase_items')->where('vendor_product_id', DAAL_PRODUCT)->whereNotNull('ingredient_id')->count();
    ok('setup: none of the Daal Channa lines is stamped', $stamped, 0);
    $p = $ps->latest(BU, [DAAL])[DAAL] ?? null;
    ok('priced: Rs 280 a kg · bill · 5 Oct · Imtiaz Store frozen',
        [$p['price_text'] ?? null, $p['source'] ?? null, $p['bought_on'] ?? null, $p['vendor_name'] ?? null],
        ['Rs 280 a kg', 'bill', '2026-10-05', 'Imtiaz Store frozen']);
    ok('  …previous price is the 3-Oct bill (Rs 269 a kg)', $p['previous']['price_text'] ?? null, 'Rs 269 a kg');
    $facts = (new RecipeService())->lineFacts([DAAL]);
    ok('  …the recipe badge agrees: last bought 5 Oct (not "never bought")', $facts[DAAL]['last_bought_on'], '2026-10-05');

    head('§2 another vendor\'s Daal Channa never prices it');
    $other = DB::table('t_fin_vendors')->where('business_unit_id', BU)->where('id', '!=', 50)->value('id');
    $vp = DB::table('t_fin_vendor_products')->insertGetId(['vendor_id' => $other, 'product_name' => 'Daal Channa', 'unit' => 'kg',
        'rate_per_unit' => 10, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $ledger = DB::table('t_fin_ledger')->insertGetId(['transaction_date' => '2026-10-11', 'transaction_type' => 'vendor_purchase',
        'business_unit_id' => BU, 'amount' => 50, 'approval_status' => 'approved', 'mode' => 'cash', 'description' => 'test staff food', 'from_account_id' => 1, 'to_account_id' => 1,
        'created_at' => now(), 'updated_at' => now()]);
    DB::table('t_fin_vendor_purchase_items')->insert(['ledger_id' => $ledger, 'vendor_product_id' => $vp, 'product_name' => 'Daal Channa',
        'quantity' => 5, 'unit' => 'kg', 'rate_per_unit' => 10, 'line_total' => 50, 'created_at' => now(), 'updated_at' => now()]);
    $p = $ps->latest(BU, [DAAL])[DAAL] ?? null;
    ok('a newer, cheaper Daal Channa bill (11 Oct, Rs 10/kg) at another vendor, unlinked: price unchanged',
        [$p['price_text'] ?? null, $p['bought_on'] ?? null], ['Rs 280 a kg', '2026-10-05']);

    head('§3 a linked product that is OFF prices nothing');
    ok('Zeera (Powder): its only bill is on the switched-off Zeera Sabat product → no price', isset($ps->latest(BU, [ZEERA_P])[ZEERA_P]), false);

    head('§4 price only — quantities still count stamped lines');
    $m = (new FrozenCostingService())->ingredientMonth(BU, '2026-10');
    $row = collect($m['rows'] ?? [])->first(fn ($r) => ($r['ingredient_id'] ?? null) == DAAL || ($r['name'] ?? '') === 'Daal Channa');
    ok('October "bought" for Daal Channa is still 0 (the 5 kg is not counted until Qasim says so)', (float) ($row['bought_qty'] ?? 0), 0.0);

    head('§5 same day: stamped + unstamped make one weighted price');
    $l2 = DB::table('t_fin_ledger')->insertGetId(['transaction_date' => '2026-10-05', 'transaction_type' => 'vendor_purchase',
        'business_unit_id' => BU, 'amount' => 600, 'approval_status' => 'approved', 'mode' => 'cash', 'description' => 'test', 'from_account_id' => 1, 'to_account_id' => 1,
        'created_at' => now(), 'updated_at' => now()]);
    DB::table('t_fin_vendor_purchase_items')->insert(['ledger_id' => $l2, 'vendor_product_id' => DAAL_PRODUCT, 'product_name' => 'Daal Channa',
        'quantity' => 2, 'unit' => 'kg', 'rate_per_unit' => 300, 'line_total' => 600, 'ingredient_id' => DAAL, 'qty_base' => 2000,
        'created_at' => now(), 'updated_at' => now()]);
    $p = $ps->latest(BU, [DAAL])[DAAL] ?? null;
    ok('5 Oct: stamped 2 kg @ 300 + unstamped 2 kg @ 280 → Rs 290, source bill',
        [$p['price_text'] ?? null, $p['source'] ?? null], ['Rs 290 a kg', 'bill']);
    DB::table('t_fin_vendor_purchase_items')->where('ledger_id', $l2)->delete();

    head('§6 another unit is not guessed at');
    DB::table('t_fin_vendor_purchase_items')->where('id', 3662)->update(['unit' => 'pack']);
    $p = $ps->latest(BU, [DAAL])[DAAL] ?? null;
    ok('the 5-Oct line typed in "pack" on a kg product is skipped → 3 Oct, Rs 269',
        [$p['price_text'] ?? null, $p['bought_on'] ?? null], ['Rs 269 a kg', '2026-10-03']);
    DB::table('t_fin_vendor_purchase_items')->where('id', 3662)->update(['unit' => 'kg']);

    head('§7 Beef Shami');
    $k = $ps->costRecipe((new RecipeService())->recipeFor(SHAMI), $ps->latest(BU));
    $daal = collect($k['lines'])->firstWhere('name', 'Daal Channa');
    ok('Daal Channa line priced: 500 g → Rs 140 a batch', $daal['batch_cost'] ?? null, 140.0);
    printf("     Beef Shami: Rs %s a pack, %d of %d lines priced; still missing: %s\n",
        $k['per_pack'], $k['priced_lines'], $k['priced_lines'] + $k['unpriced_lines'], implode(', ', $k['unpriced_names']));
} finally {
    DB::rollBack();
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
