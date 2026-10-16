# Deploy — 📦 Bundled products, single AND multi-product (15-Oct-2026)

**What it does.** A product can be ticked **Bundled** in Products. It then lists the products inside it.
Each row has the number of packets and the price of **one packet** (owner ruling: Taimur enters packet
prices; nothing is divided). Examples:

| Shopify sends | The order shows |
|---|---|
| `EK123 × 1 @ 1,200` | Chicken Samosa 3 × 400 = 1,200 |
| `EK200 × 1 @ 1,500` | Chicken Samosa 2 × 500 = 1,000 **and** Chicken Roll 1 × 500 = 500 |
| `EK200 × 2 @ 1,500` | Samosa 4 × 500 + Roll 2 × 500 = 3,000 |

- This happens when the order is **accepted**, on web and phone alike (same server code).
- It also happens when someone **picks the bundle by hand** on a new order: Orders page, Customers page,
  phone. The order immediately shows one line per product inside, each with a "📦 bundle EK200 = N
  packets" note.
- **Before accepting**, the Shopify approval queue shows what each bundle line will become:
  "📦 = 4 × Chicken Samosa @ Rs 500 + 2 × Chicken Roll @ Rs 500". This appears in the web Approvals
  drawer, the web order detail, and the phone's Shopify screen.
- Every order line **is** the product inside: its own SKU, stock, packing (Open Quantities), box scan,
  invoice and reports. Ledger and invoice money use the order total, which equals the bundle total.
- The bundle's own price is **set automatically to the bundle total** (the Shopify price) and can't be
  typed while it is a bundle.

**Safety rules built in**
- **A bundle never stops a conversion.** If a product inside is deleted or broken, that line converts as
  ONE line at the bundle price (the money is still right), and staff get a warning: "Bundle EK200: a
  product inside it no longer exists — kept as ONE line at the bundle price; fix the bundle in Products".
- If the packet prices don't add up to what Shopify charged, the order converts at our prices with a
  warning: "Bundle EK200: Shopify charged Rs 1,500, our order says Rs 1,600 — check the packet prices".
- You can't delete a product that is inside a bundle. A bundle can't contain another bundle or itself,
  and it has exactly one SKU (the Shopify SKU).
- An order **edit** never re-splits old lines.

Engine: `app/Services/CRM/ProductBundle.php`. Plans: `BUNDLED-PRODUCTS-PLAN-OCT15-2026.md`,
`MULTI-PRODUCT-BUNDLES-PLAN-OCT15-2026.md` (now built — see "Decisions taken" below).

## ⚠ Files shared with another uncommitted round
`OrderController.php` and `customers/index.blade.php` also carry the **Oct-14 online-invoice edit
guards** (`DEPLOY-ONLINE-INVOICE-EDIT-GUARDS-OCT14-2026.md`). Deploy both rounds together. File 13 below
is that round's third file.

## Steps
1. **SQL first**: `database/migrations/product_bundles_oct2026.sql`. It creates ONE new table,
   `t_crm_prod_product_bundle_item`; nothing existing changes. With 0 rows every product behaves as
   today. The code checks for the table, so files landing before the SQL are harmless (the Bundled box
   simply doesn't show).
   - The earlier draft that added 2 columns to `t_crm_prod_product` is **gone**. It never reached prod,
     so nothing needs undoing there.
2. **Upload 13 web files** (WinSCP):

| # | File | Change |
|---|------|--------|
| 1 | `app/Services/CRM/ProductBundle.php` | **NEW** — the one engine |
| 2 | `app/Models/CRM/ProductChangeHistory.php` | `TYPE_BUNDLE_CHANGE` (bundle edits show in product history) |
| 3 | `app/Models/CRM/ShopifyOrderLineItemModel.php` | `bundle_hint` on every staging line (the approval-queue hint) |
| 4 | `app/Http/Controllers/CRM/ProductController.php` | save bundle rows with the product (one transaction); price pinned to the total; phone saves keep the bundle; search returns `bundle.items`; delete guard; bulk price adjust (and its preview) skips bundles |
| 5 | `app/Http/Controllers/CRM/OrderController.php` | Shopify accept splits bundles into lines; order create splits a bundle line sent by an older app — ⚠ also Oct-14 round |
| 6 | `app/Services/Khaas/OpenOrderDemandService.php` | frozen demand counts a waiting bundle as packets of each product inside |
| 7 | `resources/views/pages/products/partials/bundle-section.blade.php` | **NEW** — the "📦 Bundled product" table |
| 8 | `resources/views/pages/products/create.blade.php` | includes it |
| 9 | `resources/views/pages/products/edit.blade.php` | includes it |
| 10 | `resources/views/pages/orders/index.blade.php` | picker adds one line per product inside; hint in Approvals drawer + order detail |
| 11 | `resources/views/pages/customers/index.blade.php` | same picker; also fixes ordinary picks saving without SKU/product — ⚠ also Oct-14 round |
| 12 | *(none)* | `ProductModel.php` is **not** changed any more. Don't upload it for this round. |
| 13 | `app/Http/Controllers/CRM/CustomerController.php` | **Oct-14 round's file** (see the warning above) |

3. `/api/public/xclean` — **required** (Blade views changed).
4. **APK** after the web upload. Changed: `src/utils/productPick.js` (NEW), `src/screens/CustomersScreen.js`
   (bundle pick adds one line per product inside), `src/screens/StoreShopifyOrdersScreen.js` (hint line).
   - Until the new APK is installed, an old phone that picks a bundle shows "1 × EK200 @ 1,500". The
     server saves it as the split lines, so the invoice is right; only the old phone's preview is.

## Setting up a bundle (Taimur)
Products → Add Product → tick **📦 Bundled product**:
- **SKU = exactly the Shopify SKU** (EK200). One variant only.
- Add a row per product inside: search for it, enter packets and **the price of ONE packet**.
- "One bundle = Rs …" must equal the Shopify price. The product's price box fills itself in.
- Give it the same category as the main product inside. The WhatsApp delivery schedule reads it while
  the order is still waiting for approval.

## Check after upload
1. Products → edit any ordinary product → Save. Nothing changes; no "Bundle" row appears in its history.
2. Set up a test bundle. In the Shopify approval queue, an order containing it shows the 📦 hint.
3. Orders → new order → pick the bundle. You should see one line per product inside with the packet
   prices. Cancel without saving.
4. The first real bundle order: after Accept it shows the split lines and the total matches Shopify. Any
   "Bundle …" warning in the accept toast means the bundle needs fixing in Products.

## Proof
- `php test_product_bundles_oct15.php` (local, everything rolled back): **68/68**.
  - set-up rules (12 refusals)
  - create: rows saved, price pinned, history logged once
  - phone save keeps rows and can't un-pin the price
  - untick clears; inside-product can't become a bundle
  - search shape
  - order-create split: variant / "variant_" / SKU-only / free / note on first line only / idempotent / money
  - Shopify accept: 1 bundle → 2 lines, 2 bundles → 4 + 2, ordinary line untouched, totals, no warning;
    wrong packet price warns; **broken bundle still converts at the right money with a warning**
  - approval hint text
  - frozen demand per product inside
  - delete guards
  - **last 40 real converted Shopify orders recalc exactly as before**
  - ordinary SKUs map to frozen demand exactly as before
- `php test_product_bundles_e2e_oct15.php` (pre-deploy check, rolled back): **16/16**.
  - a Shopify order with 2 bundles + 1 ordinary line goes through the same steps as Accept, up to a
    REAL save in `t_crm_prod_order`
  - the saved lines: A 4 × 500, B 2 × 500, ordinary line untouched, each pointing at the real product
  - subtotal = Σ lines; total = subtotal + shipping (the amount the ledger posts)
  - all 4 invoice views render both products and the right totals: invoice, invoice-print,
    **invoice-image (the picture sent on WhatsApp)** and invoice-pdf
  - Skipped: Accept's after-save extras (WhatsApp promise, location request), which don't touch lines or
    money and would message a real customer in the dev copy.
- Pre-deploy surface check (code read): every way into an order or a product goes through the files
  above.
  - **Shopify accept:** web route, phone `/rider/orders/{id}/convert` and the store-screen convert all
    call one `convertOrder`.
  - **New order:** web Orders page, Customers page and phone customer view all call one `store()`.
  - **Order edit** keeps saved prices; phone qty edits recompute from the saved price.
  - **Product create/edit:** web and phone use one `store`/`update`; bulk price adjust skips bundles;
    nothing else writes product prices.
  - **WhatsApp invoice:** the invoice image + a template; the chat panel lists line names; ledger and
    approvals use the order total.
- Browser, local, as Taimur:
  - created a 2-product DEVCHECK bundle through the real form (2 rows saved, price 1,500; edit page
    pre-filled)
  - Orders-page and Customers-page pickers each gave 2 × Samosa @ 500 + 1 × Beef Samosa @ 500
  - the Approvals drawer showed the hint
  - No order was saved or approved. The DEVCHECK data was deleted afterwards.
- Phone: jest `productPickBundle` 5/5; full suite 1,002 passed. `App.test.tsx` can't load the native
  gesture-handler module, which predates this round.
- Not walked on a real phone.

## Decisions taken (from the multi-product plan)
1. Packet prices live **on the bundle rows** (owner: "take the prices entered in the bundle itself").
2. A returned packet is handled per line, like any other product (no change to returns).
3. No "bundle" tag on order lines. Reports count packets of the real products, not bundles sold.
4. Shopify sends the bundle as one SKU today. If a Shopify bundle app ever starts sending the components
   itself, revisit.

## Local dev DB note
Your own test bundle `test-200` (product 1497) was moved into the new table as 3 × KS-CCS @ 400, and
its price became 1,200. The two draft columns were dropped locally so dev matches what prod will get.
