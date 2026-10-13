<?php

namespace App\Services\Ops;

use App\Http\Controllers\Ops\ShiftController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ⏳ A SHIFT CHANGE THAT IS WAITING FOR SOMEONE ABOVE TO SAY YES.
 *
 * Owner ask, 6-Sep-2026: "when Farooq or Shabib shift is changed Taimur gets a
 * notification banner to approve". Rules live in {@see ShiftAuthorityService}; this class
 * only handles the waiting.
 *
 * ⭐⭐ THE ONE DESIGN DECISION WORTH KEEPING. A request stores the ASSIGN PAYLOAD verbatim
 *    and nothing else. On approval the approver's own session replays it through the SAME
 *    `ShiftController::assignShiftToUser` engine every other surface uses — so the history
 *    row, the attendance re-stamp, the rider's push and his WhatsApp all happen exactly as
 *    they do today, only later, and by the approver's hand. There is no second assignment
 *    path to keep in step with the first.
 *
 * ⚠⚠ NOTHING IS WRITTEN TO THE SHIFT TABLES WHILE A REQUEST WAITS. The person whose shift
 *    it is has not been told and must not be: until someone approves, his shift is
 *    unchanged and his phone says nothing. That is the whole point of the round.
 *
 * ⚠ No prod scheduler ([[prod-has-no-scheduler-cron]]) — the lapse sweep rides the
 *   approvals poll via `app()->terminating()`, like the workshop cut-off.
 */
class ShiftChangeRequestService
{
    public const T = 't_ops_shift_change_request';

    /** A request nobody answered by the end of its start day is dead (owner ruling Q5). */
    public const STATUS_OPEN = 'proposed';

    private ShiftAuthorityService $auth;

    public function __construct(?ShiftAuthorityService $auth = null)
    {
        $this->auth = $auth ?: app(ShiftAuthorityService::class);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Raising one
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Park a change that needs approval. $payload is the same shape `/shifts/assign` takes.
     *
     * @return array{ok:bool, id:?int, message:string}
     */
    public function queue(array $payload, $actor, array $approvers, string $source = 'web'): array
    {
        if (!$this->auth->requestsAvailable()) {
            return ['ok' => false, 'id' => null, 'message' => 'Shift approvals are not switched on yet.'];
        }
        $userId = (int) ($payload['user_id'] ?? 0);
        $tplId  = (int) ($payload['shift_template_id'] ?? 0);
        if (!$userId || !$tplId) {
            return ['ok' => false, 'id' => null, 'message' => 'Nothing to send for approval.'];
        }
        $mode = (string) ($payload['mode'] ?? 'until_changed');
        $from = (string) ($payload['effective_from'] ?? now()->format('Y-m-d'));
        $to   = $payload['effective_to'] ?? null;
        if ($mode === 'until_changed') $to = null;
        if ($mode === 'one_day') $to = $from;

        /**
         * 🔁 Weekly days asked for but the request table cannot hold them yet → refuse BEFORE
         * anything is touched. (Checked after the withdraw below, a refusal would also have
         * silently withdrawn the person's earlier waiting request.)
         */
        if (($payload['day_pattern'] ?? null) !== null && !WeekdayPattern::requestColumnAvailable()) {
            return ['ok' => false, 'id' => null,
                    'message' => 'Weekly days cannot be sent for approval yet — the database update for them has not been run.'];
        }

        try {
            // ⭐ One open request per person per start-date+mode: pressing Save twice, or two
            //   managers asking the same thing, must not put two cards in front of Taimur.
            //   The newer payload wins — he should answer what was asked LAST.
            DB::table(self::T)
                ->where('user_id', $userId)
                ->where('status', self::STATUS_OPEN)
                ->where('effective_from', $from)
                ->where('mode', $mode)
                ->update(['status' => 'withdrawn', 'decided_at' => now(),
                          'decline_reason' => 'Replaced by a newer request']);

            $row = [
                'user_id' => $userId,
                'shift_template_id' => $tplId,
                'mode' => $mode,
                'effective_from' => $from,
                'effective_to' => $to,
                'location_id' => !empty($payload['location_id']) ? (int) $payload['location_id'] : null,
                'set_default_location' => !empty($payload['set_default_location']) ? 1 : 0,
                'requested_by' => (int) ($actor->id ?? 0),
                'requested_at' => now(),
                'source' => $source === 'mobile' ? 'mobile' : 'web',
                'status' => self::STATUS_OPEN,
                'created_at' => now(),
            ];
            /**
             * 🔁 Weekly days travel with the request, VERBATIM: NULL = the screen did not
             * send any (the change keeps his current ones), '{}' = cleared, JSON = these.
             * ⚠ If the column is missing but weekly days were asked for, refuse rather than
             *   queue a change that would later be approved WITHOUT them.
             */
            $dp = $payload['day_pattern'] ?? null;
            if ($dp !== null) {
                // (Column presence was checked BEFORE the older request was withdrawn, above.)
                $row['day_pattern'] = (string) $dp;
            }
            $id = (int) DB::table(self::T)->insertGetId($row);
        } catch (\Throwable $e) {
            Log::error('Shift change request insert failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'id' => null, 'message' => 'Could not send this for approval.'];
        }

        $this->notifyApprovers($id, $approvers, $actor);

        return ['ok' => true, 'id' => $id,
                'message' => 'Sent to ' . $this->auth->namesOf($approvers) . ' for approval. '
                           . $this->auth->nameOf($userId) . ' has not been told.'];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Reading the queue
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Open requests THIS person may answer — i.e. where they rank above both the person
     * whose shift it is and the person who asked. Re-derived from the ladder every time,
     * never a stored assignee list, so moving someone on the ladder takes effect at once.
     */
    public function pendingFor($user, bool $mobile = false, int $limit = 20): array
    {
        if (!$this->auth->requestsAvailable() || !$this->auth->isPlanner($user, $mobile)) return [];
        $meId = (int) ($user->id ?? 0);
        if (!$meId) return [];

        try {
            $rows = DB::table(self::T . ' as r')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'r.user_id')
                ->leftJoin('t_sys_user as a', 'a.id', '=', 'r.requested_by')
                ->leftJoin('t_ops_shift_template as t', 't.id', '=', 'r.shift_template_id')
                ->leftJoin('t_ops_company_locations as l', 'l.id', '=', 'r.location_id')
                ->where('r.status', self::STATUS_OPEN)
                ->orderBy('r.effective_from')
                ->orderBy('r.id')
                ->limit($limit * 3)
                ->get([
                    'r.*', 'u.fullname as person', 'a.fullname as asked_by',
                    't.shift_name', 't.shift_start', 't.shift_end', 'l.location_name',
                ]);
        } catch (\Throwable $e) { return []; }

        $out = [];
        foreach ($rows as $r) {
            $approvers = $this->auth->approversFor((int) $r->user_id, (int) $r->requested_by);
            if (!in_array($meId, $approvers, true)) continue;
            $out[] = $this->present($r);
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    /** Requests THIS person raised that are still waiting — so he can withdraw one. */
    public function myOpenRequests($user): array
    {
        if (!$this->auth->requestsAvailable()) return [];
        $meId = (int) ($user->id ?? 0);
        if (!$meId) return [];
        try {
            $rows = DB::table(self::T . ' as r')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'r.user_id')
                ->leftJoin('t_ops_shift_template as t', 't.id', '=', 'r.shift_template_id')
                ->where('r.status', self::STATUS_OPEN)
                ->where('r.requested_by', $meId)
                ->orderBy('r.effective_from')
                ->limit(20)
                ->get(['r.*', 'u.fullname as person', 't.shift_name', 't.shift_start', 't.shift_end']);
        } catch (\Throwable $e) { return []; }
        return $rows->map(fn ($r) => $this->present($r))->all();
    }

    /** Open requests keyed by the person they would change — feeds the planner's grid chips. */
    public function openByUser(): array
    {
        if (!$this->auth->requestsAvailable()) return [];
        try {
            $cols = ['r.id', 'r.user_id', 'r.mode', 'r.effective_from', 'r.effective_to',
                     't.shift_name', 'a.fullname as asked_by'];
            if (WeekdayPattern::requestColumnAvailable()) $cols[] = 'r.day_pattern';
            $rows = DB::table(self::T . ' as r')
                ->leftJoin('t_ops_shift_template as t', 't.id', '=', 'r.shift_template_id')
                ->leftJoin('t_sys_user as a', 'a.id', '=', 'r.requested_by')
                ->where('r.status', self::STATUS_OPEN)
                ->get($cols);
        } catch (\Throwable $e) { return []; }

        $map = [];
        foreach ($rows as $r) {
            $weekly = WeekdayPattern::summary(WeekdayPattern::parse($r->day_pattern ?? null));
            $map[(int) $r->user_id][] = [
                'id' => (int) $r->id,
                // 🔁 The grid chip reads shift_name — so the weekly days ride on it there.
                'shift_name' => $r->shift_name . ($weekly ? ' + 🔁 ' . $weekly : ''),
                'when' => $this->whenLabel($r->mode, $r->effective_from, $r->effective_to),
                'asked_by' => $r->asked_by ?: 'someone',
                'weekly_summary' => $weekly,
            ];
        }
        return $map;
    }

    private function present($r): array
    {
        $time = $r->shift_start
            ? substr($r->shift_start, 0, 5) . ($r->shift_end ? '–' . substr($r->shift_end, 0, 5) : ' onwards')
            : '';
        // 🔁 The approver must SEE the weekly days he is saying yes to. Every approval screen
        //   (desk banner, Shift rules page, phone) prints shift_time, so they ride on it —
        //   no screen can show the change without them. '{}' = "and remove his weekly days".
        $dp = $r->day_pattern ?? null;
        $weekly = WeekdayPattern::summary(WeekdayPattern::parse($dp));
        if ($weekly) {
            $time .= ($time ? ' · ' : '') . '🔁 ' . $weekly;
        } elseif ($dp !== null && $r->mode !== 'one_day' && trim((string) $dp) === '{}') {
            $time .= ($time ? ' · ' : '') . 'weekly days removed';
        }
        return [
            'id' => (int) $r->id,
            'user_id' => (int) $r->user_id,
            'person' => $r->person ?: ('#' . $r->user_id),
            'shift_name' => $r->shift_name ?: 'a shift',
            'shift_time' => $time,
            'weekly_summary' => $weekly,
            'mode' => $r->mode,
            'when' => $this->whenLabel($r->mode, $r->effective_from, $r->effective_to),
            'location_name' => $r->location_name ?? null,
            'asked_by' => $r->asked_by ?? null,
            'asked_ago' => $this->ago($r->requested_at),
            'source' => $r->source,
            'starts_today' => substr((string) $r->effective_from, 0, 10) === now()->format('Y-m-d'),
        ];
    }

    private function whenLabel(string $mode, $from, $to): string
    {
        $f = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('D j M') : '';
        if ($mode === 'one_day')    return $f($from) . ' only';
        if ($mode === 'date_range') return $f($from) . ' – ' . $f($to);
        return 'from ' . $f($from) . ' · until changed';
    }

    private function ago($when): string
    {
        try { return \Carbon\Carbon::parse($when)->diffForHumans(); }
        catch (\Throwable $e) { return ''; }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Answering one
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ✓ APPROVE — replay the stored payload through the real engine, as the approver.
     *
     * @return array{ok:bool, message:string}
     */
    public function approve($user, int $id, string $source = 'web'): array
    {
        $r = $this->openRow($id);
        if (!$r) return ['ok' => false, 'message' => 'That request is no longer waiting.'];

        $meId = (int) ($user->id ?? 0);
        if (!in_array($meId, $this->auth->approversFor((int) $r->user_id, (int) $r->requested_by), true)) {
            return ['ok' => false, 'message' => 'This one is not yours to approve.'];
        }

        // ⚠ Its day is gone — approving now would back-date a change nobody acted on.
        //   Say so plainly instead of silently re-writing an elapsed day.
        if (substr((string) $r->effective_from, 0, 10) < now()->format('Y-m-d')) {
            $this->close($id, 'lapsed', $meId, 'Start day passed before it was answered');
            $this->notifyRequester($r, 'lapsed', $user, null);
            return ['ok' => false, 'message' => 'Too late — that change was for a day that has passed. It has been dropped.'];
        }

        $payload = [
            'user_id' => (int) $r->user_id,
            'shift_template_id' => (int) $r->shift_template_id,
            'mode' => $r->mode,
            'effective_from' => substr((string) $r->effective_from, 0, 10),
        ];
        if ($r->effective_to) $payload['effective_to'] = substr((string) $r->effective_to, 0, 10);
        if ($r->location_id)  $payload['location_id'] = (int) $r->location_id;
        if ((int) $r->set_default_location === 1) $payload['set_default_location'] = true;
        // 🔁 Replay the weekly days exactly as asked. Absent key = "keep his current ones".
        if (($r->day_pattern ?? null) !== null) $payload[WeekdayPattern::INPUT] = (string) $r->day_pattern;

        $req = new Request();
        $req->replace($payload);
        // ⭐ The engine's own gate would ask "may Taimur change Shabib?" and say yes anyway —
        //   but this flag makes the intent explicit and keeps the replay from ever queueing
        //   a second request off the back of an approval.
        $req->attributes->set('shift_authority_ok', true);
        $req->attributes->set('shift_log_source', $source === 'mobile' ? 'mobile' : 'web');
        $req->attributes->set('shift_request_id', $id);

        try {
            $resp = app(ShiftController::class)->assignShiftToUser($req);
            $body = json_decode($resp->getContent(), true);
            if (empty($body['success'])) {
                return ['ok' => false, 'message' => $body['message'] ?? 'Could not apply the change.'];
            }
        } catch (\Throwable $e) {
            Log::error('Shift request approve replay failed', ['id' => $id, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not apply the change.'];
        }

        $this->close($id, 'approved', $meId, null);
        $this->notifyRequester($r, 'approved', $user, null);

        return ['ok' => true, 'message' => 'Approved — ' . $this->auth->nameOf((int) $r->user_id) . ' has been told.'];
    }

    /** ✖ DECLINE — only the person who asked hears about it. */
    public function decline($user, int $id, ?string $reason = null): array
    {
        $r = $this->openRow($id);
        if (!$r) return ['ok' => false, 'message' => 'That request is no longer waiting.'];

        $meId = (int) ($user->id ?? 0);
        if (!in_array($meId, $this->auth->approversFor((int) $r->user_id, (int) $r->requested_by), true)) {
            return ['ok' => false, 'message' => 'This one is not yours to answer.'];
        }
        $this->close($id, 'declined', $meId, $reason);
        $this->notifyRequester($r, 'declined', $user, $reason);
        return ['ok' => true, 'message' => 'Declined — ' . ($r->asked_by_name ?: 'the person who asked') . ' has been told.'];
    }

    /** ↩ WITHDRAW — the person who asked changed their mind before it was answered. */
    public function withdraw($user, int $id): array
    {
        $r = $this->openRow($id);
        if (!$r) return ['ok' => false, 'message' => 'That request is no longer waiting.'];
        $meId = (int) ($user->id ?? 0);
        if ((int) $r->requested_by !== $meId) {
            return ['ok' => false, 'message' => 'Only the person who asked can withdraw it.'];
        }
        $this->close($id, 'withdrawn', $meId, null);
        return ['ok' => true, 'message' => 'Withdrawn.'];
    }

    /**
     * 🕛 The sweep. A request still unanswered after its start day is dead — the day it was
     * for is over, and applying it later would re-write history nobody worked to.
     * Owner ruling Q5. Runs off a page poll; there is no cron on prod.
     */
    public function lapseDue(): int
    {
        if (!$this->auth->requestsAvailable()) return 0;
        try {
            $today = now()->format('Y-m-d');
            /**
             * ⚠⚠ THE JOIN IS LOad-BEARING, not cosmetic. `notifyRequester()` reads
             *    `$r->shift_name`, and Laravel's error handler turns an undefined-property
             *    warning into an ErrorException — which this method's own catch would
             *    swallow. The rows would still be marked lapsed, but the sweep would report
             *    0 and NOBODY WOULD EVER BE TOLD their request had died. Caught by
             *    test_shift_authority.php §8.
             */
            $due = DB::table(self::T . ' as r')
                ->leftJoin('t_ops_shift_template as t', 't.id', '=', 'r.shift_template_id')
                ->where('r.status', self::STATUS_OPEN)
                ->whereDate('r.effective_from', '<', $today)
                ->get(['r.*', 't.shift_name']);
            if ($due->isEmpty()) return 0;
            DB::table(self::T)->whereIn('id', $due->pluck('id'))->update([
                'status' => 'lapsed', 'decided_at' => now(),
                'decline_reason' => 'Nobody answered before the day it was for',
            ]);
            foreach ($due as $r) {
                $this->notifyRequester($r, 'lapsed', null, null);
            }
            return $due->count();
        } catch (\Throwable $e) {
            Log::warning('Shift request lapse sweep failed (non-fatal)', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    private function openRow(int $id)
    {
        if (!$this->auth->requestsAvailable()) return null;
        try {
            return DB::table(self::T . ' as r')
                ->leftJoin('t_sys_user as a', 'a.id', '=', 'r.requested_by')
                ->leftJoin('t_ops_shift_template as t', 't.id', '=', 'r.shift_template_id')
                ->where('r.id', $id)->where('r.status', self::STATUS_OPEN)
                ->first(['r.*', 'a.fullname as asked_by_name', 't.shift_name']);
        } catch (\Throwable $e) { return null; }
    }

    private function close(int $id, string $status, ?int $byUserId, ?string $reason): void
    {
        try {
            DB::table(self::T)->where('id', $id)->update([
                'status' => $status,
                'decided_by' => $byUserId,
                'decided_at' => now(),
                'decline_reason' => $reason,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Shift request close failed', ['id' => $id, 'error' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Telling people (best-effort; the banner is the reliable channel)
    // ─────────────────────────────────────────────────────────────────────────────

    private function notifyApprovers(int $id, array $approvers, $actor): void
    {
        $r = $this->openRow($id);
        if (!$r) return;
        $person = $this->auth->nameOf((int) $r->user_id);
        $asker  = $actor->fullname ?? $this->auth->nameOf((int) ($actor->id ?? 0));
        $body = $asker . ' wants to put ' . $person . ' on ' . ($r->shift_name ?: 'a shift')
              . ' ' . $this->whenLabel($r->mode, $r->effective_from, $r->effective_to)
              . '. ' . $person . ' has not been told.';
        foreach ($approvers as $uid) {
            try {
                app(\App\Services\FirebaseService::class)->notifyUser(
                    (int) $uid,
                    ['title' => '⏳ A shift change needs your approval', 'body' => $body],
                    ['type' => 'shift_change_request', 'request_id' => (string) $id],
                    'shift_notifications'
                );
            } catch (\Throwable $e) {
                Log::warning('Shift request push failed (non-fatal)', ['to' => $uid, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * ⚠⚠ ONLY THE PERSON WHO ASKED is told about a decline, a lapse or a withdrawal. The
     *    person whose shift it would have been never heard about it in the first place, and
     *    telling him now would be telling him about a change that is not happening.
     *    On APPROVAL he is told by the engine, in the ordinary way, like any other change.
     */
    private function notifyRequester($r, string $event, $decider, ?string $reason): void
    {
        $to = (int) ($r->requested_by ?? 0);
        if (!$to) return;
        $person = $this->auth->nameOf((int) ($r->user_id ?? 0));
        // ⚠ Every read off $r is null-safe: two callers pass rows of DIFFERENT shapes, and
        //   a missing property is an ErrorException here, not a quiet null (see lapseDue).
        $what = (($r->shift_name ?? null) ?: 'the shift') . ' for ' . $person;
        $by = $decider ? ($decider->fullname ?? $this->auth->nameOf((int) ($decider->id ?? 0))) : null;

        if ($event === 'approved') {
            $title = '✓ Shift change approved';
            $body = $what . ' was approved' . ($by ? ' by ' . $by : '') . '. He has been told.';
        } elseif ($event === 'declined') {
            $title = '✖ Shift change declined';
            $body = $what . ' was declined' . ($by ? ' by ' . $by : '') . ($reason ? ' — ' . $reason : '') . '.';
        } else {
            $title = '⌛ Shift change dropped';
            $body = $what . ' was never answered and the day has passed, so it was dropped.';
        }
        try {
            app(\App\Services\FirebaseService::class)->notifyUser(
                $to, ['title' => $title, 'body' => $body],
                ['type' => 'shift_change_request'], 'shift_notifications'
            );
        } catch (\Throwable $e) {
            Log::warning('Shift request result push failed (non-fatal)', ['to' => $to, 'error' => $e->getMessage()]);
        }
    }
}
