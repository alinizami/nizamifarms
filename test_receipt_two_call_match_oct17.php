<?php
/**
 * PROOF — product matching is a SECOND, TEXT-ONLY call; the photo call carries no lists (Oct-17).
 * Run: php test_receipt_two_call_match_oct17.php
 *
 * The enum lists in the photo call made the model lose its place at the first price on
 * Qasim's 26-line slip (4/4 with, 0/1 without). Now: photo call = plain schema, plain
 * prompt; then one text-only call with the printed names + OUR lists. A failed matching
 * call costs the suggestions, never the read.
 *
 * The HTTP layer is faked — NO live model call, NO credits.
 * ⚠ Traps: Http::sequence()->push(body, status); a fresh Http factory per case (a second
 *   Http::fake() stacks on the exhausted sequence); Log::spy() reuses one spy, so swap a new one.
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Khaas\ReceiptExtractionService as R;
use Illuminate\Http\Client\ConnectionException;
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
function matchSchemaHas($request, string $field): bool {
    return isset($request->data()['generationConfig']['responseSchema']['properties']['matches']['items']['properties'][$field]);
}
function promptText($request): string {
    return implode("\n", array_map(fn ($p) => $p['text'] ?? '', $request->data()['contents'][0]['parts'] ?? []));
}
function hasImage($request): bool {
    foreach ($request->data()['contents'][0]['parts'] ?? [] as $p) { if (isset($p['inline_data'])) return true; }
    return false;
}

// ── fixtures ────────────────────────────────────────────────────────────────
config(['assistant.gemini.api_key' => 'test-key-not-real', 'whatsapp.media_disk' => 'public']);
Storage::fake('public');
$path = 'receipts/test/two-call.jpg';
Storage::disk('public')->put($path, base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='));

$gemini = fn (string $json, string $finish = 'STOP', int $out = 500) => [
    'candidates'    => [['finishReason' => $finish, 'content' => ['parts' => [['text' => $json]]]]],
    'usageMetadata' => ['promptTokenCount' => 1500, 'candidatesTokenCount' => $out],
];
$line = fn ($n, $q, $p, $t) => ['raw_name' => $n, 'qty' => $q, 'unit_price' => $p, 'line_total' => $t, 'discount' => '0.00', 'sold_by' => 'weight', 'pack_size_unit' => null, 'pack_size_value' => null];
$clean = json_encode([
    'lines' => [$line('Fresh Onions', '10.03', '195.00', '1955.46'), $line('Fresh Tomatoes', '1.22', '175.00', '213.15'), $line('Non-Woven Bag', '1.00', '35.00', '15.00')],
    'grand_total' => '2183.61', 'confidence' => 'high',
]);
$matchReply = json_encode(['matches' => [
    ['line' => '1', 'match' => 'P91', 'ingredient' => 'I18'],
    ['line' => '2', 'match' => 'NONE', 'ingredient' => 'I7'],
    ['line' => '3', 'match' => 'NONE', 'ingredient' => 'NONE'],
]]);
$hints = [
    'products'    => [['id' => 91, 'name' => 'Onion (Pyaz)', 'unit' => 'kg'], ['id' => 5, 'name' => 'Sugar', 'unit' => 'kg']],
    'ingredients' => [['id' => 18, 'name' => 'Onion'], ['id' => 7, 'name' => 'Tomato']],
];

// ── 1. hints set: photo call plain, matching call separate, answers land ─────
echo "1. with lists: photo call carries nothing, text call matches\n";
fresh();
Http::fake(['*' => Http::sequence()->push($gemini($clean, 'STOP', 1937))->push($gemini($matchReply, 'STOP', 60))]);
$r = (new R())->withHints($hints);
$read = $r->extract($path);
ok(is_array($read) && count($read['lines']) === 3, '3 lines read');
Http::assertSentCount(2);
$s = sent();
ok(hasImage($s[0]) && !imageSchemaHas($s[0], 'match') && !imageSchemaHas($s[0], 'ingredient'), 'photo call: image, NO enum fields');
ok(!str_contains(promptText($s[0]), "PRODUCT LIST") && !str_contains(promptText($s[0]), 'P91'), 'photo call: no list text');
ok(!hasImage($s[1]) && matchSchemaHas($s[1], 'match') && matchSchemaHas($s[1], 'ingredient'), 'match call: text only, enum fields');
$enum = $s[1]->data()['generationConfig']['responseSchema']['properties']['matches']['items']['properties']['match']['enum'];
ok($enum === ['P91', 'P5', 'NONE'], 'match enum = exactly our codes + NONE', json_encode($enum));
ok(str_contains(promptText($s[1]), '1. Fresh Onions') && str_contains(promptText($s[1]), '3. Non-Woven Bag') && str_contains(promptText($s[1]), 'P91: Onion (Pyaz)'), 'match call: numbered names + our list');
ok(($s[1]->data()['generationConfig']['maxOutputTokens'] ?? 0) === 2048, 'match call capped at 2048');
ok($read['lines'][0]['ai_match'] === 91 && $read['lines'][0]['ai_ingredient'] === 18, 'line 1 → P91 / I18');
ok($read['lines'][1]['ai_match'] === null && $read['lines'][1]['ai_ingredient'] === 7, 'line 2 → NONE / I7');
ok($read['lines'][2]['ai_match'] === null && $read['lines'][2]['ai_ingredient'] === null, 'line 3 → NONE / NONE');
ok($read['hinted_products'] === true && $read['hinted_ingredients'] === true, 'hinted flags true (asked AND answered)');
ok($read['tokens_out'] === 1937, 'tokens reported are the photo call\'s');
Log::shouldHaveReceived('info')->with('ReceiptExtraction: read ok', Mockery::on(fn ($c) => ($c['attempt'] ?? 0) === 1 && ($c['lines'] ?? 0) === 3))->once();
Log::shouldHaveReceived('info')->with('ReceiptExtraction: match ok', Mockery::on(fn ($c) => ($c['lines'] ?? 0) === 3 && ($c['suggested'] ?? 0) === 2 && ($c['tokens_out'] ?? 0) === 60))->once();
ok(true, 'logs: read ok + match ok (2 lines suggested)');

// ── 2. matching call 500 → lines kept, read still succeeds ──────────────────
echo "2. matching call 500 → no suggestions, read kept\n";
fresh();
Http::fake(['*' => Http::sequence()->push($gemini($clean))->push(['error' => 'boom'], 500)]);
$r = (new R())->withHints($hints);
$read = $r->extract($path);
ok(is_array($read) && count($read['lines']) === 3, 'read succeeded with 3 lines');
ok($read['lines'][0]['ai_match'] === null && $read['lines'][0]['ai_ingredient'] === null, 'no suggestions');
ok($read['hinted_products'] === false && $read['hinted_ingredients'] === false, 'hinted flags false (asked, not answered)');
ok($r->lastFailure() === null, 'not a failed read');
Log::shouldHaveReceived('warning')->with('ReceiptExtraction: matching call non-200 (no suggestions)', Mockery::on(fn ($c) => ($c['status'] ?? 0) === 500))->once();
ok(true, 'warning logged');

// ── 3. matching reply garbage / MAX_TOKENS → kept ───────────────────────────
echo "3. matching reply unusable → no suggestions, read kept\n";
fresh();
Http::fake(['*' => Http::sequence()->push($gemini($clean))->push($gemini('{"matches": [{"line": "1", "match": "P91', 'MAX_TOKENS'))]);
$read = (new R())->withHints($hints)->extract($path);
ok(is_array($read) && $read['lines'][0]['ai_match'] === null && $read['hinted_products'] === false, 'MAX_TOKENS on the match call → kept, unhinted');
Log::shouldHaveReceived('warning')->with('ReceiptExtraction: matching reply unusable (no suggestions)', Mockery::any())->once();
ok(true, 'warning logged');

// ── 4. matching call throws (timeout) → kept ────────────────────────────────
echo "4. matching call times out → no suggestions, read kept\n";
fresh();
$calls = 0;
Http::fake(function ($request) use (&$calls, $gemini, $clean) {
    $calls++;
    if ($calls === 1) { return Http::response($gemini($clean), 200); }
    throw new ConnectionException('cURL error 28: Operation timed out https://x/?key=SECRET123');
});
$read = (new R())->withHints($hints)->extract($path);
ok(is_array($read) && count($read['lines']) === 3 && $read['hinted_products'] === false, 'read kept, unhinted');
Log::shouldHaveReceived('warning')->with('ReceiptExtraction: matching call failed (no suggestions)', Mockery::on(fn ($c) => str_contains($c['error'] ?? '', 'key=***') && !str_contains($c['error'] ?? '', 'SECRET123')))->once();
ok(true, 'warning logged with the key redacted');

// ── 5. no hints → exactly one call ──────────────────────────────────────────
echo "5. no lists → one call only\n";
fresh();
Http::fake(['*' => Http::sequence()->push($gemini($clean))]);
$read = (new R())->extract($path);
ok(is_array($read) && count($read['lines']) === 3, '3 lines');
Http::assertSentCount(1);
ok($read['hinted_products'] === false && $read['hinted_ingredients'] === false, 'hinted flags false (never asked)');
ok(true, 'exactly 1 HTTP call');

// ── 6. answers we never sent are ignored ────────────────────────────────────
echo "6. codes / line numbers we never sent\n";
fresh();
Http::fake(['*' => Http::sequence()->push($gemini($clean))->push($gemini(json_encode(['matches' => [
    ['line' => '1', 'match' => 'P999', 'ingredient' => 'I18'],   // product not on the list
    ['line' => '9', 'match' => 'P91', 'ingredient' => 'I18'],    // line we never sent
    ['line' => 'line 2', 'match' => 'P5', 'ingredient' => 'NONE'], // sloppy number, still ours
]])))]);
$read = (new R())->withHints($hints)->extract($path);
ok($read['lines'][0]['ai_match'] === null && $read['lines'][0]['ai_ingredient'] === 18, 'P999 ignored, I18 kept');
ok($read['lines'][1]['ai_match'] === 5, '"line 2" → line 2 gets P5');
ok($read['lines'][2]['ai_match'] === null, 'line 9 answer dropped, line 3 untouched');
ok($read['hinted_products'] === true, 'still counts as answered');

// ── 7. photo-call retry keeps the lists for the matching call ───────────────
echo "7. photo call loops once, retry reads, matching still runs\n";
fresh();
Http::fake(['*' => Http::sequence()->push($gemini('{"lines":[{"raw_name":"0000', 'MAX_TOKENS'))->push($gemini($clean))->push($gemini($matchReply))]);
$read = (new R())->withHints($hints)->extract($path);
Http::assertSentCount(3);
$s = sent();
ok(is_array($read) && count($read['lines']) === 3 && $read['lines'][0]['ai_match'] === 91, 'read on attempt 2, then matched');
ok(!imageSchemaHas($s[1], 'match') && (float) $s[1]->data()['generationConfig']['temperature'] === 0.2, 'retry: still no enum, temperature nudged');
ok($read['hinted_products'] === true, 'hinted true');

echo "\nPASS $pass  FAIL $fail\n";
exit($fail ? 1 : 0);
