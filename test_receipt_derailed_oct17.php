<?php
/**
 * PROOF — a derailed bill-reader reply is a FAILED attempt, not a one-line success (Oct-17).
 * Run: php test_receipt_derailed_oct17.php
 *
 * Qasim's 26-line Imtiaz slip came back as ONE line: the model wrote its reasoning into
 * the first price ("195.00Pool Number field values like…"), closed the array, STOP. Valid
 * JSON, so it passed. Now that reply gets the one retry, and a successful read leaves one
 * log line. (Same day, later: the product lists moved to a separate text-only call, so
 * the photo call never carries them — see test_receipt_two_call_match_oct17.php.)
 *
 * The HTTP layer is faked — NO live model call, NO credits.
 * ⚠ Traps: Http::sequence()->push(body, status); a fresh Http factory per case (a second
 *   Http::fake() stacks on the exhausted sequence); Log::spy() reuses one spy, so swap a new one.
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Khaas\ReceiptExtractionService as R;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

$pass = 0; $fail = 0;
function ok($cond, $label, $extra = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; } else { $fail++; echo "  ✗ $label" . ($extra !== '' ? "  → $extra" : '') . "\n"; }
}
function fresh(): void {
    Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
    Log::swap(Mockery::spy(\Illuminate\Log\LogManager::class));
}
function sent(): array { $s = []; Http::assertSent(function ($r) use (&$s) { $s[] = $r; return true; }); return $s; }
function imageSchemaHas($request, string $field): bool {
    return isset($request->data()['generationConfig']['responseSchema']['properties']['lines']['items']['properties'][$field]);
}
function hasImage($request): bool {
    foreach ($request->data()['contents'][0]['parts'] ?? [] as $p) { if (isset($p['inline_data'])) return true; }
    return false;
}

// ── fixtures ────────────────────────────────────────────────────────────────
config(['assistant.gemini.api_key' => 'test-key-not-real', 'whatsapp.media_disk' => 'public']);
Storage::fake('public');
$path = 'receipts/test/derailed.jpg';
Storage::disk('public')->put($path, base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='));

$gemini = fn (string $json, string $finish = 'STOP', int $out = 500) => [
    'candidates'    => [['finishReason' => $finish, 'content' => ['parts' => [['text' => $json]]]]],
    'usageMetadata' => ['promptTokenCount' => 1500, 'candidatesTokenCount' => $out],
];

// Exactly what prod returned on Oct-17 (dev reproduction, 116 tokens out).
$derailed = json_encode(['lines' => [[
    'raw_name'   => 'Fresh Onions',
    'qty'        => '10.03',
    'unit_price' => '195.00Pool Number field values like: 195.00 string formatted? Wait schema: unit_price string or null. Yes string: "195.00". Let\'s write strings for all numbers as instructed in schema/prompt. 195.00',
]]]);
$line = fn ($n, $q, $p, $t) => ['raw_name' => $n, 'qty' => $q, 'unit_price' => $p, 'line_total' => $t, 'discount' => '0.00', 'sold_by' => 'weight', 'pack_size_unit' => null, 'pack_size_value' => null];
$clean = json_encode([
    'lines' => [$line('Fresh Onions', '10.03', '195.00', '1955.46'), $line('Fresh Tomatoes', '1.22', '175.00', '213.15'), $line('Sugar 1kg', '2.00', '149.00', '298.00')],
    'grand_total' => '2466.61', 'confidence' => 'high', 'store_name' => 'Imtiaz',
]);
$matchReply = json_encode(['matches' => [['line' => '1', 'match' => 'P91', 'ingredient' => 'I18'], ['line' => '2', 'match' => 'NONE', 'ingredient' => 'NONE'], ['line' => '3', 'match' => 'NONE', 'ingredient' => 'NONE']]]);
$hints = ['products' => [['id' => 91, 'name' => 'Onion (Pyaz)', 'unit' => 'kg']], 'ingredients' => [['id' => 18, 'name' => 'Onion']]];

// ── 1. derailed first reply → retry → clean read → matching call ────────────
echo "1. derailed reply → retried → clean read, then matched\n";
fresh();
Http::fake(['*' => Http::sequence()->push($gemini($derailed, 'STOP', 116))->push($gemini($clean))->push($gemini($matchReply, 'STOP', 40))]);
$r = (new R())->withHints($hints);
$read = $r->extract($path);
ok(is_array($read), 'extract() returned a reading');
ok(count($read['lines'] ?? []) === 3, '3 clean lines (not the derailed one)', json_encode(array_column($read['lines'] ?? [], 'raw_name')));
ok(($read['lines'][0]['unit_price'] ?? null) === 195.0 && ($read['lines'][0]['line_total'] ?? null) === 1955.46, 'first line is the clean onion line');
ok(($read['grand_total'] ?? null) === 2466.61, 'grand total read');
ok($r->lastFailure() === null, 'lastFailure() is null after the good retry');
Http::assertSentCount(3);
$s = sent();
ok(hasImage($s[0]) && hasImage($s[1]) && !imageSchemaHas($s[0], 'match') && !imageSchemaHas($s[1], 'match'), 'both photo calls carried NO enum lists');
ok((float) ($s[1]->data()['generationConfig']['temperature'] ?? -1) === 0.2, 'request 2 nudged the temperature (same as the loop retry)');
ok(!hasImage($s[2]) && ($read['lines'][0]['ai_match'] ?? null) === 91, 'request 3 = text-only matching, answer landed');
ok($read['hinted_products'] === true, 'suggestions survive a derailed first try');
Log::shouldHaveReceived('warning')->with('ReceiptExtraction: reply derailed (model lost its place)', Mockery::on(fn ($c) => ($c['attempt'] ?? 0) === 1 && str_contains($c['why'] ?? '', 'unit_price of line 1')))->once();
Log::shouldHaveReceived('info')->with('ReceiptExtraction: read ok', Mockery::on(fn ($c) => ($c['attempt'] ?? 0) === 2 && ($c['lines'] ?? 0) === 3 && ($c['tokens_out'] ?? 0) === 500))->once();
ok(true, 'log lines: derailed warning, read ok (attempt 2, 3 lines)');

// ── 2. both replies derailed → null + reason 'derailed' ─────────────────────
echo "2. derailed twice → honest failure\n";
fresh();
Http::fake(['*' => Http::sequence()->push($gemini($derailed))->push($gemini(json_encode(['lines' => []])))]);
$r = (new R())->withHints($hints);
$read = $r->extract($path);
ok($read === null, 'extract() returned null');
ok(($r->lastFailure()['reason'] ?? null) === R::FAIL_DERAILED, "reason is 'derailed'", json_encode($r->lastFailure()));
ok(($r->lastFailure()['attempts'] ?? 0) === 2, 'after 2 attempts');
ok(str_contains($r->lastFailure()['detail'] ?? '', 'no lines'), 'detail names the second failure (no lines)');
Http::assertSentCount(2);
Log::shouldNotHaveReceived('info', ['ReceiptExtraction: read ok', Mockery::any()]);
ok(true, 'no "read ok" logged, no matching call');

// ── 3. clean first reply → untouched, logged once ───────────────────────────
echo "3. clean first reply → kept as before\n";
fresh();
Http::fake(['*' => Http::sequence()->push($gemini($clean, 'STOP', 1937))->push($gemini($matchReply))]);
$r = (new R())->withHints($hints);
$read = $r->extract($path);
ok(is_array($read) && count($read['lines']) === 3, '3 lines read on attempt 1');
ok($read['hinted_products'] === true, 'matched in the second call');
Http::assertSentCount(2);
Log::shouldHaveReceived('info')->with('ReceiptExtraction: read ok', Mockery::on(fn ($c) => ($c['attempt'] ?? 0) === 1 && ($c['tokens_out'] ?? 0) === 1937))->once();
Log::shouldNotHaveReceived('warning', [Mockery::on(fn ($m) => str_starts_with((string) $m, 'ReceiptExtraction')), Mockery::any()]);
ok(true, 'one "read ok" line, no scanner warning');

// ── 4. what counts as derailed, and what does not ───────────────────────────
echo "4. the detector itself\n";
$m = new ReflectionMethod(R::class, 'derailed'); $m->setAccessible(true);
$d = fn (array $p) => $m->invoke(new R(), $p);
ok($d(['lines' => []]) === 'no lines', 'empty list');
ok($d(['lines' => [['raw_name' => 'X', 'qty' => '2.00 kg', 'line_total' => '100'], ['raw_name' => 'Y'], ['raw_name' => 'Z']]]) === null, '"2.00 kg" (two letters) is NOT derailed');
ok($d(['lines' => [['raw_name' => 'X', 'line_total' => 'Rs 1,234.00']]]) !== null && $d(['lines' => [['raw_name' => 'X', 'line_total' => 'Rs 1,234.00'], ['raw_name' => 'Y'], ['raw_name' => 'Z']]]) === null, '"Rs 1,234.00" is a number (3 lines ok; 1 line w/o total is short)');
ok(str_contains((string) $d(['lines' => [['raw_name' => 'X', 'qty' => '1.00 Wait the schema says']]]), 'text inside qty'), 'prose inside qty');
ok($d(['lines' => [['raw_name' => 'Eggs', 'qty' => '1', 'line_total' => '500'], ['raw_name' => 'Bag', 'qty' => '1', 'line_total' => '15']], 'grand_total' => '515', 'confidence' => '']) === null, 'a real 2-line slip WITH a total is fine');
ok($d(['lines' => [['raw_name' => 'Eggs', 'qty' => '1', 'line_total' => '500']], 'confidence' => 'high']) === null, '1 line, no total, but confident → fine');
ok($d(['lines' => [['raw_name' => 'Eggs', 'qty' => '1', 'line_total' => '500']], 'confidence' => '0.95']) === null, '1 line, numeric confidence 0.95 → fine');
ok(str_contains((string) $d(['lines' => [['raw_name' => 'Eggs', 'qty' => '1']]]), 'only 1 line'), '1 line, no total, no confidence → short');
$big = ['lines' => array_fill(0, 26, ['raw_name' => 'X', 'qty' => '1.00', 'unit_price' => '10.00', 'line_total' => '10.00']), 'confidence' => 'low'];
ok($d($big) === null, '26 clean lines, no total, low confidence → fine');

echo "\nPASS $pass  FAIL $fail\n";
exit($fail ? 1 : 0);
