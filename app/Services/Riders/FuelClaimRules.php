<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * THE fuel/maintenance claim rules — one implementation, every creation path.
 *
 * WHY THIS EXISTS
 * There are two ways a petrol or maintenance request can be created:
 *   1. the rider's own app          → API\RiderController::createRequest
 *   2. a manager filing FOR someone → Request\RequestController::store
 *      (the web form AND the mobile Store "create request" screen both post here)
 *
 * Every guard built in Jul-2026 lived only in (1). So a manager-filed company-bike
 * petrol claim skipped the meter requirement, the odometer sanity check and the
 * double-tap block entirely — and `RequestController::store` additionally stored
 * `meter_at_fill` ONLY for Maintenance, silently discarding the reading on Petrol.
 * That is the exact leak this class closes: the rules now live in ONE place and
 * both controllers call it.
 *
 * ⭐ EVERYTHING IS KEYED TO THE **TARGET** RIDER, never the actor. When Shabib
 *    files for Danish, it is Danish's bike, Danish's odometer history and Danish's
 *    company/own status that decide — the manager's own profile is irrelevant.
 *
 * The rules are unchanged in substance; this is a move, not a redesign. The one
 * deliberate widening is that the meter is now required for MAINTENANCE on a
 * company bike too, not just petrol (owner ruling Jul-29) — a service without the
 * odometer can never reset the bike's service clock, so it was always half-useless.
 */
class FuelClaimRules
{
    /** Readings below this are dropped-digit typos — bikes here are 5-figure. */
    const MIN_PLAUSIBLE_METER = 1000;

    /** Tolerance around the floor/ceiling before a reading is called wrong. */
    const METER_SLACK_KM = 20;

    /** With no later reading to bound it, only a wild extra digit is refused. */
    const MAX_FORWARD_JUMP_KM = 2000;

    /** Identical amount inside this many seconds = a double tap, never a 2nd fill. */
    const DOUBLE_TAP_SECS = 600;

    /**
     * Validate a petrol/maintenance claim.
     *
     * @param  int         $forUserId   the rider the claim BELONGS to
     * @param  string|null $category    'Petrol' | 'Maintenance' | anything else
     * @param  array       $input       amount, expense_date, meter_at_fill, attendance_id, service_type
     * @param  int|null    $actorId     who is FILING it. Same as $forUserId = the
     *                                  rider filing for himself; different = a
     *                                  manager filing on his behalf. Null is
     *                                  treated as self-service (the safe default:
     *                                  it applies the stricter rule).
     * @return array{ok: bool, message: ?string, notice: ?string}
     *         ok=false → reject with `message` (422). `notice` is a soft flag to
     *         append to the description so the approver sees it.
     */
    public function check(int $forUserId, ?string $category, array $input, ?int $actorId = null): array
    {
        $category = trim((string) $category);
        if (!in_array($category, ['Petrol', 'Maintenance'], true)) {
            return $this->pass();          // not bike money — nothing to enforce
        }

        $meter     = $this->intOrNull($input['meter_at_fill'] ?? null);
        $claimDate = $this->dateOf($input['expense_date'] ?? null);
        // A claim tied to an attendance row is the SELF-AUDITING metered kind (an
        // own-bike rider's automatic petrol). It carries meter_start/end already,
        // so the flat-cash guards below must not touch it.
        $isMetered = !empty($input['attendance_id']);

        // ── 0. A PER-KM rider does not file his own petrol ─────────────────────
        // His petrol is paid on meter kilometres via the automatic metered claim
        // (the kind with an attendance_id, exempt below) — a hand-typed cash
        // claim on top of that is double payment.
        //
        // ⚠ "Per-km" is judged by USE OF THE METERED FLOW, not by the
        //   company_bike flag alone. `company_bike = 0` also covers people who
        //   were never on the scheme — Taimur (general petrol expenses, ~Rs 195k,
        //   zero metered claims), Farooq (no meters at all, so the manual claim
        //   is his ONLY path), office staff with no rider profile. Blocking on
        //   the flag would have cut all of them off. The double-payment risk
        //   exists precisely for riders who DO get metered claims — so that is
        //   the test.
        //
        // Deliberately narrow, so nothing else breaks:
        //   • only PETROL — own-bike maintenance is a real, separate cost
        //   • only FLAT   — the automatic metered claim must keep working
        //   • only SELF-SERVICE — a manager filing on his behalf is allowed
        //                         (that is the point of the on-behalf path)
        $isSelfService = $actorId === null || (int) $actorId === $forUserId;
        if ($category === 'Petrol' && !$isMetered && $isSelfService
            && $this->isPerKmRider($forUserId)) {
            return $this->fail(
                'Your petrol is paid on the kilometres from your meter readings, so it is '
                . 'raised for you automatically — you do not need to send a petrol request. '
                . 'If something is missing, ask your manager to add it for you.'
            );
        }

        // ── 0b. NO MACHINE THAT DAY → he cannot file his own fuel or service ────
        // Owner ruling R1 (Aug-6). A rider who has handed his bike back still had
        // an open fuel door: the profile checkbox was frozen at "company bike", so
        // nothing stopped him claiming petrol for a machine he no longer has.
        //
        // ⚠⚠ SCOPED TO RIDERS THE REGISTRY HAS ACTUALLY TRACKED, and that is not
        //    fussiness — it is the difference between closing a door and breaking
        //    someone's only path. Farooq has no registered machine and files flat
        //    petrol claims (14 of them in the last quarter); Taimur and Shabib the
        //    same for maintenance. A blanket "no vehicle = no claim" would cut all
        //    of them off overnight. `trackedByRegistry` is true only for someone
        //    the registry has actually seen holding something — so it refuses
        //    Waseem, who gave his bike back, and never speaks about Farooq.
        //
        // Narrow on purpose, same shape as the per-km rule above:
        //   • SELF-SERVICE only — a manager on-behalf is how reality gets recorded
        //   • FLAT only — the automatic metered claim is not a hand-typed request
        //   • judged on the CLAIM'S DATE, so a late claim for a day he did hold
        //     the bike still goes through
        if ($isSelfService && !$isMetered && $this->hasNoMachineOn($forUserId, $claimDate)) {
            return $this->fail(
                'You have no bike recorded for ' . $claimDate . ', so this claim cannot be '
                . 'raised from your app. If you were riding that day, ask your manager to '
                . 'record the bike for you — then file it again.'
            );
        }

        // ── 1. Meter required on a COMPANY bike ────────────────────────────────
        // Only the flat cash kind: a metered claim already has the day's readings.
        if ($meter === null && !$isMetered && $this->ridesCompanyBike($forUserId)) {
            if ($category === 'Petrol' && $this->cfg('FUEL_METER_REQUIRED', 'N') === 'Y') {
                return $this->fail('Please enter the bike\'s meter reading with this fuel request.');
            }
            if ($category === 'Maintenance'
                && in_array($input['service_type'] ?? null, ['oil_change', 'general'], true)) {
                return $this->fail('Please enter the bike\'s meter reading with this service, '
                    . 'otherwise the next service cannot be scheduled from it.');
            }
        }

        // ── 2. Odometer sanity, against the reading's OWN date ─────────────────
        /**
         * ⚠⚠ A READING INHERITED FROM AN ALREADY-RECORDED SERVICE IS NOT NEW INFORMATION
         *    (owner ask, 3-Sep — "add the bill that was handed over to them").
         *
         *    This check exists to catch a mistyped odometer AT ENTRY. When the manager attaches
         *    a bill to a service he recorded earlier, the meter is copied FROM that record — it
         *    was already entered, already accepted, and is already feeding the countdowns.
         *    Re-judging it here produced a flat contradiction: the log at 47,013 km stands, but
         *    the bill for that very service is refused as implausible. The result was a
         *    legitimate action nobody could complete.
         *
         * ⭐ So the check is skipped ONLY for an inherited reading. Every claim that ASSERTS a
         *   meter — which is every ordinary one — is judged exactly as before. If the figure is
         *   wrong the fix is to correct the service record, where it is editable and audited.
         */
        if ($meter !== null && empty($input['meter_from_service_log'])) {
            /**
             * ⭐ The MACHINE is passed when the caller knows it, so the window can also count
             *   what SERVICE RECORDS say about that bike. Without it the typo-guard measured
             *   against attendance and claims only, while the odometer itself already counted
             *   service readings — two answers to "how far has this bike gone".
             * ⚠ Only when the machine is known: a service log is rider-keyed, and counting one
             *   blind would re-create the man-not-machine leak the notes below describe.
             */
            $bad = $this->checkOdometer($forUserId, $meter, $claimDate,
                                        $this->intOrNull($input['vehicle_id'] ?? null));
            if ($bad !== null) return $this->fail($bad);
        }

        // ── 3b. METERED (per-km) petrol — the kilometres must be HIS OWN ───────
        // The mirror image of rule 3, and the one guard the metered path never had.
        if ($category === 'Petrol' && $isMetered) {
            return $this->checkMeteredPetrol(
                $forUserId, $claimDate,
                $input['meter_distance'] ?? null,
                (int) $input['attendance_id'],
                $this->intOrNull($input['vehicle_id'] ?? null),
                $this->intOrNull($input['ignore_request_id'] ?? null)
            );
        }

        // ── 3. Flat-cash petrol guards ─────────────────────────────────────────
        // `ignore_request_id` = the row being EDITED. Without it a manager saving
        // a correction to an existing claim would be told he had just filed a
        // duplicate of it — the row is its own same-amount, same-day neighbour.
        if ($category === 'Petrol' && !$isMetered) {
            return $this->checkFlatPetrol(
                $forUserId,
                (float) ($input['amount'] ?? 0),
                $claimDate,
                $this->intOrNull($input['ignore_request_id'] ?? null)
            );
        }

        return $this->pass();
    }

    /** Distances are metered integers rounded to 0.1 — anything inside this is the same number. */
    const KM_MATCH_TOLERANCE = 1.0;

    /** How far back a RIDER may raise his own metered petrol claim. */
    const PETROL_WINDOW_DEFAULT = 5;

    /** …and a MANAGER filing on someone's behalf. See petrolWindowDays(). */
    const PETROL_WINDOW_MANAGER_DEFAULT = 30;

    /**
     * ⭐⭐ HOW FAR BACK A METERED PETROL CLAIM MAY BE DATED — one definition
     *    (Aug-28 2026).
     *
     * ⚠⚠ WHY THE MANAGER GETS HIS OWN NUMBER. The 5-day window is a RIDER policy: he
     *    files his own kilometres daily, and a short window keeps his claims current.
     *    The on-behalf path exists to fix what a rider could NOT do — a missed day, a
     *    reading a manager had to enter for him — and that work is discovered late by
     *    definition. Holding it to the rider's window blocks exactly the repairs the
     *    path is for (the live case: filing 22-Aug on the 28th, six days out).
     *
     * ⚠⚠ AND IT MUST NOT BE UNBOUNDED. On this path a claim AUTO-APPROVES when the
     *    actor holds L1/L2, so "no window" would mean money out for any date in
     *    history with one click. A generous bound solves the real problem and keeps a
     *    ceiling.
     *
     * ⚠ THE ROLE BACKDATE CAP IS NOT THE ANSWER HERE, though it looks like it should
     *   be: Shabib's roles cap him at **0 days**, so falling back to it would be
     *   STRICTER than today, not looser — it would block him from filing yesterday.
     *   That is precisely why metered petrol is exempt from that cap and carries its
     *   own window instead.
     *
     * ⭐ A manager can never have LESS room than a rider, whatever the config says.
     */
    public function petrolWindowDays(bool $onBehalf = false): int
    {
        $rider = (int) $this->cfg('PETROL_WINDOW_DAYS', self::PETROL_WINDOW_DEFAULT);
        if ($rider < 1) $rider = self::PETROL_WINDOW_DEFAULT;
        if (!$onBehalf) return $rider;

        $mgr = (int) $this->cfg('PETROL_WINDOW_DAYS_MANAGER', 0);
        if ($mgr < 1) $mgr = self::PETROL_WINDOW_MANAGER_DEFAULT;
        return max($rider, $mgr);
    }

    /**
     * ⭐⭐ THE PER-KM CLAIM MUST NAME KILOMETRES THE RIDER ACTUALLY OWNS (Aug-27 2026).
     *
     * ⚠⚠ THE HOLE THIS CLOSES — it is live money, and nothing guarded it. Every
     *    company-bike rule in `check()` short-circuits on `$isMetered`, and the endpoint's
     *    own guards only covered the window, the duplicate and the split day. So on a day
     *    a rider's attendance meters held the VAN's odometer, the server happily computed
     *    the van's distance, the app rendered "167 km × Rs 9.5 = Rs 1,586.5", and one tap
     *    would have paid a per-km allowance for kilometres the firm had ALREADY fuelled
     *    with Rs 2–3,000 cash fills. Two payments, same kilometres.
     *
     * ⭐ The scheme itself is the reason: per-km exists to reimburse a man for fuel HE
     *   buys. On a company machine the company buys it, so there is nothing to reimburse.
     *
     * ⭐ AND THE SAME CHECK RESTORES WHAT WAS BROKEN. Because it judges per MACHINE rather
     *   than per DAY, a mixed day stops being refused outright: the van's kilometres are
     *   declined and his own bike's are paid — which is the whole point of the change.
     *
     * ⚠ FAIL-OPEN. When the legs builder has no opinion (no registry, no readings, an
     *   error) this passes exactly as before. It only ever refuses on positive evidence.
     *
     * @return array{ok:bool, message:?string, notice:?string, vehicle_id:?int, km:?float}
     */
    public function checkMeteredPetrol(int $forUserId, ?string $expenseDate, $km,
                                       ?int $attendanceId, ?int $vehicleId = null,
                                       ?int $ignoreRequestId = null): array
    {
        $date = $this->dateOf($expenseDate);
        $km   = ($km === null || $km === '') ? null : (float) $km;

        // ⚠ THE DUPLICATE CHECK IS NOT PART OF THE NEW RULE and must survive every one of
        //   the fall-throughs below — it is the long-standing "one petrol claim per day"
        //   guard, simply made machine-aware. Losing it behind the new logic would open a
        //   far worse hole than the one being closed.
        $dupe = fn (?int $vid, ?string $label) => $this->meteredDuplicate(
            $forUserId, $attendanceId, $vid, $ignoreRequestId, $label, $date
        );

        // Rollback lever, same shape as its siblings: an absent row means ON.
        if (strtoupper((string) $this->cfg('METERED_COMPANY_GUARD', 'Y')) !== 'Y') {
            if ($msg = $dupe($vehicleId, null)) return $this->failWith($msg);
            return $this->passWith($vehicleId, $km);
        }

        try {
            $legs = (new RiderDayLegs())->forDay($forUserId, $date);
        } catch (\Throwable $e) {
            $legs = [];
        }
        if (!$legs) {                                          // no opinion → as before
            if ($msg = $dupe($vehicleId, null)) return $this->failWith($msg);
            return $this->passWith($vehicleId, $km);
        }

        // ── which machine is this claim for? ───────────────────────────────────
        $leg = null;
        if ($vehicleId) {
            $leg = RiderDayLegs::forVehicle($legs, $vehicleId);
            if (!$leg) {
                return $this->failWith('There are no meter readings for that vehicle on '
                    . $date . ', so there is nothing to claim against it.');
            }
        } else {
            $claimable = RiderDayLegs::claimable($legs);
            if (count($claimable) === 1) {
                $leg = $claimable[0];
            } elseif (count($claimable) > 1) {
                // More than one own machine that day — the amount decides, or the
                // caller must say which. Never guess when money is involved.
                $matches = $km === null ? [] : array_values(array_filter(
                    $claimable,
                    fn ($l) => abs((float) $l['km'] - $km) <= self::KM_MATCH_TOLERANCE
                ));
                if (count($matches) !== 1) {
                    return $this->failWith('You rode more than one of your own vehicles that '
                        . 'day. Please choose which one this petrol claim is for.');
                }
                $leg = $matches[0];
            } else {
                // Nothing claimable. If the day's kilometres are the company's, say so —
                // that is the double-payment case and the rider deserves the reason.
                if (RiderDayLegs::hasCompany($legs)) {
                    return $this->failWith($this->companyKmMessage($legs));
                }
                return $this->failWith('No distance is recorded on your own vehicle for '
                    . $date . ', so there is nothing to claim. If you did ride it, add that '
                    . 'day\'s meter readings first.');
            }
        }

        if (!empty($leg['is_company'])) {
            return $this->failWith($this->companyKmMessage($legs, $leg));
        }
        if (($leg['km'] ?? 0) <= 0) {
            return $this->failWith('No distance is recorded on ' . $leg['label'] . ' for '
                . $date . ', so there is nothing to claim for it.');
        }

        // ── the amount must be the kilometres actually recorded ────────────────
        // A phone holding yesterday's screen can still post the old figure; the server,
        // not the screen, is the guarantee (the same reasoning as the split-day guard
        // this replaces).
        if ($km !== null && abs((float) $leg['km'] - $km) > self::KM_MATCH_TOLERANCE) {
            return $this->failWith('That day now reads ' . rtrim(rtrim(number_format((float) $leg['km'], 1, '.', ''), '0'), '.')
                . ' km on ' . $leg['label'] . ', not ' . rtrim(rtrim(number_format($km, 1, '.', ''), '0'), '.')
                . ' km. Please refresh the screen and send it again.');
        }

        if ($msg = $dupe((int) $leg['vehicle_id'], $leg['label'])) return $this->failWith($msg);

        return $this->passWith((int) $leg['vehicle_id'], (float) $leg['km']);
    }

    /**
     * One metered petrol claim per rider per attendance day PER MACHINE.
     *
     * ⚠ An UNSTAMPED existing claim blocks every machine, deliberately: it is a claim for
     *   that day whose machine nobody recorded, so a second one beside it could be paying
     *   the same kilometres twice. Conservative by design, and it costs nothing going
     *   forward — every claim written from here on carries its machine.
     *
     * @param  ?int $vehicleId  null = "any machine", the pre-registry behaviour
     * @return ?string  the refusal message, or null when there is no duplicate
     */
    private function meteredDuplicate(int $forUserId, ?int $attendanceId, ?int $vehicleId,
                                      ?int $ignoreRequestId, ?string $label, string $date): ?string
    {
        if (!$attendanceId) return null;
        try {
            $hit = DB::table('t_req_master')
                ->where('requester_user_id', $forUserId)
                ->where('expense_category', 'Petrol')
                ->where('attendance_id', $attendanceId)
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->when($ignoreRequestId, fn ($q) => $q->where('id', '!=', $ignoreRequestId))
                ->when($vehicleId && \Illuminate\Support\Facades\Schema::hasColumn('t_req_master', 'vehicle_id'),
                    fn ($q) => $q->where(function ($w) use ($vehicleId) {
                        $w->whereNull('vehicle_id')->orWhere('vehicle_id', (int) $vehicleId);
                    }))
                ->exists();
            if (!$hit) return null;
            return $label
                ? ('A petrol request has already been submitted for ' . $label . ' on ' . $date . '.')
                : 'A petrol request has already been submitted for this day.';
        } catch (\Throwable $e) {
            return null;                       // never block a claim on a read failure
        }
    }

    /** Name the machine and the reason, so nobody has to guess why a claim was refused. */
    private function companyKmMessage(array $legs, ?array $leg = null): string
    {
        $company = $leg ?: null;
        if (!$company) {
            foreach ($legs as $l) { if (!empty($l['is_company'])) { $company = $l; break; } }
        }
        $name = $company['label'] ?? 'that vehicle';
        return 'Those kilometres are on ' . $name . ', and the company buys its fuel — so '
            . 'there is no per-kilometre claim for them. Only your own vehicle\'s kilometres '
            . 'can be claimed here.';
    }

    private function passWith(?int $vehicleId, ?float $km): array
    {
        return ['ok' => true, 'message' => null, 'notice' => null,
                'vehicle_id' => $vehicleId, 'km' => $km];
    }

    private function failWith(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'notice' => null,
                'vehicle_id' => null, 'km' => null];
    }

    /**
     * The reading must sit between what the bike had already covered BEFORE that
     * day and what it read AFTER it. Compared to the claim's own date, not to
     * "latest known" — a service filed for yesterday is legitimately lower than
     * today's odometer, and comparing to the latest rejected exactly those.
     */
    private function checkOdometer(int $userId, int $meter, string $date, ?int $vehicleId = null,
                                   $ignoreServiceLogId = null): ?string
    {
        $win = $this->odometerWindow($userId, $date, $vehicleId, $ignoreServiceLogId);

        /**
         * ⭐⭐ METER REPLACED (5-Oct-2026). `$win` is in the figures of the meter fitted that day
         *    (what the messages print). The COMPARISON is made on the machine's continuous
         *    scale — the typed reading and both bounds — so a new meter's 147 is not "lower
         *    than this bike's 9,133", and on the replacement day itself a reading off either
         *    meter is judged as what it is. `$cm`/`$cf`/`$cc` equal the plain figures for a
         *    machine never replaced, so every line below is unchanged for it.
         */
        $cm = $meter; $cf = $win['floor']; $cc = $win['ceil'];
        try {
            $res = new VehicleResolver();
            $rv  = $res->rulesEnabled() ? ($vehicleId ?: $res->vehicleForDay($userId, $date)) : null;
            if ($rv && MeterReplacement::has((int) $rv)) {
                $cw = (new VehicleService())->meterWindowContinuousFor((int) $rv, $date, $ignoreServiceLogId);
                if ($cw !== null) {
                    $cm = (int) MeterReplacement::toContinuous((int) $rv, $meter, $date);
                    $cf = $cw['floor']; $cc = $cw['ceil'];
                    // Show each bound as the meter it was read on showed it.
                    $win = ['floor' => $cf !== null ? MeterReplacement::toRaw((int) $rv, (int) $cf, $date) : null,
                            'ceil'  => $cc !== null ? MeterReplacement::toRaw((int) $rv, (int) $cc, $date) : null];
                }
            }
        } catch (\Throwable $e) { /* the plain comparison stands */ }

        // ⭐⭐ NAME THE RECORD THAT SET THE BOUND (Sep-20 2026). Kanan's honest 52,702 on
        //    18-Sep was refused against "52,766 recorded before 2026-09-18", and nobody could
        //    find which record said that — it was a service log typed on the 19th and dated
        //    the 15th. A bound with no pointer is a dead end, and the docblock below already
        //    knows what a dead end gets: a fake higher reading. Fails soft: no pointer, no
        //    suffix, the message stands as it always did.
        $whence = fn (string $side) => $this->describeReading(
            $userId, $vehicleId, (int) $win[$side === 'floor' ? 'floor' : 'ceil'],
            $side, $date, $ignoreServiceLogId
        );

        if ($cf !== null && $cm < $cf - self::METER_SLACK_KM) {
            // ⭐ Teach the remedy, don't just refuse. The most common legitimate hit
            // (owner, Aug-3): a rider fills MID-SHIFT and hands over the receipt
            // after his day has closed — so the claim is filed the NEXT day, the
            // form defaults to today, and the reading is "below" yesterday's close.
            // Dated to the day of the fill it passes, because the window brackets
            // by the claim's own date. Without this sentence the block reads as
            // "the system won't let me", and it gets worked around with a fake
            // higher reading — which poisons the very history the check reads.
            return 'That reading (' . number_format($meter) . ' km) is lower than this bike\'s '
                . number_format($win['floor']) . ' km recorded before ' . $date . '. '
                . 'If the fill or service actually happened on an earlier day, change the '
                . 'request\'s date to that day — the reading is checked against the date it is filed for. '
                . 'Otherwise please check the number.'
                . $whence('floor');
        }
        if ($cc !== null && $cm > $cc + self::METER_SLACK_KM) {
            return 'That reading (' . number_format($meter) . ' km) is higher than this bike\'s '
                . number_format($win['ceil']) . ' km recorded after ' . $date . '. Please check the number.'
                . $whence('ceil');
        }
        if ($cc === null && $cf !== null
            && $cm > $cf + self::MAX_FORWARD_JUMP_KM) {
            return 'That reading (' . number_format($meter) . ' km) is far above this bike\'s last '
                . number_format($win['floor']) . ' km. Please check the number.'
                . $whence('floor');
        }
        return null;
    }

    /**
     * ⭐ THE SAME ODOMETER RULE, FOR A SERVICE RECORD (Sep-20 2026).
     *
     * `checkOdometer` judged every petrol and maintenance CLAIM, on every surface — but the
     * "Record service" door and its Edit door judged only magnitude (`readingPlausibleFor`,
     * "could this be this bike's odometer at all"). So a service typed on 19-Sep, dated
     * 15-Sep, carrying the 19th's 52,766 sailed in, and then bounded every honest claim from
     * the 16th onward. One rule, one definition: the service door now asks this.
     *
     * @param  int|int[]|null $ignoreServiceLogId  the row being EDITED — it must not bound itself
     * @return ?string  the refusal, or null when the reading sits inside the window
     */
    public function odometerObjection(int $userId, int $meter, string $date, ?int $vehicleId = null,
                                      $ignoreServiceLogId = null): ?string
    {
        return $this->checkOdometer($userId, $meter, $this->dateOf($date), $vehicleId, $ignoreServiceLogId);
    }

    /**
     * Which record carries the reading that set the floor / ceiling — so the refusal can
     * point at it instead of quoting a number nobody can locate.
     *
     * Looked up by EXACT value on the same sources `odometerWindow` / `meterWindowFor` read,
     * on the same side of the same date. Ordered by how likely each is to be the typo the
     * manager is hunting: a service record (typed by hand, editable on the Bikes page), a
     * claim (editable there too), the vehicle meter log, an attendance meter, a handover
     * reading. Only ever called while a refusal is being built, so its queries cost nothing
     * on the happy path.
     *
     * ⚠ FAILS SOFT, always. A missing table, an absent column, a value that matches nothing
     *   (the window applies slack and drops implausible candidates, so the bound may not be
     *   a raw row value) — every one of those returns '' and the message stands as before.
     *
     * @param  string $side  'floor' (rows dated BEFORE $date) or 'ceil' (AFTER)
     */
    private function describeReading(int $userId, ?int $vehicleId, int $value, string $side,
                                     string $date, $ignoreServiceLogId = null): string
    {
        if ($value <= 0) return '';
        $op = $side === 'floor' ? '<' : '>';
        try {
            // The machine the window was built for — the same resolution `odometerWindow` uses.
            if ($vehicleId === null) {
                try {
                    $res = new VehicleResolver();
                    if ($res->rulesEnabled()) $vehicleId = $res->vehicleForDay($userId, $date) ?: null;
                } catch (\Throwable $e) { /* rider-keyed lookup below */ }
            }
            $forMachine = function ($q, string $userCol = 'user_id', string $vehCol = 'vehicle_id')
                          use ($userId, $vehicleId) {
                return $q->where(function ($w) use ($userId, $vehicleId, $userCol, $vehCol) {
                    $w->where($userCol, $userId);
                    if ($vehicleId) $w->orWhere($vehCol, $vehicleId);
                });
            };
            $schema = \Illuminate\Support\Facades\Schema::class;
            $hasTbl = fn (string $t) => $schema::hasTable($t);
            $hasCol = fn (string $t, string $c) => $schema::hasColumn($t, $c);
            $fix = ' Edit or remove it on the Bikes page if that record is wrong.';

            // 1. A service record — the one source that was never judged on entry until now.
            if ($hasTbl('t_fleet_service_log')) {
                $q = DB::table('t_fleet_service_log as l')
                    ->leftJoin('t_fleet_maintenance_types as t', 't.id', '=', 'l.maintenance_type_id')
                    ->where('l.meter', $value)
                    ->where('l.service_date', $op, $date)
                    ->when(ServiceRecordService::normaliseIds($ignoreServiceLogId), fn ($q, $ids) => $q->whereNotIn('l.id', $ids));
                $q = $hasCol('t_fleet_service_log', 'vehicle_id')
                    ? $forMachine($q, 'l.user_id', 'l.vehicle_id')
                    : $q->where('l.user_id', $userId);
                $r = $q->orderByDesc('l.id')->first(['l.id', 'l.service_date', 't.type_name']);
                if ($r) {
                    return ' It comes from service record #' . $r->id
                        . ($r->type_name ? ' (' . $r->type_name . ')' : '')
                        . ' dated ' . substr((string) $r->service_date, 0, 10) . '.' . $fix;
                }
            }

            // 2. A claim carrying an odometer.
            $q = DB::table('t_req_master')
                ->where('meter_at_fill', $value)
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->whereRaw('COALESCE(expense_date, DATE(created_at)) ' . $op . ' ?', [$date]);
            $q = $hasCol('t_req_master', 'vehicle_id')
                ? $forMachine($q, 'requester_user_id', 'vehicle_id')
                : $q->where('requester_user_id', $userId);
            $r = $q->orderByDesc('id')->first(['id', 'request_number', 'expense_category', 'expense_date', 'created_at']);
            if ($r) {
                return ' It comes from claim ' . ($r->request_number ?: ('#' . $r->id))
                    . ($r->expense_category ? ' (' . $r->expense_category . ')' : '')
                    . ' dated ' . substr((string) ($r->expense_date ?: $r->created_at), 0, 10) . '.' . $fix;
            }

            // 3. The vehicle meter log (typed on the Vehicles page or the rider's My Vehicle).
            if ($vehicleId && $hasTbl(VehicleService::T_METER_LOG)) {
                $r = DB::table(VehicleService::T_METER_LOG)
                    ->where('vehicle_id', $vehicleId)
                    ->where('log_date', $op, $date)
                    ->where(fn ($w) => $w->where('meter_start', $value)->orWhere('meter_end', $value))
                    ->orderByDesc('id')->first(['log_date']);
                if ($r) {
                    return ' It comes from the vehicle meter log for '
                        . substr((string) $r->log_date, 0, 10) . '.' . $fix;
                }
            }

            // 4. An attendance meter — his own row, or one pointed at this machine.
            $q = DB::table('t_ops_attendance')
                ->where('attendance_date', $op, $date)
                ->where(fn ($w) => $w->where('meter_start', $value)
                                     ->orWhere('meter_end', $value)
                                     ->orWhere('meter_home', $value));
            $stamps = $vehicleId && $hasCol('t_ops_attendance', 'meter_start_vehicle_id');
            $q->where(function ($w) use ($userId, $vehicleId, $stamps, $hasCol) {
                $w->where('user_id', $userId);
                if ($vehicleId && $hasCol('t_ops_attendance', 'vehicle_id')) $w->orWhere('vehicle_id', $vehicleId);
                if ($stamps) {
                    $w->orWhere('meter_start_vehicle_id', $vehicleId)
                      ->orWhere('meter_end_vehicle_id', $vehicleId)
                      ->orWhere('meter_home_vehicle_id', $vehicleId);
                }
            });
            $r = $q->orderByDesc('attendance_date')
                   ->first(['user_id', 'attendance_date', 'meter_start', 'meter_end', 'meter_home']);
            if ($r) {
                $which = (int) $r->meter_start === $value ? 'start'
                       : ((int) $r->meter_end === $value ? 'closing' : 'home');
                $whose = (int) $r->user_id === $userId ? 'his' : 'another rider\'s';
                return ' It comes from ' . $whose . ' attendance ' . $which . ' meter on '
                    . substr((string) $r->attendance_date, 0, 10)
                    . '. Correct that day\'s meter on the Attendance page if it is wrong.';
            }

            // 5. The odometer written down when the machine changed hands.
            if ($vehicleId && $hasCol(VehicleService::T_ASSIGN, 'handover_meter')) {
                $r = DB::table(VehicleService::T_ASSIGN)
                    ->where('vehicle_id', $vehicleId)
                    ->where('handover_meter', $value)
                    ->where('assigned_on', $op, $date)
                    ->orderByDesc('id')->first(['assigned_on']);
                if ($r) {
                    return ' It comes from the handover reading of '
                        . substr((string) $r->assigned_on, 0, 10) . '.';
                }
            }
        } catch (\Throwable $e) {
            // a pointer is a courtesy; the refusal itself never depends on it
        }
        return '';
    }

    /**
     * (a) HARD BLOCK the same amount minutes apart — always a double tap.
     * (b) SOFT FLAG a cash claim on a day the meter already paid for, or simply
     *     the 2nd cash claim of the day. Allowed; the approver is told.
     */
    private function checkFlatPetrol(int $userId, float $amount, string $claimDate, ?int $ignoreRequestId = null): array
    {
        try {
            $sameDay = DB::table('t_req_master')
                ->where('requester_user_id', $userId)
                ->where('expense_category', 'Petrol')
                ->whereRaw('COALESCE(expense_date, DATE(created_at)) = ?', [$claimDate])
                ->whereNotIn('status', ['cancelled', 'rejected'])
                // The row being edited is not a duplicate of itself.
                ->when($ignoreRequestId, fn ($q) => $q->where('id', '!=', $ignoreRequestId))
                // ⚠ `vehicle_id` is selected DEFENSIVELY — the notice below names the
                //   machine, and reading an unselected column is an undefined-property
                //   warning, not a null. Absent before batch 13, hence the guard.
                ->get(array_merge(['id', 'amount', 'attendance_id', 'created_at'],
                    \Illuminate\Support\Facades\Schema::hasColumn('t_req_master', 'vehicle_id')
                        ? ['vehicle_id'] : []));
        } catch (\Throwable $e) {
            return $this->pass();          // never block a claim on a read failure
        }

        foreach ($sameDay as $prev) {
            if ($prev->attendance_id !== null) continue;
            if (abs((float) $prev->amount - $amount) < 0.01
                && abs(time() - strtotime($prev->created_at)) <= self::DOUBLE_TAP_SECS) {
                return $this->fail('You already submitted Rs ' . number_format($amount)
                    . ' for petrol a moment ago. Check "My Requests" before sending it again.');
            }
        }

        $meteredToday = $sameDay->first(fn ($x) => $x->attendance_id !== null);
        if ($meteredToday) {
            // ⚠⚠ THIS NOTICE MUST NOT IMPLY A DUPLICATE ON A MIXED DAY (Aug-27 2026).
            //
            // It used to read "a day already paid by meter reading", which was true when a
            // rider had one machine. Now that his own bike's kilometres are paid per-km
            // WHILE the company van's fuel is bought with cash, the two claims coincide by
            // design and are different money. An approver reading "already paid" would
            // reject a legitimate van fill. So name the machine the meter claim was for
            // and let him check, rather than asserting a duplicate that is not one.
            $label = null;
            try {
                if (!empty($meteredToday->vehicle_id ?? null)) {
                    $label = (new VehicleResolver())->labelFor((int) $meteredToday->vehicle_id);
                }
            } catch (\Throwable $e) { $label = null; }

            return $this->pass($label
                ? ('There is also a per-kilometre claim for ' . $label . ' that day (request #'
                   . $meteredToday->id . ') — check this cash claim is for a different vehicle.')
                : ('Cash claim on a day that also has a per-kilometre claim (request #'
                   . $meteredToday->id . ').'));
        }
        if ($sameDay->count() > 0) {
            return $this->pass('This is petrol cash claim #' . ($sameDay->count() + 1) . ' for ' . $claimDate . '.');
        }
        return $this->pass();
    }

    /**
     * floor = highest plausible reading known BEFORE $date
     * ceil  = lowest  plausible reading known AFTER  $date
     *
     * ⚠ Only PLAUSIBLE rows. The data contains typo'd meters (one row jumps
     * 26,261 → 56,403 in a day). A raw MAX lets one such row become the floor
     * forever, after which the rider can never file a correct reading again.
     */
    public function odometerWindow(int $userId, string $date, ?int $vehicleId = null,
                                   $ignoreServiceLogId = null): array
    {
        // ⭐ PHASE C: the window belongs to the MACHINE he held on that date, not
        //    to the man. Danish's first fill on DCR-799 (~24,800) must be judged
        //    against DCR-799's series — his own bike's 33,700 would wrongly
        //    refuse the right reading AND wrongly accept the old bike's. Gated
        //    like everything else; any failure falls through to the rider-keyed
        //    window below, so the check itself is never lost.
        try {
            $res = new VehicleResolver();
            if ($res->rulesEnabled()) {
                /**
                 * ⚠⚠ THE MACHINE THE CALLER NAMED WINS (29-Sep-2026, found on the device).
                 *    Every service door passes the bike explicitly — the workshop visit, the
                 *    vehicle card — precisely because "what was this man on that day" is the
                 *    WRONG answer when the bike is in the workshop and he is on a spare (the
                 *    Sep-10 "records follow the vehicle" rule). This window ignored it and asked
                 *    the registry anyway, so a service on the bike that went in was judged
                 *    against the spare's readings — or, for a keeper with no assignment, against
                 *    his own attendance history ("far above this bike's last 1,663 km" for a
                 *    machine at 29,700). `describeReading` already preferred the named machine;
                 *    the window now agrees with its own message.
                 */
                $vid = $vehicleId ?: $res->vehicleForDay($userId, $date);
                if ($vid) {
                    $win = (new VehicleService())->meterWindowFor($vid, $date, $ignoreServiceLogId);
                    if ($win !== null) return $win;
                }
            }
        } catch (\Throwable $e) { /* fall back to the rider-keyed window */ }

        try {
            // ⭐⭐ ONE READING RULE, ONE DEFINITION (Aug-22 2026). This block used to carry a
            //    VERBATIM COPY of VehicleService's old predicate — including its defect, that
            //    `meter_start` gatekeeps `meter_end`/`meter_home`, so a handover evening was
            //    thrown away whole. Left as a copy, a rider the registry CAN answer for would be
            //    judged by one rule and a rider it CANNOT by another, and only one of them would
            //    ever get fixed. Both now call the same three helpers.
            //
            // ⚠ MIN_PLAUSIBLE_METER has not been lost: it lives inside `readingLowExprSql()`,
            //   applied to the CANDIDATE rather than the row — see that method for why the
            //   distinction matters on the ceil side.
            $sane     = \App\Services\Riders\VehicleService::readingRowFilterSql();
            $highExpr = \App\Services\Riders\VehicleService::readingHighExprSql();
            $lowExpr  = \App\Services\Riders\VehicleService::readingLowExprSql();

            $attBefore = DB::table('t_ops_attendance')
                ->where('user_id', $userId)
                ->where('attendance_date', '<', $date)
                ->whereRaw($sane)
                ->selectRaw('MAX(' . $highExpr . ') AS m')
                ->value('m');

            $fillBefore = DB::table('t_req_master')
                ->where('requester_user_id', $userId)
                // ⚠ UNSTAMPED only (Aug-22 2026) — a claim stamped to a machine belongs to THAT
                //   machine's window. Counting it in the rider-keyed fallback re-creates the
                //   man-not-machine bug this method's own docblock describes: Rajab's van cash
                //   claims (stamped v4, filed in his name) would set HIS floor at 73,872 and
                //   refuse his own bike's honest 6,6xx. Same fix as computeCurrentMeter.
                ->when(\Illuminate\Support\Facades\Schema::hasColumn('t_req_master', 'vehicle_id'),
                    fn ($q) => $q->whereNull('vehicle_id'))
                ->whereNotNull('meter_at_fill')
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->whereRaw('COALESCE(expense_date, DATE(created_at)) < ?', [$date])
                ->max('meter_at_fill');

            /**
             * ⭐ WHAT THE SERVICE RECORDS SAY (3-Sep). The odometer itself already counts
             *   service readings; this typo-guard did not — so a bike whose only recent
             *   reading came from a recorded service was judged against a stale floor, and an
             *   honest claim could be refused as "far above this bike's last km". Same date
             *   anchor as everything else here: strictly BEFORE the claim's own date.
             *
             * ⚠⚠ ONLY when the machine is known, and only rows that resolve to THAT machine.
             *    A service log is rider-keyed, so counting one blind would let a van reading
             *    set the floor for the man's own bike — precisely the man-not-machine leak the
             *    $fillBefore note above exists to prevent.
             * ⚠ Fails OPEN: a guard must never be the reason a real claim cannot be filed.
             */
            $svcBefore = 0;
            if ($vehicleId) {
                try {
                    $res = new VehicleResolver();
                    foreach (DB::table('t_fleet_service_log')
                                ->where('user_id', $userId)
                                ->whereNotNull('meter')
                                ->where('meter', '>', self::MIN_PLAUSIBLE_METER)
                                ->whereDate('service_date', '<', $date)
                                // ⭐ the row being corrected must not bound itself
                                ->when(ServiceRecordService::normaliseIds($ignoreServiceLogId), fn ($q, $ids) => $q->whereNotIn('id', $ids))
                                ->orderByDesc('meter')->limit(40)
                                ->get(['meter', 'service_date']) as $sl) {
                        $d = substr((string) $sl->service_date, 0, 10);
                        if ((int) $res->vehicleForDay($userId, $d) !== (int) $vehicleId) continue;
                        $svcBefore = (int) $sl->meter;
                        break;   // ordered desc — the first match on this machine is its highest
                    }
                } catch (\Throwable $e) {
                    $svcBefore = 0;
                }
            }
            $floor = max((int) $attBefore, (int) $fillBefore, $svcBefore);
            $floor = $floor > self::MIN_PLAUSIBLE_METER ? $floor : null;

            // Sub-1000 junk is excluded INSIDE the query — taking MIN() first would
            // let one junk row become the ceiling and silently disable the check.
            $attAfter = DB::table('t_ops_attendance')
                ->where('user_id', $userId)
                ->where('attendance_date', '>', $date)
                ->whereRaw($sane)
                ->selectRaw('MIN(' . $lowExpr . ') AS m')
                ->value('m');

            $fillAfter = DB::table('t_req_master')
                ->where('requester_user_id', $userId)
                // ⚠ UNSTAMPED only — see $fillBefore. On the CEIL side the stamped-claim leak is
                //   worse: the van's 73,8xx as a MIN ceiling would refuse every honest own-bike
                //   reading above it, with no honest way past.
                ->when(\Illuminate\Support\Facades\Schema::hasColumn('t_req_master', 'vehicle_id'),
                    fn ($q) => $q->whereNull('vehicle_id'))
                ->whereNotNull('meter_at_fill')
                ->where('meter_at_fill', '>', self::MIN_PLAUSIBLE_METER)
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->whereRaw('COALESCE(expense_date, DATE(created_at)) > ?', [$date])
                ->min('meter_at_fill');

            $candidates = array_filter([
                (int) $attAfter  > self::MIN_PLAUSIBLE_METER ? (int) $attAfter  : null,
                (int) $fillAfter > self::MIN_PLAUSIBLE_METER ? (int) $fillAfter : null,
            ], fn ($v) => $v !== null);

            return ['floor' => $floor, 'ceil' => $candidates ? min($candidates) : null];
        } catch (\Throwable $e) {
            return ['floor' => null, 'ceil' => null];
        }
    }

    /** Metered petrol inside this window = the rider is living on the per-km scheme. */
    const PER_KM_LOOKBACK_DAYS = 60;

    /**
     * On the per-km scheme: NOT a company bike, and has had at least one metered
     * petrol claim recently. Judged by behaviour rather than a profile flag —
     * see the note in check(). Self-correcting in both directions: a rider who
     * stops using the metered flow ages out; one who starts is covered at once.
     */
    public function isPerKmRider(int $userId): bool
    {
        try {
            if ($this->ridesCompanyBike($userId)) return false;
            return DB::table('t_req_master')
                ->where('requester_user_id', $userId)
                ->where('expense_category', 'Petrol')
                ->whereNotNull('attendance_id')
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->whereRaw('COALESCE(expense_date, DATE(created_at)) >= DATE_SUB(CURDATE(), INTERVAL '
                    . self::PER_KM_LOOKBACK_DAYS . ' DAY)')
                ->exists();
        } catch (\Throwable $e) {
            return false;              // never block anyone on a read failure
        }
    }

    /** For the app: may this user file a petrol claim for himself? */
    public function canSelfFilePetrol(int $userId): bool
    {
        return !$this->isPerKmRider($userId)
            && !$this->hasNoMachineOn($userId, Carbon::today()->format('Y-m-d'));
    }

    /**
     * Does the registry positively say this rider held NOTHING on that date?
     *
     * Three things must all be true, and each one is load-bearing:
     *   • the switch is on and the tables exist — otherwise the registry has no
     *     standing to refuse anything;
     *   • the registry has TRACKED him (some assignment, ever) — so it never
     *     speaks about people it has never known, like Farooq;
     *   • it resolves no machine for that date.
     *
     * FAIL-OPEN: any error answers "no" (i.e. do not block). A read failure must
     * never turn into a refused claim.
     */
    public function hasNoMachineOn(int $userId, string $date): bool
    {
        try {
            $res = new VehicleResolver();
            if (!$res->available() || !$res->rulesEnabled()) return false;
            if (!$res->trackedByRegistry($userId))            return false;
            return $res->vehicleForDay($userId, substr($date, 0, 10)) === null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Is this rider on a company-owned bike (the company buys the actual fuel)?
     *
     * ⭐ PHASE C: THE ASSIGNMENT DECIDES, NOT A CHECKBOX (owner ruling Aug-4 —
     *    "company bike and bike assignment should be linked, otherwise it doesn't
     *    make sense"). Answered per DATE, because a rider can hold a company
     *    machine for a week and his own the rest of the month, and the fuel
     *    treatment must follow the machine he actually had that day.
     *
     * ⚠ Gated inside `isCompanyDay`: while `VEHICLE_RULES` is off — and for any
     *   rider holding no registered vehicle — this returns the profile checkbox
     *   exactly as it always did. Nobody outside the registry changes behaviour.
     *
     * ⚠ Callers that pass no date get TODAY. Anything judging a past day (month
     *   reports, attendance rows) MUST pass that day's date or it will grade
     *   history by today's arrangement.
     */
    public function ridesCompanyBike(int $userId, ?string $date = null): bool
    {
        try {
            return (new VehicleResolver())->isCompanyDay(
                $userId, $date ?: Carbon::today()->format('Y-m-d')
            );
        } catch (\Throwable $e) {
            return $this->profileCompanyBikeFlag($userId);
        }
    }

    /**
     * The raw profile checkbox — the FALLBACK the registry falls back TO.
     *
     * ⚠⚠ `VehicleResolver::isCompanyDay` calls THIS, never `ridesCompanyBike`.
     *    Calling the other way round would recurse forever now that
     *    `ridesCompanyBike` asks the resolver. Keep this one flag-free and dumb.
     */
    public function profileCompanyBikeFlag(int $userId): bool
    {
        try {
            return (int) DB::table('t_ops_rider_profile')
                ->where('user_id', $userId)->value('company_bike') === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function pass(?string $notice = null): array
    {
        return ['ok' => true, 'message' => null, 'notice' => $notice];
    }

    private function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'notice' => null];
    }

    private function intOrNull($v): ?int
    {
        if ($v === null || $v === '' || !is_numeric($v)) return null;
        $n = (int) $v;
        return $n > 0 ? $n : null;
    }

    private function dateOf($v): string
    {
        try {
            return $v ? Carbon::parse($v)->format('Y-m-d') : Carbon::today()->format('Y-m-d');
        } catch (\Throwable $e) {
            return Carbon::today()->format('Y-m-d');
        }
    }

    private function cfg(string $key, $default)
    {
        try {
            $v = DB::table('t_fin_config')->where('config_key', $key)->value('config_value');
            return ($v === null || $v === '') ? $default : $v;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
