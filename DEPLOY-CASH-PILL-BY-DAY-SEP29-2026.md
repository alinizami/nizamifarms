# DEPLOY — 💵 "Cash moved by others" grouped by day (Sep-29-2026)

**Web only · NO SQL · NO APK · /xclean after upload.**

Owner (Shabib): "segregate it by date so it's not one long notification."
Rulings: group by the day the entry was **typed**; show the **last 7 days**, with a
**"Show earlier days"** button.

## Upload (3 files)

| File | Change |
|------|--------|
| `app/Services/FIN/LedgerWatchService.php` | `recent($userId, ?$before, ?$since)` returns one 7-day block of whole days + `day` / `day_label` / `time` per row + `next_before` (anchored on the newest earlier row, so empty weeks are skipped) + `seen_since` (C11). `LIST_LIMIT` removed. |
| `app/Http/Controllers/FIN/Hub/HubController.php` | `watchList` takes `?before=Y-m-d` instead of `?limit=` (anything else → first page), and `?since=` (C11; digits only, else ignored). |
| `resources/views/partials/cash-pill.blade.php` | Day headings (pinned while scrolling) with count + day total; card shows time only; "Show earlier days" button; "That's everything" end line; passes `since` on earlier pages (C11); drops stale answers after a close/reopen (C12). |

## Pre-deploy fixes (Sep-29, plan C11 + C12)
- **C11 — unread entries older than the 7 days shown.** The badge counts every unread row however
  old, but the first page showed 7 days and opening marks everything read — so a row typed 10 days
  ago was counted, never shown, then silently cleared. Now the **first page reaches back to the day
  of the oldest unread row** (same unread rule as the badge; at most 31 days back, same scan
  ceiling). It also returns `seen_since` — the watermark before the open — and the drawer sends it
  as `?since=` on "Show earlier days", so those pages keep their unread dots after the open moved
  the watermark. `since` is display-only and ignored when above the real watermark. Response fields
  are additive; the old drawer ignores them.
  - Known limit: an unread row more than 31 days old is still counted by the badge but not pulled
    onto the first page (it is on a "Show earlier days" page, with its dot).
- **C12 — a slow "Show earlier days" from a closed drawer** could overwrite the new page's paging
  (`NEXT`) and append under it. Every open now takes a generation number; an answer (earlier page
  or first page) belonging to an older open is dropped.

Not uploaded: `PROOF-TILL-KEEPER-SEP22-2026.php` (local proof; 2 calls updated to the new signature).

Then run `/api/public/xclean` (the Blade view changed). No route changes.

## Unchanged
Badge count, the "seen" marker, which rows count (keeper tills, not your hand, no order
deliveries), the Hub "Not me" filter, and the whole mobile app (it has no cash pill; the
keeper's check-out till count does not use this list).

## Proof
- `PROOF-TILL-KEEPER-SEP22-2026.php` — 79/79 → **92/92** after C11 (a 10-day-old unread row is on
  the first page with its dot, the block reaches back to its day, `seen_since` = the pre-open
  watermark, earlier pages keep the dot with `since=` and lose it without, a `since` above the
  watermark is ignored, the endpoint passes/refuses `since`, the 31-day cap).
- `scratchpad/cashpill_harness.cjs` (the rendered pill's real script, fake DOM + held fetches) —
  9/9: `since=` sent; a stale "Show earlier days" after close/reopen is not appended and does not
  set `NEXT`; a slower first page from an earlier open is dropped and posts no second "seen".
- Paging against the replica, Shabib / NF Cash: 43 blocks cover **all 1,042** rows exactly once
  (brute-force compared), no day split across pages, days strictly descending, ~90 ms a block.
- Browser, as Shabib: first page Sep 23–29 → 3 day headings; "Show earlier days" skipped the
  empty Sep 22 and started at Sep 21; headings pin and hand over cleanly while scrolling.
