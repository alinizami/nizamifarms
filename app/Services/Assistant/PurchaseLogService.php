<?php

namespace App\Services\Assistant;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp purchase-log screenshots → weighted vendor purchases.
 *
 * Taimur's butcher posts each weighing into the vendor's WhatsApp group as it
 * happens ("Mutton 12.5" at 9:15, "Mutton 5.1" at 2:59, "Chakki . 650"…). He
 * screenshots the chat and types the day up by hand. This turns the screenshot
 * into the same entry he would have made.
 *
 * ⭐⭐ THE SHAPE WAS NOT DESIGNED — IT WAS READ OFF HIS OWN LEDGER. The sample
 * screenshots are already in the books, so the conventions are settled facts:
 *   • ONE weighted purchase per vendor per DAY (ledger 19307 = six weights of
 *     one day on one entry), and
 *   • ONE LINE PER MESSAGE, never summed — so every line stays checkable
 *     against the chat, with the screenshot attached as the receipt.
 *
 * ⚠⚠ VENDOR PRODUCTS ARE NOT THE SALES CATALOG. Purchases live entirely in
 * `t_fin_vendor_products` / `t_fin_vendor_purchase_items` (per-vendor names,
 * units and rates, managed on the vendor screen). The customer-facing catalog
 * (`t_pdm_*`) is never read or written here — verified across every writer of
 * `vendor_product_id`. Nothing in this class may reference it.
 *
 * Everything here is READ-ONLY: it proposes a card. Money moves only when
 * Taimur taps Confirm, through the existing weighted-purchase endpoint.
 */
class PurchaseLogService
{
    /** Chat lines that are conversation, not weighings. */
    private const NOISE = ['ok', 'okay', 'thanks', 'thank you', 'done', 'yes', 'no', 'ji', 'theek', 'sahi'];

    /**
     * Resolve a WhatsApp group title to one of our vendors.
     *
     * Taught groups win (a human said so once); otherwise a name match against
     * the vendor list, which is why "Taimoor Ali (Jilani Meat…" and "Wahab
     * Mutton" (participant "Wahab Qureshi") land correctly. Ambiguity returns
     * null so the caller asks rather than guesses — a wrong vendor here would
     * put a day's meat on someone else's khata.
     *
     * @return array{vendor_id:int, vendor_name:string, how:string}|null
     */
    public function resolveVendor(?string $groupTitle, array $participants = []): ?array
    {
        $title = trim((string) $groupTitle);
        if ($title === '') {
            return null;
        }

        // 1. Taught: WAGROUP:<normalised title> in the same counterparty map
        //    the bank rules use, so it shows on the existing review panel and
        //    can be deactivated like any other rule.
        $rule = app(SmsCounterpartyMap::class)->byAccount($this->groupKey($title));
        if ($rule && $rule->entity_type === 'vendor' && $rule->entity_id) {
            $name = DB::table('t_fin_vendors')->where('id', $rule->entity_id)->value('vendor_name');
            if ($name) {
                return ['vendor_id' => (int) $rule->entity_id, 'vendor_name' => $name, 'how' => 'taught'];
            }
        }

        // 2. Name match on the GROUP TITLE. The title is what names the vendor
        //    ("Taimoor Ali (Jilani Meat)", "Wahab Mutton") and it is the only
        //    reliable signal here.
        //
        // ⚠⚠ PARTICIPANTS ARE A LAST RESORT, NEVER MIXED IN. Our own staff sit
        // in these groups and several of them are ALSO vendors — Sajid (Desi
        // Chicken) and Nizami Foods/Farms are real vendor rows. Searching the
        // participant list alongside the title turned a clean Jilani match into
        // a four-way tie on live data, which would have meant asking every
        // single time. Participants are only consulted when the title itself
        // names nobody.
        $hits = $this->vendorsNamedIn([$title]);
        if (count($hits) !== 1 && !empty($participants)) {
            $hits = $this->vendorsNamedIn($participants);
        }

        if (count($hits) !== 1) {
            return null; // nobody, or a choice that is not ours to make
        }
        $id = array_key_first($hits);
        return ['vendor_id' => (int) $id, 'vendor_name' => $hits[$id], 'how' => 'name_match'];
    }

    /** Remember this group → vendor, so the next screenshot needs no question. */
    public function teachVendor(string $groupTitle, int $vendorId, ?int $userId): bool
    {
        $key = $this->groupKey($groupTitle);
        if (!$key) {
            return false;
        }
        return (bool) app(SmsCounterpartyMap::class)
            ->save($key, null, 'vendor', $vendorId, null, $userId);
    }

    /**
     * ⭐ LEARN WHAT THE BUTCHER'S WORDS MEAN — from a CONFIRMED purchase only.
     *
     * For every line that still carries its chat text, compare what Taimur
     * confirmed against what we would have resolved on our own. Where they
     * differ — a token we could not place ("Chakki") or one we placed WRONG and
     * he corrected — save WAPROD:<vendor>:<TOKEN> → that product. Upserting on
     * the key IS the relearn: correct it again and the rule moves again.
     *
     * Deliberately narrow:
     *   • never taught from a card he cancels (caller runs this on confirm);
     *   • a token confirmed as TWO different products in one day is ambiguous —
     *     his own usage disagrees with itself, so no rule is written;
     *   • a token our own matching already resolves to the same product is not
     *     stored — a rule that changes nothing is just noise on the map;
     *   • name_key stays NULL so these rows can never collide with bank-SMS
     *     name matching (byName), and 'product' rows are invisible to every
     *     bank-side consumer (they all filter on vendor/customer/account/ignore).
     */
    public function teachProductAliases(int $vendorId, array $lines, ?int $userId): void
    {
        // token → the set of product ids he confirmed it as, this card.
        $confirmed = [];
        foreach ($lines as $l) {
            $token = $this->tokenFrom((string) ($l['text'] ?? ''));
            $pid   = (int) ($l['product_id'] ?? 0);
            if ($token === '' || $pid <= 0) {
                continue; // a bare number is the DEFAULT product, not a word to learn
            }
            $confirmed[$token][$pid] = true;
        }

        $products = $this->vendorProducts($vendorId);
        $default  = collect($products)->firstWhere('is_default', 1) ?: ($products[0] ?? null);
        $byId     = collect($products)->keyBy('id');

        foreach ($confirmed as $token => $pids) {
            if (count($pids) !== 1) {
                continue; // he used the same word for two products today — ambiguous
            }
            $pid = (int) array_key_first($pids);
            $product = $byId[$pid] ?? null;
            if (!$product) {
                continue; // not on this vendor's list (stale line) — never learn it
            }

            $taught = $this->taughtProduct($vendorId, $token);
            if ($taught && (int) $taught->id === $pid) {
                continue; // already knows exactly this
            }
            if (!$taught) {
                $auto = $this->matchProduct($token, $products, $default);
                if ($auto && (int) $auto->id === $pid) {
                    continue; // name matching already gets this right on its own
                }
            }

            $key = $this->productKey($vendorId, $token);
            if ($key) {
                app(SmsCounterpartyMap::class)
                    ->save($key, null, 'product', $pid, mb_substr($product->product_name, 0, 120), $userId);
            }
        }
    }

    /** The product a taught alias names — only if still on THIS vendor's list. */
    private function taughtProduct(int $vendorId, string $token): ?object
    {
        $key = $this->productKey($vendorId, $token);
        if (!$key) {
            return null;
        }
        $rule = app(SmsCounterpartyMap::class)->byAccount($key);
        if (!$rule || $rule->entity_type !== 'product' || !$rule->entity_id) {
            return null;
        }
        return DB::table('t_fin_vendor_products')
            ->where('id', $rule->entity_id)
            ->where('vendor_id', $vendorId)
            ->where('is_active', 1)
            ->first(['id', 'product_name', 'unit', 'rate_per_unit', 'is_default']);
    }

    /** "Chakki" for vendor 1 → WAPROD:1:CHAKKI (letters only, so no digit tail
     *  can ever look like a bank account to the structural matcher). */
    private function productKey(int $vendorId, string $token): ?string
    {
        $t = mb_strtoupper(trim(preg_replace('/\s+/', ' ',
            preg_replace('/[^\p{L}\s]/u', ' ', $token))));
        return $t === '' ? null : mb_substr('WAPROD:' . $vendorId . ':' . $t, 0, 64);
    }

    /** The words before the number — the butcher's own name for the product. */
    private function tokenFrom(string $text): string
    {
        $token = trim(preg_replace('/[\d.,\s]+$/u', '', $text));
        return trim(preg_replace('/^[^\p{L}]+/u', '', $token));
    }

    /**
     * Turn one day-block of chat lines into purchase lines.
     *
     * Quantity conventions, all taken from real messages:
     *   "Mutton 12.5"   → 12.5      "Mutton 5.750" → 5.75 (grams written long)
     *   "Chakki . 650"  → 0.650     a bare "5.9"   → 5.9 of the DEFAULT product
     * A bare number is how the vendor himself posts (ledger 19302 contains his
     * "5.9"), so it counts — but only as the default product, never a guess at
     * which one.
     *
     * @return array{lines:array, unknown:array}  unknown = product tokens we
     *         could not place, for the caller to ask about.
     */
    public function parseLines(int $vendorId, array $rawLines): array
    {
        $products = $this->vendorProducts($vendorId);
        $default  = collect($products)->firstWhere('is_default', 1) ?: ($products[0] ?? null);

        $lines = [];
        $unknown = [];

        foreach ($rawLines as $raw) {
            $text = trim((string) ($raw['text'] ?? $raw));
            if ($text === '' || in_array(mb_strtolower($text), self::NOISE, true)) {
                continue;
            }

            $qty = $this->quantityFrom($text);
            if ($qty === null || $qty <= 0) {
                continue; // no number → not a weighing
            }

            $token = $this->tokenFrom($text);

            // ⭐ TAUGHT ALIASES WIN. Taimur placing "Chakki . 650" once (chips or
            // a correction) teaches WAPROD:<vendor>:CHAKKI, and from then on the
            // butcher's own word beats any name-similarity guess — per vendor,
            // because "chicken" can mean a different product in each catalogue.
            $product = $token === ''
                ? $default
                : ($this->taughtProduct($vendorId, $token) ?? $this->matchProduct($token, $products, $default));

            if (!$product) {
                $unknown[] = ['text' => $text, 'token' => $token, 'quantity' => $qty,
                              'time' => $raw['time'] ?? null];
                continue;
            }

            $lines[] = [
                'product_id'   => (int) $product->id,
                'product_name' => $product->product_name,
                'unit'         => $product->unit,
                'quantity'     => $qty,
                'rate'         => $this->rateFor($product),
                'rate_source'  => $this->rateSourceFor($product),
                'time'         => $raw['time'] ?? null,
                'text'         => $text,
            ];
        }

        return ['lines' => $lines, 'unknown' => $unknown];
    }

    /**
     * ⭐⭐ IS THIS DAY ALREADY ON THE BOOKS? (owner ruling, Aug-2026.)
     *
     * A screenshot of recent chat ALWAYS contains days already entered — the
     * sample spans three days of which two were. Recording them again would
     * double-book stock and the vendor's khata, so the answer is never "just
     * add it".
     *
     * The ladder, in the owner's words "when in doubt, ask, and look back two
     * days" (two, because a screenshot can be stale or a relative separator
     * mis-resolved by a day):
     *   skip      — same date, and the content looks like the same purchase
     *   ask_same  — same date, different content (a real second entry that day
     *               exists: Aug-17 had two) — could be genuine, could be a
     *               correction, so he decides
     *   ask_near  — no entry that date, but a purchase within 2 days looks the
     *               same: "is this the same purchase, or a new day?"
     *   ok        — nothing resembling it; go straight to the card
     *
     * @return array{verdict:string, existing:?array}
     */
    public function dedupeVerdict(int $vendorId, string $date, array $lines, array $unplaced = []): array
    {
        try {
            $accountId = DB::table('t_fin_vendors')->where('id', $vendorId)->value('account_id');
            if (!$accountId) {
                return ['verdict' => 'ok', 'existing' => null];
            }

            // ⚠⚠ UNPLACED WEIGHINGS STILL COUNT. A line whose product we could
            // not name is still meat that was weighed, and leaving it out makes
            // the day look SMALLER than the entry it duplicates — which is
            // exactly how a duplicate slips through. Proven on the real data:
            // "Chakki . 650" was the one token we cannot place, and dropping it
            // turned a 6-line/21.0 kg day into 5 lines/20.35 kg, missing its
            // own already-recorded entry (ledger 19307) and offering to record
            // the night twice. Money is unknown for an unplaced line, so it
            // contributes to COUNT and QUANTITY only.
            $qty   = round(array_sum(array_column($lines, 'quantity'))
                         + array_sum(array_column($unplaced, 'quantity')), 3);
            $count = count($lines) + count($unplaced);
            $total = round(array_sum(array_map(fn($l) => $l['quantity'] * $l['rate'], $lines)), 2);
            $partial = !empty($unplaced);   // total is known to be short

            $rows = DB::table('t_fin_ledger')
                ->where('transaction_type', 'vendor_purchase')
                ->where('to_account_id', $accountId)
                ->whereNotIn('approval_status', ['rejected', 'reversed'])
                ->whereBetween('transaction_date', [
                    Carbon::parse($date)->subDays(2)->toDateString(),
                    Carbon::parse($date)->addDays(2)->toDateString(),
                ])
                ->orderByDesc('transaction_date')
                ->get(['id', 'transaction_date', 'amount']);

            foreach ($rows as $row) {
                $existing = $this->describeExisting($row);
                $similar  = $this->looksLikeSame($existing, $count, $qty, $total, $partial);
                $sameDate = substr((string) $row->transaction_date, 0, 10) === $date;

                if ($sameDate && $similar) {
                    return ['verdict' => 'skip', 'existing' => $existing];
                }
                if ($sameDate) {
                    return ['verdict' => 'ask_same', 'existing' => $existing];
                }
                if ($similar) {
                    return ['verdict' => 'ask_near', 'existing' => $existing];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[PurchaseLog] dedupe: ' . $e->getMessage());
        }
        return ['verdict' => 'ok', 'existing' => null];
    }

    /**
     * ⭐ ONE product resolver for BOTH entry paths (owner-reported, Aug-2026).
     *
     * The WhatsApp-log reader could price a line from the vendor catalogue, but
     * a slip photographed or a line TYPED in chat ("Jilani mutton whole 13.5")
     * went nowhere near this class — the model had no way to see the catalogue,
     * so it either invented a rate (13.5 kg booked at 1,650 when the catalogue
     * says 2,575 — ledger 19595, deleted and re-entered by hand) or refused
     * five times in a row for want of a rate it already had (Imran Qureshi,
     * 21-Aug 02:37→02:40). Same lookup, same taught aliases, either way in.
     *
     * @return object|null a t_fin_vendor_products row
     */
    public function resolveProduct(int $vendorId, ?string $name): ?object
    {
        $token = trim((string) $name);
        if ($token === '') {
            return null;
        }
        // Strip a trailing quantity if the caller passed the raw phrase.
        $token = $this->tokenFrom($token) ?: $token;

        $taught = $this->taughtProduct($vendorId, $token);
        if ($taught) {
            return $taught;
        }
        $products = $this->vendorProducts($vendorId);
        // ⚠ NO default fallback here. parseLines() may fall back to the default
        // product for a BARE NUMBER in a chat log, where the vendor's own habit
        // makes that unambiguous. A name we could not match is the opposite
        // signal — guessing "the usual" would book meat against the wrong
        // product. Unmatched returns null so the caller asks with chips.
        return $this->matchProduct($token, $products, null);
    }

    /** The vendor's purchase products (NEVER the sales catalog). */
    public function vendorProducts(int $vendorId): array
    {
        return DB::table('t_fin_vendor_products')
            ->where('vendor_id', $vendorId)
            ->where('is_active', 1)
            ->orderByDesc('is_default')
            ->orderBy('product_name')
            ->get(['id', 'product_name', 'unit', 'rate_per_unit', 'is_default'])
            ->all();
    }

    /**
     * The rate to pre-fill. ⭐ THE CATALOG IS THE RULE (owner, Aug-2026):
     * "rates usually go by catalog, and in certain exceptions they change".
     * So the catalog rate leads; the last purchase is only a fallback for a
     * product the catalog has no rate for. Exceptions (Cow Brain has been
     * bought at 300 / 350 / 800 / 1000) are handled where they belong — on the
     * card, which Taimur can edit before confirming.
     */
    public function rateFor(object $product): float
    {
        $catalog = (float) ($product->rate_per_unit ?? 0);
        if ($catalog > 0) {
            return round($catalog, 2);
        }
        $last = DB::table('t_fin_vendor_purchase_items')
            ->where('vendor_product_id', $product->id)
            ->orderByDesc('id')
            ->value('rate_per_unit');
        return round((float) ($last ?: 0), 2);
    }

    /**
     * Has this product actually been bought at other rates? Used to mark the
     * line "rate varies" so a catalog figure is never presented as settled
     * fact on exactly the products where it isn't.
     */
    public function rateSourceFor(object $product): array
    {
        $catalog = round((float) ($product->rate_per_unit ?? 0), 2);
        $last = DB::table('t_fin_vendor_purchase_items')
            ->where('vendor_product_id', $product->id)
            ->orderByDesc('id')
            ->value('rate_per_unit');
        $last = $last !== null ? round((float) $last, 2) : null;

        // ⚠ FLAG ONLY WHAT IS ACTIONABLE. "This product has ever been bought at
        // two prices" is true of almost everything and becomes noise that gets
        // ignored — on live data it lit up EVERY mutton line, because one
        // single line months ago used an adjusted rate. What is worth saying is
        // narrower: the last time we bought this, we did NOT pay the catalogue
        // price. That is the Cow Brain case (catalogue 800, last paid 350) and
        // it is the only one where the pre-filled figure is likely wrong.
        $differs = $catalog > 0 && $last !== null && abs($last - $catalog) > max(0.01, $catalog * 0.01);

        return [
            'from'      => $catalog > 0 ? 'catalog' : 'last_purchase',
            'varies'    => $differs,
            'catalog'   => $catalog ?: null,
            'last_paid' => $last,
        ];
    }

    // ── internals ───────────────────────────────────────────────────────────

    /** "Taimoor Ali (Jilani Meat…" → WAGROUP:TAIMOOR ALI JILANI MEAT */
    public function groupKey(string $title): ?string
    {
        $k = mb_strtoupper(trim(preg_replace('/\s+/', ' ',
            preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $title))));
        return $k === '' ? null : mb_substr('WAGROUP:' . $k, 0, 64);
    }

    /** Active vendors whose distinctive name tokens appear in any haystack. */
    private function vendorsNamedIn(array $haystacks): array
    {
        $haystacks = array_filter(array_map('mb_strtolower', array_map('trim', $haystacks)));
        if (empty($haystacks)) {
            return [];
        }
        $hits = [];
        foreach (DB::table('t_fin_vendors')->where('is_active', 1)->get(['id', 'vendor_name']) as $v) {
            foreach ($this->distinctTokens($v->vendor_name) as $token) {
                foreach ($haystacks as $h) {
                    if (str_contains($h, $token)) {
                        $hits[$v->id] = $v->vendor_name;
                        break 2;
                    }
                }
            }
        }
        return $hits;
    }

    /** Tokens of a vendor name worth matching on (drops generic words). */
    private function distinctTokens(string $name): array
    {
        $stop = ['meat', 'mutton', 'foods', 'food', 'and', 'the', 'beef', 'farms'];
        $parts = preg_split('/[^\p{L}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_filter($parts,
            fn($p) => mb_strlen($p) >= 4 && !in_array($p, $stop, true)));
    }

    /**
     * The weight in a line. "12.5" → 12.5 · "5.750" → 5.75 · ". 650" → 0.650
     * (the butcher writes grams after a bare dot).
     */
    private function quantityFrom(string $text): ?float
    {
        // A lone ". 650" / ".650" means grams.
        if (preg_match('/(?<![\d.])\.\s*(\d{2,3})\s*$/u', $text, $m)) {
            return round(((float) $m[1]) / 1000, 3);
        }
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*$/u', $text, $m)) {
            return round((float) str_replace(',', '.', $m[1]), 3);
        }
        return null;
    }

    /**
     * The vendor product a spoken/typed phrase names, or null to ask.
     *
     * ⚠⚠ REWRITTEN Aug-2026 — the old substring rules got two things wrong on
     * the REAL catalogues, both of which mis-price money:
     *   • Products are numbered ("1. Mutton Whole", "3. Veal boneless"), so the
     *     old "does the phrase contain the product's first word" test matched
     *     "3." inside the quantity "13.5" — a weight could pick a product.
     *   • Its fallback returned the FIRST product sharing any 4-letter word, in
     *     alphabetical order, so "veal undercut" resolved to "Veal boneless"
     *     (Rs 1,650 instead of Rs 2,500) — and every other "veal …" phrase with
     *     it. Verified against Jilani, Imran, Ghousia and Meat Inn.
     *
     * Now scored on WORD OVERLAP, which is what a human reads:
     *   1. most query words matched wins  ("veal undercut" → 4. Veal Undercut)
     *   2. then the vendor's DEFAULT product ("Mutton" → 1. Mutton Whole, not
     *      Mutton Paaye — the bare word means his usual cut)
     *   3. then the tightest name (fewest words the phrase didn't ask for)
     * A genuine tie returns null so the caller asks with chips: "veal" alone
     * really is ambiguous between Trotters, Bong and Nalli.
     */
    private function matchProduct(string $token, array $products, ?object $default): ?object
    {
        $qt = $this->words($token);
        if (empty($qt)) {
            // Nothing nameable in the phrase (a bare weight). Only parseLines
            // passes a default here, and only because the vendor's own habit
            // makes a bare number mean his usual product.
            return $default;
        }

        $best = null;
        $bestKey = null;
        $tied = false;

        foreach ($products as $p) {
            $pt = $this->words($p->product_name);
            if (empty($pt)) {
                continue;
            }
            $overlap = count(array_intersect($qt, $pt));
            if ($overlap === 0) {
                continue;
            }
            $key = [$overlap, (int) ($p->is_default ?? 0), -(count($pt) - $overlap)];
            if ($bestKey === null || $key > $bestKey) {
                $bestKey = $key;
                $best = $p;
                $tied = false;
            } elseif ($key === $bestKey) {
                $tied = true;
            }
        }

        return ($best && !$tied) ? $best : null; // ambiguous → caller asks
    }

    /**
     * Comparable words of a name: letters only, lowercased, 2+ chars. Drops the
     * "1." / "4." ordering prefixes the catalogues use and every punctuation
     * separator, so "4. Veal Undercut - Imran" reads as [veal, undercut, imran].
     */
    private function words(string $s): array
    {
        $parts = preg_split('/[^\p{L}]+/u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_filter(
            $parts,
            fn($w) => mb_strlen($w) >= 2 && !self::isSizeWord($w)
        )));
    }

    /**
     * ⚠⚠ Oct-17-2026: a SIZE is not a NAME. "Non-Woven Bag 50gm" auto-matched "Long 50gm"
     *    (cloves) on the one shared word "gm", and the card showed the bag as a certain
     *    match. "Sonf 100g" drew "Bari Elaichi 100gm" as a did-you-mean the same way. So
     *    unit words — and digits-plus-unit tokens from a matcher that keeps digits — count
     *    for nothing in BOTH matchers (this one and the receipt card's did-you-mean).
     */
    public static function isSizeWord(string $w): bool
    {
        $w = mb_strtolower(trim($w));
        if ($w === '') {
            return true;
        }
        return (bool) preg_match(
            '/^(?:\d+(?:\.\d+)?(?:g|gm|gms|gram|grams|kg|kgs|mg|ml|l|lt|ltr|litre|liter|pcs|pc|pk|pkt|oz|s)?'
            . '|g|gm|gms|gram|grams|kg|kgs|mg|ml|lt|ltr|litre|liter|pcs|pc|pk|pkt|oz)$/u',
            $w
        );
    }

    /** Line count / quantity / total of an existing purchase, for comparison. */
    private function describeExisting(object $row): array
    {
        $items = DB::table('t_fin_vendor_purchase_items')
            ->where('ledger_id', $row->id)
            ->get(['quantity', 'product_name', 'rate_per_unit']);

        return [
            'ledger_id' => (int) $row->id,
            'date'      => substr((string) $row->transaction_date, 0, 10),
            'amount'    => round((float) $row->amount, 2),
            'lines'     => $items->count(),
            'quantity'  => round((float) $items->sum('quantity'), 3),
            'summary'   => $items->take(4)->map(fn($i) => trim($i->quantity + 0 . ' ' . $i->product_name))->implode(', '),
        ];
    }

    /**
     * Same purchase? Compared on shape rather than one number: an equal line
     * count with near-equal total quantity is decisive, and a near-equal money
     * total corroborates. Deliberately tolerant — this decides whether to ASK,
     * and asking costs a tap while a silent double-entry costs the khata.
     */
    private function looksLikeSame(array $existing, int $count, float $qty, float $total, bool $partial = false): bool
    {
        if ($existing['lines'] === 0) {
            return abs($existing['amount'] - $total) <= max(1, $total * 0.01);
        }
        $qtyClose   = $existing['quantity'] > 0 && abs($existing['quantity'] - $qty) <= 0.05 * max($qty, 0.001);
        $totalClose = abs($existing['amount'] - $total) <= max(1, $total * 0.02);

        // Same number of weighings AND the same weight is decisive on its own —
        // that is the shape of a day, and it is what caught the real Aug-17
        // duplicate. The money check only corroborates, and is skipped when a
        // line is unpriced (our total is knowingly short, so a mismatch there
        // says nothing).
        return ($existing['lines'] === $count && $qtyClose)
            || (!$partial && $qtyClose && $totalClose);
    }
}
