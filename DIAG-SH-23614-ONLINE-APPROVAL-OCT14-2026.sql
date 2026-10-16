-- ============================================================================
-- SH-23614 (prod order id 18249, M Irfan Burney) vanished from Online Approvals
-- on 14-Oct-2026 after the order was moved from customer 4637 (landline) to 952.
--
-- RESULT READ ON PROD 14-Oct 15:11 (Workbench):
--   t_crm_prod_order 18249 → customer_id 952, payment_method 'online',
--                            ledger_transaction_id NULL, total 53,687.40
--   t_fin_ledger rows for order 18249:
--     24473 invoice        online REVERSED 14:02:30  balance_updated=0   ← the invoice
--     24579 credit_grant   7,201.60 approved 13-Oct                      ← overpay credit (on cust 4637)
--     24613 credit_consume 7,201.60 REVERSED  (applied 14:02:01, removed 14:02:23)
--     24616 credit_consume 7,201.60 REVERSED  (applied 14:04:30, removed 14:04:45)
--   No replacement invoice row exists  ⇒  run STEP 2 (one UPDATE pair).
-- ============================================================================

-- ---------------------------------------------------------------------------
-- STEP 2 — FIX (run as one block in Workbench; both UPDATEs must say 1 row)
-- Safe because 24473 moved no money (balance_updated = 0) and both consumes
-- against it are already reversed.
-- ---------------------------------------------------------------------------
START TRANSACTION;

UPDATE t_fin_ledger
   SET approval_status = 'pending_l1',
       comments = CONCAT(COALESCE(comments, ''),
                         ' | Restored to pending_l1 on 2026-10-14: the order-edit save reversed it (dropdown posted online for online_payment) and the re-post never landed')
 WHERE id = 24473
   AND order_id = 18249
   AND approval_status = 'reversed'
   AND balance_updated = 0;

UPDATE t_crm_prod_order
   SET ledger_transaction_id = 24473
 WHERE id = 18249
   AND ledger_transaction_id IS NULL;

COMMIT;

-- Check (expect pending_l1 / 24473):
SELECT l.id, l.approval_status, l.mode, l.amount, o.ledger_transaction_id, o.customer_id
FROM t_fin_ledger l JOIN t_crm_prod_order o ON o.id = l.order_id
WHERE l.id = 24473;

-- ---------------------------------------------------------------------------
-- STEP 3 (optional, read-only) — why the re-post never landed.
-- Ledger ids 24614 and 24615 were created between the two credit consumes
-- (14:02:01 … 14:04:30); if one of them was the re-posted invoice it has since
-- been deleted or re-pointed. The audit table names the actor and action.
-- ---------------------------------------------------------------------------
SELECT id, `at`, user_id, source, action, entity_type, entity_id, entity_label,
       related_order_id, changes, note
FROM t_sys_audit_log
WHERE related_order_id = 18249
   OR (entity_type = 'order'  AND entity_id = 18249)
   OR (entity_type = 'ledger' AND entity_id IN (24473, 24613, 24614, 24615, 24616))
ORDER BY id DESC LIMIT 60;

SELECT id, order_id, transaction_type, mode, approval_status, amount, created_by, created_at, description
FROM t_fin_ledger WHERE id BETWEEN 24610 AND 24620 ORDER BY id;

-- ---------------------------------------------------------------------------
-- The Rs 7,201.60 account balance: it is a credit GRANT on customer 4637 (the
-- landline record), made 13-Oct as "Paid more than order SH-23614". The order
-- now belongs to 952, so the balance did not travel with it. Move it in the UI
-- (no SQL): on 4637 → Account balance → "Remove this entry" on the 13-Oct grant,
-- then on 952 → "+ Received extra" 7,201.60 against SH-23614. Do this AFTER
-- STEP 2, and only after confirming the overpayment is real:
-- 53,687.40 + 7,201.60 = 60,889.00, which is exactly the SH-22814 (11-Sep) total.
-- ---------------------------------------------------------------------------
