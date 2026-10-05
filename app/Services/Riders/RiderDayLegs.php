<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐ "WHICH MACHINES DID THIS RIDER PUT KILOMETRES ON, ON THIS DATE?" — one answer,
 *    every surface (Aug-27 2026).
 *
 * WHY IT EXISTS
 * `t_ops_attendance` holds exactly ONE meter pair per rider-day. That was fine while a
 * rider had one machine, and it stopped being fine the day Rajab started arriving on his
 * own bike and taking the van out mid-shift: his own bike's kilometres have nowhere to
 * live, so his per-km petrol claim — real money he is owed — simply stopped appearing.
 * The Aug-21/22 round made the mixed day RECORDABLE (per-reading vehicle stamps, plus the
 * vehicle meter log). This class is what makes it USABLE: it reassembles a rider's day
 * from every place a reading can legitimately live and hands back one leg per machine.
 *
 * ⭐ EVERY consumer reads THIS — the rider's attendance payload, the claim guards, the
 *   manager's petrol modal. That is the point: a rider must never be offered a claim the
 *   server will refuse, and a manager must never see a different day than the rider does.
 *   (This codebase has been bitten repeatedly by the same rule living in two places —
 *   three copies of "is this a transfer day", four of the sane-row predicate.)
 *
 * ⚠⚠ FAIL-OPEN, ALWAYS. An empty result means "no opinion", NEVER "he rode nothing".
 *    Callers must treat [] as "behave exactly as before". A read failure here must never
 *    remove a rider's claim button or block his money — that is strictly worse than the
 *    bug this class exists to fix.
 *
 * WHERE A READING CAN LIVE, and how each is attributed:
 *
 *   1. THE ATTENDANCE ROW — `meter_start` / `meter_end`, each carrying its own
 *      `meter_*_vehicle_id` stamp (step C, LIVE on prod). Stamps are the recorded FACT;
 *      when they are absent the machine is DERIVED via `vehicleForDay`, which is exactly
 *      today's behaviour, so history is untouched.
 *
 *   2. THE VEHICLE METER LOG (`t_ops_vehicle_meter_log`) — the manager-entered stint. Two
 *      kinds count as this rider's:
 *        • rows naming him as the driver, and
 *        • DRIVERLESS rows on a machine that is his own (see `ownMachineIdsFor`) —
 *          because an own bike's kilometres belong to its owner by construction, and in
 *          practice nobody fills the driver box: every log row on prod has it NULL.
 *      This is what makes the owner's ruling work — readings a manager types on the
 *      Vehicles page reach the rider's own claim on his phone.
 */
class RiderDayLegs
{
    /**
     * Could two readings plausibly be of the SAME machine?
     *
     * ⚠ Deliberately GENEROUS (2,000 km, the codebase's existing "wild jump" bound) and
     *   not the 500 km day bound. This test only ever runs when ONE of the two readings
     *   is stamped and the other is not, and it decides whether the unstamped one inherits
     *   the stamp. Too tight and a genuinely long day gets split into two phantom
     *   machines; the catastrophe it must catch is 6,434 → 73,959, which is 67,000.
     */
    private const SAME_MACHINE_SPAN_KM = VehicleService::MAX_GAP_KM;

    /** Per-process memo: ['userId|from|to' => ['Y-m-d' => legs[]]]. */
    private static array $memo = [];
    /** Per-process memo for ownership, which is a stable fact about a rider. */
    private static array $ownMemo = [];
    /** [vehicleId => ?userId] — the inverse direction (see ownerOf). */
    private static array $ownerMemo = [];

    /**
     * Every machine this rider put kilometres on, on this date.
     *
     * @return array<int, array> one leg per machine; [] means "cannot say" (see the class note)
     */
    public function forDay(int $userId, string $date): array
    {
        $date = substr($date, 0, 10);
        return $this->forRange($userId, $date, $date)[$date] ?? [];
    }

    /**
     * The bulk form — ['Y-m-d' => legs[]] — because the month sheet asks for 30 days at
     * once and the per-day form would be ~100 round trips per page.
     *
     * @param  array|null $attByDate  the caller's already-loaded attendance rows keyed by
     *                                date (the monthly endpoint has them); null = load here.
     */
    public function forRange(int $userId, string $from, string $to, ?array $attByDate = null): array
    {
        $from = substr($from, 0, 10);
        $to   = substr($to, 0, 10);
        $key  = $userId . '|' . $from . '|' . $to;
        if (isset(self::$memo[$key])) return self::$memo[$key];

        $out = [];
        try {
            $svc = new VehicleService();
            if (!$svc->available()) return self::$memo[$key] = [];

            $res      = new VehicleResolver();
            $hasStamp = VehicleService::stampsAvailable();

            // ── the rider's own attendance rows ────────────────────────────────────
            if ($attByDate === null) {
                $attByDate = DB::table('t_ops_attendance')
                    ->where('user_id', $userId)
                    ->whereBetween('attendance_date', [$from, $to])
                    ->get()->keyBy('attendance_date')->toArray();
            }

            // ── the day-level fallback, in bulk (one query, not one per day) ───────
            $derivedMap = $res->vehiclesForDays([$userId], $from, $to);

            // ── manager/rider entered machine-log stints ───────────────────────────
            $ownIds  = $this->ownMachineIdsFor($userId);
            $logRows = $this->logRows($userId, $ownIds, $from, $to);

            // ── every machine either source mentions, resolved once ────────────────
            // ⚠⚠ HIS OWN MACHINES BELONG IN HERE EVEN WHEN NOTHING MENTIONS THEM. The
            //    second chance in `deriveFor` can name an own machine that neither the
            //    day's assignment nor the log ever referred to — which is the whole point
            //    of it — and a leg on a machine missing from this map is silently dropped
            //    by `merge`. That made a SINGLE-DAY lookup answer differently from a
            //    month-range one (13-Aug: no leg alone, own bike inside a range), i.e. the
            //    claim path and the display path disagreeing — a button the server refuses.
            $vids = [];
            foreach ($ownIds as $v)     { $vids[(int) $v] = true; }
            foreach ($derivedMap as $v) { $vids[(int) $v] = true; }
            foreach ($logRows as $r)    { $vids[(int) $r->vehicle_id] = true; }
            foreach ($attByDate as $a) {
                $a = (object) $a;
                foreach (['meter_start_vehicle_id', 'meter_end_vehicle_id'] as $c) {
                    if (!empty($a->$c)) $vids[(int) $a->$c] = true;
                }
            }
            $vehicles = $this->vehicleMap(array_keys($vids));

            // ── who typed the log rows (for provenance on the rider's screen) ──────
            $names = $this->userNames(array_filter(array_map(
                fn ($r) => $r->entered_by ? (int) $r->entered_by : null, $logRows
            )));

            $cursor = \Carbon\Carbon::parse($from)->startOfDay();
            $last   = \Carbon\Carbon::parse($to)->startOfDay();
            while ($cursor->lte($last)) {
                $d    = $cursor->format('Y-m-d');
                $legs = [];

                $att = $attByDate[$d] ?? null;
                if ($att !== null) {
                    $legs = array_merge($legs, $this->attendanceLegs(
                        (object) $att, $derivedMap[$userId . '|' . $d] ?? null,
                        $hasStamp, $ownIds, $svc
                    ));
                }

                foreach ($logRows as $r) {
                    if (substr((string) $r->log_date, 0, 10) !== $d) continue;
                    if ($this->duplicatesAttendance($r, $legs)) continue;
                    $legs[] = [
                        'vehicle_id'   => (int) $r->vehicle_id,
                        'meter_start'  => $r->meter_start !== null ? (int) $r->meter_start : null,
                        'meter_end'    => $r->meter_end   !== null ? (int) $r->meter_end   : null,
                        'source'       => 'log',
                        'log_id'       => (int) $r->id,
                        'attendance_id' => $att ? (int) ((object) $att)->id : null,
                        'entered_by'   => $r->entered_by ? (int) $r->entered_by : null,
                        'entered_by_name' => $r->entered_by ? ($names[(int) $r->entered_by] ?? null) : null,
                    ];
                }

                $merged = $this->merge($legs, $vehicles, $userId, $d);
                if ($merged) $out[$d] = $merged;

                $cursor->addDay();
            }
        } catch (\Throwable $e) {
            Log::warning('RiderDayLegs failed (non-fatal)', [
                'user' => $userId, 'from' => $from, 'error' => $e->getMessage(),
            ]);
            $out = [];
        }

        return self::$memo[$key] = $out;
    }

    /**
     * The attendance row's two readings, each attributed to a machine.
     *
     * ⭐ THE INHERIT RULE. When one reading is stamped and the other is not, the unstamped
     *   one belongs to the same machine PROVIDED the pair reads like one odometer. That is
     *   not a nicety — it is the live case: a rider photographs his own bike's close while
     *   the registry still says he holds the van, `readingPlausibleFor` refuses the stamp
     *   (correctly — an unstamped reading is recoverable, a wrong stamp is not), and the
     *   day is left half-labelled. Prod has exactly this on 22-Aug: start 6,639 stamped to
     *   the own bike, end 6,645 stamped to nothing. Those are plainly the same 6 km.
     *
     * ⚠ When the pair CANNOT be one odometer, the unstamped reading falls to the day-level
     *   derivation — and if that just names the stamped machine again it is left
     *   UNATTRIBUTED rather than forced, because merging two contradictory readings onto
     *   one machine would manufacture exactly the six-figure distance this all exists to
     *   prevent.
     */
    private function attendanceLegs(object $att, ?int $derived, bool $hasStamp,
                                    array $ownIds, VehicleService $svc): array
    {
        $ms = ($att->meter_start ?? null) !== null && (float) $att->meter_start > 0 ? (int) $att->meter_start : null;
        $me = ($att->meter_end   ?? null) !== null && (float) $att->meter_end   > 0 ? (int) $att->meter_end   : null;
        if ($ms === null && $me === null) return [];
        // The day the readings were taken — lets Rule P refuse a machine whose odometer was
        // already LOWER on a later day (an odometer never runs backwards).
        $attDate = !empty($att->attendance_date) ? substr((string) $att->attendance_date, 0, 10) : null;

        $sV = $hasStamp && !empty($att->meter_start_vehicle_id) ? (int) $att->meter_start_vehicle_id : null;
        $eV = $hasStamp && !empty($att->meter_end_vehicle_id)   ? (int) $att->meter_end_vehicle_id   : null;
        if ($ms === null) $sV = null;
        if ($me === null) $eV = null;

        if ($ms !== null && $me !== null) {
            // (on the continuous scale of the machine in question — a swapped meter mid-day
            //  is still ONE machine; identical without a replacement)
            $spanVid = $sV ?: ($eV ?: $derived);
            $cms = MeterReplacement::toContinuous($spanVid, $ms, $attDate);
            $cme = MeterReplacement::toContinuous($spanVid, $me, $attDate);
            $sameMachine = ($cme >= $cms) && (($cme - $cms) <= self::SAME_MACHINE_SPAN_KM);
            if ($sV && !$eV) {
                $d  = $this->deriveFor($derived, $me, $ownIds, $svc, $attDate);
                $eV = $sameMachine ? $sV : (($d && $d !== $sV) ? $d : null);
            } elseif ($eV && !$sV) {
                $d  = $this->deriveFor($derived, $ms, $ownIds, $svc, $attDate);
                $sV = $sameMachine ? $eV : (($d && $d !== $eV) ? $d : null);
            } elseif (!$sV && !$eV) {
                $sV = $this->deriveFor($derived, $ms, $ownIds, $svc, $attDate);
                $eV = $sameMachine
                    ? ($sV ?: $this->deriveFor($derived, $me, $ownIds, $svc, $attDate))
                    : $this->deriveFor($derived, $me, $ownIds, $svc, $attDate);
            }
        } else {
            if ($ms !== null && !$sV) $sV = $this->deriveFor($derived, $ms, $ownIds, $svc, $attDate);
            if ($me !== null && !$eV) $eV = $this->deriveFor($derived, $me, $ownIds, $svc, $attDate);
        }

        $aid = (int) $att->id;
        if ($sV && $eV && $sV === $eV) {
            return [[
                'vehicle_id' => $sV, 'meter_start' => $ms, 'meter_end' => $me,
                'source' => 'attendance', 'log_id' => null, 'attendance_id' => $aid,
                'entered_by' => null, 'entered_by_name' => null,
            ]];
        }

        $legs = [];
        if ($sV) {
            $legs[] = ['vehicle_id' => $sV, 'meter_start' => $ms, 'meter_end' => null,
                       'source' => 'attendance', 'log_id' => null, 'attendance_id' => $aid,
                       'entered_by' => null, 'entered_by_name' => null];
        }
        if ($eV) {
            $legs[] = ['vehicle_id' => $eV, 'meter_start' => null, 'meter_end' => $me,
                       'source' => 'attendance', 'log_id' => null, 'attendance_id' => $aid,
                       'entered_by' => null, 'entered_by_name' => null];
        }
        return $legs;
    }

    /**
     * ⭐⭐ A DERIVED MACHINE MUST STILL BE POSSIBLE (Rule P, applied on the read side).
     *
     * ⚠⚠ THE CASE THAT FORCED THIS, found on the replica while building. Rajab's 13-Aug
     *    row (5,637 → 5,717, plainly his own bike — he was PAID for it, claim 2711, and
     *    the money is stamped to the own bike) has no reading stamps, and no assignment
     *    covered that date, so `vehicleForDay` fell through to step 3: his profile default,
     *    which is the VAN. Trusting that would have declared the day "company", hidden his
     *    claim button and silently stopped a legitimate payment — the exact failure this
     *    whole change exists to end, re-introduced by the fix for it.
     *
     * ⭐ A STAMP IS A RECORDED FACT AND IS NEVER SECOND-GUESSED HERE. This applies ONLY to
     *   the derived answer, which is a guess — and a guess that names a machine whose
     *   odometer is 68,000 km away is simply wrong. Same evidence and same tolerance the
     *   write side already uses (`readingPlausibleFor`), so the two cannot drift.
     *
     * ⭐ SECOND CHANCE. When the derived machine cannot own the reading, his OWN machines
     *   are tried; exactly one plausible candidate wins. That is what puts 13-Aug back on
     *   the own bike with no SQL and no backfill. Ambiguity (none, or more than one) is
     *   left UNATTRIBUTED — which means "no opinion", which means today's behaviour.
     */
    private function deriveFor(?int $derived, ?int $reading, array $ownIds, VehicleService $svc, ?string $date = null): ?int
    {
        if ($reading === null) return $derived;
        try {
            if ($derived && $svc->readingPlausibleFor($derived, $reading, $date)) return $derived;

            $hits = [];
            foreach ($ownIds as $vid) {
                if ((int) $vid === (int) $derived) continue;
                if ($svc->readingPlausibleFor((int) $vid, $reading, $date)) $hits[] = (int) $vid;
            }
            if (count($hits) === 1) return $hits[0];

            // Nothing can honestly own this reading → say so, rather than guess.
            return null;
        } catch (\Throwable $e) {
            return $derived;              // plausibility is a safety net, never a gate
        }
    }

    /**
     * ⚠ DUPLICATE GUARD — the same rule the attribution engine already applies
     *   (MachineAttribution ~line 427): a manager typing the readings the rider also
     *   recorded must not be counted twice. A different stint on the same day is genuine
     *   and stays.
     *
     * ⚠⚠ WIDER THAN AN EXACT MATCH, and the replica proved why. Prod's 22-Aug log row on
     *    the own bike carries start 6,639 with NO close, while the rider's attendance row
     *    holds 6,639 → 6,645: the same morning reading typed twice, in two places. An
     *    exact-pair test misses it, so it became a second "part" of the day — harmless
     *    there only because the half row contributed no distance, but the moment such a
     *    row carries a close it would DOUBLE the kilometres, and this figure is money.
     *
     * ⭐ The rule: a log row adds nothing when every reading it actually carries is
     *   already in the attendance leg for that machine. A row whose readings differ is a
     *   real second stint and is kept.
     */
    private function duplicatesAttendance(object $logRow, array $legs): bool
    {
        $ls = $logRow->meter_start !== null ? (int) $logRow->meter_start : null;
        $le = $logRow->meter_end   !== null ? (int) $logRow->meter_end   : null;
        if ($ls === null && $le === null) return true;      // an empty row is never a stint

        foreach ($legs as $l) {
            if ($l['source'] !== 'attendance') continue;
            if ((int) $l['vehicle_id'] !== (int) $logRow->vehicle_id) continue;
            $startSeen = $ls === null || $ls === $l['meter_start'];
            $endSeen   = $le === null || $le === $l['meter_end'];
            if ($startSeen && $endSeen) return true;
        }
        return false;
    }

    /**
     * One entry per MACHINE. Two stints on the same machine (his morning pair plus a
     * manager-entered afternoon run) are one leg whose km is the sum — the rider is owed
     * for both, and a single machine appearing twice on his screen would read as a bug.
     */
    private function merge(array $legs, array $vehicles, int $userId, ?string $date = null): array
    {
        $byVehicle = [];
        foreach ($legs as $l) {
            $vid = (int) $l['vehicle_id'];
            $v   = $vehicles[$vid] ?? null;
            if (!$v) continue;                       // a machine we cannot describe is not a leg

            // ⭐ Meter replaced (Oct-2026): distances and "which reading is the later one" are
            //   asked on the machine's continuous scale (`$cs`/`$ce`), so the day a meter is
            //   swapped (in at 21,400, out at 35) still has its real km. The leg keeps the
            //   figures as typed. `$cs`/`$ce` ARE those figures without a replacement.
            $cs = MeterReplacement::toContinuous($vid, $l['meter_start'], $date);
            $ce = MeterReplacement::toContinuous($vid, $l['meter_end'], $date);

            $km = null;
            if ($cs !== null && $ce !== null && $ce >= $cs) {
                $km = (float) ($ce - $cs);
            }

            if (!isset($byVehicle[$vid])) {
                $byVehicle[$vid] = [
                    'vehicle_id'  => $vid,
                    'label'       => $this->labelOf($v),
                    'is_company'  => ((int) $v->is_company === 1),
                    // ⭐ WHAT KIND OF MACHINE IT IS — 'bike' | 'van' (4 Sep 2026).
                    //   `vehicleMap()` has always SELECTed vtype and then dropped it here, so
                    //   every screen drawing a leg had only `is_company` to go on and used it as
                    //   a stand-in for "van". That is a different axis: it drew a company BIKE
                    //   as a van, and a van as a bike wherever the own/company sense was flipped.
                    //   The registry knows the answer; this just stops it being thrown away.
                    'vtype'       => (((string) ($v->vtype ?? '')) === 'van') ? 'van' : 'bike',
                    'meter_start' => $l['meter_start'],
                    'meter_end'   => $l['meter_end'],
                    'km'          => $km,
                    'source'      => $l['source'],
                    'parts'       => 1,
                    'attendance_id'   => $l['attendance_id'],
                    'log_id'          => $l['log_id'],
                    'entered_by'      => $l['entered_by'],
                    'entered_by_name' => $l['entered_by_name'],
                    'self_entered'    => $l['entered_by'] !== null && (int) $l['entered_by'] === $userId,
                ];
                continue;
            }

            $cur = $byVehicle[$vid];
            $cur['parts']++;
            $cur['km'] = ($km === null && $cur['km'] === null) ? null : (float) ($cur['km'] ?? 0) + (float) ($km ?? 0);
            // Readings still describe the day's span when they are coherent; two stints
            // on one machine keep the outermost pair.
            if ($l['meter_start'] !== null
                && ($cur['meter_start'] === null
                    || $cs < MeterReplacement::toContinuous($vid, $cur['meter_start'], $date))) {
                $cur['meter_start'] = $l['meter_start'];
            }
            if ($l['meter_end'] !== null
                && ($cur['meter_end'] === null
                    || $ce > MeterReplacement::toContinuous($vid, $cur['meter_end'], $date))) {
                $cur['meter_end'] = $l['meter_end'];
            }
            if ($cur['attendance_id'] === null) $cur['attendance_id'] = $l['attendance_id'];
            if ($cur['log_id'] === null)        $cur['log_id']        = $l['log_id'];
            if ($cur['source'] !== $l['source']) $cur['source']       = 'mixed';
            // ⚠ PROVENANCE SURVIVES THE MERGE. The attendance half of a mixed leg names
            //   nobody (the rider recorded it himself, through the ordinary flow), so it
            //   creates the entry with an empty `entered_by` — and the manager-entered
            //   stint merged in afterwards would silently lose its author. That line is
            //   exactly what tells the rider on his phone that these kilometres came from
            //   his manager rather than from his own reading.
            if ($cur['entered_by'] === null && $l['entered_by'] !== null) {
                $cur['entered_by']      = $l['entered_by'];
                $cur['entered_by_name'] = $l['entered_by_name'];
                $cur['self_entered']    = (int) $l['entered_by'] === $userId;
            }
            $byVehicle[$vid] = $cur;
        }

        // Own machines first — that is the row the rider acts on.
        $outLegs = array_values($byVehicle);
        usort($outLegs, function ($a, $b) {
            if ($a['is_company'] !== $b['is_company']) return $a['is_company'] ? 1 : -1;
            return ($b['km'] ?? 0) <=> ($a['km'] ?? 0);
        });
        return $outLegs;
    }

    /**
     * ⭐ WHICH MACHINES ARE "HIS OWN"? A non-company machine the registry has only ever
     *   assigned to HIM.
     *
     * ⚠ Deliberately NOT "assigned to him on that date". The whole situation this class
     *   addresses is a rider whose OPEN assignment is the company van while his own bike
     *   sits unassigned — asking for a covering assignment would answer "he owns nothing"
     *   on precisely the days that matter.
     *
     * ⚠ The exclusivity test (nobody else has ever held it) is what makes a DRIVERLESS log
     *   row safe to attribute: without it, a shared machine's ownerless stint could be
     *   claimed by two people. Own bikes are personal by construction — the rows are
     *   literally named "Rajab Masood - own bike" — so this costs nothing real.
     */
    public function ownMachineIdsFor(int $userId): array
    {
        if (isset(self::$ownMemo[$userId])) return self::$ownMemo[$userId];
        $out = [];
        try {
            // ⚠ ASSIGNMENT ROWS ONLY — `t_ops_rider_profile.default_vehicle_id` is
            //   deliberately NOT consulted. VehicleResolver documents it as a convenience
            //   mirror that goes stale ("a live example: one rider's mirror points at a
            //   colleague's bike"), and ownership is exactly the question where a stale
            //   pointer would hand one man's kilometres to another. The assignment table
            //   is the truth; on the live data it names the same machines anyway.
            $mine = DB::table(VehicleService::T_ASSIGN)
                ->where('user_id', $userId)->distinct()->pluck('vehicle_id')
                ->map(fn ($v) => (int) $v)->all();

            $mine = array_values(array_unique(array_filter($mine)));
            if (!$mine) return self::$ownMemo[$userId] = [];

            $shared = DB::table(VehicleService::T_ASSIGN)
                ->whereIn('vehicle_id', $mine)
                ->where('user_id', '!=', $userId)
                ->distinct()->pluck('vehicle_id')
                ->map(fn ($v) => (int) $v)->all();

            $own = DB::table(VehicleService::T_VEHICLE)
                ->whereIn('id', $mine)
                ->where('is_company', 0)
                ->pluck('id')->map(fn ($v) => (int) $v)->all();

            $out = array_values(array_diff($own, $shared));
        } catch (\Throwable $e) {
            $out = [];
        }
        return self::$ownMemo[$userId] = $out;
    }

    /**
     * ⭐ WHO OWNS THIS MACHINE? — the inverse of `ownMachineIdsFor`, for callers that
     *   start from a VEHICLE (the service-alert push needs "whose bike is due").
     *
     * ⚠⚠ THE TWO DIRECTIONS SHARE ONE DEFINITION and must stay in step: a NON-COMPANY
     *    machine that the registry has only ever assigned to ONE person belongs to that
     *    person. If either side's conditions change, change both — a vehicle that
     *    `ownMachineIdsFor(u)` lists must always answer `u` here, or a rider could be
     *    alerted for a bike his own day-legs refuse to credit him with.
     *
     * Null = no owner (a company machine, a shared one, or one nobody was ever
     * assigned) — the caller falls back to whatever it did before.
     */
    public function ownerOf(int $vehicleId): ?int
    {
        // ⚠ On the class, not a function static — flush() must clear it (the test
        //   harness mutates assignments inside rolled-back transactions, and a stale
        //   owner would leak between sections exactly like the isTransferDay memo trap).
        if (array_key_exists($vehicleId, self::$ownerMemo)) return self::$ownerMemo[$vehicleId];
        try {
            $isCompany = DB::table(VehicleService::T_VEHICLE)
                ->where('id', $vehicleId)->value('is_company');
            if ($isCompany === null || (int) $isCompany === 1) return self::$ownerMemo[$vehicleId] = null;

            $users = DB::table(VehicleService::T_ASSIGN)
                ->where('vehicle_id', $vehicleId)
                ->distinct()->pluck('user_id')
                ->map(fn ($v) => (int) $v)->all();

            return self::$ownerMemo[$vehicleId] = (count($users) === 1 ? $users[0] : null);
        } catch (\Throwable $e) {
            return self::$ownerMemo[$vehicleId] = null;
        }
    }

    private function logRows(int $userId, array $ownIds, string $from, string $to): array
    {
        try {
            if (!Schema::hasTable(VehicleService::T_METER_LOG)) return [];
            return DB::table(VehicleService::T_METER_LOG)
                ->whereBetween('log_date', [$from, $to])
                ->where(function ($w) use ($userId, $ownIds) {
                    $w->where('driver_user_id', $userId);
                    if ($ownIds) {
                        $w->orWhere(function ($o) use ($ownIds) {
                            $o->whereNull('driver_user_id')->whereIn('vehicle_id', $ownIds);
                        });
                    }
                })
                ->orderBy('log_date')->orderBy('id')
                ->get(['id', 'vehicle_id', 'log_date', 'meter_start', 'meter_end',
                       'driver_user_id', 'note', 'entered_by'])
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function vehicleMap(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return [];
        try {
            return DB::table(VehicleService::T_VEHICLE)->whereIn('id', $ids)
                ->get(['id', 'reg_no', 'nickname', 'is_company', 'vtype'])
                ->keyBy('id')->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function userNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        try {
            return DB::table('t_sys_user')->whereIn('id', $ids)
                ->pluck('fullname', 'id')->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Same shape as VehicleResolver::labelFor — plate, else nickname, else an id. */
    /**
     * ⚠ ONE LABEL RULE, shared with every meter sentence — see `MeterPairHelper::labelOf`.
     *   This used to be plate-first unconditionally, which named Rajab's personal bike
     *   "APPLIED-FOR" (its placeholder registration) in his own petrol modal and in the
     *   manager's two-machines tooltip. A personal machine now reads by its nickname
     *   ("Rajab Masood - own bike"); a company machine still reads by its plate.
     *   `vehicleMap()` selects `is_company`, so the rule always has what it needs.
     */
    private function labelOf(object $v): string
    {
        return MeterPairHelper::labelOf($v) ?: ('Vehicle #' . ($v->id ?? '?'));
    }

    // ── helpers every consumer shares, so nobody re-derives the same test ─────────

    /** The legs a per-km rider can actually claim: his own machine, with real distance. */
    public static function claimable(array $legs): array
    {
        return array_values(array_filter(
            $legs, fn ($l) => empty($l['is_company']) && ($l['km'] ?? 0) > 0
        ));
    }

    /** Did he put kilometres on a company machine that day? */
    public static function hasCompany(array $legs): bool
    {
        foreach ($legs as $l) if (!empty($l['is_company'])) return true;
        return false;
    }

    /** The leg for one machine, or null. */
    public static function forVehicle(array $legs, ?int $vehicleId): ?array
    {
        if (!$vehicleId) return null;
        foreach ($legs as $l) if ((int) $l['vehicle_id'] === (int) $vehicleId) return $l;
        return null;
    }

    /** Tests and long-running processes need to drop the memo. */
    public static function flush(): void
    {
        self::$memo = [];
        self::$ownMemo = [];
        self::$ownerMemo = [];
    }
}
