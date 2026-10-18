# Deploy — 🧾🤖 Bill scanner: photo read + product match as TWO calls (17-Oct-2026)

> ⚠ **SUPERSEDED the same evening by `DEPLOY-RECEIPT-SCANNER-OCT17-2026.md`** (adds the matcher fix,
> linked-first suggestions, add-a-line, picker search and the type-it-in door; 3 web + 3 APK files).
> Upload from that note; this one stays for the why of the two-call read and the live walk.

**ONE checklist for both Oct-17 scanner rounds.** It supersedes `DEPLOY-RECEIPT-DERAILED-OCT17-2026.md`
(same two files; that round's guard is still inside, its "retry without the lists" is replaced by this).

**Why.** Qasim's 26-line Imtiaz slip came back as ONE line. The vendor's product list and the recipe
ingredients rode in the photo call as an enum on every line, and on this slip that made the model lose its
place at the first price (4 times out of 4, counting prod). The same photo without the lists read 26/26 with
the exact total. Text-only Gemini calls have never stalled (8/8 in the Sep tests).

**What changes.**
1. The **photo call carries nothing but the slip**: plain prompt, plain schema (every amount a STRING, output
   cap 6144, two attempts, derailed/looped replies retried at a nudged temperature). No product enum, ever.
2. The **"which of OUR products is this" matching is a second, text-only call**: the printed names, numbered,
   plus the same lists and rules as before; the model answers per line with one of our codes or NONE (enum,
   so it cannot invent a product). 15 s, one attempt, output capped at 2048. **Any failure of this call costs
   the suggestions, never the read** — the bill still comes back, a person matches by hand as before.
3. A reply that parses but is not a reading of the slip (no lines / prose inside a number / fewer than 3
   lines with no total and no confidence) is a **failed attempt** → the one retry; both failing → the phone
   gets "The bill reader lost its place on this bill…" (reason `derailed`).
4. Logs on success: `ReceiptExtraction: read ok {attempt, lines, tokens_in, tokens_out, seconds}` and
   `ReceiptExtraction: match ok {lines, suggested, tokens_in, tokens_out, seconds}`. Matching failures log a
   warning that names the cause (`non-200` / `unusable` / `failed`), key redacted.
5. `hinted_products` / `hinted_ingredients` on the read now mean "asked AND answered". The card already uses
   them only to drop a word-overlap "Did you mean" that the reader rejected — unchanged behaviour.

**Web only. NO SQL, NO APK, NO routes, no `/xclean`.** The phone shows whatever card/message the server sends.
Cost: one extra text call per Frozen-vendor scan (~1.5–3k input tokens, <100 out).

## Files (2, WinSCP)

| # | File | Change |
|---|------|--------|
| 1 | `app/Services/Khaas/ReceiptExtractionService.php` | photo call without lists; `matchLines()` + `matchSchema()`; `derailed()` + `FAIL_DERAILED`; `read ok` / `match ok` logs; C15's drop-the-lists retry removed |
| 2 | `app/Http/Controllers/Khaas/ReceiptController.php` | one sentence for reason `derailed` in `failureMessage()` |

## Proof (HTTP faked — no live model call, no credits)
- `php test_receipt_two_call_match_oct17.php` — 32/32: photo call has no enum and no list text; match call is
  text-only with exactly our codes + NONE; answers land on the right lines; match call 500 / MAX_TOKENS /
  timeout → read kept, flags false, warning logged (key redacted); no lists → exactly one call; codes or line
  numbers we never sent are ignored; a looped photo call retries and still matches.
- `php test_receipt_derailed_oct17.php` — 27/27: derailed → retried → read + matched; derailed twice → null +
  `derailed`; clean read untouched; detector edge cases.
- ✅ Walked live ONCE on dev with the owner's Oct-17 Imtiaz photo and vendor 50's real lists (40 products +
  38 ingredients): `read ok {attempt 1, lines 26, 1685 in / 1934 out, 9.2 s}` then `match ok {lines 26,
  suggested 7, 1634 in / 1121 out, 5.4 s}`; 26/26 lines, lines sum = printed total Rs 15,665.28, confidence high,
  14.6 s end to end (phone timeout is 90 s). The same photo read ONE line under the old single call.
- ⚠ Known limit: the match call answered ~43 output tokens per line, so its 2048 cap covers ~47 printed lines;
  a longer slip loses the suggestions (never the read) with a `matching reply unusable` warning. Raise
  `MATCH_MAX_OUTPUT_TOKENS` to 4096 if that warning ever shows up.

## After upload
Ask Qasim to scan the Oct-17 Imtiaz slip again. Expect in `storage/logs/laravel.log`:
`read ok {"attempt":1,"lines":26…}` then `match ok {"lines":26,"suggested":N…}`, and a card with 26 lines
totalling Rs 15,665.28 with "tap to confirm" suggestions on the lines the reader could place.
If `match ok` is missing, the warning before it says why; the bill is still complete.

## Not done (deliberate)
- The matching call is one attempt by design. If `matching call failed` shows up often in the log, give it
  one retry — separate, tiny change.
