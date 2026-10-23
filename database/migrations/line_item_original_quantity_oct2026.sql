-- ============================================================================
-- Original ordered quantity on a line — Oct-19-2026
-- Run ONCE on prod BEFORE uploading the web files. Safe to re-run.
--
-- t_crm_prod_order_line_item.original_quantity — the quantity the line had
-- BEFORE it was first changed after ordering (a meat weight scan, a frozen box
-- count, the scanner's manual entry, or a web Edit Invoice save). Written ONCE,
-- never overwritten, so managers always see what the customer asked for:
--   "ordered 3 → packed 2" / "ordered 2.00 kg → scanned 2.15 kg".
--
-- NULL = never changed (or changed before this column existed). Shown on the
-- web Edit Invoice + order view and the phone's open-orders card ONLY — never
-- on a printed / PDF / WhatsApp invoice.
--
-- Until this column exists the web hides the "ordered" line and stamps
-- nothing (every reader checks the column first), so upload order is safe
-- either way — but run this first so no change goes unrecorded.
--
-- No backfill: changes made before today kept no record of the old number.
-- ============================================================================

ALTER TABLE t_crm_prod_order_line_item
    ADD COLUMN IF NOT EXISTS original_quantity DECIMAL(10,3) NULL DEFAULT NULL AFTER quantity;

-- Check (expect one row, decimal(10,3), YES, NULL):
SHOW COLUMNS FROM t_crm_prod_order_line_item LIKE 'original_quantity';
