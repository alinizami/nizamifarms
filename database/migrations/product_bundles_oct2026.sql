-- ============================================================================
-- 📦 BUNDLED PRODUCTS (Oct-15-2026)
-- A product can be marked "Bundled": one sale of it is really several packets of
-- one OR MORE other products, e.g.
--     EK123 = 3 × Chicken Samosa @ 400                     (Shopify: 1 × 1,200)
--     EK200 = 2 × Chicken Samosa @ 500 + 1 × Chicken Roll @ 500   (Shopify: 1 × 1,500)
-- Each row below is one product inside a bundle, with its packets and the price of
-- ONE packet (owner ruling: Taimur enters packet prices; nothing is divided).
-- On Shopify accept / order create the bundle line becomes these rows as ordinary
-- order lines (App\Services\CRM\ProductBundle).
--
-- No rows = no bundles = today's behaviour exactly. The web code checks for this
-- table before reading it, so uploading files before the SQL is harmless — run it
-- BEFORE anyone sets up a bundle.
--
-- ⚠ Plain DDL, run once.
-- ============================================================================

CREATE TABLE IF NOT EXISTS t_crm_prod_product_bundle_item (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bundle_product_id  BIGINT UNSIGNED NOT NULL COMMENT 'The bundle (t_crm_prod_product.id)',
  variant_id         BIGINT UNSIGNED NOT NULL COMMENT 'A product inside it (t_crm_prod_product_variant.id)',
  qty                SMALLINT UNSIGNED NOT NULL COMMENT 'Packets of it in ONE bundle',
  unit_price         DECIMAL(10,2) NOT NULL COMMENT 'Price of ONE packet of it',
  position           TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_by         BIGINT UNSIGNED NULL,
  created_at         TIMESTAMP NULL DEFAULT NULL,
  updated_at         TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uq_bundle_item_variant (bundle_product_id, variant_id),
  KEY idx_bundle_item_variant (variant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Check (expect the table, 0 rows):
-- SELECT COUNT(*) FROM t_crm_prod_product_bundle_item;
