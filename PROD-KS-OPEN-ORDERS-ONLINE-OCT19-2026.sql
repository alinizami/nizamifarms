-- =====================================================================
-- Khaas (KS-) orders ALREADY in the pipeline with NO payment method → online.
-- New KS orders get 'online_payment' from the hotfix (WarehouseController);
-- this covers the ones placed before it went up.        RUN ON PRODUCTION
-- Not-yet-delivered only — a delivered one already has its invoice booked
-- (that needs the KS-36-style repair instead, never a plain UPDATE).
-- =====================================================================

-- S1. what would change (approval queue + live, open only)
SELECT 'queue' AS tbl, id, order_number, order_status, payment_method
FROM t_crm_shopify_order
WHERE external_source = 'khaas_storage' AND converted = 0 AND payment_method IS NULL
UNION ALL
SELECT 'live', id, order_number, order_status, payment_method
FROM t_crm_prod_order
WHERE order_number LIKE 'KS-%' AND payment_method IS NULL
  AND order_status NOT IN ('delivered', 'completed', 'cancelled', 'returned')
  AND ledger_transaction_id IS NULL;

-- S2. no rows above? stop here. Otherwise:
START TRANSACTION;

UPDATE t_crm_shopify_order
SET payment_method = 'online_payment'
WHERE external_source = 'khaas_storage' AND converted = 0 AND payment_method IS NULL;

UPDATE t_crm_prod_order
SET payment_method = 'online_payment'
WHERE order_number LIKE 'KS-%' AND payment_method IS NULL
  AND order_status NOT IN ('delivered', 'completed', 'cancelled', 'returned')
  AND ledger_transaction_id IS NULL;

-- row counts must equal S1's queue / live counts → COMMIT; otherwise ROLLBACK;
COMMIT;
