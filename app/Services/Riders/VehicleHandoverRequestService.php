<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 🔁 RIDER-INITIATED VEHICLE HANDOVER REQUESTS (Sep-2026).
 *
 * The case, in the owner's words: Rajab checks in on his own bike, takes the VAN
 * over when it is handed to him in the morning, and gives it back in the evening.
 * Recording both moves needed a manager at a desk, so the registry lagged reality
 * by hours — and everything downstream follows the registry: the van's kilometres,
 * the fuel he may claim, the meters he is asked for, the ride-home timer.
 *
 * ⭐⭐ A REQUEST MOVES NOTHING. This class records that a rider ASKED. The machine
 *    changes hands only in `decide()`, and `decide()` does it by calling the very
 *    same `VehicleService::assign()` / `release()` the web fleet screen has always
 *    called. There is deliberately no second handover engine: a parallel
 *    implementation is exactly how two surfaces drift into disagreeing about who
 *    holds the bike. Same principle the khaas transfer request states outright —
 *    "a request moves NO inventory; only /accept does".
 *
 * ⭐⭐ `live()` RE-DERIVES THE OPEN SET EVERY POLL, exactly like the verified-pin
 *    unlock banner it is modelled on. A banner is never stored state: the request
 *    must still be pending, still inside its TTL, and still make sense against the
 *    world as it is now. That is what makes a dead banner unconstructable rather
 *    than merely unlikely — the failure mode where a manager stares at a request
 *    somebody already handled on another screen.
 *
 * ⚠ EVERY method is safe before the SQL is run: `available()` gates the class on
 *   the table, so an upload landing before the migration degrades to "no requests"
 *   rather than throwing on a screen nobody expected to break.
 */
class VehicleHandoverRequestService
{
    public const TABLE = 't_ops_vehicle_handover_request';

    public const DIR_TAKE   = 'take';
    public const DIR_RETURN = 'return';

    public const ST_PENDING   = 'pending';
    public const ST_APPROVED  = 'approved';
    public const ST_REJECTED  = 'rejected';
    public const ST_CANCELLED = 'cancelled';
    public const ST_EXPIRED   = 'expired';

    /**
     * How long an unanswered request stays live (owner ruling: 12h, the same window
     * the pin-unlock request uses). A request nobody answered inside a shift is a
     * request that has been overtaken by events — the rider went home, the van is
     * parked. It must not resurface tomorrow morning as if it were fresh.
     */
    public const TTL_HOURS = 12;

    public function available(): bool
    {
        try { return Schema::hasTable(self::TABLE); }
        catch (\Throwable $e) { return false; }
    }

    /**
     * ⭐ ONE-STEP COMPANY → COMPANY SWAP (7-Oct-2026) — are its two columns there?
     *
     * A rider holding a company bike who asks for the van needs TWO readings: the bike's
     * closing and the van's opening. Before `vehicle_handover_swap_oct2026.sql` the request
     * had room for one, so it was refused ("pehle wapas karein…"). Uploaded before that SQL,
     * this answers false and the old refusal stands, word for word.
     */
    public function swapReady(): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        try {
            return $ok = $this->available()
                && Schema::hasColumn(self::TABLE, 'swap_from_vehicle_id')
                && Schema::hasColumn(self::TABLE, 'swap_from_meter');
        } catch (\Throwable $e) { return $ok = false; }
    }

    /** Is the meter PHOTO compulsory? Owner: optional now, possibly mandatory later. */
    public function photoRequired(): bool
    {
        try {
            $v = DB::table('t_fin_config')->where('config_key', 'HANDOVER_METER_PHOTO_REQUIRED')->value('config_value');
            return strtoupper((string) $v) === 'Y';
        } catch (\Throwable $e) { return false; }
    }

    private function fail(string $msg): array { return ['ok' => false, 'message' => $msg]; }

    // =================================================================
    // WHAT THE RIDER MAY ASK FOR
    // =================================================================

    /**
     * ⭐ THE PICKER, ORDERED THE WAY HIS DAY ACTUALLY GOES (owner ruling Q2).
     *
     * "Usually the most switched will be the van, so that should come on top — or if
     * we have a list of last used vehicles for that rider we can show them at the
     * top." Both, and the second subsumes the first: a machine this rider has
     * actually held sorts above everything else, most recent first, which puts the
     * van at the top for Rajab every morning WITHOUT hard-coding anything about
     * vans. A machine he has never touched can still be picked, just lower down.
     *
     * ⚠ A machine somebody else is holding is OFFERED, but labelled with the holder.
     *   Hiding it would be worse: the van IS normally held by yesterday's driver
     *   when Rajab asks for it, and that handover is the entire point. The approver
     *   gets the displaced-rider prompt exactly as he does on the web.
     */
    public function options(int $userId): array
    {
        if (!$this->available()) return ['holding' => null, 'options' => []];
        try {
            $svc = new VehicleService();
            $res = new VehicleResolver();

            $holdingId = $res->currentVehicleFor($userId);
            $holders   = [];   // vehicle_id => holder name
            foreach (DB::table(VehicleService::T_ASSIGN . ' as a')
                        ->leftJoin('t_sys_user as u', 'u.id', '=', 'a.user_id')
                        ->whereNull('a.released_on')
                        ->get(['a.vehicle_id', 'a.user_id', 'u.fullname']) as $h) {
                $holders[(int) $h->vehicle_id] = ['user_id' => (int) $h->user_id, 'name' => $h->fullname];
            }

            // His own history, most recently assigned first. DISTINCT by vehicle,
            // keeping the newest row's date as the sort key.
            $recent = [];
            foreach (DB::table(VehicleService::T_ASSIGN)
                        ->where('user_id', $userId)
                        ->orderByDesc('assigned_on')->orderByDesc('id')
                        ->get(['vehicle_id', 'assigned_on']) as $r) {
                $vid = (int) $r->vehicle_id;
                if (!isset($recent[$vid])) $recent[$vid] = substr((string) $r->assigned_on, 0, 10);
            }

            $out = [];
            foreach (DB::table(VehicleService::T_VEHICLE)->where('is_active', 1)
                        ->get(['id', 'nickname', 'reg_no', 'vtype', 'is_company']) as $v) {
                $vid = (int) $v->id;
                if ($holdingId && $vid === (int) $holdingId) continue;   // he already has it

                // ⚠⚠ Someone else's PERSONAL bike is never offered — the same rule the
                //   fleet screen's quick-picks follow. Company machines and HIS OWN only.
                //   "His own" is the FIRST-KEEPER test, NOT "he rode it once" (Sep-01
                //   review finding): $recent holds every machine he was ever assigned,
                //   which includes a colleague's bike he borrowed for a day — the exact
                //   Waseem-on-"Danish - own bike" shape ownVehicleFor() was hardened
                //   against the same morning. One borrow must not put Danish's personal
                //   bike in Waseem's picker with an Approve button behind it.
                $isCompany = (int) $v->is_company === 1;
                $mineEver  = isset($recent[$vid]);
                if (!$isCompany && !$this->isFirstKeeper($vid, $userId)) continue;

                $held = $holders[$vid] ?? null;
                $out[] = [
                    'id'          => $vid,
                    // ⚠ `VehicleService::displayName` is private; `labelFor` is the public
                    //   naming rule and is what every other surface prints, so the picker
                    //   cannot show a machine under a different name than the fleet screen.
                    'name'        => $res->labelFor($vid) ?: (string) ($v->nickname ?: $v->reg_no ?: ('Vehicle ' . $vid)),
                    'vtype'       => (string) $v->vtype,
                    'is_company'  => $isCompany,
                    'last_used'   => $recent[$vid] ?? null,
                    'held_by'     => $held['name'] ?? null,
                    'held_by_id'  => $held['user_id'] ?? null,
                    // Sort key: 0 = he has held it before (newest first), 1 = a van he
                    // never held, 2 = anything else.
                    '_rank'       => $mineEver ? 0 : ((string) $v->vtype === 'van' ? 1 : 2),
                ];
            }

            usort($out, function ($a, $b) {
                if ($a['_rank'] !== $b['_rank']) return $a['_rank'] <=> $b['_rank'];
                if ($a['_rank'] === 0) return strcmp((string) $b['last_used'], (string) $a['last_used']);
                return strcmp((string) $a['name'], (string) $b['name']);
            });
            foreach ($out as &$o) unset($o['_rank']);
            unset($o);

            return [
                /**
                 * ⚠ `vtype` + `is_company` ride along so the PHONE can apply the one naming
                 *   rule (utils/checkoutMachine → machineWords): a van is "Van", a company bike
                 *   is its plate, his own bike is "apni bike". Without them the sheet could only
                 *   print the raw label and would drift from the checkout dialogs. Additive.
                 */
                'holding' => $holdingId ? [
                    'id'         => (int) $holdingId,
                    'name'       => $res->labelFor((int) $holdingId),
                    'vtype'      => (string) (optional($res->vehicle((int) $holdingId))->vtype ?? 'bike'),
                    'is_company' => (int) (optional($res->vehicle((int) $holdingId))->is_company ?? 1) === 1,
                ] : null,
                // What he would get back if he handed his current machine in right now.
                'give_back' => $svc->ownVehicleFor($userId),
                'options'   => $out,
                'photo_required' => $this->photoRequired(),
                // ⭐ Additive (7-Oct): may he ask for a company machine while holding one, in ONE
                //   request with two readings? The phone shows the second meter box only when
                //   true — an older server, or this one before its SQL, keeps the old refusal.
                'swap_ready' => $this->swapReady(),
            ];
        } catch (\Throwable $e) {
            Log::warning('handover options failed', ['user' => $userId, 'error' => $e->getMessage()]);
            return ['holding' => null, 'options' => []];
        }
    }

    /**
     * "His own bike" = he is its FIRST keeper — the same rule (and the same
     * reasoning) as `VehicleService::ownVehicleFor()`, asked about one machine.
     * Memoized per process: the picker asks it once per non-company candidate.
     */
    private function isFirstKeeper(int $vehicleId, int $userId): bool
    {
        static $memo = [];
        $k = $vehicleId . '|' . $userId;
        if (isset($memo[$k])) return $memo[$k];
        try {
            $first = DB::table(VehicleService::T_ASSIGN)->where('vehicle_id', $vehicleId)
                ->orderBy('assigned_on')->orderBy('id')->value('user_id');
            return $memo[$k] = ($first !== null && (int) $first === $userId);
        } catch (\Throwable $e) {
            return $memo[$k] = false;
        }
    }

    // =================================================================
    // RAISE / CANCEL — the rider's side
    // =================================================================

    /**
     * @param ?int $closeMeter  7-Oct — the CLOSING reading of the company machine he is holding,
     *                          used only on a company → company swap (see the block below). The
     *                          other number, `$meterClaimed`, stays the reading of the machine
     *                          this request is ABOUT, exactly as before.
     */
    public function raise(int $userId, string $direction, int $vehicleId,
                          ?int $meterClaimed, ?string $note, ?string $photoPath = null,
                          ?int $closeMeter = null): array
    {
        if (!$this->available()) return $this->fail('Handover requests are not set up on this server yet.');

        $direction = $direction === self::DIR_RETURN ? self::DIR_RETURN : self::DIR_TAKE;
        try {
            $svc = new VehicleService();
            $res = new VehicleResolver();

            $v = DB::table(VehicleService::T_VEHICLE)->where('id', $vehicleId)->first();
            if (!$v) return $this->fail('That machine no longer exists.');
            if ((int) $v->is_active !== 1) return $this->fail('That machine is retired.');

            if ($this->photoRequired() && !$photoPath) {
                return $this->fail('A photo of the meter reading is required.');
            }

            $holdingId = $res->currentVehicleFor($userId);
            $giveBack  = null;
            // ⭐ 7-Oct: set only on a company → company swap (the machine he steps OFF + its reading).
            $swapFrom  = null;
            $swapMeter = null;

            if ($direction === self::DIR_TAKE) {
                if ($holdingId && (int) $holdingId === $vehicleId) {
                    return $this->fail('You already have that machine.');
                }
                /**
                 * ⚠ NO RIDER PROFILE ⇒ NOTHING CAN EVER BE ASSIGNED TO HIM. `VehicleService::assign()`
                 *   refuses such a user, so a request from him would sit on the managers' banner with
                 *   an Approve button that can only fail. Since 7-Oct the door is shown to EVERYONE
                 *   holding nothing (owner ruling), so say it to him now, while he is asking, instead
                 *   of to a manager hours later.
                 */
                if (!DB::table('t_ops_rider_profile')->where('user_id', $userId)->exists()) {
                    return $this->fail(
                        'Aap ka rider profile abhi nahi bana — is liye gaari aap ke naam nahi ho sakti. '
                        . 'Office se kehein ke pehle profile bana dein.'
                    );
                }
                // ⚠⚠ A PERSONAL machine can only be asked back by its OWNER (Sep-01
                //   review finding) — the same first-keeper rule the picker applies,
                //   enforced here too because a request body is not the picker.
                //   Without this, one day's borrow of a colleague's bike would let
                //   the borrower request it, and an Approve tap on the banner would
                //   take a man's personal bike off him.
                if ((int) $v->is_company !== 1 && !$this->isFirstKeeper($vehicleId, $userId)) {
                    return $this->fail('That is somebody\'s personal bike — only its owner can ask for it.');
                }

                /**
                 * ⭐⭐ R4 — "GIVE ME MY OWN BIKE BACK" *IS* "TAKE THE VAN BACK" (owner ruling,
                 *    13-Sep-2026). One physical event, so it must be ONE kind of request.
                 *
                 * ⚠⚠ THE PROD FAILURE THIS CLOSES. At 17:45 Rajab sent a TAKE of vehicle 9 (his
                 *    own bike) while holding the van. Filed as a TAKE, it carried no meaning for
                 *    the VAN at all: `decide()` took the TAKE branch, `assign()` closed the van
                 *    row through step 3, and the van's day ended with no closing reading from
                 *    anybody — Shabib typed it by hand at 17:47. Re-labelled here as a RETURN of
                 *    the van (give-back = the bike he asked for), the meter he types is
                 *    unambiguously the VAN's closing reading, and R3 makes it compulsory.
                 *
                 * ⚠ Only when the machine he HOLDS is a company one. Swapping between two of his
                 *   own bikes is a plain TAKE and stays one.
                 */
                if ($holdingId && (int) $v->is_company !== 1
                    && $svc->isCompanyMachine((int) $holdingId)) {
                    $direction = self::DIR_RETURN;
                    $giveBack  = $vehicleId;            // the bike he asked for
                    $vehicleId = (int) $holdingId;      // …the request is ABOUT the company machine
                    $v = DB::table(VehicleService::T_VEHICLE)->where('id', $vehicleId)->first();
                }
            }

            if ($direction === self::DIR_RETURN && $giveBack === null) {
                // A return is about the machine he actually holds.
                if (!$holdingId) return $this->fail('You are not holding a machine to hand back.');
                if ((int) $holdingId !== $vehicleId) {
                    return $this->fail('You are not holding that machine.');
                }
                // ⭐ Snapshot what he should get back, so his phone can SAY it before he
                //   sends and the approver reads the same sentence. It is only a
                //   proposal — `decide()` re-resolves it and the approver may change it.
                $own = $svc->ownVehicleFor($userId);
                $giveBack = $own['id'] ?? null;
            }

            /**
             * ⭐⭐ R3 AT THE RIDER'S DOOR — a company machine never moves without its odometer.
             *    Refused HERE rather than at approval so he is told while the machine is in front
             *    of him, not hours later through a manager. (On prod both of Rajab's requests
             *    carried `meter: null` and both were approved.)
             */
            if ($svc->isCompanyMachine($vehicleId) && ($meterClaimed === null || $meterClaimed <= 0)) {
                return $this->fail(
                    $svc->meterRequiredMessage($vehicleId,
                        $direction === self::DIR_RETURN ? 'closing' : 'opening')
                ) + ['meter_missing' => 'meter'];
            }

            /**
             * ⭐⭐ TWO COMPANY MACHINES IN ONE REQUEST — the ONE-STEP SWAP (owner ruling, 7-Oct-2026).
             *
             * He holds a company machine and is asking for ANOTHER one (Waseem on EHP-676 asking for
             * the van), so BOTH need a reading: the van's opening (`$meterClaimed`, checked just
             * above) and the bike's closing (`$closeMeter`). Each number has its own box and its own
             * column, so nothing is guessed.
             *
             * ⚠⚠ WHY IT COULD NOT STAY TWO REQUESTS. The old answer was "hand the bike back first,
             *    then ask for the van". But after the first approval he holds NOTHING — and a rider
             *    holding nothing had no door to ask with at all. A rider without his own bike was
             *    therefore stuck exactly halfway, with no machine.
             *
             * ⚠ Before the SQL the request has nowhere to keep the second reading, so the old refusal
             *   stands word for word. The everyday van → own-bike case is R4 above and is untouched.
             */
            if ($direction === self::DIR_TAKE && $holdingId
                && (int) $holdingId !== $vehicleId
                && $svc->isCompanyMachine((int) $holdingId)) {
                $heldName = (new VehicleResolver())->labelFor((int) $holdingId);
                if (!$this->swapReady()) {
                    return $this->fail(
                        'Pehle ' . $heldName . ' wapas karein (us ka meter likh kar), phir ye machine lein — '
                        . 'dono company ki hain, dono ki reading alag chahiye.'
                    );
                }
                if ($closeMeter === null || $closeMeter <= 0) {
                    // ⚠ An APK older than this round has no second box and can never send it — so
                    //   the sentence also keeps the old two-step route open for that phone.
                    return $this->fail(
                        $heldName . ' ka abhi ka meter bhi likhein — wo company ki hai, aur aap use '
                        . 'isi waqt wapas kar rahe hain. (Purani app mein ye box nahi hai: pehle '
                        . $heldName . ' wapas karein, phir ye machine maangein.)'
                    ) + ['meter_missing' => 'close_meter'];
                }
                $swapFrom  = (int) $holdingId;
                $swapMeter = $closeMeter;
            }

            // ⚠ ONE OPEN REQUEST PER RIDER — checked INSIDE a transaction with the
            //   rider's own pending rows locked (Sep-01 review finding: a plain
            //   read-then-insert let a duplicated network retry file two pending
            //   rows, and approving both moves two machines). The lock serialises
            //   two concurrent raises from one rider; the second sees the first's
            //   row and is refused. An EXPIRED-but-pending old row deliberately does
            //   not block (same TTL rule the banner uses).
            $id = DB::transaction(function () use ($userId, $direction, $vehicleId, $giveBack,
                                                   $meterClaimed, $photoPath, $note,
                                                   $swapFrom, $swapMeter) {
                $open = DB::table(self::TABLE)
                    ->where('user_id', $userId)->where('status', self::ST_PENDING)
                    ->where('requested_at', '>=', $this->ttlCutoff())
                    ->lockForUpdate()->exists();
                if ($open) return null;

                $row = [
                    'user_id'              => $userId,
                    'direction'            => $direction,
                    'vehicle_id'           => $vehicleId,
                    'give_back_vehicle_id' => $giveBack,
                    'meter_claimed'        => ($meterClaimed !== null && $meterClaimed > 0) ? $meterClaimed : null,
                    'photo_path'           => $photoPath,
                    'note'                 => $note ? mb_substr($note, 0, 255) : null,
                    'status'               => self::ST_PENDING,
                    'requested_at'         => now(),
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ];
                // Only a swap writes these — and a swap only exists once swapReady() is true.
                if ($swapFrom !== null) {
                    $row['swap_from_vehicle_id'] = $swapFrom;
                    $row['swap_from_meter']      = $swapMeter;
                }
                return (int) DB::table(self::TABLE)->insertGetId($row);
            });
            if ($id === null) {
                return $this->fail('You already have a handover request waiting for approval.');
            }

            Log::info('Vehicle handover requested', [
                'id' => $id, 'user' => $userId, 'direction' => $direction,
                'vehicle' => $vehicleId, 'meter' => $meterClaimed,
                'swap_from' => $swapFrom, 'swap_meter' => $swapMeter,
            ]);

            // 🔔 TELL THE PEOPLE WHO CAN ANSWER IT. A banner needs somebody to be
            //    looking at the right screen; the rider is standing next to the van
            //    waiting. Deferred so the phone's "sent" response is never held up
            //    by an FCM round trip, and try-wrapped so a push failure can never
            //    unmake a request that is already recorded.
            try {
                $riderName = DB::table('t_sys_user')->where('id', $userId)->value('fullname') ?: 'A rider';
                $vName = (new VehicleResolver())->labelFor($vehicleId) ?: 'a vehicle';
                // A swap names BOTH machines in the push, so the manager knows before opening
                // anything that approving also takes a bike back.
                if ($swapFrom !== null) {
                    $vName .= ' (handing back ' . ((new VehicleResolver())->labelFor($swapFrom) ?: 'his bike') . ')';
                }
                app()->terminating(function () use ($id, $riderName, $direction, $vName, $userId) {
                    try {
                        app(\App\Services\FirebaseService::class)
                            ->notifyVehicleHandoverRequest($id, $riderName, $direction, $vName, $userId);
                    } catch (\Throwable $e) { /* logged inside */ }
                });
            } catch (\Throwable $e) { /* a silent push is better than a lost request */ }

            return ['ok' => true, 'id' => $id, 'request' => $this->find($id),
                    'message' => $direction === self::DIR_TAKE
                        ? 'Request sent — waiting for approval.'
                        : 'Hand-back request sent — waiting for approval.'];
        } catch (\Throwable $e) {
            Log::error('handover raise failed', ['user' => $userId, 'error' => $e->getMessage()]);
            return $this->fail('Could not send that request.');
        }
    }

    /** The rider withdraws his own request. Nobody else can use this door. */
    public function cancel(int $id, int $userId): array
    {
        if (!$this->available()) return $this->fail('Handover requests are not set up yet.');
        try {
            $n = DB::table(self::TABLE)->where('id', $id)
                ->where('user_id', $userId)                 // his own only
                ->where('status', self::ST_PENDING)         // and only while it is open
                ->update(['status' => self::ST_CANCELLED, 'decided_at' => now(), 'updated_at' => now()]);
            return $n
                ? ['ok' => true, 'message' => 'Request cancelled.']
                : $this->fail('That request is no longer open.');
        } catch (\Throwable $e) {
            return $this->fail('Could not cancel that request.');
        }
    }

    // =================================================================
    // READ
    // =================================================================

    public function find(int $id): ?array
    {
        if (!$this->available()) return null;
        try {
            $r = DB::table(self::TABLE)->where('id', $id)->first();
            return $r ? $this->shape($r) : null;
        } catch (\Throwable $e) { return null; }
    }

    /** His own open request, or null — what the rider's vehicle card renders. */
    public function openFor(int $userId): ?array
    {
        if (!$this->available()) return null;
        try {
            $r = DB::table(self::TABLE)
                ->where('user_id', $userId)->where('status', self::ST_PENDING)
                ->where('requested_at', '>=', $this->ttlCutoff())
                ->orderByDesc('id')->first();
            return $r ? $this->shape($r) : null;
        } catch (\Throwable $e) { return null; }
    }

    /**
     * ⭐⭐ THE ONE QUERY BEHIND EVERY APPROVAL SURFACE — the mobile store banner, the
     *    web orders-page banner and the fleet screen's strip all render THIS, so two
     *    managers can never be looking at different lists.
     *
     * ⚠ RE-DERIVED, NOT STORED. Pending is not enough: the request must still be
     *   inside its TTL, the rider must still be active, and the machine must still
     *   exist and be active. Anything that has been overtaken by events simply stops
     *   appearing — which is why no "clear the banner" step exists to be forgotten.
     */
    public function live(int $limit = 25): array
    {
        if (!$this->available()) return [];
        try {
            $rows = DB::table(self::TABLE . ' as q')
                ->join('t_sys_user as u', 'u.id', '=', 'q.user_id')
                ->join(VehicleService::T_VEHICLE . ' as v', 'v.id', '=', 'q.vehicle_id')
                ->where('q.status', self::ST_PENDING)
                ->where('q.requested_at', '>=', $this->ttlCutoff())
                ->where('u.is_active', '1')
                ->where('v.is_active', 1)
                ->orderBy('q.requested_at')
                ->limit(max(1, $limit))
                ->get(['q.*', 'u.fullname as rider_name']);

            $out = [];
            foreach ($rows as $r) $out[] = $this->shape($r, true);
            return $out;
        } catch (\Throwable $e) {
            Log::warning('handover live failed', ['error' => $e->getMessage()]);
            return [];        // a broken banner must never break the screen it sits on
        }
    }

    private function ttlCutoff(): string
    {
        return now()->subHours(self::TTL_HOURS)->format('Y-m-d H:i:s');
    }

    /**
     * One row as the surfaces read it. `$rich` adds what an APPROVER needs: the
     * machine's current keeper and the sentence describing what he gets back.
     */
    private function shape($r, bool $rich = false): array
    {
        $svc = new VehicleService();
        $res = new VehicleResolver();

        $out = [
            'id'            => (int) $r->id,
            'user_id'       => (int) $r->user_id,
            'rider_name'    => $r->rider_name ?? (DB::table('t_sys_user')->where('id', $r->user_id)->value('fullname') ?: 'Rider'),
            'direction'     => (string) $r->direction,
            'vehicle_id'    => (int) $r->vehicle_id,
            'vehicle_name'  => $res->labelFor((int) $r->vehicle_id),
            'give_back_vehicle_id' => $r->give_back_vehicle_id ? (int) $r->give_back_vehicle_id : null,
            'give_back_name'=> $r->give_back_vehicle_id ? $res->labelFor((int) $r->give_back_vehicle_id) : null,
            'meter_claimed' => $r->meter_claimed !== null ? (int) $r->meter_claimed : null,
            // ⭐ 7-Oct — a company → company SWAP: the machine he hands back in the same move, and
            //   its closing reading. Null on every other request (and before the SQL, when the
            //   row has no such columns at all — hence property_exists, not `??`, so a real NULL
            //   and a missing column read the same).
            'swap_from_vehicle_id' => property_exists($r, 'swap_from_vehicle_id') && $r->swap_from_vehicle_id
                ? (int) $r->swap_from_vehicle_id : null,
            'swap_from_name'       => property_exists($r, 'swap_from_vehicle_id') && $r->swap_from_vehicle_id
                ? $res->labelFor((int) $r->swap_from_vehicle_id) : null,
            'swap_from_meter'      => property_exists($r, 'swap_from_meter') && $r->swap_from_meter !== null
                ? (int) $r->swap_from_meter : null,
            // ⚠ /public-storage, NOT asset('storage/…') (Sep-01 review finding): the
            //   public/storage symlink is unreliable on the stackcp deploy (two repair
            //   scripts exist in public/ because of it), so every photo on this server
            //   is served through the FileController /public-storage route instead.
            //   Same convention as VehicleService::publicUrl().
            'photo_url'     => !empty($r->photo_path) ? url('/public-storage/' . ltrim((string) $r->photo_path, '/')) : null,
            'note'          => $r->note,
            'status'        => (string) $r->status,
            'requested_at'  => (string) $r->requested_at,
            'decided_by'    => $r->decided_by ? (int) $r->decided_by : null,
            'decided_at'    => $r->decided_at,
            'decision_note' => $r->decision_note,
        ];

        if ($rich) {
            $keeper = $svc->keeperOf((int) $r->vehicle_id);
            $out['current_keeper_id']   = $keeper ? (int) $keeper->user_id : null;
            $out['current_keeper_name'] = $keeper
                ? (DB::table('t_sys_user')->where('id', $keeper->user_id)->value('fullname') ?: 'someone')
                : null;
            // Already true? Then approving it changes nothing — say so on the card.
            $out['already_satisfied'] = ((string) $r->direction === self::DIR_TAKE)
                && $keeper && (int) $keeper->user_id === (int) $r->user_id;

            // ⭐ WHAT HAPPENS TO THE MAN LOSING IT (Sep-01 review finding: the banners
            //   never asked the displaced-rider question the whole flow exists for).
            //   Approving still uses the same own-bike default the fleet screen has —
            //   this line makes the card SAY it before the tap, so "Farooq gets back:
            //   EDN-198" (or "…is left with nothing") is read, not discovered.
            if ((string) $r->direction === self::DIR_TAKE && $keeper && !$out['already_satisfied']) {
                $keeperOwn = $svc->ownVehicleFor((int) $keeper->user_id);
                $out['keeper_gets_back'] = $keeperOwn['name'] ?? null;
            }

            // ⭐ What the approver may change on a return, resolved FRESH. The snapshot
            //   taken at raise time can be stale by now (his bike may have been lent
            //   out), so the sheet offers today's answer and today's spares.
            if ((string) $r->direction === self::DIR_RETURN) {
                $out['give_back_suggested'] = $svc->ownVehicleFor((int) $r->user_id);
                $out['give_back_spares']    = array_values(array_filter(
                    $svc->spareVehicles(),
                    fn ($s) => (int) $s['id'] !== (int) $r->vehicle_id && $s['is_company']
                ));
            }

            // Soft plausibility on the claimed odometer — ADVICE, never a gate. Same
            // stance as the web assign modal's hint: a handover already happened, and
            // refusing to record it over a questionable digit leaves the register wrong.
            $out['meter_hint'] = null;
            if ($r->meter_claimed !== null) {
                try {
                    if (!$svc->readingPlausibleFor((int) $r->vehicle_id, (int) $r->meter_claimed)) {
                        $out['meter_hint'] = 'That reading looks far from this machine\'s last known odometer — worth a second look.';
                    }
                } catch (\Throwable $e) { /* no hint is fine */ }
            }
            // …and the same advice for the swap's second reading, against ITS machine.
            $out['swap_meter_hint'] = null;
            if ($out['swap_from_vehicle_id'] && $out['swap_from_meter'] !== null) {
                try {
                    if (!$svc->readingPlausibleFor($out['swap_from_vehicle_id'], $out['swap_from_meter'])) {
                        $out['swap_meter_hint'] = 'The hand-back reading looks far from that machine\'s last known odometer — worth a second look.';
                    }
                } catch (\Throwable $e) { /* no hint is fine */ }
            }
        }

        return $out;
    }

    // =================================================================
    // DECIDE — the ONLY place a machine actually moves
    // =================================================================

    /**
     * ⭐⭐ APPROVE EXECUTES THE REAL HANDOVER, THROUGH THE REAL ENGINE.
     *
     * @param array $over Approver overrides, all optional:
     *   give_back_vehicle_id  what he gets back on a return (owner ruling: the
     *                         management team must be able to change this)
     *   give_back_none        true = he gets nothing back, explicitly
     *   meter                 corrected odometer
     *   close_meter           7-Oct: the closing reading of the company machine he steps OFF on a
     *                         take (overrides the swap's stored one, or supplies it when he
     *                         picked up a company machine after he asked)
     *   displaced_action     what happens to the man losing the machine
     *   displaced_vehicle_id  …and onto which machine
     *   note                  free text recorded against the decision
     *
     * ⚠⚠ RE-VALIDATED AGAINST NOW. Minutes or hours pass between asking and
     *    approving, and in that time the van can change hands, a rider can be
     *    deactivated, a machine retired. The check at raise time proves nothing
     *    here, so everything is asked again.
     *
     * ⚠⚠ THE STATUS FLIP IS THE LOCK. `pending → approved` is a single conditional
     *    UPDATE, and only the caller that actually changed a row goes on to move the
     *    machine. Two managers tapping Approve on the same request at the same moment
     *    is not hypothetical — they are looking at the same banner on two phones.
     */
    public function decide(int $id, bool $approve, int $actorId, array $over = []): array
    {
        if (!$this->available()) return $this->fail('Handover requests are not set up yet.');

        try {
            $r = DB::table(self::TABLE)->where('id', $id)->first();
            if (!$r) return $this->fail('That request no longer exists.');
            if ((string) $r->status !== self::ST_PENDING) {
                return $this->fail('That request was already ' . $r->status . '.');
            }

            // ⚠ THE TTL BINDS HERE TOO (Sep-01 review finding). `live()`/`openFor()`
            //   hide an aged request, but the row itself sat 'pending' forever — so a
            //   stale tab, a replayed POST or a direct call with a known id could
            //   still move a machine on a three-day-old ask the world has moved past.
            //   Deciding an expired request now writes the 'expired' status the
            //   schema always documented, whichever button was pressed.
            if ((string) $r->requested_at < $this->ttlCutoff()) {
                DB::table(self::TABLE)->where('id', $id)->where('status', self::ST_PENDING)
                    ->update(['status' => self::ST_EXPIRED, 'decided_at' => now(), 'updated_at' => now()]);
                return $this->fail('That request expired ' . self::TTL_HOURS . ' hours after it was made — ask the rider to send a fresh one.');
            }

            $note = isset($over['note']) ? mb_substr((string) $over['note'], 0, 255) : null;

            // ---- REJECT: no machine moves, so nothing else to check.
            if (!$approve) {
                $n = DB::table(self::TABLE)->where('id', $id)->where('status', self::ST_PENDING)
                    ->update(['status' => self::ST_REJECTED, 'decided_by' => $actorId,
                              'decided_at' => now(), 'decision_note' => $note, 'updated_at' => now()]);
                if (!$n) return $this->fail('That request was just decided by someone else.');
                Log::info('Vehicle handover rejected', ['id' => $id, 'by' => $actorId]);
                $this->pushDecision((int) $r->user_id, false, (string) $r->direction,
                    (new VehicleResolver())->labelFor((int) $r->vehicle_id) ?: 'the vehicle', $note);
                return ['ok' => true, 'message' => 'Request rejected.', 'request' => $this->find($id)];
            }

            // ---- APPROVE: re-validate against the world as it is NOW.
            $svc = new VehicleService();
            $res = new VehicleResolver();

            $rider = DB::table('t_sys_user')->where('id', $r->user_id)->first(['id', 'is_active', 'fullname']);
            if (!$rider || (string) $rider->is_active !== '1') {
                return $this->fail('That rider is no longer active.');
            }
            $v = DB::table(VehicleService::T_VEHICLE)->where('id', $r->vehicle_id)->first();
            if (!$v || (int) $v->is_active !== 1) {
                return $this->fail('That machine is retired or gone.');
            }

            $direction = (string) $r->direction;
            $meter = array_key_exists('meter', $over) && $over['meter'] !== null && (int) $over['meter'] > 0
                ? (int) $over['meter']
                : ($r->meter_claimed !== null ? (int) $r->meter_claimed : null);

            if ($direction === self::DIR_RETURN) {
                // He must still be the one holding it, or there is nothing to hand back.
                $keeper = $svc->keeperOf((int) $r->vehicle_id);
                if (!$keeper || (int) $keeper->user_id !== (int) $r->user_id) {
                    return $this->fail('He is no longer holding that machine — nothing to hand back.');
                }
            }

            /**
             * ⭐⭐ R3 AT THE APPROVAL DOOR TOO. `raise()` now demands the reading, but rows filed
             *    BEFORE this shipped (and any future caller) must not slip through: on prod both
             *    of Rajab's meter-less requests were approved without a murmur. The approver can
             *    supply it here — the override field already exists on both banners.
             */
            if ($svc->isCompanyMachine((int) $r->vehicle_id) && ($meter === null || $meter <= 0)) {
                return $this->fail(
                    $svc->meterRequiredMessage((int) $r->vehicle_id,
                        $direction === self::DIR_RETURN ? 'closing' : 'opening')
                    . ' Type it in the meter box on this request before approving.'
                ) + ['meter_missing' => 'meter', 'meter_vehicle_id' => (int) $r->vehicle_id];
            }

            // ⚠ CLAIM THE REQUEST FIRST. If the machine moved and only THEN we tried to
            //   mark it approved, a lost race would move a machine twice while telling
            //   the second manager it failed. Claim, then act; a failure after this
            //   point is reported with the request already closed and the reason
            //   recorded, which is the honest order for an irreversible act.
            $claimed = DB::table(self::TABLE)->where('id', $id)->where('status', self::ST_PENDING)
                ->update(['status' => self::ST_APPROVED, 'decided_by' => $actorId,
                          'decided_at' => now(), 'decision_note' => $note, 'updated_at' => now()]);
            if (!$claimed) return $this->fail('That request was just decided by someone else.');

            $today = now()->format('Y-m-d');
            $result = null;
            $extraNote = '';

            if ($direction === self::DIR_TAKE) {
                /**
                 * ⭐⭐ THE MACHINE HE STEPS OFF — ITS CLOSING READING (7-Oct-2026 swap).
                 *
                 * `assign()` step 3 closes whatever else he holds, and R3 demands that machine's
                 * closing reading when it is a company one. Which number:
                 *   1. the approver's `close_meter`, when typed — a correction, or the answer to
                 *      "he picked up a company bike AFTER he asked";
                 *   2. else the swap's stored reading — but ONLY while he still holds THAT machine.
                 *      If the world moved (someone took the bike off him in between), the stored
                 *      number is of a machine he no longer has, and must never be written onto
                 *      whatever he holds now. assign() then asks for the right one by name.
                 */
                $vacatedMeter = null;
                if (isset($over['close_meter']) && (int) $over['close_meter'] > 0) {
                    $vacatedMeter = (int) $over['close_meter'];
                } elseif (property_exists($r, 'swap_from_vehicle_id') && $r->swap_from_vehicle_id
                          && $r->swap_from_meter !== null && (int) $r->swap_from_meter > 0) {
                    $swapKeeper = $svc->keeperOf((int) $r->swap_from_vehicle_id);
                    if ($swapKeeper && (int) $swapKeeper->user_id === (int) $r->user_id) {
                        $vacatedMeter = (int) $r->swap_from_meter;
                    }
                }
                // ⚠ WHAT HE IS ACTUALLY STEPPING OFF, read before the move — which is not always
                //   the request's `swap_from` (the approver's `close_meter` may be for a machine he
                //   was put on after asking). The outcome sentence and the push name THIS one.
                $steppingOff = null;
                if ($vacatedMeter !== null) {
                    $held = $res->currentVehicleFor((int) $r->user_id);
                    if ($held && (int) $held !== (int) $r->vehicle_id && $svc->isCompanyMachine((int) $held)) {
                        $steppingOff = (int) $held;
                    }
                }

                // The machine goes to him. `assign()` closes the previous keeper's row
                // AND his own other open row (his bike goes spare) — one call, the same
                // one the web screen makes.
                // ⚠ settle-follows = true: the displaced man's ride-home timer is
                //   judged AFTER settleDisplaced() places him, below.
                $result = $svc->assign((int) $r->vehicle_id, (int) $r->user_id, $today, $actorId,
                                       'Rider request #' . $id, $meter, true, $vacatedMeter);
                if (!($result['ok'] ?? false)) {
                    DB::table(self::TABLE)->where('id', $id)->update([
                        'status' => self::ST_PENDING, 'decided_by' => null, 'decided_at' => null,
                        'decision_note' => 'Approval failed: ' . ($result['message'] ?? 'unknown'),
                        'updated_at' => now(),
                    ]);
                    // ⭐ Say WHICH box the approver must fill, in this request's own words:
                    //   assign()'s `handover_meter` is this request's `meter`, its `vacated_meter`
                    //   is `close_meter`. The banners re-ask for that one number and retry.
                    $missing = ['handover_meter' => 'meter', 'vacated_meter' => 'close_meter'][$result['meter_missing'] ?? ''] ?? null;
                    return $this->fail($result['message'] ?? 'Could not hand that machine over.')
                        + ($missing ? ['meter_missing' => $missing,
                                       'meter_vehicle_id' => $result['meter_vehicle_id'] ?? null] : []);
                }
                $extraNote = $this->settleDisplaced($svc, $result['displaced_user_id'] ?? null,
                                                    $over, (int) $r->vehicle_id, $today, $actorId);
                // The swap's other half, in the outcome sentence — assign() already closed that
                // machine and wrote its reading (step 3 + R3); this only SAYS it happened.
                if ($steppingOff !== null) {
                    $extraNote .= ' ' . ($res->labelFor($steppingOff) ?: 'His bike')
                        . ' handed back at ' . number_format($vacatedMeter) . '.';
                }
                if (!empty($result['displaced_user_id'])) {
                    $svc->disarmIdleHomeJourney((int) $result['displaced_user_id']);
                }
            } else {
                // ---- RETURN. What does he get back?
                // ⭐⭐ NO OVERRIDE MEANS THE FRESH ANSWER, NOT THE SNAPSHOT (Sep-01
                //    review finding). Both approval banners DISPLAY the fresh
                //    resolution (`give_back_suggested`, computed in shape()), but the
                //    mobile banner posts an empty body — so executing the raise-time
                //    snapshot here meant the confirm dialog could promise DCR-5 and
                //    the approval hand over nothing (his bike was held at 08:00,
                //    free by 18:00). What is executed is now exactly what was shown.
                //    The snapshot survives on the row purely as the audit of what the
                //    rider was told when he asked.
                $giveBack = null;
                if (empty($over['give_back_none'])) {
                    if (array_key_exists('give_back_vehicle_id', $over) && $over['give_back_vehicle_id']) {
                        $giveBack = (int) $over['give_back_vehicle_id'];
                    } else {
                        $own = $svc->ownVehicleFor((int) $r->user_id);
                        $giveBack = $own['id'] ?? null;
                    }

                    // The approver's choice is validated the same way the web settle is:
                    // never the machine being handed back, never one somebody else holds.
                    if ($giveBack) {
                        if ($giveBack === (int) $r->vehicle_id) {
                            $giveBack = null;
                            $extraNote .= ' The machine being handed back cannot also be given back to him.';
                        } else {
                            $holder = $svc->keeperOf($giveBack);
                            if ($holder && (int) $holder->user_id !== (int) $r->user_id) {
                                $giveBack = null;
                                $extraNote .= ' The chosen machine is held by somebody else, so he was left without one.';
                            }
                        }
                    }
                }

                /**
                 * ⭐⭐ THE CLOSING ODOMETER BELONGS TO THE MACHINE HE IS HANDING BACK, not to
                 *    whatever he receives — it becomes that machine's OWN meter-log row
                 *    (driver = him), never the next assignment's `handover_meter`.
                 *
                 * ⚠⚠ EXACTLY ONE WRITER PER PATH (13-Sep-2026). `VehicleService` now owns
                 *    `recordClosingMeter()` and calls it itself from BOTH doors — from step 3 of
                 *    `assign()` when he is moved onto another machine, and from `release()` when
                 *    he is not. This service used to keep its own private copy and call it here;
                 *    that would now write the row twice on the give-back path and leave the log's
                 *    note ambiguous about who recorded it. So nothing is written here: `$meter`
                 *    is handed to whichever engine call actually closes the row, below.
                 */
                if ($giveBack) {
                    // Assigning his own bike closes the van row by itself (step 3 of
                    // assign()) — one call, no separate release, no window where he
                    // holds both or neither.
                    // ⚠ `$meter` rides along as the VACATED machine's closing reading so
                    //   assign()'s R3 gate is satisfied by the number the rider already typed.
                    //   The write itself happened above; this only proves the rule to the engine.
                    $result = $svc->assign($giveBack, (int) $r->user_id, $today, $actorId,
                                           'Handed back via rider request #' . $id, null, false, $meter);
                    if (!($result['ok'] ?? false)) {
                        // Fall back to a plain release: the van MUST come back either way.
                        $result = $svc->release((int) $r->vehicle_id, $today, $actorId, false, $meter);
                        $extraNote .= ' He could not be put back on the other machine — do it from the fleet screen.';
                    }
                } else {
                    // ⭐ No give-back: release() takes the reading and writes the close itself.
                    $result = $svc->release((int) $r->vehicle_id, $today, $actorId, false, $meter);
                }

                // ⚠ BOTH engine calls failed (release only fails on a real fault) —
                //   NOTHING moved, so the request must not read "approved". Same
                //   reopen the TAKE branch does, for the same honesty reason. The
                //   meter-log row written above stays: it is a true reading of the
                //   machine, whoever ends up recording the handover.
                if (!($result['ok'] ?? false)) {
                    DB::table(self::TABLE)->where('id', $id)->update([
                        'status' => self::ST_PENDING, 'decided_by' => null, 'decided_at' => null,
                        'decision_note' => 'Approval failed: ' . ($result['message'] ?? 'unknown'),
                        'updated_at' => now(),
                    ]);
                    return $this->fail($result['message'] ?? 'Could not record that hand-back.');
                }

                // ⚠ CARGO DOES NOT FOLLOW A VAN RETURNED TO NOBODY (Sep-01 review
                //   finding). assign()'s cargo-move runs when a van gains a NEW
                //   driver; an evening hand-back leaves it with none, so any box
                //   still scanned aboard stays on the ex-driver's manifest —
                //   invisible to whoever drives it next. Moving boxes to nobody is
                //   not possible, so the honest thing is to SAY it, loudly, in the
                //   sentence the approver reads. Schema-guarded and non-fatal: a
                //   missing column must never turn a recorded hand-back into a 500.
                try {
                    if ((string) $v->vtype === 'van'
                        && Schema::hasColumn('t_crm_prod_order', 'van_user_id')) {
                        $aboard = DB::table('t_crm_prod_order')
                            ->where('order_status', 'on_van')
                            ->where('van_user_id', (int) $r->user_id)
                            ->count();
                        if ($aboard > 0) {
                            $extraNote .= ' ⚠ ' . $aboard . ' order' . ($aboard > 1 ? 's are' : ' is')
                                . ' still loaded on the van — reassign them from the Van board.';
                        }
                    }
                } catch (\Throwable $e) { /* the warning is a kindness, never a blocker */ }
            }

            if (!empty($result['id'])) {
                DB::table(self::TABLE)->where('id', $id)
                    ->update(['applied_assignment_id' => (int) $result['id'], 'updated_at' => now()]);
            }

            Log::info('Vehicle handover approved', [
                'id' => $id, 'by' => $actorId, 'direction' => $direction,
                'vehicle' => (int) $r->vehicle_id, 'meter' => $meter,
            ]);

            // 🔔 …and tell the RIDER, because until he knows he keeps riding the
            //    machine he thinks he still has. His card updates on its own poll
            //    within 30s; this is what reaches him when the app is closed.
            $this->pushDecision((int) $r->user_id, true, (string) $r->direction,
                                $res->labelFor((int) $r->vehicle_id) ?: 'the vehicle', null,
                                // a swap tells him BOTH halves — he must stop riding the bike too
                                !empty($steppingOff) ? ($res->labelFor((int) $steppingOff) ?: null) : null);

            return ['ok' => true, 'request' => $this->find($id),
                    'message' => trim(($result['message'] ?? 'Done.') . $extraNote)];
        } catch (\Throwable $e) {
            Log::error('handover decide failed', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->fail('Could not complete that handover.');
        }
    }

    /** Tell the rider his request was answered. Deferred + try-wrapped: a decision
     *  that is already recorded must never fail because a push did. */
    private function pushDecision(int $riderId, bool $approved, string $direction,
                                  string $vehicleName, ?string $why = null,
                                  ?string $handedBack = null): void
    {
        try {
            app()->terminating(function () use ($riderId, $approved, $direction, $vehicleName, $why, $handedBack) {
                try {
                    // 🗣 Roman Urdu — this is the rider's own answer, and it tells him which
                    // machine to ride tomorrow (owner ruling). $why is the manager's typed
                    // reason and is passed through exactly as he wrote it.
                    // ⭐ $handedBack (7-Oct swap): the company machine that left him in the same move.
                    $body = $approved
                        ? ($direction === self::DIR_RETURN
                            ? "Aap ne {$vehicleName} wapas kar di hai."
                            : ($handedBack
                                ? "{$vehicleName} ab aap ki hai aur {$handedBack} wapas ho gayi — meter hamesha ki tarah daal dein."
                                : "{$vehicleName} ab aap ki hai — meter hamesha ki tarah daal dein."))
                        : ("{$vehicleName} ki request manzoor nahi hui."
                            . ($why ? ' ' . $why : ' Apni maujooda bike hi istemal karte rahein.'));
                    app(\App\Services\FirebaseService::class)->notifyUser($riderId, [
                        'title' => $approved ? '✅ Bike change manzoor ho gaya' : '❌ Bike change manzoor nahi hua',
                        'body'  => $body,
                    ], ['type' => 'vehicle_handover_decision'], 'shift_notifications');
                } catch (\Throwable $e) { /* best effort */ }
            });
        } catch (\Throwable $e) { /* best effort */ }
    }

    /**
     * The man who just lost the machine. Deliberately the SAME three answers the web
     * screen offers, with the same silent default (his own bike, when he has one and
     * it is free) — approving from a phone must not quietly mean something different
     * from approving from a desk.
     */
    private function settleDisplaced(VehicleService $svc, ?int $displacedId, array $over,
                                     int $handedOverVehicleId, string $date, int $actorId): string
    {
        if (!$displacedId) return '';
        try {
            $action = $over['displaced_action'] ?? null;
            if (!$action) {
                $action = $svc->ownVehicleFor($displacedId) ? 'own' : null;
                if (!$action) return ' The previous rider now has no machine.';
            }
            if ($action === 'none') return ' The previous rider now has no machine.';

            $target = null;
            if ($action === 'own') {
                $own = $svc->ownVehicleFor($displacedId);
                $target = $own['id'] ?? null;
                if (!$target) return ' His own bike could not be handed back — assign it from the fleet screen.';
            } elseif ($action === 'vehicle') {
                $target = (int) ($over['displaced_vehicle_id'] ?? 0);
                if ($target <= 0) return ' No replacement was chosen, so he now has none.';
                if ($target === $handedOverVehicleId) {
                    return ' He could not be moved onto the machine that was just handed over.';
                }
                $holder = $svc->keeperOf($target);
                if ($holder && (int) $holder->user_id !== $displacedId) {
                    return ' The replacement is held by somebody else, so he was left without one.';
                }
            }

            $r = $svc->assign((int) $target, $displacedId, $date, $actorId, 'Moved after handover',
                              isset($over['displaced_meter']) ? (int) $over['displaced_meter'] : null);
            return ($r['ok'] ?? false)
                ? ' The previous rider moved onto ' . ($svc->find((int) $target)['name'] ?? 'another machine') . '.'
                : ' The previous rider could not be placed — do it from the fleet screen.';
        } catch (\Throwable $e) {
            Log::warning('handover settleDisplaced failed', ['user' => $displacedId, 'error' => $e->getMessage()]);
            return ' The handover is recorded, but the previous rider could not be placed.';
        }
    }

}
