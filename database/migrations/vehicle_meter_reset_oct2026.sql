-- 5-Oct-2026 — "this machine's odometer was REPLACED".
-- One row per replacement: the day it happened, what the OLD meter last read and what the NEW
-- one read when it was fitted. Every odometer rule reads a machine's history as ONE continuous
-- distance through these rows (new readings + (old_reading - new_reading)), so a new meter
-- starting at 0 is no longer "lower than this bike's 9,133" and countdowns keep counting.
-- With no row for a machine the code behaves exactly as before; the whole feature is guarded
-- on this table existing, so the web files are safe to upload before OR after this runs.
CREATE TABLE IF NOT EXISTS t_ops_vehicle_meter_reset (
    id          INT NOT NULL AUTO_INCREMENT,
    vehicle_id  INT NOT NULL,
    reset_date  DATE NOT NULL,
    old_reading INT NOT NULL,
    new_reading INT NOT NULL DEFAULT 0,
    note        VARCHAR(255) DEFAULT NULL,
    entered_by  INT DEFAULT NULL,
    created_at  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_vehicle_reset_day (vehicle_id, reset_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
