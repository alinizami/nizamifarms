-- ============================================================================
-- Dispatch ORIGIN on the ETA log (2026-09-28)
-- Portable, plain DDL. Run on LOCAL and PROD (owner's manual workflow).
-- ⚠ Run it BEFORE uploading the PHP is preferred, but NOT required: every
--   writer and reader checks for these columns and falls back to today's
--   behaviour when they are missing.
--
-- WHY
-- Sep-27: Taimur cancelled and re-dispatched Asim at 18:52 while Asim was
-- 13.4 km out. The route was timed from the OFFICE, and the report could not
-- tell afterwards whether he had been out on the road or back at the office.
-- These columns record that. They are read for:
--   * the "left without dispatch" excuse: a STORE cancel made while he was
--     out (rider_distance_m > 300 on the cancel row) is the store's re-plan,
--     not his missed press;
--   * the screens: "timed from his location" / "timed from office — he was
--     13.4 km out".
-- They do NOT change the yardstick (owner ruling, Sep-28): every store
-- dispatch or cancel still resets the promise, exactly as before. The rider's
-- own earlier times are shown alongside for information only.
--
-- Columns (all NULL for every row written before this):
--   origin_source     where the times were measured FROM: 'rider_chosen',
--                     'office_chosen', or the automatic source name
--                     (rider_gps_midrun, rider_gps_anchored, store_far_gps, …)
--   rider_distance_m  the rider's OWN latest usable GPS fix, metres from the
--                     office, at the moment of the press (whatever origin was
--                     used). On cancel rows too.
--   rider_gps_age_s   how old that fix was, in seconds.
-- ============================================================================

ALTER TABLE `t_ops_eta_log`
  ADD COLUMN `origin_source`    VARCHAR(40) NULL AFTER `scope`,
  ADD COLUMN `rider_distance_m` INT         NULL AFTER `origin_source`,
  ADD COLUMN `rider_gps_age_s`  INT         NULL AFTER `rider_distance_m`;
