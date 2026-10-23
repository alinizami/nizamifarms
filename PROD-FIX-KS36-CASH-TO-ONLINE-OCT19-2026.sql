-- =====================================================================
-- PROD FIX — KS-36 (order 18523): finish the cash → online change that
--            crashed at 15:38:21 on 19-Oct-2026.          RUN ON PRODUCTION
-- =====================================================================
-- What the app SHOULD have done (OrderController::handlePaymentMethodChange
-- + reverseLedgerEntry + BalancePostingService::reverse), done here by hand:
--   1. ledger 25151 → approval_status 'reversed', balance_updated 0, note appended
--   2. undo its balance move (exact inverse of BalancePostingService::move):
--        to   leg  84 CASH_EMP_MASHOOD (asset)   applied +9930 → now −9930
--        from leg   3 REV_SALES        (income)  applied −9930 → now +9930
--   3. order 18523 ledger_transaction_id 25151 → NULL
--   4. NO new invoice — Khaas Warehouse is a SHOP customer; shop online
--      orders are settled in Online Approvals → Shop tab via "Add payment"
--      (same shape as KS-17 … KS-35).
--   5. one audit-log row so the timeline explains the change.
-- Tips Fund: nothing to do — order has no tip rows (diag Q2 showed 25151 only).
--   PRE-CHECK P3 proves it; if tip_amount > 0, STOP and ask.
--
-- Afterwards: KS-36 leaves Mashood's Daily Closing, Mashood's held cash drops
-- by Rs 9,930, and KS-36 appears in Online Approvals → Shop tab as Rs 9,930
-- unpaid for Khaas Warehouse.  No cache clear, no upload needed.
-- =====================================================================


-- ---------------------------------------------------------------------
-- PRE-CHECKS — run these first, every line must match the "expect".
-- If anything differs, STOP and send me the output.
-- ---------------------------------------------------------------------

-- P1. expect: 25151 | invoice | approved | cash | 9930.00 | open | 0.00 | 1 | 3 | 84
SELECT id, transaction_type, approval_status, mode, amount, settlement_status,
       settled_amount, balance_updated, from_account_id, to_account_id
FROM t_fin_ledger WHERE id = 25151;

-- P2. expect: 3 = REV_SALES / income ; 84 = CASH_EMP_MASHOOD / asset / employee_cash
--     (WRITE DOWN both current_balance values — used in the after-check)
SELECT id, account_code, account_type, account_category, current_balance
FROM t_fin_accounts WHERE id IN (3, 84);

-- P3. expect: online | 25151 | delivered | tip 0.00 (or NULL) | shop
SELECT o.payment_method, o.ledger_transaction_id, o.order_status, o.tip_amount,
       c.customer_type
FROM t_crm_prod_order o JOIN t_crm_prod_customer c ON c.id = o.customer_id
WHERE o.id = 18523;

-- P4. expect: exactly 1 row (25151) — no tip / payment / settlement rows
SELECT id, transaction_type, approval_status FROM t_fin_ledger WHERE order_id = 18523;

-- P5. expect: 0 — nobody has started a settlement deposit for this invoice
SELECT COUNT(*) AS pending_settlements_naming_25151
FROM t_fin_ledger
WHERE transaction_type = 'employee_deposit'
  AND approval_status = 'pending'
  AND settlement_metadata LIKE '%25151%';


-- ---------------------------------------------------------------------
-- THE FIX — run as ONE block. Each UPDATE must say "1 row(s) affected".
-- If ANY says 0, run ROLLBACK; and send me the output.
-- ---------------------------------------------------------------------
START TRANSACTION;

-- 1. reverse the invoice row (guarded on its exact current state)
UPDATE t_fin_ledger
SET approval_status = 'reversed',
    balance_updated = 0,
    comments = CONCAT(IFNULL(CONCAT(comments, '\n\n'), ''),
        'REVERSED: Payment method changed from NULL (cash) to ''online'' — shop customer, settled via Shop tab. ',
        'Manual SQL 19-Oct-2026: the web change crashed at 15:38:21 after saving the order.'),
    updated_at = NOW()
WHERE id = 25151
  AND transaction_type = 'invoice'
  AND approval_status = 'approved'
  AND balance_updated = 1
  AND settlement_status = 'open'
  AND settled_amount = 0;                                   -- expect 1

-- 2a. to-leg: Mashood's cash account (asset) gives back the 9,930
UPDATE t_fin_accounts
SET current_balance = current_balance - 9930.00
WHERE id = 84 AND account_code = 'CASH_EMP_MASHOOD' AND account_type = 'asset';   -- expect 1

-- 2b. from-leg: sales revenue (income, stored negative) gives back the 9,930
UPDATE t_fin_accounts
SET current_balance = current_balance + 9930.00
WHERE id = 3 AND account_code = 'REV_SALES' AND account_type = 'income';         -- expect 1

-- 3. unlink the order from the reversed invoice
UPDATE t_crm_prod_order
SET ledger_transaction_id = NULL
WHERE id = 18523 AND ledger_transaction_id = 25151 AND payment_method = 'online'; -- expect 1

-- 4. audit trail (same shape the app writes)
INSERT INTO t_sys_audit_log
    (at, user_id, source, action, entity_type, entity_id, entity_label, related_order_id, changes, note, created_at)
VALUES
    (NOW(), NULL, 'system', 'updated', 'ledger', 25151, '#25151 invoice', 18523,
     '{"approval_status":{"old":"approved","new":"reversed"},"balance_updated":{"old":1,"new":0}}',
     'Manual SQL fix: KS-36 cash->online (web change crashed 15:38:21 on 19-Oct)', NOW());

-- 5. look before you commit
SELECT id, approval_status, balance_updated FROM t_fin_ledger WHERE id = 25151;            -- reversed | 0
SELECT id, account_code, current_balance FROM t_fin_accounts WHERE id IN (3, 84);          -- 3: P2 + 9930 ; 84: P2 − 9930
SELECT id, payment_method, ledger_transaction_id FROM t_crm_prod_order WHERE id = 18523;   -- online | NULL

-- all good?
COMMIT;
-- anything off?  →  ROLLBACK;


-- ---------------------------------------------------------------------
-- AFTER — check on the live pages
-- ---------------------------------------------------------------------
--  • Daily Closing (/finance/employee/outstanding-invoices): KS-36 gone from
--    "Cash - Mashood" (refresh the page).
--  • Online Approvals → Shop tab: KS-36, Khaas Warehouse, Rs 9,930, unpaid.
--    Record Khaas Warehouse's payment there with "Add payment" as usual.
--  • Shop-tab query proof (expect 1 row):
SELECT o.id, o.order_number, o.payment_method, o.payment_status, o.total_price
FROM t_crm_prod_order o
JOIN t_crm_prod_customer c ON c.id = o.customer_id AND c.customer_type = 'shop'
WHERE o.id = 18523
  AND o.order_status = 'delivered'
  AND o.payment_method IN ('online','Online','bank_transfer','card','online_payment')
  AND NOT EXISTS (SELECT 1 FROM t_fin_ledger l
                  WHERE l.order_id = o.id AND l.transaction_type = 'invoice'
                    AND l.approval_status IN ('approved','pending_l2'));
