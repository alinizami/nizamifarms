<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\ShiftResolutionService;
use App\Models\Ops\ShiftTemplateModel;
use App\Models\Ops\PublicHolidayModel;
use App\Models\Ops\UserShiftAssignmentModel;
use App\Services\Ops\WeekdayPattern;

/**
 * Shift Planner — the single place to see and change who works which shift.
 * Read-only aggregation; all writes go through ShiftController (assign / cancel-change),
 * which owns the layered primary + temporary-override engine.
 */
class ShiftPlannerController extends Controller
{
    public function index()
    {
        return view('pages.shifts.planner');
    }

    /**
     * 👥 The USERS LIST behind the planner's "Users list" button — every active account
     * with whether it is on the one roster that Shift Planner and Attendance share.
     *
     * 🔒 Gated: only Shabib and Taimur (owner ruling 9-Sep). Refused rather than returned
     *    empty, so a hand-rolled request cannot enumerate accounts.
     * ⚠ Read-only. The toggle goes to POST /attendance/update-visibility — the ONE writer.
     */
    public function usersList(Request $request)
    {
        $me = $request->user() ?: auth()->user();
        if (!app(\App\Services\Ops\ShiftAuthorityService::class)->canManageRoster($me)) {
            return response()->json([
                'success' => false,
                'message' => 'Only Shabib and Taimur can open the users list.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'users' => app(\App\Services\Ops\UserRosterService::class)->list(),
        ]);
    }

    /**
     * Save/update a rider's WhatsApp contact number (unified location =
     * t_ops_rider_profile.phone) from the planner's "add number" prompt. Upserts
     * by user_id; never touches other profile fields.
     */
    public function updatePhone(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'phone' => 'required|string|max:50',
        ]);
        // A real number, not a note: at least 10 digits somewhere in the string.
        if (strlen(preg_replace('/\D/', '', $data['phone'])) < 10) {
            return response()->json(['success' => false, 'message' => 'Please enter a valid number (at least 10 digits).'], 422);
        }
        $uid = (int) $data['user_id'];
        if (DB::table('t_ops_rider_profile')->where('user_id', $uid)->exists()) {
            DB::table('t_ops_rider_profile')->where('user_id', $uid)
                ->update(['phone' => trim($data['phone']), 'updated_at' => now()]);
        } else {
            // New row (e.g. All-staff view, non-rider): active=0 EXPLICITLY — the column
            // defaults to 1, and saving a phone must never mint a delivery rider.
            DB::table('t_ops_rider_profile')->insert([
                'user_id' => $uid, 'phone' => trim($data['phone']), 'active' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        // Fire any WhatsApp that was skipped "no phone" for a pending change.
        try {
            app(ShiftController::class)->dispatchShiftWhatsApp($uid);
        } catch (\Throwable $e) {
            \Log::warning('planner update-phone WA redispatch failed (non-fatal)', ['error' => $e->getMessage()]);
        }
        return response()->json(['success' => true]);
    }

    /**
     * Week grid data: riders (or all staff) × 7 days, each day resolved to the shift
     * actually in effect, plus each rider's active/upcoming changes (for past/now/next
     * and the Cancel button), the assignable templates, and the week's holidays.
     */
    public function weekData(Request $request)
    {
        $svc = new ShiftResolutionService();

        // 🔒 Who is looking, and what the shift rules let them do to each row below.
        $me = $request->user() ?: auth()->user();
        $authority = app(\App\Services\Ops\ShiftAuthorityService::class);
        $openRequests = app(\App\Services\Ops\ShiftChangeRequestService::class)->openByUser();

        // Week starts on Monday.
        $startInput = $request->input('start');
        $monday = $startInput
            ? \Carbon\Carbon::parse($startInput)->startOfWeek(\Carbon\Carbon::MONDAY)
            : \Carbon\Carbon::now()->startOfWeek(\Carbon\Carbon::MONDAY);

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $monday->copy()->addDays($i);
            $days[] = [
                'date' => $d->format('Y-m-d'),
                'label' => $d->format('D'),
                'day' => $d->format('j'),
            ];
        }
        $dateList = array_column($days, 'date');
        $today = now()->format('Y-m-d');

        /**
         * 👥 ONE COMMON LIST (owner ruling Sep-2026) — the roster is the ATTENDANCE list,
         * shared with the mobile planner via User::shiftPlannerRoster(). The Delivery
         * Rider tick no longer decides who can be scheduled.
         *
         * ⚠ 9-Sep follow-up: the Riders/Everyone chips are GONE — *"now no need to
         *   differentiate between riders only and everyone"*. The page shows the one list,
         *   and the "Users list" button edits who is on it. `filter` is still accepted and
         *   ignored so an old bookmark or a cached JS bundle cannot 500 or show a subset.
         */
        $search = trim((string) $request->input('search', ''));

        $roster = \App\Models\User::shiftPlannerRoster();
        $offRoster = array_flip($roster['off_roster']);

        $users = DB::table('t_sys_user as u')
            ->leftJoin('t_sys_user_role as ur', 'ur.user_id', '=', 'u.id')
            ->leftJoin('t_sys_role as r', 'r.id', '=', 'ur.role_id')
            ->leftJoin('t_ops_rider_profile as p', 'p.user_id', '=', 'u.id')
            ->where('u.is_active', 1)
            ->whereIn('u.id', $roster['ids'] ?: [0])
            ->when($search !== '', fn($q) => $q->where('u.fullname', 'like', '%' . $search . '%'))
            ->select(
                'u.id as user_id',
                'u.fullname',
                DB::raw('MAX(r.urole_name) as role_name'),
                // "Delivery rider" = active rider profile — same list as the assign screens.
                DB::raw('MAX(CASE WHEN p.active = 1 THEN 1 ELSE 0 END) as is_rider'),
                // Has a WhatsApp number on file? (drives the "add number" prompt.)
                DB::raw("MAX(CASE WHEN p.phone IS NOT NULL AND TRIM(p.phone) <> '' THEN 1 ELSE 0 END) as has_phone")
            )
            ->groupBy('u.id', 'u.fullname')
            ->orderBy('u.fullname')
            ->get();

        // ⚠ Kept ONLY by the safety net (live shift row / open request while hidden from
        //   attendance) — the grid tags the row so the planner knows why it is here.
        $users = $users->map(function ($u) use ($offRoster) {
            $u->off_roster = isset($offRoster[(int) $u->user_id]) ? 1 : 0;
            return $u;
        });


        // Holidays in the week (date => name).
        $holidayNames = DB::table('t_ops_public_holidays')
            ->where('is_active', 1)
            ->whereBetween('holiday_date', [$dateList[0], $dateList[6]])
            ->get()
            ->mapWithKeys(fn($h) => [(string) \Carbon\Carbon::parse($h->holiday_date)->format('Y-m-d') => $h->holiday_name])
            ->all();

        // "Not needed" day tags for the visible users across the week (batch — never per cell).
        $dayTags = [];
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('t_ops_day_tag')) {
                $uids = $users->pluck('user_id')->all();
                if (!empty($uids)) {
                    foreach (
                        DB::table('t_ops_day_tag')
                            ->whereIn('user_id', $uids)
                            ->whereBetween('tag_date', [$dateList[0], $dateList[6]])
                            ->get(['user_id', 'tag_date'])
                        as $t
                    ) {
                        $dayTags[$t->user_id . '|' . \Carbon\Carbon::parse($t->tag_date)->format('Y-m-d')] = true;
                    }
                }
            }
        } catch (\Throwable $e) { /* table not deployed → no tags */ }

        /**
         * 🔧 WORKSHOP VISITS for the visible week (owner ask, Sep-2026): "whoever is
         * planning the shift should know that on this date the rider has to go".
         *
         * ⚠⚠ A visit is NOT a day tag. It does not change pay, absence or the shift —
         *    it is an errand on a normal paid working day (owner ruling). So it is
         *    painted ON TOP of whatever the cell already says, and the cell keeps its
         *    shift times. Batched for the whole week in ONE query, like the tags above.
         */
        $workshop = [];
        $canApproveWorkshop = false;
        try {
            $uids = $users->pluck('user_id')->all();
            if (!empty($uids)) {
                $wvSvc = app(\App\Services\Riders\WorkshopVisitService::class);
                /**
                 * ⏳ 6-Sep ruling: a workshop day that has NOT been approved yet is drawn here
                 *    too — as a REQUEST, never as a plan. This grid is the only screen where
                 *    the question ("should this rider be at a workshop that day?") and the
                 *    answer (his shift for that day) sit side by side, so it is where the
                 *    planner should be able to say yes.
                 * ⚠ Only for a planner. Anyone else looking at this page sees the same grid
                 *   it has always shown — a proposal is not yet a fact about the day.
                 */
                $canApproveWorkshop = $wvSvc->canApprove(auth()->user(), false);
                $workshop = $wvSvc->mapForRange($uids, $dateList[0], $dateList[6], $canApproveWorkshop);
            }
        } catch (\Throwable $e) { /* table not deployed → no visits */ }

        $riders = [];
        foreach ($users as $u) {
            $uid = (int) $u->user_id;
            $rows = UserShiftAssignmentModel::with('shiftTemplate')->where('user_id', $uid)->get();
            // ⚠ A closed REGULAR row has an end date too — it is not a temporary change.
            $closedRegular = $svc->closedRegularIds($uid, $rows);

            $cells = [];
            foreach ($dateList as $d) {
                $shift = $svc->getUserShift($uid, $d);
                $dow = (int) date('N', strtotime($d));
                $isHoliday = isset($holidayNames[$d]);
                $isOff = !in_array($dow, $shift['working_days']);
                // A TEMPORARY row covering this date means it's an override.
                $isOverride = $rows->first(function ($row) use ($d, $svc, $closedRegular) {
                    if (!$svc->isTemporaryRow($row, $closedRegular)) {
                        return false; // primary (open or closed), not an override
                    }
                    $f = $row->effective_from ? $row->effective_from->format('Y-m-d') : null;
                    $t = $row->effective_to->format('Y-m-d');
                    return ($f === null || $f <= $d) && ($t >= $d);
                }) !== null;

                // "Not needed" only matters on a would-be WORKING day (off/holiday already win in
                // dayKind) — matches the salary/absent treatment.
                $notNeeded = isset($dayTags[$uid . '|' . $d]) && !$isOff && !$isHoliday;

                $cells[] = [
                    'date' => $d,
                    'shift_name' => $shift['shift_name'],
                    'start' => $shift['shift_start'],
                    'end' => $shift['shift_end'],
                    // Resolved location for THIS day (assignment → default → primary).
                    // The grid pins a day only when it differs from the rider's usual.
                    'location_id' => $shift['location_id'] ?? null,
                    'location_name' => $shift['location_name'] ?? null,
                    'is_off' => $isOff,
                    'is_holiday' => $isHoliday,
                    'is_override' => $isOverride,
                    // 🔁 One of his WEEKLY days (a different shift/place, or off, on this
                    //   weekday). Drawn as an ordinary chip with 🔁 — never the red override
                    //   chip and never the 📍 "different place" pin, which would otherwise
                    //   fire every single week and stop meaning anything (owner ruling R6).
                    'pattern_day' => (bool) ($shift['pattern_day'] ?? false),
                    'not_needed' => $notNeeded,
                    // 🔧 The workshop errand for this rider on this day, or null.
                    // Additive: an older planner blade simply ignores the key.
                    'workshop' => $workshop[$uid . '|' . $d] ?? null,
                ];
            }

            $primary = $svc->getUserShift($uid, $today);

            // Active / upcoming changes → past/now/next + Cancel.
            $changes = [];
            foreach ($rows as $row) {
                $f = $row->effective_from ? $row->effective_from->format('Y-m-d') : null;
                $t = $row->effective_to ? $row->effective_to->format('Y-m-d') : null;
                if ($t !== null && $t >= $today && $svc->isTemporaryRow($row, $closedRegular)) {
                    $changes[] = [
                        'assignment_id' => $row->id,
                        'kind' => 'temporary',
                        'shift_name' => optional($row->shiftTemplate)->shift_name,
                        'from' => $f,
                        'to' => $t,
                        'started' => ($f === null || $f <= $today),
                        'acknowledged' => $row->acknowledged_at !== null,
                        'weekly_summary' => WeekdayPattern::summary(WeekdayPattern::parse($row->day_pattern ?? null)),
                    ];
                } elseif ($t === null && $f !== null && $f > $today) {
                    $changes[] = [
                        'assignment_id' => $row->id,
                        'kind' => 'upcoming_primary',
                        'shift_name' => optional($row->shiftTemplate)->shift_name,
                        'from' => $f,
                        'to' => null,
                        'started' => false,
                        'acknowledged' => $row->acknowledged_at !== null,
                        'weekly_summary' => WeekdayPattern::summary(WeekdayPattern::parse($row->day_pattern ?? null)),
                    ];
                }
            }

            $def = $svc->userDefaultLocation($uid); // rider's default office (pre-selected on assign)

            // The rider's USUAL location = their current open-ended primary row's
            // explicit location, else their default. Day cells whose resolved
            // location differs from this get a 📍 pin in the grid — so a one-day
            // LaCarne cover stands out while a whole week at the usual place shows
            // no pins at all.
            $primaryRow = $rows->filter(function ($r) use ($today) {
                    $f = $r->effective_from ? $r->effective_from->format('Y-m-d') : null;
                    return is_null($r->effective_to) && ($f === null || $f <= $today);
                })
                // Latest start, then newest id — the resolver's own tie-break, so the row
                // whose weekly days are shown is the row that is actually applied.
                ->sortByDesc(fn ($r) => ($r->effective_from ? $r->effective_from->format('Y-m-d') : '0000-00-00')
                                       . '|' . str_pad((string) $r->id, 10, '0', STR_PAD_LEFT))
                ->first();
            $usualLocationId = ($primaryRow && !empty($primaryRow->location_id))
                ? (int) $primaryRow->location_id
                : $def['location_id'];

            /**
             * 🔁 His standing WEEKLY DAYS (the regular row's), so the popup can open with them
             * already filled in and the row can say them. ⚠ `regular` is the row's OWN shift and
             * place — the summary chip must not show today's resolved shift, which on a
             * LaCarne Wednesday IS the weekly day.
             */
            $weeklyMap = $primaryRow ? WeekdayPattern::parse($primaryRow->day_pattern ?? null) : null;
            $regular = null;
            if ($primaryRow && $primaryRow->shiftTemplate) {
                $rt = $primaryRow->shiftTemplate;
                $rl = $svc->locationFor($uid, $primaryRow->location_id ? (int) $primaryRow->location_id : null);
                $regular = [
                    'template_id' => (int) $rt->id,
                    'shift_name' => $rt->shift_name,
                    'start' => substr((string) $rt->shift_start, 0, 5),
                    'end' => $rt->shift_end ? substr((string) $rt->shift_end, 0, 5) : null,
                    'location_id' => $primaryRow->location_id ? (int) $primaryRow->location_id : null,
                    'location_name' => $rl['location_name'],
                ];
            }

            /**
             * 🔒 SHIFT AUTHORITY (Sep-2026). Three things the grid needs per person:
             *   can            — may the person looking at this grid change this row at all;
             *   lock_reason    — what the padlock's tooltip says. ⭐ Owner ruling 6-Sep: a
             *                    locked row is SHOWN WITH A LOCK, never hidden — hiding
             *                    people makes a planner think the grid is broken;
             *   needs_approval — the Save button becomes "Send for approval" for this row.
             * Plus the allowed-shift list, so the picker offers only what the gate accepts.
             * ⚠ Advisory only. Every write re-asks ShiftAuthorityService.
             */
            $rowState = $authority->rowStateFor($me, $uid);

            $riders[] = [
                'user_id' => $uid,
                'name' => $u->fullname,
                'role' => $u->role_name,
                'is_rider' => (int) ($u->is_rider ?? 0) === 1,
                // ⚠ Only the safety net is keeping this row here — see User::shiftPlannerRoster().
                'off_roster' => (int) ($u->off_roster ?? 0) === 1,
                'has_phone' => (int) ($u->has_phone ?? 0) === 1,
                'can_change' => $rowState['can'],
                'lock_reason' => $rowState['reason'],
                'needs_approval' => $rowState['needs_approval'],
                'allowed_template_ids' => $authority->allowedTemplateIdsFor($me, $uid),
                'pending_requests' => $openRequests[$uid] ?? [],
                'default_location_id' => $def['location_id'],
                'default_location_name' => $def['location_name'],
                'usual_location_id' => $usualLocationId,
                'primary' => [
                    'shift_name' => $primary['shift_name'],
                    'start' => $primary['shift_start'],
                    'end' => $primary['shift_end'],
                    'location_name' => $primary['location_name'] ?? null,
                ],
                // 🔁 Additive: null when he has no weekly days.
                'regular' => $regular,
                'weekly' => $weeklyMap ? [
                    'days' => WeekdayPattern::toWire($weeklyMap),
                    'summary' => WeekdayPattern::summary($weeklyMap),
                ] : null,
                'cells' => $cells,
                'changes' => $changes,
            ];
        }

        // ⏳ A shift type still waiting for approval is never assignable, so it is not
        //    offered here at all. Schema-guarded — before the SQL every type is approved.
        $templates = ShiftTemplateModel::where('active', 1)
            ->when($authority->templateApprovalAvailable(), fn ($q) => $q->where('approval_status', 'approved'))
            ->orderBy('shift_name')
            ->get()
            ->map(fn($t) => [
                'id' => $t->id,
                'name' => $t->shift_name,
                'start' => substr($t->shift_start, 0, 5),
                'end' => $t->shift_end ? substr($t->shift_end, 0, 5) : null,
                'off_days' => $t->getOffDaysString(),
            ])
            ->values();

        // Active office locations (for the assign dialog's location bubbles).
        // ⚠ VAN MEET-UP POINTS ARE NOT OFFICES. They share this table, so without
        //   this filter a roadside rendezvous shows up as an assignable work
        //   location — and an assigned location is honoured by the attendance
        //   check-in rules, which is exactly the hole the LocationService and
        //   CompanyLocationsController exclusions already close. Schema-guarded:
        //   before batch 14 the column does not exist and this is a no-op.
        $locations = DB::table('t_ops_company_locations')->where('is_active', 1)
            ->when(\App\Services\LocationService::hasHandoverPointColumn(), fn ($q) => $q->where(function ($w) {
                $w->where('is_handover_point', 0)->orWhereNull('is_handover_point');
            }))
            // ⚠⚠ AND NEITHER IS A WORKSHOP (Sep-3). Same hole, same shape: it is somewhere a
            //   rider is sent for one morning, so it must never be offered as his standing
            //   place of work. The Phase-4 SQL said this filter existed; it did not.
            //   A workshop reaches his day only through the one-day visit override.
            ->when(\App\Services\LocationService::hasWorkshopColumn(), fn ($q) => $q->where(function ($w) {
                $w->where('is_workshop', 0)->orWhereNull('is_workshop');
            }))
            ->orderByDesc('is_primary')->orderBy('location_name')
            ->get(['id', 'location_name', 'is_primary'])
            ->map(fn($l) => ['id' => (int) $l->id, 'name' => $l->location_name, 'is_primary' => (int) $l->is_primary === 1])
            ->values();

        return response()->json([
            'success' => true,
            'week_start' => $dateList[0],
            'week_end' => $dateList[6],
            'prev_week' => $monday->copy()->subDays(7)->format('Y-m-d'),
            'next_week' => $monday->copy()->addDays(7)->format('Y-m-d'),
            'this_week' => \Carbon\Carbon::now()->startOfWeek(\Carbon\Carbon::MONDAY)->format('Y-m-d'),
            'days' => $days,
            'today' => $today,
            'holiday_names' => $holidayNames,
            'templates' => $templates,
            'riders' => array_values($riders),
            'locations' => $locations,
            /**
             * ⏳ 6-Sep: does the person looking at this grid get to say yes or no to a
             *   proposed workshop day? The cell renderer uses this to decide whether a
             *   `proposed` chip carries Approve / Adjust / Decline or is simply drawn.
             * ⚠ Advisory only — every action re-checks `manage_shifts` server-side.
             */
            'can_approve_workshop' => $canApproveWorkshop,
            /**
             * ⚙ Shift rules — only the holder of `manage_shift_rules` (Taimur) gets the
             *   button. Everyone else does not see that the page exists.
             */
            'can_manage_rules' => $authority->canManageRules($me),
            /**
             * 👥 Users list — only Shabib and Taimur (owner ruling 9-Sep). Everyone else
             *   does not see the button. ⚠ Advisory only: the write itself is refused by
             *   AttendanceController::updateUserVisibility, the one door onto the list.
             */
            'can_manage_roster' => $authority->canManageRoster($me),
            // 🔁 Weekly days need one column (shift_weekly_days_oct2026.sql). Until it is run
            //   the popup does not offer them — and the server refuses them anyway.
            'weekly_available' => WeekdayPattern::available() && WeekdayPattern::requestColumnAvailable(),
            'template_create' => $authority->templateCreateState($me),
            // The workshops a planner may move a proposal to while approving it.
            'workshops' => app(\App\Services\Riders\WorkshopVisitService::class)->workshopLocations(),
        ]);
    }
}
