<?php
/**
 * PROOF — recipe FAMILIES, per PIECE (Oct-19-2026). Server side, all three phases.
 * Run: php test_recipe_families_oct19.php
 *
 * Real replica data, everything inside ONE transaction that is ROLLED BACK. No model calls.
 *   A  title rule, suggestions, did-you-mean
 *   B  the maths (gcd snapshot, one formula, one rounding)
 *   C  set-up of all 8 families: every existing pack's per-pack amounts UNCHANGED; 12-pack = exact multiple
 *   D  consumption per size from real stock-ins (8-pack vs 12-pack, exact)
 *   E  meat mapping shared across a family (join, 🔗 save, 🔗 delete)
 *   F  family recipe save → every size; older-app save through a pack; history
 *   G  join / leave / pieces / rename / bundle / non-Frozen / bad pieces
 *   H  Accept Demand: refuses unmapped, says what was taken
 *   I  End Batch by size + yield line
 *   J  Repack: conserved pieces, refusals, Month Review bucket
 *   K  Replace + unit-change through the family
 *   L  HTTP: web + API doors as Taimur / Qasim (Sanctum), product create with a family
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

use App\Models\CRM\ProductModel;
use App\Models\CRM\WarehouseInventoryModel;
use App\Models\Khaas\FamilyRecipeModel;
use App\Models\Khaas\IngredientModel;
use App\Models\Khaas\RecipeFamilyModel;
use App\Models\Khaas\RecipeModel;
use App\Services\Khaas\RecipeFamilyService as F;
use App\Services\Khaas\RecipeService;
use App\Services\Khaas\RepackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

$pass = 0; $fail = 0;
function ok($cond, $label, $extra = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; } else { $fail++; echo "  ✗ $label" . ($extra !== '' ? "  → $extra" : '') . "\n"; }
}
function call(string $method, string $uri, array $data = []): array {
    global $kernel;
    $req = Request::create($uri, $method, $data, [], [], ['HTTP_ACCEPT' => 'application/json']);
    $res = $kernel->handle($req);
    $b = json_decode($res->getContent(), true);
    return [$res->getStatusCode(), is_array($b) ? $b : ['_raw' => mb_substr((string) $res->getContent(), 0, 300)]];
}
/** per-pack quantities of a product's recipe on a date, by ingredient id */
function perPack(int $productId, ?string $date = null): array {
    $r = RecipeModel::currentFor($productId, $date ?? now()->toDateString());
    if (!$r) { return []; }
    $out = [];
    foreach ($r->lines()->get() as $l) { $out[(int) $l->ingredient_id] = round($r->perPacket($l), 3); }
    ksort($out);
    return $out;
}

$taimur = App\Models\User::find(68);
$qasim  = App\Models\User::find(91);
$app->instance('request', Request::create('/x', 'GET'));
auth()->setUser($taimur);
$svc = new F();

$famBefore  = F::ready() ? RecipeFamilyModel::count() : 0;
$snapBefore = F::ready() ? DB::table('t_crm_khaas_recipe')->whereNotNull('family_recipe_id')->count() : 0;
DB::beginTransaction();
try {
    ok(F::ready(), 'the family SQL is applied on this database');

    // The proof starts from "right after the SQL": if this database already has families (the owner ran
    // "Set up all" on dev), take them away INSIDE this transaction — rolled back at the end, untouched.
    if (DB::table('t_crm_khaas_recipe_family')->exists()) {
        $snapIds = DB::table('t_crm_khaas_recipe')->whereNotNull('family_recipe_id')->pluck('id')->all();
        $memberIds = DB::table('t_crm_prod_product')->whereNotNull('recipe_family_id')->pluck('id')->all();
        DB::table('t_crm_khaas_batch_consumption')->whereIn('recipe_id', $snapIds ?: [0])->delete();
        DB::table('t_crm_khaas_recipe_line')->whereIn('recipe_id', $snapIds ?: [0])->delete();
        DB::table('t_crm_khaas_recipe')->whereIn('id', $snapIds ?: [0])->delete();
        foreach ($memberIds as $mid) {
            $last = DB::table('t_crm_khaas_recipe')->where('product_id', $mid)->orderByDesc('effective_from')->orderByDesc('version')->first();
            DB::table('t_crm_khaas_recipe')->where('product_id', $mid)->update(['is_current' => 0]);
            if ($last) { DB::table('t_crm_khaas_recipe')->where('id', $last->id)->update(['is_current' => 1]); }
        }
        // the mapping the set-up fanned out to sizes that had none (the 12-pcs packs)
        DB::table('t_crm_khaas_product_recipe')->whereIn('khaas_product_id', $memberIds ?: [0])
            ->where('created_at', '>=', '2026-10-20 00:00:00')->delete();
        DB::table('t_crm_prod_product')->whereIn('id', $memberIds ?: [0])->update(['recipe_family_id' => null, 'pieces_per_pack' => null]);
        DB::table('t_crm_khaas_family_recipe')->delete();
        DB::table('t_crm_khaas_recipe_family')->delete();
        echo "  (families already on this database were set aside inside the transaction)\n";
    }

    // ── A. titles ────────────────────────────────────────────────────────────
    echo "A. titles, suggestions\n";
    ok(F::parseTitle('NF – Chicken Cheese Samosa (12 pcs) - Single Pack') === ['name' => 'Chicken Cheese Samosa', 'pieces' => 12], 'prefix + (12 pcs) + suffix');
    ok(F::parseTitle('Khaas - Beef Shami Kabab (6 pcs)') === ['name' => 'Beef Shami Kabab', 'pieces' => 6], 'Khaas prefix, (6 pcs)');
    ok(F::parseTitle('Chicken Tikka Samosa (24 pieces) family pack') === ['name' => 'Chicken Tikka Samosa', 'pieces' => 24], '"pieces" word, no prefix');
    ok(F::parseTitle('NF – Chicken Tikka Samosa - Family Pack') === ['name' => 'Chicken Tikka Samosa', 'pieces' => null], 'no size in brackets → name only, pieces null');
    ok(F::parseTitle('') === null && F::parseTitle(null) === null, 'empty title → null');
    ok(F::parseTitle('NF – X (0 pcs)')['pieces'] === null, '0 pcs is not a size');
    ok(F::normalise('  Chicken   CHEESE-samosa ') === F::normalise('chicken cheese samosa'), 'case, spacing, dash never make two families');

    // ── B. maths ─────────────────────────────────────────────────────────────
    echo "B. the maths\n";
    ok(F::scaleFor(80, 8) === ['basis_packets' => 10, 'scale' => 1], '80 pieces, 8-pack → 10 packs, ×1 (identical to v17)');
    ok(F::scaleFor(80, 12) === ['basis_packets' => 20, 'scale' => 3], '80 pieces, 12-pack → 20 packs, ×3');
    ok(F::scaleFor(48, 6) === ['basis_packets' => 8, 'scale' => 1], '48 pieces, 6-pack → 8 packs');
    ok(F::scaleFor(7, 12) === ['basis_packets' => 7, 'scale' => 12], 'coprime: 7 pieces, 12-pack → 7 packs ×12');
    foreach ([[80, 8], [80, 12], [72, 6], [72, 12], [136, 12], [7, 12], [1, 999]] as [$B, $P]) {
        $s = F::scaleFor($B, $P);
        $q = 1234.567;
        $viaSnap = round(round($q * $s['scale'], 3) / $s['basis_packets'], 6);
        $direct  = round($q * $P / $B, 6);
        ok(abs($viaSnap - $direct) < 0.000001, "snapshot maths = one formula for B=$B P=$P", "$viaSnap vs $direct");
    }
    ok(F::amountFor(1000, 12, 80) === 150.0 && F::amountFor(10, 12, 80) === 1.5 && F::amountFor(2, 12, 80) === 0.3, 'amountFor: 150 g, 1.5 g, 0.3 egg per 12-pack');

    // ── C. set-up of all 8 families ──────────────────────────────────────────
    echo "C. set-up\n";
    $plan = $svc->setupPlan(2);
    ok(count($plan['groups']) === 8 && count($plan['unparsed']) === 0, '8 families suggested, nothing unparsed', count($plan['groups']) . '/' . count($plan['unparsed']));
    $memberCount = array_sum(array_map(fn ($g) => count($g['members']), $plan['groups']));
    ok($memberCount === 15, '15 packs (bundles never offered)', (string) $memberCount);
    ok(!collect($plan['groups'])->contains(fn ($g) => $g['meat_conflict']), 'no family has two different meats today');

    $before = [];
    foreach ($plan['groups'] as $g) {
        foreach ($g['members'] as $m) { $before[$m['product_id']] = perPack($m['product_id']); }
    }
    $families = [];
    foreach ($plan['groups'] as $g) {
        $r = $svc->setupFamily(['name' => $g['name'], 'source_product_id' => $g['source_product_id'],
            'members' => array_map(fn ($m) => ['product_id' => $m['product_id'], 'pieces_per_pack' => $m['pieces_per_pack']], $g['members'])], 2, 68);
        $families[$g['key']] = ['id' => $r['family']['id'], 'members' => $g['members'], 'source' => $g['source_product_id']];
    }
    ok(RecipeFamilyModel::count() >= 8, 'families created');
    $same = 0; $sameTotal = 0; $multipleOk = true; $why = '';
    foreach ($families as $key => $f) {
        $src = $f['source'];
        if (!$src) { continue; }
        $srcPieces = collect($f['members'])->firstWhere('product_id', $src)['pieces_per_pack'];
        $sameTotal++;
        if (perPack($src) == $before[$src]) { $same++; } else { $why .= " $key"; }
        foreach ($f['members'] as $m) {
            if ($m['product_id'] === $src) { continue; }
            $k = $m['pieces_per_pack'] / $srcPieces;
            foreach (perPack($src) as $iid => $q) {
                $got = perPack($m['product_id'])[$iid] ?? null;
                if ($got === null || abs($got - round($q * $k, 3)) > 0.0015) { $multipleOk = false; $why .= " {$m['product_id']}:$iid=$got vs " . round($q * $k, 3); }
            }
        }
    }
    ok($same === $sameTotal && $sameTotal === 7, "every pack that had a recipe has the SAME per-pack amounts after set-up ($same/$sameTotal)", $why);
    ok($multipleOk, 'every other size = source per pack × (its pieces ÷ source pieces), to the gram', $why);
    $cc = $families['chicken cheese samosa'];
    $fr = $svc->familyRecipe($cc['id']);
    // Oct-20: the family carries the 8-pack's WHOLE history — one family version per date the 8-pack's
    //   recipe changed, each on that date (read from the replica, so a new prod edit cannot rot this)
    $srcDays = DB::table('t_crm_khaas_recipe')->where('product_id', 1313)->whereNull('family_recipe_id')
        ->distinct()->orderBy('effective_from')->pluck('effective_from')->map(fn ($d) => substr((string) $d, 0, 10))->values()->all();
    $famDays = FamilyRecipeModel::where('family_id', $cc['id'])->orderBy('version')->pluck('effective_from')->map(fn ($d) => $d->toDateString())->all();
    ok($famDays === $srcDays, 'family history = every date the 8-pack\'s recipe changed (' . count($srcDays) . ' versions, ' . ($srcDays[0] ?? '?') . ' → ' . end($srcDays) . ')', json_encode([$famDays, $srcDays]));
    $srcLatest = RecipeModel::where('product_id', 1313)->whereNull('family_recipe_id')->orderByDesc('effective_from')->orderByDesc('version')->first();
    ok($fr['basis_pieces'] === 80 && $fr['version'] === count($srcDays), "Chicken Cheese Samosa family: current = v" . count($srcDays) . ", 80 pieces (10 packs × 8)");
    ok(str_contains((string) $fr['note'], 'v' . $srcLatest->version) && str_contains((string) $fr['note'], '80 pieces'), 'its note says where it came from (the 8-pack\'s newest version)', (string) $fr['note']);
    $chicken = collect($fr['lines'])->firstWhere('ingredient_id', 1);
    ok($chicken['per_piece'] == 12.5 && $chicken['per_pack']['1313']['qty'] == 100.0 && $chicken['per_pack']['1489']['qty'] == 150.0, 'chicken: 12.5 g a piece, 100 g per 8, 150 g per 12');
    // the 12-pack (no recipe of its own) is covered on EVERY past day, by the version in force then
    foreach (['2026-10-16', $srcDays[0], '2026-10-01'] as $day) {
        $r12 = RecipeModel::currentFor(1489, $day);
        $fvDay = FamilyRecipeModel::currentFor($cc['id'], $day);
        ok($r12 && $fvDay && (int) $r12->family_recipe_id === (int) $fvDay->id, "12-pcs on $day: covered by the family version in force that day (v{$fvDay?->version})");
    }
    $r8 = RecipeModel::currentFor(1313, '2026-10-16'); $r12 = RecipeModel::currentFor(1489, '2026-10-16');
    $l8 = $r8->lines()->where('ingredient_id', 1)->first(); $l12 = $r12->lines()->where('ingredient_id', 1)->first();
    ok(abs($r12->perPacket($l12) - 1.5 * $r8->perPacket($l8)) < 0.0015, '…and on 16-Oct a 12-pack holds exactly 1.5 × that day\'s 8-pack chicken (' . round($r12->perPacket($l12), 3) . ' g)');
    ok(RecipeModel::currentFor(1313, '2026-10-16')?->version === 17, '8-pcs keeps v17 for 16-Oct (its own history untouched)');
    ok(RecipeModel::currentFor(1313)?->family_recipe_id !== null && RecipeModel::currentFor(1313)?->family_scale === 1, '8-pcs today: a family snapshot, scale 1');
    $aloo = $families['aloo samosa'];
    ok($svc->summary($aloo['id'])['recipe'] === null && count($svc->summary($aloo['id'])['members']) === 1, 'Aloo Samosa: a family of one, no recipe (it had none) — nothing invented');

    // ── D. consumption per size, from real stock-ins ─────────────────────────
    echo "D. consumption per size\n";
    $inv8  = WarehouseInventoryModel::getOrCreate(1313, ProductModel::find(1313)->variants()->first()->id, 2);
    $inv12 = WarehouseInventoryModel::getOrCreate(1489, ProductModel::find(1489)->variants()->first()->id, 2);
    $l8  = $inv8->adjustQuantity(3, 'stock_in', 'proof 8', null, null);
    $l12 = $inv12->adjustQuantity(2, 'stock_in', 'proof 12', null, null);
    $c8  = DB::table('t_crm_khaas_batch_consumption')->where('warehouse_log_id', $l8->id)->pluck('qty_base', 'ingredient_id')->map(fn ($v) => (float) $v)->all();
    $c12 = DB::table('t_crm_khaas_batch_consumption')->where('warehouse_log_id', $l12->id)->pluck('qty_base', 'ingredient_id')->map(fn ($v) => (float) $v)->all();
    ok(($c8[1] ?? null) === 300.0 && ($c12[1] ?? null) === 300.0, '3 × 8-pack and 2 × 12-pack (24 pieces each) consume the same 300 g chicken', json_encode([$c8[1] ?? null, $c12[1] ?? null]));
    $equal = true;
    foreach ($c8 as $iid => $q) { if (abs(($c12[$iid] ?? -1) - $q) > 0.0015) { $equal = false; } }
    ok($equal && count($c8) === count($c12) && count($c8) === 11, 'every one of the 11 lines matches: 24 pieces are 24 pieces, whatever the pack');
    ok(($c12[27] ?? null) === 0.6, 'eggs: 2 × 0.3 = 0.6 (fractions are exact, summed not rounded per pack)', json_encode($c12[27] ?? null));

    // ── E. meat mapping shared ───────────────────────────────────────────────
    echo "E. meat mapping\n";
    $meat = fn ($pid) => DB::table('t_crm_khaas_product_recipe')->where('khaas_product_id', $pid)->where('is_active', 1)
        ->whereNotNull('storage_product_id')->pluck('storage_product_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
    $twins = [1313 => 1489, 1314 => 1498, 1338 => 1507, 1316 => 1516, 1318 => 1525, 1331 => 1534, 1315 => 1543];
    $allCopied = true;
    foreach ($twins as $a => $b) { if (!$meat($b) || $meat($a) !== $meat($b)) { $allCopied = false; } }
    ok($allCopied, 'all seven 12-pcs packs now carry their twin\'s meat (they had NONE)');
    $wh = app(\App\Http\Controllers\CRM\WarehouseController::class);
    $resp = json_decode($wh->saveProductRecipe(new Request(['khaas_product_id' => 1489, 'storage_product_id' => 1339]))->getContent(), true);
    ok(in_array(1339, $meat(1313), true) && in_array(1339, $meat(1489), true), '🔗 a meat added on the 12-pcs is added to the 8-pcs too', $resp['message'] ?? '');
    ok(str_contains($resp['message'] ?? '', 'other size'), 'and the message says so', $resp['message'] ?? '');
    $rowId = DB::table('t_crm_khaas_product_recipe')->where('khaas_product_id', 1313)->where('storage_product_id', 1339)->value('id');
    $wh->deleteProductRecipe(new Request(['recipe_id' => $rowId]));
    ok(!in_array(1339, $meat(1313), true) && !in_array(1339, $meat(1489), true), '🔗 removed on the 8-pcs → removed on the 12-pcs');
    ok($meat(1489) === [1332], '…the family is back to chicken thigh only');

    // ── F. family save, older-app save, history ──────────────────────────────
    echo "F. family recipe saves\n";
    $rs = new RecipeService();
    $raw = collect($fr['lines'])->map(fn ($l) => ['ingredient_id' => $l['ingredient_id'], 'qty' => $l['qty_per_basis'], 'unit' => $l['base_unit'], 'is_optional' => $l['is_optional']])->all();
    $raw[0]['qty'] = 1100;   // chicken 1000 → 1100 g a batch
    $v0  = (int) $fr['version'];   // the history the set-up carried (Oct-20) — versions continue after it
    $fv2 = $svc->saveFamilyRecipe($cc['id'], 80, $rs->cleanLines($raw), null, 'proof edit', 68);
    ok($fv2->version === $v0 + 1, 'family v' . ($v0 + 1) . ' (next after the carried history)');
    ok(perPack(1313)[1] === 110.0 && perPack(1489)[1] === 165.0, 'both sizes follow at once: 110 g / 165 g chicken');
    ok(RecipeModel::where('family_recipe_id', $fv2->id)->count() === 2, 'one snapshot per size');
    ok(DB::table('t_crm_khaas_batch_consumption')->where('warehouse_log_id', $l12->id)->where('ingredient_id', 1)->value('qty_base') == 300, 'consumption already written keeps its version (300 g, not restated)');
    // an older app saves the 12-pcs pack: "20 packs" (its snapshot basis) with the snapshot lines
    $snap = RecipeModel::currentFor(1489);
    $oldAppLines = $snap->lines()->get()->map(fn ($l) => ['ingredient_id' => $l->ingredient_id, 'qty' => (float) $l->qty_per_basis, 'unit' => IngredientModel::find($l->ingredient_id)->base_unit])->all();
    $r = $rs->saveRecipe(1489, (int) $snap->basis_packets, $oldAppLines, null, null, 91);
    $fv3 = FamilyRecipeModel::where('family_id', $cc['id'])->where('is_current', 1)->first();
    ok($fv3->version === $v0 + 2 && $fv3->basis_pieces === 240, 'older app saving the 12-pcs pack → family v' . ($v0 + 2) . ', 20 packs × 12 = 240 pieces', $fv3->version . '/' . $fv3->basis_pieces);
    ok(perPack(1313)[1] === 110.0 && perPack(1489)[1] === 165.0, '…and nothing moved: 110 g / 165 g, exact');
    ok($r->family_recipe_id === $fv3->id, 'the old door gets back the pack\'s new snapshot');
    ok(count($svc->history($cc['id'])) === $v0 + 2, 'family history: ' . ($v0 + 2) . ' versions');
    try { $svc->saveFamilyRecipe($cc['id'], 0, $rs->cleanLines($raw), null, null, 68); ok(false, 'basis 0 refused'); } catch (\InvalidArgumentException $e) { ok(str_contains($e->getMessage(), 'pieces'), 'basis 0 refused in words', $e->getMessage()); }
    try { $rs->cleanLines([['ingredient_id' => 27, 'qty' => 1, 'unit' => 'kg']]); ok(false, 'kg of eggs refused'); } catch (\InvalidArgumentException $e) { ok(true, 'kg against a pieces ingredient refused (same door as a pack recipe)'); }

    // ── G. join / leave / pieces / rename / refusals ─────────────────────────
    echo "G. membership\n";
    $chk = fn ($fn) => (function () use ($fn) { try { $fn(); return null; } catch (\InvalidArgumentException $e) { return $e->getMessage(); } })();
    $bundleId = DB::table('t_crm_prod_product_bundle_item as b')->join('t_crm_prod_product as p', 'p.id', '=', 'b.bundle_product_id')->where('p.business_unit_id', 2)->value('b.bundle_product_id');
    ok(str_contains((string) $chk(fn () => $svc->assign((int) $bundleId, $cc['id'], 8, 68)), 'bundle'), 'a bundle cannot join a family');
    $meatProduct = ProductModel::where('business_unit_id', 1)->value('id');
    ok(str_contains((string) $chk(fn () => $svc->assign((int) $meatProduct, $cc['id'], 8, 68)), 'Frozen'), 'a non-Frozen product cannot join');
    ok(str_contains((string) $chk(fn () => $svc->assign(1489, $cc['id'], 0, 68)), 'pieces'), 'pieces 0 refused');
    ok(str_contains((string) $chk(fn () => $svc->readAssignment(['recipe_family_id' => $cc['id'], 'pieces_per_pack' => '8.5'], 2, false)), 'whole number'), 'pieces 8.5 refused');
    ok($svc->readAssignment(['title' => 'x'], 2, false) === null, 'no family keys at all = keep (older forms)');
    $res = $svc->assign(1489, $cc['id'], 10, 68);
    ok($res['snapshots'] === 1 && perPack(1489)[1] === round(1100 * 10 / 80, 3), 'pieces 12 → 10: new snapshot from today, 137.5 g', json_encode(perPack(1489)[1] ?? null));
    ok(RecipeModel::currentFor(1489, now()->toDateString())->effective_from->toDateString() === now()->toDateString(), '…effective from today');
    $svc->assign(1489, $cc['id'], 12, 68);
    $res = $svc->assign(1489, null, null, 68);
    ok(ProductModel::find(1489)->recipe_family_id === null && perPack(1489)[1] === 165.0, 'leaving keeps its last recipe as its own (165 g)');
    ok(!collect($svc->summary($cc['id'])['members'])->contains('product_id', 1489), 'and it is no longer listed in the family');
    $svc->assign(1489, $cc['id'], 12, 68);
    $svc->rename($cc['id'], 'Chicken Cheese Samosa (Classic)');
    ok(ProductModel::find(1489)->recipe_family_id === $cc['id'] && ProductModel::find(1489)->title === 'NF – Chicken Cheese Samosa (12 pcs) - Single Pack', 'renaming a family keeps its packs; titles untouched');
    ok(str_contains((string) $chk(fn () => $svc->rename($cc['id'], 'Beef Qeema Samosa')), 'already a family'), 'renaming onto another family\'s name refused');
    $svc->rename($cc['id'], 'Chicken Cheese Samosa');
    $sug = $svc->suggest('NF – Chicken Cheese Samosa (24 pcs) - Family Pack', 2);
    ok(($sug['match']['id'] ?? 0) === $cc['id'] && $sug['parsed']['pieces'] === 24, 'suggest: a new 24-pcs title finds the family and its pieces');
    $sug = $svc->suggest('NF – Chicken Reshmi Kabab (8 pcs)', 2);
    ok(!$sug['match'] && ($sug['did_you_mean'][0]['name'] ?? '') === 'Chicken Reshmi Kebab', '"Kabab" vs "Kebab" → did you mean Chicken Reshmi Kebab', json_encode($sug['did_you_mean']));
    $sug = $svc->suggest('NF – Chicken Tikka Samosa (8 pcs)', 2);
    ok(!$sug['match'] && !$sug['did_you_mean'], 'a genuinely new name: no match, no false did-you-mean');
    $new = $svc->createFamily('  chicken   tikka samosa ', 2, 68);
    ok($svc->createFamily('Chicken Tikka Samosa', 2, 68)->id === $new->id, 'creating the same name twice reuses the family');

    // ── H. Accept Demand ─────────────────────────────────────────────────────
    echo "H. Accept Demand\n";
    $mkDemand = function (array $items) {
        $id = DB::table('t_crm_khaas_production_demand')->insertGetId(['business_unit_id' => 2, 'demand_date' => now()->toDateString(),
            'status' => 'submitted', 'created_by' => 91, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($items as [$pid, $kg]) {
            DB::table('t_crm_khaas_production_demand_item')->insert(['demand_id' => $id, 'khaas_product_id' => $pid, 'quantity_kg' => $kg,
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        }
        return $id;
    };
    $tikka = ProductModel::find(1489)->replicate(['recipe_family_id', 'pieces_per_pack']);
    $tikka->title = 'NF – Chicken Tikka Samosa (8 pcs) - Single Pack (proof)';
    $tikka->save();
    $d = $mkDemand([[(int) $tikka->id, 1]]);
    $r = json_decode($wh->acceptDemand(new Request(), $d)->getContent(), true);
    ok(($r['success'] ?? true) === false && str_contains($r['message'] ?? '', 'no raw material from Storage') && str_contains($r['message'] ?? '', 'Tikka'), 'a product with no meat is REFUSED by name', $r['message'] ?? '');
    ok(DB::table('t_crm_khaas_production_demand')->where('id', $d)->value('status') === 'submitted', '…the demand stays submitted, nothing deducted');
    $thighBefore = (float) DB::table('t_crm_khaas_storage_inventory')->where('id', 23)->value('quantity_on_hand');
    $d2 = $mkDemand([[1489, 1.0]]);
    $r = json_decode($wh->acceptDemand(new Request(), $d2)->getContent(), true);
    ok(($r['success'] ?? false) === true, 'Chicken Cheese Samosa (12 pcs) 1 kg is accepted now (it inherited its meat)', $r['message'] ?? '');
    ok(str_contains($r['message'] ?? '', '1 kg') && str_contains($r['message'] ?? '', 'Chicken LEAN Thigh Boneless'), 'the message says what came off Storage', $r['message'] ?? '');
    ok(abs($thighBefore - 1.0 - (float) DB::table('t_crm_khaas_storage_inventory')->where('id', 23)->value('quantity_on_hand')) < 0.0005, 'Storage chicken thigh went down by exactly 1 kg');
    $batchId = (int) DB::table('t_crm_khaas_production_demand_item')->where('demand_id', $d2)->value('batch_id');
    ok($batchId > 0, 'a batch was started');

    // ── I. End Batch by size + yield ─────────────────────────────────────────
    echo "I. End Batch by size\n";
    $sizes = json_decode($wh->batchSizes(new Request(), $batchId)->getContent(), true);
    ok(($sizes['multi_size'] ?? false) && collect($sizes['sizes'])->pluck('pieces_per_pack')->all() === [8, 12], 'the batch offers both sizes, 8 and 12', json_encode($sizes['sizes'] ?? null));
    $bad = json_decode($wh->endBatch(new Request(['outputs' => [['product_id' => 1314, 'packs' => 1]]]), $batchId)->getContent(), true);
    ok(($bad['success'] ?? true) === false && str_contains($bad['message'] ?? '', 'family'), 'a size from ANOTHER family is refused', $bad['message'] ?? '');
    $bad = json_decode($wh->endBatch(new Request(['outputs' => [['product_id' => 1489, 'packs' => '1.5']]]), $batchId)->getContent(), true);
    ok(($bad['success'] ?? true) === false, '1.5 packs refused');
    $made12 = WarehouseInventoryModel::where('product_id', 1489)->sum('quantity');
    $made8  = WarehouseInventoryModel::where('product_id', 1313)->sum('quantity');
    $r = json_decode($wh->endBatch(new Request(['outputs' => [['product_id' => 1489, 'packs' => 4], ['product_id' => 1313, 'packs' => 2]]]), $batchId)->getContent(), true);
    ok(($r['success'] ?? false) === true, 'End Batch: 4 × 12 pcs + 2 × 8 pcs', $r['message'] ?? '');
    ok(WarehouseInventoryModel::where('product_id', 1489)->sum('quantity') - $made12 == 4 && WarehouseInventoryModel::where('product_id', 1313)->sum('quantity') - $made8 == 2, 'each size went into its own warehouse stock');
    ok(DB::table('t_crm_product_batch_output')->where('batch_id', $batchId)->count() === 2, 'two output rows kept for the story');
    $chickenG = (float) DB::table('t_crm_khaas_batch_consumption')->where('batch_id', $batchId)->where('ingredient_id', 1)->sum('qty_base');
    ok(abs($chickenG - (4 * 165 + 2 * 110)) < 0.001, '64 pieces of chicken: 4 × 165 + 2 × 110 = 880 g (both sizes, one recipe)', (string) $chickenG);
    ok(($r['batch']['yield']['taken_kg'] ?? null) == 1.0 && ($r['batch']['yield']['recipe_kg'] ?? null) == 0.88, 'yield: 1 kg taken at accept, recipe says 0.88 kg', json_encode($r['batch']['yield'] ?? null));
    ok(str_contains($r['message'] ?? '', '0.12 kg not in packs'), 'yield sentence: 0.12 kg not in packs', $r['message'] ?? '');
    ok((int) DB::table('t_crm_product_batch')->where('id', $batchId)->value('quantity_produced') === 6, 'batch quantity = 6 packs');
    // legacy single number still works for a batch with no family
    $bLegacy = \App\Models\CRM\ProductBatchModel::create(['product_id' => $tikka->id, 'business_unit_id' => 2, 'batch_number' => 'PROOF-LEGACY',
        'status' => 'in_progress', 'started_at' => now(), 'started_by' => 68]);
    $r = json_decode($wh->endBatch(new Request(['quantity_produced' => 3]), $bLegacy->id)->getContent(), true);
    ok(($r['success'] ?? false) && str_contains($r['message'] ?? '', '3 units'), 'a product with no family ends the old way (3 units)', $r['message'] ?? '');

    // ── J. Repack ────────────────────────────────────────────────────────────
    echo "J. Repack\n";
    $rp = new RepackService();
    $o = $rp->options(1489);
    ok($o['can_repack'] && $o['from']['pieces_per_pack'] === 12 && $o['to'][0]['pieces_per_pack'] === 8, 'options: 12-pcs can become 8-pcs');
    ok(RepackService::plan(3, 12, 8) === ['pieces' => 36, 'to_packs' => 4, 'loose' => 4], 'plan: 3 × 12 = 4 × 8 + 4 loose');
    $wh12 = (int) WarehouseInventoryModel::where('product_id', 1489)->sum('quantity');
    $wh8  = (int) WarehouseInventoryModel::where('product_id', 1313)->sum('quantity');
    ok(str_contains((string) $chk(fn () => $rp->repack(1489, 2, 1313, 2, 0, null, 68)), 'Pieces must add up'), '2 × 12 ≠ 2 × 8 refused with the right numbers');
    ok(str_contains((string) $chk(fn () => $rp->repack(1489, 2, 1313, 2, 8, null, 68)), 'another pack'), '8 loose = another pack, refused');
    ok(str_contains((string) $chk(fn () => $rp->repack(1489, 999, 1313, 1498, 4, null, 68)), 'Only'), 'more packs than the warehouse has, refused');
    ok(str_contains((string) $chk(fn () => $rp->repack(1489, 1, 1498, 1, 0, null, 68)), 'same recipe family'), 'across families refused');
    $res = $rp->repack(1489, 2, 1313, 3, 0, 'proof', 68);
    ok($res['from_warehouse'] === $wh12 - 2 && $res['to_warehouse'] === $wh8 + 3, '2 × 12 → 3 × 8: warehouse 12-pcs −2, 8-pcs +3');
    ok(DB::table('t_crm_warehouse_inventory_log')->where('reference_type', 'repack')->where('reference_id', $res['repack_id'])->where('change_type', 'adjustment')->count() === 2, 'two ADJUSTMENT logs (never stock_in)');
    ok(DB::table('t_crm_khaas_batch_consumption as c')->join('t_crm_warehouse_inventory_log as l', 'l.id', '=', 'c.warehouse_log_id')->where('l.reference_type', 'repack')->count() === 0, 'a repack consumes NO ingredients');
    $mr = (new \App\Services\Khaas\FrozenMonthService())->production(2, now()->format('Y-m'));
    $row12 = collect($mr['products'])->firstWhere('product_id', 1489);
    $row8  = collect($mr['products'])->firstWhere('product_id', 1313);
    ok(($row12['adjustments_repacked'] ?? null) === -2 && ($row8['adjustments_repacked'] ?? null) === 3, 'Month Review: 12-pcs −2 repacked, 8-pcs +3 repacked', json_encode([$row12['adjustments_repacked'] ?? null, $row8['adjustments_repacked'] ?? null]));
    ok(($row12['adjustments_counts'] ?? 0) === 0, '…and NOT counted as a stock correction');
    $res = $rp->repack(1489, 1, 1313, 1, 4, null, 68);
    ok(str_contains($res['message'], '4 loose'), '1 × 12 → 1 × 8 + 4 loose declared', $res['message']);

    // ── K. Replace + unit change through the family ──────────────────────────
    echo "K. Replace / unit change\n";
    // the oil the samosa family uses TODAY (Qasim swapped Cooking Oil for Canola on 20-Oct) — read, not pinned
    $oilId = collect($svc->familyRecipe($cc['id'])['lines'])->pluck('ingredient_id')->intersect([50, 24])->first();
    $oil = IngredientModel::find($oilId);
    $oilFams = FamilyRecipeModel::where('is_current', 1)->get()->filter(fn ($fv) => $fv->lines()->where('ingredient_id', $oilId)->exists())->count();
    $imp = (new \App\Services\Khaas\IngredientReplaceService())->impact($oil, null, 'ml');
    $ids = array_column($imp['recipes'], 'recipe_id');
    ok(count(array_filter($ids, fn ($i) => is_string($i) && str_starts_with($i, 'f'))) === $oilFams && $oilFams > 0 && count(array_filter($ids, 'is_int')) === 0, "Replace ({$oil->name}) lists the $oilFams oil FAMILIES once each, no per-size snapshots", json_encode($ids));
    $qty = [];
    foreach ($imp['recipes'] as $x) { $qty[$x['recipe_id']] = $x['suggested_qty']; }
    $newOil = (new RecipeService())->saveIngredient(['name' => 'Cooking Oil (ml proof)', 'base_unit' => 'ml', 'kind' => 'other'], 68, 2);
    $done = (new \App\Services\Khaas\IngredientReplaceService())->apply($oil->fresh(), $newOil, $qty, [], 68);
    ok($done['recipes'] === $oilFams, "Replace re-versioned $oilFams family recipes", json_encode($done));
    $ccOilNow = DB::table('t_crm_khaas_recipe_line as l')->join('t_crm_khaas_recipe as r', 'r.id', '=', 'l.recipe_id')
        ->where('r.product_id', 1489)->where('r.is_current', 1)->where('l.ingredient_id', $newOil->id)->value('l.qty_per_basis');
    ok($ccOilNow !== null && abs((float) $ccOilNow - 1500) < 0.001, '…and the 12-pcs snapshot follows (family is 240 pieces since the older-app save: 12-pack scale 1, 1500 ml ÷ 20 packs)', (string) $ccOilNow);
    ok(abs(perPack(1489)[$newOil->id] - 75.0) < 0.0005 && abs(perPack(1313)[$newOil->id] - 50.0) < 0.0005, 'oil per pack: 50 ml (8) / 75 ml (12)');
    // unit change: a fresh ingredient used only in a family
    $pep = (new RecipeService())->saveIngredient(['name' => 'Proof Pepper', 'base_unit' => 'g', 'kind' => 'dry'], 68, 2);
    $cur = $svc->familyRecipe($cc['id']);
    $lines = collect($cur['lines'])->map(fn ($l) => ['ingredient_id' => $l['ingredient_id'], 'qty' => $l['qty_per_basis'], 'unit' => $l['base_unit']])->all();
    $lines[] = ['ingredient_id' => $pep->id, 'qty' => 24, 'unit' => 'g'];
    $svc->saveFamilyRecipe($cc['id'], (int) $cur['basis_pieces'], $rs->cleanLines($lines), null, null, 68);
    $u = new \App\Services\Khaas\IngredientUnitChangeService();
    $ui = $u->impact($pep, 'pcs');
    $fl = collect($ui['recipe_lines'])->first(fn ($l) => is_string($l['id']) && str_starts_with($l['id'], 'f'));
    ok($fl && collect($ui['recipe_lines'])->every(fn ($l) => is_string($l['id'])), 'unit change lists the FAMILY line ("f…"), never the size snapshots', json_encode(array_column($ui['recipe_lines'], 'id')));
    $u->apply($pep->fresh(), 'pcs', [$fl['id'] => 6], [], [], 68);
    ok(abs(perPack(1489)[$pep->id] - 6 * 12 / 240) < 0.0005 && abs(perPack(1313)[$pep->id] - 6 * 8 / 240) < 0.0005, 'after the change both sizes read the retyped family line (0.3 / 0.2 pcs a pack)', json_encode([perPack(1489)[$pep->id] ?? null, perPack(1313)[$pep->id] ?? null]));

    // ── L. HTTP doors ────────────────────────────────────────────────────────
    echo "L. HTTP (web session + phone Sanctum)\n";
    Sanctum::actingAs($qasim);
    [$st, $b] = call('GET', '/api/warehouse/recipe-families?business_unit_id=2');
    ok($st === 200 && count($b['families'] ?? []) >= 9, 'API families: 9 (8 + Tikka)', (string) count($b['families'] ?? []));
    [$st, $b] = call('GET', '/api/warehouse/recipe-family?product_id=1489&business_unit_id=2');
    ok($st === 200 && ($b['recipe']['family']['name'] ?? '') === 'Chicken Cheese Samosa' && isset($b['costs']['1489'], $b['costs']['1313']), 'API family by product: name + a cost per size');
    $c8c = $b['costs']['1313']['per_pack'] ?? null; $c12c = $b['costs']['1489']['per_pack'] ?? null;
    ok($c8c !== null && $c12c !== null && abs($c12c - 1.5 * $c8c) <= 0.02, 'cost per 12-pack = 1.5 × per 8-pack (± 2 paisa rounding)', "$c8c / $c12c");
    [$st, $b] = call('GET', '/api/warehouse/recipe?product_id=1489&business_unit_id=2');
    ok($st === 200 && ($b['recipe']['family']['name'] ?? '') === 'Chicken Cheese Samosa' && ($b['recipe']['family']['pieces_per_pack'] ?? 0) === 12, 'old recipe door now names the family (an older app keeps working)');
    [$st, $b] = call('GET', '/api/warehouse/recipe-family/suggest?title=' . urlencode('NF – Beef Qeema Samosa (24 pcs)') . '&business_unit_id=2');
    ok($st === 200 && ($b['match']['name'] ?? '') === 'Beef Qeema Samosa' && ($b['parsed']['pieces'] ?? 0) === 24, 'API suggest');
    [$st, $b] = call('GET', '/api/warehouse/products/demand?business_unit_id=2');
    [$st, $b] = call('GET', '/api/warehouse/demand/products?business_unit_id=2');
    $tk = collect($b['products'] ?? [])->firstWhere('khaas_product_id', (int) $tikka->id);
    ok($st === 200 && str_contains((string) ($tk['warning'] ?? ''), 'No raw material from Storage set'), 'demand picker flags the product with no meat', json_encode($tk['warning'] ?? null));
    // 🍱 the plan is by family: the 12-pcs is NOT its own row — the family's row (on the 8-pcs anchor)
    //    carries the family's name, both sizes, the kitchen recipe and no warning
    $c12 = collect($b['products'] ?? [])->firstWhere('khaas_product_id', 1489);
    $cf  = collect($b['products'] ?? [])->firstWhere('khaas_product_id', 1313);
    ok($c12 === null && ($cf['product_name'] ?? '') === 'Chicken Cheese Samosa' && in_array('12 pcs', $cf['sizes'] ?? [], true)
        && array_key_exists('warning', $cf ?? []) && $cf['warning'] === null && ($cf['has_kitchen_recipe'] ?? false),
        'the 12-pcs is covered by its family\'s ONE row: family name, both sizes, recipe, no warning', json_encode($cf ? array_intersect_key($cf, array_flip(['product_name', 'sizes', 'warning', 'has_kitchen_recipe'])) : null));
    [$st, $b] = call('GET', '/api/warehouse/repack/options?product_id=1489');
    ok($st === 200 && ($b['can_repack'] ?? false), 'API repack options');
    [$st, $b] = call('POST', '/api/warehouse/repack', ['from_product_id' => 1489, 'from_packs' => 1, 'to_product_id' => 1313, 'to_packs' => 1, 'loose_pieces' => 4]);
    ok($st === 200 && ($b['success'] ?? false), 'API repack', mb_substr(json_encode($b), 0, 160));
    [$st, $b] = call('POST', '/api/warehouse/repack', ['from_product_id' => 1489, 'from_packs' => 'x', 'to_product_id' => 1313, 'to_packs' => 1]);
    ok($st === 422, 'API repack with junk packs → 422');
    // product create from the phone, with a family
    $sku = 'PROOF-' . substr(md5(microtime()), 0, 6);
    [$st, $b] = call('POST', '/api/products', ['title' => 'NF – Chicken Cheese Samosa (24 pcs) - Family Pack', 'status' => 'active', 'business_unit_id' => 2,
        'sell_unit' => 'pcs', 'recipe_family_id' => $cc['id'], 'pieces_per_pack' => 24,
        'variants' => [['title' => 'x', 'sku' => $sku, 'price' => 1400, 'inventory_quantity' => 0]]]);
    $newId = (int) ($b['product']['id'] ?? 0);
    ok($st === 200 && $newId > 0 && str_contains($b['message'] ?? '', 'Part of Chicken Cheese Samosa'), 'phone create with a family: saved and joined', mb_substr(json_encode($b['message'] ?? $b), 0, 200));
    ok(abs((perPack($newId)[1] ?? 0) - 2 * 165) < 0.0005, '…costed from day one: 330 g chicken a 24-pack', json_encode(perPack($newId)[1] ?? null));
    ok($meat($newId) === [1332], '…and it deducts the family\'s chicken at accept from day one');
    [$st, $b] = call('POST', '/api/products', ['title' => 'NF – Bad (8 pcs)', 'status' => 'active', 'business_unit_id' => 2,
        'recipe_family_id' => $cc['id'], 'pieces_per_pack' => 0, 'variants' => [['price' => 1, 'inventory_quantity' => 0]]]);
    ok($st === 422 && str_contains(json_encode($b['errors'] ?? []), 'pieces'), 'phone create with pieces 0 → 422, nothing saved', json_encode($b['errors'] ?? $b));
    ok(!ProductModel::where('title', 'NF – Bad (8 pcs)')->exists(), '…no half-saved product');
    [$st, $b] = call('PUT', '/api/products/' . $newId, ['title' => 'NF – Chicken Cheese Samosa (24 pcs) - Family Pack', 'status' => 'active',
        'variants' => [['id' => ProductModel::find($newId)->variants()->first()->id, 'title' => 'NF – Chicken Cheese Samosa (24 pcs) - Family Pack', 'sku' => $sku, 'price' => 1400, 'inventory_quantity' => 0]]]);
    ok($st === 200 && (int) ProductModel::find($newId)->recipe_family_id === $cc['id'], 'phone edit WITHOUT family keys (older app) keeps the family');
    [$st, $b] = call('POST', '/api/warehouse/recipe-family/recipe', ['family_id' => $cc['id'], 'basis_pieces' => 0, 'lines' => []]);
    ok($st === 422, 'API family save with 0 pieces → 422');
    $bad = App\Models\User::whereNotIn('id', [68, 91])->get()->first(fn ($u) => !$u->hasMobilePermission('manage_khaas_recipes') && $u->hasMobilePermission('access_khaas_mode'));
    if ($bad) {
        Sanctum::actingAs($bad);
        [$st, $b] = call('POST', '/api/warehouse/recipe-family/recipe', ['family_id' => $cc['id'], 'basis_pieces' => 80, 'lines' => [['ingredient_id' => 1, 'qty' => 1, 'unit' => 'kg']]]);
        ok($st === 403, "someone without manage_khaas_recipes ({$bad->fullname}) cannot change a family recipe");
    }

    // ── M. THE PLAN IS BY FAMILY (owner, Oct-19): one line per preparation, sizes at the end ──
    echo "M. the plan is by family\n";
    Sanctum::actingAs(App\Models\User::find(91));   // Qasim
    // a pack that is in NO family at this point of the proof (earlier sections set families up)
    $loner = \App\Services\CRM\ProductBundle::excludeBundles(ProductModel::where('business_unit_id', 2)->where('is_active', 1)->where('status', 'active')->whereNull('recipe_family_id'))->orderBy('id')->first();
    $lonerId = (int) ($loner->id ?? 0);
    $names = $svc->planNames(array_filter([1313, 1489, $newId, $lonerId]));
    ok($names[1313]['name'] === 'Chicken Cheese Samosa' && $names[1489]['name'] === 'Chicken Cheese Samosa' && $names[$newId]['name'] === 'Chicken Cheese Samosa',
        'planNames: every size of the family is called by the family', json_encode($names));
    ok($names[1313]['sku_name'] === 'NF – Chicken Cheese Samosa (8 pcs) - Single Pack' && (!$lonerId || ($names[$lonerId]['family_id'] === null && $names[$lonerId]['name'] === $loner->title)),
        '…the pack\'s own title is kept as sku_name; a pack in no family keeps its title' . ($lonerId ? " ({$loner->title})" : ' (none left to check)'));
    [$st, $b] = call('GET', '/api/warehouse/demand/products?business_unit_id=2');
    $rows = collect($b['products'] ?? []);
    $famRow = $rows->firstWhere('product_name', 'Chicken Cheese Samosa');
    ok($st === 200 && $famRow && $famRow['is_family'] === true && (int) $famRow['khaas_product_id'] === 1313,
        'demand picker: ONE row for the family, standing on its smallest size (the anchor, 8 pcs)', json_encode($famRow ? array_intersect_key($famRow, array_flip(['khaas_product_id', 'is_family'])) : null));
    ok(($famRow['sizes'] ?? null) === ['8 pcs', '12 pcs', '24 pcs'], '…naming every active size, smallest first', json_encode($famRow['sizes'] ?? null));
    ok(!$rows->contains('khaas_product_id', 1489) && !$rows->contains('khaas_product_id', $newId), '…and the 12-pcs / 24-pcs packs are NOT separate rows');
    ok(!$lonerId || $rows->contains(fn ($r) => (int) $r['khaas_product_id'] === $lonerId && $r['is_family'] === false), '…a pack in no family is its own row still');
    [$st, $b] = call('POST', '/api/warehouse/demand/create', ['business_unit_id' => 2, 'demand_date' => '2026-10-22',
        'items' => [['khaas_product_id' => 1313, 'quantity_kg' => 3], ['khaas_product_id' => 1489, 'quantity_kg' => 2]]]);
    ok($st === 422 && str_contains($b['message'] ?? '', 'One line per preparation: Chicken Cheese Samosa is on this plan 2 times (8 pcs, 12 pcs)'),
        'two sizes of one family on a plan → refused (the meat would be taken twice)', json_encode($b['message'] ?? $b));
    ok(!DB::table('t_crm_khaas_production_demand')->where('demand_date', '2026-10-22')->exists(), '…nothing saved');
    [$st, $b] = call('POST', '/api/warehouse/demand/create', ['business_unit_id' => 2, 'demand_date' => '2026-10-22',
        'items' => [['khaas_product_id' => 1313, 'quantity_kg' => 1]]]);
    $planId = (int) ($b['demand_id'] ?? 0);
    ok($st === 200 && $planId > 0, 'one line per preparation → saved');
    // earlier sections took the replica's chicken; put 10 kg on the Storage shelf (rolled back with the rest)
    DB::table('t_crm_khaas_storage_inventory')->where('source_product_id', 1332)->where('khaas_business_unit_id', 2)->increment('quantity_on_hand', 10);
    [$st, $b] = call('POST', "/api/warehouse/demand/{$planId}/update", ['items' => [['khaas_product_id' => 1313, 'quantity_kg' => 1], ['khaas_product_id' => $newId, 'quantity_kg' => 1]]]);
    ok($st === 422 && str_contains($b['message'] ?? '', '8 pcs, 24 pcs'), 'editing a plan into two sizes of one family → refused too');
    [$st, $b] = call('GET', '/api/warehouse/demand/list?business_unit_id=2');
    $mine = collect($b['demands'] ?? [])->firstWhere('id', $planId);
    $line = collect($mine['items'] ?? [])->firstWhere('khaas_product_id', 1313);
    ok($line && $line['product_name'] === 'Chicken Cheese Samosa' && $line['family_name'] === 'Chicken Cheese Samosa' && str_contains($line['sku_name'], '(8 pcs)'),
        'the plan list names the line by its family (sku_name keeps the pack)', json_encode($line ? array_intersect_key($line, array_flip(['product_name', 'sku_name', 'family_name'])) : null));
    [$st, $b] = call('POST', "/api/warehouse/demand/{$planId}/accept");
    ok($st === 200 && str_contains($b['message'] ?? '', '• Chicken Cheese Samosa: 1 kg'), 'accept names the family in "taken from Storage"', json_encode($b['message'] ?? $b));
    $batchId = (int) DB::table('t_crm_khaas_production_demand_item')->where('demand_id', $planId)->where('khaas_product_id', 1313)->value('batch_id');
    [$st, $b] = call('GET', "/api/warehouse/batch/{$batchId}/sizes");
    ok($st === 200 && (float) $b['taken_kg'] === 1.0 && $b['has_recipe'] === true && count($b['sizes']) === 3, 'sizes: the meat taken (1 kg), a recipe, every size', json_encode(array_intersect_key($b, array_flip(['taken_kg', 'has_recipe']))));
    $s8  = collect($b['sizes'])->firstWhere('product_id', 1313);
    $s12 = collect($b['sizes'])->firstWhere('product_id', 1489);
    // (section D edited the family recipe, so the chicken figure is whatever is in force TODAY — the
    //  12-pack must carry exactly 1.5 × the 8-pack, and "packs from 1 kg" is floor(1000 ÷ meat per pack))
    ok($s8['meat_g_per_pack'] > 0 && abs($s12['meat_g_per_pack'] - 1.5 * $s8['meat_g_per_pack']) < 0.001
        && $s8['packs_from_taken'] === (int) floor(1000 / $s8['meat_g_per_pack']) && $s12['packs_from_taken'] === (int) floor(1000 / $s12['meat_g_per_pack']),
        "per size: {$s8['meat_g_per_pack']} g / {$s12['meat_g_per_pack']} g of chicken a pack (12 = 1.5 × 8); 1 kg ≈ {$s8['packs_from_taken']} eights or {$s12['packs_from_taken']} twelves",
        json_encode([$s8['meat_g_per_pack'], $s12['meat_g_per_pack'], $s8['packs_from_taken'], $s12['packs_from_taken']]));
    $maida8  = collect($s8['lines'])->firstWhere('name', 'Maida');
    $maida12 = collect($s12['lines'])->firstWhere('name', 'Maida');
    ok($maida8 && $maida12 && abs($maida12['per_pack'] - 1.5 * $maida8['per_pack']) < 0.0005 && $maida8['text'] === '150 g' && $maida12['text'] === '225 g',
        'every ingredient per pack, 12-pack = 1.5 × 8-pack (Maida 150 g / 225 g)', json_encode([$maida8['per_pack'] ?? null, $maida12['per_pack'] ?? null]));
    ok(count($s8['lines']) === count(RecipeModel::currentFor(1313)->lines()->get()), '…all of the recipe\'s lines, not just meat');
    // a hand-started batch (no plan): no taken_kg, the lines still there
    [$st, $b] = call('POST', '/api/warehouse/batch/start', ['product_id' => 1489, 'business_unit_id' => 2]);
    $handBatch = (int) ($b['batch']['id'] ?? $b['batch_id'] ?? 0);
    if ($handBatch) {
        [$st, $b] = call('GET', "/api/warehouse/batch/{$handBatch}/sizes");
        ok($st === 200 && $b['taken_kg'] === null && count($b['sizes'][0]['lines'] ?? []) > 0, 'a batch started by hand: no meat taken to compare, the usage lines still come');
    }

    // ── N. 🥔 a preparation with NOTHING from Storage still has a way out of the plan ──
    echo "N. nothing from Storage (all from the shelf)\n";
    Sanctum::actingAs(App\Models\User::find(68));
    $aloo = ProductModel::where('business_unit_id', 2)->where('title', 'like', '%Aloo Samosa%')->where('is_active', 1)->first();
    ok($aloo && DB::table('t_crm_khaas_product_recipe')->where('khaas_product_id', $aloo->id)->where('is_active', 1)->whereNotNull('storage_product_id')->exists(),
        'Aloo Samosa today: its plan kilos are POTATO, taken from Storage like meat (mapping to KW - Potato)');
    // make it a shelf-only preparation for the proof: no mapping, a recipe of maida + salt only
    DB::table('t_crm_khaas_product_recipe')->where('khaas_product_id', $aloo->id)->update(['is_active' => 0]);
    $maida = IngredientModel::where('name', 'Maida')->first();
    $salt  = IngredientModel::where('name', 'Salt')->first();
    $rs->saveRecipe((int) $aloo->id, 10, [['ingredient_id' => $maida->id, 'qty' => 1.5, 'unit' => 'kg'], ['ingredient_id' => $salt->id, 'qty' => 20, 'unit' => 'g']], null, 'shelf-only proof', 68);
    ok(empty(array_filter($svc->perPackLines((int) $aloo->id), fn ($l) => $l['is_meat'])), '…proof set-up: no mapping, a recipe with no Storage-counted line');
    [$st, $b] = call('GET', '/api/warehouse/demand/products?business_unit_id=2');
    $ar = collect($b['products'] ?? [])->firstWhere('khaas_product_id', (int) $aloo->id);
    ok($ar && $ar['warning'] === null && str_contains((string) $ar['note'], 'Nothing from Storage'), 'demand picker: no warning, a note that everything comes from the shelf', json_encode($ar ? array_intersect_key($ar, array_flip(['warning', 'note'])) : null));
    [$st, $b] = call('POST', '/api/warehouse/demand/create', ['business_unit_id' => 2, 'demand_date' => '2026-10-23', 'items' => [['khaas_product_id' => $aloo->id, 'quantity_kg' => 2]]]);
    $shelfPlan = (int) ($b['demand_id'] ?? 0);
    [$st, $b] = call('POST', "/api/warehouse/demand/{$shelfPlan}/accept");
    ok($st === 200 && str_contains($b['message'] ?? '', 'nothing from Storage — its recipe has no meat or potato'), 'ACCEPTED with nothing taken, and the message says so', json_encode($b['message'] ?? $b));
    $shelfBatch = (int) DB::table('t_crm_khaas_production_demand_item')->where('demand_id', $shelfPlan)->value('batch_id');
    ok($shelfBatch > 0 && (float) DB::table('t_crm_khaas_production_demand_item')->where('demand_id', $shelfPlan)->value('storage_deducted_qty') == 0, '…its batch started, 0 kg off Storage');
    [$st, $b] = call('POST', "/api/warehouse/batch/{$shelfBatch}/end", ['outputs' => [['product_id' => $aloo->id, 'packs' => 10]]]);
    $rows = DB::table('t_crm_khaas_batch_consumption')->where('batch_id', $shelfBatch)->get();
    ok($st === 200 && $rows->count() === 2 && abs((float) $rows->firstWhere('ingredient_id', $maida->id)->qty_base - 1500) < 0.001, 'End Batch 10 packs → maida 1.5 kg and salt counted from the shelf', json_encode($rows->pluck('qty_base', 'ingredient_id')));
    ok(str_contains($b['message'] ?? '', 'no recipe for these packs') === false, '…and no bogus "no recipe" yield line');
    // the refusal still stands for a pack with no mapping and no recipe, and for one whose recipe WANTS meat
    DB::table('t_crm_khaas_recipe')->where('product_id', $aloo->id)->delete();
    [$st, $b] = call('POST', '/api/warehouse/demand/create', ['business_unit_id' => 2, 'demand_date' => '2026-10-24', 'items' => [['khaas_product_id' => $aloo->id, 'quantity_kg' => 1]]]);
    [$st, $b] = call('POST', "/api/warehouse/demand/{$b['demand_id']}/accept");
    ok($st === 400 && str_contains($b['message'] ?? '', 'no raw material from Storage (meat, potato…) is set for: ') && str_contains($b['message'] ?? '', 'Aloo Samosa'), 'no mapping AND no recipe → still refused (named by its family), naming both ways out', json_encode($b['message'] ?? $b));
    ok(str_contains($b['message'] ?? '', 'write its recipe if nothing in it comes from Storage'), '…including "write its recipe"');

    // ── O. the product CARD counts every pack made, like Month Review (Oct-20) ──
    echo "O. card made-this-month = Month Review\n";
    $month = now()->format('Y-m');
    $card = (new \App\Services\Khaas\FrozenCostingService())->cardMonth(2, $month);
    $prodRows = collect((new \App\Services\Khaas\FrozenMonthService())->production(2, $month)['products'])->keyBy('product_id');
    $mismatch = [];
    foreach ($prodRows as $pid => $p) {
        if ((int) $p['stock_in'] > 0 && (($card[(int) $pid]['made'] ?? null) !== (int) $p['stock_in'] || $card[(int) $pid]['made_plan'] !== (int) $p['stock_in_batch'])) {
            $mismatch[] = $pid;
        }
    }
    ok(!$mismatch && array_sum(array_column($card, 'made')) === (int) $prodRows->sum('stock_in'), 'card "made" = Month Review stock-ins for every product, same plan/direct split', json_encode($mismatch));
    $broken = array_filter($card, fn ($c) => $c['made_no_recipe'] !== max(0, $c['made'] - $c['costed']) || ($c['costed'] === 0) !== ($c['cost_per_pack'] === null));
    ok(!$broken, 'made_no_recipe = made − costed; no estimate without a costed pack', json_encode(array_keys($broken)));
    [$st, $b] = call('GET', '/api/warehouse/product-costs?business_unit_id=2');
    $one = collect($b['costs'] ?? [])->first();
    ok($st === 200 && is_array($one) && array_key_exists('made_no_recipe', $one) && array_key_exists('costed', $one), 'phone endpoint carries made_no_recipe + costed (same method as the web card)');
} finally {
    DB::rollBack();
}
ok(RecipeFamilyModel::count() === $famBefore && DB::table('t_crm_khaas_recipe')->whereNotNull('family_recipe_id')->count() === $snapBefore, "rolled back: the database has exactly what it had before ($famBefore families, $snapBefore snapshots)");

echo "\nPASS $pass  FAIL $fail\n";
exit($fail ? 1 : 0);
