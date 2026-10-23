# DEPLOY — everything uncommitted, ONE checklist (20-Oct-2026) ✗live

Covers every round built after web `5959ef7` / mobile `f6ce718` (both 18-Oct). They share files, so ship
them together: **1 SQL → 46 web files → `/xclean` → StackCP task → APKs (Rider + Messages)**.

| Round | Own checklist (detail + smoke tests) |
|---|---|
| 🔁 Ingredient "Replace with…" door (Oct-18) | `DEPLOY-INGREDIENT-REPLACE-DOOR-OCT18-2026.md` |
| 💥 KS-36 shop cash→online hotfix + KS orders ONLINE (Oct-19) | `DEPLOY-HOTFIX-SHOP-CASH-TO-ONLINE-OCT19-2026.md` |
| ✂ Split invoice (Oct-19) | `DEPLOY-SPLIT-INVOICE-OCT19-2026.md` |
| 📦 Box count + "ordered" qty (Oct-19) | `DEPLOY-SCAN-ORDERED-QTY-OCT19-2026.md` |
| 🍱 Recipe families · End Batch per size · Repack (Oct-19) | `DEPLOY-RECIPE-FAMILIES-OCT19-2026.md` |
| 🔴⏳ Tag alerts round 1 (web) + round 2 (APKs) (Oct-19) | `../DEPLOY-TAG-ALERTS-ROUND1/ROUND2-OCT19-2026.md` |

The KS-36 hotfix is already inside the working-tree `OrderController.php` / `WarehouseController.php`, so
the `HOTFIX-KS36-OCT19-2026/` folder is NOT needed (whether or not it was uploaded earlier).

## 1. SQL (prod, FIRST) — `C:\NF App\PENDING-PROD-OCT20-2026-ALL.sql`
Whole file, one session. STEP 0 is read-only (check A = Oct-18 SQL present, B = KS-36 repair committed,
C = Taimur is user 68). STEP 5 prints one verify row: all 1, roles 3 / 3, tables 5, columns 4, users `68`.
Tested on a throwaway copy of the local schema: first run and re-run both clean.

## 2. Web files (WinSCP) — 46 files, only under `app/`, `resources/views/`, `routes/`

**app/ (30)**
```
app/Console/Commands/MentionFollowups.php                      NEW
app/Http/Controllers/API/CustomerAppController.php
app/Http/Controllers/API/RiderController.php
app/Http/Controllers/API/WhatsAppController.php
app/Http/Controllers/CRM/OrderController.php
app/Http/Controllers/CRM/OrderSplitController.php              NEW
app/Http/Controllers/CRM/ProductController.php
app/Http/Controllers/CRM/RiderReportsController.php
app/Http/Controllers/CRM/WarehouseController.php
app/Http/Controllers/FIN/VendorProductController.php
app/Http/Controllers/Khaas/RecipeController.php
app/Http/Controllers/KhaasController.php
app/Http/Controllers/SysAdmin/RolePermissionController.php
app/Http/Controllers/Web/WhatsAppWebController.php
app/Models/CRM/OrderLineItemModel.php
app/Models/CRM/ProductModel.php
app/Models/Khaas/FamilyRecipeLineModel.php                     NEW
app/Models/Khaas/FamilyRecipeModel.php                         NEW
app/Models/Khaas/RecipeFamilyModel.php                         NEW
app/Models/Khaas/RecipeModel.php
app/Services/CRM/LineItemQuantityService.php
app/Services/CRM/OrderSplitService.php                         NEW
app/Services/FirebaseService.php
app/Services/Khaas/FrozenMonthService.php
app/Services/Khaas/IngredientReplaceService.php
app/Services/Khaas/IngredientUnitChangeService.php
app/Services/Khaas/RecipeFamilyService.php                     NEW
app/Services/Khaas/RecipeService.php
app/Services/Khaas/RepackService.php                           NEW
app/Services/WhatsApp/MentionFollowupService.php               NEW (new folder WhatsApp/ if absent)
```
**resources/views/ (14)**
```
resources/views/fin/hub/partials/vendor-products-modal.blade.php
resources/views/fin/vendor/products.blade.php
resources/views/khaas/inventory.blade.php
resources/views/khaas/month-review.blade.php
resources/views/khaas/products.blade.php
resources/views/pages/messages/index.blade.php
resources/views/pages/orders/index.blade.php
resources/views/pages/orders/invoice.blade.php
resources/views/pages/orders/partials/split-modal.blade.php     NEW
resources/views/pages/products/create.blade.php
resources/views/pages/products/edit.blade.php
resources/views/pages/products/partials/recipe-family-section.blade.php  NEW
resources/views/pages/riders-map/index.blade.php
resources/views/pages/roles/permissions.blade.php
```
**routes/ (2)**
```
routes/api.php
routes/web.php
```
Do NOT upload: `test_*.php`, `database/migrations/*.sql`, `HOTFIX-KS36-OCT19-2026/`, the `.md`/`.sql` notes.

## 3. `/api/public/xclean` — required (routes + Blade changed)

## 4. StackCP → Scheduled Tasks → new task, every 1 minute
```
/usr/bin/php82 /home/sites/29a/8/8556230fc3/public_html/app/artisan whatsapp:mention-followups
```

## 5. APKs — `build-production-apk-auto.bat` → option **6 (RIDER + MESSAGES)**
- **Nizami Farms (primary / Rider)** — split, box count, ingredient replace, recipe families, tagger reminders.
- **NF Messages** — tag alerts (loud channel, On it, full-screen).
- **NF Qurbani — not needed.**
The script bumps the version in `app/Http/Controllers/API/AppController.php` and
`resources/views/pages/auth/login.blade.php` and copies the APKs to `public/downloads/`, so afterwards upload
those **2 web files + `public/downloads/NizamiFarms-Rider.apk` + `public/downloads/NizamiFarms-Messages.apk`**.
Open each app once after install (registers the `team_alerts` capability). Taimur's one-time phone setup is
in the tag-alerts round-2 checklist.

## 6. After the upload
- Optional: STEP 6 at the bottom of the SQL file (KS orders placed before the upload → online).
- Owner once: Frozen → Products & Inventory → Ingredients → 🍱 set-up panel → set up the families, then
  Month Review → October → "Re-apply recipes for this month" (see the recipe-families checklist, steps 5–7).
- ⚠ Don't press Save on the Management / Taimur / khaas / Adnan / supervisor 2 role pages — 10 keys have no
  checkbox and get switched off (listed in the split-invoice checklist).
- Then commit both repos (committed = deployed).
