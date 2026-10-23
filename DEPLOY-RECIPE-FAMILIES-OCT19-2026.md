# Deploy — 🍱 Recipe families per piece · End Batch per size · 🔁 Repack (19-Oct-2026)

**Why.** The 8-pcs and 12-pcs packs of one preparation (Chicken Cheese Samosa, Shami Kebab, …) are separate
products, each with its own recipe and meat mapping. The 12-pcs packs had neither, so Qasim's 12-pcs batch was
accepted with **0 kg taken from Storage** while the message said "deducted". Plan:
`PLAN-RECIPE-FAMILIES-PER-PIECE-OCT19-2026.md` (phases 1–3 built here).

**What changes.**
1. **One recipe per FAMILY, written in pieces.** A pack joins a family and says how many pieces it holds. The
   family's recipe ("1 kg chicken for 80 pieces") is the only one anybody edits. Every pack gets an exact
   per-pack copy (100 g /8, 150 g /12, whole-number scaling, no rounding drift), so costing, consumption, Month
   Review and the stock sheet read exactly as before. One engine: `RecipeFamilyService`, used by web and phone.
2. **Every recipe screen says whose recipe it is.** Web Ingredients editor and phone 📖 Recipe: "Chicken Cheese
   Samosa — family recipe · Used by 8 pcs 12 pcs · Changing this changes EVERY size at once". Basis is PIECES,
   each line shows every size, cost per size. Save asks first when more than one size follows.
3. **Product forms (web create/edit, phone create/edit) have a 🍱 Recipe family block** (Frozen only, not
   bundles). A NEW product's title fills it in ("… (16 pcs)" → its family, 16 pieces), shown and changeable.
   An EDIT never changes by itself, it only offers "Looks like part of X — use it". Typos get "Did you mean".
   A new size joins and is costed, planned and deducted from day one (meat mapping comes with it).
4. **One-time set-up** (web, Frozen → **Planning** → tab 🧂 Ingredients & Quantities): suggests every family from titles,
   converts the best existing recipe into the family recipe — **its whole history, one family version per date it
   changed, each on its own date** (Oct-20 fix: Qasim re-saved every recipe on 20-Oct, so taking only the newest
   version started every family today and left the 12-pcs batches of 16/19-Oct uncosted). Exact amounts, one button
   per family or "Set up all".
5. **Accept guard.** A demand item with no meat set is refused ("Not accepted — no meat is set for: …") instead
   of accepting with 0 kg. Success says what was taken from Storage. The phone's demand list now shows each
   item's family and the server's warning (no meat / no recipe).
6. **End Batch asks packs per size** (phone, both End Batch screens), one row per size of the family (one row
   for a pack in no family). One stock-in per size, so each size is deducted and costed on its own. The answer
   also says the yield (meat taken vs the recipe's meat).
   **Later the same day (owner): a LIVE "this will use…" line while the packs are typed** — every ingredient,
   from the recipe in force today ("Chicken: 1.05 kg in these packs · 1 kg taken at accept (+5%) · Cheese 315 g ·
   Salt 21 g …"), with a hint before typing ("1 kg taken ≈ 10 × 8 pcs, or 6 × 12 pcs by the recipe"). FYI only:
   amber past ±15%, never a block — a 5–10% gap is normal yield. Server: `/api/warehouse/batch/{id}/sizes` now
   carries `lines` per size (RecipeFamilyService::perPackLines), `meat_g_per_pack`, `packs_from_taken`, `taken_kg`.
6b. **THE PLAN IS BY FAMILY (owner, same day).** The production plan lists ONE line per preparation —
   "Chicken Cheese Samosa 🍱 8 pcs · 12 pcs — packed at the end" — never "(8 pcs)" and "(12 pcs)" apart, so the
   meat is taken ONCE and the sizes are decided when the batch ends. The line stands on the family's smallest
   active size (its ANCHOR), so the stored `khaas_product_id`, the meat mapping, the batch and every older reader
   are unchanged. Two sizes of one family on a plan are refused on create and edit (`One line per preparation: …`).
   Plan lists, the accept message and the web production tab name the line by its family (`sku_name` keeps the
   pack). Web and phone read the same rows (WarehouseController::getDemandProducts; KhaasController delegates).
7. **🔁 Repack between sizes** (web Frozen Products card + phone Frozen Products card): open N packs of one size,
   pack them as another, loose pieces named. Two warehouse adjustments marked `repack`, never a stock-in, so
   nothing is cooked or deducted again. Month Review shows "🔁 ±N repacked".

8. **🥔 Every preparation has a way out of the plan (owner, same evening).** Aloo Samosa plans POTATO: its
   mapping points at "KW - Potato (Aloo)" in Storage, so its kilos come off Storage exactly like meat. A
   preparation with NO Storage-counted ingredient at all (nothing mapped, a recipe of shelf items only) is now
   ACCEPTED with nothing taken — the picker shows a green note, the accept message says "nothing from Storage —
   everything comes from the shelf", the batch starts, and End Batch counts maida, salt… from the shelf. Still
   refused: no mapping AND no recipe (or a recipe that wants meat/potato), now worded "no raw material from
   Storage (meat, potato…) is set for …" with all three ways out.
9. **Correcting a recipe, from both surfaces.** Web Ingredients tab and phone 📖 Recipe both edit the family's
   ONE recipe (basis in pieces, every line) as a new version from today. For packs already made this month the
   phone Month Review now has the same **↻ Re-apply recipes for this month** button as the web (manage
   permission; POST /api/warehouse/consumption/reapply, the one engine), and the editors say so under the version.

## ⚠⚠ Ships TOGETHER with other undeployed rounds (shared files)

| Shared file(s) | Other round (its own checklist) |
|---|---|
| `RecipeController.php`, `IngredientReplaceService.php`, `IngredientUnitChangeService.php`, `RecipeService.php`, `khaas/inventory.blade.php`, `khaas/products.blade.php` | `DEPLOY-INGREDIENT-REPLACE-DOOR-OCT18-2026.md` |
| `routes/api.php` | `DEPLOY-SCAN-ORDERED-QTY-OCT19-2026.md`, `DEPLOY-SPLIT-INVOICE-OCT19-2026.md` |
| `routes/web.php` | `DEPLOY-SPLIT-INVOICE-OCT19-2026.md` |
| `routes/api.php`, `routes/web.php` | the WhatsApp tag follow-up round (`/mention-followups/…` routes; another session's work, `MentionFollowups.php`) |

Uploading these files uploads those rounds too. Run **all** their SQL first, then upload all their files.

## Order

1. **SQL FIRST** (phpMyAdmin): `database/migrations/recipe_families_oct2026.sql`, plus the other rounds' SQL
   (`line_item_original_quantity_oct2026.sql`, `add_order_split_oct2026.sql`).
   Creates 5 tables (`t_crm_khaas_recipe_family`, `t_crm_khaas_family_recipe`, `t_crm_khaas_family_recipe_line`,
   `t_crm_product_batch_output`, `t_crm_khaas_repack`) and adds `recipe_family_id`, `pieces_per_pack` to
   `t_crm_prod_product` and `family_recipe_id`, `family_scale` to `t_crm_khaas_recipe`. Nothing existing changes.
   ⚠ `ALTER TABLE` is not rolled back by a failed transaction — run it once, check, don't re-run blindly.
2. **Web files** (below + the shared rounds' files).
3. **`/xclean`** — routes and views changed (route:clear + view:clear + config:clear).
4. **APK** with the phone files below. Old APK on the new server keeps working (absent keys = keep; old
   recipe save goes through the family; End Batch with one quantity still works). A new APK on an old server
   hides the new parts by itself.
5. **Owner, once:** Frozen → **Planning** (sidebar) → tab **🧂 Ingredients & Quantities** → the green 🍱 "Set up recipe families" panel at the top → check each
   suggested family (members, pieces, which recipe it starts from) → "Set up this family" (or "Set up all").
6. **Then** the 12-pcs data clean-up from `PLAN-12PCS-PRODUCTS-MEAT-AND-RECIPES-OCT19-2026.md`:
   - step 1 (meat mapping for the 12-pcs packs) is **no longer needed** once their family is set up;
   - step 2 (Storage corrections for demands 63 and 64, items 141–146) is still the owner's call;
   - step 3 (copy recipes to the 12-pcs packs) must be **SKIPPED** — the family does it.
   - Check Chicken Thigh stock in Storage before the next 12-pcs demand (replica: 1.07 kg vs 4.5 kg needed).
7. **Then, once:** web Month Review → October → **"↻ Re-apply recipes for this month"** — now right under the
   "packs have no recipe" warning near the top (it also stays inside the "Ingredients · bought, used and left" card),
   or phone Month Review under the month bar. **Rehearsed on dev 20-Oct (prod data of 19/20-Oct):** set-up carried
   7 recipe histories (4–9 versions each, from 24-Sep); Re-apply took October from **172 → 253 of 269 packs costed**
   (all five 12-pcs batches of 16/19-Oct now costed). Recipe cost Rs 60,116 = Rs 237.61 a pack. The 16 left are
   Chicken Qeema Samosa 8 pcs made 1-Oct, before its first recipe (9-Oct) — no recipe existed then, by design.

## Web files

| # | File | Change |
|---|------|--------|
| 1 | `database/migrations/recipe_families_oct2026.sql` | NEW — the SQL above |
| 2 | `app/Models/Khaas/RecipeFamilyModel.php` | NEW |
| 3 | `app/Models/Khaas/FamilyRecipeModel.php` | NEW — `currentFor(family, date)` |
| 4 | `app/Models/Khaas/FamilyRecipeLineModel.php` | NEW |
| 5 | `app/Services/Khaas/RecipeFamilyService.php` | NEW — the engine: suggest, assign/join/leave, family recipe save + exact snapshots, meat mapping fan-out, set-up plan, sizes |
| 6 | `app/Services/Khaas/RepackService.php` | NEW — options, plan, repack (pieces conserved) |
| 7 | `app/Models/Khaas/RecipeModel.php` | `family_recipe_id`, `family_scale` |
| 8 | `app/Models/CRM/ProductModel.php` | `recipe_family_id`, `pieces_per_pack` fillable |
| 9 | `app/Services/Khaas/RecipeService.php` | recipe carries its family; a family pack's save goes to the family; coverage names the family |
| 10 | `app/Http/Controllers/Khaas/RecipeController.php` | family endpoints (list, show, save, recipe, assign, suggest, setup) |
| 11 | `app/Http/Controllers/CRM/ProductController.php` | product forms' family block: checked before save, applied after |
| 12 | `app/Http/Controllers/CRM/WarehouseController.php` | accept guard + "taken from Storage"; demand list warnings; batch sizes; End Batch `outputs[]` + yield; repack |
| 13 | `app/Services/Khaas/IngredientReplaceService.php` | Replace edits family recipes too (skips the copies) |
| 14 | `app/Services/Khaas/IngredientUnitChangeService.php` | unit change covers family lines and their copies |
| 15 | `app/Services/Khaas/FrozenMonthService.php` | repack bucket |
| 16 | `routes/web.php` | `/khaas/recipe-famil*`, `/khaas/repack*` |
| 17 | `routes/api.php` | `/api/warehouse/recipe-famil*`, `/batch/{id}/sizes`, `/repack*` |
| 18 | `resources/views/khaas/inventory.blade.php` | set-up panel, family picker, family editor (pieces, sizes, cost per size) |
| 19 | `resources/views/khaas/products.blade.php` | family chip, "(X family)" recipe link, 🔁 Repack button + modal |
| 20 | `resources/views/khaas/month-review.blade.php` | "🔁 ±N repacked" chip |
| 21 | `resources/views/pages/products/partials/recipe-family-section.blade.php` | NEW — the form block |
| 22 | `resources/views/pages/products/create.blade.php` | includes it |
| 23 | `resources/views/pages/products/edit.blade.php` | includes it |
| 24 | `app/Http/Controllers/KhaasController.php` | web production tab: plan lines named by their family (same planNames engine); Products cards read `cardMonth` |
| 25 | `app/Services/Khaas/FrozenCostingService.php` | NEW `cardMonth()` (Oct-20): the card's "Made this month" counts EVERY pack made — same stock-ins and plan/direct split as Month Review — plus `costed` and `made_no_recipe`; the estimate still divides by costed packs. Web + phone cards read it |

(Files 5, 12 and 18 also carry the plan-by-family + live-usage round of the same day.)

## APK (phone files)

| # | File | Change |
|---|------|--------|
| 1 | `src/components/RecipeEditorModal.js` | family mode: header, pieces basis, every size per line, cost per size, Save warns + posts the family recipe |
| 2 | `src/components/RecipeFamilyField.js` | NEW — the product form block |
| 3 | `src/components/BatchSizesBox.js` | NEW — End Batch packs per size |
| 4 | `src/components/RepackModal.js` | NEW — repack |
| 5 | `src/screens/ProductsScreen.js` | family block in create/edit; End Batch sizes; 🔁 Repack size button |
| 6 | `src/screens/KhaasStorageScreen.js` | End Batch sizes; demand list family + warning |
| 7 | `src/screens/KhaasMonthReviewScreen.js` | "🔁 ±N repacked" chip · ↻ Re-apply recipes for this month (manage permission) |
| 8 | `src/utils/ingredientUnits.js` | `familyPackAmount`, `familyPackText` |
| 9 | `src/utils/recipeCost.js` | per piece label |
| 10 | `src/utils/recipeFamilyForm.js` | NEW — form block rules |
| 11 | `src/utils/batchSizes.js` | NEW — End Batch / repack maths |

## Checked on the dev replica (all inside rolled-back transactions)

- `php test_recipe_families_oct19.php` — **149 pass, 0 fail** (Oct-20: + carried history, 12-pcs covered on every past day): maths (8-pcs copy identical to v17, 100 g /8,
  150 g /12), join/leave rules, consumption 3×8 = 2×12 = 300 g chicken, yield, repack refusals and Month
  Review, cost 12-pack = 1.5 × 8-pack, phone create joins, old-app edit keeps the family, bundles refused;
  section M: one picker row per family on its anchor, two sizes on a plan refused (create + edit), plan lists and
  accept named by family, sizes payload with every ingredient per pack (12 = 1.5 × 8) and the meat taken.
- Web pages through the real HTTP kernel: **19 pass** (set-up panel, form block create/edit, family chip,
  repack button only with stock, editor JSON, suggest, repack options) + the Oct-18 recipe-link page check 17 pass.
- Phone: jest all green; new suites `recipeFamilyForm` (30) and `recipeFamiliesOct19` (26, incl. the live usage
  maths and box) use the server's real answers. Only `App.test.tsx` fails, as before. Android release bundle builds.
- **Walked on the Samsung S21 (Oct-19 evening, as Taimur, Frozen mode, against the dev copy through the safe
  server — no pushes, no WhatsApp, no bank mail).** Family set up → 📖 Recipe from the 12-pack (family header,
  pieces basis 80, "100 g /8 · 150 g /12") → corrected 10 → 12 packs (96 pieces, live re-statement) → "Every size
  changes" → saved v2 → both packs' copies changed from today (83.333 g / 125 g) → plan with ONE family line →
  accept took 1 kg chicken, named by family → End Batch 6 × 8 + 4 × 12 = exactly 1 kg (0%), every ingredient
  listed; a mistyped 64 showed +433% in amber and did not block → two stock-ins, 500 g chicken each → Repack
  3 × 8 → 2 × 12 (two adjustments, no ingredient rows) → Month Review "repacked −3 / +2", made counts untouched →
  Re-apply confirm → product edit form family block. Dev data restored to the pre-walk snapshot afterwards.
  **Fixed during the walk** (all ✗live with this round): Repack button, plan History tab and "Recipe family"
  heading showed only the emoji on the S21 (emoji + word split, as the Sep-27 History fix); plan picker hid
  the Storage kg figure under the kg box for long names (row wraps now); "Not in a family" hint wrongly said
  "no recipe" (phone + web); choosing a family by hand now fills an empty pieces box from the title (phone + web);
  repack message by family and size instead of two full titles, plus the new warehouse counts; Re-apply
  dialog said "October 2026 (Current)"; Products lost its search when coming back to the tab (old bug, ref fix).

## Left out on purpose

- No packaging lines per pack size (boxes, labels) — the recipe is the food only.
- No "hide a family" screen; a family with no members simply shows nothing.
- No web End Batch — batches are ended on the phone only, as today.
