-- =====================================================================
-- ✂ SPLIT INVOICE (Oct-2026)
--
-- An open order's items that can't go today are MOVED into a new order
-- numbered <root>-A (then -B, -C …). One engine: App\Services\CRM\OrderSplitService,
-- called by the web orders page AND the mobile store card.
--
-- Run BEFORE uploading the web files. Dormant-safe: the PHP checks the column
-- exists and refuses with a clear message until this has run.
-- Idempotent: safe to run twice.
-- =====================================================================


-- -----------------------------------------------------------------
-- 1. The link. Set on the NEW order only (the -A/-B row) and always points at
--    the ROOT order (NF-19945), even when the order being split was itself a
--    -A — so every part of one invoice hangs off one row.
--    ⚠ No FK on purpose: t_crm_prod_order rows are never hard-deleted by the
--    app, and an FK would make the ALTER take a long table lock on prod.
-- -----------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 't_crm_prod_order'
               AND COLUMN_NAME = 'split_from_order_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE t_crm_prod_order ADD COLUMN split_from_order_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER order_number',
    'SELECT ''split_from_order_id already exists''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 't_crm_prod_order'
               AND INDEX_NAME = 'idx_order_split_from');
SET @sql := IF(@idx = 0,
    'CREATE INDEX idx_order_split_from ON t_crm_prod_order (split_from_order_id)',
    'SELECT ''idx_order_split_from already exists''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------
-- 2. WEB permission `split_orders`.
--    Owner ruling (Oct-19-2026): Shabib (Management), Taimur (Taimur) and
--    Farooq (supervisor 2). ⚠ Role-level: everyone holding one of these roles
--    gets it. Named explicitly — there is NO role called 'admin'.
-- -----------------------------------------------------------------
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_by, created_at, updated_at)
SELECT r.id, 'split_orders', 'Split an Invoice (move items to a new -A order)', 1, 1, NOW(), NOW()
FROM t_sys_role r
WHERE LOWER(r.urole_name) IN ('management', 'taimur', 'supervisor 2')
AND NOT EXISTS (
    SELECT 1 FROM t_sys_role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_key = 'split_orders'
);


-- -----------------------------------------------------------------
-- 3. MOBILE permission `split_orders` (shows the ✂ button on the store card).
--    ⚠⚠ permission_group is REQUIRED or the row is invisible on the
--    mobile-permissions admin page.
-- -----------------------------------------------------------------
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order)
VALUES ('split_orders', 'Split an Invoice', 'store_mode_orders',
        'Can move items that cannot go today out of an open invoice into a new invoice (<number>-A).', 18)
ON DUPLICATE KEY UPDATE permission_name  = VALUES(permission_name),
                        permission_group = VALUES(permission_group),
                        description      = VALUES(description),
                        display_order    = VALUES(display_order);

INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id
FROM t_sys_role r
CROSS JOIN t_sys_mobile_permission p
WHERE p.permission_code = 'split_orders'
AND LOWER(r.urole_name) IN ('management', 'taimur', 'supervisor 2')
AND NOT EXISTS (
    SELECT 1 FROM t_sys_role_mobile_permission rp3
    WHERE rp3.role_id = r.id AND rp3.mobile_permission_id = p.id
);


-- =====================================================================
-- VERIFICATION (run after) — expect 1 column, 3 web roles, 3 mobile roles:
--   SHOW COLUMNS FROM t_crm_prod_order LIKE 'split_from_order_id';
--   SELECT r.urole_name FROM t_sys_role_permissions rp
--     JOIN t_sys_role r ON r.id = rp.role_id
--    WHERE rp.permission_key = 'split_orders' AND rp.is_allowed = 1;
--   SELECT r.urole_name FROM t_sys_role_mobile_permission rp
--     JOIN t_sys_role r ON r.id = rp.role_id
--     JOIN t_sys_mobile_permission p ON p.id = rp.mobile_permission_id
--    WHERE p.permission_code = 'split_orders';
-- =====================================================================
