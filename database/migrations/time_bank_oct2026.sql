-- ============================================================================
-- TIME BANK — late + overtime as ONE balance  (October 2026)
-- Plan: TIME-BANK-LATE-OT-PLAN-OCT11-2026.md (workspace root)
--
-- A month's late minutes and overtime minutes go into one bank per employee.
-- A manager may let overtime cover late (at OT_LATE_OFFSET_FACTOR, default 0.5),
-- then whole 9-hour days are settled:
--   late  → take leave / cut salary / carry to next month
--   extra → give bonus leave / carry / skip
-- and anything under 9 hours always carries.
--
-- Two NEW tables. No existing table is altered and no data is moved:
--   * t_hr_overtime_carry stays as the record of months decided the OLD way
--     (Aug/Sep 2026); the time bank only READS it for opening balances.
--   * leave effects still land in t_hr_leave_grant (reason "Time bank <Mon YYYY>").
--
-- Every read is Schema-guarded: before this runs, payroll behaves exactly as today.
-- Run once on LOCAL, then on PROD (manual), BEFORE uploading the web files.
-- Safe to run twice.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `t_hr_time_bank_month` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`          INT NOT NULL,
  `month`            VARCHAR(7) NOT NULL COMMENT 'YYYY-MM this decision is FOR',
  `folded_months`    VARCHAR(255) NULL COMMENT 'JSON: earlier undecided months rolled into this decision',
  -- What the month was made of — FROZEN at the decision.
  `late_own`         INT NOT NULL DEFAULT 0 COMMENT 'this month late minutes (net of per-day waives)',
  `ot_own`           INT NOT NULL DEFAULT 0 COMMENT 'this month overtime minutes',
  `late_lots_in`     TEXT NULL COMMENT 'JSON lots carried in (from, minutes, label)',
  `ot_lots_in`       TEXT NULL COMMENT 'JSON lots carried in',
  `month_waive`      INT NOT NULL DEFAULT 0 COMMENT 'late minutes waived on the card',
  `waive_reason`     VARCHAR(255) NULL,
  `cover`            TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'overtime used to cover late',
  `factor`           DECIMAL(4,2) NOT NULL DEFAULT 0.50 COMMENT 'OT minute covers this many late minutes',
  `per_day_minutes`  INT NOT NULL DEFAULT 540,
  `late_cancelled`   INT NOT NULL DEFAULT 0,
  `ot_spent`         INT NOT NULL DEFAULT 0,
  -- Late whole days and where they went (leave + salary + carry = late_days).
  `late_days`        INT NOT NULL DEFAULT 0,
  `leave_days`       INT NOT NULL DEFAULT 0,
  `salary_days`      INT NOT NULL DEFAULT 0,
  `salary_amount`    DECIMAL(12,2) NOT NULL DEFAULT 0,
  `day_rate`         DECIMAL(12,2) NOT NULL DEFAULT 0,
  `salary_month`     VARCHAR(7) NULL COMMENT 'YYYY-MM whose pay carries the cut',
  `salary_paid_at`   DATETIME NULL COMMENT 'set when that pay went out',
  `carry_days`       INT NOT NULL DEFAULT 0,
  `late_lots_out`    TEXT NULL COMMENT 'JSON lots carried OUT to the next month',
  -- Overtime whole days.
  `ot_days`          INT NOT NULL DEFAULT 0,
  `ot_decision`      VARCHAR(8) NULL COMMENT 'give | carry | skip (NULL = no whole day)',
  `ot_lots_out`      TEXT NULL,
  `absence_covered`  DECIMAL(4,1) NOT NULL DEFAULT 0 COMMENT 'given days that settled parked absences',
  `outcome_hash`     VARCHAR(40) NULL COMMENT 'what the rider was told (push dedupe)',
  `decided_by`       INT NULL,
  `decided_at`       DATETIME NULL,
  `created_at`       DATETIME NULL,
  `updated_at`       DATETIME NULL,
  UNIQUE KEY `uq_tbm_user_month` (`user_id`, `month`),
  KEY `idx_tbm_salary` (`salary_month`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `t_hr_time_bank_adjust` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`           INT NOT NULL,
  `kind`              VARCHAR(4) NOT NULL COMMENT 'late | ot',
  `minutes`           INT NOT NULL COMMENT 'signed: + adds to the bank, - takes away',
  `source`            VARCHAR(10) NOT NULL COMMENT 'manual | drift | returned',
  `source_month`      VARCHAR(7) NULL COMMENT 'the month these minutes belong to',
  `reason`            VARCHAR(255) NULL,
  `absorbed_in_month` VARCHAR(7) NULL COMMENT 'NULL = still open, set when a decision takes it in',
  `created_by`        INT NULL,
  `created_at`        DATETIME NULL,
  KEY `idx_tba_open` (`user_id`, `absorbed_in_month`),
  KEY `idx_tba_drift` (`user_id`, `source`, `source_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
