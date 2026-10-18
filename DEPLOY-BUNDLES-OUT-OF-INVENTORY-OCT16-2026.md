# Deploy — 📦 Bundles out of inventory lists (16-Oct-2026)

**Why.** Bundle products (e.g. "Beef Qeema Samosa – Pack of 2") showed up on the stock screens as if they
were physical packs, which spoiled the inventory counts. A bundle is not a physical pack: its packets are
the products inside it, and those are counted already.

**What changes.** Bundles are left out of every inventory, storage and production list, on web and phone.
Nothing else changes: billing, Shopify conversion, invoices, product search for orders, the web Products
page and the phone's normal Products list all still show and use bundles.

**Web only. NO SQL, NO APK.** The phone screens get their lists from the server, so the current APK
picks this up straight away.

## Files (7, WinSCP)

| # | File | Screen(s) it fixes |
|---|------|------|
| 1 | `app/Services/CRM/ProductBundle.php` | new helper `excludeBundles()` (never throws; on any error the list comes back unfiltered) |
| 2 | `app/Http/Controllers/CRM/ProductController.php` | phone **Khaas Shop Inventory** (store mode), phone **frozen-mode Products**, frozen storage product picker. Filtered only when the phone asks for ONE business unit's products. The web Products page and the phone's normal Products list are unchanged. |
| 3 | `app/Http/Controllers/KhaasController.php` | web **Khaas → Products** page + the Khaas dashboard product count |
| 4 | `app/Http/Controllers/CRM/WarehouseController.php` | storage source products, production **demand** product list, **daily inventory report** |
| 5 | `app/Services/Khaas/FrozenMonthService.php` | **Inventory report** (phone + web) and Month Review product rows |
| 6 | `app/Services/Khaas/RecipeService.php` | recipe coverage (a bundle no longer shows as "no recipe") |
| 7 | `app/Http/Controllers/CRM/OvernightStorageController.php` | overnight chiller/freezer manual product picker |

`/api/public/xclean` is not needed (no views, routes or config changed), but running it is harmless.

## Check after upload
1. Phone, store mode → Khaas Shop Inventory: the "Pack of N" bundles are gone. The single packs are still
   there.
2. Phone, frozen mode → Products / Inventory report: no bundles.
3. Web → Khaas → Products: no bundles. Web → Products: bundles still listed and editable.
4. A new order with a bundle (web or phone) still splits into the products inside.

## Proof
`php test_bundles_inventory_hidden_oct16.php` (local, rolled back): **18/18**. For every surface, the
result with bundles equals the result with no bundles **minus exactly the bundle products**:
- phone `/products` with a business unit: frozen 10 → 9, NF 1 → 0
- storage products 461 → 460; demand list 10 → 9; inventory report 10 → 9; recipe coverage 10 → 9
- overnight picker 466 → 465; web Khaas Products 10 → 9; dashboard count −1
- daily report unchanged (bundles never had stock movements)
- **unchanged:** phone normal Products list, web Products page, order product search (still finds the
  bundle with its items), order line splitting

Earlier suites re-run green: `test_product_bundles_oct15.php` 68/68, `test_product_bundles_e2e_oct15.php` 16/16.

## Noticed, not changed
The product list endpoint returns 20 products per page and ignores `per_page`. The Khaas Shop Inventory
screen asks for 200 but gets the first 20 alphabetically. That's fine today (fewer than 20 frozen
products), but it would hide products once there are more than 20.
