<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\Riders\VehicleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * The Vehicles view of the Bikes tab (Aug-2026) — registering bikes and the van,
 * and giving them to riders.
 *
 * ACCESS, in two layers, deliberately separate:
 *   READING  — the same three keys that already open Bikes (FleetFuelController::
 *              PERMISSIONS). Anyone who can see running costs can see the fleet
 *              those costs belong to; no new access is created.
 *   WRITING  — `assign_vehicles`, a NEW key (batch 13). Reusing manage_bike_service
 *              would have handed assignment to everyone holding it (Qasim, Adnan,
 *              expense-fund); the owner asked for Shabib + Taimur, with Farooq
 *              addable later by ticking his role.
 *
 * ⚠ THE WRITE GATE IS THE PERMISSION ALONE — never a role-type check. Farooq's
 *   role is type='rider', so any admin|manager|supervisor gate would silently
 *   refuse him even with the box ticked. That exact bug blocked Qasim from filing
 *   bike claims until Jul-29.
 */
class VehicleController extends Controller
{
    /** Same read gate as the Bikes tab — keep in sync with FleetFuelController. */
    const VIEW_PERMISSIONS = ['view_rider_reports', 'web_menu_finance_hub', 'view_bike_costs'];

    /** Photos are the deliverable here, so allow a decent phone photo. */
    const MAX_PHOTO_KB = 8192;

    // =================================================================
    // READ
    // =================================================================

    /** The fleet, plus the roster the assign modal picks from. */
    public function index(Request $request, VehicleService $svc)
    {
        if (!$this->canView()) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        try {
            // Not an error — the screen renders a "run batch 13" note instead. An
            // upload landing before the SQL must degrade, never explode.
            if (!$svc->available()) {
                return response()->json([
                    'success'   => true,
                    'available' => false,
                    'vehicles'  => [], 'riders' => [],
                    'can_manage' => false,
                    'message'   => 'Vehicles are not set up yet (SQL batch 13 has not been run).',
                ]);
            }

            $vehicles = $svc->all($request->boolean('include_retired'));
            $pending  = $this->pendingByVehicle();

            return response()->json([
                'success'    => true,
                'available'  => true,
                'vehicles'   => $vehicles,
                // ⚠ "Which bikes are asking for something?" — the SAME map the phone gets, so
                //   both grids sort by one rule. See FleetAttentionService.
                'attention'  => app(\App\Services\Riders\FleetAttentionService::class)
                                    ->forVehicles($vehicles, $pending),
                'riders'     => $this->roster(),
                'can_manage' => $this->canManage(),
                // Photos are a separate, wider right — see canAddPhotos().
                'can_add_photos' => $this->canAddPhotos(),
                // ⭐ Recording a machine's meter is its own right (manage_bike_service).
                'can_log_meters' => $this->canLogMeters(),
                'transfer_grace_km' => $svc->transferGraceKm(),
                'rules_enabled'     => $svc->rulesEnabled(),
                // 🔁 Which machines have a rider waiting on them right now, so the
                //    card itself says so. Without this the grid looked completely
                //    normal while a request sat unanswered — the manager had to
                //    already know to look at the banner.
                'pending_requests'  => $pending,
            ]);
        } catch (\Throwable $e) {
            Log::error('VehicleController index failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load the fleet'], 500);
        }
    }

    /** One vehicle with its assignment history and condition photos. */
    public function show(Request $request, VehicleService $svc, $id)
    {
        if (!$this->canView()) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        try {
            $v = $svc->find((int) $id);
            if (!$v) return response()->json(['success' => false, 'message' => 'That vehicle no longer exists.'], 404);

            // ⭐ THE MACHINE'S spend — the SAME `claimsForVehicle` the rider's own
            //    profile reads, so a manager and a rider can never be shown two
            //    different reconstructions of one bike's history. Month-scoped;
            //    `?month=YYYY-MM` for the picker, defaults to this month.
            $month = $request->query('month');
            try {
                $m = $month ? \Carbon\Carbon::parse($month . '-01') : \Carbon\Carbon::today();
            } catch (\Throwable $e) {
                $m = \Carbon\Carbon::today();
            }
            $claims = $svc->claimsForVehicle((int) $id,
                $m->copy()->startOfMonth()->format('Y-m-d'),
                $m->copy()->endOfMonth()->format('Y-m-d'));

            $fuel = 0.0; $maint = 0.0;
            foreach ($claims as $c) {
                if (($c['category'] ?? '') === 'Petrol') $fuel += (float) $c['amount'];
                else $maint += (float) $c['amount'];
            }

            return response()->json([
                'success'    => true,
                'vehicle'    => $v,
                'riders'     => $this->roster(),
                'can_manage' => $this->canManage(),
                // Photos are a separate, wider right — see canAddPhotos().
                'can_add_photos' => $this->canAddPhotos(),
                'transfer_grace_km' => $svc->transferGraceKm(),
                'month'      => $m->format('Y-m'),
                'claims'     => $claims,
                // Totalled from the rows above, never a second calculation.
                'claims_total' => ['fuel_rs' => round($fuel, 2), 'maint_rs' => round($maint, 2),
                                   'count' => count($claims)],
                // The bike's Rs/km: selected month vs previous 3 pooled — the
                // SAME method the rider's screen reads.
                'averages'   => $svc->fuelAverages((int) $id, $m->format('Y-m')),
                // The current keeper's stint — the rider-vs-machine diagnostic.
                'keeper_stint' => $svc->keeperStintStats((int) $id),
                // ⭐ Per-type service schedule, keyed to THIS machine (owner ask,
                //   Aug-6) — anchored on the same current meter the header shows.
                'service_schedule' => $svc->serviceScheduleFor((int) $id,
                    isset($v['current_meter']) && $v['current_meter'] !== null ? (int) $v['current_meter'] : null),
                // ⭐ The machine's own service RECORD (owner ask, Aug-13). The list
                //   existed only on the rider's drill-down, so a handover split a
                //   bike's history between two people and neither list was the bike's.
                // ⚠ `all_time=1` lifts the 24-month window (owner: "I can't see lifetime").
                //   Still newest-first and still capped, so the payload cannot run away.
                'service_history' => $svc->serviceHistoryFor((int) $id,
                    $request->boolean('all_time') ? 200 : 24, $request->boolean('all_time')),
                // 💰 What this machine has cost — four windows × regular/repairs/fuel, with
                //   pending money reported separately and "unclassified" never hidden.
                'cost_summary' => $svc->costSummaryFor((int) $id, $m->format('Y-m')),
                /**
                 * ⚠ The DETAIL payload must answer this itself. It did not — only the list
                 *   and the mobile `apiShow` carried it — so anything on the detail that gates
                 *   on it silently rendered nothing. The vehicle-page action buttons hit
                 *   exactly that. Same computation as everywhere else, per request, so a
                 *   newly-granted manager needs no reinstall.
                 */
                'can_log_meters' => $this->canLogMeters(),
            ]);
        } catch (\Throwable $e) {
            Log::error('VehicleController show failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load that vehicle'], 500);
        }
    }

    /**
     * ⭐ THE MONTH'S KILOMETRES, DAY BY DAY — the receipt behind the figure on the
     *    profile (owner ruling, Aug-5).
     *
     * Its OWN endpoint, loaded only when a manager asks for it. The profile popover
     * opens on every "Profile ▸" click; the day list walks each keeper's attendance
     * and both meter anchors, which is real work to do for a panel most openings
     * never scroll to. Lazy keeps the popover as quick as it is today.
     */
    public function days(Request $request, VehicleService $svc, $id)
    {
        if (!$this->canView()) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        try {
            try {
                $m = $request->query('month')
                    ? \Carbon\Carbon::parse($request->query('month') . '-01')
                    : \Carbon\Carbon::today();
            } catch (\Throwable $e) {
                $m = \Carbon\Carbon::today();
            }
            return response()->json(['success' => true]
                + $svc->monthDays((int) $id, $m->format('Y-m')));
        } catch (\Throwable $e) {
            Log::error('VehicleController days failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load the day list'], 500);
        }
    }

    /**
     * ⭐ WHICH MACHINE A CLAIM WILL LAND ON (owner ask, Aug-5).
     *
     * Every fuel/maintenance form — the rider's own, the manager's "for someone
     * else", the web modal — now names the bike before the claim is filed, so the
     * team can see what they are filing against. It follows reassignment by itself
     * because it asks the registry, and it is keyed to the CLAIM'S DATE, not today:
     * a backdated claim lands on the bike he had THAT day (which is exactly how the
     * server stamps it), so the form must say the same thing.
     *
     * Returns the machine only — no costs, no meter history. If the caller may not
     * look up other people's assignments it returns `vehicle: null` rather than an
     * error, so a form never breaks over a label.
     */
    public function forUser(Request $request, \App\Services\Riders\VehicleResolver $res, VehicleService $svc)
    {
        $me = $request->user() ?: auth()->user();
        // No user_id = "which bike is MINE" — what the rider's own form asks.
        $uid  = (int) ($request->query('user_id') ?: ($me->id ?? 0));
        $date = $request->query('date') ?: \Carbon\Carbon::today()->format('Y-m-d');
        try {
            $date = \Carbon\Carbon::parse($date)->format('Y-m-d');
        } catch (\Throwable $e) {
            $date = \Carbon\Carbon::today()->format('Y-m-d');
        }

        $isSelf = $me && (int) $me->id === $uid;
        $mayLook = $isSelf || $this->canView() || $this->mobileAllowed($request);
        if (!$uid || !$mayLook) {
            return response()->json(['success' => true, 'vehicle' => null]);
        }

        try {
            $vid = $res->vehicleForDay($uid, $date);
            if (!$vid) return response()->json(['success' => true, 'vehicle' => null]);

            $v = $svc->find($vid);
            if (!$v) return response()->json(['success' => true, 'vehicle' => null]);

            return response()->json([
                'success' => true,
                'date'    => $date,
                'vehicle' => [
                    'id'          => $v['id'],
                    'name'        => $v['name'],
                    'vtype'       => $v['vtype'],
                    'is_company'  => $v['is_company'],
                    'keeper_name' => $v['keeper_name'] ?? null,
                    'since'       => $v['keeper_since'] ?? null,
                    // The machine's live service state, so a form can show what it is
                    // about to be measured against.
                    'service'     => $v['service'] ?? null,
                ],
                /**
                 * ⭐ WHAT IS ALREADY ON RECORD (owner ask, Aug-16): "show them the last
                 *    maintenance entry so they know what they are entering, what is
                 *    already there, when, and by whom."
                 *
                 * ⭐ SERVED FROM THE ENDPOINT ALL FOUR FILING SURFACES ALREADY CALL —
                 *    the web modal, the manager's mobile form, the rider's own form and
                 *    the store form each fetch this to name the bike. Attaching the
                 *    context here means one implementation, one set of figures, and no
                 *    screen inventing a lookup that could disagree with the panel it
                 *    sits next to. It also means every one of them gains the feature at
                 *    once, with no new permission and no new route.
                 *
                 * Keyed to the MACHINE the claim will land on, not to the person filing
                 * it, so a manager filing for someone else sees that bike's history and
                 * a rider newly given a machine sees what his predecessor had done.
                 */
                'last_maintenance' => $svc->lastMaintenanceFor((int) $vid),

                /**
                 * ⭐⭐ THIS BIKE'S EFFECTIVE SCHEDULE, so a filing picker can say
                 *    "Oil + Tuning — every 2,000 km" using the interval THIS machine
                 *    actually runs (ServiceIntervalResolver) instead of the type's
                 *    standard one.
                 *
                 * ⚠ Every picker (rider form, store form, Bikes modal, web request
                 *   form) labels types from the raw `maint_types` list, which has no
                 *   vehicle in scope — the same mismatch that once printed "every
                 *   1,200 km" in the schedule panel and "every 2,000 km" in the
                 *   Record-service prompt beside it. Served from the endpoint all
                 *   four already call, so they cannot drift apart again.
                 *
                 * ⚠ Memoised per (machine, meter) inside VehicleService, so this is
                 *   free when `find()` above has already resolved the same machine.
                 * Display only — the forms still POST `maintenance_type_id`, and the
                 * server keeps deriving every rule from the type itself.
                 */
                'service_schedule' => $svc->serviceScheduleFor((int) $vid, $svc->currentMeterFor((int) $vid)),
            ]);
        } catch (\Throwable $e) {
            Log::warning('VehicleController forUser failed', ['user' => $uid, 'error' => $e->getMessage()]);
            return response()->json(['success' => true, 'vehicle' => null]);
        }
    }

    /**
     * ⭐⭐ WHAT THIS RIDER ACTUALLY RODE ON THIS DATE — for the New-petrol modal
     *    (Aug-27 2026).
     *
     * THE PROBLEM IT SOLVES. The modal named ONE machine ("recorded against CAD-2958"),
     * resolved from the rider's day, and offered no choice. So on a day a rider arrived
     * on his own bike and took the van out, a manager could only file against the van —
     * the own-bike kilometres the man is actually owed were unreachable from the screen.
     * Worse, he could not see whether that day had already been claimed, so a second
     * claim for the same kilometres looked exactly like a first one.
     *
     * ⭐ Served from the SAME `RiderDayLegs` the rider's phone and the claim guards read,
     *   so the modal cannot offer a machine the server would refuse, and the kilometres
     *   the manager sees are the kilometres that will be paid.
     */
    public function petrolContext(Request $request, \App\Services\Riders\VehicleResolver $res)
    {
        $me   = $request->user() ?: auth()->user();
        $uid  = (int) $request->query('user_id');
        $date = $request->query('date') ?: \Carbon\Carbon::today()->format('Y-m-d');
        try {
            $date = \Carbon\Carbon::parse($date)->format('Y-m-d');
        } catch (\Throwable $e) {
            $date = \Carbon\Carbon::today()->format('Y-m-d');
        }

        $isSelf  = $me && (int) $me->id === $uid;
        $mayLook = $isSelf || $this->canView() || $this->mobileAllowed($request);
        if (!$uid || !$mayLook) {
            return response()->json(['success' => true, 'date' => $date, 'vehicles' => []]);
        }

        try {
            $legs = (new \App\Services\Riders\RiderDayLegs())->forDay($uid, $date);

            // The day's attendance row — a metered claim must name it, exactly as the
            // rider's own app does, or it is not the self-auditing kind.
            $attendanceId = DB::table('t_ops_attendance')
                ->where('user_id', $uid)->where('attendance_date', $date)->value('id');

            // Everything already claimed for that rider+date, so each machine can say
            // for itself whether this would be a second bite.
            $claims = DB::table('t_req_master')
                ->where('requester_user_id', $uid)
                ->where('expense_category', 'Petrol')
                ->whereRaw('COALESCE(expense_date, DATE(created_at)) = ?', [$date])
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->get(array_merge(
                    ['id', 'amount', 'status', 'attendance_id', 'meter_distance', 'request_number'],
                    Schema::hasColumn('t_req_master', 'vehicle_id') ? ['vehicle_id'] : []
                ));

            $petrolRate = $this->petrolRateFor($uid);

            // ⭐⭐ THE DATE MUST BE FILEABLE, OR DO NOT OFFER IT (Aug-28 2026).
            //
            // ⚠⚠ The picker offered a priced, ready-to-send claim for ANY date the day
            //    had kilometres on, while the submit endpoint enforced the backdating
            //    window — so a manager filled the form, pressed Create and got a red
            //    422. The screen must never offer what the server is about to refuse;
            //    that is the same rule the rider's `can_claim` already follows.
            // ⚠ The window depends on WHO is asking: this endpoint also answers a rider
            //   about his own day ($isSelf above), and offering him the manager's 30 days
            //   would be the same "button the server refuses" bug in the other direction.
            $windowDays = (new \App\Services\Riders\FuelClaimRules())->petrolWindowDays(!$isSelf);
            $inWindow = $date >= \Carbon\Carbon::today()->subDays($windowDays)->format('Y-m-d');

            $out = [];
            foreach ($legs as $l) {
                $claim = null;
                foreach ($claims as $c) {
                    if (!empty($c->vehicle_id) && (int) $c->vehicle_id === (int) $l['vehicle_id']) {
                        $claim = $c; break;
                    }
                    // A metered claim nobody stamped belongs to the day's own kilometres.
                    if (empty($c->vehicle_id) && $c->attendance_id && empty($l['is_company'])) {
                        $claim = $c;
                    }
                }
                $out[] = [
                    'vehicle_id'   => $l['vehicle_id'],
                    'label'        => $l['label'],
                    'is_company'   => (bool) $l['is_company'],
                    // 'bike' | 'van' — so the picker can draw the machine it actually is
                    // instead of inferring it from is_company (see RiderDayLegs).
                    'vtype'        => $l['vtype'] ?? 'bike',
                    'km'           => $l['km'],
                    'meter_start'  => $l['meter_start'],
                    'meter_end'    => $l['meter_end'],
                    'source'       => $l['source'],
                    'entered_by_name' => $l['entered_by_name'],
                    // A per-km claim is only possible on his OWN machine, with real
                    // distance, a rate to price it and an attendance row to anchor it.
                    'can_meter_claim' => (empty($l['is_company']) && ($l['km'] ?? 0) > 0
                                          && $petrolRate > 0 && $attendanceId && !$claim
                                          && $inWindow),
                    'suggested_amount' => (empty($l['is_company']) && ($l['km'] ?? 0) > 0 && $petrolRate > 0)
                        ? round(((float) $l['km']) * $petrolRate, 2) : null,
                    'claim' => $claim ? [
                        'id' => (int) $claim->id, 'status' => $claim->status,
                        'amount' => (float) $claim->amount, 'number' => $claim->request_number,
                        'metered' => !empty($claim->attendance_id),
                    ] : null,
                ];
            }

            // Claims that named no machine we can show — surfaced rather than hidden, so
            // "already claimed" is never quietly missing from the manager's view.
            $otherClaims = [];
            foreach ($claims as $c) {
                $shown = false;
                foreach ($out as $row) {
                    if ($row['claim'] && $row['claim']['id'] === (int) $c->id) { $shown = true; break; }
                }
                if (!$shown) {
                    $otherClaims[] = [
                        'id' => (int) $c->id, 'status' => $c->status,
                        'amount' => (float) $c->amount, 'number' => $c->request_number,
                        'metered' => !empty($c->attendance_id),
                    ];
                }
            }

            return response()->json([
                'success'       => true,
                'date'          => $date,
                'attendance_id' => $attendanceId ? (int) $attendanceId : null,
                'petrol_rate'   => $petrolRate,
                'vehicles'      => $out,
                'other_claims'  => $otherClaims,
                // So the modal can say WHY a per-km claim is not on offer for an old
                // date, instead of silently showing a cash-only chip.
                'window_days'   => $windowDays,
                'in_window'     => $inWindow,
            ]);
        } catch (\Throwable $e) {
            Log::warning('petrolContext failed', ['user' => $uid, 'date' => $date, 'error' => $e->getMessage()]);
            // Never break the modal over this — it degrades to the old single-machine hint.
            return response()->json(['success' => true, 'date' => $date, 'vehicles' => []]);
        }
    }

    /**
     * The rider's per-kilometre rate, from his active rate group.
     * ⚠ Same membership test as the rider's own endpoint (a CSV of user ids) — keep the
     *   two in step or the manager would price a claim differently from the app.
     */
    private function petrolRateFor(int $userId): ?float
    {
        try {
            $group = DB::table('t_fin_petrol_rate_group')->where('is_active', 1)->get()
                ->first(function ($g) use ($userId) {
                    if (empty($g->user_ids)) return false;
                    return in_array((string) $userId, array_map('trim', explode(',', $g->user_ids)), true);
                });
            return $group ? (float) $group->rate : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // =================================================================
    // MOBILE (Aug-2026) — the manager's Bikes screen, read only
    // =================================================================

    /**
     * ⭐ THE FLEET IN THE APP (owner ask, Aug-5). The Bikes screen on mobile showed
     *    RIDERS and their costs; the machines themselves — who has what, what each
     *    one costs to run, and now where its kilometres went — were web-only. A
     *    manager in the field could not answer "what has this bike done this month?"
     *
     * ⚠ READ ONLY, and that is deliberate. Assigning, releasing, editing, condition
     *   photos and base pins stay on the web: they are consequential, they want a
     *   real screen, and `assign_vehicles` has never been a mobile grant. These three
     *   endpoints add no write path and no new access — the gate is the SAME mobile
     *   `view_bike_costs` key the Bikes screen already runs on (FleetFuelController::
     *   mobileAllowed), so anyone who can see running costs can see the fleet they
     *   belong to, exactly as on the web.
     */
    public function apiIndex(Request $request, VehicleService $svc)
    {
        if (!$this->mobileAllowed($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        try {
            if (!$svc->available()) {
                return response()->json(['success' => true, 'available' => false, 'vehicles' => []]);
            }
            $vehicles = $svc->all(false);
            $pending  = $this->pendingByVehicle();

            return response()->json([
                'success'   => true,
                'available' => true,
                'vehicles'  => $vehicles,
                // ⚠ "Which bikes are asking for something?" — see FleetAttentionService. The web
                //   grid reads the SAME map and sorts by the same `rank`, so the desk and the
                //   phone cannot disagree about which machine is worst off.
                'attention' => app(\App\Services\Riders\FleetAttentionService::class)
                                  ->forVehicles($vehicles, $pending),
                // ⭐ SEP-2026: the phone CAN now hand a machine over (apiAssign /
                //   apiRelease), so this stops being hard-coded false and becomes the
                //   real answer — `assign_vehicles`, the same key the web asks.
                // ⚠ PAYLOAD-DRIVEN ON PURPOSE, not a mobile permission: mobile
                //   permissions are snapshotted at login and never refreshed, so a
                //   newly-granted manager would see nothing until he reinstalled.
                //   This flag is computed per request, so the buttons appear the
                //   moment the right is given. Old APKs ignore it and stay read-only.
                'can_manage' => $this->canManage(),
                'rules_enabled' => $svc->rulesEnabled(),
                // Same pending map the web grid gets — the phone's machine list
                // must not look calm while a rider is waiting on one of them.
                'pending_requests' => $pending,
                // ⭐ …but condition photos CAN now be added from the phone
                //   (apiAddPhotos). Separate flag on purpose: it is the only
                //   write the app has, and conflating it with can_manage would
                //   light up buttons that would 404.
                'can_add_photos' => $this->canAddPhotos(),
            ]);
        } catch (\Throwable $e) {
            Log::error('VehicleController apiIndex failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load the fleet'], 500);
        }
    }

    /** One machine: its state, its month's claims, its averages and the keeper's stint. */
    public function apiShow(Request $request, VehicleService $svc, $id)
    {
        if (!$this->mobileAllowed($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        try {
            $v = $svc->find((int) $id);
            if (!$v) return response()->json(['success' => false, 'message' => 'That vehicle no longer exists.'], 404);

            $m = $this->safeMonth($request->query('month'));
            $claims = $svc->claimsForVehicle((int) $id,
                $m->copy()->startOfMonth()->format('Y-m-d'),
                $m->copy()->endOfMonth()->format('Y-m-d'));

            $fuel = 0.0; $maint = 0.0;
            foreach ($claims as $c) {
                if (($c['category'] ?? '') === 'Petrol') $fuel += (float) $c['amount'];
                else $maint += (float) $c['amount'];
            }

            return response()->json([
                'success'      => true,
                'vehicle'      => $v,
                'month'        => $m->format('Y-m'),
                'claims'       => $claims,
                'claims_total' => ['fuel_rs' => round($fuel, 2), 'maint_rs' => round($maint, 2),
                                   'count' => count($claims)],
                'averages'     => $svc->fuelAverages((int) $id, $m->format('Y-m')),
                'keeper_stint' => $svc->keeperStintStats((int) $id),
                // Additive — current APKs ignore it; a future one renders the
                // same per-type schedule the web profile shows.
                'service_schedule' => $svc->serviceScheduleFor((int) $id,
                    isset($v['current_meter']) && $v['current_meter'] !== null ? (int) $v['current_meter'] : null),
                /**
                 * 🛠 PAST SERVICES — the same list the web profile shows, claims and
                 * hand-recorded rows together.
                 *
                 * ⚠⚠ The phone had NO service history at all, so a manual record was
                 *    invisible on mobile exactly as it was on the web before Sep-3 — and
                 *    Qasim works in FROZEN MODE ON HIS PHONE. Without this he could record
                 *    a service he could never afterwards see or correct.
                 * Rows carrying `log_id` are the correctable ones (a CLAIM carries money and
                 * is amended through the claims flow instead).
                 */
                // ⚠ `all_time=1` lifts the 24-month window (owner: "I can't see lifetime").
                //   Still newest-first and still capped, so the payload cannot run away.
                'service_history' => $svc->serviceHistoryFor((int) $id,
                    $request->boolean('all_time') ? 200 : 24, $request->boolean('all_time')),
                // 💰 What this machine has cost — four windows × regular/repairs/fuel, with
                //   pending money reported separately and "unclassified" never hidden.
                'cost_summary' => $svc->costSummaryFor((int) $id, $m->format('Y-m')),
                // May this manager record a condition photo from the phone?
                'can_add_photos' => $this->canAddPhotos(),
                // ⭐ …and may he hand the machine over from it? (Sep-2026, see
                //   apiAssign.) Same per-request computation as apiIndex.
                'can_manage'   => $this->canManage(),
                // ✏️ …and may he correct a hand-recorded service? Same right as recording
                //    one (`manage_bike_service`), computed per request like the others so a
                //    newly-granted manager does not have to reinstall.
                'can_log_meters' => $this->canLogMeters(),
                'months'       => $this->recentMonths(),
            ]);
        } catch (\Throwable $e) {
            Log::error('VehicleController apiShow failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load that vehicle'], 500);
        }
    }

    /**
     * ⭐ ADD CONDITION PHOTOS FROM THE PHONE (owner ask, Aug-12).
     *
     * The handover happens in the yard, not at a desk — so the photo has to be
     * takeable where the bike is. Same store, same table, same validation as the
     * web door: this only adds a second entrance, never a second rulebook.
     *
     * ⚠⚠ TWO GATES, BOTH REQUIRED, AND THEY MEAN DIFFERENT THINGS:
     *   • mobileAllowed()  — may he open Bikes on the phone at all
     *                        (`view_bike_costs`, the MOBILE key);
     *   • canAddPhotos()   — may he RECORD CONDITION (`assign_vehicles` OR
     *                        `manage_bike_service`, WEB keys — `hasPermission()`
     *                        reads the web permission table and works identically
     *                        under Sanctum, so one grant governs both surfaces and
     *                        a manager can never be allowed on web but refused on
     *                        the phone). It also refuses read-only accounts, which
     *                        is why the mobile key alone is not enough.
     *
     * ⚠ NOT canManage(): assigning/releasing/editing stay web-only and are a
     *   narrower right. See canAddPhotos() for why the two were separated.
     *
     * Deliberately NOT a new permission key: a key that exists only in SQL stays
     * invisible on the Roles screen, and nobody would remember to tick it.
     */
    public function apiAddPhotos(Request $request, VehicleService $svc, $id)
    {
        if (!$this->mobileAllowed($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        if (!$this->canAddPhotos()) {
            return response()->json([
                'success' => false,
                'message' => 'You can view the fleet but not record photos — '
                    . 'ask for the bike-service or vehicle-assignment right.',
            ], 403);
        }

        $request->validate([
            'photos'   => 'required|array|max:8',
            'photos.*' => 'image|max:' . self::MAX_PHOTO_KB,
            'context'  => 'nullable|string|max:16',
            'note'     => 'nullable|string|max:255',
            'date'     => 'nullable|date',
        ]);

        if (!$svc->find((int) $id)) {
            return response()->json(['success' => false, 'message' => 'That vehicle no longer exists.'], 404);
        }

        // storePhotos streams each file (putFileAs) — a phone sends full-size
        // photos, and reading 8 of them into memory would exhaust PHP's limit.
        $note = $this->storePhotos($request, $svc, (int) $id,
                                   $request->input('context', 'condition'),
                                   $request->input('date'), null, $request->input('note'));

        return response()->json([
            'success' => true,
            'message' => $note ?: 'Nothing was uploaded.',
            'vehicle' => $svc->find((int) $id),
        ]);
    }

    /**
     * 🛢 SERVICE ALERTS — the dismissable banner list for whoever is asking.
     *
     * ONE method serves web and mobile because the audience rule lives in the
     * service, not here: a manager holding `receive_service_alerts` gets the whole
     * fleet, the rider holding a machine gets that machine, anyone else gets an
     * empty list (so the banner never renders rather than being hidden client-side).
     *
     * ⚠ NO view permission is checked. That is deliberate — a rider has no fleet
     *   access at all, yet must be told his own bike is due. `forUser()` is the gate.
     *
     * Also the piggyback point for the push sweep: prod has no scheduler, so the
     * act of a manager or rider opening the app is what drives notifications
     * (throttled to ~once every 30 min, deferred so this response is not slowed).
     */
    public function serviceAlerts(Request $request)
    {
        try {
            $svc = new \App\Services\Riders\BikeServiceAlerts();
            $alerts = $svc->forUser($request->user() ?: auth()->user());
            $svc->fireFromRequest();
            return response()->json(['success' => true, 'alerts' => $alerts]);
        } catch (\Throwable $e) {
            // A broken banner must never break the screen it sits on.
            Log::warning('serviceAlerts failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => true, 'alerts' => []]);
        }
    }

    /** Wave one away — per user, per service cycle (see BikeServiceAlerts::keyFor). */
    public function dismissServiceAlert(Request $request)
    {
        $data = $request->validate(['alert_key' => 'required|string|max:64']);
        $u = $request->user() ?: auth()->user();
        if (!$u) return response()->json(['success' => false], 401);

        return response()->json([
            'success' => (new \App\Services\Riders\BikeServiceAlerts())
                ->dismiss((int) $u->id, $data['alert_key']),
        ]);
    }

    /** The month's kilometres day by day — lazy, exactly as on the web. */
    public function apiDays(Request $request, VehicleService $svc, $id)
    {
        if (!$this->mobileAllowed($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        try {
            return response()->json(['success' => true]
                + $svc->monthDays((int) $id, $this->safeMonth($request->query('month'))->format('Y-m')));
        } catch (\Throwable $e) {
            Log::error('VehicleController apiDays failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load the day list'], 500);
        }
    }

    // =================================================================
    // MOBILE — ASSIGN / RELEASE (Sep-2026). Phase 1 of the handover project.
    // =================================================================

    /**
     * ⭐⭐ HANDOVERS FROM THE PHONE — a SECOND DOOR, never a second rulebook.
     *
     * Assigning was web-only by deliberate design ("assigning, editing and photos
     * stay on the web" — routes/api.php), and photos were the first carve-out. The
     * owner has now asked for the handover itself, because it happens in the yard:
     * Shabib or Taimur is standing next to the van at 8 a.m., not at a desk.
     *
     * ⚠⚠ EVERY ONE OF THESE IS A ONE-LINE DELEGATE TO THE WEB METHOD, ON PURPOSE.
     *    A handover moves money (whose fuel the company buys), demands (who is asked
     *    for meters), cargo and the ride-home timer. A parallel mobile implementation
     *    of that is exactly how two surfaces drift into disagreeing about who has the
     *    bike. So the phone reaches the SAME method, the SAME validation, the SAME
     *    displaced-rider settle and the SAME logging.
     *
     * ⚠ TWO GATES, mirroring apiAddPhotos:
     *   • mobileAllowed() — may he open Bikes on the phone (`view_bike_costs`);
     *   • canManage()     — may he move machines (`assign_vehicles`), checked inside
     *                       the delegate. That is a WEB key read through
     *                       `hasPermission()`, which works identically under Sanctum,
     *                       so one grant governs both surfaces and nobody can be
     *                       allowed on the web but refused on the phone. It also
     *                       refuses read-only accounts.
     *   Deliberately NO new permission key: `assign_vehicles` already means exactly
     *   "may move machines between riders", and a mobile-only twin would drift.
     *
     * ⚠ NOTHING NEW IS GRANTED. Today the set is Shabib + Taimur; ticking
     *   `assign_vehicles` on another role extends both surfaces at once.
     */
    public function apiPreviewAssign(Request $request, VehicleService $svc, $id)
    {
        if (!$this->mobileAllowed($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        return $this->previewAssign($request, $svc, $id);
    }

    public function apiAssign(Request $request, VehicleService $svc, $id)
    {
        if (!$this->mobileAllowed($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        return $this->assign($request, $svc, $id);
    }

    public function apiPreviewRelease(Request $request, VehicleService $svc, $id)
    {
        if (!$this->mobileAllowed($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        return $this->releasePreview($request, $svc, $id);
    }

    public function apiRelease(Request $request, VehicleService $svc, $id)
    {
        if (!$this->mobileAllowed($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        return $this->release($request, $svc, $id);
    }

    /**
     * The rider list the assign sheet picks from — the SAME roster the web screen
     * builds, so the two offer identical choices.
     *
     * ⚠ Its own endpoint rather than riding `apiIndex`: the fleet list is polled by
     *   every phone that can open Bikes, and only the handful who can actually
     *   assign ever need the roster.
     */
    public function apiRoster(Request $request, VehicleService $svc)
    {
        if (!$this->mobileAllowed($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to assign vehicles'], 403);
        }
        return response()->json([
            'success' => true,
            'riders'  => $this->roster(),
            'spare'   => $svc->spareVehicles(),
        ]);
    }

    /* ====================================================================== *
     * 🏠 RIDER HOME LOCATION — the SAME three endpoints for the Bikes tab on
     *    the web and Bikes on the phone (owner ruling, 10-Sep-2026: "all this
     *    should still be one engine and in sync on the web and mobile").
     *
     *    Every one of them is a thin gate over RiderHomePinService. Neither
     *    surface parses a Google link, decides what "moved" means, or stamps
     *    who/when — the service does, once, so the two can never drift.
     *
     * ⚠ The WEB pair and the MOBILE pair differ ONLY in the first check: the
     *   phone must additionally hold the Bikes mobile key (`view_bike_costs`),
     *   because a mobile permission being off means the screen is not meant to
     *   exist for that user at all. The authority to CHANGE a pin is
     *   `assign_vehicles` on both, checked by canManage().
     * ====================================================================== */

    /** WEB — read one rider's pin + who set it + the recent trail. */
    public function homePinShow(Request $request, $userId)
    {
        if (!$this->canView()) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        return response()->json($this->homePinPayload((int) $userId) + ['can_manage' => $this->canManage()]);
    }

    /** WEB — save a pasted link / Plus Code / coordinates. */
    public function homePinSave(Request $request, $userId)
    {
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to change rider home locations'], 403);
        }
        return response()->json($this->doHomePinSave($request, (int) $userId, 'web-bikes'));
    }

    /** WEB — explicit removal. */
    public function homePinClear(Request $request, $userId)
    {
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to change rider home locations'], 403);
        }
        return response()->json($this->doHomePinClear((int) $userId, 'web-bikes'));
    }

    /** MOBILE — read. */
    public function apiHomePinShow(Request $request, $userId)
    {
        if (!$this->mobileAllowed($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        return response()->json($this->homePinPayload((int) $userId) + ['can_manage' => $this->canManage()]);
    }

    /** MOBILE — save. */
    public function apiHomePinSave(Request $request, $userId)
    {
        if (!$this->mobileAllowed($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to change rider home locations'], 403);
        }
        return response()->json($this->doHomePinSave($request, (int) $userId, 'mobile-bikes'));
    }

    /** MOBILE — explicit removal. */
    public function apiHomePinClear(Request $request, $userId)
    {
        if (!$this->mobileAllowed($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to change rider home locations'], 403);
        }
        return response()->json($this->doHomePinClear((int) $userId, 'mobile-bikes'));
    }

    /** The one response shape both surfaces render. */
    private function homePinPayload(int $userId): array
    {
        $svc = new \App\Services\Riders\RiderHomePinService();
        $name = null;
        try {
            $name = DB::table('t_sys_user')->where('id', $userId)->value('fullname');
        } catch (\Throwable $e) { /* cosmetic */ }

        return [
            'success'     => true,
            'user_id'     => $userId,
            'rider_name'  => $name,
            'home_pin'    => $svc->get($userId),
            'history'     => $svc->history($userId, 10),
            'default_radius_m' => $svc->defaultRadius(),
        ];
    }

    /**
     * ⚠ The refusal path matters as much as the happy one: when the text cannot be read
     *   we return `success:false` and DO NOT touch the stored pin, so a bad paste can
     *   never cost a good home location. The caller shows `message` and keeps the sheet
     *   open with what the manager typed still in it.
     */
    private function doHomePinSave(Request $request, int $userId, string $source): array
    {
        $radius = $request->input('home_radius_m');
        $res = (new \App\Services\Riders\RiderHomePinService())->resolveAndSave(
            $userId,
            $request->input('text'),
            $request->input('latitude'),
            $request->input('longitude'),
            ($radius !== null && $radius !== '') ? (int) $radius : null,
            (int) auth()->id(),
            $source
        );

        if (!$res['ok']) {
            return ['success' => false, 'message' => $res['error']] + $this->homePinPayload($userId);
        }

        return [
            'success' => true,
            'moved'   => $res['moved'],
            'message' => $res['moved']
                ? 'Home location saved.'
                : 'That is already the saved home location — nothing changed.',
        ] + $this->homePinPayload($userId);
    }

    private function doHomePinClear(int $userId, string $source): array
    {
        $res = (new \App\Services\Riders\RiderHomePinService())
            ->clear($userId, (int) auth()->id(), $source);

        if (!$res['ok']) {
            return ['success' => false, 'message' => $res['error']] + $this->homePinPayload($userId);
        }

        return [
            'success' => true,
            'message' => $res['cleared'] ? 'Home location removed.' : 'There was no home location to remove.',
        ] + $this->homePinPayload($userId);
    }

    /**
     * The Bikes screen's OWN mobile key — the same gate FleetFuelController uses.
     * Authoritative on purpose: an additive gate would make unticking it useless.
     */
    /**
     * 🔁 [vehicle_id => {id, rider_name, direction, requested_at}] for every OPEN
     * handover request — what the fleet grid stamps on the affected cards.
     *
     * ⚠ Reads the SAME `live()` the banners read, so a card can never claim a
     *   request the banner has already dropped (expired, decided elsewhere, rider
     *   deactivated). Empty for anyone who cannot approve, for the same reason.
     */
    private function pendingByVehicle(): array
    {
        try {
            if (!$this->canManage()) return [];
            $out = [];
            foreach ((new \App\Services\Riders\VehicleHandoverRequestService())->live() as $q) {
                $out[(string) $q['vehicle_id']] = [
                    'id'           => $q['id'],
                    'rider_name'   => $q['rider_name'],
                    'direction'    => $q['direction'],
                    'requested_at' => $q['requested_at'],
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            // A decoration must never take the fleet screen down.
            return [];
        }
    }

    private function mobileAllowed(Request $request): bool
    {
        $u = $request->user();
        if (!$u || !method_exists($u, 'hasMobilePermission')) return false;
        return $u->hasMobilePermission('view_bike_costs');
    }

    /** Never trust a month off the wire, and never let it run into the future. */
    private function safeMonth($raw): \Carbon\Carbon
    {
        try {
            $c = $raw ? \Carbon\Carbon::parse($raw . '-01') : \Carbon\Carbon::today()->startOfMonth();
        } catch (\Throwable $e) {
            $c = \Carbon\Carbon::today()->startOfMonth();
        }
        if ($c->gt(\Carbon\Carbon::today()->startOfMonth())) $c = \Carbon\Carbon::today()->startOfMonth();
        return $c->startOfMonth();
    }

    /** The last 6 months, for the app's month switcher. */
    private function recentMonths(): array
    {
        $out = [];
        $c = \Carbon\Carbon::today()->startOfMonth();
        for ($i = 0; $i < 6; $i++) {
            $out[] = ['value' => $c->format('Y-m'), 'label' => $c->format('M Y')];
            $c->subMonthNoOverflow();
        }
        return $out;
    }

    /**
     * What assigning this vehicle to this rider would do — read-only, so the modal
     * can state the consequences before the manager commits. Deliberately its own
     * endpoint: the sentences are computed from live data (who holds what, whose
     * home pin is missing), not guessed in JavaScript.
     */
    public function previewAssign(Request $request, VehicleService $svc, $id)
    {
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to assign vehicles'], 403);
        }
        $userId = (int) $request->query('user_id');
        if ($userId <= 0) {
            return response()->json(['success' => false, 'message' => 'user_id required'], 422);
        }
        $p = $svc->previewAssign((int) $id, $userId, $request->query('date'));
        return response()->json(['success' => true] + $p);
    }

    // =================================================================
    // WRITE
    // =================================================================

    public function save(Request $request, VehicleService $svc, $id = null)
    {
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to manage vehicles'], 403);
        }
        $data = $request->validate([
            'vtype'      => 'nullable|in:bike,van',
            'reg_no'     => 'nullable|string|max:32',
            'nickname'   => 'nullable|string|max:64',
            'is_company' => 'nullable|boolean',
            'make_model' => 'nullable|string|max:64',
            'notes'      => 'nullable|string|max:255',
            'is_active'  => 'nullable|boolean',
            'service_interval_km' => 'nullable|integer|min:0|max:200000',
        ]);

        $res = $svc->saveVehicle($data, $id ? (int) $id : null, (int) auth()->id());
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        // ⚠ This form can change `service_interval_km`, which the derived schedule
        //   reads — and the derivation is cached across requests. Without this bump a
        //   manager saved a new per-bike schedule here and the card kept showing the
        //   old countdown until the cache expired (verified: 2,500 → 600 invisible).
        //   Bump BEFORE the find() below, so the payload we hand back is already the
        //   new answer rather than the one we just invalidated.
        \App\Services\Riders\VehicleService::bumpServiceEvidence((int) $res['id']);

        return response()->json([
            'success' => true,
            'message' => $id ? 'Vehicle updated.' : 'Vehicle added.',
            'vehicle' => $svc->find((int) $res['id']),
        ]);
    }

    /**
     * Give the vehicle to a rider. Condition photos may ride along in the same
     * request (the manager is standing next to the bike with his phone) — but a
     * photo failure must NEVER undo the assignment, so they are stored
     * best-effort after the fact and reported separately.
     */
    public function assign(Request $request, VehicleService $svc, $id)
    {
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to assign vehicles'], 403);
        }
        $data = $request->validate([
            'user_id' => 'required|integer',
            'date'    => 'nullable|date',
            'note'    => 'nullable|string|max:255',
            'photos'   => 'nullable|array|max:8',
            'photos.*' => 'image|max:' . self::MAX_PHOTO_KB,
            // ⭐ PHASE D — what happens to the rider losing this machine.
            'displaced_action'     => 'nullable|in:none,own,vehicle',
            'displaced_vehicle_id' => 'nullable|integer',
            // ⭐ Sep-2026 — the odometer of the machine the DISPLACED rider lands on.
            //   Same optional, soft-validated contract as `handover_meter` below; it
            //   simply reaches the second assign() instead of the first. Without it
            //   that machine's day stays "shared" and is charged to neither man.
            'displaced_meter'      => 'nullable|integer|min:0|max:9999999',
            // ⭐ Aug-2026 — the odometer as the bike changed hands. OPTIONAL, and
            //   deliberately validated no further than "a number": a handover is a
            //   thing that already happened, and refusing to record it over a
            //   questionable digit would leave the register wrong about who has the
            //   bike. The preview warns; the engine ignores an out-of-range value.
            'handover_meter'       => 'nullable|integer|min:0|max:9999999',
            /**
             * ⭐⭐ R3 (13-Sep-2026) — the CLOSING reading of whatever THIS rider is stepping OFF.
             *    A different number from `handover_meter` (which opens the machine he steps ON
             *    to) and from `displaced_meter` (which opens the machine the OTHER man lands on).
             *    Compulsory when the machine he vacates is a company one; the engine decides.
             */
            'vacated_meter'        => 'nullable|integer|min:0|max:9999999',
        ]);

        // ⚠ `$displacedSettleFollows = true`: we settle the displaced rider right
        //   below, so his ride-home timer is judged AFTER placement (a timer erased
        //   in the gap could never re-arm — Sep-01 review finding).
        $res = $svc->assign((int) $id, (int) $data['user_id'], $data['date'] ?? null,
                            (int) auth()->id(), $data['note'] ?? null,
                            isset($data['handover_meter']) ? (int) $data['handover_meter'] : null,
                            true,
                            isset($data['vacated_meter']) ? (int) $data['vacated_meter'] : null);
        if (!$res['ok']) {
            // ⚠ `meter_missing` names the box the modal must highlight, so one server answer
            //   drives web, store mode and the rider sheet identically.
            return response()->json(array_filter([
                'success' => false,
                'message' => $res['message'],
                'meter_missing'    => $res['meter_missing'] ?? null,
                'meter_vehicle_id' => $res['meter_vehicle_id'] ?? null,
            ], fn ($v) => $v !== null), 422);
        }

        $photoNote = $this->storePhotos($request, $svc, (int) $id, 'handover_in',
                                        $data['date'] ?? null, $res['id'] ?? null);

        $displacedNote = $this->settleDisplacedRider(
            $svc, $res['displaced_user_id'] ?? null,
            $data['displaced_action'] ?? null, $data['displaced_vehicle_id'] ?? null,
            $data['date'] ?? null, (int) $id,
            isset($data['displaced_meter']) ? (int) $data['displaced_meter'] : null
        );

        // Now his placement is final: on company iron his live timer stands, on his
        // own bike / nothing the timer for the machine that left him is cleared.
        if (!empty($res['displaced_user_id'])) {
            $svc->disarmIdleHomeJourney((int) $res['displaced_user_id']);
        }

        return response()->json([
            'success'      => true,
            'message'      => trim($res['message'] . ' ' . $photoNote . ' ' . $displacedNote),
            'changed'      => $res['changed'] ?? true,
            'vehicle'      => $svc->find((int) $id),
        ]);
    }

    /**
     * ⭐ PHASE D — GIVE THE DISPLACED RIDER SOMEWHERE TO LAND.
     *
     * Taking a machine off someone used to be a half-finished act: his assignment
     * closed and nothing else happened, so he silently held nothing while the old
     * profile checkboxes still had him on the company fuel protocol and being asked
     * for meters. Now the manager says explicitly what happens to him, and the three
     * answers are the three real ones:
     *
     *   'vehicle' → he moves onto another machine (picked from the spares)
     *   'own'     → his own registered bike is handed back to him — the case nothing
     *               used to do, which is why a rider's own bike could sit unassigned
     *               and blind for days
     *   'none'    → he genuinely has nothing, and from now on the system stops
     *               asking him for meters instead of pestering him about a bike he
     *               does not have
     *
     * ⚠ RUNS AFTER the main handover, never inside it. The primary assignment is the
     *   thing that must be atomic; if this second step fails, the handover still
     *   stands and the manager can place the rider from the fleet screen. The
     *   reverse — losing a recorded handover because a follow-up failed — is worse.
     *   So this NEVER throws: it returns a sentence, and a failure is reported as a
     *   note rather than as a failed handover.
     */
    private function settleDisplacedRider(VehicleService $svc, ?int $userId,
                                          ?string $action, $vehicleId, ?string $date,
                                          ?int $handedOverVehicleId = null,
                                          ?int $handoverMeter = null): string
    {
        if (!$userId) {
            return '';
        }

        // ⭐ OWNER RULING (Aug-8): a rider with his OWN registered bike falls back to
        //   it BY DEFAULT — "no bike" only when the manager says so specifically.
        //   The live case: Danish gave DCR-799 back on 7 Aug and was left holding
        //   nothing while his own bike sat unassigned, so the registry stopped
        //   asking him for meters the moment he went back to riding it. No answer
        //   from the client (or an older client that never asks) now means "back on
        //   his own bike" whenever that bike exists and is free.
        if (!$action) {
            $action = $svc->ownVehicleFor($userId) ? 'own' : null;
            if (!$action) {
                return '';   // nothing to fall back to — the fleet screen shows him under "no machine"
            }
        }
        if ($action === 'none') {
            return 'The previous rider now has no bike, so he will not be asked for meter readings.';
        }

        try {
            $target = null;
            if ($action === 'own') {
                $own = $svc->ownVehicleFor($userId);
                $target = $own['id'] ?? null;
                if (!$target) return 'His own bike could not be handed back — assign it from the fleet screen.';
            } elseif ($action === 'vehicle') {
                $target = (int) $vehicleId;
                if ($target <= 0) return 'No replacement bike was chosen, so he now has none.';

                // ⭐⭐ VALIDATE THE REPLACEMENT SERVER-SIDE (Sep-2026). Until now any
                //    integer was accepted and passed straight to assign(). Two ways
                //    that bites, both silent:
                //      1. the machine JUST handed over → the settle re-assigns it
                //         BACK to him and quietly undoes the handover that succeeded
                //         a line earlier;
                //      2. a machine a THIRD rider is holding → that man is displaced
                //         with no prompt of his own, which is the one outcome this
                //         whole flow exists to prevent.
                // ⚠ The pickers already filter both cases in JS. This is the server
                //   saying the same thing, because a stale tab, a replayed POST or a
                //   mobile client is not the fleet screen's JavaScript.
                if ($handedOverVehicleId && $target === (int) $handedOverVehicleId) {
                    return 'He could not be moved onto that machine — it is the one that was just handed over.';
                }
                $holder = $svc->keeperOf($target);
                if ($holder && (int) $holder->user_id !== $userId) {
                    return 'He could not be moved onto that machine — somebody else is holding it. Free it first.';
                }
            }

            $r = $svc->assign((int) $target, $userId, $date, (int) auth()->id(), 'Moved after handover',
                              $handoverMeter);
            return ($r['ok'] ?? false)
                ? 'He was moved onto ' . ($svc->find((int) $target)['name'] ?? 'another bike') . '.'
                : 'He could not be moved onto the other bike (' . ($r['message'] ?? 'unknown error') . ').';
        } catch (\Throwable $e) {
            Log::error('settleDisplacedRider failed (handover itself is recorded)', [
                'user_id' => $userId, 'action' => $action, 'error' => $e->getMessage(),
            ]);
            return 'The handover is recorded, but the previous rider could not be placed — do it from the fleet screen.';
        }
    }

    public function release(Request $request, VehicleService $svc, $id)
    {
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to assign vehicles'], 403);
        }
        $data = $request->validate([
            'date'     => 'nullable|date',
            'photos'   => 'nullable|array|max:8',
            'photos.*' => 'image|max:' . self::MAX_PHOTO_KB,
            'displaced_action'     => 'nullable|in:none,own,vehicle',
            'displaced_vehicle_id' => 'nullable|integer',
            'displaced_meter'      => 'nullable|integer|min:0|max:9999999',
            /**
             * ⭐⭐ R3 (13-Sep-2026) — THE MACHINE'S OWN CLOSING READING. Until now `release()`
             *    took no meter at all, so a "Take back" from the web or the store-mode fleet
             *    screen has NEVER recorded a machine's closing odometer: the number had nowhere
             *    to go. Compulsory for a company machine; written to its own meter log.
             */
            'meter'                => 'nullable|integer|min:0|max:9999999',
        ]);

        // The photos are of the state it came BACK in, so they belong to the
        // assignment being closed — grab it before it is released.
        $closing = $svc->keeperOf((int) $id);

        // Same settle-follows contract as assign() above.
        $res = $svc->release((int) $id, $request->input('date'), (int) auth()->id(), true,
                             isset($data['meter']) ? (int) $data['meter'] : null);
        if (!$res['ok']) {
            return response()->json(array_filter([
                'success' => false,
                'message' => $res['message'],
                'meter_missing'    => $res['meter_missing'] ?? null,
                'meter_vehicle_id' => $res['meter_vehicle_id'] ?? null,
            ], fn ($v) => $v !== null), 422);
        }

        $photoNote = $this->storePhotos($request, $svc, (int) $id, 'handover_out',
                                        $request->input('date'), $closing->id ?? null);

        // Taking a bike back displaces its keeper exactly as reassigning does.
        $displacedNote = $this->settleDisplacedRider(
            $svc, $res['displaced_user_id'] ?? null,
            $data['displaced_action'] ?? null, $data['displaced_vehicle_id'] ?? null,
            $request->input('date'), (int) $id,
            isset($data['displaced_meter']) ? (int) $data['displaced_meter'] : null
        );
        if (!empty($res['displaced_user_id'])) {
            $svc->disarmIdleHomeJourney((int) $res['displaced_user_id']);
        }

        return response()->json([
            'success' => true,
            'message' => trim($res['message'] . ' ' . $photoNote . ' ' . $displacedNote),
            'changed' => $res['changed'] ?? true,
            'vehicle' => $svc->find((int) $id),
        ]);
    }

    /** Who loses this machine if it is taken back, and where can he land? */
    public function releasePreview(Request $request, VehicleService $svc, $id)
    {
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to assign vehicles'], 403);
        }
        $current = $svc->keeperOf((int) $id);
        if (!$current) {
            // Nobody holds it — releasing is a no-op, so no reading is owed either.
            return response()->json(['success' => true, 'displaced' => null, 'meters_required' => []]);
        }
        $uid = (int) $current->user_id;
        /**
         * ⭐ R3 — the machine's own CLOSING reading, declared by the server exactly as
         *   `previewAssign` declares its boxes, so the take-back modal and the store-mode sheet
         *   mark the same field compulsory without deciding anything themselves.
         */
        $meters = $svc->needsClosingMeter((int) $id)
            ? [['field' => 'meter', 'vehicle_id' => (int) $id,
                'label' => $svc->find((int) $id)['name'] ?? 'this machine', 'which' => 'closing',
                'prompt' => ($svc->find((int) $id)['name'] ?? 'This machine') . ' — meter reading as it comes back']]
            : [];
        return response()->json([
            'success'   => true,
            'meters_required' => $meters,
            'displaced' => [
                'user_id'    => $uid,
                'name'       => DB::table('t_sys_user')->where('id', $uid)->value('fullname') ?: 'the current rider',
                'own'        => $svc->ownVehicleFor($uid),
                // Company machines only — same reasoning as previewAssign's list.
                'spare'      => array_values(array_filter($svc->spareVehicles(),
                                    fn ($s) => $s['id'] !== (int) $id && $s['is_company'])),
                'goes_quiet' => $svc->rulesEnabled(),
            ],
        ]);
    }

    /** Set or clear the vehicle's fixed overnight base (the van's parking). */
    public function setBase(Request $request, VehicleService $svc, $id)
    {
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to manage vehicles'], 403);
        }
        $data = $request->validate([
            'latitude'  => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'radius_m'  => 'nullable|integer|min:50|max:5000',
            'clear'     => 'nullable|boolean',
        ]);

        $clear = !empty($data['clear']);
        $res = $svc->setBase(
            (int) $id,
            $clear ? null : (isset($data['latitude'])  ? (float) $data['latitude']  : null),
            $clear ? null : (isset($data['longitude']) ? (float) $data['longitude'] : null),
            isset($data['radius_m']) ? (int) $data['radius_m'] : null,
            (int) auth()->id()
        );
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        return response()->json([
            'success' => true, 'message' => $res['message'], 'vehicle' => $svc->find((int) $id),
        ]);
    }

    /** Add condition photos on their own (not tied to a handover). */
    public function addPhotos(Request $request, VehicleService $svc, $id)
    {
        // ⭐ Photos are their OWN right (canAddPhotos) — a bike-service manager may
        //   record condition without being able to move machines between riders.
        //   Web and phone must answer this identically or the same man would be
        //   allowed on one screen and refused on the other.
        if (!$this->canAddPhotos()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to record vehicle photos'], 403);
        }
        $request->validate([
            'photos'   => 'required|array|max:8',
            'photos.*' => 'image|max:' . self::MAX_PHOTO_KB,
            'context'  => 'nullable|string|max:16',
            'note'     => 'nullable|string|max:255',
            'date'     => 'nullable|date',
        ]);

        $ctx  = $request->input('context', 'condition');
        $note = $this->storePhotos($request, $svc, (int) $id, $ctx, $request->input('date'), null,
                                   $request->input('note'));

        return response()->json([
            'success' => true,
            'message' => $note ?: 'Nothing was uploaded.',
            'vehicle' => $svc->find((int) $id),
        ]);
    }

    /**
     * "He was on a different bike that day."
     *
     * The ONE place `t_ops_attendance.vehicle_id` is ever written. Everything else
     * about which machine a day was on is derived from the assignment timeline
     * (VehicleResolver) — this exists for the exception the timeline cannot know:
     * a bike borrowed for an afternoon, a breakdown, a swap nobody recorded at the
     * time. Sending `vehicle_id` empty clears the override and hands the day back
     * to the timeline.
     *
     * Manager-only by ruling (`assign_vehicles`) — riders do not declare their own.
     */
    public function dayOverride(Request $request, \App\Services\Riders\VehicleResolver $res)
    {
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to assign vehicles'], 403);
        }
        $data = $request->validate([
            'user_id'    => 'required|integer',
            'date'       => 'required|date',
            'vehicle_id' => 'nullable|integer',
        ]);

        try {
            if (!\Illuminate\Support\Facades\Schema::hasColumn('t_ops_attendance', 'vehicle_id')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vehicles are not set up on this server yet (SQL batch 13).',
                ], 422);
            }

            $date = \Carbon\Carbon::parse($data['date'])->format('Y-m-d');
            $uid  = (int) $data['user_id'];
            $vid  = !empty($data['vehicle_id']) ? (int) $data['vehicle_id'] : null;

            // Only a day the rider actually has an attendance row for — there is
            // nowhere else to hang the override, and inventing a row would create a
            // phantom working day out of a bookkeeping correction.
            $row = DB::table('t_ops_attendance')
                ->where('user_id', $uid)->whereDate('attendance_date', $date)
                ->first(['id']);
            if (!$row) {
                return response()->json([
                    'success' => false,
                    'message' => 'There is no attendance record for that rider on ' . $date . ', so there is no day to correct.',
                ], 422);
            }

            if ($vid) {
                $exists = DB::table(VehicleService::T_VEHICLE)->where('id', $vid)->exists();
                if (!$exists) {
                    return response()->json(['success' => false, 'message' => 'That vehicle no longer exists.'], 422);
                }
            }

            DB::table('t_ops_attendance')->where('id', $row->id)->update([
                'vehicle_id'     => $vid,
                'vehicle_source' => $vid ? 'manager' : null,
            ]);
            \App\Services\Riders\VehicleResolver::flush();
            // ⚠ A day override moves that day's claims (and its readings) between
            //   machines, so BOTH the machine gaining the day and the one losing it
            //   have a different derivation now. The config counter is the honest
            //   blunt instrument here — we do not know which machine lost the day.
            \App\Services\Riders\VehicleService::bumpServiceConfig();

            $label = $vid ? $res->labelFor($vid) : null;
            Log::info('Vehicle day override set', [
                'user_id' => $uid, 'date' => $date, 'vehicle_id' => $vid, 'by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => $vid
                    ? 'Recorded: he was on ' . $label . ' on ' . $date . '.'
                    : 'Cleared — that day follows the normal assignment again.',
                'vehicle_id' => $vid,
                'label'      => $label,
            ]);
        } catch (\Throwable $e) {
            Log::error('dayOverride failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not save that change.'], 500);
        }
    }

    /** What a rider was on for each day of a month — powers the drill-down chips. */
    public function riderDays(Request $request, \App\Services\Riders\VehicleResolver $res)
    {
        if (!$this->canView()) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        $uid   = (int) $request->query('rider_id');
        $month = $request->query('month') ?: date('Y-m');
        if ($uid <= 0) {
            return response()->json(['success' => false, 'message' => 'rider_id required'], 422);
        }

        try {
            $from = \Carbon\Carbon::parse($month . '-01')->startOfMonth()->format('Y-m-d');
            $to   = \Carbon\Carbon::parse($month . '-01')->endOfMonth()->format('Y-m-d');

            $rows = DB::table('t_ops_attendance')
                ->where('user_id', $uid)
                ->whereBetween('attendance_date', [$from, $to])
                ->orderBy('attendance_date')
                ->get(['attendance_date', 'vehicle_id', 'vehicle_source']);

            // ⚠ Resolved from ONE read of this rider's assignment history rather than
            //   per day. Calling vehicleForDay() in the loop re-queried the override
            //   table for all ~31 days — and the override is already in the row we
            //   just fetched. Same for the transfer check, which only needs the set of
            //   dates on which any of his assignments started or ended.
            $windows = DB::table(VehicleService::T_ASSIGN)
                ->where('user_id', $uid)
                ->orderBy('assigned_on')->orderBy('id')
                ->get(['vehicle_id', 'assigned_on', 'released_on']);

            $default = DB::table('t_ops_rider_profile')->where('user_id', $uid)->value('default_vehicle_id');

            $out = [];
            foreach ($rows as $r) {
                $d = substr((string) $r->attendance_date, 0, 10);

                if ($r->vehicle_id) {
                    $vid = (int) $r->vehicle_id;
                } else {
                    // Open row first, then the latest start — the same precedence
                    // VehicleResolver applies on a transfer day.
                    $vid = null; $bestOpen = false; $bestFrom = null;
                    foreach ($windows as $w) {
                        $f = substr((string) $w->assigned_on, 0, 10);
                        $t = $w->released_on ? substr((string) $w->released_on, 0, 10) : null;
                        if ($f > $d || ($t !== null && $t < $d)) continue;
                        $isOpen = $t === null;
                        if ($vid === null || ($isOpen && !$bestOpen) || (($isOpen === $bestOpen) && $f >= $bestFrom)) {
                            $vid = (int) $w->vehicle_id; $bestOpen = $isOpen; $bestFrom = $f;
                        }
                    }
                    if ($vid === null && $default) $vid = (int) $default;
                }

                $out[] = [
                    'date'       => $d,
                    'vehicle_id' => $vid,
                    'label'      => $res->labelFor($vid),   // cached per vehicle
                    'overridden' => $r->vehicle_id !== null,
                    'transfer'   => false,                  // filled in below
                ];
            }

            // Transfer days, keyed on the VEHICLE across ALL its keepers. Deriving it
            // from this rider's own windows would disagree whenever a day was
            // overridden onto a machine he was never formally assigned to.
            //
            // ⭐ Aug-2026: this used to be a third hand-written copy of the rule,
            //   carrying a comment that it "matched VehicleResolver::isTransferDay
            //   exactly" — which is precisely the kind of promise that quietly stops
            //   being true. It now ASKS the resolver, which is the one definition.
            $vids = array_values(array_unique(array_filter(array_column($out, 'vehicle_id'))));
            if ($vids) {
                foreach ($out as &$row) {
                    if ($row['vehicle_id']) {
                        $row['transfer'] = $res->isTransferDay((int) $row['vehicle_id'], $row['date']);
                    }
                }
                unset($row);
            }

            return response()->json(['success' => true, 'days' => $out, 'can_manage' => $this->canManage()]);
        } catch (\Throwable $e) {
            Log::error('riderDays failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not load those days'], 500);
        }
    }

    public function deletePhoto(Request $request, VehicleService $svc, $photoId)
    {
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to manage vehicles'], 403);
        }
        $res = $svc->deletePhoto((int) $photoId);
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        return response()->json(['success' => true, 'message' => $res['message']]);
    }

    // =================================================================
    // helpers
    // =================================================================

    /**
     * Store uploaded photos, best-effort. Returns a sentence for the caller to
     * append to its own message. NEVER throws — an assignment that succeeded must
     * not be reported as failed because a JPEG did not save.
     */
    private function storePhotos(Request $request, VehicleService $svc, int $vehicleId,
                                 string $context, ?string $date, ?int $assignmentId,
                                 ?string $note = null): string
    {
        if (!$request->hasFile('photos')) return '';

        $saved = 0; $failed = 0;
        foreach ((array) $request->file('photos') as $file) {
            try {
                if (!$file || !$file->isValid()) { $failed++; continue; }
                $path = $this->storeOne($file, $vehicleId, $context);
                $res  = $svc->addPhoto($vehicleId, $path, $date, $context, $note,
                                       (int) auth()->id(), $assignmentId);
                $res['ok'] ? $saved++ : $failed++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('Vehicle photo upload failed', [
                    'vehicle_id' => $vehicleId, 'error' => $e->getMessage(),
                ]);
            }
        }

        if ($saved && !$failed) return $saved . ' photo' . ($saved === 1 ? '' : 's') . ' saved.';
        if ($saved && $failed)  return $saved . ' saved, ' . $failed . ' could not be uploaded.';
        if ($failed)            return 'The photo could not be uploaded — the rest was saved.';
        return '';
    }

    /**
     * Same folder convention as the attendance meter pictures.
     *
     * ⚠ Streamed via putFileAs, NOT file_get_contents. This endpoint accepts up to
     *   8 photos of up to 8 MB each; reading them all into strings would put ~64 MB
     *   through PHP's memory limit on one request. The single-photo meter path gets
     *   away with it — a batch upload would not.
     *
     * The extension follows the actual file. The meter path hardcodes .jpg, which
     * works only because browsers sniff content; a PNG saved as .jpg is a small lie
     * that costs nothing to avoid here.
     */
    private function storeOne($file, int $vehicleId, string $context): string
    {
        $now  = now();
        $safe = preg_replace('/[^a-z_]/', '', strtolower($context)) ?: 'condition';
        $ext  = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic'], true)) $ext = 'jpg';

        $dir  = 'vehicles/photos/' . $now->format('Y') . '/' . $now->format('m');
        $name = "vehicle_{$vehicleId}_{$now->format('Ymd_His')}_" . uniqid() . "_{$safe}.{$ext}";

        Storage::disk('public')->putFileAs($dir, $file, $name);
        return $dir . '/' . $name;
    }

    /**
     * Every active user, for the meter-log DRIVER picker only. The assign modal
     * keeps the rider roster() — an ASSIGNMENT makes someone a machine-holder with
     * meter demands, so it stays rider-shaped; a log entry merely NAMES who drove
     * a stint, and the owner ruled that can be anyone on the team.
     */
    private function allActiveUsers(): array
    {
        try {
            return DB::table('t_sys_user')
                ->where('is_active', '1')   // ⚠ this table uses 1/0, not Y/N
                ->orderBy('fullname')
                ->get(['id', 'fullname'])
                ->map(fn ($u) => ['user_id' => (int) $u->id, 'name' => $u->fullname])
                ->values()->all();
        } catch (\Throwable $e) {
            return $this->roster();   // worst case: the old, narrower list
        }
    }

    /**
     * The rider list the assign modal picks from — the Bikes roster (the "Delivery
     * Rider" tick on the People list), never the company-wide user list, whose
     * names render cut off and whose entries are mostly not riders at all.
     */
    private function roster(): array
    {
        try {
            // ⚠⚠ `has_vehicle` is derived from the OPEN ASSIGNMENT, never from
            //    `p.default_vehicle_id`. That column is a convenience mirror and it
            //    goes stale: one rider's currently points at a COLLEAGUE'S bike
            //    (test residue that survived a handover), so trusting it would have
            //    shown a man as equipped while he holds nothing — and this list is
            //    exactly what the fleet screen uses to find riders with no machine.
            $held = [];
            $since = [];
            try {
                foreach (DB::table(VehicleService::T_ASSIGN)->whereNull('released_on')
                            ->get(['user_id', 'vehicle_id', 'assigned_on']) as $a) {
                    $held[(int) $a->user_id]  = (int) $a->vehicle_id;
                    $since[(int) $a->user_id] = substr((string) $a->assigned_on, 0, 10);
                }
            } catch (\Throwable $e) { /* no registry → everyone reads as unequipped */ }

            // When he last gave one back — "no bike since 4 Aug" reads far better
            // than a bare "no bike", and tells the manager whether it is news.
            $lastHeld = [];
            try {
                foreach (DB::table(VehicleService::T_ASSIGN)->whereNotNull('released_on')
                            ->orderBy('released_on')
                            ->get(['user_id', 'released_on']) as $a) {
                    $lastHeld[(int) $a->user_id] = substr((string) $a->released_on, 0, 10);
                }
            } catch (\Throwable $e) { /* optional colour */ }

            // ⭐ Sep-2026 — the machine's NAME alongside its id. The web screen already
            //   has the whole fleet loaded and resolves this itself; the mobile assign
            //   sheet does not, and "now on DCR-799" is the line that stops a manager
            //   handing a bike to someone who is already on one. Additive: every
            //   existing reader ignores it.
            $vNames = [];
            try {
                $vres = new \App\Services\Riders\VehicleResolver();
                foreach (array_unique($held) as $vid) {
                    $vNames[(int) $vid] = $vres->labelFor((int) $vid);
                }
            } catch (\Throwable $e) { /* a missing label is cosmetic */ }

            // 🏠 Who set each home pin, resolved in ONE query rather than per rider —
            //    this roster is drawn on every Bikes load, on the web and on the phone.
            $homeSvc = new \App\Services\Riders\RiderHomePinService();
            $setterNames = [];
            try {
                $setterNames = DB::table('t_ops_rider_profile as p')
                    ->join('t_sys_user as su', 'su.id', '=', 'p.home_set_by')
                    ->whereNotNull('p.home_set_by')
                    ->pluck('su.fullname', 'su.id')
                    ->all();
            } catch (\Throwable $e) { /* a missing name is cosmetic */ }

            return DB::table('t_ops_rider_profile as p')
                ->join('t_sys_user as u', 'u.id', '=', 'p.user_id')
                ->where('p.active', 1)
                ->orderBy('u.fullname')
                ->get(['p.user_id', 'u.fullname', 'p.company_bike', 'p.home_latitude',
                       'p.home_longitude', 'p.home_set_at', 'p.home_set_by'])
                ->map(fn ($r) => [
                    'user_id'      => (int) $r->user_id,
                    'name'         => $r->fullname,
                    'company_bike' => (int) $r->company_bike === 1,
                    'has_vehicle'  => isset($held[(int) $r->user_id]),
                    'vehicle_id'   => $held[(int) $r->user_id] ?? null,
                    'vehicle_name' => isset($held[(int) $r->user_id])
                        ? ($vNames[$held[(int) $r->user_id]] ?? null) : null,
                    'since'        => $since[(int) $r->user_id] ?? null,
                    'free_since'   => isset($held[(int) $r->user_id]) ? null : ($lastHeld[(int) $r->user_id] ?? null),
                    'has_home_pin' => $r->home_latitude !== null,
                    // 🏠 The pin itself, so the Bikes roster can SHOW the stored location
                    // (owner: "should be able to view the saved") instead of only a tick.
                    // Same shape on the web tab and the phone — one roster, one answer.
                    'home_pin'     => $r->home_latitude !== null && $r->home_longitude !== null
                        ? [
                            'lat' => (float) $r->home_latitude,
                            'lng' => (float) $r->home_longitude,
                            'set_at' => $r->home_set_at,
                            'set_by_name' => $r->home_set_by !== null
                                ? ($setterNames[(int) $r->home_set_by] ?? null) : null,
                            'maps_url' => $homeSvc->mapsUrl((float) $r->home_latitude, (float) $r->home_longitude),
                        ]
                        : null,
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function canView(): bool
    {
        $u = auth()->user();
        if (!$u) return false;
        foreach (self::VIEW_PERMISSIONS as $p) {
            if ($u->hasPermission($p)) return true;
        }
        return false;
    }

    /**
     * May this user change the fleet? `assign_vehicles` and nothing else.
     * View-only accounts are refused regardless of the grant (ReadOnlyGuard).
     */
    /**
     * ⭐ MAY THIS USER RECORD A MACHINE'S METER? (owner ruling Aug-14: Shabib +
     *   admin users + Qasim — which is exactly the `manage_bike_service` holder set,
     *   so NO new permission key is needed.)
     *
     * ⚠ Deliberately NOT `assign_vehicles`: recording what a machine did belongs to
     *   the same family as recording its service, not to handing it to someone.
     *   Read-only accounts are refused here as everywhere.
     */
    private function canLogMeters(): bool
    {
        $u = auth()->user();
        if (!$u) return false;
        if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) return false;
        /**
         * ⚠ WEB **OR** MOBILE (Sep-3). This flag decides whether the meter and
         *   correct-a-service controls are OFFERED; the action itself is gated by
         *   `FleetFuelController::canManageService()`, which IS mobile-aware. A flag
         *   stricter than the server hides a button somebody is entitled to press — so it
         *   must mirror what the server will accept, never be a second, different gate.
         * ⚠ This can only ever REVEAL a control the server would already allow; it cannot
         *   let anything through, because the endpoint checks again.
         */
        return (bool) ($u->hasPermission('manage_bike_service')
            || (method_exists($u, 'hasMobilePermission')
                && $u->hasMobilePermission('manage_bike_service')));
    }

    /**
     * ⚠⚠ THE SAME ACTION MUST HAVE THE SAME LOCK. Correcting a rider's attendance
     *   reading is already reachable from the Attendance page, whose only server gate
     *   is `block.rider` (pure-rider accounts refused). Mirroring exactly that here
     *   means this second door changes NOBODY's access: it does not widen it, and it
     *   does not create the inconsistency of one screen refusing what another allows.
     */
    private function canCorrectAttendance(): bool
    {
        $u = auth()->user();
        if (!$u) return false;
        if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) return false;

        $roleTypes = DB::table('t_sys_user_role as ur')
            ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $u->id)->pluck('r.type')->all();
        $hasRider = in_array('rider', $roleTypes, true);
        $hasStaff = count(array_filter($roleTypes, fn ($t) => $t !== 'rider')) > 0;
        return !($hasRider && !$hasStaff);
    }

    /**
     * What the meter editor shows for one machine on one date: whatever is ALREADY
     * recorded, and where each reading came from.
     *
     * ⭐⭐ THIS IS WHAT MAKES "ONE READING, ONE HOME" ENFORCEABLE. The editor preloads
     *   from here, so a manager can never add a log reading that competes with a
     *   rider's attendance reading for the same slot — he edits the existing one
     *   instead. Two competing points for one reading would put a contradiction into
     *   the machine's chain, which is the class of bug the engine exists to prevent.
     */
    public function meterDay(Request $request, VehicleService $svc, $id)
    {
        if (!$this->canLogMeters()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to record meters'], 403);
        }
        $data = $request->validate(['date' => 'required|date']);
        $date = \Carbon\Carbon::parse($data['date'])->format('Y-m-d');

        try {
            $res = new \App\Services\Riders\VehicleResolver();

            // 1. an ATTENDANCE row whose day the resolver maps to THIS machine
            $attendance = null;
            foreach (DB::table('t_ops_attendance as a')
                        ->leftJoin('t_sys_user as u', 'u.id', '=', 'a.user_id')
                        ->where('a.attendance_date', $date)
                        ->whereNotNull('a.login_time')
                        ->get(array_merge(
                            ['a.id', 'a.user_id', 'a.meter_start', 'a.meter_end',
                             'a.meter_start_source', 'u.fullname'],
                            \App\Services\Riders\VehicleService::stampsAvailable()
                                ? ['a.meter_start_vehicle_id', 'a.meter_end_vehicle_id']
                                : []
                        )) as $a) {
                if ($res->vehicleForDay((int) $a->user_id, $date) !== (int) $id) continue;

                // ⭐⭐ NEVER OFFER A READING THAT IS STAMPED TO A DIFFERENT MACHINE (Aug-22 2026).
                //
                // ⚠⚠ THE PROD INCIDENT THIS PREVENTS. `vehicleForDay` answers with whatever the
                //    rider ENDS the day on, so on a mixed day the VAN's meter editor happily
                //    offered Rajab's OWN BIKE attendance row for editing. A manager fixing the
                //    Van's odometer typed the van's 73,9xx into that rider block — and silently
                //    overwrote his own bike's reading. That single act is what put the Van's
                //    odometer onto his attendance for 20 and 21 Aug, produced a false
                //    "overnight -100 km", and broke his per-km fuel basis.
                //
                // ⭐ Step C's stamps make the rider's answer authoritative: if the reading says it
                //   belongs to another machine, this editor has no business touching it. The
                //   machine-log block below is still shown, which is the correct place to record
                //   THIS machine's odometer.
                if (\App\Services\Riders\VehicleService::stampsAvailable()) {
                    $sV = $a->meter_start_vehicle_id ?? null;
                    $eV = $a->meter_end_vehicle_id ?? null;
                    if (($sV && (int) $sV !== (int) $id) || ($eV && (int) $eV !== (int) $id)) {
                        continue;
                    }
                }

                $attendance = [
                    'attendance_id' => (int) $a->id,
                    'user_id'       => (int) $a->user_id,
                    'name'          => $a->fullname,
                    'meter_start'   => $a->meter_start !== null ? (int) $a->meter_start : null,
                    'meter_end'     => $a->meter_end !== null ? (int) $a->meter_end : null,
                    'start_source'  => $a->meter_start_source,
                ];
                break;
            }

            // 2. a LOG row for this machine+date
            $log = null;
            if (Schema::hasTable('t_ops_vehicle_meter_log')) {
                $l = DB::table('t_ops_vehicle_meter_log')
                    ->where('vehicle_id', (int) $id)->where('log_date', $date)->first();
                if ($l) {
                    $log = [
                        'id'             => (int) $l->id,
                        'meter_start'    => $l->meter_start !== null ? (int) $l->meter_start : null,
                        'meter_end'      => $l->meter_end !== null ? (int) $l->meter_end : null,
                        'driver_user_id' => $l->driver_user_id !== null ? (int) $l->driver_user_id : null,
                        'note'           => $l->note,
                    ];
                }
            }

            // ⭐⭐ WHO WAS DRIVING THIS MACHINE THAT DAY (Aug-27 2026).
            //
            // The driver box defaulted to "— no driver (machine only) —" and every log row
            // on prod carries NULL, which is the same thing said twice: nobody fills in a
            // box that starts empty. Yet the answer was already knowable — and already
            // WRITTEN, in `VehicleResolver::riderForVehicleDay`, which had sat unused since
            // it was built. It mirrors `vehicleForDay` exactly (an explicit day override
            // first, then the assignment covering the date, open row winning), so a
            // HISTORICAL day names that day's holder rather than today's, and a day the
            // machine changed hands names the last man to take it.
            //
            // ⚠ A SUGGESTION, NEVER A DECISION. The manager can always change it — Taimur
            //   drove the van himself on 17 Aug and he holds nothing. It is only offered
            //   when the row does not already name someone.
            $suggested = null;
            try {
                $sid = $res->riderForVehicleDay((int) $id, $date);
                if ($sid) {
                    $suggested = [
                        'user_id' => (int) $sid,
                        'name'    => DB::table('t_sys_user')->where('id', $sid)->value('fullname'),
                    ];
                }
            } catch (\Throwable $sErr) { $suggested = null; }

            return response()->json([
                'success'    => true,
                'date'       => $date,
                'attendance' => $attendance,
                'log'        => $log,
                'can_edit_attendance' => $this->canCorrectAttendance(),
                // ⭐ Ruling 4 (Aug-18): the DRIVER can be ANY active user — Taimur took
                //   the van himself on 17 Aug and he is no rider. The roster() list is
                //   the Delivery-Rider cohort and would hide exactly those people.
                'drivers'    => $this->allActiveUsers(),
                'suggested_driver' => $suggested,
                'window'     => $svc->meterWindowFor((int) $id, $date),
                // 🔁 Meter replaced (Oct-2026): what is on record for this machine, whether the
                //   feature exists on this server yet, and the figure to offer as "the old
                //   meter's last reading" (the machine's reading just before this date).
                'replacements'      => $svc->meterReplacementsFor((int) $id),
                'replacement_ready' => \App\Services\Riders\MeterReplacement::available(),
            ]);
        } catch (\Throwable $e) {
            Log::error('meterDay failed', ['vehicle' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not load that day'], 500);
        }
    }

    /**
     * Save the machine's readings for one date. Each field is routed back to ITS OWN
     * home: a reading that came from a rider's attendance row updates that row (via
     * the shared MeterCorrectionService, so the Attendance page and this page cannot
     * drift); anything else lives in the machine's own log.
     */
    public function meterSave(Request $request, VehicleService $svc, $id)
    {
        if (!$this->canLogMeters()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to record meters'], 403);
        }
        $data = $request->validate([
            // ⚠ meters are recorded, never forecast — a future date would plant a
            //   phantom point in the machine's chain that no reading can ever match.
            'date'           => 'required|date|before_or_equal:today',
            // `replacement` / `replacement_remove` (Oct-2026) ride this endpoint on purpose: the
            // same screen, the same permission, and no new route to upload.
            'target'         => 'required|in:attendance,log,replacement,replacement_remove',
            'old_reading'    => 'nullable|integer|min:0',
            'new_reading'    => 'nullable|integer|min:0',
            'replacement_id' => 'nullable|integer',
            'attendance_id'  => 'nullable|integer',
            'meter_start'    => 'nullable|integer|min:0',
            'meter_end'      => 'nullable|integer|min:0',
            'driver_user_id' => 'nullable|integer|exists:t_sys_user,id',
            'note'           => 'nullable|string|max:255',
        ]);
        $date = \Carbon\Carbon::parse($data['date'])->format('Y-m-d');

        try {
            /**
             * 🔁 "THE METER ON THIS MACHINE WAS REPLACED" (5-Oct-2026). Recorded here, beside the
             *    machine's own readings, by the same people who may type them. One writer
             *    (`VehicleService::saveMeterReplacement`) — it flushes everything derived.
             */
            if ($data['target'] === 'replacement') {
                $r = $svc->saveMeterReplacement((int) $id, $date,
                    (int) ($data['old_reading'] ?? 0), (int) ($data['new_reading'] ?? 0),
                    $data['note'] ?? null, (int) auth()->id());
                return response()->json(['success' => $r['ok'], 'message' => $r['message']], $r['ok'] ? 200 : 422);
            }
            if ($data['target'] === 'replacement_remove') {
                $r = $svc->removeMeterReplacement((int) $id, (int) ($data['replacement_id'] ?? 0));
                return response()->json(['success' => $r['ok'], 'message' => $r['message']], $r['ok'] ? 200 : 422);
            }
            if ($data['target'] === 'attendance') {
                if (!$this->canCorrectAttendance()) {
                    return response()->json(['success' => false,
                        'message' => 'You cannot change a rider own reading here. Correct it on the Attendance page.'], 403);
                }
                if (empty($data['attendance_id'])) {
                    return response()->json(['success' => false, 'message' => 'Which day are we correcting?'], 422);
                }
                // ⭐⭐ NAME THE MACHINE ON THE READING (Aug-27 2026).
                //
                // This editor is titled "Meter reading — CAD-2958": the manager is looking
                // at ONE machine and typing ITS odometer, so which machine the reading
                // belongs to is not a guess here, it is the whole context of the screen.
                // `MeterCorrectionService` has carried a `$vehicleId` parameter since the
                // Van repair and NO caller ever passed it, so every manager-typed reading
                // went in unstamped — which then leaves the rider's day half-labelled, and
                // his own-bike petrol claim guessing its machine from the date.
                //
                // ⚠ The service still refuses to invent a stamp on its own (it must never
                //   use "what is he holding right now" for last Tuesday). Being TOLD is the
                //   one case it accepts, and this is the screen that can tell it.
                $r = app(\App\Services\Riders\MeterCorrectionService::class)->correct(
                    (int) $data['attendance_id'],
                    $request->has('meter_start'), $data['meter_start'] ?? null,
                    $request->has('meter_end'),   $data['meter_end'] ?? null,
                    (int) auth()->id(),
                    (int) $id
                );
                if (!$r['ok']) {
                    return response()->json(['success' => false, 'message' => $r['message']], 422);
                }
            } else {
                if (!Schema::hasTable('t_ops_vehicle_meter_log')) {
                    return response()->json(['success' => false,
                        'message' => 'Meter logging is not set up on this server yet (SQL pending).'], 422);
                }
                $start = $request->has('meter_start') ? $data['meter_start'] : null;
                $end   = $request->has('meter_end')   ? $data['meter_end']   : null;

                // ⭐ ONE WRITER, shared with the rider's own "add my bike's meter" door —
                //   see VehicleService::saveMeterLog. The delete-when-empty rule and the
                //   cache flush live there now, so both surfaces behave identically.
                $saved = $svc->saveMeterLog(
                    (int) $id, $date,
                    $start === null ? null : (int) $start,
                    $end === null ? null : (int) $end,
                    isset($data['driver_user_id']) ? (int) $data['driver_user_id'] : null,
                    $data['note'] ?? null,
                    (int) auth()->id()
                );
                if (!$saved['ok']) {
                    return response()->json(['success' => false, 'message' => $saved['message']], 422);
                }
            }

            // The month's figures are derived — drop the cache so the page redraws truth.
            (new \App\Services\Riders\MachineAttribution())->flush(substr($date, 0, 7));
            \App\Services\Riders\VehicleResolver::flush();
            // ⭐ The rider's day legs are derived from these same readings — a correction
            //   here changes what his phone may claim, so its memo must go too.
            \App\Services\Riders\RiderDayLegs::flush();

            return response()->json(['success' => true, 'message' => 'Meter saved.']);
        } catch (\Throwable $e) {
            Log::error('meterSave failed', ['vehicle' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not save the meter'], 500);
        }
    }

    private function canManage(): bool
    {
        $u = auth()->user();
        if (!$u) return false;
        if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) return false;
        return (bool) $u->hasPermission('assign_vehicles');
    }

    /**
     * ⭐ MAY HE CHANGE A SERVICE SCHEDULE? `manage_bike_service` — the SAME key that
     *   already gates recording a service and editing the maintenance types, and NOT
     *   `assign_vehicles`.
     *
     * ⚠⚠ Qasim owns maintenance and deliberately holds no `assign_vehicles` (owner
     *    ruling, 3-Sep: Shabib assigns machines). Gating the schedule editor on the
     *    assignment key would have locked the maintenance manager out of the one screen
     *    built for him. Accepts either half so the same method serves web and mobile.
     */
    private function canManageService(Request $request = null): bool
    {
        $u = auth()->user() ?: ($request ? $request->user() : null);
        if (!$u) return false;
        if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) return false;
        if (method_exists($u, 'hasMobilePermission')
            && $u->hasMobilePermission('manage_bike_service')) return true;
        return (bool) $u->hasPermission('manage_bike_service');
    }

    /**
     * ⭐⭐ THE PER-VEHICLE SCHEDULE EDITOR — read (Sep-2026).
     *
     * Every job this machine's CLASS is offered, the standard beside each, and this
     * vehicle's own value where it has one. This is the payload behind "⚙️ This
     * vehicle's schedule" on both surfaces, so web and mobile draw the same rows in
     * the same order and cannot disagree about what is set.
     */
    public function schedule(Request $request, VehicleService $svc, $id)
    {
        if (!$this->canManageService($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised to change service schedules'], 403);
        }
        try {
            $v = $svc->find((int) $id);
            if (!$v) return response()->json(['success' => false, 'message' => 'That vehicle no longer exists.'], 404);

            $class   = $svc->classOf((int) $id);
            $tracked = $svc->isTrackedId((int) $id);
            $own     = (new \App\Services\Riders\VehicleScheduleService())->forVehicle((int) $id);
            $types   = app(\App\Services\Riders\MaintenanceTypeService::class)->optionsFor($class);

            $rows = [];
            foreach ($types as $t) {
                $tid = (int) $t['id'];
                $o   = $own[$tid] ?? null;
                $rows[] = [
                    'id'   => $tid,
                    'name' => $t['name'],
                    'bucket' => $t['bucket'],
                    'basis'  => $t['basis'] ?? 'km',
                    'resets_service_clock' => !empty($t['resets_service_clock']),
                    // What every machine of this class follows unless told otherwise.
                    'standard_label' => $t['due_label'] ?? null,
                    'standard_km'    => $t['interval_km'] ?? null,
                    'standard_days'  => $t['interval_days'] ?? null,
                    'has_standard'   => !empty($t['has_schedule']),
                    // …and what THIS machine does, when it differs. Null = follow it.
                    'own_km'         => $o['km'] ?? null,
                    'own_days'       => $o['days'] ?? null,
                ];
            }

            return response()->json([
                'success'       => true,
                'vehicle'       => ['id' => (int) $id, 'name' => $v['name'] ?? null,
                                    'vtype' => $v['vtype'] ?? $class,
                                    'is_company' => $v['is_company'] ?? null],
                'vehicle_class' => $class,
                // ⚠ A personal machine has no company schedule at all, so the editor
                //   shows a sentence instead of a form rather than offering settings
                //   that would never be read.
                'tracked'       => $tracked,
                'rows'          => $tracked ? $rows : [],
                'note'          => $tracked ? null
                    : 'This is a personal vehicle. The company does not keep its service schedule.',
            ]);
        } catch (\Throwable $e) {
            \Log::error('vehicle schedule read failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not read this schedule'], 500);
        }
    }

    /**
     * ⭐⭐ THE PER-VEHICLE SCHEDULE EDITOR — write.
     *
     * The editor posts EVERY job it displayed, so a field left blank clears that
     * vehicle's exception rather than leaving the old value behind. See
     * VehicleScheduleService::saveFor for why a partial save would be wrong.
     */
    public function saveSchedule(Request $request, VehicleService $svc, $id)
    {
        if (!$this->canManageService($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised to change service schedules'], 403);
        }

        $data = $request->validate([
            'rows'               => 'required|array|max:100',
            'rows.*.id'          => 'required|integer',
            'rows.*.interval_km'   => 'nullable|integer|min:0|max:200000',
            'rows.*.interval_days' => 'nullable|integer|min:0|max:36500',
        ]);

        try {
            if (!$svc->find((int) $id)) {
                return response()->json(['success' => false, 'message' => 'That vehicle no longer exists.'], 404);
            }
            if (!$svc->isTrackedId((int) $id)) {
                return response()->json(['success' => false, 'message' =>
                    'This is a personal vehicle and the company does not keep its service schedule.'], 422);
            }

            $rows = [];
            foreach ($data['rows'] as $r) {
                $rows[(int) $r['id']] = ['km'   => $r['interval_km']   ?? null,
                                         'days' => $r['interval_days'] ?? null];
            }

            $res = (new \App\Services\Riders\VehicleScheduleService())
                ->saveFor((int) $id, $rows, (int) auth()->id());
            if (!$res['ok']) {
                return response()->json(['success' => false, 'message' => $res['message']], 422);
            }

            return response()->json(['success' => true, 'message' => $res['message'],
                                     'saved' => $res['saved'], 'cleared' => $res['cleared']]);
        } catch (\Throwable $e) {
            \Log::error('vehicle schedule save failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not save this schedule'], 500);
        }
    }

    /** Mobile twins — one method each, so the phone and the desk cannot drift. */
    public function apiSchedule(Request $request, VehicleService $svc, $id)
    {
        if (!$this->mobileAllowed($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        return $this->schedule($request, $svc, $id);
    }

    public function apiSaveSchedule(Request $request, VehicleService $svc, $id)
    {
        if (!$this->mobileAllowed($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        return $this->saveSchedule($request, $svc, $id);
    }

    /**
     * ⭐ MAY HE RECORD A CONDITION PHOTO? (owner ask, Aug-12 — photos on mobile)
     *
     * Deliberately WIDER than canManage(), and deliberately not the same thing.
     * `assign_vehicles` is described in the DB as "Assign bikes/van to riders
     * (and record their condition)" — it bundles two very different powers:
     * MOVING a machine between riders (consequential; changes whose fuel the
     * company buys, who is asked for meters) and PHOTOGRAPHING one (evidence,
     * additive, undoable by ignoring it).
     *
     * Qasim runs bike upkeep and holds `manage_bike_service` — "Set bike service
     * schedules (record a service…)" — but is explicitly denied `assign_vehicles`
     * (`is_allowed = 0`). Gating photos on assignment alone would have locked the
     * very manager the owner asked to enable. Widening `canManage()` instead
     * would have handed him assign/release/edit, which the owner withheld on
     * purpose. So the photo right is its own question, answered by either key.
     *
     * ⚠ Precedent, not invention: `RequestController::store` already falls back
     *   to `manage_bike_service` for exactly this person and reason.
     */
    private function canAddPhotos(): bool
    {
        $u = auth()->user();
        if (!$u) return false;
        if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) return false;
        return (bool) ($u->hasPermission('assign_vehicles')
                    || $u->hasPermission('manage_bike_service'));
    }
}
