# Bundled products — PLAN (Oct-15-2026) → BUILT same day, see `DEPLOY-PRODUCT-BUNDLES-OCT15-2026.md`

> **Owner rulings (Oct-15), which supersede the open questions below:**
> - The bundle's own price IS the per-packet price (Taimur enters 400). Nothing is divided: qty × bundle_qty, at that price. Q2 is moot.
> - Single-product bundles only (Q4).
> - No line tag column was built (Q3 dropped for round 1). The expanded line is simply the inside product.
> - **As finally built (same day):** bundles live in a row table, `t_crm_prod_product_bundle_item`
>   (product inside, packets, packet price). That table also carries multi-product bundles. The draft
>   product columns were dropped before shipping. The approval-queue hint is built.
>   See `MULTI-PRODUCT-BUNDLES-PLAN-OCT15-2026.md` and `DEPLOY-PRODUCT-BUNDLES-OCT15-2026.md`.

## What the owner wants
A Shopify SKU (e.g. `EK123`) is a **bundle**: "3 packets of Chicken Samosa for Rs 1,200".
Shopify sends it as **qty 1 @ 1,200**. In our app (live order, invoice, packing, stock) it must read
**Chicken Samosa — qty 3 @ 400 = 1,200**, so the team never confuses "1 bundle" with "1 packet".

Whoever sets up products marks the bundle product as **Bundled**, picks **what is inside**
(Chicken Samosa) and **how many** (3). Everything after that is automatic.

## How it works today (facts, read from code)
- SKU → product link is `t_crm_prod_product_variant.sku` (price is on the variant too). No mapping table.
- Shopify webhook → `OrderModel::mapShopifyOrder()` (:1023) → staging `t_crm_shopify_order_line_item`
  with Shopify's qty/price as-is.
- **Convert** = `OrderController::convertOrder()` (:3441) → `validateAndRecalculateOrder()` (:3861).
  Per line (:3902-3978): find variant by SKU, **replace the Shopify price with our variant price**,
  `line_total = qty × price`, set our variant_id/product_id/name. Order-level Shopify discount, shipping,
  tip are kept. This is the ONE place Shopify lines become live lines.
- Ledger/invoice money uses the order's `total_price`, not the lines → a 1×1200 vs 3×400 split does
  not change any money posting as long as the line total stays 1,200.
- Many readers find the product **by the line's SKU** (Open Quantities :4994, sell_unit/box-scan lookup in
  RiderController, stock fallback, frozen demand). So the expanded line must carry the **samosa's own SKU**,
  not `EK123` — otherwise those screens treat it as "1 of EK123".
- Order edit (`update()` :2367-2404) keeps the unit price from the form → 400 survives an edit.
  Mobile qty change recomputes `round(unit_price × qty, 2)` → 400 × 2 = 800, still consistent.
- Fulfillment sync back to Shopify works at whole-order level (no line ids/qty) → unaffected.
- There is no bundle/pack concept on sale products today (only Qurbani "bundle" and vendor `pack_size`,
  both unrelated).

## The design
### 1. Product setup (one SQL, two columns on the variant)
`t_crm_prod_product_variant` + `bundle_of_variant_id` (nullable INT) + `bundle_qty` (nullable INT).
- Products add/edit (web `create`/`edit.blade.php`, mobile `ProductsScreen.js`): a **"Bundled"** switch →
  "Contains: [product picker] × [qty]". The bundle product keeps its own SKU (`EK123`) and price (1,200).
- Validation: qty ≥ 2; inside-product must exist, must not itself be a bundle, must not be the same product;
  price must divide evenly by qty (see Q2).
- Save path: `ProductController::store/update` → `formatManualProductData` → `storeProductFromApi` variant
  update array (ProductModel :829-843). **Keep-old-value-if-key-missing guard** (same as `sell_unit`) so an
  old APK saving the product doesn't wipe the bundle setting.

### 2. Shopify convert (the main change) — `validateAndRecalculateOrder()`
If the found variant is a bundle:
- `quantity = shopify_qty × bundle_qty` (1 × 3 = 3; 2 bundles → 6)
- `unit_price = bundle variant price ÷ bundle_qty` (1,200 ÷ 3 = 400)
- `line_total = shopify_qty × bundle price` (exact 1,200 — never recomputed from the division)
- `sku / variant_id / product_id / name` = **the samosa's**, not the bundle's
- remember the origin on the line: `bundle_sku = 'EK123'` (see SQL in §6) for traceability + a small tag.
- Stock is then deducted from **Chicken Samosa** by 3 automatically (existing deduction uses variant_id).
- A bundle line and a loose samosa line in the same order stay **two separate lines** (different prices).

### 3. Before convert (approval queue)
- Web approval list + mobile `StoreShopifyOrdersScreen`: under the Shopify line show
  "= 3 × Chicken Samosa @ 400" so the approver sees what it will become.
- `OpenOrderDemandService::stagingLinesQuery` (:64-89): count the staging bundle as 3 samosas (frozen demand).

### 4. Staff adding the bundle SKU by hand (new order / edit order)
- `ProductController::search` returns bundle info with the result.
- Web orders page + customers page `selectProduct()`: picking `EK123` adds **Chicken Samosa, qty 3, @ 400**
  straight away (staff never see "1 @ 1200").
- Server safety net in `OrderController::store()` / `update()`: if a line arrives with a bundle SKU
  (e.g. from an old APK), expand it the same way before saving.
- Mobile `CustomersScreen` create-order: same expansion on pick (next APK).

### 5. Not changed (checked)
Ledger/invoice posting, Shopify fulfillment sync, order-level discount/shipping/tip, packets/dispatch counts.
Customer-facing invoice will read "Chicken Samosa 3 × 400 = 1,200" (Shopify showed "1 × 1,200") — total same.

### 6. SQL (run FIRST at deploy)
```sql
ALTER TABLE t_crm_prod_product_variant
  ADD COLUMN bundle_of_variant_id INT NULL AFTER sku,
  ADD COLUMN bundle_qty INT NULL AFTER bundle_of_variant_id;
ALTER TABLE t_crm_prod_order_line_item
  ADD COLUMN bundle_sku VARCHAR(100) NULL;   -- only if Q3 = yes
```
(⚠ ALTER TABLE is not rolled back inside a transaction — run plainly.)

### 7. Deploy shape (estimate)
SQL FIRST → ~8 web files (OrderController, ProductController, ProductModel, ProductVariantModel,
OrderLineItemModel, OpenOrderDemandService, products create/edit blades, orders index blade, customers
index blade) → `/xclean` → APK (ProductsScreen, CustomersScreen, StoreShopifyOrdersScreen).
Web must go up before the APK.

### 8. Proof plan
Replica: make a bundle `EK123` (3 × Chicken Samosa, 1,200); push a staged Shopify order with qty 1 and qty 2;
convert → expect 3 @ 400 = 1,200 / 6 @ 400 = 2,400, samosa stock −3/−6, Open Quantities shows samosa 3,
order total + ledger unchanged vs today. Edit the order (no change) → still 3 @ 400. Mobile qty 3→2 → 800.
Manual add on web/mobile → 3 @ 400. Old-APK product save → bundle setting kept.

## Open questions for the owner
1. **Per-packet price = bundle price ÷ qty** (1,200 ÷ 3 = 400)? (Not the samosa's normal single price.)
2. **Uneven prices** (1,000 ÷ 3 = 333.33): block saving such a bundle (recommended), or allow paisa?
3. **Keep a "Bundle EK123" tag** on the line (staff screens only; invoice stays plain)? Needs the one
   extra column. Recommended yes — also lets reports count bundles sold.
4. **One product per bundle** for now (3 samosas), or also mixed bundles (2 samosa + 1 roll)?
   Mixed needs a split-the-price rule — recommend single-product only for round 1.
