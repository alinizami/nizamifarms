<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\Riders\WorkshopVisitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 🔧 WORKSHOP VISITS — one door for the desk and the phone.
 *
 * ⭐ Every rule is in WorkshopVisitService; the `api*` wrappers only flip
 *   `$mobileContext` so the mobile permission table is consulted instead of the web one.
 *   Same shape as VehicleTicketController and FleetFuelController::apiMarkServiced.
 *
 * ⚠ NO permission middleware on the routes, deliberately: the ACCEPT and PENDING
 *   endpoints must be reachable by a rider who holds no key at all — he is the person
 *   the instruction is for. Scheduling and completing check `schedule_workshop` inside
 *   the service, and `pending` is self-scoped to the caller so it can never leak.
 */
class WorkshopVisitController extends Controller
{
    private bool $mobileContext = false;

    public function __construct(private WorkshopVisitService $visits)
    {
    }

    // ── mobile entries ───────────────────────────────────────────────────────────
    public function apiIndex(Request $r)         { $this->mobileContext = true; return $this->index($r); }
    public function apiStore(Request $r)         { $this->mobileContext = true; return $this->store($r); }
    public function apiAccept(Request $r, $id)   { $this->mobileContext = true; return $this->accept($r, $id); }
    public function apiApprove(Request $r, $id)  { $this->mobileContext = true; return $this->approve($r, $id); }
    public function apiDecline(Request $r, $id)  { $this->mobileContext = true; return $this->decline($r, $id); }
    public function apiApprovals(Request $r)     { $this->mobileContext = true; return $this->approvals($r); }
    public function apiAddWorkshopLocation(Request $r) { $this->mobileContext = true; return $this->addWorkshopLocation($r); }
    public function apiCancel(Request $r, $id)   { $this->mobileContext = true; return $this->cancel($r, $id); }
    public function apiDone(Request $r, $id)     { $this->mobileContext = true; return $this->done($r, $id); }
    public function apiPending(Request $r)       { $this->mobileContext = true; return $this->pending($r); }
    public function apiAlerts(Request $r)        { $this->mobileContext = true; return $this->alerts($r); }
    public function apiWarnings(Request $r)      { $this->mobileContext = true; return $this->warnings($r); }
    public function apiOutcome(Request $r)       { $this->mobileContext = true; return $this->outcome($r); }

    // ─────────────────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $data = $request->validate([
            'user_id'      => 'nullable|integer',
            'vehicle_id'   => 'nullable|integer',
            'from'         => 'nullable|date_format:Y-m-d',
            'to'           => 'nullable|date_format:Y-m-d',
            'include_done' => 'nullable|boolean',
        ]);
        $user      = $request->user() ?: auth()->user();
        $canManage = $this->visits->canSchedule($user, $this->mobileContext);
        $canApprove = $this->visits->canApprove($user, $this->mobileContext);

        // ⚠ Someone who cannot schedule sees only his OWN visits. Without this a rider
        //   hitting the list endpoint would read the whole fleet's movements.
        // ⚠ A PLANNER is not a rider either (6-Sep review): Farooq holds no booking key, and
        //   forcing his list to his own id turned every vehicle-scoped read empty for him.
        if (!$canManage && !$canApprove) {
            $data['user_id'] = (int) $user->id;
        }
        /**
         * ⚠⚠ ONLY A MANAGER SEES PROPOSALS, and only because he asked. A rider must never
         *    receive a proposed day from any endpoint — that is the ruling, and this is the
         *    one list endpoint he can reach. Planners get them too (a planner who is not a
         *    booker still needs to see what is waiting on the machine's page).
         */
        if ($canManage || $canApprove) {
            $data['include_proposed'] = true;
        }

        return response()->json([
            'success'      => true,
            'available'    => $this->visits->available(),
            'can_schedule' => $canManage,
            // ⭐ The UI asks this to decide between "Assign now / Send for approval" and a
            //   plain Send-for-approval — the same question the server then re-decides.
            'can_approve'  => $canApprove,
            'approval_on'  => $this->visits->approvalEnabled(),
            'visits'       => $this->visits->listVisits($data),
            // 📍 Phase 4 — the registered workshops. Picking one makes it that day's shift
            //   location, so checking in there is on time by itself. Empty until a manager
            //   ticks a location as a workshop, and both schedulers then simply show no picker.
            'workshops'    => $this->visits->workshopLocations(),
            // The Adjust picker: which shift a planner may put the rider on for that one day.
            'shifts'       => $canApprove ? $this->visits->shiftTemplates() : [],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  APPROVAL (6-Sep ruling) — a booked workshop day is a request until a planner
    //  says yes. These three doors are the planners'.
    // ─────────────────────────────────────────────────────────────────────────────

    /** Everything waiting on this planner, with the warnings the booker was shown. */
    public function approvals(Request $request)
    {
        $user = $request->user() ?: auth()->user();
        return response()->json([
            'success'     => true,
            'can_approve' => $this->visits->canApprove($user, $this->mobileContext),
            'approval_on' => $this->visits->approvalEnabled(),
            'pending'     => $this->visits->pendingApprovals($user, $this->mobileContext),
            'workshops'   => $this->visits->workshopLocations(),
            'shifts'      => $this->visits->canApprove($user, $this->mobileContext)
                                ? $this->visits->shiftTemplates() : [],
        ]);
    }

    /**
     * 📍 ADD A WORKSHOP WITHOUT LEAVING THE BOOKING FORM (owner + team ruling, 6-Sep).
     *
     * ⭐ Lives here, next to the form that needs it, but writes through the ONE
     *   `CompanyLocationsService::createWorkshop()` the Locations admin page can also use —
     *   so "what makes a workshop usable" is answered in a single place.
     *
     * ⚠ Gate: either key. Qasim (`schedule_workshop`) is the man standing at the workshop
     *   with no location to pick; a planner (`manage_shifts`) may be fixing it for him while
     *   approving. Nobody else — this writes a row that attendance reads.
     */
    public function addWorkshopLocation(Request $request)
    {
        $data = $request->validate([
            'location_name' => 'required|string|max:100',
            // ⚠ Coordinates are required, but they may arrive as numbers OR as a Maps link,
            //   so neither is `required` here — the service refuses when neither yields any.
            'latitude'      => 'nullable|numeric|between:-90,90',
            'longitude'     => 'nullable|numeric|between:-180,180',
            'maps_url'      => 'nullable|string|max:500',
            'radius_meters' => 'nullable|integer|min:100|max:10000',
        ]);
        $user = $request->user() ?: auth()->user();
        if (!$this->visits->canSchedule($user, $this->mobileContext)
            && !$this->visits->canApprove($user, $this->mobileContext)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }

        $res = app(\App\Services\Location\CompanyLocationsService::class)
            ->createWorkshop($data, (int) ($user->id ?? 0));
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        return response()->json([
            'success'   => true,
            'location'  => $res['location'],
            // Returned so the form can re-draw its picker with the new one already ticked,
            // which is the whole "without having to leave the form" requirement.
            'workshops' => $this->visits->workshopLocations(),
            'message'   => $res['message'],
        ]);
    }

    public function approve(Request $request, $id)
    {
        $data = $request->validate([
            // All three are ADJUSTMENTS — absent means "as proposed".
            'location_id'       => 'nullable|integer',
            'visit_time'        => 'nullable|date_format:H:i',
            // ⭐ The planner may also move his START TIME for that one day (Danish's 09:00
            //   visit against a 09:30 shift). Nothing else in the flow could fix that.
            'shift_template_id' => 'nullable|integer',
            /**
             * 📍 "Will he mark attendance at his regular place, or at the workshop?"
             *    (owner ask, 10-Sep-2026). ⚠ ABSENT still means "as proposed" — an older
             *    APK sends nothing and gets exactly the pre-10-Sep inference, so approving
             *    from a phone that has not been updated behaves as it always did.
             */
            'attendance_at'     => 'nullable|in:regular,workshop',
        ]);
        $user = $request->user() ?: auth()->user();
        $res  = $this->visits->approve($user, (int) $id, $data, $this->mobileContext);
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        // ⭐ THIS is where the rider finally hears about it — and the booker hears it went through.
        $this->notify('approved', (int) $id, $user);
        return response()->json(['success' => true, 'pinned' => (bool) ($res['pinned'] ?? false),
                                 'message' => $res['message']]);
    }

    public function decline(Request $request, $id)
    {
        $request->validate(['reason' => 'nullable|string|max:255']);
        $user = $request->user() ?: auth()->user();
        $res  = $this->visits->decline($user, (int) $id, $request->input('reason'), $this->mobileContext);
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        // ⚠ Only the booker is told. The rider was never part of this.
        $this->notify('declined', (int) $id, $user);
        return response()->json(['success' => true, 'message' => $res['message']]);
    }

    /** Advisory checks before a manager commits to a date — never blocking. */
    public function warnings(Request $request)
    {
        $data = $request->validate([
            /**
             * ⚠ 15-Sep: `vehicle_id` is now OPTIONAL. A rider-first booking (the Bikes drawer
             *   knows the man, not the machine) could not ask for warnings at all, and with
             *   Part B it also needs the machine's open issues — so the registry resolves it
             *   here exactly as `schedule()` does, rather than the form guessing.
             */
            'vehicle_id' => 'nullable|integer|required_without:user_id',
            'user_id'    => 'required|integer',
            'visit_date' => 'required|date_format:Y-m-d',
        ]);
        // ⚠ Advice for a SCHEDULER only — it names another rider's approved leave, off day and
        //   who holds the bike, so it must not answer just anyone who is logged in (Sep-4 review).
        $user = $request->user() ?: auth()->user();
        if (!$this->visits->canSchedule($user, $this->mobileContext)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        // ⚠ Resolved the SAME way schedule() resolves it, so the form is warned about — and
        //   offered the issues of — the very machine the booking will land on.
        $vehicleId = (int) ($data['vehicle_id'] ?? 0);
        if (!$vehicleId) {
            try {
                $vehicleId = (int) ((new \App\Services\Riders\VehicleResolver())
                    ->currentVehicleFor((int) $data['user_id']) ?: 0);
            } catch (\Throwable $e) {
                $vehicleId = 0;
            }
        }
        if (!$vehicleId) {
            return response()->json(['success' => true, 'warnings' => [], 'open_tickets' => [],
                                     'vehicle_id' => null]);
        }

        return response()->json([
            'success'    => true,
            'vehicle_id' => $vehicleId,
            'warnings'   => $this->visits->warningsFor(
                $vehicleId, (int) $data['user_id'], $data['visit_date']),
            /**
             * ⭐⭐ PART B (15-Sep-2026) — WHICH ISSUES THIS VISIT COVERS.
             *
             *    The booking form asks "which of this bike's open issues is this trip for?" and
             *    ticks them all by default. Served HERE rather than from a new endpoint because
             *    both forms already call warnings the moment a bike and a date are chosen — one
             *    fetch, one round trip, and no second place that has to agree about which
             *    tickets belong to a machine.
             *
             * ⚠ Manager-only by construction: the `canSchedule` gate above already guards this
             *   whole response, and these are complaint titles.
             */
            // ⚠ `$vehicleId` (resolved above), NOT `$data['vehicle_id']` — which does not exist
            //   at all on a rider-first call, because `validate()` returns only keys that were
            //   sent. That 500'd the whole endpoint for the very path this round added.
            'open_tickets' => $this->visits->openTicketsForVehicle($vehicleId),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            // ⚠ Either is enough: a vehicle-first surface sends the bike, a rider-first
            //   one sends the man and the registry resolves his machine. The service
            //   refuses only when neither yields a bike.
            'vehicle_id'          => 'nullable|integer|required_without:user_id',
            'user_id'             => 'nullable|integer|required_without:vehicle_id',
            'visit_date'          => 'required|date_format:Y-m-d',
            'visit_time'          => 'nullable|date_format:H:i',
            'workshop'            => 'nullable|string|max:120',
            'location_id'         => 'nullable|integer',
            'purpose'             => 'nullable|in:service,repair,inspection,other',
            'maintenance_type_id' => 'nullable|integer',
            'ticket_id'           => 'nullable|integer',
            /**
             * ⭐⭐ PART B (15-Sep-2026): WHICH reported issues this visit is for. The form lists
             *    the machine's open ones and ticks them all by default.
             * ⚠ Validated as a shape only. WHICH ids are allowed is decided in the service
             *   (`resolveTicketIds`), against the machine actually going in and against being
             *   open — a request body is never trusted to name someone else's complaint.
             * ⚠ Additive: `ticket_id` (the old singular) still works, so a thread-first booking
             *   and every older client behave exactly as before.
             */
            'ticket_ids'          => 'nullable|array|max:20',
            'ticket_ids.*'        => 'integer',
            'note'                => 'nullable|string|max:255',
            /**
             * ⭐ 6-Sep: a PLANNER booking his own workshop day is asked "assign now, or send
             *   for approval?" and answers here. Everyone else is sent for approval whatever
             *   they send — the service decides from the caller's rights, never from this
             *   flag, so a crafted payload cannot buy an assignment.
             */
            'send_for_approval'   => 'nullable|boolean',
            /**
             * ⚠ The booker's explicit "yes, change the day he already has" (owner ruling
             *   7-Sep). Without it the service REFUSES a booking that would replace an
             *   already-approved visit and hands back `needs_confirmation` plus exactly what
             *   would change, so the screen can ask before anything happens.
             */
            'confirm_replace'     => 'nullable|boolean',
        ]);

        $user = $request->user() ?: auth()->user();
        $res  = $this->visits->schedule($user, $data, $this->mobileContext);
        if (!$res['ok']) {
            /**
             * ⏳ NOT AN ERROR — a question. The booking would change a workshop day the rider
             * has already been told about, so the screen must ask first and re-send with
             * `confirm_replace`. 409 (Conflict), not 422: a client that does not understand
             * this still shows the message, and shows a real reason rather than "invalid".
             */
            if (!empty($res['needs_confirmation'])) {
                return response()->json([
                    'success'            => false,
                    'needs_confirmation' => true,
                    'replaces'           => $res['replaces'] ?? null,
                    'message'            => $res['message'],
                ], 409);
            }
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        // ⚠⚠ A proposal notifies the PLANNERS. It must NOT notify the rider — that push is
        //    the "instant confirmation" the team ruled out, and sending it here would defeat
        //    the whole flow while everything else about it looked correct.
        $this->notify(!empty($res['proposed']) ? 'proposed' : 'scheduled', (int) $res['visit_id'], $user);

        return response()->json([
            'success'          => true,
            'visit_id'         => (int) $res['visit_id'],
            'rescheduled_from' => $res['rescheduled_from'] ?? null,
            'warnings'         => $res['warnings'] ?? [],
            'proposed'         => (bool) ($res['proposed'] ?? false),
            'message'          => $res['message'],
        ]);
    }

    public function accept(Request $request, $id)
    {
        $user = $request->user() ?: auth()->user();
        $res  = $this->visits->accept($user, (int) $id, $this->mobileContext);
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        $this->notify('accepted', (int) $id, $user);
        return response()->json(['success' => true, 'message' => $res['message']]);
    }

    /**
     * ⭐⭐ "WORKSHOP JAA RAHA HOON" — the rider sets off (owner ask, 10-Sep-2026).
     *
     * The ONE manual step of the trip. Arrival is the geofence's job (no button, by ruling)
     * and the end is the outcome prompt that already exists — so this endpoint is the whole
     * of the new rider-facing surface.
     *
     * ⚠ 409, not 422, when open orders block it: it is a QUESTION with a remedy attached, not
     *   a validation failure, and both clients already know that shape from `confirm_replace`.
     *   `force` is honoured for a MANAGER only (the service enforces that, not this method).
     */
    public function depart(Request $request, $id)
    {
        $request->validate(['force' => 'nullable|boolean']);
        $user = $request->user() ?: auth()->user();
        $res  = $this->visits->depart($user, (int) $id,
                                      ['force' => $request->boolean('force')], $this->mobileContext);
        if (!$res['ok']) {
            return response()->json([
                'success' => false,
                'message' => $res['message'],
                'blocked_by_orders' => (bool) ($res['blocked_by_orders'] ?? false),
                'orders'  => $res['orders'] ?? null,
            ], !empty($res['blocked_by_orders']) ? 409 : 422);
        }
        return response()->json([
            'success' => true,
            'message' => $res['message'],
            'trip'    => $res['trip'] ?? null,
            'assigned_warning' => $res['assigned_warning'] ?? null,
        ]);
    }

    /**
     * ⭐⭐ EVERY ERRAND IN PROGRESS — the ONE source the store side reads.
     *
     * The phone banner, the web corner notice and the pushes all come from here, so "who is
     * away at a workshop right now" cannot be answered two different ways on two screens.
     *
     * ⚠ AUDIENCE: whoever assigns work (`assign_riders`), the shift planners, and the workshop
     *   alert holders. A rider gets an EMPTY list rather than a 403 — the banner then simply
     *   never renders for him, which is how every other notice on these screens behaves.
     */
    public function live(Request $request)
    {
        $user = $request->user() ?: auth()->user();
        if (!$user) return response()->json(['success' => true, 'trips' => []]);

        $may = false;
        try {
            $may = $this->visits->canSchedule($user, $this->mobileContext)
                || $this->visits->canApprove($user, $this->mobileContext)
                || (method_exists($user, 'hasMobilePermission') && $user->hasMobilePermission('assign_riders'))
                || (method_exists($user, 'hasPermission') && $user->hasPermission('assign_riders'))
                || (method_exists($user, 'hasMobilePermission')
                    && $user->hasMobilePermission(\App\Services\Riders\WorkshopVisitService::ALERT_PERMISSION));
        } catch (\Throwable $e) {
            $may = false;
        }
        if (!$may) return response()->json(['success' => true, 'trips' => []]);

        return response()->json(['success' => true, 'trips' => $this->visits->liveTrips()]);
    }

    /** Mobile twins — same methods, so the phone and the desk cannot drift. */
    public function apiDepart(Request $r, $id) { $this->mobileContext = true; return $this->depart($r, $id); }
    public function apiLive(Request $r)        { $this->mobileContext = true; return $this->live($r); }

    public function cancel(Request $request, $id)
    {
        $request->validate(['reason' => 'nullable|string|max:500']);
        $user = $request->user() ?: auth()->user();
        $res  = $this->visits->cancel($user, (int) $id, $request->input('reason'), $this->mobileContext);
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        $this->notify('cancelled', (int) $id, $user);
        return response()->json(['success' => true, 'message' => $res['message']]);
    }

    public function apiVisitTypes(Request $r, $id) { $this->mobileContext = true; return $this->visitTypes($r, $id); }

    /**
     * 🔧 THE JOB LIST FOR **THIS** VISIT'S MACHINE — for whoever is closing it.
     *
     * ⚠⚠ WHY A SECOND ENDPOINT. `/workshop-visits/outcome` is the RIDER's, scoped to his own
     *    awaited visit; a manager closing somebody else's gets nothing from it and the web
     *    dialog therefore had no picker at all — which is half of why Shabib saw two types.
     *    This one is keyed by the visit, so the desk and the phone ask the same question about
     *    the same machine.
     * ⭐ `typesForClose`, so every active job is offered and each row says whether it counts
     *   down. Work that happened must always be recordable.
     */
    public function visitTypes(Request $request, $id)
    {
        $user = $request->user() ?: auth()->user();
        if (!$user) return response()->json(['success' => false, 'message' => 'Not authorised'], 403);

        $v = $this->visits->find((int) $id);
        if (!$v) return response()->json(['success' => false, 'message' => 'That visit no longer exists.'], 404);

        $class = !empty($v['vehicle_id'])
            ? (new \App\Services\Riders\VehicleService())->classOf((int) $v['vehicle_id'])
            : null;

        /**
         * ⭐⭐ "AND IS THE COMPLAINT FIXED?" (owner ruling 6, 15-Sep-2026 — the other half).
         *
         *    The ruling was: do NOT auto-close a ticket when a visit is marked done, because
         *    the RIDER can mark a visit done and only a manager may close a complaint. Instead,
         *    ask the manager at the one moment he actually knows what happened — and make it
         *    one tap. Part B is what makes the question honest: the visit now knows WHICH
         *    issues it went in for, so those arrive PRE-TICKED and the rest do not.
         *
         * ⚠⚠ MANAGERS ONLY. `completionGate` lets the RIDER close out his own visit, and this
         *    list must be empty for him — a rider silently closing his own complaint is the
         *    exact outcome the ruling exists to prevent. The write side re-checks this; the
         *    empty list here just means his form never shows the question.
         */
        $canManageTickets = app(\App\Services\Riders\VehicleTicketService::class)
            ->canManage($user, $this->mobileContext);
        $closeable = [];
        if ($canManageTickets && !empty($v['vehicle_id'])) {
            $linked = $this->visits->linkedTicketIds((int) $id, $v);
            foreach ($this->visits->openTicketsForVehicle((int) $v['vehicle_id']) as $t) {
                $closeable[] = $t + [
                    // ⭐ Pre-ticked only for what this visit actually went in for. An issue the
                    //   trip was never about must not be closed by a manager tapping through.
                    'covered_by_this_visit' => in_array((int) $t['id'], $linked, true),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'booked_type_id' => $v['maintenance_type_id'] ? (int) $v['maintenance_type_id'] : null,
            'vehicle_name'   => $v['vehicle_name'] ?? null,
            'class'          => $class,
            'can_close_tickets' => $canManageTickets,
            'closeable_tickets' => $closeable,
            'types'          => app(\App\Services\Riders\ServiceRecordService::class)->typesForClose($class),
            /**
             * The manager may be paying on the spot, so the SAME accounts the Bikes bill form
             * offers — business unit 1 (Bikes is Nizami Farms operations, never Khaas), stated
             * here exactly as it is there so the two doors cannot drift apart.
             */
            'pay_sources'    => (function () {
                try {
                    return app(\App\Services\FIN\PaymentSourceService::class)->sourcesFor(
                        auth()->user(), 1, \App\Services\FIN\PaymentSourceService::PURPOSE_EXPENSE);
                } catch (\Throwable $e) { return []; }
            })(),
        ]);
    }

    public function apiArrivedHere(Request $r, $id) { $this->mobileContext = true; return $this->arrivedHere($r, $id); }
    public function apiNotHere(Request $r, $id)     { $this->mobileContext = true; return $this->notHere($r, $id); }
    public function apiSnooze(Request $r, $id)      { $this->mobileContext = true; return $this->snooze($r, $id); }

    /**
     * ⏰ "Baad mein (6h)" — this planner has seen it and is not the one dealing with it now.
     *
     * ⭐ Not a decision. The visit stays open for everyone else and returns to him in six
     *   hours if nobody has dealt with it — which is exactly the case the owner described,
     *   where Taimur is only informed until the day Farooq is on leave.
     */
    public function snooze(Request $request, $id)
    {
        $user = $request->user() ?: auth()->user();
        if (!$user) return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        $res = $this->visits->snoozeProposal($user, (int) $id);
        return response()->json([
            'success' => (bool) $res['ok'],
            'message' => $res['message'] ?? '',
        ], $res['ok'] ? 200 : 422);
    }

    /**
     * 📍⭐⭐ "HAAN, YAHI JAGAH HAI" — he is at the workshop, and this IS where it is.
     *
     * ⚠ The coordinates come from the phone's CURRENT fix, not from the ask that was armed
     *   earlier: he may have walked the last fifty metres, and the pin should be where the
     *   workshop is rather than where he first stopped.
     * ⚠ Accuracy is required to be sane here as well as at the ask — this writes a location
     *   every future visit will be judged against, so a 2 km fix must not become the pin.
     */
    public function arrivedHere(Request $request, $id)
    {
        /**
         * ⚠⚠ COORDINATES ARE OPTIONAL, AND THAT IS THE WHOLE DIFFERENCE BETWEEN THE TWO
         *    CALLERS. The RIDER's phone sends its fix, so answering "yes" both stamps the
         *    arrival and PINS the workshop for everyone afterwards. A MANAGER at his desk is
         *    not standing there — he can only vouch that the man arrived, so he stamps and
         *    pins nothing. Requiring a fix from him would have made the desk button impossible;
         *    accepting his browser's location would have pinned the workshop to the office.
         */
        $data = $request->validate([
            'latitude'  => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'accuracy'  => 'nullable|numeric',
        ]);
        $user = $request->user() ?: auth()->user();
        if (!$user) return response()->json(['success' => false, 'message' => 'Not authorised'], 403);

        $hasFix = $request->filled('latitude') && $request->filled('longitude');

        if ($hasFix && $request->filled('accuracy') && (float) $data['accuracy'] > 250) {
            return response()->json(['success' => false, 'message' =>
                'GPS signal abhi kamzor hai — thori der baad dobara koshish karein.'], 422);
        }

        $res = $hasFix
            ? $this->visits->confirmArrivalHere($user, (int) $id,
                (float) $data['latitude'], (float) $data['longitude'])
            : $this->visits->confirmArrivalByManager($user, (int) $id);

        return response()->json([
            'success'     => (bool) $res['ok'],
            'location_id' => $res['location_id'] ?? null,
            'message'     => $res['message'] ?? '',
        ], $res['ok'] ? 200 : 422);
    }

    /** "Nahi, abhi nahi" — and the dismiss, which takes the same path. Never a yes. */
    public function notHere(Request $request, $id)
    {
        $user = $request->user() ?: auth()->user();
        if (!$user) return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        $res = $this->visits->declineArrivalHere($user, (int) $id);
        return response()->json(['success' => (bool) $res['ok']]);
    }

    /**
     * 📷 The visit's proof photo. Same rule and same folder as the Bikes screen's — one
     *    storage shape, so the history list can render either without asking where it came
     *    from. Never fatal: a picture must not cost a closed visit.
     */
    private function storeVisitPhoto(Request $request): ?string
    {
        try {
            $file = $request->file('photo') ?: $request->file('bill_image');
            if (!$file) return null;
            $now  = now();
            $name = 'svc_' . (int) (auth()->id() ?: 0) . '_' . $now->format('Ymd_His') . '_'
                  . substr(bin2hex(random_bytes(3)), 0, 6) . '.jpg';
            $path = 'service-logs/' . $now->format('Y') . '/' . $now->format('m') . '/' . $name;
            \Storage::disk('public')->put($path, file_get_contents($file));
            return $path;
        } catch (\Throwable $e) {
            \Log::warning('workshop visit photo not stored', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * 💰 File the bill the workshop handed over — ONE bill for every job of the trip.
     *
     * ⭐⭐ A thin call to `ServiceRecordService::fileBill`, the ONE bill door the Bikes screen uses
     *    too (29-Sep-2026). This used to be its own copy, and it titled the claim after the job
     *    the visit was BOOKED for rather than the jobs actually done.
     * ⚠ A rider filing his own bill is not "on behalf of" — fileBill decides that from the
     *   same signed-in user RequestController::store reads.
     * ⚠ Non-fatal by design. The service is already recorded; if the money cannot be filed the
     *   rider is told, and a manager can attach the bill later from the vehicle page.
     *
     * ⚠⚠ ONE RECEIPT IS NEVER PAID TWICE (pre-deploy A1, 29-Sep-2026): when any job of this close
     *    already carries a live bill, none is filed and the message names that bill
     *    (`billRecordedVisit`) — this used to bill the other jobs for the FULL amount again.
     *
     * @param array $recorded the result of recordVisit()
     * @param bool  $skipped  set true when a live bill already covered the visit
     */
    private function fileVisitBill(Request $request, array $visit, array $recorded, float $amount, bool &$skipped = false): string
    {
        try {
            if (!\App\Services\Riders\ServiceRecordService::normaliseIds($recorded['service_log_ids'] ?? [])) return '';
            $bill = app(\App\Services\Riders\ServiceRecordService::class)->billRecordedVisit($request, $recorded, [
                'rider_id'    => (int) $visit['user_id'],
                'amount'      => $amount,
                'payment_source_account_id' => $request->input('payment_source_account_id'),
                'description' => 'Filed with the workshop visit on ' . substr((string) $visit['visit_date'], 0, 10) . '.',
            ]);
            if (!empty($bill['skipped_existing'])) {
                $skipped = true;
                return ' ' . $bill['message'];
            }
            return $bill['ok']
                ? ' ' . $bill['message']
                : ' Bill NOT filed: ' . $bill['message'] . ' A manager can add it from the vehicle page.';
        } catch (\Throwable $e) {
            \Log::error('fileVisitBill failed', ['visit' => $visit['id'] ?? null, 'error' => $e->getMessage()]);
            return ' The bill could not be filed — a manager can add it from the vehicle page.';
        }
    }

    public function done(Request $request, $id)
    {
        $data = $request->validate([
            'outcome_note'        => 'nullable|string|max:500',
            // ⚠⚠ happened = 0 is the "Nahi hua" answer: the trip did NOT take place. It is
            //    handled below as its own path and NEVER reaches markDone — the Sep-4 review
            //    found that button recording the visit as done.
            'happened'            => 'nullable|boolean',
            'request_id'          => 'nullable|integer',
            // ⭐ PHASE 3 — the loop closes here. Giving a meter records the service as a
            //   TYPED record through the shared recorder, so a workshop visit ends up in
            //   the same place (and under the same rules) as any other service.
            'meter'               => 'nullable|integer|min:0',
            'maintenance_type_id' => 'nullable|integer',
            // ⭐ 29-Sep-2026: every job the workshop did on this trip — merged with the scalar
            //   above, so a phone that sends only one job behaves exactly as before.
            'maintenance_type_ids'   => 'nullable',
            'service_log_id'      => 'nullable|integer',
            /**
             * 💰 THE BILL, OPTIONAL (owner ruling Q5, 3-Sep). The workshop hands the receipt
             *    over on the day, so the rider can file it with the reading instead of leaving
             *    an un-billed service for a manager to chase.
             * ⚠ Blank is the common case and behaves exactly as before: the work is recorded,
             *   no money moves. Only a figure here spends anything.
             */
            'amount'                    => 'nullable|numeric|min:1|max:9999999',
            'payment_source_account_id' => 'nullable|integer',
            'bill_image'                => 'nullable|image|max:5120',
            /**
             * 📷 THE PROOF PHOTO, INDEPENDENT OF ANY AMOUNT (owner ruling, 11-Sep-2026).
             *    `bill_image` rides on the expense claim and therefore needs money; `photo`
             *    is kept on the service record itself and needs nothing. Both accepted, so a
             *    phone built before this change still gets its picture stored.
             */
            'photo'                     => 'nullable|image|max:5120',
            /**
             * ⭐⭐ RULING 6 (15-Sep-2026): the issues this visit FIXED, closed in the same breath.
             *
             *    Not auto-closed — asked. The manager is already deciding what happened, so it
             *    costs him one tap per issue at the one moment he knows the answer.
             *
             * ⚠ A shape check only. WHO may close and WHICH ids are real is re-decided below
             *   through `VehicleTicketService::close()` — the same engine the thread, the
             *   vehicle panel and the Issues board all press.
             */
            'close_ticket_ids'          => 'nullable|array|max:20',
            'close_ticket_ids.*'        => 'integer',
        ]);

        $user  = $request->user() ?: auth()->user();
        $visit = $this->visits->find((int) $id);
        if (!$visit) {
            return response()->json(['success' => false, 'message' => 'That visit no longer exists.'], 404);
        }

        // "Nahi hua" — the distinct answer. Nothing is closed, no service is recorded; the
        // note reaches the manager and the visit stays due (see reportNotDone).
        if ($request->has('happened') && !$request->boolean('happened')) {
            $res = $this->visits->reportNotDone($user, (int) $id, $data['outcome_note'] ?? null, $this->mobileContext);
            return response()->json([
                'success'   => (bool) $res['ok'],
                'kept_open' => !empty($res['kept_open']),
                'message'   => $res['message'],
            ], $res['ok'] ? 200 : 422);
        }

        // ⚠⚠ GATE FIRST. The service record and the bill below are real writes; markDone()
        //    only runs after them, so its own refusal came too late (Sep-4 review: anyone could
        //    post a meter on someone else's visit, and a re-post on a done visit inserted a
        //    fresh log each time). Same gate markDone applies, asked before anything is written.
        if ($err = $this->visits->completionGate($user, $visit, $this->mobileContext)) {
            return response()->json(['success' => false, 'message' => $err], 422);
        }

        /**
         * 🔒 C3 (29-Sep-2026): one record-and-bill at a time for the visit's rider — the manager
         *    closing it on the web and the rider answering "ho gaya?" on his phone at the same
         *    moment must not record the jobs, or file the bill, twice. Fails open when the lock
         *    store is unavailable.
         */
        $saveLock = \App\Services\Riders\ServiceRecordService::acquireSaveLock((int) ($visit['user_id'] ?? 0));
        if (!$saveLock['ok']) {
            return response()->json(['success' => false, 'busy' => true, 'message' => $saveLock['message']], 409);
        }
        try {
            return $this->recordAndClose($request, $id, $data, $user, $visit);
        } finally {
            ($saveLock['release'])();
        }
    }

    /** The body of done() once the gate has passed and the save lock is held. */
    private function recordAndClose(Request $request, $id, array $data, $user, array $visit)
    {
        $recorded = null;
        $billMsg  = null;
        $billSkipped = false;
        if ($request->filled('meter')) {
            /**
             * ⚠⚠ ORDER MATTERS. The service is recorded FIRST and the visit is closed only
             *    if that succeeded — otherwise a refused type (or an "as conditions" job)
             *    would leave a visit marked done with no service behind it, which is the
             *    silent-hole shape this whole round has been removing.
             *
             * ⚠ The visit's OWN date is used, not today: the work happened when he went.
             * ⚠ The type falls back to the one the visit was booked for, so completing a
             *   scheduled service needs no re-picking.
             */
            $rec  = app(\App\Services\Riders\ServiceRecordService::class);
            /**
             * ⭐ CLASS-AWARE (Sep-2026): the visit names the machine, so the job is
             *   judged against that machine's own schedule — a van completion resolves
             *   against van figures, and a bike-only job on the van is refused with a
             *   sentence instead of recording a service nothing counts down.
             */
            $vKlass = !empty($visit['vehicle_id'])
                ? (new \App\Services\Riders\VehicleService())->classOf((int) $visit['vehicle_id'])
                : null;
            /**
             * ⭐⭐ EVERY JOB DONE ON THIS TRIP (29-Sep-2026), judged by the one rule — one bad id
             *    refuses the lot. With none named, the job the visit was BOOKED for, so completing
             *    a scheduled service still needs no re-picking.
             *
             * ⭐⭐ AN UNSCHEDULED JOB IS STILL RECORDED, IT JUST RESETS NOTHING (owner ruling,
             *    11-Sep-2026: *"if it's not a regular maintenance, if it's something else, he
             *    should be able to add that as well. In which case, no meter will be reset."*)
             *    Each job carries `counts_down` for THIS machine.
             */
            $typeIds = \App\Services\Riders\ServiceRecordService::normaliseIds(
                $request->input('maintenance_type_ids'), $data['maintenance_type_id'] ?? null);
            if (!$typeIds && !empty($visit['maintenance_type_id'])) $typeIds = [(int) $visit['maintenance_type_id']];
            $jobs = $rec->resolveJobs($typeIds, $vKlass);
            if (!$jobs['ok']) {
                return response()->json(['success' => false, 'message' => $jobs['message']], 422);
            }
            $recorded = $rec->recordVisit([
                'rider_id'   => (int) $visit['user_id'],
                /**
                 * ⭐⭐ THE VISIT NAMES THE MACHINE — so the record is stamped with it rather
                 *    than re-derived from "what was this rider on that day" (owner ask,
                 *    10-Sep-2026).
                 *
                 * ⚠⚠ THIS IS THE CASE THE DERIVATION GETS WRONG, and it is the ordinary one:
                 *    the bike goes IN, the manager puts him on a spare for the day, and the
                 *    registry then answers "the spare". The bike that went to the workshop is
                 *    right here on the visit; use it.
                 */
                'vehicle_id' => !empty($visit['vehicle_id']) ? (int) $visit['vehicle_id'] : null,
                'meter'      => (int) $data['meter'],
                'date'       => substr((string) $visit['visit_date'], 0, 10),
                'jobs'       => $jobs['jobs'],
                'actor_id'   => (int) $user->id,
                /**
                 * 📷 THE RIDER'S PROOF, kept whether or not he paid (owner ruling, 11-Sep).
                 *    He is handed a receipt at the counter; the manager who enters the amount
                 *    days later needs to see it. Stored on the service log, so a bill filed
                 *    afterwards inherits it instead of the photo being lost with the moment.
                 */
                'photo_path' => $this->storeVisitPhoto($request),
                'note'       => 'Workshop visit #' . (int) $id
                    . ((int) $visit['user_id'] === (int) $user->id ? ' — confirmed by the rider' : ''),
            ]);
            if (!$recorded['ok']) {
                return response()->json(['success' => false, 'message' => $recorded['message']], 422);
            }
            // ⭐ The visit links to its LEAD job (the first oil service, else the first job).
            //   Remove moves the link to a surviving job of the same visit.
            $data['service_log_id'] = $rec->leadLogId($recorded['service_log_ids'] ?? []);

            /**
             * 💰 …AND ITS BILL, if the workshop handed one over (owner ruling Q5) — ONE bill
             *    for every job of the trip, through the one bill door (`fileBill`).
             *
             * ⚠ ORDER: the service is already recorded above. A bill that fails to file must
             *   NOT lose the reading — the work happened either way — so this only decorates
             *   the receipt message and never changes the outcome of the visit.
             */
            if (!empty($data['amount']) && (float) $data['amount'] > 0) {
                $billMsg = $this->fileVisitBill($request, $visit, $recorded, (float) $data['amount'], $billSkipped);
            }
        }

        $res = $this->visits->markDone($user, (int) $id, $data, $this->mobileContext);
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        // ⭐ 6-Sep: nobody used to be told a visit had closed — not the rider who took the
        //   bike in, not the manager who booked it. A loop that closes silently is one people
        //   stop trusting is closed.
        $this->notify('done', (int) $id, $user);

        /**
         * ⭐⭐ RULING 6 — CLOSE THE ISSUES HE SAYS ARE FIXED, in the same breath.
         *
         * ⚠⚠ AFTER markDone, never before. `markDone` has just put every linked ticket back to
         *    `acknowledged`; closing first would have that write undo the close a second later.
         * ⚠⚠ Through `VehicleTicketService::close()` — the ONE close engine, which re-checks
         *    that this caller is a manager and that the ticket is open. So a RIDER closing out
         *    his own visit cannot close his own complaint by posting ids, whatever his client
         *    sends: the ruling is enforced here, not by the form omitting the question.
         * ⚠ Restricted to the tickets THIS visit was answering, plus any still open on the same
         *   machine — never an arbitrary id from the body.
         * ⚠ Non-fatal. The visit IS done; a close that fails must not turn that into an error
         *   and make a manager mark it done twice.
         */
        $closedNote = '';
        $wanted = array_values(array_unique(array_map('intval', (array) ($data['close_ticket_ids'] ?? []))));
        if ($wanted) {
            $tickets  = app(\App\Services\Riders\VehicleTicketService::class);
            $allowed  = array_column($this->visits->openTicketsForVehicle((int) ($visit['vehicle_id'] ?? 0)), 'id');
            $closedOk = 0;
            foreach (array_intersect($wanted, array_map('intval', $allowed)) as $tid) {
                try {
                    // The outcome note IS the close note — he has already typed what happened,
                    // and asking him to type it twice is how close notes end up empty.
                    $r = $tickets->close($user, (int) $tid, $data['outcome_note'] ?? null, $this->mobileContext);
                    if (!empty($r['ok'])) $closedOk++;
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Issue not closed with the visit',
                        ['visit' => $id, 'ticket' => $tid, 'error' => $e->getMessage()]);
                }
            }
            if ($closedOk) {
                $closedNote = $closedOk . ' ' . ($closedOk === 1 ? 'issue' : 'issues') . ' closed.';
            }
        }

        return response()->json([
            'success'        => true,
            'service_log_id'  => $recorded ? ($data['service_log_id'] ?? null) : null,
            // ⭐ 29-Sep-2026: every job record this close wrote (one per job done).
            'service_log_ids' => $recorded['service_log_ids'] ?? [],
            // ⭐ A1: an amount was given, but a live bill already covers this visit — none filed.
            'bill_skipped_existing' => $billSkipped,
            'tickets_closed' => $closedOk ?? 0,
            'message'        => trim($res['message'] . ' ' . ($recorded['message'] ?? '')
                                     . ' ' . ($billMsg ?? '') . ' ' . $closedNote),
        ]);
    }

    /**
     * ⭐ THE RIDER'S SIDE OF THE LOOP (owner ruling, 2-Sep): "did it get done?" — asked on
     *   the day or after, never before. Self-scoped: it resolves the caller and returns
     *   only a visit addressed to him, so this route needs no permission and can leak
     *   nothing.
     *
     * ⚠ Deliberately no end-of-day auto-complete. Midnight turns an unanswered visit into
     *   MISSED — a question — and this is how it gets answered. Auto-marking it done would
     *   make "he went" and "he never went" look identical.
     */
    public function outcome(Request $request)
    {
        $user = $request->user() ?: auth()->user();
        if (!$user) return response()->json(['success' => true, 'visit' => null]);

        $v = $this->visits->awaitingOutcomeFor((int) $user->id);
        return response()->json([
            'success' => true,
            'visit'   => $v,
            /**
             * 📍 "ARE YOU AT THE WORKSHOP?" — rides on the endpoint the phone ALREADY polls
             *    (every 60s, plus on resume and on `workshop:changed`), so the arrival sheet
             *    needs no timer of its own. That is deliberate: a client-side countdown is
             *    exactly how one of these boxes gets stuck on screen.
             * ⚠ Null whenever the question does not apply — answered, stamped by somebody
             *   else, visit closed, or the ask gone stale — so the sheet closes itself.
             */
            'arrival_prompt' => $this->visits->arrivalPromptFor((int) $user->id),
            /**
             * What the prompt needs to ask for: the job it was booked for, and every OTHER
             * job it might have turned out to be.
             *
             * ⭐ Narrowed to the VISIT'S OWN MACHINE (Sep-2026) — a van driver is not offered
             *   a bike's jobs, and the figures shown are the ones that machine follows.
             * ⚠⚠ `typesForClose`, NOT `scheduledTypes` (11-Sep-2026). The old list answered
             *    "which countdowns can be reset", which on prod is two of four types — so a
             *    manager who had just paid for brake shoes was offered Oil Change or nothing.
             *    Work that happened must always be recordable; each row now carries
             *    `counts_down` so the picker can say which ones reset a clock and which are
             *    simply logged.
             */
            'types'   => $v
                ? app(\App\Services\Riders\ServiceRecordService::class)->typesForClose(
                    !empty($v['vehicle_id'])
                        ? (new \App\Services\Riders\VehicleService())->classOf((int) $v['vehicle_id'])
                        : null)
                : [],
        ]);
    }

    /**
     * The RIDER's own next visit, and whether he still has to accept it.
     * ⭐ Self-scoped to `Auth::id()` — no parameter, so no rider can ask about another.
     */
    public function pending(Request $request)
    {
        $user = $request->user() ?: auth()->user();
        $next = $user ? $this->visits->nextForUser((int) $user->id) : null;
        return response()->json([
            'success'        => true,
            'visit'          => $next,
            'needs_accept'   => $next ? ($next['status'] === 'scheduled') : false,
        ]);
    }

    /** Drives the management banners (web corner card, mobile floating bar). */
    public function alerts(Request $request)
    {
        $user = $request->user() ?: auth()->user();
        $out  = $this->visits->summaryFor($user, $this->mobileContext);

        /**
         * ⭐ The planners' banner rides the same poll rather than a second one — one request
         *   answers "what is happening" and "what is waiting on me".
         * ⚠ Empty for anyone who is not a planner (the service refuses, not the caller).
         */
        $out['pending_approvals'] = $this->visits->pendingApprovals($user, $this->mobileContext);
        $out['can_approve']       = $this->visits->canApprove($user, $this->mobileContext);

        /**
         * 🚦⭐ WHO IS AWAY AT A WORKSHOP RIGHT NOW — promised 10-Sep for the web corner, built
         *    11-Sep. The phone has had this banner since the Sep-10 round; the desk never did,
         *    so a manager on the browser could assign work to a man halfway to Ali Motors with
         *    nothing on screen to tell him. Same `liveTrips()` the phone reads, so the two
         *    surfaces cannot describe the same man differently.
         * ⚠ Non-fatal: the notice must never cost the banner its approvals.
         */
        try {
            $out['live_trips'] = $this->visits->liveTrips();
        } catch (\Throwable $e) {
            $out['live_trips'] = [];
        }

        /**
         * ⏰ ONE implementation (10-Sep-2026): the day-before reminder, the 17:00 planner
         *    nudge and the auto-decline live in `FleetSweepService::workshop()`, which is
         *    also what the `fleet:sweep` cron runs. This poll keeps calling it — the cron
         *    covers a day nobody opens the screen, the poll covers a day the cron is off —
         *    and every push is once-only by construction, so the two cannot double-send.
         * ⚠ Deferred so a slow push never delays the banner.
         */
        try {
            app()->terminating(function () {
                try {
                    app(\App\Services\Riders\FleetSweepService::class)->workshop();
                } catch (\Throwable $e) {
                    \Log::warning('workshop sweep (poll) failed', ['error' => $e->getMessage()]);
                }
            });
        } catch (\Throwable $e) {
        }

        return response()->json(['success' => true] + $out);
    }

    private function notify(string $event, int $visitId, $actor): void
    {
        try {
            app(\App\Services\FirebaseService::class)
                ->notifyWorkshopVisit($event, $visitId, (int) ($actor->id ?? 0));
        } catch (\Throwable $e) {
            Log::warning('Workshop push failed', ['visit' => $visitId, 'error' => $e->getMessage()]);
        }
    }
}
