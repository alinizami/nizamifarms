<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Riders\FleetFuelService;
use App\Services\Riders\RiderDayLegs;
use App\Services\Riders\VehicleResolver;
use App\Services\Riders\VehicleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

/**
 * "My vehicle" — the rider's own machine, in his own app (Aug-2026).
 *
 * Owner's ask: the rider should see what is assigned to him, and on tapping it get
 * the history of his fuel and maintenance requests, the condition photos with their
 * dates, and the same fuel average the Bikes screen shows.
 *
 * ⭐ SELF-SCOPED, SO IT NEEDS NO PERMISSION. It resolves the CALLER's own
 *    assignment and returns only that machine. A rider seeing his own bike's
 *    odometer and his own claims is not new access — he filed those claims. The
 *    fleet-wide roster and everybody else's costs stay behind `view_bike_costs`,
 *    which this endpoint never consults and never exposes.
 *
 * ⭐ THE NUMBERS COME FROM FleetFuelService, NOT FROM A PARALLEL CALCULATION.
 *    `rs_per_fuelled_km` here is literally the row the Bikes tab renders for him,
 *    read from the same cache key. If a rider and a manager ever quote different
 *    figures for the same bike, one of them is reading a copy — so there is no copy.
 */
class MyVehicleController extends Controller
{
    public function show(Request $request, VehicleResolver $res, VehicleService $veh, FleetFuelService $fleet)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 401);
        }

        try {
            // Before batch 13 this is simply "no vehicle" — the app hides the card
            // and nothing else changes. Never an error.
            if (!$veh->available()) {
                return response()->json([
                    'success' => true, 'has_vehicle' => false, 'vehicle' => null,
                    'reason' => 'not_set_up',
                ]);
            }

            $month = $this->safeMonth($request->query('month'));
            $today = Carbon::today()->format('Y-m-d');
            $uid   = (int) $user->id;

            /**
             * ⭐⭐ THE **NOW** QUESTION, SO THE **NOW** RESOLVER (5-Sep-2026).
             *
             * This screen answers "what is MY bike, right now" — the card on Attendance, the
             * odometer he is about to type, the handover request, the ticket he raises. That is
             * `currentVehicleFor` (OPEN assignment, or a manager's explicit override for today).
             *
             * ⚠⚠ IT USED TO ASK `vehicleForDay($uid, $today)`, AND THAT IS A DIFFERENT QUESTION.
             *    The day rule deliberately keeps a bike released ON a date as that rider's FOR
             *    that date (`released_on >= $date`) so the kilometres he rode this morning stay
             *    attributed to him. Asked as "is it mine now", it means a handover does not
             *    reach the rider's phone **until midnight**. Measured on prod, 5-Sep: the bike
             *    moved to Rajab at 13:42:14 and Waseem's phone still showed
             *    "MY VEHICLE DCR-799" at 14:42 — with `my_stint` naming RAJAB underneath it.
             *    Both riders were told the same machine was theirs for the rest of the day.
             *
             * ⚠ The morning's attribution is NOT lost by this — it never lived here. Fuel, km
             *   and service attribution ask `vehicleForDay` with a real date, in FleetFuelService
             *   / FuelClaimRules / ServiceRecordService, and are untouched.
             */
            $vehicleId = $res->currentVehicleFor($uid);
            if (!$vehicleId) {
                return response()->json([
                    'success' => true, 'has_vehicle' => false, 'vehicle' => null,
                    'reason' => 'none_assigned', 'month' => $month,
                ]);
            }

            $v = $veh->find($vehicleId);
            if (!$v) {
                return response()->json(['success' => true, 'has_vehicle' => false, 'vehicle' => null,
                                         'reason' => 'none_assigned', 'month' => $month]);
            }

            // Only the parts of the admin payload a rider should see. His own
            // machine's condition and service state — not who else has had it,
            // which is a management question about other people.
            unset($v['history']);
            // The "needs a home pin" nag is a MANAGER's job (it points at the
            // Riders page, which no rider can open). It stays on the web fleet
            // card; showing a rider an instruction he cannot act on is noise.
            unset($v['needs_home_pin']);
            // Same reasoning for the saved pin itself (Sep-2026): it is manager colour for
            // the Bikes card, and only a manager can change it. A rider has no use for it
            // here and no way to act on it.
            unset($v['keeper_home_pin']);

            // ⭐ BRIEF MODE — what the one-line card on the Attendance screen needs,
            //   and nothing else.
            //
            //   `fetchAttendanceData()` fires from THIRTEEN places (check-in,
            //   checkout, every meter upload, pull-to-refresh, screen focus…). The
            //   full payload runs FleetFuelService::monthSummary(), which computes
            //   the whole company's month — an expensive call to render a plate and
            //   an odometer. Brief mode skips the month summary and the claim list
            //   entirely, so the card costs a couple of cheap reads no matter how
            //   often the attendance screen refreshes. The full screen asks for
            //   everything.
            /**
             * 🔧 HIS NEXT WORKSHOP ERRAND (owner ask, Sep-2026) — "shown in the attendance
             * with the bike, so he knows clearly".
             *
             * ⚠ Rides on the BRIEF payload too: the Attendance screen fetches `brief=1` for
             *   its MY VEHICLE line, and that line is precisely where the owner asked for
             *   the date to appear. Leaving it out of brief would have meant building the
             *   feature and then not showing it in the one place he named.
             * ⚠ Self-scoped like the rest of this controller — it resolves the caller.
             * ⚠ Additive and guarded: null before the SQL runs, and an older app ignores it.
             */
            $nextWorkshop = null;
            try {
                $nextWorkshop = app(\App\Services\Riders\WorkshopVisitService::class)
                    ->nextForUser($uid);
            } catch (\Throwable $e) {
                $nextWorkshop = null;
            }

            if ($request->boolean('brief')) {
                unset($v['photos']);
                return response()->json([
                    'success'       => true,
                    'has_vehicle'   => true,
                    'brief'         => true,
                    'vehicle'       => $v,
                    'next_workshop' => $nextWorkshop,
                ]);
            }

            return response()->json([
                'success'       => true,
                'has_vehicle'   => true,
                'month'         => $month,
                'next_workshop' => $nextWorkshop,
                'vehicle'     => $v,
                'assignment'  => $this->assignmentFor($vehicleId, $uid),
                // HIS running cost per km — the manager's Bikes figure, unchanged.
                'summary'     => $this->summaryFor($uid, $month, $fleet),
                // ⭐ THE MACHINE'S claims, not the man's (Phase C4). See below.
                'claims'      => $claims = $this->claimsFor($vehicleId, $uid, $month, $veh),
                // ⭐ …and the month totals TOTALLED FROM THOSE SAME ROWS, so the
                //    headline figures can never disagree with the list under them.
                //    (This is a sum of what is displayed, not a second engine.)
                'vehicle_month' => $this->totalsOf($claims),
                // ⭐ THE MACHINE'S running cost: selected month vs the pooled
                //    previous three (owner ruling Aug-4 — lifetime is impossible,
                //    and the old rider-month figure dashed out on day one of a
                //    handover even though the bike had a month of real data).
                'averages'    => $veh->fuelAverages($vehicleId, $month),
                // ⭐ HIS stint on this machine — km + Rs/km since HE took it,
                //    the number that, against last-3, says whether the rider or
                //    the machine is the variable (owner ruling Aug-4).
                // ⚠⚠ `keeperStintStats` answers "the CURRENT keeper's stint on this machine",
                //    which is only "yours" while the caller IS that keeper. On 5-Sep it handed
                //    Waseem a block headed "You on this bike" containing Rajab's name, start
                //    date and kilometres. The resolver fix above already stops a non-keeper
                //    reaching this line; this is the second lock, so the label can never lie
                //    again even if some other path lands here.
                'my_stint'    => $this->myStintOnly($veh->keeperStintStats($vehicleId), $uid),
                'months'      => $this->recentMonths(),
            ]);
        } catch (\Throwable $e) {
            Log::error('MyVehicle failed', ['user' => $request->user()->id ?? null, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not load your vehicle'], 500);
        }
    }

    /**
     * ⭐⭐ "ADD MY OWN BIKE'S METER" — the rider's own door into the machine meter log
     *    (owner ruling q2, Aug-27 2026).
     *
     * WHY A RIDER NEEDS ONE AT ALL. He has exactly ONE meter pair per day, on his
     * attendance row, and on a day he is holding the company van those two slots are the
     * VAN's. His own bike's kilometres — the ones he is actually owed money for — then
     * have nowhere to go, and until now the only way to record them was to ask a manager
     * to type them on the Vehicles page. That is a daily dependency on somebody else for
     * a man's own wages.
     *
     * ⭐⭐ ONE WRITER, ON THE OWNER'S EXPLICIT INSTRUCTION: this goes through
     *    `VehicleService::saveMeterLog`, the SAME method the Vehicles page now calls. The
     *    two surfaces cannot drift, because there is only one of them.
     *
     * ⚠ THE GATES ARE NARROW AND EACH ONE IS LOAD-BEARING:
     *    • HIS OWN machine only (`ownMachineIdsFor`) — never a company vehicle, whose
     *      fuel the firm buys and whose odometer is not his to write;
     *    • today or inside the petrol window, never the future — a forward-dated reading
     *      plants a point in the chain no real reading can ever match;
     *    • plausible for that machine (Rule P), so a typo cannot become its whole spine;
     *    • he may only touch a row HE entered. A manager's row is read-only to him —
     *      otherwise the man being checked could quietly rewrite the check.
     */
    public function saveMeter(Request $request, VehicleService $veh)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 401);
        }

        $data = $request->validate([
            'vehicle_id'  => 'required|integer',
            'date'        => 'required|date|before_or_equal:today',
            'meter_start' => 'nullable|integer|min:0|max:9999999',
            'meter_end'   => 'nullable|integer|min:0|max:9999999',
            'note'        => 'nullable|string|max:255',
        ]);

        $uid  = (int) $user->id;
        $vid  = (int) $data['vehicle_id'];
        $date = Carbon::parse($data['date'])->format('Y-m-d');

        try {
            if (!$veh->available() || !Schema::hasTable(VehicleService::T_METER_LOG)) {
                return response()->json(['success' => false,
                    'message' => 'Meter logging is not available yet.'], 422);
            }

            // 1. his own machine, and nothing else
            $own = (new RiderDayLegs())->ownMachineIdsFor($uid);
            if (!in_array($vid, array_map('intval', $own), true)) {
                return response()->json(['success' => false,
                    'message' => 'You can only record the meter for your own vehicle.'], 403);
            }

            // 2. inside the window a claim could still be made for
            // ⭐ ONE definition — the RIDER's window: this is his own door, and there is
            //   no point letting him record a reading he could not then claim against.
            $window = (new \App\Services\Riders\FuelClaimRules())->petrolWindowDays(false);
            if ($date < Carbon::today()->subDays($window)->format('Y-m-d')) {
                return response()->json(['success' => false,
                    'message' => "Meter readings can only be added for the last {$window} days. "
                        . 'Ask your manager to record an older day.'], 422);
            }

            $start = $request->filled('meter_start') ? (int) $data['meter_start'] : null;
            $end   = $request->filled('meter_end')   ? (int) $data['meter_end']   : null;
            if ($start === null && $end === null) {
                return response()->json(['success' => false,
                    'message' => 'Enter at least one reading.'], 422);
            }
            // (compared on the machine's continuous scale, so the day its meter was replaced —
            //  start off the old meter, close off the new — is not "lower"; identical otherwise)
            if ($start !== null && $end !== null
                && \App\Services\Riders\MeterReplacement::toContinuous((int) $vid, $end, $date)
                   < \App\Services\Riders\MeterReplacement::toContinuous((int) $vid, $start, $date)) {
                return response()->json(['success' => false,
                    'message' => 'The closing reading cannot be lower than the starting one.'], 422);
            }

            // 3. it has to be believable for THAT machine
            foreach (array_filter([$start, $end], fn ($v) => $v !== null) as $val) {
                if (!$veh->readingPlausibleFor($vid, (int) $val, $date ?? null, false)) {
                    return response()->json(['success' => false,
                        'message' => 'That reading does not look like this vehicle\'s odometer. '
                            . 'Please check the number.'], 422);
                }
            }

            // 4. never overwrite somebody else's entry
            $existing = DB::table(VehicleService::T_METER_LOG)
                ->where('vehicle_id', $vid)->where('log_date', $date)->first();
            if ($existing && (int) ($existing->entered_by ?? 0) !== $uid) {
                return response()->json(['success' => false,
                    'message' => 'Your manager already recorded this vehicle for that day. '
                        . 'Ask him to change it if it is wrong.'], 422);
            }

            $saved = $veh->saveMeterLog($vid, $date, $start, $end, $uid, $data['note'] ?? null, $uid);
            if (!$saved['ok']) {
                return response()->json(['success' => false, 'message' => $saved['message']], 422);
            }

            Log::info('Rider recorded his own vehicle meter', [
                'user_id' => $uid, 'vehicle_id' => $vid, 'date' => $date,
                'start' => $start, 'end' => $end, 'action' => $saved['action'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Meter saved.',
                'action'  => $saved['action'],
                'date'    => $date,
            ]);
        } catch (\Throwable $e) {
            Log::error('MyVehicle saveMeter failed', ['user' => $uid, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not save that reading'], 500);
        }
    }

    /** Since when he has had it, and any note the manager left. */
    /**
     * The stint block, but only when it is genuinely the caller's.
     * ⚠ Never "fix" this by relabelling the block — another rider's name, start date and
     *   kilometres are not this rider's business at all. Null, and the app hides the row.
     */
    private function myStintOnly(?array $stint, int $userId): ?array
    {
        if (!$stint) return null;
        return (int) ($stint['user_id'] ?? 0) === $userId ? $stint : null;
    }

    private function assignmentFor(int $vehicleId, int $userId): ?array
    {
        try {
            $a = DB::table(VehicleService::T_ASSIGN)
                ->where('vehicle_id', $vehicleId)->where('user_id', $userId)
                ->whereNull('released_on')->orderByDesc('id')->first();
            if (!$a) return null;
            return [
                'since' => substr((string) $a->assigned_on, 0, 10),
                'note'  => $a->note,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * His month, as the Bikes tab computes it.
     *
     * ⚠ Reads the SAME cache key the web/mobile Bikes screens use, so a rider and
     *   his manager cannot be shown different figures for the same month. The
     *   monthly summary covers every rider; only this rider's row is returned.
     */
    private function summaryFor(int $userId, string $month, FleetFuelService $fleet): array
    {
        $empty = [
            'fuel_rs' => 0, 'maint_rs' => 0, 'rs_per_fuelled_km' => null,
            'fuelled_km' => 0, 'work_km' => 0, 'offduty_km' => 0,
        ];
        try {
            $data = Cache::remember("fleet_fuel_month_{$month}", 120,
                fn () => $fleet->monthSummary($month));

            $row = collect($data['riders'] ?? [])->firstWhere('user_id', $userId);
            if (!$row) return $empty;

            return [
                'fuel_rs'           => $row['fuel_rs'] ?? 0,
                'maint_rs'          => $row['maint_rs'] ?? 0,
                // The machine's running cost per kilometre ridden — the same field,
                // with the same label, that the Bikes table shows.
                'rs_per_fuelled_km' => $row['rs_per_fuelled_km'] ?? null,
                'fuelled_km'        => $row['fuelled_km'] ?? 0,
                'work_km'           => $row['work_km'] ?? 0,
                'offduty_km'        => $row['offduty_km'] ?? 0,
            ];
        } catch (\Throwable $e) {
            Log::warning('MyVehicle summary failed', ['error' => $e->getMessage()]);
            return $empty;
        }
    }

    /**
     * ⭐ THIS MACHINE'S fuel and maintenance for the month — READ ONLY.
     *
     * ⚠ IT USED TO BE THE MAN'S, AND THAT WAS WRONG (owner, Aug-4): the screen is
     *   titled with a plate, so a rider newly given DCR-799 was shown his OWN old
     *   personal-bike fills underneath it. The list now belongs to the bike —
     *   including a predecessor's claims, each labelled with who filed it, which
     *   is exactly how a keeper learns what has already been done to the machine
     *   he has just been handed.
     *
     * One query, in VehicleService, shared with the web profile — a rider and his
     * manager must never be looking at two different reconstructions of the same
     * bike's history.
     *
     * `is_mine` lets the app tint his own rows without a second call.
     */
    private function claimsFor(int $vehicleId, int $userId, string $month, VehicleService $veh): array
    {
        try {
            $from = Carbon::parse($month . '-01')->startOfMonth()->format('Y-m-d');
            $to   = Carbon::parse($month . '-01')->endOfMonth()->format('Y-m-d');

            return array_map(function (array $c) use ($userId) {
                $c['is_mine'] = ((int) ($c['by_user_id'] ?? 0)) === $userId;
                return $c;
            }, $veh->claimsForVehicle($vehicleId, $from, $to));
        } catch (\Throwable $e) {
            Log::warning('MyVehicle claims failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /** Month totals for the MACHINE, summed from the rows the screen displays. */
    private function totalsOf(array $claims): array
    {
        $fuel = 0.0; $maint = 0.0; $pending = 0;
        foreach ($claims as $c) {
            if (($c['category'] ?? '') === 'Petrol') $fuel += (float) ($c['amount'] ?? 0);
            else $maint += (float) ($c['amount'] ?? 0);
            if (!empty($c['is_pending'])) $pending++;
        }
        return [
            'fuel_rs'  => round($fuel, 2),
            'maint_rs' => round($maint, 2),
            'count'    => count($claims),
            'pending'  => $pending,
        ];
    }

    /** The last 6 months, for the month switcher. */
    private function recentMonths(): array
    {
        $out = [];
        $c = Carbon::today()->startOfMonth();
        for ($i = 0; $i < 6; $i++) {
            $out[] = ['value' => $c->format('Y-m'), 'label' => $c->format('M Y')];
            $c->subMonthNoOverflow();
        }
        return $out;
    }

    private function safeMonth($raw): string
    {
        try {
            $c = $raw ? Carbon::parse($raw . '-01') : Carbon::today()->startOfMonth();
        } catch (\Throwable $e) {
            $c = Carbon::today()->startOfMonth();
        }
        if ($c->gt(Carbon::today()->startOfMonth())) $c = Carbon::today()->startOfMonth();
        return $c->format('Y-m');
    }
}
