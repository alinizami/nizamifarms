<?php

namespace App\Http\Controllers\Khaas;

use App\Http\Controllers\Controller;
use App\Models\FIN\ReceiptDraftModel;
use App\Models\FIN\VendorModel;
use App\Models\Khaas\IngredientModel;
use App\Services\Assistant\PurchaseLogService;
use App\Services\Khaas\ReceiptExtractionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Photograph a receipt, read it, show it back, and let a person confirm it.
 *
 * ⭐⭐ THE ONE RULE THIS FEATURE LIVES BY: nothing here writes money. The card that
 * comes out of this controller is a SUGGESTION. It becomes a purchase only when the
 * person presses Submit, and only by going through
 * VendorController::recordWeightedPurchase — the same door a hand-typed purchase uses,
 * with the same validation, the same ledger posting and the same images. That is the
 * rule the NF Assistant already follows when it replays a WhatsApp purchase log, and it
 * is why a bad read can waste a minute but can never book a wrong purchase.
 *
 * ⭐ Names are matched with the Assistant's OWN resolver (PurchaseLogService), which
 * already carries every taught alias for this vendor. A receipt line learned here is
 * therefore learned everywhere, and there is no second matcher to drift.
 */
class ReceiptController extends Controller
{
    /** Bounded so a stuck client cannot spend the month's credit in an afternoon. */
    private const MAX_PER_HOUR = 20;

    /**
     * ⚠⚠ GATED THE SAME WAY THE PURCHASE ITSELF IS, NOT MORE TIGHTLY.
     *
     * The first version required `manage_vendor_transactions`. Qasim does not hold it —
     * and he has recorded 314 of the frozen vendor purchases, more than anyone. He would
     * have been the one person unable to use the bill scanner, which is the whole point
     * of it. Caught on the device, 22-Sep.
     *
     * The ordinary weighted-purchase endpoint this replays into carries NO permission
     * check at all, so gating the READ harder than the WRITE was backwards. Anyone who
     * can reach the vendor screens can scan a bill; the money door is unchanged.
     */
    private function canRecord(): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        foreach (['manage_vendor_transactions', 'access_khaas_mode', 'access_store_mode'] as $key) {
            if ($user->hasMobilePermission($key)) {
                return true;
            }
        }

        return false;
    }

    /** One honest sentence per failure reason (ReceiptExtractionService::FAIL_*). */
    private function failureMessage(string $reason): string
    {
        $saved = ' The picture is saved either way.';

        return match ($reason) {
            ReceiptExtractionService::FAIL_TIMEOUT,
            ReceiptExtractionService::FAIL_LOOPED,
            ReceiptExtractionService::FAIL_BUSY =>
                'The bill reader is busy right now and did not finish. Wait a minute and try again, '
                . 'or type the bill in by hand.' . $saved,
            ReceiptExtractionService::FAIL_NO_CREDIT =>
                'The bill reader is out of credit. Tell Shabib, and type this bill in by hand for now.' . $saved,
            ReceiptExtractionService::FAIL_REFUSED,
            ReceiptExtractionService::FAIL_NO_KEY =>
                'The bill reader is not set up correctly. Tell Shabib, and type this bill in by hand for now.' . $saved,
            ReceiptExtractionService::FAIL_NO_IMAGE =>
                'The photo did not arrive properly. Take it again.',
            default =>
                'The reader could not make out this bill. Lay the whole slip flat, fill the frame with it '
                . 'and try again — or type it in by hand.' . $saved,
        };
    }

    /**
     * Photo in, draft + card out.
     *
     * ⚠ The image is stored and the draft row written BEFORE the model is called, so a
     *   model failure leaves a draft with a picture to retry — never a lost receipt.
     */
    public function extract(Request $request, $vendorId)
    {
        if (!$this->canRecord()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this vendor screen. Ask Taimur or Shabib to enter this bill.',
            ], 403);
        }

        // ⚠ 5 MB, the SAME ceiling as the purchase's own bill_image (VendorController::
        //   imageValidationRules). It used to be 8 MB here, so a 5–8 MB photo read fine and
        //   then could never be recorded — Record replays the photo into that door.
        $request->validate([
            'image'       => 'required|image|mimes:jpeg,png,jpg|max:5120',
            'client_uuid' => 'nullable|string|max:36',
        ], [
            'image.max' => 'That photo is too large (over 5 MB). Take it again with the camera, or pick a smaller copy.',
        ]);

        $vendor = VendorModel::find($vendorId);
        if (!$vendor) {
            return response()->json(['success' => false, 'message' => 'That vendor no longer exists.'], 404);
        }

        if ($this->overRateLimit()) {
            return response()->json([
                'success' => false,
                'message' => 'That is a lot of receipts in one hour. Take a break and try again shortly, '
                    . 'or type this bill in by hand.',
            ], 429);
        }

        // ⚠ client_uuid is minted when the CARD OPENS on the client, not at Submit, so a
        //   retry after a timeout resolves to the same draft instead of a second one.
        //   ⚠⚠ Validated to a real uuid shape AND scoped to this user. A client sending
        //   a constant like "1" would otherwise collide across people and hand one
        //   person's photo to another.
        $uuid = (string) $request->input('client_uuid');
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            $uuid = (string) Str::uuid();
        }

        $draft = ReceiptDraftModel::where('client_uuid', $uuid)
            ->where('created_by', auth()->id())
            ->first();

        if ($draft && $draft->status === ReceiptDraftModel::STATUS_SUBMITTED) {
            return response()->json([
                'success'   => false,
                'message'   => 'This receipt has already been recorded.',
                'ledger_id' => $draft->ledger_id,
            ], 409);
        }

        // ── store the photo ────────────────────────────────────────────────
        // ⚠⚠ The extension comes from the GUESSED mime, never from the client's
        //    filename. `mimes:jpeg,png,jpg` validates the file's CONTENT, so a file
        //    named "receipt.php" carrying real JPEG bytes passes it — and this disk is
        //    served under the docroot at /storage. Taking the client's extension would
        //    write an executable name into a web-served directory.
        $file = $request->file('image');
        $ext  = match ($file->getMimeType()) {
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
            default      => 'jpg',
        };
        $path = 'receipts/' . date('Y/m') . '/r-' . uniqid() . '.' . $ext;

        Storage::disk(config('whatsapp.media_disk', 'public'))
            ->put($path, file_get_contents($file->getRealPath()));

        if (!$draft) {
            $draft = ReceiptDraftModel::create([
                'client_uuid' => $uuid,
                'vendor_id'   => (int) $vendorId,
                'image_path'  => $path,
                'status'      => ReceiptDraftModel::STATUS_DRAFT,
                'created_by'  => auth()->id(),
            ]);
        } else {
            // A retry replaces the photo. The old one is never recorded (Record only ever
            // replays the draft's CURRENT photo), so drop it rather than leave it orphaned.
            $previous = $draft->image_path;
            $draft->update(['image_path' => $path, 'vendor_id' => (int) $vendorId]);
            if ($previous && $previous !== $path && str_starts_with($previous, 'receipts/')) {
                try {
                    Storage::disk(config('whatsapp.media_disk', 'public'))->delete($previous);
                } catch (\Throwable $e) {
                    // housekeeping only
                }
            }
        }

        // ⚠ Counted here, on every READ, not by counting draft rows: a client that
        //   holds one uuid — exactly what the retry design tells it to do — would
        //   otherwise call the vision model without limit and never create a second row.
        $draft->increment('extract_count');

        // ── read it ────────────────────────────────────────────────────────
        // Resolved from the container, not newed up, so a test can bind a fake reader
        // and prove the card end to end without spending a real model call.
        $reader = app(ReceiptExtractionService::class);
        $read   = $reader->extract($path);

        if (!$read) {
            // ⚠⚠ Tell the truth about WHY. The old single sentence ("try again in better
            //    light") was wrong for every failure production ever had — they were all
            //    the model stalling — and it sent Qasim re-photographing a sharp bill.
            $failure = method_exists($reader, 'lastFailure') ? $reader->lastFailure() : null;
            $reason  = $failure['reason'] ?? ReceiptExtractionService::FAIL_UNREADABLE;

            $draft->update([
                'status' => ReceiptDraftModel::STATUS_FAILED,
                'error'  => mb_substr(sprintf(
                    '%s: %s (HTTP %s, %ss, %d attempt%s)',
                    $reason,
                    $failure['detail'] ?? 'no detail',
                    $failure['status'] ?? '-',
                    $failure['seconds'] ?? '?',
                    $failure['attempts'] ?? 0,
                    ($failure['attempts'] ?? 0) === 1 ? '' : 's'
                ), 0, 250),
            ]);

            return response()->json([
                'success'  => false,
                'draft_id' => $draft->id,
                'reason'   => $reason,
                'message'  => $this->failureMessage($reason),
            ], 422);
        }

        $card = $this->buildCard($vendor, $read, $reader);

        $draft->update([
            'model'       => $read['model'] ?? null,
            'raw_json'    => $read['raw'] ?? null,
            'parsed_json' => json_encode($card),
            'tokens_in'   => $read['tokens_in'] ?? null,
            'tokens_out'  => $read['tokens_out'] ?? null,
            'status'      => ReceiptDraftModel::STATUS_DRAFT,
            'error'       => null,
        ]);

        return response()->json([
            'success'     => true,
            'draft_id'    => $draft->id,
            'client_uuid' => $uuid,
            'card'        => $card,
        ]);
    }

    /**
     * Turn what the model read into what a person confirms.
     *
     * Every line comes back, in printed order, whether or not it matched anything. A
     * line we could not place is shown EMPTY and flagged, never guessed — guessing here
     * would put meat against the wrong product, and the whole point of the card is that
     * a human looks at it.
     */
    /**
     * The products most likely to be what a printed line means — at most three.
     *
     * ⭐ Deliberately WEAKER than the matcher that already failed. `resolveProduct()` is
     * strict because a wrong match books meat against the wrong product silently. These
     * are the opposite: loose, ranked, and shown as a question a person answers. Nothing
     * here is ever applied on its own.
     *
     * ⚠ The score is a shared-word count, not an edit distance. A till slip writes
     *   "POTATO LOOSE" for "Potato (Aaloo)" — a whole word in common, three characters
     *   apart. Words are what these names have in common; spelling is not.
     */
    private function closestProducts(string $rawName, array $catalogue): array
    {
        // ⚠ Unique words only — "ONION ONION" must not score a product twice.
        $words = fn (string $s) => array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s)) ?: [],
            fn ($w) => mb_strlen($w) >= 3
        )));

        $want = $words($rawName);
        if (!$want) {
            return [];
        }

        $scored = [];
        // ⚠ The catalogue is passed in, read ONCE per card. The first version re-queried it
        //   for every unmatched line — fifteen queries for a fifteen-line slip nobody had
        //   catalogued yet, which is exactly the slip this feature is for.
        foreach ($catalogue as $p) {
            $have  = $words((string) $p->product_name);
            $score = 2 * count(array_intersect($want, $have));

            // ⚠⚠ A NEAR-SPELLING IS THE WHOLE POINT, and a prefix test alone misses the
            //    commonest case here: "Aloo" against "Aaloo" differs in its FIRST letter,
            //    so no shared prefix exists. (This very pair was seeded into the ingredient
            //    list by accident on 22-Sep, so it is not hypothetical.) "Gobi"/"Gobhi" and
            //    "Piyaz"/"Piyaaz" are the same shape. So: a shared prefix OR one or two
            //    characters of edit distance, scaled to the word's length.
            foreach ($want as $w) {
                foreach ($have as $h) {
                    if (in_array($w, $have, true)) {
                        continue 2;   // already counted, and worth double
                    }
                    if (str_starts_with($h, mb_substr($w, 0, 4)) || str_starts_with($w, mb_substr($h, 0, 4))) {
                        $score += 1;
                        continue 2;
                    }
                    $allow = mb_strlen($w) <= 5 ? 1 : 2;
                    if (abs(mb_strlen($w) - mb_strlen($h)) <= $allow && levenshtein($w, $h) <= $allow) {
                        $score += 1;
                        continue 2;
                    }
                }
            }

            if ($score > 0) {
                $scored[] = ['score' => $score, 'product' => $p];
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']
            ?: strcmp((string) $a['product']->product_name, (string) $b['product']->product_name));

        return array_map(fn ($s) => [
            'id'   => (int) $s['product']->id,
            'name' => (string) $s['product']->product_name,
            'unit' => $s['product']->unit ?? null,
            'rate' => (float) ($s['product']->rate_per_unit ?? 0),
        ], array_slice($scored, 0, 3));
    }

    private function buildCard(VendorModel $vendor, array $read, ReceiptExtractionService $reader): array
    {
        $resolver = new PurchaseLogService();

        // Ingredient columns are not in the Assistant's own select, so read them here.
        $ingredientCols = DB::table('t_fin_vendor_products')
            ->where('vendor_id', $vendor->id)
            ->get(['id', 'ingredient_id', 'pack_qty_base'])
            ->keyBy('id');

        $ingredients = IngredientModel::where('is_active', 1)->get()->keyBy('id');

        // Read once; used for the "did you mean" ranking on every unmatched line and for
        // telling an empty catalogue apart from a failed match.
        $catalogue = $resolver->vendorProducts((int) $vendor->id);

        $lines = [];
        foreach ($read['lines'] as $raw) {
            $match = null;
            try {
                $match = $resolver->resolveProduct((int) $vendor->id, $raw['raw_name']);
            } catch (\Throwable $e) {
                $match = null;
            }

            $qty   = $raw['qty'];
            $rate  = $raw['unit_price'];
            $total = $raw['line_total'];

            // Fill in the third number when two are printed and one is not. This is
            // arithmetic on what is printed, not a guess about what was bought.
            if ($total === null && $qty !== null && $rate !== null) {
                $total = round($qty * $rate, 2);
            } elseif ($rate === null && $qty > 0 && $total !== null) {
                $rate = round($total / $qty, 2);
            }

            $productId   = $match->id ?? null;
            $cols        = $productId ? ($ingredientCols[$productId] ?? null) : null;
            $ingredientId = $cols->ingredient_id ?? null;
            $packQty      = (float) ($cols->pack_qty_base ?? 0);
            $ingredient   = $ingredientId ? $ingredients->get($ingredientId) : null;

            // What this line means in ingredient terms, shown so the person can see it
            // before it is saved rather than discovering it a month later on a report.
            $qtyBase = ($ingredient && $packQty > 0 && $qty !== null)
                ? round($qty * $packQty, 3)
                : null;

            // ⚠⚠ A PRINTED DISCOUNT the line total already took off: "Non-Woven Bag 2 × 65,
            //    discount 60, Rs 70". The purchase is recorded as qty × rate, so without this
            //    the bag was booked at Rs 130 and the whole bill Rs 60 over — with no warning,
            //    because the total check reads the printed (discounted) line totals.
            $discount = (float) ($raw['discount'] ?? 0);
            $discountApplied = $discount > 0 && $qty !== null && $rate !== null && $total !== null
                && abs(($qty * $rate) - $discount - $total) <= 1.0;

            // ⭐ The unit a NEW product for this line should start with — from the one unit
            //   engine, never a guessed kg ("Eggs 30's" is a pack of 30 pieces).
            $prefill = \App\Services\FIN\VendorUnits::fromReceipt(
                $raw['sold_by'] ?? null, $raw['pack_size_value'] ?? null, $raw['pack_size_unit'] ?? null
            );

            $lines[] = [
                'discount_applied' => $discountApplied,
                'suggested_unit'   => $prefill['unit'],
                'suggested_pack_qty_base' => $prefill['pack_qty_base'],
                'raw_name'        => $raw['raw_name'],
                'qty'             => $qty,
                'unit_price'      => $rate,
                'line_total'      => $total,
                'discount'        => $raw['discount'],
                'sold_by'         => $raw['sold_by'],
                'pack_size_value' => $raw['pack_size_value'],
                'pack_size_unit'  => $raw['pack_size_unit'],

                // the match, or an honest blank
                'product_id'      => $productId,
                'product_name'    => $match->product_name ?? null,
                'unit'            => $match->unit ?? null,
                'matched'         => (bool) $match,

                // what it means for Frozen
                'ingredient_id'   => $ingredientId,
                'ingredient_name' => $ingredient->name ?? null,
                'qty_base'        => $qtyBase,
                'qty_base_text'   => ($ingredient && $qtyBase !== null) ? $ingredient->phrase($qtyBase) : null,

                // ⭐ "Not found" is a dead end. When we cannot place a line, offer the
                //   closest products this vendor already has so the answer is usually one
                //   tap — and if none of them fit, the card offers adding it as new.
                'suggestions'     => $match ? [] : $this->closestProducts((string) $raw['raw_name'], $catalogue),

                // a line the person can tick off as not-an-ingredient
                'not_ingredient'  => false,
                'needs_attention' => !$match || $qty === null || $rate === null,
            ];
        }

        $check = $reader->reconcile($lines, $read['grand_total']);

        // Lines are recorded at their printed PRICE, so the printed discounts go into the
        // bill's adjustment instead — the card pre-fills it and says so.
        $lineDiscounts = round(array_sum(array_map(
            fn ($l) => $l['discount_applied'] ? (float) $l['discount'] : 0.0, $lines
        )), 2);

        $warnings = [];
        if ($lineDiscounts > 0) {
            $warnings[] = sprintf(
                'The bill takes Rs %s off in discounts. That is filled into the adjustment (−%s) so the purchase '
                . 'matches the paper — check it before recording.',
                number_format($lineDiscounts, 2), number_format($lineDiscounts, 2)
            );
        }
        if (!$check['matches']) {
            $warnings[] = sprintf(
                'The lines add up to Rs %s but the receipt says Rs %s. Fix a line, or put the Rs %s '
                . 'difference in the adjustment box before saving.',
                number_format($check['lines_total'], 2),
                number_format((float) $check['printed_total'], 2),
                number_format(abs($check['difference']), 2)
            );
        }

        // ⚠⚠ AN EMPTY CATALOGUE IS NOT A FAILED MATCH, and saying "3 lines could not be
        //    matched" when the vendor has NO products is both useless and misleading —
        //    it sends the person hunting for a picker that has nothing in it. Say the
        //    real thing and name the way out. Owner asked for this, 22-Sep.
        $catalogueSize = count($catalogue);
        $unmatched = count(array_filter($lines, fn ($l) => !$l['matched']));

        if ($catalogueSize === 0) {
            $warnings[] = 'This vendor has no products yet, so nothing on the bill can be matched. '
                . 'Add each item as a product — you can do it from here, one line at a time.';
        } elseif ($unmatched > 0) {
            $warnings[] = $unmatched === 1
                ? 'One line could not be matched to this vendor\'s product list. Pick the product it '
                    . 'means, or add it as a new one.'
                : $unmatched . ' lines could not be matched to this vendor\'s product list. Pick the '
                    . 'product each one means, or add it as a new one.';
        }

        if (($read['confidence'] ?? 'low') === 'low') {
            $warnings[] = 'The photo was hard to read, so check every line against the paper.';
        }

        if ($read['grand_total'] === null) {
            $warnings[] = 'The bill\'s own total could not be read, so nothing here can be checked against it. '
                . 'Add the lines up against the paper yourself before recording.';
        }

        return [
            'vendor_id'      => (int) $vendor->id,
            'vendor_name'    => $vendor->vendor_name,
            'purchase_method' => $vendor->default_purchase_method,
            'store_name'     => $read['store_name'],
            'receipt_no'     => $read['receipt_no'],
            'receipt_date'   => $read['receipt_date'],
            'lines'          => $lines,
            'subtotal'       => $read['subtotal'],
            'discount_total' => $read['discount_total'],
            'grand_total'    => $read['grand_total'],
            'reconcile'      => $check,
            // ⭐ Negative: what the adjustment box should start at. 0 when nothing was discounted.
            'discount_adjustment' => $lineDiscounts > 0 ? -$lineDiscounts : 0.0,
            'confidence'     => $read['confidence'],
            'warnings'       => $warnings,
            // ⭐ How many products this vendor has at all. Zero means "add some", which is a
            //   different problem from "this line did not match" and needs a different screen.
            'catalogue_size' => $catalogueSize,
            // ⚠ The card NEVER auto-submits, whatever it says. This flag only decides
            //   whether Submit starts enabled or asks for a correction first.
            // ⚠ A receipt whose total could not be read is the LEAST checkable one, so it
            //   must not start with Submit enabled. reconcile() returns matches=true for
            //   a null total because there is nothing to disagree with — that is not the
            //   same as "checked", and conflating the two armed the button on exactly the
            //   card nobody could verify.
            'ready'          => $check['matches'] && $unmatched === 0 && $read['grand_total'] !== null,
        ];
    }

    /**
     * ⭐⭐ RECORD THE CARD — the one door a scanned bill becomes a purchase through.
     *
     * It does NOT write money itself. It replays the confirmed card into
     * VendorController::recordWeightedPurchase, exactly as
     * AssistantDraftService::replayWeightedPurchase does, so there is still one writer
     * of a vendor purchase in this codebase and it is the one that has always been.
     *
     * ⚠⚠ WHY THIS EXISTS AT ALL. The card used to POST straight at the weighted-purchase
     *    endpoint while the UI promised "press Record again, it will not book this
     *    twice". That promise was empty: nothing read client_uuid, nothing ever marked a
     *    draft submitted, and a gateway timeout after a successful commit would have
     *    booked the bill a second time. The draft row is the lock that makes the promise
     *    true — claimed under a row lock BEFORE the money call, released only if the
     *    money call fails.
     */
    public function record(Request $request, $vendorId)
    {
        if (!$this->canRecord()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this vendor screen. Ask Taimur or Shabib to enter this bill.',
            ], 403);
        }

        $uuid = (string) $request->input('client_uuid');
        if ($uuid === '') {
            return response()->json(['success' => false, 'message' => 'This card is missing its id. Re-open it and try again.'], 422);
        }

        // Claim the draft under a row lock, so two presses of Record — or a retry racing
        // the original — cannot both get past this point.
        $claim = DB::transaction(function () use ($uuid) {
            $draft = ReceiptDraftModel::where('client_uuid', $uuid)
                ->where('created_by', auth()->id())
                ->lockForUpdate()
                ->first();

            if (!$draft) {
                return ['state' => 'missing'];
            }
            if ($draft->status === ReceiptDraftModel::STATUS_SUBMITTED) {
                return ['state' => 'done', 'ledger_id' => $draft->ledger_id];
            }

            // Mark it submitted BEFORE the money call. A crash between here and the call
            // leaves a bill unrecorded, which somebody will notice and can re-enter. The
            // other order leaves a bill recorded twice, which nobody notices.
            $draft->update(['status' => ReceiptDraftModel::STATUS_SUBMITTED]);

            return ['state' => 'claimed', 'draft' => $draft];
        });

        if ($claim['state'] === 'missing') {
            return response()->json([
                'success' => false,
                'message' => 'That card is no longer open. Photograph the bill again.',
            ], 404);
        }

        if ($claim['state'] === 'done') {
            // Not an error from where the user stands: the bill IS recorded.
            return response()->json([
                'success'        => true,
                'already'        => true,
                'transaction_id' => $claim['ledger_id'],
                'message'        => 'This bill was already recorded — nothing was booked twice.',
            ]);
        }

        /** @var ReceiptDraftModel $draft */
        $draft = $claim['draft'];

        try {
            $response = app(\App\Http\Controllers\FIN\VendorController::class)
                ->recordWeightedPurchase($this->purchaseRequest($request, $vendorId, $draft), $vendorId);

            $body = json_decode($response->getContent(), true) ?: [];

            if (!($body['success'] ?? false)) {
                // The money call refused, so the claim must be given back — otherwise a
                // corrected re-submit would be told it had already been recorded.
                $draft->update(['status' => ReceiptDraftModel::STATUS_DRAFT]);
                return $response;
            }

            $draft->update(['ledger_id' => $body['transaction_id'] ?? null]);

            // ⭐⭐ LEARN THE SHOP'S OWN WORDING, so the same bill is never asked about twice.
            //
            // The machinery already existed and is already proven — the WhatsApp purchase
            // log has been teaching product aliases for months, and `resolveProduct()` has
            // always read them. The scanner simply never wrote any, so every scan re-asked
            // the same questions. Owner spotted it, 22-Sep.
            //
            // Its guards are the reason this is safe to call blind: it learns nothing from
            // a word used for two different products on the same bill, nothing for a
            // product that is not on this vendor's list, and nothing where ordinary name
            // matching already gets it right.
            $learned = $this->teachFromCard($request, (int) $vendorId);

            return response()->json([
                'success'        => true,
                'transaction_id' => $body['transaction_id'] ?? null,
                'learned'        => $learned,
                'message'        => $body['message'] ?? 'Purchase recorded.',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // ⚠ The money door's own validation (a blank qty, an oversized photo…). It used
            //   to fall into the catch-all below and read "Nothing was saved — try again",
            //   which no retry could ever fix. Hand back the real reason instead.
            $draft->update(['status' => ReceiptDraftModel::STATUS_DRAFT]);
            $errors = $e->errors();
            $first  = collect($errors)->flatten()->first();

            return response()->json([
                'success' => false,
                'message' => $first ? 'Not recorded: ' . $first : 'Not recorded — please check the lines.',
                'errors'  => $errors,
            ], 422);
        } catch (\Throwable $e) {
            $draft->update(['status' => ReceiptDraftModel::STATUS_DRAFT]);
            \Log::error('Receipt record failed', ['draft' => $draft->id, 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Could not record that purchase. Nothing was saved — try again.',
            ], 500);
        }
    }

    /**
     * Rebuild the request the weighted-purchase endpoint expects, carrying the uploaded
     * bill image through. Same shape a hand-typed purchase posts.
     */
    /**
     * Teach the resolver what this shop calls each product, from a card a person just
     * confirmed. Returns the names learned, for the message on screen.
     *
     * ⚠⚠ THIS MUST NEVER FAIL THE PURCHASE. The money is already booked by the time it
     *    runs; a teaching error is a lost convenience, not a lost bill. So everything
     *    here is inside one catch and the worst case is that it asks again next time.
     *
     * ⚠ It learns from `raw_name` — what the till slip PRINTED — paired with the product
     *   the person chose. `product_name` would teach a product's name as an alias for
     *   itself, which is worth nothing.
     */
    private function teachFromCard(Request $request, int $vendorId): array
    {
        try {
            $lines = [];
            foreach ((array) $request->input('items', []) as $item) {
                $raw = trim((string) ($item['raw_name'] ?? ''));
                $pid = (int) ($item['product_id'] ?? 0);
                if ($raw !== '' && $pid > 0) {
                    $lines[] = ['text' => $raw, 'product_id' => $pid];
                }
            }

            if (!$lines) {
                return [];
            }

            $resolver = new PurchaseLogService();

            // What it does NOT already know — worked out before teaching, so the message
            // can name what was actually learned rather than everything on the bill.
            $before = [];
            foreach ($lines as $l) {
                $known = $resolver->resolveProduct($vendorId, $l['text']);
                $before[$l['text']] = (int) ($known->id ?? 0);
            }

            $resolver->teachProductAliases($vendorId, $lines, auth()->id());

            $learned = [];
            foreach ($lines as $l) {
                $now = $resolver->resolveProduct($vendorId, $l['text']);
                if ((int) ($now->id ?? 0) === $l['product_id']
                    && ($before[$l['text']] ?? 0) !== $l['product_id']) {
                    $learned[$l['text']] = (string) $now->product_name;
                }
            }

            return array_map(
                fn ($raw, $product) => ['printed' => $raw, 'product' => $product],
                array_keys($learned),
                array_values($learned)
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Receipt teaching failed (purchase is safe)', [
                'vendor_id' => $vendorId,
                'error'     => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function purchaseRequest(Request $request, $vendorId, ?ReceiptDraftModel $draft = null): Request
    {
        $body = $request->except(['client_uuid', 'draft_id']);

        $files = $request->allFiles();
        // ⭐ A draft picked up again LATER ("Continue it") has no photo on the phone — the
        //   photo lives on the server with the draft. Without this the purchase was
        //   recorded with no bill image at all. Attach the draft's own photo instead.
        if (empty($files) && $draft && $draft->image_path) {
            try {
                $disk = Storage::disk(config('whatsapp.media_disk', 'public'));
                if ($disk->exists($draft->image_path)) {
                    $photo = new \Illuminate\Http\UploadedFile(
                        $disk->path($draft->image_path),
                        basename($draft->image_path),
                        $disk->mimeType($draft->image_path) ?: 'image/jpeg',
                        null,
                        true   // a file already on our disk, not a fresh upload
                    );
                    // ⚠ Sep-27: attach it only if the purchase's OWN photo rule accepts it.
                    //   A damaged file would otherwise fail that validation and refuse the
                    //   whole purchase — the opposite of "no photo beats no purchase".
                    //   (Found by PROOF-RECEIPT-CAPTURE, whose stand-in photo is not a real
                    //   image.)
                    $okPhoto = \Illuminate\Support\Facades\Validator::make(
                        ['p' => $photo], ['p' => 'image|mimes:jpeg,png,jpg,gif|max:5120']
                    )->passes();
                    if ($okPhoto) {
                        $files['bill_images'] = [$photo];
                    } else {
                        \Log::warning('Receipt record: draft photo not attached (not a valid image)', [
                            'draft_id' => $draft->id,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                // no photo is better than no purchase
            }
        }

        $fresh = Request::create(
            "/api/vendors/{$vendorId}/weighted-purchase",
            'POST',
            $body,
            [],
            $files,
            ['HTTP_ACCEPT' => 'application/json']
        );
        $fresh->setUserResolver($request->getUserResolver());

        return $fresh;
    }

    /** Drafts this person can still pick up — photograph three slips, confirm them later. */
    public function drafts(Request $request)
    {
        if (!$this->canRecord()) {
            return response()->json(['success' => false, 'message' => 'Not for you.'], 403);
        }

        $rows = ReceiptDraftModel::where('created_by', auth()->id())
            ->whereIn('status', [ReceiptDraftModel::STATUS_DRAFT, ReceiptDraftModel::STATUS_FAILED])
            ->where('created_at', '>=', now()->subDays(7))
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        return response()->json([
            'success' => true,
            'drafts'  => $rows->map(function (ReceiptDraftModel $d) {
                $card = $d->parsed();
                return [
                    'draft_id'    => (int) $d->id,
                    'client_uuid' => $d->client_uuid,
                    'vendor_id'   => $d->vendor_id,
                    'vendor_name' => $card['vendor_name'] ?? null,
                    'store_name'  => $card['store_name'] ?? null,
                    'grand_total' => $card['grand_total'] ?? null,
                    'lines'       => count($card['lines'] ?? []),
                    'status'      => $d->status,
                    'taken_at'    => optional($d->created_at)->toDateTimeString(),
                ];
            })->values(),
        ]);
    }

    public function show(Request $request, $id)
    {
        if (!$this->canRecord()) {
            return response()->json(['success' => false, 'message' => 'Not for you.'], 403);
        }

        $draft = ReceiptDraftModel::find($id);
        if (!$draft || $draft->created_by !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'That draft is not yours.'], 404);
        }

        return response()->json([
            'success'     => true,
            'draft_id'    => (int) $draft->id,
            'client_uuid' => $draft->client_uuid,
            'status'      => $draft->status,
            'card'        => $draft->parsed(),
        ]);
    }

    public function discard(Request $request, $id)
    {
        // ⚠ A write needs the same gate the surface does — ownership alone is not it.
        if (!$this->canRecord()) {
            return response()->json(['success' => false, 'message' => 'Not for you.'], 403);
        }

        $draft = ReceiptDraftModel::find($id);
        if (!$draft || $draft->created_by !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'That draft is not yours.'], 404);
        }

        if ($draft->status === ReceiptDraftModel::STATUS_SUBMITTED) {
            return response()->json([
                'success' => false,
                'message' => 'That receipt is already recorded. Delete the purchase itself if it was wrong.',
            ], 422);
        }

        $draft->update(['status' => ReceiptDraftModel::STATUS_DISCARDED]);

        return response()->json(['success' => true, 'message' => 'Draft discarded. The photo is kept.']);
    }

    /**
     * How many times this person has had the vision model read something in the last
     * hour — counting EXTRACTIONS, not draft rows.
     *
     * ⚠ Counting rows was wrong and looked right: `extract()` deliberately reuses the
     *   row when the uuid repeats, so a client following the retry design could call the
     *   model for ever behind a single row and never trip a row-based limit.
     */
    private function overRateLimit(): bool
    {
        try {
            return (int) ReceiptDraftModel::where('created_by', auth()->id())
                ->where('updated_at', '>=', now()->subHour())
                ->sum('extract_count') >= self::MAX_PER_HOUR;
        } catch (\Throwable $e) {
            // A database without the column yet must not block bill entry.
            return false;
        }
    }
}
