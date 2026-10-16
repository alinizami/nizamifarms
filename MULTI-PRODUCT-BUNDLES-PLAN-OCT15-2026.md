# Multi-product bundles — PLAN (Oct-15-2026) → **BUILT the same day**, in the same deploy as round 1

> Owner asked to ship it together. Because round 1 had not reached prod, round 1 was **rebuilt on this
> table** instead of migrating: the draft `t_crm_prod_product.bundle_*` columns were dropped, and the bundle
> row table below is the only storage. Single-product bundles are just bundles with one row.
> Built as: `DEPLOY-PRODUCT-BUNDLES-OCT15-2026.md`.
>
> Changes from this plan: a broken bundle NEVER blocks a conversion (it stays one line at the bundle
> price, with a warning). The bundle's own price is pinned to its total, which is what makes that fallback
> bill the right money. Decisions 1–4 were taken as recommended; see the deploy doc.

Today (round 1, `DEPLOY-PRODUCT-BUNDLES-OCT15-2026.md`) a bundle holds ONE product:
`t_crm_prod_product.bundle_of_variant_id` + `bundle_qty`, and the bundle's own price is the price of
one packet. This plan says how to grow that into "2 Chicken Samosa + 1 Chicken Roll for Rs 1,500"
**without redoing round 1** — round 1's design was chosen so this is an extension, not a rewrite.

## What changes for the business
- A bundle lists several products inside, each with its packets.
- The **price of each packet is entered on the bundle** (per product inside), not on the product inside
  itself — the same rule as today (Taimur enters packet prices; nothing is divided). The bundle total
  must equal the Shopify price, and the screen shows that total while he types.
- A Shopify line `1 × EK200 @ 1,500` becomes **two lines**: Chicken Samosa 2 × 500 = 1,000 and
  Chicken Roll 1 × 500 = 500. Two bundles → 4 + 2. Order total unchanged (1,500 / 3,000).
- Everything downstream (invoice, Open Quantities, box scan, stock, reports) already works per line, so it
  sees two ordinary product lines. Nothing there needs to change — that is the whole point of
  expanding at the door.

## Data: one new table, the old columns become a fallback
```sql
CREATE TABLE t_crm_prod_product_bundle_item (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bundle_product_id BIGINT UNSIGNED NOT NULL,   -- the bundle (t_crm_prod_product.id)
  variant_id  BIGINT UNSIGNED NOT NULL,         -- the product inside (t_crm_prod_product_variant.id)
  qty         SMALLINT UNSIGNED NOT NULL,       -- packets of it in ONE bundle (1+)
  unit_price  DECIMAL(10,2) NOT NULL,           -- price of ONE packet of it (owner's number)
  position    TINYINT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_bundle_variant (bundle_product_id, variant_id),
  KEY idx_bundle_item_variant (variant_id)
);
```
- Round-1 bundles are **migrated into this table on deploy** (one row each: qty = `bundle_qty`,
  unit_price = the bundle variant's price). After that `bundle_of_variant_id` / `bundle_qty` are left in
  place but unused (dropped in a later clean-up once prod is proven).
- A bundle with one row behaves exactly like today, so nothing the team learned changes.

## Code: the same ONE engine, one shape change
`App\Services\CRM\ProductBundle::forVariant()` today returns `{qty, inner, name, bundle_sku}`. It would
return `{bundle_sku, items: [{qty, inner, name, unit_price}, …]}` and every caller loops:

| Caller (all exist today) | Change |
|---|---|
| `OrderController::validateAndRecalculateOrder` (Shopify accept) | one staging line → N live lines (push N `$lineItemData`); subtotal sums all; the price-mismatch warning compares Shopify's line total against the SUM of the items |
| `ProductBundle::expandFormItems` (order create safety net) | one form line → N lines (array splice) |
| `ProductController::search` → `bundle` key | `items: [...]` instead of one inner; web + phone pickers add N rows instead of filling one (the Orders-page `addLineItem()` and the phone's `setOrderForm` already know how to append rows) |
| `OpenOrderDemandService::stagingSkuMapSql` | the SKU map joins the new table: one staging SKU → N (product, pack) rows, so the SUM counts every product inside |
| `ShopifyOrderLineItemModel::bundle_hint` | "= 2 × Chicken Samosa @ Rs 500 + 1 × Chicken Roll @ Rs 500" |
| Products form (`bundle-section.blade.php`) | a small table: product · packets · packet price · line, with a running total and "must equal the Shopify price" check |
| `ProductController::setupError` / delete guard | loop over rows; same rules per row (no bundle inside a bundle, no self, SKU required, total > 0) |

Rules that stay exactly as today: single level only; the line IS the product inside (its SKU / variant /
product); order **edit** never re-expands; `supported()` gates everything so files can land before the SQL.

## Things to decide before building (owner)
1. **Where the packet price lives** — on the bundle row (recommended: Taimur controls the split and the
   total is checked) vs the product's own price (then the sum rarely matches Shopify and discounts creep in).
2. **Free / returned handling**: if a customer returns one packet of a 2+1 bundle, each line is already its
   own product, so today's return flow works per line. Confirm that is wanted (vs refund the whole bundle).
3. **Reports**: do you want to know how many *bundles* sold, not just packets? Round 1 chose not to tag
   lines. If yes, add `bundle_sku` on `t_crm_prod_order_line_item` at that time (one nullable column).
4. **Shopify "bundle" apps**: if Shopify ever sends the components itself (some bundle apps do, with a
   parent line), the webhook must drop the parent line. Not the case today; check when the first
   multi-product bundle is created in Shopify.

## Size
1 SQL (table + migration of round-1 rows) · ~8 web files (all already touched in round 1) · 1 Blade partial
rewrite · APK (2 screens). Proof: extend `test_product_bundles_oct15.php` — the same sections, with a
2-product fixture, plus "round-1 single bundle still gives one line".
