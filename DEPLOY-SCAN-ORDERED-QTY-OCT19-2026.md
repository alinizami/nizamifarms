# DEPLOY — Frozen box COUNT on scan + "ordered" quantity on screens (19-Oct-2026)

**1 SQL → 6 web files → `/xclean` → APK.** Nothing here is live until uploaded.

⚠⚠ **Ships TOGETHER with `DEPLOY-SPLIT-INVOICE-OCT19-2026.md`** (built the same day). The two rounds share
`RiderController`, `OrderController`, `orders/index.blade.php`, `routes/api.php` and the phone's
`StoreOpenOrdersScreen` — the files on disk hold BOTH. Run both SQLs first, upload the union of both file
lists, one `/xclean`, one APK. A split moves lines with `UPDATE order_id`, so a kept "ordered" quantity
moves with its line. Proofs for both rounds pass on the combined files (70/70 + 52/52).

## What changes for the team

**Farooq (scanning frozen boxes on the phone).** After scanning a frozen box the card now says
*"Order has 3 boxes — How many boxes are you packing?"*, with **3** already in the box.
- Leave it and tap **✓ Yes, 3 boxes**. The bill stays as it is.
- Packed a different number? Tap **−** / **+** or type it (whole boxes only). The card turns amber:
  *"Different from the order (3) — the bill will be for 2 boxes."* Tap **✓ Save 2 boxes**.
  The line total and order total change right away, the same way a weighed meat scan changes them.
- A number far above the order (e.g. 30 for 3) needs a **second tap** ("Tap again — 30 boxes?").
- Scanning the same box again reopens the card at his last answer, so he can correct it.
- When one barcode matches two box products (the 8-pc and 12-pc samosa share a label), he picks
  the product first, then gets the count card. A box is never saved without the count card.
- Meat scanning is **unchanged**.

**Shabib / managers (screens only, never on a printed / PDF / WhatsApp invoice).** Once a line's
quantity has changed, the first ordered quantity is kept and shown:
- **Web Edit Invoice**, under the quantity: `📦 Box count confirmed · ordered 3 boxes · 19 Oct, 14:05 · by Farooq`
  or `🔖 Qty set by barcode scan · ordered 2 · scanned 2.150 kg · …`
- **Web order view**, under the quantity: small amber `ordered 3`
- **Phone open-orders card** (store mode): `📦 box count confirmed · ordered 3 · 14:05 · Farooq`

It is kept on the **first** change (a weight scan, a box count, the scanner's manual entry, or a
web Edit Invoice save) and **never overwritten**. It's hidden while the quantity equals it (for example
when set back to the ordered number). Lines changed **before** this deploy show nothing; the old number
was never stored.

## Step 1 — SQL (prod, first)
`database/migrations/line_item_original_quantity_oct2026.sql`. One `ALTER TABLE … ADD COLUMN IF
NOT EXISTS original_quantity DECIMAL(10,3) NULL`. Nullable, no backfill. The last statement is a `SHOW COLUMNS` check.

## Step 2 — web files (6)
| File | Why |
|---|---|
| `app/Models/CRM/OrderLineItemModel.php` | column in fillable/casts, `supportsOriginalQuantity()` guard, `originalToKeep()` rule |
| `app/Services/CRM/LineItemQuantityService.php` | keeps the original inside `applyLineQuantity` (every scan / box count / manual save); new `setBoxCount()`; responses carry `original_quantity` |
| `app/Http/Controllers/API/RiderController.php` | box-scan endpoint takes optional `quantity` (int 1–999); both store payloads send `original_quantity`; the light list selects the column only when it exists |
| `app/Http/Controllers/CRM/OrderController.php` | Edit Invoice save (delete + recreate) carries / stamps it; live orders only |
| `resources/views/pages/orders/index.blade.php` | "ordered N" on the edit form + order view |
| `routes/api.php` | **comment only** (box-scan route description) |

Safe if uploaded before the SQL: every reader and writer checks the column first. Proven by dropping
the column locally: box count, both payloads and an Edit Invoice save all return 200.

## Step 3 — `/api/public/xclean`
Required: Blade + routes changed.

## Step 4 — APK (after the web)
`src/components/BarcodeScannerModal.js`, `src/utils/boxCount.js` (new), `src/services/lineItemService.js`,
`src/screens/StoreOpenOrdersScreen.js`.

⚠⚠ **The phone working tree ALSO holds the 18-Oct ingredient-replace round** (`IngredientStockModal`,
`VendorProductForm`, `IngredientReplacePanel`). Any APK built now carries both, so upload that round's
web files first (`DEPLOY-INGREDIENT-REPLACE-DOOR-OCT18-2026.md`) or ship the two together.

If the APK somehow lands before this web round: the old server ignores the count. The phone notices
(no `quantity_applied`) and says *"Box confirmed, but the count was NOT changed (still 3). Ask a manager
to set 2."* It never shows a changed bill that didn't happen. Phones still on the old APK keep
today's confirm-only box card.

## Smoke test after upload
1. Phone: scan a frozen box on an open order → "Order has N boxes", box pre-filled → **Yes** → line unchanged.
2. Scan it again, change to N−1 → amber → Save → order total drops by one box price.
3. Web: open that order → view shows `ordered N` under the quantity; Edit Invoice shows `· ordered N boxes`.
4. Print / PDF the invoice → no "ordered" anywhere.
5. Weigh a meat line → Edit Invoice shows `ordered <old> · scanned <kg>`.

## Proofs (local replica, everything rolled back)
- `php test_original_quantity_oct19.php` → **70/70**. Box count (same / different / keep-first /
  set back / older phone / bad counts 0, 2.5, 1000, abc, −1 / meat line refused / foreign label /
  invoiced / delivered), stock follows the count, meat weight scan, manual Save-all, both phone
  payloads, Edit Invoice save carries + stamps.
- `php test_original_quantity_presql_oct19.php` → **7/7** with the column dropped (re-added after).
- Jest: `boxScanFlow` + new `boxCountOriginalQty` + scanner + split + store-screen suites **107/107** (after the device fixes). Full suite: 1044/1044 tests;
  `App.test.tsx` fails as before (`RNGestureHandlerModule`, unrelated).
- Browser (local, as Shabib): order view + Edit Invoice show the lines; printed invoice has none.
- `PROOF-BOX-SCAN-SEP13-2026.php`: sections C–F pass. Section B's 2 failures are old data assumptions
  (it expects exactly 8 box products; there are 71 since the pack variants were added).

## Device walk (19-Oct, Samsung S21 over USB, local server, as Taimur)
A box label was fed through a temporary dev-build-only field, two frames like the 2-frame camera mode; the
field has been removed. Order SH-23808 was restored afterwards, matching the snapshot exactly, and stock never
moved (lines not deducted).
- Box card: "Order has 5 boxes", pre-filled 5, − / +, "✓ Yes, 5 boxes". − → amber "Save 4 boxes" + warning.
- Keyboard: the card slides up and Save / Cancel stay visible above the number pad. Save works on the
  first tap with the keyboard still open. Back closes only the keyboard.
- Saved 2 of 5 → DB qty 2, original 5, line Rs 640, order 21,815 → 20,855. Re-scan → "now 2", pre-filled 2.
  30 for 5 → "Tap again — 30 boxes?" and nothing saved until the 2nd tap; Cancel saves nothing.
- "Yes" on the samosa (5 of 5) → stamp only, no "ordered", total unchanged. Meat 1.15 for 1.00 →
  the unchanged weight card, saved, "ordered 1" kept. The phone card shows "ordered 5" / "ordered 1".
- Web order data and the printed invoice read 2 boxes / Rs 640 / Rs 21,227; the invoice has no "ordered".

**Two bugs the device found, both fixed and re-checked on the phone:**
1. ⚠⚠ **"2.5" became 25.** Samsung's number pad has `.-` and `,` keys. The box dropped the "." as
   it was typed, so the 5 landed after the 2. Now the box keeps what was typed, shows red
   "Whole boxes only", and Save stays off until it's fixed. A key-by-key test was added.
2. **The "📦 Box" badge lost its word.** On the open-orders card the SKU beside it (`flex: 1`)
   squeezed the pill until "Box" wrapped out of sight; proven by undoing the fix (pill 77 px tall instead
   of 41). All three badges (Barcode / Box / Manual) now keep their width. This badge predates the round.

## Not done / limits
- The product picker (two box products on one barcode) wasn't walked on the device: SH-23808 has no
  such pair. Jest covers it.
- No backfill: lines changed before the deploy show no "ordered".
- A Shopify/Woo **sync** that rewrites an order's lines (`OrderModel::storeOrderFromApi`) drops the
  kept number along with the scan badge, as it always has. The source order changed, so its new
  quantity *is* what was ordered.
