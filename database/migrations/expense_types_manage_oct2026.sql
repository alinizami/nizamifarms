-- =====================================================================
-- Expense Types: rename / inactive / order / group (Oct-2026)
-- Plan: EXPENSE-TYPES-MANAGE-PLAN-OCT11-2026.md
--
-- Adds 8 columns to t_fin_config. They are only READ for rows whose
-- config_key starts with EXPENSE_CATEGORY_. Every other config row keeps
-- is_active = 1 and NULLs, so nothing else changes.
--
-- NO data is moved. account_code stays NULL until a type is renamed or
-- created: until then the posting code derives the account from the name,
-- exactly as it does today.
--
-- Safe to run twice (each column is added only when missing).
-- Plain ALTERs, no transaction (ALTER TABLE is never rolled back anyway).
-- =====================================================================

SET @t = 't_fin_config';

-- 1. is_active — 0 = inactive: no longer offered in any dropdown; history untouched
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'is_active');
SET @sql = IF(@c = 0, 'ALTER TABLE t_fin_config ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER request_category_code', 'SELECT "is_active already exists" AS Info');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. sort_order — manual order in the dropdown (NULL = alphabetical, as today)
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'sort_order');
SET @sql = IF(@c = 0, 'ALTER TABLE t_fin_config ADD COLUMN sort_order INT NULL DEFAULT NULL AFTER is_active', 'SELECT "sort_order already exists" AS Info');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. group_name — heading the type sits under in the dropdown (display only)
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'group_name');
SET @sql = IF(@c = 0, 'ALTER TABLE t_fin_config ADD COLUMN group_name VARCHAR(60) NULL DEFAULT NULL AFTER sort_order', 'SELECT "group_name already exists" AS Info');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4. account_code — the EXP_ ledger account this type posts to. Pinned on rename so a
--    renamed type keeps posting to the SAME account (without it the next posting would
--    open a brand-new EXP_<new name> account and split the P&L line).
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'account_code');
SET @sql = IF(@c = 0, 'ALTER TABLE t_fin_config ADD COLUMN account_code VARCHAR(50) NULL DEFAULT NULL AFTER group_name', 'SELECT "account_code already exists" AS Info');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 5. former_names — JSON list of names this type was renamed from / merged in from.
--    An old phone that still sends the old name is mapped to the current one.
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'former_names');
SET @sql = IF(@c = 0, 'ALTER TABLE t_fin_config ADD COLUMN former_names TEXT NULL DEFAULT NULL AFTER account_code', 'SELECT "former_names already exists" AS Info');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 6. merged_into_id — set when this type was merged into another (it is then inactive)
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'merged_into_id');
SET @sql = IF(@c = 0, 'ALTER TABLE t_fin_config ADD COLUMN merged_into_id INT NULL DEFAULT NULL AFTER former_names', 'SELECT "merged_into_id already exists" AS Info');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 7. hidden_at / hidden_by — when and by whom it was made inactive
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'hidden_at');
SET @sql = IF(@c = 0, 'ALTER TABLE t_fin_config ADD COLUMN hidden_at DATETIME NULL DEFAULT NULL AFTER merged_into_id', 'SELECT "hidden_at already exists" AS Info');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'hidden_by');
SET @sql = IF(@c = 0, 'ALTER TABLE t_fin_config ADD COLUMN hidden_by INT NULL DEFAULT NULL AFTER hidden_at', 'SELECT "hidden_by already exists" AS Info');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Check: every expense type is still active, nothing pinned yet.
SELECT COUNT(*) AS expense_types,
       SUM(is_active = 1) AS active,
       SUM(account_code IS NOT NULL) AS pinned
FROM t_fin_config
WHERE config_key LIKE 'EXPENSE\_CATEGORY\_%';
