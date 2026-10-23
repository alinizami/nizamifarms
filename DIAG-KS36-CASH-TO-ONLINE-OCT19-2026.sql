-- =====================================================================
-- DIAG — KS-36 (order 18523) changed cash → online, still on Mashood's
--        Daily Closing.  READ-ONLY.  Run on PRODUCTION.  19-Oct-2026
-- =====================================================================
-- Expected (from today's prod log, 15:38:21):
--   • order 18523 payment_method = 'online'  (saved BEFORE the ledger step)
--   • ledger 25151 = invoice, NOT reversed (reversal was rolled back),
--     to_account = CASH_EMP_MASHOOD, settlement open  → Daily Closing row
--   • order.ledger_transaction_id still = 25151
--   • KS-36 therefore NOT in Online Approvals → Shop tab (an approved
--     invoice row excludes it there)
-- =====================================================================

-- Q1. The order as it stands
SELECT o.id, o.order_number, o.order_status, o.payment_method, o.payment_status,
       o.ledger_transaction_id, o.total_price, o.assigned_rider_user_id,
       o.customer_id, c.customer_type, o.external_source, o.updated_at
FROM t_crm_prod_order o
LEFT JOIN t_crm_prod_customer c ON c.id = o.customer_id
WHERE o.id = 18523;

-- Q2. Every ledger row for the order (expect ONE: 25151, NOT 'reversed',
--     balance_updated = 1, no newer sibling)
SELECT l.id, l.transaction_type, l.approval_status, l.mode, l.amount,
       l.settlement_status, l.settled_amount, l.balance_updated,
       l.from_account_id, fa.account_code AS from_code,
       l.to_account_id,   ta.account_code AS to_code,
       l.transaction_date, l.created_at, l.updated_at, l.comments
FROM t_fin_ledger l
LEFT JOIN t_fin_accounts fa ON fa.id = l.from_account_id
LEFT JOIN t_fin_accounts ta ON ta.id = l.to_account_id
WHERE l.order_id = 18523
ORDER BY l.id;

-- Q3. Audit trail of the order + ledger 25151 (shows the 15:38 edits;
--     a ledger 'reversed' entry here would mean the rollback did NOT hold)
SELECT *
FROM t_sys_audit_log
WHERE (entity_type IN ('order','orders') AND entity_id = 18523)
   OR (entity_type = 'ledger' AND entity_id = 25151)
ORDER BY id;

-- Q4. Payment-method history for the order — was it ever set before today?
--     (log showed payment_method NULL at 14:31 = Khaas storage orders are
--      created with no payment method → rider delivery posts a CASH invoice)
SELECT id, order_number, payment_method, order_status, created_at
FROM t_crm_prod_order
WHERE order_number LIKE 'KS-%'
ORDER BY id DESC
LIMIT 20;

-- Q5. Blast radius: any OTHER delivered order whose payment_method says
--     ONLINE but whose live invoice is still a CASH invoice on an employee
--     cash account (same half-done state, from this or any earlier crash)
SELECT o.id, o.order_number, o.payment_method, c.customer_type,
       l.id AS ledger_id, l.mode, l.approval_status, l.settlement_status,
       ta.account_code AS held_by, l.amount, o.updated_at
FROM t_crm_prod_order o
JOIN t_fin_ledger l ON l.order_id = o.id
                   AND l.transaction_type = 'invoice'
                   AND l.approval_status <> 'reversed'
JOIN t_fin_accounts ta ON ta.id = l.to_account_id
LEFT JOIN t_crm_prod_customer c ON c.id = o.customer_id
WHERE o.order_status = 'delivered'
  AND o.payment_method IN ('online','Online','online_payment','bank_transfer','card')
  AND ta.account_category = 'employee_cash'
ORDER BY o.id DESC;

-- Q6. (follow-up) WHO set 'online_payment' on the earlier KS orders, and when?
--     Expect a manual store/rider switch before delivery each time — nothing
--     sets it automatically, so KS-36 was simply missed.
SELECT a.entity_id AS order_id, a.entity_label, a.at, a.user_id, u.fullname,
       a.source, a.changes
FROM t_sys_audit_log a
LEFT JOIN t_sys_user u ON u.id = a.user_id
WHERE a.entity_type = 'order'
  AND a.entity_label LIKE 'KS-%'
  AND a.changes LIKE '%payment_method%'
ORDER BY a.id DESC
LIMIT 30;

-- Q7. (follow-up) Khaas Warehouse's saved default payment method
SELECT id, first_name, last_name, customer_type, default_payment_method,
       default_payment_method_set_by, default_payment_method_set_at
FROM t_crm_prod_customer
WHERE id = 6419;
