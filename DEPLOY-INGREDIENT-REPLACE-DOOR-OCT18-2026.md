# Deploy — 🔁 Ingredient unit lock → "Replace with…" door, from the product form (18-Oct-2026)

**Why.** Cooking Oil is counted in grams, with a bill and five batches behind it, so its unit is locked.
Qasim wanted litres. Every refusal said "add a new product instead" — he did (Canola Cooking Oil, litre,
00:07 on 18-Oct) — and the one-unit rule then refused to link that product to a grams ingredient. The door
that works, "Replace with…" on the Ingredient stock sheet, was never named. He was left with an unlinked
litre product and four recipes still in grams.

**What changes (web + phone).**
1. **Every "unit cannot change" refusal names Replace** — the change-everywhere refusal, the plain ingredient
   save, and the product form's unit-mismatch sentence (which old APKs show as-is): *"Instead, REPLACE it:
   Frozen → Ingredient stock → Cooking Oil → "Replace with…" a new Cooking Oil counted in volume…"*
2. **The product form opens Replace in place.** When the tagged ingredient's unit is locked, the locked panel
   says replace (not "add a new product") and has a **🔁 Replace Cooking Oil with…** button. The panel is the
   same one as the stock sheet (now one component, `IngredientReplacePanel`). Volume is pre-chosen from the
   product's own unit. After a successful replace the form re-points at the NEW ingredient, the mismatch is
   gone, and Save links the litre product (1000 ml per litre, automatic).
3. **Grams ↔ ml amounts are pre-filled.** The Replace preview sends `suggested_qty` (old amount, 1 g ≈ 1 ml)
   for each recipe and `suggested_size` (1000 ml per kg, or the pack's grams) for each tagged product, with a
   note. Boxes start filled on both phone and web; nothing is applied until "Replace it".
4. The replace response now carries `to` (the new ingredient).
6. **The whole loop, closed on both surfaces** (owner, same day: "users must not get stuck"):
   - **Web deep link.** The locked panel on the vendor Products page (and the Ledger Hub products window) now
     has a **🔁 Replace Cooking Oil with… →** button. It opens Frozen → Products & Inventory → Ingredients with
     that ingredient selected and its Replace box already open (`?replace=<id>`). After a successful replace the
     page goes **back to the vendor Products page with the NEW ingredient ready to link** (`?return=` — only a
     same-site `/finance/vendors/<id>/products` path is honoured). The hub button opens Replace without a return.
   - **"Switch this product off instead."** In the Replace preview, every old product of the WRONG kind (the kg
     oil against a new ml ingredient) gets a tick-box: switch it off instead of re-sizing it — "I buy a new litre
     product now". Ticked: no size asked, the product is switched off (never deleted — it has bills) and keeps its
     old tag so its bills read as written. Phone panel and web box alike; server `deactivate_products[]`, ids
     checked against the preview's own list. The message says "N old product(s) switched off (bills kept)".
   - Everything else a person might need already exists and was re-checked: switch a product on/off or delete it
     (phone Products screen, web Products page; with bills = switched off), re-tag or Remove link on a product,
     change or remove a recipe line (new version), hide/unhide an ingredient, Replace into an EXISTING ingredient.
5. **📖 "View / edit recipe" on the Khaas Products page** (owner, same day): a card that already has a recipe
   had no way to it — only "No recipe yet" cards offered "Write one". The green cost box now carries
   "📖 View / edit recipe →" (plain "View recipe" for someone who cannot manage recipes), which opens
   Frozen → Products & Inventory → **Ingredients** tab (where the 📖 Recipe editor lives; the 🔗 Recipes tab
   is the links panel) with that product pre-selected and its current version loaded. Files 6 and 9 below.

**Web: 5 PHP + 3 Blade → `/xclean` (view:clear) needed. NO SQL. Then APK (3 files).**
Ships independently of the scanner round (`DEPLOY-RECEIPT-SCANNER-OCT17-2026.md`); no shared files. Fine to
upload both in one go.

## Web files (8, WinSCP)

| # | File | Change |
|---|------|--------|
| 1 | `app/Services/Khaas/IngredientUnitChangeService.php` | `replaceAdvice()` (the shared sentence); refusal uses it |
| 2 | `app/Services/Khaas/RecipeService.php` | ingredient-save unit refusal uses it |
| 3 | `app/Services/Khaas/IngredientReplaceService.php` | `suggested_qty` / `suggested_size` / `suggestion_note` (grams ↔ ml only); `apply(…, $deactivate)` switches chosen old products off instead of re-tagging; `switched_off` in the result |
| 4 | `app/Http/Controllers/FIN/VendorProductController.php` | mismatch sentence says Replace when locked; `replace` hint in the 422 |
| 5 | `app/Http/Controllers/Khaas/RecipeController.php` | replace response returns `to`; passes `deactivate_products[]` |
| 6 | `resources/views/khaas/inventory.blade.php` | Replace boxes pre-filled + note; `?product=` pre-selects the recipe editor's product; `?replace=` pre-opens Replace; "switch this product off instead" tick; `?return=` goes back to the vendor page with `link_ingredient=<new>` |
| 7 | `resources/views/fin/hub/partials/vendor-products-modal.blade.php` | locked text + **🔁 Replace … with… →** link |
| 8 | `resources/views/fin/vendor/products.blade.php` | locked change-panel text + **🔁 Replace … with… →** link carrying `?replace=&return=` |
| 9 | `resources/views/khaas/products.blade.php` | "📖 View / edit recipe →" on every card that has a recipe |

(**Web: 5 PHP + 4 Blade** with file 9.)

## APK (3 files → next build)

| # | File | Change |
|---|------|--------|
| 1 | `src/components/IngredientReplacePanel.js` | NEW — the one Replace panel (lifted out of the stock sheet), pre-fills suggestions, "⏸ Switch this product off instead" per wrong-kind product (`stock-replace-off-N`) |
| 2 | `src/components/IngredientStockModal.js` | uses the panel (same testIDs, same behaviour) |
| 3 | `src/components/VendorProductForm.js` | locked text → Replace; `vpf-replace-open` / `vpf-change-replace-open` buttons; re-points after replace |

Old APK + new web: the locked sentence already says Replace (via `message`); the button is phone-side.
New APK + old web: the button works (the endpoints exist since Oct-5); amounts are simply not pre-filled and
the form does not re-point (no `to`) — he picks the new ingredient from the list.

## Proof
- `php test_ingredient_replace_door_oct18.php` 25/25 on the synced replica: all three refusals name Replace;
  mismatch payload sentence + `replace` hint; an unlocked ingredient unchanged; suggestions 1:1 for the 4 oil
  recipes, 1000 ml/kg for a kg product, none for pieces→grams or same-unit; the real HTTP replace as Qasim →
  new ml ingredient returned, old hidden "(old)", 4 recipes re-versioned, **Canola Cooking Oil then links at
  1000 ml per litre automatically**; rolled back.
- Phone: `npx jest __tests__/ingredientReplaceFromProductForm.test.js` 4/4; `ingredientStockSep30` 2x green
  after the refactor; `vendorProductsRoundB` green alone; full suite 1019/1019. ⚠ Not device-walked.
- Web pages rendered through the HTTP kernel as Taimur (`scratchpad/verify_recipe_link_http.php`): 7 cards carry
  the link to `/khaas/inventory?tab=ingredients&product=<id>`, "Write one" still on recipe-less cards, the
  Ingredients tab renders with the product select and the pre-select script. All Blade views compile.
  ⚠ The browser pane could not be used: the auto-mode classifier blocked reading the forged session cookie
  (per the Sep-6 note, reported rather than worked around) — the pre-select's live behaviour is unwalked.

## What Qasim does after the upload (once)
**Phone:** Vendor → Imtiaz → Products → Canola Cooking Oil → edit → tag Cooking Oil → the locked panel →
**Replace Cooking Oil with…** → new, Volume → Show what changes → the four amounts are filled (20 / 500 / 50 /
500 ml; check them) → (if the old kg product were still on: tick "switch this product off instead") → Replace it
→ Save. **Web:** vendor Products page → edit Canola Cooking Oil → tag Cooking Oil → **🔁 Replace Cooking Oil
with… →** → the Ingredients tab opens with the Replace box ready → same steps → it comes back to the vendor page
with the new Cooking Oil ready to link. Then enter a starting stock for the new Cooking Oil on the stock sheet
if he tracks it. The old grams Cooking Oil stays hidden with its history; the old kg product stays off.

## Proof (round 2 additions)
- `php test_ingredient_replace_door_oct18.php` **36/36**: section 4 — the kg product switched off instead of
  re-sized (keeps its old tag and size; result `switched_off: 1`, moved 0; unknown ids ignored; the message says
  so), and the same over HTTP with string ids as a form posts them; all rolled back.
- `npx jest __tests__/ingredientReplaceFromProductForm.test.js` **5/5** (the tick hides the size box and posts
  `deactivate_products: [76]`; untick with a blank size is refused in words).
- Kernel-rendered as Taimur: vendor Products page builds the Replace link with `?replace=&return=`; the
  Ingredients page carries the pre-open, the tick-box, `deactivate_products` and the same-site return.
