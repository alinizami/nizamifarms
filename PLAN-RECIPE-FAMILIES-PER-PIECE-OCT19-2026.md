# PLAN — one recipe per FAMILY, per PIECE; sizes are only packs (19-Oct-2026, evaluation, no code)

## Why the current shape breaks now
Every table keys on the PRODUCT (= one pack size): recipes (`t_crm_khaas_recipe.product_id`, "one batch makes
N packs"), meat mapping (`t_crm_khaas_product_recipe.khaas_product_id`), demand items, batches
(`getActiveBatch(product)`), consumption (`stock_in` log × recipe ÷ basis_packets), Month Review "made" per product.
That was right while each samosa had ONE product. Now a size is a new product row (no variant link, no parent,
nothing stores pieces per pack), so every new size starts with no recipe and no meat — the seven 12-pcs products.
Copying recipes per size (the earlier idea) works but multiplies the same recipe ×3 and lets them drift.

## Recommendation: separate "what we COOK" from "what we SELL"
- **Family** = the preparation: Chicken Cheese Samosa, Beef Shami Kabab… ONE recipe, ONE meat mapping.
- **Product** = a pack of that family: 8 pcs, 12 pcs, family pack. Two new fields on the product:
  `recipe_family_id` and `pieces_per_pack` (more generally "base units per pack": pieces for counted items,
  grams for a weighed family). Bundles keep their bundle table and no family (cost = Σ inside).
- **Recipe per family, in PIECES**: "one batch like this makes N PIECES" (`basis_pieces`), lines as today.
  Per-pack lines (bag, label, box) are flagged `per_pack` so packaging follows the pack, not the piece.
- **Nothing on the user's side changes until the END of a batch.** Qasim plans and starts "Chicken Cheese
  Samosa, 3 kg" (the kg is meat, as today; meat deducted at accept via the family mapping). At End Batch he is
  asked **packs per size**: 12 pcs ×3, 8 pcs ×2 → one stock_in per product (reference = batch) → ONE consumption
  for the batch: (3×12 + 2×8) pieces × per-piece recipe. Month Review "made" stays per product; ingredient
  "used" stays per ingredient (stock sheet unchanged). A direct stock-in of a product → pieces via its family.
- **Yield check, not a second deduction**: meat was taken at accept from the PLAN (3 kg). At end, recipe meat per
  piece × pieces made = what the recipe says it should have used; show the gap ("plan 3.0 kg, recipe says 2.7 kg
  for 52 pieces") as information and, where the owner wants, as a one-tap Storage correction.
- **Repack** (12-pcs packs → 8-pcs packs later): a new action on both surfaces — from product A n packs → product
  B m packs, pieces conserved (n×12 = m×8 + loose pieces, which must be 0 or declared as waste/loose). Writes
  `repack_out` / `repack_in` warehouse logs (NOT `stock_in`, so no consumption and no "made"), one `t_crm_khaas_repack`
  row for the story. Month Review shows repacks on their own line.
- **New size = two fields, no prompt, no copy.** Creating "Chicken Cheese Samosa (12 pcs)" on the web or phone
  product form = pick the family + type 12. It is costed, planned and consumed from the first day. The "copy
  recipe" prompt becomes unnecessary. (Default both fields from the title when it matches "<name> (N pcs)".)

## Data (small, additive, backwards-compatible)
| Table | Change |
|---|---|
| `t_crm_khaas_recipe_family` NEW | id, business_unit_id, name, is_active |
| `t_crm_prod_product` | + `recipe_family_id` (null), + `pieces_per_pack` (null) |
| `t_crm_khaas_recipe` | + `family_id` (null), + `basis_pieces` (null); `product_id` / `basis_packets` kept for old versions |
| `t_crm_khaas_product_recipe` (meat) | + `family_id`; accept resolves product → family → mapping, else falls back to the product row |
| `t_crm_product_batch` | + `family_id` (null); NEW `t_crm_product_batch_output` (batch_id, product_id, packs) |
| `t_crm_khaas_production_demand_item` | + `family_id` (null); `khaas_product_id` kept (= the product picked) |
| warehouse log | new `change_type` values `repack_in` / `repack_out`; NEW `t_crm_khaas_repack` |

Resolution rule everywhere: `RecipeModel::currentFor(product)` → the product's family recipe scaled by
`pieces_per_pack`; a product without a family → its own per-product recipe exactly as today. So nothing that works
now stops working, and migration is per family at the owner's pace.

## Migration of what exists
Per family: create the family row; set `recipe_family_id` + `pieces_per_pack` on each size (8 / 6 / 12, from the
title); convert the 8-pcs (or 6-pcs) product's CURRENT recipe to per-piece: `basis_pieces = basis_packets ×
pieces_per_pack`, lines unchanged (same batch, now counted in pieces); move the meat mapping to the family. Old
recipe versions stay per product (history untouched). The seven 12-pcs products need NO recipe at all after this —
only the two fields. The six wrongly-accepted items are still a data correction (PLAN-12PCS-… steps 1–2); step 3
(copy recipes) can be skipped if this lands within days, or done as a stop-gap.

## How the existing recipe converts (owner's question, 19-Oct) — nothing is re-typed
The recipe is entered per BATCH today and stays per batch. The only line that changes meaning is the basis:
"one batch makes **10 packs**" becomes "one batch makes **80 pieces**" (10 × 8, from the product's pieces per
pack). The ingredient lines are copied as they are. Real example, Chicken Cheese Samosa (8 pcs) v17:

| ingredient | per batch (stored, unchanged) | per 8-pcs pack | per piece (derived) | per 12-pcs pack (derived) |
|---|---|---|---|---|
| Chicken thigh | 1000 g | 100 g | 12.5 g | 150 g |
| Cheese | 300 g | 30 g | 3.75 g | 45 g |
| Maida | 1500 g | 150 g | 18.75 g | 225 g |
| Cooking oil | 500 g | 50 g | 6.25 g | 75 g |
| Eggs | 2 pcs | 0.2 | 0.025 | 0.3 |

Per piece and per pack are DERIVED (never stored), exactly as per-pack is derived today (`qty_per_basis /
basis_packets`); the divisor just becomes `basis_pieces`. Cost per pack = per-piece cost × pieces per pack
(+ any per-pack packaging line). The conversion is one new VERSION of the family recipe, note "converted from
8-pcs v17: 10 packs × 8 = 80 pieces"; old versions stay on the product and batches made before keep theirs.

**No benchmark size is needed.** The benchmark IS the piece. Qasim keeps typing what one batch uses, and answers
"how many pieces did this batch make?" — the editor helps him: `[__] pieces  = [__] packs of 8  = [__] packs of 12`
(type any one, the others fill in). It also shows the derived per-piece and per-pack columns above so he can
sanity-check against what he knows ("100 g chicken per 8-pcs pack — yes"). If he ever wants to enter a recipe
"as 12-pcs packs", he types 12-pcs packs in the helper and the pieces are computed; the stored truth is pieces.
Prerequisite per family: every size product has `pieces_per_pack` (8 / 6 / 12 from the titles). A product
without it cannot be joined to the family and keeps its own recipe until it is set.

## Family membership: the NAME suggests, a stored link decides (owner's question, 19-Oct)
Rule tested on every active Frozen title (replica 19-Oct): `<prefix> – NAME (N pcs) …` → family NAME, N pieces.
All 15 non-bundle products fit, 0 misfits, 8 families: Aloo Samosa (8) · Beef Qeema Samosa (8, 12) · Beef Shami
Kabab (6, 12) · Chicken Cheese Samosa (8, 12) · Chicken Qeema Samosa (8, 12) · Chicken Reshmi Kebab (8, 12) ·
Chicken Shami Kabab (6, 12) · Chicken Veg Spring Rolls (8, 12). The 58 bundles are skipped (they are packs OF products).
The title is only used to SUGGEST. Membership is the stored `recipe_family_id`, so a rename, a typo, "Kabab" vs
"Kebab", or a future "Family Pack (24 pcs)" title never silently moves a product between families.

## New product (web Products → Create, phone Products → Add) — Frozen, not a bundle
A **Recipe family** block under the title, filled while he types:
- Title matches an existing family → "🍱 Part of **Chicken Cheese Samosa** — uses its recipe (v17, 80 pieces per
  batch). Pieces in this pack: [12]" — pieces pre-filled from "(12 pcs)". One tap keeps it.
- No match → "New family: [Chicken Tikka Samosa] — no recipe yet; write it after saving" + "or pick an existing
  family ▾". Close-but-not-equal names ("Kebab" vs existing "Kabab") get "Did you mean Chicken Reshmi Kabab?".
- Pieces in this pack is REQUIRED with a family (whole number ≥ 1). Title says 12 but box says 8 → asked once.
- "Not made in the kitchen (bought in / bundle)" → no family; cost and usage are explicitly off for it.
- After save: "Saved. Costed from the Chicken Cheese Samosa recipe: Rs 214 per pack of 12." / for a new family,
  "Write its recipe now →".
Editing a product later: change family or pieces with a note "affects packs stocked in FROM NOW; earlier usage
was written at the time and stays" (consumption stores `qty_base` per stock-in, so history is safe by construction).

## Wherever a recipe is shown or edited — it is the FAMILY's, said every time
- Header on web + phone editor: "📖 **Chicken Cheese Samosa** — family recipe · used by 8 pcs, 12 pcs" (chips).
  Banner while editing: "Changing this changes the cost and stock use of BOTH packs."
- Picker on the web Ingredients tab and phone lists FAMILIES (with their packs underneath), not 15 products.
- Basis row: "One batch like this makes [80] pieces  = [10] packs of 8  = [6.67] packs of 12" — type any box.
- Derived table under the lines: per piece, and one column per pack size in the family (per pack of 8, of 12),
  plus Rs per pack at today's prices. Nothing in those columns is stored.
- Packaging lines (bag, label, box) get a "per pack" switch so they are counted per pack, not per piece.
- Product card (web Khaas Products / phone): "Recipe: Chicken Cheese Samosa family → Rs 214 / pack of 12"; the
  "View / edit recipe" link opens the FAMILY with that size's column highlighted.
- Product without a family: "No recipe — set its family" (not "Write one" per size).

## The maths (one formula, one rounding)
- Stored: `qty_per_batch` per line (unchanged), `basis_pieces` per recipe (whole number), `pieces_per_pack` per product.
- Any amount = `qty_per_batch × pieces ÷ basis_pieces`, computed in ONE step, rounded ONCE at the end:
  per pack = `qty × pieces_per_pack ÷ basis_pieces` (6 dp for cost); consumption for a batch =
  `qty × Σ(packs × pieces_per_pack) ÷ basis_pieces` rounded to 3 dp (as today). Never divide to a per-piece
  figure first and multiply back (12.5 g × 12 is exact, but 0.013 pcs × 12 is not).
- Per-pack packaging lines: `qty_per_batch × packs ÷ basis_packs_equivalent` is NOT used — they are entered
  "per pack" directly and multiplied by packs.
- Whole-piece ingredients (eggs, cubes) consume fractions per pack (0.3 egg per 12-pack); the stock sheet already
  sums decimals and shows them rounded — the month total is exact.
- Proof will assert: converted family recipe gives the SAME per-8-pack cost and usage as v17 to the paisa/gram,
  and 12-pack = 1.5 × 8-pack exactly, for every line of the 8 families.

## Edge cases (decided)
| Case | Behaviour |
|---|---|
| Product renamed | Stays in its family (link is stored) |
| Family renamed | Products keep the link; titles are not touched |
| Product moved to another family / pieces changed | From that day on; past consumption unchanged |
| Pieces per pack missing | Product keeps its own per-product recipe as today; flagged "set pieces to join the family" |
| A size had its OWN recipe when joining | Kept as history; family recipe used from the join date (effective_from) |
| Single-size family (Aloo Samosa) | A family of one; works the same |
| Bundle ("Pack of 2", mixed box) | Never in a family; cost = Σ inside products |
| Batch open across the switch | Consumed with the recipe current on its stock-in date (as today) |
| Family hidden / inactive | Its products show "family hidden — no recipe" |
| Weighed family (1 kg pack) | Phase 4: "base units per pack" = grams instead of pieces |

## Migration screen (one time, web, owner only)
"Set up recipe families" lists the 8 suggested families with their sizes and pieces (from titles) and which
recipe becomes the family recipe (the size that HAS one, e.g. 8-pcs v17 → 80 pieces). Owner confirms per family
(edit name / pieces / untick a product) → creates family, links products, writes the family version. Because of
this screen, PLAN-12PCS step 3 (copying recipes to the 12-pcs products) should be SKIPPED — it would create a
second recipe to choose between. Steps 1–2 (meat mapping + Storage correction) still run now.

## Phases (each shippable alone)
0. NOW (SQL): meat mapping for the seven + Storage corrections (PLAN-12PCS-… steps 1–2).
1. Families + pieces per pack: product form (web + phone) gets the two fields; recipe editor becomes per family
   in pieces; `currentFor` resolves through the family; consumption and cost per pack via pieces; accept guard
   refuses a product with neither family nor mapping. Migrate the 7 families.
2. End Batch asks packs per size (one batch per family); yield gap shown.
3. Repack action + Month Review line.
4. Later: "base units per pack" for weighed families (1 kg packs), family packs.

## What I would NOT do
- Shopify-style variants under one product row: orders, inventory, bundles, SKUs and Shopify sync all key on
  product + single variant; changing that is a rewrite for no gain.
- Keep copying recipes per size: three copies of one recipe drift; every new size is a chance to forget.
- Deduct meat again at End Batch: it would double count against the accept; show the gap instead.
