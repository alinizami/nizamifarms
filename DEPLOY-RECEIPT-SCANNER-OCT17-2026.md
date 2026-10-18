# Deploy — 🧾🤖 Bill scanner, ALL Oct-17 rounds in ONE checklist (17-Oct-2026)

Supersedes `DEPLOY-RECEIPT-DERAILED-OCT17-2026.md` and `DEPLOY-RECEIPT-TWO-CALL-MATCH-OCT17-2026.md`
(their changes are inside this one). **Web FIRST, then APK.** No SQL. No routes → no `/xclean`.

## What Qasim will see after this
Scan the Oct-17 Imtiaz slip again:
- **26 of 26 lines** (the reader no longer carries the product lists in the photo call; that is what cut
  the slip to one line). Live-walked on dev: 26/26, sum = printed Rs 15,665.28, 14.6 s.
- Lines FILL IN or are OFFERED, in this order of trust: the vendor's remembered wording → a word match
  → the reader's "Yes, use it" (now also when the reader could only name the INGREDIENT and a product
  on this vendor is already linked to it) → "Did you mean" (linked products first) → the full list,
  **searchable**, linked products first → "add it as a new product".
- The carrier bag is **no longer "matched" to Long 50gm (cloves)** — a size word is not a name.
- **"➕ Add a line the reader missed"** on the card: a hand-added line on the SAME bill.
- A failed read offers **"Type it in instead"**: Record Purchase opens with the photo attached.

## Web files (3, WinSCP)

| # | File | Change |
|---|------|--------|
| 1 | `app/Services/Khaas/ReceiptExtractionService.php` | photo call without lists; `matchLines()` text-only matching call; `derailed()` guard; `read ok` / `match ok` logs |
| 2 | `app/Http/Controllers/Khaas/ReceiptController.php` | `derailed` sentence; did-you-mean ignores size words and puts LINKED products first on ties; reader's ingredient guess → the linked product (`ai_suggestion`) instead of "add new" |
| 3 | `app/Services/Assistant/PurchaseLogService.php` | `isSizeWord()`; `words()` drops unit/size tokens. ⚠ SHARED with the WhatsApp purchase log (same matcher) — a "2 kg chicken" message loses nothing, "kg" never named a product |

## APK (3 files → next build)

| # | File | Change |
|---|------|--------|
| 1 | `src/components/ReceiptScanModal.js` | picker search box (`rc-search-N`, shown when > 6 products) + linked-first order; "➕ Add a line the reader missed" (`rc-add-line`); "Type it in instead" on a failed read (`rc-type-instead`); row testIDs |
| 2 | `src/screens/VendorDetailScreen.js` | wires `onTypeInstead` → opens Record Purchase with the photo |
| 3 | `src/components/WeightedPurchaseModal.js` | new `initialImages` prop (photo pre-attached) |

Old APK against new web: still fine (the card shape is unchanged; `ai_suggestion` was already rendered).
New APK against old web: fine too (add-a-line and search are client-side; "Type it in" is client-side).

## Proof
- Server: `php test_receipt_matcher_oct17.php` 51/51 (size words; bag ≠ cloves; did-you-mean without size
  noise; linked-first tie; ingredient→linked-product bridge; the 26 printed lines: 11 auto, 11 did-you-mean,
  4 blank — the blanks are genuinely not on the list: Sugar, Sonf, Qasuri Methi, the bag).
  `php test_receipt_two_call_match_oct17.php` 32/32 · `php test_receipt_derailed_oct17.php` 27/27 (HTTP faked).
- Phone: `npx jest __tests__/receiptCardDoorsOct17.test.js` 6/6 (add-a-line posts `items[1][product_name]`
  with no `raw_name`; search filters and keeps the "add new" way out; a word typed for one line does not
  hide another's list; "Type it in instead" hands the photo over; absent without the prop). Neighbouring
  suites 328/328. ⚠ Phone NOT device-walked.
- Live model: one read of the owner's photo on dev through the two-call path (see TWO-CALL note).
- **No-device verification (owner could not attach a phone):**
  - `php test_receipt_http_e2e_oct17.php` 34/34 — the REAL routes through the HTTP kernel as Qasim (user 91,
    Sanctum): `GET /vendors/50/products/list` (40 products, `ingredient_name` on each, supports_ingredients),
    `POST /vendors/50/receipt/extract` with the reader faked to his 26 lines (card: 26 lines, reconcile exact,
    Maida/Salt auto, Onions "Yes, use it", Cardamom → Bari Elaichi via the bridge, bag unmatched and no Long 50gm,
    Sonf without Bari Elaichi), `GET /vendors/receipt-drafts` (listed, 26 lines), `POST /vendors/50/receipt/record`
    with a HAND-ADDED Salt line (no raw_name) → 3 purchase items, Salt 800 × 0.06 = Rs 48 stamped with ingredient 33,
    ledger amount Rs 3,653.85, draft submitted, second press → "already"; all rolled back.
  - Full phone jest: 1015/1015 (only the pre-existing `App.test.tsx` native-module file fails, as before).
  - `npx react-native bundle --platform android --dev false` → exit 0, 6.0 MB bundle, the new card strings are in it.
    So the APK's JavaScript compiles end to end; what is NOT proven is touch/scroll feel on a real screen.

## Known limits (deliberate)
- The matching call is one attempt, output cap 2048 ≈ 47 printed lines; a longer slip loses only the
  suggestions (warning `matching reply unusable`). Raise `MATCH_MAX_OUTPUT_TOKENS` if it ever shows.
- A hand-added line has no printed wording, so it teaches no alias (there is nothing to remember).
- "Linked first" is a tie-break on equal word evidence and a sort in the picker — it never overrides a
  better word match, so an unlinked product with the right name still wins.
