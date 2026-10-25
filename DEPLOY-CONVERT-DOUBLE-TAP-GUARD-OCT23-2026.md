# Deploy — 👯 Stop a double-tapped Approve from making TWO orders with one SH- number (23-Oct-2026)

**Why.** SH-23869 exists twice on prod (ids 18626 + 18627). Shopify sent the order ONCE (webhook
09:25:53 → staging row 9660). At 11:12:23 the Approve (convert) request for that row ran TWICE in the
same second, both by Farooq from the phone, and each built a live order. The server read `converted`,
built the order, messaged, audited, and only at the END set `converted = 1` — so two requests in that
window both passed. `t_crm_prod_order.order_number` has no unique index either. The web modal already
greys its buttons; the phone screens did not. Recurring: SH-23737 (Oct-16), SH-20101, SH-19827,
SH-19753, SH-17204 are earlier pairs on the replica.

**What changes.** Nothing about HOW orders are approved. Same buttons, same promise messages, same
statuses, same khaas / qurbani handling. Only this:
- Server: the staging row is CLAIMED atomically (`UPDATE … SET converted=1 WHERE id=? AND converted
  IS NULL OR 0`) right after the existing "already converted" check. Exactly one request wins; the
  other gets the same `400 "Order has already been converted"` the second tap would have got a second
  later (logged as a WARNING "duplicate approve blocked"). If the convert is then refused (SKU
  validation) or throws BEFORE a live order exists, the claim is released → the row is back in the
  queue exactly as today. If it throws AFTER the live order exists, the claim is kept (a retry would
  be the duplicate).
- Phone: one convert in flight at a time (`convertingRef`), promise buttons greyed while it runs.
  Covers the Shopify approvals screen (regular + khaas + "Accept without messaging") and the older
  Convert button on Store Open Orders.

**1 web file → that's the fix. APK optional.** NO SQL, no /xclean (no routes / views / config).
The server guard alone stops duplicates on every client, including today's APK — a double-tap on an
old APK just shows "Success" then "Error: Order has already been converted". The APK half only
removes that second alert. Ship it with the next build.

## File (web, 1, WinSCP)

| # | File | What |
|---|------|------|
| W1 | `app/Http/Controllers/CRM/OrderController.php` | `convertOrder()`: atomic claim + `$releaseClaim` on the two failure paths (validation refusal; exception before a live order exists). Serves BOTH `/orders/{id}/convert` (web) and `/rider/orders/{id}/convert` (phone) — same method. |

## Mobile (2 files → next APK, option 6 / Rider + Messages)

| # | File | What |
|---|------|------|
| M1 | `src/screens/StoreShopifyOrdersScreen.js` | `convertingRef` + `converting` state; `handleConvertShopifyOrder` and `doConvertWithPromise` ignore a second tap; promise buttons + "Accept without messaging" `disabled` while in flight; `finally` resets. |
| M2 | `src/screens/StoreOpenOrdersScreen.js` | `convertShopifyInFlightRef` around the Convert alert's POST. |

## Proof (dev replica, Oct-23)

- Claim statement on staging row 9614 (converted reset to 0 for the test, restored to 1 after):
  first claim → 1 row, second → 0, release → 1, third after release → 1.
- Race: three separate PHP processes firing the claim at the same moment on that row → `1, 0, 0`.
- `php -l` clean. Mobile: eslint on both screens (no new errors), jest suite — see WORKLOG.

## After upload

1. Nothing to run (no SQL, no cache clear).
2. Watch `storage/logs/laravel.log` for `duplicate approve blocked` — each line is a double-tap that
   WOULD have made a duplicate.
3. ⚠ Data clean-up for SH-23869 is a SEPARATE decision: 18626 is delivered + in the ledger (25393,
   Rs 7,199.70); 18627 went out again on Oct-23 with Danish Ali. Check with the store whether the
   customer actually received two deliveries before cancelling/deleting 18627. Same question for the
   five older pairs.

## Rollback

Re-upload the previous `OrderController.php`. The claim leaves no schema or data trace (a blocked
double-tap changes nothing; a released claim restores `converted = 0`).
