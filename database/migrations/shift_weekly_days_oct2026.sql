-- =====================================================================================
--  WEEKLY DAYS on a shift  -  "different on some days"                    (Oct-13-2026)
--  Plan: ../WEEKLY-SHIFT-PATTERN-PLAN-OCT12-2026.md   (owner rulings 12-Oct)
--
--  Taimur can now say, ONCE: Haider is on Manager Shift 11:00 at the office, except Mon /
--  Wed / Thu at LaCarne 09:30 (Orchard Lacarne), until changed. The weekly days ride ON the
--  shift row they belong to, so closing / replacing / cancelling / approving / confirming a
--  shift carries them along with no new machinery.
--
--  PART 1 and PART 2 add one NULLable column each. NULL = no weekly days = exactly today.
--  Nothing reads them until the new PHP is uploaded, and the new PHP is schema-guarded
--  (it hides the weekly-days section and refuses weekly days while a column is missing).
--
--  PART 3 is a DATA CLEAN-UP the owner agreed to (12-Oct). It changes no answer the system
--  gives for any person on any date - proven on the replica by resolving every roster
--  person over 2025-10-01 .. 2026-12-31 before and after (see the deploy note).
--
--  ORDER: run PART 1 and PART 2 FIRST, then upload the PHP. PART 3 can run any time.
--  ⚠ Plain ALTER (no PREPARE) - shared StackCP hosting. A second run of PART 1 / PART 2
--    errors 1060 Duplicate column name, which is harmless and means it is already applied.
--  ⚠ ALTER TABLE is NOT rolled back by a transaction - run PART 1 / PART 2 on their own.
--  ⚠ No apostrophes and no semicolons inside any COMMENT string (statement splitter).
-- =====================================================================================


-- #####################################################################################
--  PRE-FLIGHT - read only.
-- #####################################################################################
SELECT 'pre-flight: day_pattern on assignments already there?' AS check_name, COUNT(*) AS found
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_ops_user_shift_assignment' AND COLUMN_NAME = 'day_pattern';
-- EXPECT 0. If 1, PART 1 is already applied - skip it.

SELECT 'pre-flight: day_pattern on approval requests already there?' AS check_name, COUNT(*) AS found
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_ops_shift_change_request' AND COLUMN_NAME = 'day_pattern';
-- EXPECT 0. If 1, PART 2 is already applied - skip it.


-- #####################################################################################
--  PART 1 - weekly days on the shift row
--  JSON, e.g. {"1":{"template_id":9,"location_id":8},"2":"off"}   key = ISO weekday 1..7
-- #####################################################################################
ALTER TABLE t_ops_user_shift_assignment
  ADD COLUMN day_pattern TEXT NULL DEFAULT NULL
  COMMENT 'Weekly days on this row - JSON keyed by ISO weekday. NULL = none'
  AFTER location_id;


-- #####################################################################################
--  PART 1b - what KIND of row it is, stamped by the engine at write time (13-Oct)
--  1 = temporary change (one day / date range / workshop pin) - 0 = regular shift (open, or
--  closed because a newer regular began) - NULL = written before this column existed, the
--  PHP then decides from the audit log. Every screen used to GUESS this from "has an end
--  date", which painted every day of a closed regular shift as "changed".
-- #####################################################################################
SELECT 'pre-flight: is_temporary already there?' AS check_name, COUNT(*) AS found
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_ops_user_shift_assignment' AND COLUMN_NAME = 'is_temporary';
-- EXPECT 0. If 1, skip the ALTER below.

ALTER TABLE t_ops_user_shift_assignment
  ADD COLUMN is_temporary TINYINT(1) NULL DEFAULT NULL
  COMMENT 'Oct-2026: 1 temporary change - 0 regular shift - NULL unknown (older row, decided from the audit log)'
  AFTER day_pattern;

-- Backfill what is CERTAIN. Everything else stays NULL on purpose and is decided by the PHP.
-- (a) an open row is a regular shift
UPDATE t_ops_user_shift_assignment SET is_temporary = 0 WHERE effective_to IS NULL;
-- (b) a row with no start day ("since forever") is a regular shift
UPDATE t_ops_user_shift_assignment SET is_temporary = 0 WHERE effective_from IS NULL AND is_temporary IS NULL;
-- (c) a workshop pin is a temporary change
UPDATE t_ops_user_shift_assignment SET is_temporary = 1 WHERE workshop_visit_id IS NOT NULL AND is_temporary IS NULL;
-- (d) a bounded row whose audit log shows ONLY temporary changes at its start day
UPDATE t_ops_user_shift_assignment a
   SET a.is_temporary = 1
 WHERE a.is_temporary IS NULL AND a.effective_to IS NOT NULL
   AND EXISTS (SELECT 1 FROM t_ops_shift_assignment_log l
                WHERE l.user_id = a.user_id AND l.action = 'assign' AND l.effective_from = a.effective_from
                  AND l.mode IN ('one_day','date_range'))
   AND NOT EXISTS (SELECT 1 FROM t_ops_shift_assignment_log l
                    WHERE l.user_id = a.user_id AND l.action = 'assign' AND l.effective_from = a.effective_from
                      AND l.mode = 'until_changed');
-- (e) a bounded row whose audit log shows ONLY a regular change at its start day
UPDATE t_ops_user_shift_assignment a
   SET a.is_temporary = 0
 WHERE a.is_temporary IS NULL AND a.effective_to IS NOT NULL
   AND EXISTS (SELECT 1 FROM t_ops_shift_assignment_log l
                WHERE l.user_id = a.user_id AND l.action = 'assign' AND l.effective_from = a.effective_from
                  AND l.mode = 'until_changed')
   AND NOT EXISTS (SELECT 1 FROM t_ops_shift_assignment_log l
                    WHERE l.user_id = a.user_id AND l.action = 'assign' AND l.effective_from = a.effective_from
                      AND l.mode IN ('one_day','date_range'));

SELECT 'after 1b: rows still undecided (NULL) - the PHP decides these from the log + chain' AS check_name, COUNT(*) AS found
  FROM t_ops_user_shift_assignment WHERE is_temporary IS NULL;
-- On the replica (13-Oct): 50 - older bounded rows with no audit entry at their start day (July 2026 and
-- earlier), plus any where both kinds were logged on one start day. They only affect how PAST days are drawn.


-- #####################################################################################
--  PART 2 - weekly days on a change that waits for approval (replayed verbatim on approve)
--  NULL = the screen sent none (keep current ones) - {} = remove them - JSON = these
-- #####################################################################################
ALTER TABLE t_ops_shift_change_request
  ADD COLUMN day_pattern TEXT NULL DEFAULT NULL
  COMMENT 'Weekly days asked for - NULL keep current - empty object remove them'
  AFTER location_id;


-- #####################################################################################
--  PART 3 - CLEAN-UP of junk shift rows (owner agreed 12-Oct). Changes NO answer.
--
--  3a. Rows whose end is BEFORE their start. They were left by cancelling a temporary
--      change on the day it started. They can never apply to any date. (The change log
--      t_ops_shift_assignment_log keeps the record of what happened.)
--  3b. Regular rows that were replaced but never closed (Kanan had four open at once).
--      The newest one already wins every date, so each older one is closed the day before
--      the next one starts - the same thing the engine does for every new regular shift.
-- #####################################################################################

-- PREVIEW 3a - these rows will be DELETED:
SELECT a.id, u.fullname, a.effective_from, a.effective_to, a.created_at
  FROM t_ops_user_shift_assignment a
  JOIN t_sys_user u ON u.id = a.user_id
 WHERE a.effective_from IS NOT NULL AND a.effective_to IS NOT NULL
   AND a.effective_to < a.effective_from
 ORDER BY a.id;
-- On the replica (12-Oct): 10 rows - Haider x3 (25, 28, 304), Farooq x2, Danish x3, Arslan, Kanan.

-- PREVIEW 3b - these open rows will be CLOSED (new_to = the day before the next one starts):
SELECT x.id, u.fullname, x.effective_from, DATE_SUB(MIN(y.effective_from), INTERVAL 1 DAY) AS new_to
  FROM t_ops_user_shift_assignment x
  JOIN t_ops_user_shift_assignment y
    ON y.user_id = x.user_id AND y.effective_to IS NULL AND y.id <> x.id
   AND y.effective_from IS NOT NULL
   AND (x.effective_from IS NULL OR y.effective_from > x.effective_from)
  JOIN t_sys_user u ON u.id = x.user_id
 WHERE x.effective_to IS NULL
 GROUP BY x.id, u.fullname, x.effective_from
 ORDER BY u.fullname, x.effective_from;
-- On the replica (12-Oct): 3 rows, all Kanan Anoos (225, 267, 294).

-- 3a
DELETE FROM t_ops_user_shift_assignment
 WHERE effective_from IS NOT NULL AND effective_to IS NOT NULL
   AND effective_to < effective_from;

-- 3b
UPDATE t_ops_user_shift_assignment a
  JOIN (
        SELECT x.id, DATE_SUB(MIN(y.effective_from), INTERVAL 1 DAY) AS new_to
          FROM t_ops_user_shift_assignment x
          JOIN t_ops_user_shift_assignment y
            ON y.user_id = x.user_id AND y.effective_to IS NULL AND y.id <> x.id
           AND y.effective_from IS NOT NULL
           AND (x.effective_from IS NULL OR y.effective_from > x.effective_from)
         WHERE x.effective_to IS NULL
         GROUP BY x.id
       ) s ON s.id = a.id
   SET a.effective_to = s.new_to;


-- #####################################################################################
--  POST-FLIGHT - read only.
-- #####################################################################################
SELECT 'post-flight: both columns present' AS check_name, COUNT(*) AS found
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'day_pattern'
   AND TABLE_NAME IN ('t_ops_user_shift_assignment', 't_ops_shift_change_request');
-- EXPECT 2.

SELECT 'post-flight: rows ending before they start' AS check_name, COUNT(*) AS found
  FROM t_ops_user_shift_assignment
 WHERE effective_from IS NOT NULL AND effective_to IS NOT NULL AND effective_to < effective_from;
-- EXPECT 0.

SELECT 'post-flight: people with more than one open regular row' AS check_name, COUNT(*) AS found
  FROM (SELECT user_id FROM t_ops_user_shift_assignment WHERE effective_to IS NULL
         GROUP BY user_id HAVING COUNT(*) > 1) t;
-- EXPECT 0.
