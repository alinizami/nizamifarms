# Deploy — 🧾🤖 Bill scanner: a derailed reply is a failed read (17-Oct-2026)

> ⚠ **SUPERSEDED the same day by `DEPLOY-RECEIPT-TWO-CALL-MATCH-OCT17-2026.md`** — same two files, one
> checklist for both rounds. The guard below is still in the code; "retry WITHOUT the lists" is no longer
> how it works (the lists never ride in the photo call now). Upload from the newer note.

**Why.** Qasim's 26-line Imtiaz slip came back as ONE line (Fresh Onions). The model lost its place at the
first line's price, wrote its own reasoning into the price string, closed the list and finished normally. The
reply was valid JSON, so the scanner accepted it: the price still read as 195, there was no printed total to
check against, and the card showed one line with no warning. Nothing was logged, because only failures were.
Reproduced on dev with the same photo: WITH the vendor's product/ingredient lists in the schema → 1 line
(4 times out of 4, counting prod); WITHOUT them → all 26 lines, total exact.

**What changes.**
1. A reply that parses but is not a reading of the slip is now a **failed attempt** and the one retry runs
   **without the lists** (the same path the output-cap loop already uses). "Derailed" means: no lines; or a run
   of 3+ letters inside a number (qty / price / total / discount); or fewer than 3 lines with no printed total
   and no real confidence. A genuine 2-line slip with a readable total is untouched.
2. Every successful read logs one line: `ReceiptExtraction: read ok {attempt, lines, tokens_in, tokens_out,
   hinted, seconds}` — so the next "only a few lines" has a trace.
3. If both attempts derail, the phone gets an honest sentence (reason `derailed`): "The bill reader lost its
   place on this bill and did not read it through. Try once more, or type the bill in by hand."

**Web only. NO SQL, NO APK, NO routes** (`/xclean` not needed — no config/route/view change).
The phone already shows whatever `message` the server sends.

## Files (2, WinSCP)

| # | File | Change |
|---|------|--------|
| 1 | `app/Services/Khaas/ReceiptExtractionService.php` | `FAIL_DERAILED`, `derailed()` detector, retry-without-lists on a derailed reply, `read ok` info log |
| 2 | `app/Http/Controllers/Khaas/ReceiptController.php` | one sentence for reason `derailed` in `failureMessage()` |

## Proof
`php test_receipt_derailed_oct17.php` — HTTP faked, no live model call. Covers: derailed first reply →
retried without the enum lists → clean read kept, lastFailure null, log lines; derailed twice → null + reason
`derailed`; clean first reply untouched + one `read ok`; the detector's edge cases ("2.00 kg" fine, "Rs 1,234.00"
fine, 2-line slip with a total fine, 26 low-confidence lines fine).

## After upload
- Ask Qasim to scan the Oct-17 Imtiaz slip again. Expect in `storage/logs/laravel.log`:
  `reply derailed` → `retrying without the product list {"why":"derailed"}` → `read ok {"attempt":2,"lines":26…}`
  (or `read ok {"attempt":1…}` if the model behaves). The card should show 26 lines and a total of Rs 15,665.28.
- The product suggestions ("tap to confirm") are absent on a retried read — the lists are what trip the model.
  Word-match "Did you mean" suggestions still work.

## Not done (deliberate)
- The product/ingredient matching still rides in the same call as a schema enum. If derailed retries become the
  norm in the log, move the matching to a second text-only call (text calls never stall) — separate round.
