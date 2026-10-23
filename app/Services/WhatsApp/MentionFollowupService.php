<?php

namespace App\Services\WhatsApp;

use App\Services\FirebaseService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 🔴⏳ Tag alerts + "still not opened" reminders (Oct-2026).
 * Plan: TAG-ALERTS-PLAN-OCT19-2026.md (workspace root).
 *
 * When a teammate tags an ENABLED user (t_fin_config MENTION_FOLLOWUP_USER_IDS — Taimur
 * only for now) on a WhatsApp chat, this service opens a "cycle" (one t_wa_mention_followup
 * row): someone (the tagger, waiting_user) is waiting on someone (on_user) to open the chat.
 *
 *   tag            → loud alert to the on_user, first reminder due at +10 min
 *   +10,+25…+115   → still not opened: remind the TAGGER and re-buzz the on_user
 *   +120           → give up: one last push to the tagger ("it's in Daily Issues")
 *   opened         → closes at once (both markRead endpoints call onOpened)
 *   buttons        → Wait 10 / I'll call him (+15) / Stop (tagger) · On it (on_user)
 *
 * ONE place owns every rule, so the web, the phone API, the clock command and Daily Issues
 * cannot disagree. Users NOT in the list are untouched: the controllers keep today's push.
 *
 * ⭐ No-unnecessary-buzz rules (plan §2.6), each pinned by test_mention_followups_oct19.php:
 *   - at most MAX_REMINDERS reminders per cycle; nothing after open / On it / Stop / untag / give-up
 *   - a late tick (cron outage) sends ONE reminder, never the backlog; past 120 → straight to give-up
 *   - one open cycle per (chat, person): a double tap, a re-tag while unopened, or a SECOND label
 *     owned by the same person ("Qurbani Complaint" is also Taimur's) creates nothing new
 *   - every push is claimed twice: a conditional UPDATE on the row, then a unique alert_key in
 *     t_ops_service_alert_push — so the cron and the request piggyback can never double-send
 *   - every public entry point swallows its own errors: tagging or opening a chat must never fail
 *     because of this
 */
class MentionFollowupService
{
    public const FIRST_MIN     = 10;   // first reminder to the tagger
    public const EVERY_MIN     = 15;   // then every 15 min
    public const GIVE_UP_MIN   = 120;  // stop after 2 h
    public const MAX_REMINDERS = 8;    // 10,25,40,55,70,85,100,115
    public const WAIT_MIN      = 10;   // "Wait 10 min"
    public const CALLED_MIN    = 15;   // "I'll call him" → check again in 15
    public const FLAG_MINUTES  = 60;   // Daily Issues: took longer than this to respond
    public const RECENT_CLOSED_MIN = 30; // chat strip keeps "✓ opened" visible this long

    public const OPEN_STATES = ['watching', 'muted'];

    private const TABLE    = 't_wa_mention_followup';
    private const LOCK_KEY = 'mention_followups_tick';
    private const LOCK_SECONDS = 50;

    /** Per-request memo (static: the controllers resolve a fresh instance on every call). */
    private static ?bool $ready = null;
    private ?array $enabled = null;

    // ───────────────────────────── switches ─────────────────────────────

    /** The SQL has run. Code uploaded before the SQL simply does nothing. */
    public function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Schema::hasTable(self::TABLE);
            } catch (\Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    /** @return int[] user ids from t_fin_config MENTION_FOLLOWUP_USER_IDS ("68" or "68,74"). */
    public function enabledUserIds(): array
    {
        if ($this->enabled === null) {
            $this->enabled = [];
            try {
                $raw = (string) DB::table('t_fin_config')
                    ->where('config_key', 'MENTION_FOLLOWUP_USER_IDS')
                    ->where(function ($q) { $q->where('is_active', 1)->orWhereNull('is_active'); })
                    ->value('config_value');
                foreach (preg_split('/[\s,;]+/', $raw) as $p) {
                    if (ctype_digit($p) && (int) $p > 0) $this->enabled[] = (int) $p;
                }
                $this->enabled = array_values(array_unique($this->enabled));
            } catch (\Throwable $e) {
                $this->enabled = [];
            }
        }
        return $this->enabled;
    }

    public function isEnabledFor(int $userId): bool
    {
        return $userId > 0 && $this->ready() && in_array($userId, $this->enabledUserIds(), true);
    }

    /** Test/long-running helper: forget the memo (config edited mid-process). */
    public function refresh(): void
    {
        self::$ready = null;
        $this->enabled = null;
    }

    // ───────────────────────────── entry points ─────────────────────────────

    /**
     * A label was applied (both applyLabel twins call this AFTER their upsert).
     *
     * Returns NULL when this service does not own the tag (label has no person, a self-tag,
     * or the person is not enabled) — the controller then keeps TODAY's push logic exactly.
     * Returns an array when it does — the controller must NOT send its own push:
     *   ['cycle_id' => int|null, 'alerted' => bool]
     */
    public function onTagged(int $conversationId, int $labelId, int $taggerId): ?array
    {
        try {
            $label = DB::table('t_wa_labels')->where('id', $labelId)->first(['id', 'user_id']);
            $onUserId = $label && $label->user_id ? (int) $label->user_id : 0;
            if (!$onUserId || $onUserId === $taggerId || !$this->isEnabledFor($onUserId)) {
                return null;
            }

            // One open cycle per (chat, person). A double tap, a re-tag while still unopened,
            // or a second label owned by the same person must not buzz again.
            $open = DB::table(self::TABLE)
                ->where('conversation_id', $conversationId)
                ->where('on_user_id', $onUserId)
                ->whereIn('state', self::OPEN_STATES)
                ->orderByDesc('id')
                ->first(['id']);
            if ($open) {
                return ['cycle_id' => (int) $open->id, 'alerted' => false];
            }

            $pivotId = DB::table('t_wa_conversation_labels')
                ->where('conversation_id', $conversationId)
                ->where('label_id', $labelId)
                ->value('id');

            $now = now();
            $id = DB::table(self::TABLE)->insertGetId([
                'subject_type'          => 'wa_mention',
                'conversation_label_id' => $pivotId ? (int) $pivotId : null,
                'conversation_id'       => $conversationId,
                'label_id'              => $labelId,
                'on_user_id'            => $onUserId,
                'waiting_user_id'       => $taggerId,
                'tagged_at'             => $now,
                'state'                 => 'watching',
                'next_at'               => $now->copy()->addMinutes(self::FIRST_MIN),
                'remind_count'          => 0,
                'created_at'            => $now,
                'updated_at'            => $now,
            ]);

            $this->sendTeamAlert((int) $id, 'first');
            return ['cycle_id' => (int) $id, 'alerted' => true];
        } catch (\Throwable $e) {
            Log::warning('MentionFollowup onTagged failed (non-fatal)', ['conv' => $conversationId, 'error' => $e->getMessage()]);
            // We could not open a cycle — fall back to today's push rather than send nothing.
            return null;
        }
    }

    /**
     * The person opened the chat (both markRead twins call this right after they stamp
     * mention_seen_at). Cheap for everyone else: returns at once unless the viewer is enabled.
     */
    public function onOpened(int $conversationId, int $userId): void
    {
        try {
            if (!$this->isEnabledFor($userId)) return;
            // gave_up is included on purpose: a chat opened at 3 h must record 3 h in Daily
            // Issues, not "not opened". (No push for it — the tagger was already told.)
            $rows = DB::table(self::TABLE)
                ->where('conversation_id', $conversationId)
                ->where('on_user_id', $userId)
                ->whereIn('state', ['watching', 'muted', 'acknowledged', 'gave_up'])
                ->whereNull('opened_at')
                ->get();
            foreach ($rows as $row) {
                $this->closeAsOpened($row, now());
            }
        } catch (\Throwable $e) {
            Log::warning('MentionFollowup onOpened failed (non-fatal)', ['conv' => $conversationId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * A label is about to be removed (both removeLabel twins call this BEFORE the delete).
     * Closes the cycle silently — nobody is waiting on a tag that no longer exists.
     */
    public function onUntagged(int $conversationId, int $labelId): void
    {
        try {
            if (!$this->ready()) return;
            $pivotId = DB::table('t_wa_conversation_labels')
                ->where('conversation_id', $conversationId)
                ->where('label_id', $labelId)
                ->value('id');
            if (!$pivotId) return;
            $now = now();
            // Only cycles still waiting. One that already got an answer (acknowledged) or
            // already ran out (gave_up) keeps its record — removing the tag afterwards must
            // not erase a real delay from Daily Issues.
            $ids = DB::table(self::TABLE)
                ->where('conversation_label_id', $pivotId)
                ->whereIn('state', self::OPEN_STATES)
                ->pluck('id')->all();
            if (!$ids) return;
            DB::table(self::TABLE)->whereIn('id', $ids)->update([
                'state' => 'untagged', 'next_at' => null, 'closed_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($ids as $cid) {
                $this->clearTeamAlert((int) $cid);
            }
        } catch (\Throwable $e) {
            Log::warning('MentionFollowup onUntagged failed (non-fatal)', ['conv' => $conversationId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * A button: wait10 | called | stop (the tagger) · on_it (the tagged person).
     * Returns ['ok'=>bool, 'code'=>string, 'message'=>string, 'followup'=>?array].
     * A tap on a cycle that already closed is NOT an error (stale notification).
     */
    public function act(int $cycleId, int $userId, string $action): array
    {
        if (!$this->ready()) {
            return ['ok' => false, 'code' => 'unavailable', 'message' => 'Not available yet.', 'followup' => null];
        }
        $row = DB::table(self::TABLE)->where('id', $cycleId)->first();
        if (!$row) {
            return ['ok' => false, 'code' => 'not_found', 'message' => 'Not found.', 'followup' => null];
        }

        $taggerActions = ['wait10', 'called', 'stop'];
        if (in_array($action, $taggerActions, true)) {
            if ((int) $row->waiting_user_id !== $userId) {
                return ['ok' => false, 'code' => 'forbidden', 'message' => 'Only the person who tagged can do this.', 'followup' => null];
            }
        } elseif ($action === 'on_it') {
            if ((int) $row->on_user_id !== $userId) {
                return ['ok' => false, 'code' => 'forbidden', 'message' => 'Only the tagged person can do this.', 'followup' => null];
            }
        } else {
            return ['ok' => false, 'code' => 'bad_action', 'message' => 'Unknown action.', 'followup' => null];
        }

        $now = now();
        $openForAction = $action === 'on_it' ? self::OPEN_STATES : ['watching'];
        if (!in_array($row->state, $openForAction, true)) {
            return ['ok' => true, 'code' => 'already_closed', 'message' => $this->closedMessage($row), 'followup' => $this->stripFor($row, $userId)];
        }

        $giveUpAt = Carbon::parse($row->tagged_at)->addMinutes(self::GIVE_UP_MIN);
        $update = ['last_action' => $action, 'last_action_at' => $now, 'updated_at' => $now];

        if ($action === 'wait10' || $action === 'called') {
            $next = $now->copy()->addMinutes($action === 'wait10' ? self::WAIT_MIN : self::CALLED_MIN);
            $update['next_at'] = $next->greaterThan($giveUpAt) ? $giveUpAt : $next;
        } elseif ($action === 'stop') {
            // Stop = ALL buzzing ends for this tag: no more reminders to the tagger AND no
            // more re-buzzes to the tagged person (re-buzzes ride the same clock). Daily Issues
            // still sees the delay — muting is not a response.
            $update['state'] = 'muted';
            $update['next_at'] = null;
        } else { // on_it
            $update['state'] = 'acknowledged';
            $update['acknowledged_at'] = $now;
            $update['next_at'] = null;
        }

        $affected = DB::table(self::TABLE)->where('id', $cycleId)->where('state', $row->state)->update($update);
        if (!$affected) {
            $fresh = DB::table(self::TABLE)->where('id', $cycleId)->first();
            return ['ok' => true, 'code' => 'already_closed', 'message' => $this->closedMessage($fresh), 'followup' => $this->stripFor($fresh, $userId)];
        }

        if ($action === 'on_it') {
            $this->clearTeamAlert($cycleId);
            // Tell the tagger — unless they had already pressed Stop (they asked for quiet).
            if ($row->state === 'watching' && $this->claim("mfu:{$cycleId}:onit")) {
                $this->pushTagger($row, '👍 ' . $this->userName((int) $row->on_user_id) . ' is on it',
                    'Chat with ' . $this->chatName((int) $row->conversation_id), 'on_it');
            }
        }

        $fresh = DB::table(self::TABLE)->where('id', $cycleId)->first();
        $messages = [
            'wait10' => 'OK — reminding you again in ' . self::WAIT_MIN . ' min.',
            'called' => 'OK — checking again in ' . self::CALLED_MIN . ' min.',
            'stop'   => 'Reminders stopped for this chat.',
            'on_it'  => 'Told them you are on it.',
        ];
        return ['ok' => true, 'code' => 'done', 'message' => $messages[$action], 'followup' => $this->stripFor($fresh, $userId)];
    }

    // ───────────────────────────── the clock ─────────────────────────────

    /**
     * Piggyback heartbeat: run one tick after the response is flushed. Called from the
     * unread-count polls (web header + phone store header). Throttled by the same lock the
     * cron command uses; every push is claimed, so overlapping runs cannot double-send.
     */
    public function fireFromRequest(): void
    {
        if (!$this->ready() || !$this->enabledUserIds()) return;
        try {
            app()->terminating(function () {
                try {
                    $this->tickLocked();
                } catch (\Throwable $e) {
                    Log::warning('MentionFollowup piggyback tick failed', ['error' => $e->getMessage()]);
                }
            });
        } catch (\Throwable $e) {
            // some environments cannot register terminating callbacks — the cron still runs
        }
    }

    /**
     * One tick, at most one per LOCK_SECONDS across the cron AND every polling device. The key
     * is NOT released at the end on purpose — it is a throttle, not a mutex: dozens of phones
     * poll unread-count every 15 s, and each would otherwise start its own tick. (Reminders are
     * minutes apart, so ≤ 50 s of lateness is invisible.) Returns null when throttled.
     */
    public function tickLocked(): ?array
    {
        if (!Cache::add(self::LOCK_KEY, 1, self::LOCK_SECONDS)) {
            return null;
        }
        return $this->processDue();
    }

    /**
     * Process every cycle whose next_at has come. Returns counts for the command output.
     * Safe to call concurrently: each row is claimed before anything is sent.
     */
    public function processDue(int $limit = 100): array
    {
        $out = ['reminders' => 0, 'gave_up' => 0, 'opened' => 0, 'untagged' => 0, 'skipped' => 0, 'errors' => []];
        if (!$this->ready()) return $out;

        $now = now();
        $rows = DB::table(self::TABLE)
            ->where('state', 'watching')
            ->whereNotNull('next_at')
            ->where('next_at', '<=', $now)
            ->orderBy('next_at')
            ->limit($limit)
            ->get();

        foreach ($rows as $row) {
            try {
                $out[$this->processOne($row, $now)]++;
            } catch (\Throwable $e) {
                $out['errors'][] = "cycle {$row->id}: " . $e->getMessage();
                Log::warning('MentionFollowup cycle failed', ['id' => $row->id, 'error' => $e->getMessage()]);
            }
        }
        return $out;
    }

    /** @return string one of reminders|gave_up|opened|untagged|skipped */
    private function processOne(object $row, Carbon $now): string
    {
        // 1. The tag itself is the truth. Removed → close quietly. Opened by a path that did
        //    not call onOpened (or a race) → close as opened at the pivot's own stamp.
        $pivot = $row->conversation_label_id
            ? DB::table('t_wa_conversation_labels')->where('id', $row->conversation_label_id)->first(['id', 'mention_seen_at'])
            : null;
        if (!$pivot) {
            $n = DB::table(self::TABLE)->where('id', $row->id)->where('state', 'watching')
                ->update(['state' => 'untagged', 'next_at' => null, 'closed_at' => $now, 'updated_at' => $now]);
            if ($n) $this->clearTeamAlert((int) $row->id);
            return $n ? 'untagged' : 'skipped';
        }
        if (!empty($pivot->mention_seen_at)) {
            $seen = Carbon::parse($pivot->mention_seen_at);
            if ($seen->greaterThanOrEqualTo(Carbon::parse($row->tagged_at)->subSeconds(1))) {
                return $this->closeAsOpened($row, $seen) ? 'opened' : 'skipped';
            }
        }

        $taggedAt = Carbon::parse($row->tagged_at);
        $giveUpAt = $taggedAt->copy()->addMinutes(self::GIVE_UP_MIN);

        // 2. Two hours gone (also the catch-up case: a tick that arrives late goes straight
        //    here instead of firing the reminders it missed).
        if ($now->greaterThanOrEqualTo($giveUpAt) || (int) $row->remind_count >= self::MAX_REMINDERS) {
            if ($now->lessThan($giveUpAt)) {
                // All reminders sent but 120 not reached yet → just wait for it.
                DB::table(self::TABLE)->where('id', $row->id)->where('state', 'watching')
                    ->update(['next_at' => $giveUpAt, 'updated_at' => $now]);
                return 'skipped';
            }
            $n = DB::table(self::TABLE)->where('id', $row->id)->where('state', 'watching')
                ->update(['state' => 'gave_up', 'next_at' => null, 'closed_at' => $now, 'updated_at' => $now]);
            if ($n && $this->claim("mfu:{$row->id}:giveup")) {
                $this->pushTagger($row,
                    $this->userName((int) $row->on_user_id) . " hasn't opened it in 2 hours",
                    'Chat with ' . $this->chatName((int) $row->conversation_id) . ". No more reminders — it's in Daily Issues.",
                    'gave_up');
            }
            return $n ? 'gave_up' : 'skipped';
        }

        // 3. A reminder. ONE per tick, whatever was missed; the next one is 15 min from NOW.
        $nth  = (int) $row->remind_count + 1;
        $next = $now->copy()->addMinutes(self::EVERY_MIN);
        if ($next->greaterThan($giveUpAt)) $next = $giveUpAt;

        $claimed = DB::table(self::TABLE)
            ->where('id', $row->id)
            ->where('state', 'watching')
            ->where('remind_count', (int) $row->remind_count)
            ->where('next_at', $row->next_at)
            ->update(['remind_count' => $nth, 'next_at' => $next, 'last_reminded_at' => $now, 'updated_at' => $now]);
        if (!$claimed || !$this->claim("mfu:{$row->id}:r{$nth}")) {
            return 'skipped';
        }

        $minutes = max(1, (int) floor($taggedAt->diffInSeconds($now) / 60));
        $onName  = $this->userName((int) $row->on_user_id);
        $chat    = $this->chatName((int) $row->conversation_id);
        $this->pushTagger($row,
            "⏳ {$onName} hasn't opened your tag · {$minutes} min",
            "Chat with {$chat} · reminder {$nth} of " . self::MAX_REMINDERS . '. Open the chat to wait or stop.',
            'reminder', ['minutes' => (string) $minutes, 'reminder' => (string) $nth]);
        $this->sendTeamAlert((int) $row->id, 'rebuzz', $minutes);
        return 'reminders';
    }

    /** Close one cycle as opened at $at. Returns true if THIS call closed it. */
    private function closeAsOpened(object $row, Carbon $at): bool
    {
        $now = now();
        $newState = $row->state === 'acknowledged' ? 'acknowledged' : 'opened';
        $n = DB::table(self::TABLE)
            ->where('id', $row->id)
            ->whereIn('state', ['watching', 'muted', 'acknowledged', 'gave_up'])
            ->whereNull('opened_at')
            ->update([
                'state' => $newState, 'opened_at' => $at, 'next_at' => null,
                'closed_at' => $row->closed_at ?? $now, 'updated_at' => $now,
            ]);
        if (!$n) return false;

        $this->clearTeamAlert((int) $row->id);

        // "✓ opened" goes back ONLY when the tagger had actually been chased (a reminder
        // fired) and had not asked for quiet. Opened inside 10 min = the tagger hears nothing.
        if ($row->state === 'watching' && (int) $row->remind_count > 0 && $this->claim("mfu:{$row->id}:opened")) {
            $mins = max(1, (int) floor(Carbon::parse($row->tagged_at)->diffInSeconds($at) / 60));
            $this->pushTagger($row,
                '✓ ' . $this->userName((int) $row->on_user_id) . ' opened it',
                'Chat with ' . $this->chatName((int) $row->conversation_id) . " · after {$mins} min",
                'opened');
        }
        return true;
    }

    // ───────────────────────────── reads ─────────────────────────────

    /**
     * The strip the TAGGER sees on the chat they tagged (web now, phone in round 2).
     * NULL for everyone else, and once the cycle has been closed for a while.
     */
    public function stripForViewer(int $conversationId, int $viewerId): ?array
    {
        try {
            if (!$this->ready() || $viewerId <= 0) return null;
            $row = DB::table(self::TABLE)
                ->where('conversation_id', $conversationId)
                ->where('waiting_user_id', $viewerId)
                ->orderByDesc('id')
                ->first();
            return $row ? $this->stripFor($row, $viewerId) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function stripFor(?object $row, int $viewerId): ?array
    {
        if (!$row || (int) $row->waiting_user_id !== $viewerId || $row->state === 'untagged') return null;
        $now = now();
        $tagged = Carbon::parse($row->tagged_at);
        $closedAt = $row->opened_at ?? $row->acknowledged_at ?? $row->closed_at;
        // Closed long ago → nothing to show. Exception: gave up and STILL not opened keeps
        // saying so — the tagger coming back to the chat should see it is still waiting.
        $stillUnopened = $row->state === 'gave_up' && empty($row->opened_at);
        if (!in_array($row->state, self::OPEN_STATES, true) && !$stillUnopened && $closedAt
            && Carbon::parse($closedAt)->diffInMinutes($now) > self::RECENT_CLOSED_MIN) {
            return null;
        }
        $response = $row->opened_at ?? $row->acknowledged_at;
        return [
            'id'            => (int) $row->id,
            'state'         => $row->state,
            'on_user'       => $this->userName((int) $row->on_user_id),
            'minutes'       => (int) floor($tagged->diffInSeconds($response ? Carbon::parse($response) : $now) / 60),
            'tagged_at'     => $tagged->format('H:i'),
            'remind_count'  => (int) $row->remind_count,
            'max_reminders' => self::MAX_REMINDERS,
            'next_at'       => $row->next_at ? Carbon::parse($row->next_at)->format('H:i') : null,
            'opened_at'     => $row->opened_at ? Carbon::parse($row->opened_at)->format('H:i') : null,
            'acknowledged_at' => $row->acknowledged_at ? Carbon::parse($row->acknowledged_at)->format('H:i') : null,
            'last_action'   => $row->last_action,
            'can_act'       => $row->state === 'watching',
        ];
    }

    /**
     * Daily Issues: per tagged person, how many tags they got on $date and which ones took
     * longer than FLAG_MINUTES to answer (answered = opened the chat or On it, whichever came
     * first). Answered late is STILL flagged. A tag still under the limit is not shown yet.
     * Un-tagged cycles are not counted. Only people with at least one flagged tag are returned.
     */
    public function dailyIssues(string $date): array
    {
        try {
            if (!$this->ready()) return [];
            $rows = DB::table(self::TABLE)
                ->whereBetween('tagged_at', [$date . ' 00:00:00', $date . ' 23:59:59'])
                ->where('state', '!=', 'untagged')
                ->orderBy('tagged_at')
                ->get();
            if ($rows->isEmpty()) return [];

            $names = DB::table('t_sys_user')
                ->whereIn('id', $rows->pluck('on_user_id')->merge($rows->pluck('waiting_user_id'))->unique()->all())
                ->pluck('fullname', 'id');
            $now = now();
            $byUser = [];
            foreach ($rows as $r) {
                $uid = (int) $r->on_user_id;
                $byUser[$uid] ??= ['on_user_id' => $uid, 'on_user' => $names[$uid] ?? 'User', 'tagged_count' => 0, 'flagged_count' => 0, 'flagged' => []];
                $byUser[$uid]['tagged_count']++;

                $tagged = Carbon::parse($r->tagged_at);
                [$resp, $how] = $this->firstResponse($r);
                // Carbon 3 diffs are SIGNED — clamp so a clock wobble can never go negative.
                $waited = max(0, (int) floor($tagged->diffInSeconds($resp ?? $now) / 60));
                if ($waited <= self::FLAG_MINUTES) continue; // answered in time, or not late yet

                $byUser[$uid]['flagged_count']++;
                $byUser[$uid]['flagged'][] = [
                    'cycle_id'        => (int) $r->id,
                    'conversation_id' => (int) $r->conversation_id,
                    'chat'            => $this->chatName((int) $r->conversation_id),
                    'tagger'          => $names[(int) $r->waiting_user_id] ?? 'Someone',
                    'tagged_at'       => $tagged->format('H:i'),
                    'response'        => $resp
                        ? $how . ' ' . $resp->format($resp->isSameDay($tagged) ? 'H:i' : 'H:i, d M')
                        : 'not opened',
                    'waited_min'      => $waited,
                    'answered'        => $resp !== null,
                    'reminders'       => (int) $r->remind_count,
                    'tagger_action'   => $r->last_action && in_array($r->last_action, ['called', 'stop', 'wait10'], true)
                        ? ['called' => 'said they would call', 'stop' => 'stopped reminders', 'wait10' => 'chose to wait'][$r->last_action]
                            . ($r->last_action_at ? ' ' . Carbon::parse($r->last_action_at)->format('H:i') : '')
                        : null,
                ];
            }
            return array_values(array_filter($byUser, fn ($u) => $u['flagged_count'] > 0));
        } catch (\Throwable $e) {
            Log::warning('MentionFollowup dailyIssues failed (non-fatal)', ['date' => $date, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * opened_at or acknowledged_at, whichever came first.
     * @return array{0: ?Carbon, 1: ?string} [when, 'opened'|'on it']
     */
    private function firstResponse(object $r): array
    {
        $a = $r->opened_at ? Carbon::parse($r->opened_at) : null;
        $b = $r->acknowledged_at ? Carbon::parse($r->acknowledged_at) : null;
        if ($a && (!$b || $a->lessThanOrEqualTo($b))) return [$a, 'opened'];
        if ($b) return [$b, 'on it'];
        return [null, null];
    }

    // ───────────────────────────── pushes ─────────────────────────────

    /** Loud alert / re-buzz to the tagged person. */
    private function sendTeamAlert(int $cycleId, string $kind, int $minutes = 0): void
    {
        try {
            $row = DB::table(self::TABLE)->where('id', $cycleId)->first();
            if (!$row) return;
            $tagger = $this->userName((int) $row->waiting_user_id);
            $chat   = $this->chatName((int) $row->conversation_id);
            if ($kind === 'first') {
                $title = "🔴 {$tagger} tagged you";
                $body  = "Chat with {$chat} — please open it.";
            } else {
                $title = "🔴 {$tagger} is still waiting · {$minutes} min";
                $body  = "Chat with {$chat} — tagged at " . Carbon::parse($row->tagged_at)->format('H:i') . '.';
            }
            app(FirebaseService::class)->notifyTeamAlert((int) $row->on_user_id, $title, $body, [
                'cycle_id'        => (string) $cycleId,
                'conversation_id' => (string) $row->conversation_id,
                'mentioned_by'    => $tagger,
                'kind'            => $kind,
            ]);
        } catch (\Throwable $e) {
            Log::warning('MentionFollowup team alert failed (non-fatal)', ['id' => $cycleId, 'error' => $e->getMessage()]);
        }
    }

    /** Remove the tagged person's sticky alert (only phones that draw one; a no-op today). */
    private function clearTeamAlert(int $cycleId): void
    {
        try {
            $onUser = (int) DB::table(self::TABLE)->where('id', $cycleId)->value('on_user_id');
            if ($onUser) app(FirebaseService::class)->clearTeamAlert($onUser, $cycleId);
        } catch (\Throwable $e) {
            // best-effort; the phone also clears it on open and after 2 h on its own
        }
    }

    private function pushTagger(object $row, string $title, string $body, string $kind, array $extra = []): void
    {
        try {
            app(FirebaseService::class)->notifyFollowupToTagger((int) $row->waiting_user_id, $title, $body, array_merge([
                'cycle_id'        => (string) $row->id,
                'conversation_id' => (string) $row->conversation_id,
                'kind'            => $kind,
                'on_user'         => $this->userName((int) $row->on_user_id),
            ], $extra));
        } catch (\Throwable $e) {
            Log::warning('MentionFollowup tagger push failed (non-fatal)', ['id' => $row->id, 'error' => $e->getMessage()]);
        }
    }

    /** Stamp a push key; true only for the FIRST caller (unique alert_key). */
    private function claim(string $key): bool
    {
        try {
            return DB::table('t_ops_service_alert_push')->insertOrIgnore(['alert_key' => $key, 'pushed_at' => now()]) === 1;
        } catch (\Throwable $e) {
            // Dedupe table unavailable → the row-level claim already held; allow the send.
            return true;
        }
    }

    // ───────────────────────────── small helpers ─────────────────────────────

    private array $nameMemo = [];
    private function userName(int $id): string
    {
        return $this->nameMemo["u{$id}"] ??= (string) (DB::table('t_sys_user')->where('id', $id)->value('fullname') ?: ('User #' . $id));
    }

    private function chatName(int $conversationId): string
    {
        if (!isset($this->nameMemo["c{$conversationId}"])) {
            // display_name is an ACCESSOR (customer name → WA contact name → phone), not a
            // column — same name the applyLabel push has always used.
            $c = \App\Models\WhatsApp\ConversationModel::with('customer:id,first_name,last_name')->find($conversationId);
            $this->nameMemo["c{$conversationId}"] = $c ? ((string) $c->display_name ?: 'a customer') : 'a customer';
        }
        return $this->nameMemo["c{$conversationId}"];
    }

    private function closedMessage(?object $row): string
    {
        return match ($row->state ?? '') {
            'opened'       => 'Already opened — nothing to do.',
            'acknowledged' => 'They already said they are on it.',
            'muted'        => 'Reminders were already stopped.',
            'gave_up'      => 'Reminders already ended after 2 hours.',
            'untagged'     => 'The tag was removed.',
            default        => 'Already closed.',
        };
    }
}
