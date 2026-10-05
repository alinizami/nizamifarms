-- 5-Oct-2026 — the paid-salary receipt keeps how many late minutes a manager WAIVED that month.
-- `late_minutes` on the row is already NET of the waive; without this column the "paid" sheet
-- shows a reduced figure it cannot explain. Additive, default 0 → every existing row reads as
-- "nothing waived", exactly as it does today. The code is guarded on the column, so the web
-- files are safe to upload before OR after this runs.
ALTER TABLE t_hr_payroll_payment
    ADD COLUMN late_waived_minutes INT NOT NULL DEFAULT 0 AFTER late_minutes;
