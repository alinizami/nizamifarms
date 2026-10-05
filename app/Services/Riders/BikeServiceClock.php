<?php

namespace App\Services\Riders;

use App\Models\Request\RequestModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fleet & Fuel (Jul-2026) — the ONE place an approved bike service moves the
 * service-due clock.
 *
 * Lives here (not in RequestApprovalController) because a request can become
 * APPROVED on more than one path — the normal L1/L2 approve endpoint, the web
 * form's auto-approve-at-creation branch (a category with no approval levels),
 * and the short-cash-settlement auto-approves that call
 * RequestModel::processApproval() directly from LedgerController / the mobile
 * deposit flow. The hook is wired into processApproval() itself plus the
 * create-time auto-approve, so every current AND future path is covered without
 * each caller having to remember it.
 *
 * Deliberately silent and non-fatal — a failure here must never turn a
 * successful approval into an error for the approver. Idempotent — the stamp
 * is written once (service_due_km guard) and the clock never moves backwards,
 * so being invoked twice for the same request is a no-op.
 */
class BikeServiceClock
{
    /**
     * When a MAINTENANCE request that was an oil change / general service is
     * fully approved and carries the meter reading taken at the time, record it
     * as the bike's last service. That is what makes the Fleet tab's
     * "due in X km" chip self-maintaining.
     */
    public static function onRequestApproved(RequestModel $req): void
    {
        try {
            if ($req->status !== RequestModel::STATUS_APPROVED) return;
            if ($req->expense_category !== 'Maintenance') return;

            // ⚠⚠ THE MEMO MUST BE DROPPED ON EVERY EXIT FROM HERE, not just the one
            //   that moves the clock. An approved claim is new EVIDENCE even when it
            //   does not move the overall clock — an old back-dated one still starts
            //   its own type's countdown, and a non-clock-resetting job (brake shoe)
            //   returns early above having changed that type's schedule entirely. A
            //   flush on the happy path only would leave an approval endpoint
            //   re-rendering the state from before its own write.
            //   `finally` is the only placement that survives all seven returns.
            try {
                self::applyApproval($req);
            } finally {
                // Bump the machine's evidence version too, so cross-request caches
                // die with the memos. Resolving the machine is best-effort — a null
                // simply falls back to memo-flush + the cache TTL.
                $vid = null;
                try {
                    $vid = $req->vehicle_id
                        ?: (new \App\Services\Riders\VehicleResolver())->vehicleForDay(
                            (int) $req->requester_user_id,
                            $req->expense_date
                                ? \Carbon\Carbon::parse($req->expense_date)->format('Y-m-d')
                                : now()->format('Y-m-d')
                        );
                } catch (\Throwable $e) {
                    $vid = null;
                }
                \App\Services\Riders\VehicleService::bumpServiceEvidence($vid ? (int) $vid : null);
            }
        } catch (\Throwable $e) {
            Log::warning('Service clock reset skipped: ' . $e->getMessage(), ['request_id' => $req->id ?? null]);
        }
    }

    /** The body of onRequestApproved — see the memo note in its caller. */
    private static function applyApproval(RequestModel $req): void
    {
        try {

            // ⭐ Aug-2026: which REGULAR services actually reset the clock is now a
            // property of the chosen type, not of the bucket. The manager's list has
            // several regular types on different schedules (oil 1,200 km, brake shoe
            // 10,000 km); before this, any of them would have reset the one clock and
            // a bike with fresh brake shoes would have looked oil-serviced while its
            // oil was overdue. Untyped rows — every row before this ships — keep the
            // old answer exactly: any oil_change/general resets it.
            if (!app(\App\Services\Riders\MaintenanceTypeService::class)
                    ->resetsClock($req->maintenance_type_id ?? null, $req->service_type)) {
                return;
            }

            $meter = (int) ($req->meter_at_fill ?? 0);
            if ($meter <= 0) return;         // missing

            $profile = DB::table('t_ops_rider_profile')
                ->where('user_id', $req->requester_user_id)->first();
            if (!$profile) return;

            // ⚠ Drop the memo BEFORE reading too: anything earlier in this request
            //   may have cached this machine's evidence from before the approval.
            \App\Services\Riders\VehicleService::flushServiceMemo();

            // ⚠⚠ THE DROPPED-DIGIT GUARD, ASKED OF THE MACHINE (Aug-2026). This used
            //   to be a bare `<= 1000 return`, which is right for a 47,000 km bike and
            //   silently fatal for a new one: Farooq's approved oil change on EDN-198
            //   at 659 km reset nothing and stamped nothing, so that bike still has no
            //   service clock. `plausibleServiceMeter` is now THE one rule — shared
            //   with the per-type evidence and `lastServicePointBefore`, so all three
            //   accept or refuse a reading together. It returns exactly the old answer
            //   for any machine whose own odometer has passed 1,000 km.
            if (!self::meterIsPlausible($req, $meter)) return;

            // Freeze how far off schedule the bike was, BEFORE the clock moves.
            // Negative = overdue by that many km. Once the update below lands,
            // this can never be recomputed — the old reference point is gone —
            // so a record of a service done 317 km late would be lost forever.
            self::stampServiceDueKm($req, $profile, $meter);

            // Never move the clock backwards — an older service approved late
            // must not undo a newer one already recorded.
            if ($profile->last_service_meter !== null && (int) $profile->last_service_meter >= $meter) return;

            DB::table('t_ops_rider_profile')
                ->where('user_id', $req->requester_user_id)
                ->update([
                    'last_service_meter' => $meter,
                    'last_service_at'    => $req->expense_date
                        ? \Carbon\Carbon::parse($req->expense_date)->format('Y-m-d')
                        : now()->format('Y-m-d'),
                    'updated_at'         => now(),
                ]);

            Log::info('Service clock reset from approved maintenance request', [
                'request_id' => $req->id, 'user_id' => $req->requester_user_id, 'meter' => $meter,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Service clock reset skipped: ' . $e->getMessage(), ['request_id' => $req->id ?? null]);
        }
    }

    /**
     * Is this odometer reading usable as a service record?
     *
     * Delegates to `VehicleService::plausibleServiceMeter`, which is machine-relative:
     * anything over 1,000 km passes as it always has, and a three-figure reading
     * passes only on a machine whose own history is genuinely down there.
     *
     * ⚠ Falls back to the OLD absolute rule if the registry cannot answer, so a
     *   missing table or an unresolvable machine can never make this hook laxer than
     *   it was before — only ever exactly as strict.
     */
    private static function meterIsPlausible(RequestModel $req, int $meter): bool
    {
        try {
            $veh = new \App\Services\Riders\VehicleService();
            if (!$veh->available()) return $meter > \App\Services\Riders\VehicleService::MIN_METER;

            $date = $req->expense_date
                ? \Carbon\Carbon::parse($req->expense_date)->format('Y-m-d')
                : now()->format('Y-m-d');
            $vid = $req->vehicle_id
                ?: (new \App\Services\Riders\VehicleResolver())
                    ->vehicleForDay((int) $req->requester_user_id, $date);

            // The claim's own date anchors the verdict — a low reading is judged
            // against what the machine had read BY that day, so approving an old
            // claim late cannot change what the answer would have been.
            return $veh->plausibleServiceMeter($meter, $vid ? (int) $vid : null, $date);
        } catch (\Throwable $e) {
            return $meter > \App\Services\Riders\VehicleService::MIN_METER;
        }
    }

    /**
     * Write `service_due_km` on the request: km still to run when the service
     * was done (negative = overdue). Read against the bike's own interval when
     * it has one, otherwise the company default.
     *
     * Skipped when the bike has no previous service on record — there is no
     * reference point, and inventing one would put a made-up number on a
     * permanent record. Also non-fatal by design.
     */
    private static function stampServiceDueKm(RequestModel $req, $profile, int $meter): void
    {
        try {
            if ($req->service_due_km !== null) return;          // already frozen

            // ⭐⭐ MEASURED AGAINST THE MACHINE, NOT THE MAN (Aug-16). The reference
            //    point used to be the rider's profile stamp, which drifts the moment a
            //    bike changes hands — hand a bike over and the next service on it was
            //    judged against whatever the NEW rider's own last bike had done.
            //    `lastServicePointBefore` asks the machine's own history, and asks it
            //    strictly below this claim's odometer, so a late-filed old claim is
            //    judged against the bike as it stood at ITS meter rather than against
            //    work done since. Falls back to the profile when the registry cannot
            //    place the claim (a rider with no registered machine).
            $last = null;
            $machineAnswered = false;
            try {
                $veh = new \App\Services\Riders\VehicleService();
                if ($veh->available()) {
                    $date = $req->expense_date
                        ? \Carbon\Carbon::parse($req->expense_date)->format('Y-m-d')
                        : now()->format('Y-m-d');
                    $vid = $req->vehicle_id
                        ?: (new \App\Services\Riders\VehicleResolver())
                            ->vehicleForDay($req->requester_user_id, $date);
                    if ($vid) {
                        // ⚠ The claim's OWN type interval rides along so the reference
                        //   point is something that could have included this job — an
                        //   Oil + Tuning is measured from the last tuning-or-bigger,
                        //   never from a mere oil change (the covers rule, both ways).
                        $claimType = app(\App\Services\Riders\MaintenanceTypeService::class)
                            ->find($req->maintenance_type_id ?? null);
                        $point = $veh->lastServicePointBefore(
                            (int) $vid,
                            $meter,
                            $claimType && (int) $claimType->interval_km > 0
                                ? (int) $claimType->interval_km : null,
                            $date   // the claim's own day — see the meter-replaced note there
                        );
                        // ⚠ "No valid reference" IS an answer. When the machine's
                        //   history holds nothing that could have included this job,
                        //   the stamp must be SKIPPED (the docblock's no-made-up-
                        //   numbers rule) — falling back to the rider-profile stamp
                        //   here would count exactly the smaller-job records the
                        //   filter above just rejected, through the back door.
                        $machineAnswered = true;
                        if ($point) $last = (int) $point['meter'];
                    }
                }
            } catch (\Throwable $e) {
                $last = null;
            }
            // The profile is only consulted when the registry could not PLACE the
            // claim at all (no machine, tables missing) — the pre-registry behaviour.
            if ($last === null && !$machineAnswered) {
                $last = $profile->last_service_meter !== null ? (int) $profile->last_service_meter : null;
            }
            if ($last === null || $meter <= $last) return;

            // ⭐⭐ THE SHARED RESOLVER (Aug-27 2026) — this figure is FROZEN onto the
            //    request forever, so it above all must be the number the manager was
            //    looking at when he approved.
            //
            // ⚠⚠ WHAT WAS WRONG. The chain here was `type → RIDER profile → config` and
            //    it never read `t_ops_vehicle.service_interval_km` at all. So a bike with
            //    a machine-level schedule froze `service_due_km` against a different
            //    interval from the one on every screen and in every alert — a permanent
            //    record disagreeing with the page that produced it, with no way to tell
            //    afterwards which was meant.
            //
            // ⚠ Existing rows are NOT rewritten: they are frozen evidence of what was
            //   true at approval. Only claims approved from here on use the shared chain.
            $vehicleId = null;
            try {
                $vehicleId = $req->vehicle_id
                    ?? (new \App\Services\Riders\VehicleResolver())->vehicleForDay(
                        (int) $req->requester_user_id,
                        $req->expense_date ? substr((string) $req->expense_date, 0, 10) : date('Y-m-d')
                    );
            } catch (\Throwable $e) {
                $vehicleId = null;
            }

            $type = app(\App\Services\Riders\MaintenanceTypeService::class)
                ->find($req->maintenance_type_id ?? null);

            /**
             * ⭐⭐ CLASS-AWARE (Sep-2026). The number frozen here must be the one the
             *    manager was looking at, and on a van that is the VAN's figure. Passing
             *    the machine's class is the whole difference: before this, approving a
             *    van claim froze a bike interval onto a permanent record.
             */
            $veh   = new \App\Services\Riders\VehicleService();
            $class = $veh->classOf($vehicleId ? (int) $vehicleId : null);

            $r = (new \App\Services\Riders\ServiceIntervalResolver())->resolveFor(
                $vehicleId ? (int) $vehicleId : null,
                $class,
                $type,
                (int) $req->requester_user_id
            );

            /**
             * ⚠ A TIME-BASED job has no kilometre due-point, so nothing is frozen —
             *   `service_due_km` stays null rather than being filled with a figure in
             *   the wrong unit. The countdown for those jobs is derived from the
             *   service DATE, which is already on the row and cannot go stale.
             */
            if (($r['basis'] ?? 'km') !== 'km') return;

            $interval = (int) ($r['km'] ?? 0);
            if ($interval <= 0) return;

            DB::table('t_req_master')->where('id', $req->id)
                ->update(['service_due_km' => $interval - ($meter - $last)]);
        } catch (\Throwable $e) {
            Log::warning('service_due_km stamp skipped: ' . $e->getMessage(), ['request_id' => $req->id ?? null]);
        }
    }
}
