-- ============================================================================
-- 🍱 RECIPE FAMILIES (Oct-19-2026) — one recipe per preparation, in PIECES
--
-- A FAMILY is what the kitchen cooks: "Chicken Cheese Samosa". A PRODUCT is a pack
-- of it: "(8 pcs) - Single Pack", "(12 pcs) - Single Pack". The family owns ONE
-- recipe, written per reference batch in pieces ("one batch makes 80 pieces"). Each
-- pack size keeps its per-product recipe row in t_crm_khaas_recipe, but only as an
-- exact derived snapshot written by App\Services\Khaas\RecipeFamilyService
-- (qty × pieces ÷ gcd, basis_pieces ÷ gcd — integer maths, nothing rounded), so every
-- existing reader (consumption, costs, Month Review, stock sheet) keeps working.
--
-- Also: batch outputs (End Batch asks packs per size) and repacks between sizes.
--
-- ⚠ Additive only. The web code checks these tables exist before reading them, so
--   uploading files before the SQL is harmless. Plain DDL, run ONCE, in this order.
-- ============================================================================

-- 1. the families
CREATE TABLE IF NOT EXISTS t_crm_khaas_recipe_family (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  business_unit_id  INT NOT NULL,
  name              VARCHAR(120) NOT NULL COMMENT 'e.g. Chicken Cheese Samosa — the preparation, not a pack',
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  created_by        INT NULL,
  created_at        TIMESTAMP NULL DEFAULT NULL,
  updated_at        TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uq_recipe_family_name (business_unit_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='A preparation the kitchen cooks - its pack sizes are products that point here.';

-- 2. the family recipe — versions, per reference batch in PIECES
CREATE TABLE IF NOT EXISTS t_crm_khaas_family_recipe (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  family_id       INT NOT NULL,
  version         INT NOT NULL DEFAULT 1,
  basis_pieces    INT NOT NULL COMMENT 'pieces the reference batch makes - the denominator',
  effective_from  DATE NOT NULL,
  is_current      TINYINT(1) NOT NULL DEFAULT 1,
  note            VARCHAR(255) NULL,
  created_by      INT NULL,
  created_at      TIMESTAMP NULL DEFAULT NULL,
  updated_at      TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uq_family_recipe_version (family_id, version),
  KEY idx_family_recipe_current (family_id, is_current),
  CONSTRAINT fk_family_recipe_family FOREIGN KEY (family_id)
    REFERENCES t_crm_khaas_recipe_family (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One version of a family recipe: what one reference batch of N pieces uses.';

CREATE TABLE IF NOT EXISTS t_crm_khaas_family_recipe_line (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  family_recipe_id  INT NOT NULL,
  ingredient_id     INT NOT NULL,
  qty_per_basis     DECIMAL(14,3) NOT NULL COMMENT 'base units for the whole reference batch',
  is_optional       TINYINT(1) NOT NULL DEFAULT 0,
  sort_order        INT NOT NULL DEFAULT 0,
  created_at        TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uq_family_recipe_line (family_recipe_id, ingredient_id),
  KEY idx_family_recipe_line_ing (ingredient_id),
  CONSTRAINT fk_family_recipe_line_recipe FOREIGN KEY (family_recipe_id)
    REFERENCES t_crm_khaas_family_recipe (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One ingredient inside one family recipe version.';

-- 3. a pack size points at its family, and says how many pieces it holds
ALTER TABLE t_crm_prod_product
  ADD COLUMN recipe_family_id INT NULL COMMENT 'FK t_crm_khaas_recipe_family — which preparation this pack is' AFTER sell_unit,
  ADD COLUMN pieces_per_pack SMALLINT UNSIGNED NULL COMMENT 'pieces in one pack (8, 12) - required with a family' AFTER recipe_family_id,
  ADD KEY idx_product_recipe_family (recipe_family_id);

-- 4. a per-product recipe row that was DERIVED from a family version
ALTER TABLE t_crm_khaas_recipe
  ADD COLUMN family_recipe_id INT NULL COMMENT 'FK t_crm_khaas_family_recipe — set when this row is a family snapshot' AFTER note,
  ADD COLUMN family_scale INT NULL COMMENT 'pieces_per_pack ÷ gcd: member qty = family qty × this' AFTER family_recipe_id,
  ADD KEY idx_khaas_recipe_family (family_recipe_id);

-- 5. End Batch: packs per size (one row per size the batch was packed into)
CREATE TABLE IF NOT EXISTS t_crm_product_batch_output (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id          BIGINT UNSIGNED NOT NULL,
  product_id        BIGINT UNSIGNED NOT NULL,
  packs             INT NOT NULL,
  warehouse_log_id  BIGINT UNSIGNED NULL COMMENT 'the stock_in this output wrote',
  created_by        INT NULL,
  created_at        TIMESTAMP NULL DEFAULT NULL,
  KEY idx_batch_output_batch (batch_id),
  KEY idx_batch_output_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='What one production batch was packed into, per pack size.';

-- 6. Repack between sizes of one family (12-pcs packs opened into 8-pcs packs)
CREATE TABLE IF NOT EXISTS t_crm_khaas_repack (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  business_unit_id  INT NOT NULL,
  family_id         INT NULL,
  from_product_id   BIGINT UNSIGNED NOT NULL,
  from_packs        INT NOT NULL,
  to_product_id     BIGINT UNSIGNED NOT NULL,
  to_packs          INT NOT NULL,
  loose_pieces      INT NOT NULL DEFAULT 0 COMMENT 'pieces left over, declared — not stocked anywhere',
  from_log_id       BIGINT UNSIGNED NULL,
  to_log_id         BIGINT UNSIGNED NULL,
  notes             VARCHAR(255) NULL,
  created_by        INT NULL,
  created_at        TIMESTAMP NULL DEFAULT NULL,
  KEY idx_repack_bu (business_unit_id, created_at),
  KEY idx_repack_from (from_product_id),
  KEY idx_repack_to (to_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Warehouse packs of one size opened and re-packed as another size of the same family.';
