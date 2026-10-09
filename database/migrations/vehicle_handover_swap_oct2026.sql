-- 7-Oct-2026 — ONE-STEP SWAP between two company machines on a rider's handover request.
-- A rider on a company bike (Waseem, Kanan, Arslan, Farooq) asking for the VAN used to be refused:
-- the request carried one meter box, and both machines need a reading (one closing, one opening).
-- These two columns carry the SECOND reading: the machine he is stepping OFF and its closing meter.
-- NULL on every existing row and on every request that is not a company -> company swap.
-- The web code is guarded on these columns existing: uploaded before this runs, it simply keeps
-- refusing the swap exactly as before. Safe to run twice? No (plain ADD COLUMN) — run it once.
ALTER TABLE t_ops_vehicle_handover_request
    ADD COLUMN swap_from_vehicle_id INT NULL DEFAULT NULL
        COMMENT 'Take only: the company machine he hands back in the same move (t_ops_vehicle.id)'
        AFTER give_back_vehicle_id,
    ADD COLUMN swap_from_meter INT NULL DEFAULT NULL
        COMMENT 'Closing odometer of swap_from_vehicle_id, as the rider read it'
        AFTER swap_from_vehicle_id;
