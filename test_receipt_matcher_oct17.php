<?php
/**
 * PROOF — a SIZE is not a NAME; linked products first; ingredient guess → linked product (Oct-17).
 * Run: php test_receipt_matcher_oct17.php
 *
 * Found in the owner's evaluation of Qasim's 26-line Imtiaz slip: "Non-Woven Bag 50gm"
 * auto-matched "Long 50gm" (cloves) on the shared word "gm" and showed as a CERTAIN match;
 * "Sonf 100g" drew "Bari Elaichi 100gm" as a did-you-mean the same way.
 *
 * Reads vendor 50's real catalogue on the dev replica. No writes, no model calls.
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Assistant\PurchaseLogService;
use App\Http\Controllers\Khaas\ReceiptController;
use App\Models\FIN\VendorModel;

$pass = 0; $fail = 0;
function ok($cond, $label, $extra = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; } else { $fail++; echo "  ✗ $label" . ($extra !== '' ? "  → $extra" : '') . "\n"; }
}

$r    = new PurchaseLogService();
$ctrl = new ReceiptController();
$closest = new ReflectionMethod($ctrl, 'closestProducts'); $closest->setAccessible(true);
$build   = new ReflectionMethod($ctrl, 'buildCard');       $build->setAccessible(true);
$cat  = $r->vendorProducts(50);
$names = fn (array $s) => array_map(fn ($x) => $x['name'], $s);

// ── 1. the size-word rule ────────────────────────────────────────────────────
echo "1. isSizeWord()\n";
foreach (['g', 'gm', 'gms', 'kg', 'kgs', 'ml', 'ltr', 'litre', 'pcs', '50gm', '100g', '910g', '1ltr', '750ml', '18g', '500', '1.5kg', '30s'] as $w) {
    ok(PurchaseLogService::isSizeWord($w), "\"$w\" is a size word");
}
foreach (['onion', 'salt', 'long', 'bag', 'gobi', 'lal', 'oil', 'ghee', 'egg', 'al'] as $w) {
    ok(!PurchaseLogService::isSizeWord($w), "\"$w\" is NOT a size word");
}

// ── 2. the resolver (the card's AUTO match) ──────────────────────────────────
echo "2. resolveProduct() on vendor 50\n";
$bag = $r->resolveProduct(50, 'Non-Woven Ultrasonic Bag 18"x21" 50gm');
ok($bag === null, 'the carrier bag no longer matches "Long 50gm" (cloves)', json_encode($bag->product_name ?? null));
ok(($r->resolveProduct(50, 'Ponam Maida 1kg')->product_name ?? '') === 'Maida', '"Ponam Maida 1kg" → Maida still');
ok(($r->resolveProduct(50, 'National Salt Refined 800G')->product_name ?? '') === 'Salt', '"National Salt Refined 800G" → Salt still');
ok(str_contains($r->resolveProduct(50, 'Puck Crm Ches 910G')->product_name ?? '', 'Puck Cream Cheese'), '"Puck Crm Ches 910G" → Puck Cream Cheese still');
ok(str_contains($r->resolveProduct(50, 'Eva Cokng Oil Stndng Pch 1Ltr')->product_name ?? '', 'Cooking Oil'), '"Eva Cokng Oil … 1Ltr" → Cooking Oil still');
ok(($r->resolveProduct(50, 'Dashi Pwdr Chkn Scht 18G')->product_name ?? '') === 'Chicken Powder 18gm', '"Dashi Pwdr Chkn Scht 18G" → Chicken Powder 18gm still');
ok($r->resolveProduct(50, 'Ponam Sonf 100g') === null, '"Ponam Sonf 100g" matches nothing (no sonf on the list)');

// ── 3. did-you-mean: no size noise, linked first on ties ────────────────────
echo "3. closestProducts()\n";
$ing = DB::table('t_fin_vendor_products')->where('vendor_id', 50)->whereNotNull('ingredient_id')->pluck('id')->map(fn ($i) => (int) $i)->all();
$sonf = $names($closest->invoke($ctrl, 'Ponam Sonf 100g', $cat, $ing));
ok(!in_array('Bari Elaichi 100gm', $sonf, true) && !in_array('Garam Masala (Powder) 100gm', $sonf, true), '"Sonf 100g" no longer suggests the other 100g packs', json_encode($sonf));
$methi = $names($closest->invoke($ctrl, 'Ponam Qasuri Methi 100g', $cat, $ing));
ok(!in_array('Bari Elaichi 100gm', $methi, true), '"Qasuri Methi 100g" neither', json_encode($methi));
$gm = $names($closest->invoke($ctrl, 'Ponam Garam Masala Whole 100g', $cat, $ing));
ok(in_array('Garam Masala Sabat 100gm', $gm, true), '"Garam Masala Whole" still offers Garam Masala Sabat', json_encode($gm));
$bagS = $names($closest->invoke($ctrl, 'Non-Woven Ultrasonic Bag 18"x21" 50gm', $cat, $ing));
ok(!in_array('Long 50gm', $bagS, true), 'the bag does not even get "Long 50gm" as a did-you-mean', json_encode($bagS));

// tie-break: two products with the same word evidence — the linked one first
$fake = [
    (object) ['id' => 1, 'product_name' => 'Zeera Powder 250gm', 'unit' => 'pack', 'rate_per_unit' => 1, 'is_default' => 0], // unlinked (by name, sorts first)
    (object) ['id' => 2, 'product_name' => 'Zeera Sabat 250gm',  'unit' => 'pack', 'rate_per_unit' => 1, 'is_default' => 0], // linked
];
$tie = $closest->invoke($ctrl, 'Zeera 250g', $fake, [2]);
ok(($tie[0]['id'] ?? 0) === 2 && ($tie[1]['id'] ?? 0) === 1, 'on equal evidence the LINKED product comes first (name order would say otherwise)', json_encode($names($tie)));
$tieNone = $closest->invoke($ctrl, 'Zeera 250g', $fake, []);
ok(($tieNone[0]['id'] ?? 0) === 1, 'with no link info, name order as before');

// ── 4. buildCard: the reader's ingredient guess becomes the LINKED product ───
echo "4. buildCard() ingredient → linked product bridge\n";
$vendor = VendorModel::find(50);
$reader = new \App\Services\Khaas\ReceiptExtractionService();
$ln = fn ($name, $ing = null, $match = null) => [
    'raw_name' => $name, 'qty' => 1.0, 'unit_price' => 100.0, 'line_total' => 100.0, 'discount' => 0.0,
    'sold_by' => 'pack', 'pack_size_value' => null, 'pack_size_unit' => null,
    'ai_match' => $match, 'ai_ingredient' => $ing,
];
$read = [
    'store_name' => 'Imtiaz', 'receipt_no' => null, 'receipt_date' => null, 'subtotal' => null, 'discount_total' => 0.0,
    'grand_total' => 400.0, 'confidence' => 'high', 'raw' => '{}', 'model' => 'x', 'tokens_in' => 0, 'tokens_out' => 0,
    'hinted_products' => true, 'hinted_ingredients' => true,
    'lines' => [
        $ln('Cardamom Black 100g', 46),          // I46 Bari Elaichi → P117 Bari Elaichi 100gm is linked to it
        $ln('Thoom', 13),                        // I13 Garlic → TWO linked products (P112, P121); no word in common with either — the bridge still offers one
        $ln('Ponam Sonf 100g', 999999),          // an ingredient with no product → no bridge, no product guess
        $ln('Non-Woven Ultrasonic Bag 50gm'),    // nothing at all
    ],
];
$card = $build->invoke($ctrl, $vendor, $read, $reader);
$L = $card['lines'];
ok(($L[0]['ai_suggestion']['name'] ?? null) === 'Bari Elaichi 100gm', 'Cardamom Black + guess "Bari Elaichi" → suggests the LINKED product P117, not "add new"', json_encode($L[0]['ai_suggestion'] ?? null));
ok(empty($L[0]['ai_ingredient']), '…and no "add it as a product" nudge beside it');
ok(($L[1]['matched'] ?? true) === false, '"Thoom": no word match, so the resolver refuses (as designed)');
ok(in_array($L[1]['ai_suggestion']['name'] ?? null, ['Garlic (Lehsan)', 'Fresh Garlic (Peeled)'], true), '"Thoom" + guess I13 → the bridge offers one of the two linked garlic products', json_encode($L[1]['ai_suggestion'] ?? null));
ok(empty($L[1]['ai_ingredient']), '…and not "add Garlic as a new product"');
ok(empty($L[2]['ai_suggestion']) && empty($L[2]['ai_ingredient']), 'unknown ingredient id → no suggestion of either kind');
ok(empty($L[3]['ai_suggestion']) && ($L[3]['matched'] ?? true) === false, 'the bag: unmatched, no suggestion');
ok(!in_array('Long 50gm', array_column($L[3]['suggestions'] ?? [], 'name'), true), 'the bag: "Long 50gm" not in did-you-mean');

// ── 5. the whole slip, after ─────────────────────────────────────────────────
echo "5. Qasim's 26 lines against vendor 50 (AUTO / DYM / blank), after the change\n";
$printed = ["Fresh Onions","Fresh Podina Leaves","Fresh Tomatoes","Fresh Capsicum (Shimla Mirch)","Fresh Carrot (Orange)","Fresh Ginger","Fresh Garlic (Peeled)","Ponam Maida 1kg","Dashi Pwdr Chkn Scht 18G","Eva Cokng Oil Stndng Pch 1Ltr","Key Suc Soya 750Ml","Sugar 1kg","National Salt Refined 800G","Puck Crm Ches 910G","Ponam Lal Mirch Powder 400g","Ponam Sonf 100g","Ponam Garam Masala Whole 100g","Ponam Dar Chini","Cardamom Black 100g","Chilli Whole 200G","Ponam Red Kidney Beans (Lobia) 500g","Ponam Qasuri Methi 100g","Ponam Dal Mash 1000g","Chilli Crushed 500g","Non-Woven Ultrasonic Bag 18\"x21\" 50gm","FBR POS Charges"];
$auto = 0; $dym = 0; $blank = 0; $wrong = [];
foreach ($printed as $i => $n) {
    $x = $r->resolveProduct(50, $n);
    if ($x) { $auto++; $how = 'AUTO → ' . $x->product_name; }
    else { $s = $names($closest->invoke($ctrl, $n, $cat, $ing)); if ($s) { $dym++; $how = 'DYM  → ' . implode(' | ', $s); } else { $blank++; $how = 'blank'; } }
    printf("   %2d %-38s %s\n", $i + 1, $n, $how);
}
echo "   auto=$auto didyoumean=$dym blank=$blank\n";
ok($r->resolveProduct(50, 'FBR POS Charges') === null, 'FBR POS Charges: no auto match');
ok($auto >= 11, 'at least 11 lines still auto-match');

echo "\nPASS $pass  FAIL $fail\n";
exit($fail ? 1 : 0);
