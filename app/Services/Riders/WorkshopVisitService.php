<?php

namespace App\Services\Riders;

use App\Services\ShiftResolutionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 🔧 WORKSHOP VISITS — "Asim, take AY-4771 in on Friday at 11" (owner ask, Sep-2026).
 *
 * Plan: VEHICLE-TICKETS-AND-WORKSHOP-PLAN-SEP2026.md §3.
 *
 * ⭐⭐ NOT A DAY TAG. `t_ops_day_tag` means "not needed — paid, not counted absent" and
 *    changes pay and absence treatment. OWNER RULING (2-Sep): a workshop day is a NORMAL
 *    PAID WORKING DAY; the person planning the shift adjusts that day so no lateness
 *    lands. So this is its own row that every surface READS, and nothing here ever
 *    writes a day tag.
 *
 * ⭐⭐ THE VISIT NAMES ONE PERSON, and that is what makes acceptance meaningful. The
 *    banner, the push and the Accept button all resolve `user_id` and nobody else, so a
 *    rider can never see — let alone accept — someone else's instruction. A manager may
 *    accept ON HIS BEHALF when the app is not working, and that is stored as such and
 *    rendered as "accepted by X for Y", never as the rider's own confirmation.
 *
 * ⭐ MISSED IS DERIVED, NEVER STORED. A visit whose date has passed while still
 *   scheduled/accepted is "missed" — computed at read time, exactly like the service
 *   alerts. Prod has no cron, so anything that depended on a nightly job to set a flag
 *   would simply never run (see [[prod-has-no-scheduler-cron]]).
 *
 * ⚠ Schema-guarded throughout: the PHP may be uploaded before
 *   `database/migrations/workshop_visits_sep2026.sql` runs.
 */
class WorkshopVisitService
{
    public const T_VISIT = 't_ops_workshop_visit';

    /** Set / reschedule / cancel / complete a visit. */
    public const PERMISSION = 'schedule_workshop';
    /** Be told one was set — RULED to include Farooq (role 20), who plans the shifts. */
    public const ALERT_PERMISSION = 'receive_workshop_alerts';
    /**
     * ⭐⭐ WHO MAY APPROVE A PROPOSED WORKSHOP DAY (owner + team ruling, 6-Sep-2026).
     *    The SHIFT PLANNERS — Shabib, Farooq, Taimur — because what a workshop day
     *    actually changes is the rider's day, and that is their table. Deliberately NOT
     *    `schedule_workshop`: Qasim holds that, and the whole point of this round is that
     *    Qasim's booking is a REQUEST until a planner says yes.
     * ⚠ Existed as a MOBILE key only until `workshop_approval_sep2026.sql` seeded the web
     *   half — without that SQL `hasPermission()` is false for everyone on the desk.
     */
    public const APPROVE_PERMISSION = 'manage_shifts';

    /**
     * ⭐⭐ THE TRIP STATES — a derived view of one visit row, never stored.
     *
     * `none` is not "no visit": it is "he has a visit today and has not set off".
     * Read `NONE` as *booked, not started*; a rider with no visit at all gets `null`
     * from `tripFor()`, which is a different answer and must stay different.
     */
    public const TRIP_NONE       = 'booked_today';   // told, not gone yet
    public const TRIP_EN_ROUTE   = 'en_route';       // pressed "going", not there yet
    public const TRIP_AT         = 'at_workshop';    // geofence says he is there
    public const TRIP_ENDED      = 'ended';          // outcome recorded, or he checked out
    /** The two states the boards must react to. */
    public const TRIP_ACTIVE     = [self::TRIP_EN_ROUTE, self::TRIP_AT];

    /**
     * How close counts as "at the workshop". Deliberately wider than the office radius:
     * a workshop is a roadside unit, its pin is approximate, and a rider standing across
     * the street is at the workshop by every measure that matters. Overridable per
     * location by the location's own radius when it has one.
     */
    public const ARRIVE_RADIUS_M = 250;

    private static ?bool $tripCols = null;
    /**
     * [userId|date => bool] — the boards ask per rider, so one read each.
     *
     * ⚠⚠ INSTANCE-level, not static, and that distinction is load-bearing. As a static it
     *    survived the request that filled it: a rider whose checkout was cached as `true` kept
     *    reading "trip over" for the life of the process, and a queue worker or a test running
     *    several scenarios saw yesterday's answer. Per instance it is exactly as cheap inside
     *    one board render — which is the only place it is asked twice — and cannot go stale.
     */
    private array $checkoutMemo = [];
    /** [locationId => coords|null] */
    private static array $coordMemo = [];

    public const PURPOSES = ['service', 'repair', 'inspection', 'other'];
    /**
     * Statuses that still expect something to happen.
     *
     * ⚠⚠ `proposed` IS DELIBERATELY NOT IN HERE, and that is the safety mechanism of the
     *    whole approval round. Every rider-facing reader is built on this list —
     *    `nextForUser`, `awaitingOutcomeFor`, `summaryFor`'s rider branch, `dueReminders`,
     *    `mapForRange`'s default — so a proposal is invisible to the rider BY CONSTRUCTION
     *    rather than by remembering to filter it in each place. It is also what makes OLD
     *    APKs safe: a phone that has never heard of `proposed` is never sent one as his.
     */
    public const LIVE_STATUSES = ['scheduled', 'accepted'];
    /**
     * Everything a NEW booking on the same machine must supersede, and everything the
     * booker may still cancel. A proposal occupies the bike's slot exactly as a booking
     * does — two open instructions for one bike is how a rider ends up at the workshop on
     * the wrong day, and "one of them was only a request" does not make that better.
     */
    public const OPEN_STATUSES = ['proposed', 'scheduled', 'accepted'];

    private ?bool $tableExists = null;
    private ?bool $approvalCols = null;
    /**
     * Why the workshop could NOT be pinned to the rider's day, when it could not.
     * ⭐ Read straight after `applyShiftLocation()` and appended to the message the human
     *   sees. Same shape as `VehicleTicketController::$lastVoiceError`, and for the same
     *   reason: a convenience that quietly does nothing is worse than one that fails loudly.
     */
    private ?string $lastPinNote = null;

    public function available(): bool
    {
        if ($this->tableExists === null) {
            try {
                $this->tableExists = Schema::hasTable(self::T_VISIT);
            } catch (\Throwable $e) {
                $this->tableExists = false;
            }
        }
        return $this->tableExists;
    }

    public function canSchedule($user, bool $mobile = false): bool
    {
        if (!$user) return false;
        if (method_exists($user, 'isReadOnly') && $user->isReadOnly()) return false;
        if ($mobile) {
            return method_exists($user, 'hasMobilePermission')
                && $user->hasMobilePermission(self::PERMISSION);
        }
        return method_exists($user, 'hasPermission') && (bool) $user->hasPermission(self::PERMISSION);
    }

    /**
     * ⭐⭐ IS THE APPROVAL FLOW EVEN INSTALLED? Until `workshop_approval_sep2026.sql` has
     *    run there is nowhere to record who proposed or approved anything, so this whole
     *    round falls back to the OLD behaviour — a booking is assigned immediately and the
     *    rider is told, exactly as before. That is the correct degradation for a system
     *    deployed by hand: the PHP routinely lands before the SQL does, and a half-applied
     *    approval flow that silently swallowed bookings would be far worse than the old one.
     */
    public function approvalEnabled(): bool
    {
        if ($this->approvalCols === null) {
            try {
                $this->approvalCols = $this->available()
                    && Schema::hasColumn(self::T_VISIT, 'proposed_by');
            } catch (\Throwable $e) {
                $this->approvalCols = false;
            }
        }
        return $this->approvalCols;
    }

    /**
     * ⭐⭐ Has the TRIP migration run (`workshop_trip_sep2026.sql`)?
     *
     * Same discipline as `approvalEnabled()`: the PHP routinely lands before the SQL on a
     * hand-deployed system, so every trip reader asks this first and degrades to "no trip
     * exists" — which is exactly the behaviour before this feature. Nothing 500s, no board
     * changes, and the START button simply does not appear.
     */
    public function tripEnabled(): bool
    {
        if (self::$tripCols === null) {
            try {
                self::$tripCols = $this->available()
                    && Schema::hasColumn(self::T_VISIT, 'departed_at');
            } catch (\Throwable $e) {
                self::$tripCols = false;
            }
        }
        return self::$tripCols;
    }

    /** Tests, and anything that changes the schema mid-process. */
    public static function flushSchemaMemo(): void
    {
        self::$tripCols = null;
    }

    /**
     * ⭐ ONE PREDICATE for "is this person a shift planner?", asked by the booking decision,
     *   the approve/decline doors, the banner and both UIs. Written once for the same
     *   reason `VehicleTicketService::visibilityScope` is: a rule spelled out in four
     *   places is a rule that will disagree with itself within a month.
     */
    public function canApprove($user, bool $mobile = false): bool
    {
        if (!$user) return false;
        if (method_exists($user, 'isReadOnly') && $user->isReadOnly()) return false;
        if ($mobile) {
            return method_exists($user, 'hasMobilePermission')
                && (bool) $user->hasMobilePermission(self::APPROVE_PERMISSION);
        }
        return method_exists($user, 'hasPermission') && (bool) $user->hasPermission(self::APPROVE_PERMISSION);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  SCHEDULING
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Set a date. If the machine already has a live visit, this REPLACES it — the old
     * row becomes 'rescheduled' and points at the new one, and the rider must accept
     * again (a moved date he never saw is worse than no date at all).
     *
     * @return array{ok: bool, message: string, visit_id?: int, warnings?: array, rescheduled_from?: int}
     */
    public function schedule($user, array $in, bool $mobile = false): array
    {
        if (!$this->available()) return ['ok' => false, 'message' => 'Workshop visits are not set up yet.'];
        if (!$this->canSchedule($user, $mobile)) {
            return ['ok' => false, 'message' => 'You cannot schedule workshop visits.'];
        }

        $vehicleId = (int) ($in['vehicle_id'] ?? 0);
        $date      = trim((string) ($in['visit_date'] ?? ''));

        /**
         * ⭐ THE BIKE CAN BE RESOLVED FROM THE RIDER. Callers that already know which man
         *   they are looking at (the Bikes drawer, a ticket) should not have to carry a
         *   vehicle id around — the registry answers it, and asking the registry once here
         *   is safer than four screens each finding their own way to the same number.
         *   `vehicle_id` still wins when given, for the vehicle-first surfaces.
         */
        if (!$vehicleId && !empty($in['user_id'])) {
            try {
                $vehicleId = (int) ((new VehicleResolver())->currentVehicleFor((int) $in['user_id']) ?: 0);
            } catch (\Throwable $e) {
                $vehicleId = 0;
            }
        }
        if (!$vehicleId) return ['ok' => false, 'message' => 'Choose which bike is going in — the registry has no machine for that rider.'];

        /**
         * ⭐⭐ COMPANY MACHINES ONLY (owner ruling, 10-Sep-2026): *"we don't care about the
         *    maintenance and overnight flags for personal bikes — for personal bikes we note
         *    the meter only for fuel, because that's what we are responsible for."*
         *
         * The same rule the maintenance schedule now follows, applied one step earlier: there
         * is no point booking a workshop day for a machine whose service the company does not
         * keep. Refused with the reason, not silently.
         * ⚠ EXISTING visits on a personal bike are left alone — they are history, and rewriting
         *   history to match a new rule is how audit trails stop being trustworthy.
         */
        try {
            if (!(new VehicleService())->isTrackedId($vehicleId)) {
                return ['ok' => false, 'message' =>
                    'That is a personal vehicle. The company does not keep its service schedule, '
                    . 'so there is no workshop day to book — its meter is recorded for fuel only.'];
            }
        } catch (\Throwable $e) {
            // A registry hiccup must not block a legitimate booking.
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['ok' => false, 'message' => 'Give the date as YYYY-MM-DD.'];
        }
        if ($date < \Carbon\Carbon::today()->format('Y-m-d')) {
            // A visit is an instruction for the future. Recording work already done is
            // "Record service", which is a different action with a different effect.
            return ['ok' => false, 'message' => 'That date has already passed. To record work already done, use Record service on the Bikes screen.'];
        }

        // WHO goes. Named explicitly, else whoever the registry says holds it that day.
        $riderId = (int) ($in['user_id'] ?? 0);
        if (!$riderId) {
            try {
                $riderId = (int) ((new VehicleResolver())->riderForVehicleDay($vehicleId, $date) ?: 0);
            } catch (\Throwable $e) {
                $riderId = 0;
            }
        }
        if (!$riderId) {
            return ['ok' => false, 'message' => 'Nobody holds that bike on the day — name the rider who should take it.'];
        }

        $purpose = in_array($in['purpose'] ?? '', self::PURPOSES, true) ? $in['purpose'] : 'service';

        /**
         * ⭐⭐ THE BOOKING DECISION (owner + team ruling, 6-Sep-2026), made SERVER-SIDE from
         *    the caller's own rights so no client can grant itself an assignment.
         *
         *      Qasim (schedule_workshop, not a planner)  → ALWAYS a proposal.
         *      Shabib / Taimur (planner as well)         → the form asks him, and he
         *                                                  answers with `send_for_approval`.
         *
         * ⚠⚠ THE PLANNER'S DEFAULT IS THE OLD BEHAVIOUR, ON PURPOSE. A planner on an old
         *    APK — or an old cached web page — sends neither flag, and must not have his
         *    booking quietly turned into a request he will never see the banner for and
         *    which the morning sweep then auto-declines. So the ASK is a UI affordance and
         *    the flag is opt-IN; only a client that knows about approval can send for it.
         *    Qasim's client being old changes nothing, because it is the PLANNERS' screens
         *    that show a proposal and those ship with this server.
         *
         * ⚠ A non-planner sending `assign_now` is simply not believed — the flag is never
         *   read from the request for him; his rights decide, not his payload.
         */
        $isPlanner = $this->canApprove($user, $mobile);
        $proposed  = $this->approvalEnabled()
            && (!$isPlanner || !empty($in['send_for_approval']));

        /**
         * ⏰ DEAD ON ARRIVAL (owner ruling 7-Sep). A proposal is auto-declined once the clock
         * passes its approval cut-off — min(shift start, appointment) minus the lead time.
         * Booking one AFTER that moment was accepted with a cheerful "Sent for approval", and
         * the very next alerts poll killed it. The booker walked away believing a planner
         * would look; nobody could have, because `approve()` refuses on the same clock.
         *
         * Refuse it at the door instead, and say what to do about it. A PLANNER assigning
         * directly is unaffected — he is not asking anyone, so there is no cut-off to miss.
         */
        $cutoffCtx = ['user_id' => $riderId, 'visit_date' => $date,
                      'visit_time' => $in['visit_time'] ?? null];
        if ($proposed && $this->hasApprovalCutoff($cutoffCtx)) {
            $cutoff = $this->approvalCutoffFor($cutoffCtx);
            if (now()->greaterThanOrEqualTo($cutoff)) {
                return ['ok' => false, 'message' =>
                    'Too late to send this for approval — it had to be decided by '
                    . $cutoff->format('g:i A') . ' on ' . $cutoff->format('j M')
                    . '. Pick a later day, or ask a shift planner to book it directly.'];
            }
        }

        /**
         * ⚠⚠ TELL HIM BEFORE, NOT AFTER (owner ruling 7-Sep). Booking a bike that already has
         *    an APPROVED workshop day changes a plan the rider has already been told about —
         *    and possibly confirmed. The warnings used to arrive with the receipt, once the
         *    booking had happened. Now the server refuses the first attempt, says exactly what
         *    would change, and only accepts it when the client sends `confirm_replace`.
         *
         * ⭐ Server-side on purpose: the desk and the phone get the same guard from one place,
         *   and it cannot be skipped by a screen that forgot to ask.
         * ⚠ An OLD client cannot send the flag, so it simply cannot replace an approved day —
         *   it reads the refusal instead. That is the safe direction to fail in: the whole
         *   point is that this must never happen by accident.
         */
        $liveNow = $this->liveVisitFor($vehicleId);
        if ($liveNow && empty($in['confirm_replace'])) {
            return [
                'ok' => false,
                'needs_confirmation' => true,
                'replaces' => $liveNow,
                'message' => $this->replacementNoticeFor($liveNow)
                    . ($proposed
                        ? ' Approval ke baad hi woh badlega.'
                        : ' Woh abhi cancel ho jayega.'),
            ];
        }

        try {
            $now = now();
            $warnings = $this->warningsFor($vehicleId, $riderId, $date);

            $visitId = null;
            $replaced = null;
            /**
             * ⭐ THE VISIT'S OWN `ticket_id` — "the thread this was raised from" — resolved here
             *   so the row written below and the link written after it cannot disagree.
             *
             * ⚠⚠ Dropped when the booker WAS asked which issues the trip covers and left this
             *    very one unticked. `linkedTicketIds()` reads this column as well as the
             *    pointers, so keeping it would resurrect the complaint he just excluded — and
             *    its thread would later be told the visit was completed for it.
             */
            $originatingTicketId = !empty($in['ticket_id']) ? (int) $in['ticket_id'] : null;
            if ($originatingTicketId && array_key_exists('ticket_ids', $in)) {
                $ticked = array_map('intval', (array) $in['ticket_ids']);
                if (!in_array($originatingTicketId, $ticked, true)) $originatingTicketId = null;
            }
            /**
             * 📍 WHERE HE CHECKS IN THAT DAY — resolved BEFORE the transaction, because both
             *    halves of this method need the same answer: the row written inside the
             *    closure, and the shift pin written after it.
             *
             * ⚠⚠ It used to live only inside the closure, so the pin below could not see it —
             *    which is exactly how a SAME-DAY booking came to store "regular" on the row
             *    and move his check-in place anyway (found driving the real web form,
             *    10-Sep-2026).
             * ⚠ A SAME-DAY booking is forced to `regular` whatever the form sends: he has
             *   already checked in (or not), and pinning a location retrospectively would
             *   move the goalposts under a man who is already at work.
             * ⚠ NULL means "not asked" — the pre-10-Sep behaviour, kept for old clients:
             *   `checkinAtOf()` then falls back to "a registered workshop was chosen ⇒ pin".
             */
            $attendanceAt = null;
            if ($this->tripEnabled()) {
                $want = (string) ($in['attendance_at'] ?? '');
                $attendanceAt = ($date === \Carbon\Carbon::today()->format('Y-m-d'))
                    ? 'regular'
                    : (in_array($want, ['regular', 'workshop'], true) ? $want : null);
            }

            DB::transaction(function () use (&$visitId, &$replaced, $vehicleId, $riderId, $date, $purpose, $in, $user, $now, $proposed, $attendanceAt, $originatingTicketId) {
                /**
                 * ⚠ One open visit per machine — two open instructions for one bike is how a
                 *   rider ends up at the workshop on the wrong day.
                 *
                 * ⭐⭐ BUT A PROPOSAL MAY NOT KILL AN APPROVED DAY (owner ruling 7-Sep). Until
                 *    this round, booking a bike that already had an APPROVED visit marked that
                 *    visit `rescheduled` and deleted its shift pin the instant the new one was
                 *    raised — even when the new one was only a request nobody had answered. The
                 *    rider had been told to go, may have confirmed it, and then heard nothing:
                 *    his day quietly lost its pin and the replacement might never be approved.
                 *
                 *    So a PROPOSAL now supersedes only other PROPOSALS (re-asking is harmless).
                 *    The approved day stands, untouched, until somebody approves the
                 *    replacement — and `approve()` is what swaps them and tells the rider it
                 *    moved. A planner assigning DIRECTLY still supersedes at once, because he
                 *    is not asking anyone; the rider is told in the same breath.
                 *
                 * ⚠ The rider never sees two: a proposal is not a LIVE status, so every
                 *   rider-facing reader ignores it by construction.
                 */
                $supersedable = $proposed
                    ? ['proposed']
                    : ($this->approvalEnabled() ? self::OPEN_STATUSES : self::LIVE_STATUSES);
                $existing = DB::table(self::T_VISIT)
                    ->where('vehicle_id', $vehicleId)
                    ->whereIn('status', $supersedable)
                    ->lockForUpdate()
                    ->orderByDesc('id')->first();

                /**
                 * 📍 WHERE HE CHECKS IN THAT DAY (owner ask, 10-Sep). Asked of the booker or the
                 *    approver instead of inferred from "did you pick a registered workshop".
                 * ⚠ A SAME-DAY booking is forced to `regular` whatever the form sends: he has
                 *   already checked in (or not), and pinning a location retrospectively would
                 *   move the goalposts under a man who is already at work.
                 * ⚠ NULL means "not asked" — the pre-10-Sep behaviour, kept for old clients:
                 *   `approve()` then pins if, and only if, a registered workshop was chosen.
                 */
                // ⭐ Resolved above the transaction — the pin after it needs the same answer.
                $visitId = (int) DB::table(self::T_VISIT)->insertGetId(array_filter([
                    'attendance_at'       => $attendanceAt,
                ], fn ($v) => $v !== null) + [
                    'vehicle_id'          => $vehicleId,
                    'user_id'             => $riderId,
                    'visit_date'          => $date,
                    'visit_time'          => $in['visit_time'] ?? null,
                    'workshop'            => isset($in['workshop']) ? mb_substr(trim((string) $in['workshop']), 0, 120) : null,
                    'location_id'         => !empty($in['location_id']) ? (int) $in['location_id'] : null,
                    'purpose'             => $purpose,
                    'maintenance_type_id' => $purpose === 'service' && !empty($in['maintenance_type_id'])
                                             ? (int) $in['maintenance_type_id'] : null,
                    // ⚠ Resolved above the transaction, and NULL when he unticked this very issue.
                    'ticket_id'           => $originatingTicketId,
                    'note'                => isset($in['note']) ? mb_substr(trim((string) $in['note']), 0, 255) : null,
                    'status'              => $proposed ? 'proposed' : 'scheduled',
                    'created_by'          => (int) $user->id,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ] + ($proposed ? ['proposed_by' => (int) $user->id] : []));

                if ($existing) {
                    DB::table(self::T_VISIT)->where('id', $existing->id)->update([
                        'status'        => 'rescheduled',
                        'superseded_by' => $visitId,
                        'updated_at'    => $now,
                    ]);
                    $replaced = (int) $existing->id;
                    /**
                     * ⭐ PART B: the complaints MOVE with the day. A superseded visit is not a
                     *   cancellation — the bike is still going in, on a different date — so its
                     *   tickets follow rather than being dropped back into the queue and having
                     *   to be ticked again. Without this the replacement would cover nothing and
                     *   the old visit would keep the links it can no longer honour.
                     */
                    $this->carryTicketsOver($replaced, (int) $visitId, (array) $existing, $in, $vehicleId);
                }
            });

            /**
             * ⭐ The ticket thread is the audit trail — a visit scheduled off a complaint
             *   must be visible to the rider who complained, in the place he is watching.
             *
             * ⚠⚠ NOT WHILE IT IS ONLY A PROPOSAL. The rider reads that thread. Writing
             *    "Workshop visit set for Sat 7 Sep" into it would tell him the one thing the
             *    whole ruling exists to withhold — and would tell him a date that a planner
             *    may yet decline. The line is written on APPROVAL instead, which is the
             *    moment it becomes true.
             */
            if (!$proposed) {
                $this->linkTicket((int) $visitId, $in, $user, $date, $in['visit_time'] ?? null,
                                  'set for', $vehicleId);
            } else {
                /**
                 * ⭐⭐ PART B: A PROPOSAL STAKES THE CHOICE WITHOUT ANNOUNCING IT.
                 *
                 *    The booker ticked which issues this visit covers, and that answer has to
                 *    survive until a planner decides — possibly hours later, from a different
                 *    screen. The alternative was to re-derive it at approval from "every open
                 *    ticket on the machine", which would silently ignore a booker who
                 *    deliberately ticked two of four.
                 *
                 * ⚠⚠ THE POINTER ONLY. No status moves and NOT ONE LINE is written into any
                 *    thread — that is the 6-Sep ruling, and the rider reads those threads.
                 *    `workshop_visit_id` is an internal FK: `listVisits` refuses a proposal to
                 *    him, so it resolves to nothing he can see, and no rider surface renders it.
                 *    `approve()` completes the link; `decline()`, the sweep and a withdrawal
                 *    all release it (see `releaseTickets`).
                 */
                $staked = $this->resolveTicketIds($in, $vehicleId);
                if ($staked) {
                    try {
                        DB::table(VehicleTicketService::T_TICKET)->whereIn('id', $staked)
                            ->update(['workshop_visit_id' => (int) $visitId, 'updated_at' => $now]);
                    } catch (\Throwable $e) {
                        Log::warning('Proposal could not stake its tickets',
                            ['visit' => $visitId, 'error' => $e->getMessage()]);
                    }
                }
            }

            /**
             * 📍 PHASE 4. A visit booked at a REGISTERED workshop makes that workshop the
             * rider's shift location for that one day, so checking in there is on time by
             * itself. Opt-in: no `location_id`, no override, nothing changes.
             * ⚠ The row it replaces is cleared first — a MOVED visit must not leave the old
             *   day pointing at a workshop nobody is going to any more.
             * ⚠⚠ A PROPOSAL PINS NOTHING. Approval is precisely the gate on this write: until
             *    a planner says yes, the rider's day is untouched and he is measured against
             *    his normal place, exactly as if nobody had booked anything.
             *
             * ⚠⚠ …AND NEITHER DOES A DAY THAT HAS ALREADY STARTED (found on the web form,
             *    10-Sep-2026). This wrote the pin from `location_id` alone, ignoring
             *    `$attendanceAt` entirely — so a SAME-DAY booking stored "regular" on the row
             *    and then moved his check-in place anyway. The two halves of one visit said
             *    opposite things, and the planner was told "he checks in at the workshop"
             *    about a morning the rider had already spent somewhere else.
             *
             * ⭐ `approve()` has always honoured the choice; this is the direct-assign twin
             *   doing the same, so both doors write a pin only when the answer is 'workshop'.
             */
            if ($replaced) $this->clearShiftLocation($replaced, $riderId);
            /**
             * ⚠ Through `checkinAtOf()`, NOT a bare `=== 'workshop'`. `$attendanceAt` is NULL
             *   whenever nobody was asked — which is every booking form today — and null must
             *   keep meaning the OLD inference ("a registered workshop was chosen ⇒ pin it"),
             *   or this would quietly stop pinning future days that have always pinned.
             *   Today is already forced to 'regular' above, so today alone stops pinning.
             */
            $checkinAt = self::checkinAtOf([
                'attendance_at' => $attendanceAt,
                'location_id'   => $in['location_id'] ?? null,
            ]);
            $pinLocationId = ($checkinAt === 'workshop' && !empty($in['location_id']))
                ? (int) $in['location_id'] : null;
            // ⚠ $user is passed so the SHIFT RULES apply to this door too — see applyShiftLocation.
            $pinned = !$proposed && $this->applyShiftLocation((int) $visitId, $riderId, $date,
                                                $pinLocationId,
                                                null, $user);

            return [
                'ok'               => true,
                'visit_id'         => (int) $visitId,
                'rescheduled_from' => $replaced,
                'warnings'         => $warnings,
                // ⭐ The controller reads this to decide WHO gets pushed: the planners
                //   ("approve this"), or the rider ("this is your day").
                'proposed'         => $proposed,
                // Say it plainly: this is the difference between "he must not be marked late"
                // being handled by the system and being a job the planner still has to do.
                'shift_location_set' => $pinned,
                'message'          => $proposed
                    ? ('Sent for approval. ' . $this->nameOf($riderId) . ' has NOT been told yet — '
                        . 'a shift planner has to approve it first.'
                        . (!empty($in['location_id']) ? '' : ' ⚠ No workshop was chosen, so nothing '
                            . 'will be pinned to his day even after approval.'))
                    : (($replaced
                        ? 'Moved. ' . $this->nameOf($riderId) . ' has to accept the new date.'
                        : 'Set. ' . $this->nameOf($riderId) . ' will be asked to accept it.')
                    . ($pinned
                        ? ' That day he checks in at the workshop, so he will not be marked late or remote.'
                        /**
                         * ⚠⚠ 6-Sep: this used to say NOTHING when nothing was pinned, which is
                         *    how Danish was booked into a workshop while his day still pointed
                         *    at LaCarne and nobody could tell from the confirmation.
                         * ⚠ …but a DELIBERATE "he checks in as usual" is not a failed pin, and
                         *   a same-day booking is always deliberate — he started his day hours
                         *   ago. Reporting the rule as a warning is how a planner comes to
                         *   distrust the warnings that matter.
                         */
                        : ($checkinAt === 'regular'
                            ? ' He checks in as usual and rides over — nothing about that day moves.'
                            : ' ⚠ ' . ($this->lastPinNote ?: 'His check-in place was not changed.')))),
            ];
        } catch (\Throwable $e) {
            Log::error('WorkshopVisitService::schedule failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not set that date.'];
        }
    }

    /**
     * ⭐ WARN, NEVER BLOCK (owner intent). A workshop appointment on a rider's off day is
     *   unusual but legitimate — the manager may have agreed it with him. What is NOT
     *   acceptable is setting it without noticing. So these are returned for the UI to
     *   show, and the visit is created either way.
     *
     * @return array<int, string>
     */
    /**
     * The bike's APPROVED (live) workshop day, if it has one — the thing a new booking would
     * replace. Returns null when there is none, and never counts the visit being worked on.
     *
     * ⭐ One source for all three places that must agree about "he already has a day":
     *   the booker's warning, the approver's card, and the swap inside `approve()`.
     */
    public function liveVisitFor(int $vehicleId, ?int $exceptVisitId = null): ?array
    {
        if (!$vehicleId || !$this->available()) return null;
        try {
            $q = DB::table(self::T_VISIT)
                ->where('vehicle_id', $vehicleId)
                ->whereIn('status', self::LIVE_STATUSES);
            if ($exceptVisitId) $q->where('id', '<>', $exceptVisitId);
            $r = $q->orderByDesc('id')->first(['id', 'user_id', 'visit_date', 'visit_time', 'workshop', 'status']);
            if (!$r) return null;
            return [
                'id'         => (int) $r->id,
                'user_id'    => (int) $r->user_id,
                'visit_date' => substr((string) $r->visit_date, 0, 10),
                'visit_time' => $r->visit_time ? substr((string) $r->visit_time, 0, 5) : null,
                'workshop'   => $r->workshop,
                'accepted'   => (string) $r->status === 'accepted',
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * One human sentence for "he already has an approved day", used by the warning, the
     * approval card and the pushes so all three say the same thing.
     * ⚠ English lead, Roman Urdu for the DETAIL — owner ruling 7-Sep. The rider must be
     *   re-told, and that is the part a manager has to actually take in.
     */
    public function replacementNoticeFor(?array $live): ?string
    {
        if (!$live) return null;
        $when = \Carbon\Carbon::parse($live['visit_date'])->format('D j M')
              . ($live['visit_time'] ? ' ' . $live['visit_time'] : '');
        return 'He already has an approved workshop day: ' . $when
             . ($live['workshop'] ? ' · ' . $live['workshop'] : '')
             . ($live['accepted'] ? ' (he has confirmed it)' : '')
             . '. Yeh us din ko badal dega — usko dobara batana parega.';
    }

    /**
     * ⭐ PART B: the machine's OPEN issues, for the booking form's tick-boxes.
     *
     * ⚠ Includes ones already pointing at another live visit, flagged rather than hidden: a
     *   manager moving a bike to a new day must be able to see — and re-tick — what the old day
     *   was covering. Hiding them would make the new visit silently cover less.
     *
     * @return array<int, array{id:int,title:string,urgent:bool,status:string,opened_for_name:?string,already_linked:bool}>
     */
    public function openTicketsForVehicle(int $vehicleId): array
    {
        if (!$vehicleId) return [];
        try {
            $tickets = app(VehicleTicketService::class);
            if (!$tickets->available()) return [];
            return DB::table(VehicleTicketService::T_TICKET . ' as t')
                ->leftJoin('t_sys_user as fo', 'fo.id', '=', 't.opened_for_user_id')
                ->where('t.vehicle_id', $vehicleId)
                ->whereIn('t.status', VehicleTicketService::OPEN_STATUSES)
                ->orderByDesc('t.urgent')->orderBy('t.id')
                ->limit(20)
                ->get(['t.id', 't.title', 't.urgent', 't.status', 't.workshop_visit_id',
                       'fo.fullname as opened_for_name'])
                ->map(fn ($r) => [
                    'id'              => (int) $r->id,
                    'title'           => (string) $r->title,
                    'urgent'          => (int) $r->urgent === 1,
                    'status'          => (string) $r->status,
                    'opened_for_name' => $r->opened_for_name,
                    'already_linked'  => $r->workshop_visit_id !== null,
                ])->values()->all();
        } catch (\Throwable $e) {
            Log::warning('Open tickets for vehicle failed', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    public function warningsFor(int $vehicleId, int $riderId, string $date): array
    {
        $out = [];
        // ⚠⚠ FIRST, because it is the only warning about something that ALREADY EXISTS and
        //    that somebody has already been told. The others are about the day being awkward.
        $notice = $this->replacementNoticeFor($this->liveVisitFor($vehicleId));
        if ($notice) $out[] = $notice;
        try {
            $kind = (new ShiftResolutionService())->dayKind($riderId, $date);
            if ($kind === 'off')        $out[] = 'That is his weekly off day.';
            if ($kind === 'holiday')    $out[] = 'That is a public holiday.';
            if ($kind === 'not_joined') $out[] = 'He had not joined by that date.';
            if ($kind === 'not_needed') $out[] = 'He is marked "not needed" that day.';
        } catch (\Throwable $e) {
            // A warning is advisory; never let it fail the scheduling.
        }
        try {
            $keeper = (new VehicleResolver())->riderForVehicleDay($vehicleId, $date);
            if ($keeper && (int) $keeper !== $riderId) {
                $out[] = 'On that day the registry says ' . $this->nameOf((int) $keeper) . ' has this bike.';
            }
        } catch (\Throwable $e) {
        }
        try {
            $leave = DB::table('t_req_master')
                ->where('requester_user_id', $riderId)
                ->where('status', 'approved')
                ->whereNotNull('leave_start_date')
                ->whereDate('leave_start_date', '<=', $date)
                ->whereDate('leave_end_date', '>=', $date)
                ->exists();
            if ($leave) $out[] = 'He has approved leave on that date.';
        } catch (\Throwable $e) {
        }
        return $out;
    }

    /** Does this install have the Phase-4 workshop label yet? */
    public function locationsEnabled(): bool
    {
        try {
            return Schema::hasColumn('t_ops_company_locations', 'is_workshop');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The registered workshops, for the "which workshop?" picker. */
    public function workshopLocations(): array
    {
        if (!$this->locationsEnabled()) return [];
        try {
            return DB::table('t_ops_company_locations')
                ->where('is_active', 1)->where('is_workshop', 1)
                ->orderBy('location_name')
                ->get(['id', 'location_name'])
                ->map(fn ($l) => ['id' => (int) $l->id, 'name' => $l->location_name])
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 📍 PHASE 4 — make the workshop that day's SHIFT LOCATION.
     *
     * ⭐⭐ WHY THIS IS ENOUGH. Attendance already resolves the check-in base from the rider's
     *    shift for the day: check-in → `ShiftResolutionService::getUserShift()` →
     *    `resolveLocation()` → the assignment's location → `LocationService`. So a rider who
     *    checks in AT the workshop is measured against the WORKSHOP and picks up no remote /
     *    late flag — with no change to attendance code at all. Owner intent, 2-Sep: "later
     *    workshop will be added as the shift location".
     *
     * ⚠⚠ THE SHIFT TIMES ARE NOT TOUCHED. The override reuses the template he was already on
     *    that day and changes ONLY `location_id`. A workshop day is a normal paid working day
     *    (owner ruling) — this must never become a way to shorten or move his hours.
     *
     * ⚠ `notified_at` is left NULL ON PURPOSE, so this does NOT raise the "your shift is
     *   changing — confirm" banner. He already confirms the VISIT; asking twice for one fact
     *   is how people start ignoring both.
     *
     * ⚠ It NEVER overwrites an override the planner made himself. If a bounded row already
     *   covers that day, his decision stands and this returns false — a scheduling convenience
     *   must not silently undo a human's deliberate change.
     *
     * ⚠ Bounded to exactly one day (from = to = the visit date) and removed again by
     *   `clearShiftLocation()` on cancel / reschedule, so nothing outlives the visit.
     */
    /**
     * @param int|null $templateId ⭐ 6-Sep: the approving planner may ALSO move the rider's
     *        start time for that one day — the case Danish exposed, whose visit was booked
     *        for 09:00 while his shift still started 09:30 at LaCarne. Given, this template
     *        replaces the one he was on; omitted, the times are untouched exactly as before.
     *        ⚠ Only a planner ever reaches this argument (see `approve()`); booking cannot.
     */
    private function applyShiftLocation(int $visitId, int $userId, string $date, ?int $locationId, ?int $templateId = null, $actor = null): bool
    {
        $this->lastPinNote = null;

        /**
         * ⚠⚠ THE SHIFT RULES APPLY HERE TOO (Sep-2026). This method writes a real one-day row
         *    into `t_ops_user_shift_assignment` WITHOUT going through the gated
         *    `ShiftController` engine, so it is a second door onto somebody's working day —
         *    and the shift-authority round would have left it unwatched.
         *
         *    Three things it let through before this check, all found by
         *    `probe_workshop_shift_seam.php`:
         *      • FAROOQ HOLDS A BIKE, so a visit can be booked for him — and as a planner he
         *        could approve it himself, writing his own one-day shift row. The one rule the
         *        owner cared about most ("nobody sets his own shift") went out of this door.
         *      • a planner could pin somebody ABOVE him on the ladder;
         *      • the Adjust picker could move a rider onto a shift OUTSIDE the allowed list
         *        Taimur set for him — refused by the planner screens, accepted here.
         *
         * ⭐ Only a DENY blocks. An "approval needed" verdict is fine: this pin IS a planner
         *   approving, and demanding a second approval to move a check-in place would make the
         *   ordinary workshop day unusable. For a plain rider — the overwhelming case — the
         *   gate answers ALLOW and nothing about today's behaviour changes.
         */
        if ($actor) {
            try {
                $gate = app(\App\Services\Ops\ShiftAuthorityService::class)
                    ->decide($actor, $userId, $templateId, 'assign');
                if ($gate['verdict'] === \App\Services\Ops\ShiftAuthorityService::DENY) {
                    $this->lastPinNote = 'His check-in place was NOT moved — ' . lcfirst((string) $gate['message'])
                        . ' The visit is approved; ask someone who can change that day to pin it.';
                    return false;
                }
            } catch (\Throwable $e) {
                // The rules are an extra guard, never a reason the workshop flow breaks.
                Log::warning('Shift-authority check skipped on workshop pin', ['error' => $e->getMessage()]);
            }
        }

        if (!$locationId) {
            $this->lastPinNote = 'No registered workshop was chosen, so his check-in place is '
                . 'unchanged — he will be measured against his normal location that day.';
            return false;
        }
        try {
            if (!Schema::hasTable('t_ops_user_shift_assignment')) return false;

            // A row the planner created for that day wins — see the note above.
            $existing = DB::table('t_ops_user_shift_assignment')
                ->where('user_id', $userId)
                ->whereNotNull('effective_to')
                ->whereDate('effective_from', '<=', $date)
                ->whereDate('effective_to', '>=', $date)
                ->first(['id', 'workshop_visit_id']);
            if ($existing && empty($existing->workshop_visit_id)) {
                /**
                 * ⚠⚠ AND SAY SO OUT LOUD (6-Sep). A human's row still wins — that has not
                 *    changed — but returning a bare false made this the second silent way to
                 *    reach "the visit exists and nothing is pinned", which is the exact
                 *    failure the approval round was built to end. The approver is now told,
                 *    in the same breath as his approval, that he has to move that day
                 *    himself.
                 */
                $this->lastPinNote = 'A shift change already exists for that day, made by hand — '
                    . 'it was left alone, so his check-in place was NOT moved to the workshop. '
                    . 'Change that day on the shift planner if he should check in there.';
                return false;
            }

            // The template he is ALREADY on that day — so only the place changes — unless a
            // planner deliberately chose a different one while approving.
            $resolved = (new ShiftResolutionService())->getUserShift($userId, $date);
            if (!$templateId) {
                $templateId = $resolved['shift_id'] ?? null;
            }
            if (!$templateId) {
                // Same principle as above: a silent "nothing happened" is what we are ending.
                $this->lastPinNote = 'He has no shift on that day, so there was nothing to pin the '
                    . 'workshop to — give him a shift for that day if he must check in there.';
                return false;   // cannot pin a location with no shift to pin it to
            }

            $row = [
                'user_id'           => $userId,
                'shift_template_id' => (int) $templateId,
                'location_id'       => $locationId,
                'effective_from'    => $date,
                'effective_to'      => $date,
                'workshop_visit_id' => $visitId,
                'notified_at'       => null,      // ⚠ no second confirm — see the note above
                'acknowledged_at'   => null,
                'updated_at'        => now(),
            ];
            /**
             * 🔁 WEEKLY DAYS (Oct-2026). This one-day row wins the date, and on its own it would
             * fall back to the shift TYPE's off days — flipping a weekly "off" day into a working
             * day (absent if he does not check in), or a weekly shift on the type's off day into
             * an off day. Carry that day's on/off status onto the pin so ONLY the place moves.
             * ⚠ Only when he has weekly days in force that date — otherwise byte-identical.
             */
            // A pin is a one-day TEMPORARY row — say so (Oct-2026), guarded for deploy order.
            if (ShiftResolutionService::kindColumnAvailable()) {
                $row['is_temporary'] = 1;
            }
            if (!empty($resolved['weekly']) && \App\Services\Ops\WeekdayPattern::available()) {
                $dow = (int) date('N', strtotime($date));
                $worksThatDay = in_array($dow, array_map('intval', $resolved['working_days'] ?? []), true);
                $wd = DB::table('t_ops_shift_template')->where('id', (int) $templateId)->value('working_days');
                $typeWorks = in_array($dow, array_map('intval', is_string($wd) ? (json_decode($wd, true) ?: []) : []), true);
                $pin = null;
                if (!$worksThatDay && $typeWorks) {
                    $pin = [$dow => ['off' => true]];
                } elseif ($worksThatDay && !$typeWorks) {
                    $pin = [$dow => ['template_id' => (int) $templateId, 'location_id' => $locationId]];
                }
                $row['day_pattern'] = \App\Services\Ops\WeekdayPattern::encode($pin);
            }
            if ($existing) {
                DB::table('t_ops_user_shift_assignment')->where('id', $existing->id)->update($row);
            } else {
                DB::table('t_ops_user_shift_assignment')->insert($row + ['created_at' => now()]);
            }
            (new ShiftResolutionService())->clearUserShiftCache($userId);
            return true;
        } catch (\Throwable $e) {
            // A convenience must never fail the scheduling it decorates.
            Log::warning('Workshop shift-location override not written',
                ['visit' => $visitId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** Remove the one-day override a visit created. Only ever deletes OUR OWN row. */
    private function clearShiftLocation(int $visitId, int $userId): void
    {
        try {
            if (!Schema::hasTable('t_ops_user_shift_assignment')) return;
            if (!Schema::hasColumn('t_ops_user_shift_assignment', 'workshop_visit_id')) return;
            $n = DB::table('t_ops_user_shift_assignment')
                ->where('workshop_visit_id', $visitId)->delete();
            if ($n) (new ShiftResolutionService())->clearUserShiftCache($userId);
        } catch (\Throwable $e) {
            Log::warning('Workshop shift-location override not cleared',
                ['visit' => $visitId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Write the visit into the ticket's thread, when it came from one.
     *
     * @param string $verb ⭐ 6-Sep: "set for" when a planner assigned it directly, "approved
     *        for" when it arrived through the approval flow. The rider reads this thread, so
     *        it should say what actually happened — and it is only ever written at the moment
     *        the date becomes real (never for a proposal, see `schedule()`).
     */
    private function linkTicket(int $visitId, array $in, $user, string $date, ?string $time,
                                string $verb = 'set for', int $vehicleId = 0): void
    {
        /**
         * ⭐⭐ PART B (15-Sep-2026) — A VISIT MAY ANSWER SEVERAL COMPLAINTS AT ONCE.
         *
         *    Until now this linked exactly one ticket, the one a manager happened to press
         *    "🔧 Schedule workshop" from. On the replica that produced **zero** links in six
         *    visits, because managers book the MACHINE from its own card, not a thread — so
         *    "is a workshop day assigned for this issue?" was unanswerable per issue, and the
         *    Issues board had to answer it per machine instead.
         *
         *    Now the booking form lists the machine's open issues and the booker ticks which
         *    ones the visit covers (all ticked by default). Each ticked ticket points at this
         *    visit, moves to `scheduled`, and gets the line in its own thread.
         *
         * ⚠ NO SQL. `t_ops_vehicle_ticket.workshop_visit_id` already existed and is the
         *   many-side: N tickets → 1 visit. The visit's own `ticket_id` keeps meaning "the
         *   ticket this was raised from" so every existing reader is untouched.
         */
        $ids = $this->resolveTicketIds($in, $vehicleId);
        if (!$ids) return;
        try {
            $tickets = app(VehicleTicketService::class);
            if (!$tickets->available()) return;

            DB::table(VehicleTicketService::T_TICKET)->whereIn('id', $ids)->update([
                'workshop_visit_id' => $visitId,
                // ⚠ 'scheduled' outranks 'acknowledged'; VehicleTicketService::reply
                //   deliberately never knocks it back down when a manager chats.
                'status'            => 'scheduled',
                'last_message_at'   => now(),
                'updated_at'        => now(),
            ]);

            /**
             * ⚠ The visit remembers ONE originating ticket, because that is what its column
             *   means and what every older reader expects. Filled only if the caller did not
             *   name one, so a thread-first booking still records the thread it came from.
             */
            if (empty($in['ticket_id'])) {
                DB::table(self::T_VISIT)->where('id', $visitId)->whereNull('ticket_id')
                    ->update(['ticket_id' => $ids[0], 'updated_at' => now()]);
            }

            $line = 'Workshop visit ' . $verb . ' '
                . \Carbon\Carbon::parse($date)->format('D j M')
                . ($time ? ' at ' . substr((string) $time, 0, 5) : '')
                . ' by ' . $this->nameOf((int) $user->id) . '.';
            /**
             * ⚠ One line per THREAD, and it says when the visit covers more than this issue.
             *   A rider who reported three faults and sees one date should be able to tell
             *   whether all three are being looked at or only the one he is reading.
             */
            $extra = count($ids) > 1
                ? ' This visit also covers ' . (count($ids) - 1)
                  . ' other reported ' . (count($ids) === 2 ? 'issue' : 'issues') . ' on this bike.'
                : '';
            foreach ($ids as $tid) $tickets->system((int) $tid, $line . $extra);
        } catch (\Throwable $e) {
            Log::warning('Tickets not linked to workshop visit', ['visit' => $visitId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * ⭐⭐ WHICH TICKETS MAY THIS BOOKING LINK — and the security boundary for Part B.
     *
     * ⚠⚠ THE CLIENT'S LIST IS NEVER TRUSTED. `ticket_ids` arrives from a form, so every id is
     *    re-checked here against the MACHINE going in and against being open. Without this a
     *    crafted payload could drag an unrelated bike's complaint — or a closed one — onto a
     *    visit, move it to `scheduled`, and write a line into a thread the booker cannot see.
     *
     * ⚠ `ticket_id` (the old singular, sent by a thread-first booking and by older clients) is
     *   folded in, so nothing that works today stops working.
     *
     * ⚠⚠ …but ONLY when the booker was never asked. If `ticket_ids` is present the form put the
     *    question on screen, and its answer is the whole truth — folding the originating thread
     *    back in would mean a manager who deliberately unticked the very issue he pressed
     *    "Schedule workshop" from still had the trip booked against it. Ticked is covered; that
     *    is the one sentence this whole feature rests on.
     *
     * @return int[] validated, de-duplicated, in a stable order
     */
    private function resolveTicketIds(array $in, int $vehicleId): array
    {
        $asked = array_key_exists('ticket_ids', $in);
        $want  = [];
        if (!empty($in['ticket_id']) && !$asked) $want[] = (int) $in['ticket_id'];
        foreach ((array) ($in['ticket_ids'] ?? []) as $t) {
            if ((int) $t > 0) $want[] = (int) $t;
        }
        $want = array_values(array_unique($want));
        if (!$want) return [];

        try {
            $tickets = app(VehicleTicketService::class);
            if (!$tickets->available()) return [];
            $q = DB::table(VehicleTicketService::T_TICKET)
                ->whereIn('id', $want)
                ->whereIn('status', VehicleTicketService::OPEN_STATUSES);
            // ⚠ Vehicle check only when we know the machine — `approve()` re-links from a row
            //   that was already validated at booking time, and re-deriving it there would be
            //   a second place for the rule to live.
            if ($vehicleId) $q->where('vehicle_id', $vehicleId);
            return $q->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
        } catch (\Throwable $e) {
            Log::warning('Ticket ids not resolved for workshop visit', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Every ticket this visit is answering.
     *
     * ⚠ The UNION of the many-side (`workshop_visit_id`) and the visit's own originating
     *   `ticket_id`. Visits booked before Part B carry only the latter, and a sweep that
     *   forgot them would leave those threads saying "Workshop set" forever after the visit
     *   was cancelled.
     *
     * @return int[]
     */
    public function linkedTicketIds(int $visitId, ?array $visit = null): array
    {
        $ids = [];
        try {
            $tickets = app(VehicleTicketService::class);
            if (!$tickets->available()) return [];
            $ids = DB::table(VehicleTicketService::T_TICKET)
                ->where('workshop_visit_id', $visitId)->pluck('id')->map(fn ($i) => (int) $i)->all();
        } catch (\Throwable $e) {
            $ids = [];
        }
        $v = $visit ?? $this->find($visitId);
        if (!empty($v['ticket_id'])) $ids[] = (int) $v['ticket_id'];
        return array_values(array_unique($ids));
    }

    /**
     * The issues each of these visits is answering — ONE query for a whole page of cards.
     *
     * @param int[] $visitIds
     * @param int[] $originatingTicketIds the visits' own `ticket_id` values, so pre-Part-B rows
     *                                    (which have no many-side pointer) still report theirs
     * @return array<int, array<int, array{id:int,title:string,status:string,is_open:bool}>>
     */
    private function ticketsForVisits(array $visitIds, array $originatingTicketIds = []): array
    {
        if (!$visitIds) return [];
        try {
            $tickets = app(VehicleTicketService::class);
            if (!$tickets->available()) return [];

            $rows = DB::table(VehicleTicketService::T_TICKET)
                ->where(function ($w) use ($visitIds, $originatingTicketIds) {
                    $w->whereIn('workshop_visit_id', $visitIds);
                    if ($originatingTicketIds) $w->orWhereIn('id', $originatingTicketIds);
                })
                ->orderBy('id')
                ->get(['id', 'title', 'status', 'workshop_visit_id']);

            // id => the visit it belongs to, for the originating-only rows.
            $ownerOf = [];
            foreach ($visitIds as $vid) $ownerOf[$vid] = true;
            $byOriginating = [];
            foreach (DB::table(self::T_VISIT)->whereIn('id', $visitIds)
                        ->whereNotNull('ticket_id')->get(['id', 'ticket_id']) as $v) {
                $byOriginating[(int) $v->ticket_id] = (int) $v->id;
            }

            $out = [];
            foreach ($rows as $r) {
                $vid = $r->workshop_visit_id !== null && isset($ownerOf[(int) $r->workshop_visit_id])
                    ? (int) $r->workshop_visit_id
                    : ($byOriginating[(int) $r->id] ?? null);
                if (!$vid) continue;
                $shaped = ['id' => (int) $r->id, 'title' => (string) $r->title,
                           'status' => (string) $r->status,
                           'is_open' => in_array((string) $r->status, VehicleTicketService::OPEN_STATUSES, true)];
                // ⚠ De-duplicate: a ticket can be BOTH the originating one and the many-side.
                if (!isset($out[$vid]) || !in_array($shaped['id'], array_column($out[$vid], 'id'), true)) {
                    $out[$vid][] = $shaped;
                }
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('Visit tickets not resolved', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * ⭐⭐ A VISIT THAT IS NO LONGER HAPPENING MUST RELEASE ITS COMPLAINTS.
     *
     *    Cancel a visit and its tickets would otherwise sit at `scheduled` — "Workshop set" —
     *    pointing at a day nobody is going on. That is exactly the stuck state the Issues
     *    board exists to surface, manufactured by the system itself, and the rider reading the
     *    thread would have no way to know the plan had evaporated.
     *
     * @param int|null $movedTo when a newer visit replaces this one, the tickets MOVE to it
     *                          rather than being released — the complaint is still going in,
     *                          just on a different day.
     */
    private function releaseTickets(int $visitId, ?int $movedTo = null, ?array $visit = null): void
    {
        $ids = $this->linkedTicketIds($visitId, $visit);
        if (!$ids) return;
        try {
            if ($movedTo) {
                DB::table(VehicleTicketService::T_TICKET)->whereIn('id', $ids)
                    ->update(['workshop_visit_id' => $movedTo, 'updated_at' => now()]);
                return;
            }
            $this->detachTickets($ids, $visitId);
        } catch (\Throwable $e) {
            Log::warning('Tickets not released from workshop visit',
                ['visit' => $visitId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Put these complaints back in the queue, and unhook them from `$visitId`.
     *
     * ⚠ Back to `acknowledged`, NEVER to `open`. A manager has already answered these — that is
     *   what `acknowledged` records, and `first_response_at` is untouched — so resetting them to
     *   `open` would report them on the board as "nobody has replied" and reset a response time
     *   that was really met days ago.
     * ⚠ Only rows still sitting at `scheduled` are moved: one a manager has since closed stays
     *   closed.
     * ⚠⚠ …and the POINTER is cleared for ALL of them, whatever their status. A PROPOSAL stakes
     *    the link without moving any status (see `schedule()`), so a declined or withdrawn
     *    request would otherwise leave every ticked ticket pointing at a visit that will never
     *    happen — and the next booking's "which issues?" list would show them as already covered.
     * ⚠ BOTH writes are scoped to THIS visit, so a ticket already re-linked to a newer one is
     *   left alone — including the status write, which used to be unscoped.
     *
     * @param int[] $ids
     */
    private function detachTickets(array $ids, int $visitId): void
    {
        if (!$ids) return;
        DB::table(VehicleTicketService::T_TICKET)->whereIn('id', $ids)
            ->where('workshop_visit_id', $visitId)
            ->where('status', 'scheduled')
            ->update(['status' => 'acknowledged', 'updated_at' => now()]);
        DB::table(VehicleTicketService::T_TICKET)->whereIn('id', $ids)
            ->where('workshop_visit_id', $visitId)
            ->update(['workshop_visit_id' => null, 'updated_at' => now()]);
    }

    /**
     * ⭐⭐ WHAT HAPPENS TO THE OLD DAY'S COMPLAINTS WHEN A NEW DAY REPLACES IT (15-Sep-2026).
     *
     *    A superseded visit is not a cancellation — the bike is still going in, just on another
     *    date — so by default its complaints FOLLOW the new day rather than being dropped back
     *    into the queue and having to be ticked all over again.
     *
     * ⚠⚠ …but AN UNTICK MUST MEAN SOMETHING. The booking form promises "untick anything this
     *    trip is not for — it stays in the queue", and a blanket move made that sentence false:
     *    a manager who deliberately narrowed the new day still had every old complaint dragged
     *    onto it, reading "Workshop set" against a trip it was never for.
     *
     * ⚠⚠ AN OLD CLIENT IS NOT AN EMPTY ANSWER. A phone built before Part B sends no `ticket_ids`
     *    key at all — it was never ASKED, so it cannot have unticked anything, and reading its
     *    silence as "cover nothing" would silently unhook every complaint on every re-booking
     *    made from a stale APK. The presence of the KEY is the signal, which is why both clients
     *    now send it — possibly empty — whenever the question was actually put on screen.
     */
    private function carryTicketsOver(int $from, int $to, array $fromVisit, array $in, int $vehicleId): void
    {
        // Never asked ⇒ cannot have answered. Move everything, exactly as before Part B.
        if (!array_key_exists('ticket_ids', $in)) {
            $this->releaseTickets($from, $to, $fromVisit);
            return;
        }

        $had = $this->linkedTicketIds($from, $fromVisit);
        // ⚠ The same validated set the link/stake step will use — never the raw body.
        $keep = $this->resolveTicketIds($in, $vehicleId);

        // Everything follows first, so the new day owns the pointers…
        $this->releaseTickets($from, $to, $fromVisit);
        // …and then the ones he deliberately left behind are let go of, from the NEW visit.
        try {
            $this->detachTickets(array_values(array_diff($had, $keep)), $to);
        } catch (\Throwable $e) {
            Log::warning('Unticked complaints not released from the replacing visit',
                ['from' => $from, 'to' => $to, 'error' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  ACCEPTING
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⭐⭐ OWNER RULING (2-Sep): the RIDER accepts. A manager may stand in only while the
     *    visit is still awaiting acceptance, and it is recorded as `accepted_via=manager`
     *    so no screen can present it as the rider's own confirmation.
     */
    public function accept($user, int $visitId, bool $mobile = false): array
    {
        if (!$this->available()) return ['ok' => false, 'message' => 'Workshop visits are not set up yet.'];
        $v = $this->find($visitId);
        if (!$v) return ['ok' => false, 'message' => 'That visit no longer exists.'];

        $uid       = (int) $user->id;
        $isRider   = (int) $v['user_id'] === $uid;
        $isManager = $this->canSchedule($user, $mobile);

        if (!$isRider && !$isManager) {
            return ['ok' => false, 'message' => 'That visit is not yours.'];
        }
        if ($v['status'] === 'accepted') return ['ok' => false, 'message' => 'Already accepted.'];
        if (!in_array($v['status'], self::LIVE_STATUSES, true)) {
            return ['ok' => false, 'message' => 'That visit is no longer active.'];
        }

        try {
            DB::table(self::T_VISIT)->where('id', $visitId)->update([
                'status'       => 'accepted',
                'accepted_at'  => now(),
                'accepted_via' => $isRider ? 'app' : 'manager',
                'accepted_by'  => $uid,
                'updated_at'   => now(),
            ]);
            return ['ok' => true, 'message' => $isRider
                ? 'Confirmed. You are expected at the workshop that day.'
                : 'Marked as accepted for ' . $this->nameOf((int) $v['user_id']) . '.'];
        } catch (\Throwable $e) {
            Log::error('WorkshopVisitService::accept failed', ['visit' => $visitId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not confirm it.'];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  APPROVAL — the 6-Sep ruling. A booked workshop day is a REQUEST until a shift
    //  planner says yes; only then is anything pinned and only then is the rider told.
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⭐⭐ APPROVE, optionally ADJUSTING it on the way through.
     *
     * A planner may change three things while approving, because he is the person who
     * knows the day:
     *   • which workshop  — `location_id` (must be a REGISTERED one, or nothing can pin)
     *   • the appointment — `visit_time`
     *   • the rider's start time that day — `shift_template_id`. ⭐ This is the case
     *     Danish exposed on 6-Sep: his visit was booked for 09:00 while his shift still
     *     began 09:30, so even a correct pin would have measured him against the wrong
     *     hour. Nothing else in the flow could fix that, and the planner is exactly who
     *     should.
     *
     * ⚠ Approving is the ONLY thing that writes the pin, tells the rider, and touches the
     *   ticket thread. Everything the rider can see hangs off this one call.
     */
    public function approve($user, int $visitId, array $in = [], bool $mobile = false): array
    {
        if (!$this->available())        return ['ok' => false, 'message' => 'Workshop visits are not set up yet.'];
        if (!$this->approvalEnabled())  return ['ok' => false, 'message' => 'The approval step is not installed yet (workshop_approval_sep2026.sql has not been run).'];
        if (!$this->canApprove($user, $mobile)) {
            return ['ok' => false, 'message' => 'Only a shift planner can approve a workshop day.'];
        }

        $v = $this->find($visitId);
        if (!$v) return ['ok' => false, 'message' => 'That request no longer exists.'];
        if ((string) $v['status'] !== 'proposed') {
            // ⚠ Three planners see the same banner; two of them will press it at once.
            //   Say which of the two things happened rather than a flat refusal.
            return ['ok' => false, 'message' => (string) $v['status'] === 'scheduled'
                ? 'Someone has already approved that one.'
                : 'That request is no longer waiting — it is ' . $v['status'] . '.'];
        }

        $date   = substr((string) $v['visit_date'], 0, 10);
        $today  = \Carbon\Carbon::today()->format('Y-m-d');
        if ($date < $today) {
            // ⚠ Approving a dead date would tell a rider to go somewhere yesterday.
            return ['ok' => false, 'message' => 'That day has already passed — decline it and book a new date.'];
        }
        /**
         * ⚠⚠ AND THE SAME-DAY CUT-OFF (6-Sep review). Without this a planner could approve at
         *    11:00 for a 09:30 shift the rider has already checked in for at LaCarne — the pin
         *    would move under him and he would read as remote where he actually is. Same clock
         *    as the sweep, so "too late" means one thing.
         */
        /**
         * ⭐ 10-Sep: TODAY has no cut-off (see `hasApprovalCutoff`). A same-day request is a
         *   breakdown, and the check-in decision it used to protect is already settled.
         */
        if ($this->hasApprovalCutoff($v)) {
            $cutoff = $this->approvalCutoffFor($v);
            if (\Carbon\Carbon::now()->gte($cutoff)) {
                return ['ok' => false, 'message' => 'Too late for ' . \Carbon\Carbon::parse($date)->format('D j M')
                    . ' — the cut-off was ' . $cutoff->format('H:i') . ', so he could not have been told in time. '
                    . 'Decline it and book a new date.'];
            }
        }

        // ── the adjustments ──────────────────────────────────────────────────────
        $locationId = array_key_exists('location_id', $in) && $in['location_id'] !== null && $in['location_id'] !== ''
            ? (int) $in['location_id']
            : (!empty($v['location_id']) ? (int) $v['location_id'] : null);
        if ($locationId && !$this->isWorkshopLocation($locationId)) {
            return ['ok' => false, 'message' => 'That place is not ticked as a workshop, so his day could not be pinned to it.'];
        }

        /**
         * 📍 WHERE DOES HE CHECK IN? (owner ask, 10-Sep) — asked of the approver, never inferred.
         *
         * ⚠⚠ The old rule was "pin it if a registered workshop was chosen", which conflated two
         *    different decisions: WHICH workshop, and WHETHER his day starts there. A planner who
         *    wanted "check in at LaCarne as usual, ride over at 11" had no way to say so, and the
         *    rider was told his place had changed when it had not.
         *
         * ⚠ Falls back to the visit's own value, then to the old inference — so an approval sent
         *   by a client that predates this behaves exactly as before.
         */
        $attendanceAt = (string) ($in['attendance_at'] ?? ($v['attendance_at'] ?? ''));
        if (!in_array($attendanceAt, ['regular', 'workshop'], true)) {
            $attendanceAt = $locationId ? 'workshop' : 'regular';
        }
        /**
         * ⚠⚠ …EXCEPT TODAY, WHATEVER WAS SENT. `book()` forces `regular` on a same-day
         *    booking because the man has already started his day somewhere; approving one
         *    an hour later must obey the same rule, or a planner pressing the wrong half of
         *    the choice would move a check-in place backwards in time and mark him remote —
         *    or late — for a place nobody had told him to go to when he clocked in.
         *    The card does not offer the choice for today; this is the server refusing to
         *    take it from anything that does.
         */
        if ($date === $today) $attendanceAt = 'regular';
        if ($attendanceAt === 'workshop' && !$locationId) {
            return ['ok' => false, 'message' =>
                'To have him check in at the workshop, pick a registered workshop first — '
                . 'a typed name has no place to measure his arrival against.'];
        }
        // ⭐ "Check in as usual" means exactly that: no pin is written, whatever workshop was
        //   picked. The pin is the ONLY thing this choice controls.
        $pinLocationId = $attendanceAt === 'workshop' ? $locationId : null;

        $time = $v['visit_time'];
        if (array_key_exists('visit_time', $in)) {
            $t = trim((string) $in['visit_time']);
            if ($t !== '' && !preg_match('/^\d{2}:\d{2}$/', $t)) {
                return ['ok' => false, 'message' => 'Give the time as HH:MM.'];
            }
            $time = $t !== '' ? $t : null;
        }

        $templateId = !empty($in['shift_template_id']) ? (int) $in['shift_template_id'] : null;
        if ($templateId && !$this->shiftTemplateExists($templateId)) {
            return ['ok' => false, 'message' => 'That shift does not exist any more.'];
        }

        try {
            $riderId = (int) $v['user_id'];
            $claimed = DB::table(self::T_VISIT)->where('id', $visitId)->where('status', 'proposed')->update([
                'status'      => 'scheduled',
                'location_id' => $locationId,
                'visit_time'  => $time,
                'attendance_at' => $this->tripEnabled() ? $attendanceAt : null,
                'approved_by' => (int) $user->id,
                'approved_at' => now(),
                /**
                 * ⚠⚠ CLEAR THE NUDGE FLAG. `reminded_at` is shared with the day-before rider
                 *    reminder (one column, two sweeps — see `escalateProposals`). A proposal
                 *    that was nudged at 17:00 yesterday would otherwise have this set, and
                 *    `dueReminders()` would skip the rider's "kal workshop jana hai" for the
                 *    very visit that most needs it.
                 */
                'reminded_at' => null,
                'updated_at'  => now(),
            ]);

            /**
             * ⚠⚠ THE UPDATE IS THE CLAIM — read whether it actually won. `where(status =
             *    proposed)` was already the right filter, but the affected-row count was
             *    thrown away, so TWO planners pressing Approve within the same moment both
             *    fell through to the pin, the ticket line and the pushes: the rider got told
             *    twice, the booker got told twice, and the thread grew two "approved for"
             *    lines for one decision. Losing the race is not an error — it just means
             *    somebody else already said yes.
             */
            if (!$claimed) {
                return ['ok' => false, 'message' => 'Someone else has just answered this one.'];
            }

            /**
             * ⭐⭐ THE SWAP HAPPENS HERE, NOT AT BOOKING (owner ruling 7-Sep). If this bike
             *    still has an APPROVED day standing, approving the replacement is the moment
             *    it is retired: its pin is cleared, it is marked `rescheduled`, and the rider
             *    is told his day MOVED rather than being left believing the old one.
             * ⚠ Captured BEFORE the pin is written, or the two would fight over the same day.
             */
            $movedFrom = $this->liveVisitFor((int) ($v['vehicle_id'] ?? 0), $visitId);
            if ($movedFrom) {
                DB::table(self::T_VISIT)->where('id', $movedFrom['id'])->update([
                    'status'        => 'rescheduled',
                    'superseded_by' => $visitId,
                    'updated_at'    => now(),
                ]);
                $this->clearShiftLocation((int) $movedFrom['id'], $riderId);
                /**
                 * ⭐ PART B: complaints the OLD day was answering move to the new one, unless
                 *   this proposal staked them for itself already (releaseTickets is scoped to
                 *   the visit it is releasing, so a ticket both of them named is not stolen back).
                 */
                $this->releaseTickets((int) $movedFrom['id'], $visitId, $movedFrom);
            }

            // NOW the two things a proposal deliberately did not do.
            /**
             * ⚠ `$pinLocationId`, not `$locationId`: "he checks in as usual" must not pin, even
             *   though a workshop was chosen for the errand itself.
             */
            $pinned  = $this->applyShiftLocation($visitId, $riderId, $date, $pinLocationId, $templateId, $user);
            $pinNote = $this->lastPinNote;
            // A deliberate "check in as usual" is not a failure to pin, so it must not be
            // reported as one — that note is for when the pin was WANTED and did not happen.
            if ($attendanceAt === 'regular') $pinNote = null;
            /**
             * ⭐⭐ PART B: approval COMPLETES the link the proposal staked. The ticked list was
             *    recorded as pointers at booking time and nothing else; this is the moment the
             *    tickets move to `scheduled` and their threads are finally written to — which
             *    is precisely the 6-Sep ruling about when a rider may learn of a date.
             * ⚠ `linkedTicketIds` also picks up the visit's own originating `ticket_id`, so a
             *   proposal raised from a thread by an OLD client still links exactly as before.
             * ⚠ No vehicle filter: these ids were validated against the machine at booking time,
             *   and re-deriving the rule here would be a second place for it to live.
             */
            $this->linkTicket($visitId, ['ticket_ids' => $this->linkedTicketIds($visitId, $v)],
                              $user, $date, $time, 'approved for');

            return [
                'ok'         => true,
                'visit_id'   => $visitId,
                'rider_id'   => $riderId,
                'pinned'     => $pinned,
                'booked_by'  => (int) ($v['proposed_by'] ?? $v['created_by'] ?? 0),
                /**
                 * ⚠⚠ A DECISION IS NOT A WARNING (found on the device, 10-Sep-2026). Choosing
                 *    "he checks in as usual" fell through to the ⚠ branch, so the planner was
                 *    told "⚠ His check-in place was not changed" — his own answer, reported
                 *    back as though something had failed. The ⚠ is for the case where a pin
                 *    was WANTED and did not happen (no workshop, a hand-made shift row, no
                 *    shift that day); `$pinNote` carries that reason and is nulled above when
                 *    the choice was deliberate.
                 */
                'message'    => 'Approved. ' . $this->nameOf($riderId) . ' has been told'
                    . ($pinned
                        ? ' — that day he checks in at the workshop, so he will not be marked late or remote.'
                        : ($attendanceAt === 'regular'
                            ? ' — he checks in as usual and rides over afterwards, so nothing about his day moves.'
                            : '. ⚠ ' . ($pinNote ?: 'His check-in place was not changed.'))),
            ];
        } catch (\Throwable $e) {
            Log::error('WorkshopVisitService::approve failed', ['visit' => $visitId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not approve it.'];
        }
    }

    /**
     * The planner says no. The reason travels back to whoever booked it, so he can
     * re-propose a day that works instead of guessing.
     *
     * ⚠ Nothing was pinned and the rider was never told, so declining has NOTHING to undo
     *   — which is the whole reason the proposal state is worth having.
     */
    public function decline($user, int $visitId, ?string $reason, bool $mobile = false): array
    {
        if (!$this->available())       return ['ok' => false, 'message' => 'Workshop visits are not set up yet.'];
        if (!$this->approvalEnabled()) return ['ok' => false, 'message' => 'The approval step is not installed yet.'];
        if (!$this->canApprove($user, $mobile)) {
            return ['ok' => false, 'message' => 'Only a shift planner can decline a workshop day.'];
        }
        $v = $this->find($visitId);
        if (!$v) return ['ok' => false, 'message' => 'That request no longer exists.'];
        if ((string) $v['status'] !== 'proposed') {
            return ['ok' => false, 'message' => 'That request is no longer waiting — it is ' . $v['status'] . '.'];
        }
        if (!$this->declineRow($visitId, (int) $user->id, $reason ?: 'No reason given.')) {
            return ['ok' => false, 'message' => 'Could not decline it.'];
        }
        return [
            'ok'        => true,
            'visit_id'  => $visitId,
            'booked_by' => (int) ($v['proposed_by'] ?? $v['created_by'] ?? 0),
            'message'   => 'Declined. ' . $this->nameOf((int) ($v['proposed_by'] ?? $v['created_by'] ?? 0))
                           . ' has been told, and ' . $this->nameOf((int) $v['user_id']) . ' was never told anything.',
        ];
    }

    /**
     * The one writer for "this proposal is dead". Used by the planner's Decline button and
     * by the morning sweep, so the two can never drift apart.
     * `$byUserId` is NULL when it was time, not a person, that killed it.
     */
    private function declineRow(int $visitId, ?int $byUserId, string $reason): bool
    {
        try {
            $n = DB::table(self::T_VISIT)->where('id', $visitId)->where('status', 'proposed')->update([
                'status'         => 'declined',
                'declined_by'    => $byUserId,
                'declined_at'    => now(),
                'decline_reason' => mb_substr(trim($reason), 0, 255),
                'updated_at'     => now(),
            ]);
            /**
             * ⭐⭐ PART B: a dead proposal RELEASES the issues it staked.
             *
             * ⚠ The update above is the claim — only the winner releases, so two planners (or a
             *   planner and the morning sweep) racing cannot have one of them unhook tickets
             *   that the other has just approved onto a live day.
             * ⚠ Silent by design: a proposal never wrote a line into any thread, so its death
             *   must not write one either — that would announce a plan by killing it.
             *   `releaseTickets` only touches pointers and `scheduled` rows, and a staked
             *   proposal moved no status, so nothing the rider can see changes at all.
             */
            if ($n > 0) $this->releaseTickets($visitId);
            return $n > 0;
        } catch (\Throwable $e) {
            Log::error('WorkshopVisitService::declineRow failed', ['visit' => $visitId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** Is this location one a workshop day can actually be pinned to? */
    public function isWorkshopLocation(int $locationId): bool
    {
        if (!$this->locationsEnabled()) return false;
        try {
            return DB::table('t_ops_company_locations')
                ->where('id', $locationId)->where('is_active', 1)->where('is_workshop', 1)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function shiftTemplateExists(int $templateId): bool
    {
        try {
            return DB::table('t_ops_shift_template')->where('id', $templateId)->where('active', 1)
                ->when($this->shiftTypesCarryApproval(), fn ($q) => $q->where('approval_status', 'approved'))
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The shifts a planner may put the rider on for that one day (the Adjust picker).
     *
     * ⚠⚠ A shift TYPE still WAITING for approval must never appear here. Since the
     *    shift-authority round (Sep-2026) a type can be `proposed`, and every other picker in
     *    the app filters those out — this one did not, so a planner could pin a rider onto a
     *    set of hours nobody had approved, through a door the shift rules do not watch.
     *    Schema-guarded: before `shift_authority_sep2026.sql` the column does not exist and
     *    every type is assignable, exactly as before.
     */
    public function shiftTemplates(): array
    {
        try {
            return DB::table('t_ops_shift_template')->where('active', 1)
                ->when($this->shiftTypesCarryApproval(), fn ($q) => $q->where('approval_status', 'approved'))
                ->orderBy('shift_start')
                ->get(['id', 'shift_name', 'shift_start'])
                ->map(fn ($t) => [
                    'id'    => (int) $t->id,
                    'name'  => (string) $t->shift_name,
                    'start' => $t->shift_start ? substr((string) $t->shift_start, 0, 5) : null,
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Does ANYONE hold the approve permission? Checks both halves — a planner reaching the
     * desk needs the web row, one reaching it from the phone needs the mobile row, and either
     * is enough to mean "somebody can answer a proposal".
     * ⚠ Memoized per request; this is asked from a poll, not from a loop.
     */
    private ?bool $anyApprover = null;
    private function anyoneCanApprove(): bool
    {
        if ($this->anyApprover !== null) return $this->anyApprover;
        $found = false;
        try {
            $found = DB::table('t_sys_role_permissions')
                ->where('permission_key', self::APPROVE_PERMISSION)->where('is_allowed', 1)->exists();
            if (!$found) {
                $found = DB::table('t_sys_role_mobile_permission as rmp')
                    ->join('t_sys_mobile_permission as mp', 'mp.id', '=', 'rmp.mobile_permission_id')
                    ->where('mp.permission_code', self::APPROVE_PERMISSION)->exists();
            }
        } catch (\Throwable $e) {
            // If the question cannot be answered, assume somebody can — the old behaviour.
            $found = true;
        }
        return $this->anyApprover = $found;
    }

    /** Memoized: do shift types carry an approval state on this server yet? */
    private ?bool $shiftApprovalCol = null;
    private function shiftTypesCarryApproval(): bool
    {
        if ($this->shiftApprovalCol === null) {
            try { $this->shiftApprovalCol = Schema::hasColumn('t_ops_shift_template', 'approval_status'); }
            catch (\Throwable $e) { $this->shiftApprovalCol = false; }
        }
        return $this->shiftApprovalCol;
    }

    /**
     * What the planners' banner shows: every workshop day still waiting on one of them,
     * soonest first, each carrying the same warnings the booker was shown — the planner is
     * the person who should see "that is his day off" before he says yes.
     *
     * ⚠ Returns [] for anyone who is not a planner. This is a list of other people's days.
     */
    public function pendingApprovals($user, bool $mobile = false, int $limit = 20): array
    {
        if (!$this->approvalEnabled() || !$this->canApprove($user, $mobile)) return [];
        $rows = $this->listVisits(['statuses' => ['proposed'], 'limit' => $limit]);

        /**
         * ⏰⭐⭐ WHAT THIS USER HAS PUT OFF FOR SIX HOURS (owner ask, 11-Sep-2026).
         *
         * ⚠⚠ THE AUDIENCE IS FLAT AND CANNOT BE NARROWED. Everyone holding `manage_shifts`
         *    sees every proposal, because the deciders are not always the same people — the
         *    owner's example is Taimur, who only needs to KNOW, except on the days Farooq is
         *    on leave and he must actually decide. Removing him from the audience would break
         *    exactly the case the audience exists for. So the fix is not a smaller audience,
         *    it is letting a man say "not me, not now" without hiding it from anybody else.
         *
         * ⭐ Per USER and server-side, so it follows him across his phone and the web, and one
         *   planner's snooze never hides a live request from the rest.
         * ⚠ Decided visits need nothing here: approve/decline moves the row off `proposed`,
         *   so it leaves EVERY list at once. That half already worked.
         */
        $rows = $this->minusSnoozed($rows, (int) ($user->id ?? 0));

        /**
         * ⚠⚠ A PLANNER IS NOT ASKED TO APPROVE HIS OWN WORKSHOP DAY (open ruling 4 from
         *    6-Sep, closed 11-Sep). `manage_shifts` includes people who also ride, so a
         *    planner booked in for his own bike was shown "⏳ needs your approval" about
         *    himself and could wave it through. Self-approval is the one thing an approval
         *    queue exists to prevent.
         */
        $uid  = (int) ($user->id ?? 0);
        $rows = array_values(array_filter($rows, fn ($r) => (int) ($r['user_id'] ?? 0) !== $uid));

        foreach ($rows as &$r) {
            $r['warnings'] = $this->warningsFor((int) $r['vehicle_id'], (int) $r['user_id'], $r['visit_date']);
            // ⏰ So the card can say "decide by 08:30" instead of leaving the planner to guess.
            // ⚠ null for TODAY — there is no deadline to print, and showing yesterday's
            //   08:00 beside a live request would read as "already dead".
            $r['approve_by'] = $this->hasApprovalCutoff($r)
                ? $this->approvalCutoffFor($r)->format('Y-m-d H:i') : null;
            /**
             * ⭐ WHAT APPROVING WOULD REPLACE. The approver must know he is not just saying
             *   yes to a new day — he is retiring one the rider has already been told about,
             *   and that rider has to be told again. Without this the card looks identical
             *   whether it is a first booking or a move.
             */
            $live = $this->liveVisitFor((int) $r['vehicle_id'], (int) $r['id']);
            $r['replaces'] = $live ? [
                'visit_date' => $live['visit_date'],
                'visit_time' => $live['visit_time'],
                'workshop'   => $live['workshop'],
                'accepted'   => $live['accepted'],
                'label'      => \Carbon\Carbon::parse($live['visit_date'])->format('D j M')
                                . ($live['visit_time'] ? ' ' . $live['visit_time'] : '')
                                . ($live['workshop'] ? ' · ' . $live['workshop'] : ''),
            ] : null;
            // 📍 "Will he mark attendance at his regular place, or at the workshop?" now
            //    rides on shape() for every proposal (see the note there), so EVERY door that
            //    can approve asks it — not just this card.
        }
        unset($r);
        return $rows;
    }

    /**
     * What the approval card should show for "where does he check in that day?".
     *
     * ⭐ `value` is what will happen if the approver presses ✓ without touching anything —
     *   the proposal's own answer if the booker gave one, else the pre-10-Sep inference
     *   ("a registered workshop was chosen ⇒ pin it"). So the card never states one thing
     *   and the server does another.
     * ⚠ `asked` is false for TODAY: he has already started his day where he started it, and
     *   moving his check-in place retrospectively would mark a man late for a place he was
     *   never told to go to. `book()` forces `regular` for today, and `approve()` does too.
     * ⚠ `can_pin` is false with no registered workshop — a typed name has no coordinates to
     *   measure an arrival against, which is exactly why `approve()` refuses that pair.
     */
    private function attendanceChoiceFor(array $r): array
    {
        $isToday  = substr((string) $r['visit_date'], 0, 10) === \Carbon\Carbon::today()->format('Y-m-d');
        $canPin   = !empty($r['location_id']);
        // ⭐ ONE rule for "where would he check in", shared with the push and every card.
        $value    = self::checkinAtOf($r);
        if ($isToday || !$canPin) $value = 'regular';

        $regular = null;
        try {
            $shift = (new ShiftResolutionService())->getUserShift((int) $r['user_id'],
                substr((string) $r['visit_date'], 0, 10));
            $regular = $shift['location_name'] ?? null;
        } catch (\Throwable $e) {
            // The name is a courtesy on a label — never a reason the card fails to draw.
        }

        return [
            'asked'          => $this->tripEnabled() && !$isToday,
            'value'          => $value,
            'can_pin'        => $canPin,
            'is_today'       => $isToday,
            'regular_label'  => $regular ?: 'his usual place',
            // ⚠ `location_name` rides on the row now (see listVisits), so this costs no query.
            'workshop_label' => $r['workshop']
                ?: ($r['location_name'] ?? ($this->locationNameFor($r['location_id'] ?? null) ?: 'the workshop')),
        ];
    }

    /**
     * ⭐⭐ SILENCE MUST NEVER SEND A RIDER ANYWHERE — and it must never quietly cancel a
     *    plan either. Prod has no cron (see the class note), so this rides the alerts poll
     *    exactly as `dueReminders()` does:
     *
     *      • a proposal for TOMORROW, still waiting at or after 17:00 → nudge the planners
     *        ONCE (flagged with `reminded_at`, which a proposal does not otherwise use;
     *        `approve()` clears it again so the rider's own day-before reminder still fires);
     *      • a proposal whose day has ARRIVED unapproved → auto-DECLINE, and tell the booker.
     *
     * ⚠⚠ AUTO-DECLINE, NEVER AUTO-APPROVE. An unapproved proposal is a question nobody
     *    answered; turning that into "go to the workshop tomorrow" would send a rider to a
     *    place no planner ever agreed to, on the strength of nobody having looked.
     * ⚠ A proposal made TODAY for TODAY is left alone — it has not had its day yet. Only one
     *   that has been waiting since before today is killed by the morning.
     *
     * @return array{nudge: array<int,int>, declined: array<int,int>}
     */
    public function escalateProposals(): array
    {
        $out = ['nudge' => [], 'declined' => []];
        if (!$this->approvalEnabled()) return $out;

        /**
         * ⚠⚠ A DEPLOY LANDMINE, GUARDED HERE. `approvalEnabled()` only asks whether PART 1 of
         *    `workshop_approval_sep2026.sql` (the columns) has run. PART 2 seeds the WEB half
         *    of `manage_shifts`, and the file's own note says a re-run dies mid-way on
         *    `1060 Duplicate column name` — so "columns applied, permission not" is a live
         *    possibility. In that state `canApprove()` is false for everyone on the desk:
         *    every booking becomes a proposal, nobody can answer one, and this sweep would
         *    quietly auto-decline the lot, an hour before each rider's shift.
         *
         *    So: if NOBODY can approve, the round is misconfigured, not overdue. Leave the
         *    proposals standing — they pile up visibly, which is how the misconfiguration
         *    gets noticed — and never turn silence into a decision.
         */
        if (!$this->anyoneCanApprove()) {
            Log::warning('Workshop proposals not escalated: nobody holds the approve permission '
                . '(is PART 2 of workshop_approval_sep2026.sql applied?)');
            return $out;
        }
        try {
            $now      = \Carbon\Carbon::now();
            $today    = $now->copy()->startOfDay();
            $tomorrow = $today->copy()->addDay()->format('Y-m-d');

            if ((int) $now->format('H') >= 17) {
                $ids = DB::table(self::T_VISIT)
                    ->where('status', 'proposed')->whereNull('reminded_at')
                    ->whereDate('visit_date', $tomorrow)
                    ->pluck('id')->map('intval')->all();
                if ($ids) {
                    DB::table(self::T_VISIT)->whereIn('id', $ids)->update(['reminded_at' => now()]);
                    $out['nudge'] = $ids;
                }
            }

            /**
             * ⭐ OWNER RULING (6-Sep, on review): give the planners until just before the
             *   rider's shift, not just until midnight. Candidates are every proposal dated
             *   today-or-earlier; each is then measured against ITS OWN cut-off (below),
             *   because "before his shift" is a different clock for every rider.
             */
            $candidates = DB::table(self::T_VISIT)
                ->where('status', 'proposed')
                ->whereDate('visit_date', '<=', $today->format('Y-m-d'))
                ->get(['id', 'user_id', 'visit_date', 'visit_time']);
            $todayStr = $today->format('Y-m-d');
            foreach ($candidates as $c) {
                $cd = substr((string) $c->visit_date, 0, 10);

                /**
                 * ⚠⚠ THE DAY IS GONE. A proposal for a date already past cannot be gone on by
                 *    anybody, so it is closed with a reason. Before 10-Sep the cut-off sweep
                 *    happened to catch these on the way through; now that TODAY has no cut-off
                 *    (below) they would otherwise sit in the planners' queue for ever.
                 */
                if ($cd < $todayStr) {
                    if ($this->declineRow((int) $c->id, null,
                            'The day passed and nobody approved it.')) {
                        $out['declined'][] = (int) $c->id;
                    }
                    continue;
                }

                /**
                 * ⚠⚠ A TODAY PROPOSAL IS NEVER AUTO-DECLINED (owner ruling, 10-Sep). It has no
                 *    cut-off any more, so there is no moment at which it "expired" — killing it
                 *    on this sweep would delete a live breakdown request out from under the
                 *    planners. It stays in their queue until somebody answers; if he goes home
                 *    without going, `alertNotGoneAtCheckout()` tells them once and it ends there.
                 */
                if (!$this->hasApprovalCutoff((array) $c)) continue;

                // A future day CAN still pass its cut-off when the lead time is long enough to
                // put it on the previous evening — that is the case this branch still serves.
                if ($now->lt($this->approvalCutoffFor((array) $c))) continue;
                if ($this->declineRow((int) $c->id, null,
                        'Not approved in time — nobody approved it before his shift.')) {
                    $out['declined'][] = (int) $c->id;
                }
            }
            if ($out['nudge'] || $out['declined']) {
                Log::info('Workshop proposals escalated', $out);
            }
        } catch (\Throwable $e) {
            Log::warning('WorkshopVisitService::escalateProposals failed', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    /**
     * ⭐⭐ THE LAST MOMENT A PROPOSAL CAN STILL BE APPROVED (owner ruling, 6-Sep review):
     *    "right before the assigned shift time — give time till then".
     *
     * Not the shift start itself, but a LEAD before it, because the point of telling the
     * rider is that he rides to the workshop instead of his usual place — so he has to hear
     * before he leaves home. An approval that lands after he has already checked in at
     * LaCarne cannot move that check-in, and would pin his day to a workshop he is not at.
     *
     *   cut-off = min(shift start that day, the appointment time) − WORKSHOP_APPROVAL_LEAD_MIN
     *
     * `WORKSHOP_APPROVAL_LEAD_MIN` lives in `t_fin_config` (default 60) so the owner can change
     * it without a code change, like the other operational knobs. If he has no shift that day
     * (off day, holiday) and no appointment time, 08:00 stands in — nobody should be sent
     * anywhere on an off day by silence either.
     *
     * ⚠ ONE clock for both the morning sweep (`escalateProposals`) and `approve()` — a planner
     *   pressing Approve after the cut-off is refused for the same reason the sweep declines.
     *   Two clocks here would let the answer depend on which one ran first.
     */
    /**
     * ⭐⭐ IS THERE A CUT-OFF AT ALL? (owner ruling, 10-Sep-2026: *"for same day bookings no
     *    need for cut off time"*.)
     *
     * The cut-off exists to protect ONE thing: the rider must hear about a change to where
     * he checks in BEFORE he leaves home. For a booking made for TODAY that decision is
     * already behind us — he has checked in, or he has not, and no approval can change it.
     * Holding a same-day breakdown to "should have been decided by 08:00" simply meant the
     * bike could not be sent in on the day it broke, which is the case this whole round is
     * about.
     *
     * ⚠ What replaces it is not silence: if he checks out having never set off, the managers
     *   are told once (`alertNotGoneAtCheckout`) and it ends there.
     *
     * A FUTURE day keeps its cut-off exactly as before — there, the promise is real.
     */
    public function hasApprovalCutoff(array $v): bool
    {
        return substr((string) ($v['visit_date'] ?? ''), 0, 10)
            > \Carbon\Carbon::today()->format('Y-m-d');
    }

    public function approvalCutoffFor(array $v): \Carbon\Carbon
    {
        $date  = substr((string) $v['visit_date'], 0, 10);
        $start = null;
        try {
            $s = (new ShiftResolutionService())->getUserShift((int) $v['user_id'], $date);
            $start = !empty($s['shift_start']) ? substr((string) $s['shift_start'], 0, 5) : null;
        } catch (\Throwable $e) {
        }
        $appt = !empty($v['visit_time']) ? substr((string) $v['visit_time'], 0, 5) : null;
        $times = array_values(array_filter([$start, $appt]));
        $at = $times ? min($times) : '08:00';
        $lead = (int) $this->cfg('WORKSHOP_APPROVAL_LEAD_MIN', 60);
        if ($lead < 0 || $lead > 720) $lead = 60;
        return \Carbon\Carbon::parse($date . ' ' . $at . ':00')->subMinutes($lead);
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

    // ─────────────────────────────────────────────────────────────────────────────

    public function cancel($user, int $visitId, ?string $reason, bool $mobile = false): array
    {
        if (!$this->available()) return ['ok' => false, 'message' => 'Workshop visits are not set up yet.'];
        if (!$this->canSchedule($user, $mobile)) {
            return ['ok' => false, 'message' => 'You cannot change workshop visits.'];
        }
        $v = $this->find($visitId);
        if (!$v) return ['ok' => false, 'message' => 'That visit no longer exists.'];
        // ⚠ A PROPOSAL is cancellable too — the person who booked it may realise the bike
        //   is not going after all, and it should not sit in three planners' banners
        //   waiting to be declined for a reason nobody has any more.
        if (!in_array($v['status'], self::OPEN_STATUSES, true)) {
            return ['ok' => false, 'message' => 'That visit is no longer active.'];
        }
        try {
            DB::table(self::T_VISIT)->where('id', $visitId)->update([
                'status'       => 'cancelled',
                'outcome_note' => $reason ? mb_substr(trim($reason), 0, 500) : null,
                'updated_at'   => now(),
            ]);
            // 📍 A cancelled visit must not leave his shift pointing at a workshop he is no
            //   longer going to — that would measure his check-in against the wrong place.
            $this->clearShiftLocation($visitId, (int) $v['user_id']);
            /**
             * ⚠⚠ NOT FOR A PROPOSAL. The rider reads this thread, and a proposal never wrote
             *    anything into it (see `schedule()`), so "Workshop visit cancelled" would be
             *    the FIRST he ever heard of a visit — announcing a plan by cancelling it.
             */
            $linked = $this->linkedTicketIds($visitId, $v);
            if ($linked && (string) $v['status'] !== 'proposed') {
                // ⭐ PART B: every issue this visit was answering hears it, not just the one
                //   it happened to be raised from.
                foreach ($linked as $tid) {
                    app(VehicleTicketService::class)->system((int) $tid,
                        'Workshop visit cancelled by ' . $this->nameOf((int) $user->id)
                        . ($reason ? ' — ' . $reason : '') . '.');
                }
            }
            /**
             * ⭐⭐ PART B: AND THE COMPLAINTS GO BACK IN THE QUEUE. Without this they sit at
             *    "Workshop set" pointing at a day nobody is going on — the system manufacturing
             *    exactly the stuck state the Issues board exists to catch. A withdrawn PROPOSAL
             *    releases its staked pointers the same way, silently, having announced nothing.
             */
            $this->releaseTickets($visitId, null, $v);
            return ['ok' => true, 'was_proposal' => (string) $v['status'] === 'proposed',
                    'message' => (string) $v['status'] === 'proposed'
                        ? 'Request withdrawn. Nobody had been told.'
                        : 'Cancelled.'];
        } catch (\Throwable $e) {
            Log::error('WorkshopVisitService::cancel failed', ['visit' => $visitId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not cancel it.'];
        }
    }

    /**
     * The work happened.
     *
     * ⭐ This is where a workshop visit becomes a TYPED service record — the loop the
     *   whole round started from. The caller records the service through the normal
     *   `markServiced` door (so every rule about which clock moves stays in one place)
     *   and hands the resulting log id back here to be stored as the visit's outcome.
     */
    /**
     * ⭐⭐ THE ONE GATE for answering a visit ("ho gaya" AND "nahi hua"), returned as an error
     *    message or null. Public because the controller must ask it BEFORE it records the
     *    service and files the bill — the Sep-4 review found done() writing the service log
     *    (and money) first and only then discovering the caller was not allowed, the day had
     *    not come, or the visit was already done (a re-post inserted a fresh log each time).
     */
    public function completionGate($user, array $v, bool $mobile = false): ?string
    {
        $uid       = (int) ($user->id ?? 0);
        $isRider   = (int) $v['user_id'] === $uid;
        $isManager = $this->canSchedule($user, $mobile);
        if (!$isManager && !$isRider) {
            return 'You cannot complete workshop visits.';
        }
        if (!$isManager && substr((string) $v['visit_date'], 0, 10) > \Carbon\Carbon::today()->format('Y-m-d')) {
            return 'That day has not come round yet.';
        }
        if ($v['status'] === 'done') return 'That visit is already marked done.';
        if (!in_array($v['status'], self::LIVE_STATUSES, true)) {
            return 'That visit is no longer active.';
        }
        return null;
    }

    public function markDone($user, int $visitId, array $in, bool $mobile = false): array
    {
        if (!$this->available()) return ['ok' => false, 'message' => 'Workshop visits are not set up yet.'];
        $v = $this->find($visitId);
        if (!$v) return ['ok' => false, 'message' => 'That visit no longer exists.'];

        /**
         * ⭐⭐ WHO MAY CLOSE THE LOOP (owner ruling, 2-Sep): a manager on any visit, AND THE
         *    RIDER on his OWN — "when either Qasim enters the values or the riders enter it
         *    after the service". He holds no fleet key, so the gate is ownership of this
         *    visit, exactly as accept() works.
         *
         * ⚠ He may only answer for a visit that has actually come round — today or a past
         *   one he still has to account for. Letting him close a visit three days early
         *   would turn "did it get done?" into a way to make the instruction disappear.
         */
        if ($err = $this->completionGate($user, $v, $mobile)) {
            return ['ok' => false, 'message' => $err];
        }

        try {
            DB::table(self::T_VISIT)->where('id', $visitId)->update([
                'status'         => 'done',
                'done_at'        => now(),
                'done_by'        => (int) $user->id,
                'outcome_note'   => isset($in['outcome_note']) ? mb_substr(trim((string) $in['outcome_note']), 0, 500) : null,
                'service_log_id' => !empty($in['service_log_id']) ? (int) $in['service_log_id'] : null,
                'request_id'     => !empty($in['request_id']) ? (int) $in['request_id'] : null,
                'updated_at'     => now(),
            ]);

            // ⭐ PART B: every issue the visit was answering, not just the originating one.
            $linked = $this->linkedTicketIds($visitId, $v);
            if ($linked) {
                $tickets = app(VehicleTicketService::class);
                foreach ($linked as $tid) {
                    $tickets->system((int) $tid, 'Workshop visit completed by '
                        . $this->nameOf((int) $user->id)
                        . (!empty($in['outcome_note']) ? ' — ' . $in['outcome_note'] : '') . '.');
                }
                /**
                 * ⚠⚠ Completing the VISIT does not close the TICKET. Only a manager closes one
                 *    (owner ruling, 2-Sep, reaffirmed 15-Sep), and the RIDER can mark a visit
                 *    done — so auto-closing here would let him close his own complaint. Put it
                 *    back where a reply is expected instead of finishing the conversation.
                 * ⚠ `workshop_visit_id` is deliberately KEPT: it is the record of which visit
                 *   dealt with this, and the Issues board's "done but still open" line reads it.
                 */
                DB::table(VehicleTicketService::T_TICKET)
                    ->whereIn('id', $linked)->where('status', 'scheduled')
                    ->update(['status' => 'acknowledged', 'updated_at' => now()]);
            }

            return ['ok' => true, 'message' => 'Marked done.'];
        } catch (\Throwable $e) {
            Log::error('WorkshopVisitService::markDone failed', ['visit' => $visitId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not mark it done.'];
        }
    }

    /**
     * "Nahi hua" — the rider (or a manager) reports that the trip did NOT happen.
     *
     * ⚠⚠ Found in the Sep-4 review: the mobile "Nahi hua / sirf dekha gaya" button posted to
     *    /done with only a note, and markDone() unconditionally wrote status = done — so
     *    "he went" and "he never went" became the same row, the exact thing this feature
     *    must never do. This is the distinct path.
     *
     * ⭐ NOTHING IS CLOSED. The instruction still stands: the visit stays LIVE (scheduled /
     *   accepted), so it keeps appearing for the manager as due or missed, and the outcome
     *   prompt returns to the rider until it is either done, cancelled or moved. The answer
     *   is written into outcome_note (dated) and, when the visit came from a ticket, as a
     *   system line on that thread, so the manager can see WHY it did not happen.
     *
     * Same gate as markDone: a manager on any visit, the rider only on his OWN, and only
     * once the day has come round.
     */
    public function reportNotDone($user, int $visitId, ?string $note, bool $mobile = false): array
    {
        if (!$this->available()) return ['ok' => false, 'message' => 'Workshop visits are not set up yet.'];
        $v = $this->find($visitId);
        if (!$v) return ['ok' => false, 'message' => 'That visit no longer exists.'];

        if ($err = $this->completionGate($user, $v, $mobile)) {
            return ['ok' => false, 'message' => $err];
        }
        $uid     = (int) ($user->id ?? 0);
        $isRider = (int) $v['user_id'] === $uid;

        $who  = $isRider ? 'Rider' : $this->nameOf($uid);
        $line = 'Nahi hua (' . $who . ', ' . now()->format('d M H:i') . ')'
            . ($note !== null && trim($note) !== '' ? ': ' . trim($note) : '');
        $prev = trim((string) ($v['outcome_note'] ?? ''));
        $new  = mb_substr(trim($prev !== '' ? $prev . "\n" . $line : $line), -500);

        try {
            DB::table(self::T_VISIT)->where('id', $visitId)->update([
                'outcome_note' => $new,
                'updated_at'   => now(),
            ]);
            /**
             * ⭐ PART B: every issue this visit was answering hears that it did not happen.
             * ⚠ The links are KEPT and no status moves: the visit stays LIVE and still due, so
             *   these complaints are still "at the workshop" — releasing them here would put
             *   them back on the board as waiting on us while the errand is still standing.
             */
            foreach ($this->linkedTicketIds($visitId, $v) as $tid) {
                app(VehicleTicketService::class)->system((int) $tid,
                    'Workshop visit of ' . substr((string) $v['visit_date'], 0, 10) . ' did NOT happen — reported by '
                    . $this->nameOf($uid) . ($note !== null && trim($note) !== '' ? ' — ' . trim($note) : '') . '.');
            }
            return [
                'ok'         => true,
                'kept_open'  => true,
                // Rider-facing (Roman Urdu — it tells him what happens next).
                'message'    => 'Theek hai — manager ko bata diya gaya hai. Visit abhi bhi due hai; jab ho jaye tab yahin batayein.',
            ];
        } catch (\Throwable $e) {
            Log::error('WorkshopVisitService::reportNotDone failed', ['visit' => $visitId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not record that.'];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  READING
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⭐⭐ THE BIKE CHANGED HANDS — its booked workshop day goes with it (owner, 5-Sep-2026:
     *    "the subsequent actions… follow the correct rider so if a new person is assigned to
     *     the bike it follows it").
     *
     * A visit is "take THIS machine in on Friday". It is stored against the rider who was
     * going to take it, but the errand belongs to the machine: when the bike moves to Rajab,
     * Rajab is the one riding it to the workshop on Friday. So every LIVE visit on the machine
     * that is today-or-later is re-pointed to the new holder, and his acceptance is asked for
     * afresh — the old rider's "accepted" was a promise about a bike he no longer has.
     *
     * ⚠ Only today-or-later. A visit dated in the past is history and stays with the man who
     *   (did or did not) do it.
     * ⚠ Taken back to NOBODY: the visit is left as it is — there is nobody to give it to —
     *   and the next assign() will move it, because that fires this hook too.
     * ⚠ Never throws; a note or push must not undo a committed handover.
     *
     * @return int how many visits moved
     */
    public function onHandover(int $vehicleId, ?int $fromUserId, ?int $toUserId, ?int $actorId = null): int
    {
        if (!$this->available() || !$toUserId) return 0;
        try {
            $rows = DB::table(self::T_VISIT)
                ->where('vehicle_id', $vehicleId)
                // ⭐ 6-Sep: a PROPOSAL follows the bike too. The request is "take THIS machine
                //   in on Friday", so the man who will be riding it on Friday is the man the
                //   planner is being asked about — otherwise an approval a day later would
                //   pin the wrong rider's day.
                ->whereIn('status', $this->approvalEnabled() ? self::OPEN_STATUSES : self::LIVE_STATUSES)
                ->whereDate('visit_date', '>=', now()->format('Y-m-d'))
                ->where('user_id', '!=', $toUserId)
                ->get(['id', 'user_id', 'visit_date', 'note', 'location_id', 'status']);
            if ($rows->isEmpty()) return 0;

            $name = function (?int $id): ?string {
                return $id ? DB::table('t_sys_user')->where('id', $id)->value('fullname') : null;
            };
            $from = $name($fromUserId) ?: 'the previous rider';
            $to   = $name($toUserId)   ?: 'the new rider';

            foreach ($rows as $v) {
                $wasProposal = (string) $v->status === 'proposed';
                $line = 'Bike moved from ' . $from . ' to ' . $to . ' on ' . now()->format('d M')
                    . ' — this ' . ($wasProposal ? 'request' : 'visit') . ' is now his.';
                DB::table(self::T_VISIT)->where('id', (int) $v->id)->update([
                    'user_id'      => $toUserId,
                    // ⚠⚠ A PROPOSAL STAYS A PROPOSAL. Promoting it to `scheduled` here would
                    //    hand a rider a workshop day no planner ever approved — a handover
                    //    would have become a back door around the whole ruling.
                    'status'       => $wasProposal ? 'proposed' : 'scheduled',  // else he accepts it himself
                    'accepted_at'  => null,
                    'accepted_by'  => null,
                    'accepted_via' => null,
                    /**
                     * ⚠⚠ THE MACHINE HAS A KEEPER AGAIN, so clear the flag (10-Sep-2026).
                     *    Without this a bike that lost its rider in the morning and was given
                     *    to someone else in the afternoon stayed marked "nobody has it" — and
                     *    the rider-facing surfaces, which now skip a no-keeper visit, would
                     *    have hidden the errand from the very man who had just been handed it.
                     */
                    ...($this->tripEnabled() ? ['no_keeper_since' => null] : []),
                    'note'         => trim((string) $v->note . "\n" . $line),
                    'updated_at'   => now(),
                ]);
                /**
                 * ⚠⚠ THE PHASE-4 PIN IS KEYED BY THE RIDER, NOT THE VISIT. It is a one-day row in
                 *    `t_ops_user_shift_assignment` for the OLD rider, pointing his shift at the
                 *    workshop. Re-pointing the visit alone would leave Waseem checking in at a
                 *    workshop he is not going to, and Rajab — who IS going — marked late or
                 *    remote there. Move the pin with the visit: clear his, pin the new holder.
                 */
                $this->clearShiftLocation((int) $v->id, (int) $v->user_id);
                // ⚠ A proposal has no pin to move — approval is what writes one.
                // ⚠⚠ NO actor here, deliberately: this is not a person choosing to change
                //    somebody's day, it is the system keeping an ALREADY-APPROVED pin correct
                //    after a handover. Gating it could leave the new holder measured against a
                //    workshop he is not going to — the exact bug the pin-move exists to prevent.
                if (!empty($v->location_id) && !$wasProposal) {
                    $this->applyShiftLocation((int) $v->id, $toUserId, substr((string) $v->visit_date, 0, 10), (int) $v->location_id);
                }
                try {
                    // Same push it would have got had it been booked for him in the first
                    // place — which for a proposal means the PLANNERS, not the rider.
                    app(\App\Services\FirebaseService::class)->notifyWorkshopVisit(
                        $wasProposal ? 'proposed' : 'scheduled', (int) $v->id, (int) ($actorId ?: 0));
                } catch (\Throwable $e) {
                    Log::warning('Handover workshop push failed', ['visit' => $v->id, 'error' => $e->getMessage()]);
                }
            }
            Log::info('Workshop visits travelled with the machine', [
                'vehicle_id' => $vehicleId, 'from_user' => $fromUserId, 'to_user' => $toUserId, 'visits' => $rows->count(),
            ]);
            return $rows->count();
        } catch (\Throwable $e) {
            Log::warning('WorkshopVisitService::onHandover failed', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
            return 0;
        }
    }

    public function find(int $visitId): ?array
    {
        if (!$this->available()) return null;
        try {
            $r = DB::table(self::T_VISIT)->where('id', $visitId)->first();
            return $r ? (array) $r : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Every live visit, newest date first, shaped for a screen.
     *
     * @param array $opts {user_id?, vehicle_id?, from?, to?, include_done?,
     *                     include_proposed?, statuses?}
     *
     * ⚠⚠ THE DEFAULT IS LIVE-ONLY, AND MUST STAY THAT WAY. Every rider-facing reader goes
     *    through here with no status option, so a proposal is invisible to him unless a
     *    caller deliberately asks for it — `include_proposed` (manager surfaces: the vehicle
     *    panel, the planner cell) or `statuses` (the approval banner). Adding `proposed` to
     *    the default would leak it to the phone of the man it is being decided about.
     */
    public function listVisits(array $opts = []): array
    {
        if (!$this->available()) return [];
        try {
            $q = DB::table(self::T_VISIT . ' as v')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'v.user_id')
                ->leftJoin('t_sys_user as c', 'c.id', '=', 'v.created_by')
                ->leftJoin('t_sys_user as ab', 'ab.id', '=', 'v.accepted_by');
            /**
             * 📍 THE REGISTERED WORKSHOP'S NAME, in the same query (10-Sep-2026).
             * ⚠ A visit booked at a registered workshop has NO free-text `workshop`, so every
             *   sentence built from that column alone said "workshop" where it meant "Ali
             *   Motors" — including the one telling the rider where to clock in. One join
             *   beats a lookup per row, and beats a vague instruction.
             * ⚠ Guarded: the locations table is its own migration.
             */
            $hasLocs = $this->locationsEnabled();
            if ($hasLocs) {
                $q->leftJoin('t_ops_company_locations as loc', 'loc.id', '=', 'v.location_id');
            }

            if (!empty($opts['user_id']))    $q->where('v.user_id', (int) $opts['user_id']);
            if (!empty($opts['vehicle_id'])) $q->where('v.vehicle_id', (int) $opts['vehicle_id']);
            if (!empty($opts['from']))       $q->whereDate('v.visit_date', '>=', $opts['from']);
            if (!empty($opts['to']))         $q->whereDate('v.visit_date', '<=', $opts['to']);

            if (!empty($opts['statuses']) && is_array($opts['statuses'])) {
                $statuses = array_values(array_map('strval', $opts['statuses']));
            } else {
                $statuses = empty($opts['include_done'])
                    ? self::LIVE_STATUSES
                    : ['scheduled', 'accepted', 'done', 'cancelled', 'rescheduled'];
                // ⚠ `declined` is NOT in the history default either. A rider may legitimately
                //   ask this endpoint for his own history, and a request that was proposed for
                //   him and turned down is a conversation between managers that he was
                //   deliberately never part of. Manager surfaces ask for it by `statuses`.
                if (!empty($opts['include_proposed'])) $statuses = array_merge($statuses, ['proposed', 'declined']);
            }
            // ⚠ Never ask for a status the table cannot hold yet — before the approval SQL
            //   a `proposed` filter simply matches nothing, which is the right degradation.
            $q->whereIn('v.status', $statuses);

            $rows = $q->orderBy('v.visit_date')->orderBy('v.id')
                ->limit(min(500, max(1, (int) ($opts['limit'] ?? 200))))
                ->get(array_merge(['v.*', 'u.fullname as rider_name', 'c.fullname as created_by_name',
                                   'ab.fullname as accepted_by_name'],
                                  $hasLocs ? ['loc.location_name as location_name'] : []));
            if ($rows->isEmpty()) return [];

            $labels = [];
            try {
                $res = new VehicleResolver();
                foreach ($rows->pluck('vehicle_id')->unique() as $vid) {
                    $labels[(int) $vid] = $res->labelFor((int) $vid);
                }
            } catch (\Throwable $e) {
            }

            /**
             * ⚠⚠ ONE QUERY FOR EVERY NAME, not one per row per column. The six approval columns
             *    carry three more user ids each (proposed / approved / declined by), and
             *    resolving them inside `shape()` would have been up to 60 extra queries on
             *    every banner poll once a few weeks of visits carry `approved_by` — the same
             *    N+1 shape the payroll page was 14 s slow for.
             */
            $names = [];
            $ids = [];
            foreach ($rows as $r) {
                foreach (['proposed_by', 'approved_by', 'declined_by'] as $k) {
                    if (!empty($r->$k)) $ids[(int) $r->$k] = true;
                }
            }
            if ($ids) {
                $names = DB::table('t_sys_user')->whereIn('id', array_keys($ids))
                    ->pluck('fullname', 'id')->all();
            }

            /**
             * ⭐ PART B: the issues each visit is answering, in ONE query for the whole page.
             * ⚠ The UNION of the many-side and each visit's own originating `ticket_id` — a
             *   visit booked before Part B carries only the latter, and a card that showed it
             *   as covering nothing would be saying something untrue about real history.
             */
            $ticketsByVisit = $this->ticketsForVisits($rows->pluck('id')->map(fn ($i) => (int) $i)->all(),
                                                      $rows->pluck('ticket_id')->filter()->map(fn ($i) => (int) $i)->all());

            return $rows->map(fn ($r) => $this->shape((array) $r, $labels, $names, $ticketsByVisit))
                ->values()->all();
        } catch (\Throwable $e) {
            Log::warning('WorkshopVisitService::listVisits failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * ⭐ "Did it get done?" — a visit whose day has ARRIVED (today, or past and unanswered)
     *   and which nobody has closed. Drives the rider's completion prompt, and the reason
     *   an end-of-day sweep is NOT needed: the question is derived, so it simply appears
     *   the moment the day turns and stays until answered.
     *
     * ⚠ Deliberately NOT auto-completed at midnight. Auto-marking a visit done would make
     *   "he went" and "he never went" indistinguishable — the one thing this must never do.
     *   Midnight only makes it MISSED, which is a question, not an answer.
     */
    public function awaitingOutcomeFor(int $userId): ?array
    {
        $today = \Carbon\Carbon::today()->format('Y-m-d');
        foreach ($this->listVisits(['user_id' => $userId, 'limit' => 20]) as $v) {
            if ($v['visit_date'] > $today) continue;

            /**
             * ⚠⚠ NOT WHILE HE STILL HAS TO ACCEPT IT (found on the device, 3-Sep).
             *
             *    A visit dated TODAY put BOTH cards on his screen at once: "take the bike to
             *    the workshop — confirm karein" and "workshop aaj — ho gaya?". Asking whether
             *    he has done a thing he has not yet agreed to do is nonsense, and two cards
             *    disagreeing about where he is in the flow is how a rider stops trusting them.
             *
             * ⭐ So: ask for the outcome once he has ACCEPTED — or once the day has PASSED,
             *   because a MISSED visit still needs an answer whether he confirmed it or not.
             *   That keeps exactly one card on screen at every point in the flow.
             */
            if ($v['visit_date'] === $today && !$v['accepted']) continue;

            /**
             * ⚠⚠ AND NOT WHILE HE IS STILL ON THE ROAD (Sep-10, the trip round).
             *
             *    Setting off now ACCEPTS the visit — which is right, it is plainly a yes — but
             *    it also made this question qualify the moment he pressed the button. He would
             *    have had "Ali Motors ki taraf" and "workshop aaj — ho gaya?" on screen at the
             *    same time, ten minutes before he got there. That is the identical failure the
             *    Sep-3 device round fixed for the accept/outcome pair, reintroduced one step
             *    later in the flow.
             *
             * ⭐ So on the day itself the question waits until he has ARRIVED — proved by his
             *   own GPS, since there is no arrival button. A day that has PASSED still asks
             *   regardless: a missed visit needs an answer whether he ever set off or not.
             * ⚠ Free-text workshops never geofence, so `departed` alone qualifies there —
             *   otherwise those visits could never be answered on the day at all.
             */
            if ($v['visit_date'] === $today && $this->tripEnabled()
                && !empty($v['departed_at']) && empty($v['arrived_at']) && !empty($v['location_id'])) {
                continue;
            }

            /**
             * ⚠⚠ AND NOT FOR A BIKE HE NO LONGER HAS (10-Sep-2026). Same rule as
             *    `nextForUser`: the question still needs answering, but not by the man who
             *    handed the machine back — he cannot know what the workshop did to it. It
             *    stays live for the managers, who were told the moment the keeper was lost.
             */
            if (!empty($v['no_keeper_since'])) continue;

            return $v;
        }
        return null;
    }

    /**
     * ⭐⭐ "WORKSHOP JAA RAHA HOON" — the rider sets off (owner ask, 10-Sep-2026).
     *
     * This is the only manual step in the trip. Arrival is the geofence's job (there is no
     * arrival button by ruling), and the end is the outcome prompt that already exists.
     *
     * ⚠⚠ THE ORDERS GATE, and why it refuses rather than warns. A rider who leaves with
     *    DISPATCHED orders on his name strands those orders: they are out for delivery, the
     *    board counts them, the customer is waiting, and nobody else can take them because
     *    they are assigned to him. So the press is refused and the sentence tells him the
     *    remedy in his own words — ask the manager to take them off his name — and the same
     *    press tells the STORE what to do, because he cannot do it himself.
     * ⭐ Orders merely ASSIGNED (not dispatched) do not strand: the store can reassign them
     *   at leisure. Those let him go and raise a notice instead (owner ruling).
     *
     * @param array $in  ['force' => bool] — a MANAGER pressing it for him after moving the
     *                   orders himself; never available to the rider.
     */
    public function depart($user, int $visitId, array $in = [], bool $mobile = false): array
    {
        if (!$this->available())   return ['ok' => false, 'message' => 'Workshop visits are not set up yet.'];
        if (!$this->tripEnabled()) return ['ok' => false, 'message' => 'This app is newer than the server — ask for the workshop update to be installed.'];

        $v = $this->find($visitId);
        if (!$v) return ['ok' => false, 'message' => 'That visit no longer exists.'];

        $uid       = (int) ($user->id ?? 0);
        $isRider   = (int) $v['user_id'] === $uid;
        $isManager = $this->canSchedule($user, $mobile);
        if (!$isRider && !$isManager) {
            return ['ok' => false, 'message' => 'You cannot start this workshop trip.'];
        }
        if (!in_array((string) $v['status'], self::LIVE_STATUSES, true)) {
            return ['ok' => false, 'message' => 'That visit is no longer active.'];
        }
        $today = \Carbon\Carbon::today()->format('Y-m-d');
        if ($v['visit_date'] !== $today) {
            return ['ok' => false, 'message' => $v['visit_date'] > $today
                ? 'That day has not come round yet.'
                : 'That day has passed — tell the manager what happened to it.'];
        }
        if (!empty($v['departed_at'])) {
            return ['ok' => false, 'message' => 'Already on the way.'];
        }

        $riderId = (int) $v['user_id'];

        // ── the orders gate ──────────────────────────────────────────────────────
        /**
         * ⭐⭐ THE REGISTRY DECIDES WHETHER THIS IS STILL HIS ERRAND (owner principle,
         *    10-Sep-2026: *"whoever is assigned the vehicle… this registry and comparison
         *    between the rider and vehicle is very important"*).
         *
         * ⚠⚠ Setting off arms a trip, moves the live board and silences his "left without
         *    dispatch" flag. Doing that for a machine he no longer holds — because it was
         *    handed on, or he was moved onto a spare while it waits for the workshop — puts
         *    a false errand on every board at once. The visit itself is not wrong; it simply
         *    is not his any more, and `onHandover()` re-points it the moment somebody takes
         *    the bike.
         *
         * ⚠ Refused ONLY on a definite disagreement. A registry that answers NULL (a lookup
         *   failure, a machine between keepers) leaves this alone — a guard must never be the
         *   reason a real trip cannot start.
         */
        try {
            /**
             * ⚠ Asked of the MACHINE, not of the man. `currentVehicleFor($rider)` answers
             *   "what is he holding", which is NULL both when he holds nothing and when the
             *   registry cannot say — and those are opposite answers here. `keeperOf($bike)`
             *   answers the question this guard is actually asking: who has this machine.
             */
            $keeper = (new VehicleService())->keeperOf((int) $v['vehicle_id']);
            $has    = $keeper ? (int) $keeper->user_id : 0;
            if ($has !== $riderId) {
                $bike = (new VehicleResolver())->labelFor((int) $v['vehicle_id']) ?: 'that bike';
                $now  = $has ? $this->nameOf($has) : null;
                return ['ok' => false, 'message' => $isRider
                    ? $bike . ' ab aap ke naam par nahi hai — is liye workshop trip shuru nahi '
                      . 'ho sakti. Manager se baat karein.'
                    : $this->nameOf($riderId) . ' no longer holds ' . $bike . ', so this errand is '
                      . 'not his to start. ' . ($now
                          ? $now . ' has it now and takes it in.'
                          : 'Nobody holds it — assign it to whoever is taking it in.')];
            }
        } catch (\Throwable $e) {
            // Registry unavailable ⇒ behave exactly as before this check existed.
        }

        $orders = $this->openOrdersFor($riderId);
        if ($orders['dispatched'] > 0 && empty($in['force'])) {
            /**
             * ⚠ A MANAGER may force it — he is the one who can move the orders, and he may
             *   have just done so by phone. The RIDER never can: `force` is ignored for him,
             *   because the whole point is that he cannot fix this himself.
             */
            if (!$isManager) {
                try {
                    app(\App\Services\FirebaseService::class)
                        ->notifyWorkshopVisit('needs_unassign', $visitId, $uid);
                } catch (\Throwable $e) {
                    Log::warning('needs_unassign push failed', ['visit' => $visitId, 'error' => $e->getMessage()]);
                }
                return ['ok' => false, 'blocked_by_orders' => true, 'orders' => $orders, 'message' =>
                    'Aap ke naam par ' . $orders['dispatched'] . ' dispatched order'
                    . ($orders['dispatched'] === 1 ? '' : 's') . ' hain. Manager se kahein ke woh orders '
                    . 'aap ke naam se hata dein, phir dobara dabayein — warna woh orders atak jayenge. '
                    . 'Manager ko itla bhej di gayi hai.'];
            }
            return ['ok' => false, 'blocked_by_orders' => true, 'orders' => $orders, 'message' =>
                $this->nameOf($riderId) . ' still has ' . $orders['dispatched'] . ' dispatched order'
                . ($orders['dispatched'] === 1 ? '' : 's') . ' out. Move them to another rider first, '
                . 'or send this again to go anyway.'];
        }

        // ⚠ A van carrying cargo strands MORE than orders — the stock is on it.
        if ($orders['on_van'] > 0 && empty($in['force'])) {
            return ['ok' => false, 'blocked_by_orders' => true, 'orders' => $orders, 'message' =>
                $isRider
                    ? 'Van par ' . $orders['on_van'] . ' order abhi loaded hain — pehle unload ya kisi'
                      . ' aur ko transfer karwayein.'
                    : 'The van still has ' . $orders['on_van'] . ' loaded orders — unload or move them first.'];
        }

        try {
            $upd = ['departed_at' => now(), 'departed_by' => $uid, 'updated_at' => now()];
            /**
             * ⭐⭐ SETTING OFF **IS** ACCEPTING (Sep-10). A rider who presses "Workshop jaa raha
             *    hoon" has plainly agreed to go, so leaving the visit `scheduled` would keep the
             *    "confirm karein" card on his phone while he is already on the road — two cards
             *    disagreeing about where he is in the flow, which is exactly the failure the
             *    Sep-3 device round fixed for the accept/outcome pair.
             * ⚠ Only when HE pressed it. A manager standing in has confirmed nothing on the
             *   rider's behalf — that distinction is what `accepted_via` exists to preserve.
             */
            if ($isRider && (string) $v['status'] !== 'accepted') {
                $upd['status']       = 'accepted';
                $upd['accepted_at']  = now();
                $upd['accepted_by']  = $uid;
                $upd['accepted_via'] = 'rider';
            }
            $n = DB::table(self::T_VISIT)->where('id', $visitId)->whereNull('departed_at')
                ->update($upd);
            if (!$n) return ['ok' => false, 'message' => 'Already on the way.'];
        } catch (\Throwable $e) {
            Log::error('workshop depart failed', ['visit' => $visitId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not start the trip.'];
        }

        $this->checkoutMemo = [];
        try {
            app(\App\Services\FirebaseService::class)->notifyWorkshopVisit('departed', $visitId, $uid);
        } catch (\Throwable $e) {
            Log::warning('departed push failed', ['visit' => $visitId, 'error' => $e->getMessage()]);
        }

        $trip = $this->tripFor($riderId);
        return [
            'ok' => true,
            'trip' => $trip,
            'assigned_warning' => $orders['assigned'] > 0 ? $orders['assigned'] : null,
            'message' => $isRider
                ? 'Theek hai — nikal jayein. Pohanchne par khud pata chal jayega.'
                : $this->nameOf($riderId) . ' is on the way to ' . ($trip['workshop'] ?? 'the workshop') . '.',
        ];
    }

    /**
     * What is on this rider's plate right now — the three counts the START gate needs.
     *
     * ⚠ "dispatched" = out for delivery AND already dispatched (an ETA was calculated), the
     *   same distinction the live board draws. Those are the ones that strand.
     *
     * @return array{dispatched:int, assigned:int, on_van:int}
     */
    public function openOrdersFor(int $riderId): array
    {
        $out = ['dispatched' => 0, 'assigned' => 0, 'on_van' => 0];
        try {
            $rows = DB::table('t_crm_prod_order')
                ->where('assigned_rider_user_id', $riderId)
                ->whereIn('order_status', ['out_for_delivery', 'on_van'])
                ->get(['order_status', 'eta_calculated_at']);
            foreach ($rows as $r) {
                if ((string) $r->order_status === 'on_van') { $out['on_van']++; continue; }
                if (!empty($r->eta_calculated_at)) $out['dispatched']++;
                else                               $out['assigned']++;
            }
        } catch (\Throwable $e) {
            // ⚠ Fail OPEN: an unreadable orders table must not trap a rider who has been
            //   told to take the bike in. The store notice still goes out.
            Log::warning('openOrdersFor failed', ['rider' => $riderId, 'error' => $e->getMessage()]);
        }
        return $out;
    }

    /**
     * ⭐⭐ ARRIVAL BY GEOFENCE — there is no arrival button (owner ruling, 10-Sep).
     *
     * Called from the location heartbeat the phone already sends every five minutes, so
     * nothing new runs on the device. Same shape as the going-home arrival stamp.
     *
     * ⭐ It also stamps `departed_at` when that is missing: a rider who forgot to press the
     *   button but is demonstrably standing at the workshop HAS gone, and the board saying
     *   otherwise while his own GPS proves it would be the kind of contradiction this whole
     *   round exists to remove.
     *
     * ⚠ Only for a REGISTERED workshop with coordinates. A free-text one never arrives, and
     *   the trip honestly reads "going to …" until he answers the outcome.
     *
     * @return bool true when this fix stamped an arrival (so the caller can push once)
     */
    public function stampArrival(int $userId, float $lat, float $lng): bool
    {
        if (!$this->tripEnabled()) return false;
        try {
            $today = \Carbon\Carbon::today()->format('Y-m-d');
            $rows = DB::table(self::T_VISIT)
                ->where('user_id', $userId)
                ->whereDate('visit_date', $today)
                ->whereIn('status', self::LIVE_STATUSES)
                ->whereNull('arrived_at')
                ->whereNull('done_at')
                ->whereNotNull('location_id')
                ->get(['id', 'location_id', 'departed_at']);
            if ($rows->isEmpty()) return false;

            foreach ($rows as $r) {
                $c = $this->workshopCoords($r->location_id);
                if (!$c) continue;
                $d = $this->haversine($lat, $lng, $c['lat'], $c['lng']);
                if ($d > $c['radius']) continue;

                $upd = ['arrived_at' => now(), 'updated_at' => now()];
                // He is there; if he never pressed "going", the fix proves he went anyway.
                if (empty($r->departed_at)) {
                    $upd['departed_at'] = now();
                    $upd['departed_by'] = $userId;
                }
                $n = DB::table(self::T_VISIT)->where('id', $r->id)->whereNull('arrived_at')->update($upd);
                if (!$n) continue;

                $this->checkoutMemo = [];
                try {
                    app(\App\Services\FirebaseService::class)
                        ->notifyWorkshopVisit('arrived', (int) $r->id, $userId);
                } catch (\Throwable $e) {
                    Log::warning('arrived push failed', ['visit' => $r->id, 'error' => $e->getMessage()]);
                }
                return true;
            }
        } catch (\Throwable $e) {
            // ⚠ NON-FATAL BY CONTRACT. This runs inside the heartbeat; a failure here must
            //   never cost the rider his location update.
            Log::warning('stampArrival failed', ['user' => $userId, 'error' => $e->getMessage()]);
        }
        return false;
    }

    /**
     * ⭐ "WHO WAS SENT TO A WORKSHOP ON THIS DAY?" — for the review layers, which look at days
     *    that are already over.
     *
     * ⚠⚠ NOT `tripsFor()`. That one answers "who is on an errand RIGHT NOW": it keeps only
     *    `LIVE_STATUSES` and only rows he has actually set off on. By the time a day is
     *    reviewed the visit is `done`, so asking the live question about yesterday returns
     *    nobody and every workshop day collects the flags it was supposed to be excused.
     * ⚠ A visit that was declined, cancelled, or reported not-done excuses NOTHING — those are
     *   days he did not go, and a man who did not go has no excuse for a late start.
     *
     * @return array<int, true> keyed by user id
     */
    public function ridersWithVisitOn(array $userIds, string $date): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$this->available() || !$userIds) return [];
        try {
            return DB::table(self::T_VISIT)
                ->whereIn('user_id', $userIds)
                ->whereDate('visit_date', substr($date, 0, 10))
                ->whereIn('status', array_merge(self::LIVE_STATUSES, ['done']))
                ->pluck('user_id')
                ->mapWithKeys(fn ($uid) => [(int) $uid => true])
                ->all();
        } catch (\Throwable $e) {
            Log::warning('ridersWithVisitOn failed', ['date' => $date, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * The same answer over a RANGE, keyed `"userId|Y-m-d"` — the shape the month-level
     * reviewers already use for handover days, so the two exemptions read alike.
     *
     * @return array<string, true>
     */
    public function visitDayKeys(array $userIds, string $from, string $to): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$this->available() || !$userIds) return [];
        try {
            $out = [];
            DB::table(self::T_VISIT)
                ->whereIn('user_id', $userIds)
                ->whereBetween('visit_date', [substr($from, 0, 10), substr($to, 0, 10)])
                ->whereIn('status', array_merge(self::LIVE_STATUSES, ['done']))
                ->get(['user_id', 'visit_date'])
                ->each(function ($r) use (&$out) {
                    $out[(int) $r->user_id . '|' . substr((string) $r->visit_date, 0, 10)] = true;
                });
            return $out;
        } catch (\Throwable $e) {
            Log::warning('visitDayKeys failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /** How long a "Baad mein" lasts. Owner's figure, 11-Sep-2026. */
    public const SNOOZE_HOURS = 6;

    /** The per-user key for one proposal. ⚠ Must fit `t_ops_alert_dismissal.alert_key` (64). */
    private function snoozeKey(int $visitId): string
    {
        return substr('wsapproval:' . $visitId, 0, 64);
    }

    /**
     * ⏰ Drop the proposals THIS user has put off, and only for as long as the snooze lasts.
     *
     * ⚠ Reuses `t_ops_alert_dismissal`, which already exists on prod and was built to be
     *   reused — so this feature needs no table of its own. The one difference from the
     *   service-alert use is that a row here EXPIRES: `dismissed_at` is a start time, not a
     *   permanent tombstone, because the question is still open and must come back.
     */
    private function minusSnoozed(array $rows, int $userId): array
    {
        if (!$userId || !$rows) return $rows;
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('t_ops_alert_dismissal')) return $rows;
            $keys = [];
            foreach ($rows as $r) $keys[] = $this->snoozeKey((int) $r['id']);

            $live = DB::table('t_ops_alert_dismissal')
                ->where('user_id', $userId)
                ->whereIn('alert_key', $keys)
                ->where('dismissed_at', '>=', now()->subHours(self::SNOOZE_HOURS))
                ->pluck('alert_key')
                ->flip();

            return array_values(array_filter(
                $rows,
                fn ($r) => !isset($live[$this->snoozeKey((int) $r['id'])])
            ));
        } catch (\Throwable $e) {
            // ⚠ A snooze lookup must never empty the queue — fail towards SHOWING the work.
            Log::warning('approval snooze filter skipped', ['error' => $e->getMessage()]);
            return $rows;
        }
    }

    /**
     * ⏰ "Baad mein (6h)" — hide this proposal from THIS user for six hours.
     *
     * ⭐ Deliberately not a decision: the visit stays `proposed`, every other planner still
     *   sees it, and it comes back to him if nobody has dealt with it. A snooze that silently
     *   declined would be far worse than the nuisance it is solving.
     */
    public function snoozeProposal($user, int $visitId): array
    {
        $uid = (int) ($user->id ?? 0);
        if (!$uid) return ['ok' => false, 'message' => 'Not authorised'];
        if (!$this->canApprove($user, true) && !$this->canApprove($user, false)) {
            return ['ok' => false, 'message' => 'Not authorised'];
        }
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('t_ops_alert_dismissal')) {
                return ['ok' => false, 'message' => 'Snooze is not set up on this install yet.'];
            }
            $v = $this->find($visitId);
            if (!$v) return ['ok' => false, 'message' => 'That visit no longer exists.'];
            // ⚠ Only an OPEN question can be put off. Snoozing a decided one would write a row
            //   that outlives the thing it refers to.
            if ((string) $v['status'] !== 'proposed') {
                return ['ok' => true, 'already_decided' => true,
                        'message' => 'That one has already been decided.'];
            }

            DB::table('t_ops_alert_dismissal')->updateOrInsert(
                ['user_id' => $uid, 'alert_key' => $this->snoozeKey($visitId)],
                ['dismissed_at' => now()]
            );
            Log::info('Workshop proposal snoozed', ['visit' => $visitId, 'by' => $uid,
                                                    'hours' => self::SNOOZE_HOURS]);
            return ['ok' => true, 'message' => self::SNOOZE_HOURS . ' ghante baad dobara dikhega.'];
        } catch (\Throwable $e) {
            Log::warning('snoozeProposal failed', ['visit' => $visitId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not snooze that one.'];
        }
    }

    /**
     * ⭐⭐ THE WORKSHOP PINS ITSELF — asking the rider, ONCE, when he has clearly stopped
     *    somewhere we cannot recognise (owner ask, 11-Sep-2026).
     *
     * ⚠⚠ THE PROBLEM THIS SOLVES. Arrival is proved by a geofence, and a geofence needs a pin.
     *    A visit booked through the free-text "Workshop name (not on the list)" box has none,
     *    so `stampArrival()` skips it (`whereNotNull('location_id')`) and the man reads "going
     *    to the workshop" all day, with no ETA and no arrival — for every screen, forever.
     *    Banning free text was rejected: a manager genuinely may not know the place. So the
     *    rider's OWN arrival becomes the pin, and every later visit there is automatic.
     *
     * ⭐ Runs inside the heartbeat the phone ALREADY sends. Nothing new runs on the device and
     *   nothing polls; `maybeAskArrival` only ever writes the "ask him" flag.
     *
     * ⚠⚠ THE FOUR GATES EXIST TO STOP A PROMPT THAT WILL NOT GO AWAY — the owner's explicit
     *    requirement. In order: he must have STOPPED (not be riding past), the fix must be
     *    CREDIBLE, the place must be UNRECOGNISED, and he must not have been asked too often.
     *    A prompt that fires while he is moving, or at his own home, is one nobody ever
     *    answers honestly again.
     *
     * @return bool true when a prompt was just armed
     */
    public function maybeAskArrival(int $userId, float $lat, float $lng, $accuracy = null): bool
    {
        if (!$this->tripEnabled() || !$this->hasArrivalAsk()) return false;

        try {
            /**
             * ⚠ GATE 3a — THE FIX MUST BE CREDIBLE. The same 150 m bar the dispatch origin
             *   uses. A 2 km "fix" is how a workshop would get pinned to a petrol station two
             *   streets away, and that pin would then be wrong for everybody, forever. NULL
             *   accuracy is allowed through exactly as the dispatch reader allows it — many
             *   devices simply do not report it.
             */
            if ($accuracy !== null && is_numeric($accuracy) && (float) $accuracy > 150.0) {
                return false;
            }

            $today = \Carbon\Carbon::today()->format('Y-m-d');
            $rows = DB::table(self::T_VISIT)
                ->where('user_id', $userId)
                ->whereDate('visit_date', $today)
                ->whereIn('status', self::LIVE_STATUSES)
                ->whereNotNull('departed_at')      // he has set off
                ->whereNull('arrived_at')
                ->whereNull('done_at')
                ->orderBy('id')
                ->get(['id', 'location_id', 'workshop', 'arrival_ask_at',
                       'arrival_ask_lat', 'arrival_ask_lng', 'arrival_ask_count']);
            if ($rows->isEmpty()) return false;

            foreach ($rows as $r) {
                // Only a visit with NO USABLE PIN. One that has coordinates is geofenced by
                // stampArrival() and must never be answered by a question instead.
                if ($r->location_id && $this->workshopCoords($r->location_id)) continue;

                // ⚠ GATE 4 — ASKED AT MOST TWICE, EVER. Server-side, because a counter kept on
                //   the device resets when the app does, which is precisely how a "confirm?"
                //   box becomes one that will not go away.
                if ((int) $r->arrival_ask_count >= 2) continue;

                // ⚠ GATE 4b — THE COOLDOWN, BOTH HALVES. After "not now" he must be left alone
                //   for 45 minutes AND have moved 300 m. Time alone would re-ask him standing
                //   in the same spot; distance alone would re-ask the moment he crossed the
                //   road. Both, or it is the nagging box the owner warned about.
                if (!empty($r->arrival_ask_at)) {
                    $askedAgo = \Carbon\Carbon::parse($r->arrival_ask_at)->diffInMinutes(now());
                    $moved = ($r->arrival_ask_lat !== null && $r->arrival_ask_lng !== null)
                        ? $this->haversine($lat, $lng, (float) $r->arrival_ask_lat, (float) $r->arrival_ask_lng)
                        : 9999;
                    if ($askedAgo < 45 || $moved < 300) continue;
                }

                // ⚠ GATE 1 — HE HAS ACTUALLY STOPPED. Asking a man riding past a row of shops
                //   is how a workshop ends up pinned to a junction.
                if (!$this->hasDwelled($userId, $lat, $lng)) continue;

                // ⚠ GATE 2 — THE PLACE IS NOT ALREADY EXPLAINED. At the office, at his own
                //   home, or at a workshop we already know, "are you at the workshop?" is
                //   noise — and answering yes would pin a SECOND workshop on top of a place
                //   that already has a name.
                if ($this->placeIsKnown($userId, $lat, $lng)) continue;

                DB::table(self::T_VISIT)->where('id', $r->id)->update([
                    'arrival_ask_at'    => now(),
                    'arrival_ask_lat'   => $lat,
                    'arrival_ask_lng'   => $lng,
                    'arrival_ask_count' => (int) $r->arrival_ask_count + 1,
                    'updated_at'        => now(),
                ]);
                Log::info('Workshop arrival prompt armed (no pin on this workshop)', [
                    'visit' => (int) $r->id, 'user' => $userId,
                    'ask_number' => (int) $r->arrival_ask_count + 1,
                ]);
                return true;
            }
        } catch (\Throwable $e) {
            // ⚠ NON-FATAL BY CONTRACT — this runs inside the heartbeat.
            Log::warning('maybeAskArrival failed', ['user' => $userId, 'error' => $e->getMessage()]);
        }
        return false;
    }

    /**
     * Has he been sitting still? The last fixes spanning at least 8 minutes must all be within
     * 120 m of where he is now.
     *
     * ⚠ Needs at least TWO fixes: one reading cannot tell "stopped" from "passing through", and
     *   treating a single fix as a stop is the difference between a prompt at the workshop and
     *   a prompt at a red light.
     */
    private function hasDwelled(int $userId, float $lat, float $lng): bool
    {
        try {
            $fixes = DB::table('t_ops_rider_location')
                ->where('user_id', $userId)
                ->where('captured_at', '>=', now()->subMinutes(25))
                ->orderByDesc('captured_at')
                ->limit(6)
                ->get(['latitude', 'longitude', 'captured_at', 'accuracy']);
            if ($fixes->count() < 2) return false;

            $oldest = null;
            foreach ($fixes as $f) {
                if ($f->latitude === null || $f->longitude === null) continue;
                // A wild fix must not break a real dwell — skip it rather than fail the gate.
                if ($f->accuracy !== null && (float) $f->accuracy > 150.0) continue;
                if ($this->haversine($lat, $lng, (float) $f->latitude, (float) $f->longitude) > 120) {
                    return false;
                }
                $oldest = $f->captured_at;
            }
            if (!$oldest) return false;
            return \Carbon\Carbon::parse($oldest)->diffInMinutes(now()) >= 8;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Is he somewhere the system can already name — the office, his home, a known workshop? */
    private function placeIsKnown(int $userId, float $lat, float $lng): bool
    {
        try {
            $locs = DB::table('t_ops_company_locations')
                ->where('is_active', 1)
                ->whereNotNull('latitude')->whereNotNull('longitude')
                ->get(['latitude', 'longitude', 'radius_meters']);
            foreach ($locs as $l) {
                $r = (int) ($l->radius_meters ?? 0);
                if ($this->haversine($lat, $lng, (float) $l->latitude, (float) $l->longitude)
                    <= ($r > 0 ? $r : self::ARRIVE_RADIUS_M)) {
                    return true;
                }
            }
            // …and his own home, which is not a company location.
            $home = DB::table('t_ops_rider_profile')->where('user_id', $userId)
                ->first(['home_latitude', 'home_longitude']);
            if ($home && $home->home_latitude !== null && $home->home_longitude !== null) {
                if ($this->haversine($lat, $lng, (float) $home->home_latitude,
                                     (float) $home->home_longitude) <= 300) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // Cannot say ⇒ do not claim it is known; the other gates still apply.
        }
        return false;
    }

    /** Does the visit table carry the arrival-prompt state yet? (Sep-12 SQL) */
    private function hasArrivalAsk(): bool
    {
        if (self::$askMemo !== null) return self::$askMemo;
        try {
            self::$askMemo = \Illuminate\Support\Facades\Schema::hasColumn(self::T_VISIT, 'arrival_ask_at');
        } catch (\Throwable $e) {
            self::$askMemo = false;
        }
        return self::$askMemo;
    }

    /** @internal memo for hasArrivalAsk(); a static so a test can reset it. */
    private static ?bool $askMemo = null;

    /** Test seam — see the ALTER-inside-a-transaction trap. */
    public static function flushArrivalMemo(): void { self::$askMemo = null; }

    /**
     * ⭐⭐ "ARE YOU AT THE WORKSHOP?" — what the phone should show him right now, or null.
     *
     * ⚠⚠ THE PROMPT IS A FACT ON THE SERVER, NOT A TIMER ON THE PHONE. This is the whole
     *    anti-bug design: the sheet appears because this returns a row and disappears the
     *    moment it stops doing so. Close the visit, check out, let a manager stamp the
     *    arrival, or answer it on another device — and the box closes itself on the next
     *    poll, because there is nothing here any more. A client-side countdown could not do
     *    that, which is how these prompts get stuck.
     */
    public function arrivalPromptFor(int $userId): ?array
    {
        if (!$this->tripEnabled() || !$this->hasArrivalAsk()) return null;
        try {
            $r = DB::table(self::T_VISIT)
                ->where('user_id', $userId)
                ->whereDate('visit_date', \Carbon\Carbon::today()->format('Y-m-d'))
                ->whereIn('status', self::LIVE_STATUSES)
                ->whereNotNull('arrival_ask_at')
                ->whereNull('arrived_at')
                ->whereNull('done_at')
                ->orderByDesc('arrival_ask_at')
                ->first(['id', 'workshop', 'location_id', 'vehicle_id', 'arrival_ask_at']);
            if (!$r) return null;

            // ⚠ The ask goes stale on its own. A question from four hours ago is not worth
            //   answering, and a sheet that outlives its moment is one people dismiss blindly.
            if (\Carbon\Carbon::parse($r->arrival_ask_at)->diffInMinutes(now()) > 90) return null;

            $place = $r->workshop ?: ($this->locationNameFor($r->location_id) ?: 'the workshop');
            return [
                'visit_id'     => (int) $r->id,
                'workshop'     => $place,
                'vehicle_name' => (new VehicleResolver())->labelFor((int) $r->vehicle_id) ?: null,
                'asked_at'     => (string) $r->arrival_ask_at,
                'title'        => 'Kya aap ' . $place . ' par pahunch gaye hain?',
                'body'         => 'Is jagah ka pin save nahi hai. "Haan" dabayein to yeh jagah '
                                . $place . ' ke taur par save ho jayegi aur agli baar khud pata chal jayega.',
                'yes_label'    => 'Haan, yahi jagah hai',
                'no_label'     => 'Nahi, abhi nahi',
            ];
        } catch (\Throwable $e) {
            Log::warning('arrivalPromptFor failed', ['user' => $userId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * ⭐⭐ "HAAN, YAHI JAGAH HAI" — the arrival AND the pin, in one answer.
     *
     * ⚠⚠ IDEMPOTENT BY CONSTRUCTION. The stamp is a conditional update (`whereNull('arrived_at')`)
     *    and `createWorkshop()` already hands back an existing row for a name it has seen, so a
     *    double-tap, a retry on a flaky connection, or two devices answering at once cannot
     *    double-stamp the visit or create a second workshop.
     * ⚠ The PIN is written even if the stamp loses the race — the place is just as real either
     *   way, and losing it would mean asking somebody the same question tomorrow.
     */
    public function confirmArrivalHere($user, int $visitId, float $lat, float $lng): array
    {
        if (!$this->available())   return ['ok' => false, 'message' => 'Workshop visits are not set up yet.'];
        if (!$this->hasArrivalAsk()) return ['ok' => false, 'message' => 'This app is newer than the server.'];

        $v = $this->find($visitId);
        if (!$v) return ['ok' => false, 'message' => 'That visit no longer exists.'];

        $uid = (int) ($user->id ?? 0);
        if ((int) $v['user_id'] !== $uid && !$this->canSchedule($user, true)) {
            return ['ok' => false, 'message' => 'That is not your workshop visit.'];
        }
        if (!empty($v['arrived_at'])) {
            return ['ok' => true, 'already' => true, 'message' => 'Pehle hi mark ho chuka hai.'];
        }

        $place = trim((string) ($v['workshop'] ?? '')) ?: 'Workshop';
        $locationId = $v['location_id'] ?: null;

        // 1) The PIN — the lasting half of the answer.
        try {
            if (!$locationId || !$this->workshopCoords($locationId)) {
                $made = app(\App\Services\Location\CompanyLocationsService::class)
                    ->createWorkshop([
                        'location_name' => $place,
                        'latitude'      => $lat,
                        'longitude'     => $lng,
                        'radius_meters' => 300,
                    ], $uid);
                if (!empty($made['ok']) && !empty($made['location']['id'])) {
                    $locationId = (int) $made['location']['id'];
                    Log::info('Workshop pinned by the rider who went there', [
                        'visit' => $visitId, 'location' => $locationId,
                        'name' => $place, 'by' => $uid,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // ⚠ A pin that cannot be saved must NOT cost him the arrival — he is still there.
            Log::warning('workshop self-pin failed', ['visit' => $visitId, 'error' => $e->getMessage()]);
        }

        // 2) The ARRIVAL — conditional, so a second tap changes nothing.
        $upd = [
            'arrived_at'     => now(),
            'arrived_by'     => $uid,
            'arrived_source' => 'rider_confirmed',
            'updated_at'     => now(),
        ];
        if ($locationId && empty($v['location_id'])) $upd['location_id'] = $locationId;
        if (empty($v['departed_at'])) {
            $upd['departed_at'] = now();
            $upd['departed_by'] = $uid;
        }
        $n = DB::table(self::T_VISIT)->where('id', $visitId)->whereNull('arrived_at')->update($upd);

        if ($n) {
            $this->checkoutMemo = [];
            try {
                app(\App\Services\FirebaseService::class)->notifyWorkshopVisit('arrived', $visitId, $uid);
            } catch (\Throwable $e) {
                Log::warning('arrived push failed', ['visit' => $visitId, 'error' => $e->getMessage()]);
            }
        }

        return ['ok' => true, 'location_id' => $locationId, 'stamped' => (bool) $n,
                'message' => $place . ' par pahunchne ka waqt save ho gaya'
                    . ($locationId ? ' aur yeh jagah bhi save kar li gayi hai.' : '.')];
    }

    /**
     * 📍 A MANAGER VOUCHING THAT HE GOT THERE — no pin, because the manager is not standing
     *    there (owner ruling 4.12, 11-Sep-2026).
     *
     * ⚠⚠ THE ASYMMETRY IS THE POINT. The rider's own "Haan" pins the workshop at his GPS fix,
     *    so the place is right for everyone afterwards. A desk answer carries no location at
     *    all — taking the browser's would pin the workshop to the office, which is worse than
     *    having no pin. So this stamps the arrival and leaves the workshop unpinned; the first
     *    rider who answers on his phone still pins it properly.
     * ⚠ Managers only, and only for a visit that has actually set off.
     */
    public function confirmArrivalByManager($user, int $visitId): array
    {
        if (!$this->available())     return ['ok' => false, 'message' => 'Workshop visits are not set up yet.'];
        if (!$this->hasArrivalAsk()) return ['ok' => false, 'message' => 'This server has not had the Sep-12 update.'];
        if (!$this->canSchedule($user, false) && !$this->canSchedule($user, true)) {
            return ['ok' => false, 'message' => 'You cannot change workshop visits.'];
        }

        $v = $this->find($visitId);
        if (!$v) return ['ok' => false, 'message' => 'That visit no longer exists.'];
        if (!empty($v['arrived_at'])) {
            return ['ok' => true, 'already' => true, 'message' => 'Already marked as arrived.'];
        }
        if (empty($v['departed_at'])) {
            return ['ok' => false, 'message' =>
                'He has not set off yet — use "He has gone" first, so the trip has a start time.'];
        }
        /**
         * ⚠ Refused for a workshop that HAS a pin. Those arrive by geofence, and letting a
         *   manager stamp one by hand would turn a proved arrival into an asserted one — the
         *   whole reason the geofence exists.
         */
        if ($v['location_id'] && $this->workshopCoords($v['location_id'])) {
            return ['ok' => false, 'message' =>
                'That workshop is pinned, so his arrival is detected automatically — no need to mark it.'];
        }

        $uid = (int) ($user->id ?? 0);
        $n = DB::table(self::T_VISIT)->where('id', $visitId)->whereNull('arrived_at')->update([
            'arrived_at'     => now(),
            'arrived_by'     => $uid,
            'arrived_source' => 'manager',
            'updated_at'     => now(),
        ]);
        if ($n) {
            $this->checkoutMemo = [];
            Log::info('Workshop arrival vouched by a manager (workshop has no pin)', [
                'visit' => $visitId, 'by' => $uid,
            ]);
            try {
                app(\App\Services\FirebaseService::class)->notifyWorkshopVisit('arrived', $visitId, $uid);
            } catch (\Throwable $e) {
                Log::warning('arrived push failed', ['visit' => $visitId, 'error' => $e->getMessage()]);
            }
        }
        return ['ok' => true, 'location_id' => null, 'stamped' => (bool) $n,
                'message' => 'Marked as arrived. ⚠ This workshop still has no pin — when a rider '
                           . 'confirms it from his phone the place is saved and this becomes automatic.'];
    }

    /**
     * "Nahi, abhi nahi." Nothing is stamped and nothing is pinned; the cooldown on the row
     * already written by `maybeAskArrival` is what keeps him from being asked again.
     *
     * ⚠ Dismissing the sheet without answering takes this same path (see the client): silence
     *   must never be read as yes, because a wrong yes pins a workshop at the wrong place for
     *   everybody.
     */
    public function declineArrivalHere($user, int $visitId): array
    {
        if (!$this->hasArrivalAsk()) return ['ok' => true, 'message' => ''];
        try {
            $uid = (int) ($user->id ?? 0);
            DB::table(self::T_VISIT)->where('id', $visitId)->where('user_id', $uid)
                ->whereNull('arrived_at')
                ->update(['arrival_ask_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('declineArrivalHere failed', ['visit' => $visitId, 'error' => $e->getMessage()]);
        }
        return ['ok' => true, 'message' => ''];
    }

    /** Metres between two pins. Local so the heartbeat path pulls in no controller. */
    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $R = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * ⭐⭐ EVERY ERRAND IN PROGRESS RIGHT NOW — the ONE source for the store side.
     *
     * The phone banner, the web corner notice and the manager pushes all read this, so
     * "who is away at a workshop" cannot be answered two different ways on two screens.
     * Riders never see it; the audience gate lives in the controller.
     */
    public function liveTrips(): array
    {
        if (!$this->tripEnabled()) return [];
        try {
            $today = \Carbon\Carbon::today()->format('Y-m-d');
            $rows = $this->listVisits(['from' => $today, 'to' => $today, 'limit' => 100]);
            $out = [];
            foreach ($rows as $v) {
                if (!in_array((string) ($v['trip_state'] ?? ''), self::TRIP_ACTIVE, true)) continue;
                $t = $this->decorateTrip($v, (int) $v['user_id'], true);
                if (!$t['is_active']) continue;          // he has checked out
                $t['user_id']    = (int) $v['user_id'];
                $t['rider_name'] = $v['rider_name'];
                $out[] = $t;
            }
            // Those still on the road first — they are the ones a dispatcher must not load up.
            usort($out, fn ($a, $b) => ($a['state'] === self::TRIP_EN_ROUTE ? 0 : 1)
                                     <=> ($b['state'] === self::TRIP_EN_ROUTE ? 0 : 1));
            return $out;
        } catch (\Throwable $e) {
            Log::warning('liveTrips failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * ⭐⭐ "HE NEVER WENT, AND HE HAS GONE HOME" (owner ruling, 10-Sep).
     *
     * Same-day bookings have no approval cut-off any more, so the one thing that must not
     * pass in silence is the rider checking out having never set off. The managers are told
     * ONCE, from the checkout request itself (prod has no scheduler), and the matter ends
     * there for the day.
     *
     * ⚠⚠ The VISIT IS NOT CLOSED. Midnight still makes it MISSED, which is a question a
     *    manager answers — auto-resolving it here would make "he went" and "he never went"
     *    indistinguishable, the one thing this feature must never do (Sep-2 ruling).
     *
     * @return int how many notices were sent (0 or 1 in practice)
     */
    public function alertNotGoneAtCheckout(int $userId): int
    {
        if (!$this->tripEnabled()) return 0;
        $sent = 0;
        try {
            $today = \Carbon\Carbon::today()->format('Y-m-d');
            /**
             * ⚠ PROPOSALS COUNT TOO. A same-day request nobody approved is the other way this
             *   day ends with the bike unserviced, and it is arguably the one the managers most
             *   need to hear — the rider was never even told. The push copy forks on status.
             */
            $statuses = $this->approvalEnabled() ? self::OPEN_STATUSES : self::LIVE_STATUSES;
            $rows = DB::table(self::T_VISIT)
                ->where('user_id', $userId)
                ->whereDate('visit_date', $today)
                ->whereIn('status', $statuses)
                ->whereNull('departed_at')
                ->whereNull('done_at')
                ->whereNull('not_gone_alert_at')
                // ⚠ "He never went" is already explained when the machine lost its keeper —
                //   the managers were pushed then. A second notice about the same day says
                //   nothing new and reads as two separate failures.
                ->whereNull('no_keeper_since')
                ->get(['id']);
            foreach ($rows as $r) {
                // The stamp IS the claim — two checkout posts must not push twice.
                $n = DB::table(self::T_VISIT)->where('id', $r->id)->whereNull('not_gone_alert_at')
                    ->update(['not_gone_alert_at' => now(), 'updated_at' => now()]);
                if (!$n) continue;
                try {
                    app(\App\Services\FirebaseService::class)
                        ->notifyWorkshopVisit('not_gone', (int) $r->id, $userId);
                    $sent++;
                } catch (\Throwable $e) {
                    Log::warning('not_gone push failed', ['visit' => $r->id, 'error' => $e->getMessage()]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('alertNotGoneAtCheckout failed', ['user' => $userId, 'error' => $e->getMessage()]);
        }
        return $sent;
    }

    /**
     * ⭐ THE TRIP STATE OF ONE ROW. Static and tiny on purpose: `shape()` needs it, the
     *   boards need it, and the tests need it without a database.
     *
     * ⚠ A row is only ever "on a trip" on its OWN day. A visit departed yesterday and never
     *   answered is MISSED, not still-in-progress — otherwise a forgotten outcome would keep
     *   a rider marked "at the workshop" for a week.
     */
    /**
     * ⭐⭐ WHERE DOES HE CHECK IN ON THE DAY OF THIS VISIT — 'workshop' or 'regular'.
     *
     * THE ONE ANSWER, shared by the approval push, every rider-facing card and the
     * attendance line. Before this each of them inferred it from `location_id`, which was
     * safe only while "a registered workshop was chosen" and "his day starts there" were the
     * same fact. Since the approver is ASKED (10-Sep-2026) they are two different facts, and
     * a screen that keeps inferring tells him the opposite of the decision.
     *
     * ⚠ NULL `attendance_at` = the row predates the question (or nobody was asked), so the
     *   OLD inference applies and nothing about an existing visit changes.
     *
     * @param array|object $r a visit row or a shaped visit
     */
    public static function checkinAtOf($r): string
    {
        $get = fn (string $k) => is_array($r) ? ($r[$k] ?? null) : ($r->$k ?? null);
        $att = (string) ($get('attendance_at') ?? '');
        if (in_array($att, ['regular', 'workshop'], true)) return $att;
        return !empty($get('location_id')) ? 'workshop' : 'regular';
    }

    public static function tripStateOf(array $r): ?string
    {
        $live = in_array((string) ($r['status'] ?? ''), self::LIVE_STATUSES, true);
        if (!$live) return null;
        if (substr((string) ($r['visit_date'] ?? ''), 0, 10) !== \Carbon\Carbon::today()->format('Y-m-d')) {
            return null;
        }
        if (!empty($r['done_at'])) return self::TRIP_ENDED;
        if (!empty($r['arrived_at']))  return self::TRIP_AT;
        if (!empty($r['departed_at'])) return self::TRIP_EN_ROUTE;
        return self::TRIP_NONE;
    }

    /**
     * ⭐⭐ "IS THIS PERSON ON A WORKSHOP ERRAND RIGHT NOW, AND WHERE IS HE IN IT?"
     *
     * THE ONE ANSWER. Every surface reads this and adds nothing of its own: the live rider
     * card on the orders page, the riders-map Live tab, the store phone's pinned-rider tab
     * and rider picker, the van boards, the dispatch guard, the rider's own line, and the
     * store notices. That is deliberate — the alternative is six screens each deciding what
     * "at the workshop" means, which is exactly how the fleet ended up with seven answers to
     * "how often is this bike due" in August.
     *
     * ⭐ THE LABEL IS COMPOSED HERE TOO. With an ETA, a "since", two audiences and two
     *   languages, a client that formats its own sentence will drift from the next one.
     *
     * @param  bool $withEta  false on batch/board paths that only need the state (the ETA
     *                        costs a GPS read and possibly a cached Google call).
     * @return array{state,visit_id,vehicle_id,vehicle_name,vtype,workshop,visit_time,since,
     *               eta_min,eta_text,label,label_ur,is_active}|null  null = no errand today
     */
    public function tripFor(int $userId, ?string $date = null, bool $withEta = true): ?array
    {
        if (!$this->tripEnabled() || $userId <= 0) return null;
        $day = $date ? substr($date, 0, 10) : \Carbon\Carbon::today()->format('Y-m-d');
        try {
            $rows = $this->listVisits(['user_id' => $userId, 'from' => $day, 'to' => $day, 'limit' => 5]);
            foreach ($rows as $v) {
                if (($v['trip_state'] ?? null) === null) continue;
                return $this->decorateTrip($v, $userId, $withEta);
            }
        } catch (\Throwable $e) {
            Log::warning('tripFor failed', ['user' => $userId, 'error' => $e->getMessage()]);
        }
        return null;
    }

    /**
     * ⭐⭐ "HE IS AT THE WORKSHOP — CARRY ON, BUT KNOW IT." The ONE warning every
     *    order-side door hands back (11-Sep-2026).
     *
     * ⚠⚠ THIS REPLACES A REFUSAL THAT COST A REAL ASSIGNMENT. The assign doors used to
     *    answer 409 `needs_confirmation` and wait for a client to re-send `confirm`. No
     *    client ever learned to, so the store tablet read "Failed to assign rider" and the
     *    order could not be given to him at all. The owner's rule is the opposite way round:
     *    **out for delivery is not on the road** — nothing moves until Dispatch — so assigning
     *    to a man at the workshop is ordinary, and only DISPATCH may stop (and even then with
     *    an override).
     *
     * ⭐ Returns NULL for the overwhelmingly common case, so a caller may splice it straight
     *   into a success payload and a client that has never heard of `warning` is unaffected.
     * ⭐ The SENTENCE IS THE SERVER'S (`tripFor()->label`), the same words the live card, the
     *   van board and the push use — the phone must not compose a second wording.
     * ⚠ Fails OPEN and SILENT: a lookup wobble must never cost an assignment. That is the
     *   whole lesson of the 11-Sep incident.
     *
     * @param  string $context  'assign' | 'out_for_delivery' | 'auto_assign' — logged, so the
     *                          log finally shows these events (the 409 path logged nothing).
     * @return array{kind:string,label:string,label_ur:string,visit_time:?string,
     *               vehicle_name:?string,workshop:?string,state:string}|null
     */
    public function warningFor(int $riderId, string $context = 'assign', array $meta = []): ?array
    {
        if ($riderId <= 0) return null;
        try {
            $trip = $this->tripFor($riderId);
            if (!$trip || empty($trip['is_active'])) return null;

            Log::info('Order assigned to a rider on a workshop trip', [
                    'rider_id' => $riderId,
                    'context'  => $context,
                    'state'    => $trip['state'] ?? null,
                    'visit_id' => $trip['visit_id'] ?? null,
                ] + $meta);

            return [
                'kind'         => 'workshop',
                'label'        => (string) ($trip['label'] ?? 'He is at the workshop'),
                'label_ur'     => (string) ($trip['label_ur'] ?? $trip['label'] ?? ''),
                'visit_time'   => $trip['visit_time']   ?? null,
                'vehicle_name' => $trip['vehicle_name'] ?? null,
                'workshop'     => $trip['workshop']     ?? null,
                'state'        => (string) ($trip['state'] ?? ''),
            ];
        } catch (\Throwable $e) {
            Log::warning('workshop warning skipped', ['rider' => $riderId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * The same answer for a whole board in ONE query — the live rider card asks for every
     * rider on screen, and asking per row is how a 60 ms board becomes a 600 ms one.
     *
     * @return array<int, array> keyed by user id; riders with no errand are absent
     */
    public function tripsFor(array $userIds, ?string $date = null, bool $withEta = true): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$this->tripEnabled() || !$userIds) return [];
        $day = $date ? substr($date, 0, 10) : \Carbon\Carbon::today()->format('Y-m-d');

        $out = [];
        try {
            $rows = DB::table(self::T_VISIT . ' as v')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'v.user_id')
                ->whereIn('v.user_id', $userIds)
                ->whereDate('v.visit_date', $day)
                ->whereIn('v.status', self::LIVE_STATUSES)
                ->orderBy('v.id')
                ->get(['v.*', 'u.fullname as rider_name']);
            if ($rows->isEmpty()) return [];

            $labels = [];
            try {
                $res = new VehicleResolver();
                foreach ($rows->pluck('vehicle_id')->unique() as $vid) {
                    $labels[(int) $vid] = $res->labelFor((int) $vid);
                }
            } catch (\Throwable $e) {
                $labels = [];
            }

            foreach ($rows as $r) {
                $v = $this->shape((array) $r, $labels, []);
                if (($v['trip_state'] ?? null) === null) continue;
                $uid = (int) $v['user_id'];
                // One errand per person per day on the boards: the earliest live one wins,
                // so a second booking cannot make him appear twice with different states.
                if (!isset($out[$uid])) $out[$uid] = $this->decorateTrip($v, $uid, $withEta);
            }
        } catch (\Throwable $e) {
            Log::warning('tripsFor failed', ['error' => $e->getMessage()]);
            return [];
        }
        return $out;
    }

    /**
     * Turn a shaped visit into the trip payload: resolve the workshop's coordinates, work
     * out the ETA from his latest fix, close the trip if he has gone home, and write both
     * sentences.
     */
    private function decorateTrip(array $v, int $userId, bool $withEta): array
    {
        $state = (string) $v['trip_state'];

        /**
         * ⚠⚠ HE HAS GONE HOME. A rider who checks out has ended the errand whatever the row
         *    says — leaving him "at the workshop" on the board overnight would be a lie the
         *    next morning's dispatcher acts on.
         * ⭐ The VISIT is untouched: it still wants its outcome, and midnight still makes it
         *   MISSED if he never went. Ending the TRIP is not answering the QUESTION — that
         *   distinction is the Sep-2 ruling and it holds here.
         */
        if (in_array($state, self::TRIP_ACTIVE, true) && $this->hasCheckedOut($userId, $v['visit_date'])) {
            $state = self::TRIP_ENDED;
        }

        $place  = $v['workshop'] ?: ($this->locationNameFor($v['location_id']) ?: 'the workshop');
        $coords = $this->workshopCoords($v['location_id']);
        $since  = $state === self::TRIP_AT ? $v['arrived_at'] : $v['departed_at'];

        $eta = null;
        if ($withEta && $state === self::TRIP_EN_ROUTE && $coords) {
            $eta = $this->etaToWorkshop($userId, $coords);
        }

        $sinceTxt = $since ? \Carbon\Carbon::parse($since)->format('g:i A') : null;

        // 🗣 Manager copy is English (UI furniture); the rider's own line is Roman Urdu
        //    because it tells him what is happening to him — the Sep-3 copy rule.
        switch ($state) {
            case self::TRIP_EN_ROUTE:
                $label = '🔧 Going to ' . $place . ($eta ? ' · ~' . $eta['minutes'] . ' min' : '');
                $labelUr = $place . ' ki taraf' . ($eta ? ' · ~' . $eta['minutes'] . ' min' : '');
                break;
            case self::TRIP_AT:
                $label = '🔧 At ' . $place . ($sinceTxt ? ' · since ' . $sinceTxt : '');
                $labelUr = $place . ' par' . ($sinceTxt ? ' · ' . $sinceTxt . ' se' : '');
                break;
            case self::TRIP_NONE:
                $label = '🔧 Workshop today' . ($v['visit_time'] ? ' · ' . $v['visit_time'] : '');
                $labelUr = 'Aaj workshop' . ($v['visit_time'] ? ' · ' . $v['visit_time'] : '');
                break;
            default:
                $label = '🔧 Workshop done';
                $labelUr = 'Workshop mukammal';
        }

        return [
            'state'         => $state,
            'is_active'     => in_array($state, self::TRIP_ACTIVE, true),
            'visit_id'      => (int) $v['id'],
            'vehicle_id'    => (int) $v['vehicle_id'],
            'vehicle_name'  => $v['vehicle_name'],
            'vtype'         => $this->vtypeOf((int) $v['vehicle_id']),
            'workshop'      => $place,
            'location_id'   => $v['location_id'],
            'visit_time'    => $v['visit_time'],
            'purpose'       => $v['purpose'],
            'departed_at'   => $v['departed_at'],
            'arrived_at'    => $v['arrived_at'],
            'since'         => $since,
            'since_text'    => $sinceTxt,
            'eta_min'       => $eta['minutes'] ?? null,
            'eta_text'      => $eta ? ('~' . $eta['minutes'] . ' min') : null,
            'eta_source'    => $eta['source'] ?? null,
            'can_depart'    => (bool) $v['can_depart'],
            'no_keeper_since' => $v['no_keeper_since'],
            'label'         => $label,
            'label_ur'      => $labelUr,
        ];
    }

    /** Did he check out on that day? One read, memoised — the boards ask per rider. */
    private function hasCheckedOut(int $userId, string $date): bool
    {
        $key = $userId . '|' . $date;
        if (array_key_exists($key, $this->checkoutMemo)) return $this->checkoutMemo[$key];
        try {
            // ⚠ `logout_time` is the column; there is no `check_out_time` on this table
            //   (the API renames it on the way out, which is easy to be misled by).
            $row = DB::table('t_ops_attendance')
                ->where('user_id', $userId)->whereDate('attendance_date', $date)
                ->first(['logout_time']);
            $out = $row && !empty($row->logout_time);
        } catch (\Throwable $e) {
            // ⚠ Fail OPEN (not checked out): a missing attendance table must never make a
            //   live errand vanish from the board.
            $out = false;
        }
        return $this->checkoutMemo[$key] = $out;
    }

    /** 'bike' | 'van' — so a van's trip reads "Van at Ali Motors" on the van boards. */
    private function vtypeOf(int $vehicleId): string
    {
        try {
            return (string) (DB::table(VehicleService::T_VEHICLE)->where('id', $vehicleId)
                ->value('vtype') ?: 'bike');
        } catch (\Throwable $e) {
            return 'bike';
        }
    }

    private function locationNameFor($locationId): ?string
    {
        if (!$locationId) return null;
        try {
            return DB::table('t_ops_company_locations')->where('id', (int) $locationId)
                ->value('location_name');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The workshop's pin, when it is a registered location. Free-text workshops have none —
     * which is why arrival is never stamped for them and the trip honestly reads "going to"
     * until the rider answers the outcome.
     *
     * @return array{lat:float,lng:float,radius:int}|null
     */
    public function workshopCoords($locationId): ?array
    {
        if (!$locationId) return null;
        $id = (int) $locationId;
        if (array_key_exists($id, self::$coordMemo)) return self::$coordMemo[$id];
        $out = null;
        try {
            $l = DB::table('t_ops_company_locations')->where('id', $id)
                ->first(['latitude', 'longitude', 'radius_meters']);
            if ($l && $l->latitude !== null && $l->longitude !== null) {
                $r = (int) ($l->radius_meters ?? 0);
                $out = ['lat' => (float) $l->latitude, 'lng' => (float) $l->longitude,
                        'radius' => $r > 0 ? $r : self::ARRIVE_RADIUS_M];
            }
        } catch (\Throwable $e) {
            $out = null;
        }
        return self::$coordMemo[$id] = $out;
    }

    /**
     * ⭐⭐ "HOW LONG UNTIL HE GETS THERE" — the owner's definition of arming the GPS.
     *
     * ⚠ NOT a new tracker. The 5-minute heartbeat has always run from check-in to checkout;
     *   what a delivery adds is a STATUS and an ETA, and that is precisely what this errand
     *   was missing. So this reuses `getReturnToOfficeInfo` — already parametric on the
     *   destination, already Google-with-cache and haversine fallback — pointed at the
     *   workshop instead of the office. One ETA engine, two destinations.
     *
     * @return array{minutes:int, source:string}|null  null = no fresh fix to measure from
     */
    private function etaToWorkshop(int $userId, array $coords): ?array
    {
        try {
            $info = app(\App\Http\Controllers\API\RiderController::class)
                ->getReturnToOfficeInfo($userId, $coords['lat'], $coords['lng'], $coords['radius'], true);
            if (!$info || !isset($info['minutes'])) return null;
            return ['minutes' => (int) $info['minutes'], 'source' => $info['source'] ?? 'estimate'];
        } catch (\Throwable $e) {
            Log::debug('workshop ETA skipped', ['user' => $userId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * ⭐⭐ THE MACHINE LOST ITS KEEPER WHILE AN ERRAND WAS OPEN (owner ruling, 10-Sep-2026).
     *
     * *"in this case Shabib will simply remove the bike from his name with the change rider
     * option"* — that is the answer to a bike kept at the workshop overnight. There is no third
     * outcome and no new button; the existing release does the work, and this keeps the errand
     * coherent afterwards.
     *
     * What it does NOT do, deliberately:
     *   • it does not close the visit — somebody still has to say what happened to the bike;
     *   • it does not answer it — auto-completing would erase the difference between "he went"
     *     and "he never went", the one rule this feature is built around (Sep-2);
     *   • it does not blame the rider — he handed the machine back, which is what he was told.
     *
     * ⚠ Only for a machine with NO new keeper. `onHandover()` already moves an errand that has
     *   been passed to somebody else, and that path is the right one — the new holder is the man
     *   who will be at the workshop.
     */
    public function onKeeperLost(int $vehicleId, ?int $fromUserId, ?int $actorId = null): int
    {
        if (!$this->available() || !$this->tripEnabled()) return 0;
        $n = 0;
        try {
            $today = \Carbon\Carbon::today()->format('Y-m-d');
            /**
             * ⚠⚠ WIDENED 10-Sep-2026 (review). This used to stamp only an errand actually
             *    UNDER WAY, on the reasoning that "a visit he never started is simply a day
             *    nobody went on, and the checkout notice covers it". That held while the only
             *    caller was `release()` at the end of a day. It does not hold for the caller
             *    added in the same review — a manager giving him a SPARE at 10am, which is the
             *    ordinary workshop morning:
             *
             *      • the errand is still ahead of him, and he no longer has the bike;
             *      • his phone would keep offering "Workshop jaa raha hoon" for it;
             *      • and the checkout notice fires hours later, if at all.
             *
             *    So a live visit dated TODAY-OR-LATER is stamped too. Nothing is closed and
             *    nothing is answered — the managers are told the machine has nobody to take
             *    it in, which is the fact, and the visit waits for a decision.
             *
             * ⚠ A PAST visit is stamped only if he had actually set off on it; one he never
             *   started is history and the missed-visit question already covers it.
             */
            $rows = DB::table(self::T_VISIT)
                ->where('vehicle_id', $vehicleId)
                ->whereIn('status', self::LIVE_STATUSES)
                ->whereNull('done_at')
                ->whereNull('no_keeper_since')
                ->where(function ($q) use ($today) {
                    $q->where(function ($w) use ($today) {
                        $w->whereDate('visit_date', '<=', $today)->whereNotNull('departed_at');
                    })->orWhereDate('visit_date', '>=', $today);
                })
                ->get(['id']);
            foreach ($rows as $r) {
                $upd = DB::table(self::T_VISIT)->where('id', $r->id)->whereNull('no_keeper_since')
                    ->update(['no_keeper_since' => now(), 'updated_at' => now()]);
                if (!$upd) continue;
                $n++;
                $this->checkoutMemo = [];
                try {
                    app(\App\Services\FirebaseService::class)
                        ->notifyWorkshopVisit('no_keeper', (int) $r->id, (int) ($actorId ?: $fromUserId ?: 0));
                } catch (\Throwable $e) {
                    Log::warning('no_keeper push failed', ['visit' => $r->id, 'error' => $e->getMessage()]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('onKeeperLost failed', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
        }
        return $n;
    }

    /** The rider's own next live visit — his banner, his vehicle card, his attendance line. */
    public function nextForUser(int $userId): ?array
    {
        // ⚠ `from` = today, so a MISSED visit still shows as "next" until it is dealt with —
        //   a rider who did not go must keep seeing it, not have it silently vanish.
        $rows = $this->listVisits(['user_id' => $userId,
                                   'from' => \Carbon\Carbon::today()->subDays(14)->format('Y-m-d'),
                                   'limit' => 5]);
        /**
         * ⚠⚠ …BUT NOT A BIKE HE NO LONGER HAS (10-Sep-2026). When a machine loses its keeper
         *    the errand stays open — somebody must still answer for it — but it stops being
         *    THIS man's instruction, and leaving it on his card asks him to take in a bike
         *    that is not in his hands. The managers were pushed at the moment it happened;
         *    `onHandover()` gives it to the new holder as soon as there is one.
         */
        foreach ($rows as $r) {
            if (!empty($r['no_keeper_since'])) continue;
            /**
             * 🚦⭐ HIS OWN CARD LEARNS WHERE HE IS IN THE ERRAND (11-Sep-2026).
             *
             * ⚠⚠ Until now this row carried only the DATE, so after he pressed "Workshop jaa
             *    raha hoon" his own vehicle page read exactly as it had before he set off —
             *    while every manager's screen had already moved to "going to the workshop".
             *    The man doing the errand was the last person able to see it.
             * ⚠ The label is `tripFor()`'s, not a second wording, and it is null unless a trip
             *   is actually running.
             */
            try {
                $trip = $this->tripFor($userId, null, false);
                $r['trip_state'] = $trip['state'] ?? null;
                $r['trip_label'] = ($trip && !empty($trip['is_active']))
                    ? ($trip['label_ur'] ?? $trip['label'] ?? null) : null;
            } catch (\Throwable $e) {
                $r['trip_state'] = null;
                $r['trip_label'] = null;
            }
            return $r;
        }
        return null;
    }

    /**
     * Visits keyed by "userId|Y-m-d" for a date range — the shift planner and the
     * attendance grid each paint a cell from this in ONE query, rather than asking per row.
     *
     * @return array<string, array>
     */
    /**
     * @param bool $includeProposed ⭐ 6-Sep: the SHIFT PLANNER's grid asks for true, because a
     *        proposal is precisely a question addressed to him and his cell is where he is
     *        looking. Everything else — the attendance grid included — leaves it false: a
     *        proposal is not yet a fact about that day and must not be drawn as one.
     */
    public function mapForRange(array $userIds, string $from, string $to, bool $includeProposed = false): array
    {
        if (!$this->available() || !$userIds) return [];
        try {
            $rows = DB::table(self::T_VISIT)
                ->whereIn('user_id', array_map('intval', $userIds))
                ->whereIn('status', $includeProposed && $this->approvalEnabled()
                    ? array_merge(self::LIVE_STATUSES, ['proposed'])
                    : self::LIVE_STATUSES)
                ->whereDate('visit_date', '>=', $from)
                ->whereDate('visit_date', '<=', $to)
                ->get(['id', 'user_id', 'vehicle_id', 'visit_date', 'visit_time', 'status',
                       'purpose', 'workshop', 'accepted_via', 'accepted_by']);
            $labels = [];
            try {
                $res = new VehicleResolver();
                foreach ($rows->pluck('vehicle_id')->unique() as $vid) {
                    $labels[(int) $vid] = $res->labelFor((int) $vid);
                }
            } catch (\Throwable $e) {
            }
            $out = [];
            foreach ($rows as $r) {
                $key = (int) $r->user_id . '|' . substr((string) $r->visit_date, 0, 10);
                $out[$key] = [
                    'id'           => (int) $r->id,
                    'vehicle_id'   => (int) $r->vehicle_id,
                    'vehicle_name' => $labels[(int) $r->vehicle_id] ?? null,
                    'time'         => $r->visit_time ? substr((string) $r->visit_time, 0, 5) : null,
                    'status'       => (string) $r->status,
                    'accepted'     => (string) $r->status === 'accepted',
                    'accepted_via' => $r->accepted_via,
                    'purpose'      => (string) $r->purpose,
                    'workshop'     => $r->workshop,
                    // ⏳ The planner's cell draws this differently: a request, not a plan.
                    'proposed'     => (string) $r->status === 'proposed',
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('WorkshopVisitService::mapForRange failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * What the management banner shows: upcoming and missed visits.
     * A rider gets only his own — the same endpoint serves both, so there is one
     * audience rule rather than one per surface.
     */
    public function summaryFor($user, bool $mobile = false): array
    {
        $empty = ['count' => 0, 'missed' => 0, 'latest_id' => 0, 'latest' => null,
                  'can_schedule' => false, 'visits' => []];
        if (!$this->available() || !$user) return $empty;

        $isManager = $this->canSchedule($user, $mobile)
            || (method_exists($user, 'hasMobilePermission') && $user->hasMobilePermission(self::ALERT_PERMISSION))
            || (method_exists($user, 'hasPermission') && $user->hasPermission(self::ALERT_PERMISSION));

        $visits = $isManager
            ? $this->listVisits(['limit' => 60])
            : $this->listVisits(['user_id' => (int) $user->id, 'limit' => 20]);

        // ⚠⚠ array_merge, not `+` — see the note in VehicleTicketService::summaryFor.
        if (!$visits) return array_merge($empty, ['can_schedule' => $this->canSchedule($user, $mobile)]);

        /**
         * ⭐⭐ THE BANNER WATERMARK IS AN EVENT INSTANT, NOT A ROW ID (Sep-2 review).
         *
         * Both banners re-fire only when `latest_id` goes UP. Keyed to the visit id it
         * fired once when a visit was created and never again — so "Asim accepted",
         * "Asim's workshop is TOMORROW" and "Asim MISSED it" would all have stayed silent,
         * and the owner asked for the tomorrow notice by name.
         *
         * Each visit therefore contributes the unix time of its LATEST event, and the
         * watermark is the max across the audience. Every event is a fixed instant, so it
         * fires exactly once and never again:
         *   created_at     — set                    accepted_at — the rider confirmed
         *   reminded_at    — became "tomorrow"      done_at     — completed
         *   missed_at      — midnight after the date, DERIVED (no cron; the instant is
         *                    fixed, so once it has passed it simply becomes the max)
         * `latest` is the visit whose event is newest — i.e. what just happened.
         * ⚠ Unix seconds fit the banners' integer compare and AsyncStorage/localStorage.
         */
        $eventTs = function (array $v): int {
            $ts = [];
            foreach (['created_at', 'accepted_at', 'reminded_at', 'done_at'] as $k) {
                if (!empty($v[$k])) {
                    try { $ts[] = \Carbon\Carbon::parse($v[$k])->getTimestamp(); } catch (\Throwable $e) {}
                }
            }
            if ($v['is_missed']) {
                try { $ts[] = \Carbon\Carbon::parse($v['visit_date'])->addDay()->startOfDay()->getTimestamp(); } catch (\Throwable $e) {}
            }
            return $ts ? max($ts) : 0;
        };
        $stamped = array_map(fn ($v) => $v + ['event_ts' => $eventTs($v)], $visits);

        /**
         * ⭐ `latest` is chosen by PRIORITY, not by recency. Two events can land in the
         *   same poll — B becomes missed at 00:00, A's "tomorrow" reminder fires on the
         *   first poll after 00:00 — and a manager must see the missed one first. The
         *   watermark (max event) decides WHETHER the banner fires; priority decides WHAT
         *   it says. Ties inside a priority go to the newest event.
         */
        $rank = fn (array $v): int => $v['is_missed'] ? 0
            : ($v['is_today'] ? 1 : ($v['is_tomorrow'] ? 2 : (!$v['accepted'] ? 3 : 4)));
        usort($stamped, fn ($a, $b) => [$rank($a), $b['event_ts']] <=> [$rank($b), $a['event_ts']]);

        $missed   = array_values(array_filter($stamped, fn ($v) => $v['is_missed']));
        $tomorrow = array_values(array_filter($stamped, fn ($v) => $v['is_tomorrow']));
        return [
            'count'        => count($stamped),
            'missed'       => count($missed),
            'tomorrow'     => count($tomorrow),
            'latest_id'    => (int) max(array_column($stamped, 'event_ts') ?: [0]),
            'latest'       => $stamped[0],
            'can_schedule' => $this->canSchedule($user, $mobile),
            'visits'       => array_slice($stamped, 0, 10),
        ];
    }

    /**
     * Day-before reminders. ⚠ There is NO CRON on prod, so this piggybacks a banner
     * request (`app()->terminating()`), exactly like the service-due push sweep.
     * `reminded_at` makes it fire once per visit no matter how often it is called.
     *
     * @return array<int, array> the visits that were reminded, for the caller to push
     */
    public function dueReminders(): array
    {
        if (!$this->available()) return [];
        try {
            $tomorrow = \Carbon\Carbon::today()->addDay()->format('Y-m-d');
            $rows = DB::table(self::T_VISIT)
                ->whereIn('status', self::LIVE_STATUSES)
                ->whereNull('reminded_at')
                ->whereDate('visit_date', $tomorrow)
                ->get(['id']);
            if ($rows->isEmpty()) return [];
            $ids = $rows->pluck('id')->map('intval')->all();
            DB::table(self::T_VISIT)->whereIn('id', $ids)->update(['reminded_at' => now()]);

            /**
             * ⚠⚠ RETURN ONLY THE ONES JUST FLAGGED. This used to claim `reminded_at` for the
             *    unreminded rows and then return EVERY live visit for tomorrow — so one new
             *    visit dragged everybody else's reminder along with it, and a rider who had
             *    already had "kal workshop jana hai" got it again each time somebody booked.
             *    `approve()` clears `reminded_at`, which creates a fresh row to remind and so
             *    made this fire more often, not less. Owner ruling 7-Sep: already reminded,
             *    do not push again.
             */
            $mine = array_flip($ids);
            return array_values(array_filter(
                $this->listVisits(['from' => $tomorrow, 'to' => $tomorrow, 'limit' => 100]),
                fn ($v) => isset($mine[(int) $v['id']])
            ));
        } catch (\Throwable $e) {
            Log::warning('WorkshopVisitService::dueReminders failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────

    /** @param array<int,string> $names user id → full name, resolved once by the caller. */
    private function shape(array $r, array $labels, array $names = [], array $ticketsByVisit = []): array
    {
        $date   = substr((string) $r['visit_date'], 0, 10);
        $live   = in_array((string) $r['status'], self::LIVE_STATUSES, true);
        /**
         * ⭐ Derived, never stored — no cron could have set a flag (see the class note).
         * ⚠⚠ `date()` / Carbon here are PHP-side and PKT (config app.timezone). NEVER compare
         *    against MySQL CURDATE()/NOW(): the DB session runs 2 hours behind PHP on this
         *    host, so between 00:00 and 02:00 PKT the database still thinks it is yesterday
         *    and a visit "today" would read as tomorrow, a missed one as due.
         */
        // ⚠ Carbon, not native date(): both are PKT here, but only Carbon honours
        //   setTestNow(), which is how the midnight boundary is PROVEN in the test suite.
        $today     = \Carbon\Carbon::today()->format('Y-m-d');
        $tomorrowD = \Carbon\Carbon::today()->addDay()->format('Y-m-d');
        $missed    = $live && $date < $today;

        return [
            'id'                  => (int) $r['id'],
            'vehicle_id'          => (int) $r['vehicle_id'],
            'vehicle_name'        => $labels[(int) $r['vehicle_id']] ?? null,
            'user_id'             => (int) $r['user_id'],
            'rider_name'          => $r['rider_name'] ?? null,
            'visit_date'          => $date,
            'visit_time'          => $r['visit_time'] ? substr((string) $r['visit_time'], 0, 5) : null,
            // ⚠ The free-text name a manager typed, unchanged. `workshop_label` below is what
            //   a SCREEN should print — a registered workshop has a name but no free text.
            'workshop'            => $r['workshop'],
            'workshop_label'      => $r['workshop'] ?: ($r['location_name'] ?? null),
            'location_id'         => $r['location_id'] ? (int) $r['location_id'] : null,
            'purpose'             => (string) $r['purpose'],
            'maintenance_type_id' => $r['maintenance_type_id'] ? (int) $r['maintenance_type_id'] : null,
            'ticket_id'           => $r['ticket_id'] ? (int) $r['ticket_id'] : null,
            /**
             * ⭐ PART B (15-Sep-2026): WHICH issues this visit is answering — so a card can say
             *   "covers 3 reported issues" instead of leaving a manager to guess, and the done
             *   form can pre-tick exactly those.
             * ⚠ Passed IN from the bulk lookup in `listVisits`, never queried per row: with a
             *   few weeks of visits on screen that would be one query per card, the N+1 shape
             *   this file has already been bitten by twice.
             */
            'tickets'             => $ticketsByVisit[(int) $r['id']] ?? [],
            'note'                => $r['note'],
            'status'              => (string) $r['status'],
            'is_live'             => $live,
            /**
             * ⏳ THE APPROVAL STATE, for every screen that draws a visit.
             * ⚠ `is_live` stays FALSE for a proposal — it is what the rider-facing surfaces
             *   key off, and a proposal is not something he has been told about.
             * ⚠ `?? null` throughout: the six approval columns may not exist yet (the PHP
             *   routinely lands before the SQL on a hand-deployed system).
             */
            'is_proposed'         => (string) $r['status'] === 'proposed',
            'is_declined'         => (string) $r['status'] === 'declined',
            'proposed_by'         => !empty($r['proposed_by']) ? (int) $r['proposed_by'] : null,
            'proposed_by_name'    => !empty($r['proposed_by']) ? ($names[(int) $r['proposed_by']] ?? null) : null,
            'approved_by'         => !empty($r['approved_by']) ? (int) $r['approved_by'] : null,
            'approved_by_name'    => !empty($r['approved_by']) ? ($names[(int) $r['approved_by']] ?? null) : null,
            'approved_at'         => !empty($r['approved_at']) ? (string) $r['approved_at'] : null,
            // ⚠ NULL when it was TIME that declined it, not a person (the morning sweep).
            'declined_by_name'    => !empty($r['declined_by']) ? ($names[(int) $r['declined_by']] ?? null) : null,
            'declined_at'         => !empty($r['declined_at']) ? (string) $r['declined_at'] : null,
            'decline_reason'      => $r['decline_reason'] ?? null,
            'is_missed'           => $missed,
            'is_today'            => $live && $date === $today,
            'is_tomorrow'         => $live && $date === $tomorrowD,
            'reminded_at'         => $r['reminded_at'] ? (string) $r['reminded_at'] : null,
            'accepted_at'         => $r['accepted_at'] ? (string) $r['accepted_at'] : null,
            'created_at'          => $r['created_at'] ? (string) $r['created_at'] : null,
            'accepted'            => (string) $r['status'] === 'accepted',
            'accepted_via'        => $r['accepted_via'],
            'accepted_by_name'    => $r['accepted_by_name'] ?? null,
            // ⚠ Rendered as "accepted by X for Y" — a stand-in confirmation must never
            //   be presented as the rider's own.
            'accepted_on_behalf'  => (string) ($r['accepted_via'] ?? '') === 'manager',
            'created_by'          => (int) $r['created_by'],
            'created_by_name'     => $r['created_by_name'] ?? null,
            'superseded_by'       => $r['superseded_by'] ? (int) $r['superseded_by'] : null,
            'done_at'             => $r['done_at'] ? (string) $r['done_at'] : null,
            'outcome_note'        => $r['outcome_note'] ?? null,
            'service_log_id'      => $r['service_log_id'] ? (int) $r['service_log_id'] : null,
            /**
             * 🚦 THE TRIP (Sep-10 2026) — additive; `?? null` throughout because the columns
             *    may not exist yet (the PHP lands before the SQL on a hand-deployed system).
             * ⚠ `trip_state` here is the row's own view. The BOARDS ask `tripFor()`, which
             *   also weighs his attendance (checked out ⇒ the trip is over) and computes the
             *   ETA. Both agree on the first three states by construction — this one simply
             *   cannot see a checkout.
             */
            'attendance_at'       => $r['attendance_at'] ?? null,
            /**
             * 📍 THE RESOLVED ANSWER AND THE RIDER'S OWN SENTENCE (10-Sep-2026).
             *
             * ⚠⚠ The rider was never told WHERE TO CLOCK IN by any screen — only by the
             *    approval push, which said "jagah badal gayi hai" whether it had or not. His
             *    card, his attendance line and the push now read one derivation and print one
             *    sentence, so they cannot send him to two different places.
             * ⚠ Composed HERE, not on the clients: two apps and a blade formatting the same
             *   two-branch sentence is three chances to drift.
             * ⚠ Null for anything that is not a LIVE instruction — a proposal is not something
             *   he has been told about, and a finished visit has no morning left to plan.
             */
            'checkin_at'          => self::checkinAtOf($r),
            /**
             * 📍 THE QUESTION, ON EVERY SCREEN THAT CAN ANSWER IT (10-Sep-2026 review).
             *
             * ⚠⚠ This was built only into `pendingApprovals()`, so the web corner card and the
             *    phone's approval banner asked it — while the OTHER two doors that approve
             *    (the Bikes screen's list and a machine's own card) posted `{}` and told the
             *    planner "his day will be pinned to the workshop" whether it would be or not.
             *    Three doors, one decision, two of them lying about it.
             *
             * ⚠ PROPOSALS ONLY. It costs a shift lookup (cached), and it is meaningless for
             *   anything already decided — so every other row carries null and pays nothing.
             */
            'attendance'          => (string) $r['status'] === 'proposed'
                ? $this->attendanceChoiceFor($r) : null,
            'checkin_line'        => !$live ? null : (self::checkinAtOf($r) === 'workshop'
                ? 'Us din attendance ' . ($r['workshop'] ?: ($r['location_name'] ?? 'workshop')) . ' par karein.'
                : 'Attendance apni normal jagah par hi karein, phir wahan chale jayein.'),
            'departed_at'         => !empty($r['departed_at']) ? (string) $r['departed_at'] : null,
            'departed_by'         => !empty($r['departed_by']) ? (int) $r['departed_by'] : null,
            'departed_by_name'    => !empty($r['departed_by']) ? ($names[(int) $r['departed_by']] ?? null) : null,
            'arrived_at'          => !empty($r['arrived_at']) ? (string) $r['arrived_at'] : null,
            'no_keeper_since'     => !empty($r['no_keeper_since']) ? (string) $r['no_keeper_since'] : null,
            'trip_state'          => self::tripStateOf($r),
            // ⚠ "May he press START?" is a question about TODAY, so it is false on every
            //   other day by construction — a card for tomorrow must never offer the button.
            // ⚠ …and NOT once the machine has lost its keeper (10-Sep-2026). The button would
            //   otherwise sit on the phone of a man who handed the bike back this morning.
            'can_depart'          => $live && $date === $today && empty($r['departed_at'])
                                     && empty($r['no_keeper_since']),
        ];
    }

    private function nameOf(?int $userId): string
    {
        if (!$userId) return 'someone';
        try {
            return (string) (DB::table('t_sys_user')->where('id', $userId)->value('fullname') ?: 'someone');
        } catch (\Throwable $e) {
            return 'someone';
        }
    }
}
