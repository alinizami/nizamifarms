<?php
/**
 * PROOF — the exact HTTP doors the phone calls, end to end, on the dev replica (Oct-17).
 * Run: php test_receipt_http_e2e_oct17.php
 *
 * Drives the real routes through the HTTP kernel as Qasim (user 91, Sanctum):
 *   GET  /api/vendors/50/products/list      (the picker's list — linked-first sort needs ingredient_name)
 *   POST /api/vendors/50/receipt/extract    (the card — with the READER FAKED to Qasim's 26 lines)
 *   GET  /api/vendors/receipt-drafts        (the "Continue it" list)
 *   POST /api/vendors/50/receipt/record     (the money door — incl. a HAND-ADDED line with no raw_name)
 * The record step runs inside a transaction that is ROLLED BACK. No model call, no credits.
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

use App\Services\Khaas\ReceiptExtractionService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

$pass = 0; $fail = 0;
function ok($cond, $label, $extra = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; } else { $fail++; echo "  ✗ $label" . ($extra !== '' ? "  → $extra" : '') . "\n"; }
}
function call(string $method, string $uri, array $data = [], array $files = []): array {
    global $kernel;
    $req = Request::create($uri, $method, $data, [], $files, ['HTTP_ACCEPT' => 'application/json']);
    $res = $kernel->handle($req);
    $body = json_decode($res->getContent(), true);
    return [$res->getStatusCode(), is_array($body) ? $body : ['_raw' => mb_substr((string) $res->getContent(), 0, 300)]];
}

// ── Qasim, the real user ────────────────────────────────────────────────────
$qasim = App\Models\User::find(91);
$app->instance('request', Request::create('/api/vendors/50/products/list', 'GET')); // Sanctum::actingAs needs a bound request
Sanctum::actingAs($qasim);
Storage::fake('public');   // the draft photo lands on a throwaway disk

// ── the reader, faked to EXACTLY Qasim's slip (names + the live reader's answers) ──
$printed = [
    ['Fresh Onions', '10.03', '195.00', '1955.46', 'weight', 90, 10], ['Fresh Podina Leaves', '0.19', '345.00', '66.24', 'weight', 87, 17],
    ['Fresh Tomatoes', '1.22', '175.00', '213.15', 'weight', null, null], ['Fresh Capsicum (Shimla Mirch)', '1.40', '189.00', '263.84', 'weight', 91, 18],
    ['Fresh Carrot (Orange)', '1.61', '235.00', '377.88', 'weight', 94, 9], ['Fresh Ginger', '0.50', '369.00', '183.02', 'weight', 113, 14],
    ['Fresh Garlic (Peeled)', '0.71', '275.00', '195.80', 'weight', 112, 13], ['Ponam Maida 1kg', '10.00', '165.00', '1650.00', 'pack', null, null],
    ['Dashi Pwdr Chkn Scht 18G', '12.00', '20.00', '240.00', 'pack', null, null], ['Eva Cokng Oil Stndng Pch 1Ltr', '5.00', '2975.00', '2975.00', 'pack', null, null],
    ['Key Suc Soya 750Ml', '1.00', '245.00', '245.00', 'pack', null, null], ['Sugar 1kg', '2.00', '149.00', '298.00', 'pack', null, null],
    ['National Salt Refined 800G', '1.00', '49.00', '49.00', 'pack', null, null], ['Puck Crm Ches 910G', '1.00', '3579.00', '3579.00', 'pack', null, null],
    ['Ponam Lal Mirch Powder 400g', '1.00', '329.00', '329.00', 'pack', null, null], ['Ponam Sonf 100g', '1.00', '85.00', '85.00', 'pack', null, null],
    ['Ponam Garam Masala Whole 100g', '1.00', '395.00', '395.00', 'pack', null, null], ['Ponam Dar Chini', '0.11', '1099.00', '120.89', 'weight', 118, 48],
    ['Cardamom Black 100g', '1.00', '779.00', '779.00', 'pack', null, 46],   // ⭐ reader names the INGREDIENT only → bridge to P117
    ['Chilli Whole 200G', '1.00', '179.00', '179.00', 'pack', null, null], ['Ponam Red Kidney Beans (Lobia) 500g', '3.00', '199.00', '597.00', 'pack', null, null],
    ['Ponam Qasuri Methi 100g', '1.00', '75.00', '75.00', 'pack', null, null], ['Ponam Dal Mash 1000g', '1.00', '399.00', '399.00', 'pack', null, null],
    ['Chilli Crushed 500g', '1.00', '399.00', '399.00', 'pack', null, null], ['Non-Woven Ultrasonic Bag 18"x21" 50gm', '1.00', '35.00', '15.00', 'pack', null, null],
    ['FBR POS Charges', '1.00', '1.00', '1.00', 'unknown', null, null],
];
$fakeLines = array_map(fn ($p) => [
    'raw_name' => $p[0], 'qty' => (float) $p[1], 'unit_price' => (float) $p[2], 'line_total' => (float) $p[3], 'discount' => 0.0,
    'sold_by' => $p[4], 'pack_size_value' => null, 'pack_size_unit' => null, 'ai_match' => $p[5], 'ai_ingredient' => $p[6],
], $printed);

$app->bind(ReceiptExtractionService::class, fn () => new class($fakeLines) extends ReceiptExtractionService {
    public function __construct(private array $fake) {}
    public function extract(string $path): ?array {
        return [
            'store_name' => 'Imtiaz', 'receipt_no' => null, 'receipt_date' => null, 'subtotal' => null, 'discount_total' => 20.0,
            'grand_total' => 15665.28, 'confidence' => 'high', 'raw' => '{}', 'model' => 'fake', 'tokens_in' => 0, 'tokens_out' => 0,
            'hinted_products' => true, 'hinted_ingredients' => true, 'lines' => $this->fake,
        ];
    }
});

$jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==');
$tmp = tempnam(sys_get_temp_dir(), 'rcpt'); file_put_contents($tmp, $jpeg);
$uuid = (string) Illuminate\Support\Str::uuid();

// Oct-19 refresh: sizes and ids come from the replica, not from the day the proof was written (Qasim
// re-made Salt as P131 and added Sonf / Garam Masala Whole / Dar Chini on 17-18 Oct; the prod sync brought
// them in, and his 17-Oct recording TAUGHT "Fresh Onions" → P90 and "Cardamom Black 100g" → P117).
$activeCount = DB::table('t_fin_vendor_products')->where('vendor_id', 50)->where('is_active', 1)->count();
$pid = fn (string $name) => (int) DB::table('t_fin_vendor_products')->where('vendor_id', 50)->where('is_active', 1)->where('product_name', $name)->value('id');
$aliasMap = app(\App\Services\Assistant\SmsCounterpartyMap::class);
$plog = new \App\Services\Assistant\PurchaseLogService();
$keyOf = new ReflectionMethod($plog, 'productKey'); $keyOf->setAccessible(true);
$taughtId = function (string $raw) use ($aliasMap, $keyOf, $plog): int {
    $a = $aliasMap->byAccount($keyOf->invoke($plog, 50, $raw));
    return ($a && $a->entity_type === 'product') ? (int) $a->entity_id : 0;
};
$saltId = $pid('Salt'); $maidaId = $pid('Maida'); $onionId = $pid('Onions (Piyaaz)');

DB::beginTransaction();
try {
    // ── 1. the picker's list ────────────────────────────────────────────────
    echo "1. GET /api/vendors/50/products/list\n";
    [$st, $b] = call('GET', '/api/vendors/50/products/list');
    ok($st === 200 && ($b['success'] ?? false), 'responds 200 success', "$st " . json_encode($b)[0] ?? '');
    $plist = $b['products'] ?? [];
    ok(count($plist) === $activeCount, "every active product ($activeCount)", (string) count($plist));
    ok(array_key_exists('ingredient_name', $plist[0] ?? []), 'each product carries ingredient_name (the picker sorts linked first on it)');
    ok(($b['supports_ingredients'] ?? false) === true, 'supports_ingredients true (the "Not an ingredient" tick shows)');
    ok(count(array_filter($plist, fn ($p) => !empty($p['ingredient_name']))) >= 30, 'most products are linked', (string) count(array_filter($plist, fn ($p) => !empty($p['ingredient_name']))));

    // ── 2. the card ─────────────────────────────────────────────────────────
    echo "2. POST /api/vendors/50/receipt/extract (reader faked)\n";
    $file = new UploadedFile($tmp, 'bill.jpg', 'image/jpeg', null, true);
    [$st, $b] = call('POST', '/api/vendors/50/receipt/extract', ['client_uuid' => $uuid], ['image' => $file]);
    ok($st === 200 && ($b['success'] ?? false), 'responds 200 success', "$st " . mb_substr(json_encode($b), 0, 200));
    $card = $b['card'] ?? [];
    $L = $card['lines'] ?? [];
    ok(count($L) === 26, '26 lines on the card', (string) count($L));
    ok(($card['catalogue_size'] ?? 0) === $activeCount, "catalogue_size $activeCount", (string) ($card['catalogue_size'] ?? 0));
    ok(($card['reconcile']['matches'] ?? false) === true && abs(($card['reconcile']['lines_total'] ?? 0) - 15665.28) < 0.01, 'reconcile: lines add up to the printed Rs 15,665.28', json_encode($card['reconcile'] ?? null));
    $by = []; foreach ($L as $l) { $by[$l['raw_name']] = $l; }
    ok(($by['Ponam Maida 1kg']['product_name'] ?? null) === 'Maida' && $by['Ponam Maida 1kg']['matched'] === true, 'Maida auto-matched');
    ok(($by['National Salt Refined 800G']['product_name'] ?? null) === 'Salt', 'Salt auto-matched');
    // A TAUGHT name matches straight away; an untaught one only gets the reader's guess as a suggestion.
    // (The untaught case is proved on its own in test_receipt_matcher_oct17.php, section 4.)
    $on = $by['Fresh Onions'] ?? [];
    ok($taughtId('Fresh Onions')
        ? ($on['matched'] ?? false) === true && (int) ($on['product_id'] ?? 0) === $taughtId('Fresh Onions')
        : ($on['ai_suggestion']['name'] ?? null) === 'Onions (Piyaaz)' && ($on['matched'] ?? true) === false,
        'Fresh Onions: taught → matched to its taught product; untaught → reader suggestion P90, shown not applied',
        json_encode(array_intersect_key($on, array_flip(['matched', 'product_id', 'ai_suggestion']))));
    $ca = $by['Cardamom Black 100g'] ?? [];
    ok($taughtId('Cardamom Black 100g')
        ? ($ca['matched'] ?? false) === true && (int) ($ca['product_id'] ?? 0) === $taughtId('Cardamom Black 100g')
        : ($ca['ai_suggestion']['name'] ?? null) === 'Bari Elaichi 100gm',
        '⭐ Cardamom: taught → matched; untaught → the LINKED product Bari Elaichi 100gm offered',
        json_encode(array_intersect_key($ca, array_flip(['matched', 'product_id', 'ai_suggestion']))));
    ok(empty($ca['ai_ingredient']), '…and no "add it as a product" nudge');
    $bag = $by['Non-Woven Ultrasonic Bag 18"x21" 50gm'] ?? [];
    ok(($bag['matched'] ?? true) === false && empty($bag['product_id']), '⭐ the bag is NOT matched (used to be "Long 50gm")');
    ok(!in_array('Long 50gm', array_column($bag['suggestions'] ?? [], 'name'), true), '…and Long 50gm is not even a did-you-mean');
    ok(!in_array('Bari Elaichi 100gm', array_column($by['Ponam Sonf 100g']['suggestions'] ?? [], 'name'), true), 'Sonf 100g: no Bari Elaichi 100gm did-you-mean');
    // Garam Masala Whole / Dar Chini: products of those names now exist (P133, P134). Whatever the list holds,
    // the line lands on a product of THAT name — matched, offered or suggested — never on another 100g pack.
    $landsOn = function (array $l, string $word): bool {
        $names = array_merge([$l['matched'] ? ($l['product_name'] ?? '') : ''], array_column($l['suggestions'] ?? [], 'name'), [$l['ai_suggestion']['name'] ?? '']);
        $hits = array_filter($names, fn ($n) => $n !== '' && stripos(str_replace(['Daar Cheeni'], ['Dar Chini'], $n), $word) !== false);
        $bad  = array_filter($names, fn ($n) => $n !== '' && stripos($n, 'Bari Elaichi') !== false);
        return count($hits) >= 1 && count($bad) === 0;
    };
    ok($landsOn($by['Ponam Garam Masala Whole 100g'] ?? [], 'Garam Masala'), 'Garam Masala Whole → a garam masala product (matched or offered)',
        json_encode(array_intersect_key($by['Ponam Garam Masala Whole 100g'] ?? [], array_flip(['matched', 'product_name', 'suggestions']))));
    ok($landsOn($by['Ponam Dar Chini'] ?? [], 'Dar Chini'), 'Dar Chini → a dar chini product (matched, reader or did-you-mean)',
        json_encode(array_intersect_key($by['Ponam Dar Chini'] ?? [], array_flip(['matched', 'product_name', 'suggestions', 'ai_suggestion']))));
    ok(is_array($card['warnings'] ?? null) && count($card['warnings']) >= 1, 'the card carries its warnings sentence(s)');
    $draftId = $b['draft_id'] ?? null;
    ok((int) $draftId > 0, 'draft row created', json_encode($draftId));

    // ── 3. the "Continue it" list ───────────────────────────────────────────
    echo "3. GET /api/vendors/receipt-drafts\n";
    [$st, $b] = call('GET', '/api/vendors/receipt-drafts');
    $mine = array_values(array_filter($b['drafts'] ?? [], fn ($d) => (int) $d['draft_id'] === (int) $draftId));
    ok($st === 200 && count($mine) === 1, 'the draft is listed for Qasim');
    ok((int) ($mine[0]['lines'] ?? 0) === 26 && ($mine[0]['status'] ?? '') === 'draft', 'with 26 lines, status draft', json_encode($mine[0] ?? null));

    // ── 4. the money door, with a HAND-ADDED line ───────────────────────────
    echo "4. POST /api/vendors/50/receipt/record (rolled back)\n";
    $items = [
        ['product_id' => $onionId, 'quantity' => '10.03', 'rate' => '195', 'unit' => 'kg', 'product_name' => 'Onions (Piyaaz)', 'raw_name' => 'Fresh Onions'],
        ['product_id' => $maidaId, 'quantity' => '10', 'rate' => '165', 'unit' => 'kg', 'product_name' => 'Maida', 'raw_name' => 'Ponam Maida 1kg'],
        // ➕ added by hand on the card: no raw_name at all (exactly what the phone posts)
        ['product_id' => $saltId, 'quantity' => '800', 'rate' => '0.06', 'unit' => 'g', 'product_name' => 'Salt'],
    ];
    ok($onionId > 0 && $maidaId > 0 && $saltId > 0, "the three products exist on the replica (Onions P$onionId, Maida P$maidaId, Salt P$saltId)");
    $before = DB::table('t_fin_vendor_purchase_items')->count();
    [$st, $b] = call('POST', '/api/vendors/50/receipt/record', [
        'transaction_date' => date('Y-m-d'), 'description' => 'Receipt · Imtiaz', 'adjustment_amount' => '0',
        'draft_id' => (string) $draftId, 'client_uuid' => $uuid, 'items' => $items,
    ]);
    ok($st === 200 && ($b['success'] ?? false), 'responds 200 success', "$st " . mb_substr(json_encode($b), 0, 300));
    $tid = (int) ($b['transaction_id'] ?? 0);
    ok($tid > 0, 'a ledger transaction id came back', (string) $tid);
    $rows = DB::table('t_fin_vendor_purchase_items')->where('ledger_id', $tid)->get();
    ok(count($rows) === 3, '3 purchase items under that ledger row', (string) count($rows));
    $salt = $rows->firstWhere('vendor_product_id', $saltId);
    ok($salt && abs((float) $salt->quantity - 800) < 0.001 && abs((float) $salt->rate_per_unit - 0.06) < 0.0001 && abs((float) $salt->line_total - 48) < 0.01, '⭐ the hand-added Salt line is recorded: 800 × 0.06 = Rs 48', json_encode($salt));
    ok($salt && (int) $salt->ingredient_id === 33 && (string) $salt->product_name === 'Salt', '…stamped with its ingredient (Salt) and product name', json_encode($salt));
    $ledger = DB::table('t_fin_ledger')->where('id', $tid)->first();
    $expected = round(10.03 * 195 + 10 * 165 + 800 * 0.06, 2);
    ok($ledger && abs((float) $ledger->amount - $expected) < 1.0, "ledger amount ≈ Rs $expected", json_encode(['amount' => $ledger->amount ?? null]));
    $draft = DB::table('t_fin_receipt_draft')->where('id', $draftId)->first();
    ok(($draft->status ?? '') === 'submitted' && (int) ($draft->ledger_id ?? 0) === $tid, 'draft marked submitted, pointing at the ledger row');
    ok(is_array($b['learned'] ?? null), '"learned" list present (teaching never blocks the purchase)');

    // a second press of Record with the same uuid: nothing booked twice
    [$st2, $b2] = call('POST', '/api/vendors/50/receipt/record', [
        'transaction_date' => date('Y-m-d'), 'adjustment_amount' => '0', 'client_uuid' => $uuid, 'items' => $items,
    ]);
    ok($st2 === 200 && ($b2['already'] ?? false) === true && (int) ($b2['transaction_id'] ?? 0) === $tid, 'second Record with the same uuid → "already", same id');
    ok(DB::table('t_fin_vendor_purchase_items')->count() === $before + 3, 'still exactly 3 new purchase items');
} finally {
    DB::rollBack();
    @unlink($tmp);
}
ok(DB::table('t_fin_receipt_draft')->where('client_uuid', $uuid)->doesntExist(), 'rolled back: no draft row left behind');

echo "\nPASS $pass  FAIL $fail\n";
exit($fail ? 1 : 0);
