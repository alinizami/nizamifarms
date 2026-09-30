<?php

namespace App\Services\Khaas;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Reads a photographed shop receipt and returns its printed lines as structured data.
 *
 * ⭐ Deliberately built in the shape of GeminiBankScreenshotExtractor, which has been
 * reading payment screenshots in production since early 2026: ONE call, temperature 0,
 * a responseSchema so the reply cannot wander, bounded retries, and the API key
 * redacted out of every log line. Nothing here is novel; only the schema is new.
 *
 * ⚠⚠ THIS SERVICE NEVER WRITES ANYTHING. It reads an image and returns an array. The
 *    draft row, the purchase and every rupee are somebody else's job — the purchase is
 *    only ever written by VendorController::recordWeightedPurchase, which stays the one
 *    writer of money. That is the same rule the NF Assistant follows when it replays a
 *    WhatsApp purchase log.
 *
 * ⚠ The model is a reader, not an authority. The server recomputes the arithmetic and
 *   the person confirms every line before anything is saved. A receipt this cannot read
 *   is a blank card to correct, never a wrong purchase.
 *
 * Cost: a receipt is roughly 1,500 input tokens and under 1,000 out — well under a
 * rupee per slip on Gemini Flash.
 */
class ReceiptExtractionService
{
    public const VERSION = 'receipt@v3';

    /**
     * 🤖 Sep-27 (owner): "for unmatched items use the AI to suggest which of OUR products it
     * is". The vendor's product list — and, for a Frozen vendor, the recipe ingredients —
     * ride along IN THE SAME CALL that reads the slip (no second call, no extra credits
     * beyond a few hundred input tokens), and the model answers per line with one of OUR
     * codes or "NONE".
     *
     * ⚠⚠ The answer is an ENUM of the codes we sent: it cannot invent a product, and a
     *    short code cannot fall into the digit loop that NUMBER fields did. The server
     *    checks every code against the list again anyway (cleanLines), and a person taps
     *    to confirm every suggestion — nothing here is ever applied on its own.
     */
    private const MAX_HINT_PRODUCTS    = 150;
    private const MAX_HINT_INGREDIENTS = 120;

    /** @var array{products: array<int,array{id:int,name:string,unit:?string}>, ingredients: array<int,array{id:int,name:string}>}|null */
    private ?array $hints = null;

    /**
     * What this vendor sells (and, for Frozen, what the kitchen cooks with) — sent with the
     * next extract() so the reader can say which of OUR products each printed line is.
     * A separate call rather than an extract() argument so a test's fake reader, which
     * overrides extract(string $path), keeps working untouched.
     */
    public function withHints(?array $hints): static
    {
        $products = array_slice(array_values($hints['products'] ?? []), 0, self::MAX_HINT_PRODUCTS);
        $ings     = array_slice(array_values($hints['ingredients'] ?? []), 0, self::MAX_HINT_INGREDIENTS);
        $this->hints = ($products || $ings) ? ['products' => $products, 'ingredients' => $ings] : null;
        return $this;
    }

    // Why a read failed — the controller turns each into an honest sentence for the
    // person holding the phone. Before this there was ONE sentence ("better light") for
    // every failure, and in production every failure was actually the model stalling.
    public const FAIL_NO_KEY     = 'no_key';
    public const FAIL_NO_IMAGE   = 'no_image';
    public const FAIL_TIMEOUT    = 'timeout';     // no answer inside the per-try window
    public const FAIL_LOOPED     = 'looped';      // answered, but ran into the output cap
    public const FAIL_BUSY       = 'busy';        // 429 rate / 5xx
    public const FAIL_NO_CREDIT  = 'no_credit';   // prepaid balance used up
    public const FAIL_REFUSED    = 'refused';     // 400/401/403/404 — a setup problem
    public const FAIL_UNREADABLE = 'unreadable';  // empty / blocked / not JSON

    /**
     * ⚠⚠ THE PRODUCTION FAILURE (Sep-2026): with `NUMBER` fields in the schema, Gemini
     *    sometimes falls into a digit loop INSIDE a number ("0000000…", "20932093…") and
     *    keeps generating until the model's own output ceiling (~65k tokens). A
     *    non-streamed call returns nothing until it finishes, so our timeout saw
     *    "0 bytes received" — all three of Qasim's scans died exactly at 45 s. And the
     *    stalled generation is still BILLED at the full ceiling after we hang up.
     *    Measured fix: amounts as STRING (6/6 clean) + a hard output cap, so a loop that
     *    still happens ends in seconds as MAX_TOKENS instead of billing for minutes.
     *    A normal 15-line slip is ~850–1,800 output tokens.
     */
    private const MAX_OUTPUT_TOKENS = 6144;
    /** Per attempt. Two attempts stay inside the phone's 90 s request timeout. */
    private const PER_TRY_TIMEOUT   = 25;
    private const MAX_ATTEMPTS      = 2;

    /** @var array{reason: string, detail: string, status: ?int, seconds: float, attempts: int}|null */
    private ?array $lastFailure = null;

    /** Why the last extract() returned null. Null after a successful read. */
    public function lastFailure(): ?array
    {
        return $this->lastFailure;
    }

    /**
     * @return array{lines: array, store_name: ?string, receipt_no: ?string,
     *               receipt_date: ?string, grand_total: ?float, subtotal: ?float,
     *               discount_total: ?float, confidence: string, raw: string}|null
     *         Null on a hard failure so the caller can keep the draft and retry;
     *         lastFailure() says why.
     */
    public function extract(string $storageRelativePath): ?array
    {
        $this->lastFailure = null;
        $started = microtime(true);

        $cfg    = config('assistant.gemini');
        $apiKey = $cfg['api_key'] ?? config('payment_signals.gemini.api_key') ?? '';

        if (!$apiKey) {
            Log::warning('ReceiptExtraction: no API key configured');
            return $this->fail(self::FAIL_NO_KEY, 'no API key configured', null, $started, 0);
        }

        $disk = Storage::disk(config('whatsapp.media_disk', 'public'));
        if (!$disk->exists($storageRelativePath)) {
            Log::warning('ReceiptExtraction: image not found', ['path' => $storageRelativePath]);
            return $this->fail(self::FAIL_NO_IMAGE, 'stored image not found', null, $started, 0);
        }

        $bytes = $disk->get($storageRelativePath);
        $mime  = $disk->mimeType($storageRelativePath) ?: 'image/jpeg';

        $model    = $cfg['model'] ?? 'gemini-3.5-flash';
        $endpoint = rtrim($cfg['base_url'] ?? 'https://generativelanguage.googleapis.com', '/')
            . '/v1beta/models/' . $model . ':generateContent';

        $attempt  = 0;
        $response = null;
        $parsed   = null;
        $text     = null;
        $rejected = false;   // the previous try was refused outright (C15) — nothing was generated

        while ($attempt < self::MAX_ATTEMPTS) {
            $attempt++;

            $payload = [
                'contents' => [[
                    'parts' => [
                        ['text' => $this->prompt() . $this->hintsPrompt()],
                        ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]],
                    ],
                ]],
                'generationConfig' => [
                    // A retry nudges the temperature: the digit loop is a decoding rut, and
                    // an identical request at 0 is the likeliest way back into it. A retry after
                    // a refused (400) request generated nothing, so there is no rut: it stays at 0.
                    'temperature'      => ($attempt === 1 || $rejected) ? 0 : 0.2,
                    'maxOutputTokens'  => self::MAX_OUTPUT_TOKENS,
                    'responseMimeType' => 'application/json',
                    'responseSchema'   => $this->responseSchema(),
                    // Reading a printed list is mechanical; thought tokens are billed as
                    // output and buy nothing here. Same setting the Assistant uses.
                    'thinkingConfig'   => ['thinkingBudget' => 0],
                ],
            ];

            try {
                $response = Http::timeout(self::PER_TRY_TIMEOUT)
                    ->withQueryParameters(['key' => $apiKey])
                    ->post($endpoint, $payload);
            } catch (\Throwable $e) {
                // ⚠ SECURITY: a cURL error message carries the full request URL, and the
                //   URL carries ?key=<API_KEY>. Redact before it reaches the log.
                $msg = $this->redactKey($e->getMessage());
                Log::error('ReceiptExtraction: request failed', ['attempt' => $attempt, 'error' => $msg]);
                $this->lastFailure = $this->failure(self::FAIL_TIMEOUT, mb_substr($msg, 0, 160), null, $started, $attempt);
                continue; // a stall is worth one more try
            }

            if (!$response->successful()) {
                $status = $response->status();
                $body   = $this->redactKey(mb_substr($response->body(), 0, 400));
                Log::error('ReceiptExtraction: non-200', ['attempt' => $attempt, 'status' => $status, 'body' => $body]);

                $reason = $this->reasonForStatus($status, $body);
                $this->lastFailure = $this->failure($reason, mb_substr($body, 0, 160), $status, $started, $attempt);
                if ($reason === self::FAIL_BUSY) {
                    continue;
                }
                // 🤖 C15: a 400 on a read that carried our lists is most likely the lists (an
                //    enum the API would not take). The one retry reads the slip WITHOUT them —
                //    the bill still reads, it just has no suggestions. A plain read's 400 is
                //    still final, exactly as before.
                if ($status === 400 && $this->dropHintsForRetry($attempt, 'HTTP 400')) {
                    $rejected = true;
                    continue;
                }
                return null; // no credit / refused: asking again changes nothing
            }

            $finish = (string) data_get($response->json(), 'candidates.0.finishReason', '');
            $text   = data_get($response->json(), 'candidates.0.content.parts.0.text');

            if ($finish === 'MAX_TOKENS') {
                Log::warning('ReceiptExtraction: hit the output cap (model looped)', [
                    'attempt' => $attempt,
                    'tail'    => mb_substr((string) $text, -60),
                ]);
                $this->lastFailure = $this->failure(self::FAIL_LOOPED, 'output cap reached', 200, $started, $attempt);
                // 🤖 C15: a long bill plus our lists can run out of room; the retry (which
                //    happened anyway) now goes without the lists. Same cap, same count.
                $this->dropHintsForRetry($attempt, 'MAX_TOKENS');
                continue;
            }

            if (!$text) {
                Log::warning('ReceiptExtraction: empty candidate', ['attempt' => $attempt, 'finish' => $finish]);
                $this->lastFailure = $this->failure(self::FAIL_UNREADABLE, 'empty reply (' . ($finish ?: 'no reason') . ')', 200, $started, $attempt);
                continue;
            }

            $parsed = json_decode($text, true);
            if (!is_array($parsed)) {
                Log::warning('ReceiptExtraction: reply was not JSON', ['attempt' => $attempt, 'text' => mb_substr($text, 0, 300)]);
                $this->lastFailure = $this->failure(self::FAIL_UNREADABLE, 'reply was not JSON', 200, $started, $attempt);
                $parsed = null;
                continue;
            }

            $this->lastFailure = null;
            break;
        }

        if (!is_array($parsed)) {
            return null;
        }

        $usage = $response->json('usageMetadata') ?: [];

        return [
            'store_name'     => $this->clean($parsed['store_name'] ?? null),
            'receipt_no'     => $this->clean($parsed['receipt_no'] ?? null),
            'receipt_date'   => $this->cleanDate($parsed['receipt_date'] ?? null),
            'lines'          => $this->cleanLines($parsed['lines'] ?? []),
            'subtotal'       => $this->toAmount($parsed['subtotal'] ?? null),
            'discount_total' => $this->toAmount($parsed['discount_total'] ?? null),
            'grand_total'    => $this->toAmount($parsed['grand_total'] ?? null),
            'confidence'     => in_array(($parsed['confidence'] ?? ''), ['low', 'medium', 'high'], true)
                ? $parsed['confidence'] : 'low',
            'raw'            => $text,
            'model'          => $model,
            'tokens_in'      => (int) ($usage['promptTokenCount'] ?? 0),
            'tokens_out'     => (int) ($usage['candidatesTokenCount'] ?? 0),
            // 🤖 whether the reader was shown our lists — so "no match" can be told apart
            //    from "never asked"
            'hinted_products'    => (bool) ($this->hints['products'] ?? false),
            'hinted_ingredients' => (bool) ($this->hints['ingredients'] ?? false),
        ];
    }

    /**
     * The arithmetic check the model is NOT trusted to do.
     *
     * Sums the lines and compares with the printed total. Anything more than Rs 5 apart
     * is shown to the person as a red warning rather than quietly accepted — a receipt
     * that does not add up is exactly the one worth a second look.
     *
     * @return array{lines_total: float, printed_total: ?float, difference: float, matches: bool}
     */
    public function reconcile(array $lines, ?float $printedTotal): array
    {
        $sum = 0.0;
        foreach ($lines as $l) {
            $sum += (float) ($l['line_total'] ?? 0);
        }
        $sum = round($sum, 2);

        if ($printedTotal === null) {
            return ['lines_total' => $sum, 'printed_total' => null, 'difference' => 0.0, 'matches' => true];
        }

        $diff = round($printedTotal - $sum, 2);

        return [
            'lines_total'   => $sum,
            'printed_total' => round($printedTotal, 2),
            'difference'    => $diff,
            'matches'       => abs($diff) <= 5.0,
        ];
    }

    // =================================================================
    //  INTERNALS
    // =================================================================

    private function prompt(): string
    {
        return <<<'TXT'
You are reading a photographed SHOP RECEIPT from Pakistan — a supermarket or grocery
till slip, usually printed in English with rupee amounts.

Return ONE line object for every printed sales line, IN THE ORDER PRINTED. Do not merge
lines, do not split them, do not invent any, and do not skip a line you cannot fully read
— return it with the fields you can read and null for the rest.

For each line:
- raw_name: the product text exactly as printed, including any size or pack wording.
- qty: the printed quantity. Weighed items print a decimal like 0.92 or 5.06; counted
  items print a whole number like 2 or 12.
- unit_price: the printed price per unit.
- line_total: the printed amount for that line.
- discount: the printed discount for that line, 0 when none.
- sold_by: "weight" when the quantity is a decimal weight on a scale, "pack" when it is a
  count of packs or pieces, "unknown" if you genuinely cannot tell.
- pack_size_value and pack_size_unit: ONLY when the product name states a size.
    "Seasons Canola Oil Poly Bag 1Ltr"  -> 1 and "L"
    "Puck Crm Ches 910G"                -> 910 and "g"
    "Ponam Maida 1kg"                   -> 1 and "kg"
    "Farm Fresh Golden D Egg 30's"      -> 30 and "pcs"
    "Non-Woven Large Bag 18\"x18\"x7.5\"" -> null and null (a dimension is not a size)
  Use null for both when the name states no size.

Also return, from the totals block: store_name, receipt_no, receipt_date in YYYY-MM-DD
(null if you cannot read it confidently — NEVER guess a date), subtotal, discount_total
and grand_total.

Lines that are charges rather than goods — "FBR POS Charges", service or delivery fees,
rounding — are still real printed lines: return them, with sold_by "unknown".

Write every number as the digits printed, as a short string like "520.93" or "5.00" —
no currency word, no thousands commas. Never pad a number with extra digits.

Read only what is printed. Do not calculate, correct or complete anything: if a number is
unreadable, return null for it. Somebody will check every line against the paper before
any of it is saved.
TXT;
    }

    /**
     * 🤖 The list the reader matches against, in the buyer's own names. Empty when there
     * are no hints, so a vendor with no products reads exactly as before.
     */
    private function hintsPrompt(): string
    {
        if (!$this->hints) {
            return '';
        }
        $name = fn ($s) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $s)), 0, 60);

        $out = '';
        if ($this->hints['products']) {
            $out .= "\n\nTHIS VENDOR'S PRODUCT LIST — the buyer's own names for what they buy here. "
                . "They rarely match the printed name word for word:\n";
            foreach ($this->hints['products'] as $p) {
                $out .= 'P' . (int) $p['id'] . ': ' . $name($p['name'])
                    . (!empty($p['unit']) ? ' (bought per ' . $name($p['unit']) . ')' : '') . "\n";
            }
            $out .= <<<'TXT'
For EVERY line also return "match": the code of the product on this list that the line is
the SAME ITEM as — allowing for brand names ("MAGGI CHICKEN CUBES" is chicken cubes), Urdu
or Roman-Urdu names ("NAMAK" is salt, "CHEENI" is sugar, "ANDAY" are eggs), abbreviations
("CHKN", "CRM CHES") and pack sizes. Return "NONE" when no product on the list is the same
item. Never pick a product that is only related: chicken stock powder is not chicken cubes,
whole chilli is not chilli powder, a carrier bag or a charge is NONE. When unsure, return
"NONE" — a person picks it by hand, and a wrong guess costs more than a blank.
TXT;
        }
        if ($this->hints['ingredients']) {
            $out .= "\n\nRECIPE INGREDIENTS — what the kitchen cooks with:\n";
            foreach ($this->hints['ingredients'] as $i) {
                $out .= 'I' . (int) $i['id'] . ': ' . $name($i['name']) . "\n";
            }
            $out .= <<<'TXT'
For EVERY line also return "ingredient": the code of the ingredient the item IS, by the
same rule — the same thing, not something related — or "NONE" for anything else
(a bag, a charge, a cleaning product, or anything not on the list).
TXT;
        }
        return $out;
    }

    /** The codes the reader may answer with — exactly the ones sent, plus NONE. */
    private function hintCodes(string $kind): array
    {
        if (!$this->hints) {
            return [];
        }
        $list = $kind === 'P' ? $this->hints['products'] : $this->hints['ingredients'];
        if (!$list) {
            return [];
        }
        return array_merge(array_map(fn ($x) => $kind . (int) $x['id'], $list), ['NONE']);
    }

    /**
     * ⚠⚠ Every amount is a STRING on purpose — see MAX_OUTPUT_TOKENS. `NUMBER` is what
     *    let the model loop on digits. toAmount() reads the strings back.
     */
    private function responseSchema(): array
    {
        $schema = $this->baseSchema();
        // 🤖 Only when there is a list to match against: an enum of OUR codes, so the
        //    answer can be nothing else.
        foreach (['match' => 'P', 'ingredient' => 'I'] as $field => $kind) {
            $codes = $this->hintCodes($kind);
            if ($codes) {
                $schema['properties']['lines']['items']['properties'][$field] = [
                    'type' => 'STRING', 'format' => 'enum', 'enum' => $codes,
                ];
            }
        }
        return $schema;
    }

    private function baseSchema(): array
    {
        return [
            'type'       => 'OBJECT',
            'properties' => [
                'store_name'     => ['type' => 'STRING', 'nullable' => true],
                'receipt_no'     => ['type' => 'STRING', 'nullable' => true],
                'receipt_date'   => ['type' => 'STRING', 'nullable' => true],
                'subtotal'       => ['type' => 'STRING', 'nullable' => true],
                'discount_total' => ['type' => 'STRING', 'nullable' => true],
                'grand_total'    => ['type' => 'STRING', 'nullable' => true],
                'confidence'     => ['type' => 'STRING'],
                'lines' => [
                    'type'  => 'ARRAY',
                    'items' => [
                        'type'       => 'OBJECT',
                        'properties' => [
                            'raw_name'        => ['type' => 'STRING'],
                            'qty'             => ['type' => 'STRING', 'nullable' => true],
                            'unit_price'      => ['type' => 'STRING', 'nullable' => true],
                            'line_total'      => ['type' => 'STRING', 'nullable' => true],
                            'discount'        => ['type' => 'STRING', 'nullable' => true],
                            'sold_by'         => ['type' => 'STRING', 'nullable' => true],
                            'pack_size_value' => ['type' => 'STRING', 'nullable' => true],
                            'pack_size_unit'  => ['type' => 'STRING', 'nullable' => true],
                        ],
                        'required' => ['raw_name'],
                    ],
                ],
            ],
            'required' => ['lines'],
        ];
    }

    private function cleanLines($lines): array
    {
        if (!is_array($lines)) {
            return [];
        }

        $out = [];
        foreach ($lines as $l) {
            if (!is_array($l)) {
                continue;
            }
            $name = $this->clean($l['raw_name'] ?? null);
            if ($name === null || $name === '') {
                continue;
            }

            $out[] = [
                'raw_name'        => $name,
                'qty'             => $this->toAmount($l['qty'] ?? null),
                'unit_price'      => $this->toAmount($l['unit_price'] ?? null),
                'line_total'      => $this->toAmount($l['line_total'] ?? null),
                'discount'        => $this->toAmount($l['discount'] ?? null) ?? 0.0,
                'sold_by'         => in_array(($l['sold_by'] ?? ''), ['weight', 'pack', 'unknown'], true)
                    ? $l['sold_by'] : 'unknown',
                'pack_size_value' => $this->toAmount($l['pack_size_value'] ?? null),
                'pack_size_unit'  => $this->cleanPackUnit($l['pack_size_unit'] ?? null),
                // 🤖 our product / ingredient id, or null — ONLY a code we sent counts
                'ai_match'        => $this->hintId($l['match'] ?? null, 'P'),
                'ai_ingredient'   => $this->hintId($l['ingredient'] ?? null, 'I'),
            ];
        }

        return $out;
    }

    /** "P82" → 82 when P82 is one of the codes we sent; anything else → null. */
    private function hintId($code, string $kind): ?int
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '' || $code === 'NONE' || !in_array($code, $this->hintCodes($kind), true)) {
            return null;
        }
        return (int) substr($code, 1);
    }

    /** Normalise the handful of size units a Pakistani receipt actually prints. */
    private function cleanPackUnit($v): ?string
    {
        $v = strtolower(trim((string) $v));
        if ($v === '') {
            return null;
        }

        return match ($v) {
            'l', 'ltr', 'litre', 'liter' => 'L',
            'ml'                          => 'ml',
            'kg', 'kgs'                   => 'kg',
            'g', 'gm', 'gms', 'gram', 'grams' => 'g',
            'pcs', 'pc', 'piece', 'pieces', "'s", 's' => 'pcs',
            default => null,
        };
    }

    private function clean($v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim((string) $v);
        return $v === '' ? null : mb_substr($v, 0, 255);
    }

    private function cleanDate($v): ?string
    {
        $v = $this->clean($v);
        if (!$v) {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($v)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Amounts arrive as STRINGS (see MAX_OUTPUT_TOKENS) and are printed the shop's way:
     * "Rs1,188.63", "Rs. 2,895.00", "PKR 60", "-60.00".
     * ⚠ The old version stripped every non-digit, so "Rs.1,000" became ".1000" = 0.1.
     *   The currency word is removed FIRST, then separators.
     */
    private function toAmount($v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return round((float) $v, 3);
        }
        $s = trim((string) $v);
        $s = preg_replace('/^(?:rs\.?|pkr)\s*/i', '', $s);
        $s = str_replace([',', ' '], '', $s);
        if (preg_match('/^-?\d+(?:\.\d+)?$/', $s)) {
            return round((float) $s, 3);
        }
        // Anything else ("2.00 kg", "Rs 60 off"): the first number in it, or nothing.
        if (preg_match('/-?\d+(?:\.\d+)?/', $s, $m)) {
            return round((float) $m[0], 3);
        }
        return null;
    }

    /**
     * 🤖 C15 (Sep-29): forget the product/ingredient lists for the rest of this read, when
     * there are lists and a retry is still left. True when the next attempt will go without
     * them. The attempt count is unchanged (MAX_ATTEMPTS), and so is the output cap.
     */
    private function dropHintsForRetry(int $attempt, string $why): bool
    {
        if (!$this->hints || $attempt >= self::MAX_ATTEMPTS) {
            return false;
        }
        Log::warning('ReceiptExtraction: retrying without the product list', ['attempt' => $attempt, 'why' => $why]);
        $this->hints = null;
        return true;
    }

    private function reasonForStatus(int $status, string $body): string
    {
        if ($status === 429) {
            // Google answers a used-up prepaid balance with 429 too — but no retry helps it.
            return preg_match('/credit|billing|prepay/i', $body) ? self::FAIL_NO_CREDIT : self::FAIL_BUSY;
        }
        if ($status >= 500) {
            return self::FAIL_BUSY;
        }
        return self::FAIL_REFUSED;
    }

    private function failure(string $reason, string $detail, ?int $status, float $started, int $attempts): array
    {
        return [
            'reason'   => $reason,
            'detail'   => $detail,
            'status'   => $status,
            'seconds'  => round(microtime(true) - $started, 1),
            'attempts' => $attempts,
        ];
    }

    private function fail(string $reason, string $detail, ?int $status, float $started, int $attempts): ?array
    {
        $this->lastFailure = $this->failure($reason, $detail, $status, $started, $attempts);
        return null;
    }

    private function redactKey(string $s): string
    {
        return preg_replace('/([?&]key=)[^&\s]+/i', '$1***', $s);
    }
}
