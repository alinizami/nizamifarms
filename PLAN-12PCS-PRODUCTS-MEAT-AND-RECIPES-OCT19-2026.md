# PLAN — the seven "12 pcs" products deduct nothing (19-Oct-2026)

**Finding.** Accept Demand deducts meat from Storage through `t_crm_khaas_product_recipe` (product → meat, ratio).
That table has rows for the 8 original products only. The seven "12 pcs" single packs (created 16-Oct) have NO
mapping and NO recipe. Accept finds nothing to deduct, writes `storage_deducted = 1, storage_deducted_qty = 0`,
starts the batches, and still answers "Raw materials deducted and batches started". At batch end the stock-in
finds no recipe either, so salt / oil / maida are not consumed. Nothing moved to the end of the batch; the code
has not changed since April. Prod (owner's query, 19-Oct): demand 63 (16-Oct 22:47) and demand 64 (18-Oct 21:41),
six items, 0 kg deducted, no `used` rows in the storage log since 17-Oct.

| Item | Demand | Product | kg | Meat (twin's mapping) |
|---|---|---|---|---|
| 141 | 63 | Beef Shami Kabab (12 pcs) | 3.0 | 1334 DKC Veal LEAN Boneless Boti |
| 142 | 63 | Chicken Shami Kabab (12 pcs) | 3.0 | 1336 KW Chicken LEAN Breast Butterfly |
| 143 | 64 | Beef Qeema Samosa (12 pcs) | 2.0 | 1333 KW Veal LEAN Minced (Qeema) |
| 144 | 64 | Chicken Cheese Samosa (12 pcs) | 3.0 | 1332 KW Chicken LEAN Thigh Boneless |
| 145 | 64 | Chicken Qeema Samosa (12 pcs) | 2.0 | 1339 KW Chicken LEAN Thigh Minced (Qeema) |
| 146 | 64 | Chicken Veg Spring Rolls (12 pcs) | 1.5 | 1332 KW Chicken LEAN Thigh Boneless |

⚠ Chicken Thigh (1332): the replica showed **1.07 kg on hand** against **4.5 kg** needed by items 144 + 146. With
the mapping in place the accept would have REFUSED ("Insufficient raw materials"). Either the Storage figure was
already behind (meat received and not logged) or the kg typed is not meat kg. Check Storage for 1332 on prod
before the correction below — a deduction past zero is clamped to 0 and the difference is lost.

## Step 1 — SQL on prod NOW: the mapping rows (copy the twin, ratio 1.0, no variant — as the twins are)
```sql
INSERT INTO t_crm_khaas_product_recipe (khaas_product_id, storage_product_id, storage_variant_id, custom_material_id, ratio_kg, is_active, created_at, updated_at) VALUES
(1489, 1332, NULL, NULL, 1.000, 1, NOW(), NOW()),  -- Chicken Cheese Samosa (12)  ← twin 1313
(1498, 1333, NULL, NULL, 1.000, 1, NOW(), NOW()),  -- Beef Qeema Samosa (12)       ← twin 1314
(1507, 1339, NULL, NULL, 1.000, 1, NOW(), NOW()),  -- Chicken Qeema Samosa (12)    ← twin 1338
(1516, 1332, NULL, NULL, 1.000, 1, NOW(), NOW()),  -- Chicken Veg Spring Rolls (12)← twin 1316
(1525, 1334, NULL, NULL, 1.000, 1, NOW(), NOW()),  -- Beef Shami Kabab (12)        ← twin 1318
(1534, 1336, NULL, NULL, 1.000, 1, NOW(), NOW()),  -- Chicken Shami Kabab (12)     ← twin 1331
(1543, 1343, NULL, NULL, 1.000, 1, NOW(), NOW());  -- Chicken Reshmi Kebab (12)    ← twin 1315
```
Check first that none exists: `SELECT * FROM t_crm_khaas_product_recipe WHERE khaas_product_id IN (1489,1498,1507,1516,1525,1534,1543);`

## Step 2 — correct Storage for the six items (prod, NOW)
**Preferred: through the app**, so inventory + log stay consistent — phone Khaas Storage → the meat → *Use*
(`POST /warehouse/storage/use`), one entry per line, note "Correction: demand #63 / #64 accepted without mapping":
1334 −3.0 · 1336 −3.0 · 1333 −2.0 · 1332 −4.5 · 1339 −2.0.
**Fallback SQL** (same effect as acceptDemand; one block per meat; the BU-2 row with `source_variant_id IS NULL` is
the live one — the variant rows hold 0):
```sql
-- repeat for (1334, 3.0, 'Production demand #63'), (1336, 3.0, '#63'), (1333, 2.0, '#64'), (1332, 4.5, '#64 ×2 items'), (1339, 2.0, '#64')
SET @sp = 1334, @qty = 3.000, @note = 'Correction: production demand #63 (accepted 16-Oct without a meat mapping)';
SELECT @inv := id, @before := quantity_on_hand FROM t_crm_khaas_storage_inventory
 WHERE khaas_business_unit_id = 2 AND source_product_id = @sp AND source_variant_id IS NULL;
UPDATE t_crm_khaas_storage_inventory SET quantity_on_hand = GREATEST(0, quantity_on_hand - @qty),
       total_used = total_used + @qty, last_used_at = NOW() WHERE id = @inv;
INSERT INTO t_crm_khaas_storage_log (storage_inventory_id, source_product_id, khaas_business_unit_id, change_type,
       quantity_before, quantity_change, quantity_after, notes, created_by, created_at)
VALUES (@inv, @sp, 2, 'used', @before, -@qty, GREATEST(0, @before - @qty), @note, 68, NOW());
```
Then the demand items, so reports read true:
```sql
UPDATE t_crm_khaas_production_demand_item SET storage_deducted_qty = quantity_kg, updated_at = NOW() WHERE id IN (141,142,143,144,145,146);
```

## Step 3 — recipes for the seven products (so batch END consumes salt / oil / maida)
Copy each twin's CURRENT recipe on prod, scaled per pack (8 → 12 = ×1.5; 6 → 12 = ×2), same batch size in packs.
Run on prod, one block per product — or write them by hand on Frozen → Products & Inventory → Ingredients → Recipe.
```sql
-- (new_id, twin_id, factor): (1489,1313,1.5) (1498,1314,1.5) (1507,1338,1.5) (1516,1316,1.5) (1525,1318,2) (1534,1331,2) (1543,1315,1.5)
SET @new = 1489, @twin = 1313, @f = 1.5;
SELECT @src := id FROM t_crm_khaas_recipe WHERE product_id = @twin AND is_current = 1;
INSERT INTO t_crm_khaas_recipe (product_id, version, basis_packets, effective_from, is_current, note, created_by, created_at, updated_at)
SELECT @new, 1, basis_packets, CURDATE(), 1, CONCAT('Copied from the twin recipe v', version, ' × ', @f, ' per pack'), 68, NOW(), NOW()
  FROM t_crm_khaas_recipe WHERE id = @src;
SET @r = LAST_INSERT_ID();
INSERT INTO t_crm_khaas_recipe_line (recipe_id, ingredient_id, qty_per_basis, is_optional, sort_order, created_at)
SELECT @r, ingredient_id, ROUND(qty_per_basis * @f, 3), is_optional, sort_order, NOW()
  FROM t_crm_khaas_recipe_line WHERE recipe_id = @src;
```
Guard first: `SELECT product_id, COUNT(*) FROM t_crm_khaas_recipe WHERE product_id IN (1489,1498,1507,1516,1525,1534,1543) GROUP BY 1;`
must return nothing. The recipes take effect from today, so the six open batches consume correctly when ended
with a quantity (stock-in made_on ≥ today). Then Qasim checks each on the web Recipes editor (the meat line
comes from the mapping; the rest is the twin × factor).

## Step 4 — code (small round, after the data): accept never lies again
1. `acceptDemand`: an item whose product has no active meat mapping → refuse the whole accept, naming the
   product ("Beef Shami Kabab (12 pcs) has no meat set — Frozen → Products → Recipes"). No silent 0.
2. The success message lists what was taken per item: "Beef Shami (12): 3 kg Veal Boti from Storage".
3. `getDemandProducts` marks products with no mapping / no recipe, and the phone picker shows the mark.
4. Later: creating a size variant (12 pcs) of an existing product offers to copy its mapping and recipe.

## Order
Step 1 → Step 2 → Step 3 → (end the six open batches with real quantities) → Step 4 ships with the next web round.
Nothing in the pending deploy (`DEPLOY-INGREDIENT-REPLACE-DOOR-OCT18-2026.md`, scanner round) touches any of this.
